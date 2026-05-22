#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""
🔧 APPEK CSS BRACE CHECK — najde nevyvážené { } v admin.css

Nevyvážená závorka = neuzavřený blok → všechno za ním spadne dovnitř
(typicky do @media) a styly se aplikují špatně / jen na některých šířkách.

Spuštění:  python3 scripts/css-braces.py
"""
import os
import sys

ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
CSS = os.path.join(ROOT, 'admin', 'admin.css')
src = open(CSS, encoding='utf-8').read()
n = len(src)

i = 0
line = 1
col = 0
in_comment = False
in_string = None
buf = ''
buf_line = None
buf_col = None
stack = []          # (open_line, opener_text)
suspicious = []     # (line, depth, selector, stack_snapshot)
opens = closes = 0

while i < n:
    c = src[i]
    nxt = src[i + 1] if i + 1 < n else ''
    if in_comment:
        if c == '*' and nxt == '/':
            in_comment = False
            i += 2
            col += 2
            continue
        if c == '\n':
            line += 1
            col = 0
        else:
            col += 1
        i += 1
        continue
    if in_string:
        if c == '\\':
            i += 2
            col += 2
            continue
        if c == in_string:
            in_string = None
        if c == '\n':
            line += 1
            col = 0
        else:
            col += 1
        buf += c
        i += 1
        continue
    if c == '/' and nxt == '*':
        in_comment = True
        i += 2
        col += 2
        continue
    if c in '"\'':
        in_string = c
        if buf_line is None:
            buf_line = line
            buf_col = col
        buf += c
        i += 1
        col += 1
        continue
    if c == '{':
        opens += 1
        sel = ' '.join(buf.split())
        sl = buf_line or line
        sc = buf_col if buf_col is not None else col
        # pravidlo začínající na sloupci 0, ale parser si myslí že jsme zanořeni
        if sc == 0 and stack:
            suspicious.append((sl, len(stack), sel[:70], list(stack)))
        stack.append((sl, sel[:80]))
        buf = ''
        buf_line = buf_col = None
        i += 1
        col += 1
        continue
    if c == '}':
        closes += 1
        if stack:
            stack.pop()
        buf = ''
        buf_line = buf_col = None
        i += 1
        col += 1
        continue
    if c == ';':
        buf = ''
        buf_line = buf_col = None
        i += 1
        col += 1
        continue
    if c == '\n':
        line += 1
        col = 0
        i += 1
        continue
    if buf_line is None and not c.isspace():
        buf_line = line
        buf_col = col
    buf += c
    i += 1
    col += 1

print(f'admin.css — {{ = {opens}   }} = {closes}   ROZDÍL = {opens - closes}')
print()
print('PODEZŘELÁ MÍSTA (pravidlo začíná na sloupci 0, ale parser je zanořený → výše chybí „}"):')
if not suspicious:
    print('  žádné')
for sl, depth, sel, st in suspicious[:25]:
    print(f'  L{sl}  hloubka={depth}  "{sel}"')
    print('        otevřené bloky: ' + ' / '.join(f'L{l}:{t[:38]}' for l, t in st))
print()
print('NEUZAVŘENO na konci souboru (tyhle bloky nikdy nedostaly „}"):')
if not stack:
    print('  žádné — závorky vyvážené')
for l, t in stack:
    print(f'  L{l}: {t}')

# Exit kód: 0 = OK, 1 = nevyvážené (build-zip.sh na to spadne)
sys.exit(0 if opens == closes and not suspicious else 1)
