<?php
/**
 * Bootstrap pro CLI testy účetních integrací (POHODA / FlexiBee).
 *   DB: proměnná prostředí UCETNI_TEST_DB (výchozí appek_int_test) — název MUSÍ obsahovat „test",
 *   jinak se test odmítne spustit (ochrana před zápisem do ostré DB).
 *   Testy zapisují jen do accounting_* tabulek + testovací řádky označené „APPEK TEST".
 */
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only'); }
$__db = getenv('UCETNI_TEST_DB') ?: 'appek_int_test';
if (!preg_match('/test/i', $__db)) { fwrite(STDERR, "Odmítnuto: testy smí běžet jen proti testovací DB (název musí obsahovat 'test').\n"); exit(2); }
define('DB_HOST', getenv('UCETNI_TEST_DB_HOST') ?: '127.0.0.1');
define('DB_NAME', $__db);
define('DB_USER', getenv('UCETNI_TEST_DB_USER') ?: 'root');
define('DB_PASS', getenv('UCETNI_TEST_DB_PASS') ?: '');
$_SERVER['REQUEST_METHOD'] = 'GET';
$ROOT = dirname(__DIR__, 3);
// pokud v checkoutu leží api/config.local.php, jeho define() DB_* by kolidovaly → testovací DB vyhrává, varování umlčíme
set_error_handler(fn($no, $str) => str_contains($str, 'already defined'), E_WARNING);
ob_start(); require $ROOT . '/api/config.php'; @ob_end_clean();
restore_error_handler();
require_once $ROOT . '/api/_ucetni_lib.php';
require_once $ROOT . '/api/_pohoda.php';
require_once $ROOT . '/api/_flexibee.php';

$GLOBALS['__pass'] = 0; $GLOBALS['__fail'] = 0;
function ok($cond, string $msg): void {
    if ($cond) { $GLOBALS['__pass']++; echo "  ✅ $msg\n"; }
    else { $GLOBALS['__fail']++; echo "  ❌ $msg\n"; }
}
function konec(): void {
    echo "\n== {$GLOBALS['__pass']} OK, {$GLOBALS['__fail']} FAIL ==\n";
    exit($GLOBALS['__fail'] ? 1 : 0);
}
/** Adresář se scratch výstupy (XML soubory pro xmllint). */
function test_out_dir(): string {
    $d = getenv('UCETNI_TEST_OUT') ?: (sys_get_temp_dir() . '/appek-ucetni-test');
    if (!is_dir($d)) mkdir($d, 0777, true);
    return $d;
}
