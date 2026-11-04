<?php
/**
 * 🚚 LICENSE MFT — offline generátor/validátor licenčních klíčů Můj Foodtruck.
 *
 * Formát: MFT-XXXX-XXXX-XXXX-CCCC
 *   XXXX = náhodné skupiny (abeceda bez O/0/I/1)
 *   CCCC = první 4 znaky HMAC-SHA256(body, MFT_LICENSE_SALT), velkými písmeny
 *
 * Foodtruck nemá balíčky → žádná skupina s bitmaskou.
 * Salt je ZÁMĚRNĚ jiný než LICENSE_SALT (appek) → klíče obou produktů nejdou zaměnit.
 *
 * Bezpečnost: „friction not security" stejně jako u appek — salt je ve zdroji čitelný.
 * Skutečnou kontrolu dělá heartbeat proti vendor DB.
 *
 * ⚠️ Funkce mají prefix mft_ → lze načíst spolu s _license.php (žádné „Cannot redeclare").
 * ⚠️ Soubor se 1:1 kopíruje do repa Můj Foodtruck — nic neincluduje.
 */

const MFT_LICENSE_SALT = 'mujfoodtruck-2026-uwVNR60jsm';
const MFT_LICENSE_ALPHABET = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';

function mft_license_format_valid(string $key): bool {
    return (bool) preg_match('/^MFT-[A-Z2-9]{4}-[A-Z2-9]{4}-[A-Z2-9]{4}-[A-Z0-9]{4}$/', $key);
}

function mft_license_checksum(string $body): string {
    return strtoupper(substr(hash_hmac('sha256', $body, MFT_LICENSE_SALT), 0, 4));
}

function mft_license_valid(string $key): bool {
    $key = strtoupper(trim($key));
    if (!mft_license_format_valid($key)) return false;
    $parts = explode('-', $key);
    $body = implode('-', array_slice($parts, 1, 3));
    return hash_equals(mft_license_checksum($body), $parts[4]);
}

function mft_license_generate(): string {
    $abc = MFT_LICENSE_ALPHABET;
    $max = strlen($abc) - 1;
    $groups = [];
    for ($g = 0; $g < 3; $g++) {
        $s = '';
        for ($i = 0; $i < 4; $i++) $s .= $abc[random_int(0, $max)];
        $groups[] = $s;
    }
    $body = implode('-', $groups);
    return "MFT-$body-" . mft_license_checksum($body);
}
