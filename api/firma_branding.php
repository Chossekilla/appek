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
        WHERE klic IN ('firma_logo_url', 'firma_favicon_url', 'firma_nazev')
    ")->fetchAll(PDO::FETCH_KEY_PAIR);
    json_response([
        'logo_url'    => $rows['firma_logo_url']    ?? null,
        'favicon_url' => $rows['firma_favicon_url'] ?? null,
        'firma_nazev' => $rows['firma_nazev']       ?? null,
    ]);
} catch (Throwable $e) {
    json_response(['logo_url' => null, 'favicon_url' => null]);
}
