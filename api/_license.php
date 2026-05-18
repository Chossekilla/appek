<?php
/**
 * 🔑 LICENSE — offline validátor licenčních klíčů Appek B2B.
 *
 * Klíče existují ve dvou formátech:
 *   v1 (legacy):  APPEK-XXXX-XXXX-XXXX-CCCC    (core only)
 *   v2 (s balíčky): APPEK-XXXX-XXXX-XXXX-PPPP-CCCC
 *     PPPP = base32 bitmaska balíčků (bit 0 = cukrarna, 1 = lahudky, …)
 *     CCCC = HMAC checksum (chrání i PPPP)
 *
 * Bezpečnost: PHP zdroj je čitelný, takže LICENSE_SALT je viditelný a teoreticky
 * lze generovat klíče. Tohle je „friction not security" — odradí 99% náhodných
 * hostingových snoopnerů, ale není to DRM. Primárně je legální ochrana.
 */

// 🧂 Salt — unikátní per build. Změníš → invaliduje všechny dosud vydané klíče.
const LICENSE_SALT = 'appek-b2b-2026-MmKp9XzVqL';

// Abeceda klíčů — bez vizuálně matoucích znaků (O/0/I/1 vynechány) — 32 znaků = 5 bitů/znak
const LICENSE_ALPHABET = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';

// 🎁 Mapování balíček → bit (0-19, max 20 balíčků v 4 znacích = 20 bitech)
const LICENSE_PACKAGE_BITS = [
    'cukrarna'   => 0,
    'lahudky'    => 1,
    'restaurace' => 2,
    'catering'   => 3,
    'sezona'     => 4,
    // další: bit 5-19 zatím rezervováno
];

// ════════════════════════════════════════════════════════════
// VALIDACE
// ════════════════════════════════════════════════════════════

function license_format_valid(string $key): bool {
    // Random skupiny používají LICENSE_ALPHABET (A-Z bez O/I, 2-9 bez 0/1)
    // Checksum je SHA-256 HEX (0-9, A-F) — povol 0 a 1 jen u checksum skupiny
    return (bool) preg_match('/^APPEK-[A-Z2-9]{4}-[A-Z2-9]{4}-[A-Z2-9]{4}(-[A-Z2-9]{4})?-[A-Z0-9]{4}$/', $key);
}

function license_checksum(string $body): string {
    $hash = hash_hmac('sha256', $body, LICENSE_SALT);
    return strtoupper(substr($hash, 0, 4));
}

function license_valid(string $key): bool {
    $key = strtoupper(trim($key));
    if (!license_format_valid($key)) return false;
    $parts = explode('-', $key);
    $count = count($parts);
    if ($count !== 5 && $count !== 6) return false;
    if ($parts[0] !== 'APPEK') return false;
    $checksum = $parts[$count - 1];
    $body = implode('-', array_slice($parts, 1, $count - 2));
    return hash_equals(license_checksum($body), $checksum);
}

// ════════════════════════════════════════════════════════════
// PACKAGE ENCODING / DECODING
// ════════════════════════════════════════════════════════════

/**
 * Bitmaska → 4-char base32 string (zarovnáno na 4 znaky).
 */
function license_packages_to_str(array $packages): string {
    $bitmask = 0;
    foreach ($packages as $p) {
        if (isset(LICENSE_PACKAGE_BITS[$p])) {
            $bitmask |= (1 << LICENSE_PACKAGE_BITS[$p]);
        }
    }
    return license_int_to_base32_4($bitmask);
}

/**
 * 4-char base32 string → bitmaska → seznam balíčků.
 */
function license_str_to_packages(string $pkgStr): array {
    $bitmask = license_base32_4_to_int($pkgStr);
    $packages = [];
    foreach (LICENSE_PACKAGE_BITS as $name => $bit) {
        if ($bitmask & (1 << $bit)) $packages[] = $name;
    }
    return $packages;
}

/**
 * Int → 4 znaky base32 (každý znak nese 5 bitů, total 20 bitů, MSB první).
 */
function license_int_to_base32_4(int $n): string {
    $abc = LICENSE_ALPHABET;
    $s = '';
    for ($i = 3; $i >= 0; $i--) {
        $idx = ($n >> ($i * 5)) & 0x1F;
        $s .= $abc[$idx];
    }
    return $s;
}

/**
 * 4 znaky base32 → int.
 */
function license_base32_4_to_int(string $s): int {
    $abc = LICENSE_ALPHABET;
    $n = 0;
    $len = strlen($s);
    for ($i = 0; $i < $len; $i++) {
        $idx = strpos($abc, $s[$i]);
        if ($idx === false) return 0;
        $n = ($n << 5) | $idx;
    }
    return $n;
}

/**
 * Z klíče (validovaného nebo ne) vydoluje seznam balíčků.
 * v1 klíč (5 skupin) → vždy ['core']
 * v2 klíč (6 skupin) → ['core', ...packages from bitmask]
 */
function license_packages(string $key): array {
    $key = strtoupper(trim($key));
    $packages = ['core']; // core je vždy
    $parts = explode('-', $key);
    if (count($parts) === 6) {
        $pkgStr = $parts[4];
        $packages = array_merge($packages, license_str_to_packages($pkgStr));
    }
    return $packages;
}

// ════════════════════════════════════════════════════════════
// GENEROVÁNÍ
// ════════════════════════════════════════════════════════════

/**
 * Vygeneruje v1 klíč (jen core, bez balíčků).
 */
function license_generate(): string {
    return license_generate_with_packages([]);
}

/**
 * Vygeneruje v2 klíč s balíčky.
 *
 * @param array $packages např. ['cukrarna', 'lahudky']
 */
function license_generate_with_packages(array $packages): string {
    $abc = LICENSE_ALPHABET;
    $len = strlen($abc);
    $groups = [];
    for ($g = 0; $g < 3; $g++) {
        $s = '';
        for ($i = 0; $i < 4; $i++) {
            $s .= $abc[random_int(0, $len - 1)];
        }
        $groups[] = $s;
    }

    // Pokud nejsou žádné balíčky → v1 formát (5 skupin)
    if (empty($packages)) {
        $body = implode('-', $groups);
        return "APPEK-$body-" . license_checksum($body);
    }

    // v2 formát (6 skupin) — předposlední skupina je bitmaska balíčků
    $pkgStr = license_packages_to_str($packages);
    $body = implode('-', $groups) . '-' . $pkgStr;
    return "APPEK-$body-" . license_checksum($body);
}

/**
 * Z existujícího klíče vygeneruje nový se změněnými balíčky.
 * (Random část nechá stejnou — jen přepíše PKG skupinu a přepočítá checksum.)
 * Užitečné když vendor přidá balíček k existující licenci.
 */
function license_reissue_with_packages(string $oldKey, array $packages): string {
    $oldKey = strtoupper(trim($oldKey));
    if (!license_valid($oldKey)) {
        // Nevalidní klíč → nový od nuly
        return license_generate_with_packages($packages);
    }
    $parts = explode('-', $oldKey);
    // Vezmi první 3 random skupiny ze starého klíče
    $r1 = $parts[1]; $r2 = $parts[2]; $r3 = $parts[3];

    if (empty($packages)) {
        $body = "$r1-$r2-$r3";
        return "APPEK-$body-" . license_checksum($body);
    }

    $pkgStr = license_packages_to_str($packages);
    $body = "$r1-$r2-$r3-$pkgStr";
    return "APPEK-$body-" . license_checksum($body);
}

// ════════════════════════════════════════════════════════════
// CURRENT LICENSE (z config.local.php)
// ════════════════════════════════════════════════════════════

function license_get_current(): ?string {
    return defined('APP_LICENSE_KEY') && APP_LICENSE_KEY ? APP_LICENSE_KEY : null;
}

function license_status(): array {
    $key = license_get_current();
    if (!$key) return ['ok' => false, 'reason' => 'no_key', 'key' => null, 'packages' => ['core']];
    if (!license_valid($key)) return ['ok' => false, 'reason' => 'invalid_key', 'key' => $key, 'packages' => ['core']];
    return ['ok' => true, 'reason' => null, 'key' => $key, 'packages' => license_packages($key)];
}

function license_masked(?string $key): string {
    if (!$key || !license_format_valid($key)) return '—';
    $parts = explode('-', $key);
    $count = count($parts);
    // Zobraz první 2 a poslední 1 skupinu, zbytek maskuj
    $out = [$parts[0], $parts[1]];
    for ($i = 2; $i < $count - 1; $i++) $out[] = '••••';
    $out[] = $parts[$count - 1];
    return implode('-', $out);
}

/**
 * Helper pro ostatní backend kód: má aktivní licence balíček?
 * Použij místo `package_enabled()` z admin_packages.php pokud chceš
 * čistou license-only gate (bez customer toggle stavu).
 */
function license_has_package(string $packageKey): bool {
    static $cache = null;
    if ($cache === null) {
        $st = license_status();
        $cache = $st['packages'] ?? ['core'];
    }
    return in_array($packageKey, $cache, true);
}
