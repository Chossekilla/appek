// =============================================================
// 🗺️ ZONE MANAGER
// =============================================================
window.rtOpenZoneManager = function() {
  const zones = state._rtData?.zones || [];
  openModal('🗺️ Správa zón', `
    <p style="color:var(--text-3);font-size:13px;margin-bottom:14px">
      Zóny jsou samostatná plátna (sál, terasa, bar, salon). Stoly se přiřazují k zóně.
    </p>
    <div style="display:flex;flex-direction:column;gap:8px;margin-bottom:14px">
      ${zones.map(z => `
        <div style="display:flex;align-items:center;gap:8px;padding:10px;background:var(--surface-2);border-radius:8px">
          <input type="text" id="rt-zone-name-${z.id}" value="${esc(z.nazev)}" style="flex:1;padding:6px 10px;border:1px solid var(--border);border-radius:6px;font-size:13px">
          <input type="text" id="rt-zone-icon-${z.id}" value="${esc(z.ikona)}" maxlength="2" style="width:50px;padding:6px;border:1px solid var(--border);border-radius:6px;font-size:18px;text-align:center">
          <input type="number" id="rt-zone-w-${z.id}" value="${z.canvas_w}" style="width:70px;padding:6px;border:1px solid var(--border);border-radius:6px;font-size:12px" title="Šířka">
          <input type="number" id="rt-zone-h-${z.id}" value="${z.canvas_h}" style="width:70px;padding:6px;border:1px solid var(--border);border-radius:6px;font-size:12px" title="Výška">
          <button class="btn-secondary" style="padding:6px 10px" onclick="rtSaveZone(${z.id})">💾</button>
          ${zones.length > 1 ? `<button class="btn-secondary" style="padding:6px 10px;background:#fde7e9;color:#a8232f;border-color:#fde7e9" onclick="rtDeleteZone(${z.id})">🗑️</button>` : ''}
        </div>
      `).join('')}
    </div>
    <button class="btn-primary" onclick="rtAddZone()" style="width:100%">＋ Přidat novou zónu</button>
  `);
};

window.rtSaveZone = async function(id) {
  const data = {
    id,
    nazev: document.getElementById('rt-zone-name-' + id).value.trim(),
    ikona: document.getElementById('rt-zone-icon-' + id).value.trim() || '🍽️',
    canvas_w: parseInt(document.getElementById('rt-zone-w-' + id).value) || 800,
    canvas_h: parseInt(document.getElementById('rt-zone-h-' + id).value) || 500,
  };
  try {
    await api('admin_tables.php?action=zone_save', { method: 'POST', body: JSON.stringify(data) });
    toastSuccess('Zóna uložena.');
    renderRestaurantTables();
    setTimeout(rtOpenZoneManager, 100);
  } catch (e) { alert('Chyba: ' + e.message); }
};

window.rtAddZone = async function() {
  const nazev = (await promptDialog({ msg: 'Název nové zóny:', value: 'Terasa' }));
  if (!nazev) return;
  try {
    const r = await api('admin_tables.php?action=zone_save', {
      method: 'POST',
      body: JSON.stringify({ nazev, ikona: '☀️', canvas_w: 600, canvas_h: 400, sort_order: 99 }),
    });
    if (r && r.ok) {
      rtState.activeZoneId = r.id;
      renderRestaurantTables();
    }
  } catch (e) { alert('Chyba: ' + e.message); }
};

window.rtDeleteZone = async function(id) {
  if (!(await confirmDialog({ msg: 'Smazat zónu? Všechny stoly v ní budou taky smazány!', danger: true }))) return;
  try {
    await api('admin_tables.php?action=zone&id=' + id, { method: 'DELETE' });
    if (rtState.activeZoneId == id) rtState.activeZoneId = null;
    closeModal();
    renderRestaurantTables();
  } catch (e) { alert('Chyba: ' + e.message); }
};

function renderRestaurantTimeline(stoly, datum, openUcty, dayHours) {
  openUcty = openUcty || [];
  // 🆕 v3.0.19 — Live obsazenost: index POS účet podle stul_id
  const uByStul = {};
  openUcty.forEach(u => { uByStul[u.stul_id] = u; });

  // 🆕 v3.0.201 — Zavřený den → info panel místo timeline
  if (dayHours && (dayHours.zavreno == 1 || dayHours.zavreno === true)) {
    return `<div class="card-block" style="padding:42px 20px;text-align:center;color:var(--text-3)">
      <div style="font-size:42px;margin-bottom:10px">🔒</div>
      <div style="font-size:16px;font-weight:700;margin-bottom:4px;color:var(--text-1)">Zavřeno</div>
      <div style="font-size:13px">V tento den máš zavřeno. Otevírací dobu změníš tlačítkem <strong>🕐 Otevírací doba</strong>.</div>
    </div>`;
  }

  // 🆕 v3.0.201 / 🐛 v3.0.222 — Rozsah kalendáře dle otevírací doby daného dne (fallback 10–24).
  //   od → dolů na hodinu, do → nahoru na hodinu. PŘES PŮLNOC (do ≤ od, např. 11–03): pokračuj
  //   do dalšího dne → endH = 24 + do (11–03 → 11..27, sloupce 11:00…23:00, 00:00, 01:00, 02:00).
  let startH = 10, endH = 24, crossMidnight = false;
  if (dayHours && dayHours.otevreno_od && dayHours.otevreno_do) {
    const oh = parseInt(String(dayHours.otevreno_od).slice(0, 2), 10);
    const dh = parseInt(String(dayHours.otevreno_do).slice(0, 2), 10);
    const dm = parseInt(String(dayHours.otevreno_do).slice(3, 5), 10);
    if (!isNaN(oh)) startH = Math.max(0, Math.min(23, oh));
    let rawEnd = !isNaN(dh) ? (dm > 0 ? dh + 1 : dh) : 24;
    if (rawEnd <= startH) { crossMidnight = true; endH = 24 + rawEnd; } // přes půlnoc
    else endH = rawEnd;
    if (endH > 30) endH = 30; // bezpečnostní strop (max +6 h po půlnoci)
    if (endH <= startH) endH = 24;
  }
  // Responzivní šířka hodiny (úzký displej = užší sloupce, ať se víc vejde)
  const hourPx = (typeof window !== 'undefined' && window.innerWidth && window.innerWidth < 640) ? 52 : 70;
  const totalMinutes = (endH - startH) * 60;
  const totalPx = (endH - startH) * hourPx;
  const colsHeader = Array.from({ length: endH - startH }, (_, i) => `
    <div style="width:${hourPx}px;flex-shrink:0;text-align:center;font-size:11px;font-weight:600;color:var(--text-3);padding:6px 0;border-left:1px solid var(--border)">${String((startH + i) % 24).padStart(2, '0')}:00</div>
  `).join('');

  // 🆕 v3.0.19 — Aktuální čas marker pozice
  const now = new Date();
  const nowDateStr = now.toISOString().slice(0, 10);
  const isToday = nowDateStr === datum;
  let nowLeftPx = null;
  if (isToday) {
    let nowH = now.getHours();
    if (crossMidnight && nowH < startH) nowH += 24; // 🐛 v3.0.222 — po půlnoci
    const nowMin = (nowH * 60 + now.getMinutes()) - startH * 60;
    if (nowMin >= 0 && nowMin <= totalMinutes) {
      nowLeftPx = (nowMin / 60) * hourPx;
    }
  }

  const tableLabelW = (typeof window !== 'undefined' && window.innerWidth && window.innerWidth < 640) ? 96 : 130; // 🆕 v3.0.201 responzivní
  const colorForGuest = (name) => {
    const palette = ['#3B82F6', '#10B981', '#F59E0B', '#EF4444', '#8B5CF6', '#EC4899', '#14B8A6', '#F97316'];
    let h = 0;
    for (let i = 0; i < (name || '').length; i++) h = (h + name.charCodeAt(i) * 31) % palette.length;
    return palette[h];
  };

  // 🆕 v3.0.246 — filtr zón i v timeline (sdílí state._rtRezZona se Seznamem)
  const _allStoly = stoly;
  const _zones = state._rtZones || [];
  const _activeZona = state._rtRezZona ?? null;
  const _zCount = {};
  _allStoly.forEach(t => { _zCount[t.zone_id] = (_zCount[t.zone_id] || 0) + 1; });
  if (_activeZona !== null) stoly = _allStoly.filter(t => t.zone_id === _activeZona);
  const _chip = (label, val, count) => `<button class="${_activeZona === val ? 'btn-primary' : 'btn-secondary'}" onclick="state._rtRezZona=${val === null ? 'null' : val};renderRestaurantTables()" style="padding:6px 14px;font-size:12.5px;border-radius:999px;border:none">${label}${count != null ? ` <span style="opacity:.75">(${count})</span>` : ''}</button>`;
  const zoneChips = _zones.length > 1 ? `<div style="display:flex;gap:6px;flex-wrap:wrap;align-items:center;margin-bottom:12px"><span style="font-size:11px;text-transform:uppercase;letter-spacing:.5px;color:var(--text-3);font-weight:600;margin-right:2px">🗺️ Zóna:</span>${_chip('Vše', null, _allStoly.length)}${_zones.filter(z => _zCount[z.id]).map(z => _chip(`${z.ikona || ''} ${esc(z.nazev)}`.trim(), z.id, _zCount[z.id])).join('')}</div>` : '';

  const rows = stoly.map(t => {
    const ucet = uByStul[t.id];
    // Rezervace blocks
    const blocks = (t.rezervace_dnes || []).filter(r => r.datum === datum || !r.datum).map(r => {
      let [oh, om] = (r.cas_od || '00:00').split(':').map(Number);
      let [dh, dm] = (r.cas_do || '00:00').split(':').map(Number);
      // 🐛 v3.0.222 — přes půlnoc: časy < otevírací hodina patří do dalšího dne (+24 h)
      if (crossMidnight) { if (oh < startH) oh += 24; if (dh < startH) dh += 24; }
      const fromMin = Math.max(0, (oh * 60 + om) - startH * 60);
      const toMin = Math.min(totalMinutes, (dh * 60 + dm) - startH * 60);
      if (toMin <= fromMin) return '';
      const leftPx = (fromMin / 60) * hourPx;
      const widthPx = ((toMin - fromMin) / 60) * hourPx;
      const c = colorForGuest(r.jmeno || '');
      return `
        <div title="${esc(r.jmeno)} · ${r.cas_od.slice(0,5)}–${r.cas_do.slice(0,5)} · ${r.pocet_osob}p${r.poznamka ? '\n' + esc(r.poznamka) : ''}"
             style="position:absolute;top:6px;height:30px;left:${leftPx}px;width:${widthPx}px;background:${c};color:white;border-radius:7px;padding:3px 8px;font-size:11px;font-weight:600;overflow:hidden;display:flex;align-items:center;gap:4px;cursor:pointer;box-shadow:0 1px 3px rgba(0,0,0,0.2);z-index:2"
             onclick="rezervaceClick(${r.id || 0})">
          <span style="white-space:nowrap;overflow:hidden;text-overflow:ellipsis">${esc(r.jmeno)}</span>
          <span style="font-size:10px;opacity:0.85;flex-shrink:0">${r.pocet_osob}p</span>
        </div>
      `;
    }).join('');

    // 🆕 v3.0.19 — Live POS účet jako blok (od otevreno_v do TEĎ)
    let liveBlock = '';
    if (ucet && isToday) {
      const dt = new Date(String(ucet.otevreno_v).replace(' ', 'T'));
      const fromMin = Math.max(0, (dt.getHours() * 60 + dt.getMinutes()) - startH * 60);
      const toMin = Math.min(totalMinutes, (now.getHours() * 60 + now.getMinutes()) - startH * 60);
      if (toMin > fromMin) {
        const leftPx = (fromMin / 60) * hourPx;
        const widthPx = Math.max(20, ((toMin - fromMin) / 60) * hourPx);
        const sum = parseFloat(ucet.castka_celkem || 0);
        const pcs = parseInt(ucet.pocet_polozek || 0);
        const min = Math.max(0, Math.floor((Date.now() - dt.getTime()) / 60000));
        liveBlock = `
          <div title="🟡 LIVE · ${esc(ucet.otevrel_jmeno || '?')} · od ${String(dt.getHours()).padStart(2,'0')}:${String(dt.getMinutes()).padStart(2,'0')} · ${pcs} pol · ${sum.toFixed(0)} Kč"
               style="position:absolute;bottom:4px;height:10px;left:${leftPx}px;width:${widthPx}px;background:repeating-linear-gradient(45deg,#F59E0B,#F59E0B 6px,#FBBF24 6px,#FBBF24 12px);border-radius:5px;box-shadow:0 1px 2px rgba(0,0,0,0.15);z-index:1">
          </div>
          <div style="position:absolute;bottom:18px;left:${leftPx + 4}px;font-size:10px;font-weight:700;color:#92400E;text-shadow:0 1px 0 rgba(255,255,255,0.7);pointer-events:none;z-index:1">
            🟡 ${min}m · ${sum.toFixed(0)}Kč
          </div>
        `;
      }
    }

    // Label highlight pokud obsazený
    const labelBg = ucet ? 'linear-gradient(135deg,#FEF3C7,#FDE68A)' : 'var(--surface-2)';
    const labelExtra = ucet
      ? `<span style="font-size:9px;font-weight:700;color:#B45309">🟡 LIVE · ${parseInt(ucet.pocet_polozek || 0)}p</span>`
      : '';

    return `
      <div style="display:flex;align-items:center;border-bottom:1px solid var(--border);min-height:50px">
        <div style="width:${tableLabelW}px;flex-shrink:0;padding:8px 10px;background:${labelBg};font-weight:600;font-size:13px;display:flex;flex-direction:column;gap:2px">
          <span>${esc(t.nazev)}</span>
          <span style="font-size:10px;font-weight:400;color:var(--text-3)">${t.mist}p · ${esc(t.sekce || '—')}</span>
          ${labelExtra}
        </div>
        <div style="position:relative;width:${totalPx}px;flex-shrink:0;background:repeating-linear-gradient(to right, transparent 0, transparent ${hourPx - 1}px, var(--border) ${hourPx - 1}px, var(--border) ${hourPx}px);min-height:48px"
             onclick="timelineCellClick(${t.id}, '${esc(t.nazev)}', event, ${startH}, ${hourPx})">
          ${blocks}
          ${liveBlock}
        </div>
      </div>
    `;
  }).join('');

  // Now-line overlay (vertikální čára přes všechny řádky)
  const nowLine = nowLeftPx !== null ? `
    <div style="position:absolute;left:${tableLabelW + nowLeftPx}px;top:32px;bottom:0;width:2px;background:#DC2626;z-index:10;pointer-events:none;box-shadow:0 0 4px rgba(220,38,38,0.5)">
      <div style="position:absolute;top:-4px;left:-5px;width:12px;height:12px;background:#DC2626;border-radius:50%;border:2px solid #fff;box-shadow:0 0 6px rgba(220,38,38,0.6)"></div>
      <div style="position:absolute;top:-22px;left:-26px;background:#DC2626;color:#fff;font-size:10px;font-weight:800;padding:2px 8px;border-radius:4px;white-space:nowrap;font-variant-numeric:tabular-nums">${String(now.getHours()).padStart(2,'0')}:${String(now.getMinutes()).padStart(2,'0')}</div>
    </div>
  ` : '';

  return `
    ${zoneChips}
    <div class="card-block" style="padding:0;overflow:auto;max-width:100%;position:relative">
      <div style="display:flex;align-items:center;border-bottom:2px solid var(--border);background:var(--surface);position:sticky;top:0;z-index:5">
        <div style="width:${tableLabelW}px;flex-shrink:0;padding:8px 10px;font-size:11px;font-weight:700;color:var(--text-3);text-transform:uppercase">Stůl</div>
        <div style="display:flex;flex-shrink:0">${colsHeader}</div>
      </div>
      ${rows}
      ${nowLine}
    </div>
    <div style="margin-top:10px;font-size:12px;color:var(--text-3);text-align:center;line-height:1.6">
      💡 Klikni do prázdného slotu pro novou rezervaci · Klikni do bloku pro detail rezervace<br>
      🟡 <strong>Žlutý pruh dole</strong> = otevřený POS účet (live obsazenost) · 🔴 <strong>Červená čára</strong> = aktuální čas
    </div>
  `;
}

function renderRestaurantList(stoly, datum) {
  // 🆕 v3.0.242 — filtr na zóny + proklik řádku do úpravy rezervace
  const zones = state._rtZones || [];
  const zoneById = Object.fromEntries(zones.map(z => [z.id, z]));
  const all = [];
  for (const t of stoly) {
    const zona = zoneById[t.zone_id] || null;
    for (const r of (t.rezervace_dnes || [])) {
      all.push({ ...r, stul_nazev: t.nazev, stul_id: t.id, zone_id: t.zone_id || null, zona_nazev: zona ? zona.nazev : (t.sekce || '—'), zona_ikona: zona ? (zona.ikona || '') : '' });
    }
  }
  all.sort((a, b) => (a.cas_od || '').localeCompare(b.cas_od || ''));

  // Chips: Vše + jednotlivé zóny s počtem rezervací (jen zóny co nějakou mají, ať není mrtvý filtr)
  const aktivniZona = state._rtRezZona ?? null;
  const countByZone = {};
  for (const r of all) countByZone[r.zone_id] = (countByZone[r.zone_id] || 0) + 1;
  const chip = (label, val, count) => `
    <button class="${(aktivniZona === val) ? 'btn-primary' : 'btn-secondary'}"
            onclick="state._rtRezZona=${val === null ? 'null' : val};renderRestaurantTables()"
            style="padding:6px 14px;font-size:12.5px;border-radius:999px;border:none">${label}${count != null ? ` <span style="opacity:0.75">(${count})</span>` : ''}</button>`;
  const zoneChips = `
    <div style="display:flex;gap:6px;flex-wrap:wrap;align-items:center;margin-bottom:12px">
      <span style="font-size:11px;text-transform:uppercase;letter-spacing:.5px;color:var(--text-3);font-weight:600;margin-right:2px">🗺️ Zóna:</span>
      ${chip('Vše', null, all.length)}
      ${zones.filter(z => countByZone[z.id]).map(z => chip(`${z.ikona || ''} ${esc(z.nazev)}`.trim(), z.id, countByZone[z.id])).join('')}
    </div>`;

  const filtered = aktivniZona === null ? all : all.filter(r => r.zone_id === aktivniZona);

  if (all.length === 0) {
    return emptyState({
      icon: '📋', title: 'Žádné rezervace na ' + new Date(datum).toLocaleDateString('cs-CZ'),
      msg: 'Pro tento den nejsou žádné rezervace.',
      actions: '',
    });
  }
  const stavBadge = (s) => ({
    pending:   '<span style="background:#FEF3C7;color:#92400E;border-radius:999px;padding:2px 9px;font-size:11px;font-weight:600">⏳ Čeká</span>',
    confirmed: '<span style="background:#D1FAE5;color:#065F46;border-radius:999px;padding:2px 9px;font-size:11px;font-weight:600">✓ Potvrzená</span>',
    no_show:   '<span style="background:#FEE2E2;color:#991B1B;border-radius:999px;padding:2px 9px;font-size:11px;font-weight:600">👻 Nepřišli</span>',
  })[s] || esc(s || '');
  return zoneChips + `
    <div class="card-block" style="padding:0;overflow:hidden">
      <table style="width:100%;border-collapse:collapse">
        <thead style="background:var(--surface-2)">
          <tr style="font-size:11px;font-weight:700;color:var(--text-3);text-transform:uppercase">
            <th style="text-align:left;padding:10px 14px">Čas</th>
            <th style="text-align:left;padding:10px 14px">Stůl</th>
            <th style="text-align:left;padding:10px 14px">Zóna</th>
            <th style="text-align:left;padding:10px 14px">Jméno</th>
            <th style="text-align:center;padding:10px 14px">Osob</th>
            <th style="text-align:left;padding:10px 14px">Telefon</th>
            <th style="text-align:left;padding:10px 14px">Stav</th>
            <th style="text-align:left;padding:10px 14px">Poznámka</th>
          </tr>
        </thead>
        <tbody>
          ${filtered.length === 0 ? `<tr><td colspan="8" style="padding:24px;text-align:center;color:var(--text-3)">V této zóně nejsou žádné rezervace.</td></tr>` : filtered.map(r => `
            <tr class="row-clickable" onclick="openRezervaceEdit(${r.id})" title="Upravit rezervaci"
                style="border-top:1px solid var(--border);font-size:13px;cursor:pointer">
              <td style="padding:10px 14px;font-weight:600;font-variant-numeric:tabular-nums">${(r.cas_od||'').slice(0,5)} – ${(r.cas_do||'').slice(0,5)}</td>
              <td style="padding:10px 14px">${esc(r.stul_nazev)}</td>
              <td style="padding:10px 14px;color:var(--text-3)">${esc(`${r.zona_ikona} ${r.zona_nazev}`.trim())}</td>
              <td style="padding:10px 14px;font-weight:500">${esc(r.jmeno)} <span style="color:var(--text-3);font-size:11px">✏️</span></td>
              <td style="padding:10px 14px;text-align:center">${r.pocet_osob}</td>
              <td style="padding:10px 14px;color:var(--text-3)">${esc(r.telefon || '—')}</td>
              <td style="padding:10px 14px">${stavBadge(r.stav)}</td>
              <td style="padding:10px 14px;color:var(--text-3);font-size:12px">${esc(r.poznamka || '')}</td>
            </tr>
          `).join('')}
        </tbody>
      </table>
    </div>
  `;
}

window.timelineCellClick = function(stulId, stulNazev, ev, startH, hourPx) {
  // Compute approx start time from click X-offset
  hourPx = hourPx || 70; // 🆕 v3.0.201 — respektuj responzivní šířku hodiny
  const rect = ev.currentTarget.getBoundingClientRect();
  const x = ev.clientX - rect.left;
  const minute = Math.floor((x / hourPx) * 60);
  const h = startH + Math.floor(minute / 60);
  const m = Math.floor((minute % 60) / 15) * 15;
  state._rtPrefillTime = `${String(h).padStart(2, '0')}:${String(m).padStart(2, '0')}`;
  rezervovatStul(stulId, stulNazev);
};

// 🆕 v3.0.242 — proklik na rezervaci (seznam i timeline) → plnohodnotná úprava.
//   Dřív stub (jen toast „Detail rezervace #id") — rezervace nešla vůbec upravit.
window.rezervaceClick = function(id) {
  if (!id) return;
  openRezervaceEdit(id);
};

window.openRezervaceEdit = function(id) {
  const r = (state._rtRezMap || {})[id];
  if (!r) { toastInfo('Rezervace nenalezena — obnov stránku'); return; }
  const zones = state._rtZones || [];
  const zoneById = Object.fromEntries(zones.map(z => [z.id, z]));
  // Stoly groupnuté podle zóny (výběr pro přesun rezervace jinam)
  const stoly = state._rtStolyCache || [];
  const byZone = {};
  for (const t of stoly) (byZone[t.zone_id || 0] ??= []).push(t);
  const stulOptions = Object.entries(byZone).map(([zid, ts]) => {
    const z = zoneById[zid];
    const label = z ? `${z.ikona || ''} ${z.nazev}`.trim() : 'Bez zóny';
    return `<optgroup label="${esc(label)}">${ts.map(t => `<option value="${t.id}" ${t.id == r.stul_id ? 'selected' : ''}>${esc(t.nazev)} · ${t.mist} míst</option>`).join('')}</optgroup>`;
  }).join('');

  openModal(`✏️ Rezervace · ${esc(r.jmeno)}`, `
    <div class="form-grid form-grid-tight" style="grid-template-columns:1fr 1fr">
      <div>
        <label class="form-label">Stůl (zóna)</label>
        <select class="form-select" id="rez-stul">${stulOptions}</select>
      </div>
      <div>
        <label class="form-label">Datum</label>
        <input type="date" class="form-input" id="rez-datum" value="${esc(String(r.datum || '').slice(0, 10))}">
      </div>
      <div>
        <label class="form-label">Od</label>
        <input type="time" class="form-input" id="rez-od" value="${esc((r.cas_od || '').slice(0, 5))}">
      </div>
      <div>
        <label class="form-label">Do</label>
        <input type="time" class="form-input" id="rez-do" value="${esc((r.cas_do || '').slice(0, 5))}">
      </div>
      <div>
        <label class="form-label">Jméno</label>
        <input type="text" class="form-input" id="rez-jmeno" value="${esc(r.jmeno || '')}">
      </div>
      <div>
        <label class="form-label">Telefon</label>
        <input type="text" class="form-input" id="rez-tel" value="${esc(r.telefon || '')}">
      </div>
      <div>
        <label class="form-label">Počet osob</label>
        <input type="number" min="1" class="form-input" id="rez-osob" value="${parseInt(r.pocet_osob) || 2}">
      </div>
      <div>
        <label class="form-label">Stav</label>
        <select class="form-select" id="rez-stav">
          <option value="confirmed" ${r.stav === 'confirmed' ? 'selected' : ''}>✓ Potvrzená</option>
          <option value="pending" ${r.stav === 'pending' ? 'selected' : ''}>⏳ Čeká na potvrzení</option>
          <option value="no_show" ${r.stav === 'no_show' ? 'selected' : ''}>👻 Nepřišli</option>
        </select>
      </div>
    </div>
    <div style="margin-top:10px">
      <label class="form-label">Poznámka</label>
      <input type="text" class="form-input" id="rez-pozn" value="${esc(r.poznamka || '')}" placeholder="Volitelná…">
    </div>
    <div class="form-actions">
      <button class="btn-danger-corner" onclick="zrusitRezervaci(${r.id})" title="Zrušit rezervaci (uvolní stůl)" aria-label="Zrušit rezervaci">🗑️</button>
      <div style="flex:1"></div>
      <button class="btn-primary btn-green" onclick="ulozitRezervaci(${r.id})">💾 Uložit změny</button>
    </div>
  `);
};

window.ulozitRezervaci = async function(id) {
  const v = (sel) => (document.getElementById(sel) || {}).value;
  try {
    await api('admin_tables.php?action=update_reservation', {
      method: 'POST',
      body: JSON.stringify({
        id,
        stul_id: parseInt(v('rez-stul')) || undefined,
        datum: v('rez-datum'),
        cas_od: v('rez-od'),
        cas_do: v('rez-do'),
        jmeno: (v('rez-jmeno') || '').trim(),
        telefon: v('rez-tel'),
        pocet_osob: parseInt(v('rez-osob')) || 2,
        stav: v('rez-stav'),
        poznamka: v('rez-pozn'),
      }),
    });
    closeModal();
    toastSuccess('✓ Rezervace upravena');
    renderRestaurantTables();
  } catch (e) { alert('Chyba: ' + e.message); }
};

window.zrusitRezervaci = async function(id) {
  if (!(await confirmDialog({ title: 'Zrušit rezervaci?', msg: 'Stůl se v tomto čase uvolní. Rezervace zůstane v historii jako zrušená.', danger: true, okText: 'Zrušit rezervaci' }))) return;
  try {
    await api('admin_tables.php?action=reservation&id=' + id, { method: 'DELETE' });
    closeModal();
    toastSuccess('Rezervace zrušena');
    renderRestaurantTables();
  } catch (e) { alert('Chyba: ' + e.message); }
};

// 🆕 v3.0.243 — picker šablon floor planu přímo v adminu (dřív jen ve floorplan editoru;
//   po smazání všech stolů byl admin slepá ulička — šlo přidat jen jednotlivý stůl).
// 🆕 v3.0.246 — mini SVG náhled rozložení stolů ze šablony (user chtěl „výběr s náhledama").
function fpSablonaThumb(tables, cw, ch) {
  tables = tables || [];
  if (!tables.length) return '<div style="height:110px;display:flex;align-items:center;justify-content:center;color:var(--text-3);font-size:26px;background:#FFFCF7;border-radius:8px">🪑</div>';
  let maxX = +cw || 0, maxY = +ch || 0;
  tables.forEach(t => { maxX = Math.max(maxX, (+t.x || 0) + (+t.width || 80)); maxY = Math.max(maxY, (+t.y || 0) + (+t.height || 80)); });
  maxX = maxX || 1000; maxY = maxY || 680;
  const W = 260, H = Math.max(90, Math.min(150, Math.round(W * maxY / maxX)));
  const s = Math.min(W / maxX, H / maxY);
  const shapes = tables.map(t => {
    const x = (+t.x || 0) * s, y = (+t.y || 0) * s, w = Math.max(3, (+t.width || 80) * s), h = Math.max(3, (+t.height || 80) * s);
    if (t.tvar === 'round') return `<ellipse cx="${(x + w / 2).toFixed(1)}" cy="${(y + h / 2).toFixed(1)}" rx="${(w / 2).toFixed(1)}" ry="${(h / 2).toFixed(1)}" fill="#EAD8B6" stroke="#BA7517" stroke-width="0.7"/>`;
    return `<rect x="${x.toFixed(1)}" y="${y.toFixed(1)}" width="${w.toFixed(1)}" height="${h.toFixed(1)}" rx="${Math.min(3, w / 4).toFixed(1)}" fill="#EAD8B6" stroke="#BA7517" stroke-width="0.7"/>`;
  }).join('');
  return `<svg viewBox="0 0 ${W} ${H}" width="100%" height="${H}" preserveAspectRatio="xMidYMid meet" style="display:block;background:#FFFCF7;border-radius:8px;border:1px solid var(--border)">${shapes}</svg>`;
}

window.openSablonyPicker = async function() {
  let builtin = [], user = [];
  try { builtin = (await api('admin_tables.php?action=templates')).templates || []; } catch (e) {}
  try { user = (await api('admin_tables.php?action=user_templates')).templates || []; } catch (e) {}
  const maStoly = (state._rtStolyCache || []).length > 0;
  // karta s náhledem; clean apply (Načíst = nahradí, žádné hromadění → editor zůstává přehledný)
  const card = (thumb, nazev, meta, applyAttr, extra = '') => `
    <div style="border:1px solid var(--border);border-radius:12px;overflow:hidden;background:var(--surface);display:flex;flex-direction:column">
      ${thumb}
      <div style="padding:10px 12px;display:flex;flex-direction:column;gap:8px;flex:1">
        <div>
          <div style="font-weight:700;font-size:13.5px">${nazev}</div>
          <div style="font-size:11px;color:var(--text-3);margin-top:2px">${meta}</div>
        </div>
        <div style="display:flex;gap:6px;margin-top:auto">
          <button class="btn-primary btn-green" style="flex:1;font-size:12.5px;padding:7px 12px" onclick="${applyAttr}">📥 Načíst</button>
          ${extra}
        </div>
      </div>
    </div>`;
  const userHtml = user.map(t => card(
    fpSablonaThumb(t.tables, (t.zones && t.zones[0] && t.zones[0].canvas_w) || 1000, (t.zones && t.zones[0] && t.zones[0].canvas_h) || 680),
    esc(t.nazev), `${t.pocet_stolu || (t.tables || []).length} stolů · ${t.pocet_zon || (t.zones || []).length} zón`,
    `adminApplySablona(${t.id},'user')`,
    `<button class="btn-secondary" style="font-size:12px;padding:7px 10px" title="Smazat šablonu" onclick="adminSmazatSablonu(${t.id})">🗑️</button>`
  )).join('');
  const builtinHtml = builtin.map(t => card(
    fpSablonaThumb(t.tables, t.canvas_w, t.canvas_h),
    esc(t.nazev || t.key), esc(t.popis || ''),
    `adminApplySablona('${esc(t.key)}','builtin')`
  )).join('');
  openModal('📋 Šablony floor planu', `
    ${maStoly ? `<div style="margin-bottom:14px;padding:10px 14px;background:#FEF3C7;border:1px solid #FCD34D;border-radius:8px;font-size:12.5px;color:#92400E">⚠️ Načtení šablony <strong>nahradí</strong> aktuální rozložení (stoly zůstanou v historii, rezervace se nemažou).</div>` : ''}
    ${user.length ? `<div style="font-size:11px;text-transform:uppercase;letter-spacing:.5px;color:var(--text-3);font-weight:700;margin-bottom:8px">Vlastní šablony</div><div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(220px,1fr));gap:12px;margin-bottom:18px">${userHtml}</div>` : ''}
    <div style="font-size:11px;text-transform:uppercase;letter-spacing:.5px;color:var(--text-3);font-weight:700;margin-bottom:8px">Předpřipravené</div>
    <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(220px,1fr));gap:12px">${builtinHtml || '<div style="color:var(--text-3);padding:14px">Žádné šablony.</div>'}</div>
  `, 'wide');
};

// 🆕 v3.0.246 — apply = vždy CLEAN nahrazení (žádný merge). Konec hromadění stolů přes zóny.
window.adminApplySablona = async function(idOrKey, kind) {
  const maStoly = (state._rtStolyCache || []).length > 0;
  if (maStoly && !(await confirmDialog({ title: 'Načíst šablonu?', msg: 'Nahradí aktuální rozložení stolů (zůstanou v historii, rezervace se nemažou).', danger: true, okText: 'Načíst šablonu' }))) return;
  try {
    const action = (kind === 'user') ? 'apply_user_template' : 'apply_template';
    const body = (kind === 'user') ? { id: idOrKey } : { template: idOrKey };  // bez merge → wipe+insert
    await api('admin_tables.php?action=' + action, { method: 'POST', body: JSON.stringify(body) });
    closeModal();
    toastSuccess('✓ Šablona načtena');
    renderRestaurantTables();
  } catch (e) { alert('Chyba: ' + e.message); }
};

window.adminSmazatSablonu = async function(id) {
  if (!(await confirmDialog({ title: 'Smazat šablonu?', msg: 'Vlastní šablona bude trvale smazána.', danger: true, okText: 'Smazat' }))) return;
  try {
    await api('admin_tables.php?action=user_template&id=' + id, { method: 'DELETE' });
    toastSuccess('Šablona smazána');
    openSablonyPicker();
  } catch (e) { alert('Chyba: ' + e.message); }
};

// 🆕 v3.0.243 — SMĚROVÁNÍ PO ULOŽENÍ: floorplan editor (samostatné okno) po „Použít"
//   nebo načtení šablony pošle postMessage → admin si invaliduje cache a překreslí
//   Stoly. Dřív admin po návratu z editoru ukazoval starý layout, dokud user nekliknul.
window.addEventListener('message', (ev) => {
  if (ev.origin !== window.location.origin) return;            // jen vlastní okna
  if (!ev.data || ev.data.type !== 'appek_floorplan_applied') return;
  try {
    apiCacheInvalidate('admin_tables');
    if (state.current === 'pkg_restaurace' && document.getElementById('rt-body')) {
      renderRestaurantTables();
      toastInfo('🗺️ Floor plan aktualizován z editoru');
    }
  } catch (e) { /* stránka zrovna není na Stolech — refresh proběhne při příchodu */ }
});

// 🆕 v3.0.243 — nová zóna přímo z adminu (dřív jen ve floorplan editoru)
window.pridatZonu = async function() {
  const nazev = (await promptDialog({ msg: 'Název nové zóny (např. Terasa, Salonek):', value: '' }));
  if (nazev === null) return;
  const n = nazev.trim();
  if (!n) return toastInfo('Název zóny nesmí být prázdný');
  try {
    await api('admin_tables.php?action=zone_save', { method: 'POST', body: JSON.stringify({ nazev: n }) });
    toastSuccess(`✓ Zóna „${n}" vytvořena — přidej do ní stoly`);
    renderRestaurantTables();
  } catch (e) { alert('Chyba: ' + e.message); }
};

// 🆕 v3.0.201 — Editor otevírací doby (po dnech v týdnu). Kalendář rezervací se podle ní přizpůsobí.
window.editOpeningHours = function() {
  const DNY = ['Pondělí', 'Úterý', 'Středa', 'Čtvrtek', 'Pátek', 'Sobota', 'Neděle'];
  const byDen = {};
  (state._rtHours || []).forEach(h => { byDen[+h.den] = h; });
  const rows = DNY.map((nazev, den) => {
    const h = byDen[den] || { otevreno_od: '11:00', otevreno_do: '23:00', zavreno: 0 };
    const zav = (h.zavreno == 1 || h.zavreno === true);
    const od = String(h.otevreno_od || '11:00').slice(0, 5);
    const doo = String(h.otevreno_do || '23:00').slice(0, 5);
    return `
      <div style="display:flex;align-items:center;gap:10px;padding:8px 10px;border:1px solid var(--border);border-radius:10px;background:var(--surface);flex-wrap:wrap">
        <strong style="flex:0 0 80px;font-size:13px">${nazev}</strong>
        <label style="display:flex;align-items:center;gap:6px;font-size:12px;color:var(--text-3);cursor:pointer">
          <input type="checkbox" id="oh-zav-${den}" ${zav ? 'checked' : ''} onchange="ohToggleClosed(${den})" style="width:16px;height:16px;cursor:pointer"> Zavřeno
        </label>
        <div id="oh-times-${den}" style="display:${zav ? 'none' : 'flex'};align-items:center;gap:6px;margin-left:auto">
          <input type="time" id="oh-od-${den}" value="${od}" class="form-input" style="width:auto;padding:5px 8px">
          <span style="color:var(--text-3)">–</span>
          <input type="time" id="oh-do-${den}" value="${doo}" class="form-input" style="width:auto;padding:5px 8px">
        </div>
      </div>
    `;
  }).join('');
  openModal('🕐 Otevírací doba', `
    <div style="display:flex;flex-direction:column;gap:8px;padding:4px 2px">
      <p style="font-size:12px;color:var(--text-3);margin:0 0 4px">Kalendář rezervací (timeline) se přizpůsobí otevírací době vybraného dne. Zavřený den se zobrazí jako 🔒 Zavřeno.</p>
      ${rows}
      <div style="display:flex;gap:8px;justify-content:flex-end;margin-top:10px">
        <button class="btn-secondary" onclick="closeModal()">Zrušit</button>
        <button class="btn-primary" onclick="saveOpeningHours()">💾 Uložit</button>
      </div>
    </div>
  `);
};
window.ohToggleClosed = function(den) {
  const zav = document.getElementById('oh-zav-' + den)?.checked;
  const t = document.getElementById('oh-times-' + den);
  if (t) t.style.display = zav ? 'none' : 'flex';
};
window.saveOpeningHours = async function() {
  const hours = [];
  for (let den = 0; den <= 6; den++) {
    const zav = document.getElementById('oh-zav-' + den)?.checked ? 1 : 0;
    const od = document.getElementById('oh-od-' + den)?.value || '11:00';
    const doo = document.getElementById('oh-do-' + den)?.value || '23:00';
    hours.push({ den, otevreno_od: od, otevreno_do: doo, zavreno: zav });
  }
  try {
    await api('admin_tables.php?action=save_hours', { method: 'POST', body: JSON.stringify({ hours }) });
    toast('✓ Otevírací doba uložena', 'success');
    closeModal();
    renderRestaurantTables();
  } catch (e) { toast('Chyba: ' + e.message, 'error'); }
};

window.addRestaurantTable = async function() {
  // 🆕 v3.0.243 — výběr zóny místo free-text sekce (stůl dřív vznikal BEZ zone_id →
  //   sirotek mimo zóny: neviděl ho floor plan ani filtr rezervací). Fallback text
  //   zůstává jen pro instalace bez zón.
  const zones = state._rtZones || [];
  const zonaField = zones.length
    ? `<div><label class="form-label">Zóna</label>
         <select class="form-input" id="rt-zona">
           ${zones.map((z, i) => `<option value="${z.id}" ${i === 0 ? 'selected' : ''}>${esc(`${z.ikona || ''} ${z.nazev}`.trim())}</option>`).join('')}
           <option value="">— bez zóny —</option>
         </select>
       </div>`
    : `<div><label class="form-label">Sekce</label>
         <input class="form-input" id="rt-sekce" placeholder="např. Terasa, Hlavní sál, Salonek">
       </div>`;
  openModal('+ Nový stůl', `
    <div class="form-grid form-grid-tight">
      <div><label class="form-label">Název *</label>
        <input class="form-input" id="rt-nazev" placeholder="např. Stůl 5">
      </div>
      <div><label class="form-label">Počet míst</label>
        <input type="number" class="form-input" id="rt-mist" value="4" min="1" max="20">
      </div>
      ${zonaField}
      <div><label class="form-label">Tvar</label>
        <select class="form-input" id="rt-tvar">
          <option value="square">⬜ Čtverec</option>
          <option value="round">⭕ Kulatý</option>
          <option value="rect">▭ Obdélník</option>
        </select>
      </div>
    </div>
    <div class="form-actions">
      <button class="btn-secondary" onclick="closeModal()">Zrušit</button>
      <button class="btn-primary btn-green" onclick="saveRestaurantTable()">💾 Přidat</button>
    </div>
  `);
};

window.saveRestaurantTable = async function() {
  const nazev = document.getElementById('rt-nazev')?.value?.trim();
  if (!nazev) { alert('Vyplň název.'); return; }
  const zonaSel = document.getElementById('rt-zona');
  const zoneId = zonaSel ? (parseInt(zonaSel.value) || null) : null;
  const zona = zoneId ? (state._rtZones || []).find(z => z.id === zoneId) : null;
  try {
    await api('admin_tables.php', { method: 'POST', body: JSON.stringify({
      nazev,
      mist: parseInt(document.getElementById('rt-mist').value) || 2,
      zone_id: zoneId,
      sekce: zona ? zona.nazev : (document.getElementById('rt-sekce')?.value?.trim() || null),
      tvar: document.getElementById('rt-tvar').value,
    })});
    closeModal();
    toastSuccess('Stůl přidán');
    renderRestaurantTables();
  } catch (e) { alert('Chyba: ' + e.message); }
};

window.editRestaurantTable = async function(id) {
  // Stub for now - just delete option
  if (!(await confirmDialog({ title: 'Smazat stůl?', msg: 'Smaže i všechny rezervace tohoto stolu.', danger: true, okText: 'Smazat' }))) return;
  try {
    await api('admin_tables.php?id=' + id, { method: 'DELETE' });
    toastSuccess('Stůl smazán');
    renderRestaurantTables();
  } catch (e) { alert('Chyba: ' + e.message); }
};

window.rezervovatStul = async function(stulId, nazev) {
  const today = state._rtDate || new Date().toISOString().slice(0,10);
  openModal(`📅 Rezervace stolu „${nazev}"`, `
    <div class="form-grid form-grid-tight">
      <div><label class="form-label">Datum</label>
        <input type="date" class="form-input" id="rez-datum" value="${today}">
      </div>
      <div><label class="form-label">Počet osob</label>
        <input type="number" class="form-input" id="rez-osob" value="2" min="1" max="20">
      </div>
      <div><label class="form-label">Čas od</label>
        <input type="time" class="form-input" id="rez-od" value="18:00">
      </div>
      <div><label class="form-label">Čas do</label>
        <input type="time" class="form-input" id="rez-do" value="20:00">
      </div>
      <div class="full"><label class="form-label">Jméno *</label>
        <input class="form-input" id="rez-jmeno" placeholder="např. Novák" autofocus>
      </div>
      <div class="full"><label class="form-label">Telefon</label>
        <input type="tel" class="form-input" id="rez-tel" placeholder="+420 ...">
      </div>
      <div class="full"><label class="form-label">Poznámka</label>
        <input class="form-input" id="rez-pozn" placeholder="např. Vegetariáni, oslava narozenin">
      </div>
    </div>
    <div class="form-actions">
      <button class="btn-secondary" onclick="closeModal()">Zrušit</button>
      <button class="btn-primary btn-green" onclick="saveReservation(${stulId})">📅 Rezervovat</button>
    </div>
  `);
};

window.saveReservation = async function(stulId) {
  const jmeno = document.getElementById('rez-jmeno')?.value?.trim();
  if (!jmeno) { alert('Vyplň jméno.'); return; }
  try {
    await api('admin_tables.php?action=reserve', { method: 'POST', body: JSON.stringify({
      stul_id: stulId,
      datum: document.getElementById('rez-datum').value,
      cas_od: document.getElementById('rez-od').value,
      cas_do: document.getElementById('rez-do').value,
      jmeno,
      telefon: document.getElementById('rez-tel').value.trim() || null,
      pocet_osob: parseInt(document.getElementById('rez-osob').value) || 2,
      poznamka: document.getElementById('rez-pozn').value.trim() || null,
    })});
    closeModal();
    toastSuccess('Rezervace uložena');
    renderRestaurantTables();
  } catch (e) { alert('Chyba: ' + e.message); }
};

