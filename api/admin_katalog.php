<?php
/**
 * 🩹 v3.0.5 — Backward-compat shim
 *
 * Stará verze POS klientů (cached service worker, browser keš) volá
 * /api/admin_katalog.php místo /api/admin_pos.php?action=catalog.
 *
 * Tento shim přesměruje volání bez ztráty session — žádný redirect, jen include.
 * Můžeme po pár releasech smazat až všichni klienti přejdou na novou verzi.
 */
$_GET['action'] = 'catalog';
require __DIR__ . '/admin_pos.php';
