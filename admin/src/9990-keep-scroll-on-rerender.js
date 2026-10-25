// =============================================================
// 🧭 v3.0.591 — ZACHOVAT POZICI PŘI PŘEKRESLENÍ (celá aplikace)
// =============================================================
// User: „to posouvání se nahoru po změně, takže nevíš, kde jsi na stránce byl — projdi celou aplikaci".
// Příčina: stovky míst po změně (přepínač, uložení, filtr, stránkování) volají renderXxx() nebo
// navigate(state.current) → innerHTML stránky znovu → dokument se na okamžik zkrátí → body.scrollTop = 0.
// Jen menšina míst používá keepScroll()/keepAnchor() (0440). Místo oprav ~stovek volání jedno centrální pravidlo:
//   • PŘECHOD na jinou stránku (nebo klik v menu)  → začni nahoře (očekávané).
//   • PŘEKRESLENÍ stránky, na které uživatel už je → zachovej pozici (keepScroll).
// Musí být POSLEDNÍ soubor v admin/src (obaluje už definované globální funkce). Admin scrolluje <body>.
(function () {
  if (typeof window.keepScroll !== 'function' || typeof window.navigate !== 'function') return;

  let navDepth = 0;      // > 0 = právě běží přechod přes navigate() → render uvnitř nepřebírat
  let userNav = false;   // klik v menu = uživatel chce „novou" stránku od začátku

  document.addEventListener('click', (e) => {
    if (e.target && e.target.closest && e.target.closest('.nav-item, .bottom-nav-item, .sidebar-pkg-icon, .nastroje-card, .btn-back')) userNav = true;
  }, true);

  const toTop = () => { try { document.body.scrollTop = 0; (document.scrollingElement || document.documentElement).scrollTop = 0; } catch (e) {} };

  const origNavigate = window.navigate;
  window.navigate = function (page, args) {
    const samePage = page === state.current;
    const fromMenu = userNav;
    userNav = false;
    navDepth++;
    let ret;
    try {
      ret = (samePage && !fromMenu)
        ? window.keepScroll(() => origNavigate.call(this, page, args))
        : origNavigate.call(this, page, args);
    } catch (e) { navDepth--; throw e; }
    const done = () => { navDepth = Math.max(0, navDepth - 1); };
    if (ret && typeof ret.then === 'function') ret.then(done, done); else done();
    if (!samePage || fromMenu) toTop();
    return ret;
  };

  // Globální renderXxx (function deklarace i window.renderXxx = …) — volané MIMO přechod = překreslení na místě
  const content = () => document.getElementById('content');
  Object.getOwnPropertyNames(window).forEach((name) => {
    if (!/^render[A-Z]/.test(name)) return;
    let fn;
    try { fn = window[name]; } catch (e) { return; }
    if (typeof fn !== 'function' || fn.__keepScrollWrapped) return;
    const wrapped = function () {
      const c = content();
      const inPlace = navDepth === 0 && c && c.childElementCount > 0 && c.dataset.page === state.current;
      return inPlace ? window.keepScroll(() => fn.apply(this, arguments)) : fn.apply(this, arguments);
    };
    wrapped.__keepScrollWrapped = true;
    try { window[name] = wrapped; } catch (e) { /* nepřepisovatelné — nech být */ }
  });
})();
