# Landing A/B přepínač — infrastruktura (fáze 1) — implementační plán

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Na appek.cz zprovoznit přepínání mezi dvěma variantami landing page, ovládané z vendor panelu.

**Architecture:** Root `index.php` se na MASTER serveru stane routerem, který podle runtime souboru `api/landing-variant.txt` pošle na `/` jednu ze dvou statických variant (`index-apple.html` / `index-classic.html`) přes `readfile`. Čistá rozhodovací logika je v nové knihovně `api/_landing.php` (unit-testovaná). Nová vendor stránka `vendor/landing.php` zapisuje volbu.

**Tech Stack:** PHP 8.0+ (vanilla, bez frameworku), statické HTML, Apache (`.htaccess` `DirectoryIndex`), bash build skript. Žádný test framework — testuje se samostatným PHP skriptem a `curl`.

---

## Kontext a rozsah

Tento plán pokrývá **fázi 1 ze 3** dle specifikace
`docs/superpowers/specs/2026-05-22-appek-landing-ab-redesign-design.md` — tj. *infrastrukturu přepínače*.

- **Fáze 1 (tento plán):** router, knihovna, vendor přepínač, dvě varianty jako dočasné kopie dnešní stránky. Po fázi 1 je web plně funkční — přepínač funguje, obě polohy zatím ukazují dnešní design.
- **Fáze 2 (Apple) a fáze 3 (Klasik):** vizuální redesign obou variant. Jde o iterativní designovou práci ověřovanou screenshoty — dostanou vlastní zpracování, až na ně dojde řada. Nejsou součástí tohoto plánu. Tehdy se také rozšíří kontrola `scripts/css-braces.py` o inline `<style>` nových variant (dle spec §8); ve fázi 1 to není potřeba — varianty jsou zatím kopie funkční stránky.

**Důležité — souběžné editace:** repozitář upravují i další automatizované smyčky a linter přepisuje soubory. Před každou editací znovu ověř stav souboru. Nikdy nepoužívej `git add -A` / `git add .` — stage vždy jen soubory, které daný úkol jmenuje. Soubor `index.html` může mít necommitnuté cizí změny; to je v pořádku — jeho aktuální stav se „zmrazí" do `index-legacy.html`.

**Lokální prostředí (pro ověřovací kroky):** XAMPP, projekt servírovaný na `http://localhost/appek/`. PHP binárka: `/Applications/XAMPP/xamppfiles/bin/php`. Před `curl` kroky musí běžet XAMPP Apache.

---

## File Structure

| Soubor | Akce | Odpovědnost |
|---|---|---|
| `api/_landing.php` | Create | Čistý resolver varianty + IO helpery; interní lib (`.htaccess` blokuje `api/_*.php`) |
| `scripts/test-landing.php` | Create | Bez-frameworkový test `api/_landing.php` (`scripts/` je vyloučen z obou ZIPů) |
| `index.php` | Modify | MASTER větev → router varianty |
| `index.html` | Rename → `index-legacy.html` | Zmražená záloha dnešní stránky (noindex) |
| `index-apple.html` | Create | Varianta A (fáze 1: kopie legacy; redesign ve fázi 2) |
| `index-classic.html` | Create | Varianta B (fáze 1: kopie legacy; redesign ve fázi 3) |
| `vendor/landing.php` | Create | Přepínač aktivní varianty pro operátora |
| `vendor/_layout.php` | Modify | Položka „Landing A/B" v navigaci vendoru |
| `.gitignore` | Modify | Ignorovat runtime soubor s volbou |
| `build-zip.sh` | Modify | Varianty do MASTER ZIPu, ne do CUSTOMER; runtime soubor mimo ZIPy i deploy manifest |
| `api/landing-variant.txt` | (runtime, ne v gitu) | Uložená volba; vytvoří ji vendor stránka při prvním uložení |

---

## Task 1: Knihovna `api/_landing.php` — resolver varianty (TDD)

**Files:**
- Create: `scripts/test-landing.php`
- Create: `api/_landing.php`

- [ ] **Step 1: Napsat selhávající test**

Create `scripts/test-landing.php`:

```php
<?php
/**
 * Testy pro api/_landing.php — resolver A/B landing varianty.
 * Spuštění:  /Applications/XAMPP/xamppfiles/bin/php scripts/test-landing.php
 * Bez frameworku. Exit 1 při jakémkoli selhání.
 */
require __DIR__ . '/../api/_landing.php';

$pass = 0; $fail = 0;
function check(string $label, $got, $want): void {
    global $pass, $fail;
    if ($got === $want) { $pass++; echo "  ✓ $label\n"; }
    else { $fail++; echo "  ✗ $label — got " . var_export($got, true)
                       . ", want " . var_export($want, true) . "\n"; }
}

echo "appek_landing_normalize:\n";
check('platná apple', appek_landing_normalize('apple'), 'apple');
check('velká písmena + mezery → classic', appek_landing_normalize('  CLASSIC '), 'classic');
check('hodnota s newline', appek_landing_normalize("apple\n"), 'apple');
check('neplatná → null', appek_landing_normalize('bogus'), null);
check('prázdná → null', appek_landing_normalize(''), null);
check('non-string → null', appek_landing_normalize(123), null);

echo "appek_landing_file:\n";
check('apple → soubor', appek_landing_file('apple'), 'index-apple.html');
check('classic → soubor', appek_landing_file('classic'), 'index-classic.html');

echo "appek_landing_resolve:\n";
check('uložená classic', appek_landing_resolve(null, 'classic'), 'classic');
check('uložená apple', appek_landing_resolve(null, 'apple'), 'apple');
check('nic uloženo → default apple', appek_landing_resolve(null, null), 'apple');
check('neplatné uloženo → default', appek_landing_resolve(null, 'xxx'), 'apple');
check('náhled přebíjí uložené', appek_landing_resolve('apple', 'classic'), 'apple');
check('náhled classic přebíjí', appek_landing_resolve('classic', 'apple'), 'classic');
check('neplatný náhled → padá na uložené', appek_landing_resolve('xxx', 'classic'), 'classic');

echo "appek_landing_read_raw / write / current (IO, temp dir):\n";
$tmp = sys_get_temp_dir() . '/appek-landing-test-' . getmypid();
@mkdir($tmp . '/api', 0777, true);
check('read bez souboru → null', appek_landing_read_raw($tmp), null);
check('write classic → true', appek_landing_write($tmp, 'classic'), true);
check('read po zápisu → classic', appek_landing_normalize(appek_landing_read_raw($tmp)), 'classic');
check('current() po zápisu → classic', appek_landing_current($tmp), 'classic');
check('write neplatné → false', appek_landing_write($tmp, 'bogus'), false);
check('current() bez souboru → default', appek_landing_current(sys_get_temp_dir() . '/appek-nope-' . getmypid()), 'apple');
@unlink(appek_landing_setting_path($tmp));
@rmdir($tmp . '/api'); @rmdir($tmp);

echo "\n" . ($fail === 0
    ? "✅ VŠE OK ($pass testů)\n"
    : "❌ SELHÁNÍ: $fail z " . ($pass + $fail) . "\n");
exit($fail === 0 ? 0 : 1);
```

- [ ] **Step 2: Spustit test — musí selhat**

Run: `/Applications/XAMPP/xamppfiles/bin/php scripts/test-landing.php`
Expected: FAIL — PHP fatal `require(): Failed opening required '.../api/_landing.php'` (soubor zatím neexistuje).

- [ ] **Step 3: Napsat knihovnu**

Create `api/_landing.php`:

```php
<?php
/**
 * 🎨 LANDING A/B — řešení a servírování variant marketingové stránky appek.cz.
 *
 * Dvě varianty: "apple" (index-apple.html), "classic" (index-classic.html).
 * Aktivní volba je v api/landing-variant.txt (runtime, gitignored).
 * Přepíná se z vendor/landing.php, servíruje z root index.php (MASTER větev).
 *
 * Interní lib — .htaccess blokuje přímý web přístup k api/_*.php.
 */

const APPEK_LANDING_VARIANTS = ['apple', 'classic'];
const APPEK_LANDING_DEFAULT  = 'apple';

/** Cesta k runtime souboru s uloženou volbou. */
function appek_landing_setting_path(string $root): string {
    return $root . '/api/landing-variant.txt';
}

/** Variantu mapuje na název HTML souboru (bez cesty). */
function appek_landing_file(string $variant): string {
    return $variant === 'classic' ? 'index-classic.html' : 'index-apple.html';
}

/** Vrátí platnou variantu, nebo null. Vstup může být cokoli (uživatelský / ze souboru). */
function appek_landing_normalize($value): ?string {
    if (!is_string($value)) return null;
    $v = strtolower(trim($value));
    return in_array($v, APPEK_LANDING_VARIANTS, true) ? $v : null;
}

/**
 * Rozhodne, kterou variantu zobrazit.
 *  - $queryRaw:   surová hodnota z ?variant= (náhled), nebo null
 *  - $settingRaw: surový obsah landing-variant.txt, nebo null
 * Náhled má přednost. Neplatné vstupy se ignorují, fallback je default.
 */
function appek_landing_resolve(?string $queryRaw, ?string $settingRaw): string {
    $preview = appek_landing_normalize($queryRaw);
    if ($preview !== null) return $preview;
    $saved = appek_landing_normalize($settingRaw);
    if ($saved !== null) return $saved;
    return APPEK_LANDING_DEFAULT;
}

/** Přečte surový obsah souboru s volbou. Vrátí string, nebo null když soubor není/nelze číst. */
function appek_landing_read_raw(string $root): ?string {
    $path = appek_landing_setting_path($root);
    if (!is_file($path)) return null;
    $raw = @file_get_contents($path);
    return $raw === false ? null : $raw;
}

/** Zapíše volbu na disk. Vrátí true při úspěchu, false při neplatné variantě / chybě zápisu. */
function appek_landing_write(string $root, string $variant): bool {
    if (appek_landing_normalize($variant) === null) return false;
    $path = appek_landing_setting_path($root);
    return @file_put_contents($path, $variant . "\n", LOCK_EX) !== false;
}

/** Aktuálně uložená aktivní varianta (bez náhledu). */
function appek_landing_current(string $root): string {
    return appek_landing_resolve(null, appek_landing_read_raw($root));
}

/**
 * MASTER index.php: vyřeší variantu a pošle její HTML na výstup.
 * Když je v URL ?variant=, jde o náhled → odpověď dostane noindex hlavičku.
 */
function appek_landing_serve(string $root): void {
    $query   = isset($_GET['variant']) ? (string)$_GET['variant'] : null;
    $variant = appek_landing_resolve($query, appek_landing_read_raw($root));
    $file    = $root . '/' . appek_landing_file($variant);

    if (!is_file($file)) {
        $legacy = $root . '/index-legacy.html';
        if (is_file($legacy)) {
            $file = $legacy;
        } else {
            header('Location: /vendor/');
            return;
        }
    }
    if (isset($_GET['variant'])) {
        header('X-Robots-Tag: noindex'); // náhledový režim — neindexovat
    }
    header('Content-Type: text/html; charset=UTF-8');
    readfile($file);
}
```

- [ ] **Step 4: Spustit test — musí projít**

Run: `/Applications/XAMPP/xamppfiles/bin/php scripts/test-landing.php`
Expected: PASS — `✅ VŠE OK (21 testů)`, exit kód 0.

- [ ] **Step 5: Commit**

```bash
git add api/_landing.php scripts/test-landing.php
git commit -m "feat: api/_landing.php — resolver A/B landing varianty + testy"
```

---

## Task 2: Dvě varianty landing page + zmražená záloha

**Files:**
- Rename: `index.html` → `index-legacy.html`
- Create: `index-apple.html`
- Create: `index-classic.html`

- [ ] **Step 1: Ověřit stav `index.html`**

Run: `git status --short index.html`
Expected: buď prázdné, nebo ` M index.html`. Modifikace je v pořádku (cizí souběžná práce) — zmrazí se do zálohy. Pokud soubor neexistuje, zastav a nahlas to.

- [ ] **Step 2: Přejmenovat na zálohu a vytvořit dvě kopie variant**

```bash
git mv index.html index-legacy.html
cp index-legacy.html index-apple.html
cp index-legacy.html index-classic.html
```

- [ ] **Step 3: Vložit marker varianty + SEO meta do `<head>` každého souboru**

Marker (HTML komentář) umožní rozlišit varianty i u shodného obsahu. Varianty dostanou
`canonical` na `/`, záloha dostane `noindex`.

```bash
PHP=/Applications/XAMPP/xamppfiles/bin/php
$PHP -r '$f="index-apple.html";$s=file_get_contents($f);$s=preg_replace("/<head>/","<head>\n<!-- APPEK landing variant: apple -->\n<link rel=\"canonical\" href=\"https://appek.cz/\">",$s,1);file_put_contents($f,$s);'
$PHP -r '$f="index-classic.html";$s=file_get_contents($f);$s=preg_replace("/<head>/","<head>\n<!-- APPEK landing variant: classic -->\n<link rel=\"canonical\" href=\"https://appek.cz/\">",$s,1);file_put_contents($f,$s);'
$PHP -r '$f="index-legacy.html";$s=file_get_contents($f);$s=preg_replace("/<head>/","<head>\n<!-- APPEK landing variant: legacy (frozen backup) -->\n<meta name=\"robots\" content=\"noindex\">",$s,1);file_put_contents($f,$s);'
```

- [ ] **Step 4: Ověřit markery**

Run: `grep -l 'APPEK landing variant' index-apple.html index-classic.html index-legacy.html`
Expected: vypíše všechny tři soubory.

Run: `grep -nE 'href="/?index\.html' index-apple.html index-classic.html`
Expected: žádný výstup (jde o single-page web, interní navigace běží přes `#kotvy`). Pokud se něco vypíše, jde o interní odkaz na starou stránku — v obou variantách nahraď `index.html` v daném `href` za `/`.

- [ ] **Step 5: Commit**

```bash
git add index-legacy.html index-apple.html index-classic.html
git commit -m "feat: dvě varianty landing page (apple/classic) + legacy záloha"
```

---

## Task 3: Router v `index.php`

**Files:**
- Modify: `index.php` (MASTER větev, dnes řádky ~23–31)

- [ ] **Step 1: Ověřit současný stav MASTER větve**

Run: `sed -n '23,31p' index.php`
Expected: blok `// 1) MASTER server …` s `if ($isMaster) { if ($hasIndexHtml) { header('Location: /index.html'); } … }`.
Pokud se obsah liší od níže uvedeného `old_string`, zastav a znovu si přečti `index.php`.

- [ ] **Step 2: Nahradit MASTER větev routerem**

V `index.php` nahraď tento blok:

```php
// 1) MASTER server — NIKDY install. Vždy sales.
if ($isMaster) {
    if ($hasIndexHtml) {
        header('Location: /index.html');
    } else {
        header('Location: /vendor/');
    }
    exit;
}
```

za:

```php
// 1) MASTER server — NIKDY install. Vždy sales (A/B landing varianta).
if ($isMaster) {
    require_once $root . '/api/_landing.php';
    appek_landing_serve($root);
    exit;
}
```

(`$root` je definováno výše na řádku `$root = __DIR__;`. Proměnná `$hasIndexHtml` tím přestane být využita v MASTER větvi — ponech ji, je neškodná, menší diff = menší riziko kolize.)

- [ ] **Step 3: Lint**

Run: `/Applications/XAMPP/xamppfiles/bin/php -l index.php`
Expected: `No syntax errors detected in index.php`.

- [ ] **Step 4: Ověřit router proti lokálnímu serveru**

Předpoklad: běží XAMPP Apache, projekt na `http://localhost/appek/`.

```bash
curl -s 'http://localhost/appek/'                  | grep -o 'APPEK landing variant: [a-z]*'
curl -s 'http://localhost/appek/?variant=classic'  | grep -o 'APPEK landing variant: [a-z]*'
curl -s 'http://localhost/appek/?variant=apple'    | grep -o 'APPEK landing variant: [a-z]*'
curl -s 'http://localhost/appek/?variant=bogus'    | grep -o 'APPEK landing variant: [a-z]*'
curl -sI 'http://localhost/appek/?variant=classic' | grep -i 'x-robots-tag'
curl -sI 'http://localhost/appek/'                 | grep -ci 'x-robots-tag'
```

Expected:
- bez parametru → `APPEK landing variant: apple` (default)
- `?variant=classic` → `APPEK landing variant: classic`
- `?variant=apple` → `APPEK landing variant: apple`
- `?variant=bogus` → `APPEK landing variant: apple` (neplatné → default)
- `x-robots-tag` u `?variant=classic` → `X-Robots-Tag: noindex`
- `x-robots-tag` bez parametru → `0` (žádná noindex hlavička)

- [ ] **Step 5: Commit**

```bash
git add index.php
git commit -m "feat: root router servíruje zvolenou landing variantu"
```

---

## Task 4: Vendor přepínač `vendor/landing.php`

**Files:**
- Create: `vendor/landing.php`

Stránka kopíruje zavedený vzor `vendor/sales-cms.php` (autentizace přes `vendor_require_login()`,
layout přes `_layout.php`, session-auth bez zvláštního CSRF tokenu — stejně jako ostatní vendor stránky).

- [ ] **Step 1: Vytvořit stránku**

Create `vendor/landing.php`:

```php
<?php
/**
 * 🎨 LANDING A/B — přepínač aktivní varianty marketingové stránky appek.cz.
 *
 * Zapisuje api/landing-variant.txt. Root router (index.php) podle něj servíruje
 * index-apple.html / index-classic.html. Viz api/_landing.php.
 */
require_once __DIR__ . '/_lib.php';
require_once __DIR__ . '/_layout.php';
require_once __DIR__ . '/../api/_landing.php';

$user = vendor_require_login();
$currentPage = 'landing';

$root = realpath(__DIR__ . '/..');
$flash_ok = null;
$flash_err = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['variant'])) {
    $choice = appek_landing_normalize($_POST['variant']);
    if ($choice === null) {
        $flash_err = 'Neplatná varianta.';
    } elseif (appek_landing_write($root, $choice)) {
        $flash_ok = 'Aktivní varianta: '
                  . ($choice === 'apple' ? 'Apple (moderní)' : 'Klasik (důvěryhodná)') . '.';
        vendor_audit(vendor_db(), $user, 'landing_variant_set', null, null);
    } else {
        $flash_err = 'Nelze zapsat api/landing-variant.txt (zkontroluj oprávnění zápisu).';
    }
}

$active      = appek_landing_current($root);
$settingPath = appek_landing_setting_path($root);
$changedAt   = is_file($settingPath) ? date('j.n.Y H:i', filemtime($settingPath)) : '—';
?><!DOCTYPE html>
<html lang="cs">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="robots" content="noindex,nofollow">
<title>🎨 Landing A/B — APPEK Master</title>
<link rel="stylesheet" href="style.css?v=1.4">
<style>
  .variant-grid { display:flex; gap:14px; flex-wrap:wrap; margin:4px 0 16px; }
  .variant-card {
    flex:1; min-width:240px; border:2px solid #d2d2d7; border-radius:12px;
    padding:16px 18px; cursor:pointer; transition:border-color .15s, background .15s;
  }
  .variant-card:hover  { border-color:#86868b; }
  .variant-card.active { border-color:#BA7517; background:#fdf8f1; }
  .variant-card input  { margin-right:8px; }
  .variant-card h3 { margin:0 0 4px; font-size:16px; }
  .variant-card p  { margin:0; font-size:13px; color:#86868b; line-height:1.5; }
  .flash { padding:12px 16px; border-radius:10px; margin-bottom:14px; font-size:14px; }
  .flash.ok  { background:#d4edda; color:#155724; }
  .flash.err { background:#f8d7da; color:#721c24; }
</style>
</head>
<body>

<?php vendor_render_topbar($user, $currentPage); ?>
<?php vendor_render_back(); ?>

<main class="page-master">

  <div class="page-header-master">
    <h1>🎨 Landing A/B</h1>
    <div style="font-size:13px;color:#86868b">Která verze appek.cz je živá pro návštěvníky</div>
  </div>

  <?php if ($flash_ok): ?><div class="flash ok">✅ <?= htmlspecialchars($flash_ok) ?></div><?php endif; ?>
  <?php if ($flash_err): ?><div class="flash err">❌ <?= htmlspecialchars($flash_err) ?></div><?php endif; ?>

  <div class="panel-master">
    <h2>Aktivní varianta</h2>
    <p style="font-size:13px;color:#3a3a3c">
      Teď živá: <strong><?= $active === 'apple' ? 'Apple (moderní)' : 'Klasik (důvěryhodná)' ?></strong>
      · poslední změna: <?= htmlspecialchars($changedAt) ?>
    </p>
    <form method="POST">
      <div class="variant-grid">
        <label class="variant-card <?= $active === 'apple' ? 'active' : '' ?>">
          <h3><input type="radio" name="variant" value="apple" <?= $active === 'apple' ? 'checked' : '' ?>>Apple (moderní)</h3>
          <p>Výrazný redesign — bílý prostor, velká typografie, tmavé sekce.</p>
        </label>
        <label class="variant-card <?= $active === 'classic' ? 'active' : '' ?>">
          <h3><input type="radio" name="variant" value="classic" <?= $active === 'classic' ? 'checked' : '' ?>>Klasik (důvěryhodná)</h3>
          <p>Tradiční old-school — hutná, ohraničená, reference a záruky nahoře.</p>
        </label>
      </div>
      <div style="display:flex;gap:10px;flex-wrap:wrap">
        <button type="submit" class="btn-master primary">💾 Uložit a přepnout</button>
        <a href="https://appek.cz/?variant=apple"   target="_blank" rel="noopener" class="btn-master secondary">👁️ Náhled Apple</a>
        <a href="https://appek.cz/?variant=classic" target="_blank" rel="noopener" class="btn-master secondary">👁️ Náhled Klasik</a>
      </div>
    </form>
  </div>

  <div class="panel-master">
    <h2>ℹ️ Jak to funguje</h2>
    <p style="font-size:13px;color:#3a3a3c;line-height:1.7">
      Volba se uloží do <code>api/landing-variant.txt</code>. Root router (<code>index.php</code>)
      podle ní na <code>appek.cz/</code> servíruje <code>index-apple.html</code> nebo
      <code>index-classic.html</code>. Náhledové odkazy (<code>?variant=</code>) ukážou variantu
      bez přepnutí naživo a jsou <code>noindex</code>.
    </p>
  </div>

</main>

<?php vendor_render_footer(); ?>
</body>
</html>
```

- [ ] **Step 2: Lint**

Run: `/Applications/XAMPP/xamppfiles/bin/php -l vendor/landing.php`
Expected: `No syntax errors detected in vendor/landing.php`.

- [ ] **Step 3: Ruční ověření v prohlížeči**

Přihlas se do vendor panelu (`http://localhost/appek/vendor/`) a otevři `landing.php`. Ověř:
- Stránka se vykreslí s topbarem/footerem jako ostatní vendor stránky.
- Vybraná je aktuální varianta (výchozí „Apple").
- „Uložit a přepnout" na „Klasik" → zelený flash, „poslední změna" se aktualizuje.
- `curl -s 'http://localhost/appek/' | grep -o 'APPEK landing variant: [a-z]*'` → nyní `classic`.
- Přepni zpět na „Apple" (ať je výchozí stav čistý).

- [ ] **Step 4: Commit**

```bash
git add vendor/landing.php
git commit -m "feat: vendor/landing.php — přepínač A/B landing varianty"
```

---

## Task 5: Položka v navigaci vendoru

**Files:**
- Modify: `vendor/_layout.php` (funkce `vendor_nav_primary()`)

- [ ] **Step 1: Přidat nav položku**

Ve `vendor/_layout.php` ve funkci `vendor_nav_primary()` nahraď tento řádek:

```php
        ['key' => 'pages-editor',  'label' => '✏️ Web',           'url' => 'pages-editor.php'],
```

za tyto dva řádky:

```php
        ['key' => 'pages-editor',  'label' => '✏️ Web',           'url' => 'pages-editor.php'],
        ['key' => 'landing',       'label' => '🎨 Landing A/B',   'url' => 'landing.php'],
```

(`'key' => 'landing'` odpovídá `$currentPage = 'landing'` ve `vendor/landing.php` — zajistí zvýraznění aktivní položky.)

- [ ] **Step 2: Lint**

Run: `/Applications/XAMPP/xamppfiles/bin/php -l vendor/_layout.php`
Expected: `No syntax errors detected in vendor/_layout.php`.

- [ ] **Step 3: Ověřit v prohlížeči**

Obnov libovolnou vendor stránku → v horní navigaci je nová položka „🎨 Landing A/B", odkazuje na `landing.php`, a na stránce `landing.php` je zvýrazněná.

- [ ] **Step 4: Commit**

```bash
git add vendor/_layout.php
git commit -m "feat: odkaz na Landing A/B ve vendor navigaci"
```

---

## Task 6: Build skript a `.gitignore`

**Files:**
- Modify: `.gitignore`
- Modify: `build-zip.sh`

- [ ] **Step 1: Ignorovat runtime soubor s volbou**

Na konec `.gitignore` přidej:

```
# Runtime — aktivní A/B landing varianta (volí se ve vendor/landing.php)
api/landing-variant.txt
```

- [ ] **Step 2: CUSTOMER ZIP — vyloučit landing soubory**

Customer balíček nesmí obsahovat marketingovou stránku. V `build-zip.sh` v sekci
„CUSTOMER ZIP" nahraď řádek:

```bash
  -x "index.html" \
```

za:

```bash
  -x "index-apple.html" -x "index-classic.html" -x "index-legacy.html" \
```

- [ ] **Step 3: Vyloučit runtime soubor z obou ZIPů**

V `build-zip.sh` v poli `COMMON_EXCLUDES=( … )` přidej za řádek `-x "api/.installed"`:

```bash
  -x "api/landing-variant.txt"
```

- [ ] **Step 4: Vyloučit runtime soubor z deploy manifestu**

V `build-zip.sh` v Python bloku `PYMANIFEST`, ve funkci `skip(rel)`, je tato podmínka:

```python
    if base in ('.DS_Store', '.build-manifest.json', '.update-manifest.json',
                '.deploy-check.json', '.version', '.installed'):
        return True
```

Přidej za ni:

```python
    if base == 'landing-variant.txt':
        return True
```

- [ ] **Step 5: Ověřit syntaxi build skriptu**

Run: `bash -n build-zip.sh`
Expected: bez výstupu, exit kód 0.

- [ ] **Step 6: Commit**

```bash
git add .gitignore build-zip.sh
git commit -m "build: zahrnout landing varianty + ignorovat runtime volbu"
```

---

## Task 7: Integrační ověření + build

Tento úkol nic necommituje — jen ověřuje hotovou fázi 1 a postaví distribuční balíček.

- [ ] **Step 1: Regresní běh testů**

Run: `/Applications/XAMPP/xamppfiles/bin/php scripts/test-landing.php`
Expected: `✅ VŠE OK (21 testů)`, exit 0.

- [ ] **Step 2: Lint všech dotčených PHP souborů**

Run:
```bash
for f in index.php api/_landing.php vendor/landing.php vendor/_layout.php; do
  /Applications/XAMPP/xamppfiles/bin/php -l "$f"
done
```
Expected: `No syntax errors detected` u všech čtyř.

- [ ] **Step 3: E2E přes lokální server**

Předpoklad: běží XAMPP Apache. Výchozí volba = `apple` (soubor `api/landing-variant.txt` neexistuje, nebo obsahuje `apple`).

```bash
curl -s 'http://localhost/appek/'                  | grep -o 'APPEK landing variant: [a-z]*'
curl -s 'http://localhost/appek/?variant=classic'  | grep -o 'APPEK landing variant: [a-z]*'
curl -sI 'http://localhost/appek/?variant=classic' | grep -i 'x-robots-tag'
```
Expected: `apple`; `classic`; `X-Robots-Tag: noindex`.

- [ ] **Step 4: Postavit distribuci**

Run: `./build-zip.sh 2.9.140` (nebo další volnou patch verzi — aktuální je 2.9.139).
Expected: skript proběhne až po `✅ BUILD HOTOVÝ`, kontrola CSS závorek projde, vzniknou
`appek-MASTER-v2.9.140.zip` i `appek-v2.9.140.zip`.

- [ ] **Step 5: Ověřit obsah ZIPů**

(Použij stejné číslo verze jako ve Step 4.)

```bash
echo "MASTER — musí obsahovat varianty:"
unzip -l appek-MASTER-v2.9.140.zip | grep -E 'index-(apple|classic|legacy)\.html|vendor/landing\.php'
echo "CUSTOMER — NESMÍ obsahovat varianty:"
unzip -l appek-v2.9.140.zip | grep -cE 'index-(apple|classic|legacy)\.html'
```
Expected: MASTER vypíše `index-apple.html`, `index-classic.html`, `index-legacy.html` i `vendor/landing.php`; CUSTOMER vypíše `0`.

- [ ] **Step 6: Závěr**

`build-zip.sh` bumpuje verzi v několika sledovaných souborech (`api/config.php`, `admin/sw.js`,
`api/.build-manifest.json` …) a kopíruje ZIPy na plochu. Tyto version-bump změny **necommituj** —
patří do běžného deploy/commit workflow uživatele. Fáze 1 je tím hotová: přepínač funguje,
obě varianty zatím ukazují dnešní design. Následují fáze 2 (Apple) a 3 (Klasik) — vizuální
redesign, samostatné zpracování.
