# 🗺️ APPEK — mapa codebase

> Navigační mapa pro rychlý pohyb v kódu. Generováno 2026-08-15.
> Rozsah: api 207 souborů / ~54k řádků · admin JS 138 / ~45k · vendor 33 · pos/b2b/mobile.
> Doplňuje `EKOSYSTEM.md` (velký obraz) a `CSS-MAP.md` (styly).

## 1. Velký obraz — 5 aplikací, jeden repozitář

| App | Kde | Co to je | Kdo to používá |
|-----|-----|----------|----------------|
| **Sales landing** | `index.html`, `checkout.html`, root HTML | prodejní web appek.cz + e-shop licencí | návštěvník / zákazník kupující |
| **Admin (SPA)** | `admin/` | hlavní aplikace provozu (objednávky, výroba, faktury, sklad, HACCP…) | zákazník-provozovatel |
| **POS** | `pos/` (standalone) + `admin/pos.php` (in-admin) | pokladna | obsluha (PIN) |
| **B2B portál** | `b2b/` | objednávkový portál pro odběratele | odběratel zákazníka |
| **Vendor backoffice** | `vendor/` | licence, e-shop licencí, self-update, GoPay/Stripe | TY (dodavatel) |
| **Mobile** | `mobile/` | Capacitor wrapper (iOS/Android) | — (odloženo) |

Backend = **PHP + MySQL**, sdílené `api/`. Frontend = vanilla JS (žádný build framework, jen konkatenace).

---

## 2. `api/` — backend

### 2a. Knihovny (`_*.php`) — engine vrstva (require, ne HTTP)
| Lib | Zodpovědnost |
|-----|--------------|
| `_admin_auth.php` | `require_admin()` + role + **POS allowlist** (pos_only PIN smí jen vyjmenované endpointy) |
| `_authz.php` | jemnější oprávnění dle role |
| `_csrf.php` | CSRF tokeny (POST/PUT/DELETE) |
| `_license.php` / `_license_enforce.php` | generování/validace klíče (checksum+balíčky), enforcement (dev-host výjimka) |
| `_features.php` / `_packages_lib.php` | gating balíčků (bitmaska v klíči, `package_enabled()`) |
| `_bom_lib.php` | **BOM engine** — víceúrovňový recept (`bom_explode`, cost/allergen rollup) |
| `_sklad_lib.php` | sklad systém A vs B |
| `_printer_lib.php` | ESC/POS tiskárny + `printer_dispatch_order` (auto-split bonů) + `printer_print_receipt` |
| `_platby_lib.php` | příplatky platby/doprava (řádky objednávky) |
| `_shipping_lib.php` | přepravci (Zásilkovna/DPD/PPL/ČP) |
| `_smtp_lib.php` | `appek_mail_raw()` drop-in mail() s SMTP fallbackem |
| `_seasonal_lib.php` | sezónní ceny/filtry katalogu |
| `_catering_lib.php` | catering kalkulačka helpery |
| `_gdpr_lib.php` | GDPR souhlasy/práva subjektu |
| `_recurring_lib.php` | opakující se objednávky |
| `_webhooks.php` | odchozí webhooky (fail-closed) |
| `_upload_lib.php` | GD upload obrázků (sdílený) |
| `_full_schema.php` / `_schema_lib.php` / `_migration_lib.php` / `_sync_schema.php` | schema (⚠️ neúplné, runtime migrace — viz gotchy) |
| `_i18n.php` / `_menu_render.php` / `_menu_styly.php` | i18n + veřejné menu render |
| `_pohoda.php` / `_flexibee.php` | účetní exporty |
| `_pwa_lib.php` / `_push_lib.php` | PWA + push notifikace |
| `_update_sign.php` | RSA-2048 podpis self-update balíčků |
| `_customer_integrace.php` | **zákazníkova** integrace přepravců/plateb (klíče `int_*` v `nastaveni`) |

### 2b. Endpointy (vzory)
- **POS:** `admin_pos.php` (create/uzávěrka/vratky), `admin_pos_presets.php`, `admin_pos_print.php`, `pos_auth.php`, `pos_qr.php`, `pay_qr.php`
- **Objednávky/výroba:** `admin_objednavky.php`, `admin_vyroba*.php`, `admin_prep_times.php`, `admin_kitchen.php`
- **Doklady:** `admin_faktury.php`, `admin_dodaci_listy.php`, `admin_faktura_z_dl.php`, `admin_export_isdoc.php`, `dodaci_list.php`, `faktura.php`
- **Sklad/suroviny:** `admin_suroviny*.php`, `admin_sklad*.php`
- **HACCP:** `admin_haccp_*.php`
- **Balíčky:** `admin_cake_*.php` (cukrárna), `admin_catering_*.php`, `admin_seasonal.php`, `admin_tables.php`
- **Licence/prodej:** `license_*.php`, `gopay_*.php`, `stripe_*.php`, `shop_*.php`
- **Systém:** `admin_notifications.php`, `admin_health_monitor.php`, `admin_zalohy.php`, `admin_integrity.php`, `admin_stanice.php`, `whoami.php`, `config.php`

---

## 3. `admin/` — hlavní SPA

**⚠️ `admin/admin.js` je GENEROVANÝ** z `admin/src/*.js` přes `cat admin/src/*.js > admin/admin.js` (build-update.sh:45). **Edituj `admin/src/`, ne admin.js** (přepíše se). Načítá `admin/index.html` (`<script src="admin.js?v=…">`).

Číselné bandy `admin/src/NNNN-slug.js` (řadí se dle čísla):

| Band | Oblast |
|------|--------|
| **0000–0250** | Infra: preamble, zachytávání JS chyb, helpers, toast, inline-edit, print-queue, shortcuts, charts, branding, webhooks, activity-log, notifications, **license-heartbeat (0120)**, **stanice-heartbeat (0126)**, sparklines, demo-seeder, confirm dialog, skeletons, command palette, **api-helper (0210)**, debounce, pin-sidebar, fullscreen, login |
| **0260–0290** | Práva v menu (0260), onboarding wizard (0270/0280), dashboard (0290) |
| **0300–0420** | Objednávky (0300), nová objednávka (0310), výrobní list (0320), spotřeba surovin (0330), dodací listy (0340), rozvozy (0350), opakující (0360), sales report (0370), DL export (0380), faktury export (0400), ISDOC import (0410), odeslat doklad e-mailem (0420) |
| **0430–0510** | Výrobky (0430), kalkulace (0450), logo upload (0460), odběratelé (0470), pobočky (0480), **nastavení (0490)**, GDPR (0495), **tiskárny (0500)**, payment methods (0510) |
| **0520–0585** | Customer integrace (0520), balíčky (0530), cukrárna hub (0550), catering kalkulátor (0560), restaurace (0570/0580), týdenní menu (0585) |
| **0590–0730** | **KDS (0590)**, otevřené účty (0600), QR pending (0610), floor plan (0620), pos účty (0650), platba (0660), split/merge/move (0670–0690), pos launcher (0730) |
| **0740–0850** | Balíčkové featury: kapacita kuchyně (0740), doba přípravy (0750), kurýři (0760), šaržová HACCP (0770), mix&match (0780), catering firemní (0800), cenové úrovně (0820), smlouvy (0830), zálohy 50 % (0840), sezónní katalog (0850) |
| **0860–0990** | API tokeny (0860), diagnostika (0870/0890), zálohy (0880), sazby DPH (0900), push (0910), email templates (0920), uživatelé (0930), práva editor (0940), slevové skupiny (0950), ruční faktura/DL (0970/0990), odběratel picker (0980) |
| **1000–1210** | **Nástroje hub + kategorie + Stanice (1000)**, PDF nabídka (1010), suroviny (1020), sklad (1040), export/import surovin (1050–1080), složení (1090), hmotnost těsta (1100), import ceníku/výrobků/odběratelů (1110–1130), štítky (1140), editor šablon (1150–1200), přehled výroby (1210) |
| **1220–1340** | Bootstrap (1220), HACCP (1230–1290), výrobní kalkulace (1300–1330), b2b picker (1340) |

Navigace: `navigate('<page>')` switch v `admin/src/0280`. Menu/práva v `0260`. **`admin/pos.php` + `admin/pos.js`** = samostatná in-admin kasa (jiný soubor než `pos/pos.js`, edituj OBOJÍ).

---

## 4. `pos/`, `b2b/`, `vendor/`, root

- **`pos/`** — standalone POS PWA: `index.php` (CFG `window.POS_CONFIG`), `pos.js` (IIFE, vlastní `api()`), `sw.js`/`manifest`. Cíl QR „Kasa".
- **`b2b/`** — B2B portál PWA: `app.js`, `index.html`, `sw.js`. Odběratel-scoped.
- **`vendor/`** — licenční backoffice: `_lib.php` (db+auth), `_layout.php` (topbar/nav), `settings.php`, `faktury.php`, `licenses.php`, `packages.php`, `shop.php`, `heartbeat.php` (licence enforcement), `self-update.php`, `_gopay.php`/`_stripe.php`, `_dpd.php`/`_zasilkovna.php` (osiřelé po úklidu). ⚠️ vendor má **vlastní kopii** `_license.php` (identická s api/).
- **Root vstupy:** `index.html`/`index.php` (landing), `install.php` (instalátor, 3jazyčný), `checkout.html` (nákup licence), `payment-done.html`, `download.php` (one-time stažení), `theme.css.php` (loader témat), `obchodni-podminky.html`/`zasady-ochrany-soukromi.html`.

---

## 5. Klíčové datové toky (chokepointy)

- **Ceník:** `cenik_pro_odberatele` — jediné místo pro cenu produktu pro odběratele (sezónní úpravy, slevy skupin sem zapuštěné).
- **BOM:** `vyrobek_suroviny.slozka_vyrobek_id` → `_bom_lib.php` (řádek = surovina NEBO polotovar).
- **Kanály:** `objednavky.puvod` = centrální registr (POS=`'pos'`, e-shop, B2B…). POS reporty čtou `pos_pokladni_sql()`.
- **Pokladny:** `objednavky.pokladna` (per stanice) — filtr uzávěrky/tržeb per kasa. `puvod='pos'` beze změny.
- **Uzávěrka:** `pos_uzaverka_data()` (seskupení per obsluha `pos_uzivatel`, filtr per pokladna), snapshot `pos_uzaverky`.
- **Licence:** klíč = checksum + balíčky (offline). Enforcement = heartbeat→vendor, fingerprint binding.
- **Integrita provozu:** `admin_integrity.php?action=audit` (detektor slepých míst).

---

## 6. Build / deploy

- **Deploy trigger = GIT TAG** `vX.Y.Z` (`release.yml` on:push:tags). `git push` do main NEdeployuje!
- `scripts/build-update.sh`: bumpne `APPEK_ADMIN_JS_VERSION` v `0000-preamble.js`, `cat src/*.js > admin.js`, minifikuje, zabalí update zip.
- `scripts/admin_modularize.py` — recovery: rozseká admin.js zpět na src/.
- **Testy:** `bash scripts/smoke.sh` (endpointy/auth), `php scripts/test-money-paths.php` (~513 asserts, peníze). Pouštěj před deployem.
- Lokál: XAMPP (`localhost/appek`, DB `appek`, admin@admin.cz/demo1234) — **oddělená kopie od git projektu**, sync ručně.

---

## 7. Známé landminy (pozor při editaci)

1. **Dvě kopie `_license.php`** (api/ + vendor/) se stejnými globálními fcemi — nikdy nenačítat obě v jednom requestu (redeclare; PHP hoisting obchází `function_exists` guard).
2. **Tělo `quick_order` je v `$data`, ne `$d`** (admin_pos.php). Jiné handlery používají `$d`.
3. **MariaDB PDO NEdovolí reuse pojmenovaného paramu** (`:v` 2× → HY093) — použij `:v1`/`:v2`.
4. **Fresh-install schema neúplné** — endpoint co použije sloupec dřív než jeho runtime migrace = 500 na čisté instalaci. Nové sloupce migruj lazily (SHOW COLUMNS + ALTER) na začátku endpointu.
5. **admin.js/admin.css generované** — edituj `src/`.
6. **`admin/pos.js` ≠ `pos/pos.js`** — dvě kopie POS jádra, edituj obojí.
7. **Landing-only apex** — `api/.htaccess` whitelist (ř.14): nový veřejný endpoint na apexu appek.cz musí být povolen, jinak 302 na landing.
8. **HTTP cache admin.js na lokálu** — SW clear nestačí, bumpni `?v=` nebo `?cb=` na URL stránky.

---

## 8. Nálezy scanu (2026-08-15)

Automatický scan napříč všemi soubory: **codebase je čistý.**

| Kontrola | Výsledek |
|---|---|
| Duplicitní PHP funkce (redeclare) | 0 |
| Mrtvé prokliky (onclick → nedefinovaná fce) | 0 |
| Nereferencované admin endpointy | 0 |
| Reálné TODO/FIXME/HACK/BUG | 0 (jen doc placeholdery) |

**Jediná drobná mezera:** `admin/src/0870-diagnostika…js:449` volá `api('admin_me.php')` — endpoint **neexistuje**; volání má `.catch(()=>({}))`, takže tiše selže a ta část diagnostiky je vždy prázdná. Fix: přesměrovat na `whoami.php` (existuje) nebo vytvořit `admin_me.php`. Nekritické (bez pádu).
