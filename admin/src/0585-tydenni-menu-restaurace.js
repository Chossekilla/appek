// =============================================================
// 🗓️ TÝDENNÍ MENU — Restaurace balíček (v3.0.409)
// Menu z VÝROBKŮ (ceny živě), šablony + historie týdnů,
// 📧 rozesílka odběratelům, 🔗 sdílení na sociální sítě.
// =============================================================

const TM_DNY_UI = [
  ['po', 'Pondělí'], ['ut', 'Úterý'], ['st', 'Středa'], ['ct', 'Čtvrtek'],
  ['pa', 'Pátek'], ['so', 'Sobota'], ['ne', 'Neděle'],
];

function tmEmptyDny() {
  const o = {};
  TM_DNY_UI.forEach(([k]) => { o[k] = []; });
  return o;
}

async function renderTydenniMenu() {
  const body = document.getElementById('rest-tab-body');
  if (!body) return;
  body.innerHTML = skeletonCards(2);

  let data;
  try { data = await api('admin_tydenni_menu.php?action=list'); }
  catch (e) { body.innerHTML = `<div class="alert err">${esc(e.message)}</div>`; return; }

  state._tmData = data;
  if (!state._tm) {
    // předvyber: existující týden pro „tento týden", jinak prázdný
    const cur = (data.weeks || []).find(w => w.tyden_od === data.tento_tyden);
    state._tm = {
      id: cur ? cur.id : 0,
      tyden_od: data.tento_tyden,
      poznamka: cur ? (cur.poznamka || '') : '',
      dny: cur ? JSON.parse(JSON.stringify(cur.dny)) : tmEmptyDny(),
    };
  }
  tmRenderBody();
}

function tmRenderBody() {
  const body = document.getElementById('rest-tab-body');
  if (!body) return;
  const d = state._tmData || {};
  const tm = state._tm;
  const vyr = d.vyrobky || [];
  const vyrOpts = vyr.map(v => `<option value="${v.id}">${esc(v.nazev)} · ${fmt(v.cena_s_dph)}</option>`).join('');
  const denBlok = ([k, label], i) => {
    const items = tm.dny[k] || [];
    const datum = new Date(new Date(tm.tyden_od).getTime() + i * 86400000);
    const datS = datum.getDate() + '. ' + (datum.getMonth() + 1) + '.';
    return `
    <div class="card-block" style="padding:12px 14px">
      <h3 style="margin:0 0 8px;font-size:14px;display:flex;justify-content:space-between"><span>${label} <span style="color:var(--text-3);font-weight:400">${datS}</span></span><span style="font-size:11px;color:var(--text-3)">${items.length} jídel</span></h3>
      ${items.map((it, j) => `
        <div style="display:flex;align-items:center;gap:8px;padding:5px 0;border-bottom:1px dashed var(--border);font-size:13px">
          <div style="flex:1;min-width:0">${esc(it.nazev || '#' + it.vyrobek_id)}${it.existuje === false ? ' <span style="color:var(--danger-text);font-size:10px">smazaný výrobek!</span>' : ''}
            <span style="color:var(--text-3);font-size:11px">${it.cena_s_dph != null ? '· ' + fmt(it.cena_s_dph) : ''}</span></div>
          <input placeholder="pozn." value="${esc(it.pozn || '')}" onchange="tmPozn('${k}',${j},this.value)" style="width:110px;font-size:11.5px;padding:4px 6px;border:1px solid var(--border);border-radius:6px">
          <button class="btn-icon" onclick="tmDel('${k}',${j})" title="Odebrat">✕</button>
        </div>`).join('')}
      <div style="display:flex;gap:6px;margin-top:8px">
        <select id="tm-add-${k}" class="form-input" style="flex:1;font-size:12px;padding:6px">${vyrOpts}</select>
        <button class="btn-secondary" style="font-size:12px;padding:6px 10px" onclick="tmAdd('${k}')">➕</button>
      </div>
    </div>`;
  };

  const hist = (d.weeks || []).filter(w => w.id !== tm.id);
  const curWeek = (d.weeks || []).find(w => w.id === tm.id);
  body.innerHTML = `
    <div class="card-block" style="margin-bottom:14px">
      <div style="display:flex;gap:10px;flex-wrap:wrap;align-items:end;justify-content:space-between">
        <div style="display:flex;gap:10px;flex-wrap:wrap;align-items:end">
          <div><label class="form-label" style="font-size:12px">Týden od (pondělí)</label>
            <input type="date" class="form-input" value="${esc(tm.tyden_od)}" onchange="tmSetWeek(this.value)"></div>
          <div style="min-width:220px;flex:1"><label class="form-label" style="font-size:12px">Poznámka (např. „Polévka v ceně")</label>
            <input class="form-input" value="${esc(tm.poznamka || '')}" onchange="state._tm.poznamka=this.value" maxlength="300"></div>
        </div>
        <div style="display:flex;gap:6px;flex-wrap:wrap">
          ${(d.sablony || []).length ? `
            <select id="tm-sab-sel" class="form-input" style="font-size:12px;max-width:170px">
              ${d.sablony.map(s => `<option value="${s.id}">📋 ${esc(s.nazev)}</option>`).join('')}
            </select>
            <button class="btn-icon" onclick="tmSablonaNahled()" title="👁️ Náhled šablony">👁️</button>
            <button class="btn-secondary" style="font-size:12px" onclick="tmSablonaNacti()">⤵️ Načíst</button>
            <button class="btn-icon" onclick="tmSablonaSmaz()" title="Smazat šablonu">🗑️</button>` : ''}
          <button class="btn-secondary" style="font-size:12px" onclick="tmSablonaUloz()">📌 Uložit jako šablonu</button>
          <button class="btn-primary btn-green" onclick="tmSave()">💾 Uložit týden</button>
        </div>
      </div>
      ${tm.id ? `
      <div style="display:flex;gap:8px;flex-wrap:wrap;margin-top:12px;padding-top:10px;border-top:1px solid var(--border)">
        <button class="btn-secondary" onclick="tmTisk()">🖨️ Tisk (A4 k vyvěšení)</button>
        <button class="btn-secondary" onclick="tmRozeslat()">📧 Rozeslat odběratelům</button>
        <button class="btn-secondary" onclick="tmSdilet()">🔗 Sdílet (web / sociální sítě)</button>
        ${curWeek && curWeek.rozeslano_at ? `<span style="font-size:11.5px;color:var(--success-text);align-self:center">✓ rozesláno ${esc(curWeek.rozeslano_at)}</span>` : ''}
      </div>` : '<div style="font-size:11.5px;color:var(--text-3);margin-top:10px">Po uložení týdne se objeví rozesílka a sdílení.</div>'}
    </div>

    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(280px,1fr));gap:12px">
      ${TM_DNY_UI.map(denBlok).join('')}
    </div>

    ${hist.length ? `
    <div class="card-block" style="margin-top:14px">
      <h3 style="margin:0 0 8px;font-size:14px">🕘 Historie týdnů</h3>
      <div style="display:flex;gap:8px;flex-wrap:wrap">
        ${hist.map(w => `
          <div style="border:1px solid var(--border);border-radius:10px;padding:8px 12px;font-size:12.5px;display:flex;gap:8px;align-items:center">
            <span><strong>${esc(w.tyden_od)}</strong> · ${Object.values(w.dny).reduce((a, x) => a + x.length, 0)} jídel${w.rozeslano_at ? ' · 📧' : ''}</span>
            <button class="btn-icon" onclick="tmOtevri(${w.id})" title="Otevřít / upravit">✏️</button>
            <button class="btn-icon" onclick="tmDuplikuj(${w.id})" title="Zkopírovat do vybraného týdne">📋</button>
            <button class="btn-icon" onclick="tmSmazTyden(${w.id})" title="Smazat">🗑️</button>
          </div>`).join('')}
      </div>
    </div>` : ''}
  `;
}

// ── Editace dnů ───────────────────────────────────────────────
window.tmAdd = function(den) {
  const sel = document.getElementById('tm-add-' + den);
  const vid = parseInt(sel && sel.value, 10);
  if (!vid) return;
  const v = (state._tmData.vyrobky || []).find(x => String(x.id) === String(vid)) || {};
  state._tm.dny[den] = state._tm.dny[den] || [];
  state._tm.dny[den].push({ vyrobek_id: vid, nazev: v.nazev, cena_s_dph: v.cena_s_dph, pozn: '' });
  tmRenderBody();
};
window.tmDel = function(den, i) { state._tm.dny[den].splice(i, 1); tmRenderBody(); };
window.tmPozn = function(den, i, val) { state._tm.dny[den][i].pozn = val; };
window.tmSetWeek = function(date) {
  const monday = (() => { const t = new Date(date); const day = (t.getDay() + 6) % 7; t.setDate(t.getDate() - day); return t.toISOString().slice(0, 10); })();
  const ex = (state._tmData.weeks || []).find(w => w.tyden_od === monday);
  state._tm = ex
    ? { id: ex.id, tyden_od: ex.tyden_od, poznamka: ex.poznamka || '', dny: JSON.parse(JSON.stringify(ex.dny)) }
    : { id: 0, tyden_od: monday, poznamka: '', dny: tmEmptyDny() };
  tmRenderBody();
};
window.tmOtevri = function(id) {
  const w = (state._tmData.weeks || []).find(x => x.id === id);
  if (!w) return;
  state._tm = { id: w.id, tyden_od: w.tyden_od, poznamka: w.poznamka || '', dny: JSON.parse(JSON.stringify(w.dny)) };
  tmRenderBody();
};

// ── Uložení / historie ────────────────────────────────────────
window.tmSave = async function() {
  try {
    const r = await api('admin_tydenni_menu.php?action=save', { method: 'POST', body: JSON.stringify({ tyden_od: state._tm.tyden_od, dny: state._tm.dny, poznamka: state._tm.poznamka || '' }) });
    toastSuccess('Týden uložen');
    state._tm = { id: r.week.id, tyden_od: r.week.tyden_od, poznamka: r.week.poznamka || '', dny: JSON.parse(JSON.stringify(r.week.dny)) };
    const keep = state._tm;
    state._tm = keep;
    // refresh dat (historie/šablony) při zachování rozpracovaného stavu
    try { state._tmData = await api('admin_tydenni_menu.php?action=list'); } catch (e) {}
    tmRenderBody();
  } catch (e) { alert('Chyba: ' + e.message); }
};
window.tmDuplikuj = async function(fromId) {
  try {
    await api('admin_tydenni_menu.php?action=duplicate', { method: 'POST', body: JSON.stringify({ from_id: fromId, tyden_od: state._tm.tyden_od }) });
    toastSuccess('Zkopírováno do týdne ' + state._tm.tyden_od);
    const od = state._tm.tyden_od;
    state._tm = null;
    await renderTydenniMenu();
    tmSetWeek(od);
  } catch (e) { alert('Chyba: ' + e.message); }
};
window.tmSmazTyden = async function(id) {
  if (!(await confirmDialog({ title: 'Smazat týden?', msg: 'Nevratné.', danger: true, okText: 'Smazat' }))) return;
  try {
    await api('admin_tydenni_menu.php?action=delete', { method: 'POST', body: JSON.stringify({ id }) });
    toastSuccess('Týden smazán');
    state._tm = null;
    await renderTydenniMenu();
  } catch (e) { alert('Chyba: ' + e.message); }
};

// ── Šablony ───────────────────────────────────────────────────
window.tmSablonaUloz = async function() {
  const poloz = Object.values(state._tm.dny).reduce((a, x) => a + x.length, 0);
  if (!poloz) return alert('Menu je prázdné.');
  const nazev = prompt('Název šablony (např. „Klasika jaro"):');
  if (!nazev || !nazev.trim()) return;
  try {
    const r = await api('admin_tydenni_menu.php?action=save_sablona', { method: 'POST', body: JSON.stringify({ nazev: nazev.trim(), dny: state._tm.dny }) });
    state._tmData.sablony = r.sablony || [];
    toastSuccess(r.prepsano ? 'Šablona přepsána' : 'Šablona uložena');
    tmRenderBody();
  } catch (e) { alert('Chyba: ' + e.message); }
};
window.tmSablonaNacti = function() {
  const sel = document.getElementById('tm-sab-sel');
  const id = parseInt(sel && sel.value, 10);
  const s = (state._tmData.sablony || []).find(x => (x.id | 0) === id);
  if (!s) return;
  const dny = tmEmptyDny();
  TM_DNY_UI.forEach(([k]) => {
    dny[k] = (s.dny[k] || []).map(r => {
      const v = (state._tmData.vyrobky || []).find(x => String(x.id) === String(r.vyrobek_id)) || {};
      return { vyrobek_id: r.vyrobek_id, nazev: v.nazev, cena_s_dph: v.cena_s_dph, pozn: r.pozn || '' };
    });
  });
  state._tm.dny = dny;
  toastSuccess('Šablona „' + s.nazev + '“ načtena');
  tmRenderBody();
};
// 🆕 v3.0.410 — 🖨️ tisk uloženého týdne (A4, window.print v novém okně)
window.tmTisk = function() {
  if (!state._tm.id) return alert('Nejdřív týden ulož.');
  window.open('../api/admin_tydenni_menu.php?action=tisk&id=' + state._tm.id, '_blank');
};

// 🆕 v3.0.410 — 👁️ náhled šablony (dny + jídla + aktuální ceny z katalogu, bez načtení)
window.tmSablonaNahled = function() {
  const sel = document.getElementById('tm-sab-sel');
  const id = parseInt(sel && sel.value, 10);
  const s = (state._tmData.sablony || []).find(x => (x.id | 0) === id);
  if (!s) return;
  const dnyHtml = TM_DNY_UI.map(([k, label]) => {
    const items = s.dny[k] || [];
    if (!items.length) return '';
    return `<h4 style="margin:10px 0 4px;font-size:13px;color:#854F0B">${label}</h4>` + items.map(r => {
      const v = (state._tmData.vyrobky || []).find(x => String(x.id) === String(r.vyrobek_id));
      return `<div style="display:flex;justify-content:space-between;font-size:13px;padding:3px 0;border-bottom:1px dashed var(--border)">
        <span>${v ? esc(v.nazev) : '#' + r.vyrobek_id + ' <span style="color:var(--danger-text);font-size:10px">smazaný výrobek</span>'}${r.pozn ? ` <span style="color:var(--text-3);font-size:11px">(${esc(r.pozn)})</span>` : ''}</span>
        <strong>${v ? fmt(v.cena_s_dph) : '—'}</strong>
      </div>`;
    }).join('');
  }).join('');
  const celkem = Object.values(s.dny).reduce((a, x) => a + x.length, 0);
  openModal(`👁️ Náhled šablony — ${esc(s.nazev)}`, `
    <div style="font-size:12px;color:var(--text-3);margin-bottom:6px">${celkem} jídel · ceny aktuální z katalogu (v šabloně se neukládají)</div>
    <div style="max-height:60vh;overflow:auto">${dnyHtml || '<div style="color:var(--text-3)">Šablona je prázdná.</div>'}</div>
    <div class="form-actions">
      <button class="btn-secondary" onclick="closeModal()">Zavřít</button>
      <button class="btn-primary btn-green" onclick="closeModal();tmSablonaNacti()">⤵️ Načíst do týdne</button>
    </div>
  `);
};

window.tmSablonaSmaz = async function() {
  const sel = document.getElementById('tm-sab-sel');
  const id = parseInt(sel && sel.value, 10);
  const s = (state._tmData.sablony || []).find(x => (x.id | 0) === id);
  if (!s) return;
  if (!(await confirmDialog({ title: 'Smazat šablonu?', msg: '„' + s.nazev + '“ — nevratné.', danger: true, okText: 'Smazat' }))) return;
  try {
    const r = await api('admin_tydenni_menu.php?action=delete_sablona', { method: 'POST', body: JSON.stringify({ id }) });
    state._tmData.sablony = r.sablony || [];
    toastSuccess('Šablona smazána');
    tmRenderBody();
  } catch (e) { alert('Chyba: ' + e.message); }
};

// ── Rozesílka + sdílení ───────────────────────────────────────
window.tmRozeslat = async function() {
  if (!state._tm.id) return alert('Nejdřív týden ulož.');
  if (!(await confirmDialog({ title: '📧 Rozeslat menu?', msg: 'Pošle e-mail všem aktivním odběratelům s vyplněným e-mailem.', okText: 'Rozeslat' }))) return;
  try {
    const r = await api('admin_tydenni_menu.php?action=rozeslat', { method: 'POST', body: JSON.stringify({ id: state._tm.id }) });
    toastSuccess(`📧 Odesláno ${r.odeslano}/${r.celkem}` + (r.selhalo ? ` (${r.selhalo} selhalo)` : ''));
    try { state._tmData = await api('admin_tydenni_menu.php?action=list'); } catch (e) {}
    tmRenderBody();
  } catch (e) { alert('Chyba: ' + e.message); }
};
window.tmSdilet = async function() {
  if (!state._tm.id) return alert('Nejdřív týden ulož.');
  let url;
  try { url = (await api('admin_tydenni_menu.php?action=share', { method: 'POST', body: JSON.stringify({ id: state._tm.id }) })).url; }
  catch (e) { return alert('Chyba: ' + e.message); }
  const eu = encodeURIComponent(url);
  const txt = encodeURIComponent('🗓️ Týdenní menu — mrkněte, co u nás tento týden vaříme!');
  openModal('🔗 Sdílet týdenní menu', `
    <div style="font-size:13px;margin-bottom:10px">Veřejná stránka menu (bez přihlášení, s náhledovými og: metadaty pro sociální sítě):</div>
    <div style="display:flex;gap:8px;margin-bottom:14px">
      <input class="form-input" id="tm-share-url" value="${esc(url)}" readonly style="flex:1;font-size:12px;font-family:monospace">
      <button class="btn-secondary" onclick="navigator.clipboard.writeText(document.getElementById('tm-share-url').value).then(()=>toastSuccess('Zkopírováno'))">📋 Kopírovat</button>
    </div>
    <div style="display:flex;gap:8px;flex-wrap:wrap">
      <a class="btn-secondary" style="text-decoration:none" href="https://www.facebook.com/sharer/sharer.php?u=${eu}" target="_blank" rel="noopener">📘 Facebook</a>
      <a class="btn-secondary" style="text-decoration:none" href="https://twitter.com/intent/tweet?url=${eu}&text=${txt}" target="_blank" rel="noopener">🐦 X / Twitter</a>
      <a class="btn-secondary" style="text-decoration:none" href="https://wa.me/?text=${txt}%20${eu}" target="_blank" rel="noopener">💬 WhatsApp</a>
      <a class="btn-secondary" style="text-decoration:none" href="${esc(url)}" target="_blank" rel="noopener">👁️ Náhled</a>
    </div>
    <div class="form-actions"><button class="btn-secondary" onclick="closeModal()">Zavřít</button></div>
  `);
};
