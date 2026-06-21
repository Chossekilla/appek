// =============================================================
// 🖨️ PRINT QUEUE — collect docs → print all at once
// =============================================================
window.printQueue = (function() {
  const KEY = 'appek_print_queue';
  let items = [];
  try { items = JSON.parse(localStorage.getItem(KEY) || '[]'); } catch (e) { items = []; }

  function save() {
    try { localStorage.setItem(KEY, JSON.stringify(items)); } catch (e) {}
    renderFab();
  }

  function add(item) {
    // item = { type: 'faktura'|'dl'|'objednavka'|'cenovka', id: 123, label: 'FA-2026-0001' }
    if (items.find(x => x.type === item.type && x.id === item.id)) return false;
    items.push({ ...item, added_at: Date.now() });
    save();
    toastSuccess(t('toast_added_to_print', { label: item.label, n: items.length }));
    return true;
  }

  function remove(idx) { items.splice(idx, 1); save(); renderPanel(); }
  function clear()      { items = []; save(); closePanel(); }
  function getAll()     { return items.slice(); }
  function count()      { return items.length; }

  function renderFab() {
    let fab = document.getElementById('print-queue-fab');
    // 🆕 v2.9.106 — „Tisknout vše" vypínatelné v Nastavení → skryj plovoucí tlačítko
    if (typeof getPrintAllEnabled === 'function' && !getPrintAllEnabled()) {
      if (fab) fab.classList.remove('show');
      return;
    }
    if (items.length === 0) {
      if (fab) fab.classList.remove('show');
      return;
    }
    if (!fab) {
      fab = document.createElement('button');
      fab.id = 'print-queue-fab';
      fab.className = 'print-queue-fab';
      fab.onclick = togglePanel;
      document.body.appendChild(fab);
    }
    fab.innerHTML = `🖨️ <span>Tisknout vše</span> <span class="pq-badge">${items.length}</span>`;
    fab.classList.add('show');
  }

  let panel = null;
  function togglePanel() {
    if (panel && panel.classList.contains('show')) { closePanel(); return; }
    if (!panel) {
      panel = document.createElement('div');
      panel.className = 'print-queue-panel';
      document.body.appendChild(panel);
      document.addEventListener('click', (e) => {
        if (panel && !panel.contains(e.target) && !e.target.closest('#print-queue-fab')) closePanel();
      });
    }
    renderPanel();
    panel.classList.add('show');
  }

  function closePanel() { if (panel) panel.classList.remove('show'); }

  function renderPanel() {
    if (!panel) return;
    const icon = { faktura: '💰', dl: '📃', objednavka: '📋', cenovka: '🏷️', vyrobni_list: '🥖' };
    panel.innerHTML = `
      <div class="pq-head">
        <h3>🖨️ Fronta tisku (${items.length})</h3>
        <button class="pq-remove" onclick="closePqPanel()" title="Zavřít">×</button>
      </div>
      <div class="pq-list">
        ${items.map((it, i) => `
          <div class="pq-item">
            <span class="pq-icon">${icon[it.type] || '📄'}</span>
            <div class="pq-info">
              <div class="pq-title">${esc(it.label)}</div>
              <div class="pq-sub">${esc({faktura:'Faktura',dl:'Dodací list',objednavka:'Objednávka',cenovka:'Cenovka',vyrobni_list:'Výrobní list'}[it.type] || it.type)}</div>
            </div>
            <button class="pq-remove" onclick="printQueue.remove(${i})" title="Odebrat">×</button>
          </div>
        `).join('')}
      </div>
      <div class="pq-foot">
        <button class="btn-secondary" onclick="printQueue.clear()">🗑️ Vymazat</button>
        <button class="btn-primary btn-green" onclick="printQueue.printAll()">🖨️ Tisknout vše</button>
      </div>
    `;
  }

  async function printAll() {
    if (items.length === 0) return;
    closePanel();
    toastInfo(t('toast_opening_docs', { n: items.length }));
    for (const it of items) {
      let url = null;
      if (it.type === 'faktura')     url = '../api/faktura.php?id=' + it.id;
      else if (it.type === 'dl')      url = '../api/dodaci_list.php?id=' + it.id;
      else if (it.type === 'vyrobni_list') url = '../api/vyrobni_list_print.php?id=' + it.id;
      if (url) {
        window.open(url, '_blank');
        // Stagger 250ms aby prohlížeč nezablokoval popup
        await new Promise(r => setTimeout(r, 250));
      }
    }
  }

  // Init FAB
  setTimeout(renderFab, 0);

  return { add, remove, clear, getAll, count, togglePanel, printAll, renderFab };
})();
window.closePqPanel = () => printQueue.togglePanel();

// 🆕 v2.9.106 — „Tisknout vše": zapínatelné/vypínatelné v Nastavení
window.getPrintAllEnabled = function() {
  try { return localStorage.getItem('appek_print_all_off') !== '1'; } catch (e) { return true; }
};
window.setPrintAllEnabled = function(on) {
  try { localStorage.setItem('appek_print_all_off', on ? '0' : '1'); } catch (e) {}
  try { printQueue.renderFab(); } catch (e) {}
  const st = document.getElementById('ns-print-all-status');
  if (st) {
    st.textContent = on ? '✓ Zapnuto' : '✕ Vypnuto';
    st.style.background = on ? 'var(--success-bg)' : '#FEE2E2';
    st.style.color = on ? 'var(--success-text)' : '#7F1D1D';
  }
};

