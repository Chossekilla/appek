// =============================================================
// 🌾 SPOTŘEBA SUROVIN (plánovač do výrobního listu)
// =============================================================
window.vyLoadSpotreba = async function(datum) {
  const host = document.getElementById('vy-spotreba-data');
  if (!host) return;
  host.innerHTML = '<div style="padding:14px;text-align:center;color:var(--text-3)">⏳ Počítám spotřebu surovin…</div>';
  try {
    const d = await api(`admin_vyroba.php?action=spotreba&datum=${datum}`);
    const maPolotovary = Array.isArray(d.polotovary) && d.polotovary.length > 0;
    if ((!d.suroviny || d.suroviny.length === 0) && !maPolotovary) {
      host.innerHTML = `
        <div class="empty-state" style="padding:24px;text-align:center">
          <div style="font-size:14px;margin-bottom:6px">Žádné suroviny k zobrazení</div>
          <div style="font-size:12px;color:var(--text-3)">
            Buď výrobky nemají recepturu (Výrobky → detail → Složení), nebo na tento den nejsou objednávky.
          </div>
        </div>`;
      return;
    }
    d.suroviny = d.suroviny || [];

    const chybi = d.chybi_pocet || 0;
    const naklad = d.celkem_naklad;
    host.innerHTML = `
      <!-- Header s celkovými statistikami -->
      <div style="display:flex;flex-wrap:wrap;gap:12px;margin-bottom:14px">
        <div class="stat-card" style="flex:1;min-width:140px;padding:10px 14px">
          <div class="stat-label">Druhů surovin</div>
          <div class="stat-value" style="font-size:20px">${d.pocet_polozek}</div>
        </div>
        <div class="stat-card" style="flex:1;min-width:140px;padding:10px 14px;${chybi > 0 ? 'border:2px solid #DC2626' : ''}">
          <div class="stat-label">${chybi > 0 ? '⚠️ Chybí na skladě' : '✅ Skladem'}</div>
          <div class="stat-value" style="font-size:20px;${chybi > 0 ? 'color:#DC2626' : 'color:#22863a'}">${chybi > 0 ? chybi : 'OK'}</div>
        </div>
        ${naklad ? `
        <div class="stat-card" style="flex:1;min-width:140px;padding:10px 14px">
          <div class="stat-label">Celkem náklady surovin</div>
          <div class="stat-value" style="font-size:20px">${fmt(naklad)}</div>
        </div>` : ''}
      </div>

      <!-- Tabulka surovin -->
      <table class="table" style="margin:0;font-size:13px">
        <thead>
          <tr>
            <th>Surovina</th>
            <th class="num">Potřeba</th>
            <th class="num">Skladem</th>
            <th class="num">Po výrobě</th>
            <th>Stav</th>
            <th class="num">Náklad</th>
          </tr>
        </thead>
        <tbody>
          ${d.suroviny.map(s => {
            const jed = esc(s.jednotka || 'g');
            const stockPo = parseFloat(s.stock_po);
            const min = parseFloat(s.minimum);
            const okMin = !isNaN(min) && stockPo >= 0 && stockPo < min;
            let stavBadge = '';
            if (!s.ok) {
              stavBadge = `<span style="background:#FEE2E2;color:#991B1B;font-weight:700;padding:3px 10px;border-radius:8px;font-size:11px">⚠ Chybí ${parseFloat(s.chybi_mn).toFixed(2).replace(/\.?0+$/, '').replace('.', ',')} ${jed}</span>`;
            } else if (okMin) {
              stavBadge = `<span style="background:#FEF3C7;color:#92400e;font-weight:700;padding:3px 10px;border-radius:8px;font-size:11px">⚠ Klesne pod min</span>`;
            } else {
              stavBadge = `<span style="background:#DCFCE7;color:#166534;font-weight:700;padding:3px 10px;border-radius:8px;font-size:11px">✓ OK</span>`;
            }
            return `
              <tr ${!s.ok ? 'style="background:rgba(220,38,38,0.06)"' : ''}>
                <td><strong>${esc(s.nazev)}</strong></td>
                <td class="num"><strong>${parseFloat(s.potreba).toFixed(3).replace(/\.?0+$/, '').replace('.', ',')}</strong> ${jed}</td>
                <td class="num">${parseFloat(s.skladem).toFixed(2).replace(/\.?0+$/, '').replace('.', ',')} ${jed}</td>
                <td class="num" style="color:${s.ok ? 'var(--text)' : '#DC2626'};font-weight:${s.ok ? '500' : '700'}">${stockPo.toFixed(2).replace(/\.?0+$/, '').replace('.', ',')} ${jed}</td>
                <td>${stavBadge}</td>
                <td class="num">${s.naklad !== null ? fmt(s.naklad) : '—'}</td>
              </tr>
            `;
          }).join('')}
        </tbody>
      </table>

      ${maPolotovary ? `
      <!-- 🧩 Stockované polotovary (sleduje_sklad=1) — vyrábí se v dávkách, odečítají se ze skladu hotových -->
      <div style="margin-top:18px">
        <div style="font-weight:700;font-size:13px;margin-bottom:8px;display:flex;align-items:center;gap:6px">
          🧩 Polotovary ze skladu
          <span style="font-weight:400;color:var(--text-3);font-size:11px">(nerozpadají se na suroviny — vyrábějí se v dávkách dopředu)</span>
        </div>
        <table class="table" style="margin:0;font-size:13px">
          <thead><tr><th>Polotovar</th><th class="num">Potřeba</th><th class="num">Skladem</th><th>Stav</th><th></th></tr></thead>
          <tbody>
            ${d.polotovary.map(p => {
              const jed = esc(p.jednotka || 'ks');
              const chybiP = parseFloat(p.chybi) || 0;
              const okP = chybiP <= 0;
              const navrh = Math.max(chybiP, 1);
              const nazevEsc = esc(p.nazev || ('#' + p.vyrobek_id));
              const stav = okP
                ? '<span style="background:#DCFCE7;color:#166534;font-weight:700;padding:3px 10px;border-radius:8px;font-size:11px">✓ Skladem</span>'
                : `<span style="background:#FEE2E2;color:#991B1B;font-weight:700;padding:3px 10px;border-radius:8px;font-size:11px">⚠ Chybí ${chybiP.toFixed(2).replace(/\.?0+$/, '').replace('.', ',')} ${jed}</span>`;
              return `
                <tr ${okP ? '' : 'style="background:rgba(220,38,38,0.06)"'}>
                  <td><strong>${nazevEsc}</strong></td>
                  <td class="num"><strong>${(parseFloat(p.potreba) || 0).toFixed(2).replace(/\.?0+$/, '').replace('.', ',')}</strong> ${jed}</td>
                  <td class="num">${(parseFloat(p.skladem) || 0).toFixed(2).replace(/\.?0+$/, '').replace('.', ',')} ${jed}</td>
                  <td>${stav}</td>
                  <td class="num"><button class="btn-secondary" style="font-size:12px;padding:5px 12px;white-space:nowrap" onclick="vyVyrobitPolotovar(${p.vyrobek_id}, ${navrh}, '${datum}', '${nazevEsc.replace(/'/g, "\\'")}')">🏭 Vyrobit dávku</button></td>
                </tr>`;
            }).join('')}
          </tbody>
        </table>
      </div>` : ''}

      <!-- Akce -->
      <div style="display:flex;gap:10px;flex-wrap:wrap;margin-top:14px;padding-top:14px;border-top:1px solid var(--border)">
        ${chybi > 0
          ? `<div style="flex:1;padding:10px 14px;background:#FEE2E2;color:#991B1B;border-radius:8px;font-size:13px;font-weight:600">⚠️ Některé suroviny nemáš na skladě — doporučujeme nejdřív naskladnit</div>`
          : `<button class="btn-primary btn-green" onclick="vyOdepsatSuroviny('${datum}')" style="font-size:14px;font-weight:700;padding:12px 22px">✅ Vyrobeno — odepsat suroviny ze skladu</button>`
        }
      </div>
    `;
  } catch (e) {
    host.innerHTML = `<div style="color:var(--danger-text);padding:14px">Chyba: ${esc(e.message)}</div>`;
  }
};

// Odpis surovin ze skladu po výrobě
window.vyOdepsatSuroviny = async function(datum) {
  if (!await confirmDelete2x({
    co: `odepsat suroviny ze skladu za datum ${fmtDate(datum)}`,
    detail: 'Odečte se VŠE podle aktuálních objednávek a receptur. Pohyb se zapíše do historie skladu. Doporučujeme odepisovat až po skutečné výrobě.'
  })) return;
  try {
    const r = await api(`admin_vyroba.php?action=odepsat_suroviny`, {
      method: 'POST',
      body: JSON.stringify({ datum }),
    });
    // Invalidate cache surovin
    state._suroviny_cache = null;
    state._suroviny_full_cache = null;
    alert(t('warehouse_writeoff', { n: r.odepsano }));
    vyLoadSpotreba(datum);   // refresh data
  } catch (e) { alert('Chyba: ' + e.message); }
};

// 🆕 v3.0.304 — Výroba dávky stockovaného polotovaru: odečte suroviny dle receptury + naskladní hotový polotovar
window.vyVyrobitPolotovar = async function(vid, navrh, datum, nazev) {
  const label = nazev || ('#' + vid);
  const q = prompt(`🏭 Vyrobit dávku polotovaru „${label}" — kolik ks?\n\nOdečtou se suroviny dle receptury a naskladní se hotový polotovar (ten se pak při výrobě dortů ubírá ze skladu místo rozpadu na suroviny).`, String(navrh || 1));
  if (q === null) return;
  const mnozstvi = parseFloat(String(q).replace(',', '.'));
  if (!(mnozstvi > 0)) { alert('Neplatné množství'); return; }
  try {
    const r = await api('admin_vyroba.php?action=vyrobit_polotovar', {
      method: 'POST', body: JSON.stringify({ vyrobek_id: vid, mnozstvi }),
    });
    state._suroviny_cache = null;
    state._suroviny_full_cache = null;
    alert(`✅ Vyrobeno ${r.vyrobeno ?? mnozstvi} ks „${label}".\nOdepsáno surovin: ${r.surovin_odepsano ?? '?'}.\nNa skladě polotovaru nyní: ${r.skladem ?? '?'} ks.`);
    vyLoadSpotreba(datum);   // refresh — krém už bude skladem
  } catch (e) { alert('Chyba výroby polotovaru: ' + e.message); }
};

// ====== Kalendář výroby ======
window.vyrobaToggleKalendar = function() {
  state._vyKalShow = !state._vyKalShow;
  const el = document.getElementById('vy-kalendar');
  const btn = document.getElementById('vy-kal-toggle');
  if (!el) return;
  if (state._vyKalShow) {
    el.style.display = 'block';
    btn?.classList.add('active');
    loadVyrobaKalendar(state._vyKalRok, state._vyKalMesic);
  } else {
    el.style.display = 'none';
    btn?.classList.remove('active');
  }
};

window.vyrobaKalendarPosun = function(delta) {
  let r = state._vyKalRok, m = state._vyKalMesic + delta;
  if (m < 1) { m = 12; r--; }
  if (m > 12) { m = 1; r++; }
  state._vyKalRok = r;
  state._vyKalMesic = m;
  loadVyrobaKalendar(r, m);
};

window.vyrobaKalendarToday = function() {
  const d = new Date();
  state._vyKalRok = d.getFullYear();
  state._vyKalMesic = d.getMonth() + 1;
  loadVyrobaKalendar(state._vyKalRok, state._vyKalMesic);
};

// 🆕 v3.0.77 — Přepínač view: kalendář vs tabulka (persist v localStorage)
window.vyrobaKalendarSetView = function(view) {
  try { localStorage.setItem('appek_vy_kal_view', view); } catch (e) {}
  loadVyrobaKalendar(state._vyKalRok, state._vyKalMesic);
};

// 🆕 v3.0.77 — Tabulkový render výroby (řádky = dny s objednávkami)
function renderVyrobaKalendarTable(wrap, data, cells, rok, mesic, mesice) {
  const rows = cells.filter(c => !c.empty && c.stats).map(c => `
    <tr ${c.isToday ? 'style="background:#FEF3C7"' : ''} ${c.isSelected ? 'style="background:#DCFCE7"' : ''} onclick="renderVyrobaAutoForDate('${c.datum}')">
      <td><strong>${c.day}.${mesic}.</strong></td>
      <td>${esc(['Po','Út','St','Čt','Pá','So','Ne'][(new Date(c.datum).getDay() + 6) % 7])}</td>
      <td class="num">${c.stats.celkem_kusu} ks</td>
      <td class="num">${c.stats.pocet_obj || 0}</td>
      <td class="num">${c.stats.pocet_pob || 0}</td>
    </tr>
  `).join('') || `<tr><td colspan="5" style="text-align:center;color:var(--text-3);padding:30px">Žádné objednávky v tomto měsíci</td></tr>`;

  wrap.innerHTML = `
    <div class="vy-kal-card">
      <div class="vy-kal-head">
        <button class="vy-kal-nav" onclick="vyrobaKalendarPosun(-1)" title="Předchozí měsíc">‹</button>
        <div class="vy-kal-title"><strong>${mesice[mesic - 1]}</strong> ${rok}</div>
        <button class="vy-kal-nav" onclick="vyrobaKalendarPosun(1)" title="Následující měsíc">›</button>
        <button class="btn-secondary" onclick="vyrobaKalendarToday()" style="font-size:11px;padding:4px 10px;margin-left:6px">Dnes</button>
        <button class="btn-secondary" onclick="vyrobaKalendarSetView('cal')" style="font-size:11px;padding:4px 10px;margin-left:6px" title="Přepnout na kalendář">📅 Kalendář</button>
      </div>
      <table class="table" style="margin-top:10px;font-size:13px">
        <thead>
          <tr>
            <th>Datum</th>
            <th>Den</th>
            <th class="num">Kusy</th>
            <th class="num">Objednávek</th>
            <th class="num">Poboček</th>
          </tr>
        </thead>
        <tbody>${rows}</tbody>
      </table>
    </div>
  `;
}

async function loadVyrobaKalendar(rok, mesic) {
  const wrap = document.getElementById('vy-kalendar');
  if (!wrap) return;
  wrap.innerHTML = '<div class="vy-kal-loading" style="padding:30px;text-align:center;color:var(--text-3)">⏳ Načítám…</div>';

  let data;
  try {
    data = await api(`admin_vyroba.php?action=kalendar&rok=${rok}&mesic=${mesic}`);
  } catch (e) {
    wrap.innerHTML = `<div style="padding:14px;color:var(--danger-text)">Chyba: ${esc(e.message)}</div>`;
    return;
  }

  const mesice = ['leden','únor','březen','duben','květen','červen','červenec','srpen','září','říjen','listopad','prosinec'];
  const dnyShort = ['Po','Út','St','Čt','Pá','So','Ne'];

  // Najdi maximum kusů pro heatmapu
  let maxKusu = 0;
  Object.values(data.dny).forEach(d => { if (d.celkem_kusu > maxKusu) maxKusu = d.celkem_kusu; });

  // Sestav cell array — začínáme pondělím
  const firstOfMonth = new Date(rok, mesic - 1, 1);
  // JS day: 0=Ne, 1=Po, …; my chceme Po=0
  const startDay = (firstOfMonth.getDay() + 6) % 7;
  const daysInMonth = new Date(rok, mesic, 0).getDate();
  const today = new Date().toISOString().split('T')[0];
  const selected = state._vyrobaDatum;

  const cells = [];
  // Prázdné buňky před 1. dnem
  for (let i = 0; i < startDay; i++) cells.push({ empty: true });
  for (let d = 1; d <= daysInMonth; d++) {
    const datumStr = `${rok}-${String(mesic).padStart(2, '0')}-${String(d).padStart(2, '0')}`;
    const stats = data.dny[datumStr] || null;
    cells.push({
      day: d,
      datum: datumStr,
      stats,
      isToday: datumStr === today,
      isSelected: datumStr === selected,
      isWeekend: (firstOfMonth.getDay() + d - 1) % 7 === 5 || (firstOfMonth.getDay() + d - 1) % 7 === 6,
    });
  }
  // Doplň prázdné buňky na celé řádky (násobky 7)
  while (cells.length % 7 !== 0) cells.push({ empty: true });

  // 🆕 v3.0.77 — View toggle: calendar vs table (persist v localStorage)
  let viewMode = 'cal';
  try { viewMode = localStorage.getItem('appek_vy_kal_view') || 'cal'; } catch (e) {}
  if (viewMode === 'table') {
    return renderVyrobaKalendarTable(wrap, data, cells, rok, mesic, mesice);
  }
  wrap.innerHTML = `
    <div class="vy-kal-card">
      <div class="vy-kal-head">
        <button class="vy-kal-nav" onclick="vyrobaKalendarPosun(-1)" title="Předchozí měsíc">‹</button>
        <div class="vy-kal-title">
          <strong>${mesice[mesic - 1]}</strong> ${rok}
        </div>
        <button class="vy-kal-nav" onclick="vyrobaKalendarPosun(1)" title="Následující měsíc">›</button>
        <button class="btn-secondary" onclick="vyrobaKalendarToday()" style="font-size:11px;padding:4px 10px;margin-left:6px">Dnes</button>
        <button class="btn-secondary" onclick="vyrobaKalendarSetView('table')" style="font-size:11px;padding:4px 10px;margin-left:6px" title="Přepnout na tabulku">📋 Tabulka</button>
      </div>

      <div class="vy-kal-grid">
        ${dnyShort.map((d, i) => `<div class="vy-kal-dayhead ${i >= 5 ? 'weekend' : ''}">${d}</div>`).join('')}
        ${cells.map(c => {
          if (c.empty) return '<div class="vy-kal-cell empty"></div>';
          const intenzita = c.stats && maxKusu > 0 ? Math.min(1, c.stats.celkem_kusu / maxKusu) : 0;
          const cls = [
            'vy-kal-cell',
            c.stats ? 'has-orders' : '',
            c.isToday ? 'today' : '',
            c.isSelected ? 'selected' : '',
            c.isWeekend ? 'weekend' : '',
          ].filter(Boolean).join(' ');
          const heatStyle = c.stats ? `style="--vy-kal-heat:${(0.15 + intenzita * 0.55).toFixed(2)}"` : '';
          return `
            <button class="${cls}" ${heatStyle} onclick="renderVyrobaAutoForDate('${c.datum}')">
              <div class="vy-kal-day-num">${c.day}</div>
              ${c.stats ? `
                <div class="vy-kal-day-stats">
                  <div class="vy-kal-day-mn">${c.stats.celkem_kusu} ks</div>
                  <div class="vy-kal-day-obj">${c.stats.pocet_objednavek} obj · ${c.stats.pocet_mist} pob.</div>
                </div>
              ` : '<div class="vy-kal-day-empty">—</div>'}
            </button>
          `;
        }).join('')}
      </div>

      <div class="vy-kal-legenda">
        <span class="vy-kal-legenda-item"><span class="vy-kal-cell-mini today"></span> Dnes</span>
        <span class="vy-kal-legenda-item"><span class="vy-kal-cell-mini selected"></span> Vybráno</span>
        <span class="vy-kal-legenda-item"><span class="vy-kal-cell-mini has-orders"></span> Má objednávky</span>
        <span class="vy-kal-legenda-item"><span class="vy-kal-cell-mini"></span> Žádné objednávky</span>
      </div>
    </div>
  `;
}

async function renderVyrobaManual() {
  const div = document.getElementById('vyroba-content');
  // 🆕 v2.9.289 — defenzivní fallback
  let list;
  try { list = await api('admin_vyroba.php'); }
  catch (e) { list = []; }
  if (!Array.isArray(list)) list = [];

  div.innerHTML = `
    <div style="margin-bottom:12px;">
      <button class="btn-primary btn-green btn-big-action" onclick="otevritNovyVyrobniList()" style="font-size:18px !important;font-weight:800 !important;padding:18px 32px !important;min-height:64px !important;border-radius:12px !important;letter-spacing:0.3px !important">+ Nový výrobní list</button>
    </div>
    
    <div class="card-block">
      ${list.length === 0 ? '<div class="empty-state">Žádné uložené výrobní listy</div>' : `
        <table class="table">
          <thead>
            <tr><th>Číslo</th><th>Datum dodání</th><th>Stav</th><th class="num">Položek</th><th class="num">Celkem ks</th><th></th></tr>
          </thead>
          <tbody>
            ${list.map((vl) => `
              <tr class="row-clickable" onclick="otevritVyrobniList(${vl.id})">
                <td><strong>${esc(vl.cislo)}</strong></td>
                <td>${fmtDate(vl.datum_dodani)}</td>
                <td><span class="status">${esc(vl.stav)}</span></td>
                <td class="num">${vl.pocet_polozek}</td>
                <td class="num">${Math.round(vl.celkem_ks)} ks</td>
                <td onclick="event.stopPropagation();">
                  ${adminOnly(`<button class="btn-danger" style="font-size:11px;padding:4px 10px;" onclick="smazatVyrobniList(${vl.id})">Smazat</button>`)}
                </td>
              </tr>
            `).join('')}
          </tbody>
        </table>
      `}
    </div>
  `;
}

window.otevritNovyVyrobniList = async function() {
  const vyrobky = (await api('admin_vyrobky.php')).vyrobky.filter((v) => v.aktivni == 1);
  const tomorrow = new Date();
  tomorrow.setDate(tomorrow.getDate() + 1);
  
  // Uložím data před otevřením modálu
  window._vlVyrobky = vyrobky.map((v) => ({ id: v.id, nazev: v.nazev, cislo: v.cislo, ikona: v.kategorie_ikona }));
  
  openModal('+ Nový výrobní list', `
    <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;margin-bottom:14px">
      <div style="background:#F7F8FA;border:1px solid #E8D5B0;border-radius:8px;padding:14px">
        <div style="font-size:11px;text-transform:uppercase;letter-spacing:0.5px;color:#854F0B;margin-bottom:10px;font-weight:600">📅 Termíny</div>
        <label style="display:block;margin-bottom:8px">
          <span style="font-size:12px;color:var(--text-2);display:block;margin-bottom:4px">Datum dodání *</span>
          <input type="date" class="input" id="vl-datum" value="${tomorrow.toISOString().split('T')[0]}" style="width:100%">
        </label>
        <label style="display:block">
          <span style="font-size:12px;color:var(--text-2);display:block;margin-bottom:4px">Datum výroby</span>
          <input type="date" class="input" id="vl-vyroba" value="${new Date().toISOString().split('T')[0]}" style="width:100%">
        </label>
      </div>
      <div style="background:#EFF6FF;border:1px solid #B5D4F4;border-radius:8px;padding:14px">
        <div style="font-size:11px;text-transform:uppercase;letter-spacing:0.5px;color:#0C447C;margin-bottom:10px;font-weight:600">📋 Akce</div>
        <button class="btn-secondary" style="width:100%;margin-bottom:8px;font-size:13px" onclick="vlNacistZobjednavek()">📥 Načíst z objednávek</button>
        <label style="display:block">
          <span style="font-size:12px;color:var(--text-2);display:block;margin-bottom:4px">Poznámka</span>
          <input type="text" class="input" id="vl-pozn" placeholder="Volitelná..." style="width:100%">
        </label>
      </div>
    </div>

    <h3 style="margin:0 0 8px;font-size:15px">Položky výroby</h3>
    <div id="vl-polozky"></div>
    <button class="btn-secondary" style="margin-top:8px;font-size:13px" onclick="vlPridatPolozku()">+ Přidat výrobek</button>

    <div class="form-actions">
      <button class="btn-secondary" onclick="closeModal()">Zrušit</button>
      <div style="flex:1"></div>
      <button class="btn-primary btn-green" onclick="ulozitVyrobniList()">💾 Uložit výrobní list</button>
    </div>
  `);
  setTimeout(() => vlPridatPolozku(), 50);
};

window.vlPridatPolozku = function(vyrobek_id = null, mnozstvi = 1) {
  const div = document.getElementById('vl-polozky');
  const row = document.createElement('div');
  row.style.cssText = 'display:flex;gap:8px;margin-bottom:6px;align-items:center;';
  // 🆕 v3.0.279 — searchable combobox místo giant <select> (hidden input nese .vl-vyrobek → čtení beze změny)
  row.innerHTML = `
    <div style="flex:2;min-width:0">${vyrComboRow('vl-vyrobek', window._vlVyrobky, vyrobek_id)}</div>
    <input type="number" class="form-input vl-mn" min="1" value="${mnozstvi}" style="width:90px;" placeholder="Ks">
    <button class="btn-danger" style="padding:8px 12px;" onclick="this.parentElement.remove()">×</button>
  `;
  div.appendChild(row);
};

window.vlNacistZobjednavek = async function() {
  const datum = document.getElementById('vl-datum').value;
  if (!datum) return alert('Vyberte datum');
  
  try {
    const data = await api(`admin_vyroba.php?datum=${datum}`);
    if (data.souhrn.length === 0) return alert('Na tento den nejsou žádné objednávky');
    
    document.getElementById('vl-polozky').innerHTML = '';
    data.souhrn.forEach((s) => vlPridatPolozku(s.id, Math.round(s.celkem)));
  } catch (e) { alert('Chyba: ' + e.message); }
};

window.ulozitVyrobniList = async function() {
  const datum = document.getElementById('vl-datum').value;
  if (!datum) return alert('Vyplňte datum dodání');
  
  const polozky = [];
  document.querySelectorAll('#vl-polozky > div').forEach((row) => {
    const v = parseInt(row.querySelector('.vl-vyrobek').value);
    const m = parseFloat(row.querySelector('.vl-mn').value);
    if (v && m > 0) polozky.push({ vyrobek_id: v, mnozstvi: m });
  });
  if (polozky.length === 0) return alert('Přidejte alespoň jednu položku');
  
  try {
    await api('admin_vyroba.php', {
      method: 'POST',
      body: JSON.stringify({
        datum_dodani: datum,
        datum_vyroby: document.getElementById('vl-vyroba').value || null,
        poznamka: document.getElementById('vl-pozn').value || null,
        polozky,
      }),
    });
    closeModal();
    navigate('vyroba');
  } catch (e) { alert('Chyba: ' + e.message); }
};

window.otevritVyrobniList = async function(id) {
  const vl = await api(`admin_vyroba.php?id=${id}`);
  openModal(`Výrobní list ${vl.cislo}`, `
    <div class="detail-row"><span class="label">Datum dodání</span><span><strong>${fmtDate(vl.datum_dodani)}</strong></span></div>
    <div class="detail-row"><span class="label">Datum výroby</span><span>${fmtDate(vl.datum_vyroby)}</span></div>
    <div class="detail-row"><span class="label">Stav</span><span>${esc(vl.stav)}</span></div>
    ${vl.poznamka ? `<div class="detail-row"><span class="label">Poznámka</span><span>${esc(vl.poznamka)}</span></div>` : ''}
    
    <h3 style="margin:16px 0 8px;font-size:15px;font-weight:500;">Položky</h3>
    <table class="table">
      <thead><tr><th></th><th>Výrobek</th><th class="num">Plánované</th><th class="num">Vyrobené</th></tr></thead>
      <tbody>
        ${vl.polozky.map((p) => `
          <tr>
            <td>${esc(p.kategorie_ikona || '🥖')}</td>
            <td><strong>${esc(p.vyrobek_nazev)}</strong> <span style="color:var(--text-3);font-size:12px;">${esc(p.vyrobek_cislo || '')}</span></td>
            <td class="num"><strong>${Math.round(p.mnozstvi)} ${esc(p.jednotka || 'ks')}</strong></td>
            <td class="num">
              <input type="number" class="form-input" style="width:80px;text-align:right;display:inline-block;padding:4px 8px;" 
                     value="${p.vyrobeno !== null ? Math.round(p.vyrobeno) : ''}"
                     placeholder="—" data-id="${p.id}">
            </td>
          </tr>
        `).join('')}
      </tbody>
    </table>
    
    <div class="form-actions">
      ${adminOnly(`<div class="form-actions-icons-row"><button class="btn-danger-corner" onclick="smazatVyrobniList(${vl.id})" title="Smazat výrobní list" aria-label="Smazat výrobní list">🗑️</button></div>`)}
      <div style="flex:1"></div>
      <!-- "Zavřít" smazáno v v2.5.11 — × v rohu modal-card stačí (větší klikací plocha) -->
      <button class="btn-primary" onclick="ulozitVyrobeno(${vl.id})">Uložit vyrobené</button>
    </div>
  `);
};

window.ulozitVyrobeno = async function(id) {
  const inputs = document.querySelectorAll('#modal-body input[data-id]');
  const vyrobeno = [];
  inputs.forEach((inp) => {
    if (inp.value !== '') vyrobeno.push({ id: parseInt(inp.dataset.id), mnozstvi: parseFloat(inp.value) });
  });
  await api('admin_vyroba.php', { method: 'PUT', body: JSON.stringify({ id, vyrobeno }) });
  closeModal();
  navigate('vyroba');
};

window.smazatVyrobniList = async function(id) {
  if (!await confirmDelete2x('tento výrobní list')) return;
  await api(`admin_vyroba.php?id=${id}`, { method: 'DELETE' });
  closeModal();
  navigate('vyroba');
};

