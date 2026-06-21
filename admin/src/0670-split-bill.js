// =============================================================
// ✂️ SPLIT BILL
// =============================================================
window.posSplitDialog = function() {
  const u = posState.currentUcet;
  const polozky = (u.polozky || []).filter(p => p.stav !== 'storno');
  if (polozky.length < 2) return alert('Účet musí mít alespoň 2 položky pro split.');

  window._splitAssign = {};
  polozky.forEach(p => window._splitAssign[p.id] = 1);

  openModal('✂️ Rozdělit účet na části', `
    <p style="font-size:13px;color:var(--text-3);margin-bottom:10px">Přiřaď každou položku k části. Po dělení vznikne tolik podúčtů, kolik použiješ čísel.</p>
    <div style="display:flex;flex-direction:column;gap:4px;max-height:50vh;overflow-y:auto">
      ${polozky.map(p => `
        <div style="display:flex;align-items:center;gap:8px;padding:8px;background:#fff;border:1px solid var(--border);border-radius:6px">
          <div style="flex:1;min-width:0">
            <div style="font-size:13px;font-weight:600">${esc(p.nazev)}</div>
            <div style="font-size:11px;color:var(--text-3)">${p.mnozstvi}× ${(+p.jednotkova_cena).toFixed(2)} = ${(p.jednotkova_cena * p.mnozstvi).toFixed(2)} Kč</div>
          </div>
          <select onchange="window._splitAssign[${p.id}] = parseInt(this.value)" style="padding:4px 8px;border-radius:6px;border:1px solid var(--border);font-size:13px;font-weight:600">
            ${[1,2,3,4].map(n => `<option value="${n}">Část ${n}</option>`).join('')}
          </select>
        </div>
      `).join('')}
    </div>
    <div style="display:flex;gap:6px;margin-top:14px">
      <button class="btn-secondary" onclick="closeModal()" style="flex:1">Zrušit</button>
      <button class="btn-primary btn-green" onclick="posSplitSubmit()" style="flex:1">✂️ Rozdělit</button>
    </div>
  `);
};

window.posSplitSubmit = async function() {
  const u = posState.currentUcet;
  const assignments = window._splitAssign || {};
  const partsMap = {};
  for (const [itemId, partNum] of Object.entries(assignments)) {
    partsMap[partNum] ??= { nazev: 'Část ' + partNum, polozka_ids: [] };
    partsMap[partNum].polozka_ids.push(parseInt(itemId));
  }
  const parts = Object.values(partsMap);
  if (parts.length < 2) return alert('Použij alespoň 2 různé části.');
  try {
    await api('admin_pos.php?action=split', { method: 'POST', body: JSON.stringify({ ucet_id: u.id, parts }) });
    closeModal();
    toastSuccess(t('toast_split_parts', { n: parts.length }));
    posState.currentUcet = null;
    renderRestaurantTables();
  } catch (e) { alert('Chyba: ' + e.message); }
};

