<?php
/**
 * 💱 CRON: denní kurzy ČNB do tabulky mena_kurzy (v3.0.643, platby v Kč i cizí měně).
 *
 * ČNB vyhlašuje kurzy v pracovní dny ve 14:30 → spouštět denně ve 14:45:
 *   hPanel (Hostinger): JEN cesta + 5 polí času, /usr/bin/php a /home/<účet>/ doplní hPanel sám:
 *     45 14 * * *   domains/<doména>/public_html/api/cron_kurzy.php
 *   jinde: 45 14 * * * php /cesta/k/webu/api/cron_kurzy.php
 *
 * Bez cronu to funguje taky — kurz se stáhne líně při první platbě v cizí měně
 * (mena_kurz_dne, max 1 pokus za 30 min). Cron jen zajistí čerstvý kurz dopředu.
 * Z webu jen s tokenem (nastaveni.cron_token), stejně jako cron_recurring.php:
 *   https://tvuj-web.cz/api/cron_kurzy.php?token=…
 * Volitelný argument / ?datum=YYYY-MM-DD = kurz k jinému dni (doplnění historie).
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/_mena_lib.php';

$jsem_cli = (php_sapi_name() === 'cli');
$pdo = db();

if (!$jsem_cli) {
    $token_v_db = nastaveni_get($pdo, 'cron_token', '');
    $token_req = $_GET['token'] ?? '';
    if (empty($token_v_db) || !hash_equals((string) $token_v_db, (string) $token_req)) {
        http_response_code(403);
        echo "Forbidden — invalid token";
        exit;
    }
    header('Content-Type: text/plain; charset=utf-8');
}

$datum = $_GET['datum'] ?? ($argv[1] ?? date('Y-m-d'));
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $datum)) { echo "Neplatné datum: $datum\n"; exit(1); }

mena_ensure_schema($pdo);
$run = cron_run('mena_kurzy', function () use ($pdo, $datum) {
    $l = mena_kurzy_aktualizuj($pdo, $datum, 20);
    if (!$l) return ['ok' => false, 'chyba' => 'ČNB kurzovní lístek nedostupný', 'datum' => $datum];
    $p = mena_config_load($pdo)['platba'];
    $vybrane = array_intersect_key($l['kurzy'], array_flip(array_merge($p['meny'], ['EUR'])));
    return ['ok' => true, 'datum_cnb' => $l['datum'], 'kurzy' => $vybrane, 'pocet' => count($l['kurzy'])];
});
$res = $run['result'] ? (json_decode($run['result'], true) ?: []) : [];
if (!empty($res['ok'])) {
    echo "💱 Kurzy ČNB ze dne {$res['datum_cnb']} uloženy ({$res['pocet']} měn): ";
    echo implode(', ', array_map(fn($k, $v) => "1 $k = $v Kč", array_keys($res['kurzy']), $res['kurzy'])) . "\n";
    exit(0);
}
echo "❌ " . ($res['chyba'] ?? $run['error'] ?? 'chyba') . "\n";
exit(1);
