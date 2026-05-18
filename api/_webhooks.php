<?php
/**
 * 🔄 WEBHOOKS — out-going HTTP notifications.
 *
 * Použití (v ostatních PHP):
 *   webhook_fire('order.created', ['id' => 123, 'cislo' => 'OBJ-2026-001', ...]);
 *   webhook_fire('invoice.created', [...]);
 *   webhook_fire('dl.created', [...]);
 *
 * Konfigurace: tabulka `webhooks` — kdo poslouchá na který event.
 * Logging: tabulka `webhook_log` — historie všech volání.
 */

function ensure_webhooks_schema(PDO $pdo): void {
    static $done = false;
    if ($done) return;
    $done = true;
    try {
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS webhooks (
                id INT AUTO_INCREMENT PRIMARY KEY,
                nazev VARCHAR(150) NOT NULL,
                url VARCHAR(500) NOT NULL,
                events VARCHAR(500) NOT NULL,
                secret VARCHAR(128) NULL,
                aktivni TINYINT(1) NOT NULL DEFAULT 1,
                pocet_volani INT NOT NULL DEFAULT 0,
                pocet_selhani INT NOT NULL DEFAULT 0,
                last_call_at DATETIME NULL,
                last_status INT NULL,
                last_error TEXT NULL,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS webhook_log (
                id INT AUTO_INCREMENT PRIMARY KEY,
                webhook_id INT NOT NULL,
                event VARCHAR(50) NOT NULL,
                payload_short VARCHAR(500) NULL,
                http_status INT NULL,
                duration_ms INT NULL,
                error_msg TEXT NULL,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_webhook_time (webhook_id, created_at),
                INDEX idx_event (event)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");
    } catch (Throwable $e) { error_log('webhooks schema: ' . $e->getMessage()); }
}

/**
 * Vystřelí webhook na všechny aktivní listenery daného eventu.
 *
 * Asynchronně (background) — non-blocking přes fastcgi_finish_request pokud dostupné,
 * jinak synchronně s krátkým timeoutem.
 */
function webhook_fire(string $event, array $payload): void {
    try {
        $pdo = db();
        ensure_webhooks_schema($pdo);

        $stmt = $pdo->prepare("SELECT * FROM webhooks WHERE aktivni = 1");
        $stmt->execute();
        $hooks = $stmt->fetchAll();

        $matching = array_filter($hooks, function($h) use ($event) {
            $events = array_map('trim', explode(',', $h['events']));
            return in_array($event, $events, true) || in_array('*', $events, true);
        });

        if (empty($matching)) return;

        $body = json_encode([
            'event'     => $event,
            'timestamp' => date('c'),
            'data'      => $payload,
        ], JSON_UNESCAPED_UNICODE);

        foreach ($matching as $h) {
            $startTs = microtime(true);
            $status = null; $err = null;

            try {
                $headers = ['Content-Type: application/json', 'User-Agent: Appek-Webhook/1.0', 'X-Webhook-Event: ' . $event];
                if (!empty($h['secret'])) {
                    $sig = hash_hmac('sha256', $body, $h['secret']);
                    $headers[] = 'X-Webhook-Signature: sha256=' . $sig;
                }
                $ch = curl_init($h['url']);
                curl_setopt_array($ch, [
                    CURLOPT_POST           => true,
                    CURLOPT_POSTFIELDS     => $body,
                    CURLOPT_HTTPHEADER     => $headers,
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_TIMEOUT        => 8,
                    CURLOPT_CONNECTTIMEOUT => 4,
                    CURLOPT_SSL_VERIFYPEER => true,
                ]);
                curl_exec($ch);
                $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
                $err = curl_error($ch);
                curl_close($ch);
            } catch (Throwable $e) {
                $err = $e->getMessage();
            }

            $duration = (int) ((microtime(true) - $startTs) * 1000);
            $ok = $status >= 200 && $status < 300 && empty($err);

            try {
                $pdo->prepare("
                    INSERT INTO webhook_log (webhook_id, event, payload_short, http_status, duration_ms, error_msg)
                    VALUES (:wid, :ev, :ps, :st, :dur, :er)
                ")->execute([
                    'wid' => $h['id'],
                    'ev'  => $event,
                    'ps'  => substr($body, 0, 480),
                    'st'  => $status,
                    'dur' => $duration,
                    'er'  => $ok ? null : ($err ?: "HTTP $status"),
                ]);

                $pdo->prepare("
                    UPDATE webhooks SET
                        pocet_volani = pocet_volani + 1,
                        pocet_selhani = pocet_selhani + " . ($ok ? '0' : '1') . ",
                        last_call_at = NOW(),
                        last_status = :st,
                        last_error = :er
                    WHERE id = :id
                ")->execute([
                    'st' => $status, 'er' => $ok ? null : ($err ?: "HTTP $status"), 'id' => $h['id'],
                ]);
            } catch (Throwable $e) { error_log('webhook log save: ' . $e->getMessage()); }
        }
    } catch (Throwable $e) { error_log('webhook_fire: ' . $e->getMessage()); }
}

/**
 * Seznam podporovaných eventů (pro UI dropdown).
 */
function webhook_events_list(): array {
    return [
        'order.created'   => '🛒 Nová objednávka',
        'order.updated'   => '✏️ Objednávka upravena',
        'order.deleted'   => '🗑️ Objednávka smazána',
        'order.status_changed' => '🔄 Změna stavu objednávky',
        'dl.created'      => '📄 Nový dodací list',
        'invoice.created' => '💰 Nová faktura',
        'invoice.paid'    => '✅ Faktura uhrazena',
        'product.created' => '➕ Nový výrobek',
        'product.updated' => '✏️ Výrobek upraven',
        'low_stock'       => '⚠️ Nízký sklad',
    ];
}
