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

echo json_encode($out, JSON_UNESCAPED_UNICODE);
