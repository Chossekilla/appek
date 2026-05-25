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

if (login_rate_limited($email, $ip, 'odberatel')) {
    json_error('Příliš mnoho pokusů. Zkuste to za 15 minut.', 429);
}

// Blokovaný účet vrací stejnou chybu jako špatné heslo - žádný oracle
$stmt = db()->prepare("
    SELECT id, nazev, heslo_hash
    FROM odberatele
    WHERE login_email = :email AND blokovan = 0 LIMIT 1
");
$stmt->execute(['email' => $email]);
$user = $stmt->fetch();

if (!$user || !password_verify($heslo, $user['heslo_hash'])) {
    login_log($email, $ip, 'odberatel', false);
    json_error('Nesprávný email nebo heslo', 401);
}

session_regenerate_id(true);

$_SESSION['odberatel_id']    = (int) $user['id'];
$_SESSION['odberatel_nazev'] = $user['nazev'];

login_log($email, $ip, 'odberatel', true);

json_response(['odberatel' => ['id' => $user['id'], 'nazev' => $user['nazev']]]);
