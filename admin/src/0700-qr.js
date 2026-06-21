// =============================================================
// 📲 QR
// =============================================================
window.posShowQR = async function(stulId, stulNazev) {
  try {
    const r = await api('admin_pos.php?action=qr_generate', {
      method: 'POST', body: JSON.stringify({ stul_id: stulId, reset: false }),
    });
    const url = r.url;
    const qrUrl = 'https://api.qrserver.com/v1/create-qr-code/?size=320x320&margin=20&data=' + encodeURIComponent(url);
    openModal(`📲 QR pro ${stulNazev}`, `
      <div style="text-align:center;padding:14px">
        <img src="${qrUrl}" alt="QR kód" style="max-width:280px;border:8px solid #fff;border-radius:12px;box-shadow:0 4px 16px rgba(0,0,0,0.15)">
        <h3 style="margin-top:14px;font-size:18px">${esc(stulNazev)}</h3>
        <p style="font-size:13px;color:var(--text-3);margin-top:4px">Host naskenuje a objedná z mobilu</p>
        <div style="background:#FAFAF8;border-radius:8px;padding:10px;margin-top:14px;font-family:monospace;font-size:11px;word-break:break-all">${esc(url)}</div>
        <div style="display:flex;gap:6px;margin-top:14px;flex-wrap:wrap">
          <button class="btn-secondary" onclick="window.print()" style="flex:1">🖨️ Tisk</button>
          <button class="btn-secondary" onclick="posResetQR(${stulId}, '${esc(stulNazev).replace(/'/g, '&#39;')}')" style="flex:1">🔄 Nový token</button>
          <a class="btn-primary" href="${esc(url)}" target="_blank" style="flex:1;text-decoration:none;text-align:center">👁️ Preview</a>
        </div>
      </div>
    `);
  } catch (e) { alert('Chyba: ' + e.message); }
};

window.posResetQR = async function(stulId, stulNazev) {
  if (!(await confirmDialog({ msg: 'Vygenerovat nový QR token? Předchozí QR kódy přestanou fungovat.', danger: false }))) return;
  try {
    await api('admin_pos.php?action=qr_generate', {
      method: 'POST', body: JSON.stringify({ stul_id: stulId, reset: true }),
    });
    closeModal();
    posShowQR(stulId, stulNazev);
  } catch (e) { alert('Chyba: ' + e.message); }
};

window.rtSetState = async function(id, stav) {
  try {
    await api('admin_tables.php?action=set_state', {
      method: 'POST',
      body: JSON.stringify({ id, stav, hostu_aktual: stav === 'occupied' ? 1 : 0 }),
    });
    closeModal();
    renderRestaurantTables();
  } catch (e) { alert('Chyba: ' + e.message); }
};

window.rtOpenTableProps = function(id) {
  const t = (state._rtData?.stoly || []).find(s => s.id === id);
  if (!t) return;
  openModal(`✏️ Vlastnosti — ${t.nazev}`, `
    <div style="display:flex;flex-direction:column;gap:10px">
      <label><span class="lbl">Název</span>
        <input id="rt-prop-nazev" class="form-input" value="${esc(t.nazev)}">
      </label>
      <div style="display:grid;grid-template-columns:1fr 1fr;gap:8px">
        <label><span class="lbl">Počet míst</span>
          <input id="rt-prop-mist" type="number" class="form-input" value="${t.mist}" min="0" max="50">
        </label>
        <label><span class="lbl">Tvar</span>
          <select id="rt-prop-tvar" class="form-input">
            <option value="round"  ${t.tvar==='round' ?'selected':''}>🔵 Kruh</option>
            <option value="square" ${t.tvar==='square'?'selected':''}>🟧 Čtverec</option>
            <option value="rect"   ${t.tvar==='rect'  ?'selected':''}>▭ Obdélník</option>
          </select>
        </label>
      </div>
      <div style="display:grid;grid-template-columns:1fr 1fr;gap:8px">
        <label><span class="lbl">Šířka (px)</span>
          <input id="rt-prop-w" type="number" class="form-input" value="${t.width || 80}" min="40" max="600" step="10">
        </label>
        <label><span class="lbl">Výška (px)</span>
          <input id="rt-prop-h" type="number" class="form-input" value="${t.height || 80}" min="40" max="600" step="10">
        </label>
      </div>
      <label><span class="lbl">Barva (volitelné, hex)</span>
        <input id="rt-prop-barva" type="text" class="form-input" placeholder="#DCFCE7" value="${esc(t.barva || '')}">
      </label>
      <div style="display:flex;gap:6px;margin-top:8px">
        <button class="btn-primary btn-green" onclick="rtSaveTableProps(${t.id})" style="flex:1">💾 Uložit</button>
        <button class="btn-secondary" onclick="closeModal()">Zrušit</button>
      </div>
    </div>
  `);
};

window.rtSaveTableProps = async function(id) {
  const data = {
    nazev:  document.getElementById('rt-prop-nazev').value.trim(),
    mist:   parseInt(document.getElementById('rt-prop-mist').value) || 2,
    tvar:   document.getElementById('rt-prop-tvar').value,
    width:  parseInt(document.getElementById('rt-prop-w').value) || 80,
    height: parseInt(document.getElementById('rt-prop-h').value) || 80,
    barva:  document.getElementById('rt-prop-barva').value.trim() || null,
  };
  try {
    await api('admin_tables.php?id=' + id, { method: 'PUT', body: JSON.stringify(data) });
    closeModal();
    renderRestaurantTables();
  } catch (e) { alert('Chyba: ' + e.message); }
};

