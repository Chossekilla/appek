# 🌍 APPEK i18n — Translation Work Brief

> **Účel:** Self-contained brief pro paralelní Claude vlákno, které pracuje výhradně na překladech (SK + DE).
> Hlavní vlákno současně řeší grafiku / UI — **NEKONFLIKTUJTE** v souborech mimo `i18n_*`.

---

## 🎯 Cíl

Doplnit chybějící **slovenské (SK)** a **německé (DE)** překlady do APPEK admin aplikace.
Aktuální stav: ~55% pokrytí (8732 SK + 8639 DE z ~15800 unikátních českých frází).

**Hotovo bude pokud:**
- Pokrytí SK ≥ 95%
- Pokrytí DE ≥ 95%
- Web admin přepnutý na SK/DE neukazuje žádné české fráze (kromě <2 % zanedbatelných edge cases)

---

## 📂 Pracovní soubory — POUZE TYTO

Máš povoleno editovat **jen tyto 2 soubory**:

| Cesta | Účel | Velikost |
|---|---|---|
| `scripts/i18n_dicts_extra.py` | Source dictionary `SK_EXTRA` + `DE_EXTRA` (Python) | ~18400 řádků |
| `admin/i18n_extra.js` | Generated JS bundle pro browser | ~17400 řádků, 775 KB |

**Workflow:**
1. Edituj `scripts/i18n_dicts_extra.py` — přidávej nové páry do `SK_EXTRA` a `DE_EXTRA` dictů
2. Spusť `python3 scripts/gen_i18n_extra.py` (regenerace JS bundlu)
3. Commit do Gitu

**❌ NESMÍŠ editovat:**
- `admin/admin.js`, `admin/admin.css`, `admin/index.html`
- `admin/i18n.js`, `admin/i18n_auto.js` (jsou source-of-truth, generované odjinud)
- `pos/`, `floorplan/`, `api/`, `vendor/`, `b2b/`
- Cokoliv jiného mimo `scripts/i18n_dicts_extra.py` a `admin/i18n_extra.js`

---

## 🏗️ Architektura

### Jak i18n funguje

```
┌─────────────────────────────────────────────────────────────┐
│  admin/i18n.js                                              │
│  ───────────────                                            │
│  - hard-coded I18N dictionary (cca 300 klíčů)              │
│  - window.appekCurrentLang ('cs'|'sk'|'en'|'de'|'es')       │
│  - window.setAppekLang(code)                                │
│  - window.applyTranslations()  — pro [data-i18n]           │
└─────────────────────────────────────────────────────────────┘
            │
            ▼
┌─────────────────────────────────────────────────────────────┐
│  admin/i18n_auto.js  (source of truth)                      │
│  ─────────────────────                                       │
│  - I18N_PHRASES = [[cs, en, es], [cs, en, es], ...]         │
│  - cca 19400 entries                                        │
│  - translateTextNode(node) — DOM walker                     │
│  - window.translatePage(root)                               │
└─────────────────────────────────────────────────────────────┘
            │
            ▼
┌─────────────────────────────────────────────────────────────┐
│  admin/i18n_extra.js  ⭐ TVOJE PRÁCE ⭐                      │
│  ────────────────────                                        │
│  - I18N_EXTRA = { cs: {sk, de}, cs: {sk, de}, ... }        │
│  - 8732 SK + 8639 DE entries (čekáme na +7000)             │
│  - načteno PŘED i18n_auto.js v admin/index.html            │
│  - SK + DE overlay přebije EN/ES translation flow          │
└─────────────────────────────────────────────────────────────┘
```

### Jak generátor pracuje

`scripts/gen_i18n_extra.py`:
1. Čte `admin/i18n_auto.js`, extrahuje všechny CS fráze
2. Importuje `SK_EXTRA` a `DE_EXTRA` z `scripts/i18n_dicts_extra.py`
3. Pro každou CS frázi:
   - Hledá v `SK_EXTRA` nebo `DE_EXTRA` (přímý lookup)
   - Fallback na rule-based morfologii (CS → SK phonetic), pro DE: jen pokud má EN ekvivalent → použije anglický text jako přibližný DE
4. Generuje `admin/i18n_extra.js` jako `window.I18N_EXTRA = { ... }`

---

## 📋 Postup pro vlákno

### Krok 1: Identifikuj chybějící fráze

```bash
cd /Users/chossekilaimac/projects/appek.cz

# Najdi všechny CS fráze v i18n_auto.js
python3 -c "
import re
with open('admin/i18n_auto.js') as f: src = f.read()
# Najdi I18N_PHRASES bloky
phrases = re.findall(r\"\['([^']+)',\s*'([^']*)',\s*'([^']*)'\]\", src)
all_cs = set(p[0] for p in phrases)
print(f'Total unique CS phrases: {len(all_cs)}')

# Načti aktuální SK + DE
import sys
sys.path.insert(0, 'scripts')
from i18n_dicts_extra import SK_EXTRA, DE_EXTRA
missing_sk = all_cs - set(SK_EXTRA.keys())
missing_de = all_cs - set(DE_EXTRA.keys())
print(f'Missing SK: {len(missing_sk)}')
print(f'Missing DE: {len(missing_de)}')

# Vypiš prvních 50 chybějících SK
print('\n--- Missing SK (first 50) ---')
for p in sorted(missing_sk)[:50]:
    print(f'  {repr(p)}')
"
```

### Krok 2: Pracuj v batchích

**Doporučená velikost batche:** 100–200 frází najednou.

Otevři `scripts/i18n_dicts_extra.py`, najdi `SK_EXTRA = { ... }` blok a přidávej páry:

```python
SK_EXTRA = {
    # ... existující entries ...

    # 🆕 BATCH N (datum) — restaurace + POS terminologie
    'Vyhotovit dodací list': 'Vyhotoviť dodací list',
    'Generuji PDF…': 'Generujem PDF…',
    'Označit jako zaplacené': 'Označiť ako zaplatené',
    # ...
}
```

A stejně pro `DE_EXTRA`. Vytvářej **přesné překlady** — ne strojový translate-API output.

### Krok 3: Regeneruj JS bundle

```bash
python3 scripts/gen_i18n_extra.py
# Mělo by vypsat něco jako:
# ✓ Načteno X frází z i18n_auto.js
# ✓ SK pokrytí: NN% (YYYY z XXXX)
# ✓ DE pokrytí: NN% (YYYY z XXXX)
# ✓ Bundle: admin/i18n_extra.js  (NNN KB)
```

### Krok 4: Verify že JS bundle není rozbitý

```bash
node -e "
const code = require('fs').readFileSync('admin/i18n_extra.js', 'utf8');
const fn = new Function('window', code);
const w = {};
fn(w);
if (!w.I18N_EXTRA) { console.error('❌ window.I18N_EXTRA chybí'); process.exit(1); }
const keys = Object.keys(w.I18N_EXTRA);
const sk = keys.filter(k => w.I18N_EXTRA[k].sk).length;
const de = keys.filter(k => w.I18N_EXTRA[k].de).length;
console.log('✓ Bundle valid · CS keys:', keys.length, '· SK:', sk, '· DE:', de);
"
```

### Krok 5: Commit (každý batch jako samostatný commit)

```bash
cd /Users/chossekilaimac/projects/appek.cz
git add scripts/i18n_dicts_extra.py admin/i18n_extra.js
git commit -m "i18n: batch N — +150 SK + 150 DE (restaurace terminologie)"
```

**❌ NEPUSHUJ na origin** — jen lokální commits. Hlavní vlákno bude periodicky synchronizovat.

---

## 🎓 Překladatelské pokyny

### Tón
- **Formální** vy-form pro SK i DE (B2B aplikace)
- Žádné slangové výrazy
- Konzistentní s existujícími překlady — zkontroluj `SK_EXTRA` / `DE_EXTRA` pro stejné kořenové slovo

### SK glossář (důležité)
| CS | SK |
|---|---|
| Uložit | Uložiť |
| Smazat | Vymazať |
| Otevřít | Otvoriť |
| Zavřít | Zavrieť |
| Hledat | Hľadať |
| Zákazník / Odběratel | Zákazník / Odberateľ |
| Objednávka | Objednávka |
| Faktura | Faktúra |
| Výrobek | Výrobok |
| Surovina | Surovina |
| Sklad | Sklad |
| Tisk / Tisknout | Tlač / Tlačiť |
| Stáhnout | Stiahnuť |
| Náhled | Náhľad |
| Nastavení | Nastavenia |
| Cena bez DPH | Cena bez DPH |
| Cena s DPH | Cena s DPH |

### DE glossář (důležité)
| CS | DE |
|---|---|
| Uložit | Speichern |
| Smazat | Löschen |
| Otevřít | Öffnen |
| Zavřít | Schließen |
| Hledat | Suchen |
| Zákazník / Odběratel | Kunde / Abnehmer |
| Objednávka | Bestellung |
| Faktura | Rechnung |
| Výrobek | Produkt / Artikel |
| Surovina | Rohstoff / Zutat |
| Sklad | Lager |
| Tisk / Tisknout | Druck / Drucken |
| Stáhnout | Herunterladen |
| Náhled | Vorschau |
| Nastavení | Einstellungen |
| Cena bez DPH | Preis ohne MwSt. |
| Cena s DPH | Preis inkl. MwSt. |

### Zachovat
- **Emoji** ze začátku/konce: `🎯 Uložit` → `🎯 Uložiť` / `🎯 Speichern`
- **`…` (ellipsis)** za "Načítám…" → `Načítavam…` / `Lade…`
- **`!` `?`** interpunkci
- **Číselné formáty**: `12 345,67 Kč` → SK: `12 345,67 €` ne; nech `Kč` (multi-currency je vlastní layer)
- **Anglické technické termíny**: `CSV`, `PDF`, `JSON`, `API`, `IČO`, `DIČ`, `HACCP`, `EAN` — nepřekládat
- **Klávesové zkratky**: `Cmd+S`, `Ctrl+K` — nepřekládat

### Speciální případy

**Plurály** — CS má 3 formy (1, 2-4, 5+), SK má stejné, DE má jen 2 (1, 2+). Nastav rozumný překlad:
- `1 objednávka / 2 objednávky / 5 objednávek` → SK: `1 objednávka / 2 objednávky / 5 objednávok` → DE: `1 Bestellung / 2 Bestellungen`

**Placeholders** `{0}`, `{name}`, `${var}` — zachovat přesně.

**HTML značky** `<strong>`, `<br>`, `&nbsp;` — zachovat přesně.

---

## ⚠️ Pravidla koordinace s hlavním vláknem

### Co MŮŽEŠ
✅ Editovat `scripts/i18n_dicts_extra.py`
✅ Editovat `admin/i18n_extra.js` (jen přes regeneraci skriptem)
✅ Spouštět `python3 scripts/gen_i18n_extra.py`
✅ Spouštět read-only validace (`node -e ...`, grep, awk, ...)
✅ Vytvářet lokální Git commity
✅ Vytvářet ve `scripts/` pomocné Python utility (např. `scripts/i18n_audit.py`)

### Co NESMÍŠ
❌ Editovat `admin/admin.js`, `admin/admin.css`, `admin/index.html`
❌ Editovat `admin/i18n.js`, `admin/i18n_auto.js` (source of truth)
❌ Editovat cokoli v `pos/`, `floorplan/`, `api/`, `vendor/`, `b2b/`, `sales/`
❌ Spouštět `bash build-zip.sh` (build dělá hlavní vlákno)
❌ Pushovat na `origin` (jen lokální commits)
❌ Měnit `api/config.php` `APP_VERSION` konstantu
❌ Modifikovat database (nepřipojuj se k DB)

### Když narazíš na něco mimo svůj scope
- Napiš poznámku do `TRANSLATIONS_LOG.md` (vytvoř si vlastní log)
- NE-FIXUJ to. Hlavní vlákno se tomu věnuje.

---

## 🚀 Rychlý start

```bash
# 1. Přepni do projektu
cd /Users/chossekilaimac/projects/appek.cz

# 2. Najdi chybějící překlady (audit)
python3 -c "
import re, sys
sys.path.insert(0, 'scripts')
from i18n_dicts_extra import SK_EXTRA, DE_EXTRA
with open('admin/i18n_auto.js') as f: src = f.read()
phrases = re.findall(r\"\['([^']+)',\s*'([^']*)',\s*'([^']*)'\]\", src)
all_cs = sorted(set(p[0] for p in phrases))
missing_sk = [p for p in all_cs if p not in SK_EXTRA]
missing_de = [p for p in all_cs if p not in DE_EXTRA]
print(f'📊 Stats: {len(all_cs)} CS | SK miss {len(missing_sk)} ({100-100*len(missing_sk)//len(all_cs)}%) | DE miss {len(missing_de)} ({100-100*len(missing_de)//len(all_cs)}%)')

# Save audit log
import json
with open('scripts/missing_translations.json', 'w', encoding='utf-8') as f:
    json.dump({'missing_sk': missing_sk[:500], 'missing_de': missing_de[:500]}, f, ensure_ascii=False, indent=2)
print('📄 Saved missing_translations.json (first 500 each)')
"

# 3. Otevři scripts/missing_translations.json + scripts/i18n_dicts_extra.py
# 4. Doplň překlady do SK_EXTRA / DE_EXTRA
# 5. Regeneruj:
python3 scripts/gen_i18n_extra.py

# 6. Verify (žádné JS chyby v bundlu):
node -e "
const c = require('fs').readFileSync('admin/i18n_extra.js', 'utf8');
const w = {}; new Function('window', c)(w);
console.log('OK:', Object.keys(w.I18N_EXTRA).length, 'keys');
"

# 7. Commit
git add scripts/i18n_dicts_extra.py admin/i18n_extra.js
git commit -m "i18n: batch X — +N SK + M DE (téma)"
```

---

## 📊 Reporting

Po každém batchi vytvoř (nebo aktualizuj) `TRANSLATIONS_LOG.md`:

```markdown
## Batch 13 — 2026-05-19 16:30

- Téma: Restaurace POS / Floor Plan terminologie
- Přidáno SK: 187
- Přidáno DE: 187
- Aktuální pokrytí: SK 62.4%, DE 61.9%
- Commit: `abc123def` "i18n: batch 13 — +187 SK + 187 DE (POS/FP)"
- Poznámky:
  - "Doba přípravy" → SK "Čas prípravy", DE "Zubereitungszeit"
  - "Rozvozový kurier" má varianty — sjednoceno na "Kuriér" / "Lieferfahrer"
```

---

## 🔁 Synchronizace s hlavním vláknem

**Konvence:**
- Hlavní vlákno **nikdy needituje** `scripts/i18n_dicts_extra.py` ani `admin/i18n_extra.js`
- Jeho práce na UI (admin.js, admin.css, pos/, floorplan/, ...) je nezávislá
- Po dokončení překladů hlavní vlákno udělá `git merge` nebo prostě build

**Pokud uvidíš v git logu nový commit na souborech mimo svůj scope** — to je hlavní vlákno, ignoruj, pokračuj v překladech.

---

## ❓ FAQ

**Q: Co když potřebuju upravit `admin/admin.css`?**
A: Nesmíš. Napiš to do `TRANSLATIONS_LOG.md` jako poznámku a pokračuj v překladech.

**Q: Co když najdu bug v generátoru `gen_i18n_extra.py`?**
A: Bug = ano, oprav. Skript je tvůj nástroj.

**Q: Můžu přidávat nové klíče které nejsou v `i18n_auto.js`?**
A: Ne. Jen překládej existující CS fráze.

**Q: Můžu spustit demo/admin a testovat?**
A: Ano, ale jen lokálně (XAMPP). Žádný deploy ani `bash build-zip.sh`.

**Q: Co když narazím na CS frázi která dává smysl jen v ČR kontextu (např. "ISDOC", "DIČ", "Pohoda")?**
A: Nech anglický/německý technický termín, případně přidej krátký český kontext do závorky.

**Q: Co když dokončím překlady?**
A: Vytvoř finální commit, aktualizuj `TRANSLATIONS_LOG.md` s "✅ Hotovo · pokrytí SK XX%, DE XX%", a počkej na user signál.

---

## 📌 Status (aktualizuj při každém batchi)

| Metric | Hodnota | Cíl |
|---|---|---|
| Verze APPEK | v2.9.31 | — |
| CS fráze celkem | ~15800 | — |
| SK překlady | 8732 | ≥ 14500 (≥ 92%) |
| DE překlady | 8639 | ≥ 14500 (≥ 92%) |
| Posledni batch | 12 | — |
| Datum start | 2026-05-19 | — |

---

*Hodně štěstí! 🌍*
