<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/_admin_auth.php';
cors_headers();
require_admin();

$method = $_SERVER['REQUEST_METHOD'];
$pdo = db();

// 🔄 v2.9.175 — DDL přesunut do _schema_lib.php (sdílený helper, idempotentní).
require_once __DIR__ . '/_schema_lib.php';
ensure_objednavky_polozky_schema($pdo);
ensure_objednavky_schema($pdo);

function prepocitat_objednavku(PDO $pdo, int $obj_id): void {
    $stmt = $pdo->prepare("
        SELECT
            COALESCE(SUM(cena_bez_dph * mnozstvi), 0) AS bez_dph,
            COALESCE(SUM(cena_bez_dph * mnozstvi * sazba_dph / 100), 0) AS dph
        FROM objednavky_polozky WHERE objednavka_id = :id
    ");
    $stmt->execute(['id' => $obj_id]);
    $sum = $stmt->fetch();
    $bez = (float) $sum['bez_dph'];
    $dph = (float) $sum['dph'];
    $pdo->prepare("
        UPDATE objednavky
        SET castka_bez_dph = :b, castka_dph = :d, castka_celkem = :c
        WHERE id = :id
    ")->execute([
        'b' => round($bez, 2), 'd' => round($dph, 2),
        'c' => round($bez + $dph, 2), 'id' => $obj_id,
    ]);
}

/**
 * Vrátí true, pokud objednávka má vystavený dodací list -
 * tj. nesmí se už editovat (jinak by se rozsynchronizovala faktura).
 */
function objednavka_zamcena(PDO $pdo, int $obj_id): bool {
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM dodaci_listy WHERE objednavka_id = :id");
    $stmt->execute(['id' => $obj_id]);
    return ((int) $stmt->fetchColumn()) > 0;
}

/**
 * Po úpravě objednávky znovu vyplní položky existujícího dodacího listu
 * podle aktuálního stavu objednávky. Předpoklad: jeden DL na jednu objednávku.
 */
function synchronizovat_dl_z_objednavky(PDO $pdo, int $obj_id): void {
    // Najdi DL pro tuto objednávku (vezmi všechny - obvykle je jeden)
    $stmt = $pdo->prepare("SELECT id FROM dodaci_listy WHERE objednavka_id = :id");
    $stmt->execute(['id' => $obj_id]);
    $dl_ids = array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
    if (empty($dl_ids)) return;

    // Načti aktuální položky objednávky (LEFT JOIN — volné řádky bez vyrobek_id)
    $stmt = $pdo->prepare("
        SELECT op.vyrobek_id, op.mnozstvi, op.cena_bez_dph, op.sazba_dph, op.poznamka,
               v.cislo AS vyrobek_cislo,
               COALESCE(v.nazev, op.vyrobek_nazev) AS vyrobek_nazev,
               COALESCE(j.kod, op.jednotka) AS jednotka
        FROM objednavky_polozky op
        LEFT JOIN vyrobky v ON v.id = op.vyrobek_id
        LEFT JOIN jednotky j ON j.id = v.jednotka_id
        WHERE op.objednavka_id = :id
    ");
    $stmt->execute(['id' => $obj_id]);
    $polozky = $stmt->fetchAll();

    // Spočítej celkem (bez DPH * koef + dph)
    $celkem = 0;
    foreach ($polozky as $p) {
        $bez = (float) $p['cena_bez_dph'] * (float) $p['mnozstvi'];
        $celkem += $bez * (1 + (float) $p['sazba_dph'] / 100);
    }
    $celkem = round($celkem, 2);

    foreach ($dl_ids as $dl_id) {
        // Smaž staré položky
        $pdo->prepare("DELETE FROM dodaci_list_polozky WHERE dodaci_list_id = :id")
            ->execute(['id' => $dl_id]);

        // Vlož nové
        $stmt = $pdo->prepare("
            INSERT INTO dodaci_list_polozky
                (dodaci_list_id, vyrobek_id, vyrobek_cislo, vyrobek_nazev, jednotka, mnozstvi, cena_bez_dph, sazba_dph, poznamka)
            VALUES (:dl, :vid, :cisl, :naz, :jed, :mn, :cen, :sa, :poz)
        ");
        foreach ($polozky as $p) {
            $stmt->execute([
                'dl'   => $dl_id,
                'vid'  => $p['vyrobek_id'],
                'cisl' => $p['vyrobek_cislo'],
                'naz'  => $p['vyrobek_nazev'],
                'jed'  => $p['jednotka'],
                'mn'   => $p['mnozstvi'],
                'cen'  => $p['cena_bez_dph'],
                'sa'   => $p['sazba_dph'],
                'poz'  => $p['poznamka'],
            ]);
        }

        // Aktualizuj součet na DL
        $pdo->prepare("UPDATE dodaci_listy SET castka_celkem = :c WHERE id = :id")
            ->execute(['c' => $celkem, 'id' => $dl_id]);
    }
}

/**
 * Po úpravě objednávky znovu vyplní fakturu, která je s ní svázaná
 * přes faktury_dodaci_listy → dodaci_listy → objednavky.
 *
 * Pozor: pokud je faktura "ruční" (rucni=1) nebo pokrývá více DL,
 * tato funkce stejně přepočítá VŠECHNY její položky podle aktuálních DL.
 */
function synchronizovat_fakturu_z_objednavky(PDO $pdo, int $obj_id): void {
    // Najdi faktury, jejichž alespoň jeden DL patří této objednávce
    $stmt = $pdo->prepare("
        SELECT DISTINCT f.id
        FROM faktury f
        JOIN faktury_dodaci_listy fdl ON fdl.faktura_id = f.id
        JOIN dodaci_listy dl ON dl.id = fdl.dodaci_list_id
        WHERE dl.objednavka_id = :oid
    ");
    $stmt->execute(['oid' => $obj_id]);
    $faktura_ids = array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
    if (empty($faktura_ids)) return;

    foreach ($faktura_ids as $fa_id) {
        // Smaž staré položky této faktury
        $pdo->prepare("DELETE FROM faktura_polozky WHERE faktura_id = :id")
            ->execute(['id' => $fa_id]);

        // Načti všechny DL položky této faktury
        $stmt = $pdo->prepare("
            SELECT dlp.*
            FROM dodaci_list_polozky dlp
            JOIN faktury_dodaci_listy fdl ON fdl.dodaci_list_id = dlp.dodaci_list_id
            WHERE fdl.faktura_id = :fa
        ");
        $stmt->execute(['fa' => $fa_id]);
        $polozky = $stmt->fetchAll();

        // Vlož je do faktura_polozky
        $stmt = $pdo->prepare("
            INSERT INTO faktura_polozky
                (faktura_id, vyrobek_id, vyrobek_cislo, vyrobek_nazev, jednotka,
                 mnozstvi, cena_bez_dph, sazba_dph, poznamka, poradi)
            VALUES (:fa, :vid, :cisl, :naz, :jed, :mn, :cen, :sa, :poz, :por)
        ");
        $i = 0;
        $sum_bez = 0; $sum_dph = 0;
        foreach ($polozky as $p) {
            $stmt->execute([
                'fa'   => $fa_id,
                'vid'  => $p['vyrobek_id'],
                'cisl' => $p['vyrobek_cislo'],
                'naz'  => $p['vyrobek_nazev'],
                'jed'  => $p['jednotka'],
                'mn'   => $p['mnozstvi'],
                'cen'  => $p['cena_bez_dph'],
                'sa'   => $p['sazba_dph'],
                'poz'  => $p['poznamka'],
                'por'  => $i++,
            ]);
            $bez = (float) $p['cena_bez_dph'] * (float) $p['mnozstvi'];
            $sum_bez += $bez;
            $sum_dph += $bez * (float) $p['sazba_dph'] / 100;
        }
        $sum_bez = round($sum_bez, 2);
        $sum_dph = round($sum_dph, 2);
        $sum_cel = round($sum_bez + $sum_dph, 2);

        // Aktualizuj součty faktury
        $pdo->prepare("
            UPDATE faktury SET castka_bez_dph = :b, castka_dph = :d, castka_celkem = :c
            WHERE id = :id
        ")->execute(['b' => $sum_bez, 'd' => $sum_dph, 'c' => $sum_cel, 'id' => $fa_id]);
    }
}

if ($method === 'GET') {
    // Posledních N objednávek odběratele (pro funkci "Načíst z dřívější objednávky")
    if (($_GET['action'] ?? '') === 'posledni') {
        $odb_id = (int) ($_GET['odberatel_id'] ?? 0);
        if (!$odb_id) json_error('Chybí ID odběratele');
        $limit = max(1, min(20, (int) ($_GET['limit'] ?? 5)));

        $stmt = $pdo->prepare("
            SELECT o.id, o.cislo, o.datum_dodani, o.datum_objednani, o.stav,
                   o.castka_celkem, o.castka_bez_dph,
                   md.nazev AS misto_nazev,
                   (SELECT COUNT(*) FROM objednavky_polozky op WHERE op.objednavka_id = o.id) AS pocet_polozek,
                   (SELECT SUM(op.mnozstvi) FROM objednavky_polozky op WHERE op.objednavka_id = o.id) AS celkem_ks
            FROM objednavky o
            LEFT JOIN mista_dodani md ON md.id = o.misto_dodani_id
            WHERE o.odberatel_id = :odb
            ORDER BY o.datum_objednani DESC
            LIMIT $limit
        ");
        $stmt->execute(['odb' => $odb_id]);
        $obj = $stmt->fetchAll();

        // Pro každou objednávku načti i položky (LEFT JOIN — volné řádky)
        $stmt_pol = $pdo->prepare("
            SELECT op.vyrobek_id, op.mnozstvi, op.cena_bez_dph, op.sazba_dph, op.poznamka,
                   v.cislo AS vyrobek_cislo,
                   COALESCE(v.nazev, op.vyrobek_nazev) AS vyrobek_nazev,
                   COALESCE(j.kod, op.jednotka) AS jednotka
            FROM objednavky_polozky op
            LEFT JOIN vyrobky v ON v.id = op.vyrobek_id
            LEFT JOIN jednotky j ON j.id = v.jednotka_id
            WHERE op.objednavka_id = :id
            ORDER BY vyrobek_nazev
        ");
        foreach ($obj as &$o) {
            $stmt_pol->execute(['id' => $o['id']]);
            $o['polozky'] = $stmt_pol->fetchAll();
        }
        json_response($obj);
    }

    if (isset($_GET['id'])) {
        $stmt = $pdo->prepare("
            SELECT o.*, od.nazev AS odberatel_nazev, od.ico, od.email AS odberatel_email,
                   od.telefon, od.ulice AS odb_ulice, od.mesto AS odb_mesto, od.psc AS odb_psc,
                   md.nazev AS misto_nazev, md.ulice AS misto_ulice, md.mesto AS misto_mesto,
                   md.psc AS misto_psc, md.kontaktni_osoba AS misto_kontakt, md.telefon AS misto_tel,
                   md.cas_dodani, md.pokyny_pro_ridice,
                   (SELECT COUNT(*) FROM dodaci_listy dl WHERE dl.objednavka_id = o.id) AS pocet_dl
            FROM objednavky o
            JOIN odberatele od ON od.id = o.odberatel_id
            LEFT JOIN mista_dodani md ON md.id = o.misto_dodani_id
            WHERE o.id = :id
        ");
        $stmt->execute(['id' => (int) $_GET['id']]);
        $obj = $stmt->fetch();
        if (!$obj) json_error('Objednávka nenalezena', 404);

        // 🐛 fix v2.9.185 — INNER JOIN vyrobky vyhazovala položky s vyrobek_id NULL
        // (volné řádky bez katalogu) i položky kde výrobek byl smazán (FK ON DELETE
        // SET NULL od v2.9.175). Demo data měla 25 ze 47 OBJ "prázdných" (kc>0, ale
        // detail vrátil 0 položek). LEFT JOIN + COALESCE se snapshot sloupci to fixne.
        $polozky = $pdo->prepare("
            SELECT p.*,
                   COALESCE(NULLIF(p.vyrobek_nazev, ''), v.nazev) AS vyrobek_nazev,
                   v.cislo AS vyrobek_cislo,
                   v.obrazek_url,
                   COALESCE(NULLIF(p.jednotka, ''), j.kod, 'ks') AS jednotka
            FROM objednavky_polozky p
            LEFT JOIN vyrobky v ON v.id = p.vyrobek_id
            LEFT JOIN jednotky j ON j.id = v.jednotka_id
            WHERE p.objednavka_id = :id
        ");
        $polozky->execute(['id' => $obj['id']]);
        $obj['polozky'] = $polozky->fetchAll();
        $obj['zamcena'] = $obj['pocet_dl'] > 0;

        // Seznam dodacích listů a faktur svázaných s objednávkou
        try {
            $stmt = $pdo->prepare("
                SELECT id, cislo, datum_vystaveni, castka_celkem
                FROM dodaci_listy
                WHERE objednavka_id = :id
                ORDER BY datum_vystaveni DESC
            ");
            $stmt->execute(['id' => $obj['id']]);
            $obj['dodaci_listy'] = $stmt->fetchAll();
        } catch (Exception $e) {
            $obj['dodaci_listy'] = [];
        }

        try {
            $stmt = $pdo->prepare("
                SELECT DISTINCT f.id, f.cislo, f.datum_vystaveni, f.castka_celkem,
                       f.castka_uhrazeno, f.datum_splatnosti
                FROM faktury f
                JOIN faktury_dodaci_listy fdl ON fdl.faktura_id = f.id
                JOIN dodaci_listy dl ON dl.id = fdl.dodaci_list_id
                WHERE dl.objednavka_id = :id
                ORDER BY f.datum_vystaveni DESC
            ");
            $stmt->execute(['id' => $obj['id']]);
            $obj['faktury'] = $stmt->fetchAll();
        } catch (Exception $e) {
            $obj['faktury'] = [];
        }

        // Historie změn (může chybět, pokud migrace 04 nedoběhla)
        try {
            $stmt = $pdo->prepare("
                SELECT kdy, kdo_typ, kdo_jmeno, akce, detail
                FROM objednavky_zmeny
                WHERE objednavka_id = :id
                ORDER BY kdy DESC
            ");
            $stmt->execute(['id' => $obj['id']]);
            $obj['historie_zmen'] = $stmt->fetchAll();
        } catch (Exception $e) {
            $obj['historie_zmen'] = [];
        }

        json_response($obj);
    }

    $stav     = $_GET['stav'] ?? '';
    $datum_od = $_GET['datum_od'] ?? '';
    $datum_do = $_GET['datum_do'] ?? '';
    $hledat   = trim($_GET['q'] ?? '');
    $puvod    = $_GET['puvod'] ?? ''; // 🆕 v3.0.27 — filter podle zdroje (pos/b2b/interni/qr/recurring)

    $sql = "
        SELECT o.id, o.cislo, o.typ, o.stav, o.datum_objednani, o.datum_dodani,
               o.castka_celkem, o.poznamka, o.puvod,
               od.nazev AS odberatel_nazev,
               md.nazev AS misto_nazev,
               COUNT(DISTINCT p.id) AS pocet_polozek,
               COUNT(DISTINCT dl.id) AS pocet_dl,
               COUNT(DISTINCT fdl.faktura_id) AS pocet_faktur,
               MIN(fdl.faktura_id) AS prvni_faktura_id,
               (SELECT COUNT(*) FROM objednavky_zmeny oz
                 WHERE oz.objednavka_id = o.id AND oz.akce = 'upravena') AS pocet_zmen
        FROM objednavky o
        JOIN odberatele od ON od.id = o.odberatel_id
        LEFT JOIN mista_dodani md ON md.id = o.misto_dodani_id
        LEFT JOIN objednavky_polozky p ON p.objednavka_id = o.id
        LEFT JOIN dodaci_listy dl ON dl.objednavka_id = o.id
        LEFT JOIN faktury_dodaci_listy fdl ON fdl.dodaci_list_id = dl.id
        WHERE 1=1
    ";
    $params = [];
    if ($stav !== '') { $sql .= " AND o.stav = :stav"; $params['stav'] = $stav; }
    if ($datum_od !== '') { $sql .= " AND o.datum_dodani >= :do_"; $params['do_'] = $datum_od; }
    if ($datum_do !== '') { $sql .= " AND o.datum_dodani <= :ddo"; $params['ddo'] = $datum_do; }
    if ($hledat !== '') {
        $hl = str_replace(['\\','%','_'], ['\\\\','\\%','\\_'], $hledat);
        $sql .= " AND (o.cislo LIKE :q OR od.nazev LIKE :q)";
        $params['q'] = '%' . $hl . '%';
    }
    if ($puvod !== '') {
        $allowedPuvod = ['pos', 'b2b', 'interni', 'qr', 'recurring'];
        if (in_array($puvod, $allowedPuvod, true)) {
            $sql .= " AND o.puvod = :puvod";
            $params['puvod'] = $puvod;
        }
    }
    $sql .= " GROUP BY o.id ORDER BY o.datum_dodani DESC, o.datum_objednani DESC LIMIT 200";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    json_response($stmt->fetchAll());
}

if ($method === 'POST') {
    $d = json_input();
    $action = $d['action'] ?? '';

    if ($action === 'pridat_polozku') {
        $obj_id     = (int) ($d['objednavka_id'] ?? 0);
        $vyrobek_id = (int) ($d['vyrobek_id'] ?? 0);
        $mnozstvi   = (float) ($d['mnozstvi'] ?? 0);
        if (!$obj_id || !$vyrobek_id || $mnozstvi <= 0) json_error('Neplatné údaje');

        if (objednavka_zamcena($pdo, $obj_id)) {
            json_error('Objednávka je uzamčena (existuje dodací list)', 409);
        }

        $stmt = $pdo->prepare("
            SELECT v.cena_bez_dph, s.sazba
            FROM vyrobky v JOIN sazby_dph s ON s.id = v.sazba_dph_id
            WHERE v.id = :id AND v.aktivni = 1
        ");
        $stmt->execute(['id' => $vyrobek_id]);
        $vyr = $stmt->fetch();
        if (!$vyr) json_error('Výrobek neexistuje nebo není aktivní');

        $pdo->beginTransaction();
        try {
            $pdo->prepare("
                INSERT INTO objednavky_polozky
                    (objednavka_id, vyrobek_id, mnozstvi, cena_bez_dph, sazba_dph)
                VALUES (:o,:v,:m,:c,:s)
            ")->execute([
                'o' => $obj_id, 'v' => $vyrobek_id, 'm' => $mnozstvi,
                'c' => $vyr['cena_bez_dph'], 's' => $vyr['sazba'],
            ]);
            prepocitat_objednavku($pdo, $obj_id);
            $pdo->commit();
            json_response(['ok' => true]);
        } catch (Exception $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            error_log('admin_objednavky pridat_polozku: ' . $e->getMessage());
            json_error('Nepodařilo se přidat položku', 500);
        }
        exit;
    }

    if ($action === 'vytvorit') {
        // Ruční (telefonická) objednávka z adminu
        if (empty($d['odberatel_id']) || empty($d['datum_dodani']) || empty($d['polozky'])) {
            json_error('Chybí povinné údaje');
        }
        $odb_id = (int) $d['odberatel_id'];

        // 🆕 v3.0.153 BUG C — ověř existenci odběratele (jinak FK violation → leak raw SQLSTATE klientovi)
        $odbEx = $pdo->prepare("SELECT 1 FROM odberatele WHERE id = :id LIMIT 1");
        $odbEx->execute(['id' => $odb_id]);
        if (!$odbEx->fetchColumn()) json_error('Odběratel neexistuje', 400);

        // Ověř, že místo (pokud zadáno) patří odběrateli
        $misto_id = !empty($d['misto_dodani_id']) ? (int) $d['misto_dodani_id'] : null;
        if (!misto_patri_odberateli($pdo, $misto_id, $odb_id)) {
            json_error('Místo dodání nepatří k vybranému odběrateli', 400);
        }

        // 🆕 v3.0.144 — retry-on-deadlock: souběh více kanálů/adminů → 40001 (deadlock) /
        //   1205 (lock wait timeout). Zátěž odhalila 1× deadlock. Opakuj celou transakci.
        $obj_id = 0; $cislo = '';
        for ($attempt = 1; ; $attempt++) {
        $pdo->beginTransaction();
        try {
            $cislo = dalsi_cislo($pdo, 'OBJ', (int) date('Y'));

            // Načti ceník jen pro řádky s vyrobek_id
            $vyrobek_ids = array_values(array_unique(array_filter(array_map('intval',
                                                  array_column($d['polozky'], 'vyrobek_id')))));
            $cenik = [];
            if (!empty($vyrobek_ids)) {
                $placeholders = implode(',', array_fill(0, count($vyrobek_ids), '?'));
                $stmt = $pdo->prepare("
                    SELECT v.id, v.cena_bez_dph, s.sazba
                    FROM vyrobky v JOIN sazby_dph s ON s.id = v.sazba_dph_id
                    WHERE v.aktivni = 1 AND v.id IN ($placeholders)
                ");
                $stmt->execute($vyrobek_ids);
                foreach ($stmt->fetchAll() as $row) $cenik[$row['id']] = $row;
            }

            $bez = 0; $dph = 0;
            foreach ($d['polozky'] as $i => $p) {
                $vid = !empty($p['vyrobek_id']) ? (int) $p['vyrobek_id'] : null;
                $mn  = (float) ($p['mnozstvi'] ?? 0);
                if ($mn <= 0) throw new Exception("Řádek " . ($i + 1) . ": neplatné množství");

                if ($vid) {
                    $c = $cenik[$vid] ?? null;
                    if (!$c) throw new Exception("Řádek " . ($i + 1) . ": výrobek není dostupný");
                    $bez += $c['cena_bez_dph'] * $mn;
                    $dph += $c['cena_bez_dph'] * $mn * $c['sazba'] / 100;
                } else {
                    // Volný řádek
                    $nazev = trim((string) ($p['vyrobek_nazev'] ?? ''));
                    if ($nazev === '') throw new Exception("Řádek " . ($i + 1) . ": chybí název položky");
                    $cena = (float) ($p['cena_bez_dph'] ?? 0);
                    $sazba = (float) ($p['sazba_dph'] ?? 12);
                    if ($cena < 0) throw new Exception("Řádek " . ($i + 1) . ": záporná cena");
                    $bez += $cena * $mn;
                    $dph += $cena * $mn * $sazba / 100;
                }
            }

            $pdo->prepare("
                INSERT INTO objednavky (cislo, odberatel_id, misto_dodani_id, typ, stav, datum_objednani, datum_dodani,
                                        castka_bez_dph, castka_dph, castka_celkem, poznamka, interni_pozn)
                VALUES (:c,:o,:m,'jednorazova','potvrzena',CURDATE(),:d,:b,:dph,:cel,:p,:ip)
            ")->execute([
                'c' => $cislo, 'o' => $odb_id, 'm' => $misto_id,
                'd' => $d['datum_dodani'],
                'b' => round($bez, 2), 'dph' => round($dph, 2),
                'cel' => round($bez + $dph, 2),
                'p' => $d['poznamka'] ?? null,
                'ip' => $d['interni_pozn'] ?? null,
            ]);
            $obj_id = (int) $pdo->lastInsertId();

            $stmt = $pdo->prepare("
                INSERT INTO objednavky_polozky
                    (objednavka_id, vyrobek_id, vyrobek_nazev, jednotka, mnozstvi, cena_bez_dph, sazba_dph)
                VALUES (:o, :v, :n, :j, :m, :c, :s)
            ");
            foreach ($d['polozky'] as $p) {
                $vid = !empty($p['vyrobek_id']) ? (int) $p['vyrobek_id'] : null;
                if ($vid) {
                    $c = $cenik[$vid];
                    $stmt->execute([
                        'o' => $obj_id, 'v' => $vid,
                        'n' => null, 'j' => null,
                        'm' => (float) $p['mnozstvi'],
                        'c' => $c['cena_bez_dph'], 's' => $c['sazba'],
                    ]);
                } else {
                    $stmt->execute([
                        'o' => $obj_id, 'v' => null,
                        'n' => trim((string) ($p['vyrobek_nazev'] ?? '')),
                        'j' => $p['jednotka'] ?? 'ks',
                        'm' => (float) $p['mnozstvi'],
                        'c' => (float) ($p['cena_bez_dph'] ?? 0),
                        's' => (float) ($p['sazba_dph'] ?? 12),
                    ]);
                }
            }

            $pdo->commit();
            break; // úspěch — ven z retry smyčky
        } catch (Exception $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            $dl = ($e->getCode() === '40001')
                || (isset($e->errorInfo[1]) && in_array((int) $e->errorInfo[1], [1213, 1205], true));
            if ($dl && $attempt < 4) { usleep(mt_rand(8000, 40000) * $attempt); continue; }
            error_log('admin_objednavky vytvorit: ' . $e->getMessage());
            json_error($e->getMessage(), 400);
        }
        } // konec retry-on-deadlock smyčky

        // Notifikace + odpověď až po úspěšném commitu (mimo retry)
        notifikace_nova_objednavka($pdo, $obj_id);
        json_response(['id' => $obj_id, 'cislo' => $cislo], 201);
        exit;
    }

    json_error('Neznámá akce');
}

if ($method === 'PUT') {
    $d = json_input();
    if (empty($d['id'])) json_error('Chybí ID');
    $obj_id = (int) $d['id'];
    $vynutit = !empty($d['vynutit']);   // Klient potvrdil dialog "Opravdu změnit i fakturovanou objednávku?"

    $platne_stavy = ['nova','potvrzena','ve_vyrobe','pripravena','expedovana','dorucena','zrusena'];

    // Načti objednávku PŘED změnou (pro porovnání + email)
    $stmt = $pdo->prepare("SELECT * FROM objednavky WHERE id = :id");
    $stmt->execute(['id' => $obj_id]);
    $orig = $stmt->fetch();
    if (!$orig) json_error('Objednávka nenalezena', 404);

    // Načti staré položky (LEFT JOIN — volné řádky)
    $stmt = $pdo->prepare("
        SELECT op.id, op.vyrobek_id, op.mnozstvi,
               COALESCE(v.nazev, op.vyrobek_nazev) AS vyrobek_nazev
        FROM objednavky_polozky op
        LEFT JOIN vyrobky v ON v.id = op.vyrobek_id
        WHERE op.objednavka_id = :id
    ");
    $stmt->execute(['id' => $obj_id]);
    $stareItems = [];
    foreach ($stmt->fetchAll() as $r) {
        $stareItems[(int) $r['id']] = [
            'vyrobek_id' => $r['vyrobek_id'] !== null ? (int) $r['vyrobek_id'] : null,
            'mnozstvi'   => (float) $r['mnozstvi'],
            'nazev'      => $r['vyrobek_nazev'],
        ];
    }

    // Zjisti zda má objednávka DL nebo fakturu
    $maDL = (bool) $pdo->query("SELECT COUNT(*) FROM dodaci_listy WHERE objednavka_id = $obj_id")->fetchColumn();
    $maFA = (bool) $pdo->query("SELECT COUNT(*) FROM faktury_dodaci_listy fdl JOIN dodaci_listy dl ON dl.id = fdl.dodaci_list_id WHERE dl.objednavka_id = $obj_id")->fetchColumn();

    // Pokud má DL nebo fakturu a není potvrzeno, vrať varování
    $chceMenitData = isset($d['datum_dodani']) || !empty($d['polozky_zmeny']);
    if (($maDL || $maFA) && $chceMenitData && !$vynutit) {
        json_error(
            ($maFA ? 'Tato objednávka má vystavenou FAKTURU.' : 'Tato objednávka má vystavený DODACÍ LIST.') .
            ' Pro úpravu položek použijte parametr "vynutit": true. (Pozn.: bez něj systém změnu blokuje, abyste si ověřili, že chcete přepsat již vystavený doklad.)',
            409
        );
    }

    // Kdo je přihlášený admin
    $admin_id = $_SESSION['admin_id'] ?? 0;
    $admin_jmeno = $_SESSION['admin_jmeno'] ?? ($_SESSION['admin_email'] ?? 'Admin');

    $pdo->beginTransaction();
    try {
        $zmenyDoEmailu = ['polozky' => false, 'datum' => null, 'stav' => null];

        if (isset($d['stav'])) {
            if (!in_array($d['stav'], $platne_stavy)) throw new Exception('Neplatný stav');
            if ($d['stav'] !== $orig['stav']) {
                $zmenyDoEmailu['stav'] = ['z' => $orig['stav'], 'na' => $d['stav']];
            }
            $pdo->prepare("UPDATE objednavky SET stav = :s WHERE id = :id")
                ->execute(['s' => $d['stav'], 'id' => $obj_id]);
        }
        if (isset($d['interni_pozn'])) {
            $pdo->prepare("UPDATE objednavky SET interni_pozn = :p WHERE id = :id")
                ->execute(['p' => $d['interni_pozn'], 'id' => $obj_id]);
        }
        if (isset($d['datum_dodani'])) {
            if ($d['datum_dodani'] !== $orig['datum_dodani']) {
                $zmenyDoEmailu['datum'] = ['z' => $orig['datum_dodani'], 'na' => $d['datum_dodani']];
            }
            $pdo->prepare("UPDATE objednavky SET datum_dodani = :d WHERE id = :id")
                ->execute(['d' => $d['datum_dodani'], 'id' => $obj_id]);
        }
        if (!empty($d['polozky_zmeny']) && is_array($d['polozky_zmeny'])) {
            foreach ($d['polozky_zmeny'] as $z) {
                $pid = (int) ($z['polozka_id'] ?? 0);
                $mn  = (float) ($z['mnozstvi'] ?? 0);
                if (!$pid) continue;
                if ($mn <= 0) {
                    $pdo->prepare("DELETE FROM objednavky_polozky WHERE id = :id AND objednavka_id = :o")
                        ->execute(['id' => $pid, 'o' => $obj_id]);
                } else {
                    $pdo->prepare("UPDATE objednavky_polozky SET mnozstvi = :m WHERE id = :id AND objednavka_id = :o")
                        ->execute(['m' => $mn, 'id' => $pid, 'o' => $obj_id]);
                }
            }
            prepocitat_objednavku($pdo, $obj_id);
            $zmenyDoEmailu['polozky'] = true;

            // Synchronizace s DL a fakturou (pokud existují)
            if ($maDL) {
                synchronizovat_dl_z_objednavky($pdo, $obj_id);
            }
            if ($maFA) {
                synchronizovat_fakturu_z_objednavky($pdo, $obj_id);
            }
        }

        // Logování + email pouze pokud došlo k podstatné změně
        $podstatnaZmena = $zmenyDoEmailu['polozky'] || $zmenyDoEmailu['datum'] || $zmenyDoEmailu['stav'];
        if ($podstatnaZmena) {
            // Spočti diff položek pro email (LEFT JOIN — volné řádky)
            $stmt = $pdo->prepare("
                SELECT op.id, op.vyrobek_id, op.mnozstvi,
                       COALESCE(v.nazev, op.vyrobek_nazev) AS vyrobek_nazev
                FROM objednavky_polozky op
                LEFT JOIN vyrobky v ON v.id = op.vyrobek_id
                WHERE op.objednavka_id = :id
            ");
            $stmt->execute(['id' => $obj_id]);
            $noveItems = [];
            foreach ($stmt->fetchAll() as $r) {
                $noveItems[(int) $r['id']] = [
                    'vyrobek_id' => (int) $r['vyrobek_id'],
                    'mnozstvi'   => (float) $r['mnozstvi'],
                    'nazev'      => $r['vyrobek_nazev'],
                ];
            }
            $diff = ['pridane' => [], 'odebrane' => [], 'zmenene' => []];
            foreach ($noveItems as $pid => $n) {
                if (!isset($stareItems[$pid])) {
                    $diff['pridane'][] = ['nazev' => $n['nazev'], 'mnozstvi' => $n['mnozstvi']];
                } elseif (abs($stareItems[$pid]['mnozstvi'] - $n['mnozstvi']) > 0.001) {
                    $diff['zmenene'][] = [
                        'nazev'   => $n['nazev'],
                        'puvodne' => $stareItems[$pid]['mnozstvi'],
                        'nove'    => $n['mnozstvi'],
                    ];
                }
            }
            foreach ($stareItems as $pid => $s) {
                if (!isset($noveItems[$pid])) {
                    $diff['odebrane'][] = ['nazev' => $s['nazev'], 'mnozstvi' => $s['mnozstvi']];
                }
            }

            // Doplň info o přepsaných dokladech
            $detail = [
                'rozdil' => $diff,
                'datum'  => $zmenyDoEmailu['datum'],
                'stav'   => $zmenyDoEmailu['stav'],
            ];
            if ($zmenyDoEmailu['polozky'] && ($maDL || $maFA)) {
                $detail['prepsano'] = [];
                if ($maDL) $detail['prepsano'][] = 'dodací list';
                if ($maFA) $detail['prepsano'][] = 'faktura';
            }

            log_zmena_objednavky($pdo, $obj_id, 'admin', $admin_id, $admin_jmeno,
                !empty($zmenyDoEmailu['stav']) && $d['stav'] === 'zrusena' ? 'zrusena' : 'upravena',
                $detail
            );
        }

        $pdo->commit();

        // Email mimo transakci
        if ($podstatnaZmena) {
            // Načti aktuální čísla pro email
            $stmt = $pdo->prepare("SELECT castka_celkem FROM objednavky WHERE id = :id");
            $stmt->execute(['id' => $obj_id]);
            $novaCastka = (float) $stmt->fetchColumn();

            notifikace_zmena_objednavky($pdo, $obj_id, $orig, [
                'castka' => $novaCastka,
                'diff'   => $diff ?? ['pridane' => [], 'odebrane' => [], 'zmenene' => []],
                'kdo'    => $admin_jmeno . ' (administrace)',
                'zruseno' => !empty($zmenyDoEmailu['stav']) && $d['stav'] === 'zrusena',
                'prepsano' => $maDL || $maFA ? ($maFA ? 'faktura a DL' : 'dodací list') : null,
            ]);
        }

        // Notifikace odběrateli o změně stavu (pokud admin změnil stav na "zajímavý" — expedovana, dorucena…)
        if (!empty($zmenyDoEmailu['stav'])) {
            $stz = $zmenyDoEmailu['stav']['z'] ?? '';
            $stna = $zmenyDoEmailu['stav']['na'] ?? '';
            // Zrušení už řeší notifikace_zmena_objednavky výše, tady jen ostatní stavy
            if ($stna !== 'zrusena' && $stz !== $stna) {
                notifikace_zmena_stavu($pdo, $obj_id, $stz, $stna);
            }
        }

        json_response(['ok' => true, 'prepsan_dl' => $maDL, 'prepsana_faktura' => $maFA]);
    } catch (Exception $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        json_error($e->getMessage(), 400);
    }
}

if ($method === 'DELETE') {
    if (($_GET['action'] ?? '') === 'smazat_polozku') {
        $polozka_id = (int) ($_GET['id'] ?? 0);
        $obj_id     = (int) ($_GET['objednavka_id'] ?? 0);
        if (!$polozka_id || !$obj_id) json_error('Chybí ID');

        if (objednavka_zamcena($pdo, $obj_id)) {
            json_error('Položku nelze smazat - objednávka má dodací list', 409);
        }

        $pdo->beginTransaction();
        try {
            $pdo->prepare("DELETE FROM objednavky_polozky WHERE id = :id AND objednavka_id = :o")
                ->execute(['id' => $polozka_id, 'o' => $obj_id]);
            prepocitat_objednavku($pdo, $obj_id);
            $pdo->commit();
            json_response(['ok' => true]);
        } catch (Exception $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            error_log('admin_objednavky DELETE polozka: ' . $e->getMessage());
            json_error('Nepodařilo se smazat položku', 500);
        }
        exit;
    }

    // Smazání celé objednávky - jen pokud nemá DL/fakturu
    require_super_admin(); // jen super admin smí mazat celé objednávky
    $id = (int) ($_GET['id'] ?? 0);
    if (!$id) json_error('Chybí ID');

    if (objednavka_zamcena($pdo, $id)) {
        json_error('Objednávku nelze smazat - existuje k ní dodací list nebo faktura. '
                 . 'Místo toho ji označte jako "zrušenou".', 409);
    }

    // === AUTO-SNAPSHOT před smazáním celé objednávky ===
    require_once __DIR__ . '/_zaloha_helper.php';
    zaloha_snapshot($pdo, 'Před smazáním objednávky ID ' . $id);

    $pdo->prepare("DELETE FROM objednavky WHERE id = :id")->execute(['id' => $id]);
    json_response(['ok' => true]);
}

json_error('Neplatná metoda', 405);
