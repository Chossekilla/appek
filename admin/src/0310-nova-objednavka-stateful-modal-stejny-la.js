// =============================================================
// NOVÁ OBJEDNÁVKA — stateful modal (stejný layout jako Ruční DL/FA)
// =============================================================
let noState = null;
// Expose na window aby ho mohly číst sdílené helpery (vpOdbFilter, atd.)
Object.defineProperty(window, 'noState', { get: () => noState, configurable: true });

window.otevritNovouObjednavku = async function() {
  noState = {
    odberatel_id: null,
    misto_dodani_id: null,
    cenik: [],
    polozky: [],
    pobocky: [],
    odberatel: null,
    vsechny_odberatele: [],
    posledni_objednavky: [],
    datum_dodani: null,
    poznamka: '',
    interni_poznamka: '',
  };

  try {
    noState.vsechny_odberatele = await api('admin_odberatele.php');
  } catch (e) { alert('Nepodařilo se načíst odběratele: ' + e.message); return; }

  vykreslitNovouObjednavku();
};

function vykreslitNovouObjednavku() {
  const s = noState;
  const _zitra = new Date(); _zitra.setDate(_zitra.getDate() + 1);
  const tomorrow = _zitra.toISOString().split('T')[0];
  if (!s.datum_dodani) s.datum_dodani = tomorrow;

  const odberatelSelected = s.odberatel_id && s.odberatel;
  const pobockyOptions = `<option value="">— bez pobočky —</option>` +
    s.pobocky.map((m) =>
      `<option value="${m.id}" ${m.id == s.misto_dodani_id ? 'selected' : ''}>${esc(m.nazev)}${m.vychozi == 1 ? ' (výchozí)' : ''}</option>`
    ).join('');

  let bez = 0, dph = 0;
  const polozkyHtml = s.polozky.length === 0
    ? `<div class="empty-state" style="padding:24px;background:var(--surface-2);border-radius:8px">Zatím žádné položky.${odberatelSelected ? ' Přidejte z katalogu níže nebo volný řádek.' : ''}</div>`
    : s.polozky.map((p, i) => {
        const b = p.mnozstvi * p.cena_bez_dph;
        const d = b * (p.sazba_dph / 100);
        bez += b; dph += d;
        const volny = !p.vyrobek_id;
        if (volny) {
          // Editovatelný volný řádek
          return `
            <div class="obj-polozka-row obj-polozka-volny" data-pol-idx="${i}">
              <div class="obj-polozka-img"><div class="obj-polozka-img-empty" title="Volný řádek">✏️</div></div>
              <div class="obj-polozka-info">
                <input type="text" class="form-input" placeholder="Název položky *" value="${esc(p.vyrobek_nazev || '')}"
                       oninput="noUpdatePolozka(${i}, 'vyrobek_nazev', this.value)" style="width:100%;font-weight:600;margin-bottom:4px">
                <div style="display:flex;gap:6px;align-items:center;font-size:12px">
                  <input type="number" min="0" step="0.01" class="form-input" value="${p.cena_bez_dph}"
                         oninput="noUpdatePolozka(${i}, 'cena_bez_dph', this.value)" style="width:90px;text-align:right" title="Cena bez DPH">
                  <span style="color:var(--text-3)">Kč /</span>
                  <input type="text" class="form-input" value="${esc(p.jednotka || 'ks')}"
                         oninput="noUpdatePolozka(${i}, 'jednotka', this.value)" style="width:60px;text-align:center" title="Jednotka">
                  <span style="color:var(--text-3)">·</span>
                  <select class="form-input" onchange="noUpdatePolozka(${i}, 'sazba_dph', this.value)" style="width:70px" title="Sazba DPH">
                    <option value="0"  ${p.sazba_dph == 0  ? 'selected' : ''}>0 %</option>
                    <option value="12" ${p.sazba_dph == 12 ? 'selected' : ''}>12 %</option>
                    <option value="21" ${p.sazba_dph == 21 ? 'selected' : ''}>21 %</option>
                  </select>
                </div>
              </div>
              <div class="obj-polozka-qty">
                <button type="button" class="qty-btn" onclick="noState.polozky[${i}].mnozstvi = Math.max(0, (parseFloat(noState.polozky[${i}].mnozstvi)||0) - 1); vykreslitNovouObjednavku()">−</button>
                <input type="number" min="0" step="any" class="form-input qty-input" value="${p.mnozstvi}"
                       oninput="noState.polozky[${i}].mnozstvi = parseFloat(this.value)||0; vykreslitNovouObjednavku()">
                <button type="button" class="qty-btn" onclick="noState.polozky[${i}].mnozstvi = (parseFloat(noState.polozky[${i}].mnozstvi)||0) + 1; vykreslitNovouObjednavku()">+</button>
              </div>
              <div class="obj-polozka-cena">${fmt(b + d)}</div>
              <button class="obj-polozka-del" onclick="noState.polozky.splice(${i},1); vykreslitNovouObjednavku()" title="Smazat">×</button>
            </div>
          `;
        }
        return `
          <div class="obj-polozka-row" data-pol-idx="${i}">
            <div class="obj-polozka-img"><div class="obj-polozka-img-empty">🥖</div></div>
            <div class="obj-polozka-info">
              <div class="obj-polozka-nazev">${esc(p.vyrobek_nazev || '')}</div>
              <div class="obj-polozka-meta">${p.vyrobek_cislo ? 'kód ' + esc(p.vyrobek_cislo) + ' · ' : ''}${fmt(p.cena_bez_dph)} / ${esc(p.jednotka || 'ks')} · DPH ${p.sazba_dph}%</div>
            </div>
            <div class="obj-polozka-qty">
              <button type="button" class="qty-btn" onclick="noState.polozky[${i}].mnozstvi = Math.max(0, (parseFloat(noState.polozky[${i}].mnozstvi)||0) - 1); vykreslitNovouObjednavku()">−</button>
              <input type="number" min="0" step="any" class="form-input qty-input" value="${p.mnozstvi}"
                     oninput="noState.polozky[${i}].mnozstvi = parseFloat(this.value)||0; vykreslitNovouObjednavku()">
              <button type="button" class="qty-btn" onclick="noState.polozky[${i}].mnozstvi = (parseFloat(noState.polozky[${i}].mnozstvi)||0) + 1; vykreslitNovouObjednavku()">+</button>
            </div>
            <div class="obj-polozka-cena">${fmt(b + d)}</div>
            <button class="obj-polozka-del" onclick="noState.polozky.splice(${i},1); vykreslitNovouObjednavku()" title="Smazat">×</button>
          </div>
        `;
      }).join('');

  const katalogOptions = s.cenik.map((v) => {
    const slevaInfo = v.pevna_cena !== null ? ' [pevná]' : (v.sleva_pct !== null ? ` [-${parseFloat(v.sleva_pct).toFixed(0)}%]` : '');
    return `<option value="${v.id}">${esc(v.nazev)}${slevaInfo} — ${parseFloat(v.cena_bez_dph).toFixed(2)} Kč</option>`;
  }).join('');

  openModal('+ Nová objednávka', `
    <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;margin-bottom:16px">
      <div style="background:#F7F8FA;border:1px solid #E8D5B0;border-radius:8px;padding:14px">
        <div style="font-size:11px;text-transform:uppercase;letter-spacing:0.5px;color:#854F0B;margin-bottom:6px;font-weight:600">🏢 Odběratel</div>
        ${odberatelSelected && s.odberatel ? `
          <div style="font-size:15px;font-weight:600;margin-bottom:4px">${esc(s.odberatel.nazev || '')}</div>
          <div style="font-size:12px;color:#854F0B">${s.odberatel.ico ? 'IČO: ' + esc(s.odberatel.ico) : ''}${s.odberatel.dic ? ' · DIČ: ' + esc(s.odberatel.dic) : ''}</div>
          ${s.odberatel.skupina_nazev ? `<div style="margin-top:6px"><span style="background:#FAEEDA;color:#854F0B;padding:2px 8px;border-radius:5px;font-size:11px;font-weight:600">💰 ${esc(s.odberatel.skupina_nazev)}</span></div>` : ''}
        ` : ''}
        <div class="vp-picker-wrap" style="margin-top:8px;position:relative">
          <input class="form-input vp-picker-input" id="no-odberatel-search" type="text"
                 placeholder="🔍 Hledat odběratele (název / IČO)…"
                 value="${s.odberatel ? esc(s.odberatel.nazev || '') : ''}"
                 style="width:100%;font-size:16px;padding:12px 16px;height:50px;font-weight:500"
                 autocomplete="off"
                 oninput="vpOdbFilter('no', this.value)"
                 onfocus="vpOdbFilter('no', this.value)"
                 onkeydown="vpOdbKey(event, 'no')">
          <input type="hidden" id="no-odberatel" value="${s.odberatel_id || ''}">
          <div class="vp-picker-dropdown" id="no-odb-dropdown" style="display:none"></div>
        </div>
      </div>
      <div style="background:#EFF6FF;border:1px solid #B5D4F4;border-radius:8px;padding:14px">
        <div style="font-size:11px;text-transform:uppercase;letter-spacing:0.5px;color:#0C447C;margin-bottom:6px;font-weight:600">📍 Místo dodání</div>
        ${!odberatelSelected
          ? '<div style="font-size:13px;color:#0C447C">Nejdřív vyberte odběratele</div>'
          : s.pobocky.length === 0
          ? `<div style="font-size:13px;color:#0C447C">Tento odběratel zatím nemá žádnou pobočku.</div>
             <button type="button" class="btn-secondary" onclick="editPobocka(${s.odberatel_id}, null, 'novaObjednavka')" style="margin-top:8px;width:100%;font-size:13px;padding:7px 12px">➕ Vytvořit pobočku</button>`
          : (() => {
              const m = s.pobocky.find(p => p.id == s.misto_dodani_id);
              const detail = m ? `
            <div class="modal-party-name">${esc(m.nazev || '')}</div>
            ${m.ulice ? '<div style="font-size:13px;color:#0C447C">' + esc(m.ulice) + '</div>' : ''}
            ${m.mesto ? '<div style="font-size:13px;color:#0C447C">' + (m.psc ? esc(m.psc) + ', ' : '') + esc(m.mesto) + '</div>' : ''}
          ` : '<div style="font-size:13px;color:#0C447C">Vyberte pobočku</div>';
              return detail + `<select class="input" id="no-misto" onchange="noState.misto_dodani_id = parseInt(this.value) || null; vykreslitNovouObjednavku()" style="margin-top:8px;width:100%">${pobockyOptions}</select>`;
            })()}
      </div>
    </div>

    <div style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:12px;margin-bottom:16px">
      <div>
        <div style="font-size:12px;color:var(--text-2);margin-bottom:4px">Datum dodání *</div>
        <input class="input" id="no-datum" type="date" value="${esc(s.datum_dodani)}"
               oninput="noState.datum_dodani = this.value" style="width:100%">
      </div>
      <div>
        <div style="font-size:12px;color:var(--text-2);margin-bottom:4px">Poznámka (pro odběratele)</div>
        <input class="input" id="no-pozn" value="${esc(s.poznamka)}"
               oninput="noState.poznamka = this.value" placeholder="Volitelná..." style="width:100%">
      </div>
      <div>
        <div style="font-size:12px;color:var(--text-2);margin-bottom:4px">Interní poznámka</div>
        <input class="input" id="no-ipozn" value="${esc(s.interni_poznamka)}"
               oninput="noState.interni_poznamka = this.value" placeholder="Není vidět odběrateli..." style="width:100%">
      </div>
    </div>

    <h3 style="margin:20px 0 8px;font-size:15px">Položky</h3>

    ${odberatelSelected ? '' : `
      <div style="background:#E6F1FB;color:#0C447C;padding:12px 16px;border-radius:8px;margin-bottom:12px;font-size:13px">
        ℹ️ Nejdřív vyberte odběratele, pak budete moci přidávat položky s aplikovanými slevami.
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
                <div onclick="noNacistZObjednavky(${o.id})" style="background:#F7F8FA;border:1px solid #E8D5B0;border-radius:10px;padding:12px;cursor:pointer">
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
          <input class="form-input vp-picker-input" id="no-pridat-vyrobek" type="text"
                 placeholder="🔍 Hledat výrobek (název / kód)…"
                 style="flex:1;min-width:200px;font-size:15px;padding:10px 14px"
                 autocomplete="off"
                 oninput="vpFilter('no', this.value)"
                 onfocus="vpFilter('no', this.value)"
                 onkeydown="vpKey(event, 'no')">
          <input type="hidden" id="no-pridat-vyrobek-id" value="">
          <button class="btn-secondary" onclick="noPridatZKatalogu()">+ Přidat z katalogu</button>
          <button class="btn-secondary" onclick="noPridatVolny()">+ Volný řádek</button>
        </div>
        <div class="vp-picker-dropdown" id="no-picker-dropdown" style="display:none"></div>
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
      <button class="btn-primary btn-green" onclick="ulozitNovouObjednavku()" ${s.polozky.length === 0 || !s.odberatel_id ? 'disabled' : ''}>
        🛒 Vytvořit objednávku
      </button>
    </div>
  `, 'wide');
}

window.noVybratOdberatele = async function(id) {
  if (!id) {
    noState.odberatel_id = null;
    noState.cenik = [];
    noState.pobocky = [];
    noState.odberatel = null;
    noState.posledni_objednavky = [];
    return vykreslitNovouObjednavku();
  }
  noState.odberatel_id = parseInt(id);
  try {
    const data = await api(`cenik_odberatele.php?odberatel_id=${id}`);
    noState.cenik = data.vyrobky;
    noState.odberatel = data;
    noState.pobocky = await api(`admin_pobocky.php?odberatel_id=${id}`);
    const vychozi = noState.pobocky.find(m => m.vychozi == 1) || noState.pobocky[0];
    if (vychozi) noState.misto_dodani_id = vychozi.id;
    try {
      noState.posledni_objednavky = await api(
        `admin_objednavky.php?action=posledni&odberatel_id=${id}&limit=5`
      );
    } catch (e) {
      console.warn('Nepodařilo se načíst poslední objednávky:', e);
      noState.posledni_objednavky = [];
    }
    vykreslitNovouObjednavku();
  } catch (e) { alert('Chyba: ' + e.message); }
};

window.noNacistZObjednavky = function(obj_id) {
  const s = noState;
  const obj = s.posledni_objednavky.find(o => o.id == obj_id);
  if (!obj) return;
  if (!obj.polozky || obj.polozky.length === 0) {
    alert('Tato objednávka nemá žádné položky.');
    return;
  }
  if (s.polozky.length === 0) {
    noAplikovatPolozky(obj, 'replace');
    return;
  }
  const html = `
    <p style="font-size:15px;line-height:1.5;margin-bottom:16px">
      Objednávka už obsahuje <strong>${s.polozky.length}</strong>
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
      <button class="btn-secondary" onclick="noAplikovatPolozky(${JSON.stringify(obj).replace(/"/g, '&quot;')}, 'append'); closeModalConfirm();">
        ➕ Přidat ke stávajícím
      </button>
      <button class="btn-primary" onclick="noAplikovatPolozky(${JSON.stringify(obj).replace(/"/g, '&quot;')}, 'replace'); closeModalConfirm();">
        🔁 Nahradit vše
      </button>
    </div>
  `;
  showConfirmModal('📋 Načíst položky z objednávky', html);
};

window.noAplikovatPolozky = function(obj, mode) {
  const s = noState;
  if (mode === 'replace') s.polozky = [];
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
    }
  });
  vykreslitNovouObjednavku();
};

/**
 * Vytvořit novou objednávku ze zdrojového dokladu (OBJ / DL / FA).
 * - Zavře aktuální modal (detail), otevře čistý NO modal
 * - Přednastaví odběratele (načte ceník + pobočky)
 * - Aplikuje položky z původního dokladu (mapuje na aktuální ceník)
 */
window.noOpakovatZeZdroje = async function(source, id) {
  let zdroj, polozky, odberatel_id;
  try {
    if (source === 'obj') {
      zdroj = await api(`admin_objednavky.php?id=${id}`);
      polozky = zdroj.polozky || [];
      odberatel_id = zdroj.odberatel_id;
    } else if (source === 'dl') {
      zdroj = await api(`admin_dodaci_listy.php?id=${id}`);
      polozky = zdroj.polozky || [];
      odberatel_id = zdroj.odberatel_id;
    } else if (source === 'fa') {
      zdroj = await api(`admin_faktury.php?id=${id}`);
      polozky = zdroj.polozky || [];
      odberatel_id = zdroj.odberatel_id;
    } else {
      return alert('Neznámý zdroj: ' + source);
    }
  } catch (e) {
    return alert('Nepodařilo se načíst zdrojový doklad: ' + e.message);
  }

  // Filtrovat jen položky s vyrobek_id (volné řádky bez výrobku)
  const cleanPolozky = polozky.filter(p => p.vyrobek_id);
  const skipnuto = polozky.length - cleanPolozky.length;
  if (cleanPolozky.length === 0) {
    return alert('Tento doklad neobsahuje žádné výrobky z katalogu, které by šly opakovat.');
  }
  if (!odberatel_id) {
    return alert('Zdrojový doklad nemá odběratele.');
  }

  closeModal();

  // Otevři novou objednávku, počkej až se naplní vsechny_odberatele
  await otevritNovouObjednavku();
  // Vyber odběratele (načte ceník + pobočky + posledni objednavky)
  await noVybratOdberatele(odberatel_id);
  // Aplikuj položky
  noAplikovatPolozky({ polozky: cleanPolozky }, 'replace');

  // Krátká info zpráva pokud něco bylo přeskočeno
  if (skipnuto > 0) {
    setTimeout(() => alert(t('imported_lines_skipped', { n: cleanPolozky.length, skipped: skipnuto })), 100);
  }
};

window.noPridatZKatalogu = function() {
  let vid = parseInt(document.getElementById('no-pridat-vyrobek-id')?.value || 0);
  if (!vid) {
    const text = (document.getElementById('no-pridat-vyrobek')?.value || '').trim().toLowerCase();
    if (text) {
      const m = noState.cenik.find(x => (x.nazev || '').toLowerCase() === text);
      if (m) vid = m.id;
    }
  }
  if (!vid) return alert('Vyberte výrobek z nabídky');
  const v = noState.cenik.find(x => x.id == vid);
  if (!v) return alert('Výrobek nenalezen v ceníku');

  const exist = noState.polozky.find(p => p.vyrobek_id == vid);
  if (exist) {
    exist.mnozstvi = (parseFloat(exist.mnozstvi) || 0) + 1;
  } else {
    noState.polozky.push({
      vyrobek_id: v.id,
      vyrobek_cislo: v.cislo,
      vyrobek_nazev: v.nazev,
      jednotka: v.jednotka || 'ks',
      mnozstvi: 1,
      cena_bez_dph: parseFloat(v.cena_bez_dph),
      sazba_dph: parseFloat(v.dph || 12),
      je_z_katalogu: true,
    });
  }
  sel.value = '';
  vykreslitNovouObjednavku();
};

window.noPridatVolny = function() {
  noState.polozky.push({
    vyrobek_id: null,
    vyrobek_cislo: null,
    vyrobek_nazev: '',
    jednotka: 'ks',
    mnozstvi: 1,
    cena_bez_dph: 0,
    sazba_dph: 12,
    je_z_katalogu: false,
  });
  vykreslitNovouObjednavku();
};

window.noUpdatePolozka = function(idx, field, value) {
  if (!noState.polozky[idx]) return;
  if (field === 'cena_bez_dph' || field === 'sazba_dph') value = parseFloat(value) || 0;
  noState.polozky[idx][field] = value;
  // Re-render všeho — volné řádky mění souhrn
  vykreslitNovouObjednavku();
};

window.ulozitNovouObjednavku = async function() {
  const s = noState;
  if (!s.odberatel_id) return alert('Vyberte odběratele');
  if (!s.datum_dodani) return alert('Vyplňte datum dodání');
  // Validace volných řádků
  for (let i = 0; i < s.polozky.length; i++) {
    const p = s.polozky[i];
    if (!p.vyrobek_id) {
      if (!p.vyrobek_nazev?.trim()) return alert(t('row_missing_name', { n: i + 1 }));
      if ((p.cena_bez_dph || 0) < 0) return alert(t('row_negative_price', { n: i + 1 }));
    }
  }
  const polozky = s.polozky.filter(p => p.mnozstvi > 0).map(p => ({
    vyrobek_id: p.vyrobek_id,
    mnozstvi: p.mnozstvi,
    // Pro volné řádky pošli i ostatní data
    vyrobek_nazev: p.vyrobek_id ? null : (p.vyrobek_nazev || '').trim(),
    jednotka: p.vyrobek_id ? null : (p.jednotka || 'ks'),
    cena_bez_dph: p.vyrobek_id ? null : parseFloat(p.cena_bez_dph) || 0,
    sazba_dph: p.vyrobek_id ? null : parseFloat(p.sazba_dph) || 0,
  }));
  if (polozky.length === 0) return alert('Přidejte alespoň jednu položku');

  try {
    const res = await api('admin_objednavky.php', {
      method: 'POST',
      body: JSON.stringify({
        action: 'vytvorit',
        odberatel_id: s.odberatel_id,
        misto_dodani_id: s.misto_dodani_id || null,
        datum_dodani: s.datum_dodani,
        polozky,
        poznamka: s.poznamka || null,
        interni_pozn: s.interni_poznamka || null,
      }),
    });
    closeModal();
    alert(t('order_created', { cislo: res.cislo }));
    navigate('objednavky');
  } catch (e) { alert('Chyba: ' + e.message); }
};

