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
<meta name="mobile-web-app-capable" content="yes">
<meta name="robots" content="noindex, nofollow">
<title>APPEK POS — Kasa</title>
<link rel="manifest" href="manifest.php">
<link rel="apple-touch-icon" sizes="180x180" href="/uploads/logo/pwa/icon-apple.png">
<link rel="icon" type="image/png" sizes="192x192" href="icons/icon-192.png">
<link rel="icon" type="image/svg+xml" href="/admin/icons/icon-192.svg">
<link rel="stylesheet" href="pos.css?v=<?= htmlspecialchars($appVersion) ?>">
<!-- 🆕 v3.0.253 — KRITICKÉ CSS tabů přímo v HTML (pos/index.php je no-store = vždy čerstvé,
     vyhrává nad CDN-zacachovanou pos.css). border-radius 5px + 1.5px obrys, sjednoceno s admin/pos.php. -->
<style>
  .pos-header-center .pos-tab-h {
    border-radius: 5px !important;
    border: 1.5px solid var(--pos-border, #E2E2E7) !important;
  }
  .pos-header-center .pos-tab-h.active {
    border-color: var(--pos-primary, #BA7517) !important;
  }
</style>
<?php
  // 📊 v3.0.286 — Google Analytics pro POS (vlastní measurement ID, odděleně od B2B)
  // 🍪 v3.0.399 — GDPR/ePrivacy: gtag se načte AŽ po souhlasu. Stejný localStorage klíč
  //   appek_cookie_consent_v1 jako B2B/admin → jedna volba na prohlížeč platí všude.
  try {
      $gaPos = trim((string) (function_exists('nastaveni') ? (nastaveni()['ga_measurement_id_pos'] ?? '') : ''));
      if ($gaPos !== '' && preg_match('/^(G|AW|UA)-[A-Z0-9-]{4,}$/i', $gaPos)) {
          $gaPosEsc = htmlspecialchars($gaPos, ENT_QUOTES);
          echo "<script>window._ccGaId='{$gaPosEsc}';(function(){var K='appek_cookie_consent_v1';"
             . "function cg(){try{var r=JSON.parse(localStorage.getItem(K)||'null');return(r&&typeof r.analytics==='boolean')?r:null}catch(e){return null}}"
             . "function cs(a){try{localStorage.setItem(K,JSON.stringify({v:1,analytics:!!a,ts:new Date().toISOString()}))}catch(e){}}"
             . "function ld(){if(window._gaLoaded)return;window._gaLoaded=true;var s=document.createElement('script');s.async=true;s.src='https://www.googletagmanager.com/gtag/js?id='+encodeURIComponent(window._ccGaId);document.head.appendChild(s);window.dataLayer=window.dataLayer||[];window.gtag=window.gtag||function(){dataLayer.push(arguments)};gtag('js',new Date());gtag('config',window._ccGaId,{anonymize_ip:true})}"
             . "window._ccApply=function(a){cs(a);if(a)ld();var e=document.getElementById('cc-banner');if(e)e.remove()};"
             . "function bn(){if(document.getElementById('cc-banner'))return;var d=document.createElement('div');d.id='cc-banner';d.setAttribute('role','dialog');d.setAttribute('aria-label','Souhlas s cookies');"
             . "d.style.cssText='position:fixed;right:14px;bottom:14px;z-index:99999;background:#fff;border:1px solid #e2e2e2;border-radius:12px;box-shadow:0 6px 22px rgba(0,0,0,0.16);padding:12px 13px;width:290px;max-width:calc(100vw - 28px);font-size:12.5px;line-height:1.45;color:#222';"
             . "d.innerHTML='<div style=\\\"font-weight:700;margin-bottom:4px\\\">🍪 Cookies</div><div style=\\\"color:#555;margin-bottom:10px\\\">Nezbytné pro provoz, se souhlasem i <strong>analytické</strong> (Google Analytics, anonymizovaná IP).</div><div style=\\\"display:flex;gap:6px\\\"><button onclick=\\\"_ccApply(true)\\\" style=\\\"flex:1;padding:8px 4px;border:none;border-radius:8px;background:#166534;color:#fff;font-weight:700;cursor:pointer;font-size:12.5px\\\">Přijmout</button><button onclick=\\\"_ccApply(false)\\\" style=\\\"flex:1;padding:8px 4px;border:none;border-radius:8px;background:#374151;color:#fff;font-weight:700;cursor:pointer;font-size:12.5px\\\">Odmítnout</button></div>';document.body.appendChild(d)}"
             . "var c=cg();if(c){if(c.analytics)ld()}else{if(document.body)bn();else document.addEventListener('DOMContentLoaded',bn)}})();</script>\n";
      }
  } catch (Throwable $e) { /* bez GA */ }
?>
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
    <span class="pos-tab-h active" data-tab="products"><span class="pth-ico">📦</span><span class="pth-txt">Produkty</span></span>
    <span class="pos-tab-h" data-tab="orders"><span class="pth-ico">📜</span><span class="pth-txt">Účtenky</span></span>
    <span class="pos-tab-h" data-tab="reports"><span class="pth-ico">📊</span><span class="pth-txt">Statistiky</span></span>
  </div>
  <div class="pos-header-right">
    <span class="pos-time" id="pos-clock">--:--</span>
    <!-- 🆕 v3.0.191 — zoom kasy +/- (přehlednost na všech zařízeních) -->
    <div class="pos-zoom-ctrl" role="group" aria-label="Velikost kasy">
      <button class="pos-zoom-btn" onclick="posZoom(-1)" title="Zmenšit" aria-label="Zmenšit">−</button>
      <span class="pos-zoom-val" id="pos-zoom-val">100%</span>
      <button class="pos-zoom-btn" onclick="posZoom(1)" title="Zvětšit" aria-label="Zvětšit">+</button>
    </div>
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

<!-- 🆕 v3.0.187 — ALT VIEW: Účtenky / Statistiky (přepínané v hlavičce; dřív byly taby mrtvé) -->
<section class="pos-altview" id="pos-altview" hidden></section>

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

<!-- 🌍 v3.0.420 — POS i18n: zdědí jazyk z adminu (appek_lang) + přeloží přes sdílený slovník -->
<script src="../admin/pos-i18n.js?v=<?= htmlspecialchars($appVersion) ?>"></script>
<script src="pos.js?v=<?= htmlspecialchars($appVersion) ?>"></script>
<script>
  /* 🆕 v3.0.362 — registrace SW kvůli PWA installability (Android Chrome). Bez interceptu = žádný stale-POS. */
  if ('serviceWorker' in navigator) {
    window.addEventListener('load', function () {
      navigator.serviceWorker.register('sw.php').catch(function () {}); /* 🐛 v3.0.402 — .php = mimo 7denní hcdn CDN cache */
    });
  }
</script>
</body>
</html>
