# 📈 VÝKONNOSTNÍ LOG — zátěžové testy (demo.appek.cz)

> Záznam běhů pro porovnávání výkonu. Nový běh = nový řádek v tabulce „Běhy".
> Metoda: paralelní reálné HTTP (curl + xargs -P), endpoint `admin_pos.php?action=quick_order`.
> Klient: lokální Mac. Cíl: demo.appek.cz (sdílený hosting Hostinger).

## Běhy

| # | Datum | Verze | Souběžnost | Pokusů | OK (200/201) | Odmítnuto (000) | Čas | Throughput (ok/s) | DB stav (po) | Pozn. |
|---|-------|-------|-----------|--------|--------------|------------------|-----|-------------------|--------------|-------|
| 1 | 2026-06-08 | 3.0.193 | POS -P50 | ~6490 | **3038** | 3452 (53 %) | — | ~18 (burst) | 3 243 obj · 892 679 Kč | server saturace @ -P50; B2B padalo (plati_od) |
| 2 | 2026-06-08 | 3.0.194 | POS -P25 | 10 000 | **4156** | 5844 (58 %) | 268 s | **15,5** | 7 401 obj · 2 047 127 Kč | DB nesmazána (kumul.); B2B opraveno |
| – | pilot | 3.0.193 | -P30 (200) | 200 | 200 | 0 | 11 s | **18,1** | – | čistý burst, 0 chyb |

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
