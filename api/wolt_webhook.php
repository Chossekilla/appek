<?php
/**
 * 🟢 WOLT WEBHOOK RECEIVER
 *
 * Wolt POST sem jednorázové eventy:
 *   - order.created        → vytvoř objednavku
 *   - order.cancelled      → změň stav na zruseno
 *   - order.delivered      → změň stav na doruceno
 *
 * Signature ověření přes X-Wolt-Signature (HMAC-SHA256 přes raw body).
 * Secret = api_key uložený v courier_integrations (Wolt rule: webhook secret = stejný jako API key, nebo separátní pole v portalu).
 *
 * URL nastavená v Wolt Merchant portal → Settings → Webhooks: https://APP_URL/api/wolt_webhook.php
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/_delivery_aggregators.php';

cors_headers();
header('Content-Type: application/json; charset=UTF-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'POST only']);
    exit;
}

$rawBody = file_get_contents('php://input');
$payload = json_decode($rawBody, true);
if (!is_array($payload)) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid JSON']);
    exit;
}

// 🔐 v3.0.44 fix — Webhook signature MUSÍ být přítomný + valid pokud máme secret.
// (Dříve bypass pokud header chyběl → security hole. Útočník mohl pomocí spoof
//  payloadu vytvořit fake objednávky.)
$integration = da_get_integration('wolt');
if ($integration && !empty($integration['api_key'])) {
    $signature = $_SERVER['HTTP_X_WOLT_SIGNATURE'] ?? '';
    $secret = $integration['api_key'];
    try {
        $cfg = json_decode($integration['nastaveni'] ?? '{}', true);
        if (!empty($cfg['webhook_secret'])) $secret = $cfg['webhook_secret'];
    } catch (Throwable $e) {}

    if (empty($signature) || !Wolt_Client::verifySignature($rawBody, $signature, $secret)) {
        http_response_code(401);
        echo json_encode(['error' => 'Missing or invalid X-Wolt-Signature']);
        da_log_webhook('wolt', empty($signature) ? 'rejected_no_signature' : 'rejected_bad_signature', $payload);
        exit;
    }
}

$event = $payload['event_type'] ?? $payload['type'] ?? 'unknown';
$order = $payload['order'] ?? $payload;
$externalId = $order['id'] ?? '';
da_ensure_mapping_table();

try {
    switch ($event) {
        case 'order.created':
        case 'order':
        case 'CREATED':
            $parsed = Wolt_Client::parseInboundOrder($payload);
            $res = da_handle_inbound_order('wolt', $parsed);
            da_log_webhook('wolt', 'order.created', $payload, $res['objednavka_id'] ?? null);
            echo json_encode($res);
            break;

        case 'order.cancelled':
        case 'CANCELLED':
            db()->prepare("UPDATE objednavky SET stav='zrusena'
                WHERE id = (SELECT objednavka_id FROM delivery_external_orders WHERE sluzba='wolt' AND external_id=:e)")
                ->execute(['e' => $externalId]);
            db()->prepare("UPDATE delivery_external_orders SET stav='cancelled' WHERE sluzba='wolt' AND external_id=:e")
                ->execute(['e' => $externalId]);
            da_log_webhook('wolt', 'order.cancelled', $payload);
            echo json_encode(['ok' => true]);
            break;

        case 'order.delivered':
        case 'DELIVERED':
            db()->prepare("UPDATE objednavky SET stav='dorucena'
                WHERE id = (SELECT objednavka_id FROM delivery_external_orders WHERE sluzba='wolt' AND external_id=:e)")
                ->execute(['e' => $externalId]);
            db()->prepare("UPDATE delivery_external_orders SET stav='delivered' WHERE sluzba='wolt' AND external_id=:e")
                ->execute(['e' => $externalId]);
            da_log_webhook('wolt', 'order.delivered', $payload);
            echo json_encode(['ok' => true]);
            break;

        default:
            da_log_webhook('wolt', "unknown:$event", $payload);
            echo json_encode(['ok' => true, 'message' => "Event $event logged but not handled"]);
    }
} catch (Throwable $e) {
    http_response_code(500);
    da_log_webhook('wolt', "error:$event", $payload + ['_error' => $e->getMessage()]);
    echo json_encode(['error' => 'Internal: ' . $e->getMessage()]);
}
