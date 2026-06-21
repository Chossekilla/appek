// =============================================================
// 🔄 WEBHOOKS UI
// =============================================================
window._webhookEvents = null;

window.loadWebhooks = async function() {
  const host = document.getElementById('ns-webhooks-list');
  if (!host) return;
  host.innerHTML = '⏳ Načítám…';
  try {
    if (!window._webhookEvents) {
      const ev = await api('admin_webhooks.php?action=events');
      window._webhookEvents = ev.events || {};
    }
    const r = await api('admin_webhooks.php?action=list');
    const hooks = r.webhooks || [];
    if (hooks.length === 0) {
      host.innerHTML = `<div class="empty-state" style="padding:14px">📡 Žádné webhooks. Klikni „+ Nový webhook".</div>`;
      return;
    }
    host.innerHTML = `<div style="display:flex;flex-direction:column;gap:8px">${hooks.map(h => {
      const failRate = h.pocet_volani > 0 ? Math.round((h.pocet_selhani / h.pocet_volani) * 100) : 0;
      const events = (h.events || '').split(',').map(e => e.trim()).filter(Boolean);
      return `
        <div style="background:var(--surface-2);padding:12px 14px;border-radius:10px;display:flex;gap:14px;align-items:center;flex-wrap:wrap;border:1px solid var(--border)">
          <div style="font-size:18px">${h.aktivni == 1 ? '🟢' : '⚫'}</div>
          <div style="flex:1;min-width:200px">
            <div style="font-weight:600;font-size:13.5px">${esc(h.nazev)}</div>
            <div style="font-size:11px;color:var(--text-3);font-family:monospace;word-break:break-all">${esc(h.url)}</div>
            <div style="margin-top:4px;display:flex;gap:4px;flex-wrap:wrap">${events.slice(0, 4).map(e =>
              `<span style="background:#DBEAFE;color:#1e40af;padding:1px 7px;border-radius:999px;font-size:10.5px;font-weight:600">${esc(window._webhookEvents[e] || e)}</span>`
            ).join('')}${events.length > 4 ? `<span style="color:var(--text-3);font-size:10.5px">+${events.length - 4}</span>` : ''}</div>
          </div>
          <div style="text-align:right;font-size:11px">
            <div>${h.pocet_volani} volání · ${failRate}% selhání</div>
            ${h.last_call_at ? `<div style="color:var(--text-3)">Naposled: ${esc(fmtAgo(h.last_call_at))}</div>` : ''}
          </div>
          <div style="display:flex;gap:4px">
            ${adminOnly(`<button class="btn-icon" onclick="testWebhook(${h.id})" title="Test fire">🚀</button>`)}
            ${adminOnly(`<button class="btn-icon" onclick="openWebhookLog(${h.id})" title="Log">📜</button>`)}
            ${adminOnly(`<button class="btn-icon" onclick="openWebhookEdit(${h.id})" title="Upravit">✏️</button>`)}
            ${adminOnly(`<button class="btn-icon" onclick="deleteWebhook(${h.id})" title="Smazat">🗑️</button>`)}
          </div>
        </div>
      `;
    }).join('')}</div>`;
  } catch (e) {
    host.innerHTML = `<div class="empty-state" style="color:var(--danger-text)">Chyba: ${esc(e.message)}</div>`;
  }
};

window.openWebhookEdit = async function(id = null) {
  let h = { nazev: '', url: '', events: '', secret: '', aktivni: 1 };
  if (id) {
    try {
      const r = await api('admin_webhooks.php?action=list');
      h = (r.webhooks || []).find(x => x.id == id) || h;
    } catch (e) { return alert('Chyba: ' + e.message); }
  }
  if (!window._webhookEvents) {
    try { const ev = await api('admin_webhooks.php?action=events'); window._webhookEvents = ev.events || {}; }
    catch (e) {}
  }
  const evs = Object.entries(window._webhookEvents || {});
  const hEvents = (h.events || '').split(',').map(e => e.trim());

  openModal(id ? '✏️ Upravit webhook' : '+ Nový webhook', `
    <div class="form-grid form-grid-tight">
      <div class="full">
        <label class="form-label">Název *</label>
        <input class="form-input" id="wh-nazev" value="${esc(h.nazev)}" placeholder="např. Money S3 sync">
      </div>
      <div class="full">
        <label class="form-label">URL *</label>
        <input class="form-input" id="wh-url" type="url" value="${esc(h.url)}" placeholder="https://hooks.mojesluzba.cz/appek">
      </div>
      <div class="full">
        <label class="form-label">Events *</label>
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:6px;max-height:240px;overflow:auto;background:var(--surface-2);padding:10px;border-radius:8px">
          ${evs.map(([key, label]) => `
            <label style="display:flex;gap:8px;align-items:center;font-size:12.5px;padding:4px 6px;border-radius:4px;cursor:pointer">
              <input type="checkbox" value="${esc(key)}" ${hEvents.includes(key) ? 'checked' : ''} class="wh-event-cb">
              <span>${esc(label)}</span>
            </label>
          `).join('')}
        </div>
      </div>
      <div class="full">
        <label class="form-label">Secret (HMAC SHA256 podpis v hlavičce <code>X-Webhook-Signature</code>)</label>
        <input class="form-input" id="wh-secret" value="${esc(h.secret || '')}" placeholder="(volitelné) náhodný string pro ověření">
      </div>
      <div class="full">
        <label class="checkbox-row" style="display:flex;align-items:center;gap:10px;padding:10px 12px;background:var(--surface-2);border-radius:8px;cursor:pointer">
          <input type="checkbox" id="wh-aktivni" ${h.aktivni == 1 ? 'checked' : ''} style="width:18px;height:18px">
          <span>🟢 Aktivní</span>
        </label>
      </div>
    </div>
    <div class="form-actions">
      <button class="btn-secondary" onclick="closeModal()">Zrušit</button>
      <div style="flex:1"></div>
      <button class="btn-primary btn-green" onclick="saveWebhook(${id || 'null'})">💾 Uložit</button>
    </div>
  `, 'wide');
};

window.saveWebhook = async function(id) {
  const events = Array.from(document.querySelectorAll('.wh-event-cb:checked')).map(el => el.value);
  const data = {
    nazev:   document.getElementById('wh-nazev').value.trim(),
    url:     document.getElementById('wh-url').value.trim(),
    events:  events.join(','),
    secret:  document.getElementById('wh-secret').value.trim(),
    aktivni: document.getElementById('wh-aktivni').checked ? 1 : 0,
  };
  if (!data.nazev || !data.url || !events.length) { alert('Vyplň název, URL a aspoň jeden event.'); return; }
  try {
    if (id) {
      await api('admin_webhooks.php?id=' + id, { method: 'PUT', body: JSON.stringify(data) });
    } else {
      await api('admin_webhooks.php', { method: 'POST', body: JSON.stringify(data) });
    }
    closeModal();
    toastSuccess('Webhook uložen');
    loadWebhooks();
  } catch (e) { alert('Chyba: ' + e.message); }
};

window.testWebhook = async function(id) {
  try {
    await api('admin_webhooks.php?action=test&id=' + id, { method: 'POST' });
    toastSuccess('🚀 Test event odeslán — zkontroluj log');
    setTimeout(loadWebhooks, 1500);
  } catch (e) { alert('Chyba: ' + e.message); }
};

window.deleteWebhook = async function(id) {
  if (!(await confirmDialog({
    title: 'Smazat webhook?',
    msg: 'Smaže i log historie. Akce je nevratná.',
    danger: true, okText: 'Smazat',
  }))) return;
  try {
    await api('admin_webhooks.php?id=' + id, { method: 'DELETE' });
    toastSuccess('Webhook smazán');
    loadWebhooks();
  } catch (e) { alert('Chyba: ' + e.message); }
};

window.openWebhookLog = async function(id) {
  try {
    const r = await api('admin_webhooks.php?action=log&id=' + id + '&limit=50');
    const log = r.log || [];
    openModal('📜 Webhook log', `
      ${log.length === 0 ? '<div class="empty-state" style="padding:14px">Žádný log.</div>' :
        `<div style="max-height:60vh;overflow:auto">${log.map(l => `
          <div style="padding:8px 10px;border-bottom:1px solid var(--border);font-size:12px">
            <div style="display:flex;justify-content:space-between;gap:10px;flex-wrap:wrap">
              <strong>${esc(l.event)}</strong>
              <span style="${l.http_status >= 200 && l.http_status < 300 ? 'color:var(--success-text)' : 'color:var(--danger-text)'}">HTTP ${l.http_status || '—'} · ${l.duration_ms}ms</span>
            </div>
            <div style="font-size:10.5px;color:var(--text-3);margin-top:2px">${esc(l.created_at)}</div>
            ${l.error_msg ? `<div style="background:var(--danger-bg);color:var(--danger-text);padding:6px;border-radius:4px;margin-top:4px;font-size:11px">${esc(l.error_msg)}</div>` : ''}
            ${l.payload_short ? `<details style="margin-top:4px"><summary style="cursor:pointer;font-size:11px;color:var(--text-3)">Payload</summary><pre style="background:var(--surface-2);padding:6px;border-radius:4px;font-size:10.5px;margin-top:4px;white-space:pre-wrap;word-break:break-all">${esc(l.payload_short)}</pre></details>` : ''}
          </div>
        `).join('')}</div>`}
    `, 'wide');
  } catch (e) { alert('Chyba: ' + e.message); }
};

