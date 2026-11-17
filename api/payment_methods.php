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
require_once __DIR__ . '/_platby_lib.php'; // 🆕 v3.0.272 — config (poplatky, doprava, splatnost)
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
    'paypal'    => ['label' => '💼 PayPal',           'cat' => 'online',   'default' => false, 'pos' => false, 'b2b' => true],
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

/**
 * 💱 v3.0.643 — v jakých měnách jde metodou zaplatit (Kč vždy). Online brány dle integrace
 * (customer_int_brana_meny), dobírka jen Kč (COD u přepravců je v Kč), převod v cizí měně jen s EUR IBAN.
 */
function payment_method_meny(PDO $pdo, string $key): array {
    require_once __DIR__ . '/_mena_lib.php';
    $pl = mena_config_load($pdo)['platba'];
    $cizi = $pl['aktivni'] && in_array('b2b', $pl['kanaly'], true) ? $pl['meny'] : [];
    if (in_array($key, ['stripe', 'gopay', 'paypal'], true)) {
        require_once __DIR__ . '/_customer_integrace.php';
        return array_values(array_unique(array_merge(['CZK'], array_values(array_intersect($cizi, customer_int_brana_meny($key))))));
    }
    if ($key === 'prevod') return mena_iban_eur($pdo) !== '' ? array_values(array_unique(array_merge(['CZK'], array_intersect($cizi, ['EUR'])))) : ['CZK'];
    if ($key === 'faktura') return array_values(array_unique(array_merge(['CZK'], $cizi)));   // měnu/IBAN řeší faktura (bez EUR IBAN QR nevznikne)
    return ['CZK'];   // dobírka, hotově, …
}

$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'GET') {
    // Public read OK — žádné secrets v metadatech
    $all = payment_methods_load($pdo);
    $context = $_GET['context'] ?? '';
    $cfg = platby_config_load($pdo);

    // 🆕 v3.0.272 — náhled příplatků pro checkout (B2B portál volá při změně volby)
    if (($_GET['action'] ?? '') === 'surcharges') {
        $payment  = preg_replace('/[^a-z_]/', '', (string) ($_GET['payment'] ?? ''));
        $shipping = preg_replace('/[^a-z_]/', '', (string) ($_GET['shipping'] ?? ''));
        $subtotal = (float) ($_GET['subtotal'] ?? 0);
        json_response(platby_surcharges($pdo, $payment, $shipping, max(0, $subtotal)));
    }

    // 🆕 v3.0.272 — přilep per-metoda config (poplatek, splatnost) — ne IBAN (jen na doklad/server)
    $attachCfg = function (array $m) use ($cfg): array {
        $c = $cfg['methods_config'][$m['key']] ?? [];
        $m['poplatek']      = isset($c['poplatek']) ? (float) $c['poplatek'] : 0;
        $m['poplatek_typ']  = $c['poplatek_typ'] ?? 'kc';
        $m['splatnost_dni'] = isset($c['splatnost_dni']) ? (int) $c['splatnost_dni'] : null;
        return $m;
    };

    if ($context === 'pos') {
        // 🆕 v3.0.279 — POS metody teď nesou poplatek (karta-fee na pokladně) → recalc je zobrazí
        $filtered = array_map($attachCfg, array_values(array_filter($all, fn($m) => $m['enabled'] && $m['pos'])));
        json_response(['methods' => $filtered]);
    }

    if ($context === 'b2b') {
        $filtered = array_map($attachCfg, array_values(array_filter($all, fn($m) => $m['enabled'] && $m['b2b'])));
        // 💱 v3.0.643 — ?mena=EUR: skryj metody, které měnu neumí (dobírka = Kč u přepravce; GoPay bez EUR
        //   na GoID; převod bez EUR IBAN). Každá metoda nese `meny` (v čem jde zaplatit) pro přepínač v košíku.
        $mena = strtoupper(preg_replace('/[^A-Za-z]/', '', (string) ($_GET['mena'] ?? '')));
        $filtered = array_map(fn($m) => $m + ['meny' => payment_method_meny($pdo, $m['key'])], $filtered);
        if ($mena !== '' && $mena !== 'CZK') $filtered = array_values(array_filter($filtered, fn($m) => in_array($mena, $m['meny'], true)));
        $dopravaPub = array_values(array_filter($cfg['doprava']['metody'], fn($m) => $m['aktivni']));
        json_response(['methods' => $filtered, 'doprava' => ['zdarma_od' => $cfg['doprava']['zdarma_od'], 'metody' => $dopravaPub]]);
    }
    // No context → all (pro admin UI) — vč. plného configu (s IBAN) a dopravy
    require_admin(); // 🔒 v3.0.349 — plný config (IBAN) jen přihlášenému adminovi, ne anonymně
    json_response([
        'methods' => array_values(array_map($attachCfg, $all)),
        'config'  => $cfg['methods_config'],
        'doprava' => $cfg['doprava'],
    ]);
}

if ($method === 'PUT' || $method === 'POST') {
    // 🐛 v3.0.644 SECURITY — zápis (IBAN pro QR platby na fakturách, poplatky, doprava) JEN plný super admin
    //   heslem. Endpoint je v POS allowlistu kvůli GET ?context=pos → dřív pokladní s PINem přepsal IBAN.
    require_super_admin();
    if (!empty($_SESSION['pos_only_user'])) json_error('POS účet nemůže měnit platební metody', 403);
    $d = json_input();
    // on/off mapa (zpětně kompatibilní)
    if (isset($d['methods']) && is_array($d['methods'])) {
        payment_methods_save($pdo, $d['methods']);
    }
    // 🆕 v3.0.272 — per-metoda config (poplatky/splatnost/IBAN) + doprava (práh + ceny)
    if (array_key_exists('config', $d) || array_key_exists('doprava', $d)) {
        platby_config_save($pdo, $d['config'] ?? null, $d['doprava'] ?? null);
    }
    $cfg = platby_config_load($pdo);
    json_response(['ok' => true, 'methods' => array_values(payment_methods_load($pdo)), 'config' => $cfg['methods_config'], 'doprava' => $cfg['doprava']]);
}

json_error('Method not allowed', 405);
