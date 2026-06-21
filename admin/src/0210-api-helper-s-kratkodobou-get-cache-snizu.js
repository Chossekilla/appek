// =============================================================
// 📡 API helper s krátkodobou GET cache (snižuje lag při přepínání stránek)
// =============================================================
const _apiCache = new Map();       // path → { data, expires }
const _apiCacheTTL = 4000;          // 4s — krátká doba, ať uživatel vidí čerstvá data po editaci
function _apiCacheKey(path) { return path; }
function apiCacheInvalidate(prefix = '') {
  if (!prefix) { _apiCache.clear(); return; }
  for (const k of _apiCache.keys()) if (k.startsWith(prefix)) _apiCache.delete(k);
}

// =============================================================
// 🗑️ DELETE CONFIRMATION — volitelně 2× potvrzení (proti omylem mazání)
// Uživatel si může v Nastavení vypnout druhý krok.
// =============================================================
// Persistence: localStorage klíč 'confirm_delete_2x' = '1' | '0' (default '1' = zapnuto)
window.getConfirmDelete2xEnabled = function() {
  try {
    const v = localStorage.getItem('confirm_delete_2x');
    return v === null ? true : v === '1';   // default ON
  } catch { return true; }
};
window.setConfirmDelete2xEnabled = function(on) {
  try { localStorage.setItem('confirm_delete_2x', on ? '1' : '0'); } catch {}
  // Aktualizuj vizuální status badge v Nastavení (pokud je viditelný)
  const badge = document.getElementById('ns-confirm-2x-status');
  if (badge) {
    badge.textContent = on ? '✓ Zapnuto' : '✕ Vypnuto';
    if (on) {
      badge.style.background = 'var(--success-bg)';
      badge.style.color = 'var(--success-text)';
    } else {
      badge.style.background = '#FEE2E2';
      badge.style.color = '#7F1D1D';
    }
  }
};

// 🆕 v3.0.190 — Mobilní rychlá akční tlačítka (FAB) on/off (Nastavení → Vzhled).
//   User: „rychlá volba swipovací z boku … dej možnost v adminu zapnout/vypnout zobrazení."
//   Per-zařízení (localStorage, jako téma/hustota); default ZAP.
window.getAppFabEnabled = function() {
  try {
    const v = localStorage.getItem('appFabEnabled');
    return v === null ? true : v === '1';   // default ON
  } catch { return true; }
};
window.setAppFabEnabled = function(on) {
  try { localStorage.setItem('appFabEnabled', on ? '1' : '0'); } catch {}
  const badge = document.getElementById('ns-fab-status');
  if (badge) {
    badge.textContent = on ? '✓ Zapnuto' : '✕ Vypnuto';
    badge.style.background = on ? 'var(--success-bg)' : '#FEE2E2';
    badge.style.color = on ? 'var(--success-text)' : '#7F1D1D';
  }
  // Okamžitě aplikuj — skryj/zobraz FAB bez reloadu.
  try {
    const fab = document.getElementById('app-fab');
    if (!on) { if (fab) fab.classList.add('is-hidden'); }
    else if (typeof window.updateAppFAB === 'function') window.updateAppFAB((window.state && state.current) || 'dashboard');
  } catch (e) {}
};

// Použití:
//   if (!await confirmDelete2x('výrobek "Bageta"')) return;
// nebo
//   if (!await confirmDelete2x({ co: 'fakturu č. 2026-001', detail: 'Faktura se nenávratně smaže.' })) return;
window.confirmDelete2x = async function(arg) {
  // Akceptuje string (jen název) i objekt { co, detail }
  const co = typeof arg === 'string' ? arg : (arg?.co || 'tuto položku');
  const detail = typeof arg === 'object' ? (arg?.detail || '') : '';

  // 1. krok — běžný prompt
  const krok1 = (await confirmDialog({ msg: t('confirm_delete_co', { co, detail: detail ? '\n\n' + detail : '' }), danger: false }));
  if (!krok1) return false;

  // 2. krok — definitivní potvrzení (nepřeskočitelné), JEN pokud je v nastavení zapnuté
  if (!getConfirmDelete2xEnabled()) return true;
  const krok2 = (await confirmDialog({ msg: t('confirm_delete_final_step', { co }), danger: false }));
  return krok2;
};

// 🔒 v3.0.228 — CSRF header helper pro raw fetch() volání mimo api() wrapper
// (exporty=blob, importy/uploady=FormData → nejdou přes api(), který dělá res.json()).
// Stejný zdroj tokenu jako api(). Pod CSRF strict módem MUSÍ raw POSTy token poslat.
function csrfHeaders(extra = {}) {
  const t = (typeof state !== 'undefined' && state && state.csrfToken) || localStorage.getItem('appek_csrf_token') || '';
  return t ? { 'X-CSRF-Token': t, ...extra } : { ...extra };
}

async function api(path, opts = {}) {
  const method = (opts.method || 'GET').toUpperCase();

  // Auto-stringify body pokud je objekt (ne FormData ani string)
  if (opts.body && typeof opts.body === 'object' && !(opts.body instanceof FormData)) {
    opts.body = JSON.stringify(opts.body);
  }

  // Cache jen pro GET requesty
  if (method === 'GET') {
    const key = _apiCacheKey(path);
    const cached = _apiCache.get(key);
    if (cached && cached.expires > Date.now()) {
      return cached.data;
    }
  }

  // 🔒 v2.6.0 SECURITY: pošli CSRF token v každém POST/PUT/DELETE
  const csrfToken = (typeof state !== 'undefined' && state && state.csrfToken) || localStorage.getItem('appek_csrf_token') || '';
  const baseHeaders = {};
  if (opts.body && !(opts.body instanceof FormData)) {
    baseHeaders['Content-Type'] = 'application/json';
  }
  if (csrfToken && method !== 'GET') {
    baseHeaders['X-CSRF-Token'] = csrfToken;
  }

  if (typeof topProgressStart === 'function') topProgressStart();
  let res, data;
  try {
    res = await fetch(`${API}/${path}`, {
      credentials: 'include',
      headers: { ...baseHeaders, ...(opts.headers || {}) },
      ...opts,
    });
    data = await res.json().catch(() => ({}));
    // 🔒 Uchyt CSRF token z přihlašovací odpovědi (admin_login.php)
    if (data && data.csrf_token) {
      try {
        if (typeof state !== 'undefined' && state) state.csrfToken = data.csrf_token;
        localStorage.setItem('appek_csrf_token', data.csrf_token);
      } catch (e) {}
    }
  } finally {
    if (typeof topProgressDone === 'function') topProgressDone();
  }
  // 🆕 v2.9.321 — Přilep request_id k error message, aby user mohl říct "rozbité, reqId=abc12345"
  // a admin to našel v Diagnostice → 🐛 Chyby aplikace přes search.
  if (!res.ok) {
    // 🔒 v3.0.228 — CSRF strict self-heal: zastaralý/chybějící token → obnov přes whoami (GET) a 1× zopakuj.
    // whoami vrací čerstvý session token → další pokus už projde. Uživatel nic nepozná.
    if (res.status === 403 && data && data.error === 'csrf_invalid' && !opts._csrfRetried) {
      try {
        const who = await fetch(`${API}/whoami.php`, { credentials: 'include' }).then(r => r.json()).catch(() => null);
        if (who && who.csrf_token) {
          if (typeof state !== 'undefined' && state) state.csrfToken = who.csrf_token;
          try { localStorage.setItem('appek_csrf_token', who.csrf_token); } catch (e) {}
          return await api(path, { ...opts, _csrfRetried: true });
        }
      } catch (e) { /* fall through na chybu níže */ }
    }
    const err = new Error(data.error || 'Chyba serveru');
    if (data.request_id) {
      err.message += ' (reqId: ' + data.request_id + ')';
      err.requestId = data.request_id;
    }
    if (data.debug) err.debug = data.debug;
    throw err;
  }

  // Ulož do cache po úspěšném GET
  if (method === 'GET') {
    _apiCache.set(_apiCacheKey(path), { data, expires: Date.now() + _apiCacheTTL });
  }
  // Při POST/PUT/DELETE invaliduj odpovídající GET cache, aby se po editaci načetlo čerstvé
  if (method !== 'GET') {
    apiCacheInvalidate(path.split('?')[0]);
  }
  return data;
}

