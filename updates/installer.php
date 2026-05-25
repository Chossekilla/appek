<?php
/**
 * 🚀 APPEK STANDALONE UPDATER — kompletní single-file installer.
 *
 * Když má zákazník BROKEN customer instalaci (např. starý 2.0.63 s rozbitým
 * updaterem), stáhne si tento soubor z https://appek.cz/updates/installer.php,
 * nahraje ho přes FTP do svého /admin/ folderu, otevře v browseru a klikne
 * Update → vše proběhne automaticky.
 *
 * Co dělá:
 *   1. Auto-detekuje config.local.php → načte license key + APP_VERSION
 *   2. Volá appek.cz/updates/manifest.json → získá latest version + download URL
 *   3. Stáhne customer ZIP (build-zip output, ne bundle)
 *   4. Validuje SHA-256
 *   5. Vytvoří backup do api/zalohy/installer-backup-TIMESTAMP/
 *   6. Rozbalí ZIP přímo do webroot (přepíše api/, admin/, b2b/, install.php)
 *   7. Self-deletes (security)
 *
 * NE vyžaduje aby starý updater fungoval. Toto je bootstrap.
 *
 * Použití:
 *   1. Stáhni: https://appek.cz/updates/installer.php
 *   2. Nahraj přes FTP do: tvoje-app.cz/admin/installer.php
 *   3. Otevři: https://tvoje-app.cz/admin/installer.php
 *   4. Klikni "Spustit update"
 *   5. Po úspěchu se sám smaže
 */

// Bezpečnost — žádný HTTP cache, žádný indexing
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('X-Robots-Tag: noindex, nofollow');

@set_time_limit(300);
@ini_set('memory_limit', '256M');

// =============================================================
// 🔍 AUTO-DETECT: kde jsem? (webroot, config, license)
// =============================================================
$selfDir = __DIR__;
// Pravděpodobně /admin/ → webroot je o úroveň výš
$webroot = realpath($selfDir . '/..') ?: dirname($selfDir);

$configCandidates = [
    $webroot . '/api/config.local.php',
    $selfDir . '/config.local.php',
];
$config = null;
$licenseKey = '';
$currentVersion = '0.0.0';

foreach ($configCandidates as $cfgFile) {
    if (file_exists($cfgFile)) {
        $config = $cfgFile;
        // Načti license & version BEZ require (nechceme znečistit namespace)
        $content = @file_get_contents($cfgFile);
        if (preg_match("/APP_LICENSE_KEY[^']*'([^']+)'/", $content, $m)) {
            $licenseKey = $m[1];
        }
        break;
    }
}

// APP_VERSION ze config.php (ne local)
$configPhp = $webroot . '/api/config.php';
if (file_exists($configPhp)) {
    $content = @file_get_contents($configPhp);
    if (preg_match("/APP_VERSION[^']*'([^']+)'/", $content, $m)) {
        $currentVersion = $m[1];
    }
}

// Default endpoint
$manifestUrl = 'https://appek.cz/updates/manifest.json';

// =============================================================
// 📥 DOWNLOAD RAW SOURCE — pro FTP upload na rozbitou instalaci
// (Pokud někdo na appek.cz/updates/installer.php?download=raw klikne)
// =============================================================
if (isset($_GET['download']) && $_GET['download'] === 'raw') {
    header('Content-Type: application/octet-stream');
    header('Content-Disposition: attachment; filename="installer.php"');
    header('Cache-Control: no-store');
    readfile(__FILE__);
    exit;
}

// =============================================================
// 🎬 ACTIONS
// =============================================================
$action = $_REQUEST['action'] ?? '';
$result = null;

function http_get(string $url, int $timeout = 30): array {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_TIMEOUT        => $timeout,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_HTTPHEADER     => ['Accept: application/json', 'User-Agent: AppekInstaller/1.0'],
    ]);
    $body = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err  = curl_error($ch);
    curl_close($ch);
    return ['body' => $body, 'code' => $code, 'error' => $err];
}

function http_download(string $url, string $dest, int $timeout = 120): array {
    $fp = @fopen($dest, 'w');
    if (!$fp) return ['ok' => false, 'error' => "Cannot write $dest"];
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_FILE           => $fp,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_TIMEOUT        => $timeout,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_SSL_VERIFYPEER => true,
    ]);
    $ok = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err  = curl_error($ch);
    curl_close($ch);
    fclose($fp);
    if (!$ok || $code >= 400) {
        @unlink($dest);
        return ['ok' => false, 'error' => $err ?: "HTTP $code"];
    }
    return ['ok' => true, 'size' => filesize($dest)];
}

if ($action === 'check') {
    header('Content-Type: application/json; charset=UTF-8');
    $r = http_get($manifestUrl);
    if ($r['code'] >= 400 || !$r['body']) {
        echo json_encode(['ok' => false, 'error' => 'manifest_fetch_failed', 'detail' => $r['error']]);
        exit;
    }
    $manifest = json_decode($r['body'], true);
    if (!$manifest || empty($manifest['latest_version'])) {
        echo json_encode(['ok' => false, 'error' => 'invalid_manifest']);
        exit;
    }
    $has = version_compare($currentVersion, $manifest['latest_version'], '<');
    echo json_encode([
        'ok' => true,
        'has_update'   => $has,
        'current'      => $currentVersion,
        'latest'       => $manifest['latest_version'],
        'download_url' => $manifest['download_url'] ?? null,
        'checksum'     => $manifest['checksum_sha256'] ?? null,
        'size'         => $manifest['size_bytes'] ?? null,
        'changelog'    => $manifest['changelog'] ?? '',
    ]);
    exit;
}

if ($action === 'apply') {
    @set_time_limit(0);
    header('Content-Type: application/json; charset=UTF-8');
    $log = [];
    $logAdd = function(string $m) use (&$log) { $log[] = '[' . date('H:i:s') . '] ' . $m; };

    try {
        $r = http_get($manifestUrl);
        if ($r['code'] >= 400 || !$r['body']) throw new Exception('Cannot fetch manifest: ' . $r['error']);
        $manifest = json_decode($r['body'], true);
        if (!$manifest || empty($manifest['latest_version'])) throw new Exception('Invalid manifest');
        $logAdd('✅ Manifest staženo · latest=' . $manifest['latest_version']);

        $downloadUrl = $manifest['download_url'] ?? null;
        if (!$downloadUrl) throw new Exception('Manifest neobsahuje download_url');

        // Pokud URL nemá &key= a máme licenci, přidej ji
        if ($licenseKey && strpos($downloadUrl, 'key=') === false) {
            $sep = strpos($downloadUrl, '?') === false ? '?' : '&';
            $downloadUrl .= $sep . 'key=' . urlencode($licenseKey);
        }
        $logAdd('🔽 Stahuji: ' . parse_url($downloadUrl, PHP_URL_PATH));

        $tmpZip = sys_get_temp_dir() . '/appek-installer-' . uniqid() . '.zip';
        $dl = http_download($downloadUrl, $tmpZip);
        if (!$dl['ok']) throw new Exception('Download failed: ' . $dl['error']);
        $logAdd('✅ Staženo · ' . round($dl['size'] / 1024 / 1024, 2) . ' MB');

        // SHA-256 ověření
        if (!empty($manifest['checksum_sha256'])) {
            $actual = hash_file('sha256', $tmpZip);
            if (!hash_equals(strtolower($manifest['checksum_sha256']), strtolower($actual))) {
                @unlink($tmpZip);
                throw new Exception('Checksum SHA-256 nesedí (file corrupt or tampered)');
            }
            $logAdd('✅ SHA-256 ověřeno');
        }

        // Backup
        $backupDir = $webroot . '/api/zalohy/installer-backup-' . date('YmdHis');
        @mkdir($backupDir, 0755, true);
        if (!is_dir($backupDir)) throw new Exception("Cannot create backup dir: $backupDir");

        // Záloh klíčových složek
        foreach (['api', 'admin', 'b2b'] as $d) {
            if (is_dir("$webroot/$d")) {
                @exec('cp -R ' . escapeshellarg("$webroot/$d") . ' ' . escapeshellarg("$backupDir/$d"));
            }
        }
        $logAdd('💾 Backup vytvořen: ' . basename($backupDir));

        // Extract ZIP přímo do webroot
        $zip = new ZipArchive();
        if ($zip->open($tmpZip) !== true) throw new Exception('ZIP nelze otevřít');

        $count = $zip->numFiles;
        // Bezpečnostní hard-skip — nikdy nepřepisuj config.local.php
        $skipPaths = ['api/config.local.php', 'vendor/config.local.php', 'api/.installed'];

        $applied = 0;
        $skipped = 0;
        for ($i = 0; $i < $count; $i++) {
            $name = $zip->getNameIndex($i);
            // Skip directories
            if (substr($name, -1) === '/') continue;
            // Skip protected
            foreach ($skipPaths as $sp) {
                if ($name === $sp) { $skipped++; continue 2; }
            }
            // Pokud bundle (files/ prefix) → strip prefix
            $target = $name;
            if (strpos($target, 'files/') === 0) $target = substr($target, 6);
            // Skip manifest.json (jen v bundle formátu)
            if ($target === 'manifest.json' && strpos($name, 'files/') === false) {
                $skipped++; continue;
            }

            $destPath = $webroot . '/' . $target;
            $destDir = dirname($destPath);
            if (!is_dir($destDir)) @mkdir($destDir, 0755, true);

            $content = $zip->getFromIndex($i);
            if ($content === false) continue;
            if (@file_put_contents($destPath, $content) !== false) {
                $applied++;
            }
        }
        $zip->close();
        @unlink($tmpZip);

        $logAdd("✅ Aplikováno {$applied} souborů (skipped: {$skipped})");

        // Update version stamp
        @file_put_contents($webroot . '/api/.version', $manifest['latest_version']);

        // Self-delete (security — nesmí zůstat na webu)
        $selfPath = __FILE__;
        register_shutdown_function(function() use ($selfPath) {
            @unlink($selfPath);
        });
        $logAdd('🧹 Self-delete naplánován');

        echo json_encode([
            'ok' => true,
            'version' => $manifest['latest_version'],
            'files_applied' => $applied,
            'backup_dir' => basename($backupDir),
            'log' => $log,
            'message' => '✅ Update na ' . $manifest['latest_version'] . ' dokončen. Doporučuji refresh (Cmd+Shift+R).',
        ]);
    } catch (Throwable $e) {
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
<meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="robots" content="noindex,nofollow">
<title>🚀 APPEK Installer</title>
<style>
  * { box-sizing: border-box; }
  body { font-family: -apple-system, system-ui, sans-serif; background: #f5f5f7; margin: 0; padding: 24px; color: #1d1d1f; }
  .card { max-width: 720px; margin: 0 auto; background: #fff; border-radius: 16px; padding: 32px; box-shadow: 0 4px 20px rgba(0,0,0,0.06); }
  h1 { margin: 0 0 6px; font-size: 24px; }
  .sub { color: #6e6e73; font-size: 13.5px; margin-bottom: 24px; }
  .grid { display: grid; grid-template-columns: 1fr 1fr; gap: 12px; margin-bottom: 20px; }
  .stat { background: #f5f5f7; padding: 12px 14px; border-radius: 10px; }
  .stat label { font-size: 11px; color: #6e6e73; text-transform: uppercase; letter-spacing: 0.5px; font-weight: 700; }
  .stat strong { display: block; font-size: 16px; margin-top: 4px; font-family: 'SF Mono', Menlo, monospace; }
  .alert { padding: 14px 16px; border-radius: 10px; font-size: 13.5px; line-height: 1.5; margin-bottom: 16px; }
  .alert.info { background: #e7f3ff; color: #004085; border: 1px solid #b8daff; }
  .alert.warn { background: #fff8e5; color: #854F0B; border: 1px solid #ffeeba; }
  .alert.ok   { background: #d4edda; color: #155724; border: 1px solid #c3e6cb; }
  .alert.err  { background: #f8d7da; color: #721c24; border: 1px solid #f5c6cb; }
  button { padding: 14px 24px; border-radius: 12px; border: none; font-size: 15px; font-weight: 700; cursor: pointer; font-family: inherit; transition: all 0.15s; }
  .btn-primary { background: linear-gradient(180deg, #BA7517, #854F0B); color: #fff; width: 100%; }
  .btn-primary:hover { transform: translateY(-1px); box-shadow: 0 4px 12px rgba(186,117,23,0.3); }
  .btn-primary:disabled { opacity: 0.5; cursor: not-allowed; transform: none; box-shadow: none; }
  pre { background: #1d1d1f; color: #fff; padding: 16px; border-radius: 10px; font-size: 12px; max-height: 360px; overflow: auto; line-height: 1.6; }
  details { background: #f5f5f7; padding: 12px; border-radius: 8px; margin-top: 12px; font-size: 12.5px; }
  summary { cursor: pointer; font-weight: 700; }
</style>
</head>
<body>
<div class="card">
  <h1>🚀 APPEK Installer</h1>
  <div class="sub">Univerzální update pro <strong>zaseknuté instalace</strong>. Stáhne nejnovější verzi z appek.cz a aplikuje. Self-deletes po úspěchu.</div>

  <div class="grid">
    <div class="stat"><label>Aktuální verze</label><strong id="curVer"><?= htmlspecialchars($currentVersion) ?></strong></div>
    <div class="stat"><label>Detekovaný webroot</label><strong style="font-size:12px"><?= htmlspecialchars(basename($webroot) . '/') ?></strong></div>
    <div class="stat"><label>Licence</label><strong id="licChip"><?= $licenseKey ? htmlspecialchars(substr($licenseKey, 0, 12)) . '…' : '⚠️ NENALEZENA' ?></strong></div>
    <div class="stat"><label>Config</label><strong style="font-size:12px"><?= $config ? '✅ Načten' : '❌ Chybí' ?></strong></div>
  </div>

  <?php if (!$config): ?>
    <div class="alert warn">
      ⚠️ <strong>Tady na appek.cz to nemá kde běžet.</strong> Tento installer je určen pro <strong>tvoji</strong> instalaci.
      <br><br>
      📥 <strong>Stáhni si <code>installer.php</code></strong> a nahraj přes FTP / cPanel File Manager do svého <code>/admin/</code> folderu.
      Pak otevři <code>https://tvoje-app.cz/admin/installer.php</code> a klikni Update.
      <br><br>
      <a href="?download=raw" style="display:inline-block;padding:10px 20px;background:#BA7517;color:#fff;border-radius:8px;text-decoration:none;font-weight:700">📥 Stáhnout installer.php</a>
    </div>
  <?php elseif (!$licenseKey): ?>
    <div class="alert warn">
      ⚠️ <strong>License key nenalezen v config.local.php.</strong> Download poběží anonymně — pokud vendor vyžaduje licenci, selže.
    </div>
  <?php else: ?>
    <div class="alert info">
      💡 Klikni <strong>Zkontrolovat update</strong> — pokud je k dispozici novější verze, ukáže se tlačítko pro apply. Před aplikací se vytvoří backup do <code>api/zalohy/</code>.
    </div>
  <?php endif; ?>

  <div id="checkResult"></div>

  <button class="btn-primary" id="checkBtn" onclick="checkUpdate()">🔎 Zkontrolovat update</button>

  <div id="applyArea" style="display:none;margin-top:20px"></div>

  <details>
    <summary>ℹ️ Co installer dělá pod kapotou</summary>
    <ol style="line-height:1.7;margin:8px 0 0;padding-left:20px">
      <li>Volá <code>https://appek.cz/updates/manifest.json</code> → získá latest version + download URL</li>
      <li>Porovná se s <code>APP_VERSION</code> z <code>api/config.php</code></li>
      <li>Pokud je update: stáhne ZIP, ověří SHA-256</li>
      <li>Vytvoří backup do <code>api/zalohy/installer-backup-TIMESTAMP/</code> (api, admin, b2b)</li>
      <li>Rozbalí ZIP přímo do webroot (přeskočí <code>config.local.php</code>, <code>.installed</code>)</li>
      <li>Po úspěchu se sám smaže ze serveru (security)</li>
    </ol>
  </details>
</div>

<script>
async function checkUpdate() {
  const btn = document.getElementById('checkBtn');
  btn.disabled = true; btn.textContent = '⏳ Kontroluji…';
  document.getElementById('checkResult').innerHTML = '';

  try {
    const r = await fetch('?action=check', { cache: 'no-store' });
    const d = await r.json();
    if (!d.ok) { document.getElementById('checkResult').innerHTML = `<div class="alert err">❌ ${d.error}: ${d.detail || ''}</div>`; btn.disabled = false; btn.textContent = '🔄 Zkusit znovu'; return; }

    if (!d.has_update) {
      document.getElementById('checkResult').innerHTML = `<div class="alert ok">✅ Jsi na nejnovější verzi <strong>${d.current}</strong>. Žádný update.</div>`;
      btn.textContent = '✅ Aktuální';
      return;
    }

    document.getElementById('checkResult').innerHTML = `
      <div class="alert info">
        🆕 <strong>Dostupný update: ${d.latest}</strong> (máš ${d.current})<br>
        Velikost: ${d.size ? (d.size/1024/1024).toFixed(2)+' MB' : '?'}<br>
        ${d.changelog ? `<details style="margin-top:8px;background:transparent;padding:0"><summary>📝 Changelog</summary><pre style="background:#fff;color:#1d1d1f;font-size:11.5px;margin-top:6px">${d.changelog.replace(/</g,'&lt;')}</pre></details>` : ''}
      </div>`;
    btn.style.display = 'none';
    document.getElementById('applyArea').style.display = 'block';
    document.getElementById('applyArea').innerHTML = `
      <button class="btn-primary" onclick="applyUpdate()">⚡ Spustit update na ${d.latest}</button>
      <div style="margin-top:8px;font-size:11.5px;color:#6e6e73;text-align:center">Backup se vytvoří automaticky. Po úspěchu se installer sám smaže.</div>`;
  } catch (e) {
    document.getElementById('checkResult').innerHTML = `<div class="alert err">❌ ${e.message}</div>`;
    btn.disabled = false; btn.textContent = '🔄 Zkusit znovu';
  }
}

async function applyUpdate() {
  const area = document.getElementById('applyArea');
  area.innerHTML = '<div class="alert info">⏳ Aplikuji update… (může trvat 30-60 s)</div><pre id="applyLog">Spouštím…</pre>';
  try {
    const r = await fetch('?action=apply', { method: 'POST', cache: 'no-store' });
    const d = await r.json();
    const logEl = document.getElementById('applyLog');
    if (d.log) logEl.textContent = d.log.join('\n');
    if (d.ok) {
      area.insertAdjacentHTML('afterbegin', `<div class="alert ok">🎉 ${d.message}</div>`);
    } else {
      area.insertAdjacentHTML('afterbegin', `<div class="alert err">❌ ${d.error}</div>`);
    }
  } catch (e) {
    area.insertAdjacentHTML('afterbegin', `<div class="alert err">❌ ${e.message}</div>`);
  }
}
</script>
</body>
</html>
