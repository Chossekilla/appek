<?php
/**
 * 💳 STRIPE — REST API client (sjednocený, bez závislostí).
 *
 * Workflow:
 *   1. stripe_create_checkout(...) → vytvoří Checkout Session, vrátí URL pro redirect
 *   2. Zákazník zaplatí na hosted page Stripe
 *   3. Stripe POST webhook na api/stripe_webhook.php
 *   4. stripe_verify_signature() ověří pravost (HMAC SHA-256)
 *   5. stripe_get_session() načte detail pro double-check
 *
 * Config v vendor_settings:
 *   stripe_enabled         '1' / '0'
 *   stripe_environment     'test' / 'live'
 *   stripe_secret_key      sk_test_... / sk_live_...
 *   stripe_webhook_secret  whsec_...
 *   stripe_currency        'czk' / 'eur' / 'usd'
 */

require_once __DIR__ . '/_mail.php';

const STRIPE_API_BASE = 'https://api.stripe.com/v1';

function stripe_settings(): array {
    static $cache = null;
    if ($cache !== null) return $cache;
    require_once __DIR__ . '/_lib.php';
    $pdo = vendor_db();
    vendor_ensure_settings_table($pdo);
    $rows = $pdo->query("SELECT `key`, `value` FROM vendor_settings WHERE `key` LIKE 'stripe_%'")->fetchAll(PDO::FETCH_KEY_PAIR);
    $cache = array_merge([
        'stripe_enabled'         => '0',
        'stripe_environment'     => 'test',
        'stripe_secret_key'      => '',
        'stripe_webhook_secret'  => '',
        'stripe_currency'        => 'czk',
        'stripe_apple_pay'       => '1', // Apple Pay enabled by default (Stripe Checkout auto-detection)
        'stripe_google_pay'      => '1', // Google Pay enabled by default
        'stripe_bank_transfer'   => '0', // EU bank transfer (SEPA) — vyžaduje aktivaci v Stripe dashboardu
        'stripe_klarna'          => '0', // Klarna BNPL
        'stripe_publishable_key' => '',  // pro Payment Request Button na frontendu
    ], $rows);
    return $cache;
}

/**
 * Helper pro volání Stripe API.
 */
function stripe_request(string $method, string $endpoint, array $params = []): array {
    $cfg = stripe_settings();
    if (!$cfg['stripe_secret_key']) {
        return ['ok' => false, 'error' => 'missing_secret_key'];
    }
    $url = STRIPE_API_BASE . $endpoint;
    $ch  = curl_init($url);
    $opts = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER     => [
            'Authorization: Bearer ' . $cfg['stripe_secret_key'],
            'Stripe-Version: 2024-06-20',
        ],
        CURLOPT_TIMEOUT        => 30,
    ];
    if ($method === 'POST') {
        $opts[CURLOPT_POST]       = true;
        $opts[CURLOPT_POSTFIELDS] = http_build_query($params);
    } elseif ($method === 'GET' && $params) {
        $opts[CURLOPT_URL]        = $url . '?' . http_build_query($params);
    }
    curl_setopt_array($ch, $opts);
    $resp = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    $data = json_decode($resp, true);
    if ($code < 200 || $code >= 300) {
        return [
            'ok' => false,
            'error' => $data['error']['message'] ?? "http_$code",
            'detail' => $data,
        ];
    }
    return ['ok' => true] + ($data ?: []);
}

/**
 * Vytvoří Stripe Checkout Session.
 *
 * @return array ['ok' => bool, 'session_url' => string|null, 'session_id' => string|null, ...]
 */
function stripe_create_checkout(array $order): array {
    $cfg = stripe_settings();
    if ($cfg['stripe_enabled'] !== '1') {
        return ['ok' => false, 'error' => 'stripe_disabled'];
    }
    $currency = strtolower($cfg['stripe_currency'] ?: 'czk');
    $amountCents = (int) round(((float) $order['amount_kc']) * 100);

    // 🆕 v2.4 — automatické payment methods (Stripe sám aktivuje Apple Pay + Google Pay
    //          podle browseru/device hostů, pokud máš domain verified v Stripe dashboardu).
    //          Card metoda v Stripe Checkout NATIVE podporuje:
    //          - Klasickou kartu (Visa/MC/Amex)
    //          - Apple Pay (Safari iOS/macOS s registered card)
    //          - Google Pay (Chrome/Android s saved card)
    //          - Link (Stripe's 1-click checkout)
    $params = [
        'mode'                       => 'payment',
        // 'automatic_payment_methods[enabled]' => 'true', // moderní cesta — Stripe sám rozhodne
        'payment_method_types[]'     => 'card',
        'line_items[0][price_data][currency]'     => $currency,
        'line_items[0][price_data][product_data][name]' => $order['description'] ?? 'APPEK B2B licence',
        'line_items[0][price_data][product_data][description]' => 'Order ' . ($order['order_no'] ?? ''),
        'line_items[0][price_data][unit_amount]'  => $amountCents,
        'line_items[0][quantity]'    => 1,
        'success_url'                => $order['return_url'] . '&session_id={CHECKOUT_SESSION_ID}',
        'cancel_url'                 => $order['cancel_url'] ?? $order['return_url'],
        'customer_email'             => $order['customer_email'] ?? null,
        'client_reference_id'        => $order['order_no'] ?? null,
        'metadata[order_no]'         => $order['order_no'] ?? '',
        // 🆕 v2.4 — Payment Request branding hints
        'payment_method_options[card][request_three_d_secure]' => 'automatic',
        'allow_promotion_codes'      => 'true',
        'billing_address_collection' => 'auto',
    ];

    // 🆕 v2.4 — volitelně přidat další payment metody
    $idx = 1;
    if ($cfg['stripe_bank_transfer'] === '1' && in_array($currency, ['eur', 'gbp', 'usd'])) {
        $params['payment_method_types[' . ($idx++) . ']'] = 'sepa_debit';
    }
    if ($cfg['stripe_klarna'] === '1' && in_array($currency, ['eur', 'usd', 'gbp', 'sek', 'nok', 'dkk'])) {
        $params['payment_method_types[' . ($idx++) . ']'] = 'klarna';
    }

    return stripe_request('POST', '/checkout/sessions', $params);
}

/**
 * 🆕 v2.4 — Payment Request API (Apple Pay / Google Pay) — vytvoří PaymentIntent
 * pro frontend Payment Request Button (1-tap mobile checkout bez redirectu).
 *
 * Použití: na frontend stránce /checkout.html → button "Apple Pay / G Pay"
 * (Stripe Elements PRB) → klient získá payment_method.id → POST sem → confirm.
 */
function stripe_create_payment_intent(array $order): array {
    $cfg = stripe_settings();
    if ($cfg['stripe_enabled'] !== '1') {
        return ['ok' => false, 'error' => 'stripe_disabled'];
    }
    $currency = strtolower($cfg['stripe_currency'] ?: 'czk');
    $amountCents = (int) round(((float) $order['amount_kc']) * 100);

    $params = [
        'amount'                            => $amountCents,
        'currency'                          => $currency,
        'description'                       => $order['description'] ?? 'APPEK',
        'metadata[order_no]'                => $order['order_no'] ?? '',
        'automatic_payment_methods[enabled]' => 'true',
    ];
    if (!empty($order['customer_email'])) {
        $params['receipt_email'] = $order['customer_email'];
    }
    return stripe_request('POST', '/payment_intents', $params);
}

/**
 * Načte detail Checkout Session.
 */
function stripe_get_session(string $sessionId): array {
    return stripe_request('GET', "/checkout/sessions/" . urlencode($sessionId));
}

/**
 * Ověří HMAC SHA-256 podpis webhooku (anti-spoofing).
 *
 * @param string $payload    Raw body requestu
 * @param string $sigHeader  Hodnota Stripe-Signature
 * @return bool
 */
function stripe_verify_signature(string $payload, string $sigHeader): bool {
    $cfg = stripe_settings();
    $secret = $cfg['stripe_webhook_secret'];
    if (!$secret) return false;

    // Parse Stripe-Signature header: "t=TIMESTAMP,v1=SIG[,v1=SIG2]"
    $parts = [];
    foreach (explode(',', $sigHeader) as $pair) {
        [$k, $v] = array_pad(explode('=', $pair, 2), 2, '');
        $parts[$k] = $v;
    }
    if (empty($parts['t']) || empty($parts['v1'])) return false;

    // Tolerance ±5 minut
    $ts = (int) $parts['t'];
    if (abs(time() - $ts) > 300) return false;

    $signedPayload = $ts . '.' . $payload;
    $expected = hash_hmac('sha256', $signedPayload, $secret);
    return hash_equals($expected, $parts['v1']);
}

/**
 * Refund — vrácení peněz (full nebo částečný).
 */
function stripe_refund(string $paymentIntentId, ?int $amountCents = null): array {
    $params = ['payment_intent' => $paymentIntentId];
    if ($amountCents !== null) $params['amount'] = $amountCents;
    return stripe_request('POST', '/refunds', $params);
}
