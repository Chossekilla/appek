// =============================================================
// ⏱️ DOBA PŘÍPRAVY PER VÝROBEK (Restaurace balíček)
// =============================================================
async function renderPrepTimes() {
  const body = document.getElementById('rest-tab-body');
  if (!body) return;
  body.innerHTML = skeletonCards(3);
  let data;
  try { data = await api('admin_prep_times.php'); }
  catch (e) { body.innerHTML = `<div class="alert err">${esc(e.message)}</div>`; return; }

  state._prepEdits = {};

  // 🆕 v3.0.171 — data pro tlačítkový filtr podle kuchyňské stanice
  const _vyr = data.vyrobky || [];
  const _stanice = data.stanice || [];
  const _cntFor = (sid) => _vyr.filter(v => (parseInt(v.kitchen_station_id) || 0) === sid).length;
  const _noneCnt = _cntFor(0);
  const _chips = _stanice.length ? `
    <div id="prep-station-chips" style="display:flex;flex-wrap:wrap;gap:8px;margin-bottom:14px">
      <button class="qp-chip is-active" onclick="prepFilterStation('all', this)">Vše <span style="opacity:.6;font-weight:700">${_vyr.length}</span></button>
      ${_stanice.map(s => `<button class="qp-chip" onclick="prepFilterStation('${s.id}', this)">${s.ikona || ''} ${esc(s.nazev)} <span style="opacity:.6;font-weight:700">${_cntFor(parseInt(s.id))}</span></button>`).join('')}
      ${_noneCnt ? `<button class="qp-chip" onclick="prepFilterStation('0', this)">— Bez stanice <span style="opacity:.6;font-weight:700">${_noneCnt}</span></button>` : ''}
    </div>` : '';

  body.innerHTML = `
    <div class="card-block" style="margin-bottom:14px;display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:10px">
      <div style="display:flex;gap:16px;flex-wrap:wrap">
        <div><strong style="font-size:20px">${data.stats?.total || 0}</strong> <span style="color:var(--text-3);font-size:12px">výrobků</span></div>
        <div><strong style="font-size:20px;color:#166534">${data.stats?.s_dobou || 0}</strong> <span style="color:var(--text-3);font-size:12px">s nastavenou dobou</span></div>
        <div><strong style="font-size:20px;color:#1E40AF">${data.stats?.avg_min || 0} min</strong> <span style="color:var(--text-3);font-size:12px">průměr</span></div>
      </div>
      <button class="btn-primary btn-green" onclick="prepTimesSaveAll()">💾 Uložit změny</button>
    </div>

    ${_chips}

    <div class="card-block" style="padding:0;overflow:hidden">
      <table style="width:100%;border-collapse:collapse">
        <thead style="background:var(--surface-2);font-size:11px;text-transform:uppercase;color:var(--text-3);font-weight:700">
          <tr>
            <th style="text-align:left;padding:10px 14px">Výrobek</th>
            <th style="text-align:left;padding:10px 14px">Kategorie</th>
            <th style="text-align:center;padding:10px 14px;width:130px">Doba (min)</th>
            <th style="text-align:left;padding:10px 14px;width:200px">Stanice kuchyně</th>
          </tr>
        </thead>
        <tbody>
          ${_vyr.map(v => `
            <tr data-station="${parseInt(v.kitchen_station_id) || 0}" style="border-top:1px solid var(--border);font-size:13px">
              <td style="padding:8px 14px">
                <strong>${esc(v.nazev)}</strong>
                ${v.cislo ? `<div style="font-size:11px;color:var(--text-3)">#${esc(v.cislo)}</div>` : ''}
              </td>
              <td style="padding:8px 14px;color:var(--text-3);font-size:12px">${esc(v.kategorie_nazev || '—')}</td>
              <td style="padding:8px 14px">
                <input type="number" class="form-input" min="0" step="1" value="${v.priprava_min || 10}"
                  onchange="prepTimeSetEdit(${v.id}, 'priprava_min', this.value)"
                  style="text-align:center;font-weight:700">
              </td>
              <td style="padding:8px 14px">
                <select class="form-input" onchange="prepTimeSetEdit(${v.id}, 'station_id', this.value)">
                  <option value="0">— Žádná —</option>
                  ${(data.stanice || []).map(s => `<option value="${s.id}" ${v.kitchen_station_id == s.id ? 'selected' : ''}>${s.ikona} ${esc(s.nazev)}</option>`).join('')}
                </select>
              </td>
            </tr>
          `).join('')}
        </tbody>
      </table>
    </div>

    <div class="card-block" style="margin-top:14px;background:#F0F9FF;border-color:#3B82F6">
      <h3 style="margin:0 0 6px;font-size:14px;color:#1E40AF">💡 Jak se počítá doba pro objednávku</h3>
      <p style="margin:0;font-size:12.5px;color:#1E40AF">
        Systém sčítá doby přípravy <strong>paralelně</strong> — vezme nejdéle pracující stanici (např. 18 min pizza + 6 min předkrm + 4 min pití = celkem ~18 min, ne 28). To dává realistický odhad SLA.
      </p>
    </div>
  `;
}

// 🆕 v3.0.171 — klientský filtr řádků podle kuchyňské stanice (nemění rozpracované edits)
window.prepFilterStation = function(stationId, el) {
  if (el && el.parentElement) {
    el.parentElement.querySelectorAll('.qp-chip').forEach(c => c.classList.remove('is-active'));
    el.classList.add('is-active');
  }
  document.querySelectorAll('#rest-tab-body table tbody tr').forEach(tr => {
    const s = tr.getAttribute('data-station') || '0';
    tr.style.display = (stationId === 'all' || String(s) === String(stationId)) ? '' : 'none';
  });
};

window.prepTimeSetEdit = function(id, field, value) {
  state._prepEdits[id] = state._prepEdits[id] || { id };
  state._prepEdits[id][field] = field === 'priprava_min' ? (parseInt(value) || 0) : (parseInt(value) || null);
};

window.prepTimesSaveAll = async function() {
  const updates = Object.values(state._prepEdits || {});
  if (updates.length === 0) { toastInfo('Žádné změny'); return; }
  try {
    const r = await api('admin_prep_times.php?action=bulk', { method:'POST', body: JSON.stringify({ updates })});
    toastSuccess(t('toast_n_changes_saved', { n: r.count }));
    state._prepEdits = {};
    renderPrepTimes();
  } catch (e) { alert('Chyba: ' + e.message); }
};

