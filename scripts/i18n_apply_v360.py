#!/usr/bin/env python3
"""Apply v3.0.360 UI translation backlog (genuine remainder from audit).
   en/es -> [cs,en,es] tuples into admin/i18n_auto.js; sk/de -> i18n_dicts_extra.py.
   Idempotent: aborts if MARK present. (Clone of i18n_apply_v345.py.)
"""
import json, re, sys
from collections import Counter

ROOT = '/Users/chossekilaimac/projects/appek.cz'
MARK = 'v3.0.360 dopreklady UI (zbytek auditu: polotovar/typ/pobocka/hledat odberatele/nefakturovana)'

auto = open(f'{ROOT}/admin/i18n_auto.js', encoding='utf-8').read()
if MARK in auto:
    sys.exit('ALREADY MERGED -- aborting')

auto_keys = set()
for m in re.finditer(r"\[\s*'((?:[^'\\]|\\.)*)'\s*,\s*'((?:[^'\\]|\\.)*)'\s*,\s*'((?:[^'\\]|\\.)*)'\s*\]", auto):
    auto_keys.add(m.group(1).replace("\\'", "'").replace('\\\\', '\\'))

rows = json.load(open(f'{ROOT}/scripts/i18n_new_v360.json', encoding='utf-8'))
entries, skipped = {}, []
for x in rows:
    cs = x['cs'].strip()
    en, es, sk, de = x['en'].strip(), x['es'].strip(), x['sk'].strip(), x['de'].strip()
    if len(cs) < 2: skipped.append(('tooshort', cs)); continue
    if cs in auto_keys: skipped.append(('covered', cs)); continue
    if en == cs and es == cs and sk == cs and de == cs: skipped.append(('passthrough', cs)); continue
    if cs in entries: skipped.append(('dup', cs)); continue
    entries[cs] = (en, es, sk, de)

print(f'rows {len(rows)} | unique-new {len(entries)} | skipped {len(skipped)}')
print('skip reasons:', dict(Counter(r for r, _ in skipped)))

def q(s):
    return (s.replace('\\', '\\\\').replace("'", "\\'").replace('\n', '\\n').replace('\r', '\\r').replace('\t', '\\t'))

items = sorted(entries.items(), key=lambda kv: kv[0].lower())
if items:
    js_lines = [f'  // {MARK} (+{len(items)})']
    js_lines += [f"  ['{q(cs)}', '{q(en)}', '{q(es)}']," for cs, (en, es, sk, de) in items]
    au = auto.split('\n')
    ci = next(i for i, l in enumerate(au) if l.strip() == '];' and i + 2 < len(au) and 'Build lookup index' in au[i+2])
    au[ci:ci] = js_lines
    open(f'{ROOT}/admin/i18n_auto.js', 'w', encoding='utf-8').write('\n'.join(au))
    print(f'i18n_auto.js: +{len(items)} tuples before line {ci+1}')

    blk = ['', f'# {MARK} (+{len(items)})', 'SK_EXTRA.update({']
    blk += [f"    '{q(cs)}': '{q(sk)}'," for cs, (en, es, sk, de) in items]
    blk += ['})', 'DE_EXTRA.update({']
    blk += [f"    '{q(cs)}': '{q(de)}'," for cs, (en, es, sk, de) in items]
    blk += ['})', '']
    with open(f'{ROOT}/scripts/i18n_dicts_extra.py', 'a', encoding='utf-8') as f:
        f.write('\n'.join(blk))
    print(f'i18n_dicts_extra.py: +{len(items)} SK + {len(items)} DE')

for cs, (en, es, sk, de) in items:
    print(f'  {cs!r:44s} | {en!r:42s} | {sk!r} | {de!r}')
if skipped:
    print('skipped:', [(r, s) for r, s in skipped])
