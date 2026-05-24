<?php
/**
 * 🆕 v2.9.310 — POS RYCHLÉ VOLBY (volná položka presets)
 *
 * Endpoints:
 *   GET  /api/admin_pos_presets.php                 — vrátí array presetů (s defaulty když prázdno)
 *   POST /api/admin_pos_presets.php?action=save     — uloží array { presets: [...] }
 *   POST /api/admin_pos_presets.php?action=reset    — resetuje na továrenské defaulty
 *
 * Storage: nastaveni.pos_custom_presets (JSON array)
 * Item shape: { ikona, nazev, cena, dph } — vše string/number
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/_admin_auth.php';

cors_headers();
require_admin();
header('Content-Type: application/json; charset=UTF-8');

$pdo = db();
$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? '';

// Tovární defaulty (shoda s původními hardcoded v pos.js)
function default_presets(): array {
    return [
        ['ikona' => '🍷', 'nazev' => 'Korkovné',         'cena' => 30,  'dph' => 21],
        ['ikona' => '📦', 'nazev' => 'Obal / krabice',   'cena' => 5,   'dph' => 21],
        ['ikona' => '🏷️', 'nazev' => 'Sleva (volná)',    'cena' => -50, 'dph' => 0],
        ['ikona' => '⚙️', 'nazev' => 'Servis / poplatek','cena' => 10,  'dph' => 21],
    ];
}

// Defenzivní sanitizace + validace
function sanitize_presets(array $input): array {
    $out = [];
    foreach ($input as $p) {
        if (!is_array($p)) continue;
        $nazev = trim((string)($p['nazev'] ?? ''));
        if ($nazev === '') continue;
        if (mb_strlen($nazev) > 60) $nazev = mb_substr($nazev, 0, 60);
        $ikona = trim((string)($p['ikona'] ?? ''));
        if (mb_strlen($ikona) > 4) $ikona = mb_substr($ikona, 0, 4);
        $cena = isset($p['cena']) ? (float)$p['cena'] : 0.0;
        $dph  = isset($p['dph'])  ? (float)$p['dph']  : 21.0;
        if ($dph < 0) $dph = 0;
        if ($dph > 100) $dph = 100;
        $out[] = [
            'ikona' => $ikona ?: '🛒',
            'nazev' => $nazev,
            'cena'  => round($cena, 2),
            'dph'   => round($dph, 2),
        ];
        if (count($out) >= 24) break; // hard cap — UI by tolik buttonů nepojme
    }
    return $out;
}

if ($method === 'GET') {
    try {
        $raw = $pdo->query("SELECT hodnota FROM nastaveni WHERE klic = 'pos_custom_presets' LIMIT 1")->fetchColumn();
        $presets = $raw ? (json_decode($raw, true) ?: null) : null;
        if (!is_array($presets) || empty($presets)) {
            $presets = default_presets();
        }
        json_response(['ok' => true, 'presets' => $presets]);
    } catch (Throwable $e) {
        // Tabulka nastaveni může chybět na bare instalaci — vrať defaulty
        json_response(['ok' => true, 'presets' => default_presets(), 'note' => 'fallback_defaults: ' . $e->getMessage()]);
    }
}

if ($method === 'POST' && $action === 'save') {
    $data = json_decode(file_get_contents('php://input'), true) ?? [];
    $input = $data['presets'] ?? [];
    if (!is_array($input)) json_error('Pole "presets" musí být array', 400);
    $clean = sanitize_presets($input);
    try {
        $json = json_encode($clean, JSON_UNESCAPED_UNICODE);
        $pdo->prepare("
            INSERT INTO nastaveni (klic, hodnota) VALUES ('pos_custom_presets', :v)
            ON DUPLICATE KEY UPDATE hodnota = :v2
        ")->execute(['v' => $json, 'v2' => $json]);
        json_response(['ok' => true, 'saved' => count($clean), 'presets' => $clean]);
    } catch (Throwable $e) {
        json_error('Uložení selhalo: ' . $e->getMessage(), 500);
    }
}

if ($method === 'POST' && $action === 'reset') {
    try {
        $pdo->prepare("DELETE FROM nastaveni WHERE klic = 'pos_custom_presets'")->execute();
        json_response(['ok' => true, 'reset' => true, 'presets' => default_presets()]);
    } catch (Throwable $e) {
        json_error('Reset selhal: ' . $e->getMessage(), 500);
    }
}

json_error('Neznámá akce (GET | POST save | POST reset)', 404);
