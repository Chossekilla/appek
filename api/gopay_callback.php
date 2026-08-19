<?php
/**
 * 💳 GOPAY CALLBACK — notification handler.
 *
 * GoPay posílá POST/GET sem po stavu platby. My ověříme přes API
 * (anti-spoofing) a aktualizujeme objednávku + případně vygenerujeme licenci.
 *
 * URL: /api/gopay_callback.php?id=PAYMENT_ID
 */

header('Content-Type: text/plain; charset=UTF-8');

// 🐛 v3.0.466 — NEnačítat api/_license.php: tento endpoint běží jen na master serveru
//   (kde existuje vendor/), a vendor/_lib.php níže natáhne vendor/_license.php se
//   stejnými (identickými) funkcemi. Načíst obě = „Cannot redeclare" fatal (500 →
//   zákazník zaplatí, licence se nevystaví). license_generate_with_packages() bereme
//   z vendor/_license.php. Bez vendor/ endpoint stejně 503 dřív, než ji potřebuje.

// 🆕 v3.0.355 — bez payment id neloaduj vendor knihovny (na not-configured instalaci fatalují) → čistý 400 místo 500
$paymentId = trim($_GET['id'] ?? $_POST['id'] ?? '');
if ($paymentId === '') {
    http_response_code(400);
    echo "missing payment id\n";
    exit;
}

// Načti vendor knihovny
$vendorRoot = realpath(__DIR__ . '/..') . '/vendor';
if (!is_dir($vendorRoot)) {
    http_response_code(503);
    echo "vendor not available\n";
    exit;
}
require_once $vendorRoot . '/_lib.php';
require_once $vendorRoot . '/_mail.php';
require_once $vendorRoot . '/_gopay.php';

// ─── Ověř stav přes GoPay API ───────────────────────────────────
$status = gopay_verify_status($paymentId);
if (!$status['ok']) {
    error_log("gopay_callback verify failed: " . ($status['error'] ?? 'unknown') . " · payment_id=$paymentId");
    http_response_code(502);
    echo "verify failed\n";
    exit;
}

$pdo = vendor_db();

// Najdi objednávku podle order_number
$orderNo = $status['order_number'];
$stmt = $pdo->prepare("SELECT * FROM vendor_shop_orders WHERE order_no = :no LIMIT 1");
$stmt->execute(['no' => $orderNo]);
$order = $stmt->fetch();

if (!$order) {
    error_log("gopay_callback: order $orderNo not found");
    http_response_code(404);
    echo "order not found\n";
    exit;
}

// Zaktualizuj payment_id v order
$pdo->prepare("UPDATE vendor_shop_orders SET payment_id = :pid WHERE id = :id")
    ->execute(['pid' => $paymentId, 'id' => $order['id']]);

// 🔒 v3.0.353 — ověř ČÁSTKU (jinak: zaplatit míň, dostat licenci na drahý balíček)
$_paidKc = (float) ($status['amount_kc'] ?? 0);
$_dueKc  = (float) ($order['total_kc'] ?? 0);
if ($status['state'] === 'PAID' && $_paidKc > 0 && abs($_paidKc - $_dueKc) > 0.01) {
    error_log("gopay_callback: amount mismatch order=$orderNo paid=$_paidKc due=$_dueKc — NEoznačeno paid");
    http_response_code(409);
    echo "amount mismatch\n";
    exit;
}

// Stav PAID → označit zaplaceno + vygenerovat licenci + odeslat e-mail
if ($status['state'] === 'PAID' && $order['payment_status'] !== 'paid') {
    $pdo->prepare("UPDATE vendor_shop_orders SET payment_status = 'paid', paid_at = NOW() WHERE id = :id")
        ->execute(['id' => $order['id']]);

    // Auto-generate licence (jen pokud ještě není)
    if (empty($order['license_id'])) {
        $packages = json_decode($order['packages_json'] ?? '[]', true) ?: [];
        $packages = array_filter($packages, fn($k) => $k !== 'core');
        $key = license_generate_with_packages($packages);
        // 🆕 PRONÁJEM (Fáze 2): rental_months>0 → rental licence (expires=+N měsíců), jinak roční.
        $rentalMonths = (int) ($order['rental_months'] ?? 0);
        $isRental = $rentalMonths > 0;
        $expires = date('Y-m-d', strtotime($isRental ? "+{$rentalMonths} months" : '+1 year'));

        $pdo->prepare("
            INSERT INTO vendor_licenses
              (license_key, customer_name, customer_company, customer_email, customer_phone,
               install_url, note, expires_at, status, price_kc, paid, rental)
            VALUES (:k, :n, :c, :e, :p, :u, :note, :exp, 'active', :pr, 1, :rt)
        ")->execute([
            'k' => $key,
            'n' => $order['customer_name'],
            'c' => $order['customer_company'],
            'e' => $order['customer_email'],
            'p' => $order['customer_phone'],
            'u' => $order['install_url'],
            'note' => ($isRental ? "Pronájem {$rentalMonths} měs. · " : "") . "Auto-generated z GoPay · order {$order['order_no']}",
            'exp' => $expires,
            'pr' => $order['total_kc'],
            'rt' => $isRental ? 1 : 0,
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
                '🔑 Vaše APPEK licence je připravená',
                $tpl['html'], $tpl['text']
            );
        } catch (Throwable $e) { error_log('gopay_callback mail: ' . $e->getMessage()); }

        vendor_audit($pdo, ['username' => 'gopay_callback'], 'shop_auto_paid', ['id' => $licenseId, 'license_key' => $key], $order['order_no']);
    }
    echo "OK · paid · license generated\n";
    exit;
}

// Stav CANCELED → označit zrušeno
if (in_array($status['state'], ['CANCELED', 'TIMEOUTED'], true) && $order['payment_status'] === 'pending') {
    $pdo->prepare("UPDATE vendor_shop_orders SET payment_status = 'cancelled' WHERE id = :id")
        ->execute(['id' => $order['id']]);
    echo "OK · cancelled\n";
    exit;
}

// Stav PAYMENT_METHOD_CHOSEN / AUTHORIZED → ještě čekáme
echo "OK · state={$status['state']}\n";
