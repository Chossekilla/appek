# 🌍 APPEK i18n — Translations Log

Per-batch log spravovaný překladovým vláknem. Hlavní vlákno NEEDITUJE.

## 📊 Souhrn

| Metric | Start | Aktuální | Cíl |
|---|---|---|---|
| CS frází celkem | 15800 | 15844 | — |
| SK překlady (`SK_EXTRA`) | 8561 | 8730 | ≥ 14500 (≥ 92%) |
| DE překlady (`DE_EXTRA`) | 8513 | 8682 | ≥ 14500 (≥ 92%) |
| SK pokrytí (po rule fallbacks) | 48.3% | 56.0% | ≥ 92% |
| DE pokrytí | 48.1% | 55.3% | ≥ 92% |
| Poslední batch | — | 1 | — |
| Datum start | 2026-05-19 | — | — |

---

## Batch 1 — 2026-05-19

- **Téma:** UI buttons (+ Nová/Nový/Přidat/Vytvořit) · country codes · advent/Vánoce/Velikonoce/Silvestr · `XX% dokončeno` / `X za cenu Y` / `2FA / 3D Secure` číselné fronts · A-words (admin/UI/business) · B-words (B2B/BCC/Backup/Balíčky/Bank)
- **Přidáno SK:** 169 (8561 → 8730)
- **Přidáno DE:** 169 (8513 → 8682)
- **Pokrytí po batchi:** SK 56.0%, DE 55.3%
- **Verify:** ✓ `gen_i18n_extra.py` proběhlo, ✓ JS bundle brace-balanced (736 042 bytes), ✓ 10/10 spot-check entries presence.
- **Commit:** *(následuje)*
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

## 🔭 Plán na další batche

- **Batch 2:** C-words (305 missing) — z toho hodně common admin (`Cena`, `Cenová ...`, `Cookie`, `Cyber`, `Č-words`)
- **Batch 3:** D-words (447 missing) — vč. `Daň*`, `Dashboard`, `Dodací list*`, `Doklad*`
- **Batch 4:** F-words (172) + G-words (52) — `Faktur*` cluster
- **Batch 5+:** N-words (467), O-words (351 — `Objednávk*` cluster), P-words (1045!), S-words (984), T (372), V (710), Z (407)

Velké bloky (P, S, V) budou rozdělené na 2–3 batche po ~200.
