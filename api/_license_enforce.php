<?php
/**
 * 🔒 LICENSE ENFORCE — anti-piracy install fingerprinting (v2.6.1).
 *
 * Generuje deterministický install fingerprint který:
 *   - Je UNIKÁTNÍ per customer install (HMAC z hosting domain + DB name + install timestamp)
 *   - Se NEMĚNÍ mezi requesty (deterministicky vychází z server config)
 *   - Lze ho ověřit u vendora přes heartbeat
 *
 * Pokud customer zkopíruje admin/ + b2b/ + license_key na jiný hosting:
 *   - HOST + DB jméno bude jiné → fingerprint se změní
 *   - Vendor server zjistí mismatch → flag pirate, lock po 3 výskytech
 *
 * Stará data necháváme nedotčená.
 */

require_once __DIR__ . '/config.php';

/**
 * Vrátí deterministický fingerprint této instalace.
 * Komponenty:
 *   - host (HTTP_HOST), bez www. prefix
 *   - DB_NAME
 *   - install timestamp (.installed file mtime)
 *   - APP_LICENSE_KEY (pro bind na konkrétní klíč)
 *
 * Hash: SHA-256 HMAC s LICENSE_SALT, vrací 64 hex chars.
 */
function license_install_fingerprint(): string {
    static $cached = null;
    if ($cached !== null) return $cached;

    require_once __DIR__ . '/_license.php';

    $host = strtolower($_SERVER['HTTP_HOST'] ?? gethostname() ?: 'unknown');
    $host = preg_replace('/^www\./', '', $host);
    $host = preg_replace('/[^a-z0-9\.\-:]/', '', $host);

    $dbName = defined('DB_NAME') ? DB_NAME : 'no_db';

    // Install timestamp — file mtime instalovaného flaglu (vytvořen při install.php)
    $installedFile = __DIR__ . '/.installed';
    $installTs = file_exists($installedFile) ? filemtime($installedFile) : 0;

    $licenseKey = defined('APP_LICENSE_KEY') ? APP_LICENSE_KEY : '';

    $material = $host . '|' . $dbName . '|' . $installTs . '|' . $licenseKey;
    $hash = hash_hmac('sha256', $material, LICENSE_SALT);
    $cached = $hash;
    return $hash;
}

/**
 * License state cache file (binární, hashovaný).
 *
 * Po každém heartbeatu zapíše JSON:
 *   { state: 'active'|'locked'|'grace'|'revoked',
 *     reason: '...',
 *     lock_until: 'YYYY-MM-DD HH:MM:SS' nebo null,
 *     last_check: timestamp,
 *     checksum: HMAC ověření že soubor nebyl ručně upraven }
 *
 * Customer admin NEMŮŽE soubor smazat aby obešel lock — pokud chybí, admin.js
 * forknul heartbeat synchronně a vyžaduje fresh state.
 */
function license_state_path(): string {
    return __DIR__ . '/.license-state.json';
}

function license_state_load(): array {
    $path = license_state_path();
    if (!file_exists($path)) {
        return ['state' => 'active', 'reason' => null, 'lock_until' => null, 'last_check' => 0];
    }
    $raw = @file_get_contents($path);
    $data = json_decode($raw, true);
    if (!is_array($data)) return ['state' => 'active', 'last_check' => 0];

    // Verify HMAC checksum (anti-tamper)
    require_once __DIR__ . '/_license.php';
    $checksum = $data['_checksum'] ?? '';
    unset($data['_checksum']);
    $expected = hash_hmac('sha256', json_encode($data, JSON_UNESCAPED_UNICODE), LICENSE_SALT);
    if (!$checksum || !hash_equals($expected, $checksum)) {
        // Tampered — treat as locked!
        return ['state' => 'locked', 'reason' => 'Tampered license state file', 'last_check' => 0];
    }
    return $data;
}

function license_state_save(array $state): void {
    require_once __DIR__ . '/_license.php';
    $state['last_check'] = time();
    $clean = $state;
    unset($clean['_checksum']);
    $checksum = hash_hmac('sha256', json_encode($clean, JSON_UNESCAPED_UNICODE), LICENSE_SALT);
    $state['_checksum'] = $checksum;
    @file_put_contents(license_state_path(), json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    @chmod(license_state_path(), 0600);
}

/**
 * Server-side gate — pokud license_state = locked, vrátí 423 (Locked) všem admin endpointům.
 * Voláno z _admin_auth.php require_admin().
 *
 * Grace period: pokud lock_until > NOW, ale je v grace (např. 7 dnů), admin uvidí warning
 * ale ještě funguje.
 */
function license_enforce_check(): array {
    $state = license_state_load();
    $now = time();

    if ($state['state'] === 'locked' || $state['state'] === 'revoked') {
        return [
            'ok' => false,
            'state' => $state['state'],
            'reason' => $state['reason'] ?? 'License locked',
            'message' => 'Licenční klíč byl zablokován. Kontaktujte dodavatele systému.',
        ];
    }

    return ['ok' => true, 'state' => $state['state'] ?? 'active'];
}
