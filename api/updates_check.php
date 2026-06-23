<?php
/**
 * 🔄 UPDATES CHECK — public endpoint pro zákaznické instalace.
 *
 * POST /api/updates_check.php
 *   Body JSON: {
 *     license_key: "APPEK-...",
 *     current_version: "2.0.10",
 *     channel: "stable",          // optional, default stable
 *     packages: ["core","cukrarna"]
 *   }
 *
 * Vrací:
 *   { ok: true, update_available: true/false, latest: { version, channel, download_url, ... } }
 *
 * License validace: HMAC SHA-256 přes shared LICENSE_SALT (offline).
 */

header('Content-Type: application/json; charset=UTF-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') exit;

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'method_not_allowed']);
    exit;
}

require_once __DIR__ . '/_license.php';

// Načti vendor DB config
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
    echo json_encode(['ok' => false, 'error' => 'vendor_db_not_configured']);
    exit;
}

$raw = file_get_contents('php://input');
$d = json_decode($raw, true);
if (!is_array($d)) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'invalid_json']);
    exit;
}

$licenseKey = strtoupper(trim($d['license_key'] ?? ''));
$current    = trim($d['current_version'] ?? '0.0.0');
$channel    = $d['channel'] ?? 'stable';

if (!license_valid($licenseKey)) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'invalid_license', 'hint' => 'Klíč nemá platný checksum nebo formát.']);
    exit;
}

// 🔧 Derivuj balíčky z VALIDOVANÉ licence (ne z requestu) — klient může lhát
$packages = license_packages($licenseKey);

if (!in_array($channel, ['stable', 'beta', 'alpha'])) $channel = 'stable';

try {
    $dsn = sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
        VENDOR_DB_HOST, defined('VENDOR_DB_PORT') ? VENDOR_DB_PORT : 3306, VENDOR_DB_NAME);
    $pdo = new PDO($dsn, VENDOR_DB_USER, VENDOR_DB_PASS, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);

    // 🔐 v3.0.388 — signature sloupec nemusí existovat na starší vendor DB (panel migrace ho přidá)
    $sigCol = '';
    try {
        if ($pdo->query("SHOW COLUMNS FROM vendor_updates LIKE 'signature'")->fetch()) {
            $sigCol = 'signature, ';
        }
    } catch (Throwable $e) { /* ignore */ }

    // Najdi nejnovější publikovaný update v daném channel
    // Pokud channel=stable, fallback je jen stable. Pro beta zahrnujeme i stable.
    $channelFilter = $channel === 'stable'
        ? "channel = 'stable'"
        : "(channel = 'stable' OR channel = '$channel')";

    $stmt = $pdo->query("
        SELECT id, version, channel, file_size, checksum_sha256, min_version,
               {$sigCol}packages_required, changelog_md, published_at
        FROM vendor_updates
        WHERE status='published' AND $channelFilter
    ");
    $allUpdates = $stmt->fetchAll();

    // 🔧 Seřaď podle SEMVER (od nejvyšší k nejnižší), ne podle id
    // — uživatel může omylem nahrát "2.0.6" místo "2.0.60", ID DESC by ho dalo na vrchol
    usort($allUpdates, fn($a, $b) => version_compare($b['version'], $a['version']));

    $latest = null;
    foreach ($allUpdates as $u) {
        // Validace min_version
        if ($u['min_version'] && version_compare($current, $u['min_version'], '<')) {
            continue;
        }
        // Validace packages_required
        if ($u['packages_required']) {
            $req = json_decode($u['packages_required'], true);
            if (is_array($req)) {
                $missing = array_diff($req, $packages);
                if (!empty($missing)) continue;
            }
        }
        // Toto je nejvyšší semver verze která prošla filtrem
        $latest = $u;
        break;
    }

    // Debug info — vrátí počet publikovaných verzí, abychom viděli jestli vendor_updates něco obsahuje
    $debugInfo = [
        'total_published' => count($allUpdates),
        'versions_found'  => array_map(fn($u) => $u['version'], array_slice($allUpdates, 0, 10)),
        'derived_packages'=> $packages,
        'current'         => $current,
    ];

    if (!$latest) {
        echo json_encode([
            'ok' => true,
            'update_available' => false,
            'current_version' => $current,
            'message' => count($allUpdates) === 0
                ? 'Vendor databáze nemá žádnou publikovanou verzi.'
                : 'Žádná verze v vendor_updates neprošla filtrem (min_version / packages_required).',
            'debug' => $debugInfo,
        ]);
        exit;
    }

    if (version_compare($current, $latest['version'], '>=')) {
        echo json_encode([
            'ok' => true,
            'update_available' => false,
            'current_version' => $current,
            'latest_version' => $latest['version'],
            'message' => 'You are on the latest version.',
            'debug' => $debugInfo,
        ]);
        exit;
    }

    // Update je k dispozici!
    $baseUrl = 'https://' . ($_SERVER['HTTP_HOST'] ?? 'appek.cz');
    $downloadUrl = $baseUrl . '/api/updates_download.php?version=' . urlencode($latest['version']);

    echo json_encode([
        'ok' => true,
        'update_available' => true,
        'current_version' => $current,
        'latest' => [
            'version'      => $latest['version'],
            'channel'      => $latest['channel'],
            'size_bytes'   => (int) $latest['file_size'],
            'checksum'     => $latest['checksum_sha256'],
            'signature'    => $latest['signature'] ?? '',
            'min_version'  => $latest['min_version'],
            'changelog'    => $latest['changelog_md'],
            'published_at' => $latest['published_at'],
            'download_url' => $downloadUrl,
        ],
    ], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    error_log('updates_check: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'server_error']);
}
