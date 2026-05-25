<?php
/**
 * 📊 SYNC STATUS — admin info endpoint
 *
 * GET /api/sync/status.php       — vrátí stav sync (admin only)
 * POST ?action=save_config       — uloží sync config (mode/endpoint/secret/enabled)
 * POST ?action=generate_secret   — vygeneruje nový shared secret
 * POST ?action=test_connection   — otestuje cloud endpoint connectivity
 * GET  ?action=log&limit=50      — vrátí poslední sync operace
 * POST ?action=reset_queue       — vyčistí sync_queue
 */

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../_admin_auth.php';
require_once __DIR__ . '/_engine.php';

cors_headers();
require_admin();
header('Content-Type: application/json; charset=UTF-8');

$pdo = db();
$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? '';

// ── GET / default — status snapshot ─────────────────────────────
if ($method === 'GET' && !$action) {
    $cfg = sync_get_config($pdo);
    $tables = sync_tables_config();

    // Spočítej pending records napříč tabulkami
    $pending = [];
    $totalPending = 0;
    foreach ($tables as $table => $opts) {
        $exists = $pdo->query("SHOW TABLES LIKE " . $pdo->quote($table))->fetch();
        if (!$exists) continue;
        try {
            $cnt = (int) $pdo->query("
                SELECT COUNT(*) FROM `$table`
                WHERE synced_at IS NULL OR updated_at > synced_at
            ")->fetchColumn();
            if ($cnt > 0) {
                $pending[$table] = $cnt;
                $totalPending += $cnt;
            }
        } catch (Throwable $e) { /* ignore — chybí sync columns */ }
    }

    // Last 5 log entries
    $logs = $pdo->query("SELECT * FROM sync_log ORDER BY id DESC LIMIT 50")->fetchAll();

    echo json_encode([
        'config' => [
            'mode'             => $cfg['mode'] ?? 'cloud',
            'role'             => $cfg['role'] ?? 'master',
            'enabled'          => (int) ($cfg['enabled'] ?? 0),
            'cloud_endpoint'   => $cfg['cloud_endpoint'] ?? '',
            'has_secret'       => !empty($cfg['shared_secret']),
            'interval_minutes' => (int) ($cfg['interval_minutes'] ?? 15),
            'last_sync_at'     => $cfg['last_sync_at'] ?? null,
            'last_sync_status' => $cfg['last_sync_status'] ?? 'never',
            'last_error'       => $cfg['last_error'] ?? null,
        ],
        'pending' => [
            'total' => $totalPending,
            'by_table' => $pending,
        ],
        'recent_logs' => $logs,
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    exit;
}

// ── POST save_config ────────────────────────────────────────────
if ($method === 'POST' && $action === 'save_config') {
    require_super_admin();
    $d = json_input();
    $mode     = in_array($d['mode'] ?? '', ['local','hybrid','cloud'], true) ? $d['mode'] : 'cloud';
    $role     = in_array($d['role'] ?? '', ['master','mirror'], true) ? $d['role'] : 'master';
    $endpoint = trim($d['cloud_endpoint'] ?? '');
    $interval = max(5, min(1440, (int)($d['interval_minutes'] ?? 15)));
    $enabled  = (int) (!empty($d['enabled']) ? 1 : 0);

    // Validate endpoint (musí být HTTPS pro produkci, HTTP jen lokálně)
    if ($mode === 'hybrid' && $enabled) {
        if (!$endpoint) {
            json_error('Cloud endpoint je povinný pro hybrid mode', 400);
        }
        if (!preg_match('#^https?://#', $endpoint)) {
            json_error('Endpoint musí začínat http:// nebo https://', 400);
        }
    }

    try {
        $pdo->prepare("
            UPDATE sync_config SET
                mode = :m,
                role = :r,
                cloud_endpoint = :e,
                interval_minutes = :i,
                enabled = :en
            WHERE id = 1
        ")->execute([
            'm' => $mode,
            'r' => $role,
            'e' => $endpoint,
            'i' => $interval,
            'en' => $enabled,
        ]);
        echo json_encode(['ok' => true]);
    } catch (Throwable $e) {
        json_error('Save failed: ' . $e->getMessage(), 500);
    }
    exit;
}

// ── POST generate_secret ────────────────────────────────────────
if ($method === 'POST' && $action === 'generate_secret') {
    require_super_admin();
    $secret = sync_generate_secret();
    try {
        $pdo->prepare("UPDATE sync_config SET shared_secret = :s WHERE id = 1")->execute(['s' => $secret]);
        echo json_encode(['ok' => true, 'secret' => $secret]);
    } catch (Throwable $e) {
        json_error('Failed: ' . $e->getMessage(), 500);
    }
    exit;
}

// ── POST test_connection ────────────────────────────────────────
if ($method === 'POST' && $action === 'test_connection') {
    require_super_admin();
    $cfg = sync_get_config($pdo);
    if (empty($cfg['cloud_endpoint']) || empty($cfg['shared_secret'])) {
        json_error('Endpoint nebo secret není nastavený', 400);
    }
    try {
        $url = rtrim($cfg['cloud_endpoint'], '/') . '/sync/pending.php';
        $r = sync_http_request($url, 'POST', ['since' => date('Y-m-d H:i:s')], $cfg['shared_secret'], 10);
        echo json_encode([
            'ok' => $r['status'] === 200,
            'status' => $r['status'],
            'response' => $r['json'] ?? substr($r['body'], 0, 500),
        ]);
    } catch (Throwable $e) {
        json_error('Connection test failed: ' . $e->getMessage(), 502);
    }
    exit;
}

// ── GET log ─────────────────────────────────────────────────────
if ($method === 'GET' && $action === 'log') {
    $limit = max(1, min(200, (int)($_GET['limit'] ?? 50)));
    $logs = $pdo->prepare("SELECT * FROM sync_log ORDER BY id DESC LIMIT :l");
    $logs->bindValue(':l', $limit, PDO::PARAM_INT);
    $logs->execute();
    echo json_encode($logs->fetchAll(), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    exit;
}

// ── POST reset_queue ────────────────────────────────────────────
if ($method === 'POST' && $action === 'reset_queue') {
    require_super_admin();
    try {
        $pdo->exec("DELETE FROM sync_queue WHERE attempts > 3");
        echo json_encode(['ok' => true]);
    } catch (Throwable $e) {
        json_error('Reset failed: ' . $e->getMessage(), 500);
    }
    exit;
}

json_error('Neznámá akce', 404);
