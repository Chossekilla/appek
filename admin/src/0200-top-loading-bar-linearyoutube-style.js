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
// v3.0.621 — množství z DB (DECIMAL …,3) chodí jako "163.000" → vypadá jako 163 tisíc. Formátuj: 163 / 1,5 / 0,25
function fmtQty(n) {
  const v = parseFloat(n);
  return isFinite(v) ? v.toLocaleString('cs-CZ', { maximumFractionDigits: 3 }) : '0';
}
function fmt(n) {
  // 🌍 T4 — locale-aware formátování měny. Default cs-CZ/CZK, ale respektuje getCurrentLocale().
  // 💱 v3.0.283 — display přepočet na cílovou měnu (Nastavení → Přístupy & ceny → Měna).
  //   v3.0.640: dělí se `delitel` ze serveru (= kurz, dokud je ceník v Kč; 1 po trvalém přepočtu
  //   → žádný dvojí přepočet). Bez cílové měny je částka VŽDY v Kč — jazyk rozhraní mění jen
  //   formát čísla (dřív angličtina vypsala „£" / „€" u korunových částek).
  try {
    const m = window._menaCfg;
    if (m && m.zobrazeni === 'mena' && m.kod !== 'CZK' && m.kurz > 0) {
      const del = m.delitel > 0 ? m.delitel : m.kurz;
      return new Intl.NumberFormat('cs-CZ', { style: 'currency', currency: m.kod, minimumFractionDigits: 2, maximumFractionDigits: 2 }).format((n || 0) / del);
    }
    const cfg = (typeof window.getCurrentLocale === 'function') ? window.getCurrentLocale() : null;
    const locale   = (cfg && cfg.locale)   || 'cs-CZ';
    const currency = (m && m.zakladni) || 'CZK';
    return new Intl.NumberFormat(locale, { style: 'currency', currency, minimumFractionDigits: 2, maximumFractionDigits: 2 }).format(n || 0);
  } catch (e) {
    return new Intl.NumberFormat('cs-CZ', { style: 'currency', currency: 'CZK', minimumFractionDigits: 2, maximumFractionDigits: 2 }).format(n || 0);
  }
}

// 💱 v3.0.642 — částka KONKRÉTNÍHO dokladu (faktura/dobropis/objednávka/DL) v JEHO měně.
//   Server vrací doc_mena + doc_celkem (doc_bez/doc_dph/doc_k) spočítané shodně s PDF.
//   NEDĚLÍ kurzem → stará Kč faktura zůstane v Kč i po přepnutí prodeje na EUR; EUR faktura = „K úhradě" z PDF.
//   Statistiky/součty (tržby, reporty) dál přes fmt(). Bez `mena` (starší endpoint) = fmt().
function fmtDoc(n, mena) {
  if (!mena) return fmt(n);
  const kod = String(mena).toUpperCase();
  try {
    if (kod === 'CZK') {
      // Kč doklad: stejný formát jako fmt() bez přepočtu (locale dle jazyka rozhraní) → Kč instalace beze změny
      const cfg = (typeof window.getCurrentLocale === 'function') ? window.getCurrentLocale() : null;
      return new Intl.NumberFormat((cfg && cfg.locale) || 'cs-CZ', { style: 'currency', currency: 'CZK', minimumFractionDigits: 2, maximumFractionDigits: 2 }).format(n || 0);
    }
    return new Intl.NumberFormat('cs-CZ', { style: 'currency', currency: kod, minimumFractionDigits: 2, maximumFractionDigits: 2 }).format(n || 0);
  } catch (e) { return fmt(n); }
}
// Zaokrouhlení na centy OD NULY jako PHP round() — JS Math.round(-3852.5) = -3852 by u dobropisu
//   (záporné částky) dalo o cent jinou částku než PDF. +1e-7 = ochrana proti float 0.4999999.
function r2c(x) { x = parseFloat(x) || 0; return Math.sign(x) * Math.round(Math.abs(x) * 100 + 1e-7) / 100; }
// Celková částka dokladu v jeho měně (fallback castka_celkem) + formát
function docCelkem(d) { return (d && d.doc_mena && d.doc_celkem != null) ? d.doc_celkem : (d ? d.castka_celkem : 0); }
function fmtDocCelkem(d) { return fmtDoc(docCelkem(d), d && d.doc_mena); }
// Uložená částka (cena/ks) → měna dokladu, na centy jako PDF; Kč doklad beze změny
function docK(d, n) {
  const k = d && d.doc_mena && d.doc_k ? parseFloat(d.doc_k) : 0;
  n = parseFloat(n || 0);
  return (!k || k === 1) ? n : r2c(n * k);
}
// Řádek položky (mn × cena) v měně dokladu — shodně s mena_doklad_polozky (round(mn × round(cena×k)))
function docRadek(d, cena, mn) {
  const k = d && d.doc_mena && d.doc_k ? parseFloat(d.doc_k) : 0;
  if (!k || k === 1) return parseFloat(cena || 0) * parseFloat(mn || 0);
  return r2c(docK(d, cena) * parseFloat(mn || 0));
}
// Řádek s DPH v měně dokladu (DPH po řádcích na centy jako PDF); Kč doklad = původní výpočet
function docRadekSDph(d, cena, mn, sazba) {
  const k = d && d.doc_mena && d.doc_k ? parseFloat(d.doc_k) : 0;
  if (!k || k === 1) return parseFloat(cena) * parseFloat(mn) * (1 + parseFloat(sazba) / 100);
  const z = docRadek(d, cena, mn);
  return r2c(z + r2c(z * parseFloat(sazba || 0) / 100));
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

