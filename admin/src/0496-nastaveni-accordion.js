// =============================================================
// 🆕 v3.0.601 — NASTAVENÍ: karty jako accordion
// =============================================================
// Karty v každém tabu Nastavení jsou sbalené na nadpis, klik rozbalí. Otevřené se pamatují
// (localStorage per nadpis) → po uložení / přepnutí tabu zůstane, jak to uživatel nechal.
// Vylepšuje hotové DOM (MutationObserver nad #content) → renderNastaveni ani async karty se nemění.
// Tab s jedinou kartou (Balíčky) a nápověda (<details>) zůstávají, jak jsou.
(function () {
  const LS_KEY = 'ns_acc_open';
  let openSet;
  try { openSet = new Set(JSON.parse(localStorage.getItem(LS_KEY) || '[]')); } catch (e) { openSet = new Set(); }
  const save = () => { try { localStorage.setItem(LS_KEY, JSON.stringify([...openSet])); } catch (e) {} };

  if (!document.getElementById('ns-acc-styles')) {
    const s = document.createElement('style');
    s.id = 'ns-acc-styles';
    s.textContent = `
      .ns-acc > .ns-acc-head { cursor: pointer; position: relative; padding-right: 30px; user-select: none; }
      .ns-acc > .ns-acc-head::after { content: '▾'; position: absolute; right: 2px; top: 50%; transform: translateY(-50%);
        font-size: 18px; line-height: 1; color: var(--text-3); transition: transform .15s; }
      .ns-acc.ns-collapsed > .ns-acc-head::after { transform: translateY(-50%) rotate(-90deg); }
      .ns-acc.ns-collapsed > :not(.ns-acc-head) { display: none !important; }
      .ns-acc.ns-collapsed > .ns-acc-head, .ns-acc.ns-collapsed > .ns-acc-head h2,
      .ns-acc.ns-collapsed > .ns-acc-head h3 { margin-bottom: 0 !important; }
    `;
    document.head.appendChild(s);
  }

  const keyOf = (h) => (h.textContent || '').trim().split('\n')[0].trim().slice(0, 60);

  function headOf(card) {
    const h = card.querySelector('h2, h3, h4');
    if (!h) return null;
    let el = h;
    while (el.parentElement && el.parentElement !== card) el = el.parentElement;
    return el.parentElement === card ? { head: el, title: h } : null;
  }

  function enhance() {
    const page = document.querySelector('#content .nastaveni-page');
    if (!page) return;
    const cards = [...page.querySelectorAll('.card-block')]
      .filter(c => c.tagName !== 'DETAILS' && !c.parentElement.closest('.card-block'));
    const items = cards.map(c => ({ c, hh: headOf(c) })).filter(x => x.hh);
    if (items.length < 2) return;
    items.forEach(({ c, hh }) => {
      if (c.dataset.nsAcc) return;
      const key = keyOf(hh.title);
      c.dataset.nsAcc = key;
      c.classList.add('ns-acc');
      hh.head.classList.add('ns-acc-head');
      if (!openSet.has(key)) c.classList.add('ns-collapsed');
      hh.head.addEventListener('click', (ev) => {
        if (ev.target.closest('button, a, input, select, textarea, label')) return;
        nsAccToggle(c, c.classList.contains('ns-collapsed'));
      });
    });
  }

  function nsAccToggle(card, open) {
    card.classList.toggle('ns-collapsed', !open);
    const key = card.dataset.nsAcc;
    if (key) { open ? openSet.add(key) : openSet.delete(key); save(); }
  }

  // Deep-link (gotoNastaveniBlok) → rozbal kartu, ve které cíl leží
  window.nsAccOpen = function (el) {
    const card = el && el.closest && el.closest('.ns-acc');
    if (card && card.classList.contains('ns-collapsed')) nsAccToggle(card, true);
  };

  let raf = 0;
  const start = () => {
    const root = document.getElementById('content');
    if (!root) return setTimeout(start, 300);
    new MutationObserver(() => {
      if (raf) return;
      raf = requestAnimationFrame(() => { raf = 0; enhance(); });
    }).observe(root, { childList: true, subtree: true });
    enhance();
  };
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', start);
  else start();
})();
