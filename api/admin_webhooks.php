<?php
/**
 * 🔄 WEBHOOKS — admin endpoint pro správu.
 *
 * GET  ?action=list           → seznam webhooks + stats
 * GET  ?action=events         → seznam podporovaných eventů
 * GET  ?action=log&id=N&limit=50 → log historie
 * POST                         → create new webhook
 * PUT  ?id=N                   → update
 * DELETE ?id=N                 → smazat
 * POST ?action=test&id=N       → test fire (ručně vystřelí 'test.event')
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/_admin_auth.php';
require_once __DIR__ . '/_webhooks.php';

cors_headers();
require_admin();
header('Content-Type: application/json; charset=UTF-8');

$pdo = db();
ensure_webhooks_schema($pdo);
$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? '';
$id     = (int) ($_GET['id'] ?? 0);

if ($action === 'events') {
    json_response(['events' => webhook_events_list()]);
}

if ($action === 'list' || ($method === 'GET' && !$action)) {
    $rows = $pdo->query("SELECT * FROM webhooks ORDER BY aktivni DESC, id DESC")->fetchAll();
    json_response(['webhooks' => $rows]);
}

if ($action === 'log') {
    $limit = max(10, min(200, (int) ($_GET['limit'] ?? 50)));
    $sql = "SELECT * FROM webhook_log";
    $params = [];
    if ($id > 0) { $sql .= " WHERE webhook_id = :wid"; $params['wid'] = $id; }
    $sql .= " ORDER BY id DESC LIMIT :l";
    $stmt = $pdo->prepare($sql);
    if ($id > 0) $stmt->bindValue(':wid', $id, PDO::PARAM_INT);
    $stmt->bindValue(':l', $limit, PDO::PARAM_INT);
    $stmt->execute();
    json_response(['log' => $stmt->fetchAll()]);
}

if ($action === 'test' && $method === 'POST') {
    require_super_admin();
    $w = $pdo->prepare("SELECT * FROM webhooks WHERE id = :id");
    $w->execute(['id' => $id]);
    $row = $w->fetch();
    if (!$row) json_error('Webhook neexistuje', 404);
    // Fire test event s tímhle konkrétním webhookem (i kdyby nebyl na list)
    webhook_fire('test.event', [
        'message' => 'Toto je testovací webhook z Appek B2B admina.',
        'webhook_id' => $id,
        'sent_at' => date('c'),
    ]);
    json_response(['ok' => true]);
}

if ($method === 'POST') {
    require_super_admin();
    $d = json_input();
    $nazev  = trim($d['nazev']  ?? '');
    $url    = trim($d['url']    ?? '');
    $events = trim($d['events'] ?? '');
    $secret = trim($d['secret'] ?? '');
    $aktivni= !empty($d['aktivni']) ? 1 : 0;
    if (!$nazev || !$url || !$events) json_error('Vyplň název, URL a alespoň jeden event', 400);
    if (!preg_match('#^https?://#', $url)) json_error('URL musí začínat http:// nebo https://', 400);
    try {
        $pdo->prepare("
            INSERT INTO webhooks (nazev, url, events, secret, aktivni)
            VALUES (:n, :u, :e, :s, :a)
        ")->execute(['n' => $nazev, 'u' => $url, 'e' => $events, 's' => $secret ?: null, 'a' => $aktivni]);
        json_response(['ok' => true, 'id' => (int) $pdo->lastInsertId()]);
    } catch (Throwable $e) { json_error('Chyba: ' . $e->getMessage(), 500); }
}

if ($method === 'PUT' && $id > 0) {
    require_super_admin();
    $d = json_input();
    $fields = ['nazev', 'url', 'events', 'secret', 'aktivni'];
    $sets = []; $params = ['id' => $id];
    foreach ($fields as $f) {
        if (array_key_exists($f, $d)) {
            $sets[] = "$f = :$f";
            $params[$f] = ($f === 'aktivni') ? (!empty($d[$f]) ? 1 : 0) : $d[$f];
        }
    }
    if (!$sets) json_error('Nic ke změně', 400);
    try {
        $pdo->prepare("UPDATE webhooks SET " . implode(', ', $sets) . " WHERE id = :id")->execute($params);
        json_response(['ok' => true]);
    } catch (Throwable $e) { json_error('Chyba: ' . $e->getMessage(), 500); }
}

if ($method === 'DELETE' && $id > 0) {
    require_super_admin();
    try {
        $pdo->prepare("DELETE FROM webhooks WHERE id = :id")->execute(['id' => $id]);
        $pdo->prepare("DELETE FROM webhook_log WHERE webhook_id = :id")->execute(['id' => $id]);
        json_response(['ok' => true]);
    } catch (Throwable $e) { json_error('Chyba: ' . $e->getMessage(), 500); }
}

json_error('Neznámá akce', 404);
