<?php
/**
 * 🔢 VERSION — vrátí instalovanou verzi APPEK + license key.
 *
 * GET /api/version.php
 *   → { ok:true, version:"2.0.55", license_key:"APPEK-...", install_date:"..." }
 *
 * Veřejný endpoint (light) — žádná citlivá data, jen verze + masked license key prefix.
 * Použito v admin/updater.html pro auto-fill formuláře.
 */

header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-cache');

@require_once __DIR__ . '/config.php';

$out = [
    'ok'           => true,
    'version'      => defined('APP_VERSION')      ? APP_VERSION      : '0.0.0',
    'license_key'  => defined('APP_LICENSE_KEY')  ? APP_LICENSE_KEY  : null,
    'install_date' => null,
];

// .installed timestamp (vytvořeno install.php po dokončení wizardu)
$flag = __DIR__ . '/.installed';
if (file_exists($flag)) {
    $out['install_date'] = trim(file_get_contents($flag));
}

// 🆕 v2.9.65 — Build manifest souhrn (deploy-check.php ho ověří proti disku).
// 'build_version' může být ≠ 'version' když deploy přepsal jen config.php.
$bm = __DIR__ . '/.build-manifest.json';
if (file_exists($bm)) {
    $m = json_decode((string) file_get_contents($bm), true);
    if (is_array($m)) {
        $out['build_version'] = $m['version']    ?? null;
        $out['built_at']      = $m['built_at']   ?? null;
        $out['build_files']   = $m['file_count'] ?? (is_array($m['files'] ?? null) ? count($m['files']) : null);
    }
} else {
    $out['build_version'] = null;
}

echo json_encode($out, JSON_UNESCAPED_UNICODE);
