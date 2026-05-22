<?php
/**
 * 🏢 BUSINESS INFO — public read-only endpoint.
 *
 * GET /api/business_info.php
 *   → JSON s business_* poli z vendor_settings.
 *
 * Sensitivní pole (bank_account, IBAN, SWIFT) vrací jen pokud
 * `?include_bank=1` a request přichází z localhost (interní).
 *
 * Použito v legal docs pro substituci [Doplnit ...] placeholders.
 */

header('Content-Type: application/json; charset=UTF-8');
header('Access-Control-Allow-Origin: *');
header('Cache-Control: public, max-age=60'); // krátký cache

$vendorRoot = realpath(__DIR__ . '/..') . '/vendor';
if (!is_dir($vendorRoot)) {
    echo json_encode(['error' => 'vendor_not_available']);
    exit;
}
require_once $vendorRoot . '/_lib.php';
require_once $vendorRoot . '/_mail.php';

try {
    $pdo = vendor_db();
    $rows = $pdo->query("SELECT `key`, `value` FROM vendor_settings WHERE `key` LIKE 'business_%'")
                ->fetchAll(PDO::FETCH_KEY_PAIR);

    // Vyfiltruj citlivá pole pokud nejsou explicitně vyžádaná z trustedu
    $includeBank = isset($_GET['include_bank']) && in_array(
        $_SERVER['REMOTE_ADDR'] ?? '', ['127.0.0.1', '::1'], true
    );
    $sensitive = ['business_bank_account', 'business_bank_iban', 'business_bank_swift'];

    $publicData = [];
    foreach ($rows as $k => $v) {
        if (!$includeBank && in_array($k, $sensitive, true)) continue;
        $publicData[$k] = $v;
    }

    // Pomocné odvozené hodnoty
    $publicData['business_address_full'] = trim(implode(', ', array_filter([
        $publicData['business_street'] ?? '',
        ($publicData['business_zip'] ?? '') . ' ' . ($publicData['business_city'] ?? ''),
        $publicData['business_country'] ?? '',
    ])));

    echo json_encode($publicData, JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    error_log('business_info: ' . $e->getMessage());
    echo json_encode([]);
}
