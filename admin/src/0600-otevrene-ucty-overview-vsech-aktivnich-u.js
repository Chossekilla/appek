// =============================================================
// 🧾 OTEVŘENÉ ÚČTY — overview všech aktivních účtů
// =============================================================
async function renderOpenUcty() {
  try {
    const r = await api('admin_pos.php?action=open_ucty');
    const ucty = r.ucty || [];
    const body = document.getElementById('rt-body');
    if (!body) return;
    if (ucty.length === 0) {
      body.innerHTML = `<div class="card-block" style="padding:60px 20px;text-align:center">
        <div style="font-size:48px;margin-bottom:14px">🧾</div>
        <h2 style="margin:0 0 6px">Žádné otevřené účty</h2>
        <p style="color:var(--text-3);font-size:14px">Klikni na obsazený stůl ve floor plánu pro otevření účtu.</p>
      </div>`;
      return;
    }
    const totalSum = ucty.reduce((s, u) => s + parseFloat(u.suma_kc), 0);
    body.innerHTML = `
      <div class="card-block" style="padding:10px 14px;margin-bottom:14px">
        <strong style="font-size:14px">📊 ${ucty.length} otevřených účtů</strong> · Celkem <strong style="color:#16A34A">${totalSum.toFixed(2)} Kč</strong>
      </div>
      <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(280px,1fr));gap:10px">
        ${ucty.map(u => {
          const minutes = u.otevreno_v ? Math.floor((Date.now() - new Date(u.otevreno_v.replace(' ', 'T')).getTime()) / 60000) : 0;
          return `
            <div class="card-block" onclick="posOpenUcet(${u.stul_id})" style="cursor:pointer;padding:14px;border-left:4px solid #16A34A">
              <div style="display:flex;justify-content:space-between;align-items:start;margin-bottom:6px">
                <strong style="font-size:15px">🍽️ ${esc(u.stul_nazev)}</strong>
                <span style="font-size:11px;color:var(--text-3);background:var(--surface-2);padding:2px 8px;border-radius:999px">⏱ ${minutes}m</span>
              </div>
              <div style="display:flex;justify-content:space-between;align-items:end">
                <div style="font-size:11px;color:var(--text-3)">${u.pocet_polozek} položek · ${esc(u.otevrel_jmeno || '—')}</div>
                <div style="font-size:18px;font-weight:800;color:#16A34A">${(+u.suma_kc).toFixed(2)} Kč</div>
              </div>
            </div>
          `;
        }).join('')}
      </div>
    `;
  } catch (e) {
    document.getElementById('rt-body').innerHTML = `<div class="alert err">❌ ${esc(e.message)}</div>`;
  }
}

