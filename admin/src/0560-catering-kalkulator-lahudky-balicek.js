// =============================================================
// 🥗 CATERING KALKULÁTOR (Lahůdky balíček)
// =============================================================
async function renderCateringCalculator() {
  const tab = state._lahudkyTab || 'calc';
  const c = document.getElementById('content');
  c.innerHTML = `
    <div class="page-head">
      <div>
        <h1 class="page-title">🥗 Lahůdkárna</h1>
        <p class="page-sub">Catering kalkulátor · Šaržová HACCP evidence · Mix-and-match · Catering objednávky s časem</p>
      </div>
      <div style="display:flex;gap:8px;flex-wrap:wrap;align-items:center">
        <button class="btn-secondary" onclick="navigate('dashboard')">← Dashboard</button>
      </div>
    </div>
    <div class="nastaveni-tabs" role="tablist" style="margin-bottom:14px">
      <button class="nastaveni-tab ${tab === 'calc' ? 'active' : ''}" onclick="state._lahudkyTab='calc';renderCateringCalculator()">🍱 Catering kalkulátor</button>
      <button class="nastaveni-tab ${tab === 'batches' ? 'active' : ''}" onclick="state._lahudkyTab='batches';renderCateringCalculator()">📋 Šaržová evidence</button>
      <button class="nastaveni-tab ${tab === 'mix' ? 'active' : ''}" onclick="state._lahudkyTab='mix';renderCateringCalculator()">🧩 Mix-and-match</button>
      <button class="nastaveni-tab ${tab === 'orders' ? 'active' : ''}" onclick="state._lahudkyTab='orders';renderCateringCalculator()">🚚 Catering s časem</button>
      <button class="nastaveni-tab ${tab === 'settings' ? 'active' : ''}" onclick="state._lahudkyTab='settings';renderCateringCalculator()">⚙️ Nastavení kalkulačky</button>
    </div>
    <div id="lahudky-tab-body"></div>
  `;

  if (tab === 'batches') return renderBatches();
  if (tab === 'mix')     return renderMixMatch();
  if (tab === 'orders')  return renderLahudkyCateringList();
  if (tab === 'settings') return renderCateringSettings();

  // Default: catering calc
  const body = document.getElementById('lahudky-tab-body');
  body.innerHTML = skeletonCards(3);

  let opts;
  try {
    opts = await api('admin_catering_calc.php?action=options');
  } catch (e) {
    body.innerHTML = `<div class="alert err">${esc(e.message)}</div>`;
    return;
  }

  state._catering = state._catering || {
    osob: 30, typ: 'standard', jidlo: ['chlebicky', 'jednohubky', 'mini_kolacky'], napoje: ['voda', 'kafa'], menu: []
  };
  state._catering.menu = state._catering.menu || [];
  state._cateringVyrobky = opts.vyrobky || [];   // 🆕 v3.0.407 — katalog pro Menu z výrobků

  const renderCheckList = (items, picked, key) => Object.entries(items).map(([k, it]) => `
    <label style="display:flex;align-items:center;gap:8px;padding:8px 10px;border-radius:6px;cursor:pointer;background:${picked.includes(k) ? 'var(--surface-2)' : 'transparent'};border:1px solid ${picked.includes(k) ? 'var(--primary)' : 'transparent'}">
      <input type="checkbox" value="${esc(k)}" ${picked.includes(k) ? 'checked' : ''} onchange="cateringTogglePick('${esc(key)}','${esc(k)}',this.checked)" style="width:16px;height:16px">
      <div style="flex:1;font-size:13px">
        <div style="font-weight:600">${esc(it.vyrobek_nazev || it.nazev)} ${it.odecte_sklad ? '<span title="Napárováno na výrobek s recepturou — odečte se ze skladu" style="font-size:9.5px;background:#DCFCE7;color:#166534;padding:1px 5px;border-radius:5px;font-weight:700">🧮 sklad</span>' : '<span title="Odhadová položka — neodečítá suroviny" style="font-size:9.5px;color:var(--text-3)">odhad</span>'}</div>
        <div style="font-size:11px;color:var(--text-3)">${it.per_osobu} ${esc(it.jednotka)} / osoba · ${it.cena_kc} Kč/${esc(it.jednotka)}${it.material_kc ? ` · materiál ${fmt(it.material_kc)}` : ''}</div>
      </div>
    </label>
  `).join('');

  body.innerHTML = `
    <div style="display:grid;grid-template-columns:1fr 380px;gap:18px">
      <div style="display:flex;flex-direction:column;gap:14px">
        <!-- OSOBY + TYP -->
        <div class="card-block">
          <h3 style="margin:0 0 10px">👥 Akce</h3>
          <div class="form-grid form-grid-tight">
            <div>
              <label class="form-label">Počet osob</label>
              <input type="number" class="form-input" min="2" max="500" value="${state._catering.osob}" oninput="state._catering.osob=parseInt(this.value)||30;cateringRecalc()" style="font-size:24px;font-weight:700;text-align:center">
            </div>
            <div>
              <label class="form-label">Typ události</label>
              <select class="form-input" onchange="state._catering.typ=this.value;cateringRecalc()">
                ${opts.typy_udalosti.map(t => `<option value="${esc(t.id)}" ${state._catering.typ === t.id ? 'selected' : ''}>${t.ikona} ${esc(t.nazev)} (koef ${t.koef}×)</option>`).join('')}
              </select>
            </div>
          </div>
        </div>

        <!-- JÍDLO -->
        <div class="card-block">
          <h3 style="margin:0 0 10px">🍽️ Jídlo (zaškrtni co chceš)</h3>
          <div style="display:grid;grid-template-columns:1fr 1fr;gap:6px">
            ${renderCheckList(opts.doporuceni, state._catering.jidlo, 'jidlo')}
          </div>
        </div>

        <!-- NÁPOJE -->
        <div class="card-block">
          <h3 style="margin:0 0 10px">🥤 Nápoje</h3>
          <div style="display:grid;grid-template-columns:1fr 1fr;gap:6px">
            ${renderCheckList(opts.napoje, state._catering.napoje, 'napoje')}
          </div>
        </div>

        <!-- 🆕 v3.0.407 — MENU Z VÝROBKŮ (cena z výrobku, receptura → odpis surovin) -->
        <div class="card-block">
          <h3 style="margin:0 0 4px">🧩 Menu z výrobků</h3>
          <p style="font-size:12px;color:var(--text-3);margin:0 0 10px">Sestav menu přímo z katalogu výrobků — <strong>cena se bere z výrobku</strong>, množství = ks/osobu × počet osob × koeficient. Výrobek s recepturou se při výrobě odečte ze surovin.</p>
          <div style="display:flex;gap:8px;flex-wrap:wrap;align-items:end;margin-bottom:10px">
            <div style="flex:1;min-width:220px">
              <label class="form-label" style="font-size:12px">Výrobek</label>
              <select class="form-input" id="cat-menu-vyb" style="font-size:13px">
                ${(state._cateringVyrobky || []).map(v => `<option value="${v.id}">${esc(v.nazev)} · ${fmt(v.cena)}/${esc(v.jednotka)}${v.ma_recept ? ' · 🧮' : ''}</option>`).join('')}
              </select>
            </div>
            <div style="width:110px">
              <label class="form-label" style="font-size:12px">ks / osobu</label>
              <input type="number" class="form-input" id="cat-menu-per" value="1" min="0.01" max="50" step="0.1" style="text-align:center">
            </div>
            <button class="btn-primary btn-green" onclick="cateringMenuAdd()">➕ Přidat</button>
          </div>
          <div id="cat-menu-list">
            ${(state._catering.menu || []).length === 0 ? '<div style="font-size:12px;color:var(--text-3);padding:6px 0">Zatím nic — přidej výrobky do menu ↑</div>' :
              (state._catering.menu || []).map((m, i) => {
                const v = (state._cateringVyrobky || []).find(x => String(x.id) === String(m.vyrobek_id)) || {};
                return `<div style="display:flex;align-items:center;gap:10px;padding:7px 8px;border-bottom:1px solid var(--border);font-size:13px">
                  <div style="flex:1;min-width:0"><strong>${esc(v.nazev || ('#' + m.vyrobek_id))}</strong>
                    <span style="font-size:11px;color:var(--text-3)"> · ${fmt(v.cena || 0)}/${esc(v.jednotka || 'ks')}${v.ma_recept ? ' · <span title="Má recepturu — odečte suroviny">🧮 sklad</span>' : ' · odhad bez odpisu'}</span>
                  </div>
                  <input type="number" value="${m.per_osobu}" min="0.01" max="50" step="0.1" style="width:76px;text-align:center;padding:5px;border:1px solid var(--border);border-radius:6px" onchange="cateringMenuSetPer(${i}, this.value)" title="ks / osobu">
                  <span style="font-size:11px;color:var(--text-3)">/os</span>
                  <button class="btn-icon" onclick="cateringMenuDel(${i})" title="Odebrat">✕</button>
                </div>`;
              }).join('')}
          </div>
        </div>
      </div>

      <!-- SUMMARY -->
      <div style="position:sticky;top:80px;align-self:start">
        <div class="card-block" style="background:linear-gradient(180deg,#DCFCE7,#fff);border:2px solid #166534">
          <h3 style="margin:0 0 12px">💰 Kalkulace</h3>
          <div id="catering-quote">⏳</div>
        </div>
      </div>
    </div>
  `;
  cateringRecalc();
}

window.cateringTogglePick = function(key, val, checked) {
  state._catering = state._catering || { jidlo: [], napoje: [] };
  const list = state._catering[key] || [];
  state._catering[key] = checked ? [...list, val] : list.filter(x => x !== val);
  cateringRecalc();
};

// 🆕 v3.0.407 — Menu z výrobků: přidat / odebrat / změnit ks-na-osobu
window.cateringMenuAdd = function() {
  const vid = parseInt((document.getElementById('cat-menu-vyb') || {}).value, 10);
  const per = parseFloat((document.getElementById('cat-menu-per') || {}).value) || 1;
  if (!vid) return;
  state._catering.menu = state._catering.menu || [];
  const ex = state._catering.menu.find(m => m.vyrobek_id === vid);
  if (ex) ex.per_osobu = per; else state._catering.menu.push({ vyrobek_id: vid, per_osobu: per });
  renderCateringCalculator();
};
window.cateringMenuDel = function(i) {
  (state._catering.menu || []).splice(i, 1);
  renderCateringCalculator();
};
window.cateringMenuSetPer = function(i, val) {
  const m = (state._catering.menu || [])[i];
  if (m) { m.per_osobu = Math.max(0.01, Math.min(50, parseFloat(val) || 1)); cateringRecalc(); }
};

window.cateringRecalc = async function() {
  const host = document.getElementById('catering-quote');
  if (!host) return;
  try {
    const r = await api('admin_catering_calc.php?action=quote', {
      method: 'POST',
      body: JSON.stringify({
        osob: state._catering.osob, typ_udalosti: state._catering.typ,
        prilohy: state._catering.jidlo || [], napoje: state._catering.napoje || [],
        menu: state._catering.menu || [],   // 🆕 v3.0.407 — menu z výrobků
      }),
    });
    host.innerHTML = `
      <div style="font-size:11px;text-transform:uppercase;letter-spacing:0.05em;color:var(--text-3);font-weight:700">Pro ${r.osob} osob</div>
      <div style="font-weight:600;font-size:14px;margin-bottom:8px">${esc(r.typ.ikona)} ${esc(r.typ.nazev)}</div>

      <div style="border-top:1px solid var(--border);margin:8px 0;padding-top:8px;max-height:280px;overflow-y:auto">
        ${r.polozky.length === 0 ? '<div style="color:var(--text-3);font-size:12px;padding:14px;text-align:center">Nic nevybráno.</div>' :
          r.polozky.map(p => `
            <div style="display:flex;justify-content:space-between;font-size:12.5px;padding:4px 0;border-bottom:1px solid var(--border)">
              <div>
                <div>${esc(p.nazev)}</div>
                <div style="font-size:10.5px;color:var(--text-3)">${p.mnozstvi} ${esc(p.jednotka)} × ${p.cena_per_jednotku} Kč</div>
              </div>
              <span style="font-variant-numeric:tabular-nums;font-weight:600">${fmt(p.cena_kc)}</span>
            </div>
          `).join('')}
      </div>

      <div style="display:flex;justify-content:space-between;font-size:12px;padding:3px 0">
        <span>Bez DPH</span><span style="font-variant-numeric:tabular-nums">${fmt(r.cena_bez_dph)}</span>
      </div>
      <div style="display:flex;justify-content:space-between;font-size:12px;padding:3px 0;color:var(--text-3)">
        <span>DPH ${r.sazba_dph}%</span><span style="font-variant-numeric:tabular-nums">${fmt(r.cena_dph)}</span>
      </div>
      <div style="display:flex;justify-content:space-between;font-size:18px;padding:8px 0;margin-top:6px;border-top:2px solid #166534;font-weight:800;color:#166534">
        <span>Celkem s DPH</span><span style="font-variant-numeric:tabular-nums">${fmt(r.cena_s_dph)}</span>
      </div>
      <div style="font-size:11px;color:var(--text-3);text-align:right;margin-top:2px">${fmt(r.cena_per_osobu)} / osoba</div>

      ${r.kalkulace ? `
      <div style="background:var(--surface-2);border-radius:8px;margin-top:10px;padding:8px 10px;font-size:11.5px">
        <div style="font-weight:700;color:var(--text-2);margin-bottom:4px">🧮 Z kalkulace (receptury)</div>
        <div style="display:flex;justify-content:space-between"><span>Materiál (suroviny)</span><span style="font-variant-numeric:tabular-nums">${fmt(r.kalkulace.material_kc)}</span></div>
        <div style="display:flex;justify-content:space-between;color:var(--success-text)"><span>Marže</span><span style="font-variant-numeric:tabular-nums">${fmt(r.kalkulace.marze_kc)} · ${r.kalkulace.marze_pct}%</span></div>
        <div style="font-size:10px;color:var(--text-3);margin-top:3px">📦 ${r.kalkulace.polozek_se_skladem}/${r.kalkulace.polozek_celkem} položek se odečte ze skladu při výrobě</div>
      </div>` : ''}

      ${r.polozky.length > 0 ? `<button class="btn-primary btn-green btn-big-action" style="width:100%;margin-top:12px;padding:13px;font-size:14px;font-weight:700" onclick="cateringCreateOrder(${JSON.stringify(r).replace(/"/g, '&quot;')})">📋 Vytvořit objednávku</button>` : ''}
    `;
  } catch (e) {
    host.innerHTML = `<div style="color:var(--danger-text)">Chyba: ${esc(e.message)}</div>`;
  }
};

// 🆕 v3.0.298 — Catering kalkulačka → reálná objednávka (kanál 'catering', řádky s vyrobek_id → odpis).
window.cateringCreateOrder = async function(quote) {
  state._cateringFoto = '';   // reset náhledu pro novou objednávku
  let odb;
  try { odb = await api('admin_odberatele.php'); }
  catch (e) { return alert('Chyba načtení odběratelů: ' + e.message); }
  const odberatele = Array.isArray(odb) ? odb : (odb.odberatele || odb.data || []);
  if (odberatele.length === 0) { alert('Nejsou žádní odběratelé. Vytvoř nejdřív odběratele.'); return; }

  const minDate = new Date(Date.now() + 2 * 86400000).toISOString().slice(0, 10);
  const odecitanych = (quote.kalkulace && quote.kalkulace.polozek_se_skladem) || 0;
  openModal('🥗 Vytvořit catering objednávku', `
    <div style="background:#DCFCE7;border-left:3px solid #166534;padding:12px;border-radius:8px;margin-bottom:14px;font-size:13px;color:#14532D">
      📋 <strong>${quote.osob} osob</strong> · ${esc(quote.typ.nazev)}<br>
      💰 ${fmt(quote.cena_s_dph)} celkem · 🧮 ${odecitanych}/${quote.polozky.length} položek se odečte ze skladu
    </div>
    <div class="form-grid form-grid-tight">
      <div class="full"><label class="form-label">Odběratel *</label>
        <select class="form-input" id="cat-odb">
          ${odberatele.map(o => `<option value="${o.id}">${esc(o.nazev)}${o.ico ? ' · IČ ' + esc(o.ico) : ''}</option>`).join('')}
        </select>
      </div>
      <div class="full"><label class="form-label">📅 Datum dodání *</label>
        <input type="date" class="form-input" id="cat-datum" value="${minDate}" min="${minDate}">
      </div>
      <div class="full">
        <label class="form-label">📸 Fotka předlohy <span style="font-weight:400;font-size:11px;color:var(--text-3)">(volitelné — aranžmá / vzor mís)</span></label>
        <div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap">
          <input class="form-input" id="cat-foto" style="flex:1;min-width:180px" placeholder="https://… nebo nahraj fotku →" oninput="state._cateringFoto=this.value;cateringRenderFotoNahled()">
          <input type="file" id="cat-foto-file" accept="image/jpeg,image/png,image/webp" style="display:none" onchange="cateringUploadPredloha(this)">
          <button type="button" class="btn-secondary" id="cat-foto-btn" onclick="document.getElementById('cat-foto-file').click()" style="white-space:nowrap">⬆️ Nahrát fotku</button>
        </div>
        <div id="cat-foto-nahled" style="margin-top:8px"></div>
      </div>
      <div class="full"><label class="form-label">📝 Poznámka <span style="font-weight:400;font-size:11px;color:var(--text-3)">(volitelné — alergie, čas, speciální požadavky)</span></label>
        <input class="form-input" id="cat-pozn" placeholder="Např. bezlepkové chlebíčky, dovoz 9:00">
      </div>
    </div>
    <div class="form-actions">
      <button class="btn-secondary" onclick="closeModal()">Zrušit</button>
      <button class="btn-primary btn-green" onclick="cateringSubmitOrder()">✓ Vytvořit objednávku</button>
    </div>
  `);
  cateringRenderFotoNahled();
};

window.cateringSubmitOrder = async function() {
  const odberatelId = parseInt(document.getElementById('cat-odb').value);
  const datum = document.getElementById('cat-datum').value;
  if (!odberatelId || !datum) { alert('Vyplň odběratele a datum.'); return; }
  if (!state._catering) { alert('Nejprve nastav kalkulaci.'); return; }
  try {
    const r = await api('admin_catering_calc.php?action=create_order', {
      method: 'POST',
      body: JSON.stringify({
        osob: state._catering.osob, typ_udalosti: state._catering.typ,
        prilohy: state._catering.jidlo || [], napoje: state._catering.napoje || [],
        menu: state._catering.menu || [],   // 🆕 v3.0.407 — menu z výrobků
        odberatel_id: odberatelId, datum_dodani: datum,
        foto: (state._cateringFoto || '').trim(),
        poznamka: (document.getElementById('cat-pozn')?.value || '').trim(),
      }),
    });
    closeModal();
    toastSuccess(t('toast_order_created_amount', { cislo: r.cislo, amount: fmt(r.castka) }));
    setTimeout(() => navigate('objednavky'), 600);
  } catch (e) { alert('Chyba: ' + e.message); }
};

// 🆕 v3.0.306 — catering: náhled + upload fotky předlohy (sdílí /uploads/predlohy/ s dorty)
window.cateringRenderFotoNahled = function() {
  const host = document.getElementById('cat-foto-nahled');
  if (!host) return;
  const url = ((state._cateringFoto) || '').trim();
  host.innerHTML = url ? `
    <div style="display:inline-flex;align-items:center;gap:10px;border:1px solid var(--border);border-radius:8px;padding:6px 10px;background:var(--bg-2,#fafafa)">
      <img src="${esc(url)}" alt="předloha" style="height:54px;width:54px;object-fit:cover;border-radius:6px;background:#eee" onerror="this.style.opacity=.3;this.alt='⚠'">
      <span style="font-size:11px;color:var(--text-3);max-width:240px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap">${esc(url)}</span>
      <button type="button" title="Odebrat" onclick="state._cateringFoto='';var i=document.getElementById('cat-foto');if(i)i.value='';cateringRenderFotoNahled()" style="border:none;background:none;cursor:pointer;color:var(--danger-text,#DC2626);font-size:18px;line-height:1;padding:0 4px">×</button>
    </div>` : '';
};
window.cateringUploadPredloha = async function(input) {
  const file = input && input.files && input.files[0];
  if (!file) return;
  const btn = document.getElementById('cat-foto-btn');
  const orig = btn ? btn.textContent : '';
  if (btn) { btn.disabled = true; btn.textContent = '⏳ Nahrávám…'; }
  try {
    const fd = new FormData(); fd.append('foto', file);
    const res = await api('admin_catering_calc.php?action=upload_predloha', { method: 'POST', body: fd });
    state._cateringFoto = res.url;
    const i = document.getElementById('cat-foto'); if (i) i.value = res.url;
    cateringRenderFotoNahled();
  } catch (e) { alert('Chyba při nahrávání fotky: ' + e.message); }
  finally { if (btn) { btn.disabled = false; btn.textContent = orig; } input.value = ''; }
};

// ═══════════════════ CATERING EDITOR KONFIGURÁTORU (v3.0.306) ═══════════════════
async function renderCateringSettings() {
  const body = document.getElementById('lahudky-tab-body');
  if (!body) return;
  body.innerHTML = skeletonCards(2);
  try {
    const c = await api('admin_catering_calc.php?action=config');
    state._catCfg = c.config;
    state._catCfgVyr = c.vyrobky || [];
  } catch (e) { body.innerHTML = `<div class="alert err">${esc(e.message)}</div>`; return; }
  cateringCfgRender();
}

function cateringCfgRender() {
  const body = document.getElementById('lahudky-tab-body');
  if (!body) return;
  const cfg = state._catCfg || {};
  const vyr = state._catCfgVyr || [];
  const vyrOpts = (sel) => `<option value="">— bez napárování (odhad) —</option>` + vyr.map(v => `<option value="${v.id}" ${String(sel) === String(v.id) ? 'selected' : ''}>${esc(v.nazev)}${v.obor === 'lahudka' ? ' 🥗' : ''}${v.ma_recept ? ' · recept' : ''}</option>`).join('');
  const jedOpts = (sel) => ['ks', 'kg', 'l', 'porce'].map(j => `<option value="${j}" ${sel === j ? 'selected' : ''}>${j}</option>`).join('');

  const itemRows = (skupina) => Object.entries(cfg[skupina] || {}).map(([key, it]) => `
    <tr>
      <td><input class="form-input" style="font-size:12px;min-width:140px" value="${esc(it.nazev || '')}" oninput="cateringCfgSet('${skupina}','${esc(key)}','nazev',this.value)"></td>
      <td><input type="number" step="0.01" min="0" class="form-input" style="font-size:12px;width:78px" value="${it.per_osobu || 0}" oninput="cateringCfgSet('${skupina}','${esc(key)}','per_osobu',this.value)"></td>
      <td><input type="number" step="0.5" min="0" class="form-input" style="font-size:12px;width:78px${it.vyrobek_id ? ';opacity:0.5' : ''}" value="${it.cena_kc || 0}" ${it.vyrobek_id ? 'disabled title="Cena se bere z napárovaného výrobku — ruční cena se nepoužije"' : ''} oninput="cateringCfgSet('${skupina}','${esc(key)}','cena_kc',this.value)"></td>
      <td><select class="form-input" style="font-size:12px;width:72px" onchange="cateringCfgSet('${skupina}','${esc(key)}','jednotka',this.value)">${jedOpts(it.jednotka || 'ks')}</select></td>
      <td><select class="form-input" style="font-size:12px;min-width:170px" onchange="cateringCfgSet('${skupina}','${esc(key)}','vyrobek_id',this.value)">${vyrOpts(it.vyrobek_id)}</select></td>
      <td style="text-align:center"><button class="btn-icon" title="Smazat položku" onclick="cateringCfgDel('${skupina}','${esc(key)}')" style="border:none;background:none;cursor:pointer;color:var(--danger-text,#DC2626);font-size:15px">🗑</button></td>
    </tr>`).join('');

  const itemTable = (skupina, titulek, ikona) => `
    <div class="card-block">
      <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:8px">
        <h3 style="margin:0;font-size:15px">${ikona} ${titulek}</h3>
        <button class="btn-secondary" onclick="cateringCfgAdd('${skupina}')" style="font-size:12px">+ Přidat položku</button>
      </div>
      <div style="overflow-x:auto">
      <table class="table" style="font-size:12px;margin:0">
        <thead><tr><th>Název</th><th>Na osobu</th><th>Cena/jed</th><th>Jedn.</th><th>Napárovat na výrobek (→ odpis ze skladu)</th><th></th></tr></thead>
        <tbody>${itemRows(skupina) || `<tr><td colspan="6" style="text-align:center;color:var(--text-3);padding:14px">Žádné položky — přidej tlačítkem výše</td></tr>`}</tbody>
      </table>
      </div>
    </div>`;

  body.innerHTML = `
    <div style="display:flex;flex-direction:column;gap:14px">
      <div style="background:#FEF9E7;border-left:3px solid #BA7517;padding:10px 14px;border-radius:6px;font-size:12.5px;color:#7a5a12">
        ⚙️ Uprav položky, ceny a množství na osobu. <strong>Napáruj na výrobek</strong> (s recepturou) → kalkulačka při objednávce odečte suroviny ze skladu a vezme cenu z výrobku. Bez napárování = odhadová položka bez odpisu.
      </div>
      ${itemTable('jidlo', 'Jídlo', '🍽️')}
      ${itemTable('napoje', 'Nápoje', '🥤')}
      <div class="card-block">
        <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:8px">
          <h3 style="margin:0;font-size:15px">🎉 Typy událostí <span style="font-weight:400;color:var(--text-3);font-size:12px">(koeficient množství × na osobu)</span></h3>
          <button class="btn-secondary" onclick="cateringCfgAddTyp()" style="font-size:12px">+ Přidat typ</button>
        </div>
        <table class="table" style="font-size:12px;margin:0">
          <thead><tr><th style="width:60px">Ikona</th><th>Název</th><th style="width:90px">Koef ×</th><th></th></tr></thead>
          <tbody>${(cfg.typy_udalosti || []).map((t, i) => `
            <tr>
              <td><input class="form-input" style="width:52px;font-size:15px;text-align:center" value="${esc(t.ikona || '')}" oninput="cateringCfgSetTyp(${i},'ikona',this.value)"></td>
              <td><input class="form-input" style="font-size:12px" value="${esc(t.nazev || '')}" oninput="cateringCfgSetTyp(${i},'nazev',this.value)"></td>
              <td><input type="number" step="0.1" min="0.1" class="form-input" style="width:80px;font-size:12px" value="${t.koef || 1}" oninput="cateringCfgSetTyp(${i},'koef',this.value)"></td>
              <td style="text-align:center"><button class="btn-icon" title="Smazat typ" onclick="cateringCfgDelTyp(${i})" style="border:none;background:none;cursor:pointer;color:var(--danger-text,#DC2626);font-size:15px">🗑</button></td>
            </tr>`).join('') || `<tr><td colspan="4" style="text-align:center;color:var(--text-3);padding:14px">Žádné typy</td></tr>`}</tbody>
        </table>
      </div>
      <div style="display:flex;gap:12px;align-items:center;flex-wrap:wrap;padding:4px 2px">
        <label style="font-size:13px;font-weight:600">DPH %: <input type="number" step="1" min="0" max="30" class="form-input" style="width:80px;display:inline-block;margin-left:4px" value="${cfg.dph || 12}" oninput="state._catCfg.dph=parseFloat(this.value)||12"></label>
        <button class="btn-secondary" onclick="cateringCfgResetDefault()" style="margin-left:auto">↺ Načíst výchozí</button>
        <button class="btn-primary btn-green" onclick="cateringCfgSave()" style="font-weight:700">💾 Uložit konfiguraci</button>
      </div>
    </div>`;
}

window.cateringCfgSet = function(skupina, key, field, val) {
  if (!state._catCfg || !state._catCfg[skupina] || !state._catCfg[skupina][key]) return;
  if (field === 'per_osobu' || field === 'cena_kc') val = parseFloat(String(val).replace(',', '.')) || 0;
  if (field === 'vyrobek_id') val = val ? parseInt(val) : null;
  state._catCfg[skupina][key][field] = val;   // bez re-renderu → input neztratí fokus
};
window.cateringCfgDel = function(skupina, key) {
  if (state._catCfg && state._catCfg[skupina]) { delete state._catCfg[skupina][key]; cateringCfgRender(); }
};
window.cateringCfgAdd = function(skupina) {
  state._catCfg = state._catCfg || {};
  state._catCfg[skupina] = state._catCfg[skupina] || {};
  const key = 'pol_' + Math.random().toString(36).slice(2, 8);
  state._catCfg[skupina][key] = { nazev: 'Nová položka', per_osobu: 1, cena_kc: 20, jednotka: 'ks', vyrobek_id: null, match: [] };
  cateringCfgRender();
};
window.cateringCfgSetTyp = function(i, field, val) {
  if (!state._catCfg || !state._catCfg.typy_udalosti || !state._catCfg.typy_udalosti[i]) return;
  if (field === 'koef') val = parseFloat(String(val).replace(',', '.')) || 1;
  state._catCfg.typy_udalosti[i][field] = val;
};
window.cateringCfgAddTyp = function() {
  state._catCfg = state._catCfg || {};
  state._catCfg.typy_udalosti = state._catCfg.typy_udalosti || [];
  state._catCfg.typy_udalosti.push({ id: 'typ_' + Math.random().toString(36).slice(2, 7), nazev: 'Nový typ', ikona: '🎉', koef: 1 });
  cateringCfgRender();
};
window.cateringCfgDelTyp = function(i) {
  if (state._catCfg && state._catCfg.typy_udalosti) { state._catCfg.typy_udalosti.splice(i, 1); cateringCfgRender(); }
};
window.cateringCfgSave = async function() {
  if (!state._catCfg) return;
  (state._catCfg.typy_udalosti || []).forEach(t => { if (!t.id) t.id = 'typ_' + Math.random().toString(36).slice(2, 7); });
  try {
    const r = await api('admin_catering_calc.php?action=save_config', { method: 'POST', body: JSON.stringify({ config: state._catCfg }) });
    state._catCfg = r.config;
    if (typeof toastSuccess === 'function') toastSuccess('Konfigurace kalkulačky uložena'); else alert('Uloženo');
    cateringCfgRender();
  } catch (e) { alert('Chyba uložení: ' + e.message); }
};
window.cateringCfgResetDefault = async function() {
  if (!(await confirmDialog({ msg: 'Načíst výchozí konfiguraci do editoru? (Uloží se až tlačítkem „Uložit".)', danger: false }))) return;
  try {
    const c = await api('admin_catering_calc.php?action=config');
    state._catCfg = c.default;
    cateringCfgRender();
  } catch (e) { alert('Chyba: ' + e.message); }
};

