<?php
/**
 * 🆕 v3.0.1 — KDS (Kitchen Display Screen) STANDALONE
 *
 * Plně-screen displej pro kuchyňskou obrazovku. Otevírá se z adminu
 * (Restaurace → Provoz → "👨‍🍳 Kuchyňský displej").
 *
 * Co dělá:
 *   - Karty objednávek seskupené per stůl/účet
 *   - Stavy položek: objednáno → vaří se → hotovo → servírováno
 *   - Klik na položku posune do dalšího stavu
 *   - Klik na "✓ Vše hotovo" karty posune všechny položky účtu na hotovo
 *   - Auto-refresh 10 s (rychlejší než provoz — kuchyně potřebuje real-time)
 *   - Tmavý theme + velký font (čitelnost ze 3+ metrů)
 *   - Zvukový signal na novou objednávku
 *   - Highlight starých objednávek (>15 min = červené, >10 min = oranžové)
 *
 * Vyžaduje admin/prodavac/pos role (sdílí session s admin/).
 */

require_once __DIR__ . '/../api/config.php';
require_once __DIR__ . '/../api/_admin_auth.php';

session_secure_start();
if (empty($_SESSION['admin_id'])) {
    header('Location: ../admin/');
    exit;
}

$appVersion = defined('APP_VERSION') ? APP_VERSION : '0.0.0';
?><!DOCTYPE html>
<html lang="cs">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
<meta name="theme-color" content="#1a1d24">
<meta name="robots" content="noindex, nofollow">
<title>APPEK KDS — Kuchyňský displej</title>
<style>
* { box-sizing: border-box; margin: 0; padding: 0; }
html, body {
  font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', system-ui, sans-serif;
  background: #0d0f14;
  color: #fff;
  height: 100vh;
  overflow: hidden;
}
.kds-app { display: flex; flex-direction: column; height: 100vh; }

/* ─── HEADER — SUNRISE GRADIENT + WIDGET CARDS ─── */
.kds-head {
  display: flex; justify-content: space-between; align-items: stretch;
  padding: 14px 20px;
  gap: 14px;
  background: linear-gradient(135deg, #FBBF24 0%, #F59E0B 55%, #FB923C 100%);
  border-bottom: 3px solid rgba(0,0,0,0.12);
  color: #1F2937;
  flex-shrink: 0;
  min-height: 150px;
  box-shadow: 0 4px 14px rgba(251,146,60,0.25);
}
.kds-brand {
  display: flex; align-items: center; gap: 14px;
  flex-shrink: 0; max-width: 280px;
}
.kds-brand-ic {
  font-size: 56px;
  filter: drop-shadow(0 2px 4px rgba(0,0,0,0.18));
  animation: brand-bob 4s ease-in-out infinite;
}
@keyframes brand-bob { 50% { transform: translateY(-3px); } }
.kds-brand h1 {
  font-size: 20px; font-weight: 900;
  color: #1F2937; letter-spacing: -0.02em;
  line-height: 1.15;
}
.kds-brand small { font-size: 11px; color: rgba(31,41,55,0.7); display: block; margin-top: 3px; }

/* WIDGET STAT CARDS */
.kds-stats {
  display: grid;
  grid-template-columns: repeat(4, 1fr);
  gap: 10px;
  flex: 1;
  align-items: stretch;
}
.kds-stat {
  position: relative;
  background: rgba(255,255,255,0.95);
  border: 2px solid rgba(255,255,255,0.7);
  border-radius: 16px;
  padding: 10px 12px 12px;
  display: flex; flex-direction: column; justify-content: center; align-items: center;
  text-align: center;
  cursor: pointer;
  transition: transform 0.18s ease, box-shadow 0.18s ease, background 0.18s ease, border-color 0.18s ease;
  box-shadow: 0 3px 8px rgba(0,0,0,0.08);
  overflow: hidden;
  user-select: none;
}
.kds-stat::before {
  content: ''; position: absolute; left: 0; right: 0; bottom: 0;
  height: 4px;
  background: var(--accent, #94A3B8);
  transform: scaleX(0); transform-origin: left;
  transition: transform 0.25s ease;
}
.kds-stat:hover {
  transform: translateY(-3px);
  box-shadow: 0 10px 24px rgba(0,0,0,0.18);
  background: #fff;
}
.kds-stat:hover::before { transform: scaleX(1); }
.kds-stat:active { transform: translateY(-1px); }
.kds-stat.is-active {
  background: #1F2937;
  color: #fff;
  border-color: #1F2937;
}
.kds-stat.is-active .kds-stat-lbl,
.kds-stat.is-active .kds-stat-num { color: inherit; }
.kds-stat.is-active::before { transform: scaleX(1); }
.kds-stat-ic {
  font-size: 20px;
  position: absolute;
  top: 8px; left: 12px;
  opacity: 0.55;
}
.kds-stat-num {
  font-size: 68px;
  font-weight: 900;
  font-variant-numeric: tabular-nums;
  line-height: 0.95;
  letter-spacing: -0.05em;
  color: #1F2937;
  margin-top: 6px;
  transition: color 0.18s ease;
}
.kds-stat--cook { --accent: #EA580C; }
.kds-stat--cook .kds-stat-num { color: #EA580C; }
.kds-stat--ready { --accent: #15803D; }
.kds-stat--ready .kds-stat-num { color: #15803D; }
.kds-stat--orders { --accent: #2563EB; }
.kds-stat--orders .kds-stat-num { color: #2563EB; }
.kds-stat--items { --accent: #7C3AED; }
.kds-stat--items .kds-stat-num { color: #7C3AED; }
.kds-stat-lbl {
  font-size: 11px;
  font-weight: 800;
  text-transform: uppercase;
  letter-spacing: 0.1em;
  color: rgba(31,41,55,0.7);
  margin-top: 4px;
}
.kds-stat-pulse {
  position: absolute; top: 10px; right: 12px;
  width: 8px; height: 8px; border-radius: 50%;
  background: #10B981; opacity: 0; transition: opacity 0.25s ease;
}
.kds-stat-pulse.is-on {
  opacity: 1;
  animation: pulse-dot 1.4s ease-in-out infinite;
}
@keyframes pulse-dot {
  0%,100% { box-shadow: 0 0 0 0 rgba(16,185,129,0.6); }
  50%     { box-shadow: 0 0 0 8px rgba(16,185,129,0); }
}

.kds-clock-wrap {
  display: flex; flex-direction: column; justify-content: center; align-items: flex-end;
  flex-shrink: 0;
  padding-left: 6px;
  min-width: 110px;
}
.kds-clock {
  font-size: 48px; font-weight: 900;
  font-variant-numeric: tabular-nums;
  color: #1F2937;
  letter-spacing: -0.03em;
  line-height: 1;
  text-shadow: 0 2px 0 rgba(255,255,255,0.4);
}
.kds-date {
  font-size: 11px; opacity: 0.75;
  text-transform: uppercase; letter-spacing: 0.1em;
  margin-top: 6px; font-weight: 700;
}

/* ─── ORDERS GRID ─── */
.kds-grid {
  flex: 1;
  display: grid;
  grid-template-columns: repeat(auto-fill, minmax(320px, 1fr));
  gap: 14px;
  padding: 16px;
  overflow-y: auto;
  align-content: start;
}
.kds-order {
  background: #1a1d24;
  border: 2px solid rgba(255,255,255,0.08);
  border-radius: 12px;
  overflow: hidden;
  display: flex; flex-direction: column;
  transition: transform 0.15s ease;
}
.kds-order.is-warning { border-color: #F59E0B; box-shadow: 0 0 18px rgba(245,158,11,0.3); }
.kds-order.is-critical { border-color: #DC2626; box-shadow: 0 0 18px rgba(220,38,38,0.5); animation: critical-pulse 2s infinite; }
@keyframes critical-pulse { 50% { box-shadow: 0 0 28px rgba(220,38,38,0.8); } }

.kds-order-head {
  padding: 12px 16px;
  background: rgba(255,255,255,0.04);
  border-bottom: 1px solid rgba(255,255,255,0.06);
  display: flex; justify-content: space-between; align-items: center;
}
.kds-order-table { font-size: 18px; font-weight: 800; }
.kds-order-time {
  font-size: 13px; font-variant-numeric: tabular-nums;
  background: rgba(255,255,255,0.08); padding: 3px 9px; border-radius: 6px;
}
.kds-order.is-warning .kds-order-time { background: #F59E0B; color: #1a1d24; font-weight: 700; }
.kds-order.is-critical .kds-order-time { background: #DC2626; color: #fff; font-weight: 700; }

.kds-items { padding: 8px; }
.kds-item {
  display: grid;
  grid-template-columns: auto 1fr auto;
  gap: 8px;
  padding: 10px 12px;
  margin-bottom: 6px;
  background: rgba(255,255,255,0.04);
  border-radius: 8px;
  cursor: pointer;
  transition: all 0.15s ease;
  font-size: 15px;
  align-items: center;
  user-select: none;
}
.kds-item:hover { background: rgba(255,255,255,0.08); }
.kds-item:active { transform: scale(0.98); }
.kds-item-mn { font-weight: 800; font-size: 16px; opacity: 0.7; min-width: 28px; }
.kds-item-name { font-weight: 600; }
.kds-item-state {
  font-size: 18px; padding: 4px 8px; border-radius: 6px;
}
.kds-item[data-state="objednano"] .kds-item-state { background: #3B82F6; }
.kds-item[data-state="vari_se"]   .kds-item-state { background: #F59E0B; color: #1a1d24; }
.kds-item[data-state="hotovo"]    .kds-item-state { background: #10B981; }
.kds-item[data-state="hotovo"]    { opacity: 0.7; text-decoration: line-through; }

.kds-order-foot {
  padding: 10px 12px;
  background: rgba(0,0,0,0.3);
  display: flex; gap: 8px;
  border-top: 1px solid rgba(255,255,255,0.06);
}
.kds-btn {
  flex: 1;
  padding: 8px 12px;
  background: rgba(255,255,255,0.08);
  border: 1px solid rgba(255,255,255,0.12);
  color: #fff;
  font-family: inherit;
  font-size: 13px;
  font-weight: 700;
  border-radius: 6px;
  cursor: pointer;
}
.kds-btn:hover { background: rgba(255,255,255,0.14); }
.kds-btn.is-primary { background: #10B981; border-color: #10B981; }
.kds-btn.is-primary:hover { background: #15803D; }

/* ─── EMPTY STATE ─── */
.kds-empty {
  grid-column: 1 / -1;
  text-align: center;
  padding: 80px 20px;
  opacity: 0.5;
}
.kds-empty-ic { font-size: 80px; margin-bottom: 14px; }
.kds-empty-title { font-size: 22px; font-weight: 700; margin-bottom: 6px; }
.kds-empty-sub { font-size: 14px; opacity: 0.7; }

/* ─── FOOTER ─── */
.kds-foot {
  padding: 8px 22px;
  background: rgba(0,0,0,0.5);
  font-size: 11px;
  display: flex; justify-content: space-between; align-items: center;
  opacity: 0.6;
  flex-shrink: 0;
}
.kds-foot a { color: inherit; text-decoration: none; }
.kds-foot a:hover { opacity: 1; text-decoration: underline; }
.status-dot { width: 8px; height: 8px; border-radius: 50%; background: #10B981; display: inline-block; margin-right: 6px; }
.status-dot.is-offline { background: #DC2626; }

/* Tablet/menší TV */
@media (max-width: 1200px) {
  .kds-head { flex-wrap: wrap; min-height: auto; }
  .kds-stats { order: 3; flex-basis: 100%; grid-template-columns: repeat(4, 1fr); }
  .kds-stat-num { font-size: 54px; }
  .kds-clock { font-size: 36px; }
}
@media (max-width: 600px) {
  .kds-grid { grid-template-columns: 1fr; padding: 8px; }
  .kds-stats { grid-template-columns: repeat(2, 1fr); gap: 8px; }
  .kds-stat { padding: 8px 6px 10px; border-radius: 12px; }
  .kds-stat-num { font-size: 42px; }
  .kds-stat-ic { font-size: 14px; top: 6px; left: 8px; }
  .kds-stat-lbl { font-size: 9px; letter-spacing: 0.06em; }
  .kds-brand-ic { font-size: 38px; }
  .kds-brand h1 { font-size: 15px; }
  .kds-brand small { display: none; }
  .kds-clock { font-size: 26px; }
  .kds-date { display: none; }
  .kds-head { min-height: auto; padding: 10px 12px; }
}
</style>
</head>
<body>

<div class="kds-app">
  <header class="kds-head">
    <div class="kds-brand">
      <span class="kds-brand-ic">👨‍🍳</span>
      <div>
        <h1>APPEK · Kuchyňský displej</h1>
        <small>Klik na položku: objednáno → vaří se → hotovo</small>
      </div>
    </div>
    <div class="kds-stats">
      <div class="kds-stat kds-stat--orders" data-filter="all" title="Všechny účty">
        <span class="kds-stat-ic">🪑</span>
        <span class="kds-stat-pulse" id="pulse-orders"></span>
        <div class="kds-stat-num" id="stat-orders">0</div>
        <div class="kds-stat-lbl">Účtů</div>
      </div>
      <div class="kds-stat kds-stat--items" data-filter="all" title="Celkem položek">
        <span class="kds-stat-ic">🍽️</span>
        <div class="kds-stat-num" id="stat-items">0</div>
        <div class="kds-stat-lbl">Položek</div>
      </div>
      <div class="kds-stat kds-stat--cook" data-filter="vari_se" title="Klik = jen účty s vařícími se položkami">
        <span class="kds-stat-ic">🔥</span>
        <span class="kds-stat-pulse" id="pulse-cooking"></span>
        <div class="kds-stat-num" id="stat-cooking">0</div>
        <div class="kds-stat-lbl">Vaří se</div>
      </div>
      <div class="kds-stat kds-stat--ready" data-filter="hotovo" title="Klik = jen účty s hotovými položkami">
        <span class="kds-stat-ic">✓</span>
        <div class="kds-stat-num" id="stat-ready">0</div>
        <div class="kds-stat-lbl">Hotových</div>
      </div>
    </div>
    <div class="kds-clock-wrap">
      <div class="kds-clock" id="kds-clock">00:00</div>
      <div class="kds-date" id="kds-date">—</div>
    </div>
  </header>

  <main class="kds-grid" id="kds-grid">
    <div class="kds-empty">
      <div class="kds-empty-ic">⏳</div>
      <div class="kds-empty-title">Načítám…</div>
    </div>
  </main>

  <footer class="kds-foot">
    <div>
      <span class="status-dot" id="status-dot"></span>
      <span id="status-text">Připojeno</span>
      · Auto-refresh 10 s · Poslední data: <span id="last-update">—</span>
    </div>
    <div>APPEK KDS · v<?= htmlspecialchars($appVersion) ?> · <a href="vydej.php">📤 Výdej</a> · <a href="../admin/">← Admin</a></div>
  </footer>
</div>

<script>
const REFRESH_SEC = 10;
const API = '../api';
let prevOrderCount = 0;
let prevStats = { orders: 0, items: 0, cooking: 0, ready: 0 };
let activeFilter = 'all'; // 'all' | 'vari_se' | 'hotovo'
let lastOrdersData = [];
const DAY_NAMES = ['Ne','Po','Út','St','Čt','Pá','So'];
const MONTH_NAMES = ['led','úno','bře','dub','kvě','čvn','čvc','srp','zář','říj','lis','pro'];

// Hodiny + datum
function updateClock() {
  const now = new Date();
  const hh = String(now.getHours()).padStart(2, '0');
  const mm = String(now.getMinutes()).padStart(2, '0');
  document.getElementById('kds-clock').textContent = hh + ':' + mm;
  const dEl = document.getElementById('kds-date');
  if (dEl) dEl.textContent = `${DAY_NAMES[now.getDay()]} ${now.getDate()}. ${MONTH_NAMES[now.getMonth()]}`;
}
setInterval(updateClock, 30000);
updateClock();

// Count-up animace pro widget čísla
function animateNum(el, from, to) {
  if (from === to) { el.textContent = to; return; }
  const dur = 450; const start = performance.now();
  function step(t) {
    const p = Math.min(1, (t - start) / dur);
    const eased = 1 - Math.pow(1 - p, 3);
    el.textContent = Math.round(from + (to - from) * eased);
    if (p < 1) requestAnimationFrame(step);
  }
  requestAnimationFrame(step);
}

// Klik na widget = toggle filter
document.addEventListener('click', e => {
  const w = e.target.closest('.kds-stat[data-filter]');
  if (!w) return;
  const f = w.dataset.filter;
  // Toggle: pokud už aktivní → vypnout (vrátit na 'all')
  activeFilter = (activeFilter === f && f !== 'all') ? 'all' : f;
  document.querySelectorAll('.kds-stat').forEach(s => {
    s.classList.toggle('is-active', activeFilter !== 'all' && s.dataset.filter === activeFilter);
  });
  renderOrders(lastOrdersData);
});

// Zvuk na novou objednávku
function chimeNewOrder() {
  try {
    const ctx = new (window.AudioContext || window.webkitAudioContext)();
    const osc = ctx.createOscillator();
    const gain = ctx.createGain();
    osc.connect(gain); gain.connect(ctx.destination);
    osc.frequency.value = 880;
    gain.gain.setValueAtTime(0.3, ctx.currentTime);
    gain.gain.exponentialRampToValueAtTime(0.01, ctx.currentTime + 0.4);
    osc.start(); osc.stop(ctx.currentTime + 0.4);
  } catch (e) {}
}

// Escape HTML
function esc(s) { return String(s ?? '').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c])); }

// Změna stavu položky
async function setItemState(itemId, currentState) {
  // Cycle: objednano → vari_se → hotovo
  const next = { objednano: 'vari_se', vari_se: 'hotovo', hotovo: 'servirovano' }[currentState] || 'hotovo';
  try {
    const r = await fetch(`${API}/admin_pos.php?action=item_state`, {
      method: 'POST',
      credentials: 'include',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ id: itemId, stav: next }),
    });
    if (r.ok) loadData(); // refresh immediately
  } catch (e) { console.error('[KDS] state change failed:', e); }
}
window.setItemState = setItemState;

// Mark all items in order as done
async function markOrderReady(ucetId) {
  const items = document.querySelectorAll(`[data-ucet-id="${ucetId}"] .kds-item:not([data-state="hotovo"])`);
  for (const item of items) {
    const id = item.dataset.itemId;
    const state = item.dataset.state;
    try {
      await fetch(`${API}/admin_pos.php?action=item_state`, {
        method: 'POST',
        credentials: 'include',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ id: parseInt(id), stav: 'hotovo' }),
      });
    } catch (e) {}
  }
  loadData();
}
window.markOrderReady = markOrderReady;

// Min uplynulé od času objednávky
function minutesSince(iso) {
  if (!iso) return 0;
  const dt = new Date(iso.replace(' ', 'T'));
  return Math.floor((Date.now() - dt.getTime()) / 60000);
}

// Render objednávkové grid (volá se i při změně filtru)
function renderOrders(orders) {
  const grid = document.getElementById('kds-grid');

  // Filtruj podle aktivního widgetu
  let shown = orders;
  if (activeFilter === 'vari_se' || activeFilter === 'hotovo') {
    shown = orders.filter(o => o.polozky.some(p => p.stav === activeFilter));
  }

  if (shown.length === 0) {
    const noFilterMsg = activeFilter === 'vari_se'
      ? { ic: '🔥', t: 'Žádné položky se nevaří', s: 'Klik na widget Vaří se znovu pro zrušení filtru.' }
      : activeFilter === 'hotovo'
      ? { ic: '✓', t: 'Žádné hotové položky', s: 'Klik na widget Hotových znovu pro zrušení filtru.' }
      : { ic: '✅', t: 'Žádné aktivní objednávky', s: 'Až někdo objedná, karty se objeví zde.' };
    grid.innerHTML = `
      <div class="kds-empty">
        <div class="kds-empty-ic">${noFilterMsg.ic}</div>
        <div class="kds-empty-title">${noFilterMsg.t}</div>
        <div class="kds-empty-sub">${noFilterMsg.s}</div>
      </div>
    `;
    return;
  }

  grid.innerHTML = shown.map(o => {
    const min = minutesSince(o.first_objednavka);
    const stateClass = min >= 15 ? 'is-critical' : (min >= 10 ? 'is-warning' : '');
    return `
      <div class="kds-order ${stateClass}" data-ucet-id="${o.ucet_id}">
        <div class="kds-order-head">
          <div class="kds-order-table">🪑 ${esc(o.stul_nazev || '?')}</div>
          <div class="kds-order-time">${min} min</div>
        </div>
        <div class="kds-items">
          ${o.polozky.map(p => `
            <div class="kds-item" data-item-id="${p.id}" data-state="${esc(p.stav)}" onclick="setItemState(${p.id}, '${esc(p.stav)}')">
              <span class="kds-item-mn">${parseInt(p.mnozstvi)}×</span>
              <span class="kds-item-name">${esc(p.vyrobek_nazev || '?')}${p.poznamka ? `<div style="font-size:11px;opacity:0.7;margin-top:2px">💬 ${esc(p.poznamka)}</div>` : ''}</span>
              <span class="kds-item-state">${p.stav === 'objednano' ? '⏳' : p.stav === 'vari_se' ? '🔥' : '✓'}</span>
            </div>
          `).join('')}
        </div>
        <div class="kds-order-foot">
          <button class="kds-btn is-primary" onclick="markOrderReady(${o.ucet_id})">✓ Vše hotovo</button>
        </div>
      </div>
    `;
  }).join('');
}

// Hlavní fetch + stats
async function loadData() {
  try {
    const r = await fetch(`${API}/admin_pos.php?action=kds`, { credentials: 'include' });
    if (!r.ok) throw new Error('HTTP ' + r.status);
    const d = await r.json();
    setStatus(true);

    const orders = d.orders || [];
    lastOrdersData = orders;

    // Beep na novou objednávku
    if (orders.length > prevOrderCount && prevOrderCount > 0) chimeNewOrder();
    prevOrderCount = orders.length;

    // Stats — počty
    let totalItems = 0, cooking = 0, ready = 0;
    orders.forEach(o => {
      totalItems += o.polozky.length;
      o.polozky.forEach(p => {
        if (p.stav === 'vari_se') cooking++;
        else if (p.stav === 'hotovo') ready++;
      });
    });

    // Count-up animace
    animateNum(document.getElementById('stat-orders'),  prevStats.orders,  orders.length);
    animateNum(document.getElementById('stat-items'),   prevStats.items,   totalItems);
    animateNum(document.getElementById('stat-cooking'), prevStats.cooking, cooking);
    animateNum(document.getElementById('stat-ready'),   prevStats.ready,   ready);

    // Pulsing dot na widgetech kde se něco děje
    document.getElementById('pulse-orders').classList.toggle('is-on', orders.length > 0);
    document.getElementById('pulse-cooking').classList.toggle('is-on', cooking > 0);

    prevStats = { orders: orders.length, items: totalItems, cooking, ready };

    renderOrders(orders);
    document.getElementById('last-update').textContent = new Date().toLocaleTimeString('cs-CZ');
  } catch (e) {
    setStatus(false);
    console.error('[KDS] load failed:', e);
  }
}

function setStatus(online) {
  const dot = document.getElementById('status-dot');
  const text = document.getElementById('status-text');
  dot.className = online ? 'status-dot' : 'status-dot is-offline';
  text.textContent = online ? 'Připojeno' : 'Odpojeno — auto-retry…';
}

loadData();
setInterval(loadData, REFRESH_SEC * 1000);

// Wake-lock pro kuchyňský displej (24/7 wake)
if ('wakeLock' in navigator) {
  let wakeLock = null;
  async function requestWakeLock() { try { wakeLock = await navigator.wakeLock.request('screen'); } catch (e) {} }
  requestWakeLock();
  document.addEventListener('visibilitychange', () => { if (document.visibilityState === 'visible') requestWakeLock(); });
}
</script>
</body>
</html>
