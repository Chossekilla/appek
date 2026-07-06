// =============================================================
// 🍕 RESTAURACE / PIZZERIE — outer page s 4 hlavními tabs
// =============================================================
// 🆕 v3.0.20 — Seed restaurant demo pack (pizzy, káva, saláty, dezerty + suroviny + recepty + nutri)
window.seedRestaurantPack = async function() {
  if (!(await confirmDialog({ msg: '🍕 Naseed restaurant demo data?\n\nVloží:\n• 6 kategorií (Pizzy, Káva, Nealko, Saláty, Dezerty, Těstoviny)\n• 18 surovin s nutričními hodnotami\n• 11 výrobků (Margherita, Quattro Formaggi, Espresso, Cappuccino, Caesar, Tiramisu...)\n• Receptury → auto-výpočet nutri\n\nExistující se zachová (idempotent).', danger: false }))) return;
  try {
    const r = await api('admin_demo_seed.php?action=seed_restaurant_pack', { method: 'POST' });
    if (r && r.ok) {
      alert(t('demo_seed_done', { cat: r.kategorie, sur: r.suroviny, newP: r.vyrobky_created, updP: r.vyrobky_updated, recipes: r.recepty }));
      if (typeof toast === 'function') toast(r.msg, 'success');
    } else {
      alert('❌ ' + (r?.error || 'Neznámá chyba'));
    }
  } catch (e) {
    alert('❌ Chyba: ' + e.message);
  }
};

async function renderRestaurantPage() {
  const tab = state._restTab || 'tables';
  const c = document.getElementById('content');
  c.innerHTML = `
    <div class="page-head">
      <div>
        <h1 class="page-title">🍕 Restaurace / Pizzerie</h1>
        <p class="page-sub">Stolová správa · Kapacita kuchyně · Doba přípravy · Rozvoz a kurýrky</p>
      </div>
      <div style="display:flex;gap:8px;flex-wrap:wrap;align-items:center">
        <button class="btn-back" onclick="navigate('dashboard')" title="Zpět na Přehled"><span class="btn-back-arrow">←</span> <span class="btn-back-lbl">Přehled</span></button>
        <!-- 🆕 v3.0.139 — "Naseed demo data" ODSTRANĚN odtud. Seed je teď JEDINÝ
             v Nastavení → Údržba → Demo data (aby nešlo omylem přepsat data z provozu). -->
      </div>
    </div>
    <!-- 🆕 v3.0.42 — Velké barevné bannery místo malých tabů -->
    <div class="rest-banner-tabs" role="tablist" style="display:grid;grid-template-columns:repeat(auto-fit,minmax(170px,1fr));gap:12px;margin-bottom:18px">
      ${[
        { k:'provoz',   icon:'📺', label:'Provoz',          sub:'Live monitor + KDS',     grad:'135deg,#3B82F6,#1E40AF', light:'#DBEAFE', dark:'#1E3A8A' },
        { k:'pos',      icon:'🧾', label:'POS Kasa',        sub:'Restaurační pokladna',   grad:'135deg,#10B981,#065F46', light:'#D1FAE5', dark:'#064E3B' },
        { k:'tables',   icon:'🪑', label:'Stoly',           sub:'Layout · rezervace',     grad:'135deg,#BA7517,#854F0B', light:'#FEF3C7', dark:'#78350F' },
        { k:'kitchen',  icon:'👨‍🍳', label:'Kapacita kuchyně', sub:'Stanice · max paral.', grad:'135deg,#EF4444,#991B1B', light:'#FEE2E2', dark:'#7F1D1D' },
        { k:'prep',     icon:'⏱️', label:'Doba přípravy',   sub:'Min. per výrobek',       grad:'135deg,#A78BFA,#5B21B6', light:'#EDE9FE', dark:'#4C1D95' },
        { k:'couriers', icon:'🛵', label:'Rozvoz / Kurýři', sub:'Wolt · Bolt · vlastní',  grad:'135deg,#F97316,#9A3412', light:'#FFEDD5', dark:'#7C2D12' },
        { k:'menu',     icon:'🗓️', label:'Týdenní menu',    sub:'Z výrobků · rozesílka · sdílení', grad:'135deg,#14B8A6,#0F766E', light:'#CCFBF1', dark:'#134E4A' },
      ].map(b => {
        const active = tab === b.k || (b.k === 'provoz' && !tab);
        return `
        <button class="rest-banner ${active ? 'is-active' : ''}"
                role="tab"
                aria-selected="${active}"
                onclick="state._restTab='${b.k}';renderRestaurantPage()"
                style="${active
                  ? `background:linear-gradient(${b.grad});color:#fff;border:2px solid transparent;box-shadow:0 10px 28px ${b.light}80,0 4px 10px rgba(0,0,0,0.18)`
                  : `background:${b.light};color:${b.dark};border:2px solid ${b.light}`
                }">
          <div class="rb-icon" style="font-size:30px;line-height:1;${active ? 'filter:drop-shadow(0 2px 4px rgba(0,0,0,0.22))' : ''}">${b.icon}</div>
          <div class="rb-text">
            <div class="rb-label" style="font-size:15px;font-weight:800;letter-spacing:-0.01em">${b.label}</div>
            <div class="rb-sub" style="font-size:11.5px;font-weight:600;opacity:${active ? '0.92' : '0.75'};margin-top:1px">${b.sub}</div>
          </div>
        </button>
        `;
      }).join('')}
    </div>
    <div id="rest-tab-body"></div>
  `;

  if (tab === 'provoz' || !tab) return renderRestaurantProvoz();
  if (tab === 'pos')      return renderPOSLauncher();
  if (tab === 'kitchen')  return renderKitchenCapacity();
  if (tab === 'prep')     return renderPrepTimes();
  if (tab === 'couriers') return renderCouriers();
  if (tab === 'menu')     return renderTydenniMenu();   // 🗓️ v3.0.409 — týdenní menu
  return renderRestaurantTables();
}

// 🆕 v3.0.1 — Provoz tab v Restaurace sekci (přesunuto z Dashboardu)
// Zobrazí stejný widget jako dashboard, ale s navíc "Otevřít na druhý monitor"
// tlačítkem co otevře /provoz.php standalone kiosk page.
async function renderRestaurantProvoz() {
  const body = document.getElementById('rest-tab-body');
  if (!body) return;

  // 🆕 v3.0.25 — HERO akční karty nahoře (klíčové akce na 1 klik)
  body.innerHTML = `
    <!-- HERO akční karty -->
    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:14px;margin-bottom:24px">
      <button onclick="window.openPOSWindow?.()" style="background:linear-gradient(135deg,#FBBF24,#FB923C);color:#1F2937;border:none;border-radius:14px;padding:22px;cursor:pointer;text-align:left;font-family:inherit;transition:all 0.18s ease;display:flex;flex-direction:column;gap:10px;box-shadow:0 4px 14px rgba(251,146,60,0.25)" onmouseover="this.style.transform='translateY(-3px)';this.style.boxShadow='0 8px 22px rgba(251,146,60,0.35)'" onmouseout="this.style.transform='';this.style.boxShadow='0 4px 14px rgba(251,146,60,0.25)'">
        <div style="font-size:38px;line-height:1">🧾</div>
        <div>
          <div style="font-size:18px;font-weight:900;letter-spacing:-0.02em">Otevřít POS Kasu</div>
          <div style="font-size:12px;opacity:0.8;margin-top:2px">Stůl / pokladnu / sebou — fullscreen</div>
        </div>
        <div style="font-size:11px;font-weight:800;color:#1F2937;margin-top:auto">→ OTEVŘÍT V NOVÉM OKNĚ</div>
      </button>

      <button onclick="window.openKDSWindow?.()" style="background:linear-gradient(135deg,#34D399,#10B981);color:#052e1c;border:none;border-radius:14px;padding:22px;cursor:pointer;text-align:left;font-family:inherit;transition:all 0.18s ease;display:flex;flex-direction:column;gap:10px;box-shadow:0 4px 14px rgba(16,185,129,0.25)" onmouseover="this.style.transform='translateY(-3px)';this.style.boxShadow='0 8px 22px rgba(16,185,129,0.35)'" onmouseout="this.style.transform='';this.style.boxShadow='0 4px 14px rgba(16,185,129,0.25)'">
        <div style="font-size:38px;line-height:1">👨‍🍳</div>
        <div>
          <div style="font-size:18px;font-weight:900;letter-spacing:-0.02em">Kuchyňský displej</div>
          <div style="font-size:12px;opacity:0.8;margin-top:2px">KDS pro kuchaře — vařit → hotovo</div>
        </div>
        <div style="font-size:11px;font-weight:800;color:#052e1c;margin-top:auto">→ OTEVŘÍT KDS</div>
      </button>

      <button onclick="window.openVydejWindow?.()" style="background:linear-gradient(135deg,#60A5FA,#3B82F6);color:#fff;border:none;border-radius:14px;padding:22px;cursor:pointer;text-align:left;font-family:inherit;transition:all 0.18s ease;display:flex;flex-direction:column;gap:10px;box-shadow:0 4px 14px rgba(59,130,246,0.25)" onmouseover="this.style.transform='translateY(-3px)';this.style.boxShadow='0 8px 22px rgba(59,130,246,0.35)'" onmouseout="this.style.transform='';this.style.boxShadow='0 4px 14px rgba(59,130,246,0.25)'">
        <div style="font-size:38px;line-height:1">📤</div>
        <div>
          <div style="font-size:18px;font-weight:900;letter-spacing:-0.02em">Výdej (Pass)</div>
          <div style="font-size:12px;opacity:0.9;margin-top:2px">Pro číšníky — hotová jídla k odnesení</div>
        </div>
        <div style="font-size:11px;font-weight:800;color:#fff;margin-top:auto">→ OTEVŘÍT VÝDEJ</div>
      </button>

      <button onclick="window.openProvozMonitor?.()" style="background:linear-gradient(135deg,#1F2937,#111827);color:#fff;border:none;border-radius:14px;padding:22px;cursor:pointer;text-align:left;font-family:inherit;transition:all 0.18s ease;display:flex;flex-direction:column;gap:10px;box-shadow:0 4px 14px rgba(0,0,0,0.3)" onmouseover="this.style.transform='translateY(-3px)';this.style.boxShadow='0 8px 22px rgba(0,0,0,0.4)'" onmouseout="this.style.transform='';this.style.boxShadow='0 4px 14px rgba(0,0,0,0.3)'">
        <div style="font-size:38px;line-height:1">📺</div>
        <div>
          <div style="font-size:18px;font-weight:900;letter-spacing:-0.02em">Provoz monitor</div>
          <div style="font-size:12px;opacity:0.8;margin-top:2px">Live přehled pro druhý monitor / TV</div>
        </div>
        <div style="font-size:11px;font-weight:800;color:#FB923C;margin-top:auto">→ KIOSK FULLSCREEN</div>
      </button>
    </div>

    <div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:10px;margin-bottom:14px">
      <div>
        <h3 style="margin:0 0 4px;font-size:18px">📊 Živý přehled provozu</h3>
        <p style="margin:0;font-size:12px;color:var(--text-3)">Stoly · Kuchyně · Rozvoz · POS dnes — auto-refresh každých 10 s.</p>
      </div>
      <div style="display:flex;gap:8px;flex-wrap:wrap">
        <button class="btn-secondary" onclick="window.openProvozMonitor?.()" title="Otevřít na samostatném monitoru (např. v kanceláři / kuchyni / na shop floor)" style="display:flex;align-items:center;gap:6px">
          🖥️ Otevřít na druhý monitor
        </button>
        <button class="btn-secondary" onclick="loadProvozWidget()" title="Manuálně obnovit data">🔄</button>
      </div>
    </div>

    <!-- Widget container (stejný ID jako dashboard, aby loadProvozWidget fungoval) -->
    <div id="dash-provoz-widget" style="display:none"></div>

    <!-- 🎨 v3.0.25 — duplicitní karty odstraněny (jsou v HERO panelu nahoře) -->

    <!-- Tip pro multi-screen setup -->
    <div style="margin-top:18px;padding:14px 18px;background:linear-gradient(135deg,#FFFBEB,#FFF8F0);border:1px solid #F0D9B8;border-radius:10px;font-size:13px;color:#854F0B;line-height:1.55">
      💡 <strong>Tip pro profesionální restauraci se 3+ monitory:</strong>
      <ul style="margin:6px 0 0 18px;padding:0">
        <li>👨‍🍳 <strong>KDS</strong> u kuchaře — vidí co vařit (objednáno → vaří se → hotovo)</li>
        <li>📤 <strong>Výdej</strong> u pass-through okna — číšník vidí jen hotové, klik = odneseno</li>
        <li>📺 <strong>Provoz</strong> v kanceláři nebo na floor — celkový přehled (stoly, kuchyně, rozvoz, POS dnes)</li>
        <li>🧾 <strong>POS</strong> na tabletu u pultu / dotykový terminál</li>
      </ul>
      Vše real-time přes sdílenou DB. Stačí 1 server + 1 router.
    </div>
  `;

  // Trigger load — využije existující loadProvozWidget logiku
  setTimeout(() => loadProvozWidget(), 50);
}

// 🆕 v3.0.1 — Open Provoz na samostatném okně (kiosk mode, žádný admin chrome)
window.openProvozMonitor = function() {
  const w = window.open('provoz.php', 'appek_provoz',
    `width=${screen.availWidth},height=${screen.availHeight},left=0,top=0,toolbar=no,menubar=no,location=no,status=no,scrollbars=yes,resizable=yes`);
  if (!w) {
    alert('Prohlížeč zablokoval popup. Povolte popup okna pro tuto stránku.');
    return;
  }
  w.focus();
};

// 🆕 v3.0.1 — KDS (Kitchen Display Screen) v novém okně
window.openKDSWindow = function() {
  const w = window.open('kds.php', 'appek_kds',
    `width=${screen.availWidth},height=${screen.availHeight},left=0,top=0,toolbar=no,menubar=no,location=no,status=no,scrollbars=yes,resizable=yes`);
  if (!w) {
    alert('Prohlížeč zablokoval popup. Povolte popup okna pro tuto stránku.');
    return;
  }
  w.focus();
};

// 🆕 v3.0.4 — Výdej (Pass-through display) v novém okně
window.openVydejWindow = function() {
  const w = window.open('vydej.php', 'appek_vydej',
    `width=${screen.availWidth},height=${screen.availHeight},left=0,top=0,toolbar=no,menubar=no,location=no,status=no,scrollbars=yes,resizable=yes`);
  if (!w) {
    alert('Prohlížeč zablokoval popup. Povolte popup okna pro tuto stránku.');
    return;
  }
  w.focus();
};

// 🆕 v3.0.22 — Floor plan editor v novém okně (BUG fix — předtím neexistoval, banner byl mrtvý)
window.openFloorplanWindow = function() {
  const w = window.open('floorplan.php', 'appek_floorplan',
    `width=${Math.min(1400, screen.availWidth)},height=${Math.min(900, screen.availHeight)},toolbar=no,menubar=no,location=no,status=no,scrollbars=yes,resizable=yes`);
  if (!w) {
    // Fallback: naviguj do admin Restaurace → Stoly → Floor plan tab
    navigate('pkg_restaurace');
    setTimeout(() => {
      state._restTab = 'tables';
      state._rtTab = 'map';
      renderRestaurantPage();
    }, 100);
    return;
  }
  w.focus();
};

async function renderRestaurantTables() {
  const c = document.getElementById('rest-tab-body') || document.getElementById('content');
  const today = state._rtDate || new Date().toISOString().slice(0, 10);
  state._rtDate = today;
  const tab = state._rtTab || 'map';
  c.innerHTML = `
    <div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:10px;margin-bottom:14px">
      <div></div>
      <div style="display:flex;gap:8px;align-items:center">
        ${(tab === 'timeline' || tab === 'list') ? '' : `<input type="date" class="form-input" id="rt-date" value="${today}" onchange="state._rtDate=this.value;renderRestaurantTables()" style="width:auto">`}
        <button class="btn-secondary" onclick="window.open('floorplan.php','appek_fp','width='+screen.availWidth+',height='+screen.availHeight+',toolbar=no,menubar=no')" title="Otevřít plnotučný Floor Plan editor v novém okně">🗺️ Editor mapy</button>
        <button class="btn-secondary" onclick="addRestaurantTable()">+ Nový stůl</button>
      </div>
    </div>
    <!-- 🆕 v3.0.43 — Sub-tabs jako mini-bannery (sjednoceno s main banner tabs v3.0.42) -->
    <div class="rest-subtabs" role="tablist" style="display:grid;grid-template-columns:repeat(auto-fit,minmax(150px,1fr));gap:10px;margin-bottom:14px">
      ${[
        /* 🆕 v3.0.135 — spodní taby sjednoceny na BRAND oranžovou (user: "moc cirkus").
           Všechny stejná barva, rozliší je jen aktivní stav (plný gradient vs světlá krémová). */
        { k:'map',       icon:'🗺️', label:'Layout',         sub:'Floor plan editor',  grad:'135deg,#BA7517,#854F0B', light:'#FAEEDA', dark:'#854F0B', match:(t)=>t==='map' },
        { k:'timeline',  icon:'📅', label:'Rezervace',      sub:'Timeline · seznam',  grad:'135deg,#BA7517,#854F0B', light:'#FAEEDA', dark:'#854F0B', match:(t)=>t==='timeline'||t==='list' },
        { k:'open_ucty', icon:'🧾', label:'Otevřené účty', sub:'Aktivní POS účty',   grad:'135deg,#BA7517,#854F0B', light:'#FAEEDA', dark:'#854F0B', match:(t)=>t==='open_ucty' },
        { k:'qr_pending',icon:'📲', label:'QR queue',       sub:'Pending objednávky', grad:'135deg,#BA7517,#854F0B', light:'#FAEEDA', dark:'#854F0B', match:(t)=>t==='qr_pending' },
      ].map(b => {
        const active = b.match(tab);
        return `
        <button class="rest-subtab ${active ? 'is-active' : ''}"
                role="tab"
                aria-selected="${active}"
                onclick="state._rtTab='${b.k}';renderRestaurantTables()"
                style="${active
                  ? `background:linear-gradient(${b.grad});color:#fff;border:2px solid transparent;box-shadow:0 6px 18px ${b.light}90,0 2px 6px rgba(0,0,0,0.14)`
                  : `background:${b.light};color:${b.dark};border:2px solid ${b.light}`
                }">
          <div class="rsb-icon" style="font-size:22px;line-height:1;${active ? 'filter:drop-shadow(0 1px 3px rgba(0,0,0,0.2))' : ''}">${b.icon}</div>
          <div class="rsb-text">
            <div class="rsb-label" style="font-size:13.5px;font-weight:800;letter-spacing:-0.01em">${b.label}</div>
            <div class="rsb-sub" style="font-size:10.5px;font-weight:600;opacity:${active ? '0.92' : '0.72'};margin-top:1px">${b.sub}</div>
          </div>
        </button>
        `;
      }).join('')}
    </div>
    <div id="rt-body">${skeletonCards(4)}</div>
  `;

  let data;
  try { data = await api('admin_tables.php?date=' + today); }
  catch (e) {
    document.getElementById('rt-body').innerHTML = `<div class="alert err">${esc(e.message)}</div>`;
    return;
  }

  const stoly = data.stoly || [];
  state._rtHours = data.oteviraci_doba || []; // 🆕 v3.0.201 — týdenní otevírací doba pro editor
  const sekce = [...new Set(stoly.map(s => s.sekce || '— bez sekce —'))];
  const rezervaciDnes = stoly.reduce((s, t) => s + (t.obsazenost_dnes || 0), 0);

  // 🆕 v3.0.242 — cache pro edit rezervace (proklik ze seznamu i timeline) + filtr zón
  state._rtZones = data.zones || [];
  state._rtStolyCache = stoly;
  state._rtRezMap = {};
  const _zoneById = Object.fromEntries((data.zones || []).map(z => [z.id, z]));
  for (const t of stoly) {
    const zona = _zoneById[t.zone_id] || null;
    for (const r of (t.rezervace_dnes || [])) {
      state._rtRezMap[r.id] = { ...r, stul_nazev: t.nazev, stul_id: t.id, zone_id: t.zone_id || null, zona_nazev: zona ? zona.nazev : (t.sekce || ''), zona_ikona: zona ? zona.ikona : '' };
    }
  }

  const statsBar = `
    <div class="card-block" style="margin-bottom:14px">
      <div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:10px">
        <div>
          <strong style="font-size:14px">📊 ${stoly.length} stolů · ${stoly.reduce((s,t)=>s+t.mist,0)} míst celkem · ${rezervaciDnes} rezervací na ${new Date(today).toLocaleDateString('cs-CZ')}</strong>
        </div>
      </div>
    </div>
  `;

  if (stoly.length === 0) {
    // 🆕 v3.0.243 — empty state nesmí být slepá ulička: dřív šel přidat JEN stůl,
    //   šablona/zóna ne (user po smazání všeho neměl jak obnovit layout bez editoru).
    document.getElementById('rt-body').innerHTML = emptyState({
      icon: '🪑', title: 'Žádné stoly',
      msg: 'Začni šablonou (hotový layout restaurace na 1 klik), nebo si vytvoř zónu a stoly ručně.',
      actions: `
        <div style="display:flex;gap:10px;flex-wrap:wrap;justify-content:center">
          <button class="btn-primary btn-green" onclick="openSablonyPicker()">📋 Načíst šablonu</button>
          <button class="btn-secondary" onclick="pridatZonu()">➕ Nová zóna</button>
          <button class="btn-secondary" onclick="addRestaurantTable()">🪑 Přidat stůl</button>
          <button class="btn-secondary" onclick="window.open('floorplan.php','appek_fp','width='+screen.availWidth+',height='+screen.availHeight+',toolbar=no,menubar=no')">🗺️ Editor mapy</button>
        </div>`,
    });
    return;
  }

  // 🎨 v3.0.24 — Rezervace tab kombinuje Timeline + Seznam jako segment switch
  if (tab === 'timeline' || tab === 'list') {
    const subView = state._rtRezSubView || 'timeline';
    // 🆕 v3.0.201 — popisek otevírací doby vybraného dne + tlačítko editoru
    const denDnes = data.den_dnes;
    const hoursLabel = denDnes
      ? (denDnes.zavreno == 1 ? '🔒 Zavřeno' : `${String(denDnes.otevreno_od).slice(0,5)}–${String(denDnes.otevreno_do).slice(0,5)}`)
      : '';
    // 🆕 v3.0.311 — rychlý filtr dne rezervací (dnes/zítra/pozítří + date picker) u otevírací doby
    const _qd = (off) => { const d = new Date(); d.setDate(d.getDate() + off); return d.toISOString().slice(0, 10); };
    const segSwitch = `
      <div style="display:flex;gap:10px;margin-bottom:14px;flex-wrap:wrap;align-items:center;justify-content:space-between">
        <div style="display:flex;gap:6px;padding:4px;background:var(--surface-2);border-radius:10px;width:fit-content">
          <button class="${subView === 'timeline' ? 'btn-primary' : 'btn-secondary'}" onclick="state._rtRezSubView='timeline';state._rtTab='timeline';renderRestaurantTables()" style="padding:8px 16px;font-size:13px;border:none">⏱️ Timeline</button>
          <button class="${subView === 'list' ? 'btn-primary' : 'btn-secondary'}" onclick="state._rtRezSubView='list';state._rtTab='list';renderRestaurantTables()" style="padding:8px 16px;font-size:13px;border:none">📋 Seznam</button>
        </div>
        <div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap">
          <div style="display:flex;gap:4px;padding:4px;background:var(--surface-2);border-radius:10px">
            ${[['Dnes', 0], ['Zítra', 1], ['Pozítří', 2]].map(([lbl, off]) => { const ds = _qd(off); return `<button class="${today === ds ? 'btn-primary' : 'btn-secondary'}" onclick="state._rtDate='${ds}';renderRestaurantTables()" style="padding:7px 12px;font-size:13px;border:none;white-space:nowrap">${lbl}</button>`; }).join('')}
          </div>
          <input type="date" class="form-input" value="${today}" onchange="if(this.value){state._rtDate=this.value;renderRestaurantTables()}" style="width:auto;padding:6px 10px;font-size:13px" title="Vyber datum rezervací">
          <button class="btn-secondary" onclick="editOpeningHours()" style="padding:8px 14px;font-size:13px" title="Nastavit otevírací dobu po dnech v týdnu">🕐 Otevírací doba${hoursLabel ? ` · <strong>${hoursLabel}</strong>` : ''}</button>
        </div>
      </div>
    `;
    if (subView === 'list' || tab === 'list') {
      document.getElementById('rt-body').innerHTML = statsBar + segSwitch + renderRestaurantList(stoly, today);
      return;
    }
    document.getElementById('rt-body').innerHTML = statsBar + segSwitch + '<div style="padding:30px;text-align:center;color:var(--text-3)">⏳ Načítám otevřené účty…</div>';
    api('admin_pos.php?action=open_ucty').then(r => {
      const openUcty = (r && r.ucty) || [];
      document.getElementById('rt-body').innerHTML = statsBar + segSwitch + renderRestaurantTimeline(stoly, today, openUcty, denDnes);
    }).catch(() => {
      document.getElementById('rt-body').innerHTML = statsBar + segSwitch + renderRestaurantTimeline(stoly, today, [], denDnes);
    });
    return;
  }

  // 🆕 v2.3 — KDS view
  if (tab === 'kds') {
    document.getElementById('rt-body').innerHTML = '<div style="padding:40px;text-align:center">⏳ Načítám KDS…</div>';
    renderKDS();
    return;
  }

  // 🆕 v2.3 — Otevřené účty
  if (tab === 'open_ucty') {
    document.getElementById('rt-body').innerHTML = '<div style="padding:40px;text-align:center">⏳ Načítám účty…</div>';
    renderOpenUcty();
    return;
  }

  // 🆕 v2.3 — QR objednávky čekající na schválení
  if (tab === 'qr_pending') {
    document.getElementById('rt-body').innerHTML = '<div style="padding:40px;text-align:center">⏳ Načítám QR objednávky…</div>';
    renderQRPending();
    return;
  }

  // Default: floor plan view (drag-and-drop canvas)
  state._rtData = data;
  document.getElementById('rt-body').innerHTML = statsBar + renderFloorPlan(data, today);
  setTimeout(() => { rtAttachDragHandlers(); if (typeof rtScaleCanvas === 'function') rtScaleCanvas(); }, 50);
}

