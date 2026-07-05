<?php
/**
 * 🏢 VENDOR PANEL — sdílená knihovna.
 *
 * - DB connection (vlastní config.local.php, separované od customer Appek)
 * - Session-based auth pro vendor uživatele
 * - Generování & validace licenčních klíčů (sdílí algoritmus s api/_license.php)
 * - Audit log helper
 */

// 🚀 Načti config (DB credentials pro vendor)
if (file_exists(__DIR__ . '/config.local.php')) {
    require_once __DIR__ . '/config.local.php';
}

if (!defined('VENDOR_DB_HOST')) define('VENDOR_DB_HOST', 'localhost');
if (!defined('VENDOR_DB_PORT')) define('VENDOR_DB_PORT', 3306);
if (!defined('VENDOR_DB_NAME')) define('VENDOR_DB_NAME', '');
if (!defined('VENDOR_DB_USER')) define('VENDOR_DB_USER', '');
if (!defined('VENDOR_DB_PASS')) define('VENDOR_DB_PASS', '');

date_default_timezone_set('Europe/Prague');
ini_set('display_errors', '0');
ini_set('log_errors', '1');

// 🔑 License algoritmus — self-contained kopie sdílená s api/_license.php
// (NUTNĚ stejný LICENSE_SALT, jinak validace nesedí na customer instalacích!)
require_once __DIR__ . '/_license.php';

// 🔐 TOTP (2FA) — Google Authenticator kompatibilní
require_once __DIR__ . '/_totp.php';

/**
 * Vrátí PDO instance (singleton).
 */
function vendor_db(): PDO {
    static $pdo = null;
    if ($pdo === null) {
        $dsn = sprintf(
            'mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
            VENDOR_DB_HOST, VENDOR_DB_PORT, VENDOR_DB_NAME
        );
        $pdo = new PDO($dsn, VENDOR_DB_USER, VENDOR_DB_PASS, [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]);
        // 🐛 v3.0.402 — date('P') místo fixního '+01:00' (v létě CEST +02:00 → NOW() o hodinu vedle)
        $pdo->exec("SET time_zone = '" . date('P') . "'");
        vendor_ensure_schema($pdo);
        vendor_ensure_2fa_columns($pdo);
        vendor_ensure_pirate_columns($pdo);
        vendor_ensure_update_signature_column($pdo);
        vendor_apply_status_expiry($pdo);
    }
    return $pdo;
}

/**
 * Idempotentní migrace schématu (na každém requestu).
 */
function vendor_ensure_schema(PDO $pdo): void {
    static $done = false;
    if ($done) return;
    $done = true;
    try {
        $sql = file_get_contents(__DIR__ . '/_schema.sql');
        if ($sql) {
            // Strip SQL komentáře (-- ...) — jinak by chunky začínající komentářem byly skipnuty
            $sqlClean = preg_replace('/^\s*--.*$/m', '', $sql);
            $stmts = preg_split('/;\s*$/m', $sqlClean);
            foreach ($stmts as $s) {
                $s = trim($s);
                if ($s === '') continue;
                try { $pdo->exec($s); } catch (Throwable $e) { /* ignore — duplicate column atd. */ }
            }
        }
    } catch (Throwable $e) { error_log('vendor_ensure_schema: ' . $e->getMessage()); }
}

/**
 * Idempotentní migrace pirate heartbeat sloupců (vendor_licenses).
 * 🆕 v2.6.1 — anti-piracy install fingerprint binding.
 */
function vendor_ensure_pirate_columns(PDO $pdo): void {
    static $done = false;
    if ($done) return;
    $done = true;
    try {
        $cols = $pdo->query("SHOW COLUMNS FROM vendor_licenses")->fetchAll(PDO::FETCH_COLUMN);
        if (!in_array('last_seen_at', $cols, true)) {
            $pdo->exec("ALTER TABLE vendor_licenses ADD COLUMN last_seen_at DATETIME NULL");
        }
        if (!in_array('last_seen_host', $cols, true)) {
            $pdo->exec("ALTER TABLE vendor_licenses ADD COLUMN last_seen_host VARCHAR(255) NULL");
        }
        if (!in_array('last_seen_version', $cols, true)) {
            $pdo->exec("ALTER TABLE vendor_licenses ADD COLUMN last_seen_version VARCHAR(32) NULL");
        }
        if (!in_array('heartbeat_count', $cols, true)) {
            $pdo->exec("ALTER TABLE vendor_licenses ADD COLUMN heartbeat_count INT NOT NULL DEFAULT 0");
        }
        // 🆕 v2.6.1 — Anti-piracy: install fingerprint (HMAC unique per install)
        if (!in_array('install_fingerprint', $cols, true)) {
            $pdo->exec("ALTER TABLE vendor_licenses ADD COLUMN install_fingerprint VARCHAR(64) NULL");
        }
        if (!in_array('fingerprint_first_seen', $cols, true)) {
            $pdo->exec("ALTER TABLE vendor_licenses ADD COLUMN fingerprint_first_seen DATETIME NULL");
        }
        if (!in_array('lock_state', $cols, true)) {
            $pdo->exec("ALTER TABLE vendor_licenses ADD COLUMN lock_state ENUM('active','locked','grace','revoked') NOT NULL DEFAULT 'active'");
        }
        if (!in_array('lock_reason', $cols, true)) {
            $pdo->exec("ALTER TABLE vendor_licenses ADD COLUMN lock_reason VARCHAR(120) NULL");
        }
        if (!in_array('lock_until', $cols, true)) {
            $pdo->exec("ALTER TABLE vendor_licenses ADD COLUMN lock_until DATETIME NULL");
        }
        if (!in_array('mismatch_count', $cols, true)) {
            $pdo->exec("ALTER TABLE vendor_licenses ADD COLUMN mismatch_count INT NOT NULL DEFAULT 0");
        }
    } catch (Throwable $e) { /* ignore */ }
}

/**
 * Idempotentní migrace 2FA sloupců.
 */
function vendor_ensure_2fa_columns(PDO $pdo): void {
    static $done = false;
    if ($done) return;
    $done = true;
    try {
        $cols = $pdo->query("SHOW COLUMNS FROM vendor_users")->fetchAll(PDO::FETCH_COLUMN);
        if (!in_array('totp_secret', $cols, true)) {
            $pdo->exec("ALTER TABLE vendor_users ADD COLUMN totp_secret VARCHAR(32) NULL");
        }
        if (!in_array('totp_enabled', $cols, true)) {
            $pdo->exec("ALTER TABLE vendor_users ADD COLUMN totp_enabled TINYINT(1) NOT NULL DEFAULT 0");
        }
    } catch (Throwable $e) { /* ignore */ }
}

/**
 * Idempotentní migrace — sloupec `signature` ve vendor_updates (kryptopodpis bundlu).
 * 🔐 v3.0.388 — supply-chain ochrana self-update.
 */
function vendor_ensure_update_signature_column(PDO $pdo): void {
    static $done = false;
    if ($done) return;
    $done = true;
    try {
        $cols = $pdo->query("SHOW COLUMNS FROM vendor_updates")->fetchAll(PDO::FETCH_COLUMN);
        if (!in_array('signature', $cols, true)) {
            $pdo->exec("ALTER TABLE vendor_updates ADD COLUMN signature TEXT NULL");
        }
    } catch (Throwable $e) { /* ignore */ }
}

/**
 * Auto-přepne status na 'expired' u klíčů kde expires_at < dnes (a nejsou revoked).
 */
function vendor_apply_status_expiry(PDO $pdo): void {
    try {
        $pdo->exec("
            UPDATE vendor_licenses
            SET status = 'expired'
            WHERE expires_at IS NOT NULL
              AND expires_at < CURDATE()
              AND status = 'active'
        ");
    } catch (Throwable $e) { /* tabulka může neexistovat při prvním migrate */ }
}

// ════════════════════════════════════════════════════════════
// SESSION & AUTH
// ════════════════════════════════════════════════════════════

function vendor_session_start(): void {
    if (session_status() !== PHP_SESSION_NONE) return;
    $isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
            || ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https';

    // 🔧 Cookie path detekce: subdoména (vendor.appek.cz) → '/', subfolder (appek.cz/vendor/) → '/vendor/'
    //
    // Bug fix: dříve napevno '/vendor/' — na subdoméně se cookie nastavila,
    //          ale browser ji při requestu na '/index.php' už neposlal zpět
    //          (path mismatch) → session zmizela, login byl bez efektu.
    $scriptName = $_SERVER['SCRIPT_NAME'] ?? '/index.php';
    $dir        = rtrim(dirname($scriptName), '/');     // např. '/vendor' nebo ''
    $cookiePath = $dir === '' ? '/' : $dir . '/';        // '/vendor/' nebo '/'

    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => $cookiePath,
        'secure'   => $isHttps,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_name('APPEKVENDORSID');
    session_start();
}

function vendor_user(): ?array {
    vendor_session_start();
    return $_SESSION['vendor_user'] ?? null;
}

function vendor_require_login(): array {
    $u = vendor_user();
    if (!$u) {
        if (vendor_is_ajax()) {
            http_response_code(401);
            header('Content-Type: application/json');
            echo json_encode(['error' => 'unauthorized']);
            exit;
        }
        header('Location: index.php');
        exit;
    }
    return $u;
}

/**
 * @param string $totpCode  6místný kód z autentikační aplikace (volitelný)
 * @return string  'ok' | 'bad_credentials' | 'totp_required' | 'totp_bad'
 */
function vendor_login(string $username, string $password, string $totpCode = ''): string {
    $pdo = vendor_db();
    $stmt = $pdo->prepare("SELECT * FROM vendor_users WHERE username = :u LIMIT 1");
    $stmt->execute(['u' => $username]);
    $row = $stmt->fetch();
    if (!$row || !password_verify($password, $row['password_hash'])) {
        return 'bad_credentials';
    }
    // 🔐 TOTP gate
    if (!empty($row['totp_enabled']) && !empty($row['totp_secret'])) {
        if ($totpCode === '') return 'totp_required';
        if (!totp_verify($row['totp_secret'], $totpCode)) return 'totp_bad';
    }
    vendor_session_start();
    $_SESSION['vendor_user'] = [
        'id'           => (int) $row['id'],
        'username'     => $row['username'],
        'display_name' => $row['display_name'],
        'role'         => $row['role'],
    ];
    try {
        $pdo->prepare("UPDATE vendor_users SET last_login = NOW(), last_ip = :ip WHERE id = :id")
            ->execute(['ip' => $_SERVER['REMOTE_ADDR'] ?? null, 'id' => $row['id']]);
    } catch (Throwable $e) { /* ignore */ }
    vendor_audit($pdo, $_SESSION['vendor_user'], 'login', null, null);
    return 'ok';
}

function vendor_logout(): void {
    vendor_session_start();
    $u = $_SESSION['vendor_user'] ?? null;
    if ($u) vendor_audit(vendor_db(), $u, 'logout', null, null);
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $p = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'], $p['secure'], $p['httponly']);
    }
    session_destroy();
}

function vendor_is_ajax(): bool {
    return strtolower($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'xmlhttprequest'
        || str_starts_with($_SERVER['HTTP_ACCEPT'] ?? '', 'application/json');
}

// ════════════════════════════════════════════════════════════
// AUDIT LOG
// ════════════════════════════════════════════════════════════

function vendor_audit(PDO $pdo, ?array $user, string $action, ?array $license = null, ?string $details = null): void {
    try {
        $pdo->prepare("
            INSERT INTO vendor_audit_log (user_id, username, action, target_license_id, target_key, details, ip)
            VALUES (:uid, :un, :a, :lid, :lk, :d, :ip)
        ")->execute([
            'uid' => $user['id']       ?? null,
            'un'  => $user['username'] ?? null,
            'a'   => $action,
            'lid' => $license['id']          ?? null,
            'lk'  => $license['license_key'] ?? null,
            'd'   => $details,
            'ip'  => $_SERVER['REMOTE_ADDR'] ?? null,
        ]);
    } catch (Throwable $e) { error_log('vendor_audit: ' . $e->getMessage()); }
}

// ════════════════════════════════════════════════════════════
// JSON HELPERS
// ════════════════════════════════════════════════════════════

function vendor_json($data, int $code = 200): void {
    http_response_code($code);
    header('Content-Type: application/json; charset=UTF-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    exit;
}

function vendor_json_error(string $msg, int $code = 400): void {
    vendor_json(['error' => $msg], $code);
}

function vendor_json_input(): array {
    $raw = file_get_contents('php://input');
    $d = json_decode($raw, true);
    return is_array($d) ? $d : [];
}

// ════════════════════════════════════════════════════════════
// CSRF PROTECTION (synchronizer token pattern)
// ════════════════════════════════════════════════════════════
//
// Obrana do hloubky nad SameSite=Lax. Token vázaný na session, ověřuje
// se na VŠECH state-changing POST requestech (api.php hlavička X-CSRF-Token
// + HTML formuláře hidden pole _csrf). Strojové endpointy (heartbeat,
// resolve, deploy-hook) NEPOUŽÍVAJÍ session → CSRF se na ně NEAPLIKUJE.

/**
 * Vrátí (a při prvním volání vygeneruje) CSRF token vázaný na session.
 */
function vendor_csrf_token(): string {
    vendor_session_start();
    if (empty($_SESSION['_csrf']) || !is_string($_SESSION['_csrf'])) {
        $_SESSION['_csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['_csrf'];
}

/**
 * Vytiskne hidden input s CSRF tokenem — vlož do každého <form method="POST">.
 */
function vendor_csrf_field(): void {
    echo '<input type="hidden" name="_csrf" value="' . htmlspecialchars(vendor_csrf_token(), ENT_QUOTES) . '">';
}

/**
 * Ověří CSRF token (z POST pole _csrf nebo hlavičky X-CSRF-Token).
 * Při selhání ukončí request 403. Volej na začátku POST větve.
 *
 * @param bool $json  true → odpověď JSONem (api.php), false → plain 403 (formuláře)
 */
function vendor_csrf_check(bool $json = false): void {
    vendor_session_start();
    $sent = $_POST['_csrf'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
    $real = $_SESSION['_csrf'] ?? '';
    if (!is_string($real) || $real === '' || !is_string($sent) || $sent === '' || !hash_equals($real, $sent)) {
        if ($json) {
            vendor_json_error('CSRF token neplatný — obnov stránku (F5) a zkus znovu.', 403);
        }
        http_response_code(403);
        header('Content-Type: text/plain; charset=UTF-8');
        echo "403 — CSRF token neplatný.\nObnov stránku (F5) a zkus znovu.";
        exit;
    }
}
