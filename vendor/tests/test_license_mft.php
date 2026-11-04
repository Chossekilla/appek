<?php
// CLI test: php vendor/tests/test_license_mft.php
// MFT klíče (Můj Foodtruck) — tvar, checksum, oddělení od APPEK klíčů.
// Načítá obě knihovny v jednom procesu → ověřuje i to, že nedojde k „Cannot redeclare“.
require __DIR__ . '/../_license.php';
require __DIR__ . '/../_license_mft.php';

$fails = 0;
function check(bool $cond, string $msg): void {
    global $fails;
    if (!$cond) { $fails++; echo "FAIL: $msg\n"; }
}

$k = mft_license_generate();
check(preg_match('/^MFT-[A-Z2-9]{4}-[A-Z2-9]{4}-[A-Z2-9]{4}-[A-Z0-9]{4}$/', $k) === 1, "tvar klíče ($k)");
check(mft_license_valid($k), 'vygenerovaný je platný');
check(mft_license_valid('  ' . strtolower($k) . "\n"), 'trim + case-insensitive');
$bad = substr($k, 0, -1) . (substr($k, -1) === 'A' ? 'B' : 'A');
check(!mft_license_valid($bad), 'změněný checksum neplatí');
check(!mft_license_valid('MFT-AAAA-BBBB-CCCC'), 'chybí skupina');
$appek = license_generate();
check(!mft_license_valid($appek), 'APPEK klíč v MFT neplatí');
check(!license_valid($k), 'MFT klíč v APPEK validátoru neplatí');
check(MFT_LICENSE_SALT !== LICENSE_SALT, 'jiný salt');
for ($i = 0; $i < 200; $i++) check(mft_license_valid(mft_license_generate()), 'opakované generování');

echo $fails ? "$fails FAIL\n" : "OK test_license_mft\n";
exit($fails ? 1 : 0);
