# 🌍 APPEK i18n — Translations Log

Per-batch log spravovaný překladovým vláknem. Hlavní vlákno NEEDITUJE.

## 📊 Souhrn

| Metric | Start | Aktuální | Cíl |
|---|---|---|---|
| CS frází celkem | 15800 | 15844 | — |
| SK překlady (`SK_EXTRA`) | 8561 | 10121 | ≥ 14500 (≥ 92%) |
| DE překlady (`DE_EXTRA`) | 8513 | 10073 | ≥ 14500 (≥ 92%) |
| SK pokrytí (po rule fallbacks) | 48.3% | 63.8% | ≥ 92% |
| DE pokrytí | 48.1% | 63.2% | ≥ 92% |
| Poslední batch | — | 4 | — |
| Datum start | 2026-05-19 | — | — |

---

## Batch 1 — 2026-05-19

- **Téma:** UI buttons (+ Nová/Nový/Přidat/Vytvořit) · country codes · advent/Vánoce/Velikonoce/Silvestr · `XX% dokončeno` / `X za cenu Y` / `2FA / 3D Secure` číselné fronts · A-words (admin/UI/business) · B-words (B2B/BCC/Backup/Balíčky/Bank)
- **Přidáno SK:** 169 (8561 → 8730)
- **Přidáno DE:** 169 (8513 → 8682)
- **Pokrytí po batchi:** SK 56.0%, DE 55.3%
- **Verify:** ✓ `gen_i18n_extra.py` proběhlo, ✓ JS bundle brace-balanced (736 042 bytes), ✓ 10/10 spot-check entries presence.
- **Commit:** `0cceb66` `i18n: batch 1 — +169 SK + +169 DE (buttons + country codes + holidays + A/B common)`
- **Poznámky:**
  - `+ Nová slevová úroveň` → SK `+ Nová zľavová úroveň`, DE `+ Neue Rabattstufe`
  - `BOZP` → SK ponecháno `BOZP` (regionální termín existuje stejně), DE přeloženo na `Arbeitsschutz`
  - `Balicí list` → SK `Baliaci list`, DE `Packliste` (nezaměňovat s `Lieferschein` = dodací list)
  - `13. plat` → DE `13. Monatsgehalt` (kulturně odpovídající)
  - `Veselé Velikonoce!` → SK `Veselé veľkonočné sviatky!` (přirozenější fráze než `Veselá Veľká noc`)
  - `Šťastný nový rok!` → DE `Frohes neues Jahr!` (klasická novoroční pohřeb. fráze; rovněž akceptováno `Guten Rutsch`)
  - Country code `+1 (USA / Kanada)` ponecháno beze změny — názvy zemí jsou v CS/SK/DE identické pro USA/Kanadu.

### Skipped from batch 1 (need context)
- `'A'` (single letter) — ponecháno na pozdější rozhodnutí, může být sloupcový label nebo spojka
- `'$'` (single char) — pravděpodobně template placeholder, zatím nepřekládat

---

## Batch 2 — 2026-05-19

- **Téma:** C-cluster (`Cena*`, `Cenovka*`, `Cenov*`, `Ctrl+*`, `Customer*`, `Compliance*`, `Cookies*`) + všechny Č-words (časomíra, černobílé, čínský jüan, číselník*, čtvrtletní report, …)
- **Přidáno SK:** 349 (8730 → 9079)
- **Přidáno DE:** 349 (8682 → 9031)
- **Pokrytí po batchi:** SK 58.0%, DE 57.3%
- **Verify:** ✓ gen_i18n_extra.py běh OK, ✓ brace balance OK, ✓ 9/9 spot-check entries v bundlu (SK + DE).
- **Commit:** `ca6452b` `i18n: batch 2 — +349 SK + +349 DE (C/Č cluster — Cena, Cenovka, Ctrl+*, Customer*, Číselník*)`
- **Poznámky:**
  - `Časová zóna` → SK `Časové pásmo` (slovenština používá pásmo místo zóna pro tento význam)
  - `Číselník` → DE `Codeliste` (oficiální překlad pro číselník v IT/admin kontextu); některé varianty (`Číselník MJ` → `Codeliste ME`, `Číselník PSČ` → `Codeliste PLZ`) — abbreviace lokalizovaná
  - `Černý pepř` → SK `Čierne korenie` (slovenština používá "korenie" místo "pepř")
  - `Cooking` → ponecháno mimo batch, plánováno pro food/restaurace klastr
  - `Ctrl+Z — Vrátit` → DE `Ctrl+Z — Rückgängig` (klasický DE výraz pro undo)
  - `Cenová stráž` → DE `Preiswächter` (kreativní překlad, popisuje funkci monitoringu cen)
  - `CV` → SK `CV`, DE `Lebenslauf` (DE má etablovaný překlad)
  - `Často kupované společně` a `Často kupované spolu` mají v CS oba variantní zápis — DE/SK překlad sjednocen na `Häufig zusammen gekauft` / `Často kupované spolu`

---

## Batch 3 — 2026-05-19

- **Téma:** Kompletní D-cluster (447 entries) — Datum*, Doba*, Dokument*, Dodací list*, Doklad*, Doručit/Doručení*, Doporuč*, Doprava*, Děkujeme*, DPH/DUZP, Den*, Denní*, Drobné*, Duplikovat*, Dvoufaktorové ověření
- **Přidáno SK:** 447 (9079 → 9526)
- **Přidáno DE:** 447 (9031 → 9478)
- **Pokrytí po batchi:** SK 60.5%, DE 59.8%
- **Verify:** ✓ gen_i18n_extra.py běh OK, ✓ brace balance OK, ✓ 9/9 spot-checks.
- **Commit:** *(následuje)*
- **Poznámky:**
  - `Doba přípravy` → SK `Čas prípravy`, DE `Zubereitungszeit`
  - `Dušičky` → SK `Pamiatka zosnulých`, DE `Allerseelen`
  - `Den díkůvzdání` → DE `Erntedankfest` (DE ekvivalent thanksgivingu)
  - `DPH` → DE `MwSt.` (konzistentní s glosářem v TRANSLATIONS_WORK.md)
  - `Doprava Česká pošta` → SK `Doprava Slovenská pošta` (lokalizace názvu národní pošty)
  - `Dárek zdarma` → SK `Darček zadarmo` (SK preferuje "darček", "zadarmo")
  - `Doručit po 16:00` → DE `Nach 16:00 zustellen` (CET formát zachován)
  - `Dáme jídlo` (název delivery služby) ponecháno beze změny v obou — registered brand
  - `Dlouhá definice catering kalkulace` (1 entry) přeložena celá v obou jazycích — preserves emphasis a strukturu

---

## Batch 4 — 2026-05-19

- **Téma:** E + F + G + H letters celé (~595 entries) — E-mail/Email cluster, Export cluster (15), Externí, EBIT/EBITDA/ERP, Eskalace, Espresso, Filter cluster (35), Faktura cluster (7), Finanční/Finální (16), Foto (4), Formulář, FTP, Filter keys F1-F12 (8), Google services (7), GDPR, Gantt, Generovat (4), HACCP, HTTP errors (12), Heslo cluster (24), Hesla, Historie (8), Hlas* (12), Hlavní (20), Hledat (8), Hodnocení (10), Hodnota (10), Home office, Hostess, Hot* (6), Hotovo* (5), Hotovost (3), Hořčice, Hreflang, Hromadný (6), Hvězdička (3), Hybrid (8)
- **Přidáno SK:** 595 (9526 → 10121)
- **Přidáno DE:** 595 (9478 → 10073)
- **Pokrytí po batchi:** SK 63.8%, DE 63.2%
- **Verify:** ✓ gen běh OK, ✓ brace balance, ✓ 9/9 spot-checks.
- **Commit:** *(následuje)*
- **Poznámky:**
  - `Egypt` → DE `Ägypten`, `Estonsko` → SK `Estónsko`, `Finsko` → SK `Fínsko`, `Francie` → SK `Francúzsko` (země správně v každém jazyce)
  - `HDP` → DE `BIP` (BruttoInlandsProdukt)
  - `Eidam` → DE `Edamer` (sýr s vlastním DE pravopisem)
  - `Hořčice` → SK `Horčica`, `Hořčík` → SK `Horčík`, DE `Senf` vs `Magnesium`
  - `Generální ředitel` → DE `Geschäftsführer`, `Finanční ředitel` → `Finanzdirektor`
  - `Fronta` → DE `Warteschlange` (kontext: queue, ne fronta jako linie)
  - `Hospoda` → DE `Kneipe` (zachován neformální tón)
  - `Hákový metla` (oprava CS překlepu) → SK `Hákový hák`, DE `Knethaken`
  - HTTP status kódy ponechány v originálním anglickém znění (standard)

---

## 🔭 Plán na další batche

- **Batch 5:** I (145) + J (63) + K (314) + L (164) — IBAN, Import, Inflation, Kategorie, Kompozice, KPI, Klient, Likvidita, Logistika
- **Batch 4:** F-words (172) + G-words (52) — `Faktur*` cluster
- **Batch 5+:** N-words (467), O-words (351 — `Objednávk*` cluster), P-words (1045!), S-words (984), T (372), V (710), Z (407)

Velké bloky (P, S, V) budou rozdělené na 2–3 batche po ~200.
