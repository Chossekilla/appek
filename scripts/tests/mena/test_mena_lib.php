<?php
/**
 * Test měny dokladů (api/_mena_lib.php) — bez MySQL, na SQLite v paměti.
 * Spuštění: php scripts/tests/mena/test_mena_lib.php   (exit 0 = vše OK)
 */
require_once __DIR__ . '/../../../api/_mena_lib.php';

$ok = 0; $fail = 0;
function t(string $nazev, bool $podminka): void {
    global $ok, $fail;
    if ($podminka) { $ok++; if (getenv("V")) echo "✓ $nazev\n"; } else { $fail++; echo "❌ $nazev\n"; }
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


// ══════════════════════════════════════════════════════════════════════════
//  🆕 v3.0.643 — BALÍK 0: platba v Kč i cizí měně (akceptace + smlouva _mena_lib)
// ══════════════════════════════════════════════════════════════════════════
$GLOBALS['_drz'] = [];   // PDO objekty držíme naživu (cache mena_ma_sloupec je per objekt)
function pdo_platba(?array $cfg): PDO {
    $pdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
    $GLOBALS['_drz'][] = $pdo;
    $pdo->exec("CREATE TABLE nastaveni (klic TEXT PRIMARY KEY, hodnota TEXT)");
    $pdo->exec("CREATE TABLE mena_kurzy (mena TEXT, datum TEXT, kurz REAL, zdroj TEXT, nacteno_at TEXT, PRIMARY KEY (mena, datum))");
    $pdo->exec("CREATE TABLE objednavky (id INTEGER PRIMARY KEY, mena TEXT NULL, mena_kurz REAL NULL, mena_kurz_prodej REAL NULL, mena_zaklad TEXT NULL,
                castka_celkem REAL, castka_bez_dph REAL, castka_dph REAL, castka_mena REAL NULL, stav TEXT DEFAULT 'nova', pay_status TEXT DEFAULT 'none')");
    $pdo->exec("CREATE TABLE objednavky_polozky (id INTEGER PRIMARY KEY, objednavka_id INT, mnozstvi REAL, cena_bez_dph REAL, sazba_dph REAL)");
    $pdo->exec("CREATE TABLE dodaci_listy (id INTEGER PRIMARY KEY, objednavka_id INT NULL, mena TEXT NULL, mena_kurz REAL NULL, mena_kurz_prodej REAL NULL, mena_zaklad TEXT NULL)");
    $pdo->exec("CREATE TABLE faktury (id INTEGER PRIMARY KEY, objednavka_id INT NULL, mena TEXT NULL, mena_kurz REAL NULL, mena_kurz_prodej REAL NULL, mena_zaklad TEXT NULL)");
    $pdo->exec("CREATE TABLE restaurant_pos_ucty (id INTEGER PRIMARY KEY, stav TEXT DEFAULT 'open', pay_status TEXT DEFAULT 'none', mena TEXT NULL, mena_kurz REAL NULL, mena_kurz_prodej REAL NULL)");
    $pdo->exec("CREATE TABLE restaurant_pos_polozky (id INTEGER PRIMARY KEY, ucet_id INT, mnozstvi REAL, jednotkova_cena REAL, sazba_dph REAL NULL, stav TEXT DEFAULT 'objednano')");
    $pdo->exec("CREATE TABLE platby_doklad (id INTEGER PRIMARY KEY AUTOINCREMENT, objednavka_id INT, ucet_id INT, zdroj TEXT, metoda TEXT, mena TEXT,
                castka_mena REAL, kurz REAL, castka_czk REAL, prijato_mena REAL, vraceno_mena REAL, pokladna TEXT, uzivatel TEXT, idem TEXT UNIQUE, created_at TEXT)");
    if ($cfg !== null) $pdo->prepare("INSERT INTO nastaveni VALUES ('mena_config_json', ?)")->execute([json_encode($cfg)]);
    return $pdo;
}
function listek(string $datumYmd, array $kurzy): string {
    $t = date('d.m.Y', strtotime($datumYmd)) . " #222\nzemě|měna|množství|kód|kurz\n";
    foreach ($kurzy as $k => $v) $t .= "X|x|1|$k|" . str_replace('.', ',', (string) $v) . "\n";
    $t .= "Maďarsko|forint|100|HUF|6,543\n";
    return $t;
}
function hazi(callable $f, string $kod): bool {
    try { $f(); return false; } catch (MenaException $e) { return $e->kod === $kod; }
}
$dnes = date('Y-m-d');
$volani = 0;
$GLOBALS['MENA_CNB_FETCH'] = function (string $d) use (&$volani, $dnes) { $volani++; return listek($dnes, ['EUR' => 25.17, 'USD' => 21.5]); };

// 8) výchozí blok platba = vypnuto, Kč + EUR připraveno
$c = mena_config_load(pdo_platba(null));
$p = $c['platba'];
t('platba default vypnuto', $p['aktivni'] === false && $p['meny'] === ['EUR'] && $p['prijimane'] === ['CZK', 'EUR'] && $p['vychozi'] === 'CZK');
t('platba default terminál Kč+EUR, kanály, vrácení', $p['terminal_meny'] === ['CZK', 'EUR'] && $p['kanaly'] === MENA_KANALY && $p['vraceni'] === 'mena_platby' && $p['kurz_zdroj'] === 'cnb' && $p['max_stari_kurzu_dni'] === 3);
$c = mena_config_load(pdo_platba(['kod' => 'EUR', 'kurz' => 25.25, 'zobrazeni' => 'mena']));
t('legacy prodej v EUR → platba.vychozi EUR (vypnuto)', $c['platba']['vychozi'] === 'EUR' && !$c['platba']['aktivni'] && $c['zobrazeni'] === 'mena');
$c = mena_config_load(pdo_platba(['kod' => 'EUR', 'kurz' => 25.25, 'zobrazeni' => 'mena', 'zakladni' => 'EUR', 'platba' => ['aktivni' => true]]));
t('po trvalém přepočtu nejde platba aktivni', $c['platba']['aktivni'] === false);
$c = mena_config_load(pdo_platba(['kod' => 'EUR', 'kurz' => 25.25, 'zobrazeni' => 'mena', 'platba' => ['aktivni' => true]]));
t('aktivni → zobrazení Kč (delitel 1)', $c['platba']['aktivni'] && $c['zobrazeni'] === 'kc' && $c['delitel'] == 1.0);
$c = mena_config_load(pdo_platba(['platba' => ['aktivni' => true, 'meny' => ['CZK', 'XXX', 'eur'], 'vychozi' => 'USD', 'prirazka_pct' => ['EUR' => 99], 'iban_eur' => 'nesmysl']]));
t('norm: měny/vychozi/přirážka/IBAN ošetřené', $c['platba']['meny'] === ['EUR'] && $c['platba']['vychozi'] === 'CZK' && $c['platba']['prirazka_pct']['EUR'] == 20.0 && $c['platba']['iban_eur'] === '');

// 9) volba platby VYPNUTO = legacy chování (identické s v3.0.642)
$pdo = pdo_platba(['kod' => 'CZK']);
$v = mena_volba_platby($pdo, null, 'pos');
t('vypnuto: bez volby CZK, kurz_prodej null', $v['mena'] === 'CZK' && $v['mena_kurz'] == 1.0 && $v['mena_kurz_prodej'] === null);
t('vypnuto: volba EUR → platba_vypnuta', hazi(fn() => mena_volba_platby($pdo, 'EUR', 'pos'), 'platba_vypnuta'));
t('vypnuto: volba CZK projde', mena_volba_platby($pdo, 'czk', 'b2b')['mena'] === 'CZK');
$pdoL = pdo_platba(['kod' => 'EUR', 'kurz' => 25.25, 'zobrazeni' => 'mena']);
$v = mena_volba_platby($pdoL, null, 'web');
t('vypnuto + legacy EUR: = mena_pro_novy_doklad', $v['mena'] === 'EUR' && $v['mena_kurz'] == 25.25 && $v['mena_kurz_prodej'] === null && array_intersect_key($v, mena_pro_novy_doklad($pdoL)) == mena_pro_novy_doklad($pdoL));
t('vypnuto + legacy EUR: volba CZK odmítnuta', hazi(fn() => mena_volba_platby($pdoL, 'CZK', 'web'), 'platba_vypnuta'));
t('neznámý kanál', hazi(fn() => mena_volba_platby($pdo, null, 'eshop'), 'kanal_neznamy'));
t('vypnuto: platebni_info jen Kč', mena_platebni_info($pdo, 'pos')['prijimane'] === ['CZK']);

// 10) ZAPNUTO, kurz ČNB (líně stažený, stub)
$pdo = pdo_platba(['kod' => 'CZK', 'platba' => ['aktivni' => true, 'kanaly' => ['pos', 'b2b', 'stul', 'web']]]);
$volani = 0;
$v = mena_volba_platby($pdo, 'EUR', 'pos');
t('zapnuto: EUR kurz ČNB líně', $v['mena'] === 'EUR' && $v['mena_kurz'] == 25.17 && $v['mena_kurz_prodej'] == 25.17 && $v['kurz_datum'] === $dnes && $v['kurz_zdroj'] === 'cnb' && $volani === 1);
t('kurz uložen do mena_kurzy', (float) $pdo->query("SELECT kurz FROM mena_kurzy WHERE mena='EUR'")->fetchColumn() == 25.17
    && !$pdo->query("SELECT COUNT(*) FROM mena_kurzy WHERE mena NOT IN ('EUR','USD','HUF')")->fetchColumn());
t('HUF ze 100 ks → za 1 ks', abs((float) $pdo->query("SELECT kurz FROM mena_kurzy WHERE mena='HUF'")->fetchColumn() - 0.06543) < 1e-9);
mena_volba_platby($pdo, 'EUR', 'b2b');
t('druhá platba = bez dalšího stahování', $volani === 1);
t('bez volby = výchozí Kč', mena_volba_platby($pdo, null, 'pos')['mena'] === 'CZK');
t('nepovolený kanál: EUR odmítnuto', hazi(fn() => mena_volba_platby($pdo, 'EUR', 'qr'), 'kanal_nepovolen'));
t('nepovolený kanál: bez volby Kč', mena_volba_platby($pdo, null, 'qr')['mena'] === 'CZK');
t('admin kanál vždy povolen', mena_volba_platby($pdo, 'EUR', 'admin')['mena'] === 'EUR');
t('nepřijímaná měna USD', hazi(fn() => mena_volba_platby($pdo, 'USD', 'pos'), 'mena_nepovolena'));
$i = mena_platebni_info($pdo, 'pos');
t('platebni_info zapnuto', $i['aktivni'] && $i['prijimane'] === ['CZK', 'EUR'] && $i['meny']['EUR']['ok'] && $i['meny']['EUR']['kurz'] == 25.17);
t('platebni_info nepovolený kanál = jen Kč', mena_platebni_info($pdo, 'qr')['aktivni'] === false);

// přirážka 2 %: kurz prodeje = ČNB/1.02, mena_kurz zůstává ČNB
$pdo2 = pdo_platba(['platba' => ['aktivni' => true, 'prirazka_pct' => ['EUR' => 2]]]);
$v2 = mena_volba_platby($pdo2, 'EUR', 'pos');
t('přirážka 2 %: kurz prodeje', $v2['mena_kurz'] == 25.17 && abs($v2['mena_kurz_prodej'] - round(25.17 / 1.02, 6)) < 1e-9 && $v2['prirazka_pct'] == 2.0);

// výchozí měna EUR + legacy snapshot dostane EUR s kurzem prodeje
$pdo3 = pdo_platba(['platba' => ['aktivni' => true, 'vychozi' => 'EUR']]);
mena_kurzy_aktualizuj($pdo3);
$pdo3->exec("INSERT INTO objednavky (id, castka_celkem) VALUES (1, 100)");
mena_snapshot_doklad($pdo3, 'objednavky', 1);
$r = $pdo3->query("SELECT * FROM objednavky WHERE id=1")->fetch();
t('zapnuto + výchozí EUR: legacy snapshot EUR', $r['mena'] === 'EUR' && $r['mena_kurz'] == 25.17 && $r['mena_kurz_prodej'] == 25.17 && $r['mena_zaklad'] === 'CZK');

// starý kurz → kurz_stary; výpadek ČNB bez kurzu → kurz_chybi; throttle
$pdo4 = pdo_platba(['platba' => ['aktivni' => true]]);
$GLOBALS['MENA_CNB_FETCH'] = fn($d) => listek(date('Y-m-d', strtotime('-5 days')), ['EUR' => 24.9]);
t('kurz starší než limit → kurz_stary', hazi(fn() => mena_volba_platby($pdo4, 'EUR', 'pos'), 'kurz_stary'));
$k = mena_kurz_dne($pdo4, 'EUR');
t('mena_kurz_dne stáří', $k['stari_dni'] === 5 && $k['kurz'] == 24.9);
$pdo5 = pdo_platba(['platba' => ['aktivni' => true, 'max_stari_kurzu_dni' => 7]]);
t('limit 7 dní → 5denní kurz projde', mena_volba_platby($pdo5, 'EUR', 'pos')['kurz_stari_dni'] === 5);
$volani = 0;
$GLOBALS['MENA_CNB_FETCH'] = function ($d) use (&$volani) { $volani++; return false; };
$pdo6 = pdo_platba(['platba' => ['aktivni' => true]]);
t('ČNB nedostupná a žádný kurz → kurz_chybi', hazi(fn() => mena_volba_platby($pdo6, 'EUR', 'pos'), 'kurz_chybi'));
t('ČNB nedostupná: druhý pokus až za 30 min', hazi(fn() => mena_volba_platby($pdo6, 'EUR', 'pos'), 'kurz_chybi') && $volani === 1);
$pdo6->exec("INSERT INTO mena_kurzy VALUES ('EUR', '" . date('Y-m-d', strtotime('-1 day')) . "', 25.0, 'cnb', NULL)");
$v = mena_volba_platby($pdo6, 'EUR', 'pos');
t('výpadek ČNB: poslední známý kurz + stáří', $v['mena_kurz'] == 25.0 && $v['kurz_stari_dni'] === 1);
t('mena_kurz_dne k datu v minulosti', mena_kurz_dne($pdo6, 'EUR', date('Y-m-d', strtotime('-1 day')), false)['stari_dni'] === 0);

// vlastní kurz
$pdo7 = pdo_platba(['platba' => ['aktivni' => true, 'kurz_zdroj' => 'rucni', 'kurzy_rucni' => ['EUR' => 25.5]]]);
$v = mena_volba_platby($pdo7, 'EUR', 'stul');
t('vlastní kurz', $v['mena_kurz'] == 25.5 && $v['kurz_zdroj'] === 'rucni');
$pdo8 = pdo_platba(['platba' => ['aktivni' => true, 'kurz_zdroj' => 'rucni']]);
t('vlastní kurz chybí → kurz_chybi', hazi(fn() => mena_volba_platby($pdo8, 'EUR', 'stul'), 'kurz_chybi'));
$GLOBALS['MENA_CNB_FETCH'] = fn($d) => listek($dnes, ['EUR' => 25.17]);

// 11) mena_doklad_ro + kurz prodeje
$m0 = mena_doklad_ro($pdo, ['mena' => 'EUR', 'mena_kurz' => 25.17, 'mena_zaklad' => 'CZK']);
$m0p = mena_doklad_ro($pdo, ['mena' => 'EUR', 'mena_kurz' => 25.17, 'mena_kurz_prodej' => 25.17, 'mena_zaklad' => 'CZK']);
$m2 = mena_doklad_ro($pdo, ['mena' => 'EUR', 'mena_kurz' => 25.17, 'mena_kurz_prodej' => round(25.17 / 1.02, 6), 'mena_zaklad' => 'CZK']);
t('k_doklad přes kurz prodeje', abs($m2['k_doklad'] - 1 / round(25.17 / 1.02, 6)) < 1e-15 && $m2['k_czk'] == 1.0 && $m2['mena_kurz'] == 25.17);
t('CZK doklad kurz prodeje ignoruje', mena_doklad_ro($pdo, ['mena' => 'CZK', 'mena_kurz' => 1, 'mena_kurz_prodej' => 9])['k_doklad'] == 1.0);

// 12) přirážka 0 % = dnešní výsledek; 2 % = EUR výš, rozpis_czk dle ČNB
mt_srand(643);
$kosik = function () {
    $p = [];
    for ($j = 0, $n = mt_rand(1, 9); $j < $n; $j++) $p[] = [mt_rand(1, 40) / (mt_rand(0, 3) ? 1 : 4), mt_rand(100, 99999) / 100 + (mt_rand(0, 1) ? mt_rand(0, 99) / 10000 : 0), [0, 12, 21][mt_rand(0, 2)]];
    return $p;
};
$shodne0 = true; $vys2 = true; $czk2 = true;
for ($i = 0; $i < 500; $i++) {
    $p = $kosik();
    $a = mena_doklad_polozky($p, $m0); $b = mena_doklad_polozky($p, $m0p); $c2 = mena_doklad_polozky($p, $m2);
    if ($a != $b) $shodne0 = false;
    if ($c2['celkem'] < $a['celkem']) $vys2 = false;
    foreach ($c2['rozpis'] as $s => $r) {
        if (abs($c2['rozpis_czk'][$s]['bez'] - round($r['bez'] * 25.17, 2)) > 0.001 || abs($c2['rozpis_czk'][$s]['dph'] - round($r['dph'] * 25.17, 2)) > 0.001) $czk2 = false;
    }
    if ($c2['rozpis_czk_cnb'] !== $c2['rozpis_czk']) $czk2 = false;
}
t('přirážka 0 % = výsledek bez kurzu prodeje (500 košíků)', $shodne0);
t('přirážka 2 %: EUR celkem nikdy nižší', $vys2);
t('přirážka 2 %: rozpis_czk = rozpis × kurz ČNB', $czk2);
$p = [[3, 100, 21], [2, 50, 12]];
t('přirážka 2 % konkrétně (≈ +2 %)', mena_doklad_polozky($p, $m2)['celkem'] > mena_doklad_polozky($p, $m0)['celkem'] * 1.019);

// 13) vyrovnání na nulu: 100% voucher/sleva v EUR = 0,00 při 24,33 / 25,17 / 25,405
$nula = true; $bezVyr = 0; $jinakBeze = true; $pocet = 0;
foreach ([24.33, 25.17, 25.405] as $kurz) {
    $m = mena_doklad_ro($pdo, ['mena' => 'EUR', 'mena_kurz' => $kurz, 'mena_zaklad' => 'CZK']);
    for ($i = 0; $i < 400; $i++) {
        // zboží + voucher po sazbách (= voucherRadky: záporný základ každé sazby) → Kč přesně 0
        $p = []; $zak = [];
        for ($j = 0, $n = mt_rand(1, 6); $j < $n; $j++) {
            $s = [12, 21][mt_rand(0, 1)]; $mn = mt_rand(1, 5); $c = mt_rand(1000, 50000) / 100;
            $p[] = [$mn, $c, $s]; $zak[$s] = round(($zak[$s] ?? 0) + $mn * $c, 2);
        }
        foreach ($zak as $s => $z) $p[] = [1, -$z, $s];
        $kc = 0; foreach ($p as [$mn, $c, $s]) $kc += $mn * $c * (1 + $s / 100);
        if (abs($kc) >= 0.005) continue;
        $pocet++;
        $r = mena_doklad_polozky($p, $m);
        if (abs($r['celkem']) > 0.0001 || abs($r['bez'] + $r['dph']) > 0.0001 || abs(array_sum(array_column($r['rozpis'], 'bez')) + array_sum(array_column($r['rozpis'], 'dph'))) > 0.0001) { $nula = false; echo "nula nesedí kurz $kurz: {$r['celkem']}\n"; break 2; }
        if (abs(mena_doklad_polozky($p, $m, false)['celkem']) > 0.0001) $bezVyr++;
        // doklad, který v Kč NENÍ nula, se vyrovnáním nemění
        $p2 = $p; $p2[] = [1, 10, 21];
        if (mena_doklad_polozky($p2, $m) != mena_doklad_polozky($p2, $m, false)) $jinakBeze = false;
    }
}
t("vyrovnání na nulu: $pocet dokladů = 0,00 €", $nula && $pocet > 500);
t("bez vyrovnání by zbyly centy ({$bezVyr}×) — test má zuby", $bezVyr > 0);
t('nenulový doklad vyrovnání nemění', $jinakBeze);
t('Kč doklad (k=1) se nevyrovnává', mena_doklad_polozky([[1, 100, 21], [1, -100, 21]], mena_doklad_ro($pdo, ['mena' => 'CZK']))['celkem'] == 0.0);
$r = mena_doklad_polozky([[3, 33.33, 12], [1, -99.99, 12]], mena_doklad_ro($pdo, ['mena' => 'EUR', 'mena_kurz' => 24.33, 'mena_zaklad' => 'CZK']));
t('vyrovnání jde do posledního záporného řádku', $r['celkem'] == 0.0 && $r['polozky'][1]['cena'] == $r['polozky'][1]['zaklad']);

// 14) round-trip x € → Kč → x € (spec 2.8) pro 10 000 náhodných x a kurzy ≥ 1
$rt = true;
for ($i = 0; $i < 10000; $i++) {
    $x = mt_rand(-100000, 1000000) / 100; $k = mt_rand(1000, 40000) / 1000;
    $kc = round($x * $k, 2);
    if (abs(round($kc / $k, 2) - $x) > 0.0001) { $rt = false; echo "round-trip $x @ $k\n"; break; }
}
t('round-trip x → Kč → x (10 000×)', $rt);

// 15) mena_nastav_doklad: zámky přepisu
$pdo = pdo_platba(['platba' => ['aktivni' => true]]);
mena_kurzy_aktualizuj($pdo);
$eur = mena_volba_platby($pdo, 'EUR', 'pos'); $czk = mena_volba_platby($pdo, 'CZK', 'pos');
$pdo->exec("INSERT INTO objednavky (id, castka_celkem, castka_bez_dph, castka_dph) VALUES (10, 159.30, 140, 19.30), (11, 50, 41.32, 8.68), (12, 50, 41.32, 8.68), (13, 50, 41.32, 8.68), (14, 50, 41.32, 8.68)");
$pdo->exec("INSERT INTO objednavky_polozky (objednavka_id, mnozstvi, cena_bez_dph, sazba_dph) VALUES (10, 1, 100, 21), (10, 1, 50, 12), (10, 1, -10, 21)");
t('snapshot nového dokladu', mena_nastav_doklad($pdo, 'objednavky', 10, $eur) === true);
t('bez přepisu se existující snapshot nemění', mena_nastav_doklad($pdo, 'objednavky', 10, $czk) === false && $pdo->query("SELECT mena FROM objednavky WHERE id=10")->fetchColumn() === 'EUR');
t('přepis nezaplacené objednávky', mena_nastav_doklad($pdo, 'objednavky', 10, $czk, true) && $pdo->query("SELECT mena FROM objednavky WHERE id=10")->fetchColumn() === 'CZK');
mena_nastav_doklad($pdo, 'objednavky', 10, $eur, true);
$pdo->exec("UPDATE objednavky SET pay_status='paid' WHERE id=11");
$pdo->exec("UPDATE objednavky SET stav='zaplaceno' WHERE id=12");
$pdo->exec("INSERT INTO dodaci_listy (id, objednavka_id) VALUES (1, 13)");
$pdo->exec("INSERT INTO faktury (id, objednavka_id) VALUES (1, 14)");
t('přepis: zaplaceno online', hazi(fn() => mena_nastav_doklad($pdo, 'objednavky', 11, $eur, true), 'doklad_zamceny'));
t('přepis: zaplaceno na pokladně', hazi(fn() => mena_nastav_doklad($pdo, 'objednavky', 12, $eur, true), 'doklad_zamceny'));
t('přepis: má DL', hazi(fn() => mena_nastav_doklad($pdo, 'objednavky', 13, $eur, true), 'doklad_zamceny'));
t('přepis: faktura bez DL objednávku nezamyká (faktura jde jen přes DL)', mena_nastav_doklad($pdo, 'objednavky', 14, $eur, true));
t('přepis faktury nikdy', hazi(fn() => mena_nastav_doklad($pdo, 'faktury', 1, $eur, true), 'doklad_zamceny'));
t('přepis neexistující objednávky', hazi(fn() => mena_nastav_doklad($pdo, 'objednavky', 999, $eur, true), 'doklad_nenalezen'));
t('neznámá tabulka', mena_nastav_doklad($pdo, 'odberatele', 1, $eur) === false);

// 16) mena_doc_castka / mena_ulozit_castku (objednávka)
$dc = mena_doc_castka($pdo, 'objednavky', 10);
$ocek = mena_doklad_polozky([[1, 100, 21], [1, 50, 12], [1, -10, 21]], mena_doklad_ro($pdo, $eur + ['mena_zaklad' => 'CZK']))['celkem'];
t('doc_castka EUR = výpočet po řádcích', $dc['mena'] === 'EUR' && $dc['celkem'] == $ocek && $dc['celkem_minor'] === (int) round($ocek * 100) && $dc['kurz'] == 25.17 && $dc['celkem_czk'] == 159.30);
t('ulozit_castku zapíše castka_mena', mena_ulozit_castku($pdo, 'objednavky', 10) == $ocek && (float) $pdo->query("SELECT castka_mena FROM objednavky WHERE id=10")->fetchColumn() == $ocek);
$q = mena_doc_castka($pdo, 'objednavky', 10, $czk);
t('doc_castka náhled v Kč (quote)', $q['mena'] === 'CZK' && $q['celkem'] == 159.30 && $q['celkem_minor'] === 15930);
t('doc_castka náhled v EUR (quote) = snapshot', mena_doc_castka($pdo, 'objednavky', 10, $eur)['celkem'] == $ocek);
t('doc_castka neexistující → 404', hazi(fn() => mena_doc_castka($pdo, 'objednavky', 999), 'doklad_nenalezen'));

// 17) účet stolu: bez snapshotu Kč (ne globál), položky se sazbou
$pdo->exec("INSERT INTO restaurant_pos_ucty (id) VALUES (5)");
$pdo->exec("INSERT INTO restaurant_pos_polozky (ucet_id, mnozstvi, jednotkova_cena, sazba_dph, stav) VALUES (5, 2, 121, 21, 'hotovo'), (5, 1, 112, 12, 'hotovo'), (5, 1, 999, NULL, 'storno'), (5, 1, 60.50, NULL, 'objednano')");
$u = mena_doc_castka($pdo, 'restaurant_pos_ucty', 5);
t('účet bez snapshotu = Kč', $u['mena'] === 'CZK' && $u['celkem'] == 414.50);
t('položky účtu: sazba, NULL = 21 %, storno pryč', mena_ucet_polozky($pdo, 5) === [[2.0, 100.0, 21.0], [1.0, 100.0, 12.0], [1.0, 50.0, 21.0]]);
mena_nastav_doklad($pdo, 'restaurant_pos_ucty', 5, $eur);
$u = mena_doc_castka($pdo, 'restaurant_pos_ucty', 5);
t('účet v EUR = po řádcích', $u['mena'] === 'EUR' && $u['celkem'] == mena_doklad_polozky(mena_ucet_polozky($pdo, 5), mena_doklad_ro($pdo, $eur + ['mena_zaklad' => 'CZK']))['celkem']);
t('účet: přepis nezaplaceného', mena_nastav_doklad($pdo, 'restaurant_pos_ucty', 5, $czk, true) && mena_doc_castka($pdo, 'restaurant_pos_ucty', 5)['mena'] === 'CZK');
$pdo->exec("UPDATE restaurant_pos_ucty SET stav='paid' WHERE id=5");
t('účet: zaplacený nejde přepsat', hazi(fn() => mena_nastav_doklad($pdo, 'restaurant_pos_ucty', 5, $eur, true), 'doklad_zamceny'));

// 18) ledger platby_doklad
$id1 = mena_platba_zapis($pdo, ['objednavka_id' => 10, 'zdroj' => 'quick', 'metoda' => 'hotovost', 'mena' => 'EUR', 'castka_mena' => 6.37, 'kurz' => 25.17, 'prijato_mena' => 10, 'vraceno_mena' => 3.63, 'idem' => 'abc']);
$r = $pdo->query("SELECT * FROM platby_doklad WHERE id = $id1")->fetch();
t('ledger: zápis + castka_czk = × kurz ČNB', $id1 > 0 && $r['castka_czk'] == round(6.37 * 25.17, 2) && $r['vraceno_mena'] == 3.63);
t('ledger: stejné idem = jeden řádek', mena_platba_zapis($pdo, ['zdroj' => 'quick', 'metoda' => 'hotovost', 'mena' => 'EUR', 'castka_mena' => 6.37, 'kurz' => 25.17, 'idem' => 'abc']) === $id1
    && (int) $pdo->query("SELECT COUNT(*) FROM platby_doklad")->fetchColumn() === 1);
t('ledger: přijatá platba zamkne přepis', hazi(fn() => mena_nastav_doklad($pdo, 'objednavky', 10, $czk, true), 'doklad_zamceny'));
$id2 = mena_platba_zapis($pdo, ['zdroj' => 'vratka', 'metoda' => 'karta', 'mena' => 'CZK', 'castka_mena' => -50, 'kurz' => 7]);
t('ledger: Kč kurz = 1, vratka záporně', (float) $pdo->query("SELECT kurz FROM platby_doklad WHERE id=$id2")->fetchColumn() == 1.0 && (float) $pdo->query("SELECT castka_czk FROM platby_doklad WHERE id=$id2")->fetchColumn() == -50.0);
$spatne = 0;
foreach ([['zdroj' => 'x'], ['zdroj' => 'quick', 'metoda' => 'bitcoin'], ['zdroj' => 'quick', 'metoda' => 'karta', 'mena' => 'XYZ', 'castka_mena' => 1, 'kurz' => 1],
          ['zdroj' => 'quick', 'metoda' => 'karta', 'mena' => 'EUR', 'kurz' => 25], ['zdroj' => 'quick', 'metoda' => 'karta', 'mena' => 'EUR', 'castka_mena' => 1]] as $bad) {
    try { mena_platba_zapis($pdo, $bad); } catch (InvalidArgumentException $e) { $spatne++; }
}
t('ledger: validace vstupu', $spatne === 5);

// 19) formát, IBAN
t('mena_fmt', mena_fmt(1234.5, 'EUR') === '1 234,50 €' && mena_fmt(-3, 'czk') === '-3,00 Kč');
$pdoI = pdo_platba(['platba' => ['iban_eur' => 'cz65 0800 0000 1920 0014 5399']]);
t('mena_iban_eur z nastavení', mena_iban_eur($pdoI) === 'CZ6508000000192000145399');
$pdoI2 = pdo_platba(null);
$pdoI2->prepare("INSERT INTO nastaveni VALUES ('payment_methods_config_json', ?)")->execute([json_encode(['prevod' => ['ucet_iban_eur' => 'SK31 1200 0000 1987 4263 7541']])]);
t('mena_iban_eur fallback z plateb', mena_iban_eur($pdoI2) === 'SK3112000000198742637541');
t('ČNB parser: nesmysl = null', mena_cnb_parse("ahoj") === null && mena_cnb_parse(listek('2026-11-13', ['EUR' => 24.5]))['datum'] === '2026-11-13');
unset($GLOBALS['MENA_CNB_FETCH']);

echo "mena_lib: $ok OK, $fail chyb\n";
exit($fail ? 1 : 0);
