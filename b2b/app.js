/**
 * APPEK B2B - frontend pro odběratele (POS layout)
 *
 * Architektura:
 * - Vlevo: obsah podle aktivní záložky (katalog / checkout / historie / přehled)
 * - Vpravo: přilepený "košíkový" panel jako účtenka v POSu
 * - Na záložkách Historie / Přehled se panel skryje
 * - Stav košíku v localStorage, aktualizace přes renderCart() po každé změně
 */

// 🆕 v2.0.76 — Relativní API path (../api)
// Předtím: fetch('api/login.php') z /b2b/ → /b2b/api/login.php (404!)
// Nyní:    fetch('../api/login.php')      → správně se rozřeší na /api/login.php
// Funguje univerzálně bez ohledu na to, zda je install v root nebo v subdirectory:
//   /b2b/         → ../api → /api          ✅
//   /appek/b2b/   → ../api → /appek/api    ✅
//   /sub/x/b2b/   → ../api → /sub/x/api    ✅
const API = '../api';

// =============================================================
// 🚨 ZACHYTÁVÁNÍ JS CHYB — pošle na backend pro diagnostiku
// =============================================================
(function setupErrorCapture() {
  let lastReportTs = 0;
  function reportError(payload) {
    // Throttle: max 1 chyba/sec (chrání proti loop chybám)
    const now = Date.now();
    if (now - lastReportTs < 1000) return;
    lastReportTs = now;
    try {
      fetch('api/admin_klient_chyby.php', {
        method: 'POST',
        credentials: 'include',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({
          app: 'frontend',
          ...payload,
          url: location.href,
          user_info: (state?.user?.nazev || 'anonym') + ' (id ' + (state?.user?.id || '?') + ')',
        }),
      }).catch(() => {});
    } catch (e) {}
  }
  window.addEventListener('error', (e) => {
    reportError({
      msg: e.message || 'Unknown error',
      source: e.filename || '',
      line: e.lineno || 0,
      col: e.colno || 0,
      stack: e.error?.stack || '',
    });
  });
  window.addEventListener('unhandledrejection', (e) => {
    reportError({
      msg: '[Promise] ' + (e.reason?.message || String(e.reason)),
      stack: e.reason?.stack || '',
    });
  });
})();

const state = {
  user: null,
  vyrobky: [],
  kategorie: [],
  mistaDodani: [],
  posledniObjednavky: [],
  filterKategorie: null,
  cart: JSON.parse(localStorage.getItem('cart') || '{}'),
  currentTab: 'catalog',
  checkoutData: { typ: 'jednorazova', misto_dodani_id: null },
  // Moderní featury
  search: '',                                                        // fulltextové vyhledávání
  sablony: JSON.parse(localStorage.getItem('sablony') || '[]'),      // uložené šablony objednávek
  oblibene: new Set(JSON.parse(localStorage.getItem('oblibene') || '[]')), // oblíbené výrobky (vyrobek_id)
  darkMode: localStorage.getItem('darkMode') === '1',                // dark/light theme
};

// Dark mode init (před jakýmkoli renderem)
if (state.darkMode) document.documentElement.classList.add('dark');

// =============================================================
// HELPERS
// =============================================================
function esc(s) {
  return String(s ?? '').replace(/[&<>"']/g, (c) => (
    {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]
  ));
}
function fmt(n) {
  return new Intl.NumberFormat('cs-CZ', {
    style: 'currency', currency: 'CZK',
    minimumFractionDigits: 2, maximumFractionDigits: 2,
  }).format(n);
}
function fmtDate(s) {
  if (!s) return '';
  return new Date(s).toLocaleDateString('cs-CZ', {
    day: 'numeric', month: 'numeric', year: 'numeric',
  });
}
function statusLabel(s) {
  return ({
    nova: 'Nová', potvrzena: 'Potvrzená', ve_vyrobe: 'Ve výrobě',
    pripravena: 'Připravená', expedovana: 'Expedována',
    dorucena: 'Doručena', zrusena: 'Zrušena',
  })[s] || s;
}
function saveCart() {
  localStorage.setItem('cart', JSON.stringify(state.cart));
  updateBadge();
  renderCart();
}
function cartCount() {
  return Object.values(state.cart).reduce((s, q) => s + q, 0);
}
function cartItems() {
  return Object.entries(state.cart).map(([id, q]) => {
    const v = state.vyrobky.find((x) => x.id == id);
    return v ? { ...v, mnozstvi: q } : null;
  }).filter(Boolean);
}
function cartTotals() {
  let bezDph = 0, dph = 0, usetreno = 0;
  for (const i of cartItems()) {
    const cAkt = parseFloat(i.cena_bez_dph);
    const cZakl = parseFloat(i.cena_zakladni || i.cena_bez_dph);
    const c = cAkt * i.mnozstvi;
    bezDph += c;
    dph += c * (parseFloat(i.dph) / 100);
    if (cZakl > cAkt + 0.001) {
      // Sleva po zaokrouhlení DPH se počítá z bez-DPH a přepočítává s DPH
      const usetrBez = (cZakl - cAkt) * i.mnozstvi;
      const usetrSDph = usetrBez * (1 + parseFloat(i.dph) / 100);
      usetreno += usetrSDph;
    }
  }
  return { bezDph, dph, total: bezDph + dph, usetreno };
}

// =============================================================
// 📏 JEDNOTKY — formátování + přepočty (ks / kg / g / ml / l)
// =============================================================
/**
 * Formátuje množství s jednotkou: "5 ks", "1,5 kg", "250 g"
 * Dle jednotky uplatní pravidla zaokrouhlení (ks=celé, kg=1 des. místo, g=celé)
 */
function fmtMnozstvi(qty, jed) {
  const j = (jed || 'ks').toLowerCase();
  const n = parseFloat(qty) || 0;
  if (j === 'ks' || j === 'pcs' || j === 'kus') {
    return `${Math.round(n)} ks`;
  }
  if (j === 'kg') {
    return `${n.toLocaleString('cs-CZ', { minimumFractionDigits: n % 1 === 0 ? 0 : 1, maximumFractionDigits: 3 })} kg`;
  }
  if (j === 'g' || j === 'gram') {
    return `${Math.round(n)} g`;
  }
  if (j === 'l') {
    return `${n.toLocaleString('cs-CZ', { minimumFractionDigits: n % 1 === 0 ? 0 : 1, maximumFractionDigits: 3 })} l`;
  }
  if (j === 'ml') {
    return `${Math.round(n)} ml`;
  }
  return `${n} ${jed || ''}`.trim();
}

/**
 * Vrátí hmotnost položky v gramech (pokud lze určit).
 * - jednotka 'ks' + hmotnost_g => mnozstvi × hmotnost_g
 * - jednotka 'kg' => mnozstvi × 1000
 * - jednotka 'g'  => mnozstvi
 * - jinak => 0 (nelze určit)
 */
function hmotnostPolozkyG(item) {
  const j = (item.jednotka || 'ks').toLowerCase();
  const q = parseFloat(item.mnozstvi) || 0;
  if (j === 'kg') return q * 1000;
  if (j === 'g' || j === 'gram') return q;
  if (j === 'ks' || j === 'pcs' || j === 'kus') {
    const w = parseFloat(item.hmotnost_g) || 0;
    return q * w;
  }
  return 0; // ml/l nelze prevest na gramy bez density
}

/**
 * Pretty formát celkové hmotnosti: "2,5 kg" když ≥ 1000 g, jinak "750 g"
 */
function fmtHmotnost(grams) {
  const g = parseFloat(grams) || 0;
  if (g <= 0) return '';
  if (g >= 1000) return (g / 1000).toLocaleString('cs-CZ', { minimumFractionDigits: 1, maximumFractionDigits: 2 }) + ' kg';
  return Math.round(g) + ' g';
}

/**
 * Celková hmotnost všech položek v košíku (v gramech)
 */
function cartTotalWeightG() {
  return cartItems().reduce((sum, i) => sum + hmotnostPolozkyG(i), 0);
}

// =============================================================
// ŠABLONY (uložené v localStorage)
// =============================================================
function saveSablony() {
  localStorage.setItem('sablony', JSON.stringify(state.sablony));
}
function sablonaPridat(nazev, polozky) {
  const id = Date.now().toString(36) + Math.random().toString(36).slice(2, 6);
  state.sablony.push({
    id,
    nazev: nazev.trim() || `Šablona ${state.sablony.length + 1}`,
    polozky, // { vyrobek_id: mnozstvi }
    vytvoreno: new Date().toISOString(),
  });
  saveSablony();
  return id;
}
function sablonaSmazat(id) {
  state.sablony = state.sablony.filter((s) => s.id !== id);
  saveSablony();
}
function sablonaPouzit(id, mode = 'replace') {
  const s = state.sablony.find((x) => x.id === id);
  if (!s) return;
  if (mode === 'replace') state.cart = {};
  const dostupne = new Set(state.vyrobky.map((v) => v.id));
  let pridano = 0, preskoceno = 0;
  for (const [vid, mn] of Object.entries(s.polozky)) {
    if (dostupne.has(parseInt(vid))) {
      state.cart[vid] = (state.cart[vid] || 0) + parseFloat(mn);
      pridano++;
    } else {
      preskoceno++;
    }
  }
  saveCart();
  toast(`✓ Šablona „${esc(s.nazev)}" — ${pridano} položek` + (preskoceno ? ` (${preskoceno} nedostupných přeskočeno)` : ''));
}

// =============================================================
// OBLÍBENÉ
// =============================================================
function saveOblibene() {
  localStorage.setItem('oblibene', JSON.stringify(Array.from(state.oblibene)));
}
window.toggleOblibeny = function(vid) {
  vid = parseInt(vid);
  if (state.oblibene.has(vid)) state.oblibene.delete(vid);
  else state.oblibene.add(vid);
  saveOblibene();
  if (state.currentTab === 'catalog') renderCatalog();
};

// =============================================================
// TOAST notifikace (lightweight, bez modal)
// =============================================================
function toast(msg, type = 'success') {
  // Existující toast smaž (jen jeden v ten okamžik)
  document.querySelectorAll('.app-toast').forEach((t) => t.remove());
  const t = document.createElement('div');
  t.className = 'app-toast app-toast-' + type;
  t.innerHTML = msg;
  document.body.appendChild(t);
  // Auto-remove po animaci
  requestAnimationFrame(() => t.classList.add('show'));
  setTimeout(() => {
    t.classList.remove('show');
    setTimeout(() => t.remove(), 300);
  }, 3000);
}

// =============================================================
// DARK MODE toggle
// =============================================================
window.toggleDarkMode = function() {
  state.darkMode = !state.darkMode;
  document.documentElement.classList.toggle('dark', state.darkMode);
  localStorage.setItem('darkMode', state.darkMode ? '1' : '0');
  // Re-render headeru kvůli ikoně
  renderHeaderUser();
};

// =============================================================
// 📱 POS / DOTYKOVÝ REŽIM — větší tiles, sticky košík, klik = +1
// =============================================================
window.togglePosMode = function() {
  const isOn = document.body.classList.toggle('pos-mode');
  localStorage.setItem('posMode', isOn ? '1' : '0');
  // Při zapnutí: auto-fullscreen (jen v rámci user gesture)
  if (isOn) {
    try {
      const doc = document.documentElement;
      const isFs = !!(document.fullscreenElement || document.webkitFullscreenElement || document.msFullscreenElement);
      if (!isFs) {
        if (doc.requestFullscreen) doc.requestFullscreen();
        else if (doc.webkitRequestFullscreen) doc.webkitRequestFullscreen();
        else if (doc.msRequestFullscreen) doc.msRequestFullscreen();
      }
    } catch (e) { /* fullscreen nedostupný — ok */ }
  }
  // Re-render katalogu, aby tap = +1 fungoval
  if (typeof renderCatalog === 'function' && state.currentTab === 'catalog') renderCatalog();
  if (typeof renderCart === 'function') renderCart();
};

// Init při startu — obnovit POS režim z localStorage
(function initPosMode() {
  if (localStorage.getItem('posMode') === '1') {
    document.body.classList.add('pos-mode');
  }
})();

// =============================================================
// 🖥️ FULLSCREEN — pro menší monitory v pekárně
// =============================================================
window.toggleFullscreen = function() {
  const doc = document.documentElement;
  const isFs = !!(document.fullscreenElement || document.webkitFullscreenElement || document.msFullscreenElement);
  try {
    if (!isFs) {
      if (doc.requestFullscreen) doc.requestFullscreen();
      else if (doc.webkitRequestFullscreen) doc.webkitRequestFullscreen();
      else if (doc.msRequestFullscreen) doc.msRequestFullscreen();
    } else {
      if (document.exitFullscreen) document.exitFullscreen();
      else if (document.webkitExitFullscreen) document.webkitExitFullscreen();
      else if (document.msExitFullscreen) document.msExitFullscreen();
    }
  } catch (e) {
    alert('Celá obrazovka není dostupná: ' + e.message);
  }
};
['fullscreenchange', 'webkitfullscreenchange'].forEach((ev) => {
  document.addEventListener(ev, () => {
    document.documentElement.classList.toggle('is-fullscreen',
      !!(document.fullscreenElement || document.webkitFullscreenElement));
  });
});

// =============================================================
// ↑ Scroll-to-top button — zobrazí se po překročení 400 px scrollu
// =============================================================
(function setupScrollTop() {
  let ticking = false;
  function update() {
    const btn = document.getElementById('scroll-top-btn');
    if (!btn) return;
    const y = window.scrollY || window.pageYOffset || 0;
    btn.classList.toggle('is-visible', y > 400);
    ticking = false;
  }
  window.addEventListener('scroll', () => {
    if (!ticking) {
      requestAnimationFrame(update);
      ticking = true;
    }
  }, { passive: true });
  // Init při loadu (pro případ, že už je scrollnuto)
  setTimeout(update, 100);
})();
function updateBadge() {
  const b = document.getElementById('cart-badge');
  const c = cartCount();
  if (c > 0) {
    const t = cartTotals();
    // Kompaktní formát počtu: 1234 → 1,2k aby badge nepřesahoval
    const cFmt = c >= 1000 ? (c/1000).toFixed(1).replace(/\.0$/, '').replace('.', ',') + 'k'
               : c >= 100 ? c.toString()
               : c.toString();
    // Detekce úzkého displeje — zobraz jen počet kusů, na PC + cena (taky kompaktní)
    const isMobile = window.innerWidth < 600;
    if (isMobile) {
      b.innerHTML = cFmt;
    } else {
      b.innerHTML = `${cFmt} · ${fmt(t.total)}`;
    }
    b.style.display = 'inline-block';
  } else {
    b.style.display = 'none';
  }
}

// Reaguj na resize / orientaci - aktualizuj badge
window.addEventListener('resize', () => {
  if (typeof updateBadge === 'function') updateBadge();
});

// =============================================================
// 🗑️ DELETE CONFIRMATION — volitelně 2× potvrzení (proti omylem mazání)
// Lze vypnout v admin Nastavení (sdílený localStorage klíč 'confirm_delete_2x')
// =============================================================
window.getConfirmDelete2xEnabled = function() {
  try {
    const v = localStorage.getItem('confirm_delete_2x');
    return v === null ? true : v === '1';
  } catch { return true; }
};
window.confirmDelete2x = async function(arg) {
  const co = typeof arg === 'string' ? arg : (arg?.co || 'tuto položku');
  const detail = typeof arg === 'object' ? (arg?.detail || '') : '';
  const krok1 = confirm(`Opravdu smazat ${co}?${detail ? '\n\n' + detail : ''}`);
  if (!krok1) return false;
  if (!getConfirmDelete2xEnabled()) return true;
  const krok2 = confirm(`⚠️ POSLEDNÍ POTVRZENÍ\n\nOpravdu nevratně smazat ${co}?\n\nKlikněte OK pouze pokud si jste 100% jistí. Tato akce nelze vrátit zpět.`);
  return krok2;
};

// =============================================================
// 🔔 PWA PUSH NOTIFIKACE — registrace service worker + permission flow
// =============================================================
let _pushVapidKey = null;
let _pushSwReg = null;

async function _initPushB2B() {
  if (!('serviceWorker' in navigator) || !('PushManager' in window)) return;
  try {
    _pushSwReg = await navigator.serviceWorker.register('/sw.js', { scope: '/' });
    // Načti existing subscription — pokud uživatel už souhlasil, je už zapsaný
    const sub = await _pushSwReg.pushManager.getSubscription();
    if (sub) {
      // Sync s backendem (pro případ že subscription zanikla)
      try {
        await fetch(`${API}/push.php?action=subscribe`, {
          method: 'POST',
          credentials: 'include',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify(sub.toJSON()),
        });
      } catch {}
    }
  } catch (e) { console.warn('PWA push init failed:', e); }
}

window.zapnoutPushNotifikace = async function() {
  if (!_pushSwReg) { alert('Service Worker není aktivní. Obnovte stránku a zkuste znovu.'); return; }
  if (Notification.permission === 'denied') {
    alert('Notifikace jsou zakázané v prohlížeči. Povolte je v nastavení prohlížeče.');
    return;
  }
  try {
    // Načti veřejný VAPID klíč ze serveru
    if (!_pushVapidKey) {
      const r = await fetch(`${API}/push.php?action=vapid_public`);
      if (!r.ok) throw new Error('Nelze načíst VAPID klíč — funkce není nakonfigurovaná na serveru.');
      const d = await r.json();
      _pushVapidKey = d.public_key;
    }
    // Subscribe
    const sub = await _pushSwReg.pushManager.subscribe({
      userVisibleOnly: true,
      applicationServerKey: _urlBase64ToUint8Array(_pushVapidKey),
    });
    // Pošli do backendu
    const r2 = await fetch(`${API}/push.php?action=subscribe`, {
      method: 'POST',
      credentials: 'include',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify(sub.toJSON()),
    });
    if (!r2.ok) throw new Error('Nelze uložit subscription');
    alert('✅ Hotovo! Budete dostávat upozornění na změny vašich objednávek.');
    // Schovej banner
    document.getElementById('push-prompt-banner')?.remove();
  } catch (e) { alert('Chyba: ' + e.message); }
};

window.vypnoutPushNotifikace = async function() {
  if (!_pushSwReg) return;
  const sub = await _pushSwReg.pushManager.getSubscription();
  if (!sub) return alert('Notifikace nejsou aktivované.');
  await fetch(`${API}/push.php?action=unsubscribe`, {
    method: 'POST',
    credentials: 'include',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ endpoint: sub.endpoint }),
  });
  await sub.unsubscribe();
  alert('Notifikace byly vypnuty.');
};

function _urlBase64ToUint8Array(base64) {
  const padding = '='.repeat((4 - base64.length % 4) % 4);
  const b64 = (base64 + padding).replace(/-/g, '+').replace(/_/g, '/');
  const raw = atob(b64);
  const out = new Uint8Array(raw.length);
  for (let i = 0; i < raw.length; i++) out[i] = raw.charCodeAt(i);
  return out;
}

_initPushB2B();

// 📱 PWA Install Promo — zachytí beforeinstallprompt + ukáže banner
let _deferredPwaPrompt = null;
window.addEventListener('beforeinstallprompt', (e) => {
  e.preventDefault();
  _deferredPwaPrompt = e;
  // Zobraz banner až po 5s + jen pokud uživatel ještě nezamítl
  setTimeout(_zobrazInstallBanner, 5000);
});
window.addEventListener('appinstalled', () => {
  _deferredPwaPrompt = null;
  try { localStorage.setItem('pwa_installed', '1'); } catch(e) {}
});

async function _zobrazInstallBanner() {
  if (!_deferredPwaPrompt) return;
  if (localStorage.getItem('pwa_install_dismissed') === '1') return;
  if (localStorage.getItem('pwa_installed') === '1') return;
  if (window.matchMedia('(display-mode: standalone)').matches) return; // už nainstalovaný

  const banner = document.createElement('div');
  banner.id = 'pwa-install-banner';
  banner.style.cssText = `
    position: fixed; bottom: 20px; left: 50%; transform: translateX(-50%);
    background: linear-gradient(135deg, #BA7517, #FFC966); color: #fff;
    padding: 14px 18px; border-radius: 14px;
    box-shadow: 0 10px 30px rgba(186,117,23,0.45);
    display: flex; gap: 12px; align-items: center;
    max-width: calc(100% - 32px); z-index: 9999;
    font-family: -apple-system, BlinkMacSystemFont, sans-serif;
    animation: slideUp 0.3s ease-out;
  `;
  banner.innerHTML = `
    <div style="font-size: 32px; line-height: 1">📱</div>
    <div style="flex: 1; min-width: 0">
      <strong style="display: block; font-size: 14px">Nainstaluj jako aplikaci</strong>
      <span style="font-size: 12px; opacity: 0.9">Rychlejší přístup z plochy, push notifikace.</span>
    </div>
    <button id="pwa-install-yes" style="background: #fff; color: #BA7517; border: none; padding: 8px 14px; border-radius: 8px; font-weight: 700; font-size: 13px; cursor: pointer">Instalovat</button>
    <button id="pwa-install-no" style="background: transparent; color: #fff; border: none; padding: 8px; cursor: pointer; font-size: 18px; line-height: 1">✕</button>
  `;
  document.body.appendChild(banner);

  document.getElementById('pwa-install-yes').onclick = async () => {
    if (!_deferredPwaPrompt) return;
    _deferredPwaPrompt.prompt();
    const { outcome } = await _deferredPwaPrompt.userChoice;
    if (outcome === 'accepted') {
      try { localStorage.setItem('pwa_installed', '1'); } catch(e) {}
    }
    _deferredPwaPrompt = null;
    banner.remove();
  };
  document.getElementById('pwa-install-no').onclick = () => {
    try { localStorage.setItem('pwa_install_dismissed', '1'); } catch(e) {}
    banner.remove();
  };
}

// 🔔 Banner pro zapnutí push (zobrazí se až po showApp + zpoždění)
async function _zobrazPushBannerB2B() {
  // Skryje se navždy pokud:
  // - prohlížeč push nepodporuje
  // - uživatel už dříve souhlasil (subscription existuje)
  // - uživatel už dříve zamítl (denied)
  // - uživatel ručně banner zavřel (localStorage flag)
  if (!('serviceWorker' in navigator) || !('PushManager' in window)) return;
  if (Notification.permission === 'denied') return;
  if (localStorage.getItem('push_banner_dismissed') === '1') return;
  try {
    const sub = _pushSwReg ? await _pushSwReg.pushManager.getSubscription() : null;
    if (sub) return; // už jsou aktivované
  } catch {}

  // Vytvoř banner (sticky bottom)
  if (document.getElementById('push-prompt-banner')) return;
  const b = document.createElement('div');
  b.id = 'push-prompt-banner';
  b.style.cssText = 'position:fixed;left:12px;right:12px;bottom:12px;max-width:520px;margin:0 auto;background:linear-gradient(135deg,#BA7517,#D89940);color:#fff;padding:14px 18px;border-radius:12px;box-shadow:0 6px 24px rgba(0,0,0,0.18);z-index:9998;display:flex;align-items:center;gap:14px;font-size:14px;animation:slideUpBanner 0.4s ease-out';
  b.innerHTML = `
    <div style="font-size:32px;flex-shrink:0">🔔</div>
    <div style="flex:1;min-width:0">
      <div style="font-weight:700;font-size:15px;margin-bottom:2px">Zapnout upozornění?</div>
      <div style="font-size:12px;opacity:0.95;line-height:1.4">Dostávejte zprávy o stavu vašich objednávek přímo na telefon — zdarma, bez SMS.</div>
    </div>
    <div style="display:flex;flex-direction:column;gap:6px;flex-shrink:0">
      <button onclick="zapnoutPushNotifikace()" style="background:#fff;color:#854F0B;border:none;padding:8px 14px;border-radius:8px;font-weight:700;font-size:13px;cursor:pointer;white-space:nowrap">✓ Zapnout</button>
      <button onclick="document.getElementById('push-prompt-banner').remove();localStorage.setItem('push_banner_dismissed','1')" style="background:rgba(255,255,255,0.2);color:#fff;border:none;padding:5px 14px;border-radius:8px;font-size:11px;cursor:pointer">Ne, díky</button>
    </div>
  `;
  document.body.appendChild(b);
  // Inject animace pokud chybí
  if (!document.getElementById('_push_banner_anim')) {
    const s = document.createElement('style');
    s.id = '_push_banner_anim';
    s.textContent = '@keyframes slideUpBanner { from { transform: translateY(100%); opacity: 0; } to { transform: translateY(0); opacity: 1; } }';
    document.head.appendChild(s);
  }
}

// =============================================================
// 🖼️ Branding — favicon + logo z admin nastaveni
// =============================================================
async function _initBrandingB2B() {
  try {
    const r = await fetch(`${API}/firma_branding.php`, { credentials: 'include' });
    if (!r.ok) return;
    const d = await r.json();
    if (d.favicon_url) {
      document.querySelectorAll('link[rel="icon"], link[rel="shortcut icon"]').forEach(l => l.remove());
      const link = document.createElement('link');
      link.rel = 'icon'; link.type = 'image/png'; link.href = d.favicon_url;
      document.head.appendChild(link);
    }
    if (d.logo_url) {
      document.querySelectorAll('.logo-icon, .login-logo-icon').forEach(el => {
        el.innerHTML = `<img src="${d.logo_url}" style="width:100%;height:100%;object-fit:contain;border-radius:inherit" alt="Logo">`;
        el.style.padding = '4px';
        el.style.background = '#fff';
      });
    }
  } catch {}
}
_initBrandingB2B();

async function api(path, opts = {}) {
  const res = await fetch(`${API}/${path}`, {
    credentials: 'include',
    headers: { 'Content-Type': 'application/json' },
    ...opts,
  });
  const data = await res.json().catch(() => ({}));
  if (!res.ok) throw new Error(data.error || 'Chyba serveru');
  return data;
}

// =============================================================
// LOGIN
// =============================================================
document.getElementById('login-form').addEventListener('submit', async (e) => {
  e.preventDefault();
  const fd = new FormData(e.target);
  const err = document.getElementById('login-error');
  err.style.display = 'none';
  try {
    const data = await api('login.php', {
      method: 'POST',
      body: JSON.stringify({ email: fd.get('email'), heslo: fd.get('heslo') }),
    });
    state.user = data.odberatel;
    showApp();
  } catch (e) {
    err.textContent = e.message;
    err.style.display = 'block';
  }
});

document.getElementById('logout-btn').addEventListener('click', async () => {
  try { await api('logout.php', { method: 'POST' }); } catch (e) {}
  localStorage.removeItem('cart');
  location.reload();
});

// Pre-select current language in switcher (po DOMContentLoaded)
setTimeout(() => {
  const code = window.b2bCurrentLang || 'cs';
  document.querySelectorAll('.b2b-lang-pill').forEach(b => {
    b.classList.toggle('is-active', b.dataset.lang === code);
  });
  const sw = document.getElementById('b2b-lang-switch');
  if (sw) sw.value = code;
}, 50);

async function showApp() {
  document.getElementById('login-screen').style.display = 'none';
  document.getElementById('app').style.display = 'block';
  // Footer rok
  const yEl = document.getElementById('app-footer-year');
  if (yEl) yEl.textContent = new Date().getFullYear();
  // Footer kontakt z nastavení firmy (telefon, email, adresa)
  try {
    const f = await api('firma_branding.php');
    const cEl = document.getElementById('app-footer-contact');
    if (cEl && f) {
      const tel  = f.firma_telefon ? `<a href="tel:${esc(f.firma_telefon).replace(/\s+/g,'')}">📞 ${esc(f.firma_telefon)}</a>` : '';
      const mail = f.firma_email   ? `<a href="mailto:${esc(f.firma_email)}">✉️ ${esc(f.firma_email)}</a>` : '';
      const adr  = (f.firma_ulice || f.firma_mesto) ? `<span>📍 ${esc([f.firma_ulice, f.firma_mesto].filter(Boolean).join(', '))}</span>` : '';
      cEl.innerHTML = tel + mail + adr;
    }
    // Brand v patičce
    const brandEl = document.querySelector('.app-footer-brand strong');
    if (brandEl && f && f.firma_nazev) brandEl.textContent = f.firma_nazev;
  } catch (e) { /* tichá chyba — footer prostě zůstane prázdný */ }
  await loadCatalog();
  await loadMista();
  // Načti posledních 3 objednávek pro "Znovu objednat" v katalogu
  await loadPosledniObjednavky();
  // Hlavička: jméno odběratele + výchozí pobočka (pokud má více poboček)
  renderHeaderUser();
  // 🔔 PWA Push banner — zobrazit za 3 sekundy (po načtení katalogu)
  setTimeout(_zobrazPushBannerB2B, 3000);

  // Pokud má rozpracovaný košík, skoč rovnou do košíku
  if (cartCount() > 0) {
    switchTab('checkout');
  } else {
    switchTab('catalog');
  }
  updateBadge();
}

async function loadPosledniObjednavky() {
  try {
    state.posledniObjednavky = await api('objednavky.php?last=3');
  } catch (e) {
    console.warn('Nepodařilo se načíst poslední objednávky:', e);
    state.posledniObjednavky = [];
  }
}

function renderHeaderUser() {
  const el = document.getElementById('user-name');
  if (!el) return;
  const odbName = state.user?.nazev || '';
  const initial = (odbName.trim()[0] || 'F').toUpperCase();
  let sub = '';
  if (state.mistaDodani && state.mistaDodani.length > 0) {
    const aktualni = state.mistaDodani.find(m => m.id == state.checkoutData.misto_dodani_id) || state.mistaDodani[0];
    if (aktualni) {
      sub = '📍 ' + [aktualni.nazev, aktualni.mesto].filter(Boolean).join(', ');
    }
  }
  el.innerHTML = `<span class="b2b-vizitka-ava">${esc(initial)}</span>`
    + `<span class="b2b-vizitka-info"><strong>${esc(odbName)}</strong>`
    + (sub ? `<span class="b2b-vizitka-sub">${esc(sub)}</span>` : '')
    + `</span>`;
}

async function loadCatalog() {
  try {
    const data = await api('katalog.php');
    state.vyrobky = data.vyrobky;
    state.kategorie = data.kategorie;
  } catch (e) { console.error(e); }
}

async function loadMista() {
  try {
    state.mistaDodani = await api('mista_dodani.php');
    const vychozi = state.mistaDodani.find((m) => m.vychozi == 1) || state.mistaDodani[0];
    if (vychozi) state.checkoutData.misto_dodani_id = vychozi.id;
  } catch (e) { console.error(e); }
}

// =============================================================
// TABS
// =============================================================
document.querySelectorAll('.tab').forEach((t) => {
  t.addEventListener('click', () => switchTab(t.dataset.tab));
});

function switchTab(tab) {
  state.currentTab = tab;
  document.querySelectorAll('.tab').forEach((t) =>
    t.classList.toggle('active', t.dataset.tab === tab)
  );

  // Třída na body pro CSS targeting (sticky mini-bar dole)
  document.body.classList.remove('tab-catalog', 'tab-checkout', 'tab-history', 'tab-stats');
  document.body.classList.add('tab-' + tab);

  // Vykresli obsah vlevo
  if (tab === 'catalog') renderCatalog();
  else if (tab === 'checkout') renderCheckout();
  else if (tab === 'history') renderHistory();
  else if (tab === 'stats') renderStats();

  // Vykresli košík vpravo (nebo skryj layout)
  renderCart();
}

// =============================================================
// CART PANEL (vpravo) — vykreslí se při každé změně
// =============================================================
function renderCart() {
  const layout = document.querySelector('.layout');
  const panel = document.getElementById('cart-panel');
  if (!layout || !panel) return;

  // Na záložkách Historie / Přehled košík nezobrazuj — layout = jeden sloupec
  if (state.currentTab === 'history' || state.currentTab === 'stats' || state.currentTab === 'checkout') {
    layout.classList.add('no-cart');
    return;
  }
  layout.classList.remove('no-cart');

  const items = cartItems();
  const t = cartTotals();

  if (items.length === 0) {
    panel.innerHTML = `
      <div class="cart-head">
        <h3>🧾 Košík</h3>
      </div>
      <div class="cart-empty">
        Košík je prázdný.<br>
        <span style="font-size:12px">Přidávejte výrobky vlevo.</span>
      </div>
    `;
    return;
  }

  panel.innerHTML = `
    <div class="cart-head">
      <h3>🧾 Košík <span style="color:var(--text-3); font-weight:500; font-size:13px">(${cartCount()} ks)</span></h3>
      <button class="cart-clear" onclick="clearCart()">Vyprázdnit</button>
    </div>
    <div class="cart-list">
      ${items.map((i) => {
        const cZakl = parseFloat(i.cena_zakladni || i.cena_bez_dph);
        const cAkt = parseFloat(i.cena_bez_dph);
        const maSlevu = cZakl > cAkt + 0.001;
        const usetrenoNaPolozce = maSlevu ? (cZakl - cAkt) * i.mnozstvi * (1 + parseFloat(i.dph) / 100) : 0;
        return `
        <div class="cart-item">
          <button class="cart-item-del" onclick="removeFromCart(${i.id})" title="Odebrat „${esc(i.nazev)}" z košíku" aria-label="Odebrat z košíku">
            <svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
              <path d="M3 6h18"></path>
              <path d="M8 6V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"></path>
              <path d="M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6"></path>
              <line x1="10" y1="11" x2="10" y2="17"></line>
              <line x1="14" y1="11" x2="14" y2="17"></line>
            </svg>
          </button>
          <div class="cart-item-name">${esc(i.nazev)}</div>
          <div class="cart-item-price">${fmt(cAkt * i.mnozstvi)}</div>
          <div class="cart-item-meta">
            <span class="cart-item-unit">
              ${maSlevu ? `<s style="color:var(--text-3)">${fmt(cZakl)}</s> ` : ''}<strong>${fmt(cAkt)}</strong>/${esc(i.jednotka || 'ks')}${i.hmotnost_g ? ' · ' + i.hmotnost_g + ' g/ks' : ''}
              ${hmotnostPolozkyG(i) > 0 ? `<span class="cart-item-weight" title="Celková hmotnost položky">⚖️ ${fmtHmotnost(hmotnostPolozkyG(i))}</span>` : ''}
            </span>
            <div class="qty-stack">
              <div class="qty">
                <button class="qty-btn" onclick="changeQty(${i.id}, -1)" title="−1 ${esc(i.jednotka || 'ks')}">−</button>
                <span class="qty-val" title="${fmtMnozstvi(i.mnozstvi, i.jednotka)}">${i.mnozstvi}<span class="qty-val-unit"> ${esc(i.jednotka || 'ks')}</span></span>
                <button class="qty-btn" onclick="changeQty(${i.id}, 1)" title="+1 ${esc(i.jednotka || 'ks')}">+</button>
              </div>
              <div class="qty-chips">
                <button class="qty-chip-min" onclick="changeQty(${i.id}, -10)" title="−10 ${esc(i.jednotka || 'ks')}">−10</button>
                <button class="qty-chip-plus" onclick="changeQty(${i.id}, 10)" title="+10 ${esc(i.jednotka || 'ks')}">+10</button>
              </div>
            </div>
          </div>
          ${maSlevu ? `<div class="cart-item-saved">💰 Rabat ${fmt(usetrenoNaPolozce)}</div>` : ''}
        </div>
      `;
      }).join('')}
    </div>
    <div class="cart-summary">
      ${t.usetreno > 0.005 ? `
        <div class="cart-summary-row cart-summary-saved">
          <span>💰 Celková sleva</span>
          <span>− ${fmt(t.usetreno)}</span>
        </div>
      ` : ''}
      <div class="cart-summary-row"><span>Bez DPH</span><span>${fmt(t.bezDph)}</span></div>
      <div class="cart-summary-row"><span>DPH</span><span>${fmt(t.dph)}</span></div>
      ${cartTotalWeightG() > 0 ? `
        <div class="cart-summary-row cart-summary-weight" title="Součet hmotností všech položek (ks × hmotnost na kus + kg/g položky)">
          <span>⚖️ Hmotnost</span><span>${fmtHmotnost(cartTotalWeightG())}</span>
        </div>
      ` : ''}
      <div class="cart-summary-row total"><span>Celkem</span><span>${fmt(t.total)}</span></div>
      ${state.currentTab === 'catalog' ? `
        <button class="btn-primary cart-cta cart-cta-green" onclick="switchTab('checkout')">
          Souhrn objednávky →
        </button>
      ` : ''}
    </div>
  `;
}

window.clearCart = function() {
  if (!confirm('Vyprázdnit košík?')) return;
  state.cart = {};
  saveCart();
  if (state.currentTab === 'catalog') renderCatalog();
  if (state.currentTab === 'checkout') renderCheckout();
};

window.removeFromCart = function(id) {
  delete state.cart[id];
  saveCart();
  if (state.currentTab === 'catalog') renderCatalog();
  if (state.currentTab === 'checkout') renderCheckout();
};

window.setQty = function(id, val) {
  const n = parseInt(val, 10);
  if (isNaN(n) || n <= 0) {
    delete state.cart[id];
  } else {
    state.cart[id] = n;
  }
  saveCart();
  if (state.currentTab === 'catalog') renderCatalog();
  if (state.currentTab === 'checkout') renderCheckout();
};

// =============================================================
// KATALOG (vlevo)
// =============================================================
function renderCatalog() {
  const c = document.getElementById('content');

  let vyrobky = state.vyrobky;

  // Filter: oblíbené (speciální virtuální kategorie 'oblibene')
  if (state.filterKategorie === 'oblibene') {
    vyrobky = vyrobky.filter((v) => state.oblibene.has(parseInt(v.id)));
  } else if (state.filterKategorie) {
    vyrobky = vyrobky.filter((v) => v.kategorie_id == state.filterKategorie);
  }

  // Filter: search query (case-insensitive, název + kategorie + obsah)
  if (state.search && state.search.trim().length > 0) {
    const q = state.search.trim().toLowerCase();
    vyrobky = vyrobky.filter((v) => {
      const haystack = [
        v.nazev, v.kategorie_nazev, v.cislo,
        v.obsah, v.obsah_jednotka,
      ].filter(Boolean).join(' ').toLowerCase();
      return haystack.includes(q);
    });
  }

  // "Vaše poslední objednávky" — zobraz vždy (i s košíkem) pokud existují
  // Ne při aktivním filtru kategorií nebo searchi
  const showPosledni = state.posledniObjednavky
    && state.posledniObjednavky.length > 0
    && !state.filterKategorie
    && !state.search;

  // Šablony banner — pokud má uživatel aspoň 1 šablonu (zobrazí se i s aktivním filterem,
  //                  aby byly košíky vždy snadno dostupné nahoře v katalogu)
  const showSablony = state.sablony.length > 0;

  // Quick Reorder — největší tlačítko, hned vidí
  const posledni = state.posledniObjednavky?.[0];
  const showQuickReorder = posledni && !state.filterKategorie && !state.search;

  c.innerHTML = `
    ${showSablony ? `
      <div class="sablony-bar">
        <div class="sablony-head">
          <h3 class="sablony-title">📌 Vaše uložené košíky <span class="sablony-count-badge">${state.sablony.length}</span></h3>
          <span class="sablony-hint" title="Šablony jsou uložené lokálně ve vašem prohlížeči — vidíte je jen v tomto zařízení">
            💾 Lokálně · klikni pro načtení do košíku
          </span>
        </div>
        <div class="sablony-list">
          ${state.sablony.map((s) => `
            <div class="sablona-chip">
              <button class="sablona-use" onclick="sablonaPouzitConfirm('${esc(s.id)}')" title="Načíst do košíku">
                <strong>${esc(s.nazev)}</strong>
                <span class="sablona-count">${Object.keys(s.polozky).length} pol.</span>
              </button>
              <button class="sablona-del" onclick="sablonaSmazatConfirm('${esc(s.id)}', '${esc(s.nazev)}')" title="Smazat šablonu">✕</button>
            </div>
          `).join('')}
        </div>
      </div>
    ` : ''}

    ${showQuickReorder ? `
      <div class="quick-reorder-bar">
        <div class="quick-reorder-info">
          <div class="quick-reorder-icon">🔁</div>
          <div>
            <div class="quick-reorder-title">Zopakovat poslední objednávku</div>
            <div class="quick-reorder-meta">
              ${esc(posledni.cislo)} · ${posledni.polozky?.length || 0} položek · ${fmt(parseFloat(posledni.castka_celkem || 0))}
            </div>
          </div>
        </div>
        <button class="quick-reorder-btn" onclick="reorderObjednavku(${posledni.id})">
          🔁 Přidat do košíku
        </button>
      </div>
    ` : ''}

    ${showPosledni ? `
      <div class="last-orders">
        <div class="last-orders-head">
          <h2 class="last-orders-title">📋 Vaše poslední objednávky</h2>
          <button onclick="switchTab('history')" class="last-orders-more">Vše →</button>
        </div>
        <div class="last-orders-grid">
          ${state.posledniObjednavky.map(renderOrderMiniCard).join('')}
        </div>
      </div>
    ` : ''}

    <h1 class="section-title">Katalog výrobků</h1>

    <!-- 🔍 Vyhledávání -->
    <div class="catalog-search">
      <input type="search" id="catalog-search-input"
             class="catalog-search-input" placeholder="🔍 Hledat výrobek (název, kategorie, kód)…"
             value="${esc(state.search)}"
             oninput="onSearchInput(this.value)">
      ${state.search ? `<button class="catalog-search-clear" onclick="onSearchClear()" title="Vymazat">×</button>` : ''}
    </div>

    ${cartCount() > 0 ? `
      <div class="cart-top-bar">
        <div class="cart-top-info">
          <span class="cart-top-icon">🛒</span>
          <span><strong>${cartCount()}</strong> ks</span>
          <span class="cart-top-sep">·</span>
          <span class="cart-top-total">${fmt(cartTotals().total)}</span>
        </div>
        <div class="cart-top-actions">
          <button class="cart-top-btn-clear" onclick="clearCart()" title="Vyprázdnit">🗑️</button>
          <button class="cart-top-btn cart-top-btn-green" onclick="switchTab('checkout')">Souhrn objednávky →</button>
        </div>
      </div>
    ` : ''}

    <div class="categories-wrap">
      <div class="categories">
        <button class="cat ${!state.filterKategorie ? 'active' : ''}" onclick="setKategorie(null)">Vše</button>
        ${state.oblibene.size > 0 ? `
          <button class="cat cat-oblibene ${state.filterKategorie === 'oblibene' ? 'active' : ''}" onclick="setKategorie('oblibene')">
            <span class="cat-icon">⭐</span>
            Oblíbené (${state.oblibene.size})
          </button>
        ` : ''}
        ${state.kategorie.map((k) => `
          <button class="cat ${state.filterKategorie == k.id ? 'active' : ''}" onclick="setKategorie(${k.id})">
            ${k.obrazek_url
              ? `<img class="cat-img" src="${esc(k.obrazek_url)}" alt="">`
              : `<span class="cat-icon">${k.ikona}</span>`}
            ${esc(k.nazev)}
          </button>
        `).join('')}
      </div>
    </div>

    ${vyrobky.length === 0 ? `
      <div class="empty">
        ${state.search
          ? `Nenašli jsme žádný výrobek odpovídající „<strong>${esc(state.search)}</strong>"`
          : (state.filterKategorie === 'oblibene'
              ? 'Zatím nemáš žádné oblíbené výrobky. Klikni na ⭐ u libovolného výrobku.'
              : 'V této kategorii zatím nejsou žádné výrobky.')}
      </div>
    ` : `
      ${renderCatalogGrid(vyrobky)}
    `}
  `;
}

/**
 * Vykreslí grid produktů — pokud není aktivní filter/search,
 * seskupí podle kategorie s nadpisy. Jinak plochý seznam.
 */
function renderCatalogGrid(vyrobky) {
  const isWide = window.innerWidth >= 600;
  const gridCols = isWide ? 'repeat(auto-fill, minmax(220px, 1fr))' : '1fr 1fr';
  const gridGap = isWide ? '14px' : '8px';
  const gridStyle = `display:grid;grid-template-columns:${gridCols};gap:${gridGap};`;

  // Pokud je filter / search aktivní → plochý seznam (uživatel chce vidět výsledky)
  if (state.filterKategorie || (state.search && state.search.trim())) {
    return `<div class="grid" style="${gridStyle}">${vyrobky.map(renderCard).join('')}</div>`;
  }

  // Bez filtru → grupovat podle kategorie, s hezkými oddělovači
  const skupiny = new Map();
  vyrobky.forEach(v => {
    const kid = parseInt(v.kategorie_id || 0);
    if (!skupiny.has(kid)) skupiny.set(kid, []);
    skupiny.get(kid).push(v);
  });

  // Pořadí kategorií podle state.kategorie (zachová admin-defined řazení)
  const kategorieOrder = (state.kategorie || []).map(k => parseInt(k.id));
  // Doplň "Bez kategorie" (id 0) pokud existuje
  if (skupiny.has(0) && !kategorieOrder.includes(0)) kategorieOrder.push(0);

  return kategorieOrder
    .filter(kid => skupiny.has(kid) && skupiny.get(kid).length > 0)
    .map(kid => {
      const items = skupiny.get(kid);
      const kat = (state.kategorie || []).find(k => parseInt(k.id) === kid);
      const ikona = kat?.ikona || (kid === 0 ? '📦' : '🥖');
      const nazev = kat?.nazev || (kid === 0 ? 'Bez kategorie' : 'Kategorie');
      const accent = (kid * 47) % 360;
      return `
        <section class="catalog-section" style="--sec-hue:${accent}">
          <h2 class="catalog-section-head">
            <span class="catalog-section-icon">${esc(ikona)}</span>
            <span class="catalog-section-name">${esc(nazev)}</span>
            <span class="catalog-section-count">${items.length}</span>
          </h2>
          <div class="grid" style="${gridStyle}">
            ${items.map(renderCard).join('')}
          </div>
        </section>
      `;
    }).join('');
}

// Search input handler (debounced)
let _searchTimer = null;
window.onSearchInput = function(val) {
  state.search = val;
  if (_searchTimer) clearTimeout(_searchTimer);
  _searchTimer = setTimeout(() => {
    renderCatalog();
    // Vrat focus do inputu po re-renderu
    const inp = document.getElementById('catalog-search-input');
    if (inp) {
      inp.focus();
      // Move cursor na konec
      const len = inp.value.length;
      inp.setSelectionRange(len, len);
    }
  }, 200);
};
window.onSearchClear = function() {
  state.search = '';
  renderCatalog();
};

// Šablona — confirm dialog před náhradou košíku
window.sablonaPouzitConfirm = function(id) {
  const s = state.sablony.find((x) => x.id === id);
  if (!s) return;
  if (cartCount() > 0) {
    const mode = confirm(
      `Načíst šablonu „${s.nazev}" (${Object.keys(s.polozky).length} pol.)?\n\n` +
      `Košík obsahuje ${cartCount()} ks.\n\n` +
      `OK = NAHRADIT obsah košíku šablonou\n` +
      `Cancel = PŘIDAT šablonu k aktuálnímu košíku`
    );
    sablonaPouzit(id, mode ? 'replace' : 'append');
  } else {
    sablonaPouzit(id, 'replace');
  }
};

window.sablonaSmazatConfirm = async function(id, nazev) {
  if (!await confirmDelete2x(`uložený košík „${nazev}"`)) return;
  sablonaSmazat(id);
  renderCatalog();
};

// Uložit aktuální košík jako šablonu (volá se z checkout)
window.sablonaUlozitZKosiku = function() {
  if (cartCount() === 0) return alert('Košík je prázdný.');
  const defaultNazev = `Šablona ${new Date().toLocaleDateString('cs-CZ')}`;
  const nazev = prompt('Pojmenuj šablonu (např. „Pondělní set", „Snídaňový bufet"):', defaultNazev);
  if (nazev === null) return;
  // Snapshot aktuálního obsahu košíku
  const polozky = { ...state.cart };
  sablonaPridat(nazev, polozky);
  toast(`✓ Šablona „${esc(nazev)}" uložena (${Object.keys(polozky).length} pol.)`);
};

window.reorderObjednavku = async function(id) {
  let obj = state.posledniObjednavky?.find(o => o.id == id);
  if (!obj || !obj.polozky) {
    // Fallback: dohledat z API
    try { obj = await api(`objednavky.php?id=${id}`); }
    catch (e) { return alert('Nepodařilo se načíst objednávku.'); }
  }
  if (!obj || !obj.polozky || obj.polozky.length === 0) return alert('Objednávka neobsahuje položky.');

  // Filtrovat jen výrobky, které jsou stále v katalogu (aktivní)
  const dostupneIds = new Set(state.vyrobky.map(v => v.id));
  let nedostupne = 0;

  obj.polozky.forEach((p) => {
    if (dostupneIds.has(p.vyrobek_id)) {
      // Přičti množství k aktuálnímu košíku (pokud už něco bylo)
      state.cart[p.vyrobek_id] = (state.cart[p.vyrobek_id] || 0) + parseFloat(p.mnozstvi);
    } else {
      nedostupne++;
    }
  });

  saveCart();
  updateBadge();

  if (nedostupne > 0) {
    alert(`Načteno ${obj.polozky.length - nedostupne} položek.\n${nedostupne} již nedostupný v katalogu (přeskočeno).`);
  }

  // Skoč rovnou do košíku
  switchTab('checkout');
};

// Modal: Zobrazit položky objednávky + akce (znovu, DL, FA)
window.zobrazPolozkiObjednavky = async function(id) {
  // Hledej v posledních nebo načti z API
  let obj = state.posledniObjednavky?.find(o => o.id == id);
  if (!obj) {
    try {
      obj = await api(`objednavky.php?id=${id}`);
    } catch(e) {
      return alert('Nepodařilo se načíst objednávku.');
    }
  }
  if (!obj || !obj.polozky || obj.polozky.length === 0) {
    return alert('Objednávka neobsahuje žádné položky.');
  }

  const maDL = obj.pocet_dl > 0 && obj.prvni_dl_id;
  const maFA = obj.pocet_faktur > 0 && obj.prvni_faktura_id;

  // Spočítej celkovou cenu
  let celkem = parseFloat(obj.castka_celkem || 0);

  openModal(`
    <div class="modal-head">
      <h2>📦 Položky objednávky ${esc(obj.cislo)}</h2>
      <button class="modal-close" onclick="closeModal()"></button>
    </div>
    <div class="modal-meta" style="margin-bottom:12px;color:var(--text-2);font-size:13px;">
      📅 ${fmtDate(obj.datum_dodani)} · ${obj.pocet_polozek || obj.polozky.length} položek · <strong>${fmt(celkem)}</strong>
      ${obj.stav ? ` · <span class="status ${esc(obj.stav)}">${statusLabel(obj.stav)}</span>` : ''}
    </div>
    <table class="modal-items-table">
      <thead>
        <tr><th style="text-align:left">Výrobek</th><th style="text-align:center;width:50px">Ks</th><th style="text-align:right">Cena</th></tr>
      </thead>
      <tbody>
        ${obj.polozky.map(p => {
          const nazev = p.vyrobek_nazev || p.nazev || 'Výrobek';
          const mn = parseFloat(p.mnozstvi) || 0;
          // Fallback: pokud backend nevrací cena_celkem, spočti z bez DPH × množství + DPH
          let cena = parseFloat(p.cena_celkem || 0);
          if (!cena) {
            const cBez = parseFloat(p.cena_bez_dph || 0);
            const dph = parseFloat(p.sazba_dph || 0);
            cena = cBez * mn * (1 + dph / 100);
          }
          return `<tr>
            <td>${esc(nazev)}</td>
            <td style="text-align:center;font-weight:700;color:var(--primary-dark)">${mn}×</td>
            <td style="text-align:right">${fmt(cena)}</td>
          </tr>`;
        }).join('')}
      </tbody>
    </table>
    <div style="border-top:2px solid var(--primary);margin-top:8px;padding-top:10px;display:flex;justify-content:space-between;font-weight:700;font-size:15px;">
      <span>Celkem</span>
      <span>${fmt(celkem)}</span>
    </div>
    <div class="modal-polozky-actions">
      <button class="oc-btn oc-btn-primary" onclick="reorderObjednavku(${obj.id}); closeModal();" style="flex:2">
        🔁 Znovu objednat
      </button>
      ${maDL ? `
        <a class="oc-btn oc-btn-ghost" href="api/dodaci_list.php?id=${obj.id}" target="_blank">
          📃 DL
        </a>
      ` : `
        <span class="oc-btn oc-btn-ghost oc-btn-disabled" title="DL zatím neexistuje">📃 DL</span>
      `}
      ${maFA ? `
        <a class="oc-btn oc-btn-ghost" href="api/faktura.php?id=${obj.prvni_faktura_id}" target="_blank">
          💰 FA
        </a>
      ` : `
        <span class="oc-btn oc-btn-ghost oc-btn-disabled" title="FA zatím neexistuje">💰 FA</span>
      `}
    </div>
  `);
};

// Mini karta objednávky pro Katalog (kompaktní verze renderOrderCard, 3 vedle sebe)
function renderOrderMiniCard(o) {
  const polozky = (o.polozky || []).slice(0, 6).map((p) => {
    const v = state.vyrobky.find(x => x.id == p.vyrobek_id);
    const nazev = p.vyrobek_nazev || (v ? v.nazev : `Výrobek #${p.vyrobek_id}`);
    const mn = parseFloat(p.mnozstvi);
    return { nazev, mn };
  });
  const zbyva = (o.polozky || []).length - polozky.length;

  const maDL = o.pocet_dl > 0 && o.prvni_dl_id;
  const maFA = o.pocet_faktur > 0 && o.prvni_faktura_id;

  return `
    <div class="order-mini">
      <div class="order-mini-head" onclick="zobrazDetailObjednavky(${o.id})">
        <strong>${esc(o.cislo)}</strong>
        <span class="status ${esc(o.stav)}">${statusLabel(o.stav)}</span>
      </div>
      <div class="order-mini-meta" onclick="zobrazDetailObjednavky(${o.id})">
        📅 ${fmtDate(o.datum_dodani)} · ${o.pocet_polozek} pol. · <strong>${fmt(o.castka_celkem)}</strong>
      </div>
      ${polozky.length > 0 ? `
        <ul class="order-mini-list" onclick="zobrazDetailObjednavky(${o.id})">
          ${polozky.map(p => `<li><span class="oml-qty">${p.mn}×</span> ${esc(p.nazev)}</li>`).join('')}
          ${zbyva > 0 ? `<li class="oml-more">+ dalších ${zbyva}</li>` : ''}
        </ul>
      ` : ''}
      <div class="order-mini-actions">
        <button class="oc-btn oc-btn-ghost oc-btn-sm" onclick="zobrazDetailObjednavky(${o.id})" title="Detail">
          📋 Detail
        </button>
        ${maDL ? `
          <a class="oc-btn oc-btn-ghost oc-btn-sm" href="api/dodaci_list.php?id=${o.id}" target="_blank" title="Dodací list">
            📃 DL
          </a>
        ` : `
          <span class="oc-btn oc-btn-ghost oc-btn-sm oc-btn-disabled" title="DL zatím neexistuje">
            📃 DL
          </span>
        `}
        ${maFA ? `
          <a class="oc-btn oc-btn-ghost oc-btn-sm" href="api/faktura.php?id=${o.prvni_faktura_id}" target="_blank" title="Faktura">
            💰 FA
          </a>
        ` : `
          <span class="oc-btn oc-btn-ghost oc-btn-sm oc-btn-disabled" title="FA zatím neexistuje">
            💰 FA
          </span>
        `}
        <button class="oc-btn oc-btn-primary oc-btn-sm" onclick="reorderObjednavku(${o.id})" title="Načíst položky této objednávky do košíku">
          🔁 <span class="oc-btn-label">Znovu</span>
        </button>
      </div>
    </div>
  `;
}

// Sdílená karta objednávky pro Katalog (poslední 3) i Historii
function renderOrderCard(o) {
  const polozky = (o.polozky || []).map((p) => {
    const v = state.vyrobky.find(x => x.id == p.vyrobek_id);
    const nazev = p.vyrobek_nazev || (v ? v.nazev : `Výrobek #${p.vyrobek_id}`);
    const mn = parseFloat(p.mnozstvi);
    const cenaJedn = parseFloat(p.cena_bez_dph || 0);
    const dph = parseFloat(p.sazba_dph || 0);
    const sDph = cenaJedn * (1 + dph / 100);
    const bezDphCelkem = cenaJedn * mn;
    const dphCelkem = bezDphCelkem * dph / 100;
    const celkem = bezDphCelkem + dphCelkem;
    // Detekce rabatu: porovnání zaplacené ceny vs. základní ceny (z polozky nebo z katalogu)
    let cenaZakladni = parseFloat(p.cena_zakladni || 0);
    if (!cenaZakladni && v) cenaZakladni = parseFloat(v.cena_zakladni || v.cena_bez_dph || 0);
    const maRabat = cenaZakladni > cenaJedn + 0.001;
    const rabatKs = maRabat ? (cenaZakladni - cenaJedn) : 0;
    const slevaPct = maRabat ? Math.round((1 - cenaJedn / cenaZakladni) * 100) : 0;
    const usetrenoCelkem = maRabat ? rabatKs * mn * (1 + dph / 100) : 0;
    return { nazev, mn, cenaJedn, dph, sDph, bezDphCelkem, dphCelkem, celkem,
             cenaZakladni, maRabat, rabatKs, slevaPct, usetrenoCelkem,
             dostupny: !!v };
  });

  const maDL = o.pocet_dl > 0 && o.prvni_dl_id;
  const maFA = o.pocet_faktur > 0 && o.prvni_faktura_id;

  return `
    <div class="order-card">
      <div class="order-card-head" onclick="zobrazDetailObjednavky(${o.id})">
        <strong>${esc(o.cislo)}</strong>
        <span class="status ${esc(o.stav)}">${statusLabel(o.stav)}</span>
      </div>
      <div class="order-card-meta" onclick="zobrazDetailObjednavky(${o.id})">
        📅 ${fmtDate(o.datum_dodani)} · ${o.pocet_polozek} pol. · <strong>${fmt(o.castka_celkem)}</strong>
      </div>
      ${polozky.length > 0 ? `
        <ul class="order-card-items">
          ${polozky.map((p) => `
            <li ${!p.dostupny ? 'class="unavailable"' : ''}>
              <div class="oci-main">
                <span class="oci-qty">${p.mn}×</span>
                <span class="oci-name">${esc(p.nazev)}</span>
                ${p.celkem > 0 ? `<span class="oci-price">${fmt(p.celkem)}</span>` : ''}
              </div>
              ${p.cenaJedn > 0 ? `
                <div class="oci-detail">
                  ${p.maRabat ? `
                    <span class="oci-d oci-d-saving"><span class="oci-d-lbl">💰 Rabat /ks</span> <strong>−${fmt(p.rabatKs)}</strong></span>
                    <span class="oci-d-sep">·</span>
                    <span class="oci-d oci-d-saving"><span class="oci-d-lbl">Sleva</span> <strong>−${p.slevaPct}%</strong></span>
                    <span class="oci-d-sep">·</span>
                    <span class="oci-d"><span class="oci-d-lbl">Běžně</span> <s>${fmt(p.cenaZakladni)}</s></span>
                    <span class="oci-d-sep">·</span>
                  ` : ''}
                  <span class="oci-d"><span class="oci-d-lbl">Vaše /ks</span> <strong>${fmt(p.cenaJedn)}</strong></span>
                  <span class="oci-d-sep">·</span>
                  <span class="oci-d"><span class="oci-d-lbl">s DPH /ks</span> <strong>${fmt(p.sDph)}</strong></span>
                  <span class="oci-d-sep">·</span>
                  <span class="oci-d"><span class="oci-d-lbl">DPH</span> <strong>${p.dph.toFixed(0)}%</strong></span>
                  <span class="oci-d-sep">·</span>
                  <span class="oci-d"><span class="oci-d-lbl">Bez DPH celkem</span> <strong>${fmt(p.bezDphCelkem)}</strong></span>
                  <span class="oci-d-sep">·</span>
                  <span class="oci-d"><span class="oci-d-lbl">DPH celkem</span> <strong>${fmt(p.dphCelkem)}</strong></span>
                </div>
              ` : ''}
            </li>
          `).join('')}
        </ul>
      ` : ''}
      <div class="order-card-actions">
        <button class="oc-btn oc-btn-ghost" onclick="zobrazDetailObjednavky(${o.id})" title="Detail objednávky">
          📋 Detail
        </button>
        ${maDL ? `
          <a class="oc-btn oc-btn-ghost" href="../api/dodaci_list.php?id=${o.id}" target="_blank" title="Dodací list (PDF)">
            <span class="oc-btn-icon">📃</span><span class="oc-btn-text"><span class="oc-btn-text-full">Dodací list</span><span class="oc-btn-text-short">DL</span></span>
          </a>
        ` : `
          <span class="oc-btn oc-btn-ghost oc-btn-disabled" title="Dodací list zatím nebyl vytvořen">
            <span class="oc-btn-icon">📃</span><span class="oc-btn-text"><span class="oc-btn-text-full">Dodací list</span><span class="oc-btn-text-short">DL</span></span>
          </span>
        `}
        ${maFA ? `
          <a class="oc-btn oc-btn-ghost" href="../api/faktura.php?id=${o.prvni_faktura_id}" target="_blank" title="Faktura (PDF)">
            <span class="oc-btn-icon">💰</span><span class="oc-btn-text"><span class="oc-btn-text-full">Faktura</span><span class="oc-btn-text-short">FA</span></span>
          </a>
        ` : `
          <span class="oc-btn oc-btn-ghost oc-btn-disabled" title="Faktura zatím nebyla vystavena">
            <span class="oc-btn-icon">💰</span><span class="oc-btn-text"><span class="oc-btn-text-full">Faktura</span><span class="oc-btn-text-short">FA</span></span>
          </span>
        `}
        <button class="oc-btn oc-btn-primary" onclick="reorderObjednavku(${o.id})" title="Načíst položky této objednávky do košíku">
          🔁 Znovu objednat
        </button>
      </div>
    </div>
  `;
}

// Renderuje statusové badges (NOVINKA / AKCE / DOPRODEJ).
// Manuálně nastavené v admin/výrobky, nezávislé na slevě.
// Zobrazí se VLEVO nahoře pod pilulkou kategorie, stack vertikálně.
function renderStatusBadges({ isNovinka, isAkce, isDoprodej, isVyprodano }) {
  if (isVyprodano) return ''; // VYPRODÁNO řeší overlay přes celou kartu
  const badges = [];
  if (isAkce)      badges.push(`<span class="card-status-badge card-status-akce">🔥 Akce</span>`);
  if (isNovinka)   badges.push(`<span class="card-status-badge card-status-novinka">✨ Novinka</span>`);
  if (isDoprodej)  badges.push(`<span class="card-status-badge card-status-doprodej">⏰ Doprodej</span>`);
  if (badges.length === 0) return '';
  return `<div class="card-status-badges">${badges.join('')}</div>`;
}

function renderCard(v) {
  const q = state.cart[v.id] || 0;
  const inCart = q > 0;
  const ikona = v.kategorie_ikona || '🥖';

  // Bezpečný onerror — bez interpolace user contentu do JS stringu
  const img = v.obrazek_url
    ? `<img src="${esc(v.obrazek_url)}" alt="${esc(v.nazev)}" class="card-img" data-fallback="${esc(ikona)}" onerror="imgFallback(this)">`
    : `<div class="card-img-placeholder">${esc(ikona)}</div>`;

  // Cenový blok - pokud je cena_zakladni vyšší než aktuální cena, je to sleva → ukaž přeškrtnutou
  const cenaZakladni = parseFloat(v.cena_zakladni || v.cena_bez_dph);
  const cenaAktualni = parseFloat(v.cena_bez_dph);
  const maSlevu = cenaZakladni > cenaAktualni + 0.001;

  const cenaHtml = maSlevu ? `
    <div class="card-price-block">
      <div class="card-price-original">${fmt(cenaZakladni)}</div>
      <div class="card-price card-price-sale">${fmt(cenaAktualni)}</div>
    </div>
  ` : `
    <div class="card-price">${fmt(cenaAktualni)}</div>
  `;

  const isFav = state.oblibene.has(parseInt(v.id));
  // Kategorie — barevný proužek nahoře + drobná pilulka
  const katId = parseInt(v.kategorie_id || 0);
  const accentHue = (katId * 47) % 360;
  const katIkona = v.kategorie_ikona || '🥖';
  const katNazev = v.kategorie_nazev || '';
  // Statusové flagy — **nezávislé** na slevě (manuálně nastavené v admin/výrobky)
  const isAkce      = parseInt(v.je_akce || 0) === 1;
  const isNovinka   = parseInt(v.je_novinka || 0) === 1;
  const isDoprodej  = parseInt(v.je_doprodej || 0) === 1;
  const isVyprodano = parseInt(v.je_vyprodano || 0) === 1 || parseInt(v.aktivni || 1) === 0;
  // Sleva = automatická podle ceny (skupina/individuální) — vpravo nahoru, je nezávislá na akci
  const slevaPct = maSlevu ? Math.round((1 - cenaAktualni / cenaZakladni) * 100) : 0;

  return `
    <div class="card ${inCart ? 'in-cart' : ''} ${isVyprodano ? 'is-vyprodano' : ''}" data-kat="${katId}" style="--cat-hue: ${accentHue}" title="${esc(katNazev || '')}" onclick="if(document.body.classList.contains('pos-mode') && !event.target.closest('button,input')) { changeQty(${v.id}, 1); }">
      <div class="card-img-wrap">
        ${img}
        ${renderStatusBadges({ isNovinka, isAkce, isDoprodej, isVyprodano })}
        ${maSlevu ? `<div class="card-badges card-badges-right-top"><span class="card-badge card-badge-sale">−${slevaPct}%</span></div>` : ''}
        ${v.oblibeny == 1 ? `<div class="card-badges card-badges-right-top2"><span class="card-badge card-badge-top">★ Top</span></div>` : ''}
        ${isVyprodano ? '<div class="card-vyprodano-overlay">VYPRODÁNO</div>' : ''}
        <button class="card-fav ${isFav ? 'is-fav' : ''}" onclick="event.stopPropagation();toggleOblibeny(${v.id})" title="${isFav ? 'Odebrat z oblíbených' : 'Přidat do oblíbených'}" aria-label="Oblíbený">
          ${isFav ? '⭐' : '☆'}
        </button>
      </div>
      <div class="card-body">
        <div class="card-name">${esc(v.nazev)}</div>
        <div class="card-unit">${v.hmotnost_g ? v.hmotnost_g + ' g · ' : ''}${esc(v.jednotka || 'ks')}</div>
        <div class="card-price-row">
          ${cenaHtml}
          ${inCart ? `<button class="qty-btn-del" onclick="removeFromCart(${v.id})" title="Odebrat z košíku">🗑️</button>` : ''}
        </div>
        ${q > 0 ? `
        <div class="card-qty-row">
          <button class="qty-btn" onclick="changeQty(${v.id}, -1)">−</button>
          <input type="number" class="qty-input" value="${q}" min="0" onchange="setQty(${v.id}, this.value)" onclick="this.select()">
          <button class="qty-btn" onclick="changeQty(${v.id}, 1)">+</button>
        </div>
        <div class="card-qty-quick-row">
          <button class="qty-quick-wide qty-quick-minus" onclick="changeQty(${v.id}, -5)" title="−5 ks">−5</button>
          <button class="qty-quick-wide" onclick="changeQty(${v.id}, 10)" title="+10 ks">+10</button>
        </div>
        ` : `
        <div class="card-qty-row">
          <button class="qty-btn-add" onclick="changeQty(${v.id}, 1)">+ Přidat</button>
        </div>
        `}
      </div>
    </div>
  `;
}

// Bezpečný image fallback — neinjektuje obsah z proměnné do JS stringu
window.imgFallback = function(img) {
  const fb = img.dataset.fallback || '🥖';
  const div = document.createElement('div');
  div.className = 'card-img-placeholder';
  div.textContent = fb;
  img.replaceWith(div);
};

window.setKategorie = function(id) {
  state.filterKategorie = id;
  renderCatalog();
};

window.changeQty = function(id, delta) {
  const cur = state.cart[id] || 0;
  const next = Math.max(0, cur + delta);
  if (next === 0) delete state.cart[id];
  else state.cart[id] = next;
  saveCart();
  // Karty potřebují re-render aby se update reflektoval v `+/-` ovládání
  if (state.currentTab === 'catalog') renderCatalog();
  if (state.currentTab === 'checkout') renderCheckout();
};

// =============================================================
// POKLADNA — vlevo formulář, vpravo košíkový panel slouží jako účtenka
// =============================================================
function renderCheckout() {
  const c = document.getElementById('content');
  const items = cartItems();

  if (items.length === 0) {
    c.innerHTML = `
      <h1 class="section-title">Košík</h1>
      <div class="empty">
        Košík je prázdný. Přidejte výrobky v
        <a onclick="switchTab('catalog')" style="cursor:pointer">katalogu</a>.
      </div>
    `;
    return;
  }

  const tomorrow = new Date();
  tomorrow.setDate(tomorrow.getDate() + 1);
  const minDate = tomorrow.toISOString().split('T')[0];

  const t = cartTotals();

  // Shared action button block — používá se nahoře i dole, ať jsou tlačítka shodná
  const actionsBlock = (showBack = false) => `
    <div class="checkout-actions ${showBack ? 'checkout-actions-with-back' : ''}">
      ${showBack ? `<button class="checkout-actions-back" onclick="switchTab('catalog')" title="Zpět do katalogu">← Zpět</button>` : ''}
      <button class="checkout-actions-save" onclick="sablonaUlozitZKosiku()" title="Ulož aktuální košík jako šablonu pro příště">
        📌 Uložit jako šablonu
      </button>
      <button class="checkout-actions-submit" onclick="submitOrder()">
        ✅ Odeslat objednávku
      </button>
    </div>
  `;

  c.innerHTML = `
    <div class="checkout-head-row">
      <div>
        <h1 class="section-title" style="margin-bottom:2px">Souhrn objednávky</h1>
        <p class="section-sub" style="margin-bottom:0">Zkontroluj položky, nastav dodání a odešli</p>
      </div>
      ${actionsBlock(false)}
    </div>

    <!-- 1️⃣ TYP OBJEDNÁVKY — nejdříve si vybereš jak/kdy -->
    <div class="checkout-section">
      <div class="form-row" style="grid-template-columns: 1fr; gap: 8px;">
        <span class="form-label">1. Typ objednávky <span class="form-hint">— jak často se má opakovat</span></span>
        <div class="typ-cards">
          <button class="typ-card ${state.checkoutData.typ === 'jednorazova' ? 'active' : ''}"
                  onclick="setTyp('jednorazova')" type="button">
            <span class="typ-card-icon">🛒</span>
            <span class="typ-card-body">
              <span class="typ-card-title">Jednorázová</span>
              <span class="typ-card-desc">Na zítra, jednou. Nejčastější volba.</span>
            </span>
          </button>
          <button class="typ-card ${state.checkoutData.typ === 'naplanovana' ? 'active' : ''}"
                  onclick="setTyp('naplanovana')" type="button">
            <span class="typ-card-icon">📆</span>
            <span class="typ-card-body">
              <span class="typ-card-title">Naplánovaná</span>
              <span class="typ-card-desc">Na konkrétní den v budoucnu.</span>
            </span>
          </button>
          <button class="typ-card ${state.checkoutData.typ === 'pravidelna_denni' ? 'active' : ''}"
                  onclick="setTyp('pravidelna_denni')" type="button">
            <span class="typ-card-icon">🔁</span>
            <span class="typ-card-body">
              <span class="typ-card-title">Denně</span>
              <span class="typ-card-desc">Každý pracovní den v určeném období.</span>
            </span>
          </button>
          <button class="typ-card ${state.checkoutData.typ === 'tydenni_plan' ? 'active' : ''}"
                  onclick="setTyp('tydenni_plan')" type="button">
            <span class="typ-card-icon">📅</span>
            <span class="typ-card-body">
              <span class="typ-card-title">Týdenní plán</span>
              <span class="typ-card-desc">Opakuje se každý týden ve stejné dny.</span>
            </span>
          </button>
        </div>
        ${renderTypHelp(state.checkoutData.typ)}
      </div>
    </div>

    <!-- 2️⃣ MÍSTO + DATUM + POZNÁMKA -->
    <div class="checkout-section">
      <span class="form-label" style="margin-bottom:10px;display:block">2. Dodací údaje</span>
      ${state.mistaDodani.length > 1 ? `
        <div class="form-row">
          <label class="form-label" style="font-weight:500">📍 Místo dodání</label>
          <select class="input" onchange="state.checkoutData.misto_dodani_id = parseInt(this.value); renderHeaderUser()">
            ${state.mistaDodani.map((m) => `
              <option value="${m.id}" ${state.checkoutData.misto_dodani_id == m.id ? 'selected' : ''}>
                ${esc(m.nazev)}${m.ulice ? ' – ' + esc(m.ulice) : ''}${m.mesto ? ', ' + esc(m.mesto) : ''}
              </option>
            `).join('')}
          </select>
        </div>
      ` : (state.mistaDodani.length === 1 ? `
        <div class="form-row">
          <label class="form-label" style="font-weight:500">📍 Místo dodání</label>
          <div style="padding:10px 14px;background:var(--surface-2);border-radius:8px;font-size:14px">
            <strong>${esc(state.mistaDodani[0].nazev)}</strong>
            ${state.mistaDodani[0].ulice ? '<br><span style="color:var(--text-3);font-size:13px">' + esc(state.mistaDodani[0].ulice) + (state.mistaDodani[0].mesto ? ', ' + esc(state.mistaDodani[0].mesto) : '') + '</span>' : ''}
          </div>
        </div>
      ` : '')}

      ${state.checkoutData.typ === 'jednorazova' ? `
        <div class="form-row">
          <label class="form-label" for="dt-dodani" style="font-weight:500">📅 Datum dodání <span class="form-hint">— defaultně zítra</span></label>
          <input class="input" type="date" id="dt-dodani" min="${minDate}" value="${minDate}">
        </div>
      ` : state.checkoutData.typ === 'naplanovana' ? `
        <div class="form-row">
          <label class="form-label" for="dt-dodani" style="font-weight:500">📆 Vyber den dodání</label>
          <div class="form-row-stack">
            <div class="date-chips">
              ${rychleChipy(minDate).map((c) => `
                <button type="button" class="date-chip" onclick="setDatumDodani('${c.date}')">
                  <span class="date-chip-label">${esc(c.label)}</span>
                  <span class="date-chip-date">${esc(c.short)}</span>
                </button>
              `).join('')}
            </div>
            <input class="input" type="date" id="dt-dodani" min="${minDate}" value="${state.checkoutData.datumDodaniPick || minDate}" style="margin-top:8px">
            <p class="form-hint" style="margin-top:6px">Klikni na rychlou volbu nebo vyber datum z kalendáře.</p>
          </div>
        </div>
      ` : `
        <div class="form-row">
          <label class="form-label" for="dt-od" style="font-weight:500">📅 Platí od</label>
          <input class="input" type="date" id="dt-od" min="${minDate}" value="${minDate}">
        </div>
        <div class="form-row">
          <label class="form-label" for="dt-do" style="font-weight:500">📅 Platí do <span class="form-hint">— volitelné, jinak běží do odvolání</span></label>
          <input class="input" type="date" id="dt-do" min="${minDate}">
        </div>
      `}

      <div class="form-row" style="align-items: start;">
        <label class="form-label" for="poznamka" style="font-weight:500">📝 Poznámka <span class="form-hint">— volitelné</span></label>
        <textarea class="input" id="poznamka" rows="3" placeholder="Speciální požadavky..."></textarea>
      </div>
    </div>

    <!-- 🆕 v2.5 — 3️⃣ DOPRAVA -->
    <div class="checkout-section">
      <span class="form-label" style="margin-bottom:10px;display:block">3. 🚚 Způsob doručení</span>
      <div id="b2b-doprava-cards" class="b2b-pay-grid">⏳ Načítám možnosti dopravy…</div>
    </div>

    <!-- 🆕 v2.5 — 4️⃣ PLATBA -->
    <div class="checkout-section">
      <span class="form-label" style="margin-bottom:10px;display:block">4. 💳 Způsob platby</span>
      <div id="b2b-platba-cards" class="b2b-pay-grid">⏳ Načítám možnosti platby…</div>
    </div>

    <!-- 5️⃣ POLOŽKY KOŠÍKU -->
    <div class="checkout-cart-inline">
      <div class="checkout-cart-head">
        <h3>5. 🧾 Položky (${cartCount()} ks)</h3>
        <button class="cart-clear" onclick="clearCart()">Vyprázdnit</button>
      </div>
      <div class="pokladna-tabulka">
        <!-- 🏷️ HLAVIČKA — subgrid přebírá 12 sloupců z .pokladna-tabulka -->
        <div class="pokladna-row pokladna-row-header">
          <div class="ph-cell ph-cell-empty"></div>
          <div class="ph-cell ph-cell-empty">Položka</div>
          <div class="ph-cell ph-cell-spacer"></div>
          <div class="ph-cell ph-cell-h">Vaše cena</div>
          <div class="ph-cell ph-cell-h">Běžná cena</div>
          <div class="ph-cell ph-cell-h">Rabat / ks</div>
          <div class="ph-cell ph-cell-h">Sleva</div>
          <div class="ph-cell ph-cell-spacer"></div>
          <div class="ph-cell ph-cell-empty">Množství</div>
          <div class="ph-cell ph-cell-spacer-narrow"></div>
          <div class="ph-cell ph-cell-empty">Celkem</div>
          <div class="ph-cell ph-cell-spacer-narrow"></div>
          <div class="ph-cell ph-cell-empty"></div>
        </div>
        ${items.map((i, idx) => {
          const cZakl = parseFloat(i.cena_zakladni || i.cena_bez_dph);
          const cAkt = parseFloat(i.cena_bez_dph);
          const maSlevu = cZakl > cAkt + 0.001;
          const ikona = i.kategorie_ikona || '🥖';
          const katId = parseInt(i.kategorie_id || 0);
          const accentHue = (katId * 47) % 360;
          const thumbHtml = i.obrazek_url
            ? `<img src="${esc(i.obrazek_url)}" alt="${esc(i.nazev)}" data-fallback="${esc(ikona)}" onerror="imgFallback(this)">`
            : `<div class="pokladna-row-emoji">${esc(ikona)}</div>`;
          return `
          <div class="pokladna-row" style="--row-accent: hsl(${accentHue}, 65%, 55%)">
            <div class="pr-cell pokladna-row-ikona">${thumbHtml}</div>
            <div class="pr-cell pokladna-row-nazev">${esc(i.nazev)}</div>
            <div class="pr-cell pr-spacer"></div>
            <div class="pr-cell pokladna-cell-now">
              <strong>${fmt(cAkt)}</strong>
              <span class="pokladna-row-jednotka">/ ${esc(i.jednotka || 'ks')}</span>
            </div>
            <div class="pr-cell pokladna-cell-was">
              ${maSlevu ? `<s>${fmt(cZakl)}</s>` : '<span class="dash">—</span>'}
            </div>
            <div class="pr-cell pokladna-cell-save">
              ${maSlevu ? `<strong>−${fmt(cZakl - cAkt)}</strong>` : '<span class="dash">—</span>'}
            </div>
            <div class="pr-cell pokladna-cell-sleva">
              ${maSlevu ? `<span class="pokladna-row-saleflag">−${Math.round((1 - cAkt / cZakl) * 100)}%</span>` : '<span class="dash">—</span>'}
            </div>
            <div class="pr-cell pr-spacer"></div>

            <div class="pr-cell pokladna-row-qty">
              <button class="pokladna-qty-minus" onclick="changeQty(${i.id}, -1)" aria-label="Méně">−</button>
              <input type="number" class="pokladna-qty-num" value="${i.mnozstvi}" min="0"
                     onchange="setQty(${i.id}, this.value)" onclick="this.select()" title="${fmtMnozstvi(i.mnozstvi, i.jednotka)}">
              <button class="pokladna-qty-plus" onclick="changeQty(${i.id}, 1)" aria-label="Více">+</button>
              <div class="pokladna-qty-quick">
                <button class="pokladna-qty-chip" onclick="changeQty(${i.id}, -5)" title="Odebrat 5">−5</button>
                <button class="pokladna-qty-chip" onclick="changeQty(${i.id}, 10)" title="Přidat 10">+10</button>
              </div>
            </div>
            <div class="pr-cell pr-spacer-narrow"></div>

            <div class="pr-cell pokladna-row-cena">${fmt(cAkt * i.mnozstvi)}</div>
            <div class="pr-cell pr-spacer-narrow"></div>

            <button class="pr-cell pokladna-row-del" onclick="removeFromCart(${i.id})" title="Smazat položku z košíku" aria-label="Smazat z košíku">
              <svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                <path d="M3 6h18"></path>
                <path d="M8 6V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"></path>
                <path d="M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6"></path>
                <line x1="10" y1="11" x2="10" y2="17"></line>
                <line x1="14" y1="11" x2="14" y2="17"></line>
              </svg>
            </button>
          </div>
          `;
        }).join('')}
      </div>
    </div>

    <!-- 4️⃣ CELKOVÝ SOUHRN — nahoře nad odeslání -->
    <div class="checkout-total-block">
      <div class="checkout-total-head">4. 💰 Celkem k objednání</div>
      <div class="checkout-total-rows">
        ${t.usetreno > 0.005 ? `
          <div class="cart-summary-row cart-summary-saved">
            <span>💰 Celková sleva</span>
            <span>− ${fmt(t.usetreno)}</span>
          </div>
        ` : ''}
        <div class="cart-summary-row"><span>Bez DPH</span><span>${fmt(t.bezDph)}</span></div>
        <div class="cart-summary-row"><span>DPH</span><span>${fmt(t.dph)}</span></div>
        ${cartTotalWeightG() > 0 ? `
          <div class="cart-summary-row cart-summary-weight" title="Součet hmotností všech položek (ks × hmotnost na kus + kg/g položky)">
            <span>⚖️ Hmotnost celkem</span><span>${fmtHmotnost(cartTotalWeightG())}</span>
          </div>
        ` : ''}
        <div class="cart-summary-row total"><span>Celkem s DPH</span><span>${fmt(t.total)}</span></div>
      </div>
    </div>

    ${actionsBlock(true)}
  `;

  // 🆕 v2.5 — Lazy-load doprava + platba karty
  loadDopravaPlatbaCards();

  // Cart panel vpravo dostane v "checkout" módu jiné CTA (skryté)
  // — protože tlačítko "Odeslat" je vlevo. renderCart() už to ošetřuje
  // (CTA "K pokladně" se ukazuje jen na 'catalog').
}

// =============================================================
// 🆕 v2.5 — DOPRAVA + PLATBA karty (z customer integrací)
// =============================================================
async function loadDopravaPlatbaCards() {
  let services = { stripe: false, gopay: false, zas: false, dpd: false };
  try {
    const r = await api('admin_integrace.php?action=all');
    for (const s of (r.services || [])) services[s.key] = s.enabled;
  } catch (e) { /* ne-admin route — to je v pořádku, fallback na základní opce */ }

  // Default selection (z localStorage)
  if (!state.checkoutData.doprava) {
    state.checkoutData.doprava = localStorage.getItem('b2b_doprava_default') || 'vlastni';
  }
  if (!state.checkoutData.platba) {
    state.checkoutData.platba = localStorage.getItem('b2b_platba_default') || 'prevod';
  }

  // Doprava karty
  const dopravaOpts = [
    { key: 'vlastni',    icon: '🚐', label: 'Vlastní odvoz / pickup', desc: 'Zboží si vyzvednu osobně nebo vyřešíme jinak', always: true },
    { key: 'zasilkovna', icon: '📦', label: 'Zásilkovna', desc: 'Výdejní místo po ČR/EU · dobírka možná', enabled: services.zas },
    { key: 'dpd',        icon: '📦', label: 'DPD CZ', desc: 'Kurýrní doručení na adresu · dobírka možná', enabled: services.dpd },
  ];
  const dopravaEl = document.getElementById('b2b-doprava-cards');
  if (dopravaEl) {
    dopravaEl.innerHTML = dopravaOpts.filter(o => o.always || o.enabled).map(o => `
      <button type="button" class="b2b-pay-card ${state.checkoutData.doprava === o.key ? 'active' : ''}"
              onclick="setDoprava('${o.key}')" data-svc="${o.key}">
        <span class="b2b-pay-ico">${o.icon}</span>
        <span class="b2b-pay-info">
          <span class="b2b-pay-name">${esc(o.label)}</span>
          <span class="b2b-pay-desc">${esc(o.desc)}</span>
        </span>
      </button>
    `).join('') || '<div style="color:var(--text-3);font-size:13px">Žádný způsob dopravy zatím není zapnutý. Kontaktuj dodavatele.</div>';
  }

  // Platba karty
  const platbaOpts = [
    { key: 'prevod',   icon: '🏦', label: 'Bankovní převod', desc: 'Faktura po dodání, splatnost typicky 14 dní', always: true },
    { key: 'hotove',   icon: '💵', label: 'Hotově při převzetí', desc: 'Platba na ruku při doručení', always: true },
    { key: 'dobirka',  icon: '📦', label: 'Dobírka', desc: 'Platba kurýrovi/výdejně při převzetí', condition: (s) => s.zas || s.dpd },
    { key: 'stripe',   icon: '💳', label: 'Karta online (Stripe)', desc: 'Visa, Mastercard, Apple Pay, Google Pay', enabled: services.stripe },
    { key: 'gopay',    icon: '💳', label: 'GoPay (karta + bank)', desc: 'CZ karty + okamžitý bank převod', enabled: services.gopay },
  ];
  const platbaEl = document.getElementById('b2b-platba-cards');
  if (platbaEl) {
    const visible = platbaOpts.filter(o =>
      o.always || o.enabled || (o.condition && o.condition(services))
    );
    platbaEl.innerHTML = visible.map(o => `
      <button type="button" class="b2b-pay-card ${state.checkoutData.platba === o.key ? 'active' : ''}"
              onclick="setPlatba('${o.key}')" data-svc="${o.key}">
        <span class="b2b-pay-ico">${o.icon}</span>
        <span class="b2b-pay-info">
          <span class="b2b-pay-name">${esc(o.label)}</span>
          <span class="b2b-pay-desc">${esc(o.desc)}</span>
        </span>
      </button>
    `).join('');
  }
}

window.setDoprava = function(key) {
  state.checkoutData.doprava = key;
  try { localStorage.setItem('b2b_doprava_default', key); } catch (e) {}
  loadDopravaPlatbaCards();
};
window.setPlatba = function(key) {
  state.checkoutData.platba = key;
  try { localStorage.setItem('b2b_platba_default', key); } catch (e) {}
  loadDopravaPlatbaCards();
};

window.setTyp = function(typ) {
  state.checkoutData.typ = typ;
  renderCheckout();
};

window.setDatumDodani = function(dateStr) {
  state.checkoutData.datumDodaniPick = dateStr;
  const inp = document.getElementById('dt-dodani');
  if (inp) inp.value = dateStr;
  // Highlight aktivní chip
  document.querySelectorAll('.date-chip').forEach((b) => {
    b.classList.toggle('active', b.getAttribute('onclick')?.includes(`'${dateStr}'`));
  });
};

// Velký info box pod kartami — vysvětlí, co se přesně stane po odeslání
function renderTypHelp(typ) {
  const texty = {
    jednorazova:
      '🛒 <strong>Jednorázová</strong> — vytvoří se jedna objednávka pro <strong>jeden den</strong>. ' +
      'Provoz ti ji potvrdí a v ten den ti zboží přivezou. Defaultně <strong>na zítra</strong>.',
    naplanovana:
      '📆 <strong>Naplánovaná</strong> — to samé jako jednorázová, ale můžeš si vybrat <strong>jakýkoli den v budoucnu</strong> ' +
      '(např. „pondělí za týden") přes rychlé chipy nebo kalendář.',
    pravidelna_denni:
      '🔁 <strong>Denně</strong> — objednávka se vytvoří <strong>každý den</strong> v zadaném období. ' +
      'Když potřebuješ identickou objednávku po dobu několika dní/týdnů (např. dovolenou v hotelu). ' +
      'Můžeš ji kdykoli upravit nebo zrušit v Historii.',
    tydenni_plan:
      '📅 <strong>Týdenní plán</strong> — objednávka se opakuje <strong>každý týden</strong> ve stejné dny v zvoleném období. ' +
      'Pro hotely / kavárny s pravidelným rytmem (např. „každý pátek a sobota").',
  };
  return `<p class="typ-help">${texty[typ] || ''}</p>`;
}

// Generuje pole rychlých chipů pro výběr data (Naplánovaná objednávka)
function rychleChipy(minDate) {
  const today = new Date();
  today.setHours(0, 0, 0, 0);
  const dny = ['neděle', 'pondělí', 'úterý', 'středa', 'čtvrtek', 'pátek', 'sobota'];

  const chips = [];

  // +1, +2, +3 dny
  for (let i = 1; i <= 3; i++) {
    const d = new Date(today);
    d.setDate(d.getDate() + i);
    const label = i === 1 ? 'Zítra' : (i === 2 ? 'Pozítří' : dny[d.getDay()].charAt(0).toUpperCase() + dny[d.getDay()].slice(1));
    chips.push({
      date: d.toISOString().split('T')[0],
      label,
      short: `${d.getDate()}. ${d.getMonth() + 1}.`,
    });
  }

  // Příští pondělí (pokud dnes není pondělí, jinak za týden)
  const nextMonday = new Date(today);
  const dow = today.getDay();
  const daysUntilMonday = (dow === 1 ? 7 : (8 - dow) % 7 || 7);
  nextMonday.setDate(today.getDate() + daysUntilMonday);
  if (nextMonday > new Date(today.getTime() + 3 * 86400000)) {
    chips.push({
      date: nextMonday.toISOString().split('T')[0],
      label: 'Příští pondělí',
      short: `${nextMonday.getDate()}. ${nextMonday.getMonth() + 1}.`,
    });
  }

  // +1 týden
  const week1 = new Date(today);
  week1.setDate(week1.getDate() + 7);
  chips.push({
    date: week1.toISOString().split('T')[0],
    label: 'Za týden',
    short: `${week1.getDate()}. ${week1.getMonth() + 1}.`,
  });

  // +2 týdny
  const week2 = new Date(today);
  week2.setDate(week2.getDate() + 14);
  chips.push({
    date: week2.toISOString().split('T')[0],
    label: 'Za 2 týdny',
    short: `${week2.getDate()}. ${week2.getMonth() + 1}.`,
  });

  return chips;
}

window.submitOrder = async function() {
  const polozky = Object.entries(state.cart).map(([id, q]) => ({
    vyrobek_id: parseInt(id), mnozstvi: q,
  }));
  if (polozky.length === 0) return alert('Košík je prázdný');

  // Backend rozpoznává jen 3 typy: jednorazova / pravidelna_denni / tydenni_plan.
  // 'naplanovana' je UX-only varianta, na backend ji posíláme jako 'jednorazova'.
  const typBackend = state.checkoutData.typ === 'naplanovana'
    ? 'jednorazova'
    : state.checkoutData.typ;

  const data = {
    typ: typBackend,
    misto_dodani_id: state.checkoutData.misto_dodani_id,
    polozky,
    poznamka: document.getElementById('poznamka')?.value || null,
    // 🆕 v2.5 — doprava + platba selection
    doprava: state.checkoutData.doprava || 'vlastni',
    platba:  state.checkoutData.platba  || 'prevod',
  };

  if (typBackend === 'jednorazova') {
    data.datum_dodani = document.getElementById('dt-dodani').value;
  } else {
    data.plati_od = document.getElementById('dt-od').value;
    data.plati_do = document.getElementById('dt-do').value || null;
    data.datum_dodani = data.plati_od;
  }

  if (!data.datum_dodani) return alert('Vyplňte datum dodání');

  try {
    const res = await api('objednavky.php', {
      method: 'POST',
      body: JSON.stringify(data),
    });
    state.cart = {};
    saveCart();
    alert(`Objednávka ${res.cislo} byla odeslána. Děkujeme!`);
    switchTab('history');
  } catch (e) {
    alert('Chyba: ' + e.message);
  }
};

// =============================================================
// HISTORIE
// =============================================================
async function renderHistory() {
  const c = document.getElementById('content');
  c.innerHTML = `<h1 class="section-title">Historie objednávek</h1><p class="section-sub">Načítám…</p>`;
  try {
    const data = await api('objednavky.php?last=50');
    if (!data.length) {
      c.innerHTML = `
        <h1 class="section-title">Historie objednávek</h1>
        <div class="empty">Zatím žádné objednávky</div>
      `;
      return;
    }
    // Cache pro reorder (sdílí se s posledniObjednavky pro konsistenci)
    state.posledniObjednavky = data;

    c.innerHTML = `
      <h1 class="section-title">Historie objednávek</h1>
      <p class="section-sub">${data.length} ${data.length === 1 ? 'objednávka' : (data.length < 5 ? 'objednávky' : 'objednávek')}</p>
      <div class="orders-list">
        ${data.map(renderOrderCard).join('')}
      </div>
    `;
  } catch (e) {
    c.innerHTML = `<h1 class="section-title">Historie</h1><p style="color:var(--danger-text)">Chyba: ${esc(e.message)}</p>`;
  }
}

// =============================================================
// PŘEHLED
// =============================================================
async function renderStats() {
  const c = document.getElementById('content');
  c.innerHTML = `<h1 class="section-title">Přehled za měsíc</h1><p class="section-sub">Načítám…</p>`;
  try {
    const data = await api('statistiky.php');
    c.innerHTML = `
      <h1 class="section-title">Přehled za měsíc</h1>
      <p class="section-sub">Statistiky za tento měsíc</p>
      <div class="stat-grid">
        <div class="stat"><div class="stat-label">Objednávek</div><div class="stat-value">${data.souhrn.objednavek}</div></div>
        <div class="stat"><div class="stat-label">Útrata</div><div class="stat-value">${fmt(data.souhrn.utrata)}</div></div>
        <div class="stat"><div class="stat-label">Průměr</div><div class="stat-value">${fmt(data.souhrn.prumer)}</div></div>
      </div>
      <h2 style="font-size:16px; font-weight:500; margin: 20px 0 8px;">Nejčastěji objednávané výrobky</h2>
      ${data.top_vyrobky.length === 0 ? '<div class="empty">Žádná data</div>' : `
        <div class="checkout-section">
          ${data.top_vyrobky.map((t) => `
            <div class="bar-row">
              <div class="bar-head"><span>${esc(t.nazev)}</span><span><strong>${t.mnozstvi}</strong> ks</span></div>
              <div class="bar-track"><div class="bar-fill" style="width:${t.pct}%"></div></div>
            </div>
          `).join('')}
        </div>
      `}
    `;
  } catch (e) {
    c.innerHTML = `<h1 class="section-title">Přehled</h1><p style="color:var(--danger-text)">Chyba: ${esc(e.message)}</p>`;
  }
}

// =============================================================
// BOOTSTRAP
// =============================================================
(async function () {
  try {
    // Zkusíme statistiky.php — pokud projde, jsme přihlášení
    await api('statistiky.php');
    // Bohužel nemáme endpoint pro "kdo jsem" — login stav stačí
    state.user = { id: 0, nazev: 'Odběratel' };
    await loadCatalog();
    await loadMista();
    showApp();
  } catch (e) {
    // 401 = login screen zůstane viditelný (default)
  }
})();

// =============================================================
// MODAL helpers
// =============================================================
function openModal(html) {
  const overlay = document.getElementById('modal-overlay');
  const card = document.getElementById('modal-card');
  card.innerHTML = html;
  overlay.style.display = 'flex';
  document.body.style.overflow = 'hidden';
}
window.closeModal = function() {
  document.getElementById('modal-overlay').style.display = 'none';
  document.body.style.overflow = '';
};

function fmtDateTime(s) {
  if (!s) return '';
  const d = new Date(s.replace(' ', 'T'));
  if (isNaN(d.getTime())) return s;
  return d.toLocaleString('cs-CZ', {
    day: 'numeric', month: 'numeric', year: 'numeric',
    hour: '2-digit', minute: '2-digit',
  });
}

function akceLabel(a) {
  return ({
    upravena: '✏️ Upravena',
    zrusena: '❌ Zrušena',
    obnovena: '↩️ Obnovena',
  })[a] || a;
}

// =============================================================
// DETAIL OBJEDNÁVKY
// =============================================================
window.zobrazDetailObjednavky = async function(id) {
  openModal(`
    <div class="modal-head">
      <h2>Detail objednávky</h2>
      <button class="modal-close" onclick="closeModal()"></button>
    </div>
    <p style="color:var(--text-3)">Načítám…</p>
  `);
  try {
    const o = await api(`objednavky.php?id=${id}`);
    renderDetailObjednavky(o);
  } catch (e) {
    openModal(`
      <div class="modal-head">
        <h2>Chyba</h2>
        <button class="modal-close" onclick="closeModal()"></button>
      </div>
      <p style="color:var(--danger-text)">${esc(e.message)}</p>
    `);
  }
};

function renderDetailObjednavky(o) {
  const lze = o.lze_editovat;
  const polozkySoucet = (o.polozky || []).reduce((s, p) =>
    s + parseFloat(p.cena_bez_dph) * parseFloat(p.mnozstvi) * (1 + parseFloat(p.sazba_dph) / 100)
  , 0);

  const html = `
    <div class="modal-head">
      <h2>Objednávka <strong>${esc(o.cislo)}</strong></h2>
      <button class="modal-close" onclick="closeModal()"></button>
    </div>

    <div class="detail-meta-grid">
      <div class="detail-meta-box">
        <div class="detail-meta-label">Datum dodání</div>
        <div class="detail-meta-value">${fmtDate(o.datum_dodani)}</div>
      </div>
      <div class="detail-meta-box">
        <div class="detail-meta-label">Stav</div>
        <div class="detail-meta-value"><span class="status ${esc(o.stav)}">${statusLabel(o.stav)}</span></div>
      </div>
      <div class="detail-meta-box">
        <div class="detail-meta-label">Místo dodání</div>
        <div class="detail-meta-value" style="font-size:13px">${esc(o.misto_nazev || '—')}${o.misto_mesto ? '<br><span style="font-weight:400;color:var(--text-3);font-size:12px">' + esc(o.misto_mesto) + '</span>' : ''}</div>
      </div>
      <div class="detail-meta-box">
        <div class="detail-meta-label">Celkem</div>
        <div class="detail-meta-value">${fmt(o.castka_celkem)}</div>
      </div>
    </div>

    ${lze
      ? `<div class="detail-editable">✅ Objednávku můžete upravovat ${o.uzaverka ? '<strong>do ' + fmtDateTime(o.uzaverka) + '</strong>' : ''}</div>`
      : `<div class="detail-locked">🔒 ${esc(o.duvod_zamceni || 'Objednávku už nelze upravovat.')}</div>`
    }

    <table class="detail-table">
      <thead>
        <tr>
          <th>Položka</th>
          <th class="num">Množství</th>
          <th class="num">Cena/ks</th>
          <th class="num">Celkem</th>
        </tr>
      </thead>
      <tbody>
        ${(o.polozky || []).map(p => `
          <tr>
            <td>${esc(p.vyrobek_nazev)}</td>
            <td class="num">${parseFloat(p.mnozstvi)} ${esc(p.jednotka || 'ks')}</td>
            <td class="num">${fmt(p.cena_bez_dph)}</td>
            <td class="num"><strong>${fmt(parseFloat(p.cena_bez_dph) * parseFloat(p.mnozstvi))}</strong></td>
          </tr>
        `).join('')}
      </tbody>
    </table>

    ${o.poznamka ? `<div style="background:var(--surface-2);padding:10px;border-radius:8px;font-size:13px;margin-bottom:10px"><strong>Poznámka:</strong> ${esc(o.poznamka)}</div>` : ''}

    ${(o.historie_zmen && o.historie_zmen.length) ? `
      <div class="history-zmen-list">
        <h4>Historie změn (${o.historie_zmen.length})</h4>
        ${o.historie_zmen.map(z => `
          <div class="history-zmena-row">
            <strong>${akceLabel(z.akce)}</strong> &middot;
            ${fmtDateTime(z.kdy)} &middot;
            <span style="color:var(--text-3)">${esc(z.kdo_jmeno || z.kdo_typ)}</span>
          </div>
        `).join('')}
      </div>
    ` : ''}

    <div class="modal-actions">
      <button class="btn-secondary" onclick="closeModal()">Zavřít</button>
      <div class="grow"></div>
      ${lze ? `
        <button class="btn-cancel-order" onclick="zrusitObjednavku(${o.id})">❌ Zrušit objednávku</button>
        <button class="btn-primary" onclick="zacniUpravuObjednavky(${o.id})">✏️ Upravit objednávku</button>
      ` : ''}
    </div>
  `;
  openModal(html);
}

// =============================================================
// ZRUŠENÍ OBJEDNÁVKY
// =============================================================
window.zrusitObjednavku = async function(id) {
  if (!await confirmDelete2x({ co: 'celou tuto objednávku', detail: 'Objednávka bude zrušená a odběrateli odejde potvrzení.' })) return;
  try {
    await api(`objednavky.php?id=${id}`, { method: 'DELETE' });
    closeModal();
    alert('Objednávka byla zrušena. Email s potvrzením byl odeslán.');
    renderHistory();
  } catch (e) {
    alert('Chyba: ' + e.message);
  }
};

// =============================================================
// EDITOR OBJEDNÁVKY
// =============================================================
let editorState = null;

window.zacniUpravuObjednavky = async function(id) {
  // Načti znovu detail (kvůli aktuálnímu stavu)
  try {
    const o = await api(`objednavky.php?id=${id}`);
    if (!o.lze_editovat) {
      alert(o.duvod_zamceni || 'Objednávku už nelze upravovat.');
      return;
    }
    // Stav editoru = mapa vyrobek_id → mnozstvi
    editorState = {
      objednavka: o,
      polozky: {},      // { vyrobek_id: mnozstvi }
      misto_id: o.misto_dodani_id,
      poznamka: o.poznamka || '',
    };
    for (const p of (o.polozky || [])) {
      editorState.polozky[p.vyrobek_id] = parseFloat(p.mnozstvi);
    }
    renderEditorObjednavky();
  } catch (e) {
    alert('Chyba: ' + e.message);
  }
};

function renderEditorObjednavky() {
  const o = editorState.objednavka;
  const vyrobky = state.vyrobky;

  // Sestav řádky aktuálních položek (z editorState)
  const rows = Object.entries(editorState.polozky).map(([vid, mn]) => {
    const v = vyrobky.find(x => x.id == vid);
    if (!v) return '';
    return `
      <div class="editor-item-row" data-vid="${v.id}">
        <div>
          <strong>${esc(v.nazev)}</strong>
          <div style="font-size:12px;color:var(--text-3)">${fmt(v.cena_bez_dph)} / ${esc(v.jednotka || 'ks')}</div>
        </div>
        <div class="qty">
          <button class="qty-btn" onclick="editorZmenMnozstvi(${v.id}, -1)">−</button>
          <span class="qty-val">${mn}</span>
          <button class="qty-btn" onclick="editorZmenMnozstvi(${v.id}, 1)">+</button>
        </div>
        <button class="x-btn" title="Odebrat" onclick="editorOdeber(${v.id})">×</button>
      </div>
    `;
  }).join('');

  // Spočti součet
  let bezDph = 0, dph = 0;
  for (const [vid, mn] of Object.entries(editorState.polozky)) {
    const v = vyrobky.find(x => x.id == vid);
    if (!v) continue;
    const c = parseFloat(v.cena_bez_dph) * mn;
    bezDph += c;
    dph += c * (parseFloat(v.dph) / 100);
  }

  // Volné výrobky pro přidání
  const dostupneVyrobky = vyrobky
    .filter(v => !editorState.polozky[v.id])
    .map(v => `<option value="${v.id}">${esc(v.nazev)} — ${fmt(v.cena_bez_dph)}</option>`)
    .join('');

  // Místa dodání
  const mistaOpts = (state.mistaDodani || [])
    .map(m => `<option value="${m.id}" ${m.id == editorState.misto_id ? 'selected' : ''}>${esc(m.nazev)}${m.mesto ? ', ' + esc(m.mesto) : ''}</option>`)
    .join('');

  const html = `
    <div class="modal-head">
      <h2>Úprava objednávky <strong>${esc(o.cislo)}</strong></h2>
      <button class="modal-close" onclick="closeModal()"></button>
    </div>

    <p style="color:var(--text-3);font-size:13px;margin-bottom:14px">
      Upravte množství, přidejte nebo odeberte položky. Po uložení dorazí emailová notifikace.
    </p>

    ${state.mistaDodani && state.mistaDodani.length > 1 ? `
      <div class="form-row" style="margin-bottom:14px">
        <label class="form-label">📍 Místo dodání</label>
        <select class="input" id="ed-misto">${mistaOpts}</select>
      </div>
    ` : ''}

    ${rows || '<p style="color:var(--text-3);text-align:center;padding:16px">Žádné položky. Přidejte alespoň jednu.</p>'}

    <div class="editor-add-row">
      <select class="input" id="ed-pridat-vyrobek">
        <option value="">— Přidat výrobek —</option>
        ${dostupneVyrobky}
      </select>
      <button class="btn-secondary" onclick="editorPridejVybrany()">+ Přidat</button>
    </div>

    <div class="form-row" style="margin-top:14px">
      <label class="form-label">Poznámka</label>
      <textarea class="input" id="ed-poznamka" rows="2" placeholder="Volitelná poznámka">${esc(editorState.poznamka)}</textarea>
    </div>

    <div style="margin-top:14px;padding:12px;background:var(--surface-2);border-radius:8px">
      <div style="display:flex;justify-content:space-between;font-size:13px"><span>Bez DPH</span><span>${fmt(bezDph)}</span></div>
      <div style="display:flex;justify-content:space-between;font-size:13px"><span>DPH</span><span>${fmt(dph)}</span></div>
      <div style="display:flex;justify-content:space-between;font-size:16px;font-weight:600;margin-top:6px;padding-top:6px;border-top:1px solid var(--border)"><span>Celkem</span><span>${fmt(bezDph + dph)}</span></div>
    </div>

    <div class="modal-actions">
      <button class="btn-secondary" onclick="zobrazDetailObjednavky(${o.id})">Zpět na detail</button>
      <div class="grow"></div>
      <button class="btn-primary" onclick="ulozUpravuObjednavky()">💾 Uložit změny</button>
    </div>
  `;
  openModal(html);
}

window.editorZmenMnozstvi = function(vid, delta) {
  const stary = editorState.polozky[vid] || 0;
  const novy = stary + delta;
  if (novy <= 0) {
    delete editorState.polozky[vid];
  } else {
    editorState.polozky[vid] = novy;
  }
  renderEditorObjednavky();
};

window.editorOdeber = function(vid) {
  delete editorState.polozky[vid];
  renderEditorObjednavky();
};

window.editorPridejVybrany = function() {
  const sel = document.getElementById('ed-pridat-vyrobek');
  const vid = parseInt(sel.value);
  if (!vid) return;
  editorState.polozky[vid] = 1;
  // Zachovej poznámku z UI před překreslením
  const pozn = document.getElementById('ed-poznamka');
  if (pozn) editorState.poznamka = pozn.value;
  const misto = document.getElementById('ed-misto');
  if (misto) editorState.misto_id = parseInt(misto.value);
  renderEditorObjednavky();
};

window.ulozUpravuObjednavky = async function() {
  const o = editorState.objednavka;
  // Sesbírej UI hodnoty
  const pozn = document.getElementById('ed-poznamka');
  if (pozn) editorState.poznamka = pozn.value;
  const misto = document.getElementById('ed-misto');
  if (misto) editorState.misto_id = parseInt(misto.value);

  const polozky = Object.entries(editorState.polozky)
    .map(([vid, mn]) => ({ vyrobek_id: parseInt(vid), mnozstvi: parseFloat(mn) }))
    .filter(p => p.mnozstvi > 0);

  if (polozky.length === 0) {
    alert('Objednávka musí obsahovat alespoň jednu položku');
    return;
  }

  try {
    await api('objednavky.php', {
      method: 'PUT',
      body: JSON.stringify({
        id: o.id,
        misto_dodani_id: editorState.misto_id,
        poznamka: editorState.poznamka.trim() || null,
        polozky,
      }),
    });
    closeModal();
    alert('Objednávka byla upravena. Email s potvrzením byl odeslán.');
    renderHistory();
  } catch (e) {
    alert('Chyba: ' + e.message);
  }
};
