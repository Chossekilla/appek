<?php
/**
 * 🔐 ONLINE LICENSE VERIFY — Endpoint pro install.php a updates_check.php.
 *
 * Customer (instalátor / aktualizátor) volá tento endpoint na vendoru,
 * který:
 *   1. Validuje HMAC checksum (offline) — žádné podvržené klíče
 *   2. Looknu v vendor_licenses → existuje? Aktivní? Není revoked / expired?
 *   3. Spočítá kolik instalací s tímto klíčem už existuje (vendor_license_installs)
 *   4. Volitelně: zaznamená/aktualizuje záznam pro tuto instalaci
 *   5. Vrátí package list, expiration, max_installs limit
 *
 * POST /api/license_verify.php
 *   Body: {
 *     license_key:   "APPEK-...",
 *     install_url:   "https://customer.cz",        ← volitelně (pro tracking)
 *     current_version: "2.0.61",                  ← volitelně
 *     register:      true|false,                    ← false = jen check, true = zapsat
 *   }
 *
 * Response:
 *   { ok:true, valid:true, packages:[...], expires_at:..., max_installs, current_installs }
 *   { ok:true, valid:false, reason:"format"|"checksum"|"not_found"|"revoked"|"expired" }
 */

header('Content-Type: application/json; charset=UTF-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') exit;
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405); echo json_encode(['ok'=>false,'error'=>'method_not_allowed']); exit;
}

require_once __DIR__ . '/_license.php';

// Vendor DB
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
    echo json_encode(['ok'=>false,'error'=>'vendor_db_not_configured']);
    exit;
}

$raw = file_get_contents('php://input');
$d = json_decode($raw, true);
if (!is_array($d)) {
    http_response_code(400);
    echo json_encode(['ok'=>false,'error'=>'invalid_json']);
    exit;
}

$licenseKey  = strtoupper(trim($d['license_key'] ?? ''));
$installUrl  = trim($d['install_url'] ?? '');
$current     = trim($d['current_version'] ?? '');
$register    = !empty($d['register']);

// ─── 1. Offline HMAC validace ─────────────────────────────────
if (!license_format_valid($licenseKey)) {
    echo json_encode(['ok'=>true, 'valid'=>false, 'reason'=>'format',
        'message'=>'Klíč nemá správný formát APPEK-XXXX-XXXX-XXXX-XXXX.']);
    exit;
}
if (!license_valid($licenseKey)) {
    echo json_encode(['ok'=>true, 'valid'=>false, 'reason'=>'checksum',
        'message'=>'Checksum klíče nesedí — klíč je pozměněný nebo neexistuje.']);
    exit;
}

// Z klíče vytáhni balíčky (zakódované v bitmasce)
$packages = license_packages($licenseKey);

try {
    $dsn = sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
        VENDOR_DB_HOST,
        defined('VENDOR_DB_PORT') ? VENDOR_DB_PORT : 3306,
        VENDOR_DB_NAME);
    $pdo = new PDO($dsn, VENDOR_DB_USER, VENDOR_DB_PASS, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);

    // ─── 2. Lookup v vendor_licenses ──────────────────────────
    // Pokud tabulka existuje (záleží na verzi vendor schématu)
    $row = null;
    try {
        $stmt = $pdo->prepare("SELECT * FROM vendor_licenses WHERE license_key = :k LIMIT 1");
        $stmt->execute(['k' => $licenseKey]);
        $row = $stmt->fetch();
    } catch (Throwable $e) {
        // Tabulka neexistuje? Vrátíme jen offline-validní info
        echo json_encode([
            'ok' => true, 'valid' => true,
            'reason' => 'offline_only',
            'packages' => $packages,
            'message' => 'Klíč offline-validní; vendor nemá tracking tabulku.',
        ]);
        exit;
    }

    if (!$row) {
        // Klíč má valid checksum, ale není v DB — neregistrovaný "zinit" klíč
        echo json_encode(['ok'=>true, 'valid'=>false, 'reason'=>'not_found',
            'message'=>'Klíč není evidovaný u dodavatele.',
            'packages' => $packages]);
        exit;
    }

    if (!empty($row['revoked_at']) || ($row['status'] ?? '') === 'revoked') {
        echo json_encode(['ok'=>true, 'valid'=>false, 'reason'=>'revoked',
            'revoked_at' => $row['revoked_at'] ?? null,
            'message' => 'Klíč byl revokován (' . htmlspecialchars($row['revoke_reason'] ?? 'bez důvodu') . ').']);
        exit;
    }

    if (!empty($row['expires_at']) && strtotime($row['expires_at']) < time()) {
        echo json_encode(['ok'=>true, 'valid'=>false, 'reason'=>'expired',
            'expires_at' => $row['expires_at'],
            'message' => 'Platnost klíče vypršela ' . $row['expires_at'] . '.']);
        exit;
    }

    // ─── 3. Spočítej registrované instalace ──────────────────
    $installCount = 0;
    $maxInstalls  = isset($row['max_installs']) ? (int) $row['max_installs'] : 0;  // 0 = neomezeno
    $thisInstall  = null;
    try {
        $cs = $pdo->prepare("SELECT COUNT(*) FROM vendor_license_installs WHERE license_id = :id");
        $cs->execute(['id' => $row['id']]);
        $installCount = (int) $cs->fetchColumn();

        if ($installUrl) {
            $thisStmt = $pdo->prepare("SELECT * FROM vendor_license_installs WHERE license_id = :id AND install_url = :u LIMIT 1");
            $thisStmt->execute(['id' => $row['id'], 'u' => $installUrl]);
            $thisInstall = $thisStmt->fetch();
        }
    } catch (Throwable $e) { /* table may not exist — ignore */ }

    // Pokud register=true a tato URL ještě není zaznamenaná → INSERT/UPDATE
    if ($register && $installUrl) {
        try {
            $pdo->exec("
                CREATE TABLE IF NOT EXISTS vendor_license_installs (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    license_id INT NOT NULL,
                    install_url VARCHAR(500) NOT NULL,
                    current_version VARCHAR(40),
                    first_seen_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                    last_seen_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                    ip VARCHAR(45),
                    user_agent VARCHAR(255),
                    UNIQUE KEY ux_lic_url (license_id, install_url),
                    INDEX idx_lic (license_id)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
            ");
            $pdo->prepare("
                INSERT INTO vendor_license_installs (license_id, install_url, current_version, ip, user_agent)
                VALUES (:lid, :url, :ver, :ip, :ua)
                ON DUPLICATE KEY UPDATE
                    current_version = VALUES(current_version),
                    last_seen_at = NOW(),
                    ip = VALUES(ip), user_agent = VALUES(user_agent)
            ")->execute([
                'lid' => $row['id'],
                'url' => $installUrl,
                'ver' => $current ?: null,
                'ip'  => $_SERVER['REMOTE_ADDR'] ?? null,
                'ua'  => substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 255),
            ]);
            // recount
            $cs2 = $pdo->prepare("SELECT COUNT(*) FROM vendor_license_installs WHERE license_id = :id");
            $cs2->execute(['id' => $row['id']]);
            $installCount = (int) $cs2->fetchColumn();
        } catch (Throwable $e) { /* ignore */ }
    }

    // Validace max_installs (pokud existuje sloupec a má hodnotu > 0)
    if ($maxInstalls > 0 && $installCount > $maxInstalls && !$thisInstall) {
        echo json_encode(['ok'=>true, 'valid'=>false, 'reason'=>'too_many_installs',
            'max_installs' => $maxInstalls,
            'current_installs' => $installCount,
            'message' => "Licence je již instalovaná {$installCount}× (limit {$maxInstalls})."]);
        exit;
    }

    echo json_encode([
        'ok'       => true,
        'valid'    => true,
        'packages' => $packages,
        'license_info' => [
            'customer_name' => $row['customer_name'] ?? null,
            'customer_email'=> $row['customer_email'] ?? null,
            'expires_at'    => $row['expires_at']    ?? null,
            'issued_at'     => $row['issued_at']     ?? $row['created_at'] ?? null,
            'max_installs'  => $maxInstalls,
            'current_installs' => $installCount,
        ],
    ], JSON_UNESCAPED_UNICODE);

} catch (Throwable $e) {
    error_log('license_verify: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['ok'=>false, 'error'=>'server_error', 'detail'=>$e->getMessage()]);
}
