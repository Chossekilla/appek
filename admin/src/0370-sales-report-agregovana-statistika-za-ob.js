// =============================================================
// 📈 SALES REPORT — agregovaná statistika za období
// =============================================================
async function renderSalesReport() {
  const c = document.getElementById('content');
  const dnes = new Date();
  const prvniMesice = new Date(dnes.getFullYear(), dnes.getMonth(), 1).toISOString().split('T')[0];
  const dnesStr = dnes.toISOString().split('T')[0];
  const od = state._salesOd || prvniMesice;
  const dotxt = state._salesDo || dnesStr;
  state._salesOd = od; state._salesDo = dotxt;

  c.innerHTML = `
    <div class="page-head">
      <div>
        <h1 class="page-title">📈 Sales report</h1>
        <p class="page-sub">Přehled tržeb, top výrobky a top odběratelé za zvolené období</p>
      </div>
      <div style="display:flex;gap:8px;flex-wrap:wrap">
        <button class="btn-secondary" onclick="navigate('dashboard')">← Dashboard</button>
        <button class="btn-primary btn-green" onclick="salesPrint()">🖨️ Stáhnout / vytisknout</button>
      </div>
    </div>

    <div class="filters">
      <button class="btn-secondary" onclick="salesPredchoziMesic()">← Předchozí měsíc</button>
      <button class="btn-secondary" onclick="salesTentoMesic()">📅 Tento měsíc</button>
      <button class="btn-secondary" onclick="salesTentoRok()">📅 Tento rok</button>
      <label class="filter-date-wrap"><span>Od:</span><input class="filter-input" type="date" value="${od}" onchange="state._salesOd=this.value;renderSalesReport()"></label>
      <label class="filter-date-wrap"><span>Do:</span><input class="filter-input" type="date" value="${dotxt}" onchange="state._salesDo=this.value;renderSalesReport()"></label>
    </div>

    <div id="sales-data"><div class="empty-state" style="padding:40px">⏳ Načítám…</div></div>
  `;

  try {
    const d = await api(`admin_sales_report.php?od=${od}&do=${dotxt}`);
    const host = document.getElementById('sales-data');
    const s = d.summary;
    const celkem = parseFloat(s.celkem_kc) || 0;

    host.innerHTML = `
      <!-- Summary -->
      <div class="stat-grid" style="margin-bottom:14px">
        <div class="stat-card">
          <div class="stat-label">Objednávek</div>
          <div class="stat-value">${parseInt(s.celkem_obj) || 0}</div>
        </div>
        <div class="stat-card">
          <div class="stat-label">Aktivních odběratelů</div>
          <div class="stat-value">${parseInt(s.pocet_odberatelu) || 0}</div>
        </div>
        <div class="stat-card">
          <div class="stat-label">Celková tržba (s DPH)</div>
          <div class="stat-value" style="color:var(--success-text)">${fmt(celkem)}</div>
        </div>
        <div class="stat-card">
          <div class="stat-label">Bez DPH</div>
          <div class="stat-value">${fmt(parseFloat(s.celkem_bez_dph) || 0)}</div>
        </div>
        <div class="stat-card">
          <div class="stat-label">Průměr / obj.</div>
          <div class="stat-value">${fmt(parseFloat(s.prum_obj) || 0)}</div>
        </div>
      </div>

      <!-- TOP výrobky + TOP odběratelé vedle sebe -->
      <div class="nastaveni-row">
        <div class="card-block">
          <h3 style="margin:0 0 10px">🏆 TOP 10 výrobků</h3>
          ${d.top_vyrobky.length === 0 ? '<div class="empty-state">Žádná data</div>' : `
            <table class="table" style="margin:0;font-size:13px">
              <thead><tr><th>#</th><th>Výrobek</th><th class="num">Ks</th><th class="num">Tržba</th><th class="num">% z celku</th></tr></thead>
              <tbody>
                ${d.top_vyrobky.map((v, i) => `
                  <tr>
                    <td style="color:var(--primary-dark);font-weight:700">${i+1}.</td>
                    <td><strong>${esc(v.nazev || '—')}</strong>${v.kat_nazev ? `<div style="font-size:10px;color:var(--text-3)">${esc(v.kat_ikona || '')} ${esc(v.kat_nazev)}</div>` : ''}</td>
                    <td class="num">${Math.round(v.celkem_ks || 0)}</td>
                    <td class="num"><strong>${fmt(v.celkem_kc)}</strong></td>
                    <td class="num" style="color:var(--text-3)">${celkem > 0 ? Math.round((v.celkem_kc / celkem) * 1000) / 10 : 0} %</td>
                  </tr>
                `).join('')}
              </tbody>
            </table>
          `}
        </div>

        <div class="card-block">
          <h3 style="margin:0 0 10px">👥 TOP 10 odběratelů</h3>
          ${d.top_odberatele.length === 0 ? '<div class="empty-state">Žádná data</div>' : `
            <table class="table" style="margin:0;font-size:13px">
              <thead><tr><th>#</th><th>Odběratel</th><th class="num">Obj.</th><th class="num">Tržba</th><th class="num">Průměr</th></tr></thead>
              <tbody>
                ${d.top_odberatele.map((o, i) => `
                  <tr>
                    <td style="color:var(--primary-dark);font-weight:700">${i+1}.</td>
                    <td><strong>${esc(o.nazev)}</strong></td>
                    <td class="num">${parseInt(o.pocet_obj) || 0}</td>
                    <td class="num"><strong>${fmt(o.celkem_kc)}</strong></td>
                    <td class="num" style="color:var(--text-3)">${fmt(o.prum_obj)}</td>
                  </tr>
                `).join('')}
              </tbody>
            </table>
          `}
        </div>
      </div>

      <!-- Kategorie -->
      ${d.top_kategorie && d.top_kategorie.length > 0 ? `
        <div class="card-block">
          <h3 style="margin:0 0 10px">📂 Tržba podle kategorií</h3>
          ${d.top_kategorie.map(k => {
            const pct = celkem > 0 ? Math.round((k.celkem_kc / celkem) * 1000) / 10 : 0;
            return `
              <div style="margin-bottom:10px">
                <div style="display:flex;justify-content:space-between;font-size:13px;margin-bottom:4px">
                  <span><strong>${esc(k.ikona || '')} ${esc(k.nazev)}</strong> <span style="color:var(--text-3)">${Math.round(k.celkem_ks)} ks</span></span>
                  <span><strong>${fmt(k.celkem_kc)}</strong> <span style="color:var(--text-3)">(${pct} %)</span></span>
                </div>
                <div style="height:8px;background:var(--surface-2);border-radius:4px;overflow:hidden">
                  <div style="height:100%;background:linear-gradient(90deg,var(--primary),var(--primary-dark));width:${pct}%"></div>
                </div>
              </div>
            `;
          }).join('')}
        </div>
      ` : ''}
    `;
  } catch (e) {
    document.getElementById('sales-data').innerHTML = `<div style="color:var(--danger-text);padding:20px">Chyba: ${esc(e.message)}</div>`;
  }
}

window.salesPredchoziMesic = function() {
  const od = new Date(state._salesOd);
  od.setMonth(od.getMonth() - 1, 1);
  const doD = new Date(od.getFullYear(), od.getMonth() + 1, 0);
  state._salesOd = od.toISOString().split('T')[0];
  state._salesDo = doD.toISOString().split('T')[0];
  renderSalesReport();
};
window.salesTentoMesic = function() {
  const d = new Date();
  state._salesOd = new Date(d.getFullYear(), d.getMonth(), 1).toISOString().split('T')[0];
  state._salesDo = new Date().toISOString().split('T')[0];
  renderSalesReport();
};
window.salesTentoRok = function() {
  const d = new Date();
  state._salesOd = new Date(d.getFullYear(), 0, 1).toISOString().split('T')[0];
  state._salesDo = new Date().toISOString().split('T')[0];
  renderSalesReport();
};
window.salesPrint = function() {
  const od = state._salesOd, doD = state._salesDo;
  window.open(`../api/sales_report_print.php?od=${od}&do=${doD}`, '_blank');
};

window.recurringSpustit = async function() {
  if (!(await confirmDialog({ msg: 'Spustit generování objednávek pro ZÍTRA? (Anti-duplikát zajistí že se neuhrazené pravidla nevytvoří dvakrát.)', danger: false }))) return;
  try {
    const r = await api('admin_recurring.php?action=spustit_ted', { method: 'POST' });
    alert(t('bulk_done_stats', { ok: r.vytvoreno, skip: r.preskoceno, err: r.chyby }));
    navigate('recurring');
  } catch (e) { alert('Chyba: ' + e.message); }
};

async function renderDodaciListy(filters = {}, opts = {}) {
  // 🆕 v3.0.219 — paging (offset/limit + total), styl dle pagination_styl
  const append = !!opts.append;
  const pg = (state._dlPag ??= { items: [], total: 0, offset: 0, limit: 10, filters: {} });
  if (append) { pg.offset = pg.items.length; }
  else if (opts.offset !== undefined) { pg.offset = Math.max(0, opts.offset); pg.filters = filters; }
  else { pg.offset = 0; pg.items = []; pg.filters = filters; }
  await loadPaginationStyl(); applyPagLimit(pg);

  const qp = new URLSearchParams({ ...pg.filters, offset: pg.offset, limit: pg.limit }).toString();
  let data;
  try {
    data = await api('admin_dodaci_listy.php?' + qp);
  } catch (e) { data = null; }
  // 🆕 v2.9.288 — defenzivní fallback
  if (!data || typeof data !== 'object') data = { dodaci_listy: [], souhrn: {} };
  if (!Array.isArray(data.dodaci_listy)) data.dodaci_listy = [];
  if (!data.souhrn || typeof data.souhrn !== 'object') data.souhrn = {};
  pg.total = Number.isFinite(data.total) ? data.total : (parseInt(data.pocet) || data.dodaci_listy.length);
  pg.items = append ? pg.items.concat(data.dodaci_listy) : data.dodaci_listy;
  data.dodaci_listy = pg.items; // render čte z akumulovaného seznamu

  if (!state._dlSelected) state._dlSelected = new Set();
  state._dlList = data.dodaci_listy;

  const c = document.getElementById('content');
  c.innerHTML = `
    <div class="page-head">
      <div>
        <h1 class="page-title">📃 Dodací listy</h1>
        <p class="page-sub">${pg.total} <span>dodacích listů</span>${pg.items.length < pg.total ? ` · <span>zobrazeno</span> ${pg.items.length}` : ''}</p>
      </div>
      <div style="display:flex;gap:8px;flex-wrap:wrap;align-items:center">
        <button class="btn-icon-action btn-rozvozy-action" onclick="navigate('rozvozy')" title="Rozvozové trasy — DL seskupené podle města/PSČ s pořadovými čísly zastávek pro řidiče" aria-label="Rozvozové trasy">
          <span class="btn-icon-action-ico">🛣️</span>
          <span class="btn-icon-action-lbl">Rozvozové trasy</span>
        </button>
        <button class="btn-secondary" onclick="dlBulkTiskNahore()" title="Vytiskni vybrané (nebo všechny zobrazené, pokud nic není zaškrtnuté)">🖨️ Tisk</button>
        <button class="btn-secondary" onclick="otevritExportDl()" title="Export do CSV pro Money S3, Pohoda, Excel">📤 Export</button>
        <button class="btn-primary btn-green btn-big-action" onclick="otevritRucniDl()">+ Nový dodací list</button>
      </div>
    </div>

    <div class="stat-grid">
      <div class="stat-card">
        <div class="stat-label">Celkem DL</div>
        <div class="stat-value">${parseInt(data.pocet || 0)}</div>
      </div>
      <div class="stat-card">
        <div class="stat-label">Celková částka</div>
        <div class="stat-value">${fmt(parseFloat(data.castka_celkem) || 0)}</div>
      </div>
      <div class="stat-card">
        <div class="stat-label">Nefakturováno</div>
        <div class="stat-value" style="color:var(--danger-text)">${fmt(parseFloat(data.castka_nefakturovana) || 0)}</div>
      </div>
    </div>

    ${dashStylePeriodHtml('dl', filters.datum_od, filters.datum_do)}

    <div class="filters">
      <input class="filter-input" type="search" id="dlf-q" placeholder="Hledat (číslo, odběratel)..." value="${filters.q || ''}">
      <select class="filter-select" id="dlf-fakt">
        <option value="">Vše</option>
        <option value="0" ${filters.fakturovano === '0' ? 'selected' : ''}>Nefakturováno</option>
        <option value="1" ${filters.fakturovano === '1' ? 'selected' : ''}>Fakturováno</option>
      </select>
      <div class="filter-dates-row filter-dates-row--off">
        <label class="filter-date-wrap">
          <span>Od:</span>
          <input class="filter-input" type="date" id="dlf-od" value="${filters.datum_od || ''}">
        </label>
        <label class="filter-date-wrap">
          <span>Do:</span>
          <input class="filter-input" type="date" id="dlf-do" value="${filters.datum_do || ''}">
        </label>
      </div>
      <button class="btn-secondary" onclick="applyDlFilters()">Filtrovat</button>
    </div>

    <!-- Desktop: tabulka -->
    <div class="card-block desktop-only-block wide-table-block">
      ${data.dodaci_listy.length === 0 ? '<div class="empty-state">Žádné dodací listy</div>' : `
        <table class="table table-selectable">
          <thead>
            <tr>
              <th class="col-check"><input type="checkbox" id="dl-check-all" onchange="dlSelectAll(this.checked)" title="Vybrat vše"></th>
              <th>Číslo</th>
              <th>Objednávka</th>
              <th>Odběratel</th>
              <th>Pobočka</th>
              <th>Vystaveno</th>
              <th>Dodáno</th>
              <th class="num">Položek</th>
              <th>Faktura</th>
              <th class="num">Částka</th>
              <th class="col-akce"></th>
            </tr>
          </thead>
          <tbody>
            ${data.dodaci_listy.map((d) => {
              const sel = (state._dlSelected || new Set()).has(d.id);
              return `
              <tr class="row-clickable ${sel ? 'is-selected' : ''}" onclick="openDodaciListDetail(${d.id})">
                <td class="col-check" onclick="event.stopPropagation();">
                  <input type="checkbox" ${sel ? 'checked' : ''} onchange="dlToggleSelect(${d.id}, this.checked)" data-dl-check="${d.id}">
                </td>
                <td><strong>${esc(d.cislo)}</strong>${upravenoDot(d.obsah_upraveno)}</td>
                <td onclick="event.stopPropagation();">
                  ${d.objednavka_id
                    ? `<a href="#" onclick="openObjednavkaDetail(${d.objednavka_id});return false" class="doc-badge obj">🛒 ${esc(d.objednavka_cislo)}</a>`
                    : `<span class="doc-badge rucni">✏️ Ruční</span>`}
                </td>
                <td>${esc(d.odberatel_nazev || '⚠️ smazaný odběratel')}</td>
                <td style="color:var(--text-3);font-size:13px">${esc(d.misto_nazev || '—')}</td>
                <td>${fmtDate(d.datum_vystaveni)}</td>
                <td>${fmtDate(d.datum_dodani)}</td>
                <td class="num">${d.pocet_polozek}</td>
                <td onclick="event.stopPropagation();">
                  ${d.fakturovano && d.prvni_faktura_id
                    ? `<a href="#" onclick="openFakturaDetail(${d.prvni_faktura_id});return false" class="doc-badge fa">💰 ${esc(d.faktura_cisla || '')}</a>`
                    : '<span style="color:var(--danger-text);font-size:11px;font-weight:500">Nefakturováno</span>'}
                </td>
                <td class="num"><strong>${fmt(d.castka_celkem)}</strong></td>
                <td onclick="event.stopPropagation();" style="white-space:nowrap;text-align:right">
                  <span class="doc-badges-row" style="justify-content:flex-end">
                    ${docBadge('dl', dlPdfUrl(d), '📃 DL', { title: 'Otevřít DL (PDF)' })}
                    ${docBadge('fa', (d.fakturovano && d.prvni_faktura_id) ? `../api/faktura.php?id=${d.prvni_faktura_id}` : null, '💰 FA', { title: 'Otevřít fakturu (PDF)', disabledTitle: 'Faktura zatím nevystavena' })}
                  </span>
                </td>
              </tr>
            `;}).join('')}
          </tbody>
        </table>
      `}
    </div>

    <div id="dl-bulk-bar" class="bulk-bar" style="display:none;">
      <div class="bulk-bar-info">
        <span class="bulk-bar-count" id="dl-bulk-count">0</span>
        <span>vybráno</span>
      </div>
      <div class="bulk-bar-actions">
        <button class="btn-primary btn-green" onclick="dlBulkVystavitFakturu()" title="Vystavit jednu fakturu pro všechny vybrané DL (musí být od stejného odběratele a ještě nefakturované)">💰 Vystavit fakturu</button>
        <button class="btn-secondary" onclick="dlBulkTisk()" title="Otevře tiskový dialog se všemi vybranými DL za sebou">🖨️ Tisk vybrané</button>
        <button class="btn-secondary" onclick="dlBulkExportCsvZip()">📤 Export CSV ZIP</button>
        <button class="btn-secondary" onclick="dlBulkOdeslatEmailem()" title="Odeslat každý vybraný dodací list e-mailem jeho odběrateli (PDF v příloze)">✉️ Odeslat e-mailem</button>
        <button class="btn-link" onclick="dlClearSelection()">✕ Zrušit výběr</button>
      </div>
    </div>

    <!-- Mobile: kompaktní karty -->
    <div class="mobile-only-block wide-table-block">
      ${data.dodaci_listy.length === 0 ? '<div class="card-block"><div class="empty-state">Žádné dodací listy</div></div>' :
        data.dodaci_listy.map((d) => `
          <div class="dl-card${(state._dlSelected && state._dlSelected.has(d.id)) ? ' is-selected' : ''}" onclick="openDodaciListDetail(${d.id})">
            <div class="dl-card-head">
              <label class="doc-card-check" onclick="event.stopPropagation()" title="Vybrat pro export / e-mail">
                <input type="checkbox" ${(state._dlSelected && state._dlSelected.has(d.id)) ? 'checked' : ''} onchange="dlToggleSelect(${d.id}, this.checked)" data-dl-check="${d.id}">
              </label>
              <div class="dl-card-cislo">${esc(d.cislo)}${upravenoDot(d.obsah_upraveno)}</div>
              ${dlStavBadge(d)}
            </div>
            <div class="dl-card-odb">${esc(d.odberatel_nazev || '⚠️ smazaný odběratel')}</div>
            ${d.misto_nazev ? `<div class="dl-card-misto">📍 ${esc(d.misto_nazev)}</div>` : ''}
            <div class="dl-card-zdroj" onclick="event.stopPropagation();">
              ${d.objednavka_id
                ? `<a href="#" onclick="openObjednavkaDetail(${d.objednavka_id});return false" class="doc-badge obj">🛒 ${esc(d.objednavka_cislo)}</a>`
                : `<span class="doc-badge rucni">✏️ Ruční</span>`}
              ${d.fakturovano && d.prvni_faktura_id
                ? `<a href="#" onclick="openFakturaDetail(${d.prvni_faktura_id});return false" class="doc-badge fa">💰 ${esc(d.faktura_cisla || '')}</a>`
                : ''}
            </div>
            <div class="dl-card-foot">
              <div class="dl-card-datum">
                <span>📅 vystaveno ${fmtDate(d.datum_vystaveni)}</span>
                <span>🚚 dodáno ${fmtDate(d.datum_dodani)}</span>
              </div>
              <div class="dl-card-castka">${fmt(d.castka_celkem)}</div>
            </div>
            <div class="dl-card-actions" onclick="event.stopPropagation();">
              <span class="doc-badges-row">
                ${docBadge('dl', dlPdfUrl(d), '📃 Dodací list', { title: 'Otevřít DL (PDF)' })}
                ${docBadge('fa', (d.fakturovano && d.prvni_faktura_id) ? `../api/faktura.php?id=${d.prvni_faktura_id}` : null, '💰 Faktura', { disabledTitle: 'Faktura zatím nevystavena' })}
              </span>
            </div>
          </div>
        `).join('')
      }
    </div>

    ${pagControlHtml('dl', pg, 'dlGoToPage', 'dlLoadMore')}
  `;
  pagSetupInfinite('dl', pg, 'dlLoadMore'); // 🆕 v3.0.219
}
window.dlLoadMore = function() { renderDodaciListy(state._dlPag.filters, { append: true }); };
window.dlGoToPage = function(p) { renderDodaciListy(state._dlPag.filters, { offset: p * (state._dlPag.limit || 50) }); };

