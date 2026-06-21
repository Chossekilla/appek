// =============================================================
// 🎯 ACTIONS
// =============================================================
window.rtToggleEditMode = async function() {
  if (rtState.editMode && rtState.dirtyTables.size > 0) {
    if (!(await confirmDialog({ msg: 'Máš neuložené změny. Zahodit?', danger: true }))) return;
    rtState.dirtyTables.clear();
  }
  rtState.editMode = !rtState.editMode;
  renderRestaurantTables();
};

window.rtSwitchZone = function(zoneId) {
  rtState.activeZoneId = zoneId;
  renderRestaurantTables();
};

window.rtTableClick = function(event, id) {
  event.stopPropagation();
  if (rtState.editMode) {
    rtState.selectedTableId = id;
    rtOpenTableProps(id);
  } else {
    rtOpenTableActions(id);
  }
};

// 🆕 v3.0.17 — Smart naming: pokračuje v sekvenci v dané zóně
// Detekuje prefix (S / T / Stůl / B...) a najde max číslo, dá +1
function rtNextTableName(zoneId, fallbackPrefix = 'S') {
  const tables = (state._rtData?.stoly || []).filter(t => String(t.zone_id || '') === String(zoneId || ''));
  if (tables.length === 0) return fallbackPrefix + '1';

  // Najdi všechny prefixy + čísla
  const re = /^([A-Za-zÁ-ž]+?)\s*(\d+)$/;
  const groups = {};
  let maxAll = 0;
  let mostUsedPrefix = null;
  let mostUsedCount = 0;
  for (const t of tables) {
    const m = (t.nazev || '').match(re);
    if (!m) continue;
    const prefix = m[1].trim();
    const num = parseInt(m[2]);
    groups[prefix] = groups[prefix] || { count: 0, max: 0 };
    groups[prefix].count++;
    if (num > groups[prefix].max) groups[prefix].max = num;
    if (groups[prefix].count > mostUsedCount) {
      mostUsedCount = groups[prefix].count;
      mostUsedPrefix = prefix;
    }
    if (num > maxAll) maxAll = num;
  }
  if (mostUsedPrefix) {
    return mostUsedPrefix + (groups[mostUsedPrefix].max + 1);
  }
  // Žádná matchnutá konvence → fallback
  return fallbackPrefix + (tables.length + 1);
}

// 🆕 v3.0.17 — Najdi volné místo na canvasu (žádný overlap)
function rtFindFreeSpot(zoneId, w = 80, h = 80, prefX = null, prefY = null) {
  const zone = (state._rtData?.zones || []).find(z => String(z.id) === String(zoneId)) || { canvas_w: 800, canvas_h: 500 };
  const others = (state._rtData?.stoly || []).filter(t => String(t.zone_id || '') === String(zoneId || ''));
  const overlaps = (x, y) => others.some(o => {
    const ox = parseInt(o.x) || 0, oy = parseInt(o.y) || 0;
    const ow = parseInt(o.width) || 80, oh = parseInt(o.height) || 80;
    return !(x + w + 10 < ox || x > ox + ow + 10 || y + h + 10 < oy || y > oy + oh + 10);
  });
  // Try preferred pos first
  if (prefX !== null && prefY !== null) {
    const px = Math.max(0, Math.min(zone.canvas_w - w, prefX));
    const py = Math.max(0, Math.min(zone.canvas_h - h, prefY));
    if (!overlaps(px, py)) return { x: px, y: py };
  }
  // Grid scan
  for (let y = 20; y < zone.canvas_h - h; y += 20) {
    for (let x = 20; x < zone.canvas_w - w; x += 20) {
      if (!overlaps(x, y)) return { x, y };
    }
  }
  // Fallback: random offset to avoid stacking
  return { x: 20 + Math.floor(Math.random() * 60), y: 20 + Math.floor(Math.random() * 60) };
}

window.rtAddTableAtClick = async function(event) {
  if (!rtState.editMode) return;
  const canvas = document.getElementById('rt-canvas');
  const rect = canvas.getBoundingClientRect();
  const rawX = Math.round((event.clientX - rect.left - 40) / 20) * 20;
  const rawY = Math.round((event.clientY - rect.top - 40) / 20) * 20;
  const zoneId = rtState.activeZoneId;
  const { x, y } = rtFindFreeSpot(zoneId, 80, 80, rawX, rawY);
  try {
    const r = await api('admin_tables.php', {
      method: 'POST',
      body: JSON.stringify({
        nazev: rtNextTableName(zoneId, 'S'),
        mist: 4, tvar: 'square', width: 80, height: 80,
        x, y, zone_id: zoneId,
      }),
    });
    if (r && r.ok) renderRestaurantTables();
  } catch (e) { alert('Chyba: ' + e.message); }
};

// 🆕 v3.0.17 — Quick-add preset stolu (z toolbaru)
window.rtQuickAddTable = async function(preset) {
  if (!rtState.editMode) return;
  const presets = {
    square2: { mist: 2, tvar: 'square', width: 60, height: 60, prefix: 'S' },
    square4: { mist: 4, tvar: 'square', width: 80, height: 80, prefix: 'S' },
    square6: { mist: 6, tvar: 'rect',   width: 140, height: 80, prefix: 'S' },
    round2:  { mist: 2, tvar: 'round',  width: 70, height: 70, prefix: 'S' },
    round4:  { mist: 4, tvar: 'round',  width: 90, height: 90, prefix: 'S' },
    rect6:   { mist: 6, tvar: 'rect',   width: 160, height: 70, prefix: 'S' },
    rect8:   { mist: 8, tvar: 'rect',   width: 200, height: 80, prefix: 'S' },
    bar:     { mist: 4, tvar: 'rect',   width: 200, height: 60, prefix: 'B' },
    lounge:  { mist: 8, tvar: 'rect',   width: 220, height: 100, prefix: 'L' },
  };
  const p = presets[preset];
  if (!p) return;
  const zoneId = rtState.activeZoneId;
  const { x, y } = rtFindFreeSpot(zoneId, p.width, p.height);
  try {
    const r = await api('admin_tables.php', {
      method: 'POST',
      body: JSON.stringify({
        nazev: rtNextTableName(zoneId, p.prefix),
        mist: p.mist, tvar: p.tvar, width: p.width, height: p.height,
        x, y, zone_id: zoneId,
      }),
    });
    if (r && r.ok) renderRestaurantTables();
  } catch (e) { alert('Chyba: ' + e.message); }
};

window.rtDeleteTable = async function(id) {
  if (!(await confirmDialog({ msg: 'Smazat tento stůl?', danger: true }))) return;
  try {
    await api('admin_tables.php?id=' + id, { method: 'DELETE' });
    rtState.dirtyTables.delete(id);
    renderRestaurantTables();
  } catch (e) { alert('Chyba: ' + e.message); }
};

window.rtSaveLayout = async function() {
  const tables = (state._rtData?.stoly || []).filter(t => rtState.dirtyTables.has(t.id));
  if (tables.length === 0) return;
  try {
    const r = await api('admin_tables.php?action=save_layout', {
      method: 'POST',
      body: JSON.stringify({ tables: tables.map(t => ({
        id: t.id, x: t.x, y: t.y, width: t.width, height: t.height, zone_id: t.zone_id, rotace: t.rotace || 0,
      })) }),
    });
    if (r && r.ok) {
      rtState.dirtyTables.clear();
      toastSuccess(t('toast_saved_tables', { n: r.updated }));
      renderRestaurantTables();
    }
  } catch (e) { alert('Chyba při ukládání: ' + e.message); }
};

window.rtOpenTableActions = function(id) {
  const t = (state._rtData?.stoly || []).find(s => s.id === id);
  if (!t) return;
  const st = RT_STATES[t.stav || 'free'];

  // 🆕 v2.3 — Pokud je stůl occupied, rovnou otevři POS účet (nejvyšší priorita)
  if (t.stav === 'occupied') {
    posOpenUcet(id);
    return;
  }

  // 🎨 v3.0.33 — Redesign: bigger touch targets, vyšší řádky, lepší rozložení
  const tvarLabel = { round: '🔵 Kruh', square: '🟧 Čtverec', rect: '▭ Obdélník' }[t.tvar] || t.tvar;
  openModal(`🪑 ${t.nazev} — ${st.label}`, `
    <div style="display:flex;flex-direction:column;gap:14px;font-size:14px">

      <!-- Info hlavička - bigger -->
      <div style="display:flex;justify-content:space-between;align-items:center;background:linear-gradient(135deg,#F9FAFB,#F3F4F6);padding:12px 16px;border-radius:10px;border:1px solid #E5E7EB">
        <div style="display:flex;flex-direction:column;gap:2px">
          <span style="font-size:15px;font-weight:700;color:#1F2937">👥 ${t.mist} míst</span>
          <span style="font-size:12px;color:#6B7280">${tvarLabel}</span>
        </div>
        <div style="text-align:right">
          <div style="font-size:11px;color:#6B7280;text-transform:uppercase;letter-spacing:0.06em;font-weight:700">Dnes</div>
          <div style="font-size:18px;font-weight:800;color:#1F2937">${t.obsazenost_dnes || 0} <span style="font-size:12px;font-weight:500;color:#6B7280">rezerv.</span></div>
        </div>
      </div>

      <!-- Hlavní akce — velké POS tlačítko -->
      <button class="btn-primary btn-green" onclick="closeModal();setTimeout(()=>posOpenUcet(${t.id}), 50)"
              style="padding:18px;font-size:17px;font-weight:800;background:linear-gradient(135deg,#10B981,#059669);border-radius:12px;box-shadow:0 4px 14px rgba(16,185,129,0.3);transition:all 0.15s ease;display:flex;align-items:center;justify-content:center;gap:10px"
              onmouseover="this.style.transform='translateY(-2px)';this.style.boxShadow='0 6px 20px rgba(16,185,129,0.4)'"
              onmouseout="this.style.transform='';this.style.boxShadow='0 4px 14px rgba(16,185,129,0.3)'">
        <span style="font-size:22px">🧾</span> Otevřít účet (POS)
      </button>

      <!-- ZMĚNIT STAV - bigger buttons, jedna řada na desktop -->
      <div>
        <h4 style="margin:0 0 8px;font-size:12px;color:#6B7280;text-transform:uppercase;letter-spacing:0.08em;font-weight:800">Změnit stav stolu</h4>
        <div style="display:grid;grid-template-columns:repeat(auto-fit, minmax(120px, 1fr));gap:8px">
          ${Object.entries(RT_STATES).filter(([k]) => k !== 'disabled').map(([k, s]) => `
            <button onclick="rtSetState(${t.id}, '${k}')"
                    style="padding:14px 10px;border-radius:10px;border:2px solid ${t.stav === k ? s.border : '#E5E7EB'};background:${t.stav === k ? s.bg : '#fff'};color:${s.text};font-weight:700;font-size:13px;cursor:pointer;transition:all 0.15s ease;display:flex;flex-direction:column;align-items:center;gap:4px;min-height:54px;justify-content:center"
                    onmouseover="this.style.borderColor='${s.border}';this.style.background='${s.bg}'"
                    onmouseout="${t.stav !== k ? `this.style.borderColor='#E5E7EB';this.style.background='#fff'` : ''}">
              <span style="font-size:16px">${s.label.split(' ')[0]}</span>
              <span>${s.label.split(' ').slice(1).join(' ')}</span>
            </button>
          `).join('')}
        </div>
      </div>

      <!-- REZERVACE - bigger rows -->
      <div>
        <h4 style="margin:0 0 8px;font-size:12px;color:#6B7280;text-transform:uppercase;letter-spacing:0.08em;font-weight:800">📅 Rezervace dnes</h4>
        ${(t.rezervace_dnes || []).length > 0 ? `
          <div style="display:flex;flex-direction:column;gap:8px;max-height:240px;overflow-y:auto">
            ${t.rezervace_dnes.map(r => `
              <div style="background:linear-gradient(135deg,#FFFBEB,#FEF3C7);border:1.5px solid #FCD34D;padding:12px 14px;border-radius:10px;display:flex;justify-content:space-between;align-items:center;gap:10px">
                <div style="flex:1">
                  <div style="font-weight:800;font-size:14px;color:#78350F;font-variant-numeric:tabular-nums">${r.cas_od.slice(0,5)} – ${r.cas_do.slice(0,5)}</div>
                  <div style="font-size:13px;color:#92400E;margin-top:2px">👤 ${esc(r.jmeno)} · ${r.pocet_osob}p ${r.poznamka ? '· ' + esc(r.poznamka) : ''}</div>
                </div>
                ${r.telefon ? `<a href="tel:${esc(r.telefon)}" style="font-size:12px;font-weight:700;color:#78350F;text-decoration:none;background:rgba(255,255,255,0.6);padding:6px 12px;border-radius:8px;white-space:nowrap">📞 ${esc(r.telefon)}</a>` : ''}
              </div>
            `).join('')}
          </div>
        ` : '<div style="color:#9CA3AF;font-size:13px;font-style:italic;padding:14px;background:#F9FAFB;border-radius:10px;text-align:center">Dnes žádná rezervace</div>'}
      </div>

      <!-- AKCE - footer buttons (bigger, equally spaced) -->
      <div style="display:grid;grid-template-columns:1fr auto auto;gap:8px;padding-top:14px;border-top:2px solid #E5E7EB">
        <button class="btn-primary" onclick="closeModal();setTimeout(()=>rezervovatStul(${t.id}, '${esc(t.nazev)}'), 50)"
                style="padding:14px;font-size:14px;font-weight:700;border-radius:10px;display:flex;align-items:center;justify-content:center;gap:8px">
          <span style="font-size:18px">📅</span> Nová rezervace
        </button>
        <button class="btn-secondary" onclick="closeModal();setTimeout(()=>editRestaurantTable(${t.id}), 50)"
                style="padding:14px 16px;font-size:13px;font-weight:700;border-radius:10px;min-width:54px;display:flex;align-items:center;justify-content:center;gap:6px" title="Vlastnosti stolu">
          <span style="font-size:18px">✏️</span> <span class="rt-modal-act-lbl">Vlastnosti</span>
        </button>
        <button class="btn-secondary" onclick="closeModal();setTimeout(()=>posShowQR(${t.id}, '${esc(t.nazev)}'), 50)"
                style="padding:14px 16px;font-size:13px;font-weight:700;border-radius:10px;min-width:54px;display:flex;align-items:center;justify-content:center;gap:6px" title="QR kód pro self-order">
          <span style="font-size:18px">📲</span> <span class="rt-modal-act-lbl">QR</span>
        </button>
      </div>
    </div>
  `);
};

