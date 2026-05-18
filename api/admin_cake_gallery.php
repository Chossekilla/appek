<?php
/**
 * 🖼️ GALERIE INSPIRACÍ DORTŮ — Cukrárna balíček.
 *
 * GET      → seznam všech fotek
 * POST     → { url, nazev, tag } přidat
 * DELETE ?id=N → smazat
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/_admin_auth.php';
require_once __DIR__ . '/_packages_lib.php';

cors_headers();
require_admin();
header('Content-Type: application/json; charset=UTF-8');

if (!package_enabled('cukrarna')) {
    http_response_code(402);
    json_response(['error' => 'Vyžaduje balíček 🧁 Cukrárna']);
}

$pdo = db();
$pdo->exec("
    CREATE TABLE IF NOT EXISTS cake_gallery (
        id INT AUTO_INCREMENT PRIMARY KEY,
        url VARCHAR(500) NOT NULL,
        nazev VARCHAR(200) NULL,
        tag VARCHAR(80) NULL,
        poradi INT DEFAULT 0,
        aktivni TINYINT(1) NOT NULL DEFAULT 1,
        vytvoreno DATETIME DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_tag (tag),
        INDEX idx_aktivni (aktivni, poradi)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
");

$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'GET') {
    $rows = $pdo->query("SELECT * FROM cake_gallery WHERE aktivni = 1 ORDER BY poradi, id DESC")->fetchAll();
    json_response(['photos' => $rows]);
}

if ($method === 'POST') {
    require_super_admin();
    $d = json_input();
    $url = trim($d['url'] ?? '');
    if (!$url || !preg_match('#^(https?://|/)#', $url)) json_error('Neplatná URL', 400);
    try {
        $pdo->prepare("
            INSERT INTO cake_gallery (url, nazev, tag) VALUES (:u, :n, :t)
        ")->execute([
            'u' => $url,
            'n' => trim($d['nazev'] ?? '') ?: null,
            't' => trim($d['tag']   ?? '') ?: null,
        ]);
        json_response(['ok' => true, 'id' => (int) $pdo->lastInsertId()]);
    } catch (Throwable $e) { json_error('DB: ' . $e->getMessage(), 500); }
}

if ($method === 'DELETE') {
    require_super_admin();
    $id = (int) ($_GET['id'] ?? 0);
    if (!$id) json_error('Chybí id', 400);
    $pdo->prepare("DELETE FROM cake_gallery WHERE id = :id")->execute(['id' => $id]);
    json_response(['ok' => true]);
}

json_error('Neznámá akce', 404);
