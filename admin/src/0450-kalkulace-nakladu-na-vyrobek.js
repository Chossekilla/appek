// =============================================================
// KALKULACE NÁKLADŮ NA VÝROBEK
// =============================================================
window.vyKalkulace = async function() {
  const rows = document.querySelectorAll('#vy-sloz-rows .sloz-row');
  await loadSurovinyCache();
  const sur = state._suroviny_cache || [];
  const idx = Object.fromEntries(sur.map(s => [s.id, s]));

  // Cena suroviny převedená na shodnou jednotku jako receptur
  const cenaSurovin = (sId, mn, jed) => {
    const s = idx[sId];
    if (!s) return { cena: 0, problem: 'Surovina nenalezena' };
    const cb = parseFloat(s.cena_baleni) || 0;
    const ob = parseFloat(s.obsah_baleni) || 0;
    if (cb <= 0 || ob <= 0) return { cena: 0, problem: 'Bez ceny' };
    const cenaZaJed = cb / ob; // Kč za 1 jednotku surovny (s.jednotka)
    // Převod jednotek mezi recipe (jed) a surovina (s.jednotka)
    const norm = (j) => (j || 'g').toLowerCase();
    const sJ = norm(s.jednotka);
    const rJ = norm(jed);
    const conv = {
      // Hmotnost (g jako bázová)
      g: { g: 1, kg: 1000 },
      kg: { g: 0.001, kg: 1 },
      // Objem
      ml: { ml: 1, l: 1000 },
      l: { ml: 0.001, l: 1 },
      // Kusy
      ks: { ks: 1 },
    };
    const c = conv[sJ]?.[rJ];
    if (c === undefined) {
      // Nekonvertibilní (např. lžička <-> g) — předpokládáme stejnou jednotku
      if (sJ === rJ) return { cena: cenaZaJed * mn };
      return { cena: 0, problem: `Nelze převést ${rJ} → ${sJ}` };
    }
    return { cena: cenaZaJed * c * mn };
  };

  const polozky = [];
  let suma = 0;
  let problem_count = 0;
  rows.forEach(r => {
    const sid = parseInt(r.querySelector('.sloz-sur')?.value) || 0;
    const mn  = parseFloat(r.querySelector('.sloz-mn')?.value) || 0;
    const jed = r.querySelector('.sloz-jed')?.value || 'g';
    if (!sid || mn <= 0) return;
    const s = idx[sid];
    const r1 = cenaSurovin(sid, mn, jed);
    if (r1.problem) problem_count++;
    suma += r1.cena;
    polozky.push({
      nazev: s?.nazev || '?',
      mn, jed,
      cenaJed: s ? (parseFloat(s.cena_baleni)||0) / (parseFloat(s.obsah_baleni)||1) : 0,
      sJed: s?.jednotka || 'g',
      celkem: r1.cena,
      problem: r1.problem || null,
      // 🆕 v2.9.279 — Composite ingredient flag (např. Diasauer = mouka+sůl+kyselina)
      // Cena je z balení kompozitní suroviny (přesná), ale uživatel ví že je to směs
      kompozitni: !!(s?.slozeni && String(s.slozeni).trim().length > 5),
      slozeniText: s?.slozeni || null,
    });
  });

  // Fixní náklady z nastavení (energie, práce, obal)
  let fixni = [];
  try {
    const ns = await api('admin_nastaveni.php');
    const fixData = ns.naklady_polozky;
    if (fixData) {
      try { fixni = JSON.parse(fixData) || []; } catch (e) {}
    }
  } catch (e) {}
  let fixniSum = 0;
  fixni.forEach(f => { fixniSum += parseFloat(f.cena_kc) || 0; });

  const naklady = suma + fixniSum;
  const cenaProdej = parseFloat(document.getElementById('vy-cena')?.value) || 0;
  const dphSel = document.getElementById('vy-dph');
  const sazbaDph = dphSel ? parseFloat(dphSel.options[dphSel.selectedIndex]?.dataset.sazba) || 12 : 12;
  const cenaProdejSDph = cenaProdej * (1 + sazbaDph / 100);
  const marze = cenaProdej - naklady;
  const marzePct = naklady > 0 ? (marze / naklady) * 100 : 0;
  const koef = naklady > 0 ? cenaProdej / naklady : 0;

  const fmtKc = (n) => n.toFixed(2).replace('.', ',') + ' Kč';
  const fmtKcDetail = (n) => n.toFixed(4).replace(/\.?0+$/, '').replace('.', ',') + ' Kč';

  const out = document.getElementById('vy-kalkulace-out');
  if (!out) return;
  out.innerHTML = `
    ${polozky.length === 0 ? `
      <p style="color:var(--text-3);font-size:13px;margin:0">Nejdřív přidej suroviny do složení a zkontroluj, že u každé je nastavená nákupní cena (v <a href="javascript:closeModal();navigate('suroviny')" style="color:#1E40AF">Suroviny</a>).</p>
    ` : `
      <table class="table" style="margin:0;font-size:13px">
        <thead>
          <tr>
            <th>Surovina</th>
            <th class="num">Množství</th>
            <th class="num">Cena za jed.</th>
            <th class="num">Náklad</th>
          </tr>
        </thead>
        <tbody>
          ${polozky.map(p => `
            <tr ${p.problem ? 'style="background:#FEF3C7"' : ''}>
              <td>
                ${esc(p.nazev)}
                ${p.kompozitni ? `<span title="🧬 Kompozitní surovina — má vlastní složení: ${esc((p.slozeniText || '').slice(0, 100))}" style="margin-left:6px;color:#7c3aed;font-size:12px;cursor:help;font-weight:500">🧬</span>` : ''}
                ${p.problem ? `<div style="font-size:11px;color:#92400e">⚠ ${esc(p.problem)}</div>` : ''}
                ${p.kompozitni ? `<div style="font-size:10px;color:#7c3aed;margin-top:2px;font-style:italic">Cena z balení (kompozitní)</div>` : ''}
              </td>
              <td class="num">${p.mn} ${esc(p.jed)}</td>
              <td class="num">${p.cenaJed > 0 ? `${fmtKcDetail(p.cenaJed)} / ${esc(p.sJed)}` : '—'}</td>
              <td class="num"><strong>${fmtKc(p.celkem)}</strong></td>
            </tr>
          `).join('')}
        </tbody>
        <tfoot>
          <tr style="border-top:2px solid var(--border)">
            <td colspan="3"><strong>Σ Suroviny</strong></td>
            <td class="num"><strong>${fmtKc(suma)}</strong></td>
          </tr>
          ${fixni.length > 0 ? fixni.map(f => `
            <tr>
              <td colspan="3" style="color:var(--text-3)">+ ${esc(f.nazev || 'Fixní náklad')}</td>
              <td class="num">${fmtKc(parseFloat(f.cena_kc) || 0)}</td>
            </tr>
          `).join('') : ''}
          <tr style="background:#DBEAFE">
            <td colspan="3"><strong>NÁKLADY CELKEM</strong></td>
            <td class="num" style="font-size:15px"><strong>${fmtKc(naklady)}</strong></td>
          </tr>
        </tfoot>
      </table>

      <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(150px,1fr));gap:8px;margin-top:14px">
        <div style="background:white;border:1px solid var(--border);border-radius:8px;padding:10px 12px">
          <div style="font-size:11px;color:var(--text-3);text-transform:uppercase;letter-spacing:0.4px">Prodejní cena</div>
          <div style="font-size:18px;font-weight:700;color:#854F0B">${fmtKc(cenaProdej)}</div>
          <div style="font-size:11px;color:var(--text-3)">bez DPH · s DPH ${fmtKc(cenaProdejSDph)}</div>
        </div>
        <div style="background:white;border:1px solid var(--border);border-radius:8px;padding:10px 12px">
          <div style="font-size:11px;color:var(--text-3);text-transform:uppercase;letter-spacing:0.4px">Marže (Kč)</div>
          <div style="font-size:18px;font-weight:700;color:${marze > 0 ? '#22863a' : '#dc2626'}">${marze >= 0 ? '+' : ''}${fmtKc(marze)}</div>
          <div style="font-size:11px;color:var(--text-3)">prodej − náklady</div>
        </div>
        <div style="background:white;border:1px solid var(--border);border-radius:8px;padding:10px 12px">
          <div style="font-size:11px;color:var(--text-3);text-transform:uppercase;letter-spacing:0.4px">Marže (%)</div>
          <div style="font-size:18px;font-weight:700;color:${marzePct > 0 ? '#22863a' : '#dc2626'}">${marzePct.toFixed(1).replace('.', ',')}%</div>
          <div style="font-size:11px;color:var(--text-3)">koef. ${koef.toFixed(2).replace('.', ',')}×</div>
        </div>
      </div>

      ${problem_count > 0 ? `
        <div style="margin-top:10px;padding:8px 12px;background:#FEF3C7;border-radius:6px;font-size:12px;color:#92400e">
          ⚠ ${problem_count} surovin nemá platnou cenu nebo nelze převést jednotky. Doplň ceny v sekci Suroviny.
        </div>
      ` : ''}
    `}
  `;
};

window.vyOdvoditSlozeniText = function() {
  const rows = document.querySelectorAll('#vy-sloz-rows .sloz-row');
  const sur = state._suroviny_cache || [];
  const indexById = Object.fromEntries(sur.map(s => [s.id, s]));
  const items = [];
  rows.forEach(r => {
    const sid = parseInt(r.querySelector('.sloz-sur').value) || 0;
    if (!sid) return;
    const s = indexById[sid];
    if (!s) return;
    items.push(s.nazev);
  });
  if (items.length === 0) {
    alert('Nejdříve přidejte suroviny do složení (níže).');
    return;
  }
  // Setříď podle abecedy nebo zachovej původní pořadí?
  // Pro cenovku obvykle: zachová se pořadí dle obsahu (nejvíc → nejméně).
  // Tady jen joinem.
  document.getElementById('vy-sloz').value = items.join(', ');
};

