<?php
/**
 * 🔑 LICENSE GENERATOR — interní skript pro dodavatele.
 *
 * NEDISTRIBUOVAT zákazníkům! Tento skript je vyloučen z build-zip.sh.
 *
 * Použití:
 *   php scripts/generate-license.php             → 1 klíč
 *   php scripts/generate-license.php 5           → 5 klíčů
 *   php scripts/generate-license.php 1 "Pekárna Novák, Brno"  → 1 klíč + zákazník
 *
 * Vygenerované klíče si zapisuj do vlastní evidence (key → zákazník + datum).
 */

if (PHP_SAPI !== 'cli') {
    die("Spouštěj z CLI: php scripts/generate-license.php\n");
}

require_once __DIR__ . '/../api/_license.php';

$count    = max(1, (int) ($argv[1] ?? 1));
$customer = $argv[2] ?? '';

echo "═══════════════════════════════════════════════════\n";
echo "🔑 Appek B2B — License Key Generator\n";
echo "═══════════════════════════════════════════════════\n";
if ($customer) echo "Zákazník: $customer\n";
echo "Datum:    " . date('Y-m-d H:i:s') . "\n";
echo "Salt:     " . substr(LICENSE_SALT, 0, 8) . "…\n";
echo "───────────────────────────────────────────────────\n";

for ($i = 1; $i <= $count; $i++) {
    $key = license_generate();
    printf("%2d. %s\n", $i, $key);
    // Sanity check (paranoia)
    if (!license_valid($key)) {
        die("\n❌ FATAL: Vygenerovaný klíč $key neprošel validací. Bug v _license.php.\n");
    }
}

echo "───────────────────────────────────────────────────\n";
echo "💡 Zapiš si tyto klíče (a komu jsi je vydal) do své evidence.\n";
echo "💡 Zákazník zadá klíč při instalaci (install.php).\n";
echo "\n";
