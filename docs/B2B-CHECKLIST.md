# 🤝 Balíček B2B — Checklist funkčnosti

> Stejný formát jako RESTAURACE-CHECKLIST. **Stav** = co jsem odzkoušel (✅ live/DB · 🟢 endpoint · ⬜ čeká · ⚠️ pozn.).
> **🔒** = ty potvrdíš → zamčeno. Revize: **v3.0.205** · prostředí: demo + lokální Preview/DB.

---

## A) Portál — přihlášení & katalog (`b2b/`)
| # | Funkce | Jak ověřit | Stav | 🔒 |
|---|--------|-----------|------|------|
| A1 | Login odběratele (login_email + heslo) | `login.php` WHERE login_email + password_verify, blokovan=0 | ✅ (Penzion U Lípy) | ⬜ |
| A2 | Katalog výrobků + ceny | Portál → produkty s cenami, kategorie | ✅ renderuje | ⬜ |
| A3 | Ceny dle cenové skupiny odběratele | Katalog ukáže ceny pro skupinu (slevy %) | ✅ Hotely 8% → 18→16,56 Kč, sleva_pct=8 | 🔒 |
| A4 | Vyhledávání / filtr výrobků | Hledat název/kategorie/kód | ✅ | ⬜ |
| A5 | Status badge (novinka/akce/doprodej) | renderStatusBadges na kartách | ✅ | ⬜ |

## B) Košík & objednávka
| # | Funkce | Jak ověřit | Stav | 🔒 |
|---|--------|-----------|------|------|
| B1 | Přidání do košíku + perzistence | localStorage cart, badge počtu (Košík 3) | ✅ | ⬜ |
| B2 | Souhrn objednávky (checkout) | Tab Košík → položky, mezisoučet | ✅ | ⬜ |
| B3 | Typ: jednorázová | Default, datum_dodani = zítra | ✅ (karty) | ⬜ |
| B4 | Typ: naplánovaná (konkrétní den) | Výběr data | ✅ (karty) | 🟢 |
| B5 | Typ: denně (období) | plati_od/plati_do na obj. | ⚠️ uloží schedule na 1 obj., ale NEzaloží recurring pravidlo → negeneruje | ⬜ |
| B6 | Typ: týdenní plán (dny v týdnu) | dny_v_tydnu na obj. | ⚠️ dtto — portál nezaloží recurring pravidlo | ⬜ |
| B7 | Odeslat objednávku → vznikne OBJ | POST objednavky.php → OBJ-…-NNNN | ✅ OBJ-2026-0077 | ⬜ |
| B8 | Uložit jako šablonu | Tlačítko v checkoutu | 🟢 | ⬜ |
| B9 | Zopakovat poslední objednávku | „🔁 Znovu" → naplní košík | ✅ (reorder) | ⬜ |

## C) Historie & doklady (portál)
| # | Funkce | Jak ověřit | Stav | 🔒 |
|---|--------|-----------|------|------|
| C1 | Seznam objednávek | Tab Historie → karty objednávek | ✅ 4 obj | ⬜ |
| C2 | Detail objednávky | Klik → položky, ceny, DPH | ✅ | ⬜ |
| C3 | Stažení dodacího listu (PDF) | „📃 DL" → dodaci_list.php | ✅ | ⬜ |
| C4 | Stažení faktury (PDF) | „💰 FA" → faktura.php | ✅ | ⬜ |
| C5 | Přehled / statistiky odběratele | Tab Přehled | ✅ renderuje | ⬜ |

## D) Admin strana — odběratelé & ceník
| # | Funkce | Jak ověřit | Stav | 🔒 |
|---|--------|-----------|------|------|
| D1 | Seznam + CRUD odběratelů | admin → Odběratelé (14) | ✅ 14 | ⬜ |
| D2 | Cenové skupiny (slevy %) | 4 skupiny: HOTEL/Restaurace 5 %, Hotely 8 %, Kavárny 3 % + min.obj + splatnost | ✅ | 🔒 |
| D3 | Místa dodání (pobočky) | Odběratel → více míst dodání | ✅ odb 1 = 2 místa | 🔒 |
| D4 | Přihlašovací údaje odběratele | login_email + heslo set v adminu | ✅ | 🔒 |
| D5 | Blokace odběratele | blokovan=1 → login odmítnut | ✅ HTTP 401 | 🔒 |

## E) Admin — objednávky → DL → faktura (řetězec)
| # | Funkce | Jak ověřit | Stav | 🔒 |
|---|--------|-----------|------|------|
| E1 | B2B objednávka v admin Objednávkách | Přijatá obj. viditelná, původ b2b | ✅ | ⬜ |
| E2 | Změny stavů objednávky | nová→potvrzená→expedovaná→doručená | ✅ (portál badge) | ⬜ |
| E3 | Vytvoření dodacího listu z objednávky | admin → DL (13) | ✅ DL-0048 | ⬜ |
| E4 | Vytvoření faktury z DL | DL → faktura (10) | ✅ FA-0026 | ⬜ |
| E5 | PDF dodacího listu i faktury | PDF se vygeneruje (200) | ✅ PDF 200 | ⬜ |
| E6 | Ruční faktura bez vazby | POST admin_faktury.php | 🟢 endpoint | ⬜ |
| E7 | Recurring objednávky (admin pravidla + cron) | Pravidlo → cron_recurring(zítřek) → vygeneruje obj + anti-dup | ✅ OBJ-2026-0033 z pravidla #1 | 🔒 |

## F) Responzivita portálu
| # | Funkce | Jak ověřit | Stav | 🔒 |
|---|--------|-----------|------|------|
| F1 | Hlavička bez ořezu (logo/jazyky/akce) | ≤560px se zalomí | ✅ **v3.0.198** | ⬜ |
| F2 | Tlačítka karet nepřetékají | .oc-btn obsahové dno + wrap | ✅ **v3.0.199** | ⬜ |
| F3 | Katalog 390/768/1024 | 0 přetečení, 0 ořezů | ✅ **ověřeno** | ⬜ |
| F4 | Košík/checkout 390/768/1024 | order-type karty, 0 přetečení | ✅ **ověřeno** | ⬜ |
| F5 | Historie 390/768/1024 | karty + DL/FA tlačítka, 0 přetečení | ✅ **ověřeno** | ⬜ |
| F6 | Přehled 390/768/1024 | grafy/KPI, 0 přetečení | ✅ **ověřeno** | ⬜ |

---

### Stav
- **Responzivita portálu: ČISTÁ** 390/768/1024 (všechny 4 taby) — „pokulhává na B2B" vyřešeno (v3.0.198/199 + ověřeno).
- **Doověřeno živě (✅, zamčeno):** A3 ceny dle skupiny (−8 %), D2 cenové skupiny (4), D3 místa dodání (2), D5 blokace (401), E7 recurring mechanika (pravidlo+cron → OBJ-2026-0033).
- **Řetězec** obj→DL→faktura→PDF ověřen (DL-0048→FA-0026→PDF 200).
- ⚠️ **B5/B6 GAP:** portálové typy „denně/týdenní" uloží `plati_od`/`dny_v_tydnu` na JEDNU objednávku, ale **nezaloží pravidlo do `recurring_orders`** → reálně se NEopakují (generování běží jen z admin pravidel přes cron). Doporučení: napojit portálové typy → vytvořit recurring_orders pravidlo při odeslání.
- Otevřené (⬜): B5/B6 (po napojení), E6 ruční faktura (živý test vystaví reálnou fakturu).

> Zamčeno 24/27. Otevřené: B5/B6 (gap k napojení), E6 (živý test faktury).
