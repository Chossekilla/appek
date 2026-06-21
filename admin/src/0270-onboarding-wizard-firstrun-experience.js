// =============================================================
// 🎯 ONBOARDING WIZARD — first-run experience
// =============================================================
async function _checkOnboarding() {
  try {
    const s = await api('admin_onboarding.php?action=status');
    if (s.should_show) {
      openOnboarding(s.step || 0);
    }
  } catch (e) { /* tichá chyba */ }
}

window.openOnboarding = async function(startStep = 0) {
  state._onboard = {
    step: startStep || 0,
    data: {
      kategorie_zvolene: ['Chleby', 'Pečivo', 'Koláče'],
      jazyk: localStorage.getItem('appek_lang') || 'cs',
      install_mode: null,
      detected_os: detectOnboardOS(),
      demo_data_seed: true, // 🆕 v2.9.292 — default ANO (user nemusí klikat, demo se naplní)
    },
  };
  renderOnboardingStep();
};

function detectOnboardOS() {
  const ua = (navigator.userAgent || '').toLowerCase();
  if (ua.includes('mac')) return 'mac';
  if (ua.includes('win')) return 'windows';
  if (ua.includes('linux')) return 'linux';
  return 'unknown';
}

// =============================================================
// 🌍 APPEK_LOCALES — Lokalizované taxonomie (T4)
// Při výběru jazyka v onboardingu nebo Nastavení se automaticky
// nastaví výchozí měna, DPH sazby, formát data a regiony.
// =============================================================
window.APPEK_LOCALES = {
  cs: {
    code: 'cs',
    label: 'Čeština',
    flag: '🇨🇿',
    locale: 'cs-CZ',
    currency: 'CZK',
    currency_symbol: 'Kč',
    country: 'CZ',
    country_label: 'Česká republika',
    date_format: 'dd.MM.yyyy',
    decimal_sep: ',',
    thousand_sep: ' ',
    vat_rates: [
      { sazba: 0,  nazev: 'nulová' },
      { sazba: 12, nazev: 'snížená' },
      { sazba: 21, nazev: 'základní' },
    ],
    regions: [
      { code: 'PHA', name: 'Hlavní město Praha' },
      { code: 'STC', name: 'Středočeský kraj' },
      { code: 'JHC', name: 'Jihočeský kraj' },
      { code: 'PLK', name: 'Plzeňský kraj' },
      { code: 'KVK', name: 'Karlovarský kraj' },
      { code: 'ULK', name: 'Ústecký kraj' },
      { code: 'LBK', name: 'Liberecký kraj' },
      { code: 'HKK', name: 'Královéhradecký kraj' },
      { code: 'PAK', name: 'Pardubický kraj' },
      { code: 'VYS', name: 'Kraj Vysočina' },
      { code: 'JHM', name: 'Jihomoravský kraj' },
      { code: 'OLK', name: 'Olomoucký kraj' },
      { code: 'ZLK', name: 'Zlínský kraj' },
      { code: 'MSK', name: 'Moravskoslezský kraj' },
    ],
    invoice_terms: { default_days: 14, max_days: 30 },
    ico_label: 'IČO',
    dic_label: 'DIČ',
    bank_account_format: 'XXXXXXXXXX/XXXX',
  },
  en: {
    code: 'en',
    label: 'English',
    flag: '🇬🇧',
    locale: 'en-GB',
    currency: 'GBP',
    currency_symbol: '£',
    country: 'GB',
    country_label: 'United Kingdom',
    date_format: 'dd/MM/yyyy',
    decimal_sep: '.',
    thousand_sep: ',',
    vat_rates: [
      { sazba: 0,  nazev: 'zero rate' },
      { sazba: 5,  nazev: 'reduced rate' },
      { sazba: 20, nazev: 'standard rate' },
    ],
    regions: [
      // UK + US dual support — start with UK countries
      { code: 'ENG', name: 'England' },
      { code: 'SCT', name: 'Scotland' },
      { code: 'WLS', name: 'Wales' },
      { code: 'NIR', name: 'Northern Ireland' },
      // US states (most common for international gastro biz)
      { code: 'US-CA', name: 'California (US)' },
      { code: 'US-NY', name: 'New York (US)' },
      { code: 'US-TX', name: 'Texas (US)' },
      { code: 'US-FL', name: 'Florida (US)' },
      { code: 'US-IL', name: 'Illinois (US)' },
      { code: 'US-WA', name: 'Washington (US)' },
      { code: 'US-MA', name: 'Massachusetts (US)' },
      { code: 'US-OTHER', name: 'Other US State' },
    ],
    invoice_terms: { default_days: 30, max_days: 60 },
    ico_label: 'Company No.',
    dic_label: 'VAT No.',
    bank_account_format: 'IBAN / Sort Code',
  },
  es: {
    code: 'es',
    label: 'Español',
    flag: '🇪🇸',
    locale: 'es-ES',
    currency: 'EUR',
    currency_symbol: '€',
    country: 'ES',
    country_label: 'España',
    date_format: 'dd/MM/yyyy',
    decimal_sep: ',',
    thousand_sep: '.',
    vat_rates: [
      { sazba: 0,  nazev: 'exento' },
      { sazba: 4,  nazev: 'superreducido' },
      { sazba: 10, nazev: 'reducido' },
      { sazba: 21, nazev: 'general' },
    ],
    regions: [
      // 17 autonomous communities of Spain
      { code: 'AN', name: 'Andalucía' },
      { code: 'AR', name: 'Aragón' },
      { code: 'AS', name: 'Asturias' },
      { code: 'IB', name: 'Islas Baleares' },
      { code: 'CN', name: 'Canarias' },
      { code: 'CB', name: 'Cantabria' },
      { code: 'CL', name: 'Castilla y León' },
      { code: 'CM', name: 'Castilla-La Mancha' },
      { code: 'CT', name: 'Cataluña' },
      { code: 'EX', name: 'Extremadura' },
      { code: 'GA', name: 'Galicia' },
      { code: 'MD', name: 'Madrid' },
      { code: 'MC', name: 'Murcia' },
      { code: 'NC', name: 'Navarra' },
      { code: 'PV', name: 'País Vasco' },
      { code: 'RI', name: 'La Rioja' },
      { code: 'VC', name: 'Valencia' },
      // Mexico optional
      { code: 'MX', name: 'México (otra región)' },
    ],
    invoice_terms: { default_days: 30, max_days: 60 },
    ico_label: 'CIF',
    dic_label: 'NIF / VAT',
    bank_account_format: 'IBAN ES##',
  },
};

window.getLocaleConfig = function(lang) {
  return window.APPEK_LOCALES[lang] || window.APPEK_LOCALES.cs;
};

// 🌍 Aplikuj výchozí měnu, sazby DPH a regionální nastavení podle jazyka.
// Volá se z _onboardSetLang a z Nastavení → Lokalizace.
// Volitelný param `force` (default false) — pokud true, přepíše i existující hodnoty.
window.applyLocaleDefaults = async function(lang, force = false) {
  const cfg = window.getLocaleConfig(lang);
  if (!cfg) return null;

  try {
    // 1) Načti aktuální nastaveni (možná uživatel už nastavil měnu manuálně)
    let curr = {};
    try { curr = await api('admin_nastaveni.php'); } catch (e) {}

    // 2) Připrav payload — jen pokud klíč chybí nebo force=true
    const payload = { firma_jazyk: lang };
    if (force || !curr.firma_locale)      payload.firma_locale      = cfg.locale;
    if (force || !curr.firma_mena)        payload.firma_mena        = cfg.currency;
    if (force || !curr.firma_zeme)        payload.firma_zeme        = cfg.country;
    if (force || !curr.firma_format_data) payload.firma_format_data = cfg.date_format;

    // 3) Ulož přes API (whitelist v admin_nastaveni.php)
    try {
      await api('admin_nastaveni.php', { method: 'PUT', body: JSON.stringify(payload) });
    } catch (e) { console.warn('applyLocaleDefaults: nelze uložit nastavení', e); }

    // 4) Pokud nejsou žádné DPH sazby (čerstvá instalace), naimportuj výchozí
    try {
      const dphData = await api('admin_sazby_dph.php');
      if (force || !dphData.sazby || dphData.sazby.length === 0) {
        for (const s of cfg.vat_rates) {
          try {
            await api('admin_sazby_dph.php', {
              method: 'POST',
              body: JSON.stringify({
                nazev: s.nazev,
                sazba: s.sazba,
                platne_od: new Date().toISOString().split('T')[0],
              }),
            });
          } catch (e) { /* duplicate je OK */ }
        }
      }
    } catch (e) { console.warn('applyLocaleDefaults: DPH sazby nelze nahrát', e); }

    // 5) Aktualizuj cache pro fmt() — uložíme do window pro okamžité přepnutí UI
    window._appekLocaleCache = cfg;
    return cfg;
  } catch (e) {
    console.error('applyLocaleDefaults selhalo:', e);
    return null;
  }
};

// 🔢 Vrať aktuálně platnou locale konfiguraci (z cache nebo z localStorage).
window.getCurrentLocale = function() {
  if (window._appekLocaleCache) return window._appekLocaleCache;
  const lang = (typeof window.appekCurrentLang === 'string') ? window.appekCurrentLang : (localStorage.getItem('appek_lang') || 'cs');
  return window.getLocaleConfig(lang);
};

async function renderOnboardingStep() {
  const o = state._onboard;
  const step = o.step || 0;
  const totalSteps = 9;
  const stepTitles = [
    'Vítejte', 'Jazyk', 'Typ instalace', 'Údaje firmy',
    'Logo + vzhled', 'Balíčky', 'Demo data', 'Quick start', 'Hotovo!'
  ];
  const title = `🎯 Onboarding — krok ${step + 1} / ${totalSteps}: ${stepTitles[step] || ''}`;
  const stepHtml = await _onboardingStepHtml(step);
  openModal(title, stepHtml, 'wide');
}

async function _onboardingStepHtml(step) {
  // Progress bar nahoře (shared) — 9 kroků
  const total = 9;
  const pct = Math.round(((step + 1) / total) * 100);
  const progress = `
    <div style="margin-bottom:20px">
      <div style="display:flex;justify-content:space-between;font-size:11px;color:var(--text-3);margin-bottom:4px">
        <span>Krok ${step + 1} z ${total}</span>
        <span>${pct} %</span>
      </div>
      <div style="height:8px;background:var(--surface-2);border-radius:4px;overflow:hidden">
        <div style="height:100%;background:linear-gradient(90deg,var(--primary),var(--primary-dark));width:${pct}%;transition:width 0.3s"></div>
      </div>
    </div>
  `;

  // Načti current data (pro kroky 3+) — defenzivně, ať selhání fetchu nerozbije wizard (first-run)
  let n = {};
  if (step >= 3) { try { n = (await api('admin_nastaveni.php')) || {}; } catch (e) { n = {}; } }
  const o = state._onboard.data;

  switch (step) {
    case 0: // Welcome
      return progress + `
        <div style="text-align:center;padding:30px 20px">
          <div style="font-size:64px;margin-bottom:14px">🥖</div>
          <h2 style="margin:0 0 12px;font-size:24px">Vítejte v Appek!</h2>
          <p style="font-size:15px;color:var(--text-2);line-height:1.7;max-width:480px;margin:0 auto">
            Pomůžeme vám rozjet váš objednávkový systém za <strong>~5 minut</strong>.
            Můžete kdykoliv přeskočit a doplnit později — všechno najdete v Nastavení.
          </p>
        </div>
        <div class="form-actions">
          <button class="btn-secondary" onclick="onboardSkip()">✕ Přeskočit vše</button>
          <div style="flex:1"></div>
          <button class="btn-primary btn-green" onclick="onboardNext()" style="font-weight:700;padding:14px 28px">➜ Začít</button>
        </div>
      `;

    case 1: // 🌍 Jazyk
      return progress + `
        <h3 style="margin:0 0 8px">🌍 Vyber jazyk aplikace</h3>
        <p style="font-size:13px;color:var(--text-3);margin:0 0 18px">Aplikace plně přeložená do češtiny, angličtiny a španělštiny. Můžeš kdykoliv změnit v Nastavení.</p>
        <div style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:12px;margin-bottom:18px">
          ${[
            {code:'cs', flag:'🇨🇿', label:'Čeština', sub:'CZ — výchozí (ARES, DPH, EET)'},
            {code:'en', flag:'🇬🇧', label:'English', sub:'EU/UK/US (USD/EUR, VAT)'},
            {code:'es', flag:'🇪🇸', label:'Español', sub:'ES/MX/LATAM (IVA)'},
          ].map(l => {
            const aktiv = o.jazyk === l.code;
            return `<button onclick="window._onboardSetLang('${l.code}')" style="padding:18px 14px;border:2px solid ${aktiv ? 'var(--primary)' : 'var(--border)'};border-radius:12px;background:${aktiv ? 'var(--surface-2)' : 'var(--surface)'};cursor:pointer;font-family:inherit;text-align:center;transition:all 0.15s">
              <div style="font-size:38px;line-height:1;margin-bottom:8px">${l.flag}</div>
              <div style="font-weight:700;font-size:15px;margin-bottom:4px">${l.label}</div>
              <div style="font-size:11px;color:var(--text-3);line-height:1.4">${l.sub}</div>
              ${aktiv ? '<div style="margin-top:8px;color:var(--primary);font-weight:700;font-size:12px">✓ Vybráno</div>' : ''}
            </button>`;
          }).join('')}
        </div>
        <div style="padding:12px;background:var(--info-bg);color:var(--info-text);border-radius:8px;font-size:12.5px;line-height:1.5">
          💡 Při výběru EN/ES se automaticky upraví <strong>výchozí měna, sazby DPH a formát data</strong> dle regionu. Můžeš kdykoliv změnit.
        </div>
        <div class="form-actions">
          <button class="btn-secondary" onclick="onboardBack()">← Zpět</button>
          <div style="flex:1"></div>
          <button class="btn-primary btn-green" onclick="onboardNext()" style="font-weight:700;padding:12px 24px">➜ Dál</button>
        </div>
      `;

    case 2: // 🏠 Typ instalace
      const osDetected = o.detected_os || 'unknown';
      const osLabelMap = { mac: '🍎 macOS', windows: '🪟 Windows', linux: '🐧 Linux', unknown: '🖥️ tvém počítači' };
      const osLabel = osLabelMap[osDetected];
      // Pro lokální zobrazený OS — defaultně detekovaný, lze ručně přepnout
      const localOs = o.local_os || (osDetected !== 'unknown' ? osDetected : 'mac');
      return progress + `
        <h3 style="margin:0 0 8px">🏠 Kde to chceš provozovat?</h3>
        <p style="font-size:13px;color:var(--text-3);margin:0 0 14px">Vyber typ instalace. Můžeš později kdykoliv migrovat (přesun je jednoduchý — exportneš DB, naimportuješ jinde).</p>

        <div style="background:linear-gradient(135deg,#FFF8E5,#FEF3C7);border:1px solid #FBBF24;border-radius:10px;padding:12px 14px;margin-bottom:16px;font-size:13px;color:#854F0B;line-height:1.55">
          💡 <strong>Nevíš co vybrat?</strong> Pro 90% uživatelů je <strong>Webová instalace</strong> nejjednodušší volba — funguje z mobilu i počítače, automatické zálohy, žádný počítač nemusíš nechávat zapnutý.
        </div>

        <div style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:12px;margin-bottom:18px" class="install-mode-grid">
          ${[
            {
              id: 'cloud',
              icon: '🌐',
              label: 'Webová instalace',
              sub: 'Tvůj vlastní webhosting',
              badge: 'Doporučeno',
              badgeColor: '#16a34a',
              desc: 'Nahraješ Appek na svůj webhosting (Wedos, Hostinger, Forpsi, Active24, WebSupport, jakýkoliv s PHP 8+ a MySQL). Funguje hned online z mobilu i PC, automatické zálohy.',
              pros: ['📱 Přístup odkudkoliv','🔄 Automatické zálohy','⚡ Hned online','💼 Profesionální'],
              for: 'Pekařství, restaurace, eshop, B2B'
            },
            {
              id: 'local',
              icon: '🏠',
              label: 'Lokální (offline)',
              sub: `Na tvém počítači zdarma`,
              desc: 'Appek poběží přímo na tvém počítači — Mac / Windows / Linux. Žádný internet ani webhosting nepotřebuješ. Data jsou pouze u tebe.',
              pros: ['🔒 Data jen u tebe','💸 Žádný webhosting','📴 Funguje i offline','🧪 Ideální na test'],
              for: 'Testování, jedna pobočka, max. soukromí',
              osChips: ['🍎 macOS','🪟 Windows','🐧 Linux']
            },
            {
              id: 'hybrid',
              icon: '🔄',
              label: 'Hybridní',
              sub: 'Lokálně + web zároveň',
              badge: 'Pokročilé',
              badgeColor: '#9333ea',
              desc: 'Hlavní instance běží lokálně (na PC v provozovně), současně synchronizuje s kopií na webhostingu (mobil, šéf z domova). Vyžaduje obě prostředí.',
              pros: ['⚡ Lokálně rychlé','📱 Web pro mobil','🔄 Auto sync','🛡️ Dvojí záloha'],
              for: 'Větší provozy, více poboček, šéf na cestách'
            },
          ].map(m => {
            const aktiv = o.install_mode === m.id;
            return `<button onclick="window._onboardSetMode('${m.id}')" style="position:relative;padding:18px 14px;border:2px solid ${aktiv ? 'var(--primary)' : 'var(--border)'};border-radius:12px;background:${aktiv ? 'var(--surface-2)' : 'var(--surface)'};cursor:pointer;font-family:inherit;text-align:left;transition:all 0.15s;display:flex;flex-direction:column">
              ${m.badge ? `<div style="position:absolute;top:-9px;right:10px;background:${m.badgeColor};color:#fff;font-size:10px;font-weight:800;padding:3px 9px;border-radius:999px;letter-spacing:0.5px;box-shadow:0 2px 6px rgba(0,0,0,0.15)">${m.badge.toUpperCase()}</div>` : ''}
              <div style="font-size:38px;line-height:1;margin-bottom:8px">${m.icon}</div>
              <div style="font-weight:800;font-size:15.5px;margin-bottom:4px;color:var(--text-1)">${m.label}</div>
              <div style="font-size:12px;color:var(--primary-dark);font-weight:600;margin-bottom:8px">${m.sub}</div>
              <div style="font-size:12px;color:var(--text-2);line-height:1.55;margin-bottom:10px">${m.desc}</div>
              ${m.osChips ? `<div style="display:flex;gap:4px;flex-wrap:wrap;margin-bottom:8px">${m.osChips.map(c=>`<span style="background:var(--surface-2);border:1px solid var(--border);padding:2px 8px;border-radius:6px;font-size:11px;font-weight:500">${c}</span>`).join('')}</div>` : ''}
              <ul style="margin:0 0 10px;padding:0;list-style:none;font-size:11.5px;color:var(--text-2);line-height:1.75">
                ${m.pros.map(p=>`<li>${p}</li>`).join('')}
              </ul>
              <div style="margin-top:auto;padding-top:8px;border-top:1px dashed var(--border);font-size:11px;color:var(--text-3);font-style:italic;line-height:1.4">${m.for}</div>
              ${aktiv ? '<div style="margin-top:10px;color:var(--primary);font-weight:800;font-size:13px">✓ Vybráno</div>' : ''}
            </button>`;
          }).join('')}
        </div>

        ${o.install_mode === 'local'  ? _onboardLocalInstallGuide(localOs) : ''}
        ${o.install_mode === 'cloud'  ? _onboardCloudInstallGuide() : ''}
        ${o.install_mode === 'hybrid' ? _onboardHybridInstallGuide() : ''}

        <style>
          @media (max-width: 760px) {
            .install-mode-grid { grid-template-columns: 1fr !important; }
          }
        </style>

        <div class="form-actions">
          <button class="btn-secondary" onclick="onboardBack()">← Zpět</button>
          <button class="btn-secondary" onclick="onboardSkipStep()">Přeskočit</button>
          <div style="flex:1"></div>
          <button class="btn-primary btn-green" onclick="onboardNext()" style="font-weight:700;padding:12px 24px">➜ Dál</button>
        </div>
      `;

    case 3: // Údaje firmy + ARES (původní krok 1)
      return progress + `
        <h3 style="margin:0 0 8px">🏢 O vaší firmě</h3>
        <p style="font-size:13px;color:var(--text-3);margin:0 0 16px">Zadejte IČO — automaticky načteme název, adresu a DIČ z veřejného registru. Podporujeme <strong>🇨🇿 CZ ARES</strong> i <strong>🇸🇰 SK RPO</strong> (auto-detekce, zdarma, bez klíče).</p>
        <div class="form-grid form-grid-tight">
          <div>
            <label class="form-label">IČO</label>
            <div style="display:flex;gap:6px">
              <input class="form-input" id="ob-ico" value="${esc(n.firma_ico || '')}" placeholder="12345678">
              <button class="btn-primary" onclick="onboardAresLookup()" style="white-space:nowrap" title="Zkusí ARES (CZ) → při nenalezení RPO (SK)">🔍 Načíst</button>
            </div>
          </div>
          <div>
            <label class="form-label">DIČ</label>
            <input class="form-input" id="ob-dic" value="${esc(n.firma_dic || '')}" placeholder="CZ12345678 / SK1234567890">
          </div>
          <div class="full">
            <label class="form-label">Název firmy *</label>
            <input class="form-input" id="ob-nazev" value="${esc(n.firma_nazev || '')}" placeholder="např. APPEK B2B s.r.o.">
          </div>
          <div class="full">
            <label class="form-label">Ulice</label>
            <input class="form-input" id="ob-ulice" value="${esc(n.firma_ulice || '')}" placeholder="Hlavní 15">
          </div>
          <div>
            <label class="form-label">Město</label>
            <input class="form-input" id="ob-mesto" value="${esc(n.firma_mesto || '')}">
          </div>
          <div>
            <label class="form-label">PSČ</label>
            <input class="form-input" id="ob-psc" value="${esc(n.firma_psc || '')}">
          </div>
        </div>
        <div class="form-actions">
          <button class="btn-secondary" onclick="onboardBack()">← Zpět</button>
          <button class="btn-secondary" onclick="onboardSkip()">Přeskočit vše</button>
          <div style="flex:1"></div>
          <button class="btn-primary btn-green" onclick="onboardSaveAndNext()" style="font-weight:700;padding:12px 24px">➜ Dál</button>
        </div>
      `;

    case 4: // Logo + theme (původní krok 2)
      return progress + `
        <h3 style="margin:0 0 8px">🎨 Logo a vzhled</h3>
        <p style="font-size:13px;color:var(--text-3);margin:0 0 16px">Nahrajte logo — automaticky vygenerujeme favicon. Volitelně si vyberte téma (můžete změnit kdykoliv).</p>
        <div style="display:flex;gap:14px;align-items:center;margin-bottom:18px">
          <div style="width:120px;height:120px;border:2px dashed var(--border);border-radius:10px;display:flex;align-items:center;justify-content:center;background:var(--surface-2);overflow:hidden" id="ob-logo-prev">
            ${n.firma_logo_url
              ? `<img src="${esc(n.firma_logo_url)}" style="max-width:100%;max-height:100%;object-fit:contain">`
              : `<span style="font-size:38px;color:var(--text-3)">🖼️</span>`}
          </div>
          <div style="flex:1">
            <input type="file" id="ob-logo-file" accept="image/png,image/jpeg,image/webp" style="display:none" onchange="onboardUploadLogo()">
            <button class="btn-primary btn-green" onclick="document.getElementById('ob-logo-file').click()">📤 Nahrát logo</button>
            <p style="font-size:11px;color:var(--text-3);margin-top:6px">PNG / JPG / WEBP — doporučuji čtvercové 400×400 s průhledným pozadím.</p>
          </div>
        </div>
        <h4 style="margin:14px 0 8px;font-size:13px">Téma aplikace</h4>
        <div style="display:grid;grid-template-columns:repeat(3,1fr);gap:8px">
          ${[
            {id:'default',l:'☀️ Moderní'},
            {id:'win98',l:'🪟 Win98'},
            {id:'dark',l:'🌙 Tmavý'},
          ].map(t => {
            const akt = getAppTheme() === t.id;
            return `<button class="btn-secondary" onclick="setAppTheme('${t.id}');setTimeout(renderOnboardingStep, 100)" style="padding:14px;${akt ? 'border:2px solid var(--primary)' : ''}">${t.l}${akt ? ' ✓' : ''}</button>`;
          }).join('')}
        </div>
        <div class="form-actions">
          <button class="btn-secondary" onclick="onboardBack()">← Zpět</button>
          <button class="btn-secondary" onclick="onboardSkipStep()">Přeskočit krok</button>
          <div style="flex:1"></div>
          <button class="btn-primary btn-green" onclick="onboardNext()" style="font-weight:700;padding:12px 24px">➜ Dál</button>
        </div>
      `;

    case 5: // 🎁 Balíčky výběr (NEW)
      return progress + _onboardPackagesStep();

    case 6: // 🌱 Demo data (NEW)
      return progress + _onboardDemoDataStep();

    case 7: // 🚀 Quick start checklist (NEW)
      return progress + _onboardQuickStartStep();

    case 8: // Done
      return progress + `
        <div style="text-align:center;padding:30px 20px">
          <div style="font-size:64px;margin-bottom:14px">🎉</div>
          <h2 style="margin:0 0 12px;font-size:24px">Skvělé, máte hotovo!</h2>
          <p style="font-size:14px;color:var(--text-2);line-height:1.7;max-width:480px;margin:0 auto 24px">
            Systém je připravený. Můžete začít přidávat odběratele, vystavovat objednávky a tisknout cenovky.
          </p>
          <div style="text-align:left;max-width:420px;margin:0 auto;background:var(--surface-2);padding:16px 20px;border-radius:10px">
            <div style="font-size:13px;font-weight:600;margin-bottom:8px">💡 Doporučujeme dál:</div>
            <ul style="margin:0;padding-left:20px;font-size:13px;color:var(--text-2);line-height:1.8">
              <li>👥 Pozvat první odběratele (sekce Odběratelé)</li>
              <li>🏷️ Vytisknout cenovky (Štítky a cenovky)</li>
              <li>📧 Nastavit e-mailové notifikace (Nastavení → Notifikace)</li>
              <li>📦 Vyzkoušet PWA push notifikace (zdarma)</li>
            </ul>
          </div>
        </div>
        <div class="form-actions">
          <div style="flex:1"></div>
          <button class="btn-primary btn-green btn-big-action" onclick="onboardComplete()" style="font-size:16px;font-weight:700;padding:14px 32px">✨ Spustit systém</button>
        </div>
      `;
  }
  return '<div>Neznámý krok</div>';
}

