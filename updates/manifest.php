<?php
/**
 * 🎯 PUBLIC UPDATE MANIFEST — appek.cz/updates/manifest.json
 *
 * Tenhle PHP soubor je servovaný jako manifest.json (via .htaccess rewrite).
 * Vrací JSON s informacemi o nejnovější verzi — kompatibilní se starým
 * formátem, který očekává customer's `admin_version_check.php` (v jakékoliv
 * verzi, včetně 2.0.63 z 17.5.).
 *
 * Dynamicky čte vendor_updates DB → vždy aktuální. Žádný stale static soubor.
 *
 * Volá to:
 *   - GET /updates/manifest.json           → veřejně, returns latest stable
 *   - GET /updates/manifest.json?channel=beta → beta channel
 *
 * Response format (stabilní, nikdy neměnit aby nerozbilo staré klienty):
 *   {
 *     "latest_version":   "2.0.68",
 *     "download_url":     "https://appek.cz/api/updates_download.php?version=2.0.68&key=LICENSE",
 *     "changelog":        "...",
 *     "released_at":      "2026-05-18",
 *     "checksum_sha256":  "abc123...",
 *     "size_bytes":       1500000,
 *     "min_version":      "1.0.0",
 *     "channel":          "stable"
 *   }
 *
 * Pokud DB nedostupná: vrátí HTTP 503 s informativní hláškou.
 */

header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: public, max-age=300'); // 5 min CDN/browser cache
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, OPTIONS');
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS') exit;

$channel = $_GET['channel'] ?? 'stable';
if (!in_array($channel, ['stable', 'beta'], true)) $channel = 'stable';

// Vendor DB config — zkus oba možné kandidáty
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
    echo json_encode([
        'error' => 'vendor_db_not_configured',
        'message' => 'Vendor database connection is not set up. Contact admin.',
    ]);
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

    // Channel filter: beta zahrnuje i stable (beta channel = stable + beta)
    $channelClause = $channel === 'stable'
        ? "channel = 'stable'"
        : "channel IN ('stable', 'beta')";

    $stmt = $pdo->query("
        SELECT version, channel, file_size, checksum_sha256, min_version,
               packages_required, changelog_md, published_at, file_path
        FROM vendor_updates
        WHERE status = 'published' AND $channelClause
    ");
    $allUpdates = $stmt->fetchAll();

    if (empty($allUpdates)) {
        http_response_code(404);
        echo json_encode([
            'error' => 'no_published_version',
            'message' => 'No published version found in vendor_updates.',
        ]);
        exit;
    }

    // 🔧 SEMVER sort (NE id DESC) — '2.0.6' < '2.0.60' < '2.0.68'
    usort($allUpdates, fn($a, $b) => version_compare($b['version'], $a['version']));
    $latest = $allUpdates[0];

    // Build response — old-format compatible
    $baseUrl = ($_SERVER['HTTPS'] ?? 'off') === 'on' ? 'https://' : 'http://';
    $baseUrl .= $_SERVER['HTTP_HOST'] ?? 'appek.cz';

    // 🆕 v2.0.70 — Anonymous download endpoint (bez license check)
    // License se ověřuje až při APPLY (install.php / updates_apply.php),
    // ne při downloadu. Customer admin tak může jednoduše kliknout "Stáhnout ZIP".
    $downloadUrl = $baseUrl . '/updates/download.php?version=' . urlencode($latest['version']);

    $response = [
        'latest_version'   => $latest['version'],
        'download_url'     => $downloadUrl,
        'changelog'        => $latest['changelog_md'] ?? '',
        'released_at'      => $latest['published_at'] ?? null,
        'checksum_sha256'  => $latest['checksum_sha256'] ?? null,
        'size_bytes'       => (int) ($latest['file_size'] ?? 0),
        'min_version'      => $latest['min_version'] ?? null,
        'channel'          => $latest['channel'] ?? 'stable',
        'file_path'        => $latest['file_path'] ?? null,
        // Dodatečně — vendor URL pro v2 API (modernější klienti)
        'check_endpoint'   => $baseUrl . '/api/updates_check.php',
        'apply_endpoint'   => $baseUrl . '/api/updates_apply.php',
        'manifest_version' => '2.0',
        'generated_at'     => date('c'),
    ];

    echo json_encode($response, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);

} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode([
        'error' => 'server_error',
        'message' => 'Failed to read vendor_updates: ' . $e->getMessage(),
    ]);
    error_log('manifest.php: ' . $e->getMessage());
}
