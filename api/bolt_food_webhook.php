<?php
/**
 * 🟩 BOLT FOOD WEBHOOK RECEIVER
 *
 * Bolt Food POST eventy: order_received, order_cancelled, order_completed.
 * Signature: X-Bolt-Signature header (HMAC-SHA256).
 *
 * URL pro Bolt portal: https://APP_URL/api/bolt_food_webhook.php
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

$integration = da_get_integration('bolt');
if ($integration && !empty($integration['api_key'])) {
    $signature = $_SERVER['HTTP_X_BOLT_SIGNATURE'] ?? '';
    $secret = $integration['api_key'];
    try {
        $cfg = json_decode($integration['nastaveni'] ?? '{}', true);
        if (!empty($cfg['webhook_secret'])) $secret = $cfg['webhook_secret'];
    } catch (Throwable $e) {}

    if (!empty($signature) && !BoltFood_Client::verifySignature($rawBody, $signature, $secret)) {
        http_response_code(401);
        echo json_encode(['error' => 'Invalid signature']);
        da_log_webhook('bolt', 'rejected_signature', $payload);
        exit;
    }
}

$event = $payload['event'] ?? $payload['type'] ?? 'unknown';
$order = $payload['order'] ?? $payload;
$externalId = (string) ($order['id'] ?? '');
da_ensure_mapping_table();

try {
    switch ($event) {
        case 'order_received':
        case 'order.created':
        case 'received':
            $parsed = BoltFood_Client::parseInboundOrder($payload);
            $res = da_handle_inbound_order('bolt', $parsed);
            da_log_webhook('bolt', 'order_received', $payload, $res['objednavka_id'] ?? null);
            echo json_encode($res);
            break;

        case 'order_cancelled':
        case 'cancelled':
            db()->prepare("UPDATE objednavky SET stav='zrusena'
                WHERE id = (SELECT objednavka_id FROM delivery_external_orders WHERE sluzba='bolt' AND external_id=:e)")
                ->execute(['e' => $externalId]);
            db()->prepare("UPDATE delivery_external_orders SET stav='cancelled' WHERE sluzba='bolt' AND external_id=:e")
                ->execute(['e' => $externalId]);
            da_log_webhook('bolt', 'order_cancelled', $payload);
            echo json_encode(['ok' => true]);
            break;

        case 'order_completed':
        case 'delivered':
            db()->prepare("UPDATE objednavky SET stav='dorucena'
                WHERE id = (SELECT objednavka_id FROM delivery_external_orders WHERE sluzba='bolt' AND external_id=:e)")
                ->execute(['e' => $externalId]);
            db()->prepare("UPDATE delivery_external_orders SET stav='delivered' WHERE sluzba='bolt' AND external_id=:e")
                ->execute(['e' => $externalId]);
            da_log_webhook('bolt', 'order_completed', $payload);
            echo json_encode(['ok' => true]);
            break;

        default:
            da_log_webhook('bolt', "unknown:$event", $payload);
            echo json_encode(['ok' => true, 'message' => "Event $event logged but not handled"]);
    }
} catch (Throwable $e) {
    http_response_code(500);
    da_log_webhook('bolt', "error:$event", $payload + ['_error' => $e->getMessage()]);
    echo json_encode(['error' => 'Internal: ' . $e->getMessage()]);
}
