// =============================================================
// 🚀 v2.9.53 — Aplikace dropdown v topbaru (B2B / POS / Floor Plan)
// =============================================================
window.tbToggleApps = function(e) {
  if (e) { e.stopPropagation(); e.preventDefault(); }
  var menu = document.getElementById('tb-apps-menu');
  var wrap = document.getElementById('tb-apps-wrap');
  var btn  = wrap ? wrap.querySelector('.tb-apps-btn') : null;
  if (!menu) return;
  var isOpen = menu.classList.contains('show');
  if (isOpen) {
    menu.classList.remove('show');
    menu.style.display = 'none';                 // inline — nepřebitelné CSS cache
    if (btn) btn.setAttribute('aria-expanded', 'false');
  } else {
    menu.classList.add('show');
    // Force všechny floating styly inline (immune to CSS cache)
    menu.style.display = 'flex';
    menu.style.flexDirection = 'column';
    menu.style.position = 'absolute';
    menu.style.top = 'calc(100% + 8px)';
    menu.style.right = '0';
    menu.style.left = 'auto';
    menu.style.zIndex = '10060';
    menu.style.minWidth = '280px';
    menu.style.background = '#fff';
    menu.style.border = '1px solid rgba(0,0,0,0.12)';
    menu.style.borderRadius = '14px';
    menu.style.boxShadow = '0 12px 36px rgba(0,0,0,0.16)';
    menu.style.padding = '8px';
    menu.style.gap = '2px';
    if (btn) btn.setAttribute('aria-expanded', 'true');
  }
};
// Init — zajisti že apps menu je zavřené (inline display:none) po loadu
(function() {
  function closeAppsInit() {
    var menu = document.getElementById('tb-apps-menu');
    if (menu) { menu.classList.remove('show'); menu.style.display = 'none'; }
  }
  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', closeAppsInit);
  } else { closeAppsInit(); }
  setTimeout(closeAppsInit, 300);
})();
// Zavři apps dropdown při kliku mimo
document.addEventListener('click', function(e) {
  var wrap = document.getElementById('tb-apps-wrap');
  if (!wrap) return;
  if (!wrap.contains(e.target)) {
    var menu = document.getElementById('tb-apps-menu');
    if (menu && menu.classList.contains('show')) {
      menu.classList.remove('show');
      var btn = wrap.querySelector('.tb-apps-btn');
      if (btn) btn.setAttribute('aria-expanded', 'false');
    }
  }
}, true);
// Escape zavře
document.addEventListener('keydown', function(e) {
  if (e.key !== 'Escape') return;
  var menu = document.getElementById('tb-apps-menu');
  if (menu && menu.classList.contains('show')) menu.classList.remove('show');
});

// =============================================================
// 📱 v2.9.55 — Mobilní header compact — JS bulletproof
// Na mobilu (≤700px) schová texty co zabírají místo: jméno,
// kód jazyka "CS", label "Aplikace". CSS-cache-immune (inline styly).
// =============================================================
(function() {
  function applyMobileHeader() {
    var isMobile = window.matchMedia && window.matchMedia('(max-width: 700px)').matches;

    // Prvky které na mobilu zaberou zbytečně místo
    var hideOnMobile = [
      '.topbar .topbar-user',           // "Vítejte, Demo Admin" + datum (datum je v sidebar-logo)
      '.topbar .lang-trigger-code',     // "CS" text za vlajkou
      '.topbar .tb-apps-lbl',           // "Aplikace" text
      '.topbar .tb-apps-arr',           // šipka ▾ u Aplikace
      '.topbar .btn-logout-label',      // "Odhlásit" text
      '.topbar #fullscreen-btn',        // fullscreen tlačítko
    ];
    hideOnMobile.forEach(function(sel) {
      document.querySelectorAll(sel).forEach(function(el) {
        el.style.display = isMobile ? 'none' : '';
      });
    });

    // Apps tlačítko na mobilu = čtverec (jen 🚀)
    var appsBtn = document.querySelector('.topbar .tb-apps-btn');
    if (appsBtn) {
      if (isMobile) {
        appsBtn.style.width = '40px';
        appsBtn.style.padding = '0';
        appsBtn.style.justifyContent = 'center';
      } else {
        appsBtn.style.width = '';
        appsBtn.style.padding = '';
        appsBtn.style.justifyContent = '';
      }
    }
    // Logout na mobilu = čtverec (jen ↩)
    var logoutBtn = document.getElementById('logout-btn');
    if (logoutBtn) {
      if (isMobile) {
        logoutBtn.style.width = '40px';
        logoutBtn.style.padding = '0';
        logoutBtn.style.justifyContent = 'center';
      } else {
        logoutBtn.style.width = '';
        logoutBtn.style.padding = '';
        logoutBtn.style.justifyContent = '';
      }
    }
  }
  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', applyMobileHeader);
  } else {
    applyMobileHeader();
  }
  window.addEventListener('resize', function() {
    clearTimeout(window._mobHdrT);
    window._mobHdrT = setTimeout(applyMobileHeader, 120);
  });
  // Re-apply po loadu (admin DOM přichází postupně po loginu)
  setTimeout(applyMobileHeader, 200);
  setTimeout(applyMobileHeader, 800);
  setTimeout(applyMobileHeader, 2000);
  window.applyMobileHeader = applyMobileHeader;
})();

/* ═══════════════════════════════════════════════════════════════════
   🆕 v3.0.326 — GLOBÁLNÍ SKENER ČÁROVÝCH KÓDŮ
   HW čtečka (keyboard-wedge) + kamera + routing přes admin_scan.php.
   Config nastaveni.scanner_config (JSON) — rozumné defaulty, funguje i bez nastavení.
   ═══════════════════════════════════════════════════════════════════ */
(function appekScannerInit() {
  var CFG = { enabled: true, hw_enabled: true, hw_min_len: 6, hw_prefix: '', hw_suffix: '',
              camera_enabled: true, default_action: 'find', beep: true };
  try {
    if (typeof api === 'function') {
      api('admin_nastaveni.php').then(function (s) {
        try {
          var raw = s && (s.scanner_config || (s.nastaveni && s.nastaveni.scanner_config));
          if (raw) { var c = (typeof raw === 'string') ? JSON.parse(raw) : raw; if (c && typeof c === 'object') Object.assign(CFG, c); }
        } catch (e) {}
        injectBtn();
      }).catch(function () { injectBtn(); });
    }
  } catch (e) {}

  function beep() {
    if (!CFG.beep) return;
    try {
      var a = new (window.AudioContext || window.webkitAudioContext)(); var o = a.createOscillator();
      o.frequency.value = 880; o.connect(a.destination); o.start();
      setTimeout(function () { try { o.stop(); a.close && a.close(); } catch (e) {} }, 90);
    } catch (e) {}
  }

  async function scanHandle(code) {
    code = String(code || '').trim();
    if (CFG.hw_prefix && code.indexOf(CFG.hw_prefix) === 0) code = code.slice(CFG.hw_prefix.length);
    if (CFG.hw_suffix && code.length >= CFG.hw_suffix.length && code.slice(-CFG.hw_suffix.length) === CFG.hw_suffix) code = code.slice(0, -CFG.hw_suffix.length);
    if (!code) return;
    var r = null;
    try { r = await api('admin_scan.php?code=' + encodeURIComponent(code)); } catch (e) {}
    var m = r && r.match;
    if (!m) { try { toast('🔍 Kód ' + code + ' nenalezen', 'warn'); } catch (e) {} return; }
    beep();
    var act = CFG.default_action || 'find';
    if (act === 'pos' && typeof window.posScanAdd === 'function') { window.posScanAdd(m); return; }
    if (act === 'sklad' && typeof window.skladScanAdd === 'function') { window.skladScanAdd(m); return; }
    if (m.type === 'vyrobek') {
      if (typeof window.editVyrobek === 'function') window.editVyrobek(m.id);
      else if (typeof navigate === 'function') navigate('vyrobky');
      try { toast('📦 ' + m.nazev, 'success'); } catch (e) {}
    } else if (m.type === 'surovina') {
      if (typeof navigate === 'function') navigate('suroviny');
      try { toast('🧪 Surovina: ' + m.nazev, 'success'); } catch (e) {}
    }
  }
  window.appekScanHandle = scanHandle;

  window.appekScanGlobal = function () {
    if (typeof appekScanner === 'undefined' || !appekScanner.open) { try { toast('Kamera skener není dostupný', 'error'); } catch (e) {} return; }
    appekScanner.open({ onScan: function (code) { scanHandle(code); } });
  };

  // Akce 'pos' — přidej naskenovaný produkt na PRÁVĚ otevřený účet u stolu (pos.js drží __posTableUcetId).
  window.posScanAdd = async function (match) {
    if (!match || match.type !== 'vyrobek') { try { toast('Pro POS naskenuj produkt (ne surovinu)', 'warn'); } catch (e) {} return; }
    var ucetId = window.__posTableUcetId;
    if (!ucetId) { try { toast('Nejdřív otevři účet u stolu', 'warn'); } catch (e) {} return; }
    var body = { ucet_id: ucetId, vyrobek_id: match.id };
    // 🆕 v3.0.328 — váha: cena nebo hmotnost zakódovaná ve váhovém čárovém kódu, nebo prompt u váženého produktu
    if (match.weight_barcode && typeof match.price === 'number') {
      body.jednotkova_cena = match.price; body.mnozstvi = 1; body.nazev = match.nazev + ' (vážené)';
    } else if (match.weight_barcode && match.weight_g) {
      body.mnozstvi = match.weight_g / 1000; body.jednotkova_cena = match.cena_bez_dph; body.nazev = match.nazev + ' (' + match.weight_g + ' g)';
    } else if (match.na_vahu) {
      var g = parseFloat(window.prompt('Hmotnost v gramech — ' + match.nazev + ':', ''));
      if (!g || g <= 0) return;
      body.mnozstvi = g / 1000; body.jednotkova_cena = match.cena_bez_dph; body.nazev = match.nazev + ' (' + g + ' g)';
    }
    try {
      await api('admin_pos.php?action=item', { method: 'POST', body: JSON.stringify(body) });
      try { toast('🍽️ + ' + (body.nazev || match.nazev), 'success'); } catch (e) {}
      if (typeof window.refreshTableModal === 'function') window.refreshTableModal(ucetId);
    } catch (e) { try { toast('Přidání na účet selhalo', 'error'); } catch (_) {} }
  };
  // Akce 'sklad' — otevři navádějící dialog „kde leží" + rychlý příjem/pozice (v3.0.332).
  window.skladScanAdd = function (match) {
    if (!match) return;
    window._skladScan = match;
    if (typeof window.skladScanDialog === 'function') { window.skladScanDialog(match); return; }
    try { if (typeof navigate === 'function') navigate('sklad'); } catch (e) {}
    try { toast('📦 ' + (match.nazev || match.ean) + ' — doplň příjem / inventuru', 'info'); } catch (e) {}
  };

  // HW čtečka = keyboard-wedge: rychlá dávka znaků (<50 ms mezi sebou) zakončená Enter.
  // 50ms práh → lidské psaní (>100ms/znak) buffer resetuje → žádná interference s psaním.
  var buf = '', lastT = 0;
  document.addEventListener('keydown', function (e) {
    if (!CFG.enabled || !CFG.hw_enabled) return;
    var now = (window.performance && performance.now) ? performance.now() : Date.now();
    if (e.key === 'Enter') {
      if (buf.length >= (CFG.hw_min_len || 6) && (now - lastT) < 50) { var code = buf; buf = ''; e.preventDefault(); e.stopPropagation(); scanHandle(code); }
      else { buf = ''; }
      return;
    }
    if (e.key && e.key.length === 1) {
      if (now - lastT > 50) buf = '';
      buf += e.key; lastT = now;
    }
  }, true);

  // 📷 kamera tlačítko do topbaru (jednou; když topbar není, tiše přeskoč — HW čtečka jede tak jako tak)
  function injectBtn() {
    if (!CFG.camera_enabled || document.getElementById('appek-scan-btn')) return;
    var bar = document.querySelector('.topbar-actions');
    if (!bar) return;
    var b = document.createElement('button');
    b.id = 'appek-scan-btn'; b.type = 'button'; b.title = 'Skenovat čárový kód (kamera)';
    b.textContent = '📷';
    b.style.cssText = 'font-size:18px;background:none;border:none;cursor:pointer;padding:4px 8px;line-height:1';
    b.onclick = function () { window.appekScanGlobal(); };
    bar.insertBefore(b, bar.firstChild);
  }
  // Uložení config z Nastavení → Skener (POST scanner_config JSON; aplikuje se i bez reloadu)
  window.ulozitSkener = async function () {
    var g = function (id) { return document.getElementById(id); };
    var cfg = {
      enabled: !!(g('sk-enabled') && g('sk-enabled').checked),
      hw_enabled: !!(g('sk-hw') && g('sk-hw').checked),
      camera_enabled: !!(g('sk-cam') && g('sk-cam').checked),
      beep: !!(g('sk-beep') && g('sk-beep').checked),
      default_action: (g('sk-action') && g('sk-action').value) || 'find',
      hw_min_len: parseInt((g('sk-minlen') && g('sk-minlen').value) || '6', 10) || 6,
      hw_prefix: (g('sk-prefix') && g('sk-prefix').value) || '',
      hw_suffix: (g('sk-suffix') && g('sk-suffix').value) || ''
    };
    // 🆕 v3.0.330 — kódování váhových kódů (prefixy + layout) → scanner_config.weight_barcode (čte ho dekodér)
    if (g('sk-wb-price') || g('sk-wb-weight')) {
      var sp = function (v) { return String(v || '').split(',').map(function (x) { return x.trim(); }).filter(Boolean); };
      cfg.weight_barcode = {
        price_prefixes: sp(g('sk-wb-price') && g('sk-wb-price').value),
        weight_prefixes: sp(g('sk-wb-weight') && g('sk-wb-weight').value),
        item_start: parseInt((g('sk-wb-istart') && g('sk-wb-istart').value) || '2', 10),
        item_len: parseInt((g('sk-wb-ilen') && g('sk-wb-ilen').value) || '5', 10),
        value_len: parseInt((g('sk-wb-vlen') && g('sk-wb-vlen').value) || '5', 10)
      };
    }
    try {
      await api('admin_nastaveni.php', { method: 'PUT', body: JSON.stringify({ scanner_config: JSON.stringify(cfg) }) });
      Object.assign(CFG, cfg);
      try { toast('💾 Skener uložen', 'success'); } catch (e) {}
    } catch (e) { try { toast('Uložení selhalo: ' + (e.message || e), 'error'); } catch (_) {} }
  };

  // 🆕 v3.0.330 — Skener: konfigurace (přesunuto z Nastavení pod Nástroje) + kódování váhových kódů.
  window.appekScannerSettings = async function () {
    var n = {};
    try { n = await api('admin_nastaveni.php'); } catch (e) {}
    var raw = n && (n.scanner_config || (n.nastaveni && n.nastaveni.scanner_config));
    var c = {}; try { c = raw ? (typeof raw === 'string' ? JSON.parse(raw) : raw) : {}; } catch (e) {}
    var s = Object.assign({ enabled: true, hw_enabled: true, camera_enabled: true, default_action: 'find', hw_min_len: 6, hw_prefix: '', hw_suffix: '', beep: true }, c);
    var wb = Object.assign({ price_prefixes: ['28'], weight_prefixes: ['29'], item_start: 2, item_len: 5, value_len: 5 }, (c.weight_barcode || {}));
    var E = (typeof esc === 'function') ? esc : function (x) { return String(x == null ? '' : x); };
    var ov = document.createElement('div');
    ov.style.cssText = 'position:fixed;inset:0;z-index:99999;background:rgba(0,0,0,.5);display:flex;align-items:flex-start;justify-content:center;padding:24px;overflow:auto';
    var ck = function (id, v, lbl) { return '<label style="display:flex;gap:8px;align-items:center;cursor:pointer;font-size:13px"><input type="checkbox" id="' + id + '"' + (v ? ' checked' : '') + '> ' + lbl + '</label>'; };
    var fld = function (id, lbl, val, type) { return '<div><div style="font-size:12px;color:#666">' + lbl + '</div><input id="' + id + '" class="form-input"' + (type ? ' type="' + type + '"' : '') + ' value="' + E(val) + '" style="width:100%"></div>'; };
    ov.innerHTML =
      '<div style="background:#fff;border-radius:14px;max-width:540px;width:100%;padding:22px;box-shadow:0 10px 40px rgba(0,0,0,.3)">' +
        '<div style="font-size:18px;font-weight:700;margin-bottom:4px">📷 Skener čárových kódů</div>' +
        '<p style="font-size:12px;color:#666;margin:0 0 14px">HW čtečka (USB/BT) i kamera. Naskenuj kód → najde produkt/surovinu a provede zvolenou akci.</p>' +
        '<div style="display:grid;grid-template-columns:1fr 1fr;gap:10px">' +
          '<div style="grid-column:1/-1">' + ck('sk-enabled', s.enabled, 'Skener zapnutý') + '</div>' +
          ck('sk-hw', s.hw_enabled, 'HW čtečka (USB/BT)') + ck('sk-cam', s.camera_enabled, 'Kamera (📷 v liště)') +
          ck('sk-beep', s.beep, 'Pípnutí při skenu') +
          '<div><div style="font-size:12px;color:#666">Akce po skenu</div><select id="sk-action" class="form-input" style="width:100%">' +
            '<option value="find"' + (s.default_action === 'find' ? ' selected' : '') + '>🔎 Najít produkt</option>' +
            '<option value="pos"' + (s.default_action === 'pos' ? ' selected' : '') + '>🍽️ Přidat na POS účet</option>' +
            '<option value="sklad"' + (s.default_action === 'sklad' ? ' selected' : '') + '>📦 Příjem skladu</option>' +
          '</select></div>' +
          fld('sk-minlen', 'Min. délka kódu', s.hw_min_len, 'number') +
          fld('sk-prefix', 'Prefix čtečky', s.hw_prefix || '') + fld('sk-suffix', 'Suffix čtečky', s.hw_suffix || '') +
        '</div>' +
        '<div style="margin-top:16px;border-top:1px solid #eee;padding-top:12px">' +
          '<div style="font-weight:600;font-size:14px;margin-bottom:2px">⚖️ Kódování váhových kódů</div>' +
          '<p style="font-size:12px;color:#666;margin:0 0 10px">Jak váha kóduje hmotnost/cenu do EAN-13. Prefixy odděl čárkou. Výchozí: 28 = cena (haléře), 29 = hmotnost (g).</p>' +
          '<div style="display:grid;grid-template-columns:1fr 1fr;gap:10px">' +
            fld('sk-wb-price', 'Prefixy CENA', (wb.price_prefixes || []).join(',')) +
            fld('sk-wb-weight', 'Prefixy HMOTNOST', (wb.weight_prefixes || []).join(',')) +
            fld('sk-wb-istart', 'PLU od pozice', wb.item_start, 'number') +
            fld('sk-wb-ilen', 'Délka PLU', wb.item_len, 'number') +
            fld('sk-wb-vlen', 'Délka hodnoty', wb.value_len, 'number') +
          '</div>' +
        '</div>' +
        '<div style="display:flex;gap:8px;justify-content:flex-end;align-items:center;margin-top:18px">' +
          '<input class="form-input" placeholder="test: kód + Enter" style="flex:1" onkeydown="if(event.key===\'Enter\'){event.stopPropagation();window.appekScanHandle&&appekScanHandle(this.value);this.value=\'\';}">' +
          '<button id="sk-cancel" class="btn-secondary">Zavřít</button>' +
          '<button id="sk-save" class="btn-primary btn-green" style="border:none;border-radius:8px;padding:10px 18px;font-weight:700;cursor:pointer">💾 Uložit</button>' +
        '</div>' +
      '</div>';
    document.body.appendChild(ov);
    ov.querySelector('#sk-cancel').onclick = function () { ov.remove(); };
    ov.onclick = function (e) { if (e.target === ov) ov.remove(); };
    ov.querySelector('#sk-save').onclick = async function () { await window.ulozitSkener(); ov.remove(); };
  };

  // Editor produktu: vygeneruj interní EAN-13 + tisk EAN štítku/ů
  window.appekGenEan = async function (vyrobekId, cb) {
    if (!vyrobekId) { try { toast('Nejdřív ulož produkt', 'warn'); } catch (e) {} return; }
    try {
      var r = await api('admin_scan.php?action=gen_ean', { method: 'POST', body: JSON.stringify({ vyrobek_id: vyrobekId }) });
      if (r && r.ean) { try { toast('🔢 EAN: ' + r.ean, 'success'); } catch (e) {} if (typeof cb === 'function') cb(r.ean); return r.ean; }
    } catch (e) { try { toast('Generování EAN selhalo', 'error'); } catch (_) {} }
  };
  window.appekPrintEanLabels = function (ids) {
    var s = Array.isArray(ids) ? ids.join(',') : String(ids || '');
    if (!s) { try { toast('Žádné produkty k tisku', 'warn'); } catch (e) {} return; }
    window.open('../api/admin_ean_labels.php?ids=' + encodeURIComponent(s) + '&autoprint=1', 'appek_ean_print', 'width=720,height=820');
  };

  setTimeout(injectBtn, 1500); setTimeout(injectBtn, 3500);
})();

/* 🆕 v3.0.329 — Expediční zásilka: dialog z detailu objednávky (přepravce + tisk štítku). */
(function () {
  window.appekShipmentDialog = async function (orderId) {
    var carriers = [], existing = [];
    try { var c = await api('admin_shipment.php?action=carriers'); carriers = (c && c.carriers) || []; } catch (e) {}
    try { var l = await api('admin_shipment.php?action=list&objednavka_id=' + orderId); existing = (l && l.zasilky) || []; } catch (e) {}
    // 🆕 v3.0.342 — předvyplň hmotnost ze součtu produktů objednávky + ukaž rozměry
    var meta = {}; try { meta = await api('admin_shipment.php?action=order_meta&objednavka_id=' + orderId) || {}; } catch (e) {}
    var defW = (meta.weight_kg && meta.weight_kg > 0) ? meta.weight_kg : 1;
    var dimHint = (meta.rozmery && (meta.rozmery.d || meta.rozmery.s || meta.rozmery.v))
      ? '<div style="font-size:11px;color:#888;margin-top:4px">📐 Max rozměr produktu: ' + [meta.rozmery.d, meta.rozmery.s, meta.rozmery.v].filter(Boolean).join(' × ') + ' cm</div>' : '';
    var ov = document.createElement('div');
    ov.style.cssText = 'position:fixed;inset:0;z-index:99999;background:rgba(0,0,0,.5);display:flex;align-items:center;justify-content:center;padding:16px';
    var opts = carriers.map(function (c) { return '<option value="' + c.key + '"' + (c.enabled ? '' : ' disabled') + '>' + c.label + (c.enabled ? '' : ' — nenastaveno') + '</option>'; }).join('');
    var exHtml = existing.length ? '<div style="margin-top:12px;font-size:13px"><b>Zásilky objednávky:</b>' + existing.map(function (z) {
      return '<div style="display:flex;justify-content:space-between;gap:8px;padding:4px 0;border-top:1px solid #eee"><span>' + z.carrier + ' · ' + (z.tracking_number || (z.chyba ? '⚠️ ' + z.chyba : z.stav)) + '</span>' + (z.tracking_number ? '<a href="../api/admin_shipment.php?action=label&id=' + z.id + '" target="_blank">🏷️ Štítek</a>' : '') + '</div>';
    }).join('') + '</div>' : '';
    ov.innerHTML =
      '<div style="background:#fff;border-radius:14px;max-width:420px;width:100%;padding:22px;box-shadow:0 10px 40px rgba(0,0,0,.3)">' +
        '<div style="font-size:17px;font-weight:700;margin-bottom:12px">📦 Vytvořit zásilku</div>' +
        '<label style="font-size:13px">Přepravce<br><select id="ship-carrier" class="form-input" style="width:100%;margin-top:4px">' + opts + '</select></label>' +
        '<div style="display:flex;gap:8px;margin-top:10px">' +
          '<label style="flex:1;font-size:13px">Hmotnost (kg)<br><input id="ship-w" class="form-input" type="number" step="0.1" value="' + defW + '" style="width:100%"></label>' +
          '<label style="flex:1;font-size:13px">Dobírka (Kč)<br><input id="ship-cod" class="form-input" type="number" value="0" style="width:100%"></label>' +
        '</div>' + dimHint +
        '<label style="font-size:13px;display:block;margin-top:10px">Výdejní místo ID (Zásilkovna)<br><input id="ship-pp" class="form-input" placeholder="volitelné" style="width:100%"></label>' +
        '<div id="ship-msg" style="margin-top:10px;font-size:13px;color:#a00"></div>' + exHtml +
        '<div style="display:flex;gap:8px;justify-content:flex-end;margin-top:16px">' +
          '<button id="ship-cancel" class="btn-secondary">Zavřít</button>' +
          '<button id="ship-go" class="btn-primary btn-green" style="border:none;border-radius:8px;padding:10px 18px;font-weight:700;cursor:pointer">Vytvořit</button>' +
        '</div>' +
      '</div>';
    document.body.appendChild(ov);
    var close = function () { ov.remove(); };
    ov.querySelector('#ship-cancel').onclick = close;
    ov.onclick = function (e) { if (e.target === ov) close(); };
    ov.querySelector('#ship-go').onclick = async function () {
      var carrier = ov.querySelector('#ship-carrier').value;
      var msg = ov.querySelector('#ship-msg');
      if (!carrier) { msg.textContent = 'Vyber přepravce'; return; }
      ov.querySelector('#ship-go').disabled = true; msg.style.color = '#666'; msg.textContent = 'Vytvářím…';
      try {
        var r = await api('admin_shipment.php?action=create', { method: 'POST', body: JSON.stringify({
          objednavka_id: orderId, carrier: carrier,
          weight_kg: parseFloat(ov.querySelector('#ship-w').value) || 1,
          cod_kc: parseFloat(ov.querySelector('#ship-cod').value) || 0,
          pickup_point_id: parseInt(ov.querySelector('#ship-pp').value) || 0
        }) });
        try { toast('📦 Zásilka vytvořena: ' + (r.tracking || ''), 'success'); } catch (e) {}
        close();
        if (r.zasilka_id) window.open('../api/admin_shipment.php?action=label&id=' + r.zasilka_id, 'appek_label', 'width=720,height=820');
      } catch (e) {
        ov.querySelector('#ship-go').disabled = false; msg.style.color = '#a00';
        msg.textContent = (e && e.message) || 'Chyba — zkontroluj klíče přepravce v Nastavení → Integrace.';
      }
    };
  };
})();
