<?php
/**
 * 📥 SYNC RECEIVE — Mirror (cloud) endpoint pro PŘÍJEM batch z masteru.
 *
 * POST /api/sync/receive.php
 *   Headers: X-Sync-Timestamp, X-Sync-Signature (HMAC SHA256)
 *   Body: {"batch": [{"table": "vyrobky", "records": [...]}, ...]}
 *
 * Vrací JSON s stats: {accepted, updated, inserted, conflicts, errors}.
 */

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/_engine.php';

header('Content-Type: application/json; charset=UTF-8');
header('X-Robots-Tag: noindex');

$pdo = db();
$startTs = microtime(true);

try {
    // 1. Sync musí být enabled na mirror straně
    if (!sync_is_enabled($pdo)) {
        http_response_code(503);
        echo json_encode(['error' => 'Sync not enabled on this server']);
        exit;
    }

    // 2. Verify HMAC + parse body
    $verified = sync_hmac_verify();
    $payload = json_decode($verified['body'], true);
    if (!is_array($payload) || !isset($payload['batch'])) {
        throw new Exception('Missing "batch" in payload');
    }

    // 3. Apply batch
    $stats = sync_apply_batch($pdo, $payload['batch']);
    $duration = (int) ((microtime(true) - $startTs) * 1000);

    // 4. Log
    $status = empty($stats['errors']) ? 'success' : 'partial';
    sync_log(
        $pdo,
        'pull',  // From mirror's perspective: we are pulling/receiving
        $status,
        $stats['accepted'],
        $duration,
        empty($stats['errors']) ? null : implode("\n", $stats['errors'])
    );

    // 5. Update mirror's last_sync_at
    sync_update_last_sync($pdo, $status, 0, $stats['accepted']);

    echo json_encode([
        'ok' => true,
        'stats' => $stats,
        'duration_ms' => $duration,
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);

} catch (Throwable $e) {
    http_response_code(400);
    sync_log($pdo, 'pull', 'error', 0, (int)((microtime(true) - $startTs) * 1000), $e->getMessage());
    echo json_encode([
        'ok' => false,
        'error' => $e->getMessage(),
    ]);
}
