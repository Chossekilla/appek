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

/* ─── HEADER ─── */
.kds-head {
  display: flex; justify-content: space-between; align-items: center;
  padding: 12px 22px;
  background: rgba(0,0,0,0.5);
  border-bottom: 2px solid rgba(255,255,255,0.08);
  flex-shrink: 0;
}
.kds-brand { display: flex; align-items: center; gap: 12px; }
.kds-brand-ic { font-size: 32px; }
.kds-brand h1 { font-size: 18px; font-weight: 800; }
.kds-brand small { font-size: 11px; opacity: 0.6; }
.kds-stats { display: flex; gap: 18px; align-items: center; }
.kds-stat { text-align: center; }
.kds-stat-num { font-size: 22px; font-weight: 800; font-variant-numeric: tabular-nums; }
.kds-stat-lbl { font-size: 10px; text-transform: uppercase; letter-spacing: 0.05em; opacity: 0.6; }
.kds-clock { font-size: 26px; font-weight: 700; font-variant-numeric: tabular-nums; }

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

@media (max-width: 600px) {
  .kds-grid { grid-template-columns: 1fr; padding: 8px; }
  .kds-stats { display: none; }
  .kds-brand-ic { font-size: 24px; }
  .kds-brand h1 { font-size: 14px; }
  .kds-clock { font-size: 18px; }
}
</style>
</head>
<body>

<div class="kds-app">
  <header class="kds-head">
    <div class="kds-brand">
      <span class="kds-brand-ic">👨‍🍳</span>
      <div>
        <h1>APPEK · Kuchyňský displej (KDS)</h1>
        <small>Klik na položku posune stav: objednáno → vaří se → hotovo</small>
      </div>
    </div>
    <div class="kds-stats">
      <div class="kds-stat"><div class="kds-stat-num" id="stat-orders">0</div><div class="kds-stat-lbl">Účtů</div></div>
      <div class="kds-stat"><div class="kds-stat-num" id="stat-items">0</div><div class="kds-stat-lbl">Položek</div></div>
      <div class="kds-stat"><div class="kds-stat-num" style="color:#F59E0B" id="stat-cooking">0</div><div class="kds-stat-lbl">Vaří se</div></div>
      <div class="kds-stat"><div class="kds-stat-num" style="color:#10B981" id="stat-ready">0</div><div class="kds-stat-lbl">Hotových</div></div>
    </div>
    <div class="kds-clock" id="kds-clock">00:00</div>
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
    <div>APPEK KDS · v<?= htmlspecialchars($appVersion) ?> · <a href="../admin/">← Admin</a></div>
  </footer>
</div>

<script>
const REFRESH_SEC = 10;
const API = '../api';
let prevOrderCount = 0;

// Hodiny
function updateClock() {
  const now = new Date();
  const hh = String(now.getHours()).padStart(2, '0');
  const mm = String(now.getMinutes()).padStart(2, '0');
  document.getElementById('kds-clock').textContent = hh + ':' + mm;
}
setInterval(updateClock, 30000);
updateClock();

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

// Hlavní render
async function loadData() {
  try {
    const r = await fetch(`${API}/admin_pos.php?action=kds`, { credentials: 'include' });
    if (!r.ok) throw new Error('HTTP ' + r.status);
    const d = await r.json();
    setStatus(true);

    const orders = d.orders || [];

    // Beep na novou objednávku
    if (orders.length > prevOrderCount && prevOrderCount > 0) chimeNewOrder();
    prevOrderCount = orders.length;

    // Stats
    let totalItems = 0, cooking = 0, ready = 0;
    orders.forEach(o => {
      totalItems += o.polozky.length;
      o.polozky.forEach(p => {
        if (p.stav === 'vari_se') cooking++;
        else if (p.stav === 'hotovo') ready++;
      });
    });
    document.getElementById('stat-orders').textContent = orders.length;
    document.getElementById('stat-items').textContent = totalItems;
    document.getElementById('stat-cooking').textContent = cooking;
    document.getElementById('stat-ready').textContent = ready;

    const grid = document.getElementById('kds-grid');
    if (orders.length === 0) {
      grid.innerHTML = `
        <div class="kds-empty">
          <div class="kds-empty-ic">✅</div>
          <div class="kds-empty-title">Žádné aktivní objednávky</div>
          <div class="kds-empty-sub">Až někdo objedná, karty se objeví zde.</div>
        </div>
      `;
      return;
    }

    grid.innerHTML = orders.map(o => {
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
