// =============================================================
// 📊 TOP LOADING BAR — Linear/YouTube style
// =============================================================
(function() {
  let bar = null, count = 0, timer = null;
  function ensureBar() {
    if (!bar) {
      const wrap = document.createElement('div');
      wrap.className = 'top-progress';
      bar = document.createElement('div');
      bar.className = 'top-progress-bar';
      wrap.appendChild(bar);
      document.body.appendChild(wrap);
    }
    return bar;
  }
  function start() {
    const b = ensureBar();
    count++;
    b.style.opacity = '1';
    b.style.width = Math.min(85, parseFloat(b.style.width) || 0) + Math.random() * 15 + 10 + '%';
  }
  function done() {
    count = Math.max(0, count - 1);
    if (count > 0) return;
    const b = ensureBar();
    b.style.width = '100%';
    clearTimeout(timer);
    timer = setTimeout(() => {
      b.style.opacity = '0';
      setTimeout(() => { b.style.width = '0%'; }, 300);
    }, 200);
  }
  window.topProgressStart = start;
  window.topProgressDone  = done;
})();
function fmt(n) {
  // 🌍 T4 — locale-aware formátování měny. Default cs-CZ/CZK, ale respektuje getCurrentLocale().
  // 💱 v3.0.283 — display přepočet na cílovou měnu (Nastavení → Přístupy & ceny → Měna).
  //   DB hodnoty jsou VŽDY v Kč; při zobrazeni='mena' se dělí kurzem a formátuje cílovou měnou.
  try {
    const m = window._menaCfg;
    if (m && m.zobrazeni === 'mena' && m.kod !== 'CZK' && m.kurz > 0) {
      return new Intl.NumberFormat('cs-CZ', { style: 'currency', currency: m.kod, minimumFractionDigits: 2, maximumFractionDigits: 2 }).format((n || 0) / m.kurz);
    }
    const cfg = (typeof window.getCurrentLocale === 'function') ? window.getCurrentLocale() : null;
    const locale   = (cfg && cfg.locale)   || 'cs-CZ';
    const currency = (cfg && cfg.currency) || 'CZK';
    return new Intl.NumberFormat(locale, { style: 'currency', currency, minimumFractionDigits: 2, maximumFractionDigits: 2 }).format(n || 0);
  } catch (e) {
    return new Intl.NumberFormat('cs-CZ', { style: 'currency', currency: 'CZK', minimumFractionDigits: 2, maximumFractionDigits: 2 }).format(n || 0);
  }
}

// URL na PDF dodacího listu — ruční DL (bez objednávky) jdou přes ?dl_id, ostatní přes ?id (objednávka)
function dlPdfUrl(d) {
  if (d.objednavka_id) return `../api/dodaci_list.php?id=${d.objednavka_id}`;
  return `../api/dodaci_list.php?dl_id=${d.id}`;
}

/**
 * Unifikovaný badge pro odkaz na DL / FA / OBJ.
 * type: 'dl' | 'fa' | 'obj'
 * url:  cíl odkazu (string) nebo null pokud nedostupné
 * label: text uvnitř (např. "📃 DL", "💰 FA", "🛒 OBJ")
 * Když url je null/empty, vrátí disabled (šedý) badge.
 * Když k dispozici, vrátí klikatelný odkaz s barvou podle typu (DL = amber, FA = zelená, OBJ = modrá).
 */
function docBadge(type, url, label, opts = {}) {
  const title = opts.title || '';
  if (url) {
    const onclick = opts.onclick ? `onclick="${opts.onclick};return false"` : '';
    const target  = opts.onclick ? '' : 'target="_blank"';
    return `<a href="${onclick ? '#' : url}" ${target} ${onclick} class="doc-badge ${type}" title="${esc(title)}">${label}</a>`;
  }
  // Nedostupné: ne-klikatelný šedý badge
  return `<span class="doc-badge ${type} unavailable" title="${esc(opts.disabledTitle || 'Není k dispozici')}">${label}</span>`;
}
function fmtDate(s) {
  if (!s) return '';
  const d = new Date(s);
  return d.toLocaleDateString('cs-CZ', { day: 'numeric', month: 'numeric', year: 'numeric' });
}
function fmtDateTime(s) {
  if (!s) return '';
  const d = new Date(s);
  return d.toLocaleDateString('cs-CZ', { day: 'numeric', month: 'numeric' }) + ' ' + d.toLocaleTimeString('cs-CZ', { hour: '2-digit', minute: '2-digit' });
}
function statusLabel(s) {
  const m = { nova: 'Nová', potvrzena: 'Potvrzená', ve_vyrobe: 'Ve výrobě', pripravena: 'Připravená', expedovana: 'Expedována', dorucena: 'Doručena', zrusena: 'Zrušena' };
  return m[s] || s;
}

