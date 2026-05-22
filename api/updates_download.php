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

    $stmt = $pdo->prepare("SELECT * FROM vendor_updates WHERE version = :v AND status = 'published' LIMIT 1");
    $stmt->execute(['v' => $version]);
    $update = $stmt->fetch();

    if (!$update) {
        http_response_code(404);
        header('Content-Type: application/json');
        echo json_encode(['error' => 'version_not_found_or_not_published']);
        exit;
    }

    // Najdi license id (nejlepší match)
    $licenseId = null;
    try {
        $stmt2 = $pdo->prepare("SELECT id FROM vendor_licenses WHERE license_key = :k LIMIT 1");
        $stmt2->execute(['k' => $licenseKey]);
        $licenseId = $stmt2->fetchColumn() ?: null;
    } catch (Throwable $e) {}

    $storageDir = realpath(__DIR__ . '/..') . '/vendor/updates_storage';
    $filePath = $storageDir . '/' . $update['file_path'];

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
