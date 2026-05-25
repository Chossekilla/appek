<?php
/**
 * 🚀 SELF-UPDATE — Aktualizace vendor.appek.cz nahráním MASTER zipu.
 *
 * Workflow:
 *   1. Lokálně:  ./build-zip.sh 2.0.37  →  appek-MASTER-v2.0.37.zip
 *   2. Tady upload → auto-extract → vendor/ + sales + api + landing aktualizováno
 *   3. Zachovává: vendor/config.local.php, vendor/.installed, api/config.local.php, api/.installed,
 *                 vendor/updates_storage/* (customer balíčky)
 *   4. Pre-flight: validace ZIPu (musí mít vendor/_lib.php, index.html, api/_license.php)
 *   5. Pre-extract: backup vendor/ + root html do /tmp (rollback při chybě)
 *   6. Post-extract: ověří klíčové soubory, jinak rollback
 *
 * Bezpečnost:
 *   - Pouze pro role 'admin' (vendor_require_login + role check)
 *   - Soubor max 100 MB
 *   - Whitelist přípony .zip
 *   - Validace ZIP signature
 */
require_once __DIR__ . '/_lib.php';
require_once __DIR__ . '/_layout.php';
require_once __DIR__ . '/_self_update.php';

$user = vendor_require_login();
$currentPage = 'self-update';

// Pouze admin
if (($user['role'] ?? '') !== 'admin') {
    http_response_code(403);
    echo '<h1>403 Forbidden</h1><p>Pouze admin smí spouštět self-update.</p>';
    exit;
}

$flash_ok = null;
$flash_err = null;
$progressLog = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['master_zip'])) {
    if ($_FILES['master_zip']['error'] !== UPLOAD_ERR_OK) {
        $flash_err = 'Upload chyba: kód ' . $_FILES['master_zip']['error'];
    } elseif ($_FILES['master_zip']['size'] > 100 * 1024 * 1024) {
        $flash_err = 'Soubor je příliš velký (max 100 MB).';
    } elseif (strtolower(pathinfo($_FILES['master_zip']['name'], PATHINFO_EXTENSION)) !== 'zip') {
        $flash_err = 'Pouze .zip soubory.';
    } else {
        $res = self_update_apply($_FILES['master_zip']['tmp_name'], $progressLog);
        if ($res['ok']) {
            try { vendor_audit(vendor_db(), $user, 'self_update', null, basename($_FILES['master_zip']['name'])); }
            catch (Throwable $e) {}
            $flash_ok = "✅ Self-update dokončen — verze {$res['version']}. Doporučení: hard refresh (Ctrl+Shift+R).";
        } else {
            $flash_err = $res['error'];
        }
    }
}

?><!DOCTYPE html>
<html lang="cs">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="robots" content="noindex,nofollow">
<title>🚀 Self-update — APPEK Master</title>
<link rel="stylesheet" href="style.css?v=2.0.38">
<style>
  .su-card { background: #fff; border-radius: 14px; padding: 28px 32px; box-shadow: 0 1px 3px rgba(0,0,0,0.05); margin-bottom: 20px; }
  .su-card h2 { margin: 0 0 12px; font-size: 18px; }
  .su-step {
    display: flex; gap: 14px; padding: 14px 0; border-bottom: 1px solid #f0f0f3;
  }
  .su-step:last-child { border-bottom: none; }
  .su-step .num {
    width: 32px; height: 32px; border-radius: 50%;
    background: linear-gradient(135deg, #BA7517, #854F0B); color: #fff;
    display: inline-flex; align-items: center; justify-content: center;
    font-weight: 700; font-size: 14px; flex-shrink: 0;
  }
  .su-step .body strong { display: block; font-size: 14px; margin-bottom: 3px; }
  .su-step .body { color: #6e6e73; font-size: 13px; line-height: 1.5; }
  .su-step .body code { background: #f5f5f7; padding: 2px 6px; border-radius: 4px; font-family: 'SF Mono', monospace; font-size: 12px; color: #1d1d1f; }

  .upload-zone {
    border: 2px dashed #d2d2d7; border-radius: 14px; padding: 36px 24px;
    text-align: center; background: #fafafa; transition: all 0.2s;
  }
  .upload-zone:hover, .upload-zone.dragover {
    border-color: #BA7517; background: #fff8e8;
  }
  .upload-zone .ico { font-size: 48px; margin-bottom: 12px; }
  .upload-zone input[type="file"] { display: block; margin: 14px auto; }
  .upload-zone .fname { font-weight: 600; color: #1d1d1f; margin-top: 8px; }

  .progress-log {
    background: #1d1d1f; color: #fff; border-radius: 10px; padding: 16px 20px;
    font-family: 'SF Mono', Menlo, monospace; font-size: 12.5px; line-height: 1.7;
    max-height: 320px; overflow-y: auto;
  }
  .progress-log .ok { color: #34c759; }
  .progress-log .err { color: #ff6b6b; }

  .alert { padding: 14px 18px; border-radius: 10px; margin-bottom: 14px; font-size: 14px; line-height: 1.6; }
  .alert.ok  { background: #d4edda; color: #155724; white-space: pre-line; }
  .alert.err { background: #f8d7da; color: #721c24; }
  .alert.info { background: rgba(0,122,255,0.10); color: #0058b8; }

  .btn-deploy {
    width: 100%; padding: 14px; margin-top: 14px;
    background: linear-gradient(180deg, #BA7517, #854F0B);
    color: #fff; border: none; border-radius: 10px;
    font-weight: 700; font-size: 15px; cursor: pointer;
  }
  .btn-deploy:hover { opacity: 0.92; transform: translateY(-1px); box-shadow: 0 4px 12px rgba(186,117,23,0.3); }
  .btn-deploy[disabled] { opacity: 0.5; cursor: not-allowed; transform: none; box-shadow: none; }

  .danger-note {
    background: #FEF3C7; color: #92400E; padding: 12px 16px; border-radius: 10px;
    font-size: 13px; line-height: 1.6; margin: 14px 0;
  }
</style>
</head>
<body>

<?php vendor_render_topbar($user, $currentPage); ?>
<?php vendor_render_back(); ?>

<main class="page-master">

  <div class="page-header-master">
    <h1>🚀 Self-update</h1>
    <div style="font-size:13px;color:#86868b">Aktualizace vendor.appek.cz nahráním MASTER zipu</div>
  </div>

  <?php if ($flash_ok): ?><div class="alert ok">✅ <?= htmlspecialchars($flash_ok) ?></div><?php endif; ?>
  <?php if ($flash_err): ?><div class="alert err">❌ <?= htmlspecialchars($flash_err) ?></div><?php endif; ?>


  <!-- 🆕 v2.0.80 — 2-column grid: vlevo akce (upload), vpravo info (jak to funguje) -->
  <div class="su-grid">

    <!-- LEVÝ SLOUPEC — Upload action (primary) -->
    <div class="su-col-primary">
      <div class="su-card">
        <h2>📥 Nahraj MASTER zip</h2>
        <p style="color:#6e6e73;font-size:14px;line-height:1.6;margin:0 0 14px">
          Lokálně postavený balíček <code>appek-MASTER-vX.Y.Z.zip</code> obsahuje <strong>vendor/ + api/ + sales/ + landing pages</strong>.
          Po uploadu se vendor.appek.cz automaticky aktualizuje. <strong>Tvoje config + customer balíčky zůstanou.</strong>
        </p>

        <form method="POST" enctype="multipart/form-data" onsubmit="return confirmDeploy()">
          <div class="upload-zone" id="dz">
            <div class="ico">📦</div>
            <strong>appek-MASTER-vX.Y.Z.zip</strong>
            <input type="file" name="master_zip" id="master_zip" accept=".zip" required onchange="onFileSelect()">
            <div class="fname" id="fname">Vyber soubor nebo přetáhni sem</div>
          </div>

          <div class="danger-note">
            ⚠️ <strong>Self-update probíhá za běhu.</strong> Doporučení:
            <ul style="margin:6px 0 0;padding-left:20px">
              <li>Před uploadem zálohuj DB (phpMyAdmin → Export)</li>
              <li>Operace trvá ~5–30 sekund podle velikosti</li>
              <li>Pokud se něco pokazí, backup v <code>/tmp/appek-self-update-backup-*</code></li>
            </ul>
          </div>

          <button type="submit" class="btn-deploy" id="btn-deploy" disabled>🚀 Spustit self-update</button>
        </form>
      </div>

      <div class="alert info" style="margin-top:14px">
        💡 <strong>Pro customer distribuci</strong> (zákazníci stahují updaty přes update modul) použij <a href="updates.php" style="color:#0058b8;font-weight:600">🔄 Updates / Customer distribuce</a>.
        Tahle stránka je <strong>jen pro self-update vendor.appek.cz</strong>.
      </div>
    </div>

    <!-- PRAVÝ SLOUPEC — Průběh (po akci) vedle uploadu + Info "Jak to funguje" -->
    <aside class="su-col-info">
      <?php if ($progressLog): ?>
      <div class="su-card" style="margin-bottom:16px">
        <h2>📋 Průběh</h2>
        <div class="progress-log">
          <?php foreach ($progressLog as $line): ?>
            <div class="<?= strpos($line, '❌') !== false ? 'err' : (strpos($line, '✅') !== false ? 'ok' : '') ?>"><?= htmlspecialchars($line) ?></div>
          <?php endforeach; ?>
        </div>
      </div>
      <?php endif; ?>
      <div class="su-card">
        <h2>🧠 Jak to funguje</h2>
        <div class="su-step">
          <div class="num">1</div>
          <div class="body">
            <strong>Pre-flight check</strong>
            Ověří že ZIP obsahuje očekávané soubory (<code>vendor/_lib.php</code>, <code>api/_license.php</code>, <code>index.html</code>).
            Pokud chybí cokoliv kritického, upload se odmítne před extrakcí.
          </div>
        </div>
        <div class="su-step">
          <div class="num">2</div>
          <div class="body">
            <strong>Preserve konfigurace</strong>
            Tyto soubory NIKDY nepřepíše: <code>vendor/config.local.php</code> · <code>vendor/.installed</code> · <code>api/config.local.php</code> · <code>api/.installed</code> · <code>vendor/updates_storage/*.zip</code> (customer balíčky)
          </div>
        </div>
        <div class="su-step">
          <div class="num">3</div>
          <div class="body">
            <strong>Backup do /tmp</strong>
            Zazálohuje <code>vendor/</code>, <code>api/</code>, <code>sales/</code> a root HTML do <code>/tmp/appek-self-update-backup-YYYYMMDD-HHMMSS</code> pro rollback.
          </div>
        </div>
        <div class="su-step">
          <div class="num">4</div>
          <div class="body">
            <strong>Atomic deploy</strong>
            Použije <code>rsync</code> (s exclude pro preserve) nebo PHP fallback. Sync je INCREMENTAL — nepřepisuje soubory které jsou stejné.
          </div>
        </div>
        <div class="su-step">
          <div class="num">5</div>
          <div class="body">
            <strong>Post-deploy validace</strong>
            Po extrakci zkontroluje že kritické soubory existují a nejsou prázdné. Pokud ne, je třeba rollback z backupu.
          </div>
        </div>
        <div class="su-step">
          <div class="num">6</div>
          <div class="body">
            <strong>Audit log</strong>
            Zaznamená do <code>vendor_audit_log</code>: kdo + kdy + verze ZIPu.
          </div>
        </div>
      </div>
    </aside>

  </div>

  <style>
    /* 🆕 v2.0.80 — 2-column grid layout pro self-update */
    .su-grid {
      display: grid;
      grid-template-columns: minmax(0, 1.2fr) minmax(0, 1fr);
      gap: 20px;
      align-items: start;
    }
    .su-col-primary, .su-col-info { min-width: 0; }
    .su-col-info { position: sticky; top: 76px; }
    /* Stack na mobile / tabletu */
    @media (max-width: 980px) {
      .su-grid { grid-template-columns: 1fr; }
      .su-col-info { position: static; }
    }
    /* Wider desktop — 3:2 ratio pro lepší poměr */
    @media (min-width: 1280px) {
      .su-grid { grid-template-columns: 3fr 2fr; }
    }
  </style>

</main>

<script>
function onFileSelect() {
  const f = document.getElementById('master_zip').files[0];
  if (!f) return;
  document.getElementById('fname').textContent = '📎 ' + f.name + ' · ' + (f.size / 1024 / 1024).toFixed(2) + ' MB';
  document.getElementById('btn-deploy').disabled = false;

  // Verify it's master, not customer
  if (!f.name.toLowerCase().includes('master')) {
    alert('⚠️ Pozor — vybral jsi ' + f.name + ', což pravděpodobně NENÍ MASTER ZIP.\n\nMASTER ZIP má v názvu "MASTER", např. appek-MASTER-v2.0.37.zip.\n\nPokud nahraješ customer ZIP, vendor portál bude pravděpodobně poškozen.');
  }
}

function confirmDeploy() {
  return confirm('🚀 Spustit self-update?\n\nAktualizuje se vendor portál + landing pages.\nProces trvá ~5–30 sekund.\n\nPokračovat?');
}

// Drag & drop
const dz = document.getElementById('dz');
dz.addEventListener('dragover', e => { e.preventDefault(); dz.classList.add('dragover'); });
dz.addEventListener('dragleave', () => dz.classList.remove('dragover'));
dz.addEventListener('drop', e => {
  e.preventDefault();
  dz.classList.remove('dragover');
  if (e.dataTransfer.files.length) {
    document.getElementById('master_zip').files = e.dataTransfer.files;
    onFileSelect();
  }
});
</script>

<?php vendor_render_footer(); ?>
</body>
</html>
