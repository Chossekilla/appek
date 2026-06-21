// =============================================================
// 📑 SMLOUVY A NABÍDKY (Catering balíček)
// =============================================================
async function renderCateringPdfs() {
  const body = document.getElementById('cat-tab-body');
  if (!body) return;
  body.innerHTML = skeletonCards(2);
  let data;
  try { data = await api('admin_catering_orders.php'); }
  catch (e) { body.innerHTML = `<div class="alert err">${esc(e.message)}</div>`; return; }

  const akce = data.akce || [];
  body.innerHTML = `
    <div class="card-block" style="margin-bottom:14px">
      <h3 style="margin:0 0 6px;font-size:15px">📑 Generování PDF dokumentů</h3>
      <p style="margin:0;font-size:12.5px;color:var(--text-3)">
        Pro každou akci můžeš vygenerovat <strong>nabídku</strong> (zaslat klientovi před objednávkou),
        <strong>smlouvu</strong> (s podmínkami a podpisem) nebo <strong>zálohovou fakturu</strong> (50 % předem).
        Dokumenty se otevřou v novém okně — uložíš jako PDF přes tisk (Cmd+P / Ctrl+P → Uložit jako PDF).
      </p>
    </div>

    ${akce.length === 0 ? emptyState({
      icon:'📑', title:'Žádné akce',
      msg:'Nejdřív vytvoř catering akci v záložce Firemní objednávky, pak zde můžeš generovat PDF.',
      actions:'<button class="btn-primary btn-green" onclick="state._catTab=\'orders\';renderCateringPage()">→ Firemní objednávky</button>',
    }) : `
      <div class="card-block" style="padding:0;overflow:hidden">
        <table style="width:100%;border-collapse:collapse">
          <thead style="background:var(--surface-2);font-size:11px;text-transform:uppercase;color:var(--text-3);font-weight:700">
            <tr>
              <th style="text-align:left;padding:10px 14px">Akce</th>
              <th style="text-align:left;padding:10px 14px">Datum</th>
              <th style="text-align:left;padding:10px 14px">Zákazník</th>
              <th style="text-align:right;padding:10px 14px">Cena</th>
              <th style="text-align:right;padding:10px 14px;min-width:280px">PDF dokumenty</th>
            </tr>
          </thead>
          <tbody>
            ${akce.map(a => `
              <tr style="border-top:1px solid var(--border);font-size:13px">
                <td style="padding:9px 14px;font-weight:600">${esc(a.nazev)}</td>
                <td style="padding:9px 14px">${new Date(a.datum_akce).toLocaleDateString('cs-CZ')}</td>
                <td style="padding:9px 14px">
                  ${esc(a.zakaznik)}
                  ${a.ico ? `<div style="font-size:11px;color:var(--text-3)">IČO: ${esc(a.ico)}</div>` : ''}
                </td>
                <td style="padding:9px 14px;text-align:right;font-weight:600;font-variant-numeric:tabular-nums">${fmt(a.castka_celkem)}</td>
                <td style="padding:9px 14px;text-align:right;white-space:nowrap">
                  <a href="../api/admin_catering_pdf.php?id=${a.id}&type=nabidka" target="_blank" class="btn-secondary" style="font-size:11px;padding:5px 9px;text-decoration:none">📋 Nabídka</a>
                  <a href="../api/admin_catering_pdf.php?id=${a.id}&type=smlouva" target="_blank" class="btn-secondary" style="font-size:11px;padding:5px 9px;text-decoration:none">📑 Smlouva</a>
                  <a href="../api/admin_catering_pdf.php?id=${a.id}&type=zaloha_fa" target="_blank" class="btn-primary btn-green" style="font-size:11px;padding:5px 9px;text-decoration:none">🧾 Záloha FA</a>
                </td>
              </tr>
            `).join('')}
          </tbody>
        </table>
      </div>
    `}
  `;
}

