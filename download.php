<?php
/**
 * 🆕 v2.9.325 — CUSTOMER DOWNLOAD ENDPOINT (license-gated)
 *
 * E-mail po zaplacení obsahuje link:
 *   https://appek.cz/download.php?key=APPEK-XXXX-XXXX-XXXX-XXXX-XXXX
 *
 * Co dělá:
 *   1. Validuje license key proti vendor_licenses
 *   2. Najde nejnovější published verzi v vendor_updates (status='published')
 *   3. Redirect na api/updates_download.php?version=X.Y.Z&key=... (reuse existující logic)
 *
 * Fallback: pokud vendor_updates prázdné (žádná publish), servíruje LATEST_MASTER_ZIP
 * z /var/www/appek.cz/master-builds/ (vendor mirror příprava).
 *
 * Audit log: každý download zapíše do vendor_audit_log (kdo, kdy, IP).
 *
 * 🔴 BLOCKED FIX v2.9.325 — předtím:
 *   E-mail vedl na appek.cz/download.php → 404 (endpoint neexistoval).
 *   Customer zaplatil přes Stripe → license OK → klikl link → 404 → contacted support.
 *   Bez tohoto endpointu nelze prodávat.
 */

// Nejprve sanity validace klíče (bez DB connect)
$key = strtoupper(trim($_GET['key'] ?? $_SERVER['HTTP_X_APPEK_LICENSE'] ?? ''));

if (empty($key)) {
    http_response_code(400);
    header('Content-Type: text/html; charset=UTF-8');
    echo '<!DOCTYPE html><html lang="cs"><head><meta charset="UTF-8"><title>Chybí licenční klíč</title></head><body style="font-family:system-ui;max-width:600px;margin:60px auto;padding:20px;text-align:center">
        <h1>❌ Chybí licenční klíč</h1>
        <p>Tento odkaz vyžaduje parametr <code>?key=APPEK-XXX-XXX-XXX-XXX-XXX</code>.</p>
        <p>Použij odkaz z potvrzovacího e-mailu po platbě, nebo kontaktuj <a href="mailto:info@appek.cz">info@appek.cz</a>.</p>
    </body></html>';
    exit;
}

// 🐛 v3.0.72 fix — Regex přijímá v1 (5 segments, core only) i v2 (6 segments, s balíčky)
// Předtím povolen jen 6-segment → customer s pure core licencí dostal 400 z e-mail linku.
// Aligned with license_format_valid() v api/_license.php.
if (!preg_match('/^APPEK-[A-Z2-9]{4}-[A-Z2-9]{4}-[A-Z2-9]{4}(-[A-Z2-9]{4})?-[A-Z0-9]{4}$/', $key)) {
    http_response_code(400);
    header('Content-Type: text/html; charset=UTF-8');
    echo '<!DOCTYPE html><html lang="cs"><head><meta charset="UTF-8"><title>Neplatný formát klíče</title></head><body style="font-family:system-ui;max-width:600px;margin:60px auto;padding:20px;text-align:center">
        <h1>❌ Neplatný formát klíče</h1>
        <p>Klíč má formát <code>APPEK-XXXX-XXXX-XXXX-XXXX</code> (v1) nebo <code>APPEK-XXXX-XXXX-XXXX-XXXX-XXXX</code> (v2 s balíčky).</p>
        <p>Zkontroluj jestli jsi zkopíroval celý klíč z e-mailu, nebo kontaktuj <a href="mailto:info@appek.cz">info@appek.cz</a>.</p>
    </body></html>';
    exit;
}

// Pokus se připojit k vendor DB pro license validation + lookup latest version
$vendorConfigCandidates = [
    __DIR__ . '/vendor/config.local.php',
    __DIR__ . '/api/vendor_db_config.local.php',
];

$cfgLoaded = false;
foreach ($vendorConfigCandidates as $cfg) {
    if (file_exists($cfg)) {
        require_once $cfg;
        $cfgLoaded = true;
        break;
    }
}

if (!$cfgLoaded || !defined('VENDOR_DB_HOST')) {
    // Vendor DB nedostupný — fallback na local file lookup MASTER ZIP
    serveFallbackMasterZip($key);
    exit;
}

try {
    $dsn = sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
        VENDOR_DB_HOST,
        defined('VENDOR_DB_PORT') ? VENDOR_DB_PORT : 3306,
        VENDOR_DB_NAME);
    $pdo = new PDO($dsn, VENDOR_DB_USER, VENDOR_DB_PASS, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
} catch (Throwable $e) {
    // DB nedostupné → fallback
    error_log('[download.php] vendor DB unreachable: ' . $e->getMessage());
    serveFallbackMasterZip($key);
    exit;
}

// 1) Validace klíče
$stmt = $pdo->prepare("SELECT id, status, expires_at, revoked_at FROM vendor_licenses WHERE license_key = :k LIMIT 1");
$stmt->execute(['k' => $key]);
$license = $stmt->fetch();

if (!$license) {
    showError(404, 'Licenční klíč nenalezen', 'Klíč není v naší databázi. Zkontroluj že jsi ho zkopíroval celý z potvrzovacího e-mailu.');
    exit;
}
if (!empty($license['revoked_at'])) {
    showError(403, 'Licence byla zrušena', 'Tato licence byla zrušena. Kontaktuj <a href="mailto:info@appek.cz">info@appek.cz</a>.');
    exit;
}
if (!empty($license['expires_at']) && strtotime($license['expires_at']) < time()) {
    showError(403, 'Licence vypršela', 'Licence vypršela ' . htmlspecialchars($license['expires_at']) . '. Pro prodloužení kontaktuj <a href="mailto:info@appek.cz">info@appek.cz</a>.');
    exit;
}

// 2) Najdi nejnovější published verzi
$st2 = $pdo->prepare("
    SELECT version, file_path
    FROM vendor_updates
    WHERE status = 'published'
    ORDER BY released_at DESC, id DESC
    LIMIT 1
");
$st2->execute();
$latest = $st2->fetch();

if (!$latest) {
    // Žádná verze v vendor_updates → fallback na local MASTER ZIP
    serveFallbackMasterZip($key, $pdo, (int) $license['id']);
    exit;
}

// 3) Audit log
try {
    $pdo->prepare("
        INSERT INTO vendor_audit_log (akce, target_type, target_id, target_label, ip, user_agent, created_at)
        VALUES ('download', 'license', :lid, :lbl, :ip, :ua, NOW())
    ")->execute([
        'lid' => $license['id'],
        'lbl' => 'v' . $latest['version'],
        'ip'  => $_SERVER['REMOTE_ADDR'] ?? '',
        'ua'  => substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 255),
    ]);
} catch (Throwable $e) { /* audit tabulka může chybět, neblokuj */ }

// 4) Redirect na existující updates_download.php (reuse stream + per-install logging logic)
$redirect = '/api/updates_download.php?version=' . urlencode($latest['version']) . '&key=' . urlencode($key);
header('Location: ' . $redirect, true, 302);
exit;

// ───────────────────────────────────────────────────────────────────
// HELPERS
// ───────────────────────────────────────────────────────────────────

function serveFallbackMasterZip(string $key, ?PDO $pdo = null, ?int $licenseId = null): void {
    // Najdi nejnovější MASTER ZIP v rootu (vendor mirror)
    $zips = glob(__DIR__ . '/appek-MASTER-v*.zip');
    if (!$zips) {
        showError(503, 'Bundle nedostupný', 'Aktuální verze není dostupná. Kontaktuj <a href="mailto:info@appek.cz">info@appek.cz</a>.');
        return;
    }
    // Sort semver
    usort($zips, function ($a, $b) {
        preg_match('/v(\d+)\.(\d+)\.(\d+)/', $a, $ma);
        preg_match('/v(\d+)\.(\d+)\.(\d+)/', $b, $mb);
        for ($i = 1; $i <= 3; $i++) {
            $diff = (int)($mb[$i] ?? 0) - (int)($ma[$i] ?? 0);
            if ($diff !== 0) return $diff;
        }
        return 0;
    });
    $latest = $zips[0];
    $filename = basename($latest);

    // Audit (best effort)
    if ($pdo && $licenseId) {
        try {
            $pdo->prepare("
                INSERT INTO vendor_audit_log (akce, target_type, target_id, target_label, ip, user_agent, created_at)
                VALUES ('download_fallback', 'license', :lid, :lbl, :ip, :ua, NOW())
            ")->execute([
                'lid' => $licenseId,
                'lbl' => $filename,
                'ip'  => $_SERVER['REMOTE_ADDR'] ?? '',
                'ua'  => substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 255),
            ]);
        } catch (Throwable $e) {}
    }

    // Stream
    header('Content-Type: application/zip');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Content-Length: ' . filesize($latest));
    header('Cache-Control: no-cache, no-store, must-revalidate');
    header('X-Robots-Tag: noindex, nofollow');
    readfile($latest);
    exit;
}

function showError(int $code, string $title, string $msg): void {
    http_response_code($code);
    header('Content-Type: text/html; charset=UTF-8');
    echo '<!DOCTYPE html><html lang="cs"><head><meta charset="UTF-8"><title>' . htmlspecialchars($title) . '</title></head><body style="font-family:system-ui;max-width:600px;margin:60px auto;padding:20px;text-align:center">
        <h1>❌ ' . htmlspecialchars($title) . '</h1>
        <p>' . $msg . '</p>
        <p><small>Kontaktuj <a href="mailto:info@appek.cz">info@appek.cz</a> · +420 733 700 808</small></p>
    </body></html>';
}
