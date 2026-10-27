// =============================================================
// READ-ONLY (náhledový režim) — trvalá lišta
// =============================================================
// Když je přihlášený účet role='readonly' (veřejné demo / náhledový účet),
// ukáže dole trvalou lištu. Fixní dole = bezpečné vůči fixnímu topbaru/sidebaru
// (žádné posouvání layoutu). Čistý text bez emoji (demo koukají i lidi na Windows).
// Backend blokuje mutace (require_admin) + json_error hlášku appka ukáže sama.
(function () {
  function ensureBanner() {
    // stav se plní async (whoami/login) → čekej než bude.
    // POZOR: appka používá bare `state` (const), NE window.state → čti bezpečně přes typeof.
    var st = (typeof state !== 'undefined') ? state : null;
    if (!st || !st.admin) return false;
    if ((st.admin.role || '') !== 'readonly') return true;    // není readonly → hotovo
    if (document.getElementById('ro-banner')) return true;    // už je

    var b = document.createElement('div');
    b.id = 'ro-banner';
    b.innerHTML = 'Náhledový režim — prohlížíš demo, akce jsou vypnuté. '
      + 'Chceš plný přístup? Napiš na <a href="mailto:info@appek.cz">info@appek.cz</a>.';
    b.style.cssText = 'position:fixed;left:0;right:0;bottom:0;z-index:100000;'
      + 'background:linear-gradient(180deg,#9a5c0d,#7a4708);color:#fff;text-align:center;'
      + 'font-size:13px;font-weight:600;padding:9px 16px;line-height:1.4;'
      + 'box-shadow:0 -2px 12px rgba(0,0,0,.18);font-family:-apple-system,BlinkMacSystemFont,Segoe UI,Arial,sans-serif';
    var a = b.querySelector('a');
    if (a) a.style.cssText = 'color:#fff;text-decoration:underline;font-weight:700';
    document.body.appendChild(b);
    return true;
  }

  var tries = 0;
  var iv = setInterval(function () {
    if (ensureBanner() || tries++ > 80) clearInterval(iv);
  }, 200);
})();

// 🔒 v3.0.611 — HACCP na veřejném demu zamčený (náhledový účet na demo.appek.cz). Backend to vynucuje
//   taky (_admin_auth.php, APPEK_DEMO_MODE) — tohle je jen srozumitelná obrazovka místo chyb 403.
window.demoHaccpLocked = function () {
  var st = (typeof state !== 'undefined') ? state : null;
  return !!(st && st.admin && st.admin.role === 'readonly' && /^demo\./i.test(location.hostname));
};
window.renderDemoHaccpLock = function () {
  var c = document.getElementById('content');
  if (!c) return;
  c.innerHTML = '<div class="page-head"><div><h1 class="page-title">📋 HACCP</h1>'
    + '<p class="page-sub">Dokumentace, produktové listy, grafy technologických postupů</p></div></div>'
    + '<div class="card-block" style="max-width:640px;margin:24px auto;text-align:center;padding:36px 24px">'
    + '<div style="font-size:44px;line-height:1;margin-bottom:12px">🔒</div>'
    + '<h2 style="margin:0 0 8px;font-size:20px">HACCP je dostupné v plné verzi</h2>'
    + '<p style="margin:0 0 18px;color:var(--text-2);font-size:14px;line-height:1.6">Kompletní HACCP dokumentace firmy, produktové listy výrobků s alergeny, '
    + 'grafy technologických postupů, interní audity a tisk příručky. V demu je tahle část zamčená.</p>'
    + '<a class="btn-primary" href="mailto:info@appek.cz?subject=HACCP%20v%20APPEK" style="text-decoration:none;display:inline-block;padding:12px 22px;font-size:15px">Chci plný přístup — info@appek.cz</a>'
    + '</div>';
};
