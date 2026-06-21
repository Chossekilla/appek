// =============================================================
// 🎁 BALÍČKY FUNKCÍ — Cukrárna / Lahůdky / Restaurace …
// =============================================================
window.loadBalicky = async function() {
  const host = document.getElementById('ns-balicky-host');
  if (!host) return;
  host.innerHTML = '⏳ Načítám…';
  try {
    const r = await api('admin_packages.php');
    const lic = r.license || {};
    const cards = (r.packages || []).map(p => {
      const featuresHtml = (p.features || []).map(f =>
        `<li style="margin:3px 0;font-size:12.5px;color:var(--text-2)">${esc(f)}</li>`
      ).join('');
      const isCore = p.always_on;
      const licensed = !!p.licensed;
      const enabled = !!p.enabled;

      // 💳 v3.0.292 — balíčky = roční licence; cenu řídí dodavatel na vendoru (žádné fixní číslo v appce)
      const priceLabel = isCore
        ? `<span style="font-weight:600;color:var(--success-text)">Zdarma · vždy zapnuto</span>`
        : `<span style="font-weight:700;font-size:14px;color:var(--primary-dark)">🗓️ Roční licence</span> <span style="font-size:11px;color:var(--text-3)">${licensed ? '· zakoupeno' : ''}</span>`;

      let toggleHtml;
      if (isCore) {
        toggleHtml = `<span class="status-pill" style="background:#DCFCE7;color:#166534;padding:4px 12px;border-radius:999px;font-size:11.5px;font-weight:600">✅ Aktivní (core)</span>`;
      } else if (!licensed) {
        toggleHtml = `<span class="status-pill" style="background:#FEE2E2;color:#991B1B;padding:4px 12px;border-radius:999px;font-size:11.5px;font-weight:700" title="Tato featura není ve tvém licenčním klíči — kontaktuj dodavatele">🔒 Zakoupit</span>`;
      } else {
        toggleHtml = `<label class="switch-toggle" style="cursor:pointer;display:inline-flex;align-items:center;gap:8px">
            <input type="checkbox" ${enabled ? 'checked' : ''} onchange="togglePackage('${esc(p.key)}', this.checked)" style="width:20px;height:20px;cursor:pointer;accent-color:var(--primary)">
            <span style="font-size:13px;font-weight:600;color:${enabled ? 'var(--success-text)' : 'var(--text-3)'}">${enabled ? '✅ Aktivní' : 'Vypnuto'}</span>
          </label>`;
      }

      const borderColor = isCore ? 'var(--success-text)'
                       : !licensed ? 'transparent'
                       : enabled ? 'var(--success-text)'
                       : 'var(--border)';
      const bgFilter = !licensed && !isCore ? 'opacity:0.62;filter:saturate(0.4)' : '';

      const lockBanner = !licensed && !isCore ? `
        <div style="background:#FEE2E2;border-left:3px solid #991B1B;padding:8px 12px;border-radius:6px;margin-top:6px;font-size:11.5px;color:#991B1B">
          🔒 <strong>Vyžaduje upgrade licence.</strong> Kontaktuj dodavatele — pošle ti nový klíč co tuto featuru odemkne.
        </div>` : '';

      return `
        <div class="pkg-card" style="background:var(--surface);border:1.5px solid ${borderColor};border-radius:14px;padding:18px;display:flex;flex-direction:column;gap:10px;transition:all 0.15s;${bgFilter}">
          <div style="display:flex;justify-content:space-between;align-items:start;gap:10px;flex-wrap:wrap">
            <div style="flex:1;min-width:0">
              <h3 style="margin:0;font-size:16px;letter-spacing:-0.01em">${esc(p.ikona)} ${esc(p.nazev)}</h3>
              <div style="font-size:12px;color:var(--text-3);margin-top:2px">${esc(p.popis)}</div>
            </div>
            ${toggleHtml}
          </div>
          <div style="display:flex;justify-content:space-between;align-items:center">${priceLabel}</div>
          <ul style="margin:4px 0 0;padding-left:18px;list-style:'›  '">${featuresHtml}</ul>
          ${lockBanner}
        </div>
      `;
    }).join('');

    const licInfo = lic.ok ? `
      <div style="background:#DCFCE7;border-left:3px solid #166534;padding:10px 14px;border-radius:8px;margin-bottom:14px;font-size:12.5px;color:#166534;display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:10px">
        <span>✅ <strong>Licence aktivní</strong> · ${esc(lic.masked)} · ${lic.licensed_pkgs.length} ${lic.licensed_pkgs.length === 1 ? 'balíček' : 'balíčků'} odemčeno</span>
        <button class="btn-secondary" style="font-size:11.5px;padding:4px 10px" onclick="openLicenseUpdate()">🔑 Aktualizovat klíč</button>
      </div>` : `
      <div style="background:#FEE2E2;border-left:3px solid #991B1B;padding:10px 14px;border-radius:8px;margin-bottom:14px;font-size:12.5px;color:#991B1B;display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:10px">
        <span>⚠️ <strong>Licence není nakonfigurována.</strong> Aplikace běží v omezeném módu.</span>
        <button class="btn-secondary" style="font-size:11.5px;padding:4px 10px" onclick="openLicenseUpdate()">🔑 Zadat klíč</button>
      </div>`;

    // 🆕 v3.0.301 — banner roční platnosti (expiring_soon/grace/expired)
    const _val = lic.validity || {};
    const _dl = _val.days_left;
    const _dStr = (n) => `${n} ${n === 1 ? 'den' : (n < 5 ? 'dny' : 'dní')}`;
    let expiryBanner = '';
    if (_val.expiry_state === 'expiring_soon') {
      expiryBanner = `<div style="background:#FEF3C7;border-left:3px solid #92400E;padding:10px 14px;border-radius:8px;margin-bottom:14px;font-size:12.5px;color:#92400E">⏳ <strong>Licence končí ${esc(_val.valid_until || '')}</strong> (za ${_dStr(_dl)}). Obnov u dodavatele, ať se ti nevypnou balíčky.</div>`;
    } else if (_val.expiry_state === 'grace') {
      expiryBanner = `<div style="background:#FEE2E2;border-left:3px solid #991B1B;padding:10px 14px;border-radius:8px;margin-bottom:14px;font-size:12.5px;color:#991B1B">🔴 <strong>Licence vypršela ${esc(_val.valid_until || '')}.</strong> Balíčky ještě jedou ${_dStr(_dl)} (grace období), pak se vypnou. Obnov u dodavatele.</div>`;
    } else if (_val.expiry_state === 'expired') {
      expiryBanner = `<div style="background:#E5E7EB;border-left:3px solid #374151;padding:10px 14px;border-radius:8px;margin-bottom:14px;font-size:12.5px;color:#374151">⛔ <strong>Licence vypršela ${esc(_val.valid_until || '')} — placené balíčky jsou vypnuté.</strong> Core (objednávky, fakturace, POS) jede dál. Obnov licenci u dodavatele.</div>`;
    }

    host.innerHTML = `
      ${licInfo}
      ${expiryBanner}
      <div style="background:#FFF8E5;border-left:3px solid #BA7517;padding:14px 16px;border-radius:8px;margin-bottom:14px;font-size:13px;color:#854F0B;line-height:1.6">
        🎁 <strong>Modulární licence.</strong> Můžeš aktivovat/deaktivovat jen balíčky obsažené ve tvém licenčním klíči (zelené). Zamčené (🔒) si dokoupíš u dodavatele — pošle ti nový klíč, který sem vložíš.
      </div>
      <div class="pkg-grid" style="display:grid;grid-template-columns:repeat(auto-fit, minmax(320px, 1fr));gap:14px">
        ${cards}
      </div>
    `;
  } catch (e) {
    host.innerHTML = `<div class="alert err" style="padding:12px;background:var(--danger-bg);color:var(--danger-text);border-radius:8px">${esc(e.message)}</div>`;
  }
};

// 🔑 Update license modal
window.openLicenseUpdate = function() {
  openModal('🔑 Aktualizace licenčního klíče', `
    <div style="background:#FEF3C7;border-left:3px solid #FBBF24;padding:12px;border-radius:8px;margin-bottom:14px;font-size:12.5px;color:#854F0B">
      ⚠️ <strong>Pozor:</strong> Nový klíč nahradí ten současný v <code>api/config.local.php</code>.
      Předtím se ujisti, že nový klíč máš ze serióžního zdroje od svého dodavatele.
    </div>
    <label class="form-label">Nový licenční klíč</label>
    <input class="form-input" id="new-license-key" placeholder="APPEK-XXXX-XXXX-XXXX-XXXX-XXXX"
           style="font-family:'SF Mono',Menlo,monospace;letter-spacing:0.05em;text-transform:uppercase;font-size:14px">
    <small style="color:var(--text-3);font-size:11.5px;display:block;margin-top:6px">
      Klíč může být v starém (5 skupin) nebo novém formátu (6 skupin s balíčky).
    </small>
    <div class="form-actions" style="margin-top:14px">
      <button class="btn-secondary" onclick="closeModal()">Zrušit</button>
      <div style="flex:1"></div>
      <button class="btn-primary btn-green" onclick="submitLicenseUpdate()">💾 Uložit klíč</button>
    </div>
  `);
};

window.submitLicenseUpdate = async function() {
  const key = document.getElementById('new-license-key')?.value?.trim()?.toUpperCase();
  if (!key) { alert('Zadej klíč.'); return; }
  try {
    const r = await api('admin_license_update.php', {
      method: 'POST',
      body: JSON.stringify({ license_key: key }),
    });
    closeModal();
    alert(t('license_updated', { pkgs: (r.packages || []).join(', ') }));
    location.reload();
  } catch (e) {
    alert('Chyba: ' + e.message);
  }
};

window.togglePackage = async function(key, enabled) {
  try {
    await api('admin_packages.php', { method: 'POST', body: JSON.stringify({ key, enabled }) });
    // 🔥 Pokud uživatel právě VYPÍNÁ balíček, na jehož stránce je → naviguj na dashboard
    if (!enabled && (state.current || '').startsWith('pkg_')) {
      const currentPkg = (state.current || '').slice(4); // strip 'pkg_'
      if (currentPkg === key) {
        toastInfo('Balíček vypnut. Přesouvám na Přehled.');
        setTimeout(() => navigate('dashboard'), 300);
      }
    }
    await loadActivePackages();   // refresh chip + sidebar PRVNÍ (mizí položky)
    await loadBalicky();          // refresh karet (s lock state)
    // 🆕 Po toggle ON vždy re-renderuj aktuální stránku, aby se nové funkce projevily bez nutnosti dalšího kliknutí
    if (enabled) {
      toastSuccess('Balíček aktivován');
      if (state.current) {
        setTimeout(() => navigate(state.current), 200);
      }
    } else {
      toastSuccess('Balíček deaktivován');
    }
  } catch (e) {
    alert('Chyba: ' + e.message);
    loadBalicky();
  }
};

// 🎁 Mapování balíčků → sidebar položky (key, icon, label, page)
const PKG_NAV = {
  cukrarna:   { ikona: '🧁', label: 'Cukrárna',    page: 'pkg_cukrarna',    color: '#FFB6C1' },
  lahudky:    { ikona: '🥗', label: 'Lahůdky',     page: 'pkg_lahudky',     color: '#90EE90' },
  restaurace: { ikona: '🍕', label: 'Restaurace',  page: 'pkg_restaurace',  color: '#FFA500' },
  catering:   { ikona: '🎉', label: 'Catering',    page: 'pkg_catering',    color: '#9370DB' },
  sezona:     { ikona: '🍰', label: 'Sezónní',     page: 'pkg_sezona',      color: '#FF69B4' },
};

window._activePackages = [];

window.loadActivePackages = async function() {
  try {
    const r = await api('admin_packages.php');
    const active = (r.packages || []).filter(p => p.enabled && !p.always_on);
    window._activePackages = active.map(p => p.key);

    // 1. Topbar chip
    const chip = document.getElementById('active-packages-chip');
    if (chip) {
      if (active.length > 0) {
        const ikons = active.map(p => p.ikona).join(' ');
        chip.innerHTML = `🎁 ${ikons} <strong>${active.length} ${active.length === 1 ? 'modul' : (active.length < 5 ? 'moduly' : 'modulů')}</strong>`;
        chip.style.display = 'inline-flex';
        chip.onclick = () => { navigate('nastaveni'); setTimeout(() => { state._nastaveniTab = 'balicky'; renderNastaveni(); }, 100); };
      } else {
        chip.style.display = 'none';
      }
    }

    // 2. Topbar — render malých čtverečků s aktivními balíčky (místo sidebar)
    renderPackageHeaderBadges(active);
    // Sidebar zůstává čistý — žádné balíčky se neinsertují
    document.querySelectorAll('.nav-item.pkg-item').forEach(el => el.remove());

    // 3. POS + Floor Plan v "Aplikace" dropdownu — viditelné pouze pokud aktivní balíček Restaurace
    const hasRest = window._activePackages.includes('restaurace');
    const posBtn = document.getElementById('btn-open-pos');
    if (posBtn) posBtn.style.display = hasRest ? 'flex' : 'none';
    const fpBtn = document.getElementById('btn-open-fp');
    if (fpBtn) fpBtn.style.display = hasRest ? 'flex' : 'none';
  } catch (e) { /* tichá chyba — fresh install nemá API */ }
};

/**
 * 🎁 Vykreslí aktivní balíčky jako malé čtverečky v topbaru (32×32px) s ikonou + tooltipem
 * Klik → navigate na pkg_xxx stránku. Aktivní balíček (=current page) je vizuálně zvýrazněný.
 */
function renderPackageHeaderBadges(active) {
  const host = document.getElementById('topbar-package-badges');
  const sub  = document.getElementById('package-subheader');
  if (!host) return;
  if (!active || active.length === 0) {
    host.innerHTML = '';
    if (sub) sub.classList.remove('show');
    return;
  }
  // 🎁 Subheader se zobrazí pouze pokud jsou aktivované balíčky
  if (sub) sub.classList.add('show');
  const currentPage = state.current || '';
  host.innerHTML = active.map(p => {
    const meta = PKG_NAV[p.key];
    if (!meta) return '';
    const isActive = currentPage === meta.page;
    return `
      <button type="button"
        class="pkg-badge-square ${isActive ? 'is-active' : ''}"
        data-page="${meta.page}"
        data-pkg-key="${esc(p.key)}"
        title="${esc(meta.label)}"
        aria-label="${esc(meta.label)}"
        onclick="navigate('${meta.page}')"
        style="--pkg-color: ${meta.color || '#BA7517'}">
        <span class="pkg-badge-icon">${meta.ikona}</span>
        <span class="pkg-badge-label">${esc(meta.label)}</span>
      </button>
    `;
  }).join('');
}

// Legacy alias — pro případ že někdo volá starý název
function renderPackageSidebarItems(active) {
  return renderPackageHeaderBadges(active);
}

// 🆕 v3.0.92 — Toggle package subheader (user: "klik na ikonku v rohu zajede nahoru, čouhat bude jazyk")
window.togglePackageSubheader = function() {
  const isCollapsed = document.body.classList.toggle('pkg-subheader-collapsed');
  try { localStorage.setItem('appek_pkg_collapsed', isCollapsed ? '1' : '0'); } catch(e) {}
};
// Restore stav po load — pokud user měl naposledy collapsed
(function restorePackageSubheaderState() {
  try {
    if (localStorage.getItem('appek_pkg_collapsed') === '1') {
      document.body.classList.add('pkg-subheader-collapsed');
    }
  } catch(e) {}
})();

