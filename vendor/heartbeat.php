<?php
/**
 * 📡 VENDOR HEARTBEAT — public endpoint pro customer instalace.
 *
 * URL: https://vendor.appek.cz/heartbeat.php (POST JSON)
 *
 * Customer admin posílá denně (cron-style trigger z admin loginu) tento payload:
 *   {
 *     "license_key": "APPEK-XXXX-...",  // nebo null
 *     "install_url": "https://customer.com",
 *     "install_host": "customer.com",
 *     "app_version": "2.1.0",
 *     "php_version": "8.2.10",
 *     "b2b_count": 42,
 *     "orders_count": 1834,
 *     "days_since_install": 120,
 *     "admin_email": "admin@customer.com" // pro outreach pokud pirate
 *   }
 *
 * Server logika:
 *   - klíč chybí / neplatný format → pirate (no_key / invalid_format)
 *   - klíč není ve vendor_licenses → pirate (unknown_key)
 *   - klíč je revoked / expired ALE customer ho používá → pirate (revoked_used / expired_used)
 *   - klíč existuje, ALE host nesedí s předchozími heartbeaty → pirate (key_reuse)
 *   - jinak → legit: update vendor_licenses.last_seen_* a vlož do vendor_license_heartbeats
 *
 * Response:
 *   { "status": "ok" | "pirate", "license_status": "active|expired|revoked|unknown",
 *     "message": "..." }
 *
 * NEPOTŘEBUJE LOGIN — public API endpoint (rate-limited via Cloudflare/IP).
 */

require_once __DIR__ . '/_lib.php';

header('Content-Type: application/json; charset=UTF-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit; }
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'POST only']);
    exit;
}

$raw  = file_get_contents('php://input');
$data = json_decode($raw, true);
if (!is_array($data)) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid JSON']);
    exit;
}

$key       = strtoupper(trim($data['license_key'] ?? ''));
$installUrl  = trim($data['install_url']  ?? '');
$installHost = strtolower(trim($data['install_host'] ?? parse_url($installUrl, PHP_URL_HOST) ?? ''));
$appVersion  = trim($data['app_version']  ?? '');
$phpVersion  = trim($data['php_version']  ?? '');
$b2bCount    = isset($data['b2b_count']) ? (int) $data['b2b_count'] : null;
$ordersCount = isset($data['orders_count']) ? (int) $data['orders_count'] : null;
$daysSince   = isset($data['days_since_install']) ? (int) $data['days_since_install'] : null;
$adminEmail  = trim($data['admin_email'] ?? '');
$ip          = $_SERVER['REMOTE_ADDR'] ?? null;
// 🆕 v2.6.1 — Install fingerprint (HMAC z install_url + DB_NAME + install_timestamp)
$fingerprint = trim($data['install_fingerprint'] ?? '');

// Normalizace install_host
$installHost = preg_replace('/^www\./', '', $installHost);
$installHost = preg_replace('/[^a-z0-9\.\-:]/', '', $installHost);
if ($installHost === '') {
    http_response_code(400);
    echo json_encode(['error' => 'Missing install_host']);
    exit;
}

try {
    $pdo = vendor_db();

    // ─── Klasifikace ─────────────────────────────────────────────
    $reason = null;       // pokud pirate → důvod
    $licenseRow = null;
    $licenseStatus = 'unknown';

    if ($key === '') {
        $reason = 'no_key';
    } elseif (!license_format_valid($key)) {
        $reason = 'invalid_format';
    } elseif (!license_valid($key)) {
        $reason = 'invalid_format';
    } else {
        // Najdi v DB
        $stmt = $pdo->prepare("SELECT * FROM vendor_licenses WHERE license_key = :k LIMIT 1");
        $stmt->execute(['k' => $key]);
        $licenseRow = $stmt->fetch();

        if (!$licenseRow && $key === 'APPEK-BED9-RG9D-MRV8-AAA9-8FBC') {
            // 🚑 v3.0.416 SELF-SEED — oficiální DEMO klíč (build-zip.sh ho zapisuje do
            //   demo/api/config.local.php při každém master deployi). Musí ve vendor_licenses
            //   existovat VŽDY; po opravě heartbeat transportu (v411) první průchozí heartbeat
            //   odhalil, že řádek chybí → demo.appek.cz se zamklo na unknown_key.
            //   Idempotentní: založí se jen když chybí; revoked/expired stav se NEpřepisuje.
            $pdo->prepare("INSERT INTO vendor_licenses (license_key, customer_name, customer_email, install_url, note, status, expires_at)
                           VALUES (:k, 'APPEK Demo', 'demo@appek.cz', 'https://demo.appek.cz', 'Interní demo instance — seed z heartbeat.php (v3.0.416)', 'active', NULL)")
                ->execute(['k' => $key]);
            $stmt = $pdo->prepare("SELECT * FROM vendor_licenses WHERE license_key = :k LIMIT 1");
            $stmt->execute(['k' => $key]);
            $licenseRow = $stmt->fetch();
        }

        if (!$licenseRow) {
            $reason = 'unknown_key';
        } else {
            $licenseStatus = $licenseRow['status'] ?: 'unknown';
            if ($licenseRow['status'] === 'revoked') {
                $reason = 'revoked_used';
            } else {
                // 🆕 v3.0.302 — VYPRŠENÍ (status='expired') NENÍ pirátství: nepadá do pirate větve.
                //   Heartbeat vrátí status='ok' + license_status='expired' + expires_at → appka udělá
                //   grace (14 dní, balíčky ještě jedou) a pak vypne JEN balíčky (core/POS jede dál).
                //   Fingerprint binding (anti-piracy) běží i tak — vypršelá licence na cizí doméně = pirát.
                // 🆕 v2.6.1 — FINGERPRINT BINDING (anti-piracy enforcement)
                //
                // First-use bind: pokud license ještě nemá fingerprint, zapíšeme tento.
                // Druhé použití klíče = mismatch → flag pirate (key_reuse).
                $storedFingerprint = $licenseRow['install_fingerprint'] ?? '';
                $mismatchCount     = (int) ($licenseRow['mismatch_count'] ?? 0);

                if (!$storedFingerprint && $fingerprint) {
                    // FIRST USE — bind fingerprint na tento install
                    $pdo->prepare("
                        UPDATE vendor_licenses
                        SET install_fingerprint = :fp,
                            fingerprint_first_seen = NOW(),
                            install_url = :url
                        WHERE id = :id
                    ")->execute([
                        'fp'  => $fingerprint,
                        'url' => $installUrl,
                        'id'  => $licenseRow['id'],
                    ]);
                } elseif ($storedFingerprint && $fingerprint && $storedFingerprint !== $fingerprint) {
                    // FINGERPRINT MISMATCH — někdo používá klíč z jiné instalace
                    $mismatchCount++;
                    $pdo->prepare("UPDATE vendor_licenses SET mismatch_count = :mc WHERE id = :id")
                        ->execute(['mc' => $mismatchCount, 'id' => $licenseRow['id']]);

                    // Po 3 mismatch v řadě → LOCK
                    if ($mismatchCount >= 3) {
                        $pdo->prepare("
                            UPDATE vendor_licenses
                            SET lock_state = 'locked',
                                lock_reason = 'Fingerprint mismatch — klíč použit z jiné instalace',
                                lock_until = DATE_ADD(NOW(), INTERVAL 90 DAY)
                            WHERE id = :id
                        ")->execute(['id' => $licenseRow['id']]);
                    }
                    $reason = 'key_reuse';
                } elseif ($storedFingerprint && $fingerprint && $storedFingerprint === $fingerprint) {
                    // OK match — reset mismatch counter (legitimate use)
                    if ($mismatchCount > 0) {
                        $pdo->prepare("UPDATE vendor_licenses SET mismatch_count = 0 WHERE id = :id")
                            ->execute(['id' => $licenseRow['id']]);
                    }
                }

                // Legacy host check (pro back-compat pokud chybí fingerprint)
                $registeredHost = '';
                if (!empty($licenseRow['install_url'])) {
                    $registeredHost = strtolower(parse_url($licenseRow['install_url'], PHP_URL_HOST) ?: '');
                    $registeredHost = preg_replace('/^www\./', '', $registeredHost);
                }
                $lastSeenHost = strtolower((string)($licenseRow['last_seen_host'] ?? ''));

                if (!$reason && $registeredHost && $installHost !== $registeredHost
                    && $lastSeenHost && $installHost !== $lastSeenHost) {
                    $reason = 'key_reuse';
                }

                // 🆕 v2.6.1 — Pokud je license LOCKED (z předchozího mismatch), vrať lock command
                if (in_array($licenseRow['lock_state'] ?? 'active', ['locked', 'revoked'], true)) {
                    $reason = 'revoked_used';  // klient zobrazí "Klíč zablokován"
                }
            }
        }
    }

    // ─── Pokud pirate → log + response ───────────────────────────
    if ($reason !== null) {
        $pdo->prepare("
            INSERT INTO vendor_pirate_installs
              (install_url, install_host, license_key_attempted, reason, matched_license_id,
               app_version, php_version, admin_emails, customer_b2b_count, ip_first, ip_last)
            VALUES
              (:url, :host, :key, :reason, :lid, :av, :pv, :em, :b2b, :ip, :ip2)
            ON DUPLICATE KEY UPDATE
              last_seen = NOW(),
              heartbeat_count = heartbeat_count + 1,
              app_version = :av2,
              php_version = :pv2,
              ip_last = :ip3,
              customer_b2b_count = :b2b2,
              admin_emails = CASE
                WHEN admin_emails IS NULL OR admin_emails = '' THEN :em2
                ELSE admin_emails
              END
        ")->execute([
            'url'   => $installUrl,
            'host'  => $installHost,
            'key'   => $key !== '' ? $key : null,
            'reason'=> $reason,
            'lid'   => $licenseRow['id'] ?? null,
            'av'    => $appVersion ?: null,
            'pv'    => $phpVersion ?: null,
            'em'    => $adminEmail ? json_encode([$adminEmail], JSON_UNESCAPED_UNICODE) : null,
            'b2b'   => $b2bCount,
            'ip'    => $ip,
            'ip2'   => $ip,
            'av2'   => $appVersion ?: null,
            'pv2'   => $phpVersion ?: null,
            'ip3'   => $ip,
            'b2b2'  => $b2bCount,
            'em2'   => $adminEmail ? json_encode([$adminEmail], JSON_UNESCAPED_UNICODE) : null,
        ]);

        echo json_encode([
            'status'         => 'pirate',
            'license_status' => $licenseStatus,
            'reason'         => $reason,
            'message'        => match ($reason) {
                'no_key'         => 'Instalace bez licence — kontaktujte dodavatele.',
                'invalid_format' => 'Neplatný formát licenčního klíče.',
                'unknown_key'    => 'Licenční klíč není v naší databázi.',
                'key_reuse'      => 'Tento klíč je registrovaný pro jinou doménu.',
                'revoked_used'   => 'Tento klíč byl odvolán.',
                'expired_used'   => 'Tato licence vypršela — obnovte ji.',
                default          => 'Pirate install.',
            },
            // 🆕 v3.0.387 P1-C — pošli expires_at i v pirate větvi (když licence existuje), ať si klient
            //   nepřepíše datum na null fallbackem (locked = i legit migrace serveru, viz P1-A/P1-B unlock).
            'expires_at'     => (isset($licenseRow['expires_at']) ? $licenseRow['expires_at'] : null),
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // ─── Legit heartbeat → update vendor_licenses + insert do _heartbeats ──
    $pdo->prepare("
        UPDATE vendor_licenses SET
          last_seen_at = NOW(),
          last_seen_host = :host,
          last_seen_version = :ver,
          heartbeat_count = heartbeat_count + 1
        WHERE id = :id
    ")->execute([
        'host' => $installHost,
        'ver'  => $appVersion ?: null,
        'id'   => $licenseRow['id'],
    ]);

    $pdo->prepare("
        INSERT INTO vendor_license_heartbeats
          (license_id, license_key, install_url, install_host, app_version, php_version,
           customer_b2b_count, customer_orders_count, days_since_install, ip)
        VALUES
          (:lid, :lk, :url, :host, :av, :pv, :b2b, :orders, :days, :ip)
    ")->execute([
        'lid'    => $licenseRow['id'],
        'lk'     => $key,
        'url'    => $installUrl,
        'host'   => $installHost,
        'av'     => $appVersion ?: null,
        'pv'     => $phpVersion ?: null,
        'b2b'    => $b2bCount,
        'orders' => $ordersCount,
        'days'   => $daysSince,
        'ip'     => $ip,
    ]);

    // 🆕 Fáze 1A — ulož admin e-maily → instalace (pro vendor/resolve.php = centrální login mobilní appky)
    $emails = $data['admin_emails'] ?? [];
    if (!is_array($emails)) $emails = [];
    if ($adminEmail !== '' && !in_array($adminEmail, $emails, true)) $emails[] = $adminEmail; // back-compat
    $nazevInst = preg_replace('/^https?:\/\//', '', (string) $installUrl);
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS vendor_install_emails (
            email VARCHAR(190) NOT NULL, install_url VARCHAR(255) NOT NULL, license_id INT NULL,
            nazev VARCHAR(190) NULL, updated_at DATETIME NOT NULL,
            PRIMARY KEY (email, install_url), INDEX idx_email (email)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $insEm = $pdo->prepare("INSERT INTO vendor_install_emails (email, install_url, license_id, nazev, updated_at)
            VALUES (:e, :u, :lid, :n, NOW())
            ON DUPLICATE KEY UPDATE license_id = VALUES(license_id), nazev = VALUES(nazev), updated_at = NOW()");
        foreach ($emails as $em) {
            $em = strtolower(trim((string) $em));
            if ($em === '' || !filter_var($em, FILTER_VALIDATE_EMAIL)) continue;
            $insEm->execute(['e' => $em, 'u' => $installUrl, 'lid' => $licenseRow['id'], 'n' => $nazevInst]);
        }
    } catch (Throwable $e) { /* heartbeat nesmí spadnout kvůli tomuhle */ }

    // Cleanup: heartbeats starší 30 dnů smaž (rolling window)
    try {
        $pdo->exec("DELETE FROM vendor_license_heartbeats WHERE seen_at < DATE_SUB(NOW(), INTERVAL 30 DAY)");
    } catch (Throwable $e) { /* ignore */ }

    echo json_encode([
        'status'         => 'ok',
        'license_status' => $licenseStatus,
        'message'        => 'Heartbeat zaznamenán.',
        'expires_at'     => $licenseRow['expires_at'] ?? null,
    ], JSON_UNESCAPED_UNICODE);

} catch (Throwable $e) {
    http_response_code(500);
    error_log('vendor heartbeat: ' . $e->getMessage());
    echo json_encode(['error' => 'Server error']);
}
