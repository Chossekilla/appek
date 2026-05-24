<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/_admin_auth.php';
cors_headers();
$admin = require_admin();

$method = $_SERVER['REQUEST_METHOD'];
$pdo = db();

// =============================================================
// GET ?datum=2026-05-06  → automaticky sestavený list z objednávek
// GET ?id=123  → uložený výrobní list
// GET (bez param) → seznam uložených výrobních listů
// =============================================================
if ($method === 'GET') {
    // 🌾 Spotřeba surovin na vybraný den — sečte receptury × objednané ks
    // GET ?action=spotreba&datum=YYYY-MM-DD
    //   → { suroviny: [{ surovina_id, nazev, jednotka, potreba, skladem, min, chybi, ok }], chybi_pocet }
    if (($_GET['action'] ?? '') === 'spotreba') {
        $datum = $_GET['datum'] ?? '';
        if (!$datum || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $datum)) json_error('Chybí/neplatný datum');

        // Pro každou surovinu sečti: SUM(op.mnozstvi × vs.mnozstvi) za daný den
        // Bere se z objednávek, kde stav ≠ 'zrusena'
        $stmt = $pdo->prepare("
            SELECT s.id           AS surovina_id,
                   s.nazev        AS nazev,
                   s.jednotka     AS jednotka,
                   COALESCE(s.stock_aktualni, 0)  AS skladem,
                   s.stock_minimalni              AS minimum,
                   s.cena_baleni, s.obsah_baleni,
                   SUM(op.mnozstvi * vs.mnozstvi) AS potreba
            FROM objednavky o
            JOIN objednavky_polozky op ON op.objednavka_id = o.id
            JOIN vyrobek_suroviny vs   ON vs.vyrobek_id = op.vyrobek_id
            JOIN suroviny s            ON s.id = vs.surovina_id
            WHERE o.datum_dodani = :datum
              AND o.stav NOT IN ('zrusena')
            GROUP BY s.id, s.nazev, s.jednotka, s.stock_aktualni, s.stock_minimalni, s.cena_baleni, s.obsah_baleni
            ORDER BY s.nazev
        ");
        $stmt->execute(['datum' => $datum]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $celkemNakladu = 0.0;
        $chybi = 0;
        foreach ($rows as &$r) {
            $r['potreba']   = (float) $r['potreba'];
            $r['skladem']   = (float) $r['skladem'];
            $r['minimum']   = $r['minimum'] !== null ? (float) $r['minimum'] : null;
            $r['chybi_mn']  = max(0, $r['potreba'] - $r['skladem']);
            $r['stock_po']  = $r['skladem'] - $r['potreba'];
            $r['ok']        = $r['skladem'] >= $r['potreba'];
            // Cena: cena_baleni / obsah_baleni × potreba
            $cb = (float) ($r['cena_baleni'] ?? 0);
            $ob = (float) ($r['obsah_baleni'] ?? 0);
            $r['naklad'] = ($cb > 0 && $ob > 0) ? round($cb / $ob * $r['potreba'], 2) : null;
            if ($r['naklad'] !== null) $celkemNakladu += $r['naklad'];
            if (!$r['ok']) $chybi++;
        }
        unset($r);

        json_response([
            'datum'         => $datum,
            'suroviny'      => $rows,
            'pocet_polozek' => count($rows),
            'chybi_pocet'   => $chybi,
            'celkem_naklad' => round($celkemNakladu, 2),
        ]);
    }

    // 📤 Odepsat suroviny ze skladu pro daný den (po výrobě) — POST
    // Implementace přes POST výše

    // Kalendář: denní souhrn za zvolený měsíc
    if (($_GET['action'] ?? '') === 'kalendar') {
        $rok  = (int) ($_GET['rok']  ?? date('Y'));
        $mesic = (int) ($_GET['mesic'] ?? date('n'));
        if ($rok < 2000 || $rok > 2100) json_error('Neplatný rok');
        if ($mesic < 1 || $mesic > 12) json_error('Neplatný měsíc');

        $od = sprintf('%04d-%02d-01', $rok, $mesic);
        $do = date('Y-m-t', strtotime($od));

        $stmt = $pdo->prepare("
            SELECT o.datum_dodani AS den,
                   COUNT(DISTINCT o.id) AS pocet_objednavek,
                   COUNT(DISTINCT o.misto_dodani_id) AS pocet_mist,
                   COALESCE(SUM(p.mnozstvi), 0) AS celkem_kusu,
                   COUNT(DISTINCT p.vyrobek_id) AS pocet_vyrobku
            FROM objednavky o
            JOIN objednavky_polozky p ON p.objednavka_id = o.id
            WHERE o.datum_dodani BETWEEN :od AND :do
              AND o.stav NOT IN ('zrusena')
            GROUP BY o.datum_dodani
            ORDER BY o.datum_dodani
        ");
        $stmt->execute(['od' => $od, 'do' => $do]);
        $dny = [];
        foreach ($stmt->fetchAll() as $r) {
            $dny[$r['den']] = [
                'pocet_objednavek' => (int) $r['pocet_objednavek'],
                'pocet_mist'       => (int) $r['pocet_mist'],
                'celkem_kusu'      => (int) $r['celkem_kusu'],
                'pocet_vyrobku'    => (int) $r['pocet_vyrobku'],
            ];
        }
        json_response([
            'rok'   => $rok,
            'mesic' => $mesic,
            'od'    => $od,
            'do'    => $do,
            'dny'   => $dny,
        ]);
    }

    if (isset($_GET['id'])) {
        $stmt = $pdo->prepare("SELECT * FROM vyrobni_listy WHERE id = :id");
        $stmt->execute(['id' => (int) $_GET['id']]);
        $vl = $stmt->fetch();
        if (!$vl) json_error('Výrobní list nenalezen', 404);

        $stmt = $pdo->prepare("
            SELECT vp.*, v.nazev AS vyrobek_nazev, v.cislo AS vyrobek_cislo,
                   v.obrazek_url, v.hmotnost_g, j.kod AS jednotka,
                   k.nazev AS kategorie, k.ikona AS kategorie_ikona
            FROM vyrobni_list_polozky vp
            JOIN vyrobky v ON v.id = vp.vyrobek_id
            LEFT JOIN jednotky j ON j.id = v.jednotka_id
            LEFT JOIN kategorie_vyrobku k ON k.id = v.kategorie_id
            WHERE vp.vyrobni_list_id = :id
            ORDER BY k.poradi, v.poradi, v.nazev
        ");
        $stmt->execute(['id' => $vl['id']]);
        $vl['polozky'] = $stmt->fetchAll();
        json_response($vl);
    }

    if (isset($_GET['datum'])) {
        $datum = $_GET['datum'];
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $datum)) json_error('Neplatné datum');

        $stmt = $pdo->prepare("
            SELECT v.id, v.nazev, v.cislo, v.hmotnost_g, v.obrazek_url,
                   k.nazev AS kategorie, k.ikona AS kategorie_ikona,
                   SUM(p.mnozstvi) AS celkem,
                   j.kod AS jednotka
            FROM objednavky_polozky p
            JOIN objednavky o ON o.id = p.objednavka_id
            JOIN vyrobky v ON v.id = p.vyrobek_id
            LEFT JOIN kategorie_vyrobku k ON k.id = v.kategorie_id
            LEFT JOIN jednotky j ON j.id = v.jednotka_id
            WHERE o.datum_dodani = :datum
              AND o.stav NOT IN ('zrusena')
            GROUP BY v.id, v.nazev, v.cislo, v.hmotnost_g, v.obrazek_url, k.nazev, k.ikona, k.poradi, v.poradi, j.kod
            ORDER BY k.poradi, v.poradi, v.nazev
        ");
        $stmt->execute(['datum' => $datum]);
        $souhrn = $stmt->fetchAll();

        $stmt = $pdo->prepare("
            SELECT od.id AS odberatel_id, od.nazev AS odberatel,
                   md.id AS misto_id, md.nazev AS misto,
                   md.ulice, md.mesto, md.psc,
                   md.kontaktni_osoba AS misto_kontakt,
                   md.telefon AS misto_tel, md.cas_dodani, md.pokyny_pro_ridice,
                   o.id AS objednavka_id, o.cislo AS objednavka_cislo, o.poznamka,
                   v.id AS vyrobek_id, v.nazev AS vyrobek, p.mnozstvi
            FROM objednavky o
            JOIN odberatele od ON od.id = o.odberatel_id
            LEFT JOIN mista_dodani md ON md.id = o.misto_dodani_id
            JOIN objednavky_polozky p ON p.objednavka_id = o.id
            JOIN vyrobky v ON v.id = p.vyrobek_id
            WHERE o.datum_dodani = :datum AND o.stav NOT IN ('zrusena')
            ORDER BY od.nazev, md.nazev, o.cislo, v.nazev
        ");
        $stmt->execute(['datum' => $datum]);
        $rozpis = $stmt->fetchAll();

        $po_pobockach = [];
        foreach ($rozpis as $r) {
            $key = ($r['misto_id'] ?? 'no') . '|' . $r['odberatel_id'];
            if (!isset($po_pobockach[$key])) {
                $po_pobockach[$key] = [
                    'odberatel' => $r['odberatel'],
                    'misto' => $r['misto'] ?? '—',
                    'adresa' => trim(($r['ulice'] ?? '') . ', ' . ($r['mesto'] ?? '') . ' ' . ($r['psc'] ?? ''), ' ,'),
                    'kontakt' => $r['misto_kontakt'],
                    'telefon' => $r['misto_tel'],
                    'cas_dodani' => $r['cas_dodani'],
                    'pokyny' => $r['pokyny_pro_ridice'],
                    'objednavka_cislo' => $r['objednavka_cislo'],
                    'poznamka' => $r['poznamka'],
                    'polozky' => [],
                ];
            }
            $po_pobockach[$key]['polozky'][] = [
                'vyrobek' => $r['vyrobek'],
                'mnozstvi' => $r['mnozstvi'],
            ];
        }

        json_response([
            'datum' => $datum,
            'souhrn' => $souhrn,
            'po_pobockach' => array_values($po_pobockach),
        ]);
    }

    $listy = $pdo->query("
        SELECT vl.*, COUNT(vp.id) AS pocet_polozek,
               COALESCE(SUM(vp.mnozstvi), 0) AS celkem_ks
        FROM vyrobni_listy vl
        LEFT JOIN vyrobni_list_polozky vp ON vp.vyrobni_list_id = vl.id
        GROUP BY vl.id
        ORDER BY vl.datum_dodani DESC, vl.id DESC
        LIMIT 50
    ")->fetchAll();
    json_response($listy);
}

// 📤 POST ?action=odepsat_suroviny — automaticky odečte všechny suroviny dle plánu na datum
//   { datum, poznamka?, force?: bool } → vrátí počet odepsaných surovin + celkem hodnotu
// 🆕 v2.9.286 — Pre-check dostatku + FOR UPDATE lock + dual-write do v2
// Pokud chybí stav → 409 s deficit_seznam. Pokud force=true, povolí odpis do mínusu (warn).
if ($method === 'POST' && ($_GET['action'] ?? '') === 'odepsat_suroviny') {
    $d = json_input();
    $datum = $d['datum'] ?? '';
    $force = !empty($d['force']);
    if (!$datum || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $datum)) json_error('Chybí/neplatný datum');
    $pozn = trim($d['poznamka'] ?? '') ?: ('Výroba ' . $datum);

    // Sečti spotřebu (stejně jako v ?action=spotreba)
    $stmt = $pdo->prepare("
        SELECT s.id AS surovina_id, s.nazev, s.jednotka, s.stock_aktualni,
               SUM(op.mnozstvi * vs.mnozstvi) AS potreba
        FROM objednavky o
        JOIN objednavky_polozky op ON op.objednavka_id = o.id
        JOIN vyrobek_suroviny vs   ON vs.vyrobek_id = op.vyrobek_id
        JOIN suroviny s            ON s.id = vs.surovina_id
        WHERE o.datum_dodani = :datum
          AND o.stav NOT IN ('zrusena')
        GROUP BY s.id, s.nazev, s.jednotka, s.stock_aktualni
    ");
    $stmt->execute(['datum' => $datum]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    if (empty($rows)) json_error('Žádné suroviny k odepsání — neexistují objednávky na tento datum nebo výrobky bez receptur', 404);

    $kdo = $admin['email'] ?? $admin['jmeno'] ?? 'system';
    $odepsano = 0;
    $celkemMnozstvi = 0.0;

    try {
        $pdo->beginTransaction();

        // 🆕 PHASE 1: Lock + pre-validace dostatku (FOR UPDATE)
        // Atomic — jiná transakce nemůže snížit stock dokud necommitneme
        $surIds = array_filter(array_map(fn($r) => (int) $r['surovina_id'], $rows));
        if (empty($surIds)) { $pdo->rollBack(); json_error('Žádné valid surovina IDs', 400); }
        $placeholders = implode(',', array_fill(0, count($surIds), '?'));
        $lockStmt = $pdo->prepare("SELECT id, stock_aktualni FROM suroviny WHERE id IN ($placeholders) FOR UPDATE");
        $lockStmt->execute($surIds);
        $aktualniStavy = [];
        foreach ($lockStmt->fetchAll(PDO::FETCH_ASSOC) as $sr) {
            $aktualniStavy[(int) $sr['id']] = (float) $sr['stock_aktualni'];
        }

        // Pre-check: kterým chybí
        $deficity = [];
        foreach ($rows as $r) {
            $sid = (int) $r['surovina_id'];
            $potreba = (float) $r['potreba'];
            if ($potreba <= 0) continue;
            $pred = $aktualniStavy[$sid] ?? 0;
            if ($pred < $potreba) {
                $deficity[] = [
                    'nazev'   => $r['nazev'],
                    'potreba' => $potreba,
                    'na_skladu' => $pred,
                    'chybi'   => round($potreba - $pred, 3),
                    'jednotka' => $r['jednotka'],
                ];
            }
        }
        if (!empty($deficity) && !$force) {
            $pdo->rollBack();
            $names = array_slice(array_map(fn($d) => $d['nazev'], $deficity), 0, 5);
            json_response([
                'error'    => 'Nedostatek surovin na skladu (' . count($deficity) . ')',
                'deficity' => $deficity,
                'tip'      => 'Doplň zásoby na skladu, nebo opakuj s force=true (povolí odpis do mínusu — POZOR audit ztratí přesnost).',
                'priklady' => implode(', ', $names),
            ], 409);
        }

        // 🆕 PHASE 2: Skutečný odpis (legacy + v2)
        $updStock = $pdo->prepare("UPDATE suroviny SET stock_aktualni = :s WHERE id = :id");
        $insPohyb = $pdo->prepare("
            INSERT INTO sklad_pohyby (surovina_id, typ, mnozstvi, jednotka, stock_pred, stock_po, poznamka, kdo)
            VALUES (:sid, 'vydej', :mn, :jed, :pred, :po, :pz, :kdo)
        ");
        // 🆕 v2.9.286 — dual-write do v2 (multi-warehouse audit) — najdi default SK01
        $sk01 = (int) $pdo->query("SELECT id FROM sklady WHERE kod = 'SK01' LIMIT 1")->fetchColumn();
        $insPohybV2 = $sk01 ? $pdo->prepare("
            INSERT INTO sklad_pohyby_v2 (sklad_id, item_typ, item_id, typ, mnozstvi, stav_pred, stav_po, poznamka, kdo)
            VALUES (:sid, 'surovina', :iid, 'vydej', :mn, :sp, :sP, :pz, :kdo)
        ") : null;

        foreach ($rows as $r) {
            $sid = (int) $r['surovina_id'];
            $pred = $aktualniStavy[$sid] ?? 0;
            $potreba = (float) $r['potreba'];
            if ($potreba <= 0) continue;
            // 🆕 force=true → může do mínusu (audit ztratí přesnost ale uživatel věděl)
            $po = $force ? ($pred - $potreba) : max(0, $pred - $potreba);
            $updStock->execute(['s' => $po, 'id' => $sid]);
            $insPohyb->execute([
                'sid'  => $sid,
                'mn'   => $potreba,
                'jed'  => $r['jednotka'],
                'pred' => $pred,
                'po'   => $po,
                'pz'   => $pozn . ($force && $pred < $potreba ? ' [FORCE — záporný stav]' : ''),
                'kdo'  => $kdo,
            ]);
            // Dual-write v2 (best-effort, neporušíme legacy pokud selže)
            if ($insPohybV2) {
                try {
                    $insPohybV2->execute([
                        'sid' => $sk01, 'iid' => $sid,
                        'mn'  => -$potreba, // záporné = vydej
                        'sp'  => $pred, 'sP' => $po,
                        'pz'  => $pozn, 'kdo' => $kdo,
                    ]);
                    // Update sklad_polozky stav (synchronizace s legacy)
                    try {
                        $pdo->prepare("
                            INSERT INTO sklad_polozky (sklad_id, item_typ, item_id, stav)
                            VALUES (:s, 'surovina', :i, :st)
                            ON DUPLICATE KEY UPDATE stav = :st2
                        ")->execute(['s' => $sk01, 'i' => $sid, 'st' => $po, 'st2' => $po]);
                    } catch (Throwable $e) { /* sklad_polozky optional */ }
                } catch (Throwable $e) { /* v2 optional, soft-fail */ }
            }
            $odepsano++;
            $celkemMnozstvi += $potreba;
        }
        $pdo->commit();
        json_response([
            'ok' => true, 'odepsano' => $odepsano, 'datum' => $datum,
            'celkem_mnozstvi' => $celkemMnozstvi,
            'force_used' => $force, 'deficity_byly' => count($deficity),
        ]);
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        json_error('Chyba odpisu: ' . $e->getMessage(), 500);
    }
}

if ($method === 'POST') {
    $d = json_input();
    if (empty($d['datum_dodani']) || empty($d['polozky'])) json_error('Chybí povinné údaje');

    $pdo->beginTransaction();
    try {
        $cislo = dalsi_cislo($pdo, 'VL', (int) date('Y', strtotime($d['datum_dodani'])));

        // FIX #17: ukládáme created_by
        $stmt = $pdo->prepare("
            INSERT INTO vyrobni_listy (cislo, datum_vyroby, datum_dodani, stav, poznamka, created_by)
            VALUES (:c, :v, :d, :s, :p, :u)
        ");
        $stmt->execute([
            'c' => $cislo,
            'v' => $d['datum_vyroby'] ?? date('Y-m-d'),
            'd' => $d['datum_dodani'],
            's' => $d['stav'] ?? 'koncept',
            'p' => $d['poznamka'] ?? null,
            'u' => $admin['id'],
        ]);
        $vl_id = (int) $pdo->lastInsertId();

        $stmt = $pdo->prepare("
            INSERT INTO vyrobni_list_polozky (vyrobni_list_id, vyrobek_id, mnozstvi, poznamka)
            VALUES (:vl, :v, :m, :p)
        ");
        foreach ($d['polozky'] as $p) {
            if (empty($p['vyrobek_id']) || empty($p['mnozstvi'])) continue;
            $stmt->execute([
                'vl' => $vl_id,
                'v' => (int) $p['vyrobek_id'],
                'm' => (float) $p['mnozstvi'],
                'p' => $p['poznamka'] ?? null,
            ]);
        }

        $pdo->commit();
        json_response(['id' => $vl_id, 'cislo' => $cislo], 201);
    } catch (Exception $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        error_log('admin_vyroba POST: ' . $e->getMessage());
        json_error('Nepodařilo se vytvořit výrobní list', 500);
    }
}

if ($method === 'PUT') {
    $d = json_input();
    if (empty($d['id'])) json_error('Chybí ID');

    $platne = ['koncept','potvrzeno','ve_vyrobe','hotovo'];

    $pdo->beginTransaction();
    try {
        if (isset($d['stav'])) {
            if (!in_array($d['stav'], $platne, true)) throw new Exception('Neplatný stav');
            $pdo->prepare("UPDATE vyrobni_listy SET stav = :s WHERE id = :id")
                ->execute(['s' => $d['stav'], 'id' => (int) $d['id']]);
        }

        if (!empty($d['vyrobeno']) && is_array($d['vyrobeno'])) {
            $stmt = $pdo->prepare("
                UPDATE vyrobni_list_polozky SET vyrobeno = :m
                WHERE id = :id AND vyrobni_list_id = :vl
            ");
            foreach ($d['vyrobeno'] as $v) {
                $stmt->execute([
                    'm' => (float) $v['mnozstvi'],
                    'id' => (int) $v['id'],
                    'vl' => (int) $d['id'],
                ]);
            }
        }

        $pdo->commit();
        json_response(['ok' => true]);
    } catch (Exception $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        json_error($e->getMessage(), 400);
    }
}

if ($method === 'DELETE') {
    require_super_admin(); // jen super admin smí mazat výrobní listy
    $id = (int) ($_GET['id'] ?? 0);
    if (!$id) json_error('Chybí ID');
    $pdo->prepare("DELETE FROM vyrobni_listy WHERE id = :id")->execute(['id' => $id]);
    json_response(['ok' => true]);
}

json_error('Neplatná metoda', 405);
