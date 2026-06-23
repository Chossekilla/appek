# APPEK — popis ekosystému

> Stav: **v3.0.388**. Architektonický přehled celého systému: kdo je kdo, jak spolu části mluví, kudy tečou data a aktualizace.

APPEK je **self-hosted** systém pro gastro/pekárenský provoz. Pravidlo nasazení: **1 instalace = 1 zákazník = vlastní databáze**. Cross-tenant únik mezi různými kupci nehrozí (každý běží na svém hostingu, své DB).

---

## 1. Tři tváře systému

| Doména | Co to je | DB | Kdo to spravuje |
|---|---|---|---|
| **appek.cz** | Sales web + checkout + **distribuce updatů** | vendor DB (`appek`) | dodavatel (ty) |
| **vendor.appek.cz** | **Master panel** — licence, balíčky, updaty, anti-piracy, demo log, přístupy | tatáž vendor DB | dodavatel (ty) |
| **demo.appek.cz** | Veřejné marketingové demo (auto-login, reset) | `appek_demo` | auto (CI) |
| **zákaznická instalace** | `admin/` + `b2b/` + `pos/` + `api/` na hostingu zákazníka | per-instalace (vlastní) | zákazník |

> `appek.cz` a `vendor.appek.cz` jsou **tatáž instalace** (subdoména sdílí kód + DB + storage). Kanonická vendor URL je `https://vendor.appek.cz/`.

---

## 2. Vendor = master všeho

Vendor panel (`vendor/`) řídí **veškeré přístupy a licence** celého ekosystému:

- **Licence** (`vendor/licenses.php`, `api.php`) — generování, revoke, reissue, **unlock** (anti-piracy), expirace
- **Balíčky** (`vendor/packages.php`) — roční licence gatované **bitmaskem** v licenčním klíči (Cukrárna, Lahůdky/Catering, Restaurace, Sezónní…)
- **Aktualizace** (`vendor/updates.php`, `vendor/self-update.php`) — OTA distribuce do zákaznických instalací
- **Anti-piracy** (`vendor/pirate.php`, `heartbeat.php`) — detekce instalací bez platné licence (fingerprint binding)
- **Demo log** (`vendor/demo-log.php`, `demo-track.php`) — kdo zkoušel demo
- **Přístupy** (`vendor/access.php`) — přehled admin/B2B/vendor účtů napříč instalacemi
- **Eshop** (`vendor/shop.php`) — objednávky z checkoutu → generování licencí

Vendor DB se připojuje přes `vendor_db()` (`vendor/_lib.php`), konstanty `VENDOR_DB_*` z `vendor/config.local.php`.

---

## 3. Zákaznická instalace

Každý zákazník hostuje na svém serveru:

```
admin/      Administrace (admin.js — GENEROVANÝ z admin/src/*.js, 135 souborů)
b2b/        B2B portál pro odběratele (app.js)
pos/        Pokladna (standalone POS)
api/        PHP backend (endpointy, licenční vrstva, business logika)
install.php Prvotní instalace (DB + config.local.php)
```

- **Databáze** je per-instalace (vlastní `api/config.local.php`).
- **Licence** je v `api/config.local.php` (`APP_LICENSE_KEY`) — offline ověřitelná (HMAC).
- Pro **kontrolu updatů** zákazník volá `appek.cz` endpointy přes HTTPS (viz §5).

---

## 4. Licenční model + anti-piracy

```
Licenční klíč = APPEK-XXXX-…  (offline ověřitelný, BEZ data expirace v klíči)
  ├── checksum: HMAC-SHA256(payload, LICENSE_SALT)   → api/_license.php
  └── balíčky:  bitmask zapuštěný v klíči            → license_has_package()
```

- **Expirace** se NEřeší v klíči, ale přes **heartbeat** (`vendor/heartbeat.php`): instalace se denně hlásí vendoru, ten vrací `expires_at`. Po grace 14 dní se vypnou **jen balíčky** (core jede dál).
- **Anti-piracy:** heartbeat posílá **install fingerprint** (HMAC unikátní per instalace). 3× jiný fingerprint na stejný klíč → **lock** (lock_state). Lock vypne celý admin (HTTP 423). Vendor lock **vidí i odemyká** (`unlock` akce + 🔓 UI).
- Stavy licence: `active` / `grace` / `locked` / `revoked` / `expired`.

> Detail: viz `docs/` paměť `appek-license-expirace`, `appek-vendor-audit-2026-06`.

---

## 5. Distribuce aktualizací (OTA)

Nejdůležitější tok ekosystému. **Bundle formát:** `manifest.json` (verze + `files: {cesta: sha256}`) + `files/` (zrcadlí instalaci).

### Publikace (vendor strana)
```
git push main
  → CI postaví MASTER bundle (build-update.sh: version sync, admin.js concat, lint, bundle)
  → CI deploy-hook → self_update_apply() na vendor.appek.cz
  → self_update_build_customer_zip(): zkontroluje integritu, spočítá SHA-256,
    🔐 PODEPÍŠE manifest.json (RSA-2048) → uloží do vendor_updates (status=published)
  → demo.appek.cz se auto-updatuje, zákazníci to uvidí přes check endpoint
```
Manuální cesta: `vendor/updates.php` upload (draft) → „Publikovat".

### Stažení + aplikace (zákazník)
```
admin.js (Nastavení → Aktualizace)
  → api/admin_version_check.php (lokální)
      → curl https://appek.cz/api/updates_check.php  (čte vendor_updates)
      ← { latest: { version, checksum, 🔐 signature, download_url } }
  → runSelfUpdate() → POST api/updates_apply.php (LOKÁLNÍ — musí psát soubory)
      1. stáhne bundle z appek.cz/api/updates_download.php (license-gated, rate-limit)
      2. ověří SHA-256 (integrita)
      3. zip-slip guard → extract do staging/
      4. 🔐 ověří PODPIS manifestu (fail-closed) — viz docs/NAVOD-update-podpis-a-csrf.md
      5. per-file SHA-256 (každý soubor sedí na manifest)
      6. backup → copy do live → opcache invalidate → post-verifikace
```

### Recovery cesty (když je hlavní flow rozbitý)
- `admin/force-update.php` — standalone, nainstalovaný u zákazníka; čte `updates/manifest.php`, **taky ověřuje podpis**.
- `updates/installer.php` — bootstrap nahraný přes FTP (stahuje se čerstvě z appek.cz; **bez podpisu** — je sám root of trust).

---

## 6. Bezpečnostní vrstvy

| Vrstva | Kde | Proti čemu |
|---|---|---|
| **Admin session auth** | `require_admin()`, `_admin_auth.php` | neautentizovaný přístup; POS-PIN má allowlist |
| **Licenční gating** | `license_valid()`, `_license.php` | provoz bez licence |
| **CSRF token** (vendor) | `vendor_csrf_check()`, `_lib.php` | cross-site POST na master panel |
| **RSA podpis updatu** | `_update_sign.php` | podvržený/upravený bundle (supply-chain) |
| **Zip-slip guard** | `updates_apply.php`, `_self_update.php` | path traversal při extrakci |
| **Rate-limit** | download/demo/resolve endpointy | brute-force, piracy probing |
| **SameSite=Lax + HttpOnly** | session cookies | CSRF (hlavní vektor), XSS krádež cookie |

---

## 7. Datové oddělení

```
VENDOR DB (appek)              CUSTOMER DB (per-instalace)
├── vendor_users              ├── admin_users
├── vendor_licenses           ├── odberatele (B2B)
├── vendor_packages           ├── objednavky / faktury / sklad …
├── vendor_updates 🔐         └── nastaveni (vč. license cache)
├── vendor_pirate_installs
├── vendor_shop_orders
└── demo_pristupy
```

Zákaznická instalace **nemá** přístup k vendor DB napřímo — update-check běží přes HTTPS endpointy na `appek.cz`, které vendor DB čtou na straně vendoru.

---

## 8. Build & deploy pipeline

```
lokál: edit (admin/src/, api/, vendor/…)
  → scripts/build-update.sh X.Y.Z
      • sync verze: config.php, admin.js (concat z src!), admin.css, sw.js, index.html ?v=
      • php -l gate (api/ + admin/)  +  node --check gate (admin/ + b2b/ JS)
      • bundle appek-update-X.Y.Z.zip (manifest + SHA-256)
  → git commit + push main
  → CI auto-publish (~2 min settling: mirror přebalí ZIP → jiný checksum než build)
  → demo auto-update + zákazníci OTA
```

> ⚠️ `admin/admin.js` je **generovaný** — edituj `admin/src/<NNNN-sekce>.js`, build ho přepíše concatem.
> ⚠️ Mirror **přebaluje** bundle (jiné byty kontejneru) → proto se podepisuje **obsah manifest.json**, ne byty ZIPu.

---

## Související dokumenty
- `docs/NAVOD-update-podpis-a-csrf.md` — provozní návod k podpisu + CSRF
- `docs/PRE-SALE-CHECKLIST.md` — předprodejní bezpečnostní stav
- `docs/PROVOZNI-PRIRUCKA.md` — provozní příručka
- `SYNC_ARCHITECTURE.md` — synchronizační vrstva
