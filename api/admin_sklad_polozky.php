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
