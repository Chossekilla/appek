# Changelog

Všechny významné změny v APPEK B2B.

Formát: [Keep a Changelog](https://keepachangelog.com/cs/) · [Semantic Versioning](https://semver.org/lang/cs/).

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
