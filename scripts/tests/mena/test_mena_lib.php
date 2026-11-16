<?php
/**
 * Test měny dokladů (api/_mena_lib.php) — bez MySQL, na SQLite v paměti.
 * Spuštění: php scripts/tests/mena/test_mena_lib.php   (exit 0 = vše OK)
 */
require_once __DIR__ . '/../../../api/_mena_lib.php';

$ok = 0; $fail = 0;
function t(string $nazev, bool $podminka): void {
    global $ok, $fail;
    if ($podminka) { $ok++; } else { $fail++; echo "❌ $nazev\n"; }
}
function pdo_s_configem(?array $cfg): PDO {
    $pdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
    $pdo->exec("CREATE TABLE nastaveni (klic TEXT PRIMARY KEY, hodnota TEXT)");
    $pdo->exec("CREATE TABLE faktury (id INTEGER PRIMARY KEY, mena TEXT NULL, mena_kurz REAL NULL, mena_zaklad TEXT NULL)");
    if ($cfg !== null) $pdo->prepare("INSERT INTO nastaveni VALUES ('mena_config_json', ?)")->execute([json_encode($cfg)]);
    return $pdo;
}

// 1) bez configu = Kč, nic se nedělí
$c = mena_config_load(pdo_s_configem(null));
t('default CZK', $c['kod'] === 'CZK' && $c['delitel'] == 1.0 && $c['zakladni'] === 'CZK');

// 2) prodej v EUR, ceník v Kč → dělí se kurzem
$c = mena_config_load(pdo_s_configem(['kod' => 'EUR', 'kurz' => 25.25, 'zobrazeni' => 'mena']));
t('EUR display delitel=kurz', $c['delitel'] == 25.25 && $c['symbol'] === '€');

// 3) po trvalém přepočtu → nedělí se podruhé, zobrazení vynuceně v EUR
$c = mena_config_load(pdo_s_configem(['kod' => 'EUR', 'kurz' => 25.25, 'zobrazeni' => 'kc', 'zakladni' => 'EUR']));
t('po přepočtu delitel=1', $c['delitel'] == 1.0 && $c['zobrazeni'] === 'mena' && $c['kod'] === 'EUR');

// 4) EUR config ale zobrazení v Kč → nový doklad v Kč
$pdo = pdo_s_configem(['kod' => 'EUR', 'kurz' => 25.25, 'zobrazeni' => 'kc']);
t('zobrazení kc → doklad CZK', mena_pro_novy_doklad($pdo)['mena'] === 'CZK');

// 5) snapshot: zapíše jen když chybí, pozdější změna kurzu ho nemění
$pdo = pdo_s_configem(['kod' => 'EUR', 'kurz' => 25.25, 'zobrazeni' => 'mena']);
$pdo->exec("INSERT INTO faktury (id) VALUES (1)");
$pdo->exec("INSERT INTO faktury (id, mena, mena_kurz, mena_zaklad) VALUES (2, 'CZK', 1, 'CZK')");
mena_snapshot_doklad($pdo, 'faktury', 1);
mena_snapshot_doklad($pdo, 'faktury', 2);
$r1 = $pdo->query("SELECT * FROM faktury WHERE id = 1")->fetch();
$r2 = $pdo->query("SELECT * FROM faktury WHERE id = 2")->fetch();
t('snapshot nové faktury EUR', $r1['mena'] === 'EUR' && abs($r1['mena_kurz'] - 25.25) < 1e-9 && $r1['mena_zaklad'] === 'CZK');
t('stará faktura zůstává CZK', $r2['mena'] === 'CZK');
$pdo->exec("UPDATE nastaveni SET hodnota = '" . json_encode(['kod' => 'EUR', 'kurz' => 30, 'zobrazeni' => 'mena']) . "'");
mena_snapshot_doklad($pdo, 'faktury', 1);
t('změna kurzu snapshot nepřepíše', abs($pdo->query("SELECT mena_kurz FROM faktury WHERE id = 1")->fetchColumn() - 25.25) < 1e-9);
t('neznámá tabulka se ignoruje', (function () use ($pdo) { mena_snapshot_doklad($pdo, 'odberatele; DROP', 1); return true; })());

// 6) převodní koeficienty
$m = mena_doklad($pdo, 'faktury', ['id' => 1, 'mena' => 'EUR', 'mena_kurz' => 25.25, 'mena_zaklad' => 'CZK']);
t('Kč uložené → EUR doklad', abs($m['k_doklad'] - 1 / 25.25) < 1e-12 && $m['k_czk'] == 1.0);
$m = mena_doklad($pdo, 'faktury', ['id' => 1, 'mena' => 'EUR', 'mena_kurz' => 25.25, 'mena_zaklad' => 'EUR']);
t('EUR uložené → EUR doklad, Kč rekap ×kurz', $m['k_doklad'] == 1.0 && $m['k_czk'] == 25.25);
$m = mena_doklad($pdo, 'faktury', ['id' => 1, 'mena' => 'CZK', 'mena_kurz' => 7, 'mena_zaklad' => 'CZK']);
t('CZK doklad ignoruje kurz', $m['k_doklad'] == 1.0 && $m['mena_kurz'] == 1.0);
$m = mena_doklad($pdo, 'faktury', ['id' => 0, 'mena' => 'XYZ', 'mena_kurz' => 2]);
t('neplatná měna → CZK', $m['mena'] === 'CZK');

// 7) rozpis DPH: Σ = celkem dokladu přesně (centový zbytek do největší sazby)
$rozpis = [12 => ['bez' => 1427.50, 'dph' => 171.30], 21 => ['bez' => 250.00, 'dph' => 52.50]];
$e = mena_prevod_rozpisu($rozpis, 1 / 25.25, 1677.50, 223.80);
t('Σ bez = round(celkem/kurz)', abs(array_sum(array_column($e, 'bez')) - round(1677.50 / 25.25, 2)) < 0.001);
t('Σ dph = round(dph/kurz)', abs(array_sum(array_column($e, 'dph')) - round(223.80 / 25.25, 2)) < 0.001);
// náhodné rozpisy — invariant musí platit vždy
mt_srand(640);
$nahodneOk = true;
for ($i = 0; $i < 500; $i++) {
    $r = []; $tb = 0; $td = 0;
    foreach ([0, 12, 21] as $s) { if (mt_rand(0, 1)) { $b = mt_rand(1, 999999) / 100; $d = round($b * $s / 100, 2); $r[$s] = ['bez' => $b, 'dph' => $d]; $tb += $b; $td += $d; } }
    if (!$r) continue;
    $k = 1 / (mt_rand(1000, 40000) / 1000);
    $e = mena_prevod_rozpisu($r, $k, $tb, $td);
    if (abs(array_sum(array_column($e, 'bez')) - round($tb * $k, 2)) > 0.001 || abs(array_sum(array_column($e, 'dph')) - round($td * $k, 2)) > 0.001) { $nahodneOk = false; echo "náhodný rozpis #$i nesedí\n"; break; }
}
t('500 náhodných rozpisů sedí', $nahodneOk);

echo "mena_lib: $ok OK, $fail chyb\n";
exit($fail ? 1 : 0);
