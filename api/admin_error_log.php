<?php
/**
 * 🆕 v2.9.321 — APP-LEVEL ERROR LOG VIEWER
 *
 * Persistovaný log chyb z app_errors tabulky (json_error_safe write site).
 * Admin v UI najde konkrétní chybu podle request_id co viděl v toast.
 *
 * Endpoints:
 *   GET  /api/admin_error_log.php?action=list[&severity=error&q=...&limit=50&offset=0&since_h=24]
 *   GET  /api/admin_error_log.php?action=detail&request_id=XXXX
 *   GET  /api/admin_error_log.php?action=stats
 *   POST /api/admin_error_log.php?action=clear_old[&days=30]
 *   POST /api/admin_error_log.php?action=clear_all  (vyžaduje confirm=YES_DELETE_ALL_ERRORS)
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/_admin_auth.php';

cors_headers();
require_admin();
header('Content-Type: application/json; charset=UTF-8');

$pdo = db();
$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? 'list';

// Ensure table exists (defensive — config.php má jen lazy create, můžeme to dostat dřív)
try {
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS app_errors (
            id BIGINT AUTO_INCREMENT PRIMARY KEY,
            request_id VARCHAR(16) NOT NULL,
            severity VARCHAR(10) NOT NULL DEFAULT 'error',
            http_status SMALLINT NOT NULL DEFAULT 500,
            source VARCHAR(120) NULL,
            message VARCHAR(255) NOT NULL,
            exception_class VARCHAR(120) NULL,
            exception_msg TEXT NULL,
            exception_file VARCHAR(255) NULL,
            exception_line INT NULL,
            exception_trace TEXT NULL,
            user_email VARCHAR(120) NULL,
            user_role VARCHAR(20) NULL,
            ip VARCHAR(45) NULL,
            user_agent VARCHAR(255) NULL,
            url VARCHAR(500) NULL,
            method VARCHAR(10) NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_req (request_id),
            INDEX idx_sev_created (severity, created_at),
            INDEX idx_created (created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
} catch (\Throwable $e) { json_error_safe('Schema check selhal', $e, 500); }

if ($method === 'GET' && $action === 'list') {
    $severity = $_GET['severity'] ?? null;
    $q        = trim((string)($_GET['q'] ?? ''));
    $limit    = max(1, min(200, (int)($_GET['limit']  ?? 50)));
    $offset   = max(0, (int)($_GET['offset'] ?? 0));
    $sinceH   = max(0, min(720, (int)($_GET['since_h'] ?? 168))); // default 7 dní, max 30

    $where = [];
    $params = [];
    if ($sinceH > 0) { $where[] = 'created_at >= DATE_SUB(NOW(), INTERVAL :h HOUR)'; $params['h'] = $sinceH; }
    if ($severity && in_array($severity, ['error', 'warn', 'info'], true)) {
        $where[] = 'severity = :sev'; $params['sev'] = $severity;
    }
    if ($q !== '') {
        $where[] = '(message LIKE :q OR exception_msg LIKE :q OR source LIKE :q OR request_id = :rid)';
        $params['q']   = '%' . str_replace(['%','_'], ['\\%','\\_'], $q) . '%';
        $params['rid'] = $q; // přesný match na reqId (uživatel zkopíruje z toast)
    }
    $whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

    try {
        $total = (int) $pdo->prepare("SELECT COUNT(*) FROM app_errors {$whereSql}")
            ->execute($params) ?: 0;
        // count workaround: prepare+execute vrací bool, fetchColumn vrátí int
        $stC = $pdo->prepare("SELECT COUNT(*) FROM app_errors {$whereSql}");
        $stC->execute($params);
        $total = (int) $stC->fetchColumn();

        $st = $pdo->prepare("
            SELECT id, request_id, severity, http_status, source, message,
                   exception_class, exception_file, exception_line,
                   user_email, user_role, ip, url, method, created_at
            FROM app_errors {$whereSql}
            ORDER BY created_at DESC, id DESC
            LIMIT {$limit} OFFSET {$offset}
        ");
        $st->execute($params);
        $rows = $st->fetchAll(PDO::FETCH_ASSOC);

        json_response([
            'ok'     => true,
            'rows'   => $rows,
            'total'  => $total,
            'limit'  => $limit,
            'offset' => $offset,
        ]);
    } catch (\Throwable $e) { json_error_safe('List selhal', $e, 500); }
}

if ($method === 'GET' && $action === 'detail') {
    $rid = trim((string)($_GET['request_id'] ?? ''));
    if (!$rid) json_error('Chybí request_id', 400);
    try {
        $st = $pdo->prepare("SELECT * FROM app_errors WHERE request_id = :rid ORDER BY created_at DESC LIMIT 5");
        $st->execute(['rid' => $rid]);
        $rows = $st->fetchAll(PDO::FETCH_ASSOC);
        if (empty($rows)) json_error('Nenalezeno', 404);
        json_response(['ok' => true, 'rows' => $rows]);
    } catch (\Throwable $e) { json_error_safe('Detail selhal', $e, 500); }
}

if ($method === 'GET' && $action === 'stats') {
    try {
        $sql = "
            SELECT
                COUNT(*) AS total_30d,
                SUM(CASE WHEN created_at >= DATE_SUB(NOW(), INTERVAL 1 HOUR) THEN 1 ELSE 0 END) AS last_1h,
                SUM(CASE WHEN created_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR) THEN 1 ELSE 0 END) AS last_24h,
                SUM(CASE WHEN created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY) THEN 1 ELSE 0 END) AS last_7d,
                SUM(CASE WHEN severity='error' THEN 1 ELSE 0 END) AS errors,
                SUM(CASE WHEN severity='warn' THEN 1 ELSE 0 END) AS warns,
                SUM(CASE WHEN severity='info' THEN 1 ELSE 0 END) AS infos
            FROM app_errors
            WHERE created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
        ";
        $sum = $pdo->query($sql)->fetch(PDO::FETCH_ASSOC) ?: [];

        // Top 10 sources by count (24h)
        $top = $pdo->query("
            SELECT source, message, COUNT(*) AS pocet, MAX(created_at) AS last_seen
            FROM app_errors
            WHERE created_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)
            GROUP BY source, message
            ORDER BY pocet DESC, last_seen DESC
            LIMIT 10
        ")->fetchAll(PDO::FETCH_ASSOC);

        json_response(['ok' => true, 'summary' => $sum, 'top_24h' => $top]);
    } catch (\Throwable $e) { json_error_safe('Stats selhal', $e, 500); }
}

if ($method === 'POST' && $action === 'clear_old') {
    $days = max(1, min(365, (int)($_GET['days'] ?? 30)));
    try {
        $n = $pdo->exec("DELETE FROM app_errors WHERE created_at < DATE_SUB(NOW(), INTERVAL {$days} DAY)");
        json_response(['ok' => true, 'deleted' => (int) $n, 'older_than_days' => $days]);
    } catch (\Throwable $e) { json_error_safe('Clear old selhal', $e, 500); }
}

if ($method === 'POST' && $action === 'clear_all') {
    $confirm = $_POST['confirm'] ?? (json_decode(file_get_contents('php://input'), true)['confirm'] ?? '');
    if ($confirm !== 'YES_DELETE_ALL_ERRORS') {
        json_error('Vyžaduje confirm=YES_DELETE_ALL_ERRORS', 400);
    }
    try {
        $n = $pdo->exec("DELETE FROM app_errors");
        json_response(['ok' => true, 'deleted' => (int) $n]);
    } catch (\Throwable $e) { json_error_safe('Clear all selhal', $e, 500); }
}

json_error('Neznámá akce (list|detail|stats|clear_old|clear_all)', 404);
