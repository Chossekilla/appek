/**
 * APPEK B2B - administrace
 *
 * ⚠️ admin/admin.js je GENEROVANÝ z admin/src/*.js (concat ve scripts/build-update.sh).
 *    NEEDITUJ admin/admin.js přímo — při buildu se přepíše! Edituj admin/src/<NNNN-sekce>.js.
 *    Viz admin/src/README.md.
 */

// 🆕 v2.0.83 — SELF-HEALING STALE CODE DETECTION
// Embedded BUILD_VERSION matchne to co se buildlo (auto-bumped přes build-zip.sh sed).
// Po boot porovnáme s API_VERSION (z config.php). Pokud admin.js < config.php → stale.
// Automaticky spustí cache clear + reload, aby user nikdy nezůstal trčet na starém kódu.
const APPEK_ADMIN_JS_VERSION = '3.0.410';

// ⚡ v3.0.252 — Odlehčený režim (volba výkonu v Nastavení): aplikuj z localStorage co nejdřív (bez bliknutí)
(function applyPerfLite() {
  try {
    if (localStorage.getItem('appek_perf_lite') === '1') {
      const set = () => { if (document.body) document.body.classList.add('perf-lite'); };
      if (document.body) set(); else document.addEventListener('DOMContentLoaded', set);
    }
  } catch (e) {}
})();

(async function detectStaleCode() {
  try {
    // Skip pokud běžíme přes installer/recovery
    if (location.search.includes('_freshcache=') || location.pathname.endsWith('clear-cache.html')
        || location.pathname.endsWith('verify-version.php') || location.pathname.endsWith('force-update.html')) return;

    // Skip pokud build version je placeholder (lokální dev)
    if (APPEK_ADMIN_JS_VERSION.startsWith('__APPEK_')) return;

    // Načti manifest (no-cache) — říká co je SKUTEČNĚ na disku.
    // 🐛 v3.0.402 — PRIMÁRNĚ api/version.php (PHP, vždy 200, no-cache, CDN necachuje).
    //   OBA manifest .json na Hostingeru/LiteSpeed vrací 403 (blokuje statické .json)
    //   → detektor byl na produkci slepý a klienti se starým SW trčeli na staré verzi
    //   (footer ukazoval jinou verzi než backend). Manifesty = fallback pro instalace,
    //   kde by version.php nebyl dostupný.
    let manifestVersion = null;
    for (const mf of ['../api/version.php', '../api/update-manifest.json', '../api/.update-manifest.json']) {
      try {
        const r = await fetch(mf + '?t=' + Date.now(), { cache: 'no-store', credentials: 'same-origin' });
        if (r.ok) { const j = await r.json(); if (j && j.version) { manifestVersion = j.version; break; } }
      } catch (e) { /* zkus další */ }
    }

    // Porovnej semver: APPEK_ADMIN_JS_VERSION (z loaded JS) vs manifestVersion (z disk manifest)
    const semverCompare = (a, b) => {
      const pa = String(a).split('.').map(n => parseInt(n, 10) || 0);
      const pb = String(b).split('.').map(n => parseInt(n, 10) || 0);
      for (let i = 0; i < Math.max(pa.length, pb.length); i++) {
        const x = pa[i] || 0, y = pb[i] || 0;
        if (x < y) return -1;
        if (x > y) return 1;
      }
      return 0;
    };

    if (manifestVersion && semverCompare(APPEK_ADMIN_JS_VERSION, manifestVersion) < 0) {
      // STALE CODE detected! Disk má novější verzi než loaded admin.js.
      console.warn('[APPEK] Stale code detected: loaded v' + APPEK_ADMIN_JS_VERSION + ' < disk v' + manifestVersion + '. Triggering cache clear...');
      // Marker pro post-clean reload
      try { localStorage.setItem('appek_stale_recovery', JSON.stringify({ from: APPEK_ADMIN_JS_VERSION, to: manifestVersion, at: Date.now() })); } catch (e) {}

      // Clear SW + caches
      try {
        if ('serviceWorker' in navigator) {
          const regs = await navigator.serviceWorker.getRegistrations();
          for (const r of regs) await r.unregister();
        }
        if ('caches' in window) {
          const keys = await caches.keys();
          await Promise.all(keys.map(k => caches.delete(k)));
        }
      } catch (e) { console.warn('[APPEK] Cache clear partial fail:', e); }

      // Hard reload s cache-buster
      const url = new URL(location.href);
      url.searchParams.set('_freshcache', manifestVersion + '-' + Date.now());
      location.replace(url.toString());
    }
  } catch (e) {
    console.warn('[APPEK] Stale code detection skipped:', e);
  }
})();

// 🆕 v2.0.79 — Post-update confirmation banner
// Po úspěšném update se uloží flag do localStorage. Po refreshi se zobrazí
// nahoře Apple-style pill „✅ Aktualizováno na X.Y.Z" který za 4s zmizí.
(function showPostUpdateBanner() {
  try {
    const flag = localStorage.getItem('appek_post_update_ack');
    if (!flag) return;
    localStorage.removeItem('appek_post_update_ack');

    // Počkej na DOMContentLoaded — banner potřebuje body
    const showBanner = () => {
      const el = document.createElement('div');
      el.className = 'post-update-banner';
      el.innerHTML = `
        <span class="pu-icon">✅</span>
        <span class="pu-text">Aktualizováno na <strong>${flag.replace(/[^\d.v-]/g, '')}</strong></span>
        <button class="pu-close" onclick="this.parentNode.remove()" aria-label="Zavřít">×</button>
      `;
      document.body.appendChild(el);

      // Auto-hide po 4 sekundách
      setTimeout(() => { el.classList.add('pu-hide'); }, 4000);
      // Remove z DOM po fade-out
      setTimeout(() => { el.remove(); }, 4600);
    };

    if (document.readyState === 'loading') {
      document.addEventListener('DOMContentLoaded', showBanner);
    } else {
      showBanner();
    }

    // Inject CSS (jen jednou)
    if (!document.getElementById('post-update-banner-css')) {
      const s = document.createElement('style');
      s.id = 'post-update-banner-css';
      s.textContent = `
        .post-update-banner {
          position: fixed;
          top: 18px;
          left: 50%;
          transform: translateX(-50%) translateY(-8px);
          z-index: 99999;
          display: inline-flex;
          align-items: center;
          gap: 10px;
          background: linear-gradient(135deg, #d4edda, #c3e6cb);
          color: #155724;
          border: 1px solid #86EFAC;
          border-radius: 999px;
          padding: 10px 16px 10px 14px;
          font-family: -apple-system, BlinkMacSystemFont, "SF Pro Display", system-ui, sans-serif;
          font-size: 13.5px;
          font-weight: 600;
          box-shadow: 0 4px 16px rgba(34,197,94,0.25), 0 2px 4px rgba(0,0,0,0.08);
          opacity: 0;
          animation: puSlideIn 0.4s cubic-bezier(0.16, 1, 0.3, 1) forwards;
          max-width: 90vw;
          white-space: nowrap;
        }
        .post-update-banner .pu-icon { font-size: 16px; line-height: 1; }
        .post-update-banner .pu-text strong { font-weight: 800; font-family: 'SF Mono', Menlo, monospace; }
        .post-update-banner .pu-close {
          background: transparent; border: none; cursor: pointer;
          color: #155724; opacity: 0.5;
          width: 22px; height: 22px;
          font-size: 16px; font-weight: 400;
          border-radius: 50%;
          display: inline-flex; align-items: center; justify-content: center;
          padding: 0; margin-left: 4px;
          transition: all 0.15s;
        }
        .post-update-banner .pu-close:hover {
          opacity: 1; background: rgba(21,87,36,0.1);
        }
        .post-update-banner.pu-hide {
          animation: puSlideOut 0.5s cubic-bezier(0.4, 0, 1, 1) forwards;
        }
        @keyframes puSlideIn {
          from { opacity: 0; transform: translateX(-50%) translateY(-30px); }
          to   { opacity: 1; transform: translateX(-50%) translateY(0); }
        }
        @keyframes puSlideOut {
          from { opacity: 1; transform: translateX(-50%) translateY(0); }
          to   { opacity: 0; transform: translateX(-50%) translateY(-30px); }
        }
        @media (max-width: 480px) {
          .post-update-banner { font-size: 12.5px; padding: 8px 14px 8px 12px; top: 12px; }
          .post-update-banner .pu-icon { font-size: 14px; }
        }
        @media (prefers-color-scheme: dark) {
          html.theme-dark .post-update-banner {
            background: linear-gradient(135deg, rgba(48,209,88,0.18), rgba(48,209,88,0.10));
            color: #4ade80;
            border-color: rgba(48,209,88,0.4);
          }
        }
      `;
      document.head.appendChild(s);
    }
  } catch (e) { /* ignore — banner je nice-to-have */ }
})();

const API = '../api';
const state = {
  admin: null,
  current: 'dashboard',
  vyrobaMode: 'auto',
  // Pre-init pro balíčky (zabraňuje "state._cake is undefined" pokud něco volá konfigurátor přes event/timer)
  _cake: { porci: 10, prichut: null, dekorace: 'zadna', text: '', foto: '' },
  _catering: null,
};

