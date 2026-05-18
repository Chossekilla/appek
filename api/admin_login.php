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

$stmt = db()->prepare("
    SELECT id, jmeno, heslo_hash, role, aktivni
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

// Ochrana proti session fixation
session_regenerate_id(true);

$_SESSION['admin_id']    = (int) $user['id'];
$_SESSION['admin_jmeno'] = $user['jmeno'];
$_SESSION['admin_role']  = $user['role'];

db()->prepare("UPDATE admin_users SET posledni_login = NOW() WHERE id = :id")
    ->execute(['id' => $user['id']]);

login_log($email, $ip, 'admin', true);

json_response(['admin' => ['id' => $user['id'], 'jmeno' => $user['jmeno'], 'role' => $user['role']]]);
