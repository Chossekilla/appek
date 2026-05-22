<?php
/**
 * Editor šablon cenovek — uživatelské šablony pro tisk štítků/cenovek
 * Layout je JSON: { format_id, prvky: [ {id, typ, x, y, w, h, ...} ] }
 *
 * GET                — seznam
 * GET ?id=X          — detail
 * POST               — vytvořit
 * PUT                — upravit
 * DELETE ?id=X       — smazat
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/_admin_auth.php';
cors_headers();
require_admin();

$pdo = db();

// Auto-migrace
$pdo->exec("
    CREATE TABLE IF NOT EXISTS stitky_sablony (
        id INT AUTO_INCREMENT PRIMARY KEY,
        nazev VARCHAR(150) NOT NULL,
        format_id VARCHAR(40) NOT NULL,
        layout LONGTEXT NOT NULL,
        nahled_url VARCHAR(255) DEFAULT NULL,
        vytvoreno DATETIME DEFAULT CURRENT_TIMESTAMP,
        upraveno DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) DEFAULT CHARSET=utf8mb4
");

$method = $_SERVER['REQUEST_METHOD'];

try {
    if ($method === 'GET') {
        if (isset($_GET['id'])) {
            $stmt = $pdo->prepare("SELECT * FROM stitky_sablony WHERE id = :id");
            $stmt->execute(['id' => (int) $_GET['id']]);
            $row = $stmt->fetch();
            if (!$row) json_error('Šablona nenalezena', 404);
            $row['layout'] = json_decode($row['layout'], true) ?: [];
            json_response($row);
        }
        // Vracíme i layout — frontend ho potřebuje pro mini-render náhledu v cenovkách
        $stmt = $pdo->query("SELECT id, nazev, format_id, layout, nahled_url, vytvoreno, upraveno FROM stitky_sablony ORDER BY upraveno DESC");
        $rows = $stmt->fetchAll();
        // Parse layout JSON pro každý řádek
        foreach ($rows as &$r) {
            $r['layout'] = json_decode($r['layout'], true) ?: [];
        }
        unset($r);
        json_response($rows);
    }

    if ($method === 'POST') {
        $d = json_input();
        if (empty($d['nazev'])) json_error('Chybí název');
        if (empty($d['format_id'])) json_error('Chybí formát');

        $layout = is_array($d['layout'] ?? null) ? json_encode($d['layout'], JSON_UNESCAPED_UNICODE) : '{}';
        $stmt = $pdo->prepare("
            INSERT INTO stitky_sablony (nazev, format_id, layout, nahled_url)
            VALUES (:n, :f, :l, :nu)
        ");
        $stmt->execute([
            'n'  => trim($d['nazev']),
            'f'  => $d['format_id'],
            'l'  => $layout,
            'nu' => $d['nahled_url'] ?? null,
        ]);
        json_response(['id' => (int) $pdo->lastInsertId()], 201);
    }

    if ($method === 'PUT') {
        $d = json_input();
        if (empty($d['id'])) json_error('Chybí ID');

        $layout = is_array($d['layout'] ?? null) ? json_encode($d['layout'], JSON_UNESCAPED_UNICODE) : null;
        $sql = "UPDATE stitky_sablony SET nazev = :n, format_id = :f";
        $params = [
            'id' => (int) $d['id'],
            'n'  => trim($d['nazev'] ?? ''),
            'f'  => $d['format_id'] ?? '',
        ];
        if ($layout !== null) {
            $sql .= ", layout = :l";
            $params['l'] = $layout;
        }
        if (array_key_exists('nahled_url', $d)) {
            $sql .= ", nahled_url = :nu";
            $params['nu'] = $d['nahled_url'];
        }
        $sql .= " WHERE id = :id";
        $pdo->prepare($sql)->execute($params);
        json_response(['ok' => true]);
    }

    if ($method === 'DELETE') {
        $id = (int) ($_GET['id'] ?? 0);
        if (!$id) json_error('Chybí ID');
        $pdo->prepare("DELETE FROM stitky_sablony WHERE id = :id")->execute(['id' => $id]);
        json_response(['ok' => true]);
    }

    json_error('Method not allowed', 405);
} catch (Throwable $e) {
    json_error('Server error: ' . $e->getMessage(), 500);
}
