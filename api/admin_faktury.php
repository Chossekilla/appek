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

/** 🆕 v3.0.275 — řádky faktury se stabilním faktura_polozky.id. Faktura vystavená z DL
 *  vlastní položky nemá → MATERIALIZUJ je z dodaci_list_polozky (jednorázově, idempotentně),
 *  aby šel částečný dobropis adresovat konkrétní řádek a kumulativně hlídat zbytek. */
function faktura_ensure_polozky(PDO $pdo, int $faId): array {
    $load = function () use ($pdo, $faId) {
        $p = $pdo->prepare("SELECT id, vyrobek_id, vyrobek_cislo, vyrobek_nazev, jednotka, mnozstvi, cena_bez_dph, sazba_dph
                            FROM faktura_polozky WHERE faktura_id = :id ORDER BY poradi, id");
        $p->execute(['id' => $faId]);
        return $p->fetchAll(PDO::FETCH_ASSOC);
    };
    $items = $load();
    if ($items) return $items;
    $dlp = $pdo->prepare("SELECT dlp.vyrobek_id, dlp.vyrobek_cislo, dlp.vyrobek_nazev, dlp.jednotka,
                                 dlp.mnozstvi, dlp.cena_bez_dph, dlp.sazba_dph
                          FROM faktury_dodaci_listy fdl
                          JOIN dodaci_list_polozky dlp ON dlp.dodaci_list_id = fdl.dodaci_list_id
                          WHERE fdl.faktura_id = :id ORDER BY dlp.dodaci_list_id, dlp.id");
    $dlp->execute(['id' => $faId]);
    $dlItems = $dlp->fetchAll(PDO::FETCH_ASSOC);
    if (!$dlItems) return [];
    $im = $pdo->prepare("INSERT INTO faktura_polozky (faktura_id, vyrobek_id, vyrobek_cislo, vyrobek_nazev,
                                                      jednotka, mnozstvi, cena_bez_dph, sazba_dph, poradi)
                         VALUES (:f,:v,:vc,:vn,:j,:m,:c,:s,:p)");
    foreach ($dlItems as $i => $it) {
        $im->execute(['f' => $faId, 'v' => $it['vyrobek_id'], 'vc' => $it['vyrobek_cislo'] ?? null,
                      'vn' => $it['vyrobek_nazev'], 'j' => $it['jednotka'] ?: 'ks',
                      'm' => $it['mnozstvi'], 'c' => $it['cena_bez_dph'], 's' => $it['sazba_dph'], 'p' => $i]);
    }
    return $load();
}

/** Kolik z každého řádku faktury už dobropisováno (DOB- řádky = kladné mnozstvi). pid => qty */
function faktura_jiz_dobropis(PDO $pdo, int $faId): array {
    $cr = $pdo->prepare("SELECT fp.vraci_polozku_id AS pid, COALESCE(SUM(fp.mnozstvi), 0) AS qty
                         FROM faktura_polozky fp JOIN faktury db ON db.id = fp.faktura_id
                         WHERE db.puvodni_faktura_id = :src AND db.je_dobropis = 1 AND fp.vraci_polozku_id IS NOT NULL
                         GROUP BY fp.vraci_polozku_id");
    $cr->execute(['src' => $faId]);
    $m = [];
    foreach ($cr->fetchAll(PDO::FETCH_ASSOC) as $r) $m[(int) $r['pid']] = (float) $r['qty'];
    return $m;
}

// 🆕 v3.0.275 — GET ?action=vratitelne&faktura_id=X → řádky + kolik už dobropisováno + zbývá (pro UI).
if ($method === 'GET' && ($_GET['action'] ?? '') === 'vratitelne') {
    $srcId = (int) ($_GET['faktura_id'] ?? 0);
    if (!$srcId) json_error('Chybí faktura_id', 400);
    try { $pdo->exec("ALTER TABLE faktura_polozky ADD COLUMN IF NOT EXISTS vraci_polozku_id INT NULL"); } catch (Throwable $e) {}
    $f = $pdo->prepare("SELECT id, cislo, je_dobropis, castka_celkem FROM faktury WHERE id = :id");
    $f->execute(['id' => $srcId]);
    $fa = $f->fetch(PDO::FETCH_ASSOC);
    if (!$fa) json_error('Faktura nenalezena', 404);
    if (!empty($fa['je_dobropis'])) json_error('Dobropis nelze dobropisovat', 400);
    $items   = faktura_ensure_polozky($pdo, $srcId);
    $already = faktura_jiz_dobropis($pdo, $srcId);
    $out = [];
    foreach ($items as $it) {
        $id  = (int) $it['id'];
        $rem = (float) $it['mnozstvi'] - ($already[$id] ?? 0);
        $out[] = [
            'id' => $id, 'vyrobek_nazev' => $it['vyrobek_nazev'], 'jednotka' => $it['jednotka'],
            'mnozstvi' => (float) $it['mnozstvi'], 'cena_bez_dph' => (float) $it['cena_bez_dph'],
            'sazba_dph' => (float) $it['sazba_dph'],
            'jiz_vraceno' => round($already[$id] ?? 0, 3), 'zbyva' => round(max(0, $rem), 3),
        ];
    }
    json_response(['faktura' => $fa['cislo'], 'castka_celkem' => (float) $fa['castka_celkem'], 'polozky' => $out]);
}

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

        // 🆕 v3.0.238 — související dodací listy (zpětná navigace faktura → DL; dřív
        //   chyběla, faktura linkovala jen na objednávku → nekonzistentní řetězec).
        try {
            $stmt = $pdo->prepare("
                SELECT dl.id, dl.cislo, dl.datum_dodani
                FROM faktury_dodaci_listy fdl
                JOIN dodaci_listy dl ON dl.id = fdl.dodaci_list_id
                WHERE fdl.faktura_id = :id
                ORDER BY dl.id
            ");
            $stmt->execute(['id' => (int) $f['id']]);
            $f['dodaci_listy'] = $stmt->fetchAll();
        } catch (Exception $e) {
            $f['dodaci_listy'] = [];
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
               f.rucni, f.obsah_upraveno, f.je_dobropis, f.puvodni_faktura_id,
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
        // 🐛 v3.0.265 — opakovaný :q s EMULATE_PREPARES=false → 500. Unikátní placeholdery.
        $where .= " AND (f.cislo LIKE :q OR od.nazev LIKE :q2 OR f.variabilni_symbol LIKE :q3)";
        $params['q'] = '%' . $hl . '%';
        $params['q2'] = $params['q'];
        $params['q3'] = $params['q'];
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
// 🆕 v3.0.268 — VRATKY: DOBROPIS (opravný daňový doklad) k faktuře
// POST ?action=dobropis { faktura_id, duvod? } → nová faktura řady DOB- se
// zápornými částkami + položkami a vazbou puvodni_faktura_id; stav_uhrady vyjde
// 'uhrazena' (0 ≥ záporné celkem) → nešpiní „po splatnosti".
// ⚠️ v3.0.356 oprava komentáře: tržby/statistiky se počítají z OBJEDNÁVEK (ne z faktur),
// takže dobropis SÁM tržbu NESNIŽUJE — snížení zajistí storno původní objednávky
// (gated: vyfakturovaná obj. → nejdřív dobropis, pak storno). Dřív tu stálo „statistiky
// započtou záporně automaticky" = nepravda.
// =============================================================
if ($method === 'POST' && ($_GET['action'] ?? '') === 'dobropis') {
    $d = json_input();
    $srcId = (int) ($d['faktura_id'] ?? 0);
    $duvod = trim((string) ($d['duvod'] ?? ''));
    // 🆕 v3.0.275 — částečný dobropis: polozky:[{polozka_id, mnozstvi}]. Bez nich = plný dobropis zbytku.
    $reqPolozky = (isset($d['polozky']) && is_array($d['polozky']) && $d['polozky']) ? $d['polozky'] : null;
    if (!$srcId) json_error('Chybí faktura_id', 400);

    try { $pdo->exec("ALTER TABLE faktura_polozky ADD COLUMN IF NOT EXISTS vraci_polozku_id INT NULL"); } catch (Throwable $e) {}

    $src = $pdo->prepare("SELECT * FROM faktury WHERE id = :id");
    $src->execute(['id' => $srcId]);
    $f = $src->fetch(PDO::FETCH_ASSOC);
    if (!$f) json_error('Faktura nenalezena', 404);
    if (!empty($f['je_dobropis'])) json_error('K dobropisu nelze vystavit další dobropis', 400);

    // 🆕 v3.0.278 — LHŮTA NA VRÁCENÍ: blokuj dobropis po uplynutí lhůty (override: vynutit).
    if (empty($d['vynutit']) && !empty($f['datum_vystaveni'])) {
        $lhuta = (int) ($pdo->query("SELECT hodnota FROM nastaveni WHERE klic='vratka_lhuta_dni' LIMIT 1")->fetchColumn() ?: 14);
        if ($lhuta > 0) {
            $dni = (int) floor((time() - strtotime($f['datum_vystaveni'])) / 86400);
            if ($dni > $lhuta) {
                json_error('Faktura je ' . $dni . ' dní stará — lhůta na vrácení je ' . $lhuta . ' dní (vrátit šlo do ' . date('j.n.Y', strtotime($f['datum_vystaveni'] . " +{$lhuta} days")) . '). Pro dobropis i přesto pošli "vynutit": true.', 409);
            }
        }
    }

    // Řádky faktury se stabilním id (materializace z DL když chybí) + kolik už dobropisováno.
    $items = faktura_ensure_polozky($pdo, $srcId);
    if (!$items) json_error('Faktura nemá položky k dobropisu', 400);
    $itemsById = [];
    foreach ($items as $it) $itemsById[(int) $it['id']] = $it;
    $already = faktura_jiz_dobropis($pdo, $srcId);

    // Co dobropisovat
    $want = [];
    if ($reqPolozky !== null) {
        foreach ($reqPolozky as $rp) {
            $pid = (int) ($rp['polozka_id'] ?? 0);
            $q   = (float) ($rp['mnozstvi'] ?? 0);
            if (!$pid || !isset($itemsById[$pid]) || $q <= 0) continue;
            $want[$pid] = ($want[$pid] ?? 0) + $q;
        }
        if (!$want) json_error('Nevybrány žádné platné položky', 400);
    } else {
        foreach ($items as $it) {
            $rem = (float) $it['mnozstvi'] - ($already[(int) $it['id']] ?? 0);
            if ($rem > 0.0001) $want[(int) $it['id']] = $rem;
        }
    }

    // Validace proti zbytku + součty
    $credLines = []; $bez = 0.0; $dph = 0.0;
    foreach ($want as $pid => $q) {
        $it  = $itemsById[$pid];
        $rem = (float) $it['mnozstvi'] - ($already[$pid] ?? 0);
        $nm  = $it['vyrobek_nazev'] ?: '?';
        if ($rem <= 0.0001) json_error('Položka „' . $nm . '" už byla celá dobropisována', 409);
        if ($q - $rem > 0.0001) json_error('U „' . $nm . '" lze dobropisovat max ' . rtrim(rtrim(number_format($rem, 3, '.', ''), '0'), '.'), 400);
        $credLines[] = ['orig' => $it, 'qty' => $q];
        $bez += $q * (float) $it['cena_bez_dph'];
        $dph += $q * (float) $it['cena_bez_dph'] * (float) $it['sazba_dph'] / 100;
    }
    if (!$credLines) json_error('Faktura už byla celá dobropisována', 409);
    $bez = round($bez, 2); $dph = round($dph, 2); $cel = round($bez + $dph, 2);

    require_once __DIR__ . '/_zaloha_helper.php';
    try { zaloha_snapshot($pdo, 'Před dobropisem k ' . $f['cislo']); } catch (Throwable $e) {}

    $pdo->beginTransaction();
    try {
        $cislo = dalsi_cislo($pdo, 'DOB', (int) date('Y'));
        $pozn  = 'Dobropis k faktuře ' . $f['cislo'] . ($reqPolozky !== null ? ' (částečný)' : '') . ($duvod !== '' ? ' — důvod: ' . $duvod : '');
        $pdo->prepare("
            INSERT INTO faktury (cislo, odberatel_id, misto_dodani_id, datum_vystaveni, datum_dph, datum_splatnosti,
                                 castka_bez_dph, castka_dph, castka_celkem, castka_uhrazeno,
                                 variabilni_symbol, poznamka, rucni, je_dobropis, puvodni_faktura_id,
                                 odb_nazev_snapshot, odb_ico_snapshot, odb_dic_snapshot,
                                 odb_ulice_snapshot, odb_mesto_snapshot, odb_psc_snapshot)
            VALUES (:c, :o, :m, CURDATE(), CURDATE(), DATE_ADD(CURDATE(), INTERVAL 14 DAY),
                    :bez, :dph, :cel, 0,
                    :vs, :p, 1, 1, :puv,
                    :sn, :si, :sd, :su, :sm, :sp)
        ")->execute([
            'c'   => $cislo, 'o' => $f['odberatel_id'], 'm' => $f['misto_dodani_id'] ?? null,
            'bez' => -$bez, 'dph' => -$dph, 'cel' => -$cel,
            // VS s prefixem 9 — nekolidovat s VS původních faktur (stejné číslice řady)
            'vs'  => '9' . (preg_replace('/\D/', '', $cislo) ?: '0'),
            'p'   => $pozn, 'puv' => $srcId,
            'sn'  => $f['odb_nazev_snapshot'] ?? null, 'si' => $f['odb_ico_snapshot'] ?? null,
            'sd'  => $f['odb_dic_snapshot'] ?? null,  'su' => $f['odb_ulice_snapshot'] ?? null,
            'sm'  => $f['odb_mesto_snapshot'] ?? null, 'sp' => $f['odb_psc_snapshot'] ?? null,
        ]);
        $dobId = (int) $pdo->lastInsertId();
        $ins = $pdo->prepare("
            INSERT INTO faktura_polozky (faktura_id, vyrobek_id, vyrobek_cislo, vyrobek_nazev,
                                         jednotka, mnozstvi, cena_bez_dph, sazba_dph, poradi, vraci_polozku_id)
            VALUES (:f,:v,:vc,:vn,:j,:m,:c,:s,:p,:vp)");
        foreach ($credLines as $i => $cl) {
            $it = $cl['orig'];
            $ins->execute([
                'f' => $dobId, 'v' => $it['vyrobek_id'], 'vc' => $it['vyrobek_cislo'] ?? null,
                'vn' => $it['vyrobek_nazev'], 'j' => $it['jednotka'] ?: 'ks',
                'm' => $cl['qty'], 'c' => -1 * (float) $it['cena_bez_dph'],
                's' => $it['sazba_dph'], 'p' => $i, 'vp' => (int) $it['id'],
            ]);
        }
        $pdo->commit();
        json_response(['ok' => true, 'id' => $dobId, 'cislo' => $cislo,
                       'castka_celkem' => -$cel, 'puvodni' => $f['cislo'],
                       'castecny' => ($reqPolozky !== null), 'polozek' => count($credLines)], 201);
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        json_error_safe('Dobropis se nepodařilo vystavit', $e, 500);
    }
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

    // 🆕 v3.0.358 — zamknutí mazání vydaných dokladů (Nastavení → Údržba → Doklady).
    // Default zamknuto kvůli souvislosti číselné řady (zákon). Vypnutí = vědomé.
    if (nastaveni_get($pdo, 'faktura_zamknout_mazani', '1') === '1') {
        json_error('Mazání vydaných dokladů je zamčené (Nastavení → Údržba → Doklady). Pro opravu použijte storno / dobropis — číselná řada musí být souvislá.', 409);
    }

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
