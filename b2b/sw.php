<?php
/**
 * 🐛 v3.0.403 — Service worker (push) servírovaný přes PHP (viz admin/sw.php).
 * b2b/sw.js má push+notificationclick handlery, ale nikdy se neregistroval —
 * app.js mířil na neexistující root /sw.js (404) → push notifikace tiše mrtvé.
 * PHP = mimo hcdn CDN cache → update workeru hned po deployi.
 */
header('Content-Type: application/javascript; charset=UTF-8');
header('Cache-Control: no-cache, no-store, must-revalidate');
header('Service-Worker-Allowed: ./');
readfile(__DIR__ . '/sw.js');
