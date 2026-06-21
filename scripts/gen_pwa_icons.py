#!/usr/bin/env python3
"""🆕 v3.0.362 — generuje PWA PNG ikony (Android + iOS install) pro admin/b2b/pos.

ZDROJ ikony (priorita):
  1. cesta z argumentu:  python3 scripts/gen_pwa_icons.py /cesta/k/ikone.png
  2. branding/app-icon.png  (sem dej svou ikonu z Claude design — čtverec, ideálně 1024×1024,
     NEPRŮHLEDNÉ pozadí, hlavní prvek ~uprostřed s ~15% okrajem kvůli Android „maskable" ořezu)
  3. fallback: vykreslí placeholder „A" (gradient #BA7517→#854F0B)

Výstup do admin/icons, b2b/icons, pos/icons: icon-192.png, icon-512.png,
icon-maskable.png (512, full-bleed + safe-zone), icon-apple.png (180, plný čtverec — iOS si zaobluje sám).
SVG / data-URI iOS apple-touch NEpodporuje → proto PNG.
"""
import os, sys
from PIL import Image, ImageDraw, ImageFont

ROOT = '/Users/chossekilaimac/projects/appek.cz'
C1, C2 = (0xBA, 0x75, 0x17), (0x85, 0x4F, 0x0B)
APPS = [f'{ROOT}/admin/icons', f'{ROOT}/b2b/icons', f'{ROOT}/pos/icons']

def find_source():
    if len(sys.argv) > 1 and os.path.isfile(sys.argv[1]):
        return sys.argv[1]
    for c in [f'{ROOT}/branding/app-icon.png', f'{ROOT}/branding/app-icon.jpg',
              f'{ROOT}/branding/app-icon.jpeg', f'{ROOT}/branding/app-icon.webp']:
        if os.path.isfile(c):
            return c
    return None

def load_font(sz):
    for p in ['/System/Library/Fonts/Supplemental/Arial Bold.ttf', '/Library/Fonts/Arial Bold.ttf',
              '/System/Library/Fonts/HelveticaNeue.ttc', '/System/Library/Fonts/SFNS.ttf']:
        try:
            return ImageFont.truetype(p, sz)
        except Exception:
            continue
    return ImageFont.load_default()

def gradient(size):
    img = Image.new('RGB', (size, size)); px = img.load(); d = max(1, 2 * (size - 1))
    for y in range(size):
        for x in range(size):
            t = (x + y) / d
            px[x, y] = tuple(round(C1[i] + (C2[i] - C1[i]) * t) for i in range(3))
    return img

def square_opaque(img, bg):
    """Doplní na čtverec + sloučí průhlednost na bg → neprůhledný RGBA čtverec."""
    w, h = img.size; s = max(w, h)
    canvas = Image.new('RGBA', (s, s), bg + (255,))
    canvas.alpha_composite(img if img.mode == 'RGBA' else img.convert('RGBA'),
                           ((s - w) // 2, (s - h) // 2))
    return canvas

def detect_bg(src):
    """Reprezentativní NEPRŮHLEDNÉ pozadí (pro výplň průhledných rohů u apple/maskable):
    průměr středů 4 okrajů. Pro ikonu se zaoblenými rohy = barva okraje (ne roh, ten je průhledný)."""
    w, h = src.size
    cols = []
    for p in [(w // 2, 4), (w // 2, h - 5), (4, h // 2), (w - 5, h // 2)]:
        px = src.getpixel(p)
        if len(px) < 4 or px[3] > 200:
            cols.append(px[:3])
    if not cols:
        return C1
    n = len(cols)
    return tuple(sum(c[i] for c in cols) // n for i in range(3))

def build_from_source(src_path):
    src = Image.open(src_path).convert('RGBA')
    if src.width != src.height:  # doplň na čtverec (průhledně)
        s = max(src.size)
        sq0 = Image.new('RGBA', (s, s), (0, 0, 0, 0))
        sq0.alpha_composite(src, ((s - src.width) // 2, (s - src.height) // 2))
        src = sq0
    R = Image.Resampling.LANCZOS
    opaque = square_opaque(src, detect_bg(src))  # rohy vyplněné barvou okraje
    return {
        'icon-192.png': src.resize((192, 192), R),          # zachová zaoblení + průhlednost
        'icon-512.png': src.resize((512, 512), R),
        'icon-apple.png': opaque.resize((180, 180), R),     # neprůhledný čtverec — iOS si zaobluje
        'icon-maskable.png': opaque.resize((512, 512), R),  # full-bleed — Android maskuje (obsah je centrovaný)
    }

def build_placeholder():
    def make(size, radius_frac, letter_frac, opaque):
        grad = gradient(size)
        if opaque:
            img = grad.convert('RGBA')
        else:
            img = Image.new('RGBA', (size, size), (0, 0, 0, 0))
            m = Image.new('L', (size, size), 0)
            ImageDraw.Draw(m).rounded_rectangle([0, 0, size - 1, size - 1], radius=int(size * radius_frac), fill=255)
            img.paste(grad, (0, 0), m)
        ImageDraw.Draw(img).text((size / 2, size / 2 - size * 0.02), 'A',
                                 font=load_font(int(size * letter_frac)), fill=(255, 255, 255, 255), anchor='mm')
        return img
    return {'icon-192.png': make(192, 0.1875, 0.62, False), 'icon-512.png': make(512, 0.1875, 0.62, False),
            'icon-maskable.png': make(512, 0.0, 0.46, True), 'icon-apple.png': make(180, 0.0, 0.62, True)}

src = find_source()
if src:
    print(f'📥 zdroj: {src}')
    icons = build_from_source(src)
else:
    print('ℹ️  žádný zdroj (branding/app-icon.png) → placeholder „A". Dej tam svou ikonu a spusť znovu.')
    icons = build_placeholder()

for d in APPS:
    os.makedirs(d, exist_ok=True)
    for name, img in icons.items():
        img.convert('RGBA').save(f'{d}/{name}')
print('✅ vygenerováno do:', ', '.join(a.replace(ROOT + '/', '') for a in APPS))
