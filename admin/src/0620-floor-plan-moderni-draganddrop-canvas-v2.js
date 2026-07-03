// =============================================================
// 🗺️ FLOOR PLAN — moderní drag-and-drop canvas (v2.2)
// =============================================================
// Stavy stolů + jejich barvy/popis (single source of truth)
const RT_STATES = {
  free:      { label: '🟢 Volný',         bg: '#DCFCE7', border: '#16A34A', text: '#166534' },
  reserved:  { label: '🟡 Rezervováno',   bg: '#FEF3C7', border: '#F59E0B', text: '#92400E' },
  occupied:  { label: '🔴 Obsazeno',      bg: '#FEE2E2', border: '#DC2626', text: '#991B1B' },
  cleaning:  { label: '⚪ Uklízí se',     bg: '#F3F4F6', border: '#9CA3AF', text: '#374151' },
  attention: { label: '🟣 Pozornost',     bg: '#F3E8FF', border: '#9333EA', text: '#581C87' },
  disabled:  { label: '⚫ Vyřazen',       bg: '#1F2937', border: '#111827', text: '#F3F4F6' },
};

// Tvary stolů → CSS border-radius
const RT_SHAPES = {
  round:  { borderRadius: '50%',                            label: '🔵 Kruh' },
  square: { borderRadius: '12px',                           label: '🟧 Čtverec' },
  rect:   { borderRadius: '14px',                           label: '▭ Obdélník' },
};

window.rtState = window.rtState || {
  editMode: false,
  activeZoneId: null,
  draggedTableId: null,
  dragStart: null,
  dirtyTables: new Set(),  // ID tabulek změněných v current session
  selectedTableId: null,
};

function renderFloorPlan(data, today) {
  const stoly = data.stoly || [];
  const zones = (data.zones && data.zones.length) ? data.zones : [{ id: null, nazev: 'Hlavní sál', ikona: '🍽️', canvas_w: 800, canvas_h: 500 }];

  // Pick aktivní zónu (z state nebo první)
  if (!rtState.activeZoneId || !zones.find(z => z.id == rtState.activeZoneId)) {
    rtState.activeZoneId = zones[0].id;
  }
  const activeZone = zones.find(z => z.id == rtState.activeZoneId) || zones[0];
  // 🆕 v3.0.136 — Osiřelé stoly (zone_id null NEBO ukazuje na smazanou zónu) spadnou
  //   do PRVNÍ zóny, aby byly vždy vidět + editovatelné. Předtím: stůl s zone_id=null
  //   se nezobrazil v ŽÁDNÉ zóně když existovaly reálné zóny → prázdné plátno
  //   (user: "1 stůl ale Zahrada 0 / Pergola 0 a prázdné plátno").
  const validZoneIds = zones.map(z => z.id);
  const isOrphanTable = (s) => !validZoneIds.some(zid => zid == s.zone_id);
  const firstZoneId = zones[0].id;
  const stolyInZone = stoly.filter(s =>
    (s.zone_id == activeZone.id) || (isOrphanTable(s) && activeZone.id == firstZoneId)
  );

  // Per-stav stats v aktivní zóně
  const stateStats = { free: 0, reserved: 0, occupied: 0, cleaning: 0, attention: 0, disabled: 0 };
  for (const t of stolyInZone) stateStats[t.stav || 'free']++;

  const editMode = !!rtState.editMode;
  const dirtyCount = rtState.dirtyTables.size;

  // 🆕 v3.0.36 — Detekce "starý/výchozí layout" (A1-A6, B1-B4, C1-C4 pattern)
  // Pokud user má staré default tabulky, nabídneme upgrade na pěknou šablonu
  const oldPattern = stoly.length > 0 && stoly.filter(s => /^[A-C][0-9]+$/.test(s.nazev || '')).length / stoly.length > 0.6;
  const suggestNewTemplate = oldPattern && !state._rtTemplateBannerDismissed;
  const templateBanner = suggestNewTemplate ? `
    <div class="card-block" style="padding:16px 20px;margin-bottom:14px;background:linear-gradient(135deg,#EFF6FF,#DBEAFE);border:2px solid #3B82F6;border-radius:14px">
      <div style="display:flex;justify-content:space-between;align-items:start;gap:14px;flex-wrap:wrap;margin-bottom:12px">
        <div style="flex:1;min-width:240px">
          <h3 style="margin:0 0 4px;font-size:16px;color:#1E40AF;display:flex;align-items:center;gap:8px">
            ✨ Tvůj layout vypadá jako výchozí
          </h3>
          <p style="margin:0;font-size:13px;color:#1E3A8A;line-height:1.55">
            Vyzkoušej některou z <strong>10 vyladěných šablon</strong> — pizzerie, pivnice, banketní sál, steakhouse, rooftop, sushi bar…
          </p>
        </div>
        <button onclick="state._rtTemplateBannerDismissed=true;renderRestaurantTables()"
                style="padding:6px 12px;background:rgba(255,255,255,0.6);border:1px solid #93C5FD;color:#1E40AF;border-radius:8px;font-size:12px;font-weight:600;cursor:pointer">
          ✕ Zatím ne
        </button>
      </div>
      <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(160px,1fr));gap:8px">
        <button onclick="rtApplyTemplate('pizzerie_modena','🍕 Pizzeria Modena')"
                style="padding:12px;background:#fff;border:2px solid #FB923C;color:#9A3412;border-radius:10px;font-weight:700;font-size:13px;cursor:pointer;text-align:left;transition:all 0.15s ease"
                onmouseover="this.style.background='#FFF7ED';this.style.transform='translateY(-2px)'"
                onmouseout="this.style.background='#fff';this.style.transform=''">
          <div style="font-size:22px;line-height:1;margin-bottom:4px">🍕</div>
          <div>Pizzeria Modena</div>
          <div style="font-size:11px;font-weight:500;opacity:0.7;margin-top:2px">Bar + terasa</div>
        </button>
        <button onclick="rtApplyTemplate('bistro_verde','🍽️ Bistro Verde')"
                style="padding:12px;background:#fff;border:2px solid #10B981;color:#065F46;border-radius:10px;font-weight:700;font-size:13px;cursor:pointer;text-align:left;transition:all 0.15s ease"
                onmouseover="this.style.background='#F0FDF4';this.style.transform='translateY(-2px)'"
                onmouseout="this.style.background='#fff';this.style.transform=''">
          <div style="font-size:22px;line-height:1;margin-bottom:4px">🍽️</div>
          <div>Bistro Verde</div>
          <div style="font-size:11px;font-weight:500;opacity:0.7;margin-top:2px">3 zóny · lounge</div>
        </button>
        <button onclick="rtApplyTemplate('wine_bar_apollo','🍷 Wine bar Apollo')"
                style="padding:12px;background:#fff;border:2px solid #7C3AED;color:#5B21B6;border-radius:10px;font-weight:700;font-size:13px;cursor:pointer;text-align:left;transition:all 0.15s ease"
                onmouseover="this.style.background='#F5F3FF';this.style.transform='translateY(-2px)'"
                onmouseout="this.style.background='#fff';this.style.transform=''">
          <div style="font-size:22px;line-height:1;margin-bottom:4px">🍷</div>
          <div>Wine bar Apollo</div>
          <div style="font-size:11px;font-weight:500;opacity:0.7;margin-top:2px">U-shaped bar</div>
        </button>
        <button onclick="rtApplyTemplate('cafe_aurelio','☕ Cafe Aurelio')"
                style="padding:12px;background:#fff;border:2px solid #F59E0B;color:#78350F;border-radius:10px;font-weight:700;font-size:13px;cursor:pointer;text-align:left;transition:all 0.15s ease"
                onmouseover="this.style.background='#FFFBEB';this.style.transform='translateY(-2px)'"
                onmouseout="this.style.background='#fff';this.style.transform=''">
          <div style="font-size:22px;line-height:1;margin-bottom:4px">☕</div>
          <div>Cafe Aurelio</div>
          <div style="font-size:11px;font-weight:500;opacity:0.7;margin-top:2px">Komunitní stůl</div>
        </button>
        <button onclick="rtApplyTemplate('letni_zahrada','🌳 Letní zahrada')"
                style="padding:12px;background:#fff;border:2px solid #34D399;color:#065F46;border-radius:10px;font-weight:700;font-size:13px;cursor:pointer;text-align:left;transition:all 0.15s ease"
                onmouseover="this.style.background='#ECFDF5';this.style.transform='translateY(-2px)'"
                onmouseout="this.style.background='#fff';this.style.transform=''">
          <div style="font-size:22px;line-height:1;margin-bottom:4px">🌳</div>
          <div>Letní zahrada</div>
          <div style="font-size:11px;font-weight:500;opacity:0.7;margin-top:2px">Outdoor + pergola</div>
        </button>
        <button onclick="rtApplyTemplate('pivnice_plzen','🍺 Pivnice Plzeň')"
                style="padding:12px;background:#fff;border:2px solid #FBBF24;color:#78350F;border-radius:10px;font-weight:700;font-size:13px;cursor:pointer;text-align:left;transition:all 0.15s ease"
                onmouseover="this.style.background='#FFFBEB';this.style.transform='translateY(-2px)'"
                onmouseout="this.style.background='#fff';this.style.transform=''">
          <div style="font-size:22px;line-height:1;margin-bottom:4px">🍺</div>
          <div>Pivnice Plzeň</div>
          <div style="font-size:11px;font-weight:500;opacity:0.7;margin-top:2px">Dlouhé stoly · tap</div>
        </button>
        <button onclick="rtApplyTemplate('banketni_sal','🎉 Banketní sál')"
                style="padding:12px;background:#fff;border:2px solid #A78BFA;color:#5B21B6;border-radius:10px;font-weight:700;font-size:13px;cursor:pointer;text-align:left;transition:all 0.15s ease"
                onmouseover="this.style.background='#F5F3FF';this.style.transform='translateY(-2px)'"
                onmouseout="this.style.background='#fff';this.style.transform=''">
          <div style="font-size:22px;line-height:1;margin-bottom:4px">🎉</div>
          <div>Banketní sál</div>
          <div style="font-size:11px;font-weight:500;opacity:0.7;margin-top:2px">Svatby · oslavy</div>
        </button>
        <button onclick="rtApplyTemplate('steakhouse_grand','🥩 Steakhouse Grand')"
                style="padding:12px;background:#fff;border:2px solid #DC2626;color:#991B1B;border-radius:10px;font-weight:700;font-size:13px;cursor:pointer;text-align:left;transition:all 0.15s ease"
                onmouseover="this.style.background='#FEF2F2';this.style.transform='translateY(-2px)'"
                onmouseout="this.style.background='#fff';this.style.transform=''">
          <div style="font-size:22px;line-height:1;margin-bottom:4px">🥩</div>
          <div>Steakhouse Grand</div>
          <div style="font-size:11px;font-weight:500;opacity:0.7;margin-top:2px">Booths · VIP · grill</div>
        </button>
        <button onclick="rtApplyTemplate('burger_bistro','🍔 Burger Bistro')"
                style="padding:12px;background:#fff;border:2px solid #F97316;color:#9A3412;border-radius:10px;font-weight:700;font-size:13px;cursor:pointer;text-align:left;transition:all 0.15s ease"
                onmouseover="this.style.background='#FFF7ED';this.style.transform='translateY(-2px)'"
                onmouseout="this.style.background='#fff';this.style.transform=''">
          <div style="font-size:22px;line-height:1;margin-bottom:4px">🍔</div>
          <div>Burger Bistro</div>
          <div style="font-size:11px;font-weight:500;opacity:0.7;margin-top:2px">Fast-casual · pickup</div>
        </button>
        <button onclick="rtApplyTemplate('rooftop_praha','🏙️ Rooftop Praha')"
                style="padding:12px;background:#fff;border:2px solid #6366F1;color:#3730A3;border-radius:10px;font-weight:700;font-size:13px;cursor:pointer;text-align:left;transition:all 0.15s ease"
                onmouseover="this.style.background='#EEF2FF';this.style.transform='translateY(-2px)'"
                onmouseout="this.style.background='#fff';this.style.transform=''">
          <div style="font-size:22px;line-height:1;margin-bottom:4px">🏙️</div>
          <div>Rooftop Praha</div>
          <div style="font-size:11px;font-weight:500;opacity:0.7;margin-top:2px">Cocktail · sunset</div>
        </button>
        <button onclick="rtApplyTemplate('sushi_asia','🍣 Sushi & Asia')"
                style="padding:12px;background:#fff;border:2px solid #EC4899;color:#9D174D;border-radius:10px;font-weight:700;font-size:13px;cursor:pointer;text-align:left;transition:all 0.15s ease"
                onmouseover="this.style.background='#FDF2F8';this.style.transform='translateY(-2px)'"
                onmouseout="this.style.background='#fff';this.style.transform=''">
          <div style="font-size:22px;line-height:1;margin-bottom:4px">🍣</div>
          <div>Sushi & Asia</div>
          <div style="font-size:11px;font-weight:500;opacity:0.7;margin-top:2px">Sushi bar · tatami</div>
        </button>
      </div>
      <div style="margin-top:12px;display:flex;justify-content:space-between;align-items:center;gap:10px;flex-wrap:wrap">
        <span style="font-size:11px;color:#1E3A8A;opacity:0.8">
          💡 Šablona přepíše <strong>rozložení stolů</strong> — tvoje zóny i otevřené účty zůstanou (v „Všechny šablony" lze přepnout).
        </span>
        <button onclick="rtOpenTemplatePicker()" style="padding:6px 14px;background:#1E40AF;color:#fff;border:none;border-radius:7px;font-size:12px;font-weight:700;cursor:pointer">
          📋 Všechny šablony s náhledem →
        </button>
      </div>
    </div>
  ` : '';

  return templateBanner + `
    <!-- Toolbar -->
    <div class="card-block" style="padding:10px 14px;margin-bottom:10px;display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:10px">
      <div style="display:flex;gap:6px;align-items:center;flex-wrap:wrap">
        <button class="btn-secondary ${editMode ? 'is-active' : ''}" onclick="rtToggleEditMode()" style="font-size:13px;padding:7px 14px;${editMode ? 'background:linear-gradient(135deg,#BA7517,#854F0B);color:#fff;border-color:#854F0B' : ''}">
          ${editMode ? '✅ Hotovo (provoz)' : '✏️ Editovat layout'}
        </button>
        ${editMode ? `
          <button class="btn-secondary" onclick="rtOpenTemplatePicker()" style="font-size:13px;padding:7px 14px;background:#FEF3C7;border-color:#F59E0B;color:#92400E">📋 Šablony</button>
          <button class="btn-secondary" onclick="rtOpenZoneManager()" style="font-size:13px;padding:7px 14px">🗺️ Zóny</button>
          ${dirtyCount > 0 ? `<button class="btn-primary btn-green" onclick="rtSaveLayout()" style="font-size:13px;padding:7px 14px">💾 Uložit (${dirtyCount} změn)</button>` : ''}
        ` : ''}
      </div>
      ${editMode ? `
        <!-- 🎨 v3.0.24 — Quick-add v collapsible details (default zavřené, jen na "+ Přidat stůl") -->
        <details style="position:relative">
          <summary style="cursor:pointer;padding:8px 14px;background:linear-gradient(135deg,#FEF3C7,#FDE68A);border:1.5px solid #F59E0B;border-radius:8px;font-size:13px;font-weight:700;color:#92400E;list-style:none;user-select:none">+ Přidat stůl ▾</summary>
          <div style="position:absolute;top:100%;left:0;margin-top:6px;display:flex;gap:5px;flex-wrap:wrap;background:#fff;padding:10px;border:1px solid var(--border);border-radius:10px;box-shadow:0 6px 20px rgba(0,0,0,0.12);z-index:5;min-width:280px">
            <button onclick="rtQuickAddTable('square2')" title="Čtverec pro 2"  style="padding:8px 12px;border:1.5px solid #E5E7EB;background:#fff;border-radius:7px;cursor:pointer;font-weight:700;font-size:13px">⬜ 2</button>
            <button onclick="rtQuickAddTable('square4')" title="Čtverec pro 4"  style="padding:8px 12px;border:1.5px solid #E5E7EB;background:#fff;border-radius:7px;cursor:pointer;font-weight:700;font-size:13px">⬜ 4</button>
            <button onclick="rtQuickAddTable('round2')"  title="Kruh pro 2"     style="padding:8px 12px;border:1.5px solid #E5E7EB;background:#fff;border-radius:7px;cursor:pointer;font-weight:700;font-size:13px">⭕ 2</button>
            <button onclick="rtQuickAddTable('round4')"  title="Kruh pro 4"     style="padding:8px 12px;border:1.5px solid #E5E7EB;background:#fff;border-radius:7px;cursor:pointer;font-weight:700;font-size:13px">⭕ 4</button>
            <button onclick="rtQuickAddTable('rect6')"   title="Obdélník pro 6" style="padding:8px 12px;border:1.5px solid #E5E7EB;background:#fff;border-radius:7px;cursor:pointer;font-weight:700;font-size:13px">▭ 6</button>
            <button onclick="rtQuickAddTable('rect8')"   title="Obdélník pro 8" style="padding:8px 12px;border:1.5px solid #E5E7EB;background:#fff;border-radius:7px;cursor:pointer;font-weight:700;font-size:13px">▭ 8</button>
            <button onclick="rtQuickAddTable('bar')"     title="Barpult (4)"    style="padding:8px 12px;border:1.5px solid #FBBF24;background:#FFFBEB;color:#92400E;border-radius:7px;cursor:pointer;font-weight:700;font-size:13px">🍺 Bar</button>
            <button onclick="rtQuickAddTable('lounge')"  title="Salonek (8)"    style="padding:8px 12px;border:1.5px solid #C4B5FD;background:#F5F3FF;color:#5B21B6;border-radius:7px;cursor:pointer;font-weight:700;font-size:13px">🛋️ Salonek</button>
            <div style="flex-basis:100%;font-size:10px;color:var(--text-3);margin-top:4px;text-align:center">💡 nebo <strong>dvojklik</strong> do prázdna v mapě</div>
          </div>
        </details>
      ` : ''}
      <!-- 🎨 v3.0.24 — Stats jako kompaktní pill badges -->
      <div style="display:flex;gap:6px;align-items:center;flex-wrap:wrap">
        <span style="background:#DCFCE7;color:#15803D;font-size:12px;font-weight:800;padding:4px 10px;border-radius:99px;border:1px solid #86EFAC">${stateStats.free} 🟢</span>
        ${stateStats.reserved ? `<span style="background:#FEF3C7;color:#92400E;font-size:12px;font-weight:800;padding:4px 10px;border-radius:99px;border:1px solid #FCD34D">${stateStats.reserved} 🟡</span>` : ''}
        ${stateStats.occupied ? `<span style="background:#FED7AA;color:#9A3412;font-size:12px;font-weight:800;padding:4px 10px;border-radius:99px;border:1px solid #FB923C">${stateStats.occupied} 🟠</span>` : ''}
        ${stateStats.cleaning ? `<span style="background:#F3F4F6;color:#374151;font-size:12px;font-weight:800;padding:4px 10px;border-radius:99px;border:1px solid #D1D5DB">${stateStats.cleaning} ⚪</span>` : ''}
        ${stateStats.attention ? `<span style="background:#F3E8FF;color:#6B21A8;font-size:12px;font-weight:800;padding:4px 10px;border-radius:99px;border:1px solid #C4B5FD">${stateStats.attention} 🟣</span>` : ''}
      </div>
    </div>

    <!-- 🆕 v3.0.43 — Zone tabs jako mini-bannery (sjednoceno se sub-tabs designem) -->
    <div class="rt-zone-tabs" style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;margin-bottom:12px;overflow-x:auto">
      ${zones.map((z, idx) => {
        const isActive = z.id == activeZone.id;
        // 🆕 v3.0.136 — první zóna počítá i osiřelé stoly (konzistence s canvasem)
        const count = stoly.filter(s => s.zone_id == z.id).length
          + (z.id == firstZoneId ? stoly.filter(isOrphanTable).length : 0);
        // Per-index color palette (cycle through accent colors)
        const PALETTE = [
          { grad:'135deg,#10B981,#065F46', light:'#D1FAE5', dark:'#064E3B' }, // green
          { grad:'135deg,#3B82F6,#1E40AF', light:'#DBEAFE', dark:'#1E3A8A' }, // blue
          { grad:'135deg,#A78BFA,#5B21B6', light:'#EDE9FE', dark:'#4C1D95' }, // purple
          { grad:'135deg,#F97316,#9A3412', light:'#FFEDD5', dark:'#7C2D12' }, // orange
          { grad:'135deg,#EC4899,#9D174D', light:'#FCE7F3', dark:'#9D174D' }, // pink
        ];
        const c = PALETTE[idx % PALETTE.length];
        return `
        <button class="rt-zone-tab ${isActive ? 'is-active' : ''}"
                onclick="rtSwitchZone(${z.id === null ? 'null' : z.id})"
                style="${isActive
                  ? `background:linear-gradient(${c.grad});color:#fff;border:2px solid transparent;box-shadow:0 4px 14px ${c.light}90,0 1px 4px rgba(0,0,0,0.12)`
                  : `background:${c.light};color:${c.dark};border:2px solid ${c.light}`
                }">
          <span class="ztb-icon" style="font-size:18px;line-height:1;${isActive ? 'filter:drop-shadow(0 1px 2px rgba(0,0,0,0.2))' : ''}">${esc(z.ikona || '🍽️')}</span>
          <span class="ztb-name" style="font-weight:800;font-size:14px;letter-spacing:-0.01em">${esc(z.nazev)}</span>
          <span class="ztb-count" style="font-size:11px;font-weight:700;background:${isActive ? 'rgba(255,255,255,0.25)' : 'rgba(0,0,0,0.06)'};padding:2px 8px;border-radius:99px;line-height:1">${count}</span>
        </button>
        `;
      }).join('')}
      ${editMode ? `
        <button onclick="rtAddZone()" class="rt-zone-add" title="Přidat novou zónu">
          <span style="font-size:18px;line-height:1">＋</span>
          <span>Zóna</span>
        </button>
      ` : ''}
    </div>

    <!-- Canvas — v3.0.84 auto-fit wrapper (aspect-ratio + single-calc scale).
         CSS vars inline → admin.css .rt-canvas-wrap používá k aspect-ratio + scale calc. -->
    <div class="rt-canvas-wrap"
         style="--canvas-w-num:${activeZone.canvas_w};--canvas-h-num:${activeZone.canvas_h};--canvas-w-px:${activeZone.canvas_w}px">
      <div id="rt-canvas"
           class="rt-canvas ${editMode ? 'is-edit' : ''}"
           data-zone-id="${activeZone.id || ''}"
           style="position:relative;width:${activeZone.canvas_w}px;height:${activeZone.canvas_h}px;
                  background:${activeZone.bg_barva || '#FFFAF1'};
                  background-image:radial-gradient(circle, rgba(0,0,0,0.07) 1px, transparent 1px);
                  background-size:20px 20px;
                  user-select:none;
                  ${editMode ? 'cursor:crosshair' : ''}"
           ${editMode ? 'ondblclick="rtAddTableAtClick(event)"' : ''}>
        ${stolyInZone.map(t => renderTableTile(t, editMode)).join('')}
      </div>
    </div>

    <!-- 🆕 v3.0.26 — Velký Floor designer button dole pod canvasem -->
    <div style="margin-top:14px;display:flex;gap:10px;align-items:center;justify-content:center;flex-wrap:wrap">
      <button onclick="window.openFloorplanWindow?.()" style="display:inline-flex;align-items:center;gap:10px;padding:14px 24px;background:linear-gradient(135deg,#3B82F6,#1E40AF);color:#fff;border:none;border-radius:12px;font-size:14px;font-weight:800;cursor:pointer;box-shadow:0 4px 14px rgba(59,130,246,0.3);transition:all 0.18s ease" onmouseover="this.style.transform='translateY(-2px)';this.style.boxShadow='0 6px 18px rgba(59,130,246,0.4)'" onmouseout="this.style.transform='';this.style.boxShadow='0 4px 14px rgba(59,130,246,0.3)'">
        <span style="font-size:22px">🗺️</span>
        <span>Otevřít plnotučný Floor Designer</span>
        <span style="font-size:11px;opacity:0.85">v novém okně →</span>
      </button>
      ${editMode ? `
        <button onclick="rtOpenTemplatePicker()" style="display:inline-flex;align-items:center;gap:8px;padding:13px 18px;background:#FFFBEB;border:1.5px solid #F59E0B;color:#92400E;border-radius:12px;font-size:13px;font-weight:700;cursor:pointer">
          📋 Vybrat z šablon
        </button>
      ` : ''}
    </div>

    <!-- Bottom info -->
    <div style="margin-top:10px;text-align:center;font-size:12px;color:var(--text-3);line-height:1.6">
      ${editMode ? `
        💡 <strong>Editor:</strong> Tahem přesouvej · Dvojklikem do prázdna přidej nový · Klikem uprav · Změny ulož 💾
      ` : `
        💡 <strong>Provoz:</strong> Klikni na stůl → nastav stav, rezervuj, zobraz detail
      `}
    </div>
  `;
}

// 📱 v3.0.393 — Engine-agnostic scale floor-plan canvasu (JS místo CSS `cqi`).
//   Safari (iOS) neškáluje spolehlivě `transform: scale(calc(100cqi / ...))` →
//   canvas zůstal v plné velikosti a přetékal (stůl vpravo uříznutý). Tady scale
//   spočítáme v JS (šířka wrapu / canvas_w) a nastavíme inline → funguje na všech enginech.
function rtScaleCanvas() {
  document.querySelectorAll('.rt-canvas-wrap').forEach(wrap => {
    const canvas = wrap.querySelector('.rt-canvas');
    if (!canvas) return;
    const cw = parseFloat(wrap.style.getPropertyValue('--canvas-w-num')) || 800;
    const avail = wrap.clientWidth;
    if (avail > 0 && cw > 0) canvas.style.transform = 'scale(' + (avail / cw) + ')';
  });
}
if (typeof window !== 'undefined' && !window._rtScaleResizeBound) {
  window._rtScaleResizeBound = true;
  window.addEventListener('resize', () => requestAnimationFrame(rtScaleCanvas));
}

function renderTableTile(t, editMode) {
  // 🆕 v3.0.40 — Modern 2026 floor plan tile: category-aware colors,
  // soft gradients, inset shadow (carved-in feel), premium typography,
  // optional decorative tile (mist=0 = dekorativní prvek bez židliček).
  const shape = RT_SHAPES[t.tvar || 'square'] || RT_SHAPES.square;
  const w = parseInt(t.width)  || 80;
  const h = parseInt(t.height) || 80;
  const x = parseInt(t.x) || 0;
  const y = parseInt(t.y) || 0;
  const tvar = t.tvar || 'square';
  const isRound = tvar === 'round';
  const mist = parseInt(t.mist) || 0;
  const isDecor = mist === 0;

  // 🎨 v3.0.40 — Kategorizace podle názvu (pro stav=free zvolíme barvu per typ)
  const nazev = t.nazev || '';
  let category = 'standard';
  // 🐛 v3.0.44 — Pořadí důležité (first match wins). 🍻 dříve duplicitní v bar+family.
  if (/🥩|Grill|Pec|Teppanyaki|🔥/.test(nazev))                     category = 'grill';
  else if (/💃|🎵|🎤|🎧|Parket|DJ|Pódium|Fire pit|💍/.test(nazev))  category = 'stage';
  else if (/🍕 Rodi|Komunit|Společn|Dlouhý|Tapas/.test(nazev))      category = 'family';
  else if (/🛋️|🥃|VIP|Lounge|Salon|Tatami|Pergola/.test(nazev))     category = 'lounge';
  else if (/🌳|🌲|🍃|Pikni|Zahrad|🌹|🌷/.test(nazev))                category = 'garden';
  else if (/🍺|🍻|🍷|🍸|🍹|🍾|🥂|Bar|bar|Tap|Pult/.test(nazev))      category = 'bar';

  // Per-stav OVERRIDE (occupied > category default). free → category default
  const STAV_COLORS = {
    occupied:  { bg: 'linear-gradient(140deg,#FED7AA 0%,#FDBA74 60%,#FB923C 100%)', border: '#EA580C', text: '#7C2D12', glow: 'rgba(234,88,12,0.32)', dot: '#EA580C' },
    reserved:  { bg: 'linear-gradient(140deg,#FEF3C7 0%,#FDE68A 60%,#FCD34D 100%)', border: '#F59E0B', text: '#78350F', glow: 'rgba(245,158,11,0.28)', dot: '#F59E0B' },
    cleaning:  { bg: 'linear-gradient(140deg,#F3F4F6 0%,#E5E7EB 100%)',              border: '#9CA3AF', text: '#374151', glow: 'rgba(107,114,128,0.16)', dot: '#6B7280' },
    attention: { bg: 'linear-gradient(140deg,#EDE9FE 0%,#C4B5FD 100%)',              border: '#7C3AED', text: '#4C1D95', glow: 'rgba(124,58,237,0.24)', dot: '#7C3AED' },
    disabled:  { bg: 'linear-gradient(140deg,#1F2937 0%,#111827 100%)',              border: '#000',    text: '#E5E7EB', glow: 'rgba(0,0,0,0.35)',       dot: '#9CA3AF' },
  };
  // 🆕 v3.0.40 — Category palette pro free stav (premium 2026 look)
  const CAT_COLORS = {
    standard: { bg: 'linear-gradient(140deg,#FFFFFF 0%,#F8F4ED 50%,#EFE7D7 100%)', border: '#C9BFAA', text: '#3F2D1A', glow: 'rgba(186,117,23,0.12)', dot: '#BA7517' },
    bar:      { bg: 'linear-gradient(140deg,#FFF7ED 0%,#FED7AA 100%)',              border: '#FB923C', text: '#9A3412', glow: 'rgba(251,146,60,0.22)', dot: '#FB923C' },
    lounge:   { bg: 'linear-gradient(140deg,#F5F3FF 0%,#DDD6FE 100%)',              border: '#A78BFA', text: '#5B21B6', glow: 'rgba(167,139,250,0.22)', dot: '#A78BFA' },
    garden:   { bg: 'linear-gradient(140deg,#ECFDF5 0%,#A7F3D0 100%)',              border: '#34D399', text: '#065F46', glow: 'rgba(52,211,153,0.22)', dot: '#10B981' },
    grill:    { bg: 'linear-gradient(140deg,#FEE2E2 0%,#FCA5A5 100%)',              border: '#EF4444', text: '#7F1D1D', glow: 'rgba(239,68,68,0.24)',   dot: '#EF4444' },
    stage:    { bg: 'linear-gradient(140deg,#DBEAFE 0%,#93C5FD 100%)',              border: '#3B82F6', text: '#1E3A8A', glow: 'rgba(59,130,246,0.22)',  dot: '#3B82F6' },
    family:   { bg: 'linear-gradient(140deg,#FFFBEB 0%,#FCD34D 100%)',              border: '#D97706', text: '#78350F', glow: 'rgba(217,119,6,0.22)',   dot: '#D97706' },
  };
  const sg = STAV_COLORS[t.stav] || CAT_COLORS[category] || CAT_COLORS.standard;
  // 🐛 v3.0.44 fix — Custom t.barva jen pro FREE stav (předtím přepsal occupied/reserved
  // overlay → ztracený stav signál pro waiter z dálky)
  const bg = (!t.stav || t.stav === 'free') ? (t.barva || sg.bg) : sg.bg;

  // Timer for "kolik sedí" — 🎨 v3.0.26 smart truncation pro malé tiles (žádný overflow)
  let timerLabel = '';
  if (t.stav === 'occupied' && t.stav_od) {
    const minutes = Math.max(0, Math.floor((Date.now() - new Date(t.stav_od.replace(' ','T')).getTime()) / 60000));
    let timeStr;
    if (w >= 110) {
      timeStr = minutes < 60 ? minutes + 'm' : Math.floor(minutes/60) + 'h' + String(minutes%60).padStart(2,'0');
    } else if (w >= 75) {
      timeStr = minutes < 60 ? minutes + 'm' : Math.floor(minutes/60) + 'h';
    } else {
      timeStr = minutes < 60 ? minutes + '′' : Math.floor(minutes/60) + 'h';
    }
    const fz = w >= 100 ? '11px' : (w >= 70 ? '9px' : '8px');
    timerLabel = `<div style="font-size:${fz};font-weight:800;color:${sg.text};font-variant-numeric:tabular-nums;background:rgba(255,255,255,0.65);padding:1px 5px;border-radius:99px;line-height:1.2;white-space:nowrap;max-width:90%;overflow:hidden">⏱${timeStr}</div>`;
  }
  // Next rezervace dnes
  let nextRes = '';
  const upcoming = (t.rezervace_dnes || [])[0];
  if (upcoming && t.stav !== 'occupied') {
    nextRes = `<div style="font-size:10px;opacity:0.85;font-weight:700;color:${sg.text}">🕐 ${upcoming.cas_od.slice(0,5)} · ${upcoming.pocet_osob}p</div>`;
  }

  // Status dot v rohu (viditelný i z dálky)
  const statusDot = (t.stav === 'occupied' || t.stav === 'reserved' || t.stav === 'attention') ? `
    <div style="position:absolute;top:6px;right:6px;width:11px;height:11px;border-radius:50%;background:${sg.dot};box-shadow:0 0 0 2px #fff,0 0 8px ${sg.glow};${t.stav === 'occupied' ? 'animation:rt-status-dot-pulse 1.6s ease-in-out infinite' : ''}"></div>
  ` : '';

  // Dynamický font (čitelnější z dálky)
  const nameFont = w >= 140 ? '18px' : (w >= 100 ? '16px' : (w >= 70 ? '14px' : '12px'));

  // 🆕 v3.0.40 — Dekorativní prvek (strom, parket, fontána apod.) = 0 míst → minimální chrome
  if (isDecor && !editMode) {
    return `
      <div class="rt-table-tile rt-decor"
           data-id="${t.id}"
           data-x="${x}" data-y="${y}" data-w="${w}" data-h="${h}"
           data-tvar="${esc(tvar)}"
           data-stav="decor"
           style="position:absolute;left:${x}px;top:${y}px;width:${w}px;height:${h}px;
                  background:linear-gradient(140deg,#A7F3D0 0%,#6EE7B7 50%,#34D399 100%);
                  border:none;
                  border-radius:${shape.borderRadius};
                  display:flex;align-items:center;justify-content:center;
                  font-size:${Math.min(w, h) * 0.55}px;
                  line-height:1;
                  cursor:default;
                  pointer-events:none;
                  opacity:0.92;
                  filter:drop-shadow(0 2px 6px rgba(16,185,129,0.25));">
        <span style="text-shadow:0 2px 4px rgba(0,0,0,0.1)">${esc(t.nazev)}</span>
      </div>
    `;
  }

  // 🆕 v3.0.40 — Modern 2026 main render: inset highlight + lower-edge shadow,
  // category-aware soft glow, premium typography
  return `
    <div class="rt-table-tile rt-tile-modern ${t.stav === 'occupied' ? 'is-pulsing' : ''} ${isRound ? 'rt-tile-round' : 'rt-tile-rect'} rt-cat-${category}"
         data-id="${t.id}"
         data-x="${x}"
         data-y="${y}"
         data-w="${w}"
         data-h="${h}"
         data-tvar="${esc(tvar)}"
         data-stav="${esc(t.stav || 'free')}"
         style="position:absolute;left:${x}px;top:${y}px;width:${w}px;height:${h}px;
                background:${bg};
                border:1.5px solid ${sg.border}40;
                border-radius:${shape.borderRadius};
                box-shadow: 0 6px 18px ${sg.glow},
                            0 1px 0 rgba(255,255,255,0.7) inset,
                            0 -4px 8px rgba(0,0,0,0.06) inset;
                display:flex;flex-direction:column;align-items:center;justify-content:center;
                gap:3px;padding:8px;
                cursor:${editMode ? 'grab' : 'pointer'};
                transition:transform 0.16s cubic-bezier(.2,.8,.2,1), box-shadow 0.22s ease;
                ${rtState.selectedTableId === t.id ? 'box-shadow:0 0 0 3px #BA7517, 0 8px 22px rgba(186,117,23,0.40), 0 1px 0 rgba(255,255,255,0.7) inset' : ''}
                ${rtState.dirtyTables.has(t.id) ? 'outline:2px dashed #F59E0B;outline-offset:3px' : ''}"
         onclick="rtTableClick(event, ${t.id})">
      ${statusDot}
      <div style="font-weight:800;font-size:${nameFont};color:${sg.text};line-height:1.05;text-align:center;word-break:break-word;letter-spacing:-0.02em;text-shadow:0 1px 0 rgba(255,255,255,0.55);max-width:100%">${esc(t.nazev)}</div>
      ${mist > 0 ? `<div style="font-size:${w >= 100 ? '11.5px' : (w >= 70 ? '10px' : '9px')};color:${sg.text};opacity:0.82;font-weight:700;display:flex;align-items:center;gap:3px;background:rgba(255,255,255,0.4);padding:1px 7px;border-radius:99px;backdrop-filter:blur(2px);-webkit-backdrop-filter:blur(2px)">👥 ${mist}</div>` : ''}
      ${timerLabel}
      ${nextRes}
      ${editMode ? `<div class="rt-tile-del" style="position:absolute;top:-9px;right:-9px;background:#fff;border:2px solid #DC2626;color:#DC2626;border-radius:50%;width:22px;height:22px;display:flex;align-items:center;justify-content:center;font-size:11px;font-weight:800;cursor:pointer;box-shadow:0 2px 4px rgba(0,0,0,0.15);opacity:0;transition:opacity 0.15s ease" onclick="event.stopPropagation();rtDeleteTable(${t.id})" title="Smazat stůl">✕</div>` : ''}
    </div>
  `;
}

