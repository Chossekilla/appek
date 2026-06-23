<?php
/**
 * 🔄 UPDATES DOWNLOAD — streamování update bundle (license-gated).
 *
 * GET /api/updates_download.php?version=X.Y.Z&key=APPEK-...
 *
 * Validuje licenci, najde bundle, streamuje ZIP.
 * Logu install do vendor_update_installs (success / fail).
 */

require_once __DIR__ . '/_license.php';

$vendorConfigs = [
    __DIR__ . '/vendor_db_config.local.php',
    realpath(__DIR__ . '/..') . '/vendor/config.local.php',
];
$loaded = false;
foreach ($vendorConfigs as $cfg) {
    if ($cfg && file_exists($cfg)) { require_once $cfg; $loaded = true; break; }
}
if (!$loaded || !defined('VENDOR_DB_HOST')) {
    http_response_code(503);
    header('Content-Type: application/json');
    echo json_encode(['error' => 'vendor_db_not_configured']);
    exit;
}

$version = trim($_GET['version'] ?? '');
$licenseKey = strtoupper(trim($_GET['key'] ?? $_SERVER['HTTP_X_APPEK_LICENSE'] ?? ''));

if (!$version || !preg_match('/^[0-9]+\.[0-9]+\.[0-9]+(-[a-z0-9]+)?$/', $version)) {
    http_response_code(400);
    header('Content-Type: application/json');
    echo json_encode(['error' => 'invalid_version']);
    exit;
}

if (!license_valid($licenseKey)) {
    http_response_code(403);
    header('Content-Type: application/json');
    echo json_encode(['error' => 'invalid_license']);
    exit;
}

try {
    $dsn = sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
        VENDOR_DB_HOST, defined('VENDOR_DB_PORT') ? VENDOR_DB_PORT : 3306, VENDOR_DB_NAME);
    $pdo = new PDO($dsn, VENDOR_DB_USER, VENDOR_DB_PASS, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);

    // 🔒 v3.0.387 P3-E — rate-limit per IP proti brute-force download klíče (best-effort, nezablokuje legit)
    $ipRaw = (string) ($_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0');
    $dlIp = substr(trim(explode(',', $ipRaw)[0]), 0, 64);
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS vendor_download_hits (id INT AUTO_INCREMENT PRIMARY KEY, ip VARCHAR(64) NOT NULL, ts DATETIME NOT NULL, INDEX idx_ip_ts (ip, ts)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $pdo->prepare("INSERT INTO vendor_download_hits (ip, ts) VALUES (:ip, NOW())")->execute(['ip' => $dlIp]);
        $pdo->exec("DELETE FROM vendor_download_hits WHERE ts < DATE_SUB(NOW(), INTERVAL 1 HOUR)");
        $c = $pdo->prepare("SELECT COUNT(*) FROM vendor_download_hits WHERE ip = :ip AND ts > DATE_SUB(NOW(), INTERVAL 10 MINUTE)");
        $c->execute(['ip' => $dlIp]);
        if ((int) $c->fetchColumn() > 30) {   // > 30 / 10 min / IP
            http_response_code(429);
            header('Content-Type: application/json');
            echo json_encode(['error' => 'rate_limited']);
            exit;
        }
    } catch (Throwable $e) { /* best-effort */ }

    // 🔒 v3.0.387 P3-E — ověř klíč proti vendor_licenses, ne JEN offline HMAC.
    //   Offline HMAC (krátký checksum) je guessovatelný → klíč co projde HMAC, ale NENÍ vydaný,
    //   NESMÍ stáhnout placený bundle. Blokuj neexistující (brute-force) + revoked. Expired NECHÁN
    //   (smí si stáhnout/přeinstalovat core — placené balíčky stejně gatuje runtime přes valid_until).
    $licStmt = $pdo->prepare("SELECT id, status FROM vendor_licenses WHERE license_key = :k LIMIT 1");
    $licStmt->execute(['k' => $licenseKey]);
    $lic = $licStmt->fetch();
    if (!$lic || ($lic['status'] ?? '') === 'revoked') {
        http_response_code(403);
        header('Content-Type: application/json');
        echo json_encode(['error' => 'license_revoked_or_unknown']);
        exit;
    }
    $licenseId = $lic['id'];

    $stmt = $pdo->prepare("SELECT * FROM vendor_updates WHERE version = :v AND status = 'published' LIMIT 1");
    $stmt->execute(['v' => $version]);
    $update = $stmt->fetch();

    if (!$update) {
        http_response_code(404);
        header('Content-Type: application/json');
        echo json_encode(['error' => 'version_not_found_or_not_published']);
        exit;
    }

    $storageDir = realpath(__DIR__ . '/..') . '/vendor/updates_storage';
    $filePath = $storageDir . '/' . basename((string) $update['file_path']); // 🔒 v3.0.353 — basename proti path traversal

    if (!file_exists($filePath)) {
        // Log failure
        $pdo->prepare("
            INSERT INTO vendor_update_installs
              (update_id, license_id, license_key, customer_url, ip, user_agent, success, error_msg)
            VALUES (:uid, :lid, :lk, :url, :ip, :ua, 0, 'file_missing')
        ")->execute([
            'uid' => $update['id'], 'lid' => $licenseId, 'lk' => $licenseKey,
            'url' => substr($_SERVER['HTTP_REFERER'] ?? '', 0, 500),
            'ip'  => $_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? null,
            'ua'  => substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 500),
        ]);
        http_response_code(500);
        header('Content-Type: application/json');
        echo json_encode(['error' => 'bundle_file_missing']);
        exit;
    }

    // Log success + increment counter
    $pdo->prepare("
        INSERT INTO vendor_update_installs
          (update_id, license_id, license_key, customer_url, ip, user_agent, success)
        VALUES (:uid, :lid, :lk, :url, :ip, :ua, 1)
    ")->execute([
        'uid' => $update['id'], 'lid' => $licenseId, 'lk' => $licenseKey,
        'url' => substr($_SERVER['HTTP_REFERER'] ?? '', 0, 500),
        'ip'  => $_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? null,
        'ua'  => substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 500),
    ]);
    $pdo->prepare("UPDATE vendor_updates SET download_count = download_count + 1 WHERE id = :id")
        ->execute(['id' => $update['id']]);

    // Stream file
    header('Content-Type: application/zip');
    header('Content-Disposition: attachment; filename="' . basename($update['file_path']) . '"');
    header('Content-Length: ' . filesize($filePath));
    header('X-APPEK-Version: ' . $update['version']);
    header('X-APPEK-Checksum: sha256=' . $update['checksum_sha256']);
    readfile($filePath);
    exit;
} catch (Throwable $e) {
    error_log('updates_download: ' . $e->getMessage());
    http_response_code(500);
    header('Content-Type: application/json');
    echo json_encode(['error' => 'server_error']);
}
