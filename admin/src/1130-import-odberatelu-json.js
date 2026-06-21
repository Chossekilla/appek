// =============================================================
// IMPORT ODBĚRATELŮ (JSON)
// =============================================================
window.otevritImportOdberatelu = function() {
  openModal('📥 Import odběratelů (JSON / CSV)', `
    <div style="background:#F7F8FA;border:1px solid #E8D5B0;border-radius:8px;padding:14px 16px;margin-bottom:14px">
      <h3 style="margin:0 0 6px;font-size:14px;color:#854F0B">📋 Formát souboru</h3>
      <p style="font-size:12px;color:#854F0B;margin-bottom:8px;line-height:1.5">
        <strong>JSON</strong> nebo <strong>CSV</strong> (oddělovač <code>,</code> <code>;</code> tab) — seznam odběratelů s hlavičkou:
        <code style="background:#fff;padding:1px 6px;border-radius:3px">IDOdberatel</code> ·
        <code style="background:#fff;padding:1px 6px;border-radius:3px">ONazev</code> ·
        <code style="background:#fff;padding:1px 6px;border-radius:3px">Ulice</code> ·
        <code style="background:#fff;padding:1px 6px;border-radius:3px">PSC</code> ·
        <code style="background:#fff;padding:1px 6px;border-radius:3px">Mesto</code> ·
        <code style="background:#fff;padding:1px 6px;border-radius:3px">Identifikacni</code> (IČO) ·
        <code style="background:#fff;padding:1px 6px;border-radius:3px">Danove</code> (DIČ) ·
        <code style="background:#fff;padding:1px 6px;border-radius:3px">PlatceDane</code> ·
        <code style="background:#fff;padding:1px 6px;border-radius:3px">MistoDodani</code> ·
        <code style="background:#fff;padding:1px 6px;border-radius:3px">UliceDodani</code> ·
        <code style="background:#fff;padding:1px 6px;border-radius:3px">MestoDodani</code> ·
        <code style="background:#fff;padding:1px 6px;border-radius:3px">PSCDodani</code>
      </p>
      <p style="font-size:11px;color:var(--text-3);margin:0">
        Když má odběratel <code>MistoDodani</code> vyplněné, automaticky se přidá jako výchozí pobočka.
      </p>
    </div>

    <div class="form-grid form-grid-tight">
      <div class="full">
        <label class="form-label">Soubor JSON nebo CSV *</label>
        <input class="form-input" id="impo-file" type="file" accept=".json,.csv,.tsv,application/json,text/csv">
      </div>
      <div class="full">
        <label class="checkbox-row" style="padding:6px 0">
          <input type="checkbox" id="impo-prepsat">
          <span>Přepsat existující odběratele (podle čísla)</span>
        </label>
      </div>
    </div>

    <div id="impo-preview" style="margin-top:14px"></div>

    <div class="form-actions">
      <button class="btn-secondary" onclick="closeModal()">Zrušit</button>
      <div style="flex:1"></div>
      <button class="btn-secondary" onclick="importOdbPreview()" id="impo-btn-preview">👁️ Náhled</button>
      <button class="btn-primary btn-green" onclick="importOdbSpustit()" id="impo-btn-spustit" disabled>📥 Spustit import</button>
    </div>
  `, 'wide');
};

window.importOdbPreview = async function() {
  const file = document.getElementById('impo-file').files[0];
  if (!file) return alert('Vyberte JSON soubor');
  const fd = new FormData();
  fd.append('soubor', file);
  fd.append('mode', 'preview');

  document.getElementById('impo-preview').innerHTML = '<div style="padding:20px;text-align:center;color:var(--text-3)">⏳ Analyzuji…</div>';

  try {
    const res = await fetch(`${API}/admin_odberatele_import.php`, { method: 'POST', credentials: 'include', headers: csrfHeaders(), body: fd });
    const data = await res.json();
    if (!res.ok) throw new Error(data.error || 'Chyba');

    document.getElementById('impo-btn-spustit').disabled = data.validnich === 0;

    document.getElementById('impo-preview').innerHTML = `
      <div style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:8px;margin-bottom:12px">
        <div class="stat-card"><div class="stat-label">Celkem</div><div class="stat-value">${data.celkem}</div></div>
        <div class="stat-card"><div class="stat-label">Validních</div><div class="stat-value" style="color:var(--success-text)">${data.validnich}</div></div>
        <div class="stat-card"><div class="stat-label">Neúplných</div><div class="stat-value" style="color:${data.neuplnych > 0 ? 'var(--danger-text)' : 'var(--text-3)'}">${data.neuplnych}</div></div>
      </div>
      ${data.duvody && data.duvody.length > 0 ? `
        <details style="margin-bottom:12px;background:#FCEBEB;border-left:3px solid var(--danger-text);padding:8px 12px;border-radius:4px">
          <summary style="cursor:pointer;font-size:13px;color:var(--danger-text);font-weight:600">⚠️ ${data.duvody.length} přeskočených</summary>
          <ul style="margin:8px 0 0;padding-left:20px;font-size:12px;color:var(--text-2)">
            ${data.duvody.map(d => `<li><strong>${esc(d.nazev)}</strong> — ${esc(d.duvod)}</li>`).join('')}
          </ul>
        </details>
      ` : ''}
      <h4 style="font-size:13px;margin:0 0 6px;color:var(--text-2)">📋 Náhled (prvních ${data.vzorek.length}):</h4>
      <div style="max-height:300px;overflow-y:auto;border:1px solid var(--border);border-radius:6px">
        <table class="table" style="margin:0;font-size:12px">
          <thead style="position:sticky;top:0;background:#fff;z-index:1">
            <tr><th>Č.</th><th>Název</th><th>IČO/DIČ</th><th>Sídlo</th><th>Pobočka</th></tr>
          </thead>
          <tbody>
            ${data.vzorek.map(v => `
              <tr>
                <td>${esc(v.cislo)}</td>
                <td><strong>${esc(v.nazev)}</strong></td>
                <td>${esc(v.ico)}${v.dic ? '<br><span style="color:var(--text-3);font-size:11px">' + esc(v.dic) + '</span>' : ''}</td>
                <td>${esc(v.sidlo)}</td>
                <td>${v.pobocka ? '<strong>' + esc(v.pobocka) + '</strong>' + (v.pobocka_adr ? '<br><span style="color:var(--text-3);font-size:11px">' + esc(v.pobocka_adr) + '</span>' : '') : '<span style="color:var(--text-3)">—</span>'}</td>
              </tr>
            `).join('')}
          </tbody>
        </table>
      </div>
    `;
  } catch (e) {
    document.getElementById('impo-preview').innerHTML = `<div style="padding:14px;color:var(--danger-text);background:var(--danger-bg);border-radius:6px">❌ ${esc(e.message)}</div>`;
    document.getElementById('impo-btn-spustit').disabled = true;
  }
};

// ════════════════════════════════════════════════════════════
// 📇 IMPORT VIZITEK (vCard) — z mobilu, e-mailu, kontaktních aplikací
// ════════════════════════════════════════════════════════════
window.otevritImportVcard = function() {
  openModal('📇 Import vizitek (vCard)', `
    <div style="background:#F7F8FA;border:1px solid #E8D5B0;border-radius:8px;padding:14px 16px;margin-bottom:14px">
      <h3 style="margin:0 0 6px;font-size:14px;color:#854F0B">📋 Co je to vizitka (vCard)?</h3>
      <p style="font-size:12px;color:#854F0B;margin:0 0 6px;line-height:1.5">
        Standard pro výměnu kontaktů. Soubor končí na <code>.vcf</code>. Lze získat:
      </p>
      <ul style="font-size:12px;color:#854F0B;margin:0 0 6px;padding-left:20px;line-height:1.6">
        <li>📱 <strong>iPhone:</strong> Kontakty → vyberte kontakt → Sdílet kontakt → Mail/AirDrop</li>
        <li>🤖 <strong>Android:</strong> Kontakty → vyberte kontakt → ⋮ → Sdílet → vCard</li>
        <li>✉️ <strong>Gmail:</strong> Kontakty (contacts.google.com) → Export → vCard</li>
        <li>📒 <strong>Outlook:</strong> Lidé → vybrat → Akce → Předat dál jako vizitku</li>
      </ul>
      <p style="font-size:11px;color:var(--text-3);margin:0">
        Můžeš vložit buď <strong>soubor .vcf</strong>, nebo <strong>obsah</strong> přímo zkopírovaný (více vizitek najednou OK).
      </p>
    </div>

    <div class="form-grid form-grid-tight">
      <div class="full">
        <label class="form-label">🏷️ Typ pro všechny importované odběratele (volitelné)</label>
        <select class="form-input" id="vc-typ">
          <option value="">— nezařazovat —</option>
          ${(window.ODB_TYPY || []).map(t => `<option value="${t.id}">${t.ikona} ${esc(t.label)}</option>`).join('')}
        </select>
      </div>
      <div class="full">
        <label class="form-label">Soubor .vcf</label>
        <input class="form-input" id="vc-file" type="file" accept=".vcf,text/vcard,text/x-vcard" onchange="vcardNacistSoubor()">
      </div>
      <div class="full" style="text-align:center;color:var(--text-3);font-size:12px;padding:4px 0">— nebo —</div>
      <div class="full">
        <label class="form-label">Vložit obsah vizitky (BEGIN:VCARD…END:VCARD)</label>
        <textarea class="form-textarea" id="vc-text" rows="10" style="font-family:monospace;font-size:11px" placeholder="BEGIN:VCARD
VERSION:3.0
FN:Jan Novák
ORG:Restaurace u Lva
TEL:+420777111222
EMAIL:info@uleva.cz
ADR:;;Hlavní 12;Praha;;110 00;CZ
NOTE:IČO: 12345678
END:VCARD"></textarea>
      </div>
    </div>

    <div id="vc-preview" style="margin-top:14px"></div>

    <div class="form-actions">
      <button class="btn-secondary" onclick="closeModal()">Zrušit</button>
      <div style="flex:1"></div>
      <button class="btn-secondary" onclick="vcardNahled()">👁️ Náhled</button>
      <button class="btn-primary btn-green" onclick="vcardImport()">📥 Importovat</button>
    </div>
  `, 'wide');
};

window.vcardNacistSoubor = function() {
  const f = document.getElementById('vc-file').files[0];
  if (!f) return;
  const reader = new FileReader();
  reader.onload = (e) => {
    document.getElementById('vc-text').value = e.target.result;
    vcardNahled();
  };
  reader.readAsText(f, 'UTF-8');
};

// Rozdělí slepený text na jednotlivé vCard bloky
window.vcardRozdelit = function(text) {
  const out = [];
  const regex = /BEGIN:VCARD[\s\S]*?END:VCARD/g;
  let m;
  while ((m = regex.exec(text)) !== null) out.push(m[0]);
  return out;
};

window.vcardNahled = function() {
  const text = document.getElementById('vc-text').value.trim();
  const prev = document.getElementById('vc-preview');
  if (!text) { prev.innerHTML = '<div style="padding:8px;color:var(--text-3);font-size:12px">Vlož obsah vizitky nebo nahraj soubor .vcf</div>'; return; }
  const cards = window.vcardRozdelit(text);
  if (cards.length === 0) {
    prev.innerHTML = '<div style="padding:10px;background:var(--danger-bg);color:var(--danger-text);border-radius:6px;font-size:13px">❌ Žádné vCard bloky nenalezeny. Obsah musí obsahovat <code>BEGIN:VCARD</code> a <code>END:VCARD</code>.</div>';
    return;
  }
  // Heuristický náhled — vytáhne FN/ORG/TEL/EMAIL
  const items = cards.map((c, i) => {
    const fn = (c.match(/^FN[^:]*:(.+)$/m) || [])[1] || '';
    const org = (c.match(/^ORG[^:]*:(.+)$/m) || [])[1] || '';
    const tel = (c.match(/^TEL[^:]*:(.+)$/m) || [])[1] || '';
    const email = (c.match(/^EMAIL[^:]*:(.+)$/m) || [])[1] || '';
    const nazev = (org || fn || '(bez názvu)').split(';')[0].trim();
    return `<tr><td>${i+1}</td><td><strong>${esc(nazev)}</strong></td><td>${esc(tel)}</td><td>${esc(email)}</td></tr>`;
  });
  prev.innerHTML = `
    <div style="background:var(--success-bg);color:var(--success-text);padding:8px 12px;border-radius:6px;font-size:13px;margin-bottom:8px">
      ✅ Nalezeno <strong>${cards.length}</strong> vizitek
    </div>
    <div style="max-height:280px;overflow:auto;border:1px solid var(--border);border-radius:6px">
      <table class="table" style="margin:0;font-size:12px">
        <thead style="position:sticky;top:0;background:#fff"><tr><th>#</th><th>Název</th><th>Telefon</th><th>E-mail</th></tr></thead>
        <tbody>${items.join('')}</tbody>
      </table>
    </div>
  `;
};

window.vcardImport = async function() {
  const text = document.getElementById('vc-text').value.trim();
  if (!text) return alert('Vlož obsah vizitky nebo nahraj soubor .vcf');
  const cards = window.vcardRozdelit(text);
  if (cards.length === 0) return alert('Žádné platné vCard bloky.');
  const typ = document.getElementById('vc-typ').value || null;
  if (!(await confirmDialog({ msg: t('confirm_import_cards', { n: cards.length, typ_text: typ ? ' Všechny jako "' + (window.odbTypByKey(typ)?.label || typ) + '".' : '' }), danger: false }))) return;

  try {
    const res = await api('admin_odberatele.php?action=import_vcard', {
      method: 'POST',
      body: JSON.stringify({ vcards: cards, typ }),
    });
    {
      let warnings = '';
      if (res.errors && res.errors.length) {
        const extra = res.errors.length > 8 ? t('more_errors_suffix', { n: res.errors.length - 8 }) : '';
        warnings = '\n\nUpozornění:\n' + res.errors.slice(0, 8).join('\n') + extra;
      }
      alert(t('vcards_imported', { n: res.imported || 0, warnings }));
    }
    closeModal();
    renderOdberatele();
  } catch (e) {
    alert('❌ Chyba: ' + e.message);
  }
};

window.importOdbSpustit = async function() {
  const file = document.getElementById('impo-file').files[0];
  if (!file) return alert('Vyberte JSON soubor');
  if (!(await confirmDialog({ msg: 'Spustit import? Tato akce zapíše do databáze.', danger: false }))) return;

  const fd = new FormData();
  fd.append('soubor', file);
  fd.append('mode', 'import');
  fd.append('prepsat', document.getElementById('impo-prepsat').checked ? '1' : '0');

  document.getElementById('impo-btn-spustit').disabled = true;
  document.getElementById('impo-btn-spustit').textContent = '⏳ Importuji…';

  try {
    const res = await fetch(`${API}/admin_odberatele_import.php`, { method: 'POST', credentials: 'include', headers: csrfHeaders(), body: fd });
    const data = await res.json();
    if (!res.ok) throw new Error(data.error || 'Chyba');

    alert(
      `✅ Import dokončen.\n\n` +
      `Vloženo nových: ${data.vlozeno}\n` +
      `Přepsáno: ${data.prepsano}\n` +
      `Přeskočeno: ${data.preskoceno}\n` +
      `Vytvořeno poboček: ${data.vlozeno_pobocek}\n` +
      (data.chyby.length > 0 ? `\n⚠️ Chyby: ${data.chyby.length}` : '')
    );
    closeModal();
    navigate('odberatele');
  } catch (e) {
    alert('❌ Chyba: ' + e.message);
    document.getElementById('impo-btn-spustit').disabled = false;
    document.getElementById('impo-btn-spustit').textContent = '📥 Spustit import';
  }
};

