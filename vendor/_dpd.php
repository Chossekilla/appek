<?php
/**
 * 🚚 DPD CZ — REST API client (DPD Shipping Service / SOAP gateway).
 *
 * Dokumentace: https://docs.dpd.cz (vyžaduje obchodní účet)
 *
 * Workflow:
 *   1. dpd_create_shipment() → vytvoří zásilku, vrátí parcel_number + barcode
 *   2. dpd_label_pdf() → stáhne PDF štítek
 *   3. dpd_track() → sledování stavu
 *
 * Config v vendor_settings:
 *   dpd_enabled         '1' / '0'
 *   dpd_api_url         API endpoint (sandbox/prod podle smlouvy)
 *   dpd_client_id       OAuth client ID
 *   dpd_client_secret   OAuth client secret
 *   dpd_sender_id       ID odesílatele
 *   dpd_environment     'sandbox' / 'production'
 *
 * Pozn.: DPD vyžaduje B2B kontrakt — pro získání credentials kontaktuj
 *        obchodního zástupce DPD CZ (https://www.dpd.cz/business/).
 */

require_once __DIR__ . '/_mail.php';

const DPD_API_PROD    = 'https://api.dpd.cz/shipping';
const DPD_API_SANDBOX = 'https://api-sandbox.dpd.cz/shipping';

function dpd_settings(): array {
    static $cache = null;
    if ($cache !== null) return $cache;
    require_once __DIR__ . '/_lib.php';
    $pdo = vendor_db();
    vendor_ensure_settings_table($pdo);
    $rows = $pdo->query("SELECT `key`, `value` FROM vendor_settings WHERE `key` LIKE 'dpd_%'")->fetchAll(PDO::FETCH_KEY_PAIR);
    $cache = array_merge([
        'dpd_enabled'       => '0',
        'dpd_environment'   => 'sandbox',
        'dpd_client_id'     => '',
        'dpd_client_secret' => '',
        'dpd_sender_id'     => '',
    ], $rows);
    return $cache;
}

function dpd_api_base(): string {
    $cfg = dpd_settings();
    return $cfg['dpd_environment'] === 'production' ? DPD_API_PROD : DPD_API_SANDBOX;
}

/**
 * OAuth 2.0 token (cached pro request).
 */
function dpd_get_token(): ?string {
    static $token = null;
    if ($token !== null) return $token;
    $cfg = dpd_settings();
    if (!$cfg['dpd_client_id'] || !$cfg['dpd_client_secret']) return null;

    $ch = curl_init(dpd_api_base() . '/oauth/token');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => http_build_query([
            'grant_type'    => 'client_credentials',
            'client_id'     => $cfg['dpd_client_id'],
            'client_secret' => $cfg['dpd_client_secret'],
        ]),
        CURLOPT_HTTPHEADER     => ['Content-Type: application/x-www-form-urlencoded'],
        CURLOPT_TIMEOUT        => 15,
    ]);
    $resp = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($code !== 200) {
        error_log("dpd_get_token failed: HTTP $code");
        return null;
    }
    $data = json_decode($resp, true);
    $token = $data['access_token'] ?? null;
    return $token;
}

/**
 * Vytvoří zásilku.
 *
 * @param array $data [
 *   'order_no', 'recipient_name', 'recipient_street', 'recipient_city',
 *   'recipient_zip', 'recipient_country' (2-letter), 'recipient_email',
 *   'recipient_phone', 'weight_kg', 'value_kc', 'cod_kc'
 * ]
 */
function dpd_create_shipment(array $data): array {
    $cfg = dpd_settings();
    if ($cfg['dpd_enabled'] !== '1') {
        return ['ok' => false, 'error' => 'dpd_disabled'];
    }
    $token = dpd_get_token();
    if (!$token) {
        return ['ok' => false, 'error' => 'oauth_failed'];
    }

    $payload = [
        'senderId' => $cfg['dpd_sender_id'],
        'reference' => $data['order_no'],
        'recipient' => [
            'name'    => $data['recipient_name'],
            'street'  => $data['recipient_street'] ?? '',
            'city'    => $data['recipient_city'] ?? '',
            'zip'     => $data['recipient_zip'] ?? '',
            'country' => strtoupper($data['recipient_country'] ?? 'CZ'),
            'email'   => $data['recipient_email'] ?? '',
            'phone'   => $data['recipient_phone'] ?? '',
        ],
        'parcels' => [[
            'weight' => (float) ($data['weight_kg'] ?? 1.0),
            'value'  => (float) $data['value_kc'],
        ]],
        'productCode' => 'CL',  // CL = classic, DPD standard
        'codAmount'   => (float) ($data['cod_kc'] ?? 0),
        'codCurrency' => 'CZK',
    ];

    $ch = curl_init(dpd_api_base() . '/shipments');
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
        error_log("dpd_create_shipment failed: HTTP $code · $resp");
        return ['ok' => false, 'error' => "http_$code", 'detail' => $resp];
    }
    $data = json_decode($resp, true);
    return [
        'ok'             => true,
        'shipment_id'    => (string) ($data['shipmentId'] ?? ''),
        'parcel_number'  => (string) ($data['parcelNumber'] ?? ''),
        'barcode'        => (string) ($data['barcode'] ?? ''),
        'tracking_url'   => $data['trackingUrl'] ?? null,
        'raw'            => $data,
    ];
}

/**
 * Stáhne PDF štítek pro zásilku.
 */
function dpd_label_pdf(string $shipmentId, string $format = 'A6'): ?string {
    $token = dpd_get_token();
    if (!$token) return null;

    $ch = curl_init(dpd_api_base() . '/shipments/' . urlencode($shipmentId) . '/label?format=' . urlencode($format));
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER     => [
            'Authorization: Bearer ' . $token,
            'Accept: application/pdf',
        ],
        CURLOPT_TIMEOUT        => 30,
    ]);
    $resp = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($code !== 200) return null;
    return $resp; // binary PDF
}

/**
 * Sledování zásilky.
 */
function dpd_track(string $parcelNumber): array {
    $token = dpd_get_token();
    if (!$token) return ['ok' => false, 'error' => 'oauth_failed'];

    $ch = curl_init(dpd_api_base() . '/tracking/' . urlencode($parcelNumber));
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
        'status'     => $data['status'] ?? null,
        'events'     => $data['events'] ?? [],
        'delivered'  => ($data['status'] ?? '') === 'DELIVERED',
    ];
}
