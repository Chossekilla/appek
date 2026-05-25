# APPEK B2B 3.0 — Pekárenský & restaurační management

[![Verze](https://img.shields.io/badge/verze-3.0.0-BA7517.svg)](https://appek.cz)
[![PHP](https://img.shields.io/badge/PHP-8.1%2B-777BB4.svg)](https://www.php.net)
[![MySQL](https://img.shields.io/badge/MySQL-5.7%2B-4479A1.svg)](https://www.mysql.com)
[![Jazyky](https://img.shields.io/badge/jazyky-5-success.svg)](#-jazyky)
[![Licence](https://img.shields.io/badge/licence-Komer%C4%8Dn%C3%AD-FFC107.svg)](LICENSE.md)
[![Demo](https://img.shields.io/badge/demo-live-22c55e.svg)](https://demo.appek.cz)

**Vše pro váš provoz v jednom systému.** Objednávky, výroba, sklady, fakturace, B2B portál, POS kasa, HACCP — jeden krabicový systém pro pekárny, cukrárny, lahůdkárny, restaurace a catering. Bez měsíčních poplatků, vaše data zůstanou na vašem serveru.

🌐 **Demo:** [demo.appek.cz](https://demo.appek.cz) · 🏠 **Web:** [appek.cz](https://appek.cz) · 📩 [info@appek.cz](mailto:info@appek.cz) · 📞 +420 733 700 808

---

## ✨ Co APPEK umí

- 📋 **Objednávky → Výroba → Fakturace** — celý workflow v jedné aplikaci, propojený od přijetí poptávky po ISDOC export do účetnictví
- 🛍️ **B2B portál pro odběratele** — vlastní login, online katalog, individuální ceny, košík, historie a opakované objednávky
- 🧾 **POS kasa, KDS a stolová mapa** — prodej na pokladně, kuchyňský displej, plán stolů a rezervace
- 🥖 **Výrobní plán & sklady surovin** — auto-sumarizace surovin z objednávek a receptur, šarže, alerty pod minimum, HACCP-ready dokumentace
- 🚛 **Rozvozové trasy a dodací listy** — denní rozvozový plán podle PSČ, automatické faktury, opakované objednávky (cron)
- 🏷️ **Cenovky, štítky a kalkulace** — 15 designů, A4 archy (Avery, SEVT), kalkulace ceny za kus ze surovin a režií, EU 1169/2011 nutriční tabulky
- 📱 **PWA pro mobil i tablet** — instaluje se jako nativní appka, push notifikace, offline mód, dark mode

> 🎬 [Vyzkoušejte si vše naživo na demo.appek.cz](https://demo.appek.cz) — žádná registrace, plně funkční prostředí s ukázkovými daty.

---

## 📦 Balíčky

**Core** je vždy v ceně. Specializované balíčky aktivuješ kdykoliv — bez reinstalace.

| Balíček | Cena | Pro koho |
|---|---|---|
| ⭐ **Core** *(Starter)* | **12 990 Kč** | Základ — objednávky, faktury, výroba, HACCP, cenovky, B2B portál, POS |
| 🎂 **Cukrárna** | +2 990 Kč | Torty a dorty, konfigurátor zakázek, alergeny, předobjednávky na termín |
| 🥪 **Lahůdky** | +2 490 Kč | Studená kuchyně, bedny, gramáže, krabičkování, denní menu |
| 🍽️ **Restaurace** | +3 490 Kč | Menu engineering, rezervace, kuchyně & sklad, stolová mapa |
| 🎉 **Catering** | +2 990 Kč | Eventy & banketing, kalkulace, sety, logistika rozvozů, kalkulátor na hosta |
| 🍂 **Sezónní akce** | +1 990 Kč | Pop-up moduly: trhy, vánoce, velikonoce, festivaly |

**Jednorázová platba. Plný zdrojový kód. 1 rok updaty zdarma.** 🛒 [Objednat na appek.cz](https://appek.cz/#pricing)

---

## 🚀 Rychlé nastavení (3 kroky)

```
1️⃣  KUP LICENCI         2️⃣  NAHRAJ ZIP          3️⃣  SPUSŤ INSTALL.PHP
    appek.cz/#pricing  →   FTP / File Manager  →   wizard za 5 minut
```

1. **Kup licenci** na [appek.cz](https://appek.cz/#pricing) přes Stripe — dostaneš ZIP balíček a licenční klíč e-mailem
2. **Nahraj ZIP** na svůj hosting (Wedos, Forpsi, Hostinger, Active24, Hetzner — funguje všude, kde běží PHP 8) a rozbal jej
3. **Otevři `https://tvoje-domena.cz/install.php`** — wizard tě provede: licenční klíč → MySQL credentials → ARES auto-fill firmy → admin účet → hotovo

🎁 **Bonus zdarma:** pomoc s migrací z Excelu / Google Sheets, individuální design cenovek, vlastní e-mail šablony.

Detailní průvodce: [instalace.html](https://appek.cz/instalace.html)

---

## 🛠️ Technologie

Zero-dependency stack — žádný `node_modules`, žádný build step, žádný framework lock-in.

- **Backend:** PHP 8.1+ (vanilla, bez frameworku) + PDO MySQL
- **Frontend:** Vanilla JavaScript ES2020 + CSS s proměnnými
- **Databáze:** MySQL 5.7+ / MariaDB 10.3+ (utf8mb4)
- **PWA:** Service Worker, Web Push API, offline-first
- **Bezpečnost:** Argon2id, HMAC SHA-256, 2FA TOTP, CSRF tokens, license-key gating
- **Platby:** Stripe Checkout + webhooks, GoPay, PayPal
- **Self-update:** klik z admin panelu — vendor cloud doručí update ZIP, aplikace si jej rozbalí sama + SHA256 verify + auto-rollback při selhání

Plný zdrojový kód v balíčku. Můžeš si ho upravit, nebo nechat upravit — žádný framework, žádný kompilátor, jen čisté PHP a JS.

---

## 📋 Požadavky

| Komponenta | Minimum | Doporučeno |
|-----------|---------|------------|
| **PHP** | 8.1 | 8.2+ |
| **MySQL / MariaDB** | 5.7 / 10.3 | 8.0 / 10.6+ |
| **Web server** | Apache, Nginx, LiteSpeed | LiteSpeed (Hostinger) |
| **HTTPS** | doporučeno | povinné pro PWA |
| **Disk** | 500 MB | 5 GB+ |

**PHP rozšíření:** `pdo_mysql`, `openssl`, `gd`, `gmp`, `curl`, `mbstring`, `json`, `fileinfo`, `zip`
*(standardně dostupná na všech českých webhostincích i v XAMPP / MAMP / Laragon)*

**Klient:** Chrome 90+, Edge 90+, Firefox 88+, Safari 14+, iOS Safari 14+, Android Chrome 90+

---

## 🌍 Jazyky

APPEK mluví **5 jazyky** — rozhraní pro tebe i mezinárodní odběratele, přepnutí jedním klikem:

🇨🇿 Čeština · 🇸🇰 Slovenčina · 🇬🇧 English · 🇩🇪 Deutsch · 🇪🇸 Español

Překlady jsou **lazy-loaded** (jen aktivní jazyk se stáhne v runtime), 18 000+ překladových klíčů pokrývá kompletní admin, B2B portál, POS i e-mailové šablony.

---

## 🔒 Bezpečnost a monitoring

- **`app_errors` DB tabulka** — každá chyba se zaloguje s `request_id` a admin ji najde v Diagnostice
- **Healthcheck endpoint** `/api/healthcheck.php` — 7 checks (DB, schema, write, disk, file, error rate, PHP) — externí monitor (UptimeRobot, BetterStack) friendly
- **Proaktivní monitoring** — admin panel ukáže červený banner sekundy po incidentu, ne až user nahlásí
- **Bell notifikace** při error spike nebo selhání healthchecku
- **Auto-rollback** při selhání self-update
- 41 endpoints používá `json_error_safe()` — žádný leak SQL detailů klientovi
- POS + admin frontend error capture do centrální DB

---

## 📞 Podpora

- 📧 **E-mail:** [info@appek.cz](mailto:info@appek.cz)
- 📞 **Telefon:** +420 733 700 808
- 🌍 **Region:** ČR, SK, EU, mezinárodně
- ⏱️ **Reakční doba:** do 24 hodin v pracovní dny
- 🔒 **GDPR:** [Zásady ochrany osobních údajů](https://appek.cz/zasady-ochrany-soukromi.html) · [Obchodní podmínky](https://appek.cz/obchodni-podminky.html)

Sleduj nás: [Facebook @appek.cz](https://www.facebook.com/profile.php?id=61590201243782)

---

## 📄 Licence

**Komerční licence** — kup na [appek.cz](https://appek.cz/#pricing), nainstaluj na vlastní server, používej navždy. Plný zdrojový kód, žádné měsíční poplatky, časově neomezená licence vázaná na doménu.

**Můžeš:** používat na vlastním hostingu, upravovat zdrojový kód, lokalizovat pro svůj provoz, migrovat mezi hostingy.
**Nemůžeš:** dál prodávat, redistribuovat nebo nabízet jako SaaS třetím stranám.

Plné znění: [LICENSE.md](LICENSE.md) · [Obchodní podmínky](https://appek.cz/obchodni-podminky.html)

---

## 🗺️ Roadmap

3.0.0 je **první oficiální launch verze**. Co se chystá:

- **3.1** — nativní mobile app (iOS / Android), cloud sync pro multi-pobočky, import kontaktů z mobilu
- **3.2** — one-click sync s účetnictvím (Pohoda, FlexiBee, Money S3)
- **3.3** — AI chatbot pro customer support, predikce poptávky
- **4.0** — marketplace pluginů, white-label varianta

Viz [CHANGELOG.md](CHANGELOG.md) pro kompletní historii verzí · [ROADMAP.md](ROADMAP.md) pro detailní plán.

---

**Copyright © 2024–2026 APPEK B2B.** Všechna práva vyhrazena. Vyrobeno v České republice 🇨🇿
