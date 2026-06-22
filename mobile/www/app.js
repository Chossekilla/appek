// APPEK mobile — login shell.
// Tok: e-mail → vendor resolve (Fáze 1A) → ulož install_url → otevři admin instalace ve webview.
// Heslo se zadává až na přihlašovací stránce instalace (vlastní origin → session cookie OK).
// Appka heslo NEŘEŠÍ. Capacitor Preferences s localStorage fallbackem (funguje i v prohlížeči).

const VENDOR = 'https://vendor.appek.cz/resolve.php';
const Prefs = (window.Capacitor && window.Capacitor.Plugins && window.Capacitor.Plugins.Preferences) || null;
const $ = (id) => document.getElementById(id);

async function prefGet(k) {
  try { return Prefs ? (await Prefs.get({ key: k })).value : localStorage.getItem(k); }
  catch (e) { return null; }
}
async function prefSet(k, v) {
  try { if (Prefs) await Prefs.set({ key: k, value: v }); else localStorage.setItem(k, v); }
  catch (e) {}
}

function openInstall(url, email) {
  const u = String(url).replace(/\/+$/, '') + '/admin/' + (email ? ('?app_email=' + encodeURIComponent(email)) : '');
  window.location.href = u;
}
function showErr(m) { const e = $('err'); e.textContent = m; e.hidden = false; }

// Při startu: pokud už známe instalaci, jdi rovnou tam (session na instalaci rozhodne login).
async function boot() {
  const url = await prefGet('install_url');
  if (url) openInstall(url, (await prefGet('email')) || '');
}

$('go').addEventListener('click', async () => {
  const email = ($('email').value || '').trim().toLowerCase();
  $('err').hidden = true;
  if (!/^[^@\s]+@[^@\s]+\.[^@\s]+$/.test(email)) return showErr('Zadej platný e-mail.');
  $('go').disabled = true; $('go').textContent = 'Hledám…';
  try {
    const r = await fetch(VENDOR, { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ email }) });
    const d = await r.json();
    const installs = (d && d.installs) || [];
    if (!installs.length) return showErr('E-mail jsme nenašli. Zkus „zadat adresu ručně" nebo kontaktuj dodavatele.');
    let chosen = installs[0];
    if (installs.length > 1) {
      const txt = installs.map((x, i) => `${i + 1}) ${x.nazev || x.url}`).join('\n');
      const n = parseInt(prompt('Víc instalací — vyber číslo:\n' + txt, '1'), 10);
      chosen = installs[n - 1];
      if (!chosen) return;
    }
    await prefSet('install_url', chosen.url);
    await prefSet('email', email);
    openInstall(chosen.url, email);
  } catch (e) {
    showErr('Chyba spojení: ' + e.message);
  } finally {
    $('go').disabled = false; $('go').textContent = 'Pokračovat →';
  }
});

$('manual').addEventListener('click', async () => {
  const url = prompt('Adresa tvé APPEK instalace (https://…):', 'https://');
  if (!url || !/^https?:\/\//.test(url)) return;
  await prefSet('install_url', url);
  openInstall(url, ($('email').value || '').trim().toLowerCase());
});

boot();
