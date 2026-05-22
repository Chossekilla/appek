# Instalační průvodce

**Krok za krokem instalace APPEK B2B na váš hosting.**

| | |
|---|---|
| **Verze** | 2.0.4 |
| **Aktualizováno** | 2026-05-17 |
| **Audience** | Zákazník |
| **Časová náročnost** | 15–30 minut |

---

## Obsah

1. [Před zahájením](#před-zahájením)
2. [Možnosti instalace](#možnosti-instalace)
3. [A. Lokální instalace (XAMPP/MAMP)](#a-lokální-instalace-xamppmamp)
4. [B. Cloud hosting](#b-cloud-hosting)
5. [Spuštění instalátoru](#spuštění-instalátoru)
6. [Po instalaci](#po-instalaci)
7. [Aktivace licence](#aktivace-licence)
8. [Onboarding](#onboarding)
9. [Co dělat když něco nefunguje](#co-dělat-když-něco-nefunguje)

---

## Před zahájením

### Co budete potřebovat

- [ ] **Licenční klíč** (přišel e-mailem po nákupu)
- [ ] **Instalační balíček** ZIP (přišel e-mailem nebo ke stažení v účtu)
- [ ] **Doménu nebo lokální prostředí** (XAMPP/MAMP zdarma)
- [ ] **MySQL databázi** (vytvoříte v ovládacím panelu hostingu)
- [ ] **30 minut času**

### Údaje, které budete vyplňovat

Připravte si:

- Název firmy
- IČO (v ČR — vyplní zbytek automaticky z ARES)
- Adresa firmy
- E-mail pro vystavování faktur
- Bankovní účet (pro QR platby)
- Admin e-mail a heslo (16+ znaků)

---

## Možnosti instalace

Vyberte podle vašich potřeb:

| Možnost | Kdy zvolit | Náklady |
|---------|-----------|---------|
| **Lokální** (XAMPP/MAMP) | Interní použití, žádné externí B2B | Zdarma |
| **Cloud** (Hostinger atd.) | Standardní provoz s B2B portálem | Od 150 Kč/měs. |
| **Hybrid** (Local + Cloud) | Slabé připojení, mobilní B2B přístup | Od 150 Kč/měs. |

---

## A. Lokální instalace (XAMPP/MAMP)

### macOS — MAMP

1. **Stáhněte MAMP** zdarma z [mamp.info](https://www.mamp.info/)
2. **Nainstalujte** a spusťte
3. **Klikněte "Start Servers"** v MAMP aplikaci (Apache + MySQL zelené)
4. **Rozbalte ZIP** do `~/Sites/appek/` nebo `/Applications/MAMP/htdocs/appek/`
5. **Otevřete v prohlížeči:** [http://localhost:8888/appek/install.php](http://localhost:8888/appek/install.php)

> **Tip:** MAMP používá výchozí DB credentials `root` / `root`. Při instalátoru je zadejte do DB konfigurace.

### Windows — XAMPP

1. **Stáhněte XAMPP** zdarma z [apachefriends.org](https://www.apachefriends.org/)
2. **Nainstalujte** s výchozími hodnotami
3. **Spusťte XAMPP Control Panel** → Start Apache + Start MySQL
4. **Rozbalte ZIP** do `C:\xampp\htdocs\appek\`
5. **Otevřete v prohlížeči:** [http://localhost/appek/install.php](http://localhost/appek/install.php)

> **Tip:** XAMPP používá výchozí credentials `root` s **prázdným heslem**.

### Linux — LAMP stack

```bash
# Instalace
sudo apt update
sudo apt install apache2 mysql-server php8.2 php8.2-mysql php8.2-gd \
    php8.2-mbstring php8.2-curl php8.2-gmp php8.2-zip libapache2-mod-php8.2

# Spuštění služeb
sudo systemctl start apache2 mysql

# Rozbalení balíčku
sudo cp -R appek-customer-2.0.4/ /var/www/html/appek/
sudo chown -R www-data:www-data /var/www/html/appek/
sudo chmod -R 755 /var/www/html/appek/

# Otevřete v prohlížeči
open http://localhost/appek/install.php
```

---

## B. Cloud hosting

### 1. DNS konfigurace (10 minut)

Pokud používáte vlastní DNS server, přidejte A záznamy. Pokud používáte Hostinger/Wedos panel, DNS se přidává automaticky při vytvoření subdomény.

### 2. Vytvoření subdomén v hostingu

V administraci hostingu (cPanel, hPanel) vytvořte:

| Subdoména | Document Root |
|-----------|---------------|
| `admin.vase-domena.cz` | `public_html/admin/` |
| `b2b.vase-domena.cz` | `public_html/b2b/` |

Volitelně:

| Subdoména | Document Root |
|-----------|---------------|
| `demo.vase-domena.cz` | `public_html/demo/` |

### 3. Aktivace SSL

V hostingu aktivujte **Let's Encrypt SSL** pro každou subdoménu. Většina hostingů to dělá automaticky do 15 minut.

### 4. Vytvoření MySQL databáze

V ovládacím panelu hostingu:

1. **Databases → MySQL → Create database**
2. **Název:** např. `u123456_appek`
3. **Vytvořte uživatele** se silným heslem
4. **Přidělte ALL PRIVILEGES**

> **Warning:** Heslo k databázi uložte do password manageru (1Password, Bitwarden). Po vytvoření jej již nelze zobrazit.

### 5. Upload souborů

Přes FTP klient (FileZilla, Cyberduck) nebo File Manager v cPanel:

1. **Otevřete `public_html/`**
2. **Smažte existující obsah** (Cmd/Ctrl+A → Delete)
3. **Nahrajte ZIP balíček**
4. **Rozbalte v místě** (Extract here / Rozbalit zde)
5. **Smažte ZIP soubor**

### 6. Nastavení permissions

V File Manageru → pravé tlačítko → Permissions:

| Cesta | Permissions |
|-------|-------------|
| Soubory | 644 |
| Adresáře | 755 |
| `uploads/` | 775 |
| `api/.backups/` | 775 |

Po instalátoru navíc:

| Cesta | Permissions |
|-------|-------------|
| `api/config.local.php` | 600 |

---

## Spuštění instalátoru

Otevřete v prohlížeči:

```
https://vase-domena.cz/install.php
```

Pro lokální vývoj:

```
http://localhost/appek/install.php
```

### Krok 1: Jazyk

V pravém horním rohu vyberte preferovaný jazyk: 🇨🇿 CS / 🇬🇧 EN / 🇪🇸 ES

### Krok 2: Licenční klíč

Vložte klíč ve formátu `APPEK-XXXX-XXXX-XXXX-XXXX` z e-mailu o nákupu.

### Krok 3: Režim provozu

Vyberte:

- **Cloud only** — standardní, doporučeno
- **Hybrid** — lokální PC + cloud sync (pro slabé připojení)
- **Local only** — pouze offline (bez B2B portálu)

### Krok 4: Kontrola serveru

Wizard ověří PHP verzi a rozšíření. Pokud něco chybí, kontaktujte podporu hostingu.

### Krok 5: Databáze

Vyplňte:

| Pole | Hodnota |
|------|---------|
| **Host** | `localhost` (na většině hostingů) |
| **Port** | `3306` |
| **Název databáze** | z předchozího kroku |
| **Uživatel** | z předchozího kroku |
| **Heslo** | z předchozího kroku |

### Krok 6: Admin účet

Vytvořte hlavní administrátorský účet:

| Pole | Doporučení |
|------|------------|
| **Jméno** | Vaše skutečné jméno |
| **E-mail** | Pro přihlášení (musí být platný) |
| **Heslo** | Min. 16 znaků, kombinace velkých/malých/čísel/symbolů |

> **Warning:** Heslo si **okamžitě uložte do password manageru**. Při zapomenutí potřebujete reset přes e-mail.

### Krok 7: Dokončení

Wizard automaticky:

- Vytvoří všechny databázové tabulky (~75)
- Naimportuje výchozí DPH sazby a jednotky
- Vytvoří admin účet
- Nakonfiguruje `api/config.local.php`

---

## Po instalaci

### Bezpečnostní kroky (KRITICKÉ)

> **Warning:** Ihned po dokončení instalace:

1. **Smažte `install.php`** z rootu (přes FTP nebo File Manager)
2. **Nastavte permissions** pro `api/config.local.php` na **600**
3. **Otestujte přihlášení** do administrace na `https://vase-domena.cz/admin/`
4. **Zazálohujte DB credentials** do password manageru

### Aktivace HTTPS

Pokud váš hosting ještě nemá aktivní SSL:

```bash
# cPanel
SSL/TLS Status → Run AutoSSL

# hPanel (Hostinger)
SSL → Manage SSL → Install SSL
```

---

## Aktivace licence

1. Přihlaste se do `https://vase-domena.cz/admin/`
2. Spustí se **9-krokový onboarding wizard**
3. V kroku **Logo a vzhled** klikněte na ikonu 🔑
4. **Vložte licenční klíč** z e-mailu o nákupu
5. **Klikněte Uložit**

Systém ověří klíč proti HMAC podpisu (lokálně, bez phone-home) a aktivuje:

- Moduly podle tarifu (Starter / Profi / Premium)
- Časový limit licence (pokud je nastaven)
- Počet povolených admin uživatelů

---

## Onboarding

Wizard vás provede 9 kroky pro plnou konfiguraci:

| Krok | Co nastavit |
|------|-------------|
| 1 | Jazyk aplikace |
| 2 | Typ instalace (informativní) |
| 3 | Údaje firmy — IČO automaticky vyplní zbytek z ARES |
| 4 | Logo a vzhled |
| 5 | Aktivace modulů (HACCP, výrobní plán, atd.) |
| 6 | Demo data (volitelné — naseed 15 výrobků, 5 odběratelů) |
| 7 | Quick start checklist |
| 8 | Dokončení |

### Po onboardingu

Doporučené další kroky:

1. **Nastavení SMTP** — `Nastavení → E-mail` (pro odesílání faktur)
2. **Bankovní účet** — `Nastavení → Firma → IBAN` (pro QR platby)
3. **První výrobky** — `Výrobky → ➕ Nový výrobek` nebo import z XLSX
4. **První odběratel** — `Odběratelé → ➕ Nový` (IČO → ARES auto-fill)
5. **Test objednávky** — kompletní průchod od objednávky po fakturu

---

## Co dělat když něco nefunguje

### "Server error" při loginu

**Příčina:** Neúplná instalace, chybí tabulka v DB.

**Řešení:** Otevřete `https://vase-domena.cz/repair-schema.php` a klikněte "Opravit schéma". Po opravě **smažte** `repair-schema.php` z FTP.

### "Database connection failed"

**Příčina:** Nesprávné DB credentials.

**Řešení:**

1. Zkontrolujte `api/config.local.php`
2. `DB host` musí být `localhost` (ne IP)
3. `DB user` a `DB name` mají prefix hostingu (např. `u123456_xxx`)

### "403 Forbidden" na hlavní stránce

**Příčina:** Chybí `index.html` v rootu nebo neplatný `.htaccess`.

**Řešení:**

1. Ověřte, že `public_html/index.html` existuje
2. Nahraďte `.htaccess` šablonou z `deploy/htaccess-templates/sales.htaccess`

### Subdoména hlásí 404

**Příčina:** DNS propagace nebo neaktivní SSL.

**Řešení:**

1. Počkejte 30 minut na DNS propagaci
2. Otestujte: `ping admin.vase-domena.cz`
3. Aktivujte SSL manuálně v cPanel/hPanel

### Onboarding wizard nestartuje

**Příčina:** Admin už onboarding dokončil nebo cookies blokované.

**Řešení:**

1. Vymažte cookies pro `vase-domena.cz`
2. Otevřete `Nastavení → Onboarding → Restart wizard`

---

## Související dokumenty

- [README.md](README.md) — Přehled produktu
- [HOSTING_SETUP.md](deploy/docs/HOSTING_SETUP.md) — Detailní nasazení
- [SECURITY.md](deploy/docs/SECURITY.md) — Bezpečnostní průvodce

---

**Kontakt:** support@appek.cz
**Aktualizováno:** 2026-05-17
