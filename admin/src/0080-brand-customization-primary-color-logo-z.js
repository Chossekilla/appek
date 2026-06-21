// =============================================================
// 🎨 BRAND CUSTOMIZATION — primary color + logo z firma nastavení
// =============================================================
window.applyBrandPreview = function() {
  const color = document.getElementById('ns-brand-color')?.value;
  if (!color || !/^#[0-9A-Fa-f]{6}$/.test(color)) { alert('Zadej validní HEX barvu #RRGGBB'); return; }
  const hex2rgb = (h) => h.match(/[0-9A-Fa-f]{2}/g).map(x => parseInt(x, 16));
  const rgb2hex = ([r,g,b]) => '#' + [r,g,b].map(x => Math.max(0, Math.min(255, x)).toString(16).padStart(2,'0')).join('');
  const [r, g, b] = hex2rgb(color);
  const lighten = (a) => rgb2hex([r + (255-r)*a, g + (255-g)*a, b + (255-b)*a].map(x => Math.round(x)));
  const darken  = (a) => rgb2hex([r*(1-a), g*(1-a), b*(1-a)].map(x => Math.round(x)));
  const root = document.documentElement.style;
  root.setProperty('--primary', color);
  root.setProperty('--primary-light', lighten(0.78));
  root.setProperty('--primary-dark', darken(0.18));
  toastSuccess('Barva aplikována (ulož nastavení pro trvalou změnu)');
};

window.applyBrandCustomization = async function() {
  try {
    const f = await api('firma_branding.php');
    if (!f) return;
    const primary = (f.firma_brand_color || '').trim();
    const logo    = (f.firma_logo_url    || '').trim();

    if (primary && /^#[0-9A-Fa-f]{6}$/.test(primary)) {
      // Derive light/dark variants
      const hex2rgb = (h) => h.match(/[0-9A-Fa-f]{2}/g).map(x => parseInt(x, 16));
      const rgb2hex = ([r,g,b]) => '#' + [r,g,b].map(x => Math.max(0, Math.min(255, x)).toString(16).padStart(2,'0')).join('');
      const [r, g, b] = hex2rgb(primary);
      const lighten = (a) => rgb2hex([r + (255-r)*a, g + (255-g)*a, b + (255-b)*a].map(x => Math.round(x)));
      const darken  = (a) => rgb2hex([r*(1-a), g*(1-a), b*(1-a)].map(x => Math.round(x)));

      const root = document.documentElement.style;
      root.setProperty('--primary', primary);
      root.setProperty('--primary-light', lighten(0.78));
      root.setProperty('--primary-dark', darken(0.18));
    }

    // Logo do sidebar (pokud má firma logo)
    if (logo) {
      const sidebarLogo = document.querySelector('.sidebar-logo .logo-icon');
      const topLogo     = document.querySelector('.login-logo-icon');
      [sidebarLogo, topLogo].forEach(el => {
        if (!el) return;
        el.style.background = `url('${logo}') center/cover no-repeat`;
        el.textContent = '';
      });
    }

    // Brand name v sidebar (jestli admin chce přepsat APPEK B2B)
    if (f.firma_nazev) {
      const brandEl = document.querySelector('.sidebar-logo strong');
      if (brandEl) brandEl.textContent = f.firma_nazev;
    }
  } catch (e) { /* tichá chyba */ }
};

