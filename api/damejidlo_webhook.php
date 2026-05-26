<?php
/**
 * 🟧 DÁME JÍDLO WEBHOOK RECEIVER
 *
 * Dáme jídlo POST eventy do našeho endpointu:
 *   - new_order             → vytvoř objednavku
 *   - order_cancelled       → změň stav
 *   - order_delivered       → změň stav
 *
 * Signature: X-DameJidlo-Signature header (HMAC-SHA256 přes raw body).
 *
 * URL pro restaurátorský portál Dáme jídlo: https://APP_URL/api/damejidlo_webhook.php
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

$integration = da_get_integration('dame_jidlo');
if ($integration && !empty($integration['api_key'])) {
    $signature = $_SERVER['HTTP_X_DAMEJIDLO_SIGNATURE'] ?? '';
    $secret = $integration['api_key'];
    try {
        $cfg = json_decode($integration['nastaveni'] ?? '{}', true);
        if (!empty($cfg['webhook_secret'])) $secret = $cfg['webhook_secret'];
    } catch (Throwable $e) {}

    if (!empty($signature) && !DameJidlo_Client::verifySignature($rawBody, $signature, $secret)) {
        http_response_code(401);
        echo json_encode(['error' => 'Invalid signature']);
        da_log_webhook('dame_jidlo', 'rejected_signature', $payload);
        exit;
    }
}

$event = $payload['event'] ?? $payload['type'] ?? 'unknown';
$order = $payload['order'] ?? $payload;
$externalId = (string) ($order['id'] ?? '');
da_ensure_mapping_table();

try {
    switch ($event) {
        case 'new_order':
        case 'order.created':
            $parsed = DameJidlo_Client::parseInboundOrder($payload);
            $res = da_handle_inbound_order('dame_jidlo', $parsed);
            da_log_webhook('dame_jidlo', 'new_order', $payload, $res['objednavka_id'] ?? null);
            echo json_encode($res);
            break;

        case 'order_cancelled':
        case 'cancelled':
            db()->prepare("UPDATE objednavky SET stav='zrusena'
                WHERE id = (SELECT objednavka_id FROM delivery_external_orders WHERE sluzba='dame_jidlo' AND external_id=:e)")
                ->execute(['e' => $externalId]);
            db()->prepare("UPDATE delivery_external_orders SET stav='cancelled' WHERE sluzba='dame_jidlo' AND external_id=:e")
                ->execute(['e' => $externalId]);
            da_log_webhook('dame_jidlo', 'order_cancelled', $payload);
            echo json_encode(['ok' => true]);
            break;

        case 'order_delivered':
        case 'delivered':
            db()->prepare("UPDATE objednavky SET stav='dorucena'
                WHERE id = (SELECT objednavka_id FROM delivery_external_orders WHERE sluzba='dame_jidlo' AND external_id=:e)")
                ->execute(['e' => $externalId]);
            db()->prepare("UPDATE delivery_external_orders SET stav='delivered' WHERE sluzba='dame_jidlo' AND external_id=:e")
                ->execute(['e' => $externalId]);
            da_log_webhook('dame_jidlo', 'order_delivered', $payload);
            echo json_encode(['ok' => true]);
            break;

        default:
            da_log_webhook('dame_jidlo', "unknown:$event", $payload);
            echo json_encode(['ok' => true, 'message' => "Event $event logged but not handled"]);
    }
} catch (Throwable $e) {
    http_response_code(500);
    da_log_webhook('dame_jidlo', "error:$event", $payload + ['_error' => $e->getMessage()]);
    echo json_encode(['error' => 'Internal: ' . $e->getMessage()]);
}
