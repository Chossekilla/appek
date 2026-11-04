<?php
// CLI test: php vendor/tests/test_product.php
// Produkt Appek / Můj Foodtruck — normalizace, SQL filtr, session volba, defaulty licence.
require __DIR__ . '/../_license.php';
require __DIR__ . '/../_product.php';

$fails = 0;
function check(bool $cond, string $msg): void {
    global $fails;
    if (!$cond) { $fails++; echo "FAIL: $msg\n"; }
}

// ── normalizace ──
check(vendor_product_normalize('foodtruck') === 'foodtruck', 'ft');
check(vendor_product_normalize('all') === 'all', 'all');
check(vendor_product_normalize('all', false) === 'appek', 'all zakázán');
check(vendor_product_normalize("foodtruck' OR 1=1") === 'appek', 'injekce → appek');
check(vendor_product_normalize(null) === 'appek', 'null');

// ── SQL fragment ──
check(vendor_product_sql('all') === '1=1', 'all sql');
check(vendor_product_sql('foodtruck', 'l.product') === "COALESCE(l.product,'appek') = 'foodtruck'", 'ft sql');
check(vendor_product_sql('nesmysl') === "COALESCE(product,'appek') = 'appek'", 'neznámý → appek');
try { vendor_product_sql('appek', 'x; DROP'); check(false, 'col injekce'); }
catch (InvalidArgumentException $e) { check(true, 'col injekce'); }

// ── session volba ──
$_SESSION = []; $_GET = ['product' => 'foodtruck'];
check(vendor_product_current() === 'foodtruck' && ($_SESSION['vendor_product'] ?? null) === 'foodtruck', 'uloží do session');
$_GET = [];
check(vendor_product_current() === 'foodtruck', 'pamatuje si');
$_SESSION = [];
check(vendor_product_current() === 'appek', 'default appek');
$_SESSION['vendor_product'] = 'all';
check(vendor_product_for_write() === 'appek', 'all → zápis appek');
$_SESSION['vendor_product'] = 'evil';
check(vendor_product_current() === 'appek', 'podvržená session → appek');

// ── defaulty licence podle produktu ──
$ft = vendor_license_defaults('foodtruck', ['packages' => ['cukrarna'], 'rental' => 0], '2026-11-04');
check($ft['packages'] === [] && $ft['rental'] === 1 && $ft['expires_at'] === '2026-12-04' && $ft['product'] === 'foodtruck', 'ft defaulty');
$ft2 = vendor_license_defaults('foodtruck', ['expires_at' => '2027-03-01'], '2026-11-04');
check($ft2['expires_at'] === '2027-03-01', 'ft ruční datum');
$ap = vendor_license_defaults('appek', ['packages' => ['cukrarna', 'xyz'], 'rental' => 1], '2026-11-04');
check($ap['packages'] === ['cukrarna'] && $ap['rental'] === 1 && $ap['expires_at'] === '2027-11-04' && $ap['product'] === 'appek', 'appek beze změny');
check(vendor_license_defaults('appek', [], '2026-01-31')['expires_at'] === '2027-01-31', 'appek +1 rok');
check(vendor_license_defaults('appek', [], '2026-01-31')['rental'] === 0, 'appek default bez pronájmu');
check(vendor_license_defaults('foodtruck', [], '2026-01-31')['expires_at'] === '2026-02-28', 'ft +1 měsíc konec měsíce');
check(vendor_license_defaults('evil', [], '2026-11-04')['product'] === 'appek', 'neznámý produkt → appek');

// ── nastavení Můj Foodtruck: validace ──
[$err, $vals] = vendor_ft_settings_validate([
    'ft_price_month_kc' => '499', 'ft_price_year_kc' => '', 'ft_web_url' => 'https://mujfoodtruck.cz',
    'ft_portal_url' => '', 'ft_mail_from_email' => 'info@mujfoodtruck.cz', 'ft_mail_from_name' => ' Můj Foodtruck ',
    'ft_invoice_prefix' => '', 'ft_supplier_name' => 'Firma s.r.o.', 'neznamy_klic' => 'x',
]);
check($err === [], 'platná data bez chyb');
check($vals['ft_price_month_kc'] === '499' && $vals['ft_price_year_kc'] === '', 'ceny');
check($vals['ft_invoice_prefix'] === 'MFT', 'default prefix faktur MFT');
check($vals['ft_mail_from_name'] === 'Můj Foodtruck', 'trim');
check(!isset($vals['neznamy_klic']) && count($vals) === count(VENDOR_FT_SETTING_KEYS), 'jen whitelist klíčů');
[$err] = vendor_ft_settings_validate(['ft_price_month_kc' => '-5']);
check(count($err) === 1, 'záporná cena = chyba');
[$err] = vendor_ft_settings_validate(['ft_price_month_kc' => 'abc', 'ft_web_url' => 'javascript:alert(1)', 'ft_mail_from_email' => 'neni-email']);
check(count($err) === 3, 'nečíslo + špatná URL + špatný e-mail = 3 chyby');
[$err, $vals] = vendor_ft_settings_validate(['ft_price_month_kc' => '499,50', 'ft_invoice_prefix' => 'mft-x']);
check($err === [] && $vals['ft_price_month_kc'] === '499.50', 'desetinná čárka');
check($vals['ft_invoice_prefix'] === 'MFTX', 'prefix jen A-Z0-9 velkými');

echo $fails ? "$fails FAIL\n" : "OK test_product\n";
exit($fails ? 1 : 0);
