# Changelog

Všechny významné změny v APPEK B2B.

Formát: [Keep a Changelog](https://keepachangelog.com/cs/) · [Semantic Versioning](https://semver.org/lang/cs/).

---

## [3.0.39] — 2026-05-26

### 📱 POCKET-READY — APPEK jako profi mobilní app
_User feedback: "musí to být super vychytávka do kapsy"_

**📲 Bottom navigation enabled na mobilu (≤768px):**
- 5 tlačítek (Přehled / Objednávky / Výroba / Dodací listy / Nastavení)
- Frosted-glass backdrop (`backdrop-filter: blur(20px)` + 96% alpha)
- iOS safe-area podpora (`env(safe-area-inset-bottom)` pro home indicator pruh)
- Aktivní item zvýrazněn primary color + drop-shadow
- **Notification badge** — počet nových objednávek se aktualizuje každých 60s s animací pulse
- Auto-padding `<body>` aby fixed nav nepřekrýval content

**➕ Context-aware FAB (Floating Action Button):**
- Per-stránka primary akce (Dashboard → 🛒 Nová obj · Výroba → 🥖 Vyrobit · DL → ➕ Nový DL · atd.)
- Sledování přes `state.current` v `navigate()`
- Pill-shape s emoji + label (vyšší affordance než kruh)
- iOS safe-area margin

**📍 Tap-to-navigate:**
- Adresy v recent rows na dashboardu nyní clickable → `openInMaps()`
- Univerzální deeplink: `maps://` (iOS) → `geo:` (Android) → fallback `google.com/maps` (web)
- Vizuální indikace 🗺️ emoji + primary barva

**📲 PWA install banner:**
- Po 30s na mobilu se zobrazí "📲 Nainstaluj APPEK na plochu"
- Dismiss persisted do localStorage (žádné nátlakové repeaty)
- Trigger `beforeinstallprompt` event (Android + Chrome)
- Skryté pokud už nainstalováno (`display-mode: standalone`)

**🎨 Visual polish:**
- Pulsing badge animace 1.4s ease-in-out
- Slide-up animace banneru 0.35s ease-out
- Pull-to-refresh indikátor (CSS připravený, JS handler v další verzi)

### 📦 Build & sync
- Bumped: config.php 3.0.38→3.0.39, admin.js, sw.js, HTML versioned URLs

---

## [3.0.38] — 2026-05-26

### 🍔 Plnohodnotná LIVE integrace pro Wolt / Bolt Food / Dáme jídlo / Foodora
Po zpětné vazbě uživatele _"musí to být, tak to dodělej"_ — kompletně přepracované od stubu na full backend.

**Nová library: `api/_delivery_aggregators.php`** (~700 řádků):
- HTTP klient přes curl (žádné Composer dependencies)
- Třídy `Wolt_Client`, `BoltFood_Client`, `DameJidlo_Client`, `Foodora_Client` — společný interface
- Per-service: `test()` · `pushStatus()` · `syncMenu()` · `acknowledgeOrder()` · `parseInboundOrder()` · `verifySignature()`
- HMAC-SHA256 signature verification pro každý webhook
- Mapping stavů: naše 6 stavů ↔ jejich 5-7 stavů per service
- Auto-acknowledge Wolt (musí být do 5 min)

**4 nové webhook endpointy** (public, signature-verified):
- `api/wolt_webhook.php` (`X-Wolt-Signature`)
- `api/bolt_food_webhook.php` (`X-Bolt-Signature`)
- `api/damejidlo_webhook.php` (`X-DameJidlo-Signature`)
- `api/foodora_webhook.php` (`X-DH-Signature`)
- Event handlery: order.created → vytvoří objednávku · order.cancelled → stav zrušena · order.delivered → stav doručena
- `delivery_webhook_log` tabulka pro debug + audit (poslední 30 eventů per service)

**Nové akce v `admin_couriers.php`:**
- `?action=test_integration` — ping API, ověří credentials (Wolt: `GET /venues/{id}/status`, Bolt: `GET /v1/providers/{id}`, Dáme jídlo: `GET /v1/restaurants/{id}`, Foodora: `GET /v1/vendors/{id}`)
- `?action=sync_menu` — push naše restaurační výrobky do jejich katalogu (kategorie + položky + ceny + obrázky)
- `?action=push_status` — manuální push stavu pro konkrétní objednávku
- `?action=webhook_urls` — vrátí URL které user vloží do partner portalu
- `?action=webhook_log&sluzba=X` — poslední příchozí eventy
- **Auto-push stavu** při změně v `delivery_status` — pokud má objednávka externí mapování, automaticky pošle do služby

**Nová tabulka `delivery_external_orders`** — mapování náš id ↔ jejich external_id + raw payload + last status push.

**UI v `admin/admin.js` — kompletně přepracovaný integration modal:**
- Live status badge (🟢 aktivní / ⚪ vypnutá) + Test tlačítko v hlavičce
- Help banner s odkazem na partner portal + API docs per service
- Per-service portal info (Wolt → merchant.wolt.com, Bolt → partners.bolt.eu/food, atd.)
- **Webhook URL display** s "Kopírovat" tlačítkem — user jen vloží do partner portalu
- Pokročilé akce: Sync menu · Webhook log viewer
- Test výsledky inline (zelený OK / červený error s detaily)
- Updated text: ❌ "Plánovaná integrace, manuální evidence" → ✅ "Live integrace aktivní"

### 📱 Mobile UX fixes (user feedback)
- **DL přetékaly na mobilním přehledu** → CSS safety net: `min-width: 0` + `overflow: hidden` + `overflow-wrap: anywhere` na `.recent-card` a buňkách
- **Sidebar logo (písmeno "A" + brand text)** → nyní klikatelné, navigates na dashboard. Hover state + tooltip "🏠 Domů — Přehled"

### 📦 Build & sync
- Bumped: config.php 3.0.37→3.0.38, admin.js, sw.js, index.html versioned assets

---

## [3.0.37] — 2026-05-26

### ⏱️ Doba přípravy — jen jídla & nápoje (žádný chleba)
- **Backend filter** `api/admin_prep_times.php` — výpis výrobků jen z restauračních kategorií (Pizzy, Káva, Nealko, Saláty, Dezerty, Těstoviny, Hlavní jídla, Předkrmy, Polévky, Drinky, Alkohol, Víno, Pivo, Burgery + LIKE patterny `%nápoj%`, `%jídl%`, `%pizza%`, `%káva%`, `%drink%`)
- Fallback pokud kategorie chybí → filtr na `cislo LIKE 'R-%'` (restaurační seed prefix)
- **Důvod:** uživatel reportoval _"doba přípravy veka je divná"_ — nemá smysl mít prep time pro pekařské produkty

### 🏗️ 6 nových restaurant space templates (celkem 10!)
1. 🌳 **Letní zahrada** — Outdoor sezení + pergola, slunečníky, piknik stůl pro 16 (2 zóny)
2. 🍺 **Pivnice Plzeň** — Tap bar (10 míst) + 4 dlouhé komunitní stoly po 12 + 6 menších (zone single)
3. 🎉 **Banketní sál** — Hlavní stůl novomanželů, 6 dlouhých stolů po 10, parket, DJ booth, Champagne bar zóna (2 zóny)
4. 🥩 **Steakhouse Grand** — Premium booths podél stěn, open grill bar, chef tasting, whisky stůl, 3 VIP salonky (2 zóny, tmavé pozadí)
5. 🍔 **Burger Bistro** — Counter + pickup okno + bar pult + booth tables podél stěny + středové stoly
6. 🏙️ **Rooftop Praha** — Cocktail bar (vertikální), 3 sunset sofas, high cocktail tables, fire pit, DJ booth (tmavé pozadí)
7. 🍣 **Sushi & Asia** — U-shaped sushi bar (3 segmenty), 8 barových stoliček, teppanyaki grill tables, Tatami room s VIP suite (2 zóny, tmavé pozadí)

### 🎨 UI polish
- **Banner šablon** v Restaurace → Stoly: 8 quick-pick tlačítek (místo původních 4) + tlačítko "📋 Všechny šablony s náhledem →" otevírající full picker
- **Template picker** modal:
  - Header: počet šablon v titulku (`📋 Šablony layoutu (10)`)
  - SVG mini-preview s barvami **per typ stolu**: bar (oranžová), lounge/VIP (fialová), grill/oheň (červená), parket/DJ (modrá), zahrada/tatami (zelená), default (žlutá)
  - **Podpora tmavého pozadí** (rooftop/steakhouse/sushi) — automaticky invertujeme barvy stolů (tmavší fill, světlejší stroke) pro dobrou čitelnost

### 📦 Build & sync
- Bumped: `config.php` 3.0.36→3.0.37, `admin.js` BUILD_VERSION, `sw.js` CACHE_VERSION
- Updated `index.html` versioned asset URLs (admin + b2b)

---

## [3.0.12] — 2026-05-25

### ✨ Velký redesign POS Stoly modal

**Plně přepsaný table modal — touch-first, responsive, kompaktní**

- **Šířka 1400px max** (předtím 920px) — víc místa pro produkty + cart
- **Výška 92vh** — využívá celou obrazovku
- **Mobil = full screen** (no padding, no border-radius) → wraps whole viewport
- **Dotykové buttony** — min-height 50px, větší padding, výrazný hover/active feedback
- **Animace** — modal fade-in 180ms + slide-up 220ms

**Hlavička modal:**
- Status badge: 🟡 Rozpracovaný / ✅ Zaplaceno / ✕ Storno (auto podle stav účtu)
- Meta info: „otevřen 87 min · Karel"
- ESC klávesa zavře modal
- Rotace ✕ tlačítka na 90° při hover

**Cart sekce:**
- Header counter „📋 Položky účtu (3)"
- Položky jako karty s color-codingem (žlutá = vaří se, zelená = hotovo)
- Status badge vedle jména („✓ hotovo" / „🔥 vaří se")
- Cena 16px tučně zelená
- × tlačítko pro odebrání (animace scale)
- **Summary box** dole: Bez DPH / DPH / **CELKEM (22px)** — vše viditelné

**Menu sekce:**
- Search box 16px font, výrazný focus state s box-shadow
- **Kategorie chips** — gradient pro aktivní, hover border
- **Produkty 160px** s big price (24px tučně oranžová) — touch friendly
- Hover lift -2px + shadow

**Footer akce:**
- 🍳 Tisk bonu · 📤 Tisk účtu · 📲 QR platba (secondary)
- 🔓 Znovu otevřít (jen pokud paid/cancelled, danger style)
- **💾 Uložit a zavřít** (modrý gradient — viditelně odlišený)
- **💰 Zaplatit** (zelený gradient, větší font)

**Responsivní breakpointy:**
- **Desktop (>1100px)** — full 2-col 0.9fr + 1.4fr, 160px produktové karty
- **Tablet (800-1100px)** — užší cart, 140px karty
- **Mobil (<800px)** — full screen, **tabs nahoře** „📋 Účet" | „➕ Přidat" (přepínání cart/menu views), 2 produkty per řádek
- **Phone (<400px)** — kompaktní buttony 12px font

**Status logika:**
- Tlačítka Uložit/Zaplatit zobrazené jen pro `stav=open`
- Tlačítko Znovu otevřít jen pro `paid` nebo `cancelled` (TODO: implementace v adminu)
- Auto-refresh statusu v hlavičce po každém přidání/odebrání položky

---

## [3.0.11] — 2026-05-25

### ✨ Vylepšení

**Back tlačítka — vizuálně odlišená**
- `.btn-back` CSS přebarvená na **modrý nádech** (#EFF6FF → #DBEAFE gradient + modrý border #93C5FD) — vidíš na první pohled že to je "zpět" akce, ne běžné secondary tlačítko
- Hover: tmavší modrá + výraznější posun šipky doleva (animace `translateX(-3px)`)
- Šipka samotná má svojí mini-animaci na hover
- Dark mode varianta s tlumenými modrými barvami
- **Bulk update**: 11 back buttonů v admin.js (mimo onboarding wizard) konvertováno z `btn-secondary` → `btn-back`
- **Diagnostika back button** opraven — vrací do Údržba tabu místo výchozí Firma tabu (3× výskyt opravený)

### 🐛 Opravy

**Faktury — auto-migrace chybějících sloupců**
- Diagnostika hlásila: `faktury.odb_nazev_snapshot` a `faktury.odb_ico_snapshot` chybí
- Nový `ensure_faktury_schema()` v `_schema_lib.php` — auto-vytvoří 6 snapshot sloupců + `rucni` flag
- Zavolán v `api/admin_faktury.php` a `api/admin_faktura_z_dl.php` při každém requestu (idempotent, static cache)
- Po prvním načtení Faktury sekce sloupce přibydou automaticky

---

## [3.0.10] — 2026-05-25

### 🐛 Opravy + ✨ UX polish

**POS Stoly tab (v3.0.8 follow-up)**
- **Ceny 0 Kč** — bug v field name (`v.cena` neexistuje, správně `v.cena_bez_dph` + DPH dopočítat). Teď zobrazuje **správné ceny s DPH**.
- **Cart výpočet** — předtím počítal z `cena_bez_dph` které není v restaurant_pos_polozky. Teď z `jednotkova_cena` (která je s DPH, jak ji POS ukládá).
- **Větší produktové karty** v modal (150px width, 110px height) — touch-friendly pro tablet
- **Velká cena** v kartě (22px bold orange) — vidíš ji první
- **Kategorie chips** nad gridem — filtr per kategorie (Pečivo / Chleby / Nápoje / ...). Bez filtru = "⭐ Vše"
- **Search box** větší (15px) pro snadné psaní

**Zone tabs ve Floor view**
- Tabs nad floor gridem: 🪑 Vše (N) · Sál (10) · Terasa (4) · Bar (4) · Salonek (2)
- Klik = filtr stolů jen pro tu zónu (skryje ostatní)
- Aktivní zóna = sunrise gradient highlight

**Modal akce**
- **🍳 Tisk bonu** — opraven na popup okno (380×640) místo nového tabu, zobrazí toast feedback
- **📤 Tisk účtu** (nahradil "Zaplatit") — vytiskne účet hosta pak nabídne uzavření jako hotovostní platba
- **🗑️ Odebrat položku** — nový × tlačítko vedle každé položky (storno přes item_state endpoint)
- **Stav badge** vedle položky (✓ hotovo / 🔥 vaří se) — info pro číšníka co kuchyně dělá

**Uzavření účtu**
- `posTableCloseUcet()` — volá `admin_pos.php?action=pay` endpoint
- Po platbě modal zmizí, floor refresh ukáže stůl jako volný

---

## [3.0.9] — 2026-05-25

### 🐛 Kritická oprava — subdirectory hosting

**Bug:** Když byla aplikace umístěna v subdirectory (např. `localhost/appek/` při lokálním vývoji nebo na hostingu kde appek není root domény), spousta JS volání selhávala s 404.

**Příčina:** Absolutní cesty `/api/...` místo relativních `../api/...` na 17 místech v admin/pos/b2b šablonách.

**Fix v3.0.9:**
- `admin/pos.php` `CFG.apiBase: '/api/'` → `'../api/'`
- `admin/index.html` 3× `fetch('/api/X')` → `fetch('../api/X')`
- `admin/feature-*.html` (9 souborů) — všechny fetch volání
- `admin/shipping.html` — 2 fetch volání
- `b2b/index.html` — 2 fetch volání

**Production na root doméně** (např. `restaurace.cz/admin/`) fungoval předtím i teď stejně. Bug se projevoval jen na **subdirectory** instalacích (lokální XAMPP, shared hosting bez root).

---

## [3.0.8] — 2026-05-25

### ✨ Nové funkce

**POS — Floor view tab (🪑 Stoly)**
- Nový 4. tab v POS hlavičce: **📦 Produkty | 🪑 Stoly | 📜 Účtenky | 📊 Statistiky**
- Grid karet stolů seskupených podle zón (Sál, Terasa, Bar, Salonek)
- Karta ukáže: název, kapacitu, status (VOLNÝ zelená / OBSAZENO oranžová), sumu účtu + počet položek + minuty od otevření
- Stats banner: Volné / Obsazené / Celkem (počty)
- **Klik na stůl** = otevři účet → modal:
  - Vlevo: aktuální položky účtu + total
  - Vpravo: katalog výrobků (search + grid) — tap = +1 do účtu
  - Footer: 🍳 Tisk bonu / 📲 QR platba / ⚙️ Více v adminu / 💰 Zaplatit
- Floor plan **editor** (přidat bar / salonek / přesunout stoly drag-drop) zůstává v Admin → Restaurace → Stoly → Floor plan tab — POS je jen operační view

---

## [3.0.7] — 2026-05-25

### ✨ Nové funkce

**QR k platbě (pay-at-table)**
- Nová stránka `/pay/?t=<token>` — mobil-first platební landing pro hosty
- V detailu účtenky (POS) tlačítko **"📲 QR platba"** → modal s QR + URL → nalep / vytiskni k účtence
- Host naskenuje → vidí účtenku + 3 možnosti platby:
  - **💳 Stripe** (Apple Pay / Google Pay / kartou online) — pokud nakonfigurován
  - **🔴 GoPay** (kartou / bankovním převodem CZ) — pokud nakonfigurován
  - **💵 Hotovostí číšníkovi** — vždy dostupné, informuje obsluhu
- Backend (`api/pay_qr.php`): create_token, info, status polling, stripe_init, gopay_init, webhook handlers
- Schema: nové sloupce `objednavky.pay_token / pay_status / pay_method / paid_at / pay_extra` (auto-migrace)
- Reuse existující customer_int_stripe_create_checkout / customer_int_gopay_create_payment

**Nápověda — komplexní průvodci**
- Nastavení → ❓ Nápověda → **8 nových sekcí** krok-za-krokem:
  - POS Kasa — jak začít prodávat
  - KDS + Výdej — kuchyňský workflow
  - QR objednávky — host objedná z mobilu
  - QR k platbě — pay-at-table workflow
  - Tiskárny ESC/POS — setup pro kuchyň/bar
  - Multi-screen setup — 3-4 monitory v provozu
  - Update systému — self-update + manuální
  - Zálohy DB — frekvence, off-site, restore

---

## [3.0.6] — 2026-05-25

### ✨ Vylepšení

**Provoz — škálovatelná velikost čísel**
- Default čísla zvětšena 56px → **96px** (skoro 2× větší)
- **6 úrovní velikosti**: XS (56), S (72), M (96), L (130), XL (170), **TV (220px)** — pro velký monitor v provozu
- **A− / A+ buttons** v hlavičce pro live změnu (uloží se do localStorage)
- **Klávesy + / −** pro rychlé škálování bez myši, **0** pro reset
- **URL parametr `?size=tv`** pro deep-link na konkrétní velikost (např. pro fixní setup TV monitoru)
- Label/meta také škálují proporcionálně

### 🐛 Opravy

- **Backward-compat shim `api/admin_katalog.php`** — staré klienty s cached service workerem volaly toto URL → 404. Nyní forwarduje na `admin_pos.php?action=catalog`.

---

## [3.0.5] — 2026-05-25

### ✨ Nové funkce

**Tiskárny — kompletní ESC/POS infrastruktura**
- Admin → Nastavení → **🖨️ Tiskárny** tab
- **CRUD** termo tiskáren: název, typ (kasa/kuchyně/bar/sklad/výdej/generic), IP, port (default 9100), šířka papíru (58/80mm), encoding (cp852/cp1250/utf-8/ascii), aktivní, poznámka
- **Test tisk** — tlačítko 🧪 Test vytiskne testovací bon s diakritikou
- **Mapování kategorie → tiskárna** — výrobky z kategorie "Nápoje" jdou na bar, "Hlavní jídla" na kuchyň atd.
- **Dummy mode** — bez fyzické tiskárny zapsat tisk do `/tmp/appek_printer_dummy/*.txt` (preview přes browser)
- **Statistiky** — počet tisků, datum posledního, last error (auto-zaznamen pokud TCP socket selže)

**POS auto-tisk při finish objednávky**
- Setting "Tisk účtenky po platbě": **Vždy** / **Zeptat se** / **Nikdy**
- Při "Zeptat se" → modal dialog "🖨️ Vytisknout účtenku? Ano/Ne" s velkou částkou (auto-close za 12s)
- Setting "Auto-split bonů": **Auto** / **Manuální** / **Vypnuto**
- Při "Auto" → po `quick_order` se bonu rozesílají paralelně podle kategorie (jídlo → kuchyň, drinky → bar)
- Soft-fail: pokud tisk selže, POS objednávka se přesto uloží (tisk je vedlejší)

**Backend (ESC/POS přes TCP socket :9100)**
- `api/_printer_lib.php` — kompletní ESC/POS builder (init, align, bold, size, feed, cut), encoding přes iconv
- `api/admin_printers.php` — CRUD + test + map + settings + dummy_files endpoints
- `api/admin_pos.php` — nový endpoint `?action=print_receipt` + auto-dispatch v `quick_order`
- Tabulka `restaurant_printers` (auto-create on first hit)
- Column `kategorie_vyrobku.printer_id` (auto-add)

### 🔌 Pro zákazníky

- Standard: **Epson TM-T20III** Ethernet (cca 3500 Kč) nebo **Star TSP100III**
- Podpora **58mm i 80mm** termo papíru
- Bez fyzické tiskárny: dummy mode → testuj logiku, kup tiskárnu když ti vyhovuje

---

## [3.0.4] — 2026-05-25

### ✨ Nové funkce

**Výdej / Pass-through displej** (`admin/vydej.php`)
- Druhý kuchyňský displej pro číšníka u výdejního okna
- Ukazuje **jen účty s aspoň jednou hotovou položkou**
- Hotové = velké, zelené, klikatelné → klik = servírováno (zmizí z výdeje)
- Vařící se / nepřipravené = šedé, neklikatelné (info pro číšníka co ještě dojde)
- **Tisk pickup bonu** — tlačítko per účet vytiskne kitchen ticket: stůl + hotové položky + čas
- "Vše odneseno" tlačítko pro hromadné označení
- Zelený sunrise gradient (vizuálně odlišný od oranžového KDS)
- Zvukový ding-dong na novou hotovou položku
- Wake-lock + auto-refresh 8 s

**Restaurace → Provoz hub — 3 karty pro multi-screen setup**
- 👨‍🍳 KDS (oranžová karta) — pro kuchaře
- 📤 Výdej (zelená karta) — pro číšníka u pass
- 🗺️ Floor plan editor
- 🧾 POS Kasa
- Tip pro 3+ monitor setup (KDS + Výdej + Provoz + POS)

### 🐛 Opravy

- Odkazy mezi displeji: KDS ↔ Výdej ↔ Provoz ↔ Admin v každém footeru

---

## [3.0.3] — 2026-05-25

### ✨ Vylepšení

**KDS (Kuchyňský displej) — nová hlavička**
- Pozadí změněno z tmavé na světlý sunrise gradient (amber → orange) — pozitivnější vibe pro kuchyňský provoz
- **OBROVSKÉ číselné staty** (68px) — čitelné ze 3+ metrů přes celou kuchyň
- Staty přepracovány na widget karty: ikona, count-up animace, hover lift + shadow
- **Klik na widget = filtr** — kliknu na "Vaří se" → vidím jen účty s vařícími se položkami; opakovaný klik filtr zruší
- Pulsing dot indikátor na widgetech kde se něco děje (Účtů > 0, Vaří se > 0)
- Hodiny zvětšeny na 48px + přidáno datum (Po 25. kvě)
- Color-coding čísel: Účtů modrá, Položek fialová, Vaří se oranžová, Hotových zelená
- Responsive: tablet stack pod hlavičku, mobil 2×2 grid s menšími čísly

---

## [3.0.0] — 2026-05-25 — 🎉 OFFICIAL LAUNCH

První veřejná verze APPEK B2B. Kulminace 400+ commitů a 2 měsíců intenzivního vývoje.

### ✨ Nové funkce

**Pokladna (POS)**
- Nový kasový modul s PIN přihlášením prodavačů (4 uživatelé)
- Velké, dotykově optimalizované rozhraní pro tablet i MacBook 13"
- Editovatelné rychlé volby pro volný řádek (korkovné, obal, sleva)
- Sleva a spropitné se ukládají jako samostatné řádky → vidí je admin v detailu objednávky
- Klikatelné účtenky s detailem v modálním okně
- Drafty rozpracovaných košíků (localStorage 20-item) + auto-resume po přepnutí prodavače
- 6 platebních metod (hotově, karta, PayPal, gift card, voucher, mobile)
- 4 typy objednávky (sebou, na místě, vyzvednutí, rozvoz)
- Idempotency key proti duplikátům při retry
- Kompaktní launcher Kasy v adminu s dnešními prodeji + TOP položkami

**Sklady (multi-warehouse)**
- Správa více skladů — CRUD, přepínání, oddělené přehledy
- Pivot „suroviny + výrobky per sklad" pro jasný stav zásob
- Pohyby: příjem, výdej, inventura, korekce, přesun (FOR UPDATE proti race)
- Per-sklad exporty: PDF / CSV / XML / JSON
- Porovnání skladů + UI pro přesuny

**Platby online**
- Stripe Checkout s validací klíčů + test connection v nastavení
- GoPay + PayPal jako alternativní brány
- Centrální správa platebních metod (POS + B2B + checkout)
- Webhook + session_verify polling fallback

**Dashboard a přehledy**
- Layout: Tržby 75 % + Dnešek 25 %, druhý řádek 50/50
- Sparkliny jako watermark pod hlavní částkou (Stripe/Linear styl)
- Widget „Akce vyžadující pozornost" s klikatelnými alerty
- Sjednocené period taby napříč aplikací

**Výroba a kalkulace**
- Hub „Výroba" s 5 sub-taby (Vyrobeno / Výrobní list / Suroviny / Kalkulace / HACCP)
- Step-by-step UI klonek výpočtu
- Composite ingredient recurse (kompozitní suroviny — Diasauer, směsi)
- Pre-check + lock + dual-write do sklad_pohyby_v2

**Štítky a katalog**
- 7 prvků pro nutriční hodnoty na štítku (Energie kJ/kcal, Tuky, Sacharidy, Bílkoviny, Sůl)
- EU 1169/2011 tabulka per 100 g

**Onboarding a demo**
- Funkční „WOW" demo seed (10 výrobků, 35 surovin, 10 receptů, 5 kalkulací, 5 odběratelů, POS users, stoly)
- Při onboardingu volba **ano / ne** pro demo data
- Reset demo skryt v Údržbě (proti omylem kliku)
- Merge režim — doplní jen chybějící data
- 14 dnů historie objednávek pro funkční grafy

**Navigace a UX**
- Globální Cmd+K vyhledávání
- Mobilní hamburger menu + bottom-nav
- Cesty zpět na 100 % sub-stránek
- Mobile period tabs zkrácené (Týden místo Tento týden)

**Notifikace**
- Centrální panel v Dashboard stylu
- Seskupené notifikace, klikatelné s navigací
- Červená tečka u upravených dokladů

### 🔒 Bezpečnost

- **`app_errors` DB tabulka** — každý error logger zapíše s request_id + plný exception trace + user kontext
- **Admin Logs viewer** v Diagnostice — search podle reqId
- **POS frontend error capture** (předtím chyběl)
- **request_id v error toastech** — propojení frontend ↔ backend
- **Healthcheck endpoint** (`/api/healthcheck.php`) — 7 checks (DB / schema / write / disk / file / error_rate / PHP runtime)
- **Proaktivní monitoring** — cron-callable, auto-notif při alert/spike
- **Dashboard top banner** sekundy po incidentu
- **41 endpoints** migrováno z `json_error($e->getMessage())` na `json_error_safe()` (zabraňuje information disclosure)
- **Stored XSS fix** v POS preset names (JSON.stringify v single-quoted attribute)
- **Privilege escalation fixes** — `fix_demo_users` guard, `quick_order_detail` puvod check
- **Input bounds** v POS quick_order (DoS guards: ≤200 položek, tip 0-100k, sleva 0-100%, mn 0-9999)
- **Sklad lock** proti race conditions (sorted ID lock order, FOR UPDATE)
- **Idempotency** (POS finish + retry guard)
- **Auto-rollback** při selhání self-update
- **session_secure_start** sjednoceno (APPEKSID cookie)
- 2FA TOTP, CSRF tokens, license-key gating, Argon2id

### 🚀 Výkon

- **Lazy i18n loading** — CS users (~80 % bázi) předtím tahali 4 MB JS zbytečně. Teď CS = 0 MB extra
- **SQL range scan** místo DATE() wrap — 5-50× rychlejší při >10k řádků
- **Promise.all** parallel fetch v POS launcher (úspora ~150-300 ms)
- Lazy DDL — chybějící sloupce se doplní jen v momentě, kdy jsou potřeba
- Cache-bust verze CSS i JS automaticky v release skriptu
- Defenzivní fallbacky proti broken API responses

### 🌍 Lokalizace

- 5 jazyků: 🇨🇿 čeština · 🇸🇰 slovenčina · 🇬🇧 angličtina · 🇪🇸 španělština · 🇩🇪 němčina
- 18 000+ frází napříč moduly
- B2B portál: kompletní pokrytí EN/ES/SK/DE
- Frontpage fallback chain (SK → CS, DE → EN)
- Lazy load i18n bundles + runtime dynamic load při přepnutí

### 🐛 Důležité opravy

- Sidebar se rozbil při jediné položce (flex chování)
- POS cart row v MacBook 13 nebyl viditelný (BIG MODE CSS bez media query)
- „Akce vyžadující pozornost" + bell nyní klikatelné
- POS účtenky klikatelné s detail modálním oknem
- Demo seed 500 fix (DDL implicit commit v MySQL)
- Aktualizace selhávala s `admin_session_required` (oprava session konfliktu)
- Mobile period taby — nikdy nezalamovat, plynulý shrink
- Defenzivní fallbacky pro `data.faktury`, `obdobi_stats`, `data.pocet`
- Otevírací doba odstraněna z landing page (matoucí pro SaaS)

### 🛠️ Pro vývojáře / správce

- **GitHub Actions CI/CD** — `release.yml` build + deploy s retry logic
- **`scripts/release.sh`** pro jednopříkazové vydání
- **Self-update modul** s SHA256 verifikací + auto-rollback při selhání
- **`download.php`** v rootu — license-gated download pro customers
- **Install.php self-delete** button + auto-chmod 0600 na config.local.php
- **`scripts/sync-local.sh`** pro multi-device vývoj
- **`SETUP-IMAC.md`** pro nový dev stroj
- BSD sed kompatibilita ve `release.sh` (macOS friendly)

---

## Pre-release historie

**v2.9.x** — interní vývoj a beta testování (327+ commitů, neveřejné)
**v2.0.x – v2.6.x** — alpha / private beta

Detailní git historie: [GitHub Releases](https://github.com/Chossekilla/appek/releases)
