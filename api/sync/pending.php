<?php
/**
 * 📤 SYNC PENDING — Mirror (cloud) endpoint pro VÝDEJ nových záznamů pro master.
 *
 * POST /api/sync/pending.php
 *   Headers: X-Sync-Timestamp, X-Sync-Signature (HMAC SHA256)
 *   Body: {"since": "YYYY-MM-DD HH:MM:SS"}  — fetch records updated_at > since
 *
 * Vrací: {"batch": [{"table": "...", "records": [...]}, ...]}
 * Pouze tabulky s direction='pull' nebo 'both'.
 */

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/_engine.php';

header('Content-Type: application/json; charset=UTF-8');
header('X-Robots-Tag: noindex');

$pdo = db();
$startTs = microtime(true);

try {
    if (!sync_is_enabled($pdo)) {
        http_response_code(503);
        echo json_encode(['error' => 'Sync not enabled']);
        exit;
    }

    $verified = sync_hmac_verify();
    $payload = json_decode($verified['body'], true) ?: [];
    $since = $payload['since'] ?? '1970-01-01 00:00:00';

    // Validate datetime format
    if (!preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $since)) {
        $since = '1970-01-01 00:00:00';
    }

    $tables = sync_tables_config();
    $batch = [];
    $totalRecords = 0;

    foreach ($tables as $table => $opts) {
        if ($totalRecords >= SYNC_BATCH_SIZE) break;
        // Pull only 'pull' or 'both' directions (mirror returns these)
        if ($opts['direction'] === 'push') continue;

        $exists = $pdo->query("SHOW TABLES LIKE " . $pdo->quote($table))->fetch();
        if (!$exists) continue;

        $limit = SYNC_BATCH_SIZE - $totalRecords;
        try {
            $stmt = $pdo->prepare("
                SELECT * FROM `$table`
                WHERE updated_at > :since
                ORDER BY updated_at ASC
                LIMIT :lim
            ");
            $stmt->bindValue(':since', $since);
            $stmt->bindValue(':lim', $limit, PDO::PARAM_INT);
            $stmt->execute();
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
            if ($rows) {
                $batch[] = ['table' => $table, 'records' => $rows];
                $totalRecords += count($rows);
            }
        } catch (Throwable $e) {
            // Tabulka může nemít updated_at (chybí migrace) — přeskoč
            error_log("sync pending $table: " . $e->getMessage());
        }
    }

    $duration = (int) ((microtime(true) - $startTs) * 1000);
    sync_log($pdo, 'push', 'success', $totalRecords, $duration);

    echo json_encode([
        'ok'    => true,
        'batch' => $batch,
        'count' => $totalRecords,
        'duration_ms' => $duration,
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);

} catch (Throwable $e) {
    http_response_code(400);
    sync_log($pdo, 'push', 'error', 0, (int)((microtime(true) - $startTs) * 1000), $e->getMessage());
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
}
