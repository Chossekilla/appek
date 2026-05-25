<?php
/**
 * 📦 SHOP PACKAGES — veřejný read-only endpoint pro načtení balíčků.
 *
 * GET /api/shop_packages.php
 *   → JSON: { ok: true, packages: [ {key, name_cs, name_en, name_es, price_kc, ...} ] }
 *
 * Cache 5 minut (vendor packages se mění zřídka).
 */

header('Content-Type: application/json; charset=UTF-8');
header('Access-Control-Allow-Origin: *');
header('Cache-Control: public, max-age=300');

$vendorConfigPaths = [
    __DIR__ . '/vendor_db_config.local.php',
    realpath(__DIR__ . '/..') . '/vendor/config.local.php',
];
$loaded = false;
foreach ($vendorConfigPaths as $cfg) {
    if ($cfg && file_exists($cfg)) {
        require_once $cfg;
        $loaded = true;
        break;
    }
}

if (!$loaded || !defined('VENDOR_DB_HOST')) {
    // Fallback — vrátíme prázdný seznam místo erroru (sales page přežije bez packages)
    echo json_encode(['ok' => true, 'packages' => [], 'note' => 'vendor_not_configured']);
    exit;
}

try {
    $dsn = sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
        VENDOR_DB_HOST, defined('VENDOR_DB_PORT') ? VENDOR_DB_PORT : 3306, VENDOR_DB_NAME);
    $pdo = new PDO($dsn, VENDOR_DB_USER, VENDOR_DB_PASS, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);

    $rows = $pdo->query("
        SELECT `key`, name_cs, name_en, name_es,
               description_cs, description_en, description_es,
               icon, price_kc, price_eur, price_usd, is_core, bit_pos,
               features_json
        FROM vendor_packages
        WHERE is_active = 1
        ORDER BY sort_order, id
    ")->fetchAll();

    foreach ($rows as &$r) {
        $r['price_kc'] = (float) $r['price_kc'];
        $r['price_eur'] = $r['price_eur'] !== null ? (float) $r['price_eur'] : null;
        $r['price_usd'] = $r['price_usd'] !== null ? (float) $r['price_usd'] : null;
        $r['is_core'] = (bool) $r['is_core'];
        $r['bit_pos'] = $r['bit_pos'] !== null ? (int) $r['bit_pos'] : null;
        $r['features'] = $r['features_json'] ? json_decode($r['features_json'], true) : [];
        unset($r['features_json']);
    }

    echo json_encode(['ok' => true, 'packages' => $rows], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    error_log('shop_packages: ' . $e->getMessage());
    echo json_encode(['ok' => true, 'packages' => [], 'note' => 'db_error']);
}
