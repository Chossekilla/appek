// =============================================================
// HACCP DOKUMENTACE FIRMY — sidebar list + editor obsahu
// =============================================================
// 🆕 v2.4.3 — async načtení firma_nazev pro WYSIWYG header v editoru
async function haccpEnsureFirmaNazev() {
  if (window._haccpFirmaNazev) return window._haccpFirmaNazev;
  try {
    const r = await api('firma_branding.php');
    window._haccpFirmaNazev = (r && r.firma_nazev) ? r.firma_nazev : 'Vaše firma s.r.o.';
  } catch (e) {
    window._haccpFirmaNazev = 'Vaše firma s.r.o.';
  }
  // Trigger re-render pokud už je tab vidět
  if (haccpState.tab === 'dokumenty') {
    setTimeout(() => { const el = document.querySelector('.haccp-doc-main'); if (el) renderHaccp(); }, 50);
  }
  return window._haccpFirmaNazev;
}

function haccpRenderDokumenty() {
  // Trigger async load (fire-and-forget) — uloží se do window cache
  if (!window._haccpFirmaNazev) haccpEnsureFirmaNazev();

  const data = haccpState.dokumenty || { kategorie: [], dokumenty: [] };
  const kategorie = Array.isArray(data.kategorie) ? data.kategorie : [];
  const dokumenty = Array.isArray(data.dokumenty) ? data.dokumenty : [];
  const aktId = haccpState.dok_aktivni_id;
  const akt = haccpState.dok_aktivni_obsah;

  // Pokud je seznam prázdný, ukáž import button
  if (dokumenty.length === 0) {
    return `
      <div class="card-block" style="padding:30px;text-align:center">
        <div style="font-size:42px;margin-bottom:12px">📚</div>
        <h3 style="margin:0 0 8px">Žádné firemní HACCP dokumenty</h3>
        <p style="color:var(--text-3);font-size:13px;margin-bottom:20px;max-width:480px;margin-left:auto;margin-right:auto">
          Naimportuj výchozí sadu dokumentů pekařství Appek — Plán HACCP, Sanitační řád, Vstupní instruktáž osobní hygieny, Postupy CCP, Formuláře, Záznamy o školení.
        </p>
        <button class="btn-primary" onclick="haccpDokImportDefault()">🔄 Naimportovat výchozí sadu</button>
      </div>
    `;
  }

  // Seskupit podle kategorie
  const byKat = {};
  dokumenty.forEach(d => {
    if (!byKat[d.kategorie]) byKat[d.kategorie] = [];
    byKat[d.kategorie].push(d);
  });

  const sidebarHtml = kategorie.map(k => `
    <div style="margin-bottom:14px">
      <div style="font-size:11px;font-weight:700;color:#854F0B;text-transform:uppercase;letter-spacing:0.6pt;padding:4px 8px">${esc(k.label)}</div>
      ${(byKat[k.key] || []).map(d => `
        <button onclick="haccpDokOtevrit(${d.id})" style="display:block;width:100%;text-align:left;padding:7px 10px;border:0;background:${aktId === d.id ? '#FFF8E7' : 'transparent'};border-left:3px solid ${aktId === d.id ? '#BA7517' : 'transparent'};font-size:13px;cursor:pointer;color:${aktId === d.id ? '#854F0B' : 'var(--text-1)'};font-weight:${aktId === d.id ? '600' : '400'}" onmouseover="this.style.background='${aktId === d.id ? '#FFF8E7' : 'var(--surface-2)'}'" onmouseout="this.style.background='${aktId === d.id ? '#FFF8E7' : 'transparent'}'">
          ${esc(d.nazev)}
          ${parseInt(d.aktivni) === 0 ? '<span style="font-size:10px;color:var(--text-3);margin-left:4px">(neaktivní)</span>' : ''}
        </button>
      `).join('')}
    </div>
  `).join('');

  return `
    <div class="card-block" style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:8px;padding:10px 14px;margin-bottom:14px">
      <div>
        <strong style="color:#854F0B">📚 Firemní HACCP dokumentace</strong>
        <span style="font-size:11px;color:var(--text-3);margin-left:6px">${dokumenty.length} dokumentů ve ${kategorie.filter(k => byKat[k.key]).length} kategoriích</span>
      </div>
      <div style="display:flex;gap:6px;flex-wrap:wrap">
        <button class="btn-secondary" onclick="haccpDokPersonalizovat()" style="font-size:12px;background:#ECFDF5;border-color:#10B981;color:#065F46" title="Nahradí v dokumentech výchozí 'APPEK' aktuálními údaji firmy z Nastavení (název, adresa, IČO, jednatel)">🏢 Personalizovat dle firmy</button>
        <button class="btn-secondary" onclick="haccpDokUpgrade()" style="font-size:12px;background:#FFF8E7;border-color:#F59E0B;color:#92400e" title="Přepíše obsah dokumentů aktualizovanou verzí dle aktuální legislativy 2025 (178/2002, 852/2004, 2073/2005, 1169/2011, zákon 110/1997 Sb.)">♻️ Aktualizovat dle směrnic 2025</button>
        <a href="../api/firemni_haccp.php?master=1" target="_blank" class="btn-primary" style="text-decoration:none;font-size:12px" title="Vygeneruje kompletní příručku se všemi dokumenty (PDF)">📄 Tisk celé příručky</a>
        <button class="btn-secondary" onclick="haccpDokNovy()" style="font-size:12px">+ Nový dokument</button>
      </div>
    </div>

    <div style="display:grid;grid-template-columns:280px 1fr;gap:14px;align-items:start">
      <!-- Sidebar -->
      <aside class="card-block" style="padding:8px 0;max-height:80vh;overflow-y:auto">
        ${sidebarHtml}
      </aside>

      <!-- Obsah / editor -->
      <main class="card-block haccp-doc-main" style="padding:0;background:#f5f5f0;border:0;box-shadow:none">
        ${aktId && akt ? `
          <!-- TOOLBAR (sticky horní lišta) -->
          <div class="haccp-doc-toolbar" style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:8px;padding:12px 16px;background:#fff;border:1px solid var(--border);border-radius:10px;margin-bottom:14px;position:sticky;top:8px;z-index:5;box-shadow:0 2px 8px rgba(0,0,0,0.05)">
            <div style="flex:1;min-width:220px;display:flex;flex-direction:column;gap:4px">
              <div style="display:flex;align-items:center;gap:8px;font-size:10pt;color:#854F0B;text-transform:uppercase;letter-spacing:1pt;font-weight:700">
                <select id="hd-dok-kat" onchange="const p=document.querySelector('.haccp-sheet .haccp-doc-kategorie');if(p)p.textContent=this.options[this.selectedIndex].text.replace(/^[^A-Za-z0-9ěščřžýáíéúůďťňĚŠČŘŽÝÁÍÉÚŮ]+/,'').trim()" style="font-size:10pt;border:1px dashed #BA7517;background:#FFFAF1;color:#854F0B;padding:2px 8px;border-radius:4px;font-weight:700;cursor:pointer">
                  ${kategorie.map(k => `<option value="${k.key}" ${akt.kategorie === k.key ? 'selected' : ''}>${esc(k.label)}</option>`).join('')}
                </select>
                · pořadí: <input type="number" id="hd-dok-poradi" value="${parseInt(akt.poradi) || 0}" style="width:50px;font-size:11px;border:1px dashed var(--border);background:transparent;text-align:center;border-radius:4px;padding:2px">
                · <label style="cursor:pointer;display:inline-flex;align-items:center;gap:4px;color:var(--text-2);font-weight:500"><input type="checkbox" id="hd-dok-aktivni" ${parseInt(akt.aktivni) === 1 ? 'checked' : ''}> aktivní</label>
              </div>
              <input class="form-input" id="hd-dok-nazev" value="${esc(akt.nazev || '')}" oninput="const t=document.getElementById('hd-dok-title-preview');if(t)t.textContent=this.value" style="font-size:18pt;font-weight:700;border-color:transparent;background:transparent;padding:0;color:#000;font-family:'Times New Roman',Georgia,serif">
            </div>
            <div style="display:flex;gap:6px;flex-wrap:wrap;align-self:flex-start">
              <a href="../api/firemni_haccp.php?id=${aktId}&download=1" download class="btn-secondary" style="text-decoration:none;font-size:12px;background:#ECFDF5;border-color:#10B981;color:#065F46" title="Stáhnout tento formulář">⬇️ Stáhnout</a>
              <a href="../api/firemni_haccp.php?id=${aktId}" target="_blank" class="btn-secondary" style="text-decoration:none;font-size:12px" title="Otevřít v novém okně">📄 Náhled ↗</a>
              <button class="btn-secondary" onclick="window.open('../api/firemni_haccp.php?id=${aktId}&autoprint=1', '_blank')" style="font-size:12px" title="Vytisknout">🖨 Tisk</button>
              <button class="btn-primary btn-green" onclick="haccpDokUlozit()" style="font-size:12px">💾 Uložit</button>
            </div>
          </div>

          <!-- A4 SHEET preview wrapper — INLINE STYLES guarantee 1:1 s firemni_haccp.php -->
          <div class="haccp-sheet-wrap" style="background:#f5f5f0;padding:14px;border-radius:12px;max-height:75vh;overflow-y:auto;border:1px solid rgba(0,0,0,0.08)">
            <!-- Scoped inline <style> — 1:1 mirror firemni_haccp.php (immune to admin.css overrides) -->
            <style>
              .hk-sheet { max-width:210mm; margin:0 auto; padding:18mm 18mm; background:#fff; min-height:297mm; box-shadow:0 2px 12px rgba(0,0,0,0.12); font-family:'Times New Roman',Georgia,serif !important; font-size:11pt; line-height:1.55; color:#000; }
              .hk-sheet, .hk-sheet * { font-family:'Times New Roman',Georgia,serif !important; }
              .hk-sheet .hk-doc-head { border-bottom:2px solid #BA7517; padding-bottom:6mm; margin-bottom:6mm; display:flex; justify-content:space-between; align-items:flex-end; }
              .hk-sheet .hk-firma { font-size:9pt; color:#666; line-height:1.3; }
              .hk-sheet .hk-firma strong { color:#000; font-size:10pt; }
              .hk-sheet .hk-meta { text-align:right; font-size:9pt; color:#666; }
              .hk-sheet .hk-kat { font-size:10pt; color:#854F0B; text-transform:uppercase; letter-spacing:1pt; font-weight:600; margin-bottom:1mm; }
              .hk-sheet .hk-title { font-size:18pt; font-weight:700; color:#000; margin-bottom:6mm; line-height:1.2; }
              .hk-sheet .hk-obsah { font-size:11pt; line-height:1.6; color:#000; min-height:200mm; outline:none; }
              .hk-sheet .hk-obsah:focus { box-shadow:inset 0 0 0 1px rgba(186,117,23,0.2); border-radius:4px; }
              .hk-sheet .hk-obsah h1 { font-size:18pt; margin-top:6mm; margin-bottom:3mm; color:#854F0B; font-weight:700; }
              .hk-sheet .hk-obsah h2 { font-size:16pt; margin-top:6mm; margin-bottom:3mm; color:#854F0B; font-weight:700; }
              .hk-sheet .hk-obsah h3 { font-size:13pt; margin-top:5mm; margin-bottom:2mm; color:#000; border-bottom:1px solid #ddd; padding-bottom:1mm; font-weight:700; }
              .hk-sheet .hk-obsah h4 { font-size:11.5pt; margin-top:4mm; margin-bottom:1mm; color:#000; font-weight:700; }
              .hk-sheet .hk-obsah p { margin-bottom:3mm; }
              .hk-sheet .hk-obsah ul, .hk-sheet .hk-obsah ol { margin:2mm 0 3mm 8mm; }
              .hk-sheet .hk-obsah li { margin-bottom:1mm; }
              .hk-sheet .hk-obsah dl { margin:2mm 0 3mm 4mm; }
              .hk-sheet .hk-obsah dt { font-weight:700; margin-top:2mm; }
              .hk-sheet .hk-obsah dd { margin-left:4mm; margin-bottom:1mm; }
              .hk-sheet .hk-obsah table { width:100%; border-collapse:collapse; margin:3mm 0; font-size:10pt; }
              .hk-sheet .hk-obsah th, .hk-sheet .hk-obsah td { border:0.4mm solid #999; padding:2mm 3mm; vertical-align:top; text-align:left; }
              .hk-sheet .hk-obsah th { background:#f5f5f0; font-weight:600; }
              .hk-sheet .hk-obsah strong { color:#000; font-weight:700; }
              .hk-sheet .hk-obsah em { color:#444; font-style:italic; }
              .hk-sheet .hk-obsah hr { border:none; border-top:1px dashed #999; margin:3mm 0; }
              .hk-sheet .hk-foot { margin-top:8mm; padding-top:6mm; border-top:1px solid #ccc; display:flex; justify-content:space-between; font-size:8.5pt; color:#666; }
              @media (max-width: 700px) {
                .hk-sheet { padding:8mm 6mm; min-height:auto; font-size:10.5pt; }
              }
            </style>
            <div class="hk-sheet">
              <!-- Cover header (firma vlevo / datum vpravo + brown line) -->
              <div class="hk-doc-head">
                <div class="hk-firma">
                  <strong>${esc(window._haccpFirmaNazev || '—')}</strong>
                </div>
                <div class="hk-meta">${new Date().toLocaleDateString('cs-CZ')}</div>
              </div>

              <!-- Category pill + Title (mirror PDF cover style) -->
              <div class="hk-kat">${esc((kategorie.find(k => k.key === akt.kategorie)?.label || '').replace(/^[^\w]+/, ''))}</div>
              <div class="hk-title" id="hd-dok-title-preview">${esc(akt.nazev || '')}</div>

              <!-- Editable obsah -->
              <div id="hd-dok-obsah" class="hk-obsah obsah" contenteditable="true">${akt.obsah || ''}</div>

              <!-- Footer s číslem stránky (jen vizuál) -->
              <div class="hk-foot">
                <div>${esc(window._haccpFirmaNazev || '—')}</div>
                <div>str. 1</div>
              </div>
            </div>
          </div>

          <div style="padding:8px 16px;background:var(--surface);border:1px solid var(--border);border-radius:8px;margin-top:8px;font-size:11px;color:var(--text-3);display:flex;gap:14px;flex-wrap:wrap;align-items:center">
            <span>💡 <strong>Editor jako papír A4:</strong> Co vidíš tady, vytiskne se. Ctrl+B (tučně), Ctrl+I (kurzíva).</span>
            <span style="margin-left:auto">Upraveno: ${akt.upraveno ? new Date(akt.upraveno.replace(' ', 'T')).toLocaleString('cs-CZ') : '—'}</span>
          </div>
          ${haccpJeAuditDokument(akt) ? renderHaccpAudityPanel() : ''}
        ` : `
          <div style="padding:60px;text-align:center;color:var(--text-3)">
            <div style="font-size:42px;margin-bottom:10px">📖</div>
            <p>Vyber dokument vlevo, nebo vytvoř <a href="javascript:haccpDokNovy()" style="color:var(--brand);cursor:pointer">+ nový</a>.</p>
          </div>
        `}
      </main>
    </div>
  `;
}

window.haccpDokUpgrade = function() {
  openModal('♻️ Aktualizace HACCP dokumentace dle aktuálních směrnic', `
    <p style="font-size:13px;color:var(--text-2);margin-bottom:14px">
      Přepíše obsah jednotlivých dokumentů aktualizovanou verzí, která zohledňuje:
    </p>

    <div style="background:#FFF8E7;padding:12px 16px;border-radius:8px;border:1px solid #F59E0B33;margin-bottom:14px">
      <h4 style="margin:0 0 8px;color:#92400e;font-size:13px">📜 Aktuální legislativa zapracována:</h4>
      <ul style="margin:0;padding-left:20px;font-size:12px;line-height:1.7">
        <li><strong>Nařízení (ES) č. 178/2002</strong> — obecné principy potravinového práva</li>
        <li><strong>Nařízení (ES) č. 852/2004</strong> — hygiena potravin (povinnost HACCP)</li>
        <li><strong>Nařízení (ES) č. 2073/2005</strong> — mikrobiologická kritéria</li>
        <li><strong>Nařízení (EU) č. 1169/2011</strong> — informace o potravinách (alergeny)</li>
        <li><strong>Zákon č. 110/1997 Sb.</strong> o potravinách (ve znění pozdějších předpisů)</li>
        <li><strong>Vyhláška č. 18/2024 Sb.</strong> o hygienických požadavcích na potraviny (ruší 137/2004 Sb.)</li>
        <li><strong>Codex Alimentarius CAC/RCP 1-1969</strong> Rev. 4-2003 (7 zásad HACCP)</li>
      </ul>
    </div>

    <div style="background:#F0F9FF;border:1px solid #93C5FD;padding:10px 14px;border-radius:8px;font-size:12px;margin-bottom:14px;color:#1e40af">
      <strong>✅ Co se zachová:</strong><br>
      • ID dokumentů a metadata · pořadí v sekci · vlastní úpravy které sis manuálně vytvořil<br>
      • <strong>Firemní data</strong> — APPEK B2B, [Vaše firma], kontakty<br>
      • <strong>Jména členů týmu HACCP</strong> a zaměstnanců (záznamy o školení)<br>
      • Datum, podpisy (zůstávají prázdná pole pro doplnění)
    </div>

    <div style="background:#FEF3C7;border:1px solid #F59E0B;padding:10px 14px;border-radius:8px;font-size:12px;margin-bottom:14px;color:#92400e">
      ⚠️ <strong>Pozor:</strong> Tato akce <strong>přepíše obsah</strong> jednotlivých dokumentů. Ručně přidané dokumenty (mimo výchozí sadu) zůstanou zachovány.
    </div>

    <div style="display:flex;gap:8px;justify-content:flex-end;flex-wrap:wrap">
      <button class="btn-secondary" onclick="closeModal()">Zrušit</button>
      <button class="btn-primary btn-green" onclick="haccpDokUpgradeRun()">♻️ Spustit aktualizaci</button>
    </div>
  `);
};

window.haccpDokUpgradeRun = async function() {
  closeModal();
  try {
    const r = await api('admin_haccp_dokumenty.php?action=upgrade_obsahy', { method: 'POST', body: '{}' });
    haccpState.dokumenty = await api('admin_haccp_dokumenty.php');
    // Pokud byl některý dokument otevřen, znovu načíst
    if (haccpState.dok_aktivni_id) {
      try { haccpState.dok_aktivni_obsah = await api(`admin_haccp_dokumenty.php?id=${haccpState.dok_aktivni_id}`); } catch (e) {}
    }
    haccpRender();
    const t = document.createElement('div');
    t.style.cssText = 'position:fixed;bottom:24px;right:24px;background:var(--success-bg);color:var(--success-text);padding:14px 22px;border-radius:8px;box-shadow:0 4px 12px rgba(0,0,0,.15);font-size:13px;font-weight:600;z-index:9999;max-width:340px;line-height:1.5';
    t.innerHTML = `♻️ Aktualizováno<br><small style="font-size:11px;opacity:0.85">Přepsáno: <strong>${r.updated || 0}</strong> dokumentů${r.created > 0 ? ` · vytvořeno: <strong>${r.created}</strong>` : ''}</small>`;
    document.body.appendChild(t);
    setTimeout(() => t.remove(), 4500);
  } catch (e) { alert('Chyba: ' + e.message); }
};

window.haccpDokImportDefault = async function() {
  if (!(await confirmDialog({ msg: 'Naimportovat výchozí sadu HACCP dokumentů (Plán, Sanitační řád, Vstupní instruktáž, Postupy CCP, Formuláře, Záznamy školení)?', danger: false }))) return;
  try {
    const r = await api('admin_haccp_dokumenty.php?action=import_default', { method: 'POST', body: '{}' });
    if (r && r.ok === false && r.existing) {
      if (!(await confirmDialog({ msg: t('confirm_add_more_docs', { n: r.existing }), danger: false }))) return;
      await api('admin_haccp_dokumenty.php?action=import_default&force=1', { method: 'POST', body: '{}' });
    }
    haccpState.dokumenty = await api('admin_haccp_dokumenty.php');
    haccpRender();
  } catch (e) { alert('Chyba: ' + e.message); }
};

// 🏢 Re-personalizace HACCP — nahradí v textech výchozí "APPEK" údaji z Nastavení firmy
// Volá se po změně firma_nazev/adresa/IČO atd. nebo z onboardingu.
window.haccpDokPersonalizovat = async function() {
  if (!(await confirmDialog({ msg: 'Personalizovat HACCP dokumenty aktuálními údaji firmy (z Nastavení → Firma a doklady)?\n\nNahradí se "APPEK pekařství..." aktuálním názvem firmy, adresou, IČO, jednatelem.', danger: false }))) return;
  try {
    const r = await api('admin_haccp_dokumenty.php?action=personalize', { method: 'POST', body: '{}' });
    haccpState.dokumenty = await api('admin_haccp_dokumenty.php');
    if (haccpState.dok_aktivni_id) {
      try { haccpState.dok_aktivni_obsah = await api(`admin_haccp_dokumenty.php?id=${haccpState.dok_aktivni_id}`); } catch (e) {}
    }
    haccpRender();
    const t = document.createElement('div');
    t.style.cssText = 'position:fixed;bottom:24px;right:24px;background:var(--success-bg);color:var(--success-text);padding:14px 22px;border-radius:8px;box-shadow:0 4px 12px rgba(0,0,0,.15);font-size:13px;font-weight:600;z-index:9999;max-width:340px;line-height:1.5';
    t.innerHTML = `🏢 Personalizováno<br><small style="font-size:11px;opacity:0.85">Aktualizováno: <strong>${r.aktualizovano || 0}</strong> dokumentů daty firmy <strong>${esc(r.firma || '')}</strong></small>`;
    document.body.appendChild(t);
    setTimeout(() => t.remove(), 4500);
  } catch (e) { alert('Chyba: ' + e.message); }
};

window.haccpDokOtevrit = async function(id) {
  // Pokud je něco rozeditované, dotaz
  if (haccpState.dok_aktivni_obsah && haccpState.dok_aktivni_id !== id) {
    if (haccpDokJeZmena() && !(await confirmDialog({ msg: 'Máš neuložené změny. Pokračovat bez uložení?', danger: false }))) return;
  }
  haccpState.dok_aktivni_id = id;
  try {
    haccpState.dok_aktivni_obsah = await api(`admin_haccp_dokumenty.php?id=${id}`);
    // Pokud je to dokument vnitřního auditu, načti i seznam záznamů
    if (haccpJeAuditDokument(haccpState.dok_aktivni_obsah)) {
      try {
        const a = await api('admin_haccp_dokumenty.php?action=audity_list');
        haccpState.audity = a.audity || [];
      } catch (e) { haccpState.audity = []; }
    }
    haccpRender();
  } catch (e) { alert('Chyba: ' + e.message); }
};

