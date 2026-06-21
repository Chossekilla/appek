// =============================================================
// 📜 ACTIVITY LOG (v Údržbě)
// =============================================================
window.loadActivityLog = async function() {
  const host = document.getElementById('ns-activity-list');
  if (!host) return;
  host.innerHTML = '⏳ Načítám…';
  // Default 5, expanded 50 — pevně řízené z frontendu (přes backend i frontend slicing)
  const showAll = state._activityShowAll === true;
  const apiLimit = showAll ? 50 : 5;
  try {
    const r = await api('admin_activity_log.php?limit=' + apiLimit);
    const items = r.items || [];
    if (items.length === 0) {
      host.innerHTML = '<div class="empty-state" style="padding:14px">Žádná aktivita.</div>';
      return;
    }
    const renderItem = (it) => `
      <div class="activity-item">
        <div class="activity-icon">${esc(it.icon || '·')}</div>
        <div class="activity-text">
          <div><span class="activity-who">${esc(it.who)}</span> <span class="activity-action">${esc(it.action)}</span></div>
          ${it.detail ? `<div class="activity-detail">${esc(it.detail)}</div>` : ''}
        </div>
        <div class="activity-time" title="${esc(it.when)}">${fmtAgo(it.when)}</div>
      </div>
    `;
    // Double-safety: slice na 5 v JS, i kdyby backend vrátil víc
    const visible = showAll ? items.slice(0, 50) : items.slice(0, 5);
    host.innerHTML = `
      <div class="activity-list">${visible.map(renderItem).join('')}</div>
      <div style="text-align:center;margin-top:10px;font-size:11px;color:var(--text-3)">
        Zobrazeno ${visible.length} ${showAll ? 'z posledních 50 záznamů' : 'nejnovějších záznamů'}
      </div>
      <div style="text-align:center;margin-top:6px">
        ${!showAll ? `
          <button class="btn-secondary" style="font-size:12px;padding:5px 12px" onclick="state._activityShowAll=true;loadActivityLog()">
            ↓ Zobrazit více (až 50)
          </button>
        ` : `
          <button class="btn-secondary" style="font-size:12px;padding:5px 12px" onclick="state._activityShowAll=false;loadActivityLog()">
            ↑ Sbalit (jen 5)
          </button>
        `}
      </div>
    `;
  } catch (e) {
    host.innerHTML = `<div class="empty-state">Chyba: ${esc(e.message)}</div>`;
  }
};

