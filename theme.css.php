<?php
/**
 * 🎨 FRONTPAGE THEME LOADER — v3.0.404
 *
 * <link rel="stylesheet" href="theme.css.php"> v index.html.
 * Aktivní téma řídí VENDOR (vendor_settings.site_theme: classic|studio|noir).
 * Preview: theme.css.php?theme=studio (index.html přepíše href podle ?theme= v URL).
 *
 * PHP schválně — hcdn CDN necachuje .php → přepnutí tématu ve vendoru
 * je vidět okamžitě (statický .css by visel v 7denní CDN cache).
 * Fail-safe: cokoli selže → prázdné CSS = původní vzhled (Classic).
 */
header('Content-Type: text/css; charset=UTF-8');
header('Cache-Control: no-cache, must-revalidate');

$theme = '';
if (isset($_GET['theme'])) {
    $theme = preg_replace('/[^a-z0-9_-]/', '', strtolower((string) $_GET['theme']));
}
if ($theme === '') {
    try {
        if (is_dir(__DIR__ . '/vendor')) {
            require_once __DIR__ . '/vendor/_lib.php';
            $v = vendor_db()->query("SELECT `value` FROM vendor_settings WHERE `key` = 'site_theme'")->fetchColumn();
            if ($v) $theme = preg_replace('/[^a-z0-9_-]/', '', strtolower((string) $v));
        }
    } catch (Throwable $e) { /* fail-safe → classic */ }
}

$file = __DIR__ . '/themes/' . $theme . '.css';
if ($theme === '' || $theme === 'classic' || !is_file($file)) {
    echo "/* theme: classic (původní vzhled) */\n";
    exit;
}
echo "/* theme: {$theme} */\n";
readfile($file);
