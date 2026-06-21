// =============================================================
// ⭐ 10 PŘEDNASTAVENÝCH ŠABLON — fits A4 archů (různé velikosti)
// =============================================================
const SABLONY_PRESET = [
  {
    nazev: '★ A6 — Velká cenovka regál (Hero cena)',
    format_id: 'pk-105x148-4',
    prvky: [
      { typ: 'nazev',    x_pct: 4,  y_pct: 6,  w_pct: 92, h_pct: 14, fontSize: 22, fontWeight: 700, align: 'center' },
      { typ: 'hmotnost', x_pct: 4,  y_pct: 22, w_pct: 92, h_pct: 6,  fontSize: 11, color: '#777', align: 'center' },
      { typ: 'cena',     x_pct: 4,  y_pct: 32, w_pct: 92, h_pct: 36, fontSize: 72, fontWeight: 900, color: '#854F0B', align: 'center' },
      { typ: 'cenakg',   x_pct: 4,  y_pct: 70, w_pct: 92, h_pct: 6,  fontSize: 11, color: '#888', align: 'center' },
      { typ: 'alergeny', x_pct: 4,  y_pct: 78, w_pct: 92, h_pct: 6,  fontSize: 8,  color: '#92400e', align: 'center' },
      { typ: 'ean',      x_pct: 4,  y_pct: 88, w_pct: 70, h_pct: 8,  fontSize: 9,  align: 'left' },
      { typ: 'kod',      x_pct: 76, y_pct: 90, w_pct: 20, h_pct: 6,  fontSize: 8,  color: '#888', align: 'right' },
    ],
  },
  {
    nazev: '★ A6 půl — Standardní cenovka',
    format_id: 'pk-105x74-8',
    prvky: [
      { typ: 'nazev',    x_pct: 4,  y_pct: 8,  w_pct: 92, h_pct: 22, fontSize: 18, fontWeight: 700, align: 'center' },
      { typ: 'cena',     x_pct: 4,  y_pct: 34, w_pct: 92, h_pct: 36, fontSize: 48, fontWeight: 900, color: '#854F0B', align: 'center' },
      { typ: 'hmotnost', x_pct: 4,  y_pct: 76, w_pct: 46, h_pct: 10, fontSize: 10, align: 'left' },
      { typ: 'ean',      x_pct: 52, y_pct: 76, w_pct: 46, h_pct: 10, fontSize: 9,  align: 'right' },
    ],
  },
  {
    nazev: '★ Půl A4 — Akce / Sleva (hero cena)',
    format_id: 'pk-105x297-2',
    prvky: [
      { typ: 'text',     x_pct: 4,  y_pct: 2,  w_pct: 30, h_pct: 8,  text: '🔥 AKCE', fontSize: 22, fontWeight: 900, color: '#fff', bg: '#dc2626', align: 'center' },
      { typ: 'nazev',    x_pct: 4,  y_pct: 14, w_pct: 92, h_pct: 18, fontSize: 38, fontWeight: 700, align: 'center' },
      { typ: 'hmotnost', x_pct: 4,  y_pct: 34, w_pct: 92, h_pct: 6,  fontSize: 16, color: '#777', align: 'center' },
      { typ: 'cena',     x_pct: 4,  y_pct: 44, w_pct: 92, h_pct: 28, fontSize: 130, fontWeight: 900, color: '#854F0B', align: 'center' },
      { typ: 'cenakg',   x_pct: 4,  y_pct: 74, w_pct: 92, h_pct: 6,  fontSize: 14, color: '#888', align: 'center' },
      { typ: 'alergeny', x_pct: 4,  y_pct: 82, w_pct: 92, h_pct: 8,  fontSize: 11, color: '#92400e', align: 'center' },
      { typ: 'slozeni',  x_pct: 4,  y_pct: 90, w_pct: 92, h_pct: 8,  fontSize: 9,  italic: true, color: '#999', align: 'center' },
    ],
  },
  {
    nazev: '★ Avery 8 (99×68) — Produkt s EAN',
    format_id: 'av-99x67-8',
    prvky: [
      { typ: 'nazev',    x_pct: 4,  y_pct: 8,  w_pct: 92, h_pct: 24, fontSize: 16, fontWeight: 700, align: 'left' },
      { typ: 'cena',     x_pct: 4,  y_pct: 36, w_pct: 60, h_pct: 28, fontSize: 32, fontWeight: 900, color: '#854F0B', align: 'left' },
      { typ: 'ean',      x_pct: 66, y_pct: 38, w_pct: 30, h_pct: 16, fontSize: 9,  align: 'right' },
      { typ: 'hmotnost', x_pct: 4,  y_pct: 70, w_pct: 60, h_pct: 8,  fontSize: 9,  color: '#888', align: 'left' },
      { typ: 'kod',      x_pct: 66, y_pct: 70, w_pct: 30, h_pct: 8,  fontSize: 9,  color: '#888', align: 'right' },
    ],
  },
  {
    nazev: '★ Avery 21 (64×38) — Mini cenovka',
    format_id: 'av-63x38-21',
    prvky: [
      { typ: 'nazev',    x_pct: 4,  y_pct: 8,  w_pct: 92, h_pct: 22, fontSize: 11, fontWeight: 600, align: 'center' },
      { typ: 'cena',     x_pct: 4,  y_pct: 32, w_pct: 92, h_pct: 28, fontSize: 22, fontWeight: 900, color: '#854F0B', align: 'center' },
      { typ: 'hmotnost', x_pct: 4,  y_pct: 64, w_pct: 92, h_pct: 12, fontSize: 7,  color: '#888', align: 'center' },
      { typ: 'kod',      x_pct: 4,  y_pct: 84, w_pct: 92, h_pct: 8,  fontSize: 7,  color: '#aaa', align: 'center' },
    ],
  },
  {
    nazev: '★ Avery 21 (70×42) — Pruh nahoře',
    format_id: 'av-70x42-21',
    prvky: [
      { typ: 'nazev',    x_pct: 4,  y_pct: 4,  w_pct: 92, h_pct: 18, fontSize: 11, fontWeight: 700, color: '#fff', bg: '#854F0B', align: 'center' },
      { typ: 'cena',     x_pct: 4,  y_pct: 28, w_pct: 92, h_pct: 32, fontSize: 26, fontWeight: 900, color: '#854F0B', align: 'center' },
      { typ: 'hmotnost', x_pct: 4,  y_pct: 64, w_pct: 46, h_pct: 12, fontSize: 8,  align: 'left' },
      { typ: 'kod',      x_pct: 52, y_pct: 64, w_pct: 46, h_pct: 12, fontSize: 8,  color: '#888', align: 'right' },
    ],
  },
  {
    nazev: '★ Printky 10 (100×58) — Universal',
    format_id: 'pk-100x58-10',
    prvky: [
      { typ: 'nazev',    x_pct: 4,  y_pct: 8,  w_pct: 92, h_pct: 22, fontSize: 14, fontWeight: 700, align: 'center' },
      { typ: 'cena',     x_pct: 4,  y_pct: 34, w_pct: 92, h_pct: 30, fontSize: 32, fontWeight: 900, color: '#854F0B', align: 'center' },
      { typ: 'hmotnost', x_pct: 4,  y_pct: 70, w_pct: 46, h_pct: 14, fontSize: 9,  align: 'left' },
      { typ: 'ean',      x_pct: 52, y_pct: 70, w_pct: 46, h_pct: 14, fontSize: 8,  align: 'right' },
    ],
  },
  {
    nazev: '★ Printky 42 mini (66×21) — Cena vpravo',
    format_id: 'pk-66x21-42',
    prvky: [
      { typ: 'nazev',    x_pct: 2,  y_pct: 12, w_pct: 60, h_pct: 70, fontSize: 9,  fontWeight: 600, align: 'left' },
      { typ: 'cena',     x_pct: 64, y_pct: 12, w_pct: 34, h_pct: 70, fontSize: 16, fontWeight: 900, color: '#854F0B', align: 'right' },
    ],
  },
  {
    nazev: '★ Appek 6 (fold) — Cenovka regál',
    format_id: 'rp-cenovka-6',
    prvky: [
      // fold: použijeme dolní polovinu — y >= 55 (záhyb na 50 %)
      { typ: 'nazev',    x_pct: 4,  y_pct: 56, w_pct: 92, h_pct: 10, fontSize: 16, fontWeight: 700, align: 'center' },
      { typ: 'hmotnost', x_pct: 4,  y_pct: 68, w_pct: 92, h_pct: 5,  fontSize: 10, color: '#777', align: 'center' },
      { typ: 'cena',     x_pct: 4,  y_pct: 75, w_pct: 92, h_pct: 18, fontSize: 48, fontWeight: 900, color: '#854F0B', align: 'center' },
      { typ: 'cenakg',   x_pct: 4,  y_pct: 95, w_pct: 92, h_pct: 4,  fontSize: 8,  color: '#888', align: 'center' },
    ],
  },
  {
    nazev: '★ Appek 8 (fold) — Kompaktní cenovka',
    format_id: 'rp-cenovka-8',
    prvky: [
      { typ: 'nazev',    x_pct: 4,  y_pct: 56, w_pct: 92, h_pct: 12, fontSize: 13, fontWeight: 700, align: 'center' },
      { typ: 'cena',     x_pct: 4,  y_pct: 72, w_pct: 92, h_pct: 22, fontSize: 32, fontWeight: 900, color: '#854F0B', align: 'center' },
      { typ: 'hmotnost', x_pct: 4,  y_pct: 95, w_pct: 92, h_pct: 5,  fontSize: 7,  color: '#888', align: 'center' },
    ],
  },
  // === Druhých 10 ===
  {
    nazev: '★ A4 plakát — Hero produkt',
    format_id: 'pk-210x297-1',
    prvky: [
      { typ: 'logo',     x_pct: 4,  y_pct: 2,  w_pct: 20, h_pct: 8,  fontSize: 36, align: 'left' },
      { typ: 'text',     x_pct: 60, y_pct: 4,  w_pct: 36, h_pct: 6,  text: 'NOVINKA', fontSize: 28, fontWeight: 900, color: '#fff', bg: '#dc2626', align: 'center' },
      { typ: 'nazev',    x_pct: 4,  y_pct: 14, w_pct: 92, h_pct: 16, fontSize: 56, fontWeight: 700, align: 'center' },
      { typ: 'popis',    x_pct: 4,  y_pct: 32, w_pct: 92, h_pct: 6,  fontSize: 18, italic: true, color: '#666', align: 'center' },
      { typ: 'cena',     x_pct: 4,  y_pct: 42, w_pct: 92, h_pct: 28, fontSize: 180, fontWeight: 900, color: '#854F0B', align: 'center' },
      { typ: 'cenakg',   x_pct: 4,  y_pct: 72, w_pct: 92, h_pct: 6,  fontSize: 18, color: '#888', align: 'center' },
      { typ: 'alergeny', x_pct: 4,  y_pct: 80, w_pct: 92, h_pct: 6,  fontSize: 14, color: '#92400e', align: 'center' },
      { typ: 'slozeni',  x_pct: 4,  y_pct: 88, w_pct: 92, h_pct: 8,  fontSize: 11, italic: true, color: '#999', align: 'center' },
    ],
  },
  {
    nazev: '★ Printky 6 (70×148) — Minimalist',
    format_id: 'pk-70x148-6',
    prvky: [
      { typ: 'nazev',    x_pct: 4,  y_pct: 4,  w_pct: 92, h_pct: 14, fontSize: 18, fontWeight: 700, align: 'center' },
      { typ: 'cena',     x_pct: 4,  y_pct: 28, w_pct: 92, h_pct: 38, fontSize: 80, fontWeight: 900, color: '#1a1a1a', align: 'center' },
      { typ: 'hmotnost', x_pct: 4,  y_pct: 70, w_pct: 92, h_pct: 6,  fontSize: 12, color: '#888', align: 'center' },
      { typ: 'cenakg',   x_pct: 4,  y_pct: 77, w_pct: 92, h_pct: 5,  fontSize: 10, color: '#aaa', align: 'center' },
      { typ: 'kod',      x_pct: 4,  y_pct: 92, w_pct: 92, h_pct: 5,  fontSize: 8,  color: '#bbb', align: 'center' },
    ],
  },
  {
    nazev: '★ SEVT 10 (105×57) — Klasik s rámečkem',
    format_id: 'se-105x57-10',
    prvky: [
      { typ: 'nazev',    x_pct: 6,  y_pct: 12, w_pct: 88, h_pct: 22, fontSize: 14, fontWeight: 700, color: '#fff', bg: '#854F0B', align: 'center', padding: 6 },
      { typ: 'cena',     x_pct: 6,  y_pct: 38, w_pct: 60, h_pct: 30, fontSize: 30, fontWeight: 900, color: '#854F0B', align: 'left' },
      { typ: 'hmotnost', x_pct: 66, y_pct: 38, w_pct: 30, h_pct: 12, fontSize: 9,  color: '#777', align: 'right' },
      { typ: 'ean',      x_pct: 66, y_pct: 54, w_pct: 30, h_pct: 12, fontSize: 8,  align: 'right' },
      { typ: 'kod',      x_pct: 6,  y_pct: 72, w_pct: 88, h_pct: 8,  fontSize: 8,  color: '#aaa', align: 'center' },
    ],
  },
  {
    nazev: '★ SEVT 14 (105×42) — Štítek s logem',
    format_id: 'se-105x42-14',
    prvky: [
      { typ: 'logo',     x_pct: 4,  y_pct: 10, w_pct: 16, h_pct: 60, fontSize: 28, align: 'center' },
      { typ: 'nazev',    x_pct: 22, y_pct: 10, w_pct: 76, h_pct: 30, fontSize: 13, fontWeight: 700, align: 'left' },
      { typ: 'cena',     x_pct: 22, y_pct: 44, w_pct: 50, h_pct: 38, fontSize: 26, fontWeight: 900, color: '#854F0B', align: 'left' },
      { typ: 'hmotnost', x_pct: 74, y_pct: 50, w_pct: 24, h_pct: 16, fontSize: 9,  color: '#777', align: 'right' },
      { typ: 'kod',      x_pct: 22, y_pct: 86, w_pct: 76, h_pct: 8,  fontSize: 7,  color: '#aaa', align: 'left' },
    ],
  },
  {
    nazev: '★ SEVT 18 (63×47) — Vintage rámeček',
    format_id: 'se-63x46-18',
    prvky: [
      { typ: 'text',     x_pct: 4,  y_pct: 6,  w_pct: 92, h_pct: 4,  text: '✦ ✦ ✦', fontSize: 8, color: '#854F0B', align: 'center' },
      { typ: 'nazev',    x_pct: 4,  y_pct: 14, w_pct: 92, h_pct: 22, fontSize: 11, fontWeight: 700, italic: true, color: '#5C3608', align: 'center' },
      { typ: 'cena',     x_pct: 4,  y_pct: 38, w_pct: 92, h_pct: 36, fontSize: 26, fontWeight: 900, color: '#854F0B', align: 'center' },
      { typ: 'hmotnost', x_pct: 4,  y_pct: 78, w_pct: 92, h_pct: 8,  fontSize: 7,  color: '#888', align: 'center' },
      { typ: 'text',     x_pct: 4,  y_pct: 90, w_pct: 92, h_pct: 4,  text: '✦ ✦ ✦', fontSize: 8, color: '#854F0B', align: 'center' },
    ],
  },
  {
    nazev: '★ Avery 24 (70×36) — Eco / Bio',
    format_id: 'av-70x36-24',
    prvky: [
      { typ: 'text',     x_pct: 4,  y_pct: 6,  w_pct: 30, h_pct: 16, text: '🌱 BIO', fontSize: 11, fontWeight: 900, color: '#fff', bg: '#15803d', align: 'center' },
      { typ: 'nazev',    x_pct: 36, y_pct: 8,  w_pct: 60, h_pct: 14, fontSize: 11, fontWeight: 700, color: '#15803d', align: 'left' },
      { typ: 'cena',     x_pct: 4,  y_pct: 30, w_pct: 60, h_pct: 36, fontSize: 24, fontWeight: 900, color: '#15803d', align: 'left' },
      { typ: 'hmotnost', x_pct: 66, y_pct: 36, w_pct: 30, h_pct: 14, fontSize: 8,  color: '#666', align: 'right' },
      { typ: 'cenakg',   x_pct: 66, y_pct: 54, w_pct: 30, h_pct: 12, fontSize: 7,  color: '#888', align: 'right' },
      { typ: 'kod',      x_pct: 4,  y_pct: 78, w_pct: 92, h_pct: 12, fontSize: 7,  color: '#aaa', align: 'center' },
    ],
  },
  {
    nazev: '★ Avery 33 (70×25) — Mini akce',
    format_id: 'av-70x25-33',
    prvky: [
      { typ: 'text',     x_pct: 0,  y_pct: 0,  w_pct: 100, h_pct: 28, text: '🔥 AKCE', fontSize: 10, fontWeight: 900, color: '#fff', bg: '#dc2626', align: 'center' },
      { typ: 'nazev',    x_pct: 4,  y_pct: 32, w_pct: 58, h_pct: 36, fontSize: 9,  fontWeight: 600, align: 'left' },
      { typ: 'cena',     x_pct: 62, y_pct: 32, w_pct: 36, h_pct: 56, fontSize: 18, fontWeight: 900, color: '#dc2626', align: 'right' },
      { typ: 'hmotnost', x_pct: 4,  y_pct: 72, w_pct: 58, h_pct: 18, fontSize: 7,  color: '#888', align: 'left' },
    ],
  },
  {
    nazev: '★ Printky 44 (52×25) — Kompakt cena',
    format_id: 'pk-52x25-44',
    prvky: [
      { typ: 'nazev',    x_pct: 4,  y_pct: 8,  w_pct: 92, h_pct: 36, fontSize: 8,  fontWeight: 600, align: 'center' },
      { typ: 'cena',     x_pct: 4,  y_pct: 46, w_pct: 92, h_pct: 38, fontSize: 16, fontWeight: 900, color: '#854F0B', align: 'center' },
      { typ: 'hmotnost', x_pct: 4,  y_pct: 86, w_pct: 92, h_pct: 10, fontSize: 6,  color: '#999', align: 'center' },
    ],
  },
  {
    nazev: '★ Printky 65 (38×21) — Tiny produkty',
    format_id: 'pk-38x21-65',
    prvky: [
      { typ: 'nazev',    x_pct: 4,  y_pct: 10, w_pct: 92, h_pct: 38, fontSize: 7,  fontWeight: 600, align: 'center' },
      { typ: 'cena',     x_pct: 4,  y_pct: 52, w_pct: 92, h_pct: 38, fontSize: 13, fontWeight: 900, color: '#854F0B', align: 'center' },
    ],
  },
  {
    nazev: '★ SEVT 52 (52×21) — Inventory tag',
    format_id: 'se-52x21-52',
    prvky: [
      { typ: 'kod',      x_pct: 2,  y_pct: 10, w_pct: 30, h_pct: 80, fontSize: 8,  fontWeight: 900, color: '#854F0B', align: 'left' },
      { typ: 'nazev',    x_pct: 34, y_pct: 6,  w_pct: 64, h_pct: 38, fontSize: 7,  fontWeight: 600, align: 'left' },
      { typ: 'cena',     x_pct: 34, y_pct: 46, w_pct: 64, h_pct: 42, fontSize: 12, fontWeight: 900, color: '#222', align: 'right' },
    ],
  },
];

window.ed_seedPreset = async function() {
  if (!(await confirmDialog({ msg: t('confirm_create_template_presets', { n: SABLONY_PRESET.length }), danger: true }))) return;
  let ok = 0, chyby = 0;
  for (const tpl of SABLONY_PRESET) {
    try {
      await api('admin_stitky_sablony.php', {
        method: 'POST',
        body: { nazev: tpl.nazev, format_id: tpl.format_id, layout: { prvky: tpl.prvky } },
      });
      ok++;
    } catch (e) {
      console.error('Seed šablona selhala:', tpl.nazev, e);
      chyby++;
    }
  }
  alert(t('templates_created', { ok, chyby_text: chyby > 0 ? ` (${chyby} chyba)` : '' }));
  // Refresh seznam v editoru
  try {
    stEditor.ulozeneSablony = await api('admin_stitky_sablony.php');
    stState.sablony = stEditor.ulozeneSablony;
  } catch (e) {}
  vykreslitEditor();
};

window.ed_nacist = async function(id) {
  if (!id) return;
  if (stEditor.prvky.length > 0 && !(await confirmDialog({ msg: 'Načíst jinou šablonu? Neuložené změny budou ztraceny.', danger: false }))) return;
  try {
    const sab = await api(`admin_stitky_sablony.php?id=${id}`);
    stEditor.sablonaId = parseInt(sab.id) || sab.id;
    stEditor.nazev = sab.nazev || '';
    if (sab.format_id) stEditor.formatId = sab.format_id;
    // Fallback pro různé tvary uloženého layoutu (objekt {prvky:[]} | přímo pole | string)
    let layoutData = sab.layout;
    if (typeof layoutData === 'string') {
      try { layoutData = JSON.parse(layoutData); } catch (e) { layoutData = {}; }
    }
    let prvkyRaw = [];
    if (Array.isArray(layoutData)) prvkyRaw = layoutData;
    else if (Array.isArray(layoutData?.prvky)) prvkyRaw = layoutData.prvky;
    // Sanitize — zajistí povinné fieldy
    stEditor.prvky = prvkyRaw.map((p, i) => ed_sanitizePrvek(p, i));
    stEditor.vybranyId = null;
    if (stEditor.prvky.length === 0) {
      alert('Šablona "' + (sab.nazev || '?') + '" neobsahuje žádné prvky.\n\nMožné příčiny:\n• Šablona byla uložena prázdná\n• Layout je v jiném formátu — kontaktuj podporu');
    }
    vykreslitEditor();
  } catch (e) {
    console.error('ed_nacist chyba:', e);
    alert('Chyba: ' + e.message);
  }
};

window.ed_ulozit = async function() {
  if (!stEditor.nazev?.trim()) return alert('Vyplňte název šablony');
  const wasUpdate = !!stEditor.sablonaId;
  const data = {
    id: stEditor.sablonaId || undefined,
    nazev: stEditor.nazev.trim(),
    format_id: stEditor.formatId,
    layout: { prvky: stEditor.prvky },
  };
  try {
    const r = await api('admin_stitky_sablony.php', {
      method: wasUpdate ? 'PUT' : 'POST',
      body: JSON.stringify(data),
    });
    // Po POSTu nám BE vrátí {id: N}. Po PUTu vrátí {ok: true} a id už máme.
    if (r.id) stEditor.sablonaId = parseInt(r.id) || r.id;

    // 🛡️ Defense: pokud se z nějakého důvodu nepodařilo zjistit id, dál nepokračujeme
    if (!stEditor.sablonaId) {
      throw new Error('Nepodařilo se získat ID uložené šablony');
    }

    // 🔄 Invalidate cache (api() to dělá auto, ale pojistka pro jistotu)
    apiCacheInvalidate('admin_stitky_sablony.php');

    // Refresh seznam šablon — pro editor i pro picker v cenovkách
    const fresh = await api('admin_stitky_sablony.php');
    stEditor.ulozeneSablony = fresh;
    stState.sablony = fresh;

    // 🛡️ Defense: ověř, že nová/upravená šablona je opravdu v seznamu
    const sablonaInList = fresh.find(t => parseInt(t.id) === parseInt(stEditor.sablonaId));
    if (!sablonaInList) {
      console.warn('Uložená šablona nenalezena v refresh seznamu (možná replikace), ale id mám:', stEditor.sablonaId);
    }

    // 🔁 KLÍČOVÉ: po uložení automaticky aktivovat uloženou šablonu jako vybranou,
    // aby se v cenovkách / Tisku/Náhledu zobrazila NOVÁ verze (ne původní design).
    stState.sablonaId = stEditor.sablonaId;
    stState.designId = null;
    // Pokud editor přebral formát ze šablony, sjednoť i ve stState (aby náhled / tisk používaly stejný formát)
    if (stEditor.formatId) stState.formatId = stEditor.formatId;

    vykreslitEditor();

    // Toast s tlačítkem zpět
    const existing = document.querySelector('.ed-save-toast');
    if (existing) existing.remove();
    const toast = document.createElement('div');
    toast.className = 'ed-save-toast';
    toast.style.cssText = 'position:fixed;bottom:24px;right:24px;background:var(--success-bg);color:var(--success-text);padding:14px 22px;border-radius:8px;box-shadow:0 4px 12px rgba(0,0,0,0.15);font-size:14px;font-weight:600;z-index:9999;display:flex;align-items:center;gap:10px;max-width:90vw';
    toast.innerHTML = `<span>✓ ${wasUpdate ? 'Šablona upravena' : 'Šablona uložena'}</span><button onclick="this.closest('.ed-save-toast').remove();stSetRezim('cenovky')" style="background:rgba(255,255,255,0.3);border:none;color:inherit;padding:6px 12px;border-radius:6px;cursor:pointer;font-weight:700;font-size:13px;white-space:nowrap">→ Zpět k cenovkám</button>`;
    document.body.appendChild(toast);
    setTimeout(() => toast.remove(), 6000);
  } catch (e) {
    alert('Chyba při ukládání: ' + e.message);
    console.error('ed_ulozit failed:', e);
  }
};

window.ed_smazat = async function() {
  if (!stEditor.sablonaId) return;
  if (!await confirmDelete2x(`šablonu "${stEditor.nazev}"`)) return;
  try {
    await api(`admin_stitky_sablony.php?id=${stEditor.sablonaId}`, { method: 'DELETE' });
    stEditor.sablonaId = null;
    stEditor.nazev = 'Nová šablona';
    stEditor.prvky = [];
    stEditor.ulozeneSablony = await api('admin_stitky_sablony.php');
    vykreslitEditor();
  } catch (e) { alert('Chyba: ' + e.message); }
};

// Klávesnice — Del / Esc
document.addEventListener('keydown', (e) => {
  if (stState.rezim !== 'editor') return;
  if (!stEditor.vybranyId) return;
  // Pokud má focus formulářové pole, neřeš
  const tag = (document.activeElement?.tagName || '').toLowerCase();
  if (tag === 'input' || tag === 'textarea' || tag === 'select') return;
  if (e.key === 'Delete' || e.key === 'Backspace') {
    e.preventDefault();
    ed_smazatPrvek(stEditor.vybranyId);
  } else if (e.key === 'Escape') {
    stEditor.vybranyId = null;
    vykreslitEditor();
  }
});

window.stSetFormat = function(id) {
  stState.formatId = id;
  renderStitky();
};

window.stToggleObj = function(id) {
  if (stState.vybraneObj.has(id)) stState.vybraneObj.delete(id);
  else stState.vybraneObj.add(id);
  renderStitky();
};

window.stToggleVyr = function(id) {
  id = parseInt(id);
  if (stState.vybraneVyr.has(id)) stState.vybraneVyr.delete(id);
  else stState.vybraneVyr.add(id);
  // Aktualizuj seznam (visual select state) i mřížku samostatně (rychlejší než full renderStitky který je async)
  const ll = document.getElementById('st-vyr-list');
  if (ll) ll.innerHTML = stRenderVyrList();
  stRefreshGridPreview();
};
// Refresh jen samotný grid preview (zoom buttons / fast toggle)
function stRefreshGridPreview() {
  try {
    const fmt = STITKY_FORMATY.find(f => f.id === stState.formatId) || STITKY_FORMATY[0];
    const host = document.getElementById('st-grid-host');
    if (host) {
      host.innerHTML = stRenderGrid(fmt, stState.startPos);
      // 🔧 Autofit po insertu — smrští přesahující texty
      setTimeout(() => autoFitPrvky(host), 0);
      requestAnimationFrame(() => requestAnimationFrame(() => autoFitPrvky(host)));
    } else {
      // Fallback — host neexistuje (jiný tab) → full re-render
      renderStitky();
    }
  } catch (e) {
    console.error('stRefreshGridPreview failed:', e);
    renderStitky();
  }
}

window.stToggleVse = function(typ, aktivovat) {
  if (typ === 'obj') {
    if (aktivovat) stState.obj.forEach(o => stState.vybraneObj.add(o.id));
    else stState.vybraneObj.clear();
  } else if (typ === 'moje') {
    const q = (stState.mojeQ || '').toLowerCase();
    const list = (stState.mojeStitky || []).filter(m => {
      if (!q) return true;
      return (m.nazev || '').toLowerCase().includes(q);
    });
    if (aktivovat) list.forEach(m => stState.vybraneMoje.add(m.id));
    else stState.vybraneMoje.clear();
  } else {
    const q = (stState.cenovkaQ || '').toLowerCase();
    const list = (stState.vyrobky || []).filter(v => {
      if (parseInt(v.aktivni) === 0) return false;
      if (!q) return true;
      return (v.nazev || '').toLowerCase().includes(q);
    });
    if (aktivovat) list.forEach(v => stState.vybraneVyr.add(v.id));
    else stState.vybraneVyr.clear();
  }
  renderStitky();
};

window.stitkyTisknout = async function(jenNahled) {
  const s = stState;
  // Pokud je vybraná šablona, načti její data — případně přepneme format
  let sablona = null;
  if ((s.rezim === 'cenovky' || s.rezim === 'moje') && s.sablonaId) {
    sablona = s.sablony.find(t => t.id == s.sablonaId);
    if (sablona && !sablona.layout) {
      // List endpoint nevrací layout — dotáhni detail
      try { sablona = await api(`admin_stitky_sablony.php?id=${s.sablonaId}`); }
      catch (e) { sablona = null; }
    }
  }
  // Format — pokud máme uloženou šablonu, použij její formát; jinak aktuální (designy se škálují na něj)
  const fmtSrc = sablona && sablona.format_id
    ? (STITKY_FORMATY.find(f => f.id === sablona.format_id) || STITKY_FORMATY[0])
    : (s.formatId === 'custom'
        ? { ...s.custom, custom: true }
        : (STITKY_FORMATY.find(f => f.id === s.formatId) || STITKY_FORMATY[0]));
  const fmt = { ...fmtSrc };

  // 🎨 Pokud je vybraný built-in design (a žádná uložená šablona), vytvoř virtuální sablonu s auto-scale prvky
  // foldHalf → Appek cenovky se sklady mají prvky jen ve spodní polovině
  if (!sablona && (s.rezim === 'cenovky' || s.rezim === 'moje') && s.designId) {
    sablona = designAsSablona(s.designId, parseFloat(fmt.w) || 70, parseFloat(fmt.h) || 42, !!fmt.foldHalf);
  }

  // Helper: vyber renderovací funkci podle šablony
  const renderCenovka = (data) => sablona
    ? stitekHtmlSablona(data, sablona)
    : stitekHtmlCenovka(data);

  let stitky = []; // pole HTML stringů
  if (s.rezim === 'expedicni') {
    if (s.vybraneObj.size === 0) return alert('Vyberte alespoň jednu objednávku');
    s.obj.filter(o => s.vybraneObj.has(o.id)).forEach(o => {
      for (let k = 0; k < (s.kopie || 1); k++) stitky.push(stitekHtmlExpedicni(o));
    });
  } else if (s.rezim === 'cenovky') {
    if (s.vybraneVyr.size === 0) return alert('Vyberte alespoň jeden výrobek');
    s.vyrobky.filter(v => s.vybraneVyr.has(v.id)).forEach(v => {
      // 🆕 v2.0.77 — per-product quantity (chip override) místo globální cenovkaPocet
      const kopii = stGetPocetVyrobek(v.id);
      for (let k = 0; k < kopii; k++) stitky.push(renderCenovka(v));
    });
  } else if (s.rezim === 'moje') {
    if (s.vybraneMoje.size === 0) return alert('Vyberte alespoň jeden vlastní štítek');
    s.mojeStitky.filter(m => s.vybraneMoje.has(m.id)).forEach(m => {
      // Přemapuj na "vyrobek-like" — renderCenovka spočítá cenu s DPH z cena_bez_dph + dph
      const dph = parseFloat(m.sazba_dph) || 12;
      const cena_s = parseFloat(m.cena_s_dph) || 0;
      const adapted = {
        nazev: m.nazev,
        cislo: m.cislo,
        ean: m.ean,
        cena_bez_dph: cena_s / (1 + dph / 100),
        dph: dph,
        jednotka: m.jednotka || 'ks',
        hmotnost_g: m.hmotnost_g,
        obsah: m.obsah,
        obsah_jednotka: m.obsah_jednotka,
        slozeni: m.slozeni,
        alergeny: m.alergeny,
        // Per-štítek badge má přednost před globálním
        _localBadge: m.badge,
        _localBadgeText: m.badge_text,
      };
      for (let k = 0; k < (s.cenovkaPocet || 1); k++) stitky.push(renderCenovka(adapted));
    });
  }

  // Skip prázdné buňky podle startPos
  const empty = Math.max(0, (s.startPos || 1) - 1);
  for (let i = 0; i < empty; i++) stitky.unshift('<div class="st-cell st-empty"></div>');

  const win = window.open('', '_blank');
  win.document.open();
  win.document.write(buildStitkyHtml(fmt, stitky, jenNahled));
  win.document.close();
};

function buildStitkyHtml(fmt, stitky, jenNahled) {
  const cellPerPage = fmt.cols * fmt.rows;
  const pages = [];
  for (let i = 0; i < stitky.length; i += cellPerPage) {
    pages.push(stitky.slice(i, i + cellPerPage));
  }
  if (pages.length === 0) pages.push([]);

  // Škálovací faktor — relativní vůči referenční velikosti (Avery 70×42 = 2940 mm²)
  const area = fmt.w * fmt.h;
  const ref = 70 * 42;
  let scale = Math.sqrt(area / ref);
  scale = Math.max(0.45, Math.min(1.7, scale));
  // Pomocné: vyrobí pt (z bázové hodnoty)
  const pt = (base) => (base * scale).toFixed(2) + 'pt';
  const mm = (base) => (base * Math.max(0.5, Math.min(1.4, scale))).toFixed(2) + 'mm';

  const css = `
    @page { size: A4; margin: 0; }
    * { box-sizing: border-box; }
    body { margin: 0; padding: 0; font-family: 'Helvetica Neue', Arial, sans-serif; background: #ddd; }
    .toolbar { padding: 12px 16px; background: #fff; display: flex; gap: 8px; justify-content: flex-end; box-shadow: 0 1px 3px rgba(0,0,0,.08); position: sticky; top: 0; z-index: 100; }
    .toolbar button { padding: 8px 16px; border: 1px solid #ccc; background: #fff; border-radius: 6px; cursor: pointer; font-size: 14px; }
    .toolbar .btn-print { background: #BA7517; color: #fff; border-color: #BA7517; }
    .page {
      width: 210mm;
      height: 297mm;
      background: #fff;
      margin: 12px auto;
      padding: ${fmt.mTop}mm 0 0 ${fmt.mLeft}mm;
      box-shadow: 0 0 8px rgba(0,0,0,0.15);
      page-break-after: always;
      position: relative;
    }
    .page:last-child { page-break-after: auto; }
    .grid {
      display: grid;
      grid-template-columns: repeat(${fmt.cols}, ${fmt.w}mm);
      grid-template-rows: repeat(${fmt.rows}, ${fmt.h}mm);
      column-gap: ${fmt.gapX || 0}mm;
      row-gap: ${fmt.gapY || 0}mm;
    }
    .st-cell {
      width: ${fmt.w}mm;
      height: ${fmt.h}mm;
      overflow: hidden;
      padding: ${Math.max(2.5, Math.min(fmt.h * 0.12, 5)).toFixed(2)}mm ${Math.max(3, Math.min(fmt.w * 0.10, 6)).toFixed(2)}mm;
      display: flex;
      flex-direction: column;
      justify-content: center;
      text-align: center;
      border: 0.2mm dashed #eee;
    }
    .st-cell.st-empty { background: transparent; border: 0.2mm dashed #f5f5f5; }
    ${fmt.foldHalf ? `
    /* === Appek fold — tisk jen v dolní polovině buňky === */
    .st-cell:not(.st-empty) {
      padding-top: ${(fmt.h * 0.5 + 2).toFixed(2)}mm !important;
      position: relative;
    }
    /* Badge u fold režimu začíná od půlky štítku, ne od vrchu */
    .st-cell:not(.st-empty) .st-cen-badge {
      top: ${(fmt.h * 0.5).toFixed(2)}mm !important;
    }
    .st-cell.has-badge:not(.st-empty) {
      padding-top: calc(${(fmt.h * 0.5).toFixed(2)}mm + ${mm(4.5)}) !important;
    }
    .st-cell:not(.st-empty)::before {
      content: '';
      position: absolute;
      top: ${(fmt.h * 0.5).toFixed(2)}mm;
      left: 1mm;
      right: 1mm;
      border-top: 0.3mm dashed #d1d1d1;
      pointer-events: none;
      z-index: 0;
    }
    .st-cell:not(.st-empty)::after {
      content: '✂ sklad zde';
      position: absolute;
      top: calc(${(fmt.h * 0.5).toFixed(2)}mm - 1.6mm);
      left: 50%;
      transform: translateX(-50%);
      background: white;
      color: #aaa;
      font-size: 5pt;
      padding: 0 1.5mm;
      letter-spacing: 0.05em;
      pointer-events: none;
      z-index: 1;
    }
    @media print {
      .st-cell:not(.st-empty)::before { border-top-color: #ccc; }
      .st-cell:not(.st-empty)::after { background: white; color: #bbb; }
    }
    ` : ''}
    /* Expediční s QR — flex layout */
    .st-cell-exp { flex-direction: row; align-items: center; gap: ${mm(1.5)}; text-align: left; }
    .st-cell-exp .st-exp-content { flex: 1; min-width: 0; overflow: hidden; }
    .st-exp-h1 { font-size: ${pt(7.5)}; font-weight: 600; line-height: 1.1; margin-bottom: ${mm(0.6)}; word-break: break-word; color: #555; }
    .st-exp-pob { font-size: ${pt(11)}; font-weight: 800; line-height: 1.1; margin-bottom: ${mm(1)}; word-break: break-word; }
    .st-exp-addr { font-size: ${pt(7)}; color: #555; line-height: 1.25; margin-bottom: ${mm(0.8)}; }
    .st-exp-meta { font-size: ${pt(7.5)}; line-height: 1.4; }
    .st-exp-cislo { font-weight: 700; }
    .st-exp-qr {
      width: ${Math.max(8, Math.min(20, fmt.h * 0.32)).toFixed(1)}mm;
      height: ${Math.max(8, Math.min(20, fmt.h * 0.32)).toFixed(1)}mm;
      flex-shrink: 0;
    }
    .st-exp-qr svg, .st-exp-qr img { width: 100% !important; height: 100% !important; display: block; }
    /* === Modern cenovka — hero cena, kompaktní meta === */
    .st-cell-cen {
      flex-direction: column;
      justify-content: space-between;
      align-items: stretch;
      text-align: left;
      gap: 0;
    }
    .st-cell-cen.has-badge { padding-top: ${mm(4.5)} !important; }
    .st-cen-head { width: 100%; flex-shrink: 1; min-height: 0; overflow: hidden; }
    .st-cen-nazev {
      font-size: ${pt(11)};
      font-weight: 700;
      line-height: 1.15;
      letter-spacing: -0.01em;
      color: #2C2C2A;
      margin-bottom: ${mm(0.6)};
      word-break: break-word;
    }
    .st-cen-popis {
      font-size: ${pt(6.5)};
      font-style: italic;
      color: #666;
      line-height: 1.25;
      margin-bottom: ${mm(1)};
      word-break: break-word;
    }
    .st-cen-meta-line {
      display: flex;
      flex-wrap: wrap;
      align-items: center;
      gap: ${mm(0.6)} ${mm(1.5)};
      font-size: ${pt(5.5)};
      color: #888;
      margin-bottom: ${mm(1.6)};
      line-height: 1.2;
    }
    .st-cen-meta-item { white-space: nowrap; }
    .st-cen-meta-aler { color: #b45309; }
    .st-cen-meta-cislo { color: #999; font-family: monospace; }
    .st-cen-meta-dph {
      color: #0C447C;
      background: #EFF6FF;
      padding: ${mm(0.15)} ${mm(0.7)};
      border-radius: ${mm(0.5)};
      font-weight: 700;
      font-size: 0.92em;
      letter-spacing: 0.01em;
    }

    /* Info blok (složení + alergeny) — každý řádek samostatně, auto-fit je sloučí pokud nestačí místo */
    .st-cen-info {
      display: flex;
      flex-direction: column;
      gap: ${mm(0.5)};
      font-size: ${pt(5)};
      color: #777;
      line-height: 1.2;
      margin-top: ${mm(0.8)};
      margin-bottom: ${mm(0.5)};
      text-align: left;
      word-break: break-word;
      hyphens: auto;
    }
    .st-cen-info .info-line { display: block; }
    .st-cen-info b { font-weight: 700; color: #555; }
    .st-cen-info .info-aler b { color: #b45309; }
    .st-cen-info .info-aler { color: #92400e; }
    /* Inline fallback — když nestačí místo, sloučíme na jeden řádek */
    .st-cen-info.is-inline { display: block; }
    .st-cen-info.is-inline .info-line { display: inline; }
    .st-cen-info.is-inline .info-line + .info-line::before {
      content: ' · ';
      color: #bbb;
      font-weight: 400;
    }

    /* PRICE BLOCK — hero */
    .st-cen-price-block {
      width: 100%;
      margin-top: auto;
      padding-top: ${mm(1.4)};
      border-top: 1px solid rgba(0,0,0,0.08);
    }
    .st-cen-cena {
      font-size: ${pt(40)};
      font-weight: 900;
      line-height: 0.88;
      letter-spacing: -0.035em;
      color: #2C2C2A;
      white-space: nowrap;
      display: flex;
      align-items: baseline;
      gap: ${mm(1)};
    }
    .st-cen-mena {
      font-size: 0.42em;
      font-weight: 700;
      color: #BA7517;
      letter-spacing: 0;
    }
    .st-cen-secondary {
      display: flex;
      flex-wrap: wrap;
      gap: ${mm(0.6)} ${mm(1.5)};
      align-items: baseline;
      margin-top: ${mm(0.8)};
      font-size: ${pt(6.5)};
      line-height: 1.3;
    }
    .st-cen-jed {
      color: #666;
      font-weight: 600;
      text-transform: lowercase;
      letter-spacing: 0.02em;
    }
    .st-cen-cenakg {
      background: #FFF8E5;
      color: #854F0B;
      padding: ${mm(0.4)} ${mm(1.2)};
      border-radius: ${mm(0.8)};
      font-weight: 700;
      white-space: nowrap;
    }
    .st-cen-cenakg-only {
      font-size: ${pt(14)};
      font-weight: 800;
      color: #854F0B;
      margin-top: auto;
    }

    /* FOOT — EAN */
    .st-cen-foot { width: 100%; margin-top: ${mm(0.8)}; line-height: 0; }
    .st-cen-ean svg {
      width: auto;
      max-width: 92%;
      height: auto;
      max-height: ${Math.max(6, fmt.h * 0.22).toFixed(1)}mm;
    }

    /* === Šablona z editoru — absolute positioning prvků === */
    .st-cell-sablona {
      padding: 0 !important;
      flex-direction: row;
      justify-content: flex-start;
      align-items: flex-start;
      gap: 0;
      position: relative;
    }
    .sab-prvek {
      position: absolute;
      box-sizing: border-box;
      overflow: hidden;
      display: flex;
      align-items: center;
      line-height: 1.15;
      word-break: break-word;
    }
    .sab-prvek b { font-weight: 700; }
    .sab-prvek svg { max-width: 100%; max-height: 100%; }
    .sab-prvek > div { width: 100%; height: 100%; }
    /* 🥗 v2.9.302 — Nutriční hodnoty (na 100 g) — kompaktní EU-style tabulka pro tisk */
    .sab-prvek.sab-typ-nutri { align-items: flex-start; padding: ${mm(0.4)}; line-height: 1.18; }
    .sab-nutri-tbl {
      width: 100%; height: 100%;
      display: flex; flex-direction: column;
      font-size: inherit;
      border: 0.3pt solid #555;
      padding: ${mm(0.5)} ${mm(0.8)};
      box-sizing: border-box;
      gap: ${mm(0.15)};
    }
    .sab-nutri-head {
      font-weight: 700;
      border-bottom: 0.3pt solid #555;
      padding-bottom: ${mm(0.3)};
      margin-bottom: ${mm(0.3)};
      font-size: 1em;
      text-align: left;
    }
    .sab-nutri-cell {
      display: flex; justify-content: space-between; gap: ${mm(1)};
      font-size: 0.92em; line-height: 1.2;
    }
    .sab-nutri-l { color: #333; }
    .sab-nutri-v { font-weight: 600; white-space: nowrap; }

    /* BADGE — modern minimal ribbon */
    .st-cen-badge {
      position: absolute;
      top: 0;
      left: 0;
      right: 0;
      background: #dc2626;
      color: white;
      font-size: ${pt(6.5)};
      font-weight: 800;
      letter-spacing: 0.06em;
      padding: ${mm(0.6)} ${mm(1.5)};
      text-align: center;
      text-transform: uppercase;
    }
    .st-cell { position: relative; }
    @media print {
      body { background: #fff; }
      .toolbar { display: none; }
      .page { margin: 0; box-shadow: none; }
      .st-cell { border: none; }
    }
  `;

  // Po renderu: QR + auto-fit přetékajícího textu
  const qrScript = `
    <script src="https://cdn.jsdelivr.net/npm/qrcode-generator@1.4.4/qrcode.js"><\/script>
    <script>
      function autoFit(el, minPx) {
        if (!el) return;
        let size = parseFloat(getComputedStyle(el).fontSize) || 12;
        const parent = el.closest('.st-cell');
        if (!parent) return;
        let guard = 40;
        while (guard-- > 0 && (el.scrollHeight > el.offsetHeight + 0.5 || el.scrollWidth > el.offsetWidth + 0.5) && size > (minPx || 6)) {
          size -= 0.5;
          el.style.fontSize = size + 'px';
        }
      }
      function fitCell(cell) {
        const overflow = () => cell.scrollHeight > cell.offsetHeight + 1 || cell.scrollWidth > cell.offsetWidth + 1;

        // Krok 1: individuální shrink — JEN pro non-cena prvky (cena má prioritu)
        const candidates = cell.querySelectorAll('.st-cen-nazev, .st-cen-popis, .st-exp-pob, .st-exp-meta, .st-cen-info, .st-exp-h1, .st-exp-addr');
        candidates.forEach(el => autoFit(el, 4));

        // Krok 2: pokud info blok přetéká, zkus ho nejdřív sloučit do jednoho řádku
        const infoEl = cell.querySelector('.st-cen-info');
        if (overflow() && infoEl && infoEl.querySelectorAll('.info-line').length > 1) {
          infoEl.classList.add('is-inline');
          // Po sloučení re-fit info-blok pro jistotu
          autoFit(infoEl, 3.5);
        }

        // Krok 3: drop chipsů/řádků v určeném pořadí — info (složení/alergeny) až úplně nakonec
        const dropFirst = ['.st-cen-meta-dph', '.st-cen-cenakg', '.st-cen-meta-line', '.st-cen-popis', '.st-cen-info'];
        for (const sel of dropFirst) {
          if (!overflow()) break;
          cell.querySelectorAll(sel).forEach(el => el.style.display = 'none');
        }

        // Krok 4: až teď zmenšuj cenu a název (cena drží ratio s názvem)
        let guard = 18;
        while (guard-- > 0 && overflow()) {
          let zmenseno = false;
          cell.querySelectorAll('.st-cen-cena, .st-cen-nazev, .st-cen-jed').forEach(el => {
            const cur = parseFloat(getComputedStyle(el).fontSize) || 12;
            const min = el.classList.contains('st-cen-cena') ? 9 : 5;
            if (cur > min) { el.style.fontSize = (cur - 0.6) + 'px'; zmenseno = true; }
          });
          if (!zmenseno) break;
        }
      }
      window.addEventListener('load', () => {
        // Expediční QR
        document.querySelectorAll('.st-exp-qr[data-qr]').forEach(el => {
          try {
            const qr = qrcode(0, 'M');
            qr.addData(el.dataset.qr);
            qr.make();
            el.innerHTML = qr.createSvgTag({ scalable: true, margin: 0 });
          } catch (e) { console.warn('QR fail:', e); }
        });
        // QR ze šablon
        document.querySelectorAll('.sab-qr-placeholder[data-qr]').forEach(el => {
          try {
            const qr = qrcode(0, 'M');
            qr.addData(el.dataset.qr);
            qr.make();
            el.innerHTML = qr.createSvgTag({ scalable: true, margin: 0 });
          } catch (e) { console.warn('QR fail:', e); }
        });
        // Auto-fit pouze klasické cenovky/expediční (šablony mají pevné rozměry)
        document.querySelectorAll('.st-cell:not(.st-cell-sablona)').forEach(fitCell);
        ${!jenNahled ? "setTimeout(() => window.print(), 450);" : ''}
      });
    <\/script>
  `;

  return `<!DOCTYPE html>
<html lang="cs">
<head>
<meta charset="UTF-8">
<title>Štítky — tisk</title>
<style>${css}</style>
</head>
<body>
<div class="toolbar">
  <span style="margin-right:auto;color:#888;font-size:13px;align-self:center">
    ${stitky.length} štítků · ${pages.length} ${pages.length === 1 ? 'arch' : (pages.length < 5 ? 'archy' : 'archů')} · formát ${fmt.w}×${fmt.h} mm (${fmt.cols}×${fmt.rows}) · okraj ${fmt.mTop}/${fmt.mLeft} mm · mezera ${fmt.gapX}/${fmt.gapY} mm
  </span>
  <button onclick="window.close()">Zavřít</button>
  <button class="btn-print" onclick="window.print()">🖨️ Tisk</button>
</div>
<div class="print-info no-print" style="background:#F7F8FA;border-bottom:1px solid #E8D5B0;padding:10px 16px;font-size:12px;color:#5C3608;display:flex;gap:14px;flex-wrap:wrap;align-items:center">
  <strong>📋 Před tiskem zkontroluj v dialogu Tisku:</strong>
  <span>• Okraje (Margins): <strong>Žádné / None</strong></span>
  <span>• Tisk pozadí (Background graphics): <strong>ZAPNUTO</strong></span>
  <span>• Měřítko (Scale): <strong>100% / Skutečná velikost</strong></span>
  <span>• Záhlaví/zápatí: <strong>VYPNOUT</strong></span>
</div>
<style>@media print { .print-info { display: none !important; } }</style>
${pages.map(p => `<div class="page"><div class="grid">${p.join('')}</div></div>`).join('')}
${qrScript}
</body>
</html>`;
}

