<?php
/**
 * 🆕 v2.9.322 — SYNTHETIC HEALTH CHECK ENDPOINT
 *
 * Public endpoint co exercituje kritické cesty aplikace (DB, schema, file write,
 * disk space, session). Volaný:
 *   - CRON každých 5 minut (Hostinger cron tab → curl https://demo.appek.cz/api/healthcheck.php)
 *   - External monitor (Uptime Robot, BetterStack apod.)
 *   - Admin UI "Otestovat zdraví aplikace" tlačítko
 *   - admin_health_monitor.php (vnitřní orchestrátor)
 *
 * Response:
 *   {ok: true|false, checks: [...], duration_ms: N, version: "X.Y.Z", timestamp: ISO}
 *
 * HTTP status: 200 pokud všechny checks ok, 503 pokud jeden nebo víc failed.
 *
 * Pravidla:
 *   - Žádný admin auth (musí jít volat externě) — jen GET, žádné mutace
 *   - <2s celkem (jinak monitor false-positive timeout)
 *   - Žádný side effect na produkční data — write check jen do nastaveni klíče 'healthcheck_at'
 *   - Pokud sám healthcheck selže (exception), vrátí 503 s error
 */

require_once __DIR__ . '/config.php';

header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-cache, no-store, must-revalidate');
header('X-Robots-Tag: noindex, nofollow');

// 🔒 v3.0.353 — throttle: anonymní flood neexekuuje DB-write/FS check pokaždé (DoS amplifikace).
//   Výsledek cachujeme 30 s; cron/monitor (á 5 min) stejně dostane čerstvý.
$_hcCache = (defined('UPLOAD_DIR') && @is_dir(UPLOAD_DIR) && @is_writable(UPLOAD_DIR) ? rtrim(UPLOAD_DIR, '/') : sys_get_temp_dir()) . '/.appek-hc-cache.json';
if (is_file($_hcCache) && (time() - @filemtime($_hcCache)) < 30) {
    $_c = @file_get_contents($_hcCache);
    if ($_c !== false && $_c !== '') {
        $_cj = json_decode($_c, true);
        http_response_code((is_array($_cj) && ($_cj['ok'] ?? true)) ? 200 : 503);
        header('X-Healthcheck-Cache: hit');
        echo $_c;
        exit;
    }
}

$t0 = microtime(true);
$checks = [];
$overall = true;

// 🐛 v2.9.323 — size_human MUSÍ být definovaná PŘED checks (PHP nehostuje funkce
// declared uvnitř `if (!function_exists)`. Předtím se volalo z arrow fn → undefined.
if (!function_exists('size_human')) {
    function size_human(int $bytes): string {
        if ($bytes >= 1048576) return round($bytes / 1048576, 1) . ' MB';
        if ($bytes >= 1024) return round($bytes / 1024, 1) . ' KB';
        return $bytes . ' B';
    }
}

/** Pomocný helper — wrap check, měří duration, zachytí throwable */
function hc_check(string $name, callable $fn): array {
    $t = microtime(true);
    try {
        $out = $fn();
        $ok = is_array($out) ? ($out['ok'] ?? true) : (bool) $out;
        $detail = is_array($out) ? ($out['detail'] ?? null) : null;
        return [
            'name' => $name,
            'ok' => $ok,
            'detail' => $detail,
            'duration_ms' => (int) round((microtime(true) - $t) * 1000),
        ];
    } catch (\Throwable $e) {
        return [
            'name' => $name,
            'ok' => false,
            'detail' => substr($e->getMessage(), 0, 200),
            'duration_ms' => (int) round((microtime(true) - $t) * 1000),
            'error' => true,
        ];
    }
}

// ─────────────────────────────────────────────────────────────────
// CHECK 1 — DB connectivity (SELECT 1)
// ─────────────────────────────────────────────────────────────────
$checks[] = hc_check('db_connect', function () {
    $pdo = db();
    $v = (int) $pdo->query("SELECT 1")->fetchColumn();
    return ['ok' => $v === 1, 'detail' => "SELECT 1 = $v"];
});

// ─────────────────────────────────────────────────────────────────
// CHECK 2 — DB schema kritické tabulky (existují?)
// ─────────────────────────────────────────────────────────────────
$checks[] = hc_check('db_schema', function () {
    $required = ['nastaveni', 'admin_users', 'odberatele', 'vyrobky', 'objednavky', 'objednavky_polozky'];
    $pdo = db();
    $existing = $pdo->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN);
    $missing = array_diff($required, $existing);
    return [
        'ok' => empty($missing),
        'detail' => empty($missing) ? count($required) . ' core tables present' : 'Chybí: ' . implode(',', $missing),
    ];
});

// ─────────────────────────────────────────────────────────────────
// CHECK 3 — DB write (test že umíme zapsat — touch nastaveni timestamp)
// ─────────────────────────────────────────────────────────────────
$checks[] = hc_check('db_write', function () {
    $pdo = db();
    $now = time();
    $pdo->prepare("
        INSERT INTO nastaveni (klic, hodnota) VALUES ('healthcheck_at', :v)
        ON DUPLICATE KEY UPDATE hodnota = :v2
    ")->execute(['v' => $now, 'v2' => $now]);
    $back = (int) $pdo->query("SELECT hodnota FROM nastaveni WHERE klic = 'healthcheck_at'")->fetchColumn();
    return ['ok' => $back === $now, 'detail' => "wrote+read $now"];
});

// ─────────────────────────────────────────────────────────────────
// CHECK 4 — Disk space (≥ 100 MB free)
// ─────────────────────────────────────────────────────────────────
$checks[] = hc_check('disk_space', function () {
    $free = @disk_free_space(__DIR__);
    if ($free === false) return ['ok' => false, 'detail' => 'disk_free_space() vrátil false'];
    $freeMb = (int) ($free / 1024 / 1024);
    return ['ok' => $freeMb >= 100, 'detail' => $freeMb . ' MB free'];
});

// ─────────────────────────────────────────────────────────────────
// CHECK 5 — File write test (uploads dir nebo /tmp)
// ─────────────────────────────────────────────────────────────────
$checks[] = hc_check('file_write', function () {
    $dirs = [];
    if (defined('UPLOAD_DIR') && is_dir(UPLOAD_DIR)) $dirs[] = UPLOAD_DIR;
    $dirs[] = sys_get_temp_dir();
    foreach ($dirs as $dir) {
        $file = $dir . '/healthcheck-' . random_int(1000, 9999) . '.tmp';
        if (file_put_contents($file, 'ok') !== false) {
            @unlink($file);
            return ['ok' => true, 'detail' => "wrote to $dir"];
        }
    }
    return ['ok' => false, 'detail' => 'Žádný writable dir z: ' . implode(',', $dirs)];
});

// ─────────────────────────────────────────────────────────────────
// CHECK 6 — Recent errors rate (z app_errors)
// ─────────────────────────────────────────────────────────────────
$checks[] = hc_check('error_rate', function () {
    $pdo = db();
    try {
        $n = (int) $pdo->query("
            SELECT COUNT(*) FROM app_errors
            WHERE created_at >= DATE_SUB(NOW(), INTERVAL 15 MINUTE)
              AND severity IN ('error', 'alert')
        ")->fetchColumn();
        $threshold = 10; // > 10 errors / 15 min = warn
        return [
            'ok' => $n <= $threshold,
            'detail' => "$n errors / 15min (threshold $threshold)",
        ];
    } catch (\Throwable $e) {
        // Tabulka může chybět na bare install — treat as ok
        return ['ok' => true, 'detail' => 'app_errors table not yet created'];
    }
});

// ─────────────────────────────────────────────────────────────────
// CHECK 7 — PHP runtime (memory, extensions)
// ─────────────────────────────────────────────────────────────────
$checks[] = hc_check('php_runtime', function () {
    $required = ['pdo_mysql', 'json', 'mbstring', 'curl', 'session', 'openssl'];
    $missing = array_filter($required, fn($e) => !extension_loaded($e));
    $memUsed = memory_get_usage(true);
    $memLimit = ini_get('memory_limit');
    return [
        'ok' => empty($missing),
        'detail' => empty($missing)
            ? sprintf('PHP %s, mem %s / %s', PHP_VERSION, size_human($memUsed), $memLimit)
            : 'Chybí ext: ' . implode(',', $missing),
    ];
});

// (size_human helper definován NAHOŘE — viz fix v2.9.323)

// ─────────────────────────────────────────────────────────────────
// Vyhodnocení + response
// ─────────────────────────────────────────────────────────────────
foreach ($checks as $c) {
    if (!$c['ok']) { $overall = false; break; }
}

$response = [
    'ok' => $overall,
    'version' => defined('APP_VERSION') ? APP_VERSION : '?',
    'timestamp' => gmdate('c'),
    'duration_ms' => (int) round((microtime(true) - $t0) * 1000),
    'checks' => $checks,
];

http_response_code($overall ? 200 : 503);
$_hcJson = json_encode($response, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
@file_put_contents($_hcCache, $_hcJson); // 🔒 v3.0.353 — cache pro 30s throttle
echo $_hcJson;
