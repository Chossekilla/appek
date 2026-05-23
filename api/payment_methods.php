<?php
/**
 * 💳 PAYMENT METHODS — centrální správa povolených platebních metod.
 *
 * Settings key: `payment_methods_json` v tabulce `nastaveni`.
 * Hodnota: JSON map { method_key: bool, ... }
 *
 * GET  /api/payment_methods.php           → seznam všech metod s labelem + enabled flag
 * GET  /api/payment_methods.php?context=pos   → jen zapnuté + kompatibilní s POS (fyzické)
 * GET  /api/payment_methods.php?context=b2b   → jen zapnuté + kompatibilní s B2B (online + převod)
 * PUT  /api/payment_methods.php           → admin uloží JSON
 *   Body: { methods: { hotove: true, karta: true, stripe: false, ... } }
 *
 * Kategorie metod (pro filter podle kontextu):
 *   - 'physical'  = fyzická platba (POS): hotove, karta (terminál), qr_platba
 *   - 'online'    = online brána (B2B, vendor): stripe, gopay
 *   - 'deferred'  = odložená platba (B2B): prevod, dobirka, faktura
 *   - 'other'     = jiné (POS více volby): paypal, gift_card, voucher, mobile
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/_admin_auth.php';
cors_headers();

header('Content-Type: application/json; charset=UTF-8');

// Master seznam metod — single source of truth.
// Pokud admin přidá custom metodu v UI, doplň ji sem.
const PAYMENT_METHODS_MASTER = [
    'hotove'    => ['label' => '💵 Hotově',          'cat' => 'physical',  'default' => true,  'pos' => true, 'b2b' => false],
    'karta'     => ['label' => '💳 Karta (terminál)', 'cat' => 'physical', 'default' => true,  'pos' => true, 'b2b' => false],
    'qr_platba' => ['label' => '📱 QR platba',        'cat' => 'physical', 'default' => false, 'pos' => true, 'b2b' => false],
    'stripe'    => ['label' => '💳 Karta online (Stripe)', 'cat' => 'online', 'default' => false, 'pos' => false, 'b2b' => true],
    'gopay'     => ['label' => '💳 GoPay',            'cat' => 'online',   'default' => false, 'pos' => false, 'b2b' => true],
    'prevod'    => ['label' => '🏦 Bankovní převod',  'cat' => 'deferred', 'default' => true,  'pos' => false, 'b2b' => true],
    'dobirka'   => ['label' => '📦 Dobírka',          'cat' => 'deferred', 'default' => false, 'pos' => false, 'b2b' => true],
    'faktura'   => ['label' => '📄 Faktura + převod', 'cat' => 'deferred', 'default' => true,  'pos' => false, 'b2b' => true],
    'paypal'    => ['label' => '💼 PayPal',           'cat' => 'other',    'default' => false, 'pos' => true, 'b2b' => false],
    'gift_card' => ['label' => '🎁 Dárková karta',    'cat' => 'other',    'default' => false, 'pos' => true, 'b2b' => false],
    'voucher'   => ['label' => '🎟️ Voucher',          'cat' => 'other',    'default' => false, 'pos' => true, 'b2b' => false],
    'mobile'    => ['label' => '📱 Mobile Payment',   'cat' => 'other',    'default' => false, 'pos' => true, 'b2b' => false],
];

$pdo = db();

function payment_methods_load(PDO $pdo): array {
    try {
        $stmt = $pdo->prepare("SELECT hodnota FROM nastaveni WHERE klic = 'payment_methods_json' LIMIT 1");
        $stmt->execute();
        $json = $stmt->fetchColumn();
        $saved = $json ? json_decode($json, true) : [];
        if (!is_array($saved)) $saved = [];
    } catch (Throwable $e) { $saved = []; }

    // Sloučit master + saved overrides
    $out = [];
    foreach (PAYMENT_METHODS_MASTER as $key => $meta) {
        $out[$key] = array_merge($meta, [
            'key'     => $key,
            'enabled' => array_key_exists($key, $saved) ? (bool) $saved[$key] : (bool) $meta['default'],
        ]);
    }
    return $out;
}

function payment_methods_save(PDO $pdo, array $methods): void {
    $clean = [];
    foreach ($methods as $key => $enabled) {
        if (isset(PAYMENT_METHODS_MASTER[$key])) {
            $clean[$key] = (bool) $enabled;
        }
    }
    $json = json_encode($clean, JSON_UNESCAPED_UNICODE);
    $stmt = $pdo->prepare("
        INSERT INTO nastaveni (klic, hodnota) VALUES ('payment_methods_json', :v)
        ON DUPLICATE KEY UPDATE hodnota = :v2
    ");
    $stmt->execute(['v' => $json, 'v2' => $json]);
}

$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'GET') {
    // Public read OK — žádné secrets v metadatech
    $all = payment_methods_load($pdo);
    $context = $_GET['context'] ?? '';

    if ($context === 'pos') {
        $filtered = array_filter($all, fn($m) => $m['enabled'] && $m['pos']);
        json_response(['methods' => array_values($filtered)]);
    }
    if ($context === 'b2b') {
        $filtered = array_filter($all, fn($m) => $m['enabled'] && $m['b2b']);
        json_response(['methods' => array_values($filtered)]);
    }
    // No context → all (pro admin UI)
    json_response(['methods' => array_values($all)]);
}

if ($method === 'PUT' || $method === 'POST') {
    require_admin();  // jen admin smí měnit
    $d = json_input();
    $methods = $d['methods'] ?? [];
    if (!is_array($methods)) json_error('Pole "methods" musí být objekt {key: bool}', 400);
    payment_methods_save($pdo, $methods);
    json_response(['ok' => true, 'methods' => array_values(payment_methods_load($pdo))]);
}

json_error('Method not allowed', 405);
