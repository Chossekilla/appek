<?php
/**
 * 🔄 UPDATES MANAGEMENT — Verze ke stažení do zákaznických instalací.
 *
 * Workflow:
 *   1. Lokálně/test doména: postavíš update bundle (ZIP s manifest.json)
 *   2. Tady ho nahraješ (status=draft)
 *   3. Klikneš "Publikovat" → status=published, dostupné přes API
 *   4. Zákazníci s licencí volají api/updates_check.php → vidí nejnovější verzi
 *   5. Stáhnou přes api/updates_download.php (license-gated)
 *
 * Bundle formát:
 *   appek-update-X.Y.Z.zip
 *     ├── manifest.json   {version, min_version, files: {path: sha256}, packages_required: [...]}
 *     ├── files/          (mirror struktura customer instalace)
 *     │   ├── admin/...
 *     │   ├── api/...
 *     │   └── ...
 *     └── changelog.md
 */
require_once __DIR__ . '/_lib.php';
require_once __DIR__ . '/_layout.php';

$user = vendor_require_login();
$pdo  = vendor_db();
$currentPage = 'updates';

$flash_ok = null;
$flash_err = null;
$storage = __DIR__ . '/updates_storage';
if (!is_dir($storage)) @mkdir($storage, 0755, true);

// ─── Akce ────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    try {
        if ($action === 'upload' && !empty($_FILES['bundle']['name'])) {
            $version    = trim($_POST['version'] ?? '');
            $channel    = $_POST['channel'] ?? 'stable';
            $minVersion = trim($_POST['min_version'] ?? '');
            $changelog  = trim($_POST['changelog_md'] ?? '');
            $packagesReq = trim($_POST['packages_required'] ?? '');

            if (!preg_match('/^[0-9]+\.[0-9]+\.[0-9]+(-[a-z0-9]+)?$/', $version)) {
                throw new Exception('Verze musí být ve formátu X.Y.Z (např. 2.1.0 nebo 3.0.0-beta).');
            }
            if (!in_array($channel, ['stable', 'beta', 'alpha'])) {
                throw new Exception('Neplatný channel.');
            }
            if ($_FILES['bundle']['error'] !== UPLOAD_ERR_OK) {
                throw new Exception('Upload chyba: ' . $_FILES['bundle']['error']);
            }
            if ($_FILES['bundle']['size'] > 50 * 1024 * 1024) {
                throw new Exception('Soubor je příliš velký (max 50 MB).');
            }

            $exists = $pdo->prepare("SELECT id FROM vendor_updates WHERE version = :v");
            $exists->execute(['v' => $version]);
            if ($exists->fetchColumn()) {
                throw new Exception("Verze $version už existuje. Smaž ji nejdřív, nebo použij jinou.");
            }

            // Validace ZIP + extrakce manifestu
            $tmp = $_FILES['bundle']['tmp_name'];
            $zip = new ZipArchive();
            if ($zip->open($tmp) !== true) throw new Exception('Soubor není validní ZIP.');
            $manifestRaw = $zip->getFromName('manifest.json');
            $changelogFromZip = $zip->getFromName('changelog.md');
            $zip->close();

            $manifest = null;
            if ($manifestRaw) {
                $manifest = json_decode($manifestRaw, true);
                if (!is_array($manifest)) throw new Exception('manifest.json v ZIPu není validní JSON.');
            }
            if (!$changelog && $changelogFromZip) $changelog = $changelogFromZip;

            // Uložit soubor
            $filename = 'appek-update-' . $version . '.zip';
            $dest = $storage . '/' . $filename;
            if (!move_uploaded_file($tmp, $dest)) {
                throw new Exception('Nelze přesunout uploaded soubor do storage.');
            }
            $checksum = hash_file('sha256', $dest);
            $size = filesize($dest);

            $packagesReqJson = null;
            if ($packagesReq) {
                $parsed = json_decode($packagesReq, true);
                if (!is_array($parsed)) throw new Exception('packages_required musí být JSON pole, např. ["core","cukrarna"]');
                $packagesReqJson = json_encode($parsed);
            }

            $pdo->prepare("
                INSERT INTO vendor_updates
                  (version, channel, file_path, file_size, checksum_sha256,
                   manifest_json, changelog_md, min_version, packages_required, status)
                VALUES (:v, :c, :fp, :fs, :sum, :mf, :cl, :mv, :pr, 'draft')
            ")->execute([
                'v'   => $version,
                'c'   => $channel,
                'fp'  => $filename,
                'fs'  => $size,
                'sum' => $checksum,
                'mf'  => $manifest ? json_encode($manifest, JSON_UNESCAPED_UNICODE) : null,
                'cl'  => $changelog ?: null,
                'mv'  => $minVersion ?: null,
                'pr'  => $packagesReqJson,
            ]);

            vendor_audit($pdo, $user, 'update_upload', null, $version);
            $flash_ok = "Verze $version nahrána ($size bajtů, " . substr($checksum, 0, 12) . "...). Stav: draft — klikni Publikovat pro zpřístupnění zákazníkům.";
        } elseif ($action === 'publish') {
            $id = (int) $_POST['id'];
            $pdo->prepare("UPDATE vendor_updates SET status='published', published_at=NOW() WHERE id=:id")
                ->execute(['id' => $id]);
            vendor_audit($pdo, $user, 'update_publish', null, (string) $id);
            $flash_ok = "Verze publikována. Zákazníci ji teď uvidí přes API.";
        } elseif ($action === 'deprecate') {
            $id = (int) $_POST['id'];
            $pdo->prepare("UPDATE vendor_updates SET status='deprecated', deprecated_at=NOW() WHERE id=:id")
                ->execute(['id' => $id]);
            vendor_audit($pdo, $user, 'update_deprecate', null, (string) $id);
            $flash_ok = "Verze označena jako zastaralá.";
        } elseif ($action === 'delete') {
            $id = (int) $_POST['id'];
            $row = $pdo->prepare("SELECT version, file_path FROM vendor_updates WHERE id=:id");
            $row->execute(['id' => $id]);
            $row = $row->fetch();
            if ($row) {
                @unlink($storage . '/' . $row['file_path']);
                $pdo->prepare("DELETE FROM vendor_updates WHERE id=:id")->execute(['id' => $id]);
                vendor_audit($pdo, $user, 'update_delete', null, $row['version']);
                $flash_ok = "Verze {$row['version']} smazána.";
            }
        } elseif ($action === 'bulk_delete') {
            // 🆕 v3.0.13 — hromadné mazání vybraných verzí
            $ids = $_POST['ids'] ?? [];
            if (!is_array($ids) || empty($ids)) {
                $flash_err = 'Nebyly vybrány žádné verze.';
            } else {
                $ids = array_map('intval', $ids);
                $ids = array_filter($ids, fn($i) => $i > 0);
                if (empty($ids)) {
                    $flash_err = 'Neplatný výběr.';
                } else {
                    $placeholders = implode(',', array_fill(0, count($ids), '?'));
                    $rowsStmt = $pdo->prepare("SELECT id, version, file_path FROM vendor_updates WHERE id IN ($placeholders)");
                    $rowsStmt->execute($ids);
                    $rows = $rowsStmt->fetchAll();
                    $smazano = 0; $versions = [];
                    foreach ($rows as $r) {
                        @unlink($storage . '/' . $r['file_path']);
                        $versions[] = $r['version'];
                        $smazano++;
                    }
                    $del = $pdo->prepare("DELETE FROM vendor_updates WHERE id IN ($placeholders)");
                    $del->execute($ids);
                    vendor_audit($pdo, $user, 'update_bulk_delete', null, implode(',', $versions));
                    $flash_ok = "Hromadně smazáno: {$smazano} " . ($smazano === 1 ? 'verze' : ($smazano < 5 ? 'verze' : 'verzí')) . " (" . implode(', ', $versions) . ").";
                }
            }
        }
        header('Location: updates.php');
        exit;
    } catch (Throwable $e) {
        $flash_err = $e->getMessage();
    }
}

$updates = $pdo->query("SELECT * FROM vendor_updates ORDER BY id DESC")->fetchAll();
$stats = [
    'total'      => count($updates),
    'published'  => count(array_filter($updates, fn($u) => $u['status'] === 'published')),
    'drafts'     => count(array_filter($updates, fn($u) => $u['status'] === 'draft')),
    'downloads'  => array_sum(array_column($updates, 'download_count')),
];
$installs = $pdo->query("SELECT * FROM vendor_update_installs ORDER BY downloaded_at DESC LIMIT 20")->fetchAll();

$showUpload = !empty($_GET['upload']) || empty($updates);
?><!DOCTYPE html>
<html lang="cs">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="robots" content="noindex,nofollow">
<title>🔄 Aktualizace — APPEK Master</title>
<link rel="stylesheet" href="style.css?v=1.5">
<style>
  .stats-row { display: grid; grid-template-columns: repeat(auto-fit, minmax(160px, 1fr)); gap: 14px; margin-bottom: 20px; }
  .stat-card { background: #fff; border-radius: 10px; padding: 16px 20px; border: 1px solid #e5e5e7; }
  .stat-card .label { font-size: 11px; color: #6e6e73; text-transform: uppercase; letter-spacing: 0.5px; }
  .stat-card .value { font-size: 26px; font-weight: 800; margin-top: 4px; }

  .upload-card { background: #fff; border-radius: 14px; padding: 24px; box-shadow: 0 1px 3px rgba(0,0,0,0.05); margin-bottom: 20px; }
  .upload-card h2 { margin: 0 0 14px; font-size: 18px; }
  .upload-form .row { display: grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap: 12px; margin-bottom: 12px; }
  .upload-form label { display: block; font-size: 12px; font-weight: 600; margin-bottom: 4px; }
  .upload-form label small { font-weight: 400; color: #86868b; }
  .upload-form input[type="text"], .upload-form input[type="file"], .upload-form select, .upload-form textarea {
    width: 100%; padding: 9px 12px; border: 1px solid #d2d2d7; border-radius: 8px;
    font-family: inherit; font-size: 13px; box-sizing: border-box;
  }
  .upload-form textarea { resize: vertical; min-height: 80px; }
  .upload-form .file-zone {
    border: 2px dashed #d2d2d7; border-radius: 12px; padding: 26px;
    text-align: center; background: #fafafa; cursor: pointer;
  }
  .upload-form .file-zone:hover { background: #f5f5f7; border-color: #BA7517; }

  .upd-table { width: 100%; border-collapse: collapse; font-size: 13px; background: #fff; border-radius: 12px; overflow: hidden; box-shadow: 0 1px 3px rgba(0,0,0,0.04); table-layout: fixed; }
  .upd-table th, .upd-table td { padding: 11px 14px; text-align: left; border-bottom: 1px solid #f0f0f3; vertical-align: middle; }
  .upd-table th { background: #fafafa; font-weight: 700; color: #6e6e73; text-transform: uppercase; letter-spacing: 0.4px; font-size: 11px; }
  .upd-table tr:hover td { background: #fafafa; }
  /* Fixní šířky sloupců — žádné prozrazení do stran dle obsahu */
  .upd-table col.col-verze    { width: 11%; }
  .upd-table col.col-channel  { width: 9%;  }
  .upd-table col.col-stav     { width: 11%; }
  .upd-table col.col-velikost { width: 9%;  }
  .upd-table col.col-sha      { width: 14%; }
  .upd-table col.col-staho    { width: 10%; }
  .upd-table col.col-datum    { width: 13%; }
  .upd-table col.col-akce     { width: 23%; }
  .upd-table .badge { min-width: 76px; text-align: center; }
  .upd-table .row-actions { display: flex; gap: 6px; align-items: center; justify-content: flex-end; flex-wrap: nowrap; }
  .upd-table .row-actions form { display: inline-flex; margin: 0; }
  .upd-table .row-actions button { min-width: 38px; min-height: 30px; }
  .upd-table .row-actions .act-slot { min-width: 96px; display: inline-flex; justify-content: flex-end; }
  .upd-table .row-actions .act-dash { color: #c7c7cc; font-size: 16px; padding: 0 8px; }
  .upd-table .changelog-row td { background: #fafafa !important; padding: 12px 18px; }
  .upd-table .changelog-row details summary { padding: 4px 0; font-weight: 600; }
  .upd-table .changelog-row pre { font-size: 12px; color: #3a3a3c; margin: 8px 0 0; line-height: 1.6; white-space: pre-wrap; font-family: inherit; }
  @media (max-width: 900px) {
    .upd-table { table-layout: auto; font-size: 12px; }
    .upd-table th, .upd-table td { padding: 9px 8px; }
  }

  .badge { display: inline-block; padding: 3px 8px; border-radius: 999px; font-size: 11px; font-weight: 700; text-transform: uppercase; }
  .badge.draft       { background: #f5f5f7; color: #6e6e73; }
  .badge.published   { background: rgba(52,199,89,0.15); color: #208438; }
  .badge.deprecated  { background: rgba(255,59,48,0.15); color: #b30019; }
  .badge.channel-stable { background: rgba(0,122,255,0.12); color: #0058b8; }
  .badge.channel-beta   { background: rgba(255,149,0,0.15); color: #c66800; }
  .badge.channel-alpha  { background: rgba(147,51,234,0.15); color: #6b21a8; }

  .flash { padding: 12px 16px; border-radius: 10px; margin-bottom: 14px; font-size: 14px; }
  .flash.ok  { background: #d4edda; color: #155724; }
  .flash.err { background: #f8d7da; color: #721c24; }

  code { font-family: 'SF Mono', Menlo, monospace; font-size: 12px; background: #f5f5f7; padding: 2px 6px; border-radius: 4px; }
  details summary { cursor: pointer; color: #0058b8; font-size: 13px; }
</style>
</head>
<body>

<?php vendor_render_topbar($user, $currentPage); ?>
<?php vendor_render_back(); ?>

<main class="page-master">

  <div class="page-header-master">
    <h1>🔄 Aktualizace</h1>
    <div style="display:flex;gap:8px;align-items:center">
      <span style="font-size:13px;color:#86868b">OTA push do zákaznických instalací</span>
      <a href="updates.php?upload=1" class="btn-master primary">⬆️ Nahrát update</a>
    </div>
  </div>

  <?php if ($flash_ok): ?><div class="flash ok">✅ <?= $flash_ok ?></div><?php endif; ?>
  <?php if ($flash_err): ?><div class="flash err">❌ <?= htmlspecialchars($flash_err) ?></div><?php endif; ?>

  <div class="stats-row">
    <div class="stat-card"><div class="label">Verzí</div><div class="value"><?= $stats['total'] ?></div></div>
    <div class="stat-card"><div class="label">Publikováno</div><div class="value" style="color:#34c759"><?= $stats['published'] ?></div></div>
    <div class="stat-card"><div class="label">Drafty</div><div class="value" style="color:#ff9500"><?= $stats['drafts'] ?></div></div>
    <div class="stat-card"><div class="label">Stahování</div><div class="value"><?= $stats['downloads'] ?></div></div>
  </div>

  <?php if ($showUpload): ?>
    <div class="upload-card">
      <h2>⬆️ Nahrát novou verzi</h2>
      <form method="POST" enctype="multipart/form-data" class="upload-form">
        <input type="hidden" name="action" value="upload">

        <div class="file-zone">
          <input type="file" name="bundle" accept=".zip" required style="display:none" id="bundle-input"
                 onchange="document.getElementById('bundle-name').textContent = this.files[0]?.name || 'Vybrat ZIP'">
          <label for="bundle-input" style="cursor:pointer;display:block">
            <div style="font-size:42px">📦</div>
            <div id="bundle-name" style="font-weight:600;margin-top:8px">Klikni nebo přetáhni ZIP</div>
            <div style="font-size:12px;color:#86868b;margin-top:6px">Max 50 MB. Bundle obsahuje <code>manifest.json</code> + <code>files/</code> + <code>changelog.md</code></div>
          </label>
        </div>

        <div class="row">
          <div>
            <label>Verze * <small>(X.Y.Z)</small></label>
            <input type="text" name="version" placeholder="2.1.0" pattern="[0-9]+\.[0-9]+\.[0-9]+(-[a-z0-9]+)?" required>
          </div>
          <div>
            <label>Channel</label>
            <select name="channel">
              <option value="stable">Stable</option>
              <option value="beta">Beta</option>
              <option value="alpha">Alpha</option>
            </select>
          </div>
          <div>
            <label>Min. verze <small>(volitelné)</small></label>
            <input type="text" name="min_version" placeholder="2.0.0">
          </div>
        </div>

        <div>
          <label>Vyžadované balíčky <small>(JSON pole — např. <code>["core"]</code> nebo <code>["cukrarna","lahudky"]</code>)</small></label>
          <input type="text" name="packages_required" placeholder='["core"]'>
        </div>

        <div style="margin-top:12px">
          <label>Changelog (Markdown) <small>(pokud chybí v ZIPu)</small></label>
          <textarea name="changelog_md" placeholder="## 2.1.0&#10;- Nový feature X&#10;- Fix bug Y"></textarea>
        </div>

        <div style="display:flex;gap:10px;margin-top:14px;padding-top:14px;border-top:1px solid #e5e5e7">
          <button type="submit" class="btn-master primary">⬆️ Nahrát jako draft</button>
          <a href="updates.php" class="btn-master secondary">← Zpět</a>
        </div>
      </form>
    </div>
  <?php endif; ?>

  <h2 style="margin:24px 0 12px;font-size:16px">📋 Všechny verze</h2>

  <?php if (empty($updates)): ?>
    <div style="background:#fff;border-radius:12px;padding:60px 20px;text-align:center;color:#86868b;box-shadow:0 1px 3px rgba(0,0,0,0.04)">
      <div style="font-size:42px">🔄</div>
      <strong>Žádné updates zatím</strong><br>
      <small>Postav lokálně bundle pomocí <code>scripts/build-update.sh</code> a nahraj výše.</small>
    </div>
  <?php else: ?>
    <!-- 🆕 v3.0.13 — Bulk delete form (mimo tabulku, vstupy přes form="bulk-form") -->
    <form method="POST" id="bulk-form" onsubmit="return confirmBulkDelete(event)" style="display:contents">
      <input type="hidden" name="action" value="bulk_delete">
    </form>

    <!-- Sticky bulk action bar — zobrazí se po vybraní -->
    <div id="bulk-bar" style="position:sticky;top:0;z-index:10;background:linear-gradient(135deg,#1E40AF,#1E3A8A);color:#fff;padding:12px 18px;border-radius:10px;margin-bottom:14px;display:none;align-items:center;gap:14px;box-shadow:0 4px 12px rgba(30,64,175,0.3)">
      <span style="font-weight:700;font-size:14px"><span id="bulk-count">0</span> vybráno</span>
      <div style="flex:1"></div>
      <button type="button" onclick="bulkClearSelection()" style="padding:7px 14px;background:rgba(255,255,255,0.18);border:1px solid rgba(255,255,255,0.3);color:#fff;border-radius:8px;font-weight:600;font-size:13px;cursor:pointer">✕ Zrušit výběr</button>
      <button type="submit" form="bulk-form" style="padding:7px 16px;background:#DC2626;border:none;color:#fff;border-radius:8px;font-weight:800;font-size:13px;cursor:pointer">🗑️ Smazat vybrané</button>
    </div>

    <table class="upd-table">
      <colgroup>
        <col style="width:36px">
        <col class="col-verze">
        <col class="col-channel">
        <col class="col-stav">
        <col class="col-velikost">
        <col class="col-sha">
        <col class="col-staho">
        <col class="col-datum">
        <col class="col-akce">
      </colgroup>
      <thead>
        <tr>
          <th style="text-align:center"><input type="checkbox" id="bulk-toggle-all" onclick="bulkToggleAll(this)" style="width:18px;height:18px;cursor:pointer" title="Vybrat vše"></th>
          <th>Verze</th>
          <th>Channel</th>
          <th>Stav</th>
          <th>Velikost</th>
          <th>SHA-256</th>
          <th>Stahování</th>
          <th>Vytvořeno</th>
          <th style="text-align:right">Akce</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($updates as $u): ?>
          <tr>
            <td style="text-align:center">
              <input type="checkbox" name="ids[]" value="<?= (int) $u['id'] ?>" class="bulk-cb" form="bulk-form" onclick="bulkUpdateCount()" style="width:18px;height:18px;cursor:pointer">
            </td>
            <td><strong><?= htmlspecialchars($u['version']) ?></strong>
              <?php if ($u['min_version']): ?><br><small style="color:#86868b">min ≥ <?= htmlspecialchars($u['min_version']) ?></small><?php endif; ?>
            </td>
            <td><span class="badge channel-<?= htmlspecialchars($u['channel']) ?>"><?= htmlspecialchars($u['channel']) ?></span></td>
            <td><span class="badge <?= htmlspecialchars($u['status']) ?>"><?= htmlspecialchars($u['status']) ?></span></td>
            <td><?= number_format($u['file_size'] / 1024 / 1024, 2, ',', ' ') ?> MB</td>
            <td><code title="<?= htmlspecialchars($u['checksum_sha256']) ?>"><?= htmlspecialchars(substr($u['checksum_sha256'], 0, 10)) ?>…</code></td>
            <td><?= (int) $u['download_count'] ?></td>
            <td style="white-space:nowrap;color:#6e6e73"><?= date('d.m. H:i', strtotime($u['created_at'])) ?></td>
            <td>
              <div class="row-actions">
                <span class="act-slot">
                  <?php if ($u['status'] === 'draft'): ?>
                    <form method="POST">
                      <input type="hidden" name="action" value="publish">
                      <input type="hidden" name="id" value="<?= (int) $u['id'] ?>">
                      <button type="submit" class="btn-master primary" style="padding:5px 12px;font-size:12px" title="Publikovat verzi → zákazníci ji uvidí">✅ Publikovat</button>
                    </form>
                  <?php elseif ($u['status'] === 'published'): ?>
                    <form method="POST" onsubmit="return confirm('Označit jako zastaralé?')">
                      <input type="hidden" name="action" value="deprecate">
                      <input type="hidden" name="id" value="<?= (int) $u['id'] ?>">
                      <button type="submit" class="btn-master secondary" style="padding:5px 12px;font-size:12px" title="Označit jako zastaralou — zákazníci ji už nebudou stahovat">🚫 Deprec.</button>
                    </form>
                  <?php else: ?>
                    <span class="act-dash" title="Verze je deprecated — žádná akce">—</span>
                  <?php endif; ?>
                </span>
                <form method="POST" onsubmit="return confirm('Opravdu smazat verzi <?= htmlspecialchars($u['version']) ?>?')">
                  <input type="hidden" name="action" value="delete">
                  <input type="hidden" name="id" value="<?= (int) $u['id'] ?>">
                  <button type="submit" class="btn-master secondary" style="padding:5px 10px;font-size:12px;background:#fde7e9;color:#a8232f" title="Smazat">🗑️</button>
                </form>
              </div>
            </td>
          </tr>
          <?php if ($u['changelog_md']): ?>
            <tr class="changelog-row">
              <td colspan="9">
                <details>
                  <summary>📝 Changelog — v<?= htmlspecialchars($u['version']) ?></summary>
                  <pre><?= htmlspecialchars($u['changelog_md']) ?></pre>
                </details>
              </td>
            </tr>
          <?php endif; ?>
        <?php endforeach; ?>
      </tbody>
    </table>
  <?php endif; ?>

  <?php if (!empty($installs)): ?>
    <h2 style="margin:24px 0 12px;font-size:16px">📥 Posledních 20 stažení</h2>
    <table class="upd-table">
      <thead>
        <tr>
          <th>Kdy</th>
          <th>Update ID</th>
          <th>Licence</th>
          <th>URL</th>
          <th>IP</th>
          <th>Stav</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($installs as $i): ?>
          <tr>
            <td style="white-space:nowrap;color:#6e6e73"><?= date('d.m. H:i:s', strtotime($i['downloaded_at'])) ?></td>
            <td><code><?= (int) $i['update_id'] ?></code></td>
            <td><code style="font-size:10px"><?= htmlspecialchars(substr($i['license_key'], 0, 16)) ?>…</code></td>
            <td><?= htmlspecialchars($i['customer_url'] ?: '—') ?></td>
            <td><code><?= htmlspecialchars($i['ip']) ?></code></td>
            <td><?= $i['success'] ? '✅' : '❌ ' . htmlspecialchars(substr($i['error_msg'] ?? '', 0, 50)) ?></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  <?php endif; ?>

  <div class="panel-master" style="margin-top:30px;background:#fafafa;border:1px dashed #d2d2d7">
    <h2 style="font-size:13px;color:#6e6e73">💡 Jak připravit update bundle</h2>
    <ol style="font-size:13px;color:#3a3a3c;line-height:1.8;padding-left:20px;margin:0">
      <li>Lokálně v projektu spusť <code>./scripts/build-update.sh 2.1.0</code> — vytvoří <code>appek-update-2.1.0.zip</code></li>
      <li>Skript automaticky vygeneruje <code>manifest.json</code> se SHA-256 každého souboru</li>
      <li>Nahraj ZIP tady <strong>jako draft</strong> a zkontroluj že vše sedí (velikost, checksum, manifest)</li>
      <li>Klikni <strong>✅ Publikovat</strong> — od té chvíle ho zákazníci uvidí přes <code>api/updates_check.php</code></li>
      <li>Zákazník v adminu uvidí „Nová verze k dispozici" → klikne Stáhnout → instalace</li>
      <li>Audit log ukáže kdo si stáhl a kdy</li>
    </ol>
  </div>

</main>

<!-- 🆕 v3.0.13 — Bulk delete JS -->
<script>
function bulkUpdateCount() {
  const checked = document.querySelectorAll('.bulk-cb:checked').length;
  const bar = document.getElementById('bulk-bar');
  const cnt = document.getElementById('bulk-count');
  if (cnt) cnt.textContent = checked;
  if (bar) bar.style.display = checked > 0 ? 'flex' : 'none';
  // Update toggle-all checkbox state
  const all = document.querySelectorAll('.bulk-cb').length;
  const toggle = document.getElementById('bulk-toggle-all');
  if (toggle) {
    toggle.checked = checked === all && all > 0;
    toggle.indeterminate = checked > 0 && checked < all;
  }
}
function bulkToggleAll(src) {
  document.querySelectorAll('.bulk-cb').forEach(cb => { cb.checked = src.checked; });
  bulkUpdateCount();
}
function bulkClearSelection() {
  document.querySelectorAll('.bulk-cb').forEach(cb => { cb.checked = false; });
  const t = document.getElementById('bulk-toggle-all');
  if (t) { t.checked = false; t.indeterminate = false; }
  bulkUpdateCount();
}
function confirmBulkDelete(ev) {
  const checked = Array.from(document.querySelectorAll('.bulk-cb:checked'));
  if (checked.length === 0) {
    alert('Nebyly vybrány žádné verze.');
    ev.preventDefault();
    return false;
  }
  // Get version labels from row context for confirmation
  const versions = checked.map(cb => {
    const tr = cb.closest('tr');
    const strong = tr ? tr.querySelector('td strong') : null;
    return strong ? strong.textContent.trim() : 'v?';
  });
  const list = versions.slice(0, 10).join(', ') + (versions.length > 10 ? ` (+${versions.length - 10} dalších)` : '');
  if (!confirm(`Opravdu smazat ${checked.length} ${checked.length === 1 ? 'verzi' : 'verzí'}?\n\n${list}\n\nTato akce je nevratná!`)) {
    ev.preventDefault();
    return false;
  }
  return true;
}
</script>

<?php vendor_render_footer(); ?>
</body>
</html>
