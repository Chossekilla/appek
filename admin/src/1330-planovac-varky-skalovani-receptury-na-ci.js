// =============================================================
// PLÁNOVAČ VÁRKY — škálování receptury na cílový počet kusů
// =============================================================
window.vkScaleApply = async function() {
  const mode = vkState.scale_mode || 'ks';
  let mult = 0, label = '';
  let totalMassG = 0;
  vkState.receptura.forEach(r => {
    const baz = vkToBase(parseFloat(r.mnozstvi) || 0, r.jednotka);
    if (baz.base === 'g' || baz.base === 'ml') totalMassG += baz.v;
  });
  const currentKg = totalMassG / 1000;

  if (mode === 'kg') {
    const targetKg = parseFloat(vkState.scale_target_kg) || 0;
    if (targetKg <= 0) return alert('Zadej cílovou hmotnost těsta.');
    if (currentKg <= 0) return alert('Receptura nemá platnou hmotnost.');
    mult = targetKg / currentKg;
    label = `${targetKg} kg těsta`;
  } else {
    const target = parseInt(vkState.scale_target_ks) || 0;
    if (target <= 0) return alert('Zadej cílový počet kusů.');
    const presKg = parseFloat(vkState.pres_kg) || 0;
    const klonkuPresu = parseInt(vkState.klonku_z_presu) || 0;
    const presCount = presKg > 0 ? currentKg / presKg : 0;
    const klonkuCelkem = Math.floor(presCount * klonkuPresu);
    const overrideKs = parseInt(vkState.scale_current_override) || 0;
    const currentKs = overrideKs > 0 ? overrideKs : klonkuCelkem;
    if (currentKs === 0) return alert('Není známo, kolik kusů aktuální receptura odpovídá. Doplň ručně.');
    mult = target / currentKs;
    label = `${target} ks`;
  }
  // 🆕 v2.9.286 — defenzivní validace mult (must be > 0, finite, sane upper bound 10000×)
  if (!isFinite(mult) || mult <= 0 || mult > 10000) {
    return alert(t('invalid_multiplier', { mult }));
  }
  if (!(await confirmDialog({ msg: t('confirm_overwrite_recipe', { label, mult: mult.toFixed(3) }), danger: false }))) return;
  vkState.receptura = vkState.receptura.map(r => {
    // 🆕 v2.9.286 — clamp na non-negative (defenzivně proti NaN/záporu)
    const novaMn = Math.max(0, (parseFloat(r.mnozstvi) || 0) * mult);
    return { ...r, mnozstvi: novaMn };
  });
  vkState.scale_target_ks = 0;
  vkState.scale_target_kg = 0;
  vkRender();
};

window.vkScaleTisk = function() {
  const mode = vkState.scale_mode || 'ks';
  const sur = (state._suroviny_cache || []).filter(s => s.aktivni == 1);
  const surById = Object.fromEntries(sur.map(s => [s.id, s]));

  let totalMassG = 0;
  vkState.receptura.forEach(r => {
    const baz = vkToBase(parseFloat(r.mnozstvi) || 0, r.jednotka);
    if (baz.base === 'g' || baz.base === 'ml') totalMassG += baz.v;
  });
  const currentKg = totalMassG / 1000;
  const presKg = parseFloat(vkState.pres_kg) || 0;
  const klonkuPresu = parseInt(vkState.klonku_z_presu) || 0;
  const presCount = presKg > 0 ? currentKg / presKg : 0;
  const klonkuCelkem = Math.floor(presCount * klonkuPresu);
  const overrideKs = parseInt(vkState.scale_current_override) || 0;
  const currentKs = overrideKs > 0 ? overrideKs : klonkuCelkem;

  let mult, target, targetUnit, targetTitle;
  if (mode === 'kg') {
    target = parseFloat(vkState.scale_target_kg) || 0;
    if (target <= 0) return alert('Zadej cílovou hmotnost těsta.');
    if (currentKg <= 0) return alert('Receptura nemá platnou hmotnost.');
    mult = target / currentKg;
    targetUnit = 'kg';
    targetTitle = `${target} kg těsta` + (currentKs > 0 ? ` (~${Math.round(currentKs * mult)} ks)` : '');
  } else {
    target = parseInt(vkState.scale_target_ks) || 0;
    if (target <= 0) return alert('Zadej cílový počet kusů.');
    if (currentKs === 0) return alert('Není známo, kolik kusů aktuální receptura odpovídá. Doplň ručně.');
    mult = target / currentKs;
    targetUnit = 'ks';
    targetTitle = `${target} ks`;
  }

  const vyrobek = vkState.vyrobek_id ? vkState.vyrobky_cache.find(x => x.id == vkState.vyrobek_id) : null;

  const fmtMn = (n) => {
    if (n < 0.01) return '< 0,01';
    return n.toFixed(n >= 100 ? 1 : (n >= 10 ? 2 : 3)).replace(/\.?0+$/, '').replace('.', ',');
  };

  const recRowsTisk = vkState.receptura.map(r => {
    const s = surById[r.surovina_id] || {};
    return `
      <tr>
        <td>${esc(s.nazev || '?')}</td>
        <td class="num">${fmtMn(parseFloat(r.mnozstvi) || 0)} ${esc(r.jednotka)}</td>
        <td class="num scale">${fmtMn((parseFloat(r.mnozstvi) || 0) * mult)} ${esc(r.jednotka)}</td>
        <td class="ok"></td>
      </tr>
    `;
  }).join('');

  // Pro zdobení: pokud máme cíl v ks, násobíme target. Pokud kg → potřebujeme odhadnutý počet ks
  const targetKsForZdobeni = mode === 'ks' ? target : (currentKs > 0 ? Math.round(currentKs * mult) : 0);
  const zdobRowsTisk = vkState.zdobeni
    .filter(z => z.surovina_id && z.mnozstvi)
    .map(z => {
      const s = surById[z.surovina_id] || {};
      return `
        <tr>
          <td>${esc(s.nazev || '?')} <small>(per kus)</small></td>
          <td class="num">${fmtMn(parseFloat(z.mnozstvi) || 0)} ${esc(z.jednotka || 'g')}</td>
          <td class="num scale">${fmtMn((parseFloat(z.mnozstvi) || 0) * targetKsForZdobeni)} ${esc(z.jednotka || 'g')}</td>
          <td class="ok"></td>
        </tr>
      `;
    }).join('');

  const html = `<!DOCTYPE html>
<html lang="cs"><head><meta charset="UTF-8"><title>Pracovní list pekaře — ${esc(vyrobek?.nazev || 'Volná receptura')}</title>
<style>
  *{box-sizing:border-box;margin:0;padding:0;font-family:-apple-system,Helvetica,sans-serif}
  body{padding:14mm 14mm;color:#000;font-size:11pt;line-height:1.4}
  .toolbar{display:flex;gap:8px;justify-content:flex-end;margin-bottom:12mm}
  .toolbar button{padding:8px 16px;border:1px solid #333;background:#fff;border-radius:6px;cursor:pointer}
  .toolbar .pr{background:#BA7517;color:#fff;border-color:#BA7517}
  h1{font-size:20pt;margin-bottom:3mm}
  .meta{display:flex;justify-content:space-between;margin-bottom:8mm;padding-bottom:4mm;border-bottom:1px solid #ccc;flex-wrap:wrap;gap:6mm}
  .meta-cell .lbl{font-size:9pt;color:#888;text-transform:uppercase;letter-spacing:0.5pt}
  .meta-cell .val{font-size:13pt;font-weight:600}
  .target{background:#FFFAF1;padding:5mm 7mm;border-radius:6px;border:1px solid #E8C988;display:flex;justify-content:space-between;align-items:center;margin-bottom:8mm}
  .target .big{font-size:24pt;font-weight:700;color:#854F0B}
  table{width:100%;border-collapse:collapse;margin-bottom:6mm}
  th{background:#F1EFE8;padding:3mm 4mm;text-align:left;font-size:10pt;color:#555;text-transform:uppercase;letter-spacing:0.5pt;font-weight:600;border-bottom:1px solid #aaa}
  td{padding:3.5mm 4mm;border-bottom:1px solid #E5E3DD;font-size:12pt}
  td.num{text-align:right;font-variant-numeric:tabular-nums}
  td.scale{font-weight:700;color:#15803D;font-size:13pt}
  td.ok{width:14mm;border-left:1px solid #aaa}
  td.ok::before{content:'☐';font-size:18pt;color:#999}
  small{color:#888;font-size:9pt}
  .footer{margin-top:14mm;padding-top:4mm;border-top:1px solid #ccc;display:flex;justify-content:space-between;font-size:9pt;color:#777}
  @media print { .toolbar { display:none } body { padding: 0 } @page { size: A4; margin: 14mm } }
</style></head><body>
<div class="toolbar"><button onclick="window.history.back()">← Zpět</button><button class="pr" onclick="window.print()">🖨 Tisk</button></div>
<h1>📋 Pracovní list pekaře</h1>
<div class="meta">
  <div class="meta-cell"><div class="lbl">Výrobek</div><div class="val">${esc(vyrobek?.nazev || '— volná receptura —')}</div></div>
  <div class="meta-cell"><div class="lbl">Datum</div><div class="val">${new Date().toLocaleDateString('cs-CZ')}</div></div>
  <div class="meta-cell"><div class="lbl">Personál</div><div class="val">_______________</div></div>
</div>
<div class="target">
  <span style="font-size:10pt;text-transform:uppercase;letter-spacing:0.5pt;color:#854F0B">${mode === 'kg' ? 'Cílová hmotnost těsta' : 'Cílový počet kusů'}</span>
  <span class="big">${targetTitle}</span>
</div>
<table>
  <thead>
    <tr><th>Surovina</th><th class="num" style="width:30mm">Originál</th><th class="num" style="width:36mm">Pro ${targetTitle}</th><th style="width:14mm">✓</th></tr>
  </thead>
  <tbody>${recRowsTisk}${zdobRowsTisk ? `<tr><td colspan="4" style="padding:5mm 0 2mm;font-weight:700;color:#999;text-transform:uppercase;font-size:9pt;letter-spacing:0.5pt">— Zdobení / náplň —</td></tr>${zdobRowsTisk}` : ''}</tbody>
</table>
<div class="footer">
  <span>Násobek: ${mult.toFixed(3).replace(/\.?0+$/, '').replace('.', ',')}× · Hmotnost těsta: ${(totalMassG / 1000 * mult).toFixed(2).replace('.', ',')} kg</span>
  <span>Vytištěno ${new Date().toLocaleString('cs-CZ')}</span>
</div>
</body></html>`;

  const w = window.open('', '_blank');
  w.document.write(html);
  w.document.close();
};

function vkSpocitatVysledek() {
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
  const surPerKus = klonkuCelkem > 0 ? surovinyKc / klonkuCelkem : 0;
  const fixniPerKus = klonkuCelkem > 0 ? fixniKc / klonkuCelkem : 0;
  const cenaPerKus = surPerKus + zdobeniKc + fixniPerKus;
  const marze = parseFloat(vkState.marze_pct) || 0;
  const cenaProdejBezDph = cenaPerKus * (1 + marze / 100);
  const dph = parseFloat(vkState.sazba_dph) || 0;
  const cenaProdejSDph = cenaProdejBezDph * (1 + dph / 100);
  return { cenaPerKus, cenaProdejBezDph, cenaProdejSDph, klonkuCelkem };
}

window.vkUlozitDoHistorie = function() {
  if (vkState.receptura.length === 0) return alert('Receptura je prázdná — nemá smysl ukládat snímek.');
  const v = vkState.vyrobek_id ? vkState.vyrobky_cache.find(x => x.id == vkState.vyrobek_id) : null;
  const navrhNazev = v
    ? `${v.nazev} — ${new Date().toLocaleDateString('cs-CZ')}`
    : `Volná kalkulace — ${new Date().toLocaleDateString('cs-CZ')}`;

  openModal('💾 Uložit snímek kalkulace', `
    <p style="font-size:13px;color:var(--text-2);margin-bottom:14px">
      Uloží aktuální kalkulaci včetně <strong>cen surovin v dnešní den</strong>. Můžeš ji kdykoli načíst zpět — uvidíš ceny tak, jak byly v okamžiku uložení (ne aktuální).
    </p>
    <div class="form-grid form-grid-tight" style="grid-template-columns:1fr">
      <div>
        <label class="form-label">Název snímku</label>
        <input class="form-input" id="vk-snap-nazev" value="${esc(navrhNazev)}" placeholder="např. Bageta delikates — leden 2026">
      </div>
      <div>
        <label class="form-label">Poznámka (volitelná)</label>
        <textarea class="form-textarea" id="vk-snap-poznamka" rows="2" placeholder="např. Po zdražení mouky o 15 %"></textarea>
      </div>
    </div>
    <div style="display:flex;gap:8px;justify-content:flex-end;margin-top:14px">
      <button class="btn-secondary" onclick="closeModal()">Zrušit</button>
      <button class="btn-primary btn-green" onclick="vkUlozitDoHistorieRun()">💾 Uložit snímek</button>
    </div>
  `);
};

window.vkUlozitDoHistorieRun = async function() {
  const nazev = document.getElementById('vk-snap-nazev').value.trim();
  const poznamka = document.getElementById('vk-snap-poznamka').value.trim();
  const v = vkState.vyrobek_id ? vkState.vyrobky_cache.find(x => x.id == vkState.vyrobek_id) : null;
  const r = vkSpocitatVysledek();
  try {
    await api('admin_kalkulace_historie.php', {
      method: 'POST',
      body: JSON.stringify({
        nazev,
        poznamka,
        vyrobek_id: vkState.vyrobek_id || null,
        vyrobek_nazev: v ? v.nazev : '',
        vyrobni_cena_per_kus: r.cenaPerKus,
        cena_prodej_bez_dph: r.cenaProdejBezDph,
        cena_prodej_s_dph: r.cenaProdejSDph,
        klonku_celkem: r.klonkuCelkem,
        data: {
          receptura: vkState.receptura,
          pres_kg: vkState.pres_kg,
          klonku_z_presu: vkState.klonku_z_presu,
          zdobeni: vkState.zdobeni,
          fixni: vkState.fixni,
          marze_pct: vkState.marze_pct,
          sazba_dph: vkState.sazba_dph,
        },
      }),
    });
    closeModal();
    const t = document.createElement('div');
    t.style.cssText = 'position:fixed;bottom:24px;right:24px;background:var(--success-bg);color:var(--success-text);padding:14px 22px;border-radius:8px;box-shadow:0 4px 12px rgba(0,0,0,0.15);font-size:14px;font-weight:600;z-index:1000';
    t.textContent = '✓ Snímek uložen do historie';
    document.body.appendChild(t);
    setTimeout(() => t.remove(), 2200);
  } catch (e) { alert('Chyba: ' + e.message); }
};

window.vkOtevritHistorii = async function() {
  let list;
  try {
    const params = vkState.vyrobek_id ? `?vyrobek_id=${vkState.vyrobek_id}` : '';
    list = await api('admin_kalkulace_historie.php' + params);
  } catch (e) { return alert('Chyba: ' + e.message); }

  const fmtKc = n => n != null ? n.toFixed(4).replace(/\.?0+$/, '').replace('.', ',') + ' Kč' : '—';
  const fmtDateTime = s => {
    if (!s) return '';
    const d = new Date(s.replace(' ', 'T'));
    return isNaN(d) ? s : d.toLocaleString('cs-CZ');
  };

  const filterTitle = vkState.vyrobek_id
    ? `pro výrobek <strong>${esc(vkState.vyrobky_cache.find(x => x.id == vkState.vyrobek_id)?.nazev || '?')}</strong>`
    : 'všechny';

  openModal('📂 Historie kalkulací', `
    <div style="display:flex;gap:8px;align-items:center;margin-bottom:12px;flex-wrap:wrap">
      <input class="filter-input" id="vk-hist-q" placeholder="Hledat (název, výrobek)…" oninput="vkHistFilter(this.value)" style="flex:1;min-width:200px">
      <span style="font-size:12px;color:var(--text-3)">${list.length} snímků ${filterTitle}</span>
      ${vkState.vyrobek_id ? `<button class="btn-secondary" onclick="vkOtevritHistorii_vse()" style="font-size:12px">Zobrazit všechny</button>` : ''}
    </div>
    <div style="max-height:60vh;overflow:auto;border:1px solid var(--border);border-radius:6px">
      <table class="table" id="vk-hist-table" style="margin:0;font-size:13px">
        <thead>
          <tr>
            <th>Název</th>
            <th>Výrobek</th>
            <th class="num">Cena/kus</th>
            <th class="num">Prodej s DPH</th>
            <th class="num">Klonků</th>
            <th>Datum</th>
            <th></th>
          </tr>
        </thead>
        <tbody>
          ${list.length === 0 ? `<tr><td colspan="7" style="padding:24px;text-align:center;color:var(--text-3)">Žádné uložené snímky</td></tr>` : list.map(k => `
            <tr data-search="${esc((k.nazev + ' ' + (k.vyrobek_nazev || '')).toLowerCase())}">
              <td><strong>${esc(k.nazev)}</strong>${k.poznamka ? `<div style="font-size:11px;color:var(--text-3);margin-top:2px">${esc(k.poznamka)}</div>` : ''}</td>
              <td style="font-size:12px;color:var(--text-2)">${esc(k.vyrobek_nazev || '— volná —')}</td>
              <td class="num">${fmtKc(k.vyrobni_cena_per_kus)}</td>
              <td class="num"><strong>${fmtKc(k.cena_prodej_s_dph)}</strong></td>
              <td class="num">${k.klonku_celkem ?? '—'}</td>
              <td style="font-size:11px;color:var(--text-3);white-space:nowrap">${fmtDateTime(k.vytvoreno)}</td>
              <td onclick="event.stopPropagation();" style="white-space:nowrap;text-align:right">
                <button class="btn-primary" onclick="vkNacistZHistorie(${k.id})" style="font-size:11px;padding:4px 8px">📥 Načíst</button>
                <button class="btn-secondary" onclick="vkKlonovatHistorii(${k.id})" style="font-size:11px;padding:4px 8px;margin-left:2px" title="Vytvoří kopii — můžeš ji upravit a uložit znovu">📋 Klonovat</button>
                <button class="btn-danger" onclick="vkSmazatHistorii(${k.id})" style="font-size:11px;padding:4px 8px;margin-left:2px">×</button>
              </td>
            </tr>
          `).join('')}
        </tbody>
      </table>
    </div>
    <div style="display:flex;gap:8px;justify-content:flex-end;margin-top:14px">
      <!-- "Zavřít" smazáno v v2.5.11 — × v rohu modal-card stačí (větší klikací plocha) -->
    </div>
  `, 'wide');
};

window.vkOtevritHistorii_vse = function() {
  // Trik — dočasně vyřaď filtr výrobku
  const orig = vkState.vyrobek_id;
  vkState.vyrobek_id = null;
  vkOtevritHistorii().finally(() => { vkState.vyrobek_id = orig; });
};

window.vkHistFilter = function(q) {
  q = (q || '').toLowerCase();
  document.querySelectorAll('#vk-hist-table tbody tr').forEach(tr => {
    const t = tr.dataset.search || '';
    tr.style.display = t.includes(q) ? '' : 'none';
  });
};

window.vkNacistZHistorie = async function(id) {
  try {
    const k = await api(`admin_kalkulace_historie.php?id=${id}`);
    if (!k || !k.data) return alert('Snímek nelze načíst');

    // Aplikuj data zpět do vkState
    const d = k.data;
    vkState.vyrobek_id = k.vyrobek_id || null;
    vkState.receptura = Array.isArray(d.receptura) ? d.receptura : [];
    vkState.pres_kg = d.pres_kg || 1.8;
    vkState.klonku_z_presu = d.klonku_z_presu || 30;
    vkState.zdobeni = Array.isArray(d.zdobeni) ? d.zdobeni : [];
    vkState.fixni = Array.isArray(d.fixni) ? d.fixni : [];
    vkState.marze_pct = d.marze_pct ?? 50;
    vkState.sazba_dph = d.sazba_dph ?? 12;
    vkState.current_vyrobni_cena = k.vyrobni_cena_per_kus ?? null;

    // KLÍČOVÉ: pokud snapshot obsahuje historické ceny surovin, dočasně je aplikujeme do cache
    // (aby kalkulace přepočítala na tehdejší ceny, ne na aktuální)
    const sapTotal = collectSnapshots(d);
    if (sapTotal.length > 0) {
      vkApplySnapshotPrices(sapTotal);
    }

    closeModal();
    vkRender();

    const t = document.createElement('div');
    t.style.cssText = 'position:fixed;bottom:24px;right:24px;background:var(--surface-1);color:var(--text-1);padding:14px 22px;border-radius:8px;box-shadow:0 4px 12px rgba(0,0,0,0.15);font-size:13px;font-weight:600;z-index:1000;border:1px solid var(--border);max-width:320px';
    t.innerHTML = `📂 Načteno: <strong>${esc(k.nazev)}</strong>${sapTotal.length > 0 ? `<div style="font-size:11px;font-weight:400;color:var(--text-3);margin-top:4px">⚠ Použity ceny surovin z ${new Date(k.vytvoreno?.replace(' ', 'T')).toLocaleDateString('cs-CZ')} (snapshot)</div>` : ''}`;
    document.body.appendChild(t);
    setTimeout(() => t.remove(), 5000);
  } catch (e) { alert('Chyba: ' + e.message); }
};

// Helper: vytáhne všechny snapshoty surovin z dat
function collectSnapshots(data) {
  const snaps = [];
  (data.receptura || []).forEach(r => {
    if (r.surovina_id && r._snapshot) snaps.push({ id: r.surovina_id, ...r._snapshot });
  });
  (data.zdobeni || []).forEach(z => {
    if (z.surovina_id && z._snapshot) snaps.push({ id: z.surovina_id, ...z._snapshot });
  });
  return snaps;
}

// Obnoví původní (aktuální DB) ceny surovin v cache po načteném snímku
function vkRestoreOriginalPrices() {
  if (!Array.isArray(state._suroviny_cache)) return;
  state._suroviny_cache = state._suroviny_cache.filter(s => !s._ghost);
  state._suroviny_cache.forEach(s => {
    if (s._snapshot_active) {
      if (s._original_cena_baleni !== undefined) s.cena_baleni = s._original_cena_baleni;
      if (s._original_obsah_baleni !== undefined) s.obsah_baleni = s._original_obsah_baleni;
      delete s._original_cena_baleni;
      delete s._original_obsah_baleni;
      delete s._snapshot_active;
    }
  });
}

// Aplikuje historické ceny surovin do _suroviny_cache (jen pro výpočet — neukládá zpět)
function vkApplySnapshotPrices(snaps) {
  if (!Array.isArray(state._suroviny_cache)) return;
  const byId = Object.fromEntries(state._suroviny_cache.map(s => [s.id, s]));
  snaps.forEach(snap => {
    const s = byId[snap.id];
    if (s) {
      s._original_cena_baleni = s._original_cena_baleni ?? s.cena_baleni;
      s._original_obsah_baleni = s._original_obsah_baleni ?? s.obsah_baleni;
      s.cena_baleni = snap.cena_baleni;
      s.obsah_baleni = snap.obsah_baleni;
      s._snapshot_active = true;
    } else {
      // Surovina už neexistuje — vlož stub do cache
      state._suroviny_cache.push({
        id: snap.id,
        nazev: snap.nazev + ' (smazaná surovina)',
        jednotka: snap.jednotka,
        cena_baleni: snap.cena_baleni,
        obsah_baleni: snap.obsah_baleni,
        aktivni: 1,
        _snapshot_active: true,
        _ghost: true,
      });
    }
  });
}

window.vkSmazatHistorii = async function(id) {
  if (!await confirmDelete2x('tento snímek kalkulace')) return;
  try {
    await api(`admin_kalkulace_historie.php?id=${id}`, { method: 'DELETE' });
    vkOtevritHistorii(); // refresh
  } catch (e) { alert('Chyba: ' + e.message); }
};

window.vkKlonovatHistorii = async function(id) {
  try {
    await api('admin_kalkulace_historie.php?action=clone', {
      method: 'POST',
      body: JSON.stringify({ id }),
    });
    vkOtevritHistorii();
  } catch (e) { alert('Chyba: ' + e.message); }
};

// ════════════════════════════════════════════════════════════════════
// 🌍 v2.9.14 — LANG DROPDOWN — BULLETPROOF (event delegation)
// ════════════════════════════════════════════════════════════════════
window.ADM_LANG_META = {
  cs: { flag: '🇨🇿', code: 'CS', name: 'Čeština' },
  sk: { flag: '🇸🇰', code: 'SK', name: 'Slovenčina' },
  en: { flag: '🇬🇧', code: 'EN', name: 'English' },
  de: { flag: '🇩🇪', code: 'DE', name: 'Deutsch' },
  es: { flag: '🇪🇸', code: 'ES', name: 'Español' },
};

window.adminToggleLangDropdown = function(e) {
  if (e) { e.stopPropagation(); e.preventDefault(); }
  var menu = document.getElementById('adm-lang-menu');
  var trig = document.getElementById('adm-lang-trigger');
  if (!menu || !trig) {
    console.warn('[lang] elements missing', { menu: !!menu, trig: !!trig });
    return;
  }
  var isOpen = menu.classList.contains('show');
  if (isOpen) {
    menu.classList.remove('show');
    menu.style.display = 'none';
    trig.setAttribute('aria-expanded', 'false');
  } else {
    menu.classList.add('show');
    // v2.9.23 — FLAGS-ONLY row layout (5 vlajek vedle sebe, žádné labely)
    menu.style.position = 'absolute';
    menu.style.top = 'calc(100% + 6px)';
    menu.style.right = '0';
    menu.style.left = 'auto';
    menu.style.display = 'flex';
    menu.style.flexDirection = 'column';   // v2.9.24 — vlajky POD SEBOU
    menu.style.alignItems = 'center';
    menu.style.justifyContent = 'flex-start';
    menu.style.gap = '3px';
    menu.style.zIndex = '10050';
    menu.style.minWidth = '0';              // auto width — jen na vlajky
    menu.style.width = 'auto';
    menu.style.maxWidth = '320px';
    menu.style.background = '#fff';
    menu.style.border = '1px solid rgba(0,0,0,0.12)';
    menu.style.borderRadius = '12px';
    menu.style.padding = '6px';
    menu.style.boxShadow = '0 8px 24px rgba(0,0,0,0.18)';
    menu.style.whiteSpace = 'nowrap';
    var wrap = menu.parentElement;
    if (wrap && wrap.classList && wrap.classList.contains('lang-dropdown-wrap')) {
      wrap.style.position = 'relative';
    }
    // Force flag-only styling na všechny opt buttons
    var opts = menu.querySelectorAll('.lang-opt');
    opts.forEach(function(btn){
      btn.style.display = 'inline-flex';
      btn.style.alignItems = 'center';
      btn.style.justifyContent = 'center';
      btn.style.width = '38px';
      btn.style.height = '38px';
      btn.style.minWidth = '38px';
      btn.style.padding = '0';
      btn.style.margin = '0';
      btn.style.gap = '0';
      btn.style.fontSize = '22px';
      btn.style.lineHeight = '1';
      btn.style.borderRadius = '8px';
      btn.style.border = '1px solid transparent';
      btn.style.background = 'transparent';
      btn.style.cursor = 'pointer';
      btn.style.boxSizing = 'border-box';
      // Smaž případné zbylé .lang-name spany (defensive)
      var name = btn.querySelector('.lang-name');
      if (name) name.style.display = 'none';
    });
    trig.setAttribute('aria-expanded', 'true');
  }
};

window.adminPickLang = function(code) {
  if (!window.ADM_LANG_META[code]) code = 'cs';
  var meta = window.ADM_LANG_META[code];
  var flag = document.getElementById('adm-lang-flag');
  var codeEl = document.getElementById('adm-lang-code');
  if (flag) flag.textContent = meta.flag;
  if (codeEl) codeEl.textContent = meta.code;
  // Active state
  Object.keys(window.ADM_LANG_META).forEach(function(c) {
    var btn = document.getElementById('adm-lang-' + c);
    if (btn) btn.classList.toggle('is-active', c === code);
  });
  // Close
  var menu = document.getElementById('adm-lang-menu');
  var trig = document.getElementById('adm-lang-trigger');
  if (menu) { menu.classList.remove('show'); menu.style.display = 'none'; }
  if (trig) trig.setAttribute('aria-expanded', 'false');
  // Apply language
  if (typeof window.setAppekLang === 'function') window.setAppekLang(code);
};

// Event delegation — vždy fungující bez ohledu na load timing
document.addEventListener('click', function(e) {
  // Click on trigger button
  var trig = e.target.closest('#adm-lang-trigger');
  if (trig) {
    window.adminToggleLangDropdown(e);
    return;
  }
  // Click on lang option
  var opt = e.target.closest('.lang-opt');
  if (opt && opt.id && opt.id.indexOf('adm-lang-') === 0 && opt.id !== 'adm-lang-trigger' && opt.id !== 'adm-lang-menu') {
    var code = opt.dataset.lang || opt.id.replace('adm-lang-', '');
    window.adminPickLang(code);
    return;
  }
  // Outside click — close menu
  var inWrap = e.target.closest('.lang-dropdown-wrap');
  if (!inWrap) {
    var menu = document.getElementById('adm-lang-menu');
    var trig2 = document.getElementById('adm-lang-trigger');
    if (menu && menu.classList.contains('show')) {
      menu.classList.remove('show');
      menu.style.display = 'none';
      if (trig2) trig2.setAttribute('aria-expanded', 'false');
    }
  }
}, true); // capture: true — zachytí kliky DŘÍV než inline onclick handlers

// Escape close
document.addEventListener('keydown', function(e) {
  if (e.key !== 'Escape') return;
  var menu = document.getElementById('adm-lang-menu');
  if (menu && menu.classList.contains('show')) {
    menu.classList.remove('show');
    menu.style.display = 'none';
    var trig = document.getElementById('adm-lang-trigger');
    if (trig) trig.setAttribute('aria-expanded', 'false');
  }
});

// 🆕 v2.9.63 — applyTopbarSpacing ZRUŠENO.
// Předtím přepisovalo topbar inline `!important` styly → zneplatnilo ~24 CSS bloků
// a CSS spacing byl mrtvý kód. Spacing teď řeší JEDEN čistý CSS blok (.topbar-clean).
// Funkce ponechána jako no-op pro zpětnou kompatibilitu volání.
window.applyTopbarSpacing = function() { /* no-op — spacing řeší CSS .topbar-clean blok */ };

// Sync trigger + force-close menu on init (v2.9.23 — FLAGS-ONLY row layout)
(function syncAdmLangTrigger() {
  function apply() {
    // 🛡️ Force CLOSED state + inline positioning (immune to CSS issues)
    var menu = document.getElementById('adm-lang-menu');
    var trig = document.getElementById('adm-lang-trigger');
    if (menu) {
      menu.classList.remove('show');
      menu.classList.add('lang-menu--flags-only');
      menu.style.position = 'absolute';
      menu.style.top = 'calc(100% + 6px)';
      menu.style.right = '0';
      menu.style.left = 'auto';
      menu.style.display = 'none';
      menu.style.zIndex = '10050';
      menu.style.flexDirection = 'column';  // v2.9.24 — vlajky POD SEBOU
      menu.style.alignItems = 'center';
      menu.style.justifyContent = 'flex-start';
      menu.style.gap = '3px';
      menu.style.minWidth = '0';
      menu.style.width = 'auto';
      menu.style.maxWidth = '320px';
      menu.style.background = '#fff';
      menu.style.border = '1px solid rgba(0,0,0,0.12)';
      menu.style.borderRadius = '12px';
      menu.style.padding = '6px';
      menu.style.boxShadow = '0 8px 24px rgba(0,0,0,0.18)';
      menu.style.whiteSpace = 'nowrap';
      // Defensive — odstraň všechny .lang-name spany pokud zbyly z předchozí verze
      menu.querySelectorAll('.lang-name').forEach(function(n){
        n.style.display = 'none';
        n.style.visibility = 'hidden';
        n.style.width = '0';
        n.style.fontSize = '0';
      });
      // Force parent wrapper to position:relative
      var wrap = menu.parentElement;
      if (wrap && wrap.classList && wrap.classList.contains('lang-dropdown-wrap')) {
        wrap.style.position = 'relative';
        wrap.style.display = 'inline-block';
      }
    }
    if (trig) trig.setAttribute('aria-expanded', 'false');

    // 🆕 v2.9.49 — Topbar spacing přes JS inline styly (CSS cache-immune)
    applyTopbarSpacing();

    // Update flag/code/active state
    var cur = 'cs';
    try { cur = window.appekCurrentLang || localStorage.getItem('appek_lang') || 'cs'; } catch(e) {}
    if (!window.ADM_LANG_META[cur]) cur = 'cs';
    var meta = window.ADM_LANG_META[cur];
    var flag = document.getElementById('adm-lang-flag');
    var code = document.getElementById('adm-lang-code');
    if (flag) flag.textContent = meta.flag;
    if (code) code.textContent = meta.code;
    Object.keys(window.ADM_LANG_META).forEach(function(c) {
      var btn = document.getElementById('adm-lang-' + c);
      if (btn) btn.classList.toggle('is-active', c === cur);
    });
  }
  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', apply);
  } else {
    apply();
  }
  // Re-apply několikrát — admin DOM se loaduje postupně po loginu
  setTimeout(apply, 100);
  setTimeout(apply, 500);
  setTimeout(apply, 1500);
})();

