<?php
/**
 * 📦 ZÁSILKOVNA CREATE PACKET — endpoint pro customer admin.
 *
 * POST /api/zasilkovna_create_packet.php
 *   Body JSON: {
 *     order_no, pickup_point_id, recipient_name, recipient_email,
 *     recipient_phone, value_kc, weight_kg, cod_kc
 *   }
 *
 * Vrátí packet_id + barcode.
 */

header('Content-Type: application/json; charset=UTF-8');

// 🔒 v3.0.315 — vyžaduj admin session (dřív BEZ auth + CORS:* → kdokoli mohl vytvořit
//   placený packet/dobírku). admin/shipping.html volá same-origin s cookie; bez CSRF tokenu → SKIP_CSRF.
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
require_once $vendorRoot . '/_zasilkovna.php';

$d = json_decode(file_get_contents('php://input'), true);
if (!is_array($d)) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'invalid_json']);
    exit;
}

// Validace povinných polí
$required = ['order_no', 'pickup_point_id', 'recipient_name', 'recipient_email', 'value_kc'];
foreach ($required as $f) {
    if (empty($d[$f])) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => "missing_$f"]);
        exit;
    }
}

$result = packeta_create_packet([
    'order_no'         => $d['order_no'],
    'pickup_point_id'  => (int) $d['pickup_point_id'],
    'recipient_name'   => trim($d['recipient_name']),
    'recipient_email'  => trim($d['recipient_email']),
    'recipient_phone'  => trim($d['recipient_phone'] ?? ''),
    'value_kc'         => (float) $d['value_kc'],
    'weight_kg'        => (float) ($d['weight_kg'] ?? 1.0),
    'cod_kc'           => (float) ($d['cod_kc'] ?? 0),
]);

echo json_encode($result, JSON_UNESCAPED_UNICODE);
