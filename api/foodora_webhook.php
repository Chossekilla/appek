<?php
/**
 * 🩷 FOODORA WEBHOOK RECEIVER (Delivery Hero CEEMEA)
 *
 * Foodora POST eventy: order_received, order_cancelled, order_delivered, order_picked_up.
 * Signature: X-DH-Signature header.
 *
 * URL pro vendor.delivery-hero.com portal: https://APP_URL/api/foodora_webhook.php
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

$integration = da_get_integration('foodora');
if ($integration && !empty($integration['api_key'])) {
    $signature = $_SERVER['HTTP_X_DH_SIGNATURE'] ?? '';
    $secret = $integration['api_key'];
    try {
        $cfg = json_decode($integration['nastaveni'] ?? '{}', true);
        if (!empty($cfg['webhook_secret'])) $secret = $cfg['webhook_secret'];
    } catch (Throwable $e) {}

    if (!empty($signature) && !Foodora_Client::verifySignature($rawBody, $signature, $secret)) {
        http_response_code(401);
        echo json_encode(['error' => 'Invalid signature']);
        da_log_webhook('foodora', 'rejected_signature', $payload);
        exit;
    }
}

$event = $payload['event'] ?? $payload['type'] ?? 'unknown';
$order = $payload['order'] ?? $payload;
$externalId = (string) ($order['code'] ?? $order['id'] ?? '');
da_ensure_mapping_table();

try {
    switch ($event) {
        case 'order_received':
        case 'order.created':
        case 'received':
            $parsed = Foodora_Client::parseInboundOrder($payload);
            $res = da_handle_inbound_order('foodora', $parsed);
            da_log_webhook('foodora', 'order_received', $payload, $res['objednavka_id'] ?? null);
            echo json_encode($res);
            break;

        case 'order_cancelled':
        case 'cancelled':
            db()->prepare("UPDATE objednavky SET stav='zrusena'
                WHERE id = (SELECT objednavka_id FROM delivery_external_orders WHERE sluzba='foodora' AND external_id=:e)")
                ->execute(['e' => $externalId]);
            db()->prepare("UPDATE delivery_external_orders SET stav='cancelled' WHERE sluzba='foodora' AND external_id=:e")
                ->execute(['e' => $externalId]);
            da_log_webhook('foodora', 'order_cancelled', $payload);
            echo json_encode(['ok' => true]);
            break;

        case 'order_picked_up':
        case 'picked_up':
            db()->prepare("UPDATE objednavky SET stav='expedovana'
                WHERE id = (SELECT objednavka_id FROM delivery_external_orders WHERE sluzba='foodora' AND external_id=:e)")
                ->execute(['e' => $externalId]);
            db()->prepare("UPDATE delivery_external_orders SET stav='picked_up' WHERE sluzba='foodora' AND external_id=:e")
                ->execute(['e' => $externalId]);
            da_log_webhook('foodora', 'order_picked_up', $payload);
            echo json_encode(['ok' => true]);
            break;

        case 'order_delivered':
        case 'delivered':
            db()->prepare("UPDATE objednavky SET stav='dorucena'
                WHERE id = (SELECT objednavka_id FROM delivery_external_orders WHERE sluzba='foodora' AND external_id=:e)")
                ->execute(['e' => $externalId]);
            db()->prepare("UPDATE delivery_external_orders SET stav='delivered' WHERE sluzba='foodora' AND external_id=:e")
                ->execute(['e' => $externalId]);
            da_log_webhook('foodora', 'order_delivered', $payload);
            echo json_encode(['ok' => true]);
            break;

        default:
            da_log_webhook('foodora', "unknown:$event", $payload);
            echo json_encode(['ok' => true, 'message' => "Event $event logged but not handled"]);
    }
} catch (Throwable $e) {
    http_response_code(500);
    da_log_webhook('foodora', "error:$event", $payload + ['_error' => $e->getMessage()]);
    echo json_encode(['error' => 'Internal: ' . $e->getMessage()]);
}
