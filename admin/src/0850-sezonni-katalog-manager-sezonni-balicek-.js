// =============================================================
// 🍰 SEZÓNNÍ KATALOG MANAGER (Sezónní balíček) — full UI
// =============================================================
async function renderSeasonalCatalog() {
  const c = document.getElementById('content');
  const tab = state._seasonalTab || 'calendar';
  state._seasonalDate = state._seasonalDate || new Date().toISOString().slice(0, 10);

  c.innerHTML = `
    <div class="page-head">
      <div>
        <h1 class="page-title">🍰 Sezónní katalog</h1>
        <p class="page-sub">Sezónní okna pro výrobky · B2B portal je filtruje automaticky.</p>
      </div>
      <div style="display:flex;gap:8px;flex-wrap:wrap;align-items:center">
        <button class="btn-secondary" onclick="navigate('dashboard')">← Dashboard</button>
      </div>
    </div>

    <!-- 🚀 Rychlé akce — pre-fab šablony a email katalog -->
    <div style="display:grid;grid-template-columns:repeat(auto-fit, minmax(280px, 1fr));gap:14px;margin-bottom:18px">
      <a href="feature-seasonal-templates.html"
         style="display:flex;gap:14px;padding:18px 20px;background:linear-gradient(135deg,#fff8e8,#fff);border:2px solid #BA7517;border-radius:14px;text-decoration:none;color:inherit;transition:all 0.2s">
        <div style="font-size:38px;line-height:1">🍂</div>
        <div>
          <div style="font-size:15px;font-weight:800;color:#1d1d1f">Sezónní šablony</div>
          <div style="font-size:12.5px;color:#854F0B;margin-top:4px;line-height:1.4">
            One-click vložení Vánoc / Velikonoc / Valentýna / Halloweenu — vyrobky s cenami + alergeny + gramáž
          </div>
          <div style="margin-top:8px;display:inline-block;background:#BA7517;color:#fff;padding:4px 10px;border-radius:6px;font-size:11px;font-weight:700">⚡ One-click vložit</div>
        </div>
      </a>
      <a href="feature-seasonal-catalog.html"
         style="display:flex;gap:14px;padding:18px 20px;background:linear-gradient(135deg,#fff0f4,#fff);border:2px solid #E91E63;border-radius:14px;text-decoration:none;color:inherit;transition:all 0.2s">
        <div style="font-size:38px;line-height:1">📧</div>
        <div>
          <div style="font-size:15px;font-weight:800;color:#1d1d1f">Sezónní katalog & email</div>
          <div style="font-size:12.5px;color:#9d174d;margin-top:4px;line-height:1.4">
            Vyber výrobky + téma (8 šablon) → tematický email odběratelům · tisk · PDF
          </div>
          <div style="margin-top:8px;display:inline-block;background:#E91E63;color:#fff;padding:4px 10px;border-radius:6px;font-size:11px;font-weight:700">📧 Hromadný email</div>
        </div>
      </a>
    </div>

    <div class="nastaveni-tabs" role="tablist" style="margin-bottom:14px">
      <button class="nastaveni-tab ${tab === 'calendar' ? 'active' : ''}" onclick="state._seasonalTab='calendar';renderSeasonalCatalog()">📅 Kalendář sezón</button>
      <button class="nastaveni-tab ${tab === 'products' ? 'active' : ''}" onclick="state._seasonalTab='products';renderSeasonalCatalog()">🥖 Přiřazení výrobků</button>
      <button class="nastaveni-tab ${tab === 'manage' ? 'active' : ''}" onclick="state._seasonalTab='manage';renderSeasonalCatalog()">⚙️ Vlastní sezóny</button>
    </div>
    <div id="seasonal-body">${skeletonCards(3)}</div>
  `;

  if (tab === 'calendar') return renderSeasonalCalendar();
  if (tab === 'products') return renderSeasonalProducts();
  if (tab === 'manage')   return renderSeasonalManage();
}

async function renderSeasonalCalendar() {
  const body = document.getElementById('seasonal-body');
  if (!body) return;
  const selectedDate = state._seasonalDate || new Date().toISOString().slice(0, 10);

  let data;
  try { data = await api('admin_seasonal.php?date=' + selectedDate); }
  catch (e) { body.innerHTML = `<div class="alert err">${esc(e.message)}</div>`; return; }

  // Měsíční kalendář — průchod 12 měsíců s vyznačením kdy je která sezóna aktivní
  const year = new Date(selectedDate).getFullYear();
  const monthNames = ['Leden','Únor','Březen','Duben','Květen','Červen','Červenec','Srpen','Září','Říjen','Listopad','Prosinec'];
  const dayNamesShort = ['Po','Út','St','Čt','Pá','So','Ne'];

  const selectedMonth = new Date(selectedDate).getMonth();
  const monthRender = (mIdx) => {
    const monthDate = new Date(year, mIdx, 1);
    const startWeekday = (monthDate.getDay() + 6) % 7; // Po=0
    const lastDay = new Date(year, mIdx + 1, 0).getDate();
    const cells = [];
    for (let i = 0; i < startWeekday; i++) cells.push('<div></div>');
    for (let d = 1; d <= lastDay; d++) {
      const md = String(mIdx + 1).padStart(2, '0') + '-' + String(d).padStart(2, '0');
      const fullDate = year + '-' + md;
      const isToday = fullDate === data.today;
      const isSelected = fullDate === selectedDate;
      // Zjistit které sezóny jsou aktivní pro tento den
      const activeOn = (data.seasons || []).filter(s => {
        if (s.start_md <= s.end_md) return md >= s.start_md && md <= s.end_md;
        return md >= s.start_md || md <= s.end_md;
      });
      const bg = activeOn.length > 0
        ? activeOn[0].color + '40'
        : 'transparent';
      const border = isSelected ? '2px solid var(--primary)' : (isToday ? '2px solid #0a84ff' : '1px solid transparent');
      cells.push(`
        <button onclick="state._seasonalDate='${fullDate}';renderSeasonalCalendar()"
                style="background:${bg};border:${border};border-radius:6px;padding:4px;font-size:12px;cursor:pointer;font-family:inherit;color:inherit;position:relative;min-height:32px;display:flex;align-items:center;justify-content:center"
                title="${activeOn.map(s => s.label).join(', ') || ''}">
          ${d}
          ${activeOn.length > 0 ? `<span style="position:absolute;bottom:2px;right:2px;font-size:9px">${activeOn.length}</span>` : ''}
        </button>
      `);
    }
    return `
      <div class="card-block" style="padding:10px ${mIdx === selectedMonth ? '12px' : '10px'}; ${mIdx === selectedMonth ? 'border:2px solid var(--primary)' : ''}">
        <h4 style="margin:0 0 6px;font-size:13px;text-align:center">${esc(monthNames[mIdx])}</h4>
        <div style="display:grid;grid-template-columns:repeat(7,1fr);gap:2px;font-size:10px;color:var(--text-3);text-align:center;font-weight:700;margin-bottom:2px">
          ${dayNamesShort.map(d => `<div>${d}</div>`).join('')}
        </div>
        <div style="display:grid;grid-template-columns:repeat(7,1fr);gap:2px">
          ${cells.join('')}
        </div>
      </div>
    `;
  };

  const activeSelected = (data.seasons || []).filter(s => s.active_on_selected);
  const activeToday = (data.seasons || []).filter(s => s.active_today);

  body.innerHTML = `
    <div class="card-block" style="margin-bottom:14px">
      <div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:10px">
        <div>
          <strong style="font-size:15px">📅 Vybraný den: ${new Date(selectedDate).toLocaleDateString('cs-CZ')}</strong>
          ${selectedDate === data.today ? '<span style="background:#DBEAFE;color:#1E40AF;padding:2px 8px;border-radius:999px;font-size:10.5px;font-weight:700;margin-left:8px">DNES</span>' : ''}
        </div>
        <div style="display:flex;gap:6px">
          <button class="btn-secondary" onclick="state._seasonalDate=new Date(state._seasonalDate || new Date()).toISOString().slice(0,10);state._seasonalDate=new Date(new Date(state._seasonalDate).getTime() - 86400000).toISOString().slice(0,10);renderSeasonalCalendar()">← Předchozí den</button>
          <input type="date" class="form-input" value="${selectedDate}" onchange="state._seasonalDate=this.value;renderSeasonalCalendar()" style="width:auto">
          <button class="btn-secondary" onclick="state._seasonalDate=new Date(new Date(state._seasonalDate).getTime() + 86400000).toISOString().slice(0,10);renderSeasonalCalendar()">Další den →</button>
          <button class="btn-primary" onclick="state._seasonalDate='${data.today}';renderSeasonalCalendar()">Dnes</button>
        </div>
      </div>
      <div style="margin-top:10px">
        ${activeSelected.length === 0 ? `
          <div style="color:var(--text-3);font-size:13px">⏸️ Žádná sezóna není pro tento den aktivní.</div>
        ` : `
          <div style="display:flex;gap:6px;flex-wrap:wrap">
            ${activeSelected.map(s => `
              <span style="background:${s.color}22;border:1.5px solid ${s.color};color:var(--text);padding:6px 12px;border-radius:999px;font-size:13px;font-weight:600">
                ${esc(s.label)} <span style="color:var(--text-3);font-size:11px">· ${s.count} výrobků</span>
              </span>
            `).join('')}
          </div>
        `}
      </div>
    </div>

    <div class="card-block" style="margin-bottom:14px">
      <h3 style="margin:0 0 10px">📆 Roční přehled ${year}</h3>
      <p style="font-size:12px;color:var(--text-3);margin-bottom:10px">Klikni na den pro výběr. Barevné dny = aktivní sezóna.</p>
      <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:8px">
        ${monthNames.map((_, i) => monthRender(i)).join('')}
      </div>
    </div>

    <div class="card-block">
      <h3 style="margin:0 0 10px">🎨 Všechny sezóny (${(data.seasons || []).length})</h3>
      <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:8px">
        ${(data.seasons || []).map(s => `
          <div style="padding:12px;border-radius:10px;background:${s.active_today ? s.color + '22' : 'var(--surface-2)'};border:2px solid ${s.active_today ? s.color : 'var(--border)'}">
            <div style="font-size:14px;font-weight:700">${esc(s.label)}</div>
            <div style="font-size:11px;color:var(--text-3);margin-top:2px">${esc(s.start_md)} → ${esc(s.end_md)}</div>
            <div style="font-size:11px;color:var(--text-3);margin-top:2px">${s.count} výrobků · ${s.is_default ? 'default' : 'vlastní'}</div>
            <div style="font-size:11px;font-weight:700;margin-top:4px;color:${s.active_today ? s.color : 'var(--text-3)'}">${s.active_today ? '🟢 DNES AKTIVNÍ' : '⏸️ mimo'}</div>
          </div>
        `).join('')}
      </div>
    </div>
  `;
}

async function renderSeasonalProducts() {
  const body = document.getElementById('seasonal-body');
  if (!body) return;
  let seasons, products;
  try {
    [seasons, products] = await Promise.all([
      api('admin_seasonal.php'),
      api('admin_seasonal.php?action=products'),
    ]);
  } catch (e) { body.innerHTML = `<div class="alert err">${esc(e.message)}</div>`; return; }

  const allSeasons = seasons.seasons || [];
  const filterSezona = state._seasonalProductFilter || '';
  const products_filtered = (products.products || []).filter(p =>
    filterSezona === '' ? true :
    filterSezona === 'none' ? !p.sezona :
    p.sezona === filterSezona
  );

  body.innerHTML = `
    <div class="card-block" style="margin-bottom:14px">
      <div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:10px">
        <div>
          <strong>🥖 ${products_filtered.length} z ${(products.products || []).length} výrobků</strong>
        </div>
        <select class="form-input" onchange="state._seasonalProductFilter=this.value;renderSeasonalProducts()" style="width:auto">
          <option value="">Všechny výrobky</option>
          <option value="none" ${filterSezona === 'none' ? 'selected' : ''}>Bez sezóny</option>
          ${allSeasons.map(s => `<option value="${esc(s.key)}" ${filterSezona === s.key ? 'selected' : ''}>${esc(s.label)} (${s.count})</option>`).join('')}
        </select>
      </div>
    </div>
    <div class="card-block">
      <div style="background:#DCFCE7;border-left:3px solid #16A34A;padding:10px 12px;border-radius:8px;font-size:12.5px;color:#166534;margin-bottom:14px">
        ✅ Změna se ukládá automaticky. Výrobek s přiřazenou sezónou se v B2B portálu zobrazí <strong>jen v aktivním okně</strong>.
      </div>
      <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(280px,1fr));gap:8px">
        ${products_filtered.map(p => {
          const currentS = allSeasons.find(s => s.key === p.sezona);
          return `
            <div style="padding:10px 12px;background:var(--surface-2);border-radius:8px;border:1px solid var(--border)">
              <div style="display:flex;justify-content:space-between;gap:8px">
                <div style="min-width:0;flex:1">
                  <div style="font-weight:600;font-size:13px">${esc(p.nazev)}</div>
                  <div style="font-size:11px;color:var(--text-3)">${esc(p.kategorie_nazev || '')} · ${fmt(p.cena_bez_dph)}</div>
                </div>
                ${currentS ? `<span style="background:${currentS.color};color:#fff;padding:2px 8px;border-radius:999px;font-size:10.5px;font-weight:700;align-self:flex-start">${esc(currentS.label.split(' ')[0])}</span>` : ''}
              </div>
              <div style="margin-top:6px">
                <select class="form-input" style="font-size:12px;padding:5px 8px" onchange="assignSeason(${p.id}, this.value)">
                  <option value="">— bez sezóny —</option>
                  ${allSeasons.map(s => `<option value="${esc(s.key)}" ${p.sezona === s.key ? 'selected' : ''}>${esc(s.label)}</option>`).join('')}
                </select>
              </div>
            </div>
          `;
        }).join('') || '<div class="empty-state">Žádné výrobky</div>'}
      </div>
    </div>
  `;
}

async function renderSeasonalManage() {
  const body = document.getElementById('seasonal-body');
  if (!body) return;
  let data;
  try { data = await api('admin_seasonal.php'); }
  catch (e) { body.innerHTML = `<div class="alert err">${esc(e.message)}</div>`; return; }

  const customSeasons = (data.seasons || []).filter(s => !s.is_default);
  const defaultSeasons = (data.seasons || []).filter(s => s.is_default);

  body.innerHTML = `
    <div class="card-block" style="margin-bottom:14px">
      <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:10px;gap:8px;flex-wrap:wrap">
        <h3 style="margin:0">⚙️ Vlastní sezóny</h3>
        <div style="display:flex;gap:8px;flex-wrap:wrap">
          <button class="btn-secondary" onclick="renderSeasonalReport()">📊 Report prodejů</button>
          <button class="btn-primary btn-green" onclick="editCustomSeason()">+ Nová sezóna</button>
        </div>
      </div>
      <p style="font-size:12px;color:var(--text-3);margin:0 0 14px">Vytvoř vlastní sezónní okna (např. „Letní speciály", „Adventní cukroví", …).</p>
      ${customSeasons.length === 0 ? emptyState({
        icon: '🎨', title: 'Žádné vlastní sezóny',
        msg: 'Defaultně máš 6 sezón (Vánoce, Velikonoce, …). Můžeš si přidat vlastní pro speciální nabídky.',
        actions: '<button class="btn-primary btn-green" onclick="editCustomSeason()">+ Vytvořit první</button>',
      }) : `
        <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(240px,1fr));gap:10px">
          ${customSeasons.map(s => `
            <div style="padding:14px;border-radius:10px;background:${s.color}22;border:2px solid ${s.color}">
              <div style="display:flex;justify-content:space-between;align-items:start;gap:6px">
                <strong style="font-size:14px;flex:1;min-width:0">${esc(s.label)}</strong>
                <div style="display:flex;gap:4px">
                  <button class="btn-icon" onclick="seasonalVyrobaPredikce('${esc(s.key)}')" title="🏭 Suroviny na sezónu (dle loňska)" style="font-size:12px">🏭</button>
                  <button class="btn-icon" onclick="editCustomSeason(${s.id})" style="font-size:12px">✏️</button>
                  <button class="btn-icon" onclick="deleteCustomSeason(${s.id})" style="font-size:12px">🗑️</button>
                </div>
              </div>
              <div style="font-size:11px;color:var(--text-3);margin-top:4px">${esc(s.start_md)} → ${esc(s.end_md)} · ${s.count} výrobků</div>
              ${(+s.sleva_pct) ? `<div style="font-size:11px;font-weight:700;margin-top:4px;color:${(+s.sleva_pct) > 0 ? '#16A34A' : '#DC2626'}">${(+s.sleva_pct) > 0 ? '🏷️ −' + (+s.sleva_pct) + ' % sleva' : '➕ +' + Math.abs(+s.sleva_pct) + ' % přirážka'}</div>` : ''}
              <div style="font-size:11px;font-weight:700;margin-top:6px;color:${s.active_today ? s.color : 'var(--text-3)'}">${s.active_today ? '🟢 DNES AKTIVNÍ' : '⏸️ mimo'}</div>
            </div>
          `).join('')}
        </div>
      `}
    </div>

    <div class="card-block">
      <h3 style="margin:0 0 4px">📅 Výchozí sezóny</h3>
      <p style="font-size:12px;color:var(--text-3);margin:0 0 10px">Můžeš upravit název, datum, barvu i sezónní slevu. „Upraveno" jde kdykoli vrátit na původní.</p>
      <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(230px,1fr));gap:8px">
        ${defaultSeasons.map(s => `
          <div style="padding:12px;border-radius:10px;background:${s.color}18;border:2px solid ${s.color}">
            <div style="display:flex;justify-content:space-between;align-items:start;gap:6px">
              <strong style="font-size:14px;flex:1;min-width:0">${esc(s.label)}</strong>
              <div style="display:flex;gap:4px">
                <button class="btn-icon" onclick="seasonalVyrobaPredikce('${esc(s.key)}')" title="🏭 Suroviny na sezónu (dle loňska)" style="font-size:12px">🏭</button>
                <button class="btn-icon" onclick='editCustomSeason(0, ${JSON.stringify(s).replace(/'/g, "&#39;")})' title="Upravit" style="font-size:12px">✏️</button>
                ${s.has_override ? `<button class="btn-icon" onclick="resetDefaultSeason('${esc(s.key)}')" title="Vrátit na původní" style="font-size:12px">↩️</button>` : ''}
              </div>
            </div>
            <div style="font-size:11px;color:var(--text-3);margin-top:4px">${esc(s.start_md)} → ${esc(s.end_md)} · ${s.count} výrobků${s.has_override ? ' · ✏️ upraveno' : ''}</div>
            ${(+s.sleva_pct) ? `<div style="font-size:11px;font-weight:700;margin-top:4px;color:${(+s.sleva_pct) > 0 ? '#16A34A' : '#DC2626'}">${(+s.sleva_pct) > 0 ? '🏷️ −' + (+s.sleva_pct) + ' % sleva' : '➕ +' + Math.abs(+s.sleva_pct) + ' % přirážka'}</div>` : ''}
            <div style="font-size:11px;font-weight:700;margin-top:5px;color:${s.active_today ? s.color : 'var(--text-3)'}">${s.active_today ? '🟢 DNES AKTIVNÍ' : '⏸️ mimo'}</div>
          </div>
        `).join('')}
      </div>
    </div>
  `;
}

window.resetDefaultSeason = async function(key) {
  if (!(await confirmDialog({ title: 'Vrátit na původní?', msg: 'Smaže tvé úpravy této výchozí sezóny (název/datum/barva/sleva).', okText: 'Vrátit' }))) return;
  try {
    await api('admin_seasonal.php?action=reset_default', { method: 'POST', body: JSON.stringify({ key }) });
    toastSuccess('Vráceno na původní');
    renderSeasonalManage();
  } catch (e) { alert('Chyba: ' + e.message); }
};

window.saveDefaultSleva = async function(key) {
  const el = document.getElementById('ds-sleva-' + key);
  const pct = parseFloat(el && el.value) || 0;
  try {
    await api('admin_seasonal.php?action=save_default_sleva', { method: 'POST', body: JSON.stringify({ key, sleva_pct: pct }) });
    toastSuccess('Sezónní cena uložena');
    renderSeasonalManage();
  } catch (e) { alert('Chyba: ' + e.message); }
};

// 🆕 v3.0.342 — pohyblivé svátky (Velikonoce, Den matek) → letošní datum do výchozí sezóny
function appekEasterSunday(year) {
  const a = year % 19, b = Math.floor(year / 100), c = year % 100;
  const d = Math.floor(b / 4), e = b % 4, f = Math.floor((b + 8) / 25);
  const g = Math.floor((b - f + 1) / 3), h = (19 * a + b - d - g + 15) % 30;
  const i = Math.floor(c / 4), k = c % 4, l = (32 + 2 * e + 2 * i - h - k) % 7;
  const m = Math.floor((a + 11 * h + 22 * l) / 451);
  const month = Math.floor((h + l - 7 * m + 114) / 31), day = ((h + l - 7 * m + 114) % 31) + 1;
  return new Date(year, month - 1, day);
}
window.seasonalNastavLetos = function(key) {
  const y = new Date().getFullYear();
  const fmt = dt => String(dt.getMonth() + 1).padStart(2, '0') + '-' + String(dt.getDate()).padStart(2, '0');
  let start, end;
  if (key === 'velikonoce') {
    const easter = appekEasterSunday(y);
    end = new Date(easter); end.setDate(end.getDate() + 1);     // Velikonoční pondělí
    start = new Date(easter); start.setDate(start.getDate() - 28); // ~4 týdny prodejní okno před
  } else if (key === 'denmatek') {
    const may1 = new Date(y, 4, 1); const firstSun = (7 - may1.getDay()) % 7;
    const den = new Date(y, 4, 1 + firstSun + 7);                // 2. neděle května
    end = den; start = new Date(den); start.setDate(start.getDate() - 12);
  } else return;
  const se = document.getElementById('cs-start'), en = document.getElementById('cs-end');
  if (se) se.value = fmt(start);
  if (en) en.value = fmt(end);
  try { toast('📅 Nastaveno na ' + y + ': ' + fmt(start) + ' → ' + fmt(end), 'info'); } catch (e) {}
};

window.editCustomSeason = async function(id, preset) {
  let s = { id: 0, key: '', label: '', start_md: '06-01', end_md: '08-31', color: '#3B82F6', sleva_pct: 0 };
  const isDefault = !!(preset && preset.is_default); // 🆕 v3.0.339 — editace výchozí sezóny (přes override)
  if (preset) { s = Object.assign(s, preset); }
  else if (id) {
    try {
      const d = await api('admin_seasonal.php');
      const found = (d.seasons || []).find(x => x.id === id);
      if (found) s = found;
    } catch (e) { return alert('Chyba: ' + e.message); }
  }
  const keyLocked = id || isDefault;
  openModal(isDefault ? '✏️ Upravit výchozí sezónu' : (id ? '✏️ Upravit sezónu' : '+ Nová vlastní sezóna'), `
    <div class="form-grid form-grid-tight">
      <div class="full"><label class="form-label">Název *</label>
        <input class="form-input" id="cs-label" value="${esc(s.label)}" placeholder="např. 🌞 Letní speciály">
      </div>
      <div><label class="form-label">Klíč (slug) *</label>
        <input class="form-input" id="cs-key" value="${esc(s.key)}" placeholder="leto" ${keyLocked ? 'readonly' : ''}>
        ${isDefault ? '<div style="font-size:11px;color:var(--text-3);margin-top:2px">Výchozí sezóna — úpravy lze vrátit zpět (↩️).</div>' : ''}
      </div>
      <div><label class="form-label">🎨 Barva</label>
        <input type="color" class="form-input" id="cs-color" value="${esc(s.color)}" style="height:40px">
      </div>
      <div><label class="form-label">Začátek (MM-DD)</label>
        <input class="form-input" id="cs-start" value="${esc(s.start_md)}" pattern="\\d{2}-\\d{2}" placeholder="06-01">
      </div>
      <div><label class="form-label">Konec (MM-DD)</label>
        <input class="form-input" id="cs-end" value="${esc(s.end_md)}" pattern="\\d{2}-\\d{2}" placeholder="08-31">
      </div>
      ${(isDefault && (s.key === 'velikonoce' || s.key === 'denmatek')) ? `
      <div class="full" style="background:#FFFBEB;border:1px solid #F0D9B8;border-radius:8px;padding:8px 12px">
        <div style="font-size:12px;color:#854F0B;margin-bottom:6px">📅 ${s.key === 'velikonoce' ? 'Velikonoce' : 'Den matek'} se každý rok posouvají — datum nastav na aktuální rok jedním klikem:</div>
        <button type="button" class="btn-secondary" onclick="seasonalNastavLetos('${s.key}')" style="font-size:12px">📅 Nastavit na letošní rok (${new Date().getFullYear()})</button>
      </div>` : ''}
      <div class="full"><label class="form-label">💰 Sezónní úprava ceny (%)</label>
        <input class="form-input" id="cs-sleva" type="number" step="0.5" min="-90" max="90" value="${s.sleva_pct || 0}" placeholder="0">
        <div style="font-size:11px;color:var(--text-3);margin-top:2px">Kladné = sleva (např. 20 = −20 %), záporné = přirážka. Platí jen v aktivním okně sezóny.</div>
      </div>
      <div class="full"><label class="form-label">⏰ Předobjednávky — předstih (dní)</label>
        <input class="form-input" id="cs-predstih" type="number" min="0" max="120" value="${s.predstih_dni || 0}" placeholder="0" style="max-width:140px">
        <div style="font-size:11px;color:var(--text-3);margin-top:2px">Výrobky sezóny se v B2B katalogu objeví už X dní PŘED startem jako 📅 předobjednávka (dodání až od startu sezóny). 0 = vypnuto.</div>
      </div>
      ${!isDefault ? `
      <div class="full" style="background:var(--bg-2, #F8FAFC);border:1px dashed var(--border);border-radius:8px;padding:10px 12px">
        <label class="form-label" style="margin-bottom:6px">🗓️ Jednorázová akce s konkrétním datem <span style="font-weight:400;color:var(--text-3)">(volitelné)</span></label>
        <div style="display:flex;gap:10px;flex-wrap:wrap">
          <input class="form-input" id="cs-start-full" type="date" value="${esc(s.start_full || '')}" style="max-width:170px">
          <input class="form-input" id="cs-end-full" type="date" value="${esc(s.end_full || '')}" style="max-width:170px">
        </div>
        <div style="font-size:11px;color:var(--text-3);margin-top:4px">Vyplněním obou dat se sezóna stane JEDNORÁZOVOU (např. „Letní výprodej 2026") — MM-DD výše se ignoruje a akce se neopakuje.</div>
      </div>` : ''}
      ${(isDefault && (s.key === 'velikonoce' || s.key === 'denmatek')) ? `
      <div class="full"><label style="display:flex;gap:8px;align-items:center;cursor:pointer;font-size:13px">
        <input type="checkbox" id="cs-auto-letos" ${s.auto_letos ? 'checked' : ''} style="width:18px;height:18px">
        🔄 Automaticky přepočítat na každý rok (${s.key === 'velikonoce' ? 'velikonoční computus' : '2. neděle v květnu'}) — datum výše se pak dopočítává samo
      </label></div>` : ''}
    </div>
    <div class="form-actions">
      <button class="btn-secondary" onclick="closeModal()">Zrušit</button>
      <button class="btn-primary btn-green" onclick="saveCustomSeason(${id || 0})">💾 Uložit</button>
    </div>
  `);
};

window.saveCustomSeason = async function(id) {
  const data = {
    id: id || 0,
    key:   document.getElementById('cs-key').value.trim().toLowerCase().replace(/[^a-z0-9_]/g, ''),
    label: document.getElementById('cs-label').value.trim(),
    start_md: document.getElementById('cs-start').value.trim(),
    end_md:   document.getElementById('cs-end').value.trim(),
    color: document.getElementById('cs-color').value,
    sleva_pct: parseFloat(document.getElementById('cs-sleva').value) || 0,
    // 🆕 v3.0.406
    predstih_dni: parseInt((document.getElementById('cs-predstih') || {}).value, 10) || 0,
    start_full: ((document.getElementById('cs-start-full') || {}).value || '').trim(),
    end_full:   ((document.getElementById('cs-end-full') || {}).value || '').trim(),
    auto_letos: (document.getElementById('cs-auto-letos') || {}).checked ? 1 : 0,
  };
  const sf = data.start_full, ef = data.end_full;
  if ((sf && !ef) || (!sf && ef)) { alert('Jednorázová akce: vyplň OBĚ data (od i do), nebo obě smaž.'); return; }
  if (sf && ef && ef < sf) { alert('Jednorázová akce: konec musí být ≥ začátek.'); return; }
  if (!data.key || !data.label) { alert('Vyplň klíč a název.'); return; }
  const validMd = s => { const m = /^(\d{2})-(\d{2})$/.exec(s); if (!m) return false; const mo = +m[1], da = +m[2]; return mo >= 1 && mo <= 12 && da >= 1 && da <= 31; };
  if (!validMd(data.start_md) || !validMd(data.end_md)) { alert('Datum ve formátu MM-DD (měsíc 01–12, den 01–31)'); return; }
  try {
    await api('admin_seasonal.php?action=save_season', { method: 'POST', body: JSON.stringify(data) });
    closeModal();
    toastSuccess('Sezóna uložena');
    renderSeasonalManage();
  } catch (e) { alert('Chyba: ' + e.message); }
};

window.deleteCustomSeason = async function(id) {
  if (!(await confirmDialog({ title: 'Smazat sezónu?', msg: 'Výrobky s touto sezónou budou unassign.', danger: true, okText: 'Smazat' }))) return;
  try {
    await api('admin_seasonal.php?action=delete_season&id=' + id, { method: 'DELETE' });
    toastSuccess('Sezóna smazána');
    renderSeasonalManage();
  } catch (e) { alert('Chyba: ' + e.message); }
};

window.assignSeason = async function(productId, sezona) {
  try {
    await api('admin_seasonal.php?action=assign', { method: 'POST', body: JSON.stringify({ product_id: productId, sezona }) });
    toastSuccess(sezona ? 'Přiřazeno k sezóně' : 'Sezóna odebrána');
  } catch (e) { alert('Chyba: ' + e.message); }
};

// 🆕 v3.0.406 — 📊 SEZÓNNÍ REPORT: prodeje per sezóna, aktuální cyklus vs. loni
window.renderSeasonalReport = async function() {
  let d;
  try { d = await api('admin_seasonal.php?action=report'); }
  catch (e) { return alert('Chyba: ' + e.message); }
  const rows = (d.report || []).map(r => {
    const stav = r.active ? `🟢 aktivní${r.days_left != null ? ` · končí za ${r.days_left} d` : ''}`
               : r.preorder ? `📅 předobjednávky${r.starts_in != null ? ` · start za ${r.starts_in} d` : ''}`
               : (r.starts_in != null ? `⏳ za ${r.starts_in} d` : '⏸️ mimo');
    const diff = r.loni.trzba > 0 ? Math.round((r.letos.trzba - r.loni.trzba) / r.loni.trzba * 100) : null;
    return `<tr>
      <td><span style="display:inline-block;width:10px;height:10px;border-radius:3px;background:${r.color};margin-right:6px"></span>${esc(r.label)}</td>
      <td style="font-size:11px;color:var(--text-3);white-space:nowrap">${esc(r.okno.od)} → ${esc(r.okno.do)}</td>
      <td style="font-size:12px">${stav}</td>
      <td style="text-align:right">${(+r.letos.ks).toLocaleString('cs-CZ')} ks<br><strong>${fmt(r.letos.trzba)}</strong><br><span style="font-size:11px;color:var(--text-3)">${r.letos.objednavek} obj.</span></td>
      <td style="text-align:right">${(+r.loni.ks).toLocaleString('cs-CZ')} ks<br>${fmt(r.loni.trzba)}<br><span style="font-size:11px;color:var(--text-3)">${r.loni.objednavek} obj.</span></td>
      <td style="text-align:right;font-weight:700;color:${diff == null ? 'var(--text-3)' : diff >= 0 ? '#16A34A' : '#DC2626'}">${diff == null ? '—' : (diff >= 0 ? '+' : '') + diff + ' %'}</td>
    </tr>`;
  }).join('');
  openModal('📊 Sezónní report — aktuální cyklus vs. loni', `
    <div style="max-height:65vh;overflow:auto">
      <table class="table" style="font-size:13px">
        <thead><tr><th>Sezóna</th><th>Okno</th><th>Stav</th><th style="text-align:right">Letošní cyklus</th><th style="text-align:right">Loni (stejné okno)</th><th style="text-align:right">Δ tržba</th></tr></thead>
        <tbody>${rows || '<tr><td colspan="6" style="text-align:center;color:var(--text-3)">Žádné sezóny</td></tr>'}</tbody>
      </table>
      <div style="font-size:11px;color:var(--text-3);margin-top:8px">Tržby bez DPH z objednávek (mimo storna) výrobků přiřazených k sezóně, v okně sezóny. „Letošní cyklus" = aktuální/nejbližší okno.</div>
    </div>
    <div class="form-actions"><button class="btn-secondary" onclick="closeModal()">Zavřít</button></div>
  `);
};

// 🆕 v3.0.406 — 🏭 PREDIKCE VÝROBY: suroviny na sezónu = BOM × loňský prodej
window.seasonalVyrobaPredikce = async function(key) {
  let d;
  try { d = await api('admin_seasonal.php?action=vyroba_predikce&key=' + encodeURIComponent(key)); }
  catch (e) { return alert('Chyba: ' + e.message); }
  const sz = d.sezona || {};
  const prods = (d.produkty || []).filter(p => +p.loni_ks > 0);
  const sur = d.suroviny || [];
  const hlava = sz.active ? '🟢 sezóna právě běží' : (sz.starts_in != null ? `⏳ start za ${sz.starts_in} dní (${sz.start})` : '');
  openModal(`🏭 Suroviny na sezónu — ${esc(sz.label || key)}`, `
    <div style="max-height:65vh;overflow:auto">
      <div style="font-size:12px;color:var(--text-3);margin-bottom:10px">${hlava} · predikce = <strong>loňský prodej</strong> (${esc(d.zdroj?.od || '')} → ${esc(d.zdroj?.do || '')}) rozpadlý přes receptury (BOM).</div>
      ${prods.length === 0 ? `<div class="alert" style="background:var(--warn-bg,#FEF3C7);padding:10px;border-radius:8px;font-size:13px">Loni se v tomto okně nic neprodalo (nebo výrobky nemají loňská data) — predikce nemá z čeho vyjít. Přiřaď výrobky sezóně a po první sezóně to tu ožije.</div>` : `
      <h4 style="margin:8px 0 6px">📦 Výrobky (loňský prodej)</h4>
      <table class="table" style="font-size:12.5px"><thead><tr><th>Výrobek</th><th style="text-align:right">Loni prodáno</th></tr></thead>
        <tbody>${prods.map(p => `<tr><td>${esc(p.nazev)}</td><td style="text-align:right">${(+p.loni_ks).toLocaleString('cs-CZ')} ks</td></tr>`).join('')}</tbody></table>
      <h4 style="margin:14px 0 6px">🧂 Potřeba surovin vs. sklad</h4>
      ${sur.length === 0 ? '<div style="font-size:12px;color:var(--text-3)">Výrobky nemají receptury — není co rozpadnout.</div>' : `
      <table class="table" style="font-size:12.5px"><thead><tr><th>Surovina</th><th style="text-align:right">Potřeba</th><th style="text-align:right">Skladem</th><th style="text-align:right">Chybí</th></tr></thead>
        <tbody>${sur.map(x => `<tr${x.chybi > 0 ? ' style="background:var(--danger-bg,#FEE2E2)"' : ''}>
          <td>${esc(x.nazev)}</td>
          <td style="text-align:right">${(+x.potreba).toLocaleString('cs-CZ')} ${esc(x.jednotka || '')}</td>
          <td style="text-align:right">${(+x.skladem).toLocaleString('cs-CZ')}</td>
          <td style="text-align:right;font-weight:700;color:${x.chybi > 0 ? '#DC2626' : '#16A34A'}">${x.chybi > 0 ? (+x.chybi).toLocaleString('cs-CZ') : '✓'}</td>
        </tr>`).join('')}</tbody></table>`}`}
    </div>
    <div class="form-actions"><button class="btn-secondary" onclick="closeModal()">Zavřít</button></div>
  `);
};

