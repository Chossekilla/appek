// =============================================================
// BOOTSTRAP
// =============================================================
(async function() {
  try {
    // Zjistím, kdo jsem (vrací 401, pokud nepřihlášený)
    const me = await api('whoami.php');
    // 🐛 v3.0.644 — PIN (POS) session nemá přístup do administrace → zůstaň na login obrazovce
    if (me && Number(me.pos_only) === 1) throw new Error('pos_only');
    state.admin = me;
    localStorage.setItem('adminInfo', JSON.stringify(me));
    showApp();
  } catch (e) {
    // Není přihlášený - zůstane login screen
    localStorage.removeItem('adminInfo');
  }
})();

