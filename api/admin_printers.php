<?php
/**
 * 🆕 v3.0.5 — Printer CRUD + test print + kategorie mapping
 *
 * GET  ?action=list                       → seznam tiskáren
 * GET  ?action=settings                   → POS print settings
 * POST ?action=save                       → create/update tiskárna
 * POST ?action=delete   { id }            → smazat
 * POST ?action=test     { id }            → testovací tisk
 * POST ?action=map      { kategorie_id, printer_id|null } → namapuj kategorii na tiskárnu
 * POST ?action=settings { key, value }    → ulož settings (pos_print_receipt_mode, pos_print_kitchen_mode, printer_dummy_mode)
 * GET  ?action=dummy_files                → seznam dummy print souborů (testovací mode)
 * GET  ?action=dummy_file&name=X          → obsah dummy souboru (preview)
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/_admin_auth.php';
require_once __DIR__ . '/_printer_lib.php';

cors_headers();
require_admin();

$method = $_SERVER['REQUEST_METHOD'];
$pdo = db();
$action = $_GET['action'] ?? '';

try {
    if ($method === 'GET' && $action === 'list') {
        // Seznam tiskáren + kuchyňské stanice (pro mapování stanice → tiskárna)
        printer_ensure_schema($pdo);
        $rows = $pdo->query("SELECT p.* FROM restaurant_printers p ORDER BY p.typ, p.nazev")->fetchAll();
        // 🆕 v3.0.200 — stanice (nahradilo mapování kategorií). Defenzivně — tabulka nemusí existovat.
        $stanice = [];
        try {
            $stanice = $pdo->query("
                SELECT id, nazev, ikona, barva, printer_id
                FROM kitchen_stations
                WHERE aktivni = 1
                ORDER BY poradi, id
            ")->fetchAll();
        } catch (Throwable $e) {}
        // Počet stanic přiřazených každé tiskárně (v PHP, bez padajícího subSELECTu)
        $cnt = [];
        foreach ($stanice as $s) { if ($s['printer_id']) { $k = (int)$s['printer_id']; $cnt[$k] = ($cnt[$k] ?? 0) + 1; } }
        foreach ($rows as &$r) { $r['pocet_stanic'] = $cnt[(int)$r['id']] ?? 0; }
        unset($r);
        json_response(['printers' => $rows, 'stanice' => $stanice]);
    }

    if ($method === 'GET' && $action === 'settings') {
        json_response([
            'pos_print_receipt_mode' => setting_get($pdo, 'pos_print_receipt_mode', 'ask'),
            'pos_print_kitchen_mode' => setting_get($pdo, 'pos_print_kitchen_mode', 'auto'),
            'printer_dummy_mode'     => setting_get($pdo, 'printer_dummy_mode', '1'),
        ]);
    }

    if ($method === 'POST' && $action === 'settings') {
        $d = json_decode(file_get_contents('php://input'), true) ?? [];
        $allowed = [
            'pos_print_receipt_mode' => ['ask', 'always', 'never'],
            'pos_print_kitchen_mode' => ['auto', 'manual', 'off'],
            'printer_dummy_mode'     => ['0', '1'],
        ];
        foreach ($allowed as $k => $values) {
            if (!array_key_exists($k, $d)) continue;
            $v = (string)$d[$k];
            if (!in_array($v, $values, true)) {
                json_error("Neplatná hodnota pro $k: $v (povolené: " . implode(',', $values) . ')', 400);
            }
            setting_set($pdo, $k, $v, 'POS printer setting');
        }
        json_response(['ok' => true]);
    }

    if ($method === 'POST' && $action === 'save') {
        $d = json_decode(file_get_contents('php://input'), true) ?? [];
        $id = printer_save($pdo, $d);
        json_response(['ok' => true, 'id' => $id]);
    }

    if ($method === 'POST' && $action === 'delete') {
        $d = json_decode(file_get_contents('php://input'), true) ?? [];
        $id = (int)($d['id'] ?? 0);
        if (!$id) json_error('Chybí id', 400);
        printer_delete($pdo, $id);
        json_response(['ok' => true]);
    }

    if ($method === 'POST' && $action === 'test') {
        $d = json_decode(file_get_contents('php://input'), true) ?? [];
        $id = (int)($d['id'] ?? 0);
        if (!$id) json_error('Chybí id', 400);
        $res = printer_test_print($pdo, $id);
        json_response($res);
    }

    if ($method === 'POST' && $action === 'map') {
        $d = json_decode(file_get_contents('php://input'), true) ?? [];
        $kid = (int)($d['kategorie_id'] ?? 0);
        $pid = isset($d['printer_id']) ? (int)$d['printer_id'] : null;
        if (!$kid) json_error('Chybí kategorie_id', 400);
        if ($pid !== null && $pid <= 0) $pid = null;

        // Validate
        if ($pid !== null) {
            $exists = $pdo->prepare("SELECT 1 FROM restaurant_printers WHERE id = :id");
            $exists->execute(['id' => $pid]);
            if (!$exists->fetchColumn()) json_error('Tiskárna neexistuje', 404);
        }
        printer_ensure_schema($pdo);
        $pdo->prepare("UPDATE kategorie_vyrobku SET printer_id = :p WHERE id = :k")
            ->execute(['p' => $pid, 'k' => $kid]);
        json_response(['ok' => true]);
    }

    // 🆕 v3.0.200 — párování tiskárny na KUCHYŇSKOU STANICI (nahradilo kategorie→tiskárna)
    if ($method === 'POST' && $action === 'map_station') {
        $d = json_decode(file_get_contents('php://input'), true) ?? [];
        $sid = (int)($d['station_id'] ?? 0);
        $pid = isset($d['printer_id']) ? (int)$d['printer_id'] : null;
        if (!$sid) json_error('Chybí station_id', 400);
        if ($pid !== null && $pid <= 0) $pid = null;
        if ($pid !== null) {
            $exists = $pdo->prepare("SELECT 1 FROM restaurant_printers WHERE id = :id");
            $exists->execute(['id' => $pid]);
            if (!$exists->fetchColumn()) json_error('Tiskárna neexistuje', 404);
        }
        printer_ensure_schema($pdo); // zajistí kitchen_stations.printer_id
        $pdo->prepare("UPDATE kitchen_stations SET printer_id = :p WHERE id = :s")
            ->execute(['p' => $pid, 's' => $sid]);
        json_response(['ok' => true]);
    }

    if ($method === 'POST' && $action === 'reset_chyba') {
        $d = json_decode(file_get_contents('php://input'), true) ?? [];
        $id = (int)($d['id'] ?? 0);
        if (!$id) json_error('Chybí id', 400);
        $pdo->prepare("UPDATE restaurant_printers SET posledni_chyba = NULL WHERE id = :id")
            ->execute(['id' => $id]);
        json_response(['ok' => true]);
    }

    // Dummy mode — list testovacích souborů
    if ($method === 'GET' && $action === 'dummy_files') {
        $dir = sys_get_temp_dir() . '/appek_printer_dummy';
        if (!is_dir($dir)) json_response(['files' => []]);
        $files = glob($dir . '/*.txt') ?: [];
        rsort($files);
        $files = array_slice($files, 0, 50);
        $out = [];
        foreach ($files as $f) {
            $out[] = [
                'name' => basename($f),
                'size' => filesize($f),
                'mtime' => date('Y-m-d H:i:s', filemtime($f)),
            ];
        }
        json_response(['files' => $out, 'dir' => $dir]);
    }

    if ($method === 'GET' && $action === 'dummy_file') {
        $name = basename((string)($_GET['name'] ?? ''));
        if (!preg_match('/^[a-zA-Z0-9_.\-]+\.txt$/', $name)) json_error('Neplatný název', 400);
        $f = sys_get_temp_dir() . '/appek_printer_dummy/' . $name;
        if (!file_exists($f)) json_error('Soubor nenalezen', 404);
        header('Content-Type: text/plain; charset=UTF-8');
        readfile($f);
        exit;
    }

    // 🆕 v3.0.133 — Tisk libovolného dokladu (obj/dl/fa) na zvolenou tiskárnu
    if ($method === 'POST' && $action === 'print_doc') {
        $d = json_decode(file_get_contents('php://input'), true) ?? [];
        $docType   = (string)($d['doc_type'] ?? '');
        $docId     = (int)($d['doc_id'] ?? 0);
        $printerId = (int)($d['printer_id'] ?? 0);
        $mode      = (string)($d['mode'] ?? 'receipt');
        if (!in_array($docType, ['obj', 'dl', 'fa'], true)) json_error('Neplatný doc_type', 400);
        if (!$docId)     json_error('Chybí doc_id', 400);
        if (!$printerId) json_error('Chybí printer_id', 400);
        if (!in_array($mode, ['receipt', 'bon'], true)) $mode = 'receipt';
        $res = printer_print_doc($pdo, $docType, $docId, $printerId, $mode);
        json_response($res);
    }

    json_error('Neznámá akce: ' . $action, 400);

} catch (Throwable $e) {
    json_error('Chyba: ' . $e->getMessage(), 500);
}
