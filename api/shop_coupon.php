<?php
/**
 * 🎟️ SHOP COUPON — veřejné ověření slevového kuponu z appek.cz/checkout.
 *
 * GET/POST /api/shop_coupon.php?code=XXX&subtotal=12345
 * Vrátí: { ok, valid, kod, typ, hodnota, popis, discount_kc } nebo { ok, valid:false, reason }
 *
 * Jen ČTE (ověří) — použití se započítá až autoritativně v shop_buy.php při objednávce.
 */

header('Content-Type: application/json; charset=UTF-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') exit;

// ── Vendor DB config (stejný pattern jako shop_buy.php) ──────────
$vendorConfigPaths = [
    __DIR__ . '/vendor_db_config.local.php',
    realpath(__DIR__ . '/..') . '/vendor/config.local.php',
];
$loaded = false;
foreach ($vendorConfigPaths as $cfg) {
    if ($cfg && file_exists($cfg)) { require_once $cfg; $loaded = true; break; }
}
if (!$loaded || !defined('VENDOR_DB_HOST')) {
    http_response_code(503);
    echo json_encode(['ok' => false, 'error' => 'vendor_db_not_configured']);
    exit;
}

$code     = strtoupper(trim($_REQUEST['code'] ?? ''));
$subtotal = (float) ($_REQUEST['subtotal'] ?? 0);
if ($code === '') {
    echo json_encode(['ok' => true, 'valid' => false, 'reason' => 'empty']);
    exit;
}

try {
    $dsn = sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
        VENDOR_DB_HOST, defined('VENDOR_DB_PORT') ? VENDOR_DB_PORT : 3306, VENDOR_DB_NAME);
    $pdo = new PDO($dsn, VENDOR_DB_USER, VENDOR_DB_PASS, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);

    require_once __DIR__ . '/_shop_coupon_lib.php';
    shop_coupon_ensure_table($pdo);

    [$valid, $reason, $c, $disc] = shop_coupon_eval($pdo, $code, $subtotal);
    if (!$valid) {
        echo json_encode(['ok' => true, 'valid' => false, 'reason' => $reason]);
        exit;
    }
    echo json_encode([
        'ok'          => true,
        'valid'       => true,
        'kod'         => $c['kod'],
        'typ'         => $c['typ'],
        'hodnota'     => (float) $c['hodnota'],
        'popis'       => $c['popis'],
        'discount_kc' => $disc,
    ]);
} catch (Throwable $e) {
    error_log('shop_coupon: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'server_error']);
}
