<?php
/**
 * 🔄 DEMO RESET — hodinový auto-reset demo dat.
 *
 * Cron na demo subdoméně:
 *   0 * * * *   /usr/bin/php /home/USER/public_html/demo/api/admin_reset_demo.php
 *
 * Akce:
 *   1. Načte demo-seed.sql
 *   2. Provede ho proti aktuální DB
 *   3. Zaloguje reset do demo_pristupy
 *
 * Bezpečnost:
 *   - Spouští se jen pokud APPEK_DEMO_MODE = true v config.local.php
 *   - Můžeš spustit i ručně přes CLI: php api/admin_reset_demo.php
 *   - Z HTTP requestu se spustí jen z localhost / 127.0.0.1
 */

require_once __DIR__ . '/config.php';

// ─── Self-protection ────────────────────────────────────────────
if (!defined('APPEK_DEMO_MODE') || APPEK_DEMO_MODE !== true) {
    http_response_code(403);
    echo "❌ Toto je demo-only endpoint. Aktivuj v config.local.php: define('APPEK_DEMO_MODE', true);\n";
    exit;
}

// HTTP přístup jen z localhost (cron běží z localhost)
$isCli = php_sapi_name() === 'cli';
$ip = $_SERVER['REMOTE_ADDR'] ?? '';
$allowedIPs = ['127.0.0.1', '::1', $_SERVER['SERVER_ADDR'] ?? ''];
if (!$isCli && !in_array($ip, $allowedIPs, true)) {
    http_response_code(403);
    echo "❌ Tento endpoint je dostupný jen z localhost (přes cron).\n";
    exit;
}

// 🆕 v3.0.165 — RETENCE: auto-reset běží jen jednou za DEMO_RESET_DAYS (default 30),
// aby testovací data na demu přežila. Dřív hodinový cron mazal VŠE každou hodinu
// (21× DELETE FROM v seedu) → testy mizely. Ruční vynucení: php admin_reset_demo.php --force
$RESET_DAYS = defined('DEMO_RESET_DAYS') ? (int) DEMO_RESET_DAYS : 30;
$force = $isCli && in_array('--force', $argv ?? [], true);
if (!$force && $RESET_DAYS > 0) {
    try {
        $last = db()->query("SELECT MAX(cas) FROM demo_pristupy WHERE akce='auto_reset'")->fetchColumn();
        if ($last && (time() - strtotime($last)) < $RESET_DAYS * 86400) {
            $zbyva = (int) ceil($RESET_DAYS - (time() - strtotime($last)) / 86400);
            if (php_sapi_name() !== 'cli') header('Content-Type: text/plain; charset=UTF-8');
            echo "⏭️  Demo reset přeskočen — interval {$RESET_DAYS} dní ještě neuplynul (zbývá ~{$zbyva} dní). Vynuť: --force\n";
            exit(0);
        }
    } catch (Throwable $e) { /* demo_pristupy ještě nemusí existovat → pokračuj resetem */ }
}

$startTime = microtime(true);

try {
    $pdo = db();

    // Najdi seed SQL
    $seedPath = realpath(__DIR__ . '/..') . '/deploy/demo-seed.sql';
    if (!file_exists($seedPath)) {
        // Fallback — možná je v rootu
        $seedPath = realpath(__DIR__ . '/..') . '/demo-seed.sql';
    }
    if (!file_exists($seedPath)) {
        throw new Exception('demo-seed.sql nenalezen. Očekáváno v: deploy/demo-seed.sql');
    }

    $sql = file_get_contents($seedPath);
    if (!$sql) throw new Exception('demo-seed.sql je prázdný.');

    // Strip SQL komentáře (-- ...) a rozdělit na statementy
    $sqlClean = preg_replace('/^\s*--.*$/m', '', $sql);
    $stmts = preg_split('/;\s*$/m', $sqlClean);

    $executed = 0;
    $errors = 0;
    foreach ($stmts as $s) {
        $s = trim($s);
        if ($s === '' || stripos($s, 'SELECT') === 0) continue;
        try {
            $pdo->exec($s);
            $executed++;
        } catch (Throwable $e) {
            $errors++;
            error_log('demo_reset stmt error: ' . $e->getMessage());
        }
    }

    // Zaloguj reset
    try {
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS demo_pristupy (
                id INT AUTO_INCREMENT PRIMARY KEY,
                ip VARCHAR(45), user_agent TEXT, akce VARCHAR(50),
                referer VARCHAR(255), jazyk VARCHAR(5),
                cas DATETIME DEFAULT CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");
        $pdo->prepare("INSERT INTO demo_pristupy (ip, user_agent, akce) VALUES (:ip, :ua, 'auto_reset')")
            ->execute(['ip' => 'cron', 'ua' => 'demo_reset.php']);
    } catch (Throwable $e) { /* ignore */ }

    $duration = round((microtime(true) - $startTime) * 1000);
    $output = sprintf(
        "✅ Demo reset proběhl. Statements: %d ok / %d errors · Trvalo: %d ms\n",
        $executed, $errors, $duration
    );

    if ($isCli) {
        echo $output;
    } else {
        header('Content-Type: text/plain; charset=UTF-8');
        echo $output;
    }
} catch (Throwable $e) {
    $msg = "❌ Reset selhal: " . $e->getMessage() . "\n";
    error_log('demo_reset: ' . $e->getMessage());
    if ($isCli) {
        fwrite(STDERR, $msg);
        exit(1);
    } else {
        http_response_code(500);
        echo $msg;
    }
}
