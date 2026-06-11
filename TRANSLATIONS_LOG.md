# 🌍 APPEK i18n — Translations Log

Per-batch log spravovaný překladovým vláknem. Hlavní vlákno NEEDITUJE.

## 📊 Souhrn

| Metric | Start | Aktuální | Cíl |
|---|---|---|---|
| Metric | Start | Final (batch 19) | Cíl |
|---|---|---|---|
| CS frází celkem | 15800 | 19055 | — |
| SK překlady | 8561 | **20265** | ≥ 14500 ✅ |
| DE překlady | 8513 | **20254** | ≥ 14500 ✅ |
| EN overlay (mimo i18n_auto.js) | 0 | **1959** | — |
| ES overlay (mimo i18n_auto.js) | 0 | **1951** | — |
| SK pokrytí | 48.3% | **99.99%** | ≥ 92% ✅ |
| DE pokrytí | 48.1% | **99.99%** | ≥ 92% ✅ |
| EN pokrytí | 100% (i18n_auto.js) | **100% + 1959 overlay** | — |
| ES pokrytí | 100% (i18n_auto.js) | **100% + 1951 overlay** | — |
| Bundle size | 775 KB | **2.08 MB** | — |
| Total batches | — | **19** | — |

## ✅ HOTOVO · 2026-05-19

Cíl ≥92% dosažen pro SK i DE.

**Souhrn:**
- Začátek: SK 48.3% (8561 záznamů) / DE 48.1% (8513 záznamů)
- Konec: SK **92.4%** (15347 záznamů) / DE **92.3%** (15305 záznamů)
- Přidáno: **+6786 SK + +6792 DE** v 15 batchich
- Bundle: 1.30 MB (`admin/i18n_extra.js`)

## Batch 14–15 — modal audit (po dokončení)

Po /verify modal okna ověřeno + opraveno:

### Batch 14 — modal title fixes (~10 entries SK+DE)
Statické modal tituly s emoji prefixem (před chyběly v lookupu):
- `✂️ Rozdělit účet na části`, `💰 Rozdělit platbu`, `🔗 Sloučit účty`
- `📊 Import — Auto-match výsledky (3/4)`, `📊 Import — Mapping sloupců (2/4)`, `📊 Import — Hotovo! (4/4)`
- `📋 Šablony layoutu`, `📜 Webhook log`, `🗺️ Správa zón`, `🚚 Přesunout účet`

### Batch 15 — CORE UI from `admin/i18n.js` (~133 entries SK+DE) — kritická oprava
Hardcoded i18n.js dictionary (300+ klíčů) měla CS/EN/ES, ale chyběl SK+DE overlay pro bare slova:
- Akce: `Uložit`, `Zavřít`, `Zrušit`, `Upravit`, `Pohyb`, `Aplikovat`, `Mapování`
- Statusy: `Aktivní`, `Blokován`, `Uhrazeno`, `Uloženo`, `Zrušeno`, `Zrušená`
- Confirm: `Ano`, `Ne, čisté prostředí`, `Ano, nahrát demo`
- Empty states: `Zatím žádné DL/faktury/objednávky/výrobky` (6 variant), `Žádné výsledky`, `🤷 Žádné výsledky`
- Onboarding/setup texty (long descriptions)
- Sekční tituly s emoji: `🏠 Kde to chceš provozovat?`, `🎨 Logo a vzhled`, `🥖 Výroba`, `💾 Zálohy`, `🔑 Licence`, `📋 HACCP nastavení`, atd.
- Pomocné fráze: `Vyber…`, `Vyber odběratele…`, `← Zpět`, `den`, `z`
- Glosář: `Cena bez DPH` → `Preis ohne MwSt.`, `Cena s DPH` → `Preis inkl. MwSt.`, `Přehled` → `Übersicht`, `Chyba` → `Fehler`

### ⚠️ Nepřeložitelné architekturně
Tyto modal tituly mají v JS source-codu řetězení (`+ cislo`, `${var}`) — i18n DOM-walker matchuje jen kompletní textové uzly, takže runtime výsledek se nikdy nenalezne v lookup tabulce:
- `openModal('💰 Platba účtu #' + cislo, ...)` → runtime: `💰 Platba účtu #ABC123` ❌
- `openModal('📅 Kapacita pro ' + datum, ...)` → runtime: `📅 Kapacita pro 2026-05-19` ❌
- ~25 dalších s `${var}` interpolací (Faktura ${f.cislo}, Akce #${e.id}, atd.)

**Fix vyžaduje úpravu zdrojového kódu** (`admin/admin.js`) — refaktor na 2 textové uzly s placeholder substitucí. Mimo můj scope — předávám hlavnímu vláknu jako TODO.

### Modal architektura
i18n_auto.js má MutationObserver attached na `#modal` (subtree: true), takže každý nový text node v modalu se automaticky překládá přes `I18N_LOOKUP`. Bundle z `i18n_extra.js` se merge-uje do `I18N_LOOKUP` před prvním otevřením modalu. Modal body strings jsou pokryté kompletně, jen tituly s template literals nelze.

## Batch 16 — HACCP module (~88 entries SK+DE) — 2026-05-19

Po /přelož haccp ověřena a doplněna pokrytí HACCP modulu (`renderHaccp` v admin.js, ~573 references). Bylo přeloženo:

### Tabs / hlavní UI
`📋 Produktové karty`, `📈 Grafy (šablony postupu)`, `⚙️ Defaultní hodnoty`, `Produktové karty, plán kritických bodů a dokumentace`, `🪄 Auto-vyplnit HACCP karty`, `🪄 Auto-vyplnit vše`, `HACCP karta`, `Hledat (název, číslo)...`, `Všechny kategorie`, `Žádné výrobky`, `— nevyplněno —`, `+ vytvoř šablony`

### HACCP_FIELDS — popisky polí
`Cílový trh` → `Zielmarkt`, `Skladování` → `Lagerung`, `Způsob užití` → `Verwendung`, `Podmínky a způsob distribuce` → `Bedingungen und Art der Distribution`

### HACCP_FIELDS — placeholders
`např. Běžné pečivo pšeničné`, `název na obale`, `ČR`, `k přímé konzumaci`, `do 25 °C, suché místo`, `výrobek určen pro prodej…`, `bez omezení (nevhodné pro diabetiky, coeliky)`

### HACCP_POSTUP_TEMPLATE — názvy kroků výrobního postupu
`Dávkování surovin`, `Smísení / hnětení těsta`, `Zrání / kynutí těsta`, `Dělení na klonky`, `Ukládání do přepravek`
*(ostatní kroky — Příjem surovin, Pečení, Chladnutí, Expedice — byly už v dictu z dřívějších batches)*

### HACCP_KB_TEMPLATE — kritické body (rizika + opatření)
**Rizika (popis):** `Kontaminace mikroorganizmy / škůdci`, `Mykotoxiny, rezidua pesticidů, těžké kovy`, `Pomnožení MO, tvorba toxinů`, `Nedostatečné prohřátí — přežití patogenů`, `Sekundární kontaminace, kondenzace`, `Mechanická kontaminace při manipulaci`

**Opatření:** `Ověření dodavatelů, atesty, vizuální kontrola obalů a DMT, kontrola teploty`, `Atesty dodavatelů, kontrola specifikací`, `Dodržení podmínek skladování, FIFO, kontrola teploty a vlhkosti`, `Hygiena pracoviště a pracovníků, dezinfekce nářadí`, `Kontrola teploty pece a doby pečení dle receptury`, `Chlazení v čistém prostředí, dostatečný odvod par`, `Kontrola teploty skladu, FIFO, čistota přepravek`, `Čisté přepravky, hygiena, kontrola DMT`

### HACCP_VYSLEDKY — výsledky auditu
`✓ V pořádku` → `✓ V poriadku` / `✓ In Ordnung`
`⚠ S připomínkami` → `⚠ S pripomienkami` / `⚠ Mit Anmerkungen`
`✗ Nevyhovuje` → `✗ Nevyhovuje` / `✗ Nicht bestanden`

### haccpRenderGrafy (Šablony výrobního postupu)
`Šablona` → `Vorlage`, `Použito` → `Verwendet`, `📈 Šablony výrobního postupu (HACCP grafy)`, `🔄 Importovat výchozí sadu`, `🔗 Přiřadit` → `Zuweisen`, `✨ Doplnit popisy kroků`, `Název kroku`, long onboarding text

### haccpRenderDefaulty (Defaultní hodnoty)
`⚙️ Defaultní hodnoty pro všechny výrobky`, `➕ Vlastní pole`, `+ Přidat pole`, `💾 Uložit defaulty`, `(libovolně přidaná pole nad rámec standardu)`, `Vyplň jednou — hodnoty se použijí u každého výrobku, který nemá svůj přepis.`, `Žádná vlastní pole. Klepni na „+ Přidat pole" pro vytvoření.`

### haccpRenderDokumenty + audity
`Žádné firemní HACCP dokumenty`, `🔍 Provedené interní audity`, `Vnitřní audit`, `Zatím žádné audity. Klikni „+ Přidat audit" výše.`

### Seed dat / placeholders
`APPEK pekařství...`, `Vaše firma s.r.o.`, `Hana Mašková`, `Jemné pečivo`, `Pšeničné se zdobením`, `Pšeničné základní`, `Speciální (dalamánky)`, `Surovina (např. mouka pšeničná hladká)`

### Tooltipy a hlášky
`Vytiskne všechny vybrané HACCP karty za sebou` → `Druckt alle ausgewählten HACCP-Karten nacheinander`
`Otevře všechny vybrané karty v jednom okně — vhodné pro Uložit jako PDF`
`Stáhne CSV souhrn všech polí HACCP karet (pro účetní / sklad)`
`Nejprve potřebuješ HACCP grafy (šablony). Naimportovat výchozí sadu (5 šablon) automaticky?`
`Všechny popisy jsou už vyplněné`, `✓ Popisy doplněny`

**Glosář (kritické HACCP termíny SK/DE):**
- `Kontaminace mikroorganizmy` → SK `Kontaminácia mikroorganizmami`, DE `Kontamination durch Mikroorganismen`
- `MO` (mikroorganizmy) → SK/DE `MO` (zkratka zachována)
- `DMT` (datum minimální trvanlivosti) → SK `DMT`, DE `MHD` (Mindesthaltbarkeitsdatum)
- `CCP` (Critical Control Point) → CCP (mezinárodní standard, zachován v obou)
- `CP` (Control Point) → CP (zachován)
- `FIFO` → FIFO
- `Kynutí` → SK `Kysnutie`, DE `Gärung`

Hlavní vlákno teď může mergovat / buildovat.
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

## Batch 5 — 2026-05-19

- **Téma:** I + J + K letters (~505 entries) — IBAN/IMAP/ID, Import cluster (10), Incident, Index, Indie/Indien, Infografika, Inflace, Influencer, Integrace cluster (33: GitHub/GitLab/Slack/Stripe/PayPal/etc.), Interaktivní (12), Interní (4), Inventura/Inventář, Italie/Italský, IČO, JCB/JPEG/JSON, Jablečný, Java/JavaScript, Jazyk (4), Jednatel, Jméno (3), Just-in-time, Jídelní lístek, KYC, Kaizen, Kalendář, Kanada, Kanban, Karta cluster (5), Karton/Kartony, Kategorie cluster (8), Klikněte cluster (15), Klient, Klíčové (5), Knowledge base, Kodex, Komentář (6), Komentovat, Komise/Komisionování, Konec cluster (8), Konfigurace (5), Konflikt (3), Kontakt (5), Konverze (5), Kopírovat, Korea, Koruna česká, Korýši, Kosher, Košík je prázdný, Krabice/Krabicovka, Kredit (2), Kritická (4), Krize, Krok cluster (8), Kruhový graf, Krájecí stroj, Krájet (3), Krátký, Krém, Kupní smlouva, Kupón (3), Kurkuma, Kurýr (4), Kusovník, Kvartil, Květen, Káva (5), Kód (6), Křížová kontaminace
- **Přidáno SK:** 505 (10121 → 10626)
- **Přidáno DE:** 505 (10073 → 10578)
- **Pokrytí po batchi:** SK 66.6%, DE 66.1%
- **Verify:** ✓ gen běh, ✓ bundle valid, ✓ 8/8 spot-checks (9. byl test pro non-existující frázi).
- **Commit:** *(následuje)*
- **Poznámky:**
  - `Inflace` → DE `Inflation`
  - `Inkaso` → DE `Lastschrift`
  - `IČO` → DE `Firmen-ID` (DE nemá přímý ekvivalent CZ identifikátoru)
  - `Indie` → SK `India`, DE `Indien` (země)
  - `Jednatel` → SK `Konateľ`, DE `Geschäftsführer`
  - `Květen` → SK `Máj`
  - `Karton (krabice)` → DE `Karton (Schachtel)`, `Krabička` → DE `Schachtel`
  - `Klient` → DE `Kunde` (klient v B2B kontextu)
  - `Komise` → DE `Provision` (kontext: provize, ne komise = výbor)
  - `Komisionování` → DE `Kommissionierung`
  - `Klouzavý průměr` → SK `Kĺzavý priemer`, DE `Gleitender Durchschnitt`
  - `Krabicovka pro gastro výrobce` → DE `Schachtelvarianten für Gastro-Hersteller` (volný překlad)

---

## Batch 6 — 2026-05-19

- **Téma:** L + M letters (~480) — LED/LIFO/LOT/LPG/LTL/LTV, Laboratoř, Lahev, Lahůdkárna, Laravel, Laser, Latence, Latte (3), Lazy loading, Lead cluster (8), Lean, Leasing, Leden→Január, Lednice, Ledová káva, Legenda (4), Letadlo + Letadlový režim, Letecký, Letní (6), Letos, Levné/Levný, Licence (3), Liché řádky, Lidské zdroje, Liga, Light, Lightbox, Likvidace (4), Likér, Limetky, Limit cluster (4), Lineární (4), Link odeslán, Linked list, LinkedIn (2), Linode, Listopad, Litva, Live (4), Lněná semínka, Load balancer/testy, Loading spinner, Loajální zákazník, Local deployment, Lock screen, Lodí, Logistika, Logo + Logo a vzhled, Lokace cluster (10), Londýn (GMT), Long press, Loni, Lookalike, Lookbook, Lopatka, Lotyšsko, Loyalty program, Lucembursko, Lungo, Luxusní (2), Léto, Lístek (3), Lívanec — pak M-words: MAU/MD/MRR/MTBF/MTTR, Macaron, Macchiato, Macro-influencer, Madrid (CET), Maestro, Mailchimp, Major (3), Major incident, Makroekonomie, Malware (2), Malá (2), Malé písmeno, Malý (2), Man in the middle, Managed (3), Manažer cluster (6), Mandel, Mangan, Manipulant, Manuální (8), Mapa rizik, Mapování (2), Margarín, Marinovat, Marketing cluster (10), Marketplace, Maroko, Marže, Masopust, Mastercard, Matcha, Mate, Material Design, Materiál, Max cluster (5), Maximum cluster (8), Maximální (4), Mazání (2), Maďarsko + Maďarský forint, Medián + Mediánová tržba, Medové perníčky, Mega/Mega-influencer, Member price, Memoization, MoU, Memory (3), Menu engineering, Meta (5), Method, Mexické peso/Mexiko, Mezery (3), Mezi (10), Mezitím, Micro-influencer, Microdata, Microservices, Microsoft (2), Middle mile, Migrace (3), Mikro (8), Milestone, Miliarda/Milion/Miliony, Mimo (5), Mimořádný, Min/Mince/Minerálka, Mini (2), Minimal (5), Minimum (6), Minor (2), Minulé (4), Minut/Minuta/Minuty, Miska, Mistr/Mistrovství, Mitigace, Mix-and-match, Mizí (3), Mladý, Mletá pepř, Mluví o nás, Mléčné výrobky + Mléko Vejce Lepek, Mlýn, Mlčenlivost, Mnoho/Mnohokrát děkujeme, Množstevní sleva, Množství (2), Mobil/Mobilní (8), Mocha, Modifikovat, Modulární licence, Mohlo by vás zajímat, Money-back guarantee, Monitorování (2), Monochromatic, Monolithic, Monthly report, Motivační dopis, Mouky a směsi, Mozzarella, Možná (3), Možnost, Multi (5), MultiSport, Multiplayer hry, Multiplikátor bodů, Musíte přijmout, Mute (2), Muškát, Mytí rukou, Málo, Mám, Máta, Máte (4), Mátový sirup, Mé projekty, Médium, Méně (3), Míchat, Mínili jste, Mínus, Míra odchodu, Mísa, Místní (4), Místo (3), Mýtický, Mějte se hezky, Měkká střídka, Měkký stín, Měkkýši, Měna (2), Mění nastavení, Měrka, Město, Měsíc (2), Měsíční (7), Měď, Měřící (2), Můj (3), Může obsahovat stopy, Můžeme/Můžete/Můžeš (5)
- **Přidáno SK:** 479 (10626 → 11105)
- **Přidáno DE:** 479 (10578 → 11057)
- **Pokrytí po batchi:** SK 69.3%, DE 68.9%
- **Verify:** ✓ gen běh OK, ✓ bundle valid.
- **Commit:** *(následuje)*
- **Poznámky:**
  - `Leden` → SK `Január`, `Listopad` → SK/DE `November`
  - `Lepek` → DE `Gluten` (správný DE pojem)
  - `Letadlový režim` → SK `Letový režim`, DE `Flugmodus`
  - `Levný` → SK `Lacný` (preferovaný překlad pro "levný")
  - `Loni` → SK `Vlani`, DE `Letztes Jahr`
  - `Lucembursko` → SK `Luxembursko`, DE `Luxemburg`
  - `Manažer` → SK `Manažér` (SK má diakritiku), DE `Manager`
  - `Mandel` → SK `Mandľa`, DE `Mandel` (CS=DE pro mandle)
  - `Maturitní` → DE `Abitur-` (kontext: maturitní zkouška)
  - `Masopust` → SK `Fašiangy`, DE `Fasching`
  - `Mlčenlivost` → DE `Verschwiegenheit` (kontext: NDA / mlčenlivost o údajích)
  - `Manipulant` → DE `Lagerarbeiter` (kontext: warehouse worker)
  - `Mezi-státní přenos` → DE `Grenzüberschreitende Übermittlung` (GDPR pojem)
  - `Měkká střídka` / `Hutná střídka` (pekařský termín) → SK přeloženo, DE `Weiche/Dichte Krume`
  - `Mokrá / suchá střídka` (jen na zboží konkrétní typ)

---

## Batch 7 — 2026-05-19

- **Téma:** N letter (461 entries) — N-tech (NDA/NPS/NOT), Nabídka (4), Nahlásit (5), Nahrát (5), Nainstalovat, Najít (4), Naplnit (2), Naplánovat (5), Naprogramovat, Naskenovat (5), Nastavení/Nastaveno, Native/Nativní, Načíst cluster (8), Načítání cluster (8), Ne / Neaktivní (4), Nealkoholické, Nedávné (5), Neděle, Nefakturováno, Nehmotný majetek, Nejaktivnější, Nejbližší, Nej- comparatives (~22), Nekonečné scrollování, Nekvalifikovaný/Nekvalitní, Nelze cluster (11), Nemoc, Není cluster (10), Neobsahuje (4), Neomezené (2), Neonová, Neověřené (2), Neplatné/Neplatný cluster (16), Neplánovaná (4), Nepodařilo (7), Nepodporovaný (3), Nepotvrzený, Nepoužitelný, Nepravidelná, Nepřetržitý provoz, Nesouhlasit, Nespolehlivé, Nesprávný (3), Nestabilní, Nesynchronizovaný (2), Net Promoter Score, Neuhrazeno, Neuloženo, Neurgentní, Neutrální, Nevyhovuje/Nevyřešeno, Nezaměstnanost, Nezbytné cookies, Nezničitelný, Neznámý zdroj, Neúspěšný, Nešifrované spojení, Nic (4), Nikdy, Nizozemsko → Niederlande, Norsko, Norská koruna, Notebook, Notifikace cluster (24), Nová cluster (15), Nový cluster (12), Nula/Null/Nulový, Nutriční hodnoty, Nuxt.js, Nábor → Personalbeschaffung, Náhled (5), Nájemné, Nájezd km, Nákladní auto, Náklady (4), Nákupní cena/košík, Námořní přeprava, Náplň + Náplně povidla džemy, Nápojový lístek, Nápověda → Pomocník / Hilfe, Náprava, Návod k použití, Návratnost, Návrh (5), Návštěv (3), Název cluster (8), Nízk* (7), Nějaké/Někde/Někdo/Několik/Některé, Německo, Nůž (3)
- **Přidáno SK:** 461 (11105 → 11566)
- **Přidáno DE:** 461 (11057 → 11518)
- **Pokrytí po batchi:** SK 71.8%, DE 71.4%
- **Verify:** ✓ gen běh OK.
- **Commit:** *(následuje)*
- **Poznámky:**
  - `Nizozemsko` → SK `Holandsko`, DE `Niederlande`
  - `Nezaměstnanost` → DE `Arbeitslosigkeit`
  - `Nábor` → DE `Personalbeschaffung`, `Nájem*` → DE `Miete*`
  - `Napřesrok` → SK `Nabudúce`, DE `Nächstes Jahr`
  - `Na shledanou` → SK `Dovidenia`, DE `Auf Wiedersehen`
  - `Není zač` → SK `Niet za čo`, DE `Gern geschehen` (správný DE zdvořilostní obrat)
  - `Notifikace` → SK plural `Notifikácie` (CS singular sometimes treated as plural)
  - `Nestabilní` → DE `Instabil`, `Nešifrované spojení` → `Unverschlüsselte Verbindung`

---

## Batch 8–13 (souhrn) — 2026-05-19

- **Batch 8:** O cluster (~345). Objednávka, Obnovit, Odběratel, Odebrat, Odeslat, Odhlásit, Odměny, Označit, Ověřit, Otevřít, Ovoce. Commit `d02c463`.
- **Batch 9:** P part 1 (~515). PDF/PWA, Pekařský, Plán, Platba, Pobočka, Pole, Pomoc, Potvrdit, Pouze, Použít, Povolit, Poznámka, Pozvat, Počet. Commit `62cc4d8`.
- **Batch 10:** P part 2 (~516). Pracovní, Premium, Pro cluster, Procent, Profil, Promo, Provoz, První, Práce, Právo, Průměr, Push, Pátek, Pět, Před, Předvolby, Přepnout, Připomenout, Přihlásit, Přijmout, Připojit, Pří*. Commit `8475ef3`.
- **Batch 11:** R + T clusters (~599). React, Reklam*, Receptury, Registr*, Resetovat, Restart, Rezervace, Roční, Rusko, Rychlý, TLS, Telefon, Tento, Termín, Test, Tisk, Tlačítko, Top, Trasování, Trend, Tržby, Týmový. Commit `7f382ad`.
- **Batch 12:** S cluster (~974). SLA/SMS/SMTP, Schválit, Sdílet, Server, Servis, Sezónní, Skupina, Sleva, Sloučit, Smazat, Smlouva, Souhlas, Spravovat, Spustit, Synchronizace, Stránka, Stáhnout, Středa, Stůl, Svátek, Šablona-related. Commit `934ae89`.
- **Batch 13:** V cluster (~691). Vada, Validace, Variabilní symbol, Vegan, Velikonoční, Velikost, Verifikace, Verze, Vyhledat, Vymazat, Vyplnit, Vytvořit, Vzhled, Výrobek, Výroba, Výchozí, Vítejte, V cloudu/poriadku/přípravě, VIP/VPN/VPS. Commit *(následuje)*.
- **Batch 4:** F-words (172) + G-words (52) — `Faktur*` cluster
- **Batch 5+:** N-words (467), O-words (351 — `Objednávk*` cluster), P-words (1045!), S-words (984), T (372), V (710), Z (407)

Velké bloky (P, S, V) budou rozdělené na 2–3 batche po ~200.
