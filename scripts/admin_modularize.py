#!/usr/bin/env python3
"""🆕 v3.0.361 — admin.js modularizace (BEZPEČNÝ concat-split).

Rozseká admin/admin.js na admin/src/NNNN-slug.js na hranicích sekčních bannerů
(// ====/title/// ====). Konkatenace src/*.js (seřazeno) == PŮVODNÍ admin.js
BAJTOVĚ (ověřeno assertem) → runtime se NEMĚNÍ. admin.js se needituje.

Build (build-update.sh) pak regeneruje admin.js z src/. Editovat se má src/, ne admin.js.
Idempotentní: smaže staré admin/src/*.js a vytvoří znovu.
"""
import os, re, sys, unicodedata, glob

ROOT = '/Users/chossekilaimac/projects/appek.cz'
SRC = f'{ROOT}/admin/admin.js'
OUTDIR = f'{ROOT}/admin/src'

BANNER = re.compile(r'^// ={5,}\s*$')

def slug(title):
    t = title.lstrip('/').strip()
    t = ''.join(c for c in unicodedata.normalize('NFKD', t) if not unicodedata.combining(c))
    t = re.sub(r'[^A-Za-z0-9 ]', '', t).strip().lower()
    t = re.sub(r'\s+', '-', t)[:40]
    return t or 'section'

lines = open(SRC, encoding='utf-8').readlines()  # zachovává \n
n = len(lines)

# najdi začátky sekcí = trojice banner / //title / banner
starts = [0]
i = 0
while i < n - 2:
    if BANNER.match(lines[i]) and lines[i+1].lstrip().startswith('//') and BANNER.match(lines[i+2]):
        if i != 0:
            starts.append(i)
        i += 3
        continue
    i += 1
starts = sorted(set(starts))

# vyčisti starý src/
if os.path.isdir(OUTDIR):
    for f in glob.glob(f'{OUTDIR}/*.js'):
        os.remove(f)
os.makedirs(OUTDIR, exist_ok=True)

written = []
for idx, s in enumerate(starts):
    e = starts[idx+1] if idx+1 < len(starts) else n
    chunk = lines[s:e]
    if idx == 0:
        name = 'preamble'
    else:
        name = slug(chunk[1]) if len(chunk) > 1 else 'section'
    fn = f'{idx*10:04d}-{name}.js'
    path = f'{OUTDIR}/{fn}'
    with open(path, 'w', encoding='utf-8') as fh:
        fh.write(''.join(chunk))
    written.append((fn, s+1, e))

# OVĚŘENÍ bajtové identity: concat seřazených src == původní admin.js
concat = ''.join(open(f'{OUTDIR}/{fn}', encoding='utf-8').read() for fn, _, _ in sorted(written))
orig = ''.join(lines)
if concat != orig:
    print('❌ BAJTOVÁ IDENTITA SELHALA — split NEPOUŽÍVAT!')
    # diagnostika: kde se liší
    for k in range(min(len(concat), len(orig))):
        if concat[k] != orig[k]:
            print(f'  první rozdíl na znaku {k}: concat={concat[k-20:k+20]!r} orig={orig[k-20:k+20]!r}')
            break
    print(f'  délky: concat={len(concat)} orig={len(orig)}')
    sys.exit(1)

print(f'✅ {len(written)} src souborů, concat == admin.js BAJTOVĚ ({len(orig)} B)')
print(f'   první: {written[0][0]} (ř.{written[0][1]}-{written[0][2]})')
print(f'   poslední: {written[-1][0]} (ř.{written[-1][1]}-{written[-1][2]})')
print(f'   ukázka názvů: {[w[0] for w in written[1:6]]}')
