#!/usr/bin/env python3
"""Filter + normalize the missing-UI list, split into translation batches.

Normalization mirrors the runtime i18nStripIconPrefix in i18n_auto.js: a leading
run of icon chars (code >= 0x2000, or # * +) followed by whitespace is stripped,
so the dictionary key is the bare phrase. If the bare phrase already exists in
I18N_PHRASES the runtime icon-strip already covers it -> dropped.
"""
import json, re, math

ROOT = '/Users/chossekilaimac/projects/appek.cz'

# CS keys already in i18n_auto.js (what the runtime translator uses)
auto = open(f'{ROOT}/admin/i18n_auto.js', encoding='utf-8').read()
auto_keys = set()
for m in re.finditer(r"\[\s*'((?:[^'\\]|\\.)*)'\s*,\s*'((?:[^'\\]|\\.)*)'\s*,\s*'((?:[^'\\]|\\.)*)'\s*\]", auto):
    auto_keys.add(m.group(1).replace("\\'", "'").replace('\\\\', '\\'))

def strip_icon(s):
    """Return the bare phrase after a leading icon prefix, or s unchanged."""
    i = 0
    while i < len(s):
        c = ord(s[i])
        if c >= 0x2000 or c in (35, 42, 43):
            i += 1; continue
        break
    if i == 0 or i >= len(s) or not s[i].isspace():
        return s
    j = i
    while j < len(s) and s[j].isspace():
        j += 1
    return s[j:]

def is_junk(s):
    if s.startswith('/'): return True
    if s.startswith('http') and ' ' not in s: return True
    if 'XXXX' in s or 'xxxx' in s: return True
    if re.fullmatch(r'[\w./\-]+', s) and re.search(r'\.(png|jpg|jpeg|js|css|php|pdf|svg|csv|xml|json|zip)$', s):
        return True
    if re.fullmatch(r'#[0-9A-Fa-f]{3,8}', s): return True
    if re.fullmatch(r'[A-Z]{2}\d[\d /A-Z]*', s): return True
    if re.fullmatch(r'[A-Z][A-Z0-9]*-[A-Z0-9\-]+', s): return True
    if re.fullmatch(r'[\d .,–-]+', s): return True
    return False

data = json.load(open(f'{ROOT}/scripts/i18n_missing_ui.json', encoding='utf-8'))
raw = sorted(set(data['missing_short'] + data['missing_long']))

keep, dropped, already = set(), [], 0
for s in raw:
    bare = strip_icon(s).strip()
    if len(bare) < 2:
        dropped.append(s); continue
    if bare in auto_keys:
        already += 1; continue          # runtime icon-strip already covers it
    if is_junk(bare):
        dropped.append(bare); continue
    keep.add(bare)

clean = sorted(keep, key=lambda s: s.lower())
print(f'raw missing {len(raw)} | already-covered-after-strip {already} | '
      f'dropped {len(dropped)} | to translate {len(clean)}')

N = 6
per = math.ceil(len(clean) / N)
for i in range(N):
    chunk = clean[i*per:(i+1)*per]
    if not chunk: continue
    json.dump(chunk, open(f'{ROOT}/scripts/i18n_batch_{i+1:02d}_input.json', 'w', encoding='utf-8'),
              ensure_ascii=False, indent=1)
    print(f'batch {i+1:02d}: {len(chunk):3d}  ({chunk[0]!r} .. {chunk[-1]!r})')
json.dump(sorted(set(dropped)), open(f'{ROOT}/scripts/i18n_dropped.json', 'w', encoding='utf-8'),
          ensure_ascii=False, indent=1)
