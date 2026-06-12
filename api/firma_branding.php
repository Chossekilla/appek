<?php
/**
 * Veřejný endpoint — vrací logo + favicon URL z nastavení.
 * Bez autentizace — branding info je veřejné (logo a favicon jsou tak jako tak v /uploads/).
 * Použito v B2B frontendu (app.js) pro nahrazení defaultního "R" loga / favicony.
 */
require_once __DIR__ . '/config.php';
cors_headers();

$pdo = db();
$method = $_SERVER['REQUEST_METHOD'];
if ($method !== 'GET') json_error('Method not allowed', 405);

try {
    $rows = $pdo->query("
        SELECT klic, hodnota FROM nastaveni
        WHERE klic IN ('firma_logo_url', 'firma_favicon_url', 'firma_nazev', 'mena_config_json', 'ga_measurement_id')
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
    ]);
} catch (Throwable $e) {
    json_response(['logo_url' => null, 'favicon_url' => null]);
}
