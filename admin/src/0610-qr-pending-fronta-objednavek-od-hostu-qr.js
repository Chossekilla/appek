// =============================================================
// 📲 QR PENDING — fronta objednávek od hostů (QR self-order)
// =============================================================
async function renderQRPending() {
  try {
    const r = await api('admin_pos.php?action=qr_pending');
    const orders = r.orders || [];
    const body = document.getElementById('rt-body');
    if (!body) return;

    if (window._qrTimer) clearInterval(window._qrTimer);
    window._qrTimer = setInterval(() => {
      if (state._rtTab === 'qr_pending') renderQRPending();
      else clearInterval(window._qrTimer);
    }, 10000);

    if (orders.length === 0) {
      body.innerHTML = `<div class="card-block" style="padding:60px 20px;text-align:center">
        <div style="font-size:48px;margin-bottom:14px">📲</div>
        <h2 style="margin:0 0 6px">Žádné QR objednávky čekající</h2>
        <p style="color:var(--text-3);font-size:14px">Když host naskenuje QR kód na stole a něco si objedná, objeví se to tady ke schválení.</p>
        <p style="font-size:12px;color:var(--text-3);margin-top:14px">Auto-refresh 10s</p>
      </div>`;
      return;
    }

    // Group by stůl (může být víc položek z jednoho stolu)
    const byTable = {};
    for (const o of orders) {
      byTable[o.stul_id] ??= { stul_nazev: o.stul_nazev, stul_id: o.stul_id, items: [] };
      byTable[o.stul_id].items.push(o);
    }

    body.innerHTML = `
      <div class="card-block" style="padding:10px 14px;margin-bottom:14px;background:linear-gradient(135deg,#FFF8E7,#FEF3C7)">
        <strong style="font-size:14px;color:#854F0B">📲 ${orders.length} čekajících QR objednávek od ${Object.keys(byTable).length} stolů</strong>
        <span style="font-size:12px;color:var(--text-3);margin-left:8px">auto-refresh 10s</span>
      </div>
      <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(360px,1fr));gap:14px">
        ${Object.values(byTable).map(grp => {
          const total = grp.items.reduce((s, i) => s + i.jednotkova_cena * i.mnozstvi, 0);
          return `
            <div class="card-block" style="padding:14px;border:2px solid #F59E0B">
              <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:10px">
                <div>
                  <strong style="font-size:16px">🍽️ ${esc(grp.stul_nazev)}</strong>
                  ${grp.items[0]?.host_jmeno ? `<div style="font-size:12px;color:var(--text-3)">👤 ${esc(grp.items[0].host_jmeno)} ${grp.items[0].host_telefon ? '· 📞 ' + esc(grp.items[0].host_telefon) : ''}</div>` : ''}
                </div>
                <div style="text-align:right">
                  <div style="font-size:18px;font-weight:800;color:#854F0B">${total.toFixed(2)} Kč</div>
                </div>
              </div>
              <div style="display:flex;flex-direction:column;gap:4px;margin-bottom:10px">
                ${grp.items.map(i => `
                  <div style="display:flex;justify-content:space-between;padding:6px 10px;background:#FFFAF1;border-radius:6px">
                    <span>${i.mnozstvi}× ${esc(i.nazev)}</span>
                    <strong>${(i.jednotkova_cena * i.mnozstvi).toFixed(2)} Kč</strong>
                  </div>
                  ${i.poznamka ? `<div style="font-size:11px;color:#854F0B;font-style:italic;padding-left:10px">⚠️ ${esc(i.poznamka)}</div>` : ''}
                `).join('')}
              </div>
              <div style="display:flex;gap:6px">
                <button class="btn-primary btn-green" style="flex:1;padding:10px;background:linear-gradient(135deg,#16A34A,#15803D);font-weight:700" onclick="qrApproveAll(${grp.stul_id}, [${grp.items.map(i => i.id).join(',')}])">✅ Schválit vše</button>
                <button class="btn-secondary" style="padding:10px" onclick="qrRejectAll([${grp.items.map(i => i.id).join(',')}])">❌ Odmítnout</button>
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

window.qrApproveAll = async function(stulId, ids) {
  try {
    for (const id of ids) {
      await api('admin_pos.php?action=qr_approve', {
        method: 'POST', body: JSON.stringify({ qr_order_id: id }),
      });
    }
    toastSuccess(t('toast_approved_items', { n: ids.length }));
    renderQRPending();
  } catch (e) { alert('Chyba: ' + e.message); }
};

window.qrRejectAll = async function(ids) {
  if (!(await confirmDialog({ msg: t('confirm_reject_items', { n: ids.length }), danger: false }))) return;
  try {
    for (const id of ids) {
      await api('admin_pos.php?action=qr_reject', {
        method: 'POST', body: JSON.stringify({ qr_order_id: id }),
      });
    }
    renderQRPending();
  } catch (e) { alert('Chyba: ' + e.message); }
};

