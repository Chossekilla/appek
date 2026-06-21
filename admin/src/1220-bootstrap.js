// =============================================================
// BOOTSTRAP
// =============================================================
(async function() {
  try {
    // Zjistím, kdo jsem (vrací 401, pokud nepřihlášený)
    const me = await api('whoami.php');
    state.admin = me;
    localStorage.setItem('adminInfo', JSON.stringify(me));
    showApp();
  } catch (e) {
    // Není přihlášený - zůstane login screen
    localStorage.removeItem('adminInfo');
  }
})();

