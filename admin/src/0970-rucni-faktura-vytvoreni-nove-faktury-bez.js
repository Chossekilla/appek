// =============================================================
// RUČNÍ FAKTURA - vytvoření nové faktury bez vazby na objednávku
// =============================================================
window.rucniFakturaState = {
  odberatel_id: null,
  misto_dodani_id: null,
  cenik: [],     // ceník pro odběratele s aplikovanými slevami
  polozky: [],   // řádky faktury
  pobocky: [],
  odberatel: null,
  vsechny_odberatele: [],
  posledni_objednavky: [],   // posledních 5 objednávek pro rychlé kopírování
};

window.otevritRucniFakturu = async function() {
  // Reset stavu
  rucniFakturaState = {
    odberatel_id: null, misto_dodani_id: null,
    cenik: [], polozky: [], pobocky: [], odberatel: null,
    vsechny_odberatele: [], posledni_objednavky: [],
    datum_splatnosti: null,
  };

  try {
    // 🆕 v3.0.237 — načti i nefakturované objednávky (bez DL) + nefakturované DL pro picker
    const [odb, objRes, dlRes] = await Promise.all([
      api('admin_odberatele.php'),
      api('admin_objednavky.php?limit=300').catch(() => ([])),
      api('admin_dodaci_listy.php?fakturovano=0&limit=300').catch(() => ([])),
    ]);
    rucniFakturaState.vsechny_odberatele = odb;
    const objArr = Array.isArray(objRes) ? objRes : (objRes.objednavky || []);
    // Objednávky bez dodacího listu a nezrušené → vyfakturovat = vytvoří DL+FA
    rucniFakturaState.nefakt_obj = objArr.filter(o => (parseInt(o.pocet_dl) || 0) === 0 && o.stav !== 'zrusena');
    const dlArr = Array.isArray(dlRes) ? dlRes : (dlRes.dodaci_listy || []);
    rucniFakturaState.nefakt_dl = dlArr.filter(d => !parseInt(d.fakturovano));
  } catch (e) { alert('Nepodařilo se načíst data: ' + e.message); return; }

  vykreslitRucniFakturu();
};

// 🆕 v3.0.237 — vystavit fakturu z vybrané položky pickeru (objednávka NEBO DL)
window.rfVystavitZVyberu = function(sel) {
  if (!sel || !sel.value) return;
  const [typ, id] = sel.value.split(':');
  if (typ === 'obj') vytvoritFakturu(parseInt(id));
  else if (typ === 'dl') vytvoritFakturuZDL(parseInt(id), null);
};

function vykreslitRucniFakturu() {
  const s = rucniFakturaState;
  const today = new Date().toISOString().split('T')[0];
  const _zitra = new Date(); _zitra.setDate(_zitra.getDate() + 1);
  const tomorrow = _zitra.toISOString().split('T')[0];

  // Sazby DPH
  const sazbyOptions = `
    <option value="12">12%</option>
    <option value="21">21%</option>
    <option value="0">0%</option>
  `;

  const odberatelSelected = s.odberatel_id;

  const polozkyHtml = s.polozky.length === 0
    ? `<div class="empty-state" style="padding:20px;background:var(--surface-2);border-radius:8px">Zatím žádné položky. Přidejte je tlačítkem dole.</div>`
    : s.polozky.map((p, i) => `
        <div class="obj-polozka-row obj-polozka-edit">
          <div class="obj-polozka-img"><div class="obj-polozka-img-empty">🥖</div></div>
          <div class="obj-polozka-info">
            <input class="form-input obj-polozka-name-input" type="text" value="${esc(p.vyrobek_nazev || '')}"
                   placeholder="Název položky"
                   oninput="rfUpdate(${i}, 'vyrobek_nazev', this.value)">
            <div class="obj-polozka-zdroj">${p.je_z_katalogu ? '<span style="color:var(--text-3)">Z katalogu: ' + esc(p.vyrobek_cislo || '') + '</span>' : '<span style="color:#BA7517">✏️ Volný řádek</span>'}</div>
            <div class="obj-polozka-edit-row">
              <input class="form-input obj-polozka-jed" type="text" value="${esc(p.jednotka || 'ks')}" maxlength="10"
                     placeholder="ks"
                     oninput="rfUpdate(${i}, 'jednotka', this.value)">
              <input class="form-input obj-polozka-cena-input" type="number" min="0" step="0.01" value="${parseFloat(p.cena_bez_dph) || 0}"
                     placeholder="cena"
                     oninput="rfUpdate(${i}, 'cena_bez_dph', parseFloat(this.value) || 0)">
              <select class="form-input obj-polozka-dph"
                      onchange="rfUpdate(${i}, 'sazba_dph', parseFloat(this.value))">
                <option value="12" ${p.sazba_dph == 12 ? 'selected' : ''}>12%</option>
                <option value="21" ${p.sazba_dph == 21 ? 'selected' : ''}>21%</option>
                <option value="0"  ${p.sazba_dph == 0  ? 'selected' : ''}>0%</option>
              </select>
            </div>
          </div>
          <div class="obj-polozka-qty">
            <button type="button" class="qty-btn" onclick="rfUpdate(${i}, 'mnozstvi', Math.max(0, (parseFloat(this.parentElement.querySelector('input').value)||0) - 1))">−</button>
            <input type="number" min="0" step="1" class="form-input qty-input" value="${parseFloat(p.mnozstvi) || 0}"
                   oninput="rfUpdate(${i}, 'mnozstvi', parseFloat(this.value) || 0)">
            <button type="button" class="qty-btn" onclick="rfUpdate(${i}, 'mnozstvi', (parseFloat(this.parentElement.querySelector('input').value)||0) + 1)">+</button>
          </div>
          <div class="obj-polozka-cena">${fmt(p.cena_bez_dph * p.mnozstvi * (1 + p.sazba_dph / 100))}</div>
          <button class="obj-polozka-del" onclick="rfRemove(${i})" title="Smazat">×</button>
        </div>
      `).join('');

  // Souhrn
  let bez = 0, dph = 0;
  s.polozky.forEach(p => {
    const b = p.cena_bez_dph * p.mnozstvi;
    bez += b;
    dph += b * (p.sazba_dph / 100);
  });

  // Pobočky pro vybraného odběratele
  const pobockyOptions = s.pobocky.length === 0 ? '<option value="">— bez pobočky —</option>' :
    `<option value="">— bez pobočky —</option>` +
    s.pobocky.map((m) => `<option value="${m.id}" ${s.misto_dodani_id == m.id ? 'selected' : ''}>
      ${esc(m.nazev)}${m.ulice ? ' – ' + esc(m.ulice) : ''}${m.mesto ? ', ' + esc(m.mesto) : ''}
    </option>`).join('');

  // Katalog výrobků pro datalist (autocomplete)
  const katalogOptions = s.cenik.map((v) => {
    const slevaInfo = v.pevna_cena !== null
      ? ` [pevná ${v.cena_bez_dph} Kč]`
      : (v.sleva_pct !== null ? ` [-${parseFloat(v.sleva_pct).toFixed(0)}%]` : '');
    return `<option value="${v.id}">${esc(v.nazev)}${slevaInfo} — ${v.cena_bez_dph} Kč</option>`;
  }).join('');

  const skupinaInfo = s.odberatel?.skupina_nazev
    ? `<span style="background:#FAEEDA;color:#854F0B;padding:3px 8px;border-radius:6px;font-size:11px;font-weight:600">💰 ${esc(s.odberatel.skupina_nazev)}</span>`
    : '';

  // 🆕 v3.0.237 — picker: vystavit fakturu z nefakturované objednávky (bez DL) nebo z DL
  const _nObj = s.nefakt_obj || [];
  const _nDl  = s.nefakt_dl  || [];
  const _pickerHtml = (_nObj.length || _nDl.length) ? `
    <div style="background:#EFF6FF;border:1px solid #B5D4F4;border-radius:10px;padding:14px 16px;margin-bottom:16px">
      <div style="font-size:13px;font-weight:700;color:#0C447C;margin-bottom:8px">📋 Vystavit z existující objednávky / dodacího listu <span style="font-weight:400;color:#5b7a9c">(volitelné)</span></div>
      <select class="form-select" onchange="rfVystavitZVyberu(this)" style="width:100%">
        <option value="">— vyber nefakturovanou objednávku / DL —</option>
        ${_nObj.length ? `<optgroup label="📦 Objednávky bez dodacího listu (${_nObj.length})">${_nObj.map(o => `<option value="obj:${o.id}">${esc(o.cislo || ('#' + o.id))} · ${esc(o.odberatel_nazev || o.odberatel || '—')} · ${fmt(parseFloat(o.castka_celkem) || 0)}</option>`).join('')}</optgroup>` : ''}
        ${_nDl.length ? `<optgroup label="📃 Nefakturované dodací listy (${_nDl.length})">${_nDl.map(d => `<option value="dl:${d.id}">${esc(d.cislo || ('#' + d.id))} · ${esc(d.odberatel_nazev || d.odberatel || '—')} · ${fmt(parseFloat(d.castka_celkem) || 0)}</option>`).join('')}</optgroup>` : ''}
      </select>
      <div style="font-size:11px;color:#5b7a9c;margin-top:6px;line-height:1.5">Objednávka, DL i faktura mají <strong>vlastní číselné řady</strong> — nekolidují. Ne každá objednávka musí mít DL/fakturu a naopak. Nebo vyplň fakturu ručně níže.</div>
    </div>` : '';

  openModal('+ Nová faktura', `
    ${_pickerHtml}
    <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;margin-bottom:16px">
      <div style="background:#F7F8FA;border:1px solid #E8D5B0;border-radius:8px;padding:14px">
        <div style="font-size:11px;text-transform:uppercase;letter-spacing:0.5px;color:#854F0B;margin-bottom:6px;font-weight:600">\ud83c\udfe2 Odběratel</div>
        ${odberatelSelected && s.odberatel ? (() => {
          const o = typeof s.odberatel === 'object' ? s.odberatel : null;
          return o ? `
            <div class="modal-party-name">${esc(o.nazev || '')}</div>
            <div style="font-size:13px;color:#854F0B">${o.ico ? 'I\u010cO: ' + esc(o.ico) : ''}${o.dic ? ' \u00b7 DI\u010c: ' + esc(o.dic) : ''}</div>
            ${o.ulice ? '<div style="font-size:13px;color:#854F0B;margin-top:2px">' + esc(o.ulice) + (o.mesto ? ', ' + esc(o.mesto) : '') + '</div>' : ''}
          ` : `<div class="modal-party-name">${esc(String(s.odberatel))}</div>`;
        })() : ''}
        <div class="vp-picker-wrap" style="margin-top:8px;position:relative">
          <input class="form-input vp-picker-input" id="rf-odberatel-search" type="text"
                 placeholder="\ud83d\udd0d Hledat odb\u011bratele (n\u00e1zev / I\u010cO)\u2026"
                 value="${odberatelSelected && s.odberatel ? esc(s.odberatel.nazev || '') : ''}"
                 style="width:100%;font-size:16px;padding:12px 16px;height:50px;font-weight:500"
                 autocomplete="off"
                 oninput="vpOdbFilter('rf', this.value)"
                 onfocus="vpOdbFilter('rf', this.value)"
                 onkeydown="vpOdbKey(event, 'rf')">
          <input type="hidden" id="rf-odberatel" value="${s.odberatel_id || ''}">
          <div class="vp-picker-dropdown" id="rf-odb-dropdown" style="display:none"></div>
        </div>
      </div>
      <div style="background:#EFF6FF;border:1px solid #B5D4F4;border-radius:8px;padding:14px">
        <div style="font-size:11px;text-transform:uppercase;letter-spacing:0.5px;color:#0C447C;margin-bottom:6px;font-weight:600">\ud83d\udccd Místo dodání</div>
        ${odberatelSelected && s.pobocky.length === 0 ? `<div style="font-size:13px;color:#0C447C">Tento odběratel zatím nemá žádnou pobočku.</div><button type="button" class="btn-secondary" onclick="editPobocka(${s.odberatel_id}, null, 'novaFaktura')" style="margin-top:8px;width:100%;font-size:13px;padding:7px 12px">➕ Vytvořit pobočku</button>` : odberatelSelected && s.pobocky.length > 0 ? (() => {
          const m = s.pobocky.find(p => p.id == s.misto_dodani_id);
          return m ? `
            <div class="modal-party-name">${esc(m.nazev || '')}</div>
            ${m.ulice ? '<div style="font-size:13px;color:#0C447C">' + esc(m.ulice) + '</div>' : ''}
            ${m.mesto ? '<div style="font-size:13px;color:#0C447C">' + (m.psc ? esc(m.psc) + ', ' : '') + esc(m.mesto) + '</div>' : ''}
          ` : '<div style="font-size:13px;color:#0C447C">Vyberte pobo\u010dku</div>';
        })() : '<div style="font-size:13px;color:#0C447C">Nejd\u0159\u00edv vyberte odběratele</div>'}
        ${s.pobocky.length > 0 ? `<select class="input" id="rf-pobocka" onchange="rucniFakturaState.misto_dodani_id = parseInt(this.value) || null; vykreslitRucniFakturu()" style="margin-top:8px;width:100%">${pobockyOptions}</select>` : ''}
      </div>
    </div>
    <div class="doc-meta-row">
      <div class="doc-meta-field">
        <div class="doc-meta-label"><span class="doc-meta-icon">📅</span> Datum vystavení <span class="doc-meta-req">*</span></div>
        <input class="doc-meta-input" id="rf-datum-vyst" type="date" value="${today}" oninput="rfPrepocitatSplatnost()">
      </div>
      <div class="doc-meta-field">
        <div class="doc-meta-label">
          <span class="doc-meta-icon">⏳</span> Datum splatnosti
          ${s.odberatel?.splatnost_dni ? `<span class="doc-meta-hint">(${s.odberatel.splatnost_dni} dní)</span>` : ''}
        </div>
        <input class="doc-meta-input" id="rf-datum-spl" type="date" value="${esc(s.datum_splatnosti || '')}" oninput="rucniFakturaState.datum_splatnosti = this.value">
      </div>
      <div class="doc-meta-field">
        <div class="doc-meta-label"><span class="doc-meta-icon">📝</span> Poznámka</div>
        <input class="doc-meta-input" id="rf-poznamka" placeholder="Volitelná…">
      </div>
    </div>

    <h3 style="margin:0 0 8px;font-size:15px">Polo\u017eky</h3>

    ${odberatelSelected ? '' : `
      <div style="background:#E6F1FB;color:#0C447C;padding:12px 16px;border-radius:8px;margin-bottom:12px;font-size:13px">
        \u2139\ufe0f Nejd\u0159\u00edv vyberte odběratele.
      </div>
    `}

    ${odberatelSelected ? `
      <div class="rf-history" style="background:#F7F8FA;border:1px solid var(--border);border-radius:8px;padding:12px 16px;margin-bottom:14px">
        <div style="font-size:13px;font-weight:600;color:var(--text-2);margin-bottom:8px">
          📋 Načíst z dřívější objednávky: ${s.posledni_objednavky.length === 0 ? '<span style="font-weight:400;color:var(--text-3);font-style:italic">— odběratel zatím nemá žádné předchozí objednávky</span>' : ''}
        </div>
        ${s.posledni_objednavky.length > 0 ? `
          <div style="display:grid;grid-template-columns:repeat(3,1fr);gap:10px">
            ${s.posledni_objednavky.map((o) => {
              const pol = (o.polozky || []).slice(0, 6);
              const zbyva = (o.polozky || []).length - pol.length;
              return `
                <div onclick="rfNacistZObjednavky(${o.id})" style="background:#F7F8FA;border:1px solid #E8D5B0;border-radius:10px;padding:12px;cursor:pointer">
                  <div style="display:flex;justify-content:space-between;align-items:baseline;margin-bottom:4px">
                    <strong style="font-size:13px">${esc(o.cislo)}</strong>
                    <span style="font-weight:700;color:#854F0B;font-size:14px">${fmt(o.castka_celkem)}</span>
                  </div>
                  <div style="font-size:11px;color:#999;margin-bottom:6px">📅 ${fmtDate(o.datum_dodani)} · ${o.pocet_polozek} pol.</div>
                  ${pol.length > 0 ? `
                    <ul style="list-style:none;margin:0;padding:0;font-size:11px;color:#666;display:grid;grid-template-columns:1fr 1fr;gap:1px 8px">
                      ${pol.map(p => '<li style="white-space:nowrap;overflow:hidden;text-overflow:ellipsis"><span style="font-weight:700;color:#854F0B;min-width:20px;display:inline-block">' + parseFloat(p.mnozstvi) + '×</span> ' + esc(p.vyrobek_nazev || '') + '</li>').join('')}
                      ${zbyva > 0 ? '<li style="color:#999;font-style:italic;grid-column:1/-1">+ dalších ' + zbyva + '</li>' : ''}
                    </ul>
                  ` : ''}
                  <div style="margin-top:8px;background:#BA7517;color:white;border-radius:6px;padding:6px 0;text-align:center;font-size:12px;font-weight:600">📥 Načíst položky</div>
                </div>
              `;
            }).join('')}
          </div>
        ` : ''}
      </div>
    ` : ''}

    <div class="obj-polozky-list" style="margin-bottom:12px">${polozkyHtml}</div>

    ${odberatelSelected ? `
      <div class="vp-picker-wrap" style="margin-bottom:16px;position:relative">
        <div style="display:flex;gap:8px;flex-wrap:wrap;align-items:stretch">
          <input class="form-input vp-picker-input" id="rf-pridat-vyrobek" type="text"
                 placeholder="🔍 Hledat výrobek (název / kód)…"
                 style="flex:1;min-width:200px;font-size:15px;padding:10px 14px"
                 autocomplete="off"
                 oninput="vpFilter('rf', this.value)"
                 onfocus="vpFilter('rf', this.value)"
                 onkeydown="vpKey(event, 'rf')">
          <input type="hidden" id="rf-pridat-vyrobek-id" value="">
          <button class="btn-secondary" onclick="rfPridatZKatalogu()">+ Přidat z katalogu</button>
          <button class="btn-secondary" onclick="rfPridatVolny()">+ Volný řádek</button>
        </div>
        <div class="vp-picker-dropdown" id="rf-picker-dropdown" style="display:none"></div>
      </div>
    ` : ''}

    <div style="background:#FAEEDA;border-radius:8px;padding:14px 18px;margin-bottom:16px">
      <div style="display:flex;justify-content:space-between;font-size:13px;color:#855;margin-bottom:4px">
        <span>Bez DPH</span><span>${fmt(bez)}</span>
      </div>
      <div style="display:flex;justify-content:space-between;font-size:13px;color:#855;margin-bottom:8px">
        <span>DPH</span><span>${fmt(dph)}</span>
      </div>
      <div style="display:flex;justify-content:space-between;font-size:18px;font-weight:700;color:#854F0B;border-top:1px solid #E5C499;padding-top:8px">
        <span>Celkem</span><span>${fmt(bez + dph)}</span>
      </div>
    </div>

    <div class="form-actions">
      <button class="btn-secondary" onclick="closeModal()">Zrušit</button>
      <div style="flex:1"></div>
      <button class="btn-primary btn-green" onclick="rfVystavit()" ${s.polozky.length === 0 || !s.odberatel_id ? 'disabled' : ''}>
        💰 Vystavit fakturu
      </button>
    </div>
  `, 'wide');
}

// Když uživatel změní datum vystavení, přepočítej splatnost (podle splatnost_dni odběratele)
window.rfPrepocitatSplatnost = function() {
  const s = rucniFakturaState;
  const splDni = parseInt(s.odberatel?.splatnost_dni) || 0;
  if (!splDni) return;
  const dv = document.getElementById('rf-datum-vyst');
  if (!dv || !dv.value) return;
  const d = new Date(dv.value);
  if (isNaN(d)) return;
  d.setDate(d.getDate() + splDni);
  const novy = d.toISOString().slice(0, 10);
  s.datum_splatnosti = novy;
  const sp = document.getElementById('rf-datum-spl');
  if (sp) sp.value = novy;
};

window.rfVybratOdberatele = async function(id) {
  if (!id) {
    rucniFakturaState.odberatel_id = null;
    rucniFakturaState.cenik = [];
    rucniFakturaState.pobocky = [];
    rucniFakturaState.odberatel = null;
    rucniFakturaState.posledni_objednavky = [];
    return vykreslitRucniFakturu();
  }
  rucniFakturaState.odberatel_id = parseInt(id);
  try {
    const data = await api(`cenik_odberatele.php?odberatel_id=${id}`);
    rucniFakturaState.cenik = data.vyrobky;
    rucniFakturaState.odberatel = data;
    // Auto-spočítej datum splatnosti podle splatnost_dni odběratele
    // (z aktuální hodnoty datum_vystaveni v inputu, fallback dnes)
    const splDni = parseInt(data.splatnost_dni) || 14;
    const dvInput = document.getElementById('rf-datum-vyst');
    const dvStr = (dvInput && dvInput.value) ? dvInput.value : new Date().toISOString().slice(0, 10);
    const dv = new Date(dvStr);
    if (!isNaN(dv)) {
      dv.setDate(dv.getDate() + splDni);
      rucniFakturaState.datum_splatnosti = dv.toISOString().slice(0, 10);
    }
    // Načti pobočky
    rucniFakturaState.pobocky = await api(`admin_pobocky.php?odberatel_id=${id}`);
    // Vychozí pobočku přednastav
    const vychozi = rucniFakturaState.pobocky.find(m => m.vychozi == 1) || rucniFakturaState.pobocky[0];
    if (vychozi) rucniFakturaState.misto_dodani_id = vychozi.id;
    // Posledních 5 objednávek odběratele - pro rychlé kopírování položek
    try {
      rucniFakturaState.posledni_objednavky = await api(
        `admin_objednavky.php?action=posledni&odberatel_id=${id}&limit=5`
      );
    } catch (e) {
      console.warn('Nepodařilo se načíst poslední objednávky:', e);
      rucniFakturaState.posledni_objednavky = [];
    }
    vykreslitRucniFakturu();
  } catch (e) { alert('Chyba: ' + e.message); }
};

window.rfPridatZKatalogu = function() {
  let vid = parseInt(document.getElementById('rf-pridat-vyrobek-id')?.value || 0);
  if (!vid) {
    // Fallback — pokud uživatel jen napsal text bez výběru, pokus se přesně matchnout
    const text = (document.getElementById('rf-pridat-vyrobek')?.value || '').trim().toLowerCase();
    if (text) {
      const m = rucniFakturaState.cenik.find(x => (x.nazev || '').toLowerCase() === text);
      if (m) vid = m.id;
    }
  }
  if (!vid) return alert('Vyberte výrobek z nabídky');
  const v = rucniFakturaState.cenik.find(x => x.id == vid);
  if (!v) return;
  rucniFakturaState.polozky.push({
    vyrobek_id: v.id,
    vyrobek_cislo: v.cislo,
    vyrobek_nazev: v.nazev,
    jednotka: v.jednotka || 'ks',
    mnozstvi: 1,
    cena_bez_dph: parseFloat(v.cena_bez_dph),
    sazba_dph: parseFloat(v.dph || 12),
    je_z_katalogu: true,
  });
  vykreslitRucniFakturu();
};

// =============================================================
// 🔍 VYROBEK PICKER — sdílený searchable dropdown pro FA / DL / OBJ
// Použití: input s oninput="vpFilter('rf', this.value)" + hidden #{rf}-pridat-vyrobek-id + .vp-picker-dropdown
// =============================================================
function _vpState() {
  if (!window._vpState) window._vpState = { highlight: 0 };
  return window._vpState;
}
function _vpGetCenik(prefix) {
  if (prefix === 'rf')  return window.rucniFakturaState?.cenik || [];
  if (prefix === 'rdl') return window.rdlState?.cenik || [];
  if (prefix === 'no')  return window.noState?.cenik || [];
  return [];
}
window.vpFilter = function(prefix, query) {
  const dd = document.getElementById(`${prefix}-picker-dropdown`);
  if (!dd) return;
  const q = (query || '').toLowerCase().trim();
  const cenik = _vpGetCenik(prefix);
  let items = cenik;
  if (q) {
    items = cenik.filter(v =>
      (v.nazev || '').toLowerCase().includes(q) ||
      (v.cislo || '').toLowerCase().includes(q)
    );
  }
  // Limit pro výkon — max 25 položek
  const top = items.slice(0, 25);
  _vpState().highlight = 0;
  _vpState().items = top;
  _vpState().prefix = prefix;

  if (top.length === 0) {
    dd.innerHTML = `<div class="vp-picker-empty">Žádný výrobek neodpovídá „${esc(q)}"</div>`;
  } else {
    dd.innerHTML = top.map((v, i) => `
      <div class="vp-picker-item ${i === 0 ? 'is-active' : ''}" data-idx="${i}"
           onmousedown="vpPick('${prefix}', ${v.id})" onmouseenter="vpHighlight(${i})">
        <div class="vp-picker-name">${esc(v.nazev)}${v.cislo ? `<span class="vp-picker-code"> · ${esc(v.cislo)}</span>` : ''}</div>
        <div class="vp-picker-price">${fmt(parseFloat(v.cena_bez_dph || 0))}<span class="vp-picker-jed">/${esc(v.jednotka || 'ks')}</span></div>
      </div>
    `).join('') + (items.length > 25 ? `<div class="vp-picker-more">Zobrazeno prvních 25 z ${items.length} — upřesněte vyhledávání…</div>` : '');
  }
  dd.style.display = 'block';
};
window.vpPick = function(prefix, id) {
  const v = _vpGetCenik(prefix).find(x => x.id == id);
  if (!v) return;
  document.getElementById(`${prefix}-pridat-vyrobek`).value = v.nazev + (v.cislo ? ' · ' + v.cislo : '');
  document.getElementById(`${prefix}-pridat-vyrobek-id`).value = v.id;
  document.getElementById(`${prefix}-picker-dropdown`).style.display = 'none';
};
window.vpHighlight = function(idx) {
  _vpState().highlight = idx;
  document.querySelectorAll('.vp-picker-item').forEach((el, i) => {
    el.classList.toggle('is-active', i === idx);
  });
};
window.vpKey = function(ev, prefix) {
  const st = _vpState();
  const items = st.items || [];
  if (ev.key === 'ArrowDown') {
    ev.preventDefault();
    st.highlight = Math.min(items.length - 1, st.highlight + 1);
    vpHighlight(st.highlight);
  } else if (ev.key === 'ArrowUp') {
    ev.preventDefault();
    st.highlight = Math.max(0, st.highlight - 1);
    vpHighlight(st.highlight);
  } else if (ev.key === 'Enter') {
    ev.preventDefault();
    const v = items[st.highlight];
    if (v) vpPick(prefix, v.id);
  } else if (ev.key === 'Escape') {
    document.getElementById(`${prefix}-picker-dropdown`).style.display = 'none';
  }
};
// Klik mimo dropdown ho zavře
document.addEventListener('click', (e) => {
  if (!e.target.closest('.vp-picker-wrap')) {
    document.querySelectorAll('.vp-picker-dropdown').forEach(dd => dd.style.display = 'none');
  }
});

