<?php
/**
 * 💰 SHOP PRICING LIB — autoritativní výpočet ceny prodeje APPEK licence.
 *
 * Prodejní model (od v3.0.647): ke CORE je v ceně JEDEN balíček zdarma, vybere si ho zákazník.
 * Když v košíku víc placených balíčků, zdarma je ten NEJDRAŽŠÍ (pro zákazníka nejvýhodnější).
 * Platí pro koupi i pronájem i prodloužení — vždy jen když je v licenci/košíku core.
 *
 * Používají: api/shop_buy.php (objednávka), my/index.php (prodloužení), checkout.html (stejné
 * pravidlo v JS — `freePackageKey`). Cena z klienta se NIKDY nebere (dřív šel total_kc podvrhnout).
 */

/**
 * Čisté pravidlo (bez DB): vstup = položky [['key'=>..,'is_core'=>bool,'price'=>float], ...]
 * v pořadí eshopu. Vrací ['subtotal','free_key','free_kc','total'].
 */
function shop_apply_free_package(array $items): array {
    $subtotal = 0.0;
    $hasCore  = false;
    $freeKey  = null;
    $freeKc   = 0.0;
    foreach ($items as $it) {
        $price = max(0.0, (float) ($it['price'] ?? 0));
        $subtotal += $price;
        if (!empty($it['is_core'])) { $hasCore = true; continue; }
        // nejdražší vyhrává; při shodě první v pořadí eshopu (striktní >)
        if ($price > $freeKc) { $freeKc = $price; $freeKey = (string) $it['key']; }
    }
    if (!$hasCore) { $freeKey = null; $freeKc = 0.0; }
    return [
        'subtotal' => round($subtotal, 2),
        'free_key' => $freeKey,
        'free_kc'  => round($freeKc, 2),
        'total'    => round(max(0.0, $subtotal - $freeKc), 2),
    ];
}

/** Cena jednoho balíčku pro eshop: pronájem = měsíční × N (zaokrouhleno jako checkout), jinak roční/napořád. */
function shop_package_price(array $row, int $rentalMonths): float {
    if ($rentalMonths > 0) return (float) round(((float) ($row['price_month_kc'] ?? 0)) * $rentalMonths);
    return (float) ($row['price_kc'] ?? 0);
}

/**
 * Načte prodejné balíčky z vendor DB a spočítá cenu košíku.
 * Neznámé/neaktivní/neprodejné klíče se zahodí. Vrací breakdown + 'packages' (platné klíče).
 */
function shop_price_cart(PDO $pdo, array $keys, int $rentalMonths): array {
    $keys = array_values(array_unique(array_filter(array_map(
        fn($k) => strtolower(trim((string) $k)), $keys))));
    // v3.0.588 — catering (v Lahůdkách) a sezona (zdarma) se samostatně neprodávají
    $keys = array_values(array_diff($keys, ['catering', 'sezona']));
    if (!$keys) return ['subtotal' => 0.0, 'free_key' => null, 'free_kc' => 0.0, 'total' => 0.0, 'packages' => []];

    $in = implode(',', array_fill(0, count($keys), '?'));
    $st = $pdo->prepare("SELECT `key`, is_core, price_kc, price_month_kc FROM vendor_packages
                          WHERE is_active = 1 AND LOWER(`key`) IN ($in) ORDER BY sort_order, id");
    $st->execute($keys);
    $items = [];
    $valid = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $items[] = ['key' => $r['key'], 'is_core' => (bool) $r['is_core'], 'price' => shop_package_price($r, $rentalMonths)];
        $valid[] = $r['key'];
    }
    return shop_apply_free_package($items) + ['packages' => $valid];
}

/** Idempotentní migrace: objednávka i licence si pamatují, který balíček byl zdarma. */
function shop_pricing_ensure_cols(PDO $pdo): void {
    static $done = false;
    if ($done) return;
    $done = true;
    try {
        if (!$pdo->query("SHOW COLUMNS FROM vendor_shop_orders LIKE 'free_package'")->fetchAll()) {
            $pdo->exec("ALTER TABLE vendor_shop_orders ADD COLUMN free_package VARCHAR(32) DEFAULT NULL, ADD COLUMN free_kc DECIMAL(10,2) NOT NULL DEFAULT 0");
        }
    } catch (Throwable $e) { error_log('shop_pricing_ensure_cols orders: ' . $e->getMessage()); }
    try {
        if (!$pdo->query("SHOW COLUMNS FROM vendor_licenses LIKE 'free_package'")->fetchAll()) {
            $pdo->exec("ALTER TABLE vendor_licenses ADD COLUMN free_package VARCHAR(32) DEFAULT NULL");
        }
    } catch (Throwable $e) { error_log('shop_pricing_ensure_cols licenses: ' . $e->getMessage()); }
}

/**
 * Po vygenerování licence z objednávky (volají VŠECHNY cesty vystavení):
 *   • KOUPĚ (rental_months=0) = licence NAVŽDY → expires_at NULL (core i všechny koupené balíčky;
 *     od v3.0.648 — dřív roční). Updaty zdarma 12 měsíců řeší obchodní podmínky, ne expirace.
 *   • PRONÁJEM = expirace zůstává (platí jen zaplacené období).
 *   • free_package (balíček zdarma ke core) se uloží na licenci (prodloužení pronájmu ho neúčtuje).
 */
function shop_licence_apply_free(PDO $pdo, int $licenseId, array $order): void {
    if ($licenseId <= 0) return;
    shop_pricing_ensure_cols($pdo);
    $free = strtolower(trim((string) ($order['free_package'] ?? '')));
    $perpetual = ((int) ($order['rental_months'] ?? 0)) === 0 && ($order['tier'] ?? '') !== 'renewal';
    $set = [];
    $par = ['id' => $licenseId];
    if ($free !== '') { $set[] = 'free_package = :f'; $par['f'] = $free; }
    if ($perpetual)   { $set[] = 'expires_at = NULL'; }
    if (!$set) return;
    $pdo->prepare("UPDATE vendor_licenses SET " . implode(', ', $set) . " WHERE id = :id")->execute($par);
}
