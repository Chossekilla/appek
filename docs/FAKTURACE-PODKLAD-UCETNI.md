# APPEK — Podklad k fakturaci pro účetní / daňového poradce

**Účel:** stručně popsat, jak APPEK počítá a vystavuje daňové doklady, aby účetní/daňový
poradce mohl posoudit soulad se zákonem (ZDPH, zákon o účetnictví) a případně doplnit
připomínky. Verze appky: 3.0.361.

> Co potřebuju od vás: posoudit body níže a označit, co je OK / co upravit. Hlavně:
> metoda zaokrouhlení DPH, náležitosti dokladů, číselné řady, ISDOC, EET relevance.

---

## 1. Výpočet DPH a rozpis podle sazeb

- Cena položky se vede **bez DPH** (`cena_bez_dph`), sazba se ukládá k položce (`sazba_dph`).
- **Rozpis DPH** se na dokladu počítá **po sazbách**: pro každou sazbu se sečte základ
  (Σ množství × cena bez DPH) a daň, oboje se **zaokrouhlí na 2 desetinná místa (haléře)**.
- **Reconciliace (v3.0.356):** haléřový zbytek mezi součtem zaokrouhlených sazeb a hlavičkovou
  částkou se vloží do sazby s **největším základem** → součet rozpisu **sedí přesně** na
  „K úhradě" (i u dokladů s více sazbami, např. 12 % + 21 %). Ověřeno: faktura se sazbami
  12 % + 21 % → rozpis 109,00 + 266,56 = K úhradě 375,56.
- Zaokrouhlení dokladu: **na haléře (2 místa)**, žádné zaokrouhlení na celé koruny (lze doplnit,
  pokud to vyžadujete — řádek „Zaokrouhlení").

**❓ K posouzení:** vyhovuje metoda (zaokrouhlení DPH po sazbách) a 2-místné zaokrouhlení?

---

## 2. Náležitosti dokladu (§29 ZDPH)

Na faktuře/dodacím listu jsou: **dodavatel** (název, IČO, DIČ, adresa), **odběratel** (název,
IČO, DIČ, adresa — snapshot k datu vystavení), **číslo dokladu**, **datum vystavení**, **datum
zdanitelného plnění**, **datum splatnosti**, **variabilní symbol**, **bankovní spojení**, **forma
úhrady**, **rozpis DPH po sazbách** (základ / daň / celkem), **předmět plnění** (položky:
kód, název, množství, jednotka, cena, sazba, celkem).

**❓ K posouzení:** je výčet náležitostí kompletní pro plátce i neplátce DPH?

---

## 3. Dobropis (opravný daňový doklad, §45 ZDPH)

- Vystavuje se k původní faktuře, má **vlastní číselnou řadu `DOB-`**.
- **Záporné částky** (základ/daň/celkem i položky), **vazba na původní doklad** (na dokladu
  „k faktuře <číslo>"), **datum zdanitelného plnění** vyplněné (v3.0.356).
- **Částečný dobropis** (jen některé položky/množství) — kumulativní strop zabrání dobropisovat
  více než zbývá z původní faktury.

**❓ K posouzení:** stačí stávající podoba opravného dokladu? Chcete na něm explicitně **důvod
opravy** + tabulku „původní / nová / rozdíl částka" (dnes je důvod v poznámce)?

---

## 4. Číselné řady

- Řady per **prefix a rok**: `FA-RRRR-NNNN` (faktura), `DOB-` (dobropis), `DL-` (dodací list),
  `OBJ-` (objednávka), POS účtenky vlastní řada.
- **Souvislé a jedinečné**: čítač je atomický (bez děr při souběhu), na čísle dokladu je
  UNIKÁTNÍ index. Reset per rok.
- **Zámek mazání vydaných dokladů** (v3.0.358, **default zapnuto**): vydanou fakturu/DL nelze
  smazat (jen storno/dobropis) → řada zůstane souvislá. (Lze vypnout v Nastavení → Údržba.)

**❓ K posouzení:** je formát a kontinuita řad v pořádku? Doporučujete zámek mazání nechat zapnutý?

---

## 5. ISDOC export (e-faktura)

- APPEK umí export do **ISDOC 6.0.1** (Faktury → Export ISDOC / ZIP) + e-mail účetní.
- Výstup je **validní proti oficiálnímu ISDOC 6.0.1 XSD** (ověřeno strojově,
  `DOMDocument::schemaValidate`): hlavička (DocumentType, ID, UUID, IssuingSystem, data,
  VATApplicable), **AccountingSupplierParty/CustomerParty** (IČO, DIČ, adresa),
  **InvoiceLines**, **TaxTotal** s rozpisem po sazbách, **LegalMonetaryTotal**, platba
  (číslo účtu, **IBAN** dopočítaný mod-97, variabilní symbol).
- **Dobropis** se exportuje jako `DocumentType=2` (opravný daňový doklad).

**⚠️ K ověření na vaší straně:** zkuste prosím **naimportovat vzorovou fakturu i dobropis do
vašeho účetního SW (Pohoda / Money / FlexiBee)** a potvrdit, že se načtou správně (hlavně
dobropis se zápornými částkami). Export je standardně validní, ale chování konkrétního
importu umíme ověřit jen ve vašem programu.

---

## 6. Další k posouzení
- **EET** — je pro provoz (pekárna/cukrárna/restaurace, hotovostní tržby přes POS) relevantní?
  Pokud ano, je třeba doplnit (dnes APPEK EET neřeší).
- **Měna / cizí měna** — APPEK umí dual zobrazení (CZK + cizí měna na dokladu, kurz ČNB).
- **GDPR** — na dokladech jsou osobní/firemní údaje odběratelů; potřebujeme posoudit retenci,
  výmaz, zpracovatelskou smlouvu (samostatně).

---

*Tento podklad je technický popis chování aplikace, ne právní/daňové stanovisko. Připomínky
prosím k jednotlivým bodům — co je třeba upravit, doplníme.*
