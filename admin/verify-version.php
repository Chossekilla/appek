<?php
/**
 * 🔍 APPEK Version Diagnostic — verifikuje co je SKUTEČNĚ na disku.
 *
 * Když customer admin ukazuje starou verzi i po update, tahle stránka pomůže
 * rozlišit JESTLI je problém v:
 *   A) cache (server na disku má novou verzi, ale browser cachuje starou)
 *   B) souborech (update neaplikoval admin.js — file write fail)
 *   C) APP_VERSION konfigu (config.php updated, ale ostatní soubory ne)
 *
 * Otevři: /admin/verify-version.php
 */

@require_once __DIR__ . '/../api/config.php';
$appVersion = defined('APP_VERSION') ? APP_VERSION : '?';

// Klíčové soubory + jejich mtime + obsahové markery
$files = [
    'admin/admin.js'   => __DIR__ . '/admin.js',
    'admin/admin.css'  => __DIR__ . '/admin.css',
    'admin/index.html' => __DIR__ . '/index.html',
    'admin/sw.js'      => __DIR__ . '/sw.js',
    'api/config.php'   => __DIR__ . '/../api/config.php',
    'api/updates_apply.php' => __DIR__ . '/../api/updates_apply.php',
    'api/admin_version_check.php' => __DIR__ . '/../api/admin_version_check.php',
    'admin/clear-cache.html' => __DIR__ . '/clear-cache.html',
];

$results = [];
foreach ($files as $label => $path) {
    $info = [
        'path' => $label,
        'exists' => file_exists($path),
        'mtime' => null,
        'size' => null,
        'version_marker' => null,
    ];
    if ($info['exists']) {
        $info['mtime'] = date('Y-m-d H:i:s', filemtime($path));
        $info['size'] = filesize($path);
        // Hledej version marker v souboru
        $content = @file_get_contents($path, false, null, 0, 4096);  // jen první 4 KB
        if ($content !== false) {
            // Hledá: APP_VERSION = '2.0.X', $version = '2.0.X', CACHE_VERSION = 'appek-v2.0.X', v=2.0.X, // v2.0.X
            if (preg_match('/(?:APP_VERSION[^\']*\'|version\s*=\s*\'|CACHE_VERSION\s*=\s*\'appek-v|v=)(\d+\.\d+\.\d+)/', $content, $m)) {
                $info['version_marker'] = $m[1];
            }
            // Marker pro admin.js: hledej naše komentáře
            if (str_contains($content, '🆕 v2.0.79')) $info['has_v_2_0_79'] = true;
            if (str_contains($content, '🆕 v2.0.74')) $info['has_v_2_0_74'] = true;
            if (str_contains($content, '🆕 v2.0.71')) $info['has_v_2_0_71'] = true;
            if (str_contains($content, '🆕 v2.0.68')) $info['has_v_2_0_68'] = true;
        }
    }
    $results[] = $info;
}

// Pokud chceš JSON odpověď
if (isset($_GET['format']) && $_GET['format'] === 'json') {
    header('Content-Type: application/json; charset=UTF-8');
    echo json_encode(['version' => $appVersion, 'files' => $results], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    exit;
}

?><!DOCTYPE html>
<html lang="cs">
<head>
<meta charset="UTF-8">
<meta http-equiv="Cache-Control" content="no-cache, no-store, must-revalidate">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>🔍 APPEK Version Diagnostic</title>
<style>
  body { font-family: -apple-system, BlinkMacSystemFont, sans-serif; background:#FBFAF7; color:#1d1d1f; padding:24px; max-width:900px; margin:0 auto; }
  h1 { font-size:22px; margin-bottom:4px; }
  .sub { color:#6e6e73; font-size:13px; margin-bottom:24px; }
  .hero { background:linear-gradient(135deg,#FFF8E5,#FEF3C7); border:1.5px solid #FBBF24; border-radius:12px; padding:18px 22px; margin-bottom:24px; }
  .hero h2 { font-size:14px; margin-bottom:6px; color:#854F0B; text-transform:uppercase; letter-spacing:0.5px; }
  .hero .v { font-family:'SF Mono',Menlo,monospace; font-size:28px; font-weight:800; color:#1d1d1f; }
  table { width:100%; border-collapse:collapse; background:#fff; border-radius:12px; overflow:hidden; box-shadow:0 1px 3px rgba(0,0,0,0.05); font-size:13px; }
  th { text-align:left; padding:10px 12px; background:#f7f8fa; font-weight:700; color:#6e6e73; border-bottom:1px solid #e5e5e7; }
  td { padding:10px 12px; border-bottom:1px solid #f0f0f3; vertical-align:top; }
  tr:last-child td { border-bottom:none; }
  .path { font-family:'SF Mono',Menlo,monospace; font-size:12px; }
  .mtime { font-family:'SF Mono',Menlo,monospace; font-size:11.5px; color:#6e6e73; }
  .marker { font-family:'SF Mono',Menlo,monospace; font-size:11px; padding:2px 8px; border-radius:999px; font-weight:600; }
  .marker.ok { background:#d4edda; color:#155724; }
  .marker.warn { background:#FFF8E5; color:#854F0B; }
  .marker.err { background:#FEE2E2; color:#991B1B; }
  .marker.miss { background:#f5f5f7; color:#86868b; }
  .check { font-size:11px; padding:2px 6px; border-radius:4px; font-weight:600; }
  .check.yes { background:#d4edda; color:#155724; }
  .check.no { background:#FEE2E2; color:#991B1B; }
  .actions { margin-top:24px; display:flex; gap:8px; flex-wrap:wrap; }
  .btn { padding:10px 18px; border-radius:10px; border:none; cursor:pointer; font-family:inherit; font-size:13px; font-weight:600; text-decoration:none; display:inline-block; }
  .btn-primary { background:linear-gradient(180deg,#BA7517,#854F0B); color:#fff; }
  .btn-secondary { background:#f5f5f7; color:#1d1d1f; }
  .alert { padding:12px 16px; border-radius:10px; margin:12px 0; font-size:13.5px; line-height:1.5; }
  .alert.warn { background:#FFF8E5; border-left:3px solid #FBBF24; color:#854F0B; }
  .alert.info { background:#E6F1FB; border-left:3px solid #0058b8; color:#0C447C; }
</style>
</head>
<body>
<h1>🔍 APPEK Version Diagnostic</h1>
<div class="sub">Verifikuje co je <strong>SKUTEČNĚ na disku</strong> (ne v browser cache).</div>

<div class="hero">
  <h2>APP_VERSION z api/config.php</h2>
  <div class="v"><?= htmlspecialchars($appVersion) ?></div>
</div>

<?php
$mainAdminJs = $results[0]; // admin/admin.js
$hasNewBanner = !empty($mainAdminJs['has_v_2_0_79']);
$hasCleanUpdateCard = !empty($mainAdminJs['has_v_2_0_74']);
$diagStatus = $hasNewBanner ? 'modern' : ($hasCleanUpdateCard ? 'mid' : 'old');
?>

<?php if ($diagStatus === 'old'): ?>
  <div class="alert warn">
    ⚠️ <strong>Detekováno: admin.js je STARÝ</strong> (pre-2.0.74). Update aplikoval config.php (APP_VERSION ukazuje <?= htmlspecialchars($appVersion) ?>), ALE admin.js zůstal starý.
    To znamená že soubory v <code>admin/</code> nebyly přepsány. <strong>Řešení:</strong> Stáhni installer.php z appek.cz/updates/installer.php a manuálně přehraj instalaci, nebo nahraj fresh customer ZIP přes FTP.
  </div>
<?php elseif ($diagStatus === 'mid'): ?>
  <div class="alert info">
    ℹ️ admin.js je <strong>v2.0.74+</strong> ale ne nejnovější. Update by měl fungovat — zkus „Aktualizovat" v Nastavení.
  </div>
<?php else: ?>
  <div class="alert info" style="background:#d4edda;border-left-color:#15803d;color:#155724">
    ✅ <strong>admin.js je MODERNÍ</strong> (v2.0.79+ s post-update bannerem). Pokud se UI ukazuje staře, je to <strong>SW / browser cache</strong>. Použij <a href="clear-cache.html" style="color:#155724;font-weight:700">🧹 Clear Cache utility</a>.
  </div>
<?php endif; ?>

<table>
  <thead>
    <tr>
      <th>Soubor</th>
      <th>Existuje</th>
      <th>Velikost</th>
      <th>Poslední změna</th>
      <th>Verze marker</th>
      <th>Modern code?</th>
    </tr>
  </thead>
  <tbody>
    <?php foreach ($results as $r): ?>
      <tr>
        <td class="path"><?= htmlspecialchars($r['path']) ?></td>
        <td>
          <?php if ($r['exists']): ?>
            <span class="check yes">✓ ano</span>
          <?php else: ?>
            <span class="check no">✗ chybí</span>
          <?php endif; ?>
        </td>
        <td><?php if ($r['size'] !== null): ?><?= number_format($r['size']/1024, 1, ',', ' ') ?> kB<?php endif; ?></td>
        <td class="mtime"><?= htmlspecialchars($r['mtime'] ?? '—') ?></td>
        <td>
          <?php if ($r['version_marker']): ?>
            <span class="marker ok"><?= htmlspecialchars($r['version_marker']) ?></span>
          <?php elseif ($r['exists']): ?>
            <span class="marker miss">—</span>
          <?php else: ?>
            <span class="marker err">missing</span>
          <?php endif; ?>
        </td>
        <td>
          <?php
            $hits = [];
            if (!empty($r['has_v_2_0_79'])) $hits[] = '🆕 2.0.79';
            if (!empty($r['has_v_2_0_74'])) $hits[] = '🆕 2.0.74';
            if (!empty($r['has_v_2_0_71'])) $hits[] = '🆕 2.0.71';
            if (!empty($r['has_v_2_0_68'])) $hits[] = '🆕 2.0.68';
            if (!empty($hits)): ?>
              <small><?= htmlspecialchars(implode(' · ', $hits)) ?></small>
            <?php endif; ?>
        </td>
      </tr>
    <?php endforeach; ?>
  </tbody>
</table>

<div class="actions">
  <a href="clear-cache.html" class="btn btn-primary">🧹 Clear cache & reload</a>
  <a href="../" class="btn btn-secondary">🏠 Zpět na admin</a>
  <a href="?format=json" class="btn btn-secondary">📋 JSON output</a>
</div>

<div style="margin-top:32px;font-size:11.5px;color:#86868b;font-family:'SF Mono',Menlo,monospace">
  PHP <?= PHP_VERSION ?> · <?= htmlspecialchars(date('Y-m-d H:i:s')) ?> · <?= htmlspecialchars($_SERVER['HTTP_HOST'] ?? 'localhost') ?>
</div>

</body>
</html>
