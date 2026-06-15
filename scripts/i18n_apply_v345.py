#!/usr/bin/env python3
"""Apply the v3.0.345 UI translation backlog into the dictionaries.

  en/es -> new [cs,en,es] tuples appended to I18N_PHRASES in admin/i18n_auto.js
  sk/de -> SK_EXTRA.update / DE_EXTRA.update appended to scripts/i18n_dicts_extra.py

Source: scripts/i18n_new_v345.json (already js-decoded, bare-icon CS keys).
Idempotent: aborts if MARK already present in i18n_auto.js.
"""
import json, re, sys
from collections import Counter

ROOT = '/Users/chossekilaimac/projects/appek.cz'
MARK = 'v3.0.345 dopreklady UI (v330-344: skener/sezony/subkat/import/expedice/BOM/mena/vratky)'

auto = open(f'{ROOT}/admin/i18n_auto.js', encoding='utf-8').read()
if MARK in auto:
    sys.exit('ALREADY MERGED (marker present in i18n_auto.js) -- aborting')

auto_keys = set()
for m in re.finditer(r"\[\s*'((?:[^'\\]|\\.)*)'\s*,\s*'((?:[^'\\]|\\.)*)'\s*,\s*'((?:[^'\\]|\\.)*)'\s*\]", auto):
    auto_keys.add(m.group(1).replace("\\'", "'").replace('\\\\', '\\'))

rows = json.load(open(f'{ROOT}/scripts/i18n_new_v345.json', encoding='utf-8'))

entries, skipped = {}, []
for x in rows:
    cs = x['cs'].strip()
    en, es, sk, de = (x['en'].strip(), x['es'].strip(), x['sk'].strip(), x['de'].strip())
    if len(cs) < 2:
        skipped.append(('tooshort', cs)); continue
    if cs in auto_keys:
        skipped.append(('covered', cs)); continue
    if en == cs and es == cs and sk == cs and de == cs:
        skipped.append(('passthrough', cs)); continue
    if cs in entries:
        skipped.append(('dup', cs)); continue
    entries[cs] = (en, es, sk, de)

print(f'rows {len(rows)} | unique-new {len(entries)} | skipped {len(skipped)}')
print('skip reasons:', dict(Counter(r for r, _ in skipped)))

def jq(s):
    return (s.replace('\\', '\\\\').replace("'", "\\'").replace('\n', '\\n')
             .replace('\r', '\\r').replace('\t', '\\t'))
def pq(s):
    return (s.replace('\\', '\\\\').replace("'", "\\'").replace('\n', '\\n')
             .replace('\r', '\\r').replace('\t', '\\t'))

items = sorted(entries.items(), key=lambda kv: kv[0].lower())

# --- i18n_auto.js: insert tuples before the I18N_PHRASES ']' ---
js_lines = [f'  // {MARK} (auto-audit, +{len(items)})']
js_lines += [f"  ['{jq(cs)}', '{jq(en)}', '{jq(es)}']," for cs, (en, es, sk, de) in items]
au = auto.split('\n')
ci = next(i for i, l in enumerate(au)
          if l.strip() == '];' and i + 2 < len(au) and 'Build lookup index' in au[i+2])
au[ci:ci] = js_lines
open(f'{ROOT}/admin/i18n_auto.js', 'w', encoding='utf-8').write('\n'.join(au))
print(f'i18n_auto.js: +{len(items)} tuples before line {ci+1}')

# --- i18n_dicts_extra.py: append SK/DE update blocks ---
blk = ['', f'# {MARK} (auto-audit, +{len(items)})', 'SK_EXTRA.update({']
blk += [f"    '{pq(cs)}': '{pq(sk)}'," for cs, (en, es, sk, de) in items]
blk += ['})', 'DE_EXTRA.update({']
blk += [f"    '{pq(cs)}': '{pq(de)}'," for cs, (en, es, sk, de) in items]
blk += ['})', '']
with open(f'{ROOT}/scripts/i18n_dicts_extra.py', 'a', encoding='utf-8') as f:
    f.write('\n'.join(blk))
print(f'i18n_dicts_extra.py: +{len(items)} SK + {len(items)} DE')

print('\n--- sample (cs | en | de | sk) ---')
step = max(1, len(items) // 20)
for cs, (en, es, sk, de) in items[::step][:20]:
    print(f'  {cs!r:38s} | {en!r:26s} | {de!r:26s} | {sk!r}')
if skipped:
    print('\n--- skipped sample ---')
    for r, s in skipped[:15]:
        print(f'  [{r}] {s!r}')
