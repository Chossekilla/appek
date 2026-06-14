<?php
/**
 * 🚚 DPD CREATE SHIPMENT — endpoint pro customer admin.
 *
 * POST /api/dpd_create_shipment.php
 *   Body JSON: {
 *     order_no, recipient_name, recipient_street, recipient_city,
 *     recipient_zip, recipient_country, recipient_email, recipient_phone,
 *     weight_kg, value_kc, cod_kc
 *   }
 */

header('Content-Type: application/json; charset=UTF-8');

// 🔒 v3.0.315 — vyžaduj admin session (dřív BEZ auth + CORS:* → kdokoli mohl vytvořit
//   placenou zásilku/dobírku jménem zákazníka). admin/shipping.html volá same-origin
//   s session cookie; CSRF token ale neposílá → SKIP_CSRF (session-auth díru zavře).
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/_admin_auth.php';
if (!defined('SKIP_CSRF')) define('SKIP_CSRF', true);
require_admin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'method_not_allowed']);
    exit;
}

$vendorRoot = realpath(__DIR__ . '/..') . '/vendor';
if (!is_dir($vendorRoot)) {
    http_response_code(503);
    echo json_encode(['ok' => false, 'error' => 'vendor_not_configured']);
    exit;
}
require_once $vendorRoot . '/_lib.php';
require_once $vendorRoot . '/_mail.php';
require_once $vendorRoot . '/_dpd.php';

$d = json_decode(file_get_contents('php://input'), true);
if (!is_array($d)) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'invalid_json']);
    exit;
}

$required = ['order_no', 'recipient_name', 'recipient_street', 'recipient_city', 'recipient_zip', 'value_kc'];
foreach ($required as $f) {
    if (empty($d[$f])) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => "missing_$f"]);
        exit;
    }
}

$result = dpd_create_shipment([
    'order_no'           => $d['order_no'],
    'recipient_name'     => trim($d['recipient_name']),
    'recipient_street'   => trim($d['recipient_street']),
    'recipient_city'     => trim($d['recipient_city']),
    'recipient_zip'      => trim($d['recipient_zip']),
    'recipient_country'  => $d['recipient_country'] ?? 'CZ',
    'recipient_email'    => trim($d['recipient_email'] ?? ''),
    'recipient_phone'    => trim($d['recipient_phone'] ?? ''),
    'weight_kg'          => (float) ($d['weight_kg'] ?? 1.0),
    'value_kc'           => (float) $d['value_kc'],
    'cod_kc'             => (float) ($d['cod_kc'] ?? 0),
]);

echo json_encode($result, JSON_UNESCAPED_UNICODE);
