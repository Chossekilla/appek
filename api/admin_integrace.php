<?php
/**
 * 🔌 ADMIN INTEGRACE — sjednocený endpoint pro správu customer-side integrací.
 *
 * 4 služby: stripe, gopay, zas (Zásilkovna), dpd (DPD CZ).
 *
 * GET  ?action=settings&service=<key>     → načte settings (s zamaskovanými secrets)
 * POST ?action=save_settings&service=<key>→ uloží
 * GET  ?action=test&service=<key>         → test spojení
 * GET  ?action=all                        → seznam všech 4 služeb s status (enabled/disabled)
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/_admin_auth.php';
require_once __DIR__ . '/_customer_integrace.php';

cors_headers();
require_admin();
header('Content-Type: application/json; charset=UTF-8');

$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? '';
$service = $_GET['service'] ?? '';

if ($service && !in_array($service, CUSTOMER_INT_SERVICES, true)) {
    json_error('Neznámá služba: ' . $service, 400);
}

// ─── GET all (overview) ───
if ($method === 'GET' && $action === 'all') {
    $services = [];
    foreach (CUSTOMER_INT_SERVICES as $svc) {
        $cfg = customer_int_settings($svc);
        $services[] = [
            'key' => $svc,
            'enabled' => customer_int_enabled($svc),
            'has_credentials' => !empty($cfg),
        ];
    }
    json_response(['services' => $services]);
}

// ─── GET settings ───
if ($method === 'GET' && $action === 'settings' && $service) {
    json_response(customer_int_settings_safe($service));
}

// ─── POST save_settings ───
if ($method === 'POST' && $action === 'save_settings' && $service) {
    require_super_admin();
    $d = json_input();
    // Odstraň plné klíče int_<svc>_ pokud existují (přepošli jen short keys)
    $clean = [];
    foreach ($d as $k => $v) {
        $shortKey = preg_replace('/^int_' . preg_quote($service, '/') . '_/', '', $k);
        $clean[$shortKey] = $v;
    }

    // 🆕 v2.9.203 — validace klíčů per service
    if ($service === 'stripe') {
        $sk = trim($clean['secret_key'] ?? '');
        if ($sk !== '' && strpos($sk, 'pk_') === 0) {
            json_error("Tohle je PUBLISHABLE klíč (pk_...). Pro Secret Key použij sk_test_ nebo sk_live_ ze Stripe Dashboardu → API keys.", 400);
        }
        if ($sk !== '' && strpos($sk, 'sk_') !== 0) {
            json_error("Stripe Secret Key musí začínat 'sk_test_' nebo 'sk_live_'.", 400);
        }
        $wh = trim($clean['webhook_secret'] ?? '');
        if ($wh !== '' && strpos($wh, 'whsec_') !== 0) {
            json_error("Stripe Webhook Secret musí začínat 'whsec_'.", 400);
        }
    } elseif ($service === 'gopay') {
        $goid = trim($clean['goid'] ?? '');
        $cid  = trim($clean['client_id'] ?? '');
        if ($goid !== '' && !preg_match('/^\d{7,12}$/', $goid)) {
            json_error("GoPay GoID musí být 7–12 číslic (najdeš v GoPay účtu → Nastavení).", 400);
        }
        if ($cid !== '' && !preg_match('/^\d{7,12}$/', $cid)) {
            json_error("GoPay Client ID musí být 7–12 číslic (z API klíčů v GoPay obchodním účtu).", 400);
        }
    } elseif ($service === 'paypal') {
        $cid = trim($clean['client_id'] ?? '');
        $env = trim($clean['environment'] ?? 'sandbox');
        if ($env !== '' && !in_array($env, ['sandbox', 'live'], true)) {
            json_error("PayPal environment musí být 'sandbox' nebo 'live'.", 400);
        }
        if ($cid !== '' && strlen($cid) < 50) {
            json_error("PayPal Client ID je obvykle ~80 znaků (z developer.paypal.com → My Apps & Credentials).", 400);
        }
    }

    customer_int_set($service, $clean);
    json_response(['ok' => true]);
}

// ─── GET test ───
if ($method === 'GET' && $action === 'test' && $service) {
    $fn = 'customer_int_' . $service . '_test';
    if (function_exists($fn)) {
        json_response($fn());
    }
    json_error('Test funkce neexistuje pro ' . $service, 500);
}

json_error('Neznámá akce', 404);
