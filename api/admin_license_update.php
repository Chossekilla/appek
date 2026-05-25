<?php
/**
 * 🔑 LICENSE UPDATE — vyměňuje APP_LICENSE_KEY v api/config.local.php.
 *
 * POST { license_key: "APPEK-..." }
 *   → validuje formát + checksum offline (žádný phone-home)
 *   → přepíše definici v config.local.php (zachová ostatní)
 *   → vrátí: { ok, key, masked, packages: [...] }
 *
 * Jen super admin smí volat.
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/_admin_auth.php';
require_once __DIR__ . '/_license.php';

cors_headers();
require_super_admin();
header('Content-Type: application/json; charset=UTF-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') json_error('Pouze POST', 405);

$d = json_input();
$newKey = strtoupper(trim($d['license_key'] ?? ''));
if ($newKey === '') json_error('Zadej licenční klíč', 400);
if (!license_valid($newKey)) {
    json_error('Klíč není platný (neodpovídá formátu nebo checksum). Zkontroluj že je celý a správně opsaný.', 400);
}

$cfgPath = __DIR__ . '/config.local.php';
if (!file_exists($cfgPath)) json_error('config.local.php neexistuje — spusť install.php', 500);
if (!is_writable($cfgPath)) {
    json_error("Nemůžu zapsat $cfgPath — zkontroluj oprávnění (chmod 664 nebo 660).", 500);
}

$cfg = file_get_contents($cfgPath);
if ($cfg === false) json_error('Nelze přečíst config.local.php', 500);

// Nahraď APP_LICENSE_KEY (define) — match jakékoliv hodnoty, single/double quotes
$pattern = "/define\(\s*['\"]APP_LICENSE_KEY['\"]\s*,\s*['\"][^'\"]*['\"]\s*\)\s*;/";
$replacement = "define('APP_LICENSE_KEY', '" . addslashes($newKey) . "');";

if (preg_match($pattern, $cfg)) {
    $newCfg = preg_replace($pattern, $replacement, $cfg);
} else {
    // Nebyl definovaný — připoj na konec
    $newCfg = rtrim($cfg) . "\n" . $replacement . "\n";
}

// Záloha před přepisem
$backup = $cfgPath . '.bak-' . date('YmdHis');
@copy($cfgPath, $backup);

if (file_put_contents($cfgPath, $newCfg) === false) {
    json_error('Zápis selhal — config.local.php není zapsatelný.', 500);
}

json_response([
    'ok'       => true,
    'key'      => $newKey,
    'masked'   => license_masked($newKey),
    'packages' => license_packages($newKey),
    'backup'   => basename($backup),
]);
