#!/usr/bin/env python3
"""🆕 v3.0.362 — generuje PWA PNG ikony (Android + iOS install).
Design = existující SVG: diagonální gradient #BA7517→#854F0B + bílé bold „A".
Sady: icon-192.png, icon-512.png (any, zaoblené), icon-maskable.png (512 full-bleed,
content v safe-zone), icon-apple.png (180 plný neprůhledný čtverec — iOS si zaobluje sám).
Zapíše do admin/icons, b2b/icons, pos/icons. SVG (data-URI) iOS apple-touch NEpodporuje → PNG nutné.
"""
from PIL import Image, ImageDraw, ImageFont
import os

ROOT = '/Users/chossekilaimac/projects/appek.cz'
C1 = (0xBA, 0x75, 0x17)
C2 = (0x85, 0x4F, 0x0B)
APPS = [f'{ROOT}/admin/icons', f'{ROOT}/b2b/icons', f'{ROOT}/pos/icons']

def load_font(sz):
    for p in ['/System/Library/Fonts/Supplemental/Arial Bold.ttf',
              '/Library/Fonts/Arial Bold.ttf',
              '/System/Library/Fonts/HelveticaNeue.ttc',
              '/System/Library/Fonts/SFNS.ttf',
              '/System/Library/Fonts/Supplemental/Arial.ttf']:
        try:
            return ImageFont.truetype(p, sz)
        except Exception:
            continue
    return ImageFont.load_default()

def gradient(size):
    img = Image.new('RGB', (size, size))
    px = img.load()
    denom = max(1, 2 * (size - 1))
    for y in range(size):
        for x in range(size):
            t = (x + y) / denom
            px[x, y] = (round(C1[0] + (C2[0] - C1[0]) * t),
                        round(C1[1] + (C2[1] - C1[1]) * t),
                        round(C1[2] + (C2[2] - C1[2]) * t))
    return img

def make(size, radius_frac, letter_frac, opaque):
    grad = gradient(size)
    if opaque:
        img = grad.convert('RGBA')
    else:
        img = Image.new('RGBA', (size, size), (0, 0, 0, 0))
        mask = Image.new('L', (size, size), 0)
        ImageDraw.Draw(mask).rounded_rectangle([0, 0, size - 1, size - 1],
                                               radius=int(size * radius_frac), fill=255)
        img.paste(grad, (0, 0), mask)
    d = ImageDraw.Draw(img)
    d.text((size / 2, size / 2 - size * 0.02), 'A', font=load_font(int(size * letter_frac)),
           fill=(255, 255, 255, 255), anchor='mm')
    return img

icons = {
    'icon-192.png':      make(192, 0.1875, 0.62, False),
    'icon-512.png':      make(512, 0.1875, 0.62, False),
    'icon-maskable.png': make(512, 0.0,    0.46, True),   # full-bleed + safe-zone content
    'icon-apple.png':    make(180, 0.0,    0.62, True),   # plný čtverec pro iOS
}
for d in APPS:
    os.makedirs(d, exist_ok=True)
    for name, img in icons.items():
        img.save(f'{d}/{name}')
print('✅ vygenerováno:', list(icons.keys()))
for d in APPS:
    print('  →', d, sorted(os.listdir(d)))
