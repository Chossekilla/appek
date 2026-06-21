// =============================================================
// 🚚 MOVE
// =============================================================
window.posMoveDialog = function() {
  const stoly = (state._rtData?.stoly || []).filter(s => s.id !== posState.currentUcet.stul_id && s.stav === 'free');
  if (stoly.length === 0) return alert('Žádné volné stoly k přesunu.');
  openModal('🚚 Přesunout účet', `
    <p style="font-size:13px;color:var(--text-3);margin-bottom:10px">Vyber stůl kam host přechází.</p>
    <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(120px,1fr));gap:6px;max-height:50vh;overflow-y:auto">
      ${stoly.map(s => `
        <button onclick="posMoveSubmit(${s.id})" class="btn-secondary" style="padding:12px;text-align:center">
          <strong>${esc(s.nazev)}</strong><br><small>${s.mist}p</small>
        </button>
      `).join('')}
    </div>
  `);
};

window.posMoveSubmit = async function(novyStulId) {
  const u = posState.currentUcet;
  try {
    await api('admin_pos.php?action=move', { method: 'POST', body: JSON.stringify({ ucet_id: u.id, novy_stul_id: novyStulId }) });
    closeModal();
    toastSuccess('🚚 Přesunuto');
    posState.currentUcet = await api('admin_pos.php?action=ucet&stul_id=' + novyStulId);
    posRenderUcetModal();
    renderRestaurantTables();
  } catch (e) { alert('Chyba: ' + e.message); }
};

