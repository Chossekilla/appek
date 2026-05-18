<?php
/**
 * 🛒 SHOP STATUS — vrátí status objednávky.
 *
 * GET /api/shop_status.php?order=APPEK-ORD-...
 *
 * Vrátí: { payment_status, has_license }
 * Bezpečnost: vrací jen základní stav (žádné platební údaje).
 */

header('Content-Type: application/json; charset=UTF-8');
header('Access-Control-Allow-Origin: *');

$orderNo = trim($_GET['order'] ?? '');
if (!$orderNo || !preg_match('/^APPEK-ORD-/', $orderNo)) {
    http_response_code(400);
    echo json_encode(['error' => 'invalid_order']);
    exit;
}

$vendorRoot = realpath(__DIR__ . '/..') . '/vendor';
if (!is_dir($vendorRoot)) {
    http_response_code(503);
    echo json_encode(['error' => 'vendor_not_available']);
    exit;
}
require_once $vendorRoot . '/_lib.php';

try {
    $pdo = vendor_db();
    $stmt = $pdo->prepare("SELECT payment_status, license_id FROM vendor_shop_orders WHERE order_no = :no LIMIT 1");
    $stmt->execute(['no' => $orderNo]);
    $row = $stmt->fetch();
    if (!$row) {
        http_response_code(404);
        echo json_encode(['error' => 'order_not_found']);
        exit;
    }

    echo json_encode([
        'order_no'       => $orderNo,
        'payment_status' => $row['payment_status'],
        'has_license'    => !empty($row['license_id']),
    ]);
} catch (Throwable $e) {
    error_log('shop_status: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'server_error']);
}
