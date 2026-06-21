// =============================================================
// IMPORT VÝROBKŮ (JSON)
// =============================================================
window.otevritImportVyrobku = function() {
  openModal('📥 Import výrobků (JSON / CSV)', `
    <div style="background:#F7F8FA;border:1px solid #E8D5B0;border-radius:8px;padding:14px 16px;margin-bottom:14px">
      <h3 style="margin:0 0 6px;font-size:14px;color:#854F0B">📋 Formát souboru</h3>
      <p style="font-size:12px;color:#854F0B;margin-bottom:8px;line-height:1.5">
        <strong>JSON</strong> seznam výrobků nebo <strong>CSV</strong> (oddělovač <code>,</code> <code>;</code> nebo tab) s hlavičkou:<br>
        <code style="background:#fff;padding:1px 6px;border-radius:3px">IDVyrobek</code> ·
        <code style="background:#fff;padding:1px 6px;border-radius:3px">Nazev</code> ·
        <code style="background:#fff;padding:1px 6px;border-radius:3px">Popis</code> ·
        <code style="background:#fff;padding:1px 6px;border-radius:3px">Trvanlivost</code> ·
        <code style="background:#fff;padding:1px 6px;border-radius:3px">MernaJednotka</code> ·
        <code style="background:#fff;padding:1px 6px;border-radius:3px">Vaha</code> ·
        <code style="background:#fff;padding:1px 6px;border-radius:3px">CenaBezDPH</code> ·
        <code style="background:#fff;padding:1px 6px;border-radius:3px">SazbaDPH</code> ·
        <code style="background:#fff;padding:1px 6px;border-radius:3px">Smazano</code>
      </p>
      <p style="font-size:11px;color:var(--text-3);margin:0">
        Neúplné záznamy (bez názvu, ceny nebo jednotky) se přeskočí. Smazané se importují jako skryté.
      </p>
    </div>

    <div class="form-grid form-grid-tight">
      <div class="full">
        <label class="form-label">Soubor JSON nebo CSV *</label>
        <input class="form-input" id="imp-file" type="file" accept=".json,.csv,.tsv,application/json,text/csv">
      </div>
      <div class="full">
        <label class="checkbox-row" style="padding:6px 0">
          <input type="checkbox" id="imp-prepsat">
          <span>Přepsat existující výrobky (podle čísla / IDVyrobek)</span>
        </label>
      </div>
    </div>

    <div id="imp-preview" style="margin-top:14px"></div>

    <div class="form-actions">
      <button class="btn-secondary" onclick="closeModal()">Zrušit</button>
      <div style="flex:1"></div>
      <button class="btn-secondary" onclick="importVyrobkuPreview()" id="imp-btn-preview">👁️ Náhled</button>
      <button class="btn-primary btn-green" onclick="importVyrobkuSpustit()" id="imp-btn-spustit" disabled>📥 Spustit import</button>
    </div>
  `, 'wide');
};

window.importVyrobkuPreview = async function() {
  const file = document.getElementById('imp-file').files[0];
  if (!file) return alert('Vyberte JSON soubor');
  const fd = new FormData();
  fd.append('soubor', file);
  fd.append('mode', 'preview');

  document.getElementById('imp-preview').innerHTML = '<div style="padding:20px;text-align:center;color:var(--text-3)">⏳ Analyzuji…</div>';

  try {
    const res = await fetch(`${API}/admin_vyrobky_import.php`, { method: 'POST', credentials: 'include', headers: csrfHeaders(), body: fd });
    const data = await res.json();
    if (!res.ok) throw new Error(data.error || 'Chyba');

    document.getElementById('imp-btn-spustit').disabled = data.validnich === 0;

    document.getElementById('imp-preview').innerHTML = `
      <div style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:8px;margin-bottom:12px">
        <div class="stat-card"><div class="stat-label">Celkem</div><div class="stat-value">${data.celkem}</div></div>
        <div class="stat-card"><div class="stat-label">Validních</div><div class="stat-value" style="color:var(--success-text)">${data.validnich}</div></div>
        <div class="stat-card"><div class="stat-label">Neúplných</div><div class="stat-value" style="color:${data.neuplnych > 0 ? 'var(--danger-text)' : 'var(--text-3)'}">${data.neuplnych}</div></div>
      </div>
      ${data.duvody && data.duvody.length > 0 ? `
        <details style="margin-bottom:12px;background:#FCEBEB;border-left:3px solid var(--danger-text);padding:8px 12px;border-radius:4px">
          <summary style="cursor:pointer;font-size:13px;color:var(--danger-text);font-weight:600">⚠️ ${data.duvody.length} přeskočených (klikni pro detail)</summary>
          <ul style="margin:8px 0 0;padding-left:20px;font-size:12px;color:var(--text-2)">
            ${data.duvody.map(d => `<li><strong>${esc(d.nazev)}</strong> — ${esc(d.duvod)}</li>`).join('')}
          </ul>
        </details>
      ` : ''}
      <h4 style="font-size:13px;margin:0 0 6px;color:var(--text-2)">📋 Náhled (prvních ${data.vzorek.length}):</h4>
      <div style="max-height:240px;overflow-y:auto;border:1px solid var(--border);border-radius:6px">
        <table class="table" style="margin:0;font-size:12px">
          <thead style="position:sticky;top:0;background:#fff;z-index:1">
            <tr><th>Kód</th><th>Název</th><th>Jed.</th><th class="num">Hmot.</th><th class="num">Cena</th><th>DPH</th><th>Trvanl.</th><th>Stav</th></tr>
          </thead>
          <tbody>
            ${data.vzorek.map(v => `
              <tr>
                <td>${esc(v.cislo)}</td>
                <td>${esc(v.nazev)}</td>
                <td>${esc(v.jednotka)}</td>
                <td class="num">${v.vaha_g ? v.vaha_g + ' g' : '<span style="color:var(--text-3)">—</span>'}</td>
                <td class="num">${fmt(v.cena_bez_dph)}</td>
                <td>${v.sazba_dph}%</td>
                <td>${esc(v.trvanlivost || '')}</td>
                <td>${v.aktivni ? '<span class="status dorucena">Aktivní</span>' : '<span class="status zrusena">Skrytý</span>'}</td>
              </tr>
            `).join('')}
          </tbody>
        </table>
      </div>
    `;
  } catch (e) {
    document.getElementById('imp-preview').innerHTML = `<div style="padding:14px;color:var(--danger-text);background:var(--danger-bg);border-radius:6px">❌ ${esc(e.message)}</div>`;
    document.getElementById('imp-btn-spustit').disabled = true;
  }
};

window.importVyrobkuSpustit = async function() {
  const file = document.getElementById('imp-file').files[0];
  if (!file) return alert('Vyberte JSON soubor');
  if (!(await confirmDialog({ msg: 'Spustit import? Tato akce zapíše do databáze.', danger: false }))) return;

  const fd = new FormData();
  fd.append('soubor', file);
  fd.append('mode', 'import');
  fd.append('prepsat', document.getElementById('imp-prepsat').checked ? '1' : '0');

  document.getElementById('imp-btn-spustit').disabled = true;
  document.getElementById('imp-btn-spustit').textContent = '⏳ Importuji…';

  try {
    const res = await fetch(`${API}/admin_vyrobky_import.php`, { method: 'POST', credentials: 'include', headers: csrfHeaders(), body: fd });
    const data = await res.json();
    if (!res.ok) throw new Error(data.error || 'Chyba');

    alert(
      `✅ Import dokončen.\n\n` +
      `Vloženo nových: ${data.vlozeno}\n` +
      `Přepsáno: ${data.prepsano}\n` +
      `Přeskočeno (existují, nepřepisovat): ${data.preskoceno}\n` +
      `Neúplných (vynecháno): ${data.neuplnych}\n` +
      (data.chyby.length > 0 ? `\n⚠️ Chyby: ${data.chyby.length}` : '')
    );
    closeModal();
    navigate('vyrobky');
  } catch (e) {
    alert('❌ Chyba: ' + e.message);
    document.getElementById('imp-btn-spustit').disabled = false;
    document.getElementById('imp-btn-spustit').textContent = '📥 Spustit import';
  }
};

