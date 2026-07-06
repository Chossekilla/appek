<?php
/**
 * 📡 LICENSE HEARTBEAT (customer-side) — denně se hlásí vendoru s aktuálním stavem instalace.
 *
 * URL: /api/license_heartbeat.php (POST, admin-authenticated)
 *
 * Spouští se 1× denně automaticky z admin loginu (admin.js detekuje:
 * pokud localStorage.appek_heartbeat_date != dnes → fetch tento endpoint).
 *
 * Payload odesílaný na vendor.appek.cz/heartbeat.php obsahuje JEN technické statistiky —
 * NIKDY zákaznická data (jména/emaily zákazníků, produkty, ceny, objednávky).
 *
 * Response je důležitá: customer admin podle toho ví, jestli instalace je legit nebo
 * pirate. Pirate flag se uloží do api/.pirate-flag a admin UI zobrazí varovný banner.
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/_authz.php';
require_once __DIR__ . '/_license.php';
require_once __DIR__ . '/_license_enforce.php';

// 🐛 v3.0.411 — session_start() otevíral DEFAULT (PHPSESSID) session, ale admin login
//   žije v APPEKSID (session_secure_start) → ctx vždy prázdný → 403 „admin only" na
//   KAŽDÉM loadu adminu = denní klientský heartbeat nikdy neproběhl.
session_secure_start();
header('Content-Type: application/json; charset=UTF-8');

// Admin-only — nesmí volat anonym
$ctx = aktualni_uzivatel_z_session();
if (!$ctx || ($ctx['role'] ?? '') !== 'admin') {
    http_response_code(403);
    echo json_encode(['error' => 'admin only']);
    exit;
}

// Vendor URL (kam posíláme heartbeat) — konfigurabilní pro test, default production
$vendorUrl = defined('APPEK_VENDOR_HEARTBEAT_URL')
    ? APPEK_VENDOR_HEARTBEAT_URL
    : 'https://vendor.appek.cz/heartbeat.php';

try {
    $pdo = db(); // 🐛 v3.0.411 — db_connect() NEEXISTUJE (Call to undefined) → 500;
                 //   bug byl skrytý za session-403 (endpoint se za auth nikdy nedostal).

    // ─── Sbírej technické statistiky (ne zákaznická data) ─────────
    $licenseKey = defined('APP_LICENSE_KEY') ? APP_LICENSE_KEY : '';
    $appVersion = defined('APP_VERSION')      ? APP_VERSION : '';
    $phpVersion = PHP_VERSION;

    $host = $_SERVER['HTTP_HOST'] ?? gethostname();
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $installUrl = $scheme . '://' . $host;

    $b2bCount    = null;
    $ordersCount = null;
    $daysSince   = null;

    try { $b2bCount    = (int) $pdo->query("SELECT COUNT(*) FROM odberatele WHERE login_email IS NOT NULL AND login_email <> ''")->fetchColumn(); } catch (Throwable $e) {}
    try { $ordersCount = (int) $pdo->query("SELECT COUNT(*) FROM objednavky")->fetchColumn(); } catch (Throwable $e) {}
    try {
        $firstOrder = $pdo->query("SELECT MIN(DATE(vytvoreno)) FROM objednavky")->fetchColumn();
        if ($firstOrder) {
            $daysSince = (int) ((time() - strtotime($firstOrder)) / 86400);
        }
    } catch (Throwable $e) {}

    $payload = [
        'license_key'        => $licenseKey,
        'install_url'        => $installUrl,
        'install_host'       => preg_replace('/^www\./', '', strtolower($host)),
        'app_version'        => $appVersion,
        'php_version'        => $phpVersion,
        'b2b_count'          => $b2bCount,
        'orders_count'       => $ordersCount,
        'days_since_install' => $daysSince,
        'admin_email'        => $ctx['email'] ?? '',
        'admin_emails'       => (function () {
            // 🆕 Fáze 1A — všechny admin e-maily (pro centrální login mobilní appky / vendor resolve)
            try {
                $rows = db()->query("SELECT email FROM admin_users WHERE email IS NOT NULL AND email != ''")->fetchAll(PDO::FETCH_COLUMN);
                return array_values(array_unique(array_map('strtolower', array_map('trim', $rows))));
            } catch (Throwable $e) { return []; }
        })(),
        // 🆕 v2.6.1 — Anti-piracy install fingerprint (HMAC unikátní per install)
        'install_fingerprint' => license_install_fingerprint(),
    ];

    // ─── HTTP POST → vendor ───────────────────────────────────────
    $ch = curl_init($vendorUrl);
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => json_encode($payload, JSON_UNESCAPED_UNICODE),
        CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 10,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_USERAGENT      => 'APPEK-Heartbeat/' . $appVersion,
    ]);
    $resp = curl_exec($ch);
    $http = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err  = curl_error($ch);
    curl_close($ch);

    if ($resp === false || $http >= 400) {
        // Nepodařilo se připojit — neblokuje admin, jen log
        echo json_encode([
            'status'  => 'unreachable',
            'message' => 'Vendor server nedostupný — heartbeat odložen.',
            'http'    => $http,
            'error'   => $err,
        ]);
        exit;
    }

    $vendorReply = json_decode($resp, true);
    if (!is_array($vendorReply)) {
        echo json_encode(['status' => 'unreachable', 'message' => 'Neplatná odpověď vendora.']);
        exit;
    }

    // ─── Pokud pirate → ulož flag pro admin UI ─────────────────────
    $pirateFlagFile = __DIR__ . '/.pirate-flag';
    if (($vendorReply['status'] ?? '') === 'pirate') {
        $flagData = [
            'reason'   => $vendorReply['reason'] ?? 'unknown',
            'message'  => $vendorReply['message'] ?? '',
            'flagged_at' => date('c'),
            'license_status' => $vendorReply['license_status'] ?? 'unknown',
        ];
        @file_put_contents($pirateFlagFile, json_encode($flagData, JSON_PRETTY_PRINT));
    } else {
        // Vyčisti pirate flag pokud existoval (instalace se srovnala)
        if (file_exists($pirateFlagFile)) {
            @unlink($pirateFlagFile);
        }
    }

    // 🆕 v2.6.1 — Ulož license_state cache (anti-tamper HMAC)
    //    Mapování reason → state:
    //      no_key / unknown_key / invalid_format → state="locked" (rychlý lock)
    //      key_reuse / revoked_used / expired_used → state="locked"
    //      OK → state="active"
    $newState = 'active';
    $newReason = null;
    if (($vendorReply['status'] ?? '') === 'pirate') {
        $reason = $vendorReply['reason'] ?? '';
        if ($reason === 'expired_used') {
            // 🆕 v3.0.301 — VYPRŠENÍ ≠ pirátství: nezamyká celý admin, jen balíčky (přes valid_until).
            $newState = 'active';
        } elseif ($reason === 'revoked_used') {
            $newState = 'revoked';
        } elseif (in_array($reason, ['key_reuse', 'unknown_key'], true)) {
            $newState = 'locked';
        } else {
            $newState = 'locked';
        }
        $newReason = $vendorReply['message'] ?? $reason;
    }
    // 🆕 v3.0.301 — ulož valid_until (= expires_at od vendora) pro roční expiraci.
    //   Když vendor pole nepošle, zachovej předchozí (neztratit datum mezi heartbeaty).
    $prevState = license_state_load();
    license_state_save([
        'state'       => $newState,
        'reason'      => $newReason,
        'lock_until'  => $vendorReply['lock_until'] ?? null,
        'valid_until' => $vendorReply['expires_at'] ?? ($prevState['valid_until'] ?? null),
        'last_check'  => time(),
    ]);

    // Update timestamp posledního heartbeatu (pro client-side detekci)
    @file_put_contents(__DIR__ . '/.heartbeat-last', date('c'));

    echo json_encode([
        'status'      => $vendorReply['status'] ?? 'ok',
        'license_status' => $vendorReply['license_status'] ?? null,
        'reason'      => $vendorReply['reason'] ?? null,
        'message'     => $vendorReply['message'] ?? '',
        'expires_at'  => $vendorReply['expires_at'] ?? null,
        'pirate_flag' => file_exists($pirateFlagFile),
    ], JSON_UNESCAPED_UNICODE);

} catch (Throwable $e) {
    http_response_code(500);
    error_log('license_heartbeat: ' . $e->getMessage());
    echo json_encode(['error' => 'Server error', 'message' => $e->getMessage()]);
}
