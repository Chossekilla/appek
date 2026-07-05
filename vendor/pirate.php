<?php
/**
 * 🏴‍☠️ PIRATE INSTALLS — vendor dashboard pro instalace bez platné licence.
 *
 * Zobrazuje vše z vendor_pirate_installs s filtry (reason/status), pro každou pirate
 * instalaci umožňuje:
 *   - poznamenat poznámku ("kontaktováno 17.5.2026 e-mailem")
 *   - změnit status (new → contacted → warned → licensed / closed / ignored)
 *   - vystavit licenci ad-hoc (link na licenses.php?new=1&prefill_host=...)
 *   - smazat (pokud false positive)
 */
require_once __DIR__ . '/_lib.php';
require_once __DIR__ . '/_layout.php';

$user = vendor_require_login();
$pdo  = vendor_db();
$currentPage = 'pirate';

if ($_SERVER['REQUEST_METHOD'] === 'POST') vendor_csrf_check();  // 🔐 CSRF

$flash_ok = null;
$flash_err = null;

// ─── POST: update status / note / delete ───────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $action = $_POST['action'] ?? '';
        $id = (int) ($_POST['id'] ?? 0);

        if ($action === 'update' && $id > 0) {
            $newStatus = trim($_POST['status'] ?? 'new');
            $note      = trim($_POST['note'] ?? '');
            $contactedAt = ($newStatus === 'new') ? null : date('Y-m-d H:i:s');

            $pdo->prepare("
                UPDATE vendor_pirate_installs
                SET status = :s, contact_note = :n, contacted_at = COALESCE(contacted_at, :ca)
                WHERE id = :id
            ")->execute(['s' => $newStatus, 'n' => $note, 'ca' => $contactedAt, 'id' => $id]);
            vendor_audit($pdo, $user, 'pirate_update', null, 'id=' . $id . ' status=' . $newStatus);
            $flash_ok = "Pirate záznam #$id aktualizován.";
        } elseif ($action === 'delete' && $id > 0) {
            $pdo->prepare("DELETE FROM vendor_pirate_installs WHERE id = :id")->execute(['id' => $id]);
            vendor_audit($pdo, $user, 'pirate_delete', null, 'id=' . $id);
            $flash_ok = "Pirate záznam #$id smazán.";
        } elseif ($action === 'mark_all_seen') {
            $pdo->exec("UPDATE vendor_pirate_installs SET status = 'contacted' WHERE status = 'new'");
            vendor_audit($pdo, $user, 'pirate_mark_all_seen', null, null);
            $flash_ok = "Všechny nové záznamy označeny jako kontaktované.";
        }
        header('Location: pirate.php' . (!empty($_GET['filter']) ? '?filter=' . urlencode($_GET['filter']) : ''));
        exit;
    } catch (Throwable $e) {
        $flash_err = $e->getMessage();
    }
}

// ─── Filter ─────────────────────────────────────────────────────
$filter = $_GET['filter'] ?? 'open';
$reasonFilter = $_GET['reason'] ?? '';

$where = [];
$params = [];

// 🐛 v3.0.403 — kvalifikace p.* : JOIN na vendor_licenses (reuse_customer) přinesl
//   druhý sloupec `status` → "Column 'status' ambiguous" → celá stránka 500 (default
//   filtr 'open' padal VŽDY). Regrese ze střetu P1-A JOINu a P1-B status sloupce licencí.
if ($filter === 'open') {
    $where[] = "p.status IN ('new', 'contacted', 'warned')";
} elseif ($filter === 'closed') {
    $where[] = "p.status IN ('licensed', 'closed', 'ignored')";
}
if ($reasonFilter !== '') {
    $where[] = "p.reason = :r";
    $params[':r'] = $reasonFilter;
}

$whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

$rows = $pdo->prepare("
    SELECT p.*, l.customer_name AS reuse_customer
    FROM vendor_pirate_installs p
    LEFT JOIN vendor_licenses l ON l.id = p.matched_license_id
    $whereSql
    ORDER BY p.last_seen DESC
    LIMIT 500
");
$rows->execute($params);
$pirates = $rows->fetchAll();

// Stats
$stats = [
    'total'    => (int) $pdo->query("SELECT COUNT(*) FROM vendor_pirate_installs")->fetchColumn(),
    'new'      => (int) $pdo->query("SELECT COUNT(*) FROM vendor_pirate_installs WHERE status='new'")->fetchColumn(),
    'open'     => (int) $pdo->query("SELECT COUNT(*) FROM vendor_pirate_installs WHERE status IN ('new','contacted','warned')")->fetchColumn(),
    'licensed' => (int) $pdo->query("SELECT COUNT(*) FROM vendor_pirate_installs WHERE status='licensed'")->fetchColumn(),
    'last_24h' => (int) $pdo->query("SELECT COUNT(*) FROM vendor_pirate_installs WHERE last_seen > DATE_SUB(NOW(), INTERVAL 24 HOUR)")->fetchColumn(),
];

$reasonLabels = [
    'no_key'         => ['🚫 Žádný klíč',     '#ef4444'],
    'invalid_format' => ['⚠️ Neplatný formát', '#f59e0b'],
    'unknown_key'    => ['❓ Neznámý klíč',   '#8b5cf6'],
    'key_reuse'      => ['🔁 Reuse klíče',   '#ec4899'],
    'revoked_used'   => ['🚷 Revoked klíč',  '#dc2626'],
    'expired_used'   => ['⏰ Expired klíč',  '#ca8a04'],
];

$statusLabels = [
    'new'       => ['🆕 Nové',         '#3b82f6'],
    'contacted' => ['📧 Kontaktováno', '#0891b2'],
    'warned'    => ['⚠️ Varováno',     '#f59e0b'],
    'licensed'  => ['✅ Licencováno',  '#16a34a'],
    'closed'    => ['🔒 Uzavřeno',     '#64748b'],
    'ignored'   => ['🙈 Ignorováno',   '#94a3b8'],
];

function fmt_datetime(?string $s): string {
    if (!$s) return '—';
    $ts = strtotime($s);
    if (!$ts) return $s;
    $diff = time() - $ts;
    if ($diff < 60)          return 'právě';
    if ($diff < 3600)        return floor($diff / 60) . ' min';
    if ($diff < 86400)       return floor($diff / 3600) . ' h';
    if ($diff < 7 * 86400)   return floor($diff / 86400) . ' d';
    return date('j. n. Y', $ts);
}
?><!DOCTYPE html>
<html lang="cs"><head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="robots" content="noindex,nofollow">
<title>🏴‍☠️ Pirate Installs — APPEK Master</title>
<link rel="stylesheet" href="style.css?v=1.5">
<style>
  .pirate-stats { display:grid; grid-template-columns:repeat(auto-fit,minmax(140px,1fr)); gap:10px; margin-bottom:18px; }
  .pirate-stat { background:#fff; border:1px solid #e5e5e7; border-radius:12px; padding:14px 16px; }
  .pirate-stat .lbl { font-size:11px; color:#86868b; text-transform:uppercase; letter-spacing:.5px; font-weight:600; }
  .pirate-stat .val { font-size:24px; font-weight:800; margin-top:4px; color:#1d1d1f; }
  .pirate-stat.red .val { color:#dc2626; }
  .pirate-stat.amber .val { color:#f59e0b; }
  .pirate-stat.green .val { color:#16a34a; }

  .filter-bar { display:flex; gap:6px; flex-wrap:wrap; margin-bottom:16px; align-items:center; }
  .filter-bar a, .filter-bar select { padding:6px 12px; border-radius:999px; background:#f5f5f7; color:#3a3a3c; text-decoration:none; font-size:12.5px; font-weight:600; border:1px solid transparent; }
  .filter-bar a.active { background:linear-gradient(180deg,#BA7517,#854F0B); color:#fff; }
  .filter-bar select { background:#fff; border:1px solid #d2d2d7; }

  .pirate-table { width:100%; border-collapse:separate; border-spacing:0; background:#fff; border-radius:12px; overflow:hidden; box-shadow:0 1px 3px rgba(0,0,0,.04); }
  .pirate-table th, .pirate-table td { padding:10px 12px; text-align:left; font-size:13px; vertical-align:top; }
  .pirate-table thead th { background:#fafafa; font-weight:700; color:#1d1d1f; font-size:11px; text-transform:uppercase; letter-spacing:.5px; border-bottom:1px solid #e5e5e7; }
  .pirate-table tbody tr { border-top:1px solid #f0f0f3; }
  .pirate-table tbody tr:hover { background:#fafafa; }
  .badge-tag { display:inline-block; padding:3px 8px; border-radius:999px; font-size:11px; font-weight:700; color:#fff; }
  .host { font-family:'SF Mono',Menlo,monospace; font-size:12px; color:#1d1d1f; }
  .key-fake { font-family:'SF Mono',Menlo,monospace; font-size:11px; color:#86868b; }
  .row-actions form { display:inline; }
  .row-actions button, .row-actions select { padding:4px 8px; font-size:11px; border-radius:6px; border:1px solid #d2d2d7; background:#fff; cursor:pointer; }
  .row-actions button.danger { background:#fde7e9; color:#dc2626; border-color:#fde7e9; }
  .row-actions .save-btn { background:linear-gradient(180deg,#BA7517,#854F0B); color:#fff; border:none; }
  .note-cell textarea { width:100%; min-height:38px; max-height:80px; padding:6px 8px; border:1px solid #e5e5e7; border-radius:6px; font-family:inherit; font-size:12px; }

  .empty { background:#fff; padding:40px 20px; text-align:center; color:#86868b; border-radius:12px; border:1px dashed #e5e5e7; }
  .empty .emoji { font-size:42px; margin-bottom:10px; }
</style>
</head><body>
<?php vendor_render_topbar($user, 'pirate'); ?>

<main class="page-master">

  <div class="page-header-master" style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:10px">
    <div>
      <h1>🏴‍☠️ Pirate Installs</h1>
      <div style="font-size:13px;color:#86868b">Instalace bez platné licence detekované přes denní heartbeat z customer admin loginů.</div>
    </div>
    <?php if ($stats['new'] > 0): ?>
      <form method="POST" onsubmit="return confirm('Označit všech <?= $stats['new'] ?> nových jako kontaktované?')">
        <?php vendor_csrf_field(); ?>
        <input type="hidden" name="action" value="mark_all_seen">
        <button class="btn-master" type="submit">📧 Označit nové jako kontaktované</button>
      </form>
    <?php endif; ?>
  </div>

  <?php if ($flash_ok):  ?><div class="alert ok" style="margin-bottom:14px">✅ <?= htmlspecialchars($flash_ok) ?></div><?php endif; ?>
  <?php if ($flash_err): ?><div class="alert err" style="margin-bottom:14px">❌ <?= htmlspecialchars($flash_err) ?></div><?php endif; ?>

  <!-- Stats -->
  <div class="pirate-stats">
    <div class="pirate-stat">
      <div class="lbl">Celkem</div><div class="val"><?= $stats['total'] ?></div>
    </div>
    <div class="pirate-stat red">
      <div class="lbl">Nové</div><div class="val"><?= $stats['new'] ?></div>
    </div>
    <div class="pirate-stat amber">
      <div class="lbl">Otevřené</div><div class="val"><?= $stats['open'] ?></div>
    </div>
    <div class="pirate-stat green">
      <div class="lbl">Licencováno</div><div class="val"><?= $stats['licensed'] ?></div>
    </div>
    <div class="pirate-stat">
      <div class="lbl">Posl. 24h</div><div class="val"><?= $stats['last_24h'] ?></div>
    </div>
  </div>

  <!-- Filter -->
  <div class="filter-bar">
    <a href="pirate.php?filter=open"   class="<?= $filter==='open'   ? 'active' : '' ?>">🟠 Otevřené (<?= $stats['open'] ?>)</a>
    <a href="pirate.php?filter=closed" class="<?= $filter==='closed' ? 'active' : '' ?>">✅ Uzavřené</a>
    <a href="pirate.php?filter=all"    class="<?= $filter==='all'    ? 'active' : '' ?>">📋 Vše (<?= $stats['total'] ?>)</a>
    <span style="margin-left:6px;color:#86868b">·</span>
    <select onchange="location.href='pirate.php?filter=<?= htmlspecialchars($filter) ?>&reason=' + this.value">
      <option value="">Důvod: všechny</option>
      <?php foreach ($reasonLabels as $r => [$l, $c]): ?>
        <option value="<?= $r ?>" <?= $reasonFilter === $r ? 'selected' : '' ?>><?= htmlspecialchars($l) ?></option>
      <?php endforeach; ?>
    </select>
  </div>

  <?php if (empty($pirates)): ?>
    <div class="empty">
      <div class="emoji">🏝️</div>
      <h3 style="color:#16a34a;margin:0">Žádné pirate instalace — všechny instalace v pořádku!</h3>
      <p style="font-size:13px;margin-top:10px">Pokud máš filtr nastavený a chybí ti záznamy, <a href="pirate.php?filter=all">zobraz všechny</a>.</p>
    </div>
  <?php else: ?>
    <table class="pirate-table">
      <thead>
        <tr>
          <th>Host / URL</th>
          <th>Důvod</th>
          <th>Klíč (pokus)</th>
          <th>Aktivita</th>
          <th>Kontakt</th>
          <th>Status</th>
          <th>Poznámka</th>
          <th>Akce</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($pirates as $p):
          [$rLabel, $rColor] = $reasonLabels[$p['reason']] ?? [$p['reason'], '#64748b'];
          [$sLabel, $sColor] = $statusLabels[$p['status']] ?? [$p['status'], '#64748b'];
          $adminEmails = json_decode($p['admin_emails'] ?? '[]', true);
          if (!is_array($adminEmails)) $adminEmails = [];
        ?>
        <tr>
          <td>
            <div class="host"><?= htmlspecialchars($p['install_host']) ?></div>
            <?php if ($p['install_url']): ?>
              <a href="<?= htmlspecialchars($p['install_url']) ?>" target="_blank" rel="noopener" style="font-size:11px;color:#0058b8;text-decoration:none">↗ otevřít</a>
            <?php endif; ?>
          </td>
          <td>
            <span class="badge-tag" style="background:<?= $rColor ?>"><?= htmlspecialchars($rLabel) ?></span>
            <?php if (!empty($p['reuse_customer'])): ?>
              <div style="font-size:11px;color:#86868b;margin-top:4px">Klíč patří: <strong><?= htmlspecialchars($p['reuse_customer']) ?></strong></div>
            <?php endif; ?>
          </td>
          <td>
            <?php if ($p['license_key_attempted']): ?>
              <span class="key-fake"><?= htmlspecialchars(substr($p['license_key_attempted'], 0, 18)) ?>…</span>
            <?php else: ?>
              <span style="color:#86868b">—</span>
            <?php endif; ?>
            <?php if ($p['app_version']): ?>
              <div style="font-size:11px;color:#86868b">v<?= htmlspecialchars($p['app_version']) ?></div>
            <?php endif; ?>
          </td>
          <td>
            <div><strong><?= $p['heartbeat_count'] ?></strong>× hb</div>
            <div style="font-size:11px;color:#86868b">posl. <?= fmt_datetime($p['last_seen']) ?></div>
            <?php if ($p['customer_b2b_count'] !== null): ?>
              <div style="font-size:11px;color:#86868b"><?= (int)$p['customer_b2b_count'] ?> B2B</div>
            <?php endif; ?>
          </td>
          <td>
            <?php if ($adminEmails): ?>
              <?php foreach ($adminEmails as $e): ?>
                <a href="mailto:<?= htmlspecialchars($e) ?>" style="font-size:12px;color:#0058b8;text-decoration:none;display:block"><?= htmlspecialchars($e) ?></a>
              <?php endforeach; ?>
            <?php else: ?>
              <span style="color:#86868b;font-size:12px">—</span>
            <?php endif; ?>
            <?php if ($p['contacted_at']): ?>
              <div style="font-size:11px;color:#16a34a">✓ kontakt <?= fmt_datetime($p['contacted_at']) ?></div>
            <?php endif; ?>
          </td>
          <td>
            <form method="POST" style="margin:0">
              <?php vendor_csrf_field(); ?>
              <input type="hidden" name="action" value="update">
              <input type="hidden" name="id" value="<?= (int)$p['id'] ?>">
              <select name="status" onchange="this.form.submit()" style="border:1px solid <?= $sColor ?>;background:<?= $sColor ?>22;color:<?= $sColor ?>;border-radius:6px;padding:4px 8px;font-size:11px;font-weight:700">
                <?php foreach ($statusLabels as $s => [$l, $c]): ?>
                  <option value="<?= $s ?>" <?= $p['status'] === $s ? 'selected' : '' ?>><?= htmlspecialchars($l) ?></option>
                <?php endforeach; ?>
              </select>
              <input type="hidden" name="note" value="<?= htmlspecialchars($p['contact_note'] ?? '') ?>">
            </form>
          </td>
          <td class="note-cell">
            <form method="POST" style="margin:0">
              <?php vendor_csrf_field(); ?>
              <input type="hidden" name="action" value="update">
              <input type="hidden" name="id" value="<?= (int)$p['id'] ?>">
              <input type="hidden" name="status" value="<?= htmlspecialchars($p['status']) ?>">
              <textarea name="note" onblur="this.form.submit()" placeholder="poznámka..."><?= htmlspecialchars($p['contact_note'] ?? '') ?></textarea>
            </form>
          </td>
          <td class="row-actions">
            <a href="licenses.php?new=1&prefill_url=<?= urlencode($p['install_url']) ?>&prefill_host=<?= urlencode($p['install_host']) ?>" title="Vystavit licenci"
               style="display:inline-block;padding:4px 8px;font-size:11px;border-radius:6px;background:linear-gradient(180deg,#16a34a,#15803d);color:#fff;text-decoration:none;font-weight:700">+ Licence</a>
            <form method="POST" onsubmit="return confirm('Smazat záznam #<?= (int)$p['id'] ?>?');" style="display:inline;margin-left:4px">
              <?php vendor_csrf_field(); ?>
              <input type="hidden" name="action" value="delete">
              <input type="hidden" name="id" value="<?= (int)$p['id'] ?>">
              <button class="danger" type="submit" title="Smazat">🗑</button>
            </form>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  <?php endif; ?>

  <!-- Info -->
  <div class="panel-master" style="margin-top:30px;background:#fafafa;border:1px dashed #d2d2d7">
    <h2 style="font-size:13px;color:#6e6e73">💡 Jak pirate detection funguje</h2>
    <ol style="font-size:13px;color:#3a3a3c;line-height:1.7;padding-left:20px;margin:0">
      <li>Customer admin se přihlásí → admin.js posílá denní <strong>heartbeat</strong> na <code>vendor.appek.cz/heartbeat.php</code> se svým license_key + URL.</li>
      <li>Server klasifikuje: <strong>legit</strong> (klíč v DB + URL sedí) nebo <strong>pirate</strong> (no_key / unknown / key_reuse / revoked / expired).</li>
      <li>Pokud pirate → záznam do této tabulky + customer dostane flag (<code>api/.pirate-flag</code>) → admin UI ukáže nag screen.</li>
      <li>Legit klíče dostanou <code>last_seen_at</code> update + záznam do <code>vendor_license_heartbeats</code> (rolling 30 days).</li>
      <li>Z této obrazovky umíš kontaktovat pirate adminy, vystavit jim licenci ad-hoc, nebo je ignorovat.</li>
    </ol>
  </div>

</main>

<?php vendor_render_footer(); ?>
</body></html>
