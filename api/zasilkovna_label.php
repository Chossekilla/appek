<?php
/**
 * 📦 ZÁSILKOVNA LABEL — stáhne PDF štítek pro zásilku.
 *
 * GET /api/zasilkovna_label.php?packet_id=12345678&format=A6%20on%20A4
 *
 * Vrátí PDF s Content-Type application/pdf.
 */

$vendorRoot = realpath(__DIR__ . '/..') . '/vendor';
if (!is_dir($vendorRoot)) {
    http_response_code(503);
    echo "vendor not configured";
    exit;
}
require_once $vendorRoot . '/_lib.php';
require_once $vendorRoot . '/_mail.php';
require_once $vendorRoot . '/_zasilkovna.php';

$packetId = trim($_GET['packet_id'] ?? '');
$format   = $_GET['format'] ?? 'A6 on A4';
if (!$packetId) {
    http_response_code(400);
    echo "missing packet_id";
    exit;
}

$pdf = packeta_label_pdf($packetId, $format);
if (!$pdf) {
    http_response_code(500);
    echo "label generation failed";
    exit;
}

header('Content-Type: application/pdf');
header('Content-Disposition: inline; filename="zasilkovna-' . $packetId . '.pdf"');
header('Content-Length: ' . strlen($pdf));
echo $pdf;
