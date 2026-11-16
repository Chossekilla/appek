// ═════════════════════════════════════════════════════════════════════
// 📊 ÚČETNÍ INTEGRACE — POHODA (mServer / XML soubor) + ABRA Flexi (FlexiBee REST)
//   Backend: api/admin_ucetni.php (jeden endpoint pro oba systémy).
//   Háčky v jiných souborech:
//     0490 (Nastavení → Integrace): karty #ucetni-pohoda-card / #ucetni-flexibee-card + loadUcetniIntegrace()
//     0410 (detail faktury): ${ucetniFakturaTlacitka(f)} v řádku akcí
// ═════════════════════════════════════════════════════════════════════

window._ucetniCfg = null;

const UCETNI_SYS = {
  pohoda:   { label: 'POHODA', do: 'POHODY', k: 'POHODĚ', v: 'v POHODĚ', badge: '<span style="background:#e30613;color:#fff;border-radius:6px;padding:3px 8px;font-size:11px;font-weight:800;letter-spacing:0.5px">POHODA</span>' },
  flexibee: { label: 'FlexiBee', do: 'FlexiBee', k: 'FlexiBee', v: 've FlexiBee', badge: '<span style="background:#f7941d;color:#fff;border-radius:6px;padding:3px 8px;font-size:11px;font-weight:800;letter-spacing:0.5px">ABRA Flexi</span>' },
};

function _ucetniDnes() { return new Date().toISOString().slice(0, 10); }
function _ucetniZacatekMesice() { const d = new Date(); return new Date(d.getFullYear(), d.getMonth(), 1, 12).toISOString().slice(0, 10); }
function _ucetniKdy(s) { return s ? (typeof fmtDateTime === 'function' ? fmtDateTime(s) : String(s)) : ''; }
function _ucetniChyba(e) { return (e && e.message) ? e.message : String(e || 'Neznámá chyba'); }

async function _ucetniCfgNacti(force = false) {
  if (window._ucetniCfg && !force) return window._ucetniCfg;
  window._ucetniCfg = await api('admin_ucetni.php?action=config&_t=' + Date.now());
  return window._ucetniCfg;
}

// ─────────────────────────── Nastavení → Integrace ───────────────────────────

async function loadUcetniIntegrace() {
  const hostP = document.getElementById('ucetni-pohoda-card');
  const hostF = document.getElementById('ucetni-flexibee-card');
  if (!hostP && !hostF) return;
  let cfg;
  try { cfg = await _ucetniCfgNacti(true); }
  catch (e) {
    const msg = `<div style="color:var(--danger-text);font-size:13px">❌ Nastavení účetní integrace nelze načíst: ${esc(_ucetniChyba(e))}</div>`;
    if (hostP) hostP.innerHTML = msg; if (hostF) hostF.innerHTML = msg;
    return;
  }
  if (hostP) hostP.innerHTML = _ucetniKartaHtml('pohoda', cfg);
  if (hostF) hostF.innerHTML = _ucetniKartaHtml('flexibee', cfg);
}
window.loadUcetniIntegrace = loadUcetniIntegrace;

function _ucetniStavPill(c, sys) {
  const on = c[sys + '_enabled'] === '1';
  return on
    ? '<span style="background:#DCFCE7;color:#166534;padding:3px 10px;border-radius:999px;font-size:12px;font-weight:600">✅ Zapnuto</span>'
    : '<span style="background:#F3F4F6;color:#6B7280;padding:3px 10px;border-radius:999px;font-size:12px;font-weight:600">⚫ Vypnuto</span>';
}

function _ucetniPosledni(p) {
  if (!p) return '<span style="color:var(--text-3)">Zatím žádná synchronizace.</span>';
  const ikona = String(p.success) === '1' ? '✅' : '❌';
  return `${ikona} ${esc(_ucetniKdy(p.created_at))} — ${esc(String(p.message || p.action || '').slice(0, 160))}`;
}

function _ucetniPole(id, label, value, opts = {}) {
  return `<label style="display:flex;flex-direction:column;gap:3px;min-width:0">
    <span style="font-size:12px;color:var(--text-2);font-weight:600">${label}</span>
    <input id="${id}" class="form-input" type="${opts.type || 'text'}" value="${esc(value || '')}" placeholder="${esc(opts.placeholder || '')}"
      autocomplete="${opts.type === 'password' ? 'new-password' : 'off'}" ${opts.disabled ? 'disabled' : ''}>
  </label>`;
}

function _ucetniKartaHtml(sys, cfg) {
  const c = cfg[sys] || {};
  const ro = !cfg.muze_upravit;
  const isP = sys === 'pohoda';
  const pre = isP ? 'uip' : 'uif';
  const hesloLbl = 'Heslo' + (c[sys + '_password_set'] ? ' <small style="color:#16A34A;font-weight:500">(uloženo — vyplň jen pro změnu)</small>' : '');
  const pole = isP
    ? _ucetniPole(pre + '-url', 'Adresa mServeru', c.pohoda_url, { placeholder: 'https://pohoda.firma.cz:444', disabled: ro })
      + _ucetniPole(pre + '-ico', 'IČO účetní jednotky', c.pohoda_ico, { placeholder: '12345678', disabled: ro })
      + _ucetniPole(pre + '-user', 'Uživatel POHODY', c.pohoda_username, { disabled: ro })
      + _ucetniPole(pre + '-pass', hesloLbl, '', { type: 'password', placeholder: c.pohoda_password_set ? '••••••••' : '', disabled: ro })
      + _ucetniPole(pre + '-predkontace', 'Předkontace (volitelné)', c.pohoda_predkontace, { placeholder: 'např. 3Fv', disabled: ro })
      + _ucetniPole(pre + '-cleneni', 'Členění DPH (volitelné)', c.pohoda_cleneni, { placeholder: 'např. UD', disabled: ro })
    : _ucetniPole(pre + '-url', 'Adresa serveru', c.flexibee_url, { placeholder: 'https://firma.flexibee.eu', disabled: ro })
      + _ucetniPole(pre + '-company', 'Kód firmy (z URL za /c/)', c.flexibee_company, { placeholder: 'moje_firma_s_r_o', disabled: ro })
      + _ucetniPole(pre + '-user', 'Uživatel', c.flexibee_username, { disabled: ro })
      + _ucetniPole(pre + '-pass', hesloLbl, '', { type: 'password', placeholder: c.flexibee_password_set ? '••••••••' : '', disabled: ro })
      + _ucetniPole(pre + '-typfa', 'Typ faktury (kód)', c.flexibee_typ_faktura, { placeholder: 'FAKTURA', disabled: ro })
      + _ucetniPole(pre + '-typdob', 'Typ dobropisu (kód)', c.flexibee_typ_dobropis, { placeholder: 'automaticky', disabled: ro });

  const popis = isP
    ? 'Vydané faktury a dobropisy do agendy <strong>Vydané faktury</strong>. Bez připojení stáhneš <strong>XML soubor</strong> a naimportuješ ho v POHODĚ (Soubor → Datová komunikace → XML import/export); s běžícím <strong>POHODA mServerem</strong> se odesílá přímo.'
    : 'Faktury a dobropisy do <strong>Vydaných faktur</strong>, odběratelé do <strong>Adresáře</strong>, výrobky do <strong>Ceníku</strong>. Opakované odeslání záznam aktualizuje (žádné duplicity).';

  const akce = isP
    ? `<button class="btn-secondary" onclick="ucetniHromadnyExport('pohoda')">📦 Hromadný export</button>
       <button class="btn-secondary" onclick="ucetniAdresar('pohoda')">👥 Odběratelé</button>
       <button class="btn-secondary" onclick="ucetniPrijate('pohoda')">📥 Přijaté faktury</button>
       <button class="btn-secondary" onclick="ucetniLog('pohoda')">📋 Log</button>`
    : `<button class="btn-secondary" onclick="ucetniHromadnyExport('flexibee')">📦 Hromadný export</button>
       <button class="btn-secondary" onclick="ucetniAdresar('flexibee')">👥 Odběratelé</button>
       <button class="btn-secondary" onclick="ucetniCenik()">🥖 Výrobky → ceník</button>
       <button class="btn-secondary" onclick="ucetniPrijate('flexibee')">📥 Přijaté faktury</button>
       <button class="btn-secondary" onclick="ucetniLog('flexibee')">📋 Log</button>`;

  const napoveda = isP
    ? `<ol style="padding-left:18px;margin:6px 0">
         <li>V POHODĚ: <strong>Soubor → POHODA mServer</strong> → nový mServer pro účetní jednotku, port (např. 444), spustit.</li>
         <li>Uživatel potřebuje právo <em>Soubor → Datová komunikace</em> a <em>Administrátorské funkce → POHODA mServer</em>.</li>
         <li>APPEK na internetu: mServer zveřejni přes <strong>HTTPS</strong> (reverzní proxy / VPN). Adresy v místní síti jdou jen po zapnutí volby níž.</li>
         <li>Bez mServeru stačí <strong>⬇️ XML</strong> (u faktury nebo hromadně) a import v POHODĚ.</li>
       </ol>`
    : `<ol style="padding-left:18px;margin:6px 0">
         <li>Adresa serveru bez cesty (např. <code>https://firma.flexibee.eu</code>), kód firmy najdeš v URL za <code>/c/</code>.</li>
         <li>Uživatel musí mít povolený přístup přes <strong>REST API</strong>.</li>
         <li>Typ dobropisu se dohledá sám (doklad typu „dobropis"), případně zadej jeho kód.</li>
       </ol>`;

  return `
    <div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:8px;margin-bottom:10px">
      <h3 style="margin:0;font-size:15px;display:flex;align-items:center;gap:8px">${UCETNI_SYS[sys].badge} ${isP ? 'mServer / XML' : 'REST API'}</h3>
      ${_ucetniStavPill(c, sys)}
    </div>
    <p style="font-size:12px;color:var(--text-3);margin:0 0 10px;line-height:1.5">${popis}</p>
    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(170px,1fr));gap:8px">${pole}</div>
    <div style="display:flex;flex-direction:column;gap:6px;margin-top:10px;font-size:13px">
      <label style="display:flex;align-items:center;gap:6px;cursor:pointer;font-weight:600">
        <input type="checkbox" id="${pre}-enabled" ${c[sys + '_enabled'] === '1' ? 'checked' : ''} ${ro ? 'disabled' : ''} style="width:18px;height:18px">
        Zapnout ${UCETNI_SYS[sys].label} (tlačítka u faktur)
      </label>
      <label style="display:flex;align-items:center;gap:6px;cursor:pointer;color:var(--text-2)" title="Platí pro POHODU i FlexiBee">
        <input type="checkbox" id="${pre}-lan" ${cfg.povolit_lan ? 'checked' : ''} ${ro ? 'disabled' : ''} style="width:16px;height:16px">
        Povolit adresy v místní síti (APPEK běží ve stejné síti jako účetní server)
      </label>
    </div>
    <div style="display:flex;gap:8px;flex-wrap:wrap;margin-top:10px">
      ${ro ? '' : `<button class="btn-primary" onclick="ucetniUlozit('${sys}')">💾 Uložit</button>`}
      <button class="btn-secondary" onclick="ucetniTest('${sys}')">🔌 Test připojení</button>
    </div>
    <div style="font-size:12px;margin-top:10px;padding:8px 10px;background:var(--surface-2);border-radius:8px" id="${pre}-posledni">
      <strong>Poslední synchronizace:</strong> ${_ucetniPosledni(c.posledni)}
    </div>
    <div style="display:flex;gap:6px;flex-wrap:wrap;margin-top:10px">${akce}</div>
    <details style="margin-top:10px">
      <summary style="cursor:pointer;color:var(--text-3);font-size:13px">ℹ️ Jak nastavit ${sys === 'pohoda' ? 'POHODU' : 'FlexiBee'}?</summary>
      <div style="font-size:12.5px;color:var(--text-2);line-height:1.6">${napoveda}</div>
    </details>`;
}

window.ucetniUlozit = async function(sys) {
  const pre = sys === 'pohoda' ? 'uip' : 'uif';
  const v = (id) => { const el = document.getElementById(pre + '-' + id); return el ? el.value.trim() : ''; };
  const body = { system: sys, enabled: document.getElementById(pre + '-enabled').checked ? '1' : '0',
                 povolit_lan: document.getElementById(pre + '-lan').checked, url: v('url'), username: v('user') };
  const pass = document.getElementById(pre + '-pass').value;
  if (pass) body.password = pass; // prázdné = ponech uložené
  if (sys === 'pohoda') Object.assign(body, { ico: v('ico'), predkontace: v('predkontace'), cleneni: v('cleneni') });
  else Object.assign(body, { company: v('company'), typ_faktura: v('typfa'), typ_dobropis: v('typdob') });
  try {
    await api('admin_ucetni.php?action=save', { method: 'POST', body });
    toastSuccess('✅ Nastavení ' + UCETNI_SYS[sys].do + ' uloženo');
    loadUcetniIntegrace();
  } catch (e) { toastError('❌ ' + _ucetniChyba(e)); }
};

window.ucetniTest = async function(sys) {
  toastInfo('⏳ Testuji připojení k ' + UCETNI_SYS[sys].k + '…');
  try {
    const r = await api('admin_ucetni.php?action=test', { method: 'POST', body: { system: sys } });
    if (r.ok) { toastSuccess('✅ ' + r.message); if (r.warning) toastWarn('⚠️ ' + r.warning); }
    else toastError('❌ ' + (r.error || 'Připojení selhalo'));
  } catch (e) { toastError('❌ ' + _ucetniChyba(e)); }
  loadUcetniIntegrace();
};

// ─────────────────────────── stažení souboru (POST → blob) ───────────────────────────

async function _ucetniStahni(action, body, fallbackName) {
  const res = await fetch(`${API}/admin_ucetni.php?action=${action}`, {
    method: 'POST', credentials: 'include',
    headers: csrfHeaders({ 'Content-Type': 'application/json' }),
    body: JSON.stringify(body),
  });
  if (!res.ok) {
    let msg = 'HTTP ' + res.status;
    try { const j = await res.json(); if (j && j.error) msg = j.error === 'csrf_invalid' ? 'Relace vypršela — obnov stránku.' : j.error; } catch (e) {}
    throw new Error(msg);
  }
  const cd = res.headers.get('Content-Disposition') || '';
  const m = cd.match(/filename="([^"]+)"/);
  const vynechano = res.headers.get('X-Ucetni-Vynechano');
  const blob = await res.blob();
  const url = URL.createObjectURL(blob);
  const a = document.createElement('a');
  a.href = url; a.download = m ? m[1] : fallbackName;
  document.body.appendChild(a); a.click(); a.remove();
  setTimeout(() => URL.revokeObjectURL(url), 2000);
  if (vynechano) toastWarn('⚠️ Vynecháno: ' + decodeURIComponent(vynechano));
}

// ─────────────────────────── hromadný export ───────────────────────────

window.ucetniHromadnyExport = function(sys) {
  const isP = sys === 'pohoda';
  openModal(`📦 Hromadný export faktur — ${UCETNI_SYS[sys].label}`, `
    <p style="font-size:13px;color:var(--text-2);margin:0 0 12px">Faktury a dobropisy podle data vystavení. Už exportované faktury beze změny se přeskočí; změněné se odešlou jen se zaškrtnutím „i změněné".</p>
    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(150px,1fr));gap:10px">
      <label><span class="form-label">Od</span><input type="date" id="ucx-od" class="form-input" value="${_ucetniZacatekMesice()}"></label>
      <label><span class="form-label">Do</span><input type="date" id="ucx-do" class="form-input" value="${_ucetniDnes()}"></label>
    </div>
    <div style="display:flex;flex-direction:column;gap:6px;margin:12px 0;font-size:13px">
      <label style="display:flex;gap:6px;align-items:center"><input type="radio" name="ucx-rozsah" value="nove" checked> Jen dosud neexportované</label>
      <label style="display:flex;gap:6px;align-items:center"><input type="radio" name="ucx-rozsah" value="vse"> Všechny v období</label>
      <label style="display:flex;gap:6px;align-items:center"><input type="checkbox" id="ucx-force"> Odeslat znovu i faktury změněné od posledního exportu</label>
    </div>
    <div style="display:flex;gap:8px;flex-wrap:wrap">
      ${isP ? `<button class="btn-secondary" onclick="ucetniHromadneXml()">⬇️ Stáhnout XML pro POHODU</button>` : ''}
      <button class="btn-primary" onclick="ucetniHromadneOdeslat('${sys}')">📤 Odeslat do ${UCETNI_SYS[sys].do}</button>
    </div>
    <div id="ucx-vysledek" style="margin-top:14px"></div>
  `, 'wide');
};

function _ucetniObdobiZFormu() {
  return {
    od: document.getElementById('ucx-od').value,
    do: document.getElementById('ucx-do').value,
    jen_neodeslane: (document.querySelector('input[name="ucx-rozsah"]:checked') || {}).value !== 'vse',
    force: !!(document.getElementById('ucx-force') || {}).checked,
  };
}

window.ucetniHromadneXml = async function() {
  const o = _ucetniObdobiZFormu();
  try {
    await _ucetniStahni('pohoda_xml', o, 'pohoda_faktury.xml');
    toastSuccess('✅ XML staženo — naimportuj ho v POHODĚ (Soubor → Datová komunikace → XML import/export)');
  } catch (e) { toastError('❌ ' + _ucetniChyba(e)); }
};

window.ucetniHromadneOdeslat = async function(sys) {
  const o = _ucetniObdobiZFormu();
  const box = document.getElementById('ucx-vysledek');
  if (box) box.innerHTML = '<div style="color:var(--text-3)">⏳ Odesílám… (může trvat i minutu)</div>';
  try {
    const r = await api(`admin_ucetni.php?action=${sys === 'pohoda' ? 'pohoda_send_bulk' : 'flexi_send_bulk'}`, { method: 'POST', body: o });
    const rows = (r.vysledky || []).map(v => `<tr>
        <td style="padding:4px 6px">${esc(v.cislo)}</td>
        <td style="padding:4px 6px">${v.ok ? '✅' : '❌'}</td>
        <td style="padding:4px 6px">${esc(v.externi_cislo || '')}</td>
        <td style="padding:4px 6px;font-size:12px">${esc(v.zprava || '')}</td></tr>`).join('')
      + (r.preskocene || []).map(v => `<tr style="color:var(--text-3)">
        <td style="padding:4px 6px">${esc(v.cislo)}</td><td style="padding:4px 6px">⏭️</td><td></td>
        <td style="padding:4px 6px;font-size:12px">${esc(v.zprava || '')}</td></tr>`).join('');
    if (box) box.innerHTML = `
      <div style="font-weight:600;margin-bottom:8px">Odesláno ${r.odeslano} · chyb ${r.chyb} · přeskočeno ${r.preskoceno}</div>
      ${rows ? `<div style="max-height:50vh;overflow:auto"><table style="width:100%;border-collapse:collapse;font-size:13px">
        <thead><tr style="text-align:left;color:var(--text-3);font-size:11px;text-transform:uppercase"><th style="padding:4px 6px">Faktura</th><th></th><th style="padding:4px 6px">Číslo ${esc(UCETNI_SYS[sys].v)}</th><th style="padding:4px 6px">Zpráva</th></tr></thead>
        <tbody>${rows}</tbody></table></div>` : '<div style="color:var(--text-3)">V období nebylo nic k odeslání.</div>'}`;
    if (r.chyb) toastWarn(`⚠️ ${r.chyb} faktur se nepodařilo odeslat — viz tabulka`);
    else toastSuccess(`✅ Odesláno ${r.odeslano} faktur`);
  } catch (e) {
    if (box) box.innerHTML = `<div style="color:var(--danger-text)">❌ ${esc(_ucetniChyba(e))}</div>`;
    toastError('❌ ' + _ucetniChyba(e));
  }
};

// ─────────────────────────── adresář / ceník ───────────────────────────

window.ucetniAdresar = async function(sys) {
  if (sys === 'pohoda') {
    const odeslat = await confirmDialog({ title: '👥 Odběratelé → POHODA', msg: 'Odeslat všechny odběratele přímo na mServer? (Zrušit = jen stáhnout XML pro ruční import.) Existující záznamy se stejným IČO se aktualizují.', okText: '📤 Odeslat', cancelText: '⬇️ Jen XML' });
    try {
      if (!odeslat) { await _ucetniStahni('pohoda_adresar', { rezim: 'soubor' }, 'pohoda_adresar.xml'); toastSuccess('✅ XML adresáře staženo'); return; }
      toastInfo('⏳ Odesílám odběratele…');
      const r = await api('admin_ucetni.php?action=pohoda_adresar', { method: 'POST', body: { rezim: 'odeslat' } });
      if (r.chyby && r.chyby.length) toastWarn(`⚠️ Zapsáno ${r.odeslano}, chyby: ${r.chyby.slice(0, 3).join(' | ')}`);
      else toastSuccess(`✅ Zapsáno ${r.odeslano} odběratelů do POHODY`);
    } catch (e) { toastError('❌ ' + _ucetniChyba(e)); }
    return;
  }
  if (!(await confirmDialog({ title: '👥 Odběratelé → FlexiBee', msg: 'Zapsat všechny odběratele do adresáře FlexiBee? Opakovaný export záznamy jen aktualizuje.', okText: 'Odeslat' }))) return;
  toastInfo('⏳ Odesílám odběratele…');
  try {
    const r = await api('admin_ucetni.php?action=flexi_adresar', { method: 'POST', body: {} });
    if (r.ok) toastSuccess(`✅ Zapsáno ${r.odeslano} odběratelů do FlexiBee`);
    else toastWarn(`⚠️ Zapsáno ${r.odeslano}, chyby: ${(r.chyby || []).join(' | ')}`);
  } catch (e) { toastError('❌ ' + _ucetniChyba(e)); }
};

window.ucetniCenik = async function() {
  if (!(await confirmDialog({ title: '🥖 Výrobky → ceník FlexiBee', msg: 'Zapsat všechny aktivní výrobky do ceníku FlexiBee (cena bez DPH, sazba, EAN)? Opakovaný export záznamy aktualizuje.', okText: 'Odeslat' }))) return;
  toastInfo('⏳ Odesílám výrobky…');
  try {
    const r = await api('admin_ucetni.php?action=flexi_cenik', { method: 'POST', body: {} });
    if (r.ok) toastSuccess(`✅ Zapsáno ${r.odeslano} výrobků do ceníku`);
    else toastWarn(`⚠️ Zapsáno ${r.odeslano}, chyby: ${(r.chyby || []).join(' | ')}`);
  } catch (e) { toastError('❌ ' + _ucetniChyba(e)); }
};

// ─────────────────────────── přijaté faktury (jen čtení) ───────────────────────────

window.ucetniPrijate = function(sys) {
  openModal(`📥 Přijaté faktury — ${UCETNI_SYS[sys].label}`, `
    <div style="display:flex;gap:8px;flex-wrap:wrap;align-items:end;margin-bottom:12px">
      <label><span class="form-label">Od</span><input type="date" id="ucp-od" class="form-input" value="${_ucetniZacatekMesice()}"></label>
      <label><span class="form-label">Do</span><input type="date" id="ucp-do" class="form-input" value="${_ucetniDnes()}"></label>
      <button class="btn-primary" onclick="ucetniPrijateNacti('${sys}')">🔎 Načíst</button>
    </div>
    <div id="ucp-vysledek" style="font-size:13px;color:var(--text-3)">Vyber období a klikni na Načíst. Data se jen zobrazí, do APPEK se nic neukládá.</div>
  `, 'wide');
};

window.ucetniPrijateNacti = async function(sys) {
  const od = document.getElementById('ucp-od').value, dd = document.getElementById('ucp-do').value;
  const box = document.getElementById('ucp-vysledek');
  box.innerHTML = '⏳ Načítám…';
  try {
    const r = await api(`admin_ucetni.php?action=${sys === 'pohoda' ? 'pohoda_prijate' : 'flexi_prijate'}&od=${encodeURIComponent(od)}&do=${encodeURIComponent(dd)}&_t=${Date.now()}`);
    const f = r.faktury || [];
    if (!f.length) { box.innerHTML = 'V období nejsou žádné přijaté faktury.'; return; }
    const suma = {};
    f.forEach(x => { suma[x.mena] = (suma[x.mena] || 0) + Number(x.celkem || 0); });
    box.innerHTML = `
      <div style="margin-bottom:8px;color:var(--text-2)">${f.length} faktur · ${Object.entries(suma).map(([m, s]) => esc(s.toLocaleString('cs-CZ', { minimumFractionDigits: 2, maximumFractionDigits: 2 }) + ' ' + m)).join(' · ')}</div>
      <div style="max-height:60vh;overflow:auto"><table style="width:100%;border-collapse:collapse;font-size:13px;color:var(--text)">
        <thead><tr style="text-align:left;color:var(--text-3);font-size:11px;text-transform:uppercase">
          <th style="padding:4px 6px">Číslo</th><th style="padding:4px 6px">Dod. číslo</th><th style="padding:4px 6px">Dodavatel</th>
          <th style="padding:4px 6px">Vystaveno</th><th style="padding:4px 6px">Splatnost</th><th style="padding:4px 6px;text-align:right">Celkem</th></tr></thead>
        <tbody>${f.map(x => `<tr style="border-top:1px solid var(--border)">
          <td style="padding:4px 6px">${esc(x.cislo)}</td>
          <td style="padding:4px 6px">${esc(x.doslo_cislo || x.vs || '')}</td>
          <td style="padding:4px 6px">${esc(x.dodavatel)}${x.ico ? ` <span style="color:var(--text-3);font-size:11px">IČO ${esc(x.ico)}</span>` : ''}</td>
          <td style="padding:4px 6px;white-space:nowrap">${esc(typeof fmtDate === 'function' ? fmtDate(x.datum) : x.datum)}</td>
          <td style="padding:4px 6px;white-space:nowrap">${esc(x.splatnost ? (typeof fmtDate === 'function' ? fmtDate(x.splatnost) : x.splatnost) : '')}</td>
          <td style="padding:4px 6px;text-align:right;white-space:nowrap">${esc(Number(x.celkem || 0).toLocaleString('cs-CZ', { minimumFractionDigits: 2, maximumFractionDigits: 2 }) + ' ' + x.mena)}</td>
        </tr>`).join('')}</tbody></table></div>`;
  } catch (e) { box.innerHTML = `<span style="color:var(--danger-text)">❌ ${esc(_ucetniChyba(e))}</span>`; }
};

// ─────────────────────────── log ───────────────────────────

window.ucetniLog = async function(sys) {
  openModal(`📋 Log synchronizací — ${UCETNI_SYS[sys].label}`, '<div id="ucl-box">⏳ Načítám…</div>', 'wide');
  try {
    const r = await api(`admin_ucetni.php?action=log&system=${sys}&limit=100&_t=${Date.now()}`);
    const z = r.zaznamy || [];
    const box = document.getElementById('ucl-box');
    if (!box) return;
    if (!z.length) { box.innerHTML = '<div style="color:var(--text-3)">Zatím žádné záznamy.</div>'; return; }
    box.innerHTML = `<div style="max-height:65vh;overflow:auto"><table style="width:100%;border-collapse:collapse;font-size:12.5px">
      <thead><tr style="text-align:left;color:var(--text-3);font-size:11px;text-transform:uppercase">
        <th style="padding:4px 6px">Kdy</th><th></th><th style="padding:4px 6px">Akce</th><th style="padding:4px 6px">Doklad</th><th style="padding:4px 6px">Zpráva</th><th style="padding:4px 6px">Kdo</th></tr></thead>
      <tbody>${z.map(x => `<tr style="border-top:1px solid var(--border);vertical-align:top">
        <td style="padding:4px 6px;white-space:nowrap">${esc(_ucetniKdy(x.created_at))}</td>
        <td style="padding:4px 6px">${String(x.success) === '1' ? '✅' : '❌'}</td>
        <td style="padding:4px 6px">${esc(x.action)}</td>
        <td style="padding:4px 6px">${esc(x.target_ref || '')}</td>
        <td style="padding:4px 6px">${esc(x.message || '')}${x.response_excerpt ? `<details><summary style="cursor:pointer;color:var(--text-3)">odpověď serveru</summary><pre style="white-space:pre-wrap;font-size:11px;max-height:200px;overflow:auto">${esc(x.response_excerpt)}</pre></details>` : ''}</td>
        <td style="padding:4px 6px">${esc(x.created_by || '')}</td>
      </tr>`).join('')}</tbody></table></div>`;
  } catch (e) {
    const box = document.getElementById('ucl-box');
    if (box) box.innerHTML = `<span style="color:var(--danger-text)">❌ ${esc(_ucetniChyba(e))}</span>`;
  }
};

// ─────────────────────────── detail faktury ───────────────────────────

/** Háček pro detail faktury (0410): vrátí kontejner, tlačítka + stav se dočtou asynchronně. */
function ucetniFakturaTlacitka(f) {
  const id = parseInt(f && f.id, 10);
  if (!id) return '';
  setTimeout(() => ucetniFakturaObnov(id), 0);
  return `<span id="ucetni-fa-${id}" class="ucetni-fa-akce" style="display:contents"></span>`;
}
window.ucetniFakturaTlacitka = ucetniFakturaTlacitka;

function _ucetniBadge(sys, s) {
  if (!s) return '';
  const lbl = UCETNI_SYS[sys].label;
  if (s.ok_at) {
    const kde = s.rezim === 'soubor' ? (sys === 'pohoda' ? 'XML pro POHODU staženo' : `XML pro ${lbl} staženo`) : (sys === 'pohoda' ? 'V POHODĚ' : 'Ve FlexiBee');
    const cislo = s.externi_cislo ? ` č. ${s.externi_cislo}` : '';
    const zmena = s.zmeneno_od_exportu ? ' · ⚠️ faktura se od exportu změnila' : '';
    const chyba = s.stav === 'chyba' && s.posledni_chyba ? ` · poslední pokus selhal: ${s.posledni_chyba}` : '';
    return `<span title="${esc(kde + cislo + ' (' + _ucetniKdy(s.ok_at) + ')' + zmena + chyba)}" style="font-size:11.5px;padding:3px 8px;border-radius:999px;background:${s.zmeneno_od_exportu || s.stav === 'chyba' ? '#FEF3C7;color:#92400E' : '#DCFCE7;color:#166534'};white-space:nowrap;align-self:center">✓ ${esc(kde + cislo)} · ${esc(_ucetniKdy(s.ok_at))}${s.zmeneno_od_exportu ? ' ⚠️' : ''}</span>`;
  }
  return `<span title="${esc(s.posledni_chyba || '')}" style="font-size:11.5px;padding:3px 8px;border-radius:999px;background:#FEE2E2;color:#991B1B;white-space:nowrap;align-self:center;max-width:320px;overflow:hidden;text-overflow:ellipsis">✗ ${esc(lbl)}: ${esc(String(s.posledni_chyba || 'export selhal').slice(0, 80))}</span>`;
}

async function ucetniFakturaObnov(id) {
  const host = document.getElementById('ucetni-fa-' + id);
  if (!host) return;
  let st;
  try { st = await api(`admin_ucetni.php?action=stav&faktura_id=${id}&_t=${Date.now()}`); }
  catch (e) { return; } // integrace není dostupná / nemá práva → nic nezobrazuj
  const h = document.getElementById('ucetni-fa-' + id);
  if (!h) return;
  const en = st.enabled || {};
  let html = '';
  if (en.pohoda) {
    html += `<button class="btn-secondary" onclick="ucetniOdeslatFakturu('pohoda', ${id})" title="Odeslat fakturu přímo na POHODA mServer">📤 Odeslat do POHODY</button>`;
    html += `<button class="btn-secondary" onclick="ucetniFakturaXml(${id})" title="Stáhnout XML pro ruční import v POHODĚ">⬇️ XML pro POHODU</button>`;
    html += _ucetniBadge('pohoda', st.pohoda);
  }
  if (en.flexibee) {
    html += `<button class="btn-secondary" onclick="ucetniOdeslatFakturu('flexibee', ${id})" title="Odeslat fakturu do ABRA Flexi (FlexiBee)">📤 Odeslat do FlexiBee</button>`;
    html += _ucetniBadge('flexibee', st.flexibee);
  }
  h.innerHTML = html;
}
window.ucetniFakturaObnov = ucetniFakturaObnov;

window.ucetniOdeslatFakturu = async function(sys, id, force = false) {
  const lbl = UCETNI_SYS[sys].label;
  toastInfo(sys === 'pohoda' ? '⏳ Odesílám do POHODY…' : '⏳ Odesílám do FlexiBee…');
  try {
    const r = await api(`admin_ucetni.php?action=${sys === 'pohoda' ? 'pohoda_send' : 'flexi_send'}`, { method: 'POST', body: { faktura_id: id, force } });
    if (r.needs_force) {
      const znovu = await confirmDialog({ title: sys === 'pohoda' ? 'Faktura už je v POHODĚ' : 'Faktura už je ve FlexiBee', msg: r.zprava + (r.duvod === 'beze_zmeny' ? ' Přesto odeslat znovu?' : ''), okText: 'Odeslat znovu' });
      if (znovu) return ucetniOdeslatFakturu(sys, id, true);
      return;
    }
    if (r.ok) {
      toastSuccess(`✅ Odesláno do ${sys === 'pohoda' ? 'POHODY' : 'FlexiBee'}${r.externi_cislo ? ' — č. ' + r.externi_cislo : ''}${r.zprava ? ' (' + r.zprava + ')' : ''}`);
      (r.varovani || []).forEach(w => toastWarn('⚠️ ' + w));
    } else {
      toastError(`❌ ${lbl}: ${r.zprava || 'odeslání selhalo'}`);
    }
  } catch (e) { toastError('❌ ' + _ucetniChyba(e)); }
  ucetniFakturaObnov(id);
};

window.ucetniFakturaXml = async function(id) {
  try {
    await _ucetniStahni('pohoda_xml', { faktura_id: id }, `pohoda_faktura_${id}.xml`);
    toastSuccess('✅ XML staženo — naimportuj ho v POHODĚ (Soubor → Datová komunikace → XML import/export)');
  } catch (e) { toastError('❌ ' + _ucetniChyba(e)); }
  ucetniFakturaObnov(id);
};
