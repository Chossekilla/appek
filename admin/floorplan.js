/* ═════════════════════════════════════════════════════════════════
   🗺️ APPEK FLOOR PLAN EDITOR — Standalone JS Core
   ═════════════════════════════════════════════════════════════════ */
'use strict';

(function () {
  const CFG = window.FP_CONFIG || {};

  // ─── State ────────────────────────────────────────────────────
  const State = {
    zones:     [],          // [{id?, nazev, ikona, canvas_w, canvas_h, items:[]}]
    activeZoneIdx: 0,
    selected:  null,        // item ID nebo null
    counter:   1,           // pro generování unique IDs
    zoom:      1.0,
    showGrid:  true,
    snap:      true,
    dirty:     false,       // unsaved changes
    history:   [],          // undo stack
    historyPtr: -1,
    maxHistory: 50,
  };

  // ─── DOM helpers ─────────────────────────────────────────────
  const $   = s => document.querySelector(s);
  const $$  = s => Array.from(document.querySelectorAll(s));
  const esc = s => String(s ?? '').replace(/[&<>"']/g, m => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[m]));

  // ─── Prefab item definitions ─────────────────────────────────
  const PREFABS = {
    'round-2':  { type: 'round',   w: 60,  h: 60,  mist: 2, nazev: 'S' },
    'round-4':  { type: 'round',   w: 80,  h: 80,  mist: 4, nazev: 'S' },
    'round-6':  { type: 'round',   w: 100, h: 100, mist: 6, nazev: 'S' },
    'square-4': { type: 'square',  w: 80,  h: 80,  mist: 4, nazev: 'S' },
    'rect-6':   { type: 'rect',    w: 120, h: 80,  mist: 6, nazev: 'S' },
    'rect-8':   { type: 'rect',    w: 160, h: 80,  mist: 8, nazev: 'S' },
    'bar-2':    { type: 'bar',     w: 40,  h: 40,  mist: 1, nazev: 'B' },
    'lounge':   { type: 'lounge',  w: 140, h: 100, mist: 8, nazev: 'L' },
    'wall-h':   { type: 'wall',    w: 200, h: 12,  nazev: '' },
    'wall-v':   { type: 'wall',    w: 12,  h: 200, nazev: '' },
    'door':     { type: 'door',    w: 60,  h: 50,  nazev: '🚪' },
    'bar-cnt':  { type: 'bar-cnt', w: 240, h: 60,  nazev: '🍸 Bar' },
    'kitchen':  { type: 'kitchen', w: 180, h: 120, nazev: '👨‍🍳 Kuchyně' },
    'wc':       { type: 'wc',      w: 90,  h: 80,  nazev: '🚻 WC' },
    'plant':    { type: 'plant',   w: 40,  h: 40,  nazev: '🌿' },
    'text':     { type: 'text',    w: 120, h: 40,  nazev: 'Popisek' },
  };

  // ─── API helper ──────────────────────────────────────────────
  async function api(path, opts = {}) {
    const url = CFG.apiBase + path;
    const method = (opts.method || 'GET').toUpperCase();
    const headers = { 'Accept': 'application/json', 'Content-Type': 'application/json', ...(opts.headers || {}) };
    if (method !== 'GET' && CFG.csrfToken) headers['X-CSRF-Token'] = CFG.csrfToken;
    const r = await fetch(url, { method, headers, body: opts.body, credentials: 'same-origin' });
    const ct = r.headers.get('content-type') || '';
    const data = ct.includes('application/json') ? await r.json() : { error: await r.text() };
    if (!r.ok) throw new Error(data?.error || data?.message || ('HTTP ' + r.status));
    return data;
  }

  // ─── Toast ───────────────────────────────────────────────────
  function toast(msg, kind = '') {
    const host = $('#fp-toast');
    if (!host) return;
    const el = document.createElement('div');
    el.className = 'fp-toast' + (kind ? ' ' + kind : '');
    el.textContent = msg;
    host.appendChild(el);
    setTimeout(() => { el.style.opacity = '0'; setTimeout(() => el.remove(), 200); }, 2200);
  }

  // 🆕 v3.0.241 — i18n se v tomto editoru nenačítá → 4 volání t() házela "t is not defined"
  //   (mj. „Chyba apply", tiché selhání „Načíst šablonu", mazání zóny, import). Lokální
  //   fallback s českými texty + {param} substitucí (forward-compatible, kdyby přišlo i18n).
  function t(key, params) {
    const S = {
      fp_confirm_delete_zone: 'Smazat zónu „{nazev}"? Smaže i všechny stoly v této zóně.',
      fp_confirm_load_template_destructive: 'POZOR — Načtení šablony PŘEPÍŠE celý floor plan (všechny zóny i stoly). Pokračovat?\n\nTip: „Přidat jako novou zónu" šablonu připojí bez přepsání.',
      fp_toast_applied_zones: '✓ Aplikováno do produkce: {zon} zón · {stoly} stolů · {mist} míst',
      fp_confirm_import_overwrite: 'Importovat JSON? Přepíše aktuální rozložení.',
    };
    let s = S[key] || key;
    if (params) for (const k in params) s = s.replaceAll('{' + k + '}', params[k]);
    return s;
  }

  // ─── Modal ───────────────────────────────────────────────────
  function modal(title, bodyHtml, footHtml = '') {
    const host = $('#fp-modal');
    host.innerHTML = `<div class="fp-modal" role="dialog">
      <div class="fp-modal-head">
        <div class="fp-modal-title">${esc(title)}</div>
        <button class="fp-modal-x" onclick="FP._closeModal()">✕</button>
      </div>
      <div class="fp-modal-body">${bodyHtml}</div>
      ${footHtml ? `<div class="fp-modal-foot">${footHtml}</div>` : ''}
    </div>`;
    host.hidden = false;
    host.onclick = e => { if (e.target === host) closeModal(); };
  }
  function closeModal() { const h = $('#fp-modal'); h.hidden = true; h.innerHTML = ''; }

  // ─── History (undo/redo) ─────────────────────────────────────
  function snapshot() {
    return JSON.stringify(State.zones);
  }
  function pushHistory() {
    // Drop redo branch
    State.history = State.history.slice(0, State.historyPtr + 1);
    State.history.push(snapshot());
    if (State.history.length > State.maxHistory) State.history.shift();
    State.historyPtr = State.history.length - 1;
    updateUndoRedoButtons();
    setDirty(true);
  }
  function undo() {
    if (State.historyPtr <= 0) return;
    State.historyPtr--;
    State.zones = JSON.parse(State.history[State.historyPtr]);
    renderZoneTabs();
    renderCanvas();
    renderProps();
    updateUndoRedoButtons();
  }
  function redo() {
    if (State.historyPtr >= State.history.length - 1) return;
    State.historyPtr++;
    State.zones = JSON.parse(State.history[State.historyPtr]);
    renderZoneTabs();
    renderCanvas();
    renderProps();
    updateUndoRedoButtons();
  }
  function updateUndoRedoButtons() {
    $('#fp-undo-btn').disabled = State.historyPtr <= 0;
    $('#fp-redo-btn').disabled = State.historyPtr >= State.history.length - 1;
  }
  function setDirty(d) {
    State.dirty = d;
    const $st = $('#fp-foot-status');
    if ($st) {
      $st.className = d ? 'dirty' : 'saved';
      $st.textContent = d ? '⚠️ Změny neuloženy' : '✓ Uloženo';
    }
  }

  // ─── Active zone helpers ─────────────────────────────────────
  function activeZone() {
    return State.zones[State.activeZoneIdx] || null;
  }

  // ─── Render zone tabs ────────────────────────────────────────
  function renderZoneTabs() {
    const wrap = $('#fp-zone-tabs');
    if (!wrap) return;
    wrap.innerHTML = State.zones.map((z, i) => `
      <button class="fp-zone-tab ${i === State.activeZoneIdx ? 'is-active' : ''}" data-idx="${i}">
        <span class="ic">${esc(z.ikona || '🍽️')}</span>
        ${esc(z.nazev)}
        <span class="cnt">${(z.items || []).length}</span>
      </button>
    `).join('');
    wrap.querySelectorAll('.fp-zone-tab').forEach(b => {
      b.onclick = () => {
        State.activeZoneIdx = parseInt(b.dataset.idx, 10);
        State.selected = null;
        renderZoneTabs();
        renderCanvas();
        renderProps();
      };
    });
    // Update footer counts
    const totalStoly = State.zones.reduce((s, z) => s + ((z.items || []).filter(it => ['round','square','rect','bar','lounge'].includes(it.type))).length, 0);
    if ($('#fp-foot-stoly')) $('#fp-foot-stoly').textContent = totalStoly + ' stolů';
    if ($('#fp-foot-zones')) $('#fp-foot-zones').textContent = State.zones.length + ' zón';
  }

  // ─── Render canvas ───────────────────────────────────────────
  function renderCanvas() {
    const z = activeZone();
    const canvas = $('#fp-canvas');
    const itemsWrap = $('#fp-canvas-items');
    const zoneName = $('#fp-zone-name');
    const zoneInfo = $('#fp-zone-info');
    if (!canvas || !itemsWrap) return;
    if (!z) {
      itemsWrap.innerHTML = '';
      if (zoneName) zoneName.textContent = '— žádná zóna —';
      if (zoneInfo) zoneInfo.textContent = '';
      return;
    }
    canvas.style.width  = (z.canvas_w || 1200) + 'px';
    canvas.style.height = (z.canvas_h || 800) + 'px';
    if (zoneName) zoneName.textContent = (z.ikona || '🍽️') + ' ' + z.nazev;
    if (zoneInfo) zoneInfo.textContent = `${z.canvas_w || 1200} × ${z.canvas_h || 800} px`;

    const items = z.items || [];
    itemsWrap.innerHTML = items.map(it => renderItemHtml(it)).join('');
    items.forEach(it => attachItemHandlers(itemsWrap.querySelector(`[data-id="${it.id}"]`), it));
  }

  function renderItemHtml(it) {
    const isSelected = State.selected === it.id;
    const rot = it.rotace || 0;
    const transform = rot ? `transform:rotate(${rot}deg)` : '';
    const isStul = ['round','square','rect','bar','lounge'].includes(it.type);
    const labelText = it.nazev || '';
    const subText = (isStul && it.mist) ? `<div style="font-size:10px;opacity:0.7;font-weight:600">${it.mist}p</div>` : '';
    return `
      <div class="fp-item ${isSelected ? 'is-selected' : ''}"
           data-id="${it.id}" data-type="${it.type}"
           style="left:${it.x}px;top:${it.y}px;width:${it.w}px;height:${it.h}px;${transform}">
        <div class="fp-item-label">${esc(labelText)}${subText}</div>
        ${isSelected ? `
          <div class="fp-rotate-handle" title="Otočit">⟳</div>
          <div class="fp-resize-handle" title="Změnit velikost"></div>
        ` : ''}
      </div>
    `;
  }

  // ─── Item drag / select / resize / rotate handlers ───────────
  // 🆕 v2.9.48 — Přepsáno na PointerEvents + setPointerCapture
  // → plynulejší drag, funguje pro mouse + touch + pen jednotně
  // → kurzor zůstává "zachycený" elementem i mimo viewport
  function attachItemHandlers(el, it) {
    if (!el) return;

    el.style.touchAction = 'none';
    el.style.cursor = 'grab';

    el.addEventListener('pointerdown', (e) => {
      if (e.button !== undefined && e.button !== 0) return; // jen levé tlačítko
      const tgt = e.target;
      if (tgt.classList.contains('fp-resize-handle')) return startResize(e);
      if (tgt.classList.contains('fp-rotate-handle')) return startRotate(e);
      startDrag(e);
    });

    function startDrag(e) {
      e.stopPropagation();
      e.preventDefault();

      // 🐛 fix v3.0.164 — vyber prvek BEZ renderCanvas(). Předtím se při kliku
      // na nevybraný stůl zavolal renderCanvas() (přepsal DOM, odpojil 'el') +
      // return → probíhající pointer gesto se zahodilo a táhnout šlo až napodruhé
      // ("canvas se chová špatně"). Teď jen zvýrazníme výběr přes class a táhnutí
      // pokračuje ve stejném gestu; handles (resize/rotate) dorenderujeme v cleanup().
      if (State.selected !== it.id) {
        State.selected = it.id;
        document.querySelectorAll('#fp-canvas-items .fp-item.is-selected')
          .forEach(n => n.classList.remove('is-selected'));
        el.classList.add('is-selected');
        renderProps(); // panel vlastností nesahá na canvas DOM → gesto přežije
      }

      // Capture pointer — všechny pointer události půjdou na el i mimo
      try { el.setPointerCapture(e.pointerId); } catch (_) {}
      el.style.cursor = 'grabbing';
      el.classList.add('dragging');

      const startX = e.clientX;
      const startY = e.clientY;
      const origX  = it.x;
      const origY  = it.y;
      let didMove  = false;

      function onPointerMove(ev) {
        ev.preventDefault();
        const dx = (ev.clientX - startX) / State.zoom;
        const dy = (ev.clientY - startY) / State.zoom;
        if (!didMove && (Math.abs(dx) > 2 || Math.abs(dy) > 2)) didMove = true;
        if (!didMove) return;

        let nx = origX + dx;
        let ny = origY + dy;
        if (State.snap) {
          nx = Math.round(nx / 10) * 10;
          ny = Math.round(ny / 10) * 10;
        }
        nx = Math.max(0, nx);
        ny = Math.max(0, ny);
        const z = activeZone();
        if (z) {
          nx = Math.min(nx, (z.canvas_w || 1200) - it.w);
          ny = Math.min(ny, (z.canvas_h || 800)  - it.h);
        }
        it.x = nx; it.y = ny;
        el.style.left = nx + 'px';
        el.style.top  = ny + 'px';
      }

      function cleanup(ev) {
        el.removeEventListener('pointermove', onPointerMove);
        el.removeEventListener('pointerup',   cleanup);
        el.removeEventListener('pointercancel', cleanup);
        try { el.releasePointerCapture(ev.pointerId); } catch (_) {}
        el.classList.remove('dragging');
        if (didMove) pushHistory();
        // Po skončení gesta přerenderuj — vykreslí handles vybraného prvku
        // i finální pozici (bezpečné, gesto už doběhlo). 🐛 fix v3.0.164
        renderCanvas();
        renderProps();
      }

      el.addEventListener('pointermove', onPointerMove);
      el.addEventListener('pointerup',   cleanup);
      el.addEventListener('pointercancel', cleanup);
    }

    function startResize(e) {
      e.stopPropagation();
      e.preventDefault();
      const handle = e.target;
      try { handle.setPointerCapture(e.pointerId); } catch (_) {}
      const startX = e.clientX;
      const startY = e.clientY;
      const origW  = it.w;
      const origH  = it.h;

      function onMove(ev) {
        ev.preventDefault();
        let nw = Math.max(30, origW + (ev.clientX - startX) / State.zoom);
        let nh = Math.max(30, origH + (ev.clientY - startY) / State.zoom);
        if (State.snap) {
          nw = Math.round(nw / 10) * 10;
          nh = Math.round(nh / 10) * 10;
        }
        it.w = nw; it.h = nh;
        el.style.width  = nw + 'px';
        el.style.height = nh + 'px';
      }
      function cleanup(ev) {
        handle.removeEventListener('pointermove', onMove);
        handle.removeEventListener('pointerup', cleanup);
        try { handle.releasePointerCapture(ev.pointerId); } catch (_) {}
        pushHistory();
        renderProps();
      }
      handle.addEventListener('pointermove', onMove);
      handle.addEventListener('pointerup',   cleanup);
    }

    function startRotate(e) {
      e.stopPropagation();
      e.preventDefault();
      const handle = e.target;
      try { handle.setPointerCapture(e.pointerId); } catch (_) {}
      const rect = el.getBoundingClientRect();
      const cx = rect.left + rect.width / 2;
      const cy = rect.top  + rect.height / 2;
      const startAngle = Math.atan2(e.clientY - cy, e.clientX - cx);
      const origRot = it.rotace || 0;

      function onMove(ev) {
        ev.preventDefault();
        const r = el.getBoundingClientRect();
        const ccx = r.left + r.width / 2;
        const ccy = r.top  + r.height / 2;
        const ang = Math.atan2(ev.clientY - ccy, ev.clientX - ccx);
        let deg = origRot + (ang - startAngle) * 180 / Math.PI;
        if (State.snap) deg = Math.round(deg / 15) * 15;
        it.rotace = Math.round(deg);
        el.style.transform = `rotate(${it.rotace}deg)`;
      }
      function cleanup(ev) {
        handle.removeEventListener('pointermove', onMove);
        handle.removeEventListener('pointerup', cleanup);
        try { handle.releasePointerCapture(ev.pointerId); } catch (_) {}
        pushHistory();
        renderProps();
      }
      handle.addEventListener('pointermove', onMove);
      handle.addEventListener('pointerup',   cleanup);
    }
  }

  function pointer(e) {
    if (e.touches && e.touches.length) return { x: e.touches[0].clientX, y: e.touches[0].clientY };
    if (e.changedTouches && e.changedTouches.length) return { x: e.changedTouches[0].clientX, y: e.changedTouches[0].clientY };
    return { x: e.clientX, y: e.clientY };
  }

  // ─── Add item via drag from sidebar ──────────────────────────
  function initSidebarDrag() {
    $$('.fp-tool[data-add]').forEach(t => {
      t.draggable = true;
      t.ondragstart = e => {
        e.dataTransfer.setData('text/plain', t.dataset.add);
        e.dataTransfer.effectAllowed = 'copy';
        t.classList.add('dragging');
      };
      t.ondragend = () => t.classList.remove('dragging');
      // Click-add: na střed canvasu
      t.onclick = () => addItem(t.dataset.add, null);
    });
    const canvas = $('#fp-canvas');
    if (canvas) {
      canvas.ondragover  = e => { e.preventDefault(); e.dataTransfer.dropEffect = 'copy'; };
      canvas.ondrop      = e => {
        e.preventDefault();
        const key = e.dataTransfer.getData('text/plain');
        if (!key) return;
        const rect = canvas.getBoundingClientRect();
        const x = (e.clientX - rect.left) / State.zoom;
        const y = (e.clientY - rect.top)  / State.zoom;
        addItem(key, { x, y });
      };
      canvas.onclick = e => {
        if (e.target === canvas || e.target.id === 'fp-canvas-items' || e.target.id === 'fp-canvas-grid') {
          State.selected = null;
          renderCanvas();
          renderProps();
        }
      };
    }
  }

  function addItem(key, pos) {
    const z = activeZone();
    if (!z) return toast('Nejdřív přidej zónu', 'error');
    const pref = PREFABS[key];
    if (!pref) return;
    let x = pos?.x, y = pos?.y;
    if (x == null) {
      x = ((z.canvas_w || 1200) - pref.w) / 2;
      y = ((z.canvas_h || 800)  - pref.h) / 2;
    } else {
      x = x - pref.w / 2;
      y = y - pref.h / 2;
    }
    if (State.snap) { x = Math.round(x / 10) * 10; y = Math.round(y / 10) * 10; }
    x = Math.max(0, Math.round(x));
    y = Math.max(0, Math.round(y));

    // Nazev — pro stoly inkrementuj číslo
    let nazev = pref.nazev;
    if (['round','square','rect','bar','lounge'].includes(pref.type)) {
      const used = (z.items || []).filter(it => it.type === pref.type).length;
      nazev = pref.nazev + (used + 1);
    }

    const item = {
      id: 'i' + (State.counter++),
      type: pref.type,
      nazev: nazev,
      x: x, y: y,
      w: pref.w, h: pref.h,
      mist: pref.mist,
      rotace: 0,
      barva: null,
    };
    z.items = z.items || [];
    z.items.push(item);
    State.selected = item.id;
    pushHistory();
    renderZoneTabs();
    renderCanvas();
    renderProps();
  }

  // ─── Render properties panel ─────────────────────────────────
  function renderProps() {
    const wrap = $('#fp-props');
    if (!wrap) return;
    if (!State.selected) {
      wrap.innerHTML = `
        <div class="fp-props-empty">
          <div class="fp-props-empty-ic">👆</div>
          <div class="fp-props-empty-title">Vyber prvek</div>
          <div class="fp-props-empty-sub">Klikni na stůl nebo přetáhni z knihovny vlevo</div>
        </div>`;
      return;
    }
    const z = activeZone();
    const it = (z?.items || []).find(x => x.id === State.selected);
    if (!it) { State.selected = null; return renderProps(); }

    const isStul = ['round','square','rect','bar','lounge'].includes(it.type);
    const TYPE_LABEL = {
      'round':'🟢 Kruhový stůl', 'square':'⬛ Čtvercový stůl', 'rect':'▭ Obdélníkový stůl',
      'bar':'🍸 Barová židle', 'lounge':'🛋️ Salonek',
      'wall':'🧱 Zeď', 'door':'🚪 Dveře', 'bar-cnt':'🍸 Barový pult',
      'kitchen':'👨‍🍳 Kuchyně', 'wc':'🚻 WC', 'plant':'🌿 Květina', 'text':'📝 Popisek',
    };

    wrap.innerHTML = `
      <div class="fp-prop-section">
        <div class="fp-prop-title">${esc(TYPE_LABEL[it.type] || it.type)}</div>
        <div class="fp-prop-field">
          <span class="fp-prop-label">Název / Popisek</span>
          <input class="fp-prop-input" type="text" value="${esc(it.nazev || '')}" oninput="FP._setProp('nazev', this.value)">
        </div>
      </div>

      ${isStul ? `
      <div class="fp-prop-section">
        <div class="fp-prop-title">Kapacita</div>
        <div class="fp-prop-field">
          <span class="fp-prop-label">Počet míst</span>
          <input class="fp-prop-input" type="number" min="1" max="40" value="${it.mist || 2}" oninput="FP._setProp('mist', parseInt(this.value,10)||1)">
        </div>
      </div>
      ` : ''}

      <div class="fp-prop-section">
        <div class="fp-prop-title">Pozice & velikost</div>
        <div class="fp-prop-row">
          <div class="fp-prop-field">
            <span class="fp-prop-label">X</span>
            <input class="fp-prop-input" type="number" value="${it.x}" oninput="FP._setProp('x', parseInt(this.value,10)||0)">
          </div>
          <div class="fp-prop-field">
            <span class="fp-prop-label">Y</span>
            <input class="fp-prop-input" type="number" value="${it.y}" oninput="FP._setProp('y', parseInt(this.value,10)||0)">
          </div>
        </div>
        <div class="fp-prop-row">
          <div class="fp-prop-field">
            <span class="fp-prop-label">Šířka</span>
            <input class="fp-prop-input" type="number" value="${it.w}" min="20" oninput="FP._setProp('w', parseInt(this.value,10)||20)">
          </div>
          <div class="fp-prop-field">
            <span class="fp-prop-label">Výška</span>
            <input class="fp-prop-input" type="number" value="${it.h}" min="20" oninput="FP._setProp('h', parseInt(this.value,10)||20)">
          </div>
        </div>
        <div class="fp-prop-field">
          <span class="fp-prop-label">Rotace (°)</span>
          <input class="fp-prop-input" type="number" min="0" max="359" value="${it.rotace || 0}" oninput="FP._setProp('rotace', parseInt(this.value,10)||0)">
        </div>
      </div>

      ${isStul ? `
      <div class="fp-prop-section">
        <div class="fp-prop-title">Vzhled</div>
        <div class="fp-prop-field">
          <span class="fp-prop-label">Vlastní barva (volitelné)</span>
          <input class="fp-prop-input" type="color" value="${it.barva || '#ffffff'}" oninput="FP._setProp('barva', this.value)">
        </div>
      </div>
      ` : ''}

      <div class="fp-prop-actions">
        <button class="fp-prop-btn" onclick="FP.duplicateItem()">📋 Duplikovat</button>
        <button class="fp-prop-btn danger" onclick="FP.deleteItem()">🗑️ Smazat</button>
      </div>
    `;
  }

  function setProp(key, value) {
    const z = activeZone();
    const it = (z?.items || []).find(x => x.id === State.selected);
    if (!it) return;
    it[key] = value;
    renderCanvas();
    if (['x','y','w','h','rotace','barva','mist'].includes(key)) {
      // No re-render of props (input would lose focus)
    } else {
      // For nazev — re-render
    }
    setDirty(true);
    // Don't push history per-keystroke — pushHistory on blur instead
  }

  function duplicateItem() {
    const z = activeZone();
    const it = (z?.items || []).find(x => x.id === State.selected);
    if (!it) return;
    const copy = { ...it, id: 'i' + (State.counter++), x: it.x + 20, y: it.y + 20 };
    z.items.push(copy);
    State.selected = copy.id;
    pushHistory();
    renderZoneTabs();
    renderCanvas();
    renderProps();
  }

  function deleteItem() {
    const z = activeZone();
    if (!z) return;
    z.items = z.items.filter(x => x.id !== State.selected);
    State.selected = null;
    pushHistory();
    renderZoneTabs();
    renderCanvas();
    renderProps();
  }

  // ─── Zone management ─────────────────────────────────────────
  function addZone() {
    const nazev = prompt('Název nové zóny:', 'Nová zóna ' + (State.zones.length + 1));
    if (!nazev) return;
    const ikona = prompt('Ikona (emoji):', '🍽️') || '🍽️';
    State.zones.push({
      nazev: nazev.trim(),
      ikona: ikona.trim(),
      canvas_w: 1200,
      canvas_h: 800,
      items: [],
    });
    State.activeZoneIdx = State.zones.length - 1;
    State.selected = null;
    pushHistory();
    renderZoneTabs();
    renderCanvas();
    renderProps();
  }

  function editZone() {
    const z = activeZone();
    if (!z) return;
    modal('Upravit zónu', `
      <div class="fp-prop-section">
        <div class="fp-prop-field">
          <span class="fp-prop-label">Název</span>
          <input class="fp-prop-input" id="fp-z-nazev" type="text" value="${esc(z.nazev)}">
        </div>
        <div class="fp-prop-field">
          <span class="fp-prop-label">Ikona</span>
          <input class="fp-prop-input" id="fp-z-ikona" type="text" value="${esc(z.ikona || '🍽️')}" maxlength="3">
        </div>
        <div class="fp-prop-row">
          <div class="fp-prop-field">
            <span class="fp-prop-label">Šířka canvasu (px)</span>
            <input class="fp-prop-input" id="fp-z-w" type="number" value="${z.canvas_w || 1200}" min="400">
          </div>
          <div class="fp-prop-field">
            <span class="fp-prop-label">Výška canvasu (px)</span>
            <input class="fp-prop-input" id="fp-z-h" type="number" value="${z.canvas_h || 800}" min="300">
          </div>
        </div>
      </div>
    `, `
      <button class="fp-btn-secondary-modal" onclick="FP._deleteZone()" style="color:#EF4444">🗑️ Smazat zónu</button>
      <div style="flex:1"></div>
      <button class="fp-btn-secondary-modal" onclick="FP._closeModal()">Zrušit</button>
      <button class="fp-btn-primary-modal" onclick="FP._saveZone()">💾 Uložit</button>
    `);
  }

  function saveZoneFromModal() {
    const z = activeZone();
    if (!z) return;
    z.nazev    = $('#fp-z-nazev').value.trim() || z.nazev;
    z.ikona    = $('#fp-z-ikona').value.trim() || '🍽️';
    z.canvas_w = Math.max(400, parseInt($('#fp-z-w').value, 10) || 1200);
    z.canvas_h = Math.max(300, parseInt($('#fp-z-h').value, 10) || 800);
    closeModal();
    pushHistory();
    renderZoneTabs();
    renderCanvas();
  }
  function deleteZoneFromModal() {
    if (State.zones.length <= 1) return toast('Musí být alespoň jedna zóna', 'error');
    if (!confirm(t('fp_confirm_delete_zone', { nazev: State.zones[State.activeZoneIdx].nazev }))) return;
    State.zones.splice(State.activeZoneIdx, 1);
    State.activeZoneIdx = Math.max(0, State.activeZoneIdx - 1);
    State.selected = null;
    closeModal();
    pushHistory();
    renderZoneTabs();
    renderCanvas();
    renderProps();
  }

  // ─── Zoom & grid ─────────────────────────────────────────────
  function setZoom(z) {
    State.zoom = Math.max(0.3, Math.min(2, z));
    const canvas = $('#fp-canvas');
    if (canvas) canvas.style.transform = `scale(${State.zoom})`;
    $('#fp-zoom-val').textContent = Math.round(State.zoom * 100) + '%';
  }
  function zoom(delta) { setZoom(State.zoom + delta); }
  function zoomReset() { setZoom(1); }
  function toggleGrid(on) {
    State.showGrid = on;
    $('#fp-canvas').classList.toggle('no-grid', !on);
  }
  function toggleSnap(on) { State.snap = on; }

  // ─── Clear canvas ────────────────────────────────────────────
  function clearCanvas() {
    const z = activeZone();
    if (!z) return;
    if (!confirm('Vymazat všechny prvky z aktuální zóny?')) return;
    z.items = [];
    State.selected = null;
    pushHistory();
    renderZoneTabs();
    renderCanvas();
    renderProps();
  }

  // ─── Templates: save / load / apply ─────────────────────────
  async function openSaveTemplate() {
    modal('Uložit jako šablonu', `
      <div class="fp-prop-section">
        <div class="fp-prop-field">
          <span class="fp-prop-label">Název šablony</span>
          <input class="fp-prop-input" id="fp-tpl-nazev" type="text" placeholder="např. Pizzerie Hlavní sál + Terasa" autofocus>
        </div>
        <div class="fp-prop-field">
          <span class="fp-prop-label">Popis (volitelné)</span>
          <input class="fp-prop-input" id="fp-tpl-popis" type="text" placeholder="Krátký popis layoutu">
        </div>
        <div class="fp-prop-field">
          <span class="fp-prop-label">Ikona</span>
          <input class="fp-prop-input" id="fp-tpl-ikona" type="text" value="🗺️" maxlength="3">
        </div>
        <div style="font-size:12px;color:#5f6470;margin-top:10px">
          📊 Bude uloženo: <strong>${State.zones.length}</strong> zón a
          <strong>${State.zones.reduce((s,z) => s + (z.items||[]).length, 0)}</strong> prvků
        </div>
      </div>
    `, `
      <button class="fp-btn-secondary-modal" onclick="FP._closeModal()">Zrušit</button>
      <button class="fp-btn-primary-modal" onclick="FP._saveTemplate()">💾 Uložit šablonu</button>
    `);
  }

  async function saveTemplateFromModal() {
    const nazev = $('#fp-tpl-nazev').value.trim();
    const popis = $('#fp-tpl-popis').value.trim();
    const ikona = $('#fp-tpl-ikona').value.trim() || '🗺️';
    if (!nazev) return toast('Vyplňte název šablony', 'error');
    try {
      // 1) Nejdřív aplikuj aktuální stav do DB (přes save_layout)
      await applyToProduction(true);
      // 2) Potom save_user_template z aktuálního DB stavu
      const r = await api('admin_tables.php?action=save_user_template', {
        method: 'POST',
        body: JSON.stringify({ nazev, popis, ikona }),
      });
      closeModal();
      toast(r.message || '✓ Šablona uložena', 'success');
    } catch (e) {
      toast('Chyba: ' + e.message, 'error');
    }
  }

  async function openTemplates() {
    let userTpls = [];
    let builtinTpls = [];
    try {
      const r = await api('admin_tables.php?action=user_templates');
      userTpls = r.templates || [];
    } catch (e) {}
    try {
      const r = await api('admin_tables.php?action=templates');
      builtinTpls = r.templates || [];
    } catch (e) {}

    const userHtml = userTpls.length ? userTpls.map(t => `
      <div class="fp-tpl-row">
        <div class="fp-tpl-ic">${esc(t.ikona || '🗺️')}</div>
        <div class="fp-tpl-meta">
          <div class="fp-tpl-nazev">${esc(t.nazev)}</div>
          <div class="fp-tpl-popis">${esc(t.popis || '')} · ${t.pocet_stolu || 0} stolů, ${t.pocet_zon || 0} zón · ${esc(t.created_by || '')}</div>
        </div>
        <div class="fp-tpl-actions">
          <button onclick="FP._applyTemplate(${t.id}, 'user')" title="Přepsat celý floor plan touto šablonou">📥 Načíst</button>
          <button onclick="FP._applyTemplate(${t.id}, 'user', true)" title="Přidat jako nové zóny — nepřepíše stávající">➕ Jako zónu</button>
          <button onclick="FP._exportTemplate(${t.id})">📤 Export</button>
          <button onclick="FP._deleteTemplate(${t.id})" title="Smazat" style="color:#EF4444">🗑️</button>
        </div>
      </div>`).join('') : '<div style="padding:20px;text-align:center;color:#9097a3">Žádné vlastní šablony</div>';

    const builtinHtml = builtinTpls.length ? builtinTpls.map(t => `
      <div class="fp-tpl-row">
        <div class="fp-tpl-ic">${esc(t.ikona || '🍕')}</div>
        <div class="fp-tpl-meta">
          <div class="fp-tpl-nazev">${esc(t.nazev || t.key)}</div>
          <div class="fp-tpl-popis">${esc(t.popis || '')} · built-in šablona</div>
        </div>
        <div class="fp-tpl-actions">
          <button onclick="FP._applyBuiltin('${esc(t.key)}')">📥 Načíst</button>
        </div>
      </div>`).join('') : '';

    modal('Šablony floor planu', `
      <div class="fp-prop-section">
        <div class="fp-prop-title">Vlastní šablony</div>
        <div class="fp-tpl-list">${userHtml}</div>
      </div>
      ${builtinHtml ? `
      <div class="fp-prop-section">
        <div class="fp-prop-title">Předpřipravené šablony</div>
        <div class="fp-tpl-list">${builtinHtml}</div>
      </div>` : ''}
    `, `<button class="fp-btn-secondary-modal" onclick="FP._closeModal()">Zavřít</button>`);
  }

  // 🆕 v3.0.241 — merge=true → přidá šablonu jako NOVÉ zóny (additivně, nepřepíše).
  //   Backend apply_user_template merge param to umí (jen wipe přeskočí).
  async function applyTemplate(id, kind, merge) {
    if (merge) {
      if (!confirm('Přidat šablonu jako nové zóny? Stávající zóny i stoly zůstanou zachované.')) return;
    } else {
      if (!confirm(t('fp_confirm_load_template_destructive'))) return;
    }
    try {
      const action = (kind === 'user') ? 'apply_user_template' : 'apply_template';
      const body = (kind === 'user') ? { id, merge: !!merge } : { template: id, merge: !!merge };
      await api('admin_tables.php?action=' + action, {
        method: 'POST',
        body: JSON.stringify(body),
      });
      closeModal();
      toast(merge ? '✓ Šablona přidána jako nová zóna' : '✓ Šablona načtena', 'success');
      // Reload from DB
      await loadFromDB();
    } catch (e) {
      toast('Chyba: ' + e.message, 'error');
    }
  }

  async function exportTemplate(id) {
    window.location.href = CFG.apiBase + 'admin_tables.php?action=export_template&id=' + id;
  }

  async function deleteTemplate(id) {
    if (!confirm('Smazat tuto šablonu?')) return;
    try {
      await api('admin_tables.php?action=user_template&id=' + id, { method: 'DELETE' });
      toast('Šablona smazána', 'success');
      openTemplates();
    } catch (e) { toast(e.message, 'error'); }
  }

  // ─── Apply current state to production ───────────────────────
  async function applyToProduction(silent) {
    // 🆕 v2.9.48 — Použij dedicated endpoint co pošle ŽIVÝ STATE z editoru přímo do DB
    // (předtím buggy 2-step flow s save_user_template/apply_user_template snapshotoval DB stav, ne editor!)
    try {
      // Posbírej všechny items ze všech zón
      const allItems = [];
      State.zones.forEach((z, zoneIdx) => {
        (z.items || []).forEach(it => {
          allItems.push({
            dbId:    it.dbId ?? null,   // 🆕 v3.0.202 — reálné DB id existujícího stolu → server UPDATEuje (zachová rezervace/ID), místo destruktivního reinsertu
            type:    it.type,
            nazev:   it.nazev,
            mist:    it.mist || null,
            x:       it.x,
            y:       it.y,
            w:       it.w,
            h:       it.h,
            rotace:  it.rotace || 0,
            barva:   it.barva || null,
            zone_idx: zoneIdx,
          });
        });
      });

      const r = await api('admin_tables.php?action=apply_editor_state', {
        method: 'POST',
        body: JSON.stringify({
          zones: State.zones.map(z => ({
            dbId:     z.dbId ?? null,   // 🆕 v3.0.202 — reálné DB id zóny
            nazev:    z.nazev,
            ikona:    z.ikona,
            canvas_w: z.canvas_w || 1200,
            canvas_h: z.canvas_h || 800,
          })),
          tables: allItems,
        }),
      });

      // 🆕 v3.0.202 — resync editor s DB: nové stoly/zóny dostanou dbId, takže další „Aplikovat"
      //   je UPDATEem (neduplikuje). Reload je levný a stav je po uložení konzistentní.
      await loadFromDB();

      if (!silent) {
        const stoly = r.pocet_stolu ?? 0;
        const mist  = r.celkem_mist ?? 0;
        const zon   = r.pocet_zon ?? 0;
        toast(t('fp_toast_applied_zones', { zon, stoly, mist }), 'success');
        setDirty(false);
        loadCapacity();
      }
    } catch (e) {
      if (!silent) toast('Chyba apply: ' + e.message, 'error');
      throw e;
    }
  }

  // ─── Export PNG ──────────────────────────────────────────────
  async function exportPNG() {
    const canvas = $('#fp-canvas');
    if (!canvas) return;
    // Use html2canvas if available, otherwise fallback to SVG export
    if (typeof html2canvas === 'undefined') {
      // Fallback: dynamicky načti html2canvas
      const script = document.createElement('script');
      script.src = 'https://cdn.jsdelivr.net/npm/html2canvas@1.4.1/dist/html2canvas.min.js';
      document.head.appendChild(script);
      await new Promise((r, e) => { script.onload = r; script.onerror = () => e(new Error('Nelze stáhnout html2canvas')); });
    }
    try {
      const c = await html2canvas(canvas, { backgroundColor: '#ffffff', scale: 2 });
      const link = document.createElement('a');
      link.download = `floorplan-${(activeZone()?.nazev || 'export').replace(/\s+/g, '_')}.png`;
      link.href = c.toDataURL('image/png');
      link.click();
      toast('PNG exportován', 'success');
    } catch (e) {
      toast('Chyba exportu: ' + e.message, 'error');
    }
  }

  // ─── Export / Import JSON ────────────────────────────────────
  function exportJSON() {
    const data = {
      version: 1,
      zones: State.zones,
      exported_at: new Date().toISOString(),
    };
    const blob = new Blob([JSON.stringify(data, null, 2)], { type: 'application/json' });
    const link = document.createElement('a');
    link.download = `floorplan-${Date.now()}.json`;
    link.href = URL.createObjectURL(blob);
    link.click();
    toast('JSON exportován', 'success');
  }

  function importJSON() {
    const inp = document.createElement('input');
    inp.type = 'file';
    inp.accept = 'application/json';
    inp.onchange = e => {
      const file = e.target.files[0];
      if (!file) return;
      const reader = new FileReader();
      reader.onload = ev => {
        try {
          const data = JSON.parse(ev.target.result);
          if (!data.zones || !Array.isArray(data.zones)) throw new Error('Neplatný formát');
          if (!confirm(t('fp_confirm_import_overwrite'))) return;
          State.zones = data.zones;
          State.activeZoneIdx = 0;
          State.selected = null;
          pushHistory();
          renderZoneTabs();
          renderCanvas();
          renderProps();
          toast('JSON načten', 'success');
        } catch (err) {
          toast('Chyba: ' + err.message, 'error');
        }
      };
      reader.readAsText(file);
    };
    inp.click();
  }

  // ─── Capacity / vytíženost — z produkce (footer + header chip) ─
  async function loadCapacity() {
    try {
      const r = await api('admin_tables.php?action=capacity');
      const c = r.capacity || {};
      const total = Number(c.celkem_mist || 0);
      const obs   = Number(c.obsazeno_mist || 0);
      const rez   = Number(c.rezerv_mist || 0);
      const vol   = Number(c.volno_mist || 0);
      const pct   = total > 0 ? Math.round(((obs + rez) / total) * 100) : 0;
      const emoji = pct >= 80 ? '🔴' : pct >= 50 ? '🟡' : '🟢';

      const $cap = $('#fp-foot-cap');
      if ($cap) {
        $cap.innerHTML = `${emoji} ${pct}% (${obs+rez}/${total} míst)`;
        $cap.title = `Vytíženost: ${pct}% · obsazeno ${obs} míst · rezerv. ${rez} · volných ${vol} · ${c.pocet_stolu || 0} stolů celkem`;
      }
    } catch (e) {
      const $cap = $('#fp-foot-cap');
      if ($cap) $cap.textContent = '📊 — / —';
    }
  }

  // ─── Load from DB (initial) ──────────────────────────────────
  async function loadFromDB() {
    try {
      const r = await api('admin_tables.php');
      const stoly = r.stoly || [];
      const zones = r.zones || [];

      // Group items by zone
      const zoneMap = {};
      for (const z of zones) {
        zoneMap[z.id] = {
          dbId: z.id,
          nazev: z.nazev,
          ikona: z.ikona || '🍽️',
          canvas_w: z.canvas_w || 1200,
          canvas_h: z.canvas_h || 800,
          items: [],
        };
      }
      // Default fallback zone
      let fallbackZone = null;
      if (zones.length === 0) {
        fallbackZone = { nazev: 'Hlavní sál', ikona: '🍽️', canvas_w: 1200, canvas_h: 800, items: [] };
      }

      for (const t of stoly) {
        const item = {
          id: 'i' + (State.counter++),
          dbId: t.id,
          type: t.tvar || 'square',  // round/square/rect
          nazev: t.nazev,
          mist: t.mist,
          x: t.x || 0, y: t.y || 0,
          w: t.width || 80, h: t.height || 80,
          rotace: t.rotace || 0,
          barva: t.barva || null,
        };
        const z = zoneMap[t.zone_id] || fallbackZone;
        if (z) z.items.push(item);
      }

      State.zones = Object.values(zoneMap);
      if (fallbackZone) State.zones.push(fallbackZone);
      if (State.zones.length === 0) {
        // Empty new install — add default zone
        State.zones = [{ nazev: 'Hlavní sál', ikona: '🍽️', canvas_w: 1200, canvas_h: 800, items: [] }];
      }
      State.activeZoneIdx = 0;
      State.selected = null;
      // Reset history with current state as first snapshot
      State.history = [snapshot()];
      State.historyPtr = 0;
      setDirty(false);
      renderZoneTabs();
      renderCanvas();
      renderProps();
      updateUndoRedoButtons();
    } catch (e) {
      toast('Chyba načtení: ' + e.message, 'error');
      // Empty fallback
      State.zones = [{ nazev: 'Hlavní sál', ikona: '🍽️', canvas_w: 1200, canvas_h: 800, items: [] }];
      State.history = [snapshot()];
      State.historyPtr = 0;
      renderZoneTabs();
      renderCanvas();
      renderProps();
    }
  }

  // ─── Public API ──────────────────────────────────────────────
  window.FP = {
    undo, redo,
    addZone, editZone, clearCanvas,
    zoom, zoomReset, toggleGrid, toggleSnap,
    duplicateItem, deleteItem,
    openSaveTemplate, openTemplates, applyToProduction,
    exportPNG, exportJSON, importJSON,
    _setProp: setProp,
    _closeModal: closeModal,
    _saveZone: saveZoneFromModal,
    _deleteZone: deleteZoneFromModal,
    _saveTemplate: saveTemplateFromModal,
    _applyTemplate: (id, k) => applyTemplate(id, k),
    _applyBuiltin: (key) => applyTemplate(key, 'builtin'),
    _exportTemplate: exportTemplate,
    _deleteTemplate: deleteTemplate,
    state: State,
  };

  // ─── Init ────────────────────────────────────────────────────
  document.addEventListener('DOMContentLoaded', () => {
    initSidebarDrag();

    // Keyboard shortcuts
    document.addEventListener('keydown', e => {
      // Skip if typing in input
      if (e.target.matches('input, textarea')) return;
      if ((e.metaKey || e.ctrlKey) && e.key === 'z' && !e.shiftKey) { e.preventDefault(); undo(); }
      else if ((e.metaKey || e.ctrlKey) && (e.key === 'Z' || (e.key === 'z' && e.shiftKey))) { e.preventDefault(); redo(); }
      else if (e.key === 'Delete' || e.key === 'Backspace') { if (State.selected) { e.preventDefault(); deleteItem(); } }
      else if (e.key === 'Escape') { State.selected = null; renderCanvas(); renderProps(); closeModal(); }
      else if (e.key === 'd' && (e.metaKey || e.ctrlKey)) { e.preventDefault(); if (State.selected) duplicateItem(); }
    });

    // Warn before close if dirty
    window.addEventListener('beforeunload', e => {
      if (State.dirty) {
        e.preventDefault();
        e.returnValue = 'Máte neuložené změny. Opravdu chcete odejít?';
        return e.returnValue;
      }
    });

    loadFromDB();
    loadCapacity();
    // Auto-refresh vytíženosti každých 30 s
    setInterval(loadCapacity, 30000);
  });
})();
