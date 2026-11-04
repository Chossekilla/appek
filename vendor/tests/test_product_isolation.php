<?php
// CLI test: php vendor/tests/test_product_isolation.php
// Kontrakt izolace produktů v místech, která běží proti DB (cron, portál, faktury) a v UI textu.
// Statická kontrola zdroje — tyto skripty nejdou spustit bez produkční DB.
$fails = 0;
function check(bool $cond, string $msg): void {
    global $fails;
    if (!$cond) { $fails++; echo "FAIL: $msg\n"; }
}
$root = dirname(__DIR__, 2);
$appekOnly = "COALESCE(product,'appek') = 'appek'";

// I1 — připomínky pronájmu (šablona APPEK + my.appek.cz) jen pro appek licence
$cron = file_get_contents("$root/vendor/cron_reminders.php");
check(str_contains($cron, "AND $appekOnly"), 'cron_reminders: jen appek licence');

// I2 — portál my.appek.cz nevidí ani neprodlužuje foodtruck licence (obě větve dotazu)
$my = file_get_contents("$root/my/index.php");
preg_match('/function my_licenses\(.*?\n}\n/s', $my, $m);
$fn = $m[0] ?? '';
check(substr_count($fn, "COALESCE(l.product,'appek') = 'appek'") === 1, 'my_licenses: JOIN větev filtruje appek');
check(substr_count($fn, "AND $appekOnly") === 1, 'my_licenses: fallback větev filtruje appek');

// I3 — ruční faktura ve vendoru je vždy appek (foodtruck faktury vystaví Plán 2 vlastní řadou)
$fak = file_get_contents("$root/vendor/faktury.php");
check(!str_contains($fak, "'prod'=>vendor_product_for_write()"), 'faktury: nebrat produkt ze session');
check(str_contains($fak, "'prod'=>'appek'"), 'faktury: ruční faktura = appek');

// M2 — nápověda k expiraci odpovídá produktu
$js = file_get_contents("$root/vendor/app.js");
check(str_contains($js, "ft ? 'Prázdné = 1 měsíc' : 'Prázdné = 1 rok'"), 'app.js: nápověda expirace podle produktu');

// M2b — skrytí řádku Pronájem u foodtrucku: atribut hidden přebíjí CSS .checkbox-row (display:flex) → inline style
check(!preg_match('/class="checkbox-row" \$\{[^}]*\? \'hidden\'/', $js), 'app.js: checkbox-row nesmí skrývat přes atribut hidden');
check(substr_count($js, 'class="checkbox-row" style="${') >= 2, 'app.js: checkbox-row Pronájem skrytý přes style (gen + edit)');

echo $fails ? "$fails FAIL\n" : "OK test_product_isolation\n";
exit($fails ? 1 : 0);
