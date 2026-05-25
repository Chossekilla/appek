/* ═════════════════════════════════════════════════════════════════
   🧾 APPEK POS — Standalone JS Core
   Vlastní logika (žádné dependencies na admin.js)
   ═════════════════════════════════════════════════════════════════ */
'use strict';

(function () {
  const CFG = window.POS_CONFIG || {};

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
    m.innerHTML = `
      <button class="pos-pay-opt" data-act="discount">💰 Sleva %</button>
      <button class="pos-pay-opt" data-act="tip">💵 Spropitné</button>
      <button class="pos-pay-opt" data-act="note">💬 Poznámka</button>
      <button class="pos-pay-opt" data-act="clear">🗑️ Vyprázdnit košík</button>
    `;
    $('#pos-cart-panel').appendChild(m);
    m.querySelectorAll('.pos-pay-opt').forEach(b => {
      b.onclick = () => {
        const a = b.dataset.act;
        m.remove();
        if (a === 'discount') askDiscount();
        if (a === 'tip')      askTip();
        if (a === 'note')     addNote();
        if (a === 'clear') {
          if (confirm('Smazat celý košík?')) { State.cart = []; renderCart(); }
        }
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
    toast(`💾 Uloženo (${drafts.length} rozpracovaných v paměti)`, 'success');
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
    toast(`✓ Načteno: ${d.itemCount} položek · ${Math.round(d.total)} Kč`, 'success');
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
      toast(`✓ Účet ${r.cislo} · ${fmt(r.celkem)} Kč · připraveno pro dalšího hosta`, 'success');
      // 🆕 v2.9.43 — Po úspěšném FINISH okamžitě vyprázdnit bez confirmu
      // Připraveno pro dalšího zákazníka (kasový mode)
      resetCart();
    } catch (e) {
      toast('Chyba: ' + e.message, 'error');
    } finally {
      if (btn) { btn.disabled = false; btn.textContent = '✓ FINISH'; }
    }
  }

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
      if (Date.now() - d.saved_at > 5 * 60 * 1000) {
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
      if (products) products.style.display = '';
      if (cart) cart.style.display = '';
      if (panel) panel.remove();
    } else {
      // Hide products+cart, render alternate panel
      if (products) products.style.display = 'none';
      if (cart) cart.style.display = 'none';
      if (!panel) {
        panel = document.createElement('div');
        panel.id = 'pos-tab-content';
        panel.className = 'pos-tab-panel';
        const main = document.querySelector('.pos-main');
        if (main) main.appendChild(panel);
      }
      if (tab === 'orders')  renderHistory(panel);
      if (tab === 'reports') renderReports(panel);
    }
  }
  window.posSetTab = setActiveTab;

  // ─── History / Účtenky ───────────────────────────────────────
  async function renderHistory(panel) {
    panel.innerHTML = `<div class="pos-loading" style="padding:40px;text-align:center">⏳ Načítám účtenky…</div>`;
    try {
      const today = new Date().toISOString().slice(0, 10);
      const date = State._historyDate || today;
      State._historyDate = date;
      const d = await api('admin_pos.php?action=quick_history&date=' + encodeURIComponent(date));
      const orders = d.objednavky || [];
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
              <p style="margin:0;color:#6b7280;font-size:14px">Den ${fmtDateCs(date)} · ${orders.length} účtenek</p>
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
          `}
        </div>
      `;
    } catch (e) {
      panel.innerHTML = `<div class="pos-error" style="margin:20px">⚠️ Chyba načtení historie: ${esc(e.message)}</div>`;
    }
  }

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
    try { d = await api('admin_pos.php?action=quick_order_detail&id=' + objId); }
    catch (e) {
      modal('⚠️ Chyba', `<div style="padding:20px;color:#dc2626">Detail účtenky se nepodařilo načíst: ${esc(e.message)}</div>`,
        `<button class="btn-secondary" onclick="POS._closeModal()">Zavřít</button>`);
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
      <button class="btn-primary" onclick="POS._closeModal();posOpenOrderInAdmin(${objId})">✏️ Upravit v adminu</button>
    `;
    modal('📜 Účtenka ' + (d.cislo || ''), body, foot);
  };

  // ─── Re-tisk účtenky ─────────────────────────────────────────
  window.posReprintReceipt = function(objId) {
    // Načti polozky z DB a tiskni
    api('admin_pos.php?action=quick_history&date=' + (State._historyDate || new Date().toISOString().slice(0, 10)))
      .then(d => {
        const ord = (d.objednavky || []).find(x => x.id === objId);
        if (!ord) return toast('Účtenka nenalezena', 'error');
        // Vyrobit prosté reprintové okno (browser print)
        const time = (ord.datum_objednani || '').slice(0, 16).replace('T', ' ');
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
  });
})();
