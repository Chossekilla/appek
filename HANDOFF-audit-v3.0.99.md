# 🔍 HANDOFF — APPEK B2B kompletní audit (v3.0.99)

**Stav:** 2026-05-27 ~21:00 · poslední commit po `e59e304` (v3.0.99 deployed)
**Pro:** druhé vlákno / robot — proveď komplet audit aplikace, najdi bugy.

---

## 🎯 Co se má udělat v druhém vlákně

User chce **komplet audit** — projít celou aplikaci napříč:
- Všechny stránky (Přehled, Objednávky, Výroba, Dodací listy, Faktury, Výrobky, Nástroje, Odběratelé, Nastavení, POS, KDS, Výdej, Restaurace)
- Všechny density modes (Kompaktní, Pohodlné, Prostorné, Extrémní)
- Všechny themes (Moderní, Apple, Tmavý, Windows 98)
- Mobile + Desktop viewports
- Pinned + Non-pinned sidebar states

**Cíl:** vypsat všechny chyby co najde do strukturovaného seznamu (priorita, file:line, opravný návrh).

---

## ⚠️ KRITICKÁ PRAVIDLA (do odvolání — user explicit)

1. **NIKDY** psát "hotovo / ✅ / deployed" bez user explicit "jedem dál"
2. **POUŽÍVAT Preview MCP** (`mcp__Claude_Preview__`) pro vizuální verifikaci PŘED reportováním
3. **Plan agent** pro non-trivial CSS změny (>50 LOC, shared selector, opakovaný problém)
4. **architecture-critic agent v background** po commitu >50 LOC
5. **iCloud sync** kontrolovat verze na 3 místech (local/XAMPP/iCloud) — iCloud občas zaostává
6. **`api/config.local.php`** — NIKDY nesyncovat (rsync vždy s `--exclude='api/config.local.php'`), jinak login crash
7. **Service Worker cache** — po deploy user musí Cmd+Shift+R nebo Safari → Smazat data webů
8. **Hostinger CDN** drží 7-denní cache (`max-age=604800`) — bump version vždy
9. **MOBILE pin v rail** (`@media (max-width: 1100px)` v admin.css line 19870+) — user dělal SÁM, NESAHAT bez explicit
10. **PC sidebar fixy** v3.0.82/86/91/93/94 zakomentované (line 20223-20401 admin.css) — REVERT na original

---

## 🔑 Login

```
Local XAMPP:  http://localhost/appek/admin/    (rate limit BYPASS na ::1)
Live:         https://demo.appek.cz/admin/

Email:        admin@appek.cz   nebo  demo@appek.cz  nebo  admin@admin.cz
Heslo:        admin123
JSON klíč:    "heslo" (NE "password")

cURL test:    curl -X POST -H "Content-Type: application/json" \
                -d '{"email":"admin@appek.cz","heslo":"admin123"}' \
                http://localhost/appek/api/admin_login.php
```

---

## ⚙️ Build/sync workflow

```bash
cd /Users/chossekilaimac/projects/appek.cz
./build-zip.sh X.Y.Z

# rsync — VŽDY s exclude config.local.php (jinak login break!)
rsync -a --delete --exclude='.git' --exclude='*.log' --exclude='api/config.local.php' --exclude='uploads/' \
  /Users/chossekilaimac/projects/appek.cz/ \
  /Applications/XAMPP/xamppfiles/htdocs/appek/

rsync -a --delete --exclude='.git' --exclude='uploads/' --exclude='api/config.local.php' \
  /Users/chossekilaimac/projects/appek.cz/ \
  "/Users/chossekilaimac/Library/Mobile Documents/com~apple~CloudDocs/appek.cz/"

git add -A && git commit -m "..." && git tag -a vX.Y.Z -m "..."
git push origin main && git push origin vX.Y.Z
# GH Action deploy → demo.appek.cz za 1-2 min
```

## Verze bump checklist (každý release)

```
admin/admin.js            const APPEK_ADMIN_JS_VERSION = 'X.Y.Z'
admin/sw.js               const CACHE_VERSION = 'appek-vX.Y.Z'
api/config.php            define('APP_VERSION', 'X.Y.Z')
admin/admin.css           --appek-css-version: "X.Y.Z"
admin/index.html          ?v= (4× — admin.css, admin.js, i18n.js, i18n_auto.js)
b2b/index.html            ?v= (3× — style.css, i18n.js, app.js)
```

---

## 📊 Známé bugy / TODO (priorita)

### 🔴 Vysoká priorita

| Bug | Stav | File:line |
|---|---|---|
| Density Extreme/Spacious nemění velikost menu na desktopu | Rollback v3.0.95, znovu řešit | admin.css 11251, 19114-19127, 277-282 |
| Compact = Comfortable (identicky vypadají) | Open | admin.css `@media (max-height: 800px)` line 287-291 přebíjí density Compact bez !important |
| Service Worker cache stale CSS na production | Workaround: bump version | admin/sw.js |
| Hostinger CDN drží 7-denní cache | Workaround: ?v= cache buster funguje | server config |

### 🟡 Středně

| Bug | Stav |
|---|---|
| Balíčky toggle: handle visible při expanded (v3.0.99 fix #package-subheader ID specificity) | Verify |
| Quick actions grid auto-fit collapsed na 1 col v narrow (v3.0.98 fix natvrdo 1fr 1fr) | Verify |
| Mobile period tabs "Měs" místo "M" (v3.0.97 fix isMob always 1-letter) | Verify |
| Objednávky select-all toggle mobile (v3.0.90 přidáno) | Verify live |
| Výroba kalendář compact desktop (v3.0.77) | Neověřeno |
| POS Stoly canvas responsive (v3.0.84 aspect-ratio + scale) | Preview MCP OK, user nepotvrdil |
| Tabulkový toggle u kalendáře (v3.0.77) | Neověřeno |
| vendor.appek.cz šedé sidebary + bílý dashboard (v3.0.80) | Neověřeno |

### 🟢 Nízká / cosmetic

- Footer zobrazí starý verze pokud SW cache → user instrukce Cmd+Shift+R
- Localhost login: rate limit truncate manuálně pokud Preview MCP test floodne (bypass v3.0.98 už cleanup řeší)

---

## 🧠 Klíčové architektonické pozn. (CSS)

### Sidebar/menu cascade hell

V `admin.css` jsou rules na `.sidebar-nav .nav-item` rozesety po souboru s různými specificity:

| Line | Selector | Spec | Co dělá |
|---|---|---|---|
| 252 | `.sidebar-nav .nav-item` | (0,2,0) | base flex-shrink |
| 272 | `.sidebar-nav .nav-item` | (0,2,0) | flex 0 1 auto, min-height 38px |
| 277-282 | `@media(>701px) .sidebar-nav .nav-item` | (0,2,0) !imp | flex 1 1 0, max-height 80px (v3.0.68) |
| 287-291 | `@media(max-h:800) .sidebar-nav .nav-item` | (0,2,0) !imp | min-height 30px |
| 11251 | `html.density-extreme .nav-item` | (0,2,1) | padding 26, font 22 |
| 19114 | `html.density-spacious body:not(.pinned) .sidebar-nav .nav-item` | (0,4,3) !imp | density Spacious mobile |
| 19720 | `@media(1024-1366) .nav-item` | (0,1,0) !imp | MacBook13 |
| 20223-20401 | **ZAKOMENTOVÁNO** v3.0.82/86/91/93/94 mé pokusy | — | rollback |

**Doporučení:** druhé vlákno → cleanup duplicates, jeden authoritative density block per density mode.

### Pin / sidebar-utils

- **Mobile pin** v rail mode: admin.css line 19870-20100 (v3.0.85+ user-edited transparent) — NESAHAT
- **Desktop pin** "Připnuto" + "Skrýt menu" v sidebar-utils — VISIBLE per user (v3.0.94 hide reverted)

### Balíčky subheader (#package-subheader)

- HTML: `admin/index.html:267-285` — subheader + toggle button + handle
- CSS: `admin/admin.css:13438-13524` + `17459-17475` (DUPLICATE! ID selector override)
- JS: `admin/admin.js:15604-15663` — render + toggle function + localStorage persist
- **Bug fixed v3.0.99:** ID specificity (1,1,0) přebíjelo class rule — fix přidal ID do collapse rule

---

## 📂 Klíčové soubory

```
/Users/chossekilaimac/projects/appek.cz/
├── admin/
│   ├── admin.js          ~35k LOC vanilla JS SPA
│   ├── admin.css         ~20k LOC monolitické styly
│   ├── pos.css, pos.js   POS terminal
│   ├── index.html        loader + login screen
│   └── sw.js             service worker
├── api/
│   ├── config.php        APP_VERSION + DB connect + rate limiter (v3.0.98 bypass on ::1)
│   ├── config.local.php  DB credentials (NIKDY nesyncovat!)
│   ├── admin_*.php       REST endpoints
│   └── login.php / admin_login.php
├── b2b/                   B2B portál
├── vendor/                vendor.appek.cz panel
├── CLAUDE.md             project rules (v3.0.86+ quality gates)
├── HANDOFF-pin-rail.md   pin design history (✅ resolved v3.0.85)
└── HANDOFF-audit-v3.0.99.md  ← THIS FILE
```

---

## 🛠️ Doporučený workflow audit

1. **Setup:** `cd /Users/chossekilaimac/projects/appek.cz && git pull && ls api/config.local.php` (musí existovat)
2. **Localhost dev:** `http://localhost/appek/admin/` přihlas `admin@appek.cz / admin123`
3. **Preview MCP:**
   ```
   mcp__Claude_Preview__preview_start    name: "appek-php"
   mcp__Claude_Preview__preview_resize   width: 1280, height: 800
   mcp__Claude_Preview__preview_screenshot
   ```
4. **Audit matrix** — projít každou stranu × 4 density × 4 themes × 2 viewports (mobile 390 + desktop 1280):
   - Dashboard / Přehled
   - Objednávky (list + detail + nová)
   - Výroba (kalendář + výrobní list + suroviny + sklad)
   - Dodací listy
   - Faktury (list + ISDOC export)
   - Výrobky (CRUD + import + cenovky + štítky)
   - Nástroje (tiskárny + integrace + diagnostika)
   - Odběratelé (CRUD + import)
   - Nastavení (jazyk + theme + density + bezpečnost)
   - POS (kasa + stoly + KDS)
   - Restaurace balíček (stolová správa + rezervace + kapacita)
5. **Pro každou chybu** zapsat:
   ```
   [PRIORITA] [PAGE] [STATE]
   Co špatně: ...
   File:line: ...
   Návrh fix: ...
   Severity: blocker/major/minor/cosmetic
   ```
6. **Output** → nový file `AUDIT-FINDINGS-v3.0.99.md` v projektu

---

## 🎯 Co user OČEKÁVÁ od audit

- **Strukturovaný seznam** (markdown table) všech nalezených bugů
- **NEFIXOVAT** nic samo — jen NAJÍT a ZAPSAT
- User sám rozhodne co opravit
- **Kompletnost > rychlost** — projít VŠECHNY pages, ne jen vzorek
- Screenshots kdyby pomohly (Preview MCP)

---

## 📌 Posledních ~30 release notes (kontext)

```
v3.0.99 — Balíčky toggle ID specificity fix (#package-subheader.show přebíjelo class collapse)
v3.0.98 — Quick actions 2x2 grid + login bypass localhost
v3.0.97 — Mobile period tabs vždy 1-letter (D/T/M/R/V)
v3.0.96 — Fix balíčky handle toggle (duplicitní display override)
v3.0.95 — ROLLBACK PC sidebar fixů v3.0.82/86/91/93/94 (zakomentováno)
v3.0.94 — Pin + sidebar-utils HIDE na desktopu (REVERTED v3.0.95)
v3.0.93 — NUCLEAR density override (REVERTED v3.0.95)
v3.0.92 — Tvé balíčky subheader collapse/expand toggle
v3.0.91 — Density-aware sidebar (REVERTED v3.0.95)
v3.0.90 — Objednávky select-all mobile
v3.0.89 — Pin v rail TRANSPARENT (krémovou smaž)
v3.0.88 — Cleanup pin CSS legacy (−159 LOC)
v3.0.87 — Pin v rail: bottom-nav HIDDEN + sidebar bottom:0
v3.0.86 — Sidebar rail solid bg + DEFENZIVNÍ desktop full-flex (zakomentováno)
v3.0.85 — Pin v rail krémová dlaždice DOLE
v3.0.84 — Floor canvas auto-fit (aspect-ratio + scale)
v3.0.83 — Stepped scale ladder (REPLACED v3.0.84)
v3.0.82 — Desktop sidebar-pinned full-flex (REVERTED v3.0.95)
v3.0.81 — Pin uprostřed empty (REVERTED)
v3.0.80 — Pin jen ikona bez rámečku
v3.0.79 — Pin krémová dlaždice s textem
v3.0.78 — ZÁRUKA pin v rail visible
v3.0.77 — Výroba kalendář compact + POS Stoly canvas responsive + table view toggle
v3.0.76 — Smaž tooltip pod logem
v3.0.74 — Pin přesunut do bottom-nav (REVERTED v3.0.75)
v3.0.73 — Valdemar fix
v3.0.72 — KRITICKÝ download.php regex
```

---

**Konec dokumentu. Připravil Claude Opus 4.7 — pro audit v druhém vlákně.**
