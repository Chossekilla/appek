# Bezpečnostní audit — Appek B2B 2.5.5

Cíl: souborový sken `appekv2.5.5.zip` (PHP 8 + MySQL, ~161 PHP souborů). Nálezy jsou seřazené podle závažnosti. Cesty jsou relativní k rootu balíčku.

---

## 🔴 KRITICKÉ (vzdálené převzetí systému)

### C1. Neautentizovaný RCE přes `/api/updates_apply.php`
**Soubor:** `api/updates_apply.php:1–243`
Endpoint je gated jen `license_valid()` — žádný `require_admin()`, žádná session.
- Stáhne `download_url` (libovolná HTTPS adresa) → `extractTo($stagingDir)` → bez integrity kontroly (větev „Raw customer ZIP", řádky 129–133, 161–176).
- Pre-flight chce jen `admin/admin.js, admin/index.html, admin/sw.js, api/config.php` — útočník je triviálně přibalí.
- Pak `copy($src, $dst)` přes celý `$fileList` do webroot (řádky 224–243). Žádná validace cest dovnitř webrootu mimo prefix.
Komentář v souboru sám přiznává (řádky 22–23): „vyžaduje pro plnou bezpečnost ADMIN auth check. Pro MVP je license-only".

### C2. License HMAC salt je v customer buildu — klíče lze libovolně razit
**Soubor:** `api/_license.php:17`
```php
const LICENSE_SALT = 'appek-b2b-2026-MmKp9XzVqL';
```
Kdokoliv s ZIPem (i potenciální zákazník, který chce vyzkoušet beta) vygeneruje libovolný platný `APPEK-XXXX-...` klíč pomocí `license_generate_with_packages()` ve stejném souboru. To zneplatňuje licenční model, ale především otvírá branku pro C1 (RCE bez session).

### C3. Neautentizovaný `/admin/force-update.php?action=run`
**Soubor:** `admin/force-update.php:91`
```php
if (($_REQUEST['action'] ?? '') === 'run') {
    @set_time_limit(0); …
```
**Žádný** `require_admin()` přes celý soubor (ověřeno `grep`). V `admin/` chybí `.htaccess` (ověřeno `find … -name .htaccess`), tedy endpoint je dostupný anonymně z internetu. Stáhne `manifest.json` z `appek.cz` a aplikuje bundle. SHA-256 kontrola je **podmíněná** (řádky 121–129 — „skip SHA verify" když manifest neobsahuje checksum). Útočník, který DNS-spoofuje / MITMuje vendor server (nebo když vendor server samotný má sebemenší kompromis), získá RCE na všech instalacích, které někdo na webu pingne `?action=run`.

### C4. Hardcoded preauth "check key" `appekSet2026`
**Soubor:** `api/check_install.php:16`
```php
if (($_GET['key'] ?? '') !== 'appekSet2026') { … die(); }
```
Identický klíč v každém customer buildu (= public). Pak (řádky 12–14) `display_errors=1` a vypisuje:
- existenci kritických souborů,
- count `admin_users`, `odberatele`, `vyrobky`, `objednavky`,
- migrace, schéma, MySQL verze.
Komentář říká „Po úspěšné kontrole soubor SMAŽTE z hostingu" — ale ZIP ho dodává a `install.php` ho nemaže.

### C5. Installer `?force=1` bypass + tichá změna super-admin hesla
**Soubor:** `install.php:156–161`
```php
if (file_exists(__DIR__ . '/api/.installed') && $step !== 99) {
    if (!isset($_GET['force'])) { header('Location: admin/'); exit; }
}
```
`?force=1` přeskočí lock a vede dál.
**Soubor:** `install.php:528–534`
```sql
INSERT INTO admin_users (email, jmeno, heslo_hash, role, aktivni)
VALUES (:e, :j, :h, 'admin', 1)
ON DUPLICATE KEY UPDATE heslo_hash = :h2, role = 'admin', aktivni = 1
```
Tichý reset hesla pro existující super-admin email. Step 3 je gated jen `$_SESSION['install_db_ok']`, který se nastaví ve step 2 zadáním DB creds (které najde každý v `api/config.local.php` přes session-fixation či přečtením přes RCE). Installer se v ZIPu dodává — pokud zákazník po instalaci nesmaže `install.php`, je celá instance plně převzatelná z internetu.

### C6. Hardcoded produkční DB heslo v `api/config.php`
**Soubor:** `api/config.php:24–32`
```php
if (!defined('DB_HOST')) define('DB_HOST',     'localhost');
if (!defined('DB_NAME')) define('DB_NAME',     'u880385154_pekarna');
if (!defined('DB_USER')) define('DB_USER',     'u880385154_appek');
if (!defined('DB_PASS')) define('DB_PASS',     'Karkulka55+');
…
if (!defined('APP_URL')) define('APP_URL', 'https://white-badger-130749.hostingersite.com');
```
Plaintext heslo & Hostinger username v ZIPu. Komentář (řádky 11–13) sice doporučuje rotovat, ale heslo je vystavené **každému, kdo má ZIP** — a Hostinger user/DB identifikátor jednoznačně identifikuje konkrétní hosting. CORS `Access-Control-Allow-Origin: APP_URL` (config.php:404) defaultně směřuje na ten Hostinger preview, takže out-of-box nesedí customerovi a vede k tichému selhání cross-origin requestů (a u zákazníka, který náhodou používá tu adresu, povolí tomu jejich session).

---

## 🟠 VYSOKÉ

### H1. `/admin/verify-version.php` — neautentizované informace o souborech
**Soubor:** `admin/verify-version.php:1–62`
Žádná auth kontrola. Vrací JSON (`?format=json`) se `APP_VERSION`, `mtime`, `size`, version markery `admin.js / config.php / sw.js / updates_apply.php`. Pomocný recon pro útočníka před spuštěním C1/C3.

### H2. `/api/diag.php` — auth gate je triviálně obejdná
**Soubor:** `api/diag.php:17–29`
```php
$isLocal = in_array($ip, ['127.0.0.1', '::1', $_SERVER['SERVER_ADDR'] ?? ''], true);
…
if (!$isDemoMode && !$isLocal) { … }
```
- `SERVER_ADDR` je IP serveru samotného. Jakákoliv SSRF (např. přes `updates_apply.php:73 file_get_contents($fullUrl)`) projde gate.
- Pokud kdokoli někdy definoval `APPEK_DEMO_MODE = true` (typicky před spuštěním na produkci), endpoint odhaluje `DB_HOST/DB_NAME/DB_USER` plně a `DB_PASS` jako `Ka•••••••5+` (řádky 75–78 + funkce `maskPass`). Také vypisuje plný `APP_LICENSE_KEY` (řádek 80).

### H3. Žádné CSRF tokeny v admin API
`grep -rn "csrf\|X-CSRF" /home/user/appek/api/` vrací jen komentář ve `faktura.php:9`. Obrana se opírá výhradně o `SameSite=Lax` (`config.php:120`), což je _částečně_ dostatečné pro POST z cross-site formulářů u moderních prohlížečů, ale:
- `GET`-based state-change patterns (`?action=delete`, atd.) v Lax-default cookies stále fungují.
- Žádný defense-in-depth na úrovni endpointu.

### H4. Žádná 2FA i přes claim v README
**Soubor:** `api/admin_login.php:1–50`
Login je pouze email + heslo + rate-limit. Žádné `totp_code`, žádný druhý krok. README („Bezpečnost: Argon2id, HMAC SHA-256, 2FA TOTP, CSRF tokens") je nepravdivý (Argon2id navíc neplatí — heslo je `password_hash($heslo, PASSWORD_BCRYPT)` v `install.php:533`).

### H5. License email-verify / vendor-online check bypass
**Soubor:** `install.php:262–264, 332–334`
Když je `vendor.appek.cz` nedostupný (nebo zablokovaný útočníkem přes DNS / firewall), `install.php` pokračuje s warningem („vendor offline"). Email TOFU binding a online registrace licence jsou tedy přeskočitelné.

### H6. Backup helper — DB dump pod `api/zalohy/`
**Soubor:** `api/_zaloha_helper.php` (a `api/updates_apply.php:203`)
Zálohy se ukládají do `api/zalohy/`. `.htaccess` na rootu blokuje `api/zalohy/.*` přes `mod_rewrite` (řádek 53). Ale:
- Na nginx hostech (které ZIP zmiňuje jako podporované — viz `nginx.conf.example`) `.htaccess` neplatí.
- Na Apache bez `mod_rewrite` (kde se má dle README také rozjet) pravidlo se neuplatní.
Výsledek: kompletní MySQL dump (`zaloha_*.zip`) může být dostupný anonymně.

---

## 🟡 STŘEDNÍ

### M1. HTML injection v notifikačních e-mailech
**Soubor:** `api/config.php:843–854` (`email_template_render`)
```php
foreach ($vars as $k => $v) {
    $text = str_replace('{' . $k . '}', (string) $v, $text);
}
```
Žádný `htmlspecialchars`. Když je šablona ve formátu HTML (`format='html'`, řádky 939, 1019), proměnné `{poznamka}` / `{misto}` / `{odberatel}` / názvy položek pocházejí z B2B portálu (POST od libovolného odběratele přes `api/objednavky.php:222`). Útočný odběratel může vložit `<a href="https://phish/">` / `<img src=tracker>` / `<style>` do e-mailu, který přijde adminovi pekárny.

### M2. Fixní timezone `+01:00` v MySQL
**Soubor:** `api/config.php:62`
```php
$pdo->exec("SET time_zone = '+01:00'");
```
Komentář tvrdí „CET; v létě se připočítá DST přes PHP". Není to pravda — fixní offset DST nepřipočte. V létě budou všechny `NOW()/CURDATE()` zápisy o hodinu pozadu oproti PHP `date('…')` rendrům. Faktury, dodací listy, uzávěrky objednávek (`objednavka_editovatelna`, řádky 461–498) běží na nekonzistentním čase.

### M3. `api/admin_diagnostika.php` čte error log s případnými PDO výpisy obsahujícími heslo
Volá `require_admin()` (jakákoliv role, ne super-admin). Tail error logu na malém hostingovém kontu typicky obsahuje PDO výjimky včetně připojovacích řetězců / hesel po prvním selhání. Skinní (`prodavac`) admin tak může extrahovat DB credentials.

### M4. Nelimitované množství v objednávce
**Soubor:** `api/objednavky.php:152`
Odmítne `$mn <= 0`, ale nepřijme horní hranici. Násobení `cena * mnozstvi` u DECIMAL(10,2) sloupců způsobí přetečení / chybu transakce nebo schované přijetí absurdní částky.

### M5. SQL stringová interpolace všude (zatím neexploitable)
**Soubory:**
- `api/admin_activity_log.php:25,45,67,87` — `LIMIT $limit` (po `(int)` castu — bezpečné)
- `api/admin_suroviny.php:333`, `api/admin_objednavky.php:240`, `api/objednavky.php:85`, `api/admin_klient_chyby.php:98`, `api/admin_cenove_skupiny.php:237` — všechny po číselném castu / hardcoded řetězci.
Žádný z nich není dnes exploitable, ale jeden přehlédnutý cast a vznikne SQLi. **Doporučení:** přejít na placeholdery i pro `LIMIT/ORDER BY` (PDO whitelist) — toto je systémový problém kódování stylu, ne jednotlivá chyba.

### M6. `update-manifest.json` se v `updates_apply.php` přepisuje proměnnou `$manifestPath`
**Soubor:** `api/updates_apply.php:113` (staging manifest) vs řádek `293` (živý manifest)
Stejná proměnná `$manifestPath` se používá pro dva různé soubory — kosmetické, ale matoucí. Nemá funkční dopad.

### M7. Auto-migrace na každém requestu
**Soubor:** `api/config.php:64–77`
Při každém vytvoření PDO se volá `ensure_sync_schema()` + `apply_full_schema()` — to dělá `SHOW COLUMNS`, ALTER TABLE, atd. Mimo overhead to znamená:
- Aplikační DB user musí mít DDL grant (větší dopad při kompromitaci).
- Při schema-mismatch dostane každý request neviditelnou ALTER, která může selhat tiše.

---

## 🟢 NÍZKÉ / hygiena

- **L1.** `install.php:21` volá `session_start()` přímo místo `session_secure_start()`. Cookie pro install session není `Secure`/`SameSite`. Licenční klíč a email tečou v session.
- **L2.** `admin_login.php:32` rozlišuje „špatné heslo" 401 vs „deaktivovaný účet" 403 — drobný user-enumeration oracle.
- **L3.** `api/cron_recurring.php:27` porovnává cron token přes `!==` (non-constant-time).
- **L4.** CSP v `.htaccess:88` má `script-src 'self' 'unsafe-inline' 'unsafe-eval'` — slabá obrana proti XSS na úrovni HTTP.
- **L5.** `.user.ini:25` má `session.cookie_secure = 0`. `session_secure_start` v PHP přebije, ale na endpointech, které volají `session_start()` přímo (např. `install.php`, několik vendor scriptů), zůstane nezabezpečené.
- **L6.** `Access-Control-Allow-Origin: APP_URL` (config.php:404) — APP_URL je out-of-box hostinger preview doména. CORS preflighty se zákazníkům nepovolí, dokud `config.local.php` nepřepíše.
- **L7.** `payment_refund.php` má header `Access-Control-Allow-Origin: *` (řádek viz soubor) — refund endpoint by neměl být cross-origin čitelný.
- **L8.** Robotí blokace v `.htaccess` blokuje `curl` a `wget` user-agenty řádkem 158 (zakomentováno) — pozor, lze odkomentovat a tím rozbít vlastní cron/monitoring.

---

## Doporučené pořadí oprav

1. **Smazat z customer ZIPu:** `check_install.php`, `diag.php`, `verify-version.php`. Žádný z nich nepatří do produkce.
2. **Před `updates_apply.php` a `force-update.php` přidat `require_super_admin()`** — license-only není auth.
3. **Otočit / vyjmout** DB heslo z `api/config.php`. Defaultní fallbacky nechat prázdné, instalátor nech vždy napsat `config.local.php`.
4. **Zip-slip + path whitelisting** v `updates_apply.php` — copy jen do `api/, admin/, b2b/, assets/` se `realpath()` ověřením, nikdy přes `..`.
5. **Mazat `install.php`** po dokončení wizardu, nebo aspoň přepsat na 0-byte stub. `?force=1` re-run musí vyžadovat super-admin session.
6. **HTML-escape** proměnných v `email_template_render()` při `format='html'`.
7. **2FA + CSRF tokeny** — pokud README slibuje, pak implementovat. Jinak claim odstranit.
8. **MySQL timezone** — buď `SET time_zone='Europe/Prague'` (vyžaduje načtené tz tabulky) nebo udržovat všechno v UTC + převádět v PHP.
9. **License HMAC** — pokud má být legální ochrana, ne DRM, OK. Pak nepoužívat `license_valid()` jako auth gate (C1).

---

_Audit zpracován oproti staticky rozbalenému ZIPu; běh aplikace netestován. Některé „high" nálezy lze degradovat, pokud nasazení provoz neuzavírá za reverse proxy s vlastní WAF / IP-allow listou. Naopak několik „critical" se zhoršuje, pokud aplikace stojí na nginx (kde `.htaccess` ignoruje)._
