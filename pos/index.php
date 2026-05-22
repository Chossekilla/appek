<?php
/**
 * 🧾 APPEK POS — Touch-grid kasa (samostatná aplikace)
 *
 * URL: /pos/ → vyžaduje admin přihlášení (sdílí session s /admin)
 */

// 🛡️ Bulletproof error handler — pokud cokoliv selže, ukázat diagnostiku místo 404/blank
ini_set('display_errors', '0');
error_reporting(E_ALL);
set_error_handler(function($errno, $errstr, $errfile, $errline) {
    if (!(error_reporting() & $errno)) return false;
    pos_show_error('PHP Error', "$errstr\nv souboru: $errfile, řádek $errline");
});
set_exception_handler(function($e) {
    pos_show_error('Uncaught Exception', $e->getMessage() . "\nv souboru: " . $e->getFile() . ":" . $e->getLine());
});
function pos_show_error($title, $detail) {
    http_response_code(500);
    if (!headers_sent()) header('Content-Type: text/html; charset=utf-8');
    echo '<!DOCTYPE html><html lang="cs"><head><meta charset="utf-8"><title>POS — chyba</title>'
       . '<style>body{font-family:-apple-system,sans-serif;max-width:720px;margin:60px auto;padding:24px;color:#1a1d24}'
       . 'h1{font-size:22px;color:#dc2626;margin:0 0 14px}pre{background:#f3f4f6;padding:14px;border-radius:8px;'
       . 'overflow-x:auto;font-size:12px;color:#374151;white-space:pre-wrap;word-break:break-word}'
       . '.tip{background:#fef3c7;border-left:3px solid #f59e0b;padding:12px 14px;border-radius:6px;margin:14px 0;font-size:13px}'
       . 'a{color:#4F46E5}</style></head><body>'
       . '<h1>🧾 ' . htmlspecialchars($title) . '</h1>'
       . '<pre>' . htmlspecialchars($detail) . '</pre>'
       . '<div class="tip">💡 <strong>Tip:</strong> Zkontroluj že <code>/api/config.local.php</code> existuje a obsahuje DB credentials. '
       . 'Nebo zkus <a href="/admin/">← zpět do adminu</a>.</div>'
       . '</body></html>';
    exit;
}

// Načti core knihovny (try/catch pro robustnost)
try {
    require_once __DIR__ . '/../api/config.php';
    require_once __DIR__ . '/../api/_admin_auth.php';
    require_once __DIR__ . '/../api/_packages_lib.php';
} catch (Throwable $e) {
    pos_show_error('Chyba načtení knihoven', $e->getMessage() . "\nv souboru: " . $e->getFile() . ":" . $e->getLine());
}

// Ověř že všechny potřebné funkce existují
foreach (['session_secure_start', 'csrf_token', 'package_enabled'] as $fn) {
    if (!function_exists($fn)) {
        pos_show_error('Chybí funkce: ' . $fn,
            "Aplikace nebyla správně nainstalována, nebo verze knihoven nesedí.\n"
          . "Zkontroluj že /api/ obsahuje: config.php, _admin_auth.php, _packages_lib.php, _csrf.php");
    }
}

// 🔒 Auth — pokud není přihlášen, redirect na admin login
session_secure_start();
if (empty($_SESSION['admin_id'])) {
    if (!headers_sent()) {
        header('Location: /admin/?return=' . urlencode('/pos/'));
    }
    exit;
}

// 🎁 Balíček check — POS Kasa = součást Restaurace balíčku
if (!package_enabled('restaurace')) {
    http_response_code(403);
    ?><!DOCTYPE html>
    <html lang="cs"><head><meta charset="utf-8"><title>POS — nedostupné</title>
    <style>body{font-family:-apple-system,sans-serif;max-width:520px;margin:80px auto;padding:24px;text-align:center}
    h1{font-size:24px}a{color:#BA7517;text-decoration:none;font-weight:600}</style>
    </head><body>
    <h1>🧾 POS Kasa není v tvém balíčku</h1>
    <p>POS Kasa je součást balíčku <strong>🍕 Restaurace / Pizzerie</strong>.</p>
    <p><a href="/admin/#pkg_restaurace">← Aktivovat balíček v administraci</a></p>
    </body></html><?php
    exit;
}

$adminJmeno = $_SESSION['admin_jmeno'] ?? '';
$adminRole  = $_SESSION['admin_role']  ?? 'admin';
$appVersion = defined('APP_VERSION') ? APP_VERSION : '0.0.0';
$csrfToken  = csrf_token();
?><!DOCTYPE html>
<html lang="cs">
<head>
<meta charset="UTF-8">
<meta http-equiv="Cache-Control" content="no-cache, no-store, must-revalidate">
<meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover, user-scalable=no">
<meta name="theme-color" content="#1a1d24">
<meta name="apple-mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
<meta name="apple-mobile-web-app-title" content="APPEK POS">
<meta name="robots" content="noindex, nofollow">
<title>APPEK POS — Kasa</title>
<link rel="icon" type="image/svg+xml" href="/admin/icons/icon-192.svg">
<link rel="apple-touch-icon" href="/admin/icons/icon-apple.svg">
<link rel="stylesheet" href="pos.css?v=<?= htmlspecialchars($appVersion) ?>">
<script>
  // Globální config — předáno z PHP do JS
  window.POS_CONFIG = {
    version:    <?= json_encode($appVersion) ?>,
    adminName:  <?= json_encode($adminJmeno) ?>,
    adminRole:  <?= json_encode($adminRole) ?>,
    csrfToken:  <?= json_encode($csrfToken) ?>,
    apiBase:    '/api/',
    locale:     'cs-CZ',
    currency:   'Kč',
  };
</script>
</head>
<body class="pos-app">

<!-- HEADER -->
<header class="pos-header">
  <div class="pos-header-left">
    <button class="pos-back-btn" onclick="window.close()" title="Zavřít POS">←</button>
    <div class="pos-brand">
      <span class="pos-brand-icon">🧾</span>
      <span class="pos-brand-name">APPEK POS</span>
    </div>
    <div class="pos-loc" id="pos-location">📍 Provozovna</div>
  </div>
  <div class="pos-header-center">
    <span class="pos-tab-h active" data-tab="products">📦 Produkty</span>
    <span class="pos-tab-h" data-tab="orders">📜 Účtenky</span>
    <span class="pos-tab-h" data-tab="reports">📊 Statistiky</span>
  </div>
  <div class="pos-header-right">
    <span class="pos-time" id="pos-clock">--:--</span>
    <span class="pos-user">
      <span class="pos-user-avatar"><?= htmlspecialchars(mb_substr($adminJmeno ?: 'A', 0, 1)) ?></span>
      <span class="pos-user-name"><?= htmlspecialchars($adminJmeno ?: 'Admin') ?></span>
    </span>
    <button class="pos-fs-btn" onclick="posFullscreen()" title="Celá obrazovka">⛶</button>
  </div>
</header>

<!-- MAIN — vlevo katalog, vpravo košík -->
<main class="pos-main">

  <!-- LEVÝ PANEL: produktový grid -->
  <section class="pos-products">

    <div class="pos-cats-bar" id="pos-cats">
      <div class="pos-loading">⏳ Načítám katalog…</div>
    </div>

    <div class="pos-search-bar">
      <span class="pos-search-ic">🔍</span>
      <input id="pos-search" type="text" placeholder="Hledat produkt (název / EAN / kód)…"
             autocomplete="off" autocorrect="off">
      <button class="pos-search-x" onclick="POS.search('')" title="Vymazat">✕</button>
    </div>

    <div class="pos-filters" id="pos-filters"></div>

    <div class="pos-grid" id="pos-grid"></div>

  </section>

  <!-- PRAVÝ PANEL: košík + platba -->
  <aside class="pos-cart" id="pos-cart-panel">

    <div class="pos-cart-head">
      <button class="pos-new-btn" onclick="POS.newOrder()">🆕 Nová objednávka</button>
      <button class="pos-more-btn" onclick="POS.menuToggle(event)" title="Možnosti">⋮</button>
    </div>

    <div class="pos-cust-row">
      <div class="pos-cust-info">
        <div class="pos-cust-name" id="pos-cust-name">Neznámý zákazník</div>
        <div class="pos-cust-sub"  id="pos-cust-sub"></div>
      </div>
      <button class="pos-cust-btn" onclick="POS.pickCustomer()">＋ Zákazník</button>
    </div>

    <div class="pos-cart-list" id="pos-cart-list">
      <div class="pos-cart-empty">
        <div class="pos-cart-empty-ic">🛒</div>
        <div class="pos-cart-empty-title">Košík je prázdný</div>
        <div class="pos-cart-empty-sub">Klikni na produkt vlevo</div>
      </div>
    </div>

    <div class="pos-summary">
      <div class="pos-sum-row"><span>Mezisoučet</span><strong id="pos-sub-bez">0,00</strong></div>
      <div class="pos-sum-row"><span>DPH</span><strong id="pos-sub-dph">0,00</strong></div>
      <div class="pos-sum-row" id="pos-sleva-row" style="display:none">
        <span>Sleva (<span id="pos-sleva-pct">0</span>%)</span>
        <strong id="pos-sleva-abs" style="color:#e74c3c">−0,00</strong>
      </div>
      <div class="pos-sum-row" id="pos-tip-row" style="display:none">
        <span>Spropitné</span>
        <strong id="pos-tip-abs">0,00</strong>
      </div>
    </div>

    <div class="pos-payment-bar">
      <button class="pos-pay-btn is-active" data-pay="hotove" onclick="POS.setPay('hotove')">💵 Hotově</button>
      <button class="pos-pay-btn"           data-pay="karta"  onclick="POS.setPay('karta')">💳 Kartou</button>
      <button class="pos-pay-btn pos-pay-more" onclick="POS.payMenu(event)">📲 Jiné ▾</button>
    </div>

    <div class="pos-type-bar">
      <button class="pos-type-btn is-active" data-typ="sebou"      onclick="POS.setTyp('sebou')">🛍️ Sebou</button>
      <button class="pos-type-btn"           data-typ="vyzvednuti" onclick="POS.setTyp('vyzvednuti')">📦 Vyzvednutí</button>
      <button class="pos-type-btn"           data-typ="rozvoz"     onclick="POS.setTyp('rozvoz')">🛵 Rozvoz</button>
      <button class="pos-type-btn"           data-typ="na_miste"   onclick="POS.setTyp('na_miste')">🍽️ Na místě</button>
    </div>

    <div class="pos-total-bar">
      <span>CELKEM</span>
      <strong id="pos-total">0,00 Kč</strong>
    </div>

    <div class="pos-actions">
      <button class="pos-icon" onclick="POS.printReceipt()" title="Tisk účtenky">🖨️</button>
      <button class="pos-icon" onclick="POS.saveDraft()"    title="Uložit rozpracované">💾</button>
      <button class="pos-icon" onclick="POS.addNote()"      title="Poznámka">💬</button>
      <button class="pos-finish" onclick="POS.finish()">✓ FINISH</button>
    </div>

  </aside>

</main>

<!-- MODAL host -->
<div id="pos-modal-host" class="pos-modal-host" hidden></div>

<!-- TOAST host -->
<div id="pos-toast-host" class="pos-toast-host"></div>

<!-- FOOTER -->
<footer class="pos-footer">
  <span>APPEK POS · v<?= htmlspecialchars($appVersion) ?></span>
  <span class="pos-foot-spacer">·</span>
  <span id="pos-foot-status">🟢 Online</span>
</footer>

<script src="pos.js?v=<?= htmlspecialchars($appVersion) ?>"></script>
</body>
</html>
