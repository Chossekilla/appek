#!/usr/bin/env python3
"""Merge B2B translation batches into B2B_PHRASES in b2b/i18n.js."""
import json, re, sys
from collections import Counter

ROOT = '/Users/chossekilaimac/projects/appek.cz'
MARK = 'b2b EN/ES doplneni 2026-05-22'

def js_unescape(s):
    out, i = [], 0
    while i < len(s):
        c = s[i]
        if c == '\\' and i + 1 < len(s):
            n = s[i+1]
            if n == 'u' and re.fullmatch(r'[0-9a-fA-F]{4}', s[i+2:i+6] or ''):
                out.append(chr(int(s[i+2:i+6], 16))); i += 6; continue
            mp = {'n': '\n', 't': '\t', 'r': '\r', '\\': '\\', "'": "'", '"': '"', '/': '/'}
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

def jq(s):
    return (s.replace('\\', '\\\\').replace("'", "\\'")
             .replace('\n', '\\n').replace('\r', '\\r').replace('\t', '\\t'))

i18n = open(f'{ROOT}/b2b/i18n.js', encoding='utf-8').read()
if MARK in i18n:
    sys.exit('ALREADY MERGED -- aborting')

covered = set()
for m in re.finditer(r"\[\s*'((?:[^'\\]|\\.)*)'\s*,", i18n):
    covered.add(js_unescape(m.group(1)))

raw_entries = []
for i in (1, 2):
    for x in json.load(open(f'{ROOT}/scripts/b2b_batch_{i:02d}_output.json', encoding='utf-8')):
        cs = js_unescape(x['cs']).strip()
        en = js_unescape(x['en']).strip()
        es = js_unescape(x['es']).strip()
        raw_entries.append((cs, en, es))

entries = {}
skipped = []
for cs, en, es in raw_entries:
    if len(cs) < 2: skipped.append(('tooshort', cs)); continue
    try:
        for v in (cs, en, es): v.encode('utf-8')
    except Exception:
        skipped.append(('badchar', cs)); continue
    if cs in covered:
        skipped.append(('covered', cs)); continue
    if en == cs and es == cs:
        skipped.append(('passthrough', cs)); continue
    if cs not in entries:
        entries[cs] = (en or cs, es or cs)

print(f'raw {len(raw_entries)} | unique-new {len(entries)} | skipped {len(skipped)}')
print('skip reasons:', dict(Counter(r for r, _ in skipped)))

items = sorted(entries.items(), key=lambda kv: kv[0].lower())
js_lines = [f'  // {MARK} (+{len(items)})']
js_lines += [f"  ['{jq(cs)}', '{jq(en)}', '{jq(es)}']," for cs, (en, es) in items]

lines = i18n.split('\n')
start = next(i for i, l in enumerate(lines) if l.startswith('const B2B_PHRASES'))
end = next(i for i in range(start+1, len(lines)) if lines[i] == '];')
lines[end:end] = js_lines
open(f'{ROOT}/b2b/i18n.js', 'w', encoding='utf-8').write('\n'.join(lines))
print(f'b2b/i18n.js: +{len(items)} tuples before line {end+1}')

print('\n--- sample (cs | en | es) ---')
step = max(1, len(items) // 18)
for cs, (en, es) in items[::step][:18]:
    print(f'  {cs!r:36} | {en!r:32} | {es!r}')
