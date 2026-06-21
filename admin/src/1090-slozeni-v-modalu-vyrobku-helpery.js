// =============================================================
// SLOŽENÍ V MODALU VÝROBKU - helpery
// =============================================================
async function loadSurovinyCache() {
  if (!state._suroviny_cache) {
    state._suroviny_cache = await api('admin_suroviny.php');
  }
  // 🆕 v3.0.149 — robustnost: api může vrátit {} (0 surovin / chyba) → normalizuj na pole,
  // jinak (state._suroviny_cache||[]).filter v vkRender spadne na "filter is not a function".
  if (!Array.isArray(state._suroviny_cache)) state._suroviny_cache = [];
  return state._suroviny_cache;
}

// 🆕 v2.9.290 — Tlačítko v editVyrobek pro doplnění demo receptu (RK01, CH01, …)
window.vyNaplnitDemoRecept = async function(vyrobekId, cislo) {
  if (!(await confirmDialog({ msg: t('confirm_demo_recipe', { cislo }), danger: false }))) return;
  try {
    const r = await api('admin_demo_seed.php?action=seed_one_recipe', {
      method: 'POST',
      body: JSON.stringify({ cislo }),
    });
    let msg = `✓ Recept doplněn pro „${r.vyrobek}"\n\nVloženo: ${r.vlozeno} / ${r.celkem_v_demo} surovin.`;
    if (r.nenalezeno && r.nenalezeno.length > 0) {
      msg += `\n\n⚠ Nenalezeno v DB (přeskočeno): ${r.nenalezeno.slice(0, 5).join(', ')}`;
      msg += `\n\n💡 Tip: spusť 🎬 Naplnit demo daty pro doplnění chybějících surovin.`;
    }
    alert(msg);
    // Reload editVyrobek modal s čerstvými daty
    closeModal();
    setTimeout(() => editVyrobek(vyrobekId), 200);
  } catch (e) {
    alert('Chyba: ' + e.message);
  }
};

window.vySlozeniAddRow = function(surovina_id = '', mnozstvi = '', jednotka = 'g', poznamka = '') {
  const c = document.getElementById('vy-sloz-rows');
  if (!c) return;
  const sur = state._suroviny_cache || [];
  const row = document.createElement('div');
  row.className = 'sloz-row';
  row.style.cssText = 'display:grid;grid-template-columns:2fr 1fr 1fr 1.5fr auto;gap:8px;margin-bottom:6px;align-items:center';
  row.innerHTML = `
    <select class="form-select sloz-sur" style="font-size:13px">
      <option value="">— Vyberte —</option>
      ${sur.filter(s => s.aktivni || s.id == surovina_id).map(s => `
        <option value="${s.id}" data-jed="${esc(s.jednotka || 'g')}" ${s.id == surovina_id ? 'selected' : ''}>${esc(s.nazev)}${s.alergen ? ' · ' + esc(s.alergen) : ''}</option>
      `).join('')}
    </select>
    <input class="form-input sloz-mn" type="number" step="0.001" min="0" placeholder="Množ." value="${mnozstvi}" style="font-size:13px" oninput="vyTestoUpdateDisplay()">
    <select class="form-select sloz-jed" style="font-size:13px" onchange="vyTestoUpdateDisplay()">
      ${['g','kg','ml','l','ks','lžíce','lžička'].map(j => `<option value="${j}" ${j===jednotka?'selected':''}>${j}</option>`).join('')}
    </select>
    <input class="form-input sloz-pozn" type="text" placeholder="Pozn. (volitelné)" value="${esc(poznamka)}" style="font-size:13px">
    <button class="btn-secondary" type="button" style="padding:6px 10px;font-size:13px" onclick="this.parentElement.remove();vyTestoUpdateDisplay()">✕</button>
  `;
  c.appendChild(row);

  // Při změně suroviny předvyplň jednotku z kartotéky
  const sel = row.querySelector('.sloz-sur');
  sel.addEventListener('change', () => {
    const opt = sel.selectedOptions[0];
    if (opt && opt.dataset.jed) {
      row.querySelector('.sloz-jed').value = opt.dataset.jed;
    }
    vyTestoUpdateDisplay();
  });

  vyTestoUpdateDisplay();
};

