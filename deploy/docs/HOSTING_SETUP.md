# Průvodce nasazením APPEK B2B

| | |
|---|---|
| **Verze** | 2.0.4 |
| **Aktualizováno** | 2026-05-17 |
| **Audience** | Administrátor hostingu |
| **Časová náročnost** | 30–60 minut |

---

## Obsah

1. [Předpoklady](#předpoklady)
2. [Architektura nasazení](#architektura-nasazení)
3. [Konfigurace DNS](#konfigurace-dns)
4. [Nastavení hostingu](#nastavení-hostingu)
5. [Upload souborů](#upload-souborů)
6. [Spuštění instalátoru](#spuštění-instalátoru)
7. [Cron jobs](#cron-jobs)
8. [E-mail (SMTP)](#e-mail-smtp)
9. [Bezpečnostní kontroly](#bezpečnostní-kontroly)
10. [Řešení problémů](#řešení-problémů)

---

## Předpoklady

Před začátkem instalace ověřte, že máte k dispozici:

| Položka | Požadavek |
|---------|-----------|
| **Doména** | Registrovaná (např. `appek.cz`) |
| **Hosting** | PHP 8.0+, MySQL 5.7+, Apache s mod_rewrite a mod_headers, min. 5 GB |
| **FTP/SFTP klient** | FileZilla, Cyberduck, nebo přístup přes File Manager v cPanel |
| **SSL certifikát** | Let's Encrypt (zdarma, většinou automaticky) |
| **MySQL přístupy** | Možnost vytvořit databázi a uživatele |

> **Tip:** Pokud nemáte vlastní hosting, doporučujeme Hostinger Premium (od ~150 Kč/měs.), který splňuje všechny požadavky a podporuje subdomény.

---

## Architektura nasazení

APPEK B2B se skládá ze 4–5 subdomén. Doporučujeme **subdoménové oddělení** z bezpečnostních důvodů.

### Mapování subdomén

| Subdoména | Účel | Document Root |
|-----------|------|--------------|
| `appek.cz` | Marketingová prezentace | `public_html/` |
| `admin.appek.cz` | Administrační panel | `public_html/admin/` |
| `b2b.appek.cz` | B2B portál pro odběratele | `public_html/b2b/` |
| `demo.appek.cz` | Demonstrace (volitelné) | `public_html/demo/` |
| `vendor.appek.cz` | Master Control Panel (jen vlastník) | `public_html/vendor/` |

> **Note:** Backend (`api/`) je sdílený mezi všemi subdoménami. Při subdoménovém rozdělení musí být kopie v každé složce nebo dostupný přes CORS z `api.appek.cz`.

### Alternativa: subpath deployment

Pro malé instalace nebo lokální vývoj lze použít jednu doménu:

```
vase-domena.cz/          → marketing (volitelné)
vase-domena.cz/admin/    → admin
vase-domena.cz/b2b/      → B2B portál
vase-domena.cz/api/      → backend
```

---

## Konfigurace DNS

Pokud používáte vlastní DNS (mimo Hostinger panel), přidejte následující A záznamy.

### A záznamy

```dns
appek.cz.            A    [IP hostingu]    TTL 3600
www.appek.cz.        A    [IP hostingu]    TTL 3600
admin.appek.cz.      A    [IP hostingu]    TTL 3600
b2b.appek.cz.        A    [IP hostingu]    TTL 3600
demo.appek.cz.       A    [IP hostingu]    TTL 3600
vendor.appek.cz.     A    [IP hostingu]    TTL 3600
```

### Záznamy pro e-mail

```dns
appek.cz.            MX   10 mail.appek.cz.
appek.cz.            TXT  "v=spf1 include:_spf.hostinger.com ~all"
appek.cz.            TXT  "v=DMARC1; p=quarantine; rua=mailto:dmarc@appek.cz"
```

### CAA záznam pro Let's Encrypt

```dns
appek.cz.            CAA  0 issue "letsencrypt.org"
```

> **Tip:** Hostinger přidává A záznamy automaticky při vytvoření subdomény v hPanelu. Ruční konfigurace DNS není potřeba.

---

## Nastavení hostingu

### Hostinger (doporučeno)

#### 1. Vytvoření subdomén

`hPanel → Domains → Subdomains → Create new subdomain`

Vytvořte postupně:

| Název | Document Root |
|-------|---------------|
| `admin` | `public_html/admin` |
| `b2b` | `public_html/b2b` |
| `demo` | `public_html/demo` |
| `vendor` | `public_html/vendor` |

#### 2. Aktivace SSL

`hPanel → SSL → Manage SSL`

Aktivujte Let's Encrypt pro každou subdoménu. Hostinger Premium+ aktivuje SSL automaticky do 15 minut.

#### 3. PHP konfigurace

`hPanel → Advanced → PHP Configuration`

| Parametr | Hodnota |
|----------|---------|
| **PHP verze** | 8.2 nebo vyšší |
| **memory_limit** | 256M |
| **upload_max_filesize** | 32M |
| **post_max_size** | 32M |
| **max_execution_time** | 60 |

#### 4. MySQL databáze

`hPanel → Databases → MySQL Databases → Create database`

- **Název databáze:** např. `u123456_appek` (Hostinger předponu nastavuje automaticky)
- **Username:** např. `u123456_admin`
- **Password:** silné heslo (24+ znaků, zapamatujte si je)
- **Privileges:** ALL PRIVILEGES

> **Warning:** Heslo k databázi si uložte do password manageru. Po vytvoření jej již nelze zobrazit, jen resetovat.

### Ostatní hostingy

Postup se podobá Hostingeru, pouze názvosloví ovládacího panelu se liší. Vyhledejte ekvivalenty pro:

- Vytvoření subdomény → "Subdomain Manager", "Domain Manager"
- SSL certifikát → "SSL/TLS Status", "Let's Encrypt"
- MySQL → "MySQL Databases", "Databáze"

---

## Upload souborů

### Příprava balíčku

Použijte oficiální instalační balíček:

- **Master verze** (pro provozovatele platformy): `appek-master-2.0.4.zip`
- **Customer verze** (pro koncové zákazníky): `appek-customer-2.0.4.zip`

### Postup uploadu

1. Přihlaste se do File Manager hostingu nebo FTP klienta
2. Otevřete adresář `public_html/`
3. **Vyčistěte adresář** (pokud obsahuje soubory) — Cmd/Ctrl+A → Delete
4. Nahrajte ZIP balíček
5. Rozbalte v místě (Extract here)
6. Smažte ZIP soubor

### Struktura po rozbalení

```
public_html/
├── install.php           # Instalační wizard
├── instalace.html        # Statický průvodce
├── .htaccess             # Hlavní Apache konfigurace
├── .user.ini             # PHP runtime konfigurace
├── robots.txt
├── sales/                # Marketingová stránka (jen master)
├── admin/                # Admin panel
├── b2b/                  # B2B portál
├── demo/                 # Demonstrace
├── vendor/               # Master Control Panel (jen master)
├── api/                  # Backend
├── deploy/               # .htaccess šablony a docs
├── scripts/              # Backup a maintenance skripty
└── uploads/              # User uploads (chmod 775)
```

### Permissions

Po uploadu nastavte správná oprávnění:

```bash
# Soubory: 644
find ~/public_html -type f -exec chmod 644 {} \;

# Adresáře: 755
find ~/public_html -type d -exec chmod 755 {} \;

# Citlivé soubory (po instalátoru): 600
chmod 600 ~/public_html/api/config.local.php

# Zapisovatelné adresáře: 775
chmod 775 ~/public_html/uploads
chmod 775 ~/public_html/api/.backups
```

> **Tip:** Hostinger v File Manageru umožňuje hromadné nastavení permissions. Pravým tlačítkem → "Permissions" → "Apply to all files in subfolders".

---

## Spuštění instalátoru

1. Otevřete v prohlížeči: `https://vase-domena.cz/install.php`
2. **Vyberte jazyk** (CZ / EN / ES) — switcher v pravém horním rohu
3. **Vložte licenční klíč** (formát `APPEK-XXXX-XXXX-XXXX-XXXX`)
4. **Vyberte režim provozu** (Cloud / Hybrid / Local)
5. **Server check** — wizard ověří PHP verzi a rozšíření
6. **Databáze** — vyplňte DB credentials z předchozího kroku
7. **Admin účet** — vytvořte hlavní administrátorský účet (e-mail + heslo)
8. **Dokončení** — wizard naimportuje DB schéma a seed data

### Po instalaci

> **Warning:** Z bezpečnostních důvodů ihned po instalaci proveďte:
>
> 1. Smažte `install.php` z `public_html/`
> 2. Nastavte `api/config.local.php` na chmod 600
> 3. Otestujte přihlášení do admin panelu na `https://admin.vase-domena.cz/`

---

## Cron jobs

APPEK využívá několik plánovaných úloh. Konfigurace v `hPanel → Advanced → Cron Jobs`.

### Doporučené cron jobs

```cron
# Opakované objednávky — každou hodinu
0 * * * * curl -s "https://vase-domena.cz/api/admin_recurring_run.php?token=SECRET"

# Denní záloha — 3:00 ráno
0 3 * * * php /home/user/public_html/scripts/backup.sh

# Demo reset — každou hodinu (jen pokud používáte demo subdoménu)
0 * * * * php /home/user/public_html/scripts/demo-reset.php

# Čištění logů — neděle 4:00
0 4 * * 0 find /home/user/public_html/api/.logs -name "*.log" -mtime +30 -delete

# Hybrid sync — každých 15 minut (jen pro hybrid režim)
*/15 * * * * php /home/user/public_html/api/sync/agent.php
```

> **Note:** Token pro `admin_recurring_run.php` najdete v `api/config.local.php` po dokončení instalace.

---

## E-mail (SMTP)

Pro odesílání faktur, licenčních klíčů a notifikací nakonfigurujte SMTP.

### Vytvoření e-mailového účtu

`hPanel → Emails → Email Accounts → Create email account`

Doporučená adresa: `noreply@vase-domena.cz` nebo `info@vase-domena.cz`.

### Konfigurace v aplikaci

`admin.vase-domena.cz → Nastavení → SMTP`

| Parametr | Hodnota |
|----------|---------|
| **SMTP host** | `smtp.hostinger.com` (Hostinger) |
| **Port** | 587 (TLS) nebo 465 (SSL) |
| **Šifrování** | TLS |
| **Username** | `noreply@vase-domena.cz` |
| **Password** | heslo k e-mailovému účtu |
| **From e-mail** | `noreply@vase-domena.cz` |
| **From name** | např. `Vaše Firma s.r.o.` |

### Alternativy: transactional služby

Pro vysoký objem (> 200 e-mailů denně) doporučujeme:

- **Mailgun** (10 000 e-mailů zdarma/měs.)
- **SendGrid** (100 e-mailů zdarma/den)
- **Postmark** (placené, ale výborná doručitelnost)

---

## Bezpečnostní kontroly

Po nasazení proveďte následující checklist:

- [ ] HTTPS funguje na všech subdoménách (test: `https://...`)
- [ ] HSTS header aktivní (test: `curl -I https://appek.cz`)
- [ ] CSP header bez chyb (DevTools → Console)
- [ ] `.htaccess` aktivní v každé subdoméně
- [ ] `api/config.local.php` má chmod 600
- [ ] `install.php` smazán
- [ ] Admin účet má silné heslo (16+ znaků)
- [ ] 2FA aktivní pro admin
- [ ] `vendor.appek.cz` má IP whitelist v `.htaccess`
- [ ] `robots.txt` blokuje `/admin/`, `/vendor/`, `/demo/`
- [ ] Záloha databáze nastavena (cron 3:00)
- [ ] PHP `expose_php = Off`
- [ ] Server header skrytý (`ServerSignature Off`)

Detailní bezpečnostní průvodce: [SECURITY.md](SECURITY.md)

---

## Řešení problémů

### 403 Forbidden na hlavní stránce

**Příčina:** Chybí `index.html` v `public_html/` nebo špatný `.htaccess`.

**Řešení:**

1. Ověřte, že `public_html/index.html` existuje
2. Pokud používáte master verzi, obsah `sales/` musí být přesunut do rootu
3. Nahraďte `.htaccess` šablonou z `deploy/htaccess-templates/sales.htaccess`

### Database connection failed

**Příčina:** Nesprávné DB credentials nebo host.

**Řešení:**

1. Otevřete `api/config.local.php` a zkontrolujte hodnoty
2. **DB host** musí být `localhost` (ne IP adresa) na většině sdílených hostingů
3. **DB user a name** mají prefix hostingu (např. `u123456_xxx`)

### Subdomain ukazuje 404

**Příčina:** DNS propagace neproběhla nebo subdoména neexistuje.

**Řešení:**

1. Počkejte 30 minut na DNS propagaci
2. Otestujte: `dig admin.vase-domena.cz`
3. Ověřte v hPanelu, že subdoména existuje a má správný document root

### SSL "Pending" stále po hodinách

**Příčina:** Hostinger auto-SSL se nepodařilo aktivovat.

**Řešení:**

`hPanel → SSL → Install SSL` → manuálně spustit pro každou subdoménu.

### 500 Internal Server Error

**Příčina:** PHP error nebo neplatný `.htaccess`.

**Řešení:**

1. `hPanel → Files → Error Log` — zobrazí přesnou chybu
2. Dočasně přejmenujte `.htaccess` na `.htaccess.bak` pro izolaci problému
3. Ověřte PHP verzi (≥ 8.0)

### Cron neběží

**Příčina:** Špatný formát cron string nebo chybějící token.

**Řešení:**

1. V hPanelu zkontrolujte logy cron jobs
2. Endpointy vyžadují `?token=` parametr — najdete v `api/config.local.php`
3. Otestujte ručně: `curl -v "https://vase-domena.cz/api/cron_recurring.php?token=..."`

---

## Související dokumenty

- [README.md](../../README.md) — Přehled produktu
- [INSTALL.md](../../INSTALL.md) — Instalační průvodce
- [SECURITY.md](SECURITY.md) — Bezpečnostní průvodce
- [SYNC_ARCHITECTURE.md](../../SYNC_ARCHITECTURE.md) — Hybrid synchronizace

---

**Kontakt:** support@appek.cz
**Aktualizováno:** 2026-05-17
