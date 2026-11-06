<?php
/**
 * 🎟️ SHOP COUPON LIB — slevové kupony na PRODEJ APPEK licence (appek.cz checkout).
 *
 * Sdílená validace + výpočet slevy pro `api/shop_coupon.php` (veřejné ověření z checkoutu)
 * a `api/shop_buy.php` (autoritativní přepočet při vytvoření objednávky).
 *
 * ⚠️ NEZAMĚŇOVAT se zákaznickými vouchery (`api/admin_vouchers.php`) — ty jsou pro
 * ESHOP ZÁKAZNÍKA (pekárna → její odběratelé). Tohle jsou kupony na prodej samotné APPEK
 * licence, spravované na vendoru, tabulka `vendor_coupons` ve vendor DB.
 */

/** Idempotentně zajistí tabulku kuponů ve vendor DB. */
function shop_coupon_ensure_table(PDO $pdo): void {
    $pdo->exec("CREATE TABLE IF NOT EXISTS vendor_coupons (
        id INT AUTO_INCREMENT PRIMARY KEY,
        kod VARCHAR(64) NOT NULL UNIQUE,
        typ ENUM('percent','fixed') NOT NULL DEFAULT 'percent',
        hodnota DECIMAL(10,2) NOT NULL DEFAULT 0,
        popis VARCHAR(255) DEFAULT NULL,
        active TINYINT(1) NOT NULL DEFAULT 1,
        plati_do DATE DEFAULT NULL,
        max_pouziti INT DEFAULT NULL,
        pouzito INT NOT NULL DEFAULT 0,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
}

/**
 * Vyhodnotí kupon proti danému subtotalu.
 * @return array [bool $valid, string $reason, ?array $coupon, float $discountKc]
 *   reason ∈ empty|not_found|inactive|expired|used_up|ok
 */
function shop_coupon_eval(PDO $pdo, string $code, float $subtotal): array {
    $code = strtoupper(trim($code));
    if ($code === '') return [false, 'empty', null, 0.0];

    $st = $pdo->prepare("SELECT * FROM vendor_coupons WHERE kod = :k LIMIT 1");
    $st->execute(['k' => $code]);
    $c = $st->fetch(PDO::FETCH_ASSOC);
    if (!$c)                                                                 return [false, 'not_found', null, 0.0];
    if ((int) $c['active'] !== 1)                                           return [false, 'inactive', $c, 0.0];
    if (!empty($c['plati_do']) && $c['plati_do'] < date('Y-m-d'))          return [false, 'expired', $c, 0.0];
    if ($c['max_pouziti'] !== null && (int) $c['pouzito'] >= (int) $c['max_pouziti']) return [false, 'used_up', $c, 0.0];

    $disc = ($c['typ'] === 'percent')
        ? round($subtotal * ((float) $c['hodnota'] / 100), 2)
        : (float) $c['hodnota'];
    if ($disc < 0)          $disc = 0.0;
    if ($disc > $subtotal)  $disc = $subtotal;   // sleva nikdy nepřevýší cenu

    return [true, 'ok', $c, $disc];
}
