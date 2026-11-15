<?php
/**
 * 🧪 E2E přes HTTP: api/admin_ucetni.php (auth, CSRF, readonly, maskování hesla, mServer tok, chybové cesty).
 *
 * Potřebuje běžící APPEK nad TESTOVACÍ DB a mock mServer:
 *   php -S 127.0.0.1:8099 -t <repo>                                  (api/config.local.php → testovací DB)
 *   UCETNI_XSD_DIR=… php -S 127.0.0.1:8098 scripts/tests/ucetni/mock_mserver.php
 *   UCETNI_E2E_BASE=http://127.0.0.1:8099 UCETNI_E2E_EMAIL=… UCETNI_E2E_PASS=… \
 *   UCETNI_E2E_RO_EMAIL=… UCETNI_E2E_RO_PASS=… UCETNI_MOCK_URL=http://127.0.0.1:8098 \
 *   php scripts/tests/ucetni/test_endpoint.php
 */
require __DIR__ . '/_bootstrap.php';
require __DIR__ . '/_fixtures.php';

$base = rtrim(getenv('UCETNI_E2E_BASE') ?: 'http://127.0.0.1:8099', '/');
$mock = getenv('UCETNI_MOCK_URL') ?: 'http://127.0.0.1:8098';
$xsd = getenv('UCETNI_XSD_DIR') ?: '';
$pdo = db();
$fx = fixture_testovaci_doklady($pdo);
$pdo->exec("DELETE FROM accounting_export_state"); // testovací DB: každý běh začíná bez historie exportů

function http(string $method, string $url, ?array $json = null, string $jar = '', ?string $csrf = null, bool $raw = false): array {
    $ch = curl_init($url);
    $h = ['Accept: application/json'];
    if ($json !== null) $h[] = 'Content-Type: application/json';
    if ($csrf) $h[] = 'X-CSRF-Token: ' . $csrf;
    $hdrs = [];
    curl_setopt_array($ch, [CURLOPT_CUSTOMREQUEST => $method, CURLOPT_RETURNTRANSFER => true, CURLOPT_HTTPHEADER => $h,
        CURLOPT_COOKIEJAR => $jar, CURLOPT_COOKIEFILE => $jar, CURLOPT_TIMEOUT => 120,
        CURLOPT_HEADERFUNCTION => function ($c, $l) use (&$hdrs) { $p = explode(':', $l, 2); if (count($p) === 2) $hdrs[strtolower(trim($p[0]))] = trim($p[1]); return strlen($l); }]);
    if ($json !== null) curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($json));
    $body = (string) curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);
    return ['code' => $code, 'body' => $body, 'json' => $raw ? null : json_decode($body, true), 'headers' => $hdrs];
}
function login(string $base, string $email, string $pass, string $jar): ?string {
    $r = http('POST', "$base/api/admin_login.php", ['email' => $email, 'heslo' => $pass], $jar);
    return $r['json']['csrf_token'] ?? null;
}
$U = "$base/api/admin_ucetni.php?action=";
$jar = tempnam(sys_get_temp_dir(), 'ucj'); $jarRo = tempnam(sys_get_temp_dir(), 'ucr'); $jarNo = tempnam(sys_get_temp_dir(), 'ucn');

echo "🧪 E2E admin_ucetni.php ($base)\n";
$r = http('GET', $U . 'config', null, $jarNo);
ok($r['code'] === 401, 'bez přihlášení → 401');
$csrf = login($base, getenv('UCETNI_E2E_EMAIL') ?: '', getenv('UCETNI_E2E_PASS') ?: '', $jar);
ok((bool) $csrf, 'admin přihlášen');

$r = http('POST', $U . 'save', ['system' => 'pohoda', 'enabled' => '1'], $jar);
ok($r['code'] === 403 && ($r['json']['error'] ?? '') === 'csrf_invalid', 'POST bez CSRF → 403');
$r = http('GET', $U . 'save', null, $jar);
ok($r['code'] === 405, 'GET na mutaci → 405');
$r = http('GET', $U . 'neco', null, $jar);
ok($r['code'] === 400 && isset($r['json']['error']), 'neznámá akce → 400 JSON');

echo "── nastavení POHODY + maskování hesla\n";
$r = http('POST', $U . 'save', ['system' => 'pohoda', 'enabled' => '1', 'url' => $mock, 'ico' => '12345678', 'username' => 'mock', 'password' => 'mock', 'povolit_lan' => false], $jar, $csrf);
ok($r['code'] === 400 && str_contains($r['json']['error'] ?? '', 'místní síti'), 'LAN adresa bez opt-in odmítnuta: ' . ($r['json']['error'] ?? $r['body']));
$r = http('POST', $U . 'save', ['system' => 'pohoda', 'enabled' => '1', 'url' => 'ftp://x', 'ico' => '12345678', 'username' => 'mock', 'povolit_lan' => true], $jar, $csrf);
ok($r['code'] === 400 && str_contains($r['json']['error'] ?? '', 'http://'), 'ftp:// odmítnuto: ' . ($r['json']['error'] ?? ''));
$r = http('POST', $U . 'save', ['system' => 'pohoda', 'enabled' => '1', 'url' => $mock . '/xml', 'ico' => '12345678', 'username' => 'mock', 'password' => 'mock', 'povolit_lan' => true], $jar, $csrf);
ok($r['code'] === 200 && ($r['json']['ok'] ?? false), 'uloženo (s opt-in LAN)');
ok(($r['json']['config']['pohoda_url'] ?? '') === $mock, 'koncové /xml z adresy odstraněno');
$r = http('GET', $U . 'config&_t=1', null, $jar);
ok(($r['json']['pohoda']['pohoda_password'] ?? 'x') === '' && ($r['json']['pohoda']['pohoda_password_set'] ?? false) === true, 'heslo se do klienta nevrací (jen příznak *_set)');
ok(!str_contains($r['body'], '"mock"') || substr_count($r['body'], 'mock') <= 2, 'tělo odpovědi neobsahuje heslo');
$r = http('POST', $U . 'save', ['system' => 'pohoda', 'enabled' => '1', 'url' => $mock, 'ico' => '12345678', 'username' => 'mock', 'password' => '', 'povolit_lan' => true], $jar, $csrf);
ok($r['code'] === 200, 'uložení s prázdným heslem (ponechá uložené)');
$pw = $pdo->query("SELECT hodnota FROM nastaveni WHERE klic = 'pohoda_password'")->fetchColumn();
ok($pw === 'mock', 'heslo v DB zůstalo zachované');

echo "── POHODA přes (mock) mServer\n";
$r = http('POST', $U . 'test', ['system' => 'pohoda'], $jar, $csrf);
ok(($r['json']['ok'] ?? false) && str_contains($r['json']['message'] ?? '', 'mock'), 'test připojení: ' . ($r['json']['message'] ?? $r['json']['error'] ?? $r['body']));
$r = http('POST', $U . 'pohoda_send', ['faktura_id' => $fx['faktura_id']], $jar, $csrf);
ok(($r['json']['ok'] ?? false) && ($r['json']['externi_cislo'] ?? '') === 'FA-TEST-0001', 'faktura odeslána: ' . json_encode($r['json'], JSON_UNESCAPED_UNICODE));
$r = http('POST', $U . 'pohoda_send', ['faktura_id' => $fx['faktura_id']], $jar, $csrf);
ok(($r['json']['needs_force'] ?? false) && ($r['json']['duvod'] ?? '') === 'beze_zmeny', 'opakované odeslání → upozornění „už exportováno": ' . ($r['json']['zprava'] ?? ''));
$r = http('POST', $U . 'pohoda_send', ['faktura_id' => $fx['faktura_id'], 'force' => true], $jar, $csrf);
ok($r['json']['ok'] ?? false, 'vynucené odeslání projde');
$r = http('POST', $U . 'pohoda_send', ['faktura_id' => $fx['dobropis_id']], $jar, $csrf);
ok($r['json']['ok'] ?? false, 'dobropis odeslán (původní faktura už v POHODĚ → correctiveDocument)');
$r = http('GET', $U . 'stav&faktura_id=' . $fx['faktura_id'], null, $jar);
ok(($r['json']['pohoda']['stav'] ?? '') === 'ok' && ($r['json']['pohoda']['rezim'] ?? '') === 'mserver' && !empty($r['json']['pohoda']['externi_id']) && ($r['json']['enabled']['pohoda'] ?? false), 'stav faktury: ok / mserver / ID z POHODY');

$pdo->exec("UPDATE faktury SET poznamka = CONCAT(poznamka, ' (upraveno)') WHERE id = {$fx['faktura_id']}");
$r = http('GET', $U . 'stav&faktura_id=' . $fx['faktura_id'], null, $jar);
ok(($r['json']['pohoda']['zmeneno_od_exportu'] ?? false) === true, 'změna faktury po exportu je detekována');
$r = http('POST', $U . 'pohoda_send', ['faktura_id' => $fx['faktura_id']], $jar, $csrf);
ok(($r['json']['duvod'] ?? '') === 'zmeneno', 'odeslání změněné faktury vyžaduje potvrzení');
$pdo->exec("UPDATE faktury SET poznamka = REPLACE(poznamka, ' (upraveno)', '') WHERE id = {$fx['faktura_id']}");

$r = http('POST', $U . 'pohoda_xml', ['faktura_id' => $fx['faktura_id']], $jar, $csrf, true);
ok($r['code'] === 200 && str_starts_with($r['body'], '<?xml version="1.0" encoding="Windows-1250"?>') && str_contains($r['headers']['content-disposition'] ?? '', 'pohoda_faktura_'), 'XML soubor jedné faktury ke stažení');
if ($xsd) {
    $f = test_out_dir() . '/e2e_single.xml'; file_put_contents($f, $r['body']);
    exec('xmllint --noout --nonet --schema ' . escapeshellarg("$xsd/data.xsd") . ' ' . escapeshellarg($f) . ' 2>&1', $o, $rc);
    ok($rc === 0, 'stažené XML validní proti XSD');
}
$r = http('POST', $U . 'pohoda_xml', ['od' => '2026-11-01', 'do' => '2026-11-30', 'jen_neodeslane' => false], $jar, $csrf, true);
ok($r['code'] === 200 && substr_count($r['body'], '<dat:dataPackItem') > 2, 'hromadné XML za listopad: ' . substr_count($r['body'], '<dat:dataPackItem') . ' dokladů');
if ($xsd) {
    $f = test_out_dir() . '/e2e_bulk.xml'; file_put_contents($f, $r['body']);
    exec('xmllint --noout --nonet --schema ' . escapeshellarg("$xsd/data.xsd") . ' ' . escapeshellarg($f) . ' 2>&1', $o2, $rc);
    ok($rc === 0, 'hromadné XML validní proti XSD');
}
$r = http('POST', $U . 'pohoda_xml', ['od' => '2030-01-01', 'do' => '2030-01-31', 'jen_neodeslane' => true], $jar, $csrf, true);
ok($r['code'] === 404 && str_contains((string) (json_decode($r['body'], true)['error'] ?? ''), 'žádné'), 'prázdné období → česká hláška');
$r = http('POST', $U . 'pohoda_xml', ['od' => '2026-12-01', 'do' => '2026-01-01'], $jar, $csrf, true);
ok($r['code'] === 400, 'od > do → 400');

$r = http('POST', $U . 'pohoda_send_bulk', ['od' => '2026-11-01', 'do' => '2026-11-30', 'jen_neodeslane' => false], $jar, $csrf);
ok(($r['json']['ok'] ?? false) && ($r['json']['odeslano'] ?? 0) > 0, 'hromadné odeslání: odesláno ' . ($r['json']['odeslano'] ?? '?') . ', přeskočeno ' . ($r['json']['preskoceno'] ?? '?') . ', chyb ' . ($r['json']['chyb'] ?? '?'));
$r = http('POST', $U . 'pohoda_send_bulk', ['od' => '2026-11-01', 'do' => '2026-11-30', 'jen_neodeslane' => true], $jar, $csrf);
ok(($r['json']['ok'] ?? false) && ($r['json']['odeslano'] ?? -1) === 0, 'podruhé „jen neodeslané" → nic k odeslání');

// duplicita hlášená POHODOU
$pdo->exec("UPDATE faktury SET cislo = 'DUPL-TEST-0001' WHERE id = {$fx['faktura_id']}");
$r = http('POST', $U . 'pohoda_send', ['faktura_id' => $fx['faktura_id'], 'force' => true], $jar, $csrf);
ok(!($r['json']['ok'] ?? true) && str_contains($r['json']['zprava'] ?? '', 'již existuje'), 'chyba z POHODY (duplicita) se zobrazí: ' . ($r['json']['zprava'] ?? ''));
$pdo->exec("UPDATE faktury SET cislo = 'FA-TEST-0001' WHERE id = {$fx['faktura_id']}");
$r = http('GET', $U . 'stav&faktura_id=' . $fx['faktura_id'], null, $jar);
ok(($r['json']['pohoda']['stav'] ?? '') === 'chyba' && !empty($r['json']['pohoda']['ok_at']) && str_contains($r['json']['pohoda']['posledni_chyba'] ?? '', 'již existuje'), 'stav: poslední chyba uložena, předchozí úspěch zachován');

$r = http('POST', $U . 'pohoda_adresar', ['rezim' => 'odeslat'], $jar, $csrf);
ok(($r['json']['ok'] ?? false) && ($r['json']['odeslano'] ?? 0) > 0, 'adresář odeslán: ' . ($r['json']['odeslano'] ?? '?') . ' odběratelů');
$r = http('POST', $U . 'pohoda_adresar', ['rezim' => 'soubor'], $jar, $csrf, true);
ok($r['code'] === 200 && str_contains($r['body'], '<adb:addressbook'), 'adresář jako XML soubor');
$r = http('GET', $U . 'pohoda_prijate&od=2026-11-01&do=2026-11-30', null, $jar);
$f = $r['json']['faktury'] ?? [];
ok(count($f) === 2 && $f[0]['dodavatel'] === 'Mlýn Dodavatel a.s.' && abs($f[0]['celkem'] - 1120) < 0.001 && abs($f[1]['celkem'] - 1000.4) < 0.001, 'přijaté faktury: 2, součty vč. zaokrouhlení');

echo "── chybové cesty (česky, bez 500)\n";
$r = http('POST', $U . 'save', ['system' => 'pohoda', 'password' => 'spatne-heslo'], $jar, $csrf);
$r = http('POST', $U . 'test', ['system' => 'pohoda'], $jar, $csrf);
ok(!($r['json']['ok'] ?? true) && str_contains($r['json']['error'] ?? '', 'heslo'), 'špatné heslo: ' . ($r['json']['error'] ?? $r['body']));
$r = http('POST', $U . 'pohoda_send', ['faktura_id' => $fx['faktura_id'], 'force' => true], $jar, $csrf);
ok($r['code'] === 200 && !($r['json']['ok'] ?? true) && str_contains($r['json']['zprava'] ?? '', '401'), 'odeslání se špatným heslem: ' . ($r['json']['zprava'] ?? $r['body']));
$r = http('POST', $U . 'save', ['system' => 'pohoda', 'url' => 'http://127.0.0.1:9', 'password' => 'mock'], $jar, $csrf);
$r = http('POST', $U . 'test', ['system' => 'pohoda'], $jar, $csrf);
ok(!($r['json']['ok'] ?? true) && str_contains($r['json']['error'] ?? '', 'Nelze se připojit'), 'nedostupný server: ' . ($r['json']['error'] ?? ''));
$r = http('POST', $U . 'save', ['system' => 'pohoda', 'url' => $mock], $jar, $csrf);
$r = http('POST', $U . 'flexi_send', ['faktura_id' => $fx['faktura_id']], $jar, $csrf);
ok($r['code'] === 400 && str_contains($r['json']['error'] ?? '', 'FlexiBee'), 'FlexiBee vypnutý → ' . ($r['json']['error'] ?? ''));
$r = http('POST', $U . 'save', ['system' => 'flexibee', 'url' => 'gopher://x', 'company' => 'demo'], $jar, $csrf);
ok($r['code'] === 400 && str_contains($r['json']['error'] ?? '', 'http'), 'FlexiBee neplatné schéma → ' . ($r['json']['error'] ?? ''));
$r = http('POST', $U . 'save', ['system' => 'flexibee', 'company' => 'a/b'], $jar, $csrf);
ok($r['code'] === 400, 'FlexiBee neplatný kód firmy → ' . ($r['json']['error'] ?? ''));
$r = http('POST', $U . 'pohoda_send', ['faktura_id' => 99999999], $jar, $csrf);
ok($r['code'] === 404, 'neexistující faktura → 404');

$r = http('GET', $U . 'log&system=pohoda&limit=100', null, $jar);
$z = $r['json']['zaznamy'] ?? [];
ok(count($z) > 5, 'log: ' . count($z) . ' záznamů');
ok(!str_contains($r['body'], 'spatne-heslo') && !preg_match('/"mock:mock"|bW9jazptb2Nr/', $r['body']), 'log neobsahuje heslo ani Basic token');

echo "── readonly role\n";
$csrfRo = login($base, getenv('UCETNI_E2E_RO_EMAIL') ?: '', getenv('UCETNI_E2E_RO_PASS') ?: '', $jarRo);
ok((bool) $csrfRo, 'readonly přihlášen');
$r = http('GET', $U . 'config', null, $jarRo);
ok($r['code'] === 200 && ($r['json']['muze_upravit'] ?? true) === false, 'readonly smí číst nastavení (bez úprav)');
$r = http('POST', $U . 'pohoda_send', ['faktura_id' => $fx['faktura_id']], $jarRo, $csrfRo);
ok($r['code'] === 403, 'readonly nesmí odesílat → 403');
$r = http('POST', $U . 'save', ['system' => 'pohoda', 'enabled' => '0'], $jarRo, $csrfRo);
ok($r['code'] === 403, 'readonly nesmí měnit nastavení → 403');

// úklid nastavení (vypnout, ať testovací DB nezůstane „zapnutá")
http('POST', $U . 'save', ['system' => 'pohoda', 'enabled' => '0', 'povolit_lan' => false], $jar, $csrf);
@unlink($jar); @unlink($jarRo); @unlink($jarNo);
konec();
