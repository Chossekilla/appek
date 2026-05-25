# 🗺️ APPEK B2B Roadmap

Co se chystá po launch verzi 3.0. Plány nejsou pevné termíny — orientují prioritou, fixovat se budou postupně dle feedbacku z prvních zákazníků.

---

## 🎯 Vize

> **Jeden systém pro celý gastro provoz — od pultu po web.**
> APPEK má být kompletní digitální backbone malých-středních pekáren, cukráren, lahůdek a restaurací. Vlastní data, vlastní hosting, žádný měsíční vendor lock-in.

---

## 📦 3.0.0 — OFFICIAL LAUNCH *(červen 2026)*

✅ **Hotovo a v prodeji.** První veřejná verze.

Plné novinky: [CHANGELOG.md](CHANGELOG.md).

---

## 📱 3.1 — Mobile First *(plán: léto 2026)*

**Cíl:** vlastní mobilní appka co umí všechno z webu, plus využívá to, co umí jen telefon.

### Hlavní features

- 🍏 **Nativní iOS app** (App Store)
- 🤖 **Nativní Android app** (Google Play)
- ☁️ **Cloud sync** mezi web adminem ↔ mobilem ↔ POS terminálem
  - Real-time sync objednávek (push notif "Nová objednávka")
  - Offline mode s konfliktní resolution při návratu online
- 📇 **Import kontaktů z telefonu**
  - Tap "Přidat odběratele" → výběr z mobile address booku
  - Auto-fill: jméno, telefon, email, adresa
  - Sync zpět do webového adminu
- 📸 **Foto výrobků přímo z fotoaparátu**
  - Skenování čárových kódů surovin pro rychlé naskladnění
  - QR kódy stolů pro instant menu
- 📍 **Geofencing rozvozů**
  - Kurýr vidí trasu na mapě
  - Auto-notif zákazníkovi "Kurýr je 5 min od vás"
  - Tracking proof-of-delivery (signature)
- 💳 **NFC/Apple Pay/Google Pay** pro POS
- 🔔 **Push notifikace** native (nejen PWA)

### Technologie

- **React Native** (sdílený kód iOS/Android) NEBO **Flutter** (TBD per benchmark)
- **Sync backend:** WebSocket nebo Server-Sent Events + offline queue
- **Auth:** sdílená session s webem přes OAuth2 / JWT

### Co to mění

- Customer dostane v ceně Premium balíčku (nebo +1500 Kč jako add-on)
- Existující users → free upgrade
- Setup: scan QR kód v admin UI → mobile app se spojí se serverem

---

## 🔄 3.2 — Integrace s účetnictvím *(plán: podzim 2026)*

**Cíl:** APPEK fakturu → 1 klik → účetní program.

### Plánované integrace

- 🇨🇿 **Pohoda** — XML export přes mServer API
- 🇨🇿 **FlexiBee** — REST API import (oboustranný sync)
- 🇨🇿 **Money S3** — ISDOC standard
- 🇨🇿 **iDoklad** — REST API
- 🇸🇰 **iKros** / **WinDuo** — exporty
- 📊 **Excel template generator** — pokud nemáš software, gen XLSX s mappingem

### Workflow

1. APPEK vystaví fakturu
2. Auto-push do účetnictví (configurable: ihned / denní batch / manuální klik)
3. Status sync zpět: "zaplaceno", "po splatnosti", "stornováno"
4. Bez nutnosti přepínat mezi systémy

---

## 🤖 3.3 — AI asistent *(plán: zima 2026/27)*

**Cíl:** APPEK ti říká co dělat, ne ty jemu.

### Features

- 🧠 **Predikce poptávky** podle historie + počasí + svátků + dní v týdnu
  - "V pátek nech udělat 20% více croissantů — minulý víkend došly v 10:30"
- 💬 **AI chatbot** pro support zákazníků
  - Odběratel napíše do B2B portálu → AI odpoví na časté otázky
  - Eskalace na člověka když AI neví
- 🍰 **Auto-kalkulace marže**
  - "Croissant máš za 18 Kč, ale konkurence v okolí za 25 Kč. Doporučená cena: 22 Kč"
- 📈 **Anomaly detection**
  - "Včera byly o 40% nižší tržby než průměr — nemáš výpadek elektřiny / personálu?"
- 🌍 **Multi-jazyk AI**
  - Customer support v 5 jazycích automaticky

### Implementace

- **OpenAI API** nebo **lokální LLM** (Llama 3 / Mistral) pro privacy-sensitive operations
- **Optimalizace cost:** ne každý request přes AI — jen complex queries

---

## 🏪 4.0 — Marketplace & White-label *(plán: 2027)*

**Cíl:** APPEK jako platforma, ne jen produkt.

### Marketplace pluginů

- 🛒 **Plugin store** v admin UI
- Third-party vývojáři mohou vyrábět plugins (jednorázová cena nebo freemium)
- Plugin API: PHP hooks + JS extension points
- Příklady plugin typů:
  - Custom payment gateway (Twisto, Pay4Pay…)
  - Integrace s konkrétní pokladnou (Kasa fik, Markeeta)
  - Custom report typ (per-customer, per-region…)
  - White-label theming

### White-label varianta

- "APPEK na klíč pod tvou značkou"
- Resellers (agency, IT firmy) si APPEK rebrand a prodávají vlastním klientům
- Vendor backend pro reseller: správa svých customers, faktury, support tickets

### Multi-tenant managed cloud (optional)

- Pro customery co nechtějí self-host
- Cena: měsíční předplatné jako klasický SaaS (alternativa k license-sale)
- Hosting na vendor straně, customer dostává jen URL + login
- Geo-rozšíření: APPEK ve více evropských zemích

---

## 💭 Beyond 4.0 — vzdálenější vize

- 🌐 **B2B marketplace** — odběratelé hledají nové dodavatele přímo v APPEKu
- 🏷️ **AI generování receptů** podle dostupných surovin
- 🚚 **Logistic optimization** — AI routing pro rozvozy (real-time traffic)
- 🥖 **APPEK pro malé pekárny** (lite version, freemium)
- 📊 **APPEK Insights** — anonymized benchmarking ("průměrná marže v ČR cukrárně")

---

## 💡 Feedback & priority

Co by tě zajímalo nejvíc? Jako customer máš největší slovo:

📧 [feedback@appek.cz](mailto:feedback@appek.cz)
🌍 [appek.cz/roadmap-vote](https://appek.cz/) *(public voting plánováno pro 3.1)*

---

*Roadmap se aktualizuje ~1× měsíčně. Status k poslední aktualizaci: 2026-05-25.*
