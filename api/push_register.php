<?php
/**
 * api/push_register.php — registrace NATIVNÍHO push tokenu (iOS APNs / Android FCM) z mobilní appky.
 *
 * Fáze 1B: appka po startu zaregistruje device token sem (+ e-mail pro asociaci s adminem).
 *   POST {token, platform:'ios'|'android', email?} → UPSERT do push_device_tokens.
 *
 * ⚠️ Skutečné DORUČOVÁNÍ push zpráv = Fáze 2 (potřebuje APNs auth key / FCM server key — build-time).
 *    Tenhle endpoint zatím jen SBÍRÁ tokeny, aby byly připravené, až se delivery dodělá.
 */
require_once __DIR__ . '/config.php';
cors_headers();

$pdo = db();
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') json_error('POST only', 405);

$d = json_input();
$token = trim($d['token'] ?? '');
$platform = in_array(($d['platform'] ?? ''), ['ios', 'android'], true) ? $d['platform'] : 'unknown';
$email = strtolower(trim($d['email'] ?? ''));
if ($token === '') json_error('Chybí token');

// Self-create tabulky (vzor APPEK runtime migrací — fresh install bezpečné)
$pdo->exec("CREATE TABLE IF NOT EXISTS push_device_tokens (
    id INT AUTO_INCREMENT PRIMARY KEY,
    token VARCHAR(255) NOT NULL UNIQUE,
    platform VARCHAR(16) NOT NULL DEFAULT 'unknown',
    email VARCHAR(255) NULL,
    admin_id INT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

// Asociace s adminem dle e-mailu (best-effort)
$admin_id = null;
if ($email !== '') {
    try {
        $st = $pdo->prepare("SELECT id FROM admin_users WHERE LOWER(email) = :e LIMIT 1");
        $st->execute(['e' => $email]);
        $admin_id = $st->fetchColumn() ?: null;
    } catch (Throwable $e) { /* tvar admin_users se může lišit — ignoruj */ }
}

$pdo->prepare("
    INSERT INTO push_device_tokens (token, platform, email, admin_id)
    VALUES (:t, :p, :e, :a)
    ON DUPLICATE KEY UPDATE
        platform   = VALUES(platform),
        email      = COALESCE(VALUES(email), email),
        admin_id   = COALESCE(VALUES(admin_id), admin_id),
        updated_at = CURRENT_TIMESTAMP
")->execute(['t' => $token, 'p' => $platform, 'e' => ($email ?: null), 'a' => $admin_id]);

json_response(['ok' => true]);
