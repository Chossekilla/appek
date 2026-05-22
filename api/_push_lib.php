<?php
/**
 * Push notifikace — knihovna pro Web Push protocol (RFC 8030 + VAPID RFC 8292)
 *
 * Žádný composer balíček — vlastní implementace s OpenSSL + cURL pro maximum kompatibilitu.
 * Veřejný VAPID klíč se exponuje ve frontend pro subscription.
 * Privátní klíč signuje JWT tokeny pro každý push request.
 */

/**
 * Auto-migrace tabulek pro push.
 */
function ensure_push_tables(PDO $pdo): void {
    static $done = false;
    if ($done) return;
    $done = true;

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS push_subscriptions (
            id              INT AUTO_INCREMENT PRIMARY KEY,
            odberatel_id    INT NULL,
            admin_id        INT NULL,
            endpoint        TEXT NOT NULL,
            endpoint_hash   VARCHAR(64) NOT NULL,
            p256dh          TEXT NOT NULL,
            auth            TEXT NOT NULL,
            user_agent      VARCHAR(255) DEFAULT NULL,
            posledni_push   DATETIME NULL,
            chyba_count     INT NOT NULL DEFAULT 0,
            posledni_chyba  TEXT NULL,
            vytvoreno       DATETIME DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY ux_push_endpoint (endpoint_hash),
            INDEX idx_push_odb (odberatel_id),
            INDEX idx_push_admin (admin_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS push_log (
            id              INT AUTO_INCREMENT PRIMARY KEY,
            subscription_id INT NULL,
            title           VARCHAR(150) NOT NULL,
            body            VARCHAR(500) NOT NULL,
            typ             VARCHAR(50),
            objednavka_id   INT NULL,
            stav            ENUM('sent','failed','expired') DEFAULT 'sent',
            chyba           TEXT NULL,
            odeslano        DATETIME DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_log_sub (subscription_id),
            INDEX idx_log_typ (typ)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
}

/**
 * Generuje VAPID klíče (jednorázově). Uloží do nastaveni.
 * Vyžaduje OpenSSL s podporou P-256 ECDSA.
 */
function vapid_keys_get_or_create(PDO $pdo): array {
    $pub = nastaveni_get($pdo, 'push_vapid_public', null);
    $priv = nastaveni_get($pdo, 'push_vapid_private', null);
    if ($pub && $priv) return ['public' => $pub, 'private' => $priv];

    // Generuj ECDSA P-256 klíč
    if (!function_exists('openssl_pkey_new')) {
        throw new RuntimeException('OpenSSL extension chybí');
    }
    $key = openssl_pkey_new([
        'curve_name'       => 'prime256v1',
        'private_key_type' => OPENSSL_KEYTYPE_EC,
    ]);
    if (!$key) throw new RuntimeException('Nepodařilo se vytvořit VAPID klíče');
    $details = openssl_pkey_get_details($key);
    // Veřejný klíč: 65 bajtů (uncompressed): 0x04 || X (32) || Y (32)
    $publicKey = "\x04" . $details['ec']['x'] . $details['ec']['y'];
    openssl_pkey_export($key, $privateKeyPem);
    // Z PEM extrahuj raw 32-bajtový private exponent
    $privateKey = $details['ec']['d'];

    $pubB64 = b64_url_encode($publicKey);
    $privB64 = b64_url_encode($privateKey);

    $stmt = $pdo->prepare("INSERT INTO nastaveni (klic, hodnota) VALUES (:k, :v) ON DUPLICATE KEY UPDATE hodnota = :v2");
    $stmt->execute(['k' => 'push_vapid_public',  'v' => $pubB64,  'v2' => $pubB64]);
    $stmt->execute(['k' => 'push_vapid_private', 'v' => $privB64, 'v2' => $privB64]);
    return ['public' => $pubB64, 'private' => $privB64];
}

/**
 * Base64-URL encode (bez padding) — RFC 7515.
 */
function b64_url_encode(string $data): string {
    return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
}
function b64_url_decode(string $data): string {
    return base64_decode(strtr($data, '-_', '+/') . str_repeat('=', (4 - strlen($data) % 4) % 4));
}

/**
 * Pošle push notifikaci konkrétní subscription.
 * Vrací ['ok' => bool, 'http_code' => int, 'chyba' => string].
 *
 * @param array $sub  subscription z DB (endpoint, p256dh, auth)
 * @param array $payload  ['title' => '...', 'body' => '...', 'url' => '...']
 * @param string $email   kontaktní e-mail pro VAPID 'sub' claim (firma_email)
 */
function push_send(PDO $pdo, array $sub, array $payload, string $email = ''): array {
    $vapid = vapid_keys_get_or_create($pdo);
    $endpoint = $sub['endpoint'];
    $audience = parse_audience_url($endpoint);
    if (!$email) $email = nastaveni_get($pdo, 'firma_email', 'support@appek.cz');

    // VAPID JWT header + payload
    $header = ['typ' => 'JWT', 'alg' => 'ES256'];
    $jwtPayload = [
        'aud' => $audience,
        'exp' => time() + 12 * 3600,    // platnost 12 hodin
        'sub' => "mailto:$email",
    ];
    $unsigned = b64_url_encode(json_encode($header)) . '.' . b64_url_encode(json_encode($jwtPayload));
    $signature = vapid_sign($unsigned, $vapid['private']);
    if ($signature === null) return ['ok' => false, 'http_code' => 0, 'chyba' => 'Sign failed'];
    $jwt = $unsigned . '.' . b64_url_encode($signature);

    // Encryption (aes128gcm) — RFC 8291
    $cipher = aes128gcm_encrypt(json_encode($payload), $sub['p256dh'], $sub['auth']);
    if ($cipher === null) return ['ok' => false, 'http_code' => 0, 'chyba' => 'Encrypt failed'];

    // HTTP POST
    $ch = curl_init($endpoint);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $cipher);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Content-Type: application/octet-stream',
        'Content-Encoding: aes128gcm',
        'TTL: 86400',
        'Authorization: vapid t=' . $jwt . ', k=' . $vapid['public'],
        'Urgency: normal',
    ]);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);
    $body = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    curl_close($ch);

    $ok = $code >= 200 && $code < 300;
    return [
        'ok'        => $ok,
        'http_code' => $code,
        'chyba'     => $ok ? null : ($body ?: $err ?: "HTTP $code"),
    ];
}

/**
 * Vrátí audience URL (scheme://host) z full endpoint URL.
 */
function parse_audience_url(string $url): string {
    $p = parse_url($url);
    return ($p['scheme'] ?? 'https') . '://' . ($p['host'] ?? '');
}

/**
 * Podepíše JWT pomocí ECDSA P-256 + SHA-256.
 * Vrátí raw 64-bajtový signature (R || S).
 */
function vapid_sign(string $unsigned, string $privateKeyB64): ?string {
    $privRaw = b64_url_decode($privateKeyB64);
    // Vytvoř PEM key ze raw private + matching pub
    $der = asn1_ec_private_key_der($privRaw);
    $pem = "-----BEGIN EC PRIVATE KEY-----\n"
         . chunk_split(base64_encode($der), 64, "\n")
         . "-----END EC PRIVATE KEY-----\n";
    $key = openssl_pkey_get_private($pem);
    if (!$key) return null;
    $sig = '';
    if (!openssl_sign($unsigned, $sig, $key, OPENSSL_ALGO_SHA256)) return null;
    // OpenSSL vrací DER-encoded → převést na R||S (64 bytes)
    return der_signature_to_raw($sig);
}

/**
 * Zabal raw 32-byte private EC key (P-256) do DER struktury pro PEM export.
 * Šablona: SEC1 — rfc5915.
 */
function asn1_ec_private_key_der(string $privRaw): string {
    // OID P-256: 1.2.840.10045.3.1.7
    // Pevná SEC1 DER struktura (s placeholder pro privát):
    // 0x30 + len + 0x02 0x01 0x01 + 0x04 0x20 [32-byte raw priv] + parameters [0xA0 + ... oid ...] + publickey [0xA1 + ...]
    // Pro jednoduchost a kompatibilitu použijeme PKCS#8 formát (mírně delší ale stabilní)
    // PKCS#8 EC private (P-256): pevný prefix kde se cpe raw priv key
    $prefix = hex2bin(
        '308141020100301306072a8648ce3d020106082a8648ce3d030107042730250201010420'
    );
    // Po prefix přijde 32-byte raw priv (0x20 = 32)
    return $prefix . $privRaw;
}

/**
 * Konvertuje DER-encoded ECDSA signature na raw R||S (64 bytes).
 */
function der_signature_to_raw(string $der): ?string {
    // SEQUENCE { INTEGER r, INTEGER s }
    $pos = 0;
    if (ord($der[$pos++]) !== 0x30) return null;
    // Délka sequence
    $len = ord($der[$pos++]);
    if ($len > 0x80) {
        $bytes = $len & 0x7F;
        $len = 0;
        for ($i = 0; $i < $bytes; $i++) $len = ($len << 8) | ord($der[$pos++]);
    }
    // INTEGER r
    if (ord($der[$pos++]) !== 0x02) return null;
    $rLen = ord($der[$pos++]);
    $r = substr($der, $pos, $rLen);
    $pos += $rLen;
    // INTEGER s
    if (ord($der[$pos++]) !== 0x02) return null;
    $sLen = ord($der[$pos++]);
    $s = substr($der, $pos, $sLen);
    // Odstraň padding 0x00 (znaménko) + dopadduj na 32 bajtů
    $r = ltrim($r, "\x00");
    $s = ltrim($s, "\x00");
    return str_pad($r, 32, "\x00", STR_PAD_LEFT) . str_pad($s, 32, "\x00", STR_PAD_LEFT);
}

/**
 * AES-128-GCM encryption pro Web Push (RFC 8291).
 * Vrací binární tělo požadavku včetně hlavičky.
 */
function aes128gcm_encrypt(string $payload, string $p256dhB64, string $authB64): ?string {
    // 1) ECDH key agreement
    $clientPub = b64_url_decode($p256dhB64);   // 65 bytes (0x04 || X || Y)
    $auth      = b64_url_decode($authB64);
    if (strlen($clientPub) !== 65 || $clientPub[0] !== "\x04") return null;

    // Generuj efemérní server klíč
    $server = openssl_pkey_new([
        'curve_name'       => 'prime256v1',
        'private_key_type' => OPENSSL_KEYTYPE_EC,
    ]);
    if (!$server) return null;
    $det = openssl_pkey_get_details($server);
    $serverPub = "\x04" . $det['ec']['x'] . $det['ec']['y'];
    $serverPriv = $det['ec']['d'];

    // ECDH shared secret — neexistuje native PHP API, použijeme křivku ručně
    $shared = ecdh_p256_shared($serverPriv, $clientPub);
    if ($shared === null) return null;

    // HKDF
    $keyInfo  = "WebPush: info\0" . $clientPub . $serverPub;
    $prkKey   = hkdf_expand(hkdf_extract($auth, $shared), $keyInfo, 32);

    $salt = random_bytes(16);
    $cek  = hkdf_expand(hkdf_extract($salt, $prkKey), "Content-Encoding: aes128gcm\0", 16);
    $nonce = hkdf_expand(hkdf_extract($salt, $prkKey), "Content-Encoding: nonce\0", 12);

    // Padding: payload + 0x02
    $padded = $payload . "\x02";
    $tag = '';
    $cipher = openssl_encrypt($padded, 'aes-128-gcm', $cek, OPENSSL_RAW_DATA, $nonce, $tag);
    if ($cipher === false) return null;

    // Header: salt(16) || rs(4 BE = 4096) || idlen(1) || keyid(serverPub)
    $rs = pack('N', 4096);
    $keyid = $serverPub;
    $header = $salt . $rs . chr(strlen($keyid)) . $keyid;
    return $header . $cipher . $tag;
}

/**
 * ECDH na P-256 — ruční výpočet. Vrací 32-byte shared secret (X souřadnice).
 * Vyžaduje BCMath nebo GMP. Použijeme GMP pro rychlost.
 */
function ecdh_p256_shared(string $privRaw, string $clientPub65): ?string {
    if (!extension_loaded('gmp')) return null;

    // P-256 parametry (NIST P-256 / secp256r1)
    $p = gmp_init('FFFFFFFF00000001000000000000000000000000FFFFFFFFFFFFFFFFFFFFFFFF', 16);
    $a = gmp_init('FFFFFFFF00000001000000000000000000000000FFFFFFFFFFFFFFFFFFFFFFFC', 16);

    $priv = gmp_init(bin2hex($privRaw), 16);
    $cx = gmp_init(bin2hex(substr($clientPub65, 1, 32)), 16);
    $cy = gmp_init(bin2hex(substr($clientPub65, 33, 32)), 16);

    [$rx, $ry] = ec_scalar_mult($priv, [$cx, $cy], $p, $a);
    if ($rx === null) return null;
    $hex = str_pad(gmp_strval($rx, 16), 64, '0', STR_PAD_LEFT);
    return hex2bin($hex);
}

// Pomocné EC funkce — point doubling + point addition + scalar multiplication
function ec_point_double(array $P, $p, $a): array {
    [$x1, $y1] = $P;
    if (gmp_cmp($y1, 0) === 0) return [null, null];
    $s = gmp_mod(gmp_mul(gmp_add(gmp_mul(3, gmp_pow($x1, 2)), $a), gmp_invert(gmp_mul(2, $y1), $p)), $p);
    $x3 = gmp_mod(gmp_sub(gmp_sub(gmp_pow($s, 2), $x1), $x1), $p);
    $y3 = gmp_mod(gmp_sub(gmp_mul($s, gmp_sub($x1, $x3)), $y1), $p);
    return [$x3, $y3];
}
function ec_point_add(array $P, array $Q, $p, $a): array {
    if ($P[0] === null) return $Q;
    if ($Q[0] === null) return $P;
    if (gmp_cmp($P[0], $Q[0]) === 0) {
        if (gmp_cmp(gmp_mod(gmp_add($P[1], $Q[1]), $p), 0) === 0) return [null, null];
        return ec_point_double($P, $p, $a);
    }
    $s = gmp_mod(gmp_mul(gmp_sub($Q[1], $P[1]), gmp_invert(gmp_sub($Q[0], $P[0]), $p)), $p);
    $x3 = gmp_mod(gmp_sub(gmp_sub(gmp_pow($s, 2), $P[0]), $Q[0]), $p);
    $y3 = gmp_mod(gmp_sub(gmp_mul($s, gmp_sub($P[0], $x3)), $P[1]), $p);
    return [$x3, $y3];
}
function ec_scalar_mult($k, array $P, $p, $a): array {
    $R = [null, null]; $Q = $P;
    while (gmp_cmp($k, 0) > 0) {
        if (gmp_cmp(gmp_mod($k, 2), 1) === 0) $R = ec_point_add($R, $Q, $p, $a);
        $Q = ec_point_double($Q, $p, $a);
        $k = gmp_div_q($k, 2);
    }
    return $R;
}

// HKDF (RFC 5869)
function hkdf_extract(string $salt, string $ikm): string {
    return hash_hmac('sha256', $ikm, $salt, true);
}
function hkdf_expand(string $prk, string $info, int $length): string {
    $out = ''; $t = ''; $i = 0;
    while (strlen($out) < $length) {
        $i++;
        $t = hash_hmac('sha256', $t . $info . chr($i), $prk, true);
        $out .= $t;
    }
    return substr($out, 0, $length);
}
