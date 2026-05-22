#!/usr/bin/env python3
"""Audit admin.js for Czech UI strings vs. the i18n dictionaries.

Buckets every candidate string:
  MISSING       - not in any dictionary -> needs a new translation entry
  EMOJI_ONLY    - in dict once leading emoji is stripped (translator bug)
  COVERED       - already translatable
Also flags how many covered phrases sit in <select>/<option>/placeholder
context (skipped by the runtime walker).
"""
import re, json, collections

ROOT = '/Users/chossekilaimac/projects/appek.cz'
admin  = open(f'{ROOT}/admin/admin.js',     encoding='utf-8').read()
auto   = open(f'{ROOT}/admin/i18n_auto.js', encoding='utf-8').read()
i18njs = open(f'{ROOT}/admin/i18n.js',      encoding='utf-8').read()

# ---- covered set: CS keys from i18n_auto.js triples + i18n.js hardcoded ----
covered = set()
for m in re.finditer(r"\[\s*'((?:[^'\\]|\\.)*)'\s*,\s*'((?:[^'\\]|\\.)*)'\s*,\s*'((?:[^'\\]|\\.)*)'\s*\]", auto):
    covered.add(m.group(1).encode().decode('unicode_escape') if '\\' in m.group(1) else m.group(1))
for m in re.finditer(r"cs:\s*'((?:[^'\\]|\\.)*)'", i18njs):
    covered.add(m.group(1).replace("\\'", "'"))

LETTER = re.compile(r'[A-Za-zěščřžýáíéúůóťďňĚŠČŘŽÝÁÍÉÚŮÓŤĎŇ]')
EMOJI  = re.compile(r'^[\U0001F000-\U0001FAFF☀-➿⌀-⏿⬀-⯿'
                    r'←-⇿️‍⃣]+\s*')

def ok(s):
    s = s.strip()
    if not (2 <= len(s) <= 90): return False
    if '${' in s or '\\' in s or '=' in s or '|' in s: return False
    if not LETTER.search(s): return False
    if s.count(' ') > 10: return False
    return True

cand = collections.defaultdict(set)   # text -> {source kinds}
for rx, kind in [
    (r'>\s*([^<>{}]+?)\s*<',         'text'),
    (r'placeholder="([^"{}]+)"',     'placeholder'),
    (r"placeholder='([^'{}]+)'",     'placeholder'),
    (r'\btitle="([^"{}]+)"',         'title'),
    (r"\bl:\s*'([^'{}]+)'",          'label'),
    (r"\blabel:\s*'([^'{}]+)'",      'label'),
]:
    for m in re.finditer(rx, admin):
        t = m.group(1).strip()
        if ok(t): cand[t].add(kind)

missing, emoji_only, covered_hit = {}, {}, {}
for t, kinds in cand.items():
    if t in covered:
        covered_hit[t] = kinds
        continue
    stripped = EMOJI.sub('', t).strip()
    if stripped and stripped != t and stripped in covered:
        emoji_only[t] = kinds
    else:
        missing[t] = sorted(kinds)

print(f'covered dictionary phrases : {len(covered)}')
print(f'admin.js UI candidates     : {len(cand)}')
print(f'  COVERED (translatable)   : {len(covered_hit)}')
print(f'  EMOJI_ONLY (translator)  : {len(emoji_only)}')
print(f'  MISSING (need entry)     : {len(missing)}')

sel = sum(1 for k in covered_hit.values() if 'placeholder' in k)
print(f'covered-but-in-placeholder : {sel}')

by_kind = collections.Counter()
for ks in missing.values():
    by_kind[tuple(ks)] += 1
print('\nMISSING by source kind:')
for k, n in by_kind.most_common():
    print(f'  {n:4d}  {", ".join(k)}')

print('\n--- EMOJI_ONLY samples (translator bug, no new entry needed) ---')
for t in sorted(emoji_only)[:15]:
    print(f'  {t!r}')

print('\n--- MISSING samples (first 60, sorted) ---')
for t in sorted(missing)[:60]:
    print(f'  {t!r}  [{",".join(missing[t])}]')

json.dump({'missing': sorted(missing), 'emoji_only': sorted(emoji_only)},
          open(f'{ROOT}/scripts/i18n_missing_ui.json', 'w', encoding='utf-8'),
          ensure_ascii=False, indent=1)
print(f'\nwrote scripts/i18n_missing_ui.json ({len(missing)} missing)')
