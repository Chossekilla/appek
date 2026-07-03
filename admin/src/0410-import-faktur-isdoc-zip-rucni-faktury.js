// ===================================================================
// IMPORT FAKTUR — ISDOC / ZIP → ruční faktury
// ===================================================================
window.otevritImportFaktur = function() {
  openModal('📥 Import faktur (ISDOC)', `
    <p style="font-size:14px;color:var(--text-2);margin-bottom:14px">
      Nahrajte <strong>.zip</strong> archiv s ISDOC fakturami, nebo jednotlivý <strong>.isdoc</strong> / <strong>.xml</strong> soubor.
      Importované faktury budou vytvořeny jako <strong>ruční</strong> (bez napojení na dodací list).
    </p>

    <div class="card-block" style="padding:16px;margin-bottom:14px;background:#F7F8FA;border:1px solid #E8D5B0;border-radius:10px">
      <h3 style="margin:0 0 10px;font-size:15px">📥 Vyber soubor</h3>
      <label class="form-label">ISDOC / ZIP soubor</label>
      <input type="file" id="imp-file" accept=".zip,.isdoc,.xml" class="form-input" style="padding:8px"
             onchange="document.getElementById('imp-preview-info').style.display='none';document.getElementById('imp-preview-body').innerHTML='';">
      <small style="display:block;color:var(--text-3);margin-top:6px;font-size:12px">
        Podporované formáty: .zip (více faktur), .isdoc, .xml
      </small>

      <div style="display:flex;gap:8px;margin-top:14px;flex-wrap:wrap">
        <button class="btn-secondary" onclick="importFakturPreview()">🔍 Náhled</button>
        <button class="btn-primary btn-green" onclick="importFakturProvest()">📥 Importovat</button>
      </div>

      <label style="display:flex;gap:8px;align-items:center;margin-top:12px;font-size:13px;color:var(--text-2);cursor:pointer">
        <input type="checkbox" id="imp-skip-dup" checked>
        <span>Přeskočit duplicitní faktury (podle čísla)</span>
      </label>
    </div>

    <div id="imp-preview-info" class="card-block" style="display:none;padding:14px;margin-bottom:14px;background:#F4F8FE;border:1px solid #C7DBFF;border-radius:10px">
      <h3 style="margin:0 0 10px;font-size:14px;color:#1849A9">📊 Náhled</h3>
      <div id="imp-preview-summary" style="font-size:13px;color:var(--text-2);margin-bottom:10px"></div>
      <div id="imp-preview-body" style="max-height:300px;overflow:auto"></div>
    </div>

    <div id="imp-result" style="display:none"></div>

    <div class="form-actions">
      <div style="flex:1"></div>
      <!-- "Zavřít" smazáno v v2.5.11 — × v rohu modal-card stačí (větší klikací plocha) -->
    </div>
  `);
};

async function _impSendFile(action) {
  const fileInput = document.getElementById('imp-file');
  if (!fileInput || !fileInput.files || fileInput.files.length === 0) {
    alert('Vyberte soubor');
    return null;
  }
  const skipDup = document.getElementById('imp-skip-dup')?.checked ? '1' : '0';

  const fd = new FormData();
  fd.append('file', fileInput.files[0]);
  fd.append('skip_duplicates', skipDup);

  const res = await fetch(`../api/admin_import_isdoc.php?action=${action}`, {
    method: 'POST',
    credentials: 'include',
    headers: csrfHeaders(),
    body: fd,
  });
  let j = null;
  try { j = await res.json(); } catch (e) {}
  if (!res.ok) {
    alert('Chyba: ' + (j?.error || res.statusText));
    return null;
  }
  return j;
}

window.importFakturPreview = async function() {
  const r = await _impSendFile('preview');
  if (!r) return;

  const info = document.getElementById('imp-preview-info');
  const sum  = document.getElementById('imp-preview-summary');
  const body = document.getElementById('imp-preview-body');
  info.style.display = '';

  const dups = r.duplicate || 0;
  const errs = (r.errors || []).length;
  sum.innerHTML = `
    <strong>${r.pocet}</strong> faktur k importu
    ${dups ? ` · <span style="color:#B0540B">⚠ ${dups} duplicitních</span>` : ''}
    ${errs ? ` · <span style="color:#B91C1C">✗ ${errs} chyb</span>` : ''}
  `;

  let html = '';
  if (r.items && r.items.length) {
    html += `
      <table class="table" style="font-size:12px">
        <thead><tr>
          <th>Soubor</th><th>Číslo</th><th>Datum</th><th>Odběratel</th>
          <th class="num">Pol.</th><th class="num">Částka</th><th>Stav</th>
        </tr></thead><tbody>
        ${r.items.map(i => `
          <tr ${i.duplicate ? 'style="background:#FFF6E6"' : ''}>
            <td style="font-family:monospace;font-size:11px">${esc(i.file)}</td>
            <td><strong>${esc(i.cislo)}</strong></td>
            <td>${fmtDate(i.datum_vystaveni)}</td>
            <td>${esc(i.odberatel)}${i.ico ? `<br><small style="color:var(--text-3)">IČO ${esc(i.ico)}</small>` : ''}</td>
            <td class="num">${i.pocet_polozek}</td>
            <td class="num">${fmt(i.castka_celkem)}</td>
            <td>${i.duplicate ? '<span style="color:#B0540B">⚠ existuje</span>' : '<span style="color:#1A7F37">✓ nová</span>'}</td>
          </tr>
        `).join('')}
        </tbody>
      </table>
    `;
  }
  if (r.errors && r.errors.length) {
    html += `<div style="margin-top:10px;padding:8px 10px;background:#FEF2F2;border:1px solid #FECACA;border-radius:6px;font-size:12px">
      <strong style="color:#B91C1C">Chyby parsování:</strong>
      <ul style="margin:6px 0 0 18px;padding:0">
        ${r.errors.map(e => `<li>${esc(e.file)}: ${esc(e.msg)}</li>`).join('')}
      </ul>
    </div>`;
  }
  body.innerHTML = html;
};

window.importFakturProvest = async function() {
  const fileInput = document.getElementById('imp-file');
  if (!fileInput || !fileInput.files || fileInput.files.length === 0) {
    return alert('Vyberte soubor');
  }
  if (!(await confirmDialog({ msg: 'Spustit import vybraného souboru?\n\nDuplicitní faktury budou ' +
      (document.getElementById('imp-skip-dup')?.checked ? 'přeskočeny.' : 'hlášeny jako chyba.'), danger: false }))) return;

  const r = await _impSendFile('import');
  if (!r) return;

  const res = document.getElementById('imp-result');
  res.style.display = '';
  res.innerHTML = `
    <div class="card-block" style="padding:14px;margin-bottom:14px;background:#F0FDF4;border:1px solid #BBF7D0;border-radius:10px">
      <h3 style="margin:0 0 8px;font-size:14px;color:#166534">✓ Import dokončen</h3>
      <div style="font-size:13px;color:var(--text-2)">
        <div><strong>${r.vytvoreno}</strong> faktur vytvořeno</div>
        ${r.preskoceno ? `<div>⏭ ${r.preskoceno} přeskočeno (duplicita)</div>` : ''}
        ${r.novych_odb ? `<div>👥 ${r.novych_odb} nových odběratelů přidáno</div>` : ''}
        ${r.chyb ? `<div style="color:#B91C1C">✗ ${r.chyb} chyb</div>` : ''}
      </div>
      ${(r.errors && r.errors.length) ? `
        <details style="margin-top:8px">
          <summary style="cursor:pointer;font-size:12px;color:#B91C1C">Zobrazit chyby</summary>
          <ul style="margin:6px 0 0 18px;padding:0;font-size:12px">
            ${r.errors.map(e => `<li>${esc(e.file)}: ${esc(e.msg)}</li>`).join('')}
          </ul>
        </details>
      ` : ''}
    </div>
  `;
  // Po importu obnovit seznam faktur
  if (r.vytvoreno > 0) {
    setTimeout(() => {
      closeModal();
      if (typeof renderFaktury === 'function') renderFaktury();
      else location.reload();
    }, 1500);
  }
};

window.openFakturaDetail = async function(id) {
  const f = await api(`admin_faktury.php?id=${id}`);
  const uhrazeno = f.castka_uhrazeno || 0;
  const zbyva = f.castka_celkem - uhrazeno;
  // Vypočtený stav úhrady (stejně jako v SQL)
  const dnes = new Date().toISOString().slice(0, 10);
  const stav = uhrazeno >= f.castka_celkem ? 'uhrazena'
    : (f.datum_splatnosti < dnes ? 'po_splatnosti' : 'cekajici');

  openModal(`Faktura ${f.cislo}`, `
    <!-- HLAVIČKA: amber Odběratel + blue Místo dodání / Stav -->
    <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px;margin-bottom:14px">
      <div style="background:#F7F8FA;border:1px solid #E8D5B0;border-radius:8px;padding:12px 14px">
        <div style="font-size:11px;text-transform:uppercase;letter-spacing:0.5px;color:#854F0B;margin-bottom:6px;font-weight:600">🏢 Odběratel</div>
        <div style="font-size:20px;font-weight:700">${esc(f.odberatel_nazev)}</div>
        ${f.odberatel_ico ? `<div style="font-size:13px;color:#854F0B;margin-top:2px">IČO: ${esc(f.odberatel_ico)}${f.odberatel_dic ? ' · DIČ: ' + esc(f.odberatel_dic) : ''}</div>` : ''}
      </div>
      <div style="background:#EFF6FF;border:1px solid #B5D4F4;border-radius:8px;padding:12px 14px">
        <div style="font-size:11px;text-transform:uppercase;letter-spacing:0.5px;color:#0C447C;margin-bottom:6px;font-weight:600">💰 Stav úhrady</div>
        <div>${stavUhradyBadge(stav)}</div>
        <div style="font-size:12px;color:#0C447C;margin-top:6px">Uhrazeno: <strong>${fmt(uhrazeno)}</strong>${zbyva > 0 ? ` · Zbývá: <strong style="color:${zbyva > 0 ? 'var(--danger-text)' : '#27500A'}">${fmt(zbyva)}</strong>` : ''}</div>
      </div>
    </div>

    <!-- 3 sloupce: Vystavení · Splatnost · Variabilní symbol -->
    <div class="form-grid form-grid-tight" style="grid-template-columns:1fr 1fr 1fr;margin-bottom:14px">
      <div>
        <label class="form-label">Datum vystavení</label>
        <input type="text" class="form-input" value="${fmtDate(f.datum_vystaveni)}" disabled style="color:var(--text-3)">
      </div>
      <div>
        <label class="form-label">Datum splatnosti</label>
        <input type="date" class="form-input" id="ff-splatnost" value="${f.datum_splatnosti}">
      </div>
      <div>
        <label class="form-label">Variabilní symbol</label>
        <input type="text" class="form-input" id="ff-vs" value="${esc(f.variabilni_symbol || '')}">
      </div>
    </div>

    <!-- Zdroj / vázané doklady (objednávka + DL) — v3.0.238 konzistentní řetězec obousměrně -->
    ${parseInt(f.rucni) === 1 && !(f.objednavky || []).length && !(f.dodaci_listy || []).length
      ? `<div style="margin-bottom:14px;padding:10px 14px;background:#F7F8FA;border-left:3px solid #BA7517;border-radius:4px;font-size:13px">
           <strong>✏️ Ručně vystavená faktura</strong> <span style="color:var(--text-3)">— bez vazby na objednávku/DL</span>
         </div>`
      : ((f.objednavky || []).length || (f.dodaci_listy || []).length)
        ? `<div style="display:flex;gap:8px;flex-wrap:wrap;align-items:center;margin-bottom:14px;padding:10px 14px;background:#F7F8FA;border:1px solid var(--border);border-radius:8px">
             <span style="font-size:12px;color:var(--text-3);font-weight:500">🔗 Vázané doklady:</span>
             ${(f.objednavky || []).map(o => `<a href="#" onclick="closeModal();setTimeout(()=>openObjednavkaDetail(${o.id}),100);return false" class="doc-badge obj" title="Otevřít objednávku ${esc(o.cislo)}">🛒 ${esc(o.cislo)}</a>`).join('')}
             ${(f.dodaci_listy || []).map(d => `<a href="#" onclick="closeModal();setTimeout(()=>openDodaciListDetail(${d.id}),100);return false" class="doc-badge dl" title="Otevřít dodací list ${esc(d.cislo)}">📃 ${esc(d.cislo)}</a>`).join('')}
           </div>`
        : ''
    }

    <h3 class="modal-section-title" style="display:flex;align-items:center;justify-content:space-between;gap:8px">
      <span>📋 Položky <span style="font-size:11px;color:var(--text-3);font-weight:400">(${(f.polozky||[]).length})</span></span>
    </h3>
    <div class="obj-polozky-list" id="fa-polozky">
      ${(f.polozky || []).length === 0 ? '<div class="empty-state" style="padding:24px;background:var(--surface-2);border-radius:8px">Žádné položky.</div>' : (f.polozky || []).map(p => {
        const celkem = parseFloat(p.cena_bez_dph) * parseFloat(p.mnozstvi) * (1 + parseFloat(p.sazba_dph) / 100);
        return `
          <div class="obj-polozka-row" data-pol-id="${p.id}">
            <div class="obj-polozka-img">${p.obrazek_url ? `<img src="${esc(p.obrazek_url)}" alt="">` : '<div class="obj-polozka-img-empty">🥖</div>'}</div>
            <div class="obj-polozka-info">
              <div class="obj-polozka-nazev">${esc(p.vyrobek_nazev)}</div>
              <div class="obj-polozka-meta">${fmt(p.cena_bez_dph)} / ${esc(p.jednotka || 'ks')} · DPH ${parseFloat(p.sazba_dph)}%</div>
            </div>
            <div class="obj-polozka-qty">
              <button type="button" class="qty-btn" onclick="faQtyAdj(${p.id}, -1)" title="−1">−</button>
              <input type="number" min="0" step="1" class="form-input qty-input"
                     value="${parseFloat(p.mnozstvi)}" data-orig="${parseFloat(p.mnozstvi)}" data-pol-id="${p.id}"
                     data-cena="${p.cena_bez_dph}" data-dph="${p.sazba_dph}" oninput="faQtyRecalc(${p.id})">
              <button type="button" class="qty-btn" onclick="faQtyAdj(${p.id}, 1)" title="+1">+</button>
            </div>
            <div class="obj-polozka-cena" id="fa-polozka-cena-${p.id}">${fmt(celkem)}</div>
            <button class="obj-polozka-del" onclick="smazatPolozkuFaktury(${p.id}, ${f.id})" title="Smazat">×</button>
          </div>
        `;
      }).join('')}
    </div>
    <div style="margin-top:10px;display:flex;justify-content:flex-end">
      <button class="btn-primary" style="font-size:13px" onclick="ulozitPolozkyFaktury(${f.id})">💾 Uložit změny množství</button>
    </div>

    <div style="margin-top:14px;background:#FAEEDA;border:2px solid #E5C499;border-radius:10px;padding:16px 22px">
      <div style="display:flex;justify-content:space-between;font-size:13px;color:#855;margin-bottom:4px">
        <span>Bez DPH</span><span>${fmt(f.castka_bez_dph)}</span>
      </div>
      <div style="display:flex;justify-content:space-between;font-size:13px;color:#855;margin-bottom:8px">
        <span>DPH</span><span>${fmt(f.castka_dph)}</span>
      </div>
      <div style="display:flex;justify-content:space-between;align-items:baseline;font-size:22px;font-weight:700;color:#854F0B;border-top:1px solid #E5C499;padding-top:8px">
        <span>Celkem s DPH</span><span>${fmt(f.castka_celkem)}</span>
      </div>
      <div style="display:flex;justify-content:space-between;font-size:12px;color:#854F0B;margin-top:6px;border-top:1px dashed #E5C499;padding-top:6px">
        <span>📅 Splatnost</span><span><strong>${fmtDate(f.datum_splatnosti)}</strong>${(f.castka_uhrazeno || 0) > 0 ? ` · uhrazeno ${fmt(f.castka_uhrazeno)}` : ''}</span>
      </div>
    </div>

    <h3 class="modal-section-title">💰 Úhrada</h3>
    <div class="form-grid form-grid-tight" style="grid-template-columns:1fr 1fr">
      <div>
        <label class="form-label">Uhrazená částka (Kč)</label>
        <div style="display:flex;gap:6px;align-items:center">
          <input type="number" step="0.01" class="form-input" id="ff-uhrazeno" value="${uhrazeno}" style="flex:1">
          <button class="btn-secondary" style="font-size:12px;padding:6px 10px;white-space:nowrap" onclick="document.getElementById('ff-uhrazeno').value='${f.castka_celkem}'" title="Plně uhrazeno">✅</button>
          <button class="btn-secondary" style="font-size:12px;padding:6px 10px" onclick="document.getElementById('ff-uhrazeno').value='0'" title="Reset">🔄</button>
        </div>
      </div>
      <div>
        <label class="form-label">Poznámka</label>
        <input type="text" class="form-input" id="ff-pozn" value="${esc(f.poznamka || '')}" placeholder="Volitelná...">
      </div>
    </div>

    <div class="form-actions">
      <div class="form-actions-tools">
        <a href="../api/faktura.php?id=${f.id}" target="_blank" class="btn-secondary" style="text-decoration:none;">📄 PDF</a>
        <a href="../api/admin_export_isdoc.php?action=isdoc&id=${f.id}" class="btn-secondary" style="text-decoration:none;" title="Stáhnout ISDOC XML pro účetnictví (Money S3, Pohoda…)">📤 ISDOC export</a>
        <button class="btn-secondary" onclick="tiskFaktury(${f.id})" title="Otevře tiskový dialog (PDF / tiskárna)">🖨️ Tisk</button>
        <button class="btn-secondary" onclick="noOpakovatZeZdroje('fa', ${f.id})" title="Vytvořit novou objednávku se stejnými položkami">🔁 Znovu objednat</button>
        ${!f.je_dobropis ? adminOnly(`<button class="btn-secondary" style="color:#DC2626;border-color:#FCA5A5" onclick="vystavitDobropis(${f.id}, '${esc(f.cislo).replace(/'/g, '')}')" title="Vystavit opravný daňový doklad (dobropis) — záporná částka se propíše do statistik">↩️ Dobropis</button>`) : ''}
        ${!f.je_dobropis ? adminOnly(`<button class="btn-secondary" style="color:#7c3aed;border-color:#c4b5fd" onclick="fakturaVymena(${f.id}, '${esc(f.cislo).replace(/'/g, '')}', ${f.odberatel_id || 'null'})" title="Výměna — dobropis vybraných položek + nová objednávka s novými, spočítá doplatek/přeplatek">🔄 Výměna</button>`) : ''}
      </div>
      <div class="form-actions-icons-row">
        <button class="btn-icon-corner" onclick="tiskNaTermo('fa', ${f.id}, '${esc(f.cislo).replace(/'/g, '')}')" title="Tisk na termo-tiskárnu (účtenka / bon)" aria-label="Tisk na tiskárnu">🖨️</button>
        <button class="btn-icon-corner" onclick="otevritOdeslatEmailem('fa', ${f.id}, '${esc(f.cislo).replace(/'/g, '')}', '${esc(f.odberatel_email || '').replace(/'/g, '')}')" title="Odeslat fakturu PDF emailem" aria-label="Odeslat e-mailem">✉️</button>
        ${adminOnly(`<button class="btn-danger-corner" onclick="smazatFakturu(${f.id})" title="Smazat fakturu" aria-label="Smazat fakturu">🗑️</button>`)}
      </div>
      <div style="flex:1"></div>
      <!-- "Zavřít" smazáno v v2.5.11 — × v rohu modal-card stačí (větší klikací plocha) -->
      <button class="btn-primary" onclick="ulozitFakturu(${f.id})">Uložit</button>
    </div>
  `, 'wide');
};

window.tiskFaktury = function(id) {
  // Otevře PDF s autoprint=1 — dialog Tisk se spustí sám
  window.open(`../api/faktura.php?id=${id}&autoprint=1`, '_blank');
};

// 🆕 v3.0.268 — VRATKY: dobropis k faktuře (opravný daňový doklad, řada DOB-)
window.vystavitDobropis = async function(id, cislo) {
  // 🆕 v3.0.275 — výběr položek + množství (částečný dobropis). Načti vratitelné řádky.
  let data;
  try { data = await api('admin_faktury.php?action=vratitelne&faktura_id=' + id); }
  catch (e) { toast('❌ ' + (e.message || 'Nelze načíst položky faktury'), 'error'); return; }
  const lines = (data.polozky || []).filter(p => p.zbyva > 0.0001);
  if (!lines.length) { toast('ℹ️ Faktura už je celá dobropisovaná', 'info'); return; }
  window._dobLines = {};
  const rows = lines.map(p => {
    window._dobLines[p.id] = p;
    const jiz = p.jiz_vraceno > 0 ? ` <span style="color:#999;font-size:11px">(už vráceno ${p.jiz_vraceno})</span>` : '';
    return `<tr>
      <td style="text-align:center;padding:4px"><input type="checkbox" data-dob-chk="${p.id}" checked onchange="dobRecalc()"></td>
      <td style="padding:4px">${esc(p.vyrobek_nazev)}${jiz}</td>
      <td style="text-align:right;padding:4px;white-space:nowrap">${fmt(p.cena_bez_dph)} <span style="color:#999">/${p.sazba_dph}%</span></td>
      <td style="text-align:right;padding:4px;white-space:nowrap"><input type="number" data-dob-qty="${p.id}" min="0" max="${p.zbyva}" step="any" value="${p.zbyva}" style="width:64px;text-align:right" oninput="dobRecalc()"> <span style="color:#999;font-size:11px">/ ${p.zbyva}</span></td>
    </tr>`;
  }).join('');
  openModal(`↩️ Dobropis k faktuře ${esc(cislo)}`, `
    <p style="margin:0 0 10px;font-size:13px;color:var(--text-2)">Vyber položky a množství k vrácení. Vznikne záporný opravný doklad (řada DOB-), který se odečte ze statistik. Lze vystavit i opakovaně, dokud něco zbývá.</p>
    <table style="width:100%;border-collapse:collapse;font-size:13px">
      <thead><tr style="border-bottom:1px solid var(--border);text-align:left;color:var(--text-2);font-size:11px;text-transform:uppercase">
        <th style="width:30px"></th><th style="padding:4px">Položka</th><th style="text-align:right;padding:4px">Cena/ks</th><th style="text-align:right;padding:4px">Vrátit</th>
      </tr></thead>
      <tbody>${rows}</tbody>
    </table>
    <div style="display:flex;justify-content:space-between;align-items:center;margin-top:12px;padding-top:10px;border-top:1px solid var(--border)">
      <strong>Dobropis celkem (s DPH):</strong><strong id="dob-sum" style="color:#DC2626;font-size:17px">0 Kč</strong>
    </div>
    <label style="display:block;margin-top:12px;font-size:13px">Důvod (volitelný)
      <input id="dob-duvod" type="text" style="width:100%;margin-top:4px" placeholder="např. reklamace, vrácené zboží">
    </label>
    <label style="display:flex;align-items:center;gap:8px;margin-top:10px;font-size:13px;cursor:pointer">
      <input type="checkbox" id="dob-restock" style="width:auto;margin:0"> ↩️ Vrátit zboží zpět na sklad</label>
    <div style="display:flex;gap:8px;justify-content:flex-end;margin-top:16px">
      <button class="btn-secondary" onclick="closeModal()">Zrušit</button>
      <button class="btn-primary" id="dob-submit" style="background:#DC2626;border-color:#DC2626" onclick="dobSubmit(${id})">↩️ Vystavit dobropis</button>
    </div>
  `, 'modal-wide');
  dobRecalc();
};

window.dobRecalc = function() {
  let sum = 0;
  Object.values(window._dobLines || {}).forEach(p => {
    const chk = document.querySelector(`[data-dob-chk="${p.id}"]`);
    const qtyEl = document.querySelector(`[data-dob-qty="${p.id}"]`);
    if (!chk || !qtyEl) return;
    let q = parseFloat(qtyEl.value) || 0;
    if (q > p.zbyva) { q = p.zbyva; qtyEl.value = p.zbyva; }
    if (q < 0) { q = 0; qtyEl.value = 0; }
    qtyEl.disabled = !chk.checked;
    if (chk.checked) sum += q * p.cena_bez_dph * (1 + p.sazba_dph / 100);
  });
  const el = document.getElementById('dob-sum');
  if (el) el.textContent = '−' + fmt(Math.round(sum * 100) / 100);
};

window.dobSubmit = async function(id) {
  const polozky = [];
  Object.values(window._dobLines || {}).forEach(p => {
    const chk = document.querySelector(`[data-dob-chk="${p.id}"]`);
    const qtyEl = document.querySelector(`[data-dob-qty="${p.id}"]`);
    if (chk && chk.checked && qtyEl) {
      const q = parseFloat(qtyEl.value) || 0;
      if (q > 0.0001) polozky.push({ polozka_id: p.id, mnozstvi: q });
    }
  });
  if (!polozky.length) { toast('Vyber alespoň jednu položku k vrácení', 'error'); return; }
  const btn = document.getElementById('dob-submit'); if (btn) btn.disabled = true;
  const duvod = (document.getElementById('dob-duvod') || {}).value || '';
  const vratitNaSklad = !!(document.getElementById('dob-restock') || {}).checked;
  const doPost = (vynutit) => api('admin_faktury.php?action=dobropis', {
    method: 'POST',
    body: JSON.stringify({ faktura_id: id, duvod, polozky, vynutit, vratit_na_sklad: vratitNaSklad }),
  });
  try {
    let r;
    try { r = await doPost(false); }
    catch (e1) {
      // 🆕 v3.0.278 — lhůta na vrácení (409) → nabídni override
      if (/lhůt|vynutit/i.test(e1.message || '') && confirm((e1.message || '') + '\n\nVystavit dobropis i přesto?')) r = await doPost(true);
      else throw e1;
    }
    toast(`✅ Dobropis ${r.cislo} vystaven (${fmt(r.castka_celkem)})${vratitNaSklad ? (r.restocked > 0 ? ` · 📦 ${r.restocked}× na sklad` : ' · sklad beze změny (zboží na zakázku)') : ''}`, 'success');
    closeModal();
    if (state.current === 'faktury') renderFaktury(state._faPag && state._faPag.filters || {});
    setTimeout(() => openFakturaDetail(r.id), 250);
  } catch (e) {
    toast('❌ ' + (e.message || 'Dobropis se nepodařilo vystavit'), 'error');
    if (btn) btn.disabled = false;
  }
};

// =============================================================
// 🔄 VÝMĚNA NA FAKTUŘE (v3.0.288) — dobropis vrácených + nová objednávka s novými + rozdíl.
//   Analogie POS výměny (pos.js posExchange): dobropis (částečný) + admin_objednavky create.
// =============================================================
window.fakturaVymena = async function(id, cislo, odberatelId) {
  let data, kat;
  try {
    [data, kat] = await Promise.all([
      api('admin_faktury.php?action=vratitelne&faktura_id=' + id),
      api('admin_vyrobky.php'),
    ]);
  } catch (e) { toast('❌ ' + (e.message || 'Nelze načíst data výměny'), 'error'); return; }
  const lines = (data.polozky || []).filter(p => p.zbyva > 0.0001);
  window._vymOdb = odberatelId || data.odberatel_id || null;
  window._vymCislo = cislo;
  window._vymId = id;
  window._vymLines = {};
  window._vymNove = [];
  window._vymKat = (Array.isArray(kat) ? kat : (kat.vyrobky || kat.data || [])).map(v => ({
    id: v.id, nazev: v.nazev, cena: parseFloat(v.cena_bez_dph) || 0,
    dph: parseFloat(v.sazba_dph != null ? v.sazba_dph : v.dph) || 0, jednotka: v.jednotka || 'ks',
  }));
  const vraTbl = lines.length ? lines.map(p => {
    window._vymLines[p.id] = p;
    const jiz = p.jiz_vraceno > 0 ? ` <span style="color:#999;font-size:11px">(už ${p.jiz_vraceno})</span>` : '';
    return `<tr>
      <td style="text-align:center;padding:3px"><input type="checkbox" data-vym-chk="${p.id}" onchange="vymRecalc()"></td>
      <td style="padding:3px">${esc(p.vyrobek_nazev)}${jiz}</td>
      <td style="text-align:right;padding:3px;white-space:nowrap">${fmt(p.cena_bez_dph)}</td>
      <td style="text-align:right;padding:3px"><input type="number" data-vym-qty="${p.id}" min="0" max="${p.zbyva}" step="any" value="${p.zbyva}" style="width:54px;text-align:right" oninput="vymRecalc()"> <span style="color:#999;font-size:11px">/${p.zbyva}</span></td>
    </tr>`;
  }).join('') : '<tr><td colspan="4" style="color:#999;padding:8px;font-size:13px">Faktura už je celá dobropisovaná — lze jen přidat nové.</td></tr>';
  const opts = '<option value="">— vyber výrobek —</option>' + window._vymKat
    .map(v => `<option value="${v.id}">${esc(v.nazev)} · ${fmt(v.cena)}</option>`).join('');
  openModal(`🔄 Výměna — faktura ${esc(cislo)}`, `
    <p style="margin:0 0 10px;font-size:13px;color:var(--text-2)">Vrať vybrané položky (vznikne dobropis) a/nebo přidej nové (vznikne nová objednávka). Spočítá se doplatek / přeplatek.</p>
    <div style="display:grid;grid-template-columns:1fr 1fr;gap:14px">
      <div>
        <h4 style="margin:0 0 6px;font-size:13px;color:#DC2626">↩️ Vrátit z faktury</h4>
        <table style="width:100%;border-collapse:collapse;font-size:13px">
          <thead><tr style="border-bottom:1px solid var(--border);text-align:left;color:var(--text-2);font-size:11px;text-transform:uppercase">
            <th style="width:26px"></th><th style="padding:3px">Položka</th><th style="text-align:right;padding:3px">Cena</th><th style="text-align:right;padding:3px">Vrátit</th>
          </tr></thead>
          <tbody>${vraTbl}</tbody>
        </table>
      </div>
      <div>
        <h4 style="margin:0 0 6px;font-size:13px;color:#16a34a">🆕 Nové položky</h4>
        <div style="display:flex;gap:6px;margin-bottom:6px">
          <input type="text" id="vym-filter" placeholder="filtr…" oninput="vymFilter(this.value)" style="width:90px">
          <select id="vym-select" style="flex:1;min-width:0">${opts}</select>
          <button class="btn-secondary" type="button" onclick="vymAddItem()" style="white-space:nowrap">+ Přidat</button>
        </div>
        <div id="vym-new-list" style="min-height:30px"></div>
      </div>
    </div>
    <div id="vym-net" style="margin-top:12px;padding-top:10px;border-top:1px solid var(--border);font-size:14px"></div>
    <div style="display:flex;gap:8px;justify-content:flex-end;margin-top:14px">
      <button class="btn-secondary" onclick="closeModal()">Zrušit</button>
      <button class="btn-primary" id="vym-submit" style="background:#7c3aed;border-color:#7c3aed" onclick="vymSubmit()">🔄 Provést výměnu</button>
    </div>
  `, 'modal-wide');
  vymRecalc();
};
window.vymFilter = function(q) {
  q = (q || '').toLowerCase().trim();
  const sel = document.getElementById('vym-select'); if (!sel) return;
  const list = q ? window._vymKat.filter(v => v.nazev.toLowerCase().includes(q)) : window._vymKat;
  sel.innerHTML = '<option value="">— vyber výrobek —</option>' + list.map(v => `<option value="${v.id}">${esc(v.nazev)} · ${fmt(v.cena)}</option>`).join('');
};
window.vymAddItem = function() {
  const sel = document.getElementById('vym-select'); if (!sel || !sel.value) return;
  const v = window._vymKat.find(x => x.id === parseInt(sel.value, 10)); if (!v) return;
  const ex = window._vymNove.find(n => n.vyrobek_id === v.id);
  if (ex) ex.mnozstvi += 1;
  else window._vymNove.push({ vyrobek_id: v.id, nazev: v.nazev, cena_bez_dph: v.cena, sazba_dph: v.dph, mnozstvi: 1 });
  vymRenderNew(); vymRecalc();
};
window.vymSetNewQty = function(vid, val) {
  const n = parseFloat(val) || 0;
  const it = window._vymNove.find(x => x.vyrobek_id === vid); if (!it) return;
  if (n <= 0) window._vymNove = window._vymNove.filter(x => x.vyrobek_id !== vid);
  else it.mnozstvi = n;
  vymRenderNew(); vymRecalc();
};
function vymRenderNew() {
  const el = document.getElementById('vym-new-list'); if (!el) return;
  el.innerHTML = window._vymNove.length ? window._vymNove.map(n => `
    <div style="display:flex;align-items:center;gap:6px;margin-bottom:4px;font-size:13px">
      <span style="flex:1;min-width:0;overflow:hidden;text-overflow:ellipsis;white-space:nowrap">${esc(n.nazev)}</span>
      <span style="color:#999">${fmt(n.cena_bez_dph)}</span>
      <input type="number" min="0" step="any" value="${n.mnozstvi}" style="width:50px;text-align:right" oninput="vymSetNewQty(${n.vyrobek_id}, this.value)">
    </div>`).join('') : '<div style="color:#9097a3;font-size:13px">Zatím nic.</div>';
}
window.vymRecalc = function() {
  let vrat = 0;
  Object.values(window._vymLines || {}).forEach(p => {
    const chk = document.querySelector(`[data-vym-chk="${p.id}"]`);
    const qtyEl = document.querySelector(`[data-vym-qty="${p.id}"]`);
    if (!chk || !qtyEl) return;
    let q = parseFloat(qtyEl.value) || 0;
    if (q > p.zbyva) { q = p.zbyva; qtyEl.value = p.zbyva; }
    if (q < 0) { q = 0; qtyEl.value = 0; }
    qtyEl.disabled = !chk.checked;
    if (chk.checked) vrat += q * p.cena_bez_dph * (1 + p.sazba_dph / 100);
  });
  let nove = 0;
  (window._vymNove || []).forEach(n => { nove += n.mnozstvi * n.cena_bez_dph * (1 + n.sazba_dph / 100); });
  vrat = Math.round(vrat * 100) / 100; nove = Math.round(nove * 100) / 100;
  const rozdil = Math.round((nove - vrat) * 100) / 100;
  let netLine;
  if (rozdil > 0.005) netLine = `<strong style="color:#DC2626">Doplatek odběratele: ${fmt(rozdil)}</strong>`;
  else if (rozdil < -0.005) netLine = `<strong style="color:#16a34a">Vrátit odběrateli: ${fmt(-rozdil)}</strong>`;
  else netLine = `<strong>Beze změny ceny</strong>`;
  const el = document.getElementById('vym-net'); if (!el) return;
  el.innerHTML = `
    <div style="display:flex;justify-content:space-between"><span>↩️ Vrácené (dobropis)</span><span style="color:#DC2626">− ${fmt(vrat)}</span></div>
    <div style="display:flex;justify-content:space-between"><span>🆕 Nová objednávka</span><span style="color:#16a34a">+ ${fmt(nove)}</span></div>
    <div style="display:flex;justify-content:space-between;margin-top:5px;font-size:15px">${netLine}<span></span></div>`;
};
window.vymSubmit = async function() {
  const vraceno = [];
  Object.values(window._vymLines || {}).forEach(p => {
    const chk = document.querySelector(`[data-vym-chk="${p.id}"]`);
    const qtyEl = document.querySelector(`[data-vym-qty="${p.id}"]`);
    if (chk && chk.checked && qtyEl) { const q = parseFloat(qtyEl.value) || 0; if (q > 0.0001) vraceno.push({ polozka_id: p.id, mnozstvi: q }); }
  });
  const nove = (window._vymNove || []).filter(n => n.mnozstvi > 0);
  if (!vraceno.length && !nove.length) { toast('Vyber co vrátit a/nebo přidej nové položky', 'error'); return; }
  if (nove.length && !window._vymOdb) { toast('❌ Faktura nemá odběratele — nové položky nelze založit', 'error'); return; }
  const btn = document.getElementById('vym-submit'); if (btn) btn.disabled = true;
  const dnes = new Date().toISOString().slice(0, 10);
  try {
    let dobCislo = null, objCislo = null;
    // 1) Dobropis vrácených (s 409 lhůta override)
    if (vraceno.length) {
      const doPost = (vynutit) => api('admin_faktury.php?action=dobropis', { method: 'POST', body: JSON.stringify({ faktura_id: window._vymId, duvod: 'Výměna za ' + window._vymCislo, polozky: vraceno, vynutit }) });
      let r1;
      try { r1 = await doPost(false); }
      catch (e1) { if (/lhůt|vynutit/i.test(e1.message || '') && confirm((e1.message || '') + '\n\nVystavit dobropis i přesto?')) r1 = await doPost(true); else throw e1; }
      dobCislo = r1.cislo;
    }
    // 2) Nová objednávka s novými položkami
    if (nove.length) {
      const polozky = nove.map(n => ({ vyrobek_id: n.vyrobek_id, mnozstvi: n.mnozstvi }));
      const r2 = await api('admin_objednavky.php', { method: 'POST', body: JSON.stringify({
        action: 'vytvorit', odberatel_id: window._vymOdb, misto_dodani_id: null,
        datum_dodani: dnes, polozky, poznamka: 'Výměna za fakturu ' + window._vymCislo,
      }) });
      objCislo = r2.cislo;
    }
    closeModal();
    toast(`✅ Výměna hotová${dobCislo ? ' · dobropis ' + dobCislo : ''}${objCislo ? ' · nová obj. ' + objCislo : ''}`, 'success');
    if (state.current === 'faktury') renderFaktury(state._faPag && state._faPag.filters || {});
    setTimeout(() => openFakturaDetail(window._vymId), 300);
  } catch (e) {
    if (btn) btn.disabled = false;
    toast('❌ ' + (e.message || 'Výměna selhala'), 'error');
  }
};

