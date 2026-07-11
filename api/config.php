<?php
/**
 * APPEK B2B - konfigurace v2
 *
 * BEZPEČNOST:
 * - Session cookie: httponly, samesite=Lax, secure (na HTTPS)
 * - PDO s utf8mb4 a Europe/Prague timezone
 * - Atomické číslování dokumentů (cislovani helper)
 * - Rate-limit helper pro login endpointy
 *
 * !!! HESLO K DB SI DEJTE DO ENVIRONMENTU NEBO MIMO public_html !!!
 *     Tady je v plain textu jen dočasně. Po nahrání rotujte heslo
 *     v Hostinger panelu a uložte ho do .env mimo webroot.
 */

// 🚀 Auto-loaded config z installeru (install.php) — pokud existuje, používá ho.
// Pro produkci: install.php vytvoří api/config.local.php s tvými přístupy.
// .gitignore: api/config.local.php
if (file_exists(__DIR__ . '/config.local.php')) {
    require_once __DIR__ . '/config.local.php';
} else {
    // 🚑 v3.0.415 SELF-HEAL (incident 2026-07-07): po master deployi v3.0.414 zmizel
    //   api/config.local.php (deploy-hook preserve ho vůbec neviděl — CI log bez
    //   „Preserving: api/config.local.php") → celé API 302 na install.php.
    //   Self-update si před extrakcí VŽDY zálohuje celý api/ do /tmp — pokud tam
    //   config je, obnovíme ho (a k němu i .installed) a jedeme dál bez výpadku.
    $__bk = glob(sys_get_temp_dir() . '/appek-self-update-backup-*/api/config.local.php') ?: [];
    if ($__bk) {
        usort($__bk, fn($a, $b) => (int) @filemtime($b) <=> (int) @filemtime($a));
        if (@copy($__bk[0], __DIR__ . '/config.local.php')) {
            $__instBk = dirname($__bk[0]) . '/.installed';
            if (!file_exists(__DIR__ . '/.installed') && file_exists($__instBk)) {
                @copy($__instBk, __DIR__ . '/.installed');
            }
            @file_put_contents(__DIR__ . '/.selfheal.log',
                date('c') . ' config.local.php obnoven z ' . $__bk[0] . "\n", FILE_APPEND);
            require_once __DIR__ . '/config.local.php';
        }
    }
    unset($__bk);
}

// 🔒 v2.6.0 SECURITY FIX (C6): odstraněno hardcoded production heslo z fallbacku!
//    Předtím "Karkulka55+" plaintext v každém build ZIPu = kdokoliv si přečte source.
//    Nyní: pokud config.local.php není přítomen, install.php donutí admina zadat
//    DB credentials. Žádné production hesla v source code.
if (!defined('DB_HOST'))    define('DB_HOST',    'localhost');
if (!defined('DB_PORT'))    define('DB_PORT',    3306);
if (!defined('DB_NAME'))    define('DB_NAME',    '');  // VYŽADUJE config.local.php
if (!defined('DB_USER'))    define('DB_USER',    '');  // VYŽADUJE config.local.php
if (!defined('DB_PASS'))    define('DB_PASS',    '');  // VYŽADUJE config.local.php (nikdy v source!)
if (!defined('DB_CHARSET')) define('DB_CHARSET', 'utf8mb4');

// 🔒 Pokud chybí DB credentials → redirect na install.php (kromě install.php samotného)
if (DB_NAME === '' || DB_USER === '') {
    $script = $_SERVER['SCRIPT_NAME'] ?? '';
    if (!str_contains($script, 'install.php') && !str_contains($script, 'force-update.php')) {
        // Pokud HTTP request: redirect na install
        if (!headers_sent() && PHP_SAPI !== 'cli') {
            header('Location: /install.php');
            exit;
        }
    }
}

// Aplikace
// 🆕 v3.0.314 — default APP_URL se ODVOZUJE z domény instalace (dřív natvrdo dev staging
//   doména → leakovala do QR/e-mailů/redirectů u každé čisté instalace). Produkce ho
//   stejně přepisuje v config.local.php; tohle je jen bezpečný fallback.
if (!defined('APP_URL')) {
    $__sch = ((!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https'
        || ((int) ($_SERVER['SERVER_PORT'] ?? 0)) === 443) ? 'https' : 'http';
    $__host = $_SERVER['HTTP_HOST'] ?? '';
    define('APP_URL', $__host ? ($__sch . '://' . $__host) : '');
}
define('APP_NAME',    'APPEK B2B');
define('APP_VERSION',    '3.0.425'); // SemVer — bump při release (matches git tag bez 'v')
define('APP_REPO',       'Chossekilla/appek'); // GitHub owner/repo (backup, viz APP_UPDATE_URL)
define('APP_UPDATE_URL', 'https://appek.cz/updates/manifest.json'); // Self-hosted update manifest (primární)
define('UPLOAD_DIR',  __DIR__ . '/../uploads');
define('UPLOAD_URL',  '/uploads');

// Inicializace
date_default_timezone_set('Europe/Prague');
mb_internal_encoding('UTF-8');

// V produkci nezobrazovat chyby uživateli
ini_set('display_errors', '0');
ini_set('log_errors', '1');
error_reporting(E_ALL);

/**
 * PDO s nastavením utf8mb4 a Europe/Prague timezone
 */
function db(): PDO {
    static $pdo = null;
    if ($pdo === null) {
        $dsn = sprintf('mysql:host=%s;dbname=%s;charset=%s', DB_HOST, DB_NAME, DB_CHARSET);
        $pdo = new PDO($dsn, DB_USER, DB_PASS, [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]);
        // Sjednoceni timezone mezi PHP a MySQL
        // 🐛 v3.0.402 — dřív fixní '+01:00' → v létě (CEST +02:00) se SQL NOW()/CURDATE()
        //   rozjelo o hodinu proti PHP date() → doklad v 00:00–00:59 nesl číslo nového dne,
        //   ale datum_objednani spadlo do včerejška (uzávěrka/denní řady mimo). date('P')
        //   vrací aktuální offset Europe/Prague vč. DST; nezávisí na tz tabulkách v MySQL.
        $pdo->exec("SET time_zone = '" . date('P') . "'");

        // 🔄 SYNC FOUNDATION — Phase 1: schéma sync tabulek (idempotentní, OFF by default)
        if (file_exists(__DIR__ . '/_sync_schema.php')) {
            require_once __DIR__ . '/_sync_schema.php';
            if (function_exists('ensure_sync_schema')) ensure_sync_schema($pdo);
        }

        // 🛡️ AUTO-FIX prihlaseni_pokusy (legacy schema měl uspesne/kdy místo uspesny/cas/typ)
        ensure_prihlaseni_pokusy_schema($pdo);

        // 🛡️ FULL SCHEMA ENSURE — konsoliduje všechny CREATE TABLE + ADD COLUMN auto-migrace
        if (file_exists(__DIR__ . '/_full_schema.php')) {
            require_once __DIR__ . '/_full_schema.php';
            if (function_exists('apply_full_schema')) apply_full_schema($pdo);
        }
    }
    return $pdo;
}


/**
 * Idempotentní migrace tabulky prihlaseni_pokusy:
 *   - rename uspesne → uspesny
 *   - rename kdy → cas
 *   - přidat sloupec typ
 * Bezpečné spustit opakovaně; v případě nové instalace nic nedělá.
 */
function ensure_prihlaseni_pokusy_schema(PDO $pdo): void {
    static $done = false;
    if ($done) return;
    $done = true;
    try {
        $cols = $pdo->query("SHOW COLUMNS FROM prihlaseni_pokusy")->fetchAll(PDO::FETCH_COLUMN);
        if (in_array('uspesne', $cols, true) && !in_array('uspesny', $cols, true)) {
            $pdo->exec("ALTER TABLE prihlaseni_pokusy CHANGE COLUMN uspesne uspesny TINYINT(1) NOT NULL DEFAULT 0");
        }
        if (in_array('kdy', $cols, true) && !in_array('cas', $cols, true)) {
            $pdo->exec("ALTER TABLE prihlaseni_pokusy CHANGE COLUMN kdy cas DATETIME DEFAULT CURRENT_TIMESTAMP");
        }
        if (!in_array('typ', $cols, true)) {
            $pdo->exec("ALTER TABLE prihlaseni_pokusy ADD COLUMN typ VARCHAR(30) DEFAULT 'admin' AFTER email");
        }
    } catch (Throwable $e) { /* tabulka může chybět při prvním requestu — installer ji vytvoří */ }
}

/**
 * Bezpečný start session - HttpOnly, SameSite=Lax, Secure na HTTPS
 */
function session_secure_start(): void {
    if (session_status() !== PHP_SESSION_NONE) return;
    $is_https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
              || ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https';
    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => '/',
        'secure'   => $is_https,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_name('APPEKSID');
    session_start();
}

/**
 * Načte všechna nastavení firmy z DB - cachuje se v paměti pro rychlost
 */
function nastaveni(): array {
    static $cache = null;
    if ($cache === null) {
        try {
            $stmt = db()->query("SELECT klic, hodnota FROM nastaveni");
            $cache = [];
            foreach ($stmt->fetchAll() as $row) {
                $cache[$row['klic']] = $row['hodnota'];
            }
        } catch (Exception $e) {
            $cache = [];
        }
    }
    return $cache;
}

/**
 * Získá jednotlivé nastavení firmy (např. firma('nazev') vrátí firma_nazev)
 */
function firma(string $klic, string $default = ''): string {
    $n = nastaveni();
    return $n['firma_' . $klic] ?? $default;
}

/**
 * Atomické přidělení dalšího čísla v řadě.
 * Použije UPDATE ... s SELECT LAST_INSERT_ID(), díky čemuž je to
 * race-safe i bez explicitního zámku tabulky.
 *
 * @param string $typ 'OBJ' | 'DL' | 'FA' | 'VL'
 * @param int    $rok např. 2026
 * @return string formátované číslo, např. 'FA-2026-0042'
 */
function dalsi_cislo(PDO $pdo, string $typ, int $rok): string {
    // Výchozí předčíslí pokud řádek ještě neexistuje
    $vychozi_predcisli = $typ . '-' . $rok . '-';

    // Zajistíme řádek pro daný typ+rok (s výchozím předčíslím)
    $pdo->prepare("INSERT IGNORE INTO cislovani (typ, rok, predcisli, posledni) VALUES (:t, :r, :p, 0)")
        ->execute(['t' => $typ, 'r' => $rok, 'p' => $vychozi_predcisli]);

    // 🐛 fix v2.9.172/173 — pokud historický řádek měl predcisli ve starém formátu
    // (např. "DL" nebo "OBJ" bez roku), upgrade ho na standardní "XX-YYYY-".
    // v2.9.172 použila LIKE s parametrem → collation mismatch (utf8mb4_unicode_ci
    // vs utf8mb4_bin). v2.9.173 fix: načteme predcisli PHP-side a porovnáme.
    $cur = $pdo->prepare("SELECT predcisli FROM cislovani WHERE typ = :t AND rok = :r");
    $cur->execute(['t' => $typ, 'r' => $rok]);
    $cur_predcisli = (string) $cur->fetchColumn();
    $expected_suffix = $rok . '-';
    if ($cur_predcisli !== '' && !str_ends_with($cur_predcisli, $expected_suffix)) {
        // Legacy formát — upgrade.
        $pdo->prepare("UPDATE cislovani SET predcisli = :p WHERE typ = :t AND rok = :r")
            ->execute(['p' => $vychozi_predcisli, 't' => $typ, 'r' => $rok]);
    }

    // Atomické zvýšení - LAST_INSERT_ID(expr) vrátí novou hodnotu
    $pdo->prepare("
        UPDATE cislovani
        SET posledni = LAST_INSERT_ID(posledni + 1)
        WHERE typ = :t AND rok = :r
    ")->execute(['t' => $typ, 'r' => $rok]);

    $next = (int) $pdo->query("SELECT LAST_INSERT_ID()")->fetchColumn();

    // Načti aktuální předčíslí
    $stmt = $pdo->prepare("SELECT predcisli FROM cislovani WHERE typ = :t AND rok = :r");
    $stmt->execute(['t' => $typ, 'r' => $rok]);
    $predcisli = (string) $stmt->fetchColumn();

    // Pokud je sloupec prázdný (např. po migraci), použij výchozí
    if ($predcisli === '') {
        $predcisli = $vychozi_predcisli;
    }

    // 🐛 fix v2.9.172 — formátovat $next na 4 cifry s leading zeros.
    // Předtím "FA-2026-1" / "FA-2026-2" / ... / "FA-2026-1000"; teď "FA-2026-0001".
    // Konzistentní s demo seedem a docstringem ("FA-2026-0042").
    return $predcisli . str_pad((string) $next, 4, '0', STR_PAD_LEFT);
}

/* ────────────────────────────────────────────────────────────────────────
 * 🆕 v3.0.212 — Centrální registr prodejních KANÁLŮ (objednavky.puvod)
 *
 * Jeden zdroj pravdy pro každý balíček, který zapisuje do `objednavky`:
 *   • label    — štítek do UI
 *   • barva    — barva badge
 *   • rada     — vlastní číselná řada (prefix v `cislovani`) → kanály se NEPŘEBÍJEJÍ
 *   • pokladni — počítá se jako pokladní prodej (teče do POS Účtenek/uzávěrky)
 *   • zapnuto  — kanál aktivní
 *
 * Defaulty lze přepsat v `nastaveni` (klic='kanaly_config', JSON) přes admin panel.
 * Sada kanálů je pevná (známé balíčky); panel mění jen label/pokladni/zapnuto.
 * ──────────────────────────────────────────────────────────────────────── */
function kanaly_defaults(): array {
    return [
        'pos'       => ['label' => 'POS pokladna',      'ikona' => '🧾', 'barva' => '#16a34a', 'rada' => 'POS',  'pokladni' => true,  'zapnuto' => true],
        // QR samoobsluha: účtuje se přes dine-in účet → ukládá se jako puvod='pos' (puvod='qr'
        // v objednavky nevzniká). pokladni=false → default pokladní řada je jednohodnotová ('pos'),
        // takže POS reporty jedou přes index, ne full scan (v3.0.214 perf fix).
        'qr'        => ['label' => 'QR samoobsluha',    'ikona' => '📲', 'barva' => '#0891b2', 'rada' => 'POS',  'pokladni' => false, 'zapnuto' => true],
        'b2b'       => ['label' => 'B2B portál',        'ikona' => '🏢', 'barva' => '#2563eb', 'rada' => 'B2B',  'pokladni' => false, 'zapnuto' => true],
        'dort'      => ['label' => 'Dort konfigurátor', 'ikona' => '🎂', 'barva' => '#db2777', 'rada' => 'DORT', 'pokladni' => false, 'zapnuto' => true],
        'catering'  => ['label' => 'Catering kalkulačka','ikona' => '🥗', 'barva' => '#0d9488', 'rada' => 'CAT',  'pokladni' => false, 'zapnuto' => true],
        'recurring' => ['label' => 'Opakované',         'ikona' => '🔁', 'barva' => '#9333ea', 'rada' => 'OPAK', 'pokladni' => false, 'zapnuto' => true],
        'wolt'      => ['label' => 'Wolt',              'ikona' => '🛵', 'barva' => '#00b8a9', 'rada' => 'D-WO', 'pokladni' => false, 'zapnuto' => true],
        'bolt'      => ['label' => 'Bolt Food',         'ikona' => '🛵', 'barva' => '#30d175', 'rada' => 'D-BO', 'pokladni' => false, 'zapnuto' => true],
        'foodora'   => ['label' => 'Foodora',           'ikona' => '🛵', 'barva' => '#d6006e', 'rada' => 'D-FO', 'pokladni' => false, 'zapnuto' => true],
        'interni'   => ['label' => 'Interní / ručně',   'ikona' => '✏️', 'barva' => '#6b7280', 'rada' => 'OBJ',  'pokladni' => false, 'zapnuto' => true],
    ];
}

/** Sloučí defaulty s uloženým override (nastaveni klic='kanaly_config'). */
function kanaly_config(PDO $pdo): array {
    static $cache = null;
    if ($cache !== null) return $cache;
    $cfg = kanaly_defaults();
    try {
        $raw = nastaveni_get($pdo, 'kanaly_config', null);
        if ($raw) {
            $over = json_decode((string) $raw, true);
            if (is_array($over)) {
                foreach ($over as $k => $v) {
                    if (isset($cfg[$k]) && is_array($v)) {
                        // měnitelné jen bezpečné klíče (řadu necháváme z kódu)
                        foreach (['label', 'barva', 'pokladni', 'zapnuto'] as $field) {
                            if (array_key_exists($field, $v)) $cfg[$k][$field] = $v[$field];
                        }
                    }
                }
            }
        }
    } catch (Throwable $e) { /* defaults */ }
    return $cache = $cfg;
}

/** Metadata jednoho kanálu (fallback na neznámý → šedý 'interni' styl). */
function kanal_meta(PDO $pdo, ?string $puvod): array {
    $cfg = kanaly_config($pdo);
    $p = ($puvod !== null && $puvod !== '') ? $puvod : 'interni';
    if (isset($cfg[$p])) return ['klic' => $p] + $cfg[$p];
    return ['klic' => $p, 'label' => ucfirst($p), 'ikona' => '•', 'barva' => '#6b7280', 'rada' => 'OBJ', 'pokladni' => false, 'zapnuto' => true];
}

/** Seznam puvod hodnot, které se počítají jako pokladní prodej (Účtenky/uzávěrka). */
function kanaly_pokladni(PDO $pdo): array {
    $out = [];
    foreach (kanaly_config($pdo) as $k => $v) {
        if (!empty($v['pokladni']) && !empty($v['zapnuto'])) $out[] = $k;
    }
    return $out ?: ['pos'];
}

/** Vlastní číslo dokladu pro daný kanál (proti přebíjení). */
function kanal_dalsi_cislo(PDO $pdo, string $puvod, ?int $rok = null): string {
    ensure_puvod_column($pdo);
    $rada = kanal_meta($pdo, $puvod)['rada'] ?? 'OBJ';
    return dalsi_cislo($pdo, $rada, $rok ?? (int) date('Y'));
}

/** Self-heal: zajistí sloupec objednavky.puvod (+ index) na čerstvé instalaci. */
function ensure_puvod_column(PDO $pdo): void {
    static $done = false;
    if ($done) return;
    $done = true;
    try {
        $has = $pdo->query("SHOW COLUMNS FROM objednavky LIKE 'puvod'")->fetchAll();
        if (!$has) {
            $pdo->exec("ALTER TABLE objednavky ADD COLUMN puvod VARCHAR(20) DEFAULT 'interni' AFTER stav, ADD INDEX idx_puvod (puvod)");
        }
    } catch (Throwable $e) { /* ignore */ }
}

/** Jednorázový backfill: doplní puvod existujícím objednávkám podle markerů v poznámce. */
function kanaly_backfill_once(PDO $pdo): void {
    static $done = false;
    if ($done) return;
    $done = true;
    try {
        if ((string) nastaveni_get($pdo, 'kanaly_backfill_done', '') !== '1') {
            ensure_puvod_column($pdo);
            $pdo->prepare("UPDATE objednavky SET puvod='recurring' WHERE (puvod IS NULL OR puvod IN ('interni','')) AND poznamka LIKE '[Recurring #%'")->execute();
            $pdo->prepare("UPDATE objednavky SET puvod='dort' WHERE (puvod IS NULL OR puvod IN ('interni','')) AND poznamka LIKE '%dort z konfigurátoru%'")->execute();
            $pdo->prepare("INSERT INTO nastaveni (klic, hodnota) VALUES ('kanaly_backfill_done','1') ON DUPLICATE KEY UPDATE hodnota='1'")->execute();
        }
        // 🐛 v3.0.235 — datum_objednani backfill: b2b portál ho dřív neukládal → 0000-00-00,
        //   objednávky pak MIZELY z dashboardu/tržeb/statistik (vše filtruje DATE(datum_objednani)).
        //   Fallback = datum_dodani (nejlepší proxy pro historická data).
        if ((string) nastaveni_get($pdo, 'datum_obj_backfill_done', '') !== '1') {
            $pdo->prepare("UPDATE objednavky SET datum_objednani = datum_dodani
                           WHERE (datum_objednani IS NULL OR datum_objednani = '0000-00-00')
                             AND datum_dodani IS NOT NULL AND datum_dodani <> '0000-00-00'")->execute();
            $pdo->prepare("INSERT INTO nastaveni (klic, hodnota) VALUES ('datum_obj_backfill_done','1') ON DUPLICATE KEY UPDATE hodnota='1'")->execute();
        }
    } catch (Throwable $e) { /* ignore */ }
}

/**
 * Rate-limit pro login endpointy.
 * Vrátí true, pokud daná IP/email překročila limit.
 */
// 🆕 v2.0.76 — Self-heal: auto-create prihlaseni_pokusy table if missing
function _ensure_prihlaseni_table(PDO $pdo): bool {
    static $done = null;
    if ($done !== null) return $done;
    try {
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS prihlaseni_pokusy (
                id INT AUTO_INCREMENT PRIMARY KEY,
                ip VARCHAR(45) NOT NULL,
                email VARCHAR(150),
                typ VARCHAR(30) DEFAULT 'admin',
                uspesny TINYINT(1) NOT NULL DEFAULT 0,
                cas DATETIME DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_ip_cas (ip, cas),
                INDEX idx_typ_cas (typ, cas)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");
        return $done = true;
    } catch (Throwable $e) {
        error_log('prihlaseni_pokusy table create: ' . $e->getMessage());
        return $done = false;
    }
}

function login_rate_limited(string $email, string $ip, string $typ): bool {
    // 🆕 v3.0.98 — Localhost (dev) bypass per user "u mě limit pokusů dej sekundu".
    if (in_array($ip, ['::1', '127.0.0.1', '0.0.0.0', 'localhost'], true)) return false;
    // 🆕 v3.0.113 — DEMO mode: zkrácená okno na 15 SEKUND (user: "zkrátit pokus na 15 vteřin").
    //   Demo customers (demo.appek.cz) testují rychle, 15min lock je frustrující.
    //   Prod (non-demo) zachovává 5 pokusů / 15 min security.
    $isDemo = defined('APPEK_DEMO_MODE') && APPEK_DEMO_MODE;
    $windowExpr = $isDemo ? 'INTERVAL 15 SECOND' : 'INTERVAL 15 MINUTE';
    try {
        $pdo = db();
        _ensure_prihlaseni_table($pdo);
        $stmt = $pdo->prepare("
            SELECT COUNT(*) FROM prihlaseni_pokusy
            WHERE typ = :t AND uspesny = 0
              AND cas > DATE_SUB(NOW(), $windowExpr)
              AND (ip = :ip OR email = :em)
        ");
        $stmt->execute(['t' => $typ, 'ip' => $ip, 'em' => $email]);
        return ((int) $stmt->fetchColumn()) >= 5;
    } catch (Throwable $e) {
        error_log('login_rate_limited: ' . $e->getMessage());
        return false;  // Soft-fail: bez rate-limitingu (lepší než zablokovat login)
    }
}

function login_log(string $email, string $ip, string $typ, bool $uspesny): void {
    try {
        $pdo = db();
        _ensure_prihlaseni_table($pdo);
        $pdo->prepare("
            INSERT INTO prihlaseni_pokusy (email, ip, typ, uspesny)
            VALUES (:em, :ip, :t, :u)
        ")->execute(['em' => $email, 'ip' => $ip, 't' => $typ, 'u' => $uspesny ? 1 : 0]);
    } catch (Throwable $e) {
        error_log('login_log: ' . $e->getMessage());
        // Soft-fail: log failure neblokuje login
    }
}

function client_ip(): string {
    return $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
}

// =============================================================
// JSON / HTTP helpery
// =============================================================
function json_response($data, int $code = 200): void {
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function json_error(string $msg, int $code = 400): void {
    json_response(['error' => $msg], $code);
}

/**
 * 🆕 v2.9.324 — Cron job logging helper.
 *
 * Wrap kolem cron callable: měří duration, zachytí throwable, persistuje do `cron_log` DB.
 * Detekuje opakovaný fail (≥3× v řadě) → emit notif do admin bell.
 *
 * Použití v cron skriptu:
 *   $r = cron_run('recurring_orders', function() use ($pdo) {
 *       $created = generate_recurring($pdo);
 *       return ['ok' => true, 'orders_created' => $created];
 *   });
 *   echo json_encode($r);
 */
function cron_run(string $job, callable $fn): array {
    $t0 = microtime(true);
    $pdo = db();

    // Idempotent schema
    static $schemaReady = false;
    if (!$schemaReady) {
        try {
            $pdo->exec("
                CREATE TABLE IF NOT EXISTS cron_log (
                    id BIGINT AUTO_INCREMENT PRIMARY KEY,
                    job VARCHAR(80) NOT NULL,
                    ok TINYINT(1) NOT NULL DEFAULT 0,
                    duration_ms INT NOT NULL DEFAULT 0,
                    result_json TEXT NULL,
                    error_msg TEXT NULL,
                    started_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                    INDEX idx_job_started (job, started_at),
                    INDEX idx_started (started_at)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
            ");
            $schemaReady = true;
        } catch (\Throwable $ig) { /* fall through */ }
    }

    $resultJson = null;
    $errorMsg = null;
    $ok = false;
    try {
        $result = $fn();
        $ok = is_array($result) ? ($result['ok'] ?? true) : (bool) $result;
        $resultJson = is_array($result) ? json_encode($result, JSON_UNESCAPED_UNICODE) : null;
    } catch (\Throwable $e) {
        $errorMsg = $e->getMessage();
        try { app_log_error('cron_' . bin2hex(random_bytes(3)), 'Cron: ' . $job, $e, 500, 'error'); }
        catch (\Throwable $ig) {}
    }
    $duration = (int) round((microtime(true) - $t0) * 1000);

    // Persist log
    try {
        $pdo->prepare("
            INSERT INTO cron_log (job, ok, duration_ms, result_json, error_msg)
            VALUES (:j, :o, :d, :r, :e)
        ")->execute([
            'j' => substr($job, 0, 80),
            'o' => $ok ? 1 : 0,
            'd' => $duration,
            'r' => $resultJson ? substr($resultJson, 0, 8000) : null,
            'e' => $errorMsg ? substr($errorMsg, 0, 2000) : null,
        ]);
        // Auto-prune > 90 dní (1% sample)
        if (mt_rand(1, 100) === 1) {
            try { $pdo->exec("DELETE FROM cron_log WHERE started_at < DATE_SUB(NOW(), INTERVAL 90 DAY) LIMIT 1000"); }
            catch (\Throwable $ig) {}
        }
    } catch (\Throwable $ig) {}

    // Detect repeat fail (≥3× v řadě)
    if (!$ok) {
        try {
            $recent = $pdo->prepare("
                SELECT ok FROM cron_log WHERE job = :j ORDER BY started_at DESC LIMIT 3
            ");
            $recent->execute(['j' => $job]);
            $last3 = $recent->fetchAll(PDO::FETCH_COLUMN);
            if (count($last3) === 3 && array_sum(array_map('intval', $last3)) === 0) {
                if (function_exists('notif_emit')) {
                    notif_emit($pdo, 'cron_failing',
                        "🔁 Cron \"$job\" selhal 3× v řadě",
                        'Otevři Diagnostiku → Cron log pro detail.',
                        '#/nastaveni', 'error',
                        'cron_fail_' . $job . '_' . date('Ymd'));
                }
            }
        } catch (\Throwable $ig) {}
    }

    return ['ok' => $ok, 'duration_ms' => $duration, 'result' => $resultJson, 'error' => $errorMsg];
}

/**
 * 🆕 v2.9.315 — Safe error wrapper.
 *
 * Vždy zaloguje plný exception trace do error_log (server-side visibility).
 * Klientovi vrátí: v dev/demo režimu plnou hlášku, v produkci jen generickou
 * + request_id pro debugging (admin si ho najde v error logu).
 *
 * Předtím se `json_error('Chyba X: ' . $e->getMessage(), 500)` v produkci
 * exponovalo SQL syntax / table names / FK constraint detaily klientovi
 * (information disclosure — pomohlo by útočníkovi při fingerprintingu schématu).
 */
function json_error_safe(string $publicMsg, ?\Throwable $e = null, int $code = 500): void {
    $reqId = bin2hex(random_bytes(4));
    if ($e) {
        error_log(sprintf('[APPEK][%s] %s: %s @ %s:%d', $reqId, $publicMsg, $e->getMessage(), $e->getFile(), $e->getLine()));
    }
    // 🆕 v2.9.321 — persistuj i do app_errors DB tabulky (= admin v Diagnostice najde podle reqId)
    try { app_log_error($reqId, $publicMsg, $e, $code); } catch (\Throwable $ignore) { /* never fail caller */ }
    $isDev = (defined('APP_DEBUG') && APP_DEBUG === true)
          || (defined('APPEK_DEMO') && APPEK_DEMO === true)
          || (php_sapi_name() === 'cli-server'); // dev server
    $out = ['error' => $publicMsg, 'request_id' => $reqId];
    if ($isDev && $e) $out['debug'] = $e->getMessage();
    json_response($out, $code);
}

/**
 * 🆕 v2.9.321 — Centrální app-level error log.
 *
 * Persistuje každou chybu do `app_errors` (request_id, severity, source, message,
 * exception, user, http_status, created_at) — admin pak v Diagnostice najde
 * konkrétní chybu podle request_id z UI errors místo grepování v raw PHP logu.
 *
 * Auto-create schéma (idempotentní), auto-prune >30 dnů (sample 1/100 requests).
 * Defenzivní: každá chyba uvnitř téhle funkce je SWALLOWED — nesmí rozbít caller.
 */
function app_log_error(string $reqId, string $publicMsg, ?\Throwable $e = null, int $httpCode = 500, string $severity = 'error'): void {
    try {
        $pdo = db();
        // Idempotent schema (cached static flag — jen 1× per process)
        static $schemaReady = false;
        if (!$schemaReady) {
            try {
                $pdo->exec("
                    CREATE TABLE IF NOT EXISTS app_errors (
                        id BIGINT AUTO_INCREMENT PRIMARY KEY,
                        request_id VARCHAR(16) NOT NULL,
                        severity VARCHAR(10) NOT NULL DEFAULT 'error',
                        http_status SMALLINT NOT NULL DEFAULT 500,
                        source VARCHAR(120) NULL,
                        message VARCHAR(255) NOT NULL,
                        exception_class VARCHAR(120) NULL,
                        exception_msg TEXT NULL,
                        exception_file VARCHAR(255) NULL,
                        exception_line INT NULL,
                        exception_trace TEXT NULL,
                        user_email VARCHAR(120) NULL,
                        user_role VARCHAR(20) NULL,
                        ip VARCHAR(45) NULL,
                        user_agent VARCHAR(255) NULL,
                        url VARCHAR(500) NULL,
                        method VARCHAR(10) NULL,
                        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                        INDEX idx_req (request_id),
                        INDEX idx_sev_created (severity, created_at),
                        INDEX idx_created (created_at)
                    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
                ");
                $schemaReady = true;
            } catch (\Throwable $ig) { /* if no DB, swallow */ return; }
        }

        // Resolve user context (best effort)
        $userEmail = null;
        $userRole = null;
        try {
            if (session_status() === PHP_SESSION_ACTIVE || isset($_SESSION)) {
                $admin = $_SESSION['admin_user'] ?? null;
                if (is_array($admin)) {
                    $userEmail = $admin['email'] ?? $admin['login'] ?? null;
                    $userRole  = $admin['role'] ?? null;
                }
            }
        } catch (\Throwable $ig) {}

        $source = $_SERVER['SCRIPT_NAME'] ?? null;
        if ($source) $source = substr(basename($source), 0, 120);
        $url = $_SERVER['REQUEST_URI'] ?? null;
        $method = $_SERVER['REQUEST_METHOD'] ?? null;
        $ip = $_SERVER['REMOTE_ADDR'] ?? null;
        $ua = $_SERVER['HTTP_USER_AGENT'] ?? null;

        $excClass = $excMsg = $excFile = $excTrace = null;
        $excLine = null;
        if ($e) {
            $excClass = get_class($e);
            $excMsg   = $e->getMessage();
            $excFile  = $e->getFile();
            $excLine  = $e->getLine();
            // Trace cap at 4 KB (defense vs huge stacks pumping disk)
            $excTrace = substr($e->getTraceAsString(), 0, 4096);
        }

        $pdo->prepare("
            INSERT INTO app_errors
                (request_id, severity, http_status, source, message,
                 exception_class, exception_msg, exception_file, exception_line, exception_trace,
                 user_email, user_role, ip, user_agent, url, method)
            VALUES
                (:rid, :sev, :http, :src, :msg,
                 :ec, :em, :ef, :el, :et,
                 :ue, :ur, :ip, :ua, :url, :mtd)
        ")->execute([
            'rid' => substr($reqId, 0, 16),
            'sev' => substr($severity, 0, 10),
            'http' => $httpCode,
            'src' => $source,
            'msg' => substr($publicMsg, 0, 255),
            'ec'  => $excClass ? substr($excClass, 0, 120) : null,
            'em'  => $excMsg,
            'ef'  => $excFile ? substr($excFile, 0, 255) : null,
            'el'  => $excLine,
            'et'  => $excTrace,
            'ue'  => $userEmail ? substr($userEmail, 0, 120) : null,
            'ur'  => $userRole ? substr($userRole, 0, 20) : null,
            'ip'  => $ip ? substr($ip, 0, 45) : null,
            'ua'  => $ua ? substr($ua, 0, 255) : null,
            'url' => $url ? substr($url, 0, 500) : null,
            'mtd' => $method ? substr($method, 0, 10) : null,
        ]);

        // Auto-prune: 1% sample — smaž >30 dnů staré, max 1000 řádků/run
        if (mt_rand(1, 100) === 1) {
            try { $pdo->exec("DELETE FROM app_errors WHERE created_at < DATE_SUB(NOW(), INTERVAL 30 DAY) LIMIT 1000"); }
            catch (\Throwable $ig) {}
        }
    } catch (\Throwable $e2) {
        // Last resort: don't ever throw from logger
        error_log('[app_log_error] swallowed: ' . $e2->getMessage());
    }
}

function json_input(): array {
    $body = file_get_contents('php://input');
    $data = json_decode($body, true);
    return is_array($data) ? $data : [];
}

/**
 * Vyžaduje přihlášeného odběratele.
 */
function require_odberatel(): int {
    session_secure_start();
    if (empty($_SESSION['odberatel_id'])) json_error('Vyžadováno přihlášení', 401);
    return (int) $_SESSION['odberatel_id'];
}

/**
 * Vrátí true pokud místo dodání patří danému odběrateli.
 * Ochrana proti tomu, aby zákazník A objednával na adresu zákazníka B.
 */
function misto_patri_odberateli(PDO $pdo, ?int $misto_id, int $odberatel_id): bool {
    if (!$misto_id) return true; // null = žádné místo, OK
    $stmt = $pdo->prepare("SELECT 1 FROM mista_dodani WHERE id = :m AND odberatel_id = :o");
    $stmt->execute(['m' => $misto_id, 'o' => $odberatel_id]);
    return (bool) $stmt->fetchColumn();
}

/**
 * Vrátí ceník pro daného odběratele s aplikovanými slevami z cenové skupiny.
 *
 * Pravidla:
 *   - Pokud má skupina pravidlo na konkrétní výrobek → použije se ono
 *   - Jinak pokud má pravidlo na kategorii výrobku → použije se ono
 *   - Pravidlo může být sleva v % NEBO pevná cena
 *   - Jinak zůstane základní cena z výrobku
 *
 * @return array Pole asociativních polí: id, cena_zakladni, cena_bez_dph, sleva_pct,
 *               pevna_cena, sazba_dph, jednotka, kategorie_id, ...
 */
function cenik_pro_odberatele(PDO $pdo, int $odberatel_id): array {
    require_once __DIR__ . '/_seasonal_lib.php'; // 🍰 v3.0.331 — sezónní úprava ceny

    // Najít skupinu odběratele
    $stmt = $pdo->prepare("SELECT cenova_skupina_id FROM odberatele WHERE id = :id");
    $stmt->execute(['id' => $odberatel_id]);
    $skupina_id = $stmt->fetchColumn();

    // Načti všechny aktivní výrobky se základními informacemi
    $vyrobky = $pdo->query("
        SELECT v.id, v.cislo, v.nazev, v.cena_bez_dph AS cena_zakladni,
               v.kategorie_id, v.hmotnost_g, v.min_objednavka, v.sezona,
               v.alergeny, v.slozeni, v.nutricni_hodnoty, -- 🆕 v3.0.224 — food-info do B2B (alergeny legálně!)
               j.kod AS jednotka,
               s.sazba AS dph,
               k.nazev AS kategorie_nazev, k.ikona AS kategorie_ikona
        FROM vyrobky v
        JOIN jednotky j ON j.id = v.jednotka_id
        JOIN sazby_dph s ON s.id = v.sazba_dph_id
        LEFT JOIN kategorie_vyrobku k ON k.id = v.kategorie_id
        WHERE v.aktivni = 1
        ORDER BY k.poradi, v.poradi, v.nazev
    ")->fetchAll();

    if (!$skupina_id) {
        // Bez skupiny - vrátíme jen základní ceny (+ sezónní úprava)
        foreach ($vyrobky as &$v) {
            $adj = seasonal_adjust_price($pdo, (float) $v['cena_zakladni'], $v['sezona'] ?? null);
            $v['cena_bez_dph']     = $adj['cena'];
            $v['sleva_pct']        = null;
            $v['pevna_cena']       = null;
            $v['cena_skupina']     = null;
            $v['sezona_sleva_pct'] = $adj['pct'];
        }
        unset($v);
        return $vyrobky;
    }

    // Načti globální nastavení skupiny (sleva, minimum, splatnost)
    $stmt = $pdo->prepare("SELECT globalni_sleva_pct FROM cenove_skupiny WHERE id = :id");
    $stmt->execute(['id' => $skupina_id]);
    $globalni_sleva = $stmt->fetchColumn();
    $globalni_sleva = $globalni_sleva !== false && $globalni_sleva !== null ? (float) $globalni_sleva : null;

    // Načti všechna pravidla této skupiny
    $stmt = $pdo->prepare("
        SELECT kategorie_id, vyrobek_id, sleva_pct, pevna_cena
        FROM cenove_skupiny_slevy
        WHERE skupina_id = :s
    ");
    $stmt->execute(['s' => $skupina_id]);
    $pravidla = $stmt->fetchAll();

    // Rozděl pravidla na index podle vyrobek_id a kategorie_id, plus sortiment-wide fallback
    $idx_vyrobek   = [];
    $idx_kategorie = [];
    $idx_sortiment = null;  // sleva na celý sortiment (vyrobek_id = NULL AND kategorie_id = NULL)
    foreach ($pravidla as $p) {
        if ($p['vyrobek_id']) {
            $idx_vyrobek[(int) $p['vyrobek_id']] = $p;
        } elseif ($p['kategorie_id']) {
            $idx_kategorie[(int) $p['kategorie_id']] = $p;
        } else {
            // Pravidlo bez vyrobek_id i kategorie_id = sleva na celý sortiment
            $idx_sortiment = $p;
        }
    }

    // 🆕 v3.0.334 — mapa subkategorie → hlavní kategorie (kaskáda slev: sub zdědí pravidlo hlavní)
    $katParent = [];
    try {
        foreach ($pdo->query("SELECT id, parent_id FROM kategorie_vyrobku WHERE parent_id IS NOT NULL") as $kr) {
            $katParent[(int) $kr['id']] = (int) $kr['parent_id'];
        }
    } catch (Throwable $e) {}

    // Aplikuj na každý výrobek
    // Priorita: per-vyrobek > per-kategorie > parent-kategorie (kaskáda) > sortiment rule > globalni_sleva_pct > základní cena
    foreach ($vyrobky as &$v) {
        $vid    = (int) $v['id'];
        $katid  = (int) $v['kategorie_id'];
        $base   = (float) $v['cena_zakladni'];
        $rule   = $idx_vyrobek[$vid] ?? $idx_kategorie[$katid]
                  ?? (isset($katParent[$katid]) ? ($idx_kategorie[$katParent[$katid]] ?? null) : null)
                  ?? $idx_sortiment ?? null;

        if ($rule) {
            if ($rule['pevna_cena'] !== null) {
                $v['cena_bez_dph'] = (float) $rule['pevna_cena'];
                $v['pevna_cena']   = (float) $rule['pevna_cena'];
                $v['sleva_pct']    = null;
            } else {
                $sleva = (float) $rule['sleva_pct'];
                $v['cena_bez_dph'] = round($base * (1 - $sleva / 100), 2);
                $v['sleva_pct']    = $sleva;
                $v['pevna_cena']   = null;
            }
        } elseif ($globalni_sleva !== null && $globalni_sleva > 0) {
            // 💰 Globální sleva ceníku (fallback když není specifické pravidlo)
            $v['cena_bez_dph'] = round($base * (1 - $globalni_sleva / 100), 2);
            $v['sleva_pct']    = $globalni_sleva;
            $v['pevna_cena']   = null;
        } else {
            $v['cena_bez_dph']   = $base;
            $v['sleva_pct']      = null;
            $v['pevna_cena']     = null;
        }
        // 🍰 sezónní úprava ceny (aplikuje se na cenu PO slevě skupiny/ceníku)
        $adj = seasonal_adjust_price($pdo, (float) $v['cena_bez_dph'], $v['sezona'] ?? null);
        $v['cena_bez_dph']     = $adj['cena'];
        $v['sezona_sleva_pct'] = $adj['pct'];
    }
    unset($v);
    return $vyrobky;
}

function cors_headers(): void {
    header('Access-Control-Allow-Origin: ' . APP_URL);
    header('Access-Control-Allow-Credentials: true');
    header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
    header('Access-Control-Allow-Headers: Content-Type, X-Requested-With');
    header('Vary: Origin');
    if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') exit;
}

// =============================================================
// Formátovací helpery (pro PDF/HTML šablony)
// =============================================================
function esc($s) {
    return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
}

function fmt_kc($n) {
    return number_format($n, 2, ',', ' ') . ' Kč';
}

function fmt_ks($n) {
    return number_format($n, 0, ',', ' ');
}

function fmt_date($s) {
    return $s ? date('j. n. Y', strtotime($s)) : '';
}

/**
 * Načte hodnotu z tabulky nastaveni nebo vrátí default.
 */
function nastaveni_get(PDO $pdo, string $klic, $default = null) {
    static $cache = null;
    if ($cache === null) {
        $cache = [];
        try {
            $rows = $pdo->query("SELECT klic, hodnota FROM nastaveni")->fetchAll();
            foreach ($rows as $r) {
                $cache[$r['klic']] = $r['hodnota'];
            }
        } catch (Exception $e) {
            // ignoruj
        }
    }
    return $cache[$klic] ?? $default;
}

/**
 * Vrací true, pokud lze objednávku upravovat odběratelem.
 *
 * Pravidla:
 *  1. Objednávka NESMÍ mít vystavený dodací list (objednavka_zamcena)
 *  2. Objednávka NESMÍ být zrušena
 *  3. Aktuální čas musí být PŘED uzávěrkou:
 *     uzávěrka = (datum_dodani - uzaverka_dni_predem dní) v uzaverka_hodina:00
 *
 * Vrací array: ['lze' => bool, 'duvod' => string, 'uzaverka' => DateTime|null]
 */
function objednavka_editovatelna(PDO $pdo, array $obj): array {
    if ($obj['stav'] === 'zrusena') {
        return ['lze' => false, 'duvod' => 'Objednávka je zrušená.', 'uzaverka' => null];
    }

    $stmt = $pdo->prepare("SELECT COUNT(*) FROM dodaci_listy WHERE objednavka_id = :id");
    $stmt->execute(['id' => $obj['id']]);
    if ((int) $stmt->fetchColumn() > 0) {
        return ['lze' => false, 'duvod' => 'Objednávka je již ve výrobě (vystaven dodací list).', 'uzaverka' => null];
    }

    if (empty($obj['datum_dodani'])) {
        // Bez data dodání nelze určit uzávěrku, povol úpravu
        return ['lze' => true, 'duvod' => '', 'uzaverka' => null];
    }

    $hodina    = (int) nastaveni_get($pdo, 'uzaverka_hodina', '18');
    $dniPredem = (int) nastaveni_get($pdo, 'uzaverka_dni_predem', '1');

    try {
        $datum = new DateTime($obj['datum_dodani']);
    } catch (Exception $e) {
        return ['lze' => true, 'duvod' => '', 'uzaverka' => null];
    }
    $uzaverka = clone $datum;
    $uzaverka->modify("-{$dniPredem} day");
    $uzaverka->setTime($hodina, 0, 0);

    $ted = new DateTime();
    if ($ted >= $uzaverka) {
        return [
            'lze'      => false,
            'duvod'    => 'Uplynula uzávěrka úprav (' . $uzaverka->format('j. n. Y H:i') . ').',
            'uzaverka' => $uzaverka,
        ];
    }
    return ['lze' => true, 'duvod' => '', 'uzaverka' => $uzaverka];
}

/**
 * Zaznamená změnu objednávky do logu.
 */
function log_zmena_objednavky(
    PDO $pdo,
    int $objednavka_id,
    string $kdo_typ,
    int $kdo_id,
    string $kdo_jmeno,
    string $akce,
    $detail = null
): void {
    try {
        $stmt = $pdo->prepare("
            INSERT INTO objednavky_zmeny
                (objednavka_id, kdo_typ, kdo_id, kdo_jmeno, akce, detail)
            VALUES (:o, :kt, :ki, :kj, :a, :d)
        ");
        $stmt->execute([
            'o'  => $objednavka_id,
            'kt' => $kdo_typ,
            'ki' => $kdo_id,
            'kj' => $kdo_jmeno,
            'a'  => $akce,
            'd'  => $detail !== null ? json_encode($detail, JSON_UNESCAPED_UNICODE) : null,
        ]);
    } catch (Exception $e) {
        error_log('log_zmena_objednavky: ' . $e->getMessage());
    }
}

/**
 * Pošle email s notifikací o změně objednávky.
 * Pokusí se odeslat přes mail(); pokud nelze, jen zapíše do error_logu.
 *
 * @param array $emailKomu  Pole emailových adres
 * @param string $predmet
 * @param string $telo       Plain-text tělo
 */
function poslat_email(array $emailKomu, string $predmet, string $telo, string $format = 'auto'): bool {
    $emailKomu = array_filter(array_map('trim', $emailKomu));
    if (empty($emailKomu)) return false;

    // Z hlavičky 'firma_email' z nastavení
    $pdo = db();
    $from = nastaveni_get($pdo, 'firma_email', '') ?: 'noreply@' . ($_SERVER['HTTP_HOST'] ?? 'localhost');
    $fromName = nastaveni_get($pdo, 'firma_nazev', '') ?: 'Provoz';

    // Auto-detekce HTML (obsahuje tagy?) — pokud format='auto'
    $isHtml = false;
    if ($format === 'html') $isHtml = true;
    elseif ($format === 'auto') $isHtml = (bool) preg_match('/<(html|body|div|table|p|h[1-6]|a|img|span|strong|b|em|i|br|ul|ol|li|table|tr|td)\b/i', $telo);

    $headers = [];
    $headers[] = 'MIME-Version: 1.0';
    $headers[] = 'Content-Type: ' . ($isHtml ? 'text/html' : 'text/plain') . '; charset=UTF-8';
    $headers[] = 'From: ' . mb_encode_mimeheader($fromName, 'UTF-8') . ' <' . $from . '>';
    $headers[] = 'Reply-To: ' . $from;
    $headers[] = 'X-Mailer: PHP/' . phpversion();

    // U HTML obal do plnohodnotného <html> dokumentu pokud chybí (Gmail/Outlook lépe renderují)
    if ($isHtml && !preg_match('/<html\b/i', $telo)) {
        $telo = "<!DOCTYPE html>\n<html lang=\"cs\"><head><meta charset=\"UTF-8\"><meta name=\"viewport\" content=\"width=device-width,initial-scale=1\"><title>" . htmlspecialchars($predmet) . "</title></head><body>\n" . $telo . "\n</body></html>";
    }

    $predmet_enc = '=?UTF-8?B?' . base64_encode($predmet) . '?=';
    $ok_count = 0;
    foreach ($emailKomu as $komu) {
        if (filter_var($komu, FILTER_VALIDATE_EMAIL) === false) continue;
        $ok = appek_mail_raw($komu, $predmet_enc, $telo, implode("\r\n", $headers));
        if ($ok) $ok_count++;
        else error_log("Nepodařilo se odeslat email na: $komu");
    }
    return $ok_count > 0;
}

/**
 * Pošle notifikační email odběrateli + pekárně po úpravě/zrušení objednávky.
 *
 * @param array $orig  Původní řádek objednávky (před změnou) — použije se pro číslo, datum a kdo
 * @param array $info  ['castka' => float, 'diff' => array, 'kdo' => string, 'zruseno' => bool?]
 */
function notifikace_zmena_objednavky(PDO $pdo, int $obj_id, array $orig, array $info): void {
    // Email odběratele - oba emaily (login + business)
    $stmt = $pdo->prepare("SELECT email, login_email, nazev FROM odberatele WHERE id = :id");
    $stmt->execute(['id' => (int) $orig['odberatel_id']]);
    $odb = $stmt->fetch();
    $emaily_odb = array_filter([
        $odb['login_email'] ?? null,
        $odb['email'] ?? null,
    ]);

    // Email pekárny - admin_email_pro_objednavky (může být čárkou oddělené více adres)
    $emaily_pekarny_raw = nastaveni_get($pdo, 'admin_email_pro_objednavky', '')
        ?: nastaveni_get($pdo, 'firma_email', '');
    $emaily_pekarny = [];
    if ($emaily_pekarny_raw) {
        foreach (preg_split('/[,;]/', $emaily_pekarny_raw) as $e) {
            $e = trim($e);
            if ($e !== '') $emaily_pekarny[] = $e;
        }
    }

    $vsechny_emaily = array_unique(array_merge($emaily_odb, $emaily_pekarny));
    if (empty($vsechny_emaily)) return;

    $cislo  = $orig['cislo'];
    $datum  = fmt_date($orig['datum_dodani']);
    $kdo    = $info['kdo'] ?? 'Odběratel';
    $firma  = nastaveni_get($pdo, 'firma_nazev', 'Provoz');

    if (!empty($info['zruseno'])) {
        $predmet = "Objednávka #$cislo byla ZRUŠENA";
        $telo  = "Vážení,\n\n";
        $telo .= "objednávka #$cislo (datum dodání $datum) byla zrušena.\n";
        $telo .= "Změnu provedl: $kdo\n\n";
        $telo .= "Pokud k tomu došlo omylem, vytvořte prosím novou objednávku.\n\n";
        $telo .= "S pozdravem\n$firma";
    } else {
        $predmet = "Objednávka #$cislo byla UPRAVENA";
        $telo  = "Vážení,\n\n";
        $telo .= "v objednávce #$cislo (datum dodání $datum) byly provedeny tyto změny:\n";
        $telo .= "Změnu provedl: $kdo\n";
        $telo .= str_repeat('-', 50) . "\n\n";

        $diff = $info['diff'] ?? [];
        if (!empty($diff['pridane'])) {
            $telo .= "PŘIDÁNO:\n";
            foreach ($diff['pridane'] as $r) {
                $telo .= "  + " . $r['nazev'] . ': ' . rtrim(rtrim(number_format($r['mnozstvi'], 2, ',', ' '), '0'), ',') . " ks\n";
            }
            $telo .= "\n";
        }
        if (!empty($diff['zmenene'])) {
            $telo .= "ZMĚNĚNO:\n";
            foreach ($diff['zmenene'] as $r) {
                $telo .= "  ~ " . $r['nazev'] . ': '
                      . rtrim(rtrim(number_format($r['puvodne'], 2, ',', ' '), '0'), ',') . " ks → "
                      . rtrim(rtrim(number_format($r['nove'], 2, ',', ' '), '0'), ',') . " ks\n";
            }
            $telo .= "\n";
        }
        if (!empty($diff['odebrane'])) {
            $telo .= "ODEBRÁNO:\n";
            foreach ($diff['odebrane'] as $r) {
                $telo .= "  - " . $r['nazev'] . ': ' . rtrim(rtrim(number_format($r['mnozstvi'], 2, ',', ' '), '0'), ',') . " ks\n";
            }
            $telo .= "\n";
        }
        $telo .= str_repeat('-', 50) . "\n";
        $telo .= "Původní částka: " . fmt_kc($orig['castka_celkem']) . "\n";
        if (isset($info['castka'])) {
            $telo .= "Nová částka:    " . fmt_kc($info['castka']) . "\n";
        }
        $telo .= "\nS pozdravem\n$firma";
    }

    poslat_email($vsechny_emaily, $predmet, $telo);
}

/**
 * Helpery pro notifikace
 */
function notif_email_odberatele(PDO $pdo, int $odb_id): array {
    $stmt = $pdo->prepare("SELECT email, login_email, IFNULL(notif_emaily, 1) AS notif_emaily FROM odberatele WHERE id = :id");
    $stmt->execute(['id' => $odb_id]);
    $odb = $stmt->fetch();
    if (!$odb) return [];
    if ((int) $odb['notif_emaily'] !== 1) return []; // odběratel si přeje neposílat
    return array_values(array_unique(array_filter([
        $odb['login_email'] ?? null,
        $odb['email'] ?? null,
    ])));
}

function notif_zapnuto(PDO $pdo, string $klic, $default = '1'): bool {
    return (string) nastaveni_get($pdo, $klic, $default) === '1';
}

/**
 * 📱 Pošle PWA push notifikaci odběrateli (paralelně s emailem).
 * Selhání tichá — push je jen "bonus", e-mail je primární kanál.
 */
function notif_push_odberateli(PDO $pdo, int $odberatel_id, array $payload): void {
    try {
        require_once __DIR__ . '/_push_lib.php';
        ensure_push_tables($pdo);
        $stmt = $pdo->prepare("SELECT * FROM push_subscriptions WHERE odberatel_id = :o");
        $stmt->execute(['o' => $odberatel_id]);
        $subs = $stmt->fetchAll(PDO::FETCH_ASSOC);
        foreach ($subs as $sub) {
            $r = push_send($pdo, $sub, $payload);
            if ($r['ok']) {
                $pdo->prepare("UPDATE push_subscriptions SET posledni_push = NOW(), chyba_count = 0 WHERE id = :id")
                    ->execute(['id' => $sub['id']]);
                $pdo->prepare("INSERT INTO push_log (subscription_id, title, body, typ, stav) VALUES (:s, :t, :b, :tp, 'sent')")
                    ->execute([
                        's' => $sub['id'],
                        't' => substr($payload['title'] ?? '', 0, 150),
                        'b' => substr($payload['body'] ?? '', 0, 500),
                        'tp' => $payload['typ'] ?? 'objednavka',
                    ]);
            } else {
                if (in_array($r['http_code'], [404, 410], true)) {
                    // Subscription expired — smaž
                    $pdo->prepare("DELETE FROM push_subscriptions WHERE id = :id")->execute(['id' => $sub['id']]);
                } else {
                    $pdo->prepare("UPDATE push_subscriptions SET chyba_count = chyba_count + 1, posledni_chyba = :c WHERE id = :id")
                        ->execute(['c' => substr($r['chyba'] ?? '', 0, 1000), 'id' => $sub['id']]);
                }
            }
        }
    } catch (Throwable $e) {
        error_log('notif_push_odberateli: ' . $e->getMessage());
    }
}

// =============================================================
// 📝 EMAIL TEMPLATES — DB tabulka + render s {proměnné}
// =============================================================
function ensure_email_templates_table(PDO $pdo): void {
    static $done = false;
    if ($done) return;
    $done = true;
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS email_templates (
            klic       VARCHAR(80) PRIMARY KEY,
            predmet    VARCHAR(255) NOT NULL,
            telo       MEDIUMTEXT NOT NULL,
            format     VARCHAR(10) NOT NULL DEFAULT 'text',
            popis      VARCHAR(255) DEFAULT NULL,
            aktivni    TINYINT(1) NOT NULL DEFAULT 1,
            upraveno   DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
    // Migrace existující tabulky — doplň sloupec format
    try {
        $cols = $pdo->query("
            SELECT COLUMN_NAME FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'email_templates'
        ")->fetchAll(PDO::FETCH_COLUMN);
        if (!in_array('format', $cols, true)) {
            $pdo->exec("ALTER TABLE email_templates ADD COLUMN format VARCHAR(10) NOT NULL DEFAULT 'text' AFTER telo");
        }
        // Z TEXT na MEDIUMTEXT (HTML šablony můžou být větší)
        $pdo->exec("ALTER TABLE email_templates MODIFY telo MEDIUMTEXT NOT NULL");
    } catch (Throwable $e) { /* ignore */ }
}

/**
 * Výchozí texty e-mailových šablon — fallback když není v DB.
 * Proměnné: {firma}, {cislo}, {datum}, {misto}, {odberatel}, {polozky_text},
 *           {castka_bez_dph}, {castka_dph}, {castka_celkem}, {poznamka}, {stav}.
 */
function email_template_defaults(): array {
    return [
        'objednavka_nova' => [
            'popis'   => '📥 Potvrzení nové objednávky (odběrateli)',
            'predmet' => 'Potvrzení objednávky #{cislo} (dodání {datum})',
            'telo'    => "Vážení,\n\npotvrzujeme přijetí Vaší objednávky #{cislo}.\n\n"
                       . "--------------------------------------------------\n"
                       . "Datum dodání:  {datum}\n"
                       . "Místo dodání:  {misto}\n"
                       . "--------------------------------------------------\n\n"
                       . "POLOŽKY:\n{polozky_text}\n"
                       . "--------------------------------------------------\n"
                       . "Celkem bez DPH:  {castka_bez_dph}\n"
                       . "DPH:             {castka_dph}\n"
                       . "Celkem s DPH:    {castka_celkem}\n"
                       . "{poznamka_block}\n"
                       . "Děkujeme za Vaši objednávku.\n\nS pozdravem\n{firma}",
        ],
        'objednavka_potvrzena' => [
            'popis'   => '✓ Objednávka potvrzena',
            'predmet' => 'Objednávka #{cislo} — potvrzena',
            'telo'    => "Vážení,\n\nVaši objednávku #{cislo} (datum dodání {datum}) jsme potvrdili.\n\n"
                       . "Pokud potřebujete změnu, kontaktujte nás co nejdříve.\n\nS pozdravem\n{firma}",
        ],
        'objednavka_ve_vyrobe' => [
            'popis'   => '🔥 Objednávka ve výrobě',
            'predmet' => 'Objednávka #{cislo} — ve výrobě',
            'telo'    => "Vážení,\n\nVaši objednávku #{cislo} (datum dodání {datum}) právě vyrábíme.\n\n"
                       . "Brzy ji dostanete připravenou.\n\nS pozdravem\n{firma}",
        ],
        'objednavka_pripravena' => [
            'popis'   => '📦 Připravena k expedici',
            'predmet' => 'Objednávka #{cislo} — připravena',
            'telo'    => "Vážení,\n\nVaše objednávka #{cislo} je připravena k expedici.\n\n"
                       . "Datum dodání: {datum}\n\nS pozdravem\n{firma}",
        ],
        'objednavka_expedovana' => [
            'popis'   => '🚚 Objednávka expedována (na cestě)',
            'predmet' => 'Objednávka #{cislo} — EXPEDOVÁNA',
            'telo'    => "Vážení,\n\nVaše objednávka #{cislo} (datum dodání {datum}) byla expedována a je na cestě k Vám.\n\n"
                       . "Celková částka: {castka_celkem}\n\nS pozdravem\n{firma}",
        ],
        'objednavka_dorucena' => [
            'popis'   => '✅ Objednávka doručena',
            'predmet' => 'Objednávka #{cislo} — DORUČENA',
            'telo'    => "Vážení,\n\npotvrzujeme doručení objednávky #{cislo}.\n\n"
                       . "Děkujeme, že nakupujete u nás.\n\nS pozdravem\n{firma}",
        ],
        'objednavka_zrusena' => [
            'popis'   => '❌ Objednávka zrušena',
            'predmet' => 'Objednávka #{cislo} — ZRUŠENA',
            'telo'    => "Vážení,\n\nVaše objednávka #{cislo} (datum dodání {datum}) byla zrušena.\n\n"
                       . "Pokud došlo k omylu, kontaktujte nás prosím.\n\nS pozdravem\n{firma}",
        ],
        'admin_nova_objednavka' => [
            'popis'   => '🔔 Nová objednávka — interní notifikace pekárně',
            'predmet' => '🆕 Nová objednávka #{cislo} od {odberatel}',
            'telo'    => "Přišla nová objednávka:\n\n"
                       . "Odběratel:    {odberatel}\n"
                       . "Místo:        {misto}\n"
                       . "Datum dodání: {datum}\n"
                       . "Číslo:        {cislo}\n"
                       . "Celkem:       {castka_celkem}\n\n"
                       . "POLOŽKY:\n{polozky_text}\n",
        ],
    ];
}

/**
 * Načte šablonu z DB nebo vrátí default.
 */
function email_template_load(PDO $pdo, string $klic): array {
    ensure_email_templates_table($pdo);
    try {
        $stmt = $pdo->prepare("SELECT predmet, telo, format, aktivni FROM email_templates WHERE klic = :k");
        $stmt->execute(['k' => $klic]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row && (int)$row['aktivni'] === 1) return $row;
    } catch (Throwable $e) { /* fallback */ }
    $defs = email_template_defaults();
    $def = $defs[$klic] ?? ['predmet' => '', 'telo' => '', 'aktivni' => 1];
    $def['format'] = 'text';
    return $def;
}

/**
 * Nahradí {proměnné} v textu hodnotami z $vars.
 * Speciální: {poznamka_block} se nahrazuje za "\nPoznámka: ...\n" pokud existuje, jinak "".
 */
function email_template_render(string $text, array $vars): string {
    // Speciální: poznamka_block
    if (strpos($text, '{poznamka_block}') !== false) {
        $pozn = trim($vars['poznamka'] ?? '');
        $block = $pozn !== '' ? "\nPoznámka: $pozn\n" : '';
        $text = str_replace('{poznamka_block}', $block, $text);
    }
    foreach ($vars as $k => $v) {
        $text = str_replace('{' . $k . '}', (string) $v, $text);
    }
    return $text;
}

/**
 * Pošle odběrateli potvrzovací e-mail po založení nové objednávky.
 * (Spouští se z B2B portálu i z adminu.)
 */
function notifikace_nova_objednavka(PDO $pdo, int $obj_id): void {
    // 🔄 Webhook fire — out-going pro účetní systémy / Slack / Discord / CRM
    try {
        if (file_exists(__DIR__ . '/_webhooks.php')) {
            require_once __DIR__ . '/_webhooks.php';
            $whStmt = $pdo->prepare("SELECT o.*, od.nazev AS odberatel_nazev FROM objednavky o JOIN odberatele od ON od.id = o.odberatel_id WHERE o.id = :id");
            $whStmt->execute(['id' => $obj_id]);
            $wh = $whStmt->fetch();
            if ($wh) {
                webhook_fire('order.created', [
                    'id'             => (int) $wh['id'],
                    'cislo'          => $wh['cislo'],
                    'odberatel'      => $wh['odberatel_nazev'],
                    'datum_dodani'   => $wh['datum_dodani'],
                    'castka_bez_dph' => (float) $wh['castka_bez_dph'],
                    'castka_dph'     => (float) $wh['castka_dph'],
                    'castka_celkem'  => (float) $wh['castka_celkem'],
                    'stav'           => $wh['stav'],
                ]);
            }
        }
    } catch (Throwable $e) { error_log('webhook order.created: ' . $e->getMessage()); }

    if (!notif_zapnuto($pdo, 'notif_nova_objednavka', '1')) return;

    try {
        $stmt = $pdo->prepare("
            SELECT o.*, od.nazev AS odb_nazev, md.nazev AS misto_nazev
            FROM objednavky o
            JOIN odberatele od ON od.id = o.odberatel_id
            LEFT JOIN mista_dodani md ON md.id = o.misto_dodani_id
            WHERE o.id = :id
        ");
        $stmt->execute(['id' => $obj_id]);
        $o = $stmt->fetch();
        if (!$o) return;

        $emaily = notif_email_odberatele($pdo, (int) $o['odberatel_id']);
        if (empty($emaily)) return;

        $stmt = $pdo->prepare("
            SELECT op.mnozstvi, op.cena_bez_dph, op.sazba_dph,
                   COALESCE(v.nazev, op.vyrobek_nazev) AS nazev,
                   v.cislo,
                   COALESCE(j.kod, op.jednotka) AS jednotka
            FROM objednavky_polozky op
            LEFT JOIN vyrobky v ON v.id = op.vyrobek_id
            LEFT JOIN jednotky j ON j.id = v.jednotka_id
            WHERE op.objednavka_id = :id
            ORDER BY nazev
        ");
        $stmt->execute(['id' => $obj_id]);
        $polozky = $stmt->fetchAll();

        // 📝 Render šablony z DB (s fallbackem na default)
        $tpl = email_template_load($pdo, 'objednavka_nova');
        $polozky_text = '';
        foreach ($polozky as $p) {
            $mn = rtrim(rtrim(number_format((float) $p['mnozstvi'], 2, ',', ' '), '0'), ',');
            $jed = $p['jednotka'] ?? 'ks';
            $cena = (float) $p['cena_bez_dph'];
            $celkem = $cena * (float) $p['mnozstvi'] * (1 + (float) $p['sazba_dph'] / 100);
            $polozky_text .= sprintf("  • %s — %s %s × %s = %s\n",
                $p['nazev'], $mn, $jed, fmt_kc($cena), fmt_kc($celkem));
        }
        $vars = [
            'firma'          => nastaveni_get($pdo, 'firma_nazev', 'Provoz'),
            'cislo'          => $o['cislo'],
            'datum'          => fmt_date($o['datum_dodani']),
            'misto'          => $o['misto_nazev'] ?: '—',
            'odberatel'      => $o['odb_nazev'],
            'polozky_text'   => $polozky_text,
            'castka_bez_dph' => fmt_kc($o['castka_bez_dph']),
            'castka_dph'     => fmt_kc($o['castka_dph']),
            'castka_celkem'  => fmt_kc($o['castka_celkem']),
            'poznamka'       => $o['poznamka'] ?? '',
        ];
        $predmet = email_template_render($tpl['predmet'], $vars);
        $telo    = email_template_render($tpl['telo'], $vars);
        $format  = $tpl['format'] ?? 'auto';

        poslat_email($emaily, $predmet, $telo, $format);

        // 📱 PWA push notifikace (paralelně s emailem — bonus)
        notif_push_odberateli($pdo, (int) $o['odberatel_id'], [
            'title' => '✓ Objednávka přijata',
            'body'  => "Vaše obj. {$o['cislo']} byla přijata. Dodání: " . fmt_date($o['datum_dodani']),
            'url'   => '/',
            'typ'   => 'objednavka_nova',
        ]);
    } catch (Throwable $e) {
        error_log('notifikace_nova_objednavka: ' . $e->getMessage());
    }
}

/**
 * Pošle odběrateli e-mail při změně stavu objednávky (jen pro vybrané stavy).
 */
function notifikace_zmena_stavu(PDO $pdo, int $obj_id, string $stary, string $novy): void {
    if ($stary === $novy) return;
    if (!notif_zapnuto($pdo, 'notif_zmena_stavu', '1')) return;

    // Které stavy spouštějí e-mail (default: expedovana, dorucena)
    $stavy_raw = nastaveni_get($pdo, 'notif_stavy_pro_email', 'expedovana,dorucena');
    $stavy_aktivni = array_filter(array_map('trim', preg_split('/[,;]/', $stavy_raw)));
    if (!in_array($novy, $stavy_aktivni, true)) return;

    try {
        $stmt = $pdo->prepare("
            SELECT o.*, od.nazev AS odb_nazev, md.nazev AS misto_nazev
            FROM objednavky o
            JOIN odberatele od ON od.id = o.odberatel_id
            LEFT JOIN mista_dodani md ON md.id = o.misto_dodani_id
            WHERE o.id = :id
        ");
        $stmt->execute(['id' => $obj_id]);
        $o = $stmt->fetch();
        if (!$o) return;

        $emaily = notif_email_odberatele($pdo, (int) $o['odberatel_id']);
        if (empty($emaily)) return;

        $stavLabels = [
            'nova'        => 'přijata',
            'potvrzena'   => 'potvrzena',
            've_vyrobe'   => 've výrobě',
            'pripravena'  => 'připravena k expedici',
            'expedovana'  => 'EXPEDOVÁNA',
            'dorucena'    => 'DORUČENA',
            'zrusena'     => 'ZRUŠENA',
        ];
        $stavTxt = $stavLabels[$novy] ?? $novy;

        // 📝 Mapování stav → klíč šablony
        $stavToKey = [
            'potvrzena'  => 'objednavka_potvrzena',
            've_vyrobe'  => 'objednavka_ve_vyrobe',
            'pripravena' => 'objednavka_pripravena',
            'expedovana' => 'objednavka_expedovana',
            'dorucena'   => 'objednavka_dorucena',
            'zrusena'    => 'objednavka_zrusena',
        ];
        $tplKey = $stavToKey[$novy] ?? 'objednavka_potvrzena';
        $tpl = email_template_load($pdo, $tplKey);

        $vars = [
            'firma'          => nastaveni_get($pdo, 'firma_nazev', 'Provoz'),
            'cislo'          => $o['cislo'],
            'datum'          => fmt_date($o['datum_dodani']),
            'misto'          => $o['misto_nazev'] ?: '—',
            'odberatel'      => $o['odb_nazev'] ?? '',
            'stav'           => $stavTxt,
            'castka_celkem'  => fmt_kc($o['castka_celkem']),
            'castka_bez_dph' => fmt_kc($o['castka_bez_dph']),
            'castka_dph'     => fmt_kc($o['castka_dph']),
            'poznamka'       => $o['poznamka'] ?? '',
        ];
        $predmet = email_template_render($tpl['predmet'], $vars);
        $telo    = email_template_render($tpl['telo'], $vars);
        $format  = $tpl['format'] ?? 'auto';

        poslat_email($emaily, $predmet, $telo, $format);

        // 📱 PWA push notifikace — paralelně s emailem
        $pushIkony = [
            'potvrzena'  => '✓', 've_vyrobe' => '🔥', 'pripravena' => '📦',
            'expedovana' => '🚚', 'dorucena'  => '✅', 'zrusena'    => '❌',
        ];
        $ikona = $pushIkony[$novy] ?? '📬';
        notif_push_odberateli($pdo, (int) $o['odberatel_id'], [
            'title' => "$ikona Objednávka {$o['cislo']}",
            'body'  => "Stav: $stavTxt · Dodání: " . fmt_date($o['datum_dodani']),
            'url'   => '/',
            'typ'   => "stav_$novy",
        ]);
    } catch (Throwable $e) {
        error_log('notifikace_zmena_stavu: ' . $e->getMessage());
    }
}

// 📧 v3.0.289 — SMTP odesílání (appek_mail_raw drop-in)
require_once __DIR__ . '/_smtp_lib.php';
