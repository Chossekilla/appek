// =============================================================
// HACCP karty — hromadný výběr a akce
// =============================================================
window.hkToggleSelect = function(id, checked) {
  if (!haccpState.selected) haccpState.selected = new Set();
  if (checked) haccpState.selected.add(id); else haccpState.selected.delete(id);
  document.querySelectorAll(`[data-hk-check="${id}"]`).forEach(el => { el.checked = checked; });
  const tr = document.querySelector(`input[data-hk-check="${id}"]`)?.closest('tr');
  if (tr) tr.classList.toggle('is-selected', checked);
  updateHkBulkBar();
};

window.hkSelectAll = function(checked) {
  if (!haccpState.selected) haccpState.selected = new Set();
  // Použij viditelný (filtrovaný) seznam
  const q = (haccpState.filter_q || '').toLowerCase();
  const kat = haccpState.filter_kat;
  const visible = (haccpState.vyrobky || []).filter(v => {
    if (parseInt(v.aktivni) !== 1) return false;
    if (kat && v.kategorie_id != kat) return false;
    if (q && !((v.nazev || '').toLowerCase().includes(q) || (v.cislo || '').toString().toLowerCase().includes(q))) return false;
    return true;
  });
  if (checked) visible.forEach(v => haccpState.selected.add(v.id));
  else haccpState.selected.clear();
  document.querySelectorAll('[data-hk-check]').forEach(el => {
    el.checked = checked;
    const tr = el.closest('tr'); if (tr) tr.classList.toggle('is-selected', checked);
  });
  updateHkBulkBar();
};

window.hkClearSelection = function() {
  if (haccpState.selected) haccpState.selected.clear();
  document.querySelectorAll('[data-hk-check]').forEach(el => {
    el.checked = false;
    const tr = el.closest('tr'); if (tr) tr.classList.remove('is-selected');
  });
  const allCheck = document.getElementById('hk-check-all');
  if (allCheck) { allCheck.checked = false; allCheck.indeterminate = false; }
  updateHkBulkBar();
};

function updateHkBulkBar() {
  const bar = document.getElementById('hk-bulk-bar');
  const cnt = document.getElementById('hk-bulk-count');
  const all = document.getElementById('hk-check-all');
  const n = haccpState.selected ? haccpState.selected.size : 0;
  if (bar) bar.style.display = n > 0 ? 'flex' : 'none';
  document.body.classList.toggle('has-bulk-bar', n > 0);
  if (cnt) cnt.textContent = n;
  if (all) {
    const totalVisible = document.querySelectorAll('[data-hk-check]').length;
    all.checked = totalVisible > 0 && n === totalVisible;
    all.indeterminate = n > 0 && n < totalVisible;
  }
}

window.hkBulkTisk = function() {
  const ids = [...(haccpState.selected || [])];
  if (ids.length === 0) return;
  window.open(`../api/vyrobek_haccp.php?ids=${ids.join(',')}&autoprint=1`, '_blank');
};

window.hkBulkExport = function() {
  const ids = [...(haccpState.selected || [])];
  if (ids.length === 0) return;
  // Bez autoprint — uživatel si pak sám otevře dialog Tisk → Uložit jako PDF
  window.open(`../api/vyrobek_haccp.php?ids=${ids.join(',')}`, '_blank');
};

window.hkBulkExportCsv = function() {
  const ids = [...(haccpState.selected || [])];
  if (ids.length === 0) return;
  // Stáhni CSV souhrn všech polí HACCP karet
  window.location.href = `../api/admin_haccp_export_csv.php?ids=${ids.join(',')}`;
};

window.haccpVyrobekSetGraf = async function(vyrobek_id, graf_id) {
  const id = parseInt(graf_id) || null;
  try {
    await api('admin_vyrobky.php', { method: 'PUT', body: JSON.stringify({ id: vyrobek_id, haccp_graf_id: id }) });
    // Aktualizuj v lokálním state
    const v = haccpState.vyrobky.find(x => x.id == vyrobek_id);
    if (v) v.haccp_graf_id = id;
    // Refresh grafy aby se updatoval pocet_vyrobku
    haccpState.grafy = await api('admin_haccp_grafy.php');
    haccpRender();
  } catch (e) { alert('Chyba: ' + e.message); }
};

