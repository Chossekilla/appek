// =============================================================
// QUICK PERIOD CHIPS — rychlé období pro filtry (DL, FA, …)
// =============================================================
// 🆕 v2.9.42 — Dashboard-style period tabs (Dnes / Týden / Měsíc / Rok / Vlastní)
// Sdílený helper pro Faktury + Dodací listy (jako na dashboardu)
function periodToRange(period) {
  const dnes = new Date();
  const f = (d) => d.toISOString().slice(0, 10);
  const today = f(dnes);
  if (period === 'dnes') return { od: today, do: today };
  if (period === 'tyden') {
    const monday = new Date(dnes);
    const wd = (monday.getDay() + 6) % 7;
    monday.setDate(monday.getDate() - wd);
    return { od: f(monday), do: today };
  }
  if (period === 'mesic') return { od: f(new Date(dnes.getFullYear(), dnes.getMonth(), 1)), do: today };
  if (period === 'rok')   return { od: f(new Date(dnes.getFullYear(), 0, 1)), do: today };
  return { od: '', do: '' }; // vse / vlastni
}

function rangeToPeriod(od, doDt) {
  if (!od && !doDt) return 'vse';
  const dnes = new Date();
  const f = (d) => d.toISOString().slice(0, 10);
  const today = f(dnes);
  const monday = new Date(dnes);
  monday.setDate(monday.getDate() - (monday.getDay() + 6) % 7);
  const mesicStart = f(new Date(dnes.getFullYear(), dnes.getMonth(), 1));
  const rokStart   = f(new Date(dnes.getFullYear(), 0, 1));

  if (od === today && doDt === today) return 'dnes';
  if (od === f(monday) && doDt === today) return 'tyden';
  if (od === mesicStart && doDt === today) return 'mesic';
  if (od === rokStart   && doDt === today) return 'rok';
  return 'vlastni';
}

function dashStylePeriodHtml(typ, datum_od, datum_do) {
  const currentPeriod = rangeToPeriod(datum_od, datum_do);
  // 🆕 v2.9.287 — short labels pro mobile (sjednoceno s Dashboard period-tabs)
  const tabs = [
    { k: 'dnes',    icon: '📅', l: 'Dnes',         short: 'Dnes' },
    { k: 'tyden',   icon: '📆', l: 'Tento týden',  short: 'Týden' },
    { k: 'mesic',   icon: '🗓️', l: 'Tento měsíc', short: 'Měsíc' },
    { k: 'rok',     icon: '📊', l: 'Tento rok',    short: 'Rok' },
    { k: 'vlastni', icon: '⚙️', l: 'Vlastní',      short: 'Vlastní' },
    { k: 'vse',     icon: '∞',  l: 'Vše',          short: 'Vše' },
  ];
  const isMob = typeof window !== 'undefined' && window.innerWidth <= 700;
  // 🆕 v3.0.97 — mobile = 1-letter (D/T/M/R/V) per user: "tady mělo být D T M R V jenom"
  const tabsHtml = tabs.map(t => {
    const label = isMob ? (t.x || (t.short || t.l || '').charAt(0).toUpperCase()) : t.l;
    return `<button class="period-tab ${currentPeriod === t.k ? 'active' : ''}" onclick="periodTabSet('${typ}', '${t.k}')"><span class="period-tab-icon">${t.icon}</span><span class="period-tab-text">${label}</span></button>`;
  }).join('');
  const customHtml = currentPeriod === 'vlastni' ? `
    <div class="period-custom" style="margin-top:10px">
      <label class="filter-date-wrap">
        <span>Od:</span>
        <input class="filter-input" type="date" id="period-${typ}-od" value="${datum_od || ''}">
      </label>
      <label class="filter-date-wrap">
        <span>Do:</span>
        <input class="filter-input" type="date" id="period-${typ}-do" value="${datum_do || ''}">
      </label>
      <button class="btn-secondary" onclick="periodTabApplyVlastni('${typ}')">Použít</button>
    </div>
  ` : '';
  return `<div class="period-tabs">${tabsHtml}</div>${customHtml}`;
}

window.periodTabSet = function(typ, period) {
  if (period === 'vlastni') {
    // Default: tento měsíc range, ať se otevře kalendář s rozumnými hodnotami
    const r = periodToRange('mesic');
    return _periodApply(typ, r.od, r.do, true);
  }
  if (period === 'vse') return _periodApply(typ, '', '', false);
  const r = periodToRange(period);
  _periodApply(typ, r.od, r.do, false);
};

window.periodTabApplyVlastni = function(typ) {
  const od = document.getElementById('period-' + typ + '-od')?.value || '';
  const doDt = document.getElementById('period-' + typ + '-do')?.value || '';
  if (od && doDt && od > doDt) return alert('Datum „od" musí být před „do"');
  _periodApply(typ, od, doDt, false);
};

function _periodApply(typ, od, doDt, openCustom) {
  if (typ === 'dl') {
    const elOd = document.getElementById('dlf-od');
    const elDo = document.getElementById('dlf-do');
    if (elOd) elOd.value = od;
    if (elDo) elDo.value = doDt;
    if (openCustom) {
      // jen re-render aby se ukázal kalendář
      renderDodaciListy({
        q: document.getElementById('dlf-q')?.value || '',
        fakturovano: document.getElementById('dlf-fakt')?.value || '',
        datum_od: od, datum_do: doDt,
      });
    } else {
      applyDlFilters();
    }
  } else if (typ === 'fa') {
    const elOd = document.getElementById('ff-od');
    const elDo = document.getElementById('ff-do');
    if (elOd) elOd.value = od;
    if (elDo) elDo.value = doDt;
    if (openCustom) {
      renderFaktury({
        q: document.getElementById('ff-q')?.value || '',
        stav_uhrady: document.getElementById('ff-stav')?.value || '',
        datum_od: od, datum_do: doDt,
      });
    } else {
      applyFakturyFilters();
    }
  } else if (typ === 'obj') {
    const elOd = document.getElementById('of-od');
    const elDo = document.getElementById('of-do');
    if (elOd) elOd.value = od;
    if (elDo) elDo.value = doDt;
    if (openCustom) {
      renderObjednavky({
        q: document.getElementById('of-q')?.value || '',
        stav: document.getElementById('of-stav')?.value || '',
        datum_od: od, datum_do: doDt,
      });
    } else {
      applyObjFilters();
    }
  }
}

function quickPeriodChips(typ, datum_od, datum_do) {
  const dnes = new Date();
  const fmt = (d) => d.toISOString().slice(0, 10);

  // Sestav rozsahy
  const today = new Date(dnes);
  // Posledních 7 dní (vč. dneška)
  const last7 = new Date(dnes); last7.setDate(last7.getDate() - 6);
  // Tento týden — od pondělí do dneška
  const tydenStart = new Date(dnes);
  const wd = (tydenStart.getDay() + 6) % 7; // 0 = pondělí
  tydenStart.setDate(tydenStart.getDate() - wd);
  // Posledních 10 dní (dekáda)
  const last10 = new Date(dnes); last10.setDate(last10.getDate() - 9);
  // Tento měsíc — od 1. do dnes
  const mesicStart = new Date(dnes.getFullYear(), dnes.getMonth(), 1);
  // Posledních 30 dní
  const last30 = new Date(dnes); last30.setDate(last30.getDate() - 29);

  const chips = [
    { k: 'today', l: 'Dnes',           od: fmt(today),       do: fmt(today) },
    { k: '7d',    l: '7 dní',          od: fmt(last7),       do: fmt(today) },
    { k: 'tyden', l: 'Tento týden',    od: fmt(tydenStart),  do: fmt(today) },
    { k: '10d',   l: 'Dekáda (10 dní)',od: fmt(last10),      do: fmt(today) },
    { k: 'mesic', l: 'Tento měsíc',    od: fmt(mesicStart),  do: fmt(today) },
    { k: '30d',   l: '30 dní',         od: fmt(last30),      do: fmt(today) },
  ];

  return chips.map(c => {
    const active = datum_od === c.od && datum_do === c.do;
    return `
      <button class="qp-chip ${active ? 'is-active' : ''}" onclick="quickPeriodSet('${typ}', '${c.od}', '${c.do}')">
        <span class="qp-check">${active ? '✓' : '☐'}</span>
        <span>${c.l}</span>
      </button>
    `;
  }).join('') + `
    <button class="qp-chip qp-clear ${!datum_od && !datum_do ? 'is-active' : ''}" onclick="quickPeriodSet('${typ}', '', '')" title="Bez filtrace období">
      <span class="qp-check">${(!datum_od && !datum_do) ? '✓' : '☐'}</span>
      <span>Vše</span>
    </button>
  `;
}

window.quickPeriodSet = function(typ, od, doDt) {
  if (typ === 'dl') {
    const elOd = document.getElementById('dlf-od');
    const elDo = document.getElementById('dlf-do');
    if (elOd) elOd.value = od;
    if (elDo) elDo.value = doDt;
    applyDlFilters();
  } else if (typ === 'fa') {
    const elOd = document.getElementById('ff-od');
    const elDo = document.getElementById('ff-do');
    if (elOd) elOd.value = od;
    if (elDo) elDo.value = doDt;
    applyFakturyFilters();
  } else if (typ === 'obj') {
    const elOd = document.getElementById('of-od');
    const elDo = document.getElementById('of-do');
    if (elOd) elOd.value = od;
    if (elDo) elDo.value = doDt;
    applyObjFilters();
  }
};

window.applyDlFilters = function() {
  renderDodaciListy({
    q: document.getElementById('dlf-q').value,
    fakturovano: document.getElementById('dlf-fakt').value,
    datum_od: document.getElementById('dlf-od').value,
    datum_do: document.getElementById('dlf-do').value,
  });
};

window.openDodaciListDetail = async function(id) {
  try {
    const dl = await api(`admin_dodaci_listy.php?id=${id}`);

    const fakturyHtml = dl.faktury.length === 0
      ? '<span style="color:var(--danger-text);font-weight:500">Zatím nefakturováno</span>'
      : dl.faktury.map((f) =>
          `<a href="#" onclick="closeModal();openFakturaDetail(${f.id});return false" class="doc-badge fa">💰 ${esc(f.cislo)} · ${fmt(f.castka_celkem)}</a>`
        ).join(' ');

    openModal(`📃 Dodací list ${dl.cislo}`, `
      <!-- HLAVIČKA: amber Odběratel + blue Místo dodání -->
      <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px;margin-bottom:14px">
        <div style="background:#F7F8FA;border:1px solid #E8D5B0;border-radius:8px;padding:12px 14px">
          <div style="font-size:11px;text-transform:uppercase;letter-spacing:0.5px;color:#854F0B;margin-bottom:6px;font-weight:600">🏢 Odběratel</div>
          <div style="font-size:20px;font-weight:700">${esc(dl.odberatel_nazev)}</div>
          ${dl.odberatel_ico ? `<div style="font-size:13px;color:#854F0B;margin-top:2px">IČO: ${esc(dl.odberatel_ico)}${dl.odberatel_dic ? ' · DIČ: ' + esc(dl.odberatel_dic) : ''}</div>` : ''}
        </div>
        <div style="background:#EFF6FF;border:1px solid #B5D4F4;border-radius:8px;padding:12px 14px">
          <div style="font-size:11px;text-transform:uppercase;letter-spacing:0.5px;color:#0C447C;margin-bottom:6px;font-weight:600">📍 Místo dodání</div>
          ${dl.misto_nazev ? `<div style="font-size:20px;font-weight:700">${esc(dl.misto_nazev)}</div>` : '<div style="font-size:13px;color:#0C447C">Bez konkrétní pobočky</div>'}
          ${(dl.misto_ulice || dl.misto_mesto) ? `<div style="font-size:13px;color:#0C447C;margin-top:2px">${[dl.misto_ulice, dl.misto_psc, dl.misto_mesto].filter(Boolean).map(esc).join(', ')}</div>` : ''}
        </div>
      </div>

      <!-- 3 sloupce: Datum vystavení · Datum dodání · Stav -->
      <div class="form-grid form-grid-tight" style="grid-template-columns:1fr 1fr 1fr;margin-bottom:14px">
        <div>
          <label class="form-label">Datum vystavení</label>
          <input type="text" class="form-input" value="${fmtDate(dl.datum_vystaveni)}" disabled style="color:var(--text-3)">
        </div>
        <div>
          <label class="form-label">Datum dodání</label>
          <input type="text" class="form-input" value="${fmtDate(dl.datum_dodani)}" disabled style="color:var(--text-3)">
        </div>
        <div>
          <label class="form-label">Stav</label>
          <div style="padding:7px 12px;background:var(--surface);border:1px solid var(--border);border-radius:6px">${dlStavBadge(dl)}</div>
        </div>
      </div>

      <!-- Vazby na zdroj a fakturu -->
      <div style="display:flex;gap:10px;flex-wrap:wrap;align-items:center;margin-bottom:14px;padding:10px 14px;background:#F7F8FA;border:1px solid var(--border);border-radius:8px">
        <span style="font-size:12px;color:var(--text-3);font-weight:500">🛒 Zdroj:</span>
        ${dl.objednavka_id
          ? `<a href="#" onclick="closeModal();openObjednavkaDetail(${dl.objednavka_id});return false" class="doc-badge obj">🛒 ${esc(dl.objednavka_cislo)}</a>`
          : `<span class="doc-badge rucni">✏️ Ruční DL</span>`}
        <span style="font-size:12px;color:var(--text-3);font-weight:500;margin-left:12px">💰 Faktura:</span>
        <span>${fakturyHtml}</span>
      </div>

      ${dl.poznamka ? `<div style="margin-bottom:14px;padding:10px 14px;background:#F7F8FA;border-left:3px solid #BA7517;border-radius:4px;font-size:13px"><strong>📝 Poznámka:</strong> ${esc(dl.poznamka)}</div>` : ''}

      <h3 class="modal-section-title">📦 Položky <span style="font-size:11px;color:var(--text-3);font-weight:400">(${dl.polozky.length})</span></h3>
      <div class="obj-polozky-list">
        ${dl.polozky.length === 0 ? '<div class="empty-state" style="padding:24px;background:var(--surface-2);border-radius:8px">Žádné položky.</div>' : dl.polozky.map((p) => {
          const celkem = parseFloat(p.mnozstvi) * parseFloat(p.cena_bez_dph);
          return `
            <div class="obj-polozka-row obj-polozka-readonly">
              <div class="obj-polozka-img">${p.obrazek_url ? `<img src="${esc(p.obrazek_url)}" alt="">` : '<div class="obj-polozka-img-empty">🥖</div>'}</div>
              <div class="obj-polozka-info">
                <div class="obj-polozka-nazev">${esc(p.vyrobek_nazev)}</div>
                <div class="obj-polozka-meta">${p.vyrobek_cislo ? 'kód ' + esc(p.vyrobek_cislo) + ' · ' : ''}${fmt(p.cena_bez_dph)} / ${esc(p.jednotka || 'ks')}</div>
              </div>
              <div class="obj-polozka-qty obj-polozka-qty-static">${parseFloat(p.mnozstvi)} ${esc(p.jednotka || 'ks')}</div>
              <div class="obj-polozka-cena">${fmt(celkem)}</div>
            </div>
          `;
        }).join('')}
      </div>

      <div style="margin-top:14px;background:#FAEEDA;border:2px solid #E5C499;border-radius:10px;padding:16px 22px">
        <div style="display:flex;justify-content:space-between;align-items:baseline;font-size:22px;font-weight:700;color:#854F0B">
          <span>Celkem</span><span>${fmt(dl.castka_celkem)}</span>
        </div>
        <div style="display:flex;justify-content:space-between;font-size:12px;color:#854F0B;margin-top:6px;border-top:1px dashed #E5C499;padding-top:6px">
          <span>📅 Datum dodání</span><span><strong>${fmtDate(dl.datum_dodani)}</strong></span>
        </div>
      </div>

      <div class="form-actions">
        <div class="form-actions-tools">
          <button class="btn-secondary" onclick="noOpakovatZeZdroje('dl', ${dl.id})" title="Vytvořit novou objednávku se stejnými položkami">🔁 Znovu objednat</button>
          <a href="../api/admin_export_dl.php?action=csv-detail&id=${dl.id}" class="btn-secondary" style="text-decoration:none" title="Stáhnout CSV s položkami pro Money / Pohoda / Excel">📊 CSV export</a>
          <button class="btn-secondary" onclick='tiskDodaciList(${JSON.stringify({id: dl.id, objednavka_id: dl.objednavka_id || null})})' title="Otevře tiskový dialog (PDF / tiskárna)">🖨️ Tisk</button>
          <button class="btn-secondary" onclick="printQueue.add({type:'dl', id: ${dl.id}, label: 'DL ${esc(dl.cislo || '#'+dl.id)}'})" title="Přidat do fronty pro hromadný tisk">➕🖨️</button>
          <button class="btn-secondary" onclick="closeModal(); upravitDodaciList(${dl.id})">✏️ Upravit</button>
        </div>
        <div class="form-actions-icons-row">
          <button class="btn-icon-corner" onclick="tiskNaTermo('dl', ${dl.id}, '${esc(dl.cislo).replace(/'/g, '')}')" title="Tisk na termo-tiskárnu (účtenka / bon)" aria-label="Tisk na tiskárnu">🖨️</button>
          <button class="btn-icon-corner" onclick="otevritOdeslatEmailem('dl', ${dl.id}, '${esc(dl.cislo).replace(/'/g, '')}', '${esc(dl.odberatel_email || '').replace(/'/g, '')}')" title="Odeslat dodací list PDF emailem" aria-label="Odeslat e-mailem">✉️</button>
          ${adminOnly(`<button class="btn-danger-corner" onclick="smazatDodaciList(${dl.id})" title="Smazat dodací list" aria-label="Smazat dodací list">🗑️</button>`)}
        </div>
        <!-- "Zavřít" smazáno v v2.5.11 — × v rohu modal-card stačí (větší klikací plocha) -->
        <div style="flex:1"></div>
        <a href="${dlPdfUrl(dl)}" target="_blank" class="btn-secondary" style="text-decoration:none">📃 PDF</a>
        ${dl.faktury.length > 0
          ? `<button class="btn-primary" onclick="closeModal();openFakturaDetail(${dl.faktury[0].id})">💰 Otevřít fakturu ${esc(dl.faktury[0].cislo)}</button>`
          : `<button class="btn-primary btn-green" onclick="vytvoritFakturuZDL(${dl.id}, ${dl.objednavka_id || 'null'})">💰 Vystavit fakturu</button>`}
      </div>
    `, 'wide');
  } catch (e) {
    alert('Chyba: ' + e.message);
  }
};

// Upravit existující dodací list
window.upravitDodaciList = async function(id) {
  try {
    const dl = await api(`admin_dodaci_listy.php?id=${id}`);
    const [odberatele, pobocky] = await Promise.all([
      api('admin_odberatele.php'),
      dl.odberatel_id ? api(`mista_dodani.php?odberatel_id=${dl.odberatel_id}`) : []
    ]);
    rdlState = {
      editId: id,
      odberatel_id: dl.odberatel_id,
      odberatel: dl.odberatel_nazev,
      misto_dodani_id: dl.misto_dodani_id || null,
      datum_vystaveni: dl.datum_vystaveni,
      datum_dodani: dl.datum_dodani,
      poznamka: dl.poznamka || '',
      polozky: dl.polozky.map(p => ({
        vyrobek_id: p.vyrobek_id,
        vyrobek_nazev: p.vyrobek_nazev,
        vyrobek_cislo: p.vyrobek_cislo || '',
        jednotka: p.jednotka || 'ks',
        mnozstvi: parseFloat(p.mnozstvi) || 0,
        cena_bez_dph: parseFloat(p.cena_bez_dph) || 0,
        sazba_dph: parseFloat(p.sazba_dph) || 12,
        je_z_katalogu: !!p.vyrobek_id
      })),
      vsechny_odberatele: odberatele || [],
      pobocky: pobocky.mista || pobocky || [],
      posledni_objednavky: [],
      cenik: []
    };
    try {
      const kat = await api(`cenik_odberatele.php?odberatel_id=${dl.odberatel_id}`);
      rdlState.cenik = kat.vyrobky || kat || [];
      rdlState.posledni_objednavky = kat.posledni_objednavky || [];
    } catch(e) {}
    vykreslitRucniDl();
  } catch (e) {
    alert('Chyba při načítání DL: ' + e.message);
  }
};


async function renderFaktury(filters = {}, opts = {}) {
  // 🆕 v3.0.219 — paging (offset/limit + total), styl dle pagination_styl
  const append = !!opts.append;
  const pg = (state._fakPag ??= { items: [], total: 0, offset: 0, limit: 10, filters: {} });
  if (append) { pg.offset = pg.items.length; }
  else if (opts.offset !== undefined) { pg.offset = Math.max(0, opts.offset); pg.filters = filters; }
  else { pg.offset = 0; pg.items = []; pg.filters = filters; }

  const c0 = document.getElementById('content');
  if (c0 && !append && opts.offset === undefined) c0.innerHTML = `
    <div class="page-head"><div><h1 class="page-title">💰 Faktury</h1><p class="page-sub">${skeletonLine('120px', '12px')}</p></div></div>
    <div class="card-block">${skeletonTable(8)}</div>
  `;
  await loadPaginationStyl(); applyPagLimit(pg);

  const qp = new URLSearchParams({ ...pg.filters, offset: pg.offset, limit: pg.limit }).toString();
  let data;
  try {
    data = await api('admin_faktury.php?' + qp);
  } catch (e) {
    data = null;
  }
  // 🆕 v2.9.288 — defenzivní fallback (server může vrátit error/prázdno)
  if (!data || typeof data !== 'object') data = { faktury: [], souhrn: {} };
  if (!Array.isArray(data.faktury)) data.faktury = [];
  if (!data.souhrn || typeof data.souhrn !== 'object') data.souhrn = { celkem: 0, celkem_kc: 0, po_splatnosti_kc: 0 };
  pg.total = Number.isFinite(data.total) ? data.total : data.faktury.length;
  pg.items = append ? pg.items.concat(data.faktury) : data.faktury;
  data.faktury = pg.items; // render čte z akumulovaného seznamu
  const c = document.getElementById('content');

  if (!state._faSelected) state._faSelected = new Set();
  state._faList = data.faktury;

  c.innerHTML = `
    <div class="page-head">
      <div>
        <h1 class="page-title">💰 Faktury</h1>
        <p class="page-sub">${pg.total} <span>faktur</span>${data.faktury.length < pg.total ? ` · <span>zobrazeno</span> ${data.faktury.length}` : ''}</p>
      </div>
      <div style="display:flex;gap:8px;flex-wrap:wrap">
        <button class="btn-secondary" onclick="faBulkTiskNahore()" title="Vytiskni vybrané (nebo všechny zobrazené, pokud nic není zaškrtnuté)">🖨️ Tisk</button>
        <button class="btn-secondary" onclick="otevritExportFaktur()" title="Hromadný export (ISDOC / CSV)">📤 Export</button>
        <button class="btn-secondary" onclick="otevritImportFaktur()" title="Import ISDOC / ZIP (vytvoří ruční faktury)">📥 Import</button>
        <button class="btn-primary btn-green btn-big-action" onclick="otevritRucniFakturu()">+ Nová faktura</button>
      </div>
    </div>

    <div class="stat-grid">
      <div class="stat-card">
        <div class="stat-label">Celkem faktur</div>
        <div class="stat-value">${data.souhrn.celkem}</div>
      </div>
      <div class="stat-card">
        <div class="stat-label">Celková částka</div>
        <div class="stat-value">${fmt(data.souhrn.celkem_kc)}</div>
      </div>
      <div class="stat-card">
        <div class="stat-label">Po splatnosti</div>
        <div class="stat-value ${data.souhrn.po_splatnosti_kc > 0 ? 'warn' : ''}">${fmt(data.souhrn.po_splatnosti_kc)}</div>
      </div>
    </div>

    ${dashStylePeriodHtml('fa', filters.datum_od, filters.datum_do)}

    <div class="filters">
      <input class="filter-input" type="search" id="ff-q" placeholder="Hledat (číslo, odběratel)..." value="${filters.q || ''}">
      <select class="filter-select" id="ff-stav">
        <option value="">Všechny stavy</option>
        <option value="cekajici" ${filters.stav_uhrady === 'cekajici' ? 'selected' : ''}>Čekající</option>
        <option value="po_splatnosti" ${filters.stav_uhrady === 'po_splatnosti' ? 'selected' : ''}>Po splatnosti</option>
        <option value="uhrazena" ${filters.stav_uhrady === 'uhrazena' ? 'selected' : ''}>Uhrazené</option>
      </select>
      <div class="filter-dates-row filter-dates-row--off">
        <label class="filter-date-wrap">
          <span>Od:</span>
          <input class="filter-input" type="date" id="ff-od" value="${filters.datum_od || ''}">
        </label>
        <label class="filter-date-wrap">
          <span>Do:</span>
          <input class="filter-input" type="date" id="ff-do" value="${filters.datum_do || ''}">
        </label>
      </div>
      <button class="btn-secondary" onclick="applyFakturyFilters()">Filtrovat</button>
    </div>

    <div class="card-block desktop-only-block wide-table-block">
      ${data.faktury.length === 0 ? emptyState({
        icon: '💰',
        title: 'Zatím žádné faktury',
        msg: 'Faktury se generují z dodacích listů. Vystav nejdřív DL z objednávky.',
        actions: `<button class="btn-primary btn-green" onclick="navigate('objednavky')" style="font-size:15px;padding:11px 22px">📋 Otevřít objednávky</button>`,
      }) : `
        <table class="table table-selectable">
          <thead>
            <tr>
              <th class="col-check"><input type="checkbox" id="fa-check-all" onchange="faSelectAll(this.checked)" title="Vybrat vše"></th>
              <th>Číslo</th><th>Odběratel / pobočka</th><th>Vystaveno</th><th>Splatnost</th>
              <th>VS</th><th>Zdroj</th><th class="num">Pol.</th><th>Stav</th><th class="num">Částka</th><th class="col-akce"></th>
            </tr>
          </thead>
          <tbody>
            ${data.faktury.map((f) => {
              const sel = state._faSelected.has(f.id);
              return `
              <tr class="row-clickable ${sel ? 'is-selected' : ''}" onclick="openFakturaDetail(${f.id})">
                <td class="col-check" onclick="event.stopPropagation();">
                  <input type="checkbox" ${sel ? 'checked' : ''} onchange="faToggleSelect(${f.id}, this.checked)" data-fa-check="${f.id}">
                </td>
                <td><strong${f.je_dobropis == 1 ? ' style="color:#DC2626"' : ''}>${esc(f.cislo)}</strong>${f.je_dobropis == 1 ? ' <span class="status zrusena" style="font-size:10px">↩️ Dobropis</span>' : ''}${upravenoDot(f.obsah_upraveno)}</td>
                <td>
                  <div>${esc(f.odberatel_nazev)}</div>
                  ${f.pobocky_nazvy ? `<div style="font-size:11px;color:var(--text-3);margin-top:2px">📍 ${esc(f.pobocky_nazvy)}${f.pobocka_adresa ? ' — ' + esc(f.pobocka_adresa) : ''}</div>` : ''}
                </td>
                <td>${fmtDate(f.datum_vystaveni)}</td>
                <td>${fmtDate(f.datum_splatnosti)}</td>
                <td>${esc(f.variabilni_symbol || '')}</td>
                <td onclick="event.stopPropagation();">${zdrojFakturyBadge(f)}</td>
                <td class="num">${f.pocet_polozek || 0}</td>
                <td>${stavUhradyBadge(f.stav_uhrady)}</td>
                <td class="num"><strong>${fmt(f.castka_celkem)}</strong></td>
                <td onclick="event.stopPropagation();" style="white-space:nowrap;text-align:right">
                  <span class="doc-badges-row" style="justify-content:flex-end">
                    ${docBadge('dl', (f.pocet_dl > 0) ? (f.prvni_dl_objednavka_id ? `../api/dodaci_list.php?id=${f.prvni_dl_objednavka_id}` : `../api/dodaci_list.php?dl_id=${f.prvni_dl_id}`) : null, '📃 DL', { title: 'Otevřít dodací list (PDF)', disabledTitle: 'Faktura nemá navázaný DL' })}
                    ${docBadge('fa', `../api/faktura.php?id=${f.id}`, '💰 FA', { title: 'Otevřít fakturu (PDF)' })}
                  </span>
                </td>
              </tr>
            `;}).join('')}
          </tbody>
        </table>
      `}
    </div>

    <div id="fa-bulk-bar" class="bulk-bar" style="display:none;">
      <div class="bulk-bar-info">
        <span class="bulk-bar-count" id="fa-bulk-count">0</span>
        <span>vybráno</span>
      </div>
      <div class="bulk-bar-actions">
        <button class="btn-primary" onclick="faBulkTisk()" title="Otevře tiskový dialog se všemi vybranými fakturami za sebou">🖨️ Tisk vybrané</button>
        <button class="btn-secondary" onclick="faBulkExportIsdoc()">📤 Export ISDOC ZIP</button>
        <button class="btn-secondary" onclick="faBulkOdeslatEmailem()" title="Odeslat každou vybranou fakturu e-mailem jejímu odběrateli (PDF v příloze)">✉️ Odeslat e-mailem</button>
        <button class="btn-link" onclick="faClearSelection()">✕ Zrušit výběr</button>
      </div>
    </div>

    <!-- Mobile: kompaktní karty -->
    <div class="mobile-only-block wide-table-block">
      ${data.faktury.length === 0 ? '<div class="card-block"><div class="empty-state">Žádné faktury</div></div>' :
        data.faktury.map((f) => `
          <div class="faktura-card${(state._faSelected && state._faSelected.has(f.id)) ? ' is-selected' : ''}" onclick="openFakturaDetail(${f.id})">
            <div class="faktura-card-head">
              <label class="doc-card-check" onclick="event.stopPropagation()" title="Vybrat pro export / e-mail">
                <input type="checkbox" ${(state._faSelected && state._faSelected.has(f.id)) ? 'checked' : ''} onchange="faToggleSelect(${f.id}, this.checked)" data-fa-check="${f.id}">
              </label>
              <div class="faktura-card-cislo">${esc(f.cislo)}${upravenoDot(f.obsah_upraveno)}</div>
              ${stavUhradyBadge(f.stav_uhrady)}
            </div>
            <div class="faktura-card-odb">${esc(f.odberatel_nazev)}</div>
            ${f.pobocky_nazvy ? `<div class="faktura-card-misto">📍 ${esc(f.pobocky_nazvy)}${f.pobocka_adresa ? ' — ' + esc(f.pobocka_adresa) : ''}</div>` : ''}
            <div class="faktura-card-zdroj" onclick="event.stopPropagation();">${zdrojFakturyBadge(f)}</div>
            <div class="faktura-card-foot">
              <div class="faktura-card-datum">
                <span>📅 ${fmtDate(f.datum_vystaveni)}</span>
                <span>⏰ ${fmtDate(f.datum_splatnosti)}</span>
                <span>📦 ${f.pocet_polozek || 0} pol.</span>
              </div>
              <div class="faktura-card-castka">${fmt(f.castka_celkem)}</div>
            </div>
            <div class="faktura-card-actions" onclick="event.stopPropagation();">
              <span class="doc-badges-row">
                ${docBadge('dl', (f.pocet_dl > 0) ? (f.prvni_dl_objednavka_id ? `../api/dodaci_list.php?id=${f.prvni_dl_objednavka_id}` : `../api/dodaci_list.php?dl_id=${f.prvni_dl_id}`) : null, '📃 Dodací list', { disabledTitle: 'Faktura nemá navázaný DL' })}
                ${docBadge('fa', `../api/faktura.php?id=${f.id}`, '💰 Faktura')}
              </span>
            </div>
          </div>
        `).join('')
      }
    </div>

    ${pagControlHtml('fak', pg, 'faGoToPage', 'faLoadMore')}
  `;
  pagSetupInfinite('fak', pg, 'faLoadMore'); // 🆕 v3.0.219
}
window.faLoadMore = function() { renderFaktury(state._fakPag.filters, { append: true }); };
window.faGoToPage = function(p) { renderFaktury(state._fakPag.filters, { offset: p * (state._fakPag.limit || 50) }); };

function zdrojFakturyBadge(f) {
  const wrap = (inner) => `<span class="doc-badges-row" style="justify-content:flex-start;flex-wrap:nowrap">${inner}</span>`;
  if (parseInt(f.rucni) === 1) {
    return wrap(`<span title="Ručně vystavená faktura" class="doc-badge rucni">✏️ Ruční</span>`);
  }
  const obj = (f.objednavka_cisla || '').trim();
  const dl = (f.dl_cisla || '').trim();
  const objId = parseInt(f.prvni_objednavka_id) || 0;
  const dlId = parseInt(f.prvni_dl_id) || 0;
  const parts = [];
  if (obj && objId) {
    parts.push(`<a href="#" onclick="event.stopPropagation();openObjednavkaDetail(${objId});return false" title="Otevřít objednávku ${esc(obj)}" class="doc-badge obj">🛒 ${esc(obj)}</a>`);
  } else if (obj) {
    parts.push(`<span class="doc-badge obj">🛒 ${esc(obj)}</span>`);
  }
  if (dl && dlId) {
    parts.push(`<a href="#" onclick="event.stopPropagation();openDodaciListDetail(${dlId});return false" title="Otevřít detail dodacího listu ${esc(dl)}" class="doc-badge dl">📃 ${esc(dl)}</a>`);
  } else if (dl) {
    parts.push(`<span class="doc-badge dl">📃 ${esc(dl)}</span>`);
  }
  return parts.length
    ? wrap(parts.join(''))
    : '<span style="color:var(--text-3);font-size:12px">—</span>';
}

function stavUhradyBadge(stav) {
  if (stav === 'uhrazena') return '<span class="status dorucena">Uhrazena</span>';
  if (stav === 'po_splatnosti') return '<span class="status zrusena">Po splatnosti</span>';
  return '<span class="status nova">Čekající</span>';
}

// Stav dodacího listu — pro vizuální konzistenci s OBJ/FA badges
function dlStavBadge(dl) {
  if (parseInt(dl.fakturovano)) return '<span class="status dorucena">Fakturováno</span>';
  return '<span class="status potvrzena">Nefakturováno</span>';
}

window.applyFakturyFilters = function() {
  renderFaktury({
    q: document.getElementById('ff-q').value,
    stav_uhrady: document.getElementById('ff-stav').value,
    datum_od: document.getElementById('ff-od')?.value || '',
    datum_do: document.getElementById('ff-do')?.value || '',
  });
};

