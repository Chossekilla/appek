// =============================================================
// 📡 LICENSE HEARTBEAT — 1× denně pošli vendorovi technickou statistiku
// =============================================================
// Server-side endpoint: /api/license_heartbeat.php → POSTuje na vendor.appek.cz
// Customer admin spustí trigger jednou denně (localStorage chrání před spammen).
// Pokud vendor odpoví status:pirate → renderPirateNagBanner() ukáže warning.
async function triggerLicenseHeartbeatIfDue() {
  try {
    const HB_KEY = 'appek_heartbeat_last_date';
    const today = new Date().toISOString().slice(0, 10); // YYYY-MM-DD
    const lastDate = localStorage.getItem(HB_KEY);
    // Pokud už dnes proběhl, jen zkontroluj cached pirate flag (file → JS doesn't see; check via API)
    if (lastDate === today) {
      // Quick check: server-side flag — admin_pirate_status.php má cache i bez heartbeat trigger
      try {
        const r = await api('admin_pirate_status.php');
        if (r && r.pirate_flag) renderPirateNagBanner(r);
      } catch (e) { /* endpoint může chybět na starší instalaci — ignore */ }
      return;
    }
    // Spusť real heartbeat
    const r = await api('license_heartbeat.php', { method: 'POST', body: '{}' });
    localStorage.setItem(HB_KEY, today);
    if (r && r.status === 'pirate') {
      renderPirateNagBanner(r);
    } else {
      // Pokud existoval banner z předchozího pirate flagu a teď je clean → odstraň
      const ex = document.getElementById('appek-pirate-nag');
      if (ex) ex.remove();
    }
  } catch (e) {
    console.warn('[APPEK] heartbeat failed:', e.message || e);
  }
}

// 🆕 v2.6.1 — Anti-piracy HARD LOCK overlay
//    Pro vážné reasons (key_reuse, revoked, expired, unknown) zobrazí fullscreen blok.
//    Customer admin nemůže používat dokud nekontaktuje vendora.
function renderLicenseLockOverlay(payload) {
  if (document.getElementById('appek-license-lock')) return;
  const reasonLabels = {
    no_key:         'Chybí licenční klíč',
    invalid_format: 'Neplatný formát licenčního klíče',
    unknown_key:    'Licenční klíč není v naší databázi',
    key_reuse:      'Tento klíč je registrovaný pro jinou doménu',
    revoked_used:   'Tento klíč byl odvolán',
    expired_used:   'Vaše licence vypršela',
  };
  const reason = reasonLabels[payload.reason] || 'Problém s licencí';
  const overlay = document.createElement('div');
  overlay.id = 'appek-license-lock';
  overlay.style.cssText = `
    position: fixed; inset: 0; z-index: 999999;
    background: rgba(20, 0, 0, 0.85);
    backdrop-filter: blur(8px);
    -webkit-backdrop-filter: blur(8px);
    display: flex; align-items: center; justify-content: center;
    font-family: -apple-system, BlinkMacSystemFont, 'SF Pro Display', sans-serif;
  `;
  overlay.innerHTML = `
    <div style="background:#fff;max-width:520px;width:90%;border-radius:18px;padding:36px 30px;box-shadow:0 30px 80px rgba(0,0,0,0.5);text-align:center">
      <div style="font-size:60px;margin-bottom:14px">🔒</div>
      <h1 style="font-size:24px;font-weight:800;color:#991B1B;margin:0 0 8px">Licence zablokována</h1>
      <p style="font-size:15px;color:#1d1d1f;margin:0 0 4px;font-weight:600">${reason}</p>
      <p style="font-size:13px;color:#6e6e73;line-height:1.55;margin:14px 0 18px">
        ${payload.message ? payload.message.replace(/[<>]/g, '') : 'Tato instalace byla deaktivována. Pro obnovení kontaktujte dodavatele systému.'}
      </p>
      <div style="background:#FEE2E2;border:1px solid #FCA5A5;border-radius:10px;padding:12px 14px;margin-bottom:14px;text-align:left;font-size:12.5px;color:#7F1D1D;line-height:1.6">
        <strong>Možné důvody:</strong><br>
        • Klíč byl použit z více domén (anti-piracy)<br>
        • Klíč byl odvolán dodavatelem<br>
        • Licence vypršela bez obnovení
      </div>
      <a href="mailto:support@appek.cz?subject=Licence%20zablokována%20-%20${encodeURIComponent(location.hostname)}"
         style="display:inline-block;padding:12px 24px;background:linear-gradient(135deg,#BA7517,#854F0B);color:#fff;text-decoration:none;border-radius:10px;font-weight:700;font-size:14px;margin-bottom:8px">
        ✉️ Kontaktovat dodavatele
      </a>
      <!-- 🆕 v2.9.280 — podpora telefonem (jen pro kritické situace) -->
      <div style="font-size:12px;color:#7F1D1D;margin-top:6px">
        nebo telefon <a href="tel:+420733700808" style="color:#7F1D1D;font-weight:600;text-decoration:none">733 700 808</a>
      </div>
      <div style="font-size:11px;color:#86868b;margin-top:14px;font-family:monospace">
        Doména: ${location.hostname}<br>
        Reason: ${payload.reason || 'unknown'}
      </div>
    </div>
  `;
  document.body.appendChild(overlay);
  document.body.style.overflow = 'hidden';
}

function renderPirateNagBanner(payload) {
  // 🆕 v2.6.1 — Pro vážné reasons použij HARD LOCK overlay (ne banner)
  const hardLockReasons = ['key_reuse', 'revoked_used', 'expired_used', 'unknown_key'];
  if (hardLockReasons.includes(payload.reason)) {
    renderLicenseLockOverlay(payload);
    return;
  }
  if (document.getElementById('appek-pirate-nag')) return;
  const reasonLabels = {
    no_key:         { title: 'Chybí licenční klíč', cta: 'Kontaktovat dodavatele' },
    invalid_format: { title: 'Neplatný licenční klíč', cta: 'Zadat správný klíč' },
    unknown_key:    { title: 'Licenční klíč není v naší databázi', cta: 'Ověřit klíč u dodavatele' },
    key_reuse:      { title: 'Tento klíč je registrovaný pro jinou doménu', cta: 'Kontaktovat dodavatele' },
    revoked_used:   { title: 'Tento klíč byl odvolán',     cta: 'Obnovit licenci' },
    expired_used:   { title: 'Vaše licence vypršela',      cta: 'Obnovit licenci' },
  };
  const info = reasonLabels[payload.reason] || { title: 'Problém s licencí', cta: 'Více info' };
  const banner = document.createElement('div');
  banner.id = 'appek-pirate-nag';
  banner.style.cssText = `
    position: fixed; top: 0; left: 0; right: 0; z-index: 99999;
    background: linear-gradient(135deg, #DC2626, #991B1B);
    color: #fff; padding: 14px 22px; font-size: 14px; font-weight: 600;
    display: flex; align-items: center; gap: 14px; flex-wrap: wrap;
    box-shadow: 0 6px 20px rgba(220,38,38,0.4);
  `;
  banner.innerHTML = `
    <span style="font-size: 22px">🏴‍☠️</span>
    <div style="flex: 1; min-width: 240px">
      <div style="font-weight: 800; font-size: 15px">${info.title}</div>
      <div style="font-weight: 500; font-size: 12.5px; opacity: 0.95; margin-top: 2px">
        ${payload.message || 'Pro plnou funkčnost je třeba platná licence.'}
      </div>
    </div>
    <a href="mailto:support@appek.cz?subject=Licence%20-%20${encodeURIComponent(location.hostname)}"
       style="background: #fff; color: #DC2626; padding: 8px 16px; border-radius: 8px; text-decoration: none; font-weight: 700; font-size: 12.5px; white-space: nowrap">
      ✉️ ${info.cta}
    </a>
    <button onclick="document.getElementById('appek-pirate-nag').style.display='none'"
            style="background: rgba(255,255,255,0.18); border: none; color: #fff; padding: 6px 10px; border-radius: 6px; cursor: pointer; font-size: 11px">
      Skrýt
    </button>
  `;
  document.body.appendChild(banner);
  // Posuň body padding aby topbar nebyl překrytý
  document.body.style.paddingTop = (banner.offsetHeight) + 'px';
}

// Poll badge každých 60s, fresh generování jednou za 5 min
// 🆕 v2.0.71 — IMMEDIATE fresh při startu (aby update notif vyskočila hned po loginu)
window._lastFreshGen = 0;
function startNotifPolling() {
  if (window._notifPollTimer) return;
  // 🆕 v2.9.305 — pro POS-only roli skryj bell úplně (POS user nemůže reagovat na
  // notifikace typu "nová objednávka" → klick by ho stejně přesměroval na dashboard).
  const role = state.admin?.role || 'admin';
  if (role === 'pos') {
    const btn = document.getElementById('btn-notifications');
    if (btn) btn.style.display = 'none';
    return;
  }
  // První volání = ?fresh=1 → server detekuje update + emit notifikaci
  refreshNotifBadge(true);
  window._lastFreshGen = Date.now();
  // 🆕 Druhé volání za 3s — kontrola jestli mezitím přibyla update notif
  setTimeout(() => refreshNotifBadge(false), 3000);
  window._notifPollTimer = setInterval(() => {
    const triggerFresh = (Date.now() - window._lastFreshGen) > 5 * 60 * 1000;
    if (triggerFresh) window._lastFreshGen = Date.now();
    refreshNotifBadge(triggerFresh);
  }, 60 * 1000);
}

