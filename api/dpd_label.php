<?php
/**
 * 🚚 DPD LABEL — stáhne PDF štítek.
 *
 * GET /api/dpd_label.php?shipment_id=12345&format=A6
 */

// 🔒 v3.0.376 SECURITY — auth required (dřív veřejné: kdokoli se shipment_id stáhl štítek
//   se jménem+adresou příjemce = GDPR únik). GET → stačí session cookie (CSRF jen POST/PUT/DELETE).
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/_admin_auth.php';
require_admin();

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
$format     = preg_replace('/[^A-Za-z0-9 ()._-]/', '', (string) ($_GET['format'] ?? 'A6')); // 🔒 v3.0.378 sanitace
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
