// =============================================================
// 📌 PIN sidebar — fixuje boční menu (uloženo do localStorage)
// =============================================================
// 🐛 v3.0.50 — REVERT: smazána veškerá mobile-rail-hidden logika
// (hideMobileSidebar/showMobileSidebar/restore — user reportoval že je nefunkční +
//  floating ≡ button se zobrazoval i přes login screen). Necháváme jen existující
//  sidebar-pin (📌) jako jediný mobile toggle.

window.toggleSidebarPin = function() {
  const isOn = document.body.classList.toggle('sidebar-pinned');
  localStorage.setItem('sidebarPinned', isOn ? '1' : '0');
};
// Init při startu
(function initSidebarPin() {
  if (localStorage.getItem('sidebarPinned') === '1') {
    document.body.classList.add('sidebar-pinned');
  }
})();

// 🔲 Collapse sidebar — zúží menu na ikonu-only mode (jen PC)
//    🆕 v2.6.5 — REMOVED megamenu modal (user: "modal zruš!")
//                Tlačítko prostě toggluje sidebar collapsed ↔ expanded, žádný modal.
window.toggleSidebarCollapse = function() {
  const isOn = document.body.classList.toggle('sidebar-collapsed');
  try { localStorage.setItem('sidebarCollapsed', isOn ? '1' : '0'); } catch (e) {}
  document.querySelectorAll('.sidebar-nav .nav-item, .nav-section .nav-item').forEach(el => {
    if (!el.hasAttribute('data-tooltip')) {
      const labelEl = el.querySelector('span:not(.nav-icon):not(.pkg-lock)');
      if (labelEl) el.setAttribute('data-tooltip', labelEl.textContent.trim());
    }
  });
};

// 🆕 v2.6.5 — Megamenu funkce stub'd (kdyby ji někdo volal z legacy kódu, no-op)
window.openMegaMenu = function() { /* removed in v2.6.5 */ };
window.closeMegaMenu = function() {
  document.body.classList.remove('megamenu-open');
};

window.navigateFromMegaMenu = function(page) {
  closeMegaMenu();
  if (typeof navigate === 'function') {
    setTimeout(() => navigate(page), 50);
  }
};
(function initSidebarCollapse() {
  if (localStorage.getItem('sidebarCollapsed') === '1') {
    document.body.classList.add('sidebar-collapsed');
  }
  // Run tooltip setup once on DOM ready
  document.addEventListener('DOMContentLoaded', () => {
    document.querySelectorAll('.sidebar-nav .nav-item, .nav-section .nav-item').forEach(el => {
      if (!el.hasAttribute('data-tooltip')) {
        const labelEl = el.querySelector('span:not(.nav-icon):not(.pkg-lock)');
        if (labelEl) el.setAttribute('data-tooltip', labelEl.textContent.trim());
      }
    });
  });
})();

