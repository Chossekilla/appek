// =============================================================
// 👨‍🍳 KDS — Kitchen Display System (v2.3)
// =============================================================
async function renderKDS() {
  try {
    const r = await api('admin_pos.php?action=kds');
    const orders = r.orders || [];
    const body = document.getElementById('rt-body');
    if (!body) return;

    if (orders.length === 0) {
      body.innerHTML = `
        <div class="card-block" style="padding:60px 20px;text-align:center">
          <div style="font-size:48px;margin-bottom:14px">🍳</div>
          <h2 style="margin:0 0 6px">Kuchyně volná</h2>
          <p style="color:var(--text-3);font-size:14px">Žádné aktivní objednávky. ☕ Kafe je na řadě.</p>
          <button class="btn-secondary" onclick="renderKDS()" style="margin-top:14px">🔄 Obnovit</button>
        </div>`;
      return;
    }

    // Auto-refresh každých 8s pokud jsme stále na KDS
    if (window._kdsTimer) clearInterval(window._kdsTimer);
    window._kdsTimer = setInterval(() => {
      if (state._rtTab === 'kds') renderKDS();
      else clearInterval(window._kdsTimer);
    }, 8000);

    const stavLabels = {
      objednano: { ico: '⏳', label: 'NOVÉ', color: '#F59E0B', bg: '#FEF3C7' },
      vari_se: { ico: '🍳', label: 'VAŘÍM', color: '#3B82F6', bg: '#DBEAFE' },
      hotovo: { ico: '✅', label: 'HOTOVO', color: '#16A34A', bg: '#DCFCE7' },
    };

    body.innerHTML = `
      <div class="card-block" style="padding:10px 14px;margin-bottom:14px;display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:8px">
        <div style="font-size:14px"><strong>👨‍🍳 KDS</strong> · ${orders.length} stolů · ${r.total_items} položek · <span style="color:var(--text-3);font-size:12px">auto-refresh 8s</span></div>
        <div>
          <button class="btn-secondary" onclick="renderKDS()" style="padding:6px 14px">🔄 Obnovit teď</button>
        </div>
      </div>
      <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(320px,1fr));gap:14px">
        ${orders.map(o => renderKDSOrder(o, stavLabels)).join('')}
      </div>
    `;
  } catch (e) {
    document.getElementById('rt-body').innerHTML = `<div class="alert err">❌ ${esc(e.message)}</div>`;
  }
}

function renderKDSOrder(o, stavLabels) {
  const firstTime = o.first_objednavka ? new Date(o.first_objednavka.replace(' ', 'T')) : null;
  const waitingMin = firstTime ? Math.floor((Date.now() - firstTime.getTime()) / 60000) : 0;
  const isLate = waitingMin > 15;
  return `
    <div class="card-block" style="padding:0;overflow:hidden;border:2px solid ${isLate ? '#DC2626' : 'var(--border)'};${isLate ? 'animation:kdsPulse 2s infinite' : ''}">
      <div style="padding:10px 14px;background:${isLate ? '#FEE2E2' : '#FFF8E7'};border-bottom:1.5px solid var(--border);display:flex;justify-content:space-between;align-items:center">
        <div>
          <div style="font-size:16px;font-weight:800;color:#854F0B">🍽️ ${esc(o.stul_nazev)}</div>
          <div style="font-size:11px;color:#92400e">Účet #${o.ucet_id}</div>
        </div>
        <div style="text-align:right">
          <div style="font-size:18px;font-weight:800;color:${isLate ? '#DC2626' : '#854F0B'}">⏱ ${waitingMin}m</div>
          <div style="font-size:10px;color:#92400e">${o.polozky.length} položek</div>
        </div>
      </div>
      <div style="padding:8px;display:flex;flex-direction:column;gap:4px">
        ${o.polozky.map(p => {
          const stv = stavLabels[p.stav] || stavLabels.objednano;
          return `
            <div style="display:flex;align-items:center;gap:8px;padding:8px;background:${stv.bg};border-radius:6px;border-left:4px solid ${stv.color}">
              <div style="font-size:18px;font-weight:800;color:${stv.color};min-width:30px;text-align:center">${p.mnozstvi}×</div>
              <div style="flex:1;min-width:0">
                <div style="font-weight:600;font-size:13px">${esc(p.nazev)}</div>
                ${p.poznamka ? `<div style="font-size:11px;color:#854F0B;font-weight:600">⚠️ ${esc(p.poznamka)}</div>` : ''}
              </div>
              <div style="display:flex;gap:4px;flex-shrink:0">
                ${p.stav === 'objednano' ? `<button class="kds-btn" onclick="kdsItemAdvance(${p.id}, 'vari_se')" style="background:#3B82F6;color:#fff;border:0;border-radius:6px;padding:6px 10px;font-size:11px;font-weight:700;cursor:pointer">🍳 Vařit</button>` : ''}
                ${p.stav === 'vari_se' ? `<button class="kds-btn" onclick="kdsItemAdvance(${p.id}, 'hotovo')" style="background:#16A34A;color:#fff;border:0;border-radius:6px;padding:6px 10px;font-size:11px;font-weight:700;cursor:pointer">✅ Hotovo</button>` : ''}
                ${p.stav === 'hotovo' ? `<button class="kds-btn" onclick="kdsItemAdvance(${p.id}, 'servirovano')" style="background:#64748B;color:#fff;border:0;border-radius:6px;padding:6px 10px;font-size:11px;font-weight:700;cursor:pointer">🍽️ Servírovat</button>` : ''}
              </div>
            </div>
          `;
        }).join('')}
      </div>
    </div>
  `;
}

window.kdsItemAdvance = async function(itemId, stav) {
  try {
    await api('admin_pos.php?action=item_state', {
      method: 'POST', body: JSON.stringify({ id: itemId, stav }),
    });
    renderKDS();
  } catch (e) { alert('Chyba: ' + e.message); }
};

// CSS pro KDS animaci
if (!document.getElementById('kds-styles')) {
  const s = document.createElement('style');
  s.id = 'kds-styles';
  s.textContent = `
    @keyframes kdsPulse {
      0%, 100% { box-shadow: 0 0 0 0 rgba(220,38,38,0.4); }
      50% { box-shadow: 0 0 0 6px rgba(220,38,38,0); }
    }
  `;
  document.head.appendChild(s);
}

