// =============================================================
// ZOOM editoru — plynulé přibližování pro malé štítky
// =============================================================
window.ed_zoom = function(dir) {
  // Step závisí na aktuálním zoom (jemné když malé, hrubší když velké)
  const z = stEditor.zoom;
  const step = z < 4 ? 0.5 : (z < 10 ? 1 : (z < 20 ? 2 : 4));
  const newZ = Math.max(2, Math.min(40, z + dir * step));
  stEditor.zoom = newZ;
  vykreslitEditor();
};
let _edZoomTimer = null;
window.ed_zoomSet = function(z) {
  stEditor.zoom = Math.max(2, Math.min(40, parseFloat(z) || 4));
  // Při tahu sliderem updatuj jen velikost canvasu a font %% indikátor — bez plného re-renderu
  const fmt = STITKY_FORMATY.find(f => f.id === stEditor.formatId) || STITKY_FORMATY[0];
  const canvas = document.getElementById('ed-canvas');
  if (canvas) {
    canvas.style.width = (fmt.w * stEditor.zoom) + 'px';
    canvas.style.height = (fmt.h * stEditor.zoom) + 'px';
  }
  // Updatuj % indikátor
  document.querySelectorAll('.ed-toolbar span').forEach(el => {
    if (el.textContent.endsWith(' %')) el.textContent = (stEditor.zoom * 100 / 4).toFixed(0) + ' %';
  });
  // Debounce plný re-render (přepočet prvků se zoomem)
  if (_edZoomTimer) clearTimeout(_edZoomTimer);
  _edZoomTimer = setTimeout(() => { _edZoomTimer = null; vykreslitEditor(); }, 200);
};
window.ed_zoomFit = function() {
  // Spočítá zoom aby se štítek vešel do dostupné šířky workarea (zhruba 60 % okna)
  const fmt = STITKY_FORMATY.find(f => f.id === stEditor.formatId) || STITKY_FORMATY[0];
  // Cíl: štítek max ~70 % šířky okna (počítáme s paletou vlevo + properties vpravo, cca 380 px na okraje)
  const targetW = Math.max(400, window.innerWidth - 480);
  const targetH = Math.max(400, window.innerHeight - 320);
  const zByW = targetW / fmt.w;
  const zByH = targetH / fmt.h;
  const newZ = Math.max(2, Math.min(40, Math.min(zByW, zByH)));
  stEditor.zoom = Math.round(newZ * 2) / 2; // snap na .5
  vykreslitEditor();
};

window.ed_pridat = function(typ) {
  const def = PALETA_PRVKY.find(p => p.typ === typ);
  const fmt = STITKY_FORMATY.find(f => f.id === stEditor.formatId) || STITKY_FORMATY[0];
  const id = 'p' + Date.now() + Math.floor(Math.random() * 1000);
  const w_pct = Math.min(95, (def.defaultW / fmt.w) * 100);
  const h_pct = Math.min(40, (def.defaultH / fmt.h) * 100);
  // Text necháváme PRÁZDNÝ pro datové typy — pak renderer použije buď
  // skutečná data z previewVyrobek, nebo placeholder. Vyplníme jen u
  // typů, kde dává smysl výchozí text (text, badge, jed).
  const datoveTypy = ['nazev','cena','mena','jed','cenakg','hmotnost','dph','slozeni','alergeny','kod','ean','qr','nutri','nutri_kj','nutri_kcal','nutri_tuky','nutri_sach','nutri_bilk','nutri_sul'];
  const text = datoveTypy.includes(typ) ? '' : (def.d || '');
  const prvek = {
    id,
    typ,
    x_pct: 5,
    y_pct: 5,
    w_pct,
    h_pct,
    text,
    fontSize: def.fontSize,
    fontWeight: def.fontWeight,
    color: def.color,
    bg: def.bg,
    padding: def.padding,
    align: 'left',
  };
  // Cena/badge default zarovnání
  if (typ === 'cena') prvek.align = 'left';
  if (typ === 'badge') { prvek.align = 'center'; prvek.x_pct = 0; prvek.y_pct = 0; prvek.w_pct = 100; }
  stEditor.prvky.push(prvek);
  stEditor.vybranyId = id;
  vykreslitEditor();
};

window.ed_vybrat = function(id) {
  stEditor.vybranyId = id;
  vykreslitEditor();
};

window.ed_klikPlatno = function() {
  stEditor.vybranyId = null;
  vykreslitEditor();
};

window.ed_smazatPrvek = function(id) {
  stEditor.prvky = stEditor.prvky.filter(p => p.id !== id);
  if (stEditor.vybranyId === id) stEditor.vybranyId = null;
  vykreslitEditor();
};

window.ed_updateProp = function(key, val) {
  const p = stEditor.prvky.find(x => x.id === stEditor.vybranyId);
  if (!p) return;
  p[key] = val;
  vykreslitEditor();
};

function ed_setupDrag(el) {
  let mode = 'move';
  let startX, startY, startPrvek;

  const onDown = (e) => {
    e.preventDefault();
    e.stopPropagation();
    const id = el.dataset.id;
    stEditor.vybranyId = id;
    const target = e.target.classList.contains('ed-resize') ? 'resize' : 'move';
    mode = target;
    startX = e.clientX;
    startY = e.clientY;
    startPrvek = { ...stEditor.prvky.find(p => p.id === id) };
    document.addEventListener('pointermove', onMove);
    document.addEventListener('pointerup', onUp);
  };

  const onMove = (e) => {
    if (!startPrvek) return;
    const canvas = document.getElementById('ed-canvas');
    if (!canvas) return;
    const rect = canvas.getBoundingClientRect();
    const dxPct = ((e.clientX - startX) / rect.width) * 100;
    const dyPct = ((e.clientY - startY) / rect.height) * 100;
    const p = stEditor.prvky.find(x => x.id === startPrvek.id);
    if (!p) return;

    // Snap to ~1 mm grid (closest to 1 mm of label width/height)
    const fmt = STITKY_FORMATY.find(f => f.id === stEditor.formatId) || STITKY_FORMATY[0];
    const snapX = (val) => {
      const stepPct = (1 / fmt.w) * 100; // 1 mm v %
      return Math.round(val / stepPct) * stepPct;
    };
    const snapY = (val) => {
      const stepPct = (1 / fmt.h) * 100;
      return Math.round(val / stepPct) * stepPct;
    };

    if (mode === 'move') {
      let nx = startPrvek.x_pct + dxPct;
      let ny = startPrvek.y_pct + dyPct;
      // Shift drží přesnou pozici (bez snapu) pro fine-tuning
      if (!e.shiftKey) { nx = snapX(nx); ny = snapY(ny); }
      p.x_pct = Math.max(0, Math.min(100 - p.w_pct, nx));
      p.y_pct = Math.max(0, Math.min(100 - p.h_pct, ny));
    } else {
      let nw = startPrvek.w_pct + dxPct;
      let nh = startPrvek.h_pct + dyPct;
      if (!e.shiftKey) { nw = snapX(nw); nh = snapY(nh); }
      p.w_pct = Math.max(2, Math.min(100 - p.x_pct, nw));
      p.h_pct = Math.max(2, Math.min(100 - p.y_pct, nh));
    }
    vykreslitEditor();
  };

  const onUp = () => {
    document.removeEventListener('pointermove', onMove);
    document.removeEventListener('pointerup', onUp);
    startPrvek = null;
  };

  el.addEventListener('pointerdown', onDown);
}

window.ed_novaSablona = async function() {
  if (stEditor.prvky.length > 0 && !(await confirmDialog({ msg: 'Vytvořit novou šablonu? Neuložené změny budou ztraceny.', danger: false }))) return;
  stEditor.sablonaId = null;
  stEditor.nazev = 'Nová šablona';
  stEditor.prvky = [];
  stEditor.vybranyId = null;
  vykreslitEditor();
};

// Sanitize prvek — zajistí že má všechny povinné fieldy v rozmezí 0-100 %
function ed_sanitizePrvek(p, idx) {
  return {
    id:       p.id || ('p' + Date.now() + '_' + idx + '_' + Math.floor(Math.random() * 1000)),
    typ:      p.typ || 'text',
    x_pct:    typeof p.x_pct === 'number' ? p.x_pct : (parseFloat(p.x_pct) || 0),
    y_pct:    typeof p.y_pct === 'number' ? p.y_pct : (parseFloat(p.y_pct) || 0),
    w_pct:    typeof p.w_pct === 'number' ? p.w_pct : (parseFloat(p.w_pct) || 30),
    h_pct:    typeof p.h_pct === 'number' ? p.h_pct : (parseFloat(p.h_pct) || 10),
    text:     p.text ?? '',
    fontSize: p.fontSize ? parseFloat(p.fontSize) : null,
    fontWeight: p.fontWeight ? parseInt(p.fontWeight) : null,
    color:    p.color || null,
    bg:       p.bg || null,
    align:    p.align || 'left',
    italic:   !!p.italic,
    padding:  p.padding ? parseFloat(p.padding) : null,
  };
}

