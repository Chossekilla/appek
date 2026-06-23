<?php
/**
 * 🧪 DEMO ACCESS LOG — Sledování návštěv demo subdomény.
 *
 * Loguje:
 *   - Kdy uživatel otevřel demo.appek.cz
 *   - IP, User Agent, Referer
 *   - Akce (entered, logged_in, viewed_admin)
 *
 * Data se zapisují i čtou z tabulky `demo_pristupy` ve VENDOR DB (zápis: vendor/demo-track.php).
 */
require_once __DIR__ . '/_lib.php';
require_once __DIR__ . '/_layout.php';

$user = vendor_require_login();
$currentPage = 'demo-log';

// Načti demo log
$logs = [];
$stats = ['total' => 0, 'unique_ips' => 0, 'today' => 0, 'week' => 0];

try {
    // 🆕 v3.0.385 — vendor je master všeho a hlídá VEŠKERÉ přístupy → demo přístupy čteme z
    //   VENDOR DB (kam je píše vendor/demo-track.php). Dřív přes ../api/config.local.php = app DB
    //   vedle vendoru → na samostatné subdoméně prázdné (config tam není) = „nezaznamenává se".
    $mainPdo = vendor_db();
    if ($mainPdo) {

        // Auto-create tabulku pokud chybí
        $mainPdo->exec("
            CREATE TABLE IF NOT EXISTS demo_pristupy (
                id INT AUTO_INCREMENT PRIMARY KEY,
                ip VARCHAR(45),
                user_agent TEXT,
                akce VARCHAR(50),
                referer VARCHAR(255),
                jazyk VARCHAR(5),
                cas DATETIME DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_cas (cas),
                INDEX idx_ip (ip)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");

        // Filtry
        $whereClauses = [];
        $params = [];

        $filterDays = (int) ($_GET['days'] ?? 30);
        if ($filterDays > 0) {
            $whereClauses[] = "cas > DATE_SUB(NOW(), INTERVAL :days DAY)";
            $params['days'] = $filterDays;
        }

        $filterAction = $_GET['action'] ?? '';
        if ($filterAction) {
            $whereClauses[] = "akce = :akce";
            $params['akce'] = $filterAction;
        }

        $filterIp = trim($_GET['ip'] ?? '');
        if ($filterIp) {
            $whereClauses[] = "ip LIKE :ip";
            $params['ip'] = $filterIp . '%';
        }

        $where = $whereClauses ? 'WHERE ' . implode(' AND ', $whereClauses) : '';

        $stmt = $mainPdo->prepare("
            SELECT id, ip, user_agent, akce, referer, jazyk, cas
            FROM demo_pristupy
            $where
            ORDER BY id DESC
            LIMIT 200
        ");
        $stmt->execute($params);
        $logs = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Statistiky
        $stats['total'] = (int) $mainPdo->query("SELECT COUNT(*) FROM demo_pristupy")->fetchColumn();
        $stats['unique_ips'] = (int) $mainPdo->query("SELECT COUNT(DISTINCT ip) FROM demo_pristupy")->fetchColumn();
        $stats['today'] = (int) $mainPdo->query("SELECT COUNT(*) FROM demo_pristupy WHERE DATE(cas) = CURDATE()")->fetchColumn();
        $stats['week'] = (int) $mainPdo->query("SELECT COUNT(*) FROM demo_pristupy WHERE cas > DATE_SUB(NOW(), INTERVAL 7 DAY)")->fetchColumn();
    }
} catch (Throwable $e) {
    $error = $e->getMessage();
}

// Export CSV
if (isset($_GET['export']) && $_GET['export'] === 'csv') {
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="demo-log-' . date('Ymd') . '.csv"');
    $f = fopen('php://output', 'w');
    fputs($f, "\xEF\xBB\xBF"); // BOM pro Excel
    fputcsv($f, ['ID', 'Datum', 'IP', 'Akce', 'Jazyk', 'Referer', 'User Agent']);
    foreach ($logs as $l) {
        fputcsv($f, [$l['id'], $l['cas'], $l['ip'], $l['akce'], $l['jazyk'], $l['referer'], $l['user_agent']]);
    }
    fclose($f);
    exit;
}

?><!DOCTYPE html>
<html lang="cs">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="robots" content="noindex,nofollow">
<title>🧪 Demo Log — APPEK Master</title>
<link rel="stylesheet" href="style.css?v=1.3">
<style>
  .stats-row {
    display: grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
    gap: 14px; margin-bottom: 20px;
  }
  .stat-card {
    background: #fff; border-radius: 10px; padding: 16px 20px;
    border: 1px solid #e5e5e7;
  }
  .stat-card .label {
    font-size: 11px; color: #6e6e73; text-transform: uppercase; letter-spacing: 0.5px;
  }
  .stat-card .value { font-size: 28px; font-weight: 800; margin-top: 4px; }
  .filters {
    display: flex; gap: 10px; flex-wrap: wrap; margin-bottom: 16px;
    align-items: center;
  }
  .filters select, .filters input {
    padding: 8px 12px; border: 1px solid #d2d2d7; border-radius: 8px;
    font-family: inherit; font-size: 13px;
  }
  .access-table { width: 100%; border-collapse: collapse; font-size: 13px; }
  .access-table th, .access-table td {
    padding: 10px 14px; text-align: left; border-bottom: 1px solid #f0f0f3;
  }
  .access-table th {
    background: #fafafa; font-weight: 700; color: #6e6e73;
    text-transform: uppercase; letter-spacing: 0.4px; font-size: 11px;
  }
  .access-table tr:hover td { background: #fafafa; }
  .badge {
    display: inline-block; padding: 3px 8px; border-radius: 999px;
    font-size: 11px; font-weight: 700; text-transform: uppercase;
  }
  .badge.green { background: rgba(52,199,89,0.15); color: #208438; }
  .badge.blue { background: rgba(0,122,255,0.15); color: #0058b8; }
  .badge.orange { background: rgba(255,149,0,0.15); color: #c66800; }
  .badge.purple { background: rgba(147,51,234,0.15); color: #6b21a8; }
  .ua-short { font-size: 11px; color: #6e6e73; max-width: 300px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
  code { font-family: 'SF Mono', Menlo, monospace; font-size: 12px; }
</style>
</head>
<body>

<?php vendor_render_topbar($user, $currentPage); ?>
<?php vendor_render_back(); ?>

<main class="page-master">

  <div class="page-header-master">
    <h1>🧪 Demo Access Log</h1>
    <div style="display:flex;gap:8px;align-items:center">
      <span style="font-size:13px;color:#86868b">Sledování přístupů na demo.appek.cz</span>
      <a href="?export=csv&days=<?= (int)$filterDays ?>" class="btn-master secondary">📤 Export CSV</a>
    </div>
  </div>

  <?php if (!empty($error)): ?>
    <div style="background:#f8d7da;color:#721c24;padding:14px 18px;border-radius:10px;margin-bottom:14px">❌ <?= htmlspecialchars($error) ?></div>
  <?php endif; ?>

  <!-- STATS -->
  <div class="stats-row">
    <div class="stat-card">
      <div class="label">Celkem návštěv</div>
      <div class="value"><?= $stats['total'] ?></div>
    </div>
    <div class="stat-card">
      <div class="label">Unikátní IP</div>
      <div class="value"><?= $stats['unique_ips'] ?></div>
    </div>
    <div class="stat-card">
      <div class="label">Dnes</div>
      <div class="value" style="color:#34c759"><?= $stats['today'] ?></div>
    </div>
    <div class="stat-card">
      <div class="label">Posledních 7 dní</div>
      <div class="value" style="color:#BA7517"><?= $stats['week'] ?></div>
    </div>
  </div>

  <!-- FILTERS -->
  <form class="filters" method="GET">
    <span style="font-weight:600;font-size:13px">Filtr:</span>
    <select name="days">
      <option value="1" <?= $filterDays === 1 ? 'selected' : '' ?>>Dnes</option>
      <option value="7" <?= $filterDays === 7 ? 'selected' : '' ?>>7 dní</option>
      <option value="30" <?= $filterDays === 30 ? 'selected' : '' ?>>30 dní</option>
      <option value="90" <?= $filterDays === 90 ? 'selected' : '' ?>>90 dní</option>
      <option value="0" <?= $filterDays === 0 ? 'selected' : '' ?>>Vše</option>
    </select>
    <select name="action">
      <option value="">Všechny akce</option>
      <option value="enter" <?= $filterAction === 'enter' ? 'selected' : '' ?>>Vstup do demo</option>
      <option value="login" <?= $filterAction === 'login' ? 'selected' : '' ?>>Auto-login</option>
      <option value="page_view" <?= $filterAction === 'page_view' ? 'selected' : '' ?>>Page view</option>
      <option value="logout" <?= $filterAction === 'logout' ? 'selected' : '' ?>>Odhlášení</option>
      <option value="auto_reset" <?= $filterAction === 'auto_reset' ? 'selected' : '' ?>>Auto-reset demo</option>
    </select>
    <input type="text" name="ip" placeholder="IP prefix..." value="<?= htmlspecialchars($filterIp ?? '') ?>" style="width:160px">
    <button type="submit" class="btn-master primary">Filtrovat</button>
    <a href="demo-log.php" class="btn-master secondary">Reset</a>
  </form>

  <!-- TABLE -->
  <div class="panel-master" style="padding:0;overflow:hidden">
    <table class="access-table">
      <thead>
        <tr>
          <th>Kdy</th>
          <th>IP</th>
          <th>Akce</th>
          <th>Jazyk</th>
          <th>Referer</th>
          <th>User Agent</th>
        </tr>
      </thead>
      <tbody>
      <?php foreach ($logs as $l): ?>
        <tr>
          <td style="white-space:nowrap;color:#6e6e73"><?= htmlspecialchars(date('d.m.Y H:i:s', strtotime($l['cas']))) ?></td>
          <td><code><?= htmlspecialchars($l['ip']) ?></code></td>
          <td>
            <?php
              $actionColors = [
                'enter' => 'green', 'login' => 'blue', 'page_view' => 'purple', 'logout' => 'orange',
                'auto_reset' => 'orange',  // 🆕 v3.0.387 P2-C — admin_reset_demo zapisuje auto_reset
              ];
              $color = $actionColors[$l['akce']] ?? 'blue';
            ?>
            <span class="badge <?= $color ?>"><?= htmlspecialchars($l['akce']) ?></span>
          </td>
          <td><?= htmlspecialchars($l['jazyk'] ?: '—') ?></td>
          <td style="font-size:12px;color:#6e6e73;max-width:200px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap" title="<?= htmlspecialchars($l['referer']) ?>"><?= htmlspecialchars($l['referer'] ?: '—') ?></td>
          <td class="ua-short" title="<?= htmlspecialchars($l['user_agent']) ?>"><?= htmlspecialchars(substr($l['user_agent'] ?? '', 0, 80)) ?></td>
        </tr>
      <?php endforeach; ?>
      <?php if (empty($logs)): ?>
        <tr><td colspan="6" style="text-align:center;color:#86868b;padding:50px">
          <div style="font-size:32px;margin-bottom:10px">🧪</div>
          Žádné demo přístupy zatím.<br>
          <small>Demo musí být nakonfigurované pro tracking — viz <code>demo/setup.php</code></small>
        </td></tr>
      <?php endif; ?>
      </tbody>
    </table>
  </div>

</main>

<?php vendor_render_footer(); ?>
</body>
</html>
