#!/usr/bin/env python3
"""Generate brand images for APPEK B2B:
   - assets/og-image.png    (1200×630, OpenGraph social sharing)
   - assets/favicon.png     (32×32, browser tab icon)
   - assets/apple-touch-icon.png (180×180, iOS home screen)

Rerun any time brand colors / tagline change.
"""
import os
from PIL import Image, ImageDraw, ImageFont

ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
ASSETS = os.path.join(ROOT, 'assets')
os.makedirs(ASSETS, exist_ok=True)

BRAND_BROWN = '#BA7517'
BRAND_BROWN_DARK = '#854F0B'
BG_CREAM = '#FFF8E8'
TEXT_DARK = '#1D1D1F'
TEXT_GREY = '#6E6E73'
TEXT_MUTED = '#86868B'

# macOS system font with Czech diacritics support
FONT_PATH = '/System/Library/Fonts/Helvetica.ttc'


def load_font(size):
    return ImageFont.truetype(FONT_PATH, size)


def make_gradient_square(size, color1, color2):
    """Brown diagonal-gradient square with rounded corners."""
    sq = Image.new('RGB', (size, size), color1)
    px = sq.load()
    for y in range(size):
        for x in range(size):
            t = (x + y) / (2 * size)
            r = int(int(color1[1:3], 16) * (1 - t) + int(color2[1:3], 16) * t)
            g = int(int(color1[3:5], 16) * (1 - t) + int(color2[3:5], 16) * t)
            b = int(int(color1[5:7], 16) * (1 - t) + int(color2[5:7], 16) * t)
            px[x, y] = (r, g, b)
    # rounded corners via mask
    mask = Image.new('L', (size, size), 0)
    md = ImageDraw.Draw(mask)
    md.rounded_rectangle([0, 0, size, size], radius=size // 5, fill=255)
    out = Image.new('RGBA', (size, size), (0, 0, 0, 0))
    out.paste(sq, (0, 0), mask)
    return out


def draw_centered_a(img, color='white'):
    """Draw bold 'A' centered in the image."""
    w, h = img.size
    draw = ImageDraw.Draw(img)
    f = load_font(int(h * 0.62))
    bb = draw.textbbox((0, 0), 'A', font=f)
    tw, th = bb[2] - bb[0], bb[3] - bb[1]
    x = (w - tw) / 2 - bb[0]
    y = (h - th) / 2 - bb[1] - h * 0.04   # slight optical centering
    draw.text((x, y), 'A', font=f, fill=color)


# ──────────────────────────────────────────────────────────────────
# 1. og-image.png — 1200×630
# ──────────────────────────────────────────────────────────────────
W, H = 1200, 630
og = Image.new('RGB', (W, H), BG_CREAM)
draw = ImageDraw.Draw(og)

# subtle vertical gradient: cream → white
for y in range(H):
    t = y / H
    r = int(0xFF * (1 - t) + 0xFF * t)
    g = int(0xF8 * (1 - t) + 0xFC * t)
    b = int(0xE8 * (1 - t) + 0xF7 * t)
    draw.line([(0, y), (W, y)], fill=(r, g, b))

# logo square top-left area
logo_size = 180
lx, ly = 80, 120
logo = make_gradient_square(logo_size, BRAND_BROWN, BRAND_BROWN_DARK)
draw_centered_a(logo, 'white')
og.paste(logo, (lx, ly), logo)

# title to the right of the logo
tx = lx + logo_size + 44
draw.text((tx, ly + 18), 'APPEK B2B', font=load_font(86), fill=TEXT_DARK)
draw.text((tx, ly + 124), 'Krabicovka pro gastro výrobce', font=load_font(36), fill=TEXT_GREY)

# central headline
hy = ly + logo_size + 60
draw.text((lx, hy), 'Vše pro váš provoz v jednom systému', font=load_font(48), fill=TEXT_DARK)

# bullets (• U+2022 is well-supported across fonts)
by = hy + 78
bullets = [
    '•  Pekárny · Cukrárny · Lahůdky · Restaurace · Catering',
    '•  Bez měsíčních poplatků — zaplatíš jednou, vlastníš navždy',
]
for line in bullets:
    draw.text((lx, by), line, font=load_font(26), fill=TEXT_MUTED)
    by += 40

# footer band
draw.text((lx, H - 60), 'appek.cz · od 12 990 Kč jednorázově · bez subscription',
          font=load_font(26), fill=BRAND_BROWN)

og.save(os.path.join(ASSETS, 'og-image.png'), optimize=True)
print('✅ assets/og-image.png (1200×630)')


# ──────────────────────────────────────────────────────────────────
# 2. apple-touch-icon.png — 180×180 (rounded by iOS)
# ──────────────────────────────────────────────────────────────────
ati = Image.new('RGB', (180, 180), BRAND_BROWN)
# manual diagonal gradient
pp = ati.load()
for y in range(180):
    for x in range(180):
        t = (x + y) / 360
        r = int(0xBA * (1 - t) + 0x85 * t)
        g = int(0x75 * (1 - t) + 0x4F * t)
        b = int(0x17 * (1 - t) + 0x0B * t)
        pp[x, y] = (r, g, b)
draw_centered_a(ati, 'white')
ati.save(os.path.join(ASSETS, 'apple-touch-icon.png'), optimize=True)
print('✅ assets/apple-touch-icon.png (180×180)')


# ──────────────────────────────────────────────────────────────────
# 3. favicon.png — 32×32 (downscale from a 256×256 master for crispness)
# ──────────────────────────────────────────────────────────────────
master = Image.new('RGB', (256, 256), BRAND_BROWN)
pp = master.load()
for y in range(256):
    for x in range(256):
        t = (x + y) / 512
        r = int(0xBA * (1 - t) + 0x85 * t)
        g = int(0x75 * (1 - t) + 0x4F * t)
        b = int(0x17 * (1 - t) + 0x0B * t)
        pp[x, y] = (r, g, b)
draw_centered_a(master, 'white')
master.resize((32, 32), Image.LANCZOS).save(
    os.path.join(ASSETS, 'favicon.png'), optimize=True
)
print('✅ assets/favicon.png (32×32)')
