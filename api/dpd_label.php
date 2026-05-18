<?php
/**
 * 🚚 DPD LABEL — stáhne PDF štítek.
 *
 * GET /api/dpd_label.php?shipment_id=12345&format=A6
 */

$vendorRoot = realpath(__DIR__ . '/..') . '/vendor';
if (!is_dir($vendorRoot)) {
    http_response_code(503);
    echo "vendor not configured";
    exit;
}
require_once $vendorRoot . '/_lib.php';
require_once $vendorRoot . '/_mail.php';
require_once $vendorRoot . '/_dpd.php';

$shipmentId = trim($_GET['shipment_id'] ?? '');
$format     = $_GET['format'] ?? 'A6';
if (!$shipmentId) {
    http_response_code(400);
    echo "missing shipment_id";
    exit;
}

$pdf = dpd_label_pdf($shipmentId, $format);
if (!$pdf) {
    http_response_code(500);
    echo "label generation failed";
    exit;
}

header('Content-Type: application/pdf');
header('Content-Disposition: inline; filename="dpd-' . $shipmentId . '.pdf"');
header('Content-Length: ' . strlen($pdf));
echo $pdf;
