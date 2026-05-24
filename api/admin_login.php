<?php
require_once __DIR__ . '/config.php';
cors_headers();
session_secure_start();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') json_error('Pouze POST', 405);

$data  = json_input();
$email = trim($data['email'] ?? '');
$heslo = $data['heslo'] ?? '';
$ip    = client_ip();

if (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($heslo) < 4) {
    json_error('Neplatné údaje');
}

if (login_rate_limited($email, $ip, 'admin')) {
    json_error('Příliš mnoho pokusů. Zkuste to za 15 minut.', 429);
}

// 🆕 v2.9.270 — idempotentní migrace pin_hash/pos_only (kdyby admin_users.php nikdy neproběhlo)
try {
    $cols = db()->query("
        SELECT COLUMN_NAME FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'admin_users'
    ")->fetchAll(PDO::FETCH_COLUMN);
    if (!in_array('pos_only', $cols, true)) {
        try { db()->exec("ALTER TABLE admin_users ADD COLUMN pos_only TINYINT(1) NOT NULL DEFAULT 0"); } catch (Throwable $e) {}
    }
} catch (Throwable $e) {}

$stmt = db()->prepare("
    SELECT id, jmeno, heslo_hash, role, aktivni, COALESCE(pos_only, 0) AS pos_only
    FROM admin_users WHERE email = :email LIMIT 1
");
$stmt->execute(['email' => $email]);
$user = $stmt->fetch();

if (!$user || !password_verify($heslo, $user['heslo_hash'])) {
    login_log($email, $ip, 'admin', false);
    json_error('Nesprávný email nebo heslo', 401);
}
if (!$user['aktivni']) {
    login_log($email, $ip, 'admin', false);
    json_error('Účet je deaktivován', 403);
}
// 🆕 v2.9.270 — POS-only uživatel nesmí do adminu, jen do POS přes PIN
if (!empty($user['pos_only'])) {
    login_log($email, $ip, 'admin', false);
    json_error('Tento účet má přístup pouze do POS kasy (přihlaste se přes PIN v /pos/)', 403);
}

// Ochrana proti session fixation
session_regenerate_id(true);

$_SESSION['admin_id']    = (int) $user['id'];
$_SESSION['admin_jmeno'] = $user['jmeno'];
$_SESSION['admin_role']  = $user['role'];

// 🔒 v2.6.0 SECURITY: regenerate session ID po loginu (anti session fixation)
session_regenerate_id(true);

db()->prepare("UPDATE admin_users SET posledni_login = NOW() WHERE id = :id")
    ->execute(['id' => $user['id']]);

login_log($email, $ip, 'admin', true);

// 🔒 v2.6.0 SECURITY: vrať i CSRF token klientovi (frontend ho použije jako X-CSRF-Token header)
require_once __DIR__ . '/_csrf.php';
$csrfToken = csrf_token();

json_response([
    'admin' => ['id' => $user['id'], 'jmeno' => $user['jmeno'], 'role' => $user['role']],
    'csrf_token' => $csrfToken,
]);
