<?php
/**
 * 🔄 UPDATES APPLY — stáhne update bundle z vendoru, ověří, rozbalí, aplikuje.
 *
 * POST /api/updates_apply.php
 *   Body JSON: {
 *     license_key: "APPEK-...",
 *     version: "2.1.0",
 *     download_url: "https://appek.cz/api/updates_download.php?...",
 *     expected_checksum: "abc123...sha256"
 *   }
 *
 * Workflow:
 *   1. Vytvoří temp dir
 *   2. Stáhne bundle (čistě po HTTPS, předá license key v query)
 *   3. Ověří SHA-256
 *   4. Extract do staging/
 *   5. Validace manifestu — každý soubor sedí na hash
 *   6. Atomic apply — copy files/ do install rootu
 *   7. Zaloguje, vrátí JSON
 *
 * Pozn.: vyžaduje pro plnou bezpečnost ADMIN auth check.
 *        Pro MVP je license-only, ale ideálně by mělo být i s admin sessionem.
 */

header('Content-Type: application/json; charset=UTF-8');
header('Access-Control-Allow-Origin: *');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'method_not_allowed']);
    exit;
}

require_once __DIR__ . '/_license.php';

$d = json_decode(file_get_contents('php://input'), true);
$licenseKey = strtoupper(trim($d['license_key'] ?? license_get_current() ?? ''));
$version    = trim($d['version'] ?? '');
$url        = trim($d['download_url'] ?? '');
$expected   = trim($d['expected_checksum'] ?? '');

if (!license_valid($licenseKey)) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'invalid_license']);
    exit;
}
if (!$version || !preg_match('/^[0-9]+\.[0-9]+\.[0-9]+(-[a-z0-9]+)?$/', $version)) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'invalid_version']);
    exit;
}
if (!$url || !preg_match('#^https://#', $url)) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'invalid_url']);
    exit;
}

$root  = realpath(__DIR__ . '/..');
$tmpDir = sys_get_temp_dir() . '/appek-update-' . $version . '-' . bin2hex(random_bytes(4));
@mkdir($tmpDir, 0755, true);
$bundlePath = $tmpDir . '/bundle.zip';
$stagingDir = $tmpDir . '/staging';

$result = ['ok' => false, 'steps' => []];

try {
    // ─── 1. Stáhni bundle ────────────────────────────────────────
    $result['steps'][] = '⬇️ Stahování bundle…';
    $separator = strpos($url, '?') !== false ? '&' : '?';
    $fullUrl = $url . $separator . 'key=' . urlencode($licenseKey);
    $ctx = stream_context_create(['http' => ['timeout' => 120, 'follow_location' => 1]]);
    $bundle = @file_get_contents($fullUrl, false, $ctx);
    if ($bundle === false || strlen($bundle) < 100) {
        throw new Exception('Stažení selhalo nebo prázdný response.');
    }
    file_put_contents($bundlePath, $bundle);
    $size = filesize($bundlePath);
    $result['steps'][] = "✅ Staženo $size bajtů";

    // ─── 2. Ověř checksum ───────────────────────────────────────
    if ($expected) {
        $actual = hash_file('sha256', $bundlePath);
        if (!hash_equals(strtolower($expected), strtolower($actual))) {
            throw new Exception("Checksum nesedí. Očekáváno: $expected, dostal: $actual");
        }
        $result['steps'][] = '✅ Checksum SHA-256 ověřen';
    } else {
        $result['steps'][] = '⚠️ Checksum nebyl předán — přeskočeno (doporučuji předávat!)';
    }

    // ─── 3. Extract ─────────────────────────────────────────────
    if (!class_exists('ZipArchive')) {
        throw new Exception('PHP ZipArchive není dostupný na tomto hostingu.');
    }
    @mkdir($stagingDir, 0755, true);
    $zip = new ZipArchive();
    if ($zip->open($bundlePath) !== true) throw new Exception('Bundle není validní ZIP.');
    if ($zip->extractTo($stagingDir) === false) {
        $zip->close();
        throw new Exception('Extract selhal — kontrola oprávnění temp dir.');
    }
    $zip->close();
    $result['steps'][] = '✅ Bundle rozbalen do staging';

    // ─── 4. DETEKCE FORMÁTU ────────────────────────────────────
    // 🆕 v2.0.68 — Univerzální podpora obou formátů:
    //   A) BUNDLE FORMAT: manifest.json (s files mapou) + files/ subfolder
    //   B) RAW CUSTOMER ZIP: api/, admin/, b2b/ přímo na root
    //
    // Tím se vyřeší neslučitelnost: customer ZIP z build-zip.sh vs
    // bundle z build-update.sh. Apply funguje pro oba.
    $manifestPath = $stagingDir . '/manifest.json';
    $filesDir     = $stagingDir . '/files';
    $hasBundle    = file_exists($manifestPath) && is_dir($filesDir);
    $manifest     = null;

    if ($hasBundle) {
        // BUNDLE format
        $manifest = json_decode(file_get_contents($manifestPath), true);
        if (!is_array($manifest) || empty($manifest['version'])) {
            throw new Exception('manifest.json je neplatný (chybí version).');
        }
        if ($manifest['version'] !== $version) {
            throw new Exception("Manifest verze ({$manifest['version']}) nesedí s požadovanou ($version).");
        }
        $fileCount = !empty($manifest['files']) ? count($manifest['files']) : 0;
        $result['steps'][] = "✅ Bundle format detekován · {$manifest['version']} · {$fileCount} souborů v manifestu";
    } else {
        // RAW CUSTOMER ZIP — žádný manifest.json + files/ folder
        $result['steps'][] = "✅ Raw customer ZIP detekován (bez manifest.json/files folderu)";
        $result['steps'][] = "ℹ️ Skip SHA-256 validace (bundle manifest chybí) — fallback na unzip-all";
    }

    // ─── 5. SHA-256 VALIDACE (jen pro bundle) ──────────────────
    if ($hasBundle && !empty($manifest['files'])) {
        $errors = [];
        foreach ($manifest['files'] as $relPath => $expectedHash) {
            $absPath = $filesDir . '/' . $relPath;
            if (!file_exists($absPath)) { $errors[] = "Chybí: $relPath"; continue; }
            $actualHash = hash_file('sha256', $absPath);
            if (!hash_equals(strtolower($expectedHash), strtolower($actualHash))) {
                $errors[] = "Hash nesedí: $relPath";
            }
        }
        if ($errors) {
            throw new Exception('Validace souborů selhala: ' . implode(', ', array_slice($errors, 0, 3)));
        }
        $result['steps'][] = '✅ Všechny soubory ověřeny SHA-256';
    }

    // ─── 6. BUILD FILE LIST — jednotný napříč oběma formáty ────
    // Pro bundle: ze manifestu (files mapy)
    // Pro raw ZIP: scandirem staging directory (skip manifest.json + protected)
    $fileList = []; // [relPath => srcPath]

    if ($hasBundle && !empty($manifest['files'])) {
        foreach ($manifest['files'] as $relPath => $_) {
            $fileList[$relPath] = $filesDir . '/' . $relPath;
        }
    } else {
        // Raw ZIP — vezmi všechno z staging KROMĚ manifest.json + protected paths
        $protectedPaths = ['api/config.local.php', 'api/.installed', 'vendor/config.local.php', 'vendor/.installed'];
        $iter = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($stagingDir, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::LEAVES_ONLY
        );
        foreach ($iter as $f) {
            if (!$f->isFile()) continue;
            $abs = $f->getPathname();
            $rel = ltrim(str_replace('\\', '/', substr($abs, strlen($stagingDir))), '/');
            if ($rel === 'manifest.json') continue;
            if (in_array($rel, $protectedPaths, true)) continue;
            $fileList[$rel] = $abs;
        }
    }

    // 🆕 v2.0.84 — PRE-FLIGHT BUNDLE INTEGRITY CHECK
    // Pokud bundle nemá admin/admin.js nebo admin/index.html, ABORT.
    // Tohle je root cause "config.php updated ale admin.js zůstává starý" bugu —
    // vendor publikoval broken bundle (jen api/, bez admin/+b2b/).
    $criticalMustHave = ['admin/admin.js', 'admin/index.html', 'admin/sw.js', 'api/config.php'];
    $missingCritical = [];
    foreach ($criticalMustHave as $rel) {
        if (!isset($fileList[$rel])) $missingCritical[] = $rel;
    }
    if (!empty($missingCritical)) {
        $errMsg = 'BUNDLE NEKOMPLETNÍ — chybí kritické soubory: ' . implode(', ', $missingCritical) . '. '
                . 'Vendor publikoval broken bundle. '
                . 'Příčina: vendor MASTER ZIP byl bez admin/+b2b/. '
                . 'Řešení: dodavatel musí nahrát fresh MASTER ZIP postavený přes build-zip.sh v2.0.70+. '
                . 'Kontaktuj podpora@appek.cz s tímto error msg.';
        throw new Exception($errMsg);
    }
    $result['steps'][] = "✅ Pre-flight integrity OK · bundle má všechny kritické soubory (admin/admin.js, admin/index.html, admin/sw.js, api/config.php)";
    $result['steps'][] = "📋 File list: " . count($fileList) . " souborů";

    if (empty($fileList)) {
        throw new Exception('ZIP neobsahuje žádné aplikovatelné soubory.');
    }

    // ─── 7. Backup current install ─────────────────────────────
    $backupDir = $root . '/api/zalohy/update-backup-' . date('YmdHis') . '-v' . $version;
    @mkdir($backupDir, 0755, true);
    $backupCount = 0;
    foreach ($fileList as $relPath => $_) {
        $existing = $root . '/' . $relPath;
        if (file_exists($existing)) {
            $bp = $backupDir . '/' . $relPath;
            @mkdir(dirname($bp), 0755, true);
            if (@copy($existing, $bp)) $backupCount++;
        }
    }
    $result['steps'][] = "💾 Záloha vytvořena · $backupCount souborů (api/zalohy/" . basename($backupDir) . ")";

    // ─── 8. Apply — copy files do live + per-file HASH MAP + OPCACHE INVALIDATE ─────
    // 🆕 v2.0.84 — Po každém zapisu .php souboru INVALIDUJ opcache. Bez toho PHP
    // servuje starou verzi config.php z paměti, i když je disk přepsaný.
    // Tohle byl skrytý druhý důvod proč update "neuplikuje" — PHP cache v RAM.
    $applied = 0;
    $failed = [];
    $appliedFiles = []; // [relPath => sha256] pro post-verifikaci
    $opcacheAvailable = function_exists('opcache_invalidate') && (bool) @ini_get('opcache.enable');
    foreach ($fileList as $relPath => $src) {
        $dst = $root . '/' . $relPath;
        @mkdir(dirname($dst), 0755, true);
        if (@copy($src, $dst)) {
            $applied++;
            $appliedFiles[$relPath] = hash_file('sha256', $src);
            // 🔄 OPCACHE INVALIDATE pro PHP soubory
            if ($opcacheAvailable && substr($relPath, -4) === '.php') {
                @opcache_invalidate($dst, true);
            }
            // Clear stat cache aby filemtime/filesize vrátily aktuální data
            @clearstatcache(true, $dst);
        } else {
            $failed[] = $relPath;
            // Pokud selhal copy CRITICAL souboru (admin.js, index.html, config.php), abort+rollback
            if (in_array($relPath, ['admin/admin.js', 'admin/index.html', 'api/config.php', 'api/updates_apply.php'], true)) {
                throw new Exception("CRITICAL fail: nelze zapsat $relPath. Kontrola oprávnění (chmod 755 složky, 644 soubory). Backup je v api/zalohy/" . basename($backupDir));
            }
        }
    }
    // Reset opcache jednou globálně pro jistotu (pokud je dostupné)
    if ($opcacheAvailable) {
        @opcache_reset();
        $result['steps'][] = "🔄 PHP opcache invalidated + globální reset (jinak config.php starý v RAM)";
    } else {
        $result['steps'][] = "ℹ️ PHP opcache není dostupný — skip invalidace";
    }
    $result['steps'][] = "✅ Aplikováno $applied souborů" . ($failed ? " (failed: " . count($failed) . ")" : "");

    // ─── 9. POST-UPDATE VERIFIKACE — re-read každý kritický soubor a porovnej hash ─
    // 🆕 v2.0.83 — Tohle je hlavní fix: silně eliminuje "config.php updated ale admin.js zůstal starý" bug.
    // Re-read disk content + hash → musí matchovat zdroj. Jinak alert.
    $criticalFiles = ['admin/admin.js', 'admin/index.html', 'admin/sw.js', 'api/config.php',
                      'api/updates_apply.php', 'api/admin_version_check.php'];
    $verifyResults = [];
    $verifyFailed = [];
    foreach ($criticalFiles as $rel) {
        if (!isset($appliedFiles[$rel])) {
            // Soubor nebyl v update bundle — pravděpodobně přírůstkový update
            $verifyResults[$rel] = ['status' => 'not_in_bundle'];
            continue;
        }
        $expectedHash = $appliedFiles[$rel];
        $diskPath = $root . '/' . $rel;
        if (!file_exists($diskPath)) {
            $verifyResults[$rel] = ['status' => 'missing'];
            $verifyFailed[] = $rel;
            continue;
        }
        $actualHash = hash_file('sha256', $diskPath);
        if (!hash_equals($expectedHash, $actualHash)) {
            $verifyResults[$rel] = [
                'status' => 'mismatch',
                'expected' => substr($expectedHash, 0, 12),
                'actual' => substr($actualHash, 0, 12),
            ];
            $verifyFailed[] = $rel;
        } else {
            $verifyResults[$rel] = ['status' => 'ok'];
        }
    }
    if (!empty($verifyFailed)) {
        $result['steps'][] = "⚠️ POST-VERIFIKACE: " . count($verifyFailed) . " kritických souborů má wrong hash: " . implode(', ', $verifyFailed);
    } else {
        $result['steps'][] = "✅ POST-VERIFIKACE: všech " . count(array_filter($verifyResults, fn($v) => $v['status'] === 'ok')) . " kritických souborů má správný hash";
    }
    $result['verification'] = $verifyResults;

    // ─── 10. Write .update-manifest.json (pro klient-side stale-code detekci) ─
    $manifestPath = $root . '/api/.update-manifest.json';
    $manifestData = [
        'version' => $version,
        'applied_at' => date('c'),
        'files_applied' => $applied,
        'critical_files' => $verifyResults,
        'asset_hashes' => [
            // Krátké hashe pro klient-side check
            'admin/admin.js' => isset($appliedFiles['admin/admin.js']) ? substr($appliedFiles['admin/admin.js'], 0, 16) : null,
            'admin/admin.css' => isset($appliedFiles['admin/admin.css']) ? substr($appliedFiles['admin/admin.css'], 0, 16) : null,
        ],
    ];
    @file_put_contents($manifestPath, json_encode($manifestData, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    $result['steps'][] = "📄 .update-manifest.json zapsán";

    // ─── 11. Cleanup ─────────────────────────────────────────────
    deleteRecursive($tmpDir);
    $result['steps'][] = '🧹 Temp soubory smazány';

    // ─── 12. Update version stamp ───────────────────────────────
    $versionFile = $root . '/api/.version';
    @file_put_contents($versionFile, $version);

    // 🆕 v2.0.96 — INVALIDATE update_check_cache v DB
    // Jinak admin_version_check.php vrací cached info se starou 'current' verzí,
    // i když config.php už má novou. Po update vždy okamžitě vyčistit cache.
    try {
        require_once $root . '/api/config.php';
        $pdoCache = @db();
        if ($pdoCache) {
            $pdoCache->prepare("DELETE FROM nastaveni WHERE klic = 'update_check_cache'")->execute();
            $result['steps'][] = '🔄 update_check_cache invalidated';
        }
    } catch (Throwable $e) {
        $result['steps'][] = '⚠️ update_check_cache invalidate skipped: ' . $e->getMessage();
    }

    $result['ok'] = empty($verifyFailed);  // jen true pokud post-verifikace prošla
    $result['version'] = $version;
    $result['files_applied'] = $applied;
    $result['files_failed'] = count($failed);
    $result['backup_dir'] = basename($backupDir);
    if (empty($verifyFailed)) {
        $result['message'] = "✅ Aktualizace na verzi $version proběhla úspěšně (post-verifikace OK).";
    } else {
        $result['message'] = "⚠️ Aktualizace na $version proběhla, ale " . count($verifyFailed) . " kritických souborů má wrong hash. Doporučuji /admin/clear-cache.html + reload.";
    }

    echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
} catch (Throwable $e) {
    deleteRecursive($tmpDir);
    $result['error'] = $e->getMessage();
    http_response_code(500);
    echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
}

function deleteRecursive(string $dir): void {
    if (!is_dir($dir)) return;
    $items = scandir($dir);
    foreach ($items as $item) {
        if ($item === '.' || $item === '..') continue;
        $path = $dir . '/' . $item;
        if (is_dir($path)) deleteRecursive($path);
        else @unlink($path);
    }
    @rmdir($dir);
}
