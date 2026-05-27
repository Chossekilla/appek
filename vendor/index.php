<?php
/**
 * 📊 VENDOR DASHBOARD — central hub pro správu celého appek.cz ekosystému.
 *
 * Struktura:
 *   1. PRODUKT      — Web, Eshop, Balíčky
 *   2. ZÁKAZNÍCI    — Licence, Aktualizace, Přístupy
 *   3. SHOWCASE     — Demo, Admin, B2B
 *
 * Tahle stránka řeší jen login + dashboard. Tabulka licencí je teď
 * v licenses.php (nav: 🔑 Licence).
 */
require_once __DIR__ . '/_lib.php';
require_once __DIR__ . '/_layout.php';

// Auto-redirect na install pokud chybí config
if (!file_exists(__DIR__ . '/config.local.php') || !file_exists(__DIR__ . '/.installed')) {
    header('Location: install.php');
    exit;
}

// POST login (s podporou TOTP 2FA)
$err = null;
$needTotp = false;
$savedUser = $_POST['username'] ?? '';
$savedPw   = $_POST['password'] ?? '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['username'])) {
    $totpCode = trim($_POST['totp_code'] ?? '');
    $result = vendor_login($_POST['username'], $_POST['password'] ?? '', $totpCode);
    if ($result === 'ok') {
        header('Location: index.php'); exit;
    } elseif ($result === 'totp_required') {
        $needTotp = true;
        $err = 'Zadej 6místný kód z autentikační aplikace.';
    } elseif ($result === 'totp_bad') {
        $needTotp = true;
        $err = 'Neplatný kód. Zkus znovu (kód se mění každých 30s).';
    } else {
        $err = 'Neplatné přihlašovací údaje.';
    }
}

// Logout
if (isset($_GET['logout'])) {
    vendor_logout();
    header('Location: index.php'); exit;
}

$user = vendor_user();

// ── Načti statistiky pro karty ───────────────────────────────────
$kpi = [
    'lic_total' => 0, 'lic_active' => 0, 'lic_expiring' => 0,
    'orders_total' => 0, 'orders_pending' => 0, 'orders_paid' => 0,
    'revenue_mtd' => 0, 'revenue_total' => 0,
    'packages_total' => 0, 'packages_active' => 0,
    'updates_total' => 0, 'latest_version' => null,
    'demo_total' => 0, 'demo_today' => 0,
    'admin_users' => 0, 'b2b_users' => 0, 'vendor_users' => 0,
    'pirate_total' => 0, 'pirate_open' => 0, 'pirate_24h' => 0,
];

if ($user) {
    try {
        $pdo = vendor_db();
        $kpi['lic_total']    = (int) $pdo->query("SELECT COUNT(*) FROM vendor_licenses")->fetchColumn();
        $kpi['lic_active']   = (int) $pdo->query("SELECT COUNT(*) FROM vendor_licenses WHERE status='active'")->fetchColumn();
        $kpi['lic_expiring'] = (int) $pdo->query("
            SELECT COUNT(*) FROM vendor_licenses
            WHERE status='active' AND expires_at IS NOT NULL
              AND expires_at <= DATE_ADD(CURDATE(), INTERVAL 30 DAY)
        ")->fetchColumn();
        try {
            $kpi['orders_total']   = (int) $pdo->query("SELECT COUNT(*) FROM vendor_shop_orders")->fetchColumn();
            $kpi['orders_pending'] = (int) $pdo->query("SELECT COUNT(*) FROM vendor_shop_orders WHERE payment_status='pending'")->fetchColumn();
            $kpi['orders_paid']    = (int) $pdo->query("SELECT COUNT(*) FROM vendor_shop_orders WHERE payment_status='paid'")->fetchColumn();
            $kpi['revenue_mtd']    = (float) $pdo->query("SELECT COALESCE(SUM(total_kc),0) FROM vendor_shop_orders WHERE payment_status='paid' AND paid_at >= DATE_FORMAT(CURDATE(),'%Y-%m-01')")->fetchColumn();
            $kpi['revenue_total']  = (float) $pdo->query("SELECT COALESCE(SUM(total_kc),0) FROM vendor_shop_orders WHERE payment_status='paid'")->fetchColumn();
        } catch (Throwable $e) { /* tabulka může neexistovat */ }
        try {
            $kpi['packages_total']  = (int) $pdo->query("SELECT COUNT(*) FROM vendor_packages")->fetchColumn();
            $kpi['packages_active'] = (int) $pdo->query("SELECT COUNT(*) FROM vendor_packages WHERE is_active=1")->fetchColumn();
        } catch (Throwable $e) { /* neexistuje */ }
        try {
            $kpi['updates_total']   = (int) $pdo->query("SELECT COUNT(*) FROM vendor_updates")->fetchColumn();
            $kpi['latest_version']  = $pdo->query("SELECT version FROM vendor_updates WHERE status='published' ORDER BY id DESC LIMIT 1")->fetchColumn() ?: null;
        } catch (Throwable $e) { /* neexistuje */ }
        $kpi['vendor_users'] = (int) $pdo->query("SELECT COUNT(*) FROM vendor_users")->fetchColumn();

        // 🏴‍☠️ Pirate stats
        try {
            $kpi['pirate_total'] = (int) $pdo->query("SELECT COUNT(*) FROM vendor_pirate_installs")->fetchColumn();
            $kpi['pirate_open']  = (int) $pdo->query("SELECT COUNT(*) FROM vendor_pirate_installs WHERE status IN ('new','contacted','warned')")->fetchColumn();
            $kpi['pirate_24h']   = (int) $pdo->query("SELECT COUNT(*) FROM vendor_pirate_installs WHERE last_seen > DATE_SUB(NOW(), INTERVAL 24 HOUR)")->fetchColumn();
        } catch (Throwable $e) { /* tabulka může neexistovat */ }

        // Demo + admin/b2b uživatelé z customer DB
        $mainConfig = realpath(__DIR__ . '/..') . '/api/config.local.php';
        if (file_exists($mainConfig)) {
            try {
                require_once $mainConfig;
                if (defined('DB_HOST')) {
                    $mainPdo = new PDO("mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8mb4",
                        DB_USER, DB_PASS, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
                    try { $kpi['demo_total']  = (int) $mainPdo->query("SELECT COUNT(*) FROM demo_pristupy")->fetchColumn(); } catch (Throwable $e) {}
                    try { $kpi['demo_today']  = (int) $mainPdo->query("SELECT COUNT(*) FROM demo_pristupy WHERE DATE(cas) = CURDATE()")->fetchColumn(); } catch (Throwable $e) {}
                    try { $kpi['admin_users'] = (int) $mainPdo->query("SELECT COUNT(*) FROM admin_users")->fetchColumn(); } catch (Throwable $e) {}
                    try { $kpi['b2b_users']   = (int) $mainPdo->query("SELECT COUNT(*) FROM odberatele WHERE login_email IS NOT NULL AND login_email <> ''")->fetchColumn(); } catch (Throwable $e) {}
                }
            } catch (Throwable $e) { /* DB nedostupná */ }
        }
    } catch (Throwable $e) { /* ignoruj */ }
}

function fmtKc(float $v): string {
    return number_format($v, 0, ',', ' ') . ' Kč';
}
?><!DOCTYPE html>
<html lang="cs">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="robots" content="noindex,nofollow">
<title>🏢 APPEK Master — Vendor Panel</title>
<link rel="stylesheet" href="style.css?v=1.6">
<style>
  /* 🆕 v3.0.68 — REVERT frog background (user: "žáby smaž :)") na světlou modrou. */
  body {
    position: relative;
    background: #E0F2FE !important;   /* světlounká modrá (sky-100) */
  }

  /* 🆕 v3.0.68 — Compact UI density v menu / hub (user: "Hustota UI menší, kompaktní") */
  .topbar { padding: 8px 0 !important; }
  .topbar-inner { padding: 0 16px !important; }
  .brand { font-size: 15px !important; }
  .btn-link { padding: 5px 10px !important; font-size: 12px !important; }
  .user-chip { font-size: 12px !important; }
  /* Hub-grid: nižší padding/gap aby se vešlo víc karet */
  .hub-grid { gap: 10px !important; }
  .hub-card { padding: 12px 14px !important; border-radius: 10px !important; }
  .hub-card .head { gap: 8px !important; margin-bottom: 6px !important; }
  .hub-card .head .ico { font-size: 20px !important; }
  .hub-card .head .name { font-size: 14px !important; }
  .hub-card .head .url { font-size: 11px !important; }
  .hub-card .desc { font-size: 12px !important; margin-bottom: 8px !important; line-height: 1.35 !important; }
  .hub-card .row-stats { gap: 10px !important; font-size: 11px !important; }
  .hub-card .row-stats strong { font-size: 14px !important; margin-right: 4px !important; }
  .hub-card .actions { gap: 6px !important; margin-top: 8px !important; }
  .hub-card .actions a { padding: 5px 10px !important; font-size: 11.5px !important; }
  /* Section header smaller */
  .section-h { margin: 18px 0 8px !important; font-size: 10px !important; }
  /* Dashboard padding menší */
  .dashboard { padding: 14px 16px !important; }

  /* ─── Login screen ─── */
  .login-wrap {
    min-height: 100vh; display: flex; align-items: center; justify-content: center;
    background: linear-gradient(135deg, rgba(29,29,31,0.85) 0%, rgba(45,45,48,0.85) 100%);
    padding: 20px;
  }
  .login-card {
    background: #fff; border-radius: 18px; padding: 38px 42px;
    width: 100%; max-width: 420px; box-shadow: 0 30px 60px rgba(0,0,0,0.3);
  }
  .login-head h1 { font-size: 22px; margin: 0 0 4px; color: #1d1d1f; }
  .login-head .sub { font-size: 13px; color: #6e6e73; margin-bottom: 22px; }
  .login-card label { display: block; margin-bottom: 14px; }
  .login-card .lbl { font-size: 12px; font-weight: 600; color: #1d1d1f; display: block; margin-bottom: 4px; }
  .login-card input[type="text"], .login-card input[type="password"] {
    width: 100%; padding: 10px 14px; border: 1px solid #d2d2d7; border-radius: 9px;
    font-family: inherit; font-size: 14px; box-sizing: border-box;
  }
  .login-card .btn {
    width: 100%; padding: 12px; margin-top: 8px;
    background: linear-gradient(180deg, #BA7517, #854F0B);
    color: #fff; border: none; border-radius: 10px;
    font-weight: 700; font-size: 14px; cursor: pointer;
  }
  .login-card .alert.err { background: #fde7e9; color: #a8232f; padding: 10px 14px; border-radius: 8px; margin-bottom: 14px; font-size: 13px; }

  /* ─── Dashboard hero KPI ─── */
  .hero-kpi {
    display: grid; grid-template-columns: repeat(auto-fit, minmax(170px, 1fr));
    gap: 12px; margin-bottom: 30px;
  }
  .kpi-tile {
    background: linear-gradient(135deg, #fff 0%, #fafafa 100%);
    border: 1px solid #e5e5e7; border-radius: 14px;
    padding: 18px 20px;
  }
  .kpi-tile .lbl { font-size: 11px; color: #86868b; text-transform: uppercase; letter-spacing: 0.5px; font-weight: 600; }
  .kpi-tile .val { font-size: 28px; font-weight: 800; margin-top: 6px; color: #1d1d1f; }
  .kpi-tile .sub { font-size: 12px; color: #6e6e73; margin-top: 2px; }
  .kpi-tile.gold .val { color: #BA7517; }
  .kpi-tile.green .val { color: #208438; }
  .kpi-tile.orange .val { color: #c66800; }
  .kpi-tile.blue .val { color: #0058b8; }

  /* ─── Section headers ─── */
  .section-h {
    display: flex; align-items: center; gap: 10px;
    margin: 30px 0 14px;
    font-size: 12px; font-weight: 700; letter-spacing: 1.5px;
    text-transform: uppercase; color: #86868b;
  }
  .section-h::before, .section-h::after {
    content: ''; flex: 1; height: 1px; background: #e5e5e7;
  }

  /* ─── Karty subdomén / sekcí ─── */
  .card-grid {
    display: grid; grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
    gap: 14px;
  }
  .hub-card {
    background: #fff; border-radius: 14px; padding: 18px 20px;
    border: 1px solid #e5e5e7;
    display: flex; flex-direction: column; gap: 10px;
    box-shadow: 0 1px 3px rgba(0,0,0,0.04);
    transition: transform 0.15s, box-shadow 0.15s, border-color 0.15s;
    position: relative;
    cursor: pointer;
  }
  .hub-card:hover {
    transform: translateY(-3px);
    box-shadow: 0 8px 20px rgba(0,0,0,0.1);
    border-color: #BA7517;
  }
  .hub-card::after {
    content: '→';
    position: absolute; top: 18px; right: 18px;
    color: #c7c7cc; font-size: 18px; font-weight: 300;
    transition: all 0.2s;
  }
  .hub-card:hover::after { color: #BA7517; transform: translateX(4px); }
  /* Primary card overlay link — covers whole card a chytá kliky všude */
  .hub-card .card-link {
    position: absolute; inset: 0; z-index: 5;
    border-radius: inherit;
    text-decoration: none;
    background: transparent;
  }
  /* Inner content visible ALE NEPŘIJÍMÁ kliky — klik propadne na overlay link.
     Akční tlačítka explicitně re-enablují pointer-events. */
  .hub-card .head,
  .hub-card .url,
  .hub-card .desc,
  .hub-card .row-stats { position: relative; z-index: 2; pointer-events: none; }
  /* .row-stats má i odkaz "Otevřít web" — povol kliky na <a> uvnitř */
  .hub-card .row-stats a { pointer-events: auto; position: relative; z-index: 10; }
  /* Akční tlačítka jsou NAD overlay linkem a přijímají kliky */
  .hub-card .actions { position: relative; z-index: 10; }
  .hub-card .actions a, .hub-card .actions button { pointer-events: auto; position: relative; z-index: 10; }
  .hub-card .head { display: flex; align-items: center; gap: 10px; }
  .hub-card .ico  { font-size: 26px; }
  .hub-card .name { font-weight: 700; font-size: 15px; color: #1d1d1f; }
  .hub-card .url  { font-size: 11px; color: #86868b; font-family: 'SF Mono', Menlo, monospace; word-break: break-all; }
  .hub-card .desc { font-size: 12.5px; color: #6e6e73; line-height: 1.5; min-height: 36px; }
  .hub-card .row-stats { display: flex; gap: 14px; font-size: 12px; color: #86868b; padding-top: 8px; border-top: 1px solid #f0f0f3; }
  .hub-card .row-stats strong { color: #1d1d1f; font-size: 16px; display: block; }
  .hub-card .actions { display: flex; gap: 6px; margin-top: auto; padding-top: 8px; flex-wrap: wrap; }
  .hub-card .actions a {
    flex: 1; min-width: 78px; text-align: center;
    padding: 8px 10px; border-radius: 8px;
    background: #f5f5f7; color: #1d1d1f; text-decoration: none;
    font-size: 12px; font-weight: 600; transition: background 0.15s;
  }
  .hub-card .actions a:hover { background: #eaeaec; }
  .hub-card .actions a.primary { background: linear-gradient(180deg, #BA7517, #854F0B); color: #fff; }

  .hub-card.web    { border-left: 3px solid #0058b8; }
  .hub-card.shop   { border-left: 3px solid #BA7517; }
  .hub-card.pkg    { border-left: 3px solid #6b21a8; }
  .hub-card.lic    { border-left: 3px solid #208438; }
  .hub-card.upd    { border-left: 3px solid #d4a017; }
  .hub-card.acc    { border-left: 3px solid #c66800; }
  .hub-card.demo   { border-left: 3px solid #6b21a8; }
  .hub-card.admin  { border-left: 3px solid #BA7517; }
  .hub-card.b2b    { border-left: 3px solid #208438; }

  .hub-card .icon-web   { color: #0058b8; }
  .hub-card .icon-shop  { color: #BA7517; }
  .hub-card .icon-pkg   { color: #6b21a8; }
  .hub-card .icon-lic   { color: #208438; }
  .hub-card .icon-upd   { color: #d4a017; }
  .hub-card .icon-acc   { color: #c66800; }
</style>
</head>
<body>

<?php if (!$user): ?>
<!-- ═══════ LOGIN ═══════ -->
<div class="login-wrap">
  <div class="login-card">
    <div class="login-head">
      <h1>🏢 APPEK Master</h1>
      <div class="sub">Vendor panel · Komplet vláda nad appek.cz</div>
    </div>
    <?php if ($err): ?><div class="alert err">❌ <?= htmlspecialchars($err) ?></div><?php endif; ?>
    <form method="POST">
      <label><span class="lbl">Username</span>
        <input type="text" name="username" value="<?= htmlspecialchars($savedUser) ?>" required <?= $needTotp ? 'readonly' : 'autofocus' ?>>
      </label>
      <label><span class="lbl">Heslo</span>
        <input type="password" name="password" value="<?= htmlspecialchars($savedPw) ?>" required>
      </label>
      <?php if ($needTotp): ?>
        <label><span class="lbl">🔐 2FA kód (6 číslic)</span>
          <input type="text" name="totp_code" pattern="[0-9]{6}" maxlength="6" inputmode="numeric" autocomplete="one-time-code"
                 required autofocus style="font-family:'SF Mono',Menlo,monospace;letter-spacing:0.2em;font-size:18px;text-align:center">
          <small style="color:#86868b;font-size:11px">Z aplikace Google Authenticator / Authy / 1Password / …</small>
        </label>
      <?php endif; ?>
      <button class="btn" type="submit"><?= $needTotp ? '🔐 Ověřit kód' : '🔓 Přihlásit' ?></button>
    </form>
  </div>
</div>

<?php else:
    vendor_render_topbar($user, 'dashboard');
?>

<main class="page-master">

  <div class="page-header-master">
    <h1>📊 Přehled</h1>
    <div style="font-size:13px;color:#86868b">Vítej zpět, <strong><?= htmlspecialchars($user['display_name'] ?: $user['username']) ?></strong> · komplet vláda nad appek.cz z jednoho místa</div>
  </div>

  <!-- ⚡ RYCHLÉ AKCE -->
  <div class="quick-actions">
    <a href="self-update.php" style="background:linear-gradient(135deg,#BA7517,#854F0B);color:#fff;border-color:#854F0B"><span>🚀</span> Self-update</a>
    <a href="business-info.php"><span>🏢</span> Údaje firmy</a>
    <a href="pages-editor.php"><span>✏️</span> Editor webu</a>
    <a href="packages.php?new=1"><span>🎁</span> Nový balíček</a>
    <a href="updates.php?upload=1"><span>⬆️</span> Customer update</a>
    <a href="shop.php?status=pending"><span>🛒</span> Čekající objednávky</a>
    <?php if ($kpi['pirate_open'] > 0): ?>
      <a href="pirate.php?filter=open" style="background:linear-gradient(135deg,#DC2626,#991B1B);color:#fff;border-color:#991B1B"><span>🏴‍☠️</span> Pirate (<?= $kpi['pirate_open'] ?>)</a>
    <?php endif; ?>
    <a href="settings.php"><span>⚙️</span> Nastavení</a>
    <a href="https://appek.cz/#pricing" target="_blank" rel="noopener" style="background:linear-gradient(135deg,#16a34a,#15803d);color:#fff;border-color:#15803d"><span>🛍️</span> Frontpage Shop ↗</a>
    <a href="https://appek.cz/checkout.html" target="_blank" rel="noopener"><span>💳</span> Checkout ↗</a>
    <a href="https://appek.cz/" target="_blank" rel="noopener"><span>🌐</span> appek.cz ↗</a>
    <a href="https://demo.appek.cz/" target="_blank" rel="noopener"><span>🧪</span> demo ↗</a>
  </div>

  <style>
    .quick-actions {
      display: flex; gap: 8px; flex-wrap: wrap; margin-bottom: 24px;
    }
    .quick-actions a {
      display: inline-flex; align-items: center; gap: 6px;
      padding: 8px 14px; background: #fff;
      border: 1px solid #e5e5e7; border-radius: 999px;
      text-decoration: none; color: #1d1d1f;
      font-size: 13px; font-weight: 600;
      transition: all 0.15s;
    }
    .quick-actions a:hover {
      border-color: #BA7517; color: #BA7517;
      transform: translateY(-1px);
      box-shadow: 0 4px 10px rgba(186,117,23,0.1);
    }
    .quick-actions a span { font-size: 16px; }
  </style>

  <!-- HERO KPI -->
  <div class="hero-kpi">
    <div class="kpi-tile green">
      <div class="lbl">🔑 Aktivní licence</div>
      <div class="val"><?= $kpi['lic_active'] ?></div>
      <div class="sub">Celkem <?= $kpi['lic_total'] ?> · <?= $kpi['lic_expiring'] ?> vyprší ≤30 dní</div>
    </div>
    <div class="kpi-tile orange">
      <div class="lbl">🛒 Čeká na platbu</div>
      <div class="val"><?= $kpi['orders_pending'] ?></div>
      <div class="sub">Celkem <?= $kpi['orders_total'] ?> objednávek</div>
    </div>
    <div class="kpi-tile gold">
      <div class="lbl">💰 Tržby tento měsíc</div>
      <div class="val"><?= fmtKc($kpi['revenue_mtd']) ?></div>
      <div class="sub">Lifetime <?= fmtKc($kpi['revenue_total']) ?></div>
    </div>
    <div class="kpi-tile blue">
      <div class="lbl">🧪 Demo dnes</div>
      <div class="val"><?= $kpi['demo_today'] ?></div>
      <div class="sub">Celkem <?= $kpi['demo_total'] ?> přístupů</div>
    </div>
    <?php if ($kpi['pirate_open'] > 0): ?>
    <a href="pirate.php?filter=open" class="kpi-tile" style="text-decoration:none;color:inherit;background:linear-gradient(135deg,#FEE2E2,#FECACA);border-color:#FCA5A5">
      <div class="lbl" style="color:#991B1B">🏴‍☠️ Pirate (otevřené)</div>
      <div class="val" style="color:#DC2626"><?= $kpi['pirate_open'] ?></div>
      <div class="sub" style="color:#991B1B">Celkem <?= $kpi['pirate_total'] ?> · 24h: <?= $kpi['pirate_24h'] ?></div>
    </a>
    <?php endif; ?>
  </div>

  <!-- ═══ SEKCE 1: PRODUKT ═══ -->
  <div class="section-h">🌐 Produkt — appek.cz</div>
  <div class="card-grid">

    <!-- WEB EDITOR -->
    <div class="hub-card web">
      <a href="sales-cms.php" class="card-link" aria-label="Otevřít editor webu"></a>
      <div class="head">
        <span class="ico icon-web">✏️</span>
        <div>
          <div class="name">Editor webu</div>
          <div class="url">appek.cz — sales landing page</div>
        </div>
      </div>
      <div class="desc">Editace obsahu appek.cz ve 3 jazycích (CS/EN/ES). Auto-backup před uložením.</div>
      <div class="row-stats">
        <div><a href="https://appek.cz/" target="_blank" rel="noopener" style="color:#0058b8;text-decoration:none;font-weight:600">🌐 Otevřít web</a></div>
      </div>
      <div class="actions">
        <a href="sales-cms.php" class="primary">✏️ Upravit obsah</a>
        <a href="https://appek.cz/" target="_blank" rel="noopener">👁️ Preview</a>
      </div>
    </div>

    <!-- ESHOP / OBJEDNÁVKY -->
    <div class="hub-card shop">
      <a href="shop.php" class="card-link" aria-label="Otevřít objednávky"></a>
      <div class="head">
        <span class="ico icon-shop">🛒</span>
        <div>
          <div class="name">Eshop &amp; objednávky</div>
          <div class="url">appek.cz/checkout.html</div>
        </div>
      </div>
      <div class="desc">Checkout flow, příjem objednávek, generování licencí po platbě.</div>
      <div class="row-stats">
        <div><strong><?= $kpi['orders_pending'] ?></strong>čeká</div>
        <div><strong style="color:#34c759"><?= $kpi['orders_paid'] ?></strong>zaplaceno</div>
        <div><strong style="color:#BA7517"><?= fmtKc($kpi['revenue_mtd']) ?></strong> MTD</div>
      </div>
      <div class="actions">
        <a href="shop.php" class="primary">📋 Objednávky</a>
        <a href="https://appek.cz/checkout.html" target="_blank" rel="noopener">🛒 Checkout</a>
      </div>
    </div>

    <!-- BALÍČKY -->
    <div class="hub-card pkg">
      <a href="packages.php" class="card-link" aria-label="Spravovat balíčky"></a>
      <div class="head">
        <span class="ico icon-pkg">🎁</span>
        <div>
          <div class="name">Balíčky (katalog)</div>
          <div class="url">Produkty nabízené v eshopu</div>
        </div>
      </div>
      <div class="desc">Definice balíčků: ceny, popisy, bit_pos pro license mask, multi-lang.</div>
      <div class="row-stats">
        <div><strong><?= $kpi['packages_total'] ?></strong> balíčků</div>
        <div><strong style="color:#34c759"><?= $kpi['packages_active'] ?></strong> aktivních</div>
      </div>
      <div class="actions">
        <a href="packages.php" class="primary">🎁 Spravovat</a>
        <a href="packages.php?new=1">➕ Nový</a>
      </div>
    </div>

    <!-- DEMO — marketing showcase -->
    <div class="hub-card demo">
      <a href="demo-log.php" class="card-link" aria-label="Otevřít demo log"></a>
      <div class="head">
        <span class="ico icon-pkg">🧪</span>
        <div>
          <div class="name">demo.appek.cz</div>
          <div class="url">Veřejné marketing demo · reset 1×/h</div>
        </div>
      </div>
      <div class="desc">Sandbox pro zájemce ze sales stránky. Auto-login do ukázkového adminu.</div>
      <div class="row-stats">
        <div><strong><?= $kpi['demo_total'] ?></strong>celkem</div>
        <div><strong style="color:#34c759"><?= $kpi['demo_today'] ?></strong>dnes</div>
      </div>
      <div class="actions">
        <a href="https://demo.appek.cz/" target="_blank">🌐 Otevřít</a>
        <a href="demo-log.php" class="primary">📊 Log</a>
      </div>
    </div>

  </div>

  <!-- ═══ SEKCE 2: ZÁKAZNÍCI ═══ -->
  <div class="section-h">👥 Zákazníci — vydané instalace</div>
  <div class="card-grid">

    <!-- LICENCE -->
    <div class="hub-card lic">
      <a href="licenses.php" class="card-link" aria-label="Spravovat licence"></a>
      <div class="head">
        <span class="ico icon-lic">🔑</span>
        <div>
          <div class="name">Vydané licence</div>
          <div class="url">HMAC SHA-256 klíče</div>
        </div>
      </div>
      <div class="desc">Aktivní licence, generování manuálně nebo auto z objednávky, revokace.</div>
      <div class="row-stats">
        <div><strong><?= $kpi['lic_total'] ?></strong> celkem</div>
        <div><strong style="color:#34c759"><?= $kpi['lic_active'] ?></strong> aktivních</div>
        <div><strong style="color:#ff9500"><?= $kpi['lic_expiring'] ?></strong> ≤30d</div>
      </div>
      <div class="actions">
        <a href="licenses.php" class="primary">🔑 Spravovat</a>
        <a href="licenses.php" onclick="setTimeout(()=>document.querySelector('.btn-master.primary')?.click(),300)">➕ Vydat</a>
      </div>
    </div>

    <!-- AKTUALIZACE -->
    <div class="hub-card upd">
      <a href="updates.php" class="card-link" aria-label="Spravovat aktualizace"></a>
      <div class="head">
        <span class="ico icon-upd">🔄</span>
        <div>
          <div class="name">Aktualizace</div>
          <div class="url">OTA push do zákaznických instalací</div>
        </div>
      </div>
      <div class="desc">Build update bundle z lokálu/test domény → upload sem → zákazníci stáhnou.</div>
      <div class="row-stats">
        <div><strong><?= $kpi['updates_total'] ?></strong> verzí</div>
        <?php if ($kpi['latest_version']): ?>
          <div>nejnovější <strong><?= htmlspecialchars($kpi['latest_version']) ?></strong></div>
        <?php else: ?>
          <div>žádná publikovaná</div>
        <?php endif; ?>
      </div>
      <div class="actions">
        <a href="updates.php" class="primary">🔄 Spravovat</a>
        <a href="updates.php?upload=1">⬆️ Upload</a>
      </div>
    </div>

    <!-- PŘÍSTUPY -->
    <div class="hub-card acc">
      <a href="access.php" class="card-link" aria-label="Spravovat přístupy"></a>
      <div class="head">
        <span class="ico icon-acc">👥</span>
        <div>
          <div class="name">Přístupy</div>
          <div class="url">Účty napříč všemi instalacemi</div>
        </div>
      </div>
      <div class="desc">Vendor admini, demo log, audit. Pro customer admin/B2B účty: spravuje si je každý zákazník sám.</div>
      <div class="row-stats">
        <div><strong><?= $kpi['vendor_users'] ?></strong>vendor admini</div>
        <div><strong><?= $kpi['demo_today'] ?></strong>demo dnes</div>
      </div>
      <div class="actions">
        <a href="access.php" class="primary">👥 Spravovat</a>
        <a href="access.php?tab=failed">⚠️ Failed</a>
      </div>
    </div>

    <!-- PIRATE INSTALLS -->
    <div class="hub-card" style="border-left:3px solid #DC2626">
      <a href="pirate.php" class="card-link" aria-label="Pirate installs"></a>
      <div class="head">
        <span class="ico" style="color:#DC2626">🏴‍☠️</span>
        <div>
          <div class="name">Pirate installs</div>
          <div class="url">Instalace bez platné licence</div>
        </div>
      </div>
      <div class="desc">Detekce přes denní heartbeat z customer admin loginů. No_key / unknown / key_reuse / revoked / expired.</div>
      <div class="row-stats">
        <div><strong style="color:#DC2626"><?= $kpi['pirate_open'] ?></strong> otevřené</div>
        <div><strong><?= $kpi['pirate_total'] ?></strong> celkem</div>
        <div><strong><?= $kpi['pirate_24h'] ?></strong> 24h</div>
      </div>
      <div class="actions">
        <a href="pirate.php?filter=open" class="primary">🏴‍☠️ Pirate dashboard</a>
        <a href="pirate.php?filter=all">📋 Vše</a>
      </div>
    </div>

    <!-- CUSTOMER DISTRIBUCE -->
    <div class="hub-card upd" style="border-left-color:#208438">
      <a href="updates.php" class="card-link" aria-label="Customer distribuce"></a>
      <div class="head">
        <span class="ico" style="color:#208438">📥</span>
        <div>
          <div class="name">Customer distribuce</div>
          <div class="url">Co dostane zákazník po zaplacení</div>
        </div>
      </div>
      <div class="desc">Customer ZIP (api + admin + b2b + install.php). Build lokálně, customers stahují přes update modul.</div>
      <div class="row-stats">
        <?php
          $customerZip = realpath(__DIR__ . '/..') . '/appek-v' . ($kpi['latest_version'] ?? '*') . '.zip';
          $customerZipFiles = glob(realpath(__DIR__ . '/..') . '/appek-v*.zip');
          $latestCustomerZip = $customerZipFiles ? end($customerZipFiles) : null;
        ?>
        <?php if ($latestCustomerZip): ?>
          <div><strong><?= htmlspecialchars(basename($latestCustomerZip)) ?></strong></div>
          <div><strong><?= number_format(filesize($latestCustomerZip) / 1024 / 1024, 1, ',', ' ') ?> MB</strong>velikost</div>
        <?php else: ?>
          <div style="color:#86868b">Žádný customer ZIP zatím</div>
        <?php endif; ?>
      </div>
      <div class="actions">
        <a href="updates.php" class="primary">🔄 Spravovat updates</a>
        <a href="https://github.com/appek/" target="_blank">📖 Docs</a>
      </div>
    </div>

  </div>

  <!-- INFO FOOTER -->
  <div class="panel-master" style="margin-top:30px;background:#fafafa;border:1px dashed #d2d2d7">
    <h2 style="font-size:13px;color:#6e6e73">💡 Jak to celé funguje (production-only)</h2>
    <ol style="font-size:13px;color:#3a3a3c;line-height:1.7;padding-left:20px;margin:0">
      <li><strong>appek.cz</strong> (sales web) — návštěvníci vidí nabídku a klikají „Začít" → checkout</li>
      <li><strong>vendor.appek.cz</strong> (tahle obrazovka) — ty tu spravuješ celý ekosystém</li>
      <li><strong>demo.appek.cz</strong> — public marketing demo s reset každou hodinu</li>
      <li><strong>Zákazníci</strong> hostují <code>admin/</code> + <code>b2b/</code> na <em>svém vlastním</em> hostingu</li>
      <li><strong>Vývoj</strong> děláš <em>lokálně</em> (na tvém Macu) → <code>./scripts/build-update.sh X.Y.Z</code></li>
      <li><strong>Updates</strong> nahraješ sem → zákazníci stáhnou přes API (license-gated)</li>
    </ol>
    <div style="margin-top:14px;padding-top:14px;border-top:1px solid #e5e5e7;font-size:12px;color:#86868b">
      ⚠️ <strong>admin.appek.cz</strong> a <strong>b2b.appek.cz</strong> už nehostíme — řeší se přes update modul.
      Pokud máš v Hostinger panelu tyto subdomény, můžeš je smazat / odpojit.
    </div>
  </div>

</main>

<?php endif; ?>
<?php vendor_render_footer(); ?>
</body>
</html>
