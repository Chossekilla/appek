# Fáze 1 — Deploy pipeline — Implementační plán

> **Pro agentní workery:** POVINNÝ SUB-SKILL: použij `superpowers:subagent-driven-development` (doporučeno) nebo `superpowers:executing-plans` k provedení tohoto plánu úloha po úloze. Kroky používají checkbox (`- [ ]`).

**Cíl:** Jedním příkazem (`release.sh`) z libovolného stroje bezpečně nasadit novou verzi na celý ekosystém přes GitHub Actions, s auto-rollbackem a ověřením.

**Architektura:** `release.sh` otaguje → GitHub Actions postaví MASTER zip → POSTne ho s tokenem na `vendor/deploy-hook.php` → ten spustí sdílenou knihovnu `vendor/_self_update.php` (validace → záloha → rsync → kontrola → auto-rollback). Stávající ruční upload v `self-update.php` zůstává jako záloha.

**Tech stack:** PHP 8 (bez frameworku), Bash, GitHub Actions (YAML). Projekt nemá test framework — testy jsou samostatné CLI PHP skripty a `--dry-run`.

**Spec:** `docs/superpowers/specs/2026-05-22-deploy-pipeline-design.md`

---

## Struktura souborů

| Soubor | Akce | Odpovědnost |
|--------|------|-------------|
| `scripts/release.sh` | nový | Vydání verze: validace → bump `APP_VERSION` → commit → tag → push |
| `vendor/_self_update.php` | nový | Knihovna: `self_update_apply()`, `self_update_rollback()`, `self_update_health_check()` + přesunuté helpery |
| `vendor/self-update.php` | úprava | UI stránka — volá knihovnu místo inline logiky |
| `vendor/deploy-hook.php` | nový | Token-autentizovaný JSON endpoint pro CI |
| `.github/workflows/release.yml` | úprava | Build MASTER zip + krok „Deploy" |
| `vendor/tests/test_self_update.php` | nový | CLI test apply / rollback / health check |
| `docs/superpowers/MANUAL-STEPS-F1.md` | nový | Šablona ručních kroků pro majitele (secrets) |

**Pořadí úloh:** 1 (release.sh) je nezávislá. 2→3→4 budují knihovnu. 5 (self-update.php) závisí na 2. 6 (deploy-hook) závisí na 2–4. 7 (release.yml) závisí na 6. 8 = end-to-end.

---

## Task 1: `scripts/release.sh` — vydání verze

**Files:**
- Create: `scripts/release.sh`

- [ ] **Step 1: Napiš `scripts/release.sh`**

```bash
#!/bin/bash
# 🏷️ APPEK RELEASE — bump verze v api/config.php, commit, tag, push.
# Použití: ./scripts/release.sh 2.9.142  [--dry-run]
set -e

VERSION=""
DRY_RUN=false
for arg in "$@"; do
  case "$arg" in
    --dry-run) DRY_RUN=true ;;
    *) VERSION="$arg" ;;
  esac
done

if ! [[ "$VERSION" =~ ^[0-9]+\.[0-9]+\.[0-9]+$ ]]; then
  echo "❌ Verze musí být X.Y.Z. Použití: ./scripts/release.sh X.Y.Z [--dry-run]"
  exit 1
fi

cd "$(dirname "$0")/.."

BRANCH=$(git rev-parse --abbrev-ref HEAD)
if [[ "$BRANCH" != "main" ]]; then
  echo "❌ Nejsi na 'main' (jsi na '$BRANCH'). Release jen z main."
  exit 1
fi

if [[ -n "$(git status --porcelain)" ]]; then
  echo "❌ Pracovní strom není čistý — nejdřív zacommituj práci:"
  git status --short
  exit 1
fi

git fetch origin main --quiet
if [[ -n "$(git rev-list HEAD..origin/main)" ]]; then
  echo "❌ 'main' je pozadu za origin/main. Udělej 'git pull'."
  exit 1
fi

if git rev-parse "v$VERSION" >/dev/null 2>&1; then
  echo "❌ Tag v$VERSION už existuje."
  exit 1
fi

CUR=$(sed -nE "s/.*APP_VERSION'[^']*'([0-9]+\.[0-9]+\.[0-9]+)'.*/\1/p" api/config.php | head -1)
echo "🏷️  Release v$VERSION  (současná APP_VERSION: ${CUR:-?})  dry-run: $DRY_RUN"

if [[ "$DRY_RUN" == true ]]; then
  echo "   [dry-run] bump api/config.php → $VERSION, commit 'chore: release v$VERSION', tag v$VERSION, push main+tag"
  exit 0
fi

if [[ "$CUR" != "$VERSION" ]]; then
  sed -i.bak -E "s/(define\('APP_VERSION'[^']*')[^']*('\s*\)\s*;)/\1${VERSION}\2/" api/config.php
  rm -f api/config.php.bak
  git add api/config.php
  git commit -m "chore: release v$VERSION"
fi

git tag -a "v$VERSION" -m "Release v$VERSION"
git push origin main
git push origin "v$VERSION"
echo "✅ v$VERSION vydáno. GitHub Actions teď staví a nasazuje:"
echo "   https://github.com/Chossekilla/appek/actions"
```

- [ ] **Step 2: Udělej spustitelný a otestuj dry-run**

Run: `chmod +x scripts/release.sh && ./scripts/release.sh --dry-run 9.9.9`
Expected: vypíše `[dry-run] bump … → 9.9.9, commit …, tag v9.9.9, push …`, exit 0, **žádné** změny v gitu (`git status` čistý).

- [ ] **Step 3: Otestuj guard rails**

Run: `./scripts/release.sh 9.9` → Expected: FAIL „Verze musí být X.Y.Z".
Run (s nějakou nezacommitovanou změnou): `./scripts/release.sh --dry-run 9.9.9` → Expected: dry-run projde (nekontroluje strom u dry-run); bez `--dry-run` by FAIL „Pracovní strom není čistý". Ověř že tag `v9.9.9` neexistuje (`git tag | grep 9.9.9` prázdné).

- [ ] **Step 4: Commit**

```bash
git add scripts/release.sh
git commit -m "feat: scripts/release.sh — jednopříkazové vydání verze"
```

---

## Task 2: `vendor/_self_update.php` — knihovna apply rutiny

Vytáhne apply logiku z `vendor/self-update.php` (inline blok `if POST`, ř. ~47–271) a 6 helper funkcí do sdílené knihovny, aby ji mohl volat i CI endpoint. **Refaktor zachovává chování** — ruční upload v prohlížeči funguje stejně.

**Files:**
- Create: `vendor/_self_update.php`
- Create: `vendor/tests/test_self_update.php`
- Modify: `vendor/self-update.php` (ř. 39–310 — viz Step 4)

- [ ] **Step 1: Napiš failing test `vendor/tests/test_self_update.php`**

```php
<?php
// CLI test: php vendor/tests/test_self_update.php <cesta-k-MASTER-zipu>
// Ověří self_update_apply() proti dočasnému webrootu (NE produkci).
require __DIR__ . '/../_self_update.php';

$masterZip = $argv[1] ?? '';
if (!is_file($masterZip)) { fwrite(STDERR, "Použití: php test_self_update.php appek-MASTER-vX.Y.Z.zip\n"); exit(2); }

$tmpRoot = sys_get_temp_dir() . '/appek-test-webroot-' . getmypid();
@mkdir($tmpRoot . '/api', 0777, true);
@mkdir($tmpRoot . '/vendor', 0777, true);
file_put_contents($tmpRoot . '/api/config.local.php', "<?php // SENTINEL preserve test\n");
file_put_contents($tmpRoot . '/api/.installed', 'sentinel');

$log = [];
$res = self_update_apply($masterZip, $log, $tmpRoot);

$fails = [];
if (empty($res['ok']))                                              $fails[] = 'apply nevrátil ok=true: ' . ($res['error'] ?? '');
if (!is_file($tmpRoot . '/vendor/index.php'))                       $fails[] = 'vendor/index.php se nenasadil';
if (!is_file($tmpRoot . '/index.html'))                             $fails[] = 'index.html se nenasadil';
if (@file_get_contents($tmpRoot . '/api/config.local.php') !== "<?php // SENTINEL preserve test\n")
                                                                    $fails[] = 'config.local.php NEBYL zachován (preserve selhal)';

exec('rm -rf ' . escapeshellarg($tmpRoot));
echo implode("\n", $log) . "\n";
if ($fails) { echo "❌ TEST FAIL:\n - " . implode("\n - ", $fails) . "\n"; exit(1); }
echo "✅ TEST PASS — apply + preserve OK\n";
```

- [ ] **Step 2: Spusť test — musí selhat**

Run: `/Applications/XAMPP/xamppfiles/bin/php vendor/tests/test_self_update.php appek-MASTER-v2.9.141.zip`
Expected: FAIL — `Call to undefined function self_update_apply()` (knihovna ještě neexistuje).
Pokud `appek-MASTER-v2.9.141.zip` v rootu není, postav: `./build-zip.sh 2.9.141`.

- [ ] **Step 3: Vytvoř `vendor/_self_update.php`**

Přesuň do nového souboru **beze změny** těchto 6 funkcí z `vendor/self-update.php`: `logStep`, `self_update_copy_dir`, `self_update_rmdir`, `self_update_build_customer_zip`, `self_update_add_dir_to_zip`, `self_update_add_dir_to_bundle`, `self_update_verify_bundle_integrity`.

Přidej novou funkci `self_update_apply()` — jejím tělem je apply sekvence z `self-update.php` ř. ~50–264 (obsah `try` bloku), s těmito a **jen těmito** změnami:

```php
<?php
/**
 * 🚀 SELF-UPDATE knihovna — sdílená apply rutina.
 * Volá ji vendor/self-update.php (UI) i vendor/deploy-hook.php (CI).
 */

/**
 * Nasadí MASTER zip do webrootu. Vrací strukturovaný výsledek.
 * @param string  $zipPath  cesta k MASTER zipu
 * @param array   $log      reference — naplní se průběhem (logStep)
 * @param ?string $webroot  cílový webroot; null = realpath(__DIR__/..) = produkce
 * @return array {ok:bool, version:?string, health:?array, error:?string, backupDir:?string}
 */
function self_update_apply(string $zipPath, array &$log, ?string $webroot = null): array {
    $webroot = $webroot ?? realpath(__DIR__ . '/..');
    try {
        // ── obsah původního try-bloku self-update.php ř. ~68–264 ──
        // ZMĚNY oproti originálu:
        //  1) Vynech validaci uploadu (ř. 51–66) — caller ji dělá.
        //  2) $uploadedZip → použij přímo $zipPath.
        //  3) Záloha: rozšiř $backupTargets — viz Task 3.
        //  4) Místo `$flash_ok = ...` na konci → ulož $version a:
        //       $health = self_update_health_check($webroot, $version); // viz Task 4
        //       return ['ok'=>true, 'version'=>$version, 'health'=>$health, 'backupDir'=>$backupDir];
        //  5) Verzi vezmi z $customerZipMeta['version'] (vrací self_update_build_customer_zip),
        //     fallback z api/config.php APP_VERSION.
    } catch (Throwable $e) {
        logStep('❌ CHYBA: ' . $e->getMessage(), $log);
        return ['ok' => false, 'error' => $e->getMessage(), 'version' => null, 'health' => null];
    }
}

// ... sem přesunuté funkce logStep, self_update_copy_dir, self_update_rmdir,
//     self_update_build_customer_zip, self_update_add_dir_to_zip,
//     self_update_add_dir_to_bundle, self_update_verify_bundle_integrity ...
```

Pozn.: `self_update_build_customer_zip` interně volá `logStep` — to je OK, je teď ve stejné knihovně. `vendor_db()` používané uvnitř je z `_lib.php` (caller ho má načtený).

- [ ] **Step 4: Přepoj `vendor/self-update.php` na knihovnu**

V `vendor/self-update.php`:
- Za stávající `require_once __DIR__ . '/_layout.php';` přidej `require_once __DIR__ . '/_self_update.php';`.
- Smaž lokální definici `logStep` (ř. ~39–45) a všech 6 přesunutých funkcí (ř. ~273–594) — jsou teď v knihovně.
- Inline `if POST` blok (ř. ~47–271) nahraď:

```php
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['master_zip'])) {
    if ($_FILES['master_zip']['error'] !== UPLOAD_ERR_OK) {
        $flash_err = 'Upload chyba: kód ' . $_FILES['master_zip']['error'];
    } elseif ($_FILES['master_zip']['size'] > 100 * 1024 * 1024) {
        $flash_err = 'Soubor je příliš velký (max 100 MB).';
    } elseif (strtolower(pathinfo($_FILES['master_zip']['name'], PATHINFO_EXTENSION)) !== 'zip') {
        $flash_err = 'Pouze .zip soubory.';
    } else {
        $res = self_update_apply($_FILES['master_zip']['tmp_name'], $progressLog);
        if ($res['ok']) {
            try { vendor_audit(vendor_db(), $user, 'self_update', null, basename($_FILES['master_zip']['name'])); }
            catch (Throwable $e) {}
            $flash_ok = "✅ Self-update dokončen — verze {$res['version']}. Doporučení: hard refresh (Ctrl+Shift+R).";
        } else {
            $flash_err = $res['error'];
        }
    }
}
```

- [ ] **Step 5: Spusť test — musí projít**

Run: `/Applications/XAMPP/xamppfiles/bin/php vendor/tests/test_self_update.php appek-MASTER-v2.9.141.zip`
Expected: PASS — `✅ TEST PASS — apply + preserve OK`.

- [ ] **Step 6: Ověř UI stránku**

Spusť preview (`appek-php`), otevři `localhost:8756/vendor/self-update.php`. Expected: stránka se načte bez PHP fatal erroru, zobrazí upload formulář.

- [ ] **Step 7: Commit**

```bash
git add vendor/_self_update.php vendor/self-update.php vendor/tests/test_self_update.php
git commit -m "refactor: apply rutina do knihovny vendor/_self_update.php"
```

---

## Task 3: Auto-rollback + rozšířená záloha

**Files:**
- Modify: `vendor/_self_update.php`
- Modify: `vendor/tests/test_self_update.php`

- [ ] **Step 1: Doplň test rollbacku do `vendor/tests/test_self_update.php`**

Před závěrečný `if ($fails)` přidej:

```php
// — rollback test —
$rbRoot = sys_get_temp_dir() . '/appek-test-rb-' . getmypid();
$rbBak  = sys_get_temp_dir() . '/appek-test-bak-' . getmypid();
@mkdir($rbRoot . '/admin', 0777, true);
@mkdir($rbBak  . '/admin', 0777, true);
file_put_contents($rbBak  . '/admin/admin.js', 'PUVODNI');   // záloha = dobrý stav
file_put_contents($rbRoot . '/admin/admin.js', 'ROZBITE');   // webroot = rozbitý stav
$rbLog = [];
self_update_rollback($rbBak, $rbRoot, $rbLog);
if (@file_get_contents($rbRoot . '/admin/admin.js') !== 'PUVODNI') $fails[] = 'rollback neobnovil admin/admin.js';
exec('rm -rf ' . escapeshellarg($rbRoot) . ' ' . escapeshellarg($rbBak));
```

- [ ] **Step 2: Spusť test — rollback část selže**

Run: `/Applications/XAMPP/xamppfiles/bin/php vendor/tests/test_self_update.php appek-MASTER-v2.9.141.zip`
Expected: FAIL — `Call to undefined function self_update_rollback()`.

- [ ] **Step 3: Přidej `self_update_rollback()` do `vendor/_self_update.php`**

```php
/**
 * Obnoví zálohu zpět do webrootu (po neúspěšném nasazení).
 */
function self_update_rollback(string $backupDir, string $webroot, array &$log): bool {
    if (!is_dir($backupDir)) { logStep('❌ Rollback: záloha nenalezena: ' . $backupDir, $log); return false; }
    $rsync = trim(@shell_exec('which rsync') ?: '');
    foreach (scandir($backupDir) ?: [] as $item) {
        if ($item === '.' || $item === '..') continue;
        $src = $backupDir . '/' . $item;
        $dst = $webroot . '/' . $item;
        if (is_dir($src)) {
            if ($rsync) {
                exec($rsync . ' -aI --delete ' . escapeshellarg($src . '/') . ' ' . escapeshellarg($dst . '/'));
            } else {
                $c = 0; $s = 0;
                self_update_copy_dir($src, $dst, [], $c, $s);
            }
        } else {
            @copy($src, $dst);
        }
    }
    logStep('↩️ Rollback dokončen — obnoven stav před nasazením', $log);
    return true;
}
```

- [ ] **Step 4: Rozšiř zálohu a zapoj rollback v `self_update_apply()`**

V `self_update_apply()` v sekci zálohy změň `$backupTargets`:

```php
// PŘED: $backupTargets = ['vendor', 'api', 'sales'];
$backupTargets = ['vendor', 'api', 'sales', 'admin', 'b2b', 'demo', 'pos', 'floorplan'];
```

V sekci „POST-DEPLOY VALIDACE": když validace kritických souborů selže, místo `throw` zavolej rollback a vrať chybu:

```php
foreach ($criticalFiles as $cf) {
    if (!file_exists($webroot . '/' . $cf) || filesize($webroot . '/' . $cf) < 100) {
        logStep("❌ Post-deploy: $cf chybí/prázdný — spouštím auto-rollback", $log);
        self_update_rollback($backupDir, $webroot, $log);
        return ['ok' => false, 'error' => "Post-deploy selhal ($cf) — proveden auto-rollback", 'version' => null, 'health' => null];
    }
}
```

- [ ] **Step 5: Spusť test — musí projít**

Run: `/Applications/XAMPP/xamppfiles/bin/php vendor/tests/test_self_update.php appek-MASTER-v2.9.141.zip`
Expected: PASS (apply + preserve + rollback).

- [ ] **Step 6: Commit**

```bash
git add vendor/_self_update.php vendor/tests/test_self_update.php
git commit -m "feat: auto-rollback + rozšířená záloha (admin/b2b/demo/pos/floorplan)"
```

---

## Task 4: Health check

**Files:**
- Modify: `vendor/_self_update.php`
- Modify: `vendor/tests/test_self_update.php`

- [ ] **Step 1: Doplň test health checku**

Před závěrečný `if ($fails)` přidej:

```php
// — health check test —
$hcRoot = sys_get_temp_dir() . '/appek-test-hc-' . getmypid();
@mkdir($hcRoot . '/api', 0777, true);
@mkdir($hcRoot . '/vendor', 0777, true);
@mkdir($hcRoot . '/admin', 0777, true);
@mkdir($hcRoot . '/b2b', 0777, true);
file_put_contents($hcRoot . '/api/config.php', "<?php define('APP_VERSION', '3.0.0');");
file_put_contents($hcRoot . '/vendor/.appek-version', '3.0.0');
file_put_contents($hcRoot . '/index.html', str_repeat('x', 200));
file_put_contents($hcRoot . '/vendor/index.php', str_repeat('x', 200));
file_put_contents($hcRoot . '/api/_license.php', str_repeat('x', 200));
file_put_contents($hcRoot . '/admin/admin.js', str_repeat('x', 200));
file_put_contents($hcRoot . '/b2b/app.js', str_repeat('x', 200));
$hc = self_update_health_check($hcRoot, '3.0.0');
if (empty($hc['ok'])) $fails[] = 'health check měl projít, ale nahlásil chybu';
$hcBad = self_update_health_check($hcRoot, '9.9.9');
if (!empty($hcBad['ok'])) $fails[] = 'health check měl selhat na nesedící verzi';
exec('rm -rf ' . escapeshellarg($hcRoot));
```

- [ ] **Step 2: Spusť test — health část selže**

Run: `/Applications/XAMPP/xamppfiles/bin/php vendor/tests/test_self_update.php appek-MASTER-v2.9.141.zip`
Expected: FAIL — `Call to undefined function self_update_health_check()`.

- [ ] **Step 3: Přidej `self_update_health_check()` do `vendor/_self_update.php`**

```php
/**
 * Ověří, že nasazená verze sedí a kritické soubory jsou na místě.
 * @return array {ok:bool, checks: array<string,{ok:bool,value?:string}>}
 */
function self_update_health_check(string $webroot, string $expectedVersion): array {
    $checks = [];

    $cfg = @file_get_contents($webroot . '/api/config.php') ?: '';
    preg_match("/APP_VERSION[^']*'([0-9]+\.[0-9]+\.[0-9]+(?:-[a-z0-9]+)?)'/", $cfg, $m);
    $apiVer = $m[1] ?? '?';
    $checks['api_version'] = ['ok' => $apiVer === $expectedVersion, 'value' => $apiVer];

    $vv = trim(@file_get_contents($webroot . '/vendor/.appek-version') ?: '');
    $checks['vendor_version'] = ['ok' => $vv === $expectedVersion, 'value' => $vv ?: '?'];

    foreach (['index.html', 'vendor/index.php', 'api/_license.php', 'admin/admin.js', 'b2b/app.js'] as $f) {
        $p = $webroot . '/' . $f;
        $checks['file:' . $f] = ['ok' => is_file($p) && filesize($p) > 100];
    }

    $allOk = true;
    foreach ($checks as $c) { if (!$c['ok']) { $allOk = false; break; } }
    return ['ok' => $allOk, 'checks' => $checks];
}
```

Pozn.: spec zmiňuje i HTTP-200 kontrolu subdomén. On-disk verifikace (verze + soubory) je spolehlivější a rychlejší než outbound HTTP ze serveru na sebe — pokud soubory na disku sedí, web je správný. HTTP kontrolu lze přidat později; pro F1 stačí on-disk.

- [ ] **Step 4: Zapoj health check do `self_update_apply()`**

V `self_update_apply()` na konci úspěšné větve (změna č. 4 z Task 2 Step 3) — `$health` ber z této funkce:

```php
$health = self_update_health_check($webroot, $version);
logStep($health['ok'] ? '✅ Health check OK' : '⚠️ Health check nahlásil problém', $log);
return ['ok' => true, 'version' => $version, 'health' => $health, 'backupDir' => $backupDir];
```

- [ ] **Step 5: Spusť test — musí projít**

Run: `/Applications/XAMPP/xamppfiles/bin/php vendor/tests/test_self_update.php appek-MASTER-v2.9.141.zip`
Expected: PASS (apply + preserve + rollback + health).

- [ ] **Step 6: Commit**

```bash
git add vendor/_self_update.php vendor/tests/test_self_update.php
git commit -m "feat: post-deploy health check (verze + kritické soubory)"
```

---

## Task 5: `vendor/deploy-hook.php` — token endpoint pro CI

**Files:**
- Create: `vendor/deploy-hook.php`
- Check: `deploy/htaccess-templates/vendor.htaccess` (IP allow-list)

- [ ] **Step 1: Ověř signatury helperů**

Přečti `vendor/_lib.php` — potvrď signatury `vendor_db()` a `vendor_audit($pdo, $user, $action, $entity, $detail)` a způsob načtení `vendor/config.local.php` (kde se definují konstanty). Pokud se liší od předpokladu níže, uprav volání.

- [ ] **Step 2: Napiš `vendor/deploy-hook.php`**

```php
<?php
/**
 * 🚀 DEPLOY HOOK — token-autentizovaný endpoint pro GitHub Actions.
 * CI sem POSTne MASTER zip → spustí ověřená apply rutina. Nepoužívá session.
 */
require_once __DIR__ . '/_lib.php';
require_once __DIR__ . '/_self_update.php';

header('Content-Type: application/json; charset=utf-8');

function deploy_fail(int $code, string $msg, array $log = []): void {
    http_response_code($code);
    echo json_encode(['status' => 'error', 'error' => $msg, 'log' => $log]);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    deploy_fail(405, 'Pouze POST.');
}

if (!defined('DEPLOY_TOKEN') || DEPLOY_TOKEN === '') {
    deploy_fail(500, 'DEPLOY_TOKEN není nastaven v vendor/config.local.php.');
}
$token = $_POST['deploy_token'] ?? '';
if (!is_string($token) || !hash_equals(DEPLOY_TOKEN, $token)) {
    deploy_fail(403, 'Neplatný token.');
}

if (!isset($_FILES['master_zip']) || $_FILES['master_zip']['error'] !== UPLOAD_ERR_OK) {
    deploy_fail(400, 'Chybí master_zip nebo upload selhal.');
}

$lock = sys_get_temp_dir() . '/appek-deploy.lock';
$fh = fopen($lock, 'c');
if (!$fh || !flock($fh, LOCK_EX | LOCK_NB)) {
    deploy_fail(409, 'Jiné nasazení právě probíhá.');
}

$log = [];
try {
    $zipPath = sys_get_temp_dir() . '/appek-deploy-' . date('Ymd-His') . '.zip';
    if (!move_uploaded_file($_FILES['master_zip']['tmp_name'], $zipPath)) {
        deploy_fail(500, 'Nelze uložit nahraný zip.', $log);
    }
    $res = self_update_apply($zipPath, $log);
    @unlink($zipPath);

    try { vendor_audit(vendor_db(), ['email' => 'ci@github', 'role' => 'ci'], 'deploy_hook', null, $res['version'] ?? '?'); }
    catch (Throwable $e) {}

    if (empty($res['ok'])) {
        deploy_fail(500, $res['error'] ?? 'Apply selhal.', $log);
    }
    echo json_encode([
        'status'  => 'ok',
        'version' => $res['version'] ?? null,
        'health'  => $res['health'] ?? null,
        'log'     => $log,
    ]);
} catch (Throwable $e) {
    deploy_fail(500, $e->getMessage(), $log);
} finally {
    flock($fh, LOCK_UN);
    fclose($fh);
}
```

- [ ] **Step 3: Zajisti dosažitelnost endpointu**

Zkontroluj `deploy/htaccess-templates/vendor.htaccess` i živý `vendor/.htaccess`. Pokud obsahují IP allow-list (`Require ip ...` / `Allow from`), přidej výjimku, aby `deploy-hook.php` byl dosažitelný pro GitHub Actions:

```apache
<Files "deploy-hook.php">
    Require all granted
</Files>
```

(Endpoint je chráněn tokenem, takže veřejná dosažitelnost je bezpečná.)

- [ ] **Step 4: Test — špatný token**

Spusť preview (`appek-php`). Run:
`curl -sS -X POST -F "deploy_token=spatny" -F "master_zip=@appek-MASTER-v2.9.141.zip" localhost:8756/vendor/deploy-hook.php`
Expected: JSON `{"status":"error","error":"Neplatný token."}`, HTTP 403.

(Pro tento test musí mít lokální `vendor/config.local.php` definovaný `DEPLOY_TOKEN` — nastav libovolnou hodnotu pro test.)

- [ ] **Step 5: Test — správný token**

Run (TOKEN = hodnota z lokálního configu):
`curl -sS -X POST -F "deploy_token=TOKEN" -F "master_zip=@appek-MASTER-v2.9.141.zip" localhost:8756/vendor/deploy-hook.php`
Expected: JSON `{"status":"ok","version":"2.9.141",...}`. Ověř, že lokální web stále funguje.

- [ ] **Step 6: Commit**

```bash
git add vendor/deploy-hook.php deploy/htaccess-templates/vendor.htaccess
git commit -m "feat: vendor/deploy-hook.php — token endpoint pro CI deploy"
```

---

## Task 6: `.github/workflows/release.yml` — build MASTER + deploy

**Files:**
- Modify: `.github/workflows/release.yml`

- [ ] **Step 1: Uprav krok „Build" a „Create GitHub Release"**

Nahraď stávající kroky „Build distribution ZIP" a „Create GitHub Release":

```yaml
      - name: Build distribution ZIP
        run: |
          chmod +x build-zip.sh
          ./build-zip.sh "${{ steps.ver.outputs.version }}"
          ls -lh appek-v${{ steps.ver.outputs.version }}.zip \
                 appek-MASTER-v${{ steps.ver.outputs.version }}.zip

      - name: Create GitHub Release
        uses: softprops/action-gh-release@v2
        with:
          name: Appek B2B v${{ steps.ver.outputs.version }}
          tag_name: ${{ github.ref_name }}
          generate_release_notes: true
          files: |
            appek-v${{ steps.ver.outputs.version }}.zip
            appek-MASTER-v${{ steps.ver.outputs.version }}.zip
          fail_on_unmatched_files: true
```

- [ ] **Step 2: Přidej krok „Deploy" na konec workflow**

```yaml
      - name: Deploy to server
        run: |
          RESP=$(curl -sS -w '\n%{http_code}' -X POST \
            -F "deploy_token=${{ secrets.DEPLOY_TOKEN }}" \
            -F "master_zip=@appek-MASTER-v${{ steps.ver.outputs.version }}.zip" \
            "${{ secrets.DEPLOY_URL }}/deploy-hook.php")
          BODY=$(echo "$RESP" | sed '$d')
          CODE=$(echo "$RESP" | tail -n1)
          echo "$BODY"
          if [ "$CODE" != "200" ]; then echo "❌ Deploy HTTP $CODE"; exit 1; fi
          echo "$BODY" | grep -q '"status":"ok"' || { echo "❌ Deploy nahlásil chybu"; exit 1; }
          echo "✅ Nasazeno: v${{ steps.ver.outputs.version }}"
```

- [ ] **Step 3: Ověř YAML syntaxi**

Run: `/Applications/XAMPP/xamppfiles/bin/php -r "print_r(yaml_parse_file('.github/workflows/release.yml'));"` — pokud `yaml` rozšíření chybí, zkontroluj indentaci ručně (2 mezery, žádné taby) nebo přes online YAML lint.
Expected: validní YAML, 3 build kroky + deploy krok.

- [ ] **Step 4: Commit**

```bash
git add .github/workflows/release.yml
git commit -m "ci: release.yml staví MASTER zip + krok deploy na server"
```

---

## Task 7: End-to-end — secrets, dokumentace, první ostrý release

**Files:**
- Create: `docs/superpowers/MANUAL-STEPS-F1.md`

- [ ] **Step 1: Vygeneruj deploy token**

Run: `openssl rand -hex 32`
Hodnotu **NEcommituj** — předej ji majiteli v chatu.

- [ ] **Step 2: Napiš `docs/superpowers/MANUAL-STEPS-F1.md`**

```markdown
# Fáze 1 — Ruční kroky pro majitele

Tyto kroky udělej sám (jde o přístupy a secrety).

## 1. Deploy token na server
Otevři `vendor/config.local.php` na serveru (přes File Manager hostingu)
a přidej řádek (TOKEN = hodnota, kterou ti Claude poslal v chatu):

    define('DEPLOY_TOKEN', 'TOKEN');

## 2. GitHub Secrets
github.com/Chossekilla/appek → Settings → Secrets and variables → Actions
→ New repository secret, přidej dva:

  - DEPLOY_TOKEN  = stejná hodnota jako v config.local.php
  - DEPLOY_URL    = https://vendor.appek.cz   (bez koncového lomítka;
                    dokud běží dočasná doména, použij ji)

## 3. MacBook (jednorázově)
  git clone https://github.com/Chossekilla/appek.git
  Vytvoř api/config.local.php pro lokální XAMPP testy
  (viz docs / appek-local-testing).
```

- [ ] **Step 3: Commit dokumentace**

```bash
git add docs/superpowers/MANUAL-STEPS-F1.md
git commit -m "docs: ruční kroky F1 (deploy token, GitHub Secrets)"
```

- [ ] **Step 4: Majitel provede ruční kroky**

Počkej, až majitel potvrdí, že má v `vendor/config.local.php` na serveru
`DEPLOY_TOKEN` a v GitHub Secrets `DEPLOY_TOKEN` + `DEPLOY_URL`.

- [ ] **Step 5: První ostrý release**

Run: `./scripts/release.sh <další-verze>` (např. `2.9.142`).
Sleduj `github.com/Chossekilla/appek/actions` — workflow musí: postavit
oba zipy → vytvořit Release → krok „Deploy" zelený se `"status":"ok"`.

- [ ] **Step 6: Ověř nasazení**

Otevři `vendor.appek.cz`, `appek.cz`, `demo.appek.cz` — patička / topbar
musí hlásit novou verzi. Při chybě je CI job červený a GitHub pošle e-mail;
server se měl auto-rollbacknout do předchozího stavu.

---

## Poznámky k provedení

- **PHP CLI** lokálně: `/Applications/XAMPP/xamppfiles/bin/php` (není v PATH).
- Před testy potřebuješ alespoň jeden `appek-MASTER-v*.zip` — postav `./build-zip.sh 2.9.141`.
- `build-zip.sh` při buildu bumpuje verze a generuje `.build-manifest.json` — to je očekávané; tyto artefakty se commitují běžně.
- Tasky 1–6 jsou čistě kód a dají se provést a otestovat lokálně. Task 7 Step 4–6 vyžaduje majitele (secrets) a ostrý server — to je hranice „dělá Claude / dělá majitel".
