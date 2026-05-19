# 🌍 APPEK i18n — Translations Log

Per-batch log spravovaný překladovým vláknem. Hlavní vlákno NEEDITUJE.

## 📊 Souhrn

| Metric | Start | Aktuální | Cíl |
|---|---|---|---|
| CS frází celkem | 15800 | 15844 | — |
| SK překlady (`SK_EXTRA`) | 8561 | 9079 | ≥ 14500 (≥ 92%) |
| DE překlady (`DE_EXTRA`) | 8513 | 9031 | ≥ 14500 (≥ 92%) |
| SK pokrytí (po rule fallbacks) | 48.3% | 58.0% | ≥ 92% |
| DE pokrytí | 48.1% | 57.3% | ≥ 92% |
| Poslední batch | — | 2 | — |
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
- **Commit:** *(následuje)*
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

## 🔭 Plán na další batche

- **Batch 3:** D-words (447 missing) — vč. `Daň*`, `Dashboard`, `Dodací list*`, `Doklad*`, Dárkové poukazy, Dodavatel, Doprava, Dovolená
- **Batch 4:** F-words (172) + G-words (52) — `Faktur*` cluster
- **Batch 5+:** N-words (467), O-words (351 — `Objednávk*` cluster), P-words (1045!), S-words (984), T (372), V (710), Z (407)

Velké bloky (P, S, V) budou rozdělené na 2–3 batche po ~200.
