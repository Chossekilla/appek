<?php
/**
 * 📊 Účetní integrace (POHODA mServer + ABRA Flexi / FlexiBee) — sdílené jádro.
 *
 *   - nastavení (klíče v `nastaveni`, hesla NIKDY nejdou do klienta — maska jako u SMTP)
 *   - načtení faktury v normalizovaném tvaru (stejná kaskáda položek jako admin detail,
 *     součty = hlavička faktury = „K úhradě" v PDF; rozpis DPH = faktura.php)
 *   - rozpočítání základu/DPH na položky tak, aby Σ po sazbách sedělo PŘESNĚ na rozpis z PDF
 *   - odchozí HTTP (SSRF politika, timeouty, TLS verify, bez redirectů, pinned DNS)
 *   - log (accounting_sync_log) + stav exportu (accounting_export_state, 1 řádek / systém × faktura)
 *
 * Tabulky vytváří api/_full_schema.php (fresh-install safe); ucetni_ensure_schema() je pojistka.
 *
 * SSRF politika (odchozí URL):
 *   - jen http/https, bez jména/hesla v URL, žádné přesměrování (CURLOPT_FOLLOWLOCATION=0)
 *   - DNS se rozpozná JEDNOU, ověří a připíchne (CURLOPT_RESOLVE) → žádný DNS-rebinding
 *   - vždy zakázáno: 0.0.0.0/8, link-local 169.254/16 (cloud metadata), multicast, rezervované
 *   - privátní sítě (10/8, 172.16/12, 192.168/16, 100.64/10, fc00::/7) a loopback jen když
 *     super-admin výslovně zapne „Povolit adresy v místní síti" (ucetni_povolit_lan=1) —
 *     typicky APPEK nainstalovaný ve stejné LAN jako POHODA mServer
 *   - nešifrované http:// jen do místní sítě (s opt-in); veřejný internet = povinně https://
 */

if (!defined('UCETNI_MASKA')) define('UCETNI_MASKA', '••••••••');

/** Definice nastavení per systém: klíč v DB => [typ, max délka]. Hesla = 'secret'. */
function ucetni_settings_def(string $system): array {
    if ($system === 'pohoda') {
        return [
            'pohoda_enabled'     => 'bool',
            'pohoda_url'         => 'url',
            'pohoda_ico'         => 'text',
            'pohoda_username'    => 'text',
            'pohoda_password'    => 'secret',
            'pohoda_predkontace' => 'text', // volitelné — zkratka předkontace (např. „3Fv")
            'pohoda_cleneni'     => 'text', // volitelné — zkratka členění DPH (např. „UD")
        ];
    }
    if ($system === 'flexibee') {
        return [
            'flexibee_enabled'      => 'bool',
            'flexibee_url'          => 'url',
            'flexibee_company'      => 'text',
            'flexibee_username'     => 'text',
            'flexibee_password'     => 'secret',
            'flexibee_typ_faktura'  => 'text', // kód typu vydané faktury (výchozí FAKTURA)
            'flexibee_typ_dobropis' => 'text', // kód typu dobropisu (prázdné = auto-detekce)
        ];
    }
    return [];
}

function ucetni_system_ok(string $system): bool {
    return in_array($system, ['pohoda', 'flexibee'], true);
}

/** Načte nastavení (VČETNĚ hesla — jen pro server-side použití). */
function ucetni_settings(PDO $pdo, string $system): array {
    $out = [];
    $keys = array_keys(ucetni_settings_def($system));
    $keys[] = 'ucetni_povolit_lan';
    $ph = implode(',', array_fill(0, count($keys), '?'));
    $vals = [];
    try {
        $st = $pdo->prepare("SELECT klic, hodnota FROM nastaveni WHERE klic IN ($ph)");
        $st->execute($keys);
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) $vals[$r['klic']] = (string) $r['hodnota'];
    } catch (Throwable $e) { /* nastaveni vždy existuje; pro jistotu prázdné */ }
    foreach ($keys as $k) $out[$k] = $vals[$k] ?? '';
    return $out;
}

/** Nastavení pro klienta — heslo nahrazené maskou + příznak *_set. */
function ucetni_settings_public(PDO $pdo, string $system): array {
    $cfg = ucetni_settings($pdo, $system);
    foreach (ucetni_settings_def($system) as $k => $typ) {
        if ($typ === 'secret') {
            $cfg[$k . '_set'] = $cfg[$k] !== '';
            $cfg[$k] = '';
        }
    }
    $cfg['ucetni_povolit_lan'] = $cfg['ucetni_povolit_lan'] === '1';
    return $cfg;
}

function ucetni_nastaveni_set(PDO $pdo, string $klic, string $hodnota): void {
    $pdo->prepare("INSERT INTO nastaveni (klic, hodnota) VALUES (:k, :v) ON DUPLICATE KEY UPDATE hodnota = :v2")
        ->execute(['k' => $klic, 'v' => $hodnota, 'v2' => $hodnota]);
}

/**
 * Validuje a uloží nastavení. $in = klíče BEZ prefixu systému (enabled, url, …) nebo s ním.
 * Vrací ['ok'=>true] nebo ['ok'=>false,'error'=>'…'].
 */
function ucetni_settings_save(PDO $pdo, string $system, array $in, ?bool $povolitLan = null): array {
    $def = ucetni_settings_def($system);
    $norm = [];
    foreach ($in as $k => $v) {
        $full = str_starts_with((string) $k, $system . '_') ? $k : $system . '_' . $k;
        if (isset($def[$full])) $norm[$full] = is_bool($v) ? ($v ? '1' : '0') : trim((string) $v);
    }
    $lan = $povolitLan ?? (ucetni_settings($pdo, $system)['ucetni_povolit_lan'] === '1');
    // validace
    if (isset($norm[$system . '_url']) && $norm[$system . '_url'] !== '') {
        $u = rtrim($norm[$system . '_url'], '/');
        if ($system === 'flexibee') $u = preg_replace('~/c/[^/]+.*$~', '', $u); // vložená URL i s /c/firma
        if ($system === 'pohoda')   $u = preg_replace('~/xml$~i', '', $u);
        $chk = ucetni_url_check($u, $lan, false, true);           // syntaxe
        if (!$chk['ok']) return ['ok' => false, 'error' => $chk['error']];
        $chk = ucetni_url_check($u, $lan, true, true);            // SSRF politika (rozsah IP, http jen v LAN)
        // DNS, které teď neodpovídá, uložení neblokuje (ověří se při testu/odeslání); ostatní porušení ano
        if (!$chk['ok'] && !str_contains($chk['error'], '(DNS)')) return ['ok' => false, 'error' => $chk['error']];
        $norm[$system . '_url'] = $u;
    }
    if (isset($norm['pohoda_ico']) && $norm['pohoda_ico'] !== '' && !preg_match('/^\d{6,10}$/', $norm['pohoda_ico'])) {
        return ['ok' => false, 'error' => 'IČO účetní jednotky musí být číslo (6–10 číslic).'];
    }
    if (isset($norm['flexibee_company']) && $norm['flexibee_company'] !== '' && !preg_match('/^[A-Za-z0-9_.\-]{1,100}$/', $norm['flexibee_company'])) {
        return ['ok' => false, 'error' => 'Kód firmy ve FlexiBee smí obsahovat jen písmena bez diakritiky, číslice, _ . - (vidíš ho v URL za /c/).'];
    }
    foreach (['flexibee_typ_faktura', 'flexibee_typ_dobropis', 'pohoda_predkontace', 'pohoda_cleneni'] as $k) {
        if (isset($norm[$k]) && mb_strlen($norm[$k]) > 30) return ['ok' => false, 'error' => 'Hodnota „' . $k . '" je příliš dlouhá.'];
    }
    foreach ($norm as $k => $v) {
        if ($def[$k] === 'secret' && ($v === '' || $v === UCETNI_MASKA)) continue; // prázdné = ponech uložené
        if ($def[$k] === 'bool') $v = ($v === '1' || $v === 'true' || $v === 'on') ? '1' : '0';
        if (mb_strlen($v) > 500) return ['ok' => false, 'error' => 'Hodnota je příliš dlouhá.'];
        ucetni_nastaveni_set($pdo, $k, $v);
    }
    return ['ok' => true];
}

// ═════════════════════════════════════════════════════════════════════
// Schéma
// ═════════════════════════════════════════════════════════════════════

function ucetni_schema_sql(): array {
    return [
        'accounting_sync_log' => "
            CREATE TABLE IF NOT EXISTS accounting_sync_log (
                id INT AUTO_INCREMENT PRIMARY KEY,
                system VARCHAR(20) NOT NULL,
                action VARCHAR(40) NOT NULL,
                target_type VARCHAR(40) NULL,
                target_id INT NULL,
                target_ref VARCHAR(80) NULL,
                success TINYINT(1) NOT NULL DEFAULT 0,
                message TEXT NULL,
                response_excerpt TEXT NULL,
                created_by VARCHAR(120) NULL,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_system (system),
                INDEX idx_target (target_type, target_id),
                INDEX idx_created (created_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ",
        'accounting_export_state' => "
            CREATE TABLE IF NOT EXISTS accounting_export_state (
                id INT AUTO_INCREMENT PRIMARY KEY,
                system VARCHAR(20) NOT NULL,
                faktura_id INT NOT NULL,
                stav VARCHAR(12) NOT NULL DEFAULT 'chyba',
                rezim VARCHAR(12) NULL,
                externi_id VARCHAR(80) NULL,
                externi_cislo VARCHAR(80) NULL,
                hash CHAR(64) NULL,
                ok_at DATETIME NULL,
                posledni_pokus_at DATETIME NULL,
                posledni_chyba TEXT NULL,
                pokusy INT NOT NULL DEFAULT 0,
                UNIQUE KEY uq_system_faktura (system, faktura_id),
                INDEX idx_faktura (faktura_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ",
    ];
}

function ucetni_ensure_schema(PDO $pdo): void {
    static $done = false;
    if ($done) return;
    $done = true;
    foreach (ucetni_schema_sql() as $sql) {
        try { $pdo->exec($sql); } catch (Throwable $e) { error_log('ucetni_ensure_schema: ' . $e->getMessage()); }
    }
    // starší instalace mohly mít accounting_sync_log ze staré (nefunkční) verze bez response_excerpt
    try { $pdo->exec("ALTER TABLE accounting_sync_log ADD COLUMN IF NOT EXISTS response_excerpt TEXT NULL"); } catch (Throwable $e) {}
}

// ═════════════════════════════════════════════════════════════════════
// Log + stav exportu
// ═════════════════════════════════════════════════════════════════════

function ucetni_log(PDO $pdo, string $system, string $action, bool $ok, string $message = '', ?string $targetType = null,
                    ?int $targetId = null, ?string $targetRef = null, string $excerpt = ''): void {
    try {
        $by = $_SESSION['admin_jmeno'] ?? ($_SESSION['admin_email'] ?? (PHP_SAPI === 'cli' ? 'cli' : 'admin'));
        $pdo->prepare("INSERT INTO accounting_sync_log (system, action, target_type, target_id, target_ref, success, message, response_excerpt, created_by)
                       VALUES (:s, :a, :tt, :ti, :tr, :ok, :m, :r, :by)")
            ->execute([
                's' => $system, 'a' => mb_substr($action, 0, 40), 'tt' => $targetType, 'ti' => $targetId,
                'tr' => $targetRef !== null ? mb_substr($targetRef, 0, 80) : null, 'ok' => $ok ? 1 : 0,
                'm' => mb_substr($message, 0, 4000), 'r' => $excerpt !== '' ? mb_substr($excerpt, 0, 2000) : null,
                'by' => mb_substr((string) $by, 0, 120),
            ]);
    } catch (Throwable $e) { error_log('ucetni_log: ' . $e->getMessage()); }
}

function ucetni_state_get(PDO $pdo, string $system, int $fakturaId): ?array {
    $st = $pdo->prepare("SELECT * FROM accounting_export_state WHERE system = :s AND faktura_id = :f");
    $st->execute(['s' => $system, 'f' => $fakturaId]);
    $r = $st->fetch(PDO::FETCH_ASSOC);
    return $r ?: null;
}

/** Zapíše výsledek pokusu o export. Při chybě NEPŘEPISUJE údaje posledního úspěšného exportu. */
function ucetni_state_set(PDO $pdo, string $system, int $fakturaId, bool $ok, string $rezim, ?string $extId,
                          ?string $extCislo, ?string $hash, string $chyba = ''): void {
    if ($ok) {
        $pdo->prepare("INSERT INTO accounting_export_state (system, faktura_id, stav, rezim, externi_id, externi_cislo, hash, ok_at, posledni_pokus_at, posledni_chyba, pokusy)
                       VALUES (:s, :f, 'ok', :r, :ei, :ec, :h, NOW(), NOW(), NULL, 1)
                       ON DUPLICATE KEY UPDATE stav = 'ok', rezim = VALUES(rezim), externi_id = VALUES(externi_id),
                           externi_cislo = VALUES(externi_cislo), hash = VALUES(hash), ok_at = NOW(),
                           posledni_pokus_at = NOW(), posledni_chyba = NULL, pokusy = pokusy + 1")
            ->execute(['s' => $system, 'f' => $fakturaId, 'r' => $rezim, 'ei' => $extId, 'ec' => $extCislo, 'h' => $hash]);
    } else {
        $pdo->prepare("INSERT INTO accounting_export_state (system, faktura_id, stav, rezim, posledni_pokus_at, posledni_chyba, pokusy)
                       VALUES (:s, :f, 'chyba', :r, NOW(), :c, 1)
                       ON DUPLICATE KEY UPDATE stav = 'chyba', posledni_pokus_at = NOW(), posledni_chyba = VALUES(posledni_chyba), pokusy = pokusy + 1")
            ->execute(['s' => $system, 'f' => $fakturaId, 'r' => $rezim, 'c' => mb_substr($chyba, 0, 4000)]);
    }
}

/**
 * Rozhodne, zda export proběhne. Vrací ['pokracovat'=>bool, 'duvod'=>kód, 'zprava'=>text].
 *   - nikdy úspěšně neexportováno → ano
 *   - exportováno a obsah beze změny → ne ('beze_zmeny'), ledaže force
 *   - exportováno a faktura se od té doby změnila → ne ('zmeneno'), ledaže force
 *   - POHODA: pouhé stažení XML souboru (rezim 'soubor') přímé odeslání neblokuje — nevíme, zda byl
 *     soubor naimportován; před duplicitou chrání POHODA sama (numberRequested checkDuplicity="true")
 */
function ucetni_idempotence(?array $state, string $hash, bool $force, string $rezim = ''): array {
    if (!$state || empty($state['ok_at'])) return ['pokracovat' => true, 'duvod' => 'nova', 'zprava' => ''];
    if ($rezim === 'mserver' && ($state['rezim'] ?? '') === 'soubor') return ['pokracovat' => true, 'duvod' => 'po_souboru', 'zprava' => ''];
    if ($force) return ['pokracovat' => true, 'duvod' => 'vynuceno', 'zprava' => ''];
    $kdy = date('j. n. Y H:i', strtotime((string) $state['ok_at']));
    if (hash_equals((string) $state['hash'], $hash)) {
        return ['pokracovat' => false, 'duvod' => 'beze_zmeny',
                'zprava' => "Už exportováno $kdy" . ($state['externi_cislo'] ? " (č. {$state['externi_cislo']})" : '') . ', od té doby beze změny.'];
    }
    return ['pokracovat' => false, 'duvod' => 'zmeneno',
            'zprava' => "Exportováno $kdy, ale faktura se od té doby změnila. Odeslat znovu?"];
}

// ═════════════════════════════════════════════════════════════════════
// Faktura → normalizovaný tvar
// ═════════════════════════════════════════════════════════════════════

/** Číslo jako string s pevným počtem desetin, bez „-0.00". */
function ucetni_num($x, int $dec = 2): string {
    $s = number_format(round((float) $x, $dec), $dec, '.', '');
    if ((float) $s == 0.0) $s = number_format(0, $dec, '.', '');
    return $s;
}

/** Odstraní znaky neplatné v XML 1.0 / JSON (řídicí znaky), ořízne. */
function ucetni_clean(?string $s, int $max = 0): string {
    $s = (string) $s;
    if (!mb_check_encoding($s, 'UTF-8')) $s = mb_convert_encoding($s, 'UTF-8', 'UTF-8');
    $s = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $s) ?? '';
    $s = trim(preg_replace('/\s+/u', ' ', $s) ?? '');
    return $max > 0 ? mb_substr($s, 0, $max) : $s;
}

/** APPEK způsob platby → neutrální kód (prevod|hotove|karta|dobirka). */
function ucetni_platba_kod(?string $zpusob): string {
    $z = strtolower(trim((string) $zpusob));
    if ($z === 'hotove') return 'hotove';
    if (in_array($z, ['karta', 'stripe', 'gopay', 'paypal', 'mobile'], true)) return 'karta';
    if ($z === 'dobirka') return 'dobirka';
    return 'prevod'; // prevod, faktura, qr_platba, nevyplněno → faktura splatná převodem
}

/**
 * Rozpis DPH po sazbách — STEJNÝ algoritmus jako faktura.php (PDF):
 *   Σ nezaokrouhlených položek po sazbách → round(2) → haléřový zbytek proti hlavičce do sazby
 *   s největším základem. Vrací [sazbaKey => ['sazba','zaklad','dph']].
 */
function ucetni_rekapitulace(array $polozky, float $hlavBez, float $hlavDph): array {
    $r = [];
    foreach ($polozky as $p) {
        $bez = (float) $p['cena_mj'] * (float) $p['mnozstvi'];
        $sazba = (float) $p['sazba'];
        $k = ucetni_num($sazba, 2);
        if (!isset($r[$k])) $r[$k] = ['sazba' => $sazba, 'zaklad' => 0.0, 'dph' => 0.0];
        $r[$k]['zaklad'] += $bez;
        $r[$k]['dph'] += $bez * $sazba / 100;
    }
    if (!$r) return [];
    foreach ($r as &$x) { $x['zaklad'] = round($x['zaklad'], 2); $x['dph'] = round($x['dph'], 2); }
    unset($x);
    $maxK = null; $maxB = -INF;
    foreach ($r as $k => $x) if (abs($x['zaklad']) > $maxB) { $maxB = abs($x['zaklad']); $maxK = $k; }
    $resBez = round($hlavBez, 2) - array_sum(array_column($r, 'zaklad'));
    $resDph = round($hlavDph, 2) - array_sum(array_column($r, 'dph'));
    $r[$maxK]['zaklad'] = round($r[$maxK]['zaklad'] + $resBez, 2);
    $r[$maxK]['dph']    = round($r[$maxK]['dph'] + $resDph, 2);
    return $r;
}

/**
 * Doplní každé položce `zaklad` a `dph` (2 des.) tak, že Σ po sazbě == rekapitulace PŘESNĚ.
 * Rozdíl (haléřové zaokrouhlení / zbytek proti hlavičce) jde do položky s největším základem v sazbě.
 */
function ucetni_rozpocitej_polozky(array $polozky, array $rekap): array {
    $poSazbe = [];
    foreach ($polozky as $i => $p) {
        $zaklad = round((float) $p['cena_mj'] * (float) $p['mnozstvi'], 2);
        $polozky[$i]['zaklad'] = $zaklad;
        $polozky[$i]['dph'] = round($zaklad * (float) $p['sazba'] / 100, 2);
        $poSazbe[ucetni_num($p['sazba'], 2)][] = $i;
    }
    foreach ($poSazbe as $k => $idx) {
        if (!isset($rekap[$k])) continue;
        $sumB = 0.0; $sumD = 0.0; $max = $idx[0];
        foreach ($idx as $i) {
            $sumB += $polozky[$i]['zaklad']; $sumD += $polozky[$i]['dph'];
            if (abs($polozky[$i]['zaklad']) > abs($polozky[$max]['zaklad'])) $max = $i;
        }
        $polozky[$max]['zaklad'] = round($polozky[$max]['zaklad'] + round($rekap[$k]['zaklad'] - $sumB, 2), 2);
        $polozky[$max]['dph']    = round($polozky[$max]['dph'] + round($rekap[$k]['dph'] - $sumD, 2), 2);
    }
    return $polozky;
}

/**
 * Načte fakturu (i dobropis) v normalizovaném tvaru pro export. null = nenalezeno.
 *
 * Zdroj položek = stejná kaskáda jako detail faktury v adminu (admin_faktury.php):
 *   faktura_polozky (ruční faktura / dobropis / materializované řádky) → položky dodacích listů
 *   → položky objednávek. Součty = hlavička faktury (castka_*), tj. přesně „K úhradě" z PDF.
 */
function ucetni_nacti_fakturu(PDO $pdo, int $id): ?array {
    $st = $pdo->prepare("
        SELECT f.*, od.nazev AS a_nazev, od.ico AS a_ico, od.dic AS a_dic, od.ulice AS a_ulice,
               od.mesto AS a_mesto, od.psc AS a_psc, od.email AS a_email, od.telefon AS a_telefon
        FROM faktury f JOIN odberatele od ON od.id = f.odberatel_id WHERE f.id = :id");
    $st->execute(['id' => $id]);
    $f = $st->fetch(PDO::FETCH_ASSOC);
    if (!$f) return null;

    // 🐛 v3.0.644 — snapshot odběratele (ke dni vystavení) u KAŽDÉ faktury (dřív jen ruční → automatická
    //   faktura exportovala aktuální adresu/DIČ); aktuální údaje jen fallback pro staré faktury bez snapshotu.
    $pick = function (string $col) use ($f) {
        $s = $f['odb_' . $col . '_snapshot'] ?? null;
        return ($s !== null && $s !== '') ? $s : ($f['a_' . $col] ?? '');
    };

    // položky — kaskáda
    $st = $pdo->prepare("SELECT vyrobek_id, vyrobek_cislo, vyrobek_nazev, jednotka, mnozstvi, cena_bez_dph, sazba_dph, poznamka
                         FROM faktura_polozky WHERE faktura_id = :id ORDER BY poradi, id");
    $st->execute(['id' => $id]);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC);
    $zdroj = 'faktura';
    if (!$rows) {
        $st = $pdo->prepare("SELECT dlp.vyrobek_id, dlp.vyrobek_cislo, dlp.vyrobek_nazev, dlp.jednotka, dlp.mnozstvi,
                                    dlp.cena_bez_dph, dlp.sazba_dph, dlp.poznamka
                             FROM faktury_dodaci_listy fdl JOIN dodaci_list_polozky dlp ON dlp.dodaci_list_id = fdl.dodaci_list_id
                             WHERE fdl.faktura_id = :id ORDER BY dlp.dodaci_list_id, dlp.id");
        $st->execute(['id' => $id]);
        $rows = $st->fetchAll(PDO::FETCH_ASSOC);
        $zdroj = 'dodaci_list';
    }
    if (!$rows) {
        $st = $pdo->prepare("SELECT p.vyrobek_id, v.cislo AS vyrobek_cislo, COALESCE(v.nazev, p.vyrobek_nazev) AS vyrobek_nazev,
                                    COALESCE(j.kod, p.jednotka) AS jednotka, p.mnozstvi, p.cena_bez_dph, p.sazba_dph, p.poznamka
                             FROM faktury_dodaci_listy fdl JOIN dodaci_listy dl ON dl.id = fdl.dodaci_list_id
                             JOIN objednavky_polozky p ON p.objednavka_id = dl.objednavka_id
                             LEFT JOIN vyrobky v ON v.id = p.vyrobek_id LEFT JOIN jednotky j ON j.id = v.jednotka_id
                             WHERE fdl.faktura_id = :id ORDER BY p.id");
        try { $st->execute(['id' => $id]); $rows = $st->fetchAll(PDO::FETCH_ASSOC); } catch (Throwable $e) { $rows = []; }
        $zdroj = $rows ? 'objednavka' : 'zadne';
    }

    $polozky = [];
    foreach ($rows as $i => $r) {
        $polozky[] = [
            'poradi'     => $i + 1,
            'vyrobek_id' => $r['vyrobek_id'] !== null ? (int) $r['vyrobek_id'] : null,
            'kod'        => ucetni_clean($r['vyrobek_cislo'] ?? '', 64),
            'nazev'      => ucetni_clean($r['vyrobek_nazev'] ?? '', 255) ?: 'Položka',
            'poznamka'   => ucetni_clean($r['poznamka'] ?? '', 255),
            'jednotka'   => ucetni_clean($r['jednotka'] ?? '', 10) ?: 'ks',
            'mnozstvi'   => round((float) $r['mnozstvi'], 3),
            'cena_mj'    => round((float) $r['cena_bez_dph'], 2),
            'sazba'      => round((float) $r['sazba_dph'], 2),
        ];
    }

    // vazby: objednávky + DL + platba
    $objednavky = []; $dl = []; $zpusob = null;
    try {
        $st = $pdo->prepare("SELECT dl.cislo AS dl_cislo, o.cislo AS o_cislo, o.zpusob_platby, o.pos_payment
                             FROM faktury_dodaci_listy fdl JOIN dodaci_listy dl ON dl.id = fdl.dodaci_list_id
                             LEFT JOIN objednavky o ON o.id = dl.objednavka_id WHERE fdl.faktura_id = :id ORDER BY dl.id");
        $st->execute(['id' => $id]);
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
            if ($r['dl_cislo']) $dl[] = $r['dl_cislo'];
            if ($r['o_cislo']) $objednavky[] = $r['o_cislo'];
            if ($zpusob === null) $zpusob = $r['zpusob_platby'] ?: ($r['pos_payment'] ?: null);
        }
    } catch (Throwable $e) { /* zpusob_platby nemusí existovat na staré instalaci */ }

    $puvodni = null;
    if (!empty($f['je_dobropis']) && !empty($f['puvodni_faktura_id'])) {
        $st = $pdo->prepare("SELECT id, cislo FROM faktury WHERE id = :id");
        $st->execute(['id' => (int) $f['puvodni_faktura_id']]);
        $puvodni = $st->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    // 💱 v3.0.640 — měna dokladu (snapshot). Účetnictví vede doklad v Kč (domácí měna) + u cizí
    //   měny i částky v ní a kurz. Částky v DB jsou v `mena_zaklad` → Kč = ×k_czk, měna dokladu = ×k_doklad.
    //   Cizí měna = „nativní" doklad v ní (mena_doklad_polozky — totéž počítá PDF faktury i FlexiBee/
    //   POHODA: cena/ks na centy, DPH po řádcích). Kč = částky v cizí měně × kurz (rekapitulace na PDF).
    require_once __DIR__ . '/_mena_lib.php';
    $mena = mena_doklad($pdo, 'faktury', $f);
    $calc = null;
    if ($mena['mena'] !== 'CZK') {
        $calc = mena_doklad_polozky(array_map(fn($pp) => [$pp['mnozstvi'], $pp['cena_mj'], $pp['sazba']], $polozky), $mena);
        $kurzM = (float) $mena['mena_kurz'];
        foreach ($polozky as $i => $pp) $polozky[$i]['cena_mj'] = round($calc['polozky'][$i]['cena'] * $kurzM, 6);
        $bez = round(array_sum(array_column($calc['rozpis_czk'], 'bez')), 2);
        $dph = round(array_sum(array_column($calc['rozpis_czk'], 'dph')), 2);
        $cel = round($bez + $dph, 2);
    } else {
        $bez = round((float) $f['castka_bez_dph'], 2);
        $dph = round((float) $f['castka_dph'], 2);
        $cel = round((float) $f['castka_celkem'], 2);
    }
    $varovani = [];
    // 💱 v3.0.642 — cizí měna: rekapitulace v Kč = částka sazby v cizí měně × kurz (přesně „Rekapitulace
    //   DPH v Kč" z PDF i ISDOC); dřív se dopočítávala z Kč položek a o haléře ujela (171,23 vs 171,20).
    if ($calc) {
        $rekap = [];
        foreach ($calc['rozpis_czk'] as $sz => $r) $rekap[ucetni_num((float) $sz, 2)] = ['sazba' => (float) $sz, 'zaklad' => $r['bez'], 'dph' => $r['dph']];
    } else {
        $rekap = ucetni_rekapitulace($polozky, $bez, $dph);
    }
    if ($polozky) {
        $polozky = ucetni_rozpocitej_polozky($polozky, $rekap);
        $sumItems = 0.0;
        foreach ($polozky as $p) $sumItems += (float) $p['cena_mj'] * (float) $p['mnozstvi'];
        if (abs(round($sumItems, 2) - $bez) > 0.05) {
            $varovani[] = sprintf('Součet položek (%s) neodpovídá základu na faktuře (%s) — rozdíl je zaúčtován do položky s nejvyšší částkou.',
                ucetni_num($sumItems), ucetni_num($bez));
        }
    }
    if (abs(round($bez + $dph, 2) - $cel) > 0.005) {
        $varovani[] = sprintf('Celkem na faktuře (%s) ≠ základ + DPH (%s).', ucetni_num($cel), ucetni_num($bez + $dph));
    }

    $vs = (string) ($f['variabilni_symbol'] ?? '');
    if ($vs === '') $vs = preg_match('/(\d{4})-?(\d+)$/', (string) $f['cislo'], $m) ? $m[1] . str_pad($m[2], 4, '0', STR_PAD_LEFT) : preg_replace('/\D/', '', (string) $f['cislo']);
    $vs = substr(preg_replace('/\D/', '', $vs), 0, 10);

    $inv = [
        'id'               => (int) $f['id'],
        'cislo'            => (string) $f['cislo'],
        'je_dobropis'      => !empty($f['je_dobropis']),
        'puvodni_id'       => $puvodni ? (int) $puvodni['id'] : null,
        'puvodni_cislo'    => $puvodni ? (string) $puvodni['cislo'] : null,
        'datum_vystaveni'  => (string) $f['datum_vystaveni'],
        'datum_dph'        => (string) ($f['datum_dph'] ?: $f['datum_vystaveni']),
        'datum_splatnosti' => (string) ($f['datum_splatnosti'] ?: $f['datum_vystaveni']),
        'vs'               => $vs,
        'mena'             => $mena['mena'],            // měna dokladu (PDF); částky níže jsou v Kč
        'kurz'             => $mena['mena'] === 'CZK' ? 1.0 : (float) $mena['mena_kurz'],
        'platba'           => ucetni_platba_kod($zpusob),
        'poznamka'         => ucetni_clean($f['poznamka'] ?? '', 1000),
        'objednavky'       => array_values(array_unique($objednavky)),
        'dodaci_listy'     => array_values(array_unique($dl)),
        'odberatel'        => [
            'id'      => (int) $f['odberatel_id'],
            'nazev'   => ucetni_clean($pick('nazev'), 255),
            'ico'     => ucetni_clean($pick('ico'), 15),
            'dic'     => ucetni_clean($pick('dic'), 18),
            'ulice'   => ucetni_clean($pick('ulice'), 64),
            'mesto'   => ucetni_clean($pick('mesto'), 45),
            'psc'     => ucetni_clean(preg_replace('/\s+/', ' ', (string) $pick('psc')), 15),
            'email'   => ucetni_clean($f['a_email'] ?? '', 98),
            'telefon' => ucetni_clean($f['a_telefon'] ?? '', 24),
        ],
        'polozky'          => $polozky,
        'polozky_zdroj'    => $zdroj,
        'rekapitulace'     => $rekap,
        'celkem_bez'       => $bez,
        'celkem_dph'       => $dph,
        'celkem'           => $cel,
        'varovani'         => $varovani,
    ];
    // 💱 částky v cizí měně dokladu — STEJNÝ výpočet jako PDF (rozpis per sazba ze zaokrouhlených
    //   součtů, zbytek do největší sazby) → účetnictví ukáže přesně „K úhradě" z faktury.
    if ($calc) {
        $rekM = [];
        foreach ($calc['rozpis'] as $sz => $r) $rekM[ucetni_num((float) $sz, 2)] = ['sazba' => (float) $sz, 'zaklad' => $r['bez'], 'dph' => $r['dph']];
        $inv['cizi'] = [
            'mena'         => $mena['mena'],
            'kurz'         => (float) $mena['mena_kurz'],
            'polozky'      => array_map(fn($c) => ['cena_mj' => $c['cena'], 'zaklad' => $c['zaklad'], 'dph' => $c['dph']], $calc['polozky']),
            'rekapitulace' => $rekM,
            'celkem_bez'   => $calc['bez'],
            'celkem_dph'   => $calc['dph'],
            'celkem'       => $calc['celkem'],
        ];
    }
    $inv['hash'] = ucetni_hash($inv);
    return $inv;
}

/** Otisk obsahu faktury (co se exportuje) — změna = „faktura se od exportu změnila". */
function ucetni_hash(array $inv): string {
    unset($inv['hash'], $inv['varovani']);
    // čísla normalizovaná na pevný počet desetin → otisk nezávisí na serialize_precision serveru
    array_walk_recursive($inv, function (&$v) { if (is_float($v)) $v = ucetni_num($v, 3); });
    return hash('sha256', json_encode($inv, JSON_UNESCAPED_UNICODE));
}

/** ID faktur vystavených v období (vč. dobropisů). $jenNeodeslane = bez úspěšného exportu do $system. */
function ucetni_faktury_v_obdobi(PDO $pdo, string $system, string $od, string $do, bool $jenNeodeslane, int $limit = 500): array {
    $sql = "SELECT f.id FROM faktury f
            LEFT JOIN accounting_export_state s ON s.faktura_id = f.id AND s.system = :sys
            WHERE f.datum_vystaveni BETWEEN :od AND :do" . ($jenNeodeslane ? " AND s.ok_at IS NULL" : '') . "
            ORDER BY f.je_dobropis ASC, f.datum_vystaveni ASC, f.id ASC LIMIT " . (int) $limit;
    $st = $pdo->prepare($sql);
    $st->execute(['sys' => $system, 'od' => $od, 'do' => $do]);
    return array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN));
}

function ucetni_datum_ok(string $d): bool {
    return (bool) preg_match('/^\d{4}-\d{2}-\d{2}$/', $d) && checkdate((int) substr($d, 5, 2), (int) substr($d, 8, 2), (int) substr($d, 0, 4));
}

// ═════════════════════════════════════════════════════════════════════
// Odchozí HTTP — SSRF politika + klient
// ═════════════════════════════════════════════════════════════════════

/** true = IP je v privátní / loopback / CGNAT síti (povoleno jen s opt-in). */
function ucetni_ip_private(string $ip): bool {
    if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
        $l = ip2long($ip);
        foreach ([['10.0.0.0', 8], ['172.16.0.0', 12], ['192.168.0.0', 16], ['127.0.0.0', 8], ['100.64.0.0', 10]] as [$net, $bits]) {
            $mask = -1 << (32 - $bits);
            if (($l & $mask) === (ip2long($net) & $mask)) return true;
        }
        return false;
    }
    $ip = strtolower($ip);
    return $ip === '::1' || str_starts_with($ip, 'fc') || str_starts_with($ip, 'fd');
}

/** true = IP nesmí nikdy (link-local/metadata, 0.0.0.0/8, multicast, rezervované, broadcast). */
function ucetni_ip_forbidden(string $ip): bool {
    if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
        $l = ip2long($ip);
        foreach ([['0.0.0.0', 8], ['169.254.0.0', 16], ['224.0.0.0', 4], ['240.0.0.0', 4], ['192.0.0.0', 24], ['198.18.0.0', 15]] as [$net, $bits]) {
            $mask = -1 << (32 - $bits);
            if (($l & $mask) === (ip2long($net) & $mask)) return true;
        }
        return false;
    }
    $ip = strtolower($ip);
    if ($ip === '::' || str_starts_with($ip, 'fe8') || str_starts_with($ip, 'fe9') || str_starts_with($ip, 'fea') || str_starts_with($ip, 'feb') || str_starts_with($ip, 'ff')) return true;
    if (str_starts_with($ip, '::ffff:')) return ucetni_ip_forbidden(substr($ip, 7)) || ucetni_ip_private(substr($ip, 7));
    return false;
}

/**
 * Ověří URL podle SSRF politiky. $resolve=false = jen syntaktická kontrola (při ukládání nastavení).
 * Vrací ['ok'=>true,'scheme','host','port','ip'] nebo ['ok'=>false,'error'=>česky].
 */
function ucetni_url_check(string $url, bool $povolitLan, bool $resolve = true, bool $jenZakladniAdresa = false): array {
    $p = parse_url($url);
    if (!$p || empty($p['scheme']) || empty($p['host'])) {
        return ['ok' => false, 'error' => 'Neplatná adresa serveru. Zadej celou adresu včetně http:// nebo https://'];
    }
    $scheme = strtolower($p['scheme']);
    if (!in_array($scheme, ['http', 'https'], true)) {
        return ['ok' => false, 'error' => 'Adresa serveru musí začínat http:// nebo https:// (zadáno „' . $scheme . '://").'];
    }
    if (isset($p['user']) || isset($p['pass'])) {
        return ['ok' => false, 'error' => 'Jméno a heslo nepatří do adresy — vyplň je do samostatných polí.'];
    }
    if ($jenZakladniAdresa && (isset($p['query']) || isset($p['fragment']))) {
        return ['ok' => false, 'error' => 'Adresa serveru nesmí obsahovat ? ani # — zadej jen adresu serveru.'];
    }
    $host = strtolower(trim($p['host'], '[]'));
    $port = (int) ($p['port'] ?? ($scheme === 'https' ? 443 : 80));
    if ($port < 1 || $port > 65535) return ['ok' => false, 'error' => 'Neplatný port v adrese serveru.'];
    if (!$resolve) return ['ok' => true, 'scheme' => $scheme, 'host' => $host, 'port' => $port, 'ip' => null];

    if (filter_var($host, FILTER_VALIDATE_IP)) {
        $ips = [$host];
    } else {
        if (!preg_match('/^[a-z0-9.-]+$/', $host)) return ['ok' => false, 'error' => 'Neplatný název serveru „' . $host . '".'];
        $ips = @gethostbynamel($host) ?: [];
        if (!$ips && function_exists('dns_get_record')) {
            foreach ((@dns_get_record($host, DNS_AAAA) ?: []) as $r) if (!empty($r['ipv6'])) $ips[] = $r['ipv6'];
        }
        if (!$ips) return ['ok' => false, 'error' => 'Server „' . $host . '" se nepodařilo najít (DNS). Zkontroluj adresu.'];
    }
    foreach ($ips as $ip) {
        if (ucetni_ip_forbidden($ip)) {
            return ['ok' => false, 'error' => 'Adresa „' . $host . '" míří na zakázaný rozsah (' . $ip . ') — z bezpečnostních důvodů ji nelze použít.'];
        }
        if (ucetni_ip_private($ip) && !$povolitLan) {
            return ['ok' => false, 'error' => 'Adresa „' . $host . '" je v místní síti (' . $ip . '). Připojení do místní sítě je vypnuté — '
                . 'zapni „Povolit adresy v místní síti" (jen pokud APPEK běží ve stejné síti jako účetní server).'];
        }
    }
    $isPrivate = ucetni_ip_private($ips[0]);
    if ($scheme === 'http' && !$isPrivate) {
        return ['ok' => false, 'error' => 'Nešifrované http:// je povolené jen v místní síti. Pro server na internetu použij https:// (heslo by jinak šlo sítí v čitelné podobě).'];
    }
    return ['ok' => true, 'scheme' => $scheme, 'host' => $host, 'port' => $port, 'ip' => $ips[0]];
}

/**
 * Odchozí HTTP request (curl). Nikdy nehází — vrací
 *   ['ok'=>bool (transport+HTTP 2xx), 'http'=>int, 'body'=>string, 'headers'=>array, 'error'=>česky|null]
 * $opt: timeout (s, default 30), connect_timeout (default 8), povolit_lan (bool), max_bytes (default 20 MB)
 */
function ucetni_http(string $method, string $url, array $headers = [], ?string $body = null, array $opt = []): array {
    $chk = ucetni_url_check($url, !empty($opt['povolit_lan']));
    if (!$chk['ok']) return ['ok' => false, 'http' => 0, 'body' => '', 'headers' => [], 'error' => $chk['error']];
    if (!function_exists('curl_init')) return ['ok' => false, 'http' => 0, 'body' => '', 'headers' => [], 'error' => 'Na serveru chybí PHP rozšíření cURL.'];

    $maxBytes = (int) ($opt['max_bytes'] ?? 20 * 1024 * 1024);
    $buf = ''; $tooBig = false; $respHeaders = [];
    $ch = curl_init($url);
    $ipForResolve = str_contains((string) $chk['ip'], ':') ? '[' . $chk['ip'] . ']' : $chk['ip'];
    curl_setopt_array($ch, [
        CURLOPT_CUSTOMREQUEST   => strtoupper($method),
        CURLOPT_RETURNTRANSFER  => false,
        CURLOPT_FOLLOWLOCATION  => false,
        CURLOPT_CONNECTTIMEOUT  => (int) ($opt['connect_timeout'] ?? 8),
        CURLOPT_TIMEOUT         => (int) ($opt['timeout'] ?? 30),
        CURLOPT_SSL_VERIFYPEER  => true,
        CURLOPT_SSL_VERIFYHOST  => 2,
        CURLOPT_HTTPHEADER      => $headers,
        CURLOPT_RESOLVE         => [$chk['host'] . ':' . $chk['port'] . ':' . $ipForResolve],
        CURLOPT_USERAGENT       => 'APPEK-ucetni/1.0',
        CURLOPT_ENCODING        => '', // gzip/deflate odpovědi (mServer i Flexi je umí)
        CURLOPT_WRITEFUNCTION   => function ($c, $chunk) use (&$buf, &$tooBig, $maxBytes) {
            if (strlen($buf) + strlen($chunk) > $maxBytes) { $tooBig = true; return 0; }
            $buf .= $chunk; return strlen($chunk);
        },
        CURLOPT_HEADERFUNCTION  => function ($c, $line) use (&$respHeaders) {
            $parts = explode(':', $line, 2);
            if (count($parts) === 2) $respHeaders[strtolower(trim($parts[0]))] = trim($parts[1]);
            return strlen($line);
        },
    ]);
    if (defined('CURLOPT_PROTOCOLS')) curl_setopt($ch, CURLOPT_PROTOCOLS, CURLPROTO_HTTP | CURLPROTO_HTTPS);
    if ($body !== null) curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
    $okExec = curl_exec($ch);
    $errno = curl_errno($ch);
    $http = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);

    if ($tooBig) return ['ok' => false, 'http' => $http, 'body' => '', 'headers' => $respHeaders, 'error' => 'Odpověď serveru je příliš velká — zkus kratší období.'];
    if ($okExec === false || $errno) {
        return ['ok' => false, 'http' => $http, 'body' => $buf, 'headers' => $respHeaders, 'error' => ucetni_curl_chyba($errno, $chk['host'], (int) ($opt['timeout'] ?? 30))];
    }
    $err = null;
    if ($http >= 300) $err = ucetni_http_chyba($http);
    return ['ok' => $http >= 200 && $http < 300, 'http' => $http, 'body' => $buf, 'headers' => $respHeaders, 'error' => $err];
}

function ucetni_curl_chyba(int $errno, string $host, int $timeout): string {
    switch ($errno) {
        case 6:  return "Server „{$host}\" se nepodařilo najít (DNS). Zkontroluj adresu.";
        case 7:  return "Nelze se připojit k serveru „{$host}\" — server neběží, port je zavřený nebo ho blokuje firewall.";
        case 28: return "Server „{$host}\" neodpověděl včas (limit {$timeout} s). Zkus to znovu nebo zkontroluj, zda server běží.";
        case 35: case 51: case 53: case 54: case 58: case 59: case 60: case 77: case 83: case 90: case 91:
                 return "Zabezpečené spojení se serverem „{$host}\" selhalo (neplatný nebo nedůvěryhodný certifikát HTTPS).";
        case 47: return 'Server odpověděl přesměrováním — zadej přímo konečnou adresu serveru.';
        case 52: return "Server „{$host}\" ukončil spojení bez odpovědi.";
        case 56: return "Spojení se serverem „{$host}\" bylo přerušeno.";
        default: return "Komunikace se serverem „{$host}\" selhala (chyba spojení {$errno}).";
    }
}

function ucetni_http_chyba(int $http): string {
    if ($http >= 300 && $http < 400) return 'Server odpověděl přesměrováním (HTTP ' . $http . ') — zkontroluj adresu (http/https, port).';
    switch ($http) {
        case 400: return 'Server odmítl požadavek jako chybný (HTTP 400).';
        case 401: return 'Přihlášení odmítnuto — špatné uživatelské jméno nebo heslo (HTTP 401).';
        case 403: return 'Uživatel nemá oprávnění k této operaci (HTTP 403).';
        case 404: return 'Adresa nebyla na serveru nalezena (HTTP 404) — zkontroluj adresu serveru / kód firmy.';
        case 405: return 'Server nepodporuje tuto metodu (HTTP 405) — je adresa správná?';
        case 408: return 'Server vypršel časový limit zpracování (HTTP 408).';
        case 429: return 'Server dočasně omezil počet požadavků (HTTP 429) — zkus to za minutu znovu.';
        case 503: return 'Server je dočasně nedostupný (HTTP 503).';
    }
    return 'Server vrátil chybu HTTP ' . $http . '.';
}
