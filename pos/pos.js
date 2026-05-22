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
  function payMenu(e) {
    e?.stopPropagation();
    let m = document.querySelector('.pos-pay-menu');
    if (m) { m.remove(); return; }
    const opts = [
      { id: 'paypal',    lbl: '💼 PayPal' },
      { id: 'gift_card', lbl: '🎁 Dárková karta' },
      { id: 'voucher',   lbl: '🎟️ Voucher' },
      { id: 'mobile',    lbl: '📱 Mobile Payment' },
    ];
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
  function newOrder() {
    if (State.cart.length > 0 && !confirm('Aktuální košík obsahuje položky. Začít nový?')) return;
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
  function saveDraft() {
    toast('💾 Uloženo do rozpracovaných', 'success');
    // TODO: backend endpoint pro draft persist
  }
  function printReceipt() {
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

  async function finish() {
    if (State.cart.length === 0) return toast('Košík je prázdný', 'error');
    if (!confirm(`Dokončit objednávku?\n\nCelkem: ${$('#pos-total').textContent}\nPlatba: ${State.pos_payment}\nTyp: ${State.pos_typ}`)) return;
    const btn = $('.pos-finish');
    if (btn) { btn.disabled = true; btn.textContent = '⏳ Odesílám…'; }
    try {
      const r = await api('admin_pos.php?action=quick_order', {
        method: 'POST',
        body: JSON.stringify({
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
      toast(`✓ Účet ${r.cislo} · ${fmt(r.celkem)} Kč`, 'success');
      newOrder();
    } catch (e) {
      toast('Chyba: ' + e.message, 'error');
    } finally {
      if (btn) { btn.disabled = false; btn.textContent = '✓ FINISH'; }
    }
  }

  // ─── Public POS object ───────────────────────────────────────
  window.POS = {
    search:        setSearch,
    newOrder:      newOrder,
    pickCustomer:  pickCustomer,
    setTyp:        setTyp,
    setPay:        setPay,
    payMenu:       payMenu,
    menuToggle:    menuToggle,
    printReceipt:  printReceipt,
    saveDraft:     saveDraft,
    addNote:       addNote,
    finish:        finish,
    _pickCust:     _pickCust,
    _closeModal:   closeModal,
    state:         State,
    reload:        loadCatalog,
  };

  // ─── Init ────────────────────────────────────────────────────
  document.addEventListener('DOMContentLoaded', () => {
    // Search input live binding
    const inp = $('#pos-search');
    if (inp) {
      inp.addEventListener('input', (e) => setSearch(e.target.value));
    }
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
