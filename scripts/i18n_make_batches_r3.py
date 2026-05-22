#!/usr/bin/env python3
"""Filter the round-3 missing list and split into translation batches 14..18."""
import json, re, math

ROOT = '/Users/chossekilaimac/projects/appek.cz'
items = json.load(open(f'{ROOT}/scripts/i18n_missing_r3.json', encoding='utf-8'))

def is_junk(s):
    if re.fullmatch(r'#[0-9A-Fa-f]{3,8}', s): return True
    if re.search(r'https?://|@|\.(xml|csv|js|php|png|app|isdoc|zip)\b', s): return True
    if "'+" in s or "+'" in s or '+esc' in s or '),' in s or s.startswith('+'):
        return True                                                  # concat artifacts
    if re.fullmatch(r'[A-Z][a-zA-Z]+', s) and re.search(r'[A-Z].*[A-Z]', s):
        return True                                                  # CamelCase / ALLCAPS code
    if re.fullmatch(r'[\d.,×%/ ()–:-]+', s): return True
    if not re.search(r'[A-Za-zěščřžýáíéúůóťďňĚŠČŘŽÝÁÍÉ]{3,}', s): return True
    return False

clean = sorted({s for s in items if not is_junk(s)}, key=str.lower)
dropped = sorted({s for s in items if is_junk(s)})
print(f'r3 missing {len(items)} | clean {len(clean)} | dropped {len(dropped)}')

N = 5
per = math.ceil(len(clean) / N)
for i in range(N):
    chunk = clean[i*per:(i+1)*per]
    if not chunk: continue
    num = 14 + i
    json.dump(chunk, open(f'{ROOT}/scripts/i18n_batch_{num:02d}_input.json', 'w', encoding='utf-8'),
              ensure_ascii=False, indent=1)
    print(f'batch {num:02d}: {len(chunk):3d}  ({chunk[0]!r:.34} .. {chunk[-1]!r:.34})')
