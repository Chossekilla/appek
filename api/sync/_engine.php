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
        // 'fk' = cizí klíč → synchronizovaná rodičovská tabulka. ID se mezi uzly liší (auto-increment),
        //   proto se posílá sync_uuid rodiče (__ref) a příjemce ho přeloží na své vlastní ID (sync_apply_batch).
        //   Pořadí tabulek = pořadí odesílání (rodiče před dětmi).

        // Catalog — push only (master is source of truth)
        'sazby_dph'           => ['direction' => 'push', 'pk' => 'id'],
        'jednotky'            => ['direction' => 'push', 'pk' => 'id'],
        'kategorie_vyrobku'   => ['direction' => 'push', 'pk' => 'id', 'fk' => ['parent_id' => 'kategorie_vyrobku']],
        'vyrobky'             => ['direction' => 'push', 'pk' => 'id', 'fk' => ['kategorie_id' => 'kategorie_vyrobku', 'jednotka_id' => 'jednotky', 'sazba_dph_id' => 'sazby_dph']],
        // Ingredients — push only (optional)
        'suroviny'            => ['direction' => 'push', 'pk' => 'id'],

        // Customers — push only
        'cenove_skupiny'      => ['direction' => 'push', 'pk' => 'id'],
        'odberatele'          => ['direction' => 'push', 'pk' => 'id', 'fk' => ['cenova_skupina_id' => 'cenove_skupiny']],
        'mista_dodani'        => ['direction' => 'push', 'pk' => 'id', 'fk' => ['odberatel_id' => 'odberatele']],

        // Orders — bidirectional (cloud creates new, master updates status)
        'objednavky'          => ['direction' => 'both', 'pk' => 'id', 'fk' => ['odberatel_id' => 'odberatele', 'misto_dodani_id' => 'mista_dodani']],
        'objednavky_polozky'  => ['direction' => 'both', 'pk' => 'id', 'fk' => ['objednavka_id' => 'objednavky', 'vyrobek_id' => 'vyrobky', 'surovina_id' => 'suroviny', 'vraci_polozku_id' => 'objednavky_polozky']],

        // Documents — push only (master creates DL/FA)
        'dodaci_listy'        => ['direction' => 'push', 'pk' => 'id', 'fk' => ['objednavka_id' => 'objednavky', 'odberatel_id' => 'odberatele', 'misto_dodani_id' => 'mista_dodani']],
        'dodaci_list_polozky' => ['direction' => 'push', 'pk' => 'id', 'fk' => ['dodaci_list_id' => 'dodaci_listy', 'vyrobek_id' => 'vyrobky']],
        'faktury'             => ['direction' => 'push', 'pk' => 'id', 'fk' => ['odberatel_id' => 'odberatele', 'misto_dodani_id' => 'mista_dodani', 'puvodni_faktura_id' => 'faktury']],
        'faktura_polozky'     => ['direction' => 'push', 'pk' => 'id', 'fk' => ['faktura_id' => 'faktury', 'vyrobek_id' => 'vyrobky', 'vraci_polozku_id' => 'faktura_polozky']],
    ];
}

/**
 * Přiloží k záznamům sync_uuid rodičů (__ref: {sloupec: uuid}) — ID se mezi uzly liší.
 * Rodič bez sync_uuid / neexistující → bez __ref (příjemce nechá ID, jak je — starší chování).
 */
function sync_attach_refs(PDO $pdo, string $table, array $rows): array {
    $fk = sync_tables_config()[$table]['fk'] ?? [];
    if (!$fk || !$rows) return $rows;
    foreach ($fk as $col => $parent) {
        $ids = array_values(array_unique(array_filter(array_map(fn($r) => (int) ($r[$col] ?? 0), $rows))));
        if (!$ids) continue;
        $mapa = [];
        try {
            $st = $pdo->query("SELECT id, sync_uuid FROM `$parent` WHERE id IN (" . implode(',', $ids) . ") AND sync_uuid IS NOT NULL");
            foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $p) $mapa[(int) $p['id']] = $p['sync_uuid'];
        } catch (Throwable $e) { continue; }   // rodičovská tabulka na tomto uzlu chybí
        foreach ($rows as &$r) {
            $id = (int) ($r[$col] ?? 0);
            if ($id && isset($mapa[$id])) $r['__ref'][$col] = $mapa[$id];
        }
        unset($r);
    }
    return $rows;
}

/**
 * 🔢 Přijatý doklad s číslem (WEB-2026-0048) posune místní číselnou řadu (tabulka cislovani) za sebe,
 * aby další vlastní doklad nedostal stejné číslo. Řada se pozná podle nejdelšího odpovídajícího předčíslí.
 */
function sync_cislo_posun(PDO $pdo, string $cislo): void {
    static $rady = null;
    if ($cislo === '') return;
    try {
        if ($rady === null) $rady = $pdo->query("SELECT typ, rok, predcisli FROM cislovani")->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) { $rady = []; return; }
    $best = null; $uzel = '';
    foreach ($rady as $r) {
        $p = (string) $r['predcisli'];
        if ($p === '' || !str_starts_with($cislo, $p)) continue;
        $zbytek = substr($cislo, strlen($p));
        // „0048“ = stejná řada; „C0048“ = řada druhého uzlu (cloud) se stejným základem → vede se zvlášť (typ@C)
        if (!preg_match('/^([A-Z]?)(\d+)$/', $zbytek, $m)) continue;
        if (str_contains((string) $r['typ'], '@') && $m[1] !== '') continue;   // řada uzlu sama sebe neodvozuje
        if (!$best || strlen($p) > strlen($best['predcisli'])) { $best = $r; $uzel = $m[1]; }
    }
    if (!$best) return;
    $n = (int) substr($cislo, strlen($best['predcisli']) + strlen($uzel));
    try {
        if ($uzel === '') {
            $pdo->prepare("UPDATE cislovani SET posledni = GREATEST(posledni, :n) WHERE typ = :t AND rok = :r")
                ->execute(['n' => $n, 't' => $best['typ'], 'r' => $best['rok']]);
        } else {
            // řada druhého uzlu (WEB@C) — ať ji zná i tahle databáze (kopie / obnova ze zálohy pak nečísluje znovu od 1)
            $t = $best['typ'] . '@' . $uzel; $p = $best['predcisli'] . $uzel;
            $pdo->prepare("INSERT IGNORE INTO cislovani (typ, rok, predcisli, posledni) VALUES (:t, :r, :p, 0)")->execute(['t' => $t, 'r' => $best['rok'], 'p' => $p]);
            $pdo->prepare("UPDATE cislovani SET posledni = GREATEST(posledni, :n) WHERE typ = :t AND rok = :r")->execute(['n' => $n, 't' => $t, 'r' => $best['rok']]);
        }
    } catch (Throwable $e) { error_log('sync_cislo_posun: ' . $e->getMessage()); }
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
                    'records' => sync_attach_refs($pdo, $table, $rows),   // 🔗 uuid rodičů pro překlad cizích klíčů
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
        // 🐛 updated_at = updated_at: sloupec má ON UPDATE CURRENT_TIMESTAMP → samotné označení „odesláno“
        //   přepsalo čas poslední změny u všech odeslaných záznamů (rozbité last-write-wins i „upraveno“ v UI)
        $stmt = $pdo->prepare("UPDATE `$table` SET synced_at = NOW(), updated_at = updated_at WHERE sync_uuid IN ($placeholders)");
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
    // 'failed' = sync_uuid záznamů, které se nepodařilo zapsat → odesílatel je neoznačí jako synced a pošle je znovu
    $stats = ['accepted' => 0, 'updated' => 0, 'inserted' => 0, 'conflicts' => 0, 'errors' => [], 'failed' => []];
    $allowedTables = array_keys(sync_tables_config());
    $refCache = [];   // "tabulka|uuid" → místní ID (rodiče v jedné dávce se nedohledávají opakovaně)

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

            // sync_uuid rodičů (__ref) — přeloží se na místní ID níž (ID se mezi uzly liší)
            $refs = is_array($rec['__ref'] ?? null) ? $rec['__ref'] : [];
            unset($rec['__ref']);

            // Vyfiltruj jen sloupce co existují v lokální tabulce
            $filtered = [];
            foreach ($rec as $k => $v) {
                if (isset($colsSet[$k])) $filtered[$k] = $v;
            }
            if (empty($filtered)) continue;

            // Zkontroluj zda už existuje
            $existsStmt = $pdo->prepare("SELECT id, updated_at FROM `$table` WHERE sync_uuid = :u LIMIT 1");
            $existsStmt->execute(['u' => $rec['sync_uuid']]);
            $existing = $existsStmt->fetch(PDO::FETCH_ASSOC);

            try {
                // 🔗 cizí klíče: uuid rodiče → místní ID. Rodič ještě nedorazil → záznam selže a zkusí se znovu příště
                //   (dřív zůstalo ID odesílatele → položka bez objednávky, nebo přilepená k cizí objednávce)
                $fkCfg = sync_tables_config()[$table]['fk'] ?? [];
                foreach ($refs as $col => $puuid) {
                    if (!isset($colsSet[$col]) || empty($fkCfg[$col]) || !is_string($puuid) || $puuid === '') continue;
                    $key = $fkCfg[$col] . '|' . $puuid;
                    if (!array_key_exists($key, $refCache)) {
                        $ps = $pdo->prepare("SELECT id FROM `{$fkCfg[$col]}` WHERE sync_uuid = :u LIMIT 1");
                        $ps->execute(['u' => $puuid]);
                        $refCache[$key] = ($v = $ps->fetchColumn()) !== false ? (int) $v : null;
                    }
                    if ($refCache[$key] === null) throw new Exception("chybí nadřazený záznam {$fkCfg[$col]} ($puuid) — zkusí se znovu");
                    $filtered[$col] = $refCache[$key];
                }

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
                        // 🐛 synced_at nastavuje přijímající strana (níž) — dřív byl v SET dvakrát
                        //   → PDO „HY093 Invalid parameter number“ a KAŽDÁ aktualizace existujícího záznamu selhala
                        if ($k === 'id' || $k === 'sync_uuid' || $k === 'synced_at') continue;
                        $setParts[] = "`$k` = :$k";
                        $params[$k] = $v;
                    }
                    $params['uuid'] = $rec['sync_uuid'];
                    if (isset($colsSet['synced_at'])) {   // parametr jen se sloupcem (jinak HY093)
                        $setParts[] = '`synced_at` = :synced_at';
                        $params['synced_at'] = date('Y-m-d H:i:s');
                    }
                    // updated_at bez hodnoty od odesílatele → zachovat (ON UPDATE CURRENT_TIMESTAMP by ho přepsal)
                    if (isset($colsSet['updated_at']) && !array_key_exists('updated_at', $params)) $setParts[] = '`updated_at` = `updated_at`';
                    $sql = "UPDATE `$table` SET " . implode(', ', $setParts) . " WHERE sync_uuid = :uuid";
                    $pdo->prepare($sql)->execute($params);
                    $stats['updated']++;
                } else {
                    // INSERT
                    if (isset($colsSet['synced_at'])) $filtered['synced_at'] = date('Y-m-d H:i:s');   // čas přijetí, ne odesílatele
                    $insertCols = array_keys($filtered);
                    // Odebrat 'id' — nech auto-increment
                    $insertCols = array_filter($insertCols, fn($c) => $c !== 'id');
                    $filtered = array_intersect_key($filtered, array_flip($insertCols));

                    $colList = '`' . implode('`, `', $insertCols) . '`';
                    $placeholders = ':' . implode(', :', $insertCols);
                    $sql = "INSERT INTO `$table` ($colList) VALUES ($placeholders)";
                    $pdo->prepare($sql)->execute($filtered);
                    $stats['inserted']++;
                    // nový rodič v téže dávce → děti ho najdou bez dalšího dotazu
                    $refCache[$table . '|' . $rec['sync_uuid']] = (int) $pdo->lastInsertId();
                }
                // 🔢 přijaté číslo dokladu posune místní řadu (jinak by další vlastní doklad dostal stejné číslo)
                if (!empty($filtered['cislo'])) sync_cislo_posun($pdo, (string) $filtered['cislo']);
                $stats['accepted']++;
            } catch (Throwable $e) {
                $stats['errors'][] = "$table sync_uuid={$rec['sync_uuid']}: " . $e->getMessage();
                $stats['failed'][] = (string) $rec['sync_uuid'];
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
    // 📍 kurzor pro každou tabulku = poslední stažený (updated_at, id) V CLOUDU (stránkování podle klíče).
    //   Dřív jeden čas posledního běhu masteru → záznamy nad limit dávky, se stejným časem nebo ty, které selhaly,
    //   se už nikdy nestáhly.
    $kurzor = json_decode((string) ($cfg['pull_kurzor'] ?? ''), true);
    if (!is_array($kurzor)) $kurzor = [];
    $url = rtrim($cfg['cloud_endpoint'], '/') . '/sync/pending.php';

    $r = sync_http_request($url, 'POST', ['since' => '1970-01-01 00:00:00', 'kurzor' => (object) $kurzor], $cfg['shared_secret']);
    if ($r['status'] !== 200) {
        throw new Exception("Cloud vrátil HTTP {$r['status']}: " . substr($r['body'], 0, 200));
    }
    if (!is_array($r['json']) || !isset($r['json']['batch'])) {
        throw new Exception('Neplatná odpověď z cloudu');
    }
    if (!is_array($r['json']['kurzor'] ?? null)) {
        throw new Exception('Cloud má starší verzi synchronizace (chybí kurzor) — aktualizujte obě instalace na stejnou verzi');
    }

    // Aplikuj batch lokálně
    $batch = $r['json']['batch'];
    $stats = sync_apply_batch($pdo, $batch);

    // nové kurzory od cloudu; tabulka se záznamem, který se nepodařilo zapsat, jen těsně před něj (zkusí se znovu)
    $novy = $r['json']['kurzor'];
    $failed = array_flip(array_map('strval', $stats['failed'] ?? []));
    foreach ($batch as $item) {
        $t = (string) ($item['table'] ?? '');
        foreach ((array) ($item['records'] ?? []) as $rec) {
            if (!isset($failed[(string) ($rec['sync_uuid'] ?? '')]) || empty($rec['updated_at'])) continue;
            $pred = [(string) $rec['updated_at'], (int) ($rec['id'] ?? 0) - 1];
            $cur = $novy[$t] ?? null;
            if (!is_array($cur) || $pred[0] < $cur[0] || ($pred[0] === $cur[0] && $pred[1] < (int) $cur[1])) $novy[$t] = $pred;
        }
    }
    try { $pdo->prepare("UPDATE sync_config SET pull_kurzor = :k WHERE id = 1")->execute(['k' => json_encode($novy)]); }
    catch (Throwable $e) { error_log('sync pull_kurzor: ' . $e->getMessage()); }
    return $stats;
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

    // Označit záznamy jako synced — JEN ty, které cloud opravdu přijal (odmítnuté se pošlou znovu příště;
    // dřív se označilo vše a změna, kterou cloud nezapsal, se tiše ztratila)
    $failed = array_flip(array_map('strval', (array) ($stats['failed'] ?? [])));
    $total = 0;
    foreach ($batch as $item) {
        $uuids = array_values(array_filter(array_column($item['records'], 'sync_uuid'), fn($u) => !isset($failed[(string) $u])));
        if ($uuids) sync_mark_synced($pdo, $item['table'], $uuids);
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
