<?php
// CLI test: php vendor/tests/test_coupon_product.php
// Kupony jsou izolované podle produktu: foodtruck kupon neplatí v appek pokladně a naopak.
// SQLite in-memory (dotaz shop_coupon_eval je SQL-standardní) — žádná produkční data.
require __DIR__ . '/../../api/_shop_coupon_lib.php';

$fails = 0;
function check(bool $cond, string $msg): void {
    global $fails;
    if (!$cond) { $fails++; echo "FAIL: $msg\n"; }
}

$pdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$pdo->exec("CREATE TABLE vendor_coupons (
    id INTEGER PRIMARY KEY AUTOINCREMENT, kod TEXT NOT NULL UNIQUE, typ TEXT NOT NULL DEFAULT 'percent',
    hodnota REAL NOT NULL DEFAULT 0, popis TEXT, active INTEGER NOT NULL DEFAULT 1, plati_do TEXT,
    max_pouziti INTEGER, pouzito INTEGER NOT NULL DEFAULT 0, product TEXT NOT NULL DEFAULT 'appek', created_at TEXT)");
$pdo->exec("INSERT INTO vendor_coupons (kod, typ, hodnota, product) VALUES ('FT10', 'percent', 10, 'foodtruck')");
$pdo->exec("INSERT INTO vendor_coupons (kod, typ, hodnota) VALUES ('AP10', 'percent', 10)");

check(shop_coupon_eval($pdo, 'FT10', 1000)[0] === false, 'ft kupon v appek pokladně neplatí');
check(shop_coupon_eval($pdo, 'FT10', 1000, 'foodtruck')[0] === true, 'ft kupon ve ft pokladně platí');
check(shop_coupon_eval($pdo, 'AP10', 1000)[0] === true, 'appek kupon beze změny');
check(shop_coupon_eval($pdo, 'AP10', 1000, 'foodtruck')[0] === false, 'appek kupon ve ft neplatí');
check(abs(shop_coupon_eval($pdo, 'FT10', 1000, 'foodtruck')[3] - 100.0) < 0.001, 'sleva 10 %');
check(shop_coupon_eval($pdo, 'FT10', 1000)[1] === 'not_found', 'cizí kupon se tváří jako neexistující (nic neprozradí)');

echo $fails ? "$fails FAIL\n" : "OK test_coupon_product\n";
exit($fails ? 1 : 0);
