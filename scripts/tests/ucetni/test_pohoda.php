<?php
/**
 * 🧪 POHODA — builder XML + validace proti oficiálním XSD + parser responsePack.
 *
 *   UCETNI_XSD_DIR=/cesta/k/xsd  (oficiální sada z https://www.stormware.cz/schema/version_2/, kořen data.xsd)
 *   UCETNI_SAMPLES_DIR=/cesta    (volitelně: oficiální vzorové odpovědi Stormware ke kontrole parseru)
 *   php scripts/tests/ucetni/test_pohoda.php
 *
 * Zapisuje jen testovací doklady (FA-TEST-0001 / DOB-TEST-0001) do testovací DB.
 */
require __DIR__ . '/_bootstrap.php';
require __DIR__ . '/_fixtures.php';

$pdo = db();
$xsdDir = getenv('UCETNI_XSD_DIR') ?: '';
$out = test_out_dir();
$xmllint = trim((string) shell_exec('command -v xmllint'));

function validuj(string $file, string $xsd): array {
    $cmd = 'xmllint --noout --nonet --schema ' . escapeshellarg($xsd) . ' ' . escapeshellarg($file) . ' 2>&1';
    exec($cmd, $lines, $rc);
    return [$rc === 0, implode("\n", array_slice($lines, 0, 8))];
}

echo "🧪 POHODA XML\n";
ok($xsdDir !== '' && is_file($xsdDir . '/data.xsd'), "oficiální XSD k dispozici ($xsdDir/data.xsd)");
ok($xmllint !== '', 'xmllint k dispozici');

$fx = fixture_testovaci_doklady($pdo);
$dobReal = (int) $pdo->query("SELECT id FROM faktury WHERE je_dobropis = 1 AND cislo LIKE 'DOB-2026-%' ORDER BY id LIMIT 1")->fetchColumn();
$dlFa = (int) $pdo->query("SELECT f.id FROM faktury f JOIN faktury_dodaci_listy x ON x.faktura_id = f.id
                           WHERE NOT EXISTS (SELECT 1 FROM faktura_polozky p WHERE p.faktura_id = f.id)
                           AND EXISTS (SELECT 1 FROM dodaci_list_polozky d WHERE d.dodaci_list_id = x.dodaci_list_id) ORDER BY f.id LIMIT 1")->fetchColumn();
$pripady = [
    'FA-2026-0001 (4 položky, 12 %)' => 1,
    'vícesazbová FA-TEST-0001'       => $fx['faktura_id'],
    'dobropis DOB-TEST-0001'         => $fx['dobropis_id'],
];
if ($dobReal) $pripady['dobropis ze sondy (reálná data)'] = $dobReal;
if ($dlFa) $pripady['faktura z dodacího listu (položky z DL)'] = $dlFa;

$items = [];
foreach ($pripady as $popis => $id) {
    echo "── $popis (id $id)\n";
    $inv = ucetni_nacti_fakturu($pdo, $id);
    ok($inv !== null, 'faktura načtena');
    if (!$inv) continue;
    ok(count($inv['polozky']) > 0, 'má položky (' . count($inv['polozky']) . ', zdroj ' . $inv['polozky_zdroj'] . ')');

    // součty = hlavička (PDF „K úhradě") + rozpis = faktura.php
    $sumZ = 0; $sumD = 0;
    foreach ($inv['polozky'] as $p) { $sumZ += $p['zaklad']; $sumD += $p['dph']; }
    ok(abs(round($sumZ, 2) - $inv['celkem_bez']) < 0.001, "Σ základů položek = základ faktury ({$inv['celkem_bez']})");
    ok(abs(round($sumD, 2) - $inv['celkem_dph']) < 0.001, "Σ DPH položek = DPH faktury ({$inv['celkem_dph']})");
    ok(abs(round($inv['celkem_bez'] + $inv['celkem_dph'], 2) - $inv['celkem']) < 0.001, "základ + DPH = celkem ({$inv['celkem']})");
    if ($inv['polozky_zdroj'] === 'faktura') {
        $ref = reference_pdf_rozpis($pdo, $id);
        $shoda = true;
        foreach ($ref['rozpis'] as $s => $r) {
            $k = ucetni_num($s, 2);
            if (!isset($inv['rekapitulace'][$k]) || abs($inv['rekapitulace'][$k]['zaklad'] - $r['bez']) > 0.001 || abs($inv['rekapitulace'][$k]['dph'] - $r['dph']) > 0.001) $shoda = false;
        }
        ok($shoda && count($ref['rozpis']) === count($inv['rekapitulace']), 'rozpis DPH po sazbách = algoritmus faktura.php (PDF)');
    }

    $b = pohoda_xml_invoice($inv, ['predkontace' => '3Fv', 'cleneni' => 'UD', 'puvodni_v_pohode' => !empty($inv['je_dobropis'])]);
    ok($b['ok'], 'XML sestaveno' . ($b['ok'] ? '' : ': ' . $b['error']));
    if (!$b['ok']) continue;
    $items[] = ['id' => 'fa-' . $id, 'xml' => $b['xml']];
    $xml = pohoda_datapack([['id' => 'fa-' . $id, 'xml' => $b['xml']]], '12345678', 'test-' . $id, 'APPEK integration test');
    $file = "$out/pohoda_faktura_$id.xml";
    file_put_contents($file, $xml);
    [$valid, $msg] = validuj($file, "$xsdDir/data.xsd");
    ok($valid, "validní proti XSD ($file)" . ($valid ? '' : "\n$msg"));

    // zpětné čtení vlastního XML: typ dokladu + souhrn = APPEK
    $sx = simplexml_load_string($xml);
    $sx->registerXPathNamespace('inv', POHODA_NS['inv']); $sx->registerXPathNamespace('typ', POHODA_NS['typ']);
    $typ = (string) ($sx->xpath('//inv:invoiceType')[0] ?? '');
    ok($typ === ($inv['je_dobropis'] ? 'issuedCorrectiveTax' : 'issuedInvoice'), "typ dokladu $typ");
    $hc = function ($n) use ($sx) { return (float) ($sx->xpath("//inv:invoiceSummary/inv:homeCurrency/typ:$n")[0] ?? 0); };
    $celkem = $hc('priceNone') + $hc('priceLowSum') + $hc('priceHighSum') + $hc('price3Sum');
    ok(abs(round($celkem, 2) - $inv['celkem']) < 0.001, "souhrn XML celkem " . ucetni_num($celkem) . " = APPEK {$inv['celkem']}");
    $polSum = 0; foreach ($sx->xpath('//inv:invoiceItem/inv:homeCurrency/typ:priceSum') as $v) $polSum += (float) $v;
    ok(abs(round($polSum, 2) - $inv['celkem']) < 0.001, 'Σ položek s DPH v XML = celkem');
    ok((string) ($sx->xpath('//inv:number/typ:numberRequested/@checkDuplicity')[0] ?? '') === 'true', 'číslo dokladu s kontrolou duplicity');
    ok((string) ($sx->xpath('//inv:number/typ:numberRequested')[0] ?? '') === $inv['cislo'], 'číslo dokladu = číslo APPEK');
    if ($inv['je_dobropis']) ok(count($sx->xpath('//inv:correctiveDocument/typ:sourceDocument/typ:number')) === 1, 'opravný doklad odkazuje na původní fakturu');
}

echo "── kódování Windows-1250 + escapování\n";
$f = "$out/pohoda_faktura_{$fx['faktura_id']}.xml";
$raw = (string) @file_get_contents($f);
ok(str_starts_with($raw, '<?xml version="1.0" encoding="Windows-1250"?>'), 'deklarace encoding="Windows-1250"');
ok(strpos($raw, "\xC4\x8C") === false && strpos($raw, "\xC8okol") !== false, 'bajty jsou Windows-1250 (Č = 0xC8, ne UTF-8 0xC4 0x8C)');
$u = @iconv('WINDOWS-1250', 'UTF-8', $raw);
ok($u !== false && str_contains($u, 'Čokoládový dort „Sacher&quot; &amp; &lt;speciál&gt; €'), 'diakritika, „uvozovky", € a &<> správně (escapováno)');
ok($u !== false && !str_contains($u, '🎂'), 'znak mimo Windows-1250 (emoji) nahrazen');
$sx = simplexml_load_string($raw);
$sx->registerXPathNamespace('inv', POHODA_NS['inv']);
$t = (string) ($sx->xpath('//inv:invoiceItem/inv:text')[0] ?? '');
ok(mb_strlen($t) <= 90, 'text položky oříznutý na 90 znaků (string90)');

echo "── více dokladů v jednom dataPacku\n";
$file = "$out/pohoda_davka.xml";
file_put_contents($file, pohoda_datapack($items, '12345678', 'test-davka', 'APPEK integration test'));
[$valid, $msg] = validuj($file, "$xsdDir/data.xsd");
ok($valid, 'dávka ' . count($items) . ' dokladů validní' . ($valid ? '' : "\n$msg"));

echo "── adresář + seznam přijatých faktur\n";
$odbs = $pdo->query("SELECT id, cislo, nazev, ico, dic, ulice, mesto, psc, email, telefon, web, splatnost_dni FROM odberatele ORDER BY id LIMIT 40")->fetchAll(PDO::FETCH_ASSOC);
$adb = [];
foreach ($odbs as $o) $adb[] = ['id' => 'odb-' . $o['id'], 'xml' => pohoda_xml_addressbook($o)];
$adb[] = ['id' => 'odb-bez-ico', 'xml' => pohoda_xml_addressbook(['id' => 0, 'nazev' => 'Fyzická osoba bez IČO', 'mesto' => 'Brno'])];
$file = "$out/pohoda_adresar.xml";
file_put_contents($file, pohoda_datapack($adb, '12345678', 'test-adr', 'APPEK integration test'));
[$valid, $msg] = validuj($file, "$xsdDir/data.xsd");
ok($valid, 'adresář (' . count($adb) . ' záznamů, upsert dle IČO) validní' . ($valid ? '' : "\n$msg"));
$file = "$out/pohoda_list_prijate.xml";
file_put_contents($file, pohoda_datapack([['id' => 'list', 'xml' => pohoda_xml_list_received('2026-01-01', '2026-12-31')]], '12345678', 'test-list', 'APPEK integration test'));
[$valid, $msg] = validuj($file, "$xsdDir/data.xsd");
ok($valid, 'listInvoiceRequest (přijaté faktury) validní' . ($valid ? '' : "\n$msg"));

echo "── sazby DPH / chyby builderu\n";
ok(pohoda_rate_vat(21) === 'high' && pohoda_rate_vat(12) === 'low' && pohoda_rate_vat(0) === 'none' && pohoda_rate_vat(10) === 'third', 'mapování sazeb 21/12/10/0');
$bad = ucetni_nacti_fakturu($pdo, 1); $bad['polozky'][0]['sazba'] = 7.5;
$r = pohoda_xml_invoice($bad);
ok(!$r['ok'] && str_contains($r['error'], '7.50'), 'nepodporovaná sazba → česká chyba: ' . ($r['error'] ?? ''));

echo "── parser responsePack\n";
$ok1 = '<?xml version="1.0" encoding="Windows-1250"?>
<rsp:responsePack version="2.0" id="appek-1" state="ok" note="" programVersion="14102.4" xmlns:rsp="http://www.stormware.cz/schema/version_2/response.xsd" xmlns:rdc="http://www.stormware.cz/schema/version_2/documentresponse.xsd" xmlns:inv="http://www.stormware.cz/schema/version_2/invoice.xsd">
  <rsp:responsePackItem version="2.0" id="fa-1" state="ok">
    <inv:invoiceResponse version="2.0" state="ok"><rdc:producedDetails><rdc:id>95</rdc:id><rdc:number>FA-2026-0001</rdc:number></rdc:producedDetails></inv:invoiceResponse>
  </rsp:responsePackItem>
  <rsp:responsePackItem version="2.0" id="fa-2" state="ok">
    <inv:invoiceResponse version="2.0" state="ok">
      <rdc:importDetails><rdc:detail><rdc:state>warning</rdc:state><rdc:errno>205</rdc:errno><rdc:note>' . pohoda_to_1250('Předkontace nenalezena, použita výchozí.') . '</rdc:note><rdc:XPath>/dat:dataPack/dat:dataPackItem[2]/inv:invoice/inv:invoiceHeader/inv:accounting</rdc:XPath></rdc:detail></rdc:importDetails>
      <rdc:producedDetails><rdc:id>96</rdc:id><rdc:number>FA-2026-0002</rdc:number></rdc:producedDetails>
    </inv:invoiceResponse>
  </rsp:responsePackItem>
  <rsp:responsePackItem version="2.0" id="fa-3" state="error" note="">
    <inv:invoiceResponse version="2.0" state="error">
      <rdc:importDetails><rdc:detail><rdc:state>error</rdc:state><rdc:errno>103</rdc:errno><rdc:note>' . pohoda_to_1250('Doklad s tímto číslem již existuje.') . '</rdc:note><rdc:XPath>/dat:dataPack/dat:dataPackItem[3]/inv:invoice/inv:invoiceHeader/inv:number/typ:numberRequested</rdc:XPath><rdc:valueRequested>FA-2026-0003</rdc:valueRequested></rdc:detail></rdc:importDetails>
    </inv:invoiceResponse>
  </rsp:responsePackItem>
</rsp:responsePack>';
$file = "$out/pohoda_response_vzor.xml";
file_put_contents($file, $ok1);
[$valid, $msg] = validuj($file, "$xsdDir/response.xsd");
ok($valid, 'testovací odpověď (ok + warning + error) je validní proti response.xsd' . ($valid ? '' : "\n$msg"));
$p = pohoda_parse_response($ok1);
ok($p['ok'] && $p['state'] === 'ok' && count($p['items']) === 3, 'rozparsováno 3 položky');
ok($p['items'][0]['state'] === 'ok' && $p['items'][0]['produced_id'] === '95' && $p['items'][0]['produced_number'] === 'FA-2026-0001', 'ok: produced id + číslo');
ok($p['items'][1]['state'] === 'warning' && str_contains(pohoda_item_messages($p['items'][1]), 'Předkontace nenalezena'), 'warning: text upozornění (z Windows-1250)');
ok($p['items'][2]['state'] === 'error' && str_contains(pohoda_item_messages($p['items'][2]), 'Chyba 103: Doklad s tímto číslem již existuje'), 'error: číslo + text chyby');
$err = '<?xml version="1.0" encoding="Windows-1250"?><rsp:responsePack version="2.0" id="x" state="error" note="' . pohoda_to_1250('Neplatný formát IČO účetní jednotky.') . '" xmlns:rsp="http://www.stormware.cz/schema/version_2/response.xsd"/>';
$p = pohoda_parse_response($err);
ok($p['ok'] && $p['state'] === 'error' && $p['note'] === 'Neplatný formát IČO účetní jednotky.', 'chyba celé zásilky (state=error + note)');
$p = pohoda_parse_response('<html><body>502 Bad Gateway</body></html>');
ok(!$p['ok'] && $p['error'] !== '', 'HTML místo XML → srozumitelná chyba');
$p = pohoda_parse_response('');
ok(!$p['ok'], 'prázdná odpověď → chyba');
$p = pohoda_parse_response('<?xml version="1.0"?><!DOCTYPE x [<!ENTITY e SYSTEM "file:///etc/passwd">]><rsp:responsePack xmlns:rsp="http://www.stormware.cz/schema/version_2/response.xsd" state="ok" note="&e;"/>');
ok(!str_contains((string) ($p['note'] ?? ''), 'root:'), 'XXE: externí entita se nenačte');

$samples = getenv('UCETNI_SAMPLES_DIR') ?: '';
if ($samples !== '' && is_dir($samples)) {
    echo "── oficiální vzorové odpovědi Stormware ($samples)\n";
    foreach (glob($samples . '/*esp*.xml') ?: [] as $sf) {
        $p = pohoda_parse_response((string) file_get_contents($sf));
        ok($p['ok'] && $p['state'] === 'ok' && count($p['items']) > 0, basename($sf) . ': ' . count($p['items']) . ' položek, state ok');
        foreach ($p['items'] as $it) {
            if ($it['produced_id'] !== '') ok($it['produced_number'] !== '', basename($sf) . " položka {$it['id']}: id {$it['produced_id']} / č. {$it['produced_number']}");
            foreach ($it['invoices'] as $iv) {
                ok($iv['cislo'] !== '' && $iv['datum'] !== '', basename($sf) . " seznam: {$iv['cislo']} {$iv['datum']} {$iv['dodavatel']} {$iv['celkem']} {$iv['mena']}");
            }
        }
    }
}

echo "── idempotence\n";
$st = ['ok_at' => '2026-11-10 10:00:00', 'hash' => str_repeat('a', 64), 'externi_cislo' => 'FA-1'];
ok(ucetni_idempotence(null, 'x', false)['pokracovat'], 'nikdy neexportováno → export');
ok(ucetni_idempotence($st, str_repeat('a', 64), false)['duvod'] === 'beze_zmeny', 'stejný obsah → přeskočit');
ok(ucetni_idempotence($st, str_repeat('b', 64), false)['duvod'] === 'zmeneno', 'změněný obsah → vyžádat potvrzení');
ok(ucetni_idempotence($st, str_repeat('a', 64), true)['pokracovat'], 'force → export');
$inv1 = ucetni_nacti_fakturu($pdo, 1); $inv1b = ucetni_nacti_fakturu($pdo, 1);
ok($inv1['hash'] === $inv1b['hash'], 'otisk faktury je stabilní');
$inv1b['polozky'][0]['mnozstvi'] += 1;
ok(ucetni_hash($inv1b) !== $inv1['hash'], 'změna množství změní otisk');

konec();
