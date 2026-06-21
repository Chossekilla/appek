// =============================================================
// 📋 CSV PÁROVAČKA — sloupce z CSV → pole suroviny
// =============================================================
const CSV_POLE = [
  { key: 'ignore',       label: '— Ignorovat —' },
  { key: 'nazev',        label: '📝 Název *' },
  { key: 'jednotka',     label: '📏 Jednotka (g/kg/ks/ml/l)' },
  { key: 'cena_baleni',  label: '💰 Cena za balení (Kč)' },
  { key: 'obsah_baleni', label: '📦 Obsah balení (množství)' },
  { key: 'alergen',      label: '⚠ Alergen' },
  { key: 'poznamka',     label: '✏️ Poznámka' },
];

function renderCsvImportBody() {
  const preview = state._csvImportPreview;
  if (!preview) {
    return `
      <p style="margin:0 0 12px;font-size:14px;color:var(--text-2)">
        Vlož CSV obsah (oddělovač <code>;</code> nebo <code>,</code> — detekuje se sám). První řádek = hlavička. Po načtení napárujeme sloupce na pole suroviny.
      </p>
      <textarea id="sur-csv-paste" class="form-input" rows="10" style="font-family:monospace;font-size:13px;width:100%;resize:vertical" placeholder="Název;Jednotka;Cena;Obsah;Alergen
Pšeničná mouka T530;g;360;25000;lepek
Cukr krupice;g;1290;50000;
Mléko polotučné;ml;22;1000;mléko"></textarea>
      <div style="display:flex;gap:8px;margin-top:10px;flex-wrap:wrap">
        <button class="btn-primary" onclick="surCsvNacist()">📋 Načíst a napárovat</button>
        <button class="btn-secondary" onclick="surCsvNahratSoubor()">📁 Nahrát soubor (.csv)</button>
        <input type="file" id="sur-csv-file" accept=".csv,text/csv" style="display:none" onchange="surCsvSouborOnChange(this)">
      </div>
      <div style="margin-top:12px;padding:10px 12px;background:var(--surface-2);border-radius:6px;font-size:12px;color:var(--text-3)">
        💡 <strong>Tip:</strong> Exportuj z Excelu jako CSV. Stačí 2 sloupce (název + cena) — víc nemusíš.
      </div>
    `;
  }

  const { headers, rows, mapping } = preview;
  const ukazka = rows.slice(0, 5);

  return `
    <div style="display:flex;gap:8px;align-items:center;margin-bottom:14px">
      <span style="font-size:13px;color:var(--text-2)"><strong>${rows.length}</strong> řádků dat, <strong>${headers.length}</strong> sloupců</span>
      <span style="flex:1"></span>
      <button class="btn-secondary" style="font-size:12px;padding:6px 12px" onclick="state._csvImportPreview=null;vykreslitImportSurovin()">↶ Zrušit a vložit znovu</button>
    </div>

    <p style="margin:0 0 8px;font-size:13px;font-weight:600;color:var(--text-2)">Napáruj sloupce:</p>
    <div style="overflow-x:auto;border:1px solid var(--border);border-radius:8px;margin-bottom:12px">
      <table class="table sur-csv-table" style="margin:0;min-width:520px">
        <thead>
          <tr>
            <th style="width:200px">Sloupec v CSV</th>
            <th>Mapuj na pole</th>
            <th style="width:200px">Příklad hodnoty</th>
          </tr>
        </thead>
        <tbody>
          ${headers.slice(0, 8).map((h, i) => `
            <tr>
              <td><strong>${esc(h)}</strong></td>
              <td>
                <select class="form-input" data-col="${i}" onchange="surCsvSetMapping(${i}, this.value)" style="font-size:13px;padding:6px 10px;height:32px">
                  ${CSV_POLE.map(p => `<option value="${p.key}" ${mapping[i] === p.key ? 'selected' : ''}>${esc(p.label)}</option>`).join('')}
                </select>
              </td>
              <td style="color:var(--text-3);font-size:12px;font-style:italic">${esc((rows[0]?.[i] || '').toString().slice(0, 30))}</td>
            </tr>
          `).join('')}
          ${headers.length > 8 ? `<tr><td colspan="3" style="color:var(--text-3);font-size:12px;font-style:italic;text-align:center">⚠ Max 8 sloupců — zbylých ${headers.length - 8} se ignoruje.</td></tr>` : ''}
        </tbody>
      </table>
    </div>

    <p style="margin:0 0 8px;font-size:13px;font-weight:600;color:var(--text-2)">Náhled prvních ${ukazka.length} řádků (po napárování):</p>
    <div style="overflow-x:auto;border:1px solid var(--border);border-radius:8px">
      <table class="table" style="margin:0">
        <thead>
          <tr>
            <th>Název</th>
            <th>Jednotka</th>
            <th class="num">Cena balení</th>
            <th class="num">Obsah</th>
            <th>Alergen</th>
            <th class="num">Cena/jed</th>
          </tr>
        </thead>
        <tbody>
          ${ukazka.map((r, idx) => {
            const m = surCsvMapRow(r, mapping);
            const cenaJed = (m.cena_baleni > 0 && m.obsah_baleni > 0) ? m.cena_baleni / m.obsah_baleni : 0;
            const valid = !!m.nazev;
            return `
              <tr style="${!valid ? 'opacity:0.4' : ''}">
                <td>${valid ? `<strong>${esc(m.nazev)}</strong>` : '<span style="color:var(--danger-text)">❌ chybí název</span>'}</td>
                <td>${esc(m.jednotka || 'g')}</td>
                <td class="num">${m.cena_baleni > 0 ? m.cena_baleni.toFixed(2).replace('.', ',') + ' Kč' : '—'}</td>
                <td class="num">${m.obsah_baleni > 0 ? m.obsah_baleni : '—'}</td>
                <td>${m.alergen ? `<span style="font-size:11px;background:#fef3c7;color:#92400e;padding:1px 6px;border-radius:8px">${esc(m.alergen)}</span>` : '—'}</td>
                <td class="num">${cenaJed > 0 ? cenaJed.toFixed(4).replace(/\.?0+$/, '').replace('.', ',') + ' Kč' : '—'}</td>
              </tr>
            `;
          }).join('')}
        </tbody>
      </table>
    </div>
  `;
}

window.surCsvNahratSoubor = function() {
  document.getElementById('sur-csv-file')?.click();
};
window.surCsvSouborOnChange = function(input) {
  const file = input.files?.[0];
  if (!file) return;
  const reader = new FileReader();
  reader.onload = (e) => {
    document.getElementById('sur-csv-paste').value = e.target.result;
    surCsvNacist();
  };
  reader.readAsText(file, 'UTF-8');
};

window.surCsvNacist = function() {
  const raw = (document.getElementById('sur-csv-paste')?.value || '').trim();
  if (!raw) return alert('Vlož nebo nahraj CSV obsah.');

  // Detekce oddělovače
  const firstLine = raw.split(/\r?\n/)[0] || '';
  const delim = (firstLine.split(';').length > firstLine.split(',').length) ? ';' : ',';

  // Parser (jednoduchý, podporuje quoted "values")
  const parseLine = (line) => {
    const cells = [];
    let cur = '';
    let inQuotes = false;
    for (const ch of line) {
      if (ch === '"') { inQuotes = !inQuotes; continue; }
      if (ch === delim && !inQuotes) { cells.push(cur.trim()); cur = ''; continue; }
      cur += ch;
    }
    cells.push(cur.trim());
    return cells;
  };

  const lines = raw.split(/\r?\n/).filter(l => l.trim());
  if (lines.length < 2) return alert('CSV musí mít aspoň hlavičku + 1 řádek dat.');

  const headers = parseLine(lines[0]);
  const rows = lines.slice(1).map(parseLine);

  // Auto-mapping podle názvu sloupce
  const mapping = headers.map(h => {
    const hl = h.toLowerCase().trim();
    if (/n[aá]zev|jm[eé]no|name|product/.test(hl))         return 'nazev';
    if (/jednotk|unit/.test(hl))                            return 'jednotka';
    if (/cena.*bal|cena bal|cena_bal/.test(hl))             return 'cena_baleni';
    if (/^cena$|price|cena.kc|cena kc/.test(hl))            return 'cena_baleni';
    if (/obsah|mno[zž]stv[ií]|quantity|qty/.test(hl))       return 'obsah_baleni';
    if (/alergen|allergen/.test(hl))                        return 'alergen';
    if (/pozn[aá]mk|note|popis/.test(hl))                   return 'poznamka';
    return 'ignore';
  });

  state._csvImportPreview = { headers, rows, mapping };
  vykreslitImportSurovin();
};

function surCsvMapRow(row, mapping) {
  const result = { nazev: '', jednotka: 'g', cena_baleni: 0, obsah_baleni: 0, alergen: '', poznamka: '' };
  mapping.forEach((field, i) => {
    if (field === 'ignore' || !row[i]) return;
    const val = String(row[i]).trim();
    if (field === 'cena_baleni' || field === 'obsah_baleni') {
      result[field] = parseFloat(val.replace(',', '.').replace(/\s/g, '')) || 0;
    } else {
      result[field] = val;
    }
  });
  return result;
}

window.surCsvSetMapping = function(col, field) {
  if (!state._csvImportPreview) return;
  state._csvImportPreview.mapping[col] = field;
  vykreslitImportSurovin();
};

window.surCsvSubmit = async function() {
  const preview = state._csvImportPreview;
  if (!preview) return alert('Nejdřív načti CSV.');

  const items = preview.rows.map(r => surCsvMapRow(r, preview.mapping)).filter(i => i.nazev);
  if (items.length === 0) return alert('Žádné platné řádky (chybí název ve všech).');

  try {
    const res = await api('admin_suroviny_import.php', {
      method: 'POST',
      body: { suroviny: items },
    });
    alert(t('csv_import_done', { created: res.vytvoreno, updated: res.aktualizovano, total: res.celkem, errors: res.chyby?.length ? '\n\n⚠ Chyby:\n' + res.chyby.join('\n') : '' }));
    state._csvImportPreview = null;
    closeModal();
    renderSuroviny();
  } catch (e) {
    alert('Chyba importu: ' + e.message);
  }
};

// Alias (modal submit button volá `surImportCsvSubmit`)
window.surImportCsvSubmit = window.surCsvSubmit;

function emptyImportRow() {
  return { nazev: '', jednotka: 'g', cena_baleni: '', obsah_baleni: '', ks_krabice: '', hmotnost_ks: '' };
}
window.surImportRowEdit = function(i, field, val) {
  const r = state._importSurovinyRows[i];
  if (!r) return;
  r[field] = val;
  // Update jen computed buňky + counter — žádný full re-render (input by ztratil focus)
  surImportUpdateComputed(i);
  surImportUpdateCounter();
  // Při změně jednotky překresli select (kvůli selected attribute) — focus si user obnoví sám
  if (field === 'jednotka') {
    vykreslitImportSurovin();
  }
};
function surImportUpdateComputed(i) {
  const r = state._importSurovinyRows[i];
  const cell = document.querySelector(`[data-computed-row="${i}"]`);
  if (!cell || !r) return;
  const cena  = parseFloat(r.cena_baleni)  || 0;
  const obsah = parseFloat(r.obsah_baleni) || 0;
  const cenaJed = (cena > 0 && obsah > 0) ? cena / obsah : 0;
  const cenaKg  = (r.jednotka === 'g' && cenaJed > 0) ? cenaJed * 1000
                : (r.jednotka === 'ml' && cenaJed > 0) ? cenaJed * 1000
                : 0;
  cell.innerHTML = cenaJed > 0
    ? `${cenaJed.toFixed(4).replace(/\.?0+$/, '').replace('.', ',')} Kč/${esc(r.jednotka)}
       ${cenaKg > 0 ? `<div style="font-size:11px;font-weight:600;color:var(--text-3);margin-top:2px">${cenaKg.toFixed(2).replace('.', ',')} Kč/${r.jednotka === 'ml' ? 'l' : 'kg'}</div>` : ''}`
    : '<span style="color:var(--text-3);font-weight:400">—</span>';
}
function surImportUpdateCounter() {
  const n = (state._importSurovinyRows || []).filter(r => (r.nazev || '').trim()).length;
  // Counter ve footeru
  const counter = document.querySelector('.sur-import-counter');
  if (counter) counter.textContent = `${n} vyplněných řádků`;
  // Tlačítko Naimportovat
  const btn = document.querySelector('.sur-import-submit');
  if (btn) {
    btn.disabled = n === 0;
    btn.textContent = `✓ Naimportovat ${n > 0 ? n : ''} surovin`.replace(/\s+/g, ' ');
  }
}
window.surImportRowAdd = function() {
  state._importSurovinyRows.push(emptyImportRow());
  vykreslitImportSurovin();
};
window.surImportRowsAdd5 = function() {
  for (let i = 0; i < 5; i++) state._importSurovinyRows.push(emptyImportRow());
  vykreslitImportSurovin();
};
window.surImportRowRemove = function(i) {
  state._importSurovinyRows.splice(i, 1);
  if (state._importSurovinyRows.length === 0) state._importSurovinyRows.push(emptyImportRow());
  vykreslitImportSurovin();
};

window.surImportToggle = function(idx) {
  const sel = state._importSurovinySelected;
  if (sel.has(idx)) sel.delete(idx); else sel.add(idx);
  vykreslitImportSurovin();
};

window.surImportToggleAll = function(checkAll) {
  state._importSurovinySelected = checkAll
    ? new Set(SUROVINY_ZAKLADNI_BALICEK.map((_, i) => i))
    : new Set();
  vykreslitImportSurovin();
};

window.surImportBalicek = async function() {
  const sel = state._importSurovinySelected;
  const items = SUROVINY_ZAKLADNI_BALICEK.filter((_, i) => sel.has(i));
  if (items.length === 0) return alert('Nic není vybráno.');

  try {
    const r = await api('admin_suroviny_import.php', {
      method: 'POST',
      body: { suroviny: items },
    });
    alert(t('import_done', { created: r.vytvoreno, updated: r.aktualizovano, total: r.celkem, errors: r.chyby?.length ? '\n\n⚠ Chyby:\n' + r.chyby.join('\n') : '' }));
    closeModal();
    renderSuroviny();
  } catch (e) {
    alert('Chyba importu: ' + e.message);
  }
};

window.surImportRucni = async function() {
  const rows = (state._importSurovinyRows || []).filter(r => (r.nazev || '').trim());
  if (rows.length === 0) return alert('Žádné vyplněné řádky.');

  const items = rows.map(r => {
    const ks_krabice = parseFloat(r.ks_krabice) || 0;
    const hmotnost_ks = parseFloat(r.hmotnost_ks) || 0;
    const poznamkaParts = [];
    if (ks_krabice > 0) poznamkaParts.push(`${ks_krabice} ks v krabici`);
    if (hmotnost_ks > 0 && r.jednotka === 'ks') poznamkaParts.push(`${hmotnost_ks} g/ks`);
    return {
      nazev:        (r.nazev || '').trim(),
      jednotka:     r.jednotka || 'g',
      cena_baleni:  parseFloat(r.cena_baleni) || null,
      obsah_baleni: parseFloat(r.obsah_baleni) || null,
      poznamka:     poznamkaParts.length > 0 ? poznamkaParts.join(' · ') : null,
    };
  });

  try {
    const res = await api('admin_suroviny_import.php', {
      method: 'POST',
      body: { suroviny: items },
    });
    alert(t('import_done', { created: res.vytvoreno, updated: res.aktualizovano, total: res.celkem, errors: res.chyby?.length ? '\n\n⚠ Chyby:\n' + res.chyby.join('\n') : '' }));
    state._importSurovinyRows = null;   // reset
    closeModal();
    renderSuroviny();
  } catch (e) {
    alert('Chyba importu: ' + e.message);
  }
};

