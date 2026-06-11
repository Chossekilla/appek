<?php
/**
 * SELF-UPDATE knihovna — sdílená apply rutina.
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
    $backupDir = null;
    try {
        // ── obsah původního try-bloku z self-update.php ──
        // ZMĚNY oproti originálu:
        //  1) Vynechána validace uploadu ($_FILES kontroly) — caller ji dělá.
        //  2) Místo $_FILES['master_zip']['tmp_name'] se používá přímo $zipPath.
        //  3) Na konci místo $flash_ok = ... → return ['ok'=>true, ...]
        //  4) Verze se bere z $customerZipMeta['version'], fallback z api/config.php APP_VERSION.

        if (!class_exists('ZipArchive')) {
            throw new Exception('PHP rozšíření ZipArchive není k dispozici.');
        }

        $uploadedZip = $zipPath;
        logStep('Upload OK · ' . number_format(filesize($uploadedZip) / 1024 / 1024, 2, ',', ' ') . ' MB', $log);

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
        logStep("Pre-flight OK · $totalFiles souborů v balíčku · obsahuje vendor/ + api/ + landing", $log);

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
                logStep('Preserving: ' . $p . ' (' . number_format(strlen($preserveData[$p])) . ' B)', $log);
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
            logStep('Found ' . count($existingStorage) . ' existing customer update bundles (will keep)', $log);
        }

        // ─── 4. BACKUP před extrakcí ─────────────────────────────
        $backupDir = sys_get_temp_dir() . '/appek-self-update-backup-' . date('Ymd-His');
        @mkdir($backupDir, 0755, true);
        // Stručná záloha klíčových dirů (rollback bez ztráty dat)
        $backupTargets = ['vendor', 'api', 'sales', 'admin', 'b2b', 'demo', 'pos', 'floorplan'];
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
        logStep('Backup vytvořen v ' . $backupDir, $log);

        // 🧹 v3.0.238 — RETENCE: starý kód nechával KAŽDOU /tmp/appek-self-update-backup-*
        //   (cp -R 8 dirů) napořád → 59 záloh × ~11k souborů = 641k inodů → přetekla inode
        //   kvóta (na CloudLinux /tmp = ~/.cagefs/tmp se počítá do kvóty účtu → 107 %, web
        //   přestal tvořit soubory). Necháme newest 2 zálohy + smažeme staré extract/staging.
        self_update_prune_tmp($log, 2);

        // ─── 5. EXTRAKCE ZIPu ────────────────────────────────────
        $extractDir = sys_get_temp_dir() . '/appek-self-update-extract-' . date('Ymd-His');
        @mkdir($extractDir, 0755, true);
        if (!$zip->extractTo($extractDir)) {
            $zip->close();
            throw new Exception('Extrakce selhala. Zkontroluj práva /tmp.');
        }
        $zip->close();
        logStep('ZIP extrahován do ' . $extractDir, $log);

        // ─── 6. POST-EXTRACT VALIDACE ─────────────────────────────
        foreach ($requiredFiles as $r) {
            if (!file_exists($extractDir . '/' . $r)) {
                throw new Exception("Po extrakci chybí soubor: $r — ZIP je porušený.");
            }
        }
        logStep('Post-extract validace OK', $log);

        // ─── 6.5. PŘEKLOPIT embedded customer bundle z MASTER ─────
        $embeddedBundles = [];
        $extractStorage = $extractDir . '/vendor/updates_storage';
        if (is_dir($extractStorage)) {
            foreach (glob($extractStorage . '/appek-update-*.zip') ?: [] as $f) {
                $embeddedBundles[basename($f)] = $f;
            }
            logStep('Found ' . count($embeddedBundles) . ' embedded bundles in MASTER ZIP: ' . implode(', ', array_keys($embeddedBundles)), $log);
        }

        // ─── 7. SYNC do webrootu (kopíruje vše) ──────────────────
        $rsyncBin = trim(@shell_exec('which rsync') ?: '');
        if ($rsyncBin) {
            // -aI místo -a: vynutí přepsání KAŽDÉHO souboru (ignoruje mtime quick-check)
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
            logStep('Rsync sync dokončen', $log);
        } else {
            // Fallback: rekurzivní kopírování přes PHP
            $copied = 0;
            $skipped = 0;
            self_update_copy_dir($extractDir, $webroot, $preserve, $copied, $skipped);
            logStep("Fallback copy: $copied souborů zkopírováno, $skipped přeskočeno (preserve)", $log);
        }

        // ─── 8. RESTORE preserved files ──────────────────────────
        foreach ($preserveData as $p => $data) {
            $full = $webroot . '/' . $p;
            @mkdir(dirname($full), 0755, true);
            file_put_contents($full, $data);
            logStep('Restored: ' . $p, $log);
        }

        // Překlopit embedded customer bundles z MASTER ZIPu do storage
        $storageDir = $webroot . '/vendor/updates_storage';
        @mkdir($storageDir, 0755, true);
        $bundlesCopied = 0;
        foreach ($embeddedBundles as $name => $srcPath) {
            $dstPath = $storageDir . '/' . $name;
            // Vždy přepsat — embedded bundle z MASTER ZIPu je AUTHORITATIVE source
            if (@copy($srcPath, $dstPath)) {
                $bundlesCopied++;
                logStep('✅ Bundle propsán: ' . $name . ' (' . round(filesize($dstPath) / 1024 / 1024, 2) . ' MB)', $log);
            } else {
                logStep('⚠️ Nelze propsat bundle: ' . $name, $log);
            }
        }
        if ($bundlesCopied === 0 && !empty($embeddedBundles)) {
            logStep('❌ KRITICKÉ: ' . count($embeddedBundles) . ' embedded bundles nebylo propsáno do storage. Zkontroluj oprávnění.', $log);
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
                logStep("❌ Post-deploy: $cf chybí/prázdný — spouštím auto-rollback", $log);
                self_update_rollback($backupDir, $webroot, $log);
                return ['ok' => false, 'error' => "Post-deploy selhal ($cf) — proveden auto-rollback", 'version' => null, 'health' => null];
            }
        }
        logStep('Post-deploy validace OK · vendor.appek.cz aktualizován', $log);

        // ─── 10. AUTO-BUILD CUSTOMER ZIP & PUBLISH ──────────────
        $customerZipMeta = self_update_build_customer_zip($webroot, $log);

        // ─── 11. CLEANUP extract dir (backup ponechán pro rollback) ──
        self_update_rmdir($extractDir);
        logStep('Cleanup hotov · backup zůstává v /tmp pro případný rollback', $log);

        // Verze: preferuj z customerZipMeta, fallback z api/config.php
        $version = $customerZipMeta['version'] ?? null;
        if (!$version) {
            $cfgFile = $webroot . '/api/config.php';
            if (file_exists($cfgFile)) {
                $cfg = file_get_contents($cfgFile);
                if (preg_match("/APP_VERSION'[^']*'([0-9]+\.[0-9]+\.[0-9]+(?:-[a-z0-9]+)?)'/", $cfg, $m)) {
                    $version = $m[1];
                }
            }
        }

        $health = self_update_health_check($webroot, $version);
        logStep($health['ok'] ? '✅ Health check OK' : '⚠️ Health check nahlásil problém', $log);
        return ['ok' => true, 'version' => $version, 'health' => $health, 'backupDir' => $backupDir, 'published' => (is_array($customerZipMeta) && !empty($customerZipMeta['published']))];

    } catch (Throwable $e) {
        logStep('❌ CHYBA: ' . $e->getMessage(), $log);
        return ['ok' => false, 'error' => $e->getMessage(), 'version' => null, 'health' => null, 'backupDir' => $backupDir];
    }
}

/**
 * Helper — log do progressLog + případně i do error_log.
 */
function logStep(string $msg, &$log) {
    $log[] = '[' . date('H:i:s') . '] ' . $msg;
    error_log('[self-update] ' . $msg);
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
 * 🧹 v3.0.238 — Úklid /tmp po self-update: nech newest $keepBackups záloh, zbytek smaž;
 * extract/staging/deploy diry starší 1 h smaž celé (jsou ephemeral). Bez tohohle inody
 * lineárně rostly s každým deployem (viz komentář u volání) → přetekla kvóta účtu.
 */
function self_update_prune_tmp(array &$log, int $keepBackups = 2): void {
    $tmp = sys_get_temp_dir();
    // Zálohy: seřaď newest-first podle mtime, smaž vše za prvními $keepBackups.
    $backups = glob($tmp . '/appek-self-update-backup-*', GLOB_ONLYDIR) ?: [];
    if (count($backups) > $keepBackups) {
        usort($backups, fn($a, $b) => (@filemtime($b) ?: 0) <=> (@filemtime($a) ?: 0));
        $removed = 0;
        foreach (array_slice($backups, $keepBackups) as $old) { self_update_rmdir($old); $removed++; }
        logStep("🧹 Prune záloh: smazáno {$removed} starých (nechávám {$keepBackups})", $log);
    }
    // Ephemeral staging/extract/deploy diry: smaž starší 1 h (leaknuté z crashlých běhů).
    $sweptDirs = 0;
    foreach (['appek-self-update-extract-*', 'appek-update-*', 'appek-deploy-*'] as $pat) {
        foreach (glob($tmp . '/' . $pat, GLOB_ONLYDIR) ?: [] as $d) {
            if ((time() - (int) @filemtime($d)) > 3600) { self_update_rmdir($d); $sweptDirs++; }
        }
    }
    if ($sweptDirs) logStep("🧹 Sweep staging: {$sweptDirs} starých temp dirů smazáno", $log);
}

/**
 * AUTO-BUILD CUSTOMER ZIP po self-update.
 *
 * @return array|null  ['version' => 'X.Y.Z', 'size' => bytes, 'file' => path] nebo null při chybě
 */
function self_update_build_customer_zip(string $webroot, array &$log): ?array {
    try {
        $version = null;
        $cfgFile = $webroot . '/api/config.php';
        if (file_exists($cfgFile)) {
            $cfg = file_get_contents($cfgFile);
            if (preg_match("/APP_VERSION'[^']*'([0-9]+\.[0-9]+\.[0-9]+(?:-[a-z0-9]+)?)'/", $cfg, $m)) {
                $version = $m[1];
            }
        }
        // Fallback — odvoď verzi z názvu embedded bundle v updates_storage/
        if (!$version) {
            $storageGlob = glob($webroot . '/vendor/updates_storage/appek-update-*.zip');
            if ($storageGlob) {
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

        // 🆕 v3.0.259 — OVĚŘ že publish row SKUTEČNĚ existuje jako 'published'. Bez toho deploy
        //   "uspěl" tiše i když INSERT spadl (try/catch výše) → vendor kanál se neposune →
        //   demo/zákazníci nedostanou update. Tahle kontrola to nahlásí výš (deploy-hook → CI retry).
        $published = false;
        try {
            $vpdo = $pdo ?? vendor_db();
            $vc = $vpdo->prepare("SELECT COUNT(*) FROM vendor_updates WHERE version = :v AND status = 'published'");
            $vc->execute(['v' => $version]);
            $published = ((int) $vc->fetchColumn()) > 0;
        } catch (Throwable $e) { $published = false; }
        logStep($published
            ? "✅ Publish OVĚŘEN · vendor_updates má published v{$version}"
            : "❌ Publish NEOVĚŘEN · vendor_updates NEMÁ published v{$version} → deploy nahlásí chybu (CI retry)", $log);

        // Zapiš verzi do vendor/.appek-version
        try {
            @file_put_contents($webroot . '/vendor/.appek-version', $version);
            logStep("vendor/.appek-version → {$version}", $log);
        } catch (Throwable $e) {}

        return ['version' => $version, 'size' => $size, 'file' => basename($zipFile), 'published' => $published];

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
 * Přidá soubory do bundle ZIP pod files/ prefix + sbírá SHA-256 do mapy.
 * Bundle struktura: manifest.json (root) + files/<relPath> (subtree).
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
 * INTEGRITY VERIFICATION před zveřejněním bundle.
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

/**
 * Ověří, že nasazená verze sedí a kritické soubory jsou na místě.
 * @return array {ok:bool, checks: array<string,{ok:bool,value?:string}>}
 */
function self_update_health_check(string $webroot, string $expectedVersion): array {
    $checks = [];

    $cfg = @file_get_contents($webroot . '/api/config.php') ?: '';
    preg_match("/APP_VERSION'[^']*'([0-9]+\.[0-9]+\.[0-9]+(?:-[a-z0-9]+)?)'/", $cfg, $m);
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
