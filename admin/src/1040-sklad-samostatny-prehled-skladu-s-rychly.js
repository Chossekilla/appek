// =============================================================
// 📦 SKLAD — samostatný přehled skladu s rychlými akcemi
// =============================================================
async function renderSklad() {
  // Cache zdílí se s surovinami
  let list = state._suroviny_full_cache;
  if (!list) {
    list = await api('admin_suroviny.php');
    state._suroviny_full_cache = list;
  }
  const c = document.getElementById('content');

  // Filtr — jen aktivní suroviny s relevantnimi daty
  const aktivni = (list || []).filter(s => parseInt(s.aktivni) !== 0);

  // Roztřídit do skupin: pod minimem / OK / bez minima
  const podMin = [];
  const nadMin = [];
  const bezNasr = [];
  aktivni.forEach(s => {
    const akt = parseFloat(s.stock_aktualni) || 0;
    const min = parseFloat(s.stock_minimalni);
    if (isNaN(min)) bezNasr.push(s);
    else if (akt <= min) podMin.push(s);
    else nadMin.push(s);
  });

  // Setříděno: pod minimem podle "kolik chybí" sestupně, ostatní abecedně
  podMin.sort((a, b) => {
    const ma = parseFloat(a.stock_minimalni) - (parseFloat(a.stock_aktualni) || 0);
    const mb = parseFloat(b.stock_minimalni) - (parseFloat(b.stock_aktualni) || 0);
    return mb - ma;
  });
  nadMin.sort((a, b) => (a.nazev || '').localeCompare(b.nazev || '', 'cs'));
  bezNasr.sort((a, b) => (a.nazev || '').localeCompare(b.nazev || '', 'cs'));

  // Celková hodnota skladu (cena_baleni / obsah_baleni × stock)
  let celkemHodnota = 0;
  aktivni.forEach(s => {
    const cb = parseFloat(s.cena_baleni) || 0;
    const ob = parseFloat(s.obsah_baleni) || 0;
    const akt = parseFloat(s.stock_aktualni) || 0;
    if (cb > 0 && ob > 0 && akt > 0) celkemHodnota += (cb / ob) * akt;
  });

  const radek = (s) => {
    const akt = parseFloat(s.stock_aktualni) || 0;
    const min = parseFloat(s.stock_minimalni);
    const cil = parseFloat(s.stock_cilove);
    const jed = esc(s.jednotka || 'g');
    const cb = parseFloat(s.cena_baleni) || 0;
    const ob = parseFloat(s.obsah_baleni) || 0;
    const cenaJed = (cb > 0 && ob > 0) ? cb / ob : 0;
    const hodnota = cenaJed * akt;
    const podMinFlag = !isNaN(min) && akt <= min;
    return `
      <tr ${podMinFlag ? 'style="background:rgba(220,38,38,0.06)"' : ''}>
        <td><strong>${esc(s.nazev)}</strong>${s.alergen ? `<span style="margin-left:6px;background:#fef3c7;color:#92400e;font-size:10px;padding:1px 6px;border-radius:6px;font-weight:600">${esc(s.alergen)}</span>` : ''}</td>
        <td class="num" style="font-variant-numeric:tabular-nums">
          ${podMinFlag
            ? `<span style="background:#FEE2E2;color:#991B1B;font-weight:700;padding:3px 10px;border-radius:8px;font-size:13px">⚠ ${akt.toFixed(2).replace(/\.?0+$/, '').replace('.', ',')} ${jed}</span>`
            : `<strong>${akt.toFixed(2).replace(/\.?0+$/, '').replace('.', ',')}</strong> ${jed}`}
        </td>
        <td class="num" style="color:var(--text-3);font-size:12px">${!isNaN(min) ? min + ' ' + jed : '—'}</td>
        <td class="num" style="color:var(--text-3);font-size:12px">${!isNaN(cil) ? cil + ' ' + jed : '—'}</td>
        <td class="num">${hodnota > 0 ? fmt(hodnota) : '<span style="color:var(--text-3)">—</span>'}</td>
        <td onclick="event.stopPropagation();">
          <button class="btn-primary btn-green" style="font-size:12px;padding:6px 12px;margin-right:4px" onclick="surSkladModal(${s.id})" title="Příjem / výdej / inventura">📦 Pohyb</button>
          <button class="btn-secondary" style="font-size:12px;padding:6px 10px" onclick="editSurovina(${s.id})">✏️</button>
        </td>
      </tr>
    `;
  };

  const head = `
    <thead>
      <tr>
        <th>Surovina</th>
        <th class="num">Aktuální stav</th>
        <th class="num">Minimum</th>
        <th class="num">Cíl</th>
        <th class="num">Hodnota Kč</th>
        <th></th>
      </tr>
    </thead>
  `;

  c.innerHTML = `
    <div class="page-head">
      <div>
        <h1 class="page-title">📦 Sklad surovin</h1>
        <p class="page-sub">${aktivni.length} aktivních surovin · Hodnota skladu: <strong>${fmt(celkemHodnota)}</strong></p>
      </div>
      <div style="display:flex;gap:8px;flex-wrap:wrap">
        <button class="btn-secondary" onclick="navigate('vyroba')">← Výroba</button>
        <button class="btn-secondary" onclick="navigate('suroviny')">🌾 Spravovat suroviny</button>
      </div>
    </div>

    <!-- 🆕 v2.9.265 — Stat cards konzistence s Dashboard (clickable warn, primary tint hodnota) -->
    <div class="stat-grid" style="margin-bottom:14px">
      ${podMin.length > 0 ? `
        <div class="stat-card stat-warn" onclick="state._suroviny_pod_minimem=true;navigate('suroviny')" title="Klikni → Suroviny filtrované pod minimem">
          <div class="stat-label">⚠️ Pod minimální hladinou</div>
          <div class="stat-value">${podMin.length}</div>
          <div class="stat-sub">klikni → suroviny</div>
        </div>
      ` : `
        <div class="stat-card">
          <div class="stat-label">✓ Pod minimem</div>
          <div class="stat-value" style="color:var(--success-text)">0</div>
          <div class="stat-sub">vše OK</div>
        </div>
      `}
      <div class="stat-card">
        <div class="stat-label">✓ V pořádku</div>
        <div class="stat-value" style="color:var(--success-text)">${nadMin.length}</div>
        <div class="stat-sub">nad minimem</div>
      </div>
      <div class="stat-card">
        <div class="stat-label">⚪ Bez minima</div>
        <div class="stat-value" style="color:var(--text-3)">${bezNasr.length}</div>
        <div class="stat-sub">nenastaveno</div>
      </div>
      <div class="stat-card" style="background:linear-gradient(180deg, var(--surface) 0%, rgba(186, 117, 23, 0.04) 100%);border-color:var(--primary-border)">
        <div class="stat-label">💰 Hodnota skladu</div>
        <div class="stat-value" style="color:var(--primary-dark);font-weight:700">${fmt(celkemHodnota)}</div>
        <div class="stat-sub">celkem na skladě</div>
      </div>
    </div>

    ${podMin.length > 0 ? `
      <div class="card-block" style="margin-bottom:14px;border-left:4px solid #DC2626;background:rgba(220,38,38,0.05)">
        <h3 style="margin:0 0 10px;color:#991B1B;font-size:16px">⚠️ Suroviny pod minimální hladinou — doporučujeme naskladnit</h3>
        <table class="table" style="margin:0;font-size:13px">
          ${head}
          <tbody>${podMin.map(radek).join('')}</tbody>
        </table>
      </div>
    ` : ''}

    ${nadMin.length > 0 ? `
      <div class="card-block" style="margin-bottom:14px">
        <h3 style="margin:0 0 10px;font-size:16px">✅ Suroviny v pořádku</h3>
        <table class="table" style="margin:0;font-size:13px">
          ${head}
          <tbody>${nadMin.map(radek).join('')}</tbody>
        </table>
      </div>
    ` : ''}

    ${bezNasr.length > 0 ? `
      <details class="card-block" style="margin-bottom:14px">
        <summary style="cursor:pointer;font-weight:700;font-size:16px;padding:6px 0">
          📋 Bez nastaveného minima (${bezNasr.length}) — klikni pro zobrazení
          <span style="font-weight:400;font-size:12px;color:var(--text-3);margin-left:8px">Nastavte minimum pro alerty</span>
        </summary>
        <div style="margin-top:10px">
          <table class="table" style="margin:0;font-size:13px">
            ${head}
            <tbody>${bezNasr.map(radek).join('')}</tbody>
          </table>
        </div>
      </details>
    ` : ''}

    ${aktivni.length === 0 ? '<div class="card-block"><div class="empty-state">Žádné suroviny — přidej je v sekci Suroviny.</div></div>' : ''}
  `;
}

// 🆕 v3.0.446 — přepínač HACCP hlídání šarží/expirace (uloží nastavení + re-render)
window.skladSledovatelnostToggle = async function(on) {
  try {
    await api('admin_nastaveni.php', { method: 'PUT', body: JSON.stringify({ sklad_sledovatelnost: on ? '1' : '0' }) });
    if (!state.nastaveni) state.nastaveni = {};
    state.nastaveni.sklad_sledovatelnost = on ? '1' : '0';
    if (typeof toast === 'function') toast(on ? '✓ Hlídání šarží zapnuto' : 'Hlídání šarží vypnuto', 'info');
    renderSuroviny();
  } catch (e) { alert('Chyba: ' + e.message); }
};

// 🆕 v3.0.458 — stránkování seznamu surovin (klientské, dle nastavení pagination_styl/pocet).
window.surLoadMore = function() {
  const sp = state._surPag; if (sp) sp.shown = (sp.shown || sp.limit || 10) + (sp.limit || 10);
  renderSuroviny();
};
window.surGoToPage = function(p) {
  const sp = state._surPag; if (sp) { sp.offset = p * (sp.limit || 10); sp.shown = 0; }
  renderSuroviny();
};

// 🔒 v3.0.454 — úroveň kontroly šarží ve výrobě (off/warn/block). Off = nikdy neobtěžuje.
window.skladEnforceSet = async function(val) {
  try {
    await api('admin_nastaveni.php', { method: 'PUT', body: JSON.stringify({ sklad_sled_enforce: val }) });
    if (!state.nastaveni) state.nastaveni = {};
    state.nastaveni.sklad_sled_enforce = val;
    if (typeof toast === 'function') toast(val === 'off' ? 'Kontrola šarží ve výrobě vypnuta' : (val === 'warn' ? 'Kontrola: jen upozornit' : 'Kontrola: blokovat (s možností přeskočit)'), 'info');
  } catch (e) { alert('Chyba: ' + e.message); }
};

// 🔒 v3.0.454 — karanténa / uvolnění šarže. hold=1 blokovat, hold=0 uvolnit.
window.skladSarzeHold = async function(surovinaId, sarze, hold) {
  let poznamka = '';
  if (hold) {
    poznamka = prompt('🔒 Blokovat šarži „' + sarze + '" (karanténa)\n\nDůvod / poznámka (volitelné):', '');
    if (poznamka === null) return; // zrušeno
  } else {
    if (!confirm('Uvolnit šarži „' + sarze + '" z karantény?')) return;
  }
  try {
    await api('admin_suroviny.php?action=sarze_stav', { method: 'POST', body: JSON.stringify({ surovina_id: surovinaId, sarze, hold: !!hold, poznamka }) });
    if (typeof toast === 'function') toast(hold ? '🔒 Šarže v karanténě' : '✅ Šarže uvolněna', 'info');
    renderSuroviny();
  } catch (e) { alert('Chyba: ' + e.message); }
};

// 🔎 v3.0.453 — TRASOVATELNOST / RECALL: vyber surovinu (+ šarži, okno) → koho obvolat + které objednávky.
window.skladRecall = async function(surovinaId, sarze) {
  const dcz = (s) => (s ? String(s).split('-').reverse().join('.') : '');
  // seznam surovin do selectu (z cache nebo dotáhni)
  let list = state._suroviny_full_cache;
  if (!list) { try { list = await api('admin_suroviny.php'); state._suroviny_full_cache = list; } catch (e) { list = []; } }
  const opts = (list || []).map(s => `<option value="${s.id}" ${(+s.id === +surovinaId) ? 'selected' : ''}>${esc(s.nazev)}</option>`).join('');
  const body = `
    <div style="display:grid;grid-template-columns:2fr 1fr;gap:12px;align-items:end">
      <label style="font-size:12px;font-weight:600;color:var(--text-2)">Surovina
        <select id="rc-sur" class="form-input" style="margin-top:4px">${opts}</select></label>
      <label style="font-size:12px;font-weight:600;color:var(--text-2)">Šarže / LOT <span style="font-weight:400;color:var(--text-3)">(volitelné)</span>
        <input id="rc-sz" class="form-input" style="margin-top:4px" value="${esc(sarze || '')}" placeholder="např. L2026-14"></label>
    </div>
    <div style="display:grid;grid-template-columns:1fr 1fr 1fr auto;gap:12px;align-items:end;margin-top:10px">
      <label style="font-size:12px;font-weight:600;color:var(--text-2)">Od
        <input id="rc-from" type="date" class="form-input" style="margin-top:4px"></label>
      <label style="font-size:12px;font-weight:600;color:var(--text-2)">Do
        <input id="rc-to" type="date" class="form-input" style="margin-top:4px"></label>
      <label style="font-size:12px;font-weight:600;color:var(--text-2)">Datum podle
        <select id="rc-df" class="form-input" style="margin-top:4px">
          <option value="datum_dodani">dodání</option>
          <option value="datum_objednani">objednání</option></select></label>
      <button class="btn-primary" onclick="_recallRun()">🔎 Dohledat</button>
    </div>
    <p style="font-size:11px;color:var(--text-3);margin:8px 0 0">Prázdné okno = automaticky dle šarže (příjem → expirace), jinak posledních 90 dní.</p>
    <div id="rc-results" style="margin-top:16px"></div>`;
  openModal('🔎 Trasovatelnost / Recall', body, 'wide');
  if (surovinaId && +surovinaId > 0) setTimeout(_recallRun, 80); // prefill → rovnou spusť
};

window._recallRun = async function() {
  const sid = document.getElementById('rc-sur')?.value;
  const sz = (document.getElementById('rc-sz')?.value || '').trim();
  const from = document.getElementById('rc-from')?.value || '';
  const to = document.getElementById('rc-to')?.value || '';
  const df = document.getElementById('rc-df')?.value || 'datum_dodani';
  const box = document.getElementById('rc-results');
  if (!sid) { if (box) box.innerHTML = '<div style="color:#B91C1C">Vyber surovinu.</div>'; return; }
  if (box) box.innerHTML = '<div style="padding:20px;text-align:center;color:var(--text-3)">Hledám…</div>';
  let d;
  const qs = new URLSearchParams({ action: 'recall', surovina_id: sid, sarze: sz, from, to, date_field: df });
  try { d = await api('admin_suroviny.php?' + qs.toString()); }
  catch (e) { if (box) box.innerHTML = '<div style="color:#B91C1C">Chyba: ' + esc(e.message) + '</div>'; return; }
  state._recallLast = d;
  const dcz = (s) => (s ? String(s).split('-').reverse().join('.') : '—');
  const win = d.window || {};
  const custs = d.customers || [];
  const head = `
    <div style="background:var(--surface-2);border-radius:10px;padding:12px 14px;margin-bottom:12px;font-size:13px">
      <div><strong>${esc(d.surovina?.nazev || '')}</strong>${d.sarze ? ' · šarže <strong>' + esc(d.sarze) + '</strong>' : ''}</div>
      <div style="color:var(--text-2);margin-top:4px">Okno (${esc(win.date_field === 'datum_objednani' ? 'objednání' : 'dodání')}): <strong>${dcz(win.from)}</strong> – <strong>${dcz(win.to)}</strong>
        ${win.source === 'default' ? '<span style="color:#B25E00"> (výchozích 90 dní — zadej okno pro přesnost)</span>' : ''}</div>
      <div style="color:var(--text-2);margin-top:4px">Výrobků obsahujících surovinu: <strong>${d.products_count}</strong> · zasažených objednávek: <strong>${d.total_orders}</strong> · zákazníků: <strong>${d.total_customers}</strong>${d.truncated ? ' <span style="color:#B91C1C">(oříznuto na 5000 řádků)</span>' : ''}</div>
    </div>
    <div style="background:#FBEDDC;border:1px solid #EBC79A;border-radius:8px;padding:8px 12px;margin-bottom:12px;font-size:11.5px;color:#7A4A00">⚠️ ${esc(d.note || '')}</div>`;
  if (!custs.length) { box.innerHTML = head + '<div class="empty-state" style="padding:16px">Žádné objednávky v tomto okně neobsahovaly výrobky s touto surovinou.</div>'; return; }
  const callList = `
    <div style="display:flex;align-items:center;justify-content:space-between;gap:8px;margin:4px 0 8px">
      <h4 style="margin:0;font-size:14px">📞 Koho obvolat (${custs.length})</h4>
      <button class="btn-mini btn-secondary" onclick="_recallCsv()">📤 Export CSV</button>
    </div>
    <div style="max-height:320px;overflow:auto;border:1px solid var(--border);border-radius:8px">
      <table class="table" style="margin:0;font-size:12.5px">
        <thead><tr><th>Zákazník</th><th>Kontakt</th><th class="num">Obj.</th><th>Výrobky</th></tr></thead>
        <tbody>${custs.map(c => `<tr>
          <td><strong>${esc(c.nazev)}</strong>${c.kontakt ? '<br><span style="color:var(--text-3);font-size:11px">' + esc(c.kontakt) + '</span>' : ''}</td>
          <td style="font-size:11.5px">${c.telefon ? '📞 ' + esc(c.telefon) + '<br>' : ''}${c.email ? '✉️ ' + esc(c.email) : (c.telefon ? '' : '<span style="color:var(--text-3)">—</span>')}</td>
          <td class="num">${c.order_count}<br><span style="color:var(--text-3);font-size:11px">${c.objednavky.map(o => esc(o.cislo)).join(', ')}</span></td>
          <td style="font-size:11.5px">${c.vyrobky.map(esc).join(', ')}</td>
        </tr>`).join('')}</tbody>
      </table>
    </div>`;
  box.innerHTML = head + callList;
};

// Export call-listu recallu do CSV (klient-side z posledního výsledku)
window._recallCsv = function() {
  const d = state._recallLast; if (!d || !d.customers) return;
  const rows = [['Zákazník', 'Kontaktní osoba', 'Telefon', 'Email', 'Počet objednávek', 'Čísla objednávek', 'Výrobky']];
  d.customers.forEach(c => rows.push([c.nazev, c.kontakt || '', c.telefon || '', c.email || '', c.order_count, c.objednavky.map(o => o.cislo).join(' '), c.vyrobky.join(' ')]));
  const csv = '﻿' + rows.map(r => r.map(v => '"' + String(v).replace(/"/g, '""') + '"').join(';')).join('\r\n');
  const a = document.createElement('a');
  a.href = URL.createObjectURL(new Blob([csv], { type: 'text/csv;charset=utf-8' }));
  a.download = 'recall-' + (d.surovina?.nazev || 'surovina').replace(/[^a-z0-9]+/gi, '_') + (d.sarze ? '-' + d.sarze.replace(/[^a-z0-9]+/gi, '_') : '') + '.csv';
  a.click();
  setTimeout(() => URL.revokeObjectURL(a.href), 2000);
};

// 🔙 v3.0.456 — ZPĚTNÉ DOHLEDÁNÍ: výrobek (reklamace) + datum → kandidátní šarže surovin na skladě.
window.skladTraceBack = async function(vyrobekId, datum) {
  let prods = state._vyrobky_pick_cache;
  if (!prods) { try { const r = await api('admin_vyrobky.php'); prods = Array.isArray(r) ? r : (r.polozky || r.vyrobky || r.data || []); state._vyrobky_pick_cache = prods; } catch (e) { prods = []; } }
  const opts = (prods || []).map(p => `<option value="${p.id}" ${(+p.id === +vyrobekId) ? 'selected' : ''}>${esc(p.nazev)}</option>`).join('');
  const today = datum || new Date().toISOString().slice(0, 10);
  const body = `
    <div style="display:grid;grid-template-columns:2fr 1fr auto;gap:12px;align-items:end">
      <label style="font-size:12px;font-weight:600;color:var(--text-2)">Výrobek (reklamace)
        <select id="tb-v" class="form-input" style="margin-top:4px">${opts}</select></label>
      <label style="font-size:12px;font-weight:600;color:var(--text-2)">Datum výroby / dodání
        <input id="tb-d" type="date" class="form-input" style="margin-top:4px" value="${today}"></label>
      <button class="btn-primary" onclick="_traceBackRun()">🔙 Dohledat šarže</button>
    </div>
    <p style="font-size:11px;color:var(--text-3);margin:8px 0 0">Ukáže, které šarže surovin výrobku byly k datu na skladě (příjem ≤ datum) — kandidáti pro dohledání příčiny reklamace.</p>
    <div id="tb-res" style="margin-top:16px"></div>`;
  openModal('🔙 Zpětné dohledání šarží', body, 'wide');
  if (vyrobekId && +vyrobekId > 0) setTimeout(_traceBackRun, 80);
};

window._traceBackRun = async function() {
  const vid = document.getElementById('tb-v')?.value;
  const d = document.getElementById('tb-d')?.value;
  const box = document.getElementById('tb-res');
  if (!vid || !d) { if (box) box.innerHTML = '<div style="color:#B91C1C">Vyber výrobek i datum.</div>'; return; }
  if (box) box.innerHTML = '<div style="padding:20px;text-align:center;color:var(--text-3)">Hledám…</div>';
  let r;
  try { r = await api(`admin_suroviny.php?action=trace_back&vyrobek_id=${encodeURIComponent(vid)}&datum=${encodeURIComponent(d)}`); }
  catch (e) { if (box) box.innerHTML = '<div style="color:#B91C1C">Chyba: ' + esc(e.message) + '</div>'; return; }
  const dcz = (s) => (s ? String(s).split('-').reverse().join('.') : '—');
  const head = `
    <div style="background:var(--surface-2);border-radius:10px;padding:12px 14px;margin-bottom:12px;font-size:13px">
      <strong>${esc(r.vyrobek.nazev)}</strong> · datum <strong>${dcz(r.datum)}</strong> · surovin: <strong>${r.ingredient_count}</strong> · nalezených šarží: <strong>${r.batch_count}</strong></div>
    <div style="background:#FBEDDC;border:1px solid #EBC79A;border-radius:8px;padding:8px 12px;margin-bottom:12px;font-size:11.5px;color:#7A4A00">⚠️ ${esc(r.note)}</div>`;
  const ing = (r.ingredients || []).map(i => `
    <div style="margin-bottom:12px">
      <div style="font-weight:600;font-size:13px;margin-bottom:4px">${esc(i.nazev)} ${i.batches.length ? `<span style="color:var(--text-3);font-weight:400">(${i.batches.length})</span>` : ''}</div>
      ${i.batches.length ? `<div style="border:1px solid var(--border);border-radius:8px;overflow:hidden"><table class="table" style="margin:0;font-size:12px">
        <thead><tr><th>Šarže</th><th>Spotřeba do</th><th>Přijato</th><th>Stav</th></tr></thead><tbody>
        ${i.batches.map(b => `<tr>
          <td><code>${esc(b.sarze)}</code></td>
          <td>${b.datum_spotreby ? dcz(b.datum_spotreby) : '<span style="color:var(--text-3)">—</span>'}</td>
          <td>${b.kdy ? dcz(String(b.kdy).slice(0, 10)) : '—'}</td>
          <td>${(+b.hold) ? '<span style="background:#FCEBEB;color:#A32D2D;border-radius:6px;padding:1px 6px;font-size:10px;font-weight:700">🔒 karanténa</span> ' : ''}${(+b.expired) ? '<span style="background:#FDECEA;color:#B25E00;border-radius:6px;padding:1px 6px;font-size:10px;font-weight:700">⏰ prošlá</span>' : ((+b.hold) ? '' : '<span style="color:#208438">ok</span>')}</td>
        </tr>`).join('')}</tbody></table></div>`
        : '<div style="font-size:12px;color:var(--text-3)">Žádná evidovaná šarže k datu (surovina bez šarží nebo přijatá až po datu).</div>'}
    </div>`).join('');
  box.innerHTML = head + ing;
};

// 🗑️ v3.0.457 — ODPIS ZTRÁTY (výdej s kategorií důvodu). Volitelné; kdo nechce, používá běžný výdej.
window.skladZtrata = async function(surovinaId, sarze, reasonHint) {
  let list = state._suroviny_full_cache;
  if (!list) { try { list = await api('admin_suroviny.php'); state._suroviny_full_cache = list; } catch (e) { list = []; } }
  const opts = (list || []).map(s => `<option value="${s.id}" ${(+s.id === +surovinaId) ? 'selected' : ''}>${esc(s.nazev)}</option>`).join('');
  const reasons = [['expirace', '⏰ Expirace'], ['poskozeni', '💥 Poškození'], ['manko', '📉 Manko'], ['jine', '• Jiné']];
  const ropts = reasons.map(r => `<option value="${r[0]}" ${r[0] === (reasonHint || 'expirace') ? 'selected' : ''}>${r[1]}</option>`).join('');
  const L = 'font-size:12px;font-weight:600;color:var(--text-2)';
  const body = `
    <div style="display:grid;grid-template-columns:2fr 1fr;gap:12px;align-items:end">
      <label style="${L}">Surovina<select id="zt-sur" class="form-input" style="margin-top:4px">${opts}</select></label>
      <label style="${L}">Množství (odepsat)<input id="zt-mn" type="number" step="0.001" min="0" class="form-input" style="margin-top:4px" placeholder="0"></label>
    </div>
    <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;margin-top:10px">
      <label style="${L}">Důvod<select id="zt-duvod" class="form-input" style="margin-top:4px">${ropts}</select></label>
      <label style="${L}">Šarže <span style="font-weight:400;color:var(--text-3)">(volitelné)</span><input id="zt-sz" class="form-input" style="margin-top:4px" value="${esc(sarze || '')}"></label>
    </div>
    <label style="display:block;margin-top:10px;${L}">Poznámka<input id="zt-pozn" class="form-input" style="margin-top:4px" placeholder="volitelné"></label>
    <div id="zt-msg" style="margin-top:10px"></div>
    <div style="margin-top:14px;text-align:right"><button class="btn-primary" onclick="_ztataSave()">🗑️ Odepsat jako ztrátu</button></div>`;
  openModal('🗑️ Odpis / ztráta suroviny', body);
};
window._ztataSave = async function() {
  const sid = document.getElementById('zt-sur')?.value;
  const mn = parseFloat(String(document.getElementById('zt-mn')?.value || '').replace(',', '.'));
  const duvod = document.getElementById('zt-duvod')?.value;
  const sarze = document.getElementById('zt-sz')?.value || '';
  const poznamka = document.getElementById('zt-pozn')?.value || '';
  const msg = document.getElementById('zt-msg');
  if (!sid || !(mn > 0)) { if (msg) msg.innerHTML = '<div style="color:#B91C1C;font-size:13px">Vyber surovinu a zadej množství > 0.</div>'; return; }
  try {
    const r = await api('admin_suroviny.php?action=sklad_ztrata', { method: 'POST', body: JSON.stringify({ surovina_id: +sid, mnozstvi: mn, duvod, sarze, poznamka }) });
    if (typeof toast === 'function') toast('🗑️ Odepsáno jako ztráta' + (r.hodnota != null ? ' (' + r.hodnota + ' Kč)' : ''), 'info');
    state._suroviny_cache = null; state._suroviny_full_cache = null;
    closeModal(); renderSuroviny();
  } catch (e) { if (msg) msg.innerHTML = '<div style="color:#B91C1C;font-size:13px">Chyba: ' + esc(e.message) + '</div>'; }
};

// 📉 v3.0.457 — REPORT ZTRÁT za období (rozpad dle důvodu + hodnota Kč).
window.skladZtratyReport = async function() {
  const to = new Date().toISOString().slice(0, 10);
  const from = new Date(Date.now() - 30 * 864e5).toISOString().slice(0, 10);
  const L = 'font-size:12px;font-weight:600;color:var(--text-2)';
  const body = `
    <div style="display:grid;grid-template-columns:1fr 1fr auto;gap:12px;align-items:end">
      <label style="${L}">Od<input id="zr-from" type="date" class="form-input" style="margin-top:4px" value="${from}"></label>
      <label style="${L}">Do<input id="zr-to" type="date" class="form-input" style="margin-top:4px" value="${to}"></label>
      <button class="btn-primary" onclick="_ztratyReportRun()">📉 Zobrazit</button>
    </div>
    <div id="zr-res" style="margin-top:16px"></div>`;
  openModal('📉 Report ztrát', body, 'wide');
  setTimeout(_ztratyReportRun, 80);
};
window._ztratyReportRun = async function() {
  const from = document.getElementById('zr-from')?.value, to = document.getElementById('zr-to')?.value;
  const box = document.getElementById('zr-res');
  if (box) box.innerHTML = '<div style="padding:20px;text-align:center;color:var(--text-3)">Načítám…</div>';
  let r;
  try { r = await api(`admin_suroviny.php?action=ztraty_report&from=${from}&to=${to}`); }
  catch (e) { if (box) box.innerHTML = '<div style="color:#B91C1C">Chyba: ' + esc(e.message) + '</div>'; return; }
  const lbl = { expirace: '⏰ Expirace', poskozeni: '💥 Poškození', manko: '📉 Manko', jine: '• Jiné' };
  const dcz = (s) => (s ? String(s).split('-').reverse().join('.') : '');
  const kc = (n) => (Math.round((+n || 0) * 100) / 100).toLocaleString('cs-CZ');
  if (!r.polozky.length) { box.innerHTML = `<div class="empty-state" style="padding:16px">Za období ${dcz(r.from)}–${dcz(r.to)} žádné evidované ztráty (s kategorií důvodu).</div>`; return; }
  const cards = r.breakdown.map(b => `<div style="background:var(--surface-2);border-radius:10px;padding:12px 14px">
      <div style="font-size:12px;color:var(--text-3)">${lbl[b.duvod] || b.duvod}</div>
      <div style="font-size:18px;font-weight:800">${kc(b.hodnota)} Kč</div>
      <div style="font-size:11px;color:var(--text-3)">${b.pocet}× · ${parseFloat(b.mnozstvi_celkem)} j.</div></div>`).join('');
  const list = r.polozky.map(p => `<tr>
      <td style="white-space:nowrap">${dcz(String(p.kdy).slice(0, 10))}</td>
      <td>${esc(p.nazev)}${p.sarze ? ` <code style="font-size:10px">${esc(p.sarze)}</code>` : ''}</td>
      <td class="num">${parseFloat(p.mnozstvi)} ${esc(p.jednotka || '')}</td>
      <td>${lbl[p.duvod] || p.duvod}</td>
      <td class="num">${kc(p.hodnota)} Kč</td></tr>`).join('');
  box.innerHTML = `
    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:10px;flex-wrap:wrap;gap:8px">
      <div style="font-size:13px;color:var(--text-2)">Období <strong>${dcz(r.from)} – ${dcz(r.to)}</strong></div>
      <div style="font-size:16px;font-weight:800">Ztráty celkem: ${kc(r.total_hodnota)} Kč</div></div>
    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(140px,1fr));gap:10px;margin-bottom:14px">${cards}</div>
    <div style="max-height:300px;overflow:auto;border:1px solid var(--border);border-radius:8px"><table class="table" style="margin:0;font-size:12.5px">
      <thead><tr><th>Datum</th><th>Surovina</th><th class="num">Množství</th><th>Důvod</th><th class="num">Hodnota</th></tr></thead>
      <tbody>${list}</tbody></table></div>`;
};

async function renderSuroviny() {
  // 🚀 PERFORMANCE: cachuj výsledek (invalidate po editaci) — pro 1000+ surovin
  // ušetří 200-500 ms na každý re-render filtrů.
  let list = state._suroviny_full_cache;
  if (!list) {
    list = await api('admin_suroviny.php');
    state._suroviny_full_cache = list;
  }
  // 🆕 v3.0.446 — HACCP sledovatelnost (volitelné hlídání): když zapnuto, načti šarže + expiraci
  const sledOn = !!(state.nastaveni && state.nastaveni.sklad_sledovatelnost === '1');
  let sledData = null;
  if (sledOn) { try { sledData = await api('admin_suroviny.php?action=sarze_prehled&dny=30'); } catch (e) {} }
  const c = document.getElementById('content');

  // Filtry
  const q = (state._suroviny_q || '').trim().toLowerCase();
  const kat = state._suroviny_kat || 'vse';
  const aktivni = state._suroviny_aktivni || 'vse';   // vse | aktivni | skryte
  const alergen = state._suroviny_alergen || 'vse';   // vse | s | bez
  const groupBy = state._suroviny_group !== false;    // default true
  const cenaJedUnit = state._suroviny_cena_jed || 'kg'; // 🆕 v3.0.148 — Cena za jed. (hmotnost): kg | g
  const cenaJedUnitVol = state._suroviny_cena_jed_vol || 'l'; // 🆕 v3.0.149 — Cena za jed. (objem): l | ml

  // Aplikuj filtry
  let filtered = list.map(s => ({ ...s, _kat: kategoriziujSurovinu(s) }));
  if (q) filtered = filtered.filter(s =>
    (s.nazev || '').toLowerCase().includes(q) ||
    (s.alergen || '').toLowerCase().includes(q) ||
    (s.slozeni || '').toLowerCase().includes(q));
  if (kat !== 'vse') filtered = filtered.filter(s => s._kat === kat);
  if (aktivni === 'aktivni') filtered = filtered.filter(s => !!s.aktivni);
  if (aktivni === 'skryte')  filtered = filtered.filter(s => !s.aktivni);
  if (alergen === 's')   filtered = filtered.filter(s => s.alergen && s.alergen.trim());
  if (alergen === 'bez') filtered = filtered.filter(s => !s.alergen || !s.alergen.trim());
  // 📦 Pod minimální hladinou
  if (state._suroviny_pod_minimem) {
    filtered = filtered.filter(s => {
      const min = parseFloat(s.stock_minimalni);
      const akt = parseFloat(s.stock_aktualni) || 0;
      return !isNaN(min) && akt <= min;
    });
  }
  // Spočítej kolik je pod minimem (pro badge)
  const podMinimem = list.filter(s => {
    const min = parseFloat(s.stock_minimalni);
    const akt = parseFloat(s.stock_aktualni) || 0;
    return !isNaN(min) && akt <= min;
  }).length;

  // Spočítej kategorie pro tabs
  const pocty = {};
  list.forEach(s => {
    const k = kategoriziujSurovinu(s);
    pocty[k] = (pocty[k] || 0) + 1;
  });

  // Seřaď abecedně
  filtered.sort((a, b) => (a.nazev || '').localeCompare(b.nazev || '', 'cs'));

  // 🆕 v3.0.182 — ulož aktuálně filtrovaný seznam pro Export CSV (respektuje kategorii + hledání)
  state._suroviny_filtered_export = filtered;

  // Skupiny podle kategorie (pro group view)
  const skupiny = {};
  SUROVINA_KATEGORIE.forEach(k => skupiny[k.key] = []);
  filtered.forEach(s => skupiny[s._kat].push(s));

  // Helper pro vykreslení jedné suroviny v desktopové tabulce (řádek)
  const radekDesktop = (s) => {
    const cb = parseFloat(s.cena_baleni) || 0;
    const ob = parseFloat(s.obsah_baleni) || 0;
    const cenaJed = (cb > 0 && ob > 0) ? (cb / ob) : 0;
    // 🆕 v3.0.148 — normalizuj Cena za jed. na zvolené zobrazení (kg/g) u hmotnostních surovin
    const _jed = (s.jednotka || 'g').toLowerCase();
    let cenaDisp = cenaJed, jedDisp = _jed;
    if (cenaJed > 0 && (_jed === 'g' || _jed === 'kg')) {
      const perG = _jed === 'kg' ? cenaJed / 1000 : cenaJed;
      if (cenaJedUnit === 'kg') { cenaDisp = perG * 1000; jedDisp = 'kg'; }
      else { cenaDisp = perG; jedDisp = 'g'; }
    } else if (cenaJed > 0 && (_jed === 'ml' || _jed === 'l')) {
      // 🆕 v3.0.149 — objemové suroviny: ml ↔ L podle přepínače
      const perMl = _jed === 'l' ? cenaJed / 1000 : cenaJed;
      if (cenaJedUnitVol === 'l') { cenaDisp = perMl * 1000; jedDisp = 'l'; }
      else { cenaDisp = perMl; jedDisp = 'ml'; }
    }
    const cenaTxt = cenaDisp.toFixed((jedDisp === 'g' || jedDisp === 'ml') ? 4 : 2).replace(/\.?0+$/, '').replace('.', ',');
    return `
      <tr class="row-clickable" onclick="editSurovina(${s.id})" ${!s.aktivni ? 'style="opacity:0.5"' : ''}>
        <td>
          <strong>${esc(s.nazev)}</strong>
          ${s.slozeni ? `<span title="Kompozitní surovina — má vlastní složení: ${esc(s.slozeni)}" style="margin-left:6px;color:#7c3aed;font-size:13px;cursor:help">🧬</span>` : ''}
          ${s.poznamka ? `<div style="color:var(--text-3);font-size:12px">${esc(s.poznamka)}</div>` : ''}
          ${s.slozeni ? `<div style="color:var(--text-3);font-size:11px;margin-top:2px;font-style:italic">${esc(s.slozeni.length > 80 ? s.slozeni.slice(0, 80) + '…' : s.slozeni)}</div>` : ''}
        </td>
        <td>${esc(s.jednotka || 'g')}</td>
        <td>
          ${s.alergen ? `<span style="background:#fef3c7;color:#92400e;font-size:11px;padding:2px 8px;border-radius:10px;font-weight:600">${esc(s.alergen)}</span>` : ''}
          ${s.slozeni_alergeny && s.slozeni_alergeny !== s.alergen ? `<div style="margin-top:3px"><span title="Alergeny detekované ze složení" style="background:#ede9fe;color:#5b21b6;font-size:10px;padding:2px 7px;border-radius:8px;font-weight:500">🔬 ${esc(s.slozeni_alergeny.length > 40 ? s.slozeni_alergeny.slice(0, 40) + '…' : s.slozeni_alergeny)}</span></div>` : ''}
          ${!s.alergen && !s.slozeni_alergeny ? '<span style="color:var(--text-3)">—</span>' : ''}
        </td>
        <td class="num" style="font-variant-numeric:tabular-nums">${cenaJed > 0 ? `<strong>${cenaTxt}</strong> Kč/${jedDisp}` : '<span style="color:var(--text-3)">—</span>'}</td>
        <td class="num" style="font-variant-numeric:tabular-nums">${stockBadge(s)}</td>
        <td class="num">${s.pocet_vyrobku || 0}×</td>
        <td>${s.aktivni ? '<span class="status dorucena">Aktivní</span>' : '<span class="status zrusena">Skrytá</span>'}</td>
        <td onclick="event.stopPropagation();">
          <button class="btn-secondary" style="font-size:12px;padding:6px 10px;margin-right:4px" onclick="surSkladModal(${s.id})" title="Příjem / výdej / inventura">📦</button>
          <button class="btn-secondary" style="font-size:12px;padding:6px 10px" onclick="editSurovina(${s.id})">Upravit</button>
        </td>
      </tr>
    `;
  };

  // 📦 Stock badge — zobrazí aktuální zásobu s barevným varovaním
  const stockBadge = (s) => {
    const akt = parseFloat(s.stock_aktualni) || 0;
    const min = s.stock_minimalni !== null && s.stock_minimalni !== undefined ? parseFloat(s.stock_minimalni) : null;
    const jed = esc(s.jednotka || 'g');
    const aktTxt = akt.toFixed(akt >= 100 ? 0 : 2).replace(/\.?0+$/, '').replace('.', ',');
    if (min !== null && akt <= min) {
      return `<span style="background:#FEE2E2;color:#991B1B;font-weight:700;padding:3px 10px;border-radius:8px;font-size:12px" title="⚠ Pod minimem (${min} ${jed})">⚠ ${aktTxt} ${jed}</span>`;
    }
    if (akt === 0) {
      return `<span style="color:var(--text-3)">—</span>`;
    }
    return `<strong>${aktTxt}</strong> ${jed}`;
  };

  // Vykreslení tabulky — buď s kategoriemi nebo bez
  // 🆕 v3.0.458 — stránkování seznamu surovin dle nastavení (pagination_styl/pocet), klientské nad `filtered`.
  //   Grupovaný pohled (dle kategorie) je z paginace vyňatý (potřebuje plný dataset), stejně jako u Výrobků.
  if (state._pagStyl == null || state._pagLimit == null) {
    const n = state.nastaveni || {};
    state._pagStyl = n.pagination_styl || 'load_more';
    const poc = parseInt(n.pagination_pocet) || 10;
    state._pagLimit = [10, 25, 50, 100, 200].includes(poc) ? poc : 10;
  }
  const surPaginated = (!groupBy || kat !== 'vse');
  const sp = state._surPag || (state._surPag = { offset: 0, shown: 0 });
  if (typeof applyPagLimit === 'function') applyPagLimit(sp); else sp.limit = state._pagLimit || 10;
  const spSig = JSON.stringify([q, kat, aktivni, alergen, groupBy]);
  if (sp.sig !== spSig) { sp.sig = spSig; sp.offset = 0; sp.shown = 0; }
  let surPageItems = filtered;
  if (surPaginated) {
    const lim = sp.limit || 10;
    if ((state._pagStyl || 'load_more') === 'stranky') {
      if (sp.offset >= filtered.length) sp.offset = 0;
      surPageItems = filtered.slice(sp.offset, sp.offset + lim);
    } else {
      sp.offset = 0;
      if (!sp.shown) sp.shown = lim;
      surPageItems = filtered.slice(0, sp.shown);
    }
  }
  sp.items = surPageItems; sp.total = filtered.length;

  const tabulkaDesktop = () => {
    if (filtered.length === 0) return '<div class="empty-state">Žádné suroviny odpovídající filtru</div>';
    const head = `
      <thead>
        <tr>
          <th>Název</th>
          <th>Jednotka</th>
          <th>Alergen</th>
          <th class="num">Cena za jed.</th>
          <th class="num">📦 Skladem</th>
          <th class="num">Použito</th>
          <th>Stav</th>
          <th></th>
        </tr>
      </thead>
    `;
    if (!groupBy || kat !== 'vse') {
      return `<table class="table sur-table">${head}<tbody>${surPageItems.map(radekDesktop).join('')}</tbody></table>`;
    }
    // Groupovaný view — pro každou neprázdnou kategorii nadpis + řádky
    return SUROVINA_KATEGORIE.map(k => {
      const items = skupiny[k.key];
      if (items.length === 0) return '';
      return `
        <h3 style="margin:24px 0 10px;font-size:18px;font-weight:700;color:var(--text);display:flex;align-items:center;gap:12px;border-bottom:2px solid var(--border-2);padding-bottom:8px">
          <span style="font-size:32px;line-height:1">${k.icon}</span>
          <span>${k.label}</span>
          <span style="font-size:13px;font-weight:600;color:var(--text-3);background:var(--surface-2);padding:4px 12px;border-radius:12px">${items.length}</span>
        </h3>
        <table class="table sur-table" style="margin-bottom:12px">${head}<tbody>${items.map(radekDesktop).join('')}</tbody></table>
      `;
    }).join('');
  };

  // Mobilní karta
  // 🆕 mobil: 3-sloupcový tile grid — ikona (kategorie) + skladem nahoře, název dole (2 řádky, uniformní výška)
  const _katIkona = (s) => { const k = (typeof SUROVINA_KATEGORIE !== 'undefined') && SUROVINA_KATEGORIE.find(x => x.key === s._kat); return k ? k.icon : '📦'; };
  const _skladShort = (s) => {
    const akt = parseFloat(s.stock_aktualni) || 0;
    if (akt <= 0) return '—';
    const t = akt.toFixed(akt >= 100 ? 0 : 1).replace(/\.?0+$/, '').replace('.', ',');
    return t + ' ' + esc(s.jednotka || 'g');
  };
  const _podMin = (s) => { const min = parseFloat(s.stock_minimalni); const akt = parseFloat(s.stock_aktualni) || 0; return !isNaN(min) && akt <= min; };
  const kartaMobile = (s) => `
    <button type="button" class="sur-tile" onclick="editSurovina(${s.id})" ${!s.aktivni ? 'style="opacity:.5"' : ''}>
      <span class="sur-tile-top">
        <span class="sur-tile-ico">${_katIkona(s)}</span>
        <span class="sur-tile-num${_podMin(s) ? ' low' : ''}">${_skladShort(s)}</span>
      </span>
      <span class="sur-tile-name">${esc(s.nazev)}</span>
    </button>
  `;

  const seznamMobile = () => {
    if (filtered.length === 0) return '<div class="empty-state">Žádné suroviny odpovídající filtru</div>';
    if (!groupBy || kat !== 'vse') return `<div class="sur-grid">${surPageItems.map(kartaMobile).join('')}</div>`;
    return SUROVINA_KATEGORIE.map(k => {
      const items = skupiny[k.key];
      if (items.length === 0) return '';
      return `
        <h3 style="margin:18px 0 8px;font-size:16px;font-weight:700;color:var(--text);display:flex;align-items:center;gap:10px;padding-bottom:6px;border-bottom:2px solid var(--border-2)">
          <span style="font-size:28px;line-height:1">${k.icon}</span>
          <span>${k.label}</span>
          <span style="font-size:12px;font-weight:600;color:var(--text-3);background:var(--surface-2);padding:2px 10px;border-radius:10px">${items.length}</span>
        </h3>
        <div class="sur-grid">${items.map(kartaMobile).join('')}</div>
      `;
    }).join('');
  };

  c.innerHTML = `
    <div class="page-head">
      <div>
        <h1 class="page-title">🌾 Suroviny</h1>
        <p class="page-sub">${filtered.length} z ${list.length} surovin</p>
      </div>
      <div style="display:flex;gap:8px;flex-wrap:wrap">
        <button class="btn-secondary" onclick="navigate('vyroba')">← Výroba</button>
        <button class="btn-secondary" onclick="otevritKategorieSurovin()" title="Spravovat kategorie surovin (přidat, upravit, ikony)">📂 Kategorie</button>
        <button class="btn-secondary" onclick="otevritMatchSlozeni()" title="Projde složení výrobků a napáruje na suroviny">🔗 Spárovat</button>
        <button class="btn-secondary" onclick="openImportCenik('suroviny')" title="Import ceníku z Excel/CSV s auto-matchingem">📊 Import ceníku</button>
        <button class="btn-secondary" onclick="exportSurovinyCsv()" title="Export zobrazených surovin do CSV — seskupeno dle kategorie (respektuje filtr/kategorii)">📤 Export CSV</button>
        <button class="btn-secondary" onclick="otevritImportSurovin()" title="Hromadný import — základní balíček nebo CSV">📥 JSON / vzorky</button>
        <button class="btn-primary btn-green btn-big-action" onclick="editSurovina()" style="font-size:18px !important;font-weight:800 !important;padding:18px 32px !important;min-height:64px !important;border-radius:12px !important;letter-spacing:0.3px !important">+ Nová surovina</button>
      </div>
    </div>

    <!-- 🔎 v3.0.446 — HACCP sledovatelnost šarží & expirace (volitelné hlídání) -->
    <div class="card-block" style="margin-bottom:16px">
      <div style="display:flex;align-items:center;gap:12px;flex-wrap:wrap">
        <h3 style="margin:0;font-size:16px">🔎 Sledovatelnost šarží &amp; expirace <small style="font-weight:400;color:var(--text-3);font-size:12px">— HACCP, volitelné</small></h3>
        <label style="margin-left:auto;display:inline-flex;align-items:center;gap:8px;cursor:pointer;font-size:13px;font-weight:600;padding:6px 12px;border-radius:8px;background:var(--surface-2)">
          <input type="checkbox" ${sledOn ? 'checked' : ''} onchange="skladSledovatelnostToggle(this.checked)" style="width:18px;height:18px;cursor:pointer">
          ${sledOn ? 'Hlídání zapnuto' : 'Hlídání vypnuto'}
        </label>
      </div>
      ${!sledOn ? `
        <p style="font-size:12.5px;color:var(--text-3);margin:10px 0 0;line-height:1.6">Vypnuto — u příjmu se nezobrazuje šarže / datum spotřeby a nechodí žádná upozornění. Zapni pro HACCP sledovatelnost (nařízení 178/2002 „krok zpět"): u příjmu zadáš šarži (LOT) + datum spotřeby, systém upozorní na blížící se expiraci a umožní šarži dohledat.</p>
      ` : `
        <div style="margin-top:12px">
          ${sledData && sledData.drzenych > 0 ? `
            <div style="background:#FCEBEB;border:1px solid #F0B4B4;border-radius:8px;padding:10px 14px;margin-bottom:12px;font-size:13px;color:#8A1F1F">
              🔒 <strong>${sledData.drzenych}</strong> ${sledData.drzenych === 1 ? 'šarže je' : 'šarží je'} v karanténě (blokováno). ${sledData.enforce === 'off' ? 'Kontrola ve výrobě je vypnutá — při odpisu se neupozorní.' : ''}
            </div>` : ''}
          <div style="display:flex;gap:12px;flex-wrap:wrap;align-items:center;margin-bottom:12px">
            <button class="btn-secondary btn-mini" onclick="skladRecall(0, '')" title="Recall: vyber surovinu (a šarži) a zjisti, které objednávky/zákazníci ji dostali">🔎 Dohledat zasažené objednávky (recall)</button>
            <button class="btn-secondary btn-mini" onclick="skladTraceBack(0, '')" title="Zpětně: vyber výrobek (reklamace) a datum → které šarže surovin byly k tomu datu na skladě">🔙 Zpětně: výrobek → šarže surovin</button>
            <button class="btn-secondary btn-mini" onclick="skladZtratyReport()" title="Přehled odepsaných ztrát za období (expirace/poškození/manko) + hodnota v Kč">📉 Report ztrát</button>
            <label style="display:inline-flex;align-items:center;gap:6px;font-size:12px;color:var(--text-2)" title="Kontrola prošlých / držených šarží při denním odpisu výroby. Vypnuto = nikdy neobtěžuje. Upozornit = jen varuje. Blokovat = vyžádá potvrzení (jde přeskočit).">
              Kontrola ve výrobě:
              <select class="form-input" style="width:auto;padding:5px 8px;font-size:12px" onchange="skladEnforceSet(this.value)">
                <option value="off" ${!sledData || !sledData.enforce || sledData.enforce === 'off' ? 'selected' : ''}>⚪ Vypnuto</option>
                <option value="warn" ${sledData && sledData.enforce === 'warn' ? 'selected' : ''}>🟡 Upozornit</option>
                <option value="block" ${sledData && sledData.enforce === 'block' ? 'selected' : ''}>🔴 Blokovat</option>
              </select>
            </label>
          </div>
          ${sledData && sledData.expirujici > 0 ? `
            <div style="background:#FBEDDC;border:1px solid #EBC79A;border-radius:8px;padding:10px 14px;margin-bottom:12px;font-size:13px;color:#7A4A00">
              📅 <strong>${sledData.expirujici}</strong> ${sledData.expirujici === 1 ? 'šarže má' : 'šarží má'} datum spotřeby do ${sledData.dny} dní nebo už prošlé — zkontroluj.
            </div>` : (sledData ? `<div style="font-size:12.5px;color:var(--text-3);margin-bottom:10px">✓ Žádná šarže se neblíží expiraci (do ${sledData.dny} dní).</div>` : '')}
          ${sledData && sledData.polozky && sledData.polozky.length ? `
            <div style="max-height:280px;overflow:auto;border:1px solid var(--border);border-radius:8px">
              <table class="table" style="margin:0;font-size:12.5px">
                <thead><tr><th>Surovina</th><th>Šarže / LOT</th><th>Datum spotřeby</th><th class="num">Do expirace</th><th class="num">Přijato</th><th>Kdy</th><th></th></tr></thead>
                <tbody>
                  ${sledData.polozky.map(r => {
                    const d = r.dni_do_expirace;
                    const barva = (r.datum_spotreby === null) ? 'var(--text-3)' : (d < 0 ? '#B91C1C' : (d <= sledData.dny ? '#B25E00' : 'var(--text-2)'));
                    const txt = (r.datum_spotreby === null) ? '—' : (d < 0 ? `prošlo ${-d} d` : `za ${d} d`);
                    return `<tr>
                      <td>${esc(r.surovina_nazev)}</td>
                      <td>${r.sarze ? esc(r.sarze) : '<span style="color:var(--text-3)">—</span>'}${(+r.hold) ? ` <span title="Šarže je v karanténě${r.hold_poznamka ? ': ' + esc(r.hold_poznamka) : ''}" style="background:#FCEBEB;color:#A32D2D;border-radius:6px;padding:1px 6px;font-size:10px;font-weight:700">🔒 KARANTÉNA</span>` : ''}</td>
                      <td>${r.datum_spotreby ? esc(String(r.datum_spotreby).split('-').reverse().join('.')) : '<span style="color:var(--text-3)">—</span>'}</td>
                      <td class="num" style="color:${barva};font-weight:600">${txt}</td>
                      <td class="num">${parseFloat(r.mnozstvi)} ${esc(r.jednotka || '')}</td>
                      <td style="font-size:11px;color:var(--text-3)">${fmtDate(r.kdy)}${r.typ === 'vratka' ? ' ↩️' : ''}</td>
                      <td style="white-space:nowrap">${r.sarze ? `<button class="btn-mini" data-sz="${esc(r.sarze)}" onclick="skladSarzeHold(${r.surovina_id}, this.dataset.sz, ${(+r.hold) ? 0 : 1})" title="${(+r.hold) ? 'Uvolnit šarži z karantény' : 'Dát šarži do karantény — označí ji jako blokovanou'}">${(+r.hold) ? '✅ Uvolnit' : '🔒 Blokovat'}</button> ` : ''}<button class="btn-mini" data-sz="${esc(r.sarze || '')}" onclick="skladZtrata(${r.surovina_id}, this.dataset.sz, '${d < 0 ? 'expirace' : 'jine'}')" title="Odepsat tuto surovinu / šarži jako ztrátu (expirace, poškození…)">🗑️ Ztráta</button> <button class="btn-mini" data-sz="${esc(r.sarze || '')}" onclick="skladRecall(${r.surovina_id}, this.dataset.sz)" title="Dohledat, které objednávky/zákazníci dostali výrobky s touto šarží (recall)">🔎 Dohledat</button></td>
                    </tr>`;
                  }).join('')}
                </tbody>
              </table>
            </div>
            <p style="font-size:11px;color:var(--text-3);margin:8px 0 0">Rejstřík přijatých šarží (posl. 300). „Krok zpět" = dohledání dodávky suroviny. Šarži / expiraci zadáváš u 📦 Pohyb → Příjem.</p>
          ` : (sledData ? `<div class="empty-state" style="padding:16px;font-size:13px">Zatím žádné šarže — zadej je u příjmu suroviny (📦 Pohyb → Příjem).</div>` : '')}
        </div>
      `}
    </div>

    <!-- Filtry -->
    <div class="card-block sur-filtry" style="margin-bottom:16px">
      <div style="display:grid;grid-template-columns:2fr 1fr 1fr auto;gap:12px;align-items:stretch" class="sur-filter-grid">
        <input class="form-input" type="search" id="sf-q" placeholder="🔍 Hledat (název, alergen, složení)..." value="${esc(q)}" oninput="state._suroviny_q=this.value;debounce('sf-q', renderSuroviny, 220)">
        <select class="form-input" onchange="state._suroviny_aktivni=this.value;renderSuroviny()">
          <option value="vse"     ${aktivni === 'vse'     ? 'selected' : ''}>Stav: vše</option>
          <option value="aktivni" ${aktivni === 'aktivni' ? 'selected' : ''}>✓ Jen aktivní</option>
          <option value="skryte"  ${aktivni === 'skryte'  ? 'selected' : ''}>○ Jen skryté</option>
        </select>
        <select class="form-input" onchange="state._suroviny_alergen=this.value;renderSuroviny()">
          <option value="vse" ${alergen === 'vse' ? 'selected' : ''}>Alergeny: vše</option>
          <option value="s"   ${alergen === 's'   ? 'selected' : ''}>⚠ S alergeny</option>
          <option value="bez" ${alergen === 'bez' ? 'selected' : ''}>✓ Bez alergenů</option>
        </select>
        <label class="sur-group-toggle" style="display:flex;align-items:center;gap:8px;font-weight:600;white-space:nowrap;cursor:pointer">
          <input type="checkbox" ${groupBy ? 'checked' : ''} onchange="state._suroviny_group=this.checked;renderSuroviny()" style="width:18px;height:18px;cursor:pointer;accent-color:var(--primary)">
          Roztřídit
        </label>
      </div>

      <!-- 🆕 v3.0.148 — Cena za jednotku: přepínač kg/g (zobrazení sloupce Cena za jed.) -->
      <div style="margin-top:14px;display:flex;align-items:center;gap:10px;flex-wrap:wrap">
        <span style="font-size:15px;font-weight:600;color:var(--text-2)">💰 Cena za jednotku:</span>
        <div class="sur-unit-toggle" role="group" aria-label="Jednotka ceny (hmotnost)" title="Hmotnostní suroviny (g/kg)">
          <button type="button" class="${cenaJedUnit === 'kg' ? 'active' : ''}" onclick="if(state._suroviny_cena_jed!=='kg'){state._suroviny_cena_jed='kg';renderSuroviny()}">Kč/kg</button>
          <button type="button" class="${cenaJedUnit === 'g' ? 'active' : ''}" onclick="if(state._suroviny_cena_jed!=='g'){state._suroviny_cena_jed='g';renderSuroviny()}">Kč/g</button>
        </div>
        <div class="sur-unit-toggle" role="group" aria-label="Jednotka ceny (objem)" title="Tekuté suroviny (ml/L)">
          <button type="button" class="${cenaJedUnitVol === 'l' ? 'active' : ''}" onclick="if(state._suroviny_cena_jed_vol!=='l'){state._suroviny_cena_jed_vol='l';renderSuroviny()}">Kč/L</button>
          <button type="button" class="${cenaJedUnitVol === 'ml' ? 'active' : ''}" onclick="if(state._suroviny_cena_jed_vol!=='ml'){state._suroviny_cena_jed_vol='ml';renderSuroviny()}">Kč/ml</button>
        </div>
      </div>

      ${podMinimem > 0 ? `
        <div style="margin-top:14px;display:flex;align-items:center;gap:10px;flex-wrap:wrap">
          <label style="display:inline-flex;align-items:center;gap:8px;background:${state._suroviny_pod_minimem ? '#FEE2E2' : 'var(--surface-2)'};padding:8px 14px;border-radius:8px;cursor:pointer;border:1.5px solid ${state._suroviny_pod_minimem ? '#DC2626' : 'var(--border)'};font-weight:600;font-size:14px">
            <input type="checkbox" ${state._suroviny_pod_minimem ? 'checked' : ''} onchange="state._suroviny_pod_minimem=this.checked;renderSuroviny()" style="width:18px;height:18px;cursor:pointer;accent-color:#DC2626">
            ⚠️ Jen pod minimální hladinou
            <span style="background:#DC2626;color:white;padding:2px 8px;border-radius:10px;font-size:12px;font-weight:700;margin-left:4px">${podMinimem}</span>
          </label>
        </div>
      ` : ''}

      <!-- Kategorie — uniformní grid (stejně velká tlačítka; 2 řádky na širokém, responzivně se zúží) -->
      <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(118px,1fr));gap:10px;margin-top:18px">
        <button class="sur-cat-btn ${kat === 'vse' ? 'is-active' : ''}" onclick="state._suroviny_kat='vse';renderSuroviny()">
          <span class="sur-cat-emoji">📚</span>
          <span>Vše</span>
          <span class="sur-cat-count">${list.length}</span>
        </button>
        ${SUROVINA_KATEGORIE.filter(k => (pocty[k.key] || 0) > 0).map(k => `
          <button class="sur-cat-btn ${kat === k.key ? 'is-active' : ''}" onclick="state._suroviny_kat='${k.key}';renderSuroviny()">
            <span class="sur-cat-emoji">${k.icon}</span>
            <span>${esc(k.label)}</span>
            <span class="sur-cat-count">${pocty[k.key]}</span>
          </button>
        `).join('')}
      </div>
    </div>

    <div class="card-block desktop-only-block">
      ${tabulkaDesktop()}
    </div>

    <!-- Mobile -->
    <div class="mobile-only-block">
      <style>
        .sur-grid { display:grid; grid-template-columns:repeat(3,1fr); gap:8px; margin:0 0 12px; }
        .sur-tile { display:flex; flex-direction:column; align-items:center; gap:6px; padding:10px 6px 11px;
          background:var(--surface); border:1px solid var(--border); border-radius:12px; cursor:pointer; text-align:center; font:inherit; color:inherit; }
        .sur-tile:active { background:var(--surface-2); }
        .sur-tile-top { display:flex; align-items:center; justify-content:center; gap:5px; min-height:24px; }
        .sur-tile-ico { font-size:20px; line-height:1; }
        .sur-tile-num { font-size:12.5px; font-weight:700; color:var(--text-2); white-space:nowrap; }
        .sur-tile-num.low { color:var(--danger-text); }
        .sur-tile-name { font-size:12px; line-height:1.25; font-weight:600; color:var(--text-1);
          display:-webkit-box; -webkit-line-clamp:2; -webkit-box-orient:vertical; overflow:hidden;
          min-height:2.5em; word-break:break-word; hyphens:auto; }
      </style>
      ${seznamMobile()}
    </div>

    ${surPaginated && typeof pagControlHtml === 'function' ? pagControlHtml('sur', sp, 'surGoToPage', 'surLoadMore') : ''}
  `;
  if (surPaginated && typeof pagSetupInfinite === 'function') pagSetupInfinite('sur', sp, 'surLoadMore');
}

