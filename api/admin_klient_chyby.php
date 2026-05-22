<?php
/**
 * Zachytávání JS chyb z prohlížeče (klientů i adminů).
 *
 * POST  (bez auth — kdokoli může nahlásit chybu, ale jen z naší domény)
 *   body: { msg, source, line, col, stack, url, ua, user_id, app: 'admin'|'frontend' }
 *
 * GET   ?action=list&limit=50   — vrátí poslední chyby (vyžaduje admin)
 * DELETE ?action=clear          — smaže všechny záznamy starší než 30 dní (super admin)
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/_admin_auth.php';
cors_headers();

$pdo = db();
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$action = $_GET['action'] ?? '';

// Auto-migrace tabulky
try {
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS klient_chyby (
            id INT AUTO_INCREMENT PRIMARY KEY,
            kdy DATETIME DEFAULT CURRENT_TIMESTAMP,
            app VARCHAR(20) DEFAULT 'frontend',
            msg TEXT,
            source VARCHAR(500),
            line INT,
            col INT,
            stack TEXT,
            url VARCHAR(500),
            ua VARCHAR(300),
            user_info VARCHAR(150),
            ip VARCHAR(50),
            INDEX idx_kdy (kdy),
            INDEX idx_app (app)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
} catch (Throwable $e) {
    error_log('klient_chyby setup: ' . $e->getMessage());
}

// === POST: zaznamenat chybu (bez auth — ale s rate limit a sanitací) ===
if ($method === 'POST') {
    $body = json_input();
    if (empty($body['msg'])) json_error('Chybí msg');

    // Rate limit: max 30 chyb/min z jedné IP (aby útočník nezahlcoval log)
    $ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
    try {
        $cnt = $pdo->prepare("SELECT COUNT(*) FROM klient_chyby WHERE ip = :ip AND kdy > DATE_SUB(NOW(), INTERVAL 1 MINUTE)");
        $cnt->execute(['ip' => $ip]);
        if ((int) $cnt->fetchColumn() > 30) {
            json_response(['ok' => true, 'rate_limited' => true]);
        }
    } catch (Throwable $e) {}

    // Sanity limits (proti zaplnění DB)
    $stmt = $pdo->prepare("
        INSERT INTO klient_chyby (app, msg, source, line, col, stack, url, ua, user_info, ip)
        VALUES (:app, :m, :s, :l, :c, :st, :u, :ua, :ui, :ip)
    ");
    $stmt->execute([
        'app' => substr((string) ($body['app'] ?? 'frontend'), 0, 20),
        'm'   => substr((string) ($body['msg'] ?? ''), 0, 5000),
        's'   => substr((string) ($body['source'] ?? ''), 0, 500),
        'l'   => isset($body['line']) ? (int) $body['line'] : null,
        'c'   => isset($body['col']) ? (int) $body['col'] : null,
        'st'  => substr((string) ($body['stack'] ?? ''), 0, 8000),
        'u'   => substr((string) ($body['url'] ?? ''), 0, 500),
        'ua'  => substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 300),
        'ui'  => substr((string) ($body['user_info'] ?? ''), 0, 150),
        'ip'  => $ip,
    ]);
    json_response(['ok' => true]);
}

// Vše ostatní vyžaduje admin
require_admin();

if ($action === 'list') {
    $limit = max(1, min(500, (int) ($_GET['limit'] ?? 100)));
    $app   = $_GET['app'] ?? '';

    $where = '';
    $params = [];
    if ($app !== '') {
        $where = 'WHERE app = :app';
        $params['app'] = $app;
    }

    $stmt = $pdo->prepare("
        SELECT id, kdy, app, msg, source, line, col, stack, url, ua, user_info
        FROM klient_chyby
        $where
        ORDER BY kdy DESC
        LIMIT $limit
    ");
    $stmt->execute($params);
    $rows = $stmt->fetchAll();

    // Stats: počet za posledních 1h / 24h / 7d
    $stats = [];
    foreach (['1 HOUR' => '1h', '24 HOUR' => '24h', '7 DAY' => '7d'] as $iv => $key) {
        $s = $pdo->query("SELECT COUNT(*) FROM klient_chyby WHERE kdy > DATE_SUB(NOW(), INTERVAL $iv)");
        $stats[$key] = (int) $s->fetchColumn();
    }
    // Top 5 nejčastějších zpráv
    $top = $pdo->query("
        SELECT msg, COUNT(*) AS pocet, MAX(kdy) AS posledni
        FROM klient_chyby
        WHERE kdy > DATE_SUB(NOW(), INTERVAL 7 DAY)
        GROUP BY msg
        ORDER BY pocet DESC
        LIMIT 5
    ")->fetchAll();

    json_response([
        'errors' => $rows,
        'stats'  => $stats,
        'top'    => $top,
        'total'  => (int) $pdo->query("SELECT COUNT(*) FROM klient_chyby")->fetchColumn(),
    ]);
}

if ($method === 'DELETE') {
    require_super_admin();
    if ($action === 'clear_old') {
        $stmt = $pdo->exec("DELETE FROM klient_chyby WHERE kdy < DATE_SUB(NOW(), INTERVAL 30 DAY)");
        json_response(['ok' => true, 'smazano' => $stmt]);
    }
    if ($action === 'clear_all') {
        $pdo->exec("TRUNCATE TABLE klient_chyby");
        json_response(['ok' => true]);
    }
    json_error('Neznámá akce. Použij ?action=clear_old|clear_all');
}

json_error('Neznámá akce');
