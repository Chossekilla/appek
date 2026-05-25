<?php
/**
 * 🚦 ROOT ROUTER — fallback pro customer deployment.
 *
 * Detekce prostředí:
 *   - MASTER server (appek.cz)  → existuje `vendor/` složka → NIKDY install
 *   - CUSTOMER server (firma.cz) → existuje `admin/`, nemá vendor/
 *
 * Když Apache servíruje index.html (sales), tento PHP soubor se nespustí.
 * Pokud někdo hitne přímo /index.php (force), tady se to chytí:
 *   - Master → redirect na / (Apache pak vezme index.html)
 *   - Customer & !installed → install.php
 *   - Customer & installed → admin/
 */

$root = __DIR__;
$isMaster   = is_dir($root . '/vendor');
$isCustomer = is_dir($root . '/admin');
$installed  = file_exists($root . '/api/.installed') || file_exists($root . '/api/config.local.php');
$hasInstall = file_exists($root . '/install.php');
$hasIndexHtml = file_exists($root . '/index.html');

// 1) MASTER server — NIKDY install. Vždy sales.
if ($isMaster) {
    if ($hasIndexHtml) {
        header('Location: /index.html');
    } else {
        header('Location: /vendor/');
    }
    exit;
}

// 2) CUSTOMER flow
if ($isCustomer) {
    if (!$installed && $hasInstall) {
        header('Location: install.php');
        exit;
    }
    if ($installed) {
        header('Location: admin/');
        exit;
    }
}

// 3) Fallback welcome
$lang = strtolower(substr($_SERVER['HTTP_ACCEPT_LANGUAGE'] ?? 'cs', 0, 2));
if (!in_array($lang, ['cs','en','es'], true)) $lang = 'cs';
$T = [
    'cs' => ['welcome' => 'APPEK B2B', 'desc' => 'Tento web zatím není nakonfigurovaný.', 'install' => 'Spustit instalaci'],
    'en' => ['welcome' => 'APPEK B2B', 'desc' => 'This site is not yet configured.', 'install' => 'Run installer'],
    'es' => ['welcome' => 'APPEK B2B', 'desc' => 'Este sitio aún no está configurado.', 'install' => 'Ejecutar instalador'],
];
$t = $T[$lang];
?><!DOCTYPE html>
<html lang="<?= $lang ?>">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="robots" content="noindex,nofollow">
<title><?= htmlspecialchars($t['welcome']) ?></title>
<style>
  body { font-family: -apple-system,BlinkMacSystemFont,system-ui,sans-serif;
         background: linear-gradient(135deg,#1d1d1f,#2c2c2e); color:#fff;
         min-height:100vh; display:flex; align-items:center; justify-content:center;
         padding:20px; margin:0; }
  .card { background:rgba(255,255,255,0.06); backdrop-filter:blur(20px);
          border:1px solid rgba(255,255,255,0.1); border-radius:20px;
          padding:40px; max-width:480px; text-align:center; }
  h1 { background:linear-gradient(135deg,#BA7517,#F59E0B); -webkit-background-clip:text;
       -webkit-text-fill-color:transparent; font-size:32px; margin-bottom:12px; }
  p { color:rgba(255,255,255,0.75); margin-bottom:24px; line-height:1.6; }
  a.btn { display:inline-block; padding:12px 28px; border-radius:999px;
          background:linear-gradient(180deg,#BA7517,#854F0B); color:#fff;
          text-decoration:none; font-weight:600; }
</style>
</head>
<body>
<div class="card">
  <div style="font-size:48px;margin-bottom:14px">📦</div>
  <h1><?= htmlspecialchars($t['welcome']) ?></h1>
  <p><?= htmlspecialchars($t['desc']) ?></p>
  <?php if ($hasInstall): ?>
    <a href="install.php" class="btn">→ <?= htmlspecialchars($t['install']) ?></a>
  <?php endif; ?>
</div>
</body>
</html>
