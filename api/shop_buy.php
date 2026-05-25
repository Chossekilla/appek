<?php
/**
 * 🛒 SHOP BUY — veřejný endpoint pro objednávky z appek.cz/sales/.
 *
 * POST /api/shop_buy.php
 *   Body: JSON {
 *     customer_name, customer_company, customer_email, customer_phone,
 *     customer_country, customer_ico, customer_dic, customer_address,
 *     tier, packages: [...], install_url, notes, locale
 *   }
 *
 * Zapisuje do `vendor_shop_orders` (sdílí vendor DB).
 * Po vytvoření vrací JSON s order_no + payment instructions.
 *
 * Tento endpoint MUSÍ být dostupný i pokud vendor je na samostatné subdoméně.
 * Předpoklad: appek.cz/api/ má přístup k vendor DB credentialům přes
 * `api/vendor_db_config.local.php` (volitelný soubor) nebo přes
 * `vendor/config.local.php` pokud je dostupný (stejný server).
 */

header('Content-Type: application/json; charset=UTF-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') exit;

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'method_not_allowed']);
    exit;
}

// ── Načti config pro vendor DB ──────────────────────────────────
$vendorConfigPaths = [
    __DIR__ . '/vendor_db_config.local.php',          // dedikovaný config pro api → vendor
    realpath(__DIR__ . '/..') . '/vendor/config.local.php',  // sdílený, pokud vendor je na stejném serveru
];
$vendorConfigLoaded = false;
foreach ($vendorConfigPaths as $cfg) {
    if ($cfg && file_exists($cfg)) {
        require_once $cfg;
        $vendorConfigLoaded = true;
        break;
    }
}

if (!$vendorConfigLoaded || !defined('VENDOR_DB_HOST')) {
    http_response_code(503);
    echo json_encode(['ok' => false, 'error' => 'vendor_db_not_configured']);
    exit;
}

// ── Načti vstup ─────────────────────────────────────────────────
$raw = file_get_contents('php://input');
$d = json_decode($raw, true);
if (!is_array($d)) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'invalid_json']);
    exit;
}

// Validace povinných polí
$name  = trim($d['customer_name'] ?? '');
$email = trim($d['customer_email'] ?? '');
$tier  = trim($d['tier'] ?? '');
$total = (float) ($d['total_kc'] ?? 0);

if ($name === '' || !filter_var($email, FILTER_VALIDATE_EMAIL) || $tier === '' || $total <= 0) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'missing_fields']);
    exit;
}

try {
    $dsn = sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
        VENDOR_DB_HOST, defined('VENDOR_DB_PORT') ? VENDOR_DB_PORT : 3306, VENDOR_DB_NAME);
    $pdo = new PDO($dsn, VENDOR_DB_USER, VENDOR_DB_PASS, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    ]);

    // Rate limit — max 5 objednávek z jedné IP za 10 minut
    $ip = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
    $ip = trim(explode(',', $ip)[0]);
    $check = $pdo->prepare("SELECT COUNT(*) FROM vendor_shop_orders WHERE ip = :ip AND created_at > DATE_SUB(NOW(), INTERVAL 10 MINUTE)");
    $check->execute(['ip' => $ip]);
    if ((int) $check->fetchColumn() >= 5) {
        http_response_code(429);
        echo json_encode(['ok' => false, 'error' => 'rate_limited']);
        exit;
    }

    // Generuj order_no — formát APPEK-ORD-YYYYMMDD-XXXX
    $orderNo = 'APPEK-ORD-' . date('Ymd') . '-' . strtoupper(bin2hex(random_bytes(2)));

    $packages = is_array($d['packages'] ?? null) ? $d['packages'] : [];
    $packagesJson = json_encode(array_values($packages));

    $stmt = $pdo->prepare("
        INSERT INTO vendor_shop_orders
          (order_no, customer_name, customer_company, customer_email, customer_phone,
           customer_country, customer_ico, customer_dic, customer_address,
           tier, packages_json, install_url, notes, total_kc, currency,
           payment_method, payment_status, locale, ip, user_agent)
        VALUES
          (:no, :n, :c, :e, :p, :co, :ico, :dic, :addr,
           :tier, :pkg, :url, :notes, :total, :curr,
           :pm, 'pending', :loc, :ip, :ua)
    ");
    $stmt->execute([
        'no'   => $orderNo,
        'n'    => $name,
        'c'    => trim($d['customer_company'] ?? '') ?: null,
        'e'    => $email,
        'p'    => trim($d['customer_phone'] ?? '') ?: null,
        'co'   => strtoupper(substr(trim($d['customer_country'] ?? 'CZ'), 0, 2)),
        'ico'  => trim($d['customer_ico'] ?? '') ?: null,
        'dic'  => trim($d['customer_dic'] ?? '') ?: null,
        'addr' => trim($d['customer_address'] ?? '') ?: null,
        'tier' => $tier,
        'pkg'  => $packagesJson,
        'url'  => trim($d['install_url'] ?? '') ?: null,
        'notes' => trim($d['notes'] ?? '') ?: null,
        'total' => $total,
        'curr' => strtoupper(substr(trim($d['currency'] ?? 'CZK'), 0, 3)),
        'pm'   => in_array(($d['payment_method'] ?? 'bank'), ['bank', 'card', 'crypto', 'manual'], true) ? $d['payment_method'] : 'bank',
        'loc'  => strtolower(substr(trim($d['locale'] ?? 'cs'), 0, 2)),
        'ip'   => $ip,
        'ua'   => substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 500),
    ]);

    echo json_encode([
        'ok'       => true,
        'order_no' => $orderNo,
        'total_kc' => $total,
        // Defaultní platební instrukce — zákazník zaplatí převodem na účet,
        // následně vendor manuálně označí jako paid v vendor/shop.php.
        // V další iteraci tudy vrátíme Stripe Checkout URL apod.
        'payment_instructions' => [
            'bank_account'     => 'Bude doplněno z vendor settings',
            'variable_symbol'  => preg_replace('/\D/', '', $orderNo),
            'amount_kc'        => $total,
            'message'          => "APPEK objednávka $orderNo · $name",
        ],
    ]);
} catch (Throwable $e) {
    error_log('shop_buy: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'server_error']);
}
