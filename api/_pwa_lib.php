<?php
/**
 * 🆕 v3.0.364 — PWA install ikony (white-label).
 *
 * Aktivní ikony žijí v  /uploads/logo/pwa/  (mimo bundle → self-update je NEPŘEPÍŠE).
 * Manifesty + apple-touch míří sem. Default = APPEK ikona (admin/icons/, v bundlu) se
 * naseeduje, když chybí. Po nahrání loga v adminu se přegenerují ze zákazníkova loga (GD).
 */

/** Cesta k aktivním PWA ikonám (webroot/uploads/logo/pwa). */
function appek_pwa_dir(): string {
    return dirname(__DIR__) . '/uploads/logo/pwa';
}

/** Naseeduje APPEK default ikony do /uploads/logo/pwa, pokud tam ještě nejsou. Idempotentní, levné. */
function appek_ensure_pwa_icons(): void {
    static $done = false;
    if ($done) return;
    $done = true;
    $dest = appek_pwa_dir();
    if (is_file($dest . '/icon-512.png')) return;           // už existuje (custom nebo seed)
    $src = dirname(__DIR__) . '/admin/icons';               // shipnutý APPEK default
    if (!is_file($src . '/icon-512.png')) return;           // není z čeho seedovat
    @mkdir($dest, 0755, true);
    // .htaccess — zákaz spuštění PHP v uploads (defense-in-depth)
    if (!is_file($dest . '/.htaccess')) {
        @file_put_contents($dest . '/.htaccess', "<FilesMatch \"\\.(php|php3|php4|php5|phtml|phar)$\">\n    Require all denied\n</FilesMatch>\n");
    }
    foreach (['icon-192.png', 'icon-512.png', 'icon-maskable.png', 'icon-apple.png'] as $f) {
        if (is_file($src . '/' . $f)) @copy($src . '/' . $f, $dest . '/' . $f);
    }
}

/**
 * Vygeneruje PWA sadu ze zákazníkova loga (GD resource) → /uploads/logo/pwa.
 * Logo se vystředí na NEPRŮHLEDNÉ bílé pozadí (apple/maskable musí být neprůhledné);
 * maskable má větší okraj (Android safe-zone). Vrací počet vytvořených souborů.
 */
function appek_gen_pwa_icons_from_gd($logoImg, ?string $bgRgb = null): int {
    if (!$logoImg || !function_exists('imagecreatetruecolor')) return 0;
    $dest = appek_pwa_dir();
    @mkdir($dest, 0755, true);
    $srcW = imagesx($logoImg);
    $srcH = imagesy($logoImg);
    if ($srcW < 1 || $srcH < 1) return 0;
    [$br, $bg, $bb] = $bgRgb && count($bgRgb = array_map('intval', explode(',', $bgRgb))) === 3 ? $bgRgb : [255, 255, 255];
    $targets = [
        'icon-192.png'      => [192, 0.12],
        'icon-512.png'      => [512, 0.12],
        'icon-apple.png'    => [180, 0.12],
        'icon-maskable.png' => [512, 0.22],   // větší okraj = Android maskable safe-zone
    ];
    $n = 0;
    foreach ($targets as $name => [$size, $pad]) {
        $canvas = imagecreatetruecolor($size, $size);
        $bgcol = imagecolorallocate($canvas, $br, $bg, $bb);
        imagefilledrectangle($canvas, 0, 0, $size, $size, $bgcol);
        $avail = (int) ($size * (1 - 2 * $pad));
        $ratio = min($avail / $srcW, $avail / $srcH);
        $dw = max(1, (int) round($srcW * $ratio));
        $dh = max(1, (int) round($srcH * $ratio));
        $dx = (int) (($size - $dw) / 2);
        $dy = (int) (($size - $dh) / 2);
        imagecopyresampled($canvas, $logoImg, $dx, $dy, 0, 0, $dw, $dh, $srcW, $srcH);
        if (@imagepng($canvas, $dest . '/' . $name, 6)) $n++;
        imagedestroy($canvas);
    }
    return $n;
}

/** Smaže custom PWA ikony a vrátí APPEK default (volá se při remove_logo). */
function appek_reset_pwa_icons(): void {
    $dest = appek_pwa_dir();
    foreach (['icon-192.png', 'icon-512.png', 'icon-maskable.png', 'icon-apple.png'] as $f) {
        @unlink($dest . '/' . $f);
    }
    appek_ensure_pwa_icons();   // re-seed default
}
