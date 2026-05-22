<?php
/**
 * 🚀 APPEK FORCE UPDATE — standalone recovery tool
 *
 * Funguje INDEPENDENTNĚ na admin.js. Pokud je customer admin „zaseklý"
 * (stale code, broken update, partial files), tahle stránka:
 *
 *   1. Načte license + version z config.local.php (raw parse, ne require)
 *   2. Stáhne nejnovější customer ZIP z appek.cz/updates/download.php
 *   3. Verifikuje SHA-256
 *   4. Backup současné instalace do api/zalohy/force-update-TIMESTAMP/
 *   5. UNZIP přímo do webroot (s ochranou config.local.php)
 *   6. POST-VERIFIKACE: re-read každý kritický soubor, porovnej hash
 *   7. Vyčisti SW + caches (signal browseru)
 *   8. Redirect na /admin/?_freshcache=...
 *
 * Použití:
 *   1. Otevři: https://customer.cz/admin/force-update.php
 *   2. Klik „Spustit force-update"
 *   3. Po dokončení redirect na /admin/ s čerstvými soubory
 *
 * BEZPEČNĚJŠÍ než installer.php protože:
 *   - Žádná manuální FTP manipulace
 *   - Strict integrity check
 *   - Atomic backup před každou změnou
 *   - Vendor server SHA-256 verifikuje correct bundle
 */

// 🔒 v2.6.0 SECURITY FIX (C3): require admin session pro spuštění force-update.
//    Předtím anonymous mohl spustit ?action=run a injektovat libovolný bundle.
session_start();
require_once __DIR__ . '/../api/config.php';
require_once __DIR__ . '/../api/_admin_auth.php';

$forceUpdateUser = aktualni_uzivatel_z_session();
if (!$forceUpdateUser || ($forceUpdateUser['role'] ?? '') !== 'admin') {
    http_response_code(403);
    die('<!DOCTYPE html><html><head><meta charset="UTF-8"><title>403</title></head><body style="font-family:system-ui;padding:40px;text-align:center"><h1>🔒 403 Forbidden</h1><p>Force-update vyžaduje aktivní admin session.</p><p><a href="/admin/">→ Přihlásit se</a></p></body></html>');
}

@set_time_limit(300);
@ini_set('memory_limit', '256M');
header('Cache-Control: no-store, no-cache, must-revalidate');
header('X-Robots-Tag: noindex, nofollow');

// ─── Detect environment ───────────────────────────────────────
$selfDir = __DIR__;
$webroot = realpath($selfDir . '/..') ?: dirname($selfDir);
$configPath = $webroot . '/api/config.local.php';
$appConfigPath = $webroot . '/api/config.php';

$licenseKey = '';
$licenseEmail = '';
$currentVersion = '?';

if (file_exists($configPath)) {
    $raw = @file_get_contents($configPath);
    if (preg_match("/APP_LICENSE_KEY[^']*'([^']+)'/", $raw, $m)) $licenseKey = $m[1];
    if (preg_match("/APP_LICENSE_EMAIL[^']*'([^']+)'/", $raw, $m)) $licenseEmail = $m[1];
}
if (file_exists($appConfigPath)) {
    $raw = @file_get_contents($appConfigPath);
    if (preg_match("/APP_VERSION[^']*'([^']+)'/", $raw, $m)) $currentVersion = $m[1];
}

// ─── HTTP helpers ────────────────────────────────────────────
function http_get(string $url, int $timeout = 30): array {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_TIMEOUT => $timeout, CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_SSL_VERIFYPEER => true,
    ]);
    $body = curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    curl_close($ch);
    return ['body' => $body, 'code' => $code, 'error' => $err];
}

function http_download(string $url, string $dest, int $timeout = 180): array {
    $fp = @fopen($dest, 'w');
    if (!$fp) return ['ok' => false, 'error' => "Cannot write $dest (permission?)"];
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_FILE => $fp, CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_TIMEOUT => $timeout, CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_SSL_VERIFYPEER => true,
    ]);
    $ok = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    curl_close($ch);
    fclose($fp);
    if (!$ok || $code >= 400) {
        @unlink($dest);
        return ['ok' => false, 'error' => $err ?: "HTTP $code"];
    }
    return ['ok' => true, 'size' => filesize($dest), 'http_code' => $code];
}

// ─── ACTION: RUN force update ────────────────────────────────
if (($_REQUEST['action'] ?? '') === 'run') {
    @set_time_limit(0);
    header('Content-Type: application/json; charset=UTF-8');
    $log = [];
    $logAdd = function(string $m) use (&$log) { $log[] = '[' . date('H:i:s') . '] ' . $m; };
    $tmpZip = sys_get_temp_dir() . '/appek-force-update-' . uniqid() . '.zip';
    $stagingDir = sys_get_temp_dir() . '/appek-force-staging-' . uniqid();

    try {
        // ─── 1. Načti manifest ────────────────────────────────
        $logAdd('🔎 Načítám manifest z appek.cz/updates/manifest.json…');
        $r = http_get('https://appek.cz/updates/manifest.json');
        if ($r['code'] >= 400 || !$r['body']) throw new Exception('Manifest fetch failed: ' . $r['error']);
        $manifest = json_decode($r['body'], true);
        if (!$manifest || empty($manifest['latest_version'])) throw new Exception('Invalid manifest');
        $logAdd('✅ Manifest OK · latest_version=' . $manifest['latest_version']
            . ' · size=' . round(($manifest['size_bytes'] ?? 0) / 1024 / 1024, 2) . ' MB');

        $targetVersion = $manifest['latest_version'];
        $expectedSha = $manifest['checksum_sha256'] ?? null;
        $downloadUrl = $manifest['download_url'] ?? null;
        if (!$downloadUrl) throw new Exception('Manifest neobsahuje download_url');

        // ─── 2. Stáhni ZIP ────────────────────────────────────
        $logAdd('⬇️  Stahuji bundle: ' . parse_url($downloadUrl, PHP_URL_PATH));
        $dl = http_download($downloadUrl, $tmpZip);
        if (!$dl['ok']) throw new Exception('Download failed: ' . $dl['error']);
        $logAdd('✅ Staženo · ' . round($dl['size'] / 1024 / 1024, 2) . ' MB');

        // ─── 3. SHA-256 verifikace ────────────────────────────
        if ($expectedSha) {
            $actualSha = hash_file('sha256', $tmpZip);
            if (!hash_equals(strtolower($expectedSha), strtolower($actualSha))) {
                throw new Exception('SHA-256 nesedí — file corrupt nebo tampered. Expected ' . substr($expectedSha, 0, 12) . '…, got ' . substr($actualSha, 0, 12) . '…');
            }
            $logAdd('✅ SHA-256 ověřeno');
        } else {
            $logAdd('⚠️ Manifest nemá expected_checksum (skip SHA verify)');
        }

        // ─── 4. Bundle integrity check ────────────────────────
        if (!class_exists('ZipArchive')) throw new Exception('PHP ZipArchive není dostupný');
        $zip = new ZipArchive();
        if ($zip->open($tmpZip) !== true) throw new Exception('ZIP nelze otevřít');
        $count = $zip->numFiles;
        $hasAdmin = false; $hasB2b = false; $hasApi = false;
        $hasAdminJs = false; $hasAdminIndex = false;
        $hasFilesPrefix = false; $hasManifest = false;
        for ($i = 0; $i < $count; $i++) {
            $n = $zip->getNameIndex($i);
            if ($n === 'manifest.json') $hasManifest = true;
            if (strpos($n, 'files/') === 0) $hasFilesPrefix = true;
        }
        $prefix = ($hasManifest && $hasFilesPrefix) ? 'files/' : '';
        for ($i = 0; $i < $count; $i++) {
            $n = $zip->getNameIndex($i);
            if (strpos($n, $prefix . 'admin/') === 0) $hasAdmin = true;
            if (strpos($n, $prefix . 'b2b/') === 0)   $hasB2b = true;
            if (strpos($n, $prefix . 'api/') === 0)   $hasApi = true;
            if ($n === $prefix . 'admin/admin.js')   $hasAdminJs = true;
            if ($n === $prefix . 'admin/index.html') $hasAdminIndex = true;
        }
        $missing = [];
        if (!$hasAdmin) $missing[] = 'admin/';
        if (!$hasB2b)   $missing[] = 'b2b/';
        if (!$hasApi)   $missing[] = 'api/';
        if (!$hasAdminJs) $missing[] = 'admin/admin.js';
        if (!$hasAdminIndex) $missing[] = 'admin/index.html';
        if (!empty($missing)) {
            $zip->close();
            @unlink($tmpZip);
            throw new Exception('Bundle NEKOMPLETNÍ — chybí: ' . implode(', ', $missing) . '. Vendor server má broken bundle, kontaktuj podpora@appek.cz.');
        }
        $logAdd("✅ Bundle integrity OK · {$count} files · prefix='{$prefix}' · admin/✓ b2b/✓ api/✓");

        // ─── 5. Extract do staging ────────────────────────────
        @mkdir($stagingDir, 0755, true);
        if (!$zip->extractTo($stagingDir)) {
            $zip->close();
            throw new Exception('ZIP extract selhal — kontrola oprávnění /tmp');
        }
        $zip->close();
        $logAdd('✅ Rozbaleno do staging');

        // Skutečný staging dir má soubory s prefixem (bundle) nebo přímo (raw)
        $sourceRoot = $prefix ? rtrim($stagingDir . '/' . trim($prefix, '/'), '/') : $stagingDir;
        if (!is_dir($sourceRoot)) throw new Exception('Staging dir struktura nečekaná: ' . $sourceRoot);

        // ─── 6. Build file list (vše z source) ─────────────────
        $protectedPaths = ['api/config.local.php', 'api/.installed', 'vendor/config.local.php', 'vendor/.installed'];
        $fileList = [];
        $iter = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($sourceRoot, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::LEAVES_ONLY
        );
        foreach ($iter as $f) {
            if (!$f->isFile()) continue;
            $abs = $f->getPathname();
            $rel = ltrim(str_replace('\\', '/', substr($abs, strlen($sourceRoot))), '/');
            if ($rel === 'manifest.json') continue;
            if (in_array($rel, $protectedPaths, true)) continue;
            $fileList[$rel] = $abs;
        }
        $logAdd('📋 File list: ' . count($fileList) . ' souborů k aplikaci');

        // ─── 7. Backup ──────────────────────────────────────
        $backupDir = $webroot . '/api/zalohy/force-update-' . date('YmdHis') . '-v' . $targetVersion;
        @mkdir($backupDir, 0755, true);
        if (!is_dir($backupDir)) throw new Exception('Cannot create backup dir');
        $backed = 0;
        foreach ($fileList as $rel => $_) {
            $src = $webroot . '/' . $rel;
            if (file_exists($src)) {
                $bp = $backupDir . '/' . $rel;
                @mkdir(dirname($bp), 0755, true);
                if (@copy($src, $bp)) $backed++;
            }
        }
        $logAdd("💾 Backup vytvořen · $backed souborů (api/zalohy/" . basename($backupDir) . ')');

        // ─── 8. Apply ──────────────────────────────────────
        $applied = 0;
        $failed = [];
        foreach ($fileList as $rel => $src) {
            $dst = $webroot . '/' . $rel;
            @mkdir(dirname($dst), 0755, true);
            if (@copy($src, $dst)) {
                $applied++;
            } else {
                $failed[] = $rel;
            }
        }
        $logAdd("✅ Aplikováno $applied souborů" . ($failed ? ' (failed: ' . count($failed) . ')' : ''));

        // ─── 9. POST-VERIFIKACE kritických ───────────────────
        $criticalFiles = ['admin/admin.js', 'admin/index.html', 'admin/sw.js', 'api/config.php'];
        $verifyOk = []; $verifyFail = [];
        foreach ($criticalFiles as $rel) {
            $srcPath = $sourceRoot . '/' . $rel;
            $dstPath = $webroot . '/' . $rel;
            if (!file_exists($srcPath) || !file_exists($dstPath)) {
                $verifyFail[$rel] = 'missing'; continue;
            }
            if (hash_file('sha256', $srcPath) !== hash_file('sha256', $dstPath)) {
                $verifyFail[$rel] = 'mismatch';
            } else {
                $verifyOk[] = $rel;
            }
        }
        if (!empty($verifyFail)) {
            $logAdd('❌ POST-VERIFIKACE: ' . count($verifyFail) . ' souborů má wrong hash: ' . implode(', ', array_keys($verifyFail)));
            throw new Exception('Post-verifikace neprošla — backup je v ' . basename($backupDir) . '. Možná permission issue na hostingu.');
        }
        $logAdd('✅ POST-VERIFIKACE: všech ' . count($verifyOk) . ' kritických souborů má správný hash');

        // ─── 10. Manifest ────────────────────────────────────
        $manifestPath = $webroot . '/api/.update-manifest.json';
        @file_put_contents($manifestPath, json_encode([
            'version' => $targetVersion,
            'applied_at' => date('c'),
            'applied_by' => 'force-update.php',
            'files_applied' => $applied,
            'verified_critical' => $verifyOk,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        $logAdd('📄 .update-manifest.json zapsán');

        // ─── 11. Cleanup ─────────────────────────────────────
        @unlink($tmpZip);
        if (is_dir($stagingDir)) {
            $rri = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($stagingDir, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
            foreach ($rri as $f) { $f->isDir() ? @rmdir($f->getPathname()) : @unlink($f->getPathname()); }
            @rmdir($stagingDir);
        }

        // Flag pro browser side cache clear
        echo json_encode([
            'ok' => true,
            'version' => $targetVersion,
            'previous' => $currentVersion,
            'files_applied' => $applied,
            'backup_dir' => basename($backupDir),
            'log' => $log,
            'redirect' => '/admin/?_freshcache=' . urlencode($targetVersion) . '-' . time(),
        ]);

    } catch (Throwable $e) {
        @unlink($tmpZip);
        $logAdd('❌ Chyba: ' . $e->getMessage());
        http_response_code(500);
        echo json_encode([
            'ok' => false,
            'error' => $e->getMessage(),
            'log' => $log,
        ]);
    }
    exit;
}

?><!DOCTYPE html>
<html lang="cs">
<head>
<meta charset="UTF-8">
<meta http-equiv="Cache-Control" content="no-cache, no-store, must-revalidate">
<meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="robots" content="noindex,nofollow">
<title>🚀 APPEK Force Update</title>
<style>
  * { box-sizing: border-box; margin: 0; padding: 0; }
  body { font-family: -apple-system, BlinkMacSystemFont, sans-serif; background: linear-gradient(135deg, #FAEEDA 0%, #FFF8E5 100%); min-height: 100vh; padding: 20px; color: #1d1d1f; display: flex; align-items: center; justify-content: center; }
  .card { max-width: 720px; width: 100%; background: #fff; border-radius: 18px; padding: 36px 40px; box-shadow: 0 20px 60px rgba(186,117,23,0.15); }
  .icon { font-size: 56px; text-align: center; margin-bottom: 8px; line-height: 1; }
  h1 { font-size: 24px; text-align: center; margin-bottom: 6px; letter-spacing: -0.02em; }
  .sub { text-align: center; color: #6e6e73; font-size: 14px; margin-bottom: 24px; line-height: 1.5; }
  .grid { display: grid; grid-template-columns: 1fr 1fr; gap: 12px; margin-bottom: 20px; }
  .stat { background: #f7f8fa; padding: 14px 16px; border-radius: 12px; }
  .stat label { font-size: 11px; color: #6e6e73; text-transform: uppercase; letter-spacing: 0.5px; font-weight: 700; }
  .stat strong { display: block; font-size: 16px; margin-top: 4px; font-family: 'SF Mono', Menlo, monospace; word-break: break-word; }
  .alert { padding: 14px 18px; border-radius: 12px; font-size: 13.5px; line-height: 1.55; margin-bottom: 16px; }
  .alert.info { background: #E6F1FB; color: #0C447C; border: 1px solid #b8daff; }
  .alert.warn { background: #FFF8E5; color: #854F0B; border: 1px solid #FBBF24; }
  .alert.err  { background: #FEE2E2; color: #991B1B; border: 1px solid #FCA5A5; }
  .alert.ok   { background: #d4edda; color: #155724; border: 1px solid #86EFAC; }
  .btn { padding: 14px 28px; border-radius: 12px; border: none; cursor: pointer; font-size: 15px; font-weight: 700; font-family: inherit; transition: all 0.15s; }
  .btn-primary { background: linear-gradient(180deg, #BA7517, #854F0B); color: #fff; width: 100%; box-shadow: 0 4px 12px rgba(186,117,23,0.3); }
  .btn-primary:hover { transform: translateY(-1px); box-shadow: 0 6px 16px rgba(186,117,23,0.4); }
  .btn-primary:disabled { opacity: 0.5; cursor: not-allowed; transform: none; }
  pre { background: #1d1d1f; color: #fff; padding: 14px 16px; border-radius: 10px; font-size: 12px; max-height: 320px; overflow-y: auto; line-height: 1.7; margin-top: 12px; }
  pre .ok { color: #4ade80; }
  pre .err { color: #ff6b6b; }
  details { margin-top: 12px; padding: 12px; background: #f7f8fa; border-radius: 10px; font-size: 12.5px; }
  summary { cursor: pointer; font-weight: 700; }
</style>
</head>
<body>
<div class="card">
  <div class="icon">🚀</div>
  <h1>APPEK Force Update</h1>
  <div class="sub">Standalone recovery — funguje i kdyby admin.js byl rozbitý nebo cache zaseklá.</div>

  <div class="grid">
    <div class="stat"><label>Aktuální verze</label><strong id="curVer"><?= htmlspecialchars($currentVersion) ?></strong></div>
    <div class="stat"><label>Licence</label><strong><?= $licenseKey ? htmlspecialchars(substr($licenseKey, 0, 12)) . '…' : '⚠️ NENALEZENA' ?></strong></div>
  </div>

  <?php if (!file_exists($configPath)): ?>
    <div class="alert err">❌ <strong>config.local.php nenalezen.</strong> Tato stránka musí být v <code>customer.cz/admin/</code>.</div>
  <?php else: ?>
    <div class="alert info">
      💡 <strong>Co se stane:</strong>
      <ol style="margin:6px 0 0;padding-left:22px;line-height:1.7">
        <li>Stáhne nejnovější bundle z <code>appek.cz/updates/manifest.json</code></li>
        <li>Verifikuje SHA-256 + bundle integrity (musí mít admin/ + b2b/ + api/)</li>
        <li>Vytvoří backup do <code>api/zalohy/force-update-TIMESTAMP/</code></li>
        <li>Aplikuje VŠECHNY soubory (kromě <code>config.local.php</code>)</li>
        <li>POST-VERIFIKUJE hash kritických souborů</li>
        <li>Vyčistí browser cache + redirect na <code>/admin/</code></li>
      </ol>
    </div>
  <?php endif; ?>

  <button class="btn btn-primary" id="run-btn" onclick="runUpdate()" <?= file_exists($configPath) ? '' : 'disabled' ?>>
    🚀 Spustit force-update
  </button>

  <div id="result" style="display:none"></div>

  <details>
    <summary>ℹ️ Kdy použít force-update.php</summary>
    <ul style="line-height:1.7;margin:8px 0 0;padding-left:22px">
      <li>APP_VERSION říká novou verzi, ale UI vypadá staře</li>
      <li>Update se zdá proběhnout, ale změny se neprojeví</li>
      <li>verify-version.php ukazuje „admin.js je STARÝ" (pre-2.0.74)</li>
      <li>Clear-cache.html nepomohl</li>
      <li>Customer install má hybrid file state (config = nový, JS = starý)</li>
    </ul>
  </details>
</div>

<script>
async function runUpdate() {
  const btn = document.getElementById('run-btn');
  const res = document.getElementById('result');
  btn.disabled = true;
  btn.textContent = '⏳ Updatuji…';
  res.style.display = 'block';
  res.innerHTML = '<div class="alert info">⏳ Stahuji bundle z appek.cz a aplikuji… (může trvat 30-90 s)</div><pre id="log">Spouštím…</pre>';

  try {
    const r = await fetch('?action=run', { method: 'POST', cache: 'no-store' });
    const d = await r.json();
    const logEl = document.getElementById('log');
    if (d.log) logEl.innerHTML = d.log.map(l => {
      if (l.includes('❌')) return '<span class="err">' + l + '</span>';
      if (l.includes('✅')) return '<span class="ok">' + l + '</span>';
      return l;
    }).join('\n');

    if (d.ok) {
      res.insertAdjacentHTML('afterbegin', `<div class="alert ok">🎉 <strong>Hotovo!</strong> Aktualizováno z v${d.previous} na <strong>v${d.version}</strong>. Aplikováno ${d.files_applied} souborů. Backup: <code>${d.backup_dir}</code></div>`);
      btn.textContent = '✅ Hotovo · Přesměrovávám…';

      // Clear browser caches + redirect
      try {
        if ('serviceWorker' in navigator) {
          const regs = await navigator.serviceWorker.getRegistrations();
          for (const reg of regs) await reg.unregister();
        }
        if ('caches' in window) {
          const keys = await caches.keys();
          await Promise.all(keys.map(k => caches.delete(k)));
        }
      } catch (e) {}

      setTimeout(() => {
        location.href = d.redirect || '/admin/?_freshcache=' + Date.now();
      }, 2500);
    } else {
      res.insertAdjacentHTML('afterbegin', `<div class="alert err">❌ <strong>Update selhal:</strong> ${d.error || 'unknown'}</div>`);
      btn.disabled = false;
      btn.textContent = '🔄 Zkusit znovu';
    }
  } catch (e) {
    res.insertAdjacentHTML('afterbegin', `<div class="alert err">❌ Network error: ${e.message}</div>`);
    btn.disabled = false;
    btn.textContent = '🔄 Zkusit znovu';
  }
}
</script>
</body>
</html>
