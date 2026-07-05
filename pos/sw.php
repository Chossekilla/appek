<?php
/**
 * 🐛 v3.0.402 — Service worker servírovaný přes PHP (viz admin/sw.php).
 * hcdn CDN cachuje statické .js 7 dní bez ohledu na origin hlavičky →
 * starý SW → staré assety. PHP CDN necachuje → SW update hned po deployi.
 */
header('Content-Type: application/javascript; charset=UTF-8');
header('Cache-Control: no-cache, no-store, must-revalidate');
header('Service-Worker-Allowed: ./');
readfile(__DIR__ . '/sw.js');
