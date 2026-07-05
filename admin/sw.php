<?php
/**
 * 🐛 v3.0.402 — Service worker servírovaný přes PHP.
 *
 * Hostinger hcdn CDN cachuje statické .js 7 dní BEZ OHLEDU na origin
 * Cache-Control (ověřeno: sw.js max-age=604800 i přes .htaccess no-cache)
 * → klienti drželi starý SW a ten starý admin.js → footer ukazoval starou
 * verzi a stale-detektor byl slepý (manifest .json = LiteSpeed 403).
 * PHP requesty CDN necachuje → prohlížeč dostane nový SW hned po deployi.
 */
header('Content-Type: application/javascript; charset=UTF-8');
header('Cache-Control: no-cache, no-store, must-revalidate');
header('Service-Worker-Allowed: /admin/');
readfile(__DIR__ . '/sw.js');
