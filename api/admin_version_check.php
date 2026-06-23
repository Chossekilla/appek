<?php
/**
 * 🔑📥 LICENCE & UPDATE CHECKER — vrátí info o aktivní licenci + dostupné verzi.
 *
 * GET /api/admin_version_check.php             — vrátí cached info (TTL 1h)
 * GET /api/admin_version_check.php?refresh=1   — vynutí refresh z vendor serveru
 *
 * 🆕 v2.0.67 — Volá DYNAMIC endpoint vendor.appek.cz/api/updates_check.php
 * (s license_key + current_version v POST body) místo starého static manifest.json.
 * Tím se Settings panel propojí na vendor_updates DB — vidí všechny publikované verze.
 *
 * Cachuje v nastaveni.update_check_cache (JSON + timestamp).
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/_admin_auth.php';
require_once __DIR__ . '/_license.php';

cors_headers();
require_admin();
header('Content-Type: application/json; charset=UTF-8');

$pdo = db();
$refresh  = !empty($_GET['refresh']);
$cacheTtl = 3600; // 1 hodina

// ── 1. LICENSE STATUS (offline, vždy fresh) ──────────────────────
$lic = license_status();
$licOut = [
    'ok'     => $lic['ok'],
    'reason' => $lic['reason'],
    'masked' => license_masked($lic['key']),
    // 🆕 v2.0.71 — Raw key pro authenticated admin (potřebné pro self-update apply)
    // Bezpečné: require_admin() už proběhl; admin vidí svou vlastní licenci.
    'key'    => $lic['key'] ?? '',
];

// ── 2. VERSION CHECK (s cache) ───────────────────────────────────
$current   = defined('APP_VERSION') ? APP_VERSION : '0.0.0';
$licenseKey = $lic['key'] ?? '';

// 🆕 v2.0.67 — DYNAMIC endpoint kandidáti (fallback chain)
// Customer call → vendor's updates_check.php → vendor_updates DB
$endpointCandidates = [
    'https://appek.cz/api/updates_check.php',
    'https://vendor.appek.cz/api/updates_check.php',
];

$verOut = [
    'current'          => $current,
    'latest'           => null,
    'update_available' => false,
    'download_url'     => null,
    'signature'        => null,
    'changelog'        => null,
    'released_at'      => null,
    'checked_at'       => null,
    'cached'           => true,
    'source'           => $endpointCandidates[0],
];

// Načti cache
try {
    $cached = $pdo->prepare("SELECT hodnota FROM nastaveni WHERE klic = 'update_check_cache'");
    $cached->execute();
    $cachedRaw = $cached->fetchColumn();
    $cachedData = $cachedRaw ? json_decode($cachedRaw, true) : null;
} catch (Throwable $e) { $cachedData = null; }

$now = time();
$useCached = $cachedData
    && !$refresh
    && isset($cachedData['checked_at'])
    && ($now - (int)$cachedData['checked_at']) < $cacheTtl;

if ($useCached) {
    $verOut = array_merge($verOut, $cachedData);
    $verOut['cached'] = true;
    // 🆕 v2.0.96 FIX: 'current' MUSÍ být vždy LIVE z config.php (po update se mění).
    // Předtím cached data přebily fresh current → po update zůstávala stará verze
    // i když config.php byl přepsán. Toto byl důvod „Aktuální verze 2.0.93" po
    // úspěšném updatu na 2.0.95.
    $verOut['current'] = $current;
    // Update_available přepočítat s aktuální verzí (jinak by ukazovalo update i když byl proveden)
    if (!empty($verOut['latest'])) {
        $verOut['update_available'] = version_compare($current, $verOut['latest'], '<');
    }
} else {
    // POST body — vendor's updates_check.php očekává license_key + current_version
    $postBody = json_encode([
        'license_key'     => $licenseKey,
        'current_version' => $current,
        'channel'         => 'stable',
        'packages'        => ['core', 'cukrarna', 'lahudky', 'restaurace', 'catering', 'sezona'],
    ]);

    $lastErr = null;
    $data = null;
    foreach ($endpointCandidates as $endpoint) {
        $ch = curl_init($endpoint);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $postBody,
            CURLOPT_TIMEOUT        => 10,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_HTTPHEADER     => [
                'Accept: application/json',
                'Content-Type: application/json',
                'User-Agent: Appek-B2B/' . $current,
            ],
            CURLOPT_SSL_VERIFYPEER => true,
        ]);
        $body = curl_exec($ch);
        $http = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err  = curl_error($ch);
        curl_close($ch);

        if ($body !== false && $http < 400) {
            $parsed = json_decode($body, true);
            if (is_array($parsed) && !empty($parsed['ok'])) {
                $data = $parsed;
                $verOut['source'] = $endpoint;
                break;
            }
            $lastErr = "Endpoint $endpoint vrátil neplatnou odpověď (ok=false)";
        } else {
            $lastErr = $err ?: "Endpoint $endpoint vrátil HTTP $http";
        }
    }

    if (!$data) {
        $verOut['error'] = $lastErr ?: 'Žádný update endpoint nereagoval';
        if ($cachedData) {
            $verOut = array_merge($verOut, $cachedData);
            $verOut['cached'] = true;
            $verOut['stale']  = true;
            // Stejný fix jako výše — current musí být LIVE
            $verOut['current'] = $current;
            if (!empty($verOut['latest'])) {
                $verOut['update_available'] = version_compare($current, $verOut['latest'], '<');
            }
        }
    } else {
        // updates_check.php response format:
        //   { ok:true, update_available:bool, current_version, latest_version,
        //     latest: { version, changelog, size_bytes, checksum, channel, published_at, download_url } }
        $verOut['update_available'] = !empty($data['update_available']);
        $verOut['latest']           = $data['latest']['version'] ?? $data['latest_version'] ?? null;
        $verOut['download_url']     = $data['latest']['download_url'] ?? null;
        $verOut['signature']        = $data['latest']['signature'] ?? null;  // 🔐 v3.0.388
        $verOut['changelog']        = $data['latest']['changelog'] ?? '';
        $verOut['released_at']      = $data['latest']['published_at'] ?? null;
        // 🐛 v3.0.2 — zapomenutý unpack checksum z vendor response.
        // Předtím: admin UI runSelfUpdate dostalo prázdný checksum → updates_apply.php
        // odmítlo s "checksum_required" → customer nemohl klik self-update.
        $verOut['checksum_sha256']  = $data['latest']['checksum'] ?? $data['latest']['checksum_sha256'] ?? null;
        $verOut['file_size']        = $data['latest']['size_bytes'] ?? $data['latest']['file_size'] ?? null;
        $verOut['checked_at']       = $now;
        $verOut['cached']           = false;

        // Persist cache (jen úspěšný refresh)
        try {
            $payload = json_encode($verOut, JSON_UNESCAPED_UNICODE);
            $pdo->prepare("
                INSERT INTO nastaveni (klic, hodnota) VALUES ('update_check_cache', :v)
                ON DUPLICATE KEY UPDATE hodnota = :v2
            ")->execute(['v' => $payload, 'v2' => $payload]);
        } catch (Throwable $e) { error_log('update cache save: ' . $e->getMessage()); }
    }
}

echo json_encode([
    'license' => $licOut,
    'version' => $verOut,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
