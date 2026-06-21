// =============================================================
// HACCP GRAFY — šablony technologického postupu
// =============================================================
function haccpRenderGrafy() {
  const list = haccpState.grafy || [];
  if (haccpState.graf_editor) return haccpRenderGrafEditor();

  return `
    <div class="card-block" style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:8px;padding:12px 16px;margin-bottom:14px">
      <div>
        <h3 style="margin:0;font-size:15px">📈 Šablony výrobního postupu (HACCP grafy)</h3>
        <p style="margin:4px 0 0;color:var(--text-3);font-size:12px">Jedna šablona = jeden flow diagram. Přiřaď ji k více výrobkům, které mají stejný postup.</p>
      </div>
      <div style="display:flex;gap:6px;flex-wrap:wrap">
        ${list.length === 0 ? `<button class="btn-secondary" onclick="haccpGrafImportDefault()" style="font-size:13px">🔄 Importovat výchozí sadu</button>` : ''}
        ${list.length > 0 ? `<button class="btn-secondary" onclick="haccpGrafFillPopisy()" style="font-size:13px;background:#FFF8E7;border-color:#F59E0B;color:#92400e" title="Doplní realistické popisy do všech kroků (jen prázdná, ruční úpravy zachová)">✨ Doplnit popisy kroků</button>` : ''}
        <button class="btn-primary" onclick="haccpGrafNew()" style="font-size:13px">+ Nová šablona</button>
      </div>
    </div>

    ${list.length === 0 ? `
      <div class="card-block" style="padding:40px;text-align:center;color:var(--text-3)">
        <div style="font-size:32px;margin-bottom:10px">📈</div>
        <p style="margin:0 0 14px">Žádné šablony zatím nejsou. Naimportuj výchozí sadu (5 šablon pro pekařství) — pak je můžeš upravit a přiřadit k výrobkům.</p>
        <button class="btn-primary" onclick="haccpGrafImportDefault()">🔄 Naimportovat výchozí sadu</button>
      </div>
    ` : `
      <div class="card-block" style="padding:0">
        <table class="table">
          <thead>
            <tr>
              <th style="width:40px">#</th>
              <th>Šablona</th>
              <th>Vstupy / kroky</th>
              <th>Použito</th>
              <th></th>
            </tr>
          </thead>
          <tbody>
            ${list.map(g => `
              <tr class="row-clickable" onclick="haccpGrafEdit(${g.id})">
                <td>${g.poradi || ''}</td>
                <td>
                  <strong>${esc(g.nazev)}</strong>
                  ${g.popis ? `<div style="font-size:11px;color:var(--text-3);margin-top:2px">${esc(g.popis)}</div>` : ''}
                </td>
                <td style="font-size:12px;color:var(--text-2)">
                  ${(g.suroviny || []).length} surovin · ${(g.kroky || []).length} kroků
                  ${(g.kroky || []).some(k => k.ccp) ? '<span style="background:#FEE2E2;color:#dc2626;padding:1px 6px;border-radius:8px;font-size:10px;font-weight:700;margin-left:4px">CCP</span>' : ''}
                </td>
                <td>
                  <span style="background:${g.pocet_vyrobku > 0 ? '#DCFCE7' : '#F3F4F6'};color:${g.pocet_vyrobku > 0 ? '#166534' : '#6B7280'};padding:2px 8px;border-radius:10px;font-size:11px;font-weight:600">${g.pocet_vyrobku || 0} výrobků</span>
                </td>
                <td onclick="event.stopPropagation();" style="white-space:nowrap;text-align:right">
                  <button class="btn-secondary" onclick="haccpGrafAssignDialog(${g.id})" style="font-size:12px;padding:5px 10px">🔗 Přiřadit</button>
                  <button class="btn-secondary" onclick="haccpGrafEdit(${g.id})" style="font-size:12px;padding:5px 10px;margin-left:4px">✏️ Upravit</button>
                  <button class="btn-danger" onclick="haccpGrafDelete(${g.id})" style="font-size:12px;padding:5px 10px;margin-left:4px">×</button>
                </td>
              </tr>
            `).join('')}
          </tbody>
        </table>
      </div>
    `}
  `;
}

window.haccpGrafFillPopisy = async function() {
  openModal('✨ Doplnit popisy výrobních kroků', `
    <p style="font-size:13px;color:var(--text-2);margin-bottom:12px">
      Automaticky doplním realistické popisy do všech kroků ve všech HACCP šablonách.
      Texty jsou napsané podle pekařské praxe (poměry surovin, teploty hnětení/kynutí/pečení, časy, kontroly).
    </p>
    <div style="background:#FFF8E7;padding:10px 14px;border-radius:8px;font-size:12px;margin-bottom:12px;border:1px solid #F59E0B33">
      <strong>📋 Příklady, které dostaneš:</strong>
      <ul style="margin:6px 0 0 18px">
        <li><strong>Dávkování</strong>: poměry (mouka 100 %, voda 60–65 %, droždí 2–4 %…), kontrola DMT</li>
        <li><strong>Hnětení</strong>: 4 + 6 min, teplota těsta 28–30 °C</li>
        <li><strong>Kynutí</strong>: 35–45 min při 32–35 °C, vlhkost 75–80 %</li>
        <li><strong>Pečení (CCP)</strong>: konkrétní teploty per typ (chléb 240–260 °C, pečivo 220–240 °C, jemné 180–210 °C)</li>
        <li><strong>Specifika</strong>: chléb (kvas, ošatka, nářez), jemné pečivo (mašlování, plnění), zdobení (dávky posypu)</li>
      </ul>
    </div>
    <div style="background:#F0F9FF;border:1px solid #93C5FD;padding:10px 14px;border-radius:8px;font-size:12px;margin-bottom:14px;color:#1e40af">
      ℹ️ <strong>Defaultně</strong> doplní jen prázdné popisy — ruční úpravy zůstanou.<br>
      ⚠️ <strong>Force</strong> přepíše i existující popisy.
    </div>
    <div style="display:flex;gap:8px;justify-content:flex-end;flex-wrap:wrap">
      <button class="btn-secondary" onclick="closeModal()">Zrušit</button>
      <button class="btn-secondary" onclick="haccpGrafFillPopisyRun(true)" style="color:#dc2626;border-color:#fca5a5">⚠️ Přepsat vše</button>
      <button class="btn-primary btn-green" onclick="haccpGrafFillPopisyRun(false)">✅ Doplnit prázdné</button>
    </div>
  `);
};

window.haccpGrafFillPopisyRun = async function(force) {
  closeModal();
  const url = force
    ? 'admin_haccp_grafy.php?action=fill_postup_popis&force=1'
    : 'admin_haccp_grafy.php?action=fill_postup_popis';
  try {
    const r = await api(url, { method: 'POST', body: '{}' });
    await haccpRefreshGrafy();
    const t = document.createElement('div');
    t.style.cssText = 'position:fixed;bottom:24px;right:24px;background:var(--success-bg);color:var(--success-text);padding:14px 22px;border-radius:8px;box-shadow:0 4px 12px rgba(0,0,0,0.15);font-size:14px;font-weight:600;z-index:9999';
    t.innerHTML = `✓ Vyplněno ${r.touched_kroku || 0} kroků v ${r.updated_grafu || 0} šablonách`;
    document.body.appendChild(t);
    setTimeout(() => t.remove(), 4000);
  } catch (e) { alert('Chyba: ' + e.message); }
};

window.haccpGrafImportDefault = async function() {
  if (!(await confirmDialog({ msg: 'Naimportovat výchozí sadu 5 HACCP šablon (Pšeničné základní, Pšeničné se zdobením, Chleba, Speciální pečivo, Jemné pečivo)?', danger: false }))) return;
  try {
    const r = await api('admin_haccp_grafy.php?action=import_default', { method: 'POST', body: '{}' });
    if (r && r.ok === false && r.existing) {
      if (!(await confirmDialog({ msg: t('confirm_add_more_templates', { n: r.existing }), danger: false }))) return;
      await api('admin_haccp_grafy.php?action=import_default&force=1', { method: 'POST', body: '{}' });
    }
    await haccpRefreshGrafy();
  } catch (e) { alert('Chyba: ' + e.message); }
};

window.haccpGrafNew = function() {
  haccpState.graf_editor = {
    id: null,
    data: {
      nazev: '',
      popis: '',
      suroviny: [{ nazev: 'mouka pšeničná hladká', krok_idx: 0 }],
      kroky: [
        { nazev: 'Dávkování a smísení surovin', ccp: false, popis: '' },
        { nazev: 'Pečení', ccp: true, popis: 'CCP — kontrola teploty pece a doby pečení.' },
        { nazev: 'Expedice', ccp: false, popis: '' },
      ],
      poradi: (haccpState.grafy || []).length + 1,
      aktivni: 1,
    },
  };
  haccpRender();
};

window.haccpGrafEdit = async function(id) {
  try {
    const g = await api(`admin_haccp_grafy.php?id=${id}`);
    haccpState.graf_editor = {
      id: g.id,
      data: {
        nazev: g.nazev || '',
        popis: g.popis || '',
        suroviny: Array.isArray(g.suroviny) ? g.suroviny : [],
        kroky: Array.isArray(g.kroky) ? g.kroky : [],
        poradi: g.poradi || 0,
        aktivni: g.aktivni || 1,
        vyrobky: g.vyrobky || [],
      },
    };
    haccpRender();
  } catch (e) { alert('Chyba: ' + e.message); }
};

window.haccpGrafDelete = async function(id) {
  const g = (haccpState.grafy || []).find(x => x.id === id);
  if (!g) return;
  let msg = `Smazat šablonu „${g.nazev}"?`;
  if (g.pocet_vyrobku > 0) msg += `\n\nUpozornění: tato šablona je přiřazena k ${g.pocet_vyrobku} výrobkům — ti ji ztratí (HACCP graf bude prázdný).`;
  if (!(await confirmDialog({ msg: msg, danger: false }))) return;
  try {
    await api(`admin_haccp_grafy.php?id=${id}`, { method: 'DELETE' });
    await haccpRefreshGrafy();
  } catch (e) { alert('Chyba: ' + e.message); }
};

function haccpRenderGrafEditor() {
  const ed = haccpState.graf_editor;
  const d = ed.data;
  const isNew = !ed.id;
  return `
    <div class="card-block" style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:8px;padding:12px 16px;margin-bottom:14px">
      <div>
        <button class="btn-back" onclick="haccpState.graf_editor=null;haccpRender()" style="font-size:12px">← Zpět</button>
        <strong style="margin-left:10px">${isNew ? 'Nová šablona HACCP grafu' : 'Upravit: ' + esc(d.nazev || '—')}</strong>
      </div>
      <div style="display:flex;gap:6px">
        <button class="btn-primary btn-green" onclick="haccpGrafSave()" style="font-size:13px">💾 Uložit</button>
      </div>
    </div>

    <div class="card-block">
      <div class="form-grid" style="grid-template-columns:2fr 1fr;gap:12px">
        <div>
          <label class="form-label">Název šablony</label>
          <input class="form-input" id="gf-nazev" value="${esc(d.nazev || '')}" placeholder="např. Pšeničné pečivo se zdobením">
        </div>
        <div>
          <label class="form-label">Pořadí</label>
          <input class="form-input" type="number" id="gf-poradi" value="${parseInt(d.poradi) || 0}">
        </div>
        <div class="full">
          <label class="form-label">Popis</label>
          <input class="form-input" id="gf-popis" value="${esc(d.popis || '')}" placeholder="krátký popis (k jakým výrobkům se hodí)">
        </div>
      </div>
    </div>

    <div class="card-block">
      <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:8px">
        <h4 style="margin:0;font-size:14px">🔄 Technologické kroky <span style="color:var(--text-3);font-weight:400;font-size:11px">(přetáhni ↕ pro změnu pořadí)</span></h4>
        <button class="btn-secondary" onclick="haccpGrafKrokAdd()" style="font-size:12px;padding:5px 10px">+ Přidat krok</button>
      </div>
      <div id="gf-kroky" style="display:flex;flex-direction:column;gap:6px">
        ${(d.kroky || []).map((k, i) => haccpGrafKrokRow(k, i, (d.kroky || []).length)).join('')}
      </div>
    </div>

    <div class="card-block">
      <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:8px">
        <h4 style="margin:0;font-size:14px">🥖 Vstupy (suroviny) <span style="color:var(--text-3);font-weight:400;font-size:11px">(přiřaď, do kterého kroku vstupují)</span></h4>
        <button class="btn-secondary" onclick="haccpGrafSurovinaAdd()" style="font-size:12px;padding:5px 10px">+ Přidat vstup</button>
      </div>
      <div id="gf-suroviny" style="display:flex;flex-direction:column;gap:6px">
        ${(d.suroviny || []).map((s, i) => haccpGrafSurovinaRow(s, i, d.kroky || [])).join('')}
      </div>
    </div>

    ${ed.id && Array.isArray(d.vyrobky) && d.vyrobky.length > 0 ? `
      <div class="card-block">
        <h4 style="margin:0 0 8px;font-size:14px">📦 Přiřazené výrobky (${d.vyrobky.length})</h4>
        <div style="display:flex;flex-wrap:wrap;gap:6px">
          ${d.vyrobky.map(v => `<span style="background:var(--surface-2);padding:4px 10px;border-radius:14px;font-size:12px">${esc(v.cislo || '')} ${esc(v.nazev)}</span>`).join('')}
        </div>
      </div>
    ` : ''}
  `;
}

function haccpGrafKrokRow(k, i, total) {
  return `
    <div class="naklad-row" data-kid="${i}" style="display:grid;grid-template-columns:32px 2fr 60px 3fr 80px;gap:6px;align-items:center;background:${k.ccp ? '#FEE2E2' : 'var(--surface-2)'};padding:6px;border-radius:6px">
      <strong style="text-align:center;color:${k.ccp ? '#dc2626' : 'var(--text-2)'}">${i + 1}.</strong>
      <input class="form-input" placeholder="Název kroku" value="${esc(k.nazev || '')}" data-fld="nazev">
      <label style="display:flex;align-items:center;gap:4px;font-size:12px;font-weight:600;color:${k.ccp ? '#dc2626' : 'var(--text-2)'};cursor:pointer">
        <input type="checkbox" data-fld="ccp" ${k.ccp ? 'checked' : ''}> CCP
      </label>
      <input class="form-input" placeholder="Popis (volitelné)" value="${esc(k.popis || '')}" data-fld="popis">
      <div style="display:flex;gap:2px">
        <button class="btn-secondary" onclick="haccpGrafKrokMove(${i}, -1)" ${i === 0 ? 'disabled' : ''} style="padding:4px 8px;font-size:11px">↑</button>
        <button class="btn-secondary" onclick="haccpGrafKrokMove(${i}, 1)" ${i === total - 1 ? 'disabled' : ''} style="padding:4px 8px;font-size:11px">↓</button>
        <button class="btn-danger" onclick="haccpGrafKrokDel(${i})" style="padding:4px 8px;font-size:11px">×</button>
      </div>
    </div>
  `;
}

function haccpGrafSurovinaRow(s, i, kroky) {
  const krokOpts = kroky.map((k, idx) => `<option value="${idx}" ${(s.krok_idx || 0) == idx ? 'selected' : ''}>${idx + 1}. ${esc(k.nazev || '')}</option>`).join('');
  return `
    <div class="naklad-row" data-sid="${i}" style="display:grid;grid-template-columns:32px 3fr 2fr 40px;gap:6px;align-items:center;background:#FFF8E7;padding:6px;border-radius:6px">
      <strong style="text-align:center;color:#854F0B">${i + 1})</strong>
      <input class="form-input" placeholder="Surovina (např. mouka pšeničná hladká)" value="${esc(s.nazev || '')}" data-fld="nazev">
      <select class="form-input" data-fld="krok_idx">${krokOpts}</select>
      <button class="btn-danger" onclick="haccpGrafSurovinaDel(${i})" style="padding:4px 8px;font-size:11px">×</button>
    </div>
  `;
}

function haccpGrafSyncFromDom() {
  const ed = haccpState.graf_editor;
  if (!ed) return;
  const d = ed.data;
  d.nazev = (document.getElementById('gf-nazev')?.value || '').trim();
  d.popis = (document.getElementById('gf-popis')?.value || '').trim();
  d.poradi = parseInt(document.getElementById('gf-poradi')?.value) || 0;

  const kroky = [];
  document.querySelectorAll('#gf-kroky [data-kid]').forEach(r => {
    const nazev = (r.querySelector('[data-fld="nazev"]')?.value || '').trim();
    if (!nazev) return;
    kroky.push({
      nazev,
      ccp: r.querySelector('[data-fld="ccp"]')?.checked || false,
      popis: (r.querySelector('[data-fld="popis"]')?.value || '').trim(),
    });
  });
  d.kroky = kroky;

  const suroviny = [];
  document.querySelectorAll('#gf-suroviny [data-sid]').forEach(r => {
    const nazev = (r.querySelector('[data-fld="nazev"]')?.value || '').trim();
    if (!nazev) return;
    suroviny.push({
      nazev,
      krok_idx: parseInt(r.querySelector('[data-fld="krok_idx"]')?.value) || 0,
    });
  });
  d.suroviny = suroviny;
}

window.haccpGrafKrokAdd = function() {
  haccpGrafSyncFromDom();
  haccpState.graf_editor.data.kroky.push({ nazev: '', ccp: false, popis: '' });
  haccpRender();
};
window.haccpGrafKrokDel = function(i) {
  haccpGrafSyncFromDom();
  haccpState.graf_editor.data.kroky.splice(i, 1);
  haccpRender();
};
window.haccpGrafKrokMove = function(i, dir) {
  haccpGrafSyncFromDom();
  const arr = haccpState.graf_editor.data.kroky;
  const j = i + dir;
  if (j < 0 || j >= arr.length) return;
  [arr[i], arr[j]] = [arr[j], arr[i]];
  // Posuň krok_idx u surovin (i ↔ j)
  haccpState.graf_editor.data.suroviny.forEach(s => {
    if (s.krok_idx === i) s.krok_idx = j;
    else if (s.krok_idx === j) s.krok_idx = i;
  });
  haccpRender();
};
window.haccpGrafSurovinaAdd = function() {
  haccpGrafSyncFromDom();
  haccpState.graf_editor.data.suroviny.push({ nazev: '', krok_idx: 0 });
  haccpRender();
};
window.haccpGrafSurovinaDel = function(i) {
  haccpGrafSyncFromDom();
  haccpState.graf_editor.data.suroviny.splice(i, 1);
  haccpRender();
};

window.haccpGrafSave = async function() {
  haccpGrafSyncFromDom();
  const ed = haccpState.graf_editor;
  if (!ed) return;
  const d = ed.data;
  if (!d.nazev) return alert('Vyplň název šablony');
  if (!d.kroky || d.kroky.length === 0) return alert('Přidej alespoň jeden krok');
  try {
    if (ed.id) {
      await api(`admin_haccp_grafy.php?id=${ed.id}`, { method: 'PUT', body: JSON.stringify(d) });
    } else {
      const r = await api('admin_haccp_grafy.php', { method: 'POST', body: JSON.stringify(d) });
      ed.id = r.id;
    }
    haccpState.graf_editor = null;
    await haccpRefreshGrafy();
    const t = document.createElement('div');
    t.style.cssText = 'position:fixed;bottom:24px;right:24px;background:var(--success-bg);color:var(--success-text);padding:14px 22px;border-radius:8px;box-shadow:0 4px 12px rgba(0,0,0,0.15);font-size:14px;font-weight:600;z-index:9999';
    t.textContent = '✓ Šablona uložena';
    document.body.appendChild(t);
    setTimeout(() => t.remove(), 2000);
  } catch (e) { alert('Chyba: ' + e.message); }
};

