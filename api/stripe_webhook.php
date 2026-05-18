<?php
/**
 * 💳 STRIPE WEBHOOK — handle checkout.session.completed event.
 *
 * URL: /api/stripe_webhook.php
 * Stripe POST sem JSON event. Validujeme přes Stripe-Signature header (HMAC SHA-256).
 *
 * Při checkout.session.completed → označit zaplaceno + vygenerovat licenci + e-mail.
 */

require_once __DIR__ . '/_license.php';

$vendorRoot = realpath(__DIR__ . '/..') . '/vendor';
if (!is_dir($vendorRoot)) {
    http_response_code(503);
    exit;
}
require_once $vendorRoot . '/_lib.php';
require_once $vendorRoot . '/_mail.php';
require_once $vendorRoot . '/_stripe.php';

$payload   = file_get_contents('php://input');
$sigHeader = $_SERVER['HTTP_STRIPE_SIGNATURE'] ?? '';

if (!stripe_verify_signature($payload, $sigHeader)) {
    http_response_code(400);
    error_log('stripe_webhook: invalid signature');
    echo 'invalid signature';
    exit;
}

$event = json_decode($payload, true);
if (!is_array($event) || empty($event['type'])) {
    http_response_code(400);
    echo 'invalid payload';
    exit;
}

// Jen checkout.session.completed nás zajímá pro tento MVP
if ($event['type'] !== 'checkout.session.completed') {
    http_response_code(200);
    echo 'event ignored: ' . $event['type'];
    exit;
}

$session = $event['data']['object'] ?? [];
$orderNo = $session['client_reference_id'] ?? ($session['metadata']['order_no'] ?? '');

if (!$orderNo) {
    http_response_code(400);
    echo 'no order reference';
    exit;
}

try {
    $pdo = vendor_db();
    $stmt = $pdo->prepare("SELECT * FROM vendor_shop_orders WHERE order_no = :no LIMIT 1");
    $stmt->execute(['no' => $orderNo]);
    $order = $stmt->fetch();
    if (!$order) {
        http_response_code(404);
        echo 'order not found';
        exit;
    }

    // Idempotence — pokud už paid, jen OK
    if ($order['payment_status'] === 'paid') {
        echo 'already paid';
        exit;
    }

    // Mark paid
    $pdo->prepare("
        UPDATE vendor_shop_orders
        SET payment_status = 'paid', paid_at = NOW(), payment_id = :pid
        WHERE id = :id
    ")->execute(['pid' => $session['payment_intent'] ?? $session['id'], 'id' => $order['id']]);

    // Auto-generate licence
    $packages = json_decode($order['packages_json'] ?? '[]', true) ?: [];
    $packages = array_filter($packages, fn($k) => $k !== 'core');
    $key = license_generate_with_packages($packages);
    $expires = date('Y-m-d', strtotime('+1 year'));

    $pdo->prepare("
        INSERT INTO vendor_licenses
          (license_key, customer_name, customer_company, customer_email, customer_phone,
           install_url, note, expires_at, status, price_kc, paid)
        VALUES (:k, :n, :c, :e, :p, :u, :note, :exp, 'active', :pr, 1)
    ")->execute([
        'k' => $key,
        'n' => $order['customer_name'],
        'c' => $order['customer_company'],
        'e' => $order['customer_email'],
        'p' => $order['customer_phone'],
        'u' => $order['install_url'],
        'note' => "Auto-generated z Stripe · order $orderNo",
        'exp' => $expires,
        'pr' => $order['total_kc'],
    ]);
    $licenseId = (int) $pdo->lastInsertId();
    $pdo->prepare("UPDATE vendor_shop_orders SET license_id = :lid, license_key = :lk WHERE id = :id")
        ->execute(['lid' => $licenseId, 'lk' => $key, 'id' => $order['id']]);

    // Auto-email
    try {
        $tpl = vendor_mail_template_license([
            'customer_name'  => $order['customer_name'],
            'customer_email' => $order['customer_email'],
            'license_key'    => $key,
        ], $order);
        vendor_send_mail(
            $order['customer_email'],
            '🔑 Vaše APPEK licence — ' . $key,
            $tpl['html'], $tpl['text']
        );
    } catch (Throwable $e) { error_log('stripe_webhook mail: ' . $e->getMessage()); }

    vendor_audit($pdo, ['username' => 'stripe_webhook'], 'shop_auto_paid', ['id' => $licenseId, 'license_key' => $key], $orderNo);

    http_response_code(200);
    echo 'OK';
} catch (Throwable $e) {
    error_log('stripe_webhook: ' . $e->getMessage());
    http_response_code(500);
    echo 'server error';
}
