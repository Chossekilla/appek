/**
 * 🌍 FEATURE LANG — sdílený i18n helper pro standalone admin/feature-*.html stránky.
 *
 * Použití:
 *   <script src="_feature_lang.js"></script>
 *   <script>
 *     const T = {
 *       title:  { cs: 'Alergeny', en: 'Allergens', es: 'Alérgenos' },
 *       export: { cs: 'Export',   en: 'Export',    es: 'Exportar'    },
 *     };
 *     const t = key => featureLang.t(T[key] || key);
 *     document.title = t('title');
 *     featureLang.renderPills();
 *   </script>
 *
 * Jazyk se čte z localStorage (klíč 'appek_feature_lang') s fallbackem na
 * navigator.language → 'cs'. Změna jazyka přes pill voláním featureLang.set()
 * reloaduje stránku.
 */
(function() {
  'use strict';

  const SUPPORTED = ['cs', 'en', 'es'];
  const FLAGS = { cs: '🇨🇿', en: '🇬🇧', es: '🇪🇸' };

  function detectLang() {
    try {
      const stored = localStorage.getItem('appek_feature_lang');
      if (stored && SUPPORTED.includes(stored)) return stored;
    } catch (e) {}
    const browser = (navigator.language || 'cs').slice(0, 2).toLowerCase();
    return SUPPORTED.includes(browser) ? browser : 'cs';
  }

  let current = detectLang();

  function t(input) {
    if (input == null) return '';
    if (typeof input === 'string') return input;
    if (typeof input === 'object') return input[current] || input.cs || input.en || Object.values(input)[0] || '';
    return String(input);
  }

  function set(lang) {
    if (!SUPPORTED.includes(lang) || lang === current) return;
    try { localStorage.setItem('appek_feature_lang', lang); } catch (e) {}
    current = lang;
    location.reload();
  }

  function get() { return current; }

  function renderPills() {
    if (document.querySelector('.appek-lang-pills')) return;
    const wrap = document.createElement('div');
    wrap.className = 'appek-lang-pills';
    wrap.setAttribute('role', 'group');
    wrap.setAttribute('aria-label', 'Language');
    wrap.innerHTML = SUPPORTED.map(l => `
      <button type="button" data-lang="${l}" class="appek-lang-pill ${l === current ? 'active' : ''}" title="${l.toUpperCase()}">
        <span class="appek-flag">${FLAGS[l]}</span>
        <span class="appek-code">${l.toUpperCase()}</span>
      </button>
    `).join('');
    wrap.addEventListener('click', e => {
      const btn = e.target.closest('[data-lang]');
      if (btn) set(btn.dataset.lang);
    });
    document.body.appendChild(wrap);

    // CSS — inject once
    if (!document.getElementById('appek-lang-pills-css')) {
      const s = document.createElement('style');
      s.id = 'appek-lang-pills-css';
      s.textContent = `
        .appek-lang-pills { position: fixed; top: 14px; right: 14px; display: flex; gap: 4px; z-index: 9999; }
        .appek-lang-pill { display: inline-flex; align-items: center; gap: 4px; padding: 5px 10px; border-radius: 999px;
          border: 1px solid rgba(0,0,0,0.08); cursor: pointer; background: rgba(255,255,255,0.95);
          color: #6e6e73; font-size: 11px; font-weight: 700; font-family: inherit;
          box-shadow: 0 1px 4px rgba(0,0,0,0.06); transition: all 0.15s; backdrop-filter: blur(6px); }
        .appek-lang-pill:hover { color: #1d1d1f; transform: translateY(-1px); }
        .appek-lang-pill.active { background: linear-gradient(180deg, #BA7517, #854F0B); color: #fff; border-color: transparent; }
        .appek-flag { font-size: 13px; line-height: 1; }
        @media (max-width: 600px) {
          .appek-lang-pills { top: 8px; right: 8px; }
          .appek-lang-pill { padding: 4px 8px; }
          .appek-code { display: none; }
        }
      `;
      document.head.appendChild(s);
    }
  }

  /**
   * Translate all elements with data-i18n="key" — hodnota key musí být v okenním T objektu.
   * Pokud máš jednoduché DOM s data-i18n, zavoláš tohle po renderu.
   */
  function applyToDom() {
    if (!window.T) return;
    document.querySelectorAll('[data-i18n]').forEach(el => {
      const key = el.getAttribute('data-i18n');
      if (window.T[key]) el.textContent = t(window.T[key]);
    });
    document.querySelectorAll('[data-i18n-placeholder]').forEach(el => {
      const key = el.getAttribute('data-i18n-placeholder');
      if (window.T[key]) el.setAttribute('placeholder', t(window.T[key]));
    });
    document.querySelectorAll('[data-i18n-title]').forEach(el => {
      const key = el.getAttribute('data-i18n-title');
      if (window.T[key]) el.setAttribute('title', t(window.T[key]));
    });
  }

  window.featureLang = { t, set, get, renderPills, applyToDom, supported: SUPPORTED };
})();
