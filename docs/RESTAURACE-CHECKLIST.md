# 🍽️ Balíček RESTAURACE — Checklist funkčnosti

> Živá tabulka. **Stav** = co jsem odzkoušel já (✅ ověřeno / 🟢 endpoint / ⚠️ pozn. / ⬜ neověřeno).
> **🔒** = zamčeno jako ověřené (uživatel autorizoval zámek ověřených řádků — „1+2+3", v3.0.204).
> Verze při poslední revizi: **v3.0.204** · prostředí: demo.appek.cz + lokální Preview/DB.

Legenda stavu: ✅ ověřeno (live/DB) · 🟢 ověřeno endpointem · ⬜ čeká na test · ⚠️ pozn.

---

## A) Prodej — POS terminál (`pos/`)
| # | Funkce | Jak ověřit | Stav | 🔒 |
|---|--------|-----------|------|------|
| A1 | Načtení katalogu (kategorie, ceny) | Otevři POS → vidíš produkty + počty | ✅ 51 produktů | 🔒 |
| A2 | Přidání do košíku + přepočet | Klikni produkt → roste mezisoučet/DPH/celkem | ✅ multi-DPH 12/21 % | 🔒 |
| A3 | Finish → vznikne objednávka | Košík → FINISH → toast „Účet POS-…" | ✅ POS-2026…-004 | 🔒 |
| A4 | Finish → odpis surovin | Po finishi klesne sklad dle receptury | ✅ do gramu | 🔒 |
| A5 | Typ (sebou/vyzvednutí/rozvoz/na místě) | Přepínač funguje, uloží se do objednávky | ✅ | 🔒 |
| A6 | Platba (hotově/karta/jiné) | Volba metody se promítne do objednávky | ✅ | 🔒 |
| A7 | Rozpracované (drafty) | Uložit → vyčistí košík → obnovit z ⋮ menu | ✅ save→restore | 🔒 |
| A8 | Tisk účtenky | 🖨️ otevře tiskové okno | ✅ | 🔒 |

## B) Prodej — Restaurace / stoly (admin)
| # | Funkce | Jak ověřit | Stav | 🔒 |
|---|--------|-----------|------|------|
| B1 | Klik na volný stůl → akce | Otevře akční modal (stav, účet, rezervace, QR) | ✅ | 🔒 |
| B2 | Otevřít účet → stůl „occupied" | Účet vznikne, stůl hned svítí obsazeně | ✅ DB+plán | 🔒 |
| B3 | Přidat položku → vidět na účtu | Z menu klik → přepne na „Položky", položka tam je | ✅ (v3.0.189) | 🔒 |
| B4 | Hlavička/Celkem se přepočítá | Po přidání roste „Celkem" i tlačítko Zaplatit | ✅ (v3.0.186) | 🔒 |
| B5 | Klik na obsazený stůl → rovnou účet | Occupied stůl otevře přímo účet | ✅ | 🔒 |
| B6 | Odpis po dine-in platbě | Zaplať účet → klesne sklad dle položek | ✅ | 🔒 |
| **B7** | **+/− množství u položek účtu** | Stepper v účtu mění množství, total recalc, pod 1 → storno | ✅ **v3.0.204** (4343→3, suma 478,64) | 🔒 |

## C) Platba & změny stavů
| # | Funkce | Jak ověřit | Stav | 🔒 |
|---|--------|-----------|------|------|
| C1 | „Zaplatit a zavřít" + tisk ano/ne | Metoda → „Zaplaceno · Vytisknout? Ano/Ne" | ✅ (v3.0.189) | 🔒 |
| C2 | Po platbě stůl → volný (auto-úklid) | Stůl po zaplacení 🟢 free | ✅ DB=free | 🔒 |
| C3 | Účet zůstane rozpracovaný (nezaplaceno) | Zavři modal bez platby → účet open, stůl occupied | ✅ | 🔒 |
| C4 | Ochrana proti dvojí platbě | 2× zaplatit stejný účet → 2. odmítnuto (409) | ✅ FOR UPDATE lock | 🔒 |
| C5 | Záporná/nulová platba odmítnuta | castka ≤ 0 → 400 | ✅ (v3.0.154) | 🔒 |
| C6 | Stavy položek (objednáno→vaří→hotovo→servírováno) | Tlačítka mění stav, total počítá bez storna | ✅ | 🔒 |
| C7 | Storno položky | ✕ → storno (po vaření) / smazání (před) | ✅ | 🔒 |

## D) Odpisy surovin — „všechno musí sedět"
| # | Funkce | Jak ověřit | Stav | 🔒 |
|---|--------|-----------|------|------|
| D1 | Prodej → odpis (sklad B + log pohybu) | sklad_polozky klesne, sklad_pohyby_v2 zapíše | ✅ do gramu | 🔒 |
| D2 | **Odpis i PO PŘESUNU** | Účet A→přesun B→zaplať → odpis proběhne | ✅ ověřeno live | 🔒 |
| D3 | **Odpis po SLOUČENÍ účtů** | Merge 2 účty → zaplať → odpis pokryje OBA (agregace) | ✅ ověřeno live | 🔒 |
| D4 | **Odpis po ROZDĚLENÍ účtu** | Split → zaplať každou část → odpis per část, BEZ duplicit | ✅ ověřeno live | 🔒 |
| D5 | Žádný dvojitý odpis | Source(merged)/parent(split) se neodepisuje | ✅ ověřeno | 🔒 |
| D6 | Přepočet zásoby (stock_aktualni) | Po odpisu sedí s obrazovkou Suroviny | ✅ (v3.0.187/195) | 🔒 |
| D7 | Low-stock alert na živém sloupci | Upozornění čte stock_aktualni (živě ze sklad_polozky) | ✅ (v3.0.187/195) | 🔒 |
| **D8** | **1-klik naskladnění na cíl (z low-stock)** | „🛒 Doplnit vše" → příjem na stock_cilove + pohyb + recompute | ✅ **v3.0.204** (sur.1→250000, +50001) | 🔒 |

## E) Přesuny / Merge / Split — nekolidovat
| # | Funkce | Jak ověřit | Stav | 🔒 |
|---|--------|-----------|------|------|
| E1 | Přesun účtu na jiný stůl | Move → starý stůl free, nový occupied, položky drží | ✅ ověřeno | 🔒 |
| E2 | Přesun nabídne jen volné stoly | Dialog filtruje stav='free' | ✅ | 🔒 |
| E3 | Sloučení účtů (2+ → 1) | Merge → položky+platby na cíl, zdroje 'merged', stoly free | ✅ ověřeno | 🔒 |
| E4 | Rozdělení účtu (split bill) | Split → pod-účty (parent_ucet_id), parent 'split' | ✅ ověřeno | 🔒 |
| E5 | Rozdělit platbu (více plátců) | Součet plateb = celkem, jinak warn | ✅ | 🔒 |
| E6 | Ochrana integrity move/split/merge | Operace jen na otevřeném účtu (paid → 409) | ✅ **v3.0.196** | 🔒 |

## F) Fakturace & provázanost
| # | Funkce | Jak ověřit | Stav | 🔒 |
|---|--------|-----------|------|------|
| F1 | POS prodej = účtenka (doklad POS-…) | Každý prodej dostane číslo dokladu | ✅ | 🔒 |
| F2 | Platba „na fakturu" jako metoda | quick_order/pay přijme faktura/převod | ✅ (v3.0.160) | 🔒 |
| F3 | POS prodej v reportech (objednavky puvod=pos) | Sedí v admin Objednávky + Přehledy | ✅ | 🔒 |
| F4 | B2B fakturace: objednávka→DL→faktura→PDF | Samostatný řetězec pro odběratele | ✅ DL-0048→FA-0026→PDF | 🔒 |
| F5 | Ruční faktura (bez vazby) | Číselné řady + vystavení | 🟢 endpoint (POST admin_faktury) | ⬜ |

## G) Historie & přehledy
| # | Funkce | Jak ověřit | Stav | 🔒 |
|---|--------|-----------|------|------|
| G1 | Účtenky (dnešní prodeje + souhrn) | POS → 📜 Účtenky: seznam + počet/tržby | ✅ | 🔒 |
| G2 | Detail účtenky + tisk | Klik na účtenku → položky + tisk | ✅ | 🔒 |
| G3 | Statistiky dne + TOP položky | POS → 📊 Statistiky | ✅ | 🔒 |
| G4 | Historie sedí s objednávkami | quick_history = objednavky puvod=pos | ✅ | 🔒 |

## H) Stoly / Layout / Rezervace
| # | Funkce | Jak ověřit | Stav | 🔒 |
|---|--------|-----------|------|------|
| H1 | Floor plan (drag-drop, zóny) | Editor: přesun stolů, přidání, zóny | ✅ | 🔒 |
| H2 | Šablony — zachovat zóny | Aplikuj šablonu „zachovat zóny" → přepíše jen stoly | ✅ ověřeno | 🔒 |
| **H2b** | **Floor plan „Aplikovat" je NEDESTRUKTIVNÍ** | Apply → rezervace přežijí, ID stabilní, nový stůl insert, odebraný aktivni=0 | ✅ **v3.0.202** (rezervace 103=103, 0 ztrát) | 🔒 |
| H3 | KDS (kuchyně) | Položky se vystřelí, stavy v KDS | ✅ 4 stanice / 100 queue | 🔒 |
| H4 | QR self-order fronta | Host přes QR → fronta ke schválení; /qr/ host page | ✅ (v3.0.197 /qr/ 200) | 🔒 |
| H5 | Rezervace stolu | Nová rezervace → vidět na stole/timeline | ✅ ověřeno (timeline bloky) | 🔒 |
| **H6** | **Otevírací doba po dnech + adaptivní kalendář** | Editor 7 dnů (zavřeno), timeline se přizpůsobí dni | ✅ **v3.0.201** (Út 17–22, Po zavřeno) | 🔒 |

## I) Responzivita / zařízení
| # | Funkce | Jak ověřit | Stav | 🔒 |
|---|--------|-----------|------|------|
| I1 | POS bez přetečení 360–1440 | Smoke test breakpointů | ✅ 0 přetečení | 🔒 |
| I2 | Košík se vejde na mobil | FINISH/CELKEM/typy viditelné | ✅ (v3.0.191) | 🔒 |
| I3 | Zoom kasy +/- | Hlavička − % +, persist | ✅ (v3.0.191) | 🔒 |
| I4 | Taby Účtenky/Statistiky na mobilu | Dostupné (ikony) na telefonu/tabletu | ✅ (v3.0.192) | 🔒 |
| I5 | Admin obrazovky bez přetečení | 7 obrazovek × 4 šířky | ✅ 0 přetečení | 🔒 |
| **I6** | **Rezervační timeline responzivní** | Mobil 390px: 0 přetečení, horiz. scroll, užší sloupce | ✅ **v3.0.201** | 🔒 |
| **I7** | **POS „KASA" dlaždice hranaté/větší** | Produkty hranatá tlačítka (6px), čitelné | ✅ **v3.0.202** | 🔒 |

## J) Tiskárny & kuchyně (síťový tisk bonů)
| # | Funkce | Jak ověřit | Stav | 🔒 |
|---|--------|-----------|------|------|
| J1 | Párování stanice → tiskárna | Nastavení → Tiskárny: stanice má dropdown tiskárny | ✅ **v3.0.200** (Pec→doma, DB) | 🔒 |
| J2 | Routing bonů podle stanice (takeaway) | Objednávka → bon na tiskárnu stanice; bez stanice = přeskočeno | ✅ **v3.0.200** (obj.36, dummy) | 🔒 |
| J3 | Dine-in bony na stanicové tiskárny | „Do kuchyně" → bon na tiskárnu stanice + kuchyne_tisk=1 | ✅ **v3.0.203** (účet 1221, dummy) | 🔒 |
| J4 | Soft-fail tisku | Chyba/žádná tiskárna → POS neblokuje (browser bon pokryje) | ✅ ověřeno | 🔒 |

---

### Stav uzavření
- **Zamčeno (🔒): 60 / 61 řádků.** Balíček Restaurace prakticky uzavřen.
- **Jediný otevřený:** F5 (ruční faktura bez vazby) — endpoint potvrzen, čeká na živý test vystavení (vytvoří reálnou fakturu → nedělám bez tvého pokynu).
- Verze: **v3.0.204** na demu. Nově ověřeno tuto session: B7, D8, E6, H2b, H6, I6, I7, J1–J4.

> Řekni „F5 OK" (po živém testu) → zamknu poslední řádek → **balíček Restaurace 100 % zamčen.**
