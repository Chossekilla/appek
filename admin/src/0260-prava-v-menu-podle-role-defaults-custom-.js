// =============================================================
// 🔐 Práva v menu podle role — defaults + custom z DB
// =============================================================
const DEFAULT_ROLE_PRAVA = {
  // 🆕 v2.9.225 — přidán 'nastroje' (hub pro katalog + stitky). katalog/stitky zůstávají
  // v právech (volané z hubu přes navigate). 'rozvozy' je teď v menu (pod objednávky).
  // 🆕 v2.9.260 — přidána role 'pos' (jen POS terminál, žádný admin přístup)
  admin:    ['dashboard', 'objednavky', 'rozvozy', 'vyroba', 'dodaci_listy', 'faktury', 'vyrobky', 'nastroje', 'katalog', 'stitky', 'haccp', 'odberatele', 'nastaveni'],
  prodavac: ['dashboard', 'objednavky', 'rozvozy', 'dodaci_listy', 'faktury', 'vyrobky', 'nastroje', 'katalog', 'stitky', 'odberatele'],
  vyroba:   ['dashboard', 'vyroba', 'vyrobky', 'haccp'],
  expedice: ['dashboard', 'objednavky', 'dodaci_listy', 'rozvozy'],
  // 🆕 v2.9.309 — pos role má teď SAME jako admin. Předtím chybělo Výroba, Nastavení,
  // Nástroje, HACCP, Faktury, Katalog, Štítky. User: "chybí v menu výroba nastavení a nástroje".
  // POS-only přístup je řešený přes pos_only flag (kiosk users typu Jarmila/Evžen, kteří chodí
  // jen do /pos/ keypad screen, ne do /admin/). Prodavač 1 je teď admin role v demo seedu (v2.9.309).
  pos:      ['dashboard', 'objednavky', 'rozvozy', 'vyroba', 'dodaci_listy', 'faktury', 'vyrobky', 'nastroje', 'katalog', 'stitky', 'haccp', 'odberatele', 'nastaveni'],
};
// Stránka -> ikona + label (pro UI editor rolí). 'rozvozy' tu zůstává — admin
// si může v Údržbě roli rozšířit a tím přidat zpět do menu, pokud potřebuje.
const ALL_NAV_PAGES = [
  // 🆕 v2.9.226 — rozvozy NENÍ v menu (user: 'pryč z menu'),
  // dostupné přes Dashboard tile + Dodací listy. katalog+stitky v 'nastroje' hub.
  { key: 'dashboard',    icon: '📊', label: 'Dashboard' },
  { key: 'objednavky',   icon: '📋', label: 'Objednávky' },
  { key: 'vyroba',       icon: '🥖', label: 'Výroba' },
  { key: 'dodaci_listy', icon: '📃', label: 'Dodací listy' },
  { key: 'faktury',      icon: '💰', label: 'Faktury' },
  { key: 'vyrobky',      icon: '📦', label: 'Výrobky' },
  { key: 'nastroje',     icon: '🛠️', label: 'Nástroje' },
  { key: 'odberatele',   icon: '👥', label: 'Odběratelé' },
  { key: 'nastaveni',    icon: '⚙️', label: 'Nastavení' },
  // Skryté ale stále routovatelné stránky (z hubu / dashboard tile) — back nav + práva
  { key: 'rozvozy',      icon: '🛣️', label: 'Rozvozové trasy', hidden: true },
  { key: 'katalog',      icon: '📑', label: 'PDF nabídka',      hidden: true },
  { key: 'stitky',       icon: '🏷️', label: 'Štítky a cenovky', hidden: true },
];
state.rolePrava = DEFAULT_ROLE_PRAVA;

// Pro aktuálního uživatele zjistí, zda smí navigovat na danou stránku
function muzeNavigovat(page) {
  const role = state.admin?.role || 'admin';
  if (role === 'admin') return true;                    // super admin vše
  const allowed = state.rolePrava[role] || [];
  return allowed.includes(page);
}

// Podle role schová nav items v sidebaru
function aplikovatPravaNaMenu() {
  const role = state.admin?.role || 'admin';
  const allowed = role === 'admin'
    ? ALL_NAV_PAGES.map(p => p.key)               // admin vidí všechny
    : (state.rolePrava[role] || DEFAULT_ROLE_PRAVA[role] || []);
  document.querySelectorAll('.nav-item').forEach((b) => {
    const ok = allowed.includes(b.dataset.page);
    b.style.display = ok ? '' : 'none';
  });
}

// =============================================================
// 📱 v3.0.39 — POCKET-READY MOBILE LAYER
//   Bottom nav badge, FAB (Floating Action Button), PWA install
//   prompt, tap-to-navigate helpers, address linkifier.
// =============================================================

const APPEK_FAB_CONFIG = {
  // Per-page FAB akce — { ikona, label, onclick }
  dashboard:    { icon: '🛒', label: 'Nová objednávka', action: () => { navigate('objednavky'); setTimeout(() => { if (typeof otevritNovouObjednavku === 'function') otevritNovouObjednavku(); }, 250); } }, // navigate → pak rovnou otevři formulář (v3.0.333)
  objednavky:   { icon: '➕', label: 'Nová',           action: () => { if (typeof otevritNovouObjednavku === 'function') otevritNovouObjednavku(); } },
  dodaci_listy: { icon: '➕', label: 'Nový DL',        action: () => { if (typeof otevritRucniDl === 'function') otevritRucniDl(); } },
  faktury:      { icon: '➕', label: 'Nová FA',        action: () => { if (typeof otevritRucniFakturu === 'function') otevritRucniFakturu(); } },
  vyrobky:      { icon: '➕', label: 'Nový výrobek',   action: () => { if (typeof window.editVyrobek === 'function') window.editVyrobek(); } },
  odberatele:   { icon: '➕', label: 'Nový odběratel', action: () => { if (typeof editOdberatel === 'function') editOdberatel(0); } },
  vyroba:       { icon: '🥖', label: 'Vyrobit',         action: () => navigate('vyrobni_list') },
  restaurace:   { icon: '🍕', label: 'POS',             action: () => { location.href = 'pos.php'; } },
};

window.updateAppFAB = function(page) {
  let fab = document.getElementById('app-fab');
  // 🆕 v3.0.190 — globální vypínač mobilních rychlých tlačítek (Nastavení → Vzhled).
  if (typeof window.getAppFabEnabled === 'function' && !window.getAppFabEnabled()) {
    if (fab) fab.classList.add('is-hidden');
    return;
  }
  const cfg = APPEK_FAB_CONFIG[page];
  if (!cfg) {
    if (fab) fab.classList.add('is-hidden');
    return;
  }
  if (!fab) {
    fab = document.createElement('button');
    fab.id = 'app-fab';
    fab.className = 'app-fab has-label';
    fab.setAttribute('aria-label', 'Rychlá akce');
    document.body.appendChild(fab);
    // 🆕 v3.0.52 — Swipe-to-dismiss na FAB (user: "udělat swiper zavřít do strany")
    appekFabSwipeBind(fab);
  }
  fab.innerHTML = `<span class="fab-icon" aria-hidden="true">${cfg.icon}</span><span class="fab-label">${cfg.label}</span>`;
  fab.onclick = (ev) => { ev.preventDefault(); cfg.action(); };
  // Pokud user FAB schoval, respekt — neukazuj znovu při navigate
  try {
    const dismissedFor = localStorage.getItem('appek_fab_dismissed_page');
    if (dismissedFor === page) { fab.classList.add('is-dismissed'); return; }
  } catch (e) {}
  fab.classList.remove('is-hidden', 'is-dismissed');
  fab.style.transform = ''; fab.style.opacity = '';
};

// 🆕 v3.0.52 — Swipe FAB doprava → dismissed (na aktuální stránku, persisted)
function appekFabSwipeBind(fab) {
  if (fab.dataset.swipeBound) return;
  fab.dataset.swipeBound = '1';
  let startX = 0, startY = 0, dragging = false, currentDx = 0, axis = null;
  fab.addEventListener('touchstart', (e) => {
    if (e.touches.length > 1) return;
    startX = e.touches[0].clientX;
    startY = e.touches[0].clientY;
    dragging = true;
    axis = null;
    fab.style.transition = 'none';
  }, { passive: true });
  fab.addEventListener('touchmove', (e) => {
    if (!dragging) return;
    const dx = e.touches[0].clientX - startX;
    const dy = e.touches[0].clientY - startY;
    if (!axis) {
      if (Math.abs(dx) > 6 || Math.abs(dy) > 6) axis = Math.abs(dx) > Math.abs(dy) ? 'x' : 'y';
    }
    if (axis !== 'x') return;
    currentDx = Math.max(0, dx); // jen doprava (FAB je v pravém rohu)
    fab.style.transform = `translateX(${currentDx}px)`;
    fab.style.opacity = String(1 - Math.min(currentDx / 180, 0.8));
  }, { passive: true });
  fab.addEventListener('touchend', () => {
    if (!dragging) return;
    dragging = false;
    fab.style.transition = 'transform 0.22s cubic-bezier(.2,.8,.2,1), opacity 0.22s ease';
    if (currentDx >= 100) {
      // Dismissed
      fab.style.transform = 'translateX(220px)';
      fab.style.opacity = '0';
      try { window.haptic && window.haptic('medium'); } catch (e) {}
      try { localStorage.setItem('appek_fab_dismissed_page', state.current || 'dashboard'); } catch (e) {}
      setTimeout(() => { fab.classList.add('is-dismissed'); }, 240);
    } else {
      // Vrátit zpět
      fab.style.transform = '';
      fab.style.opacity = '';
    }
    currentDx = 0;
  });
}

// Bottom-nav notification badge (např. počet nových objednávek)
window.updateBottomNavBadges = async function() {
  if (window.matchMedia('(min-width: 769px)').matches) return; // jen mobile
  try {
    // Lehký endpoint — vrátí počty per stav (existing)
    const d = await api('admin_dashboard.php?obdobi=dnes');
    const newCount = (d?.alerts?.nove_objednavky_pocet) || (d?.dnes?.objednavek_novych) || 0;
    document.querySelectorAll('.bottom-nav-item[data-page="objednavky"] .bn-badge').forEach(b => b.remove());
    if (newCount > 0) {
      const target = document.querySelector('.bottom-nav-item[data-page="objednavky"]');
      if (target) {
        const badge = document.createElement('span');
        badge.className = 'bn-badge';
        badge.textContent = newCount > 99 ? '99+' : String(newCount);
        target.appendChild(badge);
      }
    }
  } catch (e) { /* silent */ }
};

// PWA install prompt — proaktivní banner po 30s
let _pwaDeferredPrompt = null;
window.addEventListener('beforeinstallprompt', (e) => {
  e.preventDefault();
  _pwaDeferredPrompt = e;
  setTimeout(() => {
    try {
      const dismissed = localStorage.getItem('appek_pwa_install_dismissed');
      const installed = window.matchMedia('(display-mode: standalone)').matches;
      if (dismissed || installed) return;
      window.showPwaInstallBanner && window.showPwaInstallBanner();
    } catch (e) {}
  }, 30000);
});

// 🆕 v3.0.44 — iOS Safari nemá beforeinstallprompt event → ukážeme instructions banner
//   "Tap Share → Add to Home Screen". Jen pro iPhone/iPad bez standalone mode.
(function iosInstallHint() {
  setTimeout(() => {
    try {
      const ua = navigator.userAgent || '';
      const isIOS = /iPhone|iPad|iPod/i.test(ua);
      const isStandalone = window.navigator.standalone === true
        || window.matchMedia('(display-mode: standalone)').matches;
      if (!isIOS || isStandalone) return;
      if (localStorage.getItem('appek_pwa_install_dismissed') === '1') return;
      if (document.getElementById('pwa-install-banner')) return; // already shown
      const div = document.createElement('div');
      div.id = 'pwa-install-banner';
      div.className = 'pwa-install-banner show';
      div.innerHTML = `
        <div class="pwa-install-banner-icon">📲</div>
        <div class="pwa-install-banner-text">
          <strong>Nainstaluj APPEK na plochu (iPhone)</strong>
          <span>Klepni dole <strong>Sdílet</strong> ⎙ → <strong>Přidat na plochu</strong> — rychlejší přístup + funguje offline</span>
        </div>
        <div class="pwa-install-banner-actions">
          <button class="pwa-install-no" onclick="dismissPwaInstall()">Ne teď</button>
        </div>
      `;
      document.body.appendChild(div);
    } catch (e) {}
  }, 30000);
})();

window.showPwaInstallBanner = function() {
  if (!_pwaDeferredPrompt) return;
  if (document.getElementById('pwa-install-banner')) return;
  const div = document.createElement('div');
  div.id = 'pwa-install-banner';
  div.className = 'pwa-install-banner show';
  div.innerHTML = `
    <div class="pwa-install-banner-icon">📲</div>
    <div class="pwa-install-banner-text">
      <strong>Nainstaluj APPEK na plochu</strong>
      <span>Rychlejší přístup · funguje offline · plně mobilní zážitek</span>
    </div>
    <div class="pwa-install-banner-actions">
      <button class="pwa-install-no" onclick="dismissPwaInstall()">Ne</button>
      <button class="pwa-install-yes" onclick="triggerPwaInstall()">Nainstalovat</button>
    </div>
  `;
  document.body.appendChild(div);
};

window.dismissPwaInstall = function() {
  try { localStorage.setItem('appek_pwa_install_dismissed', '1'); } catch (e) {}
  const el = document.getElementById('pwa-install-banner');
  if (el) el.remove();
};

window.triggerPwaInstall = async function() {
  if (!_pwaDeferredPrompt) return;
  _pwaDeferredPrompt.prompt();
  try {
    const { outcome } = await _pwaDeferredPrompt.userChoice;
    if (outcome === 'accepted') {
      try { localStorage.setItem('appek_pwa_install_dismissed', '1'); } catch (e) {}
    }
  } catch (e) {}
  _pwaDeferredPrompt = null;
  const el = document.getElementById('pwa-install-banner');
  if (el) el.remove();
};

// iOS detection (Safari nemá beforeinstallprompt — musí ručně přes Share menu)
window.isPwaInstalled = function() {
  return window.matchMedia('(display-mode: standalone)').matches
    || window.navigator.standalone === true;
};

// 📍 Tap-to-navigate — univerzální maps deeplink (iOS Maps, Android Google Maps, fallback web)
window.openInMaps = function(address, city = '', psc = '') {
  if (!address) return;
  const full = encodeURIComponent([address, psc, city].filter(Boolean).join(', '));
  // iOS Safari: maps:// otevře Apple Maps, Android: geo: otevře default app
  const ua = navigator.userAgent || '';
  const isIOS = /iPhone|iPad|iPod/i.test(ua);
  const isAndroid = /Android/i.test(ua);
  if (isIOS)        location.href = `maps://?q=${full}`;
  else if (isAndroid) location.href = `geo:0,0?q=${full}`;
  else                window.open(`https://www.google.com/maps/search/?api=1&query=${full}`, '_blank');
};

// 📞 Tap-to-call helper — fix formátu telefonu pro tel: link
window.makeCallLink = function(phone) {
  if (!phone) return '';
  return 'tel:' + String(phone).replace(/[^+0-9]/g, '');
};

// =============================================================
// 📱 v3.0.41 — POCKET UX UPGRADE — Pull-to-refresh, Swipe-to-action,
//   Long-press quick-sheet, Offline detection, Haptic feedback,
//   Camera barcode FAB (extend APPEK_FAB_CONFIG)
// =============================================================

// 📳 HAPTIC FEEDBACK — wrapper kolem navigator.vibrate
//   Patterns: 'light' (tap), 'medium' (akce), 'success' (potvrzení),
//   'warning' (varování), 'error' (chyba), 'heavy' (důležité)
window.haptic = function(type = 'light') {
  if (!navigator.vibrate) return; // iOS Safari nemá; bezpečně skipne
  // Respekt prefers-reduced-motion (user může mít vypnutý vibration)
  try {
    if (window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches) return;
  } catch (e) {}
  const patterns = {
    light:   10,
    medium:  20,
    heavy:   40,
    success: [40, 30, 40],
    warning: [60, 40, 60],
    error:   [80, 50, 80, 50, 80],
    tick:    [3, 10, 3], // PTR tick
  };
  try { navigator.vibrate(patterns[type] || patterns.light); } catch (e) {}
};

// 📡 OFFLINE DETECTION — banner nahoře + auto-reload pokud znovu online
let _appekOfflineBanner = null;
function appekUpdateOnlineStatus() {
  const isOffline = !navigator.onLine;
  if (isOffline && !_appekOfflineBanner) {
    _appekOfflineBanner = document.createElement('div');
    _appekOfflineBanner.id = 'appek-offline-banner';
    _appekOfflineBanner.innerHTML = `
      <span class="off-dot"></span>
      <strong>📡 Offline</strong>
      <span class="off-msg">Bez internetu — uložené data fungují, nové se synchronizují později</span>
    `;
    document.body.appendChild(_appekOfflineBanner);
    try { haptic('warning'); } catch (e) {}
  } else if (!isOffline && _appekOfflineBanner) {
    _appekOfflineBanner.classList.add('off-fade-out');
    setTimeout(() => {
      if (_appekOfflineBanner) { _appekOfflineBanner.remove(); _appekOfflineBanner = null; }
    }, 400);
    try { haptic('success'); } catch (e) {}
  }
}
window.addEventListener('online', appekUpdateOnlineStatus);
window.addEventListener('offline', appekUpdateOnlineStatus);

// 🔄 PULL-TO-REFRESH — touch handler na #content
//   Trigger jen pokud scrollTop=0 a vertical pull > 80px
let _ptrStart = null, _ptrIndicator = null, _ptrRefreshing = false;
function appekInitPullToRefresh() {
  const target = document.getElementById('content') || document.body;
  if (target.dataset.ptrBound) return;
  target.dataset.ptrBound = '1';

  // Indikátor — vytvoř jednou
  if (!_ptrIndicator) {
    _ptrIndicator = document.createElement('div');
    _ptrIndicator.className = 'ptr-indicator';
    _ptrIndicator.innerHTML = '🔄';
    document.body.appendChild(_ptrIndicator);
  }

  target.addEventListener('touchstart', (e) => {
    if (_ptrRefreshing) return;
    // Jen pokud jsme úplně nahoře a používáme prst (ne myš)
    const scrollY = window.scrollY || document.documentElement.scrollTop;
    if (scrollY > 5) return;
    _ptrStart = { y: e.touches[0].clientY, t: Date.now() };
  }, { passive: true });

  target.addEventListener('touchmove', (e) => {
    if (!_ptrStart || _ptrRefreshing) return;
    const dy = e.touches[0].clientY - _ptrStart.y;
    if (dy <= 0) { _ptrStart = null; _ptrIndicator.classList.remove('visible'); return; }
    // Vizuální feedback
    const progress = Math.min(dy / 120, 1);
    _ptrIndicator.style.transform = `translate(-50%, ${Math.min(dy * 0.5, 60)}px) rotate(${progress * 360}deg)`;
    _ptrIndicator.classList.add('visible');
    _ptrIndicator.style.opacity = String(0.4 + progress * 0.6);
    // Haptic tick na threshold
    if (dy >= 80 && !_ptrStart.threshHit) {
      _ptrStart.threshHit = true;
      try { haptic('tick'); } catch (e) {}
    }
  }, { passive: true });

  target.addEventListener('touchend', () => {
    if (!_ptrStart || _ptrRefreshing) return;
    const triggered = _ptrStart.threshHit;
    _ptrStart = null;
    if (triggered) {
      _ptrRefreshing = true;
      _ptrIndicator.classList.add('refreshing');
      _ptrIndicator.style.transform = 'translate(-50%, 0)';
      _ptrIndicator.style.opacity = '1';
      try { haptic('medium'); } catch (e) {}
      // Re-trigger aktuální stránky
      const page = state.current || 'dashboard';
      try {
        navigate(page);
      } catch (e) {}
      setTimeout(() => {
        _ptrRefreshing = false;
        _ptrIndicator.classList.remove('refreshing', 'visible');
        _ptrIndicator.style.transform = '';
        _ptrIndicator.style.opacity = '';
      }, 900);
    } else {
      _ptrIndicator.classList.remove('visible');
      _ptrIndicator.style.transform = '';
      _ptrIndicator.style.opacity = '';
    }
  });
}

// 👆 SWIPE-TO-ACTION — helper pro list items
//   Použití: appekAddSwipeActions(el, { leftLabel:'🗑️', leftAction:()=>..., rightLabel:'📃', rightAction:()=>... })
window.appekAddSwipeActions = function(el, opts = {}) {
  if (!el || el.dataset.swipeBound) return;
  el.dataset.swipeBound = '1';

  let startX = 0, startY = 0, currentX = 0, swiping = false, lockedAxis = null, threshHit = false;
  const THRESHOLD = 80; // px pro execute akce
  const MAX_PULL = 140;

  // 🆕 v3.0.44 fix — Wrap el v <div class="swipe-wrap"> (relative), underlay inset:0
  //   Předtím underlay měl absolute pozici snapshotovanou z offsetLeft/Top → desync
  //   při resize/reflow. Wrap approach = underlay vždy přesně pod elementem.
  const wrap = document.createElement('div');
  wrap.className = 'swipe-wrap';
  wrap.style.cssText = 'position:relative;border-radius:inherit;overflow:hidden';

  const underlay = document.createElement('div');
  underlay.className = 'swipe-underlay';
  underlay.style.cssText = 'position:absolute;inset:0;display:flex;justify-content:space-between;align-items:center;border-radius:inherit;overflow:hidden;z-index:0;pointer-events:none';
  if (opts.rightLabel) {
    const r = document.createElement('div');
    r.className = 'swipe-action-right';
    r.style.cssText = 'flex:0 0 auto;padding:0 24px;height:100%;display:flex;align-items:center;gap:8px;background:linear-gradient(90deg,#10B981,#059669);color:#fff;font-weight:800;font-size:14px';
    r.innerHTML = opts.rightLabel;
    underlay.appendChild(r);
  } else { underlay.appendChild(document.createElement('div')); }
  if (opts.leftLabel) {
    const l = document.createElement('div');
    l.className = 'swipe-action-left';
    l.style.cssText = 'flex:0 0 auto;padding:0 24px;height:100%;display:flex;align-items:center;gap:8px;background:linear-gradient(90deg,#DC2626,#B91C1C);color:#fff;font-weight:800;font-size:14px';
    l.innerHTML = opts.leftLabel;
    underlay.appendChild(l);
  } else { underlay.appendChild(document.createElement('div')); }

  // Vlož wrap before el, přesuň el dovnitř wrapu
  el.parentNode.insertBefore(wrap, el);
  wrap.appendChild(underlay);
  wrap.appendChild(el);
  el.style.position = 'relative';
  el.style.zIndex = '1';
  el.style.background = el.style.background || getComputedStyle(el).backgroundColor || '#fff'; // krýt underlay v default
  el.style.transition = 'transform 0.22s cubic-bezier(.2,.8,.2,1)';

  el.addEventListener('touchstart', (e) => {
    if (e.touches.length > 1) return;
    startX = e.touches[0].clientX;
    startY = e.touches[0].clientY;
    currentX = 0;
    swiping = true;
    lockedAxis = null;
    threshHit = false; // 🆕 v3.0.44 fix — reset per-touch (předtím assigned na boolean = no-op)
    el.style.transition = 'none';
  }, { passive: true });

  el.addEventListener('touchmove', (e) => {
    if (!swiping) return;
    const dx = e.touches[0].clientX - startX;
    const dy = e.touches[0].clientY - startY;
    if (!lockedAxis) {
      if (Math.abs(dx) > 8 || Math.abs(dy) > 8) {
        lockedAxis = Math.abs(dx) > Math.abs(dy) ? 'x' : 'y';
      }
    }
    if (lockedAxis !== 'x') return;
    currentX = Math.max(-MAX_PULL, Math.min(MAX_PULL, dx));
    el.style.transform = `translateX(${currentX}px)`;
    // 🆕 v3.0.44 fix — `>=` místo `===` (float dx rarely matches exactly)
    //                  + standalone threshHit var (předtím swiping.threshHit no-op na boolean)
    if (Math.abs(currentX) >= THRESHOLD && !threshHit) {
      threshHit = true;
      try { haptic('tick'); } catch (e) {}
    }
  }, { passive: true });

  el.addEventListener('touchend', () => {
    if (!swiping) return;
    swiping = false;
    el.style.transition = 'transform 0.22s cubic-bezier(.2,.8,.2,1)';
    if (currentX <= -THRESHOLD && opts.leftAction) {
      el.style.transform = `translateX(-${MAX_PULL}px)`;
      try { haptic('medium'); } catch (e) {}
      setTimeout(() => {
        try { opts.leftAction(); } catch (err) { console.warn('[swipe] leftAction err', err); }
        el.style.transform = '';
      }, 220);
    } else if (currentX >= THRESHOLD && opts.rightAction) {
      el.style.transform = `translateX(${MAX_PULL}px)`;
      try { haptic('medium'); } catch (e) {}
      setTimeout(() => {
        try { opts.rightAction(); } catch (err) { console.warn('[swipe] rightAction err', err); }
        el.style.transform = '';
      }, 220);
    } else {
      el.style.transform = '';
    }
  });
};

// 📋 LONG-PRESS QUICK ACTIONS SHEET — pro FAB
//   Per-stránka 3-5 quick actions (subset APPEK_FAB_CONFIG)
const APPEK_FAB_SHEET = {
  dashboard: [
    { icon: '🛒', label: 'Nová objednávka',  action: () => { navigate('objednavky'); setTimeout(() => { try { otevritNovouObjednavku(); } catch(e){} }, 250); } },
    { icon: '📃', label: 'Nový dodací list', action: () => { try { otevritRucniDl(); } catch(e){} } },
    { icon: '💰', label: 'Nová faktura',     action: () => { try { otevritRucniFakturu(); } catch(e){} } },
    { icon: '👥', label: 'Nový odběratel',   action: () => { navigate('odberatele'); setTimeout(() => { try { editOdberatel(0); } catch(e){} }, 250); } },
  ],
  objednavky: [
    { icon: '➕', label: 'Nová objednávka', action: () => { try { otevritNovouObjednavku(); } catch(e){} } },
    { icon: '🔍', label: 'Filtrovat',       action: () => { document.getElementById('obj-filter-text')?.focus(); } },
    { icon: '🔄', label: 'Obnovit',         action: () => navigate('objednavky') },
  ],
  vyrobky: [
    { icon: '➕', label: 'Nový výrobek',    action: () => { try { window.editVyrobek?.(); } catch(e){} } },
    { icon: '📷', label: 'Skenovat čárkód', action: () => { window.appekScanFromVyrobky?.(); } },
    { icon: '📥', label: 'Import výrobků',  action: () => { try { window.otevritImportVyrobku?.(); } catch(e){} } },
    { icon: '🔄', label: 'Obnovit',         action: () => navigate('vyrobky') },
  ],
  dodaci_listy: [
    { icon: '➕', label: 'Nový DL',         action: () => { try { otevritRucniDl(); } catch(e){} } },
    { icon: '🛣️', label: 'Rozvozové trasy', action: () => navigate('rozvozy') },
    { icon: '🔄', label: 'Obnovit',         action: () => navigate('dodaci_listy') },
  ],
  vyroba: [
    { icon: '🥖', label: 'Výrobní list',    action: () => navigate('vyrobni_list') },
    { icon: '📦', label: 'Sklad',           action: () => { try { navigate('sklad'); } catch(e) { navigate('vyroba'); } } },
    { icon: '🥚', label: 'Suroviny',        action: () => { try { navigate('suroviny'); } catch(e) { navigate('vyroba'); } } },
  ],
};

let _fabLongPressTimer = null;
let _fabSheetOpen = false;

function appekFabLongPressBind(fab) {
  if (!fab || fab.dataset.lpBound) return;
  fab.dataset.lpBound = '1';

  const startPress = (ev) => {
    if (_fabSheetOpen) return;
    if (_fabLongPressTimer) clearTimeout(_fabLongPressTimer);
    _fabLongPressTimer = setTimeout(() => {
      const page = state.current || 'dashboard';
      const sheet = APPEK_FAB_SHEET[page];
      if (!sheet || sheet.length < 2) return;
      try { haptic('medium'); } catch (e) {}
      appekShowQuickSheet(page, sheet);
      ev.preventDefault?.();
    }, 480); // 480ms hold = long press
  };

  const cancelPress = () => {
    if (_fabLongPressTimer) { clearTimeout(_fabLongPressTimer); _fabLongPressTimer = null; }
  };

  fab.addEventListener('touchstart', startPress, { passive: false });
  fab.addEventListener('touchend', cancelPress);
  fab.addEventListener('touchmove', cancelPress);
  fab.addEventListener('mousedown', startPress);
  fab.addEventListener('mouseup', cancelPress);
  fab.addEventListener('mouseleave', cancelPress);
}

window.appekShowQuickSheet = function(page, actions) {
  if (_fabSheetOpen) return;
  _fabSheetOpen = true;
  const overlay = document.createElement('div');
  overlay.id = 'appek-quick-sheet';
  overlay.innerHTML = `
    <div class="qs-backdrop" onclick="appekCloseQuickSheet()"></div>
    <div class="qs-sheet" role="dialog" aria-label="Rychlé akce">
      <div class="qs-handle"></div>
      <div class="qs-title">⚡ Rychlé akce</div>
      <div class="qs-actions">
        ${actions.map((a, i) => `
          <button class="qs-item" data-idx="${i}">
            <span class="qs-icon">${a.icon}</span>
            <span class="qs-label">${esc(a.label)}</span>
          </button>
        `).join('')}
      </div>
      <button class="qs-cancel" onclick="appekCloseQuickSheet()">Zavřít</button>
    </div>
  `;
  document.body.appendChild(overlay);
  setTimeout(() => overlay.classList.add('show'), 10);
  overlay.querySelectorAll('.qs-item').forEach(btn => {
    btn.addEventListener('click', () => {
      const idx = parseInt(btn.dataset.idx, 10);
      const act = actions[idx];
      try { haptic('light'); } catch (e) {}
      appekCloseQuickSheet();
      setTimeout(() => { try { act.action(); } catch (e) { console.warn(e); } }, 220);
    });
  });
};

window.appekCloseQuickSheet = function() {
  const overlay = document.getElementById('appek-quick-sheet');
  if (!overlay) return;
  overlay.classList.remove('show');
  setTimeout(() => { overlay.remove(); _fabSheetOpen = false; }, 220);
};

// 📷 BARCODE SCAN z FAB na Vyrobky — najde výrobek podle EAN nebo otevří nový s předvyplněným EAN
window.appekScanFromVyrobky = function() {
  if (typeof appekScanner === 'undefined' || !appekScanner.open) {
    alert('Scanner není dostupný v tomto prohlížeči');
    return;
  }
  appekScanner.open({
    types: ['ean_13', 'ean_8', 'upc_a', 'upc_e', 'code_128', 'code_39'],
    closeOnScan: true,
    onScan: async (code) => {
      try { haptic('success'); } catch (e) {}
      // 🆕 v3.0.44 — Hledáme výrobek podle EAN přes nový ?ean= endpoint
      // (Předtím broken: ?search_ean= neexistoval, openVyrobekModal taky neexistuje)
      try {
        const r = await api('admin_vyrobky.php?ean=' + encodeURIComponent(code));
        const matches = (r && r.vyrobky) || [];
        if (matches.length === 1) {
          window.editVyrobek?.(matches[0].id);
        } else if (matches.length > 1) {
          alert('🔍 Naskenováno ' + code + ' — nalezeno ' + matches.length + ' výrobků se stejným EAN.');
          if (state._vyrobky_search !== undefined) state._vyrobky_search = code;
          navigate('vyrobky');
        } else {
          // Nový výrobek — otevři prázdný a EAN doplň po načtení formuláře
          if ((await confirmDialog({ msg: '📦 EAN ' + code + ' nenalezen. Vytvořit nový výrobek?', danger: false }))) {
            window.editVyrobek?.();
            setTimeout(() => {
              const eanInput = document.querySelector('#vyr-ean, input[name="ean"], [data-field="ean"]');
              if (eanInput) { eanInput.value = code; eanInput.dispatchEvent(new Event('input', { bubbles: true })); }
            }, 350);
          }
        }
      } catch (e) {
        alert('Chyba: ' + e.message);
      }
    },
  });
};

// 🆕 v3.0.41 — Update FAB config: přidat Vyrobky scan jako sekundární akce
// (Primary akce zůstává '+ Nový výrobek', scan se nabízí přes long-press sheet)
// Také hookneme haptic do FAB tapu — viz patch updateAppFAB níže

const _appekOrigUpdateAppFAB = window.updateAppFAB;
window.updateAppFAB = function(page) {
  if (typeof _appekOrigUpdateAppFAB === 'function') _appekOrigUpdateAppFAB(page);
  const fab = document.getElementById('app-fab');
  if (!fab) return;
  // Hook haptic
  if (!fab.dataset.hapticBound) {
    fab.dataset.hapticBound = '1';
    fab.addEventListener('click', () => { try { haptic('light'); } catch (e) {} });
  }
  // Hook long-press sheet
  appekFabLongPressBind(fab);
};

// 🐛 v3.0.51 — initial: pokud je #app skryté, jsme na login → ošetřit floating elementy
(function appekInitLoginState() {
  try {
    const app = document.getElementById('app');
    if (app && getComputedStyle(app).display === 'none') {
      document.body.classList.add('is-login');
    }
  } catch (e) {}
})();

async function showApp() {
  document.getElementById('login-screen').style.display = 'none';
  document.getElementById('app').style.display = 'grid';
  // 🐛 v3.0.51 — class na body pro CSS gating floating elementů (fallback k :has())
  document.body.classList.remove('is-login');
  document.getElementById('admin-name').textContent = state.admin.jmeno;
  aktualizovatTopbarDatum();

  // Role — jen CSS gating mazacích tlačítek (badge v topbaru zrušen v2.9.54)
  const role = state.admin.role || 'admin';
  const badge = document.getElementById('admin-role-badge');
  if (badge) badge.style.display = 'none';   // role badge se v topbaru nezobrazuje
  // Pro neadmin role přidá body třídu, podle které CSS nebo JS skryje DELETE buttons
  document.body.classList.toggle('role-restricted', role !== 'admin');

  // 🎁 Načti aktivní balíčky → topbar chip + sidebar items (synchronně, aby sidebar byl hotový před prvním navigate)
  await loadActivePackages();

  // 🎨 Apply brand customization (primary color + logo z firma nastavení)
  applyBrandCustomization();

  // 💱 v3.0.283 — měna config pro fmt() (display přepočet; neblokuje boot)
  api('admin_mena.php').then(r => { window._menaCfg = r.config || null; }).catch(() => {});

  // 🔔 Start notifikační polling
  startNotifPolling();

  // 📡 License heartbeat (1× denně) — pošle vendorovi technickou statistiku.
  //     Pirate detection: pokud heartbeat vrátí status:pirate → admin uvidí banner.
  triggerLicenseHeartbeatIfDue();

  document.querySelectorAll('.nav-item').forEach((b) => {
    b.addEventListener('click', () => navigate(b.dataset.page));
  });

  // 🆕 v3.0.38 — Sidebar logo (písmeno "A" + brand text) click → dashboard
  //   User feedback: "při kliku na písmeno a logo → klik na přehled"
  const sidebarLogo = document.querySelector('.sidebar-logo');
  if (sidebarLogo && !sidebarLogo.dataset.clickBound) {
    sidebarLogo.dataset.clickBound = '1';
    // 🆕 v3.0.76 — title attr odstraněn (Safari iOS dělal preview tooltip "🏠 Domů — Přehled"
    // viditelný jako tmavý pill pod logem, user reportoval 3X). Klik na logo → dashboard stále funguje.
    sidebarLogo.addEventListener('click', (ev) => {
      // Skip pokud user kliknul na sub-element, který má vlastní handler (datum pill apod.)
      const tgt = ev.target.closest('[data-no-logo-click]');
      if (tgt) return;
      navigate('dashboard');
    });
  }

  // 🆕 v3.0.39 — Pocket-ready init: bottom nav padding + badge polling + FAB sync
  document.body.classList.add('has-bottom-nav');
  if (window.matchMedia('(max-width: 768px)').matches) {
    // Initial badge update + poll každých 60s (synchronized s notif polling)
    try { window.updateBottomNavBadges && window.updateBottomNavBadges(); } catch (e) {}
    setInterval(() => { try { window.updateBottomNavBadges && window.updateBottomNavBadges(); } catch (e) {} }, 60000);
  }
  // První render FAB pro aktuální stránku (state.current se nastaví při prvním navigate)
  setTimeout(() => { try { window.updateAppFAB && window.updateAppFAB(state.current || 'dashboard'); } catch (e) {} }, 100);

  // 🆕 v3.0.41 — Mobile UX upgrade init:
  //   1. Pull-to-refresh (jen na mobile + touch)
  //   2. Offline detection (universal)
  try { appekInitPullToRefresh(); } catch (e) { console.warn('[PTR init]', e); }
  try { appekUpdateOnlineStatus(); } catch (e) {} // initial check

  // Načti aktuální práva v menu pro role
  api('admin_role_prava.php').then((r) => {
    if (r?.prava) state.rolePrava = r.prava;
    aplikovatPravaNaMenu();
    // 🆕 v2.9.241 — default landing 'dashboard' (předtím 'vyroba').
    if (!muzeNavigovat(state.current || 'dashboard')) {
      navigate('dashboard');
    }
  }).catch(() => {
    aplikovatPravaNaMenu();
  });

  // 🆕 v2.9.45 — Deep-link podpora: ?page=X&open=N → otevři stránku + detail
  // Použito z POS (admin/pos.php → klik na účtenku → otevři detail v adminu)
  const _urlParams = new URLSearchParams(location.search);
  const _deepPage = _urlParams.get('page');
  const _deepOpen = parseInt(_urlParams.get('open') || '0', 10);
  if (_deepPage) {
    navigate(_deepPage);
    if (_deepOpen > 0) {
      setTimeout(() => {
        if (_deepPage === 'objednavky' && typeof window.openObjednavkaDetail === 'function') {
          window.openObjednavkaDetail(_deepOpen);
        } else if (_deepPage === 'faktury' && typeof window.openFakturaDetail === 'function') {
          window.openFakturaDetail(_deepOpen);
        } else if (_deepPage === 'dodaci-listy' && typeof window.openDlDetail === 'function') {
          window.openDlDetail(_deepOpen);
        }
      }, 500);
    }
  } else {
    // 🆕 v2.9.241 — default landing 'dashboard' (přehled tržeb + nedávných dokladů).
    // Předtím 'vyroba' (v2.9.189), ale user upřednostnil dashboard jako landing.
    navigate('dashboard');
  }
  // 🎯 Onboarding check — pouze pro super admina, pouze pokud fresh install
  if (isSuperAdmin()) {
    setTimeout(_checkOnboarding, 800);
  }
}

