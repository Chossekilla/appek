<?php
/**
 * 🆕 v3.0.365 — PWA manifest servírovaný přes PHP.
 * Hosting (Hostinger/LiteSpeed) blokuje statické .json souborem (403 Forbidden) → statický
 * manifest.json byl nedostupný → Android PWA install rozbitý (iOS jel přes apple-touch).
 * PHP soubor blok obejde (PHP se servíruje 200). Jeden zdroj pravdy = manifest.json (readfile).
 */
header('Content-Type: application/manifest+json; charset=utf-8');
header('Cache-Control: public, max-age=300');
$f = __DIR__ . '/manifest.json';
if (is_file($f)) {
    readfile($f);
} else {
    http_response_code(404);
    echo '{}';
}
