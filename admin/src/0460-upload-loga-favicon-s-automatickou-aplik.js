// =============================================================
// 🖼️ UPLOAD LOGA + FAVICON (s automatickou aplikací po načtení)
// =============================================================
window.uploadLogo = async function() {
  const file = document.getElementById('ns-logo-file')?.files?.[0];
  if (!file) return;
  const fd = new FormData();
  fd.append('logo', file);
  try {
    const res = await api('admin_nastaveni.php?action=upload_logo', { method: 'POST', body: fd });
    // Aktualizovat náhledy v aktuální stránce
    const lp = document.getElementById('ns-logo-preview');
    const fp = document.getElementById('ns-favicon-preview');
    if (lp) lp.innerHTML = `<img src="${esc(res.logo_url)}" style="max-width:100%;max-height:100%;object-fit:contain" alt="Logo">`;
    if (fp) fp.innerHTML = `<img src="${esc(res.favicon_url)}" style="width:32px;height:32px;object-fit:contain" alt="Favicon">`;
    // Aplikovat favicon globálně
    aplikovatFavicon(res.favicon_url);
    // Aplikovat logo do sidebaru a topbaru
    aplikovatLogo(res.logo_url);
    // Refresh nastaveni stránky pro tlačítko "Odstranit"
    renderNastaveni();
  } catch (e) { alert('Chyba při uploadu: ' + e.message); }
};

window.removeLogo = async function() {
  if (!(await confirmDialog({ msg: 'Opravdu odstranit logo a favicon? Vrátí se výchozí.', danger: true }))) return;
  try {
    await api('admin_nastaveni.php?action=remove_logo', { method: 'POST' });
    aplikovatFavicon(null);
    aplikovatLogo(null);
    renderNastaveni();
  } catch (e) { alert('Chyba: ' + e.message); }
};

// Dynamická aplikace favicon (přidá nebo aktualizuje <link rel="icon">)
window.aplikovatFavicon = function(url) {
  // Smaž stávající
  document.querySelectorAll('link[rel="icon"], link[rel="shortcut icon"]').forEach(l => l.remove());
  if (!url) return;
  const link = document.createElement('link');
  link.rel = 'icon';
  link.type = 'image/png';
  link.href = url;
  document.head.appendChild(link);
};

// Dynamická aplikace loga do sidebaru a login screen
window.aplikovatLogo = function(url) {
  // Sidebar logo (R kruh)
  document.querySelectorAll('.sidebar-logo .logo-icon, .login-logo-icon').forEach(el => {
    if (url) {
      el.innerHTML = `<img src="${url}" style="width:100%;height:100%;object-fit:contain;border-radius:inherit" alt="Logo">`;
      el.style.padding = '4px';
      el.style.background = '#fff';
    } else {
      el.innerHTML = 'R';
      el.style.padding = '';
      el.style.background = '';
    }
  });
};

// 📊 v3.0.310 — Google Analytics na admin core (hlavní aplikaci). Per-install ID
// (ga_measurement_id_core), client-side injekce gtag — vzor jako b2b/app.js + POS.
window.aplikovatGaCore = function(id) {
  id = (id || '').trim();
  if (!id || window._gaCoreLoaded || !/^(G|AW|UA)-[A-Z0-9-]{4,}$/i.test(id)) return;
  window._gaCoreLoaded = true;
  const gs = document.createElement('script');
  gs.async = true;
  gs.src = 'https://www.googletagmanager.com/gtag/js?id=' + encodeURIComponent(id);
  document.head.appendChild(gs);
  window.dataLayer = window.dataLayer || [];
  window.gtag = window.gtag || function () { window.dataLayer.push(arguments); };
  window.gtag('js', new Date());
  window.gtag('config', id, { anonymize_ip: true });
};

// Auto-load logo + favicon (+ GA core) při startu aplikace
(async function _initBranding() {
  try {
    // Cached endpoint — rychlé
    const n = await api('admin_nastaveni.php');
    if (n.firma_favicon_url) aplikovatFavicon(n.firma_favicon_url);
    if (n.firma_logo_url)    aplikovatLogo(n.firma_logo_url);
    if (n.ga_measurement_id_core) aplikovatGaCore(n.ga_measurement_id_core);
  } catch (e) { /* neauth nebo network — ignore */ }
})();

window.uploadObrazek = async function() {
  const file = document.getElementById('img-file').files[0];
  if (!file) return;
  
  const fd = new FormData();
  fd.append('obrazek', file);
  
  try {
    const res = await api('admin_vyrobky.php?action=upload', { method: 'POST', body: fd });
    document.getElementById('img-url').value = res.url;
    document.getElementById('img-preview').innerHTML = `<img src="${esc(res.url)}">`;
  } catch (e) { alert('Chyba: ' + e.message); }
};

window.zrusitObrazek = function() {
  document.getElementById('img-url').value = '';
  document.getElementById('img-preview').innerHTML = '<div class="image-preview-empty">📷</div>';
};

// 🆕 v3.0.295 — obor výrobku → hint + zvýraznění relevantních polí (provázání s balíčky)
window.vyOborChange = function() {
  const obor = (document.getElementById('vy-obor') || {}).value || '';
  const hint = document.getElementById('vy-obor-hint');
  const hmRow = document.getElementById('vy-obsah');
  const map = {
    dort: '🎂 Konfigurovatelný dort — zákazník volí velikost/příchuť/dekoraci. Počítá se do denní <strong>kapacity pečení</strong>. Vyplň hmotnost (porce).',
    chlebicek: '🥪 Počítá se do denní kapacity (chlebíčky).',
    zakusek: '🍰 Počítá se do denní kapacity (zákusky).',
  };
  if (hint) hint.innerHTML = map[obor] || 'Běžný výrobek — nezapočítává se do speciální kapacity balíčků.';
  if (hmRow) hmRow.style.boxShadow = obor === 'dort' ? '0 0 0 2px var(--primary)' : '';
};

window.vyPrepocet = function() {
  const cena = parseFloat(document.getElementById('vy-cena')?.value) || 0;
  const obsah = parseFloat(document.getElementById('vy-obsah')?.value) || 0;
  const obsahJed = document.getElementById('vy-obsah-jed')?.value || '';
  const box = document.getElementById('vy-prepocet');
  if (!box) return;

  // Synchronizace: pokud unit g/kg → naplň hidden hmotnost_g (pro backward compat)
  const hmHidden = document.getElementById('vy-hm');
  if (hmHidden) {
    let hmG = '';
    if (obsah > 0 && obsahJed === 'g')  hmG = Math.round(obsah);
    else if (obsah > 0 && obsahJed === 'kg') hmG = Math.round(obsah * 1000);
    hmHidden.value = hmG;
  }

  // Náhled cena/kg|l — počítáme s DPH (běžně se uvádí cena s DPH/kg)
  const dphSelect = document.getElementById('vy-dph');
  let dphPct = 12;
  if (dphSelect) {
    const opt = dphSelect.options[dphSelect.selectedIndex];
    const sazbaTxt = opt?.textContent || '';
    const m = sazbaTxt.match(/(\d+)\s*%/);
    if (m) dphPct = parseInt(m[1]) || 12;
  }
  const cenaSDph = cena * (1 + dphPct / 100);

  let kg = 0, l = 0;
  if (obsahJed === 'g') kg = obsah / 1000;
  else if (obsahJed === 'kg') kg = obsah;
  else if (obsahJed === 'ml') l = obsah / 1000;
  else if (obsahJed === 'l') l = obsah;

  if (cena <= 0 || (kg <= 0 && l <= 0)) { box.style.display = 'none'; return; }

  if (kg > 0) box.innerHTML = `→ ${(cenaSDph / kg).toFixed(2).replace('.', ',')} Kč / kg`;
  else if (l > 0) box.innerHTML = `→ ${(cenaSDph / l).toFixed(2).replace('.', ',')} Kč / l`;
  box.style.display = 'block';
};

window.ulozitVyrobek = async function(id) {
  const data = {
    id: id || undefined,
    cislo: document.getElementById('vy-cislo').value || null,
    ean: document.getElementById('vy-ean')?.value.replace(/\D/g, '') || null,
    nazev: document.getElementById('vy-nazev').value.trim(),
    slozeni: document.getElementById('vy-sloz')?.value || null,
    popis: document.getElementById('vy-popis').value || null,
    alergeny: document.getElementById('vy-aler').value || null,
    kategorie_id: parseInt(document.getElementById('vy-kat').value) || null,
    jednotka_id: parseInt(document.getElementById('vy-jed').value),
    cena_bez_dph: parseFloat(document.getElementById('vy-cena').value),
    sazba_dph_id: parseInt(document.getElementById('vy-dph').value),
    hmotnost_g: (() => { const el = document.getElementById('vy-hmotnost') || document.getElementById('vy-hm'); const raw = ((el && el.value) || '').trim(); if (raw === '') return null; const v = parseInt(raw); return v > 0 ? v : null; })(),
    rozmer_d: (() => { const v = parseFloat((document.getElementById('vy-rozmer-d') || {}).value); return v > 0 ? v : null; })(),
    rozmer_s: (() => { const v = parseFloat((document.getElementById('vy-rozmer-s') || {}).value); return v > 0 ? v : null; })(),
    rozmer_v: (() => { const v = parseFloat((document.getElementById('vy-rozmer-v') || {}).value); return v > 0 ? v : null; })(),
    obsah: parseFloat(document.getElementById('vy-obsah')?.value) || null,
    obsah_jednotka: document.getElementById('vy-obsah-jed')?.value || null,
    nutricni_hodnoty: (() => {
      const keys = ['energie_kj','energie_kcal','tuky','tuky_nasycene','sacharidy','cukry','bilkoviny','sul'];
      const out = {};
      let any = false;
      keys.forEach(k => {
        const el = document.getElementById('vy-nutr-' + k);
        const v = el ? parseFloat(el.value) : NaN;
        if (!isNaN(v) && el?.value !== '') { out[k] = v; any = true; }
      });
      return any ? out : null;
    })(),
    obrazek_url: document.getElementById('img-url').value || null,
    min_objednavka: parseInt(document.getElementById('vy-min').value) || 1,
    aktivni: document.getElementById('vy-akt').checked ? 1 : 0,
    oblibeny: document.getElementById('vy-obl').checked ? 1 : 0,
    // 🆕 v3.0.328 — prodej na váhu (cena = za vaha_jednotka; plu = kód pro váhové čárové kódy)
    na_vahu: document.getElementById('vy-navahu')?.checked ? 1 : 0,
    plu: parseInt(document.getElementById('vy-plu')?.value) || null,
    vaha_jednotka: document.getElementById('vy-vjed')?.value || 'kg',
    // Statusové štítky — nezávislé na slevě
    je_akce: document.getElementById('vy-akce')?.checked ? 1 : 0,
    je_novinka: document.getElementById('vy-novinka')?.checked ? 1 : 0,
    je_doprodej: document.getElementById('vy-doprodej')?.checked ? 1 : 0,
    je_vyprodano: document.getElementById('vy-vyprodano')?.checked ? 1 : 0,
    slozeni_polozky: vyCollectSlozeni(),
    // 🆕 v3.0.156 — doba přípravy + kuchyňská stanice (jen když je sekce vyrenderovaná = balíček Restaurace)
    ...(document.getElementById('vy-prep') ? { priprava_min: parseInt(document.getElementById('vy-prep').value) || 0 } : {}),
    ...(document.getElementById('vy-station') ? { kitchen_station_id: parseInt(document.getElementById('vy-station').value) || null } : {}),
    ...(document.getElementById('vy-obor') ? { obor: document.getElementById('vy-obor').value || null } : {}),
    // 🆕 v3.0.303 — polotovar (sestavy/BOM) + hybrid sklad + výrobní postup
    ...(document.getElementById('vy-polotovar') ? {
      je_polotovar: document.getElementById('vy-polotovar').checked ? 1 : 0,
      sleduje_sklad: (document.getElementById('vy-sleduje-sklad') && document.getElementById('vy-sleduje-sklad').checked) ? 1 : 0,
      postup_json: JSON.stringify((document.getElementById('vy-postup')?.value || '').split('\n').map(s => s.trim()).filter(Boolean)),
    } : {}),
    // 🆕 v3.0.213 — viditelnost na POS (jen když je přepínač vyrenderovaný = balíček Restaurace)
    ...(document.getElementById('vy-pos') ? { zobrazit_na_pos: document.getElementById('vy-pos').checked ? 1 : 0 } : {}),
  };

  // Validace s konkrétními hláškami (snáz se zjistí, co chybí)
  if (!data.nazev) {
    const el = document.getElementById('vy-nazev');
    if (el) { el.focus(); el.style.outline = '2px solid var(--err)'; setTimeout(() => el.style.outline = '', 2500); }
    return alert('Vyplňte název výrobku.');
  }
  if (isNaN(data.cena_bez_dph) || data.cena_bez_dph === null) {
    const el = document.getElementById('vy-cena');
    if (el) { el.focus(); el.style.outline = '2px solid var(--err)'; setTimeout(() => el.style.outline = '', 2500); }
    return alert('Vyplňte cenu výrobku (může být 0).');
  }
  if (!data.jednotka_id) {
    const el = document.getElementById('vy-jed');
    if (el) { el.focus(); el.style.outline = '2px solid var(--err)'; setTimeout(() => el.style.outline = '', 2500); }
    return alert('Vyberte jednotku.');
  }
  if (!data.sazba_dph_id) {
    const el = document.getElementById('vy-dph');
    if (el) { el.focus(); el.style.outline = '2px solid var(--err)'; setTimeout(() => el.style.outline = '', 2500); }
    return alert('Vyberte sazbu DPH.');
  }

  if (window._savingVyrobek) return;            // 🆕 v3.0.347 — zábrana dvojího odeslání (dupl. produkt)
  window._savingVyrobek = true;
  try {
    await api('admin_vyrobky.php', {
      method: id ? 'PUT' : 'POST',
      body: JSON.stringify(data),
    });
    closeModal();
    navigate('vyrobky');
  } catch (e) { alert('Chyba: ' + e.message); }
  finally { window._savingVyrobek = false; }
};

window.smazatVyrobek = async function(id) {
  if (!await confirmDelete2x({ co: 'tento výrobek', detail: 'Pokud má historické objednávky, výrobek se pouze skryje (nezůstanou rozbité doklady).' })) return;
  await api(`admin_vyrobky.php?id=${id}`, { method: 'DELETE' });
  closeModal();
  navigate('vyrobky');
};

