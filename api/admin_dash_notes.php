<?php
// =============================================================
// 📌 v3.0.498 — NÁSTĚNKA POZNÁMEK na dashboardu
//   Interní vzkazy mezi uživateli (pekař 1 → pekař 2 → šéf). Sdílené,
//   viditelné všem přihlášeným adminům dané instalace. Malý tým → smazat
//   smí kdokoliv (úklid hotových vzkazů).
//   GET = seznam · POST {action:add,text} = přidat · POST {action:delete,id} = smazat
// =============================================================
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/_admin_auth.php';
cors_headers();
$admin = require_admin();
$pdo = db();

// Idempotentní migrace tabulky
$pdo->exec("CREATE TABLE IF NOT EXISTS dash_poznamky (
    id INT AUTO_INCREMENT PRIMARY KEY,
    autor VARCHAR(120) NOT NULL DEFAULT '',
    autor_id INT NULL,
    text VARCHAR(1000) NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

if ($method === 'GET') {
    $rows = $pdo->query("
        SELECT id, autor, autor_id, text, created_at
        FROM dash_poznamky
        ORDER BY created_at DESC, id DESC
        LIMIT 50
    ")->fetchAll(PDO::FETCH_ASSOC);
    json_response(['ok' => true, 'poznamky' => $rows, 'me' => (int) $admin['id']]);
}

if ($method === 'POST') {
    $d = json_decode(file_get_contents('php://input'), true) ?: [];
    $action = $d['action'] ?? 'add';

    if ($action === 'delete') {
        $id = (int) ($d['id'] ?? 0);
        if ($id <= 0) json_error('Chybí id', 400);
        $pdo->prepare("DELETE FROM dash_poznamky WHERE id = :id")->execute(['id' => $id]);
        json_response(['ok' => true]);
    }

    // action = add
    $text = trim((string) ($d['text'] ?? ''));
    if ($text === '') json_error('Prázdná poznámka', 400);
    if (mb_strlen($text) > 1000) $text = mb_substr($text, 0, 1000);
    $autor = trim((string) ($admin['jmeno'] ?? '')) ?: 'Admin';
    $st = $pdo->prepare("INSERT INTO dash_poznamky (autor, autor_id, text) VALUES (:a, :aid, :t)");
    $st->execute(['a' => $autor, 'aid' => (int) $admin['id'], 't' => $text]);
    json_response(['ok' => true, 'id' => (int) $pdo->lastInsertId()]);
}

json_error('Nepodporovaná metoda', 405);
