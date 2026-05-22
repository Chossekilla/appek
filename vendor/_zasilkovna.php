<?php
/**
 * 📦 ZÁSILKOVNA (Packeta) — REST API client.
 *
 * Dokumentace: https://docs.packetery.com/03-creating-packets/01-data-for-creating-packets.html
 *
 * Workflow:
 *   1. Klient si na webu zvolí výdejní místo (Widget v2 v JS — vrátí pickup_point_id)
 *   2. Po platbě se zavolá packeta_create_packet() → vytvoří zásilku v API
 *   3. packeta_label_pdf() vygeneruje štítek (PDF)
 *   4. packeta_packet_status() umožní sledování
 *
 * Config v vendor_settings:
 *   packeta_enabled        '1' / '0'
 *   packeta_api_password   tvůj API password (z administrace Zásilkovny)
 *   packeta_api_id         tvoje API ID (sender ID)
 *   packeta_widget_key     widget veřejný klíč pro výběr výdejního místa
 *   packeta_environment    'production' (jen produkční API existuje)
 */

const PACKETA_API_URL = 'https://www.zasilkovna.cz/api/rest';

function packeta_settings(): array {
    static $cache = null;
    if ($cache !== null) return $cache;
    require_once __DIR__ . '/_lib.php';
    require_once __DIR__ . '/_mail.php';
    $pdo = vendor_db();
    vendor_ensure_settings_table($pdo);
    $rows = $pdo->query("SELECT `key`, `value` FROM vendor_settings WHERE `key` LIKE 'packeta_%'")->fetchAll(PDO::FETCH_KEY_PAIR);
    $cache = array_merge([
        'packeta_enabled'       => '0',
        'packeta_api_password'  => '',
        'packeta_api_id'        => '',
        'packeta_widget_key'    => '',
    ], $rows);
    return $cache;
}

/**
 * Pošle XML request a vrátí parsed odpověď.
 */
function packeta_request(string $xml): ?SimpleXMLElement {
    $ch = curl_init(PACKETA_API_URL);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $xml,
        CURLOPT_HTTPHEADER     => ['Content-Type: text/xml; charset=UTF-8'],
        CURLOPT_TIMEOUT        => 30,
    ]);
    $resp = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($code !== 200 || !$resp) {
        error_log("packeta_request failed: HTTP $code");
        return null;
    }
    $xml = @simplexml_load_string($resp);
    return $xml ?: null;
}

/**
 * Vytvoří packet (zásilku).
 *
 * @param array $data [
 *   'order_no'         => 'OBJ-2026-001',
 *   'pickup_point_id'  => '79',         // z widgetu (uvolnit místo Packety)
 *   'recipient_name'   => 'Jan Novák',
 *   'recipient_surname'=> 'Novák',
 *   'recipient_email'  => 'jan@example.cz',
 *   'recipient_phone'  => '+420777111222',
 *   'value_kc'         => 1280.00,      // hodnota zásilky pro pojistku
 *   'weight_kg'        => 1.5,
 *   'cod_kc'           => 0,            // dobírka (0 = bez)
 * ]
 *
 * @return array ['ok' => bool, 'packet_id' => string|null, 'barcode' => string|null, 'error' => string|null]
 */
function packeta_create_packet(array $data): array {
    $cfg = packeta_settings();
    if ($cfg['packeta_enabled'] !== '1') {
        return ['ok' => false, 'error' => 'packeta_disabled'];
    }
    if (!$cfg['packeta_api_password']) {
        return ['ok' => false, 'error' => 'api_password_missing'];
    }

    // Rozdělit jméno na firstName + lastName pokud nebylo splitted
    $name = trim($data['recipient_name'] ?? '');
    $names = explode(' ', $name, 2);
    $firstName = $names[0] ?? '';
    $lastName  = $data['recipient_surname'] ?? ($names[1] ?? '');

    $xml = sprintf(
        '<?xml version="1.0" encoding="utf-8"?>
<createPacket>
  <apiPassword>%s</apiPassword>
  <packetAttributes>
    <number>%s</number>
    <name>%s</name>
    <surname>%s</surname>
    <email>%s</email>
    <phone>%s</phone>
    <addressId>%d</addressId>
    <cod>%s</cod>
    <value>%s</value>
    <weight>%s</weight>
    <eshop>APPEK</eshop>
  </packetAttributes>
</createPacket>',
        htmlspecialchars($cfg['packeta_api_password']),
        htmlspecialchars($data['order_no']),
        htmlspecialchars($firstName),
        htmlspecialchars($lastName),
        htmlspecialchars($data['recipient_email']),
        htmlspecialchars($data['recipient_phone'] ?? ''),
        (int) $data['pickup_point_id'],
        number_format((float) ($data['cod_kc'] ?? 0), 2, '.', ''),
        number_format((float) $data['value_kc'], 2, '.', ''),
        number_format((float) ($data['weight_kg'] ?? 1), 2, '.', '')
    );

    $resp = packeta_request($xml);
    if (!$resp) return ['ok' => false, 'error' => 'request_failed'];

    if ((string) $resp->status === 'fault') {
        return [
            'ok' => false,
            'error' => 'api_fault',
            'detail' => (string) $resp->fault,
            'detail_string' => (string) $resp->string,
        ];
    }

    return [
        'ok'        => true,
        'packet_id' => (string) $resp->result->id,
        'barcode'   => (string) $resp->result->barcode,
        'barcode_text' => (string) $resp->result->barcodeText,
    ];
}

/**
 * Stáhne PDF štítek pro zásilku.
 *
 * @return string|null  Binární PDF data, null při chybě.
 */
function packeta_label_pdf(string $packetId, string $format = 'A6 on A4'): ?string {
    $cfg = packeta_settings();
    if (!$cfg['packeta_api_password']) return null;

    $xml = sprintf(
        '<?xml version="1.0" encoding="utf-8"?>
<packetLabelPdf>
  <apiPassword>%s</apiPassword>
  <packetId>%s</packetId>
  <format>%s</format>
  <offset>0</offset>
</packetLabelPdf>',
        htmlspecialchars($cfg['packeta_api_password']),
        htmlspecialchars($packetId),
        htmlspecialchars($format)
    );

    $resp = packeta_request($xml);
    if (!$resp || (string) $resp->status === 'fault') return null;

    $b64 = (string) $resp->result;
    return base64_decode($b64) ?: null;
}

/**
 * Zjistí stav zásilky podle ID.
 */
function packeta_packet_status(string $packetId): array {
    $cfg = packeta_settings();
    $xml = sprintf(
        '<?xml version="1.0" encoding="utf-8"?>
<packetStatus>
  <apiPassword>%s</apiPassword>
  <packetId>%s</packetId>
</packetStatus>',
        htmlspecialchars($cfg['packeta_api_password']),
        htmlspecialchars($packetId)
    );

    $resp = packeta_request($xml);
    if (!$resp) return ['ok' => false, 'error' => 'request_failed'];
    if ((string) $resp->status === 'fault') {
        return ['ok' => false, 'error' => (string) $resp->string];
    }
    return [
        'ok'         => true,
        'status_code'=> (string) $resp->result->statusCode,
        'status_text'=> (string) $resp->result->codeText,
        'date_time'  => (string) $resp->result->dateTime,
    ];
}
