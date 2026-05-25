#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""
🗺️ APPEK CSS INDEX — mapa admin.css

Problém: admin.css má ~17 000 řádků a VRSTVY —
  základ + témata (win98 / apple / dark) + hustoty (kompakt / prostor / extrém)
  + breakpointy (@media). Jedna třída je klidně definovaná 10× na různých místech.
  Když upravíš jen jedno místo, jiná vrstva tě přebije → "změna se neprojevila".

Tenhle skript projede admin.css a pro KAŽDOU třídu vypíše VŠECHNA místa,
kde je definovaná (řádek + vrstva + breakpoint). Díky tomu se dá upravit
správné místo napoprvé.

Spuštění:  python3 scripts/css-index.py
Výstup:    docs/CSS-MAP.md
"""
import os
import re
import collections

ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
CSS = os.path.join(ROOT, 'admin', 'admin.css')
OUT = os.path.join(ROOT, 'docs', 'CSS-MAP.md')

src = open(CSS, encoding='utf-8').read()
n = len(src)

# ── Char scanner: posbírej (řádek, selektor, [@media kontext]) pro každé pravidlo ──
rules = []          # (line, selector, [media])
i = 0
line = 1
in_comment = False
in_string = None
buf = ''
buf_line = None
stack = []          # [('media'|'keyframes'|'rule', text)]

while i < n:
    c = src[i]
    nxt = src[i + 1] if i + 1 < n else ''
    if in_comment:
        if c == '*' and nxt == '/':
            in_comment = False
            i += 2
            continue
        if c == '\n':
            line += 1
        i += 1
        continue
    if in_string:
        if c == '\\':
            buf += src[i:i + 2]
            i += 2
            continue
        if c == in_string:
            in_string = None
        if c == '\n':
            line += 1
        buf += c
        i += 1
        continue
    if c == '/' and nxt == '*':
        in_comment = True
        i += 2
        continue
    if c in '"\'':
        in_string = c
        if buf_line is None:
            buf_line = line
        buf += c
        i += 1
        continue
    if c == '{':
        sel = ' '.join(buf.split())
        sl = buf_line or line
        if sel.startswith('@'):
            if sel.startswith(('@keyframes', '@font-face', '@-')):
                stack.append(('keyframes', sel))
            else:                       # @media, @supports, ...
                stack.append(('media', sel))
        else:
            inside_kf = any(k == 'keyframes' for k, _ in stack)
            if sel and not inside_kf:
                media = [t for k, t in stack if k == 'media']
                rules.append((sl, sel, media))
            stack.append(('rule', sel))
        buf = ''
        buf_line = None
        i += 1
        continue
    if c == '}':
        if stack:
            stack.pop()
        buf = ''
        buf_line = None
        i += 1
        continue
    if c == ';':
        buf = ''
        buf_line = None
        i += 1
        continue
    if c == '\n':
        line += 1
    if buf_line is None and not c.isspace():
        buf_line = line
    buf += c
    i += 1

# ── Rozbal comma-listy a zaindexuj podle tříd ──────────────────────
def split_selectors(sel):
    out, depth, cur = [], 0, ''
    for ch in sel:
        if ch in '([':
            depth += 1
        elif ch in ')]':
            depth -= 1
        if ch == ',' and depth == 0:
            out.append(cur.strip())
            cur = ''
        else:
            cur += ch
    if cur.strip():
        out.append(cur.strip())
    return out

CLASS_RE = re.compile(r'\.[A-Za-z_][A-Za-z0-9_-]*')

def subject_classes(sel):
    """Třídy v PODMĚTU selektoru (poslední compound) — tj. kde se prvek
    sám styluje, ne kde je jen předek (`.modal-card .table td` → podmět `td`)."""
    depth = 0
    last = ''
    cur = ''
    for ch in sel:
        if ch in '([':
            depth += 1
            cur += ch
        elif ch in ')]':
            depth -= 1
            cur += ch
        elif depth == 0 and ch in ' \t\n>+~':
            if cur.strip():
                last = cur
            cur = ''
        else:
            cur += ch
    if cur.strip():
        last = cur
    return set(CLASS_RE.findall(last))

def layer_of(sel):
    tags = []
    if 'theme-win98' in sel:
        tags.append('win98')
    if 'theme-apple' in sel:
        tags.append('apple')
    if 'theme-dark' in sel or re.search(r'html\.dark\b', sel):
        tags.append('dark')
    if 'density-compact' in sel:
        tags.append('hustota-kompakt')
    if 'density-spacious' in sel:
        tags.append('hustota-prostor')
    if 'density-extreme' in sel:
        tags.append('hustota-extrem')
    return '+'.join(tags) if tags else 'zaklad'

def media_short(media):
    out = []
    for m in media:
        wq = re.findall(r'(?:max|min)-width:\s*\d+px', m)
        out.append(','.join(w.replace(' ', '') for w in wq) if wq else m.replace('@media', '').strip())
    return ' & '.join(out)

index = collections.defaultdict(list)   # class -> [ {line, sel, media, layer} ]
seen = set()                            # dedup (class, line, selektor)
for sl, sel, media in rules:
    for one in split_selectors(sel):
        classes = subject_classes(one)
        if not classes:
            continue
        ms = media_short(media)
        lay = layer_of(one)
        for cls in classes:
            key = (cls, sl, one)
            if key in seen:
                continue
            seen.add(key)
            index[cls].append({'line': sl, 'sel': one, 'media': ms, 'layer': lay})

# ── Výstup: docs/CSS-MAP.md ────────────────────────────────────────
total_lines = src.count('\n') + 1
L = []
def w(s=''):
    L.append(s)

w('# 🗺️ APPEK — CSS MAPA (admin.css)')
w('')
w(f'_Auto-generováno `scripts/css-index.py`. admin.css = **{total_lines} řádků**, '
  f'**{len(index)} tříd**, **{len(rules)} pravidel**._')
w('')
w('## K čemu to je')
w('')
w('admin.css má vrstvy: **základ → témata (win98 / apple / dark) → hustoty '
  '(kompakt / prostor / extrém) → breakpointy `@media`**. Jedna třída bývá '
  'definovaná i 10×. Když upravíš jen jedno místo, jiná vrstva tě v kaskádě přebije.')
w('')
w('Postup při úpravě CSS:')
w('1. Najdi třídu níže (Cmd/Ctrl+F).')
w('2. Koukni na VŠECHNA místa — `L<řádek>[vrstva@breakpoint]`.')
w('3. Uprav správnou vrstvu, nebo přidej kanonický blok na konec souboru '
  '(ten vyhrává zdrojovým pořadím).')
w('')
w('Po každé úpravě admin.css spusť `python3 scripts/css-index.py` — mapa se přegeneruje.')
w('')

hot = sorted(((c, len(v)) for c, v in index.items() if len(v) >= 5),
             key=lambda x: -x[1])
w('## ⚠️ Kaskádové hotspoty (5+ definic — tady hrozí přebití)')
w('')
if hot:
    for c, cnt in hot[:50]:
        w(f'- `{c}` — **{cnt}×**')
else:
    w('_Žádné._')
w('')

w('## 📑 Index tříd (abecedně)')
w('')
w('Formát: `` `.trida` — Nx — L<řádek>[<vrstva>@<breakpoint>] … ``')
w('')
for cls in sorted(index):
    entries = sorted(index[cls], key=lambda e: e['line'])
    parts = []
    for e in entries:
        tag = e['layer']
        if e['media']:
            tag += '@' + e['media']
        parts.append(f'L{e["line"]}[{tag}]')
    w(f'- `{cls}` — {len(entries)}× — ' + ' '.join(parts))
w('')

os.makedirs(os.path.dirname(OUT), exist_ok=True)
open(OUT, 'w', encoding='utf-8').write('\n'.join(L) + '\n')
print(f'✓ {os.path.relpath(OUT, ROOT)} — {len(index)} tříd, {len(rules)} pravidel, '
      f'{len(hot)} hotspotů')
