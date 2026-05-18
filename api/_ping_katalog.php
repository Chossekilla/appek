<?php
// Diagnostický skript — ověř, že nový admin_katalog_email.php je nahraný a parsuje se.
// Smaž po použití.
header('Content-Type: application/json; charset=utf-8');

$file = __DIR__ . '/admin_katalog_email.php';
$out = [
    'exists'   => file_exists($file),
    'size'     => file_exists($file) ? filesize($file) : null,
    'mtime'    => file_exists($file) ? date('Y-m-d H:i:s', filemtime($file)) : null,
    'php_ver'  => PHP_VERSION,
];

// Zkus parsovat soubor (lint)
if (function_exists('php_check_syntax')) {
    // (php_check_syntax byla smazána v PHP 5.x, ale pro jistotu)
    $out['lint'] = @php_check_syntax($file);
} else {
    // Alternativa: zkus načíst tokenizer — pokud uspěje, soubor je validní
    try {
        $code = @file_get_contents($file);
        if ($code === false) {
            $out['lint'] = 'unable to read';
        } else {
            $tokens = @token_get_all($code, TOKEN_PARSE);
            $out['lint'] = is_array($tokens) ? 'parse_ok' : 'parse_failed';
            $out['size_read'] = strlen($code);
            $out['first_bytes'] = bin2hex(substr($code, 0, 16));
            $out['last_bytes'] = bin2hex(substr($code, -16));
        }
    } catch (Throwable $e) {
        $out['lint'] = 'parse_error';
        $out['parse_msg'] = $e->getMessage();
    }
}

echo json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
