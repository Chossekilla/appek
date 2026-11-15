<?php
/**
 * 🧪 ABRA Flexi / FlexiBee — builder, parser odpovědí, SSRF/chybové cesty a (volitelně) živý round-trip.
 *
 *   php scripts/tests/ucetni/test_flexibee.php                 → offline část + chybové cesty (DNS/spojení/timeout)
 *   UCETNI_FLEXI_LIVE=1 php scripts/tests/ucetni/test_flexibee.php
 *        → navíc round-trip proti VEŘEJNÉMU demo serveru ABRA Flexi (https://demo.flexibee.eu, firma demo,
 *          winstrom/winstrom — veřejné přístupy z dokumentace ABRA). Testovací záznamy jsou označené
 *          „APPEK TEST s.r.o." / „APPEK integration test" a na konci se SMAŽOU.
 *   Jiný server: UCETNI_FLEXI_URL / UCETNI_FLEXI_FIRMA / UCETNI_FLEXI_USER / UCETNI_FLEXI_PASS
 */
require __DIR__ . '/_bootstrap.php';
require __DIR__ . '/_fixtures.php';

$pdo = db();
$fx = fixture_testovaci_doklady($pdo);

echo "🧪 FlexiBee — builder\n";
$inv = ucetni_nacti_fakturu($pdo, $fx['faktura_id']);
$b = flexi_build_invoice($inv, ['typ_faktura' => 'FAKTURA', 'mj' => ['KS' => true, 'KG' => true]]);
ok($b['ok'], 'faktura sestavena');
$d = $b['data'];
ok($d['id'] === [FLEXI_EXT . 'fa-' . $fx['faktura_id']], 'upsert přes externí ID ext:APPEK:fa-<id>');
ok($d['firma'] === FLEXI_EXT . 'odb-' . $fx['odberatel_id'], 'vazba na odběratele přes ext ID');
ok($d['typDokl'] === 'code:FAKTURA' && $d['kod'] === 'FA-TEST-0001' && $d['varSym'] === $inv['vs'], 'typ, číslo, VS');
ok($d['datVyst'] === '2026-11-10' && $d['duzpPuv'] === '2026-11-09' && $d['datSplat'] === '2026-11-24', 'datum vystavení / DUZP / splatnost');
ok($d['formaUhradyCis'] === 'code:PREVOD' && $d['mena'] === 'code:CZK', 'forma úhrady + měna');
ok(count($d['polozkyFaktury']) === 4 && $d['polozkyFaktury@removeAll'] === 'true', '4 položky, re-export nahradí položky');
$sazby = array_column($d['polozkyFaktury'], 'typSzbDphK');
ok($sazby === ['typSzbDph.dphZakl', 'typSzbDph.dphSniz', 'typSzbDph.dphSniz', 'typSzbDph.dphOsv'], 'sazby 21/12/12/0 → dphZakl/dphSniz/dphOsv');
ok(($d['polozkyFaktury'][0]['mj'] ?? '') === 'code:KS' && ($d['polozkyFaktury'][2]['mj'] ?? '') === 'code:KG', 'měrné jednotky jen existující kódy');
$dob = ucetni_nacti_fakturu($pdo, $fx['dobropis_id']);
$b2 = flexi_build_invoice($dob, ['typ_dobropis' => 'DOBROPIS']);
ok($b2['ok'] && $b2['data']['typDokl'] === 'code:DOBROPIS', 'dobropis → typ dobropisu');
ok($b2['ok'] && $b2['data']['polozkyFaktury'][0]['mnozMj'] < 0 && $b2['data']['polozkyFaktury'][0]['cenaMj'] > 0, 'dobropis: záporné množství, kladná cena (požadavek Flexi)');
ok(!flexi_build_invoice($dob, [])['ok'], 'dobropis bez typu → česká chyba');
$p = flexi_build_product(['id' => 5, 'cislo' => '', 'nazev' => 'X', 'cena_bez_dph' => 10, 'sazba' => 12, 'ean' => '8590000000001']);
ok($p['id'] === [FLEXI_EXT . 'vyr-5'] && $p['kod'] === 'APPEK-5' && $p['typSzbDphK'] === 'typSzbDph.dphSniz' && $p['eanKod'] === '8590000000001', 'ceník: ext ID, kód, sazba, EAN');

echo "── parser odpovědí Flexi\n";
$r = flexi_parse('{"winstrom":{"@version":"1.0","success":"true","stats":{"created":"1","updated":"0"},"results":[{"id":"42","request-id":"ext:APPEK:fa-1","ref":"/c/demo/faktura-vydana/42.json"}]}}', 201);
ok($r['ok'] && $r['results'][0]['id'] === '42', 'úspěch: ID záznamu');
$r = flexi_parse('{"winstrom":{"@version":"1.0","success":"false","stats":{"failed":"1"},"results":[{"request-id":"ext:APPEK:fa-1","errors":[{"message":"U dobropisu musí být zadáno záporné množství.","for":"mnozMj","code":"INVALID"}]}]}}', 400);
ok(!$r['ok'] && str_contains($r['error'], 'U dobropisu musí být zadáno záporné množství. [mnozMj]'), 'chyba validace → text Flexi: ' . $r['error']);
$r = flexi_parse('{"winstrom":{"@version":"1.0","success":"false","message":"Firma nenalezena"}}', 404);
ok(!$r['ok'] && str_contains($r['error'], 'kód firmy'), '404 → česká chyba');
$r = flexi_parse('<html>Unauthorized</html>', 401);
ok(!$r['ok'] && str_contains($r['error'], 'heslo'), '401 (HTML tělo) → špatné heslo');
$r = flexi_parse('', 502);
ok(!$r['ok'] && $r['error'] !== '', 'prázdná odpověď → chyba');

echo "── SSRF politika + chybové cesty (česká chyba, žádný warning)\n";
$base = ['flexibee_enabled' => '1', 'flexibee_company' => 'demo', 'flexibee_username' => 'x', 'flexibee_password' => 'y',
         'flexibee_typ_faktura' => '', 'flexibee_typ_dobropis' => '', 'ucetni_povolit_lan' => '0'];
$cases = [
    'ftp schéma'                 => [['flexibee_url' => 'ftp://demo.flexibee.eu'], 'http:// nebo https://'],
    'javascript schéma'          => [['flexibee_url' => 'javascript:alert(1)'], 'Neplatná adresa'],
    'jméno v URL'                => [['flexibee_url' => 'https://a:b@demo.flexibee.eu'], 'samostatných polí'],
    'privátní IP bez opt-in'     => [['flexibee_url' => 'https://192.168.1.10'], 'místní síti'],
    'localhost bez opt-in'       => [['flexibee_url' => 'http://localhost:5434'], 'místní síti'],
    'cloud metadata i s opt-in'  => [['flexibee_url' => 'http://169.254.169.254', 'ucetni_povolit_lan' => '1'], 'zakázaný rozsah'],
    'http na veřejný server'     => [['flexibee_url' => 'http://demo.flexibee.eu'], 'https://'],
    'neexistující doména (DNS)'  => [['flexibee_url' => 'https://neexistuje-appek-test.invalid'], 'DNS'],
    'zavřený port (LAN opt-in)'  => [['flexibee_url' => 'http://127.0.0.1:9', 'ucetni_povolit_lan' => '1'], 'Nelze se připojit'],
];
$prev = set_error_handler(function ($no, $str) { echo "  ⚠️ PHP warning: $str\n"; $GLOBALS['__fail']++; return true; });
foreach ($cases as $nazev => [$over, $ocekavam]) {
    $r = flexi_test(array_merge($base, $over));
    ok(!$r['ok'] && str_contains((string) $r['error'], $ocekavam), "$nazev → " . ($r['error'] ?? ''));
}
// timeout: nesměrovatelná adresa (TEST-NET-1 by byla veřejná → použijeme 10.255.255.1 s LAN opt-in) a krátký limit
$t0 = microtime(true);
$r = ucetni_http('GET', 'http://10.255.255.1:5434/status.json', [], null, ['timeout' => 3, 'connect_timeout' => 2, 'povolit_lan' => true]);
ok(!$r['ok'] && (str_contains((string) $r['error'], 'neodpověděl včas') || str_contains((string) $r['error'], 'Nelze se připojit')) && microtime(true) - $t0 < 10, 'timeout → ' . $r['error'] . sprintf(' (%.1f s)', microtime(true) - $t0));
$r = pohoda_test(['pohoda_url' => 'gopher://x', 'pohoda_username' => 'a', 'pohoda_password' => 'b', 'pohoda_ico' => '12345678', 'ucetni_povolit_lan' => '0']);
ok(!$r['ok'] && str_contains($r['error'], 'http:// nebo https://'), 'POHODA: neplatné schéma → ' . $r['error']);
$r = pohoda_test(['pohoda_url' => 'http://127.0.0.1:9', 'pohoda_username' => 'a', 'pohoda_password' => 'b', 'pohoda_ico' => '12345678', 'ucetni_povolit_lan' => '1']);
ok(!$r['ok'] && str_contains($r['error'], 'Nelze se připojit'), 'POHODA: nedostupný mServer → ' . $r['error']);
$r = pohoda_send(['pohoda_enabled' => '1', 'pohoda_url' => 'http://192.168.50.50:444', 'pohoda_username' => 'a', 'pohoda_password' => 'b', 'pohoda_ico' => '12345678', 'ucetni_povolit_lan' => '0'], '<x/>');
ok(!$r['ok'] && str_contains($r['error'], 'místní síti'), 'POHODA: LAN adresa bez opt-in → ' . $r['error']);
set_error_handler($prev);

if (getenv('UCETNI_FLEXI_LIVE') !== '1') {
    echo "\n(živý round-trip přeskočen — spusť s UCETNI_FLEXI_LIVE=1)\n";
    konec();
}

// ═════════════════════════ ŽIVĚ proti demo serveru ═════════════════════════
echo "\n🌐 Živý round-trip — " . (getenv('UCETNI_FLEXI_URL') ?: 'https://demo.flexibee.eu') . "\n";
$cfg = [
    'flexibee_enabled' => '1',
    'flexibee_url' => getenv('UCETNI_FLEXI_URL') ?: 'https://demo.flexibee.eu',
    'flexibee_company' => getenv('UCETNI_FLEXI_FIRMA') ?: 'demo',
    'flexibee_username' => getenv('UCETNI_FLEXI_USER') ?: 'winstrom',
    'flexibee_password' => getenv('UCETNI_FLEXI_PASS') ?: 'winstrom',
    'flexibee_typ_faktura' => '', 'flexibee_typ_dobropis' => '', 'ucetni_povolit_lan' => '0',
];
$r = flexi_test(array_merge($cfg, ['flexibee_password' => 'spatne-heslo-appek']));
ok(!$r['ok'] && str_contains($r['error'], 'heslo'), 'špatné heslo → ' . $r['error']);
$r = flexi_test(array_merge($cfg, ['flexibee_company' => 'neexistujici_firma_appek']));
ok(!$r['ok'], 'neexistující firma → ' . ($r['error'] ?? ''));
$r = flexi_test($cfg);
ok($r['ok'], 'test připojení: ' . ($r['message'] ?? $r['error']));

$run = strtoupper(base_convert((string) time(), 10, 36));
$synt = 7000000 + (time() % 1000000);           // syntetická ID → unikátní ext ID pro tento běh
$uklid = ['faktura-vydana' => [], 'adresar' => [], 'cenik' => []];

$prep = function (array $inv, int $id, string $kod) use ($synt) {
    $inv['id'] = $id; $inv['cislo'] = $kod;
    $inv['odberatel']['id'] = $synt; $inv['odberatel']['nazev'] = 'APPEK TEST s.r.o.';
    $inv['poznamka'] = 'APPEK integration test — automaticky smazáno';
    $inv['hash'] = ucetni_hash($inv);
    return $inv;
};
$fa = $prep(ucetni_nacti_fakturu($pdo, $fx['faktura_id']), $synt + 1, "APT{$run}F");
$db = $prep(ucetni_nacti_fakturu($pdo, $fx['dobropis_id']), $synt + 2, "APT{$run}D");
$db['puvodni_cislo'] = $fa['cislo'];

$cti = function (string $numId) use ($cfg) {
    $q = flexi_request($cfg, 'GET', 'faktura-vydana/' . rawurlencode('(id=' . $numId . ')') . '.json?relations=polozkyFaktury&detail=custom:id,kod,typDokl,varSym,datVyst,duzpPuv,datSplat,firma,formaUhradyCis,mena,sumOsv,sumZklSniz,sumDphSniz,sumZklZakl,sumDphZakl,sumCelkem,polozkyFaktury(nazev,mnozMj,cenaMj,sumZkl,sumDph,szbDph)');
    return $q['ok'] ? ($q['data']['faktura-vydana'][0] ?? null) : null;
};
$porovnej = function (array $inv, ?array $f, string $popis) {
    ok($f !== null, "$popis: načteno zpět z Flexi");
    if (!$f) return;
    $sum = function ($sazba) use ($inv) { $k = ucetni_num($sazba, 2); return $inv['rekapitulace'][$k] ?? ['zaklad' => 0, 'dph' => 0]; };
    ok(abs((float) $f['sumCelkem'] - $inv['celkem']) < 0.001, "$popis: celkem Flexi {$f['sumCelkem']} = APPEK {$inv['celkem']}");
    ok(abs((float) $f['sumZklZakl'] - $sum(21)['zaklad']) < 0.001 && abs((float) $f['sumDphZakl'] - $sum(21)['dph']) < 0.001, "$popis: 21 % základ {$f['sumZklZakl']} / DPH {$f['sumDphZakl']}");
    ok(abs((float) $f['sumZklSniz'] - $sum(12)['zaklad']) < 0.001 && abs((float) $f['sumDphSniz'] - $sum(12)['dph']) < 0.001, "$popis: 12 % základ {$f['sumZklSniz']} / DPH {$f['sumDphSniz']}");
    ok(abs((float) $f['sumOsv'] - $sum(0)['zaklad']) < 0.001, "$popis: osvobozeno {$f['sumOsv']}");
    ok(count($f['polozkyFaktury'] ?? []) === count($inv['polozky']), "$popis: počet položek " . count($f['polozkyFaktury'] ?? []));
    $polOk = true;
    foreach ($inv['polozky'] as $i => $p) {
        $fp = $f['polozkyFaktury'][$i] ?? null;
        if (!$fp || abs(abs((float) $fp['mnozMj']) - abs($p['mnozstvi'])) > 0.0001 || abs(abs((float) $fp['cenaMj']) - abs($p['cena_mj'])) > 0.001
            || abs((float) $fp['sumZkl'] - $p['zaklad']) > 0.011) $polOk = false;
    }
    ok($polOk, "$popis: množství, ceny a základy položek sedí");
    ok(strtoupper($f['kod']) === strtoupper($inv['cislo']) && $f['varSym'] === $inv['vs'], "$popis: číslo {$f['kod']} + VS {$f['varSym']}");
    ok(substr($f['datVyst'], 0, 10) === $inv['datum_vystaveni'] && substr($f['datSplat'], 0, 10) === $inv['datum_splatnosti'] && substr($f['duzpPuv'], 0, 10) === $inv['datum_dph'], "$popis: data vystavení/splatnosti/DUZP");
};

// faktura
$r1 = flexi_export_invoice($cfg, $fa);
ok($r1['ok'], 'export vícesazbové faktury: ' . ($r1['ok'] ? 'ID ' . $r1['externi_id'] : $r1['error']));
$uklid['adresar'][] = FLEXI_EXT . 'odb-' . $synt;
if ($r1['ok']) {
    $uklid['faktura-vydana'][] = $r1['externi_id'];
    $porovnej($fa, $cti($r1['externi_id']), 'faktura');
    ucetni_state_set($pdo, 'flexibee', $fx['faktura_id'], true, 'rest', $r1['externi_id'], $fa['cislo'], $fa['hash']);
    $st = ucetni_state_get($pdo, 'flexibee', $fx['faktura_id']);
    ok($st && $st['externi_id'] === $r1['externi_id'] && $st['stav'] === 'ok', 'stav exportu uložen v accounting_export_state');
    ok(!ucetni_idempotence($st, $fa['hash'], false)['pokracovat'], 'opakovaný export bez změny by se přeskočil');

    // re-export (force) → aktualizace, ne duplicita
    $r1b = flexi_export_invoice($cfg, $fa);
    ok($r1b['ok'] && $r1b['externi_id'] === $r1['externi_id'], 'opakovaný export → stejné ID ' . ($r1b['externi_id'] ?? ''));
    ok((int) ($r1b['stats']['updated'] ?? 0) === 1 && (int) ($r1b['stats']['created'] ?? 0) === 0, 'opakovaný export = updated 1 / created 0');
    $q = flexi_request($cfg, 'GET', 'faktura-vydana/' . rawurlencode("(kod = '{$fa['cislo']}')") . '.json?detail=custom:id');
    ok($q['ok'] && count($q['data']['faktura-vydana'] ?? []) === 1, 've Flexi je s tímto číslem právě 1 doklad (žádná duplicita)');
}

// dobropis
$r2 = flexi_export_invoice($cfg, $db);
ok($r2['ok'], 'export dobropisu: ' . ($r2['ok'] ? 'ID ' . $r2['externi_id'] : $r2['error']));
if ($r2['ok']) {
    $uklid['faktura-vydana'][] = $r2['externi_id'];
    $f = $cti($r2['externi_id']);
    ok($f && str_contains((string) ($f['typDokl'] ?? ''), 'code:'), 'typ dokladu dobropisu ' . ($f['typDokl'] ?? '?'));
    $porovnej($db, $f, 'dobropis');
}

// adresář + ceník
$o = $pdo->query("SELECT id, cislo, nazev, ico, dic, ulice, mesto, psc, email, telefon, splatnost_dni FROM odberatele WHERE id = " . (int) $fx['odberatel_id'])->fetch(PDO::FETCH_ASSOC);
$o['id'] = $synt + 3; $o['nazev'] = 'APPEK TEST s.r.o. (adresář)';
$r3 = flexi_upsert($cfg, 'adresar', [flexi_build_partner($o)]);
ok($r3['ok'], 'export odběratele do adresáře' . ($r3['ok'] ? ' (ID ' . ($r3['results'][0]['id'] ?? '') . ')' : ': ' . $r3['error']));
$uklid['adresar'][] = FLEXI_EXT . 'odb-' . ($synt + 3);
$r3b = flexi_upsert($cfg, 'adresar', [flexi_build_partner($o)]);
ok($r3b['ok'] && ($r3b['results'][0]['id'] ?? '') === ($r3['results'][0]['id'] ?? 'x'), 'opakovaný export odběratele → stejný záznam');
$v = ['id' => $synt + 4, 'cislo' => "APT{$run}V", 'nazev' => 'APPEK TEST výrobek (integration test)', 'cena_bez_dph' => 42.5, 'sazba' => 12, 'ean' => ''];
$r4 = flexi_upsert($cfg, 'cenik', [flexi_build_product($v)]);
ok($r4['ok'], 'export výrobku do ceníku' . ($r4['ok'] ? ' (ID ' . ($r4['results'][0]['id'] ?? '') . ')' : ': ' . $r4['error']));
if ($r4['ok']) {
    $uklid['cenik'][] = $r4['results'][0]['id'];
    $q = flexi_request($cfg, 'GET', 'cenik/' . rawurlencode('(id=' . $r4['results'][0]['id'] . ')') . '.json?detail=custom:kod,nazev,cenaZakl,typSzbDphK');
    $c = $q['data']['cenik'][0] ?? [];
    ok(abs((float) ($c['cenaZakl'] ?? 0) - 42.5) < 0.001 && ($c['typSzbDphK'] ?? '') === 'typSzbDph.dphSniz', 'ceník načten zpět: cena 42.5, snížená sazba');
}

// přijaté faktury
$r5 = flexi_prijate($cfg, date('Y-01-01'), date('Y-12-31'));
ok($r5['ok'], 'přijaté faktury za letošek: ' . ($r5['ok'] ? count($r5['faktury']) . ' ks' : $r5['error']));
if ($r5['ok'] && $r5['faktury']) { $x = $r5['faktury'][0]; ok($x['cislo'] !== '' && $x['datum'] !== '', "první: {$x['cislo']} {$x['datum']} {$x['dodavatel']} {$x['celkem']} {$x['mena']}"); }

// úklid
echo "── úklid testovacích záznamů\n";
foreach (['faktura-vydana', 'cenik', 'adresar'] as $ev) {
    foreach ($uklid[$ev] as $id) {
        $path = $ev . '/' . (ctype_digit((string) $id) ? $id : rawurlencode((string) $id)) . '.json';
        $r = flexi_request($cfg, 'DELETE', $path, null, 30);
        ok($r['ok'], "smazáno $ev $id" . ($r['ok'] ? '' : ': ' . $r['error']));
    }
}
if (!empty($r1['ok'])) {
    $q = flexi_request($cfg, 'GET', 'faktura-vydana/' . rawurlencode("(kod = '{$fa['cislo']}')") . '.json?detail=custom:id');
    ok($q['ok'] && count($q['data']['faktura-vydana'] ?? []) === 0, 'testovací faktura po úklidu ve Flexi neexistuje');
}
$pdo->prepare("DELETE FROM accounting_export_state WHERE system = 'flexibee' AND faktura_id = :f")->execute(['f' => $fx['faktura_id']]);

konec();
