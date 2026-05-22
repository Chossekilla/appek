<?php
/**
 * 🔬 DIAGNOSTIC — co je v pořádku, co ne.
 *
 * Použití:  https://demo.appek.cz/api/diag.php
 *
 * Bezpečnost:
 *   - Aktivní jen pokud APPEK_DEMO_MODE = true v config.local.php
 *     (nebo z localhost)
 *   - Nikdy nezobrazuje plné heslo (maskuje)
 */

header('Content-Type: text/html; charset=UTF-8');
header('X-Robots-Tag: noindex, nofollow');

$ip = $_SERVER['REMOTE_ADDR'] ?? '';
$isLocal = in_array($ip, ['127.0.0.1', '::1', $_SERVER['SERVER_ADDR'] ?? ''], true);

// Načti config (best-effort)
$cfgPath = __DIR__ . '/config.local.php';
$cfgExists = file_exists($cfgPath);
if ($cfgExists) @require_once $cfgPath;

$isDemoMode = defined('APPEK_DEMO_MODE') && APPEK_DEMO_MODE === true;

if (!$isDemoMode && !$isLocal) {
    echo '<h1>403</h1><p>Diagnostika je dostupná jen v demo módu nebo z localhost.</p>';
    exit;
}

function row($label, $ok, $detail = '') {
    $color = $ok === null ? '#86868b' : ($ok ? '#208438' : '#b30019');
    $ico = $ok === null ? '⏳' : ($ok ? '✅' : '❌');
    echo "<tr><td style='padding:8px 14px'>$ico</td><td style='padding:8px 14px;font-weight:600'>$label</td><td style='padding:8px 14px;color:$color'>$detail</td></tr>";
}

function maskPass($p) {
    if (!$p) return '(prázdné)';
    if (strlen($p) <= 4) return str_repeat('•', strlen($p));
    return substr($p, 0, 2) . str_repeat('•', strlen($p) - 4) . substr($p, -2);
}

?><!DOCTYPE html>
<html lang="cs">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>🔬 APPEK Diagnostika</title>
<style>
body { font-family: -apple-system, system-ui, sans-serif; background: #fafafa; padding: 24px; line-height: 1.5; }
.wrap { max-width: 820px; margin: 0 auto; }
h1 { font-size: 26px; margin-bottom: 14px; }
.card { background: #fff; border-radius: 14px; padding: 24px 28px; margin-bottom: 18px; box-shadow: 0 1px 3px rgba(0,0,0,0.04); }
h2 { font-size: 16px; margin-bottom: 14px; padding-bottom: 8px; border-bottom: 1px solid #f0f0f3; }
table { width: 100%; font-size: 14px; }
.action { background: #fff3cd; border-left: 4px solid #ff9500; padding: 14px 18px; border-radius: 8px; margin-top: 14px; font-size: 13.5px; }
.action strong { color: #c66800; }
code { background: rgba(0,0,0,0.05); padding: 2px 8px; border-radius: 4px; font-family: 'SF Mono', Menlo, monospace; font-size: 13px; }
.ok { color: #208438; }
.err { color: #b30019; }
a.btn { display: inline-block; background: linear-gradient(180deg,#BA7517,#854F0B); color: #fff; padding: 8px 18px; border-radius: 8px; text-decoration: none; font-size: 13px; font-weight: 600; }
</style>
</head>
<body>
<div class="wrap">

<h1>🔬 APPEK Diagnostika</h1>
<p style="color:#6e6e73;margin-bottom:24px">Co je v pořádku, co ne. Tahle stránka je aktivní jen pokud běžíš v demo módu.</p>

<!-- ─── 1. CONFIG ─── -->
<div class="card">
  <h2>1. Config</h2>
  <table>
    <?php row('config.local.php existuje', $cfgExists, $cfgExists ? $cfgPath : 'CHYBÍ — pre-fillnutý config nebyl uploadnut.'); ?>
    <?php row('DB_HOST', defined('DB_HOST'), defined('DB_HOST') ? DB_HOST : '— undefined'); ?>
    <?php row('DB_NAME', defined('DB_NAME'), defined('DB_NAME') ? DB_NAME : '— undefined'); ?>
    <?php row('DB_USER', defined('DB_USER'), defined('DB_USER') ? DB_USER : '— undefined'); ?>
    <?php row('DB_PASS', defined('DB_PASS') && DB_PASS !== '', defined('DB_PASS') ? maskPass(DB_PASS) : '— undefined'); ?>
    <?php row('APP_LICENSE_KEY', defined('APP_LICENSE_KEY'), defined('APP_LICENSE_KEY') ? APP_LICENSE_KEY : '— undefined'); ?>
    <?php row('APPEK_DEMO_MODE', $isDemoMode, $isDemoMode ? 'true' : 'false / undefined'); ?>
  </table>
</div>

<!-- ─── 2. DB CONNECTION ─── -->
<div class="card">
  <h2>2. Databáze</h2>
  <?php
    $dbOk = false;
    $tables = [];
    $err = '';
    if (defined('DB_HOST') && defined('DB_NAME') && defined('DB_USER')) {
      try {
        $pdo = new PDO(
          'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4',
          DB_USER, DB_PASS,
          [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_TIMEOUT => 5]
        );
        $dbOk = true;
        $tables = $pdo->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN);
      } catch (Throwable $e) {
        $err = $e->getMessage();
      }
    }
  ?>
  <table>
    <?php row('Připojení k DB', $dbOk, $dbOk ? 'OK · ' . count($tables) . ' tabulek' : '❌ ' . $err); ?>
    <?php if ($dbOk):
      $needed = ['admin_users', 'produkty', 'odberatelska', 'objednavky', 'faktury', 'haccp_zaznamy', 'sarze'];
      foreach ($needed as $t):
        row("Tabulka: $t", in_array($t, $tables, true), in_array($t, $tables, true) ? 'existuje' : 'CHYBÍ — naimportuj demo-seed.sql');
      endforeach;
    endif; ?>
  </table>

  <?php if ($dbOk && empty($tables)): ?>
    <div class="action">
      <strong>⚠️ DB je prázdná!</strong> Naimportuj <code>demo-seed.sql</code>:
      <ol style="margin:10px 0 0;padding-left:20px">
        <li>Hostinger panel → Databases → phpMyAdmin</li>
        <li>Vyber DB <code><?= htmlspecialchars(DB_NAME) ?></code></li>
        <li>Klik <strong>Import</strong> → nahraj <code>public_html/demo/demo-seed.sql</code></li>
        <li>Klik <strong>Go</strong> → refresh tuto stránku</li>
      </ol>
    </div>
  <?php elseif (!$dbOk): ?>
    <div class="action">
      <strong>❌ DB connection selhal.</strong> Zkontroluj credentials v
      <code>api/config.local.php</code> a porovnej s Hostinger DB panelem.
      Nejčastěji: heslo s @ nebo speciálním znakem, který se nesprávně escapoval.
    </div>
  <?php endif; ?>
</div>

<!-- ─── 3. PHP & SERVER ─── -->
<div class="card">
  <h2>3. PHP & server</h2>
  <table>
    <?php row('PHP verze', version_compare(PHP_VERSION, '7.4.0', '>='), PHP_VERSION); ?>
    <?php row('PDO MySQL', extension_loaded('pdo_mysql'), extension_loaded('pdo_mysql') ? 'OK' : 'CHYBÍ'); ?>
    <?php row('ZipArchive', class_exists('ZipArchive'), class_exists('ZipArchive') ? 'OK' : 'CHYBÍ (potřeba pro updates)'); ?>
    <?php row('cURL', extension_loaded('curl'), extension_loaded('curl') ? 'OK' : 'CHYBÍ'); ?>
    <?php row('session.gc_maxlifetime', true, ini_get('session.gc_maxlifetime') . ' s' . (ini_get('session.gc_maxlifetime') == '900' ? ' (15 min — OK pro demo)' : '')); ?>
  </table>
</div>

<!-- ─── 4. FILESYSTEM ─── -->
<div class="card">
  <h2>4. Soubory</h2>
  <?php
    $root = realpath(__DIR__ . '/..');
    $checks = [
      'admin/index.html'      => 'Customer admin entry',
      'b2b/index.html'        => 'B2B portal',
      'api/_license.php'      => 'License lib',
      'api/.installed'        => 'Installed flag',
      'demo-seed.sql'         => 'Demo seed SQL',
    ];
    foreach ($checks as $path => $label) {
      $full = $root . '/' . $path;
      row("$label · $path", file_exists($full), file_exists($full) ? 'OK · ' . number_format(filesize($full)) . ' B' : 'CHYBÍ');
    }
  ?>
</div>

<!-- ─── 5. ROZHODNUTÍ ─── -->
<div class="card" style="background:linear-gradient(135deg,#fff,#fafafa)">
  <h2>5. Co dál?</h2>
  <?php if ($dbOk && in_array('admin_users', $tables, true)): ?>
    <p class="ok"><strong>✅ Vypadá to dobře.</strong> DB i tabulky existují.</p>
    <p>Zkus se přihlásit: <a href="../admin/" class="btn">→ Otevřít admin</a></p>
  <?php elseif ($dbOk && empty($tables)): ?>
    <p class="err"><strong>🌱 Naimportuj demo-seed.sql přes phpMyAdmin</strong> (viz instrukce výše) a refresh.</p>
    <a href="#" onclick="location.reload()" class="btn">🔄 Refresh diagnostiku</a>
  <?php elseif (!$dbOk): ?>
    <p class="err"><strong>🔌 Oprav DB credentials v <code>api/config.local.php</code></strong> přes Hostinger File Manager.</p>
  <?php else: ?>
    <p class="err"><strong>Mix problémů.</strong> Začni od položek označených ❌ výše.</p>
  <?php endif; ?>
</div>

<p style="text-align:center;margin-top:24px;font-size:12px;color:#86868b">
  APPEK diagnostika · jen v demo módu · <a href="../admin/">← Admin</a> · <a href="../">← Landing</a>
</p>

</div>
</body>
</html>
