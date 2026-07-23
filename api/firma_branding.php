<?php
/**
 * Veřejný endpoint — vrací logo + favicon URL z nastavení.
 * Bez autentizace — branding info je veřejné (logo a favicon jsou tak jako tak v /uploads/).
 * Použito v B2B frontendu (app.js) pro nahrazení defaultního "R" loga / favicony.
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/_pwa_lib.php';
cors_headers();

$pdo = db();
$method = $_SERVER['REQUEST_METHOD'];
if ($method !== 'GET') json_error('Method not allowed', 405);
appek_ensure_pwa_icons();  // 🆕 v3.0.364 — naseeduj PWA install ikony (default APPEK), pokud chybí

try {
    $rows = $pdo->query("
        SELECT klic, hodnota FROM nastaveni
        WHERE klic IN ('firma_logo_url', 'firma_favicon_url', 'firma_nazev', 'mena_config_json', 'ga_measurement_id', 'tracking_custom_code', 'gdpr_souhlas_povinny')
    ")->fetchAll(PDO::FETCH_KEY_PAIR);
    // v3.0.283 — měna pro B2B fmt() (display konverze; necitlivé, DB zůstává v Kč)
    $mena = ['kod' => 'CZK', 'kurz' => 1, 'zobrazeni' => 'kc'];
    if (!empty($rows['mena_config_json'])) {
        $mc = json_decode($rows['mena_config_json'], true);
        if (is_array($mc)) $mena = ['kod' => $mc['kod'] ?? 'CZK', 'kurz' => max(0.0001, (float) ($mc['kurz'] ?? 1)), 'zobrazeni' => ($mc['zobrazeni'] ?? 'kc') === 'mena' ? 'mena' : 'kc'];
    }
    // 📊 v3.0.284 — GA measurement ID pro B2B portál (gtag vkládá app.js); validace formátu
    $gaId = trim((string) ($rows['ga_measurement_id'] ?? ''));
    if ($gaId !== '' && !preg_match('/^(G|AW|UA)-[A-Z0-9-]{4,}$/i', $gaId)) $gaId = '';
    json_response([
        'logo_url'    => $rows['firma_logo_url']    ?? null,
        'favicon_url' => $rows['firma_favicon_url'] ?? null,
        'firma_nazev' => $rows['firma_nazev']       ?? null,
        'mena'        => $mena,
        'ga_id'       => $gaId !== '' ? $gaId : null,
        // 🍪 v3.0.401 — vlastní sledovací kód (HTML/JS); b2b/app.js ho vloží AŽ po souhlasu s cookies
        'custom_tracking' => ($ct = trim((string) ($rows['tracking_custom_code'] ?? ''))) !== '' ? $ct : null,
        // 🆕 v3.0.432 — povinný GDPR souhlas u objednávky (default zapnuto; '0' = vypnuto v nastavení)
        'gdpr_souhlas_povinny' => (($rows['gdpr_souhlas_povinny'] ?? '1') !== '0'),
    ]);
} catch (Throwable $e) {
    json_response(['logo_url' => null, 'favicon_url' => null]);
}
