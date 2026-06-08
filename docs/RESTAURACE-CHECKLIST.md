# 🍽️ Balíček RESTAURACE — Checklist funkčnosti

> Živá tabulka. **Stav** = co jsem odzkoušel já (✅ ověřeno / ⚠️ pozn. / ⬜ neověřeno).
> **🔒 OK** = ty potvrdíš „přesně OK" → řádek je **zamčený** (změníme ⬜ → 🔒).
> Verze při poslední revizi: **v3.0.192** · prostředí: demo.appek.cz + lokální Preview.

Legenda stavu: ✅ ověřeno (live/DB) · 🟢 ověřeno endpointem · ⬜ čeká na test · ⚠️ pozn.

---

## A) Prodej — POS terminál (`pos/`)
| # | Funkce | Jak ověřit | Stav | 🔒 OK |
|---|--------|-----------|------|------|
| A1 | Načtení katalogu (kategorie, ceny) | Otevři POS → vidíš produkty + počty | ✅ 51 produktů | ⬜ |
| A2 | Přidání do košíku + přepočet | Klikni produkt → roste mezisoučet/DPH/celkem | ✅ multi-DPH 12/21 % | ⬜ |
| A3 | Finish → vznikne objednávka | Košík → FINISH → toast „Účet POS-…" | ✅ POS-2026…-004 | ⬜ |
| A4 | Finish → odpis surovin | Po finishi klesne sklad dle receptury | ✅ do gramu | ⬜ |
| A5 | Typ (sebou/vyzvednutí/rozvoz/na místě) | Přepínač funguje, uloží se do objednávky | ✅ | ⬜ |
| A6 | Platba (hotově/karta/jiné) | Volba metody se promítne do objednávky | ✅ | ⬜ |
| A7 | Rozpracované (drafty) | Uložit → vyčistí košík → obnovit z ⋮ menu | ✅ save→restore | ⬜ |
| A8 | Tisk účtenky | 🖨️ otevře tiskové okno | ✅ | ⬜ |

## B) Prodej — Restaurace / stoly (admin)
| # | Funkce | Jak ověřit | Stav | 🔒 OK |
|---|--------|-----------|------|------|
| B1 | Klik na volný stůl → akce | Otevře akční modal (stav, účet, rezervace, QR) | ✅ | ⬜ |
| B2 | Otevřít účet → stůl „occupied" | Účet vznikne, stůl hned svítí obsazeně | ✅ DB+plán | ⬜ |
| B3 | Přidat položku → vidět na účtu | Z menu klik → přepne na „Položky", položka tam je | ✅ (v3.0.189) | ⬜ |
| B4 | Hlavička/Celkem se přepočítá | Po přidání roste „Celkem" i tlačítko Zaplatit | ✅ (v3.0.186) | ⬜ |
| B5 | Klik na obsazený stůl → rovnou účet | Occupied stůl otevře přímo účet | ✅ | ⬜ |
| B6 | Odpis po dine-in platbě | Zaplať účet → klesne sklad dle položek | ✅ | ⬜ |

## C) Platba & změny stavů
| # | Funkce | Jak ověřit | Stav | 🔒 OK |
|---|--------|-----------|------|------|
| C1 | „Zaplatit a zavřít" + tisk ano/ne | Metoda → „Zaplaceno · Vytisknout? Ano/Ne" | ✅ (v3.0.189) | ⬜ |
| C2 | Po platbě stůl → volný | Stůl po zaplacení 🟢 free | ✅ DB=free | ⬜ |
| C3 | Účet zůstane rozpracovaný (nezaplaceno) | Zavři modal bez platby → účet open, stůl occupied | ✅ | ⬜ |
| C4 | Ochrana proti dvojí platbě | 2× zaplatit stejný účet → 2. odmítnuto (409) | ✅ FOR UPDATE lock | ⬜ |
| C5 | Záporná/nulová platba odmítnuta | castka ≤ 0 → 400 | ✅ (v3.0.154) | ⬜ |
| C6 | Stavy položek (objednáno→vaří→hotovo→servírováno) | Tlačítka mění stav, total počítá bez storna | ✅ | ⬜ |
| C7 | Storno položky | ✕ → storno (po vaření) / smazání (před) | ✅ | ⬜ |

## D) Odpisy surovin — „všechno musí sedět"
| # | Funkce | Jak ověřit | Stav | 🔒 OK |
|---|--------|-----------|------|------|
| D1 | Prodej → odpis (sklad B + log pohybu) | sklad_polozky klesne, sklad_pohyby_v2 zapíše | ✅ do gramu | ⬜ |
| D2 | **Odpis i PO PŘESUNU** | Účet A→přesun B→zaplať → odpis proběhne | ✅ **ověřeno live** | ⬜ |
| D3 | **Odpis po SLOUČENÍ účtů** | Merge 2 účty → zaplať → odpis pokryje OBA (agregace) | ✅ **ověřeno live** | ⬜ |
| D4 | **Odpis po ROZDĚLENÍ účtu** | Split → zaplať každou část → odpis per část, BEZ duplicit | ✅ **ověřeno live** | ⬜ |
| D5 | Žádný dvojitý odpis | Source(merged)/parent(split) se neodepisuje | ✅ ověřeno | ⬜ |
| D6 | Přepočet zásoby (stock_aktualni) | Po odpisu sedí s obrazovkou Suroviny | ✅ (v3.0.187) | ⬜ |
| D7 | Low-stock alert na živém sloupci | Upozornění čte stock_aktualni (ne mrtvý sklad_stav) | ✅ (v3.0.187) | ⬜ |

## E) Přesuny / Merge / Split — nekolidovat
| # | Funkce | Jak ověřit | Stav | 🔒 OK |
|---|--------|-----------|------|------|
| E1 | Přesun účtu na jiný stůl | Move → starý stůl free, nový occupied, položky drží | ✅ ověřeno | ⬜ |
| E2 | Přesun nabídne jen volné stoly | Dialog filtruje stav='free' | ✅ | ⬜ |
| E3 | Sloučení účtů (2+ → 1) | Merge → položky+platby na cíl, zdroje 'merged', stoly free | ✅ ověřeno | ⬜ |
| E4 | Rozdělení účtu (split bill) | Split → pod-účty (parent_ucet_id), parent 'split' | ✅ ověřeno | ⬜ |
| E5 | Rozdělit platbu (více plátců) | Součet plateb = celkem, jinak warn | ✅ | ⬜ |

## F) Fakturace & provázanost
| # | Funkce | Jak ověřit | Stav | 🔒 OK |
|---|--------|-----------|------|------|
| F1 | POS prodej = účtenka (doklad POS-…) | Každý prodej dostane číslo dokladu | ✅ | ⬜ |
| F2 | Platba „na fakturu" jako metoda | quick_order/pay přijme faktura/převod | ✅ (v3.0.160) | ⬜ |
| F3 | POS prodej v reportech (objednavky puvod=pos) | Sedí v admin Objednávky + Přehledy | ✅ | ⬜ |
| F4 | B2B/řádná fakturace: objednávka→DL→faktura | Samostatný řetězec pro odběratele | 🟢 endpoint | ⬜ |
| F5 | Ruční faktura (bez vazby) | Nastavení číselných řad + vystavení | ⬜ | ⬜ |

## G) Historie & přehledy
| # | Funkce | Jak ověřit | Stav | 🔒 OK |
|---|--------|-----------|------|------|
| G1 | Účtenky (dnešní prodeje + souhrn) | POS → 📜 Účtenky: seznam + počet/tržby | ✅ | ⬜ |
| G2 | Detail účtenky + tisk | Klik na účtenku → položky + tisk | ✅ | ⬜ |
| G3 | Statistiky dne + TOP položky | POS → 📊 Statistiky | ✅ | ⬜ |
| G4 | Historie sedí s objednávkami | quick_history = objednavky puvod=pos | ✅ | ⬜ |

## H) Stoly / Layout / Doplňky
| # | Funkce | Jak ověřit | Stav | 🔒 OK |
|---|--------|-----------|------|------|
| H1 | Floor plan (drag-drop, zóny) | Editor: přesun stolů, přidání, zóny | ✅ | ⬜ |
| H2 | Šablony — zachovat zóny | Aplikuj šablonu „zachovat zóny" → přepíše jen stoly | ✅ ověřeno | ⬜ |
| H3 | KDS (kuchyně) | Položky se vystřelí, stavy v KDS | 🟢 endpoint | ⬜ |
| H4 | QR self-order fronta | Host přes QR → fronta ke schválení | 🟢 endpoint | ⬜ |
| H5 | Rezervace stolu | Nová rezervace → vidět na stole/timeline | 🟢 endpoint | ⬜ |

## I) Responzivita / zařízení
| # | Funkce | Jak ověřit | Stav | 🔒 OK |
|---|--------|-----------|------|------|
| I1 | POS bez přetečení 360–1440 | Smoke test breakpointů | ✅ 0 přetečení | ⬜ |
| I2 | Košík se vejde na mobil | FINISH/CELKEM/typy viditelné | ✅ (v3.0.191) | ⬜ |
| I3 | Zoom kasy +/- | Hlavička − % +, persist | ✅ (v3.0.191) | ⬜ |
| I4 | Taby Účtenky/Statistiky na mobilu | Dostupné (ikony) na telefonu/tabletu | ✅ (v3.0.192) | ⬜ |
| I5 | Admin obrazovky bez přetečení | 7 obrazovek × 4 šířky | ✅ 0 přetečení | ⬜ |

---

### Jak to uzavřeme
1. Projdeš řádek (nebo sekci), na demu ověříš.
2. Řekneš „A1–A8 OK" / „sekce D OK" apod.
3. Já změním ⬜ → 🔒 u potvrzených.
4. Až je vše 🔒 → **balíček Restaurace zamčen.**

**Aktuálně:** všech 5 „kritických" odpisových scénářů (D1–D5) odzkoušeno živě na DB. Čeká se na tvé potvrzení.
