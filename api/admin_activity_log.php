<?php
/**
 * 📜 ACTIVITY LOG — sjednocený feed eventů (logins + sync + obj změny).
 *
 * GET /api/admin_activity_log.php?limit=50
 *   → { items: [{kind, who, action, detail, when, severity}, ...] }
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/_admin_auth.php';
cors_headers();
require_admin();
header('Content-Type: application/json; charset=UTF-8');

$pdo = db();
$limit = max(10, min(200, (int) ($_GET['limit'] ?? 50)));

$items = [];

// 1. Login pokusy
try {
    $rs = $pdo->query("
        SELECT email, ip, typ, uspesny, cas
        FROM prihlaseni_pokusy
        ORDER BY cas DESC LIMIT $limit
    ");
    foreach ($rs as $r) {
        $items[] = [
            'kind'     => 'login',
            'icon'     => $r['uspesny'] ? '🔓' : '🚫',
            'who'      => $r['email'] ?: '?',
            'action'   => $r['uspesny'] ? 'přihlášen' : 'NEÚSPĚŠNÉ přihlášení',
            'detail'   => "IP {$r['ip']} · typ {$r['typ']}",
            'when'     => $r['cas'],
            'severity' => $r['uspesny'] ? 'success' : 'warn',
        ];
    }
} catch (Throwable $e) { /* ignore */ }

// 2. Sync log
try {
    $rs = $pdo->query("
        SELECT direction, status, records_count, duration_ms, error_message, created_at
        FROM sync_log
        ORDER BY created_at DESC LIMIT $limit
    ");
    foreach ($rs as $r) {
        $items[] = [
            'kind'     => 'sync',
            'icon'     => '☁️',
            'who'      => 'sync',
            'action'   => "Sync {$r['direction']} · {$r['status']}",
            'detail'   => "{$r['records_count']} záznamů · {$r['duration_ms']}ms" . ($r['error_message'] ? " · ⚠️ " . mb_substr($r['error_message'], 0, 100) : ''),
            'when'     => $r['created_at'],
            'severity' => $r['status'] === 'success' ? 'success' : ($r['status'] === 'error' ? 'error' : 'warn'),
        ];
    }
} catch (Throwable $e) { /* ignore */ }

// 3. Objednavky změny (kdo upravil objednávku)
try {
    $rs = $pdo->query("
        SELECT oz.kdo_jmeno, oz.akce, oz.detail, oz.kdy,
               o.cislo AS obj_cislo
        FROM objednavky_zmeny oz
        LEFT JOIN objednavky o ON o.id = oz.objednavka_id
        ORDER BY oz.kdy DESC LIMIT $limit
    ");
    foreach ($rs as $r) {
        $items[] = [
            'kind'     => 'order_change',
            'icon'     => '📋',
            'who'      => $r['kdo_jmeno'] ?: 'systém',
            'action'   => "{$r['akce']} · obj #{$r['obj_cislo']}",
            'detail'   => mb_substr((string)$r['detail'], 0, 200),
            'when'     => $r['kdy'],
            'severity' => 'info',
        ];
    }
} catch (Throwable $e) { /* tabulka možná chybí */ }

// 4. Vendor audit (pokud existuje)
try {
    $rs = $pdo->query("
        SELECT username, action, target_key, details, created_at
        FROM vendor_audit_log
        ORDER BY created_at DESC LIMIT $limit
    ");
    foreach ($rs as $r) {
        $items[] = [
            'kind'     => 'vendor',
            'icon'     => '🏢',
            'who'      => $r['username'] ?: 'vendor',
            'action'   => "Vendor: {$r['action']}",
            'detail'   => ($r['target_key'] ? $r['target_key'] . ' · ' : '') . mb_substr((string)$r['details'], 0, 200),
            'when'     => $r['created_at'],
            'severity' => 'info',
        ];
    }
} catch (Throwable $e) { /* vendor tabulka neexistuje na customer instalaci */ }

// Sort by when desc
usort($items, fn($a, $b) => strcmp($b['when'] ?? '', $a['when'] ?? ''));
$items = array_slice($items, 0, $limit);

json_response(['items' => $items]);
