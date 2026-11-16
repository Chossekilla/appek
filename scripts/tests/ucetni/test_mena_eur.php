<?php
/**
 * 🧪 Doklady v EUR end-to-end (v3.0.640): faktura + dobropis v cizí měně přes skutečné API,
 *    PDF ↔ export do účetnictví (POHODA XML proti XSD, volitelně živě FlexiBee), trvalý přepočet ceníku.
 *
 * Potřebuje běžící APPEK nad TESTOVACÍ DB (jako test_endpoint.php):
 *   UCETNI_TEST_DB=appek_int_test UCETNI_XSD_DIR=… UCETNI_E2E_BASE=http://127.0.0.1:8099 \
 *   UCETNI_E2E_EMAIL=… UCETNI_E2E_PASS=… [UCETNI_FLEXI_LIVE=1] php scripts/tests/ucetni/test_mena_eur.php
 * Měnu a ceny výrobků testovací DB na konci vrací zpět (vystavené testovací doklady zůstávají).
 */
require __DIR__ . '/_bootstrap.php';
require_once $ROOT . '/api/_ucetni_lib.php';
require_once $ROOT . '/api/_pohoda.php';
require_once $ROOT . '/api/_flexibee.php';
require_once $ROOT . '/api/_mena_lib.php';

$base = rtrim(getenv('UCETNI_E2E_BASE') ?: 'http://127.0.0.1:8099', '/');
$xsd  = getenv('UCETNI_XSD_DIR') ?: '';
$pdo  = db();
$jar  = tempnam(sys_get_temp_dir(), 'eurjar');

function http2(string $method, string $url, ?array $json, string $jar, ?string $csrf = null): array {
    $ch = curl_init($url);
    $h = ['Content-Type: application/json'];
    if ($csrf) $h[] = 'X-CSRF-Token: ' . $csrf;
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_CUSTOMREQUEST => $method, CURLOPT_HTTPHEADER => $h,
        CURLOPT_COOKIEJAR => $jar, CURLOPT_COOKIEFILE => $jar, CURLOPT_TIMEOUT => 60]);
    if ($json !== null) curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($json));
    $body = (string) curl_exec($ch); $code = curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
    return ['code' => $code, 'body' => $body, 'json' => json_decode($body, true)];
}
/** „K úhradě" z PDF faktury (HTML) → číslo v měně dokladu. */
function pdf_k_uhrade(string $html): ?float {
    $pos = strpos($html, 'class="summary-final"');
    if ($pos === false || !preg_match('/<div class="val">([^<]+)<\/div>/u', substr($html, $pos), $m)) return null;
    $s = html_entity_decode($m[1], ENT_QUOTES, 'UTF-8');
    $s = preg_replace('/[^\d,\-]/u', '', str_replace('.', '', $s));   // cs formát: 1 234,50 €
    return (float) str_replace(',', '.', $s);
}
function validuj_xml(string $xml, string $xsd): array {
    $f = tempnam(sys_get_temp_dir(), 'pohoda') . '.xml'; file_put_contents($f, $xml);
    exec('xmllint --noout --nonet --schema ' . escapeshellarg("$xsd/data.xsd") . ' ' . escapeshellarg($f) . ' 2>&1', $out, $rc);
    @unlink($f);
    return [$rc === 0, implode("\n", $out)];
}

$r = http2('POST', "$base/api/admin_login.php", ['email' => getenv('UCETNI_E2E_EMAIL'), 'heslo' => getenv('UCETNI_E2E_PASS')], $jar);
$csrf = $r['json']['csrf_token'] ?? null;
ok($r['code'] === 200 && $csrf, 'admin přihlášen');

// záloha stavu (měna + ceny výrobků + pevné ceny skupin) → na konci obnova
$puvodniMena = $pdo->query("SELECT hodnota FROM nastaveni WHERE klic = 'mena_config_json'")->fetchColumn();
$puvodniBanka = $pdo->query("SELECT hodnota FROM nastaveni WHERE klic = 'firma_banka'")->fetchColumn();
// bankovní účet (fiktivní, validní tvar) → faktura kreslí QR platbu
$pdo->prepare("INSERT INTO nastaveni (klic, hodnota) VALUES ('firma_banka', '19-2000145399/0800') ON DUPLICATE KEY UPDATE hodnota = VALUES(hodnota)")->execute();
$puvodniCeny = $pdo->query("SELECT id, cena_bez_dph FROM vyrobky")->fetchAll(PDO::FETCH_KEY_PAIR);
$puvodniSkup = [];
try { $puvodniSkup = $pdo->query("SELECT id, pevna_cena FROM cenove_skupiny_slevy WHERE pevna_cena IS NOT NULL")->fetchAll(PDO::FETCH_KEY_PAIR); } catch (Throwable $e) {}
$odb = (int) $pdo->query("SELECT id FROM odberatele ORDER BY id LIMIT 1")->fetchColumn();

try {
    echo "── prodej v EUR (ceník v Kč, přepočet kurzem)\n";
    $r = http2('POST', "$base/api/admin_mena.php?action=save", ['config' => ['kod' => 'EUR', 'kurz' => 1, 'zobrazeni' => 'mena']], $jar, $csrf);
    ok($r['code'] === 400 && str_contains($r['body'], 'Zadej kurz'), 'kurz 1 u EUR odmítnut (nevyplněný kurz)');
    $r = http2('POST', "$base/api/admin_mena.php?action=save", ['config' => ['kod' => 'EUR', 'kurz' => 25.25, 'zobrazeni' => 'mena']], $jar, $csrf);
    ok(($r['json']['config']['delitel'] ?? 0) == 25.25, 'měna EUR uložena (delitel = kurz)');
    $polozky = [
        ['vyrobek_nazev' => 'Chléb kváskový', 'jednotka' => 'ks', 'mnozstvi' => 10, 'cena_bez_dph' => 45.50, 'sazba_dph' => 12],
        ['vyrobek_nazev' => 'Dort čokoládový', 'jednotka' => 'kg', 'mnozstvi' => 2.5, 'cena_bez_dph' => 389, 'sazba_dph' => 12],
        ['vyrobek_nazev' => 'Doprava', 'jednotka' => 'ks', 'mnozstvi' => 1, 'cena_bez_dph' => 250, 'sazba_dph' => 21],
        ['vyrobek_nazev' => 'Záloha přepravka', 'jednotka' => 'ks', 'mnozstvi' => 3, 'cena_bez_dph' => 33.33, 'sazba_dph' => 0],
    ];
    $r = http2('POST', "$base/api/admin_faktury.php", ['odberatel_id' => $odb, 'poznamka' => 'APPEK TEST EUR', 'polozky' => $polozky], $jar, $csrf);
    $faId = (int) ($r['json']['id'] ?? 0);
    ok($faId > 0, 'faktura vystavena přes API');
    $row = $pdo->query("SELECT mena, mena_kurz, mena_zaklad, castka_celkem FROM faktury WHERE id = $faId")->fetch(PDO::FETCH_ASSOC);
    ok($row['mena'] === 'EUR' && abs($row['mena_kurz'] - 25.25) < 1e-6 && $row['mena_zaklad'] === 'CZK', 'snapshot měny na faktuře EUR / 25,25 / Kč');

    $html = http2('GET', "$base/api/faktura.php?id=$faId", null, $jar)['body'];
    $pdf = pdf_k_uhrade($html);
    $inv = ucetni_nacti_fakturu($pdo, $faId);
    ok($inv['mena'] === 'EUR' && isset($inv['cizi']), 'export: měna EUR + částky v cizí měně');
    ok($pdf !== null && abs($pdf - $inv['cizi']['celkem']) < 0.001, "PDF „K úhradě\" ($pdf) = export EUR ({$inv['cizi']['celkem']})");
    $czkRek = 0.0; foreach ($inv['rekapitulace'] as $rr) $czkRek += $rr['zaklad'] + $rr['dph'];
    ok(abs($inv['celkem'] - round($czkRek, 2)) < 0.001 && abs($inv['celkem'] - (float) $row['castka_celkem']) < 1.0,
       "export Kč ({$inv['celkem']}) = rekapitulace v Kč (EUR × kurz), ~ hlavička {$row['castka_celkem']}");
    // jednotková cena v EUR na centy a řádek = mn × cena (žádné „10 × 1,80 = 18,02")
    $radkyOk = true;
    foreach ($inv['cizi']['polozky'] as $ix => $p) {
        if (abs($p['cena_mj'] - round($p['cena_mj'], 2)) > 1e-9 || abs($p['zaklad'] - round($inv['polozky'][$ix]['mnozstvi'] * $p['cena_mj'], 2)) > 1e-9) $radkyOk = false;
    }
    ok($radkyOk, 'EUR položky: cena/ks na centy, řádek = množství × cena');
    $sum = 0.0; foreach ($inv['cizi']['polozky'] as $p) $sum += $p['zaklad'] + $p['dph'];
    ok(abs(round($sum, 2) - $inv['cizi']['celkem']) < 0.001, 'Σ položek v EUR = celkem v EUR');
    ok(str_contains($html, 'CC:EUR') && str_contains($html, 'Rekapitulace DPH v Kč'), 'PDF: QR platba v EUR + rekapitulace DPH v Kč');

    if ($xsd !== '' && is_file("$xsd/data.xsd")) {
        $x = pohoda_xml_invoice($inv, []);
        ok($x['ok'], 'POHODA XML sestaveno');
        [$v, $msg] = validuj_xml(pohoda_datapack([['id' => 'fa' . $faId, 'xml' => $x['xml']]], '12345678', 'eur-test'), $xsd);
        ok($v, 'POHODA XML (EUR) validní proti XSD' . ($v ? '' : "\n$msg"));
        ok(str_contains($x['xml'], '<typ:ids>EUR</typ:ids>') && str_contains($x['xml'], '<typ:rate>25.250</typ:rate>')
           && str_contains($x['xml'], '<typ:priceSum>' . ucetni_num($inv['cizi']['celkem'], 2) . '</typ:priceSum></inv:foreignCurrency></inv:invoiceSummary>'),
           'POHODA souhrn: EUR, kurz, priceSum = PDF');
    } else { echo "  (XSD validace přeskočena — chybí UCETNI_XSD_DIR)\n"; }

    echo "── dobropis po přepnutí prodeje zpět na Kč\n";
    http2('POST', "$base/api/admin_mena.php?action=save", ['config' => ['kod' => 'CZK']], $jar, $csrf);
    $r = http2('POST', "$base/api/admin_faktury.php?action=dobropis", ['faktura_id' => $faId, 'duvod' => 'APPEK TEST EUR'], $jar, $csrf);
    $dobId = (int) ($r['json']['id'] ?? 0);
    $drow = $pdo->query("SELECT mena, mena_kurz FROM faktury WHERE id = $dobId")->fetch(PDO::FETCH_ASSOC);
    ok($dobId && $drow['mena'] === 'EUR' && abs($drow['mena_kurz'] - 25.25) < 1e-6, 'dobropis zdědil EUR + kurz původní faktury');
    $dinv = ucetni_nacti_fakturu($pdo, $dobId);
    ok(abs($dinv['cizi']['celkem'] + $inv['cizi']['celkem']) < 0.001, 'dobropis v EUR = −faktura v EUR');
    $dpdf = pdf_k_uhrade(http2('GET', "$base/api/faktura.php?id=$dobId", null, $jar)['body']);
    ok($dpdf !== null && abs($dpdf - $dinv['cizi']['celkem']) < 0.001, "PDF dobropisu ($dpdf) = export EUR");
    if ($xsd !== '' && is_file("$xsd/data.xsd")) {
        $x = pohoda_xml_invoice($dinv, ['puvodni_v_pohode' => true]);
        [$v, $msg] = validuj_xml(pohoda_datapack([['id' => 'd' . $dobId, 'xml' => $x['xml']]], '12345678', 'eur-dob'), $xsd);
        ok($v, 'POHODA XML dobropisu (EUR) validní proti XSD' . ($v ? '' : "\n$msg"));
    }

    if (getenv('UCETNI_FLEXI_LIVE') === '1') {
        echo "── živě: FlexiBee demo (doklad v EUR)\n";
        $cfg = ['flexibee_enabled' => '1', 'flexibee_url' => 'https://demo.flexibee.eu', 'flexibee_company' => 'demo',
                'flexibee_username' => 'winstrom', 'flexibee_password' => 'winstrom',
                'flexibee_typ_faktura' => '', 'flexibee_typ_dobropis' => '', 'ucetni_povolit_lan' => '0'];
        $run = strtoupper(base_convert((string) time(), 10, 36));
        $live = $inv; $live['cislo'] = 'EUR-' . $run; $live['id'] = 900000000 + (time() % 99999999);
        $r = flexi_export_invoice($cfg, $live);
        ok($r['ok'], 'FlexiBee: faktura v EUR zapsána' . ($r['ok'] ? '' : ' — ' . $r['error']));
        if ($r['ok']) {
            $g = flexi_request($cfg, 'GET', 'faktura-vydana/(kod=%27' . rawurlencode($live['cislo']) . '%27).json?detail=custom:kod,mena,kurz,sumCelkem,sumCelkemMen');
            $fv = $g['data']['faktura-vydana'][0] ?? [];
            echo '    FlexiBee vrátil: ' . json_encode($fv, JSON_UNESCAPED_UNICODE) . "\n";
            ok(str_contains((string) ($fv['mena'] ?? ''), 'EUR'), 'FlexiBee: měna dokladu EUR');
            ok(abs((float) ($fv['sumCelkemMen'] ?? 0) - $inv['cizi']['celkem']) < 0.001, "FlexiBee: celkem v EUR {$fv['sumCelkemMen']} = PDF {$inv['cizi']['celkem']} (na cent)");
        }
    }

    echo "── trvalý přepočet ceníku do EUR\n";
    http2('POST', "$base/api/admin_mena.php?action=save", ['config' => ['kod' => 'EUR', 'kurz' => 25.25, 'zobrazeni' => 'kc']], $jar, $csrf);
    $vid = (int) $pdo->query("SELECT id FROM vyrobky WHERE cena_bez_dph > 10 ORDER BY id LIMIT 1")->fetchColumn();
    $cenaKc = (float) $puvodniCeny[$vid];
    $r = http2('POST', "$base/api/admin_mena.php?action=prepocet", ['kurz' => 25.25, 'kod' => 'EUR'], $jar, $csrf);
    ok(($r['json']['ok'] ?? false) && ($r['json']['config']['zakladni'] ?? '') === 'EUR', 'přepočet hotov, ceník je trvale v EUR');
    ok(($r['json']['config']['delitel'] ?? 0) == 1.0 && ($r['json']['config']['zobrazeni'] ?? '') === 'mena', 'po přepočtu se už nedělí kurzem (delitel 1) a prodává se v EUR');
    $nova = (float) $pdo->query("SELECT cena_bez_dph FROM vyrobky WHERE id = $vid")->fetchColumn();
    ok(abs($nova - round($cenaKc / 25.25, 2)) < 0.001, "cena výrobku $cenaKc Kč → $nova EUR");
    $r = http2('POST', "$base/api/admin_mena.php?action=prepocet", ['kurz' => 25.25, 'kod' => 'EUR'], $jar, $csrf);
    ok($r['code'] === 400, 'druhý přepočet odmítnut');
    $r = http2('POST', "$base/api/admin_mena.php?action=save", ['config' => ['kod' => 'CZK']], $jar, $csrf);
    ok($r['code'] === 400, 'přepnutí zpět na Kč odmítnuto (návrat = záloha)');
    $br = http2('GET', "$base/api/firma_branding.php", null, $jar)['json'];
    ok(($br['mena']['delitel'] ?? 0) == 1.0 && ($br['mena']['kod'] ?? '') === 'EUR', 'B2B dostává EUR bez dalšího dělení');

    $r = http2('POST', "$base/api/admin_faktury.php", ['odberatel_id' => $odb, 'poznamka' => 'APPEK TEST EUR zaklad',
        'polozky' => [['vyrobek_nazev' => 'Chléb', 'jednotka' => 'ks', 'mnozstvi' => 4, 'cena_bez_dph' => 1.80, 'sazba_dph' => 12]]], $jar, $csrf);
    $f2 = (int) ($r['json']['id'] ?? 0);
    $row2 = $pdo->query("SELECT mena, mena_zaklad, castka_celkem FROM faktury WHERE id = $f2")->fetch(PDO::FETCH_ASSOC);
    ok($row2['mena'] === 'EUR' && $row2['mena_zaklad'] === 'EUR', 'nová faktura: měna EUR, částky uložené v EUR');
    $html2 = http2('GET', "$base/api/faktura.php?id=$f2", null, $jar)['body'];
    $inv2 = ucetni_nacti_fakturu($pdo, $f2);
    ok(abs((float) pdf_k_uhrade($html2) - (float) $row2['castka_celkem']) < 0.001, 'PDF = uložená částka (žádné dělení kurzem)');
    ok(abs($inv2['cizi']['celkem'] - (float) $row2['castka_celkem']) < 0.001 && abs($inv2['celkem'] - (round(7.20 * 25.25, 2) + round(0.86 * 25.25, 2))) < 0.001,
       "export: EUR {$inv2['cizi']['celkem']}, Kč {$inv2['celkem']} (= ×kurz)");
    ok(str_contains($html2, 'Rekapitulace DPH v Kč'), 'PDF: rekapitulace DPH v Kč i po přepočtu');
} finally {
    if ($puvodniBanka === false) $pdo->exec("DELETE FROM nastaveni WHERE klic = 'firma_banka'");
    else $pdo->prepare("UPDATE nastaveni SET hodnota = ? WHERE klic = 'firma_banka'")->execute([$puvodniBanka]);
    $pdo->prepare("INSERT INTO nastaveni (klic, hodnota) VALUES ('mena_config_json', ?) ON DUPLICATE KEY UPDATE hodnota = VALUES(hodnota)")
        ->execute([$puvodniMena ?: json_encode(['kod' => 'CZK', 'kurz' => 1, 'zobrazeni' => 'kc'])]);
    $u = $pdo->prepare("UPDATE vyrobky SET cena_bez_dph = ? WHERE id = ?");
    foreach ($puvodniCeny as $id => $c) $u->execute([$c, $id]);
    $u = $pdo->prepare("UPDATE cenove_skupiny_slevy SET pevna_cena = ? WHERE id = ?");
    foreach ($puvodniSkup as $id => $c) $u->execute([$c, $id]);
    @unlink($jar);
}
konec();
