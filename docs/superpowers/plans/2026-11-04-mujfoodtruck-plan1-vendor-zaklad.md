# Můj Foodtruck — Plán 1: základ ve vendoru (produkt, MFT klíče, přepínač, nastavení)

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Vendor (vendor.appek.cz) rozlišuje produkty Appek / Můj Foodtruck: sloupec `product`, generátor MFT klíčů, přepínač nahoře, filtrované seznamy/KPI, izolované kupony a nastavení foodtrucku — bez jakékoli změny toho, co dnes vidí a dělá Appek.

**Architecture:** Dvě nové malé knihovny ve `vendor/` (`_license_mft.php` s prefixem funkcí `mft_`, `_product.php` s helpery a migrací), napojené do `vendor_db()`, `_layout.php`, `api.php`, `app.js` a stránek se seznamy. Filtr produktu je čistá funkce vracející whitelistovaný SQL fragment s `COALESCE(product,'appek')`, takže stará data = appek.

**Tech Stack:** PHP 8 (vanilla), MySQL/MariaDB, vanilla JS. Testy = CLI PHP skripty ve `vendor/tests/` (vzor `test_self_update.php`, exit 0 = OK). Lokální PHP: `/Applications/XAMPP/xamppfiles/bin/php` (v PATH není `php`).

**Spec:** `docs/superpowers/specs/2026-11-04-mujfoodtruck-prodejni-kanal-design.md`

## Roadmapa (celý projekt = 5 plánů, každý samostatně otestovatelný)

1. **Tento plán** — základ ve vendoru.
2. **Prodej na mujfoodtruck.cz** — pokladna, GoPay (stejný GoID, notifikace/návrat na mujfoodtruck.cz, `MFT-ORD-…`), callback → MFT licence, foodtruck e-maily, faktury řady `MFT-RRRR-NNN`, portál `muj.…`, připomínky. Repo foodtrucku, celek „web produktu“, přímé DB připojení k vendor DB.
3. **Licence v aplikaci foodtruck** — zrušit bypass, heartbeat na mujfoodtruck.cz s plnou paritou `vendor/heartbeat.php`, hard-lock s foodtruck hláškou, instalátor s `MFT-` klíčem.
4. **Distribuce** — build zákaznického ZIPu bez webu produktu a bez vendor-DB kódu, stahování podle klíče, vlastní podepsaný update feed (vlastní RSA pár).
5. **Rebrand aplikace** — viditelné texty/vzhled všech obrazovek (admin, kasa, KDS, instalátor, B2B, hlášky), demo `demo.mujfoodtruck.cz`.

Plány 2–5 se napíšou po dokončení tohoto (stojí na jeho rozhraních níže).

## Global Constraints

- Produkty: přesně `appek` a `foodtruck`; režim pohledu navíc `all`. Default všude `appek`.
- Sloupec: `product VARCHAR(16) NOT NULL DEFAULT 'appek'` v `vendor_licenses`, `vendor_shop_orders`, `vendor_coupons`, `vendor_invoices`. Filtrovat vždy přes `COALESCE(product,'appek')`.
- MFT klíč: `MFT-XXXX-XXXX-XXXX-CCCC`, random skupiny z `ABCDEFGHJKLMNPQRSTUVWXYZ23456789`, checksum = první 4 znaky HMAC-SHA256(body, `MFT_LICENSE_SALT`) velkými písmeny. Salt je JINÝ než `LICENSE_SALT`.
- Foodtruck licence: vždy `rental=1`, žádné balíčky; výchozí `expires_at` = dnes + 1 měsíc.
- Žádná změna chování pro `product='appek'`: stejné klíče, stejné výchozí platnosti (+1 rok), stejné číslování faktur `RRRRNNN`.
- Nenačítat dvě knihovny se stejnými globálními funkcemi v jednom requestu (fatal redeclare) — MFT funkce mají prefix `mft_`.
- Hesla/klíče nikdy do gitu ani do chatu.
- Komentáře a UI texty česky, tykání.

## Review Focus

1. **Stará data bez produktu** (řádky před migrací / NULL) → musí se chovat jako `appek` a v pohledu Appek být vidět. Test v Task 2.
2. **Podvržený `?product=`** (např. `foodtruck' OR 1=1`) → normalizace na `appek`, žádný SQL fragment z uživatele. Test v Task 2.
3. **Foodtruck kupon zadaný v appek pokladně** (a naopak) → neplatný. Test v Task 5.
4. **MFT klíč podstrčený appek validátoru** (heartbeat, install) a APPEK klíč MFT validátoru → `false`. Test v Task 1.
5. **Generování foodtruck licence s `packages` v payloadu** → balíčky ignorovány, klíč MFT, `rental=1`. Test v Task 3.

---

### Task 1: Generátor a validátor MFT klíčů

**Files:**
- Create: `vendor/_license_mft.php`
- Test: `vendor/tests/test_license_mft.php`

**Interfaces:**
- Produces: `const MFT_LICENSE_SALT` (string), `mft_license_format_valid(string $key): bool`, `mft_license_checksum(string $body): string`, `mft_license_valid(string $key): bool`, `mft_license_generate(): string`. Soubor se v Plánu 2/3 1:1 zkopíruje do repa foodtrucku — nesmí nic includovat.

- [ ] **Step 1: Napiš failing test** `vendor/tests/test_license_mft.php`: `require` obou `../_license.php` i `../_license_mft.php` (ověřuje, že nedojde k redeclare), pak asserty (vlastní mini `check($cond, $msg)`, na konci `exit($fails ? 1 : 0)`):

```php
$k = mft_license_generate();
check(preg_match('/^MFT-[A-Z2-9]{4}-[A-Z2-9]{4}-[A-Z2-9]{4}-[A-Z0-9]{4}$/', $k) === 1, 'tvar klíče');
check(mft_license_valid($k), 'vygenerovaný je platný');
check(mft_license_valid('  ' . strtolower($k) . "\n"), 'trim + case-insensitive');
$bad = substr($k, 0, -1) . (substr($k, -1) === 'A' ? 'B' : 'A');
check(!mft_license_valid($bad), 'změněný checksum neplatí');
check(!mft_license_valid('MFT-AAAA-BBBB-CCCC'), 'chybí skupina');
$appek = license_generate();
check(!mft_license_valid($appek), 'APPEK klíč v MFT neplatí');
check(!license_valid($k), 'MFT klíč v APPEK validátoru neplatí');
check(MFT_LICENSE_SALT !== LICENSE_SALT, 'jiný salt');
for ($i = 0; $i < 200; $i++) check(mft_license_valid(mft_license_generate()), 'opakované generování');
```

- [ ] **Step 2: Spusť, musí selhat**
Run: `/Applications/XAMPP/xamppfiles/bin/php vendor/tests/test_license_mft.php`
Expected: fatal „Failed opening required … _license_mft.php“.

- [ ] **Step 3: Implementuj `vendor/_license_mft.php`** podle Global Constraints. `MFT_LICENSE_SALT` = nový náhodný řetězec ve tvaru `mujfoodtruck-2026-` + 10 náhodných znaků (vygeneruj, ne opisuj appek). Formát regex: random skupiny `[A-Z2-9]{4}`, checksum `[A-Z0-9]{4}`; validace přes `hash_equals`. Hlavička s poznámkou „friction not security“ jako u appek.

- [ ] **Step 4: Spusť test, musí projít**
Expected: exit 0, žádný FAIL řádek.

- [ ] **Step 5: Commit**
```bash
git add vendor/_license_mft.php vendor/tests/test_license_mft.php
git commit -m "vendor: MFT licenční klíče pro Můj Foodtruck"
```

### Task 2: Produkt — helpery, migrace, přepínač v menu

**Files:**
- Create: `vendor/_product.php`
- Modify: `vendor/_lib.php` (require `_product.php`; v `vendor_db()` volat `vendor_ensure_product_columns($pdo)` za `vendor_ensure_update_signature_column`)
- Modify: `vendor/_layout.php` (`vendor_nav_counts()` ř.120–129 filtrovat; přepínač pod `.vx-brand` v `.vx-top` ř.~237; `<script>window.VENDOR_PRODUCT=…</script>`)
- Modify: `vendor/style.css` nebo `vendor/ui.css` (styl přepínače — segmentovaný ovladač, bez emoji)
- Test: `vendor/tests/test_product.php`

**Interfaces:**
- Produces:
  - `const VENDOR_PRODUCTS = ['appek' => 'Appek', 'foodtruck' => 'Můj Foodtruck'];`
  - `vendor_product_normalize(?string $p, bool $allowAll = true): string` — vrací `appek|foodtruck|all`; cokoli jiného → `appek`.
  - `vendor_product_sql(string $product, string $col = 'product'): string` — `all` → `'1=1'`; jinak `"COALESCE($col,'appek') = '$product'"` jen pro whitelistovaný `$product` (normalizuje uvnitř) a `$col` odpovídající `/^[a-z_.]+$/` (jinak `InvalidArgumentException`).
  - `vendor_product_current(): string` — `?product=` (normalizovaný) uloží do `$_SESSION['vendor_product']`; jinak vrátí uloženou hodnotu; default `appek`. Volat až po `vendor_session_start()`.
  - `vendor_product_for_write(): string` — jako current, ale `all` → `appek` (pro INSERT).
  - `vendor_ensure_product_columns(PDO $pdo): void` — idempotentně přidá sloupec do 4 tabulek (každá tabulka v samostatném try, chybějící tabulka se přeskočí), `static $done`.

- [ ] **Step 1: Failing test** `vendor/tests/test_product.php` (require jen `../_product.php`, ten nesmí includovat `_lib.php`):

```php
check(vendor_product_normalize('foodtruck') === 'foodtruck', 'ft');
check(vendor_product_normalize('all') === 'all', 'all');
check(vendor_product_normalize('all', false) === 'appek', 'all zakázán');
check(vendor_product_normalize("foodtruck' OR 1=1") === 'appek', 'injekce → appek');
check(vendor_product_normalize(null) === 'appek', 'null');
check(vendor_product_sql('all') === '1=1', 'all sql');
check(vendor_product_sql('foodtruck', 'l.product') === "COALESCE(l.product,'appek') = 'foodtruck'", 'ft sql');
check(vendor_product_sql('nesmysl') === "COALESCE(product,'appek') = 'appek'", 'neznámý → appek');
try { vendor_product_sql('appek', 'x; DROP'); check(false, 'col injekce'); } catch (InvalidArgumentException $e) { check(true, 'col injekce'); }
$_SESSION = []; $_GET = ['product' => 'foodtruck'];
check(vendor_product_current() === 'foodtruck' && $_SESSION['vendor_product'] === 'foodtruck', 'uloží do session');
$_GET = [];
check(vendor_product_current() === 'foodtruck', 'pamatuje si');
$_SESSION['vendor_product'] = 'all';
check(vendor_product_for_write() === 'appek', 'all → zápis appek');
```

- [ ] **Step 2: Spusť, musí selhat** (`…/php vendor/tests/test_product.php` → missing file).
- [ ] **Step 3: Implementuj `vendor/_product.php`** dle Interfaces.
- [ ] **Step 4: Test projde** (exit 0).
- [ ] **Step 5: Napoj do `_lib.php` a `_layout.php`.** Přepínač = tři odkazy „Appek / Můj Foodtruck / Vše“ na aktuální stránku s `?product=…` (zachovej ostatní query parametry kromě `product`), aktivní zvýrazněný. `vendor_nav_counts()` přidá `AND ` + `vendor_product_sql(vendor_product_current())` do dotazů na `vendor_shop_orders` a `vendor_licenses` (pirate nefiltrovat). Vypiš `window.VENDOR_PRODUCT`.
- [ ] **Step 6: Syntaxe** `…/php -l` na změněné PHP soubory → „No syntax errors“.
- [ ] **Step 7: Commit** `vendor: produkt Appek/Můj Foodtruck — helpery, migrace, přepínač`.

### Task 3: Licence — seznam, statistiky, generování podle produktu

**Files:**
- Modify: `vendor/api.php` (`list` ř.35–62, `stats` ř.65–84, `generate` ř.87–139, `reissue` ř.177)
- Modify: `vendor/app.js` (formulář generování + seznam licencí)
- Modify: `vendor/_product.php` (nová čistá funkce níže)
- Test: `vendor/tests/test_product.php` (rozšířit)

**Interfaces:**
- Consumes: Task 1 `mft_license_generate()`, Task 2 `vendor_product_current()`, `vendor_product_for_write()`, `vendor_product_sql()`.
- Produces: `vendor_license_defaults(string $product, array $d, string $today): array` → `['product'=>…, 'packages'=>array, 'rental'=>0|1, 'expires_at'=>'Y-m-d']`.

- [ ] **Step 1: Rozšiř test** o:

```php
$ft = vendor_license_defaults('foodtruck', ['packages' => ['cukrarna'], 'rental' => 0], '2026-11-04');
check($ft['packages'] === [] && $ft['rental'] === 1 && $ft['expires_at'] === '2026-12-04' && $ft['product'] === 'foodtruck', 'ft defaulty');
$ft2 = vendor_license_defaults('foodtruck', ['expires_at' => '2027-03-01'], '2026-11-04');
check($ft2['expires_at'] === '2027-03-01', 'ft ruční datum');
$ap = vendor_license_defaults('appek', ['packages' => ['cukrarna', 'xyz'], 'rental' => 1], '2026-11-04');
check($ap['packages'] === ['cukrarna'] && $ap['rental'] === 1 && $ap['expires_at'] === '2027-11-04' && $ap['product'] === 'appek', 'appek beze změny');
check(vendor_license_defaults('appek', [], '2026-01-31')['expires_at'] === '2027-01-31', 'appek +1 rok');
check(vendor_license_defaults('foodtruck', [], '2026-01-31')['expires_at'] === '2026-02-28', 'ft +1 měsíc konec měsíce');
```
(Filtr známých balíčků pro appek = `LICENSE_PACKAGE_BITS`; test proto `require` i `../_license.php`. Pro +1 měsíc nepřetékat do dalšího měsíce — 31.1. → 28./29.2.)

- [ ] **Step 2: Spusť, musí selhat** (undefined function).
- [ ] **Step 3: Implementuj `vendor_license_defaults()`** v `_product.php` (funkce smí volat `LICENSE_PACKAGE_BITS` jen pokud je definovaná).
- [ ] **Step 4: Test projde.**
- [ ] **Step 5: Uprav `api.php`:** `list` a `stats` přidají `vendor_product_sql(vendor_product_current())` do WHERE (u `stats` ke každému dotazu). `generate`: `$product = vendor_product_normalize($d['product'] ?? vendor_product_for_write(), false)`; hodnoty z `vendor_license_defaults()`; klíč `foodtruck` → `mft_license_generate()`, jinak dnešní `license_generate_with_packages()`; INSERT navíc `product`. `reissue` u klíče začínajícího `MFT-` → `vendor_json_error('Licence Můj Foodtruck nemá balíčky — reissue není potřeba')`. `require_once __DIR__.'/_license_mft.php'` nahoře.
- [ ] **Step 6: Uprav `app.js`:** generování posílá `product: window.VENDOR_PRODUCT === 'all' ? 'appek' : window.VENDOR_PRODUCT`; při `foodtruck` skryje výběr balíčků a přepínač Pronájem (je vždy zapnutý) a nadpis formuláře „Nová licence — Můj Foodtruck“. V seznamu u řádku s `product==='foodtruck'` štítek „Foodtruck“ (zobrazuje se jen v režimu Vše).
- [ ] **Step 7: Ověření** — `…/php -l vendor/api.php`, `node --check vendor/app.js`, testy Task 1–3 exit 0.
- [ ] **Step 8: Commit** `vendor: licence podle produktu (MFT klíče pro foodtruck)`.

### Task 4: Přehled, objednávky, faktury, kupony — filtr podle produktu

**Files:**
- Modify: `vendor/index.php` (KPI ř.74–86, seznamy ř.122–126)
- Modify: `vendor/shop.php` (seznam ř.~142, statistiky ř.161–165)
- Modify: `vendor/faktury.php` (seznam ř.495; INSERT nové faktury ukládá `product = vendor_product_for_write()`)
- Modify: `vendor/shop-coupons.php` (seznam ř.75 filtr; formulář nového kuponu má select Produkt předvyplněný z `vendor_product_for_write()`; INSERT ukládá `product`; sloupec Produkt v tabulce v režimu Vše)

**Interfaces:**
- Consumes: Task 2 `vendor_product_current()`, `vendor_product_sql()`, `vendor_product_for_write()`.

- [ ] **Step 1:** Každý `SELECT … FROM vendor_shop_orders|vendor_licenses|vendor_invoices|vendor_coupons` na těchto stránkách (seznamy, KPI, todo) doplň o `vendor_product_sql(vendor_product_current())` (s aliasem tam, kde je JOIN). Detail jedné objednávky/faktury podle `id` NEfiltruj (odkaz musí fungovat i z jiného pohledu). `faktury_next_cislo()` neměnit (foodtruck řada vznikne v Plánu 2 a nekoliduje — nezačíná rokem).
- [ ] **Step 2: Syntaxe** `…/php -l` na 4 soubory.
- [ ] **Step 3: Commit** `vendor: přehled/objednávky/faktury/kupony filtrované podle produktu`.

### Task 5: Kupony izolované podle produktu

**Files:**
- Modify: `api/_shop_coupon_lib.php` (`shop_coupon_ensure_table()` ř.14 — přidat sloupec `product`; `shop_coupon_eval()` ř.34)
- Test: `vendor/tests/test_coupon_product.php`

**Interfaces:**
- Produces: `shop_coupon_eval(PDO $pdo, string $code, float $subtotal, string $product = 'appek'): array` (návratový tvar beze změny). Dotaz: `… WHERE kod = :k AND COALESCE(product,'appek') = :p`. Stávající volání v `api/shop_buy.php` a `api/shop_coupon.php` zůstávají bez 4. argumentu = `appek`. Plán 2 volá s `'foodtruck'`.

- [ ] **Step 1: Failing test** — SQLite in-memory PDO, `CREATE TABLE vendor_coupons` se sloupci jako v `vendor/shop-coupons.php` ř.17 + `product TEXT NOT NULL DEFAULT 'appek'`; vlož `FT10` (percent 10, product foodtruck) a `AP10` (percent 10, bez product → default appek). Asserty:

```php
check(shop_coupon_eval($pdo, 'FT10', 1000)[0] === false, 'ft kupon v appek pokladně neplatí');
check(shop_coupon_eval($pdo, 'FT10', 1000, 'foodtruck')[0] === true, 'ft kupon ve ft pokladně platí');
check(shop_coupon_eval($pdo, 'AP10', 1000)[0] === true, 'appek kupon beze změny');
check(shop_coupon_eval($pdo, 'AP10', 1000, 'foodtruck')[0] === false, 'appek kupon ve ft neplatí');
check(abs(shop_coupon_eval($pdo, 'FT10', 1000, 'foodtruck')[3] - 100.0) < 0.001, 'sleva 10 %');
```
Pokud `shop_coupon_eval` používá MySQL-only SQL, které SQLite neumí, vytáhni dotaz do `shop_coupon_find(PDO $pdo, string $code, string $product): ?array`, eval ho použije a test ověří přes eval; rozhraní eval zůstává.

- [ ] **Step 2: Spusť, musí selhat** (4. argument ignorován → FT10 platí v appek).
- [ ] **Step 3: Implementuj** dle Interfaces; `shop_coupon_ensure_table()` přidá `product` idempotentně (SHOW COLUMNS vzor).
- [ ] **Step 4: Test projde.**
- [ ] **Step 5: Commit** `kupony: izolace podle produktu (appek default)`. Pozn.: `api/` se na apex dostane releasem (git tag) — viz Task 7.

### Task 6: Nastavení „Můj Foodtruck“

**Files:**
- Modify: `vendor/settings.php` (nový blok vedle e-mail bloku ř.~204, stejný `$keys` vzor + vlastní karta)

**Interfaces:**
- Produces (klíče `vendor_settings`, Plány 2–4 je čtou): `ft_price_month_kc`, `ft_price_year_kc`, `ft_web_url`, `ft_portal_url`, `ft_mail_from_email`, `ft_mail_from_name`, `ft_invoice_prefix` (default `MFT`), `ft_supplier_name`, `ft_supplier_ico`, `ft_supplier_dic`, `ft_supplier_address`, `ft_supplier_bank_account`, `ft_supplier_iban`.

- [ ] **Step 1:** Karta „Můj Foodtruck“ s poli výše (ceny čísla ≥ 0; URL `FILTER_VALIDATE_URL` nebo prázdné; e-mail `FILTER_VALIDATE_EMAIL` nebo prázdný; při chybě flash chyba a nic se neuloží). Popisky česky; u roční ceny nápověda „prázdné = 12 × měsíční“. CSRF jako ostatní bloky.
- [ ] **Step 2: Syntaxe** `…/php -l vendor/settings.php`.
- [ ] **Step 3: Commit** `vendor: nastavení Můj Foodtruck (ceny, odesílatel, dodavatel faktur)`.

### Task 7: Nasazení a ověření živě

**Files:** žádné nové.

- [ ] **Step 1: Všechny testy** `for t in vendor/tests/test_license_mft.php vendor/tests/test_product.php vendor/tests/test_coupon_product.php; do …/php $t || echo FAIL $t; done` → bez FAIL.
- [ ] **Step 2: Snímek „před“** — přes SSH `appek` na produkční vendor DB spočítej `COUNT(*)` licencí, objednávek, faktur, kuponů a součet `total_kc` zaplacených objednávek; ulož do scratchpadu (ne do repa).
- [ ] **Step 3: Nasazení vendoru** — `scp` změněných souborů `vendor/{_license_mft.php,_product.php,_lib.php,_layout.php,api.php,app.js,index.php,shop.php,faktury.php,shop-coupons.php,settings.php}` + CSS do `~/domains/appek.cz/public_html/vendor/` (`vendor/tests/` nenasazovat).
- [ ] **Step 4: Ověření v prohlížeči** (screenshot povinný): Přehled v režimu Appek → čísla = snímek „před“; přepnout na Můj Foodtruck → nuly, prázdné seznamy; vygenerovat testovací foodtruck licenci → klíč `MFT-…`, pronájem, platnost +1 měsíc; v režimu Appek není vidět; pak ji revoke. Nastavení Můj Foodtruck uložit a znovu načíst.
- [ ] **Step 5: Kupony na apexu** — `api/_shop_coupon_lib.php` se nasadí appek releasem (`git tag vX.Y.Z && git push origin vX.Y.Z`), po ~2 min ověřit `api/version.php`; existující appek kupon v checkoutu dál funguje.
- [ ] **Step 6:** Push commitů a zápis výsledku ověření.

---

## Self-review (provedeno)

- Pokrytí spec pro tento plán: rozhodnutí 1 (sloupec), 2 (MFT klíč), 3 (ceny → nastavení), 11 (přepínač, ruční vydání, nastavení), 13 (kupony) → Tasky 1–6. Ostatní body spec → Plány 2–5 (roadmapa).
- Anti-cíl „appek beze změny“: default `appek`, `COALESCE`, test appek defaultů (Task 3), snímek před/po (Task 7).
