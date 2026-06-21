// =============================================================
// 🚚 LAHŮDKY — CATERING S ČASEM (compact list, sdílí tabulku catering_orders)
// =============================================================
async function renderLahudkyCateringList() {
  const body = document.getElementById('lahudky-tab-body');
  if (!body) return;
  body.innerHTML = skeletonCards(2);
  let data;
  try { data = await api('admin_catering_orders.php'); }
  catch (e) { body.innerHTML = `<div class="alert err">${esc(e.message)}</div>`; return; }
  const akce = data.akce || [];
  const stavLabel = {
    'poptavka':'💭 Poptávka','nabidka':'📋 Nabídka','potvrzeno':'✅ Potvrzeno',
    'zaloha_uhraz':'🧾 Záloha','realizace':'🍽️ Realizace','dokonceno':'✓ Hotovo','zruseno':'🚫 Zruš.'
  };
  body.innerHTML = `
    <div class="card-block" style="margin-bottom:14px;display:flex;justify-content:space-between;align-items:center;gap:10px">
      <div>
        <strong style="font-size:14px">🚚 Catering objednávky s časem dodání</strong>
        <div style="font-size:11.5px;color:var(--text-3);margin-top:2px">${akce.length} akcí · Sdílí evidenci s balíčkem 🎉 Velký catering</div>
      </div>
      <button class="btn-primary btn-green" onclick="newCateringOrder()">+ Nová akce</button>
    </div>
    ${akce.length === 0 ? emptyState({
      icon:'🚚', title:'Žádné catering akce',
      msg:'Akce s pevným časem dodání (firemní snídaně, raut, oběd na schůzku).',
      actions:'<button class="btn-primary btn-green" onclick="newCateringOrder()">+ Vytvořit první akci</button>',
    }) : `
      <div class="card-block" style="padding:0;overflow:hidden">
        <table style="width:100%;border-collapse:collapse">
          <thead style="background:var(--surface-2);font-size:11px;text-transform:uppercase;color:var(--text-3);font-weight:700">
            <tr>
              <th style="text-align:left;padding:10px 14px">Datum</th>
              <th style="text-align:left;padding:10px 14px">Čas dodání</th>
              <th style="text-align:left;padding:10px 14px">Akce / Zákazník</th>
              <th style="text-align:center;padding:10px 14px">Osob</th>
              <th style="text-align:right;padding:10px 14px">Cena</th>
              <th style="text-align:center;padding:10px 14px">Stav</th>
            </tr>
          </thead>
          <tbody>
            ${akce.map(a => `
              <tr style="border-top:1px solid var(--border);font-size:13px;cursor:pointer" onclick="editCateringOrder(${a.id})">
                <td style="padding:9px 14px;font-weight:600">${new Date(a.datum_akce).toLocaleDateString('cs-CZ')}</td>
                <td style="padding:9px 14px;font-variant-numeric:tabular-nums">${a.cas_od ? a.cas_od.slice(0,5) : '—'}${a.cas_do ? ' – ' + a.cas_do.slice(0,5) : ''}</td>
                <td style="padding:9px 14px">
                  <div style="font-weight:600">${esc(a.nazev)}</div>
                  <div style="font-size:11.5px;color:var(--text-3)">${esc(a.zakaznik)}</div>
                </td>
                <td style="padding:9px 14px;text-align:center">👥 ${a.osob}</td>
                <td style="padding:9px 14px;text-align:right;font-weight:600;font-variant-numeric:tabular-nums">${fmt(a.castka_celkem)}</td>
                <td style="padding:9px 14px;text-align:center;font-size:11px">${stavLabel[a.stav] || a.stav}</td>
              </tr>
            `).join('')}
          </tbody>
        </table>
      </div>
    `}
  `;
}

