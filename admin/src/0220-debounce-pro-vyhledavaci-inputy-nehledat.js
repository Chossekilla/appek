// =============================================================
// 🚀 DEBOUNCE — pro vyhledávací inputy (nehledat při každém znaku)
// =============================================================
window._debounceTimers = window._debounceTimers || {};
window.debounce = function(key, fn, delay = 250) {
  clearTimeout(window._debounceTimers[key]);
  window._debounceTimers[key] = setTimeout(fn, delay);
};

// =============================================================
// 🎨 THEME SYSTEM — výchozí / win98 / tmavý
// Aplikuje se přes html.theme-{id}. Pure CSS, žádné zásahy do funkčnosti.
// =============================================================
const APP_THEMES = ['default', 'win98', 'dark', 'apple'];
window.getAppTheme = function() {
  try {
    const t = localStorage.getItem('appTheme');
    return APP_THEMES.includes(t) ? t : 'default';
  } catch { return 'default'; }
};
window.setAppTheme = function(theme) {
  if (!APP_THEMES.includes(theme)) theme = 'default';
  try { localStorage.setItem('appTheme', theme); } catch {}
  applyAppTheme();
  // Re-render Nastavení (aktivní karta) — aby se updatly badge "Aktivní"
  if (state.current === 'nastaveni') renderNastaveni();
};
function applyAppTheme() {
  const t = getAppTheme();
  const html = document.documentElement;
  // Odstraň všechny theme-* třídy a 'dark' (legacy)
  APP_THEMES.forEach(x => html.classList.remove('theme-' + x));
  html.classList.remove('dark');
  // Aplikuj aktuální
  html.classList.add('theme-' + t);
  // Pro zpětnou kompatibilitu (existující html.dark CSS) přidej 'dark' i u darku
  if (t === 'dark') html.classList.add('dark');
}
// Aplikuj theme okamžitě při startu (před renderem)
applyAppTheme();

// =============================================================
// 📏 DENSITY (HUSTOTA UI) — kompaktní / pohodlné / prostorné
// CSS variabily nastavují multiplier pro padding/font/gap.
// Pro starší / slabozraké uživatele = pohodlnější UI.
// =============================================================
const APP_DENSITIES = ['compact', 'comfortable', 'spacious', 'extreme'];
window.getAppDensity = function() {
  try {
    const d = localStorage.getItem('appDensity');
    return APP_DENSITIES.includes(d) ? d : 'comfortable';
  } catch { return 'comfortable'; }
};
window.setAppDensity = function(density) {
  if (!APP_DENSITIES.includes(density)) density = 'comfortable';
  try { localStorage.setItem('appDensity', density); } catch {}
  applyAppDensity();
  if (state?.current === 'nastaveni') renderNastaveni();
};
function applyAppDensity() {
  const d = getAppDensity();
  const html = document.documentElement;
  APP_DENSITIES.forEach(x => html.classList.remove('density-' + x));
  html.classList.add('density-' + d);
}
applyAppDensity();

