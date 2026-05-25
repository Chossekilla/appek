<?php
/**
 * 🔐 TOTP (RFC 6238) — Google Authenticator / Authy / 1Password kompatibilní.
 * Zero-dep PHP implementace.
 *
 * Public API:
 *   totp_generate_secret(): string         → base32 secret (16 chars)
 *   totp_verify(string $secret, string $code, int $window = 1): bool
 *   totp_otpauth_url(string $secret, string $account, string $issuer): string
 */

const TOTP_DIGITS = 6;
const TOTP_PERIOD = 30;
const TOTP_ALGO   = 'sha1';

function totp_generate_secret(int $length = 16): string {
    $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567'; // base32
    $bytes = random_bytes($length);
    $out = '';
    for ($i = 0; $i < $length; $i++) {
        $out .= $alphabet[ord($bytes[$i]) & 0x1F];
    }
    return $out;
}

function base32_decode(string $b32): string {
    $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
    $b32 = strtoupper(preg_replace('/[^A-Z2-7]/', '', $b32));
    if ($b32 === '') return '';
    $bits = '';
    foreach (str_split($b32) as $c) {
        $bits .= str_pad(decbin(strpos($alphabet, $c)), 5, '0', STR_PAD_LEFT);
    }
    $out = '';
    foreach (str_split($bits, 8) as $chunk) {
        if (strlen($chunk) === 8) $out .= chr(bindec($chunk));
    }
    return $out;
}

function totp_at(string $secret, int $forTime): string {
    $key = base32_decode($secret);
    $counter = floor($forTime / TOTP_PERIOD);
    $bin = pack('N*', 0, $counter); // 8 bytes big-endian
    $hash = hash_hmac(TOTP_ALGO, $bin, $key, true);
    $offset = ord($hash[strlen($hash) - 1]) & 0x0F;
    $code = ((ord($hash[$offset])     & 0x7F) << 24)
          | ((ord($hash[$offset + 1]) & 0xFF) << 16)
          | ((ord($hash[$offset + 2]) & 0xFF) << 8)
          | ( ord($hash[$offset + 3]) & 0xFF);
    return str_pad((string) ($code % (10 ** TOTP_DIGITS)), TOTP_DIGITS, '0', STR_PAD_LEFT);
}

/**
 * Ověří kód v okně ±$window period (default ±1 = ±30s před/po pro toleranci hodin).
 */
function totp_verify(string $secret, string $code, int $window = 1): bool {
    $code = trim($code);
    if (!preg_match('/^\d{6}$/', $code)) return false;
    $now = time();
    for ($i = -$window; $i <= $window; $i++) {
        $candidate = totp_at($secret, $now + $i * TOTP_PERIOD);
        if (hash_equals($candidate, $code)) return true;
    }
    return false;
}

function totp_otpauth_url(string $secret, string $account, string $issuer = 'Appek Vendor'): string {
    $label = rawurlencode($issuer . ':' . $account);
    $params = http_build_query([
        'secret'    => $secret,
        'issuer'    => $issuer,
        'algorithm' => strtoupper(TOTP_ALGO),
        'digits'    => TOTP_DIGITS,
        'period'    => TOTP_PERIOD,
    ]);
    return "otpauth://totp/$label?$params";
}

/**
 * QR kód URL přes Google Charts API (fallback pokud nemáme lokální generator).
 * Customer offline řešení: ukázat secret jako text + nechat uživatele opsat ručně.
 */
function totp_qr_url(string $otpauthUrl): string {
    return 'https://api.qrserver.com/v1/create-qr-code/?size=200x200&data=' . urlencode($otpauthUrl);
}
