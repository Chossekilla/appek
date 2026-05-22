<?php
/**
 * 📦 PUBLIC UPDATE DOWNLOAD — appek.cz/updates/download.php?version=X.Y.Z
 *
 * Anonymní download endpoint pro update ZIPy. Žádná license validace — ta
 * proběhne až při APLIKACI updatu (install.php / updates_apply.php kontrolují
 * APP_LICENSE_KEY před změnou souborů).
 *
 * Proč anonymní:
 *   - Customer admin ze settings panelu (admin_version_check.php) zobrazí
 *     download_url jako klikací odkaz. Customer nemusí ručně paste license.
 *   - Customer ZIP/bundle sám o sobě neumožňuje instalaci bez license —
 *     install.php / updates_apply.php to vyžadují.
 *   - Stahování je rate-limited (Hostinger CDN + .htaccess pravidla).
 *
 * Pokud potřebuješ license-gated download (např. pre-release builds),
 * použij /api/updates_download.php (s ?key= validation).
 */

@set_time_limit(300);

$version = trim($_GET['version'] ?? '');

if (!$version) {
    http_response_code(400);
    header('Content-Type: application/json');
    echo json_encode(['error' => 'missing_version', 'message' => 'Použij ?version=X.Y.Z']);
    exit;
}

if (!preg_match('/^[0-9]+\.[0-9]+\.[0-9]+(-[a-z0-9]+)?$/', $version)) {
    http_response_code(400);
    header('Content-Type: application/json');
    echo json_encode(['error' => 'invalid_version']);
    exit;
}

// Vendor DB
$vendorConfigs = [
    __DIR__ . '/../api/vendor_db_config.local.php',
    __DIR__ . '/../vendor/config.local.php',
];
$loaded = false;
foreach ($vendorConfigs as $cfg) {
    if (file_exists($cfg)) { require_once $cfg; $loaded = true; break; }
}

if (!$loaded || !defined('VENDOR_DB_HOST')) {
    http_response_code(503);
    header('Content-Type: application/json');
    echo json_encode(['error' => 'vendor_db_not_configured']);
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

    $stmt = $pdo->prepare("SELECT * FROM vendor_updates WHERE version = :v AND status = 'published' LIMIT 1");
    $stmt->execute(['v' => $version]);
    $update = $stmt->fetch();

    if (!$update) {
        http_response_code(404);
        header('Content-Type: application/json');
        echo json_encode(['error' => 'version_not_found']);
        exit;
    }

    // Najdi soubor
    $storageDir = realpath(__DIR__ . '/..') . '/vendor/updates_storage';
    $filePath = $storageDir . '/' . $update['file_path'];

    if (!file_exists($filePath)) {
        http_response_code(404);
        header('Content-Type: application/json');
        echo json_encode([
            'error' => 'file_missing',
            'message' => 'Update soubor nenalezen na disku: ' . basename($filePath),
            'detail' => 'Vendor admin musí re-uploadnout bundle.',
        ]);
        exit;
    }

    // Log anonymous download (bez license — jen statistika)
    try {
        $pdo->prepare("UPDATE vendor_updates SET download_count = download_count + 1 WHERE id = :id")
            ->execute(['id' => $update['id']]);
    } catch (Throwable $e) { /* ignore */ }

    // Stream file
    header('Content-Type: application/zip');
    header('Content-Disposition: attachment; filename="' . basename($update['file_path']) . '"');
    header('Content-Length: ' . filesize($filePath));
    header('X-APPEK-Version: ' . $update['version']);
    header('X-APPEK-Checksum: sha256=' . $update['checksum_sha256']);
    header('Cache-Control: public, max-age=3600');
    readfile($filePath);
    exit;

} catch (Throwable $e) {
    error_log('updates/download.php: ' . $e->getMessage());
    http_response_code(500);
    header('Content-Type: application/json');
    echo json_encode(['error' => 'server_error', 'message' => $e->getMessage()]);
}
