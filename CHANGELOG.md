# Changelog

**Historie verzí APPEK B2B.**

Formát: [Keep a Changelog](https://keepachangelog.com/cs/1.1.0/) · [Semantic Versioning](https://semver.org/lang/cs/).

| | |
|---|---|
| **Aktuální stabilní** | 2.0.15 |
| **Aktualizováno** | 2026-05-17 |

---

## [2.0.15] — 2026-05-17

### Přidáno

- **Admin updater UI** (`admin/updater.html`) — customer si zkontroluje a stáhne nové verze rovnou v adminu
- **API updates_apply.php** — atomic update flow: download → SHA verify → extract → file-level checksum → auto-backup → apply
- **SMTP/Mail modul** (`vendor/_mail.php`) — lightweight mailer, SMTP nebo PHP mail(), bez externích deps
- **Auto-email licence** — po `shop.mark_paid` se automaticky vygeneruje licence + odešle e-mail zákazníkovi s šablonou
- **Vendor settings: SMTP konfigurace** — UI pro from email/name, SMTP host/port/auth/TLS, test odeslání

### Změněno

- `vendor/settings.php` — TODO placeholdery nahrazeny funkční SMTP konfigurací

---

## [2.0.14] — 2026-05-17

### Přidáno

- **Sales na appek.cz/** (přesunuto z /sales/) — root index.html zobrazuje marketing rovnou
- **🔌 Integrations sekce** — 6 karet s čistým označením „✅ Funguje" vs „⏳ Příprava":
  - ARES + RPO (CZ + SK registry firem) — funkční
  - Pohoda / Money S3 / FlexiBee / Helios přes ISDOC export — funkční
  - DPH CZ/SK + EET ready + EU OSS — funkční
  - CSV/Excel/REST API/SMTP/EAN/QR/PDF — funkční
  - Stripe + GoPay (Q1 2026) — příprava
  - Zásilkovna + DPD (Q2 2026) — příprava
- **Root index.php router** — fallback pro customer deployment: detekce install state, redirect

### Změněno

- `build-zip.sh` — customer ZIP nyní exkluduje root index.html/checkout.html/sitemap.xml (jen master má sales)
- Vendor odkazy `../sales/` → `../` (sales je teď na rootu)

### Smazáno

- Složka `sales/` (přesunutý obsah na root)

---

## [2.0.13] — 2026-05-17

### Přidáno

- **Mobile / PWA hero sekce** v sales — prominentní tmavá sekce s animovaným pulse mockup telefonu
- **Demo polish** (`demo/index.html`) — 3-jazyčný přepínač, copy buttons na credentials, glass-morphism design, mobile hint
- **Auto-aktualizace karta** ve features (místo PWA — která je teď v hero)

### Změněno

- Topbar nav: přidán link `📱 Mobile` směřující na #mobile anchor

---

## [2.0.12] — 2026-05-17

### Přidáno

- **Architektura: 3-sekce dashboard** ve vendor/index.php (Produkt · Zákazníci · Showcase)
- **Customer distribuce** karta — info o customer ZIPu pro distribuci

### Změněno

- **MASTER ZIP exkluduje admin/ a b2b/** — řeší se přes update modul, ne deploy
- Sjednocený nav: 📊 Přehled · 🛒 Eshop · 🎁 Balíčky · 🔑 Licence · 🔄 Aktualizace · 👥 Přístupy · 🧪 Demo · ⚙️
- Vendor/index.php refaktorováno: pure dashboard, licence přesunuty do licenses.php

---

## [2.0.11] — 2026-05-17

### Přidáno

- **🔄 Update modul** — OTA distribuce nových verzí pro zákaznické instalace:
  - `vendor/updates.php` — UI upload, publish, deprecate, stats
  - `api/updates_check.php` — license-gated check (POST)
  - `api/updates_download.php` — license-gated streaming (GET)
  - `scripts/build-update.sh` — lokální build s SHA-256 manifest
  - Schema: `vendor_updates`, `vendor_update_installs`
  - Storage: `vendor/updates_storage/` (HTTP zakázán direct přístup)

---

## [2.0.10] — 2026-05-17

### Přidáno

- **Vendor dashboard cards** — subdomain status s mini info a přímými odkazy
- **Licenses page** + **Shop orders** + **Packages** + **Sales CMS** ve vendor panelu
- **Schema rozšíření**: `vendor_packages`, `vendor_shop_orders` + seed 6 balíčků
- **Veřejné API**: `api/shop_buy.php`, `api/shop_packages.php`
- **Sales/checkout.html** — kompletní 3-jazyčný eshop flow

---

## [2.0.4] — 2026-05-17

### Přidáno

- **i18n masivní rozšíření** — Aplikace nyní obsahuje 43 149 přeložených klíčů (CZ/EN/ES)
- **Sales page rozšíření** — Nové sekce: testimoniály, srovnání s SaaS, use cases (4 persony), integrace, newsletter
- **Plná responzivita** — 9 breakpointů (320 / 360 / 414 / 640 / 768 / 1024 / 1280 / 1440 / 1920px)
- **Dark mode** podpora přes `prefers-color-scheme` (opt-in)
- **Reduced motion** podpora pro accessibility
- **Print styles** pro fakturace a dokumenty
- **Sticky mobile CTA** — fixed bottom bar na mobilech

### Změněno

- Sjednocený profesionální styl všech dokumentů
- Nové znění README.md, INSTALL.md, SECURITY.md, CHANGELOG.md

### Opraveno

- Nic kritického — drobné překlady a UI polish

---

## [2.0.3] — 2026-05-17

### Přidáno

- **Sales page** rozšířena na 213 i18n klíčů
- **i18n_auto.js** rozšířen o 1 700+ nových frází (BATCH 22-47)
- **Sticky mobile CTA**
- **Apple-style scroll animations**

### Změněno

- Sjednocený design pricing tabulky
- Vylepšené FAQ sekce (8 položek s plnou translací)

---

## [2.0.2] — 2026-05-17

### Přidáno

- **Install wizard multi-jazyk** (CZ/EN/ES) s language switcherem v topbaru
- **Schema fallback** — explicit create kritických tabulek bez foreign keys
- **`repair-schema.php`** — záchranný nástroj v balíčku pro doplnění chybějících tabulek
- **`INSTALL.txt`** — krok-za-krokem návod v ZIP balíčku

### Změněno

- `login_rate_limited()` v `config.php` nyní **defensive** — auto-vytvoří tabulku při chybějícím schématu
- `login_log()` graceful fallback místo fatal error
- ZIP balíčky vytvořeny **flat** (bez wrapping složky) pro snadnější extract

### Opraveno

- **Fatal error při loginu** kvůli chybějící `prihlaseni_pokusy` tabulce
- **Foreign key errors** u `recurring_orders` a `sklad_pohyby` (nyní ignorovány)
- **Wrapping složka** v ZIPu (`appek master/`) — opraveno

---

## [2.0.1] — 2026-05-17

### Přidáno

- **Hostinger-specific dokumentace** a tipy
- **DEMO-DEBUG.php** — diagnostický nástroj
- **EMERGENCY-RESET.php** — záchranný admin reset
- **SESSION-FIX.php** — oprava session cookie path bug

### Opraveno

- **Session cookie path bug** ve vendor panelu (auto-detect subdomain vs subpath)
- **Robustní subdomain ping** v vendor dashboard (curl → fsockopen fallback)
- **Demo subdomain** redirect loop
- **Sales page footer** — broken links na `/blog`, `/docs`, `/o-nas` nahrazeny

---

## [2.0.0] — 2026-05-16

### Přidáno — major release

#### Master Control Panel (vendor)

- **Dashboard** s KPI a subdomain monitorem
- **Customers** — agregovaný CRM z licencí + CSV/JSON export
- **Sales CMS** — vizuální editor obsahu pro appek.cz
- **Pages** — HTML editor pro GDPR/OP/Cookies
- **Subdomains monitor** — live status check s diagnostikou
- **Settings** — SMTP, Stripe, GoPay, IP whitelist
- **2FA TOTP** pro vendor admin účty

#### Sales / Marketing

- **Sales page** (`appek.cz`) — kompletní marketingová stránka s hero, features, pricing, FAQ, contact
- **Sales CMS integration** — content řízený přes JSON s vendor adminem
- **GDPR, Obchodní podmínky, Cookies** — plné HTML stránky

#### Subdomény

- 5-subdoménová architektura (sales / admin / b2b / demo / vendor)
- Per-subdomain `.htaccess` šablony s rozdílnou bezpečností
- Demo subdomain s auto-login a hourly reset

#### Onboarding

- **9-krokový onboarding wizard** v admin
- Lokalizované defaults (T4) — měna, DPH, regiony podle jazyka
- Demo data seed (15 výrobků, 5 odběratelů, 3 objednávky)
- Quick start checklist

#### Lokalizace

- **3 jazyky:** Čeština, English, Español
- **APPEK_LOCALES** konstanta s daty pro každý jazyk
- Automatická aplikace měny, DPH sazeb, regionů podle jazyka

#### Android

- **TWA (Trusted Web Activity)** package pro Play Store
- Bubblewrap config + build skript
- Play Store listing šablony (CZ/EN/ES)
- Privacy policy template

#### Bezpečnost

- HSTS preload na všech subdoménách
- CSP rozšířena (`default-src 'self'`)
- Argon2id pro password hashing
- Rate limiting na login (5 pokusů / 15 min)

### Změněno — major refactor

- Admin přejmenován z `/admin/` na `/admin/` (security by obscurity)
- Sazby DPH 15% → 12% (legislativní změna od 1.1.2024)
- Topbar package badges přesunuty z bočního menu (32×32px squares)
- `fmt()` funkce — locale-aware, respektuje `getCurrentLocale()`
- API endpoint `admin_nastaveni.php` rozšířen o 5 nových locale klíčů

### Opraveno

- Race condition v multi-user editaci výrobků
- CSP blokovala inline event handlery — přidáno `'unsafe-inline'`
- Mobilní fullscreen modaly na iOS Safari
- Push notifikace na Chrome 121+
- Service worker cache invalidation

---

## [1.5.0] — 2024-09-01

### Přidáno

- HACCP modul (analýza nebezpečí, kritické body, kontrolní záznamy)
- Výrobní plán s denní kapacitou
- Aktivita log + audit trail
- ARES integrace (CZ) pro auto-fill firmy podle IČO

### Změněno

- Migrace na PHP 8.0 jako minimum
- DPH dvousazbové → třísazbové (0/12/21 %) od 2024
- Dashboard přepracován do "card-grid" layoutu

---

## [1.0.0] — 2024-01-15

### První produkční verze

- Základní CRUD pro výrobky, odběratele, objednávky
- Faktury, dodací listy, ISDOC export
- B2B portál (samoobsluha)
- PWA s offline cache a push notifikacemi
- 4 vzhledové motivy (Apple, Modern, Win98, Dark)

---

## Plánováno

### [2.1.0] — Q3 2026

- Stripe / GoPay integrace pro automatický prodej z webu
- Auto-email licencí po platbě
- Affiliate program (referral linky + commission)
- Native Android app v Play Store
- Pokročilá analytika (Cohorts, LTV, AOV)

### [2.2.0] — Q4 2026

- AI chatbot pro customer support (Claude API)
- Predikce poptávky (ML model)
- Více jazyků: DE, FR, IT, PL, SK, HU
- Integrace s Pohodou / Money S3 / FlexiBee (one-click sync)

### [3.0.0] — 2027

- Marketplace pluginů (3rd party rozšíření)
- White-label brandable pro Premium zákazníky
- Multi-tenant cloud (managed hosting)
- iOS native app
- Real-time multi-user editing

---

## Versioning

APPEK používá [Semantic Versioning](https://semver.org/):

- **MAJOR** (X.0.0) — breaking changes, migrace nutná
- **MINOR** (X.Y.0) — nové funkce, zpětně kompatibilní
- **PATCH** (X.Y.Z) — bug fixes, drobné vylepšení

### Upgrade cesta

Pro hladký upgrade:

1. **Záloha DB i souborů** před každým updatem
2. **Test na staging** (pokud máte) před produkcí
3. **Postupný upgrade** — neskakovat přes major verze
4. **Migrate skripty** se spouští automaticky v `install.php`

---

**Kontakt:** support@appek.cz
**Web:** [appek.cz](https://appek.cz)
