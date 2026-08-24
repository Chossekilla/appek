<?php
/**
 * 🔐 UPDATE SIGNATURE — podepisování manifestu update bundlu (VENDOR strana).
 *
 * Privátní klíč se NIKDY nebalí do customer bundlu (api/+admin/+b2b/) — žije jen
 * ve vendor/config.local.php jako konstanta APPEK_UPDATE_PRIVATE_KEY (RSA-2048 PEM).
 * Klient ověřuje veřejným klíčem zapečeným v api/_update_sign.php.
 *
 * Podepisuje se OBSAH manifest.json (ne byty ZIPu) — viz api/_update_sign.php.
 */

/**
 * Podepíše byty manifestu privátním klíčem vendoru.
 *
 * @param string $manifestBytes  přesné byty manifest.json
 * @return string|null  base64 podpis, nebo null když klíč není nastaven / openssl chybí
 */
/**
 * 🔐 v3.0.496 — SELF-HEAL podpisového klíče.
 *
 * Klíč se opakovaně „ztrácel" z vendor/config.local.php (preserve krok při self-update
 * ho občas neviděl → přepsán). Řešení: dedikovaný soubor config.signing.local.php, který
 * self-update NEPŘEPISUJE — není v master zipu a apply rsync jede bez --delete → přežije
 * KAŽDÝ deploy. Když je klíč v config.local.php k dispozici, automaticky se sem zazálohuje;
 * když z config.local.php zmizí, čte se odsud → publish zůstává podepsaný.
 *
 * @return string|null  PEM privátního klíče, nebo null když není nikde
 */
function vendor_signing_key(): ?string {
    // 1) Historické umístění: konstanta z vendor/config.local.php
    if (defined('APPEK_UPDATE_PRIVATE_KEY') && trim((string) APPEK_UPDATE_PRIVATE_KEY) !== '') {
        $key = (string) APPEK_UPDATE_PRIVATE_KEY;
        vendor_signing_key_persist($key); // udrž redundantní zálohu (self-heal do budoucna)
        return $key;
    }
    // 2) Self-heal: dedikovaný soubor (přežije self-update)
    $f = vendor_signing_key_file();
    if (is_file($f)) {
        $key = @include $f; // vrací PEM string
        if (is_string($key) && trim($key) !== '') return $key;
    }
    return null;
}

/** Cesta k dedikovanému klíčovému souboru (gitignored přes *.local.php). */
function vendor_signing_key_file(): string {
    return __DIR__ . '/config.signing.local.php';
}

/** Zapíše klíč do dedikovaného souboru (jen když chybí / liší se). Best-effort. */
function vendor_signing_key_persist(string $key): void {
    try {
        $f = vendor_signing_key_file();
        $cur = is_file($f) ? (@include $f) : null;
        if (is_string($cur) && $cur === $key) return; // beze změny → nezapisuj
        $body = "<?php\n"
              . "// 🔐 APPEK podpisový privátní klíč — AUTO-ZÁLOHA (self-heal, v3.0.496+).\n"
              . "// Přežije self-update (není v master zipu, rsync bez --delete). NEMAZAT, NECOMMITOVAT.\n"
              . "// Web přístup je neškodný: .php se vykoná a návratovou hodnotu nevypíše.\n"
              . 'return ' . var_export($key, true) . ";\n";
        @file_put_contents($f, $body, LOCK_EX);
        @chmod($f, 0600);
    } catch (Throwable $e) { /* best-effort — nikdy nesmí shodit podpis */ }
}

function vendor_sign_update_manifest(string $manifestBytes): ?string {
    $keyPem = vendor_signing_key(); // 🔐 v3.0.496 — konstanta NEBO self-heal záloha
    if ($keyPem === null || trim($keyPem) === '') {
        return null; // klíč nenastaven → nepodepsáno (publish-verify to nahlásí)
    }
    if (!function_exists('openssl_sign')) return null;
    $priv = openssl_pkey_get_private($keyPem);
    if ($priv === false) return null;
    $sig = '';
    if (!openssl_sign($manifestBytes, $sig, $priv, OPENSSL_ALGO_SHA256)) return null;
    return base64_encode($sig);
}

/**
 * Vytáhne přesné byty manifest.json z bundle ZIPu (pro podpis při publishi).
 *
 * @return string|null  byty manifest.json, nebo null když ZIP/manifest chybí
 */
function vendor_extract_manifest_bytes(string $zipPath): ?string {
    if (!class_exists('ZipArchive') || !is_file($zipPath)) return null;
    $zip = new ZipArchive();
    if ($zip->open($zipPath) !== true) return null;
    $bytes = $zip->getFromName('manifest.json');
    $zip->close();
    return ($bytes === false || $bytes === '') ? null : $bytes;
}

/**
 * Podepíše manifest přímo z bundle ZIPu. Vrátí base64 podpis nebo null.
 */
function vendor_sign_update_bundle(string $zipPath): ?string {
    $bytes = vendor_extract_manifest_bytes($zipPath);
    if ($bytes === null) return null;
    return vendor_sign_update_manifest($bytes);
}
