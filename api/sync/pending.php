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
    // 📍 kurzor PRO KAŽDOU TABULKU = poslední vydaný (updated_at, id) — stránkování podle klíče.
    //   Dřív jeden společný čas: záznamy nad limit dávky a záznamy se stejným časem (hromadná úprava/import)
    //   se přeskočily nebo stahování uvázlo. Tabulka, na kterou v dávce nevyšlo místo, pokračuje příště od svého kurzoru.
    $kurzor = is_array($payload['kurzor'] ?? null) ? $payload['kurzor'] : [];
    $novyKurzor = [];

    foreach ($tables as $table => $opts) {
        // Pull only 'pull' or 'both' directions (mirror returns these)
        if ($opts['direction'] === 'push') continue;
        if ($totalRecords >= SYNC_BATCH_SIZE) break;   // zbytek příště — kurzory nevydaných tabulek se nemění

        $exists = $pdo->query("SHOW TABLES LIKE " . $pdo->quote($table))->fetch();
        if (!$exists) continue;

        [$kTs, $kId] = [$since, 0];
        $k = $kurzor[$table] ?? null;
        if (is_array($k) && isset($k[0], $k[1]) && preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', (string) $k[0])) [$kTs, $kId] = [(string) $k[0], (int) $k[1]];

        $limit = SYNC_BATCH_SIZE - $totalRecords;
        try {
            $stmt = $pdo->prepare("
                SELECT * FROM `$table`
                WHERE updated_at > :ts OR (updated_at = :ts2 AND id > :id)
                ORDER BY updated_at ASC, id ASC
                LIMIT :lim
            ");
            $stmt->bindValue(':ts', $kTs);
            $stmt->bindValue(':ts2', $kTs);
            $stmt->bindValue(':id', $kId, PDO::PARAM_INT);
            $stmt->bindValue(':lim', $limit, PDO::PARAM_INT);
            $stmt->execute();
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
            if ($rows) {
                $batch[] = ['table' => $table, 'records' => sync_attach_refs($pdo, $table, $rows)];   // 🔗 uuid rodičů
                $totalRecords += count($rows);
                $last = end($rows);
                $novyKurzor[$table] = [(string) $last['updated_at'], (int) $last['id']];
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
        'kurzor' => $novyKurzor + $kurzor,   // kam smí master posunout kurzory (vydané tabulky nově, ostatní beze změny)
        'duration_ms' => $duration,
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);

} catch (Throwable $e) {
    http_response_code(400);
    sync_log($pdo, 'push', 'error', 0, (int)((microtime(true) - $startTs) * 1000), $e->getMessage());
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
}
