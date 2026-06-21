// =============================================================
// HACCP — produktové listy a další (samostatná sekce)
// =============================================================
const haccpState = {
  tab: 'karty',     // 'karty' | 'defaulty' | 'grafy' | 'dokumenty'
  vyrobky: [],
  defaults: {},
  grafy: [],         // pole HACCP šablon (lazy načítáno)
  graf_editor: null, // { id, data: { nazev, popis, suroviny:[], kroky:[] } }
  dokumenty: null,   // { kategorie: [...], dokumenty: [...] } — lazy
  dok_aktivni_id: null,    // ID právě otevřeného dokumentu
  dok_aktivni_obsah: null, // detail editovaného dokumentu (s obsahem)
  audity: [],        // pole interních HACCP auditů (rok, auditor, ...)
  filter_q: '',
  filter_kat: '',
  filter_abc: '',     // 🆕 v3.0.277 — abecední rozsah (např. 'A-B') pro 1000+ výrobků
  shown_karty: 30,    // 🆕 v3.0.277 — kolik karet zobrazit (load-more)
  vybrany_id: null,
};

// 🆕 v3.0.277 — normalizace první písmene (diakritika pryč: Č→C, Ř→R, Ž→Z, Á→A…) pro abecední filtr
const HACCP_DIA = { 'Á':'A','Ä':'A','À':'A','Â':'A','Č':'C','Ç':'C','Ć':'C','Ď':'D','É':'E','Ě':'E','Ë':'E','È':'E','Ê':'E','Í':'I','Ï':'I','Î':'I','Ľ':'L','Ĺ':'L','Ł':'L','Ň':'N','Ñ':'N','Ó':'O','Ö':'O','Ô':'O','Ő':'O','Ř':'R','Ŕ':'R','Š':'S','Ś':'S','Ť':'T','Ú':'U','Ů':'U','Ü':'U','Ű':'U','Ý':'Y','Ž':'Z','Ź':'Z','Ż':'Z' };
function haccpFirstLetter(s) {
  const t = (s || '').trim();
  if (!t) return '';
  const c = t.charAt(0).toUpperCase();
  return HACCP_DIA[c] || c;
}
const HACCP_ABC_RANGES = [['A','B'],['C','D'],['E','F'],['G','H'],['I','J'],['K','L'],['M','N'],['O','P'],['Q','R'],['S','T'],['U','V'],['W','Z']];
window.haccpSetAbc = function(r) { haccpState.filter_abc = (haccpState.filter_abc === r) ? '' : r; haccpState.shown_karty = 30; haccpRender(); };
window.haccpShowMore = function() { haccpState.shown_karty = (haccpState.shown_karty || 30) + 30; haccpRender(); };

const HACCP_FIELDS = [
  { k: 'produkt',          l: 'Produkt (kategorie)',        type: 'text', placeholder: 'např. Běžné pečivo pšeničné', srcDefault: 'kategorie_nazev' },
  { k: 'obchodni_jmeno',   l: 'Obchodní jméno',              type: 'text', placeholder: 'název na obale', srcDefault: 'nazev' },
  { k: 'misto_vyroby',     l: 'Místo výroby',                type: 'text', placeholder: 'ČR' },
  { k: 'cilovy_trh',       l: 'Cílový trh',                  type: 'textarea', placeholder: 'kdo to bude prodávat / distribuovat' },
  { k: 'skupina',          l: 'Skupina',                     type: 'text', placeholder: 'pšeničné pečivo / chleba…', srcDefault: 'kategorie_nazev' },
  { k: 'popis_produktu',   l: 'Popis produktu',              type: 'textarea', srcDefault: 'popis' },
  { k: 'zpusob_uziti',     l: 'Způsob užití',                type: 'text', placeholder: 'k přímé konzumaci' },
  { k: 'baleni',           l: 'Balení',                      type: 'text', placeholder: 'nebalený / balený / sáček…' },
  { k: 'trvanlivost',      l: 'Doba minimální trvanlivosti', type: 'text', placeholder: '24 hodin od doby výroby' },
  { k: 'skladovani',       l: 'Skladování',                  type: 'text', placeholder: 'do 25 °C, suché místo' },
  { k: 'distribuce',       l: 'Podmínky a způsob distribuce',type: 'textarea', placeholder: 'výrobek určen pro prodej…' },
  { k: 'omezeni',          l: 'Omezení spotřebitelů',        type: 'text', placeholder: 'bez omezení (nevhodné pro diabetiky, coeliky)' },
];

async function renderHaccp() {
  const c = document.getElementById('content');
  c.innerHTML = '<div style="padding:40px;text-align:center;color:var(--text-3)">Načítám…</div>';
  // Načti vyrobky, nastavení a grafy paralelně
  try {
    const [vList, ns, grafy] = await Promise.all([
      api('admin_vyrobky.php'),
      api('admin_nastaveni.php'),
      api('admin_haccp_grafy.php').catch(() => []),
    ]);
    haccpState.vyrobky = (vList && Array.isArray(vList.vyrobky)) ? vList.vyrobky : [];
    haccpState.kategorie = (vList && Array.isArray(vList.kategorie)) ? vList.kategorie : [];
    try { haccpState.defaults = ns.haccp_defaults ? JSON.parse(ns.haccp_defaults) : {}; }
    catch (e) { haccpState.defaults = {}; }
    haccpState.grafy = Array.isArray(grafy) ? grafy : [];
  } catch (e) { return alert('Chyba: ' + e.message); }
  haccpRender();
}

function haccpRender() {
  const c = document.getElementById('content');
  c.innerHTML = `
    <div class="page-head">
      <div>
        <h1 class="page-title">🛡️ HACCP</h1>
        <p class="page-sub">Produktové karty, plán kritických bodů a dokumentace</p>
      </div>
      <div style="display:flex;gap:8px;flex-wrap:wrap">
        <button class="btn-secondary" onclick="navigate('vyroba')">← Výroba</button>
      </div>
    </div>

    <div class="card-block" style="padding:8px;margin-bottom:14px">
      <div style="display:flex;gap:6px;flex-wrap:wrap">
        <button class="period-tab ${haccpState.tab === 'karty' ? 'active' : ''}" onclick="haccpSetTab('karty')"><span class="period-tab-icon">📋</span><span class="period-tab-text">Produktové karty</span></button>
        <button class="period-tab ${haccpState.tab === 'grafy' ? 'active' : ''}" onclick="haccpSetTab('grafy')"><span class="period-tab-icon">📈</span><span class="period-tab-text">Grafy (šablony postupu)</span></button>
        <button class="period-tab ${haccpState.tab === 'dokumenty' ? 'active' : ''}" onclick="haccpSetTab('dokumenty')"><span class="period-tab-icon">📚</span><span class="period-tab-text">Dokumentace firmy</span></button>
        <button class="period-tab ${haccpState.tab === 'defaulty' ? 'active' : ''}" onclick="haccpSetTab('defaulty')">⚙️ Defaultní hodnoty</button>
      </div>
    </div>

    ${haccpState.tab === 'karty' ? haccpRenderKarty()
      : haccpState.tab === 'grafy' ? haccpRenderGrafy()
      : haccpState.tab === 'dokumenty' ? haccpRenderDokumenty()
      : haccpRenderDefaulty()}
  `;
}

function haccpRenderKarty() {
  const q = haccpState.filter_q.toLowerCase();
  const kat = haccpState.filter_kat;
  const abcRange = haccpState.filter_abc ? haccpState.filter_abc.split('-') : null; // 🆕 v3.0.277
  const list = haccpState.vyrobky.filter(v => {
    if (parseInt(v.aktivni) !== 1) return false;
    if (kat && v.kategorie_id != kat) return false;
    if (q && !((v.nazev || '').toLowerCase().includes(q) || (v.cislo || '').toString().toLowerCase().includes(q))) return false;
    if (abcRange) { const fl = haccpFirstLetter(v.nazev); if (!(fl >= abcRange[0] && fl <= abcRange[1])) return false; }
    return true;
  });
  // 🆕 v3.0.277 — paginace (load-more) — ať se nenačítá 1400+ řádků naráz
  const totalFiltered = list.length;
  const shown = Math.min(haccpState.shown_karty || 30, totalFiltered);
  const pageList = list.slice(0, shown);
  const abcChips = `<div style="display:flex;gap:4px;flex-wrap:wrap;margin:0 0 10px">
    <button onclick="haccpSetAbc('')" class="period-tab ${!haccpState.filter_abc ? 'active' : ''}" style="padding:4px 10px;font-size:12px">Vše</button>
    ${HACCP_ABC_RANGES.map(([a, b]) => { const r = a + '-' + b; return `<button onclick="haccpSetAbc('${r}')" class="period-tab ${haccpState.filter_abc === r ? 'active' : ''}" style="padding:4px 10px;font-size:12px">${a}–${b}</button>`; }).join('')}
  </div>`;
  const loadMoreHtml = shown < totalFiltered
    ? `<div style="text-align:center;padding:14px"><button class="btn-secondary" onclick="haccpShowMore()" style="font-weight:700;padding:10px 22px;border-radius:10px">▾ Načíst další (${Math.min(30, totalFiltered - shown)})</button><div style="font-size:12px;color:var(--text-3);margin-top:6px">Zobrazeno ${shown} / ${totalFiltered}</div></div>`
    : (totalFiltered > 0 ? `<div style="text-align:center;padding:10px;font-size:12px;color:var(--text-3)">Zobrazeno všech ${totalFiltered}</div>` : '');

  const grafy = haccpState.grafy || [];
  // 🆕 v3.0.221 — coverage indikátor (kolik aktivních výrobků má přiřazený HACCP graf)
  const aktivni = (haccpState.vyrobky || []).filter(v => parseInt(v.aktivni) === 1);
  const sGrafem = aktivni.filter(v => parseInt(v.haccp_graf_id) > 0).length;
  const bezGrafu = aktivni.length - sGrafem;
  const pct = aktivni.length ? Math.round(sGrafem / aktivni.length * 100) : 0;
  const covBadge = bezGrafu === 0
    ? `<span style="background:#DCFCE7;color:#166534;padding:3px 10px;border-radius:999px;font-size:12px;font-weight:700">✓ Všech ${aktivni.length} výrobků má HACCP graf (100 %)</span>`
    : `<span style="background:#FEE2E2;color:#991B1B;padding:3px 10px;border-radius:999px;font-size:12px;font-weight:700">⚠️ ${bezGrafu} z ${aktivni.length} výrobků bez HACCP grafu (${pct} % pokryto)</span>`;
  return `
    <div class="card-block" style="padding:10px 14px;margin-bottom:12px;background:linear-gradient(135deg,#FFF8E7,#FEF3C7);border:1px solid #F59E0B33;display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:8px">
      <div>
        <strong style="color:#92400e">🪄 Auto-vyplnit HACCP karty</strong> ${covBadge}
        <div style="font-size:11px;color:var(--text-2);margin-top:4px">Automaticky doplní typ, skupinu, trvanlivost, popis, kritické body, jakost a mikrobio podle pravidel pekařské praxe (ČSN, EK 2073/2005). Existující ruční úpravy zachová. Nové výrobky dostanou HACCP automaticky.</div>
      </div>
      <button class="btn-primary" onclick="haccpAutofillPreview()" style="font-size:13px;background:#F59E0B;border-color:#F59E0B">🪄 Auto-vyplnit vše</button>
    </div>

    <div class="filters">
      <input class="filter-input" type="search" placeholder="Hledat (název, číslo)..." value="${esc(haccpState.filter_q)}" oninput="haccpState.filter_q=this.value;haccpRender()">
      <select class="filter-select" onchange="haccpState.filter_kat=this.value;haccpRender()">
        <option value="">Všechny kategorie</option>
        ${haccpState.kategorie.map(k => `<option value="${k.id}" ${haccpState.filter_kat == k.id ? 'selected' : ''}>${esc(k.ikona || '')} ${esc(k.nazev)}</option>`).join('')}
      </select>
    </div>

    ${abcChips}

    <div class="card-block" style="padding:0">
      ${totalFiltered === 0 ? '<div class="empty-state" style="padding:30px">Žádné výrobky</div>' : `
        <table class="table table-selectable">
          <thead>
            <tr>
              <th class="col-check"><input type="checkbox" id="hk-check-all" onchange="hkSelectAll(this.checked)" title="Vybrat vše"></th>
              <th>Kód</th>
              <th>Název</th>
              <th>Kategorie</th>
              <th>Graf</th>
              <th>HACCP karta</th>
              <th></th>
            </tr>
          </thead>
          <tbody>
            ${pageList.map(v => {
              let hd = {};
              try { hd = v.haccp_data ? (typeof v.haccp_data === 'string' ? JSON.parse(v.haccp_data) : v.haccp_data) : {}; } catch (e) {}
              const filledCount = HACCP_FIELDS.filter(f => hd[f.k] && String(hd[f.k]).trim() !== '').length;
              const total = HACCP_FIELDS.length;
              const graf_id = parseInt(v.haccp_graf_id) || 0;
              const grafSel = grafy.length === 0
                ? `<a onclick="event.stopPropagation();haccpSetTab('grafy')" style="font-size:11px;color:var(--brand);cursor:pointer">+ vytvoř šablony</a>`
                : `<select onchange="haccpVyrobekSetGraf(${v.id}, this.value)" onclick="event.stopPropagation()" class="form-input" style="font-size:11px;padding:3px 6px;height:auto;min-width:130px">
                     <option value="">— bez grafu —</option>
                     ${grafy.map(g => `<option value="${g.id}" ${graf_id === g.id ? 'selected' : ''}>${esc(g.nazev)}</option>`).join('')}
                   </select>`;
              const sel = (haccpState.selected || new Set()).has(v.id);
              return `
                <tr class="row-clickable ${sel ? 'is-selected' : ''}" onclick="otevritHaccpEditor(${v.id})">
                  <td class="col-check" onclick="event.stopPropagation();">
                    <input type="checkbox" ${sel ? 'checked' : ''} onchange="hkToggleSelect(${v.id}, this.checked)" data-hk-check="${v.id}">
                  </td>
                  <td>${esc(v.cislo || '')}</td>
                  <td><strong>${esc(v.nazev)}</strong></td>
                  <td>${esc((v.kategorie_ikona || '') + ' ' + (v.kategorie_nazev || ''))}</td>
                  <td>${grafSel}</td>
                  <td>
                    ${filledCount === 0
                      ? '<span style="color:var(--text-3);font-size:12px">— nevyplněno —</span>'
                      : `<span style="background:${filledCount === total ? '#DCFCE7' : '#FEF3C7'};color:${filledCount === total ? '#166534' : '#92400e'};padding:2px 8px;border-radius:10px;font-size:11px;font-weight:600">${filledCount}/${total} polí</span>`}
                  </td>
                  <td onclick="event.stopPropagation();" style="white-space:nowrap;text-align:right">
                    <button class="btn-secondary" onclick="otevritHaccpEditor(${v.id})" style="font-size:12px;padding:5px 10px">✏️ Upravit</button>
                    <a href="../api/vyrobek_haccp.php?id=${v.id}" target="_blank" class="btn-primary" style="text-decoration:none;font-size:12px;padding:5px 10px;display:inline-block;margin-left:4px">📄 Karta</a>
                  </td>
                </tr>
              `;
            }).join('')}
          </tbody>
        </table>
      `}
    </div>
    ${loadMoreHtml}

    <div id="hk-bulk-bar" class="bulk-bar" style="display:none;">
      <div class="bulk-bar-info">
        <span class="bulk-bar-count" id="hk-bulk-count">0</span>
        <span>vybráno</span>
      </div>
      <div class="bulk-bar-actions">
        <button class="btn-primary" onclick="hkBulkTisk()" title="Vytiskne všechny vybrané HACCP karty za sebou">🖨️ Tisk vybrané</button>
        <button class="btn-secondary" onclick="hkBulkExport()" title="Otevře všechny vybrané karty v jednom okně — vhodné pro Uložit jako PDF">📤 Export PDF</button>
        <button class="btn-secondary" onclick="hkBulkExportCsv()" title="Stáhne CSV souhrn všech polí HACCP karet (pro účetní / sklad)">📊 Export CSV</button>
        <button class="btn-link" onclick="hkClearSelection()">✕ Zrušit výběr</button>
      </div>
    </div>
  `;
}

