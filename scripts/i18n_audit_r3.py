#!/usr/bin/env python3
"""Round-3 audit — scan admin.js + PHP for text the browser actually renders
(incl. fragments around interpolations / inline tags), simulate the FIXED
translator (icon + number + colon strip), report strings that still won't
translate. Output = dictionary keys that need a translation entry.
"""
import re, json, collections

ROOT = '/Users/chossekilaimac/projects/appek.cz'
admin  = open(f'{ROOT}/admin/admin.js', encoding='utf-8').read()
auto   = open(f'{ROOT}/admin/i18n_auto.js', encoding='utf-8').read()
i18njs = open(f'{ROOT}/admin/i18n.js', encoding='utf-8').read()
cfg    = open(f'{ROOT}/api/config.php', encoding='utf-8').read()
emd    = open(f'{ROOT}/api/_email_html_designs.php', encoding='utf-8').read()

covered = set()
for m in re.finditer(r"\[\s*'((?:[^'\\]|\\.)*)'\s*,", auto):
    covered.add(m.group(1).replace("\\'", "'").replace('\\\\', '\\'))
for m in re.finditer(r"cs:\s*'((?:[^'\\]|\\.)*)'", i18njs):
    covered.add(m.group(1).replace("\\'", "'"))

CZ = 'ěščřžýáíéúůóťďňĚŠČŘŽÝÁÍÉÚŮÓŤĎŇ'
CZRE = re.compile('[' + CZ + ']')
LETTER = re.compile('[A-Za-z' + CZ + ']')

def strip_icon(s):                       # mirrors i18nStripIconPrefix
    i = 0
    while i < len(s):
        c = ord(s[i])
        if c >= 0x2000 or c in (35, 42, 43, 0xB7): i += 1; continue
        break
    if i == 0 or i >= len(s) or not s[i].isspace(): return None
    j = i
    while j < len(s) and s[j].isspace(): j += 1
    return s[j:]

def core_of(s):                          # mirrors i18nLookupFlexible stripping
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
    return bool(re.search(r'[;=]|=>|\|\||&&|\bfunction\b|\breturn\b|\.\w{2,}\(|\bconst |\blet |\bvar ', s))

def ok(s):
    if not (2 <= len(s) <= 200): return False
    if not LETTER.search(s): return False
    if is_code(s) or '$' in s or '\\' in s: return False
    if s[0] in '":,);': return False                        # mid-sentence fragment
    if ' ' not in s and re.search(r'[a-z][A-Z]', s): return False  # CamelCase identifier
    return True

cand = collections.Counter()
for m in re.finditer(r'>([^<>{}`\n]+?)(?=[<{`])', admin):           # element text + fragments
    t = m.group(1).strip()
    if ok(t): cand[t] += 1
for m in re.finditer(r'}([^<>{}`\n]+?)(?=[<{`])', admin):           # text after ${...}
    t = m.group(1).strip()
    if ok(t) and CZRE.search(t): cand[t] += 1
for m in re.finditer(r'(?:placeholder|title)="([^"<>{}`\n]+)"', admin):
    t = m.group(1).strip()
    if ok(t): cand[t] += 1
for m in re.finditer(r"\b(?:nadpis|popis|desc|hint|tip|info|note|poznamka|msg|label|nazev|title)\s*:\s*'((?:[^'\\]|\\.)+?)'", admin):
    t = m.group(1).replace("\\'", "'").strip()
    if ok(t): cand[t] += 1
for m in re.finditer(r"stStepHint\(\s*\d+\s*,\s*'((?:[^'\\]|\\.)+?)'", admin):
    t = m.group(1).replace("\\'", "'").strip()
    if ok(t): cand[t] += 1
for php in (cfg, emd):
    for m in re.finditer(r"'(?:popis|subject|predmet|nazev|name)'\s*=>\s*'((?:[^'\\]|\\.)+?)'", php):
        t = m.group(1).replace("\\'", "'").strip()
        if ok(t) and CZRE.search(t): cand[t] += 1

miss = {}
for t in cand:
    if resolves(t): continue
    key = core_of(t).strip()
    if len(key) < 2 or key in covered: continue
    miss.setdefault(key, t)

print(f'candidates {len(cand)} | genuine misses (dict keys needed) {len(miss)}')
keys = sorted(miss)
json.dump(keys, open(f'{ROOT}/scripts/i18n_missing_r3.json', 'w', encoding='utf-8'),
          ensure_ascii=False, indent=1)
print(f'wrote scripts/i18n_missing_r3.json ({len(keys)})')
for k in keys[::max(1, len(keys)//55)][:55]:
    print(f'  {k!r}')
