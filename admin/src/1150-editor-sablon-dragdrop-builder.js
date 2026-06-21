// =============================================================
// EDITOR ŠABLON — drag&drop builder
// =============================================================
const stEditor = {
  formatId: 'pk-105x148-4',
  sablonaId: null,
  nazev: 'Nová šablona',
  prvky: [],          // { id, typ, x_pct, y_pct, w_pct, h_pct, ...style/data }
  vybranyId: null,
  zoom: 4,            // px na 1 mm na obrazovce
  pretahovani: null,  // { id, mode: 'move'|'resize', startX, startY, startEl }
  ulozeneSablony: [],
  previewVyrobek: null, // výrobek pro náhled v editoru (reálná data místo placeholderů)
};

const PALETA_PRVKY = [
  { typ: 'nazev',   l: '🏷️ Název',          d: 'Název výrobku',        defaultW: 90, defaultH: 14, fontSize: 14, fontWeight: 800 },
  { typ: 'cena',    l: '💰 Cena',           d: 'Cena s DPH',           defaultW: 60, defaultH: 18, fontSize: 28, fontWeight: 900, color: '#2C2C2A' },
  { typ: 'mena',    l: '💱 Měna „Kč"',      d: 'Měna',                 defaultW: 14, defaultH: 9,  fontSize: 12, fontWeight: 700, color: '#BA7517' },
  { typ: 'jed',     l: '📏 Za jednotku',    d: 'za ks',                defaultW: 28, defaultH: 7,  fontSize: 8 },
  { typ: 'cenakg',  l: '⚖️ Cena za kg',     d: 'X Kč / kg',            defaultW: 38, defaultH: 8,  fontSize: 8,  fontWeight: 700, color: '#854F0B', bg: '#FFF8E5', padding: 1 },
  { typ: 'hmotnost',l: '⚖️ Hmotnost',       d: '500 g',                defaultW: 28, defaultH: 8,  fontSize: 10, fontWeight: 600 },
  { typ: 'dph',     l: '🧾 DPH %',          d: 'DPH 12%',              defaultW: 24, defaultH: 7,  fontSize: 8,  fontWeight: 700, color: '#0C447C', bg: '#EFF6FF', padding: 1 },
  { typ: 'slozeni', l: '🌾 Složení',        d: 'Složení: ...',         defaultW: 92, defaultH: 12, fontSize: 7, color: '#555' },
  { typ: 'alergeny',l: '⚠️ Alergeny',       d: 'Alergeny: lepek',      defaultW: 70, defaultH: 8,  fontSize: 7, color: '#92400e', fontWeight: 600 },
  // 🆕 v2.9.302 — Nutriční hodnoty (na 100 g výrobku) — pro tisk štítků dle EU 1169/2011
  { typ: 'nutri',     l: '🍎 Nutri tabulka', d: 'Energie 1490kJ/350kcal · Tuky 1.2g · Sach 73g · Bilk 10g · Sůl 0.01g', defaultW: 92, defaultH: 18, fontSize: 6, color: '#333' },
  { typ: 'nutri_kj',  l: '⚡ Energie kJ',    d: '1490 kJ',              defaultW: 28, defaultH: 7,  fontSize: 8, color: '#854F0B', fontWeight: 600 },
  { typ: 'nutri_kcal',l: '🔥 Energie kcal',  d: '350 kcal',             defaultW: 30, defaultH: 7,  fontSize: 8, color: '#854F0B', fontWeight: 600 },
  { typ: 'nutri_tuky',l: '🧈 Tuky',          d: 'Tuky 1.2 g',           defaultW: 30, defaultH: 7,  fontSize: 8 },
  { typ: 'nutri_sach',l: '🌾 Sacharidy',     d: 'Sach 73 g',            defaultW: 30, defaultH: 7,  fontSize: 8 },
  { typ: 'nutri_bilk',l: '💪 Bílkoviny',     d: 'Bilk 10 g',            defaultW: 30, defaultH: 7,  fontSize: 8 },
  { typ: 'nutri_sul', l: '🧂 Sůl',           d: 'Sůl 0.01 g',           defaultW: 28, defaultH: 7,  fontSize: 8 },
  { typ: 'kod',     l: '#️⃣ Kód',            d: 'kód 12',               defaultW: 28, defaultH: 6,  fontSize: 6, color: '#999' },
  { typ: 'ean',     l: '📊 EAN',            d: '▌▌▌',                  defaultW: 38, defaultH: 14 },
  { typ: 'qr',      l: '🔲 QR',             d: '▣',                    defaultW: 18, defaultH: 18 },
  { typ: 'badge',   l: '🔖 Badge proužek',  d: 'NOVINKA',              defaultW: 100, defaultH: 6, fontSize: 8,  fontWeight: 800, color: '#fff', bg: '#dc2626' },
  { typ: 'text',    l: '✏️ Vlastní text',   d: 'Text',                 defaultW: 45, defaultH: 7,  fontSize: 9 },
  { typ: 'cara',    l: '➖ Čára',            d: '',                     defaultW: 85, defaultH: 0.5,bg: '#999' },
  { typ: 'box',     l: '⬛ Box',             d: '',                     defaultW: 35, defaultH: 12, bg: '#FAEEDA' },
];

async function renderStitkyEditor() {
  const c = document.getElementById('content');
  // Načti šablony
  try {
    stEditor.ulozeneSablony = await api('admin_stitky_sablony.php');
  } catch (e) { stEditor.ulozeneSablony = []; }

  vykreslitEditor();
}

function vykreslitEditor() {
  const c = document.getElementById('content');
  const fmt = STITKY_FORMATY.find(f => f.id === stEditor.formatId) || STITKY_FORMATY[0];
  const z = stEditor.zoom;
  const cellW = fmt.w * z;
  const cellH = fmt.h * z;

  c.innerHTML = `
    <div class="page-head">
      <div>
        <h1 class="page-title">🛠️ Editor šablon</h1>
        <p class="page-sub">Vlastní rozvržení cenovek pomocí drag &amp; drop</p>
      </div>
      <div style="display:flex;gap:8px;flex-wrap:wrap">
        <button class="btn-back" onclick="stSetRezim('cenovky')">← Zpět na tisk</button>
        <button class="btn-secondary" onclick="ed_novaSablona()">+ Nová</button>
        <button class="btn-primary btn-green" onclick="ed_ulozit()">💾 Uložit</button>
      </div>
    </div>

    <div class="ed-toolbar">
      <div style="display:flex;gap:10px;align-items:center;flex-wrap:wrap">
        <input class="form-input" id="ed-nazev" value="${esc(stEditor.nazev)}" oninput="stEditor.nazev = this.value" style="font-size:14px;font-weight:600;min-width:180px;max-width:300px" placeholder="Název šablony">
        <select class="form-input" id="ed-format" onchange="ed_setFormat(this.value)" style="min-width:280px">
          ${STITKY_FORMATY.filter(f => !f.custom).map(f => `<option value="${f.id}" ${f.id === stEditor.formatId ? 'selected' : ''}>${esc(f.popis)}</option>`).join('')}
        </select>
        ${stEditor.ulozeneSablony.length > 0 ? `
          <select class="form-input" onchange="ed_nacist(this.value)" style="min-width:200px">
            <option value="">— Načíst uloženou šablonu —</option>
            ${stEditor.ulozeneSablony.map(t => `<option value="${t.id}" ${t.id == stEditor.sablonaId ? 'selected' : ''}>${esc(t.nazev)}</option>`).join('')}
          </select>
        ` : ''}
        <button class="btn-secondary" onclick="ed_seedPreset()" style="font-size:12px" title="Hromadně vytvoří 20 přednastavených šablon pro různé velikosti A4 archů">⭐ Načíst 20 přednast.</button>
        ${stEditor.sablonaId ? `<div class="form-actions-icons-row"><button class="btn-danger-corner" onclick="ed_smazat()" title="Smazat šablonu" aria-label="Smazat šablonu">🗑️</button></div>` : ''}
        <button class="btn-secondary" onclick="ed_otevritVyberVyrobku()" style="font-size:12px" title="Načíst data z konkrétního výrobku — uvidíš jak bude šablona vypadat s reálnými údaji">📥 ${stEditor.previewVyrobek ? 'Změnit výrobek' : 'Načíst data z výrobku'}</button>
        ${stEditor.previewVyrobek ? `<button class="btn-secondary" onclick="ed_zrusitVyberVyrobku()" style="font-size:12px;padding:5px 8px" title="Vrátit zástupné texty (placeholdery)">×</button>` : ''}
        ${stEditor.prvky.length > 0 ? `<button class="btn-secondary" onclick="ed_autoTransform()" style="font-size:12px" title="Auto-fit: přizpůsobí velikosti fontů a prvků proporcionálně k velikosti štítku">🪄 Auto-fit</button>` : ''}
        <div style="flex:1"></div>
        ${stEditor.previewVyrobek ? `<span style="font-size:12px;color:#15803D;font-weight:600">📦 ${esc(stEditor.previewVyrobek.nazev)}</span>` : ''}
        <span style="font-size:12px;color:var(--text-3)">Štítek ${fmt.w}×${fmt.h} mm · ${stEditor.prvky.length} prvků</span>
        <div style="display:flex;align-items:center;gap:4px;background:var(--surface-2);padding:3px 6px;border-radius:6px;border:1px solid var(--border)">
          <button class="btn-secondary" onclick="ed_zoom(-1)" style="padding:3px 8px;font-size:14px;font-weight:700;border:0;background:transparent" title="Oddálit">−</button>
          <input type="range" id="ed-zoom-range" min="2" max="40" step="0.5" value="${z}" oninput="ed_zoomSet(parseFloat(this.value))" style="width:130px;cursor:pointer" title="Zoom: tahej pro plynulý posun (až 1000 %)">
          <button class="btn-secondary" onclick="ed_zoom(1)" style="padding:3px 8px;font-size:14px;font-weight:700;border:0;background:transparent" title="Přiblížit">+</button>
          <span style="font-size:11px;color:var(--text-2);font-weight:600;min-width:42px;text-align:center;font-variant-numeric:tabular-nums">${(z * 100 / 4).toFixed(0)} %</span>
          <button class="btn-secondary" onclick="ed_zoomFit()" style="padding:3px 8px;font-size:11px;border:0;background:transparent" title="Přizpůsobit obrazovce">🔲</button>
        </div>
      </div>
    </div>

    <div class="ed-workarea">
      <!-- Levá paleta -->
      <aside class="ed-paleta">
        <div class="ed-paleta-title">📦 Prvky</div>
        ${PALETA_PRVKY.map(p => `
          <button class="ed-paleta-item" onclick="ed_pridat('${p.typ}')" title="${esc(p.d)}">
            <span>${p.l}</span>
          </button>
        `).join('')}
      </aside>

      <!-- Plátno -->
      <div class="ed-canvas-wrap">
        <div class="ed-canvas-info">${stEditor.prvky.length === 0
          ? 'Vyber jak začít — nebo klikni na prvek vlevo a přidá se do plátna.'
          : 'Klikni na prvek vlevo, přidá se do plátna. Drag = pohyb. Roh = velikost. Del = smazat.'}</div>
        <div class="ed-canvas ${fmt.foldHalf ? 'is-fold' : ''}" id="ed-canvas"
             style="width:${cellW}px;height:${cellH}px;position:relative"
             data-w-mm="${fmt.w}" data-h-mm="${fmt.h}"
             onclick="ed_klikPlatno(event)">
          ${fmt.foldHalf ? `
            <div class="ed-fold-overlay" style="height:50%"><span>HORNÍ POLOVINA — netiskne se (sklad)</span></div>
            <div class="ed-fold-line"></div>
          ` : ''}
          ${stEditor.prvky.map(p => ed_renderPrvek(p, z)).join('')}
          ${stEditor.prvky.length === 0 ? ed_renderWelcome() : ''}
        </div>
        <div class="ed-canvas-mereni">
          <span>↕ ${fmt.h} mm</span> · <span>↔ ${fmt.w} mm</span>${fmt.foldHalf ? ` · <span style="color:var(--primary)">⚠️ jen spodní polovina (${(fmt.h/2).toFixed(0)} mm) se vytiskne</span>` : ''}
        </div>
      </div>

      <!-- Pravá properties -->
      <aside class="ed-props">
        ${ed_renderProps()}
      </aside>
    </div>
  `;

  // Po renderu — připojit drag/resize handlery
  const canvas = document.getElementById('ed-canvas');
  if (canvas) {
    canvas.querySelectorAll('.ed-prvek').forEach(el => {
      ed_setupDrag(el);
    });
  }
}

function ed_renderPrvek(p, zoom) {
  const x = (p.x_pct / 100) * (parseFloat(document.getElementById('ed-canvas')?.dataset.wMm || stEditor._w || 100));
  const y = (p.y_pct / 100) * (parseFloat(document.getElementById('ed-canvas')?.dataset.hMm || stEditor._h || 100));
  const w = (p.w_pct / 100);
  const h = (p.h_pct / 100);

  const fmt = STITKY_FORMATY.find(f => f.id === stEditor.formatId) || STITKY_FORMATY[0];
  const xPx = (p.x_pct / 100) * fmt.w * zoom;
  const yPx = (p.y_pct / 100) * fmt.h * zoom;
  const wPx = (p.w_pct / 100) * fmt.w * zoom;
  const hPx = (p.h_pct / 100) * fmt.h * zoom;

  const styly = [];
  if (p.fontSize)   styly.push(`font-size:${(p.fontSize * zoom * 0.353).toFixed(1)}px`); // pt→mm→px
  if (p.fontWeight) styly.push(`font-weight:${p.fontWeight}`);
  if (p.color)      styly.push(`color:${p.color}`);
  if (p.bg)         styly.push(`background:${p.bg}`);
  if (p.align)      styly.push(`text-align:${p.align}`);
  if (p.italic)     styly.push(`font-style:italic`);
  if (p.padding)    styly.push(`padding:${(p.padding * zoom).toFixed(1)}px`);

  const isSel = p.id === stEditor.vybranyId;

  // Obsah dle typu — pokud je nastavený previewVyrobek, použij reálná data
  let inner = '';
  const txt = (s) => `<span class="ed-prvek-txt">${esc(s)}</span>`;
  const v = stEditor.previewVyrobek; // může být null

  // Helper: hmotnost / obsah z výrobku
  const vyrobekHmotnost = () => {
    if (!v) return '500 g';
    if (v.obsah && v.obsah_jednotka) {
      return `${parseFloat(v.obsah).toString().replace('.', ',')} ${v.obsah_jednotka}`;
    }
    if (v.hmotnost_g) return `${v.hmotnost_g} g`;
    return '500 g';
  };
  const vyrobekCenaSDph = () => {
    if (!v) return '199,90';
    const sazba = parseFloat(v.sazba_dph) || 12;
    const cenaSDph = (parseFloat(v.cena_bez_dph) || 0) * (1 + sazba / 100);
    return cenaSDph.toFixed(2).replace('.', ',');
  };
  const vyrobekCenaKg = () => {
    if (!v) return '76,90 Kč/kg';
    const sazba = parseFloat(v.sazba_dph) || 12;
    const cenaSDph = (parseFloat(v.cena_bez_dph) || 0) * (1 + sazba / 100);
    let g = 0;
    if (v.obsah && v.obsah_jednotka) {
      const oj = String(v.obsah_jednotka).toLowerCase();
      if (oj === 'kg') g = parseFloat(v.obsah) * 1000;
      else if (oj === 'g') g = parseFloat(v.obsah);
      else if (oj === 'l') g = parseFloat(v.obsah) * 1000;
      else if (oj === 'ml') g = parseFloat(v.obsah);
    }
    if (!g && v.hmotnost_g) g = parseFloat(v.hmotnost_g);
    if (!g) return '—';
    const perKg = (cenaSDph * 1000) / g;
    return `${perKg.toFixed(2).replace('.', ',')} Kč/kg`;
  };

  if (p.typ === 'text') inner = txt(p.text || 'Text');
  else if (p.typ === 'nazev') inner = txt(p.text || (v ? v.nazev : '{název}'));
  else if (p.typ === 'cena') inner = txt(p.text || vyrobekCenaSDph());
  else if (p.typ === 'mena') inner = txt(p.text || 'Kč');
  else if (p.typ === 'jed') inner = txt(p.text || (v?.jednotka_kod ? `za ${v.jednotka_kod}` : 'za ks'));
  else if (p.typ === 'cenakg') inner = txt(p.text || vyrobekCenaKg());
  else if (p.typ === 'hmotnost') inner = txt(p.text || vyrobekHmotnost());
  else if (p.typ === 'dph') inner = txt(p.text || (v ? `DPH ${parseFloat(v.sazba_dph || 12)}%` : 'DPH 12%'));
  else if (p.typ === 'slozeni') {
    let sl = p.text || (v ? (typeof v.slozeni === 'string' ? v.slozeni : (v.slozeni_text || 'Složení: mouka, voda…')) : 'Složení: mouka, voda…');
    if (sl && sl.length > 200) sl = sl.slice(0, 200) + '…';
    inner = txt(sl);
  }
  else if (p.typ === 'alergeny') inner = txt(p.text || (v?.alergeny ? `Alergeny: ${v.alergeny}` : 'Alergeny: lepek'));
  // 🥗 v2.9.302 — Nutriční hodnoty na štítku (na 100 g výrobku)
  else if (p.typ === 'nutri')      inner = txt(p.text || nutriPrvekText('nutri', v)      || 'Energie 1490kJ/350kcal · Tuky 1.2g · Sach 73g · Bilk 10g · Sůl 0.01g');
  else if (p.typ === 'nutri_kj')   inner = txt(p.text || nutriPrvekText('nutri_kj', v)   || '1490 kJ');
  else if (p.typ === 'nutri_kcal') inner = txt(p.text || nutriPrvekText('nutri_kcal', v) || '350 kcal');
  else if (p.typ === 'nutri_tuky') inner = txt(p.text || nutriPrvekText('nutri_tuky', v) || 'Tuky 1.2 g');
  else if (p.typ === 'nutri_sach') inner = txt(p.text || nutriPrvekText('nutri_sach', v) || 'Sach 73 g');
  else if (p.typ === 'nutri_bilk') inner = txt(p.text || nutriPrvekText('nutri_bilk', v) || 'Bilk 10 g');
  else if (p.typ === 'nutri_sul')  inner = txt(p.text || nutriPrvekText('nutri_sul', v)  || 'Sůl 0.01 g');
  else if (p.typ === 'kod') inner = txt(p.text || (v?.cislo ? `kód ${v.cislo}` : 'kód 12'));
  else if (p.typ === 'badge') inner = txt(p.text || 'NOVINKA');
  else if (p.typ === 'ean') {
    // Zobrazení reálného EAN čárového kódu pokud je validní
    const eanStr = v && v.ean && /^\d{12,13}$/.test(String(v.ean).replace(/\D/g, ''))
      ? String(v.ean).replace(/\D/g, '')
      : null;
    inner = eanStr
      ? `<div style="display:flex;flex-direction:column;align-items:center;justify-content:center;width:100%;height:100%;font-family:monospace;font-size:9px;color:#000">
          <div style="background:repeating-linear-gradient(90deg,#000 0 1.5px,transparent 1.5px 3px);width:100%;flex:1;min-height:0"></div>
          <div style="margin-top:1px">${esc(eanStr)}</div>
        </div>`
      : '<div style="background:repeating-linear-gradient(90deg,#000 0 1.5px,transparent 1.5px 3px);width:100%;height:100%"></div>';
  }
  else if (p.typ === 'qr') inner = '<div style="background:repeating-linear-gradient(45deg,#000 0 3px,#fff 3px 6px);width:100%;height:100%"></div>';
  else if (p.typ === 'cara') inner = '';
  else if (p.typ === 'box') inner = '';

  return `
    <div class="ed-prvek ${isSel ? 'is-selected' : ''}"
         data-id="${p.id}"
         style="left:${xPx.toFixed(1)}px;top:${yPx.toFixed(1)}px;width:${wPx.toFixed(1)}px;height:${hPx.toFixed(1)}px;${styly.join(';')}"
         onclick="event.stopPropagation();ed_vybrat('${p.id}')"
         title="${esc(p.typ)}">
      ${inner}
      ${isSel ? '<div class="ed-resize" data-corner="se"></div>' : ''}
    </div>
  `;
}

function ed_renderProps() {
  if (!stEditor.vybranyId) {
    return `
      <div class="ed-props-empty">
        <div style="font-size:32px;margin-bottom:10px">🛠️</div>
        <div style="font-weight:600;color:var(--text-2);margin-bottom:6px">Žádný prvek vybraný</div>
        <div style="font-size:12px;color:var(--text-3)">Klikni na prvek v plátně, nebo přidej nový z palety vlevo.</div>
      </div>
    `;
  }
  const p = stEditor.prvky.find(x => x.id === stEditor.vybranyId);
  if (!p) return '';

  return `
    <div class="ed-props-title">
      ${esc(p.typ)}
      <button class="ed-prop-del" onclick="ed_smazatPrvek('${p.id}')" title="Smazat">×</button>
    </div>

    <div class="ed-prop-group">
      <div class="ed-prop-label">Pozice & velikost (% štítku)</div>
      <div style="display:grid;grid-template-columns:1fr 1fr;gap:6px">
        <label class="ed-prop-mini"><span>X</span><input type="number" step="0.5" value="${p.x_pct.toFixed(1)}" oninput="ed_updateProp('x_pct', parseFloat(this.value))"></label>
        <label class="ed-prop-mini"><span>Y</span><input type="number" step="0.5" value="${p.y_pct.toFixed(1)}" oninput="ed_updateProp('y_pct', parseFloat(this.value))"></label>
        <label class="ed-prop-mini"><span>Š</span><input type="number" step="0.5" value="${p.w_pct.toFixed(1)}" oninput="ed_updateProp('w_pct', parseFloat(this.value))"></label>
        <label class="ed-prop-mini"><span>V</span><input type="number" step="0.5" value="${p.h_pct.toFixed(1)}" oninput="ed_updateProp('h_pct', parseFloat(this.value))"></label>
      </div>
    </div>

    ${p.typ === 'qr' ? `
      <div class="ed-prop-group" style="background:#FFF8E7;border:1px solid #E8C988;padding:10px;border-radius:6px">
        <div class="ed-prop-label" style="color:#854F0B">🔲 Obsah QR kódu</div>
        <input class="form-input" value="${esc(p.text || '')}" oninput="ed_updateProp('text', this.value)" placeholder="https://appekpekarstvi.cz nebo libovolný text">
        <small style="color:#854F0B;font-size:11px;display:block;margin-top:6px;line-height:1.5">
          <strong>Co můžeš zadat:</strong><br>
          • Webová adresa — <code>https://appekpekarstvi.cz/produkt/123</code><br>
          • Telefon — <code>tel:+420123456789</code><br>
          • Email — <code>mailto:info@firma.cz</code><br>
          • Wifi heslo, vCard, libovolný text…<br>
          <br>
          <strong>Když necháš prázdné</strong>, použije se postupně:<br>
          1. EAN výrobku · 2. Kód výrobku · 3. ID výrobku
        </small>
      </div>
    ` : ['cara','box','ean'].includes(p.typ) ? '' : `
      <div class="ed-prop-group">
        <div class="ed-prop-label">Text</div>
        <input class="form-input" value="${esc(p.text || '')}" oninput="ed_updateProp('text', this.value)" placeholder="zástupný / vlastní text">
        <small style="color:var(--text-3);font-size:11px">Ponechej prázdné = vykreslí se ze záznamu výrobku</small>
      </div>

      <div class="ed-prop-group">
        <div class="ed-prop-label">Velikost / styl</div>
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:6px">
          <label class="ed-prop-mini"><span>Font (pt)</span><input type="number" min="3" max="60" step="0.5" value="${p.fontSize || 8}" oninput="ed_updateProp('fontSize', parseFloat(this.value))"></label>
          <label class="ed-prop-mini"><span>Váha</span>
            <select onchange="ed_updateProp('fontWeight', parseInt(this.value))">
              ${[400, 500, 600, 700, 800, 900].map(w => `<option value="${w}" ${(p.fontWeight || 400) == w ? 'selected' : ''}>${w}</option>`).join('')}
            </select>
          </label>
          <label class="ed-prop-mini"><span>Barva</span><input type="color" value="${p.color || '#2C2C2A'}" oninput="ed_updateProp('color', this.value)"></label>
          <label class="ed-prop-mini"><span>Pozadí</span><input type="color" value="${p.bg || '#ffffff'}" oninput="ed_updateProp('bg', this.value === '#ffffff' ? null : this.value)"></label>
          <label class="ed-prop-mini"><span>Zarovnání</span>
            <select onchange="ed_updateProp('align', this.value)">
              ${['left','center','right'].map(a => `<option value="${a}" ${(p.align || 'left') === a ? 'selected' : ''}>${a}</option>`).join('')}
            </select>
          </label>
          <label class="ed-prop-mini"><span>Kurzíva</span>
            <input type="checkbox" ${p.italic ? 'checked' : ''} onchange="ed_updateProp('italic', this.checked)" style="width:18px;height:18px">
          </label>
        </div>
      </div>
    `}
  `;
}

window.ed_setFormat = function(id) {
  stEditor.formatId = id;
  vykreslitEditor();
};

