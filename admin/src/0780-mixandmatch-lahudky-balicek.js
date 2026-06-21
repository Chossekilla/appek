// =============================================================
// 🧩 MIX-AND-MATCH (Lahůdky balíček)
// =============================================================
async function renderMixMatch() {
  const body = document.getElementById('lahudky-tab-body');
  if (!body) return;
  body.innerHTML = skeletonCards(3);
  let data;
  try { data = await api('admin_mix_match.php'); }
  catch (e) { body.innerHTML = `<div class="alert err">${esc(e.message)}</div>`; return; }

  const sel = state._mixTplId || (data.templates[0]?.id || 0);
  state._mixTplId = sel;

  body.innerHTML = `
    <div style="display:grid;grid-template-columns:280px 1fr;gap:18px">
      <div class="card-block">
        <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:10px">
          <h3 style="margin:0;font-size:15px">🧩 Šablony</h3>
          <button class="btn-secondary" style="font-size:11px;padding:4px 8px" onclick="mixTplEdit(0)">+ Nová</button>
        </div>
        ${(data.templates || []).length === 0 ? `<div style="font-size:12px;color:var(--text-3)">Žádné šablony.</div>` : `
          <div style="display:flex;flex-direction:column;gap:4px">
            ${data.templates.map(t => `
              <button onclick="state._mixTplId=${t.id};renderMixMatch()" style="background:${t.id===sel?'var(--surface-2)':'transparent'};border:1px solid ${t.id===sel?'var(--primary)':'var(--border)'};border-radius:8px;padding:10px 12px;text-align:left;cursor:pointer;font-family:inherit">
                <div style="display:flex;align-items:center;gap:6px">
                  <span style="font-size:18px">${t.ikona}</span>
                  <strong style="font-size:13px">${esc(t.nazev)}</strong>
                </div>
                <div style="font-size:11px;color:var(--text-3);margin-top:2px">${t.kategorii} kategorií · ${t.ingredienci} ingr. · od ${fmt(t.cena_base_kc)}</div>
              </button>
            `).join('')}
          </div>
        `}
      </div>
      <div id="mix-detail">${skeletonCards(2)}</div>
    </div>
  `;

  if (sel) renderMixTemplate(sel);
}

async function renderMixTemplate(id) {
  const host = document.getElementById('mix-detail');
  if (!host) return;
  host.innerHTML = skeletonCards(2);
  let tpl;
  try { tpl = await api('admin_mix_match.php?action=template&id=' + id); }
  catch (e) { host.innerHTML = `<div class="alert err">${esc(e.message)}</div>`; return; }

  state._mixPicks = state._mixPicks || {};
  if (!state._mixPicks[id]) state._mixPicks[id] = [];

  host.innerHTML = `
    <div class="card-block" style="margin-bottom:14px">
      <div style="display:flex;justify-content:space-between;align-items:flex-start;flex-wrap:wrap;gap:10px">
        <div>
          <h3 style="margin:0 0 4px;font-size:18px">${tpl.ikona} ${esc(tpl.nazev)}</h3>
          ${tpl.popis ? `<p style="margin:0;font-size:12px;color:var(--text-3)">${esc(tpl.popis)}</p>` : ''}
          <div style="font-size:12px;color:var(--text-3);margin-top:4px">Základní cena: <strong>${fmt(tpl.cena_base_kc)}</strong></div>
        </div>
        <div style="display:flex;gap:6px">
          <button class="btn-secondary" style="font-size:12px" onclick="mixTplEdit(${tpl.id})">✏️ Upravit šablonu</button>
          <button class="btn-secondary" style="font-size:12px;color:#dc2626" onclick="mixTplDelete(${tpl.id})">🗑️ Smazat</button>
        </div>
      </div>
    </div>

    <div style="display:grid;grid-template-columns:1fr 320px;gap:14px">
      <div style="display:flex;flex-direction:column;gap:12px">
        ${(tpl.kategorie || []).length === 0 ? `
          <div class="card-block" style="text-align:center;padding:24px">
            <div style="font-size:32px;margin-bottom:8px">📁</div>
            <p style="font-size:13px;margin-bottom:12px">Šablona nemá žádné kategorie ingrediencí.</p>
            <button class="btn-primary btn-green" onclick="mixCatEdit(0, ${tpl.id})">+ Přidat kategorii</button>
          </div>
        ` : tpl.kategorie.map(k => `
          <div class="card-block">
            <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:10px">
              <div>
                <strong style="font-size:14px">${k.ikona ? k.ikona + ' ' : ''}${esc(k.nazev)}</strong>
                <span style="font-size:11px;color:var(--text-3);margin-left:8px">${k.povinne ? '<span style="color:#dc2626">povinné</span> · ' : ''}vyber ${k.min_vyber}–${k.max_vyber}</span>
              </div>
              <div style="display:flex;gap:4px">
                <button class="btn-secondary" style="font-size:11px;padding:4px 8px" onclick="mixIngEdit(0, ${k.id})">+ Ingredience</button>
                <button class="btn-secondary" style="font-size:11px;padding:4px 8px" onclick="mixCatEdit(${k.id}, ${tpl.id})">✏️</button>
                <button class="btn-secondary" style="font-size:11px;padding:4px 8px;color:#dc2626" onclick="mixCatDelete(${k.id})">🗑️</button>
              </div>
            </div>
            ${(k.ingredience || []).length === 0 ? `<div style="font-size:12px;color:var(--text-3);text-align:center;padding:14px">Bez ingrediencí — přidej výše.</div>` : `
              <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(180px,1fr));gap:6px">
                ${k.ingredience.map(ing => {
                  const picked = state._mixPicks[id].includes(ing.id);
                  return `
                    <div style="position:relative;background:${picked?'var(--surface-2)':'var(--surface)'};border:1.5px solid ${picked?'var(--primary)':'var(--border)'};border-radius:8px;padding:8px 10px;cursor:pointer" onclick="mixTogglePick(${id},${ing.id},${k.max_vyber})">
                      <div style="display:flex;justify-content:space-between;align-items:start;gap:6px">
                        <span style="font-size:12.5px;font-weight:${picked?'700':'500'}">${esc(ing.nazev)}</span>
                        <span style="font-size:11px;color:${ing.priplatek_kc>0?'#BA7517':'var(--text-3)'};font-weight:600;white-space:nowrap">${ing.priplatek_kc > 0 ? '+'+ing.priplatek_kc : '✓'}</span>
                      </div>
                      ${ing.alergeny ? `<div style="font-size:10px;color:#dc2626;margin-top:2px">⚠ ${esc(ing.alergeny)}</div>` : ''}
                      <button onclick="event.stopPropagation();mixIngEdit(${ing.id}, ${k.id})" style="position:absolute;top:4px;right:4px;background:transparent;border:none;cursor:pointer;font-size:10px;opacity:0.5">✏</button>
                    </div>
                  `;
                }).join('')}
              </div>
            `}
          </div>
        `).join('')}
        <button class="btn-secondary" onclick="mixCatEdit(0, ${tpl.id})" style="margin-top:8px">+ Přidat kategorii</button>
      </div>
      <div style="position:sticky;top:80px;align-self:start">
        <div class="card-block" style="background:linear-gradient(180deg,#F0FDF4,#fff);border:2px solid #166534">
          <h3 style="margin:0 0 10px;color:#166534">💰 Kalkulace</h3>
          <div id="mix-quote">⏳</div>
        </div>
      </div>
    </div>
  `;
  mixRecalc(id);
}

async function mixRecalc(tplId) {
  const host = document.getElementById('mix-quote');
  if (!host) return;
  try {
    const r = await api('admin_mix_match.php?action=quote', { method:'POST', body: JSON.stringify({
      template_id: tplId, ingredients: state._mixPicks[tplId] || [], mnozstvi: 1,
    })});
    host.innerHTML = `
      <div style="max-height:200px;overflow-y:auto;border-bottom:1px solid var(--border);padding-bottom:8px;margin-bottom:10px">
        ${r.polozky.map(p => `
          <div style="display:flex;justify-content:space-between;font-size:12.5px;padding:3px 0">
            <span>${esc(p.nazev)}</span>
            <span style="font-variant-numeric:tabular-nums">${p.cena_kc > 0 ? fmt(p.cena_kc) : (p.cena_kc === 0 ? 'v ceně' : fmt(p.cena_kc))}</span>
          </div>
        `).join('')}
      </div>
      <div style="display:flex;justify-content:space-between;font-size:20px;font-weight:800;color:#166534">
        <span>Cena</span><span style="font-variant-numeric:tabular-nums">${fmt(r.cena_celkem)}</span>
      </div>
      ${r.alergeny && r.alergeny.length > 0 ? `
        <div style="background:#FEE2E2;color:#991B1B;border-radius:7px;padding:8px 10px;margin-top:10px;font-size:11.5px">
          ⚠️ <strong>Obsahuje alergeny:</strong> ${r.alergeny.map(a => esc(a)).join(', ')}
        </div>
      ` : ''}
    `;
  } catch (e) {
    host.innerHTML = `<div style="color:#dc2626;font-size:12px">${esc(e.message)}</div>`;
  }
}

window.mixTogglePick = function(tplId, ingId, maxVyber) {
  const picks = state._mixPicks[tplId] = state._mixPicks[tplId] || [];
  const idx = picks.indexOf(ingId);
  if (idx >= 0) {
    picks.splice(idx, 1);
  } else {
    if (picks.length >= maxVyber) {
      // Nahraď nejstarší pokud single-select
      if (maxVyber === 1) picks.length = 0;
      else { toastWarn(t('toast_max_in_category', { n: maxVyber })); return; }
    }
    picks.push(ingId);
  }
  renderMixTemplate(tplId);
};

window.mixTplEdit = async function(id) {
  let t = { id:0, nazev:'', ikona:'🥪', popis:'', cena_base_kc:0 };
  if (id) {
    try {
      const data = await api('admin_mix_match.php?action=template&id=' + id);
      t = data;
    } catch (e) {}
  }
  openModal(id ? '✏️ Šablona' : '+ Nová šablona', `
    <div class="form-grid form-grid-tight">
      <div><label class="form-label">Ikona (emoji)</label>
        <input class="form-input" id="mt-ikona" value="${esc(t.ikona)}" style="font-size:24px;text-align:center" maxlength="2">
      </div>
      <div><label class="form-label">Základní cena (Kč)</label>
        <input type="number" class="form-input" id="mt-cena" value="${t.cena_base_kc}" step="1">
      </div>
      <div class="full"><label class="form-label">Název *</label>
        <input class="form-input" id="mt-nazev" value="${esc(t.nazev)}" placeholder="např. Chlebíček na míru">
      </div>
      <div class="full"><label class="form-label">Popis</label>
        <textarea class="form-input" id="mt-popis" rows="2">${esc(t.popis || '')}</textarea>
      </div>
    </div>
    <div class="form-actions">
      <button class="btn-secondary" onclick="closeModal()">Zrušit</button>
      <button class="btn-primary btn-green" onclick="mixTplSave(${id})">💾 Uložit</button>
    </div>
  `);
};

window.mixTplSave = async function(id) {
  const nazev = document.getElementById('mt-nazev').value.trim();
  if (!nazev) { alert('Vyplň název'); return; }
  try {
    const r = await api('admin_mix_match.php?action=template', { method:'POST', body: JSON.stringify({
      id: id || 0, nazev,
      ikona: document.getElementById('mt-ikona').value.trim() || '🥪',
      popis: document.getElementById('mt-popis').value.trim() || null,
      cena_base_kc: parseFloat(document.getElementById('mt-cena').value) || 0,
    })});
    closeModal();
    if (!id) state._mixTplId = r.id;
    toastSuccess('Šablona uložena');
    renderMixMatch();
  } catch (e) { alert('Chyba: ' + e.message); }
};

window.mixTplDelete = async function(id) {
  if (!await customConfirm('Smazat šablonu?', 'Smaže i všechny kategorie a ingredience.', 'Smazat')) return;
  try {
    await api('admin_mix_match.php?action=template&id=' + id, { method:'DELETE' });
    delete state._mixPicks?.[id];
    state._mixTplId = 0;
    toastSuccess('Šablona smazána');
    renderMixMatch();
  } catch (e) { alert('Chyba: ' + e.message); }
};

window.mixCatEdit = async function(id, tplId) {
  let k = { id:0, template_id:tplId, nazev:'', ikona:'', povinne:0, min_vyber:0, max_vyber:1, poradi:0 };
  if (id) {
    try {
      const data = await api('admin_mix_match.php?action=template&id=' + tplId);
      k = (data.kategorie || []).find(x => x.id === id) || k;
    } catch (e) {}
  }
  openModal(id ? '✏️ Kategorie' : '+ Nová kategorie', `
    <div class="form-grid form-grid-tight">
      <div><label class="form-label">Ikona</label>
        <input class="form-input" id="mc-ikona" value="${esc(k.ikona || '')}" style="font-size:20px;text-align:center" maxlength="2">
      </div>
      <div><label class="form-label">Pořadí</label>
        <input type="number" class="form-input" id="mc-poradi" value="${k.poradi}">
      </div>
      <div class="full"><label class="form-label">Název *</label>
        <input class="form-input" id="mc-nazev" value="${esc(k.nazev)}" placeholder="např. Pomazánka">
      </div>
      <div><label class="form-label">Min. výběr</label>
        <input type="number" class="form-input" id="mc-min" value="${k.min_vyber}" min="0">
      </div>
      <div><label class="form-label">Max. výběr</label>
        <input type="number" class="form-input" id="mc-max" value="${k.max_vyber}" min="1">
      </div>
      <div class="full">
        <label style="display:flex;align-items:center;gap:8px;font-size:13px;cursor:pointer">
          <input type="checkbox" id="mc-pov" ${parseInt(k.povinne)===1 ? 'checked' : ''}> Povinná kategorie (musí se z ní vybrat)
        </label>
      </div>
    </div>
    <div class="form-actions">
      <button class="btn-secondary" onclick="closeModal()">Zrušit</button>
      <button class="btn-primary btn-green" onclick="mixCatSave(${id}, ${tplId})">💾 Uložit</button>
    </div>
  `);
};

window.mixCatSave = async function(id, tplId) {
  const nazev = document.getElementById('mc-nazev').value.trim();
  if (!nazev) { alert('Vyplň název'); return; }
  try {
    await api('admin_mix_match.php?action=category', { method:'POST', body: JSON.stringify({
      id: id || 0, template_id: tplId, nazev,
      ikona: document.getElementById('mc-ikona').value.trim() || null,
      povinne: document.getElementById('mc-pov').checked ? 1 : 0,
      min_vyber: parseInt(document.getElementById('mc-min').value) || 0,
      max_vyber: parseInt(document.getElementById('mc-max').value) || 1,
      poradi: parseInt(document.getElementById('mc-poradi').value) || 0,
    })});
    closeModal();
    toastSuccess('Kategorie uložena');
    renderMixTemplate(tplId);
  } catch (e) { alert('Chyba: ' + e.message); }
};

window.mixCatDelete = async function(id) {
  if (!await customConfirm('Smazat kategorii?', 'Smaže i všechny ingredience v ní.', 'Smazat')) return;
  try {
    await api('admin_mix_match.php?action=category&id=' + id, { method:'DELETE' });
    toastSuccess('Kategorie smazána');
    renderMixTemplate(state._mixTplId);
  } catch (e) { alert('Chyba: ' + e.message); }
};

window.mixIngEdit = async function(id, catId) {
  let i = { id:0, category_id:catId, nazev:'', priplatek_kc:0, alergeny:'', aktivni:1 };
  if (id) {
    try {
      const data = await api('admin_mix_match.php?action=template&id=' + state._mixTplId);
      for (const k of (data.kategorie || [])) {
        const found = (k.ingredience || []).find(x => x.id === id);
        if (found) { i = found; break; }
      }
    } catch (e) {}
  }
  openModal(id ? '✏️ Ingredience' : '+ Nová ingredience', `
    <div class="form-grid form-grid-tight">
      <div class="full"><label class="form-label">Název *</label>
        <input class="form-input" id="mi-nazev" value="${esc(i.nazev)}" placeholder="např. Šunka Praga">
      </div>
      <div><label class="form-label">Příplatek (Kč)</label>
        <input type="number" class="form-input" id="mi-cena" value="${i.priplatek_kc}" step="1">
      </div>
      <div><label class="form-label">Pořadí</label>
        <input type="number" class="form-input" id="mi-poradi" value="${i.poradi || 0}">
      </div>
      <div class="full"><label class="form-label">⚠ Alergeny (čárkami)</label>
        <input class="form-input" id="mi-aler" value="${esc(i.alergeny || '')}" placeholder="Mléko, Vejce, Lepek">
      </div>
    </div>
    <div class="form-actions" style="justify-content:space-between">
      ${id ? `<button class="btn-secondary" style="color:#dc2626" onclick="mixIngDelete(${id})">🗑️ Smazat</button>` : '<div></div>'}
      <div style="display:flex;gap:8px">
        <button class="btn-secondary" onclick="closeModal()">Zrušit</button>
        <button class="btn-primary btn-green" onclick="mixIngSave(${id}, ${catId})">💾 Uložit</button>
      </div>
    </div>
  `);
};

window.mixIngSave = async function(id, catId) {
  const nazev = document.getElementById('mi-nazev').value.trim();
  if (!nazev) { alert('Vyplň název'); return; }
  try {
    await api('admin_mix_match.php?action=ingredient', { method:'POST', body: JSON.stringify({
      id: id || 0, category_id: catId, nazev,
      priplatek_kc: parseFloat(document.getElementById('mi-cena').value) || 0,
      alergeny: document.getElementById('mi-aler').value.trim() || null,
      poradi: parseInt(document.getElementById('mi-poradi').value) || 0,
    })});
    closeModal();
    toastSuccess('Ingredience uložena');
    renderMixTemplate(state._mixTplId);
  } catch (e) { alert('Chyba: ' + e.message); }
};

window.mixIngDelete = async function(id) {
  if (!await customConfirm('Smazat ingredienci?', '', 'Smazat')) return;
  try {
    await api('admin_mix_match.php?action=ingredient&id=' + id, { method:'DELETE' });
    closeModal();
    toastSuccess('Ingredience smazána');
    renderMixTemplate(state._mixTplId);
  } catch (e) { alert('Chyba: ' + e.message); }
};

