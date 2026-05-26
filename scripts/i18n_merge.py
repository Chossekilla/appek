#!/usr/bin/env python3
"""Merge batch translation outputs into the dictionaries.

  en/es -> new [cs,en,es] tuples appended to I18N_PHRASES in admin/i18n_auto.js
  sk/de -> SK_EXTRA.update / DE_EXTRA.update appended to scripts/i18n_dicts_extra.py

`cs` strings are JS-unescaped first so the dictionary key matches the rendered
DOM text node (e.g. source 'Polo\\u017eky' -> key 'Polozky' with real diacritics).
"""
import json, re, sys
from collections import Counter

ROOT = '/Users/chossekilaimac/projects/appek.cz'
MARK = 'v3.0.58 dopreklady B19 (Wolt/Bolt/Dame/Foodora + mobile UI)'

def js_unescape(s):
    out, i = [], 0
    while i < len(s):
        c = s[i]
        if c == '\\' and i + 1 < len(s):
            n = s[i+1]
            if n == 'u' and re.fullmatch(r'[0-9a-fA-F]{4}', s[i+2:i+6] or ''):
                out.append(chr(int(s[i+2:i+6], 16))); i += 6; continue
            if n == 'x' and re.fullmatch(r'[0-9a-fA-F]{2}', s[i+2:i+4] or ''):
                out.append(chr(int(s[i+2:i+4], 16))); i += 4; continue
            mp = {'n': '\n', 't': '\t', 'r': '\r', 'b': '\b', 'f': '\f',
                  '\\': '\\', "'": "'", '"': '"', '/': '/', '`': '`', '$': '$'}
            if n in mp:
                out.append(mp[n]); i += 2; continue
            out.append(c); i += 1; continue
        out.append(c); i += 1
    res = ''.join(out)
    try:
        res = res.encode('utf-16', 'surrogatepass').decode('utf-16')
    except Exception:
        pass
    return res

auto = open(f'{ROOT}/admin/i18n_auto.js', encoding='utf-8').read()
if MARK in auto:
    sys.exit('ALREADY MERGED (marker present in i18n_auto.js) -- aborting')

auto_keys = set()
for m in re.finditer(r"\[\s*'((?:[^'\\]|\\.)*)'\s*,\s*'((?:[^'\\]|\\.)*)'\s*,\s*'((?:[^'\\]|\\.)*)'\s*\]", auto):
    auto_keys.add(m.group(1).replace("\\'", "'").replace('\\\\', '\\'))

raw = []
import os.path
for i in range(1, 19):
    path = f'{ROOT}/scripts/i18n_batch_{i:02d}_output.json'
    if not os.path.exists(path): continue  # 🆕 v3.0.58: tolerantní k chybějícím batches
    for x in json.load(open(path, encoding='utf-8')):
        cs = js_unescape(x['cs']).strip()
        vals = tuple(js_unescape(x[k]).strip() for k in ('en', 'es', 'sk', 'de'))
        raw.append((cs, *vals))

best, skipped = {}, []
for cs, en, es, sk, de in raw:
    if len(cs) < 2:
        skipped.append(('tooshort', cs)); continue
    try:
        for v in (cs, en, es, sk, de): v.encode('utf-8')
    except Exception:
        skipped.append(('badchar', cs)); continue
    pt = (en == cs and es == cs and sk == cs and de == cs)
    if cs not in best or (best[cs][4] and not pt):     # prefer non-passthrough
        best[cs] = (en or cs, es or cs, sk or cs, de or cs, pt)

entries = {}
for cs, (en, es, sk, de, pt) in best.items():
    if cs in auto_keys:
        skipped.append(('covered', cs)); continue
    if pt:
        skipped.append(('passthrough', cs)); continue
    entries[cs] = (en, es, sk, de)

print(f'raw {len(raw)} | unique-new {len(entries)} | skipped {len(skipped)}')
print('skip reasons:', dict(Counter(r for r, _ in skipped)))
print(f"entries with apostrophe in cs: {sum(1 for cs in entries if chr(39) in cs)}")

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
step = max(1, len(items) // 22)
for cs, (en, es, sk, de) in items[::step][:22]:
    print(f'  {cs!r:38s} | {en!r:24s} | {de!r:24s} | {sk!r}')
if skipped:
    print('\n--- skipped sample ---')
    for r, s in skipped[:15]:
        print(f'  [{r}] {s!r}')
