<?php
/**
 * 💱 MĚNA & PŘEPOČET (v3.0.283).
 *
 * Config v nastaveni.mena_config_json:
 *   { kod:'EUR', kurz:25.000, zobrazeni:'kc'|'mena', dual_doklady:bool, zakladni:'CZK'|kod }
 *   (zakladni = měna uložených částek; po trvalém přepočtu = kod — viz _mena_lib.php)
 *   - kurz = kolik Kč za 1 jednotku cílové měny (ČNB konvence, např. 1 EUR = 25,265 Kč)
 *   - zobrazeni='mena' → admin/POS/B2B ukazují ceny PŘEPOČTENÉ do cílové měny
 *     (DB zůstává v Kč — vratné přepnutím zpět na 'kc')
 *   - dual_doklady → faktura/účtenka ukáže pod součtem informativní přepočet
 *
 * GET                                → { config }
 * GET  ?action=cnb&kod=EUR           → { kurz, datum } z denního kurzovního lístku ČNB
 * POST ?action=save { config }       → uloží (validace kod/kurz)
 * GET  ?action=prepocet_nahled&kurz= → { pocet, ukazky:[{id,nazev,stara,nova}] }
 * POST ?action=prepocet { kurz, kod } → TRVALE přepíše vyrobky.cena_bez_dph, pevné ceny skupin
 *                                      a ceny surovin (cena/kurz) + config.zakladni = kod,
 *                                      transakce + zápis do activity logu. UI před tím
 *                                      vynucuje zálohu DB (admin_zalohy create).
 *                                      🆕 v3.0.643 — 409, když jsou zapnuté platby ve více měnách.
 *
 * 🆕 v3.0.643 — PLATBA V KČ I V CIZÍ MĚNĚ (blok config.platba, viz _mena_lib.php):
 * POST ?action=save_platba { platba:{…} } → uloží volby (aktivni jen při ceníku v Kč, jinak 409)
 * POST ?action=kurzy_obnovit             → stáhne dnešní kurzy ČNB do mena_kurzy
 * GET  (bez action) navíc vrací kurzy{KOD:{kurz,datum,nacteno_at}} a platba_info (stav kurzů)
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/_admin_auth.php';
cors_headers();
require_admin();
header('Content-Type: application/json; charset=UTF-8');

$pdo = db();

require_once __DIR__ . '/_mena_lib.php';   // MENA_PODPOROVANE + mena_config_load (sdílené s doklady)

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$action = $_GET['action'] ?? '';

// ── GET config ────────────────────────────────────────────────────────
if ($method === 'GET' && $action === '') {
    $cfg = mena_config_load($pdo);
    json_response(['config' => $cfg, 'podporovane' => MENA_PODPOROVANE,
                   'kurzy' => mena_admin_posledni_kurzy($pdo), 'platba_info' => mena_platebni_info($pdo, 'admin')]);
}

/** Poslední uložený kurz ČNB pro každou měnu (pro UI nastavení). */
function mena_admin_posledni_kurzy(PDO $pdo): array {
    $out = [];
    try {
        $rows = $pdo->query("SELECT k.mena, k.kurz, k.datum, k.nacteno_at FROM mena_kurzy k
                             JOIN (SELECT mena, MAX(datum) d FROM mena_kurzy WHERE zdroj = 'cnb' GROUP BY mena) x
                               ON x.mena = k.mena AND x.d = k.datum WHERE k.zdroj = 'cnb'")->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as $r) $out[$r['mena']] = ['kurz' => (float) $r['kurz'], 'datum' => $r['datum'], 'nacteno_at' => $r['nacteno_at']];
    } catch (Throwable $e) { /* tabulka ještě není */ }
    return $out;
}

/** Uloží celý config (legacy pole + blok platba) — jediný zápis mena_config_json. */
function mena_admin_uloz(PDO $pdo, array $legacy, array $platba): void {
    unset($platba['prijimane']);
    $legacy['platba'] = $platba;   // zobrazeni se NEpřepisuje — při zapnutých platbách ho na 'kc' převede až mena_config_finish
    $pdo->prepare("INSERT INTO nastaveni (klic, hodnota) VALUES ('mena_config_json', :v)
                   ON DUPLICATE KEY UPDATE hodnota = VALUES(hodnota)")
        ->execute(['v' => json_encode($legacy, JSON_UNESCAPED_UNICODE)]);
}

// ── GET kurz z ČNB ────────────────────────────────────────────────────
if ($method === 'GET' && $action === 'cnb') {
    $kod = strtoupper(trim((string) ($_GET['kod'] ?? 'EUR')));
    if ($kod === 'CZK') json_error('CZK je základní měna — kurz 1', 400);
    if (!isset(MENA_PODPOROVANE[$kod])) json_error('Nepodporovaná měna', 400);
    // 🆕 v3.0.643 — sdílené stažení (_mena_lib) + uložení do mena_kurzy (historie kurzů)
    $l = mena_cnb_stahni(null, 15);
    if (!$l) json_error('ČNB kurzovní lístek nedostupný — zadej kurz ručně', 502);
    try { mena_kurzy_uloz($pdo, $l); } catch (Throwable $e) { /* tabulka ještě není — kurz i tak vrátíme */ }
    if (isset($l['kurzy'][$kod])) {
        json_response(['kurz' => round($l['kurzy'][$kod], 4), 'datum' => date('d.m.Y', strtotime($l['datum'])), 'kod' => $kod]);
    }
    json_error("Měna $kod v lístku ČNB nenalezena", 404);
}

// ── náhled trvalého přepočtu ──────────────────────────────────────────
if ($method === 'GET' && $action === 'prepocet_nahled') {
    $kurz = (float) ($_GET['kurz'] ?? 0);
    if ($kurz <= 0) json_error('Neplatný kurz', 400);
    $pocet = (int) $pdo->query("SELECT COUNT(*) FROM vyrobky")->fetchColumn();
    $rows = $pdo->query("SELECT id, nazev, cena_bez_dph FROM vyrobky WHERE cena_bez_dph > 0 ORDER BY cena_bez_dph DESC LIMIT 6")->fetchAll(PDO::FETCH_ASSOC);
    $ukazky = array_map(fn($r) => [
        'id' => (int) $r['id'], 'nazev' => $r['nazev'],
        'stara' => (float) $r['cena_bez_dph'],
        'nova'  => round((float) $r['cena_bez_dph'] / $kurz, 2),
    ], $rows);
    json_response(['pocet' => $pocet, 'kurz' => $kurz, 'ukazky' => $ukazky]);
}

if ($method !== 'POST') json_error('Method not allowed', 405);
$d = json_input();

// ── uložit config ─────────────────────────────────────────────────────
if ($action === 'save') {
    $cfg = $d['config'] ?? $d;
    $akt = mena_config_load($pdo);
    $kod = strtoupper(trim((string) ($cfg['kod'] ?? 'CZK')));
    // Po trvalém přepočtu je ceník uložený v cílové měně → nejde přepnout jinam ani zobrazit v Kč
    // (to by vyžadovalo zpětný přepočet; návrat = obnova zálohy DB vytvořené před přepočtem).
    if ($akt['zakladni'] !== 'CZK') {
        if ($kod !== $akt['zakladni']) json_error('Ceník je trvale přepočtený do ' . $akt['zakladni'] . ' — měnu nelze změnit (návrat = obnova zálohy DB z doby před přepočtem).', 400);
        $cfg['zobrazeni'] = 'mena';
    }
    if (!isset(MENA_PODPOROVANE[$kod])) json_error('Nepodporovaná měna', 400);
    $kurz = (float) ($cfg['kurz'] ?? 1);
    if ($kod !== 'CZK' && $kurz <= 0) json_error('Kurz musí být kladný', 400);
    // 🆕 v3.0.641 — kurz 1 u cizí měny = nevyplněný kurz (výchozí hodnota z Kč) → odmítni, jinak 1 EUR = 1 Kč
    if ($kod !== 'CZK' && abs($kurz - 1.0) < 0.000001) json_error('Zadej kurz — kolik Kč je 1 ' . $kod . ' (nebo ho načti z ČNB).', 400);
    $save = [
        'kod' => $kod,
        'kurz' => $kod === 'CZK' ? 1.0 : round($kurz, 4),
        'zobrazeni' => (($cfg['zobrazeni'] ?? 'kc') === 'mena' && $kod !== 'CZK') ? 'mena' : 'kc',
        'dual_doklady' => !empty($cfg['dual_doklady']) && $kod !== 'CZK',
        'kurz_zdroj' => (($cfg['kurz_zdroj'] ?? 'rucni') === 'cnb' && $kod !== 'CZK') ? 'cnb' : 'rucni',
        'zakladni' => $akt['zakladni'],
    ];
    mena_admin_uloz($pdo, $save, $akt['platba']);   // 🆕 v3.0.643 — blok platba zůstává
    json_response(['ok' => true, 'config' => mena_config_load($pdo)]);
}

// ── 🆕 v3.0.643 — uložit volby plateb ve více měnách ──────────────────
if ($action === 'save_platba') {
    $akt = mena_config_load($pdo);
    $in = is_array($d['platba'] ?? null) ? $d['platba'] : [];
    $p = mena_platba_norm($in, $akt);
    if (!empty($in['aktivni']) && $akt['zakladni'] !== 'CZK') {
        json_error('Ceník je trvale přepočtený do ' . $akt['zakladni'] . ' — platby v Kč i cizí měně zapnout nejde (ceník musí zůstat v Kč). Návrat = obnova zálohy DB z doby před přepočtem.', 409);
    }
    if ($p['aktivni'] && $p['kurz_zdroj'] === 'rucni') {
        foreach ($p['meny'] as $m) {
            if (empty($p['kurzy_rucni'][$m])) json_error("Zadej vlastní kurz pro $m (kolik Kč je 1 $m), nebo zvol kurz ČNB.", 400);
        }
    }
    if (trim((string) ($in['iban_eur'] ?? '')) !== '' && $p['iban_eur'] === '') json_error('EUR IBAN nemá platný formát (např. CZ65 0800 0000 1920 0014 5399).', 400);
    // zobrazení z ULOŽENÉHO configu (načtený ho při zapnutých platbách přepíše na 'kc') → po vypnutí plateb
    //   se vrátí původní režim (např. „prodávat v EUR"), nic se trvale neztratí
    $rawCfg = json_decode((string) $pdo->query("SELECT hodnota FROM nastaveni WHERE klic = 'mena_config_json'")->fetchColumn(), true);
    $zobrUlozene = (is_array($rawCfg) && ($rawCfg['zobrazeni'] ?? '') === 'mena') ? 'mena' : $akt['zobrazeni'];
    $legacy = ['kod' => $akt['kod'], 'kurz' => $akt['kurz'], 'zobrazeni' => $zobrUlozene, 'dual_doklady' => $akt['dual_doklady'],
               'kurz_zdroj' => $akt['kurz_zdroj'], 'zakladni' => $akt['zakladni']];
    $prelozeno = null;
    if ($p['aktivni'] && !$akt['platba']['aktivni'] && $akt['zobrazeni'] === 'mena' && $akt['kod'] !== 'CZK') {
        // Starý režim „prodávat v cílové měně" → výchozí měna platby (ceník i zobrazení zůstávají v Kč)
        if (!array_key_exists('vychozi', $in) && in_array($akt['kod'], $p['prijimane'], true)) $p['vychozi'] = $akt['kod'];
        $prelozeno = 'Režim „prodávat v ' . $akt['kod'] . '" je nahrazen volbou měny u každé platby (výchozí ' . $p['vychozi'] . ').';
    }
    mena_admin_uloz($pdo, $legacy, $p);
    // Kurz ČNB hned stáhni, ať první platba v cizí měně nečeká (selhání nevadí — zkusí to cron/líně)
    if ($p['aktivni'] && $p['kurz_zdroj'] === 'cnb') { try { mena_kurzy_aktualizuj($pdo, null, 8); } catch (Throwable $e) { /* ignore */ } }
    json_response(['ok' => true, 'config' => mena_config_load($pdo), 'kurzy' => mena_admin_posledni_kurzy($pdo),
                   'platba_info' => mena_platebni_info($pdo, 'admin'), 'prelozeno' => $prelozeno]);
}

// ── 🆕 v3.0.643 — ručně obnovit kurzy ČNB ─────────────────────────────
if ($action === 'kurzy_obnovit') {
    $l = mena_kurzy_aktualizuj($pdo, null, 15);
    if (!$l) json_error('ČNB kurzovní lístek nedostupný — zkus to později (platby použijí poslední známý kurz).', 502);
    json_response(['ok' => true, 'datum' => $l['datum'], 'kurzy' => mena_admin_posledni_kurzy($pdo), 'platba_info' => mena_platebni_info($pdo, 'admin')]);
}

// ── TRVALÝ přepočet ceníku ────────────────────────────────────────────
if ($action === 'prepocet') {
    $kurz = (float) ($d['kurz'] ?? 0);
    if ($kurz <= 0.0001 || $kurz > 10000) json_error('Neplatný kurz', 400);
    if (abs($kurz - 1.0) < 0.000001) json_error('Kurz 1 = žádná změna', 400);
    $akt = mena_config_load($pdo);
    $cil = strtoupper(trim((string) ($d['kod'] ?? $akt['kod'])));
    if ($cil === 'CZK' || !isset(MENA_PODPOROVANE[$cil])) json_error('Vyber cílovou měnu (EUR, USD…) a ulož ji před přepočtem', 400);
    if ($akt['zakladni'] !== 'CZK') json_error('Ceník už je přepočtený do ' . $akt['zakladni'], 400);
    // 🆕 v3.0.643 — trvalý přepočet a platby ve více měnách se vylučují (ceník musí zůstat v Kč)
    if ($akt['platba']['aktivni']) json_error('Trvalý přepočet ceníku nejde, dokud jsou zapnuté platby v Kč i cizí měně — ceník musí zůstat v Kč a měna se volí u každé platby. Nejdřív platby ve více měnách vypni.', 409);
    $admin = function_exists('aktualni_admin') ? (aktualni_admin($pdo)['jmeno'] ?? 'Admin') : 'Admin';
    $pdo->beginTransaction();
    try {
        $pocet = (int) $pdo->query("SELECT COUNT(*) FROM vyrobky WHERE cena_bez_dph > 0")->fetchColumn();
        $pdo->prepare("UPDATE vyrobky SET cena_bez_dph = ROUND(cena_bez_dph / :k, 2)")->execute(['k' => $kurz]);
        // 🐛 v3.0.376 — přepočítej i ABSOLUTNÍ pevné ceny skupin. cenik_pro_odberatele jim dává
        //   NEJVYŠŠÍ prioritu → bez přepočtu by odběratel s pevnou cenou dostal starou Kč hodnotu
        //   v cílové měně (~25× přeplatek). % slevy jsou relativní → přepočet nepotřebují.
        try {
            $pdo->prepare("UPDATE cenove_skupiny_slevy SET pevna_cena = ROUND(pevna_cena / :k, 2) WHERE pevna_cena IS NOT NULL AND pevna_cena > 0")
                ->execute(['k' => $kurz]);
        } catch (Throwable $e) { /* tabulka/sloupec nemusí být na staré instalaci */ }
        // 🆕 v3.0.640 — i ceny surovin (jinak food cost míchá Kč suroviny s cenami výrobků v cizí měně)
        try {
            $pdo->prepare("UPDATE suroviny SET cena_baleni = ROUND(cena_baleni / :k, 2) WHERE cena_baleni IS NOT NULL AND cena_baleni > 0")
                ->execute(['k' => $kurz]);
        } catch (Throwable $e) { /* stará instalace bez sloupce */ }
        // audit do activity logu (best-effort, tabulka nemusí existovat na staré instalaci)
        try {
            $pdo->prepare("INSERT INTO activity_log (kdo, akce, detail) VALUES (:kdo, 'mena_prepocet', :det)")
                ->execute(['kdo' => $admin, 'det' => "Trvalý přepočet ceníku kurzem $kurz ($pocet výrobků)"]);
        } catch (Throwable $e) { /* ignore */ }
        // Ceník je teď v cílové měně → config.zakladni = cíl (prodej i doklady jedou v ní, už se nedělí kurzem).
        //   kurz zůstává = Kč za 1 jednotku (pro rekapitulaci DPH v Kč na dokladech).
        $novy = ['kod' => $cil, 'kurz' => round($kurz, 4), 'zobrazeni' => 'mena', 'dual_doklady' => $akt['dual_doklady'],
                 'kurz_zdroj' => $akt['kurz_zdroj'], 'zakladni' => $cil];
        mena_admin_uloz($pdo, $novy, array_merge($akt['platba'], ['aktivni' => false]));
        $pdo->commit();
        json_response(['ok' => true, 'prepocteno' => $pocet, 'kurz' => $kurz, 'config' => mena_config_load($pdo)]);
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        json_error_safe('Přepočet selhal', $e, 500);
    }
}

json_error('Neznámá akce', 400);
