# APPEK B2B

**Komerční objednávkový a výrobní systém pro gastro provozy.**

| | |
|---|---|
| **Verze** | 2.0.4 |
| **Vydáno** | 2026-05-17 |
| **Licence** | Komerční (EULA — viz [LICENSE.md](LICENSE.md)) |
| **Web** | [appek.cz](https://appek.cz) |
| **Podpora** | support@appek.cz |

---

## Obsah

1. [Přehled](#přehled)
2. [Hlavní funkce](#hlavní-funkce)
3. [Pro koho je APPEK určen](#pro-koho-je-appek-určen)
4. [Architektura systému](#architektura-systému)
5. [Režimy provozu](#režimy-provozu)
6. [Systémové požadavky](#systémové-požadavky)
7. [Instalace](#instalace)
8. [Tarify a licence](#tarify-a-licence)
9. [Dokumentace](#dokumentace)
10. [Podpora a kontakt](#podpora-a-kontakt)

---

## Přehled

APPEK B2B je modulární systém pro malé a střední gastro výrobce — pekárny, cukrárny, lahůdkárny a catering. Pokrývá kompletní operativu od přijetí objednávky po fakturaci, včetně dokumentace HACCP.

Distribuce je krabicová: jednorázová platba, plný zdrojový kód, hosting podle volby zákazníka.

### Klíčové vlastnosti

- **Bez měsíčních poplatků** — jednorázová licence, plné vlastnictví
- **Vlastní hosting** — data zůstávají u zákazníka
- **Vanilla stack** — PHP 8 + JavaScript bez frameworků a build kroků
- **Multi-jazyk** — čeština, angličtina, španělština (43 000+ překladových klíčů)
- **PWA** — instalovatelná aplikace s offline režimem a push notifikacemi

---

## Hlavní funkce

### Obchodní moduly

| Modul | Popis |
|-------|-------|
| **Objednávky** | Přijetí, úprava, výrobní list, dodací list, fakturace |
| **Faktury** | Plná DPH evidence, ISDOC export (Pohoda, Money S3, FlexiBee) |
| **B2B portál** | Vlastní login pro odběratele, online katalog, košík, historie |
| **Sklad surovin** | Alerty při poklesu pod minimum, audit pohybů, šarže |
| **Výrobní plán** | Auto-sumarizace surovin z objednávek a receptur |
| **Rozvozové trasy** | Seskupení podle města, optimalizace pořadí zastávek |
| **Opakované objednávky** | Šablony s cron spouštěním |
| **Cenovky a štítky** | 15 hotových designů, A4 archy (Avery, SEVT) |
| **HACCP** | Dokumentace, kritické body, teplotní záznamy, audity |
| **Kalkulace** | Suroviny → fixní náklady → cena za kus |

### Komunikace

- **E-mailové šablony** (8 designů, vizuální editor)
- **Web Push notifikace** (zdarma, bez Twilio)
- **REST API v1** (token authentication, pro účetní systémy)

### Customizace

- **4 vzhledové motivy** — Apple, Moderní, Win98 retro, Dark mode
- **Onboarding wizard** s ARES integrací (CZ) a seedem demo dat
- **Hybrid sync** — lokální PC ↔ cloud (HMAC SHA-256)

---

## Pro koho je APPEK určen

| Cílová skupina | Typické použití |
|---------------|-----------------|
| **Pekárny** | Denní výroba, B2B závozy, šarže, HACCP |
| **Cukrárny** | Konfigurátor dortů, předobjednávky, alergeny |
| **Lahůdkárny** | Catering, denní menu, individuální ceny pro firmy |
| **Restaurace** | Sklad, výroba, dodávky, fakturace |
| **Gastro velkoobchod** | B2B portál, cenové úrovně, opakované objednávky |

---

## Architektura systému

APPEK se skládá z několika subdomén/složek, které mohou být na společném nebo oddělených hostech:

| Subdoména | Účel | Audience |
|-----------|------|----------|
| `appek.cz` | Marketingová prezentace | Návštěvníci |
| `admin.*` | Admin panel (SPA) | Zákazníci + interní |
| `b2b.*` | B2B portál pro odběratele | Koncoví odběratelé |
| `demo.*` | Demonstrační prostředí | Leads |
| `vendor.*` | Master Control Panel | Provozovatel platformy |

### Technologický stack

- **Backend:** PHP 8.0+ (vanilla, bez frameworku) + PDO MySQL
- **Frontend:** Vanilla JavaScript ES2020, CSS s proměnnými
- **Databáze:** MySQL 5.7+ / MariaDB 10.3+ (utf8mb4)
- **Server:** Apache nebo Nginx (mod_rewrite, mod_headers)
- **Bezpečnost:** Argon2id, HMAC SHA-256, 2FA TOTP, CSRF tokens

---

## Režimy provozu

Při instalaci si zákazník vybírá jeden ze tří režimů. Volbu lze později změnit v **Nastavení → Synchronizace**.

### Cloud only (doporučeno)

Aplikace běží na webhostingu. B2B portál je dostupný odkudkoliv. Standardní volba pro většinu provozů.

**Kdy zvolit:** Spolehlivé internetové připojení, externí webhosting.

### Hybrid (lokální + cloud)

Hlavní aplikace běží na pekárenském PC, cloud zrcadlí stav. Synchronizace přes HMAC SHA-256.

**Kdy zvolit:** Slabé internetové připojení, nutnost offline provozu s mobilním přístupem pro odběratele.

### Local only (offline)

Vše běží na lokálním PC s XAMPP/MAMP. B2B portál není dostupný z internetu.

**Kdy zvolit:** Pouze interní použití, žádní externí B2B odběratelé.

---

## Systémové požadavky

### Server

| Komponenta | Minimum | Doporučeno |
|-----------|---------|------------|
| **PHP** | 8.0 | 8.2+ |
| **MySQL / MariaDB** | 5.7 / 10.3 | 8.0 / 10.6+ |
| **Apache / Nginx** | jakákoliv verze | LiteSpeed (Hostinger) |
| **HTTPS** | doporučeno | povinné pro PWA |
| **Diskové místo** | 500 MB | 5 GB+ |

**Vyžadovaná PHP rozšíření:**

```
pdo_mysql, openssl, gd, gmp, curl, mbstring, json, fileinfo, zip
```

Tato rozšíření jsou standardně dostupná na všech českých webhostincích (Hostinger, Wedos, Forpsi, Active24) i v lokálních prostředích (XAMPP, MAMP, Laragon).

### Klient

| Prohlížeč | Minimum | Doporučeno |
|-----------|---------|------------|
| **Chrome / Edge** | 90 | aktuální |
| **Firefox** | 88 | aktuální |
| **Safari** | 14 | aktuální |
| **iOS Safari** | 14 | 16+ |
| **Android Chrome** | 90 | aktuální |

> **Warning:** Internet Explorer není podporován. Edge Legacy (pre-Chromium) zobrazí kompatibilitní banner.

---

## Instalace

### Rychlý start (5 minut)

1. **Stáhněte instalační balíček** (`appek-customer-2.0.4.zip`)
2. **Nahrajte na hosting** přes FTP nebo File Manager
3. **Vytvořte MySQL databázi** v ovládacím panelu hostingu
4. **Otevřete** `https://vase-domena.cz/install.php`
5. **Postupujte podle wizardu** — DB credentials, admin účet, dokončeno

Detailní průvodce: [INSTALL.md](INSTALL.md)

### Lokální vývoj

Pro testování na vlastním počítači:

**macOS / Linux:**

```bash
# MAMP nebo XAMPP nainstalovat
cd ~/Sites/
unzip appek-customer-2.0.4.zip
# Otevřít http://localhost:8888/install.php
```

**Windows:**

```powershell
# XAMPP nainstalovat
Expand-Archive appek-customer-2.0.4.zip -DestinationPath C:\xampp\htdocs\appek\
# Otevřít http://localhost/appek/install.php
```

---

## Tarify a licence

| Tarif | Cena | Domén | Uživatelé | Moduly |
|-------|------|-------|-----------|--------|
| **Starter** | 12 990 Kč | 1 | 2 admin | Základní |
| **Profi** | 24 990 Kč | 1 | 5 admin | Plný balíček |
| **Premium** | 49 990 Kč | vlastní | neomezeně | Vše + white-label |

**Všechny tarify zahrnují:**

- Jednorázovou platbu (žádné měsíční poplatky)
- Plný zdrojový kód
- 1 rok bezplatných aktualizací
- E-mailovou podporu
- Časově neomezenou licenci

**Premium tarif dále zahrnuje:**

- White-label varianta (vlastní branding)
- Prioritní telefonickou podporu (SLA 4h)
- Implementaci a školení
- 3 roky bezplatných aktualizací

Detailní podmínky: [LICENSE.md](LICENSE.md)

---

## Dokumentace

### Pro zákazníka

- [INSTALL.md](INSTALL.md) — Instalační průvodce krok za krokem
- [deploy/docs/HOSTING_SETUP.md](deploy/docs/HOSTING_SETUP.md) — Nasazení na produkční hosting
- [deploy/docs/SECURITY.md](deploy/docs/SECURITY.md) — Bezpečnostní doporučení

### Pro vývojáře

- [SYNC_ARCHITECTURE.md](SYNC_ARCHITECTURE.md) — Architektura hybrid synchronizace
- [vendor/README.md](vendor/README.md) — Master Control Panel
- [CHANGELOG.md](CHANGELOG.md) — Historie verzí

### Pro provozovatele platformy

- [vendor/README.md](vendor/README.md) — Generování licenčních klíčů

---

## Podpora a kontakt

- **E-mail:** support@appek.cz
- **Web:** [appek.cz](https://appek.cz)
- **Demo:** [demo.appek.cz](https://demo.appek.cz)
- **Reakční doba:**
  - Starter: 5 pracovních dnů
  - Profi: 24 hodin
  - Premium: 4 hodiny (SLA)

---

**Copyright © 2024–2026 APPEK B2B.** Všechna práva vyhrazena.
