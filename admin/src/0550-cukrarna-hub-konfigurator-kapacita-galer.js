// =============================================================
// 🎂 CUKRÁRNA HUB — Konfigurátor + Kapacita + Galerie
// =============================================================
async function renderCakeConfigurator() {
  const tab = state._cakeTab || 'configurator';
  // Bezpečná inicializace — i kdyby uživatel skočil rovnou do "Kapacita" nebo "Galerie"
  if (!state._cake || typeof state._cake !== 'object') state._cake = {};
  const c = document.getElementById('content');
  c.innerHTML = `
    <div class="page-head">
      <div>
        <h1 class="page-title">🧁 Cukrárna</h1>
        <p class="page-sub">Konfigurátor dortů · Kapacita pečení · Galerie produktů</p>
      </div>
      <div style="display:flex;gap:8px;flex-wrap:wrap;align-items:center">
        <button class="btn-secondary" onclick="navigate('dashboard')">← Dashboard</button>
      </div>
    </div>
    <div class="nastaveni-tabs" role="tablist" style="margin-bottom:14px">
      <button class="nastaveni-tab ${tab === 'configurator' ? 'active' : ''}" onclick="state._cakeTab='configurator';renderCakeConfigurator()">🎂 Konfigurátor dortů</button>
      <button class="nastaveni-tab ${tab === 'capacity' ? 'active' : ''}" onclick="state._cakeTab='capacity';renderCakeConfigurator()">📅 Kapacita pečení</button>
      <button class="nastaveni-tab ${tab === 'gallery' ? 'active' : ''}" onclick="state._cakeTab='gallery';renderCakeConfigurator()">🖼️ Galerie inspirací</button>
      <button class="nastaveni-tab ${tab === 'stands' ? 'active' : ''}" onclick="state._cakeTab='stands';renderCakeConfigurator()">♻️ Vratné stojany</button>
      <button class="nastaveni-tab ${tab === 'settings' ? 'active' : ''}" onclick="state._cakeTab='settings';renderCakeConfigurator()">⚙️ Nastavení konfigurátoru</button>
    </div>
    <div id="cake-tab-body"></div>
  `;

  if (tab === 'configurator') return renderCakeConfiguratorBody();
  if (tab === 'capacity')     return renderCakeCapacity();
  if (tab === 'gallery')      return renderCakeGallery();
  if (tab === 'stands')       return renderCakeStands();
  if (tab === 'settings')     return renderCakeConfigSettings();
}

// ───────── VRATNÉ STOJANY ─────────
async function renderCakeStands() {
  const body = document.getElementById('cake-tab-body');
  if (!body) return;
  body.innerHTML = skeletonCards(3);
  let data;
  try { data = await api('admin_cake_stands.php'); }
  catch (e) { body.innerHTML = `<div class="alert err">${esc(e.message)}</div>`; return; }

  const s = data.stats || {};
  const stavBarvy = {
    'sklad':    { bg: '#DCFCE7', fg: '#166534', label: '🟢 Na skladě' },
    'pujceno':  { bg: '#FEF3C7', fg: '#92400E', label: '🟡 Zapůjčeno' },
    'ztraceno': { bg: '#FEE2E2', fg: '#991B1B', label: '🔴 Ztraceno' },
    'vyrazeno': { bg: '#E5E7EB', fg: '#374151', label: '⚪ Vyřazeno' },
  };

  body.innerHTML = `
    <div class="card-block" style="margin-bottom:14px;display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:10px">
      <div style="display:flex;gap:16px;flex-wrap:wrap">
        <div><strong style="font-size:20px">${s.total||0}</strong> <span style="color:var(--text-3);font-size:12px">celkem</span></div>
        <div><strong style="font-size:20px;color:#166534">${s.sklad||0}</strong> <span style="color:var(--text-3);font-size:12px">na skladě</span></div>
        <div><strong style="font-size:20px;color:#92400E">${s.pujceno||0}</strong> <span style="color:var(--text-3);font-size:12px">zapůjčeno</span></div>
        ${s.po_termin > 0 ? `<div><strong style="font-size:20px;color:#dc2626">${s.po_termin}</strong> <span style="color:var(--text-3);font-size:12px">po termínu!</span></div>` : ''}
        ${s.ztraceno > 0 ? `<div><strong style="font-size:20px;color:#991B1B">${s.ztraceno}</strong> <span style="color:var(--text-3);font-size:12px">ztraceno</span></div>` : ''}
      </div>
      <button class="btn-primary btn-green" onclick="cakeStandEdit(0)">+ Nový stojan</button>
    </div>

    ${(data.aktivni || []).length > 0 ? `
      <div class="card-block" style="margin-bottom:14px">
        <h3 style="margin:0 0 12px;font-size:15px">⏰ Aktivní výpůjčky (${data.aktivni.length})</h3>
        <div style="display:flex;flex-direction:column;gap:8px">
          ${data.aktivni.map(l => {
            const po = l.stav === 'po_termin' || (l.dnu_po_termin && l.dnu_po_termin > 0);
            return `
              <div style="display:grid;grid-template-columns:auto 1fr auto auto;gap:12px;align-items:center;background:${po ? '#FEE2E2' : 'var(--surface-2)'};padding:10px 14px;border-radius:10px;border-left:4px solid ${po ? '#dc2626' : '#F59E0B'}">
                <div style="font-weight:700;font-size:14px;font-family:monospace">${esc(l.stand_kod)}</div>
                <div style="min-width:0">
                  <div style="font-weight:600;font-size:13.5px">${esc(l.nazev_clienta || '— neznámý —')}</div>
                  <div style="font-size:11.5px;color:var(--text-3)">
                    Půjčeno: <strong>${new Date(l.datum_pujceni).toLocaleDateString('cs-CZ')}</strong>
                    ${l.datum_vraceni_planovane ? ` · plánováno vrátit: <strong>${new Date(l.datum_vraceni_planovane).toLocaleDateString('cs-CZ')}</strong>` : ''}
                    · ${l.dnu_zapujceno} dní zapůjčeno
                    ${po ? ` · <strong style="color:#dc2626">PO TERMÍNU o ${l.dnu_po_termin} dní</strong>` : ''}
                  </div>
                  ${l.poznamka ? `<div style="font-size:11px;color:var(--text-3);margin-top:2px">📝 ${esc(l.poznamka)}</div>` : ''}
                </div>
                <button class="btn-primary btn-green" style="padding:6px 12px;font-size:12px" onclick="cakeStandReturn(${l.id})">✓ Vrátit</button>
                <button class="btn-secondary" style="padding:6px 10px;font-size:12px;color:#dc2626" onclick="cakeStandLost(${l.id})" title="Označit jako ztracené">⚠ Ztraceno</button>
              </div>
            `;
          }).join('')}
        </div>
      </div>
    ` : ''}

    <div class="card-block">
      <h3 style="margin:0 0 12px;font-size:15px">🏷️ Všechny stojany</h3>
      ${(data.stands || []).length === 0 ? emptyState({
        icon: '♻️', title: 'Žádné stojany v evidenci',
        msg: 'Přidej první stojan na dorty, který půjčuješ zákazníkům.',
        actions: '<button class="btn-primary btn-green" onclick="cakeStandEdit(0)">+ Přidat stojan</button>',
      }) : `
        <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(240px,1fr));gap:12px">
          ${data.stands.map(st => {
            const c = stavBarvy[st.stav] || stavBarvy.sklad;
            return `
              <div style="background:var(--surface);border:1px solid var(--border);border-radius:12px;overflow:hidden;display:flex;flex-direction:column">
                ${st.foto_url ? `<div style="aspect-ratio:1.4;background:#f5f5f7 url('${esc(st.foto_url)}') center/cover"></div>` : `<div style="aspect-ratio:1.4;background:linear-gradient(135deg,#FBBF24,#BA7517);display:flex;align-items:center;justify-content:center;font-size:42px">♻️</div>`}
                <div style="padding:12px;display:flex;flex-direction:column;gap:6px;flex:1">
                  <div style="display:flex;justify-content:space-between;align-items:start;gap:8px">
                    <strong style="font-family:monospace;font-size:14px">${esc(st.kod)}</strong>
                    <span style="background:${c.bg};color:${c.fg};padding:2px 8px;border-radius:999px;font-size:10.5px;font-weight:700;white-space:nowrap">${c.label}</span>
                  </div>
                  ${st.popis ? `<div style="font-size:12px;color:var(--text-3);line-height:1.4">${esc(st.popis)}</div>` : ''}
                  ${st.zaloha_kc > 0 ? `<div style="font-size:11px;color:var(--text-3)">🧾 Záloha: <strong>${fmt(st.zaloha_kc)}</strong></div>` : ''}
                  ${st.stav === 'pujceno' ? `
                    <div style="background:#FEF3C7;padding:6px 8px;border-radius:6px;font-size:11px;margin-top:4px">
                      <div style="color:#92400E;font-weight:600">${esc(st.aktivni_klient || '—')}</div>
                      <div style="color:#92400E;opacity:0.8">Od ${new Date(st.aktivni_od).toLocaleDateString('cs-CZ')}${st.aktivni_planovane ? ` · vrátit: ${new Date(st.aktivni_planovane).toLocaleDateString('cs-CZ')}` : ''}</div>
                    </div>
                  ` : ''}
                  <div style="display:flex;gap:6px;margin-top:auto;padding-top:6px">
                    ${st.stav === 'sklad' ? `<button class="btn-primary" style="flex:1;font-size:12px;padding:6px 10px" onclick="cakeStandLoan(${st.id},'${esc(st.kod)}')">📤 Půjčit</button>` : ''}
                    <button class="btn-secondary" style="font-size:12px;padding:6px 10px" onclick="cakeStandEdit(${st.id})">✏️</button>
                    ${st.stav !== 'pujceno' ? `<button class="btn-secondary" style="font-size:12px;padding:6px 10px;color:#dc2626" onclick="cakeStandDelete(${st.id})">🗑️</button>` : ''}
                  </div>
                </div>
              </div>
            `;
          }).join('')}
        </div>
      `}
    </div>
  `;
}

window.cakeStandEdit = async function(id) {
  let s = { id: 0, kod: '', popis: '', foto_url: '', zaloha_kc: 0 };
  if (id) {
    try {
      const data = await api('admin_cake_stands.php');
      const found = (data.stands || []).find(x => x.id === id);
      if (found) s = found;
    } catch (e) {}
  }
  openModal(id ? `✏️ Stojan ${esc(s.kod)}` : '+ Nový stojan', `
    <div class="form-grid form-grid-tight">
      <div><label class="form-label">Kód *</label>
        <input class="form-input" id="cs-kod" value="${esc(s.kod)}" placeholder="např. STJ-001" autofocus>
      </div>
      <div><label class="form-label">🧾 Záloha (Kč)</label>
        <input type="number" class="form-input" id="cs-zal" value="${s.zaloha_kc || 0}" step="100">
      </div>
      <div class="full"><label class="form-label">Popis</label>
        <input class="form-input" id="cs-popis" value="${esc(s.popis || '')}" placeholder="např. Plastový 3-patrový průměr 30 cm">
      </div>
      <div class="full"><label class="form-label">📷 URL fotky</label>
        <input class="form-input" id="cs-foto" value="${esc(s.foto_url || '')}" placeholder="https://…">
      </div>
    </div>
    <div class="form-actions">
      <button class="btn-secondary" onclick="closeModal()">Zrušit</button>
      <button class="btn-primary btn-green" onclick="cakeStandSave(${id})">💾 Uložit</button>
    </div>
  `);
};

window.cakeStandSave = async function(id) {
  const kod = document.getElementById('cs-kod')?.value?.trim();
  if (!kod) { alert('Vyplň kód'); return; }
  try {
    await api('admin_cake_stands.php?action=stand', { method: 'POST', body: JSON.stringify({
      id: id || 0, kod,
      popis: document.getElementById('cs-popis').value.trim(),
      foto_url: document.getElementById('cs-foto').value.trim(),
      zaloha_kc: parseFloat(document.getElementById('cs-zal').value) || 0,
    })});
    closeModal();
    toastSuccess(id ? 'Stojan upraven' : 'Stojan přidán');
    renderCakeStands();
  } catch (e) { alert('Chyba: ' + e.message); }
};

window.cakeStandDelete = async function(id) {
  if (!await customConfirm('Smazat stojan?', 'Smaže i historii půjček. Pokud je zapůjčen, nejprve ho vrať.', 'Smazat')) return;
  try {
    await api('admin_cake_stands.php?action=stand&id=' + id, { method: 'DELETE' });
    toastSuccess('Stojan smazán');
    renderCakeStands();
  } catch (e) { alert('Chyba: ' + e.message); }
};

window.cakeStandLoan = async function(id, kod) {
  // Načti seznam odběratelů
  let odberatele = [];
  try {
    const r = await api('admin_odberatele.php');
    odberatele = r.odberatele || r || [];
  } catch (e) {}
  const today = new Date();
  const plus7 = new Date(today.getTime() + 7 * 86400000).toISOString().slice(0, 10);

  openModal(`📤 Půjčit stojan ${kod}`, `
    <div class="form-grid form-grid-tight">
      <div class="full"><label class="form-label">Odběratel</label>
        <select class="form-input" id="csl-odb">
          <option value="">— Vyber odběratele —</option>
          ${odberatele.map(o => `<option value="${o.id}">${esc(o.nazev || o.kod || '#'+o.id)}</option>`).join('')}
        </select>
      </div>
      <div class="full"><label class="form-label">Nebo jen jméno (pokud není v evidenci)</label>
        <input class="form-input" id="csl-nazev" placeholder="např. Novákova svatba">
      </div>
      <div><label class="form-label">📅 Plánovaný návrat</label>
        <input type="date" class="form-input" id="csl-datum" value="${plus7}">
      </div>
      <div class="full"><label class="form-label">Poznámka</label>
        <textarea class="form-input" id="csl-pozn" rows="2" placeholder="např. Akce v sále Praha, kontakt 777..."></textarea>
      </div>
    </div>
    <div class="form-actions">
      <button class="btn-secondary" onclick="closeModal()">Zrušit</button>
      <button class="btn-primary btn-green" onclick="cakeStandLoanSave(${id})">📤 Půjčit</button>
    </div>
  `);
};

window.cakeStandLoanSave = async function(standId) {
  const odbId  = parseInt(document.getElementById('csl-odb').value) || 0;
  const nazev  = document.getElementById('csl-nazev').value.trim();
  if (!odbId && !nazev) { alert('Vyber odběratele nebo zadej jméno.'); return; }
  try {
    await api('admin_cake_stands.php?action=loan', { method: 'POST', body: JSON.stringify({
      stand_id: standId,
      odberatel_id: odbId || null,
      odberatel_nazev: nazev || null,
      datum_vraceni_planovane: document.getElementById('csl-datum').value || null,
      poznamka: document.getElementById('csl-pozn').value.trim() || null,
    })});
    closeModal();
    toastSuccess('Stojan zapůjčen');
    renderCakeStands();
  } catch (e) { alert('Chyba: ' + e.message); }
};

window.cakeStandReturn = async function(loanId) {
  if (!await customConfirm('Vrátit stojan?', 'Označí výpůjčku jako vrácenou a stojan jako "na skladě".', 'Vrátit')) return;
  try {
    await api('admin_cake_stands.php?action=return&id=' + loanId, { method: 'POST' });
    toastSuccess('Stojan vrácen');
    renderCakeStands();
  } catch (e) { alert('Chyba: ' + e.message); }
};

window.cakeStandLost = async function(loanId) {
  if (!await customConfirm('Označit jako ztracené?', 'Stojan nebude dostupný. Můžeš ho později obnovit přes editaci.', 'Označit')) return;
  try {
    await api('admin_cake_stands.php?action=lost&id=' + loanId, { method: 'POST' });
    toastSuccess('Označeno jako ztracené');
    renderCakeStands();
  } catch (e) { alert('Chyba: ' + e.message); }
};

async function renderCakeConfiguratorBody() {
  const body = document.getElementById('cake-tab-body');
  if (!body) return;
  body.innerHTML = skeletonCards(3);

  let opts;
  try {
    opts = await api('admin_cake_configurator.php?action=options');
  } catch (e) {
    body.innerHTML = `<div class="alert err">${esc(e.message)}</div>`;
    return;
  }
  if (!opts || !opts.velikosti) {
    body.innerHTML = `<div class="alert err">Backend nevrátil options. Zkontroluj že balíček Cukrárna je aktivní.</div>`;
    return;
  }

  // Init defaults if not set (musí být před tím, než šablona čte state._cake.porci atd.)
  if (!state._cake) state._cake = {};
  if (state._cake.velikost_id === undefined) state._cake.velikost_id = opts.velikosti[0]?.id ?? null;
  if (state._cake.prichut     === undefined) state._cake.prichut     = opts.prichute[0]?.vyrobek_id ?? null;
  if (!state._cake.volby || typeof state._cake.volby !== 'object') state._cake.volby = {};
  // single skupiny: defaultně první volba (obvykle „Bez…/Základní" = 0 Kč) → čisté UI bez překvapení
  (opts.moznosti || []).forEach(g => { if (g.typ !== 'multi' && state._cake.volby[g.id] === undefined) state._cake.volby[g.id] = g.volby[0]?.id ?? null; });
  if (state._cake.text     === undefined) state._cake.text     = '';
  if (state._cake.foto     === undefined) state._cake.foto     = '';

  body.innerHTML = `
    <div style="display:grid;grid-template-columns:1fr 360px;gap:18px">
      <div style="display:flex;flex-direction:column;gap:14px">
        <!-- VELIKOST -->
        <div class="card-block">
          <h3 style="margin:0 0 10px">📏 Velikost (počet porcí)</h3>
          <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(160px,1fr));gap:8px">
            ${opts.velikosti.map(v => `
              <button class="ck-size-btn" onclick="cakePick('velikost_id','${esc(v.id)}')" style="padding:12px;border:2px solid ${String(state._cake.velikost_id) === String(v.id) ? 'var(--primary)' : 'var(--border)'};background:${String(state._cake.velikost_id) === String(v.id) ? 'var(--surface-2)' : 'var(--surface)'};border-radius:10px;cursor:pointer;text-align:left;font-family:inherit">
                <div style="font-weight:700;font-size:14px">${esc(v.label)}</div>
                <div style="font-size:11px;color:var(--text-3);margin-top:4px">${v.prumer_cm ? '⌀ ' + v.prumer_cm + ' cm · ' : ''}${v.hmotnost_g} g · ×${v.nasobic}</div>
              </button>
            `).join('')}
          </div>
        </div>

        <!-- PŘÍCHUŤ -->
        <div class="card-block">
          <h3 style="margin:0 0 10px">🍫 Příchuť</h3>
          <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(140px,1fr));gap:8px">
            ${opts.prichute.length === 0 ? `<div style="grid-column:1/-1;padding:14px;background:var(--surface-2);border-radius:8px;font-size:13px;color:var(--text-2)">Zatím žádný dort. Založ výrobek s <strong>obor = 🎂 Dort</strong> a recepturou ve <a href="#" onclick="navigate('vyrobky');return false">Výrobky</a> — objeví se tu jako příchuť.</div>` : ''}
            ${opts.prichute.map(p => `
              <button onclick="cakePick('prichut',${p.vyrobek_id})" title="Materiál z receptury: ${fmt(p.material_kc)} (base 1000 g)" style="padding:10px 12px;border:2px solid ${String(state._cake.prichut) === String(p.vyrobek_id) ? 'var(--primary)' : 'var(--border)'};background:${String(state._cake.prichut) === String(p.vyrobek_id) ? 'var(--surface-2)' : 'var(--surface)'};border-radius:8px;cursor:pointer;font-family:inherit;text-align:left">
                <span style="font-size:18px">${p.ikona}</span>
                <div style="font-weight:600;font-size:13px;margin-top:2px">${esc(p.nazev)}</div>
                <div style="font-size:11px;color:var(--primary-dark);font-weight:700;margin-top:2px">${fmt(p.cena_bez_dph)} <span style="font-weight:400;color:var(--text-3)">/ 10 porcí</span></div>
                <div style="font-size:10px;color:var(--text-3)">🧮 materiál ${fmt(p.material_kc)} · ${p.recept_polozek} surovin</div>
              </button>
            `).join('')}
          </div>
        </div>

        <!-- MOŽNOSTI (dynamické skupiny z konfigurace) -->
        ${(opts.moznosti || []).map(g => `
        <div class="card-block">
          <h3 style="margin:0 0 10px">${g.typ === 'multi' ? '☑️' : '🔘'} ${esc(g.nazev)}${g.povinne ? ' <span style="font-size:11px;color:var(--danger-text)">*</span>' : ''}</h3>
          <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(160px,1fr));gap:8px">
            ${g.volby.map(vo => {
              const isMulti = g.typ === 'multi';
              const sel = state._cake.volby[g.id];
              const active = isMulti ? (Array.isArray(sel) && sel.includes(vo.id)) : String(sel) === String(vo.id);
              return `<button onclick="cakePickVolba('${esc(g.id)}','${esc(vo.id)}',${isMulti})" style="padding:10px 12px;border:2px solid ${active ? 'var(--primary)' : 'var(--border)'};background:${active ? 'var(--surface-2)' : 'var(--surface)'};border-radius:8px;cursor:pointer;font-family:inherit;text-align:left">
                <div style="font-weight:600;font-size:13px">${esc(vo.nazev)}</div>
                <div style="font-size:11px;color:var(--text-3)">${vo.priplatek_kc > 0 ? `+${vo.priplatek_kc} Kč` : 'v ceně'}${vo.odecte_sklad ? ` · 🧮 ${fmt(vo.material_kc)}` : ''}</div>
              </button>`;
            }).join('')}
          </div>
        </div>
        `).join('')}

        <!-- TEXT + FOTKA -->
        <div class="card-block">
          <h3 style="margin:0 0 10px">📝 Text na dortu &amp; foto předlohy</h3>
          <div class="form-grid form-grid-tight">
            <div class="full">
              <label class="form-label">Text na dortu (max ${opts.text_na_dortu.max_chars} znaků) <span style="font-weight:400;font-size:11px;color:var(--text-3)">+ ${opts.text_na_dortu.priplatek_kc} Kč pokud vyplníš</span></label>
              <input class="form-input" id="cake-text" maxlength="${opts.text_na_dortu.max_chars}" value="${esc(state._cake.text || '')}" placeholder="Např. Všechno nejlepší, Aničko!" oninput="state._cake.text=this.value;cakeRecalc()">
            </div>
            <div class="full">
              <label class="form-label">📸 Fotka předlohy <span style="font-weight:400;font-size:11px;color:var(--text-3)">(volitelné, zdarma — jen pro inspiraci)</span></label>
              <div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap">
                <input class="form-input" id="cake-foto" style="flex:1;min-width:200px" value="${esc(state._cake.foto || '')}" placeholder="https://… nebo nahraj fotku →" oninput="state._cake.foto=this.value;cakeRenderPredlohaNahled()">
                <input type="file" id="cake-foto-file" accept="image/jpeg,image/png,image/webp" style="display:none" onchange="cakeUploadPredloha(this)">
                <button type="button" class="btn-secondary" id="cake-foto-btn" onclick="document.getElementById('cake-foto-file').click()" style="white-space:nowrap">⬆️ Nahrát fotku</button>
              </div>
              <div id="cake-foto-nahled" style="margin-top:8px"></div>
            </div>
          </div>
        </div>
      </div>

      <!-- SUMMARY / QUOTE -->
      <div style="position:sticky;top:80px;align-self:start">
        <div class="card-block" style="background:linear-gradient(180deg,#FFF8E5,#fff);border:2px solid var(--primary)">
          <h3 style="margin:0 0 12px">💰 Kalkulace</h3>
          <div id="cake-quote">⏳</div>
        </div>
      </div>
    </div>
  `;
  cakeRecalc();
  cakeRenderPredlohaNahled();
}

window.cakePick = function(field, value) {
  state._cake = state._cake || {};
  state._cake[field] = value;
  renderCakeConfiguratorBody();
};

// 🆕 v3.0.299 — výběr volby ve skupině možností (single = nahradí, multi = toggle).
window.cakePickVolba = function(skupinaId, volbaId, isMulti) {
  state._cake = state._cake || {};
  state._cake.volby = state._cake.volby || {};
  if (isMulti) {
    const cur = Array.isArray(state._cake.volby[skupinaId]) ? state._cake.volby[skupinaId] : [];
    state._cake.volby[skupinaId] = cur.includes(volbaId) ? cur.filter(x => x !== volbaId) : [...cur, volbaId];
  } else {
    state._cake.volby[skupinaId] = volbaId;
  }
  renderCakeConfiguratorBody();
};

// 🆕 v3.0.305 — náhled fotky předlohy (URL i nahraná) + možnost odebrat
window.cakeRenderPredlohaNahled = function() {
  const host = document.getElementById('cake-foto-nahled');
  if (!host) return;
  const url = ((state._cake && state._cake.foto) || '').trim();
  if (!url) { host.innerHTML = ''; return; }
  host.innerHTML = `
    <div style="display:inline-flex;align-items:center;gap:10px;border:1px solid var(--border);border-radius:8px;padding:6px 10px;background:var(--bg-2,#fafafa)">
      <img src="${esc(url)}" alt="předloha" style="height:56px;width:56px;object-fit:cover;border-radius:6px;background:#eee" onerror="this.style.opacity=.3;this.alt='⚠ nelze načíst'">
      <span style="font-size:11px;color:var(--text-3);max-width:260px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap">${esc(url)}</span>
      <button type="button" title="Odebrat fotku" onclick="state._cake.foto='';var i=document.getElementById('cake-foto');if(i)i.value='';cakeRenderPredlohaNahled();" style="border:none;background:none;cursor:pointer;color:var(--danger-text,#DC2626);font-size:18px;line-height:1;padding:0 4px">×</button>
    </div>`;
};

// 🆕 v3.0.305 — upload fotky předlohy → /uploads/predlohy/ → URL do state._cake.foto
window.cakeUploadPredloha = async function(input) {
  const file = input && input.files && input.files[0];
  if (!file) return;
  const btn = document.getElementById('cake-foto-btn');
  const orig = btn ? btn.textContent : '';
  if (btn) { btn.disabled = true; btn.textContent = '⏳ Nahrávám…'; }
  try {
    const fd = new FormData();
    fd.append('foto', file);
    const res = await api('admin_cake_configurator.php?action=upload_predloha', { method: 'POST', body: fd });
    state._cake = state._cake || {};
    state._cake.foto = res.url;
    const i = document.getElementById('cake-foto');
    if (i) i.value = res.url;
    cakeRenderPredlohaNahled();
  } catch (e) {
    alert('Chyba při nahrávání fotky: ' + e.message);
  } finally {
    if (btn) { btn.disabled = false; btn.textContent = orig; }
    input.value = '';   // reset → lze nahrát stejný soubor znovu
  }
};

// ═══════════════════ ADMIN EDITOR KONFIGURÁTORU (v3.0.299) ═══════════════════
async function renderCakeConfigSettings() {
  const body = document.getElementById('cake-tab-body');
  if (!body) return;
  body.innerHTML = skeletonCards(2);
  try {
    const [cfg, vyr] = await Promise.all([
      api('admin_cake_configurator.php?action=config'),
      api('admin_vyrobky.php'),
    ]);
    let sur = [];
    try { const sr = await api('admin_suroviny.php'); sur = Array.isArray(sr) ? sr : (sr.suroviny || sr.data || sr.polozky || []); } catch (e) {}
    state._cakeCfg = cfg;
    state._cakeCfgSur = sur.map(s => ({ id: s.id, nazev: s.nazev, jednotka: s.jednotka }));
    state._cakeCfgVyr = (vyr.vyrobky || []).map(v => ({ id: v.id, nazev: v.nazev }));
  } catch (e) { body.innerHTML = `<div class="alert err">${esc(e.message)}</div>`; return; }
  cakeCfgRender();
}

function cakeCfgRender() {
  const body = document.getElementById('cake-tab-body');
  if (!body) return;
  const cfg = state._cakeCfg;
  const surOpts = (sel, typ) => {
    const list = typ === 'vyrobek' ? state._cakeCfgVyr : state._cakeCfgSur;
    return '<option value="">— vyber —</option>' + (list || []).map(x => `<option value="${x.id}" ${String(sel) === String(x.id) ? 'selected' : ''}>${esc(x.nazev)}</option>`).join('');
  };
  body.innerHTML = `
    <div class="card-block" style="margin-bottom:14px;display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:10px">
      <div style="font-size:13px;color:var(--text-2)">Uprav velikosti, skupiny možností a volby. Volba může <strong>linkovat surovinu/výrobek</strong> → odečte se ze skladu + počítá do kalkulace.</div>
      <button class="btn-primary btn-green" onclick="cakeCfgSave()">💾 Uložit konfiguraci</button>
    </div>

    <!-- VELIKOSTI -->
    <div class="card-block" style="margin-bottom:14px">
      <h3 style="margin:0 0 10px">📏 Velikosti</h3>
      <table class="table" style="width:100%"><thead><tr>
        <th>Popis</th><th style="width:90px">Porcí</th><th style="width:110px">Hmotnost (g)</th><th style="width:90px">Násobič</th><th style="width:80px">⌀ cm</th><th style="width:40px"></th>
      </tr></thead><tbody>
        ${cfg.velikosti.map((v, i) => `<tr>
          <td><input class="form-input" value="${esc(v.label || '')}" onchange="state._cakeCfg.velikosti[${i}].label=this.value"></td>
          <td><input class="form-input" type="number" value="${v.porci || 0}" onchange="state._cakeCfg.velikosti[${i}].porci=parseInt(this.value)||0"></td>
          <td><input class="form-input" type="number" value="${v.hmotnost_g || 0}" onchange="state._cakeCfg.velikosti[${i}].hmotnost_g=parseFloat(this.value)||0"></td>
          <td><input class="form-input" type="number" step="0.1" value="${v.nasobic ?? 1}" onchange="state._cakeCfg.velikosti[${i}].nasobic=parseFloat(this.value)||1"></td>
          <td><input class="form-input" type="number" value="${v.prumer_cm || 0}" onchange="state._cakeCfg.velikosti[${i}].prumer_cm=parseInt(this.value)||0"></td>
          <td><button class="btn-icon" title="Smazat" onclick="cakeCfgDel('velikosti',${i})">🗑️</button></td>
        </tr>`).join('')}
      </tbody></table>
      <button class="btn-secondary" style="margin-top:8px" onclick="cakeCfgAddVelikost()">➕ Přidat velikost</button>
      <div style="font-size:11px;color:var(--text-3);margin-top:6px">Cena = cena příchuti (dort výrobek / 10 porcí) × <strong>násobič</strong>. Recept se škáluje stejně.</div>
    </div>

    <!-- SKUPINY MOŽNOSTÍ -->
    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:8px">
      <h3 style="margin:0">🎛️ Skupiny možností</h3>
      <button class="btn-secondary" onclick="cakeCfgAddSkupina()">➕ Přidat skupinu</button>
    </div>
    ${cfg.moznosti.map((g, gi) => `
      <div class="card-block" style="margin-bottom:12px;border-left:3px solid var(--primary)">
        <div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;margin-bottom:8px">
          <input class="form-input" style="flex:1;min-width:160px;font-weight:700" value="${esc(g.nazev || '')}" onchange="state._cakeCfg.moznosti[${gi}].nazev=this.value">
          <select class="form-input" style="width:140px" onchange="state._cakeCfg.moznosti[${gi}].typ=this.value">
            <option value="single" ${g.typ !== 'multi' ? 'selected' : ''}>Jedna volba</option>
            <option value="multi" ${g.typ === 'multi' ? 'selected' : ''}>Více voleb</option>
          </select>
          <label style="font-size:12px;display:flex;align-items:center;gap:4px"><input type="checkbox" ${g.povinne ? 'checked' : ''} onchange="state._cakeCfg.moznosti[${gi}].povinne=this.checked">povinné</label>
          <button class="btn-icon" title="Smazat skupinu" onclick="cakeCfgDel('moznosti',${gi})">🗑️</button>
        </div>
        <table class="table" style="width:100%;font-size:12px"><thead><tr>
          <th>Volba</th><th style="width:90px">Příplatek</th><th style="width:110px">Link</th><th style="width:170px">Surovina/výrobek</th><th style="width:80px">Množ.</th><th style="width:60px">Jedn.</th><th style="width:36px"></th>
        </tr></thead><tbody>
          ${(g.volby || []).map((vo, vi) => `<tr>
            <td><input class="form-input" value="${esc(vo.nazev || '')}" onchange="state._cakeCfg.moznosti[${gi}].volby[${vi}].nazev=this.value"></td>
            <td><input class="form-input" type="number" value="${vo.priplatek_kc || 0}" onchange="state._cakeCfg.moznosti[${gi}].volby[${vi}].priplatek_kc=parseFloat(this.value)||0"></td>
            <td><select class="form-input" onchange="cakeCfgVolbaLinkTyp(${gi},${vi},this.value)">
              <option value="none" ${(vo.link_typ || 'none') === 'none' ? 'selected' : ''}>—</option>
              <option value="surovina" ${vo.link_typ === 'surovina' ? 'selected' : ''}>Surovina</option>
              <option value="vyrobek" ${vo.link_typ === 'vyrobek' ? 'selected' : ''}>Výrobek</option>
            </select></td>
            <td>${(vo.link_typ || 'none') === 'none' ? '<span style="color:var(--text-3)">—</span>' : `<select class="form-input" onchange="state._cakeCfg.moznosti[${gi}].volby[${vi}].link_id=parseInt(this.value)||null">${surOpts(vo.link_id, vo.link_typ)}</select>`}</td>
            <td>${(vo.link_typ || 'none') === 'none' ? '' : `<input class="form-input" type="number" step="0.1" value="${vo.mnozstvi || 0}" onchange="state._cakeCfg.moznosti[${gi}].volby[${vi}].mnozstvi=parseFloat(this.value)||0">`}</td>
            <td>${(vo.link_typ || 'none') === 'none' ? '' : `<input class="form-input" value="${esc(vo.jednotka || 'g')}" onchange="state._cakeCfg.moznosti[${gi}].volby[${vi}].jednotka=this.value">`}</td>
            <td><button class="btn-icon" title="Smazat volbu" onclick="cakeCfgDelVolba(${gi},${vi})">🗑️</button></td>
          </tr>`).join('')}
        </tbody></table>
        <button class="btn-secondary" style="margin-top:6px;font-size:12px" onclick="cakeCfgAddVolba(${gi})">➕ Přidat volbu</button>
      </div>
    `).join('')}

    <!-- TEXT NA DORTU -->
    <div class="card-block" style="margin-bottom:14px">
      <h3 style="margin:0 0 10px">📝 Text na dortu</h3>
      <div style="display:flex;gap:14px;align-items:center;flex-wrap:wrap;font-size:13px">
        <label style="display:flex;align-items:center;gap:6px"><input type="checkbox" ${cfg.text_na_dortu?.povolit ? 'checked' : ''} onchange="state._cakeCfg.text_na_dortu.povolit=this.checked">povolit</label>
        <label>Příplatek <input class="form-input" style="width:90px;display:inline-block" type="number" value="${cfg.text_na_dortu?.priplatek_kc || 0}" onchange="state._cakeCfg.text_na_dortu.priplatek_kc=parseFloat(this.value)||0"> Kč</label>
        <label>Max znaků <input class="form-input" style="width:80px;display:inline-block" type="number" value="${cfg.text_na_dortu?.max_chars || 40}" onchange="state._cakeCfg.text_na_dortu.max_chars=parseInt(this.value)||40"></label>
      </div>
    </div>

    <div style="text-align:right"><button class="btn-primary btn-green btn-big-action" onclick="cakeCfgSave()">💾 Uložit konfiguraci</button></div>
  `;
}

window.cakeCfgAddVelikost = function() { state._cakeCfg.velikosti.push({ id: 'v' + Date.now().toString(36), label: 'Nová velikost', porci: 10, hmotnost_g: 1000, nasobic: 1, prumer_cm: 0 }); cakeCfgRender(); };
window.cakeCfgAddSkupina = function() { state._cakeCfg.moznosti.push({ id: 'g' + Date.now().toString(36), nazev: 'Nová skupina', typ: 'single', povinne: false, volby: [{ id: 'o' + Date.now().toString(36), nazev: 'Bez', priplatek_kc: 0, link_typ: 'none', link_id: null, mnozstvi: 0, jednotka: '' }] }); cakeCfgRender(); };
window.cakeCfgAddVolba = function(gi) { state._cakeCfg.moznosti[gi].volby.push({ id: 'o' + Date.now().toString(36), nazev: 'Nová volba', priplatek_kc: 0, link_typ: 'none', link_id: null, mnozstvi: 0, jednotka: 'g' }); cakeCfgRender(); };
window.cakeCfgDel = function(klic, i) { state._cakeCfg[klic].splice(i, 1); cakeCfgRender(); };
window.cakeCfgDelVolba = function(gi, vi) { state._cakeCfg.moznosti[gi].volby.splice(vi, 1); cakeCfgRender(); };
window.cakeCfgVolbaLinkTyp = function(gi, vi, typ) { const vo = state._cakeCfg.moznosti[gi].volby[vi]; vo.link_typ = typ; if (typ === 'none') { vo.link_id = null; } if (typ !== 'none' && !vo.jednotka) vo.jednotka = (typ === 'vyrobek' ? 'ks' : 'g'); cakeCfgRender(); };
window.cakeCfgSave = async function() {
  try {
    const r = await api('admin_cake_configurator.php?action=save_config', { method: 'POST', body: JSON.stringify(state._cakeCfg) });
    state._cakeCfg = r.config || state._cakeCfg;
    if (typeof toast === 'function') toast('✅ Konfigurace uložena', 'success'); else alert('Uloženo');
    cakeCfgRender();
  } catch (e) { alert('Chyba uložení: ' + e.message + '\n(Uložení vyžaduje super-admin práva.)'); }
};

// ───────── KAPACITA PEČENÍ ─────────
async function renderCakeCapacity() {
  const body = document.getElementById('cake-tab-body');
  if (!body) return;
  body.innerHTML = skeletonCards(3);
  let data;
  try { data = await api('admin_daily_capacity.php'); }
  catch (e) { body.innerHTML = `<div class="alert err">${esc(e.message)}</div>`; return; }

  const dayNames = ['', 'Po', 'Út', 'St', 'Čt', 'Pá', 'So', 'Ne'];
  const today = new Date().toISOString().slice(0, 10);

  body.innerHTML = `
    <div class="card-block" style="margin-bottom:14px">
      <h3 style="margin:0 0 8px">⚙️ Výchozí denní kapacita</h3>
      <p style="font-size:12px;color:var(--text-3);margin-bottom:12px">Maximální počty pro běžný den (lze přepsat per-den níže).</p>
      <div class="form-grid form-grid-tight">
        <div>
          <label class="form-label">🎂 Max dortů / den</label>
          <input type="number" class="form-input" id="def-dortu" min="0" value="${data.defaults.max_dortu}">
        </div>
        <div>
          <label class="form-label">🥪 Max chlebíčků / den</label>
          <input type="number" class="form-input" id="def-chlebicku" min="0" value="${data.defaults.max_chlebicku}">
        </div>
        <div>
          <label class="form-label">🧁 Max zákusků / den</label>
          <input type="number" class="form-input" id="def-zakousku" min="0" value="${data.defaults.max_zakousku}">
        </div>
      </div>
      <button class="btn-primary btn-green" style="margin-top:10px" onclick="saveDailyDefaults()">💾 Uložit defaulty</button>
    </div>

    <div class="card-block">
      <h3 style="margin:0 0 10px">📅 Kalendář příštích 30 dní</h3>
      <p style="font-size:12px;color:var(--text-3);margin-bottom:12px">Klikni na den pro úpravu (přepiš max kapacity, zavři den, přidej poznámku).</p>
      <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(140px,1fr));gap:6px">
        ${data.days.map(d => {
          const date = new Date(d.date);
          const isToday = d.date === today;
          const fillPct = d.max_dortu > 0 ? Math.round((d.obsazenost.dortu / d.max_dortu) * 100) : 0;
          const fullColor = d.zavreno ? '#FEE2E2' : (fillPct >= 100 ? '#FEE2E2' : fillPct >= 75 ? '#FEF3C7' : '#DCFCE7');
          return `
            <button onclick="editDailyCapacity('${esc(d.date)}')" style="padding:10px;background:${fullColor};border:2px solid ${isToday ? 'var(--primary)' : 'transparent'};border-radius:8px;cursor:pointer;font-family:inherit;text-align:left">
              <div style="font-size:11px;color:var(--text-3);font-weight:600">${dayNames[d.weekday]}</div>
              <div style="font-size:16px;font-weight:700">${date.getDate()}.${date.getMonth() + 1}.</div>
              ${d.zavreno
                ? `<div style="font-size:11px;color:#991B1B;font-weight:700;margin-top:4px">🚫 Zavřeno</div>`
                : `<div style="font-size:11px;margin-top:4px"><strong>${d.obsazenost.dortu}</strong> / ${d.max_dortu} dortů</div>
                   <div style="font-size:10px;color:var(--text-3)">${d.volna_kapacita} volných</div>`
              }
              ${d.custom ? '<div style="font-size:9px;color:var(--primary-dark);font-weight:700;margin-top:2px">✏️ vlastní</div>' : ''}
            </button>
          `;
        }).join('')}
      </div>
    </div>
  `;
}

window.saveDailyDefaults = async function() {
  try {
    await api('admin_daily_capacity.php', { method: 'POST', body: JSON.stringify({
      save_defaults: true,
      max_dortu:     parseInt(document.getElementById('def-dortu').value) || 0,
      max_chlebicku: parseInt(document.getElementById('def-chlebicku').value) || 0,
      max_zakousku:  parseInt(document.getElementById('def-zakousku').value) || 0,
    })});
    toastSuccess('Defaultní kapacita uložena');
    renderCakeCapacity();
  } catch (e) { alert('Chyba: ' + e.message); }
};

window.editDailyCapacity = async function(date) {
  let d;
  try { d = await api('admin_daily_capacity.php?date=' + date); }
  catch (e) { return alert('Chyba: ' + e.message); }

  openModal('📅 Kapacita pro ' + new Date(date).toLocaleDateString('cs-CZ'), `
    <div style="background:#FEF3C7;border-left:3px solid #FBBF24;padding:10px;border-radius:8px;margin-bottom:14px;font-size:12.5px;color:#92400E">
      📊 Obsazenost: <strong>${d.obsazenost.dortu}</strong> dortů · <strong>${d.obsazenost.chlebicku}</strong> chlebíčků · <strong>${d.obsazenost.zakousku}</strong> zákusků (z aktuálních objednávek)
    </div>
    <div class="form-grid form-grid-tight">
      <div>
        <label class="form-label">🎂 Max dortů</label>
        <input type="number" class="form-input" id="cap-dortu" min="0" value="${d.max_dortu}">
      </div>
      <div>
        <label class="form-label">🥪 Max chlebíčků</label>
        <input type="number" class="form-input" id="cap-chlebicku" min="0" value="${d.max_chlebicku}">
      </div>
      <div>
        <label class="form-label">🧁 Max zákusků</label>
        <input type="number" class="form-input" id="cap-zakousku" min="0" value="${d.max_zakousku}">
      </div>
      <div class="full">
        <label class="form-label">Poznámka pro tým</label>
        <input class="form-input" id="cap-poznamka" value="${esc(d.poznamka || '')}" placeholder="např. Speciální zakázka pro Brno">
      </div>
      <div class="full">
        <label class="checkbox-row" style="display:flex;align-items:center;gap:10px;padding:10px;background:#FEE2E2;border-radius:8px;cursor:pointer">
          <input type="checkbox" id="cap-zavreno" ${d.zavreno ? 'checked' : ''} style="width:18px;height:18px">
          <span>🚫 <strong>Zavřeno</strong> — nepřijímat objednávky na tento den</span>
        </label>
      </div>
    </div>
    <div class="form-actions">
      ${d.custom ? `<button class="btn-secondary" onclick="resetDailyCapacity('${esc(date)}')" style="margin-right:auto">↩️ Reset na default</button>` : ''}
      <button class="btn-secondary" onclick="closeModal()">Zrušit</button>
      <button class="btn-primary btn-green" onclick="saveDailyCapacity('${esc(date)}')">💾 Uložit</button>
    </div>
  `);
};

window.saveDailyCapacity = async function(date) {
  try {
    await api('admin_daily_capacity.php', { method: 'POST', body: JSON.stringify({
      date,
      max_dortu:     parseInt(document.getElementById('cap-dortu').value) || 0,
      max_chlebicku: parseInt(document.getElementById('cap-chlebicku').value) || 0,
      max_zakousku:  parseInt(document.getElementById('cap-zakousku').value) || 0,
      poznamka:      document.getElementById('cap-poznamka').value,
      zavreno:       document.getElementById('cap-zavreno').checked,
    })});
    closeModal();
    toastSuccess('Kapacita uložena');
    renderCakeCapacity();
  } catch (e) { alert('Chyba: ' + e.message); }
};

window.resetDailyCapacity = async function(date) {
  if (!(await confirmDialog({ title: 'Reset kapacity?', msg: 'Vrátí den na výchozí kapacitu.', danger: false }))) return;
  try {
    await api('admin_daily_capacity.php?date=' + date, { method: 'DELETE' });
    closeModal();
    toastSuccess('Kapacita resetovaná');
    renderCakeCapacity();
  } catch (e) { alert('Chyba: ' + e.message); }
};

// ───────── GALERIE INSPIRACÍ ─────────
async function renderCakeGallery() {
  const body = document.getElementById('cake-tab-body');
  if (!body) return;
  body.innerHTML = skeletonCards(3);
  let g;
  try { g = await api('admin_cake_gallery.php'); }
  catch (e) {
    body.innerHTML = `<div class="alert err">${esc(e.message)}</div>`; return;
  }
  const photos = g.photos || [];

  body.innerHTML = `
    <div class="card-block" style="margin-bottom:14px">
      <h3 style="margin:0 0 8px">🖼️ Galerie inspirací</h3>
      <p style="font-size:12px;color:var(--text-3);margin-bottom:10px">Fotky hotových dortů, které ukazuješ zákazníkům jako inspiraci. Můžou si je vybrat při objednávce.</p>
      <button class="btn-primary btn-green" onclick="addGalleryPhoto()">➕ Přidat foto</button>
    </div>

    ${photos.length === 0 ? emptyState({
      icon: '🖼️', title: 'Zatím žádné fotky',
      msg: 'Přidej první foto dortu jako inspirace pro zákazníky.',
      actions: '<button class="btn-primary btn-green" onclick="addGalleryPhoto()">➕ Přidat foto</button>',
    }) : `
      <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(200px,1fr));gap:12px">
        ${photos.map(p => `
          <div style="position:relative;background:var(--surface);border:1px solid var(--border);border-radius:12px;overflow:hidden;aspect-ratio:1">
            <img src="${esc(p.url)}" style="width:100%;height:100%;object-fit:cover">
            <div style="position:absolute;bottom:0;left:0;right:0;background:linear-gradient(180deg,transparent,rgba(0,0,0,0.7));padding:8px;color:#fff;font-size:11px">
              <div style="font-weight:600">${esc(p.nazev || '')}</div>
              ${p.tag ? `<div style="opacity:0.8">${esc(p.tag)}</div>` : ''}
            </div>
            <button onclick="deleteGalleryPhoto(${p.id})" style="position:absolute;top:8px;right:8px;background:rgba(0,0,0,0.6);color:#fff;border:none;width:28px;height:28px;border-radius:50%;cursor:pointer;font-size:14px">🗑️</button>
          </div>
        `).join('')}
      </div>
    `}
  `;
}

window.addGalleryPhoto = async function() {
  const url = (await promptDialog({ msg: 'URL fotky (zatím podporujeme jen URL — upload bude v dalším batch):', value: '' }));
  if (!url) return;
  const nazev = (await promptDialog({ msg: 'Název / popis fotky:', value: '' }));
  const tag   = (await promptDialog({ msg: 'Tag (např. svatba, narozeniny, firemní):', value: '' }));
  try {
    await api('admin_cake_gallery.php', { method: 'POST', body: JSON.stringify({ url, nazev, tag }) });
    toastSuccess('Foto přidáno');
    renderCakeGallery();
  } catch (e) { alert('Chyba: ' + e.message); }
};

window.deleteGalleryPhoto = async function(id) {
  if (!(await confirmDialog({ title: 'Smazat foto?', msg: 'Foto se odebere z galerie.', danger: true, okText: 'Smazat' }))) return;
  try {
    await api('admin_cake_gallery.php?id=' + id, { method: 'DELETE' });
    toastSuccess('Foto smazáno');
    renderCakeGallery();
  } catch (e) { alert('Chyba: ' + e.message); }
};

window.cakeRecalc = async function() {
  const host = document.getElementById('cake-quote');
  if (!host) return;
  // Defenzivní init — funkce může být volaná před renderCakeConfiguratorBody (oninput race, refresh apod.)
  if (!state._cake || typeof state._cake !== 'object') state._cake = {};
  if (state._cake.prichut === undefined) state._cake.prichut = null;
  if (!state._cake.volby || typeof state._cake.volby !== 'object') state._cake.volby = {};
  const text = document.getElementById('cake-text')?.value || '';
  state._cake.text = text;
  try {
    const r = await api('admin_cake_configurator.php?action=quote', {
      method: 'POST',
      body: JSON.stringify({
        vyrobek_id: state._cake.prichut, velikost_id: state._cake.velikost_id,
        volby: state._cake.volby || {}, text: state._cake.text,
      }),
    });
    host.innerHTML = `
      <div style="font-size:11px;text-transform:uppercase;letter-spacing:0.05em;color:var(--text-3);font-weight:700">Velikost</div>
      <div style="font-weight:600;font-size:14px;margin-bottom:8px">${esc(r.velikost.label)}</div>

      <div style="border-top:1px solid var(--border);margin:8px 0;padding-top:8px">
        ${r.polozky.map(p => `
          <div style="display:flex;justify-content:space-between;font-size:12.5px;padding:3px 0">
            <span>${esc(p.nazev)}</span>
            <span style="font-variant-numeric:tabular-nums">${fmt(p.cena_kc)}</span>
          </div>
        `).join('')}
      </div>

      <div style="display:flex;justify-content:space-between;font-size:12px;padding:3px 0">
        <span>Bez DPH</span>
        <span style="font-variant-numeric:tabular-nums">${fmt(r.cena_bez_dph)}</span>
      </div>
      <div style="display:flex;justify-content:space-between;font-size:12px;padding:3px 0;color:var(--text-3)">
        <span>DPH ${r.sazba_dph}%</span>
        <span style="font-variant-numeric:tabular-nums">${fmt(r.cena_dph)}</span>
      </div>
      <div style="display:flex;justify-content:space-between;font-size:18px;padding:8px 0;margin-top:6px;border-top:2px solid var(--primary);font-weight:800;color:var(--primary-dark)">
        <span>Celkem s DPH</span>
        <span style="font-variant-numeric:tabular-nums">${fmt(r.cena_s_dph)}</span>
      </div>
      <div style="font-size:11px;color:var(--text-3);text-align:right;margin-top:2px">${fmt(r.cena_per_porci)} / porci</div>

      ${r.kalkulace ? `
      <div style="background:var(--surface-2);border-radius:8px;margin-top:10px;padding:8px 10px;font-size:11.5px">
        <div style="font-weight:700;color:var(--text-2);margin-bottom:4px">🧮 Z kalkulace (receptura × ${r.velikost.skala})</div>
        <div style="display:flex;justify-content:space-between"><span>Materiál (suroviny)</span><span style="font-variant-numeric:tabular-nums">${fmt(r.kalkulace.material_kc)}</span></div>
        <div style="display:flex;justify-content:space-between;color:var(--success-text)"><span>Marže</span><span style="font-variant-numeric:tabular-nums">${fmt(r.kalkulace.marze_kc)} · ${r.kalkulace.marze_pct}%</span></div>
        <div style="font-size:10px;color:var(--text-3);margin-top:3px">📦 ${r.kalkulace.recept_polozek} surovin — odečte se ze skladu při výrobě</div>
      </div>` : ''}

      <div style="background:#DCFCE7;color:#166534;padding:8px 10px;border-radius:8px;margin-top:12px;font-size:12px;text-align:center">
        ⏱️ Doba přípravy: <strong>${r.doba_pripravy_dni} ${r.doba_pripravy_dni === 1 ? 'den' : (r.doba_pripravy_dni < 5 ? 'dny' : 'dní')}</strong>
      </div>

      <button class="btn-primary btn-green btn-big-action" style="width:100%;margin-top:12px;padding:14px;font-size:15px;font-weight:700" onclick="cakeCreateOrder(${JSON.stringify(r).replace(/"/g, '&quot;')})">📋 Vytvořit objednávku</button>
    `;
  } catch (e) {
    host.innerHTML = `<div style="color:var(--danger-text)">Chyba: ${esc(e.message)}</div>`;
  }
};

window.cakeCreateOrder = async function(quote) {
  // Načti odběratele
  let odb;
  try { odb = await api('admin_odberatele.php'); }
  catch (e) { return alert('Chyba načtení odběratelů: ' + e.message); }

  // 🐛 v3.0.295 — admin_odberatele.php vrací HOLÉ pole, ne {odberatele:[]} → dřív vždy
  //   „Nejsou žádní odběratelé" (dead-end konfigurátoru, i když odběratelé existovali).
  const odberatele = Array.isArray(odb) ? odb : (odb.odberatele || odb.data || []);
  if (odberatele.length === 0) {
    alert('Nejsou žádní odběratelé. Vytvoř nejdřív odběratele.');
    return;
  }

  const today = new Date();
  const minDate = new Date(today.getTime() + (quote.doba_pripravy_dni || 2) * 86400000);
  const minDateStr = minDate.toISOString().slice(0, 10);

  openModal('🎂 Vytvořit objednávku dortu', `
    <div style="background:#FFF8E5;border-left:3px solid #BA7517;padding:12px;border-radius:8px;margin-bottom:14px;font-size:13px;color:#854F0B">
      📋 <strong>${esc(quote.velikost.label)}</strong> · ${esc(quote.prichut.nazev)}${(quote.volby || []).length ? ' · ' + quote.volby.map(v => esc(v.nazev)).join(', ') : ''}${quote.text ? ' · „' + esc(quote.text) + '"' : ''}<br>
      💰 ${fmt(quote.cena_s_dph)} celkem · ⏱️ ${quote.doba_pripravy_dni} dní příprava
    </div>
    <div class="form-grid form-grid-tight">
      <div class="full"><label class="form-label">Odběratel *</label>
        <select class="form-input" id="cake-odb">
          ${odberatele.map(o => `<option value="${o.id}">${esc(o.nazev)}${o.ico ? ' · IČ ' + esc(o.ico) : ''}</option>`).join('')}
        </select>
      </div>
      <div class="full"><label class="form-label">📅 Datum dodání * <span style="font-weight:400;color:var(--text-3);font-size:11px">(min ${minDate.toLocaleDateString('cs-CZ')})</span></label>
        <input type="date" class="form-input" id="cake-datum" value="${minDateStr}" min="${minDateStr}">
      </div>
    </div>
    <div class="form-actions">
      <button class="btn-secondary" onclick="closeModal()">Zrušit</button>
      <button class="btn-primary btn-green" onclick="cakeSubmitOrder()">✓ Vytvořit objednávku</button>
    </div>
  `);
};

window.cakeSubmitOrder = async function() {
  const odberatelId = parseInt(document.getElementById('cake-odb').value);
  const datum = document.getElementById('cake-datum').value;
  if (!odberatelId || !datum) { alert('Vyplň odběratele a datum.'); return; }
  if (!state._cake || typeof state._cake !== 'object') {
    alert('Nejprve nastav velikost / příchuť / dekoraci v konfigurátoru.');
    return;
  }
  try {
    const r = await api('admin_cake_configurator.php?action=create_order', {
      method: 'POST',
      body: JSON.stringify({
        odberatel_id: odberatelId,
        datum_dodani: datum,
        vyrobek_id: state._cake.prichut,
        velikost_id: state._cake.velikost_id,
        volby: state._cake.volby || {},
        text: state._cake.text,
        foto: (state._cake.foto || '').trim(),
      }),
    });
    closeModal();
    toastSuccess(t('toast_order_created_amount', { cislo: r.cislo, amount: fmt(r.castka) }));
    setTimeout(() => navigate('objednavky'), 600);
  } catch (e) { alert('Chyba: ' + e.message); }
};

