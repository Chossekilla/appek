<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/_admin_auth.php';
require_once __DIR__ . '/_schema_lib.php';
cors_headers();
require_admin();

$method = $_SERVER['REQUEST_METHOD'];
$pdo = db();

// 🆕 v3.0.11 — auto-migrace faktury snapshot sloupců (idempotent)
ensure_faktury_schema($pdo);

if ($method === 'GET') {
    if (isset($_GET['id'])) {
        $stmt = $pdo->prepare("
            SELECT f.*, od.nazev AS odberatel_nazev
            FROM faktury f
            JOIN odberatele od ON od.id = f.odberatel_id
            WHERE f.id = :id
        ");
        $stmt->execute(['id' => (int) $_GET['id']]);
        $f = $stmt->fetch();
        if (!$f) json_error('Faktura nenalezena', 404);

        // Položky faktury
        $stmt = $pdo->prepare("
            SELECT id, vyrobek_id, vyrobek_cislo, vyrobek_nazev, jednotka,
                   mnozstvi, cena_bez_dph, sazba_dph, poznamka, poradi
            FROM faktura_polozky
            WHERE faktura_id = :id
            ORDER BY poradi ASC, id ASC
        ");
        $stmt->execute(['id' => (int) $f['id']]);
        $f['polozky'] = $stmt->fetchAll();

        // 🆕 Fallback — faktura z objednávky/DL má položky v dodaci_list_polozky
        //   (faktura_polozky je prázdná). Detail jinak ukazoval "Žádné položky"
        //   u faktury s nenulovou částkou. Stejná kaskáda jako faktura.php PDF.
        if (empty($f['polozky'])) {
            $stmt = $pdo->prepare("
                SELECT dlp.id, dlp.vyrobek_id, dlp.vyrobek_cislo, dlp.vyrobek_nazev, dlp.jednotka,
                       dlp.mnozstvi, dlp.cena_bez_dph, dlp.sazba_dph, dlp.poznamka, dlp.id AS poradi
                FROM faktury_dodaci_listy fdl
                JOIN dodaci_list_polozky dlp ON dlp.dodaci_list_id = fdl.dodaci_list_id
                WHERE fdl.faktura_id = :id
                ORDER BY dlp.dodaci_list_id, dlp.id
            ");
            $stmt->execute(['id' => (int) $f['id']]);
            $f['polozky'] = $stmt->fetchAll();
            if (!empty($f['polozky'])) $f['polozky_zdroj'] = 'dodaci_list';
        }

        // Související objednávky (přes DL)
        try {
            $stmt = $pdo->prepare("
                SELECT DISTINCT o.id, o.cislo, o.datum_dodani
                FROM objednavky o
                JOIN dodaci_listy dl ON dl.objednavka_id = o.id
                JOIN faktury_dodaci_listy fdl ON fdl.dodaci_list_id = dl.id
                WHERE fdl.faktura_id = :id
            ");
            $stmt->execute(['id' => (int) $f['id']]);
            $f['objednavky'] = $stmt->fetchAll();
        } catch (Exception $e) {
            $f['objednavky'] = [];
        }

        json_response($f);
    }

    $stav     = $_GET['stav_uhrady'] ?? '';
    $hledat   = trim($_GET['q'] ?? '');
    $datum_od = trim($_GET['datum_od'] ?? '');
    $datum_do = trim($_GET['datum_do'] ?? '');

    $sql = "
        SELECT f.id, f.cislo, f.datum_vystaveni, f.datum_splatnosti,
               f.castka_celkem, f.castka_uhrazeno, f.variabilni_symbol, f.odeslano_email,
               f.rucni, f.obsah_upraveno,
               od.nazev AS odberatel_nazev,
               CASE
                   WHEN f.castka_uhrazeno >= f.castka_celkem THEN 'uhrazena'
                   WHEN f.datum_splatnosti < CURDATE() THEN 'po_splatnosti'
                   ELSE 'cekajici'
               END AS stav_uhrady,
               COALESCE(ag_pol.pocet, 0)         AS pocet_polozek,
               COALESCE(ag_dl.pocet_dl, 0)       AS pocet_dl,
               ag_dl.dl_cisla,
               ag_dl.objednavka_cisla,
               ag_dl.prvni_objednavka_id,
               ag_dl.prvni_dl_objednavka_id,
               ag_dl.prvni_dl_id,
               ag_dl.pobocky_nazvy,
               (SELECT CONCAT_WS(', ', md.ulice, md.mesto)
                  FROM faktury_dodaci_listy fdl
                  JOIN dodaci_listy dl ON dl.id = fdl.dodaci_list_id
                  LEFT JOIN mista_dodani md ON md.id = dl.misto_dodani_id
                 WHERE fdl.faktura_id = f.id AND md.nazev IS NOT NULL
                 LIMIT 1) AS pobocka_adresa
        FROM faktury f
        JOIN odberatele od ON od.id = f.odberatel_id
        -- 🚀 N+1 → 2 agregované JOINy (z původních 10 subqueries v každém řádku)
        LEFT JOIN (
            SELECT faktura_id, COUNT(*) AS pocet
            FROM faktura_polozky
            GROUP BY faktura_id
        ) ag_pol ON ag_pol.faktura_id = f.id
        LEFT JOIN (
            SELECT fdl.faktura_id,
                   COUNT(DISTINCT dl.id)                                                          AS pocet_dl,
                   GROUP_CONCAT(DISTINCT dl.cislo ORDER BY dl.datum_vystaveni SEPARATOR ', ')     AS dl_cisla,
                   GROUP_CONCAT(DISTINCT o.cislo ORDER BY o.datum_dodani SEPARATOR ', ')          AS objednavka_cisla,
                   MIN(o.id)                                                                       AS prvni_objednavka_id,
                   MIN(dl.objednavka_id)                                                           AS prvni_dl_objednavka_id,
                   MIN(fdl.dodaci_list_id)                                                         AS prvni_dl_id,
                   GROUP_CONCAT(DISTINCT md.nazev SEPARATOR ', ')                                  AS pobocky_nazvy
            FROM faktury_dodaci_listy fdl
            JOIN dodaci_listy dl ON dl.id = fdl.dodaci_list_id
            LEFT JOIN objednavky o ON o.id = dl.objednavka_id
            LEFT JOIN mista_dodani md ON md.id = dl.misto_dodani_id
            GROUP BY fdl.faktura_id
        ) ag_dl ON ag_dl.faktura_id = f.id
        WHERE 1=1
    ";
    $params = [];

    // 🆕 v3.0.219 — filtr WHERE zvlášť (sdílí list i COUNT total pro paging)
    $where = '';
    // FIX #13: filter pushed into WHERE, ne až po LIMITu v PHP
    if ($stav === 'uhrazena') {
        $where .= " AND f.castka_uhrazeno >= f.castka_celkem";
    } elseif ($stav === 'po_splatnosti') {
        $where .= " AND f.castka_uhrazeno < f.castka_celkem AND f.datum_splatnosti < CURDATE()";
    } elseif ($stav === 'cekajici') {
        $where .= " AND f.castka_uhrazeno < f.castka_celkem AND f.datum_splatnosti >= CURDATE()";
    }

    if ($hledat !== '') {
        $hl = str_replace(['\\','%','_'], ['\\\\','\\%','\\_'], $hledat);
        $where .= " AND (f.cislo LIKE :q OR od.nazev LIKE :q OR f.variabilni_symbol LIKE :q)";
        $params['q'] = '%' . $hl . '%';
    }
    if ($datum_od !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $datum_od)) {
        $where .= " AND f.datum_vystaveni >= :do_";
        $params['do_'] = $datum_od;
    }
    if ($datum_do !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $datum_do)) {
        $where .= " AND f.datum_vystaveni <= :ddo";
        $params['ddo'] = $datum_do;
    }

    // 🆕 v3.0.219 — paging: offset/limit + total
    $limit  = max(1, min(200, (int) ($_GET['limit'] ?? 50)));
    $offset = max(0, (int) ($_GET['offset'] ?? 0));
    $cs = $pdo->prepare("SELECT COUNT(*) FROM faktury f JOIN odberatele od ON od.id = f.odberatel_id WHERE 1=1" . $where);
    $cs->execute($params);
    $total = (int) $cs->fetchColumn();

    $sql .= $where . " ORDER BY f.datum_vystaveni DESC, f.id DESC LIMIT $limit OFFSET $offset";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $faktury = $stmt->fetchAll();

    $souhrn = $pdo->query("
        SELECT COUNT(*) AS celkem,
               COALESCE(SUM(castka_celkem), 0) AS celkem_kc,
               COALESCE(SUM(CASE WHEN castka_uhrazeno < castka_celkem AND datum_splatnosti < CURDATE()
                                 THEN castka_celkem - castka_uhrazeno ELSE 0 END), 0) AS po_splatnosti_kc
        FROM faktury
    ")->fetch();

    json_response([
        'faktury'  => $faktury,
        'souhrn'   => $souhrn,
        'total'    => $total,
        'offset'   => $offset,
        'limit'    => $limit,
        'has_more' => ($offset + count($faktury)) < $total,
    ]);
}

if ($method === 'PUT') {
    $d = json_input();
    if (empty($d['id'])) json_error('Chybí ID');
    $fa_id = (int) $d['id'];

    // 🆕 v3.0.155 — ověř existenci faktury (jinak tichý no-op UPDATE) + získej celkem pro validaci úhrady
    $faRow = $pdo->prepare("SELECT castka_celkem FROM faktury WHERE id = :id");
    $faRow->execute(['id' => $fa_id]);
    $faCelkem = $faRow->fetchColumn();
    if ($faCelkem === false) json_error('Faktura neexistuje', 404);

    // 🆕 v3.0.155 — validace úhrady: číselná, ≥ 0, ne přes částku faktury.
    //   Dřív: záporná → záporná pohledávka, ne-číselná → 500, přeplatek překlepem → tichý zápis.
    if (isset($d['castka_uhrazeno'])) {
        if (!is_numeric($d['castka_uhrazeno'])) json_error('Úhrada musí být číslo', 400);
        $uhr = (float) $d['castka_uhrazeno'];
        if ($uhr < 0) json_error('Úhrada nesmí být záporná', 400);
        if ($uhr > (float) $faCelkem + 0.01) json_error('Úhrada (' . $uhr . ') přesahuje částku faktury (' . $faCelkem . ' Kč)', 400);
    }

    // Změny položek (polozky_zmeny: [{polozka_id, mnozstvi}, ...])
    if (!empty($d['polozky_zmeny']) && is_array($d['polozky_zmeny'])) {
        $pdo->beginTransaction();
        try {
            foreach ($d['polozky_zmeny'] as $z) {
                $pid = (int) ($z['polozka_id'] ?? 0);
                $mn  = (float) ($z['mnozstvi'] ?? 0);
                if (!$pid) continue;
                if ($mn <= 0) {
                    $pdo->prepare("DELETE FROM faktura_polozky WHERE id = :id AND faktura_id = :f")
                        ->execute(['id' => $pid, 'f' => $fa_id]);
                } else {
                    $pdo->prepare("UPDATE faktura_polozky SET mnozstvi = :m WHERE id = :id AND faktura_id = :f")
                        ->execute(['m' => $mn, 'id' => $pid, 'f' => $fa_id]);
                }
            }

            // Přepočítej součty
            $stmt = $pdo->prepare("
                SELECT cena_bez_dph, sazba_dph, mnozstvi
                FROM faktura_polozky
                WHERE faktura_id = :f
            ");
            $stmt->execute(['f' => $fa_id]);
            $bez = 0; $dph = 0;
            foreach ($stmt->fetchAll() as $p) {
                $b = (float) $p['cena_bez_dph'] * (float) $p['mnozstvi'];
                $bez += $b;
                $dph += $b * (float) $p['sazba_dph'] / 100;
            }
            $pdo->prepare("
                UPDATE faktury SET castka_bez_dph = :b, castka_dph = :d, castka_celkem = :c,
                       obsah_upraveno = NOW()
                WHERE id = :id
            ")->execute([
                'b' => round($bez, 2), 'd' => round($dph, 2),
                'c' => round($bez + $dph, 2), 'id' => $fa_id,
            ]);

            $pdo->commit();
        } catch (Exception $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            error_log('admin_faktury PUT polozky: ' . $e->getMessage());
            json_error_safe('Nepodařilo se uložit změny', $e, 500);
        }
    }

    // Standardní pole (úhrada, splatnost, VS, poznámka)
    $updates = [];
    $params  = ['id' => $fa_id];
    foreach (['castka_uhrazeno' => 'u', 'datum_splatnosti' => 'sp',
              'poznamka' => 'po', 'variabilni_symbol' => 'vs'] as $k => $p) {
        if (isset($d[$k])) {
            $updates[] = "$k = :$p";
            $params[$p] = $d[$k];
        }
    }
    if (!empty($updates)) {
        $pdo->prepare("UPDATE faktury SET " . implode(', ', $updates) . " WHERE id = :id")
            ->execute($params);
    }

    // 🆕 v3.0.145 — auto-set datum_uhrazeni při plném zaplacení (jinak zůstávalo NULL).
    //   Plně uhrazeno → CURDATE (zachová existující), pod-uhrazeno → NULL.
    if (isset($d['castka_uhrazeno'])) {
        $pdo->prepare("UPDATE faktury SET datum_uhrazeni = CASE
                WHEN castka_uhrazeno >= castka_celkem THEN COALESCE(datum_uhrazeni, CURDATE())
                ELSE NULL END
            WHERE id = :id")->execute(['id' => $fa_id]);
    }

    json_response(['ok' => true]);
}

// =============================================================
// POST = VYTVOŘENÍ RUČNÍ FAKTURY (bez vazby na objednávku/DL)
// =============================================================
if ($method === 'POST') {
    $d = json_input();

    $odb_id   = (int) ($d['odberatel_id'] ?? 0);
    $misto_id = !empty($d['misto_dodani_id']) ? (int) $d['misto_dodani_id'] : null;
    $polozky  = $d['polozky'] ?? [];
    $datum_vystaveni  = $d['datum_vystaveni']  ?? date('Y-m-d');
    $datum_dph        = $d['datum_dph']        ?? $datum_vystaveni;
    $datum_splatnosti = $d['datum_splatnosti'] ?? null;
    $poznamka         = $d['poznamka']         ?? null;

    if (!$odb_id)               json_error('Chybí odběratel');
    if (!is_array($polozky) || count($polozky) === 0) json_error('Faktura musí mít alespoň jednu položku');
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $datum_vystaveni)) json_error('Neplatné datum vystavení');

    // Načti odběratele pro snapshot adresy a splatnost
    $stmt = $pdo->prepare("SELECT * FROM odberatele WHERE id = :id");
    $stmt->execute(['id' => $odb_id]);
    $odb = $stmt->fetch();
    if (!$odb) json_error('Odběratel nenalezen', 404);

    // Pokud místo dodání zadáno, ověř že patří odběrateli
    if ($misto_id && !misto_patri_odberateli($pdo, $misto_id, $odb_id)) {
        json_error('Místo dodání nepatří k tomuto odběrateli');
    }

    // Splatnost = z požadavku, nebo dopočti z odběratele
    if (!$datum_splatnosti) {
        $dni = (int) ($odb['splatnost_dni'] ?? 14);
        $datum_splatnosti = date('Y-m-d', strtotime($datum_vystaveni . ' + ' . $dni . ' days'));
    }

    // Načti ceník pro odběratele - využijeme jako fallback
    // (klient ale může poslat vlastní cenu/dph/název)
    $cenik = [];
    foreach (cenik_pro_odberatele($pdo, $odb_id) as $v) {
        $cenik[(int) $v['id']] = $v;
    }

    // Validace + výpočet částek
    $bez = 0; $dph_celkem = 0;
    $polozky_clean = [];
    foreach ($polozky as $i => $p) {
        $vid = isset($p['vyrobek_id']) ? (int) $p['vyrobek_id'] : null;
        $mn  = isset($p['mnozstvi']) ? (float) $p['mnozstvi'] : 0;
        if ($mn <= 0) json_error("Řádek " . ($i + 1) . ": neplatné množství");

        $row = [
            'vyrobek_id'    => $vid,
            'vyrobek_cislo' => $p['vyrobek_cislo'] ?? null,
            'vyrobek_nazev' => trim($p['vyrobek_nazev'] ?? ''),
            'jednotka'      => $p['jednotka'] ?? null,
            'mnozstvi'      => $mn,
            'cena_bez_dph'  => isset($p['cena_bez_dph']) ? (float) $p['cena_bez_dph'] : null,
            'sazba_dph'     => isset($p['sazba_dph']) ? (float) $p['sazba_dph'] : null,
            'poznamka'      => $p['poznamka'] ?? null,
            'poradi'        => $i,
        ];

        if ($vid && isset($cenik[$vid])) {
            // Položka z katalogu - použij fallback z ceníku, pokud klient nezadal
            $c = $cenik[$vid];
            if ($row['vyrobek_nazev'] === '') $row['vyrobek_nazev'] = $c['nazev'];
            if (!$row['vyrobek_cislo'])       $row['vyrobek_cislo'] = $c['cislo'];
            if (!$row['jednotka'])            $row['jednotka']      = $c['jednotka'];
            if ($row['cena_bez_dph'] === null) $row['cena_bez_dph'] = (float) $c['cena_bez_dph'];
            if ($row['sazba_dph'] === null)   $row['sazba_dph']    = (float) $c['dph'];
        }

        // Volný řádek nebo přepsaný - musí mít vše vyplněno
        if ($row['vyrobek_nazev'] === '') json_error("Řádek " . ($i + 1) . ": chybí název");
        if ($row['cena_bez_dph'] === null) json_error("Řádek " . ($i + 1) . ": chybí cena");
        if ($row['sazba_dph'] === null)   json_error("Řádek " . ($i + 1) . ": chybí sazba DPH");

        $b = $row['cena_bez_dph'] * $mn;
        $bez        += $b;
        $dph_celkem += $b * ($row['sazba_dph'] / 100);
        $polozky_clean[] = $row;
    }

    $pdo->beginTransaction();
    try {
        $cislo = dalsi_cislo($pdo, 'FA', (int) date('Y', strtotime($datum_vystaveni)));
        $vs = preg_replace('/\D/', '', $cislo);

        $pdo->prepare("
            INSERT INTO faktury (
                cislo, odberatel_id, misto_dodani_id,
                datum_vystaveni, datum_dph, datum_splatnosti,
                castka_bez_dph, castka_dph, castka_celkem,
                variabilni_symbol, poznamka, rucni,
                odb_nazev_snapshot, odb_ico_snapshot, odb_dic_snapshot,
                odb_ulice_snapshot, odb_mesto_snapshot, odb_psc_snapshot
            ) VALUES (
                :c, :o, :m,
                :dv, :dd, :ds,
                :b, :dph, :cel,
                :vs, :p, 1,
                :n, :ico, :dic, :ul, :me, :psc
            )
        ")->execute([
            'c' => $cislo, 'o' => $odb_id, 'm' => $misto_id,
            'dv' => $datum_vystaveni, 'dd' => $datum_dph, 'ds' => $datum_splatnosti,
            'b' => round($bez, 2), 'dph' => round($dph_celkem, 2),
            'cel' => round($bez + $dph_celkem, 2),
            'vs' => $vs, 'p' => $poznamka,
            'n'   => $odb['nazev'],
            'ico' => $odb['ico'],
            'dic' => $odb['dic'],
            'ul'  => $odb['ulice'],
            'me'  => $odb['mesto'],
            'psc' => $odb['psc'],
        ]);
        $fa_id = (int) $pdo->lastInsertId();

        // Vlož položky
        $stmt = $pdo->prepare("
            INSERT INTO faktura_polozky
                (faktura_id, vyrobek_id, vyrobek_cislo, vyrobek_nazev, jednotka,
                 mnozstvi, cena_bez_dph, sazba_dph, poznamka, poradi)
            VALUES (:f, :v, :c, :n, :j, :m, :ce, :s, :p, :po)
        ");
        foreach ($polozky_clean as $p) {
            $stmt->execute([
                'f' => $fa_id, 'v' => $p['vyrobek_id'], 'c' => $p['vyrobek_cislo'],
                'n' => $p['vyrobek_nazev'], 'j' => $p['jednotka'],
                'm' => $p['mnozstvi'], 'ce' => $p['cena_bez_dph'],
                's' => $p['sazba_dph'], 'p' => $p['poznamka'], 'po' => $p['poradi'],
            ]);
        }

        $pdo->commit();
        json_response(['id' => $fa_id, 'cislo' => $cislo], 201);
    } catch (Exception $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        error_log('admin_faktury POST: ' . $e->getMessage());
        json_error('Nepodařilo se vystavit fakturu', 500);
    }
}

if ($method === 'DELETE') {
    require_super_admin(); // jen super admin smí mazat faktury
    $id = (int) ($_GET['id'] ?? 0);
    if (!$id) json_error('Chybí ID');

    // === AUTO-SNAPSHOT před destruktivní akcí ===
    require_once __DIR__ . '/_zaloha_helper.php';
    zaloha_snapshot($pdo, 'Před smazáním faktury ID ' . $id);

    $pdo->beginTransaction();
    try {
        $pdo->prepare("
            UPDATE dodaci_listy dl
            JOIN faktury_dodaci_listy fdl ON fdl.dodaci_list_id = dl.id
            SET dl.fakturovano = 0
            WHERE fdl.faktura_id = :id
        ")->execute(['id' => $id]);
        $pdo->prepare("DELETE FROM faktury_dodaci_listy WHERE faktura_id = :id")
            ->execute(['id' => $id]);
        $pdo->prepare("DELETE FROM faktury WHERE id = :id")->execute(['id' => $id]);
        $pdo->commit();
        json_response(['ok' => true]);
    } catch (Exception $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        error_log('admin_faktury DELETE: ' . $e->getMessage());
        json_error('Nepodařilo se smazat fakturu', 500);
    }
}

json_error('Neplatná metoda', 405);
