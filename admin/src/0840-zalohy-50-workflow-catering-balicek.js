// =============================================================
// 🎯 ZÁLOHY 50% workflow (Catering balíček)
// =============================================================
async function renderCateringDeposits() {
  const body = document.getElementById('cat-tab-body');
  if (!body) return;
  body.innerHTML = skeletonCards(3);
  let data;
  try { data = await api('admin_catering_orders.php'); }
  catch (e) { body.innerHTML = `<div class="alert err">${esc(e.message)}</div>`; return; }

  const akce = data.akce || [];
  const akcePotrebujiZalohu = akce.filter(a => a.stav !== 'zruseno' && a.stav !== 'dokonceno' && parseFloat(a.zaloha_kc) > 0);
  const zalohaUhrazeno = akcePotrebujiZalohu.filter(a => parseInt(a.zaloha_uhrazena) === 1);
  const cekajiNaZalohu  = akcePotrebujiZalohu.filter(a => parseInt(a.zaloha_uhrazena) === 0);

  const sumOcekavaneZalohy   = cekajiNaZalohu.reduce((s, a) => s + parseFloat(a.zaloha_kc), 0);
  const sumUhrazenychZaloh   = zalohaUhrazeno.reduce((s, a) => s + parseFloat(a.zaloha_kc), 0);

  body.innerHTML = `
    <div style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:10px;margin-bottom:14px">
      <div class="card-block" style="text-align:center">
        <div style="font-size:11px;color:var(--text-3);text-transform:uppercase;font-weight:700">⏳ Čekají na zálohu</div>
        <strong style="font-size:24px;color:#92400E;display:block;margin:6px 0">${cekajiNaZalohu.length}</strong>
        <div style="font-size:12px;color:var(--text-3)">akcí, suma: <strong>${fmt(sumOcekavaneZalohy)}</strong></div>
      </div>
      <div class="card-block" style="text-align:center">
        <div style="font-size:11px;color:var(--text-3);text-transform:uppercase;font-weight:700">✅ Záloha uhrazena</div>
        <strong style="font-size:24px;color:#166534;display:block;margin:6px 0">${zalohaUhrazeno.length}</strong>
        <div style="font-size:12px;color:var(--text-3)">akcí, suma: <strong>${fmt(sumUhrazenychZaloh)}</strong></div>
      </div>
      <div class="card-block" style="text-align:center">
        <div style="font-size:11px;color:var(--text-3);text-transform:uppercase;font-weight:700">🧾 Celkem v procesu</div>
        <strong style="font-size:24px;color:#1E40AF;display:block;margin:6px 0">${akcePotrebujiZalohu.length}</strong>
        <div style="font-size:12px;color:var(--text-3)">akcí, celkem: <strong>${fmt(sumOcekavaneZalohy + sumUhrazenychZaloh)}</strong></div>
      </div>
    </div>

    ${cekajiNaZalohu.length > 0 ? `
      <div class="card-block" style="margin-bottom:14px;border-left:4px solid #F59E0B">
        <h3 style="margin:0 0 10px;font-size:15px">⏳ Čekající na úhradu zálohy (${cekajiNaZalohu.length})</h3>
        <div style="display:flex;flex-direction:column;gap:6px">
          ${cekajiNaZalohu.map(a => `
            <div style="display:grid;grid-template-columns:1fr auto auto auto;gap:10px;align-items:center;background:#FEF3C7;padding:10px 14px;border-radius:8px">
              <div>
                <strong style="font-size:13.5px">${esc(a.nazev)}</strong>
                <div style="font-size:11px;color:#92400E">${esc(a.zakaznik)} · 📅 ${new Date(a.datum_akce).toLocaleDateString('cs-CZ')} · 👥 ${a.osob}</div>
              </div>
              <div style="text-align:right">
                <div style="font-size:10px;color:#92400E;font-weight:700;text-transform:uppercase">Záloha 50%</div>
                <strong style="font-size:15px;color:#92400E">${fmt(a.zaloha_kc)}</strong>
              </div>
              <a href="../api/admin_catering_pdf.php?id=${a.id}&type=zaloha_fa" target="_blank" class="btn-secondary" style="font-size:11px;padding:5px 9px;text-decoration:none">📄 FA</a>
              <button class="btn-primary btn-green" style="font-size:12px;padding:6px 10px" onclick="cateringMarkDepositPaid(${a.id})">✓ Uhrazeno</button>
            </div>
          `).join('')}
        </div>
      </div>
    ` : ''}

    ${zalohaUhrazeno.length > 0 ? `
      <div class="card-block">
        <h3 style="margin:0 0 10px;font-size:15px">✅ Zálohy uhrazené (${zalohaUhrazeno.length})</h3>
        <div style="display:flex;flex-direction:column;gap:6px">
          ${zalohaUhrazeno.map(a => {
            const doplatek = parseFloat(a.castka_celkem) - parseFloat(a.zaloha_kc);
            const dopUhrazen = parseInt(a.doplatek_uhrazen) === 1;
            return `
              <div style="display:grid;grid-template-columns:1fr auto auto auto;gap:10px;align-items:center;background:#DCFCE7;padding:10px 14px;border-radius:8px">
                <div>
                  <strong style="font-size:13.5px">${esc(a.nazev)}</strong>
                  <div style="font-size:11px;color:#166534">${esc(a.zakaznik)} · 📅 ${new Date(a.datum_akce).toLocaleDateString('cs-CZ')}</div>
                  ${a.zaloha_uhrazena_dne ? `<div style="font-size:10.5px;color:#166534;opacity:0.8">Záloha uhr. ${new Date(a.zaloha_uhrazena_dne).toLocaleDateString('cs-CZ')}</div>` : ''}
                </div>
                <div style="text-align:right">
                  <div style="font-size:10px;color:#166534;font-weight:700;text-transform:uppercase">Doplatek</div>
                  <strong style="font-size:14px;color:#166534">${fmt(doplatek)}</strong>
                </div>
                <span style="background:${dopUhrazen?'#86efac':'#FED7AA'};color:${dopUhrazen?'#065F46':'#9A3412'};padding:3px 10px;border-radius:999px;font-size:10.5px;font-weight:700">${dopUhrazen ? '✓ Doplatek OK' : '⏳ Doplatek'}</span>
                ${!dopUhrazen ? `<button class="btn-primary" style="font-size:12px;padding:6px 10px" onclick="cateringMarkFinalPaid(${a.id})">✓ Doplatek OK</button>` : '<span></span>'}
              </div>
            `;
          }).join('')}
        </div>
      </div>
    ` : ''}

    ${cekajiNaZalohu.length === 0 && zalohaUhrazeno.length === 0 ? emptyState({
      icon:'🎯', title:'Žádné akce se zálohou',
      msg:'Akce, které mají nastavenou částku zálohy se zde zobrazí. Záloha je výchozí 50 % z celkové ceny.',
      actions:'<button class="btn-primary btn-green" onclick="state._catTab=\'orders\';renderCateringPage()">→ Vytvořit akci</button>',
    }) : ''}
  `;
}

window.cateringMarkDepositPaid = async function(id) {
  try {
    await api('admin_catering_orders.php?id=' + id, { method:'PUT', body: JSON.stringify({
      zaloha_uhrazena: 1,
      zaloha_uhrazena_dne: new Date().toISOString().slice(0, 10),
      stav: 'zaloha_uhraz',
    })});
    toastSuccess('Záloha označena jako uhrazená');
    renderCateringDeposits();
  } catch (e) { alert('Chyba: ' + e.message); }
};

window.cateringMarkFinalPaid = async function(id) {
  try {
    await api('admin_catering_orders.php?id=' + id, { method:'PUT', body: JSON.stringify({
      doplatek_uhrazen: 1,
      stav: 'dokonceno',
    })});
    toastSuccess('Doplatek uhrazen — akce dokončena');
    renderCateringDeposits();
  } catch (e) { alert('Chyba: ' + e.message); }
};

