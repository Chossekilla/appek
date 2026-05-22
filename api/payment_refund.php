<?php
/**
 * ↩️ PAYMENT REFUND — universal refund endpoint pro GoPay i Stripe.
 *
 * POST /api/payment_refund.php
 *   Body JSON: {
 *     order_no:    "APPEK-ORD-...",
 *     amount_kc:   null nebo částka (null = full),
 *     reason:      "duvod refundu"
 *   }
 *
 * Detekuje gateway podle payment_method v objednávce.
 * Po úspěšném refundu změní payment_status na 'refunded' a může revoke licenci.
 */

header('Content-Type: application/json; charset=UTF-8');

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
require_once $vendorRoot . '/_stripe.php';

// Auth — jen pro přihlášený vendor admin
require_once $vendorRoot . '/_layout.php';
$user = vendor_user();
if (!$user) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'unauthorized']);
    exit;
}

$d = json_decode(file_get_contents('php://input'), true);
$orderNo = trim($d['order_no'] ?? '');
$amountKc = isset($d['amount_kc']) && $d['amount_kc'] !== null ? (float) $d['amount_kc'] : null;
$reason = trim($d['reason'] ?? '');

if (!$orderNo) {
    echo json_encode(['ok' => false, 'error' => 'missing_order_no']);
    exit;
}

try {
    $pdo = vendor_db();
    $stmt = $pdo->prepare("SELECT * FROM vendor_shop_orders WHERE order_no = :no LIMIT 1");
    $stmt->execute(['no' => $orderNo]);
    $order = $stmt->fetch();
    if (!$order) {
        echo json_encode(['ok' => false, 'error' => 'order_not_found']);
        exit;
    }
    if ($order['payment_status'] !== 'paid') {
        echo json_encode(['ok' => false, 'error' => 'order_not_paid']);
        exit;
    }
    if (!$order['payment_id']) {
        echo json_encode(['ok' => false, 'error' => 'no_payment_id']);
        exit;
    }

    // Detekce gateway přes payment_method nebo přes prefix payment_id
    $paymentId = $order['payment_id'];
    $isStripe = str_starts_with($paymentId, 'pi_') || str_starts_with($paymentId, 'cs_');
    $isGoPay  = !$isStripe && ($order['payment_method'] === 'card' || ctype_digit($paymentId));

    $refundResult = ['ok' => false, 'error' => 'unknown_gateway'];

    if ($isStripe) {
        // U Stripe potřebujeme payment_intent, ne session_id
        // Pokud máme jen session_id, načteme z něj payment_intent
        if (str_starts_with($paymentId, 'cs_')) {
            $sess = stripe_get_session($paymentId);
            if ($sess['ok'] && !empty($sess['payment_intent'])) {
                $paymentId = is_array($sess['payment_intent']) ? $sess['payment_intent']['id'] : $sess['payment_intent'];
            }
        }
        $amountCents = $amountKc !== null ? (int) round($amountKc * 100) : null;
        $refundResult = stripe_refund($paymentId, $amountCents);
    } elseif ($isGoPay) {
        $refundResult = gopay_refund($paymentId, $amountKc);
    }

    if (!$refundResult['ok']) {
        echo json_encode(['ok' => false, 'error' => $refundResult['error'] ?? 'refund_failed', 'detail' => $refundResult['detail'] ?? null]);
        exit;
    }

    // Aktualizace stavu
    $pdo->prepare("UPDATE vendor_shop_orders SET payment_status = 'refunded' WHERE id = :id")
        ->execute(['id' => $order['id']]);

    // Revoke licenci pokud byla vygenerována
    if (!empty($order['license_id'])) {
        $pdo->prepare("
            UPDATE vendor_licenses
            SET status = 'revoked', revoked_at = NOW(), revoke_reason = :r
            WHERE id = :id
        ")->execute(['r' => 'Refund: ' . ($reason ?: 'no reason'), 'id' => $order['license_id']]);
    }

    vendor_audit($pdo, $user, 'shop_refund', null, $orderNo);

    echo json_encode([
        'ok'       => true,
        'gateway'  => $isStripe ? 'stripe' : 'gopay',
        'amount_kc'=> $amountKc ?? (float) $order['total_kc'],
        'message'  => 'Refund úspěšný. Licence revoked.',
    ]);
} catch (Throwable $e) {
    error_log('payment_refund: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'server_error']);
}
