<?php
/**
 * 💳 STRIPE PAYMENT INTENT — pro Apple Pay / Google Pay přes Payment Request Button (PRB).
 *
 * Workflow (1-tap mobile checkout BEZ redirectu na Stripe Checkout):
 *   1. Frontend (checkout.html / b2b checkout) detekuje že browser podporuje Apple/Google Pay
 *   2. POST sem s order_no → vrátíme client_secret + publishable_key
 *   3. Frontend zavolá Stripe.js elements.create('paymentRequestButton', ...)
 *   4. Zákazník klikne Apple Pay / G Pay → 1-tap autorizace
 *   5. Stripe.js confirmPaymentIntent(client_secret) → success
 *   6. Stripe webhook (stejný jako Checkout flow) potvrdí v DB
 *
 * Endpoint je veřejný — order_no je dostatečná identifikace (orphan order není problém).
 */

header('Content-Type: application/json; charset=UTF-8');
header('Access-Control-Allow-Origin: *');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    header('Access-Control-Allow-Methods: POST');
    header('Access-Control-Allow-Headers: Content-Type');
    exit;
}
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
require_once $vendorRoot . '/_stripe.php';

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

    $intent = stripe_create_payment_intent([
        'order_no'       => $order['order_no'],
        'amount_kc'      => (float) $order['total_kc'],
        'description'    => 'APPEK B2B — ' . ($order['tier'] ?? 'licence'),
        'customer_email' => $order['customer_email'],
    ]);

    if (!$intent['ok']) {
        echo json_encode(['ok' => false, 'error' => $intent['error'] ?? 'stripe_failed']);
        exit;
    }

    // Ulož payment_id pro pozdější double-check (stejně jako checkout flow)
    $pdo->prepare("UPDATE vendor_shop_orders SET payment_id = :pid, payment_method = 'card' WHERE id = :id")
        ->execute(['pid' => $intent['id'], 'id' => $order['id']]);

    $cfg = stripe_settings();
    echo json_encode([
        'ok'              => true,
        'client_secret'   => $intent['client_secret'],
        'publishable_key' => $cfg['stripe_publishable_key'],
        'amount'          => $intent['amount'],
        'currency'        => $intent['currency'],
        'order_no'        => $order['order_no'],
        'description'     => 'APPEK B2B — ' . ($order['tier'] ?? 'licence'),
        'country'         => 'CZ',
        'apple_pay'       => $cfg['stripe_apple_pay'] === '1',
        'google_pay'      => $cfg['stripe_google_pay'] === '1',
    ]);
} catch (Throwable $e) {
    error_log('stripe_payment_intent: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'server_error']);
}
