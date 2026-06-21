// =============================================================
// 👨‍🍳 KAPACITA KUCHYNĚ (Restaurace balíček)
// =============================================================
async function renderKitchenCapacity() {
  const body = document.getElementById('rest-tab-body');
  if (!body) return;
  body.innerHTML = skeletonCards(3);
  let data;
  try { data = await api('admin_kitchen.php'); }
  catch (e) { body.innerHTML = `<div class="alert err">${esc(e.message)}</div>`; return; }

  const s = data.stats || {};
  const settings = data.settings || {};
  const loadColor = s.global_load >= 90 ? '#dc2626' : s.global_load >= 70 ? '#F59E0B' : '#10B981';

  body.innerHTML = `
    <div style="display:grid;grid-template-columns:1fr 320px;gap:18px">
      <div style="display:flex;flex-direction:column;gap:14px">
        <!-- GLOBAL LOAD GAUGE -->
        <div class="card-block">
          <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:12px">
            <h3 style="margin:0;font-size:15px">⚡ Aktuální vytížení</h3>
            ${s.is_full ? '<span style="background:#FEE2E2;color:#991B1B;padding:3px 10px;border-radius:999px;font-size:11.5px;font-weight:700">🚫 Kuchyně plná</span>' : ''}
          </div>
          <div style="display:flex;align-items:center;gap:18px;flex-wrap:wrap">
            <div style="position:relative;width:130px;height:130px;flex-shrink:0">
              <svg viewBox="0 0 100 100" style="width:100%;height:100%;transform:rotate(-90deg)">
                <circle cx="50" cy="50" r="42" fill="none" stroke="var(--surface-2)" stroke-width="11"/>
                <circle cx="50" cy="50" r="42" fill="none" stroke="${loadColor}" stroke-width="11"
                  stroke-dasharray="${(s.global_load || 0) * 2.64} 264" stroke-linecap="round"/>
              </svg>
              <div style="position:absolute;inset:0;display:flex;flex-direction:column;align-items:center;justify-content:center">
                <strong style="font-size:30px;color:${loadColor};line-height:1">${s.global_load || 0}%</strong>
                <span style="font-size:10px;color:var(--text-3);text-transform:uppercase;letter-spacing:0.05em">vytížení</span>
              </div>
            </div>
            <div style="display:flex;flex-direction:column;gap:8px;flex:1;min-width:200px">
              <div style="display:flex;justify-content:space-between"><span>📋 Aktivní objednávky</span><strong>${s.active_orders || 0} / ${settings.max_paralelni_objednavky || 0}</strong></div>
              <div style="display:flex;justify-content:space-between"><span>🔨 Připravuje se</span><strong style="color:#1E40AF">${s.preparing || 0}</strong></div>
              <div style="display:flex;justify-content:space-between"><span>✅ Hotovo k výdeji</span><strong style="color:#166534">${s.ready || 0}</strong></div>
            </div>
          </div>
        </div>

        <!-- STATIONS -->
        <div class="card-block">
          <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:12px">
            <h3 style="margin:0;font-size:15px">🔥 Stanice ${(data.stanice || []).length}</h3>
            <button class="btn-secondary" style="font-size:12px" onclick="kitchenStationEdit(0)">+ Nová stanice</button>
          </div>
          <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(220px,1fr));gap:10px">
            ${(data.stanice || []).map(st => {
              const load = data.station_load?.[st.id] || {};
              return `
                <div style="border:2px solid ${st.barva};border-radius:10px;padding:12px;background:${st.barva}10">
                  <div style="display:flex;justify-content:space-between;align-items:start;margin-bottom:6px">
                    <div>
                      <strong style="font-size:14px">${st.ikona} ${esc(st.nazev)}</strong>
                      <div style="font-size:11px;color:var(--text-3)">max ${st.max_paralelni} paralelně</div>
                      ${(() => { const pr = (data.printers || []).find(p => String(p.id) === String(st.printer_id)); return pr
                        ? `<div style="font-size:11px;color:var(--text-3);margin-top:2px">🖨️ ${esc(pr.nazev)}</div>`
                        : `<div style="font-size:11px;color:#B45309;margin-top:2px" title="Přiřaď v Nastavení → Tiskárny">🖨️ bez tiskárny</div>`; })()}
                    </div>
                    <button class="btn-secondary" style="font-size:10px;padding:3px 6px" onclick="kitchenStationEdit(${st.id})">✏️</button>
                  </div>
                  <div style="display:flex;gap:6px;font-size:11.5px;margin-top:8px">
                    <span style="background:#DBEAFE;color:#1E40AF;padding:2px 8px;border-radius:999px;font-weight:700">▶ ${load.queued || 0} čeká</span>
                    <span style="background:#FEF3C7;color:#92400E;padding:2px 8px;border-radius:999px;font-weight:700">🔨 ${load.preparing || 0}</span>
                    <span style="background:#DCFCE7;color:#166534;padding:2px 8px;border-radius:999px;font-weight:700">✓ ${load.ready || 0}</span>
                  </div>
                  <div style="margin-top:8px;height:6px;background:var(--surface-2);border-radius:3px;overflow:hidden">
                    <div style="height:100%;width:${load.load_pct || 0}%;background:${st.barva};transition:width 0.3s"></div>
                  </div>
                </div>
              `;
            }).join('')}
          </div>
        </div>

        <!-- QUEUE -->
        <div class="card-block">
          <h3 style="margin:0 0 10px;font-size:15px">📋 Fronta výroby (${(data.queue || []).length})</h3>
          ${(data.queue || []).length === 0 ? `
            <div style="text-align:center;padding:24px;color:var(--text-3);font-size:13px">
              😴 Klid v kuchyni — žádné položky ve frontě.
            </div>
          ` : `
            <div style="display:flex;flex-direction:column;gap:6px">
              ${data.queue.map(q => {
                const cKey = q.stav === 'queued' ? '#DBEAFE' : (q.stav === 'preparing' ? '#FEF3C7' : '#DCFCE7');
                const cFg  = q.stav === 'queued' ? '#1E40AF' : (q.stav === 'preparing' ? '#92400E' : '#166534');
                const lbl  = q.stav === 'queued' ? '▶ Čeká' : (q.stav === 'preparing' ? '🔨 Připravuje' : '✓ Hotovo');
                return `
                  <div style="display:grid;grid-template-columns:auto 1fr auto auto;gap:10px;align-items:center;background:var(--surface-2);padding:8px 12px;border-radius:8px;border-left:4px solid ${q.station_barva || '#888'}">
                    <span style="font-size:18px">${q.station_ikona || '🍳'}</span>
                    <div>
                      <strong style="font-size:13.5px">${esc(q.vyrobek_nazev)}</strong>
                      <span style="color:var(--text-3);font-size:11.5px">· ${q.mnozstvi}× · ${q.priprava_min}min</span>
                      <div style="font-size:11px;color:var(--text-3)">
                        ${q.station_nazev ? `🍳 ${esc(q.station_nazev)} · ` : ''}
                        v queue ${q.minut_v_queue || 0}min
                        ${q.cas_zacatek ? ` · připravuje se ${q.minut_pripravuje || 0}min` : ''}
                      </div>
                    </div>
                    <span style="background:${cKey};color:${cFg};padding:2px 8px;border-radius:999px;font-size:10.5px;font-weight:700">${lbl}</span>
                    <div style="display:flex;gap:4px">
                      ${q.stav === 'queued' ? `<button class="btn-primary" style="font-size:11px;padding:4px 8px" onclick="kitchenQStatus(${q.id}, '${q.src}', 'preparing')">▶ Začít</button>` : ''}
                      ${q.stav === 'preparing' ? `<button class="btn-primary btn-green" style="font-size:11px;padding:4px 8px" onclick="kitchenQStatus(${q.id}, '${q.src}', 'ready')">✓ Hotovo</button>` : ''}
                      ${q.stav === 'ready' ? `<button class="btn-primary btn-green" style="font-size:11px;padding:4px 8px" onclick="kitchenQStatus(${q.id}, '${q.src}', 'served')">📤 Vydáno</button>` : ''}
                    </div>
                  </div>
                `;
              }).join('')}
            </div>
          `}
        </div>
      </div>

      <!-- RIGHT: SETTINGS PANEL -->
      <div style="position:sticky;top:80px;align-self:start">
        <div class="card-block">
          <h3 style="margin:0 0 12px;font-size:14px">⚙️ Nastavení</h3>
          <div style="display:flex;flex-direction:column;gap:10px;font-size:12.5px">
            <label>
              <div style="margin-bottom:4px;color:var(--text-3)">Max. paralelních objednávek</div>
              <input type="number" class="form-input" id="ks-max" value="${settings.max_paralelni_objednavky || 8}" min="1" style="font-size:18px;font-weight:700;text-align:center">
            </label>
            <label>
              <div style="margin-bottom:4px;color:var(--text-3)">Max. minut přípravy SLA</div>
              <input type="number" class="form-input" id="ks-mp" value="${settings.max_min_priprava || 25}" min="5">
            </label>
            <label>
              <div style="margin-bottom:4px;color:var(--text-3)">Velikost slotu (min)</div>
              <input type="number" class="form-input" id="ks-slot" value="${settings.slot_velikost_min || 15}" min="5">
            </label>
            <div style="display:grid;grid-template-columns:1fr 1fr;gap:6px">
              <label>
                <div style="margin-bottom:4px;color:var(--text-3)">Otevřeno od</div>
                <input type="time" class="form-input" id="ks-od" value="${esc((settings.otevreno_od || '').slice(0,5))}">
              </label>
              <label>
                <div style="margin-bottom:4px;color:var(--text-3)">Otevřeno do</div>
                <input type="time" class="form-input" id="ks-do" value="${esc((settings.otevreno_do || '').slice(0,5))}">
              </label>
            </div>
            <label style="display:flex;align-items:center;gap:8px;cursor:pointer">
              <input type="checkbox" id="ks-ab" ${parseInt(settings.auto_block)===1 ? 'checked' : ''}>
              <span>Auto-blokovat nové objednávky když je plno</span>
            </label>
            <label style="display:flex;align-items:center;gap:8px;cursor:pointer">
              <input type="checkbox" id="ks-af" ${parseInt(settings.auto_fire ?? 1)===1 ? 'checked' : ''}>
              <span>🍳 Automaticky posílat položky do kuchyně při přidání <span style="color:var(--text-3);font-size:11px">(vypnuto = až tlačítkem „Odeslat do kuchyně")</span></span>
            </label>
            <button class="btn-primary btn-green" onclick="kitchenSettingsSave()">💾 Uložit nastavení</button>
          </div>
        </div>
      </div>
    </div>
  `;
}

window.kitchenSettingsSave = async function() {
  try {
    await api('admin_kitchen.php?action=settings', { method:'POST', body: JSON.stringify({
      max_paralelni_objednavky: parseInt(document.getElementById('ks-max').value) || 8,
      max_min_priprava: parseInt(document.getElementById('ks-mp').value) || 25,
      slot_velikost_min: parseInt(document.getElementById('ks-slot').value) || 15,
      otevreno_od: document.getElementById('ks-od').value || null,
      otevreno_do: document.getElementById('ks-do').value || null,
      auto_block: document.getElementById('ks-ab').checked ? 1 : 0,
      auto_fire: document.getElementById('ks-af').checked ? 1 : 0,
    })});
    toastSuccess('Nastavení uloženo');
    renderKitchenCapacity();
  } catch (e) { alert('Chyba: ' + e.message); }
};

window.kitchenQStatus = async function(id, src, stav) {
  try {
    await api('admin_kitchen.php?action=order_status', { method:'POST', body: JSON.stringify({ id, src, stav })});
    renderKitchenCapacity();
  } catch (e) { alert('Chyba: ' + e.message); }
};

window.kitchenStationEdit = async function(id) {
  let st = { id:0, nazev:'', ikona:'🔥', max_paralelni:4, barva:'#F59E0B', poradi:0 };
  if (id) {
    try {
      const data = await api('admin_kitchen.php');
      const found = (data.stanice || []).find(x => x.id === id);
      if (found) st = found;
    } catch (e) {}
  }
  openModal(id ? `✏️ Stanice ${esc(st.nazev)}` : '+ Nová stanice', `
    <div class="form-grid form-grid-tight">
      <div><label class="form-label">Ikona</label>
        <input class="form-input" id="kst-ikona" value="${esc(st.ikona)}" style="font-size:24px;text-align:center" maxlength="2">
      </div>
      <div><label class="form-label">Barva</label>
        <input type="color" class="form-input" id="kst-barva" value="${esc(st.barva || '#F59E0B')}" style="padding:4px;height:38px">
      </div>
      <div class="full"><label class="form-label">Název *</label>
        <input class="form-input" id="kst-nazev" value="${esc(st.nazev)}" placeholder="např. Pizza pec">
      </div>
      <div><label class="form-label">Max. paralelně</label>
        <input type="number" class="form-input" id="kst-max" value="${st.max_paralelni}" min="1">
      </div>
      <div><label class="form-label">Pořadí</label>
        <input type="number" class="form-input" id="kst-poradi" value="${st.poradi}">
      </div>
    </div>
    <div class="form-actions" style="justify-content:space-between">
      ${id ? `<button class="btn-secondary" style="color:#dc2626" onclick="kitchenStationDelete(${id})">🗑️ Smazat</button>` : '<div></div>'}
      <div style="display:flex;gap:8px">
        <button class="btn-secondary" onclick="closeModal()">Zrušit</button>
        <button class="btn-primary btn-green" onclick="kitchenStationSave(${id})">💾 Uložit</button>
      </div>
    </div>
  `);
};

window.kitchenStationSave = async function(id) {
  const nazev = document.getElementById('kst-nazev').value.trim();
  if (!nazev) { alert('Vyplň název'); return; }
  try {
    await api('admin_kitchen.php?action=station', { method:'POST', body: JSON.stringify({
      id: id || 0, nazev,
      ikona: document.getElementById('kst-ikona').value.trim() || '🔥',
      max_paralelni: parseInt(document.getElementById('kst-max').value) || 4,
      barva: document.getElementById('kst-barva').value || '#F59E0B',
      poradi: parseInt(document.getElementById('kst-poradi').value) || 0,
    })});
    closeModal();
    toastSuccess('Stanice uložena');
    renderKitchenCapacity();
  } catch (e) { alert('Chyba: ' + e.message); }
};

window.kitchenStationDelete = async function(id) {
  if (!await customConfirm('Smazat stanici?', 'Položky ve frontě této stanice ztratí přiřazení.', 'Smazat')) return;
  try {
    await api('admin_kitchen.php?action=station&id=' + id, { method:'DELETE' });
    closeModal();
    toastSuccess('Stanice smazána');
    renderKitchenCapacity();
  } catch (e) { alert('Chyba: ' + e.message); }
};

