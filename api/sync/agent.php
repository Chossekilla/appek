<?php
/**
 * 🤖 SYNC AGENT — runs on LOCAL/master to push to cloud + pull new orders
 *
 * Spouští se:
 *  - Z CLI přes cron: každých 15 minut · php /var/www/appek/api/sync/agent.php
 *  - Z web (manuální button v adminu): /api/sync/agent.php?manual=1
 *
 * Posloupnost:
 *  1. Push outbound records to cloud (master → mirror)
 *  2. Pull new records from cloud (mirror → master)
 *  3. Update sync_log + sync_config.last_sync_at
 */

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/_engine.php';

$pdo = db();
$startTs = microtime(true);
$cfg = sync_get_config($pdo);

// Detekce CLI vs web
$isCLI = (php_sapi_name() === 'cli');
$direction = $isCLI ? 'cron' : 'manual';

// Web volání vyžaduje admin login (kromě CLI)
if (!$isCLI) {
    require_once __DIR__ . '/../_admin_auth.php';
    cors_headers();
    require_admin();
}

$result = [
    'enabled'      => false,
    'mode'         => $cfg['mode'] ?? 'cloud',
    'role'         => $cfg['role'] ?? 'master',
    'records_pushed' => 0,
    'records_pulled' => 0,
    'errors'       => [],
    'duration_ms'  => 0,
];

try {
    if (!sync_is_enabled($pdo)) {
        $result['skipped'] = 'sync_disabled';
        echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        exit;
    }
    $result['enabled'] = true;

    // Jen master pushuje a pullsuje. Mirror jen přijímá requesty (přes receive.php).
    if (($cfg['role'] ?? 'master') !== 'master') {
        $result['skipped'] = 'mirror_role_doesnt_push';
        echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        exit;
    }

    if (empty($cfg['cloud_endpoint']) || empty($cfg['shared_secret'])) {
        $result['errors'][] = 'Sync není nakonfigurován (cloud_endpoint/shared_secret chybí)';
        sync_update_last_sync($pdo, 'error', 0, 0, 'Missing config');
        echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        exit;
    }

    // 1. PUSH (master → mirror)
    try {
        $pushStats = sync_push_to_cloud($pdo);
        $result['records_pushed'] = $pushStats['records_pushed'] ?? 0;
        $result['push'] = $pushStats;
    } catch (Throwable $e) {
        $result['errors'][] = 'PUSH failed: ' . $e->getMessage();
    }

    // 2. PULL (master ← mirror)
    try {
        $pullStats = sync_pull_from_cloud($pdo);
        $result['records_pulled'] = $pullStats['accepted'] ?? 0;
        $result['pull'] = $pullStats;
    } catch (Throwable $e) {
        $result['errors'][] = 'PULL failed: ' . $e->getMessage();
    }

    $result['duration_ms'] = (int) ((microtime(true) - $startTs) * 1000);
    $status = empty($result['errors']) ? 'success' : (($result['records_pushed'] + $result['records_pulled'] > 0) ? 'partial' : 'error');

    // 3. UPDATE config + log
    sync_update_last_sync(
        $pdo,
        $status,
        $result['records_pushed'],
        $result['records_pulled'],
        empty($result['errors']) ? null : implode("\n", $result['errors'])
    );
    sync_log(
        $pdo,
        $direction,
        $status,
        $result['records_pushed'] + $result['records_pulled'],
        $result['duration_ms'],
        empty($result['errors']) ? null : implode("\n", $result['errors']),
        json_encode($result, JSON_UNESCAPED_UNICODE)
    );

} catch (Throwable $e) {
    $result['errors'][] = 'FATAL: ' . $e->getMessage();
    $result['duration_ms'] = (int) ((microtime(true) - $startTs) * 1000);
    sync_update_last_sync($pdo, 'error', 0, 0, $e->getMessage());
    sync_log($pdo, $direction, 'error', 0, $result['duration_ms'], $e->getMessage());
}

if ($isCLI) {
    echo "Sync agent finished in {$result['duration_ms']}ms\n";
    echo "  Pushed: {$result['records_pushed']}\n";
    echo "  Pulled: {$result['records_pulled']}\n";
    if (!empty($result['errors'])) {
        echo "  Errors:\n";
        foreach ($result['errors'] as $err) echo "    - $err\n";
    }
} else {
    header('Content-Type: application/json; charset=UTF-8');
    echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
}
