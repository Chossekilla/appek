// =============================================================
// 🔗 MERGE
// =============================================================
window.posMergeDialog = async function() {
  try {
    const r = await api('admin_pos.php?action=open_ucty');
    const others = (r.ucty || []).filter(o => o.id !== posState.currentUcet.id);
    if (others.length === 0) return alert('Žádné jiné otevřené účty k sloučení.');
    openModal('🔗 Sloučit účty', `
      <p style="font-size:13px;color:var(--text-3);margin-bottom:10px">Vyber účty které chceš sloučit do <strong>tohoto</strong> (${posState.currentUcet.stul?.nazev}).</p>
      <div style="display:flex;flex-direction:column;gap:6px;max-height:50vh;overflow-y:auto">
        ${others.map(o => `
          <label style="display:flex;align-items:center;gap:10px;padding:10px;background:#fff;border:1.5px solid var(--border);border-radius:8px;cursor:pointer">
            <input type="checkbox" class="pos-merge-cb" value="${o.id}" style="width:18px;height:18px">
            <div style="flex:1">
              <strong>${esc(o.stul_nazev)}</strong> · ${o.pocet_polozek} položek
              <div style="font-size:11px;color:var(--text-3)">${(+o.suma_kc).toFixed(2)} Kč</div>
            </div>
          </label>
        `).join('')}
      </div>
      <div style="display:flex;gap:6px;margin-top:14px">
        <button class="btn-secondary" onclick="closeModal()" style="flex:1">Zrušit</button>
        <button class="btn-primary btn-green" onclick="posMergeSubmit()" style="flex:1">🔗 Sloučit</button>
      </div>
    `);
  } catch (e) { alert('Chyba: ' + e.message); }
};

window.posMergeSubmit = async function() {
  const sources = Array.from(document.querySelectorAll('.pos-merge-cb:checked')).map(c => parseInt(c.value));
  if (sources.length === 0) return alert('Vyber alespoň 1 účet.');
  const u = posState.currentUcet;
  try {
    await api('admin_pos.php?action=merge', { method: 'POST', body: JSON.stringify({ target_ucet_id: u.id, source_ucet_ids: sources }) });
    closeModal();
    toastSuccess(t('toast_merged_accounts', { n: sources.length }));
    posState.currentUcet = await api('admin_pos.php?action=ucet&stul_id=' + u.stul_id);
    posRenderUcetModal();
    renderRestaurantTables();
  } catch (e) { alert('Chyba: ' + e.message); }
};

