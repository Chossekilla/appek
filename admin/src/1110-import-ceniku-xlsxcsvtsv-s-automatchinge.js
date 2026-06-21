// =============================================================
// 📊 IMPORT CENÍKU — XLSX/CSV/TSV s auto-matchingem (4-step wizard)
// =============================================================
state._cenikImport = { sessionId: null, target: null, headers: [], mapping: {}, sample: [], availableFields: {}, results: [], stats: null, candidates: [] };

window.openImportCenik = function(target) {
  state._cenikImport = { sessionId: null, target, headers: [], mapping: {}, sample: [], availableFields: {}, results: [], stats: null, candidates: [] };
  renderCenikStep1();
};

function renderCenikStep1() {
  const t = state._cenikImport.target;
  openModal(`📊 Import ceníku — ${t === 'vyrobky' ? 'Výrobky' : 'Suroviny'} (1/4)`, `
    <div style="background:#FFF8E7;border-left:3px solid #BA7517;padding:12px 14px;border-radius:8px;margin-bottom:14px;font-size:13px">
      <strong>📋 Jak to funguje:</strong>
      <ol style="margin:6px 0 0 18px;padding:0;line-height:1.6;font-size:12px">
        <li>Nahraješ XLSX/CSV/TSV s ceníkem</li>
        <li>Auto-detekce: aplikace pozná který sloupec je co (název, cena, EAN…). Můžeš přemapovat.</li>
        <li>Auto-matching: pokusí se napárovat řádky na existující ${t === 'vyrobky' ? 'výrobky' : 'suroviny'} podle EAN + jména. Ručně doplníš ambiguous.</li>
        <li>Apply: update existujících + vytvoření nových.</li>
      </ol>
    </div>
    <label class="form-label" style="margin-top:8px">Soubor (.xlsx, .csv, .tsv — max 10 MB)</label>
    <input type="file" id="cenik-file" accept=".xlsx,.csv,.tsv,.txt" style="display:block;width:100%;padding:14px;border:2px dashed var(--border);border-radius:10px;background:var(--surface-2);cursor:pointer">
    <div class="form-actions" style="margin-top:14px">
      <button class="btn-secondary" onclick="closeModal()">Zrušit</button>
      <div style="flex:1"></div>
      <button class="btn-primary btn-green" onclick="cenikUpload()">➜ Nahrát &amp; pokračovat</button>
    </div>
    <div id="cenik-progress" style="margin-top:10px;font-size:12px;color:var(--text-3)"></div>
  `, 'wide');
}

window.cenikUpload = async function() {
  const fi = document.getElementById('cenik-file');
  const file = fi?.files?.[0];
  if (!file) { alert('Vyber soubor.'); return; }
  const prog = document.getElementById('cenik-progress');
  if (prog) prog.innerHTML = '⏳ Nahrávám a parsuju soubor...';

  const fd = new FormData();
  fd.append('file', file);
  fd.append('target', state._cenikImport.target);
  try {
    const r = await fetch('../api/admin_import_cenik.php?action=upload', {
      method: 'POST', body: fd, credentials: 'same-origin', headers: csrfHeaders(),
    });
    const d = await r.json();
    if (!r.ok) throw new Error(d.error || 'Upload failed');

    state._cenikImport.sessionId      = d.session_id;
    state._cenikImport.headers        = d.headers;
    state._cenikImport.sample         = d.sample;
    state._cenikImport.totalRows      = d.total_rows;
    state._cenikImport.mapping        = d.auto_mapping || {};
    state._cenikImport.availableFields = d.available_fields || {};
    state._cenikImport.filename       = d.filename;
    renderCenikStep2();
  } catch (e) {
    if (prog) prog.innerHTML = `<div style="color:var(--danger-text);background:var(--danger-bg);padding:8px 12px;border-radius:6px">❌ ${esc(e.message)}</div>`;
  }
};

function renderCenikStep2() {
  const s = state._cenikImport;
  const fields = s.availableFields;
  // Pro každé DB pole nabídni dropdown s indexem do headers
  const fieldRows = Object.entries(fields).map(([fieldKey, label]) => {
    const sel = s.mapping[fieldKey] ?? '';
    const opts = `<option value="">— nemapováno —</option>` +
      s.headers.map((h, i) => `<option value="${i}" ${sel == i ? 'selected' : ''}>${esc(h || `(sloupec ${i + 1})`)}</option>`).join('');
    const auto = (s.mapping[fieldKey] !== undefined && s.mapping[fieldKey] !== '') ? '<span style="color:var(--success-text);font-size:11px;font-weight:600;margin-left:6px">✓ auto</span>' : '';
    return `
      <tr>
        <td style="font-weight:600;padding:6px 8px">${esc(label)}${auto}</td>
        <td style="padding:6px 8px"><select class="form-input" data-field="${fieldKey}" style="width:100%;padding:6px 10px;font-size:13px">${opts}</select></td>
      </tr>
    `;
  }).join('');

  // Sample preview tabulka
  const sampleRows = s.sample.map(row => `<tr>${row.map(c => `<td style="padding:4px 8px;border-bottom:1px solid var(--border);font-size:11px;color:var(--text-3);max-width:160px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap" title="${esc(c ?? '')}">${esc(c ?? '')}</td>`).join('')}</tr>`).join('');
  const sampleHead = s.headers.map(h => `<th style="padding:6px 8px;font-size:11px;background:var(--surface-2);font-weight:700;border-bottom:1px solid var(--border)">${esc(h || '?')}</th>`).join('');

  openModal(`📊 Import — Mapping sloupců (2/4)`, `
    <div style="font-size:13px;color:var(--text-2);margin-bottom:14px">
      📄 <strong>${esc(s.filename || '')}</strong> · ${s.totalRows} řádků · ${s.headers.length} sloupců
    </div>

    <details open style="margin-bottom:14px">
      <summary style="cursor:pointer;font-weight:600;font-size:13px;margin-bottom:8px">🔍 Náhled prvních 5 řádků</summary>
      <div style="max-height:200px;overflow:auto;border:1px solid var(--border);border-radius:8px">
        <table style="width:100%;border-collapse:collapse;font-size:12px">
          <thead><tr>${sampleHead}</tr></thead>
          <tbody>${sampleRows}</tbody>
        </table>
      </div>
    </details>

    <div style="font-weight:600;font-size:13px;margin-bottom:6px">🎯 Mapování sloupců souboru → DB pole</div>
    <p style="font-size:12px;color:var(--text-3);margin-bottom:8px">Auto-detekce zatrhla co umí (✓ auto). Můžeš změnit. <code>Název</code> je povinný.</p>
    <table style="width:100%;border-collapse:collapse;background:var(--surface-1)">
      <thead>
        <tr><th style="text-align:left;padding:6px 8px;font-size:11px;color:var(--text-3);background:var(--surface-2)">DB pole</th><th style="text-align:left;padding:6px 8px;font-size:11px;color:var(--text-3);background:var(--surface-2)">Sloupec v souboru</th></tr>
      </thead>
      <tbody>${fieldRows}</tbody>
    </table>

    <div style="margin-top:14px;font-weight:600;font-size:13px">🔗 Podle čeho párovat na existující záznamy?</div>
    <div style="display:flex;gap:14px;margin-top:6px;font-size:13px">
      <label><input type="checkbox" id="match-ean" checked> EAN (priorita 1)</label>
      <label><input type="checkbox" id="match-cislo" checked> Číslo/kód</label>
      <label><input type="checkbox" id="match-nazev" checked> Název (fuzzy)</label>
    </div>

    <div class="form-actions" style="margin-top:14px">
      <button class="btn-back" onclick="renderCenikStep1()">← Zpět</button>
      <div style="flex:1"></div>
      <button class="btn-primary btn-green" onclick="cenikRunMatch()">🔍 Spočítat shody &amp; pokračovat</button>
    </div>
  `, 'wide');

  // Wire up dropdown changes
  document.querySelectorAll('select[data-field]').forEach(sel => {
    sel.onchange = () => {
      const f = sel.dataset.field;
      state._cenikImport.mapping[f] = sel.value === '' ? undefined : Number(sel.value);
    };
  });
}

window.cenikRunMatch = async function() {
  const s = state._cenikImport;
  if (!s.mapping || s.mapping.nazev === undefined) {
    alert('Mapování pro "Název" je povinné. Vyber sloupec.');
    return;
  }
  const matchFields = [];
  if (document.getElementById('match-ean')?.checked) matchFields.push('ean');
  if (document.getElementById('match-cislo')?.checked) matchFields.push('cislo');
  if (document.getElementById('match-nazev')?.checked) matchFields.push('nazev');
  if (matchFields.length === 0) { alert('Vyber aspoň jeden způsob párování.'); return; }

  // Filtruj prázdné mapping fields
  const cleanMapping = {};
  Object.entries(s.mapping).forEach(([k, v]) => { if (v !== undefined && v !== '') cleanMapping[k] = v; });
  s.mapping = cleanMapping;

  try {
    const r = await api('admin_import_cenik.php?action=match', {
      method: 'POST',
      body: JSON.stringify({ session_id: s.sessionId, mapping: s.mapping, match_fields: matchFields }),
    });
    s.results = r.results;
    s.stats = r.stats;
    s.candidates = r.candidates;
    renderCenikStep3();
  } catch (e) {
    alert('Match chyba: ' + e.message);
  }
};

function renderCenikStep3() {
  const s = state._cenikImport;
  const statusColor = { matched: 'var(--success-text)', new: '#0071e3', ambiguous: '#92400e' };
  const statusBg    = { matched: '#DCFCE7', new: '#DBEAFE', ambiguous: '#FEF3C7' };
  const statusIco   = { matched: '✅', new: '➕', ambiguous: '⚠️' };
  const statusLabel = { matched: 'Shoda', new: 'Nový', ambiguous: 'Nejisté' };

  const rowsHtml = s.results.map(r => {
    const ex = r.extracted;
    const status = r.status;
    const tName = r.target_name;
    const sim = r.similarity ? ` (${Math.round(r.similarity * 100)}%)` : '';
    const candOpts = `<option value="">— nový záznam —</option>` +
      s.candidates.map(c => `<option value="${c.id}" ${r.target_id == c.id ? 'selected' : ''}>${esc(c.nazev)}</option>`).join('');

    return `
      <tr data-row="${r.row_idx}">
        <td style="padding:6px 8px;background:${statusBg[status]};color:${statusColor[status]};font-weight:600;font-size:11px;text-align:center">${statusIco[status]} ${statusLabel[status]}${sim}</td>
        <td style="padding:6px 8px;font-size:12px"><strong>${esc(ex.nazev || '?')}</strong>
          ${ex.ean ? `<br><span style="font-size:10px;color:var(--text-3);font-family:monospace">EAN: ${esc(ex.ean)}</span>` : ''}
          ${ex.cislo ? `<br><span style="font-size:10px;color:var(--text-3)">Kód: ${esc(ex.cislo)}</span>` : ''}
        </td>
        <td style="padding:6px 8px;font-size:11px;color:var(--text-3)">
          ${Object.entries(ex).filter(([k]) => !['nazev','ean','cislo'].includes(k)).map(([k,v]) => `${k}: <strong>${esc(v)}</strong>`).join(' · ') || '—'}
        </td>
        <td style="padding:6px 8px">
          <select class="form-input cenik-target" data-row="${r.row_idx}" style="width:100%;padding:5px 8px;font-size:12px">${candOpts}</select>
        </td>
        <td style="padding:6px 8px">
          <select class="form-input cenik-action" data-row="${r.row_idx}" style="width:100%;padding:5px 8px;font-size:12px">
            <option value="update" ${status !== 'new' ? 'selected' : ''}>📝 Update</option>
            <option value="create" ${status === 'new' ? 'selected' : ''}>➕ Vytvořit nový</option>
            <option value="skip">⏭️ Přeskočit</option>
          </select>
        </td>
      </tr>
    `;
  }).join('');

  openModal(`📊 Import — Auto-match výsledky (3/4)`, `
    <div style="display:grid;grid-template-columns:repeat(3,1fr);gap:8px;margin-bottom:14px">
      <div style="background:#DCFCE7;padding:10px 12px;border-radius:8px;text-align:center">
        <div style="font-size:11px;color:#166534;font-weight:600">✅ SHODA (UPDATE)</div>
        <div style="font-size:22px;font-weight:700;color:#166534">${s.stats.matched}</div>
      </div>
      <div style="background:#FEF3C7;padding:10px 12px;border-radius:8px;text-align:center">
        <div style="font-size:11px;color:#92400e;font-weight:600">⚠️ NEJISTÉ (zkontroluj)</div>
        <div style="font-size:22px;font-weight:700;color:#92400e">${s.stats.ambiguous}</div>
      </div>
      <div style="background:#DBEAFE;padding:10px 12px;border-radius:8px;text-align:center">
        <div style="font-size:11px;color:#1e40af;font-weight:600">➕ NOVÉ (vytvořit)</div>
        <div style="font-size:22px;font-weight:700;color:#1e40af">${s.stats.new}</div>
      </div>
    </div>

    <p style="font-size:12px;color:var(--text-3);margin-bottom:8px">U <strong>nejistých</strong> shod si vyber cílový záznam z dropdownu, nebo přepni na ➕ Vytvořit nový / ⏭️ Přeskočit.</p>

    <div style="max-height:400px;overflow:auto;border:1px solid var(--border);border-radius:8px">
      <table style="width:100%;border-collapse:collapse;font-size:12px">
        <thead style="position:sticky;top:0;background:var(--surface-2);z-index:1">
          <tr>
            <th style="padding:8px;font-size:11px;text-align:left;border-bottom:2px solid var(--border)">Stav</th>
            <th style="padding:8px;font-size:11px;text-align:left;border-bottom:2px solid var(--border)">Z importu</th>
            <th style="padding:8px;font-size:11px;text-align:left;border-bottom:2px solid var(--border)">Ostatní data</th>
            <th style="padding:8px;font-size:11px;text-align:left;border-bottom:2px solid var(--border)">Cíl</th>
            <th style="padding:8px;font-size:11px;text-align:left;border-bottom:2px solid var(--border)">Akce</th>
          </tr>
        </thead>
        <tbody>${rowsHtml}</tbody>
      </table>
    </div>

    <div class="form-actions" style="margin-top:14px">
      <button class="btn-back" onclick="renderCenikStep2()">← Zpět</button>
      <div style="flex:1"></div>
      <button class="btn-primary btn-green" onclick="cenikApply()">✓ Spustit import</button>
    </div>
  `, 'wide');
}

window.cenikApply = async function() {
  const s = state._cenikImport;
  // Collect decisions
  const decisions = s.results.map(r => ({
    row_idx: r.row_idx,
    action: document.querySelector(`select.cenik-action[data-row="${r.row_idx}"]`)?.value || 'skip',
    target_id: document.querySelector(`select.cenik-target[data-row="${r.row_idx}"]`)?.value || null,
  }));

  const summary = decisions.reduce((acc, d) => { acc[d.action] = (acc[d.action] || 0) + 1; return acc; }, {});
  const msg = `Spustit import?\n\n` +
              `• Update: ${summary.update || 0} záznamů\n` +
              `• Vytvořit nové: ${summary.create || 0} záznamů\n` +
              `• Přeskočit: ${summary.skip || 0} záznamů\n\n` +
              `Akce není automaticky reverzibilní — udělej zálohu DB jestli máš pochybnost.`;
  if (!(await confirmDialog({ msg: msg, danger: false }))) return;

  try {
    const r = await api('admin_import_cenik.php?action=apply', {
      method: 'POST',
      body: JSON.stringify({ session_id: s.sessionId, mapping: s.mapping, decisions }),
    });
    renderCenikStep4(r);
  } catch (e) {
    alert('Apply chyba: ' + e.message);
  }
};

function renderCenikStep4(stats) {
  const target = state._cenikImport.target;
  openModal(`📊 Import — Hotovo! (4/4)`, `
    <div style="background:#DCFCE7;border:1px solid #86EFAC;padding:14px;border-radius:10px;margin-bottom:14px;text-align:center">
      <div style="font-size:32px">🎉</div>
      <div style="font-size:16px;font-weight:700;color:#166534;margin-top:6px">Import dokončený</div>
    </div>

    <div style="display:grid;grid-template-columns:repeat(3,1fr);gap:8px;margin-bottom:14px">
      <div style="background:var(--surface-2);padding:12px;border-radius:8px;text-align:center">
        <div style="font-size:11px;color:var(--text-3);font-weight:600">UPDATED</div>
        <div style="font-size:24px;font-weight:700;color:#166534">${stats.updated || 0}</div>
      </div>
      <div style="background:var(--surface-2);padding:12px;border-radius:8px;text-align:center">
        <div style="font-size:11px;color:var(--text-3);font-weight:600">CREATED</div>
        <div style="font-size:24px;font-weight:700;color:#1e40af">${stats.created || 0}</div>
      </div>
      <div style="background:var(--surface-2);padding:12px;border-radius:8px;text-align:center">
        <div style="font-size:11px;color:var(--text-3);font-weight:600">SKIPPED</div>
        <div style="font-size:24px;font-weight:700;color:var(--text-3)">${stats.skipped || 0}</div>
      </div>
    </div>

    ${stats.errors && stats.errors.length ? `
      <details>
        <summary style="cursor:pointer;color:var(--danger-text);font-weight:600;font-size:13px">⚠️ ${stats.errors.length} chyb (rozbalit)</summary>
        <div style="background:var(--danger-bg);color:var(--danger-text);padding:10px;border-radius:6px;margin-top:6px;font-size:11px;max-height:200px;overflow:auto">
          ${stats.errors.map(e => `<div>${esc(e)}</div>`).join('')}
        </div>
      </details>
    ` : ''}

    <div class="form-actions" style="margin-top:14px">
      <div style="flex:1"></div>
      <button class="btn-primary btn-green" onclick="closeModal();navigate('${target}')">✓ Hotovo</button>
    </div>
  `, 'wide');
}

