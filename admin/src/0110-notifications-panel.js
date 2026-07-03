// =============================================================
// 🔔 NOTIFICATIONS PANEL
// =============================================================
window._notifPanelEl = null;
window._notifPollTimer = null;

function fmtAgo(iso) {
  const d = new Date(iso.replace(' ', 'T'));
  const diff = Math.floor((Date.now() - d.getTime()) / 1000);
  if (diff < 60) return `${diff}s`;
  if (diff < 3600) return `${Math.floor(diff/60)} min`;
  if (diff < 86400) return `${Math.floor(diff/3600)} h`;
  return `${Math.floor(diff/86400)} d`;
}

// 🆕 v3.0.160 — globální vypnutí notifikací (per-device, localStorage). Toggle ve zvonku.
window.notifMuted = () => { try { return localStorage.getItem('appek_notif_muted') === '1'; } catch (e) { return false; } };
window.refreshNotifBadge = async function(triggerFresh = false) {
  if (window.notifMuted()) {
    const b = document.getElementById('btn-notifications');
    if (b) b.classList.remove('has-unread');
    return;
  }
  try {
    const r = await api('admin_notifications.php' + (triggerFresh ? '?fresh=1' : ''));
    const badge = document.getElementById('notif-badge');
    const btn = document.getElementById('btn-notifications');
    if (!btn) return;
    const n = r.unread_count || 0;
    // 🆕 v2.9.21 — Notifikace: JEN barva ikony (no count badge)
    if (badge) badge.style.display = 'none';   // count vždy skrytý
    if (n > 0) {
      btn.classList.add('has-unread');
    } else {
      btn.classList.remove('has-unread');
    }
    return r;
  } catch (e) { /* ignore */ }
};

window.toggleNotifPanel = async function() {
  if (window._notifPanelEl?.classList.contains('show')) {
    closeNotifPanel();
    return;
  }
  if (!window._notifPanelEl) {
    const el = document.createElement('div');
    el.className = 'notif-panel';
    document.body.appendChild(el);
    window._notifPanelEl = el;
    document.addEventListener('click', (e) => {
      if (!el.contains(e.target) && !e.target.closest('#btn-notifications')) closeNotifPanel();
    });
  }
  window._notifPanelEl.innerHTML = `
    <div class="notif-head">
      <h3>🔔 Notifikace</h3>
      <div class="notif-actions">
        <button id="notif-mute-btn" onclick="toggleNotifMute()" title="Zapnout/vypnout notifikace na tomto zařízení">${window.notifMuted && window.notifMuted() ? '🔔 Zapnout' : '🔕 Vypnout'}</button>
        <button onclick="markAllNotifRead()">✓ Vše přečteno</button>
      </div>
    </div>
    <div class="notif-list" id="notif-list">⏳</div>
    <div class="notif-foot">Auto-refresh každých 60s</div>
  `;
  window._notifPanelEl.classList.add('show');
  await loadNotifList(true);
};

function closeNotifPanel() {
  if (window._notifPanelEl) window._notifPanelEl.classList.remove('show');
}

// 🆕 v3.0.160 — přepínač vypnutí/zapnutí notifikací (per-device, localStorage)
window.toggleNotifMute = function() {
  const willMute = !window.notifMuted();
  try { localStorage.setItem('appek_notif_muted', willMute ? '1' : '0'); } catch (e) {}
  const mb = document.getElementById('notif-mute-btn');
  if (mb) mb.textContent = willMute ? '🔔 Zapnout' : '🔕 Vypnout';
  refreshNotifBadge();
  loadNotifList();
  try { (window.toastInfo || window.toast || function(){})(willMute ? '🔕 Notifikace vypnuté' : '🔔 Notifikace zapnuté'); } catch (e) {}
};

async function loadNotifList(triggerFresh = false) {
  const host = document.getElementById('notif-list');
  if (!host) return;
  if (window.notifMuted && window.notifMuted()) {
    host.innerHTML = `<div class="notif-empty">🔕 Notifikace jsou vypnuté<br><button class="btn-secondary" style="margin-top:10px;font-size:12px;padding:6px 14px" onclick="toggleNotifMute()">🔔 Zapnout</button></div>`;
    return;
  }
  try {
    const r = await api('admin_notifications.php' + (triggerFresh ? '?fresh=1' : ''));
    const list = r.notifications || [];
    if (list.length === 0) {
      host.innerHTML = `<div class="notif-empty">🎉 Žádné nové notifikace</div>`;
      refreshNotifBadge();
      return;
    }

    // 🆕 v2.0.71 — SLUČOVÁNÍ stejných kind do jednoho řádku s počítadlem
    // (např. 5× "Nová objednávka #..." → "🛒 5 nových objednávek")
    const groups = {};
    for (const n of list) {
      const k = n.kind || 'other';
      if (!groups[k]) groups[k] = [];
      groups[k].push(n);
    }

    const kindLabels = {
      order_new:    { single: 'Nová objednávka',          plural: 'nových objednávek' },
      low_stock:    { single: 'Nízký sklad',              plural: 'surovin pod minimem' },
      sync_error:   { single: 'Sync chyba',               plural: 'sync chyb' },
      backup_stale: { single: 'Záloha DB',                plural: 'záloh DB pozadu' },
      app_update:   { single: 'Dostupný update',          plural: 'dostupných updatů' },
      license_expiring: { single: 'Licence expiruje',     plural: 'licencí expirují' },
      haccp_due:    { single: 'HACCP záznam',             plural: 'HACCP záznamů' },
      payment_due:  { single: 'Nezaplacená faktura',      plural: 'nezaplacených faktur' },
    };

    const html = [];
    for (const [kind, items] of Object.entries(groups)) {
      if (items.length === 1) {
        // Jediná notif — render normálně
        const n = items[0];
        html.push(`
          <div class="notif-item ${n.is_read == 0 ? 'is-unread' : ''} sev-${esc(n.severity)}" data-id="${n.id}" onclick="onNotifClick(${n.id}, '${esc(n.link || '')}')">
            <div class="notif-icon">${notifIcon(n.kind, n.severity)}</div>
            <div class="notif-body">
              <div class="notif-title">${esc(n.title)}</div>
              ${n.msg ? `<div class="notif-msg">${esc(n.msg)}</div>` : ''}
              <div class="notif-time">${fmtAgo(n.created_at)}</div>
            </div>
            <button class="notif-del" onclick="event.stopPropagation();deleteNotif(${n.id})" title="Smazat">×</button>
          </div>`);
      } else {
        // 2+ notif stejného kind — sloučit do jednoho řádku s počtem + expandable
        const first = items[0];
        const unreadCount = items.filter(x => x.is_read == 0).length;
        const isUnread = unreadCount > 0;
        const lbl = kindLabels[kind] || { single: 'Notifikace', plural: 'notifikací' };
        const summary = `${items.length} ${lbl.plural}`;
        const ids = items.map(x => x.id).join(',');
        const newestAt = items.map(x => x.created_at).sort().reverse()[0];

        html.push(`
          <div class="notif-item ${isUnread ? 'is-unread' : ''} sev-${esc(first.severity)} is-grouped" data-kind="${esc(kind)}" data-ids="${ids}" onclick="toggleNotifGroup('${esc(kind)}')" style="cursor:pointer">
            <div class="notif-icon">${notifIcon(kind, first.severity)}</div>
            <div class="notif-body">
              <div class="notif-title">
                ${esc(summary)}
                ${unreadCount > 0 ? `<span class="notif-count-badge">${unreadCount}</span>` : ''}
                <button class="notif-expand-btn" onclick="event.stopPropagation();toggleNotifGroup('${esc(kind)}')" title="Rozbalit">▾</button>
              </div>
              <div class="notif-msg">Klikni pro rozbalení (${items.length}× v skupině)</div>
              <div class="notif-time">Nejnovější: ${fmtAgo(newestAt)}</div>
            </div>
            <button class="notif-del" onclick="event.stopPropagation();deleteNotifGroup('${esc(kind)}', '${ids}')" title="Smazat všechny">×</button>
          </div>
          <div class="notif-group-children" id="notif-group-${esc(kind)}" style="display:none;padding-left:18px">
            ${items.map(n => `
              <div class="notif-item notif-child ${n.is_read == 0 ? 'is-unread' : ''} sev-${esc(n.severity)}" data-id="${n.id}" onclick="onNotifClick(${n.id}, '${esc(n.link || '')}')">
                <div class="notif-icon">·</div>
                <div class="notif-body">
                  <div class="notif-title">${esc(n.title)}</div>
                  ${n.msg ? `<div class="notif-msg">${esc(n.msg)}</div>` : ''}
                  <div class="notif-time">${fmtAgo(n.created_at)}</div>
                </div>
                <button class="notif-del" onclick="event.stopPropagation();deleteNotif(${n.id})" title="Smazat">×</button>
              </div>
            `).join('')}
          </div>`);
      }
    }
    host.innerHTML = html.join('');
    refreshNotifBadge();
  } catch (e) {
    host.innerHTML = `<div class="notif-empty">Chyba: ${esc(e.message)}</div>`;
  }
}

// 🆕 v2.0.71 — Expand/collapse group of same-kind notifications
window.toggleNotifGroup = function(kind) {
  const el = document.getElementById('notif-group-' + kind);
  if (!el) return;
  const isOpen = el.style.display === 'block';
  el.style.display = isOpen ? 'none' : 'block';
  // Update arrow indicator (find ▾/▴ button)
  const btn = document.querySelector(`.notif-item.is-grouped[data-kind="${kind}"] .notif-expand-btn`);
  if (btn) btn.textContent = isOpen ? '▾' : '▴';
};

// 🆕 v2.0.71 — Delete all notifications of a kind at once
window.deleteNotifGroup = async function(kind, idsCsv) {
  if (!(await confirmDialog({ msg: t('confirm_delete_notif_kind', { kind, n: idsCsv.split(',').length }), danger: false }))) return;
  const ids = idsCsv.split(',').filter(Boolean);
  try {
    await Promise.all(ids.map(id =>
      api('admin_notifications.php?action=delete', { method: 'POST', body: JSON.stringify({ id: parseInt(id, 10) }) })
    ));
    loadNotifList();
  } catch (e) { alert('Chyba: ' + e.message); }
};

function notifIcon(kind, severity) {
  const byKind = {
    order_new: '🛒', low_stock: '⚠️', sync_error: '☁️', backup_stale: '💾',
    license_expiring: '🔑', haccp_due: '📋', payment_due: '💰',
    app_update: '🆕',
  };
  return byKind[kind] || (severity === 'error' ? '❌' : severity === 'warn' ? '⚠️' : severity === 'success' ? '✓' : 'ⓘ');
}

window.onNotifClick = async function(id, link) {
  try {
    await api('admin_notifications.php?action=read', { method: 'POST', body: JSON.stringify({ id }) });
  } catch (e) { /* ignore */ }
  if (link && link.startsWith('#/')) {
    const parts = link.slice(2).split('/');
    closeNotifPanel();
    // 🆕 v3.0.283 — #/nastaveni/<tab|update> otevře konkrétní sekci nastavení;
    // 'update' = tab Údržba + scroll na kartu Licence & aktualizace (self-update).
    // ♻️ v3.0.398 — přes gotoNastaveniBlok (re-scroll po donačtení async karet,
    //   dřív cíl ujel o výšku donačtených bloků mimo viewport).
    if (parts[0] === 'nastaveni' && parts[1]) {
      const tab = parts[1] === 'update' ? 'udrzba' : parts[1];
      if (parts[1] === 'update') {
        gotoNastaveniBlok('udrzba', 'ns-license-block');
      } else {
        navigate('nastaveni');
        setTimeout(() => { state._nastaveniTab = tab; renderNastaveni(); }, 150);
      }
    } else if (parts[0]) {
      navigate(parts[0]);
    }
  }
  refreshNotifBadge();
  loadNotifList();
};

window.markAllNotifRead = async function() {
  try {
    await api('admin_notifications.php?action=read', { method: 'POST', body: JSON.stringify({ all: true }) });
    toastSuccess('Vše označeno jako přečtené');
    loadNotifList();
  } catch (e) { alert('Chyba: ' + e.message); }
};

window.deleteNotif = async function(id) {
  try {
    await api('admin_notifications.php?action=delete', { method: 'POST', body: JSON.stringify({ id }) });
    loadNotifList();
  } catch (e) { /* ignore */ }
};

