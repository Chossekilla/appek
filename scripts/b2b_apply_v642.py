#!/usr/bin/env python3
"""Merge B2B translations v3.0.642 into B2B_PHRASES (b2b/i18n.js). Clone of b2b_merge.py
   with a NEW marker; input scripts/b2b_new_v642.json [{cs,en,es}]. Skips covered keys.
   Usage: python3 scripts/b2b_apply_v642.py [ROOT]
"""
import json, re, sys
from pathlib import Path
ROOT = sys.argv[1] if len(sys.argv) > 1 else str(Path(__file__).resolve().parent.parent)
MARK = 'b2b dopreklady v3.0.642 (kosik/checkout/upozorneni/zasady)'

def jq(s):
    return s.replace('\\', '\\\\').replace("'", "\\'").replace('\n', '\\n')

i18n = open(f'{ROOT}/b2b/i18n.js', encoding='utf-8').read()
if MARK in i18n:
    sys.exit('ALREADY MERGED -- aborting')
covered = {m.group(1).replace("\\'", "'") for m in re.finditer(r"\[\s*'((?:[^'\\]|\\.)*)'\s*,", i18n)}
items, skipped = [], []
for x in json.load(open(f'{ROOT}/scripts/b2b_new_v642.json', encoding='utf-8')):
    cs, en, es = x['cs'].strip(), x['en'].strip(), x['es'].strip()
    if cs in covered or cs in [i[0] for i in items]: skipped.append(cs); continue
    items.append((cs, en, es))
lines = i18n.split('\n')
start = next(i for i, l in enumerate(lines) if l.startswith('const B2B_PHRASES'))
end = next(i for i in range(start + 1, len(lines)) if lines[i] == '];')
lines[end:end] = [f'  // {MARK} (+{len(items)})'] + [f"  ['{jq(a)}', '{jq(b)}', '{jq(c)}']," for a, b, c in items]
open(f'{ROOT}/b2b/i18n.js', 'w', encoding='utf-8').write('\n'.join(lines))
print(f'b2b/i18n.js: +{len(items)} before line {end + 1}; skipped {skipped}')
