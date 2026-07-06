<?php
/**
 * Push notifikace — veřejné endpointy pro B2B i admin.
 *
 * GET  ?action=vapid_public      → vrátí veřejný VAPID klíč (potřeba pro PushManager.subscribe)
 * POST ?action=subscribe         → uloží subscription (z B2B i admin)
 * POST ?action=unsubscribe       → smaže subscription
 * POST ?action=test (admin)      → pošle testovací notifikaci
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/_push_lib.php';
cors_headers();

$pdo = db();
$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? '';

ensure_push_tables($pdo);

// Public — veřejný VAPID klíč
if ($action === 'vapid_public' && $method === 'GET') {
    try {
        $v = vapid_keys_get_or_create($pdo);
        json_response(['public_key' => $v['public']]);
    } catch (Throwable $e) {
        json_error('Push notifikace nepodporovány: ' . $e->getMessage(), 500);
    }
}

// Subscribe — uloží browser endpoint do DB
// Vyžaduje login (B2B nebo admin)
if ($action === 'subscribe' && $method === 'POST') {
    $d = json_input();
    if (empty($d['endpoint']) || empty($d['keys']['p256dh']) || empty($d['keys']['auth'])) {
        json_error('Chybí endpoint nebo klíče');
    }

    // Zjistíme kdo — odběratel nebo admin
    // 🐛 v3.0.411 — session_secure_start (APPEKSID), NE bare session_start (PHPSESSID) →
    //   jinak admin i odběratel čtou prázdnou default session → subscribe vždy 401
    //   (push notifikace se po oživení v403 nedaly ani zapnout).
    if (function_exists('session_secure_start')) session_secure_start(); else session_start();
    $odb_id = $_SESSION['odberatel_id'] ?? null;
    $admin_id = $_SESSION['admin_id'] ?? null;
    if (!$odb_id && !$admin_id) json_error('Nepřihlášený', 401);

    $endpoint = $d['endpoint'];
    $endpoint_hash = hash('sha256', $endpoint);
    $ua = substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 255);

    // UPSERT — pokud endpoint už existuje, aktualizuj klíče
    $pdo->prepare("
        INSERT INTO push_subscriptions
            (odberatel_id, admin_id, endpoint, endpoint_hash, p256dh, auth, user_agent)
        VALUES (:odb, :adm, :e, :eh, :p, :a, :ua)
        ON DUPLICATE KEY UPDATE
            odberatel_id = COALESCE(VALUES(odberatel_id), odberatel_id),
            admin_id     = COALESCE(VALUES(admin_id), admin_id),
            p256dh       = VALUES(p256dh),
            auth         = VALUES(auth),
            user_agent   = VALUES(user_agent),
            chyba_count  = 0,
            posledni_chyba = NULL
    ")->execute([
        'odb' => $odb_id,
        'adm' => $admin_id,
        'e'   => $endpoint,
        'eh'  => $endpoint_hash,
        'p'   => $d['keys']['p256dh'],
        'a'   => $d['keys']['auth'],
        'ua'  => $ua,
    ]);
    json_response(['ok' => true]);
}

// Unsubscribe
if ($action === 'unsubscribe' && $method === 'POST') {
    $d = json_input();
    if (empty($d['endpoint'])) json_error('Chybí endpoint');
    $endpoint_hash = hash('sha256', $d['endpoint']);
    $pdo->prepare("DELETE FROM push_subscriptions WHERE endpoint_hash = :eh")
        ->execute(['eh' => $endpoint_hash]);
    json_response(['ok' => true]);
}

// Admin: pošli testovací push
if ($action === 'test' && $method === 'POST') {
    require_once __DIR__ . '/_admin_auth.php';
    require_admin();

    $d = json_input();
    $targetEndpoint = $d['endpoint'] ?? null;   // konkrétní subscription
    $targetAll = !empty($d['all']);              // všem subscriberům

    $sql = "SELECT * FROM push_subscriptions WHERE 1=1";
    $params = [];
    if ($targetEndpoint && !$targetAll) {
        $sql .= " AND endpoint_hash = :eh";
        $params['eh'] = hash('sha256', $targetEndpoint);
    }
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $subs = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if (empty($subs)) json_error('Žádné subscriptions', 404);

    $payload = [
        'title' => $d['title'] ?? '🧪 Testovací notifikace',
        'body'  => $d['body']  ?? 'Funguje to! Toto je test push notifikace z admin systému.',
        'url'   => $d['url']   ?? '/',
        'icon'  => $d['icon']  ?? '/uploads/logo/favicon.png',
    ];

    $stats = ['sent' => 0, 'failed' => 0, 'expired' => 0];
    foreach ($subs as $sub) {
        $r = push_send($pdo, $sub, $payload);
        if ($r['ok']) {
            $stats['sent']++;
            $pdo->prepare("UPDATE push_subscriptions SET posledni_push = NOW(), chyba_count = 0 WHERE id = :id")
                ->execute(['id' => $sub['id']]);
            $pdo->prepare("INSERT INTO push_log (subscription_id, title, body, typ, stav) VALUES (:s, :t, :b, 'test', 'sent')")
                ->execute(['s' => $sub['id'], 't' => $payload['title'], 'b' => $payload['body']]);
        } else {
            // 404/410 = endpoint expired → smaž
            if ($r['http_code'] === 404 || $r['http_code'] === 410) {
                $pdo->prepare("DELETE FROM push_subscriptions WHERE id = :id")
                    ->execute(['id' => $sub['id']]);
                $stats['expired']++;
            } else {
                $stats['failed']++;
                $pdo->prepare("UPDATE push_subscriptions SET chyba_count = chyba_count + 1, posledni_chyba = :c WHERE id = :id")
                    ->execute(['c' => substr($r['chyba'] ?? '', 0, 1000), 'id' => $sub['id']]);
            }
            $pdo->prepare("INSERT INTO push_log (subscription_id, title, body, typ, stav, chyba) VALUES (:s, :t, :b, 'test', 'failed', :e)")
                ->execute(['s' => $sub['id'], 't' => $payload['title'], 'b' => $payload['body'], 'e' => $r['chyba'] ?? '']);
        }
    }
    json_response(['ok' => true, 'stats' => $stats]);
}

// Admin: statistika subscriberů
if ($action === 'stats' && $method === 'GET') {
    require_once __DIR__ . '/_admin_auth.php';
    require_admin();

    $total = (int) $pdo->query("SELECT COUNT(*) FROM push_subscriptions")->fetchColumn();
    $odb_sub = (int) $pdo->query("SELECT COUNT(DISTINCT odberatel_id) FROM push_subscriptions WHERE odberatel_id IS NOT NULL")->fetchColumn();
    $adm_sub = (int) $pdo->query("SELECT COUNT(DISTINCT admin_id) FROM push_subscriptions WHERE admin_id IS NOT NULL")->fetchColumn();
    $tento_mesic = (int) $pdo->query("SELECT COUNT(*) FROM push_log WHERE odeslano >= DATE_FORMAT(NOW(), '%Y-%m-01')")->fetchColumn();

    $list = $pdo->query("
        SELECT ps.id, ps.odberatel_id, ps.admin_id, ps.user_agent, ps.vytvoreno, ps.posledni_push, ps.chyba_count,
               o.nazev AS odberatel_nazev,
               a.email AS admin_email
        FROM push_subscriptions ps
        LEFT JOIN odberatele o ON o.id = ps.odberatel_id
        LEFT JOIN admin_users a ON a.id = ps.admin_id
        ORDER BY ps.posledni_push DESC, ps.vytvoreno DESC
        LIMIT 100
    ")->fetchAll(PDO::FETCH_ASSOC);

    json_response([
        'total' => $total,
        'odberatele_sub'  => $odb_sub,
        'admins_sub'      => $adm_sub,
        'tento_mesic'     => $tento_mesic,
        'subscriptions'   => $list,
    ]);
}

json_error('Neznámá akce', 404);
