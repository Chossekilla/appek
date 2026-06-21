// =============================================================
// 📋 ŠABLONOVÝ PICKER
// =============================================================
window.rtOpenTemplatePicker = async function() {
  let tpls;
  try { tpls = await api('admin_tables.php?action=templates'); }
  catch (e) { alert('Chyba: ' + e.message); return; }
  const templates = tpls.templates || [];
  // 🆕 v3.0.208 — aktivní zóna pro režim „přidat stoly do zóny"
  const _zones = state._rtData?.zones || [];
  const _az = _zones.find(z => String(z.id) === String(rtState.activeZoneId)) || _zones[0];

  // 🎨 v3.0.33/37 — Bigger karty s mini SVG preview layoutu (s barvou per typ)
  const miniPreview = (t) => {
    const cw = t.canvas_w || 800, ch = t.canvas_h || 500;
    const bgColor = t.zones[0]?.bg_barva || '#FFFAF1';
    // 🆕 v3.0.37 — Detekce tmavého pozadí (rooftop, steakhouse, sushi) → invertujeme barvy stolů
    const isDark = /^#[0-3]/.test(bgColor);
    return `<svg viewBox="0 0 ${cw} ${ch}" style="width:100%;height:auto;background:${esc(bgColor)};border-radius:6px;display:block;margin-bottom:8px;max-height:160px">
      ${(t.tables || []).filter(x => (x.zone_idx ?? 0) === 0).map(tile => {
        const r = tile.tvar === 'round' ? Math.min(tile.width, tile.height)/2 : 4;
        // Barvy podle typu: bar = oranžová, lounge/VIP = fialová, grill/oheň = červená, ostatní = zelená
        const n = tile.nazev || '';
        let fill, stroke;
        if (/🍺|🍷|🍸|🍹|🍻|🍾|Bar|bar/.test(n))      { fill = isDark ? '#7C2D12' : '#FED7AA'; stroke = '#EA580C'; }
        else if (/🛋️|🥂|🥃|VIP|Lounge|Salon/.test(n)) { fill = isDark ? '#5B21B6' : '#E9D5FF'; stroke = '#7C3AED'; }
        else if (/🔥|🥩|🍕 Rod/.test(n))                { fill = isDark ? '#7F1D1D' : '#FECACA'; stroke = '#DC2626'; }
        else if (/💃|🎵|🎤|🎧|Parket|DJ|Pódium/.test(n)){ fill = isDark ? '#1E3A8A' : '#DBEAFE'; stroke = '#3B82F6'; }
        else if (/🎋|Tatami|🌳|Pergola|🍃|Piknik/.test(n)){fill = isDark ? '#14532D' : '#D1FAE5'; stroke = '#10B981'; }
        else                                              { fill = isDark ? '#374151' : '#FEF3C7'; stroke = isDark ? '#9CA3AF' : '#F59E0B'; }
        return tile.tvar === 'round'
          ? `<circle cx="${tile.x + tile.width/2}" cy="${tile.y + tile.height/2}" r="${Math.min(tile.width, tile.height)/2 - 2}" fill="${fill}" stroke="${stroke}" stroke-width="2"/>`
          : `<rect x="${tile.x}" y="${tile.y}" width="${tile.width}" height="${tile.height}" rx="${r}" fill="${fill}" stroke="${stroke}" stroke-width="2"/>`;
      }).join('')}
    </svg>`;
  };

  openModal(`📋 Šablony layoutu (${templates.length})`, `
    <div style="margin-bottom:18px;background:#EFF6FF;border:1px solid #BFDBFE;border-radius:10px;padding:14px 16px">
      <div style="font-weight:800;color:#1E40AF;font-size:14px;margin-bottom:10px">Jak šablonu použít?</div>
      <div style="display:flex;flex-direction:column;gap:8px">
        ${_az ? `
        <label style="display:flex;align-items:flex-start;gap:10px;cursor:pointer;font-size:13px;color:#1E3A8A;background:#fff;border:1px solid #BFDBFE;border-radius:8px;padding:10px 12px">
          <input type="radio" name="rt-tpl-mode" value="add_zone" checked style="width:17px;height:17px;margin-top:1px;accent-color:#1E40AF;cursor:pointer">
          <span><strong>➕ Přidat stoly do zóny „${esc(_az.nazev)}"</strong> — ponechá stávající stoly i ostatní zóny (skládáš si plán)</span>
        </label>` : ''}
        <label style="display:flex;align-items:flex-start;gap:10px;cursor:pointer;font-size:13px;color:#1E3A8A;padding:6px 12px">
          <input type="radio" name="rt-tpl-mode" value="keep" ${_az ? '' : 'checked'} style="width:17px;height:17px;margin-top:1px;accent-color:#1E40AF;cursor:pointer">
          <span><strong>♻️ Přepsat jen stoly</strong> — zóny zachovat, stoly šablony rozmístit do tvých zón</span>
        </label>
        <label style="display:flex;align-items:flex-start;gap:10px;cursor:pointer;font-size:13px;color:#1E3A8A;padding:6px 12px">
          <input type="radio" name="rt-tpl-mode" value="full" style="width:17px;height:17px;margin-top:1px;accent-color:#1E40AF;cursor:pointer">
          <span><strong>🗑️ Nahradit vše</strong> — smaže všechny stoly i zóny, založí kompletní layout</span>
        </label>
      </div>
      <p style="margin:10px 0 0;font-size:12px;color:#64748B">Náhled vidíš nahoře každé karty. Klikni na šablonu pro použití.</p>
    </div>
    <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(min(100%, 260px),1fr));gap:14px">
      ${templates.map(t => `
        <div style="border:2px solid #E5E7EB;border-radius:14px;padding:16px;cursor:pointer;transition:all 0.18s ease;background:#fff;display:flex;flex-direction:column"
             onmouseover="this.style.borderColor='#BA7517';this.style.transform='translateY(-2px)';this.style.boxShadow='0 6px 18px rgba(186,117,23,0.18)'"
             onmouseout="this.style.borderColor='#E5E7EB';this.style.transform='';this.style.boxShadow=''"
             onclick="rtApplyTemplate('${t.key}', '${esc(t.nazev)}')">
          ${miniPreview(t)}
          <div style="font-size:16px;font-weight:800;margin-bottom:6px;color:#1F2937">${esc(t.nazev)}</div>
          <div style="font-size:12px;color:#6B7280;line-height:1.55;margin-bottom:12px;flex:1">${esc(t.popis)}</div>
          <div style="display:flex;justify-content:space-between;align-items:center;font-size:12px;font-weight:700;padding-top:10px;border-top:1px solid #E5E7EB">
            <span style="color:#1F2937">🪑 ${t.tables.length} stolů</span>
            <span style="color:#6B7280">🗺️ ${t.zones.length} ${t.zones.length === 1 ? 'zóna' : 'zón'}</span>
            <span style="color:#FB923C;font-weight:800">Aplikovat →</span>
          </div>
        </div>
      `).join('')}
    </div>
  `);
};

window.rtApplyTemplate = async function(key, nazev) {
  // 🆕 v3.0.208 — 3 režimy (radio v pickeru; z banneru bez radia → 'keep' fallback):
  //   add_zone = PŘIDAT stoly šablony do aktuální zóny (nic nemaže) ← „přidám stoly do baru"
  //   keep     = přepsat jen stoly, zóny zachovat (dřívější default)
  //   full     = nahradit vše (zóny i stoly)
  const mode = document.querySelector('input[name="rt-tpl-mode"]:checked')?.value || 'keep';
  const zones = state._rtData?.zones || [];
  const az = zones.find(z => String(z.id) === String(rtState.activeZoneId)) || zones[0];

  let body, msg;
  if (mode === 'add_zone') {
    if (!az) return alert('Nejdřív vyber/vytvoř zónu, do které stoly přidat.');
    body = { template: key, mode: 'add_zone', target_zone_id: az.id };
    msg = `Přidat stoly šablony „${nazev}" do zóny „${az.nazev}"?\n\nStávající stoly i ostatní zóny ZŮSTANOU.`;
  } else if (mode === 'full') {
    body = { template: key, merge: false, keep_zones: false };
    msg = `Nahradit VŠECHNY stoly i zóny šablonou „${nazev}"?\n\nTohle smaže současný layout (zóny i stoly).`;
  } else {
    body = { template: key, merge: false, keep_zones: true };
    msg = `Přepsat rozložení stolů šablonou „${nazev}"?\n\nTvoje zóny ZŮSTANOU. Otevřené účty se zachovají.`;
  }
  if (!(await confirmDialog({ msg: msg, danger: false }))) return;
  try {
    const r = await api('admin_tables.php?action=apply_template', {
      method: 'POST',
      body: JSON.stringify(body),
    });
    if (r && r.ok) {
      closeModal();
      toastSuccess(
        mode === 'add_zone' ? `✅ Přidáno ${r.stoly} stolů do zóny „${r.zone_nazev || ''}"`
        : mode === 'full'   ? `✅ Naimportováno: ${r.stoly} stolů, ${r.zones} zón`
        :                     `✅ Stoly přepsány (${r.stoly}) · zóny zachovány`
      );
      // add_zone → zůstaň v té zóně (vidíš přidané stoly); jinak reset.
      rtState.activeZoneId = (mode === 'add_zone' && az) ? az.id : null;
      rtState.dirtyTables.clear();
      renderRestaurantTables();
    }
  } catch (e) { alert('Chyba: ' + e.message); }
};

