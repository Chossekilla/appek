# 📈 VÝKONNOSTNÍ LOG — zátěžové testy (demo.appek.cz)

> Záznam běhů pro porovnávání výkonu. Nový běh = nový řádek v tabulce „Běhy".
> Metoda: paralelní reálné HTTP (curl + xargs -P), endpoint `admin_pos.php?action=quick_order`.
> Klient: lokální Mac. Cíl: demo.appek.cz (sdílený hosting Hostinger).

## Běhy

| # | Datum | Verze | Souběžnost | Pokusů | OK (200/201) | Odmítnuto (000) | Čas | Throughput (ok/s) | DB stav (po) | Pozn. |
|---|-------|-------|-----------|--------|--------------|------------------|-----|-------------------|--------------|-------|
| 1 | 2026-06-08 | 3.0.193 | POS -P50 | ~6490 | **3038** | 3452 (53 %) | — | ~18 (burst) | 3 243 obj · 892 679 Kč | server saturace @ -P50; B2B padalo (plati_od) |
| 2 | 2026-06-08 | 3.0.194 | POS -P25 | 10 000 | **4156** | 5844 (58 %) | 268 s | **15,5** | 7 401 obj · 2 047 127 Kč | DB nesmazána (kumul.); B2B opraveno |
| 3 | 2026-06-08 | 3.0.194 | POS -P12 | 10 000 | **3801** | 6199 (62 %) | 241 s | **15,7** | čistá DB → 3 801 obj · 1 034 506 Kč | po Reset+seed; 6 surovin sraženo na 2 |
| – | pilot | 3.0.193 | -P30 (200) | 200 | 200 | 0 | 11 s | **18,1** | – | čistý burst, 0 chyb |

### Run 3 — dimenze + ověření odpisů
- B2B 150/150 (201) ✓ · dine-in 76 + 4× 409 (ochrana) ✓
- **Odpisy DO MÍNUSU prokázány:** snížené suroviny šly hluboko záporně ve `sklad_polozky`/pohybech (např. Droždí **−47 053**), řetězec stav_pred→stav_po konzistentní, prodej se NEblokuje. ✅
- 🐛 **Bug:** souhrnný sloupec `suroviny.stock_aktualni` (Suroviny přehled) zůstal **NULL** — reálný stav je správně v pohybech, ale přehledový sloupec se po odpisech neaktualizoval (recompute neprošel/nepsal). K opravě.

### Klíčové (3 běhy konzistentně)
- **Server strop ~15,5–15,7 ok/s** bez ohledu na -P (12/25/50) → strop je **počet souběžných PHP workerů hostingu (velmi nízký, ~3–6)**, NE databáze. Nad strop = odmítání (HTTP 000). 0 odmítnutí by bylo cca **-P5**.
- DB i při tom přijala statisíce zápisů + odpisů bez chyby; integrita (žádné duplicity, 409 ochrana, odpisy v pohybech) zachována.

### Doprovodné dimenze (run 2)
| Dimenze | Objem | Výsledek |
|---|---|---|
| B2B objednávky | 200 @ -P10 | **200/200 HTTP 201** (24 s) ✓ |
| Dine-in cykly (open→add→pay) | 100 @ -P6 | 94 zaplaceno + **6× 409** (ochrana dvojí platby) ✓ |
| Odpisy surovin | 7400+ obj. | logováno do sklad_pohyby_v2; min. stav surovin **0,0** (na hraně mínusu) |
| Random zákazníci / naskladnění | – | odmítnuto během saturace (-P50 okno) |

## Závěry (k porovnání)
- **Bottleneck = sdílený hosting (PHP workery/spojení), NE databáze.** Server přijme ~**15–18 req/s** a vše nad to odmítne (HTTP 000). DB sama nevrátila chybu — pohltila 7 400+ objednávek + 2 mil. Kč + všechny odpisy čistě.
- Throughput **klesá s růstem tabulky** (run1 ~18/s na ~prázdné DB → run2 15,5/s na 7k řádcích) — quick_order generuje číslo `MAX(SUBSTRING_INDEX(cislo))` + odpis per položka.
- **„10k / 30 min" splnitelné** při serverovém stropu (~15/s × 1800 ≈ 27 000), pokud klient nepřetěžuje (≤ ~12–15 souběžných → 0 odmítnutí).
- Integrita: žádné duplicity, dvojí platba blokovaná (409), POS účtenky v core, odpisy sedí.

## Pro příští čistý běh (doporučeno)
1. Reset DB (Nastavení → Údržba → 🗑️ Reset) → čistý baseline.
2. Pár surovin srazit na nízký stav → uvidíme **odpisy do mínusu**.
3. Souběžnost **-P12** → 0 odmítnutí, projde všech 10k (~11 min), čistá čísla pro srovnání.

<!-- DALŠÍ BĚH: přidej řádek do tabulky „Běhy" + dimenze. -->
