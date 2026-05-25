# Vendor Panel — Master Control Panel

**Privátní administrační rozhraní pro provozovatele platformy APPEK B2B.**

| | |
|---|---|
| **Verze** | 2.0.4 |
| **Aktualizováno** | 2026-05-17 |
| **Audience** | Provozovatel platformy (interní použití) |
| **Distribuce** | ❌ NEDISTRIBUOVAT zákazníkům |

---

## Obsah

1. [Účel](#účel)
2. [Funkce](#funkce)
3. [Nasazení](#nasazení)
4. [Bezpečnostní opatření](#bezpečnostní-opatření)
5. [První přihlášení](#první-přihlášení)
6. [Generování licenčních klíčů](#generování-licenčních-klíčů)
7. [Sales CMS](#sales-cms)
8. [Správa zákazníků](#správa-zákazníků)
9. [Údržba](#údržba)

---

## Účel

Vendor Panel je centrální administrační rozhraní pro **provozovatele platformy APPEK** — slouží k:

- Generování a správě licenčních klíčů
- Evidenci zákazníků a vydaných licencí
- Editaci obsahu marketingové stránky `appek.cz`
- Monitorování provozu subdomén
- Konfiguraci platebních a komunikačních služeb

> **Warning:** Tato složka **NESMÍ** být distribuována koncovým zákazníkům. Customer distribuční balíček (`appek-customer-*.zip`) ji explicitně vylučuje.

---

## Funkce

### Správa licencí

- Generování nových klíčů s evidencí zákazníka
- Volitelná expirace (auto-přepnutí na `expired` ve 00:00)
- Revokace klíče s evidovaným důvodem
- Reaktivace revokovaných klíčů
- Evidence ceny a stavu úhrady
- Search a filtr (active / expired / revoked)
- Statistický dashboard (počet, expirující, tržby)

### Sales CMS

- Vizuální editor obsahu pro `appek.cz`
- Editace hero sekce, cen, FAQ, kontaktů ve 3 jazycích (CZ/EN/ES)
- JSON editor pro pokročilé úpravy
- Auto-versioning s audit logem

### CRM (Zákazníci)

- Agregovaný přehled zákazníků z vydaných licencí
- Vyhledávání a filtry
- Export CSV/JSON
- Color-coded tarify (Starter / Profi / Premium)

### Monitor subdomén

- Live status check všech subdomén
- HTTP code + latence v ms
- Diagnostické informace (DNS, SSL, content)
- Cache výsledků 5 minut

### Editor právních stránek

- HTML editor pro GDPR, Obchodní podmínky, Cookies
- Auto-backup před uložením
- Preview na live URL

### Nastavení

- SMTP konfigurace
- Stripe / GoPay integrace
- IP whitelist (poznámky)
- License workflow (auto-e-mail, expirační připomínky)

### Bezpečnost

- Bcrypt password hashing
- Two-Factor Authentication (TOTP)
- Rate limiting na login (5 pokusů / 15 minut)
- Audit log všech akcí
- Session cookie s `secure`, `httponly`, `samesite=Lax`

---

## Nasazení

### Doporučená subdoména

`vendor.appek.cz` — separátní subdoména s vyšším stupněm zabezpečení.

### 1. Upload souborů

Z lokálního prostředí nahrajte složku `vendor/` na hosting:

```bash
# Hostinger / cPanel
scp -r vendor/ user@appek.cz:~/public_html/vendor/

# Alternativně přes FTP/SFTP klienta (FileZilla, Cyberduck)
```

### 2. Vytvoření MySQL databáze

Doporučujeme **separátní databázi** pro vendor systém (oddělená od customer DB):

```sql
CREATE DATABASE appek_vendor
  CHARACTER SET utf8mb4
  COLLATE utf8mb4_unicode_ci;
```

> **Tip:** Separátní databáze chrání licenční data v případě kompromitace customer DB.

### 3. Spuštění instalátoru

Otevřete v prohlížeči: `https://vendor.appek.cz/install.php`

Wizard projde:

1. **Databáze** — credentials pro vendor DB
2. **První admin** — vytvoření vendor administrátora (min. 10 znaků hesla)
3. **Dokončení** — automatická migrace schématu

### 4. Po instalaci

> **Warning:** Ihned po dokončení:
>
> - Smažte `vendor/install.php`
> - Nastavte `vendor/config.local.php` na chmod 600
> - Aktivujte 2FA v Settings → Security
> - Nakonfigurujte IP whitelist v `vendor/.htaccess`

---

## Bezpečnostní opatření

Vendor Panel obsahuje **kritická data** (licenční klíče, zákaznické údaje, platební credentials). Bezpečnost je prvořadá.

### IP whitelist (POVINNÉ pro produkci)

V `vendor/.htaccess`:

```apache
<RequireAll>
  Require all denied
  Require ip 192.168.1.0/24       # Vaše LAN
  Require ip 88.146.x.x            # Vaše veřejná IP
  Require ip 109.81.x.x            # Záložní IP (mobil)
</RequireAll>
```

Zjištění vlastní IP: `curl ifconfig.me` nebo `https://www.whatismyip.com/`.

### Two-Factor Authentication

Po prvním přihlášení **okamžitě aktivujte 2FA**:

1. `Settings → Security → Enable 2FA`
2. Naskenujte QR kód v Google Authenticator nebo Authy
3. Uložte záložní kódy do password manageru

### Silné heslo

- Minimum **10 znaků** (vynuceno aplikací)
- Doporučeno **16+ znaků** s kombinací velkých/malých písmen, čísel a symbolů
- Heslo měňte při podezření na kompromitaci

### Záloha keystore

Pokud používáte Android TWA build:

- Keystore zazálohujte na **3 různá místa** (cloud, USB, papír s QR)
- Heslo k keystore zapamatovat **nikdy** neměnit (jinak ztratíte možnost vydávat updates)

---

## První přihlášení

1. Otevřete `https://vendor.appek.cz/`
2. Přihlaste se údaji vytvořenými v instalátoru
3. **Okamžitě:**
   - Aktivujte 2FA (`Settings → Security`)
   - Nakonfigurujte SMTP pro odesílání licenčních e-mailů
   - Vyplňte IP whitelist v `vendor/.htaccess`

### Dashboard

Po přihlášení uvidíte:

- **KPI karty** — aktivní licence, tržby, počet zákazníků
- **Stav subdomén** — live monitor s latencí
- **Nedávná aktivita** — audit log posledních akcí
- **Rychlé akce** — generování klíče, edit CMS, atd.

---

## Generování licenčních klíčů

### Postup

1. `🔑 Licence → ➕ Generovat klíč`
2. Vyplňte:
   - **Zákazník** — jméno / firma
   - **E-mail** — pro doručení klíče
   - **Telefon** — volitelné
   - **URL instalace** — kde bude APPEK provozován
   - **Tarif** — Starter / Profi / Premium
   - **Expirace** — datum (volitelné, prázdné = neomezeně)
   - **Cena** — pro evidenci tržeb
3. **Generovat**

### Formát klíče

```
APPEK-XXXX-XXXX-XXXX-XXXX
```

Klíč obsahuje HMAC SHA-256 podpis. Customer instalátor jej ověřuje lokálně bez phone-home.

### Automatické odeslání e-mailem

Pokud máte v Settings povolenou volbu `auto_email`, klíč se po vygenerování automaticky odešle zákazníkovi na uvedený e-mail s šablonou welcome.

### Manuální distribuce

Klíč můžete také zkopírovat z detailu licence a poslat zákazníkovi jakkoliv (přes Stripe receipt, samostatný e-mail, atd.).

---

## Sales CMS

### Editace obsahu appek.cz

`🛒 Sales CMS` umožňuje editovat obsah marketingové stránky bez nutnosti FTP přístupu.

### Editovatelné sekce

- **Hero** — badge, titulek, lead text (CZ/EN/ES)
- **Pricing** — 3 tarify s cenami v CZK/EUR/USD
- **Kontakt** — IČO, DIČ, adresa, e-mail, telefon, IBAN
- **Social** — odkazy na FB, Instagram, LinkedIn
- **Stats** — sociální proof (počet zákazníků, výrobků, atd.)

### Storage

Obsah se ukládá do `/sales-content.json` v rootu webu. Sales stránka jej načítá za běhu přes JavaScript.

### Versioning

Každé uložení inkrementuje `_meta.version` a zaznamenává `updated_by`. Pro audit změn.

---

## Správa zákazníků

`💼 Zákazníci` — agregovaný přehled z vydaných licencí.

### Funkce

- Vyhledávání podle jména, e-mailu, domény
- Filter podle tarifu, stavu (aktivní / expirovaný / revokovaný)
- Detail zákazníka s historií licencí
- Export do CSV (Excel-ready, s BOM pro CZ encoding)
- Export do JSON (pro integrace)

### Použití pro marketing

Export CSV lze nahrát do:

- Mailchimp / Mailerlite — newsletter kampaně
- Hubspot / Pipedrive — CRM workflow
- Google Sheets — vlastní reporty

---

## Údržba

### Pravidelné úkoly

| Frekvence | Úkol |
|-----------|------|
| **Denně** | Kontrola aktivity log (`Settings → Activity`) — neočekávané akce? |
| **Týdně** | Kontrola expirujících licencí (Premium ✕ Profi) |
| **Měsíčně** | Export záloh databáze, kontrola stavu subdomén |
| **Kvartálně** | Review IP whitelistu, změna admin hesla |
| **Ročně** | Renewal SSL certifikátů (Let's Encrypt obvykle automaticky), upgrade PHP verze |

### Záloha databáze

Cron job pro automatickou denní zálohu:

```cron
0 3 * * * php /home/user/public_html/vendor/scripts/backup-vendor.php
```

Zálohy se ukládají do `vendor/.backups/` s rotací 30 dní.

### Aktualizace systému

Při vydání nové verze:

1. Stáhněte nový balíček
2. Zazálohujte aktuální `vendor/` přes FTP
3. Nahrajte nové soubory (přepište existující)
4. Spusťte migrate skripty: `php vendor/scripts/migrate.php`
5. Otestujte funkčnost klíčových akcí

---

## Související dokumenty

- [README.md](../README.md) — Přehled produktu APPEK B2B
- [SECURITY.md](../deploy/docs/SECURITY.md) — Bezpečnostní průvodce
- [HOSTING_SETUP.md](../deploy/docs/HOSTING_SETUP.md) — Průvodce nasazením

---

**Kontakt pro provozovatele:** support@appek.cz
**Aktualizováno:** 2026-05-17
