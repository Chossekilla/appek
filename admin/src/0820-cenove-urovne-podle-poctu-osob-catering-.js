// =============================================================
// 👥 CENOVÉ ÚROVNĚ podle počtu osob (Catering balíček)
// =============================================================
async function renderCateringPriceTiers() {
  const body = document.getElementById('cat-tab-body');
  if (!body) return;
  body.innerHTML = skeletonCards(3);
  let data;
  try { data = await api('admin_price_tiers.php'); }
  catch (e) { body.innerHTML = `<div class="alert err">${esc(e.message)}</div>`; return; }

  const sel = state._tierSetId || (data.sets?.[0]?.id || 0);
  state._tierSetId = sel;

  body.innerHTML = `
    <div class="card-block" style="margin-bottom:14px;background:linear-gradient(180deg,#FFF8E5,#fff)">
      <div style="display:flex;justify-content:space-between;align-items:start;gap:10px;flex-wrap:wrap">
        <div>
          <h3 style="margin:0 0 6px;font-size:15px">💡 Jak fungují cenové úrovně</h3>
          <p style="margin:0;font-size:12.5px;color:var(--text-3)">
            Definuj sady úrovní (např. "Standard catering", "Svatba — gala") a v každé stupňovité ceny dle počtu osob. Při kalkulaci pro X osob systém najde odpovídající úroveň a vypočítá cenu + zálohu.
          </p>
        </div>
      </div>
    </div>

    <div style="display:grid;grid-template-columns:280px 1fr;gap:18px">
      <div class="card-block">
        <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:10px">
          <h3 style="margin:0;font-size:14px">📦 Sady úrovní</h3>
          <button class="btn-secondary" style="font-size:11px;padding:4px 8px" onclick="tierSetEdit(0)">+ Nová</button>
        </div>
        ${(data.sets || []).length === 0 ? `<div style="font-size:12px;color:var(--text-3)">Žádné sady.</div>` : `
          <div style="display:flex;flex-direction:column;gap:4px">
            ${data.sets.map(s => `
              <button onclick="state._tierSetId=${s.id};renderCateringPriceTiers()" style="background:${s.id===sel?'var(--surface-2)':'transparent'};border:1px solid ${s.id===sel?'var(--primary)':'var(--border)'};border-radius:8px;padding:10px 12px;text-align:left;cursor:pointer;font-family:inherit">
                <div style="display:flex;align-items:center;gap:6px">
                  <span style="font-size:18px">${s.ikona}</span>
                  <strong style="font-size:13px">${esc(s.nazev)}</strong>
                </div>
                <div style="font-size:11px;color:var(--text-3);margin-top:2px">${(s.tiers || []).length} úrovní · záloha ${s.zaloha_pct}%</div>
              </button>
            `).join('')}
          </div>
        `}
      </div>
      <div id="tier-detail">${skeletonCards(2)}</div>
    </div>
  `;
  if (sel) renderTierSetDetail(sel);
}

async function renderTierSetDetail(setId) {
  const host = document.getElementById('tier-detail');
  if (!host) return;
  let s;
  try { s = await api('admin_price_tiers.php?action=set&id=' + setId); }
  catch (e) { host.innerHTML = `<div class="alert err">${esc(e.message)}</div>`; return; }

  state._tierCalcOsob = state._tierCalcOsob || 30;

  host.innerHTML = `
    <div class="card-block" style="margin-bottom:14px">
      <div style="display:flex;justify-content:space-between;align-items:start;flex-wrap:wrap;gap:10px">
        <div>
          <h3 style="margin:0 0 4px;font-size:17px">${s.ikona} ${esc(s.nazev)}</h3>
          ${s.popis ? `<p style="margin:0;font-size:12.5px;color:var(--text-3)">${esc(s.popis)}</p>` : ''}
          <div style="font-size:12px;color:var(--text-3);margin-top:6px">🧾 Záloha: <strong>${s.zaloha_pct} %</strong> z celkové ceny</div>
        </div>
        <div style="display:flex;gap:6px">
          <button class="btn-secondary" style="font-size:12px" onclick="tierSetEdit(${s.id})">✏️ Upravit</button>
          <button class="btn-secondary" style="font-size:12px;color:#dc2626" onclick="tierSetDelete(${s.id})">🗑️ Smazat</button>
        </div>
      </div>
    </div>

    <div style="display:grid;grid-template-columns:1fr 280px;gap:14px">
      <div class="card-block">
        <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:10px">
          <h3 style="margin:0;font-size:14px">📊 Úrovně cen (${(s.tiers || []).length})</h3>
          <button class="btn-secondary" style="font-size:11px;padding:4px 8px" onclick="tierEdit(0, ${s.id})">+ Úroveň</button>
        </div>
        ${(s.tiers || []).length === 0 ? `
          <div style="text-align:center;padding:20px;color:var(--text-3);font-size:13px">
            Žádné úrovně. Přidej první rozsah a cenu.
          </div>
        ` : `
          <table class="table" style="width:100%;border-collapse:collapse;font-size:13px">
            <thead style="background:var(--surface-2);font-size:11px;text-transform:uppercase;color:var(--text-3);font-weight:700">
              <tr>
                <th style="text-align:left;padding:8px 10px">Rozsah osob</th>
                <th style="text-align:right;padding:8px 10px">Cena/osoba</th>
                <th style="text-align:left;padding:8px 10px">Popis</th>
                <th style="text-align:right;padding:8px 10px"></th>
              </tr>
            </thead>
            <tbody>
              ${s.tiers.map(t => `
                <tr style="border-top:1px solid var(--border)">
                  <td style="padding:8px 10px;font-weight:600;font-variant-numeric:tabular-nums">${t.od_osob}${t.do_osob ? ' – ' + t.do_osob : '+'}</td>
                  <td style="padding:8px 10px;text-align:right;font-weight:700;font-variant-numeric:tabular-nums">${fmt(t.cena_per_osobu)}</td>
                  <td style="padding:8px 10px;color:var(--text-3);font-size:12px">${esc(t.popis || '')}</td>
                  <td style="padding:8px 10px;text-align:right;white-space:nowrap">
                    <button class="btn-secondary" style="font-size:11px;padding:3px 6px" onclick="tierEdit(${t.id}, ${s.id})">✏️</button>
                    <button class="btn-secondary" style="font-size:11px;padding:3px 6px;color:#dc2626" onclick="tierDelete(${t.id}, ${s.id})">🗑️</button>
                  </td>
                </tr>
              `).join('')}
            </tbody>
          </table>
        `}
      </div>

      <div style="position:sticky;top:80px;align-self:start">
        <div class="card-block" style="background:linear-gradient(180deg,#F0FDF4,#fff);border:2px solid #166534">
          <h3 style="margin:0 0 10px;color:#166534;font-size:14px">🧮 Kalkulačka</h3>
          <label style="display:block;margin-bottom:8px">
            <div style="margin-bottom:4px;font-size:11px;color:var(--text-3);text-transform:uppercase;font-weight:700">Počet osob</div>
            <input type="number" class="form-input" id="tcalc-osob" value="${state._tierCalcOsob}" min="1" max="500" style="font-size:24px;font-weight:700;text-align:center" oninput="state._tierCalcOsob=parseInt(this.value)||30;tierRecalc(${s.id})">
          </label>
          <div id="tcalc-result">⏳</div>
        </div>
      </div>
    </div>
  `;
  tierRecalc(s.id);
}

async function tierRecalc(setId) {
  const host = document.getElementById('tcalc-result');
  if (!host) return;
  try {
    const r = await api('admin_price_tiers.php?action=calc', { method:'POST', body: JSON.stringify({
      set_id: setId, osob: state._tierCalcOsob || 30,
    })});
    host.innerHTML = `
      <div style="font-size:11px;text-transform:uppercase;color:var(--text-3);font-weight:700;margin-top:8px">Vybraná úroveň</div>
      <div style="font-weight:600;font-size:13px;margin:4px 0 12px">${esc(r.tier.popis || '—')} (${r.tier.od_osob}${r.tier.do_osob ? '–' + r.tier.do_osob : '+'} osob)</div>
      <div style="display:flex;flex-direction:column;gap:5px;font-size:12.5px">
        <div style="display:flex;justify-content:space-between"><span>Cena za osobu</span><strong>${fmt(r.cena_per_osobu)}</strong></div>
        <div style="display:flex;justify-content:space-between"><span>× počet osob</span><strong>${r.osob}</strong></div>
      </div>
      <div style="border-top:2px solid #166534;margin:10px 0 6px;padding-top:8px;display:flex;justify-content:space-between;font-size:17px;font-weight:800;color:#166534">
        <span>Celkem</span><span>${fmt(r.cena_celkem)}</span>
      </div>
      <div style="background:#FEF3C7;color:#92400E;border-radius:6px;padding:8px 10px;margin-top:8px;font-size:11.5px">
        🧾 Záloha (${r.zaloha_pct}%): <strong>${fmt(r.zaloha_kc)}</strong><br>
        Doplatek: <strong>${fmt(r.doplatek_kc)}</strong>
      </div>
    `;
  } catch (e) {
    host.innerHTML = `<div style="color:#dc2626;font-size:12px">${esc(e.message)}</div>`;
  }
}

window.tierSetEdit = async function(id) {
  let s = { id:0, nazev:'', popis:'', ikona:'👥', zaloha_pct:50, aktivni:1 };
  if (id) {
    try { s = await api('admin_price_tiers.php?action=set&id=' + id); } catch (e) {}
  }
  openModal(id ? '✏️ Sada cen' : '+ Nová sada cen', `
    <div class="form-grid form-grid-tight">
      <div><label class="form-label">Ikona</label>
        <input class="form-input" id="ts-ikona" value="${esc(s.ikona)}" style="font-size:24px;text-align:center" maxlength="2">
      </div>
      <div><label class="form-label">Záloha %</label>
        <input type="number" class="form-input" id="ts-zal" value="${s.zaloha_pct}" min="0" max="100" step="5">
      </div>
      <div class="full"><label class="form-label">Název *</label>
        <input class="form-input" id="ts-nazev" value="${esc(s.nazev)}" placeholder="např. Svatba — gala">
      </div>
      <div class="full"><label class="form-label">Popis</label>
        <textarea class="form-input" id="ts-popis" rows="2">${esc(s.popis || '')}</textarea>
      </div>
    </div>
    <div class="form-actions">
      <button class="btn-secondary" onclick="closeModal()">Zrušit</button>
      <button class="btn-primary btn-green" onclick="tierSetSave(${id})">💾 Uložit</button>
    </div>
  `);
};

window.tierSetSave = async function(id) {
  const nazev = document.getElementById('ts-nazev').value.trim();
  if (!nazev) { alert('Vyplň název'); return; }
  try {
    const r = await api('admin_price_tiers.php?action=set', { method:'POST', body: JSON.stringify({
      id: id || 0, nazev,
      popis: document.getElementById('ts-popis').value.trim() || null,
      ikona: document.getElementById('ts-ikona').value.trim() || '👥',
      zaloha_pct: parseFloat(document.getElementById('ts-zal').value) || 50,
    })});
    closeModal();
    if (!id) state._tierSetId = r.id;
    toastSuccess('Sada uložena');
    renderCateringPriceTiers();
  } catch (e) { alert('Chyba: ' + e.message); }
};

window.tierSetDelete = async function(id) {
  if (!await customConfirm('Smazat sadu?', 'Smaže i všechny úrovně v ní.', 'Smazat')) return;
  try {
    await api('admin_price_tiers.php?action=set&id=' + id, { method:'DELETE' });
    state._tierSetId = 0;
    toastSuccess('Sada smazána');
    renderCateringPriceTiers();
  } catch (e) { alert('Chyba: ' + e.message); }
};

window.tierEdit = async function(id, setId) {
  let t = { id:0, set_id:setId, od_osob:1, do_osob:'', cena_per_osobu:0, popis:'', poradi:0 };
  if (id) {
    try {
      const s = await api('admin_price_tiers.php?action=set&id=' + setId);
      const found = (s.tiers || []).find(x => x.id === id);
      if (found) t = found;
    } catch (e) {}
  }
  openModal(id ? '✏️ Úroveň' : '+ Nová úroveň', `
    <div class="form-grid form-grid-tight">
      <div><label class="form-label">Od osob *</label>
        <input type="number" class="form-input" id="tt-od" value="${t.od_osob}" min="1">
      </div>
      <div><label class="form-label">Do osob (volné = bez horní hranice)</label>
        <input type="number" class="form-input" id="tt-do" value="${t.do_osob || ''}" min="1" placeholder="bez hranice">
      </div>
      <div class="full"><label class="form-label">Cena za osobu (Kč) *</label>
        <input type="number" class="form-input" id="tt-cena" value="${t.cena_per_osobu}" step="10" style="font-size:18px;font-weight:700">
      </div>
      <div class="full"><label class="form-label">Popis (volitelné)</label>
        <input class="form-input" id="tt-popis" value="${esc(t.popis || '')}" placeholder="např. Malá skupina, Hromadná akce — sleva">
      </div>
    </div>
    <div class="form-actions">
      <button class="btn-secondary" onclick="closeModal()">Zrušit</button>
      <button class="btn-primary btn-green" onclick="tierSave(${id}, ${setId})">💾 Uložit</button>
    </div>
  `);
};

window.tierSave = async function(id, setId) {
  const od = parseInt(document.getElementById('tt-od').value);
  const cena = parseFloat(document.getElementById('tt-cena').value);
  if (!od || !cena) { alert('Vyplň od osob a cenu.'); return; }
  try {
    await api('admin_price_tiers.php?action=tier', { method:'POST', body: JSON.stringify({
      id: id || 0, set_id: setId,
      od_osob: od,
      do_osob: parseInt(document.getElementById('tt-do').value) || null,
      cena_per_osobu: cena,
      popis: document.getElementById('tt-popis').value.trim() || null,
    })});
    closeModal();
    toastSuccess('Úroveň uložena');
    renderTierSetDetail(setId);
  } catch (e) { alert('Chyba: ' + e.message); }
};

window.tierDelete = async function(id, setId) {
  if (!await customConfirm('Smazat úroveň?', '', 'Smazat')) return;
  try {
    await api('admin_price_tiers.php?action=tier&id=' + id, { method:'DELETE' });
    toastSuccess('Úroveň smazána');
    renderTierSetDetail(setId);
  } catch (e) { alert('Chyba: ' + e.message); }
};

