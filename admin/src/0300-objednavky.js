// =============================================================
// OBJEDNÁVKY
// =============================================================
// 🔴 v2.9.120 — červená tečka „doklad byl po vytvoření upraven" (obj/fa/dl seznamy)
function upravenoDot(edited) {
  return edited ? ' <span class="upraveno-dot" title="Doklad byl po vytvoření upraven"></span>' : '';
}

// 🆕 v3.0.213 — je balíček aktivní? (licencovaný + zapnutý, nebo core). Cache v session.
async function packageActive(key) {
  if (!state._pkgActive) {
    try {
      const r = await api('admin_packages.php');
      state._pkgActive = new Set((r.packages || []).filter(p => p.always_on || (p.enabled && p.licensed)).map(p => p.key));
    } catch (e) { state._pkgActive = new Set(); }
  }
  return state._pkgActive.has(key);
}

// 🆕 v3.0.212 — registr prodejních kanálů (puvod) z backendu; cache v session.
async function loadKanalyRegistry() {
  if (state._kanaly && state._kanalyMap) return state._kanaly;
  try {
    const r = await api('admin_nastaveni.php?action=kanaly');
    state._kanaly = (r && Array.isArray(r.kanaly)) ? r.kanaly : [];
  } catch (e) { state._kanaly = []; }
  state._kanalyMap = {};
  for (const k of state._kanaly) state._kanalyMap[k.klic] = k;
  return state._kanaly;
}

// 🆕 v3.0.212 — badge původu objednávky (ikona + tint barvy kanálu, label na hover).
function puvodBadge(o) {
  if (!o || !o.puvod) return '';
  const m = (state._kanalyMap && state._kanalyMap[o.puvod]) || {};
  const ikona = o.puvod_ikona || m.ikona, label = o.puvod_label || m.label || o.puvod, barva = o.puvod_barva || m.barva || '#6B7280';
  if (!ikona) return '';
  return ` <span title="${esc(label)}" style="display:inline-block;background:${barva}1A;color:${barva};font-size:10px;font-weight:700;padding:2px 6px;border-radius:99px;vertical-align:middle">${ikona}</span>`;
}

// 🆕 v3.0.212 — Nastavení → Prodejní kanály: editovatelná tabulka.
async function renderKanalyPanel() {
  const el = document.getElementById('kanaly-container');
  if (!el) return;
  state._kanaly = null; state._kanalyMap = null; // vynuť čerstvé načtení
  await loadKanalyRegistry();
  const rows = state._kanaly || [];
  if (!rows.length) { el.innerHTML = '<div style="text-align:center;padding:20px;color:var(--text-3)">Žádné kanály</div>'; return; }
  el.innerHTML = rows.map(k => `
    <div class="kanal-row" data-klic="${esc(k.klic)}" style="display:flex;align-items:center;gap:12px;padding:10px 12px;border:1px solid var(--border);border-radius:10px;margin-bottom:8px;flex-wrap:wrap;${k.zapnuto ? '' : 'opacity:0.55'}">
      <input type="color" value="${esc(k.barva || '#6B7280')}" data-f="barva" style="width:32px;height:32px;border:none;background:none;cursor:pointer;flex-shrink:0;padding:0" title="Barva štítku">
      <span style="font-size:20px;flex-shrink:0">${k.ikona || '•'}</span>
      <input class="form-input" data-f="label" value="${esc(k.label || '')}" style="flex:1;min-width:150px" placeholder="Název kanálu" maxlength="40">
      <span style="font-size:11px;color:var(--text-3);font-family:monospace;background:var(--surface-2);padding:5px 9px;border-radius:6px;flex-shrink:0" title="Číselná řada dokladů (proti přebíjení)">${esc(k.rada || 'OBJ')}-</span>
      <label style="display:flex;align-items:center;gap:6px;font-size:13px;cursor:pointer;white-space:nowrap"><input type="checkbox" data-f="pokladni" ${k.pokladni ? 'checked' : ''} style="width:16px;height:16px;cursor:pointer"> 🧾 Pokladní</label>
      <label style="display:flex;align-items:center;gap:6px;font-size:13px;cursor:pointer;white-space:nowrap;font-weight:700"><input type="checkbox" data-f="zapnuto" ${k.zapnuto ? 'checked' : ''} style="width:16px;height:16px;cursor:pointer" onchange="this.closest('.kanal-row').style.opacity=this.checked?'1':'0.55'"> Zapnuto</label>
    </div>
  `).join('') + `
    <p style="font-size:11px;color:var(--text-3);margin-top:10px;line-height:1.5">💡 Pokladní = objednávky kanálu se započítají do POS Účtenek a denní uzávěrky. Řada (např. POS-, B2B-, DORT-) je pevná a zajišťuje, že se čísla dokladů nepřebíjejí.</p>`;
}

async function ulozitKanaly() {
  const kanaly = {};
  document.querySelectorAll('#kanaly-container .kanal-row').forEach(r => {
    const klic = r.getAttribute('data-klic');
    if (!klic) return;
    kanaly[klic] = {
      label:    r.querySelector('[data-f=label]')?.value || '',
      barva:    r.querySelector('[data-f=barva]')?.value || '#6B7280',
      pokladni: !!r.querySelector('[data-f=pokladni]')?.checked,
      zapnuto:  !!r.querySelector('[data-f=zapnuto]')?.checked,
    };
  });
  try {
    await api('admin_nastaveni.php?action=kanaly', { method: 'POST', body: JSON.stringify({ kanaly }) });
    state._kanaly = null; state._kanalyMap = null; // invaliduj cache → projeví se v Objednávkách
    toastSuccess('Kanály uloženy');
  } catch (e) {
    toastError('Uložení kanálů selhalo');
  }
}

// 🆕 v3.0.277 — SPRÁVA VRATEK: sjednocený přehled POS refundací (VRA-) + dobropisů (DOB-),
//   propojený s původním dokladem, s datumy vrácení a lhůtou na vrácení (politika dní).
async function renderVratky() {
  const c = document.getElementById('content');
  c.innerHTML = `<div class="page-head"><div><h1 class="page-title">↩️ Vratky</h1><p class="page-sub">Načítám…</p></div></div>`;
  let d;
  try { d = await api('admin_vratky.php' + (state._vratkyTyp ? '?typ=' + state._vratkyTyp : '')); }
  catch (e) { c.innerHTML = `<div class="page-head"><h1 class="page-title">↩️ Vratky</h1></div><p style="color:var(--danger-text)">Chyba: ${esc(e.message)}</p>`; return; }
  const s = d.souhrn || {};
  const lhuta = d.lhuta_dni || 14;
  const typ = state._vratkyTyp || '';
  const chip = (val, label) => `<button class="period-tab ${typ === val ? 'active' : ''}" onclick="state._vratkyTyp='${val}';renderVratky()" style="padding:5px 12px;font-size:13px">${label}</button>`;
  c.innerHTML = `
    <div class="page-head" style="display:flex;justify-content:space-between;align-items:flex-start;flex-wrap:wrap;gap:10px">
      <div>
        <h1 class="page-title">↩️ Vratky</h1>
        <p class="page-sub">POS refundace (VRA-) i dobropisy (DOB-), propojené s původním dokladem.</p>
      </div>
      <button class="btn-secondary" onclick="navigate('objednavky')">← Objednávky</button>
    </div>

    <div class="stat-grid">
      <div class="stat-card"><div class="stat-label">Vratek celkem</div><div class="stat-value">${s.pocet || 0}</div></div>
      <div class="stat-card"><div class="stat-label">Vráceno</div><div class="stat-value" style="color:#16a34a">${fmt(s.celkem_kc || 0)}</div></div>
      <div class="stat-card"><div class="stat-label">🧾 POS refundace</div><div class="stat-value">${s.pocet_pos || 0}</div></div>
      <div class="stat-card"><div class="stat-label">📄 Dobropisy</div><div class="stat-value">${s.pocet_faktura || 0}</div></div>
    </div>

    <div class="card-block" style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:12px;margin:12px 0;padding:12px 16px">
      <div style="display:flex;gap:6px;flex-wrap:wrap">${chip('', 'Vše')}${chip('pos', '🧾 POS')}${chip('faktura', '📄 Dobropisy')}</div>
      <div style="display:flex;align-items:center;gap:8px">
        <label style="font-size:13px;font-weight:600" title="Politika: do kolika dní od prodeje lze výrobek vrátit">⏳ Lhůta na vrácení</label>
        <input type="number" id="vratky-lhuta" value="${lhuta}" min="0" max="365" style="width:70px;text-align:right" class="form-input">
        <span style="font-size:13px;color:var(--text-3)">dní</span>
        <button class="btn-secondary" onclick="ulozitVratkaLhuta()" style="font-size:13px">💾 Uložit</button>
      </div>
    </div>

    <div class="card-block" style="padding:0">
      ${(d.vratky || []).length === 0 ? '<div class="empty-state" style="padding:30px">Žádné vratky</div>' : `
        <table class="table">
          <thead><tr><th>Doklad</th><th>Typ</th><th>Datum vrácení</th><th>Původní doklad</th><th>Zákazník / pokladní</th><th class="num">Částka</th><th>V lhůtě</th><th>Důvod</th></tr></thead>
          <tbody>
            ${d.vratky.map(v => {
              // 🆕 v3.0.284 — proklik na detail v adminu: VRA- → detail objednávky-refundace,
              // DOB- → detail dobropisu; původní doklad stejně. PDF zvlášť přes 🖨️ (jen faktury).
              const detailFn = v.typ === 'faktura' ? 'openFakturaDetail' : 'openObjednavkaDetail';
              const pdfIco = (id, title) => v.typ === 'faktura' ? ` <a href="../api/faktura.php?id=${id}" target="_blank" title="${title}" style="text-decoration:none" onclick="event.stopPropagation()">🖨️</a>` : '';
              const docLink = `<a href="#" onclick="event.stopPropagation();${detailFn}(${v.id});return false" style="color:var(--brand);font-weight:700">${esc(v.cislo)}</a>${pdfIco(v.id, 'PDF dobropisu')}`;
              const origLink = v.puvodni_id
                ? `<a href="#" onclick="event.stopPropagation();${detailFn}(${v.puvodni_id});return false" style="color:var(--brand)">${esc(v.puvodni_cislo || '')}</a>${pdfIco(v.puvodni_id, 'PDF původní faktury')}`
                : (v.puvodni_cislo ? esc(v.puvodni_cislo) : '<span style="color:var(--text-3)">—</span>');
              const lhutaBadge = v.v_lhute === true ? `<span style="background:#DCFCE7;color:#166534;padding:2px 8px;border-radius:10px;font-size:11px;font-weight:600">✓ ${v.dni_od_prodeje} d</span>`
                : v.v_lhute === false ? `<span style="background:#FEE2E2;color:#991B1B;padding:2px 8px;border-radius:10px;font-size:11px;font-weight:600" title="Vráceno po nastavené lhůtě">⚠️ ${v.dni_od_prodeje} d</span>`
                : '<span style="color:var(--text-3)">—</span>';
              return `<tr style="cursor:pointer" title="Klikni pro detail" onclick="${detailFn}(${v.id})">
                <td>${docLink}</td>
                <td style="white-space:nowrap">${v.typ === 'faktura' ? '📄 Dobropis' : '🧾 POS refundace'}</td>
                <td style="white-space:nowrap">${fmtDate(v.datum_vratky)}</td>
                <td>${origLink}</td>
                <td>${esc(v.kdo || '—')}</td>
                <td class="num" style="font-weight:700;color:#16a34a;white-space:nowrap">${fmt(v.castka)}</td>
                <td>${lhutaBadge}</td>
                <td style="color:var(--text-2);font-size:13px">${esc(v.duvod || '')}</td>
              </tr>`;
            }).join('')}
          </tbody>
        </table>
      `}
    </div>
  `;
}
// 📊 v3.0.284/286 — Google Analytics: oddělená ID pro B2B portál (gtag vkládá b2b/app.js)
// a POS pokladnu (gtag vkládá server-side admin/pos.php + pos/index.php).
window.ulozitGaId = async function() {
  const rxOk = (v) => !v || /^(G|AW|UA)-[A-Z0-9-]{4,}$/i.test(v);
  const b2b = (document.getElementById('ns-ga-id').value || '').trim();
  const pos = ((document.getElementById('ns-ga-id-pos') || {}).value || '').trim();
  const core = ((document.getElementById('ns-ga-id-core') || {}).value || '').trim();
  if (!rxOk(b2b) || !rxOk(pos) || !rxOk(core)) { toast('❌ Neplatné ID — čekám formát G-XXXXXXXXXX', 'error'); return; }
  // 🍪 v3.0.401 — vlastní sledovací kód pro B2B portál (vkládá se až po souhlasu s cookies)
  const trk = ((document.getElementById('ns-tracking-code') || {}).value || '').trim();
  try {
    await api('admin_nastaveni.php', { method: 'PUT', body: JSON.stringify({ ga_measurement_id: b2b, ga_measurement_id_pos: pos, ga_measurement_id_core: core, tracking_custom_code: trk }) });
    const stavy = [];
    if (b2b) stavy.push(`B2B ${b2b}`);
    if (pos) stavy.push(`POS ${pos}`);
    if (core) stavy.push(`Admin ${core}`);
    toast(stavy.length ? '✅ Google Analytics uloženo' : '✅ Google Analytics vypnuto', 'success');
    const info = document.getElementById('ns-ga-info');
    if (info) info.textContent = stavy.length ? `Měří se: ${stavy.join(' · ')}` : 'Vypnuto';
    if (core) aplikovatGaCore(core);   // okamžitě aktivuj na adminu (bez reloadu)
  } catch (e) { toast('❌ ' + (e.message || 'Uložení selhalo'), 'error'); }
};

window.ulozitVratkaLhuta = async function() {
  const v = parseInt(document.getElementById('vratky-lhuta').value) || 14;
  try {
    await api('admin_vratky.php', { method: 'PUT', body: JSON.stringify({ lhuta_dni: v }) });
    if (typeof toast === 'function') toast('✅ Lhůta na vrácení uložena: ' + v + ' dní', 'success');
    renderVratky();
  } catch (e) { if (typeof toast === 'function') toast('❌ ' + (e.message || 'Nepodařilo se uložit'), 'error'); }
};

// 🆕 v3.0.281 — VOUCHERY / DÁRKOVÉ KARTY — kódy s hodnotou, částečné uplatnění, dobíjení.
async function renderVouchers() {
  const c = document.getElementById('content');
  c.innerHTML = `<div class="page-head"><div><h1 class="page-title">🎟️ Vouchery & dárkové karty</h1><p class="page-sub">Načítám…</p></div></div>`;
  let d, odb = [];
  try { d = await api('admin_vouchers.php'); }
  catch (e) { c.innerHTML = `<div class="page-head"><h1 class="page-title">🎟️ Vouchery</h1></div><p style="color:var(--danger-text)">Chyba: ${esc(e.message)}</p>`; return; }
  try { odb = await api('admin_odberatele.php'); if (!Array.isArray(odb)) odb = odb.odberatele || odb.data || []; } catch (e) { odb = []; }
  const odbOpts = '<option value="">— bez odběratele —</option>' + (odb || []).map(o => {
    const em = o.email || o.login_email || '';
    return `<option value="${o.id}" data-email="${esc(em)}">${esc(o.nazev)}${em ? ' — ' + esc(em) : ' (bez emailu)'}</option>`;
  }).join('');
  const s = d.souhrn || {};
  const stavBadge = (v) => {
    const m = { aktivni: ['#DCFCE7', '#166534', 'Aktivní'], vycerpany: ['#E5E7EB', '#374151', 'Vyčerpaný'], zruseny: ['#FEE2E2', '#991B1B', 'Zrušený'], expirovany: ['#FEF3C7', '#92400e', 'Expirovaný'] };
    const a = m[v.stav_aktualni] || m.aktivni;
    return `<span style="background:${a[0]};color:${a[1]};padding:2px 8px;border-radius:10px;font-size:11px;font-weight:600">${a[2]}</span>`;
  };
  c.innerHTML = `
    <div class="page-head" style="display:flex;justify-content:space-between;align-items:flex-start;flex-wrap:wrap;gap:10px">
      <div><h1 class="page-title">🎟️ Vouchery & dárkové karty</h1><p class="page-sub">Poukazy a dárkové karty s hodnotou — částečné uplatnění na pokladně, dárkové karty lze dobíjet.</p></div>
      <button class="btn-secondary" onclick="navigate('nastaveni')">← Nastavení</button>
    </div>
    <div class="stat-grid">
      <div class="stat-card"><div class="stat-label">Celkem</div><div class="stat-value">${s.pocet || 0}</div></div>
      <div class="stat-card"><div class="stat-label">Aktivních</div><div class="stat-value">${s.aktivnich || 0}</div></div>
      <div class="stat-card"><div class="stat-label">Vydaná hodnota</div><div class="stat-value">${fmt(s.hodnota_celkem || 0)}</div></div>
      <div class="stat-card"><div class="stat-label">Nevyčerpáno (aktivní)</div><div class="stat-value" style="color:#16a34a">${fmt(s.zustatek_aktivni || 0)}</div></div>
    </div>

    <div class="card-block" style="margin:12px 0;padding:14px 16px">
      <h3 style="margin:0 0 10px;font-size:15px">➕ Vytvořit nové</h3>
      <div style="display:flex;gap:10px;flex-wrap:wrap;align-items:flex-end">
        <div><label class="form-label" style="font-size:12px">Typ</label><select class="form-input" id="vch-typ" style="width:180px" onchange="voucherTypChange()"><option value="voucher">🎟️ Voucher (Kč)</option><option value="darkova_karta">💳 Dárková karta (Kč)</option><option value="sleva">🏷️ Slevový voucher (%)</option></select></div>
        <div id="vch-kc-wrap"><label class="form-label" style="font-size:12px">Hodnota (Kč)</label><input class="form-input" id="vch-hodnota" type="number" min="1" step="1" value="500" style="width:110px"></div>
        <div id="vch-pct-wrap" style="display:none"><label class="form-label" style="font-size:12px">Sleva (%)</label><input class="form-input" id="vch-pct" type="number" min="1" max="100" step="1" value="10" style="width:90px"></div>
        <div id="vch-max-wrap" style="display:none"><label class="form-label" style="font-size:12px">Max sleva (Kč, volit.)</label><input class="form-input" id="vch-max" type="number" min="0" step="1" placeholder="bez stropu" style="width:120px"></div>
        <div id="vch-pocet-wrap"><label class="form-label" style="font-size:12px">Počet</label><input class="form-input" id="vch-pocet" type="number" min="1" max="500" value="1" style="width:80px"></div>
        <div><label class="form-label" style="font-size:12px">Platnost do (volitelné)</label><input class="form-input" id="vch-platnost" type="date" min="${new Date().toISOString().slice(0,10)}" style="width:160px"></div>
      </div>
      <div style="display:flex;gap:10px;flex-wrap:wrap;align-items:flex-end;margin-top:10px">
        <div style="min-width:240px"><label class="form-label" style="font-size:12px">Pro odběratele (volitelné)</label><select class="form-input" id="vch-odberatel" style="min-width:240px" onchange="voucherOdbChange()">${odbOpts}</select></div>
        <label style="display:flex;align-items:center;gap:6px;font-size:13px;padding-bottom:8px;cursor:pointer"><input type="checkbox" id="vch-email" onchange="voucherOdbChange()"> 📧 Poslat emailem</label>
        <span id="vch-email-info" style="font-size:12px;color:var(--text-3);padding-bottom:9px"></span>
        <div style="flex:1;min-width:140px"><label class="form-label" style="font-size:12px">Poznámka</label><input class="form-input" id="vch-pozn" placeholder="např. věrnostní sleva"></div>
        <button class="btn-primary btn-green" onclick="voucherVytvorit()">Vytvořit</button>
      </div>
      <div id="vch-vysledek" style="margin-top:10px"></div>
    </div>

    <div class="card-block" style="padding:0">
      ${(d.vouchery || []).length === 0 ? '<div class="empty-state" style="padding:30px">Žádné vouchery — vytvoř první nahoře</div>' : `
        <table class="table">
          <thead><tr><th>Kód</th><th>Typ</th><th>Hodnota / sleva</th><th class="num">Zůstatek</th><th>Odběratel</th><th>Platnost</th><th>Stav</th><th></th></tr></thead>
          <tbody>
            ${d.vouchery.map(v => {
              const typLabel = v.typ === 'darkova_karta' ? '💳 Dárková karta' : (v.typ === 'sleva' ? '🏷️ Sleva %' : '🎟️ Voucher');
              const odeslano = String(v.odeslano_email) === '1';
              return `
              <tr>
                <td><strong style="font-family:monospace;letter-spacing:0.5px">${esc(v.kod)}</strong></td>
                <td style="white-space:nowrap">${typLabel}</td>
                <td style="white-space:nowrap">${esc(v.hodnota_text || fmt(v.hodnota))}</td>
                <td class="num" style="font-weight:700;${v.typ === 'sleva' ? 'color:var(--text-3)' : (parseFloat(v.zustatek) > 0 ? 'color:#16a34a' : 'color:var(--text-3)')}">${v.typ === 'sleva' ? '—' : fmt(v.zustatek)}</td>
                <td style="word-break:break-word">${v.odberatel_nazev ? `${esc(v.odberatel_nazev)}${odeslano ? ` <span title="Odesláno emailem ${esc(v.odeslano_kdy || '')}" style="color:#16a34a">📧</span>` : ''}` : '<span style="color:var(--text-3)">—</span>'}</td>
                <td style="white-space:nowrap">${v.platnost_do ? fmtDate(v.platnost_do) : '<span style="color:var(--text-3)">bez omezení</span>'}</td>
                <td>${stavBadge(v)}</td>
                <td style="white-space:nowrap;text-align:right">
                  ${v.odberatel_email && v.stav_aktualni !== 'zruseny' ? `<button class="btn-secondary" style="font-size:12px;padding:4px 10px" onclick="voucherPoslatEmail(${v.id}, '${esc(v.kod)}')">📧 ${odeslano ? 'Znovu' : 'Poslat'}</button>` : ''}
                  ${v.typ === 'darkova_karta' && v.stav_aktualni !== 'zruseny' ? `<button class="btn-secondary" style="font-size:12px;padding:4px 10px" onclick="voucherDobit(${v.id}, '${esc(v.kod)}')">➕ Dobít</button>` : ''}
                  ${v.stav_aktualni !== 'zruseny' ? `<button class="btn-secondary" style="font-size:12px;padding:4px 10px;color:#DC2626;border-color:#FCA5A5" onclick="voucherDeaktivovat(${v.id}, '${esc(v.kod)}')">Zrušit</button>` : ''}
                </td>
              </tr>`;
            }).join('')}
          </tbody>
        </table>
      `}
    </div>
  `;
  voucherTypChange(); voucherOdbChange();   // počáteční stav polí (Kč/% + email)
}
window.voucherTypChange = function() {
  const typ = (document.getElementById('vch-typ') || {}).value;
  const isSleva = typ === 'sleva';
  const show = (id, on) => { const el = document.getElementById(id); if (el) el.style.display = on ? '' : 'none'; };
  show('vch-kc-wrap', !isSleva);
  show('vch-pct-wrap', isSleva);
  show('vch-max-wrap', isSleva);
};
window.voucherOdbChange = function() {
  const sel = document.getElementById('vch-odberatel');
  const chk = document.getElementById('vch-email');
  const info = document.getElementById('vch-email-info');
  const pocetInp = document.getElementById('vch-pocet');
  if (!sel) return;
  const opt = sel.selectedOptions[0];
  const email = opt ? (opt.getAttribute('data-email') || '') : '';
  const odbId = sel.value;
  if (chk) {
    if (!odbId || !email) { chk.checked = false; chk.disabled = true; }
    else chk.disabled = false;
  }
  if (info) {
    if (chk && chk.checked && email) info.textContent = '→ ' + email;
    else if (odbId && !email) info.textContent = '⚠️ odběratel nemá email';
    else info.textContent = '';
  }
  if (pocetInp) {
    if (chk && chk.checked) { pocetInp.value = 1; pocetInp.disabled = true; }
    else pocetInp.disabled = false;
  }
};
window.voucherPoslatEmail = async function(id, kod) {
  if (!confirm(`Poslat voucher ${kod} emailem odběrateli?`)) return;
  try { const r = await api('admin_vouchers.php?action=send_email', { method: 'POST', body: JSON.stringify({ id }) }); toast(`✅ Odesláno na ${r.na}`, 'success'); renderVouchers(); }
  catch (e) { toast('❌ ' + (e.message || 'Odeslání selhalo'), 'error'); }
};
window.voucherVytvorit = async function() {
  const typ = document.getElementById('vch-typ').value;
  const pocet = parseInt(document.getElementById('vch-pocet').value) || 1;
  const platnost_do = document.getElementById('vch-platnost').value || null;
  const poznamka = document.getElementById('vch-pozn').value || '';
  const odberatel_id = parseInt(document.getElementById('vch-odberatel').value) || 0;
  const send_email = !!(document.getElementById('vch-email') || {}).checked;
  const payload = { typ, pocet, platnost_do, poznamka, odberatel_id: odberatel_id || undefined, send_email };
  if (typ === 'sleva') {
    const pct = parseFloat(document.getElementById('vch-pct').value) || 0;
    if (pct <= 0 || pct > 100) { toast('Sleva musí být 1–100 %', 'error'); return; }
    payload.sleva_pct = pct;
    const max = parseFloat(document.getElementById('vch-max').value) || 0;
    if (max > 0) payload.sleva_max_kc = max;
  } else {
    const hodnota = parseFloat(document.getElementById('vch-hodnota').value) || 0;
    if (hodnota <= 0) { toast('Zadej kladnou hodnotu', 'error'); return; }
    payload.hodnota = hodnota;
  }
  if (send_email && !odberatel_id) { toast('Pro odeslání emailem vyber odběratele', 'error'); return; }
  try {
    const r = await api('admin_vouchers.php?action=create', { method: 'POST', body: JSON.stringify(payload) });
    const emailMsg = r.email ? ` · 📧 ${r.email.odeslano}/${r.email.z} odesláno na ${esc(r.email.na || '')}` : '';
    const html = `<div style="background:var(--surface-2);border-radius:8px;padding:10px 12px;font-size:13px">✅ Vytvořeno ${r.pocet} ks · kódy: ${r.vytvoreno.map(x => `<strong style="font-family:monospace">${esc(x.kod)}</strong>`).join(', ')}${emailMsg}</div>`;
    toast(`✅ Vytvořeno ${r.pocet} ${r.pocet === 1 ? 'kus' : 'ks'}${r.email && r.email.odeslano ? ' + email' : ''}`, 'success');
    await renderVouchers();
    const el2 = document.getElementById('vch-vysledek'); if (el2) el2.innerHTML = html;
  } catch (e) { toast('❌ ' + (e.message || 'Vytvoření selhalo'), 'error'); }
};
window.voucherDeaktivovat = async function(id, kod) {
  if (!confirm(`Zrušit voucher ${kod}? Nepůjde už uplatnit.`)) return;
  try { await api('admin_vouchers.php?action=deactivate', { method: 'POST', body: JSON.stringify({ id }) }); toast('Zrušeno', 'success'); renderVouchers(); }
  catch (e) { toast('❌ ' + (e.message || 'Chyba'), 'error'); }
};
window.voucherDobit = async function(id, kod) {
  const v = prompt(`Dobít dárkovou kartu ${kod} o kolik Kč?`, '500');
  if (v === null) return;
  const castka = parseFloat(v) || 0;
  if (castka <= 0) { toast('Zadej kladnou částku', 'error'); return; }
  try { const r = await api('admin_vouchers.php?action=topup', { method: 'POST', body: JSON.stringify({ id, castka }) }); toast(`✅ Dobito, zůstatek ${fmt(r.zustatek)}`, 'success'); renderVouchers(); }
  catch (e) { toast('❌ ' + (e.message || 'Dobití selhalo'), 'error'); }
};

// ═══════════════════════════════════════════════════════════
// 💱 MĚNA & PŘEPOČET (v3.0.283) — Nastavení → Přístupy & ceny
// ═══════════════════════════════════════════════════════════
window.menaLoad = async function() {
  const el = document.getElementById('ns-mena-body');
  if (!el) return;
  try {
    const r = await api('admin_mena.php');
    const c = r.config || {};
    window._menaCfg = c;
    const opts = Object.entries(r.podporovane || { CZK: 'Kč', EUR: '€' })
      .map(([k, s]) => `<option value="${k}" ${c.kod === k ? 'selected' : ''}>${k} (${s})</option>`).join('');
    const cizi = c.kod !== 'CZK';
    el.innerHTML = `
      <div style="display:flex;gap:10px;flex-wrap:wrap;align-items:flex-end">
        <div><label class="form-label" style="font-size:12px">Cílová měna</label>
          <select class="form-input" id="mena-kod" style="width:130px" onchange="menaKodChange()">${opts}</select></div>
        <div id="mena-zdroj-wrap" style="${cizi ? '' : 'display:none'}"><label class="form-label" style="font-size:12px">Zdroj kurzu</label>
          <div style="display:flex;gap:4px">
            <label style="display:flex;align-items:center;gap:4px;font-size:13px;cursor:pointer;border:1px solid var(--border);border-radius:6px;padding:6px 9px"><input type="radio" name="mena-zdroj" value="rucni" ${c.kurz_zdroj !== 'cnb' ? 'checked' : ''} onchange="menaZdrojChange()"> ✍️ Vlastní</label>
            <label style="display:flex;align-items:center;gap:4px;font-size:13px;cursor:pointer;border:1px solid var(--border);border-radius:6px;padding:6px 9px"><input type="radio" name="mena-zdroj" value="cnb" ${c.kurz_zdroj === 'cnb' ? 'checked' : ''} onchange="menaZdrojChange()"> 🏦 ČNB</label>
          </div></div>
        <div id="mena-kurz-wrap" style="${cizi ? '' : 'display:none'}"><label class="form-label" style="font-size:12px">Kurz (1 <span id="mena-kurz-kod">${esc(c.kod)}</span> = ? Kč)</label>
          <input class="form-input" id="mena-kurz" type="number" min="0.0001" step="0.001" value="${c.kurz || 1}" style="width:120px" ${c.kurz_zdroj === 'cnb' ? 'readonly' : ''}></div>
        <button class="btn-secondary" id="mena-cnb-btn" style="${cizi ? '' : 'display:none'}" onclick="menaCnb()">🔄 Aktualizovat z ČNB</button>
        <span id="mena-cnb-info" style="font-size:12px;color:var(--text-3);padding-bottom:9px"></span>
      </div>
      <!-- 🆕 v3.0.367 — hint když je CZK (kurz skrytý) → user „nevidím nastavení kurzu" -->
      <div id="mena-cils-hint" ${cizi ? 'hidden' : ''} style="margin-top:10px;font-size:12px;color:var(--text-2);background:var(--info-bg,#EFF6FF);border:1px dashed var(--border);border-radius:8px;padding:9px 12px">💡 Výchozí měna je <strong>Kč</strong>. Pro <strong>vlastní kurz</strong> nebo přepočet vyber výše cizí měnu (EUR, USD…) — objeví se volba zdroje kurzu (✍️ Vlastní / 🏦 ČNB) i pole pro kurz.</div>
      <div style="display:flex;gap:18px;flex-wrap:wrap;align-items:center;margin-top:12px" id="mena-volby" ${cizi ? '' : 'hidden'}>
        <label style="display:flex;align-items:center;gap:6px;font-size:13px;cursor:pointer">
          <input type="radio" name="mena-zobr" value="kc" ${c.zobrazeni !== 'mena' ? 'checked' : ''}> Zobrazovat v Kč (základ)</label>
        <label style="display:flex;align-items:center;gap:6px;font-size:13px;cursor:pointer">
          <input type="radio" name="mena-zobr" value="mena" ${c.zobrazeni === 'mena' ? 'checked' : ''}> Zobrazovat přepočtené v cílové měně</label>
        <label style="display:flex;align-items:center;gap:6px;font-size:13px;cursor:pointer">
          <input type="checkbox" id="mena-dual" ${c.dual_doklady ? 'checked' : ''}> Informativní přepočet na dokladech</label>
      </div>
      <div style="margin-top:12px;display:flex;gap:8px;align-items:center">
        <button class="btn-primary btn-green" onclick="menaSave()">💾 Uložit měnu</button>
        <span id="mena-save-info" style="font-size:12px;color:var(--text-3)"></span>
      </div>
      <div style="border-top:1px dashed var(--border);margin-top:14px;padding-top:12px" ${cizi ? '' : 'hidden'} id="mena-prepocet-sekce">
        <strong style="font-size:13px">⚠️ Trvalý přepočet ceníku</strong>
        <p class="page-sub" style="font-size:12px;margin:4px 0 8px">Přepíše uložené ceny všech výrobků (cena ÷ kurz). Nevratné bez zálohy — záloha DB se vytvoří automaticky před přepočtem.</p>
        <div style="display:flex;gap:8px;flex-wrap:wrap;align-items:center">
          <button class="btn-secondary" onclick="menaPrepocetNahled()">👁️ Náhled přepočtu</button>
          <div id="mena-prepocet-nahled" style="font-size:12px;flex-basis:100%"></div>
        </div>
      </div>`;
  } catch (e) { el.innerHTML = `<span style="color:var(--danger-text)">Chyba: ${esc(e.message)}</span>`; }
};
window.menaKodChange = function() {
  const kod = document.getElementById('mena-kod').value;
  const cizi = kod !== 'CZK';
  document.getElementById('mena-kurz-wrap').style.display = cizi ? '' : 'none';
  const zw = document.getElementById('mena-zdroj-wrap'); if (zw) zw.style.display = cizi ? '' : 'none';
  document.getElementById('mena-cnb-btn').style.display = cizi ? '' : 'none';
  document.getElementById('mena-volby').hidden = !cizi;
  document.getElementById('mena-prepocet-sekce').hidden = !cizi;
  const ch = document.getElementById('mena-cils-hint'); if (ch) ch.hidden = cizi;  // 🆕 v3.0.367
  const kk = document.getElementById('mena-kurz-kod'); if (kk) kk.textContent = kod;
};
// 🆕 v3.0.339 — přepínač zdroje kurzu: vlastní (editovatelný) vs ČNB (zamčený + auto-fetch)
window.menaZdrojChange = function() {
  const zdroj = (document.querySelector('input[name="mena-zdroj"]:checked') || {}).value || 'rucni';
  const kurzEl = document.getElementById('mena-kurz');
  if (kurzEl) kurzEl.readOnly = (zdroj === 'cnb');
  const btn = document.getElementById('mena-cnb-btn');
  if (btn) btn.style.opacity = (zdroj === 'cnb') ? '1' : '0.6';
  if (zdroj === 'cnb') menaCnb(); // hned natáhni aktuální kurz ČNB
};
window.menaCnb = async function() {
  const kod = document.getElementById('mena-kod').value;
  const info = document.getElementById('mena-cnb-info');
  info.textContent = '⏳ ČNB…';
  try {
    const r = await api(`admin_mena.php?action=cnb&kod=${encodeURIComponent(kod)}`);
    document.getElementById('mena-kurz').value = r.kurz;
    info.textContent = `✅ ČNB ${r.datum}: 1 ${kod} = ${r.kurz} Kč`;
  } catch (e) { info.textContent = '❌ ' + (e.message || 'ČNB nedostupná'); }
};
window.menaSave = async function() {
  const kod = document.getElementById('mena-kod').value;
  const cfg = {
    kod,
    kurz: parseFloat(document.getElementById('mena-kurz').value) || 1,
    zobrazeni: (document.querySelector('input[name="mena-zobr"]:checked') || {}).value || 'kc',
    dual_doklady: !!(document.getElementById('mena-dual') || {}).checked,
    kurz_zdroj: (document.querySelector('input[name="mena-zdroj"]:checked') || {}).value || 'rucni',
  };
  try {
    const r = await api('admin_mena.php?action=save', { method: 'POST', body: JSON.stringify({ config: cfg }) });
    window._menaCfg = r.config;
    toast('✅ Měna uložena', 'success');
    const info = document.getElementById('mena-save-info');
    if (info) info.textContent = r.config.zobrazeni === 'mena' ? `Ceny se zobrazují v ${r.config.kod} (kurz ${r.config.kurz})` : 'Ceny se zobrazují v Kč';
  } catch (e) { toast('❌ ' + (e.message || 'Uložení selhalo'), 'error'); }
};
window.menaPrepocetNahled = async function() {
  const kurz = parseFloat(document.getElementById('mena-kurz').value) || 0;
  const el = document.getElementById('mena-prepocet-nahled');
  if (kurz <= 0) { el.innerHTML = '<span style="color:var(--danger-text)">Zadej kladný kurz</span>'; return; }
  el.innerHTML = '⏳ Počítám…';
  try {
    const r = await api(`admin_mena.php?action=prepocet_nahled&kurz=${kurz}`);
    el.innerHTML = `
      <div style="background:var(--surface-2);border-radius:8px;padding:10px 12px;margin-top:8px">
        <strong>${r.pocet} výrobků</strong> · kurz ${kurz} · ukázka:
        <table class="table" style="font-size:12px;margin-top:6px">
          <thead><tr><th>Výrobek</th><th class="num">Teď (Kč)</th><th class="num">Po přepočtu</th></tr></thead>
          <tbody>${(r.ukazky || []).map(u => `<tr><td>${esc(u.nazev)}</td><td class="num">${u.stara.toFixed(2)}</td><td class="num"><strong>${u.nova.toFixed(2)}</strong></td></tr>`).join('')}</tbody>
        </table>
        <button class="btn-primary" style="margin-top:8px;background:#DC2626" onclick="menaPrepocet(${kurz})">⚠️ Zálohovat DB a TRVALE přepočítat</button>
      </div>`;
  } catch (e) { el.innerHTML = `<span style="color:var(--danger-text)">${esc(e.message)}</span>`; }
};
window.menaPrepocet = async function(kurz) {
  if (!confirm(`TRVALE přepočítat ceny všech výrobků kurzem ${kurz}?\n\nNejdřív se automaticky vytvoří záloha DB.`)) return;
  const el = document.getElementById('mena-prepocet-nahled');
  try {
    el.innerHTML = '💾 Vytvářím zálohu DB…';
    await api('admin_zalohy.php?action=create', { method: 'POST', body: JSON.stringify({ label: `před přepočtem měny (kurz ${kurz})`, include_uploads: false }) });
    el.innerHTML = '⏳ Přepočítávám ceník…';
    const r = await api('admin_mena.php?action=prepocet', { method: 'POST', body: JSON.stringify({ kurz }) });
    el.innerHTML = `<div style="background:var(--success-bg);color:var(--success-text);border-radius:8px;padding:10px 12px;margin-top:8px">✅ Přepočteno ${r.prepocteno} výrobků kurzem ${r.kurz}. Záloha DB vytvořena (Údržba → Zálohy).</div>`;
    toast(`✅ Ceník přepočten (${r.prepocteno} výrobků)`, 'success');
  } catch (e) { el.innerHTML = `<span style="color:var(--danger-text)">❌ ${esc(e.message)} — ceny NEzměněny${String(e.message).includes('záloh') ? '' : ' (pokud selhala záloha)'}</span>`; }
};

async function renderObjednavky(filters = {}, opts = {}) {
  // 🆕 v3.0.218 — paging (offset/limit + total); styl dle pagination_styl (load_more/stranky/infinite)
  const append = !!opts.append;
  const pg = (state._objPag ??= { items: [], total: 0, offset: 0, limit: 10, filters: {} });
  if (append) {
    pg.offset = pg.items.length;                       // další dávka navazuje
  } else if (opts.offset !== undefined) {
    pg.offset = Math.max(0, opts.offset); pg.filters = filters;  // skok na stránku
  } else {
    pg.offset = 0; pg.items = []; pg.filters = filters;          // nový filtr → reset
  }

  const c0 = document.getElementById('content');
  if (c0 && !append && opts.offset === undefined) c0.innerHTML = `
    <div class="page-head"><div><h1 class="page-title">🛒 Objednávky</h1><p class="page-sub">${skeletonLine('120px', '12px')}</p></div></div>
    <div class="card-block">${skeletonTable(8)}</div>
  `;
  await loadPaginationStyl(); applyPagLimit(pg);   // 🆕 v3.0.218
  await loadKanalyRegistry();   // 🆕 v3.0.212 — pro chips + badge dle kanálů

  const qp = new URLSearchParams({ ...pg.filters, offset: pg.offset, limit: pg.limit }).toString();
  let resp;
  try { resp = await api('admin_objednavky.php?' + qp); } catch (e) { resp = {}; }
  // Backend vrací {objednavky,total,has_more} (nebo legacy pole)
  let batch = Array.isArray(resp) ? resp : (Array.isArray(resp.objednavky) ? resp.objednavky : []);
  pg.total = Array.isArray(resp) ? batch.length : (Number.isFinite(resp.total) ? resp.total : batch.length);
  pg.items = append ? pg.items.concat(batch) : batch;
  // 🆕 v3.0.255 — per-zdroj počty ze serveru (přes CELÝ dataset, ne jen načtená stránka) pro ZDROJ čipy
  pg.counts = (resp && resp.counts && typeof resp.counts === 'object') ? resp.counts : null;
  pg.countsTotal = (resp && Number.isFinite(resp.counts_total)) ? resp.counts_total : null;
  pg.souhrn = (resp && resp.souhrn && typeof resp.souhrn === 'object') ? resp.souhrn : null; // 🆕 v3.0.255 — server tržba/k-vyfakturování (celý dataset, ne stránka)

  const list = pg.items;
  const total = pg.total;
  const c = document.getElementById('content');
  state._objList = list;
  if (!state._objSelected) state._objSelected = new Set();

  const isSel = (id) => state._objSelected.has(id);
  // Pokrok existing IDs only
  const validIds = new Set(list.map(o => o.id));
  for (const id of [...state._objSelected]) if (!validIds.has(id)) state._objSelected.delete(id);

  // 🆕 v3.0.255 — souhrn (tržba, k vyfakturování) ze serveru přes CELÝ filtrovaný dataset,
  //   ne jen z načtené stránky (dřív „Celková tržba" odpovídala jen 25 zobrazeným = špatně).
  //   Fallback na načtenou stránku, kdyby backend souhrn nevrátil.
  const sumCelkem = pg.souhrn ? (parseFloat(pg.souhrn.celkem_kc) || 0)
    : list.reduce((a, o) => a + (parseFloat(o.castka_celkem) || 0), 0);
  const sumKVyfakturovani = pg.souhrn ? (parseFloat(pg.souhrn.k_vyfakturovani_kc) || 0)
    : list
      .filter(o => o.stav !== 'zrusena' && (parseInt(o.pocet_faktur) || 0) === 0)
      .reduce((a, o) => a + (parseFloat(o.castka_celkem) || 0), 0);

  c.innerHTML = `
    <div class="page-head">
      <div>
        <h1 class="page-title">🛒 Objednávky</h1>
        <p class="page-sub">${total} <span>objednávek</span>${list.length < total ? ` · <span>zobrazeno</span> ${list.length}` : ''}</p>
      </div>
      <div style="display:flex;gap:8px;flex-wrap:wrap;align-items:center">
        <button class="btn-secondary" onclick="navigate('vratky')" title="Správa vratek — POS refundace + dobropisy, lhůta na vrácení">↩️ Vratky</button>
        <button class="btn-secondary" onclick="navigate('recurring')" title="Opakující se objednávky (cron)">🔁 Opakující</button>
        <button class="btn-primary btn-green btn-big-action" onclick="otevritNovouObjednavku()">+ Nová objednávka</button>
      </div>
    </div>

    <div class="stat-grid">
      <div class="stat-card">
        <div class="stat-label">Celkem objednávek</div>
        <div class="stat-value">${total}</div>
      </div>
      <div class="stat-card">
        <div class="stat-label">Celková tržba</div>
        <div class="stat-value">${fmt(sumCelkem)}</div>
      </div>
      <div class="stat-card">
        <div class="stat-label">K vyfakturování</div>
        <div class="stat-value ${sumKVyfakturovani > 0 ? 'warn' : ''}">${fmt(sumKVyfakturovani)}</div>
      </div>
    </div>

    ${dashStylePeriodHtml('obj', filters.datum_od, filters.datum_do)}

    <!-- 🆕 v3.0.212 — Puvod chips (řízené registrem kanálů + nastavením) -->
    ${(() => {
      // 🆕 v3.0.255 — počty per zdroj ze serveru (celý dataset); fallback na načtenou stránku
      const counts = pg.counts || list.reduce((a, o) => { const p = o.puvod || 'interni'; a[p] = (a[p] || 0) + 1; return a; }, {});
      const totalAll = (pg.countsTotal != null) ? pg.countsTotal : (pg.total || list.length);
      const activeP = filters.puvod || '';
      const chip = (val, label, icon, color) => `
        <button onclick="applyObjFilters({puvod:'${val}'})" style="display:inline-flex;align-items:center;gap:6px;padding:7px 14px;border-radius:99px;font-size:13px;font-weight:700;cursor:pointer;border:1.5px solid ${activeP === val ? color : '#E5E7EB'};background:${activeP === val ? color : '#fff'};color:${activeP === val ? '#fff' : '#374151'};transition:all 0.15s ease">
          <span>${icon}</span><span>${esc(label)}</span>
          ${counts[val] ? `<span style="background:${activeP === val ? 'rgba(255,255,255,0.25)' : '#F3F4F6'};font-size:11px;padding:1px 7px;border-radius:99px;font-weight:800">${counts[val]}</span>` : ''}
        </button>
      `;
      // Pořadí dle registru; ukaž zapnuté kanály + jakýkoli kanál s objednávkami (i vypnutý/legacy).
      const reg = state._kanaly || [];
      const known = new Set(reg.map(k => k.klic));
      const chipKeys = reg.filter(k => k.zapnuto || counts[k.klic]).map(k => k.klic);
      for (const p of Object.keys(counts)) if (!known.has(p)) chipKeys.push(p); // legacy puvod
      return `
        <div style="display:flex;gap:6px;flex-wrap:wrap;margin-bottom:14px;align-items:center">
          <span style="font-size:12px;font-weight:700;color:var(--text-3);text-transform:uppercase;letter-spacing:0.06em;margin-right:4px">Zdroj:</span>
          <button onclick="applyObjFilters({puvod:''})" style="display:inline-flex;align-items:center;gap:6px;padding:7px 14px;border-radius:99px;font-size:13px;font-weight:700;cursor:pointer;border:1.5px solid ${!activeP ? '#1F2937' : '#E5E7EB'};background:${!activeP ? '#1F2937' : '#fff'};color:${!activeP ? '#fff' : '#374151'}">
            <span>📋</span><span>Vše</span>
            <span style="background:${!activeP ? 'rgba(255,255,255,0.25)' : '#F3F4F6'};font-size:11px;padding:1px 7px;border-radius:99px;font-weight:800">${totalAll}</span>
          </button>
          ${chipKeys.map(k => { const m = state._kanalyMap?.[k] || { label: k, ikona: '•', barva: '#6B7280' }; return chip(k, m.label, m.ikona || '•', m.barva || '#6B7280'); }).join('')}
        </div>
      `;
    })()}

    <div class="filters">
      <input class="filter-input" type="search" id="of-q" placeholder="Hledat (číslo, odběratel)..." value="${filters.q || ''}">
      <select class="filter-select" id="of-stav">
        <option value="">Všechny stavy</option>
        ${['nova','potvrzena','ve_vyrobe','pripravena','expedovana','dorucena','zrusena'].map((s) => `
          <option value="${s}" ${filters.stav === s ? 'selected' : ''}>${statusLabel(s)}</option>
        `).join('')}
      </select>
      <div class="filter-dates-row filter-dates-row--off">
        <label class="filter-date-wrap">
          <span>Od:</span>
          <input class="filter-input" type="date" id="of-od" value="${filters.datum_od || ''}">
        </label>
        <label class="filter-date-wrap">
          <span>Do:</span>
          <input class="filter-input" type="date" id="of-do" value="${filters.datum_do || ''}">
        </label>
      </div>
      <button class="btn-secondary" onclick="applyObjFilters()">Filtrovat</button>
    </div>

    <!-- Desktop: tabulka -->
    <div class="card-block desktop-only-block wide-table-block">
      ${list.length === 0 ? (
        // 🐛 fix v2.0.55: 'd' byla nedefinovaná proměnná; použij filter heuristiku
        (Object.keys(filters || {}).length === 0)
          ? emptyState({
              icon: '📋',
              title: 'Zatím žádné objednávky',
              msg: 'Vytvoř první objednávku — buď ručně nebo dorazí přes B2B portal od odběratele.',
              actions: `<button class="btn-primary btn-green" onclick="otevritNovouObjednavku()" style="font-size:15px;padding:11px 22px">+ Nová objednávka</button>`,
            })
          : '<div class="empty-state">Žádná objednávka neodpovídá filtru</div>'
      ) : `
        <table class="table table-selectable">
          <thead>
            <tr>
              <th class="col-check"><input type="checkbox" id="obj-check-all" onchange="objSelectAll(this.checked)" title="Vybrat vše na stránce"></th>
              <th>Číslo</th>
              <th>Odběratel / pobočka</th>
              <th>Objednáno</th>
              <th>Dodání</th>
              <th>Stav</th>
              <th class="num">Pol.</th>
              <th>Doklady</th>
              <th class="num">Částka s DPH</th>
              <th class="col-akce"></th>
            </tr>
          </thead>
          <tbody>
            ${list.map((o) => `
              <tr class="row-clickable ${isSel(o.id) ? 'is-selected' : ''}" onclick="openObjednavkaDetail(${o.id})">
                <td class="col-check" onclick="event.stopPropagation();">
                  <input type="checkbox" ${isSel(o.id) ? 'checked' : ''} onchange="objToggleSelect(${o.id}, this.checked)" data-obj-check="${o.id}">
                </td>
                <td><strong>${esc(o.cislo)}</strong>${upravenoDot(o.pocet_zmen > 0)}${puvodBadge(o)}</td>
                <td>
                  <div>${esc(o.odberatel_nazev)}</div>
                  ${o.misto_nazev ? `<div style="font-size:11px;color:var(--text-3);margin-top:2px">📍 ${esc(o.misto_nazev)}</div>` : ''}
                </td>
                <td>${o.datum_objednani ? fmtDate(o.datum_objednani) : '<span style="color:var(--text-3)">—</span>'}</td>
                <td>${fmtDate(o.datum_dodani)}</td>
                <td><span class="status ${o.stav}">${statusLabel(o.stav)}</span></td>
                <td class="num">${o.pocet_polozek}</td>
                <td onclick="event.stopPropagation();">
                  <span class="doc-badges-row">
                    ${o.pocet_dl > 0
                      ? `<a href="../api/dodaci_list.php?id=${o.id}" target="_blank" class="doc-badge dl" title="Otevřít dodací list (PDF)">📃 DL</a>`
                      : `<span class="doc-badge dl unavailable" title="Dodací list zatím nevystaven">📃</span>`}
                    ${(o.pocet_faktur > 0 && o.prvni_faktura_id)
                      ? `<a href="../api/faktura.php?id=${o.prvni_faktura_id}" target="_blank" class="doc-badge fa" title="Otevřít fakturu (PDF)">💰 FA</a>`
                      : `<span class="doc-badge fa unavailable" title="Faktura zatím nevystavena">💰</span>`}
                  </span>
                </td>
                <td class="num"><strong>${fmt(o.castka_celkem)}</strong></td>
                <td onclick="event.stopPropagation();" style="white-space:nowrap;text-align:right">
                  <span class="doc-badges-row" style="justify-content:flex-end">
                    <button class="doc-badge reorder reorder-icon" onclick="noOpakovatZeZdroje('obj', ${o.id})" title="Znovu objednat">🔁</button>
                  </span>
                </td>
              </tr>
            `).join('')}
          </tbody>
        </table>
      `}
    </div>

    <!-- Mobile: kompaktní karty -->
    <div class="mobile-only-block wide-table-block">
      ${list.length === 0 ? '<div class="card-block"><div class="empty-state">Žádné objednávky</div></div>' : `
        <!-- 🆕 v3.0.90 — Select all toggle pro mobile (user: "objednávky select all přidat na mobilu") -->
        <div class="obj-mobile-selectall card-block" style="display:flex;align-items:center;gap:10px;padding:10px 14px;margin-bottom:10px;background:var(--surface-2,#F7F8FA);border:1px solid var(--border,rgba(0,0,0,0.08));border-radius:10px">
          <label style="display:flex;align-items:center;gap:8px;cursor:pointer;font-weight:600;font-size:13px;flex:1">
            <input type="checkbox" id="obj-check-all-mobile"
                   onchange="objSelectAll(this.checked)"
                   style="width:18px;height:18px;cursor:pointer;accent-color:var(--primary,#BA7517)">
            <span><span>Vybrat vše</span> (${list.length})</span>
          </label>
          <button class="btn-link" onclick="objClearSelection()" style="font-size:12px;padding:4px 8px">✕ Zrušit</button>
        </div>
      `}
      ${list.length === 0 ? '' : list.map((o) => `
          <div class="obj-card ${isSel(o.id) ? 'is-selected' : ''}" onclick="openObjednavkaDetail(${o.id})">
            <div class="obj-card-head">
              <label class="obj-card-check" onclick="event.stopPropagation();">
                <input type="checkbox" ${isSel(o.id) ? 'checked' : ''} onchange="objToggleSelect(${o.id}, this.checked)" data-obj-check="${o.id}">
              </label>
              <div class="obj-card-cislo">${esc(o.cislo)}${upravenoDot(o.pocet_zmen > 0)}${puvodBadge(o)}</div>
              <span class="status ${o.stav}">${statusLabel(o.stav)}</span>
            </div>
            <div class="obj-card-odb">${esc(o.odberatel_nazev)}</div>
            ${o.misto_nazev ? `<div class="obj-card-misto">📍 ${esc(o.misto_nazev)}</div>` : ''}
            <div class="obj-card-doklady" onclick="event.stopPropagation();">
              ${docBadge('dl', o.pocet_dl > 0 ? `../api/dodaci_list.php?id=${o.id}` : null, '📃 Dodací list', { disabledTitle: 'Dodací list zatím nevystaven' })}
              ${docBadge('fa', (o.pocet_faktur > 0 && o.prvni_faktura_id) ? `../api/faktura.php?id=${o.prvni_faktura_id}` : null, '💰 Faktura', { disabledTitle: 'Faktura zatím nevystavena' })}
              <button class="doc-badge reorder reorder-icon" onclick="noOpakovatZeZdroje('obj', ${o.id})" title="Znovu objednat">🔁</button>
            </div>
            <div class="obj-card-foot">
              <div class="obj-card-meta">
                ${o.datum_objednani ? `<span>📅 ${fmtDate(o.datum_objednani)}</span>` : ''}
                <span>🚚 ${fmtDate(o.datum_dodani)}</span>
                <span>📦 ${o.pocet_polozek} pol.</span>
              </div>
              <div class="obj-card-castka">${fmt(o.castka_celkem)}</div>
            </div>
          </div>
        `).join('')}
    </div>

    ${pagControlHtml('obj', pg, 'objGoToPage', 'objLoadMore')}

    <div id="obj-bulk-bar" class="bulk-bar" style="display:none;">
      <div class="bulk-bar-info">
        <span class="bulk-bar-count" id="obj-bulk-count">0</span>
        <span>vybráno</span>
      </div>
      <div class="bulk-bar-actions">
        <button class="btn-secondary" onclick="objBulkPreview()">📋 Náhled</button>
        <button class="btn-secondary" onclick="objBulkEmail()" title="Odeslat PDF objednávek e-mailem odběratelům">✉️ Odeslat email</button>
        ${adminOnly('<button class="btn-primary" onclick="objBulkAction(\'dl\')">📃 Vytvořit DL</button>')}
        ${adminOnly('<button class="btn-primary btn-green" onclick="objBulkAction(\'fa\')">💰 Vytvořit FA</button>')}
        <button class="btn-link" onclick="objClearSelection()">✕ Zrušit výběr</button>
      </div>
    </div>
  `;
  updateObjBulkBar();
  pagSetupInfinite('obj', pg, 'objLoadMore'); // 🆕 v3.0.218 — nekonečné scrollování (pokud styl=infinite)
}

// 🆕 v3.0.218 — paging helpers (sdílené pro hlavní seznamy)
async function loadPaginationStyl() {
  if (state._pagStyl) return state._pagStyl;
  try {
    const n = await api('admin_nastaveni.php');
    state._pagStyl = (n && n.pagination_styl) ? n.pagination_styl : 'load_more';
    // 🆕 v3.0.247 — počet řádků na stránku (volitelné). v3.0.277 — default 10 (rychlý první load), pak 25/50…
    const poc = parseInt(n && n.pagination_pocet);
    state._pagLimit = [10, 25, 50, 100, 200].includes(poc) ? poc : 10;
  } catch (e) { state._pagStyl = 'load_more'; state._pagLimit = state._pagLimit || 10; }
  return state._pagStyl;
}
// 🆕 v3.0.247 — aplikuj zvolený počet řádků na pg (reset offset při změně)
function applyPagLimit(pg) {
  const lim = state._pagLimit || 10;
  if (pg.limit !== lim) { pg.limit = lim; pg.offset = 0; }
}
// Vykreslí ovládání stránkování dle zvoleného stylu.
function pagControlHtml(key, pg, gotoFn, moreFn) {
  const styl = state._pagStyl || 'load_more';
  const shown = pg.items.length, total = pg.total || 0;
  if (total <= shown && pg.offset === 0) return ''; // vše se vešlo, žádné ovládání netřeba
  const info = `<span style="font-size:12px;color:var(--text-3)">Zobrazeno <strong>${shown}</strong> / <strong>${total}</strong></span>`;
  if (styl === 'stranky') {
    const limit = pg.limit || 50, pages = Math.max(1, Math.ceil(total / limit)), cur = Math.floor(pg.offset / limit);
    let btns = '';
    const win = 2;
    // 🐛 v3.0.267 — dedup „…" kontroloval btns.slice(-1)!=='…', ale btns končí '</span>'
    //   → nikdy nesepnul → jedna tečka za KAŽDOU přeskočenou stránku (při 203 stránkách
    //   ~200 teček přes několik řádků). Flag místo string-check.
    let lastEllipsis = false;
    for (let p = 0; p < pages; p++) {
      if (p > 1 && p < pages - 1 && Math.abs(p - cur) > win) {
        if (!lastEllipsis) { btns += '<span style="padding:0 4px;color:var(--text-3)">…</span>'; lastEllipsis = true; }
        continue;
      }
      lastEllipsis = false;
      btns += `<button onclick="${gotoFn}(${p})" style="min-width:34px;padding:6px 10px;border-radius:8px;border:1.5px solid ${p===cur?'#1F2937':'#E5E7EB'};background:${p===cur?'#1F2937':'#fff'};color:${p===cur?'#fff':'#374151'};font-weight:700;cursor:pointer">${p+1}</button>`;
    }
    return `<div style="display:flex;gap:6px;flex-wrap:wrap;align-items:center;justify-content:center;margin:16px 0">
      <button onclick="${gotoFn}(${Math.max(0,cur-1)})" ${cur===0?'disabled':''} style="padding:6px 12px;border-radius:8px;border:1.5px solid #E5E7EB;background:#fff;cursor:pointer;${cur===0?'opacity:.4':''}">‹</button>
      ${btns}
      <button onclick="${gotoFn}(${Math.min(pages-1,cur+1)})" ${cur>=pages-1?'disabled':''} style="padding:6px 12px;border-radius:8px;border:1.5px solid #E5E7EB;background:#fff;cursor:pointer;${cur>=pages-1?'opacity:.4':''}">›</button>
      <span style="margin-left:8px">${info}</span></div>`;
  }
  // load_more + infinite: tlačítko (u infinite navíc auto přes observer)
  const hasMore = shown < total;
  return `<div id="${key}-pag-more" style="display:flex;flex-direction:column;align-items:center;gap:6px;margin:16px 0">
    ${hasMore ? `<button onclick="${moreFn}()" class="btn-secondary" style="padding:10px 22px;font-weight:700;border-radius:10px">▾ <span>Načíst další</span> (${Math.min(pg.limit||10, total-shown)})</button>` : ''}
    ${info}</div>`;
}
window.objLoadMore = function() { renderObjednavky(state._objPag.filters, { append: true }); };
window.objGoToPage = function(p) { renderObjednavky(state._objPag.filters, { offset: p * (state._objPag.limit || 50) }); };
// Nekonečné scrollování: napojí IntersectionObserver na sentinel (jen styl=infinite).
function pagSetupInfinite(key, pg, moreFn) {
  if ((state._pagStyl || 'load_more') !== 'infinite') return;
  if (pg.items.length >= (pg.total || 0)) return;
  const anchor = document.getElementById(key + '-pag-more');
  if (!anchor) return;
  if (state['_io_' + key]) { try { state['_io_' + key].disconnect(); } catch (e) {} }
  const io = new IntersectionObserver((entries) => {
    if (entries.some(e => e.isIntersecting)) { io.disconnect(); window[moreFn](); }
  }, { rootMargin: '200px' });
  io.observe(anchor);
  state['_io_' + key] = io;
}

window.objToggleSelect = function(id, checked) {
  if (!state._objSelected) state._objSelected = new Set();
  if (checked) state._objSelected.add(id); else state._objSelected.delete(id);
  // Sync row visual
  document.querySelectorAll(`[data-obj-check="${id}"]`).forEach(el => { el.checked = checked; });
  // Update row class
  const objList = state._objList || [];
  document.querySelectorAll('.row-clickable, .obj-card').forEach(row => {});
  // Quick update without full re-render
  const tr = document.querySelector(`input[data-obj-check="${id}"]`)?.closest('tr');
  if (tr) tr.classList.toggle('is-selected', checked);
  const card = document.querySelector(`input[data-obj-check="${id}"]`)?.closest('.obj-card');
  if (card) card.classList.toggle('is-selected', checked);
  // 🆕 v3.0.90 — sync select-all checkbox state (mobile + desktop)
  syncObjSelectAllChecked();
  updateObjBulkBar();
};

// 🆕 v3.0.90 — sync header "select all" checkboxes podle počtu vybraných
function syncObjSelectAllChecked() {
  const total = (state._objList || []).length;
  const sel = state._objSelected?.size || 0;
  const allChecked = total > 0 && sel === total;
  const someChecked = sel > 0 && sel < total;
  ['obj-check-all', 'obj-check-all-mobile'].forEach(id => {
    const el = document.getElementById(id);
    if (el) {
      el.checked = allChecked;
      el.indeterminate = someChecked;
    }
  });
}

window.objSelectAll = function(checked) {
  if (!state._objSelected) state._objSelected = new Set();
  const list = state._objList || [];
  if (checked) {
    list.forEach(o => state._objSelected.add(o.id));
  } else {
    state._objSelected.clear();
  }
  // Sync všechny checkboxy + row classes
  document.querySelectorAll('[data-obj-check]').forEach(el => {
    el.checked = checked;
    const tr = el.closest('tr'); if (tr) tr.classList.toggle('is-selected', checked);
    const card = el.closest('.obj-card'); if (card) card.classList.toggle('is-selected', checked);
  });
  // 🆕 v3.0.90 — sync select-all checkbox state (mobile + desktop)
  syncObjSelectAllChecked();
  updateObjBulkBar();
};

window.objClearSelection = function() {
  if (state._objSelected) state._objSelected.clear();
  document.querySelectorAll('[data-obj-check]').forEach(el => {
    el.checked = false;
    const tr = el.closest('tr'); if (tr) tr.classList.remove('is-selected');
    const card = el.closest('.obj-card'); if (card) card.classList.remove('is-selected');
  });
  // 🆕 v3.0.90 — clear oba checkboxy (desktop + mobile)
  ['obj-check-all', 'obj-check-all-mobile'].forEach(id => {
    const el = document.getElementById(id);
    if (el) { el.checked = false; el.indeterminate = false; }
  });
  updateObjBulkBar();
};

function updateObjBulkBar() {
  const bar = document.getElementById('obj-bulk-bar');
  const cnt = document.getElementById('obj-bulk-count');
  const all = document.getElementById('obj-check-all');
  const n = state._objSelected ? state._objSelected.size : 0;
  if (bar) bar.style.display = n > 0 ? 'flex' : 'none';
  document.body.classList.toggle('has-bulk-bar', n > 0);
  if (cnt) cnt.textContent = n;
  if (all) {
    const total = (state._objList || []).length;
    all.checked = total > 0 && n === total;
    all.indeterminate = n > 0 && n < total;
  }
}

window.objBulkPreview = async function() {
  const ids = [...(state._objSelected || [])];
  if (ids.length === 0) return;
  try {
    const res = await api('admin_objednavky_hromadne.php', {
      method: 'POST',
      body: JSON.stringify({ action: 'preview', objednavka_ids: ids })
    });
    const skupinyDl = res.skupiny_dl || [];
    const skupinyFa = res.skupiny_fa || [];
    openModal('📋 Náhled hromadné akce', `
      <div style="display:grid;grid-template-columns:1fr 1fr;gap:14px">
        <div class="card-block" style="padding:14px">
          <h3 style="margin:0 0 10px;font-size:14px">📃 Dodací listy</h3>
          <p style="font-size:12px;color:var(--text-3);margin-bottom:10px">1 DL na každou objednávku (${ids.length} celkem). Pokud už DL existuje, přeskočí se.</p>
          ${skupinyDl.map(s => `
            <div style="padding:8px 10px;background:var(--surface-2);border-radius:6px;margin-bottom:6px;font-size:13px">
              <strong>${esc(s.odberatel_nazev)}</strong>${s.misto_nazev ? ` · ${esc(s.misto_nazev)}` : ''}
              <div style="font-size:11px;color:var(--text-3);margin-top:2px">
                ${s.objednavky.length} obj · datumy: ${[...new Set(s.datumy)].map(fmtDate).join(', ')}
              </div>
            </div>
          `).join('')}
        </div>
        <div class="card-block" style="padding:14px">
          <h3 style="margin:0 0 10px;font-size:14px">💰 Faktury</h3>
          <p style="font-size:12px;color:var(--text-3);margin-bottom:10px">1 FA per odběratel. Linkuje všechny DL ze skupiny.</p>
          ${skupinyFa.map(s => `
            <div style="padding:8px 10px;background:var(--surface-2);border-radius:6px;margin-bottom:6px;font-size:13px">
              <strong>${esc(s.odberatel_nazev)}</strong>
              <div style="font-size:11px;color:var(--text-3);margin-top:2px">
                ${s.objednavky.length} obj · ${fmt(s.castka_celkem)} · splatnost ${s.splatnost_dni} dní
              </div>
            </div>
          `).join('')}
        </div>
      </div>
      <div class="form-actions" style="margin-top:14px">
        <!-- "Zavřít" smazáno v v2.5.11 — × v rohu modal-card stačí (větší klikací plocha) -->
        ${adminOnly('<button class="btn-primary" onclick="closeModal();objBulkAction(\'dl\')">📃 Vytvořit ' + skupinyDl.length + ' DL</button>')}
        ${adminOnly('<button class="btn-primary btn-green" onclick="closeModal();objBulkAction(\'fa\')">💰 Vytvořit ' + skupinyFa.length + ' FA</button>')}
      </div>
    `, 'wide');
  } catch (e) { alert('Chyba: ' + e.message); }
};

// 🆕 v2.9.253 — Bulk email pro vybrané objednávky (per objednávka PDF na email odběratele)
window.objBulkEmail = async function() {
  const ids = [...(state._objSelected || [])];
  if (ids.length === 0) { alert('Vyber alespoň jednu objednávku.'); return; }

  // Najdi objednávky v state._objList — potřebujeme cislo + odberatel_email
  const list = state._objList || [];
  const items = ids.map(id => list.find(o => o.id === id)).filter(Boolean);
  const sEmailem = items.filter(o => (o.odberatel_email || '').trim() !== '');
  const bezEmailu = items.filter(o => !(o.odberatel_email || '').trim());

  if (sEmailem.length === 0) {
    alert('⚠️ Žádná z vybraných objednávek nemá vyplněný e-mail odběratele.');
    return;
  }

  let msg = `Odeslat PDF e-mailem pro ${sEmailem.length} ${sEmailem.length === 1 ? 'objednávku' : (sEmailem.length < 5 ? 'objednávky' : 'objednávek')}?`;
  if (bezEmailu.length > 0) {
    msg += `\n\n⚠️ ${bezEmailu.length} bez e-mailu (přeskočí):\n` + bezEmailu.slice(0, 5).map(o => `• ${o.cislo} — ${o.odberatel}`).join('\n');
    if (bezEmailu.length > 5) msg += `\n... a další ${bezEmailu.length - 5}`;
  }
  if (!(await confirmDialog({ msg: msg, danger: false }))) return;

  // Progress toast
  const toast = document.createElement('div');
  toast.style.cssText = 'position:fixed;bottom:24px;right:24px;background:var(--surface);color:var(--text);padding:14px 22px;border-radius:8px;box-shadow:0 4px 12px rgba(0,0,0,0.15);font-size:13px;font-weight:500;z-index:9999;border:1px solid var(--border)';
  toast.innerHTML = `📧 Odesílám 0 / ${sEmailem.length}…`;
  document.body.appendChild(toast);

  let ok = 0, fail = 0;
  const errs = [];
  for (let i = 0; i < sEmailem.length; i++) {
    const o = sEmailem[i];
    toast.innerHTML = `📧 Odesílám ${i + 1} / ${sEmailem.length} — ${esc(o.cislo)}`;
    try {
      await api('admin_doklad_email.php', {
        method: 'POST',
        body: JSON.stringify({
          typ: 'obj',
          id: o.id,
          emails: [o.odberatel_email.trim()],
        }),
      });
      ok++;
    } catch (e) {
      fail++;
      errs.push(`${o.cislo} (${o.odberatel}): ${e.message}`);
    }
  }
  toast.remove();

  // Finální shrnutí
  let summary = `✅ Odesláno: ${ok}`;
  if (fail > 0) summary += `\n❌ Selhalo: ${fail}\n\n` + errs.slice(0, 10).join('\n');
  if (bezEmailu.length > 0) summary += `\n⏭️ Přeskočeno bez e-mailu: ${bezEmailu.length}`;
  alert(summary);

  // Zrušit výběr a refresh
  objClearSelection();
};

window.objBulkAction = async function(typ) {
  const ids = [...(state._objSelected || [])];
  if (ids.length === 0) return;
  const akce = typ === 'fa' ? 'vytvořit FAKTURY' : 'vytvořit DODACÍ LISTY';
  if (!(await confirmDialog({ msg: t('confirm_bulk_action_orders', { akce, n: ids.length, label: ids.length === 1 ? 'objednávku' : (ids.length < 5 ? 'objednávky' : 'objednávek') }), danger: false }))) return;

  try {
    const res = await api('admin_objednavky_hromadne.php', {
      method: 'POST',
      body: JSON.stringify({ action: typ, objednavka_ids: ids })
    });
    const vyt = res.vytvoreno || [];
    const psk = res.preskoceno || [];
    let msg = `✓ Vytvořeno: ${vyt.length}`;
    if (typ === 'fa' && vyt.length > 0) {
      msg += '\n\n' + vyt.map(v => `• FA ${v.cislo} — ${v.odberatel_nazev} (${v.pocet_objednavek} obj, ${fmt(v.castka_celkem)})`).join('\n');
    } else if (typ === 'dl' && vyt.length > 0) {
      msg += '\n\n' + vyt.slice(0, 10).map(v => `• DL pro obj. ${v.cislo}`).join('\n');
      if (vyt.length > 10) msg += `\n... a další ${vyt.length - 10}`;
    }
    if (psk.length > 0) {
      msg += '\n\n⚠ Přeskočeno: ' + psk.length;
      msg += '\n' + psk.slice(0, 5).map(p => `• ${p.cislo}: ${p.duvod}`).join('\n');
      if (psk.length > 5) msg += `\n... a další ${psk.length - 5}`;
    }
    alert(msg);
    objClearSelection();
    renderObjednavky();
  } catch (e) {
    alert('Chyba: ' + e.message);
  }
};

window.applyObjFilters = function(extra = {}) {
  // 🆕 v3.0.27 — extra parametr (např. {puvod:'pos'}) z chips, zbytek z formu
  const filters = {
    q: document.getElementById('of-q')?.value || '',
    stav: document.getElementById('of-stav')?.value || '',
    datum_od: document.getElementById('of-od')?.value || '',
    datum_do: document.getElementById('of-do')?.value || '',
    puvod: state._objCurrentPuvod || '',
  };
  // Override puvod from extra (chips click)
  if ('puvod' in extra) {
    filters.puvod = extra.puvod;
    state._objCurrentPuvod = extra.puvod;
  }
  Object.assign(filters, extra);
  // Remove empty values
  Object.keys(filters).forEach(k => !filters[k] && delete filters[k]);
  renderObjednavky(filters);
};

window.openObjednavkaDetail = async function(id) {
  const o = await api(`admin_objednavky.php?id=${id}`);

  openModal(`Objednávka ${o.cislo}`, `
    <!-- HLAVIČKA: hezky 2 sloupce s odběratelem a místem dodání -->
    <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px;margin-bottom:14px">
      <div style="background:#F7F8FA;border:1px solid #E8D5B0;border-radius:8px;padding:12px 14px">
        <div style="font-size:11px;text-transform:uppercase;letter-spacing:0.5px;color:#854F0B;margin-bottom:6px;font-weight:600">🏢 Odběratel</div>
        <div style="font-size:20px;font-weight:700">${esc(o.odberatel_nazev)}</div>
        ${o.ico ? `<div style="font-size:13px;color:#854F0B;margin-top:2px">IČO: ${esc(o.ico)}</div>` : ''}
      </div>
      <div style="background:#EFF6FF;border:1px solid #B5D4F4;border-radius:8px;padding:12px 14px">
        <div style="font-size:11px;text-transform:uppercase;letter-spacing:0.5px;color:#0C447C;margin-bottom:6px;font-weight:600">📍 Místo dodání</div>
        ${o.misto_nazev ? `<div style="font-size:20px;font-weight:700">${esc(o.misto_nazev)}</div>` : '<div style="font-size:13px;color:#0C447C">Bez konkrétní pobočky</div>'}
        ${(o.misto_ulice || o.odb_ulice) ? `<div style="font-size:13px;color:#0C447C;margin-top:2px">${esc((o.misto_ulice || o.odb_ulice) + ', ' + (o.misto_mesto || o.odb_mesto || '') + ' ' + (o.misto_psc || o.odb_psc || ''))}</div>` : ''}
        ${o.misto_kontakt ? `<div style="font-size:11px;color:#0C447C;margin-top:2px">👤 ${esc(o.misto_kontakt)}${o.misto_tel ? ' · 📞 ' + esc(o.misto_tel) : ''}</div>` : ''}
        ${o.cas_dodani ? `<div style="font-size:11px;color:#0C447C;margin-top:2px">⏰ ${esc(o.cas_dodani)}</div>` : ''}
      </div>
    </div>

    <!-- 3 sloupce: Stav · Datum dodání · Datum objednání -->
    <div class="form-grid form-grid-tight" style="grid-template-columns:1fr 1fr 1fr;margin-bottom:14px">
      <div>
        <label class="form-label">Stav</label>
        <select class="form-select" id="modal-stav">
          ${['nova','potvrzena','ve_vyrobe','pripravena','expedovana','dorucena','zrusena'].map((s) => `
            <option value="${s}" ${o.stav === s ? 'selected' : ''}>${statusLabel(s)}</option>
          `).join('')}
        </select>
      </div>
      <div>
        <label class="form-label">Datum dodání</label>
        <input type="date" class="form-input" id="modal-datum" value="${o.datum_dodani}">
      </div>
      <div>
        <label class="form-label">Datum objednání</label>
        <input type="text" class="form-input" value="${fmtDateTime(o.datum_objednani)}" disabled style="color:var(--text-3)">
      </div>
    </div>

    ${o.poznamka ? (() => {
      // 🆕 v3.0.305 — pokud poznámka nese fotku předlohy (konfigurátor dortů), ukaž klikací náhled
      const _fm = String(o.poznamka).match(/📸 Předloha:\s*(\S+)/);
      const _furl = _fm ? _fm[1] : '';
      return `<div style="margin-bottom:14px;padding:10px 14px;background:#F7F8FA;border-left:3px solid #BA7517;border-radius:4px;font-size:13px"><strong>📝 Poznámka:</strong> ${esc(o.poznamka)}${_furl ? `<div style="margin-top:8px"><a href="${esc(_furl)}" target="_blank" rel="noopener" title="Otevřít fotku předlohy v plné velikosti"><img src="${esc(_furl)}" alt="Fotka předlohy" style="max-height:130px;max-width:220px;object-fit:cover;border-radius:8px;border:1px solid var(--border);display:block" onerror="this.style.display='none'"></a></div>` : ''}</div>`;
    })() : ''}

    <!-- POLOŽKY jako seznam řádků -->
    <h3 class="modal-section-title" style="display:flex;align-items:center;justify-content:space-between;gap:8px">
      <span>📦 Položky <span style="font-size:11px;color:var(--text-3);font-weight:400">(${o.polozky.length})</span></span>
      <button class="btn-secondary" style="font-size:12px;padding:4px 12px" onclick="pridatPolozkuForm(${o.id})">+ Přidat výrobek</button>
    </h3>

    <div class="obj-polozky-list" id="obj-polozky">
      ${o.polozky.length === 0 ? '<div class="empty-state" style="padding:24px;background:var(--surface-2);border-radius:8px">Zatím žádné položky.</div>' : o.polozky.map((p) => `
        <div class="obj-polozka-row" data-pol-id="${p.id}">
          <div class="obj-polozka-img">${p.obrazek_url ? `<img src="${esc(p.obrazek_url)}" alt="">` : '<div class="obj-polozka-img-empty">🥖</div>'}</div>
          <div class="obj-polozka-info">
            <div class="obj-polozka-nazev">${esc(p.vyrobek_nazev)}</div>
            <div class="obj-polozka-meta">${fmt(p.cena_bez_dph)} / ${esc(p.jednotka || 'ks')}</div>
          </div>
          <div class="obj-polozka-qty">
            <button type="button" class="qty-btn" onclick="objQtyAdj(${p.id}, -1)" title="−1">−</button>
            <input type="number" min="0" step="1" class="form-input qty-input"
                   value="${Math.round(p.mnozstvi)}" data-orig="${Math.round(p.mnozstvi)}" data-pol-id="${p.id}"
                   data-cena="${p.cena_bez_dph}" oninput="objQtyRecalc(${p.id})">
            <button type="button" class="qty-btn" onclick="objQtyAdj(${p.id}, 1)" title="+1">+</button>
          </div>
          <div class="obj-polozka-cena" id="obj-polozka-cena-${p.id}">${fmt(p.cena_bez_dph * p.mnozstvi)}</div>
          <button class="obj-polozka-del" onclick="smazatPolozku(${p.id}, ${o.id})" title="Smazat">×</button>
        </div>
      `).join('')}
    </div>

    <div style="margin-top:10px;display:flex;justify-content:flex-end">
      <button class="btn-primary" style="font-size:13px" onclick="ulozitMnozstvi(${o.id})">💾 Uložit změny množství</button>
    </div>
    <div id="add-polozka-form"></div>

    <!-- SOUHRN: amber box -->
    <div style="margin-top:16px;background:#FAEEDA;border:2px solid #E5C499;border-radius:10px;padding:14px 18px">
      <div style="display:flex;justify-content:space-between;font-size:13px;color:#855;margin-bottom:4px">
        <span>Bez DPH</span><span>${fmt(o.castka_bez_dph)}</span>
      </div>
      <div style="display:flex;justify-content:space-between;font-size:13px;color:#855;margin-bottom:6px">
        <span>DPH</span><span>${fmt(o.castka_dph)}</span>
      </div>
      <div style="display:flex;justify-content:space-between;font-size:20px;font-weight:700;color:#854F0B;border-top:1px solid #E5C499;padding-top:6px">
        <span>Celkem s DPH</span><span>${fmt(o.castka_celkem)}</span>
      </div>
    </div>

    <!-- Skrytý nositel info pro JS handlery -->
    <div id="detail-doklady-info" style="display:none"
         data-ma-dl="${(o.dodaci_listy && o.dodaci_listy.length) ? '1' : ''}"
         data-ma-fa="${(o.faktury && o.faktury.length) ? '1' : ''}"
         data-puv-datum="${esc(o.datum_dodani || '')}"></div>

    ${(o.dodaci_listy && o.dodaci_listy.length) || (o.faktury && o.faktury.length) ? `
      <div style="margin-top:16px; padding:14px 16px; background:#F7F8FA; border:1px solid var(--border); border-radius:8px;">
        <h4 style="font-size:12px; color:var(--text-3); text-transform:uppercase; letter-spacing:0.5px; margin-bottom:10px;">📎 Vystavené doklady</h4>
        ${(o.dodaci_listy || []).map(dl => `
          <div style="display:flex;align-items:center;gap:8px;padding:6px 0;border-bottom:1px dashed var(--border);font-size:13px">
            <span style="display:inline-block;padding:3px 8px;background:#FAEEDA;color:#854F0B;border-radius:4px;font-size:11px;font-weight:600">📃 DL</span>
            <strong>${esc(dl.cislo)}</strong>
            <span style="color:var(--text-3)">${fmtDate(dl.datum_vystaveni)}</span>
            <span style="color:var(--text-3)">·</span>
            <span>${fmt(dl.castka_celkem)}</span>
            <div style="flex:1"></div>
            <button class="btn-secondary" style="font-size:12px;padding:4px 10px;" onclick="closeModal();openDodaciListDetail(${dl.id})">Detail</button>
            <a href="../api/dodaci_list.php?id=${o.id}" target="_blank" class="btn-secondary" style="font-size:12px;padding:4px 10px;text-decoration:none">PDF</a>
          </div>
        `).join('')}
        ${(o.faktury || []).map(fa => `
          <div style="display:flex;align-items:center;gap:8px;padding:6px 0;border-bottom:1px dashed var(--border);font-size:13px">
            <span style="display:inline-block;padding:3px 8px;background:var(--success-bg);color:var(--success-text);border-radius:4px;font-size:11px;font-weight:600">💰 FA</span>
            <strong>${esc(fa.cislo)}</strong>
            <span style="color:var(--text-3)">${fmtDate(fa.datum_vystaveni)}</span>
            <span style="color:var(--text-3)">·</span>
            <span>${fmt(fa.castka_celkem)}</span>
            ${fa.castka_uhrazeno > 0 ? `<span style="color:var(--success-text);font-size:11px">uhrazeno ${fmt(fa.castka_uhrazeno)}</span>` : ''}
            <div style="flex:1"></div>
            <a href="../api/faktura.php?id=${fa.id}" target="_blank" class="btn-secondary" style="font-size:12px;padding:4px 10px;text-decoration:none">💰 Otevřít / Tisk</a>
          </div>
        `).join('')}
        ${(o.dodaci_listy && o.dodaci_listy.length) || (o.faktury && o.faktury.length) ? `
          <p style="font-size:12px;color:var(--text-3);margin-top:8px">
            ⚠️ Při úpravě položek nebo množství se doklady automaticky přepíšou. Vytištěné starší verze prosím zahoďte a vytiskněte nové.
          </p>
        ` : ''}
      </div>
    ` : ''}

    ${(o.historie_zmen && o.historie_zmen.length) ? `
      <div style="margin-top:16px; padding:14px 16px; background:#F7F8FA; border:1px solid var(--border); border-radius:8px;">
        <h4 style="font-size:12px; color:var(--text-3); text-transform:uppercase; letter-spacing:0.5px; margin-bottom:10px;">📋 Historie změn (${o.historie_zmen.length})</h4>
        ${o.historie_zmen.map(z => {
          let detailText = '';
          try {
            const det = z.detail ? JSON.parse(z.detail) : null;
            if (det && det.rozdil) {
              const parts = [];
              if (det.rozdil.pridane?.length) parts.push(`+ ${det.rozdil.pridane.length} přidaných`);
              if (det.rozdil.zmenene?.length) parts.push(`~ ${det.rozdil.zmenene.length} změněných`);
              if (det.rozdil.odebrane?.length) parts.push(`− ${det.rozdil.odebrane.length} odebraných`);
              if (parts.length) detailText = parts.join(', ');
            }
            if (det && det.stav) detailText += (detailText ? ' · ' : '') + `stav: ${statusLabel(det.stav.z)} → ${statusLabel(det.stav.na)}`;
            if (det && det.datum) detailText += (detailText ? ' · ' : '') + `datum: ${fmtDate(det.datum.z)} → ${fmtDate(det.datum.na)}`;
          } catch (e) { /* ignore */ }
          const akceText = ({
            upravena: '✏️ Upravena',
            zrusena: '❌ Zrušena',
            obnovena: '↩️ Obnovena',
          })[z.akce] || z.akce;
          const ikona = z.kdo_typ === 'admin' ? '👤' : '🛒';
          return `
            <div style="padding:6px 0; border-bottom:1px dashed var(--border); font-size:13px;">
              <strong>${akceText}</strong>
              <span style="color:var(--text-3); margin:0 8px;">·</span>
              ${fmtDateTime(z.kdy)}
              <span style="color:var(--text-3); margin:0 8px;">·</span>
              ${ikona} ${esc(z.kdo_jmeno || z.kdo_typ)}
              ${detailText ? `<div style="color:var(--text-3); font-size:12px; margin-top:2px; margin-left:18px;">${esc(detailText)}</div>` : ''}
            </div>
          `;
        }).join('')}
      </div>
    ` : ''}

    <!-- 🆕 v3.0.246 — Doklady: sjednocená profi sada 3 tlačítek (label jako hlavička, ne v gridu) -->
    <div class="obj-doc-actions-wrap">
      <div class="obj-doc-actions-label">📋 Doklady</div>
      <div class="obj-doc-actions">
        <button class="doc-action" onclick="noOpakovatZeZdroje('obj', ${o.id})" title="Vytvořit novou objednávku se stejnými položkami">
          <span class="doc-action-ic">🔁</span><span>Znovu objednat</span>
        </button>
        ${(!o.dodaci_listy || o.dodaci_listy.length === 0) ? `
          <a href="../api/dodaci_list.php?id=${o.id}" target="_blank" class="doc-action"><span class="doc-action-ic">📃</span><span>Vytvořit DL</span></a>
        ` : `
          <a href="../api/dodaci_list.php?id=${o.id}" target="_blank" class="doc-action doc-action-done"><span class="doc-action-ic">📃</span><span>Otevřít DL</span></a>
        `}
        ${(!o.faktury || o.faktury.length === 0) ? `
          <button class="doc-action doc-action-primary" onclick="vytvoritFakturu(${o.id})"><span class="doc-action-ic">💰</span><span>Vystavit fakturu</span></button>
        ` : `
          <a href="../api/faktura.php?id=${o.faktury[0].id}" target="_blank" class="doc-action doc-action-done"><span class="doc-action-ic">💰</span><span>Faktura ${esc(o.faktury[0].cislo || '')}</span></a>
        `}
          <button class="doc-action" onclick="appekShipmentDialog(${o.id})"><span class="doc-action-ic">📦</span><span>Vytvořit zásilku</span></button>
      </div>
    </div>

    <!-- Footer: Zavřít + ✉️/🗑️ + Uložit -->
    <div class="form-actions">
      <!-- "Zavřít" smazáno v v2.5.11 — × v rohu modal-card stačí (větší klikací plocha) -->
      <div class="form-actions-icons-row">
        <button class="btn-icon-corner" onclick="tiskNaTermo('obj', ${o.id}, '${esc(o.cislo).replace(/'/g, '')}')" title="Tisk na termo-tiskárnu (účtenka / bon)" aria-label="Tisk na tiskárnu">🖨️</button>
        <button class="btn-icon-corner" onclick="otevritOdeslatEmailem('obj', ${o.id}, '${esc(o.cislo).replace(/'/g, '')}', '${esc(o.odberatel_email || '').replace(/'/g, '')}')" title="Odeslat objednávku PDF emailem" aria-label="Odeslat e-mailem">✉️</button>
        ${adminOnly(`<button class="btn-danger-corner" onclick="smazatObjednavku(${o.id}, '${esc(o.cislo).replace(/'/g, '')}')" title="Smazat objednávku (pokud nemá DL/FA)" aria-label="Smazat objednávku">🗑️</button>`)}
      </div>
      <div style="flex:1"></div>
      <button class="btn-primary btn-green" onclick="ulozitObjednavku(${o.id})">💾 Uložit změny</button>
    </div>
  `);
};

window.ulozitObjednavku = async function(id) {
  const data = {
    id,
    stav: document.getElementById('modal-stav').value,
    datum_dodani: document.getElementById('modal-datum').value,
  };

  const info = document.getElementById('detail-doklady-info');
  const maDL = !!info?.dataset.maDl;
  const maFA = !!info?.dataset.maFa;
  const puvDatum = info?.dataset.puvDatum || '';
  const datumZmenen = data.datum_dodani && puvDatum && data.datum_dodani !== puvDatum;

  if (datumZmenen && (maDL || maFA)) {
    const co = maFA ? 'FAKTURU a dodací list' : 'DODACÍ LIST';
    if (!(await confirmDialog({ msg: `⚠️ Měníte datum dodání objednávky, která má vystavenou ${co}.\n\n` +
      `Datum dodání na objednávce se změní, ale na již vystavených dokladech zůstane staré.\n\n` +
      `Pokračovat?`, danger: false }))) return;
    data.vynutit = true;
  }

  try {
    await api('admin_objednavky.php', { method: 'PUT', body: JSON.stringify(data) });
    closeModal();
    navigate('objednavky');
  } catch (e) {
    alert('Chyba: ' + e.message);
  }
};

window.ulozitMnozstvi = async function(id) {
  const inputs = document.querySelectorAll('#obj-polozky input[data-pol-id]');
  const zmeny = [];
  inputs.forEach((inp) => {
    const orig = parseFloat(inp.dataset.orig);
    const aktual = parseFloat(inp.value) || 0;
    if (aktual !== orig) zmeny.push({ polozka_id: parseInt(inp.dataset.polId), mnozstvi: aktual });
  });
  if (zmeny.length === 0) return alert('Nebyly provedeny žádné změny');

  // Pokud má objednávka DL nebo fakturu, zeptej se uživatele
  const maDL = !!document.getElementById('detail-doklady-info')?.dataset.maDl;
  const maFA = !!document.getElementById('detail-doklady-info')?.dataset.maFa;
  let vynutit = false;
  if (maDL || maFA) {
    const co = maFA ? 'FAKTURU a dodací list' : 'DODACÍ LIST';
    if (!(await confirmDialog({ msg: `⚠️ Tato objednávka má vystavenou ${co}.\n\n` +
      `Pokud budete pokračovat, ${maFA ? 'faktura i dodací list' : 'dodací list'} se automaticky přepíše podle nových položek.\n\n` +
      `Pokud již byl doklad vytištěn, zahoďte ho a vytiskněte nový.\n\n` +
      `Opravdu pokračovat?`, danger: true }))) return;
    vynutit = true;
  }

  try {
    const r = await api('admin_objednavky.php', { method: 'PUT', body: JSON.stringify({ id, polozky_zmeny: zmeny, vynutit }) });

    if (r.prepsana_faktura || r.prepsan_dl) {
      const co = r.prepsana_faktura ? 'Faktura i dodací list byly' : 'Dodací list byl';
      alert(t('changes_saved_n_updated', { co }));
    }
    openObjednavkaDetail(id);
  } catch (e) { alert('Chyba: ' + e.message); }
};

// 🗑️ Smazat objednávku — pouze pokud k ní není DL/FA (BE vrátí 409)
window.smazatObjednavku = async function(id, cislo) {
  if (!await confirmDelete2x({ co: `objednávku ${cislo || ''}`, detail: 'Pozor: pokud k ní existuje dodací list nebo faktura, smazání nepůjde.' })) return;
  try {
    await api(`admin_objednavky.php?id=${id}`, { method: 'DELETE' });
    closeModal();
    navigate('objednavky');
  } catch (e) { alert('Chyba: ' + e.message); }
};

// +/− tlačítka u množství v obj edit modalu
window.objQtyAdj = function(polozka_id, delta) {
  const inp = document.querySelector(`.obj-polozka-row[data-pol-id="${polozka_id}"] .qty-input`);
  if (!inp) return;
  const newVal = Math.max(0, (parseInt(inp.value) || 0) + delta);
  inp.value = newVal;
  objQtyRecalc(polozka_id);
};

// Live přepočet celkové ceny řádku po změně množství
window.objQtyRecalc = function(polozka_id) {
  const row = document.querySelector(`.obj-polozka-row[data-pol-id="${polozka_id}"]`);
  if (!row) return;
  const inp = row.querySelector('.qty-input');
  const cenaCell = document.getElementById(`obj-polozka-cena-${polozka_id}`);
  if (!inp || !cenaCell) return;
  const mn = parseFloat(inp.value) || 0;
  const cena = parseFloat(inp.dataset.cena) || 0;
  cenaCell.textContent = fmt(mn * cena);
};

window.smazatPolozku = async function(polozka_id, objednavka_id) {
  if (!await confirmDelete2x('tuto položku z objednávky')) return;
  try {
    await api(`admin_objednavky.php?action=smazat_polozku&id=${polozka_id}&objednavka_id=${objednavka_id}`, { method: 'DELETE' });
    openObjednavkaDetail(objednavka_id);
  } catch (e) { alert('Chyba: ' + e.message); }
};

window.pridatPolozkuForm = async function(objednavka_id) {
  const data = await api('admin_vyrobky.php');
  // 🐛 fix v2.9.187 — defenzivní fallback pro prázdnou DB / API error
  const aktivni = (data?.vyrobky || []).filter((v) => v.aktivni == 1);
  // 🆕 v3.0.277 — searchable combobox místo <select> s tisíci položkami (giant dropdown nepoužitelný)
  window._addVyrobky = aktivni;
  document.getElementById('add-polozka-form').innerHTML = `
    <div style="display:flex;gap:8px;align-items:flex-end;margin-top:8px;padding:12px;background:var(--surface-2);border-radius:6px;flex-wrap:wrap">
      <div style="flex:2;min-width:200px;position:relative">
        <label class="form-label">Výrobek</label>
        <input type="text" class="form-input" id="add-vyrobek-search" autocomplete="off" placeholder="Začni psát název nebo kód…"
               oninput="addVyrobekFilter()" onfocus="addVyrobekFilter()"
               onblur="setTimeout(function(){var l=document.getElementById('add-vyrobek-list');if(l)l.style.display='none'},160)">
        <input type="hidden" id="add-vyrobek">
        <div id="add-vyrobek-list" style="display:none;position:absolute;z-index:50;left:0;right:0;top:100%;margin-top:2px;max-height:260px;overflow:auto;background:var(--surface);border:1px solid var(--border);border-radius:8px;box-shadow:0 8px 24px rgba(0,0,0,.14)"></div>
      </div>
      <div style="width:100px;">
        <label class="form-label">Množství</label>
        <input type="number" class="form-input" id="add-mnozstvi" value="1" min="1">
      </div>
      <button class="btn-primary" onclick="ulozitNovouPolozku(${objednavka_id})">Přidat</button>
    </div>
  `;
};

// 🆕 v3.0.277 — combobox pro výběr výrobku (filtruje název i kód, max 40 výsledků kvůli výkonu)
window.addVyrobekFilter = function() {
  const inp = document.getElementById('add-vyrobek-search');
  const list = document.getElementById('add-vyrobek-list');
  if (!inp || !list) return;
  const q = (inp.value || '').trim().toLowerCase();
  const all = window._addVyrobky || [];
  const matched = (q ? all.filter(v => (v.nazev || '').toLowerCase().includes(q) || (v.cislo || '').toLowerCase().includes(q)) : all).slice(0, 40);
  if (!matched.length) {
    list.innerHTML = '<div style="padding:10px 12px;color:var(--text-3);font-size:13px">Nic nenalezeno</div>';
    list.style.display = 'block';
    return;
  }
  list.innerHTML = matched.map(v => `
    <div onmousedown="addVyrobekPick(${v.id})" style="padding:8px 12px;cursor:pointer;font-size:14px;border-bottom:1px solid var(--border);display:flex;justify-content:space-between;gap:8px"
         onmouseover="this.style.background='var(--surface-2)'" onmouseout="this.style.background=''">
      <span>${esc(v.nazev)}${v.cislo ? ` <span style="color:var(--text-3);font-size:12px">${esc(v.cislo)}</span>` : ''}</span>
      <span style="color:var(--text-3);white-space:nowrap">${fmt(v.cena_bez_dph)}</span>
    </div>`).join('');
  list.style.display = 'block';
};
window.addVyrobekPick = function(id) {
  const v = (window._addVyrobky || []).find(x => x.id == id);
  if (!v) return;
  document.getElementById('add-vyrobek').value = id;
  document.getElementById('add-vyrobek-search').value = v.nazev + (v.cislo ? ` (${v.cislo})` : '');
  const list = document.getElementById('add-vyrobek-list');
  if (list) list.style.display = 'none';
};

// 🆕 v3.0.279 — searchable combobox výrobku pro slevy cenové skupiny (sl-vyrobek)
window.slVyrobekFilter = function() {
  const inp = document.getElementById('sl-vyrobek-search');
  const list = document.getElementById('sl-vyrobek-list');
  if (!inp || !list) return;
  const q = (inp.value || '').trim().toLowerCase();
  const all = window._slVyrobky || [];
  const matched = (q ? all.filter(v => (v.nazev || '').toLowerCase().includes(q) || (v.cislo || '').toString().toLowerCase().includes(q)) : all).slice(0, 40);
  if (!matched.length) { list.innerHTML = '<div style="padding:10px 12px;color:var(--text-3);font-size:13px">Nic nenalezeno</div>'; list.style.display = 'block'; return; }
  list.innerHTML = matched.map(v => `
    <div onmousedown="slVyrobekPick(${v.id})" style="padding:8px 12px;cursor:pointer;font-size:14px;border-bottom:1px solid var(--border)"
         onmouseover="this.style.background='var(--surface-2)'" onmouseout="this.style.background=''">
      ${esc(v.nazev)}${v.cislo ? ` <span style="color:var(--text-3);font-size:12px">${esc(v.cislo)}</span>` : ''}
    </div>`).join('');
  list.style.display = 'block';
};
window.slVyrobekPick = function(id) {
  const v = (window._slVyrobky || []).find(x => x.id == id);
  if (!v) return;
  document.getElementById('sl-vyrobek').value = id;
  document.getElementById('sl-vyrobek-search').value = v.nazev + (v.cislo ? ` (${v.cislo})` : '');
  const list = document.getElementById('sl-vyrobek-list');
  if (list) list.style.display = 'none';
};

// 🆕 v3.0.279 — REUSABLE row-scoped searchable combobox výrobku (pro per-řádkové repeatery:
//   výrobní list, opakující objednávky). Hidden input nese stejnou TŘÍDU (+ data-fld), takže
//   stávající čtení (row.querySelector('.trida').value / [data-fld=...]) funguje beze změny.
//   Dropdown je position:FIXED (přes getBoundingClientRect) → neořízne ho scrollovací kontejner.
function vyrComboRow(hiddenClass, items, preId, extraAttrs) {
  window._vyrComboItems = items; // sdílený zdroj (jen jeden repeater modal otevřený naráz)
  const pre = preId ? (items || []).find(v => v.id == preId) : null;
  const preLabel = pre ? (pre.nazev + (pre.cislo ? ` (${pre.cislo})` : '')) : '';
  const dataA = pre
    ? `data-cena="${pre.cena_bez_dph || ''}" data-dph="${pre.dph || 12}" data-jed="${esc(pre.jednotka_kod || pre.jednotka || 'ks')}" data-nazev="${esc(pre.nazev)}"`
    : '';
  return `
    <div class="vyr-combo" style="position:relative;min-width:0">
      <input type="text" class="form-input vyr-combo-search" autocomplete="off" placeholder="Začni psát…" value="${esc(preLabel)}"
             oninput="vyrComboFilter(this)" onfocus="vyrComboFilter(this)"
             onblur="setTimeout(()=>{var l=this.closest('.vyr-combo').querySelector('.vyr-combo-list');if(l)l.style.display='none'},170)">
      <input type="hidden" class="${hiddenClass}" ${extraAttrs || ''} ${dataA} value="${preId || ''}">
      <div class="vyr-combo-list" style="display:none;z-index:9999;max-height:240px;overflow:auto;background:var(--surface);border:1px solid var(--border);border-radius:8px;box-shadow:0 8px 24px rgba(0,0,0,.18)"></div>
    </div>`;
}
window.vyrComboFilter = function(inp) {
  const wrap = inp.closest('.vyr-combo'); if (!wrap) return;
  const list = wrap.querySelector('.vyr-combo-list');
  const q = (inp.value || '').trim().toLowerCase();
  const all = window._vyrComboItems || [];
  const matched = (q ? all.filter(v => (v.nazev || '').toLowerCase().includes(q) || (v.cislo || '').toString().toLowerCase().includes(q)) : all).slice(0, 40);
  list.innerHTML = matched.length
    ? matched.map(v => `<div data-id="${v.id}" data-cena="${v.cena_bez_dph || ''}" data-dph="${v.dph || 12}" data-jed="${esc(v.jednotka_kod || v.jednotka || 'ks')}" data-nazev="${esc(v.nazev)}" onmousedown="vyrComboPick(this)" style="padding:8px 12px;cursor:pointer;font-size:14px;border-bottom:1px solid var(--border)" onmouseover="this.style.background='var(--surface-2)'" onmouseout="this.style.background=''">${esc(v.ikona || '')} ${esc(v.nazev)}${v.cislo ? ` <span style="color:var(--text-3);font-size:12px">${esc(v.cislo)}</span>` : ''}</div>`).join('')
    : '<div style="padding:10px 12px;color:var(--text-3);font-size:13px">Nic nenalezeno</div>';
  // position:fixed dle inputu → escapuje overflow:auto kontejner (rec-polozky)
  const r = inp.getBoundingClientRect();
  list.style.position = 'fixed';
  list.style.left = r.left + 'px';
  list.style.top = r.bottom + 'px';
  list.style.width = r.width + 'px';
  list.style.display = 'block';
};
window.vyrComboPick = function(optEl) {
  const wrap = optEl.closest('.vyr-combo'); if (!wrap) return;
  const hidden = wrap.querySelector('input[type=hidden]');
  const search = wrap.querySelector('.vyr-combo-search');
  hidden.value = optEl.dataset.id;
  hidden.dataset.cena = optEl.dataset.cena; hidden.dataset.dph = optEl.dataset.dph;
  hidden.dataset.jed = optEl.dataset.jed; hidden.dataset.nazev = optEl.dataset.nazev;
  if (search) search.value = (optEl.dataset.nazev || '');
  hidden.dispatchEvent(new Event('change', { bubbles: true })); // spustí případný autofill/recompute řádku
  const list = wrap.querySelector('.vyr-combo-list'); if (list) list.style.display = 'none';
};

window.ulozitNovouPolozku = async function(objednavka_id) {
  const vyrobek_id = parseInt(document.getElementById('add-vyrobek').value);
  const mnozstvi = parseFloat(document.getElementById('add-mnozstvi').value);
  if (!vyrobek_id || mnozstvi <= 0) return alert('Vyplňte výrobek a množství');
  try {
    await api('admin_objednavky.php', {
      method: 'POST',
      body: JSON.stringify({ action: 'pridat_polozku', objednavka_id, vyrobek_id, mnozstvi }),
    });
    openObjednavkaDetail(objednavka_id);
  } catch (e) { alert('Chyba: ' + e.message); }
};

window.vytvoritFakturu = async function(objednavka_id) {
  if (!(await confirmDialog({ msg: 'Vytvořit fakturu z této objednávky?', danger: false }))) return;
  try {
    const res = await api(`faktura.php?action=vytvor`, {
      method: 'POST',
      body: JSON.stringify({ objednavka_id }),
    });
    closeModal();
    if (res.existing) alert('Faktura už pro tuto objednávku existuje, otevírám ji.');
    window.open(`../api/faktura.php?id=${res.faktura_id}`, '_blank');
    navigate('faktury');
  } catch (e) { alert('Chyba: ' + e.message); }
};

// 🆕 v3.0.233 — Vystavit fakturu přímo z dodacího listu (i ruční DL bez objednávky).
window.vytvoritFakturuZDL = async function(dlId, objednavkaId) {
  if (!(await confirmDialog({ msg: 'Vystavit fakturu z tohoto dodacího listu?', danger: false }))) return;
  try {
    const payload = objednavkaId ? { objednavka_id: objednavkaId } : { dodaci_list_id: dlId };
    const res = await api(`faktura.php?action=vytvor`, { method: 'POST', body: JSON.stringify(payload) });
    apiCacheInvalidate('admin_dodaci_listy'); apiCacheInvalidate('admin_faktury'); // fresh stav po fakturaci
    closeModal();
    if (res.existing) toastSuccess('Faktura už existuje — otevírám ji.', 'Hotovo');
    else toastSuccess(`Faktura ${res.cislo || ''} vystavena.`, '✅ Vyfakturováno');
    window.open(`../api/faktura.php?id=${res.faktura_id}`, '_blank');
    navigate('faktury');
  } catch (e) { alert('Chyba: ' + e.message); }
};

