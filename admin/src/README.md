# admin/src/ — modularizovaný admin.js

**`admin/admin.js` je GENEROVANÝ soubor** — vzniká spojením (`cat`) těchto zdrojových
souborů ve `scripts/build-update.sh`. Modularizace v3.0.361 (concat-split, bajtově
deterministická — runtime se nezměnil).

## ⚠️ Pravidla
- **Edituj soubory ZDE** (`admin/src/<NNNN-sekce>.js`), **NE `admin/admin.js`** — ten se
  při každém buildu přepíše a tvoje přímé úpravy admin.js by se ZTRATILY.
- Soubory se spojují v pořadí číselného prefixu (`0000`, `0010`, …) → `admin/admin.js`.

## Workflow
1. Uprav příslušný `admin/src/NNNN-*.js`.
2. `bash scripts/build-update.sh X.Y.Z` — regeneruje `admin.js` z src/ + bumpne verzi +
   spustí `node --check`.
3. Commitni `admin/src/` i vygenerovaný `admin/admin.js`.

## Ruční regenerace (bez buildu)
```
cat admin/src/*.js > admin/admin.js
```

## Recovery (kdybys omylem editoval admin.js přímo)
```
python3 scripts/admin_modularize.py
```
Re-rozseká AKTUÁLNÍ `admin/admin.js` zpět do `admin/src/` (a ověří bajtovou identitu) →
tvoje úpravy admin.js se tím zachytí do src/. Pak normálně commitni.
