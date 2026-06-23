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
function vendor_sign_update_manifest(string $manifestBytes): ?string {
    if (!defined('APPEK_UPDATE_PRIVATE_KEY') || trim((string) APPEK_UPDATE_PRIVATE_KEY) === '') {
        return null; // klíč nenastaven → nepodepsáno (publish-verify to nahlásí)
    }
    if (!function_exists('openssl_sign')) return null;
    $priv = openssl_pkey_get_private(APPEK_UPDATE_PRIVATE_KEY);
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
