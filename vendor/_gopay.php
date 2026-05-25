<?php
/**
 * 💳 GOPAY — REST API client (sjednocený, bez závislostí).
 *
 * Workflow:
 *   1. gopay_get_token()          → OAuth 2.0 access token (client_credentials)
 *   2. gopay_create_payment(...)  → vytvoří payment session, vrátí gateway_url
 *   3. Zákazník je přesměrován na gateway_url, zaplatí
 *   4. GoPay POST notifies api/gopay_callback.php
 *   5. gopay_verify_status(id)    → potvrdí stav přes API (anti-spoofing)
 *
 * Config v vendor_settings tabulce (key/value):
 *   gopay_enabled        '1' / '0'
 *   gopay_environment    'sandbox' / 'production'
 *   gopay_goid           tvoje GoID (číselný identifikátor obchodníka)
 *   gopay_client_id      OAuth client ID
 *   gopay_client_secret  OAuth client secret
 */

require_once __DIR__ . '/_mail.php';  // používáme vendor_mail_settings logic pro settings

const GOPAY_API_PROD    = 'https://gate.gopay.cz/api';
const GOPAY_API_SANDBOX = 'https://gw.sandbox.gopay.com/api';

function gopay_settings(): array {
    static $cache = null;
    if ($cache !== null) return $cache;
    require_once __DIR__ . '/_lib.php';
    $pdo = vendor_db();
    vendor_ensure_settings_table($pdo);
    $rows = $pdo->query("SELECT `key`, `value` FROM vendor_settings WHERE `key` LIKE 'gopay_%'")->fetchAll(PDO::FETCH_KEY_PAIR);
    $cache = array_merge([
        'gopay_enabled'       => '0',
        'gopay_environment'   => 'sandbox',
        'gopay_goid'          => '',
        'gopay_client_id'     => '',
        'gopay_client_secret' => '',
    ], $rows);
    return $cache;
}

function gopay_api_base(): string {
    $cfg = gopay_settings();
    return $cfg['gopay_environment'] === 'production' ? GOPAY_API_PROD : GOPAY_API_SANDBOX;
}

/**
 * OAuth 2.0 access token (cached pro request).
 */
function gopay_get_token(): ?string {
    static $token = null;
    if ($token !== null) return $token;
    $cfg = gopay_settings();
    if (!$cfg['gopay_client_id'] || !$cfg['gopay_client_secret']) return null;

    $url  = gopay_api_base() . '/oauth2/token';
    $auth = base64_encode($cfg['gopay_client_id'] . ':' . $cfg['gopay_client_secret']);
    $ch   = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => 'grant_type=client_credentials&scope=payment-create',
        CURLOPT_HTTPHEADER     => [
            'Authorization: Basic ' . $auth,
            'Content-Type: application/x-www-form-urlencoded',
            'Accept: application/json',
        ],
        CURLOPT_TIMEOUT        => 15,
    ]);
    $resp = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($code !== 200) {
        error_log("gopay_get_token failed: HTTP $code · $resp");
        return null;
    }
    $data = json_decode($resp, true);
    $token = $data['access_token'] ?? null;
    return $token;
}

/**
 * Vytvoří payment session. Vrátí gateway_url pro redirect.
 *
 * @param array $order [
 *   'order_no'        => 'APPEK-ORD-...',
 *   'amount_kc'       => 12990.00,
 *   'currency'        => 'CZK',
 *   'description'     => 'APPEK Starter licence',
 *   'customer_email'  => 'a@b.cz',
 *   'customer_name'   => 'Jan Novák',
 *   'return_url'      => 'https://appek.cz/payment/done?order=...',
 *   'notification_url'=> 'https://appek.cz/api/gopay_callback.php',
 * ]
 *
 * @return array ['ok' => bool, 'gateway_url' => string|null, 'payment_id' => string|null, 'error' => string|null]
 */
function gopay_create_payment(array $order): array {
    $cfg = gopay_settings();
    if ($cfg['gopay_enabled'] !== '1') {
        return ['ok' => false, 'error' => 'gopay_disabled'];
    }
    $token = gopay_get_token();
    if (!$token) {
        return ['ok' => false, 'error' => 'oauth_failed'];
    }

    $amountCents = (int) round(((float) $order['amount_kc']) * 100);
    $orderNo     = $order['order_no'];
    $currency    = $order['currency'] ?? 'CZK';
    $name        = trim($order['customer_name'] ?? '');
    $names       = explode(' ', $name, 2);
    $firstName   = $names[0] ?? '';
    $lastName    = $names[1] ?? '';

    $payload = [
        'payer' => [
            'default_payment_instrument' => 'PAYMENT_CARD',
            'allowed_payment_instruments' => ['PAYMENT_CARD', 'BANK_ACCOUNT', 'GPAY', 'APPLE_PAY', 'PAYPAL'],
            'contact' => [
                'first_name' => $firstName,
                'last_name'  => $lastName,
                'email'      => $order['customer_email'],
            ],
        ],
        'target'   => ['type' => 'ACCOUNT', 'goid' => (int) $cfg['gopay_goid']],
        'amount'   => $amountCents,
        'currency' => $currency,
        'order_number'      => $orderNo,
        'order_description' => $order['description'] ?? 'APPEK B2B',
        'callback' => [
            'return_url'       => $order['return_url'],
            'notification_url' => $order['notification_url'],
        ],
        'lang' => 'CS',
    ];

    $url = gopay_api_base() . '/payments/payment';
    $ch  = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => json_encode($payload),
        CURLOPT_HTTPHEADER     => [
            'Authorization: Bearer ' . $token,
            'Content-Type: application/json',
            'Accept: application/json',
        ],
        CURLOPT_TIMEOUT        => 30,
    ]);
    $resp = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($code < 200 || $code >= 300) {
        error_log("gopay_create_payment failed: HTTP $code · $resp");
        return ['ok' => false, 'error' => "http_$code", 'detail' => $resp];
    }
    $data = json_decode($resp, true);
    if (empty($data['gw_url']) || empty($data['id'])) {
        return ['ok' => false, 'error' => 'invalid_response', 'detail' => $resp];
    }
    return [
        'ok'          => true,
        'gateway_url' => $data['gw_url'],
        'payment_id'  => (string) $data['id'],
        'state'       => $data['state'] ?? 'CREATED',
        'raw'         => $data,
    ];
}

/**
 * Refund — vrátí peníze za zaplacenou platbu.
 *
 * @param string $paymentId
 * @param float|null $amountKc  null = full refund
 * @return array ['ok' => bool, 'error' => string|null, 'raw' => array|null]
 */
function gopay_refund(string $paymentId, ?float $amountKc = null): array {
    $token = gopay_get_token();
    if (!$token) return ['ok' => false, 'error' => 'oauth_failed'];

    $url = gopay_api_base() . '/payments/payment/' . urlencode($paymentId) . '/refund';
    $payload = [];
    if ($amountKc !== null) {
        $payload['amount'] = (int) round($amountKc * 100);
    }

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => http_build_query($payload),
        CURLOPT_HTTPHEADER     => [
            'Authorization: Bearer ' . $token,
            'Content-Type: application/x-www-form-urlencoded',
            'Accept: application/json',
        ],
        CURLOPT_TIMEOUT        => 30,
    ]);
    $resp = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($code < 200 || $code >= 300) {
        return ['ok' => false, 'error' => "http_$code", 'detail' => $resp];
    }
    return ['ok' => true, 'raw' => json_decode($resp, true)];
}

/**
 * Ověří stav platby přes API (po callbacku — anti-spoofing).
 *
 * @return array ['ok' => bool, 'state' => string|null, 'amount_kc' => float|null, 'error' => string|null]
 */
function gopay_verify_status(string $paymentId): array {
    $token = gopay_get_token();
    if (!$token) return ['ok' => false, 'error' => 'oauth_failed'];

    $url = gopay_api_base() . '/payments/payment/' . urlencode($paymentId);
    $ch  = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER     => [
            'Authorization: Bearer ' . $token,
            'Accept: application/json',
        ],
        CURLOPT_TIMEOUT        => 15,
    ]);
    $resp = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($code !== 200) return ['ok' => false, 'error' => "http_$code"];
    $data = json_decode($resp, true);
    return [
        'ok'         => true,
        'state'      => $data['state'] ?? null,
        'amount_kc'  => isset($data['amount']) ? (float) $data['amount'] / 100 : null,
        'order_number'=> $data['order_number'] ?? null,
        'raw'        => $data,
    ];
}
