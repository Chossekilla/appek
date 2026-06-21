// =============================================================
// 📦 SKLAD — samostatný přehled skladu s rychlými akcemi
// =============================================================
async function renderSklad() {
  // Cache zdílí se s surovinami
  let list = state._suroviny_full_cache;
  if (!list) {
    list = await api('admin_suroviny.php');
    state._suroviny_full_cache = list;
  }
  const c = document.getElementById('content');

  // Filtr — jen aktivní suroviny s relevantnimi daty
  const aktivni = (list || []).filter(s => parseInt(s.aktivni) !== 0);

  // Roztřídit do skupin: pod minimem / OK / bez minima
  const podMin = [];
  const nadMin = [];
  const bezNasr = [];
  aktivni.forEach(s => {
    const akt = parseFloat(s.stock_aktualni) || 0;
    const min = parseFloat(s.stock_minimalni);
    if (isNaN(min)) bezNasr.push(s);
    else if (akt <= min) podMin.push(s);
    else nadMin.push(s);
  });

  // Setříděno: pod minimem podle "kolik chybí" sestupně, ostatní abecedně
  podMin.sort((a, b) => {
    const ma = parseFloat(a.stock_minimalni) - (parseFloat(a.stock_aktualni) || 0);
    const mb = parseFloat(b.stock_minimalni) - (parseFloat(b.stock_aktualni) || 0);
    return mb - ma;
  });
  nadMin.sort((a, b) => (a.nazev || '').localeCompare(b.nazev || '', 'cs'));
  bezNasr.sort((a, b) => (a.nazev || '').localeCompare(b.nazev || '', 'cs'));

  // Celková hodnota skladu (cena_baleni / obsah_baleni × stock)
  let celkemHodnota = 0;
  aktivni.forEach(s => {
    const cb = parseFloat(s.cena_baleni) || 0;
    const ob = parseFloat(s.obsah_baleni) || 0;
    const akt = parseFloat(s.stock_aktualni) || 0;
    if (cb > 0 && ob > 0 && akt > 0) celkemHodnota += (cb / ob) * akt;
  });

  const radek = (s) => {
    const akt = parseFloat(s.stock_aktualni) || 0;
    const min = parseFloat(s.stock_minimalni);
    const cil = parseFloat(s.stock_cilove);
    const jed = esc(s.jednotka || 'g');
    const cb = parseFloat(s.cena_baleni) || 0;
    const ob = parseFloat(s.obsah_baleni) || 0;
    const cenaJed = (cb > 0 && ob > 0) ? cb / ob : 0;
    const hodnota = cenaJed * akt;
    const podMinFlag = !isNaN(min) && akt <= min;
    return `
      <tr ${podMinFlag ? 'style="background:rgba(220,38,38,0.06)"' : ''}>
        <td><strong>${esc(s.nazev)}</strong>${s.alergen ? `<span style="margin-left:6px;background:#fef3c7;color:#92400e;font-size:10px;padding:1px 6px;border-radius:6px;font-weight:600">${esc(s.alergen)}</span>` : ''}</td>
        <td class="num" style="font-variant-numeric:tabular-nums">
          ${podMinFlag
            ? `<span style="background:#FEE2E2;color:#991B1B;font-weight:700;padding:3px 10px;border-radius:8px;font-size:13px">⚠ ${akt.toFixed(2).replace(/\.?0+$/, '').replace('.', ',')} ${jed}</span>`
            : `<strong>${akt.toFixed(2).replace(/\.?0+$/, '').replace('.', ',')}</strong> ${jed}`}
        </td>
        <td class="num" style="color:var(--text-3);font-size:12px">${!isNaN(min) ? min + ' ' + jed : '—'}</td>
        <td class="num" style="color:var(--text-3);font-size:12px">${!isNaN(cil) ? cil + ' ' + jed : '—'}</td>
        <td class="num">${hodnota > 0 ? fmt(hodnota) : '<span style="color:var(--text-3)">—</span>'}</td>
        <td onclick="event.stopPropagation();">
          <button class="btn-primary btn-green" style="font-size:12px;padding:6px 12px;margin-right:4px" onclick="surSkladModal(${s.id})" title="Příjem / výdej / inventura">📦 Pohyb</button>
          <button class="btn-secondary" style="font-size:12px;padding:6px 10px" onclick="editSurovina(${s.id})">✏️</button>
        </td>
      </tr>
    `;
  };

  const head = `
    <thead>
      <tr>
        <th>Surovina</th>
        <th class="num">Aktuální stav</th>
        <th class="num">Minimum</th>
        <th class="num">Cíl</th>
        <th class="num">Hodnota Kč</th>
        <th></th>
      </tr>
    </thead>
  `;

  c.innerHTML = `
    <div class="page-head">
      <div>
        <h1 class="page-title">📦 Sklad surovin</h1>
        <p class="page-sub">${aktivni.length} aktivních surovin · Hodnota skladu: <strong>${fmt(celkemHodnota)}</strong></p>
      </div>
      <div style="display:flex;gap:8px;flex-wrap:wrap">
        <button class="btn-secondary" onclick="navigate('vyroba')">← Výroba</button>
        <button class="btn-secondary" onclick="navigate('suroviny')">🌾 Spravovat suroviny</button>
      </div>
    </div>

    <!-- 🆕 v2.9.265 — Stat cards konzistence s Dashboard (clickable warn, primary tint hodnota) -->
    <div class="stat-grid" style="margin-bottom:14px">
      ${podMin.length > 0 ? `
        <div class="stat-card stat-warn" onclick="state._suroviny_pod_minimem=true;navigate('suroviny')" title="Klikni → Suroviny filtrované pod minimem">
          <div class="stat-label">⚠️ Pod minimální hladinou</div>
          <div class="stat-value">${podMin.length}</div>
          <div class="stat-sub">klikni → suroviny</div>
        </div>
      ` : `
        <div class="stat-card">
          <div class="stat-label">✓ Pod minimem</div>
          <div class="stat-value" style="color:var(--success-text)">0</div>
          <div class="stat-sub">vše OK</div>
        </div>
      `}
      <div class="stat-card">
        <div class="stat-label">✓ V pořádku</div>
        <div class="stat-value" style="color:var(--success-text)">${nadMin.length}</div>
        <div class="stat-sub">nad minimem</div>
      </div>
      <div class="stat-card">
        <div class="stat-label">⚪ Bez minima</div>
        <div class="stat-value" style="color:var(--text-3)">${bezNasr.length}</div>
        <div class="stat-sub">nenastaveno</div>
      </div>
      <div class="stat-card" style="background:linear-gradient(180deg, var(--surface) 0%, rgba(186, 117, 23, 0.04) 100%);border-color:var(--primary-border)">
        <div class="stat-label">💰 Hodnota skladu</div>
        <div class="stat-value" style="color:var(--primary-dark);font-weight:700">${fmt(celkemHodnota)}</div>
        <div class="stat-sub">celkem na skladě</div>
      </div>
    </div>

    ${podMin.length > 0 ? `
      <div class="card-block" style="margin-bottom:14px;border-left:4px solid #DC2626;background:rgba(220,38,38,0.05)">
        <h3 style="margin:0 0 10px;color:#991B1B;font-size:16px">⚠️ Suroviny pod minimální hladinou — doporučujeme naskladnit</h3>
        <table class="table" style="margin:0;font-size:13px">
          ${head}
          <tbody>${podMin.map(radek).join('')}</tbody>
        </table>
      </div>
    ` : ''}

    ${nadMin.length > 0 ? `
      <div class="card-block" style="margin-bottom:14px">
        <h3 style="margin:0 0 10px;font-size:16px">✅ Suroviny v pořádku</h3>
        <table class="table" style="margin:0;font-size:13px">
          ${head}
          <tbody>${nadMin.map(radek).join('')}</tbody>
        </table>
      </div>
    ` : ''}

    ${bezNasr.length > 0 ? `
      <details class="card-block" style="margin-bottom:14px">
        <summary style="cursor:pointer;font-weight:700;font-size:16px;padding:6px 0">
          📋 Bez nastaveného minima (${bezNasr.length}) — klikni pro zobrazení
          <span style="font-weight:400;font-size:12px;color:var(--text-3);margin-left:8px">Nastavte minimum pro alerty</span>
        </summary>
        <div style="margin-top:10px">
          <table class="table" style="margin:0;font-size:13px">
            ${head}
            <tbody>${bezNasr.map(radek).join('')}</tbody>
          </table>
        </div>
      </details>
    ` : ''}

    ${aktivni.length === 0 ? '<div class="card-block"><div class="empty-state">Žádné suroviny — přidej je v sekci Suroviny.</div></div>' : ''}
  `;
}

async function renderSuroviny() {
  // 🚀 PERFORMANCE: cachuj výsledek (invalidate po editaci) — pro 1000+ surovin
  // ušetří 200-500 ms na každý re-render filtrů.
  let list = state._suroviny_full_cache;
  if (!list) {
    list = await api('admin_suroviny.php');
    state._suroviny_full_cache = list;
  }
  const c = document.getElementById('content');

  // Filtry
  const q = (state._suroviny_q || '').trim().toLowerCase();
  const kat = state._suroviny_kat || 'vse';
  const aktivni = state._suroviny_aktivni || 'vse';   // vse | aktivni | skryte
  const alergen = state._suroviny_alergen || 'vse';   // vse | s | bez
  const groupBy = state._suroviny_group !== false;    // default true
  const cenaJedUnit = state._suroviny_cena_jed || 'kg'; // 🆕 v3.0.148 — Cena za jed. (hmotnost): kg | g
  const cenaJedUnitVol = state._suroviny_cena_jed_vol || 'l'; // 🆕 v3.0.149 — Cena za jed. (objem): l | ml

  // Aplikuj filtry
  let filtered = list.map(s => ({ ...s, _kat: kategoriziujSurovinu(s) }));
  if (q) filtered = filtered.filter(s =>
    (s.nazev || '').toLowerCase().includes(q) ||
    (s.alergen || '').toLowerCase().includes(q) ||
    (s.slozeni || '').toLowerCase().includes(q));
  if (kat !== 'vse') filtered = filtered.filter(s => s._kat === kat);
  if (aktivni === 'aktivni') filtered = filtered.filter(s => !!s.aktivni);
  if (aktivni === 'skryte')  filtered = filtered.filter(s => !s.aktivni);
  if (alergen === 's')   filtered = filtered.filter(s => s.alergen && s.alergen.trim());
  if (alergen === 'bez') filtered = filtered.filter(s => !s.alergen || !s.alergen.trim());
  // 📦 Pod minimální hladinou
  if (state._suroviny_pod_minimem) {
    filtered = filtered.filter(s => {
      const min = parseFloat(s.stock_minimalni);
      const akt = parseFloat(s.stock_aktualni) || 0;
      return !isNaN(min) && akt <= min;
    });
  }
  // Spočítej kolik je pod minimem (pro badge)
  const podMinimem = list.filter(s => {
    const min = parseFloat(s.stock_minimalni);
    const akt = parseFloat(s.stock_aktualni) || 0;
    return !isNaN(min) && akt <= min;
  }).length;

  // Spočítej kategorie pro tabs
  const pocty = {};
  list.forEach(s => {
    const k = kategoriziujSurovinu(s);
    pocty[k] = (pocty[k] || 0) + 1;
  });

  // Seřaď abecedně
  filtered.sort((a, b) => (a.nazev || '').localeCompare(b.nazev || '', 'cs'));

  // 🆕 v3.0.182 — ulož aktuálně filtrovaný seznam pro Export CSV (respektuje kategorii + hledání)
  state._suroviny_filtered_export = filtered;

  // Skupiny podle kategorie (pro group view)
  const skupiny = {};
  SUROVINA_KATEGORIE.forEach(k => skupiny[k.key] = []);
  filtered.forEach(s => skupiny[s._kat].push(s));

  // Helper pro vykreslení jedné suroviny v desktopové tabulce (řádek)
  const radekDesktop = (s) => {
    const cb = parseFloat(s.cena_baleni) || 0;
    const ob = parseFloat(s.obsah_baleni) || 0;
    const cenaJed = (cb > 0 && ob > 0) ? (cb / ob) : 0;
    // 🆕 v3.0.148 — normalizuj Cena za jed. na zvolené zobrazení (kg/g) u hmotnostních surovin
    const _jed = (s.jednotka || 'g').toLowerCase();
    let cenaDisp = cenaJed, jedDisp = _jed;
    if (cenaJed > 0 && (_jed === 'g' || _jed === 'kg')) {
      const perG = _jed === 'kg' ? cenaJed / 1000 : cenaJed;
      if (cenaJedUnit === 'kg') { cenaDisp = perG * 1000; jedDisp = 'kg'; }
      else { cenaDisp = perG; jedDisp = 'g'; }
    } else if (cenaJed > 0 && (_jed === 'ml' || _jed === 'l')) {
      // 🆕 v3.0.149 — objemové suroviny: ml ↔ L podle přepínače
      const perMl = _jed === 'l' ? cenaJed / 1000 : cenaJed;
      if (cenaJedUnitVol === 'l') { cenaDisp = perMl * 1000; jedDisp = 'l'; }
      else { cenaDisp = perMl; jedDisp = 'ml'; }
    }
    const cenaTxt = cenaDisp.toFixed((jedDisp === 'g' || jedDisp === 'ml') ? 4 : 2).replace(/\.?0+$/, '').replace('.', ',');
    return `
      <tr class="row-clickable" onclick="editSurovina(${s.id})" ${!s.aktivni ? 'style="opacity:0.5"' : ''}>
        <td>
          <strong>${esc(s.nazev)}</strong>
          ${s.slozeni ? `<span title="Kompozitní surovina — má vlastní složení: ${esc(s.slozeni)}" style="margin-left:6px;color:#7c3aed;font-size:13px;cursor:help">🧬</span>` : ''}
          ${s.poznamka ? `<div style="color:var(--text-3);font-size:12px">${esc(s.poznamka)}</div>` : ''}
          ${s.slozeni ? `<div style="color:var(--text-3);font-size:11px;margin-top:2px;font-style:italic">${esc(s.slozeni.length > 80 ? s.slozeni.slice(0, 80) + '…' : s.slozeni)}</div>` : ''}
        </td>
        <td>${esc(s.jednotka || 'g')}</td>
        <td>
          ${s.alergen ? `<span style="background:#fef3c7;color:#92400e;font-size:11px;padding:2px 8px;border-radius:10px;font-weight:600">${esc(s.alergen)}</span>` : ''}
          ${s.slozeni_alergeny && s.slozeni_alergeny !== s.alergen ? `<div style="margin-top:3px"><span title="Alergeny detekované ze složení" style="background:#ede9fe;color:#5b21b6;font-size:10px;padding:2px 7px;border-radius:8px;font-weight:500">🔬 ${esc(s.slozeni_alergeny.length > 40 ? s.slozeni_alergeny.slice(0, 40) + '…' : s.slozeni_alergeny)}</span></div>` : ''}
          ${!s.alergen && !s.slozeni_alergeny ? '<span style="color:var(--text-3)">—</span>' : ''}
        </td>
        <td class="num" style="font-variant-numeric:tabular-nums">${cenaJed > 0 ? `<strong>${cenaTxt}</strong> Kč/${jedDisp}` : '<span style="color:var(--text-3)">—</span>'}</td>
        <td class="num" style="font-variant-numeric:tabular-nums">${stockBadge(s)}</td>
        <td class="num">${s.pocet_vyrobku || 0}×</td>
        <td>${s.aktivni ? '<span class="status dorucena">Aktivní</span>' : '<span class="status zrusena">Skrytá</span>'}</td>
        <td onclick="event.stopPropagation();">
          <button class="btn-secondary" style="font-size:12px;padding:6px 10px;margin-right:4px" onclick="surSkladModal(${s.id})" title="Příjem / výdej / inventura">📦</button>
          <button class="btn-secondary" style="font-size:12px;padding:6px 10px" onclick="editSurovina(${s.id})">Upravit</button>
        </td>
      </tr>
    `;
  };

  // 📦 Stock badge — zobrazí aktuální zásobu s barevným varovaním
  const stockBadge = (s) => {
    const akt = parseFloat(s.stock_aktualni) || 0;
    const min = s.stock_minimalni !== null && s.stock_minimalni !== undefined ? parseFloat(s.stock_minimalni) : null;
    const jed = esc(s.jednotka || 'g');
    const aktTxt = akt.toFixed(akt >= 100 ? 0 : 2).replace(/\.?0+$/, '').replace('.', ',');
    if (min !== null && akt <= min) {
      return `<span style="background:#FEE2E2;color:#991B1B;font-weight:700;padding:3px 10px;border-radius:8px;font-size:12px" title="⚠ Pod minimem (${min} ${jed})">⚠ ${aktTxt} ${jed}</span>`;
    }
    if (akt === 0) {
      return `<span style="color:var(--text-3)">—</span>`;
    }
    return `<strong>${aktTxt}</strong> ${jed}`;
  };

  // Vykreslení tabulky — buď s kategoriemi nebo bez
  const tabulkaDesktop = () => {
    if (filtered.length === 0) return '<div class="empty-state">Žádné suroviny odpovídající filtru</div>';
    const head = `
      <thead>
        <tr>
          <th>Název</th>
          <th>Jednotka</th>
          <th>Alergen</th>
          <th class="num">Cena za jed.</th>
          <th class="num">📦 Skladem</th>
          <th class="num">Použito</th>
          <th>Stav</th>
          <th></th>
        </tr>
      </thead>
    `;
    if (!groupBy || kat !== 'vse') {
      return `<table class="table sur-table">${head}<tbody>${filtered.map(radekDesktop).join('')}</tbody></table>`;
    }
    // Groupovaný view — pro každou neprázdnou kategorii nadpis + řádky
    return SUROVINA_KATEGORIE.map(k => {
      const items = skupiny[k.key];
      if (items.length === 0) return '';
      return `
        <h3 style="margin:24px 0 10px;font-size:18px;font-weight:700;color:var(--text);display:flex;align-items:center;gap:12px;border-bottom:2px solid var(--border-2);padding-bottom:8px">
          <span style="font-size:32px;line-height:1">${k.icon}</span>
          <span>${k.label}</span>
          <span style="font-size:13px;font-weight:600;color:var(--text-3);background:var(--surface-2);padding:4px 12px;border-radius:12px">${items.length}</span>
        </h3>
        <table class="table sur-table" style="margin-bottom:12px">${head}<tbody>${items.map(radekDesktop).join('')}</tbody></table>
      `;
    }).join('');
  };

  // Mobilní karta
  const kartaMobile = (s) => `
    <div class="card-block" style="padding:14px 16px;margin-bottom:10px;${!s.aktivni ? 'opacity:0.5' : ''}" onclick="editSurovina(${s.id})">
      <div style="display:flex;justify-content:space-between;align-items:center;gap:10px">
        <div style="flex:1;min-width:0">
          <div style="font-weight:700;font-size:16px;line-height:1.3">${esc(s.nazev)}</div>
          <div style="font-size:13px;color:var(--text-3);margin-top:3px">${esc(s.jednotka || 'g')}${s.alergen ? ' · ⚠ ' + esc(s.alergen) : ''}</div>
        </div>
        <div style="text-align:right;font-size:13px;color:var(--text-3);white-space:nowrap">
          <strong style="font-size:15px;color:var(--text-2)">${s.pocet_vyrobku || 0}×</strong><br>
          ${s.aktivni ? '<span style="color:var(--success-text);font-weight:600">✓ Aktivní</span>' : '<span>○ Skrytá</span>'}
        </div>
      </div>
    </div>
  `;

  const seznamMobile = () => {
    if (filtered.length === 0) return '<div class="empty-state">Žádné suroviny odpovídající filtru</div>';
    if (!groupBy || kat !== 'vse') return filtered.map(kartaMobile).join('');
    return SUROVINA_KATEGORIE.map(k => {
      const items = skupiny[k.key];
      if (items.length === 0) return '';
      return `
        <h3 style="margin:18px 0 8px;font-size:16px;font-weight:700;color:var(--text);display:flex;align-items:center;gap:10px;padding-bottom:6px;border-bottom:2px solid var(--border-2)">
          <span style="font-size:28px;line-height:1">${k.icon}</span>
          <span>${k.label}</span>
          <span style="font-size:12px;font-weight:600;color:var(--text-3);background:var(--surface-2);padding:2px 10px;border-radius:10px">${items.length}</span>
        </h3>
        ${items.map(kartaMobile).join('')}
      `;
    }).join('');
  };

  c.innerHTML = `
    <div class="page-head">
      <div>
        <h1 class="page-title">🌾 Suroviny</h1>
        <p class="page-sub">${filtered.length} z ${list.length} surovin</p>
      </div>
      <div style="display:flex;gap:8px;flex-wrap:wrap">
        <button class="btn-secondary" onclick="navigate('vyroba')">← Výroba</button>
        <button class="btn-secondary" onclick="otevritKategorieSurovin()" title="Spravovat kategorie surovin (přidat, upravit, ikony)">📂 Kategorie</button>
        <button class="btn-secondary" onclick="otevritMatchSlozeni()" title="Projde složení výrobků a napáruje na suroviny">🔗 Spárovat</button>
        <button class="btn-secondary" onclick="openImportCenik('suroviny')" title="Import ceníku z Excel/CSV s auto-matchingem">📊 Import ceníku</button>
        <button class="btn-secondary" onclick="exportSurovinyCsv()" title="Export zobrazených surovin do CSV — seskupeno dle kategorie (respektuje filtr/kategorii)">📤 Export CSV</button>
        <button class="btn-secondary" onclick="otevritImportSurovin()" title="Hromadný import — základní balíček nebo CSV">📥 JSON / vzorky</button>
        <button class="btn-primary btn-green btn-big-action" onclick="editSurovina()" style="font-size:18px !important;font-weight:800 !important;padding:18px 32px !important;min-height:64px !important;border-radius:12px !important;letter-spacing:0.3px !important">+ Nová surovina</button>
      </div>
    </div>

    <!-- Filtry -->
    <div class="card-block sur-filtry" style="margin-bottom:16px">
      <div style="display:grid;grid-template-columns:2fr 1fr 1fr auto;gap:12px;align-items:stretch" class="sur-filter-grid">
        <input class="form-input" type="search" id="sf-q" placeholder="🔍 Hledat (název, alergen, složení)..." value="${esc(q)}" oninput="state._suroviny_q=this.value;debounce('sf-q', renderSuroviny, 220)">
        <select class="form-input" onchange="state._suroviny_aktivni=this.value;renderSuroviny()">
          <option value="vse"     ${aktivni === 'vse'     ? 'selected' : ''}>Stav: vše</option>
          <option value="aktivni" ${aktivni === 'aktivni' ? 'selected' : ''}>✓ Jen aktivní</option>
          <option value="skryte"  ${aktivni === 'skryte'  ? 'selected' : ''}>○ Jen skryté</option>
        </select>
        <select class="form-input" onchange="state._suroviny_alergen=this.value;renderSuroviny()">
          <option value="vse" ${alergen === 'vse' ? 'selected' : ''}>Alergeny: vše</option>
          <option value="s"   ${alergen === 's'   ? 'selected' : ''}>⚠ S alergeny</option>
          <option value="bez" ${alergen === 'bez' ? 'selected' : ''}>✓ Bez alergenů</option>
        </select>
        <label class="sur-group-toggle" style="display:flex;align-items:center;gap:8px;font-weight:600;white-space:nowrap;cursor:pointer">
          <input type="checkbox" ${groupBy ? 'checked' : ''} onchange="state._suroviny_group=this.checked;renderSuroviny()" style="width:18px;height:18px;cursor:pointer;accent-color:var(--primary)">
          Roztřídit
        </label>
      </div>

      <!-- 🆕 v3.0.148 — Cena za jednotku: přepínač kg/g (zobrazení sloupce Cena za jed.) -->
      <div style="margin-top:14px;display:flex;align-items:center;gap:10px;flex-wrap:wrap">
        <span style="font-size:15px;font-weight:600;color:var(--text-2)">💰 Cena za jednotku:</span>
        <div class="sur-unit-toggle" role="group" aria-label="Jednotka ceny (hmotnost)" title="Hmotnostní suroviny (g/kg)">
          <button type="button" class="${cenaJedUnit === 'kg' ? 'active' : ''}" onclick="if(state._suroviny_cena_jed!=='kg'){state._suroviny_cena_jed='kg';renderSuroviny()}">Kč/kg</button>
          <button type="button" class="${cenaJedUnit === 'g' ? 'active' : ''}" onclick="if(state._suroviny_cena_jed!=='g'){state._suroviny_cena_jed='g';renderSuroviny()}">Kč/g</button>
        </div>
        <div class="sur-unit-toggle" role="group" aria-label="Jednotka ceny (objem)" title="Tekuté suroviny (ml/L)">
          <button type="button" class="${cenaJedUnitVol === 'l' ? 'active' : ''}" onclick="if(state._suroviny_cena_jed_vol!=='l'){state._suroviny_cena_jed_vol='l';renderSuroviny()}">Kč/L</button>
          <button type="button" class="${cenaJedUnitVol === 'ml' ? 'active' : ''}" onclick="if(state._suroviny_cena_jed_vol!=='ml'){state._suroviny_cena_jed_vol='ml';renderSuroviny()}">Kč/ml</button>
        </div>
      </div>

      ${podMinimem > 0 ? `
        <div style="margin-top:14px;display:flex;align-items:center;gap:10px;flex-wrap:wrap">
          <label style="display:inline-flex;align-items:center;gap:8px;background:${state._suroviny_pod_minimem ? '#FEE2E2' : 'var(--surface-2)'};padding:8px 14px;border-radius:8px;cursor:pointer;border:1.5px solid ${state._suroviny_pod_minimem ? '#DC2626' : 'var(--border)'};font-weight:600;font-size:14px">
            <input type="checkbox" ${state._suroviny_pod_minimem ? 'checked' : ''} onchange="state._suroviny_pod_minimem=this.checked;renderSuroviny()" style="width:18px;height:18px;cursor:pointer;accent-color:#DC2626">
            ⚠️ Jen pod minimální hladinou
            <span style="background:#DC2626;color:white;padding:2px 8px;border-radius:10px;font-size:12px;font-weight:700;margin-left:4px">${podMinimem}</span>
          </label>
        </div>
      ` : ''}

      <!-- Kategorie pillsy -->
      <div style="display:flex;flex-wrap:wrap;gap:10px;margin-top:18px">
        <button class="period-tab ${kat === 'vse' ? 'active' : ''}" onclick="state._suroviny_kat='vse';renderSuroviny()" style="font-size:16px;padding:12px 20px;font-weight:700;min-height:48px;display:inline-flex;align-items:center;gap:8px;flex:0 0 auto;overflow:visible">
          <span style="font-size:28px;line-height:1">📚</span>
          <span>Vše</span>
          <span style="opacity:0.7;margin-left:4px">${list.length}</span>
        </button>
        ${SUROVINA_KATEGORIE.filter(k => (pocty[k.key] || 0) > 0).map(k => `
          <button class="period-tab ${kat === k.key ? 'active' : ''}" onclick="state._suroviny_kat='${k.key}';renderSuroviny()" style="font-size:16px;padding:12px 20px;font-weight:700;min-height:48px;display:inline-flex;align-items:center;gap:8px;flex:0 0 auto;overflow:visible">
            <span style="font-size:28px;line-height:1">${k.icon}</span>
            <span>${k.label}</span>
            <span style="opacity:0.7;margin-left:4px">${pocty[k.key]}</span>
          </button>
        `).join('')}
      </div>
    </div>

    <div class="card-block desktop-only-block">
      ${tabulkaDesktop()}
    </div>

    <!-- Mobile -->
    <div class="mobile-only-block">
      ${seznamMobile()}
    </div>
  `;
}

