// =============================================================
// HMOTNOST TĚSTA — výpočet z surovin + přepočet receptury
// =============================================================
function vyTestoSpocitatHmotnostKg() {
  // Sečte všechny suroviny převoditelné na hmotnost (g, kg, ml, l)
  // ks / lžíce / lžička ignoruje
  let totalG = 0;
  document.querySelectorAll('#vy-sloz-rows .sloz-row').forEach(r => {
    const mn = parseFloat(r.querySelector('.sloz-mn')?.value) || 0;
    const jed = (r.querySelector('.sloz-jed')?.value || 'g').toLowerCase();
    if (mn <= 0) return;
    if (jed === 'g')  totalG += mn;
    else if (jed === 'kg') totalG += mn * 1000;
    else if (jed === 'ml') totalG += mn; // předpoklad ρ ≈ 1 (voda, mléko, olej cca)
    else if (jed === 'l')  totalG += mn * 1000;
  });
  return totalG / 1000; // → kg
}

window.vyTestoUpdateDisplay = function() {
  const el = document.getElementById('vy-testo-aktualni');
  if (!el) return;
  const kg = vyTestoSpocitatHmotnostKg();
  if (kg === 0) {
    el.textContent = '— kg';
    el.style.color = 'var(--text-3)';
  } else {
    el.textContent = kg.toFixed(kg >= 10 ? 2 : 3).replace(/\.?0+$/, '').replace('.', ',') + ' kg';
    el.style.color = '#854F0B';
  }
};

window.vyTestoPrepocitat = async function() {
  const targetEl = document.getElementById('vy-testo-target');
  const target = parseFloat(targetEl?.value) || 0;
  if (target <= 0) {
    targetEl?.focus();
    return alert('Zadej cílovou hmotnost těsta v kg.');
  }
  const aktualni = vyTestoSpocitatHmotnostKg();
  if (aktualni === 0) return alert('Aktuální receptura nemá žádnou váženou surovinu (g/kg/ml/l).\n\nDoplň alespoň jednu surovinu s množstvím.');

  const mult = target / aktualni;
  {
    const fromStr = aktualni.toFixed(3).replace(/\.?0+$/, '').replace('.', ',');
    const multStr = mult.toFixed(3).replace(/\.?0+$/, '').replace('.', ',');
    if (!(await confirmDialog({ msg: t('confirm_recalc_recipe', { from: fromStr, to: target, mult: multStr }), danger: false }))) return;
  }

  document.querySelectorAll('#vy-sloz-rows .sloz-row').forEach(r => {
    const mnEl = r.querySelector('.sloz-mn');
    if (!mnEl) return;
    const mn = parseFloat(mnEl.value) || 0;
    if (mn === 0) return;
    const novy = mn * mult;
    // Zaokrouhlení dle velikosti
    let dec = 3;
    if (novy >= 100) dec = 1;
    else if (novy >= 10) dec = 2;
    mnEl.value = novy.toFixed(dec).replace(/\.?0+$/, '');
  });
  vyTestoUpdateDisplay();
  // Reset target
  if (targetEl) targetEl.value = '';

  // 🐛 v3.0.158 — POZOR: NEpoužívat zde `const t` — stínilo by globální i18n t() použité
  //   výše v confirm(t('confirm_recalc_recipe',…)) → TDZ ReferenceError → přepočet spadl.
  const toastEl = document.createElement('div');
  toastEl.style.cssText = 'position:fixed;bottom:24px;right:24px;background:var(--success-bg);color:var(--success-text);padding:12px 18px;border-radius:8px;box-shadow:0 4px 12px rgba(0,0,0,.15);font-size:13px;font-weight:600;z-index:9999';
  toastEl.textContent = `✓ Přepočítáno na ${target} kg těsta (×${mult.toFixed(3).replace(/\.?0+$/, '').replace('.', ',')})`;
  document.body.appendChild(toastEl);
  setTimeout(() => toastEl.remove(), 2500);

  // 🆕 v3.0.158 — po přepočtu obnov kalkulaci nákladů (jinak zůstane stará/nulová)
  if (document.querySelector('#vy-kalkulace-out table')) { try { vyKalkulace(); } catch (e) {} }
};

window.vyCollectSlozeni = function() {
  const rows = document.querySelectorAll('#vy-sloz-rows .sloz-row');
  const out = [];
  let i = 0;
  rows.forEach(r => {
    const polSel = r.querySelector('.sloz-pol');  // 🆕 v3.0.303 — řádek typu POLOTOVAR (výrobek)
    if (polSel) {
      const vid = parseInt(polSel.value) || 0;
      if (!vid) return;
      out.push({
        slozka_vyrobek_id: vid,
        mnozstvi: parseFloat(r.querySelector('.sloz-mn').value) || 0,
        jednotka: r.querySelector('.sloz-jed').value || 'ks',
        poradi: i++,
        poznamka: (r.querySelector('.sloz-pozn')?.value || '').trim() || null,
      });
      return;
    }
    const sid = parseInt(r.querySelector('.sloz-sur').value) || 0;
    if (!sid) return;
    out.push({
      surovina_id: sid,
      mnozstvi: parseFloat(r.querySelector('.sloz-mn').value) || 0,
      jednotka: r.querySelector('.sloz-jed').value || 'g',
      poradi: i++,
      poznamka: r.querySelector('.sloz-pozn').value.trim() || null,
    });
  });
  return out;
};

// 🆕 v3.0.303 — přidá řádek POLOTOVARU (jiný výrobek) do receptury (sestava/BOM).
window.vySlozeniAddPolotovar = function(slozka_id = '', mnozstvi = '', jednotka = 'ks', slozka_nazev = '') {
  const c = document.getElementById('vy-sloz-rows');
  if (!c) return;
  let vyr = (state._vyrobky_pol_cache || []).filter(x => x.id != (state._vyEditId || 0)); // ne sám sebe
  // fallback: cache ještě nedorazila / položka chybí → přidej aktuální vybranou (z detailu)
  if (slozka_id && slozka_nazev && !vyr.some(x => x.id == slozka_id)) {
    vyr = [{ id: slozka_id, nazev: slozka_nazev, je_polotovar: 1 }, ...vyr];
  }
  const row = document.createElement('div');
  row.className = 'sloz-row';
  row.style.cssText = 'display:grid;grid-template-columns:2fr 1fr 1fr 1.5fr auto;gap:8px;margin-bottom:6px;align-items:center';
  row.innerHTML = `
    <select class="form-select sloz-pol" style="font-size:13px;border-color:#9333EA">
      <option value="">— polotovar / výrobek —</option>
      ${vyr.map(x => `<option value="${x.id}" ${x.id == slozka_id ? 'selected' : ''}>${x.je_polotovar ? '🧩 ' : '📦 '}${esc(x.nazev)}</option>`).join('')}
    </select>
    <input class="form-input sloz-mn" type="number" step="0.001" min="0" placeholder="Množ." value="${mnozstvi}" style="font-size:13px" oninput="vyTestoUpdateDisplay()">
    <select class="form-select sloz-jed" style="font-size:13px">
      ${['ks','g','kg','ml','l','porce','dávka'].map(j => `<option value="${j}" ${j===jednotka?'selected':''}>${j}</option>`).join('')}
    </select>
    <span style="font-size:11px;color:#9333EA">🧩 polotovar (sestava)</span>
    <button class="btn-secondary" type="button" style="padding:6px 10px;font-size:13px" onclick="this.parentElement.remove();vyTestoUpdateDisplay()">✕</button>
  `;
  c.appendChild(row);
  vyTestoUpdateDisplay();
};

