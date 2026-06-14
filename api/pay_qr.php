<?php
/**
 * 🆕 v3.0.7 — QR pay-at-table (Customer payment landing API)
 *
 * Workflow:
 *   1. Číšník v adminu klikne "📲 QR platba" na objednávce → POST ?action=create_token
 *      Vrátí pay_url = https://restaurace.cz/pay/?t=<token>
 *   2. Číšník QR vytiskne / přiloží na účtenku / na stůl
 *   3. Host naskenuje QR → otevře /pay/?t=TOKEN
 *      Frontend (pay/index.php) zavolá GET ?action=info&t=TOKEN → vrátí amount, items, firmu
 *      Host vybere platbu:
 *        - Stripe Checkout → POST ?action=stripe_init → redirect na Stripe → po platbě webhook
 *        - GoPay → POST ?action=gopay_init → redirect na GoPay
 *        - Manuální (hotovost u číšníka) → POST ?action=mark_paid_manual → marks "pending_manual"
 *   4. Webhook (Stripe / GoPay) potvrdí platbu → objednavka.pay_status='paid' + paid_at
 *
 * Endpointy:
 *   POST ?action=create_token { objednavka_id }        — admin only, generuje token
 *   GET  ?action=info&t=TOKEN                          — public, info pro pay page
 *   POST ?action=stripe_init { token }                 — public, vrátí Stripe checkout URL
 *   POST ?action=gopay_init  { token }                 — public, vrátí GoPay redirect URL
 *   POST ?action=mark_paid_manual { token, method }    — public, host řekne "platím hotovostí"
 *   POST ?action=stripe_webhook                        — Stripe → mark paid
 *   POST ?action=gopay_callback                        — GoPay → mark paid
 *   GET  ?action=status&t=TOKEN                        — public, polling jestli zaplaceno
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/_customer_integrace.php';

cors_headers();
header('Content-Type: application/json; charset=UTF-8');

$method = $_SERVER['REQUEST_METHOD'];
$pdo = db();
$action = $_GET['action'] ?? '';

// ─────────────────────────────────────────────────────────────
// SCHEMA — auto-migrate (přidej pay_* sloupce do objednavky)
// ─────────────────────────────────────────────────────────────
function pay_qr_ensure_schema(PDO $pdo): void {
    static $done = false;
    if ($done) return;
    try {
        $cols = $pdo->query("SHOW COLUMNS FROM objednavky")->fetchAll(PDO::FETCH_COLUMN);
        if (!in_array('pay_token', $cols, true)) {
            $pdo->exec("ALTER TABLE objednavky ADD COLUMN pay_token VARCHAR(48) NULL UNIQUE");
        }
        if (!in_array('pay_status', $cols, true)) {
            $pdo->exec("ALTER TABLE objednavky ADD COLUMN pay_status ENUM('none','pending','pending_manual','paid','failed','expired','refunded') DEFAULT 'none'");
        }
        if (!in_array('pay_method', $cols, true)) {
            $pdo->exec("ALTER TABLE objednavky ADD COLUMN pay_method VARCHAR(40) NULL");
        }
        if (!in_array('paid_at', $cols, true)) {
            $pdo->exec("ALTER TABLE objednavky ADD COLUMN paid_at DATETIME NULL");
        }
        if (!in_array('pay_extra', $cols, true)) {
            $pdo->exec("ALTER TABLE objednavky ADD COLUMN pay_extra TEXT NULL");
        }
    } catch (Throwable $e) {}
    $done = true;
}

function pay_get_order_by_token(PDO $pdo, string $token): ?array {
    pay_qr_ensure_schema($pdo);
    $token = preg_replace('/[^a-f0-9]/i', '', $token);
    if (strlen($token) < 16) return null;
    $s = $pdo->prepare("SELECT * FROM objednavky WHERE pay_token = :t LIMIT 1");
    $s->execute(['t' => $token]);
    $row = $s->fetch();
    return $row ?: null;
}

function pay_set_status(PDO $pdo, int $orderId, string $status, ?string $method = null, ?string $extra = null): void {
    $sql = "UPDATE objednavky SET pay_status = :s";
    $params = ['s' => $status, 'id' => $orderId];
    if ($method !== null) { $sql .= ", pay_method = :m"; $params['m'] = $method; }
    if ($status === 'paid') { $sql .= ", paid_at = NOW(), stav = IF(stav IN ('rozpracovano','novy','zaplaceno_predem'), 'zaplaceno', stav)"; }
    if ($extra !== null) { $sql .= ", pay_extra = :e"; $params['e'] = $extra; }
    $sql .= " WHERE id = :id";
    $pdo->prepare($sql)->execute($params);
}

function pay_base_url(): string {
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    return $scheme . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost');
}

// ─────────────────────────────────────────────────────────────
// POST ?action=create_token — Admin only, vygeneruj pay_token
// ─────────────────────────────────────────────────────────────
if ($method === 'POST' && $action === 'create_token') {
    require_once __DIR__ . '/_admin_auth.php';
    require_admin();
    pay_qr_ensure_schema($pdo);

    $d = json_decode(file_get_contents('php://input'), true) ?? [];
    $id = (int)($d['objednavka_id'] ?? $d['id'] ?? 0);
    if (!$id) json_error('Chybí objednavka_id', 400);

    $s = $pdo->prepare("SELECT id, pay_token, pay_status FROM objednavky WHERE id = :id");
    $s->execute(['id' => $id]);
    $o = $s->fetch();
    if (!$o) json_error('Objednávka nenalezena', 404);
    if ($o['pay_status'] === 'paid') json_error('Objednávka je již zaplacena', 409);

    $token = $o['pay_token'] ?: bin2hex(random_bytes(16));
    if (!$o['pay_token']) {
        $pdo->prepare("UPDATE objednavky SET pay_token = :t, pay_status = 'pending' WHERE id = :id")
            ->execute(['t' => $token, 'id' => $id]);
    }
    $url = pay_base_url() . '/pay/?t=' . $token;
    json_response(['ok' => true, 'token' => $token, 'pay_url' => $url]);
}

// ─────────────────────────────────────────────────────────────
// GET ?action=info — Public, vrátí info o objednávce (pro pay page)
// ─────────────────────────────────────────────────────────────
if ($method === 'GET' && $action === 'info') {
    $token = (string)($_GET['t'] ?? '');
    $o = pay_get_order_by_token($pdo, $token);
    if (!$o) json_error('Neplatný nebo expirovaný token', 404);

    $p = $pdo->prepare("
        SELECT op.mnozstvi, op.cena_bez_dph, op.sazba_dph,
               COALESCE(op.vyrobek_nazev, v.nazev) AS nazev
        FROM objednavky_polozky op
        LEFT JOIN vyrobky v ON v.id = op.vyrobek_id
        WHERE op.objednavka_id = :id
    ");
    $p->execute(['id' => $o['id']]);
    $items = $p->fetchAll();

    // Firma info (název pro display)
    $firma = [];
    foreach (['firma_nazev', 'firma_logo_url'] as $k) {
        $stmt = $pdo->prepare("SELECT hodnota FROM nastaveni WHERE klic = :k");
        $stmt->execute(['k' => $k]);
        $firma[$k] = $stmt->fetchColumn() ?: null;
    }

    // Které metody jsou dostupné (podle integrace)
    $methods = [
        'manual' => true,
        'stripe' => customer_int_enabled('stripe'),
        'gopay'  => customer_int_enabled('gopay'),
    ];

    json_response([
        'ok' => true,
        'order' => [
            'cislo'    => $o['cislo'],
            'castka'   => (float)$o['castka_celkem'],
            'mena'     => 'CZK',
            'datum'    => $o['datum_objednani'],
            'pay_status' => $o['pay_status'],
            'paid_at'  => $o['paid_at'],
            'pay_method' => $o['pay_method'],
        ],
        'items' => $items,
        'firma' => $firma,
        'methods' => $methods,
    ]);
}

// ─────────────────────────────────────────────────────────────
// GET ?action=status — Public, polling jestli zaplaceno
// ─────────────────────────────────────────────────────────────
if ($method === 'GET' && $action === 'status') {
    $token = (string)($_GET['t'] ?? '');
    $o = pay_get_order_by_token($pdo, $token);
    if (!$o) json_error('Neplatný token', 404);
    json_response([
        'ok' => true,
        'pay_status' => $o['pay_status'],
        'paid_at' => $o['paid_at'],
        'pay_method' => $o['pay_method'],
    ]);
}

// ─────────────────────────────────────────────────────────────
// POST ?action=stripe_init — Public, vrátí Stripe Checkout URL
// ─────────────────────────────────────────────────────────────
if ($method === 'POST' && $action === 'stripe_init') {
    $d = json_decode(file_get_contents('php://input'), true) ?? [];
    $token = (string)($d['token'] ?? '');
    $o = pay_get_order_by_token($pdo, $token);
    if (!$o) json_error('Neplatný token', 404);
    if ($o['pay_status'] === 'paid') json_error('Již zaplaceno', 409);
    if (!customer_int_enabled('stripe')) json_error('Stripe není zapnutý', 503);

    $base = pay_base_url();
    $r = customer_int_stripe_create_checkout([
        'amount_kc'      => (float)$o['castka_celkem'],
        'currency'       => 'czk',
        'description'    => 'Účtenka ' . $o['cislo'],
        'reference'      => 'PAY-' . $o['id'] . '-' . $token,
        'return_url'     => $base . '/pay/?t=' . $token . '&paid=1',
        'cancel_url'     => $base . '/pay/?t=' . $token,
    ]);
    if (!($r['ok'] ?? false) || empty($r['url'] ?? $r['session']['url'] ?? null)) {
        json_error('Stripe init selhal: ' . ($r['error'] ?? 'neznámá chyba'), 500);
    }
    pay_set_status($pdo, (int)$o['id'], 'pending', 'stripe', json_encode(['stripe_session' => $r['id'] ?? null]));
    $url = $r['url'] ?? $r['session']['url'];
    json_response(['ok' => true, 'redirect' => $url]);
}

// ─────────────────────────────────────────────────────────────
// POST ?action=gopay_init — Public, vrátí GoPay redirect URL
// ─────────────────────────────────────────────────────────────
if ($method === 'POST' && $action === 'gopay_init') {
    $d = json_decode(file_get_contents('php://input'), true) ?? [];
    $token = (string)($d['token'] ?? '');
    $o = pay_get_order_by_token($pdo, $token);
    if (!$o) json_error('Neplatný token', 404);
    if ($o['pay_status'] === 'paid') json_error('Již zaplaceno', 409);
    if (!customer_int_enabled('gopay')) json_error('GoPay není zapnutý', 503);

    $base = pay_base_url();
    $r = customer_int_gopay_create_payment([
        'amount_kc'        => (float)$o['castka_celkem'],
        // 🐛 v3.0.323 — klíče musí sedět na customer_int_gopay_create_payment (čte 'reference'
        //   + 'notification_url'). Dřív 'order_no'/'notify_url' → notification_url PRÁZDNÁ →
        //   GoPay nikdy neposlal notifikaci → platba navždy 'pending'.
        'reference'        => 'PAY-' . $o['id'] . '-' . $token,
        'description'      => 'Účtenka ' . $o['cislo'],
        'return_url'       => $base . '/pay/?t=' . $token . '&paid=1',
        'notification_url' => $base . '/api/pay_qr.php?action=gopay_callback',
    ]);
    if (!($r['ok'] ?? false) || empty($r['gw_url'])) {
        json_error('GoPay init selhal: ' . ($r['error'] ?? 'neznámá chyba'), 500);
    }
    pay_set_status($pdo, (int)$o['id'], 'pending', 'gopay', json_encode(['gopay_id' => $r['id'] ?? null]));
    json_response(['ok' => true, 'redirect' => $r['gw_url']]);
}

// ─────────────────────────────────────────────────────────────
// POST ?action=mark_paid_manual — "platím hotovostí u číšníka"
// ─────────────────────────────────────────────────────────────
if ($method === 'POST' && $action === 'mark_paid_manual') {
    $d = json_decode(file_get_contents('php://input'), true) ?? [];
    $token = (string)($d['token'] ?? '');
    $methodLbl = (string)($d['method'] ?? 'cash');
    if (!in_array($methodLbl, ['cash', 'card_terminal', 'voucher'], true)) $methodLbl = 'cash';
    $o = pay_get_order_by_token($pdo, $token);
    if (!$o) json_error('Neplatný token', 404);
    if ($o['pay_status'] === 'paid') json_error('Již zaplaceno', 409);
    // Set pending_manual — číšník v adminu musí potvrdit, že obdržel
    pay_set_status($pdo, (int)$o['id'], 'pending_manual', $methodLbl);
    json_response(['ok' => true, 'message' => 'Číšník byl informován — přijde si pro platbu']);
}

// ─────────────────────────────────────────────────────────────
// POST ?action=admin_confirm_manual — admin potvrdí přijatou platbu
// ─────────────────────────────────────────────────────────────
if ($method === 'POST' && $action === 'admin_confirm_manual') {
    require_once __DIR__ . '/_admin_auth.php';
    require_admin();
    $d = json_decode(file_get_contents('php://input'), true) ?? [];
    $id = (int)($d['objednavka_id'] ?? 0);
    if (!$id) json_error('Chybí objednavka_id', 400);
    pay_qr_ensure_schema($pdo);
    pay_set_status($pdo, $id, 'paid', null);
    json_response(['ok' => true]);
}

// ─────────────────────────────────────────────────────────────
// POST ?action=stripe_webhook — Stripe → potvrdí platbu
// (Public — Stripe ho volá. Verifikace přes signature.)
// ─────────────────────────────────────────────────────────────
if ($method === 'POST' && $action === 'stripe_webhook') {
    // 🔒 v3.0.228 SECURITY: ověř Stripe-Signature (HMAC) proti tenant webhook secretu.
    //   secret nastaven → neplatný podpis = 400 · secret NEnastaven → grace (token v ref chrání)
    $payload = file_get_contents('php://input');
    $sig = $_SERVER['HTTP_STRIPE_SIGNATURE'] ?? '';
    $sigValid = customer_int_stripe_verify_signature($payload, $sig);
    if ($sigValid === false) {
        error_log('pay_qr stripe_webhook: NEPLATNÝ podpis from ' . ($_SERVER['REMOTE_ADDR'] ?? '?'));
        json_error('invalid signature', 400);
    }
    if ($sigValid === null) {
        error_log('⚠️ pay_qr stripe_webhook: webhook secret nenastaven — grace (ověřuji jen token v ref)');
    }
    $event = json_decode($payload, true);
    if (!$event) json_error('Bad payload', 400);
    if (($event['type'] ?? '') === 'checkout.session.completed') {
        $ref = $event['data']['object']['client_reference_id'] ?? '';
        // ref formát: PAY-<id>-<token>
        if (preg_match('/^PAY-(\d+)-([a-f0-9]{32})$/', (string)$ref, $m)) {
            $oid = (int)$m[1]; $token = $m[2];
            $o = pay_get_order_by_token($pdo, $token);
            if ($o && (int)$o['id'] === $oid) {
                pay_set_status($pdo, $oid, 'paid', 'stripe', json_encode(['session_id' => $event['data']['object']['id']]));
            }
        }
    }
    json_response(['ok' => true]);
}

// ─────────────────────────────────────────────────────────────
// GET/POST ?action=gopay_callback — GoPay async notifikace → ověř stav → potvrď platbu
//   (🆕 v3.0.323 — dřív handler chyběl → 400. GoPay pošle ?id=<payment_id>; stav OVĚŘÍME
//   dotazem na GoPay API, ne slepou důvěrou. order_number = PAY-<id>-<token> jako u Stripe.)
// ─────────────────────────────────────────────────────────────
if ($action === 'gopay_callback') {
    $gpId = (int)($_GET['id'] ?? $_POST['id'] ?? 0);
    if (!$gpId) json_error('Chybí id', 400);
    $pay = customer_int_gopay_get_payment($gpId);
    if (($pay['ok'] ?? false) && ($pay['state'] ?? '') === 'PAID') {
        $ref = (string)($pay['order_number'] ?? '');
        if (preg_match('/^PAY-(\d+)-([a-f0-9]{32})$/', $ref, $m)) {
            $oid = (int)$m[1]; $token = $m[2];
            $o = pay_get_order_by_token($pdo, $token);
            if ($o && (int)$o['id'] === $oid) {
                pay_set_status($pdo, $oid, 'paid', 'gopay', json_encode(['gopay_id' => $gpId]));
            }
        }
    }
    json_response(['ok' => true]); // GoPay očekává 200
}

json_error('Neznámá akce: ' . $action, 400);
