<?php
/**
 * 🔐 UPDATE SIGNATURE — ověření kryptopodpisu update bundlu (KLIENTSKÁ strana).
 *
 * Supply-chain ochrana: i kompromitovaný distribuční kanál (CDN / mirror / feed)
 * nemůže podstrčit kód, který klient přijme — manifest update bundlu musí být
 * podepsaný privátním klíčem vendoru (RSA-2048 / SHA-256). Veřejný klíč je
 * zapečený zde (distribuovaný = veřejný, neprozradí nic).
 *
 * CO se podepisuje: OBSAH `manifest.json` (ne byty ZIP kontejneru). Mirror smí
 * bundle přebalit (jiná komprese → jiné byty kontejneru), ale obsah souborů —
 * a tím i manifest.json — zůstává bajt po bajtu stejný. Klient ověří podpis nad
 * přesnými byty manifestu ze stagingu a per-file SHA-256 (updates_apply.php)
 * pak sváže reálné soubory s podepsaným manifestem.
 *
 * Privátní klíč žije JEN na vendor serveru (vendor/config.local.php,
 * APPEK_UPDATE_PRIVATE_KEY) — nikdy se nebalí do customer bundlu (api/+admin/+b2b/).
 */

// Veřejný klíč pro ověření podpisů (RSA-2048 SPKI PEM).
if (!defined('APPEK_UPDATE_PUBLIC_KEY')) {
    define('APPEK_UPDATE_PUBLIC_KEY', <<<'PEM'
-----BEGIN PUBLIC KEY-----
MIIBIjANBgkqhkiG9w0BAQEFAAOCAQ8AMIIBCgKCAQEAvlwwsT43Ak7EfGWsjcTj
HTDDT5u4geImiVSwdrpCVOBKOADChpmG7AbsLUI3nF0M/h6Ld+bpAjoaOAo36tyr
Hmn1+RJlZHdgDccEauLP7KfUMRqi3WJNWAt6Cdx65WQ2sVkmz+oxnIaD0K/KtfDI
Gjr4SaOQGC3VOIyhjna0vgL6MUvQUeoy30prCxtVbQlcMYPPx6NgHg5p55QT3k6a
wqjfLVoWz3RFlDWRwP9KBVxWVafN2eitBjlMKUEYoH/QfocS1ez/4wIOYUbQrUz/
9RqVKGapUehqSL3ektqsHjQ1TBnIfKbny+C0pw6E0E1LTd9sIlJbdhl2cyEL9rbb
MwIDAQAB
-----END PUBLIC KEY-----
PEM);
}

/**
 * Je ověřování podpisu vynucené? true = public key je zapečený → fail-closed
 * (každý OTA update MUSÍ nést platný podpis, jinak se odmítne).
 */
function appek_update_signing_enforced(): bool {
    return defined('APPEK_UPDATE_PUBLIC_KEY') && trim((string) APPEK_UPDATE_PUBLIC_KEY) !== '';
}

/**
 * Ověří RSA-SHA256 podpis nad přesnými byty manifestu.
 *
 * @param string $manifestBytes  přesné byty manifest.json ze stagingu
 * @param string $sigB64         base64-kódovaný podpis (z updates_check response)
 * @return bool  true = podpis platný
 */
function appek_verify_update_signature(string $manifestBytes, string $sigB64): bool {
    if (!function_exists('openssl_verify')) return false;
    $sigB64 = trim($sigB64);
    if ($sigB64 === '') return false;
    $sig = base64_decode($sigB64, true);
    if ($sig === false || $sig === '') return false;
    $pub = openssl_pkey_get_public(APPEK_UPDATE_PUBLIC_KEY);
    if ($pub === false) return false;
    $ok = openssl_verify($manifestBytes, $sig, $pub, OPENSSL_ALGO_SHA256);
    return $ok === 1;
}
