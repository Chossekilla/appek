// =============================================================
// 📱 PUSH NOTIFIKACE — admin UI v Nastavení → Notifikace
// =============================================================
let _pushVapidAdmin = null;
let _pushSwAdmin = null;
async function _initPushAdmin() {
  if (!('serviceWorker' in navigator) || !('PushManager' in window)) return;
  try {
    _pushSwAdmin = await navigator.serviceWorker.register('/sw.js', { scope: '/' });
  } catch (e) { console.warn('Admin push SW init failed:', e); }
}
_initPushAdmin();

window.zapnoutPushAdmin = async function() {
  if (!_pushSwAdmin) return alert('Service Worker není aktivní. Obnovte stránku.');
  if (Notification.permission === 'denied') return alert('Notifikace jsou v prohlížeči blokované. Povolte je v nastavení.');
  try {
    if (!_pushVapidAdmin) {
      const r = await fetch(`${API}/push.php?action=vapid_public`);
      const d = await r.json();
      _pushVapidAdmin = d.public_key;
    }
    const sub = await _pushSwAdmin.pushManager.subscribe({
      userVisibleOnly: true,
      applicationServerKey: _b64ToU8a(_pushVapidAdmin),
    });
    await api('push.php?action=subscribe', { method: 'POST', body: JSON.stringify(sub.toJSON()) });
    alert('✅ Notifikace zapnuté pro tohoto adminu.');
    loadPushStats();
  } catch (e) { alert('Chyba: ' + e.message); }
};

function _b64ToU8a(base64) {
  const padding = '='.repeat((4 - base64.length % 4) % 4);
  const b64 = (base64 + padding).replace(/-/g, '+').replace(/_/g, '/');
  const raw = atob(b64);
  const out = new Uint8Array(raw.length);
  for (let i = 0; i < raw.length; i++) out[i] = raw.charCodeAt(i);
  return out;
}

window.pushSendTest = async function() {
  // Test pro aktuální session — najdi vlastní endpoint
  if (!_pushSwAdmin) return alert('Nejdřív klikni „🔔 Zapnout mně".');
  const sub = await _pushSwAdmin.pushManager.getSubscription();
  if (!sub) return alert('Nejprve si zapni push notifikace tlačítkem „🔔 Zapnout mně".');
  try {
    const r = await api('push.php?action=test', {
      method: 'POST',
      body: JSON.stringify({
        endpoint: sub.endpoint,
        title: '🧪 Test push z Appek',
        body: 'Toto je testovací notifikace. Pokud ji vidíš, vše funguje! 🎉',
      }),
    });
    alert(t('test_email_sent', { sent: r.stats.sent, failed: r.stats.failed, expired: r.stats.expired }));
  } catch (e) { alert('Chyba: ' + e.message); }
};

window.pushSendTestAll = async function() {
  if (!(await confirmDialog({ title: 'Poslat testovací push?', msg: 'Notifikace odejde VŠEM subscriberům (odběratelé + admini).', okText: 'Poslat', danger: true }))) return;
  try {
    const r = await api('push.php?action=test', {
      method: 'POST',
      body: JSON.stringify({
        all: true,
        title: '📢 Testovací zpráva',
        body: 'Provoz otestoval push systém. Jen ignoruj. 😊',
      }),
    });
    alert(t('bulk_test_sent', { sent: r.stats.sent, failed: r.stats.failed, expired: r.stats.expired }));
    loadPushStats();
  } catch (e) { alert('Chyba: ' + e.message); }
};

// 📧 v3.0.289 — SMTP odesílání (Nastavení → Notifikace)
window.smtpToggle = function() {
  const on = !!(document.getElementById('smtp-enabled') || {}).checked;
  const f = document.getElementById('smtp-fields'); if (f) f.style.display = on ? '' : 'none';
};
window.smtpLoad = async function() {
  try {
    const n = await api('admin_nastaveni.php');
    const set = (id, v) => { const el = document.getElementById(id); if (el) el.value = v || ''; };
    if (document.getElementById('smtp-enabled')) document.getElementById('smtp-enabled').checked = (n.smtp_enabled === '1' || n.smtp_enabled === 1);
    set('smtp-host', n.smtp_host); set('smtp-port', n.smtp_port || '587');
    set('smtp-user', n.smtp_user); set('smtp-from', n.smtp_from); set('smtp-from-name', n.smtp_from_name);
    const sec = document.getElementById('smtp-secure'); if (sec) sec.value = n.smtp_secure || 'tls';
    const pass = document.getElementById('smtp-pass'); if (pass) pass.value = n.smtp_pass === '••••••••' ? '••••••••' : '';
    smtpToggle();
  } catch (e) { /* ignore */ }
};
window.smtpSave = async function() {
  const v = (id) => (document.getElementById(id) || {}).value || '';
  const passEl = document.getElementById('smtp-pass');
  const payload = {
    smtp_enabled: (document.getElementById('smtp-enabled') || {}).checked ? '1' : '0',
    smtp_host: v('smtp-host').trim(), smtp_port: v('smtp-port') || '587',
    smtp_user: v('smtp-user').trim(), smtp_secure: v('smtp-secure'),
    smtp_from: v('smtp-from').trim(), smtp_from_name: v('smtp-from-name').trim(),
  };
  // heslo posíláme jen když uživatel zadal nové (ne maska) — jinak backend zachová staré
  if (passEl && passEl.value && passEl.value !== '••••••••') payload.smtp_pass = passEl.value;
  try {
    await api('admin_nastaveni.php', { method: 'PUT', body: JSON.stringify(payload) });
    toast(payload.smtp_enabled === '1' ? '✅ SMTP uloženo a zapnuto' : '✅ SMTP uloženo (vypnuto)', 'success');
    const info = document.getElementById('smtp-info');
    if (info) info.textContent = payload.smtp_enabled === '1' ? `Aktivní: ${payload.smtp_host}:${payload.smtp_port}` : 'Vypnuto (nativní mail)';
    if (passEl) passEl.value = payload.smtp_pass ? '••••••••' : passEl.value;
  } catch (e) { toast('❌ ' + (e.message || 'Uložení selhalo'), 'error'); }
};
window.smtpTest = async function() {
  const v = (id) => (document.getElementById(id) || {}).value || '';
  const to = prompt('Kam poslat testovací e-mail?', v('smtp-from') || v('smtp-user'));
  if (!to) return;
  const passEl = document.getElementById('smtp-pass');
  const body = {
    host: v('smtp-host').trim(), port: v('smtp-port') || '587', user: v('smtp-user').trim(),
    secure: v('smtp-secure'), from: v('smtp-from').trim(), from_name: v('smtp-from-name').trim(), to: to.trim(),
  };
  if (passEl && passEl.value && passEl.value !== '••••••••') body.pass = passEl.value;
  const info = document.getElementById('smtp-info'); if (info) info.textContent = '⏳ Odesílám…';
  const logEl = document.getElementById('smtp-log');
  try {
    const r = await api('admin_smtp_test.php', { method: 'POST', body: JSON.stringify(body) });
    if (logEl) { logEl.style.display = 'block'; logEl.textContent = (r.log || []).join('\n'); }
    if (r.ok) { toast('✅ ' + r.message, 'success'); if (info) info.textContent = '✅ ' + r.message; }
    else { toast('❌ SMTP: ' + (r.error || 'chyba'), 'error'); if (info) info.textContent = '❌ ' + (r.error || 'chyba'); }
  } catch (e) {
    if (info) info.textContent = '❌ ' + (e.message || 'Test selhal');
    toast('❌ ' + (e.message || 'Test selhal'), 'error');
  }
};

window.loadPushStats = async function() {
  const host = document.getElementById('push-stats-host');
  if (!host) return;
  try {
    const d = await api('push.php?action=stats');
    host.innerHTML = `
      <div style="display:flex;flex-wrap:wrap;gap:12px;margin-bottom:14px">
        <div class="stat-card" style="flex:1;min-width:140px;padding:10px 14px">
          <div class="stat-label">Aktivních subscriberů</div>
          <div class="stat-value" style="font-size:24px">${d.total}</div>
        </div>
        <div class="stat-card" style="flex:1;min-width:140px;padding:10px 14px">
          <div class="stat-label">Odběratelé</div>
          <div class="stat-value" style="font-size:24px;color:var(--success-text)">${d.odberatele_sub}</div>
        </div>
        <div class="stat-card" style="flex:1;min-width:140px;padding:10px 14px">
          <div class="stat-label">Admini</div>
          <div class="stat-value" style="font-size:24px;color:var(--primary)">${d.admins_sub}</div>
        </div>
        <div class="stat-card" style="flex:1;min-width:140px;padding:10px 14px">
          <div class="stat-label">Tento měsíc odesláno</div>
          <div class="stat-value" style="font-size:24px">${d.tento_mesic}</div>
        </div>
      </div>
      ${d.subscriptions.length > 0 ? `
        <details style="margin-top:8px">
          <summary style="cursor:pointer;font-weight:600;font-size:13px;padding:6px 0">📋 Seznam subscriberů (${d.subscriptions.length}) — klik rozbalí</summary>
          <table class="table" style="margin-top:8px;font-size:12px">
            <thead><tr><th>Kdo</th><th>Browser</th><th>Vytvořeno</th><th>Posl. push</th><th>Chyby</th></tr></thead>
            <tbody>
              ${d.subscriptions.map(s => `
                <tr>
                  <td>${s.odberatel_nazev ? '👥 ' + esc(s.odberatel_nazev) : (s.admin_email ? '🔑 ' + esc(s.admin_email) : '—')}</td>
                  <td style="font-size:11px;color:var(--text-3);max-width:240px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap">${esc((s.user_agent || '').slice(0, 80))}</td>
                  <td>${fmtDate(s.vytvoreno)}</td>
                  <td>${s.posledni_push ? fmtDateTime(s.posledni_push) : '—'}</td>
                  <td class="num">${s.chyba_count > 0 ? `<span style="color:var(--danger-text);font-weight:600">⚠ ${s.chyba_count}</span>` : '<span style="color:var(--success-text)">✓</span>'}</td>
                </tr>
              `).join('')}
            </tbody>
          </table>
        </details>
      ` : '<div style="color:var(--text-3);font-size:13px;text-align:center;padding:14px">Zatím žádní subscriberi. První se přihlásí poté co odběratel zapne notifikace v B2B aplikaci.</div>'}
    `;
  } catch (e) {
    host.innerHTML = `<div style="color:var(--danger-text)">Chyba: ${esc(e.message)}</div>`;
  }
};

