# 🔄 HANDOFF — APPEK admin pin v rail (mobile pinned mode)

**Stav:** 2026-05-27 ~16:00 · poslední commit `6d01f95` (v3.0.81 deployed)
**Status:** ❌ USER FRUSTROVANÝ — pin pořád ne podle představy. **NEVERTOVAT bez explicitního pokynu.** User řekl "udělám si to sám".

---

## 📍 Kde to vázne

Pin (📌 toggle připnutí sidebaru) na mobilním rail módu (`body.sidebar-pinned` @ ≤1100px).
User řekl 3× po sobě "není to jak chci" — design + pozice pinu se mu nelíbí.

User chce **vrátit ZÁLOHU** — daleko starší verzi kde menu/pin fungoval ok. Která verze přesně = nevíme, user řekl "udělám si to sám".

## 🧠 Co user CHCE (z dlouhé konverzace, nejisté)

| Co | User řekl verbatim |
|---|---|
| Pin **není součást menu** | "není součást menu za prvé" |
| Pin **sám bez rámečku** | "má být sám bez rámečku" |
| **Čárkovaný oddělovač** nad pinem | "s čárkovaným oddělovačem" |
| **Nesmí být schovaný** pod bottom-nav | "ale nesmí být schovaný pod bottom menu" |
| Pin **viditelný 1×** (ne duplicitní) | "vidím ho jenom jednou teda já?!" |
| Schválil v3.0.80 → "je tady!" | pak ale chtěl jinou pozici |
| **Uprostřed empty space** (v3.0.81 pokus) | "uprostřed prázdného prostoru mezi gear a bottom-nav" |
| **NE oválky** (v3.0.79 cream tile s textem) | "kulatej je hnusnej" / "ovalky" |

## 🔧 Iterace pinu — co bylo zkoušeno

| Verze | Co | User |
|---|---|---|
| v3.0.62 | Pin = floating gold pill `position: fixed` | ❌ "kulatej je hnusnej" |
| v3.0.63 | Zaoblený obdélník (rounded square) | OK krátkodobě |
| v3.0.67 | Natural pozice v sidebar-utils | OK |
| v3.0.74 | Pin přesunut **do bottom-nav** jako 6. tab | ❌ "vrať to do rail" |
| v3.0.75 | REVERT v3.0.74, pin zpět do rail jako krémová dlaždice | OK |
| v3.0.78 | ZÁRUKA visibility:visible + flex 60px slot | OK technicky |
| v3.0.79 | Krémová dlaždice s textem **"PŘIPNUTO"** (vertical) | ❌ "ovalky/hnusnej" |
| **v3.0.80** | Jen ikona, bez rámečku, dashed nahoře, dole nad bottom-nav | ✅ "je tady!" pak ❌ "není to" |
| v3.0.81 | Pin **vertikálně uprostřed** empty space (sidebar-utils flex 1 1 auto) | ❌ "nepřepisuje to dobře" |

## 📂 Klíčové soubory (kde žije pin CSS)

```
/Users/chossekilaimac/projects/appek.cz/admin/
├── admin.css
│   ├── line 280-322       Base .sidebar-pin (desktop)
│   ├── line 345-356       body.sidebar-pinned .sidebar-pin (base)
│   ├── line 526-650       Original rail rules (@media 700px)
│   ├── line 9982          html.dark .sidebar-pinned .sidebar-pin
│   ├── line 19100-19170   v2.9.94 rail RAIL (canonical)
│   ├── line 19756         Tablet portrait rules
│   ├── line 19989         Fluid clamp rules
│   └── line 20033-20180   v3.0.80/81 BLOCK (current attempt)
├── admin.js
│   └── line 2139         window.toggleSidebarPin = function()
└── index.html
    └── line 197          <button class="sidebar-pin" id="sidebar-pin-btn">
```

## 🚦 Co je další otevřené (mimo pin)

| # | Požadavek | Verze | Status |
|---|---|---|---|
| 6 | Výroba kalendář compact desktop | v3.0.77 | ⏳ Neověřeno |
| 7 | POS Stoly canvas responsive | v3.0.77 | ⏳ Neověřeno |
| 8 | Tabulkový toggle u kalendáře | v3.0.77 | ⏳ Neověřeno |
| 12 | vendor.appek.cz šedé sidebary + bílý dashboard | v3.0.80 (vendor) | ⏳ "takhle to pozadí" — user nepotvrdil "jedem dál" |

## 🌐 Aktuální deployed verze

- Local repo: **v3.0.81** (commit `6d01f95`)
- XAMPP localhost: **v3.0.81** (sync OK)
- iCloud: **v3.0.81** (sync OK)
- Live demo.appek.cz: **v3.0.81** (GH Action deploy proběhl)
- vendor.appek.cz: poslední commit `1ff907d` (gray sidebary + white middle)

## 🔑 Login pro testování

```
URL:    http://localhost/appek/admin/  (lokálně)
        https://demo.appek.cz/admin/   (live)
Email:  demo@appek.cz   (nebo admin@admin.cz)
Heslo:  admin123
JSON:   {"email":"demo@appek.cz","heslo":"admin123"}   ← klíč je "heslo" ne "password"!
```

## ⚙️ Build/sync workflow

```bash
cd /Users/chossekilaimac/projects/appek.cz
./build-zip.sh X.Y.Z

# rsync (POZOR: vždy s exclude config.local.php — jinak login přestane fungovat!)
rsync -a --delete --exclude='.git' --exclude='*.log' --exclude='api/config.local.php' \
  /Users/chossekilaimac/projects/appek.cz/ \
  /Applications/XAMPP/xamppfiles/htdocs/appek/

rsync -a --delete --exclude='.git' --exclude='uploads/' --exclude='api/config.local.php' \
  /Users/chossekilaimac/projects/appek.cz/ \
  "/Users/chossekilaimac/Library/Mobile Documents/com~apple~CloudDocs/appek.cz/"

git add -A && git commit -m "..." && git tag -a vX.Y.Z -m "..."
git push origin main && git push origin vX.Y.Z
```

## ⚠️ KRITICKÁ PRAVIDLA pro další vlákno

1. **NEPSAT "hotovo" / "✅"** dokud user explicitně neřekne "jedem dál" / "OK".
2. **POUŽÍVAT Preview MCP** (`mcp__Claude_Preview__`) k VIZUÁLNÍ verifikaci PŘED reportováním.
3. **NEMĚNIT pin design** bez explicitní instrukce od usera — user už 3 hodiny prosí o ten samý design.
4. **iCloud sync** — kontrolovat verze na všech 3 místech (local/XAMPP/iCloud) — iCloud občas zaostává.
5. **`api/config.local.php`** — NIKDY nesyncovat (rsync vždy s `--exclude`), jinak DB credentials zmizí + login přestane fungovat.
6. **Service Worker cache** — po deploy user musí Cmd+Shift+R nebo Safari → Smazat data webů.

## 📋 Co user řekl posledně (chronologicky)

1. "vidíš pin kde byl? prázdné místo. a vidíš na fotce kde je? tam ho chci."
2. "tam kde ho vidíš tam ho dej. vidim ho jenom jednou teda já?!"
3. (volil "Uprostřed prázdného prostoru" v AskUserQuestion)
4. "nepřepisuje to dobře, něco se změnilo asi pin je pořád součástí menu. chjo byla záloha! jsem to psal tady je menu ok tady to ulož"
5. "tohle smaž je to pořád dokola a špatně už po 85tý"
6. "ne já nic neschválil. byla daleko dřívější záloha přímo menu"
7. "ni nerevertuj. zkusíom to v jiném okně. tady to už asi nejde sepiš a ulož co ti nejde"

## 🎯 Doporučení pro další vlákno

1. **PRVNÍ KROK:** Zeptat se usera **konkrétně** kterou verzi pinu chce — pošli mu screenshot z `git log v3.0.50..v3.0.70` a nech ho vybrat OBRÁZKEM které design.
2. **NEMĚNIT NIC** dokud user neřekne specificky která verze = OK.
3. Možná **vykreslit screenshoty všech verzí (v3.0.50, .55, .59, .67, .75, .80)** přes Preview MCP — pak user vybere obrázkem.
4. Pak udělat REVERT na vybranou verzi (jen pin CSS sekce, nebrat overal celý codebase zpět).

---

**Konec dokumentu. Předáno k zopakování v novém vlákně.**
