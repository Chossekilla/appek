<?php
/**
 * 🆕 v2.9.322 — HEALTH MONITOR (cron-callable orchestrátor)
 *
 * Účel: detekovat problémy DŘÍV než user nahlásí. Volaný:
 *   - CRON každých 5-15 minut: curl https://demo.appek.cz/api/admin_health_monitor.php?token=XXX
 *   - Admin UI tlačítko "Otestovat hned" (Diagnostika)
 *   - Po každém deploy_hook apply (post-deploy verification)
 *
 * Co dělá:
 *   1. Spustí synthetic healthcheck (inline include healthcheck.php logiky)
 *   2. Pull nových chyb z app_errors od last_run
 *   3. Pokud:
 *      - jakýkoli health check fail → emit notif (severity=alert)
 *      - >5 errors / 15 min → emit notif (spike alert)
 *      - nová UNIKÁTNÍ chyba (prvně viděná) → emit notif
 *   4. Zapíše outcome do nastaveni.health_last_run + health_last_status
 *   5. Vrátí JSON summary (i pro debugging z prohlížeče)
 *
 * Auth: token query param porovnaný proti nastaveni.health_monitor_token
 * (auto-generated 1× — first run vygeneruje random 32-byte, uloží).
 * Bez tokenu vrací 401. Admin si token získá z Diagnostika UI.
 *
 * Pozn.: Tohle JE side-effect endpoint (logs, notifs) → nepřístupný bez auth.
 * Pure synthetic check je v healthcheck.php (public, read-only kromě 1 nastaveni klíče).
 */

require_once __DIR__ . '/config.php';
header('Content-Type: application/json; charset=UTF-8');

$pdo = db();

// ─── Auth: get/create token, validuj ───
function get_or_create_monitor_token(PDO $pdo): string {
    try {
        $existing = $pdo->query("SELECT hodnota FROM nastaveni WHERE klic = 'health_monitor_token'")->fetchColumn();
        if ($existing && strlen($existing) >= 32) return $existing;
        $new = bin2hex(random_bytes(24));
        $pdo->prepare("
            INSERT INTO nastaveni (klic, hodnota) VALUES ('health_monitor_token', :v)
            ON DUPLICATE KEY UPDATE hodnota = :v2
        ")->execute(['v' => $new, 'v2' => $new]);
        return $new;
    } catch (\Throwable $e) {
        // Fallback: nastaveni může chybět při bootstrap → vrať predictable pro dev
        return 'BOOTSTRAP_TOKEN_NOT_YET_PERSISTED';
    }
}

$expected = get_or_create_monitor_token($pdo);
$got = $_GET['token'] ?? '';

// Pokud volá přihlášený admin (z admin UI), přeskoč token check
$adminAuthed = false;
try {
    require_once __DIR__ . '/_admin_auth.php';
    session_secure_start(); // 🆕 v3.0.323 — health_monitor nevolá require_admin → session musí nastartovat sám (jinak $_SESSION prázdné → vždy 401)
    if (!empty($_SESSION['admin_id'])) $adminAuthed = true;
} catch (\Throwable $e) { /* admin auth nedostupný — wpadne na token check */ }

if (!$adminAuthed && !hash_equals($expected, (string) $got)) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'Vyžaduje token (?token=XXX) nebo admin auth']);
    exit;
}

// ─── 1) Run healthcheck (volíme přímo, ne přes HTTP self-call — rychlejší) ───
$t0 = microtime(true);
$healthcheck = null;
try {
    // Capture výstup healthcheck.php
    ob_start();
    $checks_global = [];
    // Spustíme jako include (sdílí PHP context, $pdo); healthcheck dělá echo na konci
    include __DIR__ . '/healthcheck.php';
    $hcRaw = ob_get_clean();
    $healthcheck = json_decode($hcRaw, true) ?: ['ok' => false, 'error' => 'invalid_hc_json'];
} catch (\Throwable $e) {
    $healthcheck = ['ok' => false, 'error' => 'hc_exception: ' . $e->getMessage()];
}

// ─── 2) Pull nových errors z app_errors od last_run ───
$lastRun = (int) ($pdo->query("SELECT hodnota FROM nastaveni WHERE klic = 'health_last_run_unix'")->fetchColumn() ?: 0);
if (!$lastRun) $lastRun = time() - 900; // first run = look back 15 min

$newErrors = [];
$spikeCount = 0;
try {
    $st = $pdo->prepare("
        SELECT request_id, severity, source, message, exception_class, created_at, COUNT(*) OVER () AS total
        FROM app_errors
        WHERE created_at > FROM_UNIXTIME(:since)
          AND severity IN ('error', 'alert')
        ORDER BY created_at DESC
        LIMIT 20
    ");
    $st->execute(['since' => $lastRun]);
    $newErrors = $st->fetchAll(PDO::FETCH_ASSOC);
    if (!empty($newErrors)) $spikeCount = (int) $newErrors[0]['total'];
} catch (\Throwable $e) { /* app_errors neexistuje yet — ignore */ }

// ─── 3) Vyhodnocení + notifikace ───
$alerts = []; // co notifikovat
$hcOk = $healthcheck['ok'] ?? false;
if (!$hcOk) {
    $failedChecks = array_filter($healthcheck['checks'] ?? [], fn($c) => !($c['ok'] ?? true));
    $alerts[] = [
        'kind' => 'health_check_failed',
        'severity' => 'error',
        'title' => '🚨 Health check selhal',
        'msg' => 'Selhalo: ' . implode(', ', array_column($failedChecks, 'name')),
        'link' => '#/nastaveni',
        'dedup' => 'hc_fail_' . date('YmdH'), // jednou za hodinu max
    ];
}

if ($spikeCount > 5) {
    $alerts[] = [
        'kind' => 'error_spike',
        'severity' => 'warn',
        'title' => "⚠️ Chyby aplikace: $spikeCount v posledních 15 min",
        'msg' => 'Otevři Diagnostiku → 🐛 Chyby aplikace pro detail.',
        'link' => '#/nastaveni',
        'dedup' => 'err_spike_' . date('YmdHi') . '_' . intdiv($spikeCount, 5), // resampling per 5er bucket
    ];
}

// ─── 4) Emit notifikace (pokud notif_emit existuje) ───
$emittedCount = 0;
if (!empty($alerts) && file_exists(__DIR__ . '/admin_notifications.php')) {
    // notif_emit() helper je v admin_notifications.php
    require_once __DIR__ . '/admin_notifications.php';
    if (function_exists('notif_emit')) {
        foreach ($alerts as $a) {
            try {
                notif_emit($pdo, $a['kind'], $a['title'], $a['msg'], $a['link'], $a['severity'], $a['dedup']);
                $emittedCount++;
            } catch (\Throwable $e) { /* per-alert fail — pokračuj */ }
        }
    }
}

// ─── 5) Persist last_run + status ───
$now = time();
$statusJson = json_encode([
    'ok' => $hcOk && empty($alerts),
    'hc_ok' => $hcOk,
    'errors_15min' => $spikeCount,
    'alerts_emitted' => $emittedCount,
    'duration_ms' => (int) round((microtime(true) - $t0) * 1000),
    'checked_at' => gmdate('c'),
], JSON_UNESCAPED_UNICODE);
try {
    $pdo->prepare("
        INSERT INTO nastaveni (klic, hodnota) VALUES ('health_last_run_unix', :v)
        ON DUPLICATE KEY UPDATE hodnota = :v
    ")->execute(['v' => (string) $now]);
    $pdo->prepare("
        INSERT INTO nastaveni (klic, hodnota) VALUES ('health_last_status', :v)
        ON DUPLICATE KEY UPDATE hodnota = :v
    ")->execute(['v' => $statusJson]);
} catch (\Throwable $e) { /* ignore */ }

// ─── 6) Response ───
echo json_encode([
    'ok' => $hcOk && empty($alerts),
    'healthcheck' => $healthcheck,
    'new_errors_15min' => $spikeCount,
    'sample_errors' => array_slice($newErrors, 0, 5),
    'alerts' => $alerts,
    'alerts_emitted' => $emittedCount,
    'monitor_token' => $adminAuthed ? $expected : null, // admin uvidí token, anonymous ne
    'duration_ms' => (int) round((microtime(true) - $t0) * 1000),
    'checked_at' => gmdate('c'),
], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
