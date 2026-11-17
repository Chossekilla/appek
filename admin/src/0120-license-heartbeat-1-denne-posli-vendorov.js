// =============================================================
// 📡 LICENSE HEARTBEAT — 1× denně pošli vendorovi technickou statistiku
// =============================================================
// Server-side endpoint: /api/license_heartbeat.php → POSTuje na vendor.appek.cz
// Customer admin spustí trigger jednou denně (localStorage chrání před spammen).
// Pokud vendor odpoví status:pirate → renderPirateNagBanner() ukáže warning.
// 🐛 v3.0.644 — `force` = spusť heartbeat i když dnes už proběhl (po zaplacení pronájmu,
//   po vložení nového klíče, po 402/423 ze serveru). Dřív se zámek uvolnil až další den.
async function triggerLicenseHeartbeatIfDue(force) {
  try {
    const HB_KEY = 'appek_heartbeat_last_date';
    const today = new Date().toYMD(); // YYYY-MM-DD
    let lastDate = null;
    try { lastDate = localStorage.getItem(HB_KEY); } catch (e) {}
    // Pokud už dnes proběhl, jen zkontroluj cached pirate flag (file → JS doesn't see; check via API)
    if (lastDate === today && !force) {
      // Quick check: server-side flag — admin_pirate_status.php má cache i bez heartbeat trigger
      try {
        const r = await api('admin_pirate_status.php');
        if (r && r.pirate_flag) renderPirateNagBanner(r);
        else if (r && r.key_superseded) renderKeySupersededBanner(r);
      } catch (e) { /* endpoint může chybět na starší instalaci — ignore */ }
      return null;
    }
    // Spusť real heartbeat
    const r = await api('license_heartbeat.php', { method: 'POST', body: '{}' });
    if (r && r.status !== 'throttled') { try { localStorage.setItem(HB_KEY, today); } catch (e) {} }
    if (r && r.status === 'pirate') {
      renderPirateNagBanner(r);
    } else if (r && r.status === 'superseded') {
      renderKeySupersededBanner(r);
    } else {
      // Pokud existoval banner z předchozího pirate flagu a teď je clean → odstraň
      const ex = document.getElementById('appek-pirate-nag');
      if (ex) ex.remove();
    }
    return r;
  } catch (e) {
    console.warn('[APPEK] heartbeat failed:', e.message || e);
    return null;
  }
}

// 🐛 v3.0.644 — server vrátil 402 (pronájem po grace) / 423 (licence locked/revoked).
//   Vynuť heartbeat (max 1× za 60 s) — když se mezitím zaplatilo / vyřešilo u dodavatele,
//   stránka se rovnou obnoví a appka jede. Jinak ukáže zámek s možností ověřit znovu / vložit klíč.
window._licenseLockRecheck = async function(status, opts) {
  opts = opts || {};
  const TKEY = 'appek_lock_recheck_at';
  let last = 0;
  try { last = parseInt(sessionStorage.getItem(TKEY) || '0', 10) || 0; } catch (e) {}
  if (!opts.manual && Date.now() - last < 60000) {
    if (status === 402) renderRentalLockOverlay();
    return;
  }
  if (window._licenseRecheckBusy) return;
  window._licenseRecheckBusy = true;
  try { sessionStorage.setItem(TKEY, String(Date.now())); } catch (e) {}
  let r = null;
  try { r = await triggerLicenseHeartbeatIfDue(true); } finally { window._licenseRecheckBusy = false; }
  if (r && r.full_lock === false && r.enforce_ok !== false && r.status !== 'pirate' && r.status !== 'throttled') {
    location.reload();   // odemčeno → načti appku znovu (overlaye pryč, data čerstvá)
    return;
  }
  if (r && r.status === 'pirate') return;           // overlay/banner už vykreslil heartbeat
  if (status === 423 && !(r && r.status === 'pirate')) {
    renderLicenseLockOverlay({ reason: (r && r.reason) || 'locked', message: (r && r.message) || '' });
    return;
  }
  renderRentalLockOverlay(opts.manual ? (r && r.status === 'throttled' ? r.message : 'Platba zatím nebyla potvrzena. Pokud jste právě zaplatili, zkuste to za chvíli znovu.') : '');
};

// 🐛 v3.0.644 — zámek PRONÁJMU (402) s tlačítkem „Ověřit platbu" (dřív jen chybové hlášky do zítřka).
function renderRentalLockOverlay(note) {
  let o = document.getElementById('appek-rental-lock');
  if (!o) {
    o = document.createElement('div');
    o.id = 'appek-rental-lock';
    o.style.cssText = 'position:fixed;inset:0;z-index:999998;background:rgba(15,23,42,0.78);display:flex;align-items:center;justify-content:center;padding:16px;font-family:-apple-system,BlinkMacSystemFont,sans-serif';
    o.innerHTML = `
      <div style="background:#fff;max-width:480px;width:100%;border-radius:16px;padding:28px 24px;text-align:center;box-shadow:0 30px 80px rgba(0,0,0,0.45)">
        <div style="font-size:48px;margin-bottom:10px">🗓️</div>
        <h1 style="font-size:21px;font-weight:800;color:#991B1B;margin:0 0 8px">Pronájem vypršel</h1>
        <p style="font-size:13.5px;color:#374151;line-height:1.55;margin:0 0 16px">Vaše data zůstávají v bezpečí. Po prodloužení na my.appek.cz klikněte na <strong>Ověřit platbu</strong> — appka se odemkne hned.</p>
        <div id="appek-rental-lock-note" style="font-size:12.5px;color:#92400E;margin-bottom:12px"></div>
        <div style="display:flex;gap:10px;justify-content:center;flex-wrap:wrap">
          <a href="https://my.appek.cz" target="_blank" rel="noopener" style="padding:11px 18px;background:#0071e3;color:#fff;border-radius:10px;text-decoration:none;font-weight:700;font-size:14px">Prodloužit na my.appek.cz</a>
          <button type="button" id="appek-rental-lock-check" style="padding:11px 18px;background:#16a34a;color:#fff;border:none;border-radius:10px;font-weight:700;font-size:14px;cursor:pointer">Ověřit platbu</button>
        </div>
      </div>`;
    document.body.appendChild(o);
    o.querySelector('#appek-rental-lock-check').addEventListener('click', async (ev) => {
      const b = ev.currentTarget; b.disabled = true; b.textContent = 'Ověřuji…';
      try { await window._licenseLockRecheck(402, { manual: true }); }
      finally { b.disabled = false; b.textContent = 'Ověřit platbu'; }
    });
  }
  const n = o.querySelector('#appek-rental-lock-note');
  if (n) n.textContent = note || '';
}

// 🐛 v3.0.644 — starý klíč po reissue (dodavatel přidal/odebral balíček) → NE zámek, jen výzva.
function renderKeySupersededBanner(payload) {
  if (document.getElementById('appek-key-superseded')) return;
  const b = document.createElement('div');
  b.id = 'appek-key-superseded';
  b.style.cssText = 'position:fixed;left:12px;right:12px;bottom:12px;z-index:99990;background:#FEF3C7;border:1px solid #F59E0B;color:#78350F;border-radius:12px;padding:12px 14px;display:flex;gap:10px;align-items:center;flex-wrap:wrap;font-size:13px;box-shadow:0 10px 30px rgba(0,0,0,0.18)';
  b.innerHTML = `
    <span style="flex:1;min-width:200px">🔑 <strong>Dodavatel vydal nový licenční klíč.</strong> ${esc((payload && payload.message) || 'Vložte ho, ať se projeví změna balíčků.')}</span>
    <button type="button" class="btn-primary" style="font-size:12px;padding:6px 12px" onclick="openLicenseUpdate()">Vložit nový klíč</button>
    <button type="button" class="btn-secondary" style="font-size:12px;padding:6px 10px" onclick="this.parentNode.remove()">Později</button>`;
  document.body.appendChild(b);
}

// 🐛 v3.0.644 — vložení nového klíče PŘÍMO v zámku (modal openLicenseUpdate je pod overlayem).
async function _licenseLockSubmitKey() {
  const inp = document.getElementById('appek-lock-key');
  const msg = document.getElementById('appek-lock-key-msg');
  const key = (inp && inp.value || '').trim().toUpperCase();
  if (!key) { if (msg) msg.textContent = 'Zadejte licenční klíč.'; return; }
  if (msg) msg.textContent = 'Ukládám a ověřuji…';
  try {
    await api('admin_license_update.php', { method: 'POST', body: JSON.stringify({ license_key: key }) });
    try { localStorage.removeItem('appek_heartbeat_last_date'); sessionStorage.removeItem('appek_lock_recheck_at'); } catch (e) {}
    const r = await triggerLicenseHeartbeatIfDue(true);
    if (r && r.status === 'pirate') {
      if (msg) msg.textContent = 'Klíč je uložený, ale dodavatel ho zatím neověřil: ' + (r.message || r.reason || '');
      return;
    }
    location.reload();
  } catch (e) {
    if (msg) msg.textContent = 'Chyba: ' + (e.message || e);
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
    locked:         'Licence je zablokovaná',
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
        nebo telefon <a href="tel:+420774599622" style="color:#7F1D1D;font-weight:600;text-decoration:none">774 599 622</a>
      </div>
      <!-- 🐛 v3.0.644 — nový klíč jde vložit přímo tady (dřív byl modal pod overlayem → zákazník bez cesty ven) -->
      <div style="margin-top:16px;padding-top:14px;border-top:1px solid #eee;text-align:left">
        <label for="appek-lock-key" style="font-size:12.5px;font-weight:700;color:#1d1d1f">Máte nový licenční klíč od dodavatele?</label>
        <div style="display:flex;gap:8px;margin-top:6px;flex-wrap:wrap">
          <input id="appek-lock-key" autocomplete="off" placeholder="APPEK-XXXX-XXXX-XXXX-XXXX-XXXX"
                 style="flex:1;min-width:0;padding:9px 10px;border:1px solid #d1d5db;border-radius:8px;font-family:Menlo,monospace;font-size:13px;text-transform:uppercase">
          <button type="button" id="appek-lock-key-btn" style="padding:9px 14px;background:#16a34a;color:#fff;border:none;border-radius:8px;font-weight:700;font-size:13px;cursor:pointer">Uložit klíč</button>
        </div>
        <div id="appek-lock-key-msg" style="font-size:12px;color:#7F1D1D;margin-top:6px;min-height:1em"></div>
      </div>
      <div style="font-size:11px;color:#86868b;margin-top:14px;font-family:monospace">
        Doména: ${location.hostname}<br>
        Reason: ${payload.reason || 'unknown'}
      </div>
    </div>
  `;
  document.body.appendChild(overlay);
  document.body.style.overflow = 'hidden';
  try {
    overlay.querySelector('#appek-lock-key-btn').addEventListener('click', _licenseLockSubmitKey);
    overlay.querySelector('#appek-lock-key').addEventListener('keydown', (ev) => { if (ev.key === 'Enter') _licenseLockSubmitKey(); });
  } catch (e) {}

  // 🛡️ v3.0.471 — anti-tamper: obnov lock po pokusu ho skrýt/odstranit (display:none,
  //   visibility, opacity, remove z DOM). Klientský zámek = „friction not security",
  //   ale tohle zabrání triviálnímu obejití přes DevTools (jen nastavit display:none).
  const reassertLock = () => {
    const o = document.getElementById('appek-license-lock');
    if (!o) { document.body.appendChild(overlay); return; }   // znovu připoj, když byl odstraněn
    o.style.setProperty('display', 'flex', 'important');
    o.style.setProperty('visibility', 'visible', 'important');
    o.style.setProperty('opacity', '1', 'important');
    o.style.setProperty('pointer-events', 'auto', 'important');
    o.style.setProperty('position', 'fixed', 'important');
    o.style.setProperty('inset', '0', 'important');
    o.style.setProperty('z-index', '2147483647', 'important');
    document.body.style.overflow = 'hidden';
  };
  reassertLock();
  try {
    new MutationObserver(reassertLock).observe(overlay, { attributes: true, attributeFilter: ['style', 'class'] });
    new MutationObserver(reassertLock).observe(document.body, { childList: true });
  } catch (e) {}
  setInterval(reassertLock, 1000);   // backstop, i kdyby observery odpojili
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

