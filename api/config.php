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
if (!defined('APP_URL'))     define('APP_URL',     'https://white-badger-130749.hostingersite.com');
define('APP_NAME',    'APPEK B2B');
define('APP_VERSION',    '2.9.267'); // SemVer — bump při release (matches git tag bez 'v')
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
        $pdo->exec("SET time_zone = '+01:00'"); // CET; v létě se připočítá DST přes PHP

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
    try {
        $pdo = db();
        _ensure_prihlaseni_table($pdo);
        $stmt = $pdo->prepare("
            SELECT COUNT(*) FROM prihlaseni_pokusy
            WHERE typ = :t AND uspesny = 0
              AND cas > DATE_SUB(NOW(), INTERVAL 15 MINUTE)
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
    // Najít skupinu odběratele
    $stmt = $pdo->prepare("SELECT cenova_skupina_id FROM odberatele WHERE id = :id");
    $stmt->execute(['id' => $odberatel_id]);
    $skupina_id = $stmt->fetchColumn();

    // Načti všechny aktivní výrobky se základními informacemi
    $vyrobky = $pdo->query("
        SELECT v.id, v.cislo, v.nazev, v.cena_bez_dph AS cena_zakladni,
               v.kategorie_id, v.hmotnost_g, v.min_objednavka,
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
        // Bez skupiny - vrátíme jen základní ceny
        foreach ($vyrobky as &$v) {
            $v['cena_bez_dph']   = (float) $v['cena_zakladni'];
            $v['sleva_pct']      = null;
            $v['pevna_cena']     = null;
            $v['cena_skupina']   = null;
        }
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

    // Aplikuj na každý výrobek
    // Priorita: per-vyrobek > per-kategorie > sortiment rule > globalni_sleva_pct na ceníku > základní cena
    foreach ($vyrobky as &$v) {
        $vid    = (int) $v['id'];
        $katid  = (int) $v['kategorie_id'];
        $base   = (float) $v['cena_zakladni'];
        $rule   = $idx_vyrobek[$vid] ?? $idx_kategorie[$katid] ?? $idx_sortiment ?? null;

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
    }
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
        $ok = @mail($komu, $predmet_enc, $telo, implode("\r\n", $headers));
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
