/* ═════════════════════════════════════════════════════════════════
   🧾 APPEK POS — Standalone JS Core
   Vlastní logika (žádné dependencies na admin.js)
   ═════════════════════════════════════════════════════════════════ */
'use strict';

// 🆕 v2.9.321 — POS error capture (předtím chyběl, admin/admin.js měl).
// JS chyby z POS browseru se POSTují do admin_klient_chyby.php → admin v Diagnostice vidí.
(function setupPOSErrorCapture() {
  let lastReportTs = 0;
  function reportError(payload) {
    const now = Date.now();
    if (now - lastReportTs < 1000) return; // rate-limit client-side
    lastReportTs = now;
    try {
      fetch('../api/admin_klient_chyby.php', {
        method: 'POST',
        credentials: 'include',
        headers: (CFG.csrfToken ? { 'Content-Type': 'application/json', 'X-CSRF-Token': CFG.csrfToken } : { 'Content-Type': 'application/json' }),
        body: JSON.stringify({
          app: 'pos',
          ...payload,
          url: location.href,
          user_info: 'pos (cashier)',
        }),
      }).catch(() => {});
    } catch (e) {}
  }
  window.addEventListener('error', (e) => {
    reportError({
      msg: e.message || 'Unknown POS error',
      source: e.filename || '',
      line: e.lineno || 0,
      col: e.colno || 0,
      stack: e.error?.stack || '',
    });
  });
  window.addEventListener('unhandledrejection', (e) => {
    reportError({
      msg: '[POS Promise] ' + (e.reason?.message || String(e.reason)),
      stack: e.reason?.stack || '',
    });
  });
})();

(function () {
  const CFG = window.POS_CONFIG || {};

  // ─── i18n fallback ───────────────────────────────────────────
  // 🐛 v3.0.193 — KRITICKÝ FIX: admin/pos.php nenačítá i18n.js → globální t()
  //   neexistovala. Volání t('pos_item_added' …) házela „t is not defined" →
  //   v posTableAddItem se add provedl (DB), ale toast(t(...)) spadl PŘED
  //   refreshTableModal → košík se NEOBNOVIL = „přidání nefunguje / modal nefunguje".
  //   Stejně padaly drafty a hláška o uzavření účtu. Lokální t() to spolehlivě řeší.
  const _T_FALLBACK = {
    pos_item_added:         '✓ Přidáno: {nazev}',
    pos_bill_closed:        '✓ Účet {cislo} · {amount} Kč zaplaceno',
    pos_bill_ready:         '✓ Účet připraven',
    pos_bill_print_confirm: 'Vytisknout účet?',
    pos_draft_saved:        '💾 Uloženo do rozpracovaných',
    pos_draft_loaded:       '✓ Rozpracovaný účet obnoven',
  };
  function t(key, params) {
    // Preferuj reálné i18n, pokud je (a není to tahle funkce).
    if (typeof window.t === 'function' && window.t !== t) {
      try { const r = window.t(key, params); if (r && r !== key) return r; } catch (e) {}
    }
    let s = _T_FALLBACK[key] || key;
    if (params) Object.keys(params).forEach(k => { s = s.split('{' + k + '}').join(params[k]); });
    return s;
  }

  // ─── State ────────────────────────────────────────────────────
  const State = {
    cart:          [],
    activeCat:     'all',
    activeFilter:  'all',
    search:        '',
    odberatel:     null,
    pos_typ:       'sebou',
    pos_payment:   'hotove',
    pos_tip:       0,
    sleva_pct:     0,
    poznamka:      '',
    catalog:       null,   // { kategorie, vyrobky }
    customers:     null,   // cached
    activeTab:     'products',
  };

  // ─── DOM helpers ─────────────────────────────────────────────
  const $   = (sel) => document.querySelector(sel);
  const $$  = (sel) => Array.from(document.querySelectorAll(sel));
  const esc = (s) => String(s ?? '').replace(/[&<>"']/g, m => ({
    '&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'
  }[m]));
  const fmt = (n) => Number(n || 0).toFixed(2).replace('.', ',');

  // ─── API helper ──────────────────────────────────────────────
  async function api(path, opts = {}) {
    const url = CFG.apiBase + path;
    const method = (opts.method || 'GET').toUpperCase();
    const headers = Object.assign({
      'Accept': 'application/json',
      'Content-Type': 'application/json',
    }, opts.headers || {});
    if (method !== 'GET' && CFG.csrfToken) {
      headers['X-CSRF-Token'] = CFG.csrfToken;
    }
    const r = await fetch(url, {
      method,
      headers,
      body: opts.body,
      credentials: 'same-origin',
    });
    let data = null;
    const ct = r.headers.get('content-type') || '';
    if (ct.includes('application/json')) data = await r.json();
    else data = { error: await r.text() };
    if (!r.ok) {
      const msg = (data && (data.error || data.message)) || ('HTTP ' + r.status);
      throw new Error(msg);
    }
    return data;
  }

  // ─── Toast ───────────────────────────────────────────────────
  function toast(msg, kind = '') {
    const host = $('#pos-toast-host');
    if (!host) return alert(msg);
    const el = document.createElement('div');
    el.className = 'pos-toast' + (kind ? ' ' + kind : '');
    el.textContent = msg;
    host.appendChild(el);
    setTimeout(() => {
      el.style.opacity = '0';
      setTimeout(() => el.remove(), 200);
    }, 2200);
  }

  // ─── Modal ───────────────────────────────────────────────────
  function modal(title, bodyHtml, footHtml = '') {
    const host = $('#pos-modal-host');
    host.innerHTML = `
      <div class="pos-modal" role="dialog" aria-modal="true">
        <div class="pos-modal-head">
          <div class="pos-modal-title">${esc(title)}</div>
          <button class="pos-modal-x" onclick="POS._closeModal()" aria-label="Zavřít">✕</button>
        </div>
        <div class="pos-modal-body">${bodyHtml}</div>
        ${footHtml ? `<div class="pos-modal-foot">${footHtml}</div>` : ''}
      </div>
    `;
    host.hidden = false;
    host.onclick = (e) => { if (e.target === host) closeModal(); };
  }
  function closeModal() { const h = $('#pos-modal-host'); if (h) { h.hidden = true; h.innerHTML = ''; } }

  // ─── Clock ───────────────────────────────────────────────────
  function tickClock() {
    const now = new Date();
    const hh = String(now.getHours()).padStart(2, '0');
    const mm = String(now.getMinutes()).padStart(2, '0');
    const el = $('#pos-clock');
    if (el) el.textContent = hh + ':' + mm;
  }

  // ─── Fullscreen ──────────────────────────────────────────────
  window.posFullscreen = function () {
    const el = document.documentElement;
    if (!document.fullscreenElement) {
      (el.requestFullscreen || el.webkitRequestFullscreen)?.call(el);
    } else {
      (document.exitFullscreen || document.webkitExitFullscreen)?.call(document);
    }
  };

  // ─── Zoom (velikost kasy) ────────────────────────────────────
  // 🆕 v3.0.193 — +/- zvětšení/zmenšení celé kasy (user: „pos na mobilu je velké…
  //   kasa musí být přehledná na všech zařízeních"). CSS zoom = reflow, persist.
  const POS_ZOOM_STEPS = [0.6, 0.7, 0.8, 0.9, 1.0, 1.1, 1.25, 1.4, 1.6];
  function getPosZoom() {
    const v = parseFloat(localStorage.getItem('pos_zoom'));
    return POS_ZOOM_STEPS.includes(v) ? v : 1.0;
  }
  function applyPosZoom() {
    const z = getPosZoom();
    document.body.style.zoom = z;
    const el = $('#pos-zoom-val');
    if (el) el.textContent = Math.round(z * 100) + '%';
  }
  window.posZoom = function (dir) {
    const cur = getPosZoom();
    let i = POS_ZOOM_STEPS.indexOf(cur);
    if (i < 0) i = POS_ZOOM_STEPS.indexOf(1.0);
    i = Math.max(0, Math.min(POS_ZOOM_STEPS.length - 1, i + dir));
    const z = POS_ZOOM_STEPS[i];
    try { localStorage.setItem('pos_zoom', String(z)); } catch (e) {}
    applyPosZoom();
    try { toast((dir > 0 ? 'Zvětšeno · ' : 'Zmenšeno · ') + Math.round(z * 100) + '%', ''); } catch (e) {}
  };

  // ─── Catalog loading ─────────────────────────────────────────
  async function loadCatalog() {
    $('#pos-cats').innerHTML = '<div class="pos-loading">⏳ Načítám katalog…</div>';
    try {
      const d = await api('admin_pos.php?action=catalog');
      State.catalog = { kategorie: d.kategorie || [], vyrobky: d.vyrobky || [] };
      renderCategories();
      renderFilters();
      renderGrid();
    } catch (e) {
      $('#pos-cats').innerHTML = '';
      $('#pos-grid').innerHTML = `<div class="pos-error">⚠️ Chyba načtení katalogu: ${esc(e.message)}</div>`;
    }
  }

  // ─── Render kategorií ────────────────────────────────────────
  function renderCategories() {
    const wrap = $('#pos-cats');
    if (!wrap) return;
    const cats = State.catalog?.kategorie || [];
    const total = (State.catalog?.vyrobky || []).length;
    let html = `
      <button class="pos-cat ${State.activeCat==='all'?'is-active':''}" data-cat="all">
        <span class="pos-cat-icon">🍱</span>
        <span class="pos-cat-name">Vše</span>
        <span class="pos-cat-count">${total}</span>
      </button>`;
    for (const k of cats) {
      if (!k.pocet || k.pocet === 0) continue;
      const ic = k.obrazek_url
        ? `<img class="pos-cat-img" src="${esc(k.obrazek_url)}" alt="">`
        : `<span class="pos-cat-icon">${esc(k.ikona || '📦')}</span>`;
      html += `
        <button class="pos-cat ${State.activeCat===String(k.id)?'is-active':''}" data-cat="${k.id}">
          ${ic}
          <span class="pos-cat-name">${esc(k.nazev)}</span>
          <span class="pos-cat-count">${k.pocet}</span>
        </button>`;
    }
    wrap.innerHTML = html;
    wrap.querySelectorAll('.pos-cat').forEach(b => {
      b.onclick = () => {
        State.activeCat = b.dataset.cat;
        renderCategories();
        renderGrid();
      };
    });
  }

  // ─── Render filterů ──────────────────────────────────────────
  function renderFilters() {
    const wrap = $('#pos-filters');
    if (!wrap) return;
    const items = State.catalog?.vyrobky || [];
    const cnt = (pred) => items.filter(pred).length;

    const defs = [
      { id: 'all',      lbl: 'Vše',          n: items.length },
      { id: 'oblibeny', lbl: '⭐ Oblíbené',   n: cnt(v => v.oblibeny) },
      { id: 'akce',     lbl: '🔥 Akce',       n: cnt(v => v.je_akce) },
      { id: 'novinka',  lbl: '✨ Novinky',    n: cnt(v => v.je_novinka) },
      { id: 'doprodej', lbl: '⚠️ Doprodej',   n: cnt(v => v.je_doprodej) },
    ];
    wrap.innerHTML = defs
      .filter(d => d.id === 'all' || d.n > 0)
      .map(d => `<button class="pos-filter-pill ${State.activeFilter===d.id?'is-active':''}" data-fid="${d.id}">${esc(d.lbl)} (${d.n})</button>`)
      .join('');
    wrap.querySelectorAll('.pos-filter-pill').forEach(b => {
      b.onclick = () => {
        State.activeFilter = b.dataset.fid;
        renderFilters();
        renderGrid();
      };
    });
  }

  // ─── Render grid produktů ────────────────────────────────────
  function renderGrid() {
    const wrap = $('#pos-grid');
    if (!wrap) return;
    let items = (State.catalog?.vyrobky || []).slice();

    if (State.activeCat !== 'all')      items = items.filter(v => String(v.kategorie_id) === State.activeCat);
    if (State.activeFilter === 'akce')      items = items.filter(v => v.je_akce);
    if (State.activeFilter === 'novinka')   items = items.filter(v => v.je_novinka);
    if (State.activeFilter === 'doprodej')  items = items.filter(v => v.je_doprodej);
    if (State.activeFilter === 'oblibeny')  items = items.filter(v => v.oblibeny);

    if (State.search) {
      const q = State.search.toLowerCase().trim();
      items = items.filter(v =>
        (v.nazev || '').toLowerCase().includes(q) ||
        (v.cislo || '').toLowerCase().includes(q) ||
        (v.ean   || '').toLowerCase().includes(q)
      );
    }

    if (items.length === 0) {
      wrap.innerHTML = `<div class="pos-empty">🔍 Žádný produkt nenalezen</div>`;
      return;
    }

    wrap.innerHTML = items.map(v => {
      const cena = (typeof v.cena_s_dph === 'number' ? v.cena_s_dph : v.cena_bez_dph) || 0;
      const img = v.obrazek_url
        ? `<img src="${esc(v.obrazek_url)}" alt="" class="pos-card-img" loading="lazy">`
        : `<div class="pos-card-noimg">🍽️</div>`;
      const b = [];
      if (v.je_akce)    b.push(`<span class="pos-badge b-akce">🔥</span>`);
      if (v.je_novinka) b.push(`<span class="pos-badge b-nov">✨</span>`);
      if (v.je_doprodej)b.push(`<span class="pos-badge b-dop">⚠️</span>`);
      return `
        <button class="pos-card" data-id="${v.id}" title="${esc(v.nazev)}">
          ${img}
          <div class="pos-card-badges">${b.join('')}</div>
          <div class="pos-card-name">${esc(v.nazev)}</div>
          <div class="pos-card-price">${fmt(cena)} ${esc(CFG.currency || 'Kč')}</div>
        </button>`;
    }).join('');
    wrap.querySelectorAll('.pos-card').forEach(c => {
      c.onclick = () => addToCart(parseInt(c.dataset.id, 10));
    });
  }

  // ─── Cart ────────────────────────────────────────────────────
  function addToCart(id) {
    const v = (State.catalog?.vyrobky || []).find(x => x.id === id);
    if (!v) return;
    const ex = State.cart.find(x => x.vyrobek_id === id);
    if (ex) ex.mnozstvi += 1;
    else State.cart.push({
      vyrobek_id:   v.id,
      nazev:        v.nazev,
      jednotka:     v.jednotka || 'ks',
      mnozstvi:     1,
      cena_bez_dph: v.cena_bez_dph,
      sazba_dph:    v.sazba_dph,
      obrazek_url:  v.obrazek_url,
    });
    renderCart();
    const card = document.querySelector(`.pos-card[data-id="${id}"]`);
    if (card) {
      card.classList.add('pos-pulse');
      setTimeout(() => card.classList.remove('pos-pulse'), 320);
    }
  }
  function changeQty(idx, delta) {
    const it = State.cart[idx];
    if (!it) return;
    it.mnozstvi = Math.max(0.01, +(it.mnozstvi + delta).toFixed(3));
    renderCart();
  }
  function removeItem(idx) {
    State.cart.splice(idx, 1);
    renderCart();
  }

  function renderCart() {
    const wrap = $('#pos-cart-list');
    if (!wrap) return;
    if (State.cart.length === 0) {
      wrap.innerHTML = `
        <div class="pos-cart-empty">
          <div class="pos-cart-empty-ic">🛒</div>
          <div class="pos-cart-empty-title">Košík je prázdný</div>
          <div class="pos-cart-empty-sub">Klikni na produkt vlevo</div>
        </div>`;
    } else {
      wrap.innerHTML = State.cart.map((it, idx) => {
        const cenaJ = it.cena_bez_dph * (1 + (it.sazba_dph || 0) / 100);
        const lineCelkem = cenaJ * it.mnozstvi;
        return `
          <div class="pos-cart-item">
            <div class="pos-line-num">${idx + 1}</div>
            <div class="pos-line-body">
              <div class="pos-line-name">${esc(it.nazev)}</div>
              <div class="pos-line-meta">${fmt(cenaJ)} × ${it.mnozstvi} ${esc(it.jednotka || 'ks')}</div>
            </div>
            <div class="pos-qty">
              <button class="pos-qty-btn" data-act="dec" data-idx="${idx}" aria-label="Méně">−</button>
              <span class="pos-qty-val">${it.mnozstvi}</span>
              <button class="pos-qty-btn" data-act="inc" data-idx="${idx}" aria-label="Více">+</button>
            </div>
            <div class="pos-line-sum">${fmt(lineCelkem)} Kč</div>
            <button class="pos-line-x" data-act="rm" data-idx="${idx}" title="Odebrat">🗑️</button>
          </div>`;
      }).join('');
      wrap.querySelectorAll('button[data-act]').forEach(b => {
        b.onclick = () => {
          const idx = parseInt(b.dataset.idx, 10);
          if (b.dataset.act === 'inc') changeQty(idx, +1);
          if (b.dataset.act === 'dec') changeQty(idx, -1);
          if (b.dataset.act === 'rm')  removeItem(idx);
        };
      });
    }
    recalc();
  }

  function recalc() {
    let bez = 0, dph = 0;
    for (const it of State.cart) {
      const line = it.mnozstvi * it.cena_bez_dph;
      bez += line;
      dph += line * ((it.sazba_dph || 0) / 100);
    }
    let slevaAbs = 0;
    if (State.sleva_pct > 0 && State.sleva_pct <= 100) {
      slevaAbs = (bez + dph) * (State.sleva_pct / 100);
      bez = bez * (1 - State.sleva_pct / 100);
      dph = dph * (1 - State.sleva_pct / 100);
    }
    const total = bez + dph + (State.pos_tip || 0);

    $('#pos-sub-bez').textContent = fmt(bez);
    $('#pos-sub-dph').textContent = fmt(dph);
    $('#pos-total').textContent   = fmt(total) + ' ' + (CFG.currency || 'Kč');

    const $sr = $('#pos-sleva-row');
    if ($sr) {
      $sr.style.display = State.sleva_pct > 0 ? 'flex' : 'none';
      if (State.sleva_pct > 0) {
        $('#pos-sleva-pct').textContent = State.sleva_pct;
        $('#pos-sleva-abs').textContent = '−' + fmt(slevaAbs);
      }
    }
    const $tr = $('#pos-tip-row');
    if ($tr) {
      $tr.style.display = State.pos_tip > 0 ? 'flex' : 'none';
      if (State.pos_tip > 0) $('#pos-tip-abs').textContent = fmt(State.pos_tip);
    }
    // 🆕 v3.0.240 — aktualizuj mobilní lištu / přepínač
    updateMobileBar(total, State.cart.reduce((s, it) => s + (parseFloat(it.mnozstvi) || 0), 0));
  }

  // ─── 🆕 v3.0.240 — Mobilní košík (režim 'bar' / 'toggle') ─────
  function mcartMode() {
    const m = localStorage.getItem('pos_mcart_mode');
    return (m === 'toggle' || m === 'bar') ? m : 'bar';   // výchozí = lišta
  }
  function applyMcartMode() {
    const mode = mcartMode();
    document.body.classList.toggle('pos-mc-bar', mode === 'bar');
    document.body.classList.toggle('pos-mc-toggle', mode === 'toggle');
    if (mode !== 'toggle') document.body.classList.remove('mview-cart');
  }
  function setMcartMode(mode) {
    try { localStorage.setItem('pos_mcart_mode', mode); } catch (e) {}
    cartClose();
    applyMcartMode();
    toast(mode === 'toggle' ? 'Mobilní košík: přepínač Produkty/Košík' : 'Mobilní košík: spodní lišta', 'success');
  }
  function cartOpen() {
    $('#pos-cart-panel')?.classList.add('is-open');
    $('#pos-cart-backdrop')?.classList.add('is-open');
    if (mcartMode() === 'toggle') { document.body.classList.add('mview-cart'); syncMswitch('cart'); }
  }
  function cartClose() {
    $('#pos-cart-panel')?.classList.remove('is-open');
    $('#pos-cart-backdrop')?.classList.remove('is-open');
    document.body.classList.remove('mview-cart');
    syncMswitch('products');
  }
  function mobileView(v) { v === 'cart' ? cartOpen() : cartClose(); }
  function syncMswitch(v) {
    document.querySelectorAll('#pos-mswitch button').forEach(b => b.classList.toggle('is-active', b.dataset.mv === v));
  }
  function updateMobileBar(total, count) {
    const c = $('#pos-bar-count'); if (c) c.textContent = count || 0;
    const t = $('#pos-bar-total'); if (t) t.textContent = fmt(total || 0) + ' ' + (CFG.currency || 'Kč');
    const mc = $('#pos-mswitch-count'); if (mc) mc.textContent = count || 0;
  }

  // ─── Search ──────────────────────────────────────────────────
  let _searchT = null;
  function setSearch(v) {
    State.search = v;
    const inp = $('#pos-search');
    if (inp && inp.value !== v) inp.value = v;
    clearTimeout(_searchT);
    _searchT = setTimeout(renderGrid, 150);
  }

  // ─── Typ + payment ───────────────────────────────────────────
  function setTyp(typ) {
    State.pos_typ = typ;
    $$('.pos-type-btn').forEach(b => b.classList.toggle('is-active', b.dataset.typ === typ));
  }
  function setPay(p) {
    State.pos_payment = p;
    $$('.pos-pay-btn').forEach(b => b.classList.toggle('is-active', b.dataset.pay === p));
  }
  // 🆕 v2.9.205 — payment methods z centrálního API (admin Nastavení→Platby toggle).
  // Cache v paměti pro performance.
  let __posPaymentMethods = null;
  async function loadPaymentMethods() {
    if (__posPaymentMethods !== null) return __posPaymentMethods;
    try {
      const r = await fetch('../api/payment_methods.php?context=pos');
      const j = await r.json();
      __posPaymentMethods = j.methods || [];
    } catch (e) {
      __posPaymentMethods = [
        { key: 'hotove', label: '💵 Hotově', cat: 'physical' },
        { key: 'karta', label: '💳 Kartou', cat: 'physical' },
      ];
    }
    return __posPaymentMethods;
  }

  async function renderPaymentBar() {
    const bar = document.getElementById('pos-payment-bar');
    if (!bar) return;
    const methods = await loadPaymentMethods();
    if (!methods.length) return;
    // Rozdělit na primární (physical) a sekundární (other)
    const primary = methods.filter(m => m.cat === 'physical');
    const others = methods.filter(m => m.cat === 'other');
    const html = [];
    primary.forEach(m => {
      const isAct = State.pos_payment === m.key ? ' is-active' : '';
      html.push(`<button class="pos-pay-btn${isAct}" data-pay="${m.key}" onclick="POS.setPay('${m.key}')">${esc(m.label)}</button>`);
    });
    if (others.length > 0) {
      html.push('<button class="pos-pay-btn pos-pay-more" onclick="POS.payMenu(event)">📲 Jiné ▾</button>');
    }
    bar.innerHTML = html.join('');
    // Pokud aktuální State.pos_payment není mezi povolenými → switch na první
    if (!methods.some(m => m.key === State.pos_payment) && primary.length) {
      setPay(primary[0].key);
    }
  }

  async function payMenu(e) {
    e?.stopPropagation();
    let m = document.querySelector('.pos-pay-menu');
    if (m) { m.remove(); return; }
    const methods = await loadPaymentMethods();
    const opts = methods.filter(mm => mm.cat === 'other').map(mm => ({ id: mm.key, lbl: mm.label }));
    if (!opts.length) return;
    m = document.createElement('div');
    m.className = 'pos-pay-menu';
    m.innerHTML = opts.map(o =>
      `<button class="pos-pay-opt" data-pay="${o.id}">${esc(o.lbl)}</button>`
    ).join('');
    $('#pos-cart-panel').appendChild(m);
    m.querySelectorAll('.pos-pay-opt').forEach(b => {
      b.onclick = () => { setPay(b.dataset.pay); m.remove(); };
    });
    setTimeout(() => {
      document.addEventListener('click', function once(ev) {
        if (!m.contains(ev.target)) { m.remove(); document.removeEventListener('click', once); }
      });
    }, 50);
  }

  // ─── Customer picker ─────────────────────────────────────────
  async function pickCustomer() {
    let list = [];
    try { const d = await api('admin_pos.php?action=customers'); list = d.odberatele || []; }
    catch (e) { toast('Nelze načíst zákazníky: ' + e.message, 'error'); return; }
    modal('Vybrat zákazníka', `
      <input id="pos-cust-search" class="pos-input" placeholder="🔍 Hledat (název / IČO / telefon)…">
      <div class="pos-cust-list" id="pos-cust-list">
        ${list.map(c => `
          <button class="pos-cust-row-item" data-c='${esc(JSON.stringify(c))}'>
            <strong>${esc(c.nazev)}</strong>
            <div class="sub">
              ${c.ico ? 'IČO ' + esc(c.ico) + ' · ' : ''}${esc(c.telefon || '')}${c.email ? ' · ' + esc(c.email) : ''}
            </div>
          </button>
        `).join('') || '<div style="padding:20px;text-align:center;color:#888">Žádní zákazníci</div>'}
      </div>
    `, `<button class="btn-secondary" onclick="POS._pickCust(null)">🚶 Neznámý zákazník</button>`);

    $('#pos-cust-search').oninput = (e) => {
      const q = e.target.value.toLowerCase();
      $$('#pos-cust-list .pos-cust-row-item').forEach(r => {
        r.style.display = r.textContent.toLowerCase().includes(q) ? '' : 'none';
      });
    };
    $$('#pos-cust-list .pos-cust-row-item').forEach(r => {
      r.onclick = () => {
        try { _pickCust(JSON.parse(r.dataset.c.replace(/&quot;/g, '"'))); }
        catch(e) { _pickCust(null); }
      };
    });
  }
  function _pickCust(c) {
    State.odberatel = c;
    const $n = $('#pos-cust-name'), $s = $('#pos-cust-sub');
    if (c) {
      if ($n) $n.textContent = c.nazev;
      if ($s) $s.textContent = c.ico ? 'IČO ' + c.ico : (c.telefon || '');
    } else {
      if ($n) $n.textContent = 'Neznámý zákazník';
      if ($s) $s.textContent = '';
    }
    closeModal();
  }

  // ─── Cart menu (sleva, tip, vyprázdnit) ───────────────────────
  function menuToggle(e) {
    e?.stopPropagation();
    let m = document.querySelector('.pos-cart-menu');
    if (m) { m.remove(); return; }
    m = document.createElement('div');
    m.className = 'pos-pay-menu pos-cart-menu';
    m.style.cssText = 'position:absolute;right:14px;top:54px;z-index:99';
    const mode = mcartMode();
    m.innerHTML = `
      <button class="pos-pay-opt" data-act="discount">💰 Sleva %</button>
      <button class="pos-pay-opt" data-act="tip">💵 Spropitné</button>
      <button class="pos-pay-opt" data-act="note">💬 Poznámka</button>
      <button class="pos-pay-opt" data-act="clear">🗑️ Vyprázdnit košík</button>
      <div class="pos-menu-sep">📂 Navigace</div>
      <button class="pos-pay-opt" data-nav="products">🧾 KASA</button>
      <button class="pos-pay-opt" data-nav="tables">🪑 Stoly</button>
      <button class="pos-pay-opt" data-nav="orders">📜 Účtenky</button>
      <button class="pos-pay-opt" data-nav="reports">📊 Statistiky</button>
      <button class="pos-pay-opt" data-nav="uzaverka">🧮 Uzávěrka</button>
      <div class="pos-menu-sep">📱 Mobilní košík</div>
      <button class="pos-pay-opt" data-mcart="bar">${mode === 'bar' ? '✓ ' : '   '}Spodní lišta</button>
      <button class="pos-pay-opt" data-mcart="toggle">${mode === 'toggle' ? '✓ ' : '   '}Přepínač Produkty/Košík</button>
    `;
    $('#pos-cart-panel').appendChild(m);
    m.querySelectorAll('.pos-pay-opt').forEach(b => {
      b.onclick = () => {
        const a = b.dataset.act, nav = b.dataset.nav, mc = b.dataset.mcart;
        m.remove();
        if (a === 'discount') askDiscount();
        if (a === 'tip')      askTip();
        if (a === 'note')     addNote();
        if (a === 'clear') {
          if (confirm('Smazat celý košík?')) { State.cart = []; renderCart(); }
        }
        if (nav) { cartClose(); setActiveTab(nav); }
        if (mc)  setMcartMode(mc);
      };
    });
    setTimeout(() => {
      document.addEventListener('click', function once(ev) {
        if (!m.contains(ev.target)) { m.remove(); document.removeEventListener('click', once); }
      });
    }, 50);
  }
  function askDiscount() {
    const v = prompt('Sleva v % (0–100):', State.sleva_pct || '0');
    if (v === null) return;
    const n = parseFloat(v);
    if (isNaN(n) || n < 0 || n > 100) return toast('Neplatná hodnota', 'error');
    State.sleva_pct = n;
    recalc();
  }
  function askTip() {
    const v = prompt('Spropitné (Kč):', State.pos_tip || '0');
    if (v === null) return;
    const n = parseFloat(v);
    if (isNaN(n) || n < 0) return toast('Neplatná hodnota', 'error');
    State.pos_tip = n;
    recalc();
  }
  function addNote() {
    const v = prompt('Poznámka k objednávce:', State.poznamka || '');
    if (v === null) return;
    State.poznamka = v;
    if (v) toast('Poznámka přidána', 'success');
  }

  // ─── New order / draft / print / finish ──────────────────────
  // Interní reset bez confirmu — volá se po úspěšném FINISH
  function resetCart() {
    State.cart = [];
    State.odberatel = null;
    State.poznamka = '';
    State.sleva_pct = 0;
    State.pos_tip = 0;
    setTyp('sebou');
    setPay('hotove');
    _pickCust(null);
    renderCart();
    cartClose();   // 🆕 v3.0.240 — na mobilu zavři výsuvný košík zpět na produkty
  }
  // Externí "Nová objednávka" tlačítko — ptá se před vymazáním plného košíku
  function newOrder() {
    if (State.cart.length > 0 && !confirm('Aktuální košík obsahuje položky. Začít nový?')) return;
    resetCart();
  }
  // 🆕 v2.9.279 — Drafty v localStorage (per-user PIN, dokud nebude backend persist)
  const DRAFT_KEY = 'appek_pos_drafts';
  function _loadDrafts() {
    try { return JSON.parse(localStorage.getItem(DRAFT_KEY) || '[]'); }
    catch (e) { return []; }
  }
  function _saveDrafts(arr) {
    try { localStorage.setItem(DRAFT_KEY, JSON.stringify(arr.slice(-20))); /* max 20 */ }
    catch (e) { /* localStorage full or disabled */ }
  }
  function saveDraft() {
    if (State.cart.length === 0) return toast('Košík je prázdný — není co uložit', 'error');
    const drafts = _loadDrafts();
    const draft = {
      id: 'd_' + Date.now() + '_' + Math.random().toString(36).slice(2, 6),
      created_at: new Date().toISOString(),
      cart: JSON.parse(JSON.stringify(State.cart)),
      odberatel: State.odberatel ? { id: State.odberatel.id, nazev: State.odberatel.nazev } : null,
      pos_typ: State.pos_typ,
      pos_payment: State.pos_payment,
      pos_tip: State.pos_tip,
      sleva_pct: State.sleva_pct,
      poznamka: State.poznamka,
      total: State.cart.reduce((s, it) => s + (it.cena_bez_dph * it.mnozstvi * (1 + (it.sazba_dph || 21) / 100)), 0),
      itemCount: State.cart.length,
    };
    drafts.push(draft);
    _saveDrafts(drafts);
    toast(t('pos_draft_saved', { n: drafts.length }), 'success');
    // Vyčistit aktuální košík pro nový start
    resetCart();
  }
  function loadDraft(draftId) {
    const drafts = _loadDrafts();
    const d = drafts.find(x => x.id === draftId);
    if (!d) return toast('Draft nenalezen', 'error');
    if (State.cart.length > 0 && !confirm('Aktuální košík bude přepsán. Pokračovat?')) return;
    State.cart = d.cart || [];
    State.odberatel = d.odberatel;
    State.pos_typ = d.pos_typ || 'sebou';
    State.pos_payment = d.pos_payment || 'hotove';
    State.pos_tip = d.pos_tip || 0;
    State.sleva_pct = d.sleva_pct || 0;
    State.poznamka = d.poznamka || '';
    renderCart();
    setPay(State.pos_payment);
    setTyp(State.pos_typ);
    _pickCust(State.odberatel);
    // Remove from drafts (loaded = consumed)
    _saveDrafts(drafts.filter(x => x.id !== draftId));
    toast(t('pos_draft_loaded', { n: d.itemCount, amount: Math.round(d.total) }), 'success');
  }
  function showDrafts() {
    const drafts = _loadDrafts().reverse(); // newest first
    if (drafts.length === 0) return toast('Žádné rozpracované košíky', 'info');
    const html = `
      <div style="max-height:60vh;overflow-y:auto">
        ${drafts.map(d => {
          const dt = new Date(d.created_at);
          const time = dt.toLocaleString('cs-CZ', { day:'2-digit', month:'2-digit', hour:'2-digit', minute:'2-digit' });
          return `
            <div style="display:flex;align-items:center;gap:10px;padding:10px;border:1px solid #ddd;border-radius:8px;margin-bottom:6px">
              <div style="flex:1">
                <div style="font-weight:600;font-size:14px">${d.itemCount} položek · ${Math.round(d.total)} Kč</div>
                <div style="font-size:11px;color:#666">${time} · ${d.pos_typ}${d.odberatel ? ' · ' + d.odberatel.nazev : ''}</div>
              </div>
              <button class="btn-primary" onclick="POS.loadDraft('${d.id}');POS.closeModal()" style="font-size:12px;padding:6px 12px">Načíst</button>
              <button class="btn-secondary" onclick="POS._delDraft('${d.id}');POS.showDrafts()" style="font-size:12px;padding:6px 10px" title="Smazat">🗑️</button>
            </div>
          `;
        }).join('')}
      </div>
    `;
    modal(`💾 Rozpracované košíky (${drafts.length})`, html);
  }
  function _delDraft(id) {
    _saveDrafts(_loadDrafts().filter(x => x.id !== id));
  }
  function printReceipt() {
    if (State.cart.length === 0) return toast('Košík je prázdný', 'error');
    // Dialog s volbou: standardní browser print, nebo thermal printer (ESC/POS)
    const hasThermalSaved = localStorage.getItem('appek_pos_thermal') === 'yes';
    if (hasThermalSaved) {
      // User už dříve zvolil thermal — ptej se jestli stejně, nebo zruš
      modal('Tisk účtenky', `
        <div style="text-align:center;padding:10px">
          <div style="font-size:42px;margin-bottom:10px">🖨️</div>
          <p style="margin:0 0 16px">Vyber způsob tisku:</p>
          <div style="display:flex;flex-direction:column;gap:10px">
            <button class="btn-primary" onclick="POS._printThermal()" style="font-size:16px;padding:14px">🧾 Thermal tiskárna (USB/Bluetooth — ESC/POS 80mm)</button>
            <button class="btn-secondary" onclick="POS._printBrowser()" style="font-size:14px;padding:12px">📄 Standardní browser print (A4)</button>
          </div>
          <div style="margin-top:14px;font-size:12px;color:#9097a3">
            <label style="display:inline-flex;align-items:center;gap:6px;cursor:pointer">
              <input type="checkbox" id="pos-forget-thermal"> Pamatovat poslední volbu
            </label>
          </div>
        </div>
      `);
    } else {
      // První tisk — ptej se na preferenci + ukaž info
      modal('Tisk účtenky', `
        <div style="text-align:center;padding:10px">
          <div style="font-size:42px;margin-bottom:10px">🖨️</div>
          <p style="margin:0 0 16px">Vyber způsob tisku:</p>
          <div style="display:flex;flex-direction:column;gap:10px">
            <button class="btn-primary" onclick="POS._printThermal()" style="font-size:16px;padding:14px">🧾 Thermal tiskárna (USB/Bluetooth — ESC/POS 80mm)</button>
            <button class="btn-secondary" onclick="POS._printBrowser()" style="font-size:14px;padding:12px">📄 Standardní browser print (A4)</button>
          </div>
          <div style="margin-top:14px;font-size:11px;color:#9097a3;text-align:left;background:#fef3c7;padding:10px;border-radius:6px;border-left:3px solid #f59e0b">
            💡 <strong>Thermal:</strong> připojí se k USB / Bluetooth ESC/POS tiskárně (Star, Epson, Xprinter, …). Funguje v Chrome/Edge na PC i Androidu.<br>
            <strong>Browser print:</strong> klasický dialog tisku z prohlížeče.
          </div>
        </div>
      `);
    }
  }

  function _printBrowser() {
    closeModal();
    if (State.cart.length === 0) return toast('Košík je prázdný', 'error');
    const total = (function () {
      let s = 0;
      for (const it of State.cart) s += it.mnozstvi * it.cena_bez_dph * (1 + (it.sazba_dph || 0) / 100);
      s = s * (1 - (State.sleva_pct || 0) / 100) + (State.pos_tip || 0);
      return s;
    })();
    const html = `<!DOCTYPE html><html><head><meta charset="utf-8"><title>Účtenka</title>
      <style>body{font-family:Courier,monospace;font-size:12px;width:280px;margin:10px auto}
        h1{font-size:14px;text-align:center;margin:0 0 4px}
        .it{display:flex;justify-content:space-between;border-bottom:1px dashed #aaa;padding:4px 0}
        .tot{font-weight:700;font-size:14px;margin-top:8px;text-align:right}</style></head><body>
      <h1>APPEK POS — Účtenka</h1>
      <div style="text-align:center;margin-bottom:8px">${new Date().toLocaleString('cs-CZ')}</div>
      ${State.cart.map(it => `<div class="it"><span>${esc(it.nazev)} ×${it.mnozstvi}</span><span>${(it.mnozstvi*it.cena_bez_dph*(1+(it.sazba_dph||0)/100)).toFixed(2)}</span></div>`).join('')}
      <div class="tot">CELKEM: ${total.toFixed(2)} Kč</div>
      <div style="text-align:center;margin-top:10px;font-size:10px">Děkujeme za nákup!</div>
      <script>window.print();<\/script></body></html>`;
    const w = window.open('', '_blank', 'width=320,height=600');
    if (!w) return toast('Povolte popup okna pro tisk', 'error');
    w.document.write(html);
    w.document.close();
  }

  // ─── Thermal printer (ESC/POS via WebSerial / WebUSB / WebBluetooth) ──
  async function _printThermal() {
    closeModal();
    if (State.cart.length === 0) return toast('Košík je prázdný', 'error');
    localStorage.setItem('appek_pos_thermal', 'yes');

    try {
      const data = buildEscPosReceipt();
      // Try Web Serial first (USB-to-serial cables, USB printers)
      if (navigator.serial) {
        await sendViaWebSerial(data);
        toast('✓ Vytištěno (Serial)', 'success');
        return;
      }
      // Try Web Bluetooth (Bluetooth thermal printers)
      if (navigator.bluetooth) {
        await sendViaWebBluetooth(data);
        toast('✓ Vytištěno (Bluetooth)', 'success');
        return;
      }
      toast('Prohlížeč nepodporuje WebSerial ani WebBluetooth — použij Chrome/Edge', 'error');
    } catch (e) {
      toast('Tisk selhal: ' + e.message, 'error');
      console.error('[POS thermal] ', e);
    }
  }

  // ─── ESC/POS data builder (Star/Epson kompatibilní) ──────────
  function buildEscPosReceipt() {
    // ESC/POS bytes builder
    const enc = new TextEncoder();
    const bytes = [];
    const push = (...b) => b.forEach(x => bytes.push(x));
    const text = (s) => enc.encode(s).forEach(b => bytes.push(b));

    // Init: ESC @
    push(0x1B, 0x40);
    // Code page: CP852 (Latin-2 Eastern European) — ESC t 18
    push(0x1B, 0x74, 18);
    // Center align: ESC a 1
    push(0x1B, 0x61, 0x01);
    // Double size header: GS ! 0x11
    push(0x1D, 0x21, 0x11);
    text("APPEK POS\n");
    // Reset size
    push(0x1D, 0x21, 0x00);
    text("--------------------------------\n");
    // Left align
    push(0x1B, 0x61, 0x00);
    text(new Date().toLocaleString('cs-CZ') + "\n");
    if (State.odberatel?.nazev) text("Zakaznik: " + ascii(State.odberatel.nazev) + "\n");
    text(`Typ: ${State.pos_typ} | Platba: ${State.pos_payment}\n`);
    text("--------------------------------\n");

    let total = 0;
    for (const it of State.cart) {
      const line = it.mnozstvi * it.cena_bez_dph * (1 + (it.sazba_dph || 0) / 100);
      total += line;
      const name = ascii(it.nazev);
      const qty = `${it.mnozstvi}x`;
      const price = line.toFixed(2);
      // Name line
      text(name.substring(0, 32) + "\n");
      // Qty × cena, right-aligned price (32 chars wide)
      const left = `  ${qty}`;
      const padLen = 32 - left.length - price.length - 3;
      const pad = ' '.repeat(Math.max(1, padLen));
      text(`${left}${pad}${price} Kc\n`);
    }
    text("--------------------------------\n");

    if (State.sleva_pct > 0) {
      total = total * (1 - State.sleva_pct / 100);
      text(`Sleva: -${State.sleva_pct}%\n`);
    }
    if (State.pos_tip > 0) {
      total += State.pos_tip;
      text(`Spropitne: ${State.pos_tip.toFixed(2)} Kc\n`);
    }

    // Total — double size, right align
    push(0x1B, 0x61, 0x02);          // right align
    push(0x1D, 0x21, 0x11);          // 2x size
    text(`CELKEM ${total.toFixed(2)} Kc\n`);
    push(0x1D, 0x21, 0x00);          // reset size
    push(0x1B, 0x61, 0x01);          // center
    text("\n");
    text("--------------------------------\n");
    text("Dekujeme za nakup!\n");
    text(CFG.adminName ? "Obsluha: " + ascii(CFG.adminName) + "\n" : "\n");
    text("\n\n\n");

    // Cut paper: GS V 0
    push(0x1D, 0x56, 0x00);

    return new Uint8Array(bytes);
  }

  // Strip non-ASCII (thermal printers s CP852 nezvládají vše)
  function ascii(s) {
    return String(s)
      .replace(/[ěé]/g, 'e').replace(/[ščř]/g, m => ({š:'s',č:'c',ř:'r'}[m]))
      .replace(/[ťďňĎŇŤŠČŘ]/g, m => ({ť:'t',ď:'d',ň:'n',Ď:'D',Ň:'N',Ť:'T',Š:'S',Č:'C',Ř:'R'}[m]))
      .replace(/[áíúůÁÍÚŮ]/g, m => ({á:'a',í:'i',ú:'u',ů:'u',Á:'A',Í:'I',Ú:'U',Ů:'U'}[m]))
      .replace(/[ýžÝŽ]/g, m => ({ý:'y',ž:'z',Ý:'Y',Ž:'Z'}[m]))
      .replace(/[^\x20-\x7E\n]/g, '?');
  }

  // WebSerial
  async function sendViaWebSerial(data) {
    // Reuse port if user už dříve dovolil
    let port = State._serialPort;
    if (!port) {
      port = await navigator.serial.requestPort();
      await port.open({ baudRate: 9600 });
      State._serialPort = port;
    }
    const writer = port.writable.getWriter();
    await writer.write(data);
    writer.releaseLock();
  }

  // WebBluetooth (Bluetooth thermal printer)
  async function sendViaWebBluetooth(data) {
    let device = State._btDevice;
    if (!device || !device.gatt.connected) {
      device = await navigator.bluetooth.requestDevice({
        // Common ESC/POS Bluetooth printer services
        filters: [{ services: ['000018f0-0000-1000-8000-00805f9b34fb'] }],
        optionalServices: ['000018f0-0000-1000-8000-00805f9b34fb'],
      });
      State._btDevice = device;
    }
    if (!device.gatt.connected) await device.gatt.connect();
    const service = await device.gatt.getPrimaryService('000018f0-0000-1000-8000-00805f9b34fb');
    const characteristic = await service.getCharacteristic('00002af1-0000-1000-8000-00805f9b34fb');
    // Send in chunks (Bluetooth has packet size limits ~512 bytes)
    const CHUNK = 256;
    for (let i = 0; i < data.length; i += CHUNK) {
      await characteristic.writeValue(data.slice(i, i + CHUNK));
    }
  }

  // ─── Custom item modal — volná položka bez DB ───────────────
  // 🆕 v2.9.310 — Rychlé volby (presets) jsou EDITOVATELNÉ v adminu (POS Kasa hub).
  // Načítají se dynamicky z api/admin_pos_presets.php (cache 5 min v paměti).
  let _presetsCache = null;
  let _presetsCachedAt = 0;
  async function loadPresets() {
    const now = Date.now();
    if (_presetsCache && (now - _presetsCachedAt) < 5 * 60 * 1000) return _presetsCache;
    try {
      const r = await api('admin_pos_presets.php');
      _presetsCache = Array.isArray(r?.presets) ? r.presets : [];
      _presetsCachedAt = now;
    } catch (e) {
      _presetsCache = []; // fallback — žádné tlačítka, jen ruční zadání
    }
    return _presetsCache;
  }

  async function openCustomItem() {
    const presets = await loadPresets();
    // 🐛 v2.9.313 — XSS fix: dříve `onclick='POS._customPreset(${JSON.stringify(p.nazev)}, ...)'`
    // → JSON.stringify("O'Brien") = "\"O'Brien\"" → apostroph v single-quoted HTML attribute
    // ukončí atribut a zbytek se parsuje jako HTML. Admin uložil payload do presetu, každý
    // cashier ho spustil. Fix: data-* atributy (HTML-escaped přes esc()) + dataset access.
    const presetsHtml = presets.length === 0 ? '' : `
      <div style="display:flex;gap:6px;flex-wrap:wrap;margin-top:4px">
        ${presets.map((p, idx) => {
          const isNeg = (parseFloat(p.cena) || 0) < 0;
          const bg = isNeg ? '#FEE2E2' : '#FFF5DC';
          const border = isNeg ? '#FCA5A5' : '#FAC775';
          const color = isNeg ? '#991b1b' : '';
          const cenaStr = isNeg ? '−' + Math.abs(parseFloat(p.cena)).toFixed(0) : parseFloat(p.cena).toFixed(0);
          return `<button type="button" data-preset-nazev="${esc(p.nazev)}" data-preset-cena="${parseFloat(p.cena) || 0}" data-preset-dph="${parseFloat(p.dph) || 0}" onclick="POS._customPreset(this.dataset.presetNazev, parseFloat(this.dataset.presetCena), parseFloat(this.dataset.presetDph))" style="padding:8px 14px;background:${bg};border:1.5px solid ${border};border-radius:8px;font-size:13px;font-weight:700;cursor:pointer;${color ? 'color:' + color : ''}">${esc(p.ikona || '🛒')} ${esc(p.nazev)} ${cenaStr} Kč</button>`;
        }).join('')}
      </div>
      <div style="font-size:11px;color:#9097a3;margin-top:2px">
        💡 Tipy: edituj v adminu → POS Kasa → ⚙️ Rychlé volby
      </div>
    `;
    modal('➕ Volná položka', `
      <div class="fp-prop-section" style="display:flex;flex-direction:column;gap:12px">
        <div>
          <label style="display:block;font-size:13px;font-weight:700;margin-bottom:4px;color:#5f6470">Název položky *</label>
          <input id="pos-ci-nazev" type="text" placeholder="např. Korkovné, Sleva pro zaměstnance…" autofocus
                 style="width:100%;padding:14px;border:2px solid #e1e5eb;border-radius:10px;font-size:16px;font-family:inherit">
        </div>
        <div style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:10px">
          <div>
            <label style="display:block;font-size:13px;font-weight:700;margin-bottom:4px;color:#5f6470">Cena (bez DPH) *</label>
            <input id="pos-ci-cena" type="number" step="0.01" placeholder="0.00"
                   style="width:100%;padding:14px;border:2px solid #e1e5eb;border-radius:10px;font-size:16px;font-family:inherit">
          </div>
          <div>
            <label style="display:block;font-size:13px;font-weight:700;margin-bottom:4px;color:#5f6470">DPH %</label>
            <select id="pos-ci-dph" style="width:100%;padding:14px;border:2px solid #e1e5eb;border-radius:10px;font-size:16px;font-family:inherit">
              <option value="21">21 %</option>
              <option value="15">15 %</option>
              <option value="12">12 %</option>
              <option value="10">10 %</option>
              <option value="0">0 %</option>
            </select>
          </div>
          <div>
            <label style="display:block;font-size:13px;font-weight:700;margin-bottom:4px;color:#5f6470">Množství</label>
            <input id="pos-ci-mnozstvi" type="number" step="0.01" min="0.01" value="1"
                   style="width:100%;padding:14px;border:2px solid #e1e5eb;border-radius:10px;font-size:16px;font-family:inherit">
          </div>
        </div>
        ${presetsHtml}
      </div>
    `, `
      <button class="btn-secondary" onclick="POS._closeModal()">Zrušit</button>
      <button class="btn-primary" onclick="POS._addCustomItem()">➕ Přidat do košíku</button>
    `);
    setTimeout(() => $('#pos-ci-nazev')?.focus(), 50);
    // Enter v cena/mnozstvi = submit
    ['pos-ci-nazev','pos-ci-cena','pos-ci-mnozstvi'].forEach(id => {
      const el = document.getElementById(id);
      if (el) el.addEventListener('keydown', e => { if (e.key === 'Enter') { e.preventDefault(); addCustomItem(); } });
    });
  }

  function customPreset(nazev, cena, dph) {
    const $n = $('#pos-ci-nazev'), $c = $('#pos-ci-cena'), $d = $('#pos-ci-dph');
    if ($n) $n.value = nazev;
    if ($c) $c.value = cena;
    if ($d) $d.value = String(dph);
  }

  function addCustomItem() {
    const nazev = $('#pos-ci-nazev')?.value?.trim();
    const cena  = parseFloat($('#pos-ci-cena')?.value || '0');
    const dph   = parseFloat($('#pos-ci-dph')?.value || '21');
    const mnozstvi = parseFloat($('#pos-ci-mnozstvi')?.value || '1');

    if (!nazev) return toast('Vyplňte název položky', 'error');
    if (isNaN(cena)) return toast('Vyplňte cenu', 'error');
    if (isNaN(mnozstvi) || mnozstvi <= 0) return toast('Neplatné množství', 'error');

    State.cart.push({
      vyrobek_id:   null,        // ⚡ volná položka — žádné FK
      nazev:        nazev,
      jednotka:     'ks',
      mnozstvi:     mnozstvi,
      cena_bez_dph: cena,
      sazba_dph:    dph,
      obrazek_url:  null,
      is_custom:    true,
    });
    closeModal();
    renderCart();
    toast('✓ Přidáno: ' + nazev, 'success');
  }

  async function finish() {
    if (State.cart.length === 0) return toast('Košík je prázdný', 'error');
    // v2.9.43 — žádný confirm dialog (user klikl velké FINISH = vědomé rozhodnutí)
    const btn = $('.pos-finish');
    if (btn) { btn.disabled = true; btn.textContent = '⏳ Odesílám…'; }
    // 🆕 v2.9.270 — Idempotency key (chrání před duplikáty při retry / dvojkliku)
    const idempotencyKey = (crypto?.randomUUID?.() || 'k_' + Date.now() + '_' + Math.random().toString(36).slice(2, 10));
    try {
      const r = await api('admin_pos.php?action=quick_order', {
        method: 'POST',
        body: JSON.stringify({
          idempotency_key: idempotencyKey,
          polozky: State.cart.map(it => ({
            vyrobek_id:   it.vyrobek_id,
            nazev:        it.nazev,
            jednotka:     it.jednotka,
            mnozstvi:     it.mnozstvi,
            cena_bez_dph: it.cena_bez_dph,
            sazba_dph:    it.sazba_dph,
          })),
          odberatel_id: State.odberatel?.id || null,
          pos_typ:      State.pos_typ,
          pos_payment:  State.pos_payment,
          pos_tip:      State.pos_tip || 0,
          sleva_pct:    State.sleva_pct || 0,
          poznamka:     State.poznamka || '',
        }),
      });
      toast(t('pos_bill_ready', { cislo: r.cislo, amount: fmt(r.celkem) }), 'success');

      // 🆕 v3.0.5 — Tisk účtenky podle nastavení (always / ask / never)
      const mode = r.print_receipt_mode || 'ask';
      if (mode === 'always') {
        // Tichý tisk bez dialogu
        sendPrintReceipt(r.id);
      } else if (mode === 'ask') {
        // Zobraz dialog (modální confirm)
        if (typeof askPrintReceipt === 'function') {
          askPrintReceipt(r.id, r.cislo, r.celkem);
        } else {
          if (confirm(t('pos_bill_print_confirm', { cislo: r.cislo, amount: fmt(r.celkem) }))) {
            sendPrintReceipt(r.id);
          }
        }
      }
      // 'never' → skip

      // Info o auto-dispatch bonů (nenápadně, jen log)
      if (r.printer_dispatch && r.printer_dispatch.length) {
        const oks = r.printer_dispatch.filter(d => d.ok).length;
        if (oks) console.log(`[POS] Bonů vytištěno: ${oks}/${r.printer_dispatch.length}`);
        const fails = r.printer_dispatch.filter(d => !d.ok);
        if (fails.length) {
          fails.forEach(f => console.warn(`[POS] Tisk selhal (${f.nazev}):`, f.error));
        }
      }

      // 🆕 v2.9.43 — Po úspěšném FINISH okamžitě vyprázdnit bez confirmu
      // Připraveno pro dalšího zákazníka (kasový mode)
      resetCart();
    } catch (e) {
      toast('Chyba: ' + e.message, 'error');
    } finally {
      if (btn) { btn.disabled = false; btn.textContent = '✓ FINISH'; }
    }
  }

  // 🆕 v3.0.5 — Posli print_receipt na backend (kasa tiskárna ESC/POS)
  async function sendPrintReceipt(objId) {
    try {
      const r = await api('admin_pos.php?action=print_receipt', {
        method: 'POST', body: JSON.stringify({ objednavka_id: objId })
      });
      if (r && r.ok) {
        if (r.dummy) toast('🖨️ Účtenka odeslána (dummy mode → /tmp/)', 'success');
        else         toast('🖨️ Účtenka odeslána na kasa tiskárnu', 'success');
      } else {
        toast('Tisk selhal: ' + (r?.error || 'neznámá chyba'), 'error');
      }
    } catch (e) {
      toast('Tisk selhal: ' + e.message, 'error');
    }
  }
  window.sendPrintReceipt = sendPrintReceipt;

  // 🆕 v3.0.5 — Modal dialog "Vytisknout účtenku? Ano / Ne"
  function askPrintReceipt(objId, cislo, celkem) {
    // Existuje už modal element?
    let m = document.getElementById('pos-print-ask-modal');
    if (m) m.remove();
    m = document.createElement('div');
    m.id = 'pos-print-ask-modal';
    m.style.cssText = 'position:fixed;inset:0;background:rgba(0,0,0,0.6);display:flex;align-items:center;justify-content:center;z-index:9999;animation:fadeIn 0.2s ease';
    m.innerHTML = `
      <div style="background:#fff;color:#1F2937;border-radius:18px;padding:28px;max-width:380px;width:90%;text-align:center;box-shadow:0 20px 60px rgba(0,0,0,0.3);font-family:inherit">
        <div style="font-size:48px;margin-bottom:10px">🖨️</div>
        <h2 style="font-size:22px;font-weight:800;margin-bottom:6px">Vytisknout účtenku?</h2>
        <div style="color:#6B7280;font-size:13px;margin-bottom:6px">Účet <strong>${esc(cislo)}</strong></div>
        <div style="font-size:32px;font-weight:900;color:#15803D;margin-bottom:20px">${fmt(celkem)} Kč</div>
        <div style="display:flex;gap:10px;justify-content:center">
          <button id="pos-print-no"  style="flex:1;padding:14px;font-size:16px;font-weight:700;border:2px solid #E5E7EB;background:#fff;color:#374151;border-radius:10px;cursor:pointer">Ne, díky</button>
          <button id="pos-print-yes" style="flex:1;padding:14px;font-size:16px;font-weight:800;border:none;background:linear-gradient(135deg,#10B981,#059669);color:#fff;border-radius:10px;cursor:pointer">🖨️ Tisk</button>
        </div>
        <div style="margin-top:14px;font-size:11px;color:#9CA3AF">Nastavení můžeš změnit v <strong>Admin → Nastavení → Tiskárny</strong></div>
      </div>
    `;
    document.body.appendChild(m);
    document.getElementById('pos-print-yes').onclick = () => { m.remove(); sendPrintReceipt(objId); };
    document.getElementById('pos-print-no').onclick  = () => { m.remove(); };
    // Auto-close za 12s pokud user nereaguje (pro kasový provoz)
    setTimeout(() => { if (document.body.contains(m)) m.remove(); }, 12000);
  }
  window.askPrintReceipt = askPrintReceipt;

  // 🆕 v2.9.299 — Přepínač prodavačů — klik na user avatar v POS header
  // Zachová košík, logout + lze re-loginout s jiným PIN (rychlý switch)
  async function switchUser() {
    if (State.cart.length > 0) {
      if (!confirm('V košíku jsou položky. Při přepnutí prodavače bude košík ZACHOVÁN.\n\nPokračovat?')) return;
    }
    // Logout (smaže POS session) → reload na pos.php → keypad screen
    try {
      await fetch('../api/pos_auth.php?action=logout', {
        method: 'POST', credentials: 'include',
      });
    } catch (e) { /* soft-fail, pokračujeme stejně */ }
    // Zachovat košík v localStorage (loadne se po PIN re-loginu)
    if (State.cart.length > 0) {
      try {
        localStorage.setItem('appek_pos_cart_resume', JSON.stringify({
          cart: State.cart, odberatel: State.odberatel,
          pos_typ: State.pos_typ, pos_payment: State.pos_payment,
          pos_tip: State.pos_tip, sleva_pct: State.sleva_pct,
          poznamka: State.poznamka, saved_at: Date.now(),
        }));
      } catch (e) {}
    }
    // Reload → pos.php zjistí že nejsme přihlášeni → PIN keypad screen
    window.location.reload();
  }

  // 🆕 v2.9.299 — Auto-resume košíku po switch user (pokud existuje)
  function resumeCartIfAny() {
    try {
      const raw = localStorage.getItem('appek_pos_cart_resume');
      if (!raw) return;
      const d = JSON.parse(raw);
      // Resume jen pokud < 5 minut staré (jinak je to zbytek)
      // 🐛 v2.9.315 — !d.saved_at guard: undefined saved_at → Date.now() - undefined = NaN
      // → check failnul OPEN → stale entry zůstal navždy v localStorage.
      if (!d || !d.saved_at || Date.now() - d.saved_at > 5 * 60 * 1000) {
        localStorage.removeItem('appek_pos_cart_resume');
        return;
      }
      if (Array.isArray(d.cart) && d.cart.length > 0) {
        State.cart = d.cart;
        State.odberatel = d.odberatel;
        State.pos_typ = d.pos_typ || 'sebou';
        State.pos_payment = d.pos_payment || 'hotove';
        State.pos_tip = d.pos_tip || 0;
        State.sleva_pct = d.sleva_pct || 0;
        State.poznamka = d.poznamka || '';
        toast('✓ Pokračujeme s košíkem od předchozího prodavače (' + d.cart.length + ' pol.)', 'success');
      }
      localStorage.removeItem('appek_pos_cart_resume');
    } catch (e) {}
  }
  // Zavolat po loadCatalog (po inicializaci stavu)
  const origLoadCatalog = loadCatalog;
  loadCatalog = async function() {
    const r = await origLoadCatalog.apply(this, arguments);
    resumeCartIfAny();
    return r;
  };

  // ─── Public POS object ───────────────────────────────────────
  window.POS = {
    switchUser:    switchUser,   // 🆕 v2.9.299
    search:        setSearch,
    newOrder:      newOrder,
    pickCustomer:  pickCustomer,
    setTyp:        setTyp,
    setPay:        setPay,
    payMenu:       payMenu,
    menuToggle:    menuToggle,
    printReceipt:  printReceipt,
    saveDraft:     saveDraft,
    showDrafts:    showDrafts,    // 🆕 v2.9.279
    loadDraft:     loadDraft,     // 🆕 v2.9.279
    _delDraft:     _delDraft,     // 🆕 v2.9.279
    closeModal:    closeModal,    // 🆕 v2.9.279 — public for showDrafts
    addNote:       addNote,
    finish:        finish,
    resetCart:     resetCart,
    setTab:        setActiveTab,
    openCustomItem: openCustomItem,
    _pickCust:     _pickCust,
    _closeModal:   closeModal,
    _printBrowser: _printBrowser,
    _printThermal: _printThermal,
    _customPreset: customPreset,
    _addCustomItem: addCustomItem,
    state:         State,
    reload:        loadCatalog,
    cartOpen:      cartOpen,        // 🆕 v3.0.240 — mobilní košík
    cartClose:     cartClose,
    mobileView:    mobileView,
    setMcartMode:  setMcartMode,
  };

  // ─── Tab switching (Produkty / Účtenky / Statistiky) ─────────
  function setActiveTab(tab) {
    State.activeTab = tab;
    // Header tabs UI
    $$('.pos-tab-h').forEach(el => el.classList.toggle('active', el.dataset.tab === tab));
    // Hlavní content swap
    const products = $('.pos-products');
    const cart     = $('#pos-cart-panel');
    let panel = document.getElementById('pos-tab-content');
    if (tab === 'products') {
      // Show products + cart
      document.body.classList.remove('pos-tab-alt');   // 🆕 v3.0.240 — povol mobilní lištu/přepínač
      if (products) products.style.display = '';
      if (cart) cart.style.display = '';
      if (panel) panel.remove();
    } else {
      // Hide products+cart, render alternate panel
      document.body.classList.add('pos-tab-alt');       // 🆕 v3.0.240 — skryj mobilní lištu/přepínač mimo KASU
      cartClose();
      if (products) products.style.display = 'none';
      if (cart) cart.style.display = 'none';
      if (!panel) {
        panel = document.createElement('div');
        panel.id = 'pos-tab-content';
        panel.className = 'pos-tab-panel';
        const main = document.querySelector('.pos-main');
        if (main) main.appendChild(panel);
      }
      if (tab === 'orders')   renderHistory(panel);
      if (tab === 'reports')  renderReports(panel);
      if (tab === 'tables')   renderTablesTab(panel);  // 🆕 v3.0.8
      if (tab === 'uzaverka') renderUzaverka(panel);   // 🆕 v3.0.210
    }
  }
  window.posSetTab = setActiveTab;

  // ─── 🆕 v3.0.8/10 — Floor view tab (stoly) ─────────────────────
  // 🔄 v3.0.15 — Plný floor map view (reuse admin layout: x/y pozice, canvas per zóna)
  async function renderTablesTab(panel) {
    panel.innerHTML = `<div class="pos-loading" style="padding:40px;text-align:center">⏳ Načítám mapu restaurace…</div>`;
    try {
      const [tablesResp, uctyResp] = await Promise.all([
        api('admin_tables.php'),
        api('admin_pos.php?action=open_ucty'),
      ]);
      const tables = (tablesResp.stoly || tablesResp.tables || tablesResp.items || []);
      const zones  = (tablesResp.zones && tablesResp.zones.length)
                     ? tablesResp.zones
                     : [{ id: null, nazev: 'Hlavní sál', ikona: '🍽️', canvas_w: 800, canvas_h: 500, bg_barva: '#FFFAF1' }];
      const ucty   = (uctyResp.ucty || []);

      // Index account podle stul_id
      const uByStul = {};
      ucty.forEach(u => { uByStul[u.stul_id] = u; });

      // Aktivní zóna (default = první)
      if (!State._floorZoneId || !zones.find(z => String(z.id) === String(State._floorZoneId))) {
        State._floorZoneId = zones[0].id;
      }
      const activeZone = zones.find(z => String(z.id) === String(State._floorZoneId)) || zones[0];
      const stolyInZone = tables.filter(t => String(t.zone_id || '') === String(activeZone.id || ''));

      // Per-zone stats v aktivní zóně
      const obsazenoInZone = stolyInZone.filter(t => uByStul[t.id]).length;
      const volnoInZone    = stolyInZone.length - obsazenoInZone;
      const totalAll       = tables.length;

      panel.innerHTML = `
        <div class="pos-floor-wrap">
          <!-- Stats banner -->
          <div class="pos-floor-stats">
            <div class="pos-floor-stat free">
              <div class="pos-floor-stat-lbl">🟢 Volné (zóna)</div>
              <div class="pos-floor-stat-num">${volnoInZone}</div>
            </div>
            <div class="pos-floor-stat busy">
              <div class="pos-floor-stat-lbl">🟡 Obsazené (zóna)</div>
              <div class="pos-floor-stat-num">${obsazenoInZone}</div>
            </div>
            <div class="pos-floor-stat total">
              <div class="pos-floor-stat-lbl">📊 Celkem (vše)</div>
              <div class="pos-floor-stat-num">${totalAll}</div>
            </div>
          </div>

          ${tables.length === 0 ? `
            <div class="pos-floor-empty">
              <div class="ic">🪑</div>
              <div class="t">Žádné stoly</div>
              <div class="s">Sestav si mapu v <strong>Admin → Restaurace → Stoly → Floor plan</strong> (drag & drop editor). Pak se ti tady objeví a budeš v ní účtovat klikem.</div>
            </div>
          ` : `
            <!-- Zone tabs (každá zóna = vlastní mapa) -->
            <div class="pos-floor-tabs">
              ${zones.map(z => {
                const cnt = tables.filter(t => String(t.zone_id || '') === String(z.id || '')).length;
                const isAct = String(z.id) === String(activeZone.id);
                return `
                  <button class="pos-floor-tab ${isAct ? 'is-active' : ''}" onclick="posSwitchFloorZone(${z.id === null ? 'null' : JSON.stringify(z.id)})">
                    <span>${esc(z.ikona || '🍽️')}</span>
                    <span>${esc(z.nazev)}</span>
                    <span class="badge">${cnt}</span>
                  </button>
                `;
              }).join('')}
              <button class="btn-secondary" onclick="renderTablesTab(document.getElementById('pos-tab-content'))" style="margin-left:auto;padding:8px 14px" title="Obnovit data">🔄</button>
              <button class="btn-secondary" onclick="posOpenFloorEditor()" style="padding:8px 14px;background:linear-gradient(135deg,#EFF6FF,#DBEAFE);border-color:#93C5FD;color:#1E40AF;font-weight:700" title="Otevřít Floor plan editor v adminu (drag&drop layout)">✏️ Floor plan</button>
            </div>

            <!-- Canvas — interaktivní mapa aktivní zóny
                 🆕 v3.0.84 — CSS vars na wrap pro aspect-ratio + scale calc v pos.css -->
            <div class="pos-floor-canvas-wrap"
                 style="--canvas-w-num:${activeZone.canvas_w || 800};--canvas-h-num:${activeZone.canvas_h || 500};--canvas-w-px:${activeZone.canvas_w || 800}px">
              <div class="pos-floor-canvas" style="width:${activeZone.canvas_w || 800}px;height:${activeZone.canvas_h || 500}px;background:${esc(activeZone.bg_barva || '#FFFAF1')}">
                ${stolyInZone.length === 0 ? `
                  <div style="position:absolute;inset:0;display:flex;flex-direction:column;align-items:center;justify-content:center;color:#9CA3AF;text-align:center;padding:20px">
                    <div style="font-size:48px;margin-bottom:10px">🪑</div>
                    <div style="font-weight:700">Tahle zóna nemá žádné stoly</div>
                    <div style="font-size:12px;margin-top:6px">Přidej je v Admin → Floor plan editor</div>
                  </div>
                ` : stolyInZone.map(t => renderPosTableTile(t, uByStul[t.id])).join('')}
              </div>
            </div>

            <div style="margin-top:14px;text-align:center;font-size:12px;color:#9CA3AF;line-height:1.6">
              💡 <strong>Klik na stůl</strong> = otevři účet / přidej položky · Pro úpravu layoutu (přidat bar, salonek, přesunout stoly) jdi do
              <strong>Admin → Restaurace → Stoly → Floor plan</strong>
            </div>
          `}
        </div>
      `;
    } catch (e) {
      panel.innerHTML = `<div style="padding:40px;text-align:center;color:#DC2626">❌ Chyba: ${esc(e.message)}</div>`;
    }
  }
  window.renderTablesTab = renderTablesTab;

  // Render jednoho stolu v mapě (absolute pozice + state-based barva)
  function renderPosTableTile(t, ucet) {
    const w = parseInt(t.width)  || 80;
    const h = parseInt(t.height) || 80;
    const x = parseInt(t.x) || 0;
    const y = parseInt(t.y) || 0;
    const shape = t.tvar === 'round' ? '50%' : (t.tvar === 'rect' ? '14px' : '12px');

    // Stav: pokud má otevřený POS účet → occupied (i kdyby t.stav řekl něco jiného)
    const stav = ucet ? 'occupied' : (t.stav || 'free');
    const sum = ucet ? parseFloat(ucet.castka_celkem || 0) : 0;
    const pcs = ucet ? parseInt(ucet.pocet_polozek || 0) : 0;
    const min = ucet ? minutesSince(ucet.otevreno_v) : 0;

    const nazev = (t.nazev || '?').replace(/'/g, '&#39;');
    const fontSize = w >= 120 ? '15px' : '13px';

    return `
      <div class="pos-floor-tile state-${stav}"
           style="left:${x}px;top:${y}px;width:${w}px;height:${h}px;border-radius:${shape}"
           onclick="posOpenTable(${t.id}, '${esc(nazev)}')"
           title="${esc(t.nazev)} — ${t.mist || '?'} míst${ucet ? ` · ${fmt(sum)} Kč · ${min} min` : ''}">
        <div class="pos-floor-tile-name" style="font-size:${fontSize}">${esc(t.nazev)}</div>
        ${t.mist > 0 ? `<div class="pos-floor-tile-mist">👥 ${t.mist}</div>` : ''}
        ${ucet ? `<div class="pos-floor-tile-info">${fmt(sum)} Kč</div>` : ''}
        ${ucet ? `<div style="font-size:9px;font-weight:600;opacity:0.7">${pcs} pol · ${min}m</div>` : ''}
      </div>
    `;
  }

  // Přepnutí zóny
  window.posSwitchFloorZone = function(zoneId) {
    State._floorZoneId = zoneId;
    renderTablesTab(document.getElementById('pos-tab-content'));
  };

  // 🆕 v3.0.18 — Otevři Floor plan editor v adminu (nové okno)
  window.posOpenFloorEditor = function() {
    // 🐛 fix v3.0.202 — dřív '../admin/#/restaurace?...' → admin SPA nemá hash-routing →
    //   otevřela se jen úvodní stránka (frontpage). Floor plan editor je samostatná stránka.
    const w = window.open(
      'floorplan.php',
      'appek_floor_editor',
      `width=${Math.min(1400, screen.availWidth)},height=${Math.min(900, screen.availHeight)},toolbar=no,menubar=no,scrollbars=yes,resizable=yes`
    );
    if (!w) return toast('Povol popup okna pro editaci layoutu', 'error');
    toast('✏️ Floor plan editor otevřen v novém okně', 'info');
    // Auto-refresh tabu po 60s (jakmile user uloží layout v adminu)
    setTimeout(() => {
      const panel = document.getElementById('pos-tab-content');
      if (panel && document.querySelector('.pos-floor-wrap')) {
        renderTablesTab(panel);
      }
    }, 60000);
  };

  function minutesSince(iso) {
    if (!iso) return 0;
    const dt = new Date(String(iso).replace(' ', 'T'));
    return Math.max(0, Math.floor((Date.now() - dt.getTime()) / 60000));
  }

  // ─── 🆕 v3.0.8 — POS Table modal (otevři účet stolu) ──────────
  window.posOpenTable = async function(stulId, stulNazev) {
    try {
      // GET ?action=ucet&stul_id=X → vrátí existující nebo otevře nový + nastaví stůl jako occupied
      const ucet = await api('admin_pos.php?action=ucet&stul_id=' + stulId);
      if (!ucet || !ucet.id) throw new Error('Nelze získat účet');
      posShowTableUcetModal(ucet.id, stulNazev);
    } catch (e) {
      toast('Nelze otevřít účet: ' + e.message, 'error');
    }
  };

  async function posShowTableUcetModal(ucetId, stulNazev) {
    // Modal v3.0.12 — širší, dotykový, responsivní
    let m = document.getElementById('pos-table-modal');
    if (m) m.remove();
    m = document.createElement('div');
    m.id = 'pos-table-modal';
    m.className = 'pos-tm';
    // Detekce mobile (pro tab state)
    const isMobile = window.innerWidth < 800;
    m.innerHTML = `
      <div class="pos-tm-box">
        <header class="pos-tm-head">
          <div class="pos-tm-title">
            <h2>🪑 ${esc(stulNazev)} · Účet #${ucetId}</h2>
            <span class="pos-tm-status" id="pos-tm-status">⏳ Načítám…</span>
            <span class="pos-tm-meta" id="pos-tm-meta"></span>
          </div>
          <button class="pos-tm-close" onclick="document.getElementById('pos-table-modal').remove()" title="Zavřít (Esc)">✕</button>
        </header>

        <!-- Mobile tabs (jen pod 800px) -->
        <div class="pos-tm-mobile-tabs">
          <button class="${isMobile ? 'is-active' : ''}" data-mob-tab="cart">📋 Účet (<span id="pos-tm-pcs">0</span>)</button>
          <button data-mob-tab="menu">➕ Přidat</button>
        </div>

        <div class="pos-tm-body" id="pos-tm-body" data-mob="${isMobile ? 'cart' : ''}">
          <!-- Vlevo: cart -->
          <div class="pos-tm-cart" id="pos-table-cart-wrap">
            <div class="pos-tm-cart-head">📋 Položky účtu (<span id="pos-tm-cart-cnt">0</span>)</div>
            <div class="pos-tm-cart-list" id="pos-table-cart">
              <div class="pos-tm-cart-empty"><span class="ic">⏳</span><div class="t">Načítám…</div></div>
            </div>
            <div class="pos-tm-cart-foot" id="pos-tm-cart-foot" style="display:none">
              <div class="pos-tm-sum-row"><span>Bez DPH</span><span id="pos-tm-sum-bez">0,00 Kč</span></div>
              <div class="pos-tm-sum-row"><span>DPH</span><span id="pos-tm-sum-dph">0,00 Kč</span></div>
              <div class="pos-tm-sum-row is-total"><span>CELKEM</span><span id="pos-tm-sum-total">0,00 Kč</span></div>
            </div>
          </div>

          <!-- Vpravo: menu -->
          <div class="pos-tm-menu">
            <div class="pos-tm-menu-head">
              <input type="text" id="pos-table-search" class="pos-tm-search" placeholder="🔍 Hledat položku…">
            </div>
            <div class="pos-tm-cats" id="pos-table-cats"></div>
            <div class="pos-tm-grid" id="pos-table-grid">
              <div style="grid-column:1/-1;text-align:center;padding:40px;color:#9CA3AF">⏳ Načítám menu…</div>
            </div>
          </div>
        </div>

        <footer class="pos-tm-foot">
          <button class="pos-tm-btn" id="pos-tbl-bon">🍳 Tisk bonu</button>
          <button class="pos-tm-btn" id="pos-tbl-kitchen">📨 Do kuchyně</button>
          <button class="pos-tm-btn" id="pos-tbl-ucet">📤 Tisk účtu</button>
          <button class="pos-tm-btn" id="pos-tbl-qr">📲 QR platba</button>
          <button class="pos-tm-btn is-danger" id="pos-tbl-reopen" style="display:none">🔓 Znovu otevřít</button>
          <div class="pos-tm-btn-spacer"></div>
          <button class="pos-tm-btn is-save" id="pos-tbl-save">💾 Uložit <span class="pos-tm-btn-sub">a zavřít</span></button>
          <button class="pos-tm-btn is-primary" id="pos-tbl-pay">💰 Zaplatit <span class="pos-tm-btn-sub">a zavřít</span></button>
        </footer>
      </div>
    `;
    document.body.appendChild(m);

    // Mobile tab switch
    m.querySelectorAll('.pos-tm-mobile-tabs button').forEach(b => {
      b.onclick = () => {
        m.querySelectorAll('.pos-tm-mobile-tabs button').forEach(x => x.classList.remove('is-active'));
        b.classList.add('is-active');
        document.getElementById('pos-tm-body').dataset.mob = b.dataset.mobTab;
      };
    });

    // Loaduj data
    await refreshTableModal(ucetId);

    // Search binding
    document.getElementById('pos-table-search').addEventListener('input', e => {
      filterTableMenu(e.target.value);
    });

    // Footer actions
    document.getElementById('pos-tbl-bon').onclick = () => {
      const w = window.open(`../api/admin_pos_print.php?ucet_id=${ucetId}&typ=kuchyne&autoprint=1`,
        'appek_bon_print', 'width=380,height=640,toolbar=no');
      if (!w) return toast('Povolte popup okna pro tisk', 'error');
      toast('🍳 Bon odeslán na tisk', 'success');
    };
    // 🆕 v3.0.156 — odeslat nevystřelené položky účtu na KDS board (ruční režim; idempotentní)
    document.getElementById('pos-tbl-kitchen').onclick = async () => {
      try {
        const r = await api('admin_pos.php?action=fire_kitchen', { method:'POST', body: JSON.stringify({ ucet_id: ucetId }) });
        const n = r.fired || 0;
        toast(n > 0 ? `🍳 Odesláno ${n} pol. do kuchyně` : 'Vše už je v kuchyni 👍', n > 0 ? 'success' : 'info');
      } catch (e) { toast('Chyba: ' + e.message, 'error'); }
    };
    document.getElementById('pos-tbl-ucet').onclick = () => {
      const w = window.open(`../api/admin_pos_print.php?ucet_id=${ucetId}&typ=ucet&autoprint=1`,
        'appek_ucet_print', 'width=380,height=640,toolbar=no');
      if (!w) return toast('Povolte popup okna pro tisk', 'error');
      toast('📤 Účet odeslán na tisk', 'success');
    };
    document.getElementById('pos-tbl-qr').onclick = async () => {
      // Vygeneruj QR pay token pro otevřený účet (POST create_token na pay_qr.php)
      // Note: pay_qr endpoint očekává `objednavka_id` z `objednavky` tabulky.
      // Pro POS účet (restaurant_pos_ucty) musí být nejdřív uzavřený → vytvořená objednávka.
      // Zatím jen info + tlačítko otevři v adminu
      toast('💡 QR platba: nejdřív uzavři účet ("Zaplatit") → pak v účtence klik "📲 QR platba"', 'info');
    };
    document.getElementById('pos-tbl-reopen').onclick = () => {
      if (!confirm('Znovu otevřít zavřený účet? (pouze pro opravy)')) return;
      posTableReopen(ucetId);
    };
    document.getElementById('pos-tbl-save').onclick = () => {
      // Položky jsou už uloženy (auto-save on click). Save = jen zavřít modal + refresh floor.
      toast('💾 Účet uložen (rozpracovaný)', 'success');
      m.remove();
      const panel = document.getElementById('pos-tab-content');
      if (panel) renderTablesTab(panel);
    };
    document.getElementById('pos-tbl-pay').onclick = () => {
      const ok = confirm('Uzavřít účet a označit jako ZAPLACENO (hotovostí)?\n\nÚčet se vytiskne automaticky.');
      if (!ok) return;
      // Tisk + close
      const w = window.open(`../api/admin_pos_print.php?ucet_id=${ucetId}&typ=ucet&autoprint=1`,
        'appek_ucet_print', 'width=380,height=640,toolbar=no');
      posTableCloseUcet(ucetId, 'hotove');
    };

    // ESC zavře modal
    const escHandler = e => {
      if (e.key === 'Escape') {
        m.remove();
        document.removeEventListener('keydown', escHandler);
      }
    };
    document.addEventListener('keydown', escHandler);
  }

  window.posTableReopen = async function(ucetId) {
    try {
      // Reopen — manually toggle stav back to 'open' (admin only, validate in backend)
      // Pro MVP: alert info že feature je v adminu
      toast('🔓 Reopen účtu: zatím přes admin → Restaurace → Otevřené účty', 'info');
    } catch (e) {
      toast('Chyba: ' + e.message, 'error');
    }
  };

  async function refreshTableModal(ucetId) {
    try {
      const [detailResp, catalogResp] = await Promise.all([
        api('admin_pos.php?action=ucet_detail&id=' + ucetId).catch(() => null),
        api('admin_pos.php?action=catalog'),
      ]);
      window.__posTableMenu = catalogResp.vyrobky || [];
      window.__posTableKategorie = catalogResp.kategorie || [];
      window.__posTableUcetId = ucetId;
      window.__posTableActiveCat = window.__posTableActiveCat || 0; // 0 = vše

      renderTableCart(detailResp);
      renderTableCategories();
      renderTableMenu(window.__posTableMenu);
    } catch (e) {
      document.getElementById('pos-table-cart').innerHTML = `<div style="color:#DC2626;padding:20px">❌ ${esc(e.message)}</div>`;
    }
  }

  function renderTableCategories() {
    const c = document.getElementById('pos-table-cats');
    if (!c) return;
    const cats = window.__posTableKategorie || [];
    const active = window.__posTableActiveCat || 0;
    c.innerHTML = `
      <button class="pos-tm-cat ${active === 0 ? 'is-active' : ''}" onclick="posTableSetCat(0)">⭐ Vše</button>
      ${cats.map(k => `
        <button class="pos-tm-cat ${active === k.id ? 'is-active' : ''}" onclick="posTableSetCat(${k.id})">
          ${esc(k.ikona || '📦')} ${esc(k.nazev)}
        </button>
      `).join('')}
    `;
  }
  window.posTableSetCat = function(catId) {
    window.__posTableActiveCat = catId;
    renderTableCategories();
    const items = (window.__posTableMenu || []).filter(v => !catId || v.kategorie_id === catId);
    renderTableMenu(items);
    // Clear search
    const s = document.getElementById('pos-table-search');
    if (s) s.value = '';
  };

  function renderTableCart(detail) {
    const c = document.getElementById('pos-table-cart');
    if (!c) return;
    const polozky = ((detail && detail.polozky) || []).filter(p => p.stav !== 'storno');

    // Header counter
    const cnt = document.getElementById('pos-tm-cart-cnt');
    if (cnt) cnt.textContent = polozky.length;
    const pcs = document.getElementById('pos-tm-pcs');
    if (pcs) pcs.textContent = polozky.length;

    // Status badge
    const statusEl = document.getElementById('pos-tm-status');
    const reopenBtn = document.getElementById('pos-tbl-reopen');
    const saveBtn = document.getElementById('pos-tbl-save');
    const payBtn = document.getElementById('pos-tbl-pay');
    if (detail && statusEl) {
      const stav = detail.stav || 'open';
      const labels = {
        open:      ['is-open', '🟡 Rozpracovaný'],
        paid:      ['is-paid', '✅ Zaplaceno'],
        cancelled: ['is-cancelled', '✕ Storno'],
      };
      const [cls, lbl] = labels[stav] || ['is-open', stav];
      statusEl.className = 'pos-tm-status ' + cls;
      statusEl.textContent = lbl;
      // Toggle reopen vs save/pay buttons podle stavu
      if (reopenBtn) reopenBtn.style.display = (stav === 'paid' || stav === 'cancelled') ? 'inline-flex' : 'none';
      if (saveBtn)   saveBtn.style.display   = (stav === 'open') ? 'inline-flex' : 'none';
      if (payBtn)    payBtn.style.display    = (stav === 'open') ? 'inline-flex' : 'none';
    }
    // Meta (čas otevření)
    const metaEl = document.getElementById('pos-tm-meta');
    if (detail && metaEl && detail.otevreno_v) {
      const min = minutesSince(detail.otevreno_v);
      metaEl.textContent = `· otevřen ${min} min · ${esc(detail.otevrel_jmeno || '?')}`;
    }

    // Summary (sum bez DPH, sum DPH, total)
    let sumBez = 0, sumDph = 0;
    polozky.forEach(p => {
      const mn = parseFloat(p.mnozstvi) || 0;
      const jc = parseFloat(p.jednotkova_cena) || 0; // s DPH
      // Z restaurant_pos_polozky neznáme přesný sazba_dph, default 21
      const sazba = 21;
      const cenaCelk = mn * jc;
      const cenaBez = cenaCelk / (1 + sazba / 100);
      sumBez += cenaBez;
      sumDph += cenaCelk - cenaBez;
    });
    const sumTotal = sumBez + sumDph;

    const sumBezEl = document.getElementById('pos-tm-sum-bez');
    const sumDphEl = document.getElementById('pos-tm-sum-dph');
    const sumTotEl = document.getElementById('pos-tm-sum-total');
    const foot = document.getElementById('pos-tm-cart-foot');
    if (sumBezEl) sumBezEl.textContent = fmt(sumBez) + ' Kč';
    if (sumDphEl) sumDphEl.textContent = fmt(sumDph) + ' Kč';
    if (sumTotEl) sumTotEl.textContent = fmt(sumTotal) + ' Kč';
    if (foot)     foot.style.display = polozky.length > 0 ? 'block' : 'none';

    if (polozky.length === 0) {
      c.innerHTML = `
        <div class="pos-tm-cart-empty">
          <span class="ic">🛒</span>
          <div class="t">Účet je prázdný</div>
          <div class="s">Klik na položku z menu</div>
        </div>`;
      return;
    }
    const isOpen = ((detail && detail.stav) || 'open') === 'open'; // 🆕 v3.0.204 — +/− jen na otevřeném účtu
    c.innerHTML = polozky.map(p => {
      const mn = parseFloat(p.mnozstvi) || 0;
      const jc = parseFloat(p.jednotkova_cena) || 0;
      const cena = mn * jc;
      const stavCls = p.stav === 'hotovo' ? 'is-done' : (p.stav === 'vari_se' ? 'is-cooking' : '');
      const stavIc  = p.stav === 'hotovo' ? '<span class="stav-ic">✓ hotovo</span>'
                    : p.stav === 'vari_se' ? '<span class="stav-ic">🔥 vaří se</span>'
                    : '';
      return `
        <div class="pos-tm-item ${stavCls}">
          <div class="pos-tm-item-info">
            <div class="pos-tm-item-name">${esc(p.nazev || '?')}${stavIc}</div>
            <div class="pos-tm-item-meta">${fmt(jc)} Kč/ks</div>
          </div>
          ${isOpen ? `
          <div class="pos-tm-qty">
            <button class="pos-tm-qbtn" onclick="posTableItemQty(${p.id}, ${mn}, -1)" aria-label="Méně">−</button>
            <span class="pos-tm-qval">${mn}</span>
            <button class="pos-tm-qbtn" onclick="posTableItemQty(${p.id}, ${mn}, 1)" aria-label="Více">+</button>
          </div>` : `<div class="pos-tm-item-qstatic">${mn}×</div>`}
          <div class="pos-tm-item-price">${fmt(cena)} Kč</div>
          ${isOpen ? `<button class="pos-tm-item-rm" onclick="posTableRemoveItem(${p.id})" title="Odebrat">✕</button>` : ''}
        </div>
      `;
    }).join('');
  }

  window.posTableCloseUcet = async function(ucetId, payment) {
    try {
      const r = await api('admin_pos.php?action=pay', {
        method: 'POST',
        body: JSON.stringify({ ucet_id: ucetId, payment: payment || 'hotove' }),
      });
      toast(t('pos_bill_closed', { cislo: r.cislo || '', amount: fmt(r.celkem || 0) }), 'success');
      const m = document.getElementById('pos-table-modal');
      if (m) m.remove();
      // Refresh floor tab
      const panel = document.getElementById('pos-tab-content');
      if (panel) renderTablesTab(panel);
    } catch (e) {
      toast('Chyba zavírání účtu: ' + e.message, 'error');
    }
  };

  window.posTableRemoveItem = async function(itemId) {
    if (!confirm('Odebrat položku z účtu?')) return;
    try {
      await api('admin_pos.php?action=item_state', {
        method: 'POST', body: JSON.stringify({ id: itemId, stav: 'storno' })
      });
      toast('✕ Odebráno', 'success');
      refreshTableModal(window.__posTableUcetId);
    } catch (e) {
      toast('Chyba: ' + e.message, 'error');
    }
  };

  // 🆕 v3.0.204 — +/− množství položky přímo v účtu stolu. Pod 1 ks → nabídne storno.
  window.posTableItemQty = async function(itemId, current, delta) {
    const next = +((parseFloat(current) || 1) + delta).toFixed(3);
    if (next < 1) return posTableRemoveItem(itemId);
    try {
      await api('admin_pos.php?action=item_qty', {
        method: 'POST', body: JSON.stringify({ id: itemId, mnozstvi: next })
      });
      refreshTableModal(window.__posTableUcetId);
    } catch (e) {
      toast('Chyba: ' + e.message, 'error');
    }
  };

  function renderTableMenu(items) {
    const g = document.getElementById('pos-table-grid');
    if (!g) return;
    if (!items || items.length === 0) {
      g.innerHTML = `<div style="grid-column:1/-1;text-align:center;padding:40px;color:#9CA3AF">Žádné položky v menu</div>`;
      return;
    }
    g.innerHTML = items.slice(0, 120).map(v => {
      const ceneBez = parseFloat(v.cena_bez_dph || 0);
      const sazba = parseFloat(v.sazba_dph || 21);
      const cenaS = ceneBez * (1 + sazba / 100);
      return `
        <button class="pos-tm-prod" onclick="posTableAddItem(${v.id})">
          <div class="pos-tm-prod-name">${esc(v.nazev || '?')}</div>
          <div class="pos-tm-prod-price">${fmt(cenaS)} Kč</div>
        </button>
      `;
    }).join('');
  }

  function filterTableMenu(q) {
    q = (q || '').toLowerCase().trim();
    const cat = window.__posTableActiveCat || 0;
    let items = window.__posTableMenu || [];
    if (cat) items = items.filter(v => v.kategorie_id === cat);
    if (q)   items = items.filter(v => (v.nazev || '').toLowerCase().includes(q));
    renderTableMenu(items);
  }

  window.posTableAddItem = async function(vyrobekId) {
    const ucetId = window.__posTableUcetId;
    if (!ucetId) return;
    const item = (window.__posTableMenu || []).find(v => v.id === vyrobekId);
    if (!item) return;
    try {
      // POST ?action=item — správný název endpointu
      // jednotková_cena = s DPH (POS pracuje s "konečnou" cenou)
      const ceneBez = parseFloat(item.cena_bez_dph || 0);
      const sazba   = parseFloat(item.sazba_dph || 21);
      const cenaS   = ceneBez * (1 + sazba / 100);
      await api('admin_pos.php?action=item', {
        method: 'POST',
        body: JSON.stringify({
          ucet_id: ucetId,
          vyrobek_id: vyrobekId,
          nazev: item.nazev,
          mnozstvi: 1,
          jednotkova_cena: cenaS,
          kategorie: item.kategorie_id ? String(item.kategorie_id) : null,
        }),
      });
      toast(t('pos_item_added', { nazev: item.nazev }), 'success');
      refreshTableModal(ucetId);
    } catch (e) {
      toast('Chyba: ' + e.message, 'error');
    }
  };

  // ─── History / Účtenky ───────────────────────────────────────
  async function renderHistory(panel, append = false) {
    if (!append) panel.innerHTML = `<div class="pos-loading" style="padding:40px;text-align:center">⏳ Načítám účtenky…</div>`;
    try {
      const today = new Date().toISOString().slice(0, 10);
      const date = State._historyDate || today;
      State._historyDate = date;
      // 🆕 v3.0.219 — paging Účtenek (POS = touch → „Načíst další")
      if (!append) { State._historyItems = []; }
      const offset = append ? (State._historyItems ? State._historyItems.length : 0) : 0;
      const pagLimit = (CFG.pagPocet && [25, 50, 100, 200].includes(CFG.pagPocet)) ? CFG.pagPocet : 50; // 🆕 v3.0.249 — počet/stránku z nastavení
      const d = await api('admin_pos.php?action=quick_history&date=' + encodeURIComponent(date) + '&offset=' + offset + '&limit=' + pagLimit);
      const batch = d.objednavky || [];
      State._historyItems = append ? (State._historyItems || []).concat(batch) : batch;
      State._historyTotal = Number.isFinite(d.total) ? d.total : State._historyItems.length;
      const orders = State._historyItems;
      const s = d.souhrn || {};

      const TYP_LABEL = {
        sebou: '🛍️ Sebou', na_miste: '🍽️ Na místě',
        vyzvednuti: '📦 Vyzvednutí', rozvoz: '🛵 Rozvoz'
      };
      const PAY_LABEL = {
        hotove: '💵 Hotově', karta: '💳 Karta',
        paypal: '💼 PayPal', gift_card: '🎁 Gift card',
        voucher: '🎟️ Voucher', mobile: '📱 Mobile'
      };
      const STAV_BADGE = (stav) => {
        const map = {
          'zaplaceno': { bg: '#DCFCE7', fg: '#166534', label: '✓ Zaplaceno' },
          'nova':      { bg: '#FEF3C7', fg: '#92400E', label: '⏱ Nová' },
          'zruseno':   { bg: '#FEE2E2', fg: '#991B1B', label: '✕ Storno' },
        };
        const m = map[stav] || { bg: '#E5E7EB', fg: '#374151', label: stav };
        return `<span style="background:${m.bg};color:${m.fg};padding:3px 10px;border-radius:999px;font-size:12px;font-weight:700">${m.label}</span>`;
      };

      panel.innerHTML = `
        <div class="pos-history-wrap">
          <div class="pos-history-head">
            <div class="pos-history-title">
              <h2 style="margin:0 0 4px;font-size:24px;font-weight:800">📜 Účtenky</h2>
              <p style="margin:0;color:#6b7280;font-size:14px">Den ${fmtDateCs(date)} · ${State._historyTotal} účtenek${orders.length < State._historyTotal ? ` · zobrazeno ${orders.length}` : ''}</p>
            </div>
            <div class="pos-history-date">
              <button class="pos-history-day-btn" onclick="posShiftDay(-1)">← Předchozí</button>
              <input type="date" id="pos-history-date" value="${date}" onchange="posSetHistoryDate(this.value)" style="padding:10px 14px;border:2px solid #e1e5eb;border-radius:10px;font-size:16px;font-family:inherit">
              <button class="pos-history-day-btn" onclick="posShiftDay(+1)" ${date === today ? 'disabled' : ''}>Další →</button>
              <button class="pos-history-day-btn" onclick="posSetHistoryDate('${today}')">📅 Dnes</button>
            </div>
          </div>

          <div class="pos-history-stats">
            <div class="pos-hs-card"><div class="pos-hs-label">Účtenek</div><div class="pos-hs-value">${s.pocet ?? 0}</div></div>
            <div class="pos-hs-card pos-hs-success"><div class="pos-hs-label">Tržby celkem</div><div class="pos-hs-value">${fmt(s['tržby'] ?? 0)} Kč</div></div>
            <div class="pos-hs-card"><div class="pos-hs-label">💵 Hotově</div><div class="pos-hs-value">${fmt(s.hotove ?? 0)} Kč</div></div>
            <div class="pos-hs-card"><div class="pos-hs-label">💳 Kartou</div><div class="pos-hs-value">${fmt(s.karta ?? 0)} Kč</div></div>
            <div class="pos-hs-card"><div class="pos-hs-label">💰 Tip</div><div class="pos-hs-value">${fmt(s.tip_sum ?? 0)} Kč</div></div>
          </div>

          ${orders.length === 0 ? `
            <div class="pos-history-empty">
              <div style="font-size:56px;opacity:0.4;margin-bottom:14px">📭</div>
              <div style="font-size:18px;font-weight:700;color:#6b7280">Žádné účtenky pro tento den</div>
              <div style="font-size:14px;color:#9097a3;margin-top:6px">Vraťte se na <button onclick="POS.setTab('products')" style="background:transparent;border:none;color:#BA7517;font-weight:700;cursor:pointer;font-size:14px">📦 Produkty</button> a začněte prodávat</div>
            </div>
          ` : `
            <div class="pos-history-table-wrap">
              <table class="pos-history-table">
                <thead>
                  <tr>
                    <th>Čas</th>
                    <th>Číslo</th>
                    <th>Zákazník</th>
                    <th>Položek</th>
                    <th>Typ</th>
                    <th>Platba</th>
                    <th>Stav</th>
                    <th class="num">Částka</th>
                    <th>Akce</th>
                  </tr>
                </thead>
                <tbody>
                  ${orders.map(o => {
                    const time = (o.datum_objednani || '').slice(11, 16);
                    // 🆕 v2.9.308 — celý řádek clickable → otevře detail modal
                    return `
                      <tr data-id="${o.id}" onclick="posShowReceiptDetail(${o.id})" style="cursor:pointer" title="Klikni pro detail účtenky">
                        <td><strong>${time}</strong></td>
                        <td><span style="font-family:monospace;font-weight:700">${esc(o.cislo)}</span></td>
                        <td>${esc(o.odberatel_nazev || '—')}${o.pos_uzivatel ? `<div style="font-size:11px;color:#9097a3">obsluhuje ${esc(o.pos_uzivatel)}</div>` : ''}</td>
                        <td><strong>${o.pocet_polozek || 0}</strong></td>
                        <td>${TYP_LABEL[o.pos_typ] || o.pos_typ || '—'}</td>
                        <td>${PAY_LABEL[o.pos_payment] || o.pos_payment || '—'}</td>
                        <td>${STAV_BADGE(o.stav)}</td>
                        <td class="num" style="font-weight:800;font-size:15px">${fmt(o.castka_celkem)} Kč</td>
                        <td>
                          <button class="pos-hist-btn pos-hist-btn-edit" onclick="event.stopPropagation();posOpenOrderInAdmin(${o.id})" title="Otevřít v admin pro úpravu / fakturu / vrácení">
                            ✏️ Upravit
                          </button>
                          <button class="pos-hist-btn" onclick="event.stopPropagation();posReprintReceipt(${o.id})" title="Znovu vytisknout účtenku">
                            🖨️
                          </button>
                        </td>
                      </tr>
                    `;
                  }).join('')}
                </tbody>
              </table>
            </div>
            ${orders.length < State._historyTotal ? `<div style="text-align:center;margin:18px 0">
              <button class="pos-history-day-btn" onclick="posHistoryLoadMore()" style="padding:12px 28px;font-weight:700;font-size:15px">▾ Načíst další (${Math.min(pagLimit, State._historyTotal - orders.length)})</button>
              <div style="margin-top:6px;color:#9097a3;font-size:13px">Zobrazeno ${orders.length} z ${State._historyTotal}</div>
            </div>` : ''}
          `}
        </div>
      `;
    } catch (e) {
      panel.innerHTML = `<div class="pos-error" style="margin:20px">⚠️ Chyba načtení historie: ${esc(e.message)}</div>`;
    }
  }
  // 🆕 v3.0.219 — Účtenky: načíst další dávku (append)
  window.posHistoryLoadMore = function() {
    const panel = document.getElementById('pos-tab-content');
    if (panel) renderHistory(panel, true);
  };

  function fmtDateCs(dateStr) {
    try {
      const d = new Date(dateStr);
      return d.toLocaleDateString('cs-CZ', { weekday: 'long', day: 'numeric', month: 'long', year: 'numeric' });
    } catch (e) { return dateStr; }
  }

  // ─── History controls ─────────────────────────────────────────
  window.posSetHistoryDate = function(d) {
    State._historyDate = d;
    const panel = document.getElementById('pos-tab-content');
    if (panel) renderHistory(panel);
  };
  window.posShiftDay = function(delta) {
    const cur = State._historyDate || new Date().toISOString().slice(0, 10);
    const d = new Date(cur);
    d.setDate(d.getDate() + delta);
    const next = d.toISOString().slice(0, 10);
    // Nepřeskoč budoucnost
    const today = new Date().toISOString().slice(0, 10);
    if (next > today) return;
    posSetHistoryDate(next);
  };

  // ─── Otevři objednávku v hlavním adminu (pro úpravu) ────────
  window.posOpenOrderInAdmin = function(objId) {
    // Otevři nové okno s adminu na stránce objednávky, auto-otevřít detail
    const url = '/admin/?page=objednavky&open=' + objId;
    window.open(url, 'appek_admin_order_' + objId,
      'width=' + Math.min(1400, screen.availWidth) +
      ',height=' + Math.min(900, screen.availHeight) +
      ',toolbar=no,menubar=no,resizable=yes,scrollbars=yes');
  };

  // ─── 🆕 v2.9.308 — Detail účtenky (modal) ─────────────────────
  // Klik na řádek v Účtenkách → modal s položkami, totals, akce
  window.posShowReceiptDetail = async function(objId) {
    modal('📜 Účtenka', '<div style="text-align:center;padding:40px;color:#9097a3">⏳ Načítám detail…</div>', '');
    let d;
    try {
      // 🆕 v3.0.27 — Timeout 8s (předtím nekonečno → modal stuck na "Načítám detail…")
      const ctrl = new AbortController();
      const timer = setTimeout(() => ctrl.abort(), 8000);
      const r = await fetch(CFG.apiBase + 'admin_pos.php?action=quick_order_detail&id=' + objId, {
        credentials: 'same-origin',
        signal: ctrl.signal,
        headers: { 'Accept': 'application/json' },
      });
      clearTimeout(timer);
      if (!r.ok) {
        const txt = await r.text();
        throw new Error(`HTTP ${r.status}: ${txt.slice(0, 200)}`);
      }
      d = await r.json();
    } catch (e) {
      const detail = e.name === 'AbortError' ? 'Timeout 8s — backend neodpověděl' : (e.message || 'Neznámá chyba');
      modal('⚠️ Detail se nenačetl',
        `<div style="padding:20px">
          <p style="color:#dc2626;margin-bottom:14px">Detail účtenky #${objId} se nepodařilo načíst:</p>
          <div style="background:#FEE2E2;padding:12px;border-radius:8px;font-family:monospace;font-size:12px;color:#991B1B">${esc(detail)}</div>
          <p style="font-size:12px;color:#6B7280;margin-top:14px">💡 Zkus: hard refresh (Cmd+Shift+R), nebo zkontroluj že jsi přihlášený a backend běží.</p>
        </div>`,
        `<button class="btn-secondary" onclick="POS._closeModal()">Zavřít</button>
         <button class="btn-primary" onclick="posShowReceiptDetail(${objId})">🔄 Zkusit znovu</button>`);
      return;
    }
    const time = (d.datum_objednani || '').replace('T', ' ').slice(0, 16);
    const polozky = Array.isArray(d.polozky) ? d.polozky : [];
    const typLabel = TYP_LABEL[d.pos_typ] || d.pos_typ || '—';
    const payLabel = PAY_LABEL[d.pos_payment] || d.pos_payment || '—';
    const tip = parseFloat(d.pos_tip) || 0;
    const polozkySum = polozky.reduce((s, p) => s + (parseFloat(p.cena_bez_dph) || 0) * (parseFloat(p.mnozstvi) || 0) * (1 + (parseFloat(p.sazba_dph) || 12) / 100), 0);

    const body = `
      <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px;margin-bottom:14px;font-size:13px">
        <div style="background:#F7F8FA;padding:10px 12px;border-radius:8px">
          <div style="color:#9097a3;font-size:11px;text-transform:uppercase;margin-bottom:2px">Čas</div>
          <strong>${esc(time)}</strong>
        </div>
        <div style="background:#F7F8FA;padding:10px 12px;border-radius:8px">
          <div style="color:#9097a3;font-size:11px;text-transform:uppercase;margin-bottom:2px">Číslo</div>
          <strong style="font-family:monospace">${esc(d.cislo || '—')}</strong>
        </div>
        <div style="background:#F7F8FA;padding:10px 12px;border-radius:8px">
          <div style="color:#9097a3;font-size:11px;text-transform:uppercase;margin-bottom:2px">Typ</div>
          <strong>${esc(typLabel)}</strong>
        </div>
        <div style="background:#F7F8FA;padding:10px 12px;border-radius:8px">
          <div style="color:#9097a3;font-size:11px;text-transform:uppercase;margin-bottom:2px">Platba</div>
          <strong>${esc(payLabel)}</strong>
        </div>
        ${d.pos_uzivatel ? `
          <div style="background:#F7F8FA;padding:10px 12px;border-radius:8px;grid-column:1/-1">
            <div style="color:#9097a3;font-size:11px;text-transform:uppercase;margin-bottom:2px">Prodavač</div>
            <strong>👤 ${esc(d.pos_uzivatel)}</strong>
          </div>
        ` : ''}
        ${d.odberatel_nazev && d.odberatel_nazev !== '—' ? `
          <div style="background:#EFF6FF;padding:10px 12px;border-radius:8px;grid-column:1/-1">
            <div style="color:#0C447C;font-size:11px;text-transform:uppercase;margin-bottom:2px">Zákazník</div>
            <strong>${esc(d.odberatel_nazev)}</strong>
          </div>
        ` : ''}
      </div>

      <div style="font-size:12px;font-weight:700;color:#5C6370;margin-bottom:6px;text-transform:uppercase;letter-spacing:0.04em">🛒 Položky (${polozky.length})</div>
      ${polozky.length === 0
        ? `<div style="padding:20px;text-align:center;color:#9097a3;background:#FAFAFA;border-radius:8px;font-size:13px">Žádné položky</div>`
        : `
          <table style="width:100%;border-collapse:collapse;font-size:13px">
            <thead>
              <tr style="background:#F7F8FA;color:#5C6370">
                <th style="text-align:left;padding:8px 10px;font-weight:600">Položka</th>
                <th style="text-align:right;padding:8px 10px;font-weight:600;width:70px">Mn.</th>
                <th style="text-align:right;padding:8px 10px;font-weight:600;width:100px">Cena/ks</th>
                <th style="text-align:right;padding:8px 10px;font-weight:600;width:100px">Celkem</th>
              </tr>
            </thead>
            <tbody>
              ${polozky.map(p => {
                const mn = parseFloat(p.mnozstvi) || 0;
                const cs = (parseFloat(p.cena_bez_dph) || 0) * (1 + (parseFloat(p.sazba_dph) || 12) / 100);
                const sum = mn * cs;
                return `
                  <tr style="border-bottom:1px solid #EFF1F4">
                    <td style="padding:8px 10px">
                      ${esc(p.vyrobek_nazev)}
                      ${p.poznamka ? `<div style="font-size:11px;color:#9097a3;margin-top:2px">💬 ${esc(p.poznamka)}</div>` : ''}
                    </td>
                    <td style="text-align:right;padding:8px 10px;font-variant-numeric:tabular-nums">${mn % 1 ? mn.toFixed(2) : mn.toFixed(0)}${p.jednotka && p.jednotka !== 'ks' ? ' ' + esc(p.jednotka) : '×'}</td>
                    <td style="text-align:right;padding:8px 10px;font-variant-numeric:tabular-nums">${fmt(cs)}</td>
                    <td style="text-align:right;padding:8px 10px;font-weight:700;font-variant-numeric:tabular-nums">${fmt(sum)} Kč</td>
                  </tr>
                `;
              }).join('')}
            </tbody>
          </table>
        `}

      <div style="margin-top:14px;padding-top:12px;border-top:2px solid #E1E5EB">
        <div style="display:flex;justify-content:space-between;font-size:13px;color:#5C6370;padding:3px 0">
          <span>Mezisoučet (bez DPH)</span>
          <span style="font-variant-numeric:tabular-nums">${fmt(parseFloat(d.castka_bez_dph) || 0)} Kč</span>
        </div>
        <div style="display:flex;justify-content:space-between;font-size:13px;color:#5C6370;padding:3px 0">
          <span>DPH</span>
          <span style="font-variant-numeric:tabular-nums">${fmt(parseFloat(d.castka_dph) || 0)} Kč</span>
        </div>
        ${tip > 0 ? `
          <div style="display:flex;justify-content:space-between;font-size:13px;color:#BA7517;padding:3px 0">
            <span>Spropitné</span>
            <span style="font-variant-numeric:tabular-nums">${fmt(tip)} Kč</span>
          </div>
        ` : ''}
        <div style="display:flex;justify-content:space-between;font-size:18px;font-weight:800;color:#1a1d24;padding:8px 0 0;border-top:1px solid #E1E5EB;margin-top:6px">
          <span>CELKEM</span>
          <span style="font-variant-numeric:tabular-nums">${fmt(parseFloat(d.castka_celkem) || 0)} Kč</span>
        </div>
      </div>
    `;

    const foot = `
      <button class="btn-secondary" onclick="POS._closeModal()">Zavřít</button>
      <button class="btn-secondary" onclick="posReprintReceipt(${objId})">🖨️ Reprint</button>
      <button class="btn-secondary" onclick="posShowPayQR(${objId}, '${esc(d.cislo || '')}', ${parseFloat(d.castka_celkem) || 0})">📲 QR platba</button>
      ${(parseFloat(d.castka_celkem) || 0) > 0 ? `<button class="btn-secondary" style="color:#DC2626;border-color:#FCA5A5" onclick="posRefundReceipt(${objId}, '${esc(d.cislo || '').replace(/'/g, '')}')" title="Vrátit peníze — vznikne záporná účtenka (VRA-…), uzávěrka i tržby se sníží">↩️ Vrátit</button>` : ''}
      ${(parseFloat(d.castka_celkem) || 0) > 0 ? `<button class="btn-secondary" style="color:#7c3aed;border-color:#c4b5fd" onclick="posExchange(${objId}, '${esc(d.cislo || '').replace(/'/g, '')}')" title="Výměna — vrátit vybrané položky a přidat nové, spočítá doplatek/přeplatek">🔄 Výměna</button>` : ''}
      <button class="btn-primary" onclick="POS._closeModal();posOpenOrderInAdmin(${objId})">✏️ Upravit v adminu</button>
    `;
    modal('📜 Účtenka ' + (d.cislo || ''), body, foot);
  };

  // 🆕 v3.0.268 / částečná v3.0.275 — VRATKY: refundace zaplacené účtenky (VRA-…) s výběrem položek
  window.posRefundReceipt = async function(objId, cislo) {
    let data;
    try { data = await api('admin_pos.php?action=refundovatelne&objednavka_id=' + objId); }
    catch (e) { (typeof toast === 'function' ? toast : alert)('❌ ' + (e.message || 'Nelze načíst položky účtenky')); return; }
    const lines = (data.polozky || []).filter(p => p.zbyva > 0.0001);
    if (!lines.length) { (typeof toast === 'function' ? toast : alert)('Účtenka už je celá vrácená'); return; }
    window._posRefLines = {};
    const rows = lines.map(p => {
      window._posRefLines[p.id] = p;
      const jiz = p.jiz_vraceno > 0 ? ` <span style="color:#999;font-size:11px">(už ${p.jiz_vraceno})</span>` : '';
      return `<tr>
        <td style="text-align:center;padding:4px"><input type="checkbox" data-pref-chk="${p.id}" checked onchange="posRefundRecalc()"></td>
        <td style="padding:4px">${esc(p.vyrobek_nazev)}${jiz}</td>
        <td style="text-align:right;padding:4px;white-space:nowrap">${fmt(p.cena_bez_dph)}</td>
        <td style="text-align:right;padding:4px;white-space:nowrap"><input type="number" data-pref-qty="${p.id}" min="0" max="${p.zbyva}" step="any" value="${p.zbyva}" style="width:60px;text-align:right" oninput="posRefundRecalc()"> <span style="color:#999;font-size:11px">/${p.zbyva}</span></td>
      </tr>`;
    }).join('');
    const body = `
      <p style="margin:0 0 10px;font-size:13px;color:#6b7280">Vyber co vrátit. Vznikne záporná účtenka (VRA-), uzávěrka i tržby se sníží. Lze opakovat, dokud něco zbývá.</p>
      <table style="width:100%;border-collapse:collapse;font-size:13px">
        <thead><tr style="border-bottom:1px solid #E1E5EB;text-align:left;color:#6b7280;font-size:11px;text-transform:uppercase">
          <th style="width:28px"></th><th style="padding:4px">Položka</th><th style="text-align:right;padding:4px">Cena</th><th style="text-align:right;padding:4px">Vrátit</th></tr></thead>
        <tbody>${rows}</tbody>
      </table>
      <div style="display:flex;justify-content:space-between;margin-top:12px;padding-top:10px;border-top:1px solid #E1E5EB">
        <strong>Vrátit celkem (s DPH):</strong><strong id="pref-sum" style="color:#DC2626;font-size:17px">0 Kč</strong>
      </div>
      <label style="display:block;margin-top:10px;font-size:13px">Důvod (volitelný)
        <input id="pref-duvod" type="text" style="width:100%;margin-top:4px" placeholder="např. reklamace"></label>`;
    const foot = `
      <button class="btn-secondary" onclick="POS._closeModal()">Zrušit</button>
      <button class="btn-primary" id="pref-submit" style="background:#DC2626;border-color:#DC2626" onclick="posRefundSubmit(${objId})">↩️ Vrátit vybrané</button>`;
    modal('↩️ Vrátit účtenku ' + (cislo || ''), body, foot);
    posRefundRecalc();
  };

  window.posRefundRecalc = function() {
    let sum = 0;
    Object.values(window._posRefLines || {}).forEach(p => {
      const chk = document.querySelector(`[data-pref-chk="${p.id}"]`);
      const qtyEl = document.querySelector(`[data-pref-qty="${p.id}"]`);
      if (!chk || !qtyEl) return;
      let q = parseFloat(qtyEl.value) || 0;
      if (q > p.zbyva) { q = p.zbyva; qtyEl.value = p.zbyva; }
      if (q < 0) { q = 0; qtyEl.value = 0; }
      qtyEl.disabled = !chk.checked;
      if (chk.checked) sum += q * p.cena_bez_dph * (1 + p.sazba_dph / 100);
    });
    const el = document.getElementById('pref-sum');
    if (el) el.textContent = '−' + fmt(Math.round(sum * 100) / 100);
  };

  window.posRefundSubmit = async function(objId) {
    const polozky = [];
    Object.values(window._posRefLines || {}).forEach(p => {
      const chk = document.querySelector(`[data-pref-chk="${p.id}"]`);
      const qtyEl = document.querySelector(`[data-pref-qty="${p.id}"]`);
      if (chk && chk.checked && qtyEl) {
        const q = parseFloat(qtyEl.value) || 0;
        if (q > 0.0001) polozky.push({ polozka_id: p.id, mnozstvi: q });
      }
    });
    if (!polozky.length) { (typeof toast === 'function' ? toast : alert)('Vyber alespoň jednu položku'); return; }
    const btn = document.getElementById('pref-submit'); if (btn) btn.disabled = true;
    const duvod = (document.getElementById('pref-duvod') || {}).value || '';
    try {
      const r = await api('admin_pos.php?action=refund_order', {
        method: 'POST',
        body: JSON.stringify({ objednavka_id: objId, duvod, polozky }),
      });
      POS._closeModal();
      if (typeof toast === 'function') toast(`✅ Vratka ${r.cislo} (${fmt(r.castka_celkem)} Kč)`, 'success');
      else alert(`✅ Vratka ${r.cislo} vytvořena (${fmt(r.castka_celkem)} Kč)`);
      if (typeof posLoadOrders === 'function') posLoadOrders();
    } catch (e) {
      if (btn) btn.disabled = false;
      if (typeof toast === 'function') toast('❌ ' + (e.message || 'Vratka selhala'), 'error');
      else alert('❌ ' + (e.message || 'Vratka selhala'));
    }
  };

  // ─── 🆕 v3.0.276 — VÝMĚNA: vrátit vybrané položky + přidat nové, spočítat doplatek/přeplatek ───
  //   Realizováno přes ověřené endpointy: refund_order (částečná vratka) + quick_order (nový prodej).
  //   Vznikne VRA- (vratka) + nová účtenka, propojené poznámkou. Net rozdíl = nové − vrácené.
  window.posExchange = async function(objId, cislo) {
    let data;
    try { data = await api('admin_pos.php?action=refundovatelne&objednavka_id=' + objId); }
    catch (e) { (typeof toast === 'function' ? toast : alert)('❌ ' + (e.message || 'Nelze načíst položky účtenky')); return; }
    const vrLines = (data.polozky || []).filter(p => p.zbyva > 0.0001);
    window._exchOrig = {}; vrLines.forEach(p => window._exchOrig[p.id] = p);
    window._exchNew = [];   // [{vyrobek_id, nazev, cena_bez_dph, sazba_dph, mnozstvi}]
    window._exchSrc = { objId, cislo };

    const vrRows = vrLines.length ? vrLines.map(p => `
      <tr>
        <td style="text-align:center;padding:3px"><input type="checkbox" data-exch-vr-chk="${p.id}" onchange="posExchangeRecalc()"></td>
        <td style="padding:3px">${esc(p.vyrobek_nazev)}</td>
        <td style="text-align:right;padding:3px"><input type="number" data-exch-vr-qty="${p.id}" min="0" max="${p.zbyva}" step="any" value="${p.zbyva}" style="width:54px;text-align:right" oninput="posExchangeRecalc()"> <span style="color:#999;font-size:11px">/${p.zbyva}</span></td>
      </tr>`).join('') : `<tr><td colspan="3" style="padding:8px;color:#9097a3;font-size:13px">Nic k vrácení.</td></tr>`;

    const catOpts = (State.catalog?.vyrobky || []).map(v =>
      `<option value="${v.id}">${esc(v.nazev)} — ${fmt(parseFloat(v.cena_bez_dph) || 0)}</option>`).join('');

    const body = `
      <p style="margin:0 0 12px;font-size:13px;color:#6b7280">Vrať z účtenky <strong>${esc(cislo)}</strong> co zákazník nechce a přidej náhradu. Spočítám doplatek/přeplatek. Vznikne vratka (VRA-) + nová účtenka.</p>
      <div style="display:flex;gap:16px;flex-wrap:wrap">
        <div style="flex:1;min-width:240px">
          <div style="font-weight:700;margin-bottom:6px;color:#DC2626">↩️ Vrátit z účtenky</div>
          <table style="width:100%;border-collapse:collapse;font-size:13px"><tbody>${vrRows}</tbody></table>
        </div>
        <div style="flex:1;min-width:240px">
          <div style="font-weight:700;margin-bottom:6px;color:#16a34a">🆕 Nové položky</div>
          <div style="display:flex;gap:6px;margin-bottom:8px">
            <select id="exch-new-select" style="flex:1;min-width:0;padding:7px;border:1px solid #d1d5db;border-radius:8px">${catOpts}</select>
            <button class="btn-secondary" type="button" onclick="posExchangeAddItem()">+ Přidat</button>
          </div>
          <div id="exch-new-list"></div>
        </div>
      </div>
      <div id="exch-net" style="margin-top:14px;padding-top:12px;border-top:1px solid #E1E5EB;font-size:15px"></div>`;
    const foot = `
      <button class="btn-secondary" onclick="POS._closeModal()">Zrušit</button>
      <button class="btn-primary" id="exch-submit" style="background:#7c3aed;border-color:#7c3aed" onclick="posExchangeSubmit()">🔄 Provést výměnu</button>`;
    modal('🔄 Výměna — účtenka ' + (cislo || ''), body, foot);
    posExchangeRecalc();
  };

  window.posExchangeAddItem = function() {
    const sel = document.getElementById('exch-new-select');
    if (!sel) return;
    const v = (State.catalog?.vyrobky || []).find(x => x.id === parseInt(sel.value, 10));
    if (!v) return;
    const ex = window._exchNew.find(n => n.vyrobek_id === v.id);
    if (ex) ex.mnozstvi += 1;
    else window._exchNew.push({ vyrobek_id: v.id, nazev: v.nazev, cena_bez_dph: parseFloat(v.cena_bez_dph) || 0, sazba_dph: parseFloat(v.sazba_dph || v.dph || 12), mnozstvi: 1 });
    posExchangeRenderNew();
    posExchangeRecalc();
  };
  window.posExchangeSetNewQty = function(vid, val) {
    const n = parseFloat(val) || 0;
    const it = window._exchNew.find(x => x.vyrobek_id === vid);
    if (!it) return;
    if (n <= 0) window._exchNew = window._exchNew.filter(x => x.vyrobek_id !== vid);
    else it.mnozstvi = n;
    posExchangeRenderNew();
    posExchangeRecalc();
  };
  function posExchangeRenderNew() {
    const el = document.getElementById('exch-new-list');
    if (!el) return;
    el.innerHTML = window._exchNew.length ? window._exchNew.map(n => `
      <div style="display:flex;align-items:center;gap:6px;margin-bottom:4px;font-size:13px">
        <span style="flex:1;min-width:0;overflow:hidden;text-overflow:ellipsis;white-space:nowrap">${esc(n.nazev)}</span>
        <span style="color:#999">${fmt(n.cena_bez_dph)}</span>
        <input type="number" min="0" step="any" value="${n.mnozstvi}" style="width:54px;text-align:right" oninput="posExchangeSetNewQty(${n.vyrobek_id}, this.value)">
      </div>`).join('') : '<div style="color:#9097a3;font-size:13px">Zatím nic.</div>';
  }
  window.posExchangeRecalc = function() {
    let vrat = 0;
    Object.values(window._exchOrig || {}).forEach(p => {
      const chk = document.querySelector(`[data-exch-vr-chk="${p.id}"]`);
      const qtyEl = document.querySelector(`[data-exch-vr-qty="${p.id}"]`);
      if (!chk || !qtyEl) return;
      let q = parseFloat(qtyEl.value) || 0;
      if (q > p.zbyva) { q = p.zbyva; qtyEl.value = p.zbyva; }
      qtyEl.disabled = !chk.checked;
      if (chk.checked) vrat += q * p.cena_bez_dph * (1 + p.sazba_dph / 100);
    });
    let nove = 0;
    (window._exchNew || []).forEach(n => { nove += n.mnozstvi * n.cena_bez_dph * (1 + n.sazba_dph / 100); });
    vrat = Math.round(vrat * 100) / 100;
    nove = Math.round(nove * 100) / 100;
    const rozdil = Math.round((nove - vrat) * 100) / 100;
    const el = document.getElementById('exch-net');
    if (!el) return;
    let netLine;
    if (rozdil > 0.005) netLine = `<strong style="color:#DC2626">Doplatek zákazníka: ${fmt(rozdil)}</strong>`;
    else if (rozdil < -0.005) netLine = `<strong style="color:#16a34a">Vrátit zákazníkovi: ${fmt(-rozdil)}</strong>`;
    else netLine = `<strong>Beze změny ceny (0)</strong>`;
    el.innerHTML = `
      <div style="display:flex;justify-content:space-between"><span>↩️ Vrácené</span><span>− ${fmt(vrat)}</span></div>
      <div style="display:flex;justify-content:space-between"><span>🆕 Nové</span><span>+ ${fmt(nove)}</span></div>
      <div style="display:flex;justify-content:space-between;margin-top:4px">${netLine}<span></span></div>`;
  };
  window.posExchangeSubmit = async function() {
    const src = window._exchSrc || {};
    // Vrácené
    const vraceno = [];
    Object.values(window._exchOrig || {}).forEach(p => {
      const chk = document.querySelector(`[data-exch-vr-chk="${p.id}"]`);
      const qtyEl = document.querySelector(`[data-exch-vr-qty="${p.id}"]`);
      if (chk && chk.checked && qtyEl) { const q = parseFloat(qtyEl.value) || 0; if (q > 0.0001) vraceno.push({ polozka_id: p.id, mnozstvi: q }); }
    });
    const nove = (window._exchNew || []).filter(n => n.mnozstvi > 0).map(n => ({ vyrobek_id: n.vyrobek_id, mnozstvi: n.mnozstvi, cena_bez_dph: n.cena_bez_dph, sazba_dph: n.sazba_dph, nazev: n.nazev }));
    if (!vraceno.length && !nove.length) { (typeof toast === 'function' ? toast : alert)('Vyber co vrátit a/nebo přidej nové položky'); return; }
    const btn = document.getElementById('exch-submit'); if (btn) btn.disabled = true;
    try {
      let vraCislo = null, novaCislo = null;
      // 1) Vratka (částečná) — když je co vracet
      if (vraceno.length) {
        const r1 = await api('admin_pos.php?action=refund_order', { method: 'POST', body: JSON.stringify({ objednavka_id: src.objId, duvod: 'Výměna za ' + src.cislo, polozky: vraceno }) });
        vraCislo = r1.cislo;
      }
      // 2) Nová účtenka — když jsou nové položky
      if (nove.length) {
        const r2 = await api('admin_pos.php?action=quick_order', { method: 'POST', body: JSON.stringify({ pos_typ: 'sebou', pos_payment: 'hotove', polozky: nove, poznamka: 'Výměna za účtenku ' + src.cislo }) });
        novaCislo = r2.cislo;
      }
      POS._closeModal();
      const msg = `✅ Výměna hotová${vraCislo ? ' · vratka ' + vraCislo : ''}${novaCislo ? ' · nová ' + novaCislo : ''}`;
      if (typeof toast === 'function') toast(msg, 'success'); else alert(msg);
      if (typeof posLoadOrders === 'function') posLoadOrders();
    } catch (e) {
      if (btn) btn.disabled = false;
      (typeof toast === 'function' ? toast : alert)('❌ ' + (e.message || 'Výměna selhala'));
    }
  };

  // ─── 🆕 v3.0.7 — QR k platbě (pay-at-table) ───────────────────
  window.posShowPayQR = async function(objId, cislo, castka) {
    // 1) Generuj pay_token + URL
    let payUrl = null;
    try {
      const r = await api('pay_qr.php?action=create_token', {
        method: 'POST', body: JSON.stringify({ objednavka_id: objId })
      });
      if (!r.ok) throw new Error(r.error || 'Nepodařilo se vytvořit token');
      payUrl = r.pay_url;
    } catch (e) {
      return toast('Chyba: ' + e.message, 'error');
    }

    // 2) QR via api.qrserver.com (stejný pattern jako pro stoly)
    const qrImg = 'https://api.qrserver.com/v1/create-qr-code/?size=300x300&margin=10&data=' + encodeURIComponent(payUrl);

    // 3) Modal
    const html = `
      <div style="text-align:center;padding:14px">
        <img src="${qrImg}" alt="QR platba" style="max-width:260px;border:6px solid #fff;border-radius:12px;box-shadow:0 4px 16px rgba(0,0,0,0.15)">
        <h3 style="margin-top:12px;font-size:20px;font-weight:800">${fmt(castka)} Kč</h3>
        <p style="font-size:13px;color:#6B7280;margin-top:4px">Účtenka <strong>${esc(cislo)}</strong></p>
        <p style="font-size:12px;color:#6B7280;margin-top:8px;line-height:1.5">
          Host naskenuje QR → zaplatí kartou (Stripe/GoPay) nebo informuje o hotovostní platbě.<br>
          <span style="color:#15803D;font-weight:600">Stav platby uvidíš v účtence (✅ Zaplaceno)</span>
        </p>
        <div style="background:#F9FAFB;border-radius:8px;padding:10px;margin-top:12px;font-family:monospace;font-size:10px;word-break:break-all;color:#6B7280">${esc(payUrl)}</div>
        <div style="display:flex;gap:6px;margin-top:14px;flex-wrap:wrap">
          <button class="btn-secondary" onclick="window.print()" style="flex:1">🖨️ Tisk QR</button>
          <a class="btn-primary" href="${esc(payUrl)}" target="_blank" style="flex:1;text-decoration:none;text-align:center">👁️ Preview</a>
        </div>
      </div>
    `;
    // Reuse pos modal helper
    const m = document.createElement('div');
    m.id = 'pos-qr-pay-modal';
    m.style.cssText = 'position:fixed;inset:0;background:rgba(0,0,0,0.6);display:flex;align-items:center;justify-content:center;z-index:9998';
    m.innerHTML = `
      <div style="background:#fff;color:#1F2937;border-radius:18px;padding:24px;max-width:380px;width:90%;box-shadow:0 20px 60px rgba(0,0,0,0.3);font-family:inherit;position:relative">
        <button onclick="document.getElementById('pos-qr-pay-modal').remove()" style="position:absolute;top:12px;right:12px;width:30px;height:30px;border:none;background:#F3F4F6;border-radius:50%;cursor:pointer;font-size:16px">✕</button>
        <h2 style="font-size:18px;font-weight:800;margin-bottom:6px">📲 QR k platbě</h2>
        ${html}
      </div>
    `;
    document.body.appendChild(m);
  };

  // ─── Re-tisk účtenky ─────────────────────────────────────────
  // 🐛 v2.9.315 — fix cross-day silent fail. Předtím re-fetch quick_history pro
  // State._historyDate a linear scan podle objId → pokud user otevřel detail
  // účtenky z jiného dne (přes deep link nebo modal z historie), find() vrátilo
  // undefined → "Účtenka nenalezena". Teď přímý quick_order_detail (přidaný v v2.9.308).
  window.posReprintReceipt = function(objId) {
    api('admin_pos.php?action=quick_order_detail&id=' + objId)
      .then(ord => {
        if (!ord || !ord.cislo) return toast('Účtenka nenalezena', 'error');
        const time = esc((ord.datum_objednani || '').slice(0, 16).replace('T', ' '));
        const html = `<!DOCTYPE html><html><head><meta charset="utf-8"><title>${esc(ord.cislo)}</title>
          <style>body{font-family:Courier,monospace;font-size:12px;width:280px;margin:10px auto}
          h1{font-size:14px;text-align:center;margin:0 0 4px}
          .it{display:flex;justify-content:space-between;padding:4px 0}
          .tot{font-weight:700;font-size:14px;margin-top:8px;text-align:right;border-top:1px solid #aaa;padding-top:8px}</style></head><body>
          <h1>APPEK POS — REPRINT</h1>
          <div style="text-align:center;font-size:11px">${esc(ord.cislo)}</div>
          <div style="text-align:center;margin-bottom:8px;font-size:10px">${time}</div>
          <div class="it"><span>Typ</span><span>${esc(ord.pos_typ || '')}</span></div>
          <div class="it"><span>Platba</span><span>${esc(ord.pos_payment || '')}</span></div>
          <div class="tot">CELKEM: ${Number(ord.castka_celkem || 0).toFixed(2)} Kč</div>
          <div style="text-align:center;margin-top:8px;font-size:10px">📜 Reprint účtenky — originál byl vytištěn ${time}</div>
          <script>window.print();<\/script></body></html>`;
        const w = window.open('', '_blank', 'width=320,height=600');
        if (!w) return toast('Povolte popup okna', 'error');
        w.document.write(html); w.document.close();
      })
      .catch(e => toast('Chyba: ' + e.message, 'error'));
  };

  // ─── 🆕 v3.0.210 — Denní uzávěrka na stanice (obsluhu) ───────────
  const UZAV_ML = { hotovost:'💵 Hotovost', karta:'💳 Karta', qr:'📲 QR', online:'🌐 Online', poukaz:'🎟️ Poukaz', prevod:'🏦 Převod', ostatni:'➕ Ostatní' };
  async function renderUzaverka(panel) {
    const date = State._uzavDate || new Date().toISOString().slice(0, 10);
    State._uzavDate = date;
    panel.innerHTML = `
      <div class="pos-uzav">
        <div class="pos-uzav-head">
          <h2>🧮 Denní uzávěrka</h2>
          <input type="date" id="uzav-date" value="${date}" onchange="posUzavSetDate(this.value)" class="pos-uzav-date">
        </div>
        <div id="uzav-body"><div class="pos-loading" style="padding:40px;text-align:center">⏳ Načítám…</div></div>
      </div>`;
    try {
      const r = await api('admin_pos.php?action=uzaverka&date=' + date);
      State._uzavData = r;
      const metodyRows = r.metody.filter(m => r.total.metody[m] > 0).map(m =>
        `<div class="uzav-row"><span>${UZAV_ML[m] || m}</span><strong>${fmt(r.total.metody[m])} Kč</strong></div>`).join('') || '<div class="uzav-row" style="opacity:.6"><span>Žádné platby</span></div>';
      const staniceCards = r.stanice.length ? r.stanice.map(s => `
        <div class="uzav-stanice">
          <div class="uzav-st-head"><strong>👤 ${esc(s.obsluha)}</strong><span class="uzav-st-cnt">${s.pocet} ${s.pocet === 1 ? 'doklad' : (s.pocet < 5 ? 'doklady' : 'dokladů')}</span></div>
          <div class="uzav-st-trzba">${fmt(s.trzba)} Kč</div>
          <div class="uzav-st-metody">${r.metody.filter(m => s.metody[m] > 0).map(m => `${UZAV_ML[m] || m}: ${fmt(s.metody[m])}`).join(' · ') || '—'}</div>
          ${s.tip > 0 ? `<div class="uzav-st-tip">💟 dýška ${fmt(s.tip)} Kč</div>` : ''}
        </div>`).join('') : '<div class="pos-uzav-empty">🗓️ Žádné prodeje za tento den</div>';
      document.getElementById('uzav-body').innerHTML = `
        ${r.uzavreno ? `<div class="uzav-closed">🔒 Den už uzavřen — <strong>${esc(r.uzavreno.kdo || '')}</strong> · ${esc(String(r.uzavreno.vytvoreno || '').slice(0, 16))} · ${fmt(r.uzavreno.celkem)} Kč</div>` : ''}
        <div class="uzav-total-box">
          <div class="uzav-total-main"><span>CELKEM</span><strong>${fmt(r.total.trzba)} Kč</strong></div>
          <div class="uzav-total-sub">${r.total.pocet} dokladů${r.total.tip > 0 ? ` · 💟 dýška ${fmt(r.total.tip)} Kč` : ''}</div>
          <div class="uzav-metody">${metodyRows}</div>
        </div>
        <h3 class="uzav-h3">Na stanice (obsluhu)</h3>
        <div class="uzav-stanice-grid">${staniceCards}</div>
        <div class="uzav-actions">
          <button class="pos-tm-btn" onclick="posUzavPrint()">🖨️ Tisk uzávěrky</button>
          <button class="pos-tm-btn is-primary" onclick="posUzavClose('${date}')">🔒 Uzavřít den${r.uzavreno ? ' (znovu)' : ''}</button>
        </div>`;
    } catch (e) {
      const b = document.getElementById('uzav-body');
      if (b) b.innerHTML = `<div class="pos-error" style="padding:30px;text-align:center;color:#DC2626">❌ ${esc(e.message)}</div>`;
    }
  }
  window.posUzavSetDate = function(d) { State._uzavDate = d; const p = document.getElementById('pos-tab-content'); if (p) renderUzaverka(p); };
  window.posUzavClose = async function(date) {
    if (!confirm('Uzavřít den ' + date + '?\n\nUloží se snapshot uzávěrky (tržby + rozpad na obsluhu) pro audit.')) return;
    try {
      const r = await api('admin_pos.php?action=uzaverka_close', { method: 'POST', body: JSON.stringify({ date }) });
      toast('🔒 Den uzavřen · ' + fmt(r.celkem) + ' Kč · ' + r.pocet + ' dokladů', 'success');
      const p = document.getElementById('pos-tab-content'); if (p) renderUzaverka(p);
    } catch (e) { toast('Chyba: ' + e.message, 'error'); }
  };
  window.posUzavPrint = function() {
    const r = State._uzavData; if (!r) return;
    const w = window.open('', 'appek_uzav', 'width=400,height=720');
    if (!w) return toast('Povol popup okna pro tisk', 'error');
    const stRows = r.stanice.map(s => `<div class="st"><div class="sth"><b>${esc(s.obsluha)}</b><span>${s.pocet} dokl.</span></div><div class="sttot">${fmt(s.trzba)} Kc</div><div class="stm">${r.metody.filter(m => s.metody[m] > 0).map(m => (UZAV_ML[m] || m).replace(/^[^ ]+ /, '') + ': ' + fmt(s.metody[m])).join(' · ') || '-'}</div></div>`).join('');
    const mRows = r.metody.filter(m => r.total.metody[m] > 0).map(m => `<div class="row"><span>${(UZAV_ML[m] || m).replace(/^[^ ]+ /, '')}</span><b>${fmt(r.total.metody[m])} Kc</b></div>`).join('');
    w.document.write(`<!DOCTYPE html><html lang="cs"><head><meta charset="utf-8"><title>Uzaverka ${r.date}</title><style>body{font-family:'Courier New',monospace;width:80mm;margin:0 auto;padding:8mm 6mm;color:#000}.c{text-align:center}.big{font-size:16pt;font-weight:800}hr{border:0;border-top:1px dashed #000;margin:3mm 0}.row{display:flex;justify-content:space-between;margin:1mm 0}.st{margin:2mm 0;padding-bottom:2mm;border-bottom:1px dotted #999}.sth{display:flex;justify-content:space-between}.sttot{font-size:14pt;font-weight:800}.stm{font-size:9pt;color:#444}.tot{font-size:17pt;font-weight:800;display:flex;justify-content:space-between;margin-top:3mm}@media print{@page{size:80mm auto;margin:0}}</style></head><body><div class="c big">DENNI UZAVERKA</div><div class="c">${r.date}</div><hr><div style="font-weight:700">NA STANICE (OBSLUHU)</div>${stRows || '<div class="c">zadne prodeje</div>'}<hr><div style="font-weight:700">PLATBY</div>${mRows || '<div class="row"><span>-</span></div>'}<hr><div class="tot"><span>CELKEM</span><span>${fmt(r.total.trzba)} Kc</span></div><div class="row"><span>Dokladu</span><b>${r.total.pocet}</b></div>${r.total.tip > 0 ? `<div class="row"><span>Dyska</span><b>${fmt(r.total.tip)} Kc</b></div>` : ''}<hr><div class="c" style="font-size:9pt">Vytisteno ${new Date().toLocaleString('cs-CZ')}</div><script>setTimeout(function(){window.print()},300)<\/script></body></html>`);
    w.document.close();
  };

  // ─── Reports / Statistiky ────────────────────────────────────
  async function renderReports(panel) {
    panel.innerHTML = `<div class="pos-loading" style="padding:40px;text-align:center">⏳ Načítám statistiky…</div>`;
    try {
      const today = new Date().toISOString().slice(0, 10);
      const d = await api('admin_pos.php?action=quick_history&date=' + today + '&limit=200');
      const s = d.souhrn || {};
      panel.innerHTML = `
        <div class="pos-history-wrap">
          <div class="pos-history-head">
            <div>
              <h2 style="margin:0 0 4px;font-size:24px;font-weight:800">📊 Dnešní statistiky</h2>
              <p style="margin:0;color:#6b7280;font-size:14px">${fmtDateCs(today)}</p>
            </div>
          </div>

          <div class="pos-history-stats" style="grid-template-columns:repeat(auto-fit,minmax(180px,1fr))">
            <div class="pos-hs-card pos-hs-success">
              <div class="pos-hs-label">Tržby celkem</div>
              <div class="pos-hs-value" style="font-size:32px">${fmt(s['tržby'] ?? 0)} Kč</div>
            </div>
            <div class="pos-hs-card">
              <div class="pos-hs-label">Účtenek</div>
              <div class="pos-hs-value" style="font-size:28px">${s.pocet ?? 0}</div>
            </div>
            <div class="pos-hs-card">
              <div class="pos-hs-label">💰 Spropitné</div>
              <div class="pos-hs-value">${fmt(s.tip_sum ?? 0)} Kč</div>
            </div>
            <div class="pos-hs-card">
              <div class="pos-hs-label">💵 Hotově</div>
              <div class="pos-hs-value">${fmt(s.hotove ?? 0)} Kč</div>
            </div>
            <div class="pos-hs-card">
              <div class="pos-hs-label">💳 Kartou</div>
              <div class="pos-hs-value">${fmt(s.karta ?? 0)} Kč</div>
            </div>
          </div>

          <div class="pos-history-stats" style="grid-template-columns:repeat(auto-fit,minmax(180px,1fr));margin-top:14px">
            <div class="pos-hs-card"><div class="pos-hs-label">🛍️ Sebou</div><div class="pos-hs-value">${s.pocet_sebou ?? 0}</div></div>
            <div class="pos-hs-card"><div class="pos-hs-label">🍽️ Na místě</div><div class="pos-hs-value">${s.pocet_na_miste ?? 0}</div></div>
            <div class="pos-hs-card"><div class="pos-hs-label">📦 Vyzvednutí</div><div class="pos-hs-value">${s.pocet_vyzvednuti ?? 0}</div></div>
            <div class="pos-hs-card"><div class="pos-hs-label">🛵 Rozvoz</div><div class="pos-hs-value">${s.pocet_rozvoz ?? 0}</div></div>
          </div>

          <div style="margin-top:24px;text-align:center">
            <button class="btn-primary" onclick="POS.setTab('orders')" style="font-size:15px;padding:12px 24px">📜 Zobrazit všechny účtenky</button>
            <button class="btn-secondary" onclick="window.open('/admin/?page=dashboard','_blank')" style="font-size:15px;padding:12px 24px;margin-left:8px">📊 Plný admin přehled</button>
          </div>
        </div>
      `;
    } catch (e) {
      panel.innerHTML = `<div class="pos-error" style="margin:20px">⚠️ ${esc(e.message)}</div>`;
    }
  }

  // ─── Init ────────────────────────────────────────────────────
  document.addEventListener('DOMContentLoaded', () => {
    // Search input live binding
    const inp = $('#pos-search');
    if (inp) {
      inp.addEventListener('input', (e) => setSearch(e.target.value));
    }
    // Tab clicks (Produkty / Účtenky / Statistiky)
    $$('.pos-tab-h').forEach(el => {
      el.addEventListener('click', () => setActiveTab(el.dataset.tab));
    });
    // 🆕 v2.9.205 — render payment bar z centrálního API (admin Nastavení→Platby)
    renderPaymentBar();
    // Keyboard ⌘K / Ctrl+K → focus search
    document.addEventListener('keydown', (e) => {
      if ((e.metaKey || e.ctrlKey) && (e.key === 'k' || e.key === 'K')) {
        e.preventDefault();
        $('#pos-search')?.focus();
      } else if (e.key === 'Escape') {
        closeModal();
      }
    });
    // Clock
    tickClock();
    setInterval(tickClock, 30000);
    // 🆕 v3.0.193 — obnov uložený zoom kasy
    applyPosZoom();
    // 🆕 v3.0.240 — aplikuj uložený režim mobilního košíku (lišta/přepínač)
    applyMcartMode();
    // Load
    loadCatalog();
    // Online/offline indicator
    const updateOnline = () => {
      const st = $('#pos-foot-status');
      if (!st) return;
      st.textContent = navigator.onLine ? '🟢 Online' : '🔴 Offline';
    };
    window.addEventListener('online', updateOnline);
    window.addEventListener('offline', updateOnline);
    updateOnline();
    // 🆕 v3.0.239 — detekce nové verze (POS dosud neměl stale-detektor jako admin SPA →
    //   po deploji zůstával na staré cache/SW, dokud uživatel ručně tvrdě-neobnovil).
    setTimeout(posCheckVersion, 3000);
    setInterval(posCheckVersion, 300000);
  });

  // 🆕 v3.0.239 — porovnej načtenou verzi (CFG.version, server-rendered) s živou
  //   api/version.php; při neshodě nabídni obnovení. posForceReload navíc ODREGISTRUJE
  //   service worker + smaže caches → spolehlivě probije i zaseklý starý SW (root cause
  //   "horní taby pořád hranaté" — stará pos.css ze SW cache i přes ?v= bump).
  async function posCheckVersion() {
    try {
      const r = await fetch(CFG.apiBase + 'version.php?t=' + Date.now(), { cache: 'no-store' });
      if (!r.ok) return;
      const j = await r.json();
      const live = String(j.build_version || j.version || '').replace(/[^0-9.\-a-z]/gi, '');
      if (live && CFG.version && live !== CFG.version) posShowUpdateBanner(live);
    } catch (e) { /* offline → ignoruj */ }
  }
  function posShowUpdateBanner(live) {
    if (document.getElementById('pos-update-banner')) return;
    const b = document.createElement('div');
    b.id = 'pos-update-banner';
    b.style.cssText = 'position:fixed;left:50%;transform:translateX(-50%);bottom:20px;z-index:99999;'
      + 'background:#BA7517;color:#fff;padding:13px 22px;border-radius:999px;font-weight:700;font-size:15px;'
      + 'box-shadow:0 8px 28px rgba(0,0,0,0.28);cursor:pointer;display:flex;gap:10px;align-items:center';
    b.textContent = '🆕 Nová verze ' + live + ' — klikni pro obnovení';
    b.onclick = posForceReload;
    document.body.appendChild(b);
  }
  async function posForceReload() {
    try {
      if (navigator.serviceWorker) {
        const regs = await navigator.serviceWorker.getRegistrations();
        await Promise.all(regs.map(r => r.unregister()));
      }
      if (window.caches) {
        const ks = await caches.keys();
        await Promise.all(ks.map(k => caches.delete(k)));
      }
    } catch (e) { /* nevadí */ }
    location.reload(true);
  }
})();
