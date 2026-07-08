/* 🌍 v3.0.420 — POS i18n bootstrap.
 *
 * POS (admin/pos.php i standalone pos/) dřív NEmělo i18n vůbec = česky-only.
 * Tento skript zapojí SDÍLENÝ admin slovník bez zásahu do admin i18n enginu:
 *   1. POS zdědí jazyk z localStorage 'appek_lang' (nastavený v adminu).
 *   2. Lazy-load i18n.js + i18n_auto.js (+ i18n_extra.js pro sk/de) ze složky
 *      admin/ (skript se sám zorientuje podle své vlastní src → funguje z
 *      admin/pos.php i z pos/index.php přes ../admin/pos-i18n.js).
 *   3. translatePage(document.body) + VLASTNÍ MutationObserver na document.body —
 *      admin observer hlídá jen #content/#modal, které v POS nejsou, a kasa se
 *      hodně překresluje (košík, modaly, účtenky).
 *
 * CS (default) = žádný load, HTML je nativně česky → nulová zátěž pro ~80 % bázi.
 */
(function () {
  var lang = 'cs';
  try { lang = localStorage.getItem('appek_lang') || 'cs'; } catch (e) {}
  if (lang === 'cs') return;

  var me = (document.currentScript && document.currentScript.src) || '';
  var base = me.replace(/[^/]*$/, '');            // .../admin/
  var ver = (me.match(/[?&]v=([0-9.]+)/) || [])[1] || '0';

  function load(src) {
    return new Promise(function (res) {
      var s = document.createElement('script');
      s.src = base + src + '?v=' + ver;
      s.onload = res; s.onerror = res;
      document.head.appendChild(s);
    });
  }

  var need = ['i18n.js', 'i18n_auto.js'];
  if (lang === 'sk' || lang === 'de') need.push('i18n_extra.js');

  (async function () {
    for (var i = 0; i < need.length; i++) await load(need[i]);

    function apply(root) {
      if (typeof window.translatePage === 'function') {
        try { window.translatePage(root || document.body); } catch (e) {}
      }
    }
    function boot() {
      apply(document.body);
      try {
        var obs = new MutationObserver(function (muts) {
          if (window.appekCurrentLang === 'cs') return;
          for (var i = 0; i < muts.length; i++) {
            var an = muts[i].addedNodes;
            for (var j = 0; j < an.length; j++) {
              if (an[j].nodeType === 1) apply(an[j]);
            }
          }
        });
        obs.observe(document.body, { childList: true, subtree: true });
      } catch (e) {}
      // pojistka pro pomalu rendrované komponenty (katalog, floor plan)
      setTimeout(function () { apply(document.body); }, 600);
    }
    if (document.readyState === 'loading') {
      document.addEventListener('DOMContentLoaded', boot);
    } else {
      boot();
    }
  })();
})();
