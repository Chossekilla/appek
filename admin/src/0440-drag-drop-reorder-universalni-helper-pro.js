// =============================================================
// DRAG & DROP REORDER — universální helper pro tbody nebo grid
// =============================================================
function attachReorderListeners(containerId, itemSelector) {
  const container = document.getElementById(containerId);
  if (!container) return;

  let draggedEl = null;

  // Helper - najde nejbližší draggable item, který má data-id
  const findItem = (target) => {
    const el = target.closest(itemSelector);
    if (!el || !el.dataset?.id) return null;
    return el;
  };

  container.addEventListener('dragstart', (e) => {
    const target = findItem(e.target);
    if (!target) return;
    draggedEl = target;
    // setTimeout aby se class přidala až po snapshot
    setTimeout(() => target.classList.add('dragging'), 0);
    e.dataTransfer.effectAllowed = 'move';
    e.dataTransfer.setData('text/plain', target.dataset.id);
  });

  container.addEventListener('dragend', () => {
    // Nech dragged element vyčistit, ale draggedEl proměnnou nuluj později
    container.querySelectorAll('.drag-over').forEach(el => el.classList.remove('drag-over'));
    if (draggedEl) draggedEl.classList.remove('dragging');
    // Pozor: dragend může nastat PŘED drop, neuklízej draggedEl tady
  });

  container.addEventListener('dragover', (e) => {
    e.preventDefault();
    e.dataTransfer.dropEffect = 'move';
    const target = findItem(e.target);
    if (!target || target === draggedEl) return;
    container.querySelectorAll('.drag-over').forEach(el => el.classList.remove('drag-over'));
    target.classList.add('drag-over');
  });

  container.addEventListener('drop', async (e) => {
    e.preventDefault();
    container.querySelectorAll('.drag-over').forEach(el => el.classList.remove('drag-over'));

    if (!draggedEl) return;

    const target = findItem(e.target);
    // Pokud cíl chybí nebo je to ten samý prvek, jen ukončit
    if (!target || target === draggedEl) {
      draggedEl = null;
      return;
    }

    // Vlož draggedEl před/za target podle pozice myši
    const rect = target.getBoundingClientRect();
    const mid = rect.top + rect.height / 2;
    if (e.clientY < mid) {
      target.parentNode.insertBefore(draggedEl, target);
    } else {
      target.parentNode.insertBefore(draggedEl, target.nextSibling);
    }

    const movedEl = draggedEl;
    draggedEl = null;

    // Sbírám nové pořadí JEN z prvků, které mají data-id
    const items = container.querySelectorAll(`[data-id]`);
    const poradi = Array.from(items).map((el, i) => ({
      id: parseInt(el.dataset.id),
      poradi: i,
    }));

    try {
      await api('admin_vyrobky.php?action=update_poradi', {
        method: 'POST',
        body: { poradi },
      });
      // Krátký zelený flash
      movedEl.classList.add('drag-saved');
      setTimeout(() => movedEl.classList.remove('drag-saved'), 600);
    } catch (err) {
      alert('Nepodařilo se uložit pořadí: ' + err.message);
      renderVyrobky(state._vyrobkyFilters || {});
    }
  });
}

window.applyVyrobkyFilters = function() {
  const q = document.getElementById('vf-q').value;
  const stav = document.getElementById('vf-stav').value;
  renderVyrobky({
    ...(state._vyrobkyFilters || {}),
    q,
    stav,
  });
};

window.filterVyrobkyKat = function(katId) {
  renderVyrobky({
    ...(state._vyrobkyFilters || {}),
    kategorie_id: katId,
  });
};

window.otevritPrecislovat = async function() {
  // Načti kategorie pro per-category startovní čísla
  let kategorie = [];
  try {
    const r = await api('admin_vyrobky.php');
    kategorie = (r && Array.isArray(r.kategorie)) ? r.kategorie : [];
  } catch (e) {}

  openModal('🔢 Přečíslovat výrobky', `
    <p style="font-size:14px;color:var(--text-2);margin-bottom:14px">
      Přepíše <strong>kód</strong> všech výrobků novými čísly. Můžeš zvolit globální startovní číslo nebo dát každé kategorii vlastní rozsah.
    </p>

    <!-- Režim přečíslování -->
    <div style="display:grid;grid-template-columns:1fr 1fr;gap:8px;margin-bottom:12px">
      <button id="pre-mode-single" class="btn-secondary" onclick="preSetMode('single')" style="padding:10px;font-weight:600">📋 Globální (od jednoho čísla)</button>
      <button id="pre-mode-cat" class="btn-secondary" onclick="preSetMode('cat')" style="padding:10px;font-weight:600">📂 Po kategoriích (různé starty)</button>
    </div>

    <!-- GLOBÁLNÍ -->
    <div id="pre-form-single">
      <div class="form-grid form-grid-tight">
        <div>
          <label class="form-label">Začít od</label>
          <input class="form-input" id="pre-start" type="number" min="1" value="1">
        </div>
        <div>
          <label class="form-label">Předpona <span style="color:var(--text-3);font-weight:400;font-size:11px">(volitelné)</span></label>
          <input class="form-input" id="pre-prefix" value="" placeholder="např. CH, BG, … (prázdné = bez)">
        </div>
        <div>
          <label class="form-label">Doplnit nuly zleva</label>
          <select class="form-input" id="pre-pad">
            <option value="0">— bez —</option>
            <option value="2">2 znaky (01, 02)</option>
            <option value="3">3 znaky (001, 002)</option>
            <option value="4" selected>4 znaky (0001, 0002)</option>
          </select>
        </div>
        <div>
          <label class="form-label">Řazení v rámci sady</label>
          <select class="form-input" id="pre-order">
            <option value="0">Manuální + abeceda</option>
            <option value="1" selected>Po kategoriích</option>
          </select>
        </div>
      </div>
    </div>

    <!-- PO KATEGORIÍCH -->
    <div id="pre-form-cat" style="display:none">
      <div style="font-size:12px;color:var(--text-2);margin-bottom:8px">Pro každou kategorii zadej startovní číslo. Pořadí v rámci kategorie = manuální + abeceda. Doplnění nul a předpona se použijí ze vstupu vlevo dole.</div>
      <div style="max-height:280px;overflow-y:auto;border:1px solid var(--border);border-radius:6px;padding:8px">
        ${kategorie.length === 0
          ? '<div style="padding:12px;text-align:center;color:var(--text-3);font-size:12px">Žádné kategorie</div>'
          : kategorie.map(k => {
              // Přednastavený start podle pozice (chleby 1001, dále po 1000)
              const defaultStart = (k.poradi != null ? (parseInt(k.poradi) + 1) * 1000 + 1 : 1001);
              return `
                <div style="display:grid;grid-template-columns:auto 1fr 110px;gap:8px;align-items:center;padding:5px 4px;border-bottom:1px solid var(--surface-2)">
                  <span style="font-size:18px;width:24px;text-align:center">${esc(k.ikona || '📦')}</span>
                  <span style="font-size:13px"><strong>${esc(k.nazev)}</strong> <span style="color:var(--text-3);font-size:11px">(${k.pocet_vyrobku || 0} výr.)</span></span>
                  <input class="form-input pre-cat-start" data-katid="${k.id}" type="number" min="1" value="${defaultStart}" placeholder="start" style="text-align:right;font-size:13px">
                </div>
              `;
            }).join('')}
      </div>
      <div class="form-grid form-grid-tight" style="margin-top:10px">
        <div>
          <label class="form-label">Předpona <span style="color:var(--text-3);font-weight:400;font-size:11px">(volitelné)</span></label>
          <input class="form-input" id="pre-cat-prefix" value="" placeholder="prázdné = bez">
        </div>
        <div>
          <label class="form-label">Doplnit nuly zleva</label>
          <select class="form-input" id="pre-cat-pad">
            <option value="0">— bez —</option>
            <option value="3">3 znaky</option>
            <option value="4" selected>4 znaky (0001)</option>
          </select>
        </div>
      </div>
    </div>

    <!-- Společné checkboxy -->
    <div style="margin-top:12px;padding-top:12px;border-top:1px dashed var(--border)">
      <label class="checkbox-row" style="margin:6px 0">
        <input type="checkbox" id="pre-aktivni" checked>
        <span>Jen aktivní výrobky (skryté přeskočit)</span>
      </label>
      <label class="checkbox-row" style="margin:0">
        <input type="checkbox" id="pre-propag" checked>
        <span>🔗 Propagovat čísla i do dodacích listů a faktur</span>
      </label>
    </div>

    <div style="margin-top:8px;padding:10px 12px;background:#FEF3C7;border-radius:6px;font-size:12px;color:#92400e">
      ⚠️ <strong>Operace je nevratná</strong> — všechna stávající čísla (např. „PE001", „CH012") budou přepsána novými.<br>
      🔗 <strong>Propagace</strong>: aktualizuje i kódy v existujících dodacích listech a fakturách (matchne podle ID výrobku — bezpečné).
    </div>

    <div class="form-actions">
      <button class="btn-secondary" onclick="closeModal()">Zrušit</button>
      <div style="flex:1"></div>
      <button class="btn-primary btn-green" onclick="precislovatVyrobky()">🔢 Přečíslovat</button>
    </div>
  `);

  // Init mode highlight
  setTimeout(() => preSetMode('single'), 30);
};

window.preSetMode = function(mode) {
  const single = document.getElementById('pre-mode-single');
  const cat = document.getElementById('pre-mode-cat');
  const fSingle = document.getElementById('pre-form-single');
  const fCat = document.getElementById('pre-form-cat');
  if (!single || !cat) return;
  const active = 'background:#15803D;color:white;border-color:#15803D';
  const inactive = 'background:var(--surface-2);color:var(--text-2);border-color:var(--border)';
  if (mode === 'single') {
    single.style.cssText = 'padding:10px;font-weight:600;' + active;
    cat.style.cssText = 'padding:10px;font-weight:600;' + inactive;
    if (fSingle) fSingle.style.display = '';
    if (fCat) fCat.style.display = 'none';
  } else {
    single.style.cssText = 'padding:10px;font-weight:600;' + inactive;
    cat.style.cssText = 'padding:10px;font-weight:600;' + active;
    if (fSingle) fSingle.style.display = 'none';
    if (fCat) fCat.style.display = '';
  }
  document.body.dataset.preMode = mode;
};

window.precislovatVyrobky = async function() {
  const mode = document.body.dataset.preMode || 'single';
  const jenAktivni = document.getElementById('pre-aktivni').checked;
  const propagovat = document.getElementById('pre-propag').checked;
  let payload;
  let sample = [];

  if (mode === 'cat') {
    const prefix = (document.getElementById('pre-cat-prefix')?.value || '').trim();
    const pad = parseInt(document.getElementById('pre-cat-pad')?.value) || 0;
    const kategorie_start = {};
    document.querySelectorAll('.pre-cat-start').forEach(el => {
      const kid = parseInt(el.dataset.katid) || 0;
      const v = parseInt(el.value) || 0;
      if (kid && v) kategorie_start[kid] = v;
    });
    if (Object.keys(kategorie_start).length === 0) return alert('Vyplň alespoň jednu startovní hodnotu pro kategorii.');

    // Náhled — prvních pár startů
    Object.entries(kategorie_start).slice(0, 3).forEach(([kid, st]) => {
      const cislo = pad > 0 ? String(st).padStart(pad, '0') : String(st);
      sample.push((prefix || '') + cislo);
    });

    payload = {
      prefix, pad,
      jen_aktivni: jenAktivni,
      per_kategorii: 1,
      kategorie_start,
      propagovat: propagovat ? 1 : 0,
    };
  } else {
    const start = parseInt(document.getElementById('pre-start').value) || 1;
    const prefix = document.getElementById('pre-prefix').value.trim();
    const pad = parseInt(document.getElementById('pre-pad').value) || 0;
    const order = document.getElementById('pre-order').value;

    for (let i = 0; i < 3; i++) {
      const num = start + i;
      let cislo = pad > 0 ? String(num).padStart(pad, '0') : String(num);
      if (prefix) cislo = prefix + cislo;
      sample.push(cislo);
    }

    payload = {
      start, prefix, pad,
      jen_aktivni: jenAktivni,
      podle_kategorie: order === '1',
      propagovat: propagovat ? 1 : 0,
    };
  }

  let msg = `Přečíslovat výrobky?\n\nPříklad nových kódů: ${sample.join(', ')}…`;
  if (propagovat) msg += '\n\n🔗 Propagace: změny se promítnou do dodacích listů a faktur.';
  msg += '\n\nTato akce je nevratná — všechna stávající čísla budou přepsána.';
  if (!(await confirmDialog({ msg: msg, danger: false }))) return;

  try {
    const r = await api('admin_vyrobky.php?action=renumber', {
      method: 'POST',
      body: JSON.stringify(payload),
    });
    closeModal();
    let msg2 = `✓ Přečíslováno ${r.pocet} výrobků.\n\nPrvní kódy: ${r.preklady.map(p => p.cislo).join(', ')}…`;
    if (r.propagovano_zaznamu > 0) {
      msg2 += `\n\n🔗 Propagace: aktualizováno ${r.propagovano_zaznamu} záznamů v DL/FA.`;
    }
    alert(msg2);
    navigate('vyrobky');
  } catch (e) { alert('Chyba: ' + e.message); }
};

window.vyNextCislo = async function() {
  try {
    const r = await api('admin_vyrobky.php?action=next_cislo');
    const el = document.getElementById('vy-cislo');
    if (el && r && r.next) {
      el.value = String(r.next);
      el.focus();
      el.select();
    }
  } catch (e) { alert('Chyba: ' + e.message); }
};

window.editVyrobek = async function(id = null) {
  const [data, _suroviny] = await Promise.all([
    api('admin_vyrobky.php'),
    loadSurovinyCache().catch(() => []),
  ]);
  const v = id ? await api(`admin_vyrobky.php?id=${id}`) : {};

  // 🆕 v3.0.156 — kuchyňské stanice pro pole „Doba přípravy + stanice" (jen balíček Restaurace; 402/chyba → bez sekce)
  data.stanice = await api('admin_kitchen.php').then(r => r.stanice || []).catch(() => []);
  // 🆕 v3.0.213 — přepínač „Zobrazovat na POS" jen když je aktivní balíček Restaurace (POS = restaurace)
  data._restaurace = await packageActive('restaurace');

  // Pro nový výrobek — předvyplň další volné číslo (max + 1)
  if (!id && !v.cislo) {
    try {
      const r = await api('admin_vyrobky.php?action=next_cislo');
      if (r && r.next) v.cislo = String(r.next);
    } catch (e) { /* ignore */ }
  }

  openModal(id ? `Výrobek ${esc(v.nazev)}` : 'Nový výrobek', `
    <div class="vy-top-row" style="margin-bottom:16px">
      <div class="image-upload">
        <div class="image-preview" id="img-preview">
          ${v.obrazek_url ? `<img src="${esc(v.obrazek_url)}">` : '<div class="image-preview-empty">📷</div>'}
        </div>
        <div class="image-upload-controls">
          <label class="form-label" style="margin-bottom:2px">Obrázek výrobku</label>
          <input type="file" id="img-file" accept="image/jpeg,image/png,image/webp" onchange="uploadObrazek()">
          <input type="hidden" id="img-url" value="${esc(v.obrazek_url || '')}">
          ${v.obrazek_url ? '<button class="btn-secondary" style="font-size:11px;padding:4px 10px;width:auto;align-self:flex-start;" onclick="zrusitObrazek()">✕ Odebrat</button>' : ''}
          <div class="image-upload-hint">JPG, PNG, WEBP · max 5 MB</div>
        </div>
      </div>
      ${id ? `
        <div class="vy-modal-toptools">
          <button class="btn-primary btn-green" onclick="ulozitVyrobek(${id})">💾 Uložit</button>
          <a href="../api/vyrobek_pdf.php?id=${id}" target="_blank" class="btn-secondary" style="text-decoration:none">📄 Tisk PDF</a>
          <!-- 🆕 v2.9.277 — Otevřít kalkulaci s pre-loaded výrobkem -->
          <button class="btn-secondary" onclick="otevritKalkulaciProVyrobek(${id})" title="Otevři výrobní kalkulaci s pre-loaded recepturou tohoto výrobku">🧮 Kalkulace</button>
        </div>
      ` : ''}
    </div>

    <div class="form-grid">
      <div class="full vy-section-box">
      <div class="vy-section-title">📋 Základ & balení</div>
      <div class="vy-id-row">
        <div>
          <label class="form-label">Číslo <span style="color:var(--text-3);font-weight:400;font-size:11px">(auto = další volné)</span></label>
          <div style="display:flex;gap:4px">
            <input class="form-input" id="vy-cislo" value="${esc(v.cislo || '')}" placeholder="1, 2, 3…" style="flex:1">
            <button class="btn-secondary" type="button" onclick="vyNextCislo()" style="font-size:11px;padding:6px 10px;white-space:nowrap" title="Najít další volné číslo">🔢</button>
          </div>
        </div>
        <div>
          <label class="form-label">EAN-13 <span style="color:var(--text-3);font-weight:400;font-size:11px">(volitelné)</span></label>
          <div style="display:flex;gap:6px;align-items:center">
            <input class="form-input" id="vy-ean" value="${esc(v.ean || '')}" placeholder="13 číslic" maxlength="13" pattern="\\d{12,13}" style="flex:1">
            <button type="button" class="btn-secondary" title="Vygeneruj interní EAN-13 (prefix 28)" onclick="appekGenEan(${v.id || 0}, function(e){var el=document.getElementById('vy-ean');if(el)el.value=e;})" style="white-space:nowrap;font-size:12px;padding:8px 10px">🔢 EAN</button>
            <button type="button" class="btn-secondary" title="Tisk EAN štítku (čárový kód)" onclick="appekPrintEanLabels(${v.id || 0})" style="white-space:nowrap;font-size:12px;padding:8px 10px">🏷️ Tisk</button>
          </div>
        </div>
        <div>
          <label class="form-label">⚖️ Prodej na váhu</label>
          <label style="display:flex;align-items:center;gap:8px;cursor:pointer;padding-top:6px">
            <input type="checkbox" id="vy-navahu" ${v.na_vahu ? 'checked' : ''}>
            <span style="font-size:13px">Vážený produkt — cena za
              <select id="vy-vjed" style="font-size:12px;padding:2px 4px">
                <option value="kg" ${(v.vaha_jednotka || 'kg') === 'kg' ? 'selected' : ''}>kg</option>
                <option value="100g" ${v.vaha_jednotka === '100g' ? 'selected' : ''}>100 g</option>
              </select>
            </span>
          </label>
        </div>
        <div>
          <label class="form-label">PLU <span style="color:var(--text-3);font-weight:400;font-size:11px">(kód pro váhové štítky)</span></label>
          <input class="form-input" id="vy-plu" value="${v.plu || ''}" placeholder="např. 100" inputmode="numeric">
        </div>
        <div>
          <label class="form-label">Obsah balení <span style="color:var(--text-3);font-weight:400;font-size:11px">(přepočet ceny/kg)</span></label>
          <div style="display:flex;gap:4px">
            <input class="form-input" id="vy-obsah" type="number" step="0.01" min="0" value="${v.obsah || (v.hmotnost_g || '')}" placeholder="500" style="flex:1" oninput="vyPrepocet()">
            <select class="form-input" id="vy-obsah-jed" style="width:70px;flex-shrink:0" onchange="vyPrepocet()">
              <option value=""   ${!v.obsah_jednotka && !v.hmotnost_g ? 'selected' : ''}>—</option>
              <option value="g"  ${(v.obsah_jednotka === 'g' || (!v.obsah_jednotka && v.hmotnost_g)) ? 'selected' : ''}>g</option>
              <option value="kg" ${v.obsah_jednotka === 'kg' ? 'selected' : ''}>kg</option>
              <option value="ml" ${v.obsah_jednotka === 'ml' ? 'selected' : ''}>ml</option>
              <option value="l"  ${v.obsah_jednotka === 'l'  ? 'selected' : ''}>l</option>
            </select>
          </div>
          <div id="vy-prepocet" style="margin-top:4px;font-size:11px;color:#854F0B;font-weight:600;display:none"></div>
        </div>
      </div>
      </div>
      <input type="hidden" id="vy-hm" value="${v.hmotnost_g || ''}">

      <!-- 📦 Hmotnost & rozměry — pro výpočet dopravy / přepravce (v3.0.340) -->
      <div class="full vy-section-box">
        <div class="vy-section-title">📦 Hmotnost & rozměry <span style="color:var(--text-3);font-weight:400;font-size:11px">(pro dopravu / přepravce)</span></div>
        <div style="display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:10px">
          <div><label class="form-label">Hmotnost (g)</label><input class="form-input" id="vy-hmotnost" type="number" min="0" step="1" value="${v.hmotnost_g || ''}" placeholder="500"></div>
          <div><label class="form-label">Délka (cm)</label><input class="form-input" id="vy-rozmer-d" type="number" min="0" step="0.1" value="${v.rozmer_d || ''}" placeholder="20"></div>
          <div><label class="form-label">Šířka (cm)</label><input class="form-input" id="vy-rozmer-s" type="number" min="0" step="0.1" value="${v.rozmer_s || ''}" placeholder="10"></div>
          <div><label class="form-label">Výška (cm)</label><input class="form-input" id="vy-rozmer-v" type="number" min="0" step="0.1" value="${v.rozmer_v || ''}" placeholder="8"></div>
        </div>
      </div>

      <!-- Řádek: Název | Kategorie | Jednotka | Min. obj. -->
      <div class="full vy-section-box">
      <div class="vy-section-title">📝 Název & zařazení</div>
      <div class="vy-nazev-row">
        <div>
          <label class="form-label">Název *</label>
          <input class="form-input" id="vy-nazev" value="${esc(v.nazev || '')}" required>
        </div>
        <div>
          <label class="form-label">Kategorie</label>
          <select class="form-select" id="vy-kat">
            <option value="">—</option>
            ${(() => {
              const cats = data.kategorie || [];
              const subs = {};
              cats.filter(c => c.parent_id).forEach(c => { (subs[c.parent_id] = subs[c.parent_id] || []).push(c); });
              const opt = (k, sub) => `<option value="${k.id}" ${v.kategorie_id == k.id ? 'selected' : ''}>${sub ? '&nbsp;&nbsp;↳ ' : (esc(k.ikona || '🥖') + ' ')}${esc(k.nazev)}</option>`;
              let html = '';
              cats.filter(c => !c.parent_id).forEach(m => { html += opt(m, false); (subs[m.id] || []).forEach(s => { html += opt(s, true); }); });
              // osiřelé subkategorie (rodič smazán) — plochý fallback
              cats.filter(c => c.parent_id && !cats.find(m => m.id == c.parent_id)).forEach(s => { html += opt(s, false); });
              return html;
            })()}
          </select>
        </div>
        <div>
          <label class="form-label">Jednotka</label>
          <select class="form-select" id="vy-jed">
            ${data.jednotky.map((j) => `<option value="${j.id}" ${v.jednotka_id == j.id ? 'selected' : ''}>${esc(j.kod)} - ${esc(j.nazev)}</option>`).join('')}
          </select>
        </div>
        <div>
          <label class="form-label">Min. obj.</label>
          <input class="form-input" id="vy-min" type="number" min="1" value="${v.min_objednavka || 1}">
        </div>
      </div>
      </div>
      <!-- 🆕 v3.0.295 — Obor (provázání s balíčky): kapacita pečení + dort konfigurátor -->
      <div class="full" style="display:flex;gap:10px;align-items:flex-end;flex-wrap:wrap;background:var(--surface-2);border-radius:10px;padding:10px 14px;margin-top:4px">
        <div>
          <label class="form-label" style="font-size:12px">🎁 Obor (pro balíčky)</label>
          <select class="form-select" id="vy-obor" onchange="vyOborChange()" style="min-width:170px">
            <option value=""           ${!v.obor ? 'selected' : ''}>— běžný výrobek —</option>
            <option value="dort"       ${v.obor === 'dort' ? 'selected' : ''}>🎂 Dort (konfigurovatelný)</option>
            <option value="chlebicek"  ${v.obor === 'chlebicek' ? 'selected' : ''}>🥪 Chlebíček</option>
            <option value="zakusek"    ${v.obor === 'zakusek' ? 'selected' : ''}>🍰 Zákusek</option>
          </select>
        </div>
        <div id="vy-obor-hint" style="flex:1;min-width:200px;font-size:12px;color:var(--text-3);padding-bottom:9px"></div>
      </div>
      <!-- 🆕 v3.0.303 — Polotovar (sestavy/BOM) + rollup + výrobní postup -->
      <div class="full" style="display:flex;gap:18px;align-items:center;flex-wrap:wrap;background:#FAF5FF;border:1px solid #E9D5FF;border-radius:10px;padding:10px 14px;margin-top:4px">
        <label style="display:flex;align-items:center;gap:6px;font-size:13px;font-weight:600;color:#7C3AED"><input type="checkbox" id="vy-polotovar" ${v.je_polotovar ? 'checked' : ''} onchange="var w=document.getElementById('vy-sled-wrap');if(w)w.style.display=this.checked?'flex':'none'">🧩 Polotovar (složka jiných výrobků)</label>
        <label id="vy-sled-wrap" style="display:${v.je_polotovar ? 'flex' : 'none'};align-items:center;gap:6px;font-size:12px;color:var(--text-2)"><input type="checkbox" id="vy-sleduje-sklad" ${v.sleduje_sklad ? 'checked' : ''}>📦 sleduje vlastní sklad <span style="color:var(--text-3)">(jinak se ve výrobě rozpadne na suroviny)</span></label>
        ${v.bom_naklad != null ? `<span style="margin-left:auto;font-size:12px;color:#1E40AF">🧮 Materiál sestavy: <strong>${fmt(v.bom_naklad)}</strong>${(Array.isArray(v.bom_alergeny) && v.bom_alergeny.length) ? ' · alergeny: ' + v.bom_alergeny.map(esc).join(', ') : ''}</span>` : ''}
      </div>
      <div class="full" style="margin-top:4px">
        <label class="form-label" style="font-size:12px">👨‍🍳 Výrobní postup <span style="font-weight:400;color:var(--text-3)">(kroky — jeden na řádek, tiskne se ve výrobním listu)</span></label>
        <textarea class="form-input" id="vy-postup" rows="3" placeholder="Vyšlehat máslo s cukrem&#10;Přidat vejce po jednom&#10;Péct 180 °C / 35 min" style="font-size:13px;resize:vertical">${esc((function(){try{return (JSON.parse(v.postup_json||'[]')||[]).join('\n');}catch(e){return '';}})())}</textarea>
      </div>
      <!-- Popis | Složení (vedle sebe) -->
      <div class="full vy-section-box">
      <div class="vy-section-title">📝 Texty (popis · složení · alergeny)</div>
      <div class="vy-popis-sloz-row">
        <div>
          <label class="form-label">Popis</label>
          <textarea class="form-textarea" id="vy-popis" rows="3">${esc(v.popis || '')}</textarea>
        </div>
        <div>
          <div style="display:flex;justify-content:space-between;align-items:end;margin-bottom:4px;gap:8px;flex-wrap:wrap">
            <label class="form-label" style="margin:0">Složení <span style="color:var(--text-3);font-weight:400;font-size:11px">(pro cenovky/PDF)</span></label>
            <button type="button" class="btn-secondary" onclick="vyOdvoditSlozeniText()" style="font-size:11px;padding:3px 8px">🧪 ze surovin</button>
          </div>
          <textarea class="form-textarea" id="vy-sloz" rows="3" placeholder="pšeničná mouka, voda, sůl, droždí…">${esc(v.slozeni_text || (typeof v.slozeni === 'string' ? v.slozeni : '') || '')}</textarea>
        </div>
      </div>

      <!-- Alergeny (full) -->
      <div class="full">
        <label class="form-label" style="display:flex;justify-content:space-between;align-items:center">
          <span>Alergeny</span>
          <button type="button" class="btn-secondary" onclick="vyOdvoditAlergeny()" style="font-size:11px;padding:3px 10px" title="Sečte alergeny ze všech surovin v receptu — vč. detekovaných ze složení (Diasauer → lepek)">🧬 Doplnit ze surovin</button>
        </label>
        <input class="form-input" id="vy-aler" value="${esc(v.alergeny || '')}" placeholder="lepek, mléko, vejce...">
      </div>
      </div>

      <!-- 💰 CENA — oddělená sekce -->
      <div class="full vy-section-box">
        <div class="vy-section-title">💰 Cena</div>
        <div class="vy-cena-row">
          <div class="vy-cena-box">
            <label class="form-label">Cena bez DPH (Kč) *</label>
            <input class="form-input vy-cena-input" id="vy-cena" type="number" step="0.01" min="0" value="${v.cena_bez_dph || ''}" required placeholder="0,00" oninput="vyUpdateCenaSDph()">
          </div>
          <div>
            <label class="form-label">Sazba DPH</label>
            <select class="form-select" id="vy-dph" onchange="vyUpdateCenaSDph()">
              ${data.sazby.map((s) => `<option value="${s.id}" data-sazba="${s.sazba}" ${v.sazba_dph_id == s.id ? 'selected' : ''}>${s.sazba}% (${esc(s.nazev)})</option>`).join('')}
            </select>
          </div>
          <div>
            <label class="form-label">Cena s DPH (Kč)</label>
            <input class="form-input vy-cena-input vy-cena-sdph" id="vy-cena-sdph" type="number" step="0.01" min="0" placeholder="0,00" oninput="vyUpdateCenaBezDph()">
          </div>
        </div>
      </div>
      ${(data.stanice && data.stanice.length) ? `
      <!-- 🍳 KUCHYNĚ (KDS) — jen s balíčkem Restaurace (existují stanice) -->
      <div class="full vy-section-box">
        <div class="vy-section-title">🍳 Kuchyně (KDS)</div>
        <div style="display:grid;grid-template-columns:1fr 2fr;gap:12px">
          <div>
            <label class="form-label">⏱️ Doba přípravy (min)</label>
            <input class="form-input" id="vy-prep" type="number" min="0" step="1" value="${v.priprava_min ?? 10}">
          </div>
          <div>
            <label class="form-label">Kuchyňská stanice</label>
            <select class="form-select" id="vy-station">
              <option value="0">— Žádná —</option>
              ${data.stanice.map((s) => `<option value="${s.id}" ${v.kitchen_station_id == s.id ? 'selected' : ''}>${esc(s.ikona)} ${esc(s.nazev)}</option>`).join('')}
            </select>
          </div>
        </div>
        <div style="font-size:11.5px;color:var(--text-3);margin-top:6px">Po objednání v POS se položka objeví na kuchyňském boardu u této stanice s touto dobou přípravy.</div>
      </div>` : ''}
      <div class="full vy-section-box vy-nutr-section">
        <div class="vy-section-title" style="display:flex;align-items:center;justify-content:space-between;gap:8px;flex-wrap:wrap">
          <span>🥗 Nutriční hodnoty <span style="color:var(--text-3);font-weight:400;font-size:11px">(na 100 g výrobku)</span></span>
          <div style="display:flex;gap:6px">
            <button type="button" class="btn-secondary" onclick="vyNutriZeSurovin()" style="font-size:11px;padding:4px 10px;font-weight:500" title="Spočítá nutriční hodnoty na 100 g výrobku z aktuální receptury (vážený průměr surovin podle množství)">🧮 Spočítat ze surovin</button>
            <button type="button" class="btn-secondary" onclick="vyClearNutr()" style="font-size:11px;padding:4px 10px;font-weight:500">✕ Vyčistit</button>
          </div>
        </div>
        ${(() => {
          const n = v.nutricni_hodnoty
            ? (typeof v.nutricni_hodnoty === 'string' ? (function(){ try { return JSON.parse(v.nutricni_hodnoty); } catch(e) { return {}; } })() : v.nutricni_hodnoty)
            : {};
          const fields = [
            { k: 'energie_kj',     l: 'Energie',           jed: 'kJ' },
            { k: 'energie_kcal',   l: 'Energie',           jed: 'kcal' },
            { k: 'tuky',           l: 'Tuky',              jed: 'g' },
            { k: 'tuky_nasycene',  l: '— z toho nasycené', jed: 'g' },
            { k: 'sacharidy',      l: 'Sacharidy',         jed: 'g' },
            { k: 'cukry',          l: '— z toho cukry',    jed: 'g' },
            { k: 'bilkoviny',      l: 'Bílkoviny',         jed: 'g' },
            { k: 'sul',            l: 'Sůl',               jed: 'g' },
          ];
          return `<div class="vy-nutr-grid">${fields.map(f => `
            <label class="vy-nutr-cell">
              <span class="vy-nutr-label">${esc(f.l)}</span>
              <span class="vy-nutr-input">
                <input class="form-input" type="number" step="0.01" min="0" id="vy-nutr-${f.k}" value="${n[f.k] !== undefined && n[f.k] !== null ? n[f.k] : ''}" placeholder="0">
                <span class="vy-nutr-jed">${f.jed}</span>
              </span>
            </label>
          `).join('')}</div>`;
        })()}
      </div>
      <div>
        <div class="checkbox-row">
          <input type="checkbox" id="vy-akt" ${v.aktivni == 1 || !id ? 'checked' : ''}>
          <label for="vy-akt">Aktivní (zobrazit v katalogu)</label>
        </div>
      </div>
      <div>
        <div class="checkbox-row">
          <input type="checkbox" id="vy-obl" ${v.oblibeny == 1 ? 'checked' : ''}>
          <label for="vy-obl">⭐ Oblíbený výrobek</label>
        </div>
      </div>
      ${data._restaurace ? `
      <div>
        <div class="checkbox-row">
          <input type="checkbox" id="vy-pos" ${(v.zobrazit_na_pos == 1 || v.zobrazit_na_pos === undefined || v.zobrazit_na_pos === null || !id) ? 'checked' : ''}>
          <label for="vy-pos">🧾 Zobrazovat na POS (KASA)</label>
        </div>
        <div style="font-size:11.5px;color:var(--text-3);margin-top:2px;margin-left:26px">Vypnutím se výrobek skryje z pokladny. V katalogu / B2B zůstane.</div>
      </div>` : ''}
    </div>

    <!-- 🏷️ Statusové štítky — viditelné na kartě v katalogu, nezávislé na slevě -->
    <div class="card-block" style="margin-top:14px;padding:14px;background:#F8FAFC;border:1px solid #E2E8F0;border-radius:10px">
      <h3 style="margin:0 0 4px;font-size:14px;color:var(--text-2)">🏷️ Štítky pro katalog</h3>
      <p style="margin:0 0 12px;font-size:12px;color:var(--text-3)">
        Zobrazí se vlevo nahoře na kartě výrobku v online prodejně. Nezávislé na slevě (sleva se vyjede automaticky podle cenové skupiny).
      </p>
      <div class="vy-stitky-row">
        <label class="vy-stitek-toggle" data-flag="akce">
          <input type="checkbox" id="vy-akce" ${parseInt(v.je_akce || 0) === 1 ? 'checked' : ''}>
          <span class="vy-stitek-badge" style="background:linear-gradient(135deg,#34c759 0%,#1e8e3e 100%);color:white">🔥 Akce</span>
          <small>časově omezená akční nabídka</small>
        </label>
        <label class="vy-stitek-toggle" data-flag="novinka">
          <input type="checkbox" id="vy-novinka" ${parseInt(v.je_novinka || 0) === 1 ? 'checked' : ''}>
          <span class="vy-stitek-badge" style="background:linear-gradient(135deg,#ef4444 0%,#b91c1c 100%);color:white">✨ Novinka</span>
          <small>nově přidáno do sortimentu</small>
        </label>
        <label class="vy-stitek-toggle" data-flag="doprodej">
          <input type="checkbox" id="vy-doprodej" ${parseInt(v.je_doprodej || 0) === 1 ? 'checked' : ''}>
          <span class="vy-stitek-badge" style="background:linear-gradient(135deg,#f59e0b 0%,#b45309 100%);color:white">⏰ Doprodej</span>
          <small>končí, zbývá poslední série</small>
        </label>
        <label class="vy-stitek-toggle" data-flag="vyprodano">
          <input type="checkbox" id="vy-vyprodano" ${parseInt(v.je_vyprodano || 0) === 1 ? 'checked' : ''}>
          <span class="vy-stitek-badge" style="background:linear-gradient(135deg,#6b7280 0%,#374151 100%);color:white">🚫 Vyprodáno</span>
          <small>není skladem (karta se ztmaví)</small>
        </label>
      </div>
    </div>

    <!-- SLOŽENÍ / SUROVINY -->
    <div class="card-block" style="margin-top:20px;padding:14px;background:#F7F8FA;border:1px solid #E8D5B0;border-radius:10px">
      <div style="display:flex;justify-content:space-between;align-items:center;gap:10px;margin-bottom:10px;flex-wrap:wrap">
        <h3 style="margin:0;font-size:15px;color:#854F0B">🌾 Složení / suroviny</h3>
        <div style="display:flex;gap:6px;flex-wrap:wrap">
          <button class="btn-secondary" type="button" onclick="vySlozeniAddRow()" style="font-size:13px">+ Přidat surovinu</button>
          <button class="btn-secondary" type="button" onclick="vySlozeniAddPolotovar()" style="font-size:13px;border-color:#9333EA;color:#7C3AED" title="Přidat polotovar / výrobek jako složku (sestava / BOM)">+ Přidat polotovar</button>
          <button class="btn-secondary" type="button" onclick="vyOdvoditAlergeny()" style="font-size:13px" title="Sečte alergeny ze surovin a vyplní pole Alergeny výše">🧪 Odvodit alergeny</button>
          <button class="btn-secondary" type="button" onclick="vyKalkulace()" style="font-size:13px" title="Přepočítat náklady">💰 Spočítat náklady</button>
        </div>
        <!-- 🆕 v2.9.292 — Tlačítko "🎬 Demo recept" odstraněno. Demo se naplňuje AUTOMATICKY při onboardingu. -->
        <!-- (endpoint admin_demo_seed.php?action=seed_one_recipe zůstává pro programové použití) -->
      </div>

      <!-- 📏 Hmotnost těsta — auto + ruční přepočet -->
      <div style="display:flex;align-items:center;gap:10px;padding:8px 12px;background:white;border:1px dashed #E8D5B0;border-radius:8px;margin-bottom:10px;flex-wrap:wrap">
        <span style="font-size:13px;font-weight:600;color:#854F0B">📏 Hmotnost těsta</span>
        <span style="font-size:11px;color:var(--text-3)">aktuálně:</span>
        <span id="vy-testo-aktualni" style="font-size:13px;font-weight:700;color:#854F0B;font-variant-numeric:tabular-nums">— kg</span>
        <span style="flex:1"></span>
        <span style="font-size:12px;color:var(--text-2)">přepočítat na:</span>
        <input class="form-input" id="vy-testo-target" type="number" step="0.01" min="0" placeholder="např. 5" style="width:90px;text-align:right;font-size:13px;padding:4px 8px">
        <span style="color:#854F0B;font-weight:600">kg</span>
        <button class="btn-secondary" type="button" onclick="vyTestoPrepocitat()" style="font-size:12px;padding:5px 10px" title="Přenásobí všechny hmotnosti aby těsto vážilo zadané kg">↻ Přepočítat</button>
      </div>

      <div id="vy-sloz-rows"></div>
      <p style="font-size:12px;color:var(--text-3);margin-top:8px">
        Pro autocomplete a alergeny otevřete <a href="javascript:closeModal();navigate('suroviny')" style="color:#BA7517;text-decoration:underline">správu surovin</a>.
      </p>
    </div>

    <!-- KALKULACE NÁKLADŮ -->
    <div class="card-block vy-kalkulace" style="margin-top:14px;padding:14px;background:#F0F9FF;border:1px solid #93C5FD;border-radius:10px">
      <div style="display:flex;justify-content:space-between;align-items:center;gap:10px;margin-bottom:10px;flex-wrap:wrap">
        <h3 style="margin:0;font-size:15px;color:#1E40AF">💰 Kalkulace nákladů</h3>
        <button class="btn-secondary" type="button" onclick="vyKalkulace()" style="font-size:12px;padding:4px 10px">🔄 Přepočítat</button>
      </div>
      <div id="vy-kalkulace-out">
        <p style="color:var(--text-3);font-size:13px;margin:0">Klepni na 🔄 Přepočítat — sečte ceny surovin podle množství a zobrazí marži.</p>
      </div>
    </div>

    <div class="form-actions">
      ${id ? adminOnly(`<div class="form-actions-icons-row"><button class="btn-danger-corner" onclick="smazatVyrobek(${id})" title="Smazat výrobek" aria-label="Smazat výrobek">🗑️</button></div>`) : ''}
      <div style="flex:1"></div>
      ${id ? `
        <div class="haccp-pill" style="display:inline-flex;border:1px solid var(--border);border-radius:8px;overflow:hidden;background:var(--surface-1);margin-right:18px">
          <a href="../api/vyrobek_haccp.php?id=${id}" target="_blank" title="Otevřít HACCP list výrobku v novém okně" style="text-decoration:none;display:inline-flex;align-items:center;gap:6px;padding:8px 14px;font-size:13px;font-weight:500;color:var(--text-1);border-right:1px solid var(--border);transition:background 0.15s" onmouseover="this.style.background='var(--surface-2)'" onmouseout="this.style.background='transparent'">📋 HACCP list <span style="font-size:11px;opacity:0.6;margin-left:2px">↗</span></a>
          <button onclick="closeModal();setTimeout(()=>otevritHaccpEditor(${id}, {returnTo:'vyrobek'}), 80)" title="Upravit HACCP data tohoto výrobku" style="display:inline-flex;align-items:center;gap:6px;padding:8px 14px;font-size:13px;font-weight:500;color:var(--text-1);background:transparent;border:0;cursor:pointer;transition:background 0.15s" onmouseover="this.style.background='var(--surface-2)'" onmouseout="this.style.background='transparent'">📝 Upravit HACCP</button>
        </div>
      ` : ''}
      <button class="btn-secondary" onclick="closeModal()">Zrušit</button>
      <button class="btn-primary" onclick="ulozitVyrobek(${id || 'null'})">Uložit</button>
    </div>
  `, 'wide');

  // Po renderu doplň existující řádky složení a spusť přepočet ceny/kg
  // 🆕 v3.0.303 — cache výrobků pro polotovar-picker + id editovaného výrobku (anti-cyklus self)
  state._vyEditId = id || 0;
  if (!state._vyrobky_pol_cache) {
    api('admin_vyrobky.php').then(r => {
      state._vyrobky_pol_cache = (r.vyrobky || []).map(x => ({ id: x.id, nazev: x.nazev, je_polotovar: x.je_polotovar }));
    }).catch(() => { state._vyrobky_pol_cache = []; });
  }
  if (Array.isArray(v.slozeni)) {
    v.slozeni.forEach(p => {
      if (p.slozka_vyrobek_id) vySlozeniAddPolotovar(p.slozka_vyrobek_id, p.mnozstvi, p.jednotka || 'ks', p.slozka_nazev || '');
      else vySlozeniAddRow(p.surovina_id, p.mnozstvi, p.jednotka || 'g', p.poznamka || '');
    });
  }
  // Live přepočet — reaguj i na změnu ceny a DPH
  setTimeout(() => {
    if (typeof vyPrepocet === 'function') vyPrepocet();
    if (typeof vyUpdateCenaSDph === 'function') vyUpdateCenaSDph();
    if (typeof vyOborChange === 'function') vyOborChange();   // 🆕 v3.0.295 — hint dle oboru
  }, 30);
};

// Pomocné: aktuální sazba DPH ve formuláři
function vyAktualniSazbaDPH() {
  const sel = document.getElementById('vy-dph');
  if (!sel) return 12;
  return parseFloat(sel.options[sel.selectedIndex]?.dataset.sazba) || 12;
}

// Cena bez DPH se změnila → přepočti cenu s DPH (a ceny/kg)
window.vyUpdateCenaSDph = function() {
  const inpBez = document.getElementById('vy-cena');
  const inpS   = document.getElementById('vy-cena-sdph');
  if (!inpBez || !inpS) return;
  // Pokud focus má vy-cena-sdph (právě upravuje uživatel), neměň ho
  if (document.activeElement === inpS) return;
  const cena  = parseFloat(inpBez.value) || 0;
  const sazba = vyAktualniSazbaDPH();
  const sDph  = cena * (1 + sazba / 100);
  inpS.value = cena > 0 ? sDph.toFixed(2) : '';
  if (typeof vyPrepocet === 'function') vyPrepocet();
};

// Cena s DPH se změnila → přepočti cenu bez DPH (a ceny/kg)
window.vyUpdateCenaBezDph = function() {
  const inpBez = document.getElementById('vy-cena');
  const inpS   = document.getElementById('vy-cena-sdph');
  if (!inpBez || !inpS) return;
  if (document.activeElement === inpBez) return;
  const sDph  = parseFloat(inpS.value) || 0;
  const sazba = vyAktualniSazbaDPH();
  const cena  = sazba ? sDph / (1 + sazba / 100) : sDph;
  inpBez.value = sDph > 0 ? cena.toFixed(2) : '';
  if (typeof vyPrepocet === 'function') vyPrepocet();
};

window.vyClearNutr = function() {
  ['energie_kj','energie_kcal','tuky','tuky_nasycene','sacharidy','cukry','bilkoviny','sul'].forEach(k => {
    const el = document.getElementById('vy-nutr-' + k);
    if (el) el.value = '';
  });
};

// Spočítá nutriční hodnoty na 100 g výrobku z receptury (vážený průměr dle hmotnosti)
window.vyNutriZeSurovin = function() {
  const rows = document.querySelectorAll('#vy-sloz-rows .sloz-row');
  const sur = state._suroviny_cache || [];
  const indexById = Object.fromEntries(sur.map(s => [s.id, s]));

  // Převod na g (pro vážení): kg→g, ml→g (předpoklad ρ≈1, vodu/olej už máme správně), l→ml→g, ks=neignorováno
  const toGrams = (mn, jed) => {
    const m = parseFloat(mn) || 0;
    const j = (jed || 'g').toLowerCase();
    if (j === 'kg') return m * 1000;
    if (j === 'g')  return m;
    if (j === 'l')  return m * 1000;
    if (j === 'ml') return m;
    if (j === 'ks') return 0; // ks nejde — nutri je per 100g, ks vyžaduje hmotnost ks
    return m;
  };

  const nKeys = [
    ['energie_kj',     'nutri_energie_kj'],
    ['energie_kcal',   'nutri_energie_kcal'],
    ['tuky',           'nutri_tuky'],
    ['tuky_nasycene',  'nutri_tuky_nasycene'],
    ['sacharidy',      'nutri_sacharidy'],
    ['cukry',          'nutri_cukry'],
    ['bilkoviny',      'nutri_bilkoviny'],
    ['sul',            'nutri_sul'],
  ];

  let totalG = 0;
  const sums = {}; nKeys.forEach(([k]) => sums[k] = 0);
  let surBezNutri = [];
  let surKs = [];

  rows.forEach(r => {
    const sid = parseInt(r.querySelector('.sloz-sur').value) || 0;
    const mn  = parseFloat(r.querySelector('.sloz-mn').value) || 0;
    const jed = r.querySelector('.sloz-jed')?.value || 'g';
    if (!sid || mn <= 0) return;
    const s = indexById[sid];
    if (!s) return;

    const g = toGrams(mn, jed);
    if (jed === 'ks') { surKs.push(s.nazev); return; }
    if (g <= 0) return;

    const maNutri = nKeys.some(([_, sk]) => s[sk] != null && s[sk] !== '');
    if (!maNutri) { surBezNutri.push(s.nazev); return; }

    totalG += g;
    nKeys.forEach(([k, sk]) => {
      const v = parseFloat(s[sk]) || 0;
      sums[k] += v * g / 100; // surovina nutri je per 100g → násobíme g/100
    });
  });

  if (totalG === 0) {
    alert('Žádné suroviny v receptuře s vyplněnými nutričními hodnotami.\n\nDoplň nutri u jednotlivých surovin (Suroviny → otevři surovinu → 🍎 Nutriční hodnoty).');
    return;
  }

  // Přepočet na 100 g
  nKeys.forEach(([k]) => {
    const per100 = sums[k] * 100 / totalG;
    const el = document.getElementById('vy-nutr-' + k);
    if (el) el.value = per100.toFixed(k === 'sul' ? 3 : (k.startsWith('energie') ? 0 : 2));
  });

  let msg = `✓ Nutriční hodnoty spočítány z ${totalG.toFixed(0)} g surovin`;
  if (surBezNutri.length > 0) msg += `\n\n⚠ Bez nutri (přeskočeno): ${surBezNutri.join(', ')}`;
  if (surKs.length > 0)       msg += `\n\n⚠ V kusech (nelze přepočítat): ${surKs.join(', ')}`;

  const t = document.createElement('div');
  t.style.cssText = 'position:fixed;bottom:24px;right:24px;background:var(--success-bg);color:var(--success-text);padding:14px 22px;border-radius:8px;box-shadow:0 4px 12px rgba(0,0,0,.15);font-size:13px;font-weight:500;z-index:9999;max-width:340px;white-space:pre-line';
  t.textContent = msg;
  document.body.appendChild(t);
  setTimeout(() => t.remove(), surBezNutri.length || surKs.length ? 6000 : 2500);
};

window.vyOdvoditAlergeny = async function(silent) {
  const rows = document.querySelectorAll('#vy-sloz-rows .sloz-row');
  const sur = state._suroviny_cache || [];
  const indexById = Object.fromEntries(sur.map(s => [s.id, s]));
  const set = new Set();
  rows.forEach(r => {
    const sid = parseInt(r.querySelector('.sloz-sur').value) || 0;
    if (!sid) return;
    const s = indexById[sid];
    if (!s) return;
    // Vlastní alergen suroviny
    if (s.alergen) {
      s.alergen.split(',').forEach(a => { const x = a.trim(); if (x) set.add(x); });
    }
    // Detekované alergeny ze složení suroviny (kompozitní suroviny — Diasauer, směsi)
    if (s.slozeni_alergeny) {
      s.slozeni_alergeny.split(',').forEach(a => { const x = a.trim(); if (x) set.add(x); });
    }
  });
  const list = Array.from(set).sort().join(', ');
  const aler = document.getElementById('vy-aler');
  if (!aler) return;
  const stary = aler.value.trim();
  if (!stary || silent) {
    aler.value = list;
  } else if (stary !== list) {
    if ((await confirmDialog({ msg: t('confirm_overwrite_allergens', { list: list || '(žádné)', old: stary }), danger: false }))) {
      aler.value = list;
    }
  }
  if (!silent) {
    const t = document.createElement('div');
    t.style.cssText = 'position:fixed;bottom:24px;right:24px;background:var(--success-bg);color:var(--success-text);padding:12px 18px;border-radius:8px;box-shadow:0 4px 12px rgba(0,0,0,.15);font-size:13px;font-weight:600;z-index:9999';
    t.textContent = list ? `✓ Alergeny ze surovin: ${list}` : 'Žádné alergeny v surovinách';
    document.body.appendChild(t);
    setTimeout(() => t.remove(), 2500);
  }
};

