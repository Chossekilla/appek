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
 *     int_stripe_currency        (v3.0.643 už se nepoužívá — měna transakce = měna dokladu)
 *
 *   GOPAY (CZ karty + bank převod):
 *     int_gopay_enabled          '1'/'0'
 *     int_gopay_environment      'test'/'production'
 *     int_gopay_goid             8-mistné GoID
 *     int_gopay_client_id
 *     int_gopay_client_secret
 *     int_gopay_currency         'CZK'/'EUR' — v3.0.643: měna, kterou GoID umí NAVÍC ke Kč
 *                                (měna transakce = vždy měna dokladu; viz customer_int_brana_meny)
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

// 🆕 v3.0.31 — POHODA + FlexiBee přidány (full implementace, ne jen na oko)
const CUSTOMER_INT_SERVICES = ['stripe', 'gopay', 'paypal', 'zas', 'dpd', 'ppl', 'cp', 'pohoda', 'flexibee'];

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
// 💱 v3.0.643 — ČÁSTKA + MĚNA PLATBY U BRÁNY (balík B „platba v Kč i EUR")
// ════════════════════════════════════════════════════════════════════
// Měna transakce se bere VÝHRADNĚ z dokladu (payload 'currency'); konfigurační int_*_currency
// už NENÍ zdroj měny (dřív GoPay s int_gopay_currency=EUR strhl Kč částku jako eura).
// 'amount' = částka v měně dokladu (mena_doc_castka); 'amount_kc' = starý alias (zpětná kompatibilita).

/** Měny, které brána umí. GoPay: Kč + měna z nastavení integrace („Měna" = EUR → GoID umí i EUR). */
function customer_int_brana_meny(string $svc): array {
    $vse = defined('MENA_PODPOROVANE') ? array_keys(MENA_PODPOROVANE) : ['CZK', 'EUR', 'USD', 'GBP', 'PLN', 'HUF', 'CHF'];
    if ($svc === 'gopay') {
        $cfg = customer_int_settings('gopay');
        $m = ['CZK'];
        foreach (preg_split('/[\s,;]+/', strtoupper((string) (($cfg['int_gopay_meny'] ?? '') . ',' . ($cfg['int_gopay_currency'] ?? '')))) as $k) {
            if ($k !== '' && in_array($k, ['CZK', 'EUR', 'USD', 'GBP', 'PLN', 'HUF'], true) && !in_array($k, $m, true)) $m[] = $k;
        }
        return $m;
    }
    if ($svc === 'stripe' || $svc === 'paypal') return $vse;
    return ['CZK'];
}

/** Minimální částka platby kartou (Stripe ~0,50 €). Pod ní bránu nevolat. */
function customer_int_min_castka(string $mena): float {
    return ['CZK' => 15.0, 'EUR' => 0.5, 'USD' => 0.5, 'GBP' => 0.3, 'CHF' => 0.5, 'PLN' => 2.0, 'HUF' => 175.0][strtoupper($mena)] ?? 0.5;
}

/**
 * Normalizuje částku + měnu payloadu → ['ok'=>true, amount, currency (UPPER), minor] | ['ok'=>false, error].
 * Měna je POVINNÁ (žádný fallback z konfigurace).
 */
function customer_int_platba_castka(array $payload, string $svc): array {
    $amount = $payload['amount'] ?? $payload['amount_kc'] ?? null;
    if ($amount === null || !is_numeric($amount)) return ['ok' => false, 'error' => 'missing_amount'];
    $cur = strtoupper(trim((string) ($payload['currency'] ?? '')));
    if ($cur === '' || !preg_match('/^[A-Z]{3}$/', $cur)) return ['ok' => false, 'error' => 'missing_currency'];
    if (!in_array($cur, customer_int_brana_meny($svc), true)) return ['ok' => false, 'error' => $svc . '_currency_not_allowed', 'currency' => $cur];
    $amount = round((float) $amount, 2);
    if ($amount < customer_int_min_castka($cur)) return ['ok' => false, 'error' => 'amount_below_minimum', 'min' => customer_int_min_castka($cur)];
    return ['ok' => true, 'amount' => $amount, 'currency' => $cur, 'minor' => (int) round($amount * 100)];
}

/** Připojí query parametr k URL, která už může mít '?'. */
function customer_int_url_param(string $url, string $param): string {
    return $url . (str_contains($url, '?') ? '&' : '?') . $param;
}

/**
 * 🧪 Testovací náhrada bran (jen lokální testy): env APPEK_GW_MOCK = adresář a DB s „test" v názvu.
 *   Požadavky se zapisují do <dir>/gw.jsonl, odpovědi jsou kanonické (žádná síť, žádné peníze).
 *   V produkci env nikdy není → funkce vrací null a vše jde na skutečné API.
 */
function customer_int_mock(string $svc, string $op, array $data = []): ?array {
    $dir = getenv('APPEK_GW_MOCK');
    if (!$dir || !is_dir($dir) || !defined('DB_NAME') || stripos((string) DB_NAME, 'test') === false) return null;
    @file_put_contents(rtrim($dir, '/') . '/gw.jsonl', json_encode(['svc' => $svc, 'op' => $op, 'data' => $data], JSON_UNESCAPED_UNICODE) . "\n", FILE_APPEND | LOCK_EX);
    $id = substr(sha1(json_encode($data) . microtime(true)), 0, 12);
    if ($svc === 'stripe' && $op === 'checkout') return ['ok' => true, 'id' => 'cs_test_mock_' . $id, 'url' => 'https://checkout.stripe.com/c/pay/cs_test_mock_' . $id];
    if ($svc === 'gopay' && $op === 'create') return ['ok' => true, 'id' => (int) hexdec(substr($id, 0, 7)), 'gw_url' => 'https://gw.sandbox.gopay.com/gw/v3/mock' . $id, 'state' => 'CREATED'];
    if ($svc === 'gopay' && $op === 'get') {
        $f = rtrim($dir, '/') . '/gopay_' . (int) ($data['id'] ?? 0) . '.json';
        $j = is_file($f) ? json_decode((string) file_get_contents($f), true) : null;
        return is_array($j) ? ['ok' => true] + $j : ['ok' => false, 'error' => 'http_404'];
    }
    if ($svc === 'paypal' && $op === 'create') return ['ok' => true, 'id' => 'PPMOCK' . strtoupper($id), 'status' => 'CREATED',
        'links' => [['rel' => 'approve', 'href' => 'https://www.sandbox.paypal.com/checkoutnow?token=PPMOCK' . strtoupper($id)]]];
    if ($svc === 'paypal' && $op === 'capture') {
        $f = rtrim($dir, '/') . '/paypal_' . preg_replace('/[^A-Za-z0-9]/', '', (string) ($data['id'] ?? '')) . '.json';
        $j = is_file($f) ? json_decode((string) file_get_contents($f), true) : null;
        return is_array($j) ? ['ok' => true] + $j : ['ok' => false, 'error' => 'http_404'];
    }
    return ['ok' => false, 'error' => 'mock_neznamy_' . $svc . '_' . $op];
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
 * Ověří Stripe-Signature header proti tenant webhook secretu (HMAC SHA-256).
 * Stejný algoritmus jako vendor/_stripe.php, ale bere zákaznický int_stripe_webhook_secret.
 *
 * @return bool|null  true = platný podpis · false = neplatný · null = secret nenakonfigurován (grace)
 */
function customer_int_stripe_verify_signature(string $payload, string $sigHeader): ?bool {
    $cfg = customer_int_settings('stripe');
    $secret = $cfg['int_stripe_webhook_secret'] ?? '';
    if (!$secret) return null; // tenant nemá webhook secret → grace (caller rozhodne)

    // Parse "t=TIMESTAMP,v1=SIG[,v1=SIG2]"
    $parts = [];
    foreach (explode(',', $sigHeader) as $pair) {
        [$k, $v] = array_pad(explode('=', $pair, 2), 2, '');
        $parts[trim($k)] = trim($v);
    }
    if (empty($parts['t']) || empty($parts['v1'])) return false;
    if (abs(time() - (int) $parts['t']) > 300) return false; // tolerance ±5 min (replay ochrana)

    $expected = hash_hmac('sha256', $parts['t'] . '.' . $payload, $secret);
    return hash_equals($expected, (string) $parts['v1']);
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
    if (!customer_int_enabled('stripe')) return ['ok' => false, 'error' => 'stripe_disabled'];
    // 💱 v3.0.643 — částka + měna z DOKLADU (bez fallbacku na int_stripe_currency)
    $c = customer_int_platba_castka($payload, 'stripe');
    if (!$c['ok']) return $c;
    $returnUrl = (string) ($payload['return_url'] ?? '');

    $params = [
        'mode'                       => 'payment',
        'payment_method_types[]'     => 'card',
        'line_items[0][price_data][currency]'     => strtolower($c['currency']),
        'line_items[0][price_data][product_data][name]' => $payload['description'] ?? 'Platba',
        'line_items[0][price_data][unit_amount]'  => $c['minor'],
        'line_items[0][quantity]'    => 1,
        // 🐛 v3.0.643 — return_url už '?' obsahuje (…?paid=X / …/pay/?t=…) → dřív vznikly dva '?'
        'success_url'                => customer_int_url_param($returnUrl, 'session_id={CHECKOUT_SESSION_ID}'),
        'cancel_url'                 => $payload['cancel_url'] ?? $returnUrl,
        'customer_email'             => $payload['customer_email'] ?? null,
        'client_reference_id'        => $payload['reference'] ?? null,
        'metadata[reference]'        => $payload['reference'] ?? '',
        'metadata[mena]'             => $c['currency'],
        'metadata[castka_minor]'     => (string) $c['minor'],
        'payment_method_options[card][request_three_d_secure]' => 'automatic',
        // 🔒 v3.0.643 — zákazník si v Checkoutu NESMÍ zadat vlastní slevový kód Stripe (zaplatil by méně,
        //   webhook částku dřív nekontroloval → objednávka „zaplacena"). Slevy řeší jen naše vouchery.
        'allow_promotion_codes'      => 'false',
        'billing_address_collection' => 'auto',
    ];
    if (($m = customer_int_mock('stripe', 'checkout', $params)) !== null) return $m;
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
        CURLOPT_POSTFIELDS     => 'grant_type=client_credentials&scope=payment-all',
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
    // 💱 v3.0.643 — částka + měna z DOKLADU; měna musí být povolená na GoID (Kč + „Měna" z nastavení)
    $c = customer_int_platba_castka($payload, 'gopay');
    if (!$c['ok']) return $c;

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
        'amount' => $c['minor'],
        'currency' => $c['currency'],
        'order_number' => $payload['reference'] ?? '',
        'order_description' => $payload['description'] ?? 'Platba',
        'callback' => [
            'return_url' => $payload['return_url'] ?? '',
            'notification_url' => $payload['notification_url'] ?? '',
        ],
        'lang' => 'CS',
    ];

    if (($m = customer_int_mock('gopay', 'create', $body)) !== null) return $m;
    $token = customer_int_gopay_token();
    if (!$token) return ['ok' => false, 'error' => 'gopay_auth_failed'];
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

// 🆕 v3.0.323 — načti stav platby z GoPay (pro async notifikaci ?action=gopay_callback).
//   GET {base}/payments/payment/{id} → { state: 'PAID'|'CREATED'|..., order_number, ... }
function customer_int_gopay_get_payment(int $id): array {
    if (!customer_int_enabled('gopay')) return ['ok' => false, 'error' => 'gopay_disabled'];
    if (($m = customer_int_mock('gopay', 'get', ['id' => $id])) !== null) return $m;
    $token = customer_int_gopay_token();
    if (!$token) return ['ok' => false, 'error' => 'gopay_auth_failed'];
    $ch = curl_init(customer_int_gopay_base() . '/payments/payment/' . $id);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 15,
        CURLOPT_HTTPHEADER     => ['Authorization: Bearer ' . $token, 'Accept: application/json'],
    ]);
    $resp = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    $d = json_decode($resp, true);
    if ($code < 200 || $code >= 300) return ['ok' => false, 'error' => "http_$code", 'detail' => $d];
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
    // 💱 v3.0.643 — částka + měna z DOKLADU (bez fallbacku na int_paypal_currency)
    $c = customer_int_platba_castka($payload, 'paypal');
    if (!$c['ok']) return $c;
    $currency = $c['currency'];
    $amount   = number_format($c['amount'], 2, '.', '');

    $req = [
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
    ];
    $r = customer_int_mock('paypal', 'create', $req) ?? customer_int_paypal_request('POST', '/v2/checkout/orders', $req);
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
    if (($m = customer_int_mock('paypal', 'capture', ['id' => $orderId])) !== null) return $m;
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

// 🆕 v3.0.329 — Zásilkovna štítek (PDF) přes packetLabelPdf. Vrací { ok, pdf_base64 }.
function customer_int_zas_label(string $packetId): array {
    if (!customer_int_enabled('zas')) return ['ok' => false, 'error' => 'zasilkovna_disabled'];
    $cfg = customer_int_settings('zas');
    $apiPassword = $cfg['int_zas_api_password'] ?? '';
    if (!$apiPassword) return ['ok' => false, 'error' => 'missing_api_password'];
    $xml = '<?xml version="1.0" encoding="UTF-8"?>' .
           '<packetLabelPdf><apiPassword>' . htmlspecialchars($apiPassword) . '</apiPassword>' .
           '<packetId>' . htmlspecialchars($packetId) . '</packetId>' .
           '<format>A7 on A4</format><offset>0</offset></packetLabelPdf>';
    $r = customer_int_zas_xml_request($xml);
    if (($r['http'] ?? 500) >= 400 || empty($r['response'])) return ['ok' => false, 'error' => 'http_' . ($r['http'] ?? '?')];
    libxml_use_internal_errors(true);
    $sx = @simplexml_load_string($r['response']);
    if (!$sx) return ['ok' => false, 'error' => 'invalid_response'];
    if ((string) ($sx->status ?? '') === 'fault') return ['ok' => false, 'error' => (string) ($sx->fault ?? 'unknown')];
    $pdf = (string) ($sx->result ?? '');
    if ($pdf === '') return ['ok' => false, 'error' => 'no_label'];
    return ['ok' => true, 'pdf_base64' => $pdf];
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

// ════════════════════════════════════════════════════════════════════
// 📦 PPL — myAPI2 (CPL) REST, OAuth2 client_credentials. (v3.0.337, díl 2/2)
//   Settings 'int_ppl_*': enabled, client_id, client_secret, environment(test|production),
//                         product (BUSCMN/…), sender_name. ⚠️ klíče = TVŮJ účet PPL.
// ════════════════════════════════════════════════════════════════════
function customer_int_ppl_base(): string {
    $cfg = customer_int_settings('ppl');
    $env = $cfg['int_ppl_environment'] ?? 'production';
    return $env === 'test' ? 'https://api-dev.dhl.com/ecs/ppl/myapi2' : 'https://api.dhl.com/ecs/ppl/myapi2';
}

function customer_int_ppl_token(): ?string {
    static $t = null;
    if ($t !== null) return $t ?: null;
    $cfg = customer_int_settings('ppl');
    $id = $cfg['int_ppl_client_id'] ?? '';
    $secret = $cfg['int_ppl_client_secret'] ?? '';
    if (!$id || !$secret) { $t = ''; return null; }
    $ch = curl_init(customer_int_ppl_base() . '/login/getAccessToken');
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => http_build_query(['grant_type' => 'client_credentials', 'client_id' => $id, 'client_secret' => $secret, 'scope' => 'myapi2']),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 15,
        CURLOPT_HTTPHEADER     => ['Content-Type: application/x-www-form-urlencoded', 'Accept: application/json'],
    ]);
    $resp = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($code !== 200) { $t = ''; return null; }
    $d = json_decode($resp, true);
    $t = $d['access_token'] ?? '';
    return $t ?: null;
}

function customer_int_ppl_create_shipment(array $payload): array {
    if (!customer_int_enabled('ppl')) return ['ok' => false, 'error' => 'ppl_disabled'];
    $token = customer_int_ppl_token();
    if (!$token) return ['ok' => false, 'error' => 'ppl_auth_failed', 'hint' => 'Zkontroluj client_id / client_secret (PPL myAPI2).'];
    $cfg = customer_int_settings('ppl');
    $cod = (float) ($payload['cod_kc'] ?? 0);
    $shipment = [
        'referenceId'         => (string) ($payload['reference'] ?? ''),
        'productType'         => $cfg['int_ppl_product'] ?? 'BUSCMN',
        'note'                => (string) ($payload['reference'] ?? ''),
        'weighedShipmentInfo' => ['weight' => (float) ($payload['weight_kg'] ?? 1)],
        'shipmentSet'         => ['numberOfShipments' => 1],
        'sender'              => ['name' => $cfg['int_ppl_sender_name'] ?? ''],
        'recipient'           => [
            'name'    => $payload['recipient_name'] ?? '',
            'street'  => $payload['recipient_street'] ?? '',
            'city'    => $payload['recipient_city'] ?? '',
            'zipCode' => $payload['recipient_zip'] ?? '',
            'country' => $payload['recipient_country'] ?? 'CZ',
            'email'   => $payload['recipient_email'] ?? '',
            'phone'   => $payload['recipient_phone'] ?? '',
        ],
    ];
    if ($cod > 0) {
        $shipment['cashOnDelivery'] = ['codPrice' => $cod, 'codCurrency' => 'CZK', 'codVarSym' => preg_replace('/\D/', '', (string) ($payload['reference'] ?? ''))];
    }
    $ch = curl_init(customer_int_ppl_base() . '/shipment/batch');
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => json_encode(['shipments' => [$shipment]]),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 30,
        CURLOPT_HTTPHEADER     => ['Authorization: Bearer ' . $token, 'Content-Type: application/json', 'Accept: application/json'],
    ]);
    $resp = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    $d = json_decode($resp, true);
    if ($code < 200 || $code >= 300) {
        return ['ok' => false, 'error' => $d['detail'] ?? $d['message'] ?? "http_$code", 'detail' => $d];
    }
    // PPL batch je asynchronní — číslo zásilky přijde přes import status; vrátíme co je k dispozici.
    $tracking = $d['shipmentNumbers'][0] ?? $d['batchId'] ?? ($d['importState'] ?? null);
    return ['ok' => true, 'tracking' => $tracking !== null ? (string) $tracking : null, 'raw' => $d];
}

function customer_int_ppl_label(string $tracking): array {
    if (!customer_int_enabled('ppl')) return ['ok' => false, 'error' => 'ppl_disabled'];
    $token = customer_int_ppl_token();
    if (!$token) return ['ok' => false, 'error' => 'ppl_auth_failed'];
    $ch = curl_init(customer_int_ppl_base() . '/shipment/' . rawurlencode($tracking) . '/label?fields=Pdf');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 20,
        CURLOPT_HTTPHEADER     => ['Authorization: Bearer ' . $token, 'Accept: application/pdf'],
    ]);
    $resp = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($code !== 200 || !$resp) return ['ok' => false, 'error' => "label_http_$code"];
    return ['ok' => true, 'pdf_base64' => base64_encode($resp)];
}

function customer_int_ppl_test(): array {
    if (!customer_int_enabled('ppl')) return ['ok' => false, 'error' => 'PPL není zapnutý.'];
    return customer_int_ppl_token() ? ['ok' => true, 'message' => 'PPL token OK.'] : ['ok' => false, 'error' => 'Login selhal — client_id / client_secret.'];
}

// ════════════════════════════════════════════════════════════════════
// 🟡 Česká pošta — REST (Podání Online / B2B). (v3.0.337, díl 2/2)
//   Settings 'int_cp_*': enabled, api_url (dle smlouvy), api_key/token, customer_id,
//                        sender_name. ⚠️ ČP API je smluvně specifické — endpoint = TVŮJ.
// ════════════════════════════════════════════════════════════════════
function customer_int_cp_create_shipment(array $payload): array {
    if (!customer_int_enabled('cp')) return ['ok' => false, 'error' => 'cp_disabled'];
    $cfg = customer_int_settings('cp');
    $url = rtrim($cfg['int_cp_api_url'] ?? '', '/');
    $key = $cfg['int_cp_api_key'] ?? ($cfg['int_cp_token'] ?? '');
    if (!$url || !$key) {
        return ['ok' => false, 'error' => 'cp_not_configured', 'hint' => 'Doplň int_cp_api_url + int_cp_api_key (dle smlouvy ČP Podání Online).'];
    }
    $body = [
        'customerId' => $cfg['int_cp_customer_id'] ?? '',
        'reference'  => $payload['reference'] ?? '',
        'service'    => $cfg['int_cp_service'] ?? 'BA', // BA = Balík Do ruky
        'sender'     => ['name' => $cfg['int_cp_sender_name'] ?? ''],
        'recipient'  => [
            'name'    => $payload['recipient_name'] ?? '',
            'street'  => $payload['recipient_street'] ?? '',
            'city'    => $payload['recipient_city'] ?? '',
            'zip'     => $payload['recipient_zip'] ?? '',
            'country' => $payload['recipient_country'] ?? 'CZ',
            'email'   => $payload['recipient_email'] ?? '',
            'phone'   => $payload['recipient_phone'] ?? '',
        ],
        'weightKg'   => (float) ($payload['weight_kg'] ?? 1),
        'codAmount'  => (float) ($payload['cod_kc'] ?? 0),
        'codCurrency' => 'CZK',
    ];
    $ch = curl_init($url . '/shipments');
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => json_encode($body),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 30,
        CURLOPT_HTTPHEADER     => ['Authorization: Bearer ' . $key, 'Content-Type: application/json', 'Accept: application/json'],
    ]);
    $resp = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    $d = json_decode($resp, true);
    if ($code < 200 || $code >= 300) {
        return ['ok' => false, 'error' => $d['message'] ?? "http_$code", 'detail' => $d];
    }
    $tracking = $d['trackingNumber'] ?? $d['parcelId'] ?? $d['id'] ?? null;
    return ['ok' => true, 'tracking' => $tracking !== null ? (string) $tracking : null, 'label_url' => $d['labelUrl'] ?? null, 'raw' => $d];
}

function customer_int_cp_label(string $tracking): array {
    if (!customer_int_enabled('cp')) return ['ok' => false, 'error' => 'cp_disabled'];
    $cfg = customer_int_settings('cp');
    $url = rtrim($cfg['int_cp_api_url'] ?? '', '/');
    $key = $cfg['int_cp_api_key'] ?? ($cfg['int_cp_token'] ?? '');
    if (!$url || !$key) return ['ok' => false, 'error' => 'cp_not_configured'];
    $ch = curl_init($url . '/shipments/' . rawurlencode($tracking) . '/label');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 20,
        CURLOPT_HTTPHEADER     => ['Authorization: Bearer ' . $key, 'Accept: application/pdf'],
    ]);
    $resp = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($code !== 200 || !$resp) return ['ok' => false, 'error' => "label_http_$code"];
    return ['ok' => true, 'pdf_base64' => base64_encode($resp)];
}

function customer_int_cp_test(): array {
    if (!customer_int_enabled('cp')) return ['ok' => false, 'error' => 'Česká pošta není zapnutá.'];
    $cfg = customer_int_settings('cp');
    if (empty($cfg['int_cp_api_url']) || empty($cfg['int_cp_api_key'] ?? $cfg['int_cp_token'] ?? '')) {
        return ['ok' => false, 'error' => 'Doplň API URL + klíč (dle smlouvy ČP).'];
    }
    return ['ok' => true, 'message' => 'Konfigurace ČP vyplněna (ověření proběhne při 1. zásilce).'];
}

// ════════════════════════════════════════════════════════════════════
// 📊 POHODA mServer — XML API
//   Settings (nastaveni table, klic 'int_pohoda_*'):
//     int_pohoda_enabled, int_pohoda_url (https://mserver:444), int_pohoda_username,
//     int_pohoda_password, int_pohoda_ico (firma IČO pro STW header)
// ════════════════════════════════════════════════════════════════════

function customer_int_pohoda_request(string $xml): array {
    if (!customer_int_enabled('pohoda')) return ['ok' => false, 'error' => 'pohoda_disabled'];
    $cfg = customer_int_settings('pohoda');
    $url = rtrim($cfg['int_pohoda_url'] ?? '', '/') . '/xml';
    $user = $cfg['int_pohoda_username'] ?? '';
    $pass = $cfg['int_pohoda_password'] ?? '';
    $ico  = $cfg['int_pohoda_ico'] ?? '';
    if (!$url || !$user || !$pass) return ['ok' => false, 'error' => 'missing_credentials'];

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $xml,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => [
            'Content-Type: text/xml; charset=utf-8',
            'STW-Authorization: Basic ' . base64_encode("$user:$pass"),
            'STW-Application: APPEK/3.0',
            'STW-Instance: ' . $ico,
        ],
        CURLOPT_TIMEOUT => 30,
        CURLOPT_SSL_VERIFYPEER => false, // Pohoda často self-signed
        CURLOPT_SSL_VERIFYHOST => 0,
    ]);
    $resp = curl_exec($ch);
    $http = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err  = curl_error($ch);
    curl_close($ch);

    if ($resp === false) return ['ok' => false, 'error' => 'curl: ' . $err];
    if ($http >= 400) return ['ok' => false, 'error' => "HTTP $http", 'response' => $resp];
    return ['ok' => true, 'http' => $http, 'response' => $resp];
}

function customer_int_pohoda_send_invoice(array $invoice): array {
    $ico = customer_int_settings('pohoda')['int_pohoda_ico'] ?? '00000000';
    $xml = '<?xml version="1.0" encoding="windows-1250"?>' .
        '<dat:dataPack version="2.0" id="appek_' . time() . '" ico="' . htmlspecialchars($ico, ENT_QUOTES) . '" application="APPEK" note="Export from APPEK B2B" ' .
        'xmlns:dat="http://www.stormware.cz/schema/version_2/data.xsd" xmlns:inv="http://www.stormware.cz/schema/version_2/invoice.xsd" xmlns:typ="http://www.stormware.cz/schema/version_2/type.xsd">' .
        '<dat:dataPackItem version="2.0" id="' . htmlspecialchars($invoice['cislo'] ?? 'INV', ENT_QUOTES) . '">' .
        '<inv:invoice version="2.0">' .
        '<inv:invoiceHeader>' .
        '<inv:invoiceType>issuedInvoice</inv:invoiceType>' .
        '<inv:date>' . htmlspecialchars($invoice['datum_vystaveni'] ?? date('Y-m-d')) . '</inv:date>' .
        '<inv:dateDue>' . htmlspecialchars($invoice['datum_splatnosti'] ?? date('Y-m-d', strtotime('+14 days'))) . '</inv:dateDue>' .
        '<inv:text>' . htmlspecialchars($invoice['popis'] ?? 'Zboží') . '</inv:text>' .
        '<inv:partnerIdentity><typ:address>' .
        '<typ:company>' . htmlspecialchars($invoice['odberatel_nazev'] ?? '') . '</typ:company>' .
        '<typ:ico>' . htmlspecialchars($invoice['odberatel_ico'] ?? '') . '</typ:ico>' .
        '<typ:dic>' . htmlspecialchars($invoice['odberatel_dic'] ?? '') . '</typ:dic>' .
        '</typ:address></inv:partnerIdentity>' .
        '</inv:invoiceHeader>' .
        '<inv:invoiceSummary>' .
        '<inv:homeCurrency><typ:priceNone>' . number_format((float)($invoice['castka_celkem'] ?? 0), 2, '.', '') . '</typ:priceNone></inv:homeCurrency>' .
        '</inv:invoiceSummary>' .
        '</inv:invoice>' .
        '</dat:dataPackItem>' .
        '</dat:dataPack>';
    return customer_int_pohoda_request($xml);
}

function customer_int_pohoda_test(): array {
    if (!customer_int_enabled('pohoda')) return ['ok' => false, 'error' => 'POHODA není zapnutý.'];
    // Simple list query — vrátí prvních pár faktur
    $ico = customer_int_settings('pohoda')['int_pohoda_ico'] ?? '';
    $xml = '<?xml version="1.0" encoding="windows-1250"?>' .
        '<dat:dataPack version="2.0" id="test" ico="' . htmlspecialchars($ico) . '" application="APPEK" ' .
        'xmlns:dat="http://www.stormware.cz/schema/version_2/data.xsd" xmlns:lst="http://www.stormware.cz/schema/version_2/list.xsd">' .
        '<dat:dataPackItem version="2.0" id="t1"><lst:listInvoiceRequest version="2.0" invoiceType="issuedInvoice" invoiceVersion="2.0"><lst:restrictionDate>' . date('Y-m-d', strtotime('-7 days')) . '</lst:restrictionDate></lst:listInvoiceRequest></dat:dataPackItem>' .
        '</dat:dataPack>';
    $r = customer_int_pohoda_request($xml);
    if (!$r['ok']) return $r;
    return ['ok' => true, 'message' => 'Spojení s POHODA mServerem OK', 'http' => $r['http']];
}

// ════════════════════════════════════════════════════════════════════
// 📊 FlexiBee REST — JSON API (ABRA Flexi)
//   Settings:
//     int_flexibee_enabled, int_flexibee_url (https://demo.flexibee.eu:5434),
//     int_flexibee_company (firma_s_r_o), int_flexibee_username, int_flexibee_password
// ════════════════════════════════════════════════════════════════════

function customer_int_flexibee_request(string $method, string $endpoint, ?array $data = null): array {
    if (!customer_int_enabled('flexibee')) return ['ok' => false, 'error' => 'flexibee_disabled'];
    $cfg = customer_int_settings('flexibee');
    $base = rtrim($cfg['int_flexibee_url'] ?? '', '/');
    $company = $cfg['int_flexibee_company'] ?? '';
    $user = $cfg['int_flexibee_username'] ?? '';
    $pass = $cfg['int_flexibee_password'] ?? '';
    if (!$base || !$company || !$user || !$pass) return ['ok' => false, 'error' => 'missing_credentials'];

    $url = $base . '/c/' . rawurlencode($company) . '/' . $endpoint;
    $ch = curl_init($url);
    $opts = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_USERPWD => "$user:$pass",
        CURLOPT_HTTPHEADER => ['Accept: application/json', 'Content-Type: application/json'],
        CURLOPT_TIMEOUT => 30,
        CURLOPT_SSL_VERIFYPEER => false,
    ];
    if ($data !== null) $opts[CURLOPT_POSTFIELDS] = json_encode($data);
    curl_setopt_array($ch, $opts);
    $resp = curl_exec($ch);
    $http = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err  = curl_error($ch);
    curl_close($ch);
    if ($resp === false) return ['ok' => false, 'error' => 'curl: ' . $err];
    $json = json_decode($resp, true);
    if ($http >= 400) return ['ok' => false, 'error' => "HTTP $http", 'response' => $json ?: $resp];
    return ['ok' => true, 'data' => $json];
}

function customer_int_flexibee_send_invoice(array $invoice): array {
    $data = ['winstrom' => ['faktura-vydana' => [[
        'kod' => $invoice['cislo'] ?? 'INV-' . time(),
        'typDokl' => 'code:FAKTURA',
        'datVyst' => $invoice['datum_vystaveni'] ?? date('Y-m-d'),
        'datSplat' => $invoice['datum_splatnosti'] ?? date('Y-m-d', strtotime('+14 days')),
        'popis' => $invoice['popis'] ?? 'Zboží',
        'sumZklZakl' => (float)($invoice['castka_bez_dph'] ?? 0),
        'sumZklZakl' => (float)($invoice['castka_bez_dph'] ?? 0),
        'sumCelkSDan' => (float)($invoice['castka_celkem'] ?? 0),
        'partner' => ['kod' => 'code:' . ($invoice['odberatel_kod'] ?? 'AUTO')],
    ]]]];
    return customer_int_flexibee_request('PUT', 'faktura-vydana.json', $data);
}

function customer_int_flexibee_test(): array {
    if (!customer_int_enabled('flexibee')) return ['ok' => false, 'error' => 'FlexiBee není zapnutý.'];
    // /status endpoint — ping
    $r = customer_int_flexibee_request('GET', 'status.json');
    if (!$r['ok']) return $r;
    return ['ok' => true, 'message' => 'Spojení s FlexiBee OK', 'data' => $r['data']];
}
