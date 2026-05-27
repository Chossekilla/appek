# 🤖 Claude Project Context — Appek B2B

> **Pokud jsi Claude a tohle čteš poprvé:** Tento soubor ti dá kompletní kontext projektu. Přečti si ho než začneš pracovat. Pak můžeš odpovědět uživateli.

---

# 🔴🔴🔴 QUALITY GATES (do odvolání, 2026-05-27) — PŘEDNOST PŘED VŠEMI OSTATNÍMI PRAVIDLY

User explicitně zapnul tyto gates ("ano, do odvolání"). Platí dokud user explicitně neřekne "vypni to".

## BLOCKING — bez tohoto NIKDY nereportuj "hotovo/deployed/✅"

### Pro každou UI změnu MUSÍŠ:

1. **Claude Preview MCP** → screenshot live URL po deploy:
   ```
   mcp__Claude_Preview__preview_start    (URL: https://demo.appek.cz/admin/ nebo http://localhost/appek/admin/)
   mcp__Claude_Preview__preview_resize   (width × height matching user's screenshot viewport)
   mcp__Claude_Preview__preview_screenshot
   ```

2. **Viewport match** — fotit v stejném viewportu co user poslal:
   - Desktop: **1280×800** (default)
   - Mobile: **390×844** (iPhone 14 Pro)
   - Tablet: **768×1024** (iPad portrait)

3. **Vizuálně porovnat** se screenshot co user poslal jako reference.

4. **Pokud screenshot neukáže fix** → fix to **ZNOVA** než reportovat. Žádné "deployed, zkontroluj" naslepo.

### Reportovat lean:
- ✅ OK: "build vX.Y.Z pushed, screenshot níže ukazuje [konkrétní fix], čekám 'jedem dál'"
- ❌ ZAKÁZÁNO: "hotovo" / "✅ deployed" / "měl bys vidět X" / "teď to bude OK"

## CSS gates

- **Plan agent** (`subagent_type: "Plan"`) když:
  - Change > 50 řádků
  - Shared/global selector (`.sidebar-nav`, `.btn`, `body`, etc.)
  - Opakovaný problém (user už ≥ 2× řekl "neni to ono")

- **Container queries / clamp() > fixní px** — vždy. Floor maps, canvases, modaly = `container-type: inline-size`, ne `width: 720px`.

- **Overflow audit** pro canvas/grid: test při 320/390/768/1024/1280 px viewport.

- **Po commitu** ≥ 50 LOC CSS → `code-modernization:architecture-critic` v background (`run_in_background: true`).

## Verze checklist (každý release)

```
admin/admin.js            const APPEK_ADMIN_JS_VERSION = 'X.Y.Z'
admin/sw.js               const CACHE_VERSION = 'appek-vX.Y.Z'
api/config.php            define('APP_VERSION', 'X.Y.Z')
admin/admin.css           --appek-css-version: "X.Y.Z"
admin/index.html          admin.css?v= + admin.js?v= + i18n.js?v= + i18n_auto.js?v= + i18n_extra.js?v=
b2b/index.html            style.css?v= + i18n.js?v= + app.js?v=
```

Grep verify před commitem: `grep -rn "X\.Y\.(Z-1)" --include="*.{html,js,css,php}" | grep -v build-manifest`

## Anti-pravidla (FATAL)

- 🚫 **NIKDY** nesyncovat `api/config.local.php` (rsync vždy s `--exclude='api/config.local.php'`) — jinak login crash
- 🚫 **NIKDY** psát "hotovo / deployed / ✅" bez user "jedem dál"
- 🚫 **NIKDY** revertovat bez explicitního "vrať to"
- 🚫 **NIKDY** brát `HANDOFF-pin-rail.md` jako úkol — user dělá sám
- 🚫 **NIKDY** měnit pin CSS v admin.css lines 20029-20204 — user dělá sám

## Login (testing)

```
URL:    http://localhost/appek/admin/  (XAMPP)  |  https://demo.appek.cz/admin/  (live)
Email:  demo@appek.cz   nebo   admin@admin.cz
Heslo:  admin123
JSON:   {"email":"demo@appek.cz","heslo":"admin123"}   ← klíč "heslo" ne "password"!
```

---

## 🎯 Co je tento projekt

**Appek B2B** = gastro objednávkový systém ("krabicovka"). PHP + MySQL + vanilla JS, žádný framework.

- **Admin panel** pro pekárnu (objednávky, faktury, výroba, HACCP, cenovky…)
- **B2B portál** pro odběratele (login, košík, historie)
- **REST API v1** pro účetní systémy
- **PWA** — push notifikace, offline funkčnost

Vznikl jako rebrand projektu **Repre B2B** (původně `Reprekv.cz/` na ploše). Příští produkční nasazení = pod značkou Appek.

---

## 📁 Lokace

```
/Users/chossekilaimac/projects/appek.cz/   ← TENTO projekt (vývoj) ✅ NOVÁ CESTA (přesunuto z Desktop)
/Users/chossekilaimac/Desktop/Reprekv.cz/  ← PRODUKCE (NESAHAT — nasazené přes FTP)
```

**KRITICKÉ:** `Reprekv.cz/` je živá produkce. Veškeré změny v `appek.cz/` (~/projects/appek.cz/).

**Pozn.:** Projekt byl přesunut z `~/Desktop/appek.cz/` do `~/projects/appek.cz/` (květen 2026) — kvůli lepší organizaci v rámci IDE a Git workflow.

---

## 🛠️ Tech stack

| Komponenta | Detail |
|------------|--------|
| Backend | PHP 8.0+ (žádný framework — vanilla PDO) |
| Frontend | Vanilla JS (admin.js ~22 000 řádků) + CSS (admin.css ~11 000 řádků) |
| DB | MySQL 5.7+ / MariaDB 10.3+ |
| HTTP | Apache (`.htaccess`) nebo Nginx (`nginx.conf.example`) |
| PWA | Service worker + manifest |
| Build | Žádný — soubory jsou native, jen `build-zip.sh` pro krabicovku |

---

## ✅ Co je hotové (květen 2026)

### Core funkce
- 📋 Objednávky, dodací listy, faktury (ISDOC export)
- 🥖 Výrobky, kategorie, cenové skupiny, suroviny, sklad
- 👥 Odběratele, B2B login, pobočky
- 🏷️ Cenovky/štítky (15 designů, A4 archy Avery/Printky/SEVT)
- 📋 HACCP dokumenty + audity + grafy postupů
- 🛣️ Rozvozové trasy
- 🔁 Opakující se objednávky (cron)
- 📈 Sales report PDF
- 📧 Email šablony (8 HTML designů)
- 📱 PWA push notifikace (custom VAPID, žádný Twilio)
- 🔌 REST API v1 (Bearer token)
- 🎯 Onboarding wizard (ARES + SK RPO auto-fill)
- 📤 Export katalogu (XML/CSV/JSON/Heureka/Zboží)
- 📇 vCard import vizitek

### Vzhled
- 4 témata: Moderní (default) / Apple / Win98 / Dark
- 4 hustoty UI: Kompaktní / Pohodlné / Prostorné / Extrémní
- Responzivní (wide tabulky se přepínají na karty pod 1300px)
- Browser detection banner pro staré PCs
- CSS fallbacky (`:has()`, `aspect-ratio`, `gap`)

### Bezpečnost
- `robots.txt` (zákaz AI scraperů + SEO botů)
- `.htaccess` (universal compat: mod_php / PHP-FPM / Apache 2.2+)
- `.user.ini` pro PHP-FPM hostingy
- `nginx.conf.example` pro VPS
- Meta noindex
- HMAC autentizace pro sync API

### 🔄 SYNC FEATURE (NOVĚ — květen 2026)

3 módy provozu:
- **Local** — offline-only (XAMPP/MAMP)
- **Hybrid** — lokálně + cloud sync (slabý internet)
- **Cloud** — pouze hosting (current default)

Soubory:
- `api/_sync_schema.php` — auto-migrace
- `api/sync/_engine.php` — core lib
- `api/sync/agent.php` — local agent
- `api/sync/receive.php` — cloud endpoint (PŘÍJEM)
- `api/sync/pending.php` — cloud endpoint (VÝDEJ)
- `api/sync/status.php` — admin info

Admin UI v Nastavení → Údržba → ☁️ Sync s cloudem.

Installer (`install.php`) má NEW step 0 — mode selection (Local/Hybrid/Cloud).

**Sync je OFF by default** — instalace bez sync zapnutého fungují normálně.

Architektura: viz `SYNC_ARCHITECTURE.md` ve stejné složce.

---

## 🚀 Deploy pipeline (květen 2026)

Nasazování na web je automatizované přes GitHub — ŽÁDNÉ ruční nahrávání zipů.

**Postup:** změna v `~/projects/appek.cz` → commit → `./scripts/release.sh X.Y.Z` → hotovo.

- `scripts/release.sh X.Y.Z` — bump verze v `api/config.php`, commit, tag `vX.Y.Z`, push
- GitHub Actions (`.github/workflows/release.yml`) — na tag postaví MASTER zip a pošle ho na server
- `vendor/deploy-hook.php` — token endpoint; přijme zip a nasadí přes `vendor/_self_update.php` (apply rutina + záloha + auto-rollback + health check)
- GitHub Secrets `DEPLOY_TOKEN` + `DEPLOY_URL` jsou nastavené
- Detail: `docs/superpowers/MANUAL-STEPS-F1.md`

**Lokální dev:** `localhost/appek/` (XAMPP) je kopie `~/projects/appek.cz`; obnovit ji jde `./deploy-local.sh --no-vendor`.

**Apple/Klasik landing redesign:** rozdělaná práce je odložená na git větvi `apple-vzhled-wip`.

---

## 🚧 Co NENÍ hotové / opensource

### Sync feature
- Phase 5 (Testing) — pending, nutno otestovat manuálně 2-instance setup
- Phase 6 (Dokumentace pro zákazníka) — pending

### Možná budoucí rozšíření
- Multi-tenant SaaS verze (per-bakery isolation)
- HACCP knihovna šablon pro cukrárny / lahůdky / catering
- AI generování flow diagramů
- Auto-deploy script

---

## 🔑 Konvence projektu

### Cache busting
- `admin.css?v=X.YY` v admin/index.html
- `admin.js?v=Z.WW` v admin/index.html
- Aktuálně: `admin.css?v=12.81`, `admin.js?v=13.22`
- **Po každé změně CSS/JS** — bump v admin/index.html

### Kódování
- UTF-8 všude
- Czech jazyk v UI (i v komentářích kódu)
- Apple-style design vibe (SF Pro, kulaté rohy, jemné stíny)

### Bezpečnost
- Žádné credentials commitnuté — vše v `api/config.local.php` (auto-generated by installer)
- `.installed` flag blokuje druhé spuštění installeru
- Hesla `password_hash(BCRYPT)`
- Session cookies HttpOnly + SameSite=Lax

### Database
- Auto-migrace v každém `admin_*.php` (přidávání sloupců/tabulek idempotentně)
- `_schema.sql` master schema (spouští installer)
- Žádné DROP/DELETE migrace — jen ADD (kompatibilní se starými instalacemi)

### CSS architektura
- Pořadí: base → themes → density → component overrides
- `!important` jen kde nutné (themes vs density konflikty)
- Mobile-first ne — desktop-first s breakpointy dolů

---

## ⚠️ DŮLEŽITÉ — NIKDY NEDĚLEJ

1. ❌ **Nesahat na `/Users/chossekilaimac/Desktop/Reprekv.cz/`** — to je živá produkce
2. ❌ **Nemazat `_schema.sql`** — installer ho potřebuje
3. ❌ **Nemazat ` api/config.local.php`** ani `api/.installed` při update — uživatel by ztratil DB přístupy
4. ❌ **Neměnit URLs API endpointů** bez updatu admin.js
5. ❌ **Nepřejmenovávat z Appek zpět na Repre** — produkce už je rebrand

## ✅ DOPORUČENÉ POSTUPY

1. ✅ Před změnou velkého souboru (admin.js / admin.css) — Read first
2. ✅ Velké rebrand operace — sed s find -print0 (vyhne se "argument list too long")
3. ✅ Po každé změně CSS/JS — bump cache verze
4. ✅ Pro nové features — auto-migrace v `_*.php` souborech
5. ✅ Pro destruktivní akce — vždy ptat se uživatele

---

## 🗣️ Komunikační styl uživatele

- Komunikuje v **češtině**, často krátké zprávy
- Občas překlepy ("appek.cz" by mohl být i "appel.cz") — interpretovat kontextově
- Užívá emoji v requestech (např. "udělej 🍎 Apple téma")
- Preferuje **konkrétní řešení** před dlouhými diskusemi
- Když říká "udělej to celý" = chce, ať dokončím vše naráz, on otestuje hromadně
- Říká "bomby" pro pořádně udělané, "odpad" pro nedotažené
- Když říká "vrať to zpátky" = revert poslední změnu

### Historie posledních milestones
1. Cesta Reprekv.cz → 13.20+ vzhledových oprav (density, dark mode, themes)
2. Rebrand → appek.cz (kopie + sed replace)
3. PDF instalační návod 20 stran (Apple-style HTML print)
4. Sync feature Phase 1-4 (foundation + engine + UI + installer)

---

## ⛔ NIKDY NEDĚLAT — Permanent Rules (user explicit)

### Filter tabs DESIGN (CANONICAL — měněno mnohokrát, NIKDY ovály)
**NIKDY pill border-radius (999px / oválné).** Filter tabs musí být:
- ✅ Rounded square (border-radius z `var(--filter-tab-radius)` = 10px)
- ✅ Sjednocené napříč všemi themes (default/dark/apple/win98)
- ✅ Aplikuje se na: `.period-tab`, `.seg-tab`, `.vyroba-subtab`, `.nastaveni-tab`
- ❌ NIKDY `border-radius: 999px` na filter tabs
- ❌ NIKDY `flex-wrap: wrap !important` (na filter tabs)

**Breakpoint pravidla — KANONICKÉ:**

| Breakpoint | Skupina A (.period-tabs) | Skupina B (.seg/.vyroba/.nastaveni) |
|---|---|---|
| **PC desktop** (>1024px) | 1 řádek flex distribuce | 1 řádek flex distribuce |
| **Tablet** (700-1024px) | 1 řádek flex distribuce | **3-col grid** ← user request |
| **Mobile** (<700px) | 1 řádek nowrap shrink (clamp) | **3-col grid** (kompaktnější fonts) |

- Skupina A = period filtry (Dnes/Týden/Měsíc/Rok/Vlastní/Vše) — 5-6 tabů
- Skupina B = hub tabs (Výroba 7, Nastavení 9, Sklady správa) — víc tabů, nesedí do 1 řádku
- Lichý počet v Skupině B (7 tabů Výroba) → poslední `grid-column: span 3` (full row)

**Centrální tokens v `:root`** (`--filter-tab-*`) — DRY. Změna v 1 místě = projeví se všude.

**Debug postup** když user řekne "ovály" / "stará verze" / "už po třetí":
```
grep -B 5 "border-radius: 999px" admin/admin.css | grep -E "period-tab|seg-tab|nastaveni-tab"
grep -B 5 "flex-wrap: wrap !important" admin/admin.css | grep -E "period-tab|seg-tab"
```
Najít všechny duplicitní mobile overrides a SMAZAT (CANONICAL pravidlo
je v admin.css řádek ~5128, blok "MOBILE filter tabs — CANONICAL").

### Layout konzistence
- Dashboard stat-grid: 75/25 (Tržby+Dnes) + 50/50 (Obj+Splatnost) zachovat
  **i v mobile** (NE stack do 1 col, user 2× explicit)
- Page titles s emoji (📊 🛒 📃 💰 📦 👥 🛠️) — sjednocené napříč pages
- Sparklines decentní (opacity 0.55, max 24-32px height, hide na <600px)

---

## 📋 Aktuální stav (květen 2026)

- Cache: `admin.css?v=12.81`, `admin.js?v=13.22`
- Verze krabicovky: v1.0
- Status: **Vývojový — pre-test** sync feature
- Příští krok: uživatel manuálně otestuje appek.cz lokálně (XAMPP/MAMP) a ověří funkčnost

---

## 🎬 Quick start pro tebe, Claude

Když uživatel napíše první zprávu v této konverzaci:
1. **Nepřepisuj tento dokument** — je tu pro reference, jen ho rozšiřuj o nové sekce
2. **Při velkých změnách** přidej do "Co je hotové" seznamu
3. **Po dokončení work session** můžeš updatovat "Aktuální stav"
4. **Nikdy nesahej na Reprekv.cz/** — vždy příklad: ❌

Pokud uživatel řekne "tady ti to vysvětlím" — dej mu plnou kontrolu narrative. Tvoje role je provést kód.
