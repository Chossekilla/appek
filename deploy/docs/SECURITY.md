# Bezpečnostní průvodce

**Doporučení a checklist pro bezpečný provoz APPEK B2B.**

| | |
|---|---|
| **Verze** | 2.0.4 |
| **Aktualizováno** | 2026-05-17 |
| **Audience** | Administrátor systému |
| **Compliance** | GDPR, ePrivacy, PSD2-ready |

---

## Obsah

1. [Pre-deploy checklist](#pre-deploy-checklist)
2. [Autentizace](#autentizace)
3. [Síťová bezpečnost](#síťová-bezpečnost)
4. [Databáze](#databáze)
5. [Soubory a oprávnění](#soubory-a-oprávnění)
6. [PHP konfigurace](#php-konfigurace)
7. [GDPR compliance](#gdpr-compliance)
8. [Penetrační testování](#penetrační-testování)
9. [Incident response](#incident-response)
10. [Best practices](#best-practices)

---

## Pre-deploy checklist

Před nasazením do produkce projděte následující checklist:

### HTTPS / SSL

- [ ] HTTPS aktivní na všech subdoménách (test: `https://...`)
- [ ] Let's Encrypt auto-renew zapnut
- [ ] HSTS header s `max-age=63072000; includeSubDomains; preload`
- [ ] CAA záznam v DNS: `0 issue "letsencrypt.org"`
- [ ] DNSSEC zapnut u registrátora domény (volitelné, doporučeno)

### Hlavičky a `.htaccess`

- [ ] Šablony z `deploy/htaccess-templates/` nasazeny do správných složek
- [ ] `Options -Indexes` (zákaz listingu)
- [ ] `ServerSignature Off`
- [ ] CSP header bez chyb (DevTools → Console)
- [ ] X-Frame-Options `SAMEORIGIN`
- [ ] X-Content-Type-Options `nosniff`
- [ ] Referrer-Policy `strict-origin-when-cross-origin`

### Vendor panel (master)

- [ ] **IP whitelist** v `vendor/.htaccess` (povinné!)
- [ ] 2FA aktivní pro všechny vendor admin účty
- [ ] Heslo k vendor min. 16 znaků
- [ ] Separátní databáze (oddělená od customer DB)

### Databáze

- [ ] DB uživatel má **jen práva na svou DB** (ne `GRANT ALL ON *.*`)
- [ ] Silné heslo (24+ znaků, generované)
- [ ] `api/config.local.php` chmod **600**
- [ ] Backup nastaven (cron 3:00)
- [ ] Charset `utf8mb4` (NE `utf8`)

### Soubory

- [ ] `install.php` smazán po dokončení instalace
- [ ] `phpinfo.php` neexistuje
- [ ] `.git/`, `.github/` mimo webroot
- [ ] `composer.json`, `composer.lock` mimo webroot (pokud existují)

### PHP

- [ ] Verze 8.0+ (8.2+ doporučeno)
- [ ] `expose_php = Off`
- [ ] `display_errors = Off` (produkce!)
- [ ] `error_log = /var/log/php_errors.log` (mimo webroot)
- [ ] `session.cookie_secure = 1`
- [ ] `session.cookie_httponly = 1`
- [ ] `session.cookie_samesite = Strict`

### Účty

- [ ] Admin heslo min. 16 znaků
- [ ] 2FA TOTP aktivní pro všechny admin účty
- [ ] Neaktivní zaměstnanci mají `aktivni = 0`
- [ ] Rate limiting na login funguje (5 pokusů / 15 min)

---

## Autentizace

### Argon2id hashing

Hesla jsou hashována přes `password_hash($password, PASSWORD_ARGON2ID)`. Toto je výchozí v PHP 8.0+ a poskytuje moderní ochranu proti rainbow tables a brute force.

### Rate limiting

| Endpoint | Limit |
|----------|-------|
| Login (admin) | 5 pokusů / 15 minut na IP nebo e-mail |
| Login (B2B) | 5 pokusů / 15 minut na IP nebo e-mail |
| Password reset | 3 pokusy / hodinu na e-mail |
| API token | 1000 požadavků / hodinu na token |

Implementace v `api/config.php` funkce `login_rate_limited()`.

### Two-Factor Authentication

Aplikace podporuje TOTP (Time-based One-Time Password) kompatibilní s:

- Google Authenticator
- Authy
- 1Password
- Microsoft Authenticator
- Bitwarden

Aktivace: `Nastavení → Bezpečnost → 2FA → Enable`.

> **Tip:** Pro vendor panel doporučujeme 2FA vynutit i pro běžné admin účty. V Settings je toggle `twofa_required`.

### Session cookies

Konfigurace v `api/config.php`:

```php
session_set_cookie_params([
    'lifetime' => 7200,                   // 2 hodiny
    'path'     => '/',
    'secure'   => true,                   // HTTPS only
    'httponly' => true,                   // JavaScript blocked
    'samesite' => 'Lax',                  // CSRF protection
]);
```

---

## Síťová bezpečnost

### HSTS preload

Pro maximální ochranu před downgrade attacks:

```apache
Header always set Strict-Transport-Security \
    "max-age=63072000; includeSubDomains; preload"
```

Po nasazení doporučujeme zaregistrovat doménu na [hstspreload.org](https://hstspreload.org).

### Content Security Policy (CSP)

Restriktivní CSP v `.htaccess`:

```apache
Header always set Content-Security-Policy "
    default-src 'self';
    style-src 'self' 'unsafe-inline';
    script-src 'self' 'unsafe-inline' https://cdn.jsdelivr.net;
    img-src 'self' data: https:;
    font-src 'self' data:;
    connect-src 'self';
    frame-ancestors 'self';
"
```

> **Note:** `'unsafe-inline'` pro CSS je potřeba kvůli SPA architektuře. Pro script-src budeme migrovat na nonce-based CSP v budoucí verzi.

### Permissions Policy

Vypnutí nepotřebných browser API:

```apache
Header always set Permissions-Policy \
    "geolocation=(), microphone=(), camera=(), payment=(), usb=()"
```

### IP whitelist pro vendor panel

`vendor/.htaccess`:

```apache
<RequireAll>
    Require all denied
    Require ip 192.168.1.0/24
    Require ip 88.146.x.x
</RequireAll>
```

Zjištění IP: `curl ifconfig.me` nebo `whatismyip.com`.

---

## Databáze

### Prepared statements

Veškeré SQL operace používají PDO prepared statements:

```php
$stmt = db()->prepare("SELECT * FROM odberatele WHERE email = :email");
$stmt->execute(['email' => $email]);
```

> **Warning:** Nikdy nepoužívejte string concatenation v SQL — `"SELECT * FROM users WHERE email = '$email'"` je vulnerable na SQL injection.

### Charset

Vždy `utf8mb4` pro plnou Unicode podporu (emoji, speciální znaky):

```sql
CREATE DATABASE appek
    CHARACTER SET utf8mb4
    COLLATE utf8mb4_unicode_ci;
```

### Záloha

Cron job pro denní zálohu:

```cron
0 3 * * * /home/user/public_html/scripts/backup.sh
```

Zálohy se ukládají do `api/.backups/` s rotací 30 dní.

### Šifrování at rest

- Hesla: Argon2id
- API tokeny: HMAC SHA-256
- Citlivé údaje v `nastaveni` tabulce: AES-256-GCM (pro Stripe/GoPay credentials)

---

## Soubory a oprávnění

### Doporučené chmod

```bash
# Soubory
find ~/public_html -type f -exec chmod 644 {} \;

# Adresáře
find ~/public_html -type d -exec chmod 755 {} \;

# Citlivé soubory
chmod 600 ~/public_html/api/config.local.php
chmod 600 ~/public_html/vendor/config.local.php
chmod 600 ~/public_html/vendor/.env  # pokud existuje

# Zapisovatelné adresáře
chmod 775 ~/public_html/uploads
chmod 775 ~/public_html/api/.backups
chmod 775 ~/public_html/api/.logs
```

### Vyloučení citlivých souborů

V `.htaccess`:

```apache
<FilesMatch "\.(env|log|sql|gitignore|md|bak|old)$">
    Require all denied
</FilesMatch>
```

### Po instalaci smažte

- `install.php` (na root)
- `vendor/install.php`
- `repair-schema.php` (pokud byl použit)
- `EMERGENCY-RESET.php` (pokud byl použit)
- `DEMO-DEBUG.php` (pokud byl použit)

---

## PHP konfigurace

### `php.ini` nebo `.user.ini`

Doporučené hodnoty pro produkci:

```ini
# Bezpečnost
expose_php = Off
display_errors = Off
display_startup_errors = Off
log_errors = On
error_log = /var/log/appek-error.log

# Sessions
session.cookie_secure = 1
session.cookie_httponly = 1
session.cookie_samesite = Strict
session.use_strict_mode = 1
session.gc_maxlifetime = 7200

# Limity
memory_limit = 256M
upload_max_filesize = 32M
post_max_size = 32M
max_execution_time = 60
max_input_time = 60

# Vyloučení nebezpečných funkcí (pokud možno)
disable_functions = exec,passthru,shell_exec,system,proc_open,popen
```

> **Note:** Některé hostingy neumožňují měnit `disable_functions`. Hostinger Premium ano přes File Manager → `.user.ini`.

---

## GDPR compliance

APPEK B2B je navržen tak, aby splňoval požadavky GDPR (EU 2016/679):

### Práva subjektu údajů

| Právo | Implementace |
|-------|-------------|
| **Přístup** | Export osobních údajů v `Nastavení → GDPR → Export` |
| **Oprava** | Editace v `Odběratelé → detail` |
| **Výmaz** | Anonymizace (NE smazání) — zachová účetní data |
| **Omezení** | Toggle `aktivni = 0` |
| **Přenositelnost** | Export do JSON/CSV |
| **Námitka** | Unsubscribe link v marketingových e-mailech |

### Audit log

Tabulka `aktivita_log` zaznamenává:

- Kdo (admin_id, ip)
- Co (akce, target entity)
- Kdy (timestamp)
- Stará a nová hodnota (pro update)

Pro GDPR audit dotaz (data subject access request — DSAR).

### Šifrování přenosu

- HTTPS povinné (TLS 1.2+)
- HSTS preload
- Žádné non-encrypted DB connection (i v rámci LAN)

### Záznam o zpracování (čl. 30 GDPR)

Pro plnění čl. 30 vytvořte:

1. **Účel zpracování** — fakturace, B2B objednávky, marketing
2. **Kategorie dat** — identifikační, kontaktní, transakční, technická
3. **Příjemci** — hosting, účetní, platební brána
4. **Doba uchování** — 10 let (účetnictví), 3 roky (marketing)

Template: `docs/gdpr-procesni-zaznam.docx` (přiloženo v `appek-master-*.zip`).

---

## Penetrační testování

### OWASP Top 10 — status APPEK

| Riziko | Status | Mitigation |
|--------|--------|-----------|
| A01 — Broken Access Control | ✅ | Session-based auth, RBAC |
| A02 — Cryptographic Failures | ✅ | Argon2id, AES-256-GCM, TLS 1.2+ |
| A03 — Injection | ✅ | PDO prepared statements |
| A04 — Insecure Design | ✅ | Threat modeling, secure by default |
| A05 — Security Misconfiguration | ⚠️ | Vyžaduje správnou konfiguraci hostingu |
| A06 — Vulnerable Components | ✅ | Vanilla stack, žádné dependencies |
| A07 — Authentication Failures | ✅ | 2FA, rate limiting, strong password policy |
| A08 — Software Integrity Failures | ✅ | HMAC SHA-256 license signing |
| A09 — Logging Failures | ✅ | `aktivita_log` tabulka |
| A10 — SSRF | ✅ | Žádné user-controlled URL fetching |

### Doporučená pen-test cadence

- **Roční** — externí audit (pro Premium tarif)
- **Po major release** — interní review
- **Po incidentu** — okamžitě

---

## Incident response

### V případě podezření na kompromitaci

1. **Zachovat klid** — máte zálohy
2. **Maintenance mode** — vytvořte `public_html/maintenance.html`:

```html
<!DOCTYPE html>
<html><head><title>Údržba</title></head>
<body><h1>Systém je v plánované údržbě</h1>
<p>Vrátíme se za chvíli. Děkujeme za trpělivost.</p></body></html>
```

A přidejte do `.htaccess`:

```apache
RewriteEngine On
RewriteCond %{REMOTE_ADDR} !^88\.146\.x\.x  # Vaše IP
RewriteRule ^ /maintenance.html [L]
```

3. **Změňte hesla:**

- Admin účty
- DB uživatel
- FTP/SSH credentials
- SMTP heslo
- Stripe/GoPay API klíče

4. **Zkontrolujte logy:**

```bash
tail -100 ~/public_html/api/.logs/error.log
tail -100 /var/log/apache2/access.log | grep -E "(\.php\?|POST)"
```

5. **Obnovte ze zálohy** (před incidentem):

```bash
# DB
gunzip < ~/.backups/db-2026-05-15.sql.gz | mysql -u user -p appek_db

# Files
unzip ~/.backups/files-2026-05-15.zip -d ~/public_html/
```

6. **Aktualizujte na nejnovější verzi** APPEK

7. **Informujte zákazníky** (pokud došlo k úniku osobních dat) — do 72 hodin podle čl. 33 GDPR

8. **Kontaktujte security@appek.cz** pro forenzní analýzu

---

## Best practices

### Týdně

- [ ] Kontrola `aktivita_log` — neočekávané přihlášení?
- [ ] Kontrola error log — narůstající chyby?
- [ ] Kontrola velikosti DB — neobvyklý růst?

### Měsíčně

- [ ] Aktualizace APPEK na nejnovější verzi
- [ ] Změna admin hesla (pokud nemáte 2FA)
- [ ] Test obnovy ze zálohy
- [ ] Review aktivních uživatelských účtů

### Kvartálně

- [ ] Audit přístupů k DB
- [ ] Review IP whitelistu ve `vendor/.htaccess`
- [ ] Aktualizace PHP a MySQL na hostingu
- [ ] Penetration test (interní)

### Ročně

- [ ] Externí penetration test
- [ ] Renewal SSL certifikátů (Let's Encrypt obvykle automaticky)
- [ ] Review licence a tarifů
- [ ] Audit logů z `aktivita_log` (export, archivace)

---

## Hlášení zranitelností

Pokud najdete bezpečnostní zranitelnost, **nezveřejňujte ji publicky**. Místo toho:

📧 **security@appek.cz**

Pro detailní raporty doporučujeme PGP šifrování. PGP klíč: [appek.cz/.well-known/pgp-key.asc](https://appek.cz/.well-known/pgp-key.asc).

### Bug bounty

Za potvrzené zranitelnosti vyplácíme bug bounty:

| Severita | Odměna |
|----------|--------|
| **Critical** | 50 000 Kč |
| **High** | 20 000 Kč |
| **Medium** | 5 000 Kč |
| **Low** | 1 000 Kč |

Podmínky: viz [appek.cz/security/bug-bounty](https://appek.cz/security/bug-bounty).

---

## Související dokumenty

- [README.md](../../README.md) — Přehled produktu
- [HOSTING_SETUP.md](HOSTING_SETUP.md) — Průvodce nasazením
- [LICENSE.md](../../LICENSE.md) — Licenční podmínky

---

**Kontakt pro bezpečnost:** security@appek.cz
**Aktualizováno:** 2026-05-17
