<?php
/**
 * 🏴‍☠️ ADMIN PIRATE STATUS — lehký endpoint vrací cache pirate flag.
 *
 * Volá se z admin.js POKAŽDÉ při loginu (i když heartbeat dnes neproběhl).
 * Pokud existuje api/.pirate-flag → vrátí jeho obsah pro nag banner.
 * Pokud ne → status:ok, pirate_flag:false.
 *
 * Tohle umožňuje, aby pirate banner zmizel/zůstal viset i mezi heartbeaty.
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/_authz.php';

session_secure_start(); // 🐛 v2.9.327 — bylo bare session_start (špatný session_name)
header('Content-Type: application/json; charset=UTF-8');

$ctx = aktualni_uzivatel_z_session();
if (!$ctx || ($ctx['role'] ?? '') !== 'admin') {
    http_response_code(403);
    echo json_encode(['error' => 'admin only']);
    exit;
}

$pirateFlagFile = __DIR__ . '/.pirate-flag';
$lastHbFile     = __DIR__ . '/.heartbeat-last';

$resp = [
    'pirate_flag' => false,
    'last_heartbeat' => null,
    'reason' => null,
    'message' => null,
    'license_status' => null,
];

if (file_exists($lastHbFile)) {
    $resp['last_heartbeat'] = trim(@file_get_contents($lastHbFile));
}

if (file_exists($pirateFlagFile)) {
    $raw = @file_get_contents($pirateFlagFile);
    $data = json_decode($raw, true);
    if (is_array($data)) {
        $resp['pirate_flag']    = true;
        $resp['status']         = 'pirate';
        $resp['reason']         = $data['reason'] ?? 'unknown';
        $resp['message']        = $data['message'] ?? '';
        $resp['license_status'] = $data['license_status'] ?? 'unknown';
        $resp['flagged_at']     = $data['flagged_at'] ?? null;
    }
}

if (!$resp['pirate_flag']) {
    $resp['status'] = 'ok';
}

echo json_encode($resp, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
