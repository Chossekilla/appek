<?php
/**
 * 🛍️ v3.0.626 — ONLINE OBJEDNÁVKY JÍDLA pro koncové zákazníky (vyzvednutí / rozvoz) — bez provize platforem.
 *
 * Veřejné (bez přihlášení, stránka /objednat/):
 *   GET  ?action=info                → název, logo, pravidla (vyzvednutí/rozvoz, cena dopravy, min. objednávka), otevřeno?, nejbližší čas
 *   GET  ?action=menu                → kategorie + výrobky (stejný výběr jako POS: aktivní, zobrazit_na_pos, sezóny, cena s DPH)
 *   POST ?action=order               → vytvoří objednávku (puvod='web', řada WEB, pos_typ vyzvednuti|rozvoz,
 *                                       pos_payment='pri_vyzvednuti' → mimo tržbu, dokud se nezaplatí na pokladně / kurýrovi)
 *   GET  ?action=stav&t=TOKEN        → stav pro zákazníka (přijato → připravuje se → připraveno / na cestě → vydáno / doručeno)
 * Admin:
 *   GET|POST ?action=settings        → nastavení (nastaveni.web_objednavky, JSON)
 *
 * Ceny počítá VŽDY server. Ochrany: zapnuto, otevírací doba, PSČ, min. objednávka, limit na IP, honeypot.
 * Odpis surovin: přes plán výroby (datum_dodani), jako B2B — NE při vytvoření (jinak dvojí odpis).
 */
require_once __DIR__ . '/config.php';

cors_headers();
header('Content-Type: application/json; charset=UTF-8');

$pdo    = db();
$action = $_GET['action'] ?? '';
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

function wo_defaults(): array {
    return [
        'zapnuto' => false, 'vyzvednuti' => true, 'rozvoz' => false,
        'rozvoz_cena' => 0, 'rozvoz_zdarma_od' => 0, 'rozvoz_psc' => '',
        'min_objednavka' => 0, 'priprava_min' => 30,
        'od' => '08:00', 'do' => '20:00', 'dny' => [1, 2, 3, 4, 5, 6, 7],
        'predobjednavka_dni' => 2, 'zprava' => '',
    ];
}
function wo_settings(PDO $pdo): array {
    $cfg = wo_defaults();
    try {
        $raw = nastaveni_get($pdo, 'web_objednavky', null);
        $j = $raw ? json_decode((string) $raw, true) : null;
        if (is_array($j)) foreach ($cfg as $k => $v) if (array_key_exists($k, $j)) $cfg[$k] = $j[$k];
    } catch (Throwable $e) {}
    $cfg['zapnuto'] = (bool) $cfg['zapnuto']; $cfg['vyzvednuti'] = (bool) $cfg['vyzvednuti']; $cfg['rozvoz'] = (bool) $cfg['rozvoz'];
    foreach (['rozvoz_cena', 'rozvoz_zdarma_od', 'min_objednavka'] as $k) $cfg[$k] = max(0, (float) $cfg[$k]);
    $cfg['priprava_min'] = max(0, min(600, (int) $cfg['priprava_min']));
    $cfg['predobjednavka_dni'] = max(0, min(14, (int) $cfg['predobjednavka_dni']));
    $cfg['dny'] = array_values(array_unique(array_filter(array_map('intval', (array) $cfg['dny']), fn($d) => $d >= 1 && $d <= 7)));
    foreach (['od', 'do'] as $k) if (!preg_match('/^\d{2}:\d{2}$/', (string) $cfg[$k])) $cfg[$k] = wo_defaults()[$k];
    return $cfg;
}
/** Dostupné časy pro dnes + předobjednávku: [{den:'YYYY-MM-DD', od:'HH:MM', do:'HH:MM'}] (den jen pokud je otevřeno). */
function wo_okna(array $cfg): array {
    $out = [];
    $now = time();
    for ($i = 0; $i <= $cfg['predobjednavka_dni']; $i++) {
        $ts = strtotime("+$i day", strtotime(date('Y-m-d', $now)));
        if (!in_array((int) date('N', $ts), $cfg['dny'], true)) continue;
        $den = date('Y-m-d', $ts);
        $odTs = strtotime("$den {$cfg['od']}");
        $doTs = strtotime("$den {$cfg['do']}");
        if ($i === 0) $odTs = max($odTs, $now + $cfg['priprava_min'] * 60);
        $odTs = (int) (ceil($odTs / 300) * 300);           // na 5 minut
        if ($odTs > $doTs) continue;
        $out[] = ['den' => $den, 'od' => date('H:i', $odTs), 'do' => date('H:i', $doTs)];
    }
    return $out;
}
function wo_ensure(PDO $pdo): void {
    static $done = false; if ($done) return; $done = true;
    try {
        $have = $pdo->query("SHOW COLUMNS FROM objednavky")->fetchAll(PDO::FETCH_COLUMN);
        $add = [
            'vyzvednuti_cas' => 'DATETIME NULL', 'vyzvednuti_jmeno' => 'VARCHAR(80) NULL', 'vyzvednuti_tel' => 'VARCHAR(30) NULL',
            'vyzvednuti_email' => 'VARCHAR(120) NULL', 'vyzvednuti_pripraveno' => 'DATETIME NULL', 'vyzvednuto_kdy' => 'DATETIME NULL',
            'objednano_puvodne' => 'DATETIME NULL', 'web_token' => 'VARCHAR(32) NULL', 'web_adresa' => 'VARCHAR(255) NULL',
        ];
        foreach ($add as $col => $def) if (!in_array($col, $have, true)) $pdo->exec("ALTER TABLE objednavky ADD COLUMN `$col` $def");
        if (!in_array('web_token', $have, true)) { try { $pdo->exec("ALTER TABLE objednavky ADD INDEX idx_web_token (web_token)"); } catch (Throwable $e) {} }
    } catch (Throwable $e) { /* soft */ }
}
/** Výrobky pro online nabídku — stejný výběr jako POS (aktivní, zobrazit_na_pos, sezóny), cena s DPH. */
function wo_vyrobky(PDO $pdo): array {
    $cols = $pdo->query("SHOW COLUMNS FROM vyrobky")->fetchAll(PDO::FETCH_COLUMN);
    $c = fn($n, $d = 'NULL') => in_array($n, $cols, true) ? "v.$n" : "$d AS $n";
    $posFilter = in_array('zobrazit_na_pos', $cols, true) ? "AND COALESCE(v.zobrazit_na_pos, 1) = 1" : "";
    $rows = $pdo->query("
        SELECT v.id, v.nazev, v.kategorie_id, v.cena_bez_dph, COALESCE(s.sazba, 21) AS sazba_dph,
               {$c('obrazek_url')}, {$c('alergeny')}, {$c('sezona')}, {$c('popis')}, {$c('hmotnost_g')}
        FROM vyrobky v LEFT JOIN sazby_dph s ON s.id = v.sazba_dph_id
        WHERE COALESCE(v.aktivni, 1) = 1 AND v.cena_bez_dph > 0 {$posFilter}
        ORDER BY v.nazev ASC LIMIT 800
    ")->fetchAll(PDO::FETCH_ASSOC);
    try {
        require_once __DIR__ . '/_seasonal_lib.php';
        $act = seasonal_active_keys($pdo);
        $rows = array_values(array_filter($rows, fn($v) => seasonal_visible($v['sezona'] ?? null, $act)));
        foreach ($rows as &$v) if (!empty($v['sezona'])) $v['cena_bez_dph'] = seasonal_adjust_price($pdo, (float) $v['cena_bez_dph'], $v['sezona'])['cena'];
        unset($v);
    } catch (Throwable $e) {}
    foreach ($rows as &$v) {
        $v['id'] = (int) $v['id'];
        $v['cena_bez_dph'] = (float) $v['cena_bez_dph'];
        $v['sazba_dph'] = (float) $v['sazba_dph'];
        $v['cena'] = round($v['cena_bez_dph'] * (1 + $v['sazba_dph'] / 100), 2);
    }
    unset($v);
    return $rows;
}
function wo_psc_ok(string $psc, string $list): bool {
    $list = trim($list);
    if ($list === '') return true;
    $p = preg_replace('/\D/', '', $psc);
    if (strlen($p) !== 5) return false;
    foreach (preg_split('/[\s,;]+/', $list) as $pref) {
        $pref = preg_replace('/\D/', '', $pref);
        if ($pref !== '' && str_starts_with($p, $pref)) return true;
    }
    return false;
}
function wo_restaurace(PDO $pdo): bool {
    try { require_once __DIR__ . '/_packages_lib.php'; return package_enabled('restaurace'); } catch (Throwable $e) { return false; }
}

// ─── ADMIN: nastavení ────────────────────────────────────────────────
if ($action === 'settings') {
    require_once __DIR__ . '/_admin_auth.php';
    require_admin();
    if ($method === 'POST') {
        $d = json_decode(file_get_contents('php://input'), true) ?? [];
        $cur = wo_settings($pdo);
        foreach (wo_defaults() as $k => $v) if (array_key_exists($k, $d)) $cur[$k] = $d[$k];
        $cur['zprava'] = mb_substr(trim((string) $cur['zprava']), 0, 300);
        $cur['rozvoz_psc'] = mb_substr(trim((string) $cur['rozvoz_psc']), 0, 500);
        $json = json_encode($cur, JSON_UNESCAPED_UNICODE);
        $pdo->prepare("INSERT INTO nastaveni (klic, hodnota) VALUES ('web_objednavky', :h) ON DUPLICATE KEY UPDATE hodnota = :h2")->execute(['h' => $json, 'h2' => $json]);
        json_response(['ok' => true, 'settings' => $cur, 'restaurace' => wo_restaurace($pdo)]);   // nastaveni_get má statickou cache
    }
    json_response(['ok' => true, 'settings' => wo_settings($pdo), 'restaurace' => wo_restaurace($pdo)]);
}

$cfg = wo_settings($pdo);
$aktivni = $cfg['zapnuto'] && wo_restaurace($pdo) && ($cfg['vyzvednuti'] || $cfg['rozvoz']);

// ─── veřejné: info ───────────────────────────────────────────────────
if ($action === 'info' && $method === 'GET') {
    $firma = '';
    try { $firma = (string) nastaveni_get($pdo, 'firma_nazev', ''); } catch (Throwable $e) {}
    $logo = null;
    try { $logo = nastaveni_get($pdo, 'firma_logo_url', null); } catch (Throwable $e) {}
    $okna = $aktivni ? wo_okna($cfg) : [];
    json_response([
        'ok' => true, 'aktivni' => $aktivni, 'firma' => $firma, 'logo' => $logo,
        'vyzvednuti' => $cfg['vyzvednuti'], 'rozvoz' => $cfg['rozvoz'],
        'rozvoz_cena' => $cfg['rozvoz_cena'], 'rozvoz_zdarma_od' => $cfg['rozvoz_zdarma_od'],
        'rozvoz_psc_omezeno' => trim($cfg['rozvoz_psc']) !== '',
        'min_objednavka' => $cfg['min_objednavka'], 'od' => $cfg['od'], 'do' => $cfg['do'], 'dny' => $cfg['dny'],
        'okna' => $okna, 'zprava' => $cfg['zprava'],
    ]);
}

// ─── veřejné: menu ───────────────────────────────────────────────────
if ($action === 'menu' && $method === 'GET') {
    if (!$aktivni) json_response(['ok' => true, 'aktivni' => false, 'kategorie' => [], 'vyrobky' => []]);
    $vyr = wo_vyrobky($pdo);
    $katIds = array_unique(array_filter(array_map(fn($v) => (int) $v['kategorie_id'], $vyr)));
    $kat = [];
    if ($katIds) {
        $kat = $pdo->query("SELECT id, nazev, COALESCE(NULLIF(ikona,''),'🍽️') AS ikona, COALESCE(poradi, 999) AS poradi
                            FROM kategorie_vyrobku WHERE id IN (" . implode(',', array_map('intval', $katIds)) . ") ORDER BY poradi, nazev")->fetchAll(PDO::FETCH_ASSOC);
    }
    $pub = array_map(fn($v) => ['id' => $v['id'], 'nazev' => $v['nazev'], 'kategorie_id' => (int) $v['kategorie_id'], 'cena' => $v['cena'],
        'obrazek_url' => $v['obrazek_url'], 'alergeny' => $v['alergeny'], 'popis' => $v['popis'], 'hmotnost_g' => $v['hmotnost_g']], $vyr);
    json_response(['ok' => true, 'aktivni' => true, 'kategorie' => $kat, 'vyrobky' => $pub]);
}

// ─── veřejné: objednávka ─────────────────────────────────────────────
if ($action === 'order' && $method === 'POST') {
    if (!$aktivni) json_error('Online objednávky teď nepřijímáme', 403);
    $d = json_decode(file_get_contents('php://input'), true) ?? [];
    if (!empty($d['web'])) json_error('Neplatný požadavek', 400);                          // honeypot (skryté pole)

    // limit: max 6 objednávek z jedné IP za 30 min
    $ip = substr((string) ($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45);
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS web_objednavky_rl (id INT AUTO_INCREMENT PRIMARY KEY, ip VARCHAR(45), kdy DATETIME DEFAULT CURRENT_TIMESTAMP, INDEX (ip, kdy))");
        $rl = $pdo->prepare("SELECT COUNT(*) FROM web_objednavky_rl WHERE ip = :ip AND kdy > NOW() - INTERVAL 30 MINUTE");
        $rl->execute(['ip' => $ip]);
        if ((int) $rl->fetchColumn() >= 6) json_error('Příliš mnoho objednávek za krátkou dobu. Zkuste to prosím později nebo zavolejte.', 429);
    } catch (Throwable $e) {}

    $typ = ($d['typ'] ?? '') === 'rozvoz' ? 'rozvoz' : 'vyzvednuti';
    if ($typ === 'rozvoz' && !$cfg['rozvoz']) json_error('Rozvoz teď nenabízíme', 400);
    if ($typ === 'vyzvednuti' && !$cfg['vyzvednuti']) json_error('Vyzvednutí teď nenabízíme', 400);

    $jmeno = mb_substr(trim((string) ($d['jmeno'] ?? '')), 0, 80);
    $tel   = mb_substr(preg_replace('/[^0-9+ ]/', '', (string) ($d['telefon'] ?? '')), 0, 30);
    $email = mb_substr(trim((string) ($d['email'] ?? '')), 0, 120);
    $pozn  = mb_substr(trim((string) ($d['poznamka'] ?? '')), 0, 500);
    if ($jmeno === '') json_error('Vyplňte jméno', 400);
    if (strlen(preg_replace('/\D/', '', $tel)) < 9) json_error('Vyplňte telefon', 400);
    if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) json_error('Neplatný e-mail', 400);
    if (empty($d['souhlas'])) json_error('Potvrďte souhlas se zpracováním údajů', 400);

    $adresa = ''; $psc = ''; $mesto = '';
    if ($typ === 'rozvoz') {
        $adresa = mb_substr(trim((string) ($d['adresa'] ?? '')), 0, 150);
        $psc    = preg_replace('/\D/', '', (string) ($d['psc'] ?? ''));
        $mesto  = mb_substr(trim((string) ($d['mesto'] ?? '')), 0, 80);
        if ($adresa === '' || $mesto === '' || strlen($psc) !== 5) json_error('Vyplňte adresu, PSČ a město', 400);
        if (!wo_psc_ok($psc, $cfg['rozvoz_psc'])) json_error('Na tuto adresu bohužel nerozvážíme (PSČ ' . $psc . ')', 400);
    }

    // čas: musí ležet v nabízeném okně
    $casTs = strtotime(str_replace('T', ' ', (string) ($d['cas'] ?? '')));
    if (!$casTs) json_error('Vyberte čas', 400);
    $okOkno = false;
    foreach (wo_okna($cfg) as $o) {
        if (date('Y-m-d', $casTs) === $o['den'] && date('H:i', $casTs) >= $o['od'] && date('H:i', $casTs) <= $o['do']) { $okOkno = true; break; }
    }
    if (!$okOkno) json_error('Vybraný čas už není dostupný — vyberte prosím jiný', 400);

    // položky: ceny ze serveru
    $vyrMap = [];
    foreach (wo_vyrobky($pdo) as $v) $vyrMap[$v['id']] = $v;
    $items = [];
    foreach ((array) ($d['polozky'] ?? []) as $p) {
        $id = (int) ($p['id'] ?? 0); $mn = (int) ($p['mnozstvi'] ?? 0);
        if (!$id || $mn < 1) continue;
        if ($mn > 99) json_error('Max 99 ks od jedné položky', 400);
        if (!isset($vyrMap[$id])) json_error('Položka už není v nabídce — obnovte prosím stránku', 409);
        $items[$id] = ($items[$id] ?? 0) + $mn;
    }
    if (!$items) json_error('Košík je prázdný', 400);
    if (count($items) > 50) json_error('Max 50 různých položek', 400);

    $bez = 0.0; $dph = 0.0; $zbozi = 0.0;
    foreach ($items as $id => $mn) {
        $v = $vyrMap[$id];
        $bez += $mn * $v['cena_bez_dph'];
        $dph += $mn * $v['cena_bez_dph'] * $v['sazba_dph'] / 100;
        $zbozi += $mn * $v['cena'];
    }
    if ($cfg['min_objednavka'] > 0 && $zbozi + 0.001 < $cfg['min_objednavka']) {
        json_error('Minimální objednávka je ' . number_format($cfg['min_objednavka'], 0, ',', ' ') . ' Kč', 400);
    }
    $dopravaSdph = 0.0;
    if ($typ === 'rozvoz' && $cfg['rozvoz_cena'] > 0 && !($cfg['rozvoz_zdarma_od'] > 0 && $zbozi >= $cfg['rozvoz_zdarma_od'])) $dopravaSdph = $cfg['rozvoz_cena'];
    $dphPripl = (float) nastaveni_get($pdo, 'dph_sazba_priplatky', 21);
    $dopravaBez = round($dopravaSdph / (1 + $dphPripl / 100), 2);
    $bez += $dopravaBez; $dph += $dopravaSdph - $dopravaBez;
    $celkem = round($bez + $dph, 2);

    wo_ensure($pdo);
    $token = bin2hex(random_bytes(12));
    try {
        $pdo->beginTransaction();
        $odbId = (int) ($pdo->query("SELECT id FROM odberatele WHERE nazev = '🛍️ Online zákazníci' LIMIT 1")->fetchColumn() ?: 0);
        if (!$odbId) {
            $pdo->prepare("INSERT INTO odberatele (nazev) VALUES ('🛍️ Online zákazníci')")->execute();
            $odbId = (int) $pdo->lastInsertId();
        }
        $cislo = kanal_dalsi_cislo($pdo, 'web');
        $poznFull = trim(($typ === 'rozvoz' ? "🛵 Rozvoz: $adresa, $psc $mesto" : '📦 Vyzvednutí') . "\n👤 $jmeno · 📞 $tel" . ($pozn !== '' ? "\n📝 $pozn" : ''));
        $pdo->prepare("INSERT INTO objednavky (cislo, typ, odberatel_id, datum_objednani, datum_dodani, castka_bez_dph, castka_dph, castka_celkem,
                         stav, puvod, pos_typ, pos_payment, poznamka)
                       VALUES (:c, 'web', :o, NOW(), :dd, :b, :d, :ck, 'nova', 'web', :pt, 'pri_vyzvednuti', :pz)")
            ->execute(['c' => $cislo, 'o' => $odbId, 'dd' => date('Y-m-d', $casTs), 'b' => round($bez, 2), 'd' => round($dph, 2), 'ck' => $celkem, 'pt' => $typ, 'pz' => $poznFull]);
        $objId = (int) $pdo->lastInsertId();
        $ins = $pdo->prepare("INSERT INTO objednavky_polozky (objednavka_id, vyrobek_id, vyrobek_nazev, jednotka, mnozstvi, cena_bez_dph, sazba_dph)
                              VALUES (:o, :v, :n, 'ks', :m, :c, :s)");
        foreach ($items as $id => $mn) {
            $v = $vyrMap[$id];
            $ins->execute(['o' => $objId, 'v' => $id, 'n' => mb_substr($v['nazev'], 0, 255), 'm' => $mn, 'c' => round($v['cena_bez_dph'], 2), 's' => $v['sazba_dph']]);
        }
        if ($dopravaSdph > 0) $ins->execute(['o' => $objId, 'v' => null, 'n' => '🛵 Doprava', 'm' => 1, 'c' => $dopravaBez, 's' => $dphPripl]);
        $pdo->prepare("UPDATE objednavky SET vyzvednuti_cas = :ct, vyzvednuti_jmeno = :j, vyzvednuti_tel = :t, vyzvednuti_email = :e, web_token = :tok, web_adresa = :a WHERE id = :id")
            ->execute(['ct' => date('Y-m-d H:i:s', $casTs), 'j' => $jmeno, 't' => $tel, 'e' => ($email !== '' ? $email : null), 'tok' => $token,
                       'a' => ($typ === 'rozvoz' ? "$adresa, $psc $mesto" : null), 'id' => $objId]);
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        json_error_safe('Objednávku se nepodařilo uložit', $e, 500);
    }
    try { $pdo->prepare("INSERT INTO web_objednavky_rl (ip) VALUES (:ip)")->execute(['ip' => $ip]); } catch (Throwable $e) {}

    // kuchyně (fronta/KDS) — stejná podmínka jako POS (kitchen_settings.auto_fire, default ZAP)
    try {
        $af = $pdo->query("SELECT auto_fire FROM kitchen_settings WHERE id=1")->fetchColumn();
        if ($af === false || (bool) $af) {
            $vget = $pdo->prepare("SELECT priprava_min, kitchen_station_id FROM vyrobky WHERE id = :id");
            $kins = $pdo->prepare("INSERT INTO kitchen_queue (objednavka_id, polozka_id, station_id, vyrobek_nazev, mnozstvi, priprava_min, stav, cas_pridani, poznamka)
                                   VALUES (:o, NULL, :s, :n, :m, :pm, 'queued', NOW(), :pz)");
            $kitPozn = '🛍️ Online ' . ($typ === 'rozvoz' ? '🛵 rozvoz' : '📦 vyzvednutí') . ' ' . date('j.n. H:i', $casTs) . ' · ' . $jmeno . ' · ' . $cislo;
            foreach ($items as $id => $mn) {
                $vget->execute(['id' => $id]); $vv = $vget->fetch(PDO::FETCH_ASSOC) ?: [];
                $kins->execute(['o' => $objId, 's' => isset($vv['kitchen_station_id']) && $vv['kitchen_station_id'] !== null ? (int) $vv['kitchen_station_id'] : null,
                                'n' => $vyrMap[$id]['nazev'], 'm' => $mn, 'pm' => (int) ($vv['priprava_min'] ?? 10), 'pz' => $kitPozn]);
            }
        }
    } catch (Throwable $e) { /* kuchyně ne-fatal */ }

    // upozornění v adminu
    try {
        $pdo->prepare("INSERT INTO notifications (kind, title, msg, link, severity) VALUES ('web_order', :t, :m, :l, 'info')")
            ->execute(['t' => '🛍️ Online objednávka ' . $cislo, 'm' => ($typ === 'rozvoz' ? '🛵 Rozvoz' : '📦 Vyzvednutí') . ' ' . date('j.n. H:i', $casTs) . ' · ' . $jmeno . ' · ' . number_format($celkem, 0, ',', ' ') . ' Kč',
                       'l' => '#/objednavky']);
    } catch (Throwable $e) {}

    // potvrzení zákazníkovi
    $stavUrl = rtrim((defined('APP_URL') ? APP_URL : ''), '/') . '/objednat/?stav=' . $token;
    if ($email !== '') {
        try {
            require_once __DIR__ . '/_smtp_lib.php';
            $firma = (string) nastaveni_get($pdo, 'firma_nazev', '');
            $subj = 'Objednávka ' . $cislo . ' přijata';
            $body = "Dobrý den, $jmeno,\n\nděkujeme, vaši objednávku $cislo jsme přijali.\n"
                  . ($typ === 'rozvoz' ? "Doručíme ji na: $adresa, $psc $mesto\n" : "Vyzvednutí: ")
                  . ($typ === 'rozvoz' ? 'Kdy: ' : '') . date('j. n. Y H:i', $casTs) . "\n"
                  . 'K úhradě při ' . ($typ === 'rozvoz' ? 'převzetí' : 'vyzvednutí') . ': ' . number_format($celkem, 2, ',', ' ') . " Kč\n\n"
                  . "Stav objednávky: $stavUrl\n\nDěkujeme" . ($firma ? ",\n$firma" : '') . "\n";
            appek_mail_raw($email, '=?UTF-8?B?' . base64_encode($subj) . '?=', $body, "Content-Type: text/plain; charset=UTF-8");
        } catch (Throwable $e) {}
    }

    json_response(['ok' => true, 'cislo' => $cislo, 'celkem' => $celkem, 'doprava' => $dopravaSdph, 'token' => $token, 'cas' => date('Y-m-d H:i', $casTs), 'typ' => $typ]);
}

// ─── veřejné: stav objednávky ────────────────────────────────────────
if ($action === 'stav' && $method === 'GET') {
    $t = preg_replace('/[^a-f0-9]/', '', (string) ($_GET['t'] ?? ''));
    if (strlen($t) !== 24) json_error('Objednávka nenalezena', 404);
    wo_ensure($pdo);
    $o = $pdo->prepare("SELECT id, cislo, stav, pos_typ, pos_payment, castka_celkem, vyzvednuti_cas, vyzvednuti_pripraveno, vyzvednuto_kdy, web_adresa, datum_objednani
                        FROM objednavky WHERE web_token = :t AND puvod = 'web' LIMIT 1");
    $o->execute(['t' => $t]);
    $r = $o->fetch(PDO::FETCH_ASSOC);
    if (!$r) json_error('Objednávka nenalezena', 404);
    $kuchyne = ['celkem' => 0, 'hotovo' => 0, 'pripravuje' => 0];
    try {
        $k = $pdo->prepare("SELECT stav, COUNT(*) n FROM kitchen_queue WHERE objednavka_id = :o GROUP BY stav");
        $k->execute(['o' => $r['id']]);
        foreach ($k->fetchAll(PDO::FETCH_ASSOC) as $x) {
            $kuchyne['celkem'] += (int) $x['n'];
            if (in_array($x['stav'], ['ready', 'served'], true)) $kuchyne['hotovo'] += (int) $x['n'];
            if ($x['stav'] === 'preparing') $kuchyne['pripravuje'] += (int) $x['n'];
        }
    } catch (Throwable $e) {}
    $kuryr = null;
    try {
        $c = $pdo->prepare("SELECT stav FROM courier_deliveries WHERE objednavka_id = :o ORDER BY id DESC LIMIT 1");
        $c->execute(['o' => $r['id']]);
        $kuryr = $c->fetchColumn() ?: null;
    } catch (Throwable $e) {}
    // krok: prijato → pripravuje → pripraveno (vyzvednutí) | na_ceste (rozvoz) → hotovo; zruseno
    $krok = 'prijato';
    if ($r['stav'] === 'zrusena') $krok = 'zruseno';
    elseif ($r['vyzvednuto_kdy'] || $r['stav'] === 'dorucena' || $kuryr === 'doruceno') $krok = 'hotovo';
    elseif (in_array($kuryr, ['vyzvednuto', 'na_ceste'], true) || $r['stav'] === 'expedovana') $krok = 'na_ceste';
    elseif ($r['vyzvednuti_pripraveno'] || ($kuchyne['celkem'] > 0 && $kuchyne['hotovo'] >= $kuchyne['celkem'])) $krok = 'pripraveno';
    elseif ($kuchyne['pripravuje'] > 0 || $kuchyne['hotovo'] > 0) $krok = 'pripravuje';
    $firma = '';
    try { $firma = (string) nastaveni_get($pdo, 'firma_nazev', ''); } catch (Throwable $e) {}
    json_response(['ok' => true, 'cislo' => $r['cislo'], 'typ' => $r['pos_typ'], 'krok' => $krok, 'celkem' => (float) $r['castka_celkem'],
        'zaplaceno' => $r['pos_payment'] !== 'pri_vyzvednuti', 'cas' => $r['vyzvednuti_cas'], 'adresa' => $r['web_adresa'], 'firma' => $firma]);
}

json_error('Neznámá akce', 400);
