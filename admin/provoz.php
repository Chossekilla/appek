<?php
/**
 * 🆕 v3.0.1 — PROVOZ STANDALONE KIOSK PAGE
 *
 * Otevírá se z adminu (Restaurace → Provoz → "🖥️ Otevřít na druhý monitor").
 * Plně-screen dashboard pro multi-monitor setup: druhý monitor v kanceláři,
 * sales floor, manager office. Žádný admin sidebar / navigace.
 *
 * Co dělá:
 *   - Stejné 4 tiles jako Provoz widget (Stoly + Kuchyně + Rozvoz + POS dnes)
 *   - Auto-refresh každých 30 s (kratší než admin widget, kiosk friendly)
 *   - Tmavý theme (OLED-friendly pro 24/7 displej)
 *   - Hodiny v topbaru — kuchyně vidí jasně kolik je
 *   - Auto-reconnect po internet glitch
 *
 * Vyžaduje admin login (sdílí session s admin/).
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
<title>APPEK Provoz — živý přehled</title>
<style>
* { box-sizing: border-box; margin: 0; padding: 0; }
html, body {
  font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', system-ui, sans-serif;
  background: linear-gradient(135deg, #1a1d24, #2d3441);
  color: #fff;
  height: 100vh;
  overflow: hidden;
}
.provoz-app { display: flex; flex-direction: column; height: 100vh; }

/* ─── HEADER ─── */
.provoz-head {
  display: flex; justify-content: space-between; align-items: center;
  padding: 14px 28px;
  background: rgba(0,0,0,0.3);
  border-bottom: 1px solid rgba(255,255,255,0.08);
}
.provoz-brand { display: flex; align-items: center; gap: 14px; }
.provoz-brand-ic { font-size: 38px; filter: drop-shadow(0 2px 6px rgba(0,0,0,0.5)); }
.provoz-brand-text h1 { font-size: 22px; font-weight: 800; letter-spacing: -0.01em; }
.provoz-brand-text small { font-size: 12px; opacity: 0.6; }
.provoz-clock {
  font-size: 36px; font-weight: 700; font-variant-numeric: tabular-nums;
  letter-spacing: 0.02em; color: #fff;
}
.provoz-clock small { font-size: 12px; opacity: 0.6; font-weight: 400; display: block; text-align: right; margin-top: 2px; }

/* ─── TILES GRID ─── */
.provoz-grid {
  flex: 1;
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(320px, 1fr));
  gap: 18px;
  padding: 22px 28px;
  align-content: start;
}
.tile {
  background: rgba(255,255,255,0.04);
  border: 1px solid rgba(255,255,255,0.08);
  border-radius: 14px;
  padding: 22px 24px;
  position: relative;
  overflow: hidden;
}
.tile-head {
  display: flex; justify-content: space-between; align-items: center;
  margin-bottom: 12px;
}
.tile-label {
  font-size: 12px; text-transform: uppercase; letter-spacing: 0.08em;
  opacity: 0.7; font-weight: 600;
}
.tile-badge {
  font-size: 10px; padding: 3px 8px; border-radius: 6px;
  font-weight: 700; background: rgba(255,255,255,0.1);
}
.tile-badge.is-danger { background: #DC2626; }
.tile-big {
  font-size: 56px; font-weight: 800; line-height: 1;
  letter-spacing: -0.02em;
  font-variant-numeric: tabular-nums;
}
.tile-big-unit { font-size: 16px; opacity: 0.6; font-weight: 500; margin-left: 6px; }
.tile-meta {
  margin-top: 8px;
  font-size: 13px; opacity: 0.7;
}
.tile-bar {
  margin-top: 12px; height: 6px;
  background: rgba(255,255,255,0.08); border-radius: 3px; overflow: hidden;
}
.tile-bar-fill {
  height: 100%;
  border-radius: 3px;
  transition: width 0.5s ease;
}
.tile-accent {
  position: absolute; top: 0; left: 0; bottom: 0; width: 5px;
}

/* ─── ALERTS BAR ─── */
.provoz-alerts {
  padding: 12px 28px;
  background: rgba(220,38,38,0.18);
  border-top: 2px solid #DC2626;
  font-size: 16px; font-weight: 700;
  display: none;
  text-align: center;
}
.provoz-alerts.is-active { display: block; animation: pulse 2s infinite; }
@keyframes pulse { 50% { background: rgba(220,38,38,0.3); } }

/* ─── FOOTER ─── */
.provoz-foot {
  padding: 10px 28px;
  background: rgba(0,0,0,0.4);
  font-size: 11px;
  display: flex; justify-content: space-between; align-items: center;
  opacity: 0.6;
  border-top: 1px solid rgba(255,255,255,0.05);
}
.provoz-foot-status { display: flex; align-items: center; gap: 6px; }
.status-dot {
  width: 8px; height: 8px; border-radius: 50%;
  background: #10B981; box-shadow: 0 0 6px #10B981;
}
.status-dot.is-offline { background: #DC2626; box-shadow: 0 0 6px #DC2626; }
.provoz-foot a { color: inherit; text-decoration: none; opacity: 0.8; }
.provoz-foot a:hover { opacity: 1; text-decoration: underline; }

/* ─── LOADING ─── */
.tile.is-loading .tile-big::after {
  content: '⏳';
  display: inline-block;
  animation: spin 1.5s linear infinite;
}
@keyframes spin { from { transform: rotate(0deg); } to { transform: rotate(360deg); } }
</style>
</head>
<body>

<div class="provoz-app">
  <header class="provoz-head">
    <div class="provoz-brand">
      <span class="provoz-brand-ic">📺</span>
      <div class="provoz-brand-text">
        <h1>APPEK · Provoz</h1>
        <small>Stoly · Kuchyně · Rozvoz · POS dnes — auto-refresh každých 30 s</small>
      </div>
    </div>
    <div class="provoz-clock" id="provoz-clock">
      00:00:00
      <small id="provoz-date">—</small>
    </div>
  </header>

  <main class="provoz-grid" id="provoz-grid">
    <!-- Tiles render via JS -->
    <div class="tile is-loading">
      <div class="tile-head"><span class="tile-label">🪑 Stoly</span></div>
      <div class="tile-big">—</div>
    </div>
    <div class="tile is-loading">
      <div class="tile-head"><span class="tile-label">👨‍🍳 Kuchyně</span></div>
      <div class="tile-big">—</div>
    </div>
    <div class="tile is-loading">
      <div class="tile-head"><span class="tile-label">🛵 Rozvoz</span></div>
      <div class="tile-big">—</div>
    </div>
    <div class="tile is-loading">
      <div class="tile-head"><span class="tile-label">🧾 POS dnes</span></div>
      <div class="tile-big">—</div>
    </div>
  </main>

  <div class="provoz-alerts" id="provoz-alerts"></div>

  <footer class="provoz-foot">
    <div class="provoz-foot-status">
      <span class="status-dot" id="status-dot"></span>
      <span id="status-text">Připojeno</span>
      · Poslední data: <span id="last-update">—</span>
    </div>
    <div>
      APPEK Provoz · v<?= htmlspecialchars($appVersion) ?> ·
      <a href="../admin/" title="Zpět do admin panelu">← Admin</a>
    </div>
  </footer>
</div>

<script>
const REFRESH_SEC = 30; // 30s pro kiosk (kratší než admin widget)
const API = '../api';

// Hodiny
function updateClock() {
  const now = new Date();
  const hh = String(now.getHours()).padStart(2, '0');
  const mm = String(now.getMinutes()).padStart(2, '0');
  const ss = String(now.getSeconds()).padStart(2, '0');
  document.getElementById('provoz-clock').firstChild.nodeValue = hh + ':' + mm + ':' + ss + ' ';
  document.getElementById('provoz-date').textContent = now.toLocaleDateString('cs-CZ', { weekday: 'long', day: 'numeric', month: 'long' });
}
setInterval(updateClock, 1000);
updateClock();

// Data fetch + render
async function loadData() {
  const today = new Date().toISOString().slice(0, 10);
  try {
    const [tables, kitchen, couriers, pos] = await Promise.all([
      fetch(`${API}/admin_tables.php?action=capacity`, {credentials: 'include'}).then(r => r.json()).catch(e => ({error: e.message})),
      fetch(`${API}/admin_kitchen.php`, {credentials: 'include'}).then(r => r.json()).catch(e => ({error: e.message})),
      fetch(`${API}/admin_couriers.php`, {credentials: 'include'}).then(r => r.json()).catch(e => ({error: e.message})),
      fetch(`${API}/admin_pos.php?action=quick_history&date=${today}`, {credentials: 'include'}).then(r => r.json()).catch(e => ({error: e.message})),
    ]);

    setStatus(true);

    const cap = tables?.capacity || {};
    const kStats = kitchen?.stats || {};
    const cStats = couriers?.stats || {};
    const posSum = pos?.souhrn || {};

    const totalMist = parseInt(cap.celkem_mist) || 0;
    const obsMist = parseInt(cap.obsazeno_mist) || 0;
    const obsPct = totalMist > 0 ? Math.round(obsMist / totalMist * 100) : 0;

    const kLoad = parseInt(kStats.global_load) || 0;
    const kFull = !!kStats.is_full;

    const rozvozyAkt = parseInt(cStats.rozvozy_aktivni) || 0;
    const rozvozyDnes = parseInt(cStats.rozvozy_dnes_doruceno) || 0;

    const posTrzby = parseFloat(posSum['tržby']) || 0;
    const posPocet = parseInt(posSum.pocet) || 0;

    const color = (pct) => pct >= 90 ? '#DC2626' : pct >= 70 ? '#F59E0B' : pct >= 40 ? '#3B82F6' : '#10B981';

    document.getElementById('provoz-grid').innerHTML = `
      <div class="tile">
        <div class="tile-accent" style="background:${color(obsPct)}"></div>
        <div class="tile-head"><span class="tile-label">🪑 Stoly</span><span class="tile-badge">${cap.pocet_stolu || 0} stolů</span></div>
        <div class="tile-big">${obsPct}<span class="tile-big-unit">%</span></div>
        <div class="tile-meta">${obsMist} / ${totalMist} míst · ${cap.pocet_obsazenych || 0} obsazených</div>
        <div class="tile-bar"><div class="tile-bar-fill" style="width:${obsPct}%;background:${color(obsPct)}"></div></div>
      </div>
      <div class="tile">
        <div class="tile-accent" style="background:${color(kLoad)}"></div>
        <div class="tile-head"><span class="tile-label">👨‍🍳 Kuchyně</span>${kFull ? '<span class="tile-badge is-danger">PLNÁ</span>' : ''}</div>
        <div class="tile-big">${kLoad}<span class="tile-big-unit">%</span></div>
        <div class="tile-meta">${kStats.active_orders || 0} aktivních · ${kStats.preparing || 0} se vaří · ${kStats.ready || 0} hotových</div>
        <div class="tile-bar"><div class="tile-bar-fill" style="width:${kLoad}%;background:${color(kLoad)}"></div></div>
      </div>
      <div class="tile">
        <div class="tile-accent" style="background:#10B981"></div>
        <div class="tile-head"><span class="tile-label">🛵 Rozvoz</span><span class="tile-badge">${cStats.kuryru_aktivnich || 0} kurýrů</span></div>
        <div class="tile-big">${rozvozyAkt}<span class="tile-big-unit">aktivních</span></div>
        <div class="tile-meta">✓ ${rozvozyDnes} dnes doručeno · ${cStats.rozvozy_dnes_planovano || 0} naplánováno</div>
      </div>
      <div class="tile">
        <div class="tile-accent" style="background:#BA7517"></div>
        <div class="tile-head"><span class="tile-label">🧾 POS dnes</span><span class="tile-badge">${posPocet} účt.</span></div>
        <div class="tile-big">${Math.round(posTrzby).toLocaleString('cs-CZ')}<span class="tile-big-unit">Kč</span></div>
        <div class="tile-meta">💵 ${Math.round(posSum.hotove || 0).toLocaleString('cs-CZ')} · 💳 ${Math.round(posSum.karta || 0).toLocaleString('cs-CZ')}</div>
      </div>
    `;

    // Alerts
    const alerts = document.getElementById('provoz-alerts');
    if (kFull && obsPct >= 90) {
      alerts.className = 'provoz-alerts is-active';
      alerts.textContent = '⚠ KUCHYNĚ I STOLY JSOU PLNÉ — zvažte blokaci nových objednávek';
    } else if (kFull) {
      alerts.className = 'provoz-alerts is-active';
      alerts.textContent = '⚠ KUCHYNĚ JE PLNÁ — auto-block aktivní (pokud nastavené)';
    } else if (obsPct >= 90) {
      alerts.className = 'provoz-alerts is-active';
      alerts.textContent = '⚠ STOLY JSOU SKORO PLNÉ';
    } else {
      alerts.className = 'provoz-alerts';
    }

    document.getElementById('last-update').textContent = new Date().toLocaleTimeString('cs-CZ');
  } catch (e) {
    setStatus(false);
    console.error('[Provoz] load failed:', e);
  }
}

function setStatus(online) {
  const dot = document.getElementById('status-dot');
  const text = document.getElementById('status-text');
  if (online) {
    dot.className = 'status-dot';
    text.textContent = 'Připojeno';
  } else {
    dot.className = 'status-dot is-offline';
    text.textContent = 'Odpojeno — auto-retry…';
  }
}

// Initial load + interval
loadData();
setInterval(loadData, REFRESH_SEC * 1000);

// Wake-lock: zabraň usnutí kiosk monitoru
if ('wakeLock' in navigator) {
  let wakeLock = null;
  async function requestWakeLock() {
    try { wakeLock = await navigator.wakeLock.request('screen'); } catch (e) {}
  }
  requestWakeLock();
  document.addEventListener('visibilitychange', () => {
    if (document.visibilityState === 'visible') requestWakeLock();
  });
}
</script>
</body>
</html>
