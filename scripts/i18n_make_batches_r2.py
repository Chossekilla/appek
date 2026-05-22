#!/usr/bin/env python3
"""Split the round-2 missing list into translation batches 08..13."""
import json, re, math

ROOT = '/Users/chossekilaimac/projects/appek.cz'
items = json.load(open(f'{ROOT}/scripts/i18n_missing_r2.json', encoding='utf-8'))

def is_junk(s):
    if re.fullmatch(r'#[0-9A-Fa-f]{3,8}', s): return True
    if s in ('[Promise]', '[object Object]', 'undefined', 'null'): return True
    if re.fullmatch(r'[\d.,×%/ ()–:×-]+', s): return True
    if s.startswith('\\u') or s.startswith('\\x'): return True
    return False

clean = sorted({s for s in items if not is_junk(s)}, key=str.lower)
dropped = [s for s in items if is_junk(s)]
print(f'r2 missing {len(items)} | clean {len(clean)} | dropped {len(dropped)}')

N = 6
per = math.ceil(len(clean) / N)
for i in range(N):
    chunk = clean[i*per:(i+1)*per]
    if not chunk: continue
    num = 8 + i
    json.dump(chunk, open(f'{ROOT}/scripts/i18n_batch_{num:02d}_input.json', 'w', encoding='utf-8'),
              ensure_ascii=False, indent=1)
    print(f'batch {num:02d}: {len(chunk):3d}  ({chunk[0]!r:.40} .. {chunk[-1]!r:.40})')
