<?php
/**
 * 🎟️ SLEVOVÉ KUPONY — na prodej APPEK licence (appek.cz/checkout).
 *
 * Vendor spravuje kódy → zákazník je zadá v checkoutu → shop_buy.php odečte slevu.
 * Tabulka `vendor_coupons` ve vendor DB. Validace/výpočet: api/_shop_coupon_lib.php.
 * ⚠️ NEZAMĚŇOVAT se zákaznickými vouchery (admin_vouchers.php = eshop pekárny).
 */
require_once __DIR__ . '/_lib.php';
require_once __DIR__ . '/_layout.php';

$user = vendor_require_login();
$pdo  = vendor_db();
$currentPage = 'shop-coupons';

// Idempotentní schema (shodné s api/_shop_coupon_lib.php)
$pdo->exec("CREATE TABLE IF NOT EXISTS vendor_coupons (
    id INT AUTO_INCREMENT PRIMARY KEY,
    kod VARCHAR(64) NOT NULL UNIQUE,
    typ ENUM('percent','fixed') NOT NULL DEFAULT 'percent',
    hodnota DECIMAL(10,2) NOT NULL DEFAULT 0,
    popis VARCHAR(255) DEFAULT NULL,
    active TINYINT(1) NOT NULL DEFAULT 1,
    plati_do DATE DEFAULT NULL,
    max_pouziti INT DEFAULT NULL,
    pouzito INT NOT NULL DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

if ($_SERVER['REQUEST_METHOD'] === 'POST') vendor_csrf_check();
$flash_ok = null;
$flash_err = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    try {
        if ($action === 'create') {
            $kod = preg_replace('/[^A-Z0-9_-]/', '', strtoupper(trim($_POST['kod'] ?? '')));
            $typ = (($_POST['typ'] ?? 'percent') === 'fixed') ? 'fixed' : 'percent';
            $hodnota = (float) str_replace(',', '.', (string) ($_POST['hodnota'] ?? '0'));
            $popis = trim($_POST['popis'] ?? '') ?: null;
            $platiDo = trim($_POST['plati_do'] ?? '');
            $platiDo = preg_match('/^\d{4}-\d{2}-\d{2}$/', $platiDo) ? $platiDo : null;
            $maxPouziti = (($_POST['max_pouziti'] ?? '') !== '') ? max(1, (int) $_POST['max_pouziti']) : null;

            if ($kod === '') throw new Exception('Kód je povinný (jen A–Z, 0–9, - a _).');
            if ($hodnota <= 0) throw new Exception('Hodnota slevy musí být kladná.');
            if ($typ === 'percent' && $hodnota > 100) throw new Exception('Procento nemůže být přes 100 %.');

            $st = $pdo->prepare("INSERT INTO vendor_coupons (kod, typ, hodnota, popis, plati_do, max_pouziti)
                                 VALUES (:k, :t, :h, :p, :pd, :mp)");
            $st->execute(['k' => $kod, 't' => $typ, 'h' => $hodnota, 'p' => $popis, 'pd' => $platiDo, 'mp' => $maxPouziti]);
            if (function_exists('vendor_audit')) vendor_audit($pdo, $user, 'coupon_create', null, $kod);
            $flash_ok = "Kupon $kod vytvořen.";
        } elseif ($action === 'toggle') {
            $id = (int) ($_POST['id'] ?? 0);
            $pdo->prepare("UPDATE vendor_coupons SET active = 1 - active WHERE id = :id")->execute(['id' => $id]);
            $flash_ok = "Stav kuponu přepnut.";
        } elseif ($action === 'delete') {
            $id = (int) ($_POST['id'] ?? 0);
            $kod = $pdo->prepare("SELECT kod FROM vendor_coupons WHERE id = :id");
            $kod->execute(['id' => $id]);
            $kod = $kod->fetchColumn();
            $pdo->prepare("DELETE FROM vendor_coupons WHERE id = :id")->execute(['id' => $id]);
            if ($kod && function_exists('vendor_audit')) vendor_audit($pdo, $user, 'coupon_delete', null, (string) $kod);
            $flash_ok = "Kupon smazán.";
        }
    } catch (Throwable $e) {
        $m = $e->getMessage();
        if (stripos($m, 'Duplicate') !== false || stripos($m, 'UNIQUE') !== false) $m = 'Kupon s tímto kódem už existuje.';
        $flash_err = $m;
    }
}

$coupons = $pdo->query("SELECT * FROM vendor_coupons ORDER BY active DESC, created_at DESC")->fetchAll(PDO::FETCH_ASSOC);
$today = date('Y-m-d');
?><!DOCTYPE html>
<html lang="cs">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="robots" content="noindex,nofollow">
<title>🎟️ Slevové kupony — APPEK Master</title>
<link rel="stylesheet" href="style.css?v=1.4">
<style>
  .sub { color: #6e6e73; font-size: 14px; margin: -6px 0 20px; max-width: 640px; line-height: 1.5; }
  .flash { padding: 12px 16px; border-radius: 10px; margin-bottom: 14px; font-size: 14px; }
  .flash.ok  { background: #d4edda; color: #155724; }
  .flash.err { background: #f8d7da; color: #721c24; }
  .detail-panel { background: #fff; border-radius: 14px; padding: 22px; box-shadow: 0 1px 3px rgba(0,0,0,0.05); margin-bottom: 22px; border: 1px solid #eee; }
  .coupon-form { display: grid; grid-template-columns: repeat(auto-fit, minmax(150px, 1fr)); gap: 14px; align-items: end; }
  .coupon-form label { display: block; font-size: 11px; text-transform: uppercase; letter-spacing: .4px; color: #6e6e73; font-weight: 700; margin-bottom: 5px; }
  .coupon-form input, .coupon-form select {
    width: 100%; box-sizing: border-box; padding: 9px 12px; border: 1px solid #d2d2d7;
    border-radius: 8px; font-family: inherit; font-size: 14px;
  }
  .coupon-form .full { grid-column: 1 / -1; }
  .order-table { width: 100%; border-collapse: collapse; font-size: 13px; }
  .order-table th, .order-table td { padding: 10px 14px; text-align: left; border-bottom: 1px solid #f0f0f3; }
  .order-table th { background: #fafafa; font-weight: 700; color: #6e6e73; text-transform: uppercase; letter-spacing: .4px; font-size: 11px; }
  .order-table tr:hover td { background: #fafafa; }
  .badge { display: inline-block; padding: 3px 9px; border-radius: 999px; font-size: 11px; font-weight: 700; text-transform: uppercase; }
  .badge.on  { background: rgba(52,199,89,0.15); color: #208438; }
  .badge.off { background: #f5f5f7; color: #6e6e73; }
  .badge.exp { background: rgba(255,59,48,0.15); color: #b30019; }
  code { font-family: 'SF Mono', Menlo, monospace; font-size: 13px; background: #f5f5f7; padding: 3px 8px; border-radius: 5px; font-weight: 700; }
  .actions { display: flex; gap: 6px; }
  .empty { text-align: center; padding: 40px; color: #86868b; }
</style>
</head>
<body>

<?php vendor_render_topbar($user, $currentPage); ?>
<?php vendor_render_back(); ?>

<main class="page-master">
  <h1>🎟️ Slevové kupony</h1>
  <p class="sub">Kupony na prodej APPEK licence (checkout na appek.cz). Vytvoř kód, dej ho zákazníkovi — při objednávce se sleva automaticky odečte z ceny. Toto nejsou zákaznické vouchery pro eshop pekárny.</p>

  <?php if ($flash_ok): ?><div class="flash ok"><?= htmlspecialchars($flash_ok) ?></div><?php endif; ?>
  <?php if ($flash_err): ?><div class="flash err"><?= htmlspecialchars($flash_err) ?></div><?php endif; ?>

  <div class="detail-panel">
    <form method="POST" class="coupon-form">
      <?php vendor_csrf_field(); ?>
      <input type="hidden" name="action" value="create">
      <div>
        <label>Kód</label>
        <input type="text" name="kod" placeholder="NAPŘ. LAUNCH20" required maxlength="64"
               style="text-transform:uppercase" autocomplete="off">
      </div>
      <div>
        <label>Typ slevy</label>
        <select name="typ">
          <option value="percent">Procenta (%)</option>
          <option value="fixed">Pevná částka (Kč)</option>
        </select>
      </div>
      <div>
        <label>Hodnota</label>
        <input type="text" name="hodnota" placeholder="20" required inputmode="decimal">
      </div>
      <div>
        <label>Platnost do <span style="text-transform:none;font-weight:400">(volitelně)</span></label>
        <input type="date" name="plati_do">
      </div>
      <div>
        <label>Max. použití <span style="text-transform:none;font-weight:400">(volitelně)</span></label>
        <input type="number" name="max_pouziti" min="1" placeholder="∞">
      </div>
      <div class="full">
        <label>Popis <span style="text-transform:none;font-weight:400">(interní, volitelně)</span></label>
        <input type="text" name="popis" maxlength="255" placeholder="Např. Sleva pro první zákazníky z Facebooku">
      </div>
      <div class="full">
        <button type="submit" class="btn-master primary">➕ Vytvořit kupon</button>
      </div>
    </form>
  </div>

  <?php if (!$coupons): ?>
    <div class="empty">Zatím žádné kupony. Vytvoř první výše.</div>
  <?php else: ?>
    <table class="order-table">
      <thead>
        <tr>
          <th>Kód</th><th>Sleva</th><th>Popis</th><th>Platnost</th><th>Použití</th><th>Stav</th><th>Akce</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($coupons as $c): ?>
          <?php
            $expired = !empty($c['plati_do']) && $c['plati_do'] < $today;
            $usedUp  = $c['max_pouziti'] !== null && (int) $c['pouzito'] >= (int) $c['max_pouziti'];
            $sleva = $c['typ'] === 'percent'
                ? rtrim(rtrim(number_format((float) $c['hodnota'], 2, ',', ' '), '0'), ',') . ' %'
                : number_format((float) $c['hodnota'], 0, ',', ' ') . ' Kč';
          ?>
          <tr>
            <td><code><?= htmlspecialchars($c['kod']) ?></code></td>
            <td><strong>−<?= $sleva ?></strong></td>
            <td style="color:#6e6e73"><?= htmlspecialchars($c['popis'] ?? '') ?: '—' ?></td>
            <td><?= $c['plati_do'] ? htmlspecialchars($c['plati_do']) : '<span style="color:#86868b">bez limitu</span>' ?></td>
            <td><?= (int) $c['pouzito'] ?><?= $c['max_pouziti'] !== null ? ' / ' . (int) $c['max_pouziti'] : '' ?></td>
            <td>
              <?php if ($expired): ?><span class="badge exp">Vypršel</span>
              <?php elseif ($usedUp): ?><span class="badge exp">Vyčerpán</span>
              <?php elseif ((int) $c['active'] === 1): ?><span class="badge on">Aktivní</span>
              <?php else: ?><span class="badge off">Vypnutý</span><?php endif; ?>
            </td>
            <td>
              <div class="actions">
                <form method="POST" style="display:inline">
                  <?php vendor_csrf_field(); ?>
                  <input type="hidden" name="action" value="toggle">
                  <input type="hidden" name="id" value="<?= (int) $c['id'] ?>">
                  <button type="submit" class="btn-master secondary" style="padding:5px 10px;font-size:12px">
                    <?= (int) $c['active'] === 1 ? '⏸ Vypnout' : '▶ Zapnout' ?>
                  </button>
                </form>
                <form method="POST" style="display:inline" onsubmit="return confirm('Smazat kupon <?= htmlspecialchars($c['kod']) ?>?')">
                  <?php vendor_csrf_field(); ?>
                  <input type="hidden" name="action" value="delete">
                  <input type="hidden" name="id" value="<?= (int) $c['id'] ?>">
                  <button type="submit" class="btn-master secondary" style="padding:5px 10px;font-size:12px;color:#b30019">🗑</button>
                </form>
              </div>
            </td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  <?php endif; ?>
</main>

<?php vendor_render_footer(); ?>
</body>
</html>
