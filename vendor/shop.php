<?php
/**
 * 🛒 SHOP ORDERS — Objednávky z appek.cz/checkout.
 *
 * Workflow:
 *   1. Zákazník na appek.cz/sales/ klikne "Koupit"
 *   2. Vyplní checkout → vznikne záznam vendor_shop_orders (payment_status=pending)
 *   3. Po platbě (manual / brána) přejde na 'paid'
 *   4. Vendor klikne "Vygenerovat licenci" → vznikne klíč ve vendor_licenses
 *   5. E-mail s klíčem se odešle zákazníkovi
 */
require_once __DIR__ . '/_lib.php';
require_once __DIR__ . '/_layout.php';
require_once __DIR__ . '/_mail.php';

$user = vendor_require_login();
$pdo  = vendor_db();
$currentPage = 'shop';

if ($_SERVER['REQUEST_METHOD'] === 'POST') vendor_csrf_check();  // 🔐 CSRF
$flash_ok = null;
$flash_err = null;

// ─── POST akce ──────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $orderId = (int) ($_POST['id'] ?? 0);

    try {
        $order = $pdo->prepare("SELECT * FROM vendor_shop_orders WHERE id = :id");
        $order->execute(['id' => $orderId]);
        $order = $order->fetch();

        if (!$order) throw new Exception('Objednávka neexistuje.');

        if ($action === 'mark_paid') {
            $pdo->prepare("UPDATE vendor_shop_orders SET payment_status = 'paid', paid_at = NOW() WHERE id = :id")
                ->execute(['id' => $orderId]);
            vendor_audit($pdo, $user, 'shop_mark_paid', null, $order['order_no']);
            $flash_ok = "Objednávka označena jako zaplacena. Můžeš teď vygenerovat licenci.";
        } elseif ($action === 'mark_cancelled') {
            $pdo->prepare("UPDATE vendor_shop_orders SET payment_status = 'cancelled' WHERE id = :id")
                ->execute(['id' => $orderId]);
            vendor_audit($pdo, $user, 'shop_cancel', null, $order['order_no']);
            $flash_ok = "Objednávka zrušena.";
        } elseif ($action === 'generate_license') {
            if ($order['payment_status'] !== 'paid') {
                throw new Exception('Objednávka musí být označena jako zaplacena.');
            }
            if (!empty($order['license_id'])) {
                throw new Exception('Licence už vygenerována.');
            }
            $packages = json_decode($order['packages_json'] ?? '[]', true) ?: [];
            $packages = array_filter($packages, fn($k) => $k !== 'core'); // core je default
            $key = license_generate_with_packages($packages);

            // 1 rok platnost defaultně, nebo permanent dle volby (rozšířit později)
            $expires = date('Y-m-d', strtotime('+1 year'));

            $ins = $pdo->prepare("
                INSERT INTO vendor_licenses
                  (license_key, customer_name, customer_company, customer_email, customer_phone,
                   install_url, note, expires_at, status, price_kc, paid, issued_by_id)
                VALUES (:k, :n, :c, :e, :p, :u, :note, :exp, 'active', :pr, 1, :uid)
            ");
            $ins->execute([
                'k' => $key,
                'n' => $order['customer_name'],
                'c' => $order['customer_company'],
                'e' => $order['customer_email'],
                'p' => $order['customer_phone'],
                'u' => $order['install_url'],
                'note' => "Auto-generated z objednávky " . $order['order_no'],
                'exp' => $expires,
                'pr' => $order['total_kc'],
                'uid' => $user['id'],
            ]);
            $licenseId = (int) $pdo->lastInsertId();

            $pdo->prepare("UPDATE vendor_shop_orders SET license_id = :lid, license_key = :lk WHERE id = :id")
                ->execute(['lid' => $licenseId, 'lk' => $key, 'id' => $orderId]);

            vendor_audit($pdo, $user, 'shop_license_gen', ['id' => $licenseId, 'license_key' => $key], $order['order_no']);

            // Auto-email zákazníkovi (best-effort)
            $emailSent = false;
            $emailErr = null;
            try {
                $licenseData = [
                    'customer_name'  => $order['customer_name'],
                    'customer_email' => $order['customer_email'],
                    'license_key'    => $key,
                ];
                $tpl = vendor_mail_template_license($licenseData, $order);
                $emailSent = vendor_send_mail(
                    $order['customer_email'],
                    '🔑 Vaše APPEK licence — ' . $key,
                    $tpl['html'],
                    $tpl['text'],
                    null,
                    $emailErr
                );
            } catch (Throwable $e) { $emailErr = $e->getMessage(); }

            if ($emailSent) {
                $flash_ok = "Licence vygenerována: <code>$key</code> — automaticky odeslána e-mailem na <strong>" . htmlspecialchars($order['customer_email']) . "</strong>.";
            } else {
                $flash_ok = "Licence vygenerována: <code>$key</code>. ⚠️ E-mail se nepodařilo odeslat (" . htmlspecialchars($emailErr ?: 'mail() nedostupný') . "). Pošli klíč ručně.";
            }
        }
        header('Location: shop.php' . (isset($_POST['detail']) ? '?detail=' . $orderId : ''));
        exit;
    } catch (Throwable $e) {
        $flash_err = $e->getMessage();
    }
}

// ─── Filtry & list ──────────────────────────────────────────────
$filterStatus = $_GET['status'] ?? '';
$q = trim($_GET['q'] ?? '');
$where = [];
$params = [];
if (in_array($filterStatus, ['pending', 'paid', 'failed', 'cancelled', 'refunded'], true)) {
    $where[] = "payment_status = :s";
    $params['s'] = $filterStatus;
}
if ($q !== '') {
    $where[] = "(customer_name LIKE :q OR customer_email LIKE :q OR customer_company LIKE :q OR order_no LIKE :q)";
    $params['q'] = "%$q%";
}
$sql = "SELECT * FROM vendor_shop_orders" . ($where ? " WHERE " . implode(' AND ', $where) : '') . " ORDER BY id DESC LIMIT 200";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$orders = $stmt->fetchAll();

// Statistiky
$stats = [
    'total'    => (int) $pdo->query("SELECT COUNT(*) FROM vendor_shop_orders")->fetchColumn(),
    'pending'  => (int) $pdo->query("SELECT COUNT(*) FROM vendor_shop_orders WHERE payment_status='pending'")->fetchColumn(),
    'paid'     => (int) $pdo->query("SELECT COUNT(*) FROM vendor_shop_orders WHERE payment_status='paid'")->fetchColumn(),
    'revenue'  => (float) $pdo->query("SELECT COALESCE(SUM(total_kc),0) FROM vendor_shop_orders WHERE payment_status='paid'")->fetchColumn(),
    'today'    => (int) $pdo->query("SELECT COUNT(*) FROM vendor_shop_orders WHERE DATE(created_at) = CURDATE()")->fetchColumn(),
];

// Detail objednávky
$detail = null;
$detailId = (int) ($_GET['detail'] ?? 0);
if ($detailId > 0) {
    $stmt = $pdo->prepare("SELECT * FROM vendor_shop_orders WHERE id = :id");
    $stmt->execute(['id' => $detailId]);
    $detail = $stmt->fetch();
}
?><!DOCTYPE html>
<html lang="cs">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="robots" content="noindex,nofollow">
<title>🛒 Objednávky — APPEK Master</title>
<link rel="stylesheet" href="style.css?v=1.4">
<style>
  .stats-row { display: grid; grid-template-columns: repeat(auto-fit, minmax(160px, 1fr)); gap: 14px; margin-bottom: 20px; }
  .stat-card { background: #fff; border-radius: 10px; padding: 16px 20px; border: 1px solid #e5e5e7; }
  .stat-card .label { font-size: 11px; color: #6e6e73; text-transform: uppercase; letter-spacing: 0.5px; }
  .stat-card .value { font-size: 26px; font-weight: 800; margin-top: 4px; }
  .filters { display: flex; gap: 10px; flex-wrap: wrap; margin-bottom: 16px; align-items: center; }
  .filters select, .filters input {
    padding: 8px 12px; border: 1px solid #d2d2d7; border-radius: 8px;
    font-family: inherit; font-size: 13px;
  }
  .order-table { width: 100%; border-collapse: collapse; font-size: 13px; }
  .order-table th, .order-table td { padding: 10px 14px; text-align: left; border-bottom: 1px solid #f0f0f3; }
  .order-table th { background: #fafafa; font-weight: 700; color: #6e6e73; text-transform: uppercase; letter-spacing: 0.4px; font-size: 11px; }
  .order-table tr:hover td { background: #fafafa; }
  .badge { display: inline-block; padding: 3px 8px; border-radius: 999px; font-size: 11px; font-weight: 700; text-transform: uppercase; }
  .badge.pending  { background: rgba(255,149,0,0.15); color: #c66800; }
  .badge.paid     { background: rgba(52,199,89,0.15); color: #208438; }
  .badge.failed   { background: rgba(255,59,48,0.15); color: #b30019; }
  .badge.cancelled { background: #f5f5f7; color: #6e6e73; }
  .badge.refunded { background: rgba(0,122,255,0.15); color: #0058b8; }
  .order-row .actions { display: flex; gap: 4px; }
  .flash { padding: 12px 16px; border-radius: 10px; margin-bottom: 14px; font-size: 14px; }
  .flash.ok  { background: #d4edda; color: #155724; }
  .flash.err { background: #f8d7da; color: #721c24; }
  .detail-panel { background: #fff; border-radius: 14px; padding: 24px; box-shadow: 0 1px 3px rgba(0,0,0,0.05); margin-bottom: 20px; }
  .detail-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: 14px; }
  .detail-grid .row dt { font-size: 11px; color: #86868b; text-transform: uppercase; letter-spacing: 0.4px; }
  .detail-grid .row dd { font-size: 14px; color: #1d1d1f; margin: 2px 0 0; font-weight: 500; }
  code { font-family: 'SF Mono', Menlo, monospace; font-size: 12px; background: #f5f5f7; padding: 2px 6px; border-radius: 4px; }
</style>
</head>
<body>

<?php vendor_render_topbar($user, $currentPage); ?>
<?php vendor_render_back(); ?>

<main class="page-master">

  <div class="page-header-master">
    <h1>🛒 Objednávky z eshopu</h1>
    <div style="font-size:13px;color:#86868b">appek.cz/checkout → tady → licence</div>
  </div>

  <?php if ($flash_ok): ?><div class="flash ok">✅ <?= $flash_ok ?></div><?php endif; ?>
  <?php if ($flash_err): ?><div class="flash err">❌ <?= htmlspecialchars($flash_err) ?></div><?php endif; ?>

  <div class="stats-row">
    <div class="stat-card"><div class="label">Celkem</div><div class="value"><?= $stats['total'] ?></div></div>
    <div class="stat-card"><div class="label">Čeká na platbu</div><div class="value" style="color:#ff9500"><?= $stats['pending'] ?></div></div>
    <div class="stat-card"><div class="label">Zaplaceno</div><div class="value" style="color:#34c759"><?= $stats['paid'] ?></div></div>
    <div class="stat-card"><div class="label">Tržby</div><div class="value"><?= number_format($stats['revenue'], 0, ',', ' ') ?> Kč</div></div>
    <div class="stat-card"><div class="label">Dnes</div><div class="value"><?= $stats['today'] ?></div></div>
  </div>

  <?php if ($detail): ?>
    <div class="detail-panel">
      <div style="display:flex;justify-content:space-between;align-items:flex-start;margin-bottom:14px">
        <div>
          <h2 style="margin:0 0 6px;font-size:18px">Objednávka <?= htmlspecialchars($detail['order_no']) ?></h2>
          <span class="badge <?= htmlspecialchars($detail['payment_status']) ?>"><?= htmlspecialchars($detail['payment_status']) ?></span>
        </div>
        <a href="shop.php" class="btn-master secondary">← Zpět na seznam</a>
      </div>

      <div class="detail-grid">
        <div class="row">
          <dt>Datum</dt>
          <dd><?= htmlspecialchars(date('d.m.Y H:i', strtotime($detail['created_at']))) ?></dd>
        </div>
        <div class="row">
          <dt>Zákazník</dt>
          <dd><?= htmlspecialchars($detail['customer_name']) ?></dd>
        </div>
        <div class="row">
          <dt>Firma</dt>
          <dd><?= htmlspecialchars($detail['customer_company'] ?: '—') ?></dd>
        </div>
        <div class="row">
          <dt>E-mail</dt>
          <dd><?= htmlspecialchars($detail['customer_email']) ?></dd>
        </div>
        <div class="row">
          <dt>Telefon</dt>
          <dd><?= htmlspecialchars($detail['customer_phone'] ?: '—') ?></dd>
        </div>
        <div class="row">
          <dt>IČO / DIČ</dt>
          <dd><?= htmlspecialchars(($detail['customer_ico'] ?: '—') . ' / ' . ($detail['customer_dic'] ?: '—')) ?></dd>
        </div>
        <div class="row">
          <dt>URL instalace</dt>
          <dd><?= $detail['install_url'] ? '<a href="' . htmlspecialchars($detail['install_url']) . '" target="_blank">' . htmlspecialchars($detail['install_url']) . '</a>' : '—' ?></dd>
        </div>
        <div class="row">
          <dt>Tarif</dt>
          <dd><?= htmlspecialchars($detail['tier'] ?: '—') ?></dd>
        </div>
        <div class="row">
          <dt>Balíčky</dt>
          <dd>
            <?php
              $pkgs = json_decode($detail['packages_json'] ?? '[]', true) ?: [];
              echo implode(', ', array_map('htmlspecialchars', $pkgs)) ?: '—';
            ?>
          </dd>
        </div>
        <div class="row">
          <dt>Celkem</dt>
          <dd style="font-size:18px;font-weight:700"><?= number_format((float) $detail['total_kc'], 0, ',', ' ') ?> Kč</dd>
        </div>
        <div class="row">
          <dt>Platba</dt>
          <dd><?= htmlspecialchars($detail['payment_method']) ?> · <?= htmlspecialchars($detail['payment_status']) ?></dd>
        </div>
        <div class="row">
          <dt>Vygenerovaná licence</dt>
          <dd>
            <?php if ($detail['license_key']): ?>
              <code><?= htmlspecialchars($detail['license_key']) ?></code>
            <?php else: ?>
              <em style="color:#86868b">Zatím nevygenerovaná</em>
            <?php endif; ?>
          </dd>
        </div>
      </div>

      <?php if ($detail['notes']): ?>
        <div style="margin-top:14px;padding:12px 16px;background:#fafafa;border-radius:10px">
          <strong style="font-size:12px;text-transform:uppercase;color:#86868b">Poznámka</strong><br>
          <?= nl2br(htmlspecialchars($detail['notes'])) ?>
        </div>
      <?php endif; ?>

      <div style="display:flex;gap:8px;flex-wrap:wrap;margin-top:18px;padding-top:18px;border-top:1px solid #e5e5e7">
        <?php if ($detail['payment_status'] === 'pending'): ?>
          <form method="POST" style="display:inline">
            <?php vendor_csrf_field(); ?>
            <input type="hidden" name="action" value="mark_paid">
            <input type="hidden" name="id" value="<?= (int) $detail['id'] ?>">
            <input type="hidden" name="detail" value="1">
            <button type="submit" class="btn-master primary">✅ Označit jako zaplaceno</button>
          </form>
          <form method="POST" style="display:inline" onsubmit="return confirm('Zrušit objednávku?')">
            <?php vendor_csrf_field(); ?>
            <input type="hidden" name="action" value="mark_cancelled">
            <input type="hidden" name="id" value="<?= (int) $detail['id'] ?>">
            <input type="hidden" name="detail" value="1">
            <button type="submit" class="btn-master secondary">🚫 Zrušit</button>
          </form>
        <?php endif; ?>
        <?php if ($detail['payment_status'] === 'paid' && empty($detail['license_id'])): ?>
          <form method="POST" style="display:inline">
            <?php vendor_csrf_field(); ?>
            <input type="hidden" name="action" value="generate_license">
            <input type="hidden" name="id" value="<?= (int) $detail['id'] ?>">
            <input type="hidden" name="detail" value="1">
            <button type="submit" class="btn-master primary">🔑 Vygenerovat licenci</button>
          </form>
        <?php endif; ?>
        <?php if ($detail['license_id']): ?>
          <a href="index.php#lic-<?= (int) $detail['license_id'] ?>" class="btn-master secondary">→ Otevřít licenci v Licencích</a>
        <?php endif; ?>
      </div>
    </div>
  <?php endif; ?>

  <form class="filters" method="GET">
    <span style="font-weight:600;font-size:13px">Filtr:</span>
    <select name="status">
      <option value="">Všechny stavy</option>
      <option value="pending"   <?= $filterStatus === 'pending' ? 'selected' : '' ?>>Čeká na platbu</option>
      <option value="paid"      <?= $filterStatus === 'paid' ? 'selected' : '' ?>>Zaplaceno</option>
      <option value="failed"    <?= $filterStatus === 'failed' ? 'selected' : '' ?>>Selhalo</option>
      <option value="cancelled" <?= $filterStatus === 'cancelled' ? 'selected' : '' ?>>Zrušeno</option>
      <option value="refunded"  <?= $filterStatus === 'refunded' ? 'selected' : '' ?>>Vráceno</option>
    </select>
    <input type="text" name="q" placeholder="Hledat (jméno, e-mail, objednávka...)" value="<?= htmlspecialchars($q) ?>" style="width:280px">
    <button type="submit" class="btn-master primary">Filtrovat</button>
    <a href="shop.php" class="btn-master secondary">Reset</a>
  </form>

  <div class="panel-master" style="padding:0;overflow:hidden">
    <table class="order-table">
      <thead>
        <tr>
          <th>Objednávka</th>
          <th>Zákazník</th>
          <th>Tarif / Balíčky</th>
          <th>Cena</th>
          <th>Stav</th>
          <th>Licence</th>
          <th>Datum</th>
          <th></th>
        </tr>
      </thead>
      <tbody>
      <?php foreach ($orders as $o): ?>
        <tr class="order-row">
          <td><code><?= htmlspecialchars($o['order_no']) ?></code></td>
          <td>
            <strong><?= htmlspecialchars($o['customer_name']) ?></strong>
            <?php if ($o['customer_company']): ?><br><small style="color:#86868b"><?= htmlspecialchars($o['customer_company']) ?></small><?php endif; ?>
            <br><a href="mailto:<?= htmlspecialchars($o['customer_email']) ?>" style="font-size:11px;color:#0a4a8b"><?= htmlspecialchars($o['customer_email']) ?></a>
          </td>
          <td>
            <strong><?= htmlspecialchars($o['tier'] ?: '—') ?></strong>
            <?php $pkgs = json_decode($o['packages_json'] ?? '[]', true) ?: []; ?>
            <?php if ($pkgs): ?><br><small style="color:#86868b"><?= htmlspecialchars(implode(', ', $pkgs)) ?></small><?php endif; ?>
          </td>
          <td><strong><?= number_format((float) $o['total_kc'], 0, ',', ' ') ?> Kč</strong></td>
          <td><span class="badge <?= htmlspecialchars($o['payment_status']) ?>"><?= htmlspecialchars($o['payment_status']) ?></span></td>
          <td>
            <?php if ($o['license_key']): ?>
              <code style="font-size:10px"><?= htmlspecialchars(substr($o['license_key'], 0, 11)) ?>…</code>
            <?php else: ?>
              <span style="color:#86868b">—</span>
            <?php endif; ?>
          </td>
          <td style="white-space:nowrap;color:#6e6e73"><?= htmlspecialchars(date('d.m. H:i', strtotime($o['created_at']))) ?></td>
          <td><a href="shop.php?detail=<?= (int) $o['id'] ?>" class="btn-master secondary" style="font-size:12px;padding:5px 10px">Detail →</a></td>
        </tr>
      <?php endforeach; ?>
      <?php if (empty($orders)): ?>
        <tr><td colspan="8" style="text-align:center;color:#86868b;padding:60px 20px">
          <div style="font-size:36px;margin-bottom:10px">🛒</div>
          <strong>Žádné objednávky zatím</strong><br>
          <small>Až někdo na <code>appek.cz/sales/</code> klikne „Koupit", objeví se tady.</small>
        </td></tr>
      <?php endif; ?>
      </tbody>
    </table>
  </div>

</main>

<?php vendor_render_footer(); ?>
</body>
</html>
