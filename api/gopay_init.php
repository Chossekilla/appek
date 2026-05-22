<?php
/**
 * 💳 GOPAY INIT — vytvoří payment session pro existující objednávku.
 *
 * POST /api/gopay_init.php
 *   Body JSON: { order_no: "APPEK-ORD-..." }
 *
 * Vrátí gateway_url, na který klient přesměruje uživatele.
 */

header('Content-Type: application/json; charset=UTF-8');
header('Access-Control-Allow-Origin: *');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'method_not_allowed']);
    exit;
}

$vendorRoot = realpath(__DIR__ . '/..') . '/vendor';
if (!is_dir($vendorRoot)) {
    http_response_code(503);
    echo json_encode(['ok' => false, 'error' => 'vendor_not_configured']);
    exit;
}
require_once $vendorRoot . '/_lib.php';
require_once $vendorRoot . '/_mail.php';
require_once $vendorRoot . '/_gopay.php';

$d = json_decode(file_get_contents('php://input'), true);
$orderNo = trim($d['order_no'] ?? '');
if (!$orderNo) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'missing_order_no']);
    exit;
}

try {
    $pdo = vendor_db();
    $stmt = $pdo->prepare("SELECT * FROM vendor_shop_orders WHERE order_no = :no LIMIT 1");
    $stmt->execute(['no' => $orderNo]);
    $order = $stmt->fetch();
    if (!$order) {
        http_response_code(404);
        echo json_encode(['ok' => false, 'error' => 'order_not_found']);
        exit;
    }
    if ($order['payment_status'] === 'paid') {
        echo json_encode(['ok' => false, 'error' => 'already_paid']);
        exit;
    }

    $baseUrl = 'https://' . ($_SERVER['HTTP_HOST'] ?? 'appek.cz');
    $session = gopay_create_payment([
        'order_no'         => $order['order_no'],
        'amount_kc'        => (float) $order['total_kc'],
        'currency'         => $order['currency'] ?? 'CZK',
        'description'      => 'APPEK B2B — ' . ($order['tier'] ?? 'licence'),
        'customer_email'   => $order['customer_email'],
        'customer_name'    => $order['customer_name'],
        'return_url'       => $baseUrl . '/payment-done.html?order=' . urlencode($order['order_no']),
        'notification_url' => $baseUrl . '/api/gopay_callback.php',
    ]);

    if (!$session['ok']) {
        echo json_encode(['ok' => false, 'error' => $session['error'] ?? 'gopay_failed', 'detail' => $session['detail'] ?? null]);
        exit;
    }

    // Uložit payment_id
    $pdo->prepare("UPDATE vendor_shop_orders SET payment_id = :pid, payment_method = 'card' WHERE id = :id")
        ->execute(['pid' => $session['payment_id'], 'id' => $order['id']]);

    echo json_encode([
        'ok'          => true,
        'gateway_url' => $session['gateway_url'],
        'payment_id'  => $session['payment_id'],
    ]);
} catch (Throwable $e) {
    error_log('gopay_init: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'server_error']);
}
