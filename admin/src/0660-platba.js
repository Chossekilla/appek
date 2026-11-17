// =============================================================
// 🧾 PLATBA
// =============================================================
// 💱 v3.0.643 — platba účtu v Kč nebo v cizí měně (Nastavení → Přístupy, ceny & měna)
window._posPlatbaInfo = null;
window.posPlatbaMena = 'CZK';
async function posPlatbaInfoLoad() {
  try { window._posPlatbaInfo = await api('admin_pos.php?action=platba_info&kanal=stul'); } catch (e) { window._posPlatbaInfo = null; }
  const pi = window._posPlatbaInfo;
  if (!pi || !pi.aktivni || !(pi.prijimane || []).includes(window.posPlatbaMena)) window.posPlatbaMena = (pi && pi.aktivni && pi.vychozi) || 'CZK';
  return pi;
}
window.posSetPlatbaMena = function(m) { window.posPlatbaMena = m; posPaymentDialog(); };
const posMenaSym = (m) => ({ CZK: 'Kč', EUR: '€', USD: '$', GBP: '£', PLN: 'zł', HUF: 'Ft', CHF: 'CHF' })[m] || m;
window.posPaymentDialog = async function() {
  const u = posState.currentUcet;
  const totalKc = (u.polozky || []).filter(p => p.stav !== 'storno').reduce((s, p) => s + p.jednotkova_cena * p.mnozstvi, 0);
  const pi = window._posPlatbaInfo || await posPlatbaInfoLoad();
  const mena = (pi && pi.aktivni) ? window.posPlatbaMena : 'CZK';
  const inf = pi && pi.meny ? pi.meny[mena] : null;
  const cizi = mena !== 'CZK' && inf && inf.kurz_prodej;
  // přesná částka v měně = server (po řádcích jako doklad); záložně náhled Kč / kurz
  let total = totalKc, presne = !cizi;
  if (cizi) {
    try { const q = await api(`admin_pos.php?action=ucet_castka&ucet_id=${u.id}&mena=${encodeURIComponent(mena)}`); total = +q.celkem; presne = true; }
    catch (e) { total = Math.round(totalKc / inf.kurz_prodej * 100) / 100; }
  }
  const menaBar = (pi && pi.aktivni && (pi.prijimane || []).length > 1) ? `<div style="display:flex;gap:6px;justify-content:center;align-items:center;flex-wrap:wrap">
      <span style="font-size:12px;color:var(--text-3);font-weight:600">Měna platby:</span>
      ${(pi.prijimane || []).map(m => { const i = pi.meny[m] || {}; const dis = m !== 'CZK' && i.ok === false;
        return `<button class="${mena === m ? 'btn-primary' : 'btn-secondary'}" style="padding:6px 14px" ${dis ? 'disabled title="' + esc(i.chyba || 'Kurz není k dispozici') + '"' : ''} onclick="posSetPlatbaMena('${m}')">${m === 'CZK' ? 'Kč' : esc(posMenaSym(m)) + ' ' + m}</button>`; }).join('')}
      ${cizi ? `<span style="font-size:11.5px;color:var(--text-3)">1 ${esc(posMenaSym(mena))} = ${(+inf.kurz_prodej).toLocaleString('cs-CZ', { maximumFractionDigits: 3 })} Kč</span>` : ''}
    </div>` : '';
  openModal('🧾 Platba účtu #' + u.id, `
    <div style="display:flex;flex-direction:column;gap:12px">
      ${menaBar}
      <div style="text-align:center;padding:16px;background:linear-gradient(135deg,#FFF8E7,#FEF3C7);border-radius:10px">
        <div style="font-size:11px;color:#854F0B;text-transform:uppercase;font-weight:700">K platbě</div>
        <div style="font-size:36px;font-weight:800;color:#1d1d1f;line-height:1">${cizi ? (presne ? '' : '≈ ') + total.toLocaleString('cs-CZ', { minimumFractionDigits: 2, maximumFractionDigits: 2 }) + ' ' + esc(posMenaSym(mena)) : totalKc.toFixed(2) + ' Kč'}</div>
        ${cizi ? `<div style="font-size:11px;color:#92400e;margin-top:2px">${totalKc.toFixed(2)} Kč${presne ? '' : ' · přesnou částku v ' + esc(mena) + ' spočítá pokladna při zaplacení'}</div>` : ''}
        <div style="font-size:11px;color:#92400e;margin-top:4px">${(u.polozky || []).filter(p => p.stav !== 'storno').length} položek</div>
      </div>

      <h4 style="font-size:13px;color:var(--text-3);text-transform:uppercase;letter-spacing:0.5px;margin-bottom:-4px">Způsob platby</h4>
      <div style="display:grid;grid-template-columns:1fr 1fr;gap:6px">
        ${[
          ['hotovost', '💵 Hotovost', '#10B981'],
          ['karta',    '💳 Karta',    '#3B82F6'],
          ['qr',       '📲 QR / Apple Pay', '#8B5CF6'],
          ['poukaz',   '🎁 Poukaz',   '#F59E0B'],
        ].map(([k, l, c]) => `
          <button onclick="posPaySingle(${cizi ? 'null' : total.toFixed(2)}, '${k}')"
                  style="padding:14px;border-radius:10px;border:2px solid ${c};background:#fff;color:${c};font-weight:700;font-size:13px;cursor:pointer">
            ${l}
          </button>
        `).join('')}
      </div>

      <div style="border-top:1px solid var(--border);padding-top:10px">
        <h4 style="font-size:13px;color:var(--text-3);text-transform:uppercase;letter-spacing:0.5px;margin-bottom:8px">✂️ Více plátců (rozdělit částku)</h4>
        <button class="btn-secondary" onclick="posSplitPayDialog(${total})" style="width:100%;padding:10px">Rozdělit platbu na více…</button>
        ${cizi ? `<div style="font-size:11.5px;color:var(--text-3);margin-top:6px">Při rozdělení platí všichni v ${esc(mena)}. Když host platí část v Kč a část v ${esc(posMenaSym(mena))}, rozdělte účet (✂️) a každá část zaplatí zvlášť.</div>` : ''}
      </div>
    </div>
  `);
};

window.posPaySingle = async function(amount, zpusob) {
  const u = posState.currentUcet;
  const pi = window._posPlatbaInfo;
  const mena = (pi && pi.aktivni) ? window.posPlatbaMena : 'CZK';
  const inf = pi && pi.meny ? pi.meny[mena] : null;
  try {
    // 💱 cizí měna: bez castky → server spočítá přesnou částku účtu v měně (= doklad/účtenka)
    const body = (mena !== 'CZK')
      ? { ucet_id: u.id, payment: zpusob, mena, kurz_klient: inf ? inf.kurz_prodej : undefined, station_token: (localStorage.getItem('appek_station_token') || '') }
      : { ucet_id: u.id, platby: [{ castka: amount, zpusob }], ...((pi && pi.aktivni) ? { mena: 'CZK' } : {}), station_token: (localStorage.getItem('appek_station_token') || '') };
    const r = await api('admin_pos.php?action=pay', { method: 'POST', body: JSON.stringify(body) });
    // 🆕 v3.0.189 — server účet uzavřel (stav=paid) + uvolnil stůl. Nabídni tisk (ne auto-tisk).
    posPaidPrintPrompt(u.id, mena !== 'CZK' ? r.celkem_mena : amount, mena);
  } catch (e) { alert('Chyba: ' + e.message); if (/kurz/i.test(e.message || '')) posPlatbaInfoLoad(); }
};

// 🆕 v3.0.189 — „Zaplaceno → Vytisknout účtenku? Ano/Ne". Obojí účet zavře a osvěží plán;
//   Ano navíc otevře tiskovou účtenku. (User: „zaplatit a zavřít · vytisknout ano/ne".)
window.posPaidPrintPrompt = function(ucetId, amount, mena) {
  openModal('✅ Zaplaceno', `
    <div style="text-align:center;padding:8px 0 2px">
      <div style="font-size:46px;line-height:1">✅</div>
      <div style="font-size:26px;font-weight:800;margin:8px 0 2px;color:#15803d">${(+amount).toLocaleString('cs-CZ', { minimumFractionDigits: 2, maximumFractionDigits: 2 })} ${mena && mena !== 'CZK' ? esc(posMenaSym(mena)) : 'Kč'}</div>
      <div style="color:var(--text-3);font-size:13px">Účet uzavřen · stůl uvolněn</div>
    </div>
    <p style="text-align:center;font-weight:700;margin:16px 0 8px">Vytisknout účtenku?</p>
    <div style="display:flex;gap:8px">
      <button class="btn-primary btn-green" style="flex:1;padding:15px;font-weight:800;font-size:15px" onclick="posFinishPaid(${ucetId}, true)">🖨️ Ano, tisk</button>
      <button class="btn-secondary" style="flex:1;padding:15px;font-weight:800;font-size:15px" onclick="posFinishPaid(${ucetId}, false)">Ne, hotovo</button>
    </div>
  `);
};
window.posFinishPaid = function(ucetId, doPrint) {
  closeModal();
  if (doPrint) window.open(`../api/admin_pos_print.php?ucet_id=${ucetId}&typ=ucet&autoprint=1`, '_blank', 'width=400,height=700');
  posState.currentUcet = null;
  renderRestaurantTables();
};

window.posSplitPayDialog = function(total) {
  openModal('🧾 Rozdělit platbu', `
    <p style="font-size:13px;color:var(--text-3);margin-bottom:10px">Přidej platby — součet musí být <strong>${total.toFixed(2)} ${esc(posMenaSym(((window._posPlatbaInfo || {}).aktivni ? window.posPlatbaMena : 'CZK')))}</strong></p>
    <div id="pos-split-rows" style="display:flex;flex-direction:column;gap:6px"></div>
    <button class="btn-secondary" onclick="posSplitAddRow()" style="margin-top:8px;width:100%">+ Přidat platbu</button>
    <div style="display:flex;justify-content:space-between;font-weight:700;font-size:14px;padding:10px;background:#FFF8E7;border-radius:8px;margin-top:10px">
      <span>Součet:</span>
      <span id="pos-split-sum">0,00 Kč</span>
    </div>
    <button class="btn-primary btn-green" onclick="posSubmitSplitPay(${total})" style="width:100%;margin-top:10px;padding:14px;font-weight:700">🧾 Zaplatit</button>
  `);
  posSplitAddRow();
  posSplitAddRow();
};

window.posSplitAddRow = function() {
  const rows = document.getElementById('pos-split-rows');
  if (!rows) return;
  const row = document.createElement('div');
  row.style.cssText = 'display:flex;gap:6px;align-items:center';
  row.innerHTML = `
    <input type="number" class="form-input pos-split-amount" placeholder="Částka" step="0.01" min="0" oninput="posSplitRecalc()" style="flex:1">
    <select class="form-input pos-split-zpusob" style="width:120px">
      <option value="hotovost">💵 Hotovost</option>
      <option value="karta">💳 Karta</option>
      <option value="qr">📲 QR</option>
      <option value="poukaz">🎁 Poukaz</option>
    </select>
    <button class="btn-secondary" onclick="this.parentElement.remove();posSplitRecalc()" style="padding:6px 10px">✕</button>
  `;
  rows.appendChild(row);
};

window.posSplitRecalc = function() {
  const sum = Array.from(document.querySelectorAll('.pos-split-amount')).reduce((s, i) => s + (parseFloat(i.value) || 0), 0);
  const el = document.getElementById('pos-split-sum');
  if (el) el.textContent = sum.toFixed(2) + ' ' + posMenaSym(((window._posPlatbaInfo || {}).aktivni ? window.posPlatbaMena : 'CZK'));
};

window.posSubmitSplitPay = async function(total) {
  const platby = [];
  document.querySelectorAll('#pos-split-rows > div').forEach(row => {
    const amt = parseFloat(row.querySelector('.pos-split-amount').value);
    const z = row.querySelector('.pos-split-zpusob').value;
    if (amt > 0) platby.push({ castka: amt, zpusob: z });
  });
  const sum = platby.reduce((s, p) => s + p.castka, 0);
  if (Math.abs(sum - total) > 0.01) {
    if (!(await confirmDialog({ msg: t('confirm_sum_mismatch', { sum: sum.toFixed(2), total: total.toFixed(2) }), danger: false }))) return;
  }
  const u = posState.currentUcet;
  const pi = window._posPlatbaInfo;
  const mena = (pi && pi.aktivni) ? window.posPlatbaMena : 'CZK';
  try {
    const r = await api('admin_pos.php?action=pay', {
      method: 'POST', body: JSON.stringify({ ucet_id: u.id, platby: platby.map(p => ({ ...p, mena })), ...(mena !== 'CZK' ? { mena, kurz_klient: (pi.meny[mena] || {}).kurz_prodej } : ((pi && pi.aktivni) ? { mena: 'CZK' } : {})), station_token: (localStorage.getItem('appek_station_token') || '') }),
    });
    // 🆕 v3.0.189 — sjednoceno s posPaySingle: nabídni tisk + zavři.
    posPaidPrintPrompt(u.id, mena !== 'CZK' ? r.celkem_mena : sum, mena);
  } catch (e) { alert('Chyba: ' + e.message); }
};

