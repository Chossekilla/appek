<?php
/**
 * 🚀 SELF-UPDATE — Aktualizace vendor.appek.cz nahráním MASTER zipu.
 *
 * Workflow:
 *   1. Lokálně:  ./build-zip.sh 2.0.37  →  appek-MASTER-v2.0.37.zip
 *   2. Tady upload → auto-extract → vendor/ + sales + api + landing aktualizováno
 *   3. Zachovává: vendor/config.local.php, vendor/.installed, api/config.local.php, api/.installed,
 *                 vendor/updates_storage/* (customer balíčky)
 *   4. Pre-flight: validace ZIPu (musí mít vendor/_lib.php, index.html, api/_license.php)
 *   5. Pre-extract: backup vendor/ + root html do /tmp (rollback při chybě)
 *   6. Post-extract: ověří klíčové soubory, jinak rollback
 *
 * Bezpečnost:
 *   - Pouze pro role 'admin' (vendor_require_login + role check)
 *   - Soubor max 100 MB
 *   - Whitelist přípony .zip
 *   - Validace ZIP signature
 */
require_once __DIR__ . '/_lib.php';
require_once __DIR__ . '/_layout.php';

$user = vendor_require_login();
$currentPage = 'self-update';

// Pouze admin
if (($user['role'] ?? '') !== 'admin') {
    http_response_code(403);
    echo '<h1>403 Forbidden</h1><p>Pouze admin smí spouštět self-update.</p>';
    exit;
}

$flash_ok = null;
$flash_err = null;
$progressLog = [];

$webroot = realpath(__DIR__ . '/..');  // /home/u880385154/domains/appek.cz/public_html

/**
 * Helper — log do progressLog + případně i do error_log.
 */
function logStep(string $msg, &$log) {
    $log[] = '[' . date('H:i:s') . '] ' . $msg;
    error_log('[self-update] ' . $msg);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['master_zip'])) {

    try {
        // ─── 1. VALIDACE UPLOADU ─────────────────────────────────
        if ($_FILES['master_zip']['error'] !== UPLOAD_ERR_OK) {
            throw new Exception('Upload chyba: kód ' . $_FILES['master_zip']['error']);
        }
        if ($_FILES['master_zip']['size'] > 100 * 1024 * 1024) {
            throw new Exception('Soubor je příliš velký (max 100 MB).');
        }
        $ext = strtolower(pathinfo($_FILES['master_zip']['name'], PATHINFO_EXTENSION));
        if ($ext !== 'zip') {
            throw new Exception('Pouze .zip soubory.');
        }
        if (!class_exists('ZipArchive')) {
            throw new Exception('PHP rozšíření ZipArchive není k dispozici.');
        }

        $uploadedZip = $_FILES['master_zip']['tmp_name'];
        logStep('Upload OK · ' . number_format($_FILES['master_zip']['size'] / 1024 / 1024, 2, ',', ' ') . ' MB', $progressLog);

        // ─── 2. PRE-FLIGHT: zkontroluj že je to APPEK master ─────
        $zip = new ZipArchive();
        if ($zip->open($uploadedZip) !== TRUE) {
            throw new Exception('ZIP soubor je poškozený nebo neplatný.');
        }
        $requiredFiles = [
            'vendor/_lib.php',
            'vendor/_license.php',
            'vendor/_layout.php',
            'vendor/index.php',
            'api/_license.php',
            'api/config.php',
            'index.html',
        ];
        $missing = [];
        foreach ($requiredFiles as $r) {
            if ($zip->locateName($r) === false) $missing[] = $r;
        }
        if ($missing) {
            $zip->close();
            throw new Exception('ZIP není validní APPEK MASTER bundle. Chybí: ' . implode(', ', $missing));
        }

        $totalFiles = $zip->numFiles;
        logStep("Pre-flight OK · $totalFiles souborů v balíčku · obsahuje vendor/ + api/ + landing", $progressLog);

        // ─── 3. PRESERVE — souborové cesty které NESMÍ být přepsány ──
        $preserve = [
            'vendor/config.local.php',
            'vendor/.installed',
            'api/config.local.php',
            'api/.installed',
        ];
        $preserveData = [];
        foreach ($preserve as $p) {
            $full = $webroot . '/' . $p;
            if (file_exists($full)) {
                $preserveData[$p] = file_get_contents($full);
                logStep('Preserving: ' . $p . ' (' . number_format(strlen($preserveData[$p])) . ' B)', $progressLog);
            }
        }

        // updates_storage — chceme zachovat customer balíčky uploadnuté přes vendor/updates.php
        // ZIP master /vendor/updates_storage by měl být prázdný (exclude v build-zip.sh)
        $storagePath = $webroot . '/vendor/updates_storage';
        $existingStorage = [];
        if (is_dir($storagePath)) {
            foreach (glob($storagePath . '/appek-update-*.zip') ?: [] as $f) {
                $existingStorage[] = basename($f);
            }
            logStep('Found ' . count($existingStorage) . ' existing customer update bundles (will keep)', $progressLog);
        }

        // ─── 4. BACKUP před extrakcí ─────────────────────────────
        $backupDir = sys_get_temp_dir() . '/appek-self-update-backup-' . date('Ymd-His');
        @mkdir($backupDir, 0755, true);
        // Stručná záloha klíčových dirů (rollback bez ztráty dat)
        $backupTargets = ['vendor', 'api', 'sales'];
        foreach ($backupTargets as $b) {
            $src = $webroot . '/' . $b;
            if (is_dir($src)) {
                $cmd = "cp -R " . escapeshellarg($src) . ' ' . escapeshellarg($backupDir . '/' . $b);
                @exec($cmd);
            }
        }
        // Root HTML soubory
        foreach (['index.html', 'instalace.html', 'checkout.html', 'obchodni-podminky.html', 'zasady-ochrany-soukromi.html', 'payment-done.html'] as $f) {
            $src = $webroot . '/' . $f;
            if (file_exists($src)) {
                @copy($src, $backupDir . '/' . $f);
            }
        }
        logStep('Backup vytvořen v ' . $backupDir, $progressLog);

        // ─── 5. EXTRAKCE ZIPu ────────────────────────────────────
        $extractDir = sys_get_temp_dir() . '/appek-self-update-extract-' . date('Ymd-His');
        @mkdir($extractDir, 0755, true);
        if (!$zip->extractTo($extractDir)) {
            $zip->close();
            throw new Exception('Extrakce selhala. Zkontroluj práva /tmp.');
        }
        $zip->close();
        logStep('ZIP extrahován do ' . $extractDir, $progressLog);

        // ─── 6. POST-EXTRACT VALIDACE ─────────────────────────────
        foreach ($requiredFiles as $r) {
            if (!file_exists($extractDir . '/' . $r)) {
                throw new Exception("Po extrakci chybí soubor: $r — ZIP je porušený.");
            }
        }
        logStep('Post-extract validace OK', $progressLog);

        // ─── 6.5. PŘEKLOPIT embedded customer bundle z MASTER ─────
        // 🆕 v2.0.92 — Před sync s --exclude vendor/updates_storage/ uložíme path
        // k embedded appek-update-VERSION.zip z MASTER ZIPu. Pak ho po sync ručně
        // zkopírujeme do vendor/updates_storage/. Tím se PROPÍSE embedded bundle z MASTERu,
        // ale existující customer uploads zůstanou.
        $embeddedBundles = [];
        $extractStorage = $extractDir . '/vendor/updates_storage';
        if (is_dir($extractStorage)) {
            foreach (glob($extractStorage . '/appek-update-*.zip') ?: [] as $f) {
                $embeddedBundles[basename($f)] = $f;
            }
            logStep('Found ' . count($embeddedBundles) . ' embedded bundles in MASTER ZIP: ' . implode(', ', array_keys($embeddedBundles)), $progressLog);
        }

        // ─── 7. SYNC do webrootu (kopíruje vše) ──────────────────
        // Použijeme rsync-like přístup s rsync příkazem (pokud dostupný), jinak fallback rekurzivně cp
        $rsyncBin = trim(@shell_exec('which rsync') ?: '');
        if ($rsyncBin) {
            // 🆕 v2.9.70 — -aI místo -a. Bez -I (--ignore-times) rsync přeskakuje
            // soubory, kde sedí velikost+mtime ("quick check"). Po extrakci ZIPu
            // dostane soubor mtime uložený v ZIPu → admin.css/admin.js mohly
            // zůstat STARÉ ("CSS se nepřepisuje"). -I VYNUTÍ přepsání KAŽDÉHO souboru.
            $excludes = '';
            foreach ($preserve as $p) {
                $excludes .= ' --exclude=' . escapeshellarg($p);
            }
            // updates_storage NEPŘEPSAT (zachová customer custom uploads)
            $excludes .= ' --exclude=vendor/updates_storage/';
            $cmd = "$rsyncBin -aI $excludes "
                 . escapeshellarg($extractDir . '/') . ' '
                 . escapeshellarg($webroot . '/');
            $out = []; $ret = 0;
            exec($cmd . ' 2>&1', $out, $ret);
            if ($ret !== 0) {
                throw new Exception('Rsync selhal (' . $ret . '): ' . implode("\n", $out));
            }
            logStep('Rsync sync dokončen', $progressLog);
        } else {
            // Fallback: rekurzivní kopírování přes PHP
            $copied = 0;
            $skipped = 0;
            self_update_copy_dir($extractDir, $webroot, $preserve, $copied, $skipped);
            logStep("Fallback copy: $copied souborů zkopírováno, $skipped přeskočeno (preserve)", $progressLog);
        }

        // ─── 8. RESTORE preserved files ──────────────────────────
        foreach ($preserveData as $p => $data) {
            $full = $webroot . '/' . $p;
            @mkdir(dirname($full), 0755, true);
            file_put_contents($full, $data);
            logStep('Restored: ' . $p, $progressLog);
        }

        // 🆕 v2.0.92 — PŘEKLOPIT embedded customer bundles z MASTER ZIPu do storage
        // Tím se PROPÍSE bundle z MASTERu (vyrobený přes build-zip.sh v2.0.70+),
        // ale customer custom uploads zůstanou (rsync je nepřepsal).
        $storageDir = $webroot . '/vendor/updates_storage';
        @mkdir($storageDir, 0755, true);
        $bundlesCopied = 0;
        foreach ($embeddedBundles as $name => $srcPath) {
            $dstPath = $storageDir . '/' . $name;
            // Vždy přepsat — embedded bundle z MASTER ZIPu je AUTHORITATIVE source
            if (@copy($srcPath, $dstPath)) {
                $bundlesCopied++;
                logStep('✅ Bundle propsán: ' . $name . ' (' . round(filesize($dstPath) / 1024 / 1024, 2) . ' MB)', $progressLog);
            } else {
                logStep('⚠️ Nelze propsat bundle: ' . $name, $progressLog);
            }
        }
        if ($bundlesCopied === 0 && !empty($embeddedBundles)) {
            logStep('❌ KRITICKÉ: ' . count($embeddedBundles) . ' embedded bundles nebylo propsáno do storage. Zkontroluj oprávnění.', $progressLog);
        }

        // ─── 9. POST-DEPLOY VALIDACE ─────────────────────────────
        $criticalFiles = [
            'vendor/index.php',
            'vendor/_lib.php',
            'api/_license.php',
            'index.html',
        ];
        foreach ($criticalFiles as $cf) {
            if (!file_exists($webroot . '/' . $cf) || filesize($webroot . '/' . $cf) < 100) {
                throw new Exception("Post-deploy: $cf je prázdný nebo chybí — rollback potřeba!");
            }
        }
        logStep('Post-deploy validace OK · vendor.appek.cz aktualizován', $progressLog);

        // ─── 10. AUTO-BUILD CUSTOMER ZIP & PUBLISH ──────────────
        $customerZipMeta = self_update_build_customer_zip($webroot, $progressLog);

        // ─── 11. CLEANUP extract dir (backup ponechán pro rollback) ──
        self_update_rmdir($extractDir);
        logStep('Cleanup hotov · backup zůstává v /tmp pro případný rollback', $progressLog);

        // Audit log
        try {
            $pdo = vendor_db();
            vendor_audit($pdo, $user, 'self_update', null, basename($_FILES['master_zip']['name']));
        } catch (Throwable $e) {}

        $custMsg = '';
        if ($customerZipMeta) {
            $custMsg = "\n📦 Customer ZIP v{$customerZipMeta['version']} (" . number_format($customerZipMeta['size'] / 1024 / 1024, 2, ',', ' ') . " MB) auto-publikován v Updates → zákazníci si stáhnou přes API.";
        }
        $flash_ok = "✅ Self-update dokončen! vendor.appek.cz nyní běží na novém balíčku.{$custMsg}\nDoporučení: hard refresh prohlížeče (Ctrl+Shift+R) pro načtení nového CSS/JS.";

    } catch (Throwable $e) {
        $flash_err = $e->getMessage();
        logStep('❌ CHYBA: ' . $e->getMessage(), $progressLog);
        // Pokud máme backup, povol rollback v UI
    }
}

/**
 * Rekurzivně zkopíruje obsah $src do $dst.
 * Přeskakuje cesty v $preserve (relativní k $src).
 */
function self_update_copy_dir(string $src, string $dst, array $preserve, int &$copied, int &$skipped, string $rel = ''): void {
    $items = @scandir($src) ?: [];
    foreach ($items as $item) {
        if ($item === '.' || $item === '..') continue;
        $srcPath = $src . '/' . $item;
        $dstPath = $dst . '/' . $item;
        $relPath = $rel === '' ? $item : "$rel/$item";

        // Skip preserve paths
        foreach ($preserve as $p) {
            if ($relPath === $p) { $skipped++; continue 2; }
        }
        // Skip updates_storage
        if ($relPath === 'vendor/updates_storage' && is_dir($srcPath)) { $skipped++; continue; }

        if (is_dir($srcPath)) {
            @mkdir($dstPath, 0755, true);
            self_update_copy_dir($srcPath, $dstPath, $preserve, $copied, $skipped, $relPath);
        } else {
            @copy($srcPath, $dstPath);
            $copied++;
        }
    }
}

function self_update_rmdir(string $dir): void {
    if (!is_dir($dir)) return;
    foreach (scandir($dir) ?: [] as $item) {
        if ($item === '.' || $item === '..') continue;
        $p = $dir . '/' . $item;
        is_dir($p) ? self_update_rmdir($p) : @unlink($p);
    }
    @rmdir($dir);
}

/**
 * 📦 AUTO-BUILD CUSTOMER ZIP po self-update.
 *
 * Z čerstvě nahraného master vyzipuje customer-relevantní složky:
 *   api/ + admin/ + b2b/ + install.php + index.php (router) + root docs
 *
 * Pak vloží do vendor/updates_storage + INSERT do vendor_updates jako 'published'.
 * Tím se update automaticky objeví zákazníkům přes /api/updates_check.php.
 *
 * @return array|null  ['version' => 'X.Y.Z', 'size' => bytes, 'file' => path] nebo null při chybě
 */
function self_update_build_customer_zip(string $webroot, array &$log): ?array {
    try {
        // 🆕 v2.9.62 — Detekuj verzi z api/config.php (APP_VERSION) — JEDINÝ zdroj pravdy.
        // Předtím se hledala $version v _layout.php, ta tam ale není (verze přes funkci)
        // → regex selhal → customer bundle se NIKDY nepublikoval. Tohle byl hlavní bug.
        $version = null;
        $cfgFile = $webroot . '/api/config.php';
        if (file_exists($cfgFile)) {
            $cfg = file_get_contents($cfgFile);
            if (preg_match("/APP_VERSION[^']*'([0-9]+\.[0-9]+\.[0-9]+(?:-[a-z0-9]+)?)'/", $cfg, $m)) {
                $version = $m[1];
            }
        }
        // Fallback — odvoď verzi z názvu embedded bundle v updates_storage/
        if (!$version) {
            $storageGlob = glob($webroot . '/vendor/updates_storage/appek-update-*.zip');
            if ($storageGlob) {
                // vezmi nejvyšší semver z dostupných bundlů
                $vers = [];
                foreach ($storageGlob as $f) {
                    if (preg_match("/appek-update-([0-9]+\.[0-9]+\.[0-9]+)\.zip$/", $f, $mm)) $vers[] = $mm[1];
                }
                if ($vers) {
                    usort($vers, 'version_compare');
                    $version = end($vers);
                }
            }
        }
        if (!$version) {
            logStep('⚠️ Customer ZIP skip: verze nedohledána (api/config.php ani embedded bundle)', $log);
            return null;
        }
        logStep("Customer ZIP build · verze {$version}", $log);

        $storageDir = $webroot . '/vendor/updates_storage';
        if (!is_dir($storageDir)) @mkdir($storageDir, 0755, true);
        $zipFile = $storageDir . '/appek-update-' . $version . '.zip';

        // 🆕 v2.0.83 — INTEGRITY-FIRST: embedded ZIP z MASTER musí existovat a obsahovat admin/, b2b/, api/.
        // Pokud chybí jakýkoliv kritický adresář, ABORT s clear errorem. NIKDY nevytvářet broken bundle —
        // to byla katastrofa, která způsobila že customers měli config.php aktualizovaný, ale admin.js starý.
        //
        // Pravidla:
        //   ✅ Embedded ZIP existuje + má admin/ + b2b/ + api/ → USE IT, skip rebuild
        //   ⚠️ Embedded ZIP existuje ale NEKOMPLETNÍ → ABORT s důvodem
        //   ❌ Embedded ZIP chybí → ABORT (vendor MASTER ZIP neměl proper embed — upgrade build-zip.sh)
        //   ❌ Rebuild fallback ZRUŠEN — způsoboval broken bundles
        $integrity = self_update_verify_bundle_integrity($zipFile);
        if (!$integrity['ok']) {
            // Smaž broken bundle (kdyby existoval) aby se nezveřejnil
            if (file_exists($zipFile) && $integrity['size'] < 500 * 1024) {
                @unlink($zipFile);
                logStep('🗑️ Smazán neúplný bundle (< 500 KB): ' . basename($zipFile), $log);
            }
            logStep('❌ ABORT: ' . $integrity['error'], $log);
            logStep('💡 Řešení: lokálně build-zip.sh v2.0.70+ vygeneruje MASTER ZIP s embedded customer ZIPem v vendor/updates_storage/. Nahraj nový MASTER.', $log);
            return null;
        }
        logStep('✅ Bundle integrity OK: ' . basename($zipFile)
            . ' · ' . round($integrity['size'] / 1024 / 1024, 2) . ' MB · '
            . $integrity['file_count'] . ' files · '
            . 'has admin=' . ($integrity['has_admin'] ? '✓' : '✗')
            . ' · b2b=' . ($integrity['has_b2b'] ? '✓' : '✗')
            . ' · api=' . ($integrity['has_api'] ? '✓' : '✗'), $log);

        $size = filesize($zipFile) ?: 0;
        $sha  = hash_file('sha256', $zipFile);

        // INSERT/UPDATE záznam ve vendor_updates
        try {
            $pdo = vendor_db();
            $exists = $pdo->prepare("SELECT id FROM vendor_updates WHERE version = :v");
            $exists->execute(['v' => $version]);
            $existId = (int) $exists->fetchColumn();

            if ($existId) {
                $pdo->prepare("
                    UPDATE vendor_updates SET
                        file_path = :fp, file_size = :fs, checksum_sha256 = :cs,
                        status = 'published', published_at = COALESCE(published_at, NOW())
                    WHERE id = :id
                ")->execute([
                    'fp' => 'appek-update-' . $version . '.zip',
                    'fs' => $size,
                    'cs' => $sha,
                    'id' => $existId,
                ]);
                logStep("vendor_updates updated · id={$existId}", $log);
            } else {
                $pdo->prepare("
                    INSERT INTO vendor_updates (version, channel, file_path, file_size, checksum_sha256,
                                                manifest_json, changelog_md, min_version, packages_required,
                                                status, published_at)
                    VALUES (:v, 'stable', :fp, :fs, :cs, :mj, :cl, NULL, NULL, 'published', NOW())
                ")->execute([
                    'v'  => $version,
                    'fp' => 'appek-update-' . $version . '.zip',
                    'fs' => $size,
                    'cs' => $sha,
                    'mj' => json_encode(['version' => $version, 'type' => 'customer-update']),
                    'cl' => "Verze {$version} — auto-publikováno self-update modulem.\n\nObsahuje: api/ + admin/ + b2b/ + install.php",
                ]);
                logStep("vendor_updates záznam vytvořen (id={$pdo->lastInsertId()})", $log);
            }
        } catch (Throwable $e) {
            logStep('⚠️ vendor_updates INSERT selhal: ' . $e->getMessage(), $log);
        }

        // 🆕 v2.9.62 — Zapiš verzi do vendor/.appek-version
        // vendor.appek.cz je samostatný docroot — nemá api/config.php vedle sebe,
        // takže topbar verzi čte z tohoto souboru (viz vendor_appek_version()).
        try {
            @file_put_contents($webroot . '/vendor/.appek-version', $version);
            logStep("vendor/.appek-version → {$version}", $log);
        } catch (Throwable $e) {}

        return ['version' => $version, 'size' => $size, 'file' => basename($zipFile)];

    } catch (Throwable $e) {
        logStep('❌ Customer ZIP build chyba: ' . $e->getMessage(), $log);
        return null;
    }
}

function self_update_add_dir_to_zip(ZipArchive $zip, string $srcDir, string $zipPrefix, array $excludes = []): void {
    $items = @scandir($srcDir) ?: [];
    foreach ($items as $item) {
        if ($item === '.' || $item === '..' || $item === '.DS_Store') continue;
        $srcPath = $srcDir . '/' . $item;
        $zipPath = $zipPrefix . '/' . $item;
        // Skip excludes
        foreach ($excludes as $ex) {
            if ($zipPath === $ex || str_starts_with($zipPath, $ex . '/')) continue 2;
        }
        if (is_dir($srcPath)) {
            $zip->addEmptyDir($zipPath);
            self_update_add_dir_to_zip($zip, $srcPath, $zipPath, $excludes);
        } else {
            $zip->addFile($srcPath, $zipPath);
        }
    }
}

/**
 * 🆕 v2.0.67 — Přidá soubory do bundle ZIP pod files/ prefix + sbírá SHA-256 do mapy.
 * Bundle struktura: manifest.json (root) + files/<relPath> (subtree).
 * Toto je formát, který očekává updates_apply.php (validace manifest['files'] + hash check).
 *
 * @param ZipArchive $zip       cílový ZIP
 * @param string $srcDir        absolutní cesta ke zdrojové složce (např. /var/www/api)
 * @param string $relPrefix     relativní prefix pro manifest (např. 'api') — files/<relPrefix>/<file>
 * @param array $excludes       relativní cesty k vynechání
 * @param array &$filesMap      [relPath => sha256] — naplní se za běhu
 */
function self_update_add_dir_to_bundle(ZipArchive $zip, string $srcDir, string $relPrefix, array $excludes, array &$filesMap): void {
    $items = @scandir($srcDir) ?: [];
    foreach ($items as $item) {
        if ($item === '.' || $item === '..' || $item === '.DS_Store') continue;
        $srcPath = $srcDir . '/' . $item;
        $relPath = $relPrefix . '/' . $item;
        // Skip excludes
        foreach ($excludes as $ex) {
            if ($relPath === $ex || str_starts_with($relPath, $ex . '/')) continue 2;
        }
        if (is_dir($srcPath)) {
            self_update_add_dir_to_bundle($zip, $srcPath, $relPath, $excludes, $filesMap);
        } else {
            $zipPath = 'files/' . $relPath;
            $zip->addFile($srcPath, $zipPath);
            $filesMap[$relPath] = hash_file('sha256', $srcPath);
        }
    }
}

/**
 * 🆕 v2.0.83 — INTEGRITY VERIFICATION před zveřejněním bundle.
 *
 * Otestuje že bundle:
 *   1. Existuje na disku
 *   2. Není podezřele malý (< 500 KB = určitě nemá admin/+b2b/)
 *   3. Je validní ZIP
 *   4. Obsahuje kritické adresáře: api/, admin/, b2b/
 *   5. Obsahuje kritické soubory: api/config.php, admin/index.html, admin/admin.js
 *
 * Podporuje OBA formáty:
 *   - RAW customer ZIP: api/, admin/, b2b/ přímo
 *   - BUNDLE formát: manifest.json + files/api/, files/admin/, files/b2b/
 *
 * @return array {ok, size, file_count, has_admin, has_b2b, has_api, error}
 */
function self_update_verify_bundle_integrity(string $zipPath): array {
    $result = [
        'ok' => false,
        'size' => 0,
        'file_count' => 0,
        'has_admin' => false,
        'has_b2b' => false,
        'has_api' => false,
        'has_admin_js' => false,
        'has_admin_index' => false,
        'has_api_config' => false,
        'format' => null,  // 'raw' | 'bundle'
        'error' => null,
    ];

    if (!file_exists($zipPath)) {
        $result['error'] = 'Bundle neexistuje na disku (vendor/updates_storage/' . basename($zipPath) . '). MASTER ZIP musel obsahovat embedded customer ZIP.';
        return $result;
    }

    $result['size'] = filesize($zipPath);
    if ($result['size'] < 500 * 1024) {
        $result['error'] = sprintf('Bundle podezřele malý (%.1f KB < 500 KB). Pravděpodobně chybí admin/+b2b/. Nahraj nový MASTER ZIP postavený přes build-zip.sh v2.0.70+.', $result['size'] / 1024);
        return $result;
    }

    if (!class_exists('ZipArchive')) {
        $result['error'] = 'PHP ZipArchive není dostupný — nelze ověřit integritu.';
        return $result;
    }

    $zip = new ZipArchive();
    if ($zip->open($zipPath) !== true) {
        $result['error'] = 'Bundle není validní ZIP soubor.';
        return $result;
    }

    $count = $zip->numFiles;
    $result['file_count'] = $count;

    // Detekuj format: bundle (manifest+files/) vs raw
    $hasManifest = $zip->locateName('manifest.json') !== false;
    $hasFilesDir = false;
    for ($i = 0; $i < min($count, 50); $i++) {
        $name = $zip->getNameIndex($i);
        if (strpos($name, 'files/') === 0) { $hasFilesDir = true; break; }
    }
    $result['format'] = ($hasManifest && $hasFilesDir) ? 'bundle' : 'raw';

    // Prefix podle formátu
    $prefix = $result['format'] === 'bundle' ? 'files/' : '';

    // Iteruj a hledej kritické soubory
    for ($i = 0; $i < $count; $i++) {
        $name = $zip->getNameIndex($i);
        if (strpos($name, $prefix . 'admin/') === 0) $result['has_admin'] = true;
        if (strpos($name, $prefix . 'b2b/') === 0)   $result['has_b2b'] = true;
        if (strpos($name, $prefix . 'api/') === 0)   $result['has_api'] = true;
        if ($name === $prefix . 'admin/admin.js')    $result['has_admin_js'] = true;
        if ($name === $prefix . 'admin/index.html')  $result['has_admin_index'] = true;
        if ($name === $prefix . 'api/config.php')    $result['has_api_config'] = true;
    }
    $zip->close();

    // Validace kritických adresářů
    $missing = [];
    if (!$result['has_admin']) $missing[] = 'admin/';
    if (!$result['has_b2b'])   $missing[] = 'b2b/';
    if (!$result['has_api'])   $missing[] = 'api/';
    if (!$result['has_admin_js'])    $missing[] = 'admin/admin.js';
    if (!$result['has_admin_index']) $missing[] = 'admin/index.html';
    if (!$result['has_api_config'])  $missing[] = 'api/config.php';

    if (!empty($missing)) {
        $result['error'] = 'Bundle NEKOMPLETNÍ — chybí: ' . implode(', ', $missing)
            . '. To je root cause "config.php updated ale admin.js zůstává starý" bugu. Nahraj fresh MASTER ZIP.';
        return $result;
    }

    $result['ok'] = true;
    return $result;
}

?><!DOCTYPE html>
<html lang="cs">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="robots" content="noindex,nofollow">
<title>🚀 Self-update — APPEK Master</title>
<link rel="stylesheet" href="style.css?v=2.0.38">
<style>
  .su-card { background: #fff; border-radius: 14px; padding: 28px 32px; box-shadow: 0 1px 3px rgba(0,0,0,0.05); margin-bottom: 20px; }
  .su-card h2 { margin: 0 0 12px; font-size: 18px; }
  .su-step {
    display: flex; gap: 14px; padding: 14px 0; border-bottom: 1px solid #f0f0f3;
  }
  .su-step:last-child { border-bottom: none; }
  .su-step .num {
    width: 32px; height: 32px; border-radius: 50%;
    background: linear-gradient(135deg, #BA7517, #854F0B); color: #fff;
    display: inline-flex; align-items: center; justify-content: center;
    font-weight: 700; font-size: 14px; flex-shrink: 0;
  }
  .su-step .body strong { display: block; font-size: 14px; margin-bottom: 3px; }
  .su-step .body { color: #6e6e73; font-size: 13px; line-height: 1.5; }
  .su-step .body code { background: #f5f5f7; padding: 2px 6px; border-radius: 4px; font-family: 'SF Mono', monospace; font-size: 12px; color: #1d1d1f; }

  .upload-zone {
    border: 2px dashed #d2d2d7; border-radius: 14px; padding: 36px 24px;
    text-align: center; background: #fafafa; transition: all 0.2s;
  }
  .upload-zone:hover, .upload-zone.dragover {
    border-color: #BA7517; background: #fff8e8;
  }
  .upload-zone .ico { font-size: 48px; margin-bottom: 12px; }
  .upload-zone input[type="file"] { display: block; margin: 14px auto; }
  .upload-zone .fname { font-weight: 600; color: #1d1d1f; margin-top: 8px; }

  .progress-log {
    background: #1d1d1f; color: #fff; border-radius: 10px; padding: 16px 20px;
    font-family: 'SF Mono', Menlo, monospace; font-size: 12.5px; line-height: 1.7;
    max-height: 320px; overflow-y: auto;
  }
  .progress-log .ok { color: #34c759; }
  .progress-log .err { color: #ff6b6b; }

  .alert { padding: 14px 18px; border-radius: 10px; margin-bottom: 14px; font-size: 14px; line-height: 1.6; }
  .alert.ok  { background: #d4edda; color: #155724; white-space: pre-line; }
  .alert.err { background: #f8d7da; color: #721c24; }
  .alert.info { background: rgba(0,122,255,0.10); color: #0058b8; }

  .btn-deploy {
    width: 100%; padding: 14px; margin-top: 14px;
    background: linear-gradient(180deg, #BA7517, #854F0B);
    color: #fff; border: none; border-radius: 10px;
    font-weight: 700; font-size: 15px; cursor: pointer;
  }
  .btn-deploy:hover { opacity: 0.92; transform: translateY(-1px); box-shadow: 0 4px 12px rgba(186,117,23,0.3); }
  .btn-deploy[disabled] { opacity: 0.5; cursor: not-allowed; transform: none; box-shadow: none; }

  .danger-note {
    background: #FEF3C7; color: #92400E; padding: 12px 16px; border-radius: 10px;
    font-size: 13px; line-height: 1.6; margin: 14px 0;
  }
</style>
</head>
<body>

<?php vendor_render_topbar($user, $currentPage); ?>
<?php vendor_render_back(); ?>

<main class="page-master">

  <div class="page-header-master">
    <h1>🚀 Self-update</h1>
    <div style="font-size:13px;color:#86868b">Aktualizace vendor.appek.cz nahráním MASTER zipu</div>
  </div>

  <?php if ($flash_ok): ?><div class="alert ok">✅ <?= htmlspecialchars($flash_ok) ?></div><?php endif; ?>
  <?php if ($flash_err): ?><div class="alert err">❌ <?= htmlspecialchars($flash_err) ?></div><?php endif; ?>

  <?php if ($progressLog): ?>
    <!-- Progress log: full-width (důležité, ukáže se po akci) -->
    <div class="su-card">
      <h2>📋 Průběh</h2>
      <div class="progress-log">
        <?php foreach ($progressLog as $line): ?>
          <div class="<?= strpos($line, '❌') !== false ? 'err' : (strpos($line, '✅') !== false ? 'ok' : '') ?>"><?= htmlspecialchars($line) ?></div>
        <?php endforeach; ?>
      </div>
    </div>
  <?php endif; ?>

  <!-- 🆕 v2.0.80 — 2-column grid: vlevo akce (upload), vpravo info (jak to funguje) -->
  <div class="su-grid">

    <!-- LEVÝ SLOUPEC — Upload action (primary) -->
    <div class="su-col-primary">
      <div class="su-card">
        <h2>📥 Nahraj MASTER zip</h2>
        <p style="color:#6e6e73;font-size:14px;line-height:1.6;margin:0 0 14px">
          Lokálně postavený balíček <code>appek-MASTER-vX.Y.Z.zip</code> obsahuje <strong>vendor/ + api/ + sales/ + landing pages</strong>.
          Po uploadu se vendor.appek.cz automaticky aktualizuje. <strong>Tvoje config + customer balíčky zůstanou.</strong>
        </p>

        <form method="POST" enctype="multipart/form-data" onsubmit="return confirmDeploy()">
          <div class="upload-zone" id="dz">
            <div class="ico">📦</div>
            <strong>appek-MASTER-vX.Y.Z.zip</strong>
            <input type="file" name="master_zip" id="master_zip" accept=".zip" required onchange="onFileSelect()">
            <div class="fname" id="fname">Vyber soubor nebo přetáhni sem</div>
          </div>

          <div class="danger-note">
            ⚠️ <strong>Self-update probíhá za běhu.</strong> Doporučení:
            <ul style="margin:6px 0 0;padding-left:20px">
              <li>Před uploadem zálohuj DB (phpMyAdmin → Export)</li>
              <li>Operace trvá ~5–30 sekund podle velikosti</li>
              <li>Pokud se něco pokazí, backup v <code>/tmp/appek-self-update-backup-*</code></li>
            </ul>
          </div>

          <button type="submit" class="btn-deploy" id="btn-deploy" disabled>🚀 Spustit self-update</button>
        </form>
      </div>

      <div class="alert info" style="margin-top:14px">
        💡 <strong>Pro customer distribuci</strong> (zákazníci stahují updaty přes update modul) použij <a href="updates.php" style="color:#0058b8;font-weight:600">🔄 Updates / Customer distribuce</a>.
        Tahle stránka je <strong>jen pro self-update vendor.appek.cz</strong>.
      </div>
    </div>

    <!-- PRAVÝ SLOUPEC — Info "Jak to funguje" (sticky) -->
    <aside class="su-col-info">
      <div class="su-card">
        <h2>🧠 Jak to funguje</h2>
        <div class="su-step">
          <div class="num">1</div>
          <div class="body">
            <strong>Pre-flight check</strong>
            Ověří že ZIP obsahuje očekávané soubory (<code>vendor/_lib.php</code>, <code>api/_license.php</code>, <code>index.html</code>).
            Pokud chybí cokoliv kritického, upload se odmítne před extrakcí.
          </div>
        </div>
        <div class="su-step">
          <div class="num">2</div>
          <div class="body">
            <strong>Preserve konfigurace</strong>
            Tyto soubory NIKDY nepřepíše: <code>vendor/config.local.php</code> · <code>vendor/.installed</code> · <code>api/config.local.php</code> · <code>api/.installed</code> · <code>vendor/updates_storage/*.zip</code> (customer balíčky)
          </div>
        </div>
        <div class="su-step">
          <div class="num">3</div>
          <div class="body">
            <strong>Backup do /tmp</strong>
            Zazálohuje <code>vendor/</code>, <code>api/</code>, <code>sales/</code> a root HTML do <code>/tmp/appek-self-update-backup-YYYYMMDD-HHMMSS</code> pro rollback.
          </div>
        </div>
        <div class="su-step">
          <div class="num">4</div>
          <div class="body">
            <strong>Atomic deploy</strong>
            Použije <code>rsync</code> (s exclude pro preserve) nebo PHP fallback. Sync je INCREMENTAL — nepřepisuje soubory které jsou stejné.
          </div>
        </div>
        <div class="su-step">
          <div class="num">5</div>
          <div class="body">
            <strong>Post-deploy validace</strong>
            Po extrakci zkontroluje že kritické soubory existují a nejsou prázdné. Pokud ne, je třeba rollback z backupu.
          </div>
        </div>
        <div class="su-step">
          <div class="num">6</div>
          <div class="body">
            <strong>Audit log</strong>
            Zaznamená do <code>vendor_audit_log</code>: kdo + kdy + verze ZIPu.
          </div>
        </div>
      </div>
    </aside>

  </div>

  <style>
    /* 🆕 v2.0.80 — 2-column grid layout pro self-update */
    .su-grid {
      display: grid;
      grid-template-columns: minmax(0, 1.2fr) minmax(0, 1fr);
      gap: 20px;
      align-items: start;
    }
    .su-col-primary, .su-col-info { min-width: 0; }
    .su-col-info { position: sticky; top: 76px; }
    /* Stack na mobile / tabletu */
    @media (max-width: 980px) {
      .su-grid { grid-template-columns: 1fr; }
      .su-col-info { position: static; }
    }
    /* Wider desktop — 3:2 ratio pro lepší poměr */
    @media (min-width: 1280px) {
      .su-grid { grid-template-columns: 3fr 2fr; }
    }
  </style>

</main>

<script>
function onFileSelect() {
  const f = document.getElementById('master_zip').files[0];
  if (!f) return;
  document.getElementById('fname').textContent = '📎 ' + f.name + ' · ' + (f.size / 1024 / 1024).toFixed(2) + ' MB';
  document.getElementById('btn-deploy').disabled = false;

  // Verify it's master, not customer
  if (!f.name.toLowerCase().includes('master')) {
    alert('⚠️ Pozor — vybral jsi ' + f.name + ', což pravděpodobně NENÍ MASTER ZIP.\n\nMASTER ZIP má v názvu "MASTER", např. appek-MASTER-v2.0.37.zip.\n\nPokud nahraješ customer ZIP, vendor portál bude pravděpodobně poškozen.');
  }
}

function confirmDeploy() {
  return confirm('🚀 Spustit self-update?\n\nAktualizuje se vendor portál + landing pages.\nProces trvá ~5–30 sekund.\n\nPokračovat?');
}

// Drag & drop
const dz = document.getElementById('dz');
dz.addEventListener('dragover', e => { e.preventDefault(); dz.classList.add('dragover'); });
dz.addEventListener('dragleave', () => dz.classList.remove('dragover'));
dz.addEventListener('drop', e => {
  e.preventDefault();
  dz.classList.remove('dragover');
  if (e.dataTransfer.files.length) {
    document.getElementById('master_zip').files = e.dataTransfer.files;
    onFileSelect();
  }
});
</script>

<?php vendor_render_footer(); ?>
</body>
</html>
