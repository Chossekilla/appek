<?php
/**
 * 🔌 CUSTOMER INTEGRACE — sjednocená knihovna pro customer admin.
 *
 * Konfigurace v `nastaveni` tabulce (klíče int_<service>_<param>):
 *
 *   STRIPE (online platby přes Stripe Checkout):
 *     int_stripe_enabled         '1'/'0'
 *     int_stripe_environment     'test'/'live'
 *     int_stripe_secret_key      sk_live_... / sk_test_...
 *     int_stripe_publishable_key pk_live_... / pk_test_... (Apple/Google Pay PRB)
 *     int_stripe_webhook_secret  whsec_...
 *     int_stripe_currency        'czk'/'eur'/'usd'
 *
 *   GOPAY (CZ karty + bank převod):
 *     int_gopay_enabled          '1'/'0'
 *     int_gopay_environment      'test'/'production'
 *     int_gopay_goid             8-mistné GoID
 *     int_gopay_client_id
 *     int_gopay_client_secret
 *     int_gopay_currency         'CZK'/'EUR'
 *
 *   ZÁSILKOVNA (Packeta):
 *     int_zas_enabled            '1'/'0'
 *     int_zas_api_password       API password z Zásilkovny
 *     int_zas_sender_label       label odesílatele
 *     int_zas_id_sender          ID odesílatele (z administrace Zásilkovny)
 *
 *   DPD CZ:
 *     int_dpd_enabled            '1'/'0'
 *     int_dpd_environment        'test'/'production'
 *     int_dpd_username
 *     int_dpd_password
 *     int_dpd_customer_id        zákaznické číslo DPD
 *
 * Použití (z customer admin endpointů):
 *   $cfg = customer_int_settings('stripe');
 *   if (customer_int_enabled('stripe')) {
 *       customer_int_stripe_create_checkout($order);
 *   }
 */

require_once __DIR__ . '/config.php';

const CUSTOMER_INT_SERVICES = ['stripe', 'gopay', 'paypal', 'zas', 'dpd'];

/**
 * Načte settings pro konkrétní službu.
 */
function customer_int_settings(string $service): array {
    static $cache = [];
    if (isset($cache[$service])) return $cache[$service];
    $pdo = db();
    try {
        $stmt = $pdo->prepare("SELECT klic, hodnota FROM nastaveni WHERE klic LIKE :p");
        $stmt->execute(['p' => 'int_' . $service . '_%']);
        $rows = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
        $cache[$service] = $rows ?: [];
    } catch (Throwable $e) {
        $cache[$service] = [];
    }
    return $cache[$service];
}

function customer_int_enabled(string $service): bool {
    $cfg = customer_int_settings($service);
    return ($cfg['int_' . $service . '_enabled'] ?? '0') === '1';
}

function customer_int_set(string $service, array $values): void {
    $pdo = db();
    $stmt = $pdo->prepare("INSERT INTO nastaveni (klic, hodnota) VALUES (:k, :v) ON DUPLICATE KEY UPDATE hodnota = :v2");
    foreach ($values as $k => $v) {
        // Skip placeholder pwd/secret (8 bullets)
        if (str_contains((string) $v, '••••') || $v === null) continue;
        $stmt->execute([
            'k' => 'int_' . $service . '_' . $k,
            'v' => (string) $v,
            'v2'=> (string) $v,
        ]);
    }
}

/**
 * Vrátí všechny settings se zamaskovanými secrets (pro GUI).
 */
function customer_int_settings_safe(string $service): array {
    $cfg = customer_int_settings($service);
    $secretKeys = ['secret_key', 'webhook_secret', 'client_secret', 'api_password', 'password'];
    $safe = [];
    foreach ($cfg as $k => $v) {
        $shortKey = str_replace('int_' . $service . '_', '', $k);
        if (in_array($shortKey, $secretKeys, true) && $v) {
            $safe[$k] = str_repeat('•', 8);
            $safe[$k . '_set'] = true;
        } else {
            $safe[$k] = $v;
        }
    }
    return $safe;
}

// ════════════════════════════════════════════════════════════════════
// 💳 STRIPE — customer-side
// ════════════════════════════════════════════════════════════════════

function customer_int_stripe_request(string $method, string $endpoint, array $params = []): array {
    $cfg = customer_int_settings('stripe');
    $key = $cfg['int_stripe_secret_key'] ?? '';
    if (!$key) return ['ok' => false, 'error' => 'missing_secret_key'];
    $url = 'https://api.stripe.com/v1' . $endpoint;
    $ch = curl_init($url);
    $opts = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER     => [
            'Authorization: Bearer ' . $key,
            'Stripe-Version: 2024-06-20',
        ],
        CURLOPT_TIMEOUT        => 30,
    ];
    if ($method === 'POST') {
        $opts[CURLOPT_POST]       = true;
        $opts[CURLOPT_POSTFIELDS] = http_build_query($params);
    } elseif ($method === 'GET' && $params) {
        $opts[CURLOPT_URL] = $url . '?' . http_build_query($params);
    }
    curl_setopt_array($ch, $opts);
    $resp = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    $data = json_decode($resp, true);
    if ($code < 200 || $code >= 300) {
        return ['ok' => false, 'error' => $data['error']['message'] ?? "http_$code", 'detail' => $data];
    }
    return ['ok' => true] + ($data ?: []);
}

/**
 * Vytvoří Stripe Checkout pro fakturu nebo objednávku zákazníka.
 *
 * @param array $payload [
 *   'amount_kc' => 12345.0,
 *   'currency' => 'czk',
 *   'description' => 'Faktura FA-2026-0042',
 *   'reference' => 'FA-2026-0042',
 *   'customer_email' => '...',
 *   'return_url' => 'https://customer.cz/b2b/payment-done',
 * ]
 */
function customer_int_stripe_create_checkout(array $payload): array {
    $cfg = customer_int_settings('stripe');
    if (!customer_int_enabled('stripe')) return ['ok' => false, 'error' => 'stripe_disabled'];

    $currency = strtolower($payload['currency'] ?? $cfg['int_stripe_currency'] ?? 'czk');
    $amountCents = (int) round(((float) $payload['amount_kc']) * 100);

    $params = [
        'mode'                       => 'payment',
        'payment_method_types[]'     => 'card',
        'line_items[0][price_data][currency]'     => $currency,
        'line_items[0][price_data][product_data][name]' => $payload['description'] ?? 'Platba',
        'line_items[0][price_data][unit_amount]'  => $amountCents,
        'line_items[0][quantity]'    => 1,
        'success_url'                => $payload['return_url'] . '?session_id={CHECKOUT_SESSION_ID}',
        'cancel_url'                 => $payload['cancel_url'] ?? $payload['return_url'],
        'customer_email'             => $payload['customer_email'] ?? null,
        'client_reference_id'        => $payload['reference'] ?? null,
        'metadata[reference]'        => $payload['reference'] ?? '',
        'payment_method_options[card][request_three_d_secure]' => 'automatic',
        'allow_promotion_codes'      => 'true',
        'billing_address_collection' => 'auto',
    ];
    return customer_int_stripe_request('POST', '/checkout/sessions', $params);
}

function customer_int_stripe_test(): array {
    if (!customer_int_enabled('stripe')) {
        return ['ok' => false, 'error' => 'Stripe není zapnutý nebo chybí klíč.'];
    }
    $r = customer_int_stripe_request('GET', '/balance');
    if (!$r['ok']) return $r;
    return [
        'ok' => true,
        'message' => 'Spojení OK',
        'mode' => str_starts_with($r['livemode'] ?? '' ?: '', '1') ? 'live' : 'test',
    ];
}

// ════════════════════════════════════════════════════════════════════
// 💳 GOPAY — customer-side
// ════════════════════════════════════════════════════════════════════

function customer_int_gopay_base(): string {
    $cfg = customer_int_settings('gopay');
    $env = $cfg['int_gopay_environment'] ?? 'test';
    return $env === 'production' ? 'https://gate.gopay.cz/api' : 'https://gw.sandbox.gopay.com/api';
}

function customer_int_gopay_token(): ?string {
    $cfg = customer_int_settings('gopay');
    $clientId = $cfg['int_gopay_client_id'] ?? '';
    $clientSecret = $cfg['int_gopay_client_secret'] ?? '';
    if (!$clientId || !$clientSecret) return null;
    $url = customer_int_gopay_base() . '/oauth2/token';
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => 'grant_type=client_credentials&scope=payment-create',
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 15,
        CURLOPT_USERPWD        => $clientId . ':' . $clientSecret,
        CURLOPT_HTTPHEADER     => ['Accept: application/json', 'Content-Type: application/x-www-form-urlencoded'],
    ]);
    $resp = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($code !== 200) return null;
    $d = json_decode($resp, true);
    return $d['access_token'] ?? null;
}

function customer_int_gopay_create_payment(array $payload): array {
    if (!customer_int_enabled('gopay')) return ['ok' => false, 'error' => 'gopay_disabled'];
    $cfg = customer_int_settings('gopay');
    $token = customer_int_gopay_token();
    if (!$token) return ['ok' => false, 'error' => 'gopay_auth_failed'];

    $body = [
        'payer' => [
            'default_payment_instrument' => 'PAYMENT_CARD',
            'allowed_payment_instruments' => ['PAYMENT_CARD', 'BANK_ACCOUNT', 'GOPAY'],
            'contact' => [
                'email' => $payload['customer_email'] ?? '',
            ],
        ],
        'target' => [
            'type' => 'ACCOUNT',
            'goid' => (int) ($cfg['int_gopay_goid'] ?? 0),
        ],
        'amount' => (int) round(((float) $payload['amount_kc']) * 100),
        'currency' => strtoupper($cfg['int_gopay_currency'] ?? 'CZK'),
        'order_number' => $payload['reference'] ?? '',
        'order_description' => $payload['description'] ?? 'Platba',
        'callback' => [
            'return_url' => $payload['return_url'] ?? '',
            'notification_url' => $payload['notification_url'] ?? '',
        ],
        'lang' => 'CS',
    ];

    $ch = curl_init(customer_int_gopay_base() . '/payments/payment');
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => json_encode($body),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 15,
        CURLOPT_HTTPHEADER     => [
            'Authorization: Bearer ' . $token,
            'Accept: application/json',
            'Content-Type: application/json',
        ],
    ]);
    $resp = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    $d = json_decode($resp, true);
    if ($code < 200 || $code >= 300) {
        return ['ok' => false, 'error' => $d['errors'][0]['message'] ?? "http_$code", 'detail' => $d];
    }
    return ['ok' => true] + ($d ?: []);
}

function customer_int_gopay_test(): array {
    if (!customer_int_enabled('gopay')) return ['ok' => false, 'error' => 'GoPay není zapnutý nebo chybí klíče.'];
    $token = customer_int_gopay_token();
    if (!$token) return ['ok' => false, 'error' => 'OAuth selhal — zkontroluj client_id / client_secret.'];
    return ['ok' => true, 'message' => 'OAuth OK, token získán.'];
}

// ════════════════════════════════════════════════════════════════════
// 💼 PAYPAL — customer-side (v2.9.209)
// ════════════════════════════════════════════════════════════════════
// Docs: https://developer.paypal.com/docs/api/orders/v2/
// OAuth: POST {base}/v1/oauth2/token Basic {client_id:secret}
// Create order: POST {base}/v2/checkout/orders {intent,purchase_units}
// Capture: POST {base}/v2/checkout/orders/{id}/capture

function customer_int_paypal_base(): string {
    $cfg = customer_int_settings('paypal');
    $env = $cfg['int_paypal_environment'] ?? 'sandbox';
    return $env === 'live'
        ? 'https://api-m.paypal.com'
        : 'https://api-m.sandbox.paypal.com';
}

function customer_int_paypal_token(): ?string {
    $cfg = customer_int_settings('paypal');
    $cid = $cfg['int_paypal_client_id'] ?? '';
    $sec = $cfg['int_paypal_client_secret'] ?? '';
    if (!$cid || !$sec) return null;

    $ch = curl_init(customer_int_paypal_base() . '/v1/oauth2/token');
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_USERPWD => $cid . ':' . $sec,
        CURLOPT_HTTPHEADER => ['Content-Type: application/x-www-form-urlencoded', 'Accept: application/json'],
        CURLOPT_POSTFIELDS => 'grant_type=client_credentials',
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 20,
    ]);
    $resp = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($code !== 200 || !$resp) return null;
    $j = json_decode($resp, true);
    return $j['access_token'] ?? null;
}

function customer_int_paypal_request(string $method, string $endpoint, array $body = []): array {
    $token = customer_int_paypal_token();
    if (!$token) return ['ok' => false, 'error' => 'oauth_failed'];

    $ch = curl_init(customer_int_paypal_base() . $endpoint);
    $opts = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_HTTPHEADER => [
            'Authorization: Bearer ' . $token,
            'Content-Type: application/json',
            'Accept: application/json',
        ],
    ];
    if ($method === 'POST') {
        $opts[CURLOPT_POST] = true;
        if ($body) $opts[CURLOPT_POSTFIELDS] = json_encode($body);
    } elseif ($method !== 'GET') {
        $opts[CURLOPT_CUSTOMREQUEST] = $method;
        if ($body) $opts[CURLOPT_POSTFIELDS] = json_encode($body);
    }
    curl_setopt_array($ch, $opts);
    $resp = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    $data = $resp ? json_decode($resp, true) : null;
    if ($code < 200 || $code >= 300) {
        return [
            'ok' => false,
            'error' => $data['message'] ?? ($data['details'][0]['issue'] ?? "http_$code"),
            'detail' => $data,
        ];
    }
    return ['ok' => true] + ($data ?: []);
}

function customer_int_paypal_create_order(array $payload): array {
    if (!customer_int_enabled('paypal')) return ['ok' => false, 'error' => 'paypal_disabled'];
    $cfg = customer_int_settings('paypal');
    $currency = strtoupper($payload['currency'] ?? $cfg['int_paypal_currency'] ?? 'CZK');
    $amount   = number_format((float) $payload['amount_kc'], 2, '.', '');

    $r = customer_int_paypal_request('POST', '/v2/checkout/orders', [
        'intent' => 'CAPTURE',
        'purchase_units' => [[
            'reference_id' => $payload['order_no'] ?? '',
            'description'  => $payload['description'] ?? 'Platba',
            'amount' => [
                'currency_code' => $currency,
                'value'         => $amount,
            ],
        ]],
        'application_context' => [
            'brand_name'  => 'APPEK',
            'user_action' => 'PAY_NOW',
            'return_url'  => $payload['return_url'] ?? '',
            'cancel_url'  => $payload['cancel_url'] ?? ($payload['return_url'] ?? ''),
        ],
    ]);
    if (!($r['ok'] ?? false)) return $r;

    // Najít approve link
    $approveUrl = null;
    foreach (($r['links'] ?? []) as $l) {
        if (($l['rel'] ?? '') === 'approve' || ($l['rel'] ?? '') === 'payer-action') {
            $approveUrl = $l['href'];
            break;
        }
    }
    return [
        'ok'           => true,
        'order_id'     => $r['id'] ?? null,
        'status'       => $r['status'] ?? null,
        'approve_url'  => $approveUrl,
    ];
}

function customer_int_paypal_capture_order(string $orderId): array {
    if (!customer_int_enabled('paypal')) return ['ok' => false, 'error' => 'paypal_disabled'];
    if (!$orderId) return ['ok' => false, 'error' => 'missing_order_id'];
    $r = customer_int_paypal_request('POST', '/v2/checkout/orders/' . urlencode($orderId) . '/capture', []);
    return $r;
}

function customer_int_paypal_test(): array {
    if (!customer_int_enabled('paypal')) return ['ok' => false, 'error' => 'PayPal není zapnutý nebo chybí klíče.'];
    $token = customer_int_paypal_token();
    if (!$token) return ['ok' => false, 'error' => 'OAuth selhal — zkontroluj client_id / client_secret a prostředí (sandbox/live).'];
    $cfg = customer_int_settings('paypal');
    return [
        'ok' => true,
        'message' => 'OAuth OK — token získán.',
        'environment' => $cfg['int_paypal_environment'] ?? 'sandbox',
    ];
}

// ════════════════════════════════════════════════════════════════════
// 📦 ZÁSILKOVNA — customer-side
// ════════════════════════════════════════════════════════════════════

function customer_int_zas_xml_request(string $xml): array {
    $url = 'https://www.zasilkovna.cz/api/rest';
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $xml,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 30,
        CURLOPT_HTTPHEADER     => ['Content-Type: text/xml'],
    ]);
    $resp = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return ['http' => $code, 'response' => $resp];
}

function customer_int_zas_create_packet(array $payload): array {
    if (!customer_int_enabled('zas')) return ['ok' => false, 'error' => 'zasilkovna_disabled'];
    $cfg = customer_int_settings('zas');
    $apiPassword = $cfg['int_zas_api_password'] ?? '';
    if (!$apiPassword) return ['ok' => false, 'error' => 'missing_api_password'];

    $xml = '<?xml version="1.0" encoding="UTF-8"?>' .
           '<createPacket>' .
           '<apiPassword>' . htmlspecialchars($apiPassword) . '</apiPassword>' .
           '<packetAttributes>' .
           '<number>' . htmlspecialchars($payload['reference'] ?? '') . '</number>' .
           '<name>' . htmlspecialchars($payload['recipient_first_name'] ?? '') . '</name>' .
           '<surname>' . htmlspecialchars($payload['recipient_last_name'] ?? $payload['recipient_name'] ?? '') . '</surname>' .
           '<email>' . htmlspecialchars($payload['recipient_email'] ?? '') . '</email>' .
           '<phone>' . htmlspecialchars($payload['recipient_phone'] ?? '') . '</phone>' .
           '<addressId>' . (int) ($payload['pickup_point_id'] ?? 0) . '</addressId>' .
           '<cod>' . (float) ($payload['cod_kc'] ?? 0) . '</cod>' .
           '<value>' . (float) ($payload['value_kc'] ?? 0) . '</value>' .
           '<weight>' . (float) ($payload['weight_kg'] ?? 1) . '</weight>' .
           '<eshop>' . htmlspecialchars($cfg['int_zas_sender_label'] ?? 'APPEK') . '</eshop>' .
           '</packetAttributes>' .
           '</createPacket>';

    $r = customer_int_zas_xml_request($xml);
    if ($r['http'] >= 400 || !$r['response']) {
        return ['ok' => false, 'error' => 'http_' . $r['http']];
    }
    libxml_use_internal_errors(true);
    $sx = @simplexml_load_string($r['response']);
    if (!$sx) return ['ok' => false, 'error' => 'invalid_response', 'raw' => substr($r['response'], 0, 500)];
    $status = (string) ($sx->status ?? '');
    if ($status === 'fault') {
        return ['ok' => false, 'error' => (string) ($sx->fault ?? 'unknown'), 'detail' => (string) ($sx->detail->message ?? '')];
    }
    $packetId = (string) ($sx->result->id ?? '');
    $barcode = (string) ($sx->result->barcode ?? '');
    return ['ok' => true, 'packet_id' => $packetId, 'barcode' => $barcode];
}

function customer_int_zas_test(): array {
    if (!customer_int_enabled('zas')) return ['ok' => false, 'error' => 'Zásilkovna není zapnutá.'];
    $cfg = customer_int_settings('zas');
    if (empty($cfg['int_zas_api_password'])) return ['ok' => false, 'error' => 'Chybí API password.'];
    // Simulate ping — zkusíme dotazovat existující packet (vrátí not_found ale credentials OK).
    $xml = '<?xml version="1.0" encoding="UTF-8"?><packetStatus><apiPassword>' .
           htmlspecialchars($cfg['int_zas_api_password']) . '</apiPassword><packetId>0</packetId></packetStatus>';
    $r = customer_int_zas_xml_request($xml);
    if ($r['http'] >= 400) return ['ok' => false, 'error' => 'HTTP ' . $r['http']];
    $sx = @simplexml_load_string($r['response']);
    if (!$sx) return ['ok' => false, 'error' => 'Neplatná odpověď.'];
    $fault = (string) ($sx->fault ?? '');
    if ($fault === 'WrongPassword' || $fault === 'wrong_api_password' || stripos((string) ($sx->detail->message ?? ''), 'password') !== false) {
        return ['ok' => false, 'error' => 'Neplatné API heslo.'];
    }
    return ['ok' => true, 'message' => 'Spojení OK — API heslo prošlo.'];
}

// ════════════════════════════════════════════════════════════════════
// 📦 DPD CZ — customer-side
// ════════════════════════════════════════════════════════════════════

function customer_int_dpd_base(): string {
    $cfg = customer_int_settings('dpd');
    $env = $cfg['int_dpd_environment'] ?? 'production';
    return $env === 'test' ? 'https://api.dpd.cz/v1/test' : 'https://api.dpd.cz/v1';
}

function customer_int_dpd_token(): ?string {
    static $token = null;
    if ($token !== null) return $token;
    $cfg = customer_int_settings('dpd');
    $user = $cfg['int_dpd_username'] ?? '';
    $pwd = $cfg['int_dpd_password'] ?? '';
    if (!$user || !$pwd) return null;
    $ch = curl_init(customer_int_dpd_base() . '/auth/login');
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => json_encode(['username' => $user, 'password' => $pwd]),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 15,
        CURLOPT_HTTPHEADER     => ['Content-Type: application/json', 'Accept: application/json'],
    ]);
    $resp = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($code !== 200) return null;
    $d = json_decode($resp, true);
    $token = $d['token'] ?? null;
    return $token;
}

function customer_int_dpd_create_shipment(array $payload): array {
    if (!customer_int_enabled('dpd')) return ['ok' => false, 'error' => 'dpd_disabled'];
    $token = customer_int_dpd_token();
    if (!$token) return ['ok' => false, 'error' => 'dpd_auth_failed'];

    $cfg = customer_int_settings('dpd');
    $body = [
        'customerId' => $cfg['int_dpd_customer_id'] ?? '',
        'reference'  => $payload['reference'] ?? '',
        'recipient'  => [
            'name'    => $payload['recipient_name'] ?? '',
            'street'  => $payload['recipient_street'] ?? '',
            'city'    => $payload['recipient_city'] ?? '',
            'zip'     => $payload['recipient_zip'] ?? '',
            'country' => $payload['recipient_country'] ?? 'CZ',
            'email'   => $payload['recipient_email'] ?? '',
            'phone'   => $payload['recipient_phone'] ?? '',
        ],
        'parcels' => [[
            'weight' => (float) ($payload['weight_kg'] ?? 1),
            'value'  => (float) ($payload['value_kc'] ?? 0),
        ]],
        'cod' => (float) ($payload['cod_kc'] ?? 0),
    ];

    $ch = curl_init(customer_int_dpd_base() . '/shipments');
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => json_encode($body),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 30,
        CURLOPT_HTTPHEADER     => [
            'Authorization: Bearer ' . $token,
            'Content-Type: application/json',
            'Accept: application/json',
        ],
    ]);
    $resp = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    $d = json_decode($resp, true);
    if ($code < 200 || $code >= 300) {
        return ['ok' => false, 'error' => $d['message'] ?? "http_$code", 'detail' => $d];
    }
    return ['ok' => true] + ($d ?: []);
}

function customer_int_dpd_test(): array {
    if (!customer_int_enabled('dpd')) return ['ok' => false, 'error' => 'DPD není zapnutý.'];
    $token = customer_int_dpd_token();
    if (!$token) return ['ok' => false, 'error' => 'Login selhal — zkontroluj username/password.'];
    return ['ok' => true, 'message' => 'Login OK, token získán.'];
}
