// =============================================================
// 🎉 CATERING — outer page s 4 tabs
// =============================================================
async function renderCateringPage() {
  const tab = state._catTab || 'orders';
  const c = document.getElementById('content');
  c.innerHTML = `
    <div class="page-head">
      <div>
        <h1 class="page-title">🎉 Velký catering</h1>
        <p class="page-sub">Firemní objednávky · Cenové úrovně · PDF smlouvy · Záloha 50 %</p>
      </div>
      <div style="display:flex;gap:8px;flex-wrap:wrap;align-items:center">
        <button class="btn-secondary" onclick="navigate('dashboard')">← Dashboard</button>
      </div>
    </div>
    <div class="nastaveni-tabs" role="tablist" style="margin-bottom:14px">
      <button class="nastaveni-tab ${tab === 'orders' ? 'active' : ''}" onclick="state._catTab='orders';renderCateringPage()">🏢 Firemní objednávky</button>
      <button class="nastaveni-tab ${tab === 'tiers' ? 'active' : ''}" onclick="state._catTab='tiers';renderCateringPage()">👥 Cenové úrovně</button>
      <button class="nastaveni-tab ${tab === 'pdf' ? 'active' : ''}" onclick="state._catTab='pdf';renderCateringPage()">📑 Smlouvy a nabídky</button>
      <button class="nastaveni-tab ${tab === 'deposits' ? 'active' : ''}" onclick="state._catTab='deposits';renderCateringPage()">🎯 Zálohy 50 %</button>
    </div>
    <div id="cat-tab-body"></div>
  `;

  if (tab === 'tiers')    return renderCateringPriceTiers();
  if (tab === 'pdf')      return renderCateringPdfs();
  if (tab === 'deposits') return renderCateringDeposits();
  return renderCateringOrders();
}

async function renderCateringOrders() {
  const c = document.getElementById('cat-tab-body') || document.getElementById('content');
  c.innerHTML = `
    <div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:10px;margin-bottom:14px">
      <div></div>
      <button class="btn-primary btn-green btn-big-action" onclick="newCateringOrder()" style="font-size:15px;padding:11px 22px">+ Nová akce</button>
    </div>
    <div id="cat-body">${skeletonCards(3)}</div>
  `;

  let data;
  try { data = await api('admin_catering_orders.php'); }
  catch (e) {
    document.getElementById('cat-body').innerHTML = `<div class="alert err">${esc(e.message)}</div>`;
    return;
  }

  const akce = data.akce || [];
  const stavBarvy = {
    'poptavka':     { bg: '#FEF3C7', fg: '#92400E', label: '💭 Poptávka' },
    'nabidka':      { bg: '#DBEAFE', fg: '#1E40AF', label: '📋 Nabídka odeslána' },
    'potvrzeno':    { bg: '#DCFCE7', fg: '#166534', label: '✅ Potvrzeno' },
    'zaloha_uhraz': { bg: '#A7F3D0', fg: '#065F46', label: '🧾 Záloha uhrazena' },
    'realizace':    { bg: '#FED7AA', fg: '#9A3412', label: '🍽️ Realizace' },
    'dokonceno':    { bg: '#E5E7EB', fg: '#374151', label: '✓ Dokončeno' },
    'zruseno':      { bg: '#FEE2E2', fg: '#991B1B', label: '🚫 Zrušeno' },
  };

  document.getElementById('cat-body').innerHTML = `
    ${akce.length === 0 ? emptyState({
      icon: '🎉', title: 'Zatím žádné catering akce',
      msg: 'Vytvoř první firemní/velkokapacitní akci. Můžeš sledovat status, zálohy, generovat smlouvy.',
      actions: '<button class="btn-primary btn-green" onclick="newCateringOrder()">+ Nová akce</button>',
    }) : `
      <div style="display:flex;flex-direction:column;gap:10px">
        ${akce.map(a => {
          const s = stavBarvy[a.stav] || stavBarvy.poptavka;
          const datumAkce = new Date(a.datum_akce);
          return `
            <div class="card-block" style="display:grid;grid-template-columns:auto 1fr auto;gap:14px;align-items:center;cursor:pointer" onclick="editCateringOrder(${a.id})">
              <div style="text-align:center;background:var(--surface-2);padding:10px;border-radius:10px;min-width:80px">
                <div style="font-size:11px;color:var(--text-3);font-weight:600;text-transform:uppercase">${esc(['', 'Po','Út','St','Čt','Pá','So','Ne'][datumAkce.getDay() || 7])}</div>
                <div style="font-size:22px;font-weight:700;line-height:1">${datumAkce.getDate()}.${datumAkce.getMonth() + 1}.</div>
                <div style="font-size:10px;color:var(--text-3)">${datumAkce.getFullYear()}</div>
              </div>
              <div style="min-width:0">
                <div style="display:flex;align-items:center;gap:8px;flex-wrap:wrap">
                  <strong style="font-size:15px">${esc(a.nazev)}</strong>
                  <span style="background:${s.bg};color:${s.fg};padding:2px 8px;border-radius:999px;font-size:10.5px;font-weight:700">${esc(s.label)}</span>
                </div>
                <div style="font-size:12px;color:var(--text-3);margin-top:2px">${esc(a.zakaznik)} · 👥 ${a.osob} osob · 📍 ${esc(a.misto || '—')}</div>
                ${a.zaloha_kc ? `<div style="font-size:11px;color:var(--text-3);margin-top:2px">🧾 Záloha ${fmt(a.zaloha_kc)} ${a.zaloha_uhrazena ? '✓' : '⏳'}</div>` : ''}
              </div>
              <div style="text-align:right">
                <strong style="font-size:18px;font-variant-numeric:tabular-nums">${fmt(a.castka_celkem)}</strong>
              </div>
            </div>
          `;
        }).join('')}
      </div>
    `}
  `;
}

window.newCateringOrder = function() {
  openModal('🎉 Nová catering akce', `
    <div class="form-grid form-grid-tight">
      <div class="full"><label class="form-label">Název akce *</label>
        <input class="form-input" id="cat-nazev" placeholder="např. Vánoční večírek Acme s.r.o.">
      </div>
      <div><label class="form-label">Zákazník *</label>
        <input class="form-input" id="cat-zakaznik" placeholder="Firma / kontaktní osoba">
      </div>
      <div><label class="form-label">📅 Datum akce *</label>
        <input type="date" class="form-input" id="cat-datum">
      </div>
      <div><label class="form-label">👥 Počet osob</label>
        <input type="number" class="form-input" id="cat-osob" value="50" min="2">
      </div>
      <div><label class="form-label">💰 Cena celkem (Kč)</label>
        <input type="number" class="form-input" id="cat-cena" step="100" placeholder="0">
      </div>
      <div class="full"><label class="form-label">📍 Místo akce</label>
        <input class="form-input" id="cat-misto" placeholder="např. Hotel Praha, Konferenční sál">
      </div>
      <div><label class="form-label">🧾 Záloha (Kč)</label>
        <input type="number" class="form-input" id="cat-zaloha" step="100" placeholder="50% z ceny">
      </div>
      <div><label class="form-label">Stav</label>
        <select class="form-input" id="cat-stav">
          <option value="poptavka">💭 Poptávka</option>
          <option value="nabidka">📋 Nabídka odeslána</option>
          <option value="potvrzeno">✅ Potvrzeno</option>
        </select>
      </div>
      <div class="full"><label class="form-label">Poznámka</label>
        <textarea class="form-input" id="cat-pozn" rows="2" placeholder="Speciální požadavky, alergeny, …"></textarea>
      </div>
    </div>
    <div class="form-actions">
      <button class="btn-secondary" onclick="closeModal()">Zrušit</button>
      <button class="btn-primary btn-green" onclick="saveCateringOrder()">💾 Uložit</button>
    </div>
  `);
};

window.saveCateringOrder = async function() {
  const nazev = document.getElementById('cat-nazev')?.value?.trim();
  const zakaznik = document.getElementById('cat-zakaznik')?.value?.trim();
  const datum = document.getElementById('cat-datum')?.value;
  if (!nazev || !zakaznik || !datum) { alert('Vyplň název, zákazníka a datum.'); return; }
  try {
    await api('admin_catering_orders.php', { method: 'POST', body: JSON.stringify({
      nazev, zakaznik, datum_akce: datum,
      osob: parseInt(document.getElementById('cat-osob').value) || 50,
      castka_celkem: parseFloat(document.getElementById('cat-cena').value) || 0,
      misto: document.getElementById('cat-misto').value.trim() || null,
      zaloha_kc: parseFloat(document.getElementById('cat-zaloha').value) || 0,
      stav: document.getElementById('cat-stav').value,
      poznamka: document.getElementById('cat-pozn').value.trim() || null,
    })});
    closeModal();
    toastSuccess('Catering akce vytvořena');
    renderCateringOrders();
  } catch (e) { alert('Chyba: ' + e.message); }
};

window.editCateringOrder = async function(id) {
  let data;
  try { data = await api('admin_catering_orders.php?id=' + id); }
  catch (e) { alert('Chyba načtení: ' + e.message); return; }

  state._catEdit = {
    id: data.id,
    nazev: data.nazev || '',
    zakaznik: data.zakaznik || '',
    ico: data.ico || '',
    kontaktni_email: data.kontaktni_email || '',
    kontaktni_telefon: data.kontaktni_telefon || '',
    datum_akce: data.datum_akce || '',
    cas_od: data.cas_od || '',
    cas_do: data.cas_do || '',
    osob: data.osob || 50,
    misto: data.misto || '',
    castka_celkem: parseFloat(data.castka_celkem) || 0,
    zaloha_kc: parseFloat(data.zaloha_kc) || 0,
    zaloha_uhrazena: parseInt(data.zaloha_uhrazena) === 1,
    doplatek_uhrazen: parseInt(data.doplatek_uhrazen) === 1,
    stav: data.stav || 'poptavka',
    poznamka: data.poznamka || '',
    polozky: Array.isArray(data.polozky) ? data.polozky : [],
  };

  cateringRenderEditModal();
};

function cateringRenderEditModal() {
  const e = state._catEdit;
  if (!e) return;
  const stavBarvy = {
    'poptavka':     '💭 Poptávka',
    'nabidka':      '📋 Nabídka odeslána',
    'potvrzeno':    '✅ Potvrzeno',
    'zaloha_uhraz': '🧾 Záloha uhrazena',
    'realizace':    '🍽️ Realizace',
    'dokonceno':    '✓ Dokončeno',
    'zruseno':      '🚫 Zrušeno',
  };
  const sumPolozky = (e.polozky || []).reduce((s, p) => s + (parseFloat(p.cena_celkem) || (parseFloat(p.cena) || 0) * (parseFloat(p.mnozstvi) || 1)), 0);

  openModal(`🎉 Akce #${e.id}: ${esc(e.nazev)}`, `
    <div class="form-grid form-grid-tight">
      <div class="full"><label class="form-label">Název akce *</label>
        <input class="form-input" id="cate-nazev" value="${esc(e.nazev)}">
      </div>
      <div><label class="form-label">Zákazník *</label>
        <input class="form-input" id="cate-zakaznik" value="${esc(e.zakaznik)}">
      </div>
      <div><label class="form-label">IČO</label>
        <input class="form-input" id="cate-ico" value="${esc(e.ico)}" placeholder="12345678">
      </div>
      <div><label class="form-label">📧 E-mail</label>
        <input class="form-input" id="cate-email" value="${esc(e.kontaktni_email)}">
      </div>
      <div><label class="form-label">📞 Telefon</label>
        <input class="form-input" id="cate-tel" value="${esc(e.kontaktni_telefon)}">
      </div>
      <div><label class="form-label">📅 Datum akce *</label>
        <input type="date" class="form-input" id="cate-datum" value="${esc(e.datum_akce)}">
      </div>
      <div><label class="form-label">🕐 Čas od</label>
        <input type="time" class="form-input" id="cate-od" value="${esc(e.cas_od)}">
      </div>
      <div><label class="form-label">🕐 Čas do</label>
        <input type="time" class="form-input" id="cate-do" value="${esc(e.cas_do)}">
      </div>
      <div><label class="form-label">👥 Počet osob</label>
        <input type="number" class="form-input" id="cate-osob" value="${e.osob}" min="2">
      </div>
      <div class="full"><label class="form-label">📍 Místo akce</label>
        <input class="form-input" id="cate-misto" value="${esc(e.misto)}">
      </div>
      <div><label class="form-label">💰 Cena celkem (Kč)</label>
        <input type="number" class="form-input" id="cate-cena" step="100" value="${e.castka_celkem}">
      </div>
      <div><label class="form-label">Záloha (Kč)</label>
        <input type="number" class="form-input" id="cate-zaloha" step="100" value="${e.zaloha_kc}">
      </div>
      <div><label class="form-label">Stav</label>
        <select class="form-input" id="cate-stav">
          ${Object.entries(stavBarvy).map(([v, l]) => `<option value="${v}" ${e.stav === v ? 'selected' : ''}>${l}</option>`).join('')}
        </select>
      </div>
      <div style="display:flex;flex-direction:column;gap:6px;justify-content:end">
        <label style="display:flex;align-items:center;gap:8px;font-size:13px;cursor:pointer">
          <input type="checkbox" id="cate-zalUhraz" ${e.zaloha_uhrazena ? 'checked' : ''}> 🧾 Záloha uhrazena
        </label>
        <label style="display:flex;align-items:center;gap:8px;font-size:13px;cursor:pointer">
          <input type="checkbox" id="cate-doplUhraz" ${e.doplatek_uhrazen ? 'checked' : ''}> ✅ Doplatek uhrazen
        </label>
      </div>
      <div class="full"><label class="form-label">Poznámka</label>
        <textarea class="form-input" id="cate-pozn" rows="2">${esc(e.poznamka)}</textarea>
      </div>
    </div>

    <div style="margin-top:18px;padding-top:14px;border-top:1px solid var(--border)">
      <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:10px">
        <h3 style="margin:0;font-size:15px">📋 Položky / Menu (${(e.polozky || []).length})</h3>
        <button class="btn-secondary" onclick="cateringAddItem()" style="font-size:12px;padding:5px 10px">+ Položka</button>
      </div>
      ${(e.polozky || []).length === 0 ? `
        <div style="text-align:center;padding:16px;color:var(--text-3);font-size:13px;background:var(--surface-2);border-radius:8px">
          Žádné položky. Můžeš přidat menu / vybavení / služby.
        </div>
      ` : `
        <div style="display:flex;flex-direction:column;gap:6px">
          ${(e.polozky || []).map((p, i) => `
            <div style="display:grid;grid-template-columns:1fr 70px 100px 100px 30px;gap:6px;align-items:center;background:var(--surface-2);padding:7px 10px;border-radius:7px">
              <input class="form-input" value="${esc(p.nazev || '')}" placeholder="Název" onchange="cateringSetItem(${i},'nazev',this.value)" style="font-size:13px;padding:5px 8px">
              <input class="form-input" type="number" step="0.5" value="${p.mnozstvi || 1}" onchange="cateringSetItem(${i},'mnozstvi',this.value);cateringRefreshTotal()" style="font-size:13px;padding:5px 8px;text-align:center">
              <input class="form-input" type="number" step="10" value="${p.cena || 0}" placeholder="cena/ks" onchange="cateringSetItem(${i},'cena',this.value);cateringRefreshTotal()" style="font-size:13px;padding:5px 8px;text-align:right">
              <input class="form-input" type="number" step="10" value="${p.cena_celkem || ((p.cena || 0) * (p.mnozstvi || 1))}" placeholder="celkem" onchange="cateringSetItem(${i},'cena_celkem',this.value);cateringRefreshTotal()" style="font-size:13px;padding:5px 8px;text-align:right;background:var(--surface-3);font-weight:600">
              <button onclick="cateringDelItem(${i})" style="background:transparent;border:none;color:#dc2626;cursor:pointer;font-size:16px;padding:0">×</button>
            </div>
          `).join('')}
          <div style="display:flex;justify-content:flex-end;gap:14px;padding:8px 10px;background:var(--surface);border-radius:7px;font-size:13px;font-weight:600">
            <span>Položky celkem:</span>
            <span id="cate-polozky-sum" style="font-variant-numeric:tabular-nums">${fmt(sumPolozky)}</span>
            <button class="btn-secondary" onclick="cateringApplySum()" style="font-size:11px;padding:3px 8px">→ Aplikovat na cenu</button>
          </div>
        </div>
      `}
    </div>

    <div class="form-actions" style="justify-content:space-between">
      <button class="btn-secondary" style="color:#dc2626" onclick="cateringDelete(${e.id})">🗑️ Smazat</button>
      <div style="display:flex;gap:8px">
        <button class="btn-secondary" onclick="closeModal()">Zrušit</button>
        <button class="btn-primary btn-green" onclick="cateringSaveEdit()">💾 Uložit změny</button>
      </div>
    </div>
  `);
}

window.cateringAddItem = function() {
  state._catEdit.polozky = state._catEdit.polozky || [];
  state._catEdit.polozky.push({ nazev: '', mnozstvi: 1, cena: 0, cena_celkem: 0 });
  cateringRenderEditModal();
};

window.cateringDelItem = function(i) {
  state._catEdit.polozky.splice(i, 1);
  cateringRenderEditModal();
};

window.cateringSetItem = function(i, key, val) {
  const p = state._catEdit.polozky[i];
  if (!p) return;
  if (['mnozstvi', 'cena', 'cena_celkem'].includes(key)) {
    p[key] = parseFloat(val) || 0;
    if (key === 'cena' || key === 'mnozstvi') {
      p.cena_celkem = (p.cena || 0) * (p.mnozstvi || 0);
      // Refresh just the celkem input for this row
      const row = document.querySelectorAll('#modal-body .form-grid + div [style*="grid-template-columns"]')[i];
      if (row) {
        const inputs = row.querySelectorAll('input');
        if (inputs[3]) inputs[3].value = p.cena_celkem;
      }
    }
  } else {
    p[key] = val;
  }
};

window.cateringRefreshTotal = function() {
  const sum = (state._catEdit.polozky || []).reduce((s, p) => s + (parseFloat(p.cena_celkem) || 0), 0);
  const el = document.getElementById('cate-polozky-sum');
  if (el) el.textContent = fmt(sum);
};

window.cateringApplySum = function() {
  const sum = (state._catEdit.polozky || []).reduce((s, p) => s + (parseFloat(p.cena_celkem) || 0), 0);
  const input = document.getElementById('cate-cena');
  if (input) input.value = sum;
  toastInfo(t('toast_price_set_to', { sum: fmt(sum) }));
};

window.cateringSaveEdit = async function() {
  const e = state._catEdit;
  const payload = {
    nazev: document.getElementById('cate-nazev').value.trim(),
    zakaznik: document.getElementById('cate-zakaznik').value.trim(),
    ico: document.getElementById('cate-ico').value.trim() || null,
    kontaktni_email: document.getElementById('cate-email').value.trim() || null,
    kontaktni_telefon: document.getElementById('cate-tel').value.trim() || null,
    datum_akce: document.getElementById('cate-datum').value,
    cas_od: document.getElementById('cate-od').value || null,
    cas_do: document.getElementById('cate-do').value || null,
    osob: parseInt(document.getElementById('cate-osob').value) || 50,
    misto: document.getElementById('cate-misto').value.trim() || null,
    castka_celkem: parseFloat(document.getElementById('cate-cena').value) || 0,
    zaloha_kc: parseFloat(document.getElementById('cate-zaloha').value) || 0,
    zaloha_uhrazena: document.getElementById('cate-zalUhraz').checked ? 1 : 0,
    doplatek_uhrazen: document.getElementById('cate-doplUhraz').checked ? 1 : 0,
    stav: document.getElementById('cate-stav').value,
    poznamka: document.getElementById('cate-pozn').value.trim() || null,
    polozky_json: JSON.stringify(e.polozky || []),
  };
  if (!payload.nazev || !payload.zakaznik || !payload.datum_akce) {
    alert('Vyplň název, zákazníka a datum.');
    return;
  }
  try {
    await api('admin_catering_orders.php?id=' + e.id, { method: 'PUT', body: JSON.stringify(payload) });
    closeModal();
    toastSuccess('✓ Změny uloženy');
    renderCateringOrders();
  } catch (err) { alert('Chyba: ' + err.message); }
};

window.cateringDelete = async function(id) {
  if (!await customConfirm('Opravdu smazat akci?', 'Tuto operaci nelze vrátit zpět.', 'Smazat')) return;
  try {
    await api('admin_catering_orders.php?id=' + id, { method: 'DELETE' });
    closeModal();
    toastSuccess('Akce smazána');
    renderCateringOrders();
  } catch (e) { alert('Chyba: ' + e.message); }
};

