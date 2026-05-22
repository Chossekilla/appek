<?php
/**
 * 📲 QR LANDING — Mobil-first stránka pro hosty po naskenování QR kódu na stole.
 *
 * URL: /qr/?t=<token>
 *
 * Tady NENÍ žádný backend logic — jen SPA shell která volá /api/pos_qr.php.
 */
$token = preg_replace('/[^a-f0-9]/', '', $_GET['t'] ?? '');
if (!$token) {
    http_response_code(400);
    die('Neplatný QR kód');
}
?><!DOCTYPE html>
<html lang="cs">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1,maximum-scale=1">
<title>🍽️ Objednejte si — APPEK</title>
<link rel="manifest" href="data:application/json;base64,eyJuYW1lIjoiQVBQRUsgT2JqZWRuw6F2a2EifQ==">
<meta name="theme-color" content="#BA7517">
<style>
  *{box-sizing:border-box;margin:0;padding:0}
  html,body{height:100%;font-family:-apple-system,BlinkMacSystemFont,sans-serif;background:#FAFAF8;color:#1d1d1f}
  body{padding-bottom:80px}
  .topbar{background:linear-gradient(135deg,#BA7517,#854F0B);color:#fff;padding:18px 16px;position:sticky;top:0;z-index:10;box-shadow:0 2px 10px rgba(0,0,0,0.15)}
  .topbar h1{font-size:18px;font-weight:700;margin-bottom:2px}
  .topbar .sub{font-size:12.5px;opacity:0.9}
  .cat-tabs{display:flex;gap:6px;overflow-x:auto;padding:10px 12px;background:#fff;border-bottom:1px solid #e5e5e7;position:sticky;top:60px;z-index:9}
  .cat-tab{padding:8px 16px;border-radius:999px;background:#f5f5f7;color:#3a3a3c;font-size:13px;font-weight:600;white-space:nowrap;border:none;cursor:pointer;flex-shrink:0}
  .cat-tab.is-active{background:linear-gradient(135deg,#BA7517,#854F0B);color:#fff}
  .menu{padding:12px}
  .cat-section{margin-bottom:22px}
  .cat-section h3{font-size:14px;font-weight:700;color:#854F0B;text-transform:uppercase;letter-spacing:0.6px;margin-bottom:8px;padding:6px 4px}
  .item{background:#fff;border-radius:12px;padding:14px;margin-bottom:8px;display:flex;justify-content:space-between;align-items:center;gap:10px;box-shadow:0 1px 3px rgba(0,0,0,0.04);border:1px solid transparent}
  .item.in-cart{border-color:#BA7517;background:#FFF8E7}
  .item-info{flex:1;min-width:0}
  .item-info .name{font-size:14px;font-weight:600;line-height:1.3;margin-bottom:2px}
  .item-info .desc{font-size:12px;color:#6e6e73;line-height:1.4;max-height:36px;overflow:hidden}
  .item-info .price{font-size:14px;font-weight:700;color:#BA7517;margin-top:4px}
  .qty-ctrl{display:flex;align-items:center;gap:8px;background:#FFF8E7;border-radius:999px;padding:4px}
  .qty-ctrl button{width:32px;height:32px;border-radius:50%;border:none;background:#fff;font-weight:700;font-size:18px;color:#BA7517;cursor:pointer;display:flex;align-items:center;justify-content:center}
  .qty-ctrl span{min-width:24px;text-align:center;font-weight:700}
  .add-btn{padding:8px 14px;background:linear-gradient(135deg,#16a34a,#15803d);color:#fff;border:none;border-radius:8px;font-weight:700;font-size:13px;cursor:pointer}

  .cart-bar{position:fixed;bottom:0;left:0;right:0;background:#fff;border-top:1.5px solid #e5e5e7;padding:12px 16px;display:flex;justify-content:space-between;align-items:center;gap:10px;z-index:20;box-shadow:0 -4px 16px rgba(0,0,0,0.08)}
  .cart-bar .cart-info .count{font-size:11px;color:#6e6e73;text-transform:uppercase;font-weight:600;letter-spacing:0.5px}
  .cart-bar .cart-info .sum{font-size:18px;font-weight:800;color:#1d1d1f}
  .cart-bar button{padding:12px 24px;border-radius:10px;background:linear-gradient(135deg,#BA7517,#854F0B);color:#fff;border:none;font-weight:700;font-size:14px;cursor:pointer}
  .cart-bar button:disabled{opacity:0.5;cursor:not-allowed}
  .call-waiter{position:fixed;top:78px;right:12px;background:#fff;border:1.5px solid #BA7517;color:#854F0B;padding:8px 14px;border-radius:999px;font-size:13px;font-weight:600;cursor:pointer;box-shadow:0 2px 8px rgba(0,0,0,0.1);z-index:9}

  .modal-bg{position:fixed;inset:0;background:rgba(0,0,0,0.5);display:none;align-items:flex-end;z-index:30}
  .modal-bg.show{display:flex}
  .modal{background:#fff;border-radius:18px 18px 0 0;padding:18px;width:100%;max-height:90vh;overflow-y:auto}
  .modal h2{font-size:18px;margin-bottom:14px}
  .modal label{display:block;margin-bottom:10px}
  .modal label span{display:block;font-size:12px;font-weight:600;margin-bottom:4px;color:#3a3a3c}
  .modal input{width:100%;padding:10px 12px;border:1px solid #d2d2d7;border-radius:9px;font-size:14px;font-family:inherit}
  .modal .cart-list{margin:14px 0;padding:10px;background:#FAFAF8;border-radius:8px;max-height:200px;overflow-y:auto}
  .modal .cart-item{display:flex;justify-content:space-between;font-size:13px;padding:4px 0;gap:8px}
  .modal .actions{display:flex;gap:8px;margin-top:14px}
  .modal .actions button{flex:1;padding:14px;border-radius:10px;font-weight:700;font-size:14px;cursor:pointer;border:none}
  .modal .actions .cancel{background:#f5f5f7;color:#3a3a3c}
  .modal .actions .submit{background:linear-gradient(135deg,#16a34a,#15803d);color:#fff}

  .empty{text-align:center;padding:60px 20px;color:#6e6e73}
  .toast{position:fixed;top:80px;left:50%;transform:translateX(-50%);background:#1d1d1f;color:#fff;padding:12px 20px;border-radius:10px;font-size:13px;font-weight:600;z-index:50;box-shadow:0 4px 16px rgba(0,0,0,0.3);opacity:0;transition:opacity 0.2s;pointer-events:none}
  .toast.show{opacity:1}
</style>
</head>
<body>

<div id="topbar" class="topbar">
  <h1>🍽️ <span id="stul-nazev">Načítám…</span></h1>
  <div class="sub">Objednejte si přímo z mobilu</div>
</div>

<button class="call-waiter" onclick="callWaiter()">🔔 Zavolat obsluhu</button>

<div id="cat-tabs" class="cat-tabs"></div>
<div id="menu" class="menu"><div class="empty">⏳ Načítám menu…</div></div>

<div class="cart-bar">
  <div class="cart-info">
    <div class="count"><span id="cart-count">0</span> položek</div>
    <div class="sum" id="cart-sum">0 Kč</div>
  </div>
  <button id="checkout-btn" onclick="openCheckout()" disabled>Objednat ➜</button>
</div>

<div id="checkout-modal" class="modal-bg" onclick="closeCheckout(event)">
  <div class="modal" onclick="event.stopPropagation()">
    <h2>🧾 Potvrdit objednávku</h2>
    <label>
      <span>Vaše jméno (volitelné)</span>
      <input type="text" id="host-jmeno" placeholder="Jan Novák">
    </label>
    <label>
      <span>Telefon (pokud chcete být informováni o stavu)</span>
      <input type="tel" id="host-telefon" placeholder="+420 777 123 456">
    </label>
    <div class="cart-list" id="cart-list"></div>
    <div style="display:flex;justify-content:space-between;font-weight:700;font-size:16px;padding:8px 0;border-top:1.5px solid #e5e5e7;margin-top:6px">
      <span>Celkem</span>
      <span id="cart-modal-sum">0 Kč</span>
    </div>
    <div style="font-size:12px;color:#6e6e73;line-height:1.5;margin-top:8px">
      Po odeslání obsluha objednávku během chvíle potvrdí a přinese.
    </div>
    <div class="actions">
      <button class="cancel" onclick="closeCheckout()">Zpět</button>
      <button class="submit" onclick="submitOrder()">✅ Odeslat objednávku</button>
    </div>
  </div>
</div>

<div id="toast" class="toast"></div>

<script>
const TOKEN = '<?= htmlspecialchars($token) ?>';
const API = '../api/pos_qr.php?token=' + TOKEN;
let MENU = null;
let CART = {}; // { vyrobek_id: { nazev, cena, mnozstvi } }

async function loadMenu() {
  try {
    const r = await fetch(API + '&action=menu');
    if (!r.ok) throw new Error('HTTP ' + r.status);
    MENU = await r.json();
    if (MENU.error) throw new Error(MENU.error);
    document.getElementById('stul-nazev').textContent = MENU.stul.nazev;
    renderTabs();
    renderMenu();
  } catch (e) {
    document.getElementById('menu').innerHTML = `<div class="empty">❌ ${e.message}</div>`;
  }
}

function renderTabs() {
  const tabs = MENU.kategorie.map((k, i) => `
    <button class="cat-tab ${i === 0 ? 'is-active' : ''}" onclick="scrollToCategory('cat-${k.id}', this)">${escapeHtml(k.nazev)}</button>
  `).join('');
  document.getElementById('cat-tabs').innerHTML = tabs || '';
}

function scrollToCategory(id, btn) {
  document.querySelectorAll('.cat-tab').forEach(t => t.classList.remove('is-active'));
  btn.classList.add('is-active');
  const el = document.getElementById(id);
  if (el) el.scrollIntoView({ behavior: 'smooth', block: 'start' });
}

function renderMenu() {
  if (!MENU.kategorie || MENU.kategorie.length === 0) {
    document.getElementById('menu').innerHTML = '<div class="empty">Menu zatím není k dispozici.</div>';
    return;
  }
  document.getElementById('menu').innerHTML = MENU.kategorie.map(k => `
    <div class="cat-section" id="cat-${k.id}">
      <h3 style="color:${k.barva}">${escapeHtml(k.nazev)}</h3>
      ${k.items.map(it => renderItem(it)).join('')}
    </div>
  `).join('');
}

function renderItem(it) {
  const inCart = CART[it.id];
  return `
    <div class="item ${inCart ? 'in-cart' : ''}" id="item-${it.id}">
      <div class="item-info">
        <div class="name">${escapeHtml(it.nazev)}</div>
        ${it.popis ? `<div class="desc">${escapeHtml(it.popis)}</div>` : ''}
        <div class="price">${formatPrice(it.cena)}</div>
      </div>
      ${inCart ? `
        <div class="qty-ctrl">
          <button onclick="changeQty(${it.id}, -1)">−</button>
          <span>${inCart.mnozstvi}</span>
          <button onclick="changeQty(${it.id}, +1)">+</button>
        </div>
      ` : `
        <button class="add-btn" onclick="addToCart(${it.id})">+ Přidat</button>
      `}
    </div>
  `;
}

function addToCart(id) {
  const item = MENU.kategorie.flatMap(k => k.items).find(it => it.id === id);
  if (!item) return;
  if (!CART[id]) CART[id] = { nazev: item.nazev, cena: item.cena, mnozstvi: 1 };
  else CART[id].mnozstvi++;
  updateUI();
  vibrate();
}

function changeQty(id, delta) {
  if (!CART[id]) return;
  CART[id].mnozstvi += delta;
  if (CART[id].mnozstvi <= 0) delete CART[id];
  updateUI();
}

function updateUI() {
  // Re-render only items affected (simple: re-render all)
  renderMenu();
  const count = Object.values(CART).reduce((s, c) => s + c.mnozstvi, 0);
  const sum = Object.values(CART).reduce((s, c) => s + c.mnozstvi * c.cena, 0);
  document.getElementById('cart-count').textContent = count;
  document.getElementById('cart-sum').textContent = formatPrice(sum);
  document.getElementById('checkout-btn').disabled = count === 0;
}

function openCheckout() {
  if (Object.keys(CART).length === 0) return;
  const list = Object.entries(CART).map(([id, c]) => `
    <div class="cart-item">
      <span>${c.mnozstvi}× ${escapeHtml(c.nazev)}</span>
      <strong>${formatPrice(c.mnozstvi * c.cena)}</strong>
    </div>
  `).join('');
  document.getElementById('cart-list').innerHTML = list;
  const sum = Object.values(CART).reduce((s, c) => s + c.mnozstvi * c.cena, 0);
  document.getElementById('cart-modal-sum').textContent = formatPrice(sum);
  document.getElementById('checkout-modal').classList.add('show');
}

function closeCheckout(e) {
  if (e && e.target !== e.currentTarget) return;
  document.getElementById('checkout-modal').classList.remove('show');
}

async function submitOrder() {
  const items = Object.entries(CART).map(([id, c]) => ({
    vyrobek_id: parseInt(id),
    nazev: c.nazev,
    cena: c.cena,
    mnozstvi: c.mnozstvi,
  }));
  const payload = {
    items,
    host_jmeno: document.getElementById('host-jmeno').value.trim(),
    host_telefon: document.getElementById('host-telefon').value.trim(),
  };
  try {
    const r = await fetch(API + '&action=order', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify(payload),
    });
    const j = await r.json();
    if (j.error) throw new Error(j.error);
    closeCheckout();
    CART = {};
    updateUI();
    showToast('✅ ' + (j.message || 'Objednávka odeslána!'));
  } catch (e) {
    showToast('❌ ' + e.message);
  }
}

async function callWaiter() {
  if (!confirm('Zavolat obsluhu ke stolu?')) return;
  try {
    const r = await fetch(API + '&action=call_waiter', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: '{}',
    });
    const j = await r.json();
    if (j.error) throw new Error(j.error);
    showToast('🔔 ' + (j.message || 'Obsluha přijde.'));
  } catch (e) {
    showToast('❌ ' + e.message);
  }
}

function formatPrice(p) { return p.toFixed(2).replace('.', ',').replace(',00', '') + ' Kč'; }
function escapeHtml(s) { return String(s || '').replace(/[<>&"]/g, c => ({'<':'&lt;','>':'&gt;','&':'&amp;','"':'&quot;'}[c])); }

let toastTimer = null;
function showToast(msg) {
  const t = document.getElementById('toast');
  t.textContent = msg;
  t.classList.add('show');
  if (toastTimer) clearTimeout(toastTimer);
  toastTimer = setTimeout(() => t.classList.remove('show'), 3000);
}

function vibrate() { try { navigator.vibrate?.(10); } catch (e) {} }

loadMenu();
</script>
</body>
</html>
