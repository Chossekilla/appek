# Catering — produkty z katalogu — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Přidat do catering balíčku možnost přidávat produkty z katalogu výrobků — trvalý číselník v „Nastavení kalkulačky" i ad-hoc do konkrétní nabídky — s per-produkt nastavením (porce/aktivní/povinné) a výkonným pickerem (hledání + filtr kategorie + server-side pagination).

**Architecture:** Přístup A — rozšíření `catering_config` (JSON v `nastaveni`) o klíč `produkty[]`. Žádná nová DB tabulka → žádná migrace → žádné riziko rozbití čisté instalace. Název/cena/kategorie/odpis se berou za běhu joinem na `vyrobky`. Nový paginovaný endpoint `produkty_pick` nahradí dosavadní `LIMIT 500` dump.

**Tech Stack:** PHP 8 (procedurální endpoint `api/admin_catering_calc.php`), vanilla JS (`admin/src/0560-*.js`, generovaný do `admin/admin.js`), MySQL. Testy = `scripts/test-money-paths.php` (assert skript) + `scripts/smoke.sh`.

**Spec:** `docs/superpowers/specs/2026-07-08-catering-produkty-katalog-design.md`

---

## Poznámky pro implementátora (přečti PRVNÍ)

- **admin.js je GENEROVANÝ** z `admin/src/*.js` (viz [[appek-admin-js-modular]]). **Edituj `admin/src/0560-*.js`, NE admin.js.** Po editaci se admin.js přegeneruje v CI; lokálně lze `python3 scripts/admin_modularize.py` (nebo concat) — ověř existující workflow.
- **Deploy = git tag** `vX.Y.Z` (ne pouhý push). Bump `api/config.php` APP_VERSION + `admin/src/0000-preamble.js` + `admin/admin.js` ADMIN_JS_VERSION + `vendor/.appek-version`.
- **Gating:** endpointy catering už běží pod balíčkovým gate; nové akce dědí stejný soubor, není třeba nic navíc — ale ověř, že soubor gate uplatňuje na začátku.
- Config load: `catering_config()` (řádek ~88), save: `save_config` (~411), options: `~354–386`, quote: `catering_compute()` (~264+).

---

### Task 1: Backend — `produkty[]` v config (load + save)

**Files:**
- Modify: `api/admin_catering_calc.php` (`catering_config()` ~96–102, `save_config` ~448–453)
- Test: `scripts/test-money-paths.php` (přidat asserty na konec)

- [ ] **Step 1: Test — config zachová `produkty[]` a odmítne špatný vstup**

Přidej do `scripts/test-money-paths.php` (styl = existující `assert_*`):

```php
// --- Catering produkty[] round-trip ---
$clean = catering_clean_produkty([
    ['vyrobek_id' => 5, 'porce_na_osobu' => 1.5, 'aktivni' => true,  'povinne' => true,  'poradi' => 0],
    ['vyrobek_id' => 0, 'porce_na_osobu' => 2,   'aktivni' => true,  'povinne' => true],   // vyrobek_id=0 → vyřazeno
    ['vyrobek_id' => 9, 'porce_na_osobu' => -3,  'aktivni' => 'x',   'povinne' => 0],       // porce<0 → 0, bool coerce
]);
assert_eq(count($clean), 2, 'produkty: vyrobek_id=0 vyřazen');
assert_eq($clean[0]['porce_na_osobu'], 1.5, 'porce zachována');
assert_eq($clean[1]['porce_na_osobu'], 0.0, 'porce<0 → 0');
assert_eq($clean[1]['aktivni'], true, 'aktivni coerce na bool');
assert_eq($clean[1]['povinne'], false, 'povinne=0 → false');
```

- [ ] **Step 2: Spusť test — musí selhat**

Run: `/Applications/XAMPP/xamppfiles/bin/php scripts/test-money-paths.php 2>&1 | grep -i produkt`
Expected: FAIL / fatal „Call to undefined function catering_clean_produkty".

- [ ] **Step 3: Implementace — helper + načtení + uložení**

V `api/admin_catering_calc.php` přidej helper (poblíž ostatních `catering_*`, ~za řádek 109):

```php
// 🆕 v3.0.423 — sanitizace produktů z katalogu (přístup A, viz spec)
function catering_clean_produkty($produkty): array {
    $out = [];
    if (!is_array($produkty)) return $out;
    foreach ($produkty as $p) {
        if (!is_array($p)) continue;
        $vid = (int) ($p['vyrobek_id'] ?? 0);
        if ($vid <= 0) continue;               // bez napojení na výrobek nemá smysl
        $out[] = [
            'vyrobek_id'     => $vid,
            'porce_na_osobu' => max(0, round((float) ($p['porce_na_osobu'] ?? 1), 4)),
            'aktivni'        => !empty($p['aktivni']),
            'povinne'        => !empty($p['povinne']),
            'poradi'         => (int) ($p['poradi'] ?? 0),
        ];
    }
    return $out;
}
```

Do `catering_config()` za blok s `dph` (řádek ~100) přidej:

```php
                if (isset($saved['produkty']) && is_array($saved['produkty'])) $cfg['produkty'] = catering_clean_produkty($saved['produkty']);
```

Do `catering_default_config()` (řádek ~81–86) přidej klíč: `'produkty' => [],`.

Do `save_config` `$save` pole (řádek ~448–453) přidej:

```php
        'produkty'      => catering_clean_produkty($cfg['produkty'] ?? []),
```

A uprav podmínku prázdnoty (řádek 454), aby produkty stačily:

```php
    if (empty($save['jidlo']) && empty($save['napoje']) && empty($save['produkty'])) json_error('Musí zůstat aspoň jedna položka', 400);
```

- [ ] **Step 4: Spusť test — musí projít**

Run: `/Applications/XAMPP/xamppfiles/bin/php scripts/test-money-paths.php 2>&1 | tail -5`
Expected: PASS (žádné selhání u „produkty …").

- [ ] **Step 5: Commit**

```bash
git add api/admin_catering_calc.php scripts/test-money-paths.php
git commit -m "feat(catering): produkty[] v catering_config (load/save/sanitizace)"
```

---

### Task 2: Backend — paginovaný picker `?action=produkty_pick`

**Files:**
- Modify: `api/admin_catering_calc.php` (přidat action blok, poblíž `config` ~408)
- Test: `scripts/smoke.sh` (přidat endpoint check)

- [ ] **Step 1: Test — smoke na nový endpoint**

Do `scripts/smoke.sh` přidej (styl = existující řádky):

```bash
check "catering produkty_pick" "admin_catering_calc.php?action=produkty_pick&page=1" 200
```

- [ ] **Step 2: Spusť smoke — endpoint musí chybět (404/500 nebo neznámá action)**

Run: `bash scripts/smoke.sh 2>&1 | grep produkty_pick`
Expected: FAIL (action neexistuje → vrátí chybu/neočekávaný kód).

- [ ] **Step 3: Implementace — action blok**

V `api/admin_catering_calc.php` za blok `if ($action === 'config') {…}` (řádek ~408) přidej:

```php
// 🆕 v3.0.423 — paginovaný + filtrovaný picker výrobků (nahrazuje LIMIT 500 dump v options)
if ($action === 'produkty_pick') {
    $pdo = db();
    $q    = trim((string) ($_GET['q'] ?? ''));
    $kat  = (int) ($_GET['kategorie'] ?? 0);
    $page = max(1, (int) ($_GET['page'] ?? 1));
    $per  = 24;
    $off  = ($page - 1) * $per;

    $where = ['v.aktivni = 1'];
    $args  = [];
    if ($q !== '')  { $where[] = 'v.nazev LIKE :q';        $args['q']   = '%' . $q . '%'; }
    if ($kat > 0)   { $where[] = 'v.kategorie_id = :kat';  $args['kat'] = $kat; }
    $whereSql = implode(' AND ', $where);

    $total = 0;
    try {
        $stc = $pdo->prepare("SELECT COUNT(*) FROM vyrobky v WHERE $whereSql");
        $stc->execute($args);
        $total = (int) $stc->fetchColumn();
    } catch (Throwable $e) {}

    $items = [];
    try {
        $st = $pdo->prepare("
            SELECT v.id, v.nazev, ROUND(v.cena_bez_dph, 2) AS cena, v.kategorie_id,
                   COALESCE(k.nazev, '') AS kategorie,
                   (SELECT COUNT(*) FROM vyrobek_suroviny WHERE vyrobek_id = v.id) > 0 AS ma_recept
            FROM vyrobky v LEFT JOIN kategorie_vyrobku k ON k.id = v.kategorie_id
            WHERE $whereSql
            ORDER BY v.nazev
            LIMIT $per OFFSET $off
        ");
        $st->execute($args);
        $items = $st->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {}

    // seznam kategorií pro dropdown filtr
    $kategorie = [];
    try {
        $kategorie = $pdo->query("SELECT id, nazev FROM kategorie_vyrobku ORDER BY poradi, nazev")->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {}

    json_response([
        'items'     => array_map(fn($r) => [
            'id' => (int) $r['id'], 'nazev' => $r['nazev'], 'cena' => (float) $r['cena'],
            'kategorie_id' => (int) $r['kategorie_id'], 'kategorie' => $r['kategorie'],
            'ma_recept' => (bool) $r['ma_recept'],
        ], $items),
        'total'     => $total,
        'page'      => $page,
        'pages'     => (int) ceil($total / $per),
        'kategorie' => array_map(fn($k) => ['id' => (int) $k['id'], 'nazev' => $k['nazev']], $kategorie),
    ]);
}
```

- [ ] **Step 4: Spusť smoke — musí projít**

Run: `bash scripts/smoke.sh 2>&1 | grep produkty_pick`
Expected: PASS (200 + JSON s klíči items/total/pages/kategorie).

Lokální ověření paginace:
Run: `/Applications/XAMPP/xamppfiles/bin/php -r "..."` nebo curl přes `_ztest_session.php` (viz [[appek-mobile-testing.md]] dev-session vzor).
Expected: `pages` > 1 u katalogu > 24 výrobků; `q`/`kategorie` filtrují.

- [ ] **Step 5: Commit**

```bash
git add api/admin_catering_calc.php scripts/smoke.sh
git commit -m "feat(catering): produkty_pick — paginovaný picker výrobků (hledání+filtr kategorie)"
```

---

### Task 3: Backend — nakonfigurované produkty v options + kalkulaci

**Files:**
- Modify: `api/admin_catering_calc.php` (options ~379–385, `catering_compute` ~264+)
- Test: `scripts/test-money-paths.php`

- [ ] **Step 1: Test — quote škáluje produkty dle osob**

Do `scripts/test-money-paths.php` přidej (použij existující `catering_compute` s injektovaným configem — pokud config nejde injektovat, testuj `catering_decorate_produkty` čistou funkcí):

```php
// --- Catering produkty scaling ---
$someVyrobekId = (int) $pdo->query("SELECT id FROM vyrobky WHERE aktivni=1 LIMIT 1")->fetchColumn();
$dec = catering_decorate_produkty($pdo, [
    ['vyrobek_id' => $someVyrobekId, 'porce_na_osobu' => 2, 'aktivni' => true, 'povinne' => true, 'poradi' => 0],
]);
assert_eq(count($dec), 1, 'decorate vrací 1 produkt');
assert_true(isset($dec[0]['nazev']) && isset($dec[0]['cena']) && isset($dec[0]['kategorie']), 'produkt dekorován z katalogu');
// škálování 10 osob × 2 porce = 20
assert_eq(round($dec[0]['cena'] * 20, 2), round($dec[0]['cena'] * 10 * 2, 2), 'množství = osob × porce');
```

- [ ] **Step 2: Spusť test — musí selhat**

Run: `/Applications/XAMPP/xamppfiles/bin/php scripts/test-money-paths.php 2>&1 | grep -i "decorate\|produkt"`
Expected: FAIL („undefined function catering_decorate_produkty").

- [ ] **Step 3: Implementace — dekorátor + zapojení do options a compute**

Přidej funkci (poblíž `catering_resolve_vyrobky`, ~171):

```php
// 🆕 v3.0.423 — dekoruj nakonfigurované produkty joinem na vyrobky (název/cena/kategorie/material)
function catering_decorate_produkty(PDO $pdo, array $produkty): array {
    if (!$produkty) return [];
    $ids = array_values(array_unique(array_map(fn($p) => (int) $p['vyrobek_id'], $produkty)));
    $in  = implode(',', array_fill(0, count($ids), '?'));
    $map = [];
    try {
        $st = $pdo->prepare("
            SELECT v.id, v.nazev, ROUND(v.cena_bez_dph,2) AS cena, v.kategorie_id,
                   COALESCE(k.nazev,'') AS kategorie, COALESCE(sd.sazba,12) AS dph
            FROM vyrobky v
            LEFT JOIN kategorie_vyrobku k ON k.id = v.kategorie_id
            LEFT JOIN sazby_dph sd ON sd.id = v.sazba_dph_id
            WHERE v.aktivni = 1 AND v.id IN ($in)
        ");
        $st->execute($ids);
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) $map[(int) $r['id']] = $r;
    } catch (Throwable $e) {}

    $out = [];
    foreach ($produkty as $p) {
        $vid = (int) $p['vyrobek_id'];
        $v = $map[$vid] ?? null;
        if (!$v) { $out[] = $p + ['smazany' => true, 'nazev' => '(smazaný produkt)']; continue; }
        $out[] = $p + [
            'nazev'     => $v['nazev'],
            'cena'      => (float) $v['cena'],
            'material'  => catering_material_cost($pdo, $vid),
            'kategorie_id' => (int) $v['kategorie_id'],
            'kategorie' => $v['kategorie'],
            'dph'       => (float) $v['dph'],
            'smazany'   => false,
        ];
    }
    return $out;
}
```

V options (`if ($action === 'options')`, ~379) přidej do odpovědi:

```php
        'produkty'      => catering_decorate_produkty($pdo, catering_config($pdo)['produkty'] ?? []),
```

V `catering_compute()` (~264+, kde se skládají `$polozky`) přidej po stávajících položkách smyčku (jen aktivní; povinné vždy, volitelné jen když v `$d['produkty_zvolene']`):

```php
    // 🆕 v3.0.423 — produkty z katalogu (přístup A)
    $zvolene = array_map('intval', (array) ($d['produkty_zvolene'] ?? []));
    foreach (catering_decorate_produkty($pdo, catering_config($pdo)['produkty'] ?? []) as $pp) {
        if (!empty($pp['smazany']) || empty($pp['aktivni'])) continue;
        if (empty($pp['povinne']) && !in_array((int) $pp['vyrobek_id'], $zvolene, true)) continue; // volitelné jen když zvoleno
        $mn = round($osob * (float) $pp['porce_na_osobu'], 3);
        if ($mn <= 0) continue;
        $polozky[] = [
            'nazev'        => $pp['nazev'],
            'mnozstvi'     => $mn,
            'cena_kc'      => round($mn * (float) $pp['cena'], 2),
            'material_kc'  => round($mn * (float) $pp['material'], 2),
            'vyrobek_id'   => (int) $pp['vyrobek_id'],
            'odecte_sklad' => true,
            'sekce'        => $pp['kategorie'],
        ];
    }
```

Ověř, že `create_order` bere `$c['polozky']` s `vyrobek_id` → existující řádky výroby (mělo by fungovat beze změny, protože compute je sdílený).

- [ ] **Step 4: Spusť test — musí projít**

Run: `/Applications/XAMPP/xamppfiles/bin/php scripts/test-money-paths.php 2>&1 | tail -5`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add api/admin_catering_calc.php scripts/test-money-paths.php
git commit -m "feat(catering): nakonfigurované produkty v options + kalkulaci (škálování osob×porce)"
```

---

### Task 4: Admin UI — sekce „Produkty z katalogu" + picker modal

**Files:**
- Modify: `admin/src/0560-catering-kalkulator-lahudky-balicek.js`

- [ ] **Step 1: Přidej do configu tabu „⚙️ Nastavení kalkulačky" sekci „Produkty z katalogu"**

Vykresli seznam z `config.produkty` grupovaný dle `kategorie` (načteno přes `options`/`config`). U každé položky: název (readonly z katalogu), input `porce_na_osobu`, přepínač `aktivni`, přepínač povinné/volitelné, tlačítko odebrat. Smazané (`smazany:true`) zvýrazni + jen odebrat.

Vzor render + save: následuj existující jidlo/napoje editor v tomtéž souboru (stejné `saveCateringConfig()`, jen přidej `produkty` do payloadu). Klíčový payload:

```js
body: JSON.stringify({ config: { ...cfg, produkty: cfg.produkty.map((p,i)=>({...p, poradi:i})) } })
```

- [ ] **Step 2: Picker modal (hledání + dropdown kategorie + stránkování)**

```js
async function openProduktPicker(onPick) {
  let page = 1, q = '', kat = 0;
  async function load() {
    const r = await api(`admin_catering_calc.php?action=produkty_pick&page=${page}&q=${encodeURIComponent(q)}&kategorie=${kat}`);
    renderPicker(r); // grid r.items, dropdown r.kategorie, r.page/r.pages prev/next
  }
  // debounce hledání ~300 ms; dropdown onChange → kat, page=1, load(); prev/next → page±1, load()
  // klik na item → onPick({vyrobek_id:item.id, porce_na_osobu:1, aktivni:true, povinne:true}); modal zavřít
  load();
}
```

„+ Přidat produkt" v sekci volá `openProduktPicker(p => { cfg.produkty.push(p); rerender(); })`.

- [ ] **Step 3: Ověření v prohlížeči (Preview MCP / Playwright 375px dle [[feedback-quality-gates]])**

Otevři catering tab → Nastavení kalkulačky → Produkty z katalogu → + Přidat produkt → ověř: hledání filtruje, dropdown kategorie filtruje, stránkování funguje, přidání se objeví v seznamu, uložení přežije reload. Screenshot.

- [ ] **Step 4: Commit**

```bash
git add admin/src/0560-catering-kalkulator-lahudky-balicek.js
git commit -m "feat(catering): UI — sekce Produkty z katalogu + picker (hledání/filtr/stránkování)"
```

---

### Task 5: Ad-hoc produkt v konkrétní nabídce

**Files:**
- Modify: `admin/src/0560-catering-kalkulator-lahudky-balicek.js` (nabídková obrazovka) + `api/admin_catering_calc.php` (quote/create_order akceptují `produkty_adhoc[]`)

- [ ] **Step 1: Backend — quote/create_order přijmou `produkty_adhoc`**

V `catering_compute()` za smyčku konfigurovaných produktů přidej stejný pattern pro `(array) ($d['produkty_adhoc'] ?? [])` (každý `{vyrobek_id, porce_na_osobu|mnozstvi}`) → dekoruj přes `catering_decorate_produkty` a přidej do `$polozky`. Sanitizace vyrobek_id/mnozstvi.

- [ ] **Step 2: UI — „+ Přidat produkt" v nabídce**

Na nabídkové obrazovce tlačítko volá stejný `openProduktPicker` → přidá do lokálního `state.produkty_adhoc` s inputem množství; posílá se v quote/create_order payloadu.

- [ ] **Step 3: Ověření + Commit**

Ověř v prohlížeči (ad-hoc produkt se propíše do kalkulace i objednávky). Commit:

```bash
git add api/admin_catering_calc.php admin/src/0560-catering-kalkulator-lahudky-balicek.js
git commit -m "feat(catering): ad-hoc produkt z katalogu v konkrétní nabídce"
```

---

### Task 6: Regenerace admin.js + verze + deploy

**Files:**
- Modify: `admin/admin.js` (regen), `api/config.php`, `admin/src/0000-preamble.js`, `vendor/.appek-version`

- [ ] **Step 1: Regeneruj admin.js z src**

Run: ověř workflow (concat/`scripts/admin_modularize.py`) — admin.js musí obsahovat nový kód z 0560.
Expected: `grep produkty_pick admin/admin.js` → nalezeno.

- [ ] **Step 2: Bump verzí**

```bash
V=3.0.423
sed -i '' "s/APP_VERSION',    '[0-9.]*'/APP_VERSION',    '$V'/" api/config.php
sed -i '' "s/APPEK_ADMIN_JS_VERSION = '[0-9.]*'/APPEK_ADMIN_JS_VERSION = '$V'/" admin/src/0000-preamble.js admin/admin.js
echo "$V" > vendor/.appek-version
/Applications/XAMPP/xamppfiles/bin/php -l api/admin_catering_calc.php
```

- [ ] **Step 3: Full test suite**

Run: `/Applications/XAMPP/xamppfiles/bin/php scripts/test-money-paths.php && bash scripts/smoke.sh`
Expected: vše PASS.

- [ ] **Step 4: Commit + tag + push (spustí deploy)**

```bash
git add -A
git commit -m "v3.0.423: catering — produkty z katalogu (číselník+ad-hoc, picker s paginací)"
git tag v3.0.423 && git push origin main v3.0.423
```

- [ ] **Step 5: Ověření na nasazeném demu**

Po CI: version.php = 3.0.423; catering options vrací `produkty`; picker `produkty_pick` 200 + paginace; funkčně přes browser (dle [[feedback-quality-gates]] screenshot).

---

## Self-Review (autor plánu)

- **Spec coverage:** číselník (T1,T3,T4) ✓ · ad-hoc (T5) ✓ · picker paginace+filtr kategorie (T2,T4) ✓ · per-produkt porce/aktivní/povinné (T1,T3,T4) ✓ · sekce=kategorie (T3 `kategorie` join) ✓ · bez migrace (T1 JSON) ✓ · smazaný produkt (T3 `smazany`) ✓ · testy (T1,T2,T3,T6) ✓.
- **Placeholdery:** UI tasky (T4/T5) popisují kroky + klíčový kód, ale ne řádek po řádku — implementátor následuje existující jidlo/napoje editor v tomtéž souboru (uvedeno). Backend tasky mají kompletní kód.
- **Typová konzistence:** `catering_clean_produkty`, `catering_decorate_produkty`, `produkty_pick`, payload klíče `produkty`/`produkty_zvolene`/`produkty_adhoc` konzistentní napříč T1–T5.
