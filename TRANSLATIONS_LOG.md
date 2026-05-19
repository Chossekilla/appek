# 🌍 APPEK i18n — Translations Log

Per-batch log spravovaný překladovým vláknem. Hlavní vlákno NEEDITUJE.

## 📊 Souhrn

| Metric | Start | Aktuální | Cíl |
|---|---|---|---|
| CS frází celkem | 15800 | 15844 | — |
| SK překlady (`SK_EXTRA`) | 8561 | 11566 | ≥ 14500 (≥ 92%) |
| DE překlady (`DE_EXTRA`) | 8513 | 11518 | ≥ 14500 (≥ 92%) |
| SK pokrytí (po rule fallbacks) | 48.3% | 71.8% | ≥ 92% |
| DE pokrytí | 48.1% | 71.4% | ≥ 92% |
| Poslední batch | — | 7 | — |
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

## 🔭 Plán na další batche

- **Batch 8:** O (351) + part of P (~250) — Objednávky cluster, Otevřít, Odběratel, Odměny, Plán, Platba
- **Batch 4:** F-words (172) + G-words (52) — `Faktur*` cluster
- **Batch 5+:** N-words (467), O-words (351 — `Objednávk*` cluster), P-words (1045!), S-words (984), T (372), V (710), Z (407)

Velké bloky (P, S, V) budou rozdělené na 2–3 batche po ~200.
