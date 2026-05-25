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

# Intentionally NOT translated (brand, language identifiers, codes, units).
SKIP = {
    # brand / external services
    'APPEK B2B', 'APPEK B2B s.r.o.', 'APPEK v…',
    'DPD CZ', 'GoPay', 'Stripe', 'PayPal', 'Wolt', 'Foodora', 'Bolt Food',
    # language identifiers (stay in their own language)
    'CS', 'EN', 'ES', 'SK', 'DE',
    'English', 'Español', 'Čeština', 'Slovenčina', 'Deutsch',
    'Jazyk / Language / Idioma',
    # short codes/abbreviations
    'DL', 'PDF', 'CSV', 'XLSX', 'IČO', 'DIČ', 'PSČ',
    # universal units
    'kg', 'ml', 'g', 'l', 'ks', 'm', 'cm', 'mm', '€', '$',
}

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
    if '[Promise]' in s: return True
    # JS string-concat artifacts (broken templates extracted as fragments)
    if "' +" in s or "+ '" in s or "+ esc(" in s or "+ fmt" in s: return True
    # Stray unmatched parens / brackets — JS expression artifact
    if (s.endswith(')') and '(' not in s) or (s.endswith(']') and '[' not in s): return True
    # JS punctuation/operators in UI text are suspect
    if re.search(r'[;=]|\|\||&&|\.\w{2,}\(', s): return True
    # JS keywords (unmistakable, rarely collide with CS/EN/ES UI text)
    if re.search(r'\b(?:catch|else|function|return|const|let|var|typeof|instanceof)\b', s): return True
    return False

def ok(s):
    if not (2 <= len(s) <= 200): return False
    if not LETTER.search(s): return False
    if s in SKIP: return False
    # URLs / file paths
    if s.startswith('/') or s.startswith('http://') or s.startswith('https://'): return False
    # Stray opening bracket / quote artifact
    if s.startswith('['): return False
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
    if len(key) < 2 or key in covered or key in SKIP: continue
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
