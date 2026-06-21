// =============================================================
// KATEGORIE VÝROBKŮ
// =============================================================
async function renderKategorie() {
  // 🆕 v2.9.289 — defenzivní fallback
  let list;
  try { list = await api('admin_kategorie.php'); }
  catch (e) { list = []; }
  if (!Array.isArray(list)) list = (list && Array.isArray(list.kategorie)) ? list.kategorie : [];

  document.getElementById('content').innerHTML = `
    <div class="page-head">
      <div>
        <h1 class="page-title">🏷️ Kategorie výrobků</h1>
        <p class="page-sub">${list.length} ${list.length === 1 ? 'kategorie' : (list.length < 5 ? 'kategorie' : 'kategorií')}</p>
      </div>
      <div style="display:flex;gap:8px;flex-wrap:wrap">
        <button class="btn-secondary" onclick="navigate('vyrobky')">← Výrobky</button>
        ${adminOnly(`<button class="btn-primary btn-green btn-big-action" onclick="editKategorie()" style="font-size:18px !important;font-weight:800 !important;padding:18px 32px !important;min-height:64px !important;border-radius:12px !important;letter-spacing:0.3px !important">+ Nová kategorie</button>`)}
      </div>
    </div>

    <!-- Desktop: tabulka -->
    <div class="card-block desktop-only-block">
      ${list.length === 0 ? `
        <div class="empty-state" style="padding:40px;text-align:center;color:var(--text-3)">
          Zatím žádné kategorie. Přidejte první klepnutím na tlačítko nahoře.
        </div>
      ` : `
        <table class="table">
          <thead>
            <tr>
              <th style="width:60px">Pořadí</th>
              <th style="width:60px">Ikona</th>
              <th>Název</th>
              <th class="num">Výrobků</th>
              <th>Stav</th>
              <th class="actions"></th>
            </tr>
          </thead>
          <tbody>
            ${list.map(k => `
              <tr ${k.parent_id ? 'style="background:var(--surface-2)"' : ''}>
                <td class="num">${k.poradi}</td>
                <td>
                  ${k.obrazek_url
                    ? `<img src="${esc(k.obrazek_url)}" style="width:36px;height:36px;border-radius:6px;object-fit:cover;display:block">`
                    : `<span style="font-size:24px">${esc(k.ikona || '🥖')}</span>`}
                </td>
                <td style="${k.parent_id ? 'padding-left:30px' : ''}">
                  ${k.parent_id ? '<span style="color:var(--text-3)">↳ </span>' : ''}<strong>${esc(k.nazev)}</strong>
                  ${k.pocet_subkategorii > 0 ? `<span class="muted" style="font-size:11px;margin-left:6px">(${k.pocet_subkategorii} subkat.)</span>` : ''}
                </td>
                <td class="num">${k.pocet_vyrobku}</td>
                <td>
                  ${k.aktivni == 1
                    ? '<span class="status dorucena">Aktivní</span>'
                    : '<span class="status zrusena">Skrytá</span>'}
                </td>
                <td class="actions">
                  ${adminOnly(`<button class="btn-secondary" onclick="editKategorie(${k.id})">Upravit</button>`)}
                </td>
              </tr>
            `).join('')}
          </tbody>
        </table>
      `}
    </div>

    <!-- Mobile: karty -->
    <div class="kategorie-grid mobile-only-block">
      ${list.length === 0 ? `
        <div class="card-block" style="grid-column:1/-1"><div class="empty-state">
          Zatím žádné kategorie. Přidejte první klepnutím na tlačítko nahoře.
        </div></div>
      ` : list.map(k => `
        <div class="kategorie-card ${k.aktivni == 1 ? '' : 'inactive'}" onclick="${isSuperAdmin() ? `editKategorie(${k.id})` : ''}">
          <div class="kategorie-card-img">
            ${k.obrazek_url
              ? `<img src="${esc(k.obrazek_url)}" alt="${esc(k.nazev)}">`
              : `<div class="kategorie-card-emoji">${esc(k.ikona || '🥖')}</div>`}
            <span class="kategorie-card-poradi">#${k.poradi}</span>
            ${k.aktivni != 1 ? '<span class="kategorie-card-skryt">Skrytá</span>' : ''}
          </div>
          <div class="kategorie-card-body">
            <div class="kategorie-card-nazev">${k.parent_id ? '↳ ' : ''}${esc(k.nazev)}</div>
            <div class="kategorie-card-pocet">${k.pocet_vyrobku} ${k.pocet_vyrobku === 1 ? 'výrobek' : (k.pocet_vyrobku < 5 ? 'výrobky' : 'výrobků')}${k.pocet_subkategorii > 0 ? ` · ${k.pocet_subkategorii} subkat.` : ''}</div>
          </div>
        </div>
      `).join('')}
    </div>
  `;
}

window.editKategorie = async function(id = null) {
  let k = { nazev: '', ikona: '🥖', obrazek_url: null, poradi: 999, aktivni: 1, pocet_vyrobku: 0, parent_id: null, pocet_subkategorii: 0 };
  let list = [];
  try { list = await api('admin_kategorie.php'); } catch (e) {}
  if (!Array.isArray(list)) list = [];
  if (id) { k = list.find(x => x.id == id) || k; }
  // 🆕 v3.0.334 — hlavní kategorie pro výběr nadřazené (jen 1 úroveň, ne sebe)
  const mainCats = list.filter(c => !c.parent_id && c.id != id);
  const maSubkategorie = (k.pocet_subkategorii || 0) > 0;
  // 🆕 v3.0.339 — proklikávací výpis: subkategorie + výrobky v této kategorii
  const subKat = id ? list.filter(c => c.parent_id == id) : [];
  let produkty = [];
  if (id && (k.pocet_vyrobku || 0) > 0) {
    try { produkty = (await api('admin_kategorie.php?action=produkty&id=' + id)).produkty || []; } catch (e) {}
  }

  // Doporučené ikony pro pekařskou kategorii
  // 🆕 v3.0.336 — roztříděná sada ikon pro kategorie (pekařství/cukrárna/lahůdky/restaurace)
  const ikonySkupiny = [
    { label: '🥖 Pečivo & chléb',     emojis: ['🥖', '🍞', '🥐', '🥨', '🥯', '🫓', '🧇', '🌾'] },
    { label: '🍰 Sladké & cukrárna',  emojis: ['🧁', '🍰', '🎂', '🥧', '🍪', '🍩', '🍮', '🍫', '🍬', '🍭', '🍯', '🍡', '🍦', '🍨', '🍓'] },
    { label: '🥛 Mléčné výrobky',     emojis: ['🥛', '🧈', '🧀', '🍶', '🥚'] },
    { label: '🥩 Maso & uzeniny',     emojis: ['🥩', '🍖', '🍗', '🥓', '🌭', '🍔', '🍤', '🐟', '🐔'] },
    { label: '🥪 Lahůdky & teplá',    emojis: ['🥪', '🥗', '🌮', '🌯', '🥙', '🧆', '🫔', '🍱', '🍲', '🥘', '🍳', '🍕', '🍝', '🧂'] },
    { label: '🍎 Ovoce & zelenina',   emojis: ['🍎', '🍐', '🍓', '🍌', '🍇', '🍊', '🍋', '🍑', '🥝', '🥕', '🍅', '🥔', '🌽', '🥦', '🥬'] },
    { label: '☕ Nápoje',             emojis: ['☕', '🍵', '🧃', '🥤', '🧋', '🍷', '🍺', '🍹', '🫗', '💧', '🍾'] },
    { label: '🎁 Sezónní & ostatní',  emojis: ['🎃', '🎄', '🐰', '💝', '🌰', '🥜', '🧊', '🎁', '⭐', '📦'] },
  ];
  const maObrazek = !!k.obrazek_url;

  openModal(id ? `Kategorie: ${esc(k.nazev)}` : 'Nová kategorie', `
    <div class="form-grid">
      <div class="full">
        <label class="form-label">Název *</label>
        <input class="form-input" id="kat-nazev" value="${esc(k.nazev)}" required>
      </div>

      <div class="full">
        <label class="form-label">Nadřazená kategorie</label>
        <select class="form-input" id="kat-parent" ${maSubkategorie ? 'disabled' : ''}>
          <option value="">— Žádná (hlavní kategorie) —</option>
          ${mainCats.map(c => `<option value="${c.id}" ${k.parent_id == c.id ? 'selected' : ''}>${esc(c.ikona || '🥖')} ${esc(c.nazev)}</option>`).join('')}
        </select>
        <p class="muted" style="font-size:12px;margin-top:4px">${maSubkategorie
          ? '🔒 Tato kategorie má vlastní subkategorie, takže nemůže být subkategorií (max 1 úroveň).'
          : 'Vyber hlavní kategorii → tahle se stane subkategorií (např. Mléčné výrobky → Máslo).'}</p>
      </div>

      <div class="full">
        <label class="form-label">Vzhled v katalogu</label>
        <div style="display:flex;gap:8px;margin-bottom:12px;flex-wrap:wrap">
          <button class="btn-secondary" id="kat-typ-emoji-btn"
                  style="${!maObrazek ? 'background:var(--primary);color:white;border-color:var(--primary)' : ''}"
                  onclick="prepnoutKatTyp('emoji')">😊 Emoji ikonka</button>
          <button class="btn-secondary" id="kat-typ-obrazek-btn"
                  style="${maObrazek ? 'background:var(--primary);color:white;border-color:var(--primary)' : ''}"
                  onclick="prepnoutKatTyp('obrazek')">🖼️ Vlastní obrázek</button>
        </div>

        <!-- EMOJI -->
        <div id="kat-emoji-block" style="${maObrazek ? 'display:none' : ''}">
          <input class="form-input" id="kat-ikona" value="${esc(k.ikona || '🥖')}" maxlength="8" style="font-size:24px;text-align:center;width:120px">
          <div style="margin-top:10px;max-height:230px;overflow-y:auto;padding-right:4px;border:1px solid var(--border);border-radius:8px;padding:8px 10px">
            ${ikonySkupiny.map(g => `
              <div style="font-size:11px;color:var(--text-3);font-weight:700;margin:8px 0 4px">${g.label}</div>
              <div style="display:flex;flex-wrap:wrap;gap:6px">
                ${g.emojis.map(ik => `<button type="button" class="btn-secondary kat-ikona-btn" style="padding:5px 9px;font-size:20px;min-width:40px;line-height:1.2" onclick="document.getElementById('kat-ikona').value='${ik}';document.querySelectorAll('.kat-ikona-btn').forEach(b=>b.style.outline='');this.style.outline='2px solid var(--primary)'">${ik}</button>`).join('')}
              </div>
            `).join('')}
          </div>
          <p class="muted" style="font-size:12px;margin-top:8px">Klikni na ikonu, nebo vlož do políčka jakýkoliv emoji.</p>
        </div>

        <!-- OBRÁZEK -->
        <div id="kat-obrazek-block" style="${!maObrazek ? 'display:none' : ''}">
          <div style="display:flex;gap:16px;align-items:flex-start">
            <div id="kat-preview" style="width:80px;height:80px;border-radius:8px;background:var(--surface-2);border:1.5px dashed var(--border-2);display:flex;align-items:center;justify-content:center;overflow:hidden;flex-shrink:0">
              ${k.obrazek_url
                ? `<img src="${esc(k.obrazek_url)}" style="width:100%;height:100%;object-fit:cover">`
                : '<span style="font-size:30px;color:var(--text-3)">🖼️</span>'}
            </div>
            <div style="flex:1">
              <input type="hidden" id="kat-obrazek-url" value="${esc(k.obrazek_url || '')}">
              <input type="file" id="kat-obrazek-file" accept="image/jpeg,image/png,image/webp" style="display:none" onchange="nahratKatObrazek()">
              <button class="btn-secondary" onclick="document.getElementById('kat-obrazek-file').click()">📤 Vybrat obrázek</button>
              ${k.obrazek_url ? `<button class="btn-danger" style="margin-left:8px" onclick="odstranitKatObrazek()">Odstranit</button>` : ''}
              <p class="muted" style="font-size:12px;margin-top:8px">JPG, PNG nebo WEBP. Max 2 MB. Doporučeno: čtvercový obrázek.</p>
            </div>
          </div>
        </div>
      </div>

      <div class="full">
        <label class="form-label">Pořadí</label>
        <input class="form-input" id="kat-poradi" type="number" value="${k.poradi}" style="max-width:200px">
        <p class="muted" style="font-size:12px;margin-top:4px">Menší číslo = dříve v seznamu.</p>
      </div>

      <div class="full">
        <div class="checkbox-row">
          <input type="checkbox" id="kat-aktivni" ${k.aktivni == 1 ? 'checked' : ''}>
          <label for="kat-aktivni">Aktivní (zobrazit v katalogu)</label>
        </div>
      </div>

      ${id && (subKat.length || produkty.length) ? `
        <div class="full">
          ${subKat.length ? `
            <div style="font-size:12px;color:var(--text-3);font-weight:700;margin:8px 0 4px">📂 Subkategorie (${subKat.length})</div>
            <div style="display:flex;flex-direction:column;gap:4px;margin-bottom:10px">
              ${subKat.map(s => `<button type="button" class="btn-secondary" style="display:flex;align-items:center;gap:8px;text-align:left;font-size:13px;padding:7px 10px;cursor:pointer" onclick="editKategorie(${s.id})">↳ ${esc(s.ikona || '📦')} <span>${esc(s.nazev)}</span><span style="color:var(--text-3);margin-left:auto;white-space:nowrap">${s.pocet_vyrobku || 0} ks · upravit →</span></button>`).join('')}
            </div>` : ''}
          ${produkty.length ? `
            <div style="font-size:12px;color:var(--text-3);font-weight:700;margin:8px 0 4px">🥖 Výrobky v kategorii (${produkty.length})</div>
            <div style="display:flex;flex-direction:column;gap:4px;max-height:240px;overflow:auto;border:1px solid var(--border);border-radius:8px;padding:6px">
              ${produkty.map(p => `<button type="button" class="btn-secondary" style="display:flex;justify-content:space-between;align-items:center;gap:8px;text-align:left;font-size:13px;padding:7px 10px;cursor:pointer" onclick="closeModal();setTimeout(function(){editVyrobek(${p.id});},60)">
                <span>${p.aktivni == 0 ? '🚫 ' : ''}${esc(p.nazev)}${p.cislo ? ` <span style="color:var(--text-3);font-size:11px">· ${esc(p.cislo)}</span>` : ''}</span>
                <span style="color:var(--text-3);white-space:nowrap">${fmt(p.cena_bez_dph)} →</span>
              </button>`).join('')}
            </div>` : ''}
          ${k.pocet_vyrobku > 0 ? `<p class="muted" style="font-size:11px;margin-top:8px">Pro smazání kategorie nejdřív přesuň výrobky jinam.</p>` : ''}
        </div>
      ` : ''}
    </div>

    <div class="form-actions">
      ${id && k.pocet_vyrobku === 0 ? adminOnly(`<div class="form-actions-icons-row"><button class="btn-danger-corner" onclick="smazatKategorii(${id})" title="Smazat kategorii" aria-label="Smazat kategorii">🗑️</button></div><div style="flex:1"></div>`) : ''}
      <button class="btn-secondary" onclick="closeModal()">Zrušit</button>
      <button class="btn-primary" onclick="ulozitKategorii(${id || 'null'})">Uložit</button>
    </div>
  `);
};

window.prepnoutKatTyp = function(typ) {
  const emojiBlock   = document.getElementById('kat-emoji-block');
  const obrazekBlock = document.getElementById('kat-obrazek-block');
  const emojiBtn     = document.getElementById('kat-typ-emoji-btn');
  const obrazekBtn   = document.getElementById('kat-typ-obrazek-btn');

  if (typ === 'emoji') {
    emojiBlock.style.display   = '';
    obrazekBlock.style.display = 'none';
    emojiBtn.style.cssText     = 'background:var(--primary);color:white;border-color:var(--primary)';
    obrazekBtn.style.cssText   = '';
    // Vyčistit URL aby se uložilo jen emoji
    document.getElementById('kat-obrazek-url').value = '';
  } else {
    emojiBlock.style.display   = 'none';
    obrazekBlock.style.display = '';
    emojiBtn.style.cssText     = '';
    obrazekBtn.style.cssText   = 'background:var(--primary);color:white;border-color:var(--primary)';
  }
};

window.nahratKatObrazek = async function() {
  const fileInput = document.getElementById('kat-obrazek-file');
  if (!fileInput.files || fileInput.files.length === 0) return;
  const file = fileInput.files[0];

  if (file.size > 2 * 1024 * 1024) {
    alert('Soubor je větší než 2 MB');
    return;
  }

  const fd = new FormData();
  fd.append('obrazek', file);

  try {
    const res = await fetch('../api/admin_kategorie.php?action=upload', {
      method: 'POST', credentials: 'include', headers: csrfHeaders(), body: fd,
    });
    const data = await res.json();
    if (!res.ok) throw new Error(data.error || 'Chyba při nahrávání');

    document.getElementById('kat-obrazek-url').value = data.url;
    document.getElementById('kat-preview').innerHTML =
      `<img src="${data.url}" style="width:100%;height:100%;object-fit:cover">`;
  } catch (e) {
    alert('Chyba: ' + e.message);
  }
};

window.odstranitKatObrazek = function() {
  document.getElementById('kat-obrazek-url').value = '';
  document.getElementById('kat-preview').innerHTML =
    '<span style="font-size:30px;color:var(--text-3)">🖼️</span>';
};

window.ulozitKategorii = async function(id) {
  const obrazek_url = document.getElementById('kat-obrazek-url').value.trim();
  const data = {
    id,
    nazev:   document.getElementById('kat-nazev').value.trim(),
    ikona:   document.getElementById('kat-ikona').value.trim() || '🥖',
    obrazek_url: obrazek_url || null,
    poradi:  parseInt(document.getElementById('kat-poradi').value) || 999,
    aktivni: document.getElementById('kat-aktivni').checked ? 1 : 0,
    parent_id: parseInt(document.getElementById('kat-parent')?.value) || null,
  };
  if (!data.nazev) return alert('Vyplňte název kategorie');

  if (window._savingKat) return;                // 🆕 v3.0.347 — zábrana dvojího odeslání
  window._savingKat = true;
  try {
    await api('admin_kategorie.php', {
      method: id ? 'PUT' : 'POST',
      body: JSON.stringify(data),
    });
    closeModal();
    navigate('kategorie');
  } catch (e) {
    alert('Chyba: ' + e.message);
  } finally { window._savingKat = false; }
};

window.smazatKategorii = async function(id) {
  if (!await confirmDelete2x('tuto kategorii')) return;
  try {
    await api(`admin_kategorie.php?id=${id}`, { method: 'DELETE' });
    closeModal();
    navigate('kategorie');
  } catch (e) {
    alert('Chyba: ' + e.message);
  }
};

// =============================================================
// 🛠️ NÁSTROJE — hub stránka (v2.9.225)
// Sloučení 'PDF nabídka' (katalog) a 'Štítky a cenovky' (stitky)
// do jednoho menu item kvůli lepšímu mobile UX (méně sidebar items).
// =============================================================
// 🆕 v3.0.338 — Import produktů z CSV (Shoptet / WooCommerce / Excel)
window.appekImportProdukty = function() {
  openModal('📥 Import produktů z CSV', `
    <div id="imp-body">
      <p style="font-size:13px;color:var(--text-3);margin:0 0 14px">Nahraj <strong>CSV</strong> nebo <strong>XML feed</strong> z <strong>Shoptetu</strong>, <strong>WooCommerce</strong> nebo Excelu. V dalším kroku namapuješ sloupce na pole produktu.</p>
      <input type="file" id="imp-file" accept=".csv,.xml,text/csv,text/xml,application/xml,text/plain" style="display:none" onchange="appekImportPreview()">
      <button class="btn-primary btn-green" onclick="document.getElementById('imp-file').click()" style="font-weight:700;padding:12px 22px;border:none;border-radius:10px;cursor:pointer">📤 Vybrat soubor (CSV / XML)</button>
      <p class="muted" style="font-size:12px;margin-top:12px;line-height:1.5">Oddělovač (<code>;</code> <code>,</code> Tab <code>|</code>) i kódování (UTF-8 / Windows-1250) se detekují automaticky. Max 8 MB.</p>
    </div>
  `);
};

window.appekImportPreview = async function() {
  const f = document.getElementById('imp-file');
  if (!f || !f.files || !f.files[0]) return;
  const body = document.getElementById('imp-body');
  if (f.files[0].size > 8 * 1024 * 1024) { body.innerHTML = `<div class="alert err" style="background:#fde7e9;color:#a8232f;padding:12px;border-radius:8px">❌ Soubor je větší než 8 MB</div><button class="btn-secondary" style="margin-top:10px" onclick="appekImportProdukty()">← Zpět</button>`; return; }
  body.innerHTML = '⏳ Načítám a analyzuji soubor…';
  const fd = new FormData(); fd.append('file', f.files[0]);
  let d;
  try {
    const res = await fetch('../api/admin_import.php?action=preview', { method: 'POST', credentials: 'include', headers: csrfHeaders(), body: fd });
    d = await res.json();
    if (!res.ok) throw new Error(d.error || 'Chyba načtení');
  } catch (e) { body.innerHTML = `<div class="alert err" style="background:#fde7e9;color:#a8232f;padding:12px;border-radius:8px">❌ ${esc(e.message)}</div><button class="btn-secondary" style="margin-top:10px" onclick="appekImportProdukty()">← Zpět</button>`; return; }
  window._impData = d;
  const colOpts = (sel) => `<option value="">—</option>` + d.columns.map((c, i) => `<option value="${i}" ${sel == i ? 'selected' : ''}>${esc(c || ('Sloupec ' + (i + 1)))}</option>`).join('');
  body.innerHTML = `
    <p style="font-size:13px;margin:0 0 10px">Soubor: <strong>${d.total}</strong> řádků${d.capped ? ' (zpracuje se prvních 3000)' : ''}. Namapuj sloupce:</p>
    <div style="display:grid;grid-template-columns:1fr 1fr;gap:8px;max-height:260px;overflow:auto;border:1px solid var(--border);border-radius:8px;padding:10px">
      ${Object.entries(d.fields).map(([field, label]) => `
        <label style="display:block"><span style="font-size:12px;color:var(--text-2);font-weight:600">${esc(label)}</span>
          <select class="form-input imp-map" data-field="${field}" onchange="appekImportMapChange(this)" style="font-size:13px;padding:6px">${colOpts(d.suggested[field])}</select>
        </label>`).join('')}
    </div>
    <div style="display:flex;gap:14px;flex-wrap:wrap;margin-top:12px;font-size:13px;align-items:center">
      <label>Párovat podle: <select id="imp-matchkey" class="form-input" style="width:auto;display:inline-block;padding:5px"><option value="cislo">Kód / číslo</option><option value="ean">EAN</option></select></label>
      <label style="display:flex;align-items:center;gap:6px;cursor:pointer"><input type="checkbox" id="imp-update" checked> Aktualizovat existující</label>
      <label style="display:flex;align-items:center;gap:6px;cursor:pointer"><input type="checkbox" id="imp-createcat" checked> Zakládat chybějící kategorie</label>
    </div>
    <div style="margin-top:10px;max-height:130px;overflow:auto;border:1px solid var(--border);border-radius:6px">
      <table style="width:100%;border-collapse:collapse;font-size:11px"><thead><tr>${d.columns.map(c => `<th style="text-align:left;padding:3px 6px;border-bottom:1px solid var(--border);position:sticky;top:0;background:var(--surface-2);white-space:nowrap">${esc(c || '')}</th>`).join('')}</tr></thead>
      <tbody>${d.rows.slice(0, 5).map(r => `<tr>${d.columns.map((_, i) => `<td style="padding:3px 6px;border-bottom:1px solid var(--border);white-space:nowrap">${esc(String(r[i] ?? '').slice(0, 40))}</td>`).join('')}</tr>`).join('')}</tbody></table>
    </div>
    <div style="display:flex;gap:8px;justify-content:flex-end;margin-top:14px">
      <button class="btn-secondary" onclick="appekImportProdukty()">← Zpět</button>
      <button class="btn-primary btn-green" onclick="appekImportCommit()" style="font-weight:700">📥 Importovat ${d.capped ? 3000 : d.total}</button>
    </div>`;
};

// Jeden sloupec smí být namapovaný max na 1 pole — při výběru ho zruš u ostatních (parita s auto-mapováním).
window.appekImportMapChange = function(sel) {
  if (!sel || sel.value === '') return;
  document.querySelectorAll('.imp-map').forEach(s => { if (s !== sel && s.value === sel.value) s.value = ''; });
};

window.appekImportCommit = async function() {
  const d = window._impData; if (!d) return;
  const mapping = {};
  document.querySelectorAll('.imp-map').forEach(s => { if (s.value !== '') mapping[s.dataset.field] = parseInt(s.value); });
  if (mapping.nazev === undefined) { alert('Namapuj alespoň pole „Název *".'); return; }
  const payload = {
    rows: d.rows, mapping,
    match_key: document.getElementById('imp-matchkey').value,
    update_existing: document.getElementById('imp-update').checked,
    create_categories: document.getElementById('imp-createcat').checked,
  };
  const body = document.getElementById('imp-body');
  body.innerHTML = '⏳ Importuji produkty…';
  try {
    const r = await api('admin_import.php?action=commit', { method: 'POST', body: JSON.stringify(payload) });
    body.innerHTML = `
      <div style="text-align:center;padding:18px">
        <div style="font-size:42px">✅</div>
        <h3 style="margin:8px 0">Import dokončen</h3>
        <p style="font-size:14px;line-height:1.7">Nových: <strong>${r.inserted}</strong> · Aktualizováno: <strong>${r.updated}</strong> · Přeskočeno: <strong>${r.skipped}</strong>${r.categories_created ? ` · Nových kategorií: <strong>${r.categories_created}</strong>` : ''}</p>
        ${r.priced_zero ? `<p style="font-size:13px;color:#a8232f;background:#fde7e9;border-radius:8px;padding:8px 12px;margin:6px auto;max-width:420px">⚠️ ${r.priced_zero} produktů importováno za <strong>0 Kč</strong> — zkontroluj mapování ceny.</p>` : ''}
        <button class="btn-primary btn-green" onclick="closeModal();navigate('vyrobky')" style="margin-top:10px;font-weight:700">Zobrazit produkty →</button>
      </div>`;
    try { toastSuccess(`Import: +${r.inserted} / ~${r.updated}`); } catch (e) {}
  } catch (e) { body.innerHTML = `<div class="alert err" style="background:#fde7e9;color:#a8232f;padding:12px;border-radius:8px">❌ ${esc(e.message)}</div><button class="btn-secondary" style="margin-top:10px" onclick="appekImportPreview()">← Zpět</button>`; }
};

async function renderNastroje() {
  const c = document.getElementById('content');
  c.innerHTML = `
    <div class="page-head">
      <div>
        <h1 class="page-title">🛠️ Nástroje</h1>
        <p class="page-sub">Generování PDF katalogů, tisk štítků a cenovek</p>
      </div>
    </div>

    <div class="nastroje-grid">
      <button class="nastroje-card" onclick="appekScannerSettings()" aria-label="Skener čárových kódů">
        <div class="nastroje-card-icon">📷</div>
        <div class="nastroje-card-title">Skener kódů</div>
        <div class="nastroje-card-desc">Čtečka čárových kódů (USB/BT), kamera, akce po skenu a kódování váhových štítků.</div>
        <div class="nastroje-card-cta">Nastavit →</div>
      </button>

      <button class="nastroje-card" onclick="appekImportProdukty()" aria-label="Import produktů z CSV">
        <div class="nastroje-card-icon">📥</div>
        <div class="nastroje-card-title">Import produktů</div>
        <div class="nastroje-card-desc">Naimportuj produkty z CSV (Shoptet, WooCommerce, Excel). Mapování sloupců + aktualizace existujících.</div>
        <div class="nastroje-card-cta">Importovat →</div>
      </button>

      ${adminOnly(`<button class="nastroje-card" onclick="navigate('integrace')" aria-label="Integrace a platby">
        <div class="nastroje-card-icon">🔌</div>
        <div class="nastroje-card-title">Integrace</div>
        <div class="nastroje-card-desc">Platby (Stripe, GoPay, PayPal), přepravci (Zásilkovna, DPD, PPL, ČP) a účetní (POHODA, FlexiBee).</div>
        <div class="nastroje-card-cta">Otevřít →</div>
      </button>`)}

      <button class="nastroje-card" onclick="navigate('katalog')" aria-label="Otevřít PDF nabídku">
        <div class="nastroje-card-icon">📑</div>
        <div class="nastroje-card-title">PDF nabídka</div>
        <div class="nastroje-card-desc">Sestav profesionální PDF katalog s vybranými výrobky pro zaslání odběratelům.</div>
        <div class="nastroje-card-cta">Otevřít →</div>
      </button>

      <button class="nastroje-card" onclick="navigate('stitky')" aria-label="Otevřít štítky a cenovky">
        <div class="nastroje-card-icon">🏷️</div>
        <div class="nastroje-card-title">Štítky a cenovky</div>
        <div class="nastroje-card-desc">Tisk regálových cenovek, štítků na zboží a etiket — různé velikosti a šablony.</div>
        <div class="nastroje-card-cta">Otevřít →</div>
      </button>

      <!-- 🆕 v3.0.29 — Tiskárny přesunuté z Nastavení -->
      <button class="nastroje-card" onclick="navigate('tiskarny')" aria-label="Otevřít správu tiskáren">
        <div class="nastroje-card-icon">🖨️</div>
        <div class="nastroje-card-title">Tiskárny (ESC/POS)</div>
        <div class="nastroje-card-desc">Síťové termo tiskárny pro kasu, kuchyň, bar, sklad a výdej. Auto-split bonů podle kategorie.</div>
        <div class="nastroje-card-cta">Otevřít →</div>
      </button>
    </div>

    <style>
      .nastroje-grid {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
        gap: 14px;
        margin-top: 8px;
      }
      .nastroje-card {
        display: flex;
        flex-direction: column;
        gap: 8px;
        background: var(--surface);
        border: 1px solid var(--border);
        border-radius: var(--radius-lg);
        padding: 24px 22px;
        text-align: left;
        cursor: pointer;
        font-family: inherit;
        transition: all 0.15s;
        min-height: 180px;
      }
      .nastroje-card:hover {
        background: var(--surface-2);
        border-color: var(--primary-border);
        transform: translateY(-2px);
        box-shadow: 0 4px 12px rgba(186,117,23,0.08);
      }
      .nastroje-card-icon { font-size: 36px; line-height: 1; margin-bottom: 4px; }
      .nastroje-card-title { font-size: 18px; font-weight: 700; color: var(--text); }
      .nastroje-card-desc { font-size: 13px; color: var(--text-2); line-height: 1.5; flex: 1; }
      .nastroje-card-cta { font-size: 13px; font-weight: 600; color: var(--primary); margin-top: 8px; }
      @media (max-width: 600px) {
        .nastroje-grid { grid-template-columns: 1fr; }
        .nastroje-card { min-height: 140px; padding: 18px; }
        .nastroje-card-icon { font-size: 28px; }
        .nastroje-card-title { font-size: 16px; }
      }
    </style>
  `;
}

// 🆕 v3.0.29 — Tiskárny standalone page (přesunuté z Nastavení → Nástroje)
async function renderTiskarny() {
  const c = document.getElementById('content');
  c.innerHTML = `
    <div class="page-head">
      <div>
        <h1 class="page-title">🖨️ Tiskárny (ESC/POS)</h1>
        <p class="page-sub">Síťové termo tiskárny pro kasa, kuchyň, bar, sklad a výdej · Auto-split bonů</p>
      </div>
      <div style="display:flex;gap:8px;flex-wrap:wrap;align-items:center">
        <button class="btn-back" onclick="navigate('nastroje')" title="Zpět na Nástroje"><span class="btn-back-arrow">←</span> <span class="btn-back-lbl">Nástroje</span></button>
      </div>
    </div>
    <div class="card-block" style="padding:16px;margin-bottom:14px">
      <p style="font-size:13px;color:var(--text-3);margin:0;line-height:1.55">
        Síťové termo tiskárny pro kasu, kuchyň, bar, sklad a výdej. Bonu se automaticky rozesílají podle <strong>kategorie výrobku</strong> na příslušnou tiskárnu.
        <br>Standard: <strong>Epson TM-T20III</strong> nebo podobná na IP, port <code>9100</code>. Bez fyzické tiskárny zapni <strong>dummy mode</strong> (tisk do souboru pro test).
      </p>
    </div>
    <div id="ns-tiskarny-panel">⏳ Načítám…</div>
  `;
  if (typeof loadTiskarnyPanel === 'function') loadTiskarnyPanel();
}

// =============================================================
// KATALOG / PDF NABÍDKA
// Stránka: vybrat kategorie + konkrétní výrobky, sestavit PDF nabídku.
// =============================================================
async function renderKatalog() {
  const c = document.getElementById('content');
  c.innerHTML = '<div class="empty-state">Načítám výrobky…</div>';

  let vyr = null;
  let skupiny = [];
  try {
    [vyr, skupiny] = await Promise.all([
      api('admin_vyrobky.php'),
      api('admin_cenove_skupiny.php').catch(() => []),
    ]);
  } catch (e) {
    c.innerHTML = `<div style="color:var(--danger-text);padding:20px;background:var(--danger-bg);border-radius:8px;">
      Chyba načítání výrobků: ${esc(e.message)}
    </div>`;
    return;
  }

  // State pro tuto stránku — udržujeme mezi re-rendery
  // 🆕 v2.5 — perzistentní sortMode + collapsed přes localStorage
  let savedSortMode = 'poradi';
  let savedCollapsed = false;
  try {
    savedSortMode = localStorage.getItem('kat_sort_mode') || 'poradi';
    savedCollapsed = localStorage.getItem('kat_collapsed') === '1';
  } catch (e) { /* fallback default */ }
  state._katalog = state._katalog || {
    vybrane: new Set(),
    poznamky: {},          // { vyrobek_id: text }
    kategorie_filtr: null, // null = všechny, číslo = jen ta kategorie
    skupina_id: '',
    nazev: '',
    q: '',
    groupBy: true,         // 🔀 sekcování výrobků podle kategorie (default zapnuto)
    sortMode: savedSortMode,  // 🆕 v2.5 — poradi / abeceda / pocet / emoji
    collapsed: savedCollapsed, // 🆕 v2.5 — sbalit dlouhý seznam kategorií
  };
  const k = state._katalog;

  // Všechny výrobky (i skryté — můžou být zajímavé pro nabídku)
  const vsechny = (vyr && vyr.vyrobky) ? vyr.vyrobky.slice() : [];
  const kategorie = (vyr && vyr.kategorie || []).slice().sort((a, b) => (a.poradi || 0) - (b.poradi || 0));

  // Diagnostika když chybí výrobky
  if (vsechny.length === 0) {
    c.innerHTML = `
      <div class="page-head">
        <div><h1 class="page-title">📑 PDF nabídka</h1></div>
        <div style="display:flex;gap:8px;flex-wrap:wrap">
          <button class="btn-secondary" onclick="navigate('nastroje')">← Nástroje</button>
        </div>
      </div>
      <div class="card-block" style="padding:30px;text-align:center">
        <p style="font-size:15px;margin-bottom:8px">Zatím nemáš žádné výrobky.</p>
        <p style="color:var(--text-3);font-size:13px;margin-bottom:16px">Přidej je v sekci Výrobky a pak se sem vrať.</p>
        <button class="btn-primary" onclick="navigate('vyrobky')">→ Přejít do Výrobků</button>
      </div>`;
    return;
  }

  c.innerHTML = `
    <div class="page-head">
      <div>
        <h1 class="page-title">📑 PDF nabídka</h1>
        <p class="page-sub">Sestav cenovou nabídku pro odběratele — vyber ceník, výrobky a vygeneruj PDF.</p>
      </div>
      <div style="display:flex;gap:8px;flex-wrap:wrap">
        <button class="btn-secondary" onclick="navigate('nastroje')">← Nástroje</button>
      </div>
    </div>

    <!-- 📋 POSTUP — souhrnný panel s kroky -->
    <div class="card-block step-summary" style="margin-bottom:14px;padding:14px 16px;background:linear-gradient(90deg, rgba(186, 117, 23, 0.08), rgba(186, 117, 23, 0.02));border:1px solid #E8D5B0;border-radius:10px">
      <div style="display:flex;align-items:center;gap:10px;margin-bottom:8px">
        <span style="font-size:18px">📋</span>
        <strong style="font-size:14px;color:var(--text-1)">Postup vytvoření nabídky</strong>
      </div>
      <div style="display:flex;flex-wrap:wrap;gap:6px 14px;font-size:12px;color:var(--text-2)">
        <span><strong style="color:var(--primary-dark)">1.</strong> Vyber ceník</span>
        <span style="color:var(--text-3)">→</span>
        <span><strong style="color:var(--primary-dark)">2.</strong> Pojmenuj nabídku</span>
        <span style="color:var(--text-3)">→</span>
        <span><strong style="color:var(--primary-dark)">3.</strong> Vyfiltruj / hledej</span>
        <span style="color:var(--text-3)">→</span>
        <span><strong style="color:var(--primary-dark)">4.</strong> Označ výrobky</span>
        <span style="color:var(--text-3)">→</span>
        <span><strong style="color:var(--primary-dark)">5.</strong> Vygeneruj PDF nebo pošli e-mailem</span>
      </div>
    </div>

    <!-- NASTAVENÍ NABÍDKY -->
    <div class="card-block" style="margin-bottom:14px;padding:16px;background:#F7F8FA;border:1px solid #E8D5B0;border-radius:10px">
      <div style="display:grid;grid-template-columns:1fr 2fr;gap:12px;align-items:start">
        <div>
          <label class="form-label" style="display:flex;align-items:flex-end;min-height:38px;margin:0 0 6px">Cenová skupina (ceník)</label>
          <select class="filter-select" id="kat-skupina" onchange="state._katalog.skupina_id=this.value">
            <option value="">Základní ceník</option>
            ${(Array.isArray(skupiny) ? skupiny : []).map(s => `
              <option value="${s.id}" ${k.skupina_id == s.id ? 'selected' : ''}>${esc(s.nazev)}</option>
            `).join('')}
          </select>
          ${stStepHint(1, 'Pro koho je nabídka — určuje, jaké ceny se zobrazí (sleva, pevné ceny).')}
        </div>
        <div>
          <label class="form-label" style="display:flex;align-items:flex-end;min-height:38px;margin:0 0 6px">Název nabídky (volitelný)</label>
          <input class="filter-input" type="text" id="kat-nazev" placeholder="např. Letní nabídka 2026" value="${esc(k.nazev)}" oninput="state._katalog.nazev=this.value">
          ${stStepHint(2, 'Hlavní nadpis na PDF. Když necháš prázdné, použije se jen logo + datum.')}
        </div>
      </div>
    </div>

    <!-- AKČNÍ LIŠTA -->
    <div class="card-block" style="margin-bottom:8px;padding:12px;display:flex;flex-wrap:wrap;gap:8px;align-items:center">
      <div style="position:relative;flex:1;min-width:200px">
        <input class="filter-input" type="search" id="kat-q" placeholder="🔍 Hledat výrobek (název, číslo)..." value="${esc(k.q)}" oninput="state._katalog.q=this.value;debounce('kat-q', renderKatalogList, 220)" style="padding-right:30px;width:100%">
        ${k.q ? `<button onclick="katalogClearSearch()" title="Vymazat hledání" style="position:absolute;right:6px;top:50%;transform:translateY(-50%);background:none;border:none;color:#999;cursor:pointer;font-size:18px;padding:4px 8px">✕</button>` : ''}
      </div>
      <label style="display:flex;align-items:center;gap:8px;font-size:14px;font-weight:600;white-space:nowrap;padding:6px 12px;background:var(--surface-2);border:1px solid var(--border);border-radius:8px;cursor:pointer" title="Sekcování výrobků do kategorií s mezinadpisy">
        <input type="checkbox" ${k.groupBy ? 'checked' : ''} onchange="state._katalog.groupBy=this.checked;renderKatalogList()" style="width:20px;height:20px;cursor:pointer;accent-color:var(--primary)">
        🔀 Roztřídit
      </label>
      <button class="btn-secondary" onclick="katalogSelectAllVisible()">✓ Vybrat vše viditelné</button>
      <button class="btn-secondary" onclick="katalogClearAll()" title="Smazat výběr i hledání">✕ Zrušit vše</button>
      <span id="kat-pocet" style="font-weight:600;color:#854F0B;margin-left:auto"></span>
      <button onclick="katalogOdeslatEmail()" class="btn-secondary" style="font-weight:600;font-size:13px;padding:10px 16px" title="Pošle PDF nabídku odběratelům ze slevové skupiny s jejich cenami">
        📧 Odeslat e-mailem
      </button>
      <button onclick="katalogGenerate()" style="background:#22863a;color:#fff;border:none;padding:10px 20px;border-radius:8px;cursor:pointer;font-weight:700;font-size:14px">
        📄 Vygenerovat PDF
      </button>
    </div>
    ${stStepHint(5, 'Klikni „📄 Vygenerovat PDF" → stáhne se soubor. „📧 Odeslat e-mailem" pošle PDF všem odběratelům z vybrané cenové skupiny.')}

    <!-- FILTR KATEGORIÍ -->
    <div class="kat-filter-wrap" style="margin-top:14px">
      <!-- 🔀 Řazení sekcí + Vše tlačítko -->
      <div style="display:flex;justify-content:space-between;align-items:center;gap:10px;flex-wrap:wrap;margin-bottom:10px">
        <button class="kat-btn-all ${k.kategorie_filtr === null ? 'active' : ''}" onclick="katalogFilterKat(null)"
                style="display:inline-flex;align-items:center;gap:10px;padding:10px 18px;background:${k.kategorie_filtr === null ? 'linear-gradient(135deg,#BA7517,#854F0B)' : 'var(--surface)'};color:${k.kategorie_filtr === null ? '#fff' : 'var(--text-1)'};border:1.5px solid ${k.kategorie_filtr === null ? '#854F0B' : 'var(--border)'};border-radius:10px;font-weight:700;font-size:14px;cursor:pointer">
          <span style="font-size:20px">📦</span>
          <span>Vše</span>
          <span style="background:${k.kategorie_filtr === null ? 'rgba(255,255,255,0.25)' : 'rgba(0,0,0,0.08)'};padding:2px 10px;border-radius:999px;font-size:12px;font-weight:800">${vsechny.length}</span>
        </button>
        <div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap">
          <label style="font-size:12px;color:var(--text-3);font-weight:600">🔀 Řazení sekcí:</label>
          <select id="kat-sort-mode" onchange="state._katalog.sortMode=this.value;try{localStorage.setItem('kat_sort_mode',this.value)}catch(e){};renderKatalog()" style="padding:6px 10px;border:1px solid var(--border);border-radius:8px;font-size:13px;font-weight:600;background:var(--surface)">
            <option value="poradi"   ${k.sortMode === 'poradi'   ? 'selected' : ''}>📋 Podle pořadí</option>
            <option value="abeceda"  ${k.sortMode === 'abeceda'  ? 'selected' : ''}>🔤 Abecedně</option>
            <option value="pocet"    ${k.sortMode === 'pocet'    ? 'selected' : ''}>📊 Podle počtu</option>
            <option value="emoji"    ${k.sortMode === 'emoji'    ? 'selected' : ''}>🎨 Podle emoji</option>
          </select>
          ${kategorie.length > 8 ? `
            <button class="btn-secondary" onclick="state._katalog.collapsed=!state._katalog.collapsed;try{localStorage.setItem('kat_collapsed',state._katalog.collapsed?'1':'0')}catch(e){};renderKatalog()" style="padding:6px 12px;font-size:12px;font-weight:600">
              ${k.collapsed ? '▼ Zobrazit všechny (' + kategorie.length + ')' : '▲ Sbalit'}
            </button>
          ` : ''}
        </div>
      </div>

      <!-- Velké ikony sekcí (bez Vše) -->
      <div class="kat-filter">
        ${(() => {
          let cats = kategorie.map(kat => ({
            ...kat,
            _pocet: vsechny.filter(v => (v.kategorie_id || 0) == kat.id).length,
          })).filter(c => c._pocet > 0);

          // Řazení podle vybraného módu
          const mode = k.sortMode || 'poradi';
          if (mode === 'abeceda') {
            cats.sort((a, b) => (a.nazev || '').localeCompare(b.nazev || '', 'cs'));
          } else if (mode === 'pocet') {
            cats.sort((a, b) => b._pocet - a._pocet || (a.nazev || '').localeCompare(b.nazev || '', 'cs'));
          } else if (mode === 'emoji') {
            cats.sort((a, b) => (a.ikona || '🥖').localeCompare(b.ikona || '🥖'));
          } else {
            cats.sort((a, b) => (a.poradi || 0) - (b.poradi || 0));
          }

          // Pokud collapsed → top 8 + "více"
          const showCount = (k.collapsed && cats.length > 8) ? 8 : cats.length;
          const visible = cats.slice(0, showCount);

          return visible.map(kat => `
            <button class="kat-btn ${k.kategorie_filtr == kat.id ? 'active' : ''}" onclick="katalogFilterKat(${kat.id})" title="${esc(kat.nazev)} (${kat._pocet} ks)">
              <span class="kat-btn-emoji">${esc(kat.ikona || '🥖')}</span>
              <span class="kat-btn-name">${esc(kat.nazev)}</span>
              <span class="kat-btn-count">${kat._pocet}</span>
            </button>
          `).join('') + (cats.length > showCount ? `
            <button class="kat-btn" onclick="state._katalog.collapsed=false;renderKatalog()" style="background:repeating-linear-gradient(45deg,transparent,transparent 8px,rgba(186,117,23,0.06) 8px,rgba(186,117,23,0.06) 12px);border-style:dashed">
              <span class="kat-btn-emoji">⋯</span>
              <span class="kat-btn-name">+ ${cats.length - showCount} další</span>
              <span class="kat-btn-count">${cats.length - showCount}</span>
            </button>
          ` : '');
        })()}
      </div>
      ${stStepHint(3, 'Filtruj podle kategorie nebo hledej v poli výše. „Vše" zobrazí všechny výrobky. Řazení sekcí změníš nahoře vpravo.')}
    </div>

    <!-- VÝROBKY -->
    ${stStepHint(4, 'Klikni na výrobek → přidá se do nabídky. K vybranému výrobku můžeš napsat poznámku (např. „akce", „poslední kusy").')}
    <div id="kat-list" style="margin-top:8px"></div>
  `;

  state._katalog._data = { vsechny, kategorie };
  renderKatalogList();
}

function renderKatalogList() {
  const k = state._katalog;
  if (!k || !k._data) return;
  const { vsechny, kategorie } = k._data;
  const list = document.getElementById('kat-list');
  if (!list) return;

  const q = (k.q || '').trim().toLowerCase();
  const filtr = k.kategorie_filtr;

  const filtered = vsechny.filter(v => {
    if (filtr !== null && (v.kategorie_id || 0) != filtr) return false;
    if (q && !((v.nazev || '').toLowerCase().includes(q) || (v.cislo || '').toLowerCase().includes(q))) return false;
    return true;
  });

  const groups = {};
  filtered.forEach(v => {
    const kid = v.kategorie_id || 0;
    if (!groups[kid]) groups[kid] = [];
    groups[kid].push(v);
  });

  const katMap = {};
  kategorie.forEach(kk => { katMap[kk.id] = kk; });

  if (filtered.length === 0) {
    list.innerHTML = `<div class="empty-state">Žádné výrobky odpovídající filtru. ${q ? `<br><button class="btn-secondary" style="margin-top:10px" onclick="katalogClearSearch()">Vymazat hledání</button>` : ''}</div>`;
  } else if (k.groupBy === false) {
    // 🔀 Plochý seznam — žádné kategorie, žádné mezinadpisy
    // Seřaď podle kategorie (poradi) → název pro stabilní pořadí
    const flat = filtered.slice().sort((a, b) => {
      const pa = katMap[a.kategorie_id]?.poradi ?? 999;
      const pb = katMap[b.kategorie_id]?.poradi ?? 999;
      if (pa !== pb) return pa - pb;
      return (a.nazev || '').localeCompare(b.nazev || '', 'cs');
    });
    list.innerHTML = `
      <div class="kat-products-grid" style="display:grid;grid-template-columns:repeat(auto-fill,minmax(260px,1fr));gap:10px;margin-bottom:18px">
        ${flat.map(v => katalogProductCard(v, katMap[v.kategorie_id])).join('')}
      </div>
    `;
  } else {
    // 🔀 Sekcovaný (default) — výrobky pod nadpisy kategorií
    const html = Object.keys(groups).sort((a, b) => {
      const pa = katMap[a]?.poradi ?? 999;
      const pb = katMap[b]?.poradi ?? 999;
      return pa - pb;
    }).map(kid => {
      const kat = katMap[kid];
      const items = groups[kid];
      const allSelected = items.every(v => k.vybrane.has(v.id));
      const someSelected = items.some(v => k.vybrane.has(v.id));
      return `
        <div class="kat-title" style="display:flex;align-items:center;gap:10px;cursor:pointer;user-select:none" onclick="katalogToggleGroup(${kid})">
          <input type="checkbox" ${allSelected ? 'checked' : ''} ${someSelected && !allSelected ? 'data-indeterminate="1"' : ''} onclick="event.stopPropagation();katalogToggleGroup(${kid})" style="width:18px;height:18px;cursor:pointer">
          <span style="font-size:28px;line-height:1;flex-shrink:0">${esc(kat?.ikona || '🥖')}</span>
          <span>${esc(kat?.nazev || 'Bez kategorie')}</span>
          <span style="color:#999;font-weight:400;font-size:13px">(${items.length})</span>
        </div>
        <div class="kat-products-grid" style="display:grid;grid-template-columns:repeat(auto-fill,minmax(260px,1fr));gap:10px;margin-bottom:18px">
          ${items.map(v => katalogProductCard(v, kat)).join('')}
        </div>
      `;
    }).join('');
    list.innerHTML = html;
    // Indeterminate stavy
    list.querySelectorAll('input[type=checkbox][data-indeterminate="1"]').forEach(cb => { cb.indeterminate = true; });
  }

  // Pocet badge
  const pocetEl = document.getElementById('kat-pocet');
  if (pocetEl) {
    pocetEl.textContent = k.vybrane.size > 0
      ? `Vybráno: ${k.vybrane.size}`
      : `${filtered.length} výrobků (klikni pro výběr)`;
  }
}

function katalogProductCard(v, kat) {
  const k = state._katalog;
  const on = k.vybrane.has(v.id);
  const pozn = k.poznamky[v.id] || '';
  const img = v.obrazek_url ? esc(v.obrazek_url) : '';
  return `
    <div class="kat-pcard ${on ? 'on' : ''}" style="border:2px solid ${on ? '#22863a' : '#e5e5e5'};background:${on ? '#f0f9f4' : '#fff'};border-radius:10px;overflow:hidden;cursor:pointer;transition:all 0.15s" onclick="katalogToggle(${v.id})">
      <div style="display:flex;gap:10px;padding:10px;align-items:center">
        ${img ? `<img src="${img}" style="width:56px;height:56px;border-radius:6px;object-fit:cover;flex-shrink:0">` : `<div style="width:56px;height:56px;border-radius:6px;background:#F7F8FA;display:flex;align-items:center;justify-content:center;font-size:26px;flex-shrink:0">${esc(kat?.ikona || '🥖')}</div>`}
        <div style="flex:1;min-width:0">
          <div style="font-weight:600;font-size:14px;line-height:1.2;color:#222">${esc(v.nazev)}</div>
          <div style="color:#888;font-size:11px;margin-top:3px">${v.cislo ? 'kód ' + esc(v.cislo) + ' · ' : ''}${fmt(v.cena_bez_dph)}${!v.aktivni ? ' · <span style="color:#a32d2d">skrytý</span>' : ''}</div>
        </div>
        <div style="font-size:22px;color:${on ? '#22863a' : '#ccc'};flex-shrink:0">${on ? '✓' : '○'}</div>
      </div>
      ${on ? `
        <div onclick="event.stopPropagation()" style="padding:8px 10px;background:#fff;border-top:1px solid #d9efde">
          <input type="text"
                 class="kat-pozn-input"
                 placeholder="Poznámka na PDF (např. poslední kusy, akce -10 %)"
                 value="${esc(pozn)}"
                 oninput="katalogSetPozn(${v.id}, this.value)"
                 style="width:100%;padding:6px 8px;border:1px solid #d9efde;border-radius:5px;font-size:12px;background:#fafefb">
        </div>
      ` : ''}
    </div>
  `;
}

window.katalogFilterKat = function(id) {
  state._katalog.kategorie_filtr = id;
  renderKatalog();
};

window.katalogToggle = function(id) {
  const k = state._katalog;
  if (k.vybrane.has(id)) {
    k.vybrane.delete(id);
    delete k.poznamky[id];
  } else {
    k.vybrane.add(id);
  }
  renderKatalogList();
};

window.katalogSetPozn = function(id, text) {
  const k = state._katalog;
  if ((text || '').trim() === '') delete k.poznamky[id];
  else k.poznamky[id] = text;
  // Nepřerenderuj — uživatel právě píše. State se aktualizuje, render se aktualizuje při příští změně.
};

window.katalogToggleGroup = function(kid) {
  const k = state._katalog;
  const q = (k.q || '').trim().toLowerCase();
  const visible = k._data.vsechny.filter(v => {
    if ((v.kategorie_id || 0) != kid) return false;
    if (k.kategorie_filtr !== null && (v.kategorie_id || 0) != k.kategorie_filtr) return false;
    if (q && !((v.nazev || '').toLowerCase().includes(q) || (v.cislo || '').toLowerCase().includes(q))) return false;
    return true;
  });
  const allOn = visible.length > 0 && visible.every(v => k.vybrane.has(v.id));
  visible.forEach(v => {
    if (allOn) {
      k.vybrane.delete(v.id);
      delete k.poznamky[v.id];
    } else {
      k.vybrane.add(v.id);
    }
  });
  renderKatalogList();
};

window.katalogSelectAllVisible = function() {
  const k = state._katalog;
  const q = (k.q || '').trim().toLowerCase();
  const visible = k._data.vsechny.filter(v => {
    if (k.kategorie_filtr !== null && (v.kategorie_id || 0) != k.kategorie_filtr) return false;
    if (q && !((v.nazev || '').toLowerCase().includes(q) || (v.cislo || '').toLowerCase().includes(q))) return false;
    return true;
  });
  visible.forEach(v => k.vybrane.add(v.id));
  renderKatalogList();
};

// Smaže jen text v hledání
window.katalogClearSearch = function() {
  state._katalog.q = '';
  const i = document.getElementById('kat-q');
  if (i) i.value = '';
  renderKatalog();
};

// Smaže výběr + poznámky + hledání + filtr kategorie
window.katalogClearAll = function() {
  const k = state._katalog;
  k.vybrane.clear();
  k.poznamky = {};
  k.q = '';
  k.kategorie_filtr = null;
  renderKatalog();
};

