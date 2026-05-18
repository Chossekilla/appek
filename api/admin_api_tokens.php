<?php
/**
 * Správa API tokenů — UI v admin Nastavení → Účetní API.
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/_admin_auth.php';
cors_headers();
require_admin();

$pdo = db();
$method = $_SERVER['REQUEST_METHOD'];

$pdo->exec("
    CREATE TABLE IF NOT EXISTS api_tokens (
        id           INT AUTO_INCREMENT PRIMARY KEY,
        token        VARCHAR(64) NOT NULL UNIQUE,
        nazev        VARCHAR(150) NOT NULL,
        opravneni    VARCHAR(255) DEFAULT 'read',
        aktivni      TINYINT(1) NOT NULL DEFAULT 1,
        vytvoreno    DATETIME DEFAULT CURRENT_TIMESTAMP,
        posledni_pouziti DATETIME NULL,
        pocet_volani INT NOT NULL DEFAULT 0,
        INDEX idx_token (token)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
");

if ($method === 'GET') {
    $stmt = $pdo->query("SELECT id, nazev, opravneni, aktivni, vytvoreno, posledni_pouziti, pocet_volani,
                                CONCAT(SUBSTRING(token, 1, 8), '…', SUBSTRING(token, -4)) AS token_preview
                         FROM api_tokens ORDER BY aktivni DESC, vytvoreno DESC");
    json_response($stmt->fetchAll(PDO::FETCH_ASSOC));
}

if ($method === 'POST') {
    $d = json_input();
    if (empty($d['nazev'])) json_error('Chybí název');
    $token = bin2hex(random_bytes(32));    // 64-znakový hex token
    $opr = (isset($d['opravneni']) && in_array($d['opravneni'], ['read', 'write'], true)) ? $d['opravneni'] : 'read';
    $pdo->prepare("INSERT INTO api_tokens (token, nazev, opravneni, aktivni) VALUES (:t, :n, :o, 1)")
        ->execute(['t' => $token, 'n' => trim($d['nazev']), 'o' => $opr]);
    json_response([
        'ok' => true,
        'id' => $pdo->lastInsertId(),
        'token' => $token,   // ⚠️ Plný token vrátíme jen 1× při vytvoření
        'upozorneni' => 'Uložte si token — z bezpečnostních důvodů ho podruhé neuvidíte!',
    ], 201);
}

if ($method === 'PUT') {
    $d = json_input();
    if (empty($d['id'])) json_error('Chybí ID');
    $pdo->prepare("UPDATE api_tokens SET nazev = :n, opravneni = :o, aktivni = :a WHERE id = :id")
        ->execute([
            'id' => (int) $d['id'],
            'n'  => trim($d['nazev'] ?? ''),
            'o'  => in_array($d['opravneni'] ?? '', ['read','write'], true) ? $d['opravneni'] : 'read',
            'a'  => isset($d['aktivni']) ? (int)(bool)$d['aktivni'] : 1,
        ]);
    json_response(['ok' => true]);
}

if ($method === 'DELETE') {
    $id = (int) ($_GET['id'] ?? 0);
    if (!$id) json_error('Chybí ID');
    $pdo->prepare("DELETE FROM api_tokens WHERE id = :id")->execute(['id' => $id]);
    json_response(['ok' => true]);
}

json_error('Method not allowed', 405);
