<?php
/**
 * 🔒 gdpr_verejne.php (v3.0.425) — VEŘEJNÉ čtení zásad zpracování osobních údajů.
 * Bez autentizace (jako firma_branding.php). Použito v B2B portálu / e-shopu pro
 * zobrazení zásad zákazníkovi. Vrací uložený text, nebo obecnou šablonu.
 *
 * GET → { text, updated, is_default }
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/_gdpr_lib.php';
cors_headers();

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') json_error('Method not allowed', 405);

$pdo = db();
$text = '';
$updated = '';
try {
    $st = $pdo->prepare("SELECT hodnota FROM nastaveni WHERE klic = 'gdpr_zasady_text'");
    $st->execute();
    $text = (string) $st->fetchColumn();
    $st = $pdo->prepare("SELECT hodnota FROM nastaveni WHERE klic = 'gdpr_zasady_updated'");
    $st->execute();
    $updated = (string) $st->fetchColumn();
} catch (Throwable $e) { /* ignore */ }

$isDefault = trim($text) === '';
if ($isDefault) $text = gdpr_default_template(gdpr_firma($pdo));

json_response(['text' => $text, 'updated' => $updated, 'is_default' => $isDefault]);
