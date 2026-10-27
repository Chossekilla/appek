// =============================================================
// 📅 v3.0.620 — KALENDÁŘ z data/svátku v topbaru (na mobilu u loga)
// =============================================================
// Klik na „úterý · 27. 10. 2026 · 🎉 Šarlota" → popup s měsícem: dnešek, víkendy, státní svátky ČR
// (vč. Velkého pátku a Velikonočního pondělí), klik na den = jmeniny (SVATKY_CZ z 0250) + státní svátek.
// Lehké: žádné API, DOM jen při otevření (~50 tlačítek).
(function () {
  const MESICE = ['leden', 'únor', 'březen', 'duben', 'květen', 'červen', 'červenec', 'srpen', 'září', 'říjen', 'listopad', 'prosinec'];
  const DNY = ['neděle', 'pondělí', 'úterý', 'středa', 'čtvrtek', 'pátek', 'sobota'];
  const STATNI = {
    '1.1': 'Den obnovy samostatného českého státu, Nový rok', '1.5': 'Svátek práce', '8.5': 'Den vítězství',
    '5.7': 'Den slovanských věrozvěstů Cyrila a Metoděje', '6.7': 'Den upálení mistra Jana Husa',
    '28.9': 'Den české státnosti', '28.10': 'Den vzniku samostatného československého státu',
    '17.11': 'Den boje za svobodu a demokracii', '24.12': 'Štědrý den', '25.12': '1. svátek vánoční', '26.12': '2. svátek vánoční',
  };
  // Velikonoční neděle (anonymní gregoriánský algoritmus)
  function velikonoce(y) {
    const a = y % 19, b = Math.floor(y / 100), c = y % 100, d = Math.floor(b / 4), e = b % 4;
    const f = Math.floor((b + 8) / 25), g = Math.floor((b - f + 1) / 3), h = (19 * a + b - d - g + 15) % 30;
    const i = Math.floor(c / 4), k = c % 4, l = (32 + 2 * e + 2 * i - h - k) % 7, m = Math.floor((a + 11 * h + 22 * l) / 451);
    const mes = Math.floor((h + l - 7 * m + 114) / 31), den = ((h + l - 7 * m + 114) % 31) + 1;
    return new Date(y, mes - 1, den);
  }
  function statniSvatek(d) {
    const k = d.getDate() + '.' + (d.getMonth() + 1);
    if (STATNI[k]) return STATNI[k];
    const ve = velikonoce(d.getFullYear());
    const diff = Math.round((new Date(d.getFullYear(), d.getMonth(), d.getDate()) - ve) / 86400000);
    if (diff === -2) return 'Velký pátek';
    if (diff === 1) return 'Velikonoční pondělí';
    return '';
  }
  const jmeniny = (d) => (typeof SVATKY_CZ !== 'undefined' && SVATKY_CZ[d.getDate() + '.' + (d.getMonth() + 1)]) || '';

  let view = null, sel = null, pop = null;

  function css() {
    if (document.getElementById('kal-pop-css')) return;
    const s = document.createElement('style');
    s.id = 'kal-pop-css';
    s.textContent = `
      #topbar-datum-svatek{cursor:pointer}
      .kal-pop{position:fixed;z-index:100001;width:300px;max-width:calc(100vw - 16px);background:var(--surface);color:var(--text-1);
        border:1px solid var(--border);border-radius:14px;box-shadow:0 12px 36px rgba(0,0,0,.18);padding:12px;font-size:13px}
      .kal-head{display:flex;align-items:center;justify-content:space-between;gap:6px;margin-bottom:8px}
      .kal-head strong{font-size:15px;text-transform:capitalize}
      .kal-nav{width:36px;height:36px;border-radius:9px;border:1px solid var(--border);background:var(--surface-2);color:var(--text-1);font-size:17px;cursor:pointer}
      .kal-grid{display:grid;grid-template-columns:repeat(7,1fr);gap:3px;text-align:center}
      .kal-dow{font-size:11px;font-weight:700;color:var(--text-3);padding:2px 0}
      .kal-d{height:36px;border-radius:8px;border:0;background:transparent;color:var(--text-1);font:inherit;font-weight:600;cursor:pointer;position:relative}
      .kal-d:hover{background:var(--surface-2)}
      .kal-d.out{color:var(--text-3);opacity:.45}
      .kal-d.we{color:var(--danger-text)}
      .kal-d.sv::after{content:'';position:absolute;left:50%;bottom:4px;width:5px;height:5px;margin-left:-2.5px;border-radius:50%;background:var(--danger-text)}
      .kal-d.today{background:var(--primary);color:#fff}
      .kal-d.sel:not(.today){outline:2px solid var(--primary);outline-offset:-2px}
      .kal-info{margin-top:10px;padding-top:10px;border-top:1px solid var(--border);line-height:1.5;min-height:40px}
      .kal-info b{text-transform:capitalize}
      .kal-foot{display:flex;justify-content:flex-end;margin-top:8px}
      .kal-foot button{padding:7px 14px;border-radius:8px;border:1px solid var(--border);background:var(--surface-2);color:var(--text-1);cursor:pointer;font:inherit;font-size:12px;font-weight:600}
    `;
    document.head.appendChild(s);
  }

  function render() {
    if (!pop) return;
    const y = view.getFullYear(), m = view.getMonth();
    const today = new Date(); const tk = today.toDateString();
    const first = new Date(y, m, 1);
    const start = new Date(y, m, 1 - ((first.getDay() + 6) % 7));   // pondělí týdne s 1. dnem
    let cells = '';
    for (let i = 0; i < 42; i++) {
      const d = new Date(start.getFullYear(), start.getMonth(), start.getDate() + i);
      if (i === 35 && d.getMonth() !== m) break;                       // 6. řádek jen když je potřeba
      const sv = statniSvatek(d);
      const cls = ['kal-d'];
      if (d.getMonth() !== m) cls.push('out');
      if (d.getDay() === 0 || d.getDay() === 6) cls.push('we');
      if (sv) cls.push('sv');
      if (d.toDateString() === tk) cls.push('today');
      if (sel && d.toDateString() === sel.toDateString()) cls.push('sel');
      cells += `<button type="button" class="${cls.join(' ')}" data-d="${d.getFullYear()}-${d.getMonth()}-${d.getDate()}" title="${esc(sv || jmeniny(d) || '')}">${d.getDate()}</button>`;
    }
    const s = sel || today;
    const sv = statniSvatek(s), jm = jmeniny(s);
    pop.innerHTML = `
      <div class="kal-head">
        <button type="button" class="kal-nav" data-nav="-1" aria-label="Předchozí měsíc">‹</button>
        <strong>${MESICE[m]} ${y}</strong>
        <button type="button" class="kal-nav" data-nav="1" aria-label="Další měsíc">›</button>
      </div>
      <div class="kal-grid">${['Po', 'Út', 'St', 'Čt', 'Pá', 'So', 'Ne'].map(x => `<div class="kal-dow">${x}</div>`).join('')}${cells}</div>
      <div class="kal-info"><b>${DNY[s.getDay()]}</b> ${s.getDate()}. ${s.getMonth() + 1}. ${s.getFullYear()}
        ${sv ? `<div style="color:var(--danger-text);font-weight:600">🇨🇿 ${esc(sv)}</div>` : ''}
        ${jm ? `<div>🎉 Svátek: <strong>${esc(jm)}</strong></div>` : ''}</div>
      <div class="kal-foot"><button type="button" data-today="1">Dnes</button></div>`;
  }

  function place(anchor) {
    const r = anchor.getBoundingClientRect();
    const w = pop.offsetWidth || 300;
    pop.style.left = Math.min(Math.max(8, r.left), window.innerWidth - w - 8) + 'px';
    pop.style.top = Math.max(8, Math.min(r.bottom + 6, window.innerHeight - pop.offsetHeight - 8)) + 'px';
  }

  function close() { if (pop) { pop.remove(); pop = null; } }

  function open(anchor) {
    css();
    const now = new Date();
    view = new Date(now.getFullYear(), now.getMonth(), 1);
    sel = null;
    pop = document.createElement('div');
    pop.className = 'kal-pop';
    pop.setAttribute('role', 'dialog');
    pop.setAttribute('aria-label', 'Kalendář');
    document.body.appendChild(pop);
    render();
    place(anchor);
    pop.addEventListener('click', (e) => {
      e.stopPropagation();
      const nav = e.target.closest('[data-nav]');
      if (nav) { view = new Date(view.getFullYear(), view.getMonth() + parseInt(nav.dataset.nav, 10), 1); render(); return; }
      if (e.target.closest('[data-today]')) { const t = new Date(); view = new Date(t.getFullYear(), t.getMonth(), 1); sel = null; render(); return; }
      const d = e.target.closest('[data-d]');
      if (d) { const [yy, mm, dd] = d.dataset.d.split('-').map(Number); sel = new Date(yy, mm, dd); if (mm !== view.getMonth()) view = new Date(yy, mm, 1); render(); }
    });
  }

  document.addEventListener('click', (e) => {
    const chip = e.target.closest && e.target.closest('#topbar-datum-svatek');
    if (chip) { e.preventDefault(); e.stopPropagation(); if (pop) close(); else open(chip); return; }
    if (pop && !pop.contains(e.target)) close();
  }, true);
  document.addEventListener('keydown', (e) => { if (e.key === 'Escape') close(); });
  // iOS mění výšku okna při scrollu (lišta adresy) → zavírej jen při změně ŠÍŘKY (otočení / resize okna)
  let _w = window.innerWidth;
  window.addEventListener('resize', () => { if (window.innerWidth !== _w) { _w = window.innerWidth; close(); } });
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', css); else css();
})();
