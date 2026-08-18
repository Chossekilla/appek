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
