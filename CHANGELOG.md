# Changelog

Všechny významné změny v APPEK B2B.

Formát: [Keep a Changelog](https://keepachangelog.com/cs/) · [Semantic Versioning](https://semver.org/lang/cs/).

---

## [3.0.0] — 2026-06-XX — 🎉 OFFICIAL LAUNCH

První veřejná verze APPEK B2B. Kulminace 380+ commitů a 2 měsíců intenzivního vývoje.

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
