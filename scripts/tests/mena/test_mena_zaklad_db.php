<?php
/**
 * 💱 Balík 0 (v3.0.643) — integrační test na MySQL + HTTP: migrace, backfill castka_mena,
 * ledger, nastavení plateb ve více měnách (admin_mena.php), vzájemné vyloučení s trvalým
 * přepočtem (409), odberatele.mena_platby.
 *
 *   php scripts/tests/mena/test_mena_zaklad_db.php [http://127.0.0.1:8901]
 *
 * Běží JEN proti DB s „test" v názvu (config.local.php) a běžícímu lokálnímu serveru nad ní.
 * Změny dat dělá v transakci s rollbackem; nastavení měny na konci vrátí do původního stavu.
 * Kurz ČNB podstrčí (žádná síť) — přes nastavení vlastního kurzu.
 */
require_once __DIR__ . '/../../../api/config.php';
require_once __DIR__ . '/../../../api/_mena_lib.php';

if (stripos(DB_NAME, 'test') === false) { fwrite(STDERR, "❌ DB_NAME '" . DB_NAME . "' není testovací — odmítám běžet.\n"); exit(2); }
$base = rtrim($argv[1] ?? 'http://127.0.0.1:8901', '/');
if (!preg_match('#^http://(127\.0\.0\.1|localhost)(:\d+)?$#', $base)) { fwrite(STDERR, "❌ jen lokální server\n"); exit(2); }

$ok = 0; $fail = 0;
function t(string $n, bool $c, string $info = ''): void { global $ok, $fail; if ($c) $ok++; else { $fail++; echo "❌ $n $info\n"; } }

$pdo = db();
mena_ensure_schema($pdo, true);
mena_ensure_schema($pdo, true);   // idempotence

// ── 1) schéma ──
$cols = fn($t) => $pdo->query("SHOW COLUMNS FROM `$t`")->fetchAll(PDO::FETCH_COLUMN);
t('objednavky sloupce', !array_diff(['mena_kurz_prodej', 'castka_mena', 'pay_mena', 'pay_castka', 'pay_prijato'], $cols('objednavky')));
t('faktury + DL kurz prodeje/castka_mena', !array_diff(['mena_kurz_prodej', 'castka_mena'], $cols('faktury')) && !array_diff(['mena_kurz_prodej', 'castka_mena'], $cols('dodaci_listy')));
t('účty stolu měna + pay_*', !array_diff(['mena', 'mena_kurz', 'mena_kurz_prodej', 'pay_mena', 'pay_castka', 'pay_suma_kc'], $cols('restaurant_pos_ucty')));
t('položky účtu sazba_dph', in_array('sazba_dph', $cols('restaurant_pos_polozky'), true));
t('platby účtu mena/castka_mena/kurz', !array_diff(['mena', 'castka_mena', 'kurz'], $cols('restaurant_pos_platby')));
t('odberatele.mena_platby', in_array('mena_platby', $cols('odberatele'), true));
t('ledger platby_doklad + idem', !array_diff(['zdroj', 'metoda', 'mena', 'castka_mena', 'kurz', 'castka_czk', 'prijato_mena', 'vraceno_mena', 'idem'], $cols('platby_doklad')));
t('mena_kurzy', !array_diff(['mena', 'datum', 'kurz', 'zdroj', 'nacteno_at'], $cols('mena_kurzy')));
$ps = $pdo->query("SHOW COLUMNS FROM restaurant_pos_ucty LIKE 'pay_status'")->fetch();
t("pay_status účtu umí 'failed'", stripos($ps['Type'], "'failed'") !== false && stripos($ps['Type'], "'pending'") !== false);
t('marker migrace', str_starts_with((string) nastaveni_get($pdo, 'mena_schema_ver', ''), MENA_SCHEMA_VER . '|'));

// ── 2) backfill castka_mena ──
foreach (['objednavky', 'faktury'] as $tab) {
    t("$tab: Kč castka_mena = castka_celkem", (int) $pdo->query("SELECT COUNT(*) FROM `$tab` WHERE COALESCE(mena,'CZK')='CZK' AND castka_mena IS NOT NULL AND ABS(castka_mena - castka_celkem) > 0.001")->fetchColumn() === 0);
    $ids = $pdo->query("SELECT id, castka_mena FROM `$tab` WHERE mena <> 'CZK'")->fetchAll(PDO::FETCH_KEY_PAIR);
    $sedi = true;
    foreach ($ids as $id => $cm) { $dc = mena_doc_castka($pdo, $tab, (int) $id); if (abs($dc['celkem'] - (float) $cm) > 0.001) { $sedi = false; echo "  $tab#$id castka_mena $cm ≠ {$dc['celkem']}\n"; } }
    t("$tab: cizoměnové castka_mena = mena_doc_castka (" . count($ids) . ')', $sedi);
}
$dl = (int) $pdo->query("SELECT COUNT(*) FROM dodaci_listy dl LEFT JOIN objednavky o ON o.id=dl.objednavka_id
                         WHERE COALESCE(IF(dl.objednavka_id IS NULL, dl.mena, o.mena),'CZK')='CZK' AND ABS(COALESCE(dl.castka_mena,-1) - dl.castka_celkem) > 0.001")->fetchColumn();
t('DL Kč castka_mena = castka_celkem', $dl === 0, "($dl)");

// ── 3) doc_pole: Kč doklady beze změny výstupu, nové klíče doc_kurz ──
$rows = $pdo->query("SELECT id FROM objednavky ORDER BY id DESC LIMIT 50")->fetchAll();
mena_doc_pole($pdo, 'objednavky', $rows);
$okPole = true;
foreach ($rows as $r) if (!isset($r['doc_kurz'], $r['doc_kurz_prodej'], $r['doc_celkem'])) $okPole = false;
t('mena_doc_pole vrací doc_kurz/doc_kurz_prodej', $okPole);

// ── 4) transakce: snapshot + ledger + zámky na skutečném schématu (rollback) ──
$pdo->beginTransaction();
try {
    $o = $pdo->query("SELECT id FROM objednavky WHERE COALESCE(pay_status,'none') <> 'paid' AND stav <> 'zaplaceno'
                      AND id NOT IN (SELECT objednavka_id FROM dodaci_listy WHERE objednavka_id IS NOT NULL)
                      ORDER BY id DESC LIMIT 1")->fetchColumn();
    $eur = ['mena' => 'EUR', 'mena_kurz' => 25.17, 'mena_kurz_prodej' => round(25.17 / 1.02, 6), 'mena_zaklad' => 'CZK'];
    t('přepis nezaplacené objednávky (MySQL)', $o && mena_nastav_doklad($pdo, 'objednavky', (int) $o, $eur, true));
    $r = $pdo->query("SELECT mena, mena_kurz, mena_kurz_prodej FROM objednavky WHERE id = " . (int) $o)->fetch();
    t('snapshot uložen s kurzem prodeje', $r['mena'] === 'EUR' && (float) $r['mena_kurz'] == 25.17 && abs((float) $r['mena_kurz_prodej'] - round(25.17 / 1.02, 6)) < 1e-6);
    $cel = mena_ulozit_castku($pdo, 'objednavky', (int) $o);
    t('castka_mena po přirážce > Kč/kurz ČNB', $cel >= round((float) $pdo->query("SELECT castka_celkem FROM objednavky WHERE id=" . (int) $o)->fetchColumn() / 25.17, 2) - 0.05);
    $id = mena_platba_zapis($pdo, ['objednavka_id' => $o, 'zdroj' => 'quick', 'metoda' => 'hotovost', 'mena' => 'EUR', 'castka_mena' => $cel, 'kurz' => 25.17, 'prijato_mena' => 50, 'vraceno_mena' => round(50 - $cel, 2), 'idem' => 'test-' . $o]);
    t('ledger insert (MySQL)', $id > 0);
    t('ledger idem (MySQL)', mena_platba_zapis($pdo, ['objednavka_id' => $o, 'zdroj' => 'quick', 'metoda' => 'hotovost', 'mena' => 'EUR', 'castka_mena' => $cel, 'kurz' => 25.17, 'idem' => 'test-' . $o]) === $id);
    $zam = false;
    try { mena_nastav_doklad($pdo, 'objednavky', (int) $o, ['mena' => 'CZK', 'mena_kurz' => 1], true); } catch (MenaException $e) { $zam = $e->kod === 'doklad_zamceny'; }
    t('po přijaté platbě měnu nelze přepsat', $zam);
    $u = $pdo->query("SELECT id FROM restaurant_pos_ucty WHERE stav <> 'paid' LIMIT 1")->fetchColumn();
    if ($u) {
        t('snapshot účtu stolu', mena_nastav_doklad($pdo, 'restaurant_pos_ucty', (int) $u, $eur, true));
        $dc = mena_doc_castka($pdo, 'restaurant_pos_ucty', (int) $u);
        t('doc_castka účtu v EUR', $dc['mena'] === 'EUR');
    }
} finally { $pdo->rollBack(); }
t('rollback vrátil ledger', (int) $pdo->query("SELECT COUNT(*) FROM platby_doklad WHERE idem LIKE 'test-%'")->fetchColumn() === 0);

// ── 5) HTTP: nastavení, 409 vyloučení, odberatele.mena_platby ──
$cookie = tempnam(sys_get_temp_dir(), 'mena'); $csrf = null;
$req = function (string $m, string $path, $body = null) use ($base, $cookie, &$csrf) {
    $ch = curl_init($base . '/api/' . $path);
    $h = ['Content-Type: application/json'];
    if ($csrf && $m !== 'GET') $h[] = 'X-CSRF-Token: ' . $csrf;
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_CUSTOMREQUEST => $m, CURLOPT_HTTPHEADER => $h,
                            CURLOPT_COOKIEJAR => $cookie, CURLOPT_COOKIEFILE => $cookie, CURLOPT_TIMEOUT => 30]);
    if ($body !== null) curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));
    $raw = curl_exec($ch); $code = curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
    return ['code' => $code, 'json' => json_decode((string) $raw, true), 'raw' => $raw];
};
$l = $req('POST', 'admin_login.php', ['email' => 'admin@admin.cz', 'heslo' => 'demo1234']);
$csrf = $l['json']['csrf_token'] ?? null;
t('login', $l['code'] === 200 && $csrf);
$puvodni = nastaveni_get($pdo, 'mena_config_json', '');
try {
    $g = $req('GET', 'admin_mena.php');
    t('GET config má platba + kurzy', $g['code'] === 200 && isset($g['json']['config']['platba']['aktivni'], $g['json']['kurzy']) && isset($g['json']['platba_info']['meny']));

    $r = $req('POST', 'admin_mena.php?action=save_platba', ['platba' => ['aktivni' => true, 'kurz_zdroj' => 'rucni', 'meny' => ['EUR']]]);
    t('vlastní kurz bez hodnoty → 400', $r['code'] === 400, $r['raw']);
    $r = $req('POST', 'admin_mena.php?action=save_platba', ['platba' => ['aktivni' => true, 'iban_eur' => 'blbost']]);
    t('neplatný IBAN → 400', $r['code'] === 400);
    $p = ['aktivni' => true, 'meny' => ['EUR'], 'vychozi' => 'CZK', 'kurz_zdroj' => 'rucni', 'kurzy_rucni' => ['EUR' => 25.17],
          'prirazka_pct' => ['EUR' => 2], 'max_stari_kurzu_dni' => 4, 'terminal_meny' => ['CZK'], 'kanaly' => ['pos', 'b2b'],
          'vraceni' => 'volba', 'iban_eur' => 'CZ65 0800 0000 1920 0014 5399'];
    $r = $req('POST', 'admin_mena.php?action=save_platba', ['platba' => $p]);
    $c = $r['json']['config']['platba'] ?? [];
    t('save_platba uloží všechny volby', $r['code'] === 200 && $c['aktivni'] && $c['kurzy_rucni']['EUR'] == 25.17 && $c['prirazka_pct']['EUR'] == 2
        && $c['max_stari_kurzu_dni'] === 4 && $c['terminal_meny'] === ['CZK'] && $c['kanaly'] === ['pos', 'b2b'] && $c['vraceni'] === 'volba'
        && $c['iban_eur'] === 'CZ6508000000192000145399', $r['raw']);
    t('platba_info: EUR kurz prodeje', abs(($r['json']['platba_info']['meny']['EUR']['kurz_prodej'] ?? 0) - round(25.17 / 1.02, 6)) < 1e-6);
    $v = mena_volba_platby($pdo, 'EUR', 'pos');
    t('volba po uložení', $v['mena'] === 'EUR' && $v['mena_kurz'] == 25.17);
    t('kanál mimo seznam = jen Kč', mena_volba_platby($pdo, null, 'stul')['mena'] === 'CZK');

    $r = $req('POST', 'admin_mena.php?action=prepocet', ['kurz' => 25.17, 'kod' => 'EUR']);
    t('trvalý přepočet při zapnutých platbách → 409', $r['code'] === 409 && str_contains((string) ($r['json']['error'] ?? $r['raw']), 'platby'), $r['raw']);
    t('ceník nezměněn', mena_config_load($pdo)['zakladni'] === 'CZK');

    // legacy uložení měny NESMÍ smazat blok platba
    $r = $req('POST', 'admin_mena.php?action=save', ['config' => ['kod' => 'EUR', 'kurz' => 25.2, 'zobrazeni' => 'mena']]);
    $cf = mena_config_load($pdo);
    t('legacy save zachová platba{} a vynutí zobrazení Kč', $r['code'] === 200 && $cf['platba']['aktivni'] && $cf['platba']['kurzy_rucni']['EUR'] == 25.17 && $cf['zobrazeni'] === 'kc' && $cf['delitel'] == 1.0);

    // vypnout → přepočet už není blokovaný platbami (jen náhled, nic nepřepočítáme)
    $r = $req('POST', 'admin_mena.php?action=save_platba', ['platba' => array_merge($p, ['aktivni' => false])]);
    t('vypnutí uloží', $r['code'] === 200 && !$r['json']['config']['platba']['aktivni'] && $r['json']['config']['platba']['kurzy_rucni']['EUR'] == 25.17);
    t('vypnuto: volba EUR odmítnuta', (function () use ($pdo) { try { mena_volba_platby($pdo, 'EUR', 'pos'); return false; } catch (MenaException $e) { return true; } })());

    // trvale přepočtený ceník (simulace v configu) → aktivace 409
    $cfg = json_decode(nastaveni_get($pdo, 'mena_config_json', '{}'), true);
    $cfg['zakladni'] = 'EUR'; $cfg['kod'] = 'EUR';
    $pdo->prepare("UPDATE nastaveni SET hodnota = ? WHERE klic = 'mena_config_json'")->execute([json_encode($cfg)]);
    $r = $req('POST', 'admin_mena.php?action=save_platba', ['platba' => $p]);
    t('ceník přepočtený → zapnutí plateb 409', $r['code'] === 409, $r['raw']);

    // odběratel: mena_platby
    $odb = (int) $pdo->query("SELECT id FROM odberatele ORDER BY id LIMIT 1")->fetchColumn();
    $orig = $pdo->query("SELECT * FROM odberatele WHERE id = $odb")->fetch();
    $put = fn($extra) => $req('PUT', 'admin_odberatele.php', array_merge(['id' => $odb, 'nazev' => $orig['nazev'], 'cislo' => $orig['cislo'],
        'splatnost_dni' => $orig['splatnost_dni'], 'sleva_pct' => $orig['sleva_pct'], 'blokovan' => $orig['blokovan'], 'notif_emaily' => $orig['notif_emaily'],
        'ico' => $orig['ico'], 'dic' => $orig['dic'], 'ulice' => $orig['ulice'], 'mesto' => $orig['mesto'], 'psc' => $orig['psc'], 'email' => $orig['email'],
        'telefon' => $orig['telefon'], 'kontaktni_osoba' => $orig['kontaktni_osoba'], 'login_email' => $orig['login_email'], 'poznamka' => $orig['poznamka'],
        'cenova_skupina_id' => $orig['cenova_skupina_id'], 'typ' => $orig['typ']], $extra));
    $r = $put(['mena_platby' => 'eur']);
    $g = $req('GET', "admin_odberatele.php?id=$odb");
    t('odběratel: uloží mena_platby EUR', $r['code'] === 200 && ($g['json']['mena_platby'] ?? null) === 'EUR', $r['raw']);
    $r = $put([]);
    t('odběratel: PUT bez pole měnu nesmaže', ($req('GET', "admin_odberatele.php?id=$odb")['json']['mena_platby'] ?? null) === 'EUR');
    $r = $put(['mena_platby' => 'XYZ']);
    $g = $req('GET', "admin_odberatele.php?id=$odb")['json'];
    t('odběratel: neplatná měna = výchozí firmy (NULL)', array_key_exists('mena_platby', $g) && $g['mena_platby'] === null);
    $put(['mena_platby' => $orig['mena_platby']]);
} finally {
    $pdo->prepare("UPDATE nastaveni SET hodnota = ? WHERE klic = 'mena_config_json'")->execute([$puvodni]);
    @unlink($cookie);
}
t('nastavení měny vráceno', nastaveni_get($pdo, 'mena_config_json', '') === $puvodni);

echo "mena_zaklad_db: $ok OK, $fail chyb\n";
exit($fail ? 1 : 0);
