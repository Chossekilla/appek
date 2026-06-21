// =============================================================
// Přiřazování šablony k výrobkům — modal
// =============================================================
window.haccpGrafAssignDialog = async function(graf_id) {
  const g = (haccpState.grafy || []).find(x => x.id === graf_id);
  if (!g) return;
  // Načti všechny výrobky s aktuálním haccp_graf_id
  let vList;
  try { vList = await api('admin_vyrobky.php'); }
  catch (e) { return alert('Chyba: ' + e.message); }
  const vyrobky = (vList && Array.isArray(vList.vyrobky)) ? vList.vyrobky : [];

  const rows = vyrobky.filter(v => parseInt(v.aktivni) === 1).map(v => {
    const checked = (parseInt(v.haccp_graf_id) === graf_id);
    const otherGraf = v.haccp_graf_id && parseInt(v.haccp_graf_id) !== graf_id
      ? (haccpState.grafy || []).find(x => x.id === parseInt(v.haccp_graf_id))
      : null;
    return `
      <tr>
        <td><input type="checkbox" class="hg-assign-cb" value="${v.id}" ${checked ? 'checked' : ''}></td>
        <td style="font-size:12px">${esc(v.cislo || '')}</td>
        <td><strong>${esc(v.nazev)}</strong></td>
        <td style="font-size:11px;color:var(--text-3)">${esc((v.kategorie_ikona || '') + ' ' + (v.kategorie_nazev || ''))}</td>
        <td style="font-size:11px">${otherGraf ? `<span style="color:#92400e">⚠ ${esc(otherGraf.nazev)}</span>` : '—'}</td>
      </tr>
    `;
  }).join('');

  openModal(`🔗 Přiřadit šablonu „${g.nazev}"`, `
    <p style="margin:0 0 10px;color:var(--text-2);font-size:13px">Vyber výrobky, které mají používat tuto šablonu. Aktuálně přiřazené jsou zaškrtnuté.</p>
    <div style="display:flex;gap:8px;margin-bottom:10px">
      <button class="btn-secondary" onclick="document.querySelectorAll('.hg-assign-cb').forEach(c=>c.checked=true)" style="font-size:12px">Vybrat vše</button>
      <button class="btn-secondary" onclick="document.querySelectorAll('.hg-assign-cb').forEach(c=>c.checked=false)" style="font-size:12px">Zrušit výběr</button>
      <input class="form-input" id="hg-assign-q" placeholder="Filtr…" oninput="document.querySelectorAll('#hg-assign-tb tr').forEach(tr=>{const t=tr.textContent.toLowerCase();tr.style.display=t.includes(this.value.toLowerCase())?'':'none'})" style="flex:1;font-size:12px;padding:6px 10px">
    </div>
    <div style="max-height:50vh;overflow:auto;border:1px solid var(--border);border-radius:6px">
      <table class="table" style="margin:0;font-size:13px">
        <thead><tr><th style="width:30px"></th><th>Č.</th><th>Název</th><th>Kategorie</th><th>Jiná šablona?</th></tr></thead>
        <tbody id="hg-assign-tb">${rows}</tbody>
      </table>
    </div>
    <div style="display:flex;gap:8px;justify-content:flex-end;margin-top:14px">
      <button class="btn-secondary" onclick="closeModal()">Zrušit</button>
      <button class="btn-primary btn-green" onclick="haccpGrafAssignSubmit(${graf_id})">💾 Uložit přiřazení</button>
    </div>
  `);
};

window.haccpGrafAssignSubmit = async function(graf_id) {
  const checked = Array.from(document.querySelectorAll('.hg-assign-cb:checked')).map(c => parseInt(c.value));
  const all = Array.from(document.querySelectorAll('.hg-assign-cb')).map(c => parseInt(c.value));
  const unchecked = all.filter(id => !checked.includes(id));
  try {
    if (checked.length > 0) {
      await api('admin_haccp_grafy.php?action=assign', { method: 'POST', body: JSON.stringify({ graf_id, vyrobky_ids: checked }) });
    }
    // Odeber šablonu od výrobků, kteří byli původně přiřazení a teď nejsou
    // (zde nemáme info, kteří byli — ale to nevadí, prostě odeber dosud přiřazené, kteří NEJSOU v `checked`)
    if (unchecked.length > 0) {
      // Pošli odebrání jen pro ty, co měli graf_id = tento (ostatní můžou mít jiný graf — nesmíme je promazat)
      // → na backendu unassign jen těch, co mají haccp_graf_id = tento graf_id
      await api('admin_haccp_grafy.php?action=unassign', { method: 'POST', body: JSON.stringify({ vyrobky_ids: unchecked, only_graf_id: graf_id }) });
    }
    closeModal();
    await haccpRefreshGrafy();
  } catch (e) { alert('Chyba: ' + e.message); }
};

window.haccpDefCustomAdd = function() {
  let customs = Array.isArray(haccpState.defaults._custom) ? haccpState.defaults._custom : [];
  // Před přidáním si uložíme aktuální hodnoty z DOMu
  haccpDefSyncFromDom();
  customs = haccpState.defaults._custom || [];
  customs.push({ label: '', value: '' });
  haccpState.defaults._custom = customs;
  haccpRender();
};

function haccpDefSyncFromDom() {
  HACCP_FIELDS.forEach(f => {
    const el = document.getElementById('hd-' + f.k);
    if (el) haccpState.defaults[f.k] = el.value.trim();
  });
  const customs = [];
  document.querySelectorAll('#hd-custom-rows .naklad-row').forEach(r => {
    const label = r.querySelector('[data-fld="label"]')?.value.trim() || '';
    const value = r.querySelector('[data-fld="value"]')?.value.trim() || '';
    if (label || value) customs.push({ label, value });
  });
  haccpState.defaults._custom = customs;
}

window.ulozitHaccpDefaulty = async function() {
  haccpDefSyncFromDom();
  try {
    await api('admin_nastaveni.php', { method: 'PUT', body: JSON.stringify({ haccp_defaults: JSON.stringify(haccpState.defaults) }) });
    const t = document.createElement('div');
    t.style.cssText = 'position:fixed;bottom:24px;right:24px;background:var(--success-bg);color:var(--success-text);padding:14px 22px;border-radius:8px;box-shadow:0 4px 12px rgba(0,0,0,0.15);font-size:14px;font-weight:600;z-index:9999';
    t.textContent = '✓ Defaulty uloženy';
    document.body.appendChild(t);
    setTimeout(() => t.remove(), 2200);
  } catch (e) { alert('Chyba: ' + e.message); }
};

// Defaultní šablona výrobních kroků pro pekařství (univerzální fallback)
const HACCP_POSTUP_TEMPLATE = [
  { nazev: 'Příjem surovin', ccp: false,
    popis: 'Příjem surovin od ověřených dodavatelů. Vizuální kontrola obalů, DMT, čistoty a integrity. Kontrola atestů u rizikových surovin (mouka, vejce, tuky). Záznam příjmu do skladové evidence.' },

  { nazev: 'Skladování surovin', ccp: false,
    popis: 'Skladování v suchém prostoru při teplotě do 25 °C, vlhkost < 70 %. Mouka v paletovaném pytlovaném balení, sypké suroviny v uzavřených zásobnících. Chlazené suroviny (droždí, vejce, máslo) v lednici 2–8 °C. FIFO (first-in / first-out), pravidelná deratizace.' },

  { nazev: 'Dávkování surovin', ccp: false,
    popis: 'Naváží se základní suroviny dle receptury (mouka 100 %, voda 60–65 %, droždí 2–4 %, sůl 1.5–2 %, olej 2 %, cukr 1–3 %, zlepšovadlo 0.5 %). Kontrola DMT, vizuální kontrola, čistota nádobí a vah. Záznam šarže.' },

  { nazev: 'Smísení / hnětení těsta', ccp: false,
    popis: 'Hnětení v hnětači 4 min pomalu + 6–8 min rychle při 26–28 °C. Kontrola hladkosti a pružnosti těsta. Konečná teplota těsta 28–30 °C.' },

  { nazev: 'Zrání / kynutí těsta', ccp: false,
    popis: 'Odpočinek a první kvasný proces 30–90 min při teplotě 26–28 °C a vlhkosti 70–75 % v zakryté nádobě nebo na válu. Probíhá uvolnění lepku a tvorba aroma.' },

  { nazev: 'Dělení na klonky', ccp: false,
    popis: 'Rozdělení těsta na rovnoměrné kusy dle hmotnosti výrobku (rohlík/žemle 60–90 g, bageta 80–110 g, dalamánek 50–80 g, chléb 800–1500 g) na dělicím stroji. Namátková kontrola hmotnosti.' },

  { nazev: 'Tvarování', ccp: false,
    popis: 'Ruční nebo strojové tvarování dle typu výrobku — rohlíky se válí, žemle se kulatí, bagety se táhnou s podélným nářezem, chléb se tvaruje a vkládá do ošatky vyložené žitnou moukou.' },

  { nazev: 'Konečné kynutí', ccp: false,
    popis: 'Konečné kynutí v kynárně 25–60 min při teplotě 32–35 °C a vlhkosti 75–80 %, až do plného nakynutí. Vizuální kontrola objemu (cca dvojnásobek).' },

  { nazev: 'Zdobení', ccp: false,
    popis: 'Vlhčení vodou nebo mašlovacím vejcem, posyp dle typu výrobku (mák, sezam, sůl, kmín, sýr, směs Maroko/Pikant/Fénix, ořechy, slunečnice, vločky). Rovnoměrná aplikace před zasazením do pece.' },

  { nazev: 'Pečení', ccp: true,
    popis: 'CCP — Pečení v parní troubě dle typu: pečivo 220–240 °C / 12–22 min, chléb 240–260 °C / 30–45 min, jemné pečivo 180–210 °C / 12–18 min. Vnitřní teplota produktu min. 88–92 °C. Záznam do provozního deníku (datum, šarže, teplota pece, doba pečení, jméno obsluhy).' },

  { nazev: 'Chladnutí', ccp: false,
    popis: 'Chlazení na chladicí ploše ve větraném prostoru 30–120 min, dokud teplota produktu neklesne pod 30 °C. Oddělení od surového těsta — zákaz křížové kontaminace. Chléb ideálně přes noc pro stabilizaci střídy.' },

  { nazev: 'Ukládání do přepravek', ccp: false,
    popis: 'Ruční přemístění do čistých plastových přepravek (max. 30 ks/přepravka u pečiva, 6–8 ks u chleba). Třídění dle druhu, kontrola integrity přepravek a označení (datum + šarže).' },

  { nazev: 'Skladování hotových výrobků', ccp: false,
    popis: 'Krátkodobé skladování v expediční místnosti při teplotě do 25 °C, vlhkost < 70 %. FIFO. Maximální doba do expedice 4 hodiny. Oddělení od surovin a surového těsta.' },

  { nazev: 'Expedice', ccp: false,
    popis: 'Nakládka do čistých vozidel, kontrola DMT před nakládkou, předání podle dodacích listů. Distribuce do provozoven APPEK B2B. Hygiena řidičů, dezinfekce vozidel min. 1× týdně.' },
];

const HACCP_KB_TEMPLATE = [
  { krok: 'Příjem surovin',     typ: 'B', popis: 'Kontaminace mikroorganizmy / škůdci', opatreni: 'Ověření dodavatelů, atesty, vizuální kontrola obalů a DMT, kontrola teploty', riziko: 'S', ccp: 'CP' },
  { krok: 'Příjem surovin',     typ: 'CH', popis: 'Mykotoxiny, rezidua pesticidů, těžké kovy', opatreni: 'Atesty dodavatelů, kontrola specifikací', riziko: 'S', ccp: 'CP' },
  { krok: 'Skladování surovin', typ: 'B', popis: 'Pomnožení MO, tvorba toxinů', opatreni: 'Dodržení podmínek skladování, FIFO, kontrola teploty a vlhkosti', riziko: 'S', ccp: 'CP' },
  { krok: 'Hnětení / zrání',    typ: 'B', popis: 'Kontaminace MO z prostředí a nářadí', opatreni: 'Hygiena pracoviště a pracovníků, dezinfekce nářadí', riziko: 'M', ccp: 'CP' },
  { krok: 'Pečení',             typ: 'B', popis: 'Nedostatečné prohřátí — přežití patogenů', opatreni: 'Kontrola teploty pece a doby pečení dle receptury', riziko: 'V', ccp: 'CCP' },
  { krok: 'Chladnutí',          typ: 'B', popis: 'Sekundární kontaminace, kondenzace', opatreni: 'Chlazení v čistém prostředí, dostatečný odvod par', riziko: 'M', ccp: 'CP' },
  { krok: 'Skladování hotových', typ: 'B', popis: 'Pomnožení MO při nesprávné teplotě', opatreni: 'Kontrola teploty skladu, FIFO, čistota přepravek', riziko: 'M', ccp: 'CP' },
  { krok: 'Expedice',           typ: 'F', popis: 'Mechanická kontaminace při manipulaci', opatreni: 'Čisté přepravky, hygiena, kontrola DMT', riziko: 'N', ccp: 'CP' },
];

// Helper: doplní prázdné popisy v postupu (a prázdné nazvy) — z přiřazeného grafu, jinak z HACCP_POSTUP_TEMPLATE
function haccpFillEmptyPopisy(postup, grafKroky) {
  if (!Array.isArray(postup)) return postup;
  return postup.map((k, i) => {
    let nazev = k.nazev || '';
    let popis = k.popis || '';
    let ccp = !!k.ccp;
    // Pokud má prázdný popis, hledej zdroj
    if ((popis || '').trim() === '' || (nazev || '').trim() === '') {
      // 1) ze stejného indexu v graf
      if (Array.isArray(grafKroky) && grafKroky[i]) {
        if ((nazev || '').trim() === '' && (grafKroky[i].nazev || '').trim() !== '') {
          nazev = grafKroky[i].nazev;
          if (!ccp && grafKroky[i].ccp) ccp = true;
        }
        if ((popis || '').trim() === '' && (grafKroky[i].popis || '').trim() !== '') {
          popis = grafKroky[i].popis;
        }
      }
      // 2) match podle názvu v graf
      if ((popis || '').trim() === '' && Array.isArray(grafKroky) && nazev) {
        const matched = grafKroky.find(g => (g.nazev || '').toLowerCase() === (nazev || '').toLowerCase() && (g.popis || '').trim() !== '');
        if (matched) popis = matched.popis;
      }
      // 3) match v univerzálním HACCP_POSTUP_TEMPLATE
      if ((popis || '').trim() === '' && nazev) {
        const tplMatched = HACCP_POSTUP_TEMPLATE.find(t => (t.nazev || '').toLowerCase() === (nazev || '').toLowerCase() && (t.popis || '').trim() !== '');
        if (tplMatched) popis = tplMatched.popis;
      }
    }
    return { ...k, nazev, popis, ccp };
  });
}

window.otevritHaccpEditor = async function(id, options) {
  options = options || {};
  let v;
  try { v = await api(`admin_vyrobky.php?id=${id}`); }
  catch (e) { return alert('Chyba: ' + e.message); }

  let hd = {};
  try { hd = v.haccp_data ? (typeof v.haccp_data === 'string' ? JSON.parse(v.haccp_data) : v.haccp_data) : {}; } catch (e) {}

  // Načti grafy (pokud nejsou v cache) — potřebujeme kroky pro auto-fill popisů
  if (!Array.isArray(haccpState.grafy) || haccpState.grafy.length === 0) {
    try { haccpState.grafy = await api('admin_haccp_grafy.php'); }
    catch (e) { haccpState.grafy = []; }
  }

  // Inicializace tabů a struktur
  haccpState.editor = {
    vyrobek: v,
    data: hd,
    sub_tab: 'zaklad',
    return_to: options.returnTo || null, // 'vyrobek' = vrátí se do editoru výrobku
  };

  // Najdi přiřazený graf (pro source kroků)
  let grafKroky = [];
  if (v && v.haccp_graf_id) {
    const graf = (haccpState.grafy || []).find(g => g.id === parseInt(v.haccp_graf_id));
    if (graf) {
      // Načti detail (graf.kroky může být null v list endpointu)
      let krokyArr = Array.isArray(graf.kroky) ? graf.kroky : null;
      if (!krokyArr) {
        try {
          const fullGraf = await api(`admin_haccp_grafy.php?id=${graf.id}`);
          krokyArr = fullGraf?.kroky || [];
        } catch (e) { krokyArr = []; }
      }
      grafKroky = krokyArr || [];
    }
  }

  // Doplň prázdné sekce
  if (!Array.isArray(hd.postup) || hd.postup.length === 0) {
    // Použij graf kroky (pokud jsou) nebo univerzální TEMPLATE
    hd.postup = grafKroky.length > 0
      ? JSON.parse(JSON.stringify(grafKroky))
      : JSON.parse(JSON.stringify(HACCP_POSTUP_TEMPLATE));
  }
  // Auto-doplnění popisů (i u existujícího postupu, kde byly prázdné)
  hd.postup = haccpFillEmptyPopisy(hd.postup, grafKroky);

  if (!Array.isArray(hd.kriticke_body) || hd.kriticke_body.length === 0) hd.kriticke_body = JSON.parse(JSON.stringify(HACCP_KB_TEMPLATE));
  if (!hd.jakost) hd.jakost = { vzhled: '', tvar: '', vune: '', chut: '', struktura: '' };
  if (!hd.mikrobio) hd.mikrobio = '';

  haccpRenderEditor();
};

function haccpRenderEditor() {
  const ed = haccpState.editor;
  if (!ed) return;
  const v = ed.vyrobek;
  const hd = ed.data;
  const def = haccpState.defaults || {};
  const tab = ed.sub_tab;

  const valOf = (k) => hd[k] !== undefined && hd[k] !== '' ? hd[k] : '';
  const placeholder = (f) => {
    if (def[f.k]) return def[f.k];
    if (f.srcDefault === 'nazev') return v.nazev || '';
    if (f.srcDefault === 'kategorie_nazev') return v.kategorie_nazev || '';
    if (f.srcDefault === 'popis') return v.popis || '';
    return f.placeholder || '';
  };

  let body = '';
  if (tab === 'zaklad') {
    body = `
      <div style="background:#EFF6FF;border:1px solid #B5D4F4;border-radius:8px;padding:10px 12px;margin-bottom:14px;font-size:12px;color:#0C447C">
        💡 Pole nech prázdná — použije se default z Defaultních hodnot, případně z výrobku (název, popis, kategorie).
      </div>
      <div class="form-grid">
        ${HACCP_FIELDS.map(f => `
          <div class="full">
            <label class="form-label">${esc(f.l)}</label>
            ${f.type === 'textarea'
              ? `<textarea class="form-textarea" id="hv-${f.k}" rows="2" placeholder="${esc(placeholder(f))}" oninput="haccpFieldUpdate('${f.k}', this.value)">${esc(valOf(f.k))}</textarea>`
              : `<input class="form-input" id="hv-${f.k}" value="${esc(valOf(f.k))}" placeholder="${esc(placeholder(f))}" oninput="haccpFieldUpdate('${f.k}', this.value)">`}
          </div>
        `).join('')}
      </div>
    `;
  } else if (tab === 'postup') {
    body = `
      <div style="background:#F7F8FA;border:1px solid #E8D5B0;border-radius:8px;padding:10px 12px;margin-bottom:14px;font-size:12px;color:#854F0B">
        🔄 Posloupnost výrobních operací. Označ kroky jako CCP (Kritický kontrolní bod) — typicky pečení.
      </div>
      <div class="vk-rows" style="margin-bottom:10px">
        ${hd.postup.map((p, i) => `
          <div class="vk-row" style="grid-template-columns:34px minmax(0, 1.5fr) minmax(0, 2fr) 90px 32px;align-items:center">
            <div style="text-align:center;font-weight:700;color:var(--text-3);font-family:monospace">${i + 1}.</div>
            <div>
              <label class="vk-label">Operace</label>
              <input class="form-input" value="${esc(p.nazev || '')}" oninput="haccpPostupUpdate(${i}, 'nazev', this.value)">
            </div>
            <div>
              <label class="vk-label">Popis / parametry (teplota, čas)</label>
              <input class="form-input" value="${esc(p.popis || '')}" oninput="haccpPostupUpdate(${i}, 'popis', this.value)" placeholder="např. 230 °C, 18 min">
            </div>
            <div>
              <label class="vk-label">CCP</label>
              <label class="checkbox-row" style="padding-top:8px;font-size:12px"><input type="checkbox" ${p.ccp ? 'checked' : ''} onchange="haccpPostupUpdate(${i}, 'ccp', this.checked)"> kritický</label>
            </div>
            <div><button class="btn-danger" onclick="haccpPostupRemove(${i})" title="Smazat" style="padding:7px 10px">×</button></div>
          </div>
        `).join('')}
      </div>
      <div style="display:flex;gap:6px;flex-wrap:wrap">
        <button class="btn-secondary" onclick="haccpPostupAdd()">+ Přidat krok</button>
        <button class="btn-secondary" onclick="haccpPostupFillPopisy()" title="Doplní prázdné popisy z přiřazeného grafu nebo univerzálního pekařského šablonu">✨ Doplnit popisy</button>
        <button class="btn-secondary" onclick="haccpPostupReset()" title="Vrátit šablonu pro pekařství">↺ Šablona pekařství</button>
      </div>
    `;
  } else if (tab === 'kb') {
    body = `
      <div style="background:#FEF3C7;border:1px solid #FCD34D;border-radius:8px;padding:10px 12px;margin-bottom:14px;font-size:12px;color:#92400e">
        ⚠ Analýza nebezpečí dle HACCP. <strong>Typ:</strong> B=biologické, CH=chemické, F=fyzikální. <strong>Riziko:</strong> N=nízké, M=střední, V=vysoké, S=stredni. <strong>CCP</strong>=kritický kontrolní bod, <strong>CP</strong>=kontrolní bod.
      </div>
      <div style="overflow-x:auto">
        <table class="table" style="font-size:12px;margin:0;min-width:780px">
          <thead>
            <tr>
              <th style="width:140px">Operace</th>
              <th style="width:50px">Typ</th>
              <th>Popis nebezpečí</th>
              <th>Ovládací opatření</th>
              <th style="width:60px">Riziko</th>
              <th style="width:60px">CCP/CP</th>
              <th style="width:32px"></th>
            </tr>
          </thead>
          <tbody>
            ${hd.kriticke_body.map((kb, i) => `
              <tr>
                <td><input class="form-input" value="${esc(kb.krok || '')}" oninput="haccpKbUpdate(${i}, 'krok', this.value)" style="font-size:12px"></td>
                <td>
                  <select class="form-input" onchange="haccpKbUpdate(${i}, 'typ', this.value)" style="font-size:12px">
                    ${['B','CH','F'].map(t => `<option value="${t}" ${kb.typ === t ? 'selected' : ''}>${t}</option>`).join('')}
                  </select>
                </td>
                <td><textarea class="form-input" rows="2" oninput="haccpKbUpdate(${i}, 'popis', this.value)" style="font-size:12px;font-family:inherit;resize:vertical">${esc(kb.popis || '')}</textarea></td>
                <td><textarea class="form-input" rows="2" oninput="haccpKbUpdate(${i}, 'opatreni', this.value)" style="font-size:12px;font-family:inherit;resize:vertical">${esc(kb.opatreni || '')}</textarea></td>
                <td>
                  <select class="form-input" onchange="haccpKbUpdate(${i}, 'riziko', this.value)" style="font-size:12px">
                    ${['N','M','S','V'].map(r => `<option value="${r}" ${kb.riziko === r ? 'selected' : ''}>${r}</option>`).join('')}
                  </select>
                </td>
                <td>
                  <select class="form-input" onchange="haccpKbUpdate(${i}, 'ccp', this.value)" style="font-size:12px">
                    ${['CP','CCP'].map(c => `<option value="${c}" ${kb.ccp === c ? 'selected' : ''}>${c}</option>`).join('')}
                  </select>
                </td>
                <td><button class="btn-danger" onclick="haccpKbRemove(${i})" style="padding:5px 8px;font-size:12px">×</button></td>
              </tr>
            `).join('')}
          </tbody>
        </table>
      </div>
      <div style="display:flex;gap:6px;flex-wrap:wrap;margin-top:10px">
        <button class="btn-secondary" onclick="haccpKbAdd()">+ Přidat řádek</button>
        <button class="btn-secondary" onclick="haccpKbReset()">↺ Šablona pekařství</button>
      </div>
    `;
  } else if (tab === 'jakost') {
    const j = hd.jakost || {};
    body = `
      <div style="background:#F0FDF4;border:1px solid #86EFAC;border-radius:8px;padding:10px 12px;margin-bottom:14px;font-size:12px;color:#166534">
        ⭐ Senzorické a jakostní parametry — popis výrobku, jak má vypadat, vonět a chutnat.
      </div>
      <div class="form-grid">
        <div class="full">
          <label class="form-label">Vzhled</label>
          <input class="form-input" id="hj-vzhled" value="${esc(j.vzhled || '')}" placeholder="zlatohnědá kůrka, lesklý povrch…" oninput="haccpJakostUpdate('vzhled', this.value)">
        </div>
        <div class="full">
          <label class="form-label">Tvar / hmotnost</label>
          <input class="form-input" id="hj-tvar" value="${esc(j.tvar || '')}" placeholder="oválný, 90 g (±5 %)…" oninput="haccpJakostUpdate('tvar', this.value)">
        </div>
        <div class="full">
          <label class="form-label">Vůně</label>
          <input class="form-input" id="hj-vune" value="${esc(j.vune || '')}" placeholder="příjemná, typická pro pšeničné pečivo, bez cizích pachů" oninput="haccpJakostUpdate('vune', this.value)">
        </div>
        <div class="full">
          <label class="form-label">Chuť</label>
          <input class="form-input" id="hj-chut" value="${esc(j.chut || '')}" placeholder="charakteristická, mírně slaná, bez cizí chuti" oninput="haccpJakostUpdate('chut', this.value)">
        </div>
        <div class="full">
          <label class="form-label">Struktura / konzistence</label>
          <input class="form-input" id="hj-struktura" value="${esc(j.struktura || '')}" placeholder="střídka jemně dírkovaná, pružná" oninput="haccpJakostUpdate('struktura', this.value)">
        </div>
        <div class="full">
          <label class="form-label">Mikrobiologické požadavky <span style="color:var(--text-3);font-weight:400;font-size:11px">(volitelné)</span></label>
          <textarea class="form-textarea" id="hj-mikrobio" rows="3" placeholder="dle nařízení (ES) 2073/2005…" oninput="haccpJakostUpdate('mikrobio', this.value)">${esc(hd.mikrobio || '')}</textarea>
        </div>
      </div>
    `;
  }

  openModal(`📋 HACCP karta: ${esc(v.nazev)}`, `
    ${ed.return_to === 'vyrobek' ? `
      <div style="display:flex;justify-content:flex-start;margin-bottom:10px">
        <button class="btn-back" onclick="haccpZpetNaVyrobek(${v.id})" title="Zavře HACCP a vrátí se do editoru výrobku" style="font-size:13px">← Zpět na výrobek</button>
      </div>
    ` : ''}

    <div class="haccp-subtabs">
      <button class="period-tab ${tab === 'zaklad' ? 'active' : ''}" onclick="haccpSetSubTab('zaklad')">📋 Základní</button>
      <button class="period-tab ${tab === 'postup' ? 'active' : ''}" onclick="haccpSetSubTab('postup')">🔄 Výrobní postup</button>
      <button class="period-tab ${tab === 'kb' ? 'active' : ''}" onclick="haccpSetSubTab('kb')">⚠️ Kritické body</button>
      <button class="period-tab ${tab === 'jakost' ? 'active' : ''}" onclick="haccpSetSubTab('jakost')">⭐ Jakost</button>
    </div>

    <div style="margin-top:14px">
      ${body}
    </div>

    <div class="form-actions">
      ${ed.return_to === 'vyrobek' ? `<button class="btn-back" onclick="haccpZpetNaVyrobek(${v.id})" title="Zavře HACCP a vrátí se do editoru výrobku">← Zpět na výrobek</button>` : ''}
      <a href="../api/vyrobek_haccp.php?id=${v.id}" target="_blank" class="btn-secondary" style="text-decoration:none">📄 PDF</a>
      <button class="btn-secondary" onclick="window.open('../api/vyrobek_haccp.php?id=${v.id}&autoprint=1', '_blank')" title="Otevře tiskový dialog rovnou">🖨️ Tisk</button>
      <div style="flex:1"></div>
      <!-- "Zavřít" smazáno v v2.5.11 — × v rohu modal-card stačí (větší klikací plocha) -->
      <button class="btn-primary btn-green" onclick="ulozitHaccpData(${v.id})">💾 Uložit</button>
    </div>
  `, 'wide');
}

window.haccpZpetNaVyrobek = function(id) {
  closeModal();
  setTimeout(() => editVyrobek(id), 80);
};

window.haccpSetSubTab = function(t) {
  if (!haccpState.editor) return;
  haccpState.editor.sub_tab = t;
  haccpRenderEditor();
};

window.haccpFieldUpdate = function(k, v) {
  if (!haccpState.editor) return;
  haccpState.editor.data[k] = v;
};
window.haccpPostupUpdate = function(i, k, v) {
  if (!haccpState.editor?.data?.postup?.[i]) return;
  haccpState.editor.data.postup[i][k] = v;
};
window.haccpPostupAdd = function() {
  if (!haccpState.editor) return;
  haccpState.editor.data.postup.push({ nazev: '', popis: '', ccp: false });
  haccpRenderEditor();
};
window.haccpPostupRemove = function(i) {
  if (!haccpState.editor?.data?.postup) return;
  haccpState.editor.data.postup.splice(i, 1);
  haccpRenderEditor();
};
window.haccpPostupReset = async function() {
  if (!(await confirmDialog({ msg: 'Nahradit aktuální postup šablonou pro pekařství?', danger: false }))) return;
  haccpState.editor.data.postup = JSON.parse(JSON.stringify(HACCP_POSTUP_TEMPLATE));
  haccpRenderEditor();
};
window.haccpPostupFillPopisy = async function() {
  if (!haccpState.editor) return;
  const v = haccpState.editor.vyrobek;
  // Najdi přiřazený graf (potřebujeme jeho kroky včetně popisů)
  let grafKroky = [];
  if (v && v.haccp_graf_id) {
    const graf = (haccpState.grafy || []).find(g => g.id === parseInt(v.haccp_graf_id));
    if (graf) {
      let krokyArr = Array.isArray(graf.kroky) ? graf.kroky : null;
      if (!krokyArr || !krokyArr.some(k => (k.popis || '').trim() !== '')) {
        try {
          const fullGraf = await api(`admin_haccp_grafy.php?id=${graf.id}`);
          krokyArr = fullGraf?.kroky || [];
        } catch (e) {}
      }
      grafKroky = krokyArr || [];
    }
  }
  const before = JSON.stringify(haccpState.editor.data.postup);
  haccpState.editor.data.postup = haccpFillEmptyPopisy(haccpState.editor.data.postup, grafKroky);
  const after = JSON.stringify(haccpState.editor.data.postup);
  haccpRenderEditor();

  // Toast
  const t = document.createElement('div');
  const changed = before !== after;
  t.style.cssText = `position:fixed;bottom:24px;right:24px;background:${changed ? 'var(--success-bg)' : 'var(--surface-1)'};color:${changed ? 'var(--success-text)' : 'var(--text-2)'};padding:14px 22px;border-radius:8px;box-shadow:0 4px 12px rgba(0,0,0,0.15);font-size:14px;font-weight:600;z-index:9999;border:1px solid var(--border)`;
  t.textContent = changed ? '✓ Popisy doplněny' : 'Všechny popisy jsou už vyplněné';
  document.body.appendChild(t);
  setTimeout(() => t.remove(), 2200);
};
window.haccpKbUpdate = function(i, k, v) {
  if (!haccpState.editor?.data?.kriticke_body?.[i]) return;
  haccpState.editor.data.kriticke_body[i][k] = v;
};
window.haccpKbAdd = function() {
  haccpState.editor.data.kriticke_body.push({ krok: '', typ: 'B', popis: '', opatreni: '', riziko: 'M', ccp: 'CP' });
  haccpRenderEditor();
};
window.haccpKbRemove = function(i) {
  haccpState.editor.data.kriticke_body.splice(i, 1);
  haccpRenderEditor();
};
window.haccpKbReset = async function() {
  if (!(await confirmDialog({ msg: 'Nahradit aktuální tabulku šablonou pro pekařství?', danger: false }))) return;
  haccpState.editor.data.kriticke_body = JSON.parse(JSON.stringify(HACCP_KB_TEMPLATE));
  haccpRenderEditor();
};
window.haccpJakostUpdate = function(k, v) {
  if (!haccpState.editor) return;
  if (k === 'mikrobio') {
    haccpState.editor.data.mikrobio = v;
  } else {
    if (!haccpState.editor.data.jakost) haccpState.editor.data.jakost = {};
    haccpState.editor.data.jakost[k] = v;
  }
};

window.ulozitHaccpData = async function(id) {
  if (!haccpState.editor) return;
  // Cleanup — odstraní prázdná pole
  const data = JSON.parse(JSON.stringify(haccpState.editor.data));
  HACCP_FIELDS.forEach(f => { if (data[f.k] === '') delete data[f.k]; });
  try {
    await api('admin_vyrobky.php', {
      method: 'PUT',
      body: JSON.stringify({ id, haccp_data: data }),
    });
    closeModal();
    haccpState.editor = null;
    // Refresh view
    const reloaded = await api('admin_vyrobky.php');
    haccpState.vyrobky = (reloaded && Array.isArray(reloaded.vyrobky)) ? reloaded.vyrobky : [];
    haccpRender();
  } catch (e) { alert('Chyba: ' + e.message); }
};

