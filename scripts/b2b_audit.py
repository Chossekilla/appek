#!/usr/bin/env python3
"""B2B portal audit — find Czech UI strings in b2b/app.js + b2b/index.html
that aren't in B2B_PHRASES, simulating the (newly fixed) translator that
strips emoji/count/colon decorations and handles attributes.
"""
import re, json, collections

ROOT = '/Users/chossekilaimac/projects/appek.cz'
app  = open(f'{ROOT}/b2b/app.js', encoding='utf-8').read()
html = open(f'{ROOT}/b2b/index.html', encoding='utf-8').read()
i18n = open(f'{ROOT}/b2b/i18n.js', encoding='utf-8').read()

covered = set()
for m in re.finditer(r"\[\s*'((?:[^'\\]|\\.)*)'\s*,", i18n):
    covered.add(m.group(1).replace("\\'", "'").replace('\\\\', '\\'))

CZ = 'ěščřžýáíéúůóťďňĚŠČŘŽÝÁÍÉÚŮÓŤĎŇ'
LETTER = re.compile('[A-Za-z' + CZ + ']')

def strip_icon(s):
    i = 0
    while i < len(s):
        c = ord(s[i])
        if c >= 0x2000 or c in (35, 42, 43, 0xB7): i += 1; continue
        break
    if i == 0 or i >= len(s) or not s[i].isspace(): return None
    j = i
    while j < len(s) and s[j].isspace(): j += 1
    return s[j:]

def core_of(s):
    core = s
    r = strip_icon(core)
    if r is not None: core = r
    m = re.match(r'[0-9][0-9.,\s]*\s', core)
    if m: core = core[m.end():]
    if len(core) >= 3 and core[-1] in ':：': core = core[:-1]
    return core

def resolves(s):
    if s in covered: return True
    c = core_of(s)
    return c != s and len(c) >= 2 and c in covered

def is_code(s):
    if '//' in s or '/*' in s or '*/' in s or '=>' in s: return True
    return bool(re.search(r'[;=]|\|\||&&|\bfunction\b|\breturn\b|\.\w{2,}\(|\bconst |\blet |\bvar ', s))

def ok(s):
    if not (2 <= len(s) <= 200): return False
    if not LETTER.search(s): return False
    if is_code(s) or '$' in s or '\\' in s: return False
    if s[0] in '"),;:': return False
    return True

cand = collections.Counter()
for src in (app, html):
    for m in re.finditer(r'>([^<>{}`\n]+?)(?=[<{`])', src):
        t = m.group(1).strip()
        if ok(t): cand[t] += 1
    for m in re.finditer(r'}([^<>{}`\n]+?)(?=[<{`])', src):
        t = m.group(1).strip()
        if ok(t): cand[t] += 1
    for m in re.finditer(r'(?:placeholder|title|aria-label)="([^"<>{}`\n]+)"', src):
        t = m.group(1).strip()
        if ok(t): cand[t] += 1
    for m in re.finditer(r"(?:placeholder|title|aria-label)='([^'<>{}`\n]+)'", src):
        t = m.group(1).strip()
        if ok(t): cand[t] += 1
    for m in re.finditer(r"\b(?:l|label|title|nadpis|hint|popis|name|text|msg|info|tip|note)\s*:\s*'((?:[^'\\]|\\.)+?)'", src):
        t = m.group(1).replace("\\'", "'").strip()
        if ok(t) and (' ' in t or len(t) >= 5): cand[t] += 1

miss = {}
for t in cand:
    if resolves(t): continue
    key = core_of(t).strip()
    if len(key) < 2 or key in covered: continue
    miss.setdefault(key, t)

print(f'B2B_PHRASES covered: {len(covered)}')
print(f'candidates {len(cand)} | genuine misses {len(miss)}')
keys = sorted(miss)
json.dump(keys, open(f'{ROOT}/scripts/b2b_missing.json', 'w', encoding='utf-8'),
          ensure_ascii=False, indent=1)
print(f'wrote scripts/b2b_missing.json ({len(keys)})')
print('--- sample (every Nth) ---')
for k in keys[::max(1, len(keys)//45)][:45]:
    print(f'  {k!r}')
