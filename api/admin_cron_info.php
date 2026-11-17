<?php
/**
 * 🆕 v3.0.645 — ⏰ Plánované úlohy (CRON): jakou veřejnou adresu cron použije pro odkazy
 *   + hotové příkazy pro hPanel (Hostinger) a obecný crontab.
 *
 *   V CLI (cron) není doména ani podsložka známá → instalace si ji zapamatuje z přihlášení
 *   super admina (nastaveni.app_public_base_auto, viz config.php app_public_base_remember()).
 *
 * GET               — info (každý admin vč. náhledového účtu; neobsahuje tajné tokeny)
 * POST ?action=pouzit — super admin: cron bude používat adresu, přes kterou je právě přihlášen
 *                       (instalace dostupná pod více adresami / přechod z dočasné domény).
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/_admin_auth.php';

$admin  = require_admin();
$pdo    = db();
$action = (string) ($_GET['action'] ?? '');
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

$appUrl = APP_URL_EXPLICIT ? rtrim((string) APP_URL, '/') : '';

if ($action === 'pouzit') {
    if ($method !== 'POST') json_error('Použij POST', 405);
    require_super_admin();
    $cur = app_public_base_current_web();
    if ($cur === '') json_error('Adresu aktuálního požadavku nelze určit', 400);
    if (app_public_base_resolve($appUrl, $cur) !== $cur) {
        json_error('Adresu pro cron určuje APP_URL v api/config.local.php — změňte ji tam.', 409);
    }
    app_public_base_auto_save($pdo, $cur);
    $_SESSION['_app_pub_base_seen'] = $cur;
    json_response(['ok' => true, 'cron_base' => $cur]);
}

if ($action !== '' && $action !== 'info') json_error('Neznámá akce', 400);

$auto     = app_public_base_auto_get(true);
$cronBase = app_public_base_resolve($appUrl, $auto);
$webBase  = app_public_base_current_web();

if ($appUrl !== '' && trim((string) (parse_url($appUrl, PHP_URL_PATH) ?? ''), '/') !== '') $zdroj = 'app_url';
elseif ($cronBase !== '' && $cronBase === app_public_base_normalize($auto)) $zdroj = 'auto';
elseif ($cronBase !== '') $zdroj = 'app_url';
else $zdroj = 'none';

$varovani = [];
if ($cronBase === '') {
    $varovani[] = 'Cron zatím nezná veřejnou adresu — odkazy v e-mailech z cronu by vedly na http://localhost. '
                . 'Stačí se jednou přihlásit jako super admin (adresa se uloží sama), nebo ji zadat do APP_URL v api/config.local.php.';
} elseif ($webBase !== '' && $cronBase !== $webBase) {
    $varovani[] = 'Cron použije jinou adresu, než přes kterou jste právě přihlášeni.';
}
if ($appUrl !== '' && $auto !== '' && $cronBase === app_public_base_normalize($appUrl)
    && _app_public_host_key($auto) !== _app_public_host_key($appUrl)) {
    $varovani[] = 'APP_URL v api/config.local.php má jinou doménu než zjištěná adresa instalace — cron používá APP_URL.';
}

// 📂 Cesta pro hPanel: hPanel sám doplní „/usr/bin/php /home/<účet>/" → zadává se jen cesta od domovské složky.
$root = str_replace('\\', '/', (string) (realpath(dirname(__DIR__)) ?: dirname(__DIR__)));
$hpanelRoot = null;
if (preg_match('#^/home/[^/]+/(.+)$#', $root, $m)) $hpanelRoot = rtrim($m[1], '/');

// náhledový účet (veřejné demo) nevidí název hostingového účtu v absolutní cestě
$rootUkaz = ($admin['role'] ?? '') === 'readonly' ? (string) preg_replace('#^/home/[^/]+/#', '/home/<účet>/', $root) : $root;

$ulohy = [];
foreach ([
    ['soubor' => 'cron_recurring.php', 'job' => 'recurring_orders', 'cas' => '0 4 * * *',
     'popis' => 'Opakující se objednávky — vytvoří objednávky na zítřek (denně ráno).'],
    ['soubor' => 'cron_kurzy.php', 'job' => 'mena_kurzy', 'cas' => '45 14 * * *',
     'popis' => 'Kurzy ČNB pro platby v cizí měně (pracovní dny po 14:30; nepovinné).'],
] as $u) {
    if (!is_file(__DIR__ . '/' . $u['soubor'])) continue;
    $posledni = null;
    try {
        $st = $pdo->prepare("SELECT started_at, ok, error_msg FROM cron_log WHERE job = :j ORDER BY started_at DESC LIMIT 1");
        $st->execute(['j' => $u['job']]);
        $posledni = $st->fetch(PDO::FETCH_ASSOC) ?: null;
        if ($posledni) $posledni['ok'] = (int) $posledni['ok'] === 1;
    } catch (Throwable $e) { /* cron_log vznikne až prvním během */ }
    $ulohy[] = [
        'soubor'   => $u['soubor'],
        'popis'    => $u['popis'],
        'cas'      => $u['cas'],
        'hpanel'   => $hpanelRoot !== null ? $hpanelRoot . '/api/' . $u['soubor'] : null,
        'prikaz'   => $u['cas'] . ' php ' . $rootUkaz . '/api/' . $u['soubor'],
        'posledni' => $posledni,
    ];
}

json_response([
    'web_base'    => $webBase,
    'app_url'     => $appUrl,
    'auto'        => $auto,
    'cron_base'   => $cronBase,
    'zdroj'       => $zdroj,
    'varovani'    => $varovani,
    'muze_pouzit' => is_super_admin() && $webBase !== '' && $webBase !== $cronBase
                     && app_public_base_resolve($appUrl, $webBase) === $webBase,
    'hpanel'      => $hpanelRoot !== null,
    'ulohy'       => $ulohy,
]);
