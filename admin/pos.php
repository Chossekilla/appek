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

// 🆕 v2.9.270 — Auth: zobrazit PIN keypad pokud žádné session
session_secure_start();
$appVersion = defined('APP_VERSION') ? APP_VERSION : '0.0.0';

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
    <p><a href="../admin/#pkg_restaurace">← Aktivovat balíček v administraci</a></p>
    </body></html><?php
    exit;
}

// Pokud není přihlášen → render PIN keypad screen místo redirect do adminu
if (empty($_SESSION['admin_id'])) {
    ?><!DOCTYPE html>
    <html lang="cs">
    <head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover, user-scalable=no">
    <meta name="theme-color" content="#1a1d24">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
    <title>APPEK POS — Přihlášení</title>
    <link rel="icon" type="image/svg+xml" href="icons/icon-192.svg">
    <link rel="stylesheet" href="pos.css?v=<?= htmlspecialchars($appVersion) ?>">
    <script>
      window.POS_LOGIN_CONFIG = {
        version: <?= json_encode($appVersion) ?>,
        apiBase: '../api/',
        adminBase: '../admin/',
        returnUrl: <?= json_encode($_GET['return'] ?? '') ?>,
      };
    </script>
    </head>
    <body class="pos-login-body">
      <div class="pos-login-wrap">
        <div class="pos-login-card">
          <div class="pos-login-head">
            <div class="pos-login-brand">
              <span class="pos-login-ic">🧾</span>
              <h1>APPEK POS</h1>
            </div>
            <div class="pos-login-sub">Vyberte sebe a zadejte PIN</div>
          </div>

          <div class="pos-login-users" id="pos-login-users">
            <div class="pos-login-loading">⏳ Načítám uživatele…</div>
          </div>

          <div class="pos-login-pin" id="pos-login-pin" hidden>
            <div class="pos-login-pin-user" id="pos-login-pin-user"></div>
            <div class="pos-login-pin-dots" id="pos-login-pin-dots">
              <span class="pos-pin-dot"></span><span class="pos-pin-dot"></span>
              <span class="pos-pin-dot"></span><span class="pos-pin-dot"></span>
            </div>
            <div class="pos-login-pin-err" id="pos-login-pin-err" hidden></div>
            <div class="pos-login-keypad">
              <button class="pos-key" data-k="1">1</button>
              <button class="pos-key" data-k="2">2</button>
              <button class="pos-key" data-k="3">3</button>
              <button class="pos-key" data-k="4">4</button>
              <button class="pos-key" data-k="5">5</button>
              <button class="pos-key" data-k="6">6</button>
              <button class="pos-key" data-k="7">7</button>
              <button class="pos-key" data-k="8">8</button>
              <button class="pos-key" data-k="9">9</button>
              <button class="pos-key pos-key-cancel" data-k="cancel">← Zpět</button>
              <button class="pos-key" data-k="0">0</button>
              <button class="pos-key pos-key-del" data-k="del">⌫</button>
            </div>
          </div>

          <div class="pos-login-foot">
            <a href="../admin/" class="pos-login-adminlink">🔑 Admin přihlášení (heslem)</a>
            <div class="pos-login-version">v<?= htmlspecialchars($appVersion) ?></div>
          </div>
        </div>
      </div>

      <script>
      (function(){
        const CFG = window.POS_LOGIN_CONFIG;
        const $ = (s) => document.querySelector(s);
        const usersBox = $('#pos-login-users');
        const pinBox = $('#pos-login-pin');
        const pinUserEl = $('#pos-login-pin-user');
        const dotsEl = $('#pos-login-pin-dots');
        const errEl = $('#pos-login-pin-err');
        let selectedUser = null;
        let pinBuffer = '';
        let submitting = false;

        function roleColor(role) {
          return { admin:'#8B5CF6', prodavac:'#3B82F6', vyroba:'#10B981',
                   expedice:'#F59E0B', pos:'#EF4444' }[role] || '#6B7280';
        }
        function roleLabel(role) {
          return { admin:'Admin', prodavac:'Prodavač', vyroba:'Výroba',
                   expedice:'Expedice', pos:'POS kasa' }[role] || role;
        }

        async function loadUsers() {
          try {
            const r = await fetch(CFG.apiBase + 'pos_auth.php?action=users');
            const d = await r.json();
            if (!d.ok || !d.users || !d.users.length) {
              usersBox.innerHTML = '<div class="pos-login-empty">'
                + '⚠️ Žádný uživatel nemá nastavený PIN.<br>'
                + '<a href="../admin/#nastaveni" style="color:#BA7517">Nastavit PIN v adminu →</a></div>';
              return;
            }
            usersBox.innerHTML = d.users.map(u => `
              <button class="pos-login-user-chip" data-id="${u.id}"
                      style="--chip-color:${roleColor(u.role)}">
                <span class="pos-login-user-av">${u.iniciala}</span>
                <span class="pos-login-user-body">
                  <span class="pos-login-user-nm">${u.jmeno}</span>
                  <span class="pos-login-user-rl">${roleLabel(u.role)}</span>
                </span>
              </button>
            `).join('');
            usersBox.querySelectorAll('.pos-login-user-chip').forEach(b => {
              b.addEventListener('click', () => selectUser(b.dataset.id, b.querySelector('.pos-login-user-nm').textContent));
            });
          } catch (e) {
            usersBox.innerHTML = '<div class="pos-login-empty">⚠️ Chyba: ' + e.message + '</div>';
          }
        }

        function selectUser(id, name) {
          selectedUser = { id: parseInt(id, 10), name };
          pinBuffer = '';
          updateDots();
          errEl.hidden = true;
          pinUserEl.textContent = name;
          usersBox.hidden = true;
          pinBox.hidden = false;
        }

        function updateDots() {
          // 4 dots — fill based on PIN length (4-6)
          const dots = dotsEl.querySelectorAll('.pos-pin-dot');
          dots.forEach((d, i) => d.classList.toggle('is-on', i < pinBuffer.length));
          // Vizuální feedback pro 5-6 cifer (rozšiř dots)
          if (pinBuffer.length > 4 && dots.length < pinBuffer.length) {
            for (let i = dots.length; i < pinBuffer.length; i++) {
              const e = document.createElement('span');
              e.className = 'pos-pin-dot is-on';
              dotsEl.appendChild(e);
            }
          } else if (pinBuffer.length < dots.length && dots.length > 4) {
            for (let i = dots.length - 1; i >= Math.max(4, pinBuffer.length); i--) {
              dotsEl.removeChild(dots[i]);
            }
          }
        }

        async function trySubmit() {
          if (submitting) return;
          submitting = true;
          errEl.hidden = true;
          try {
            const r = await fetch(CFG.apiBase + 'pos_auth.php?action=login', {
              method: 'POST',
              headers: { 'Content-Type': 'application/json' },
              credentials: 'include',
              body: JSON.stringify({ user_id: selectedUser.id, pin: pinBuffer }),
            });
            const d = await r.json();
            if (!r.ok || !d.ok) throw new Error(d.error || 'Přihlášení selhalo');
            // úspěch → reload pos.php (teď budeš mít session)
            window.location.href = CFG.returnUrl || 'pos.php';
          } catch (e) {
            errEl.hidden = false;
            errEl.textContent = '⚠️ ' + e.message;
            pinBuffer = '';
            updateDots();
            // shake animation
            dotsEl.classList.add('shake');
            setTimeout(() => dotsEl.classList.remove('shake'), 400);
          } finally {
            submitting = false;
          }
        }

        function onKey(k) {
          if (submitting) return;
          if (k === 'cancel') {
            selectedUser = null;
            pinBuffer = '';
            pinBox.hidden = true;
            usersBox.hidden = false;
            return;
          }
          if (k === 'del') {
            pinBuffer = pinBuffer.slice(0, -1);
            updateDots();
            return;
          }
          if (/^\d$/.test(k)) {
            if (pinBuffer.length < 6) {
              pinBuffer += k;
              updateDots();
              if (pinBuffer.length >= 4) {
                // auto-submit po 4 cifrách s 250ms delay (chance přidat 5./6.)
                setTimeout(() => {
                  if (pinBuffer.length === 4) trySubmit();
                }, 250);
              }
            }
          }
        }

        document.querySelectorAll('.pos-key').forEach(b => {
          b.addEventListener('click', () => onKey(b.dataset.k));
        });

        // HW keyboard
        document.addEventListener('keydown', (e) => {
          if (pinBox.hidden) return;
          if (e.key >= '0' && e.key <= '9') onKey(e.key);
          else if (e.key === 'Backspace') onKey('del');
          else if (e.key === 'Enter') trySubmit();
          else if (e.key === 'Escape') onKey('cancel');
        });

        loadUsers();
      })();
      </script>
    </body>
    </html><?php
    exit;
}

// 🔒 v2.9.270 — pokud uživatel je pos_only ale dostal se sem přes admin session → OK
$adminJmeno = $_SESSION['admin_jmeno'] ?? '';
$adminRole  = $_SESSION['admin_role']  ?? 'admin';
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
<!-- 🆕 v3.0.252 — KRITICKÉ CSS tabů přímo v HTML (pos.php je no-store = vždy čerstvé).
     border-radius 5px + 1.5px border (user explicitně, po 5×). Po <link> = vyhrává i nad zacachovanou pos.css. -->
<style>
  .pos-header-center .pos-tab-h {
    border-radius: 5px !important;
    border: 1.5px solid var(--pos-border, #E2E2E7) !important;
  }
  .pos-header-center .pos-tab-h.active {
    border-color: var(--pos-primary, #BA7517) !important;
  }
</style>
<script>
  // Globální config — předáno z PHP do JS
  window.POS_CONFIG = {
    version:    <?= json_encode($appVersion) ?>,
    adminName:  <?= json_encode($adminJmeno) ?>,
    adminRole:  <?= json_encode($adminRole) ?>,
    csrfToken:  <?= json_encode($csrfToken) ?>,
    apiBase:    '../api/',  // 🐛 v3.0.9 fix: relativní (předtím absolutní '/api/' = 404 na subdirectory hostingu)
    locale:     'cs-CZ',
    currency:   'Kč',
    // 🆕 v3.0.249 — počet řádků na stránku (sdíleno s admin nastavením „Dlouhé seznamy")
    pagPocet:   <?= (function () { $p = (int) (function_exists('nastaveni') ? (nastaveni()['pagination_pocet'] ?? 50) : 50); return in_array($p, [25, 50, 100, 200], true) ? $p : 50; })() ?>,
  };
</script>
</head>
<body class="pos-app">

<!-- HEADER -->
<header class="pos-header">
  <div class="pos-header-left">
    <!-- 🆕 v2.9.300 — Pokud POS otevřen v same-tab (ne nové okno), window.close() nefunguje
         → fallback na navigaci zpět do adminu. -->
    <button class="pos-back-btn" onclick="(function(){
      try {
        // 🐛 v2.9.315 — pokud nebylo otevřeno přes window.open() (jen new tab),
        // window.opener je null → close() selže silently → rovnou naviguj zpět.
        // Předtím byl const opener = window.opener; declared ale nikde used.
        if (!window.opener) { window.location.href = '../admin/'; return; }
        window.close();
        // Pokud po 120ms okno stále existuje (close failed), naviguj zpět do admina
        setTimeout(() => { if (!document.hidden) window.location.href = '../admin/'; }, 120);
      } catch (e) { window.location.href = '../admin/'; }
    })()" title="Zavřít POS / Zpět do adminu">←</button>
    <div class="pos-brand">
      <span class="pos-brand-icon">🧾</span>
      <span class="pos-brand-name">APPEK POS</span>
    </div>
    <div class="pos-loc" id="pos-location">📍 Provozovna</div>
  </div>
  <div class="pos-header-center">
    <span class="pos-tab-h active" data-tab="products">🧾 KASA</span>
    <span class="pos-tab-h" data-tab="tables">🪑 Stoly</span>
    <span class="pos-tab-h" data-tab="orders">📜 Účtenky</span>
    <span class="pos-tab-h" data-tab="reports">📊 Statistiky</span>
    <span class="pos-tab-h" data-tab="uzaverka">🧮 Uzávěrka</span>
  </div>
  <div class="pos-header-right">
    <span class="pos-time" id="pos-clock">--:--</span>
    <!-- 🆕 v3.0.193 — zoom kasy +/- (přehlednost na všech zařízeních) -->
    <div class="pos-zoom-ctrl" role="group" aria-label="Velikost kasy">
      <button class="pos-zoom-btn" onclick="posZoom(-1)" title="Zmenšit" aria-label="Zmenšit">−</button>
      <span class="pos-zoom-val" id="pos-zoom-val">100%</span>
      <button class="pos-zoom-btn" onclick="posZoom(1)" title="Zvětšit" aria-label="Zvětšit">+</button>
    </div>
    <span class="pos-user" onclick="POS.switchUser()" title="Přepnout prodavače (PIN login)" tabindex="0" onkeydown="if(event.key==='Enter'){POS.switchUser()}">
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
      <button class="pos-custom-btn" onclick="POS.openCustomItem()" title="Volná položka (zadej ručně název a cenu)">
        ➕ Volná položka
      </button>
    </div>

    <div class="pos-filters" id="pos-filters"></div>

    <div class="pos-grid" id="pos-grid"></div>

  </section>

  <!-- PRAVÝ PANEL: košík + platba -->
  <aside class="pos-cart" id="pos-cart-panel">

    <div class="pos-cart-head">
      <button class="pos-cart-close" onclick="POS.cartClose()" title="Zavřít košík" aria-label="Zavřít košík">✕</button>
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
      <!-- 🆕 v3.0.279 — poplatek platby (karta-fee) z configu vybrané metody -->
      <div class="pos-sum-row" id="pos-poplatek-row" style="display:none">
        <span id="pos-poplatek-lbl">Poplatek</span>
        <strong id="pos-poplatek-abs" style="color:#854F0B">+0,00</strong>
      </div>
    </div>

    <!-- 🆕 v2.9.205 — buttons dynamicky rendrovány z /api/payment_methods.php?context=pos -->
    <div class="pos-payment-bar" id="pos-payment-bar">
      <!-- placeholder než JS doběhne (fallback hotove + karta) -->
      <button class="pos-pay-btn is-active" data-pay="hotove" onclick="POS.setPay('hotove')">💵 Hotově</button>
      <button class="pos-pay-btn"           data-pay="karta"  onclick="POS.setPay('karta')">💳 Kartou</button>
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
      <button class="pos-icon" onclick="POS.saveDraft()"    title="Uložit aktuální košík do rozpracovaných">💾</button>
      <button class="pos-icon" onclick="POS.showDrafts()"   title="🆕 v2.9.279 — Otevřít seznam rozpracovaných košíků">📂</button>
      <button class="pos-icon" onclick="POS.addNote()"      title="Poznámka">💬</button>
      <button class="pos-finish" onclick="POS.finish()">✓ FINISH</button>
    </div>

  </aside>

</main>

<!-- 🆕 v3.0.240 — MOBILNÍ OVLÁDÁNÍ KOŠÍKU (jen ≤768px; režim 'bar'/'toggle' = volba v ⋮ menu) -->
<!-- Režim PŘEPÍNAČ: segmentové přepínání Produkty / Košík -->
<div class="pos-mswitch" id="pos-mswitch" aria-hidden="true">
  <button type="button" data-mv="products" class="is-active" onclick="POS.mobileView('products')">📦 Produkty</button>
  <button type="button" data-mv="cart" onclick="POS.mobileView('cart')">🛒 Košík <span class="pos-mswitch-badge" id="pos-mswitch-count">0</span></button>
</div>
<!-- Režim LIŠTA: spodní lišta s počtem + součtem; ťuknutím vyjede košík -->
<div class="pos-cart-bar" id="pos-cart-bar" onclick="POS.cartOpen()" role="button" tabindex="0"
     aria-label="Otevřít košík" onkeydown="if(event.key==='Enter'){POS.cartOpen()}">
  <span class="pos-cart-bar-info">🛒 <strong id="pos-bar-count">0</strong> ks</span>
  <span class="pos-cart-bar-total" id="pos-bar-total">0,00 Kč</span>
  <button type="button" class="pos-cart-bar-finish" onclick="event.stopPropagation();POS.finish()">✓ FINISH</button>
  <span class="pos-cart-bar-chev" aria-hidden="true">▲</span>
</div>
<!-- Backdrop pod vysunutým košíkem -->
<div class="pos-cart-backdrop" id="pos-cart-backdrop" onclick="POS.cartClose()" aria-hidden="true"></div>

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
