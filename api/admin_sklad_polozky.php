<?php
/**
 * 📦 ADMIN SKLAD POLOZKY — pivot tabulka stav per (sklad × surovina/výrobek).
 * PR2 z multi-warehouse spec (2026-05-23).
 *
 * GET    ?sklad_id=N             → položky v jednom skladu (s názvy z suroviny/vyrobky)
 * GET    ?item_typ=&item_id=     → kde všude položka je (suma stavů + per sklad)
 * POST                            → přiřadit položku do skladu (stav 0)
 *                                   Body: { sklad_id, item_typ, item_id, min_stav?, cil_stav? }
 * PUT    ?id=N                    → změnit min_stav / cil_stav (stav se mění přes pohyby)
 * DELETE ?id=N                    → odebrat ze skladu (jen pokud stav=0)
 *
 * Stav (count) se NEMĚNÍ přes tento endpoint — pro to bude PR3 (pohyby).
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/_admin_auth.php';
require_once __DIR__ . '/_schema_lib.php';
cors_headers();
require_admin();

$pdo = db();
ensure_sklady_schema($pdo);
ensure_sklad_polozky_schema($pdo);

$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? '';

// ─── 🆕 v2.9.272 — GET ?action=compare → porovnání skladů (pivot položka × sklad) ──
if ($method === 'GET' && $action === 'compare') {
    $itemTyp = trim((string) ($_GET['item_typ'] ?? ''));
    $jenAktivni = !empty($_GET['jen_aktivni']);
    $jenSNeNulovymStavem = !empty($_GET['jen_se_stavem']);

    try {
        // 1. Načti všechny aktivní sklady (pořadí: aktivní DESC, poradi)
        $sklady = $pdo->query("
            SELECT id, kod, nazev, typ, aktivni
            FROM sklady
            " . ($jenAktivni ? "WHERE aktivni = 1" : "") . "
            ORDER BY aktivni DESC, poradi, id
        ")->fetchAll();

        if (empty($sklady)) {
            json_response(['sklady' => [], 'polozky' => [], 'sums' => ['per_sklad' => [], 'celkem_polozek' => 0]]);
        }

        $skladIds = array_column($sklady, 'id');

        // 2. Načti všechny položky napříč sklady (s names + jednotka)
        $whereTyp = $itemTyp && in_array($itemTyp, ['surovina', 'vyrobek'], true)
            ? " AND sp.item_typ = " . $pdo->quote($itemTyp) : "";
        $whereStav = $jenSNeNulovymStavem ? " AND sp.stav > 0" : "";

        $rows = $pdo->query("
            SELECT sp.sklad_id, sp.item_typ, sp.item_id, sp.stav, sp.min_stav, sp.cil_stav,
                   CASE sp.item_typ
                       WHEN 'surovina' THEN (SELECT nazev FROM suroviny WHERE id = sp.item_id LIMIT 1)
                       WHEN 'vyrobek'  THEN (SELECT nazev FROM vyrobky  WHERE id = sp.item_id LIMIT 1)
                   END AS nazev,
                   CASE sp.item_typ
                       WHEN 'surovina' THEN (SELECT jednotka FROM suroviny WHERE id = sp.item_id LIMIT 1)
                       WHEN 'vyrobek'  THEN 'ks'
                   END AS jednotka,
                   CASE sp.item_typ
                       WHEN 'surovina' THEN (SELECT cena_baleni FROM suroviny WHERE id = sp.item_id LIMIT 1)
                       ELSE NULL END AS cena_baleni,
                   CASE sp.item_typ
                       WHEN 'surovina' THEN (SELECT obsah_baleni FROM suroviny WHERE id = sp.item_id LIMIT 1)
                       ELSE NULL END AS obsah_baleni,
                   CASE sp.item_typ
                       WHEN 'vyrobek'  THEN (SELECT cislo FROM vyrobky WHERE id = sp.item_id LIMIT 1)
                       ELSE NULL END AS cislo
            FROM sklad_polozky sp
            WHERE 1=1 {$whereTyp} {$whereStav}
              AND sp.sklad_id IN (" . implode(',', array_map('intval', $skladIds)) . ")
        ")->fetchAll();

        // 3. Pivot: pro každou unique (item_typ, item_id) udělej řádek s stav per sklad
        $itemMap = []; // key = item_typ:item_id → row
        foreach ($rows as $r) {
            $key = $r['item_typ'] . ':' . $r['item_id'];
            if (!isset($itemMap[$key])) {
                $itemMap[$key] = [
                    'item_typ'     => $r['item_typ'],
                    'item_id'      => (int) $r['item_id'],
                    'nazev'        => $r['nazev'] ?? '(neznámý)',
                    'jednotka'     => $r['jednotka'] ?? '',
                    'cislo'        => $r['cislo'] ?? null,
                    'cena_baleni'  => $r['cena_baleni'] !== null ? (float) $r['cena_baleni'] : null,
                    'obsah_baleni' => $r['obsah_baleni'] !== null ? (float) $r['obsah_baleni'] : null,
                    'stavy'        => array_fill_keys($skladIds, 0.0), // sklad_id → stav
                    'celkem'       => 0.0,
                    'min_stav_max' => null, // max min_stav napříč sklady (pro warning)
                ];
            }
            $stav = (float) $r['stav'];
            $itemMap[$key]['stavy'][(int) $r['sklad_id']] = $stav;
            $itemMap[$key]['celkem'] += $stav;
            if ($r['min_stav'] !== null) {
                $m = (float) $r['min_stav'];
                if ($itemMap[$key]['min_stav_max'] === null || $m > $itemMap[$key]['min_stav_max']) {
                    $itemMap[$key]['min_stav_max'] = $m;
                }
            }
        }

        // 4. Per-sklad součty + hodnoty (suma stavů × cena_za_jed)
        $perSklad = array_fill_keys($skladIds, ['pocet_polozek' => 0, 'celkem_stav' => 0.0, 'hodnota_kc' => 0.0]);
        foreach ($itemMap as $item) {
            $cenaJed = ($item['cena_baleni'] && $item['obsah_baleni'] && $item['obsah_baleni'] > 0)
                ? $item['cena_baleni'] / $item['obsah_baleni'] : 0;
            foreach ($item['stavy'] as $sid => $stav) {
                if ($stav > 0) {
                    $perSklad[$sid]['pocet_polozek']++;
                    $perSklad[$sid]['celkem_stav'] += $stav;
                    $perSklad[$sid]['hodnota_kc'] += $stav * $cenaJed;
                }
            }
        }

        // 5. Setřiď položky abecedně podle názvu
        $items = array_values($itemMap);
        usort($items, fn($a, $b) => strcmp($a['nazev'], $b['nazev']));

        // 6. Hodnota položky celkem (across all skladu)
        foreach ($items as &$it) {
            $cenaJed = ($it['cena_baleni'] && $it['obsah_baleni'] && $it['obsah_baleni'] > 0)
                ? $it['cena_baleni'] / $it['obsah_baleni'] : 0;
            $it['hodnota_kc'] = round($it['celkem'] * $cenaJed, 2);
            $it['pod_minimem'] = $it['min_stav_max'] !== null && $it['celkem'] <= $it['min_stav_max'];
        }
        unset($it);

        json_response([
            'sklady'   => $sklady,
            'polozky'  => $items,
            'sums'     => [
                'per_sklad'      => $perSklad,
                'celkem_polozek' => count($items),
                'hodnota_celkem' => round(array_sum(array_column($perSklad, 'hodnota_kc')), 2),
            ],
            'meta' => [
                'item_typ_filter'      => $itemTyp ?: 'vse',
                'jen_aktivni'          => $jenAktivni,
                'jen_se_stavem'        => $jenSNeNulovymStavem,
            ],
        ]);
    } catch (Throwable $e) {
        error_log('admin_sklad_polozky compare: ' . $e->getMessage());
        json_error('Chyba porovnání: ' . $e->getMessage(), 500);
    }
}

// ─── GET — list per sklad / per item ────────────────────────────
if ($method === 'GET') {
    $skladId = (int) ($_GET['sklad_id'] ?? 0);
    $itemTyp = trim((string) ($_GET['item_typ'] ?? ''));
    $itemId  = (int) ($_GET['item_id'] ?? 0);

    if ($skladId > 0) {
        // Položky v jednom skladu, JOIN s suroviny / vyrobky pro názvy
        $rows = $pdo->prepare("
            SELECT sp.*,
                   CASE sp.item_typ
                       WHEN 'surovina' THEN (SELECT nazev FROM suroviny WHERE id = sp.item_id LIMIT 1)
                       WHEN 'vyrobek'  THEN (SELECT nazev FROM vyrobky  WHERE id = sp.item_id LIMIT 1)
                   END AS nazev,
                   CASE sp.item_typ
                       WHEN 'surovina' THEN (SELECT jednotka FROM suroviny WHERE id = sp.item_id LIMIT 1)
                       WHEN 'vyrobek'  THEN 'ks'
                   END AS jednotka,
                   CASE sp.item_typ
                       WHEN 'vyrobek'  THEN (SELECT cislo FROM vyrobky WHERE id = sp.item_id LIMIT 1)
                       ELSE NULL
                   END AS cislo
            FROM sklad_polozky sp
            WHERE sp.sklad_id = :sid
            ORDER BY sp.item_typ, nazev
        ");
        $rows->execute(['sid' => $skladId]);
        json_response(['polozky' => $rows->fetchAll()]);
    }

    if ($itemTyp && $itemId > 0) {
        // Kde všude je položka — JOIN s sklady pro názvy
        $rows = $pdo->prepare("
            SELECT sp.*, s.kod AS sklad_kod, s.nazev AS sklad_nazev, s.typ AS sklad_typ, s.aktivni AS sklad_aktivni
            FROM sklad_polozky sp
            JOIN sklady s ON s.id = sp.sklad_id
            WHERE sp.item_typ = :t AND sp.item_id = :id
            ORDER BY s.aktivni DESC, s.poradi, s.id
        ");
        $rows->execute(['t' => $itemTyp, 'id' => $itemId]);
        $list = $rows->fetchAll();
        $sumStav = array_reduce($list, fn($s, $r) => $s + (float) $r['stav'], 0);
        json_response(['polozky' => $list, 'sum_stav' => $sumStav]);
    }

    // Bez filtru — vrátit všechny (s názvy)
    $all = $pdo->query("
        SELECT sp.*, s.kod AS sklad_kod, s.nazev AS sklad_nazev,
               CASE sp.item_typ
                   WHEN 'surovina' THEN (SELECT nazev FROM suroviny WHERE id = sp.item_id LIMIT 1)
                   WHEN 'vyrobek'  THEN (SELECT nazev FROM vyrobky  WHERE id = sp.item_id LIMIT 1)
               END AS nazev
        FROM sklad_polozky sp
        JOIN sklady s ON s.id = sp.sklad_id
        ORDER BY s.poradi, s.id, sp.item_typ, nazev
    ")->fetchAll();
    json_response(['polozky' => $all]);
}

// ─── POST — přiřadit položku do skladu ──────────────────────────
if ($method === 'POST') {
    $d = json_input();
    $skladId = (int) ($d['sklad_id'] ?? 0);
    $itemTyp = trim((string) ($d['item_typ'] ?? ''));
    $itemId  = (int) ($d['item_id'] ?? 0);

    if ($skladId <= 0) json_error('Chybí sklad_id');
    if (!in_array($itemTyp, ['surovina', 'vyrobek'], true)) json_error('item_typ musí být surovina nebo vyrobek');
    if ($itemId <= 0) json_error('Chybí item_id');

    // Ověř že sklad existuje
    $skladOK = (int) $pdo->prepare("SELECT id FROM sklady WHERE id = :id LIMIT 1")->execute(['id' => $skladId]);
    if (!$pdo->query("SELECT id FROM sklady WHERE id = $skladId LIMIT 1")->fetchColumn()) {
        json_error('Sklad nenalezen', 404);
    }

    // Ověř že položka existuje
    $itemTable = $itemTyp === 'surovina' ? 'suroviny' : 'vyrobky';
    if (!$pdo->query("SELECT id FROM `$itemTable` WHERE id = $itemId LIMIT 1")->fetchColumn()) {
        json_error(ucfirst($itemTyp) . ' nenalezena', 404);
    }

    $stav    = isset($d['stav']) && $d['stav'] !== '' ? (float) $d['stav'] : 0;
    $minStav = isset($d['min_stav']) && $d['min_stav'] !== '' ? (float) $d['min_stav'] : null;
    $cilStav = isset($d['cil_stav']) && $d['cil_stav'] !== '' ? (float) $d['cil_stav'] : null;

    try {
        $pdo->prepare("
            INSERT INTO sklad_polozky (sklad_id, item_typ, item_id, stav, min_stav, cil_stav)
            VALUES (:s, :t, :i, :st, :mn, :cl)
        ")->execute([
            's' => $skladId, 't' => $itemTyp, 'i' => $itemId,
            'st' => $stav, 'mn' => $minStav, 'cl' => $cilStav,
        ]);
        json_response(['ok' => true, 'id' => (int) $pdo->lastInsertId()], 201);
    } catch (PDOException $e) {
        if (strpos($e->getMessage(), 'Duplicate') !== false) {
            json_error('Položka už je v tomto skladu přiřazena', 409);
        }
        throw $e;
    }
}

// ─── PUT — update min / cíl / stav ──────────────────────────────
if ($method === 'PUT') {
    $id = (int) ($_GET['id'] ?? 0);
    if (!$id) json_error('Chybí ID');
    $d = json_input();

    $sets = [];
    $params = ['id' => $id];
    if (array_key_exists('min_stav', $d)) {
        $sets[] = 'min_stav = :mn';
        $params['mn'] = $d['min_stav'] === '' || $d['min_stav'] === null ? null : (float) $d['min_stav'];
    }
    if (array_key_exists('cil_stav', $d)) {
        $sets[] = 'cil_stav = :cl';
        $params['cl'] = $d['cil_stav'] === '' || $d['cil_stav'] === null ? null : (float) $d['cil_stav'];
    }
    // 🆕 stav lze nastavit přímo (inventura quick set) — v PR3 přidáme audit přes pohyby
    if (array_key_exists('stav', $d)) {
        $sets[] = 'stav = :st';
        $params['st'] = (float) $d['stav'];
    }
    if (empty($sets)) json_error('Nic k uložení');

    $pdo->prepare("UPDATE sklad_polozky SET " . implode(', ', $sets) . " WHERE id = :id")->execute($params);
    json_response(['ok' => true]);
}

// ─── DELETE — odebrat položku ze skladu ─────────────────────────
if ($method === 'DELETE') {
    $id = (int) ($_GET['id'] ?? 0);
    if (!$id) json_error('Chybí ID');
    $stmt = $pdo->prepare("SELECT stav FROM sklad_polozky WHERE id = :id LIMIT 1");
    $stmt->execute(['id' => $id]);
    $row = $stmt->fetch();
    if (!$row) json_error('Položka nenalezena', 404);
    if ((float) $row['stav'] > 0) {
        json_error('Nelze odebrat položku s nenulovým stavem (' . $row['stav'] . '). Nejdřív vyskladni.', 409);
    }
    $pdo->prepare("DELETE FROM sklad_polozky WHERE id = :id")->execute(['id' => $id]);
    json_response(['ok' => true]);
}

json_error('Method not allowed', 405);
