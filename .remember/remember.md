# Handoff

## State
Demo na **v3.0.207** (vendor id=485). Tento session nasazeno v3.0.198→207: B2B responzivita (hlavička/tlačítka), tiskárny↔stanice + dine-in bony (v3.0.200/203), rezervace otevírací doba + adaptivní kalendář (v3.0.201), POS KASA/hranaté dlaždice + floor-plan opravy (v3.0.202), nedestruktivní floor plan apply (v3.0.202), POS +/− v účtu + 1-klik restock (v3.0.204), „Zaplatit a zavřít" labely (v3.0.205), **pay fix** (payment→platby + zpusob ENUM normalizace, v3.0.206), **B2B portál denně/týdenní → recurring pravidlo + day-picker (v3.0.207)**.
**RESTAURACE-CHECKLIST 60/61 🔒** (otevřené: nic kritického). **B2B-CHECKLIST 27/27 🔒 — uzavřeno 100 %.**

## Next
- Čeká na směr uživatele (B2B i restaurace hotové). Možné: #3 UI/UX doladění napříč POS/admin (B2B portál čistý), nebo jiný modul (Výroba/Kalkulace/Sklady) „jako restauraci".

## Context
- 🔴 PRAVIDLO (DO ODVOLÁNÍ): **„dotáhni vždy do konce"** — dokončit na 100 % (ne 26/27 a ptát se); checkpoint „jedem dál?" až když je věc KOMPLETNÍ. Kombinuje s „NIKDY hotovo bez user jedem dál".
- Deploy flow: `bash scripts/build-update.sh X.Y.Z` → git push+tag → vendor publish (curl, heslo Karkulka55+ url-enc) → demo updates_apply. Před deploy: Preview MCP screenshot, php -l, jsc syntax.
- Preview MCP (appek-php) padá často → restartuj `preview_start name:appek-php`; naviguj na localhost/appek (XAMPP :80), ne na preview port. Lokální login: admin@admin.cz/demo1234 (role 'admin'=super), b2b b2btest@demo.cz/demo1234.
- zsh nedělí nequotované proměnné → mysql/loop přes přímé volání, ne `$VAR` s mezerami.
