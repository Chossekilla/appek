<?php
/**
 * 🔁 CRON: opakující se objednávky.
 *
 * Spouštět denně ráno (např. v 4:00):
 *   0 4 * * * php /var/www/api/cron_recurring.php
 *
 * Co dělá: pro ZÍTŘEJŠÍ datum projde všechna aktivní pravidla a vytvoří objednávky.
 * Anti-duplikát: pokud už pro zítra existuje obj z pravidla, přeskočí.
 *
 * Volat lze i z prohlížeče s tajným tokenem (pro hostingy bez CLI cron):
 *   https://tvuj-web.cz/api/cron_recurring.php?token=NĚCO_TAJNÉHO
 *   (token nastavený v nastaveni klíč 'cron_token')
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/_recurring_lib.php';

$jsem_cli = (php_sapi_name() === 'cli');
$pdo = db();

// Pokud spuštěno z webu, vyžaduj token
if (!$jsem_cli) {
    $token_v_db = nastaveni_get($pdo, 'cron_token', '');
    $token_req = $_GET['token'] ?? '';
    if (empty($token_v_db) || !hash_equals((string) $token_v_db, (string) $token_req)) { // 🔒 v3.0.376 constant-time (timing attack)
        http_response_code(403);
        echo "Forbidden — invalid token";
        exit;
    }
}

// Cílový datum: zítra (default) nebo z argumentu
$datum = $_GET['datum'] ?? (isset($argv[1]) ? $argv[1] : date('Y-m-d', strtotime('+1 day')));
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $datum)) {
    echo "Neplatný datum: $datum\n";
    exit(1);
}

echo "🔁 Recurring cron — datum dodání: $datum\n";

// 🆕 v2.9.324 — wrap v cron_run() → persistuje do cron_log DB + notif při 3× failu v řadě
$run = cron_run('recurring_orders', function() use ($pdo, $datum) {
    $result = recurring_generate($pdo, $datum);
    return [
        'ok' => $result['chyby'] === 0,
        'datum' => $datum,
        'vytvoreno' => $result['vytvoreno'],
        'preskoceno' => $result['preskoceno'],
        'chyby' => $result['chyby'],
        'detaily' => $result['detaily'] ?? [],
    ];
});

$result = $run['result'] ? (json_decode($run['result'], true) ?: []) : [];
echo "Vytvořeno:  " . ($result['vytvoreno'] ?? '?') . "\n";
echo "Přeskočeno: " . ($result['preskoceno'] ?? '?') . "\n";
echo "Chyby:      " . ($result['chyby'] ?? '?') . "\n\n";

foreach (($result['detaily'] ?? []) as $d) {
    echo "  $d\n";
}

echo "⏱️  Duration: {$run['duration_ms']}ms · cron_log persisted\n";

if ($run['error']) {
    echo "❌ ERROR: {$run['error']}\n";
    exit(1);
}

// Pokud z webu (browser), vrátí JSON
if (!$jsem_cli && !empty($_GET['format']) && $_GET['format'] === 'json') {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($run, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
}
