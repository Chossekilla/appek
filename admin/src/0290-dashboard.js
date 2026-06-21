// =============================================================
// DASHBOARD
// =============================================================

// Akční ikony do každého řádku v dashboard recent kartách
// type: 'obj' | 'dl' | 'fa'  ·  row: data objednávky/DL/faktury
function recentRowIcons(type, row) {
  let dlUrl = null, faUrl = null;
  if (type === 'obj') {
    dlUrl = (row.pocet_dl > 0) ? `../api/dodaci_list.php?id=${row.id}` : null;
    faUrl = (row.pocet_faktur > 0 && row.prvni_faktura_id) ? `../api/faktura.php?id=${row.prvni_faktura_id}` : null;
  } else if (type === 'dl') {
    dlUrl = `../api/dodaci_list.php?` + (row.objednavka_id ? `id=${row.objednavka_id}` : `dl_id=${row.id}`);
    faUrl = (parseInt(row.fakturovano) && row.prvni_faktura_id) ? `../api/faktura.php?id=${row.prvni_faktura_id}` : null;
  } else if (type === 'fa') {
    dlUrl = (row.pocet_dl > 0) ? (row.prvni_dl_objednavka_id ? `../api/dodaci_list.php?id=${row.prvni_dl_objednavka_id}` : `../api/dodaci_list.php?dl_id=${row.prvni_dl_id}`) : null;
    faUrl = `../api/faktura.php?id=${row.id}`;
  }
  const cisloEsc = String(row.cislo || '').replace(/'/g, '');
  const emailEsc = String(row.odberatel_email || '').replace(/'/g, '');
  return `
    <span class="recent-icons">
      ${recentIcon(dlUrl, '📃', 'DL', 'dl')}
      ${recentIcon(faUrl, '💰', 'FA', 'fa')}
      <button type="button" class="recent-icon-btn recent-icon-email" title="Odeslat emailem (PDF v příloze)" onclick="event.stopPropagation();otevritOdeslatEmailem('${type}', ${row.id}, '${cisloEsc}', '${emailEsc}')"><span class="recent-icon-em">✉️</span></button>
      <button type="button" class="recent-icon-btn recent-icon-reorder" title="Znovu objednat (vytvořit novou objednávku)" onclick="event.stopPropagation();noOpakovatZeZdroje('${type}', ${row.id})"><span class="recent-icon-em">🔁</span></button>
    </span>
  `;
}

function recentIcon(url, emoji, label, kind) {
  if (url) {
    return `<a href="${url}" target="_blank" class="recent-icon-btn recent-icon-${kind} active" title="Otevřít ${label} (PDF)"><span class="recent-icon-em">${emoji}</span></a>`;
  }
  return `<span class="recent-icon-btn recent-icon-${kind} disabled" title="${label} zatím nevystaven"><span class="recent-icon-em">${emoji}</span></span>`;
}

// Volitelný řádek "kam to bylo posláno" — pobočka / město
function recentMistoLine(row) {
  if (!row || !row.misto_nazev) return '';
  const detail = [row.misto_ulice, row.misto_mesto].filter(Boolean).join(', ');
  // 🆕 v3.0.39 — Adresa je clickable → otevře Maps (iOS/Android/web)
  const mapsArg = detail ? `event.stopPropagation();openInMaps('${esc(row.misto_ulice || '')}','${esc(row.misto_mesto || '')}')` : '';
  return `<div style="font-size:11px;color:var(--text-3);margin-top:2px">
    📍 ${esc(row.misto_nazev)}${detail ? ' — ' : ''}
    ${detail ? `<a href="#" onclick="${mapsArg}" style="color:var(--primary);text-decoration:none;font-weight:500" title="Otevřít v Mapách">${esc(detail)} 🗺️</a>` : ''}
  </div>`;
}

// 🆕 v3.0.131 — Položky objednávky pod adresou v dashboard kartě „Poslední objednávky".
//   Zobrazí první 3 položky jako chipy + „+N" pill pro zbytek (pocet_polozek z API).
//   User: "pod adresou položky z obj. poslední 3 položky vypsaný a pak +3 nebo +7".
function recentPolozkyLine(o) {
  const items = Array.isArray(o.polozky) ? o.polozky : [];
  if (!items.length) return '';
  const total = +o.pocet_polozek || items.length;
  // qty bez trailing nul: 2 → "2×", 2.5 → "2,5×"
  const qtyFmt = (m) => {
    if (m === null || m === undefined || m === '') return '';
    const n = +m;
    if (!isFinite(n)) return '';
    const s = (n % 1 === 0) ? String(n) : n.toFixed(2).replace(/0+$/, '').replace(/\.$/, '').replace('.', ',');
    return `${s}× `;
  };
  const chips = items.slice(0, 3).map((p) =>
    `<span class="recent-pol-item">${qtyFmt(p.mnozstvi)}${esc(p.nazev || '—')}</span>`
  ).join('');
  const rest = total - Math.min(3, items.length);
  const morePill = rest > 0 ? `<span class="recent-pol-more">+${rest}</span>` : '';
  return `<div class="recent-pol">${chips}${morePill}</div>`;
}

// 🆕 v3.0.133 — Tisk dokladu (obj/dl/fa) na termo-tiskárnu (ESC/POS) s výběrem
//   tiskárny + formátu (Účtenka/Bon). Popup overlay nad detail modalem.
//   User: "ikonka tisku v detailu + volba poslání na tiskárnu z nastavení".
window.tiskNaTermo = async function(docType, docId, cislo) {
  let printers = [];
  try {
    const r = await api('admin_printers.php?action=list');
    printers = (r.printers || []).filter(p => +p.aktivni === 1);
  } catch (e) {
    return toastError('Nepodařilo se načíst tiskárny: ' + e.message, 'Tiskárny');
  }
  if (!printers.length) {
    return toastWarn('Nemáš žádnou aktivní tiskárnu. Přidej ji v Nastavení → Tiskárny.', 'Žádná tiskárna');
  }

  const typLabel = { kasa: 'Kasa', kuchyne: 'Kuchyně', bar: 'Bar', sklad: 'Sklad', vydej: 'Výdej', generic: 'Obecná' };
  const rows = printers.map(p => `
    <button type="button" class="termo-printer-row" data-pid="${p.id}" data-nazev="${esc(String(p.nazev)).replace(/"/g, '&quot;')}">
      <span class="termo-printer-ico">🖨️</span>
      <span class="termo-printer-meta">
        <span class="termo-printer-name">${esc(p.nazev)}</span>
        <span class="termo-printer-sub">${typLabel[p.typ] || esc(p.typ)} · ${esc(p.ip)}:${p.port}</span>
      </span>
      <span class="termo-printer-arrow">→</span>
    </button>
  `).join('');

  const ov = document.createElement('div');
  ov.className = 'termo-pop-overlay';
  ov.innerHTML = `
    <div class="termo-pop-card" role="dialog" aria-label="Tisk na tiskárnu">
      <div class="termo-pop-head">
        <strong>🖨️ Tisk na tiskárnu</strong>
        <span class="termo-pop-doc">${esc(cislo || '')}</span>
        <button type="button" class="termo-pop-close" aria-label="Zavřít">✕</button>
      </div>
      <div class="termo-pop-modes">
        <button type="button" class="termo-mode active" data-mode="receipt">🧾 Účtenka<small>položky · ceny · celkem</small></button>
        <button type="button" class="termo-mode" data-mode="bon">📋 Bon<small>jen položky · množství</small></button>
      </div>
      <div class="termo-pop-label">Vyber tiskárnu (${printers.length}):</div>
      <div class="termo-pop-list">${rows}</div>
    </div>
  `;
  document.body.appendChild(ov);

  let mode = 'receipt';
  const close = () => { ov.remove(); document.removeEventListener('keydown', onEsc); };
  const onEsc = (e) => { if (e.key === 'Escape') close(); };
  document.addEventListener('keydown', onEsc);
  ov.addEventListener('click', (e) => { if (e.target === ov) close(); });
  ov.querySelector('.termo-pop-close').onclick = close;
  ov.querySelectorAll('.termo-mode').forEach(b => {
    b.onclick = () => {
      mode = b.dataset.mode;
      ov.querySelectorAll('.termo-mode').forEach(x => x.classList.toggle('active', x === b));
    };
  });
  ov.querySelectorAll('.termo-printer-row').forEach(row => {
    row.onclick = async () => {
      const pid = +row.dataset.pid;
      const nazev = row.dataset.nazev;
      ov.querySelectorAll('.termo-printer-row').forEach(r => r.disabled = true);
      row.classList.add('is-sending');
      try {
        const res = await api('admin_printers.php?action=print_doc', {
          method: 'POST',
          body: { doc_type: docType, doc_id: docId, printer_id: pid, mode },
        });
        if (res && res.ok) {
          toastSuccess(`Odesláno na „${nazev}" (${mode === 'bon' ? 'bon' : 'účtenka'})`, '🖨️ Vytištěno');
          close();
        } else {
          toastError((res && res.error) || 'Tisk selhal', 'Tiskárna');
          ov.querySelectorAll('.termo-printer-row').forEach(r => r.disabled = false);
          row.classList.remove('is-sending');
        }
      } catch (e) {
        toastError(e.message, 'Tiskárna');
        ov.querySelectorAll('.termo-printer-row').forEach(r => r.disabled = false);
        row.classList.remove('is-sending');
      }
    };
  });
};

// 🆕 v2.9.242 — Dashboard alerts widget (akce vyžadující pozornost)
// Skryje se pokud žádné alerty — žádný šum kdy je vše OK
// 🆕 v2.9.305 — Skryje se i pro role bez práva na cílové stránky (POS user nemůže
// otevřít DL/objednávky/sklad → ukazovat alert nemá smysl, jen frustruje)
function renderDashAlerts(alerts) {
  const role = state.admin?.role || 'admin';
  const allowed = role === 'admin'
    ? null // admin vidí vždy
    : (state.rolePrava[role] || DEFAULT_ROLE_PRAVA[role] || []);
  // Pokud role nemá přístup ani na jednu cílovou stránku alertů — skryj celý widget
  if (allowed && !['objednavky', 'dodaci_listy', 'suroviny', 'sklad'].some(p => allowed.includes(p))) {
    return '';
  }
  const items = [];
  if ((alerts.obj_bez_dl || 0) > 0) {
    items.push({
      icon: '📋',
      label: 'objednávek doručených bez dodacího listu',
      count: alerts.obj_bez_dl,
      tooltip: 'Doručené objednávky starší 3 dny bez DL — vystavit DL',
      // 🆕 v2.9.305 — navigate(page, args) místo setTimeout hacku
      onclick: `state._obj_filtr='bez_dl';navigate('objednavky',{stav:'dorucena'})`,
      severity: 'warn',
    });
  }
  if ((alerts.dl_bez_fa || 0) > 0) {
    items.push({
      icon: '💰',
      label: 'dodacích listů nefakturovaných >7 dní',
      count: alerts.dl_bez_fa,
      tooltip: 'Vystavit faktury — DL starší 7 dní bez FA',
      // 🆕 v2.9.305 — propaguj filtr přímo přes navigate (applyDlFilters arg byl ignorován)
      onclick: `navigate('dodaci_listy',{fakturovano:'0'})`,
      severity: 'warn',
    });
  }
  if ((alerts.sklad_pod_min || 0) > 0) {
    items.push({
      icon: '⚠️',
      label: 'surovin pod minimální zásobou',
      count: alerts.sklad_pod_min,
      tooltip: 'Suroviny pod minimem — objednat nebo přesunout',
      // 🆕 v2.9.305 — suroviny stránka má detekci state._suroviny_pod_minimem
      onclick: `state._suroviny_pod_minimem=true;navigate('suroviny')`,
      severity: 'danger',
    });
  }
  if (items.length === 0) return ''; // nothing to alert about, hide widget
  // 🆕 v3.0.55 — Dismiss persisted v localStorage (1 hodinu, pak se zase ukáže)
  try {
    const dismissedUntil = parseInt(localStorage.getItem('appek_alerts_dismissed_until') || '0', 10);
    if (dismissedUntil > Date.now()) return '';
  } catch (e) {}

  return `
    <div class="dash-alerts">
      <div class="dash-alerts-head">
        <span class="dash-alerts-ico">🔔</span>
        <strong>Akce vyžadující pozornost</strong>
        <span class="dash-alerts-count">${items.length}</span>
        <!-- 🆕 v3.0.55 — Dismiss button: schová widget na 1h -->
        <button class="dash-alerts-dismiss" onclick="dismissDashAlerts()" title="Skrýt na 1 hodinu" aria-label="Skrýt upozornění">✕</button>
      </div>
      <div class="dash-alerts-list">
        ${items.map(it => `
          <button class="dash-alert ${it.severity === 'danger' ? 'is-danger' : 'is-warn'}"
                  onclick="${it.onclick}" title="${esc(it.tooltip)}">
            <span class="dash-alert-ico">${it.icon}</span>
            <span class="dash-alert-count">${it.count}</span>
            <span class="dash-alert-label">${esc(it.label)}</span>
            <span class="dash-alert-arrow">→</span>
          </button>
        `).join('')}
      </div>
    </div>
  `;
}

// 🆕 v3.0.55 — Dismiss dashboard alerts widget na 1 hodinu
window.dismissDashAlerts = function() {
  try {
    localStorage.setItem('appek_alerts_dismissed_until', String(Date.now() + 60*60*1000));
  } catch (e) {}
  const w = document.querySelector('.dash-alerts');
  if (w) {
    w.style.transition = 'opacity 0.22s ease, transform 0.22s ease';
    w.style.opacity = '0';
    w.style.transform = 'translateX(20px)';
    setTimeout(() => w.remove(), 240);
  }
  try { window.haptic && window.haptic('light'); } catch (e) {}
};

// 🆕 v3.0.60 — Swipe-to-dismiss pro .dash-alerts widget (user: "to nova
// objednavka udelat swipe zavřít do strany"). Detekce horizontálního swipe
// na .dash-alerts — pokud uživatel přejede prstem o >80px doprava nebo doleva,
// widget se uzavře (volá dismissDashAlerts). Vertikální scroll zůstává nedotčen.
(function setupDashAlertsSwipeDismiss() {
  let startX = 0, startY = 0, currentX = 0, isTracking = false, target = null;
  const THRESHOLD = 80;     // min px posun aby se aktivoval dismiss
  const ANGLE_TOL = 1.5;    // |Δx| / |Δy| > 1.5 → horizontal swipe (ne scroll)

  function onStart(e) {
    const el = e.target.closest('.dash-alerts');
    if (!el) return;
    // Ignoruj swipe pokud začíná na dismiss button (✕) — ten má vlastní onclick
    if (e.target.closest('.dash-alerts-dismiss')) return;
    target = el;
    const t = e.touches ? e.touches[0] : e;
    startX = currentX = t.clientX;
    startY = t.clientY;
    isTracking = true;
    target.style.transition = 'none';
  }

  function onMove(e) {
    if (!isTracking || !target) return;
    const t = e.touches ? e.touches[0] : e;
    currentX = t.clientX;
    const dx = currentX - startX;
    const dy = t.clientY - startY;
    // Pokud uživatel scrolluje vertikálně (větší dy než dx), zruš tracking
    if (Math.abs(dy) > Math.abs(dx) * ANGLE_TOL && Math.abs(dy) > 10) {
      target.style.transform = '';
      target.style.opacity = '';
      isTracking = false;
      return;
    }
    target.style.transform = `translateX(${dx}px)`;
    target.style.opacity = String(Math.max(0.3, 1 - Math.abs(dx) / 300));
  }

  function onEnd() {
    if (!isTracking || !target) return;
    const dx = currentX - startX;
    target.style.transition = 'opacity 0.22s ease, transform 0.22s ease';
    if (Math.abs(dx) > THRESHOLD) {
      // Swipe potvrzen → dismiss
      target.style.transform = `translateX(${dx > 0 ? 400 : -400}px)`;
      target.style.opacity = '0';
      try { window.haptic && window.haptic('light'); } catch (e) {}
      try {
        localStorage.setItem('appek_alerts_dismissed_until', String(Date.now() + 60 * 60 * 1000));
      } catch (e) {}
      setTimeout(() => { if (target) target.remove(); }, 240);
    } else {
      // Snap back
      target.style.transform = '';
      target.style.opacity = '';
    }
    isTracking = false;
    target = null;
  }

  document.addEventListener('touchstart', onStart, { passive: true });
  document.addEventListener('touchmove',  onMove,  { passive: true });
  document.addEventListener('touchend',   onEnd,   { passive: true });
  document.addEventListener('touchcancel', onEnd,  { passive: true });
})();

async function renderDashboard(filters = {}) {
  // 🆕 v2.9.234 — filter persistence přes localStorage (přežije reload + jiný navigate)
  let savedObdobi = null, savedOd = null, savedDo = null;
  try {
    savedObdobi = localStorage.getItem('appek_dash_obdobi');
    savedOd     = localStorage.getItem('appek_dash_od');
    savedDo     = localStorage.getItem('appek_dash_do');
  } catch (e) {}
  const obdobi = filters.obdobi || state._dashObdobi || savedObdobi || 'mesic';
  const datum_od = filters.datum_od || state._dashOd || savedOd || '';
  const datum_do = filters.datum_do || state._dashDo || savedDo || '';

  // Save selection for re-render (state + localStorage)
  state._dashObdobi = obdobi;
  state._dashOd = datum_od;
  state._dashDo = datum_do;
  try {
    localStorage.setItem('appek_dash_obdobi', obdobi);
    if (datum_od) localStorage.setItem('appek_dash_od', datum_od);
    if (datum_do) localStorage.setItem('appek_dash_do', datum_do);
  } catch (e) {}

  let url = `admin_dashboard.php?obdobi=${obdobi}`;
  if (obdobi === 'vlastni' && datum_od && datum_do) {
    url += `&datum_od=${datum_od}&datum_do=${datum_do}`;
  }

  let d;
  try {
    d = await api(url);
  } catch (e) { d = {}; }
  // 🆕 v2.9.296 — defenzivní fallback pro broken/empty dashboard response
  if (!d || typeof d !== 'object') d = {};
  if (!d.obdobi_stats || typeof d.obdobi_stats !== 'object') {
    d.obdobi_stats = { trzby: 0, objednavek: 0, novych: 0, dorucenych: 0, prumerne_denne: 0 };
  }
  if (!Array.isArray(d.casovy_graf)) d.casovy_graf = [];
  if (!Array.isArray(d.top_odberatele)) d.top_odberatele = [];
  if (!Array.isArray(d.top_vyrobky)) d.top_vyrobky = [];
  if (!Array.isArray(d.vyroba_zitra)) d.vyroba_zitra = [];
  if (!Array.isArray(d.nedavne)) d.nedavne = [];
  if (!Array.isArray(d.nedavne_dl)) d.nedavne_dl = [];
  if (!Array.isArray(d.nedavne_fa)) d.nedavne_fa = [];
  if (!d.alerts || typeof d.alerts !== 'object') d.alerts = {};
  if (!d.obdobi) d.obdobi = obdobi;
  if (!d.dny_v_obdobi) d.dny_v_obdobi = 1;
  // 🐛 v3.0.51 — chybělo guarding pro d.dnes a d.po_splatnosti → JS crash
  if (!d.dnes || typeof d.dnes !== 'object') d.dnes = { trzby: 0, objednavek: 0 };
  if (!d.po_splatnosti || typeof d.po_splatnosti !== 'object') d.po_splatnosti = { pocet: 0, castka: 0 };

  const c = document.getElementById('content');

  const obdobiLabel = {
    dnes: 'dnes',
    tyden: 'tento týden',
    mesic: 'tento měsíc',
    rok: 'tento rok',
    vlastni: 'vlastní rozsah',
  }[d.obdobi] || 'tento měsíc';

  const obdobiRange = (d.datum_od === d.datum_do)
    ? fmtDate(d.datum_od)
    : `${fmtDate(d.datum_od)} – ${fmtDate(d.datum_do)} (${d.dny_v_obdobi} ${d.dny_v_obdobi === 1 ? 'den' : (d.dny_v_obdobi < 5 ? 'dny' : 'dní')})`;

  // 🆕 v2.0.97 — Empty-state banner s demo seed nabídkou
  // 🆕 v2.5.9 — DISMISS button — user může banner skrýt (uloží do localStorage)
  // 🐛 fix v2.9.161 — admin_dashboard.php nikdy nevracelo d.objednavky.celkem
  //   ani d.odberatele.celkem, takže isEmpty bylo VŽDY true a banner permanentní
  //   i po seedu. Místo neexistujících fieldů se ptáme na nedavne/dl/fa arrays.
  const isEmpty = !d.nedavne?.length
    && !d.nedavne_dl?.length
    && !d.nedavne_fa?.length;
  const dismissedDemo = (() => {
    try { return localStorage.getItem('appek_demo_banner_dismissed') === '1'; }
    catch (e) { return false; }
  })();
  const emptyBanner = (isEmpty && !dismissedDemo) ? `
    <div id="empty-demo-banner" class="empty-demo-banner" style="position:relative;background:linear-gradient(135deg,#FFF8E7,#FEF3C7);border:1.5px solid #FBBF24;border-radius:14px;padding:18px 22px;margin-bottom:18px;display:flex;align-items:center;gap:18px;flex-wrap:wrap">
      <button onclick="dismissDemoBanner()" title="Zavřít" aria-label="Zavřít"
        style="position:absolute;top:8px;right:10px;background:transparent;border:none;font-size:18px;color:#854F0B;cursor:pointer;width:28px;height:28px;border-radius:50%;display:flex;align-items:center;justify-content:center;line-height:1;transition:background 0.15s"
        onmouseover="this.style.background='rgba(133,79,11,0.1)'"
        onmouseout="this.style.background='transparent'">✕</button>
      <div style="font-size:42px;line-height:1">🎬</div>
      <div style="flex:1;min-width:260px">
        <div style="font-weight:800;font-size:16px;color:#1d1d1f;margin-bottom:4px">Začni s ukázkovými daty</div>
        <div style="font-size:13px;color:#854F0B;line-height:1.5">
          Tvá databáze je prázdná. Klikni níže a vytvoří se <strong>John Doe s.r.o.</strong> + 4 varianty odběratelů, 10 výrobků, 1 objednávka, dodací list a faktura. Vše jedním klikem. Smažeš později v Nastavení → Údržba.
        </div>
      </div>
      <!-- 🆕 v3.0.177 — seed/reset má JEDNO místo (Nastavení → Údržba). Banner sem jen naviguje. -->
      <button class="btn-primary btn-green" onclick="navigate('nastaveni');setTimeout(()=>{state._nastaveniTab='udrzba';renderNastaveni();},120)" style="font-weight:700;padding:10px 20px;font-size:14px;border:none;border-radius:10px;cursor:pointer;white-space:nowrap;flex-shrink:0">
        🛠️ Naplnit v Údržbě →
      </button>
    </div>
  ` : '';

  // 🆕 v2.9.233 — Dashboard refresh: emoji title, segmented period tabs, hero stat
  // Greeting podle denní doby (víc lidský)
  const hour = new Date().getHours();
  const greeting = hour < 6 ? 'Dobrou noc' : hour < 10 ? 'Dobré ráno' : hour < 17 ? 'Dobrý den' : hour < 22 ? 'Dobrý večer' : 'Dobrou noc';

  c.innerHTML = `
    ${emptyBanner}
    <div class="page-head">
      <div>
        <h1 class="page-title">📊 Dashboard</h1>
        <p class="page-sub">${greeting}, <strong>${esc(state.admin.jmeno)}</strong> · ${new Date().toLocaleDateString('cs-CZ', {weekday:'long', day:'numeric', month:'long'})}</p>
      </div>
    </div>

    <!-- RYCHLÉ AKCE -->
    <div class="quick-actions">
      <button class="quick-action-btn" onclick="navigate('objednavky');setTimeout(()=>otevritNovouObjednavku(),100)">
        <span class="qa-icon">🛒</span>
        <span class="qa-label">
          <span class="qa-title">Nová objednávka</span>
          <span class="qa-sub">Vystavit objednávku</span>
        </span>
      </button>
      <button class="quick-action-btn" onclick="otevritRucniFakturu()">
        <span class="qa-icon">💰</span>
        <span class="qa-label">
          <span class="qa-title">Nová faktura</span>
          <span class="qa-sub">Ručně vystavit fakturu</span>
        </span>
      </button>
      <button class="quick-action-btn" onclick="otevritRucniDl()">
        <span class="qa-icon">📃</span>
        <span class="qa-label">
          <span class="qa-title">Nový dodací list</span>
          <span class="qa-sub">Ručně vystavit DL</span>
        </span>
      </button>
      <button class="quick-action-btn" onclick="navigate('vyroba')">
        <span class="qa-icon">🥖</span>
        <span class="qa-label">
          <span class="qa-title">Výroba</span>
          <span class="qa-sub">Výrobní list, suroviny, sklad, HACCP</span>
        </span>
      </button>
    </div>

    <!-- 🆕 v2.9.242 — Alerts widget (akce vyžadující pozornost) -->
    ${renderDashAlerts(d.alerts || {})}

    <!-- 🆕 v2.9.322 — Health monitor banner (proaktivní detekce errors spike + failed checks) -->
    <div id="dash-health-banner" style="display:none"></div>
    <script>
      // Async — neblokuje render dashboardu. Pokud >5 errors / 15min nebo healthcheck fail → zobraz red banner.
      (async function() {
        try {
          const r = await api('admin_health_monitor.php');
          if (!r) return;
          const banner = document.getElementById('dash-health-banner');
          if (!banner) return;
          const errs = parseInt(r.new_errors_15min || 0);
          const hcOk = r.healthcheck && r.healthcheck.ok;
          if (errs > 5 || !hcOk) {
            const failedNames = ((r.healthcheck && r.healthcheck.checks) || []).filter(c => !c.ok).map(c => c.name).join(', ');
            banner.style.display = 'block';
            banner.innerHTML = \`
              <div style="background:linear-gradient(135deg,#FEE2E2,#FECACA);border:2px solid #DC2626;border-radius:10px;padding:14px 18px;margin-bottom:14px;display:flex;align-items:center;gap:14px;flex-wrap:wrap;cursor:pointer" onclick="navigate('nastaveni');setTimeout(()=>{const el=document.getElementById('ns-errors-block');if(el)el.scrollIntoView({behavior:'smooth',block:'start'})},300)" title="Klikni pro Diagnostiku → Chyby aplikace">
                <div style="font-size:32px;line-height:1">🚨</div>
                <div style="flex:1;min-width:200px">
                  <div style="font-weight:800;font-size:16px;color:#7F1D1D">Detekovány problémy v aplikaci</div>
                  <div style="font-size:13px;color:#991B1B;margin-top:4px;line-height:1.4">
                    \${errs > 5 ? '⚠️ ' + errs + ' chyb v posledních 15 min. ' : ''}
                    \${!hcOk && failedNames ? '🩺 Healthcheck selhal: ' + esc(failedNames) + '. ' : ''}
                    Klikni pro detail v Diagnostice.
                  </div>
                </div>
                <span style="color:#7F1D1D;font-size:20px;font-weight:700">→</span>
              </div>
            \`;
          }
        } catch (e) { /* monitor unavailable — silent */ }
      })();
    </script>

    <!-- TABY OBDOBÍ — v2.9.287 — period-tabs (Skupina A → 1 řádek nowrap), JS short labels mobile -->
    <div class="period-tabs" role="tablist" style="margin-bottom:14px">
      ${periodTabsRender([
        { k: 'dnes',    icon: '📅', l: 'Dnes',         short: 'Dnes' },
        { k: 'tyden',   icon: '📆', l: 'Tento týden',  short: 'Týden' },
        { k: 'mesic',   icon: '🗓️', l: 'Tento měsíc', short: 'Měsíc' },
        { k: 'rok',     icon: '📊', l: 'Tento rok',    short: 'Rok' },
        { k: 'vlastni', icon: '⚙️', l: 'Vlastní',      short: 'Vlastní' },
      ], obdobi, 'dashSetObdobi')}
    </div>

    ${obdobi === 'vlastni' ? `
      <div class="period-custom">
        <label class="filter-date-wrap">
          <span>Od:</span>
          <input class="filter-input" type="date" id="dash-od" value="${datum_od}">
        </label>
        <label class="filter-date-wrap">
          <span>Do:</span>
          <input class="filter-input" type="date" id="dash-do" value="${datum_do}">
        </label>
        <button class="btn-secondary" onclick="dashApplyVlastni()">Použít</button>
      </div>
    ` : ''}

    <p class="period-range">📅 Období: <strong>${obdobiRange}</strong></p>

    <!-- HLAVNÍ STAT BOXY ZA OBDOBÍ — v2.9.240 layout 75/25 + 50/50 (user request) -->
    <!-- PC: Row 1 = [💰 TRŽBY span 3 (75%)] [📅 Dnes span 1 (25%)] -->
    <!--     Row 2 = [🛒 Objednávek span 2 (50%)] [⚠️ Po splatnosti span 2 (50%)] -->
    <!-- Mobile: stack 1 col, Tržby první (KPI hierarchy) -->
    <div class="stat-grid stat-grid-dash">
      <!-- Row 1: Tržby 75% -->
      <div class="stat-card stat-card-trzby">
        <div class="stat-label">💰 Tržby ${obdobiLabel}</div>
        <div class="stat-value stat-value-lg">${fmt(d.obdobi_stats.trzby)}</div>
        ${d.dny_v_obdobi > 1 ? `<div class="stat-sub">⌀ ${fmt(d.obdobi_stats.prumerne_denne)} / den</div>` : '<div class="stat-sub">&nbsp;</div>'}
        ${(Array.isArray(d.trzby_kanaly) && d.trzby_kanaly.filter(k => +k.trzby > 0).length > 1) ? `
        <div style="display:flex;flex-wrap:wrap;gap:6px;margin-top:8px">
          ${d.trzby_kanaly.filter(k => +k.trzby > 0).slice(0, 6).map(k => `
            <span title="${esc(k.label)}: ${k.objednavek}× · ${k.pokladni ? 'pokladní prodej (teče do POS kasy)' : 'mimo POS kasu'}"
                  style="display:inline-flex;align-items:center;gap:5px;font-size:11.5px;font-weight:700;padding:3px 9px;border-radius:999px;background:color-mix(in srgb, ${esc(k.barva)} 14%, transparent);color:var(--text-1);border:1px solid color-mix(in srgb, ${esc(k.barva)} 35%, transparent)">
              <span style="width:8px;height:8px;border-radius:50%;background:${esc(k.barva)};flex-shrink:0"></span>${k.ikona ? esc(k.ikona) + ' ' : ''}${esc(k.label)} · ${fmt(+k.trzby)}${k.pokladni ? ' 🧾' : ''}
            </span>`).join('')}
        </div>` : ''}
        ${(d.casovy_graf && d.casovy_graf.length >= 2) ? `<div class="stat-spark">${sparklineSVG(d.casovy_graf.map(r => +r.trzby), {h: 32, color: 'var(--primary)'})}</div>` : ''}
      </div>
      <!-- Row 1: Dnes objednávek 25% -->
      <div class="stat-card stat-card-dnes">
        <div class="stat-label">📅 Dnes objednávek</div>
        <div class="stat-value">${d.dnes.objednavek}</div>
        <div class="stat-sub">${fmt(d.dnes.trzby)}</div>
      </div>
      <!-- Row 2: Objednávek za období 50% -->
      <div class="stat-card stat-card-obj">
        <div class="stat-label">🛒 Objednávek ${obdobiLabel}</div>
        <div class="stat-value">${d.obdobi_stats.objednavek}</div>
        <div class="stat-sub">${d.obdobi_stats.novych || 0} nových · ${d.obdobi_stats.dorucenych || 0} doručených</div>
        ${(d.casovy_graf && d.casovy_graf.length >= 2) ? `<div class="stat-spark">${sparklineSVG(d.casovy_graf.map(r => +r.objednavek), {h: 24, color: '#0a84ff'})}</div>` : ''}
      </div>
      <!-- Row 2: Po splatnosti 50% -->
      ${d.po_splatnosti.pocet > 0 ? `
        <div class="stat-card stat-warn stat-card-splat" onclick="navigate('faktury');setTimeout(()=>{state._faktury_stav='neuhrazene';renderFaktury()},100)" title="Klikni → faktury filtrované jako neuhrazené">
          <div class="stat-label">⚠️ Po splatnosti</div>
          <div class="stat-value">${fmt(d.po_splatnosti.castka)}</div>
          <div class="stat-sub">${d.po_splatnosti.pocet} ${d.po_splatnosti.pocet === 1 ? 'faktura' : (d.po_splatnosti.pocet < 5 ? 'faktury' : 'faktur')} · klikni →</div>
        </div>
      ` : `
        <div class="stat-card stat-card-splat">
          <div class="stat-label">✓ Po splatnosti</div>
          <div class="stat-value" style="color:var(--success-text)">0</div>
          <div class="stat-sub">vše uhrazeno</div>
        </div>
      `}
    </div>

    <!-- 1) Nedávné doklady — objednávky / DL / faktury vedle sebe -->
    <div class="dashboard-recent-row">
      <div class="card-block recent-card recent-obj">
        <div class="recent-card-head">
          <h3 style="margin:0">🛒 Poslední objednávky</h3>
          <button class="btn-secondary" style="font-size:11px;padding:4px 10px" onclick="navigate('objednavky')">Vše →</button>
        </div>
        ${d.nedavne.length === 0 ? '<div class="empty-state">Žádné objednávky</div>' : `
          <table class="table recent-table" style="font-size:15px">
            <thead><tr><th>Číslo</th><th>Odběratel</th><th>Stav</th><th class="num">Částka</th><th></th></tr></thead>
            <tbody>
              ${d.nedavne.map((o) => `
                <tr class="row-clickable" onclick="openObjednavkaDetail(${o.id})">
                  <td><strong>${esc(o.cislo)}</strong> <span style="font-size:11px;color:var(--text-3);font-weight:400;white-space:nowrap">· ${fmtDate(o.datum_dodani)}</span></td>
                  <td class="recent-odb">
                    <div class="recent-odb-name">${esc(o.odberatel)}</div>
                    ${recentMistoLine(o)}
                    ${recentPolozkyLine(o)}
                  </td>
                  <td><span class="status ${o.stav}" style="font-size:10px">${statusLabel(o.stav)}</span></td>
                  <td class="num"><strong>${fmt(o.castka_celkem)}</strong></td>
                  <td class="recent-actions" onclick="event.stopPropagation()">
                    ${recentRowIcons('obj', o)}
                  </td>
                </tr>
              `).join('')}
            </tbody>
          </table>
        `}
      </div>

      <div class="card-block recent-card recent-dl">
        <div class="recent-card-head">
          <h3 style="margin:0">📃 Poslední DL</h3>
          <button class="btn-secondary" style="font-size:11px;padding:4px 10px" onclick="navigate('dodaci_listy')">Vše →</button>
        </div>
        ${(!d.nedavne_dl || d.nedavne_dl.length === 0) ? '<div class="empty-state">Žádné DL</div>' : `
          <table class="table recent-table" style="font-size:15px">
            <thead><tr><th>Číslo</th><th>Odběratel</th><th>FA</th><th class="num">Částka</th><th></th></tr></thead>
            <tbody>
              ${d.nedavne_dl.map((dl) => `
                <tr class="row-clickable" onclick="openDodaciListDetail(${dl.id})">
                  <td><strong>${esc(dl.cislo)}</strong> <span style="font-size:11px;color:var(--text-3);font-weight:400;white-space:nowrap">· ${fmtDate(dl.datum_vystaveni)}</span></td>
                  <td class="recent-odb">
                    <div class="recent-odb-name">${esc(dl.odberatel)}</div>
                    ${recentMistoLine(dl)}
                    ${recentPolozkyLine(dl)}
                  </td>
                  <td>${dlStavBadge(dl)}</td>
                  <td class="num"><strong>${fmt(dl.castka_celkem)}</strong></td>
                  <td class="recent-actions" onclick="event.stopPropagation()">
                    ${recentRowIcons('dl', dl)}
                  </td>
                </tr>
              `).join('')}
            </tbody>
          </table>
        `}
      </div>

      <div class="card-block recent-card recent-fa">
        <div class="recent-card-head">
          <h3 style="margin:0">💰 Poslední faktury</h3>
          <button class="btn-secondary" style="font-size:11px;padding:4px 10px" onclick="navigate('faktury')">Vše →</button>
        </div>
        ${(!d.nedavne_fa || d.nedavne_fa.length === 0) ? '<div class="empty-state">Žádné faktury</div>' : `
          <table class="table recent-table" style="font-size:15px">
            <thead><tr><th>Číslo</th><th>Odběratel</th><th>Stav</th><th class="num">Částka</th><th></th></tr></thead>
            <tbody>
              ${d.nedavne_fa.map((f) => `
                <tr class="row-clickable" onclick="openFakturaDetail(${f.id})">
                  <td><strong>${esc(f.cislo)}</strong> <span style="font-size:11px;color:var(--text-3);font-weight:400;white-space:nowrap">· splat. ${fmtDate(f.datum_splatnosti)}</span></td>
                  <td class="recent-odb">
                    <div class="recent-odb-name">${esc(f.odberatel)}</div>
                    ${recentMistoLine(f)}
                    ${recentPolozkyLine(f)}
                  </td>
                  <td>${stavUhradyBadge(f.stav_uhrady)}</td>
                  <td class="num"><strong>${fmt(f.castka_celkem)}</strong></td>
                  <td class="recent-actions" onclick="event.stopPropagation()">
                    ${recentRowIcons('fa', f)}
                  </td>
                </tr>
              `).join('')}
            </tbody>
          </table>
        `}
      </div>
    </div>

    <!-- 2) Výroba na zítra + Rozvozové trasy (vedle sebe) -->
    <div class="dashboard-row" style="grid-template-columns: 2fr 1fr">
      <div class="card-block">
        <h3>🥖 Výroba na zítra</h3>
        ${d.vyroba_zitra.length === 0 ? '<div class="empty-state">Žádné objednávky na zítra</div>' : `
          <table class="table">
            <thead><tr><th></th><th>Výrobek</th><th class="num">Množství</th></tr></thead>
            <tbody>
              ${d.vyroba_zitra.map((v) => `
                <tr>
                  <td style="width:40px;">${v.obrazek_url ? `<img src="${esc(v.obrazek_url)}" style="width:32px;height:32px;border-radius:4px;object-fit:cover;">` : ''}</td>
                  <td><strong>${esc(v.nazev)}</strong></td>
                  <td class="num"><strong>${Math.round(v.celkem)} ks</strong></td>
                </tr>
              `).join('')}
            </tbody>
          </table>
        `}
      </div>

      <!-- 🛣️ Rozvozové trasy — quick access dlaždice -->
      <button class="card-block dashboard-rozvozy-tile" onclick="navigate('rozvozy')" title="Otevřít plán rozvozů — DL seskupené podle města/PSČ s pořadovými čísly zastávek pro řidiče">
        <div class="dashboard-rozvozy-ico">🛣️</div>
        <h3 class="dashboard-rozvozy-title">Rozvozové trasy</h3>
        <p class="dashboard-rozvozy-sub">DL podle města a PSČ · plán pro řidiče</p>
        <span class="dashboard-rozvozy-arrow">→</span>
      </button>
    </div>

    <!-- 🗑️ v3.0.1 — PROVOZ widget PŘESUNUT do Restaurace → tab "Provoz" + standalone /provoz.php pro druhý monitor.
         Dashboard se uvolnil pro tržby/přehledy; Provoz teď žije v Restaurace sekci kde má smysl. -->
    <!-- (no widget div here anymore) -->

    <!-- 3) Přehledy ${obdobiLabel} — graf + top odběratelé/výrobky vedle sebe -->
    <div class="dashboard-row dashboard-row-3col">
      ${d.casovy_graf.length > 1 ? `
        <div class="card-block dashboard-compact">
          <h3>📈 Tržby ${obdobiLabel}</h3>
          <div style="position:relative;height:240px">
            <canvas id="dashboard-revenue-chart"></canvas>
          </div>
        </div>
      ` : `
        <div class="card-block dashboard-compact" style="display:flex;flex-direction:column;align-items:center;justify-content:center;text-align:center;padding:30px 20px;min-height:240px">
          <div style="font-size:42px;margin-bottom:10px;opacity:0.4">📈</div>
          <h3 style="margin:0 0 8px;font-size:15px">Graf tržeb ${obdobiLabel}</h3>
          <p style="font-size:12px;color:var(--text-3);margin:0 0 14px;max-width:280px;line-height:1.5">
            Zatím málo dat (${d.casovy_graf.length === 0 ? 'žádné' : '1'} den objednávek). Graf se vyrenderuje po 2+ dnech aktivity.
          </p>
          <!-- 🆕 v3.0.177 — demo seed/reset sjednocen do Nastavení → Údržba (jediné místo) -->
        </div>
      `}

      <div class="card-block dashboard-compact">
        <h3>👥 Top odběratelé ${obdobiLabel}</h3>
        ${d.top_odberatele.length === 0 ? '<div class="empty-state">Žádná data</div>' : `
          <table class="table dashboard-table">
            <tbody>
              ${d.top_odberatele.map((o, i) => `
                <tr style="cursor:pointer" onclick="navigate('odberatele');setTimeout(()=>editOdberatel(${o.id}),100)">
                  <td style="width:24px;color:var(--text-3);font-weight:600">${i + 1}.</td>
                  <td><strong>${esc(o.nazev)}</strong></td>
                  <td class="num"><strong>${fmt(o.trzba)}</strong></td>
                </tr>
              `).join('')}
            </tbody>
          </table>
        `}
      </div>

      <div class="card-block dashboard-compact">
        <h3>🥖 Top výrobky ${obdobiLabel}</h3>
        ${d.top_vyrobky.length === 0 ? '<div class="empty-state">Žádná data</div>' : `
          <table class="table dashboard-table">
            <tbody>
              ${d.top_vyrobky.map((v, i) => `
                <tr>
                  <td style="width:24px;color:var(--text-3);font-weight:600">${i + 1}.</td>
                  <td><strong>${esc(v.nazev)}</strong></td>
                  <td class="num"><strong>${Math.round(v.mnozstvi)} ks</strong></td>
                </tr>
              `).join('')}
            </tbody>
          </table>
        `}
      </div>
    </div>
  `;

  // 🆕 v2.9.68 — graf "Tržby" inicializujeme přímo tady. <script> vložený
  // přes innerHTML se podle DOM specifikace NESPUSTÍ → canvas zůstával prázdný.
  if (d.casovy_graf && d.casovy_graf.length > 1) {
    setTimeout(() => renderDashboardRevenueChart(d.casovy_graf), 50);
  }

  // 🗑️ v3.0.1 — Provoz widget přesunut do Restaurace tab "Provoz".
  // Cleanup timer pro případ že byl spuštěn na jiné stránce.
  if (window._provozRefreshTimer) {
    clearInterval(window._provozRefreshTimer);
    window._provozRefreshTimer = null;
  }
}

// 🆕 v2.9.270 — PROVOZ widget — propojuje 4 moduly Restaurace balíčku
// Robustnost: paralelní fetch, soft-fail per-modul (jeden výpadek nezruší widget),
// 60s auto-refresh když je tab viditelný (visibility API)
async function loadProvozWidget() {
  const host = document.getElementById('dash-provoz-widget');
  if (!host) return;

  // Paralelní načítání 4 modulů — soft-fail per módule
  const safeFetch = (url) => api(url).catch(e => ({ error: e.message || 'chyba' }));
  const [tables, kitchen, couriers, posDnes] = await Promise.all([
    safeFetch('admin_tables.php?action=capacity'),
    safeFetch('admin_kitchen.php'),
    safeFetch('admin_couriers.php'),
    safeFetch('admin_pos.php?action=quick_history&date=' + new Date().toISOString().slice(0, 10)),
  ]);

  // Vyhodnocení dat (s fallbacky pro chyby)
  const cap = tables?.capacity || {};
  const kStats = kitchen?.stats || {};
  const cStats = couriers?.stats || {};
  const posSouhrn = posDnes?.souhrn || {};

  const hasAny = (tables && !tables.error) || (kitchen && !kitchen.error) || (couriers && !couriers.error) || (posDnes && !posDnes.error);
  if (!hasAny) {
    host.style.display = 'none';
    return;
  }
  host.style.display = 'block';

  // Vytíženost stolů (%)
  const totalMist = parseInt(cap.celkem_mist) || 0;
  const obsazenoMist = parseInt(cap.obsazeno_mist) || 0;
  const obsazenoPct = totalMist > 0 ? Math.round(obsazenoMist / totalMist * 100) : 0;

  // 🆕 v2.9.277 — Setup hint: pokud uživatel nemá nastavené stoly/kuchyně/kurýrky → ukáže onboarding banner místo widgetu
  const setupChybi = [];
  if (totalMist === 0 && (!tables || !tables.error)) setupChybi.push({ icon: '🪑', label: 'Stoly', action: 'window.openFloorplanWindow?.()', hint: 'Vytvořit floor plan (stoly + zóny)' });
  if ((!kitchen?.stanice || kitchen.stanice.length === 0) && (!kitchen || !kitchen.error)) setupChybi.push({ icon: '👨‍🍳', label: 'Kuchyně stanice', action: "navigate('pkg_restaurace');setTimeout(()=>{state._restTab='kitchen';renderRestaurantPage()},120)", hint: 'Pec, gril, studená kuchyně, bar…' });
  if ((cStats.kuryru_aktivnich || 0) === 0 && (!couriers || !couriers.error)) setupChybi.push({ icon: '🛵', label: 'Kurýrky', action: "navigate('pkg_restaurace');setTimeout(()=>{state._restTab='couriers';renderRestaurantPage()},120)", hint: 'Vlastní řidiči nebo Wolt/Bolt' });

  if (setupChybi.length >= 2) {
    // Zobraz onboarding hint místo widgetu (pokud chybí 2+ věci → ještě se nezačalo)
    host.innerHTML = `
      <div class="card-block" style="padding:18px 22px;background:linear-gradient(135deg,#FFFBEB,#FFF8F0);border:1px solid #F0D9B8">
        <div style="display:flex;align-items:start;gap:14px;flex-wrap:wrap">
          <div style="font-size:36px;line-height:1">🍕</div>
          <div style="flex:1;min-width:240px">
            <h3 style="margin:0 0 6px;font-size:16px;color:#854F0B">Restaurace balíček — nastavení</h3>
            <p style="margin:0 0 12px;font-size:13px;color:#854F0B;line-height:1.5">
              Pro plné fungování dashboard Provoz widgetu nastav:
            </p>
            <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:8px;margin-bottom:10px">
              ${setupChybi.map(s => `
                <button class="btn-secondary" onclick="${s.action}" style="display:flex;align-items:center;gap:10px;padding:10px 12px;text-align:left;font-size:13px">
                  <span style="font-size:22px">${s.icon}</span>
                  <div style="flex:1">
                    <strong>${s.label}</strong>
                    <div style="font-size:11px;color:var(--text-3);font-weight:400">${s.hint}</div>
                  </div>
                  <span style="color:var(--text-3)">→</span>
                </button>
              `).join('')}
            </div>
            <p style="margin:0;font-size:11px;color:#854F0B;opacity:0.7">
              ℹ️ Až nastavíš všechno, widget se přepne na živý přehled (stoly · kuchyně · rozvoz · POS dnes).
            </p>
          </div>
        </div>
      </div>
    `;
    return;
  }

  // Kitchen load (%)
  const kitchenLoad = parseInt(kStats.global_load) || 0;
  const kIsFull = !!kStats.is_full;

  // POS dnes
  const posTrzby = parseFloat(posSouhrn['tržby']) || 0;
  const posPocet = parseInt(posSouhrn.pocet) || 0;

  // Rozvozy aktivní
  const rozvozyAkt = parseInt(cStats.rozvozy_aktivni) || 0;
  const rozvozyDnes = parseInt(cStats.rozvozy_dnes_doruceno) || 0;

  // Barva podle vytížení
  const loadColor = (pct) => pct >= 90 ? '#DC2626' : pct >= 70 ? '#F59E0B' : pct >= 40 ? '#3B82F6' : '#10B981';

  host.innerHTML = `
    <div class="card-block" style="padding:18px 20px;background:linear-gradient(135deg,#1a1d24,#2d3441);color:#fff;border:none">
      <div style="display:flex;justify-content:space-between;align-items:center;gap:14px;flex-wrap:wrap;margin-bottom:14px">
        <div style="display:flex;align-items:center;gap:12px">
          <span style="font-size:32px;filter:drop-shadow(0 2px 4px rgba(0,0,0,0.3))">🍕</span>
          <div>
            <h3 style="margin:0;font-size:17px;font-weight:700">Provoz · živý přehled</h3>
            <p style="margin:2px 0 0;font-size:12px;opacity:0.6">Stoly · Kuchyně · Rozvoz · POS dnes · <span id="provoz-updated" style="font-variant-numeric:tabular-nums">${new Date().toLocaleTimeString('cs-CZ', {hour:'2-digit', minute:'2-digit', second:'2-digit'})}</span></p>
          </div>
        </div>
        <div style="display:flex;gap:6px;flex-wrap:wrap">
          <!-- 🆕 v2.9.279 — Manual refresh button + interval (dropdown) -->
          <button class="btn-secondary" style="background:rgba(255,255,255,0.08);border-color:rgba(255,255,255,0.12);color:#fff;font-size:12px;padding:6px 10px"
                  onclick="loadProvozWidget()" title="Manuálně obnovit data (auto-refresh běží na pozadí)">🔄</button>
          <select onchange="window._provozIntervalSec=parseInt(this.value);loadProvozWidget()"
                  style="background:rgba(255,255,255,0.08);border:1px solid rgba(255,255,255,0.12);color:#fff;font-size:12px;padding:5px 8px;border-radius:6px;cursor:pointer"
                  title="Frekvence automatického obnovení">
            <option value="10" ${(window._provozIntervalSec||10)===10?'selected':''} style="background:#1a1d24">10s</option>
            <option value="30" ${(window._provozIntervalSec||10)===30?'selected':''} style="background:#1a1d24">30s</option>
            <option value="60" ${(window._provozIntervalSec||10)===60?'selected':''} style="background:#1a1d24">60s</option>
            <option value="120" ${(window._provozIntervalSec||10)===120?'selected':''} style="background:#1a1d24">2min</option>
            <option value="300" ${(window._provozIntervalSec||10)===300?'selected':''} style="background:#1a1d24">5min</option>
          </select>
          <button class="btn-secondary" style="background:rgba(255,255,255,0.08);border-color:rgba(255,255,255,0.12);color:#fff;font-size:12px;padding:6px 12px"
                  onclick="window.openFloorplanWindow?.()" title="Floor Plan editor">🪑 Stoly</button>
          <button class="btn-secondary" style="background:rgba(255,255,255,0.08);border-color:rgba(255,255,255,0.12);color:#fff;font-size:12px;padding:6px 12px"
                  onclick="window.openPOSWindow?.()" title="POS Kasa">🧾 POS</button>
        </div>
      </div>

      <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:12px">
        <!-- Stoly -->
        <div class="provoz-tile" onclick="window.openFloorplanWindow?.()" style="background:rgba(255,255,255,0.04);border:1px solid rgba(255,255,255,0.08);border-left:4px solid ${loadColor(obsazenoPct)};border-radius:10px;padding:12px 14px;cursor:pointer;transition:background 0.15s ease">
          <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:6px">
            <span style="font-size:11px;text-transform:uppercase;letter-spacing:0.5px;opacity:0.7">🪑 Stoly</span>
            <span style="font-size:11px;opacity:0.6">${cap.pocet_stolu || 0} stolů</span>
          </div>
          <div style="display:flex;align-items:baseline;gap:6px">
            <strong style="font-size:24px">${obsazenoPct}%</strong>
            <span style="font-size:12px;opacity:0.7">obsazenost</span>
          </div>
          <div style="margin-top:4px;font-size:11px;opacity:0.6">
            ${obsazenoMist} / ${totalMist} míst · ${cap.pocet_obsazenych || 0} obsazených stolů
          </div>
          <div style="margin-top:6px;height:4px;background:rgba(255,255,255,0.08);border-radius:2px;overflow:hidden">
            <div style="width:${obsazenoPct}%;height:100%;background:${loadColor(obsazenoPct)};transition:width 0.3s ease"></div>
          </div>
        </div>

        <!-- Kuchyň -->
        <div class="provoz-tile" onclick="navigate('pkg_restaurace');setTimeout(()=>{state._restTab='kitchen';renderRestaurantPage();},120)" style="background:rgba(255,255,255,0.04);border:1px solid rgba(255,255,255,0.08);border-left:4px solid ${loadColor(kitchenLoad)};border-radius:10px;padding:12px 14px;cursor:pointer;transition:background 0.15s ease">
          <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:6px">
            <span style="font-size:11px;text-transform:uppercase;letter-spacing:0.5px;opacity:0.7">👨‍🍳 Kuchyně</span>
            ${kIsFull ? '<span style="background:#DC2626;font-size:9px;padding:1px 6px;border-radius:6px;font-weight:700">PLNÁ</span>' : ''}
          </div>
          <div style="display:flex;align-items:baseline;gap:6px">
            <strong style="font-size:24px">${kitchenLoad}%</strong>
            <span style="font-size:12px;opacity:0.7">vytížení</span>
          </div>
          <div style="margin-top:4px;font-size:11px;opacity:0.6">
            ${kStats.active_orders || 0} aktivních · ${kStats.preparing || 0} se vaří · ${kStats.ready || 0} hotových
          </div>
          <div style="margin-top:6px;height:4px;background:rgba(255,255,255,0.08);border-radius:2px;overflow:hidden">
            <div style="width:${kitchenLoad}%;height:100%;background:${loadColor(kitchenLoad)};transition:width 0.3s ease"></div>
          </div>
        </div>

        <!-- Rozvozy -->
        <div class="provoz-tile" onclick="navigate('rozvozy')" style="background:rgba(255,255,255,0.04);border:1px solid rgba(255,255,255,0.08);border-left:4px solid #10B981;border-radius:10px;padding:12px 14px;cursor:pointer;transition:background 0.15s ease">
          <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:6px">
            <span style="font-size:11px;text-transform:uppercase;letter-spacing:0.5px;opacity:0.7">🛵 Rozvoz</span>
            <span style="font-size:11px;opacity:0.6">${cStats.kuryru_aktivnich || 0} kurýrů</span>
          </div>
          <div style="display:flex;align-items:baseline;gap:6px">
            <strong style="font-size:24px">${rozvozyAkt}</strong>
            <span style="font-size:12px;opacity:0.7">aktivních</span>
          </div>
          <div style="margin-top:4px;font-size:11px;opacity:0.6">
            ✓ ${rozvozyDnes} dnes doručeno · ${cStats.rozvozy_dnes_planovano || 0} naplánováno
          </div>
        </div>

        <!-- POS dnes -->
        <div class="provoz-tile" onclick="window.openPOSWindow?.()" style="background:rgba(255,255,255,0.04);border:1px solid rgba(255,255,255,0.08);border-left:4px solid #BA7517;border-radius:10px;padding:12px 14px;cursor:pointer;transition:background 0.15s ease">
          <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:6px">
            <span style="font-size:11px;text-transform:uppercase;letter-spacing:0.5px;opacity:0.7">🧾 POS dnes</span>
            <span style="font-size:11px;opacity:0.6">${posPocet} účt.</span>
          </div>
          <div style="display:flex;align-items:baseline;gap:6px">
            <strong style="font-size:22px">${Math.round(posTrzby).toLocaleString('cs-CZ')} Kč</strong>
          </div>
          <div style="margin-top:4px;font-size:11px;opacity:0.6">
            💵 ${Math.round(posSouhrn.hotove || 0).toLocaleString('cs-CZ')} hotově · 💳 ${Math.round(posSouhrn.karta || 0).toLocaleString('cs-CZ')} kartou
          </div>
        </div>
      </div>

      ${kIsFull || obsazenoPct >= 90 ? `
        <div style="margin-top:12px;padding:8px 12px;background:rgba(220,38,38,0.15);border:1px solid rgba(220,38,38,0.3);border-radius:8px;font-size:12px;color:#FCA5A5">
          ⚠ ${kIsFull && obsazenoPct >= 90 ? 'Kuchyně i stoly jsou plné — zvažte blokaci nových objednávek' : kIsFull ? 'Kuchyně je plná — auto-block aktivní (pokud nastavené)' : 'Stoly jsou skoro plné'}
        </div>
      ` : ''}
    </div>
  `;

  // 🆕 v2.9.277 — Auto-refresh: cleanup pokud widget zmizí
  // 🆕 v2.9.279 — Configurable interval (default 60s, user-changeable v dropdownu)
  if (window._provozRefreshTimer) {
    clearInterval(window._provozRefreshTimer);
  }
  const intervalSec = window._provozIntervalSec || 10;
  window._provozRefreshTimer = setInterval(() => {
    const el = document.getElementById('dash-provoz-widget');
    if (!el) {
      // Widget zmizel (user navigoval jinam) → cleanup
      clearInterval(window._provozRefreshTimer);
      window._provozRefreshTimer = null;
      return;
    }
    if (document.visibilityState === 'visible') {
      loadProvozWidget();
    }
  }, intervalSec * 1000);
}

window.dashSetObdobi = function(obdobi) {
  renderDashboard({ obdobi });
};

// 🆕 v2.9.287 — Helper pro sjednocený period-tabs render (Dashboard + Faktury/Obj/DL + Vyroba prehled)
// 🆕 v3.0.97 — User: "tady mělo být D T M R V jenom přeci" — mobile = vždy 1-letter
//   ≤700px (mobile):  1. písmeno (D / T / M / R / V) — z t.x nebo first letter
//   desktop:          full label (t.l)
//   Předtím rozlišovalo mid-band (461-700px = "Měs") což user nechce.
// CLASS .period-tab = Skupina A = 1 řádek nowrap shrink
window.periodTabsRender = function(tabs, currentKey, onclickFn) {
  const w = (typeof window !== 'undefined') ? window.innerWidth : 1024;
  const isMob = w <= 700;
  return tabs.map(t => {
    const label = isMob
      ? (t.x || (t.short || t.l || '').charAt(0).toUpperCase())
      : t.l;
    const cls = currentKey === t.k ? 'period-tab active' : 'period-tab';
    return `<button type="button" class="${cls}" onclick="${onclickFn}('${t.k}')" aria-selected="${currentKey === t.k}"><span class="period-tab-icon">${t.icon}</span><span class="period-tab-text">${label}</span></button>`;
  }).join('');
};

// 🆕 v2.9.285 — Resize listener pro re-render period tabs (mobile/desktop přepnutí labelů)
// 🆕 v2.9.287 — Rozšířeno na všechny page co používají period-tabs
// Debounced 250ms aby resize spam nepřetížil.
(function() {
  let resizeTimer;
  let lastIsMobile = typeof window !== 'undefined' && window.innerWidth <= 700;
  // Mapování route → render funkce (musí být dostupné při resize)
  const RENDER_BY_PAGE = {
    'dashboard': () => typeof renderDashboard === 'function' && renderDashboard({}),
    'faktury':   () => typeof renderFaktury === 'function' && renderFaktury(),
    'objednavky':() => typeof renderObjednavky === 'function' && renderObjednavky(),
    'dodaci_listy': () => typeof renderDodaciListy === 'function' && renderDodaciListy(),
    'export_vyroby': () => typeof renderExportVyroby === 'function' && renderExportVyroby(),
  };
  // 🆕 v3.0.56 — Tracking ≤400 (extreme) state taky
  let lastIsExtreme = typeof window !== 'undefined' && window.innerWidth <= 400;
  window.addEventListener('resize', () => {
    clearTimeout(resizeTimer);
    resizeTimer = setTimeout(() => {
      const isMobile = window.innerWidth <= 700;
      const isExtreme = window.innerWidth <= 400;
      if (isMobile !== lastIsMobile || isExtreme !== lastIsExtreme) {
        lastIsMobile = isMobile;
        lastIsExtreme = isExtreme;
        const fn = RENDER_BY_PAGE[state.current];
        if (fn) {
          try { fn(); } catch (e) { /* page render race — skip */ }
        }
      }
    }, 250);
  });
})();

window.dashApplyVlastni = function() {
  const od = document.getElementById('dash-od').value;
  const do_ = document.getElementById('dash-do').value;
  if (!od || !do_) return alert('Vyplňte oba datumy');
  if (od > do_) return alert('Datum "od" musí být před "do"');
  renderDashboard({ obdobi: 'vlastni', datum_od: od, datum_do: do_ });
};

function renderCasovyGraf(data) {
  if (data.length === 0) return '<div class="empty-state">Žádná data</div>';
  const max = Math.max(...data.map(d => parseFloat(d.trzby))) || 1;
  const sum = data.reduce((a, d) => a + parseFloat(d.trzby), 0);

  // Pokud máme méně než ~30 bodů, ukážeme bar chart, jinak line chart
  return `
    <div class="chart-container">
      <div class="chart-bars" style="--max:${max}">
        ${data.map((d) => {
          const v = parseFloat(d.trzby);
          const pct = (v / max * 100).toFixed(1);
          return `
            <div class="chart-bar-wrap" title="${fmtDate(d.den)}: ${fmt(v)} (${d.objednavek} obj.)">
              <div class="chart-bar" style="height:${pct}%"></div>
              <div class="chart-bar-label">${new Date(d.den).getDate()}.${new Date(d.den).getMonth() + 1}.</div>
            </div>
          `;
        }).join('')}
      </div>
      <div class="chart-summary">
        Celkem za období: <strong>${fmt(sum)}</strong>
      </div>
    </div>
  `;
}

