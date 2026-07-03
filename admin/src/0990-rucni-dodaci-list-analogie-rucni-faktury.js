// =============================================================
// RUČNÍ DODACÍ LIST (analogie ruční faktury, jen bez splatnosti/VS)
// =============================================================
window.rdlState = {
  odberatel_id: null,
  misto_dodani_id: null,
  cenik: [],
  polozky: [],
  pobocky: [],
  odberatel: null,
  vsechny_odberatele: [],
  posledni_objednavky: [],
};

window.otevritRucniDl = async function() {
  rdlState = {
    odberatel_id: null, misto_dodani_id: null,
    cenik: [], polozky: [], pobocky: [], odberatel: null,
    vsechny_odberatele: [], posledni_objednavky: [],
  };

  try {
    rdlState.vsechny_odberatele = await api('admin_odberatele.php');
  } catch (e) { alert('Nepodařilo se načíst odběratele: ' + e.message); return; }

  vykreslitRucniDl();
};

function vykreslitRucniDl() {
  const s = rdlState;
  const today = new Date().toISOString().split('T')[0];
  const _zitra = new Date(); _zitra.setDate(_zitra.getDate() + 1);
  const tomorrow = _zitra.toISOString().split('T')[0];

  const odberatelSelected = s.odberatel_id && s.odberatel;
  const pobockyOptions = `<option value="">— bez pobočky —</option>` +
    s.pobocky.map((m) =>
      `<option value="${m.id}" ${m.id == s.misto_dodani_id ? 'selected' : ''}>${esc(m.nazev)}</option>`
    ).join('');

  // Položky tabulka
  let bez = 0, dph = 0;
  const polozkyHtml = s.polozky.length === 0
    ? `<div class="empty-state" style="padding:24px;background:var(--surface-2);border-radius:8px">Zatím žádné položky.${odberatelSelected ? ' Přidejte z katalogu níže.' : ''}</div>`
    : s.polozky.map((p, i) => {
        const b = p.mnozstvi * p.cena_bez_dph;
        const d = b * (p.sazba_dph / 100);
        bez += b; dph += d;
        return `
          <div class="obj-polozka-row obj-polozka-edit">
            <div class="obj-polozka-img"><div class="obj-polozka-img-empty">🥖</div></div>
            <div class="obj-polozka-info">
              <input class="form-input obj-polozka-name-input" type="text" value="${esc(p.vyrobek_nazev || '')}"
                     placeholder="Název položky"
                     oninput="rdlState.polozky[${i}].vyrobek_nazev = this.value">
              <div class="obj-polozka-edit-row">
                <input class="form-input obj-polozka-jed" type="text" value="${esc(p.jednotka || 'ks')}" maxlength="10"
                       placeholder="ks"
                       oninput="rdlState.polozky[${i}].jednotka = this.value">
                <input class="form-input obj-polozka-cena-input" type="number" min="0" step="0.01" value="${p.cena_bez_dph}"
                       placeholder="cena"
                       oninput="rdlState.polozky[${i}].cena_bez_dph = parseFloat(this.value)||0; vykreslitRucniDl()">
                <select class="form-input obj-polozka-dph"
                        onchange="rdlState.polozky[${i}].sazba_dph = parseFloat(this.value); vykreslitRucniDl()">
                  <option value="0"  ${p.sazba_dph == 0  ? 'selected' : ''}>0%</option>
                  <option value="12" ${p.sazba_dph == 12 ? 'selected' : ''}>12%</option>
                  <option value="21" ${p.sazba_dph == 21 ? 'selected' : ''}>21%</option>
                </select>
              </div>
            </div>
            <div class="obj-polozka-qty">
              <button type="button" class="qty-btn" onclick="rdlState.polozky[${i}].mnozstvi = Math.max(0, (parseFloat(rdlState.polozky[${i}].mnozstvi)||0) - 1); vykreslitRucniDl()">−</button>
              <input type="number" min="0" step="any" class="form-input qty-input" value="${p.mnozstvi}"
                     oninput="rdlState.polozky[${i}].mnozstvi = parseFloat(this.value)||0; vykreslitRucniDl()">
              <button type="button" class="qty-btn" onclick="rdlState.polozky[${i}].mnozstvi = (parseFloat(rdlState.polozky[${i}].mnozstvi)||0) + 1; vykreslitRucniDl()">+</button>
            </div>
            <div class="obj-polozka-cena">${fmt(b + d)}</div>
            <button class="obj-polozka-del" onclick="rdlState.polozky.splice(${i},1); vykreslitRucniDl()" title="Smazat">×</button>
          </div>
        `;
      }).join('');

  const katalogOptions = s.cenik.map((v) => {
    const slevaInfo = v.pevna_cena !== null ? ' [pevná]' : (v.sleva_pct !== null ? ` [-${parseFloat(v.sleva_pct).toFixed(0)}%]` : '');
    return `<option value="${v.id}">${esc(v.nazev)}${slevaInfo} — ${v.cena_bez_dph} Kč</option>`;
  }).join('');

  const skupinaInfo = s.odberatel?.skupina_nazev
    ? `<span style="background:#FAEEDA;color:#854F0B;padding:3px 8px;border-radius:6px;font-size:11px;font-weight:600">💰 ${esc(s.odberatel.skupina_nazev)}</span>`
    : '';

  openModal(s.editId ? '\u270f\ufe0f Upravit dodac\u00ed list' : '+ Nov\u00fd dodac\u00ed list', `
    <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;margin-bottom:16px">
      <div style="background:#F7F8FA;border:1px solid #E8D5B0;border-radius:8px;padding:14px">
        <div style="font-size:11px;text-transform:uppercase;letter-spacing:0.5px;color:#854F0B;margin-bottom:6px;font-weight:600">\ud83c\udfe2 Odb\u011bratel</div>
        ${odberatelSelected && s.odberatel ? (() => {
          const o = typeof s.odberatel === 'object' ? s.odberatel : null;
          return o ? `
            <div class="modal-party-name">${esc(o.nazev || '')}</div>
            <div style="font-size:13px;color:#854F0B">${o.ico ? 'I\u010cO: ' + esc(o.ico) : ''}${o.dic ? ' \u00b7 DI\u010c: ' + esc(o.dic) : ''}</div>
            ${o.ulice ? '<div style="font-size:13px;color:#854F0B;margin-top:2px">' + esc(o.ulice) + (o.mesto ? ', ' + esc(o.mesto) : '') + '</div>' : ''}
          ` : `<div class="modal-party-name">${esc(String(s.odberatel))}</div>`;
        })() : ''}
        <div class="vp-picker-wrap" style="margin-top:8px;position:relative">
          <input class="form-input vp-picker-input" id="rdl-odberatel-search" type="text"
                 placeholder="\ud83d\udd0d Hledat odb\u011bratele (n\u00e1zev / I\u010cO)\u2026"
                 value="${odberatelSelected && s.odberatel ? esc(s.odberatel.nazev || '') : ''}"
                 style="width:100%;font-size:16px;padding:12px 16px;height:50px;font-weight:500"
                 autocomplete="off"
                 oninput="vpOdbFilter('rdl', this.value)"
                 onfocus="vpOdbFilter('rdl', this.value)"
                 onkeydown="vpOdbKey(event, 'rdl')">
          <input type="hidden" id="rdl-odberatel" value="${s.odberatel_id || ''}">
          <div class="vp-picker-dropdown" id="rdl-odb-dropdown" style="display:none"></div>
        </div>
      </div>
      <div style="background:#EFF6FF;border:1px solid #B5D4F4;border-radius:8px;padding:14px">
        <div style="font-size:11px;text-transform:uppercase;letter-spacing:0.5px;color:#0C447C;margin-bottom:6px;font-weight:600">\ud83d\udccd M\u00edsto dod\u00e1n\u00ed</div>
        ${!odberatelSelected
          ? '<div style="font-size:13px;color:#0C447C">Nejd\u0159\u00edv vyberte odb\u011bratele</div>'
          : s.pobocky.length === 0
          ? `<div style="font-size:13px;color:#0C447C">Tento odb\u011bratel zat\u00edm nem\u00e1 \u017e\u00e1dnou pobo\u010dku.</div>
             <button type="button" class="btn-secondary" onclick="editPobocka(${s.odberatel_id}, null, 'novyDl')" style="margin-top:8px;width:100%;font-size:13px;padding:7px 12px">\u2795 Vytvo\u0159it pobo\u010dku</button>`
          : (() => {
              const m = s.pobocky.find(p => p.id == s.misto_dodani_id);
              const detail = m ? `
            <div class="modal-party-name">${esc(m.nazev || '')}</div>
            ${m.ulice ? '<div style="font-size:13px;color:#0C447C">' + esc(m.ulice) + '</div>' : ''}
            ${m.mesto ? '<div style="font-size:13px;color:#0C447C">' + (m.psc ? esc(m.psc) + ', ' : '') + esc(m.mesto) + '</div>' : ''}
          ` : '<div style="font-size:13px;color:#0C447C">Vyberte pobo\u010dku</div>';
              return detail + `<select class="input" id="rdl-misto" onchange="rdlState.misto_dodani_id = parseInt(this.value) || null; vykreslitRucniDl()" style="margin-top:8px;width:100%">${pobockyOptions}</select>`;
            })()}
      </div>
    </div>
    <div class="doc-meta-row">
      <div class="doc-meta-field">
        <div class="doc-meta-label"><span class="doc-meta-icon">\ud83d\udcc5</span> Datum vystaven\u00ed <span class="doc-meta-req">*</span></div>
        <input class="doc-meta-input" id="rdl-datum-vyst" type="date" value="${s.editId && s.datum_vystaveni ? s.datum_vystaveni : today}">
      </div>
      <div class="doc-meta-field">
        <div class="doc-meta-label"><span class="doc-meta-icon">\ud83d\ude9a</span> Datum dod\u00e1n\u00ed <span class="doc-meta-req">*</span></div>
        <input class="doc-meta-input" id="rdl-datum-dod" type="date" value="${s.editId && s.datum_dodani ? s.datum_dodani : tomorrow}">
      </div>
      <div class="doc-meta-field">
        <div class="doc-meta-label"><span class="doc-meta-icon">\ud83d\udcdd</span> Pozn\u00e1mka</div>
        <input class="doc-meta-input" id="rdl-poznamka" value="${esc(s.editId && s.poznamka ? s.poznamka : '')}" placeholder="Voliteln\u00e1\u2026">
      </div>
    </div>

    <h3 style="margin:20px 0 8px;font-size:15px">Položky</h3>

    ${odberatelSelected ? '' : `
      <div style="background:#E6F1FB;color:#0C447C;padding:12px 16px;border-radius:8px;margin-bottom:12px;font-size:13px">
        ℹ️ Nejdřív vyberte odběratele, pak budete moci přidávat položky s aplikovanými slevami.
      </div>
    `}

    ${odberatelSelected && s.posledni_objednavky.length > 0 ? `
      <div class="rf-history" style="background:#F7F8FA;border:1px solid var(--border);border-radius:8px;padding:12px 16px;margin-bottom:14px">
        <div style="font-size:13px;font-weight:600;color:var(--text-2);margin-bottom:8px">
          📋 Načíst z dřívější objednávky:
        </div>
        <div style="display:grid;grid-template-columns:repeat(3,1fr);gap:10px">
          ${s.posledni_objednavky.map((o) => {
            const pol = (o.polozky || []).slice(0, 6);
            const zbyva = (o.polozky || []).length - pol.length;
            return `
              <div onclick="rdlNacistZObjednavky(${o.id})" style="background:#F7F8FA;border:1px solid #E8D5B0;border-radius:10px;padding:12px;cursor:pointer">
                <div style="display:flex;justify-content:space-between;align-items:baseline;margin-bottom:4px">
                  <strong style="font-size:13px">${esc(o.cislo)}</strong>
                  <span style="font-weight:700;color:#854F0B;font-size:14px">${fmt(o.castka_celkem)}</span>
                </div>
                <div style="font-size:11px;color:#999;margin-bottom:6px">📅 ${fmtDate(o.datum_dodani)} · ${o.pocet_polozek} pol.</div>
                ${pol.length > 0 ? `
                  <ul style="list-style:none;margin:0;padding:0;font-size:11px;color:#666;display:grid;grid-template-columns:1fr 1fr;gap:1px 8px">
                    ${pol.map(p => '<li style="white-space:nowrap;overflow:hidden;text-overflow:ellipsis"><span style="font-weight:700;color:#854F0B;min-width:20px;display:inline-block">' + parseFloat(p.mnozstvi) + '\u00d7</span> ' + esc(p.vyrobek_nazev || '') + '</li>').join('')}
                    ${zbyva > 0 ? '<li style="color:#999;font-style:italic;grid-column:1/-1">+ dal\u0161\u00edch ' + zbyva + '</li>' : ''}
                  </ul>
                ` : ''}
                <div style="margin-top:8px;background:#BA7517;color:white;border-radius:6px;padding:6px 0;text-align:center;font-size:12px;font-weight:600">📥 Načíst položky</div>
              </div>
            `;
          }).join('')}
        </div>
      </div>
    ` : ''}

    <div class="obj-polozky-list" style="margin-bottom:12px">${polozkyHtml}</div>

    ${odberatelSelected ? `
      <div class="vp-picker-wrap" style="margin-bottom:16px;position:relative">
        <div style="display:flex;gap:8px;flex-wrap:wrap;align-items:stretch">
          <input class="form-input vp-picker-input" id="rdl-pridat-vyrobek" type="text"
                 placeholder="🔍 Hledat výrobek (název / kód)…"
                 style="flex:1;min-width:200px;font-size:15px;padding:10px 14px"
                 autocomplete="off"
                 oninput="vpFilter('rdl', this.value)"
                 onfocus="vpFilter('rdl', this.value)"
                 onkeydown="vpKey(event, 'rdl')">
          <input type="hidden" id="rdl-pridat-vyrobek-id" value="">
          <button class="btn-secondary" onclick="rdlPridatZKatalogu()">+ Přidat z katalogu</button>
          <button class="btn-secondary" onclick="rdlPridatVolny()">+ Volný řádek</button>
        </div>
        <div class="vp-picker-dropdown" id="rdl-picker-dropdown" style="display:none"></div>
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
      <button class="btn-primary btn-green" onclick="rdlVystavit()" ${s.polozky.length === 0 || !s.odberatel_id ? 'disabled' : ''}>
        ${s.editId ? '✏️ Uložit změny' : '📃 Vystavit dodací list'}
      </button>
    </div>
  `, 'wide');
}

window.rdlVybratOdberatele = async function(id) {
  if (!id) {
    rdlState.odberatel_id = null;
    rdlState.cenik = [];
    rdlState.pobocky = [];
    rdlState.odberatel = null;
    rdlState.posledni_objednavky = [];
    return vykreslitRucniDl();
  }
  rdlState.odberatel_id = parseInt(id);
  try {
    const data = await api(`cenik_odberatele.php?odberatel_id=${id}`);
    rdlState.cenik = data.vyrobky;
    rdlState.odberatel = data;
    rdlState.pobocky = await api(`admin_pobocky.php?odberatel_id=${id}`);
    const vychozi = rdlState.pobocky.find(m => m.vychozi == 1) || rdlState.pobocky[0];
    if (vychozi) rdlState.misto_dodani_id = vychozi.id;
    // Posledních 5 objednávek odběratele - pro rychlé kopírování položek
    try {
      rdlState.posledni_objednavky = await api(
        `admin_objednavky.php?action=posledni&odberatel_id=${id}&limit=5`
      );
    } catch (e) {
      console.warn('Nepodařilo se načíst poslední objednávky:', e);
      rdlState.posledni_objednavky = [];
    }
    vykreslitRucniDl();
  } catch (e) { alert('Chyba: ' + e.message); }
};

// Načíst položky z dřívější objednávky do ručního DL
window.rdlNacistZObjednavky = function(obj_id) {
  const s = rdlState;
  const obj = s.posledni_objednavky.find(o => o.id == obj_id);
  if (!obj) return;

  if (!obj.polozky || obj.polozky.length === 0) {
    alert('Tato objednávka nemá žádné položky.');
    return;
  }

  // Pokud je DL prázdný, rovnou nahraď bez ptání
  if (s.polozky.length === 0) {
    rdlAplikovatPolozkyZObjednavky(obj, 'replace');
    return;
  }

  // Jinak se zeptej co dělat
  const html = `
    <p style="font-size:15px;line-height:1.5;margin-bottom:16px">
      Dodací list už obsahuje <strong>${s.polozky.length}</strong>
      ${s.polozky.length === 1 ? 'položku' : (s.polozky.length < 5 ? 'položky' : 'položek')}.
      Co s nimi udělat?
    </p>

    <p style="font-size:14px;color:var(--text-2);margin-bottom:20px">
      Načítám <strong>${obj.polozky.length}</strong>
      ${obj.polozky.length === 1 ? 'položku' : (obj.polozky.length < 5 ? 'položky' : 'položek')}
      z objednávky <strong>#${esc(obj.cislo)}</strong> ze dne ${fmtDate(obj.datum_dodani)}.
    </p>

    <div class="form-actions" style="flex-wrap:wrap;gap:8px">
      <button class="btn-secondary" onclick="closeModalConfirm()">Zrušit</button>
      <div style="flex:1"></div>
      <button class="btn-secondary" onclick="rdlAplikovatPolozkyZObjednavky(${JSON.stringify(obj).replace(/"/g, '&quot;')}, 'append'); closeModalConfirm();">
        ➕ Přidat ke stávajícím
      </button>
      <button class="btn-primary" onclick="rdlAplikovatPolozkyZObjednavky(${JSON.stringify(obj).replace(/"/g, '&quot;')}, 'replace'); closeModalConfirm();">
        🔁 Nahradit vše
      </button>
    </div>
  `;
  showConfirmModal('📋 Načíst položky z objednávky', html);
};

window.rdlAplikovatPolozkyZObjednavky = function(obj, mode) {
  const s = rdlState;
  if (mode === 'replace') s.polozky = [];

  // Pro každou položku z objednávky najít odpovídající v ceníku odběratele,
  // aby se aplikovaly slevy. Pokud výrobek není v ceníku (smazaný/skrytý), použij data z objednávky.
  obj.polozky.forEach((p) => {
    const vCeniku = s.cenik.find(v => v.id == p.vyrobek_id);
    if (vCeniku) {
      s.polozky.push({
        vyrobek_id: vCeniku.id,
        vyrobek_cislo: vCeniku.cislo,
        vyrobek_nazev: vCeniku.nazev,
        jednotka: vCeniku.jednotka || p.jednotka || 'ks',
        mnozstvi: parseFloat(p.mnozstvi),
        cena_bez_dph: parseFloat(vCeniku.cena_bez_dph),
        sazba_dph: parseFloat(vCeniku.dph || p.sazba_dph || 12),
      });
    } else {
      // Výrobek už není v ceníku - použij snapshot data z objednávky
      s.polozky.push({
        vyrobek_id: p.vyrobek_id,
        vyrobek_cislo: p.vyrobek_cislo,
        vyrobek_nazev: p.vyrobek_nazev,
        jednotka: p.jednotka || 'ks',
        mnozstvi: parseFloat(p.mnozstvi),
        cena_bez_dph: parseFloat(p.cena_bez_dph),
        sazba_dph: parseFloat(p.sazba_dph),
      });
    }
  });
  vykreslitRucniDl();
};

window.rdlPridatZKatalogu = function() {
  let vid = parseInt(document.getElementById('rdl-pridat-vyrobek-id')?.value || 0);
  if (!vid) {
    const text = (document.getElementById('rdl-pridat-vyrobek')?.value || '').trim().toLowerCase();
    if (text) {
      const m = rdlState.cenik.find(x => (x.nazev || '').toLowerCase() === text);
      if (m) vid = m.id;
    }
  }
  if (!vid) return alert('Vyberte výrobek z nabídky');
  const v = rdlState.cenik.find(x => x.id == vid);
  if (!v) return alert('Výrobek nenalezen v ceníku');

  rdlState.polozky.push({
    vyrobek_id: v.id,
    vyrobek_cislo: v.cislo,
    vyrobek_nazev: v.nazev,
    jednotka: v.jednotka || 'ks',
    mnozstvi: 1,
    cena_bez_dph: parseFloat(v.cena_bez_dph),
    sazba_dph: parseFloat(v.dph || 12),
    je_z_katalogu: true,
  });
  const _inp = document.getElementById('rdl-pridat-vyrobek'); if (_inp) _inp.value = '';
  const _hid = document.getElementById('rdl-pridat-vyrobek-id'); if (_hid) _hid.value = '';
  vykreslitRucniDl();
};

window.rdlPridatVolny = function() {
  rdlState.polozky.push({
    vyrobek_id: null,
    vyrobek_cislo: null,
    vyrobek_nazev: '',
    jednotka: 'ks',
    mnozstvi: 1,
    cena_bez_dph: 0,
    sazba_dph: 12,
    je_z_katalogu: false,
  });
  vykreslitRucniDl();
};

window.rdlVystavit = async function() {
  const s = rdlState;
  if (!s.odberatel_id) return alert('Vyberte odběratele');
  if (s.polozky.length === 0) return alert('Přidejte alespoň jednu položku');

  for (let i = 0; i < s.polozky.length; i++) {
    const p = s.polozky[i];
    if (!p.vyrobek_nazev?.trim()) return alert(t('row_missing_name_short', { n: i + 1 }));
    if (p.mnozstvi <= 0) return alert(t('row_invalid_qty', { n: i + 1 }));
    if (p.cena_bez_dph < 0) return alert(t('row_negative_price', { n: i + 1 }));
  }

  const data = {
    odberatel_id: s.odberatel_id,
    misto_dodani_id: s.misto_dodani_id,
    datum_vystaveni: document.getElementById('rdl-datum-vyst').value,
    datum_dodani: document.getElementById('rdl-datum-dod').value,
    poznamka: document.getElementById('rdl-poznamka').value || null,
    polozky: s.polozky.map((p) => ({
      vyrobek_id: p.vyrobek_id,
      vyrobek_cislo: p.vyrobek_cislo,
      vyrobek_nazev: p.vyrobek_nazev,
      jednotka: p.jednotka,
      mnozstvi: p.mnozstvi,
      cena_bez_dph: p.cena_bez_dph,
      sazba_dph: p.sazba_dph,
    })),
  };

  if (s.editId) {
    data.id = s.editId;
    data.action = 'upravit';
  }

  if (!data.datum_vystaveni) return alert('Vyplňte datum vystavení');
  if (!data.datum_dodani) return alert('Vyplňte datum dodání');

  try {
    const res = await api('admin_dodaci_listy.php', {
      method: 'POST',
      body: data,
    });
    closeModal();
    alert(s.editId ? `Dodací list byl upraven.` : `Dodací list ${res.cislo} byl vystaven.`);
    window.open(`../api/dodaci_list.php?dl_id=${res.id || s.editId}`, '_blank');
    navigate('dodaci_listy');
  } catch (e) {
    alert('Chyba: ' + e.message);
  }
};

