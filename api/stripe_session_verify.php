<?php
/**
 * 💳 STRIPE SESSION VERIFY — server-side ověření zda Stripe Checkout Session byla zaplacena.
 *
 * Volá payment-done.html PO redirectu z Stripe, aby NEČEKAL na webhook
 * (který může selhat / není nakonfigurován). Endpoint dělá to samé co webhook:
 *   1. Načte session ze Stripe API
 *   2. Pokud paid → mark order paid, generate license, send email (idempotentní)
 *   3. Vrátí { ok, status, license_key?, email? }
 *
 * GET nebo POST /api/stripe_session_verify.php?session_id=cs_xxx[&order=APPEK-ORD-...]
 *
 * Bezpečnost: session_id obsahuje cs_live_ / cs_test_ + cca 60 znaků náhody;
 * útočník by ji musel uhodnout. Endpoint je veřejný (po platbě se na něj
 * dotazuje payment-done.html bez auth).
 */

header('Content-Type: application/json; charset=UTF-8');
header('Access-Control-Allow-Origin: *');

$sessionId = trim($_GET['session_id'] ?? $_POST['session_id'] ?? '');
$orderNoIn = trim($_GET['order'] ?? $_POST['order'] ?? '');

if (!$sessionId || !preg_match('/^cs_(test|live)_[A-Za-z0-9]{20,}$/', $sessionId)) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'invalid_session_id']);
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
require_once $vendorRoot . '/_stripe.php';
require_once $vendorRoot . '/_license.php';

try {
    // 1) Načti session ze Stripe API
    $session = stripe_request('GET', '/checkout/sessions/' . $sessionId);
    if (!($session['ok'] ?? false)) {
        http_response_code(502);
        echo json_encode(['ok' => false, 'error' => 'stripe_api_failed', 'detail' => $session['error'] ?? 'unknown']);
        exit;
    }

    $stripePaymentStatus = $session['payment_status'] ?? 'unpaid';  // 'paid' | 'unpaid' | 'no_payment_required'
    $stripeStatus = $session['status'] ?? 'open';                    // 'open' | 'complete' | 'expired'
    $orderNo = $orderNoIn ?: ($session['client_reference_id'] ?? ($session['metadata']['order_no'] ?? ''));

    if (!$orderNo) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'missing_order_no']);
        exit;
    }

    // 2) Najdi order v DB
    $pdo = vendor_db();
    $stmt = $pdo->prepare("SELECT * FROM vendor_shop_orders WHERE order_no = :no LIMIT 1");
    $stmt->execute(['no' => $orderNo]);
    $order = $stmt->fetch();

    if (!$order) {
        http_response_code(404);
        echo json_encode(['ok' => false, 'error' => 'order_not_found']);
        exit;
    }

    // 3) Pokud už paid → idempotent return
    if ($order['payment_status'] === 'paid') {
        echo json_encode([
            'ok' => true,
            'status' => 'paid',
            'order_no' => $orderNo,
            'license_key' => $order['license_key'] ?? null,
            'email' => $order['customer_email'],
            'note' => 'already_processed',
        ]);
        exit;
    }

    // 4) Pokud Stripe říká NOT paid → vrátit pending/failed bez akce
    if ($stripePaymentStatus !== 'paid') {
        $st = ($stripeStatus === 'expired') ? 'failed' : 'pending';
        echo json_encode([
            'ok' => true,
            'status' => $st,
            'order_no' => $orderNo,
            'stripe_payment_status' => $stripePaymentStatus,
            'stripe_status' => $stripeStatus,
        ]);
        exit;
    }

    // 5) Stripe říká PAID — provedeme stejný workflow jako webhook
    $pdo->prepare("
        UPDATE vendor_shop_orders
        SET payment_status = 'paid', paid_at = NOW(), payment_id = :pid
        WHERE id = :id
    ")->execute([
        'pid' => $session['payment_intent'] ?? $session['id'],
        'id'  => $order['id'],
    ]);

    // Generate license
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
        'k'    => $key,
        'n'    => $order['customer_name'],
        'c'    => $order['customer_company'],
        'e'    => $order['customer_email'],
        'p'    => $order['customer_phone'],
        'u'    => $order['install_url'],
        'note' => "Auto-generated z stripe_session_verify (Stripe) · order $orderNo",
        'exp'  => $expires,
        'pr'   => $order['total_kc'],
    ]);
    $licenseId = (int) $pdo->lastInsertId();
    $pdo->prepare("UPDATE vendor_shop_orders SET license_id = :lid, license_key = :lk WHERE id = :id")
        ->execute(['lid' => $licenseId, 'lk' => $key, 'id' => $order['id']]);

    // Send license email
    $emailOk = false;
    try {
        $tpl = vendor_mail_template_license([
            'customer_name'  => $order['customer_name'],
            'customer_email' => $order['customer_email'],
            'license_key'    => $key,
        ], $order);
        $emailOk = vendor_send_mail(
            $order['customer_email'],
            '🔑 Vaše APPEK licence — ' . $key,
            $tpl['html'], $tpl['text']
        );
    } catch (Throwable $e) {
        error_log('stripe_session_verify mail: ' . $e->getMessage());
    }

    vendor_audit($pdo, ['username' => 'stripe_session_verify'], 'shop_auto_paid_verify',
        ['id' => $licenseId, 'license_key' => $key], $orderNo);

    // 🆕 v2.9.208 — admin notifikace (besta-effort, neblokuje response)
    try {
        if (function_exists('vendor_send_admin_notification')) {
            vendor_send_admin_notification($order, ['license_key' => $key]);
        }
    } catch (Throwable $e) {
        error_log('stripe_session_verify admin notification: ' . $e->getMessage());
    }

    echo json_encode([
        'ok' => true,
        'status' => 'paid',
        'order_no' => $orderNo,
        'license_key' => $key,
        'email' => $order['customer_email'],
        'email_sent' => $emailOk,
    ]);
} catch (Throwable $e) {
    error_log('stripe_session_verify: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'server_error', 'detail' => $e->getMessage()]);
}
