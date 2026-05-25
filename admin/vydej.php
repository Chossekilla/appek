<?php
/**
 * 🆕 v3.0.3 — VÝDEJ / PASS (Pickup display)
 *
 * Druhý kuchyňský displej — pro číšníka/runnera u pass-through okna.
 * Zatímco KDS je pro kuchaře (co vařit), Výdej je pro obsluhu (co odnést na stůl).
 *
 * Co dělá:
 *   - Karty účtů, které mají aspoň jednu položku ve stavu HOTOVO
 *   - Hotové položky jsou klikatelné → servírováno (zmizí z výdeje)
 *   - Položky ještě se vařící jsou viditelné šedě (číšník vidí co ještě dojde)
 *   - Tlačítko "📤 Vše odneseno" pro celý účet
 *   - Tlačítko "🖨️ Tisk bonu" — vytištění pickup lístku pro číšníka
 *   - Auto-refresh 8 s (rychlejší než KDS — výdej musí být real-time)
 *   - Zvukový signál na novou položku hotovo
 *   - Zelený sunrise theme (kontrast vůči oranžovému KDS)
 *
 * Vyžaduje admin/prodavac/pos role.
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
<meta name="theme-color" content="#10B981">
<meta name="robots" content="noindex, nofollow">
<title>APPEK Výdej — Pass-through</title>
<style>
* { box-sizing: border-box; margin: 0; padding: 0; }
html, body {
  font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', system-ui, sans-serif;
  background: #0d1410;
  color: #fff;
  height: 100vh;
  overflow: hidden;
}
.vy-app { display: flex; flex-direction: column; height: 100vh; }

/* ─── HEADER — GREEN FRESH GRADIENT + WIDGET CARDS ─── */
.vy-head {
  display: flex; justify-content: space-between; align-items: stretch;
  padding: 14px 20px;
  gap: 14px;
  background: linear-gradient(135deg, #34D399 0%, #10B981 55%, #059669 100%);
  border-bottom: 3px solid rgba(0,0,0,0.12);
  color: #052e1c;
  flex-shrink: 0;
  min-height: 150px;
  box-shadow: 0 4px 14px rgba(16,185,129,0.25);
}
.vy-brand {
  display: flex; align-items: center; gap: 14px;
  flex-shrink: 0; max-width: 280px;
}
.vy-brand-ic {
  font-size: 56px;
  filter: drop-shadow(0 2px 4px rgba(0,0,0,0.18));
  animation: brand-bob 4s ease-in-out infinite;
}
@keyframes brand-bob { 50% { transform: translateY(-3px); } }
.vy-brand h1 {
  font-size: 20px; font-weight: 900;
  color: #052e1c; letter-spacing: -0.02em;
  line-height: 1.15;
}
.vy-brand small { font-size: 11px; color: rgba(5,46,28,0.75); display: block; margin-top: 3px; }

/* WIDGET STAT CARDS */
.vy-stats {
  display: grid;
  grid-template-columns: repeat(4, 1fr);
  gap: 10px;
  flex: 1;
  align-items: stretch;
}
.vy-stat {
  position: relative;
  background: rgba(255,255,255,0.95);
  border: 2px solid rgba(255,255,255,0.7);
  border-radius: 16px;
  padding: 10px 12px 12px;
  display: flex; flex-direction: column; justify-content: center; align-items: center;
  text-align: center;
  cursor: pointer;
  transition: transform 0.18s ease, box-shadow 0.18s ease, background 0.18s ease;
  box-shadow: 0 3px 8px rgba(0,0,0,0.08);
  overflow: hidden;
  user-select: none;
}
.vy-stat::before {
  content: ''; position: absolute; left: 0; right: 0; bottom: 0;
  height: 4px;
  background: var(--accent, #94A3B8);
  transform: scaleX(0); transform-origin: left;
  transition: transform 0.25s ease;
}
.vy-stat:hover {
  transform: translateY(-3px);
  box-shadow: 0 10px 24px rgba(0,0,0,0.18);
  background: #fff;
}
.vy-stat:hover::before { transform: scaleX(1); }
.vy-stat-ic { font-size: 20px; position: absolute; top: 8px; left: 12px; opacity: 0.55; }
.vy-stat-num {
  font-size: 68px; font-weight: 900;
  font-variant-numeric: tabular-nums;
  line-height: 0.95; letter-spacing: -0.05em;
  color: #1F2937; margin-top: 6px;
}
.vy-stat--ready  { --accent: #15803D; } .vy-stat--ready  .vy-stat-num { color: #15803D; }
.vy-stat--orders { --accent: #2563EB; } .vy-stat--orders .vy-stat-num { color: #2563EB; }
.vy-stat--wait   { --accent: #DC2626; } .vy-stat--wait   .vy-stat-num { color: #DC2626; }
.vy-stat--items  { --accent: #7C3AED; } .vy-stat--items  .vy-stat-num { color: #7C3AED; }
.vy-stat-lbl {
  font-size: 11px; font-weight: 800;
  text-transform: uppercase; letter-spacing: 0.1em;
  color: rgba(31,41,55,0.7); margin-top: 4px;
}
.vy-stat-pulse {
  position: absolute; top: 10px; right: 12px;
  width: 8px; height: 8px; border-radius: 50%;
  background: #DC2626; opacity: 0; transition: opacity 0.25s ease;
}
.vy-stat-pulse.is-on { opacity: 1; animation: pulse-dot 1.4s ease-in-out infinite; }
@keyframes pulse-dot {
  0%,100% { box-shadow: 0 0 0 0 rgba(220,38,38,0.6); }
  50%     { box-shadow: 0 0 0 8px rgba(220,38,38,0); }
}

.vy-clock-wrap {
  display: flex; flex-direction: column; justify-content: center; align-items: flex-end;
  flex-shrink: 0; padding-left: 6px; min-width: 110px;
}
.vy-clock {
  font-size: 48px; font-weight: 900;
  font-variant-numeric: tabular-nums;
  color: #052e1c; letter-spacing: -0.03em; line-height: 1;
  text-shadow: 0 2px 0 rgba(255,255,255,0.4);
}
.vy-date {
  font-size: 11px; opacity: 0.8;
  text-transform: uppercase; letter-spacing: 0.1em;
  margin-top: 6px; font-weight: 700;
}

/* ─── ORDERS GRID ─── */
.vy-grid {
  flex: 1;
  display: grid;
  grid-template-columns: repeat(auto-fill, minmax(340px, 1fr));
  gap: 14px;
  padding: 16px;
  overflow-y: auto;
  align-content: start;
}
.vy-order {
  background: #18211b;
  border: 2px solid #10B981;
  border-radius: 14px;
  overflow: hidden;
  display: flex; flex-direction: column;
  box-shadow: 0 0 18px rgba(16,185,129,0.18);
  transition: transform 0.15s ease;
}
.vy-order.is-fresh { animation: fresh-glow 1.2s ease-out 2; }
@keyframes fresh-glow {
  0%   { box-shadow: 0 0 28px rgba(16,185,129,0.7); transform: scale(1.01); }
  100% { box-shadow: 0 0 18px rgba(16,185,129,0.18); transform: scale(1); }
}
.vy-order-head {
  padding: 12px 16px;
  background: rgba(16,185,129,0.15);
  border-bottom: 1px solid rgba(16,185,129,0.25);
  display: flex; justify-content: space-between; align-items: center;
}
.vy-order-table { font-size: 22px; font-weight: 900; }
.vy-order-meta {
  font-size: 12px; font-variant-numeric: tabular-nums;
  background: rgba(255,255,255,0.1); padding: 4px 10px; border-radius: 6px;
}
.vy-order-meta.is-wait { background: #DC2626; color: #fff; font-weight: 700; }

.vy-items { padding: 8px; }
.vy-item {
  display: grid;
  grid-template-columns: auto 1fr auto;
  gap: 8px;
  padding: 12px 14px;
  margin-bottom: 6px;
  border-radius: 10px;
  font-size: 17px;
  align-items: center;
  user-select: none;
}
/* HOTOVO — klikatelné, výrazné */
.vy-item.is-ready {
  background: rgba(16,185,129,0.18);
  border: 2px solid #10B981;
  cursor: pointer;
  font-weight: 700;
  transition: all 0.15s ease;
}
.vy-item.is-ready:hover { background: rgba(16,185,129,0.32); transform: translateX(2px); }
.vy-item.is-ready:active { transform: scale(0.98); }

/* VARI_SE — šedé info, neklikatelné */
.vy-item.is-cooking {
  background: rgba(245,158,11,0.08);
  border: 1px dashed rgba(245,158,11,0.4);
  opacity: 0.65;
  font-size: 14px;
  padding: 8px 12px;
}
/* OBJEDNANO — ještě šedší */
.vy-item.is-pending {
  background: rgba(255,255,255,0.04);
  border: 1px dashed rgba(255,255,255,0.1);
  opacity: 0.45;
  font-size: 13px;
  padding: 6px 12px;
}

.vy-item-mn { font-weight: 900; min-width: 32px; }
.vy-item.is-ready .vy-item-mn { font-size: 20px; color: #34D399; }
.vy-item-name { font-weight: inherit; }
.vy-item-ic { font-size: 22px; }
.vy-item.is-cooking .vy-item-ic { font-size: 16px; }
.vy-item.is-pending .vy-item-ic { font-size: 14px; }

.vy-order-foot {
  padding: 10px 12px;
  background: rgba(0,0,0,0.3);
  display: flex; gap: 8px;
  border-top: 1px solid rgba(255,255,255,0.06);
}
.vy-btn {
  flex: 1;
  padding: 10px 12px;
  background: rgba(255,255,255,0.08);
  border: 1px solid rgba(255,255,255,0.12);
  color: #fff;
  font-family: inherit;
  font-size: 14px;
  font-weight: 700;
  border-radius: 8px;
  cursor: pointer;
  transition: all 0.15s ease;
}
.vy-btn:hover { background: rgba(255,255,255,0.18); }
.vy-btn.is-primary { background: #10B981; border-color: #10B981; }
.vy-btn.is-primary:hover { background: #059669; }
.vy-btn.is-primary:disabled { background: rgba(255,255,255,0.05); border-color: rgba(255,255,255,0.08); cursor: not-allowed; }

/* ─── EMPTY STATE ─── */
.vy-empty {
  grid-column: 1 / -1;
  text-align: center;
  padding: 80px 20px;
  opacity: 0.5;
}
.vy-empty-ic { font-size: 80px; margin-bottom: 14px; }
.vy-empty-title { font-size: 24px; font-weight: 700; margin-bottom: 6px; }
.vy-empty-sub { font-size: 14px; opacity: 0.7; }

/* ─── FOOTER ─── */
.vy-foot {
  padding: 8px 22px;
  background: rgba(0,0,0,0.5);
  font-size: 11px;
  display: flex; justify-content: space-between; align-items: center;
  opacity: 0.6;
  flex-shrink: 0;
}
.vy-foot a { color: inherit; text-decoration: none; }
.vy-foot a:hover { opacity: 1; text-decoration: underline; }
.status-dot { width: 8px; height: 8px; border-radius: 50%; background: #10B981; display: inline-block; margin-right: 6px; }
.status-dot.is-offline { background: #DC2626; }

/* Responsive */
@media (max-width: 1200px) {
  .vy-head { flex-wrap: wrap; min-height: auto; }
  .vy-stats { order: 3; flex-basis: 100%; grid-template-columns: repeat(4, 1fr); }
  .vy-stat-num { font-size: 54px; }
  .vy-clock { font-size: 36px; }
}
@media (max-width: 600px) {
  .vy-grid { grid-template-columns: 1fr; padding: 8px; }
  .vy-stats { grid-template-columns: repeat(2, 1fr); gap: 8px; }
  .vy-stat { padding: 8px 6px 10px; border-radius: 12px; }
  .vy-stat-num { font-size: 42px; }
  .vy-stat-ic { font-size: 14px; top: 6px; left: 8px; }
  .vy-stat-lbl { font-size: 9px; letter-spacing: 0.06em; }
  .vy-brand-ic { font-size: 38px; }
  .vy-brand h1 { font-size: 15px; }
  .vy-brand small { display: none; }
  .vy-clock { font-size: 26px; }
  .vy-date { display: none; }
  .vy-head { min-height: auto; padding: 10px 12px; }
}
</style>
</head>
<body>

<div class="vy-app">
  <header class="vy-head">
    <div class="vy-brand">
      <span class="vy-brand-ic">📤</span>
      <div>
        <h1>APPEK · Výdej (Pass)</h1>
        <small>Klik na hotovou položku = odneseno na stůl</small>
      </div>
    </div>
    <div class="vy-stats">
      <div class="vy-stat vy-stat--ready" title="Položek k výdeji">
        <span class="vy-stat-ic">✓</span>
        <span class="vy-stat-pulse" id="pulse-ready"></span>
        <div class="vy-stat-num" id="stat-ready">0</div>
        <div class="vy-stat-lbl">K výdeji</div>
      </div>
      <div class="vy-stat vy-stat--orders" title="Otevřených účtů">
        <span class="vy-stat-ic">🪑</span>
        <div class="vy-stat-num" id="stat-orders">0</div>
        <div class="vy-stat-lbl">Stolů</div>
      </div>
      <div class="vy-stat vy-stat--items" title="Položek se vaří">
        <span class="vy-stat-ic">🔥</span>
        <div class="vy-stat-num" id="stat-cooking">0</div>
        <div class="vy-stat-lbl">Vaří se</div>
      </div>
      <div class="vy-stat vy-stat--wait" title="Nejdelší čekání (min)">
        <span class="vy-stat-ic">⏱</span>
        <span class="vy-stat-pulse" id="pulse-wait"></span>
        <div class="vy-stat-num" id="stat-wait">0</div>
        <div class="vy-stat-lbl">Max čekání</div>
      </div>
    </div>
    <div class="vy-clock-wrap">
      <div class="vy-clock" id="vy-clock">00:00</div>
      <div class="vy-date" id="vy-date">—</div>
    </div>
  </header>

  <main class="vy-grid" id="vy-grid">
    <div class="vy-empty">
      <div class="vy-empty-ic">⏳</div>
      <div class="vy-empty-title">Načítám…</div>
    </div>
  </main>

  <footer class="vy-foot">
    <div>
      <span class="status-dot" id="status-dot"></span>
      <span id="status-text">Připojeno</span>
      · Auto-refresh 8 s · Poslední data: <span id="last-update">—</span>
    </div>
    <div>APPEK Výdej · v<?= htmlspecialchars($appVersion) ?> · <a href="kds.php">👨‍🍳 KDS</a> · <a href="../admin/">← Admin</a></div>
  </footer>
</div>

<script>
const REFRESH_SEC = 8;
const API = '../api';
let prevReadyCount = 0;
let prevStats = { ready: 0, orders: 0, cooking: 0, wait: 0 };
let seenReadyIds = new Set();
const DAY_NAMES = ['Ne','Po','Út','St','Čt','Pá','So'];
const MONTH_NAMES = ['led','úno','bře','dub','kvě','čvn','čvc','srp','zář','říj','lis','pro'];

function updateClock() {
  const now = new Date();
  document.getElementById('vy-clock').textContent =
    String(now.getHours()).padStart(2,'0') + ':' + String(now.getMinutes()).padStart(2,'0');
  document.getElementById('vy-date').textContent =
    `${DAY_NAMES[now.getDay()]} ${now.getDate()}. ${MONTH_NAMES[now.getMonth()]}`;
}
setInterval(updateClock, 30000);
updateClock();

// Zvuk na novou položku hotovo (ding-dong, výrazný)
function chimeReady() {
  try {
    const ctx = new (window.AudioContext || window.webkitAudioContext)();
    [880, 1320].forEach((f, i) => {
      const osc = ctx.createOscillator();
      const gain = ctx.createGain();
      osc.connect(gain); gain.connect(ctx.destination);
      osc.frequency.value = f;
      const t = ctx.currentTime + i * 0.18;
      gain.gain.setValueAtTime(0.35, t);
      gain.gain.exponentialRampToValueAtTime(0.01, t + 0.35);
      osc.start(t); osc.stop(t + 0.35);
    });
  } catch (e) {}
}

function esc(s) { return String(s ?? '').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c])); }

function animateNum(el, from, to) {
  if (from === to) { el.textContent = to; return; }
  const dur = 450, start = performance.now();
  function step(t) {
    const p = Math.min(1, (t - start) / dur);
    const eased = 1 - Math.pow(1 - p, 3);
    el.textContent = Math.round(from + (to - from) * eased);
    if (p < 1) requestAnimationFrame(step);
  }
  requestAnimationFrame(step);
}

function minutesSince(iso) {
  if (!iso) return 0;
  const dt = new Date(iso.replace(' ', 'T'));
  return Math.floor((Date.now() - dt.getTime()) / 60000);
}

// Položka hotovo → servírováno (zmizí)
async function markServed(itemId) {
  try {
    await fetch(`${API}/admin_pos.php?action=item_state`, {
      method: 'POST', credentials: 'include',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ id: itemId, stav: 'servirovano' }),
    });
    loadData();
  } catch (e) { console.error('[Výdej] mark served failed:', e); }
}
window.markServed = markServed;

// Vše hotovo na účtu → vše servírováno
async function servedAll(ucetId) {
  const items = document.querySelectorAll(`[data-ucet-id="${ucetId}"] .vy-item.is-ready`);
  for (const it of items) {
    const id = parseInt(it.dataset.itemId);
    try {
      await fetch(`${API}/admin_pos.php?action=item_state`, {
        method: 'POST', credentials: 'include',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ id, stav: 'servirovano' }),
      });
    } catch (e) {}
  }
  loadData();
}
window.servedAll = servedAll;

// Tisk pickup bonu — popup okno s vytištěním (jen hotové položky)
function printBon(ucetId) {
  const card = document.querySelector(`[data-ucet-id="${ucetId}"]`);
  if (!card) return;
  const stul = card.querySelector('.vy-order-table')?.textContent || '?';
  const items = Array.from(card.querySelectorAll('.vy-item.is-ready')).map(it => ({
    mn: it.querySelector('.vy-item-mn')?.textContent || '1×',
    nm: it.querySelector('.vy-item-name')?.textContent || '?',
  }));
  if (!items.length) { alert('Žádné hotové položky k výdeji'); return; }
  const now = new Date();
  const time = String(now.getHours()).padStart(2,'0')+':'+String(now.getMinutes()).padStart(2,'0');
  const html = `<!DOCTYPE html><html><head><meta charset="utf-8"><title>Bon výdej ${esc(stul)}</title>
    <style>
      body{font-family:Courier,monospace;font-size:13px;width:280px;margin:8px auto;color:#000}
      h1{font-size:18px;text-align:center;margin:0 0 6px;border-bottom:2px solid #000;padding-bottom:6px}
      .meta{text-align:center;font-size:11px;margin-bottom:10px}
      .it{display:flex;justify-content:space-between;padding:6px 0;border-bottom:1px dashed #999;font-size:14px}
      .mn{font-weight:700;min-width:36px}
      .nm{flex:1;padding-left:8px}
      .foot{margin-top:10px;text-align:center;font-size:10px;border-top:2px solid #000;padding-top:6px}
    </style></head><body>
    <h1>📤 BON VÝDEJ</h1>
    <div class="meta">${esc(stul)} · ${time}</div>
    ${items.map(i => `<div class="it"><span class="mn">${esc(i.mn)}</span><span class="nm">${esc(i.nm)}</span></div>`).join('')}
    <div class="foot">APPEK Výdej · vytištěno ${time}</div>
    <script>window.print();setTimeout(()=>window.close(),800);<\/script>
    </body></html>`;
  const w = window.open('', '_blank', 'width=320,height=600');
  if (!w) { alert('Povolte popup okna pro tisk'); return; }
  w.document.write(html); w.document.close();
}
window.printBon = printBon;

function setStatus(online) {
  const dot = document.getElementById('status-dot');
  const text = document.getElementById('status-text');
  dot.className = online ? 'status-dot' : 'status-dot is-offline';
  text.textContent = online ? 'Připojeno' : 'Odpojeno — auto-retry…';
}

async function loadData() {
  try {
    const r = await fetch(`${API}/admin_pos.php?action=kds`, { credentials: 'include' });
    if (!r.ok) throw new Error('HTTP ' + r.status);
    const d = await r.json();
    setStatus(true);

    const orders = d.orders || [];

    // Filtruj: jen účty s aspoň jednou hotovou položkou
    const ordersWithReady = orders.filter(o => o.polozky.some(p => p.stav === 'hotovo'));

    // Stats
    let totalReady = 0, totalCooking = 0, maxWait = 0;
    const currentReadyIds = new Set();
    ordersWithReady.forEach(o => {
      o.polozky.forEach(p => {
        if (p.stav === 'hotovo') { totalReady++; currentReadyIds.add(p.id); }
        else if (p.stav === 'vari_se') totalCooking++;
      });
      const w = minutesSince(o.first_objednavka);
      if (w > maxWait) maxWait = w;
    });

    // Beep když přibyla nová hotová položka
    const newReady = [...currentReadyIds].some(id => !seenReadyIds.has(id));
    if (newReady && seenReadyIds.size > 0) chimeReady();
    seenReadyIds = currentReadyIds;

    animateNum(document.getElementById('stat-ready'),   prevStats.ready,   totalReady);
    animateNum(document.getElementById('stat-orders'),  prevStats.orders,  ordersWithReady.length);
    animateNum(document.getElementById('stat-cooking'), prevStats.cooking, totalCooking);
    animateNum(document.getElementById('stat-wait'),    prevStats.wait,    maxWait);

    document.getElementById('pulse-ready').classList.toggle('is-on', totalReady > 0);
    document.getElementById('pulse-wait').classList.toggle('is-on', maxWait >= 10);

    prevStats = { ready: totalReady, orders: ordersWithReady.length, cooking: totalCooking, wait: maxWait };

    const grid = document.getElementById('vy-grid');
    if (ordersWithReady.length === 0) {
      grid.innerHTML = `
        <div class="vy-empty">
          <div class="vy-empty-ic">😎</div>
          <div class="vy-empty-title">Žádné jídlo k výdeji</div>
          <div class="vy-empty-sub">Až kuchař označí položku jako hotovo, objeví se zde.</div>
        </div>
      `;
      return;
    }

    grid.innerHTML = ordersWithReady.map(o => {
      const min = minutesSince(o.first_objednavka);
      const waitClass = min >= 10 ? 'is-wait' : '';
      const isFreshClass = o.polozky.some(p => p.stav === 'hotovo' && !seenReadyIds.has(p.id)) ? 'is-fresh' : '';
      const readyCount = o.polozky.filter(p => p.stav === 'hotovo').length;
      return `
        <div class="vy-order ${isFreshClass}" data-ucet-id="${o.ucet_id}">
          <div class="vy-order-head">
            <div class="vy-order-table">🪑 ${esc(o.stul_nazev || '?')}</div>
            <div class="vy-order-meta ${waitClass}">${min} min · ${readyCount} hotovo</div>
          </div>
          <div class="vy-items">
            ${o.polozky.map(p => {
              if (p.stav === 'hotovo') {
                return `
                  <div class="vy-item is-ready" data-item-id="${p.id}" onclick="markServed(${p.id})">
                    <span class="vy-item-mn">${parseInt(p.mnozstvi)}×</span>
                    <span class="vy-item-name">${esc(p.vyrobek_nazev || '?')}${p.poznamka ? `<div style="font-size:12px;opacity:0.8;margin-top:2px;font-weight:500">💬 ${esc(p.poznamka)}</div>` : ''}</span>
                    <span class="vy-item-ic">✓</span>
                  </div>`;
              } else if (p.stav === 'vari_se') {
                return `
                  <div class="vy-item is-cooking">
                    <span class="vy-item-mn">${parseInt(p.mnozstvi)}×</span>
                    <span class="vy-item-name">${esc(p.vyrobek_nazev || '?')}</span>
                    <span class="vy-item-ic">🔥</span>
                  </div>`;
              } else {
                return `
                  <div class="vy-item is-pending">
                    <span class="vy-item-mn">${parseInt(p.mnozstvi)}×</span>
                    <span class="vy-item-name">${esc(p.vyrobek_nazev || '?')}</span>
                    <span class="vy-item-ic">⏳</span>
                  </div>`;
              }
            }).join('')}
          </div>
          <div class="vy-order-foot">
            <button class="vy-btn is-primary" onclick="servedAll(${o.ucet_id})">📤 Vše odneseno</button>
            <button class="vy-btn" onclick="printBon(${o.ucet_id})">🖨️ Tisk bonu</button>
          </div>
        </div>
      `;
    }).join('');

    document.getElementById('last-update').textContent = new Date().toLocaleTimeString('cs-CZ');
  } catch (e) {
    setStatus(false);
    console.error('[Výdej] load failed:', e);
  }
}

loadData();
setInterval(loadData, REFRESH_SEC * 1000);

// Wake-lock pro nepřetržitý provoz
if ('wakeLock' in navigator) {
  let wakeLock = null;
  async function requestWakeLock() { try { wakeLock = await navigator.wakeLock.request('screen'); } catch (e) {} }
  requestWakeLock();
  document.addEventListener('visibilitychange', () => { if (document.visibilityState === 'visible') requestWakeLock(); });
}
</script>
</body>
</html>
