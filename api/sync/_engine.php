<?php
/**
 * 🔄 SYNC ENGINE — Core library pro hybrid local↔cloud sync
 *
 * Zodpovědnosti:
 *  - HMAC SHA256 podpis a verifikace requestů
 *  - Sběr záznamů k push (master) / pull (mirror)
 *  - Aplikace přijatých záznamů (idempotentně přes sync_uuid)
 *  - Conflict resolution (last-write-wins na updated_at)
 *
 * Použito z:
 *  - agent.php   — lokální cron / manuál
 *  - receive.php — cloud endpoint (přijímá od master)
 *  - pending.php — cloud endpoint (vydává nové orders mirror→master)
 *  - status.php  — admin info
 */

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../_sync_schema.php';

// ════════════════════════════════════════════════════════════
// KONFIGURACE — definice syncovatelných tabulek
// ════════════════════════════════════════════════════════════

/**
 * Vrátí seznam syncovaných tabulek + směr sync.
 * Direction:
 *   'push'      = jen master→mirror (např. faktury, DL)
 *   'pull'      = jen mirror→master (např. nové B2B objednávky)
 *   'both'      = obousměrný sync (např. objednávky — status updates)
 */
function sync_tables_config(): array {
    return [
        // Catalog — push only (master is source of truth)
        'kategorie_vyrobku'   => ['direction' => 'push', 'pk' => 'id'],
        'vyrobky'             => ['direction' => 'push', 'pk' => 'id'],
        'sazby_dph'           => ['direction' => 'push', 'pk' => 'id'],
        'jednotky'            => ['direction' => 'push', 'pk' => 'id'],

        // Customers — push only
        'odberatele'          => ['direction' => 'push', 'pk' => 'id'],
        'mista_dodani'        => ['direction' => 'push', 'pk' => 'id'],
        'cenove_skupiny'      => ['direction' => 'push', 'pk' => 'id'],

        // Orders — bidirectional (cloud creates new, master updates status)
        'objednavky'          => ['direction' => 'both', 'pk' => 'id'],
        'objednavky_polozky'  => ['direction' => 'both', 'pk' => 'id'],

        // Documents — push only (master creates DL/FA)
        'dodaci_listy'        => ['direction' => 'push', 'pk' => 'id'],
        'dodaci_list_polozky' => ['direction' => 'push', 'pk' => 'id'],
        'faktury'             => ['direction' => 'push', 'pk' => 'id'],
        'faktura_polozky'     => ['direction' => 'push', 'pk' => 'id'],

        // Ingredients — push only (optional)
        'suroviny'            => ['direction' => 'push', 'pk' => 'id'],
    ];
}

/**
 * Max records per HTTP batch (přizpůsobit dle rychlosti hostingu).
 */
const SYNC_BATCH_SIZE = 100;

/**
 * Max age requestu v sekundách (anti-replay protection).
 */
const SYNC_TIMESTAMP_WINDOW = 300; // 5 minut

// ════════════════════════════════════════════════════════════
// HMAC AUTENTIZACE
// ════════════════════════════════════════════════════════════

/**
 * Vygeneruje HMAC podpis pro request payload.
 */
function sync_hmac_sign(string $body, int $timestamp, string $secret): string {
    return hash_hmac('sha256', $timestamp . "\n" . $body, $secret);
}

/**
 * Ověří HMAC podpis příchozího requestu.
 * Vrátí true pokud OK, jinak vyhodí Exception s detailem.
 */
function sync_hmac_verify(): array {
    $pdo = db();
    $cfg = sync_get_config($pdo);
    if (empty($cfg['shared_secret'])) {
        throw new Exception('Sync není nakonfigurován (chybí shared_secret)');
    }

    $timestamp = (int) ($_SERVER['HTTP_X_SYNC_TIMESTAMP'] ?? 0);
    $signature = $_SERVER['HTTP_X_SYNC_SIGNATURE'] ?? '';

    if (!$timestamp || !$signature) {
        throw new Exception('Chybí X-Sync-Timestamp nebo X-Sync-Signature header');
    }

    // Anti-replay: timestamp musí být ±5 min od teď
    $age = abs(time() - $timestamp);
    if ($age > SYNC_TIMESTAMP_WINDOW) {
        throw new Exception("Request příliš starý nebo z budoucnosti (age={$age}s)");
    }

    $body = file_get_contents('php://input') ?: '';
    $expected = sync_hmac_sign($body, $timestamp, $cfg['shared_secret']);
    if (!hash_equals($expected, $signature)) {
        throw new Exception('Neplatný HMAC podpis');
    }

    return [
        'body'      => $body,
        'timestamp' => $timestamp,
        'config'    => $cfg,
    ];
}

// ════════════════════════════════════════════════════════════
// PUSH — Master sbírá záznamy k odeslání
// ════════════════════════════════════════════════════════════

/**
 * Vrátí batch záznamů, které čekají na push do cloudu.
 * Záznam = (updated_at > synced_at) nebo (synced_at IS NULL).
 *
 * @return array  ['table' => string, 'records' => array]
 */
function sync_collect_push_batch(PDO $pdo): array {
    $batch = [];
    $tables = sync_tables_config();
    $remaining = SYNC_BATCH_SIZE;

    foreach ($tables as $table => $opts) {
        if ($remaining <= 0) break;
        if ($opts['direction'] === 'pull') continue; // pull-only tabulky se nepushují

        // Zkontroluj existenci tabulky
        $exists = $pdo->query("SHOW TABLES LIKE " . $pdo->quote($table))->fetch();
        if (!$exists) continue;

        try {
            $stmt = $pdo->prepare("
                SELECT * FROM `$table`
                WHERE (synced_at IS NULL OR updated_at > synced_at)
                ORDER BY updated_at ASC
                LIMIT :lim
            ");
            $stmt->bindValue(':lim', $remaining, PDO::PARAM_INT);
            $stmt->execute();
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
            if ($rows) {
                $batch[] = [
                    'table'   => $table,
                    'records' => $rows,
                ];
                $remaining -= count($rows);
            }
        } catch (Throwable $e) {
            error_log("sync push collect $table: " . $e->getMessage());
        }
    }

    return $batch;
}

/**
 * Označí záznamy jako úspěšně syncnuté.
 */
function sync_mark_synced(PDO $pdo, string $table, array $uuids): void {
    if (empty($uuids)) return;
    $placeholders = implode(',', array_fill(0, count($uuids), '?'));
    try {
        $stmt = $pdo->prepare("UPDATE `$table` SET synced_at = NOW() WHERE sync_uuid IN ($placeholders)");
        $stmt->execute($uuids);
    } catch (Throwable $e) {
        error_log("sync_mark_synced $table: " . $e->getMessage());
    }
}

// ════════════════════════════════════════════════════════════
// APPLY — Mirror přijímá záznamy a vkládá/aktualizuje
// ════════════════════════════════════════════════════════════

/**
 * Aplikuje přijatý batch záznamů do lokální DB.
 * Idempotentní: pokud sync_uuid existuje, UPDATE; jinak INSERT.
 * Conflict resolution: last-write-wins na updated_at.
 *
 * @param array $batch Pole [{table, records: [...]}, ...]
 * @return array Stats [accepted, updated, inserted, conflicts, errors]
 */
function sync_apply_batch(PDO $pdo, array $batch): array {
    $stats = ['accepted' => 0, 'updated' => 0, 'inserted' => 0, 'conflicts' => 0, 'errors' => []];
    $allowedTables = array_keys(sync_tables_config());

    foreach ($batch as $item) {
        $table = $item['table'] ?? '';
        $records = $item['records'] ?? [];

        if (!in_array($table, $allowedTables, true)) {
            $stats['errors'][] = "Tabulka '$table' není povolena k sync";
            continue;
        }

        // Zjisti existující sloupce
        try {
            $cols = $pdo->query("
                SELECT COLUMN_NAME FROM information_schema.COLUMNS
                WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = " . $pdo->quote($table)
            )->fetchAll(PDO::FETCH_COLUMN);
            $colsSet = array_flip($cols);
        } catch (Throwable $e) {
            $stats['errors'][] = "Schema $table: " . $e->getMessage();
            continue;
        }

        foreach ($records as $rec) {
            if (empty($rec['sync_uuid'])) {
                $stats['errors'][] = "$table: záznam bez sync_uuid";
                continue;
            }

            // Vyfiltruj jen sloupce co existují v lokální tabulce
            $filtered = [];
            foreach ($rec as $k => $v) {
                if (isset($colsSet[$k])) $filtered[$k] = $v;
            }
            if (empty($filtered)) continue;

            // Zkontroluj zda už existuje
            $existsStmt = $pdo->prepare("SELECT id, updated_at FROM `$table` WHERE sync_uuid = :u LIMIT 1");
            $existsStmt->execute(['u' => $rec['sync_uuid']]);
            $existing = $existsStmt->fetch();

            try {
                if ($existing) {
                    // UPDATE — ale jen pokud incoming updated_at > existing (last-write-wins)
                    $incomingTs = isset($filtered['updated_at']) ? strtotime($filtered['updated_at']) : 0;
                    $existingTs = strtotime($existing['updated_at'] ?? '1970-01-01');

                    if ($incomingTs <= $existingTs) {
                        $stats['conflicts']++;
                        continue; // local má novější verzi, ignoruj
                    }

                    // Build UPDATE statement
                    $setParts = [];
                    $params = [];
                    foreach ($filtered as $k => $v) {
                        if ($k === 'id' || $k === 'sync_uuid') continue;
                        $setParts[] = "`$k` = :$k";
                        $params[$k] = $v;
                    }
                    $params['uuid'] = $rec['sync_uuid'];
                    $params['synced_at'] = date('Y-m-d H:i:s');
                    if (isset($colsSet['synced_at'])) {
                        $setParts[] = '`synced_at` = :synced_at';
                    }
                    $sql = "UPDATE `$table` SET " . implode(', ', $setParts) . " WHERE sync_uuid = :uuid";
                    $pdo->prepare($sql)->execute($params);
                    $stats['updated']++;
                } else {
                    // INSERT
                    $insertCols = array_keys($filtered);
                    if (isset($colsSet['synced_at']) && !in_array('synced_at', $insertCols)) {
                        $filtered['synced_at'] = date('Y-m-d H:i:s');
                        $insertCols[] = 'synced_at';
                    }
                    // Odebrat 'id' — nech auto-increment
                    $insertCols = array_filter($insertCols, fn($c) => $c !== 'id');
                    $filtered = array_intersect_key($filtered, array_flip($insertCols));

                    $colList = '`' . implode('`, `', $insertCols) . '`';
                    $placeholders = ':' . implode(', :', $insertCols);
                    $sql = "INSERT INTO `$table` ($colList) VALUES ($placeholders)";
                    $pdo->prepare($sql)->execute($filtered);
                    $stats['inserted']++;
                }
                $stats['accepted']++;
            } catch (Throwable $e) {
                $stats['errors'][] = "$table sync_uuid={$rec['sync_uuid']}: " . $e->getMessage();
            }
        }
    }

    return $stats;
}

// ════════════════════════════════════════════════════════════
// HTTP CLIENT — Master volá Mirror
// ════════════════════════════════════════════════════════════

/**
 * Pošle HTTPS request s HMAC podpisem.
 * @return array ['status' => int, 'body' => string, 'json' => array|null]
 */
function sync_http_request(string $url, string $method, array $body, string $secret, int $timeout = 30): array {
    $bodyJson = json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $timestamp = time();
    $signature = sync_hmac_sign($bodyJson, $timestamp, $secret);

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_CUSTOMREQUEST  => $method,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => $timeout,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_POSTFIELDS     => $bodyJson,
        CURLOPT_HTTPHEADER     => [
            'Content-Type: application/json',
            'Accept: application/json',
            'X-Sync-Timestamp: ' . $timestamp,
            'X-Sync-Signature: ' . $signature,
            'User-Agent: Appek-Sync/1.0',
        ],
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
    ]);
    $response = curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    curl_close($ch);

    if ($response === false) {
        throw new Exception("cURL error: $err");
    }

    $json = json_decode($response, true);
    return [
        'status' => $status,
        'body'   => $response,
        'json'   => $json,
    ];
}

// ════════════════════════════════════════════════════════════
// PULL — Master ptá Mirror na nové záznamy
// ════════════════════════════════════════════════════════════

/**
 * Pull nových B2B objednávek (a změn B2B hesel) z cloudu na master.
 * Cloud → Master přes GET /sync/pending.php?since=YYYY-MM-DD HH:MM:SS
 */
function sync_pull_from_cloud(PDO $pdo): array {
    $cfg = sync_get_config($pdo);
    if (empty($cfg['cloud_endpoint']) || empty($cfg['shared_secret'])) {
        throw new Exception('Sync není nakonfigurován');
    }
    $since = $cfg['last_sync_at'] ?? '1970-01-01 00:00:00';
    $url = rtrim($cfg['cloud_endpoint'], '/') . '/sync/pending.php';

    $r = sync_http_request($url, 'POST', ['since' => $since], $cfg['shared_secret']);
    if ($r['status'] !== 200) {
        throw new Exception("Cloud vrátil HTTP {$r['status']}: " . substr($r['body'], 0, 200));
    }
    if (!is_array($r['json']) || !isset($r['json']['batch'])) {
        throw new Exception('Neplatná odpověď z cloudu');
    }

    // Aplikuj batch lokálně
    return sync_apply_batch($pdo, $r['json']['batch']);
}

// ════════════════════════════════════════════════════════════
// PUSH — Master odesílá batch na Mirror
// ════════════════════════════════════════════════════════════

/**
 * Push batch nových/upravených záznamů na cloud.
 */
function sync_push_to_cloud(PDO $pdo): array {
    $cfg = sync_get_config($pdo);
    if (empty($cfg['cloud_endpoint']) || empty($cfg['shared_secret'])) {
        throw new Exception('Sync není nakonfigurován');
    }

    $batch = sync_collect_push_batch($pdo);
    if (empty($batch)) {
        return ['skipped' => 'no_pending_records', 'records' => 0];
    }

    $url = rtrim($cfg['cloud_endpoint'], '/') . '/sync/receive.php';
    $r = sync_http_request($url, 'POST', ['batch' => $batch], $cfg['shared_secret']);

    if ($r['status'] !== 200) {
        throw new Exception("Cloud vrátil HTTP {$r['status']}: " . substr($r['body'], 0, 200));
    }
    $stats = $r['json']['stats'] ?? [];

    // Označit záznamy jako synced
    $total = 0;
    foreach ($batch as $item) {
        $uuids = array_column($item['records'], 'sync_uuid');
        sync_mark_synced($pdo, $item['table'], $uuids);
        $total += count($uuids);
    }
    $stats['records_pushed'] = $total;
    return $stats;
}

// ════════════════════════════════════════════════════════════
// CONFIG UPDATE
// ════════════════════════════════════════════════════════════

/**
 * Aktualizuje sync_config po (ne)úspěšné sync operaci.
 */
function sync_update_last_sync(PDO $pdo, string $status, int $pushed = 0, int $pulled = 0, ?string $error = null): void {
    try {
        $stmt = $pdo->prepare("
            UPDATE sync_config SET
                last_sync_at = NOW(),
                last_sync_status = :s,
                last_sync_records_pushed = :p,
                last_sync_records_pulled = :pu,
                last_error = :e
            WHERE id = 1
        ");
        $stmt->execute([
            's'  => $status,
            'p'  => $pushed,
            'pu' => $pulled,
            'e'  => $error,
        ]);
    } catch (Throwable $e) { error_log('sync_update_last_sync: ' . $e->getMessage()); }
}

/**
 * Vrátí novou náhodnou shared secret.
 */
function sync_generate_secret(): string {
    return bin2hex(random_bytes(32));
}
