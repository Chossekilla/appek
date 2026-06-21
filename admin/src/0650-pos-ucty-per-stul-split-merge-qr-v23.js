// =============================================================
// 🧾 POS — ÚČTY PER STŮL, SPLIT, MERGE, QR (v2.3)
// =============================================================
window.posState = window.posState || {
  currentUcet: null,
  menuCache: null,
};

window.posOpenUcet = async function(stulId) {
  try {
    const ucet = await api('admin_pos.php?action=ucet&stul_id=' + stulId);
    posState.currentUcet = ucet;
    posRenderUcetModal();
    // 🆕 v3.0.186 — propojení stolu s účtem: action=ucet při otevření založí účet a
    //   překlopí stůl na 'occupied' v DB. Osvěž floor plan / seznam účtů POD modalem,
    //   ať je stůl hned obsazený (dřív zůstal zelený „volný" → re-klik otevřel akční
    //   menu místo účtu). Guard na #rt-body = běží jen v sekci Restaurace.
    if (document.getElementById('rt-body') && typeof renderRestaurantTables === 'function') {
      renderRestaurantTables();
    }
  } catch (e) { alert('Chyba: ' + e.message); }
};

async function posLoadMenu() {
  if (posState.menuCache) return posState.menuCache;
  try {
    const r = await api('admin_vyrobky.php?aktivni=1');
    const items = (r.data || r.vyrobky || r || []).filter(v => parseInt(v.aktivni) === 1 || v.aktivni === true);
    const cats = {};
    for (const v of items) {
      const cid = v.kategorie_id || 0;
      cats[cid] ??= {
        id: cid,
        nazev: v.kategorie_nazev || '— Bez kategorie —',
        barva: v.kategorie_barva || '#94A3B8',
        items: [],
      };
      cats[cid].items.push({
        id: parseInt(v.id),
        nazev: v.nazev,
        cena: parseFloat(v.cena_bez_dph || v.cena || 0),
        popis: v.popis || '',
        kategorie: v.kategorie_nazev || '',
      });
    }
    posState.menuCache = Object.values(cats);
    return posState.menuCache;
  } catch (e) {
    posState.menuCache = [];
    return [];
  }
}

window.posRenderUcetModal = function() {
  const u = posState.currentUcet;
  if (!u) return;
  const polozky = u.polozky || [];
  const total = polozky.filter(p => p.stav !== 'storno').reduce((s, p) => s + p.jednotkova_cena * p.mnozstvi, 0);
  const activeCount = polozky.filter(p => p.stav !== 'storno').length;
  const minutesOpen = u.otevreno_v ? Math.floor((Date.now() - new Date(u.otevreno_v.replace(' ', 'T')).getTime()) / 60000) : 0;

  const stavLabels = {
    objednano: { ico: '⏳', label: 'Objednáno', color: '#F59E0B' },
    vari_se: { ico: '🍳', label: 'Vaří se', color: '#3B82F6' },
    hotovo: { ico: '✅', label: 'Hotovo', color: '#16A34A' },
    servirovano: { ico: '🍽️', label: 'Servírováno', color: '#64748B' },
    storno: { ico: '✕', label: 'Storno', color: '#DC2626' },
  };

  openModal(`🧾 Účet — ${u.stul?.nazev || '#' + u.stul_id}`, `
    <div style="display:flex;flex-direction:column;gap:10px;max-height:80vh">
      <div style="background:linear-gradient(135deg,#FFF8E7,#FEF3C7);padding:10px 14px;border-radius:10px;display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:8px">
        <div>
          <div style="font-size:12px;color:#92400e">Účet <strong>#${u.id}</strong> · otevřen ${minutesOpen}m</div>
          <div style="font-size:11px;color:#92400e;opacity:0.85">Obsluhuje: ${esc(u.otevrel_jmeno || '—')}</div>
        </div>
        <div style="text-align:right">
          <div style="font-size:11px;color:#92400e;text-transform:uppercase;font-weight:600">Celkem</div>
          <div id="pos-head-total" style="font-size:22px;font-weight:800;color:#1d1d1f">${total.toFixed(2)} Kč</div>
        </div>
      </div>

      <div style="display:flex;gap:6px;border-bottom:1.5px solid var(--border);padding-bottom:6px">
        <button class="pos-tab is-active" data-tab="items" onclick="posTabClick(this)" style="padding:6px 12px;background:transparent;border:0;border-bottom:3px solid #BA7517;font-weight:700;cursor:pointer;color:#854F0B">📋 Položky (<span id="pos-head-count">${activeCount}</span>)</button>
        <button class="pos-tab" data-tab="add" onclick="posTabClick(this)" style="padding:6px 12px;background:transparent;border:0;border-bottom:3px solid transparent;font-weight:600;cursor:pointer;color:var(--text-3)">+ Přidat z menu</button>
        <button class="pos-tab" data-tab="actions" onclick="posTabClick(this)" style="padding:6px 12px;background:transparent;border:0;border-bottom:3px solid transparent;font-weight:600;cursor:pointer;color:var(--text-3)">⚙️ Akce</button>
      </div>

      <div id="pos-tab-body" style="flex:1;overflow-y:auto;min-height:300px;max-height:55vh">
        ${posRenderItemsTab(u, total, stavLabels)}
      </div>

      <div style="display:flex;gap:6px;flex-wrap:wrap;padding-top:10px;border-top:1.5px solid var(--border)">
        <a href="../api/admin_pos_print.php?ucet_id=${u.id}&typ=kuchyne&autoprint=1" target="_blank" class="btn-secondary" style="text-decoration:none;font-size:13px;padding:9px 14px">🍳 Kuchyňský bon</a>
        <a href="../api/admin_pos_print.php?ucet_id=${u.id}&typ=ucet" target="_blank" class="btn-secondary" style="text-decoration:none;font-size:13px;padding:9px 14px">🧾 Účet</a>
        <button class="btn-primary btn-green" onclick="posPaymentDialog()" style="flex:1;font-size:14px;padding:11px;background:linear-gradient(135deg,#16a34a,#15803d);font-weight:700">🧾 Zaplatit (<span id="pos-pay-total">${total.toFixed(2)}</span> Kč)</button>
      </div>
    </div>
  `);
};

window.posTabClick = function(btn) {
  document.querySelectorAll('.pos-tab').forEach(t => {
    t.classList.remove('is-active');
    t.style.borderBottomColor = 'transparent';
    t.style.color = 'var(--text-3)';
    t.style.fontWeight = '600';
  });
  btn.classList.add('is-active');
  btn.style.borderBottomColor = '#BA7517';
  btn.style.color = '#854F0B';
  btn.style.fontWeight = '700';
  const tab = btn.dataset.tab;
  const body = document.getElementById('pos-tab-body');
  const u = posState.currentUcet;
  const polozky = u.polozky || [];
  const total = polozky.filter(p => p.stav !== 'storno').reduce((s, p) => s + p.jednotkova_cena * p.mnozstvi, 0);
  // 🆕 v3.0.186 — sync hlavičkového „Celkem", počtu položek a tlačítka „Zaplatit".
  //   Dřív se po „Přidat z menu" překreslilo jen tělo tabu (#pos-tab-body) → header total
  //   i tlačítko Zaplatit zůstaly na staré hodnotě (typicky 0) = „pos stolů modal nepočítá".
  {
    const _ac = polozky.filter(p => p.stav !== 'storno').length;
    const _ht = document.getElementById('pos-head-total'); if (_ht) _ht.textContent = total.toFixed(2) + ' Kč';
    const _pt = document.getElementById('pos-pay-total');  if (_pt) _pt.textContent = total.toFixed(2);
    const _hc = document.getElementById('pos-head-count');  if (_hc) _hc.textContent = _ac;
  }
  const stavLabels = {
    objednano: { ico: '⏳', label: 'Objednáno', color: '#F59E0B' },
    vari_se: { ico: '🍳', label: 'Vaří se', color: '#3B82F6' },
    hotovo: { ico: '✅', label: 'Hotovo', color: '#16A34A' },
    servirovano: { ico: '🍽️', label: 'Servírováno', color: '#64748B' },
    storno: { ico: '✕', label: 'Storno', color: '#DC2626' },
  };
  if (tab === 'items') body.innerHTML = posRenderItemsTab(u, total, stavLabels);
  else if (tab === 'add') {
    body.innerHTML = '<div style="padding:30px;text-align:center;color:var(--text-3)">⏳ Načítám menu…</div>';
    posLoadMenu().then(cats => { body.innerHTML = posRenderAddTab(cats); });
  } else if (tab === 'actions') body.innerHTML = posRenderActionsTab(u);
};

function posRenderItemsTab(u, total, stavLabels) {
  const polozky = u.polozky || [];
  if (polozky.length === 0) {
    return `<div style="padding:40px 20px;text-align:center;color:var(--text-3)">
      <div style="font-size:42px;margin-bottom:10px">🧾</div>
      <strong>Účet je prázdný</strong>
      <p style="font-size:13px;margin-top:6px">Klikni na "Přidat z menu" pro objednání první položky.</p>
    </div>`;
  }
  const kurzy = {};
  for (const p of polozky) {
    const k = p.kurz || 1;
    kurzy[k] ??= [];
    kurzy[k].push(p);
  }
  const kurzNazvy = { 1: 'Předkrm', 2: 'Hlavní jídlo', 3: 'Dezert', 4: 'Nápoje', 5: 'Extra' };
  return Object.keys(kurzy).sort().map(k => `
    <div style="margin-bottom:14px">
      <div style="font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:0.6px;color:#854F0B;padding:6px 10px;background:#FFF8E7;border-radius:6px;margin-bottom:6px">
        ${k}. ${kurzNazvy[k] || 'Kurz ' + k}
      </div>
      ${kurzy[k].map(p => {
        const stv = stavLabels[p.stav] || stavLabels.objednano;
        const isStorno = p.stav === 'storno';
        return `
          <div style="display:flex;align-items:center;gap:10px;padding:10px;border-radius:8px;background:${isStorno ? '#FEF2F2' : '#fff'};border:1px solid ${isStorno ? '#FCA5A5' : 'var(--border)'};margin-bottom:4px;${isStorno ? 'opacity:0.6;text-decoration:line-through' : ''}">
            <div style="background:${stv.color};color:#fff;border-radius:50%;width:30px;height:30px;display:flex;align-items:center;justify-content:center;flex-shrink:0;font-size:14px" title="${stv.label}">${stv.ico}</div>
            <div style="flex:1;min-width:0">
              <div style="font-weight:600;font-size:13px">${esc(p.nazev)}</div>
              <div style="font-size:11px;color:var(--text-3)">${p.mnozstvi}× ${(+p.jednotkova_cena).toFixed(2)} Kč ${p.poznamka ? '· ⚠️ ' + esc(p.poznamka) : ''}</div>
            </div>
            <div style="text-align:right;flex-shrink:0">
              <div style="font-weight:700;font-size:14px">${(p.jednotkova_cena * p.mnozstvi).toFixed(2)} Kč</div>
              ${!isStorno ? `
                <div style="display:flex;gap:4px;margin-top:4px">
                  ${p.stav === 'objednano' ? `<button onclick="posItemState(${p.id}, 'vari_se')" style="padding:3px 6px;font-size:10px;border-radius:4px;border:1px solid #3B82F6;background:#fff;color:#3B82F6;cursor:pointer" title="Vaří se">🍳</button>` : ''}
                  ${p.stav === 'vari_se' ? `<button onclick="posItemState(${p.id}, 'hotovo')" style="padding:3px 6px;font-size:10px;border-radius:4px;border:1px solid #16A34A;background:#fff;color:#16A34A;cursor:pointer" title="Hotovo">✅</button>` : ''}
                  ${p.stav === 'hotovo' ? `<button onclick="posItemState(${p.id}, 'servirovano')" style="padding:3px 6px;font-size:10px;border-radius:4px;border:1px solid #64748B;background:#fff;color:#64748B;cursor:pointer" title="Servírováno">🍽️</button>` : ''}
                  <button onclick="posItemDelete(${p.id})" style="padding:3px 6px;font-size:10px;border-radius:4px;border:1px solid #DC2626;background:#fff;color:#DC2626;cursor:pointer" title="Storno/smazat">✕</button>
                </div>
              ` : ''}
            </div>
          </div>
        `;
      }).join('')}
    </div>
  `).join('');
}

function posRenderAddTab(cats) {
  if (!cats || cats.length === 0) {
    return `<div style="padding:30px;text-align:center;color:var(--text-3)">
      <div style="font-size:32px">📦</div>
      <strong>Žádné produkty v menu</strong>
      <p style="font-size:13px;margin-top:6px">Přidej produkty v sekci <strong>📦 Výrobky</strong> a budou k dispozici v POS.</p>
    </div>`;
  }
  return `
    <input type="search" id="pos-menu-search" class="form-input" placeholder="🔍 Hledat v menu…" oninput="posFilterMenu(this.value)" style="margin-bottom:10px">
    <div id="pos-menu-categories">
      ${cats.map(c => `
        <div class="pos-cat" data-cat-name="${esc(c.nazev.toLowerCase())}" style="margin-bottom:14px">
          <h4 style="font-size:12px;font-weight:700;text-transform:uppercase;letter-spacing:0.6px;color:${c.barva || '#854F0B'};padding:4px 0;margin-bottom:6px">${esc(c.nazev)} (${c.items.length})</h4>
          <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(180px,1fr));gap:6px">
            ${c.items.map(it => `
              <button class="pos-menu-item" data-search="${esc((it.nazev + ' ' + (it.popis || '')).toLowerCase())}"
                      onclick="posAddItem(${it.id}, '${esc(it.nazev).replace(/'/g, '&#39;')}', ${it.cena}, '${esc(c.nazev).replace(/'/g, '&#39;')}')"
                      style="text-align:left;padding:10px;border:1.5px solid var(--border);background:#fff;border-radius:8px;cursor:pointer;display:flex;flex-direction:column;gap:2px;transition:border-color 0.15s"
                      onmouseover="this.style.borderColor='#16A34A'" onmouseout="this.style.borderColor='var(--border)'">
                <span style="font-size:13px;font-weight:600;line-height:1.3">${esc(it.nazev)}</span>
                <span style="font-size:12px;color:#16A34A;font-weight:700">${it.cena.toFixed(2)} Kč</span>
              </button>
            `).join('')}
          </div>
        </div>
      `).join('')}
    </div>
  `;
}

function posRenderActionsTab(u) {
  return `
    <div style="display:flex;flex-direction:column;gap:8px">
      <button class="btn-secondary" onclick="posSplitDialog()" style="text-align:left;padding:14px;display:flex;align-items:center;gap:12px">
        <span style="font-size:24px">✂️</span>
        <div><strong>Rozdělit účet (split bill)</strong><br><small style="color:var(--text-3)">Vytvoř 2+ pod-účty z položek</small></div>
      </button>
      <button class="btn-secondary" onclick="posMergeDialog()" style="text-align:left;padding:14px;display:flex;align-items:center;gap:12px">
        <span style="font-size:24px">🔗</span>
        <div><strong>Sloučit účty</strong><br><small style="color:var(--text-3)">Spoj 2+ stolů do jednoho účtu</small></div>
      </button>
      <button class="btn-secondary" onclick="posMoveDialog()" style="text-align:left;padding:14px;display:flex;align-items:center;gap:12px">
        <span style="font-size:24px">🚚</span>
        <div><strong>Přesunout účet</strong><br><small style="color:var(--text-3)">Host přešel na jiný stůl</small></div>
      </button>
    </div>
  `;
}

window.posFilterMenu = function(q) {
  q = q.toLowerCase().trim();
  document.querySelectorAll('.pos-menu-item').forEach(b => {
    const match = !q || (b.dataset.search || '').includes(q);
    b.style.display = match ? '' : 'none';
  });
  document.querySelectorAll('.pos-cat').forEach(c => {
    const hasVisible = c.querySelector('.pos-menu-item:not([style*="display: none"])');
    c.style.display = hasVisible ? '' : 'none';
  });
};

window.posAddItem = async function(vyrobekId, nazev, cena, kategorie) {
  const u = posState.currentUcet;
  try {
    await api('admin_pos.php?action=item', {
      method: 'POST',
      body: JSON.stringify({
        ucet_id: u.id, vyrobek_id: vyrobekId,
        nazev, jednotkova_cena: cena, kategorie, mnozstvi: 1, kurz: 2,
      }),
    });
    posState.currentUcet = await api('admin_pos.php?action=ucet&stul_id=' + u.stul_id);
    toastSuccess(t('toast_added_item_price', { nazev, cena: cena.toFixed(2) }));
    // 🆕 v3.0.189 — po přidání ukaž účet (tab Položky) s novou položkou. Dřív se zůstalo
    //   na „Přidat z menu" → uživatel přidání neviděl → působilo „nefunguje". Pro další
    //   položku je „+ Přidat z menu" hned v záložkách.
    posRenderUcetModal();
  } catch (e) { alert('Chyba: ' + e.message); }
};

window.posItemState = async function(itemId, stav) {
  try {
    await api('admin_pos.php?action=item_state', {
      method: 'POST', body: JSON.stringify({ id: itemId, stav }),
    });
    const u = posState.currentUcet;
    posState.currentUcet = await api('admin_pos.php?action=ucet&stul_id=' + u.stul_id);
    posRenderUcetModal();
  } catch (e) { alert('Chyba: ' + e.message); }
};

window.posItemDelete = async function(itemId) {
  if (!(await confirmDialog({ msg: 'Odebrat tuto položku? (Pokud už se vaří, bude storno)', danger: true }))) return;
  try {
    await api('admin_pos.php?action=item&id=' + itemId, { method: 'DELETE' });
    const u = posState.currentUcet;
    posState.currentUcet = await api('admin_pos.php?action=ucet&stul_id=' + u.stul_id);
    posRenderUcetModal();
  } catch (e) { alert('Chyba: ' + e.message); }
};

