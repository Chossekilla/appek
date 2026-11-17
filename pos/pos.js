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
    platba_mena:   ((window.POS_CONFIG || {}).platba || {}).vychozi || 'CZK',   // 💱 v3.0.643
  };

  // ─── DOM helpers ─────────────────────────────────────────────
  const $   = (sel) => document.querySelector(sel);
  const $$  = (sel) => Array.from(document.querySelectorAll(sel));
  const esc = (s) => String(s ?? '').replace(/[&<>"']/g, m => ({
    '&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'
  }[m]));
  // 💱 v3.0.640 — přepočet na měnu prodeje (CFG.mena z index.php; delitel=1 po trvalém přepočtu)
  const fmt = (n) => {
    const m = CFG.mena;
    const v = Number(n || 0) / ((m && m.zobrazeni === 'mena' && m.delitel > 0) ? m.delitel : 1);
    return v.toFixed(2).replace('.', ',');
  };

  // 💱 v3.0.642 — částka účtenky v měně DOKLADU (server: doc_mena + doc_celkem jako na tištěné účtence).
  //   Nedělí kurzem → stará Kč účtenka zůstane v Kč i po přepnutí prodeje na EUR.
  const _DOC_SYM = { CZK: 'Kč', EUR: '€', USD: '$', GBP: '£', PLN: 'zł', HUF: 'Ft', CHF: 'CHF' };
  const fmtDocPos = (o) => {
    const kod = o && o.doc_mena;
    const conv = CFG.mena && CFG.mena.zobrazeni === 'mena' && (CFG.mena.delitel > 0 || CFG.mena.kurz > 0) && CFG.mena.kod && CFG.mena.kod !== 'CZK';
    if (!kod || (kod === 'CZK' && !conv)) return `${fmt(o.castka_celkem)} Kč`;
    return `${Number(o.doc_celkem || 0).toFixed(2).replace('.', ',')} ${_DOC_SYM[kod] || kod}`;
  };

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

  // ─── Zoom (velikost UI) ──────────────────────────────────────
  // 🆕 v3.0.191 — +/- zvětšení/zmenšení celé kasy (user: „pos na mobilu je velké…
  //   kasa musí být přehledná na všech zařízeních"). CSS zoom = reflow (vejde se víc),
  //   persist per-zařízení. Krok po ~10–15 %.
  const POS_ZOOM_STEPS = [0.6, 0.7, 0.8, 0.9, 1.0, 1.1, 1.25, 1.4, 1.6];
  function getPosZoom() {
    const v = parseFloat(localStorage.getItem('pos_zoom'));
    return POS_ZOOM_STEPS.includes(v) ? v : 1.0;
  }
  function applyPosZoom() {
    const z = getPosZoom();
    // body zoom = reflow celé appky (header/grid/košík/modal). Fallback na transform pokud
    // by prohlížeč zoom neuměl (zachová aspoň vizuální zmenšení).
    document.body.style.zoom = z;
    if ('zoom' in document.body.style === false || getComputedStyle(document.body).zoom === undefined) {
      document.body.style.transform = z === 1 ? '' : `scale(${z})`;
      document.body.style.transformOrigin = 'top left';
    }
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
    try { toast(dir > 0 ? 'Zvětšeno · ' + Math.round(z * 100) + '%' : 'Zmenšeno · ' + Math.round(z * 100) + '%', ''); } catch (e) {}
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
    // 🆕 v3.0.335 — v hlavní liště jen hlavní kategorie; subkategorie jako druhý řádek pod aktivní
    const mains = cats.filter(k => !k.parent_id);
    const active = cats.find(k => String(k.id) === String(State.activeCat));
    const activeMainId = active ? String(active.parent_id || active.id) : null;
    let html = `
      <button class="pos-cat ${State.activeCat==='all'?'is-active':''}" data-cat="all">
        <span class="pos-cat-icon">🍱</span>
        <span class="pos-cat-name">Vše</span>
        <span class="pos-cat-count">${total}</span>
      </button>`;
    // 🍰 v3.0.406 — virtuální kategorie „Sezóna" (výrobky aktivních sezón, server-filtrované)
    const sezCount = (State.catalog?.vyrobky || []).filter(v => v.sezona_aktivni).length;
    if (sezCount > 0) {
      html += `
      <button class="pos-cat ${State.activeCat==='sezona'?'is-active':''}" data-cat="sezona" style="border-color:#D9A441">
        <span class="pos-cat-icon">🎄</span>
        <span class="pos-cat-name">Sezóna</span>
        <span class="pos-cat-count">${sezCount}</span>
      </button>`;
    }
    for (const k of mains) {
      if (!k.pocet || k.pocet === 0) continue;
      const ic = k.obrazek_url
        ? `<img class="pos-cat-img" src="${esc(k.obrazek_url)}" alt="">`
        : `<span class="pos-cat-icon">${esc(k.ikona || '📦')}</span>`;
      html += `
        <button class="pos-cat ${activeMainId===String(k.id)?'is-active':''}" data-cat="${k.id}">
          ${ic}
          <span class="pos-cat-name">${esc(k.nazev)}</span>
          <span class="pos-cat-count">${k.pocet}</span>
        </button>`;
    }
    // řádek subkategorií pod aktivní hlavní kategorií
    if (activeMainId) {
      const subs = cats.filter(k => String(k.parent_id) === activeMainId && k.pocet > 0);
      if (subs.length) {
        html += `<div class="pos-subcats" style="flex-basis:100%;width:100%;display:flex;gap:6px;flex-wrap:wrap;margin-top:6px">
          <button class="pos-cat pos-cat-sub ${String(State.activeCat)===activeMainId?'is-active':''}" data-cat="${activeMainId}"><span class="pos-cat-name">↳ Vše</span></button>
          ${subs.map(s => `<button class="pos-cat pos-cat-sub ${String(s.id)===String(State.activeCat)?'is-active':''}" data-cat="${s.id}">
            <span class="pos-cat-name">${s.ikona ? esc(s.ikona)+' ' : ''}${esc(s.nazev)}</span><span class="pos-cat-count">${s.pocet}</span></button>`).join('')}
        </div>`;
      }
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

    if (State.activeCat === 'sezona') {
      // 🍰 v3.0.406 — virtuální kategorie: výrobky aktivních sezón
      items = items.filter(v => v.sezona_aktivni);
    } else if (State.activeCat !== 'all') {
      // 🆕 v3.0.335 — hlavní kategorie zahrne i produkty ve svých subkategoriích
      const subIds = (State.catalog?.kategorie || []).filter(k => String(k.parent_id) === String(State.activeCat)).map(k => String(k.id));
      items = items.filter(v => String(v.kategorie_id) === State.activeCat || subIds.includes(String(v.kategorie_id)));
    }
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

  // ══════════════════════════════════════════════════════════════
  // 💱 v3.0.643 — PLATBA V KČ I V CIZÍ MĚNĚ (Nastavení → Přístupy, ceny & měna)
  //   Ceník je v Kč; měnu platby volí prodavač přepínačem Kč | €. Displej počítá STEJNĚ jako
  //   server (cena/ks v měně na centy, řádek, DPH po řádcích), autoritativní částku vrací server.
  //   Vypnuto (CFG.platba.aktivni=false) → nic se neukazuje, pokladna jede jako dřív.
  // ══════════════════════════════════════════════════════════════
  const PL = () => (CFG.platba || { aktivni: false, meny: {}, prijimane: ['CZK'], vychozi: 'CZK' });
  const plAktivni = () => !!PL().aktivni && (PL().prijimane || []).length > 1;
  const plInfo = (m) => (PL().meny || {})[m] || null;
  const r2c = (x) => { x = +x || 0; return Math.sign(x) * Math.round(Math.abs(x) * 100 + 1e-7) / 100; };
  const plSym = (m) => ({ CZK: 'Kč', EUR: '€', USD: '$', GBP: '£', PLN: 'zł', HUF: 'Ft', CHF: 'CHF' })[m] || m;
  const plFmt = (n, m) => (+n || 0).toLocaleString('cs-CZ', { minimumFractionDigits: 2, maximumFractionDigits: 2 }) + ' ' + plSym(m);
  // Řádky dokladu tak, jak je uloží server: položky, sleva po sazbách, spropitné, poplatek platby
  function plRadky(poplatek) {
    const rows = []; const zakl = {};
    for (const it of State.cart) {
      const s = +it.sazba_dph || 0, mn = +it.mnozstvi || 0, c = +it.cena_bez_dph || 0;
      rows.push([mn, c, s]); zakl[s] = (zakl[s] || 0) + mn * c;
    }
    if (State.sleva_pct > 0) for (const s in zakl) { const sb = r2c(zakl[s] * State.sleva_pct / 100); if (sb > 0) rows.push([1, -sb, +s]); }
    if (State.pos_tip > 0) rows.push([1, +State.pos_tip, 0]);
    if (poplatek > 0) rows.push([1, r2c(poplatek / 1.21), 21]);
    return rows;
  }
  function plCelkemMena(rows, mena) {
    const inf = plInfo(mena);
    if (!inf || !inf.kurz_prodej) return null;
    const k = 1 / inf.kurz_prodej; let sum = 0, sumKc = 0;
    for (const [mn, cena, s] of rows) { const c = r2c(cena * k); const z = r2c(mn * c); sum += z + r2c(z * s / 100); sumKc += mn * cena; }
    return Math.abs(sumKc) < 0.005 ? 0 : r2c(sum);   // 100% sleva/voucher = přesně 0 (jako server)
  }
  function renderMenaBar() {
    let bar = document.getElementById('pos-mena-bar');
    if (!bar) {
      const pay = document.querySelector('.pos-payment-bar');
      if (!pay) return;
      bar = document.createElement('div');
      bar.id = 'pos-mena-bar';
      bar.style.cssText = 'display:none;gap:6px;align-items:center;flex-wrap:wrap;margin:6px 0 2px';
      pay.parentNode.insertBefore(bar, pay.nextSibling);
    }
    if (!plAktivni()) { bar.style.display = 'none'; State.platba_mena = 'CZK'; return; }
    const meny = PL().prijimane || ['CZK'];
    if (!meny.includes(State.platba_mena)) State.platba_mena = PL().vychozi || 'CZK';
    const inf = plInfo(State.platba_mena);
    const kurzTxt = State.platba_mena !== 'CZK' && inf && inf.kurz_prodej
      ? `1 ${plSym(State.platba_mena)} = ${(+inf.kurz_prodej).toLocaleString('cs-CZ', { maximumFractionDigits: 3 })} Kč${inf.datum ? ' · ' + inf.datum.split('-').reverse().join('.') : ''}` : '';
    bar.innerHTML = `<span style="font-size:12px;color:#6B7280;font-weight:600">Měna platby:</span>`
      + meny.map(m => {
          const i = plInfo(m) || {}; const dis = m !== 'CZK' && i.ok === false;
          return `<button type="button" class="pos-pay-btn${State.platba_mena === m ? ' is-active' : ''}" style="min-width:64px" ${dis ? 'disabled' : ''} title="${esc(dis ? (i.chyba || 'Kurz není k dispozici') : 'Platit v ' + m)}" onclick="POS.setMena('${m}')">${m === 'CZK' ? 'Kč' : esc(plSym(m)) + ' ' + m}</button>`;
        }).join('')
      + (kurzTxt ? `<span style="font-size:11.5px;color:#6B7280">${esc(kurzTxt)}</span>` : '');
    bar.style.display = 'flex';
  }
  function setMena(m) { State.platba_mena = m; renderMenaBar(); recalc(); }
  async function plObnovInfo() {
    try { CFG.platba = await api('admin_pos.php?action=platba_info'); } catch (e) { /* zůstane původní */ }
    renderMenaBar(); recalc();
  }
  // Hotovost: přijato → vrátit (měna vrácení dle Nastavení: měna platby / Kč / volba prodavače)
  function plHotovostDialog(castka, mena) {
    return new Promise((resolve) => {
      const rez = PL().vraceni || 'mena_platby';
      const kp = mena === 'CZK' ? 1 : (+(plInfo(mena) || {}).kurz_prodej || 1);
      const ov = document.createElement('div');
      ov.style.cssText = 'position:fixed;inset:0;background:rgba(0,0,0,.6);display:flex;align-items:center;justify-content:center;z-index:10000;padding:16px';
      const volba = mena !== 'CZK' && rez === 'volba';
      ov.innerHTML = `<div style="background:#fff;color:#1F2937;border-radius:18px;padding:22px;max-width:400px;width:100%;font-family:inherit;box-shadow:0 20px 60px rgba(0,0,0,.3)">
        <div style="font-size:13px;color:#6B7280;font-weight:600">💵 Hotově · k úhradě</div>
        <div style="font-size:30px;font-weight:800;margin:2px 0 12px">${esc(plFmt(castka, mena))}</div>
        <label style="font-size:13px;font-weight:600">Přijato (${esc(plSym(mena))})</label>
        <input id="plh-in" type="number" inputmode="decimal" step="0.01" min="0" placeholder="${esc(String(castka))}" style="width:100%;font-size:24px;padding:10px 12px;border:2px solid #D1D5DB;border-radius:10px;margin:4px 0 8px">
        <div id="plh-quick" style="display:flex;gap:6px;flex-wrap:wrap;margin-bottom:10px"></div>
        ${volba ? `<div style="display:flex;gap:14px;font-size:14px;margin-bottom:8px"><label><input type="radio" name="plh-vm" value="${mena}" checked> Vrátit v ${esc(plSym(mena))}</label><label><input type="radio" name="plh-vm" value="CZK"> Vrátit v Kč</label></div>` : ''}
        <div id="plh-out" style="font-size:20px;font-weight:800;min-height:28px;margin-bottom:12px"></div>
        <div style="display:flex;gap:8px"><button type="button" id="plh-x" style="flex:1;padding:12px;border-radius:10px;border:1px solid #D1D5DB;background:#fff;font-size:15px">Zrušit</button>
        <button type="button" id="plh-ok" style="flex:2;padding:12px;border-radius:10px;border:0;background:#16A34A;color:#fff;font-size:16px;font-weight:800">✓ Zaplatit</button></div></div>`;
      document.body.appendChild(ov);
      const inp = ov.querySelector('#plh-in'); const out = ov.querySelector('#plh-out');
      const quick = [castka, ...[5, 10, 20, 50, 100, 200, 500, 1000, 2000, 5000].filter(v => v > castka)].slice(0, 5);
      ov.querySelector('#plh-quick').innerHTML = quick.map(v => `<button type="button" data-v="${v}" style="padding:8px 12px;border-radius:8px;border:1px solid #D1D5DB;background:#F9FAFB;font-size:14px;font-weight:700">${esc(plFmt(v, mena))}</button>`).join('');
      ov.querySelectorAll('#plh-quick button').forEach(b => b.onclick = () => { inp.value = b.dataset.v; upd(); });
      const vm = () => volba ? (ov.querySelector('input[name="plh-vm"]:checked') || {}).value : (rez === 'czk' ? 'CZK' : mena);
      function upd() {
        const p = parseFloat(String(inp.value).replace(',', '.'));
        if (!isFinite(p)) { out.textContent = ''; return; }
        const v = r2c(p - castka);
        if (v < -0.004) { out.style.color = '#DC2626'; out.textContent = 'Chybí ' + plFmt(-v, mena); return; }
        out.style.color = '#16A34A';
        out.textContent = (vm() === 'CZK' && mena !== 'CZK') ? 'Vrátit ' + Math.round(v * kp).toLocaleString('cs-CZ') + ' Kč' : 'Vrátit ' + plFmt(v, mena);
      }
      inp.oninput = upd; ov.querySelectorAll('input[name="plh-vm"]').forEach(r => r.onchange = upd);
      const done = (val) => { ov.remove(); resolve(val); };
      ov.querySelector('#plh-x').onclick = () => done(null);
      ov.querySelector('#plh-ok').onclick = () => {
        const p = parseFloat(String(inp.value).replace(',', '.'));
        if (isFinite(p) && p + 0.004 < castka) { upd(); return; }
        done({ prijato: isFinite(p) ? r2c(p) : null, vraceni_mena: vm() });
      };
      inp.onkeydown = (e) => { if (e.key === 'Enter') ov.querySelector('#plh-ok').click(); if (e.key === 'Escape') done(null); };
      setTimeout(() => inp.focus(), 50);
    });
  }
  // Karta v cizí měně: terminál se ovládá ručně → velká výzva s částkou
  function plTerminalDialog(castka, mena) {
    return new Promise((resolve) => {
      const ov = document.createElement('div');
      ov.style.cssText = 'position:fixed;inset:0;background:rgba(0,0,0,.6);display:flex;align-items:center;justify-content:center;z-index:10000;padding:16px';
      ov.innerHTML = `<div style="background:#fff;color:#1F2937;border-radius:18px;padding:24px;max-width:420px;width:100%;text-align:center;font-family:inherit">
        <div style="font-size:15px;font-weight:700;color:#6B7280">💳 ZADEJ DO TERMINÁLU</div>
        <div style="font-size:38px;font-weight:900;margin:8px 0 6px">${esc(plFmt(castka, mena))}</div>
        <div style="font-size:13px;color:#6B7280;margin-bottom:16px">Na terminálu zvol měnu ${esc(mena)} — ne Kč.</div>
        <div style="display:flex;gap:8px"><button type="button" id="plt-x" style="flex:1;padding:12px;border-radius:10px;border:1px solid #D1D5DB;background:#fff;font-size:15px">Zrušit</button>
        <button type="button" id="plt-ok" style="flex:2;padding:12px;border-radius:10px;border:0;background:#16A34A;color:#fff;font-size:16px;font-weight:800">✓ Zaplaceno</button></div></div>`;
      document.body.appendChild(ov);
      ov.querySelector('#plt-x').onclick = () => { ov.remove(); resolve(false); };
      ov.querySelector('#plt-ok').onclick = () => { ov.remove(); resolve(true); };
    });
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
      // 🐛 v3.0.643 — stejně jako server: sleva po sazbách DPH (základ na haléře)
      const zakl = {};
      for (const it of State.cart) { const s = +it.sazba_dph || 0; zakl[s] = (zakl[s] || 0) + it.mnozstvi * it.cena_bez_dph; }
      for (const s in zakl) {
        const sb = r2c(zakl[s] * State.sleva_pct / 100);
        if (sb <= 0) continue;
        bez -= sb; dph -= sb * (+s / 100); slevaAbs += sb * (1 + (+s) / 100);
      }
    }
    // 🐛 v3.0.643 — poplatek platby (karta-fee) jako server: z configu metody nad bez+DPH (dřív /pos/ ukazovala
    //   nižší částku a kalkulačka hotovosti/EUR nesouhlasila se serverem)
    let poplatek = 0;
    const pm = (State._payMethods || []).find(m => m.key === State.pos_payment);
    if (pm && parseFloat(pm.poplatek) > 0) {
      poplatek = (pm.poplatek_typ === 'pct') ? r2c((r2c(bez) + r2c(dph)) * parseFloat(pm.poplatek) / 100) : parseFloat(pm.poplatek);
    }
    State._poplatek = poplatek;
    const total = bez + dph + (State.pos_tip || 0) + poplatek;

    $('#pos-sub-bez').textContent = fmt(bez);
    $('#pos-sub-dph').textContent = fmt(dph);
    $('#pos-total').textContent   = fmt(total) + ' ' + (CFG.currency || 'Kč');
    // 💱 v3.0.643 — platba v cizí měně: celkem v ní (shodně se serverem) + Kč pro kontrolu
    State._celkemKc = Math.round(total * 100) / 100;
    State._celkemMena = null;
    if (plAktivni() && State.platba_mena !== 'CZK') {
      State._celkemMena = plCelkemMena(plRadky(poplatek), State.platba_mena);
      if (State._celkemMena !== null) $('#pos-total').innerHTML = esc(plFmt(State._celkemMena, State.platba_mena)) + ` <small style="font-size:.55em;opacity:.7;font-weight:600">(${esc(fmt(total))} Kč)</small>`;
    }

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
    recalc();   // poplatek metody (karta-fee) se promítne hned
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
    const _draftN = loadDrafts().length;
    m.innerHTML = `
      <button class="pos-pay-opt" data-act="discount">💰 Sleva %</button>
      <button class="pos-pay-opt" data-act="tip">💵 Spropitné</button>
      <button class="pos-pay-opt" data-act="note">💬 Poznámka</button>
      <button class="pos-pay-opt" data-act="drafts">📋 Rozpracované${_draftN ? ' (' + _draftN + ')' : ''}</button>
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
        if (a === 'drafts')   openDrafts();
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
  function clearOrder() {
    State.cart = [];
    State.odberatel = null;
    State.poznamka = '';
    State.sleva_pct = 0;
    State.pos_tip = 0;
    setTyp('sebou');
    setPay('hotove');
    State.platba_mena = PL().vychozi || 'CZK';   // 💱 po prodeji zpět na výchozí měnu
    renderMenaBar();
    _pickCust(null);
    renderCart();
  }
  function newOrder() {
    if (State.cart.length > 0 && !confirm('Aktuální košík obsahuje položky. Začít nový?')) return;
    clearOrder();
  }

  // ─── Rozpracované objednávky (drafty) ────────────────────────
  // 🆕 v3.0.187 — saveDraft byl stub (toast „Uloženo", ale TODO/nic neuložil → klamal).
  //   Teď reálná persistence v localStorage (drafty žijí na terminálu) + obnova/smazání.
  const DRAFTS_KEY = 'appek_pos_drafts';
  function loadDrafts() { try { return JSON.parse(localStorage.getItem(DRAFTS_KEY) || '[]'); } catch (e) { return []; } }
  function storeDrafts(a) { try { localStorage.setItem(DRAFTS_KEY, JSON.stringify(a)); } catch (e) {} }
  function cartTotal() {
    let s = 0;
    for (const it of State.cart) s += it.mnozstvi * it.cena_bez_dph * (1 + (it.sazba_dph || 0) / 100);
    return s * (1 - (State.sleva_pct || 0) / 100) + (State.pos_tip || 0);
  }
  function saveDraft() {
    if (State.cart.length === 0) return toast('Košík je prázdný', 'error');
    const drafts = loadDrafts();
    drafts.unshift({
      id: Date.now(),
      ts: new Date().toISOString(),
      cart: State.cart.map(it => ({ ...it })),
      odberatel: State.odberatel,
      pos_typ: State.pos_typ,
      pos_payment: State.pos_payment,
      pos_tip: State.pos_tip,
      sleva_pct: State.sleva_pct,
      poznamka: State.poznamka,
      pocet: State.cart.length,
      total: cartTotal(),
    });
    storeDrafts(drafts.slice(0, 30));
    toast('💾 Uloženo do rozpracovaných', 'success');
    clearOrder();
  }
  function openDrafts() {
    const drafts = loadDrafts();
    if (drafts.length === 0) return toast('Žádné rozpracované objednávky', '');
    const body = `<div class="pos-draft-list">${drafts.map(d => `
      <div class="pos-draft-row">
        <button class="pos-draft-load" onclick="POS.restoreDraft(${d.id})">
          <span class="pos-draft-main"><strong>${esc(_hhmm(d.ts))}</strong> · ${d.pocet} pol.</span>
          <span class="pos-receipt-meta">${d.odberatel ? esc(d.odberatel.nazev || 'Zákazník') : 'Neznámý zákazník'}${d.poznamka ? ' · 💬' : ''}</span>
        </button>
        <span class="pos-draft-amt">${fmt(d.total)} Kč</span>
        <button class="pos-draft-del" onclick="POS.deleteDraft(${d.id})" title="Smazat">🗑️</button>
      </div>`).join('')}</div>`;
    modal('📋 Rozpracované objednávky', body);
  }
  function restoreDraft(id) {
    const drafts = loadDrafts();
    const d = drafts.find(x => x.id === id);
    if (!d) return;
    if (State.cart.length > 0 && !confirm('Nahradit aktuální košík rozpracovanou objednávkou?')) return;
    State.cart = (d.cart || []).map(it => ({ ...it }));
    State.odberatel = d.odberatel || null;
    State.pos_tip = d.pos_tip || 0;
    State.sleva_pct = d.sleva_pct || 0;
    State.poznamka = d.poznamka || '';
    setTyp(d.pos_typ || 'sebou');
    setPay(d.pos_payment || 'hotove');
    _pickCust(d.odberatel || null);
    renderCart();
    storeDrafts(drafts.filter(x => x.id !== id));  // stal se aktivním → ven z draftů
    closeModal();
    switchTab('products');
    toast('Obnoveno do košíku', 'success');
  }
  function deleteDraft(id) {
    storeDrafts(loadDrafts().filter(x => x.id !== id));
    if (loadDrafts().length === 0) { closeModal(); toast('Smazáno', ''); }
    else openDrafts();
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
    // 📦 v3.0.623 — Vyzvednutí: čas + jméno/tel/e-mail + zaplatit hned / při vyzvednutí (pos-pickup.js)
    let pickup = null;
    if (State.pos_typ === 'vyzvednuti' && window.POSPickup) {
      pickup = await window.POSPickup.collect(($('#pos-total') || {}).textContent || '');
      if (!pickup) return;
    }
    if (!pickup && !confirm(`Dokončit objednávku?\n\nCelkem: ${$('#pos-total').textContent}\nPlatba: ${State.pos_payment}\nTyp: ${State.pos_typ}`)) return;
    // 💱 v3.0.643 — měna platby + hotovost (přijato/vrátit) + výzva terminálu u karty v cizí měně
    const plMena = plAktivni() ? State.platba_mena : null;
    let plPrijato = null, plVraceni = null;
    if (plMena && !(pickup && pickup.zaplatit_pozdeji)) {
      const inf = plInfo(plMena);
      if (plMena !== 'CZK' && (!inf || !inf.ok || !inf.kurz_prodej)) return toast('Platba v ' + plMena + ' teď nejde: ' + ((inf && inf.chyba) || 'chybí kurz'), 'error');
      const castka = plMena === 'CZK' ? State._celkemKc : State._celkemMena;
      if (State.pos_payment === 'hotove') {
        const res = await plHotovostDialog(castka, plMena);
        if (!res) return;
        plPrijato = res.prijato; plVraceni = res.vraceni_mena;
      } else if (State.pos_payment === 'karta' && plMena !== 'CZK') {
        if (!(PL().terminal_meny || ['CZK']).includes(plMena)) return toast('Platební terminál neumí ' + plMena + ' — zaplať kartou v Kč nebo hotově', 'error');
        if (!(await plTerminalDialog(castka, plMena))) return;
      }
    }
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
          pos_payment:  (pickup && pickup.zaplatit_pozdeji) ? 'pri_vyzvednuti' : State.pos_payment,
          pos_tip:      State.pos_tip || 0,
          sleva_pct:    State.sleva_pct || 0,
          poznamka:     State.poznamka || '',
          ...(pickup ? { vyzvednuti_cas: pickup.vyzvednuti_cas, vyzvednuti_jmeno: pickup.vyzvednuti_jmeno, vyzvednuti_tel: pickup.vyzvednuti_tel, vyzvednuti_email: pickup.vyzvednuti_email } : {}),
          station_token: (function(){ try { return localStorage.getItem('appek_station_token') || ''; } catch(e){ return ''; } })(),
          ...(plMena ? { platba_mena: plMena, kurz_klient: plMena !== 'CZK' ? (plInfo(plMena) || {}).kurz_prodej : undefined, prijato: plPrijato, vraceni_mena: plVraceni } : {}),
        }),
      });
      if (plMena && !r.nezaplaceno && (r.mena !== 'CZK' || r.vraceno > 0)) {
        toast('✓ Účet ' + r.cislo + ' · ' + plFmt(r.celkem_mena, r.mena) + (r.vraceno > 0 ? ' · VRÁTIT ' + (r.vraceno_czk != null ? Math.round(r.vraceno_czk) + ' Kč' : plFmt(r.vraceno, r.vraceno_mena || r.mena)) : ''), 'success');
      } else toast(r.nezaplaceno ? `📦 Uloženo k vyzvednutí · ${r.cislo} · platí při vyzvednutí ${fmt(r.celkem)} Kč` : `✓ Účet ${r.cislo} · ${fmt(r.celkem)} Kč`, 'success');
      if (pickup && window.POSPickup) window.POSPickup.refresh();
      newOrder();
    } catch (e) {
      toast('Chyba: ' + e.message, 'error');
      if (/kurz/i.test(e.message || '')) plObnovInfo();   // 💱 kurz se změnil → načti nový a přepočítej
    } finally {
      if (btn) { btn.disabled = false; btn.textContent = '✓ FINISH'; }
    }
  }

  // ─── Taby v hlavičce: Produkty / Účtenky / Statistiky ────────
  // 🆕 v3.0.187 — taby byly v HTML, ale bez handleru (mrtvé). Teď přepínají
  //   mezi katalogem (.pos-main) a alt-view (#pos-altview) s reálnými daty.
  function switchTab(tab) {
    if (!tab) return;
    State.activeTab = tab;
    $$('.pos-tab-h').forEach(t => t.classList.toggle('active', t.dataset.tab === tab));
    const main = $('.pos-main');
    const alt  = $('#pos-altview');
    if (tab === 'products') {
      if (main) main.style.display = '';
      if (alt) { alt.hidden = true; alt.innerHTML = ''; }
      return;
    }
    if (main) main.style.display = 'none';
    if (alt) { alt.hidden = false; alt.innerHTML = '<div class="pos-alt-empty">⏳ Načítám…</div>'; }
    if (tab === 'orders')  renderOrders();
    if (tab === 'reports') renderReports();
  }

  const _PAY_IC = { hotove:'💵', karta:'💳', qr_platba:'📲', gift_card:'🎁', voucher:'🎟️', mobile:'📱', paypal:'🅿️', stripe:'💳', gopay:'📲', prevod:'🏦', dobirka:'📦', faktura:'🧾' };
  const _TYP_LBL = { sebou:'🛍️ Sebou', vyzvednuti:'📦 Vyzvednutí', rozvoz:'🛵 Rozvoz', na_miste:'🍽️ Na místě' };
  const _hhmm = (dt) => { try { return new Date(String(dt).replace(' ','T')).toLocaleTimeString('cs-CZ',{hour:'2-digit',minute:'2-digit'}); } catch(e){ return ''; } };

  // ─── Účtenky — POS prodeje za zvolený den (default dnes) ─────
  // 🐛 „POS nenačítá historii": standalone kasa uměla jen dnešek (quick_history bez date,
  //   bez navigace). Doplněna volba dne (date-picker + ←/→/Dnes), jako má admin POS.
  const _todayISO = () => { const d = new Date(); return d.getFullYear() + '-' + String(d.getMonth() + 1).padStart(2, '0') + '-' + String(d.getDate()).padStart(2, '0'); };
  const _dateCs = (iso) => { try { return new Date(iso + 'T00:00:00').toLocaleDateString('cs-CZ', { weekday: 'short', day: 'numeric', month: 'numeric', year: 'numeric' }); } catch (e) { return iso; } };
  let _histDate = _todayISO();
  async function renderOrders() {
    const alt = $('#pos-altview');
    if (!alt) return;
    try {
      const r = await api('admin_pos.php?action=quick_history&date=' + encodeURIComponent(_histDate) + '&limit=100');
      const orders = r.objednavky || [];
      const s = r.souhrn || {};
      const trzby = s['tržby'] ?? s.trzby ?? 0;
      const isToday = _histDate === _todayISO();
      alt.innerHTML = `
        <div class="pos-alt-wrap">
          <div class="pos-alt-head">
            <h2 class="pos-alt-title">📜 Účtenky — ${isToday ? 'dnes' : esc(_dateCs(_histDate))}</h2>
            <div class="pos-alt-sum">
              <span class="pos-chip"><strong>${s.pocet || 0}</strong> účtenek</span>
              <span class="pos-chip pos-chip-green"><strong>${fmt(trzby)}</strong> Kč</span>
              <span class="pos-chip">💵 ${fmt(s.hotove || 0)}</span>
              <span class="pos-chip">💳 ${fmt(s.karta || 0)}</span>
            </div>
          </div>
          <div class="pos-hist-nav" style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;margin-bottom:12px">
            <button class="pos-modal-btn" onclick="POS.histShift(-1)">← Předchozí</button>
            <input type="date" value="${_histDate}" max="${_todayISO()}" onchange="POS.histSet(this.value)" style="padding:8px 10px;border:2px solid #e1e5eb;border-radius:8px;font-size:15px;font-family:inherit">
            <button class="pos-modal-btn" onclick="POS.histShift(1)"${isToday ? ' disabled' : ''}>Další →</button>
            ${isToday ? '' : '<button class="pos-modal-btn" onclick="POS.histSet(POS._today())">📅 Dnes</button>'}
          </div>
          ${orders.length === 0
            ? `<div class="pos-alt-empty">🧾 ${isToday ? 'Dnes zatím žádné účtenky.' : 'V tento den žádné účtenky.'}</div>`
            : `<div class="pos-receipt-list">${orders.map(o => `
                <button class="pos-receipt-row" onclick="POS.receipt(${o.id})">
                  <div class="pos-receipt-main">
                    <strong>${esc(o.cislo)}</strong>
                    <span class="pos-receipt-meta">${_hhmm(o.datum_objednani)} · ${o.pocet_polozek} pol. · ${_PAY_IC[o.pos_payment] || ''} · ${_TYP_LBL[o.pos_typ] || esc(o.pos_typ || '')}</span>
                  </div>
                  <div class="pos-receipt-amt">${fmtDocPos(o)}</div>
                </button>`).join('')}</div>`}
        </div>`;
    } catch (e) {
      alt.innerHTML = `<div class="pos-alt-empty">❌ ${esc(e.message)}</div>`;
    }
  }

  async function showReceipt(id) {
    try {
      const o = await api('admin_pos.php?action=quick_order_detail&id=' + id);
      const pol = o.polozky || [];
      const body = `
        <div class="pos-rdetail-meta">${esc(o.odberatel_nazev || '—')}${o.pos_uzivatel ? ' · ' + esc(o.pos_uzivatel) : ''} · ${_hhmm(o.datum_objednani)}</div>
        <table class="pos-rdetail-tbl">
          ${pol.map(p => `<tr><td class="rd-q">${(+p.mnozstvi)}×</td><td class="rd-n">${esc(p.vyrobek_nazev)}</td><td class="rd-a">${fmtDocPos({ doc_mena: o.doc_mena, castka_celkem: p.cena_bez_dph * p.mnozstvi, doc_celkem: p.doc_radek != null ? p.doc_radek : p.cena_bez_dph * p.mnozstvi })}</td></tr>`).join('')}
        </table>
        <div class="pos-rdetail-tot">
          <div><span>Bez DPH</span><span>${fmtDocPos({ doc_mena: o.doc_mena, castka_celkem: o.castka_bez_dph, doc_celkem: o.doc_bez })}</span></div>
          <div><span>DPH</span><span>${fmtDocPos({ doc_mena: o.doc_mena, castka_celkem: o.castka_dph, doc_celkem: o.doc_dph })}</span></div>
          <div class="rd-grand"><span>CELKEM</span><span>${fmtDocPos(o)}</span></div>
        </div>`;
      // 🆕 v3.0.268 — VRATKY: refundace zaplacené účtenky (záporná účtenka VRA-…)
      const foot = `<button class="pos-modal-btn" onclick='POS._printReceipt(${JSON.stringify(o)})'>🖨️ Tisk účtenky</button>`
        + ((+o.castka_celkem) > 0 ? `<button class="pos-modal-btn" style="color:#DC2626" onclick="POS.refundReceipt(${o.id}, '${esc(o.cislo || '').replace(/'/g, '')}')">↩️ Vrátit</button>` : '');
      modal('🧾 ' + o.cislo, body, foot);
    } catch (e) { toast('Chyba: ' + e.message, 'error'); }
  }

  // 🆕 v3.0.268 — VRATKY
  async function refundReceipt(id, cislo) {
    const duvod = prompt('Vrátit účtenku ' + cislo + '?\n\nVznikne záporná účtenka — tržby i uzávěrka se sníží.\nDůvod (volitelný):', '');
    if (duvod === null) return;
    const vratitNaSklad = confirm('Vrátit vrácené zboží zpět na sklad?\n\nOK = ano (naskladní vrácené kusy)\nZrušit = ne (jen peníze).');
    try {
      const r = await api('admin_pos.php?action=refund_order', { method: 'POST', body: JSON.stringify({ objednavka_id: id, duvod: duvod || '', vratit_na_sklad: vratitNaSklad }) });
      closeModal();
      toast('✅ Vratka ' + r.cislo + ' (' + fmt(r.castka_celkem) + ' Kč)' + (vratitNaSklad ? (r.restocked > 0 ? ' · 📦 ' + r.restocked + '× na sklad' : ' · sklad beze změny') : ''), 'success');
      if (typeof renderOrders === 'function') renderOrders();
    } catch (e) { toast('❌ ' + e.message, 'error'); }
  }

  function printReceiptDoc(o) {
    const pol = o.polozky || [];
    const html = `<!DOCTYPE html><html><head><meta charset="utf-8"><title>Účtenka ${esc(o.cislo)}</title>
      <style>body{font-family:Courier,monospace;font-size:12px;width:280px;margin:10px auto}
        h1{font-size:14px;text-align:center;margin:0 0 4px}
        .it{display:flex;justify-content:space-between;border-bottom:1px dashed #aaa;padding:4px 0}
        .tot{font-weight:700;font-size:14px;margin-top:8px;text-align:right}</style></head><body>
      <h1>APPEK POS — ${esc(o.cislo)}</h1>
      <div style="text-align:center;margin-bottom:8px">${esc(_hhmm(o.datum_objednani))}</div>
      ${pol.map(p => `<div class="it"><span>${esc(p.vyrobek_nazev)} ×${(+p.mnozstvi)}</span><span>${(p.cena_bez_dph*p.mnozstvi).toFixed(2)}</span></div>`).join('')}
      <div class="tot">CELKEM: ${Number(o.castka_celkem||0).toFixed(2)} Kč</div>
      <div style="text-align:center;margin-top:10px;font-size:10px">Děkujeme za nákup!</div>
      <script>window.print();<\/script></body></html>`;
    const w = window.open('', '_blank', 'width=320,height=600');
    if (!w) return toast('Povolte popup okna pro tisk', 'error');
    w.document.write(html); w.document.close();
  }

  // ─── Statistiky — dnešní souhrn + TOP položky ────────────────
  async function renderReports() {
    const alt = $('#pos-altview');
    if (!alt) return;
    try {
      const r = await api('admin_pos.php?action=launcher_summary&limit_items=15');
      const s = r.souhrn || {};
      const top = r.top_items || [];
      alt.innerHTML = `
        <div class="pos-alt-wrap">
          <div class="pos-alt-head"><h2 class="pos-alt-title">📊 Statistiky — dnes</h2></div>
          <div class="pos-stat-cards">
            <div class="pos-stat-card pos-stat-green"><div class="pos-stat-val">${fmt(s.trzby || 0)} Kč</div><div class="pos-stat-lbl">Tržby</div></div>
            <div class="pos-stat-card"><div class="pos-stat-val">${s.pocet || 0}</div><div class="pos-stat-lbl">Účtenek</div></div>
            <div class="pos-stat-card"><div class="pos-stat-val">💵 ${fmt(s.hotove || 0)}</div><div class="pos-stat-lbl">Hotově</div></div>
            <div class="pos-stat-card"><div class="pos-stat-val">💳 ${fmt(s.karta || 0)}</div><div class="pos-stat-lbl">Kartou</div></div>
            <div class="pos-stat-card"><div class="pos-stat-val">🎁 ${fmt(s.tipy || 0)}</div><div class="pos-stat-lbl">Spropitné</div></div>
          </div>
          <h3 class="pos-alt-sub">🔥 TOP prodané položky</h3>
          ${top.length === 0
            ? '<div class="pos-alt-empty">Žádná data.</div>'
            : `<div class="pos-top-list">${top.map((t, i) => `
                <div class="pos-top-row">
                  <span class="pos-top-rank">${i + 1}.</span>
                  <span class="pos-top-name">${esc(t.vyrobek_nazev)}</span>
                  <span class="pos-top-qty">${fmt(t.mnozstvi_sum)}×</span>
                  <span class="pos-top-amt">${fmt(t.trzba_sum)} Kč</span>
                </div>`).join('')}</div>`}
        </div>`;
    } catch (e) {
      alt.innerHTML = `<div class="pos-alt-empty">❌ ${esc(e.message)}</div>`;
    }
  }

  // ─── Public POS object ───────────────────────────────────────
  window.POS = {
    search:        setSearch,
    newOrder:      newOrder,
    refundReceipt: refundReceipt, // 🆕 v3.0.268 — vratky
    // 🐛 historie účtenek — volba dne (viz renderOrders)
    _today:        _todayISO,
    histSet:       function (d) { if (d) { _histDate = d; renderOrders(); } },
    histShift:     function (n) { const d = new Date(_histDate + 'T00:00:00'); d.setDate(d.getDate() + n); const iso = d.getFullYear() + '-' + String(d.getMonth() + 1).padStart(2, '0') + '-' + String(d.getDate()).padStart(2, '0'); if (iso <= _todayISO()) { _histDate = iso; renderOrders(); } },

    pickCustomer:  pickCustomer,
    setTyp:        setTyp,
    setPay:        setPay,
    setMena:       setMena,       // 💱 v3.0.643
    payMenu:       payMenu,
    menuToggle:    menuToggle,
    printReceipt:  printReceipt,
    saveDraft:     saveDraft,
    addNote:       addNote,
    finish:        finish,
    switchTab:     switchTab,
    receipt:       showReceipt,
    openDrafts:    openDrafts,
    restoreDraft:  restoreDraft,
    deleteDraft:   deleteDraft,
    _printReceipt: printReceiptDoc,
    _pickCust:     _pickCust,
    _closeModal:   closeModal,
    state:         State,
    reload:        loadCatalog,
  };

  // ─── Init ────────────────────────────────────────────────────
  document.addEventListener('DOMContentLoaded', () => {
    setTimeout(() => { renderMenaBar(); if (plAktivni()) plObnovInfo(); }, 0);   // 💱 v3.0.643
    // 🐛 v3.0.643 — poplatky metod platby (stejný zdroj jako admin pokladna) → součet = server
    fetch('../api/payment_methods.php?context=pos', { cache: 'no-store' }).then(r => r.json())
      .then(j => { State._payMethods = j.methods || []; recalc(); }).catch(() => { State._payMethods = []; });
    // Search input live binding
    const inp = $('#pos-search');
    if (inp) {
      inp.addEventListener('input', (e) => setSearch(e.target.value));
    }
    // 🆕 v3.0.187 — taby v hlavičce (Produkty / Účtenky / Statistiky)
    $$('.pos-tab-h').forEach(t => t.addEventListener('click', () => switchTab(t.dataset.tab)));
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
    // 🆕 v3.0.191 — obnov uložený zoom kasy
    applyPosZoom();
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

  // 📱 Heartbeat stanice — kasa se hlásí do přehledu Nástroje → Stanice
  (function posStationHeartbeat() {
    function tok() {
      let t = ''; try { t = localStorage.getItem('appek_station_token') || ''; } catch (e) {}
      if (!/^[a-f0-9]{24,}$/.test(t)) {
        const a = new Uint8Array(16);
        if (self.crypto && crypto.getRandomValues) crypto.getRandomValues(a);
        else for (let i = 0; i < a.length; i++) a[i] = Math.floor(Math.random() * 256);
        t = Array.from(a, b => b.toString(16).padStart(2, '0')).join('');
        try { localStorage.setItem('appek_station_token', t); } catch (e) {}
      }
      return t;
    }
    async function ping() {
      try {
        const r = await api('admin_stanice.php?action=ping', {
          method: 'POST',
          body: JSON.stringify({ token: tok(), role: 'pos', nazev: 'Kasa' }),
        });
        if (r && r.cmd === 'reload') { try { location.reload(); } catch (e) {} }
      } catch (e) { /* tiše */ }
    }
    setTimeout(ping, 3000);
    setInterval(ping, 40000);
  })();
})();
