// =============================================================
// PŘEHLED VÝROBY ZA OBDOBÍ (export do CSV / tisk)
// =============================================================
async function renderExportVyroby(filters = {}) {
  const c = document.getElementById('content');

  // Default: tento měsíc (zachová se mezi re-render)
  const dnes = new Date();
  const default_od = filters.od || state._exVyrobyOd || dnes.toISOString().slice(0, 8) + '01';
  const default_do_dt = new Date(dnes.getFullYear(), dnes.getMonth() + 1, 0);
  const default_do = filters.do || state._exVyrobyDo || default_do_dt.toISOString().slice(0, 10);
  const obdobi = filters.obdobi || state._exVyrobyObdobi || 'mesic';

  state._exVyrobyOd = default_od;
  state._exVyrobyDo = default_do;
  state._exVyrobyObdobi = obdobi;

  const exMode = state._exVyrobyMode || 'souhrn';
  state._exVyrobyMode = exMode;

  c.innerHTML = `
    <div class="page-head">
      <div>
        <h1 class="page-title">📈 Vyrobeno</h1>
        <p class="page-sub">Souhrn objednaných výrobků za období · zdroj: objednávky (kromě zrušených), datum dodání</p>
      </div>
      <div style="display:flex;gap:8px;flex-wrap:wrap">
        <button class="btn-secondary" onclick="navigate('vyroba')">← Výroba</button>
        <button class="btn-secondary" onclick="window.print()">🖨️ Tisk</button>
        <button class="btn-primary btn-green" onclick="exportVyrobyCsv()">📥 Stáhnout CSV</button>
      </div>
    </div>

    <!-- TABY OBDOBÍ — v2.9.287 — short labels mobile -->
    <div class="period-tabs no-print">
      ${periodTabsRender([
        { k: 'mesic',         icon: '🗓️', l: 'Tento měsíc',  short: 'Měsíc' },
        { k: 'minuly_mesic',  icon: '⬅️', l: 'Minulý měsíc', short: 'Min. měs.' },
        { k: 'rok',           icon: '📅', l: 'Tento rok',     short: 'Rok' },
        { k: 'minuly_rok',    icon: '⬅️', l: 'Minulý rok',    short: 'Min. rok' },
        { k: 'vlastni',       icon: '⚙️', l: 'Vlastní',        short: 'Vlastní' },
      ], obdobi, 'exVyrobySetObdobi')}
    </div>

    <!-- PŘEPÍNAČ SOUHRN / DENNÍ -->
    <div class="period-tabs no-print" style="margin-top:8px">
      <button class="period-tab ${exMode === 'souhrn' ? 'active' : ''}" onclick="exVyrobySetMode('souhrn')"><span class="period-tab-icon">📊</span><span class="period-tab-text">Souhrn (po výrobcích)</span></button>
      <button class="period-tab ${exMode === 'denni' ? 'active' : ''}" onclick="exVyrobySetMode('denni')"><span class="period-tab-icon">📅</span><span class="period-tab-text">Po dnech (pivot)</span></button>
    </div>

    ${obdobi === 'vlastni' ? `
      <div class="period-custom no-print">
        <label class="filter-date-wrap"><span>Od:</span>
          <input class="filter-input" type="date" id="exv-od" value="${default_od}">
        </label>
        <label class="filter-date-wrap"><span>Do:</span>
          <input class="filter-input" type="date" id="exv-do" value="${default_do}">
        </label>
        <button class="btn-secondary" onclick="exVyrobyApplyVlastni()">Použít</button>
      </div>
    ` : ''}

    <p class="period-range">📅 Období: <strong>${fmtDate(default_od)} – ${fmtDate(default_do)}</strong></p>

    <div id="exv-content"><div class="empty-state" style="padding:40px">Načítám…</div></div>
  `;

  await exVyrobyLoad(default_od, default_do);
}

window.exVyrobySetObdobi = function(obdobi) {
  state._exVyrobyObdobi = obdobi;
  const dnes = new Date();
  let od, dto;
  if (obdobi === 'mesic') {
    od = dnes.toISOString().slice(0, 8) + '01';
    dto = new Date(dnes.getFullYear(), dnes.getMonth() + 1, 0).toISOString().slice(0, 10);
  } else if (obdobi === 'minuly_mesic') {
    const m = new Date(dnes.getFullYear(), dnes.getMonth() - 1, 1);
    od = m.toISOString().slice(0, 10);
    dto = new Date(dnes.getFullYear(), dnes.getMonth(), 0).toISOString().slice(0, 10);
  } else if (obdobi === 'rok') {
    od = dnes.getFullYear() + '-01-01';
    dto = dnes.getFullYear() + '-12-31';
  } else if (obdobi === 'minuly_rok') {
    const r = dnes.getFullYear() - 1;
    od = r + '-01-01';
    dto = r + '-12-31';
  } else { // vlastni
    od = state._exVyrobyOd;
    dto = state._exVyrobyDo;
  }
  renderExportVyroby({ obdobi, od, do: dto });
};

window.exVyrobyApplyVlastni = function() {
  const od = document.getElementById('exv-od').value;
  const dto = document.getElementById('exv-do').value;
  if (!od || !dto) return alert('Vyplňte oba datumy');
  if (od > dto) return alert('Datum od musí být před do');
  renderExportVyroby({ obdobi: 'vlastni', od, do: dto });
};

async function exVyrobyLoad(od, dto) {
  const wrap = document.getElementById('exv-content');
  const mode = state._exVyrobyMode || 'souhrn';

  try {
    const data = await api(`admin_export_vyroby.php?od=${od}&do=${dto}&mode=${mode}`);
    const s = data.souhrn;
    if (data.polozky.length === 0) {
      wrap.innerHTML = `<div class="card-block"><div class="empty-state">Žádné objednávky v tomto období.</div></div>`;
      return;
    }

    // 🆕 v2.9.266 — Statistika cards refresh (kompaktní, s sub + ikonami; primary tint na Tržbě)
    const statsHtml = `
      <div class="stat-grid stat-grid-2col" style="margin-bottom:14px">
        <div class="stat-card">
          <div class="stat-label">📦 Unikátních výrobků</div>
          <div class="stat-value">${s.unikatnich_vyrobku}</div>
          <div class="stat-sub">druhů</div>
        </div>
        <div class="stat-card">
          <div class="stat-label">🥖 Celkem kusů</div>
          <div class="stat-value">${parseFloat(s.celkem_kusu).toLocaleString('cs-CZ')}</div>
          <div class="stat-sub">vyrobeno</div>
        </div>
        <div class="stat-card">
          <div class="stat-label">🛒 Objednávek</div>
          <div class="stat-value">${s.unikatnich_objednavek}</div>
          <div class="stat-sub">v období</div>
        </div>
        <div class="stat-card" style="background:linear-gradient(180deg, var(--surface) 0%, rgba(186, 117, 23, 0.04) 100%);border-color:var(--primary-border)">
          <div class="stat-label">💰 Tržba s DPH</div>
          <div class="stat-value" style="color:var(--primary-dark);font-weight:700">${fmt(s.celkem_s_dph)}</div>
          <div class="stat-sub">celkem</div>
        </div>
      </div>
    `;

    if (mode === 'denni' && data.dny && data.vyrobky) {
      // PIVOT: Výrobky × Dny
      const dny = data.dny;
      const dnyShort = ['Ne','Po','Út','St','Čt','Pá','So'];
      // Index cen podle vyrobek_id
      const cenyIdx = {};
      (data.polozky || []).forEach(p => { cenyIdx[p.vyrobek_id] = p; });
      wrap.innerHTML = `
        ${statsHtml}
        <div class="card-block" style="padding:0;overflow:hidden">
          <div class="exv-pivot-wrap">
            <table class="table exv-pivot">
              <thead>
                <tr>
                  <th class="exv-pivot-name">Výrobek</th>
                  ${dny.map(d => {
                    const dt = new Date(d);
                    const dn = dnyShort[dt.getDay()];
                    const isWeekend = dt.getDay() === 0 || dt.getDay() === 6;
                    return `<th class="num exv-pivot-day ${isWeekend ? 'weekend' : ''}" title="${dn} ${fmtDate(d)}">
                      <div class="exv-pivot-day-num">${dt.getDate()}.</div>
                      <div class="exv-pivot-day-name">${dn}</div>
                    </th>`;
                  }).join('')}
                  <th class="num exv-pivot-total">Σ ks</th>
                  <th class="num exv-pivot-price">Bez DPH</th>
                  <th class="num exv-pivot-price">S DPH</th>
                </tr>
              </thead>
              <tbody>
                ${data.vyrobky.map(v => {
                  const cena = cenyIdx[v.vyrobek_id];
                  return `
                  <tr>
                    <td class="exv-pivot-name">
                      <strong>${esc(v.nazev)}</strong>
                      ${v.cislo ? `<div style="font-size:10px;color:var(--text-3)">kód ${esc(v.cislo)}</div>` : ''}
                    </td>
                    ${dny.map(d => {
                      const mn = v.po_dnech[d] || 0;
                      const dt = new Date(d);
                      const isWeekend = dt.getDay() === 0 || dt.getDay() === 6;
                      return `<td class="num exv-pivot-cell ${isWeekend ? 'weekend' : ''} ${mn > 0 ? 'has-mn' : ''}">${mn > 0 ? Math.round(mn) : ''}</td>`;
                    }).join('')}
                    <td class="num exv-pivot-total"><strong>${Math.round(v.mnozstvi_celkem)}</strong></td>
                    <td class="num exv-pivot-price">${cena ? fmt(cena.celkem_bez_dph) : ''}</td>
                    <td class="num exv-pivot-price">${cena ? fmt(cena.celkem_s_dph) : ''}</td>
                  </tr>
                `;}).join('')}
              </tbody>
              <tfoot>
                <tr>
                  <td class="exv-pivot-name"><strong>CELKEM</strong></td>
                  ${dny.map(d => {
                    const mn = data.souhrn_dny[d] || 0;
                    const dt = new Date(d);
                    const isWeekend = dt.getDay() === 0 || dt.getDay() === 6;
                    return `<td class="num exv-pivot-cell ${isWeekend ? 'weekend' : ''}"><strong>${mn > 0 ? Math.round(mn) : ''}</strong></td>`;
                  }).join('')}
                  <td class="num exv-pivot-total" style="font-size:14px;color:#854F0B">${parseFloat(s.celkem_kusu).toLocaleString('cs-CZ')}</td>
                  <td class="num exv-pivot-price" style="color:#854F0B"><strong>${fmt(s.celkem_bez_dph)}</strong></td>
                  <td class="num exv-pivot-price" style="color:#854F0B"><strong>${fmt(s.celkem_s_dph)}</strong></td>
                </tr>
              </tfoot>
            </table>
          </div>
        </div>
        <p style="font-size:12px;color:var(--text-3);margin-top:8px">💡 Tabulka má vodorovný posuv. Export CSV obsahuje navíc sloupce Cena/ks Ø a DPH.</p>
      `;
    } else {
      // SOUHRN: Výrobek × součty
      wrap.innerHTML = `
        ${statsHtml}
        <div class="card-block" style="padding:0;overflow-x:auto">
          <table class="table exv-table" style="margin:0">
            <thead>
              <tr>
                <th class="exv-col-cislo">Č.</th>
                <th>Název</th>
                <th class="exv-col-jed">Jed.</th>
                <th class="num">Množství</th>
                <th class="num exv-col-hide">Ø Cena</th>
                <th class="num">Bez DPH</th>
                <th class="exv-col-hide">DPH</th>
                <th class="num exv-col-hide">DPH Kč</th>
                <th class="num">S DPH</th>
                <th class="num exv-col-hide">Obj.</th>
              </tr>
            </thead>
            <tbody>
              ${data.polozky.map(p => `
                <tr>
                  <td class="exv-col-cislo">${esc(p.cislo || '')}</td>
                  <td><strong>${esc(p.nazev)}</strong></td>
                  <td class="exv-col-jed">${esc(p.jednotka || 'ks')}</td>
                  <td class="num"><strong>${parseFloat(p.mnozstvi).toLocaleString('cs-CZ')}</strong></td>
                  <td class="num exv-col-hide">${fmt(p.cena_prumer)}</td>
                  <td class="num">${fmt(p.celkem_bez_dph)}</td>
                  <td class="exv-col-hide">${parseInt(p.sazba_dph) || 0}%</td>
                  <td class="num exv-col-hide">${fmt(p.celkem_dph)}</td>
                  <td class="num"><strong>${fmt(p.celkem_s_dph)}</strong></td>
                  <td class="num exv-col-hide">${p.pocet_objednavek}</td>
                </tr>
              `).join('')}
            </tbody>
            <tfoot>
              <tr style="background:#FAEEDA;font-weight:700">
                <td colspan="3" style="text-align:right">CELKEM:</td>
                <td class="num">${parseFloat(s.celkem_kusu).toLocaleString('cs-CZ')}</td>
                <td class="exv-col-hide"></td>
                <td class="num">${fmt(s.celkem_bez_dph)}</td>
                <td class="exv-col-hide"></td>
                <td class="num exv-col-hide">${fmt(s.celkem_dph)}</td>
                <td class="num" style="font-size:15px;color:#854F0B">${fmt(s.celkem_s_dph)}</td>
                <td class="num exv-col-hide">${s.unikatnich_objednavek}</td>
              </tr>
            </tfoot>
          </table>
        </div>
      `;
    }
  } catch (e) {
    wrap.innerHTML = `<div class="card-block" style="color:var(--danger-text)">Chyba: ${esc(e.message)}</div>`;
  }
}

window.exVyrobySetMode = function(mode) {
  state._exVyrobyMode = mode;
  exVyrobyLoad(state._exVyrobyOd, state._exVyrobyDo);
  // Refresh active tab
  document.querySelectorAll('.period-tabs .period-tab').forEach(b => {
    if (b.textContent.includes('Souhrn'))   b.classList.toggle('active', mode === 'souhrn');
    if (b.textContent.includes('Po dnech')) b.classList.toggle('active', mode === 'denni');
  });
};

window.exportVyrobyCsv = function() {
  const od = state._exVyrobyOd;
  const dto = state._exVyrobyDo;
  const mode = state._exVyrobyMode || 'souhrn';
  if (!od || !dto) return alert('Není nastavené období');
  window.location.href = `${API}/admin_export_vyroby.php?od=${od}&do=${dto}&mode=${mode}&format=csv`;
};

