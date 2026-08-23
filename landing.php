<?php
/**
 * 🎨 LANDING VARIANT ROUTER — servíruje aktivní variantu prodejní stránky.
 *
 * Ruční přepínač: vendor vybere aktivní variantu (vendor_settings.landing_variant),
 * všichni návštěvníci dostanou tu samou. Měření konverze per varianta zvlášť
 * (lp/track.php + vendor_shop_orders.landing_variant).
 *
 * Aktivuje se JEN na apexu (appek.cz) — .htaccess přepíše `/` → landing.php pouze
 * když existuje marker `.landing-only`. Zákaznické instalace (bez markeru) tento
 * soubor nikdy nespustí, servírují svůj index.html napřímo.
 *
 * PHP schválně — hcdn CDN necachuje .php → přepnutí varianty ve vendoru je vidět
 * okamžitě. Fail-safe: cokoli selže → 'classic' (původní index.html).
 *
 * Preview bez přepnutí pro všechny: appek.cz/?lp=<varianta>
 */

$variant = 'classic';

// Preview override z URL (?lp=modernist) — nepřepíná nic pro ostatní návštěvníky
if (!empty($_GET['lp'])) {
    $variant = preg_replace('/[^a-z0-9_-]/', '', strtolower((string) $_GET['lp']));
} else {
    try {
        if (is_dir(__DIR__ . '/vendor')) {
            require_once __DIR__ . '/vendor/_lib.php';
            $v = vendor_db()->query("SELECT `value` FROM vendor_settings WHERE `key` = 'landing_variant'")->fetchColumn();
            if ($v) $variant = preg_replace('/[^a-z0-9_-]/', '', strtolower((string) $v));
        }
    } catch (Throwable $e) { /* fail-safe → classic */ }
}

// Mapa variant → soubor. 'classic' = původní index.html; ostatní = lp/<varianta>.html
// (rozšiřitelné: stačí přidat lp/<nová>.html a vybrat ji ve vendoru).
if ($variant === 'classic' || $variant === '') {
    $file = __DIR__ . '/index.html';
} else {
    $cand = __DIR__ . '/lp/' . $variant . '.html';
    $file = is_file($cand) ? $cand : __DIR__ . '/index.html';
}
if (!is_file($file)) { $file = __DIR__ . '/index.html'; }

header('Content-Type: text/html; charset=UTF-8');
header('Cache-Control: no-cache, must-revalidate');
readfile($file);
