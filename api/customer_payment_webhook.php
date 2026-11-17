<?php
/**
 * 💳 v3.0.643 — WEBHOOK online plateb zákazníků (B2B checkout + QR platby) — Stripe / GoPay.
 *
 *   POST /api/customer_payment_webhook.php?gw=stripe     Stripe event (Stripe-Signature, fail-closed)
 *   GET|POST /api/customer_payment_webhook.php?gw=gopay&id=<payment_id>   GoPay notifikace
 *
 * Potvrzení VŽDY ověří částku a měnu proti očekávané platbě (objednavky.pay_castka/pay_mena,
 * restaurant_pos_ucty.pay_*) — viz _online_platba_lib.php. Nesedí → 'failed' + upozornění, nikdy 'paid'.
 * Veřejný endpoint → whitelist v api/.htaccess (landing-only režim).
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/_online_platba_lib.php';

header('Content-Type: application/json; charset=UTF-8');

$pdo = db();
$gw = strtolower((string) ($_GET['gw'] ?? ''));
if ($gw === '' && !empty($_SERVER['HTTP_STRIPE_SIGNATURE'])) $gw = 'stripe';

try {
    if ($gw === 'stripe') {
        if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') json_error('POST only', 405);
        $payload = (string) file_get_contents('php://input');
        $sig = customer_int_stripe_verify_signature($payload, (string) ($_SERVER['HTTP_STRIPE_SIGNATURE'] ?? ''));
        // 🔒 fail-closed: bez webhook secretu nic nepotvrzujeme (Stripe to zkusí znovu, admin doplní secret)
        if ($sig !== true) {
            error_log('customer_payment_webhook stripe: ' . ($sig === null ? 'webhook secret nenastaven' : 'neplatný podpis') . ' from ' . ($_SERVER['REMOTE_ADDR'] ?? '?'));
            json_error($sig === null ? 'webhook secret not configured' : 'invalid signature', $sig === null ? 503 : 400);
        }
        $event = json_decode($payload, true);
        if (!is_array($event)) json_error('Bad payload', 400);
        $r = online_platba_stripe_event($pdo, $event);
        json_response(['ok' => true, 'stav' => $r['stav']]);
    }
    if ($gw === 'gopay') {
        $gpId = (int) ($_GET['id'] ?? $_POST['id'] ?? 0);
        if ($gpId <= 0) json_error('Chybí id', 400);
        $r = online_platba_gopay($pdo, $gpId);
        json_response(['ok' => true, 'stav' => $r['stav']]);   // GoPay čeká 200
    }
    json_error('Neznámá brána', 400);
} catch (Throwable $e) {
    error_log('customer_payment_webhook ' . $gw . ': ' . $e->getMessage());
    json_error('Chyba zpracování', 500);   // brána to zkusí znovu
}
