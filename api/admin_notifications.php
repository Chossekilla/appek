<?php
/**
 * 🔔 NOTIFICATIONS — inbox systém pro admin events.
 *
 * GET  /api/admin_notifications.php           → vrátí seznam s `unread_count`
 * GET  /api/admin_notifications.php?fresh=1   → auto-generuje nová z eventů (nové obj, low stock, …) + vrátí
 * POST /api/admin_notifications.php?action=read    body { id } nebo { all: true }
 * POST /api/admin_notifications.php?action=delete  body { id }
 *
 * Tabulka `notifications`: id, kind, title, msg, link, severity, is_read, created_at.
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/_admin_auth.php';
cors_headers();
require_admin();
header('Content-Type: application/json; charset=UTF-8');

$pdo = db();
$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? '';

// ════════════════════════════════════════════════════════════
// SCHEMA (idempotentní)
// ════════════════════════════════════════════════════════════
function ensure_notifications_schema(PDO $pdo): void {
    static $done = false;
    if ($done) return;
    $done = true;
    try {
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS notifications (
                id INT AUTO_INCREMENT PRIMARY KEY,
                kind VARCHAR(40) NOT NULL,
                title VARCHAR(200) NOT NULL,
                msg TEXT NULL,
                link VARCHAR(255) NULL,
                severity ENUM('info','success','warn','error') DEFAULT 'info',
                is_read TINYINT(1) NOT NULL DEFAULT 0,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_unread (is_read, created_at),
                INDEX idx_kind (kind, created_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");
        // 🆕 dismissed_at — soft-delete: smazaná notifikace zůstane v DB a NEREGENERUJE se
        try { $pdo->exec("ALTER TABLE notifications ADD COLUMN dismissed_at DATETIME NULL"); } catch (Throwable $e) { /* sloupec už existuje */ }
    } catch (Throwable $e) { error_log('notifications schema: ' . $e->getMessage()); }
}
ensure_notifications_schema($pdo);

// ════════════════════════════════════════════════════════════
// Auto-generování notifikací z eventů (idempotentně podle dedup key)
// ════════════════════════════════════════════════════════════
function notif_emit(PDO $pdo, string $kind, string $title, ?string $msg, ?string $link, string $severity, string $dedupKey = ''): void {
    try {
        // Idempotence: pokud stejný kind+title vznikl v posledních 24h, neopakuj.
        // 🐞 v2.9.102 FIX: dedup MUSÍ porovnávat se skutečným $title (to se ukládá
        // do sloupce title), NE s $dedupKey. U app_update / backup_stale / sync_error
        // je title ≠ dedupKey → shoda se nikdy nenašla → notifikace se množily
        // při každém ?fresh=1 (každých ~5 min). Teď dedup podle title = funguje.
        if ($dedupKey) {
            // Skip re-emit pokud stejná notifikace vznikla < 24h NEBO byla < 7 dnů odmítnuta (dismissed_at).
            // Tím se smazaná systémová notifikace (záloha, sync…) neskáče hned zpátky.
            $stmt = $pdo->prepare("
                SELECT 1 FROM notifications
                WHERE kind = :k AND title = :t
                  AND (created_at   > DATE_SUB(NOW(), INTERVAL 24 HOUR)
                    OR dismissed_at > DATE_SUB(NOW(), INTERVAL 7 DAY))
                LIMIT 1
            ");
            $stmt->execute(['k' => $kind, 't' => $title]);
            if ($stmt->fetchColumn()) return;
        }
        $pdo->prepare("
            INSERT INTO notifications (kind, title, msg, link, severity)
            VALUES (:k, :t, :m, :l, :s)
        ")->execute(['k' => $kind, 't' => $title, 'm' => $msg, 'l' => $link, 's' => $severity]);
    } catch (Throwable $e) { error_log('notif_emit: ' . $e->getMessage()); }
}

function generate_fresh_notifications(PDO $pdo): void {
    // 0. Úklid starých duplicit systémových notifikací — nech jen nejnovější per kind
    foreach (['backup_stale', 'sync_error'] as $sysKind) {
        try {
            $pdo->prepare("
                DELETE FROM notifications
                WHERE kind = :k
                  AND id < (SELECT mx FROM (SELECT MAX(id) AS mx FROM notifications WHERE kind = :k2) t)
            ")->execute(['k' => $sysKind, 'k2' => $sysKind]);
        } catch (Throwable $e) { /* ignore */ }
    }

    // 1. Nové objednávky (posledních 60 minut, jen jednou per obj)
    try {
        $rs = $pdo->query("
            SELECT id, cislo, castka_celkem FROM objednavky
            WHERE created_at > DATE_SUB(NOW(), INTERVAL 60 MINUTE)
        ");
        foreach ($rs as $o) {
            notif_emit(
                $pdo, 'order_new',
                'Nová objednávka #' . $o['cislo'],
                'Částka: ' . number_format($o['castka_celkem'], 2, ',', ' ') . ' Kč',
                '#/objednavky/' . $o['id'],
                'success',
                'Nová objednávka #' . $o['cislo']
            );
        }
    } catch (Throwable $e) { /* tabulka možná chybí */ }

    // 2. Nízký sklad surovin
    try {
        // 🐛 v3.0.187 — low-stock alert četl suroviny.sklad_stav/sklad_min, ALE odpis
        //   (pos_deduct_ingredients → surovina_recompute_total) i obrazovka Suroviny pracují
        //   se stock_aktualni/stock_minimalni. sklad_stav se po prodeji NEAKTUALIZOVAL →
        //   upozornění nereflektovala reálnou spotřebu (suroviny „nesedí"). Čteme teď živý
        //   sloupec = sjednoceno s odpisem i UI.
        $rs = $pdo->query("
            SELECT id, nazev, stock_aktualni, stock_minimalni FROM suroviny
            WHERE stock_minimalni IS NOT NULL AND stock_minimalni > 0
              AND stock_aktualni < stock_minimalni
              AND aktivni = 1
            LIMIT 10
        ");
        foreach ($rs as $s) {
            notif_emit(
                $pdo, 'low_stock',
                'Nízký sklad: ' . $s['nazev'],
                'Stav ' . rtrim(rtrim($s['stock_aktualni'], '0'), '.') . ' / min ' . rtrim(rtrim($s['stock_minimalni'], '0'), '.'),
                '#/suroviny/' . $s['id'],
                'warn',
                'Nízký sklad: ' . $s['nazev']
            );
        }
    } catch (Throwable $e) { /* ignore */ }

    // 3. Expirující licence (pokud existuje)
    if (defined('APP_LICENSE_KEY') && APP_LICENSE_KEY) {
        // Customer instance — licence neexpiruje ze své strany
        // (jen vendor panel ji vidí expirovat). Skip.
    }

    // 4. Sync errory za posledních 24h
    try {
        $errCount = (int) $pdo->query("
            SELECT COUNT(*) FROM sync_log
            WHERE status = 'error' AND created_at > DATE_SUB(NOW(), INTERVAL 24 HOUR)
        ")->fetchColumn();
        if ($errCount > 0) {
            notif_emit(
                $pdo, 'sync_error',
                "Sync hlásí chyby ($errCount × za 24h)",
                'Zkontroluj cloud připojení v Nastavení → Sync.',
                '#/nastaveni',
                'error',
                "sync errors $errCount"
            );
        }
    } catch (Throwable $e) { /* ignore */ }

    // 5. Žádné aktivní zálohy DB > 7 dnů
    try {
        $cols = $pdo->query("SHOW TABLES LIKE 'zalohy'")->fetch();
        if ($cols) {
            $last = $pdo->query("SELECT MAX(vytvoreno) FROM zalohy")->fetchColumn();
            if (!$last || strtotime($last) < strtotime('-7 days')) {
                notif_emit(
                    $pdo, 'backup_stale',
                    'Záloha DB je stará nebo neexistuje',
                    'Doporučujeme udělat zálohu — Nastavení → 💾 Zálohy.',
                    '#/nastaveni',
                    'warn',
                    'backup stale'
                );
            }
        }
    } catch (Throwable $e) { /* ignore */ }

    // 🆕 v2.0.71 — 6. Dostupný update aplikace
    // Volá public manifest endpoint na appek.cz, který čte vendor_updates DB.
    // Cache 1 hodina v notif tabulce (dedup key = update_available_VERSION).
    try {
        $current = defined('APP_VERSION') ? APP_VERSION : '0.0.0';
        $manifestUrl = 'https://appek.cz/updates/manifest.json';
        $ch = curl_init($manifestUrl);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 5,
            CURLOPT_CONNECTTIMEOUT => 3,
            CURLOPT_HTTPHEADER     => ['Accept: application/json'],
        ]);
        $body = curl_exec($ch);
        $http = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($body && $http < 400) {
            $manifest = json_decode($body, true);
            if (is_array($manifest) && !empty($manifest['latest_version'])) {
                $latest = (string) $manifest['latest_version'];
                if (version_compare($current, $latest, '<')) {
                    // Smaž starší update notifikace pro jiné verze (oneshot per latest)
                    try {
                        $pdo->prepare("
                            DELETE FROM notifications
                            WHERE kind = 'app_update' AND title NOT LIKE :keep
                        ")->execute(['keep' => '%' . $latest . '%']);
                    } catch (Throwable $e) { /* ignore */ }

                    $mb = number_format(((int)($manifest['size_bytes'] ?? 0)) / 1024 / 1024, 1, ',', ' ');
                    notif_emit(
                        $pdo, 'app_update',
                        "🆕 Dostupná nová verze {$latest}",
                        "Máš {$current}. Update ZIP {$mb} MB. Klikni pro detail.",
                        '#/nastaveni',
                        'info',
                        "update_available_{$latest}"  // dedup key = jedna notif per verze
                    );
                } else {
                    // 🐞 v2.9.102 FIX: jsme up-to-date ($current >= $latest) → smaž
                    // VŠECHNY app_update notifikace. Předtím se starý záznam „nová
                    // verze X" nikdy nesmazal, když jsi na verzi X dorazil → po
                    // updatu pořád otravovala notifikace „chce update".
                    try {
                        $pdo->prepare("DELETE FROM notifications WHERE kind = 'app_update'")->execute();
                    } catch (Throwable $e) { /* ignore */ }
                }
            }
        }
    } catch (Throwable $e) { /* network error / DNS — ignore */ }
}

// ════════════════════════════════════════════════════════════
// HANDLERS
// ════════════════════════════════════════════════════════════

if ($method === 'GET') {
    if (!empty($_GET['fresh'])) {
        generate_fresh_notifications($pdo);
    }
    $limit = max(5, min(100, (int)($_GET['limit'] ?? 30)));
    $stmt = $pdo->prepare("SELECT * FROM notifications WHERE dismissed_at IS NULL ORDER BY is_read ASC, created_at DESC LIMIT :l");
    $stmt->bindValue(':l', $limit, PDO::PARAM_INT);
    $stmt->execute();
    $rows = $stmt->fetchAll();
    $unread = (int) $pdo->query("SELECT COUNT(*) FROM notifications WHERE is_read = 0 AND dismissed_at IS NULL")->fetchColumn();
    json_response(['notifications' => $rows, 'unread_count' => $unread]);
}

if ($method === 'POST' && $action === 'read') {
    $d = json_input();
    if (!empty($d['all'])) {
        $pdo->exec("UPDATE notifications SET is_read = 1 WHERE is_read = 0");
    } elseif (!empty($d['id'])) {
        $pdo->prepare("UPDATE notifications SET is_read = 1 WHERE id = :id")->execute(['id' => (int)$d['id']]);
    } else {
        json_error('Chybí id nebo all=true', 400);
    }
    json_response(['ok' => true]);
}

if ($method === 'POST' && $action === 'delete') {
    $d = json_input();
    $id = (int) ($d['id'] ?? 0);
    if (!$id) json_error('Chybí id', 400);
    // Soft-delete — řádek zůstane s dismissed_at, takže systémová notifikace se NEREGENERUJE.
    $pdo->prepare("UPDATE notifications SET dismissed_at = NOW(), is_read = 1 WHERE id = :id")->execute(['id' => $id]);
    json_response(['ok' => true]);
}

if ($method === 'POST' && $action === 'clear_read') {
    $pdo->exec("DELETE FROM notifications WHERE is_read = 1 AND created_at < DATE_SUB(NOW(), INTERVAL 7 DAY)");
    json_response(['ok' => true]);
}

json_error('Neznámá akce', 404);
