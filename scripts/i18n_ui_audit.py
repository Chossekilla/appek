#!/usr/bin/env python3
"""Audit admin.js for Czech UI strings vs. the i18n dictionaries.

Precise tag-scoped extraction (low false-positive). Buckets every candidate:
  MISSING     - not in any dictionary -> needs a new translation entry
  EMOJI_ONLY  - in dict once leading emoji stripped (translator bug)
  COVERED     - already translatable
"""
import re, json, collections

ROOT = '/Users/chossekilaimac/projects/appek.cz'
admin  = open(f'{ROOT}/admin/admin.js',     encoding='utf-8').read()
auto   = open(f'{ROOT}/admin/i18n_auto.js', encoding='utf-8').read()
i18njs = open(f'{ROOT}/admin/i18n.js',      encoding='utf-8').read()

# ---- covered set: CS keys from i18n_auto.js triples + i18n.js hardcoded ----
covered = set()
for m in re.finditer(r"\[\s*'((?:[^'\\]|\\.)*)'\s*,\s*'((?:[^'\\]|\\.)*)'\s*,\s*'((?:[^'\\]|\\.)*)'\s*\]", auto):
    covered.add(m.group(1).replace("\\'", "'").replace('\\\\', '\\'))
for m in re.finditer(r"cs:\s*'((?:[^'\\]|\\.)*)'", i18njs):
    covered.add(m.group(1).replace("\\'", "'"))

LETTER = re.compile(r'[A-Za-zěščřžýáíéúůóťďňĚŠČŘŽÝÁÍÉÚŮÓŤĎŇ]')
EMOJI  = re.compile('^(?:[\U0001F000-\U0001FAFF☀-➿⌀-⏿'
                     '⬀-⯿←-⇿️⃣‍]+\\s*)+')
CLEAN  = r"([^<>${}`\n]+?)"

PATTS = [
    (rf'<th[^>]*>{CLEAN}</th>',                       'th'),
    (rf'<button[^>]*>{CLEAN}</button>',               'button'),
    (rf'<option[^>]*>{CLEAN}</option>',               'option'),
    (rf'<label[^>]*>{CLEAN}</label>',                 'label-el'),
    (rf'<h[1-6][^>]*>{CLEAN}</h[1-6]>',               'heading'),
    (rf'<a [^>]*>{CLEAN}</a>',                        'link'),
    (rf'<summary[^>]*>{CLEAN}</summary>',             'summary'),
    (rf'<div class="stat-label"[^>]*>{CLEAN}</div>',  'stat-label'),
    (rf'<div class="empty-state"[^>]*>{CLEAN}</div>', 'empty'),
    (rf'placeholder="([^"${{}}`\n]+)"',               'placeholder'),
    (rf"placeholder='([^'${{}}`\n]+)'",               'placeholder'),
    (rf'\btitle="([^"${{}}`\n]+)"',                   'title-attr'),
    (rf"\b(?:l|label|title|nadpis|hint)\s*:\s*'([^'${{}}`\n]+)'", 'obj-prop'),
]

def ok(s):
    if not (2 <= len(s) <= 120): return False
    if not LETTER.search(s): return False
    if re.fullmatch(r'#[0-9A-Fa-f]{3,8}', s): return False
    if re.search(r'(=>|\+ esc\(|\breturn \b|\bfunction\b)', s): return False
    return True

cand = collections.defaultdict(set)
for rx, kind in PATTS:
    for m in re.finditer(rx, admin):
        t = m.group(1).strip()
        if ok(t): cand[t].add(kind)

missing, emoji_only, covered_hit = {}, {}, {}
for t, kinds in cand.items():
    if t in covered:
        covered_hit[t] = kinds; continue
    st = EMOJI.sub('', t).strip()
    if st and st != t and st in covered:
        emoji_only[t] = kinds
    else:
        missing[t] = sorted(kinds)

print(f'covered dictionary phrases : {len(covered)}')
print(f'admin.js UI candidates     : {len(cand)}')
print(f'  COVERED                  : {len(covered_hit)}')
print(f'  EMOJI_ONLY (translator)  : {len(emoji_only)}')
print(f'  MISSING (need entry)     : {len(missing)}')

short = {t: k for t, k in missing.items() if len(t) <= 32}
long  = {t: k for t, k in missing.items() if len(t) >  32}
print(f'    short (<=32 chars)     : {len(short)}')
print(f'    long  (>32 chars)      : {len(long)}')

by_kind = collections.Counter(ks[0] for ks in missing.values())
print('\nMISSING by primary kind:')
for k, n in by_kind.most_common():
    print(f'  {n:4d}  {k}')

print('\n--- MISSING short, sorted (visible chrome) ---')
for t in sorted(short):
    print(f'  {t!r}  [{",".join(short[t])}]')

json.dump({'missing_short': sorted(short), 'missing_long': sorted(long),
           'emoji_only': sorted(emoji_only)},
          open(f'{ROOT}/scripts/i18n_missing_ui.json', 'w', encoding='utf-8'),
          ensure_ascii=False, indent=1)
print('\nwrote scripts/i18n_missing_ui.json')
