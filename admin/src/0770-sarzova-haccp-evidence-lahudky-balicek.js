// =============================================================
// 📋 ŠARŽOVÁ HACCP EVIDENCE (Lahůdky balíček)
// =============================================================
async function renderBatches() {
  const body = document.getElementById('lahudky-tab-body');
  if (!body) return;
  body.innerHTML = skeletonCards(3);
  const filter = state._batchFilter || 'aktivni';
  let data;
  try { data = await api('admin_batches.php?filter=' + filter); }
  catch (e) { body.innerHTML = `<div class="alert err">${esc(e.message)}</div>`; return; }

  const s = data.stats || {};
  const stavBarvy = {
    'vyrabi_se': { bg:'#DBEAFE', fg:'#1E40AF', label:'🔨 Vyrábí se' },
    'sklad':     { bg:'#DCFCE7', fg:'#166534', label:'📦 Sklad' },
    'prodej':    { bg:'#A7F3D0', fg:'#065F46', label:'🛒 Prodej' },
    'expirovano':{ bg:'#FEE2E2', fg:'#991B1B', label:'⏰ Expirováno' },
    'staženo':   { bg:'#E5E7EB', fg:'#374151', label:'🚫 Staženo' },
  };

  body.innerHTML = `
    <div class="card-block" style="margin-bottom:14px;display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:10px">
      <div style="display:flex;gap:16px;flex-wrap:wrap">
        <div><strong style="font-size:20px">${s.total||0}</strong> <span style="color:var(--text-3);font-size:12px">celkem</span></div>
        <div><strong style="font-size:20px;color:#166534">${s.aktivni||0}</strong> <span style="color:var(--text-3);font-size:12px">aktivní</span></div>
        <div><strong style="font-size:20px;color:#F59E0B">${s.expirujici||0}</strong> <span style="color:var(--text-3);font-size:12px">expirují do 3 dnů</span></div>
        <div><strong style="font-size:20px;color:#dc2626">${s.expirovane||0}</strong> <span style="color:var(--text-3);font-size:12px">expirovaly</span></div>
      </div>
      <button class="btn-primary btn-green" onclick="batchEdit(0)">+ Nová šarže</button>
    </div>

    <div style="display:flex;gap:6px;margin-bottom:12px;flex-wrap:wrap">
      ${['aktivni','expirujici','expirovane','vse'].map(f => `
        <button class="${filter===f ? 'btn-primary' : 'btn-secondary'}" style="padding:6px 14px;font-size:12px" onclick="state._batchFilter='${f}';renderBatches()">
          ${({aktivni:'🟢 Aktivní',expirujici:'⏰ Expirují brzy',expirovane:'🔴 Expirované',vse:'📋 Vše'})[f]}
        </button>
      `).join('')}
    </div>

    ${(data.batches || []).length === 0 ? emptyState({
      icon: '📋', title: 'Žádné šarže v této kategorii',
      msg: 'Pro každou várku výroby vytvoř šaržový záznam pro HACCP audit.',
      actions: '<button class="btn-primary btn-green" onclick="batchEdit(0)">+ Vytvořit první šarži</button>',
    }) : `
      <div class="card-block" style="padding:0;overflow:hidden">
        <table style="width:100%;border-collapse:collapse">
          <thead style="background:var(--surface-2);font-size:11px;text-transform:uppercase;color:var(--text-3);font-weight:700">
            <tr>
              <th style="text-align:left;padding:10px 14px">Šarže</th>
              <th style="text-align:left;padding:10px 14px">Výrobek</th>
              <th style="text-align:right;padding:10px 14px">Množství</th>
              <th style="text-align:center;padding:10px 14px">Výroba</th>
              <th style="text-align:center;padding:10px 14px">DMT</th>
              <th style="text-align:center;padding:10px 14px">Teplota</th>
              <th style="text-align:center;padding:10px 14px">Stav</th>
              <th></th>
            </tr>
          </thead>
          <tbody>
            ${data.batches.map(b => {
              const c = stavBarvy[b.stav] || stavBarvy.sklad;
              const expirujeBrzy = b.dnu_do_dmt !== null && b.dnu_do_dmt <= 3 && b.dnu_do_dmt >= 0 && b.stav !== 'expirovano';
              const expirovalo = b.dnu_do_dmt < 0 || b.stav === 'expirovano';
              const tepOk = (b.teplota_min === null || b.teplota_skladu === null || parseFloat(b.teplota_skladu) >= parseFloat(b.teplota_min))
                         && (b.teplota_max === null || b.teplota_skladu === null || parseFloat(b.teplota_skladu) <= parseFloat(b.teplota_max));
              return `
                <tr style="border-top:1px solid var(--border);font-size:13px;${expirovalo ? 'background:#FEE2E2' : (expirujeBrzy ? 'background:#FEF3C7' : '')}">
                  <td style="padding:9px 14px;font-family:monospace;font-weight:600">${esc(b.sarze_kod)}</td>
                  <td style="padding:9px 14px;font-weight:500">${esc(b.vyrobek_nazev)}</td>
                  <td style="padding:9px 14px;text-align:right;font-variant-numeric:tabular-nums">${b.mnozstvi} ${esc(b.jednotka)}</td>
                  <td style="padding:9px 14px;text-align:center;color:var(--text-3);font-size:12px">${new Date(b.datum_vyroby).toLocaleDateString('cs-CZ')}</td>
                  <td style="padding:9px 14px;text-align:center;font-weight:${expirovalo || expirujeBrzy ? '700' : '500'};color:${expirovalo ? '#991B1B' : (expirujeBrzy ? '#92400E' : 'var(--text-1)')}">
                    ${new Date(b.dmt).toLocaleDateString('cs-CZ')}
                    ${b.dnu_do_dmt !== null ? `<div style="font-size:10px;font-weight:600">${b.dnu_do_dmt < 0 ? `${Math.abs(b.dnu_do_dmt)} dní po DMT` : `+${b.dnu_do_dmt} dní`}</div>` : ''}
                  </td>
                  <td style="padding:9px 14px;text-align:center;font-size:12px">
                    ${b.teplota_skladu !== null ? `<span style="${!tepOk ? 'color:#dc2626;font-weight:700' : ''}">${b.teplota_skladu}°C</span>` : '<span style="color:var(--text-3)">—</span>'}
                    ${b.teplota_min !== null && b.teplota_max !== null ? `<div style="font-size:10px;color:var(--text-3)">norma: ${b.teplota_min}–${b.teplota_max}°C</div>` : ''}
                  </td>
                  <td style="padding:9px 14px;text-align:center">
                    <span style="background:${c.bg};color:${c.fg};padding:2px 8px;border-radius:999px;font-size:10.5px;font-weight:700;white-space:nowrap">${c.label}</span>
                  </td>
                  <td style="padding:9px 14px;text-align:right;white-space:nowrap">
                    <button class="btn-secondary" style="font-size:11px;padding:4px 8px" onclick="batchCheck(${b.id})" title="Záznam kontroly (teplota apod.)">🌡️ Kontrola</button>
                    <button class="btn-secondary" style="font-size:11px;padding:4px 8px" onclick="batchEdit(${b.id})">✏️</button>
                  </td>
                </tr>
              `;
            }).join('')}
          </tbody>
        </table>
      </div>
    `}
  `;
}

window.batchEdit = async function(id) {
  let b = { id:0, sarze_kod:'', vyrobek_nazev:'', mnozstvi:0, jednotka:'ks',
            datum_vyroby:new Date().toISOString().slice(0,10), cas_vyroby:'',
            dmt:'', teplota_min:'', teplota_max:'', sklad_misto:'', operator:'',
            stav:'sklad', poznamka:'' };
  if (id) {
    try {
      const data = await api('admin_batches.php?id=' + id);
      b = { ...b, ...data };
      if (b.datum_vyroby) b.datum_vyroby = b.datum_vyroby.slice(0,10);
      if (b.dmt) b.dmt = b.dmt.slice(0,10);
    } catch (e) { alert('Chyba načtení: ' + e.message); return; }
  } else {
    // default DMT: +3 dny pro lahůdky
    const d = new Date(); d.setDate(d.getDate() + 3);
    b.dmt = d.toISOString().slice(0,10);
  }
  openModal(id ? `✏️ Šarže ${esc(b.sarze_kod)}` : '+ Nová šarže', `
    <div class="form-grid form-grid-tight">
      <div><label class="form-label">Šaržový kód ${id ? '' : '<span style="font-weight:400;color:var(--text-3);font-size:11px">(automaticky pokud nevyplníš)</span>'}</label>
        <input class="form-input" id="b-kod" value="${esc(b.sarze_kod)}" placeholder="YYMMDD-NNN" ${id ? 'readonly' : ''}>
      </div>
      <div><label class="form-label">Stav</label>
        <select class="form-input" id="b-stav">
          ${['vyrabi_se','sklad','prodej','expirovano','staženo'].map(s => `<option value="${s}" ${b.stav===s?'selected':''}>${s}</option>`).join('')}
        </select>
      </div>
      <div class="full"><label class="form-label">Výrobek *</label>
        <input class="form-input" id="b-vyrobek" value="${esc(b.vyrobek_nazev)}" placeholder="např. Vajíčková pomazánka 500g">
      </div>
      <div><label class="form-label">Množství</label>
        <input type="number" class="form-input" id="b-mn" value="${b.mnozstvi}" step="0.001">
      </div>
      <div><label class="form-label">Jednotka</label>
        <select class="form-input" id="b-j">
          ${['ks','kg','g','l','ml'].map(j => `<option ${b.jednotka===j?'selected':''}>${j}</option>`).join('')}
        </select>
      </div>
      <div><label class="form-label">📅 Datum výroby *</label>
        <input type="date" class="form-input" id="b-dv" value="${esc(b.datum_vyroby)}">
      </div>
      <div><label class="form-label">🕐 Čas výroby</label>
        <input type="time" class="form-input" id="b-cv" value="${esc(b.cas_vyroby || '')}">
      </div>
      <div><label class="form-label">⏰ DMT *</label>
        <input type="date" class="form-input" id="b-dmt" value="${esc(b.dmt)}">
      </div>
      <div><label class="form-label">📍 Místo skladu</label>
        <input class="form-input" id="b-sm" value="${esc(b.sklad_misto || '')}" placeholder="Lednice 1, regál B…">
      </div>
      <div><label class="form-label">🌡️ Teplota min (°C)</label>
        <input type="number" class="form-input" id="b-tmin" value="${b.teplota_min ?? ''}" step="0.5" placeholder="2">
      </div>
      <div><label class="form-label">🌡️ Teplota max (°C)</label>
        <input type="number" class="form-input" id="b-tmax" value="${b.teplota_max ?? ''}" step="0.5" placeholder="8">
      </div>
      <div class="full"><label class="form-label">👷 Operátor</label>
        <input class="form-input" id="b-op" value="${esc(b.operator || '')}" placeholder="Jméno pracovníka">
      </div>
      <div class="full"><label class="form-label">Poznámka</label>
        <textarea class="form-input" id="b-pozn" rows="2">${esc(b.poznamka || '')}</textarea>
      </div>
    </div>
    <div class="form-actions" style="justify-content:space-between">
      ${id ? `<button class="btn-secondary" style="color:#dc2626" onclick="batchDelete(${id})">🗑️ Smazat</button>` : '<div></div>'}
      <div style="display:flex;gap:8px">
        <button class="btn-secondary" onclick="closeModal()">Zrušit</button>
        <button class="btn-primary btn-green" onclick="batchSave(${id})">💾 Uložit</button>
      </div>
    </div>
  `);
};

window.batchSave = async function(id) {
  const vyr = document.getElementById('b-vyrobek')?.value?.trim();
  const dv  = document.getElementById('b-dv').value;
  const dmt = document.getElementById('b-dmt').value;
  if (!vyr || !dv || !dmt) { alert('Vyplň výrobek, datum výroby a DMT.'); return; }
  const payload = {
    sarze_kod: document.getElementById('b-kod').value.trim() || undefined,
    vyrobek_nazev: vyr,
    mnozstvi: parseFloat(document.getElementById('b-mn').value) || 0,
    jednotka: document.getElementById('b-j').value,
    datum_vyroby: dv,
    cas_vyroby: document.getElementById('b-cv').value || null,
    dmt,
    teplota_min: parseFloat(document.getElementById('b-tmin').value) || null,
    teplota_max: parseFloat(document.getElementById('b-tmax').value) || null,
    sklad_misto: document.getElementById('b-sm').value.trim() || null,
    operator: document.getElementById('b-op').value.trim() || null,
    stav: document.getElementById('b-stav').value,
    poznamka: document.getElementById('b-pozn').value.trim() || null,
  };
  try {
    if (id) await api('admin_batches.php?id=' + id, { method:'PUT', body: JSON.stringify(payload) });
    else    await api('admin_batches.php', { method:'POST', body: JSON.stringify(payload) });
    closeModal();
    toastSuccess(id ? 'Šarže upravena' : 'Šarže vytvořena');
    renderBatches();
  } catch (e) { alert('Chyba: ' + e.message); }
};

window.batchDelete = async function(id) {
  if (!await customConfirm('Smazat šarži?', 'Tato akce smaže i všechny kontrolní záznamy.', 'Smazat')) return;
  try {
    await api('admin_batches.php?id=' + id, { method:'DELETE' });
    closeModal();
    toastSuccess('Šarže smazána');
    renderBatches();
  } catch (e) { alert('Chyba: ' + e.message); }
};

window.batchCheck = async function(id) {
  openModal('🌡️ Záznam kontroly', `
    <div class="form-grid form-grid-tight">
      <div><label class="form-label">Typ kontroly</label>
        <select class="form-input" id="bc-typ">
          <option value="teplota">🌡️ Teplota</option>
          <option value="vizual">👁️ Vizuální</option>
          <option value="prijem">📦 Příjem</option>
          <option value="expirace">⏰ Kontrola DMT</option>
          <option value="jiny">📋 Jiný</option>
        </select>
      </div>
      <div><label class="form-label">Hodnota</label>
        <input class="form-input" id="bc-h" placeholder="např. 5.2 (°C)">
      </div>
      <div class="full"><label class="form-label">👷 Operátor</label>
        <input class="form-input" id="bc-op" placeholder="Jméno">
      </div>
      <div class="full">
        <label style="display:flex;align-items:center;gap:8px;font-size:13px;cursor:pointer">
          <input type="checkbox" id="bc-norma" checked> ✅ V normě
        </label>
      </div>
      <div class="full"><label class="form-label">Poznámka</label>
        <textarea class="form-input" id="bc-pozn" rows="2"></textarea>
      </div>
    </div>
    <div class="form-actions">
      <button class="btn-secondary" onclick="closeModal()">Zrušit</button>
      <button class="btn-primary btn-green" onclick="batchCheckSave(${id})">💾 Zaznamenat</button>
    </div>
  `);
};

window.batchCheckSave = async function(id) {
  try {
    await api('admin_batches.php?action=check&id=' + id, { method:'POST', body: JSON.stringify({
      typ: document.getElementById('bc-typ').value,
      hodnota: document.getElementById('bc-h').value.trim() || null,
      operator: document.getElementById('bc-op').value.trim() || null,
      'v_normě': document.getElementById('bc-norma').checked ? 1 : 0,
      poznamka: document.getElementById('bc-pozn').value.trim() || null,
    })});
    closeModal();
    toastSuccess('Kontrola zaznamenána');
    renderBatches();
  } catch (e) { alert('Chyba: ' + e.message); }
};

