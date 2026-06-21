// =============================================================
// VÝROBNÍ KALKULACE — várka → presy → klonky → cena na kus
// =============================================================
const vkState = {
  vyrobek_id: null, // přiřazený výrobek (pokud načteno/ukládáme zpět)
  receptura: [],    // [{surovina_id, mnozstvi, jednotka}]
  pres_kg: 1.8,
  klonku_z_presu: 30,
  zdobeni: [],      // [{nazev, cena_per_kus}] — per kus
  fixni: [],        // [{nazev, cena_kc}] — per várku
  sablonyFixni: [], // 🆕 v3.0.149 — uložené fixní platby z Nastavení (naklady_polozky) pro picker
  marze_pct: 50,
  sazba_dph: 12,
  vyrobky_cache: [], // pro dropdown
  current_vyrobni_cena: null, // pro porovnání
  scale_target_ks: 0, // plánovač várky — cílový počet ks
  scale_current_override: 0, // override: kolik kusů má aktuální receptura
  scale_mode: 'ks', // 'ks' | 'kg' — způsob škálování plánovače
  scale_target_kg: 0, // cíl v kg těsta
};

// Převod mezi jednotkami → na základní (g, ml, ks)
function vkToBase(mn, jed) {
  const j = (jed || 'g').toLowerCase();
  if (j === 'kg') return { v: mn * 1000, base: 'g' };
  if (j === 'g')  return { v: mn,        base: 'g' };
  if (j === 'l')  return { v: mn * 1000, base: 'ml' };
  if (j === 'ml') return { v: mn,        base: 'ml' };
  return { v: mn, base: j };
}

// Cena za 1 jednotku surovny (z cena_baleni / obsah_baleni)
function vkCenaPerJed(s) {
  const cb = parseFloat(s?.cena_baleni) || 0;
  const ob = parseFloat(s?.obsah_baleni) || 0;
  if (cb <= 0 || ob <= 0) return null;
  return cb / ob; // Kč za jednotku surovny (s.jednotka)
}

// Spočítá cenu řádku receptury
function vkCenaRadku(r, surById) {
  const s = surById[r.surovina_id];
  if (!s) return { cena: 0, problem: 'Bez ceny' };
  const cenaPer = vkCenaPerJed(s);
  if (cenaPer === null) return { cena: 0, problem: 'Bez ceny' };
  // Převod jednotek
  const mnBase = vkToBase(parseFloat(r.mnozstvi) || 0, r.jednotka);
  const sBase = vkToBase(1, s.jednotka);
  if (mnBase.base !== sBase.base) {
    return { cena: 0, problem: `Neslučitelné jednotky (${r.jednotka} vs ${s.jednotka})` };
  }
  // Cena: mnBase.v / sBase.v × cenaPer
  return { cena: (mnBase.v / sBase.v) * cenaPer };
}

async function renderVyrobniKalkulace() {
  const c = document.getElementById('content');
  c.innerHTML = '<div style="padding:40px;text-align:center;color:var(--text-3)">Načítám…</div>';
  await loadSurovinyCache();
  // Načti seznam výrobků pro dropdown
  if (vkState.vyrobky_cache.length === 0) {
    try {
      const r = await api('admin_vyrobky.php');
      vkState.vyrobky_cache = (r && Array.isArray(r.vyrobky)) ? r.vyrobky : [];
    } catch (e) { vkState.vyrobky_cache = []; }
  }
  // 🆕 v3.0.149 — načti uložené fixní platby (šablony) z Nastavení (naklady_polozky) pro picker
  try {
    const ns = await api('admin_nastaveni.php');
    const arr = JSON.parse(ns.naklady_polozky || '[]');
    vkState.sablonyFixni = Array.isArray(arr) ? arr : [];
  } catch (e) { vkState.sablonyFixni = []; }
  vkRender();
}

function vkRender() {
  const c = document.getElementById('content');
  const sur = (state._suroviny_cache || []).filter(s => s.aktivni == 1);
  const surById = Object.fromEntries(sur.map(s => [s.id, s]));

  // Přepočty
  let surovinyKc = 0;
  let totalMassG = 0;
  const recRows = vkState.receptura.map((r, i) => {
    const cenaR = vkCenaRadku(r, surById);
    surovinyKc += cenaR.cena;
    const s = surById[r.surovina_id];
    // Hmotnost (jen pokud je v g/kg/ml/l)
    const baz = vkToBase(parseFloat(r.mnozstvi) || 0, r.jednotka);
    if (baz.base === 'g' || baz.base === 'ml') totalMassG += baz.v;
    return {
      idx: i,
      surovina_id: r.surovina_id,
      surovina_nazev: s?.nazev || '?',
      surovina_jed: s?.jednotka || '',
      mnozstvi: r.mnozstvi,
      jednotka: r.jednotka,
      cena_per_jed: vkCenaPerJed(s),
      celkem: cenaR.cena,
      problem: cenaR.problem || null,
    };
  });

  // Výroba
  const presKg = parseFloat(vkState.pres_kg) || 0;
  const klonkuPresu = parseInt(vkState.klonku_z_presu) || 0;
  const totalKg = totalMassG / 1000;
  const presCount = presKg > 0 ? totalKg / presKg : 0;
  const klonkuCelkem = Math.floor(presCount * klonkuPresu);

  // Zdobení per kus — buď ze suroviny v DB nebo manuálně zadaná cena
  let zdobeniKc = 0;
  const zdobeniRows = vkState.zdobeni.map((z, i) => {
    let cena = 0, problem = null, sNazev = '', sJed = '';
    if (z.surovina_id) {
      const s = surById[z.surovina_id];
      if (s) {
        sNazev = s.nazev;
        sJed = s.jednotka;
        const r1 = vkCenaRadku({ surovina_id: z.surovina_id, mnozstvi: parseFloat(z.mnozstvi) || 0, jednotka: z.jednotka || 'g' }, surById);
        cena = r1.cena;
        problem = r1.problem || null;
      } else {
        problem = 'Surovina neexistuje';
      }
    } else if (z.cena_per_kus !== undefined) {
      // Backward-compat: ručně zadaná cena
      cena = parseFloat(z.cena_per_kus) || 0;
    }
    zdobeniKc += cena;
    return { idx: i, ...z, sNazev, sJed, cena, problem };
  });

  // Fixní náklady na várku
  let fixniKc = 0;
  vkState.fixni.forEach(f => fixniKc += (parseFloat(f.cena_kc) || 0));
  const fixniPerKus = klonkuCelkem > 0 ? fixniKc / klonkuCelkem : 0;
  const surPerKus = klonkuCelkem > 0 ? surovinyKc / klonkuCelkem : 0;

  const cenaPerKus = surPerKus + zdobeniKc + fixniPerKus;
  const marze = parseFloat(vkState.marze_pct) || 0;
  const cenaProdejBezDph = cenaPerKus * (1 + marze / 100);
  const dph = parseFloat(vkState.sazba_dph) || 0;
  const cenaProdejSDph = cenaProdejBezDph * (1 + dph / 100);

  const fmtKc = (n) => n.toFixed(2).replace('.', ',') + ' Kč';
  const fmtKcDetail = (n) => n.toFixed(4).replace(/\.?0+$/, '').replace('.', ',') + ' Kč';

  // Aktuální výrobek (pokud načteno)
  const currentV = vkState.vyrobek_id ? vkState.vyrobky_cache.find(x => x.id == vkState.vyrobek_id) : null;
  const currentCena = vkState.current_vyrobni_cena;
  const diff = (currentCena !== null && klonkuCelkem > 0) ? cenaPerKus - currentCena : null;

  c.innerHTML = `
    <div class="page-head">
      <div>
        <h1 class="page-title">🏭 Výrobní kalkulace</h1>
        <p class="page-sub">Várka → presy → klonky → cena na 1 kus${currentV ? ' · 🔗 <strong>' + esc(currentV.nazev) + '</strong>' : ''}</p>
      </div>
      <div style="display:flex;gap:8px;flex-wrap:wrap">
        <button class="btn-secondary" onclick="navigate('vyroba')">← Výroba</button>
        <button class="btn-secondary" onclick="otevritFixniNaklady()" title="Energie, práce, obal — položky přičtené ke každé kalkulaci">💰 Fixní náklady</button>
        <button class="btn-secondary" onclick="vkPrepocitat()" title="Znovu načte aktuální ceny surovin z databáze a přepočítá kalkulaci">🔄 Přepočítat</button>
        <button class="btn-secondary" onclick="vkReset()">↺ Vyčistit</button>
        <button class="btn-secondary" onclick="vkOtevritHistorii()" title="Procházet uložené kalkulace s tehdejšími cenami">📂 Historie</button>
        <button class="btn-primary" onclick="vkUlozitDoHistorie()" title="Uloží snímek aktuální kalkulace včetně cen surovin (pro pozdější porovnání)">💾 Uložit snímek</button>
      </div>
    </div>

    <!-- VAZBA NA VÝROBEK -->
    <div class="card-block" style="background:#FFFAF1;border:1px solid #E8C988;padding:12px 14px;margin-bottom:14px">
      <div style="display:flex;gap:10px;align-items:center;flex-wrap:wrap">
        <label class="form-label" style="margin:0;white-space:nowrap"><strong>📦 Výrobek</strong></label>
        <select class="form-input" onchange="vkLoadVyrobek(this.value)" style="flex:1;min-width:240px">
          <option value="">— Bez vazby (volná kalkulace) —</option>
          ${vkState.vyrobky_cache
            .filter(v => v.aktivni == 1)
            .map(v => `<option value="${v.id}" ${vkState.vyrobek_id == v.id ? 'selected' : ''}>${esc(v.nazev)}${v.cislo ? ' (' + esc(v.cislo) + ')' : ''}${v.vyrobni_cena ? ' · ' + parseFloat(v.vyrobni_cena).toFixed(2).replace('.', ',') + ' Kč' : ''}</option>`).join('')}
        </select>
        ${currentV ? `
          <button class="btn-secondary" onclick="vkLoadFromVyrobek()">📥 Načíst recepturu</button>
          <button class="btn-primary btn-green" onclick="vkSaveToVyrobek()">💾 Uložit do výrobku</button>
        ` : ''}
      </div>
      ${currentV && currentCena !== null ? `
        <div style="margin-top:10px;padding:10px 12px;background:white;border:1px solid #E8C988;border-radius:6px;display:flex;gap:14px;align-items:center;flex-wrap:wrap;font-size:13px">
          <span>📊 Aktuálně uložená výrobní cena: <strong>${currentCena.toFixed(4).replace(/\.?0+$/, '').replace('.', ',')} Kč</strong></span>
          ${diff !== null ? `
            <span>Nový výpočet: <strong>${cenaPerKus.toFixed(4).replace(/\.?0+$/, '').replace('.', ',')} Kč</strong></span>
            <span style="color:${Math.abs(diff) < 0.01 ? '#22863a' : (diff > 0 ? '#dc2626' : '#0C447C')};font-weight:600">
              ${Math.abs(diff) < 0.01 ? '≈ Stejné' : (diff > 0 ? '↑ +' + diff.toFixed(2).replace('.', ',') + ' Kč' : '↓ ' + diff.toFixed(2).replace('.', ',') + ' Kč')}
            </span>
          ` : ''}
        </div>
      ` : currentV ? `
        <div style="margin-top:8px;font-size:12px;color:var(--text-3)">📊 Tento výrobek zatím nemá uloženou výrobní cenu — můžeš ji nyní spočítat a uložit.</div>
      ` : `
        <div style="margin-top:8px;font-size:12px;color:var(--text-3)">Vyber výrobek pro načtení receptury (suroviny ze složení) a uložení výsledku.</div>
      `}
    </div>

    <div class="vk-grid">
      <!-- LEVÝ SLOUPEC: receptura + zdobení + fixní -->
      <div style="display:flex;flex-direction:column;gap:14px">

        <!-- RECEPTURA -->
        <div class="card-block">
          <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:10px;flex-wrap:wrap;gap:8px">
            <h3 style="margin:0;font-size:15px">🌾 Receptura (suroviny pro celou várku)</h3>
            <button class="btn-primary" onclick="vkAddRecept()">+ Přidat surovinu</button>
          </div>
          ${recRows.length === 0 ? '<div class="empty-state" style="padding:20px;background:var(--surface-2);border-radius:8px;text-align:center;color:var(--text-3)">Zatím žádné suroviny. Přidej alespoň jednu.</div>' : `
            <div class="vk-rows">
              ${recRows.map(r => `
                <div class="vk-row ${r.problem ? 'has-problem' : ''}">
                  <div class="vk-cell-sur">
                    <label class="vk-label">Surovina</label>
                    <select class="form-input" onchange="vkUpdateRecept(${r.idx}, 'surovina_id', parseInt(this.value))">
                      <option value="">— vyberte —</option>
                      ${sur.map(s => `<option value="${s.id}" ${r.surovina_id == s.id ? 'selected' : ''}>${esc(s.nazev)}</option>`).join('')}
                    </select>
                  </div>
                  <div class="vk-cell-mn">
                    <label class="vk-label">Množství</label>
                    <input class="form-input" type="number" step="0.01" min="0" value="${r.mnozstvi}" oninput="vkUpdateRecept(${r.idx}, 'mnozstvi', parseFloat(this.value)||0, true)" onblur="vkRenderNow()">
                  </div>
                  <div class="vk-cell-jed">
                    <label class="vk-label">Jed.</label>
                    <select class="form-input" onchange="vkUpdateRecept(${r.idx}, 'jednotka', this.value)">
                      ${['g','kg','ml','l','ks'].map(j => `<option value="${j}" ${r.jednotka === j ? 'selected' : ''}>${j}</option>`).join('')}
                    </select>
                  </div>
                  <div class="vk-cell-info">
                    <label class="vk-label">Cena/jed.</label>
                    <div class="vk-info-val">${r.cena_per_jed
                      ? `${fmtKcDetail(r.cena_per_jed)}/${esc(r.surovina_jed)}`
                      : (r.surovina_id
                        ? `<a onclick="vkEditSurovina(${r.surovina_id})" style="color:#dc2626;text-decoration:underline;cursor:pointer;font-size:11px;font-weight:500" title="Doplnit cenu této suroviny">🛒 doplnit cenu →</a>`
                        : '<span style="color:#dc2626;font-size:11px">vyber surovinu</span>')}</div>
                  </div>
                  <div class="vk-cell-celkem">
                    <label class="vk-label">Celkem</label>
                    <div class="vk-celkem-val">${fmtKc(r.celkem)}</div>
                    ${r.problem ? `<div style="font-size:10px;color:#92400e">⚠ ${esc(r.problem)}</div>` : ''}
                  </div>
                  <div class="vk-cell-del">
                    <button class="btn-danger" onclick="vkRemoveRecept(${r.idx})" title="Smazat">×</button>
                  </div>
                </div>
              `).join('')}
              <div class="vk-row-foot">
                <span><strong>Σ Suroviny celkem</strong></span>
                <strong>${fmtKc(surovinyKc)}</strong>
              </div>
            </div>
          `}
        </div>

        <!-- ZDOBENÍ / NÁPLŇ -->
        <div class="card-block">
          <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:10px;flex-wrap:wrap;gap:8px">
            <h3 style="margin:0;font-size:15px">🍓 Zdobení / náplň <span style="color:var(--text-3);font-weight:400;font-size:12px">(cena za 1 kus)</span></h3>
            <button class="btn-primary" onclick="vkAddZdobeni()">+ Přidat položku</button>
          </div>
          ${zdobeniRows.length === 0 ? '<div class="empty-state" style="padding:14px;background:var(--surface-2);border-radius:8px;text-align:center;color:var(--text-3);font-size:13px">Žádné zdobení/náplň — pokud výrobek nezdobíš, nech prázdné.</div>' : `
            <div class="vk-rows">
              ${zdobeniRows.map(z => `
                <div class="vk-row ${z.problem ? 'has-problem' : ''}">
                  <div class="vk-cell-sur">
                    <label class="vk-label">Surovina</label>
                    <select class="form-input" onchange="vkUpdateZdobField(${z.idx}, 'surovina_id', parseInt(this.value)||0)">
                      <option value="">— vyberte —</option>
                      ${sur.map(s => `<option value="${s.id}" ${z.surovina_id == s.id ? 'selected' : ''}>${esc(s.nazev)}</option>`).join('')}
                    </select>
                  </div>
                  <div class="vk-cell-mn">
                    <label class="vk-label">Množství/kus</label>
                    <input class="form-input" type="number" step="0.01" min="0" value="${z.mnozstvi || ''}" oninput="vkUpdateZdobField(${z.idx}, 'mnozstvi', parseFloat(this.value)||0, true)" onblur="vkRenderNow()">
                  </div>
                  <div class="vk-cell-jed">
                    <label class="vk-label">Jed.</label>
                    <select class="form-input" onchange="vkUpdateZdobField(${z.idx}, 'jednotka', this.value)">
                      ${['g','kg','ml','l','ks'].map(j => `<option value="${j}" ${(z.jednotka || 'g') === j ? 'selected' : ''}>${j}</option>`).join('')}
                    </select>
                  </div>
                  <div class="vk-cell-info">
                    <label class="vk-label">Cena/jed.</label>
                    <div class="vk-info-val">${z.surovina_id && surById[z.surovina_id]
                      ? (vkCenaPerJed(surById[z.surovina_id])
                        ? `${fmtKcDetail(vkCenaPerJed(surById[z.surovina_id]))}/${esc(z.sJed)}`
                        : `<a onclick="vkEditSurovina(${z.surovina_id})" style="color:#dc2626;text-decoration:underline;cursor:pointer;font-size:11px;font-weight:500" title="Doplnit cenu této suroviny">🛒 doplnit cenu →</a>`)
                      : '—'}</div>
                  </div>
                  <div class="vk-cell-celkem">
                    <label class="vk-label">Σ Kč/kus</label>
                    <div class="vk-celkem-val">${fmtKc(z.cena)}</div>
                    ${z.problem ? `<div style="font-size:10px;color:#92400e">⚠ ${esc(z.problem)}</div>` : ''}
                  </div>
                  <div class="vk-cell-del">
                    <button class="btn-danger" onclick="vkState.zdobeni.splice(${z.idx},1);vkRender()" title="Smazat">×</button>
                  </div>
                </div>
              `).join('')}
              <div class="vk-row-foot">
                <span><strong>Σ Zdobení / kus</strong></span>
                <strong>${fmtKc(zdobeniKc)}</strong>
              </div>
            </div>
          `}
        </div>

        <!-- FIXNÍ NÁKLADY NA VÁRKU -->
        <div class="card-block">
          <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:10px;flex-wrap:wrap;gap:8px">
            <h3 style="margin:0;font-size:15px">⚡ Fixní náklady <span style="color:var(--text-3);font-weight:400;font-size:12px">(na celou várku)</span></h3>
            <div style="display:flex;gap:6px;align-items:center;flex-wrap:wrap">
              ${vkState.sablonyFixni.length > 0 ? `
                <select class="form-input" style="font-size:13px;padding:6px 10px;max-width:220px;height:auto" onchange="vkAddFixniZeSablony(this.value); this.value=''" title="Vybrat z uložených fixních plateb (spravuješ přes 💰 Fixní náklady)">
                  <option value="">+ z uložených ▾</option>
                  ${vkState.sablonyFixni.map((f, i) => `<option value="${i}">${esc(f.nazev || '')} — ${(parseFloat(f.cena_kc) || 0).toFixed(2).replace('.', ',')} Kč</option>`).join('')}
                </select>
                <button class="btn-secondary" style="font-size:12px;padding:6px 10px" onclick="vkAddVsechnyFixni()" title="Přidat všechny uložené fixní platby najednou">+ všechny</button>
              ` : ''}
              <button class="btn-primary" onclick="vkAddFixni()" title="Přidat prázdný (volný) řádek">+ Přidat</button>
            </div>
          </div>
          ${vkState.fixni.length === 0 ? '<div class="empty-state" style="padding:14px;background:var(--surface-2);border-radius:8px;text-align:center;color:var(--text-3);font-size:13px">Žádné fixní náklady — typicky energie, práce, ostatní.</div>' : `
            <table class="table" style="margin:0;font-size:13px">
              <thead>
                <tr>
                  <th>Položka</th>
                  <th class="num" style="width:120px">Kč/várka</th>
                  <th style="width:34px"></th>
                </tr>
              </thead>
              <tbody>
                ${vkState.fixni.map((f, i) => `
                  <tr>
                    <td><input class="form-input" value="${esc(f.nazev || '')}" placeholder="např. Energie" oninput="vkUpdateFixniNazev(${i}, this.value)" onblur="vkRenderNow()"></td>
                    <td class="num"><input class="form-input" type="number" step="0.01" min="0" value="${f.cena_kc || 0}" oninput="vkUpdateFixni(${i}, parseFloat(this.value)||0, true)" onblur="vkRenderNow()" style="text-align:right"></td>
                    <td><button class="btn-danger" onclick="vkState.fixni.splice(${i},1);vkRender()" style="padding:4px 8px;font-size:12px">×</button></td>
                  </tr>
                `).join('')}
              </tbody>
              <tfoot>
                <tr style="background:#FFF8E5">
                  <td><strong>Σ Fixní celkem</strong></td>
                  <td class="num"><strong>${fmtKc(fixniKc)}</strong></td>
                  <td></td>
                </tr>
              </tfoot>
            </table>
          `}
        </div>

      </div>

      <!-- PRAVÝ SLOUPEC: výroba + výsledek -->
      <div style="display:flex;flex-direction:column;gap:14px">

        <!-- VÝROBA — 🆕 v2.9.298 lépe vysvětlující výpočet -->
        <div class="card-block" style="background:#FFFAF1;border:1px solid #E8C988">
          <h3 style="margin:0 0 10px;font-size:15px;color:#854F0B">⚙️ Výroba</h3>

          <!-- INPUT 1: hmotnost těsta (ze surovin) -->
          <div style="display:flex;justify-content:space-between;font-size:13px;padding:8px 10px;background:#FFF8E5;border-radius:6px;margin-bottom:8px">
            <span><strong>1. Hmotnost těsta</strong> <span style="color:var(--text-3);font-size:11px">(součet surovin)</span></span>
            <strong style="color:#854F0B">${totalKg.toFixed(3).replace(/\.?0+$/, '').replace('.', ',')} kg</strong>
          </div>

          <!-- INPUT 2: pres setup -->
          <div style="display:flex;align-items:center;gap:6px;padding:6px 0">
            <label class="form-label" style="margin:0;flex:1">2. Navážka 1 presu (kg)</label>
            <input class="form-input" type="number" step="0.01" min="0" value="${vkState.pres_kg}" oninput="vkState.pres_kg = parseFloat(this.value)||0;vkRenderDebounced()" onblur="vkRenderNow()" style="width:90px;text-align:right">
          </div>
          <div style="display:flex;align-items:center;gap:6px;padding:6px 0;border-bottom:1px dashed #E8C988;margin-bottom:8px">
            <label class="form-label" style="margin:0;flex:1">3. Klonků z 1 presu</label>
            <input class="form-input" type="number" min="1" value="${vkState.klonku_z_presu}" oninput="vkState.klonku_z_presu = parseInt(this.value)||0;vkRenderDebounced()" onblur="vkRenderNow()" style="width:90px;text-align:right">
          </div>

          <!-- VÝPOČET — krok za krokem -->
          ${presKg > 0 && klonkuPresu > 0 ? `
            <div style="background:white;border:1px solid #E8C988;border-radius:6px;padding:10px 12px;font-size:12px;line-height:1.6;color:#854F0B">
              <div style="margin-bottom:4px"><strong>Výpočet:</strong></div>
              <div>📊 1 klonek = <strong>${(presKg * 1000 / klonkuPresu).toFixed(0)} g</strong> <span style="color:var(--text-3)">(${presKg} kg ÷ ${klonkuPresu} klonků)</span></div>
              <div>📊 Presů z těsta = <strong>${presCount.toFixed(2).replace(/\.?0+$/, '').replace('.', ',')}</strong> <span style="color:var(--text-3)">(${totalKg.toFixed(2).replace('.', ',')} kg ÷ ${presKg} kg)</span></div>
              ${presCount < 1 && presCount > 0 ? `
                <div style="color:#92400e;margin-top:4px;font-size:11px;font-weight:600">
                  ⚠ Méně než 1 pres → reálně připravíš jen ${klonkuCelkem} klonků z této várky.
                </div>
              ` : ''}
            </div>
          ` : ''}

          <!-- VÝSLEDEK -->
          <div style="background:#fff;border:2px solid #BA7517;border-radius:8px;padding:12px 14px;margin-top:10px;text-align:center">
            <div style="font-size:11px;color:#854F0B;text-transform:uppercase;letter-spacing:0.5px;margin-bottom:4px">Vyrobíš z této várky</div>
            <div style="font-size:32px;font-weight:800;color:#854F0B;line-height:1">${klonkuCelkem}</div>
            <div style="font-size:13px;color:#854F0B;margin-top:2px">klonků (kusů)</div>
            ${klonkuCelkem > 0 ? `
              <div style="font-size:11px;color:var(--text-3);margin-top:8px;padding-top:8px;border-top:1px dashed #E8C988">
                ✓ ${klonkuCelkem} × ${klonkuPresu > 0 ? (presKg * 1000 / klonkuPresu).toFixed(0) : 0} g = ${(klonkuCelkem * (presKg * 1000 / klonkuPresu) / 1000).toFixed(2).replace('.', ',')} kg <span style="color:var(--text-3)">(zaokrouhleno dolů)</span>
              </div>
            ` : ''}
          </div>
        </div>

        <!-- PLÁNOVAČ VÁRKY (škálování receptury — buď podle ks nebo podle kg těsta) -->
        ${(() => {
          const mode = vkState.scale_mode || 'ks';
          const targetKs = parseInt(vkState.scale_target_ks) || 0;
          const targetKg = parseFloat(vkState.scale_target_kg) || 0;
          const overrideKs = parseInt(vkState.scale_current_override) || 0;
          const currentKs = overrideKs > 0 ? overrideKs : klonkuCelkem;
          const currentKg = totalKg; // už spočítaný výše ve vkRender
          const hasReceptura = vkState.receptura.length > 0;
          const ready = hasReceptura && (mode === 'ks' ? currentKs > 0 : currentKg > 0);
          const mult = ready
            ? (mode === 'ks' && targetKs > 0 ? targetKs / currentKs
              : mode === 'kg' && targetKg > 0 ? targetKg / currentKg
              : 0)
            : 0;
          const targetLabel = mode === 'kg'
            ? `${targetKg.toFixed(targetKg >= 10 ? 2 : 3).replace(/\.?0+$/, '').replace('.', ',')} kg těsta`
            : `${targetKs} ks`;
          const fmtMn = (n) => {
            if (n < 0.01) return '< 0,01';
            return n.toFixed(n >= 100 ? 1 : (n >= 10 ? 2 : 3)).replace(/\.?0+$/, '').replace('.', ',');
          };
          const fmtKgVal = (n) => n.toFixed(n >= 10 ? 2 : 3).replace(/\.?0+$/, '').replace('.', ',');
          return `
          <div class="card-block" style="background:#F0FDF4;border:1px solid #86EFAC">
            <h3 style="margin:0 0 10px;font-size:15px;color:#15803D">📋 Plánovač várky <span style="color:var(--text-3);font-weight:400;font-size:11px">(škálovaná receptura)</span></h3>

            ${!hasReceptura ? `
              <p style="font-size:12px;color:#15803D;background:white;padding:10px 12px;border-radius:6px;border:1px dashed #86EFAC;margin:0">
                ⏸ <strong>Doplň nejdřív recepturu</strong> (alespoň jednu surovinu).
              </p>
            ` : `
              <!-- Mode toggle: ks vs kg těsta -->
              <div style="display:flex;gap:0;margin-bottom:10px;border:1px solid #86EFAC;border-radius:6px;overflow:hidden;background:white">
                <button class="${mode === 'ks' ? 'is-active' : ''}" onclick="vkState.scale_mode='ks';vkRender()" style="flex:1;padding:8px 10px;border:0;background:${mode==='ks'?'#15803D':'transparent'};color:${mode==='ks'?'#fff':'#15803D'};font-size:12px;font-weight:600;cursor:pointer;border-right:1px solid #86EFAC">📦 Podle kusů</button>
                <button class="${mode === 'kg' ? 'is-active' : ''}" onclick="vkState.scale_mode='kg';vkRender()" style="flex:1;padding:8px 10px;border:0;background:${mode==='kg'?'#15803D':'transparent'};color:${mode==='kg'?'#fff':'#15803D'};font-size:12px;font-weight:600;cursor:pointer">⚖️ Podle kg těsta</button>
              </div>

              ${mode === 'ks' ? `
                <!-- Aktuální počet kusů — auto + override -->
                <div style="display:flex;align-items:center;gap:8px;padding:8px 0;border-bottom:1px dashed #86EFAC">
                  <label class="form-label" style="margin:0;flex:1">
                    Aktuální receptura
                    ${klonkuCelkem > 0 && overrideKs === 0 ? `<span style="font-size:10px;color:#15803D;font-weight:400">(z presů: ${klonkuCelkem})</span>` : ''}
                  </label>
                  <input class="form-input" type="number" min="0" value="${overrideKs || klonkuCelkem || ''}" placeholder="ks" oninput="vkState.scale_current_override = parseInt(this.value)||0; vkRenderDebounced()" onblur="vkRenderNow()" style="width:90px;text-align:right" title="Vypočteno z hmotnosti / presů — můžeš přepsat ručně">
                  <span style="color:#15803D;font-size:13px;font-weight:600">ks</span>
                </div>
                <div style="display:flex;align-items:center;gap:8px;padding:8px 0;border-bottom:1px dashed #86EFAC">
                  <label class="form-label" style="margin:0;flex:1">Chci vyrobit</label>
                  <input class="form-input" type="number" min="1" value="${targetKs || ''}" placeholder="např. 200" oninput="vkState.scale_target_ks = parseInt(this.value)||0; vkRenderDebounced()" onblur="vkRenderNow()" style="width:90px;text-align:right">
                  <span style="color:#15803D;font-size:13px;font-weight:600">ks</span>
                </div>
              ` : `
                <!-- Mode kg — aktuální / cílová hmotnost -->
                <div style="display:flex;align-items:center;gap:8px;padding:8px 0;border-bottom:1px dashed #86EFAC">
                  <label class="form-label" style="margin:0;flex:1">Aktuální receptura</label>
                  <span style="color:#15803D;font-size:14px;font-weight:700;font-variant-numeric:tabular-nums">${currentKg > 0 ? fmtKgVal(currentKg) : '—'}</span>
                  <span style="color:#15803D;font-size:13px;font-weight:600">kg</span>
                </div>
                <div style="display:flex;align-items:center;gap:8px;padding:8px 0;border-bottom:1px dashed #86EFAC">
                  <label class="form-label" style="margin:0;flex:1">Chci těsta</label>
                  <input class="form-input" type="number" step="0.01" min="0" value="${targetKg || ''}" placeholder="např. 5" oninput="vkState.scale_target_kg = parseFloat(this.value)||0; vkRenderDebounced()" onblur="vkRenderNow()" style="width:90px;text-align:right">
                  <span style="color:#15803D;font-size:13px;font-weight:600">kg</span>
                </div>
              `}

              ${!ready ? `
                <p style="color:#92400e;font-size:12px;margin:8px 0 0;padding:8px;background:#FEF3C7;border-radius:6px">
                  ⚠ ${mode === 'ks'
                    ? 'Receptura nedává vypočítaný počet kusů. Doplň nahoře <strong>kolik kusů (klonků)</strong>.'
                    : 'Receptura nemá hmotnost (žádná surovina v g/kg/ml/l).'}
                </p>
              ` : (mode === 'ks' && targetKs <= 0) || (mode === 'kg' && targetKg <= 0) ? `
                <p style="color:var(--text-3);font-size:12px;margin:8px 0 0">Zadej cílovou hodnotu.</p>
              ` : `
                <div style="margin-top:8px;font-size:12px;color:#15803D;background:white;padding:8px 10px;border-radius:6px;border:1px solid #86EFAC;display:flex;justify-content:space-between;flex-wrap:wrap;gap:6px">
                  <span>Násobek: <strong>${(mult).toFixed(3).replace(/\.?0+$/, '').replace('.', ',')}×</strong></span>
                  <span>${mode === 'ks' ? `${currentKs} ks → <strong>${targetKs} ks</strong>` : `${fmtKgVal(currentKg)} kg → <strong>${fmtKgVal(targetKg)} kg</strong>`}</span>
                </div>
                <table style="width:100%;font-size:12px;margin-top:10px">
                  <thead>
                    <tr style="border-bottom:1px solid #86EFAC;color:#15803D">
                      <th style="text-align:left;padding:4px 0;font-weight:600">Surovina</th>
                      <th style="text-align:right;padding:4px 0;font-weight:600">Originál</th>
                      <th style="text-align:right;padding:4px 0;font-weight:600;color:#15803D">Pro ${targetLabel}</th>
                    </tr>
                  </thead>
                  <tbody>
                    ${recRows.map(r => `
                      <tr style="border-bottom:1px dashed #DCFCE7">
                        <td style="padding:5px 0">${esc(r.surovina_nazev)}</td>
                        <td style="text-align:right;padding:5px 0;color:var(--text-3)">${fmtMn(parseFloat(r.mnozstvi) || 0)} ${esc(r.jednotka)}</td>
                        <td style="text-align:right;padding:5px 0;font-weight:700;color:#15803D">${fmtMn((parseFloat(r.mnozstvi) || 0) * mult)} ${esc(r.jednotka)}</td>
                      </tr>
                    `).join('')}
                    ${vkState.zdobeni.filter(z => z.surovina_id && z.mnozstvi).length > 0 ? `
                      <tr><td colspan="3" style="padding:8px 0 4px;font-weight:600;font-size:11px;color:var(--text-3);text-transform:uppercase;letter-spacing:0.4pt">Zdobení / náplň</td></tr>
                      ${vkState.zdobeni.filter(z => z.surovina_id && z.mnozstvi).map(z => {
                        const sNaz = (sur.find(x => x.id == z.surovina_id) || {}).nazev || '?';
                        // V kg módu: zdobení je per kus → potřebujeme znát počet kusů aby se škálovalo;
                        // pokud nemáme currentKs, použijeme prostý násobek (mult)
                        // Zdobení per kus → potřebujeme absolutní počet ks (ne násobek)
                        const zdobMult = mode === 'ks'
                          ? targetKs
                          : (currentKs > 0 ? Math.round(mult * currentKs) : 0);
                        return `<tr style="border-bottom:1px dashed #DCFCE7">
                          <td style="padding:5px 0">${esc(sNaz)} <span style="color:var(--text-3);font-size:10px">(per kus)</span></td>
                          <td style="text-align:right;padding:5px 0;color:var(--text-3)">${fmtMn(parseFloat(z.mnozstvi) || 0)} ${esc(z.jednotka || 'g')}</td>
                          <td style="text-align:right;padding:5px 0;font-weight:700;color:#15803D">${fmtMn((parseFloat(z.mnozstvi) || 0) * zdobMult)} ${esc(z.jednotka || 'g')}</td>
                        </tr>`;
                      }).join('')}
                    ` : ''}
                  </tbody>
                </table>
                <div style="display:flex;gap:6px;margin-top:10px;flex-wrap:wrap">
                  <button class="btn-secondary" onclick="vkScaleTisk()" style="font-size:12px;flex:1" title="Vytisknout pracovní list pekaře">🖨 Vytisknout</button>
                  <button class="btn-secondary" onclick="vkScaleApply()" style="font-size:12px;flex:1" title="Přepíše recepturu škálovanými hodnotami (originál ztratíš!)">✓ Aplikovat</button>
                </div>
              `}
            `}
          </div>
          `;
        })()}

        <!-- VÝSLEDEK -->
        <div class="card-block" style="background:#F0F9FF;border:1px solid #93C5FD">
          <h3 style="margin:0 0 10px;font-size:15px;color:#1E40AF">💰 Náklady na 1 kus</h3>
          ${klonkuCelkem === 0 ? `
            <p style="color:#92400e;font-size:13px;background:#FEF3C7;padding:10px;border-radius:6px;margin:0">⚠ Doplň recepturu a parametry výroby (presy, klonky), aby šlo počítat.</p>
          ` : `
            <table style="width:100%;font-size:13px">
              <tbody>
                <tr><td style="padding:4px 0">Suroviny / kus</td><td style="text-align:right;padding:4px 0">${fmtKcDetail(surPerKus)}</td></tr>
                ${zdobeniKc > 0 ? `<tr><td style="padding:4px 0">Zdobení / kus</td><td style="text-align:right;padding:4px 0">${fmtKcDetail(zdobeniKc)}</td></tr>` : ''}
                ${fixniKc > 0 ? `<tr><td style="padding:4px 0">Fixní / kus</td><td style="text-align:right;padding:4px 0">${fmtKcDetail(fixniPerKus)}</td></tr>` : ''}
                <tr style="border-top:2px solid #93C5FD"><td style="padding:8px 0;font-weight:700;font-size:14px;color:#1E40AF">VÝROBNÍ NÁKLAD / kus</td><td style="text-align:right;padding:8px 0;font-weight:800;font-size:18px;color:#1E40AF">${fmtKc(cenaPerKus)}</td></tr>
              </tbody>
            </table>
            <div style="margin-top:14px;padding:12px;background:white;border-radius:8px;border:1px solid #93C5FD">
              <div style="display:flex;align-items:center;gap:8px;margin-bottom:8px;flex-wrap:wrap">
                <label class="form-label" style="margin:0;flex:1">Marže</label>
                <input class="form-input" type="number" step="1" min="0" max="500" value="${vkState.marze_pct}" oninput="vkState.marze_pct = parseFloat(this.value)||0;vkRenderDebounced()" onblur="vkRenderNow()" style="width:80px;text-align:right">
                <span style="font-size:13px;font-weight:600">%</span>
              </div>
              <div style="display:flex;align-items:center;gap:8px;margin-bottom:10px;flex-wrap:wrap">
                <label class="form-label" style="margin:0;flex:1">Sazba DPH</label>
                <select class="form-input" onchange="vkState.sazba_dph = parseFloat(this.value)||0;vkRender()" style="width:auto">
                  <option value="0"  ${vkState.sazba_dph == 0  ? 'selected' : ''}>0 %</option>
                  <option value="12" ${vkState.sazba_dph == 12 ? 'selected' : ''}>12 %</option>
                  <option value="21" ${vkState.sazba_dph == 21 ? 'selected' : ''}>21 %</option>
                </select>
              </div>
              <div style="display:flex;justify-content:space-between;font-size:13px;padding:6px 0">
                <span>Prodejní bez DPH</span>
                <strong>${fmtKc(cenaProdejBezDph)}</strong>
              </div>
              <div style="display:flex;justify-content:space-between;padding:8px 0;border-top:1px solid #BFDBFE;color:#854F0B">
                <span><strong>Prodejní s DPH</strong></span>
                <strong style="font-size:18px">${fmtKc(cenaProdejSDph)}</strong>
              </div>
            </div>
          `}
        </div>

      </div>
    </div>
  `;
}

// Operace nad stavem
window.vkAddRecept = function() {
  vkState.receptura.push({ surovina_id: 0, mnozstvi: 0, jednotka: 'kg' });
  vkRender();
};
window.vkRemoveRecept = function(i) {
  vkState.receptura.splice(i, 1);
  vkRender();
};
// Debounced re-render — aby psaní do textových polí neztrácelo focus po každém znaku
let _vkRenderTimer = null;
window.vkRenderDebounced = function(ms) {
  if (_vkRenderTimer) clearTimeout(_vkRenderTimer);
  _vkRenderTimer = setTimeout(() => { _vkRenderTimer = null; vkRender(); }, ms || 350);
};
window.vkRenderNow = function() {
  if (_vkRenderTimer) { clearTimeout(_vkRenderTimer); _vkRenderTimer = null; }
  vkRender();
};

window.vkUpdateRecept = function(i, key, val, debounced) {
  if (!vkState.receptura[i]) return;
  vkState.receptura[i][key] = val;
  if (debounced) vkRenderDebounced(); else vkRender();
};
window.vkAddZdobeni = function() {
  vkState.zdobeni.push({ surovina_id: 0, mnozstvi: 0, jednotka: 'g' });
  vkRender();
};
window.vkUpdateZdobField = function(i, key, val, debounced) {
  if (!vkState.zdobeni[i]) return;
  vkState.zdobeni[i][key] = val;
  if (debounced) vkRenderDebounced(); else vkRender();
};
// Backward-compat (pro kalkulaci načtenou se starým schema)
window.vkUpdateZdob = function(i, val) {
  if (!vkState.zdobeni[i]) return;
  vkState.zdobeni[i].cena_per_kus = val;
  vkRenderDebounced();
};
window.vkAddFixni = function() {
  vkState.fixni.push({ nazev: '', cena_kc: 0 });
  vkRender();
};
// 🆕 v3.0.149 — přidá fixní platbu z uložené šablony (Nastavení → naklady_polozky); částku lze dál upravit
window.vkAddFixniZeSablony = function(idxStr) {
  const i = parseInt(idxStr, 10);
  if (isNaN(i)) return;
  const f = (vkState.sablonyFixni || [])[i];
  if (!f) return;
  vkState.fixni.push({ nazev: f.nazev || '', cena_kc: parseFloat(f.cena_kc) || 0 });
  vkRender();
};
window.vkAddVsechnyFixni = function() {
  (vkState.sablonyFixni || []).forEach(f => vkState.fixni.push({ nazev: f.nazev || '', cena_kc: parseFloat(f.cena_kc) || 0 }));
  vkRender();
};
window.vkUpdateFixni = function(i, val, debounced) {
  if (!vkState.fixni[i]) return;
  vkState.fixni[i].cena_kc = val;
  if (debounced) vkRenderDebounced(); else vkRender();
};
window.vkUpdateFixniNazev = function(i, val) {
  if (!vkState.fixni[i]) return;
  vkState.fixni[i].nazev = val;
  // bez re-renderu — text se ukládá do state, render proběhne až později
};
window.vkReset = async function() {
  if (!(await confirmDialog({ msg: 'Vyčistit kalkulačku? Všechny zadané hodnoty budou ztraceny.', danger: false }))) return;
  vkState.vyrobek_id = null;
  vkState.current_vyrobni_cena = null;
  vkState.receptura = [];
  vkState.zdobeni = [];
  vkState.fixni = [];
  vkState.pres_kg = 1.8;
  vkState.klonku_z_presu = 30;
  vkState.marze_pct = 50;
  vkState.scale_target_ks = 0;
  vkState.scale_target_kg = 0;
  vkState.scale_current_override = 0;
  vkState.scale_mode = 'ks';
  // Pokud byly načtené historické ceny — obnovit aktuální
  if (typeof vkRestoreOriginalPrices === 'function') vkRestoreOriginalPrices();
  vkRender();
};

// Vybrat výrobek (jen vazba) — nenačítá automaticky recepturu
window.vkLoadVyrobek = async function(idStr) {
  const id = parseInt(idStr) || null;
  // Pokud byly načtené historické ceny ze snapshotu, obnovíme aktuální
  if (typeof vkRestoreOriginalPrices === 'function') vkRestoreOriginalPrices();
  vkState.vyrobek_id = id;
  vkState.current_vyrobni_cena = null;
  // Reset škálování — předchozí hodnoty z jiného výrobku by mátly
  vkState.scale_target_ks = 0;
  vkState.scale_target_kg = 0;
  vkState.scale_current_override = 0;
  if (id) {
    try {
      const v = await api(`admin_vyrobky.php?id=${id}`);
      vkState.current_vyrobni_cena = v.vyrobni_cena !== null && v.vyrobni_cena !== undefined && v.vyrobni_cena !== '' ? parseFloat(v.vyrobni_cena) : null;
      // Pokud má uloženou kalkulaci → načti
      if (v.kalkulace_data) {
        try {
          const k = typeof v.kalkulace_data === 'string' ? JSON.parse(v.kalkulace_data) : v.kalkulace_data;
          if (k && typeof k === 'object') {
            if (Array.isArray(k.receptura)) vkState.receptura = k.receptura;
            if (Array.isArray(k.zdobeni)) vkState.zdobeni = k.zdobeni;
            if (Array.isArray(k.fixni)) vkState.fixni = k.fixni;
            if (k.pres_kg) vkState.pres_kg = parseFloat(k.pres_kg);
            if (k.klonku_z_presu) vkState.klonku_z_presu = parseInt(k.klonku_z_presu);
            if (k.marze_pct) vkState.marze_pct = parseFloat(k.marze_pct);
            if (k.sazba_dph !== undefined) vkState.sazba_dph = parseFloat(k.sazba_dph);
          }
        } catch (e) {}
      }
    } catch (e) {}
  }
  vkRender();
};

// 🆕 v2.9.277 — Zkratka z produktové karty → otevři Kalkulaci s pre-loaded výrobkem
window.otevritKalkulaciProVyrobek = function(vyrobekId) {
  if (!vyrobekId) return;
  closeModal();
  // Pre-set vyrobek_id před navigací — renderVyrobniKalkulace ho použije
  vkState.vyrobek_id = parseInt(vyrobekId, 10);
  vkState.receptura = []; // vyčistit aby se načetla čerstvě
  navigate('vyrobni_kalkulace');
  // Po renderu → auto-trigger load
  setTimeout(() => {
    if (typeof vkLoadFromVyrobek === 'function') {
      vkLoadFromVyrobek().catch(e => console.warn('Kalkulace pre-load:', e));
    }
  }, 250);
};

// Načíst recepturu z aktuálně vybraného výrobku (z pivot vyrobek_suroviny)
window.vkLoadFromVyrobek = async function() {
  if (!vkState.vyrobek_id) return;
  if (vkState.receptura.length > 0 && !(await confirmDialog({ msg: 'Aktuální receptura bude přepsána. Pokračovat?', danger: true }))) return;
  try {
    const v = await api(`admin_vyrobky.php?id=${vkState.vyrobek_id}`);
    const slozeni = Array.isArray(v.slozeni) ? v.slozeni : [];
    vkState.receptura = slozeni.map(p => ({
      surovina_id: parseInt(p.surovina_id),
      mnozstvi: parseFloat(p.mnozstvi) || 0,
      jednotka: p.jednotka || 'g',
    }));
    // Reset škálování — receptura je nová
    vkState.scale_target_ks = 0;
    vkState.scale_target_kg = 0;
    vkState.scale_current_override = 0;

    // Auto-detekce počtu kusů (klonků) podle hmotnosti výrobku per kus
    // Hmotnost / kus: nejdřív v.obsah * obsah_jednotka (preferované), pak hmotnost_g
    let perPieceG = 0;
    if (v.obsah && v.obsah_jednotka) {
      const oj = String(v.obsah_jednotka).toLowerCase();
      if (oj === 'kg') perPieceG = parseFloat(v.obsah) * 1000;
      else if (oj === 'g') perPieceG = parseFloat(v.obsah);
      else if (oj === 'l') perPieceG = parseFloat(v.obsah) * 1000;
      else if (oj === 'ml') perPieceG = parseFloat(v.obsah);
    }
    if (perPieceG === 0 && v.hmotnost_g) perPieceG = parseFloat(v.hmotnost_g) || 0;

    let totalMassG = 0;
    vkState.receptura.forEach(r => {
      const baz = vkToBase(parseFloat(r.mnozstvi) || 0, r.jednotka);
      if (baz.base === 'g' || baz.base === 'ml') totalMassG += baz.v;
    });

    let detectedKs = 0;
    if (perPieceG > 0 && totalMassG > 0) {
      detectedKs = Math.round(totalMassG / perPieceG);
      vkState.scale_current_override = detectedKs;
    }

    vkRender();

    if (vkState.receptura.length === 0) {
      // 🆕 v2.9.290 — nabídnout otevření editoru výrobku místo jen alertu
      if ((await confirmDialog({ msg: 'Výrobek nemá ve složení žádné suroviny.\n\nOtevřít editor výrobku a doplnit recept?', danger: false }))) {
        const vid = vkState.vyrobek_id;
        if (vid && typeof editVyrobek === 'function') {
          // Navigate na Výrobky stránku + otevřít modal editoru
          navigate('vyrobky');
          setTimeout(() => editVyrobek(vid), 300);
        }
      }
    } else {
      const toast = document.createElement('div');
      toast.style.cssText = 'position:fixed;bottom:24px;right:24px;background:var(--success-bg);color:var(--success-text);padding:14px 22px;border-radius:8px;box-shadow:0 4px 12px rgba(0,0,0,0.15);font-size:13px;font-weight:500;z-index:1000;max-width:340px;line-height:1.5';
      toast.innerHTML = `
        ✓ Načteno <strong>${vkState.receptura.length}</strong> surovin
        ${totalMassG > 0 ? `<br>📏 Hmotnost těsta: <strong>${(totalMassG / 1000).toFixed(2).replace('.', ',')} kg</strong>` : ''}
        ${detectedKs > 0 ? `<br>📦 Detekováno: <strong>~${detectedKs} ks</strong> (${perPieceG} g/ks)` : (perPieceG === 0 ? '<br><span style="color:#92400e;font-size:11px">⚠ Výrobek nemá hmotnost/kus — doplň ji v editoru výrobku</span>' : '')}
      `;
      document.body.appendChild(toast);
      setTimeout(() => toast.remove(), 5000);
    }
  } catch (e) { alert('Chyba: ' + e.message); }
};

// 🆕 v3.0.149 — ruční přepočet: vynutí ČERSTVÉ ceny surovin (invalidace cache) + překreslí.
// Řeší "po vyplnění z produktu se cena nepřepočítá" — kalkulace držela cachované ceny surovin.
window.vkPrepocitat = async function() {
  state._suroviny_cache = null;
  try { await loadSurovinyCache(); } catch (e) {}
  vkRender();
  const toast = document.createElement('div');
  toast.style.cssText = 'position:fixed;bottom:24px;right:24px;background:var(--success-bg);color:var(--success-text);padding:12px 20px;border-radius:8px;box-shadow:0 4px 12px rgba(0,0,0,0.15);font-size:13px;font-weight:600;z-index:1000';
  toast.textContent = '🔄 Přepočítáno s aktuálními cenami surovin';
  document.body.appendChild(toast);
  setTimeout(() => toast.remove(), 2800);
};

// Uložit výrobní cenu do výrobku + celou kalkulaci do JSON
window.vkSaveToVyrobek = async function() {
  if (!vkState.vyrobek_id) return alert('Nejdřív vyber výrobek nahoře.');
  // Spočítej výrobní cenu znovu (out-of-band)
  const sur = (state._suroviny_cache || []).filter(s => s.aktivni == 1);
  const surById = Object.fromEntries(sur.map(s => [s.id, s]));
  let surovinyKc = 0, totalMassG = 0;
  vkState.receptura.forEach(r => {
    const cenaR = vkCenaRadku(r, surById);
    surovinyKc += cenaR.cena;
    const baz = vkToBase(parseFloat(r.mnozstvi) || 0, r.jednotka);
    if (baz.base === 'g' || baz.base === 'ml') totalMassG += baz.v;
  });
  const presKg = parseFloat(vkState.pres_kg) || 0;
  const klonkuPresu = parseInt(vkState.klonku_z_presu) || 0;
  const presCount = presKg > 0 ? (totalMassG / 1000) / presKg : 0;
  const klonkuCelkem = Math.floor(presCount * klonkuPresu);
  if (klonkuCelkem === 0) return alert('Nelze uložit — chybí parametry výroby (presy/klonky) nebo receptura.');

  let zdobeniKc = 0;
  vkState.zdobeni.forEach(z => {
    if (z.surovina_id) {
      const r1 = vkCenaRadku({ surovina_id: z.surovina_id, mnozstvi: parseFloat(z.mnozstvi) || 0, jednotka: z.jednotka || 'g' }, surById);
      zdobeniKc += r1.cena;
    } else if (z.cena_per_kus !== undefined) {
      zdobeniKc += parseFloat(z.cena_per_kus) || 0;
    }
  });
  let fixniKc = 0;
  vkState.fixni.forEach(f => fixniKc += parseFloat(f.cena_kc) || 0);
  const cenaPerKus = (klonkuCelkem > 0 ? surovinyKc / klonkuCelkem : 0) + zdobeniKc + (klonkuCelkem > 0 ? fixniKc / klonkuCelkem : 0);

  const v = vkState.vyrobky_cache.find(x => x.id == vkState.vyrobek_id);
  const msg = `Uložit výrobní cenu do "${v?.nazev || ''}"?\n\n` +
    `Aktuální: ${vkState.current_vyrobni_cena !== null ? vkState.current_vyrobni_cena.toFixed(4).replace(/\.?0+$/, '').replace('.', ',') + ' Kč' : '— (nic)'}\n` +
    `Nová:     ${cenaPerKus.toFixed(4).replace(/\.?0+$/, '').replace('.', ',')} Kč\n\n` +
    `Uloží se i kompletní kalkulace pro pozdější úpravy.`;
  if (!(await confirmDialog({ msg: msg, danger: false }))) return;

  try {
    await api('admin_vyrobky.php', {
      method: 'PUT',
      body: JSON.stringify({
        id: vkState.vyrobek_id,
        vyrobni_cena: cenaPerKus,
        kalkulace_data: {
          receptura: vkState.receptura,
          pres_kg: vkState.pres_kg,
          klonku_z_presu: vkState.klonku_z_presu,
          zdobeni: vkState.zdobeni,
          fixni: vkState.fixni,
          marze_pct: vkState.marze_pct,
          sazba_dph: vkState.sazba_dph,
          ulozeno: new Date().toISOString(),
          klonku_celkem: klonkuCelkem,
        },
      }),
    });
    vkState.current_vyrobni_cena = cenaPerKus;
    // Update cache
    const cached = vkState.vyrobky_cache.find(x => x.id == vkState.vyrobek_id);
    if (cached) cached.vyrobni_cena = cenaPerKus;
    vkRender();
    const toast = document.createElement('div');
    toast.style.cssText = 'position:fixed;bottom:24px;right:24px;background:var(--success-bg);color:var(--success-text);padding:14px 22px;border-radius:8px;box-shadow:0 4px 12px rgba(0,0,0,0.15);font-size:14px;font-weight:600;z-index:1000;';
    toast.textContent = '✓ Výrobní cena uložena';
    document.body.appendChild(toast);
    setTimeout(() => toast.remove(), 2500);
  } catch (e) { alert('Chyba: ' + e.message); }
};

