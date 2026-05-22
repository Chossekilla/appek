#!/usr/bin/env python3
"""Round-2 broad audit — descriptive text, hints, help paragraphs, email templates.

Round 1 covered "chrome" (buttons/headers/labels/options). This catches the
descriptive long-tail: <p>/<small>/<span> help text, page subtitles, stStepHint()
step instructions, and email-template names from the PHP config.
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
WORD = re.compile('[A-Za-z' + CZ + ']{3,}')
CZWORD = re.compile('[' + CZ + ']')

def strip_icon(s):
    i = 0
    while i < len(s):
        c = ord(s[i])
        if c >= 0x2000 or c in (35, 42, 43): i += 1; continue
        break
    if i == 0 or i >= len(s) or not s[i].isspace(): return s
    j = i
    while j < len(s) and s[j].isspace(): j += 1
    return s[j:]

def ok(s, multiword=False):
    s = s.strip()
    if not (3 <= len(s) <= 220): return False
    if '${' in s or s.count('\\') > 3: return False
    if re.match(r'^(https?://|/|www\.)', s): return False
    if '@' in s and re.fullmatch(r'[\w.\-/@: ,]+', s): return False
    if not WORD.search(s): return False
    if multiword and len(s) < 5 and ' ' not in s: return False
    return True

cand = collections.defaultdict(set)
def add(t, kind, multiword=False):
    t = t.strip()
    if ok(t, multiword): cand[t].add(kind)

# admin.js — text-bearing tags (matched open/close via backreference)
for m in re.finditer(r"<(p|small|span|li|label|button|h[1-6]|td|th|summary|figcaption|a|b|strong|em)\b[^>]*>([^<>{}`]+?)</\1>", admin):
    add(m.group(2), 'tag')
# empty-state / hint divs
for m in re.finditer(r'<div class="(?:empty-state|hint|[a-z-]*-sub|[a-z-]*-hint)"[^>]*>([^<>{}`]+?)</div>', admin):
    add(m.group(1), 'div')
# stStepHint(n, '...')
for m in re.finditer(r"stStepHint\(\s*\d+\s*,\s*'((?:[^'\\]|\\.)+?)'\s*\)", admin):
    add(m.group(1).replace("\\'", "'"), 'stephint')
# object string properties
for m in re.finditer(r"\b(?:nadpis|popis|desc|description|hint|tip|info|note|poznamka|subtitle|heading|msg|text)\s*:\s*'((?:[^'\\]|\\.)+?)'", admin):
    add(m.group(1).replace("\\'", "'"), 'objprop', multiword=True)
# attributes
for m in re.finditer(r'(?:placeholder|title)="([^"<>{}`]+)"', admin):
    add(m.group(1), 'attr')
# PHP email templates
for src in (cfg, emd):
    for m in re.finditer(r"'(?:popis|subject|predmet|nazev|name|label|title)'\s*=>\s*'((?:[^'\\]|\\.)+?)'", src):
        add(m.group(1).replace("\\'", "'"), 'php-email', multiword=True)
    for m in re.finditer(r"\[\s*'((?:[^'\\]|\\.)+?)'\s*,\s*'#[0-9A-Fa-f]{3,8}'\s*\]", src):
        add(m.group(1).replace("\\'", "'"), 'php-email')

missing, emoji_ok, cov = {}, {}, {}
for t, kinds in cand.items():
    if t in covered:
        cov[t] = kinds; continue
    st = strip_icon(t)
    if st != t and st in covered:
        emoji_ok[t] = kinds
    else:
        missing[t] = sorted(kinds)

# dictionary key = icon-stripped bare form
keys = {}
for t in missing:
    k = strip_icon(t).strip()
    if len(k) >= 3 and k not in covered:
        keys.setdefault(k, missing[t])

print(f'candidates {len(cand)} | covered {len(cov)} | emoji-ok {len(emoji_ok)} | '
      f'MISSING {len(missing)} -> {len(keys)} unique keys')
print('MISSING by kind:', dict(collections.Counter(k[0] for k in missing.values())))
out = sorted(keys)
json.dump(out, open(f'{ROOT}/scripts/i18n_missing_r2.json', 'w', encoding='utf-8'),
          ensure_ascii=False, indent=1)
print(f'wrote scripts/i18n_missing_r2.json ({len(out)})')
print('--- sample (every Nth) ---')
for k in out[::max(1, len(out)//45)][:45]:
    print(f'  {k!r}')
