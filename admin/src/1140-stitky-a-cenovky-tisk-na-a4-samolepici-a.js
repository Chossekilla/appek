// =============================================================
// ŠTÍTKY A CENOVKY — tisk na A4 samolepící archy
// =============================================================
// VŠECHNY formáty jsou pro A4 papír (210 × 297 mm) — různá rozložení nálepek na A4 archu.
// Seřazeno od velkých štítků (málo/arch) po malé (hodně/arch).
// Pokrývá: printky.cz, SEVT, Avery (L7xxx + J7xxx), Herma, Rayfilm — nejběžnější market formáty.
// ID zachována zpětně kompatibilní (pk-* / se-* / av-*) kvůli již uloženým šablonám.
const STITKY_FORMATY = [
  // ───────── ⭐ Appek vlastní (sklad od půlky) ─────────
  { id: 'rp-cenovka-6', popis: '★ A4 · Appek cenovka — 70×148 mm sklad od půlky (6/arch, 3×2)', cols: 3, rows: 2, w: 70,  h: 148.5, mTop: 0, mLeft: 0, gapX: 0, gapY: 0, foldHalf: true },
  { id: 'rp-cenovka-8', popis: '★ A4 · Appek cenovka — 105×74 mm sklad od půlky (8/arch, 2×4)', cols: 2, rows: 4, w: 105, h: 74.25, mTop: 0, mLeft: 0, gapX: 0, gapY: 0, foldHalf: true },

  // ───────── 🔲 Velké formáty (1–4 / arch) — plakátky, regálové cenovky ─────────
  { id: 'pk-210x297-1',  popis: 'A4 · Celý arch 210×297 mm (1/arch) — Printky',                   cols: 1, rows: 1,  w: 210,  h: 297,  mTop: 0,    mLeft: 0,    gapX: 0, gapY: 0 },
  { id: 'se-210x148-2',  popis: 'A4 · Půl archu 210×148.5 mm (2/arch) — SEVT / Avery L7168',      cols: 1, rows: 2,  w: 210,  h: 148.5,mTop: 0,    mLeft: 0,    gapX: 0, gapY: 0 },
  { id: 'pk-105x297-2',  popis: 'A4 · 105×297 mm (2/arch vertikálně) — Printky',                   cols: 2, rows: 1,  w: 105,  h: 297,  mTop: 0,    mLeft: 0,    gapX: 0, gapY: 0 },
  { id: 'pk-105x148-4',  popis: 'A4 · 105×148 mm — čtvrt-A6 (4/arch, 2×2) — Printky / Herma 4630', cols: 2, rows: 2,  w: 105,  h: 148.5,mTop: 0,    mLeft: 0,    gapX: 0, gapY: 0 },
  { id: 'a4-99x139-4',   popis: 'A4 · 99.1×139 mm (4/arch, 2×2) — Avery L7169',                    cols: 2, rows: 2,  w: 99.1, h: 139,  mTop: 8.5,  mLeft: 4.65, gapX: 4, gapY: 0 },
  { id: 'pk-70x148-6',   popis: 'A4 · 70×148.5 mm (6/arch, 3×2) — Printky',                        cols: 3, rows: 2,  w: 70,   h: 148.5,mTop: 0,    mLeft: 0,    gapX: 0, gapY: 0 },
  { id: 'a4-99x93-6',    popis: 'A4 · 99.1×93.1 mm (6/arch, 2×3) — Avery L7166',                   cols: 2, rows: 3,  w: 99.1, h: 93.1, mTop: 9.0,  mLeft: 4.65, gapX: 4, gapY: 0 },

  // ───────── 📋 Střední formáty (8–24 / arch) — nejběžnější cenovky a štítky ─────────
  { id: 'pk-105x74-8',   popis: 'A4 · 105×74 mm — A7 (8/arch, 2×4) — Printky',                     cols: 2, rows: 4,  w: 105,  h: 74.25,mTop: 0,    mLeft: 0,    gapX: 0, gapY: 0 },
  { id: 'av-99x67-8',    popis: 'A4 · 99.1×67.7 mm (8/arch, 2×4) — Avery L7165 / J8165',           cols: 2, rows: 4,  w: 99.1, h: 67.7, mTop: 13,   mLeft: 4,    gapX: 4,   gapY: 0 },
  { id: 'se-105x57-10',  popis: 'A4 · 105×57 mm (10/arch, 2×5) — SEVT',                            cols: 2, rows: 5,  w: 105,  h: 57,   mTop: 6,    mLeft: 0,    gapX: 0, gapY: 0 },
  { id: 'a4-99x57-10',   popis: 'A4 · 99.1×57 mm (10/arch, 2×5) — Avery L7173',                    cols: 2, rows: 5,  w: 99.1, h: 57,   mTop: 6.5,  mLeft: 4.65, gapX: 4, gapY: 0 },
  { id: 'a4-96x51-10',   popis: 'A4 · 96×50.8 mm (10/arch, 2×5) — Herma 4452',                     cols: 2, rows: 5,  w: 96,   h: 50.8, mTop: 21.5, mLeft: 9,    gapX: 2, gapY: 0 },
  { id: 'pk-100x58-10',  popis: 'A4 · 100×58 mm (10/arch, 2×5) — Printky',                         cols: 2, rows: 5,  w: 100,  h: 58,   mTop: 3.5,  mLeft: 5,    gapX: 0, gapY: 0 },
  { id: 'a4-105x49-12',  popis: 'A4 · 105×49.5 mm (12/arch, 2×6) — Rayfilm',                       cols: 2, rows: 6,  w: 105,  h: 49.5, mTop: 0,    mLeft: 0,    gapX: 0, gapY: 0 },
  { id: 'a4-99x42-12',   popis: 'A4 · 99.1×42.3 mm (12/arch, 2×6) — Avery L7164 / Herma 4622',     cols: 2, rows: 6,  w: 99.1, h: 42.3, mTop: 23.5, mLeft: 4.65, gapX: 4, gapY: 0 },
  { id: 'se-105x42-14',  popis: 'A4 · 105×42.3 mm (14/arch, 2×7) — SEVT',                          cols: 2, rows: 7,  w: 105,  h: 42.3, mTop: 0.5,  mLeft: 0,    gapX: 0, gapY: 0 },
  { id: 'a4-99x38-14',   popis: 'A4 · 99.1×38.1 mm (14/arch, 2×7) — Avery L7163',                  cols: 2, rows: 7,  w: 99.1, h: 38.1, mTop: 8.5,  mLeft: 4.65, gapX: 4, gapY: 0 },
  { id: 'se-105x37-16',  popis: 'A4 · 105×37 mm (16/arch, 2×8) — SEVT',                            cols: 2, rows: 8,  w: 105,  h: 37,   mTop: 0.5,  mLeft: 0,    gapX: 0, gapY: 0 },
  { id: 'a4-99x34-16',   popis: 'A4 · 99.1×33.9 mm (16/arch, 2×8) — Avery L7175',                  cols: 2, rows: 8,  w: 99.1, h: 33.9, mTop: 13.5, mLeft: 4.65, gapX: 4, gapY: 0 },
  { id: 'se-63x46-18',   popis: 'A4 · 63.5×46.6 mm (18/arch, 3×6) — SEVT',                         cols: 3, rows: 6,  w: 63.5, h: 46.6, mTop: 8.7,  mLeft: 9.75, gapX: 0, gapY: 0 },
  { id: 'a4-64x72-12',   popis: 'A4 · 63.5×72 mm (12/arch, 3×4) — Avery L7164a',                   cols: 3, rows: 4,  w: 63.5, h: 72,   mTop: 9,    mLeft: 7,    gapX: 2.5, gapY: 0 },
  { id: 'av-70x42-21',   popis: 'A4 · 70×42.4 mm (21/arch, 3×7) — Avery L7160 / Herma 4453',       cols: 3, rows: 7,  w: 70,   h: 42.4, mTop: 0,    mLeft: 0,    gapX: 0,   gapY: 0 },
  { id: 'av-63x38-21',   popis: 'A4 · 63.5×38.1 mm (21/arch, 3×7) — Avery L7159 / J7159',          cols: 3, rows: 7,  w: 63.5, h: 38.1, mTop: 15.1, mLeft: 7,    gapX: 2.5, gapY: 0 },
  { id: 'av-70x36-24',   popis: 'A4 · 70×36 mm (24/arch, 3×8) — Avery L7159b',                     cols: 3, rows: 8,  w: 70,   h: 36,   mTop: 4.5,  mLeft: 0,    gapX: 0,   gapY: 0 },
  { id: 'a4-64x34-24',   popis: 'A4 · 63.5×33.9 mm (24/arch, 3×8) — Avery L7159a',                 cols: 3, rows: 8,  w: 63.5, h: 33.9, mTop: 13.5, mLeft: 7,    gapX: 2.5, gapY: 0 },

  // ───────── 🏷️ Malé formáty (30+ / arch) — EAN, kódy, malé štítky ─────────
  { id: 'av-70x25-33',   popis: 'A4 · 70×25.4 mm (33/arch, 3×11) — Avery L7156',                   cols: 3, rows: 11, w: 70,   h: 25.4, mTop: 21.5, mLeft: 0,    gapX: 0,   gapY: 0 },
  { id: 'se-52x29-40',   popis: 'A4 · 52.5×29.7 mm (40/arch, 4×10) — SEVT',                        cols: 4, rows: 10, w: 52.5, h: 29.7, mTop: 0,    mLeft: 0,    gapX: 0, gapY: 0 },
  { id: 'pk-66x21-42',   popis: 'A4 · 66×21 mm (42/arch, 3×14) — Printky',                         cols: 3, rows: 14, w: 66,   h: 21,   mTop: 1.5,  mLeft: 6,    gapX: 0, gapY: 0 },
  { id: 'pk-52x25-44',   popis: 'A4 · 52×25.4 mm (44/arch, 4×11) — Printky',                       cols: 4, rows: 11, w: 52,   h: 25.4, mTop: 8.8,  mLeft: 1,    gapX: 0, gapY: 0 },
  { id: 'se-52x21-52',   popis: 'A4 · 52.5×21.2 mm (52/arch, 4×13) — SEVT',                        cols: 4, rows: 13, w: 52.5, h: 21.2, mTop: 10.7, mLeft: 0,    gapX: 0, gapY: 0 },
  { id: 'pk-24x33-64',   popis: 'A4 · 24×33 mm (64/arch, 8×8) — Printky',                          cols: 8, rows: 8,  w: 24,   h: 33,   mTop: 16.5, mLeft: 9,    gapX: 0, gapY: 0 },
  { id: 'pk-38x21-65',   popis: 'A4 · 38.1×21.2 mm (65/arch, 5×13) — Avery L7651 / J7651',         cols: 5, rows: 13, w: 38.1, h: 21.2, mTop: 10.7, mLeft: 9.75, gapX: 0, gapY: 0 },
  { id: 'pk-40x18-75',   popis: 'A4 · 40×18 mm (75/arch, 5×15) — Printky',                         cols: 5, rows: 15, w: 40,   h: 18,   mTop: 13.5, mLeft: 5,    gapX: 0, gapY: 0 },
  { id: 'a4-46x11-84',   popis: 'A4 · 46×11.1 mm (84/arch, 4×21) — Avery L7656',                   cols: 4, rows: 21, w: 46,   h: 11.1, mTop: 21.5, mLeft: 9,    gapX: 2, gapY: 0 },
  { id: 'pk-25x10-189',  popis: 'A4 · 25.4×10 mm (189/arch, 7×27) — Printky',                      cols: 7, rows: 27, w: 25.4, h: 10,   mTop: 13.5, mLeft: 16.1, gapX: 0, gapY: 0 },

  // ───────── ⚙️ Vlastní rozměr ─────────
  { id: 'custom',   popis: '— vlastní rozměr (zadám si sám) —', custom: true },
];

// =============================================================
// 🎨 DESIGN PRESETY — vestavěná knihovna 15 univerzálních cenovek
// Každý design používá prvky v % (pozice + velikost) a font v pt.
// Při renderování pro zvolený formát se font automaticky přepočítává podle scaleFactoru:
//   scale = min(currentW / refW, currentH / refH)
// Tím se 15 designů zobrazí na JAKÉMKOLIV formátu hezky a ready-to-print.
// =============================================================
const DESIGN_REF_W = 70;   // referenční šířka štítku (mm)
const DESIGN_REF_H = 42;   // referenční výška štítku (mm)

// Designy navržené tak, aby fontSize VŽDY menší než výška boxu — žádný overflow.
// Modern trend: generous whitespace, výrazný kontrast (velká cena vs malé doplňky), čisté linie.
const DESIGN_PRESETS = [
  // 1. Hero — Velká cena dominantní
  {
    id: 'd-hero', nazev: '★ Hero (velká cena)', popis: 'Cena dominuje, název nahoře',
    prvky: [
      { typ: 'nazev',    x_pct: 4,  y_pct: 4,  w_pct: 92, h_pct: 22, fontSize: 11, fontWeight: 700, align: 'center' },
      { typ: 'cena',     x_pct: 4,  y_pct: 28, w_pct: 92, h_pct: 44, fontSize: 32, fontWeight: 900, color: '#854F0B', align: 'center' },
      { typ: 'hmotnost', x_pct: 4,  y_pct: 76, w_pct: 50, h_pct: 10, fontSize: 7,  color: '#777', align: 'left' },
      { typ: 'kod',      x_pct: 56, y_pct: 76, w_pct: 40, h_pct: 10, fontSize: 6,  color: '#888', align: 'right' },
      { typ: 'ean',      x_pct: 4,  y_pct: 88, w_pct: 92, h_pct: 10, fontSize: 7,  align: 'center' },
    ],
  },
  // 2. Klasik — Vyvážené rozložení
  {
    id: 'd-klasik', nazev: '★ Klasik', popis: 'Vyvážené, vlevo zarovnané',
    prvky: [
      { typ: 'nazev',    x_pct: 4,  y_pct: 4,  w_pct: 92, h_pct: 22, fontSize: 11, fontWeight: 700, align: 'left' },
      { typ: 'hmotnost', x_pct: 4,  y_pct: 28, w_pct: 50, h_pct: 10, fontSize: 7,  color: '#777', align: 'left' },
      { typ: 'kod',      x_pct: 56, y_pct: 28, w_pct: 40, h_pct: 10, fontSize: 6,  color: '#888', align: 'right' },
      { typ: 'cena',     x_pct: 4,  y_pct: 42, w_pct: 60, h_pct: 38, fontSize: 26, fontWeight: 900, color: '#222', align: 'left' },
      { typ: 'cenakg',   x_pct: 4,  y_pct: 82, w_pct: 60, h_pct: 8,  fontSize: 6,  color: '#888' },
      { typ: 'ean',      x_pct: 66, y_pct: 46, w_pct: 32, h_pct: 42, fontSize: 6 },
    ],
  },
  // 3. Minimalist — Jen 2 prvky
  {
    id: 'd-minimalist', nazev: '★ Minimalist', popis: 'Pouze název + cena',
    prvky: [
      { typ: 'nazev', x_pct: 4, y_pct: 16, w_pct: 92, h_pct: 28, fontSize: 12, fontWeight: 600, align: 'center' },
      { typ: 'cena',  x_pct: 4, y_pct: 50, w_pct: 92, h_pct: 38, fontSize: 30, fontWeight: 900, color: '#1a1a1a', align: 'center' },
    ],
  },
  // 4. Center — Vše centrované
  {
    id: 'd-center', nazev: '★ Center', popis: 'Vše na ose, čistý styl',
    prvky: [
      { typ: 'nazev',    x_pct: 4, y_pct: 4,  w_pct: 92, h_pct: 18, fontSize: 11, fontWeight: 700, align: 'center' },
      { typ: 'hmotnost', x_pct: 4, y_pct: 24, w_pct: 92, h_pct: 8,  fontSize: 6,  color: '#888', align: 'center' },
      { typ: 'cena',     x_pct: 4, y_pct: 36, w_pct: 92, h_pct: 38, fontSize: 28, fontWeight: 900, color: '#854F0B', align: 'center' },
      { typ: 'cenakg',   x_pct: 4, y_pct: 76, w_pct: 92, h_pct: 8,  fontSize: 6,  color: '#888', align: 'center' },
      { typ: 'ean',      x_pct: 4, y_pct: 88, w_pct: 92, h_pct: 10, fontSize: 7,  align: 'center' },
    ],
  },
  // 5. Banner — Barevný proužek nahoře
  {
    id: 'd-banner', nazev: '★ Banner', popis: 'Barevný pruh s názvem',
    prvky: [
      { typ: 'nazev',    x_pct: 0,  y_pct: 0,  w_pct: 100, h_pct: 22, fontSize: 10, fontWeight: 700, color: '#fff', bg: '#854F0B', align: 'center', padding: 1 },
      { typ: 'cena',     x_pct: 4,  y_pct: 28, w_pct: 92, h_pct: 42, fontSize: 28, fontWeight: 900, color: '#1a1a1a', align: 'center' },
      { typ: 'hmotnost', x_pct: 4,  y_pct: 74, w_pct: 50, h_pct: 10, fontSize: 7,  color: '#666' },
      { typ: 'kod',      x_pct: 56, y_pct: 74, w_pct: 40, h_pct: 10, fontSize: 6,  color: '#888', align: 'right' },
      { typ: 'ean',      x_pct: 4,  y_pct: 88, w_pct: 92, h_pct: 10, fontSize: 7,  align: 'center' },
    ],
  },
  // 6. Eco — Pro BIO výrobky (zelená)
  {
    id: 'd-eco', nazev: '★ Eco (přírodní)', popis: 'Zelená, vhodné pro BIO',
    prvky: [
      { typ: 'nazev',    x_pct: 4,  y_pct: 4,  w_pct: 92, h_pct: 18, fontSize: 11, fontWeight: 700, color: '#15803d', align: 'center' },
      { typ: 'slozeni',  x_pct: 4,  y_pct: 24, w_pct: 92, h_pct: 20, fontSize: 6,  color: '#666', align: 'center' },
      { typ: 'cena',     x_pct: 4,  y_pct: 46, w_pct: 92, h_pct: 32, fontSize: 26, fontWeight: 900, color: '#15803d', align: 'center' },
      { typ: 'alergeny', x_pct: 4,  y_pct: 80, w_pct: 92, h_pct: 8,  fontSize: 6,  color: '#92400e', align: 'center' },
      { typ: 'ean',      x_pct: 4,  y_pct: 90, w_pct: 92, h_pct: 8,  fontSize: 6, align: 'center' },
    ],
  },
  // 7. Akce — Výrazný proužek "AKCE"
  {
    id: 'd-akce', nazev: '★ Akce (výrazná)', popis: 'Pro slevy, akce',
    prvky: [
      { typ: 'badge',    x_pct: 0,  y_pct: 0,  w_pct: 100, h_pct: 14, fontSize: 9,  fontWeight: 800, color: '#fff', bg: '#dc2626', align: 'center', text: '🔥 AKCE' },
      { typ: 'nazev',    x_pct: 4,  y_pct: 18, w_pct: 92, h_pct: 18, fontSize: 11, fontWeight: 700, align: 'center' },
      { typ: 'cena',     x_pct: 4,  y_pct: 40, w_pct: 92, h_pct: 38, fontSize: 28, fontWeight: 900, color: '#dc2626', align: 'center' },
      { typ: 'hmotnost', x_pct: 4,  y_pct: 80, w_pct: 50, h_pct: 8,  fontSize: 6,  color: '#666' },
      { typ: 'ean',      x_pct: 54, y_pct: 80, w_pct: 42, h_pct: 18, fontSize: 6,  align: 'right' },
    ],
  },
  // 8. Premium — Tmavý pruh nahoře
  {
    id: 'd-premium', nazev: '★ Premium', popis: 'Tmavý pruh, elegantní',
    prvky: [
      { typ: 'nazev', x_pct: 0,  y_pct: 0,  w_pct: 100, h_pct: 24, fontSize: 10, fontWeight: 700, color: '#fff', bg: '#1a1a1a', align: 'center', padding: 1 },
      { typ: 'cena',  x_pct: 4,  y_pct: 30, w_pct: 92, h_pct: 42, fontSize: 28, fontWeight: 900, color: '#854F0B', align: 'center' },
      { typ: 'cenakg', x_pct: 4, y_pct: 76, w_pct: 50, h_pct: 10, fontSize: 6, color: '#666' },
      { typ: 'kod',    x_pct: 56, y_pct: 76, w_pct: 40, h_pct: 10, fontSize: 6, color: '#888', align: 'right' },
      { typ: 'ean',    x_pct: 4, y_pct: 88, w_pct: 92, h_pct: 10, fontSize: 7, align: 'center' },
    ],
  },
  // 9. Cena za kg — Důraz na Kč/kg
  {
    id: 'd-perkg', nazev: '★ Cena za kg', popis: 'Důraz na cenu/kg',
    prvky: [
      { typ: 'nazev',    x_pct: 4, y_pct: 4,  w_pct: 92, h_pct: 18, fontSize: 11, fontWeight: 700, align: 'left' },
      { typ: 'hmotnost', x_pct: 4, y_pct: 24, w_pct: 50, h_pct: 8,  fontSize: 7,  color: '#666' },
      { typ: 'cena',     x_pct: 4, y_pct: 36, w_pct: 60, h_pct: 28, fontSize: 22, fontWeight: 900, color: '#222', align: 'left' },
      { typ: 'cenakg',   x_pct: 4, y_pct: 68, w_pct: 92, h_pct: 16, fontSize: 11, fontWeight: 700, color: '#854F0B', bg: '#FFF8E5', align: 'center', padding: 1 },
      { typ: 'ean',      x_pct: 4, y_pct: 88, w_pct: 92, h_pct: 10, fontSize: 7, align: 'center' },
    ],
  },
  // 10. Se složením — Složení + alergeny dominantně
  {
    id: 'd-slozeni', nazev: '★ Se složením', popis: 'Složení + alergeny + cena',
    prvky: [
      { typ: 'nazev',    x_pct: 4, y_pct: 2,  w_pct: 92, h_pct: 16, fontSize: 11, fontWeight: 700, align: 'left' },
      { typ: 'slozeni',  x_pct: 4, y_pct: 20, w_pct: 92, h_pct: 22, fontSize: 6,  color: '#666' },
      { typ: 'alergeny', x_pct: 4, y_pct: 44, w_pct: 92, h_pct: 10, fontSize: 6,  color: '#92400e', fontWeight: 700 },
      { typ: 'cena',     x_pct: 4, y_pct: 58, w_pct: 60, h_pct: 30, fontSize: 22, fontWeight: 900, color: '#854F0B', align: 'left' },
      { typ: 'ean',      x_pct: 66, y_pct: 60, w_pct: 32, h_pct: 28, fontSize: 6, align: 'right' },
    ],
  },
  // 11. Kompakt — Pro malé štítky
  {
    id: 'd-kompakt', nazev: '★ Kompakt', popis: 'Pro malé štítky',
    prvky: [
      { typ: 'nazev', x_pct: 2, y_pct: 4,  w_pct: 96, h_pct: 32, fontSize: 10, fontWeight: 700, align: 'center' },
      { typ: 'cena',  x_pct: 2, y_pct: 42, w_pct: 65, h_pct: 46, fontSize: 22, fontWeight: 900, color: '#854F0B', align: 'left' },
      { typ: 'kod',   x_pct: 68, y_pct: 46, w_pct: 30, h_pct: 20, fontSize: 6,  color: '#888', align: 'right' },
      { typ: 'ean',   x_pct: 68, y_pct: 68, w_pct: 30, h_pct: 28, fontSize: 5,  align: 'right' },
    ],
  },
  // 12. Pure — 3 prvky
  {
    id: 'd-pure', nazev: '★ Pure', popis: 'Pouze název, cena, EAN',
    prvky: [
      { typ: 'nazev', x_pct: 4, y_pct: 8,  w_pct: 92, h_pct: 24, fontSize: 12, fontWeight: 700, align: 'center' },
      { typ: 'cena',  x_pct: 4, y_pct: 38, w_pct: 92, h_pct: 38, fontSize: 30, fontWeight: 900, color: '#1a1a1a', align: 'center' },
      { typ: 'ean',   x_pct: 4, y_pct: 82, w_pct: 92, h_pct: 14, fontSize: 8, align: 'center' },
    ],
  },
  // 13. EAN-velký — Velký čárový kód
  {
    id: 'd-ean', nazev: '★ EAN-velký', popis: 'Velký čárový kód',
    prvky: [
      { typ: 'nazev', x_pct: 4, y_pct: 2,  w_pct: 92, h_pct: 16, fontSize: 10, fontWeight: 700, align: 'center' },
      { typ: 'cena',  x_pct: 4, y_pct: 20, w_pct: 92, h_pct: 24, fontSize: 18, fontWeight: 900, color: '#854F0B', align: 'center' },
      { typ: 'ean',   x_pct: 4, y_pct: 48, w_pct: 92, h_pct: 42, fontSize: 10, align: 'center' },
      { typ: 'kod',   x_pct: 4, y_pct: 92, w_pct: 92, h_pct: 6,  fontSize: 6, color: '#888', align: 'center' },
    ],
  },
  // 14. Split — Levá popis, pravá cena
  {
    id: 'd-split', nazev: '★ Split (levá/pravá)', popis: 'Vlevo info, vpravo cena',
    prvky: [
      { typ: 'nazev',    x_pct: 4,  y_pct: 4,  w_pct: 56, h_pct: 34, fontSize: 12, fontWeight: 700, align: 'left' },
      { typ: 'hmotnost', x_pct: 4,  y_pct: 42, w_pct: 56, h_pct: 10, fontSize: 7,  color: '#666' },
      { typ: 'kod',      x_pct: 4,  y_pct: 56, w_pct: 56, h_pct: 8,  fontSize: 6,  color: '#888' },
      { typ: 'ean',      x_pct: 4,  y_pct: 68, w_pct: 56, h_pct: 28, fontSize: 6,  align: 'left' },
      { typ: 'cena',     x_pct: 62, y_pct: 14, w_pct: 36, h_pct: 72, fontSize: 22, fontWeight: 900, color: '#854F0B', align: 'center' },
    ],
  },
  // 15. Doporučujeme — Zlatý badge
  {
    id: 'd-doporuc', nazev: '★ Doporučujeme', popis: 'Se zlatým badge',
    prvky: [
      { typ: 'badge',    x_pct: 0,  y_pct: 0,  w_pct: 100, h_pct: 14, fontSize: 9,  fontWeight: 800, color: '#fff', bg: '#BA7517', align: 'center', text: '⭐ DOPORUČUJEME' },
      { typ: 'nazev',    x_pct: 4,  y_pct: 18, w_pct: 92, h_pct: 18, fontSize: 11, fontWeight: 700, align: 'center' },
      { typ: 'cena',     x_pct: 4,  y_pct: 40, w_pct: 92, h_pct: 38, fontSize: 26, fontWeight: 900, color: '#854F0B', align: 'center' },
      { typ: 'hmotnost', x_pct: 4,  y_pct: 80, w_pct: 50, h_pct: 8,  fontSize: 7, color: '#666' },
      { typ: 'ean',      x_pct: 54, y_pct: 80, w_pct: 42, h_pct: 18, fontSize: 6, align: 'right' },
    ],
  },
];

// 🔧 Helper — auto-scale design pro daný formát
// Vrací nové prvky s upravenými fontSize podle poměru stran (refW×refH → currentW×currentH).
// Pozice (x_pct, y_pct, w_pct, h_pct) zůstávají stejné — to jsou %.
// foldHalf=true → Appek formát se sklady; data pouze do spodní poloviny (horní polka je zadní strana).
function designScaledPrvky(designId, currentW_mm, currentH_mm, foldHalf = false) {
  const design = DESIGN_PRESETS.find(d => d.id === designId);
  if (!design) return null;
  // Pro foldHalf je užitečná jen spodní polovina (horní = zadní strana po přeložení)
  const effectiveH = foldHalf ? currentH_mm / 2 : currentH_mm;
  // Použij menší poměr (aby text nepřesahoval menší rozměr)
  const scale = Math.min(currentW_mm / DESIGN_REF_W, effectiveH / DESIGN_REF_H);
  // Sloup pro extrémně malé/velké štítky, ať to nikdy nevypadá nečitelně
  const fontScale = Math.max(0.35, Math.min(3.5, scale));
  return design.prvky.map(p => {
    const out = {
      ...p,
      fontSize: Math.max(4, Math.min(120, (parseFloat(p.fontSize) || 8) * fontScale)),
    };
    // 📐 Foldové formáty: pře-mapuj y do spodní poloviny (0-100% → 50-100%) a smrskni výšku
    if (foldHalf) {
      out.y_pct = 50 + (parseFloat(p.y_pct) || 0) * 0.5;
      out.h_pct = (parseFloat(p.h_pct) || 0) * 0.5;
    }
    return out;
  });
}

// 🎨 Vrátí "virtuální sablonu" z designu — kompatibilní s stitekHtmlSablona()
function designAsSablona(designId, currentW_mm, currentH_mm, foldHalf = false) {
  const design = DESIGN_PRESETS.find(d => d.id === designId);
  if (!design) return null;
  return {
    id: `design:${designId}`,
    nazev: design.nazev,
    _foldHalf: foldHalf,    // 📐 vlajka pro mini-preview (zobrazí přerušovanou linku přeložení)
    layout: {
      prvky: designScaledPrvky(designId, currentW_mm, currentH_mm, foldHalf),
      sirka_mm: currentW_mm,
      vyska_mm: currentH_mm,
      _foldHalf: foldHalf,
    },
  };
}

// 🔍 Helper: zjistí jestli aktuální formát je sklad od půlky (Appek cenovka)
function stIsFoldHalfFormat(formatId, customSettings) {
  const fmt = formatId === 'custom' ? customSettings : (STITKY_FORMATY.find(f => f.id === formatId) || STITKY_FORMATY[0]);
  return !!(fmt && fmt.foldHalf);
}

const stState = {
  rezim: 'cenovky',            // 'expedicni' | 'cenovky' | 'moje' | 'editor' — výchozí cenovky
  designId: null,              // 🎨 ID vybraného built-in designu (auto-fit), null = bez designu
  formatId: 'pk-105x148-4',
  custom: { cols: 3, rows: 7, w: 63.5, h: 38.1, mTop: 15.1, mLeft: 7, gapX: 2.5, gapY: 0 },
  startPos: 1,                 // 1-based, kolikátá buňka začne
  // Expediční:
  od: null,
  dto: null,
  vybraneObj: new Set(),
  obj: [],                     // načtené objednávky pro výběr
  kopie: 1,                    // počet kopií per objednávka (lze přepsat per řádek)
  vlozitQr: true,              // QR kód na expedičním štítku
  gridZoom: 1,                 // 🔍 zoom mřížky (50%–300%)
  // Cenovky:
  vyrobky: [],                 // načtené výrobky
  vybraneVyr: new Set(),
  cenovkaPocet: 1,
  vlozitEan: true,             // čárový kód EAN-13 na cenovce
  // Moje štítky (vlastní):
  mojeStitky: [],
  vybraneMoje: new Set(),
  mojeQ: '',
  // Šablony (z editoru) — když je vybraná, přebije výchozí layout cenovky
  sablonaId: null,
  sablony: [],
  // Konfigurace polí na cenovce (uživatel volí, co se vytiskne)
  cenovkaPole: {
    nazev: true,
    popis: false,
    slozeni: false,
    alergeny: false,
    hmotnost: true,
    cena: true,
    cenaKg: true,
    cislo: false,
    ean: true,
  },
  cenovkaBadge: '',            // 'novinka' | 'akce' | 'doporuc' | '' (žádný)
  cenovkaBadgeText: '',        // vlastní text místo presetů
  // 🆕 v2.0.77 — Per-product quantity override (pro chip row v cenovkách)
  // Pokud vyrobekId v této mapě nemá hodnotu, použije se cenovkaPocet (default).
  poctyPerVyrobek: {},         // { [vyrobekId]: pocet }
};

// 🆕 v2.0.77 — Helper funkce pro chip system
function stGetPocetVyrobek(vId) {
  const id = parseInt(vId);
  return (stState.poctyPerVyrobek && stState.poctyPerVyrobek[id])
    ? parseInt(stState.poctyPerVyrobek[id])
    : (parseInt(stState.cenovkaPocet) || 1);
}

function stTotalKopiiAll() {
  let total = 0;
  if (!stState.vybraneVyr) return 0;
  for (const vId of stState.vybraneVyr) {
    total += stGetPocetVyrobek(vId);
  }
  return total;
}

window.stChipSetPocet = function(vId, value) {
  const id = parseInt(vId);
  const n = Math.max(1, Math.min(999, parseInt(value) || 1));
  if (!stState.poctyPerVyrobek) stState.poctyPerVyrobek = {};
  stState.poctyPerVyrobek[id] = n;
  if (typeof renderStitky === 'function') renderStitky();
};

window.stChipChangePocet = function(vId, delta) {
  const cur = stGetPocetVyrobek(vId);
  window.stChipSetPocet(vId, cur + delta);
};

window.stChipRemove = function(vId) {
  const id = parseInt(vId);
  if (stState.vybraneVyr) stState.vybraneVyr.delete(id);
  if (stState.poctyPerVyrobek) delete stState.poctyPerVyrobek[id];
  if (typeof renderStitky === 'function') renderStitky();
};

window.stVymazatVse = async function() {
  if (!stState.vybraneVyr || stState.vybraneVyr.size === 0) return;
  if (!(await confirmDialog({ msg: t('confirm_clear_selected_products', { n: stState.vybraneVyr.size }), danger: false }))) return;
  stState.vybraneVyr.clear();
  stState.poctyPerVyrobek = {};
  if (typeof renderStitky === 'function') renderStitky();
};

// EAN-13 generátor (vrací SVG string)
function ean13ToSvg(ean, width = 50, height = 18) {
  let s = String(ean || '').replace(/\D/g, '');
  if (s.length === 12) s += ean13Checksum(s);
  if (s.length !== 13) return '';
  if (!/^\d{13}$/.test(s)) return '';
  // Patterns: L (left odd), G (left even), R (right)
  const L = ['0001101','0011001','0010011','0111101','0100011','0110001','0101111','0111011','0110111','0001011'];
  const G = ['0100111','0110011','0011011','0100001','0011101','0111001','0000101','0010001','0001001','0010111'];
  const R = ['1110010','1100110','1101100','1000010','1011100','1001110','1010000','1000100','1001000','1110100'];
  // Parita levé poloviny dle prvního digitu
  const parity = ['LLLLLL','LLGLGG','LLGGLG','LLGGGL','LGLLGG','LGGLLG','LGGGLL','LGLGLG','LGLGGL','LGGLGL'];
  const first = parseInt(s[0], 10);
  const par = parity[first];

  let bin = '101'; // start guard
  for (let i = 0; i < 6; i++) {
    const d = parseInt(s[1 + i], 10);
    bin += par[i] === 'L' ? L[d] : G[d];
  }
  bin += '01010'; // center guard
  for (let i = 0; i < 6; i++) {
    const d = parseInt(s[7 + i], 10);
    bin += R[d];
  }
  bin += '101'; // end guard

  // 95 modulů + text dole
  const modules = bin.length;
  const moduleW = width / modules;
  const barH = height - 5; // místo na text dole
  let bars = '';
  for (let i = 0; i < modules; i++) {
    if (bin[i] === '1') {
      bars += `<rect x="${(i * moduleW).toFixed(3)}" y="0" width="${moduleW.toFixed(3)}" height="${barH}" fill="#000"/>`;
    }
  }
  // Text: 1 | 6 cifer | 6 cifer
  const textY = height - 0.5;
  const fontSize = Math.min(4, height * 0.28);
  const tx = (col) => (col * moduleW).toFixed(2);
  return `
    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 ${width} ${height}" width="${width}mm" height="${height}mm" style="background:#fff">
      ${bars}
      <text x="${tx(-1)}" y="${textY}" font-family="monospace" font-size="${fontSize}" fill="#000">${s[0]}</text>
      <text x="${tx(13)}" y="${textY}" font-family="monospace" font-size="${fontSize}" fill="#000">${s.substr(1, 6).split('').join(' ')}</text>
      <text x="${tx(53)}" y="${textY}" font-family="monospace" font-size="${fontSize}" fill="#000">${s.substr(7, 6).split('').join(' ')}</text>
    </svg>
  `;
}

function ean13Checksum(first12) {
  let sum = 0;
  for (let i = 0; i < 12; i++) {
    sum += parseInt(first12[i], 10) * (i % 2 === 0 ? 1 : 3);
  }
  return ((10 - (sum % 10)) % 10).toString();
}

async function renderStitky() {
  const c = document.getElementById('content');
  const s = stState;

  // Default datum (dnes a zítra)
  if (!s.od) {
    const d = new Date();
    s.od = d.toISOString().split('T')[0];
  }
  if (!s.dto) {
    const d = new Date();
    d.setDate(d.getDate() + 1);
    s.dto = d.toISOString().split('T')[0];
  }

  // Načti data podle režimu
  if (s.rezim === 'expedicni') {
    try {
      // 🐛 v3.0.220 — admin_objednavky vrací od v3.0.218 envelope {objednavky,...} (ne pole);
      //   unwrap + limit 200 (plánovač chce všechny v rozsahu, ne jen 1. stránku 50).
      const params = new URLSearchParams({ datum_od: s.od, datum_do: s.dto, limit: 200 }).toString();
      const _r = await api('admin_objednavky.php?' + params);
      s.obj = Array.isArray(_r) ? _r : (_r.objednavky || []);
    } catch (e) { s.obj = []; }
  } else if (s.rezim === 'cenovky') {
    // 📦 Vždy fetchni produkty z DB (i když máme cache) — pokud cache prázdná, NEBO timestamp > 60s starý
    const now = Date.now();
    if (s.vyrobky.length === 0 || !s._vyrobkyFetchedAt || (now - s._vyrobkyFetchedAt) > 60000) {
      try {
        const r = await api('admin_vyrobky.php');
        s.vyrobky = (r && Array.isArray(r.vyrobky)) ? r.vyrobky : (Array.isArray(r) ? r : []);
        s._vyrobkyFetchedAt = now;
      } catch (e) { s.vyrobky = []; }
    }
  } else if (s.rezim === 'moje') {
    try {
      s.mojeStitky = await api('admin_moje_stitky.php');
    } catch (e) { s.mojeStitky = []; }
  }
  // Šablony pro výběr v cenovkách / mojich
  if ((s.rezim === 'cenovky' || s.rezim === 'moje') && s.sablony.length === 0) {
    try {
      s.sablony = await api('admin_stitky_sablony.php');
    } catch (e) { s.sablony = []; }
  }

  const fmt = STITKY_FORMATY.find(f => f.id === s.formatId) || STITKY_FORMATY[0];

  // Editor má vlastní layout (canvas + paleta) — vykreslí se přes výchozí strukturu
  if (s.rezim === 'editor') {
    return renderStitkyEditor();
  }

  c.innerHTML = `
    <div class="page-head">
      <div>
        <h1 class="page-title">🏷️ Štítky a cenovky</h1>
        <p class="page-sub">Tisk na A4 samolepicí archy — pro expedici i prodejnu</p>
      </div>
      <div style="display:flex;gap:8px;flex-wrap:wrap">
        <button class="btn-secondary" onclick="navigate('nastroje')">← Nástroje</button>
        <button class="btn-secondary" onclick="stitkyTisknout(true)">👁️ Náhled</button>
        <button class="btn-primary btn-green" onclick="stitkyTisknout(false)">🖨️ Tisk</button>
      </div>
    </div>

    <div class="card-block" style="padding:16px 18px;margin-bottom:14px">
      <!-- Hlavní režimy vlevo, Expediční štítky (samostatná funkčnost) vpravo s vizuálním oddělením -->
      <div class="stitky-rezim-tabs" style="display:flex;gap:8px;margin-bottom:14px;flex-wrap:wrap;align-items:center">
        <button class="period-tab ${s.rezim === 'cenovky' ? 'active' : ''}" onclick="stSetRezim('cenovky')"><span class="period-tab-icon">🏷️</span><span class="period-tab-text">Cenovky z výrobků</span></button>
        <button class="period-tab ${s.rezim === 'moje' ? 'active' : ''}" onclick="stSetRezim('moje')"><span class="period-tab-icon">✏️</span><span class="period-tab-text">Moje štítky</span></button>
        <button class="period-tab ${s.rezim === 'editor' ? 'active' : ''}" onclick="stSetRezim('editor')"><span class="period-tab-icon">🛠️</span><span class="period-tab-text">Editor šablon</span></button>
        <span style="flex:1"></span>
        <span class="stitky-tabs-sep" aria-hidden="true" style="display:inline-block;width:1px;height:32px;background:var(--border);margin:0 14px 0 0"></span>
        <button class="period-tab ${s.rezim === 'expedicni' ? 'active' : ''}" onclick="stSetRezim('expedicni')"><span class="period-tab-icon">📦</span><span class="period-tab-text">Expediční štítky</span></button>
      </div>

      <div class="form-grid form-grid-tight" style="margin-bottom:0">
        <div class="full">
          <label class="form-label">Formát samolepicího archu</label>
          <select class="form-input" id="st-format" onchange="stSetFormat(this.value)">
            ${STITKY_FORMATY.map(f => `<option value="${f.id}" ${f.id === s.formatId ? 'selected' : ''}>${esc(f.popis)}</option>`).join('')}
          </select>
          ${s.rezim !== 'expedicni' ? stStepHint(1, 'Vyber rozměr papíru, který máš v tiskárně. Najdeš na obalu samolepicích archů (Avery, Printky, SEVT…).') : ''}
        </div>

        ${s.formatId === 'custom' ? `
          <div>
            <label class="form-label">Šířka (mm)</label>
            <input class="form-input" type="number" step="0.1" value="${s.custom.w}" oninput="stState.custom.w = parseFloat(this.value) || 0">
          </div>
          <div>
            <label class="form-label">Výška (mm)</label>
            <input class="form-input" type="number" step="0.1" value="${s.custom.h}" oninput="stState.custom.h = parseFloat(this.value) || 0">
          </div>
          <div>
            <label class="form-label">Sloupců</label>
            <input class="form-input" type="number" min="1" max="10" value="${s.custom.cols}" oninput="stState.custom.cols = parseInt(this.value) || 1">
          </div>
          <div>
            <label class="form-label">Řádků</label>
            <input class="form-input" type="number" min="1" max="20" value="${s.custom.rows}" oninput="stState.custom.rows = parseInt(this.value) || 1">
          </div>
          <div>
            <label class="form-label">Okraj nahoře (mm)</label>
            <input class="form-input" type="number" step="0.1" value="${s.custom.mTop}" oninput="stState.custom.mTop = parseFloat(this.value) || 0">
          </div>
          <div>
            <label class="form-label">Okraj vlevo (mm)</label>
            <input class="form-input" type="number" step="0.1" value="${s.custom.mLeft}" oninput="stState.custom.mLeft = parseFloat(this.value) || 0">
          </div>
          <div>
            <label class="form-label">Mezera vodorovně (mm)</label>
            <input class="form-input" type="number" step="0.1" value="${s.custom.gapX}" oninput="stState.custom.gapX = parseFloat(this.value) || 0">
          </div>
          <div>
            <label class="form-label">Mezera svisle (mm)</label>
            <input class="form-input" type="number" step="0.1" value="${s.custom.gapY}" oninput="stState.custom.gapY = parseFloat(this.value) || 0">
          </div>
        ` : ''}

        ${s.rezim === 'expedicni' ? `
          <div>
            <label class="form-label">Kopií na položku</label>
            <div class="st-num-input">
              <button type="button" onclick="stState.kopie=Math.max(1,(parseInt(stState.kopie)||1)-1);renderStitky()">−</button>
              <input class="form-input" type="number" min="1" max="999" value="${s.kopie}" oninput="stState.kopie = parseInt(this.value)||1;stRefreshGridPreview()">
              <button type="button" onclick="stState.kopie=Math.min(999,(parseInt(stState.kopie)||1)+1);renderStitky()">+</button>
            </div>
          </div>
          <div>
            <label class="form-label">Začít od pozice</label>
            <div class="st-num-input">
              <button type="button" onclick="stSetStartPos((parseInt(stState.startPos)||1)-1)">−</button>
              <input class="form-input" type="number" min="1" value="${s.startPos}" oninput="stState.startPos=Math.max(1,parseInt(this.value)||1);stRefreshGridPreview()">
              <button type="button" onclick="stSetStartPos((parseInt(stState.startPos)||1)+1)">+</button>
            </div>
          </div>
          <div>
            <label class="form-label">QR kód</label>
            <div class="checkbox-row" style="padding-top:8px">
              <input type="checkbox" id="st-extra-code" ${s.vlozitQr ? 'checked' : ''} onchange="stState.vlozitQr = this.checked">
              <label for="st-extra-code" style="font-size:13px" title="QR obsahuje text 'OBJ:<id>:<číslo>' — slouží pro identifikaci objednávky čtečkou">QR — obsahuje OBJ:&lt;id&gt;:&lt;číslo&gt; objednávky</label>
            </div>
          </div>
        ` : `
          <div class="full st-cfg-grid" style="display:grid;grid-template-columns:130px 130px 1fr;gap:12px;align-items:end">
            <div>
              <label class="form-label">Kopií</label>
              <div class="st-num-input">
                <button type="button" onclick="stState.cenovkaPocet=Math.max(1,(parseInt(stState.cenovkaPocet)||1)-1);renderStitky()">−</button>
                <input class="form-input" type="number" min="1" max="999" value="${s.cenovkaPocet}" oninput="stState.cenovkaPocet = parseInt(this.value)||1;stRefreshGridPreview()">
                <button type="button" onclick="stState.cenovkaPocet=Math.min(999,(parseInt(stState.cenovkaPocet)||1)+1);renderStitky()">+</button>
              </div>
            </div>
            <div>
              <label class="form-label">Od pozice</label>
              <div class="st-num-input">
                <button type="button" onclick="stSetStartPos((parseInt(stState.startPos)||1)-1)">−</button>
                <input class="form-input" type="number" min="1" value="${s.startPos}" oninput="stState.startPos=Math.max(1,parseInt(this.value)||1);stRefreshGridPreview()">
                <button type="button" onclick="stSetStartPos((parseInt(stState.startPos)||1)+1)">+</button>
              </div>
            </div>
            <div>
              <label class="form-label">🎨 Design cenovky</label>
              ${(() => {
                // Aktuální rozměry štítku (mm) — pro auto-scale designů
                const fmtCur = s.formatId === 'custom' ? s.custom : (STITKY_FORMATY.find(f => f.id === s.formatId) || STITKY_FORMATY[0]);
                const curW = parseFloat(fmtCur.w) || 70;
                const curH = parseFloat(fmtCur.h) || 42;
                const foldHalf = !!fmtCur.foldHalf;   // 📐 Appek sklad od půlky → prvky jen ve spodní polovině

                const aktDesign = s.designId ? DESIGN_PRESETS.find(d => d.id === s.designId) : null;
                const aktSablona = s.sablonaId
                  ? (s.sablony || []).find(t => parseInt(t.id) === parseInt(s.sablonaId))
                  : null;

                let aktNazev, aktThumb;
                if (aktDesign) {
                  aktNazev = aktDesign.nazev;
                  aktThumb = sablonaMiniPreviewHtml(designAsSablona(aktDesign.id, curW, curH, foldHalf), 58);
                } else if (aktSablona) {
                  aktNazev = aktSablona.nazev;
                  aktThumb = sablonaMiniPreviewHtml(aktSablona, 58);
                } else {
                  aktNazev = '— Bez designu (jednoduchá cenovka) —';
                  aktThumb = '<span style="display:inline-flex;width:58px;height:38px;background:var(--surface-2);border:1px dashed var(--border);border-radius:4px;align-items:center;justify-content:center;font-size:13px;color:var(--text-3)">—</span>';
                }

                return `
                <div id="sab-picker-wrap" class="sab-picker-wrap" style="position:relative">
                  <button type="button" id="sab-picker-btn" class="sab-picker-btn form-input" onclick="stTogglePickerSablona()" style="display:flex;align-items:center;gap:10px;text-align:left;width:100%;cursor:pointer;padding:6px 10px;min-height:42px">
                    <span class="sab-picker-thumb">${aktThumb}</span>
                    <span style="flex:1;min-width:0;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;font-weight:${(aktDesign || aktSablona) ? '600' : '500'};color:${(aktDesign || aktSablona) ? 'var(--text)' : 'var(--text-3)'}">${esc(aktNazev)}</span>
                    <span class="sab-picker-arrow" style="font-size:11px;color:var(--text-3)">▼</span>
                  </button>
                  <div id="sab-picker-panel" class="sab-picker-panel" style="display:none;position:absolute;z-index:50;top:calc(100% + 6px);left:0;right:0;min-width:560px;max-width:96vw;background:var(--surface);border:1px solid var(--border);border-radius:10px;box-shadow:0 10px 30px rgba(0,0,0,0.15);padding:14px;max-height:620px;overflow-y:auto">

                    <!-- 🎨 Sekce: Připravené designy (auto-fit na zvolený formát) -->
                    <h4 style="margin:0 0 10px;font-size:13px;font-weight:700;color:var(--text-2);display:flex;align-items:center;gap:8px">
                      🎨 Připravené designy (auto-fit na ${curW}×${curH} mm)
                      <span style="font-size:11px;color:var(--text-3);font-weight:500">— stejné designy, jiný rozměr → jiné rozložení</span>
                    </h4>
                    <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(140px,1fr));gap:10px;margin-bottom:14px">
                      <button type="button" onclick="stSetDesign(null);stTogglePickerSablona(false)" class="sab-card ${!s.designId && !s.sablonaId ? 'is-active' : ''}" style="display:flex;flex-direction:column;align-items:center;gap:6px;padding:10px;background:var(--surface);border:2px solid ${!s.designId && !s.sablonaId ? 'var(--primary)' : 'var(--border)'};border-radius:8px;cursor:pointer;text-align:center">
                        <span style="display:inline-flex;width:110px;height:${Math.round(110 * (curH/curW))}px;max-height:148px;background:#fafaf9;border:1px dashed #d4d4d8;border-radius:4px;align-items:center;justify-content:center;font-size:11px;color:#9ca3af;text-align:center;padding:4px;line-height:1.2">Bez designu<br>(jednoduchá<br>cenovka)</span>
                        <span style="font-size:12px;font-weight:600">— Bez designu —</span>
                      </button>
                      ${DESIGN_PRESETS.map(d => `
                        <button type="button" onclick="stSetDesign('${d.id}');stTogglePickerSablona(false)" class="sab-card ${s.designId === d.id ? 'is-active' : ''}" style="display:flex;flex-direction:column;align-items:center;gap:6px;padding:10px;background:var(--surface);border:2px solid ${s.designId === d.id ? 'var(--primary)' : 'var(--border)'};border-radius:8px;cursor:pointer;text-align:center" title="${esc(d.nazev)} — ${esc(d.popis)}">
                          ${sablonaMiniPreviewHtml(designAsSablona(d.id, curW, curH, foldHalf), 110)}
                          <span style="font-size:12px;font-weight:700;line-height:1.25;overflow:hidden;display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical">${esc(d.nazev)}</span>
                          <span style="font-size:10px;color:var(--text-3);line-height:1.2">${esc(d.popis)}</span>
                        </button>
                      `).join('')}
                    </div>

                    <!-- 📋 Sekce: Tvoje uložené šablony (z editoru) -->
                    ${(s.sablony || []).length > 0 ? `
                      <h4 style="margin:16px 0 10px;font-size:13px;font-weight:700;color:var(--text-2);display:flex;align-items:center;gap:8px;padding-top:14px;border-top:1px dashed var(--border)">
                        📋 Tvoje uložené šablony (z editoru)
                        <span style="font-size:11px;color:var(--text-3);font-weight:500">— fixní rozměry dle uložení</span>
                      </h4>
                      <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(140px,1fr));gap:10px">
                        ${(s.sablony || []).map(t => `
                          <button type="button" onclick="stPickSablona('${t.id}')" class="sab-card ${parseInt(s.sablonaId) === parseInt(t.id) ? 'is-active' : ''}" style="display:flex;flex-direction:column;align-items:center;gap:6px;padding:10px;background:var(--surface);border:2px solid ${parseInt(s.sablonaId) === parseInt(t.id) ? 'var(--primary)' : 'var(--border)'};border-radius:8px;cursor:pointer;text-align:center" title="${esc(t.nazev)}">
                            ${sablonaMiniPreviewHtml(t, 110)}
                            <span style="font-size:12px;font-weight:600;line-height:1.25;overflow:hidden;display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical">${esc(t.nazev)}</span>
                          </button>
                        `).join('')}
                      </div>
                    ` : ''}
                  </div>
                </div>
                `;
              })()}
            </div>
            <!-- 📦 Načíst z produktu (z databáze, search + Upravit štítek) — full width druhý řádek -->
            <div style="grid-column:1 / -1">
              <label class="form-label">📦 Načíst z produktu <small style="color:var(--text-3);font-weight:400">— z databáze · předvyplní cenu, EAN, alergeny</small></label>
              <div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap">
                <input type="search"
                       id="st-vyrobek-search"
                       class="form-input"
                       placeholder="🔍 začni psát název / kód / EAN…"
                       value="${esc(s.vybranyVyrobekNazev || '')}"
                       oninput="stFilterVyrobky(this.value)"
                       onfocus="stFilterVyrobky(this.value)"
                       autocomplete="off"
                       style="flex:1;min-width:220px">
                ${(s.designId || s.sablonaId)
                  ? `<button type="button" class="btn-secondary" onclick="stSetRezim('editor')" style="font-size:12px;white-space:nowrap;height:38px;padding:0 14px" title="Otevřít aktivní design v editoru — uprav layout a ulož jako vlastní šablonu">✏️ Upravit štítek ↗</button>`
                  : `<button type="button" class="btn-secondary" disabled style="font-size:12px;white-space:nowrap;height:38px;padding:0 14px;opacity:0.5" title="Nejdřív vyber design štítku výše">✏️ Upravit štítek</button>`}
              </div>
              <!-- Live dropdown s výsledky z DB -->
              <div id="st-vyrobky-dropdown" style="position:relative;display:none">
                <div style="position:absolute;top:2px;left:0;right:0;max-height:260px;overflow-y:auto;background:#fff;border:1px solid var(--border);border-radius:8px;box-shadow:0 4px 16px rgba(0,0,0,0.1);z-index:50">
                  <div id="st-vyrobky-results"></div>
                </div>
              </div>
              ${s.vyrobky && s.vyrobky.length ? `<div style="font-size:11px;color:var(--text-3);margin-top:4px">📊 ${s.vyrobky.length} produktů v DB${s.vybranyVyrobekId ? ` · ✓ vybráno: <strong>${esc(s.vybranyVyrobekNazev)}</strong>` : ''}</div>` : ''}
            </div>
          </div>

          <!-- Step hinty 2-4 pod celým řádkem, ne uvnitř úzkých sloupců -->
          <div class="full" style="margin-top:6px;display:flex;flex-direction:column;gap:6px">
            ${stStepHint(2, 'Kolik kopií od každého výrobku se vytiskne.')}
            ${stStepHint(3, 'Od které buňky archu začít — užitečné když máš část archu už použitou.')}
            ${stStepHint(4, 'Volitelné — vyber design štítku z editoru (nebo nech „Bez šablony" = výchozí cenovka).')}
          </div>
        `}
      </div>

      <!-- 🆕 v2.0.77 — Chip row vybraných výrobků s per-product quantity -->
      ${(s.rezim === 'cenovky' && s.vybraneVyr && s.vybraneVyr.size > 0) ? `
        <div style="margin-top:14px;padding:12px 14px;background:linear-gradient(135deg,#FFF8E7,#FEF3C7);border:1.5px solid #FBBF24;border-radius:10px">
          <div style="display:flex;align-items:center;flex-wrap:wrap;gap:10px;margin-bottom:10px">
            <strong style="font-size:14px;color:#854F0B;display:flex;align-items:center;gap:6px">
              🏷️ Tisknu (${s.vybraneVyr.size} ${s.vybraneVyr.size === 1 ? 'výrobek' : (s.vybraneVyr.size < 5 ? 'výrobky' : 'výrobků')} · ${stTotalKopiiAll()} ks):
            </strong>
            <div style="flex:1"></div>
            <button class="btn-secondary" onclick="stVymazatVse()" style="font-size:12px;padding:5px 12px">✕ Vymazat vše</button>
          </div>
          <div style="display:flex;flex-wrap:wrap;gap:8px">
            ${[...s.vybraneVyr].map(vId => {
              const v = (s.vyrobky || []).find(x => parseInt(x.id) === parseInt(vId));
              if (!v) return '';
              const pocet = stGetPocetVyrobek(vId);
              return `
                <div class="st-chip" style="display:inline-flex;align-items:center;gap:8px;background:#fff;border:1.5px solid #FBBF24;border-radius:999px;padding:6px 8px 6px 14px;box-shadow:0 1px 3px rgba(0,0,0,0.05)">
                  <span style="font-size:15px;font-weight:700;color:#1d1d1f;max-width:260px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap" title="${esc(v.nazev)}">${esc(v.nazev)}</span>
                  <div class="st-chip-qty" style="display:inline-flex;align-items:center;gap:0;background:var(--surface-2);border-radius:999px;padding:0;overflow:hidden">
                    <button type="button" onclick="stChipChangePocet(${vId}, -1)" style="background:transparent;border:none;cursor:pointer;width:28px;height:28px;font-size:15px;font-weight:700;color:#854F0B" aria-label="Méně">−</button>
                    <input type="number" min="1" max="999" value="${pocet}" onchange="stChipSetPocet(${vId}, this.value)" style="width:38px;height:28px;border:none;background:transparent;text-align:center;font-size:13.5px;font-weight:700;color:#1d1d1f;font-family:inherit;-moz-appearance:textfield" onclick="this.select()">
                    <button type="button" onclick="stChipChangePocet(${vId}, +1)" style="background:transparent;border:none;cursor:pointer;width:28px;height:28px;font-size:15px;font-weight:700;color:#854F0B" aria-label="Více">+</button>
                  </div>
                  <button type="button" onclick="stChipRemove(${vId})" title="Odebrat" style="background:transparent;border:none;cursor:pointer;width:24px;height:24px;font-size:13px;color:#dc2626;border-radius:50%;display:inline-flex;align-items:center;justify-content:center">✕</button>
                </div>
              `;
            }).join('')}
          </div>
        </div>
        <style>
          /* Skry browser default spinner arrows na chip inputu */
          .st-chip-qty input::-webkit-outer-spin-button,
          .st-chip-qty input::-webkit-inner-spin-button { -webkit-appearance: none; margin: 0; }
          .st-chip:hover { box-shadow: 0 2px 8px rgba(186,117,23,0.2); transform: translateY(-1px); transition: all 0.15s; }
        </style>
      ` : ''}

      <!-- Vizuální mřížka pozic (jen náhled, pozice nastavuje +/- výše) -->
      <div style="margin-top:14px">
        <label class="form-label">Náhled archu <span style="color:var(--text-3);font-weight:400">(klik na buňku = nastavit start)</span></label>
        <div id="st-grid-host">${stRenderGrid(fmt, s.startPos)}</div>
        ${s.rezim !== 'expedicni' ? stStepHint(5, 'Klikni na buňku v náhledu — určí, od kterého políčka se začne tisknout.') : ''}
      </div>
    </div>

    ${(s.rezim === 'cenovky' || s.rezim === 'moje') ? renderStitkyCenovkaConfig() : ''}

    ${s.rezim === 'expedicni'
      ? renderStitkyExpedicni()
      : (s.rezim === 'cenovky' ? renderStitkyCenovky() : renderStitkyMoje())}

    ${s.rezim !== 'expedicni' ? `<div style="margin-top:14px">${stStepHint(8, 'Klikni „🖨️ Tisk" — otevře tiskový dialog s vygenerovaným archem. „👁️ Náhled" otevře PDF pro kontrolu.')}</div>` : ''}
    <div class="form-actions" style="margin-top:14px">
      <div style="flex:1"></div>
      <button class="btn-secondary" onclick="stitkyTisknout(true)">👁️ Náhled</button>
      <button class="btn-primary btn-green" onclick="stitkyTisknout(false)">🖨️ Tisk</button>
    </div>
  `;
  // 🔧 AutoFit po renderu — smrští texty které přesahují své boxy
  // Volá se 2× (rychle + s requestAnimationFrame), aby chytli i pomalu vyrenderované fonty
  setTimeout(() => autoFitPrvky(c), 0);
  requestAnimationFrame(() => requestAnimationFrame(() => autoFitPrvky(c)));
}

function renderStitkyCenovkaConfig() {
  const p = stState.cenovkaPole;
  const b = stState.cenovkaBadge;
  const items = [
    { k: 'nazev',    l: '🏷️ Název výrobku' },
    { k: 'popis',    l: '📝 Popis výrobku' },
    { k: 'cena',     l: '💰 Cena (s DPH)' },
    { k: 'hmotnost', l: '⚖️ Hmotnost / obsah' },
    { k: 'cenaKg',   l: '📊 Cena za kg/l' },
    { k: 'slozeni',  l: '🌾 Složení' },
    { k: 'alergeny', l: '⚠️ Alergeny' },
    { k: 'cislo',    l: '#️⃣ Kód výrobku' },
    { k: 'ean',      l: '|||| Čárový kód EAN-13' },
  ];
  return `
    <div class="card-block" style="padding:14px 16px;margin-bottom:14px">
      <div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:10px;margin-bottom:10px">
        <h3 style="margin:0;font-size:15px">📋 Co bude na cenovce</h3>
        <div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap">
          <span style="font-size:12px;color:var(--text-3)">${stState.sablonaId ? 'Šablona určuje pozice — checkboxy zobrazují/skryjou pole v náhledu' : 'Zaškrtni co se vytiskne'}</span>
          <button class="btn-secondary" onclick="stOtevritEditorZeCenovky()" style="font-size:12px;white-space:nowrap" title="Vezme aktuální zaškrtnutí a otevře editor — můžeš jen pohejbat prvky">🛠️ Upravit štítek (drag&drop)</button>
          <button class="btn-secondary" onclick="stOtevritStitekZVyrobku()" style="font-size:12px;white-space:nowrap" title="Vytvořit jednorázový vlastní štítek z některého výrobku (vyplní data: cenu, EAN, hmotnost, složení, alergeny)">📋 Vytvořit z výrobku</button>
        </div>
      </div>
      ${stStepHint(6, 'Zaškrtni pole, která se mají na cenovce vytisknout. Šedé/odškrtnuté = nezobrazí se.')}
      <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(170px,1fr));gap:8px">
        ${items.map(it => `
          <label class="checkbox-row st-pole" style="background:var(--surface-2);padding:8px 12px;border-radius:6px;cursor:pointer;border:1px solid ${p[it.k] ? 'var(--primary)' : 'var(--border)'}">
            <input type="checkbox" data-pole="${it.k}" ${p[it.k] ? 'checked' : ''} onchange="stTogglePole('${it.k}', this.checked)">
            <span style="font-size:13px">${it.l}</span>
          </label>
        `).join('')}
      </div>

      <div style="margin-top:14px;padding-top:14px;border-top:1px solid var(--border)">
        <div style="font-size:13px;font-weight:600;margin-bottom:8px;color:var(--text-2)">🔖 Badge (rohový proužek)</div>
        <div style="display:flex;gap:8px;flex-wrap:wrap;align-items:center">
          ${[
            { k: '',         l: '— žádný —', bg: 'transparent', fg: 'var(--text-3)' },
            { k: 'novinka',  l: '🆕 Novinka',  bg: '#22863a',    fg: 'white' },
            { k: 'akce',     l: '🔥 Akce',     bg: '#dc2626',    fg: 'white' },
            { k: 'doporuc',  l: '⭐ Doporučujeme', bg: '#BA7517', fg: 'white' },
            { k: 'bio',      l: '🌱 BIO',      bg: '#15803d',    fg: 'white' },
            { k: 'limited',  l: '⏳ Limit. edice', bg: '#7c3aed', fg: 'white' },
          ].map(o => {
            const isActive = b === o.k;
            const activeStyle = isActive
              ? `border:2px solid #1a1a1a;box-shadow:0 2px 6px rgba(0,0,0,0.15)`
              : `border:1.5px solid transparent;opacity:0.65`;
            return `
              <button type="button" class="badge-pick ${isActive ? 'is-active' : ''}"
                      onclick="stSetBadge('${o.k}')"
                      style="background:${o.bg};color:${o.fg};padding:7px 13px;border-radius:7px;font-size:13px;font-weight:600;cursor:pointer;transition:all 0.15s ease;${activeStyle}">
                ${isActive && o.k ? '✓ ' : ''}${o.l}
              </button>
            `;
          }).join('')}
        </div>
        <div style="margin-top:10px;display:flex;align-items:center;gap:8px;flex-wrap:wrap">
          <label style="font-size:12px;color:var(--text-3)">Vlastní text:</label>
          <input class="form-input" style="max-width:200px;font-size:13px" type="text" value="${esc(stState.cenovkaBadgeText || '')}" placeholder="např. -20%" oninput="stState.cenovkaBadgeText = this.value" onblur="renderStitky()">
          ${stState.cenovkaBadgeText ? `<button class="btn-secondary" style="font-size:11px;padding:4px 10px" onclick="stState.cenovkaBadgeText='';renderStitky()" title="Vyčistit vlastní text">✕ Vymazat</button>` : ''}
          <small style="color:var(--text-3);font-size:11px">přepíše preset výše</small>
        </div>
      </div>
    </div>
  `;
}

// =============================================================
// PŘEVOD AKTUÁLNÍ KONFIGURACE CENOVKY → PRVKY EDITORU
// — vezme zaškrtnutá pole z stState.cenovkaPole + badge a vygeneruje
//   hotové layout prvky které se otevřou v editoru
// =============================================================
window.stOtevritEditorZeCenovky = async function() {
  const pole = stState.cenovkaPole || {};
  const fmt = STITKY_FORMATY.find(f => f.id === stState.formatId) || STITKY_FORMATY[0];
  const isVisitka = (fmt.h <= 50);  // malé cenovky (vizitka 90×50)
  const prvky = [];
  let nextId = 0;
  const nid = () => 'p' + Date.now() + '_' + (++nextId);

  // Layout: pro malé cenovky 1 column, pro větší 2 columns + cena vpravo
  // Y postupně narůstá v procentech
  const addPrvek = (typ, opts) => {
    prvky.push({
      id: nid(),
      typ,
      x_pct: opts.x ?? 4,
      y_pct: opts.y,
      w_pct: opts.w ?? 92,
      h_pct: opts.h ?? 10,
      text: opts.text || '',
      fontSize: opts.fontSize || null,
      fontWeight: opts.fontWeight || null,
      color: opts.color || null,
      bg: opts.bg || null,
      align: opts.align || 'left',
      italic: !!opts.italic,
      padding: opts.padding || null,
    });
  };

  // BADGE (pruh nahoře)
  const badge = stState.cenovkaBadge;
  const badgeText = (stState.cenovkaBadgeText || '').trim();
  if (badge || badgeText) {
    const map = {
      novinka:  { text: 'NOVINKA',          bg: '#22863a' },
      akce:     { text: 'AKCE',             bg: '#dc2626' },
      doporuc:  { text: 'DOPORUČUJEME',     bg: '#BA7517' },
      bio:      { text: 'BIO',              bg: '#15803d' },
      limited:  { text: 'LIMITOVANÁ EDICE', bg: '#7c3aed' },
    };
    const cfg = map[badge] || { text: 'NOVINKA', bg: '#dc2626' };
    addPrvek('badge', {
      x: 0, y: 0, w: 100, h: 8,
      text: badgeText || cfg.text,
      bg: cfg.bg, color: '#fff', fontSize: 8, fontWeight: 800, align: 'center',
    });
  }
  let y = (badge || badgeText) ? 10 : 4;

  // NÁZEV
  if (pole.nazev) {
    addPrvek('nazev', { y, h: 14, fontSize: isVisitka ? 11 : 14, fontWeight: 800 });
    y += 16;
  }

  // POPIS
  if (pole.popis) {
    addPrvek('text', { y, h: 8, fontSize: 8, color: '#666', italic: true, text: '' });
    y += 9;
  }

  // CENA (velká)
  if (pole.cena) {
    addPrvek('cena', { y, h: 18, fontSize: isVisitka ? 22 : 28, fontWeight: 900, color: '#2C2C2A', align: 'center' });
    y += 20;
  }

  // META ŘÁDKA: hmotnost + cena/kg + kód (vedle sebe)
  let metaY = y;
  let metaX = 4;
  if (pole.hmotnost) {
    addPrvek('hmotnost', { x: metaX, y: metaY, w: 30, h: 8, fontSize: 9, fontWeight: 600 });
    metaX += 30;
  }
  if (pole.cenaKg) {
    addPrvek('cenakg', { x: metaX, y: metaY, w: 36, h: 8, fontSize: 8, fontWeight: 700, color: '#854F0B', bg: '#FFF8E5', padding: 1 });
    metaX += 36;
  }
  if (pole.cislo) {
    addPrvek('kod', { x: metaX, y: metaY, w: 26, h: 8, fontSize: 7, color: '#999' });
    metaX += 26;
  }
  if (metaX > 4) y += 9;

  // SLOŽENÍ
  if (pole.slozeni) {
    addPrvek('slozeni', { y, h: 10, fontSize: 7, color: '#555' });
    y += 11;
  }

  // ALERGENY
  if (pole.alergeny) {
    addPrvek('alergeny', { y, h: 8, fontSize: 7, color: '#92400e', fontWeight: 600 });
    y += 9;
  }

  // EAN dolů
  if (pole.ean) {
    addPrvek('ean', { y: Math.min(y, 76), h: 14, w: 60, x: 4 });
  }

  // Kontrola: pokud uživatel rozdělaný editor přepíšeme
  if (stEditor.prvky.length > 0 && stEditor.sablonaId !== stState.sablonaId) {
    if (!(await confirmDialog({ msg: 'V editoru máš rozdělané prvky. Přepsat je vygenerovanými z aktuální cenovky?', danger: false }))) return;
  }

  stEditor.sablonaId = null; // nová šablona — uživatel pak uloží
  stEditor.nazev = `Vlastní — ${fmt.popis}`;
  stEditor.formatId = stState.formatId;
  stEditor.prvky = prvky;
  stEditor.vybranyId = null;

  // Přepni rezim
  stState.rezim = 'editor';
  renderStitky();

  // Toast
  setTimeout(() => {
    const t = document.createElement('div');
    t.style.cssText = 'position:fixed;bottom:24px;right:24px;background:var(--success-bg);color:var(--success-text);padding:12px 18px;border-radius:8px;box-shadow:0 4px 12px rgba(0,0,0,.15);font-size:13px;font-weight:600;z-index:9999;max-width:340px;line-height:1.5';
    t.innerHTML = `🛠️ Editor otevřen s <strong>${prvky.length}</strong> prvky.<br><small style="font-size:11px;color:var(--success-text);opacity:0.85">Přesouvej prvky tažením, uprav velikost a uloží.</small>`;
    document.body.appendChild(t);
    setTimeout(() => t.remove(), 4000);
  }, 100);
};

window.stTogglePole = function(k, v) {
  stState.cenovkaPole[k] = v;
  renderStitky();   // full re-render — okamžitě zaktualizuje aktivní border na checkboxech i grid
};

window.stSetBadge = function(b) {
  stState.cenovkaBadge = b;
  // Vyčisti vlastní text — uživatel chce čistý preset bez náhodného obsahu
  stState.cenovkaBadgeText = '';
  renderStitky();
};

window.stSetSablona = function(idStr) {
  const id = parseInt(idStr) || null;
  stState.sablonaId = id;
  stState.designId = null;   // saved sablona a design jsou exkluzivní
  if (id) {
    const sab = stState.sablony.find(s => s.id == id);
    if (sab && sab.format_id) stState.formatId = sab.format_id;
  }
  renderStitky();
};

// 🎨 Vyber built-in design preset (auto-fit) — sablonu vynulujeme
window.stSetDesign = function(designId) {
  stState.designId = designId || null;
  stState.sablonaId = null;
  renderStitky();
};

// 🔎 Filtruje vyrobky z DB (stState.vyrobky) podle textu — live dropdown s výsledky
window.stFilterVyrobky = function(query) {
  const wrap = document.getElementById('st-vyrobky-dropdown');
  const results = document.getElementById('st-vyrobky-results');
  if (!wrap || !results) return;
  stState.vybranyVyrobekNazev = query || '';

  const q = (query || '').trim().toLowerCase();
  let list = stState.vyrobky || [];
  if (q.length === 0) {
    // Empty query → zobraz prvních 30
    list = list.slice(0, 30);
  } else {
    list = list.filter(v =>
      (v.nazev || '').toLowerCase().includes(q) ||
      (v.cislo || '').toLowerCase().includes(q) ||
      (v.ean || '').toLowerCase().includes(q)
    ).slice(0, 30);
  }

  if (list.length === 0) {
    results.innerHTML = `<div style="padding:14px;text-align:center;color:var(--text-3);font-size:13px">Žádný produkt neodpovídá "${esc(query)}"</div>`;
  } else {
    results.innerHTML = list.map(v => `
      <div class="st-vyr-item"
           onclick="stPickVyrobekById(${v.id})"
           style="padding:8px 12px;cursor:pointer;border-bottom:1px solid var(--border);display:flex;justify-content:space-between;align-items:center;gap:10px;transition:background 0.1s"
           onmouseover="this.style.background='var(--surface-2)'"
           onmouseout="this.style.background=''">
        <div style="flex:1;min-width:0;overflow:hidden">
          <div style="font-weight:600;font-size:13px;white-space:nowrap;text-overflow:ellipsis;overflow:hidden">${esc(v.nazev)}</div>
          <div style="font-size:11px;color:var(--text-3);margin-top:2px">
            ${v.cislo ? `<code>${esc(v.cislo)}</code>` : ''}
            ${v.ean ? ` · 📊 ${esc(v.ean)}` : ''}
            ${v.alergeny ? ` · ⚠️ ${esc(v.alergeny)}` : ''}
          </div>
        </div>
        <div style="font-weight:700;color:var(--primary-dark);font-size:13px;white-space:nowrap">${parseFloat(v.cena_bez_dph || 0).toFixed(2)} Kč</div>
      </div>
    `).join('');
  }
  wrap.style.display = 'block';

  // Auto-close on click outside
  setTimeout(() => {
    const closeFn = (e) => {
      if (!wrap.contains(e.target) && e.target.id !== 'st-vyrobek-search') {
        wrap.style.display = 'none';
        document.removeEventListener('click', closeFn);
      }
    };
    document.addEventListener('click', closeFn);
  }, 50);
};

// 📦 Vyber produkt podle ID — předvyplní cenovku
window.stPickVyrobekById = function(id) {
  const v = (stState.vyrobky || []).find(x => parseInt(x.id) === parseInt(id));
  if (!v) return;
  stState.vybranyVyrobekId = v.id;
  stState.vybranyVyrobekNazev = v.nazev;
  // Předvyplň cenu/EAN/alergeny do cenovky preview
  stState.cenovkaData = {
    nazev: v.nazev,
    popis: v.popis || '',
    cena: parseFloat(v.cena_bez_dph || 0) * (1 + (parseFloat(v.dph || 12) / 100)),
    cena_bez_dph: parseFloat(v.cena_bez_dph || 0),
    ean: v.ean || '',
    cislo: v.cislo || '',
    hmotnost: v.hmotnost_g ? (v.hmotnost_g + ' g') : '',
    alergeny: v.alergeny || '',
    slozeni: v.slozeni || '',
  };

  // 🆕 v2.0.93 FIX: PŘIDEJ produkt do vybraneVyr (Set) — jinak se nepropíše do chip rowu ani do label gridu
  // Předtím se nastavil jen singular `vybranyVyrobekId`, ale render používá `vybraneVyr` Set.
  // Důsledek: user vybral produkt → nezobrazil se jako štítek na archu.
  if (!stState.vybraneVyr) stState.vybraneVyr = new Set();
  stState.vybraneVyr.add(parseInt(v.id));

  // Vyčisti search input — uživatel může vybrat další produkt
  const searchInput = document.getElementById('st-vyrobek-search');
  if (searchInput) searchInput.value = '';
  stState.vybranyVyrobekNazev = ''; // reset display name v hint pod searchem

  const wrap = document.getElementById('st-vyrobky-dropdown');
  if (wrap) wrap.style.display = 'none';
  renderStitky();
};

// ✏️ Otevři modal pro úpravu vybraného produktu
window.stUpravitVybranyVyrobek = function() {
  if (!stState.vybranyVyrobekId) {
    alert('Nejdřív vyber produkt z dropdown (klikni na řádek v seznamu).');
    return;
  }
  if (typeof window.editVyrobek === 'function') {
    window.editVyrobek(stState.vybranyVyrobekId);
  } else if (typeof window.otevritVyrobek === 'function') {
    window.otevritVyrobek(stState.vybranyVyrobekId);
  } else {
    alert('Editor produktů není dostupný.');
  }
};

// 🔢 Step-hint helper — pod každé pole v cenovkách napíše krok postupu
// Použití: stStepHint(1, 'Vyber rozměr archu, který máš v tiskárně')
// Na mobilu se text skryje — zůstane jen číslo + ? ikona; klik na hint otevře modal s textem.
function stStepHint(num, text) {
  const safe = String(text).replace(/'/g, '&#39;').replace(/"/g, '&quot;');
  return `<div class="st-step-hint" data-step="${num}" onclick="stShowHintModal(${num}, '${safe}')" role="button" tabindex="0" onkeydown="if(event.key==='Enter'||event.key===' '){event.preventDefault();stShowHintModal(${num}, '${safe}')}"><span class="st-step-num">${num}</span><span class="st-step-text">${esc(text)}</span><span class="st-step-q" aria-hidden="true">?</span></div>`;
}

// 💡 Otevře modal s nápovědou pro daný krok (využívá se hl. na mobilu)
window.stShowHintModal = function(num, text) {
  openModal(`💡 Krok ${num}`, `
    <div style="padding:6px 0 12px;font-size:16px;line-height:1.6;color:var(--text-1)">
      <p style="margin:0">${esc(text)}</p>
    </div>
    <div class="form-actions">
      <div style="flex:1"></div>
      <button class="btn-primary btn-green" onclick="closeModal()">Rozumím</button>
    </div>
  `);
};

function stRenderGrid(fmt, startPos) {
  const cols = fmt.custom ? stState.custom.cols : fmt.cols;
  const rows = fmt.custom ? stState.custom.rows : fmt.rows;
  const total = cols * rows;

  // === Mini-náhled v buňkách: sestav pole 'slotItems' (1 položka = 1 nálepka) ===
  const slotItems = [];
  if (stState.rezim === 'cenovky') {
    const vyrMap = new Map((stState.vyrobky || []).map(v => [parseInt(v.id), v]));
    // 🆕 v2.0.77 — per-product quantity (chip row)
    [...stState.vybraneVyr].forEach(id => {
      const v = vyrMap.get(parseInt(id));
      if (!v) return;
      const kopii = stGetPocetVyrobek(id);
      for (let k = 0; k < kopii; k++) {
        slotItems.push({
          kind: 'cenovka',
          nazev: v.nazev,
          cislo: v.cislo,
          cena: v.cena_bez_dph,
          jednotka: v.jednotka,
          hmotnost_g: v.hmotnost_g,
          popis: v.popis,
          slozeni: v.slozeni,
          alergeny: v.alergeny,
          ean: v.ean,
        });
      }
    });
  } else if (stState.rezim === 'moje') {
    const mojeMap = new Map((stState.mojeStitky || []).map(m => [parseInt(m.id), m]));
    const kopii = Math.max(1, parseInt(stState.cenovkaPocet) || 1);
    [...stState.vybraneMoje].forEach(id => {
      const m = mojeMap.get(parseInt(id));
      if (!m) return;
      for (let k = 0; k < kopii; k++) {
        slotItems.push({
          kind: 'moje',
          nazev: m.nazev || m.text || '?',
          cislo: m.cislo,
          cena: m.cena_s_dph || m.cena,
          jednotka: m.jednotka,
          hmotnost_g: m.hmotnost_g,
          ean: m.ean,
          badge_text: m.badge_text || m.badge,
        });
      }
    });
  } else if (stState.rezim === 'expedicni') {
    const objMap = new Map((stState.obj || []).map(o => [parseInt(o.id), o]));
    const kopii = Math.max(1, parseInt(stState.kopie) || 1);
    [...stState.vybraneObj].forEach(id => {
      const o = objMap.get(parseInt(id));
      if (!o) return;
      for (let k = 0; k < kopii; k++) {
        slotItems.push({
          kind: 'expedice',
          nazev: o.odberatel_nazev || '?',
          cena: o.castka_celkem,
          extra: `OBJ ${o.cislo}`,
        });
      }
    });
  }

  // Konfigurace polí (jen v cenovkách + moje, ne v expedici)
  const pole = stState.cenovkaPole || {};
  const badge = stState.cenovkaBadge || '';
  const badgeText = (stState.cenovkaBadgeText || '').trim();
  const BADGE_PRESET = {
    novinka: { txt: '🆕 Novinka', bg: '#22863a' },
    akce:    { txt: '🔥 Akce',    bg: '#dc2626' },
    doporuc: { txt: '⭐ Doporuč.', bg: '#BA7517' },
    bio:     { txt: '🌱 BIO',     bg: '#15803d' },
    limited: { txt: '⏳ Lim.ed.', bg: '#7c3aed' },
  };
  const badgeRender = (item) => {
    const presetTxt = BADGE_PRESET[badge]?.txt;
    const presetBg = BADGE_PRESET[badge]?.bg;
    const txt = badgeText || presetTxt || item.badge_text;
    if (!txt) return '';
    const bg = presetBg || '#dc2626';
    return `<span class="st-cell-badge" style="background:${bg}">${esc(txt.slice(0, 10))}</span>`;
  };

  const tplName = stState.sablonaId
    ? ((stState.sablony || []).find(t => parseInt(t.id) === parseInt(stState.sablonaId))?.nazev || '')
    : '';
  const useCfg = stState.rezim !== 'expedicni' && !stState.sablonaId;   // pro expedice/šablonu cfg ignoruj

  // Renderuje obsah jedné buňky s preview podle cfg
  const renderPreviewContent = (item, i) => {
    if (stState.rezim === 'expedicni') {
      return `
        <span class="st-cell-num">${i}</span>
        <span class="st-cell-name" title="${esc(item.nazev)}">${esc((item.nazev || '').slice(0, 22))}</span>
        ${item.cena != null ? `<span class="st-cell-price">${fmt(parseFloat(item.cena) || 0)}</span>` : ''}
        ${item.extra ? `<span class="st-cell-extra">${esc(item.extra)}</span>` : ''}
      `;
    }
    if (stState.sablonaId) {
      // Se šablonou — neaplikujeme cfg, ukážeme jen základ + badge že je šablona aktivní
      return `
        <span class="st-cell-num">${i}</span>
        <span class="st-cell-name" title="${esc(item.nazev)}">${esc((item.nazev || '').slice(0, 22))}</span>
        ${item.cena != null ? `<span class="st-cell-price">${fmt(parseFloat(item.cena) || 0)}${item.jednotka ? '/' + esc(item.jednotka) : ''}</span>` : ''}
        <span class="st-cell-tpl-badge" title="Použita šablona: ${esc(tplName)}">🛠️</span>
      `;
    }
    // Cenovky / Moje — respektuj cfg „Co bude na cenovce"
    const cenaNum = parseFloat(item.cena) || 0;
    const hmotnost = parseFloat(item.hmotnost_g) || 0;
    const cenaPerKg = (cenaNum > 0 && hmotnost > 0) ? (cenaNum / hmotnost * 1000) : 0;
    return `
      <span class="st-cell-num">${i}</span>
      ${badgeRender(item)}
      ${pole.nazev ? `<span class="st-cell-name" title="${esc(item.nazev)}">${esc((item.nazev || '').slice(0, 22))}</span>` : ''}
      ${pole.popis && item.popis ? `<span class="st-cell-popis">${esc(item.popis.slice(0, 28))}</span>` : ''}
      ${pole.hmotnost && hmotnost > 0 ? `<span class="st-cell-hmot">${hmotnost} g</span>` : ''}
      ${pole.cena && item.cena != null ? `<span class="st-cell-price">${fmt(cenaNum)}${item.jednotka ? '/' + esc(item.jednotka) : ''}</span>` : ''}
      ${pole.cenaKg && cenaPerKg > 0 ? `<span class="st-cell-cenakg">${cenaPerKg.toFixed(0)} Kč/kg</span>` : ''}
      ${pole.cislo && item.cislo ? `<span class="st-cell-cislo">${esc(item.cislo)}</span>` : ''}
      ${pole.ean && item.ean ? `<span class="st-cell-ean">||||||| ${esc(String(item.ean).slice(0,13))}</span>` : ''}
      ${pole.alergeny && item.alergeny ? `<span class="st-cell-alergeny">⚠ ${esc((item.alergeny || '').slice(0, 18))}</span>` : ''}
      ${pole.slozeni && item.slozeni ? `<span class="st-cell-slozeni">${esc((item.slozeni || '').slice(0, 24))}…</span>` : ''}
    `;
  };

  // === DEBUG SIMPLE preview: použijeme inline style, ne classes (přebíjí cokoliv) ===
  const cells = [];
  for (let i = 1; i <= total; i++) {
    const isStart = i === startPos;
    const isEmpty = i < startPos;
    const slotIdx = i - startPos;
    const item = !isEmpty && slotIdx >= 0 ? slotItems[slotIdx] : null;

    let bg = '#fff';
    let border = '1px solid #ddd';
    let content;
    if (isEmpty) {
      bg = '#f1f1f1';
      border = '1px dashed #ccc';
      content = `<span style="color:#bbb;font-size:11px">${i}</span>`;
    } else if (item) {
      // FILLED cell — vždy jasně viditelná
      bg = isStart ? '#F7F8FA' : '#FBFCFD';
      border = isStart ? '3px solid #BA7517' : '1.5px solid #BA7517';
      const cenaNum = parseFloat(item.cena) || 0;
      const hmotnost = parseFloat(item.hmotnost_g) || 0;
      const cenaPerKg = (cenaNum > 0 && hmotnost > 0) ? (cenaNum / hmotnost * 1000) : 0;
      const lines = [];
      if (stState.rezim === 'expedicni') {
        lines.push(`<div style="font-weight:700;font-size:11px;color:#222;line-height:1.2;text-align:center;padding:0 4px">${esc((item.nazev || '').slice(0, 24))}</div>`);
        if (item.cena != null) lines.push(`<div style="font-weight:800;font-size:14px;color:#854F0B;text-align:center;margin-top:3px">${fmt(cenaNum)}</div>`);
        if (item.extra) lines.push(`<div style="font-size:9px;color:#666;text-align:center;margin-top:1px">${esc(item.extra)}</div>`);
      } else if (stState.designId || stState.sablonaId) {
        // === DESIGN (built-in auto-fit) NEBO ŠABLONA Z EDITORU — render prvků na pozicích ===
        let prvky = [];
        if (stState.designId) {
          // 🎨 Built-in design — auto-scale prvky pro aktuální formát (+ fold-half pro Appek cenovky)
          const curW = parseFloat(fmt.w) || 70;
          const curH = parseFloat(fmt.h) || 42;
          prvky = designScaledPrvky(stState.designId, curW, curH, !!fmt.foldHalf) || [];
        } else {
          const sab = (stState.sablony || []).find(t => parseInt(t.id) === parseInt(stState.sablonaId));
          if (sab) {
            let layout = sab.layout;
            if (typeof layout === 'string') {
              try { layout = JSON.parse(layout); } catch (e) { layout = {}; }
            }
            prvky = Array.isArray(layout) ? layout : (Array.isArray(layout?.prvky) ? layout.prvky : []);
          }
        }
        const typToPole = { nazev: 'nazev', cena: 'cena', cenakg: 'cenaKg', kod: 'cislo', cislo: 'cislo',
                             alergeny: 'alergeny', slozeni: 'slozeni', ean: 'ean', hmotnost: 'hmotnost', popis: 'popis' };
        const filtPrvky = prvky.filter(p => {
          const polePart = typToPole[(p.typ || '').toLowerCase()];
          if (polePart) return pole[polePart] !== false;
          return true;
        });

        // 🔖 BADGE RIBBON — globální badge přes celou horní část (i přes šablonu)
        const sabBadgePresetTxt = { novinka:'🆕 NOVINKA', akce:'🔥 AKCE', doporuc:'⭐ DOPORUČUJEME', bio:'🌱 BIO', limited:'⏳ LIMIT.EDICE' }[stState.cenovkaBadge];
        const sabBadgePresetBg = { novinka:'#22863a', akce:'#dc2626', doporuc:'#BA7517', bio:'#15803d', limited:'#7c3aed' }[stState.cenovkaBadge];
        const sabCustom = (stState.cenovkaBadgeText || '').trim();
        const sabUseCustom = sabCustom.length >= 2;
        const sabBadgeTxt = sabUseCustom ? sabCustom : sabBadgePresetTxt;
        if (sabBadgeTxt) {
          lines.push(`<div style="position:absolute;top:2px;left:2px;right:2px;background:${sabBadgePresetBg || '#dc2626'};color:#fff;font-size:9px;font-weight:800;letter-spacing:0.6px;padding:3px 4px;text-align:center;z-index:2;text-transform:uppercase;border-radius:3px">${esc(sabBadgeTxt.slice(0, 18))}</div>`);
        }

        // Zaškrtnutá pole co v šabloně chybí → doplň jako fallback stack dole v cell
        const prvkyTypes = new Set(prvky.map(p => (p.typ || '').toLowerCase()));
        const missingFields = [];
        if (pole.popis    && !prvkyTypes.has('popis')    && item.popis)    missingFields.push(`<div style="font-style:italic;font-size:8px;color:#666;text-align:center">${esc(item.popis.slice(0, 26))}</div>`);
        if (pole.hmotnost && !prvkyTypes.has('hmotnost') && hmotnost > 0)  missingFields.push(`<div style="font-size:8px;color:#555;text-align:center">⚖ ${hmotnost} g</div>`);
        if (pole.cenaKg   && !prvkyTypes.has('cenakg')   && cenaPerKg > 0) missingFields.push(`<div style="font-size:8px;color:#888;text-align:center">${cenaPerKg.toFixed(0)} Kč/kg</div>`);
        if (pole.cislo    && !prvkyTypes.has('kod') && !prvkyTypes.has('cislo') && item.cislo) missingFields.push(`<div style="font-size:7px;color:#999;text-align:center">${esc(item.cislo)}</div>`);
        if (pole.ean      && !prvkyTypes.has('ean')      && item.ean)      missingFields.push(`<div style="font-family:monospace;font-size:7px;color:#666;text-align:center">||| ${esc(String(item.ean).slice(0,13))}</div>`);
        if (pole.alergeny && !prvkyTypes.has('alergeny') && item.alergeny) missingFields.push(`<div style="font-size:7px;color:#92400e;font-weight:600;text-align:center">⚠ ${esc((item.alergeny).slice(0, 20))}</div>`);
        if (pole.slozeni  && !prvkyTypes.has('slozeni')  && item.slozeni)  missingFields.push(`<div style="font-size:7px;font-style:italic;color:#888;text-align:center">${esc((item.slozeni).slice(0, 26))}…</div>`);

        if (filtPrvky.length === 0 && missingFields.length === 0) {
          lines.push(`<div style="font-weight:700;font-size:11px;color:#222;text-align:center;padding:0 4px;margin-top:${sabBadgeTxt ? 22 : 4}px">${esc((item.nazev || '').slice(0, 22))}</div>`);
          lines.push(`<div style="font-size:9px;color:#999;font-style:italic;margin-top:4px">${prvky.length === 0 ? '(prázdná šablona)' : '(nic není zaškrtnuté)'}</div>`);
        } else {
          const tplHtml = filtPrvky.map(p => stPrvekMini(p, item, cenaNum, hmotnost, cenaPerKg)).join('');
          const tplTop = sabBadgeTxt ? 24 : 2;
          const fallbackHtml = missingFields.length > 0
            ? `<div style="position:absolute;left:2px;right:2px;bottom:2px;background:rgba(255,255,255,0.92);padding:2px 4px;border-top:1px dashed #ccc;display:flex;flex-direction:column;gap:1px">${missingFields.join('')}</div>`
            : '';
          lines.push(`<div style="position:absolute;left:2px;right:2px;top:${tplTop}px;bottom:2px;background:#fff;border-radius:3px;overflow:hidden">${tplHtml}${fallbackHtml}</div>`);
        }
      } else {
        // Cenovky/Moje — pole konfigurace
        const badgePresetTxt = { novinka:'🆕 Novinka', akce:'🔥 Akce', doporuc:'⭐ Dopor.', bio:'🌱 BIO', limited:'⏳ Lim.' }[stState.cenovkaBadge];
        const badgePresetBg = { novinka:'#22863a', akce:'#dc2626', doporuc:'#BA7517', bio:'#15803d', limited:'#7c3aed' }[stState.cenovkaBadge];
        // Priorita: VLASTNÍ text (jen pokud má 2+ znaky a není 0) > preset > per-item
        const customTxt = (stState.cenovkaBadgeText || '').trim();
        const useCustom = customTxt.length >= 1 && customTxt !== '1' && customTxt !== '0';
        const badgeTxt = useCustom ? customTxt : (badgePresetTxt || item.badge_text);
        if (badgeTxt) {
          lines.push(`<div style="position:absolute;top:0;right:0;background:${badgePresetBg || '#dc2626'};color:#fff;font-size:9px;font-weight:700;padding:3px 7px;border-bottom-left-radius:5px;letter-spacing:0.2px">${esc(badgeTxt.slice(0,14))}</div>`);
        }
        if (pole.nazev) lines.push(`<div style="font-weight:700;font-size:11px;color:#222;line-height:1.2;text-align:center;padding:0 4px;margin-top:8px">${esc((item.nazev || '').slice(0, 22))}</div>`);
        if (pole.popis && item.popis) lines.push(`<div style="font-style:italic;font-size:9px;color:#666;text-align:center">${esc(item.popis.slice(0, 24))}</div>`);
        if (pole.hmotnost && hmotnost > 0) lines.push(`<div style="font-size:9px;color:#555;text-align:center">${hmotnost} g</div>`);
        if (pole.cena && item.cena != null) lines.push(`<div style="font-weight:800;font-size:14px;color:#854F0B;text-align:center;margin-top:2px">${fmt(cenaNum)}${item.jednotka ? '/' + esc(item.jednotka) : ''}</div>`);
        if (pole.cenaKg && cenaPerKg > 0) lines.push(`<div style="font-size:9px;color:#888;text-align:center">${cenaPerKg.toFixed(0)} Kč/kg</div>`);
        if (pole.cislo && item.cislo) lines.push(`<div style="font-size:8px;color:#999;text-align:center">${esc(item.cislo)}</div>`);
        if (pole.ean && item.ean) lines.push(`<div style="font-family:monospace;font-size:8px;color:#666;text-align:center">||||| ${esc(String(item.ean).slice(0,13))}</div>`);
        if (pole.alergeny && item.alergeny) lines.push(`<div style="font-size:8px;color:#92400e;font-weight:600;text-align:center">⚠ ${esc((item.alergeny || '').slice(0, 18))}</div>`);
        if (pole.slozeni && item.slozeni) lines.push(`<div style="font-size:8px;font-style:italic;color:#888;text-align:center">${esc((item.slozeni || '').slice(0, 24))}…</div>`);
        // Fallback pokud nic není zaškrtnuté
        if (lines.length === 0 || (lines.length === 1 && !lines[0].includes('font-weight'))) {
          lines.push(`<div style="font-weight:700;font-size:11px;color:#222;text-align:center;padding:0 4px;margin-top:8px">${esc((item.nazev || '').slice(0, 22))}</div>`);
          lines.push(`<div style="font-size:9px;color:#999;font-style:italic;text-align:center;margin-top:4px">(zaškrtni co tisknout)</div>`);
        }
      }
      content = lines.join('');
    } else {
      content = `<span style="color:#aaa;font-size:11px">${i}</span>`;
    }

    cells.push(`<button type="button" onclick="stSetStartPos(${i})" title="${item ? esc(item.nazev) : 'Pozice ' + i}" style="position:relative;background:${bg};border:${border};border-radius:6px;padding:14px 4px 4px;font-family:inherit;font-size:11px;cursor:pointer;display:flex;flex-direction:column;align-items:center;justify-content:center;min-height:60px;gap:1px;overflow:hidden;color:#222">${content}</button>`);
  }

  const naplnenoCnt = slotItems.length;
  const dostatecne = naplnenoCnt + startPos - 1 <= total;
  const zoom = stState.gridZoom || 1;
  // Zoom via CSS 'zoom' property — scale včetně layoutu
  return `
    <div class="st-grid-wrap">
      <div class="st-grid-info" style="position:sticky;top:0;z-index:10;background:#F7F8FA;padding:8px 4px;margin:0 -4px 10px;border-radius:6px;border-bottom:1px solid var(--border)">
        <span style="font-size:12px;color:var(--text-2)">Pozice <strong>${startPos}</strong> · ${total} buněk ${rows > 0 ? `(${cols}×${rows})` : ''}
        ${naplnenoCnt > 0 ? ` · <strong>${naplnenoCnt}</strong> nálepek${!dostatecne ? ` <span style="color:var(--danger-text)">⚠</span>` : ''}` : ''}</span>
        ${zoom !== 1 ? `<button class="btn-secondary" style="font-size:11px;padding:4px 12px;margin-left:auto;opacity:0.85" onclick="stZoomReset()" title="Reset na 100%">↻ Reset zoom</button>` : '<span style="margin-left:auto"></span>'}
        <span class="st-grid-zoom" style="display:inline-flex;gap:6px;align-items:center;margin-left:12px;background:#fff;padding:4px 8px;border-radius:8px;border:1.5px solid var(--border-2);box-shadow:0 1px 3px rgba(0,0,0,0.04)">
          <button class="btn-secondary" style="font-size:18px;padding:4px 14px;font-weight:700;min-width:42px" onclick="stZoomChange(-0.25)" title="Oddálit">−</button>
          <span style="font-size:14px;font-weight:700;min-width:54px;text-align:center;color:var(--text);font-variant-numeric:tabular-nums">${Math.round(zoom * 100)}%</span>
          <button class="btn-secondary" style="font-size:18px;padding:4px 14px;font-weight:700;min-width:42px" onclick="stZoomChange(0.25)" title="Přiblížit">+</button>
        </span>
      </div>
      <div style="overflow:auto">
        <div class="st-grid" id="st-grid-preview" style="grid-template-columns:repeat(${cols}, 1fr);zoom:${zoom};-moz-transform:scale(${zoom});-moz-transform-origin:top left">${cells.join('')}</div>
      </div>
    </div>
  `;
}

// 🥗 v2.9.302 — Helper: vrátí parsovaný nutri objekt z výrobku
// Podporuje: v.nutricni_hodnoty (JSON string nebo objekt) — klíče energie_kj, energie_kcal, tuky, tuky_nasycene, sacharidy, cukry, bilkoviny, sul
function vyrobekNutri(v) {
  if (!v) return {};
  const raw = v.nutricni_hodnoty;
  if (!raw) return {};
  if (typeof raw === 'object') return raw || {};
  if (typeof raw === 'string') {
    try { return JSON.parse(raw) || {}; } catch (e) { return {}; }
  }
  return {};
}
// Vrátí formátovaný text pro daný nutri-typ. Pokud chybí hodnota, vrátí null (volající rozhodne o placeholderu).
function nutriPrvekText(typ, v, withLabel = true) {
  const n = vyrobekNutri(v);
  const fmt = (val, dec = 1) => {
    const x = parseFloat(val);
    if (isNaN(x)) return null;
    return dec === 0 ? Math.round(x).toString() : x.toFixed(dec).replace(/\.?0+$/, '');
  };
  switch (typ) {
    case 'nutri_kj':   { const x = fmt(n.energie_kj, 0);   return x ? `${x} kJ` : null; }
    case 'nutri_kcal': { const x = fmt(n.energie_kcal, 0); return x ? `${x} kcal` : null; }
    case 'nutri_tuky': { const x = fmt(n.tuky, 1);         return x ? (withLabel ? `Tuky ${x} g` : `${x} g`) : null; }
    case 'nutri_sach': { const x = fmt(n.sacharidy, 1);    return x ? (withLabel ? `Sach ${x} g` : `${x} g`) : null; }
    case 'nutri_bilk': { const x = fmt(n.bilkoviny, 1);    return x ? (withLabel ? `Bilk ${x} g` : `${x} g`) : null; }
    case 'nutri_sul':  { const x = fmt(n.sul, 2);          return x ? (withLabel ? `Sůl ${x} g` : `${x} g`) : null; }
    case 'nutri': {
      // Plná tabulka — jen vyplněné položky, oddělené ·
      const parts = [];
      const kj = fmt(n.energie_kj, 0); const kcal = fmt(n.energie_kcal, 0);
      if (kj && kcal)   parts.push(`Energie ${kj}kJ/${kcal}kcal`);
      else if (kj)      parts.push(`Energie ${kj}kJ`);
      else if (kcal)    parts.push(`Energie ${kcal}kcal`);
      const tuky = fmt(n.tuky, 1);       if (tuky) parts.push(`Tuky ${tuky}g`);
      const sach = fmt(n.sacharidy, 1);  if (sach) parts.push(`Sach ${sach}g`);
      const bilk = fmt(n.bilkoviny, 1);  if (bilk) parts.push(`Bilk ${bilk}g`);
      const sul  = fmt(n.sul, 2);        if (sul)  parts.push(`Sůl ${sul}g`);
      return parts.length ? parts.join(' · ') : null;
    }
  }
  return null;
}

// Mini-render jednoho prvku šablony (inline-style, pozicováno přes percenta).
// Obsah generuje z item dat (název, cena, hmotnost, …) podle prvek.typ.
function stPrvekMini(p, item, cenaNum, hmotnost, cenaPerKg) {
  const x = Math.max(0, parseFloat(p.x_pct) || 0);
  const y = Math.max(0, parseFloat(p.y_pct) || 0);
  const w = Math.min(100 - x, parseFloat(p.w_pct) || 30);
  const h = Math.min(100 - y, parseFloat(p.h_pct) || 10);
  const align = p.align || 'left';
  const fw = (parseInt(p.fontWeight) >= 600) ? 'font-weight:700;' : '';
  const fst = p.italic ? 'font-style:italic;' : '';
  const color = p.color ? `color:${esc(p.color)};` : '';
  const bg = p.bg ? `background:${esc(p.bg)};` : '';
  const padding = p.padding ? `padding:${parseInt(p.padding) * 0.3}px;` : '';
  // Font scaled — actual / 2.2 (cell is ~2× menší než reálná nálepka)
  const fontSize = Math.max(4, (parseFloat(p.fontSize) || 8) * 0.5);

  // Obsah dle typu prvku
  let txt = '';
  switch ((p.typ || '').toLowerCase()) {
    case 'nazev':
      txt = (item.nazev || '').slice(0, 18); break;
    case 'cena':
      txt = item.cena != null ? fmt(cenaNum) + (item.jednotka ? '/' + item.jednotka : '') : ''; break;
    case 'cenakg':
      txt = cenaPerKg > 0 ? cenaPerKg.toFixed(0) + ' Kč/kg' : '— Kč/kg'; break;
    case 'kod':
    case 'cislo':
      txt = item.cislo || ''; break;
    case 'alergeny':
      txt = item.alergeny ? '⚠ ' + item.alergeny.slice(0, 14) : '⚠ Alergeny'; break;
    case 'slozeni':
      txt = item.slozeni ? item.slozeni.slice(0, 18) + '…' : 'Složení…'; break;
    // 🥗 v2.9.302 — Nutriční hodnoty (na 100 g výrobku)
    case 'nutri':
      txt = (nutriPrvekText('nutri', item) || 'Nutri…').slice(0, 38); break;
    case 'nutri_kj':
      txt = nutriPrvekText('nutri_kj', item) || '— kJ'; break;
    case 'nutri_kcal':
      txt = nutriPrvekText('nutri_kcal', item) || '— kcal'; break;
    case 'nutri_tuky':
      txt = nutriPrvekText('nutri_tuky', item) || 'Tuky — g'; break;
    case 'nutri_sach':
      txt = nutriPrvekText('nutri_sach', item) || 'Sach — g'; break;
    case 'nutri_bilk':
      txt = nutriPrvekText('nutri_bilk', item) || 'Bilk — g'; break;
    case 'nutri_sul':
      txt = nutriPrvekText('nutri_sul', item) || 'Sůl — g'; break;
    case 'ean':
      txt = '||||||| ' + (item.ean || '8590000');
      break;
    case 'qr':
      return `<div style="position:absolute;left:${x}%;top:${y}%;width:${w}%;height:${h}%;${bg}background:#000;color:#fff;display:flex;align-items:center;justify-content:center;font-size:${fontSize}px">▦</div>`;
    case 'text':
      txt = (p.text || '').slice(0, 14); break;
    case 'datum':
      txt = new Date().toLocaleDateString('cs-CZ', { day: 'numeric', month: 'numeric' }); break;
    case 'logo':
      txt = '🥖'; break;
    case 'obrazek':
      return `<div style="position:absolute;left:${x}%;top:${y}%;width:${w}%;height:${h}%;background:#eee;display:flex;align-items:center;justify-content:center;font-size:${fontSize * 1.5}px;color:#aaa">🖼</div>`;
    case 'jednotka':
      txt = item.jednotka || 'ks'; break;
    case 'hmotnost':
      txt = hmotnost > 0 ? hmotnost + ' g' : '— g'; break;
    case 'badge':
      txt = p.text || 'BADGE'; break;
    default:
      txt = p.text || '';
  }

  return `<div class="sab-prvek" style="position:absolute;left:${x}%;top:${y}%;width:${w}%;height:${h}%;font-size:${fontSize}px;line-height:1;text-align:${align};${fw}${fst}${color}${bg}${padding}overflow:hidden;white-space:nowrap;text-overflow:ellipsis;display:flex;align-items:center;justify-content:${align === 'right' ? 'flex-end' : (align === 'center' ? 'center' : 'flex-start')}">${esc(txt)}</div>`;
}

// 🔧 AutoFit — projde všechny .sab-prvek a smrskne fontSize tak,
// aby text neprosakoval ze své boxu. Volat PO insertu HTML do DOM.
// Funguje i v CSS-transformované preview (offset/scroll měří unscaled dimenze).
function autoFitPrvky(rootEl) {
  if (!rootEl) return;
  const elements = rootEl.querySelectorAll ? rootEl.querySelectorAll('.sab-prvek') : [];
  elements.forEach(el => {
    // SVG (EAN, QR) má vlastní auto-scale — přeskočit
    if (el.querySelector('svg')) return;
    // Žádný text → přeskočit
    if (!(el.textContent || '').trim()) return;
    let size = parseFloat(getComputedStyle(el).fontSize) || 12;
    const minSize = 4;
    let guard = 60;
    // Shrink dokud text přesahuje box (s tolerancí 1px na rounding)
    while (guard-- > 0
        && size > minSize
        && (el.scrollWidth > el.offsetWidth + 1 || el.scrollHeight > el.offsetHeight + 1)) {
      size -= 0.5;
      el.style.fontSize = size + 'px';
    }
  });
}

// 🖼️ Mini preview šablony (pro picker) — PIXEL-PERFECT s tiskem + autofit
// Používá stitekHtmlSablona() pro identický rendering jako tisk, pak CSS transformuje
// celé to do požadovaného mini-rozměru. Po insertu se spustí autoFitPrvky.
// Pokud sablona má v `layout._foldHalf` true, přidá se vizuální linka přeložení v půlce.
function sablonaMiniPreviewHtml(t, width = 110) {
  if (!t) return '';
  // Parsuj layout (může být string z DB nebo objekt)
  let layout = t.layout;
  if (typeof layout === 'string') {
    try { layout = JSON.parse(layout); } catch { layout = {}; }
  }
  layout = layout || {};
  let prvky = layout.prvky || [];
  if (typeof prvky === 'string') {
    try { prvky = JSON.parse(prvky); } catch { prvky = []; }
  }
  if (!Array.isArray(prvky)) prvky = [];

  // Reálné rozměry štítku v mm (z layoutu, nebo z format_id, nebo fallback)
  let sirkaMm = parseFloat(layout.sirka_mm);
  let vyskaMm = parseFloat(layout.vyska_mm);
  if (!sirkaMm || !vyskaMm) {
    const fmt = STITKY_FORMATY.find(f => f.id === t.format_id) || STITKY_FORMATY.find(f => f.id === stState.formatId) || STITKY_FORMATY[0];
    sirkaMm = sirkaMm || parseFloat(fmt.w) || 50;
    vyskaMm = vyskaMm || parseFloat(fmt.h) || 30;
  }

  // Pokud nic k vykreslení, ukaž placeholder
  if (prvky.length === 0) {
    const h = Math.round(width * (vyskaMm / sirkaMm));
    return `<div class="sab-mini-preview" style="position:relative;width:${width}px;height:${h}px;background:#fafaf9;border:1px dashed #d4d4d8;border-radius:4px;display:inline-flex;align-items:center;justify-content:center;color:#9ca3af;font-size:11px;font-style:italic;flex-shrink:0">prázdná</div>`;
  }

  // Vzorová data — bohatá, aby každý design měl co ukázat
  const sample = {
    nazev: 'Bageta selská',
    cena_bez_dph: 39,
    dph: 12,
    jednotka: 'ks',
    hmotnost_g: 250,
    obsah: 250,
    obsah_jednotka: 'g',
    cislo: 'V-001',
    ean: '8590000000017',
    alergeny: 'lepek, vejce',
    slozeni: 'pšeničná mouka, voda, droždí, sůl, slunečnice',
    popis: 'Tradiční česká bageta z kvalitních surovin',
  };

  // Použij REÁLNÝ render z tisku — stejný HTML jako bude na cenovce
  const sablonaPure = { id: t.id, nazev: t.nazev, layout: { prvky, sirka_mm: sirkaMm, vyska_mm: vyskaMm } };
  const stickerHtml = stitekHtmlSablona(sample, sablonaPure);

  // Scale: 1mm = 3.7795px @ 96dpi. Reálná šířka v px → škálovat na width.
  const mmPx = 3.7795275591;
  const realW = sirkaMm * mmPx;
  const realH = vyskaMm * mmPx;
  const scale = width / realW;
  const scaledH = Math.round(realH * scale);

  // Volitelná fold-line (pro Appek cenovky se sklady) — přerušovaná čára v půlce
  const foldHalfFlag = !!(layout._foldHalf || t._foldHalf);
  const foldLine = foldHalfFlag
    ? `<div style="position:absolute;left:0;right:0;top:50%;height:0;border-top:1px dashed #BA7517;pointer-events:none;z-index:5"></div>
       <div style="position:absolute;left:4px;top:calc(50% - 7px);font-size:8px;font-weight:700;color:#BA7517;background:rgba(255,255,255,0.85);padding:0 4px;border-radius:2px;pointer-events:none;z-index:5">✂ sklad</div>`
    : '';

  return `
    <div class="sab-mini-preview" style="position:relative;width:${width}px;height:${scaledH}px;background:#fff;border:1px solid #d4d4d8;border-radius:4px;overflow:hidden;flex-shrink:0;box-shadow:0 1px 2px rgba(0,0,0,0.04)">
      <div class="sab-mini-inner" style="position:absolute;left:0;top:0;width:${realW}px;height:${realH}px;transform:scale(${scale});transform-origin:0 0">
        ${stickerHtml}
      </div>
      ${foldLine}
    </div>
  `;
}

// 🎨 Picker — toggle panel s grid miniatur
window.stTogglePickerSablona = function(forceState) {
  const open = forceState !== undefined ? forceState : !stState._sablonaPickerOpen;
  stState._sablonaPickerOpen = open;
  const panel = document.getElementById('sab-picker-panel');
  const btn = document.getElementById('sab-picker-btn');
  if (panel) panel.style.display = open ? 'block' : 'none';
  if (btn) btn.classList.toggle('open', open);
  // outside click handler + autofit po otevření
  if (open) {
    setTimeout(() => {
      // 🔧 Spustí autofit na všechny mini-preview prvky v panelu
      if (panel) autoFitPrvky(panel);
      document.addEventListener('click', _sabPickerOutsideClick, { capture: true });
      document.addEventListener('keydown', _sabPickerEscClose);
    }, 0);
  } else {
    document.removeEventListener('click', _sabPickerOutsideClick, { capture: true });
    document.removeEventListener('keydown', _sabPickerEscClose);
  }
};
function _sabPickerOutsideClick(e) {
  const wrap = document.getElementById('sab-picker-wrap');
  if (wrap && !wrap.contains(e.target)) stTogglePickerSablona(false);
}
function _sabPickerEscClose(e) {
  if (e.key === 'Escape') stTogglePickerSablona(false);
}
window.stPickSablona = function(idStr) {
  stTogglePickerSablona(false);
  stSetSablona(idStr);
};

// Zoom controls — zvětší/zmenší grid přímo na stránce
window.stZoomChange = function(delta) {
  const cur = stState.gridZoom || 1;
  stState.gridZoom = Math.max(0.5, Math.min(3, Math.round((cur + delta) * 100) / 100));
  stRefreshGridPreview();
};
window.stZoomReset = function() {
  stState.gridZoom = 1;
  stRefreshGridPreview();
};

window.stSetStartPos = function(n) {
  stState.startPos = Math.max(1, parseInt(n) || 1);
  renderStitky();
};

function renderStitkyExpedicni() {
  const s = stState;
  const list = s.obj || [];
  return `
    <div class="card-block" style="padding:14px">
      <div class="filters" style="margin-bottom:12px">
        <div class="filter-dates-row">
          <label class="filter-date-wrap">
            <span>Od:</span>
            <input class="filter-input" type="date" value="${s.od}" onchange="stState.od = this.value;renderStitky()">
          </label>
          <label class="filter-date-wrap">
            <span>Do:</span>
            <input class="filter-input" type="date" value="${s.dto}" onchange="stState.dto = this.value;renderStitky()">
          </label>
        </div>
        <button class="btn-secondary" onclick="stToggleVse('obj', true)">Vybrat vše</button>
        <button class="btn-secondary" onclick="stToggleVse('obj', false)">Zrušit výběr</button>
      </div>

      ${list.length === 0 ? '<div class="empty-state">V daném období nejsou žádné objednávky</div>' : `
        <!-- Desktop: tabulka -->
        <div class="desktop-only-block">
          <table class="table table-selectable" style="margin:0">
            <thead>
              <tr>
                <th class="col-check"></th>
                <th>Číslo</th>
                <th>Odběratel / pobočka</th>
                <th>Dodání</th>
                <th>Stav</th>
                <th class="num">Pol.</th>
                <th class="num">Částka</th>
              </tr>
            </thead>
            <tbody>
              ${list.map((o) => {
                const sel = stState.vybraneObj.has(o.id);
                return `
                  <tr class="row-clickable ${sel ? 'is-selected' : ''}" onclick="stToggleObj(${o.id})">
                    <td class="col-check" onclick="event.stopPropagation();">
                      <input type="checkbox" ${sel ? 'checked' : ''} onchange="stToggleObj(${o.id})">
                    </td>
                    <td><strong>${esc(o.cislo)}</strong></td>
                    <td>
                      <div>${esc(o.odberatel_nazev)}</div>
                      ${o.misto_nazev ? `<div style="font-size:11px;color:var(--text-3);margin-top:2px">📍 ${esc(o.misto_nazev)}</div>` : ''}
                    </td>
                    <td>${fmtDate(o.datum_dodani)}</td>
                    <td><span class="status ${o.stav}">${statusLabel(o.stav)}</span></td>
                    <td class="num">${o.pocet_polozek || 0}</td>
                    <td class="num">${fmt(o.castka_celkem)}</td>
                  </tr>
                `;
              }).join('')}
            </tbody>
          </table>
        </div>

        <!-- Mobile: kompaktní řádky -->
        <div class="mobile-only-block st-rows">
          ${list.map((o) => {
            const sel = stState.vybraneObj.has(o.id);
            return `
              <label class="st-row ${sel ? 'is-selected' : ''}">
                <input type="checkbox" ${sel ? 'checked' : ''} onchange="stToggleObj(${o.id})">
                <div class="st-row-main">
                  <div class="st-row-line1">
                    <strong>${esc(o.cislo)}</strong>
                    <span class="st-row-dot">·</span>
                    <span class="st-row-nazev">${esc(o.misto_nazev || o.odberatel_nazev)}</span>
                  </div>
                  <div class="st-row-line2">
                    <span>🚚 ${fmtDate(o.datum_dodani)}</span>
                    <span>📦 ${o.pocet_polozek || 0} pol.</span>
                    <span class="status ${o.stav}">${statusLabel(o.stav)}</span>
                  </div>
                </div>
              </label>
            `;
          }).join('')}
        </div>
      `}
    </div>
  `;
}

function renderStitkyMoje() {
  const s = stState;
  const badgeOpts = [
    { k: 'vse', l: '📚 Vše' },
    { k: 's',   l: '🔖 S badgem' },
    { k: 'bez', l: '○ Bez badge' },
  ];
  const aktBadge = s.mojeBadge || 'vse';
  return `
    <div class="card-block" style="padding:14px">
      <div style="display:flex;gap:12px;flex-wrap:wrap;align-items:center;margin-bottom:14px">
        <input class="form-input" type="search" placeholder="🔍 Hledat (název, kód)..." value="${esc(s.mojeQ || '')}" oninput="stState.mojeQ = this.value;document.getElementById('st-moje-list').innerHTML = stRenderMojeList()" style="flex:1;min-width:280px;font-size:18px;padding:14px 18px;height:56px;font-weight:500">
        <label style="display:flex;align-items:center;gap:10px;font-size:17px;font-weight:600;white-space:nowrap;padding:0 12px;cursor:pointer">
          <input type="checkbox" ${s.mojeJenVybrane ? 'checked' : ''} onchange="stState.mojeJenVybrane = this.checked;document.getElementById('st-moje-list').innerHTML = stRenderMojeList()" style="width:24px;height:24px;cursor:pointer;accent-color:var(--primary)">
          Jen vybrané <span style="color:var(--text-3);font-weight:500">(${s.vybraneMoje.size})</span>
        </label>
      </div>

      <!-- Badge filter pillsy -->
      <div style="display:flex;gap:8px;flex-wrap:wrap;margin-bottom:16px">
        ${badgeOpts.map(o => `
          <button class="period-tab ${aktBadge === o.k ? 'active' : ''}" onclick="stState.mojeBadge='${o.k}';document.getElementById('st-moje-list').innerHTML = stRenderMojeList();renderStitky()" style="font-size:16px;padding:12px 20px;font-weight:700;min-height:48px">${o.l}</button>
        `).join('')}
      </div>

      <div style="display:flex;gap:8px;flex-wrap:wrap;align-items:center;margin-bottom:12px;padding-top:8px;border-top:1px dashed var(--border)">
        <button class="btn-secondary" onclick="stToggleVse('moje', true)" style="font-size:13px">Vybrat vše ze zobrazených</button>
        <button class="btn-secondary" onclick="stToggleVse('moje', false)" style="font-size:13px">Zrušit výběr</button>
        <div style="flex:1"></div>
        <span style="font-size:13px;color:var(--text-3)">Vybráno: <strong style="color:var(--primary-dark)">${s.vybraneMoje.size}</strong></span>
        <button class="btn-secondary" onclick="stOtevritStitekZVyrobku()" style="font-size:14px" title="Vyplnit nový štítek z některého výrobku">📋 Z výrobku</button>
        <button class="btn-secondary" onclick="stCenovkyZVsechVyrobku()" style="font-size:14px" title="Hromadně vytvoří cenovku ze všech aktivních výrobků (vynechá ty co už mají vlastní cenovku se stejným číslem/EAN)">📦 Načíst všechny z výrobků</button>
        <button class="btn-primary btn-green btn-big-action" onclick="otevritMujStitek()" style="font-size:18px !important;font-weight:800 !important;padding:18px 32px !important;min-height:64px !important;border-radius:12px !important;letter-spacing:0.3px !important">+ Nový štítek</button>
      </div>

      <div id="st-moje-list">${stRenderMojeList()}</div>
    </div>
  `;
}

function stRenderMojeList() {
  const s = stState;
  const q = (s.mojeQ || '').toLowerCase();
  const badge = s.mojeBadge || 'vse';
  const jenVybrane = !!s.mojeJenVybrane;
  const list = (s.mojeStitky || []).filter(m => {
    if (jenVybrane && !s.vybraneMoje.has(m.id)) return false;
    if (badge === 's' && !(m.badge || m.badge_text)) return false;
    if (badge === 'bez' && (m.badge || m.badge_text)) return false;
    if (q && !((m.nazev || '').toLowerCase().includes(q) || (m.cislo || '').toString().toLowerCase().includes(q))) return false;
    return true;
  });
  return `
    ${list.length === 0 ? `
        <div class="empty-state" style="padding:32px;text-align:center;color:var(--text-3)">
          ${(s.mojeStitky || []).length === 0
            ? '🏷️ Zatím žádné vlastní štítky. Klepněte na "+ Nový štítek".'
            : 'Žádný štítek neodpovídá vyhledávání.'}
        </div>
      ` : `
        <!-- Desktop -->
        <div class="desktop-only-block">
          <table class="table table-selectable" style="margin:0">
            <thead>
              <tr>
                <th class="col-check"></th>
                <th>Název</th>
                <th>Č.</th>
                <th class="num">Cena s DPH</th>
                <th class="num">Hmotnost</th>
                <th>EAN</th>
                <th>Badge</th>
                <th></th>
              </tr>
            </thead>
            <tbody>
              ${list.map(m => {
                const sel = stState.vybraneMoje.has(m.id);
                return `
                  <tr class="row-clickable ${sel ? 'is-selected' : ''}" onclick="stToggleMoje(${m.id})">
                    <td class="col-check" onclick="event.stopPropagation();">
                      <input type="checkbox" ${sel ? 'checked' : ''} onchange="stToggleMoje(${m.id})">
                    </td>
                    <td><strong>${esc(m.nazev)}</strong></td>
                    <td>${esc(m.cislo || '')}</td>
                    <td class="num">${fmt(m.cena_s_dph)}</td>
                    <td class="num">${m.hmotnost_g ? m.hmotnost_g + ' g' : '—'}</td>
                    <td style="font-family:monospace;font-size:11px">${esc(m.ean || '—')}</td>
                    <td>${m.badge_text ? esc(m.badge_text) : (m.badge ? esc(m.badge) : '—')}</td>
                    <td onclick="event.stopPropagation();" style="white-space:nowrap;text-align:right">
                      <button class="btn-secondary" style="font-size:12px;padding:5px 10px" onclick="otevritMujStitek(${m.id})" title="Upravit cenovku ručně">✏️ Upravit</button>
                      <button class="btn-secondary" style="font-size:12px;padding:5px 10px;background:linear-gradient(180deg,#fef3c7,#fde68a);border-color:#f59e0b" onclick="stitekNacistZProduktu(${m.id})" title="Nahradit data z aktuálního výrobku (cena, EAN, hmotnost, alergeny, složení)">🔄 Načíst z produktu</button>
                      <button class="btn-danger" style="font-size:12px;padding:5px 10px" onclick="smazatMujStitek(${m.id})">×</button>
                    </td>
                  </tr>
                `;
              }).join('')}
            </tbody>
          </table>
        </div>
        <!-- Mobile řádky -->
        <div class="mobile-only-block st-rows">
          ${list.map(m => {
            const sel = stState.vybraneMoje.has(m.id);
            return `
              <label class="st-row ${sel ? 'is-selected' : ''}">
                <input type="checkbox" ${sel ? 'checked' : ''} onchange="stToggleMoje(${m.id})">
                <div class="st-row-main">
                  <div class="st-row-line1">
                    ${m.cislo ? `<strong>${esc(m.cislo)}</strong><span class="st-row-dot">·</span>` : ''}
                    <span class="st-row-nazev">${esc(m.nazev)}</span>
                  </div>
                  <div class="st-row-line2">
                    <span>${fmt(m.cena_s_dph)} / ${esc(m.jednotka || 'ks')}</span>
                    ${m.hmotnost_g ? `<span>⚖️ ${m.hmotnost_g} g</span>` : ''}
                    ${m.badge_text || m.badge ? `<span>🔖 ${esc(m.badge_text || m.badge)}</span>` : ''}
                  </div>
                </div>
                <div onclick="event.stopPropagation()" style="display:flex;gap:4px">
                  <button class="btn-secondary" style="font-size:11px;padding:4px 8px" onclick="otevritMujStitek(${m.id})" title="Upravit">✏️</button>
                  <button class="btn-secondary" style="font-size:11px;padding:4px 8px" onclick="stitekNacistZProduktu(${m.id})" title="Načíst z produktu">🔄</button>
                </div>
              </label>
            `;
          }).join('')}
        </div>
      `}
  `;
}

window.otevritMujStitek = async function(id = null) {
  let m = {
    id: null, nazev: '', cislo: '', ean: '',
    cena_s_dph: 0, sazba_dph: 12, jednotka: 'ks',
    hmotnost_g: '', obsah: '', obsah_jednotka: '',
    slozeni: '', alergeny: '',
    badge: '', badge_text: '',
  };
  if (id) {
    try {
      m = await api(`admin_moje_stitky.php?id=${id}`);
    } catch (e) { return alert('Chyba: ' + e.message); }
  }

  // Backward compat: pokud máme jen hmotnost_g, předvyplň obsah
  if (!m.obsah && m.hmotnost_g) {
    m.obsah = m.hmotnost_g;
    m.obsah_jednotka = 'g';
  }

  openModal(id ? `✏️ Upravit štítek` : '+ Nový štítek', `
    <!-- 📋 Vyplnit z výrobku — searchovatelný picker -->
    <div class="ms-fill-from-vyrobek-bar">
      <label class="form-label" style="margin:0;font-size:12px;color:var(--text-2)">📋 Vyplnit z výrobku</label>
      <div class="ms-vyr-picker" style="position:relative;flex:1;min-width:240px">
        <input type="search" class="form-input" id="ms-vyr-search" placeholder="Začni psát název výrobku..."
               autocomplete="off" oninput="msVyrPickerSearch(this.value)" onfocus="msVyrPickerSearch(this.value)" onblur="setTimeout(()=>msVyrPickerHide(),200)">
        <div id="ms-vyr-results" class="ms-vyr-results" style="display:none"></div>
      </div>
      <button type="button" class="btn-secondary" id="ms-vyr-clear" onclick="msVyrPickerClear()" style="font-size:12px;display:none">✕</button>
      <span id="ms-vyr-current" class="ms-vyr-current" style="display:none;font-size:12px;color:var(--success-text);font-weight:600"></span>
    </div>

    <div class="form-grid form-grid-tight">
      <div class="full">
        <label class="form-label">Název *</label>
        <input class="form-input" id="ms-nazev" value="${esc(m.nazev || '')}" required placeholder="např. Domácí marmeláda jahodová">
      </div>
      <div>
        <label class="form-label">Číslo / kód</label>
        <input class="form-input" id="ms-cislo" value="${esc(m.cislo || '')}" placeholder="volitelné">
      </div>
      <div>
        <label class="form-label">EAN-13 <span style="color:var(--text-3);font-weight:400;font-size:11px">(volitelné)</span></label>
        <input class="form-input" id="ms-ean" value="${esc(m.ean || '')}" maxlength="13" placeholder="13 číslic">
      </div>
      <div>
        <label class="form-label">Cena s DPH (Kč) *</label>
        <input class="form-input" id="ms-cena" type="number" step="0.01" min="0" value="${m.cena_s_dph || ''}" required oninput="msPrepocet()">
      </div>
      <div>
        <label class="form-label">Sazba DPH</label>
        <select class="form-input" id="ms-dph">
          <option value="0"  ${m.sazba_dph == 0  ? 'selected' : ''}>0 %</option>
          <option value="12" ${m.sazba_dph == 12 ? 'selected' : ''}>12 %</option>
          <option value="21" ${m.sazba_dph == 21 ? 'selected' : ''}>21 %</option>
        </select>
      </div>
      <div class="full ms-jed-row">
        <div>
          <label class="form-label">Prodáváme po</label>
          <select class="form-input" id="ms-jed" onchange="msPrepocet()">
            <option value="ks"     ${m.jednotka === 'ks'     ? 'selected' : ''}>ks (kus)</option>
            <option value="kg"     ${m.jednotka === 'kg'     ? 'selected' : ''}>kg</option>
            <option value="l"      ${m.jednotka === 'l'      ? 'selected' : ''}>l (litr)</option>
            <option value="balení" ${m.jednotka === 'balení' ? 'selected' : ''}>balení</option>
          </select>
        </div>
        <div>
          <label class="form-label">Obsah balení</label>
          <div style="display:flex;gap:4px;align-items:stretch">
            <input class="form-input" id="ms-obsah" type="number" step="0.01" min="0" value="${m.obsah || ''}" placeholder="500" style="flex:1;min-width:0" oninput="msPrepocet()">
            <select class="form-input" id="ms-obsah-jed" style="width:60px;flex-shrink:0" onchange="msPrepocet()">
              <option value=""   ${!m.obsah_jednotka ? 'selected' : ''}>—</option>
              <option value="g"  ${m.obsah_jednotka === 'g'  ? 'selected' : ''}>g</option>
              <option value="kg" ${m.obsah_jednotka === 'kg' ? 'selected' : ''}>kg</option>
              <option value="ml" ${m.obsah_jednotka === 'ml' ? 'selected' : ''}>ml</option>
              <option value="l"  ${m.obsah_jednotka === 'l'  ? 'selected' : ''}>l</option>
            </select>
          </div>
        </div>
        <div>
          <label class="form-label">Cena za jednotku</label>
          <div id="ms-prepocet" style="padding:7px 10px;background:#FFF8E5;border:1px solid #E8C988;border-radius:6px;font-size:13px;color:#854F0B;font-weight:700;min-height:35px;display:flex;align-items:center;line-height:1.2">
            — vyplň cenu a obsah —
          </div>
        </div>
      </div>
      <div class="full"><small style="color:var(--text-3);font-size:11px;display:block;margin-top:-6px">„Prodáváme po" se vytiskne za cenou (např. „/ ks"). „Obsah balení" slouží pro automatický přepočet ceny za 1 kg / 1 l.</small></div>
      <div class="full">
        <label class="form-label">Složení</label>
        <textarea class="form-textarea" id="ms-sloz" rows="2">${esc(m.slozeni || '')}</textarea>
      </div>
      <div class="full">
        <label class="form-label">Alergeny</label>
        <input class="form-input" id="ms-aler" value="${esc(m.alergeny || '')}" placeholder="lepek, mléko, vejce...">
      </div>
      <div>
        <label class="form-label">Badge (rohový proužek)</label>
        <select class="form-input" id="ms-badge">
          <option value="">— žádný —</option>
          <option value="novinka" ${m.badge === 'novinka' ? 'selected' : ''}>🆕 Novinka</option>
          <option value="akce"    ${m.badge === 'akce'    ? 'selected' : ''}>🔥 Akce</option>
          <option value="doporuc" ${m.badge === 'doporuc' ? 'selected' : ''}>⭐ Doporučujeme</option>
          <option value="bio"     ${m.badge === 'bio'     ? 'selected' : ''}>🌱 BIO</option>
          <option value="limited" ${m.badge === 'limited' ? 'selected' : ''}>⏳ Limit. edice</option>
        </select>
      </div>
      <div>
        <label class="form-label">Vlastní text badge</label>
        <input class="form-input" id="ms-bt" value="${esc(m.badge_text || '')}" placeholder="přepíše preset (např. -20%)">
      </div>
    </div>
    <div class="form-actions">
      ${id ? `<div class="form-actions-icons-row"><button class="btn-danger-corner" onclick="smazatMujStitek(${id})" title="Smazat štítek" aria-label="Smazat štítek">🗑️</button></div>` : ''}
      <div style="flex:1"></div>
      <button class="btn-secondary" onclick="closeModal()">Zrušit</button>
      <button class="btn-primary btn-green" onclick="ulozitMujStitek(${id || 'null'})">${id ? 'Uložit změny' : 'Vytvořit štítek'}</button>
    </div>
  `, 'wide');

  // Inicializační přepočet
  setTimeout(msPrepocet, 50);
};

window.msPrepocet = function() {
  const cena = parseFloat(document.getElementById('ms-cena')?.value) || 0;
  const obsah = parseFloat(document.getElementById('ms-obsah')?.value) || 0;
  const obsahJed = document.getElementById('ms-obsah-jed')?.value || '';
  const box = document.getElementById('ms-prepocet');
  if (!box) return;

  // Převod na základní jednotku
  let kg = 0, l = 0;
  if (obsahJed === 'g') kg = obsah / 1000;
  else if (obsahJed === 'kg') kg = obsah;
  else if (obsahJed === 'ml') l = obsah / 1000;
  else if (obsahJed === 'l')  l = obsah;

  if (cena <= 0 || (kg <= 0 && l <= 0)) {
    box.innerHTML = '<span style="font-weight:400;color:var(--text-3)">— vyplň cenu a obsah —</span>';
    return;
  }

  let html = '';
  if (kg > 0) {
    const cenaKg = cena / kg;
    html = `→ <strong>${cenaKg.toFixed(2).replace('.', ',')} Kč / kg</strong>`;
  } else if (l > 0) {
    const cenaL = cena / l;
    html = `→ <strong>${cenaL.toFixed(2).replace('.', ',')} Kč / l</strong>`;
  }
  box.innerHTML = html;
};

// ════════════════════════════════════════════════════════════
// 📋 Vytvořit nový štítek z výrobku — otevře modal "+ Nový štítek"
// a po renderu auto-zafocusuje search a uživatel vybere výrobek.
// ════════════════════════════════════════════════════════════
window.stOtevritStitekZVyrobku = function() {
  // Otevři prázdný modal pro nový štítek
  otevritMujStitek(null);
  // Po vyrenderování modal — zafocusuj search a otevři dropdown
  setTimeout(() => {
    const s = document.getElementById('ms-vyr-search');
    if (s) {
      s.focus();
      msVyrPickerSearch('');  // ukáže prvních 50 výrobků
    }
  }, 220);
};

// ════════════════════════════════════════════════════════════
// 📦 Hromadné vytvoření cenovek ze VŠECH aktivních výrobků
// (skipne ty, co už existují s totožným číslem nebo EAN)
// ════════════════════════════════════════════════════════════
window.stCenovkyZVsechVyrobku = async function() {
  const vyr = (stState.vyrobky || []).filter(v => v.aktivni);
  const existing = (stState.mojeStitky || []);
  const existsByCislo = new Set(existing.map(m => (m.cislo || '').toString().trim()).filter(Boolean));
  const existsByEan   = new Set(existing.map(m => (m.ean   || '').toString().trim()).filter(Boolean));

  const noviKandidati = vyr.filter(v => {
    if (v.cislo && existsByCislo.has(v.cislo.toString().trim())) return false;
    if (v.ean   && existsByEan.has(v.ean.toString().trim()))     return false;
    return true;
  });

  if (vyr.length === 0) {
    alert('🤷 Žádné aktivní výrobky.');
    return;
  }

  const msg = `Hromadné vytvoření cenovek:\n\n`
            + `• Aktivních výrobků: ${vyr.length}\n`
            + `• Už mají cenovku (skip): ${vyr.length - noviKandidati.length}\n`
            + `• Bude vytvořeno nových: ${noviKandidati.length}\n\n`
            + `Pokračovat?`;
  if (!(await confirmDialog({ msg: msg, danger: false }))) return;

  if (noviKandidati.length === 0) {
    alert('✅ Všechny aktivní výrobky už mají vlastní cenovku.');
    return;
  }

  let ok = 0, err = 0;
  const errors = [];
  for (const v of noviKandidati) {
    const payload = {
      nazev:          v.nazev,
      cislo:          v.cislo || null,
      ean:            v.ean || null,
      cena_s_dph:     +((v.cena_bez_dph || 0) * (1 + ((v.sazba_dph ?? v.dph ?? 0) / 100))).toFixed(2),
      sazba_dph:      v.sazba_dph ?? v.dph ?? 12,
      jednotka:       v.jednotka || 'ks',
      hmotnost_g:     v.hmotnost_g || null,
      obsah:          v.obsah || null,
      obsah_jednotka: v.obsah_jednotka || null,
      slozeni:        v.slozeni || null,
      alergeny:       v.alergeny || null,
    };
    try {
      await api('admin_moje_stitky.php', { method: 'POST', body: JSON.stringify(payload) });
      ok++;
    } catch (e) {
      err++;
      errors.push(`${v.nazev}: ${e.message}`);
    }
  }

  // Refresh list
  try {
    stState.mojeStitky = await api('admin_moje_stitky.php');
  } catch (e) { /* ignore */ }
  if (typeof navigate === 'function') navigate('stitky');

  const detail = errors.length ? `\n\nChyby:\n${errors.slice(0, 5).join('\n')}${errors.length > 5 ? `\n…(${errors.length - 5} dalších)` : ''}` : '';
  alert(t('bulk_done_summary', { ok, err, detail }));
};

// ════════════════════════════════════════════════════════════
// 📋 Vyplnit z výrobku — searchovatelný picker v modalu Moje štítky
// ════════════════════════════════════════════════════════════
window.msVyrPickerSearch = function(q) {
  const box = document.getElementById('ms-vyr-results');
  if (!box) return;
  const list = stState.vyrobky || [];
  const query = (q || '').trim().toLowerCase();
  // Filtruj
  const matches = query
    ? list.filter(v => (v.nazev || '').toLowerCase().includes(query)
                    || (v.cislo || '').toString().toLowerCase().includes(query)
                    || (v.ean || '').toString().toLowerCase().includes(query))
    : list.slice(0, 50); // bez query: prvních 50
  // Render
  if (matches.length === 0) {
    box.innerHTML = '<div class="ms-vyr-empty">Žádný výrobek</div>';
    box.style.display = 'block';
    return;
  }
  box.innerHTML = matches.slice(0, 30).map(v => `
    <button type="button" class="ms-vyr-item" onmousedown="event.preventDefault();msVyrPickerPick(${v.id})">
      <span class="ms-vyr-item-cislo">${esc(v.cislo || '—')}</span>
      <span class="ms-vyr-item-nazev">${esc(v.nazev)}</span>
      <span class="ms-vyr-item-cena">${fmt(v.cena_bez_dph)} ${esc(v.jednotka || 'ks')}</span>
    </button>
  `).join('') + (matches.length > 30 ? `<div class="ms-vyr-more">… a další ${matches.length - 30}, upřesni hledání</div>` : '');
  box.style.display = 'block';
};

window.msVyrPickerHide = function() {
  const box = document.getElementById('ms-vyr-results');
  if (box) box.style.display = 'none';
};

window.msVyrPickerClear = function() {
  document.getElementById('ms-vyr-search').value = '';
  document.getElementById('ms-vyr-current').style.display = 'none';
  document.getElementById('ms-vyr-clear').style.display = 'none';
  msVyrPickerHide();
};

window.msVyrPickerPick = async function(vyrobekId) {
  msVyrPickerHide();
  try {
    // Načti detail výrobku (kvůli složení, alergenům atp. pokud nejsou v list)
    const v = await api(`admin_vyrobky.php?id=${vyrobekId}`);

    // Vypočti cenu s DPH (na štítku se zobrazuje s DPH)
    const dphPct = parseFloat(v.sazba_dph || v.dph_sazba || 12);
    const cenaBez = parseFloat(v.cena_bez_dph || 0);
    const cenaSDPH = +(cenaBez * (1 + dphPct / 100)).toFixed(2);

    // Helper — nastavit hodnotu pole jen pokud aktuálně prázdné NEBO když uživatel souhlasí s přepsáním
    const setF = (id, val) => {
      const el = document.getElementById(id);
      if (!el) return;
      if (val !== undefined && val !== null && val !== '') el.value = val;
    };
    setF('ms-nazev', v.nazev || '');
    setF('ms-cislo', v.cislo || '');
    setF('ms-ean',   (v.ean || '').toString().replace(/\D/g, ''));
    setF('ms-cena',  cenaSDPH || '');
    // DPH
    const dphSel = document.getElementById('ms-dph');
    if (dphSel) dphSel.value = String(dphPct);
    // Jednotka — pokud je v měrných jednotkách kg/l, použij to, jinak ks
    const jed = (v.jednotka || 'ks').toLowerCase();
    const jedSel = document.getElementById('ms-jed');
    if (jedSel) {
      const allowed = ['ks','kg','l','balení'];
      jedSel.value = allowed.includes(jed) ? jed : 'ks';
    }
    // Obsah balení (z hmotnost_g pokud je)
    if (v.hmotnost_g) {
      setF('ms-obsah', v.hmotnost_g);
      const obSel = document.getElementById('ms-obsah-jed');
      if (obSel) obSel.value = 'g';
    }
    // Složení a alergeny — z HACCP nebo z výrobek vlastních polí
    setF('ms-sloz', v.slozeni || v.popis || '');
    setF('ms-aler', v.alergeny || '');

    // Spustit přepočet
    if (typeof msPrepocet === 'function') msPrepocet();

    // UI feedback
    const cur = document.getElementById('ms-vyr-current');
    if (cur) {
      cur.textContent = `✓ Vyplněno z: ${v.nazev}`;
      cur.style.display = 'inline';
    }
    const clr = document.getElementById('ms-vyr-clear');
    if (clr) clr.style.display = 'inline-flex';
    // Vyčisti search input ale ponech vybraný řádek
    const s = document.getElementById('ms-vyr-search');
    if (s) s.value = '';
  } catch (e) {
    alert('Chyba načtení výrobku: ' + e.message);
  }
};

window.ulozitMujStitek = async function(id) {
  const obsah = parseFloat(document.getElementById('ms-obsah').value) || null;
  const obsahJed = document.getElementById('ms-obsah-jed').value || null;
  // Pro zpětnou kompatibilitu spočti i hmotnost_g (g/kg → g)
  let hmG = null;
  if (obsah && obsahJed === 'g')  hmG = Math.round(obsah);
  if (obsah && obsahJed === 'kg') hmG = Math.round(obsah * 1000);

  const data = {
    id: id || undefined,
    nazev: document.getElementById('ms-nazev').value.trim(),
    cislo: document.getElementById('ms-cislo').value || null,
    ean: (document.getElementById('ms-ean').value || '').replace(/\D/g, '') || null,
    cena_s_dph: parseFloat(document.getElementById('ms-cena').value) || 0,
    sazba_dph: parseFloat(document.getElementById('ms-dph').value) || 12,
    jednotka: document.getElementById('ms-jed').value || 'ks',
    hmotnost_g: hmG,
    obsah: obsah,
    obsah_jednotka: obsahJed,
    slozeni: document.getElementById('ms-sloz').value || null,
    alergeny: document.getElementById('ms-aler').value || null,
    badge: document.getElementById('ms-badge').value || null,
    badge_text: document.getElementById('ms-bt').value || null,
  };
  if (!data.nazev) return alert('Vyplňte název');
  try {
    await api('admin_moje_stitky.php', {
      method: id ? 'PUT' : 'POST',
      body: JSON.stringify(data),
    });
    closeModal();
    // Refresh list
    stState.mojeStitky = [];
    renderStitky();
  } catch (e) { alert('Chyba: ' + e.message); }
};

window.smazatMujStitek = async function(id) {
  if (!await confirmDelete2x('tento vlastní štítek')) return;
  try {
    await api(`admin_moje_stitky.php?id=${id}`, { method: 'DELETE' });
    closeModal();
    stState.vybraneMoje.delete(id);
    stState.mojeStitky = [];
    renderStitky();
  } catch (e) { alert('Chyba: ' + e.message); }
};

/**
 * 🔄 Načte data z výrobku do existující cenovky (přepíše: cena, EAN, hmotnost, alergeny, složení, sazba DPH)
 * Zachovává: badge, layout, vlastní text
 */
window.stitekNacistZProduktu = async function(stitekId) {
  // 1. Načti existující cenovku
  let stitek;
  try {
    stitek = await api(`admin_moje_stitky.php?id=${stitekId}`);
  } catch (e) {
    return alert('Chyba načtení cenovky: ' + e.message);
  }

  // 2. Načti seznam výrobků (cache pokud už máme)
  let vyrobky = stState.vyrobky;
  if (!vyrobky || vyrobky.length === 0) {
    try {
      const r = await api('admin_vyrobky.php');
      vyrobky = (r && Array.isArray(r.vyrobky)) ? r.vyrobky : (Array.isArray(r) ? r : []);
      stState.vyrobky = vyrobky;
    } catch (e) {
      return alert('Chyba načtení výrobků: ' + e.message);
    }
  }

  // 3. Otevři modal s výběrem produktu
  openModal(`🔄 Načíst z produktu — Cenovka "${esc(stitek.nazev || '?')}"`, `
    <p style="font-size:13px;color:var(--text-3);margin:0 0 12px">
      Vyber výrobek, ze kterého se převezmou data: <strong>cena, EAN, hmotnost, alergeny, složení, sazba DPH</strong>.<br>
      Zachová se: <em>badge, název cenovky, layout</em>.
    </p>
    <input type="search" class="form-input" id="snzp-search" placeholder="🔍 Hledat výrobek (název, kód)…" oninput="window._snzpFilter(this.value)" style="margin-bottom:10px;font-size:15px;padding:10px 14px" autofocus>
    <div id="snzp-list" style="max-height:50vh;overflow-y:auto;border:1px solid var(--border);border-radius:8px;padding:4px"></div>
    <div class="form-actions" style="margin-top:14px">
      <button class="btn-secondary" onclick="closeModal()">Zrušit</button>
    </div>
  `);

  // 4. Render výrobky
  const renderList = (q = '') => {
    const filtered = vyrobky.filter(v => {
      if (!q) return true;
      const qq = q.toLowerCase();
      return (v.nazev || '').toLowerCase().includes(qq) ||
             (v.cislo || '').toString().toLowerCase().includes(qq);
    });
    const host = document.getElementById('snzp-list');
    if (!host) return;
    if (filtered.length === 0) {
      host.innerHTML = '<div style="padding:24px;text-align:center;color:var(--text-3)">Žádný výrobek neodpovídá hledání.</div>';
      return;
    }
    host.innerHTML = filtered.slice(0, 50).map(v => `
      <div style="display:flex;align-items:center;justify-content:space-between;padding:10px 12px;cursor:pointer;border-radius:6px;border-bottom:1px solid var(--border)"
           onclick="window._snzpApply(${stitekId}, ${v.id})"
           onmouseover="this.style.background='var(--surface-2)'"
           onmouseout="this.style.background='transparent'">
        <div style="min-width:0;flex:1">
          <div style="font-weight:600;font-size:14px">${esc(v.nazev || '—')}</div>
          <div style="font-size:11.5px;color:var(--text-3);margin-top:2px">
            ${v.cislo ? `#${esc(v.cislo)} · ` : ''}
            ${fmt(v.cena_s_dph)} ${v.sazba_dph ? `(${v.sazba_dph}% DPH)` : ''}
            ${v.ean ? ` · EAN ${esc(v.ean)}` : ''}
          </div>
        </div>
        <div style="font-size:14px;color:var(--primary)">→</div>
      </div>
    `).join('') + (filtered.length > 50 ? `<div style="padding:12px;text-align:center;color:var(--text-3);font-size:12px">Zobrazeno prvních 50 z ${filtered.length}. Upřesni hledání.</div>` : '');
  };

  window._snzpFilter = (q) => renderList(q);
  window._snzpApply = async function(stId, vId) {
    const v = vyrobky.find(x => x.id === vId);
    if (!v) return;
    // 5. Spojit původní cenovku s daty z výrobku
    const payload = {
      ...stitek,
      cislo:       v.cislo || stitek.cislo,
      ean:         v.ean || stitek.ean,
      cena_s_dph:  v.cena_s_dph ?? stitek.cena_s_dph,
      sazba_dph:   v.sazba_dph ?? stitek.sazba_dph,
      jednotka:    v.jednotka || stitek.jednotka,
      hmotnost_g:  v.hmotnost_g ?? stitek.hmotnost_g,
      obsah:       v.obsah || v.hmotnost_g || stitek.obsah,
      obsah_jednotka: v.obsah_jednotka || (v.hmotnost_g ? 'g' : stitek.obsah_jednotka),
      slozeni:     v.slozeni || stitek.slozeni,
      alergeny:    v.alergeny || stitek.alergeny,
      // zachováno: stitek.nazev, stitek.badge, stitek.badge_text, layout
    };
    try {
      await api(`admin_moje_stitky.php?id=${stId}`, { method: 'PUT', body: JSON.stringify(payload) });
      closeModal();
      toastSuccess(t('toast_loaded_from_product', { nazev: v.nazev }));
      stState.mojeStitky = []; // force reload
      renderStitky();
    } catch (e) {
      alert('Chyba uložení: ' + e.message);
    }
  };

  renderList('');
};

window.stToggleMoje = function(id) {
  id = parseInt(id);
  if (stState.vybraneMoje.has(id)) stState.vybraneMoje.delete(id);
  else stState.vybraneMoje.add(id);
  // Aktualizuj jen seznam moje + grid (rychlejší než full async renderStitky)
  const ll = document.getElementById('st-moje-list');
  if (ll) ll.innerHTML = stRenderMojeList();
  stRefreshGridPreview();
};

function renderStitkyCenovky() {
  const s = stState;
  // Unikátní kategorie z vyrobků (pro filter dropdown)
  const kategorieMap = new Map();
  (s.vyrobky || []).forEach(v => {
    const kid = parseInt(v.kategorie_id || 0);
    if (!kategorieMap.has(kid)) {
      kategorieMap.set(kid, { id: kid, nazev: v.kategorie_nazev || (kid === 0 ? 'Bez kategorie' : `#${kid}`), pocet: 0, ikona: v.kategorie_ikona || '🥖' });
    }
    kategorieMap.get(kid).pocet++;
  });
  const kategorie = [...kategorieMap.values()].sort((a,b) => a.nazev.localeCompare(b.nazev, 'cs'));
  const aktivniKat = s.cenovkaKat ?? null;

  const stavu = [
    { k: 'aktivni', l: '✓ Aktivní' },
    { k: 'vse',     l: '📚 Vše' },
    { k: 'skryte',  l: '○ Skryté' },
  ];
  const aktStav = s.cenovkaStav || 'aktivni';
  return `
    <div class="card-block" style="padding:14px">
      <h3 style="margin:0 0 10px;font-size:15px">📦 Výběr výrobků</h3>
      ${stStepHint(7, 'Zaškrtni výrobky, ze kterých chceš tisknout cenovky. Můžeš filtrovat podle stavu a kategorie.')}
      <!-- Vyhledávání + status v jednom řádku -->
      <div style="display:flex;gap:12px;flex-wrap:wrap;align-items:center;margin-bottom:14px">
        <input class="form-input" type="search" placeholder="🔍 Hledat (název, kód)..." value="${esc(s.cenovkaQ || '')}" oninput="stState.cenovkaQ = this.value;document.getElementById('st-vyr-list').innerHTML = stRenderVyrList()" style="flex:1;min-width:280px;font-size:18px;padding:14px 18px;height:56px;font-weight:500">
        <label style="display:flex;align-items:center;gap:10px;font-size:17px;font-weight:600;white-space:nowrap;padding:0 12px;cursor:pointer" title="Rozdělí výrobky do sekcí podle kategorie (jako v PDF nabídce)">
          <input type="checkbox" ${s.cenovkaGroupBy ? 'checked' : ''} onchange="stState.cenovkaGroupBy = this.checked;document.getElementById('st-vyr-list').innerHTML = stRenderVyrList()" style="width:24px;height:24px;cursor:pointer;accent-color:var(--primary)">
          🔀 Roztřídit
        </label>
        <label style="display:flex;align-items:center;gap:10px;font-size:17px;font-weight:600;white-space:nowrap;padding:0 12px;cursor:pointer">
          <input type="checkbox" ${s.cenovkaJenVybrane ? 'checked' : ''} onchange="stState.cenovkaJenVybrane = this.checked;document.getElementById('st-vyr-list').innerHTML = stRenderVyrList()" style="width:24px;height:24px;cursor:pointer;accent-color:var(--primary)">
          Jen vybrané <span style="color:var(--text-3);font-weight:500">(${s.vybraneVyr.size})</span>
        </label>
      </div>

      <!-- Stav pillsy (Aktivní / Vše / Skryté) -->
      <div style="display:flex;gap:8px;flex-wrap:wrap;margin-bottom:14px">
        ${stavu.map(o => `
          <button class="period-tab ${aktStav === o.k ? 'active' : ''}" onclick="stState.cenovkaStav='${o.k}';document.getElementById('st-vyr-list').innerHTML = stRenderVyrList();stCenovkyRerenderHead()" style="font-size:16px;padding:12px 20px;font-weight:700;min-height:48px">${o.l}</button>
        `).join('')}
      </div>

      <!-- Kategorie pillsy -->
      <div style="display:flex;flex-wrap:wrap;gap:8px;margin-bottom:16px">
        <button class="period-tab ${aktivniKat === null || aktivniKat === undefined ? 'active' : ''}" onclick="stState.cenovkaKat=null;document.getElementById('st-vyr-list').innerHTML = stRenderVyrList();stCenovkyRerenderHead()" style="font-size:16px;padding:12px 20px;font-weight:700;min-height:48px;display:inline-flex;align-items:center;gap:10px">
          <span style="font-size:28px;line-height:1">📦</span>
          <span>Vše</span>
          <span style="opacity:0.7;margin-left:4px">${s.vyrobky?.length || 0}</span>
        </button>
        ${kategorie.map(k => `
          <button class="period-tab ${aktivniKat === k.id ? 'active' : ''}" onclick="stState.cenovkaKat=${k.id};document.getElementById('st-vyr-list').innerHTML = stRenderVyrList();stCenovkyRerenderHead()" style="font-size:16px;padding:12px 20px;font-weight:700;min-height:48px;display:inline-flex;align-items:center;gap:10px">
            <span style="font-size:28px;line-height:1">${esc(k.ikona)}</span>
            <span>${esc(k.nazev)}</span>
            <span style="opacity:0.7;margin-left:4px">${k.pocet}</span>
          </button>
        `).join('')}
      </div>

      <!-- Akce -->
      <div style="display:flex;gap:8px;flex-wrap:wrap;align-items:center;margin-bottom:12px;padding-top:8px;border-top:1px dashed var(--border)">
        <button class="btn-secondary" onclick="stToggleVse('vyr', true)" style="font-size:13px">Vybrat vše ze zobrazených</button>
        <button class="btn-secondary" onclick="stToggleVse('vyr', false)" style="font-size:13px">Zrušit výběr</button>
        <div style="flex:1"></div>
        <span style="font-size:13px;color:var(--text-3)">Vybráno: <strong style="color:var(--primary-dark)">${s.vybraneVyr.size}</strong></span>
      </div>

      <div id="st-vyr-list">${stRenderVyrList()}</div>
    </div>
  `;
}

// Pomocná funkce: rerender hlavičku cenovky (aktivní pill state) bez full renderStitky
window.stCenovkyRerenderHead = function() {
  const host = document.getElementById('content');
  if (!host) return;
  // Najdi parent .card-block obsahující pillsy a aktualizuj přes renderStitkyCenovky
  // Jednodušší: full renderStitky (rychlý dík cache)
  renderStitky();
};

function stRenderVyrList() {
  const s = stState;
  const q = (s.cenovkaQ || '').toLowerCase();
  const aktKat = s.cenovkaKat;          // null | 0 (bez kategorie) | id
  const stav = s.cenovkaStav || 'aktivni';
  const jenVybrane = !!s.cenovkaJenVybrane;
  const groupBy = !!s.cenovkaGroupBy && (aktKat === null || aktKat === undefined);
  const list = (s.vyrobky || []).filter(v => {
    // Stav
    const isAkt = parseInt(v.aktivni) !== 0;
    if (stav === 'aktivni' && !isAkt) return false;
    if (stav === 'skryte'  && isAkt)  return false;
    // Kategorie
    if (aktKat !== null && aktKat !== undefined) {
      if (parseInt(v.kategorie_id || 0) !== parseInt(aktKat)) return false;
    }
    // Jen vybrané
    if (jenVybrane && !s.vybraneVyr.has(v.id)) return false;
    // Vyhledávání
    if (q && !((v.nazev || '').toLowerCase().includes(q) || (v.cislo || '').toString().toLowerCase().includes(q))) return false;
    return true;
  });
  if (list.length === 0) return '<div class="empty-state">Žádné výrobky</div>';

  // Helpery na render řádku v desktopu a kartě v mobilu
  const radekDesktop = (v) => {
    const sel = stState.vybraneVyr.has(v.id);
    return `
      <tr class="row-clickable ${sel ? 'is-selected' : ''}" onclick="stToggleVyr(${v.id})">
        <td class="col-check" onclick="event.stopPropagation();">
          <input type="checkbox" ${sel ? 'checked' : ''} onchange="stToggleVyr(${v.id})">
        </td>
        <td>${esc(v.cislo || '')}</td>
        <td><strong>${esc(v.nazev)}</strong></td>
        <td class="num">${fmt(v.cena_bez_dph)}</td>
        <td>${esc(v.jednotka || 'ks')}</td>
      </tr>
    `;
  };
  const radekMobile = (v) => {
    const sel = stState.vybraneVyr.has(v.id);
    return `
      <label class="st-row ${sel ? 'is-selected' : ''}">
        <input type="checkbox" ${sel ? 'checked' : ''} onchange="stToggleVyr(${v.id})">
        <div class="st-row-main">
          <div class="st-row-line1">
            ${v.cislo ? `<strong>${esc(v.cislo)}</strong><span class="st-row-dot">·</span>` : ''}
            <span class="st-row-nazev">${esc(v.nazev)}</span>
          </div>
          <div class="st-row-line2">
            <span>${fmt(v.cena_bez_dph)} / ${esc(v.jednotka || 'ks')}</span>
          </div>
        </div>
      </label>
    `;
  };

  const tableHead = `
    <thead>
      <tr>
        <th class="col-check"></th>
        <th>Č.</th>
        <th>Název</th>
        <th class="num">Cena bez DPH</th>
        <th>Jed.</th>
      </tr>
    </thead>
  `;

  // 🔀 Když není groupBy, plochý seznam (původní chování)
  if (!groupBy) {
    return `
      <!-- Desktop -->
      <div class="desktop-only-block">
        <table class="table table-selectable" style="margin:0">
          ${tableHead}
          <tbody>${list.map(radekDesktop).join('')}</tbody>
        </table>
      </div>
      <!-- Mobile -->
      <div class="mobile-only-block st-rows">
        ${list.map(radekMobile).join('')}
      </div>
    `;
  }

  // 🔀 Groupované zobrazení — sekce podle kategorie
  // Sestav mapu kategorií pro nadpisy (ikona + název + pořadí)
  const katMap = new Map();
  (s.vyrobky || []).forEach(v => {
    const kid = parseInt(v.kategorie_id || 0);
    if (!katMap.has(kid)) {
      katMap.set(kid, {
        id: kid,
        nazev: v.kategorie_nazev || (kid === 0 ? 'Bez kategorie' : `Kategorie #${kid}`),
        ikona: v.kategorie_ikona || (kid === 0 ? '❓' : '🥖'),
        poradi: parseInt(v.kategorie_poradi || 999),
      });
    }
  });

  // Rozsekej filtrovaný list do skupin
  const groups = new Map();
  list.forEach(v => {
    const kid = parseInt(v.kategorie_id || 0);
    if (!groups.has(kid)) groups.set(kid, []);
    groups.get(kid).push(v);
  });

  // Seřaď podle poradi kategorií
  const sortedKids = [...groups.keys()].sort((a, b) => {
    const pa = katMap.get(a)?.poradi ?? 999;
    const pb = katMap.get(b)?.poradi ?? 999;
    return pa - pb;
  });

  const katHeader = (kat, count) => `
    <h3 style="margin:18px 0 8px;font-size:16px;font-weight:700;color:var(--text);display:flex;align-items:center;gap:10px;padding:6px 0;border-bottom:2px solid var(--border-2)">
      <span style="font-size:28px;line-height:1">${esc(kat.ikona)}</span>
      <span>${esc(kat.nazev)}</span>
      <span style="font-size:12px;font-weight:600;color:var(--text-3);background:var(--surface-2);padding:2px 10px;border-radius:10px">${count}</span>
    </h3>
  `;

  return `
    <!-- Desktop -->
    <div class="desktop-only-block">
      ${sortedKids.map(kid => {
        const kat = katMap.get(kid);
        const items = groups.get(kid);
        return `
          ${katHeader(kat, items.length)}
          <table class="table table-selectable" style="margin:0 0 8px">
            ${tableHead}
            <tbody>${items.map(radekDesktop).join('')}</tbody>
          </table>
        `;
      }).join('')}
    </div>
    <!-- Mobile -->
    <div class="mobile-only-block">
      ${sortedKids.map(kid => {
        const kat = katMap.get(kid);
        const items = groups.get(kid);
        return `
          ${katHeader(kat, items.length)}
          <div class="st-rows">${items.map(radekMobile).join('')}</div>
        `;
      }).join('')}
    </div>
  `;
}

window.stSetRezim = async function(r) {
  stState.rezim = r;
  if (r === 'editor' && !stEditor.formatId) {
    stEditor.formatId = stState.formatId;
  }
  // 🎨 Když přejdu do editoru s built-in designem, načti ho jako novou šablonu k úpravě
  if (r === 'editor' && stState.designId && !stState.sablonaId) {
    const design = DESIGN_PRESETS.find(d => d.id === stState.designId);
    if (design) {
      if (stEditor.prvky.length > 0 && !(await confirmDialog({ msg: 'V editoru máš rozdělanou šablonu. Načíst design z náhledu (neuložené změny se ztratí)?', danger: true }))) {
        renderStitky();
        return;
      }
      const fmtCur = stState.formatId === 'custom' ? stState.custom : (STITKY_FORMATY.find(f => f.id === stState.formatId) || STITKY_FORMATY[0]);
      const curW = parseFloat(fmtCur.w) || 70;
      const curH = parseFloat(fmtCur.h) || 42;
      const scaledPrvky = designScaledPrvky(design.id, curW, curH, !!fmtCur.foldHalf) || [];
      stEditor.sablonaId = null;            // nová neuložená šablona
      stEditor.nazev = `${design.nazev} (kopie)`;
      stEditor.formatId = stState.formatId; // dědíme aktuální formát
      stEditor.prvky = scaledPrvky.map((p, i) => ed_sanitizePrvek(p, i));
      stEditor.vybranyId = null;
    }
    renderStitky();
    return;
  }
  // Když přejdu do editoru a v cenovkách / mých štítcích mám aktuálně vybranou šablonu,
  // předvyplň ji rovnou v editoru (s pozicemi prvků), abych si ji mohl jen poladit
  if (r === 'editor' && stState.sablonaId && stEditor.sablonaId != stState.sablonaId) {
    // Pokud má editor neuložené prvky, zeptej se
    if (stEditor.prvky.length > 0 && stEditor.sablonaId !== stState.sablonaId) {
      if (!(await confirmDialog({ msg: 'V editoru máš rozdělanou šablonu. Načíst „aktivní" šablonu z náhledu (neuložené změny se ztratí)?', danger: true }))) {
        renderStitky();
        return;
      }
    }
    try {
      const sab = await api(`admin_stitky_sablony.php?id=${stState.sablonaId}`);
      stEditor.sablonaId = parseInt(sab.id) || sab.id;
      stEditor.nazev = sab.nazev || '';
      if (sab.format_id) stEditor.formatId = sab.format_id;
      let layoutData = sab.layout;
      if (typeof layoutData === 'string') {
        try { layoutData = JSON.parse(layoutData); } catch (e) { layoutData = {}; }
      }
      let prvkyRaw = [];
      if (Array.isArray(layoutData)) prvkyRaw = layoutData;
      else if (Array.isArray(layoutData?.prvky)) prvkyRaw = layoutData.prvky;
      stEditor.prvky = prvkyRaw.map((p, i) => ed_sanitizePrvek(p, i));
      stEditor.vybranyId = null;
    } catch (e) {
      console.warn('Nepodařilo se načíst šablonu:', e);
    }
  }
  renderStitky();
};

