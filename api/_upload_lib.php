<?php
/**
 * 🖼️ Sdílený upload obrázku předlohy → /uploads/predlohy/  (v3.0.306)
 *
 * Použití (cake konfigurátor i catering kalkulačka):
 *   require_once __DIR__ . '/_upload_lib.php';
 *   $url = upload_predloha_image('foto');   // při chybě volá json_error a ukončí
 *   json_response(['ok' => true, 'url' => $url]);
 *
 * Unikátní jména (víc objednávek) · zmenší na max 1600 px · .htaccess zakáže PHP.
 * Vyžaduje GD. URL je ROOT-absolutní `/uploads/predlohy/…` (produkce na doménovém rootu).
 */

if (!function_exists('upload_predloha_image')) {
    function upload_predloha_image(string $field = 'foto'): string {
        if (empty($_FILES[$field])) json_error('Chybí soubor');
        $file = $_FILES[$field];
        if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) json_error('Chyba uploadu (kód ' . ($file['error'] ?? '?') . ')');

        $allowed = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];
        $mime = function_exists('mime_content_type') ? mime_content_type($file['tmp_name']) : ($file['type'] ?? '');
        if (!isset($allowed[$mime])) json_error('Povolen je jen JPG, PNG nebo WEBP');
        if (($file['size'] ?? 0) > 8 * 1024 * 1024) json_error('Soubor je větší než 8 MB');

        $img = match ($mime) {
            'image/jpeg' => @imagecreatefromjpeg($file['tmp_name']),
            'image/png'  => @imagecreatefrompng($file['tmp_name']),
            'image/webp' => @imagecreatefromwebp($file['tmp_name']),
            default      => null,
        };
        if (!$img) json_error('Nepodařilo se zpracovat obrázek (potřebuje GD extension)');

        $dir = __DIR__ . '/../uploads/predlohy';
        if (!is_dir($dir)) @mkdir($dir, 0755, true);
        $ht = $dir . '/.htaccess';
        if (!file_exists($ht)) @file_put_contents($ht, "<FilesMatch \"\\.(php|php3|php4|php5|phtml|phar)$\">\n    Require all denied\n</FilesMatch>\n");

        // Zmenši na max 1600 px delší strana (reference foto — nemusí být plné rozlišení)
        $w = imagesx($img); $h = imagesy($img); $max = 1600;
        if ($w > $max || $h > $max) {
            if ($w >= $h) { $nw = $max; $nh = (int) ($h * $max / $w); }
            else          { $nh = $max; $nw = (int) ($w * $max / $h); }
            $res = imagecreatetruecolor($nw, $nh);
            imagealphablending($res, false); imagesavealpha($res, true);
            imagecopyresampled($res, $img, 0, 0, 0, 0, $nw, $nh, $w, $h);
            imagedestroy($img); $img = $res;
        }

        try { $rand = bin2hex(random_bytes(4)); } catch (Throwable $e) { $rand = substr(md5(uniqid('', true)), 0, 8); }
        $ext  = $allowed[$mime];
        $name = 'predloha_' . date('Ymd_His') . '_' . $rand . '.' . $ext;
        $path = $dir . '/' . $name;
        $ok = ($ext === 'png')  ? imagepng($img, $path, 6)
            : (($ext === 'webp') ? imagewebp($img, $path, 85) : imagejpeg($img, $path, 88));
        imagedestroy($img);
        if (!$ok || !file_exists($path)) json_error('Uložení fotky selhalo (oprávnění uploads/?)', 500);

        return '/uploads/predlohy/' . $name;
    }
}
