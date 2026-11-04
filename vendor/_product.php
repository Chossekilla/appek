<?php
/**
 * 🚚 PRODUKT — vendor spravuje dva prodejní kanály: Appek a Můj Foodtruck.
 *
 * Sloupec `product` (VARCHAR(16) DEFAULT 'appek') je v tabulkách vendor_licenses,
 * vendor_shop_orders, vendor_coupons, vendor_invoices. Filtruje se VŽDY přes
 * COALESCE(product,'appek') → stará data (před migrací) = appek.
 *
 * Volba produktu v UI: ?product=appek|foodtruck|all → uloží se do session.
 * Default appek → vendor vypadá přesně jako dřív.
 *
 * ⚠️ Nesmí includovat _lib.php (testuje se samostatně v CLI).
 */

const VENDOR_PRODUCTS = ['appek' => 'Appek', 'foodtruck' => 'Můj Foodtruck'];

/** Whitelist: appek|foodtruck|all (all jen když $allowAll). Cokoli jiného → appek. */
function vendor_product_normalize(?string $p, bool $allowAll = true): string {
    $p = strtolower(trim((string) $p));
    if (isset(VENDOR_PRODUCTS[$p])) return $p;
    if ($p === 'all' && $allowAll) return 'all';
    return 'appek';
}

/**
 * SQL fragment pro WHERE. Hodnota i název sloupce jsou whitelistované —
 * nikdy se do SQL nedostane nic z uživatele.
 */
function vendor_product_sql(string $product, string $col = 'product'): string {
    if (!preg_match('/^[a-z_.]+$/', $col)) {
        throw new InvalidArgumentException('Neplatný název sloupce');
    }
    $product = vendor_product_normalize($product);
    if ($product === 'all') return '1=1';
    return "COALESCE($col,'appek') = '$product'";
}

/** Aktuální pohled ve vendoru (session; ?product= ji přepíše). Default appek. */
function vendor_product_current(): string {
    if (isset($_GET['product'])) {
        $_SESSION['vendor_product'] = vendor_product_normalize((string) $_GET['product']);
    }
    return vendor_product_normalize($_SESSION['vendor_product'] ?? 'appek');
}

/** Produkt pro zápis (INSERT): v pohledu „Vše" se zapisuje appek. */
function vendor_product_for_write(): string {
    return vendor_product_normalize(vendor_product_current(), false);
}

/** Idempotentní migrace sloupce product do 4 tabulek (chybějící tabulka se přeskočí). */
function vendor_ensure_product_columns(PDO $pdo): void {
    static $done = false;
    if ($done) return;
    $done = true;
    foreach (['vendor_licenses', 'vendor_shop_orders', 'vendor_coupons', 'vendor_invoices'] as $t) {
        try {
            $has = $pdo->query("SHOW COLUMNS FROM `$t` LIKE 'product'")->fetchAll();
            if (!$has) {
                $pdo->exec("ALTER TABLE `$t` ADD COLUMN product VARCHAR(16) NOT NULL DEFAULT 'appek'");
            }
        } catch (Throwable $e) { /* tabulka zatím neexistuje → vytvoří ji její stránka, migrace proběhne příště */ }
    }
}

/**
 * Výchozí hodnoty nové licence podle produktu.
 *   foodtruck → vždy pronájem, žádné balíčky, platnost +1 měsíc (bez přetečení: 31.1. → 28./29.2.)
 *   appek     → beze změny proti dřívějšku: známé balíčky, rental dle vstupu, platnost +1 rok
 * Ručně zadané expires_at má přednost.
 */
function vendor_license_defaults(string $product, array $d, string $today): array {
    $product = vendor_product_normalize($product, false);
    $manual  = !empty($d['expires_at']) ? (string) $d['expires_at'] : null;
    $base    = new DateTimeImmutable($today);

    if ($product === 'foodtruck') {
        $next = $base->modify('first day of next month');
        $day  = min((int) $base->format('j'), (int) $next->format('t'));
        return [
            'product'    => 'foodtruck',
            'packages'   => [],
            'rental'     => 1,
            'expires_at' => $manual ?? $next->setDate((int) $next->format('Y'), (int) $next->format('n'), $day)->format('Y-m-d'),
        ];
    }

    $packages = is_array($d['packages'] ?? null) ? $d['packages'] : [];
    if (defined('LICENSE_PACKAGE_BITS')) {
        $packages = array_values(array_filter($packages, fn($p) => is_string($p) && isset(LICENSE_PACKAGE_BITS[$p])));
    }
    return [
        'product'    => 'appek',
        'packages'   => $packages,
        'rental'     => !empty($d['rental']) ? 1 : 0,
        'expires_at' => $manual ?? $base->modify('+1 year')->format('Y-m-d'),
    ];
}

/** Klíče vendor_settings pro Můj Foodtruck (čtou je Plány 2–4: pokladna, e-maily, faktury, portál). */
const VENDOR_FT_SETTING_KEYS = [
    'ft_price_month_kc', 'ft_price_year_kc', 'ft_web_url', 'ft_portal_url',
    'ft_mail_from_email', 'ft_mail_from_name', 'ft_invoice_prefix',
    'ft_supplier_name', 'ft_supplier_ico', 'ft_supplier_dic', 'ft_supplier_address',
    'ft_supplier_bank_account', 'ft_supplier_iban',
];

/**
 * Validace formuláře „Můj Foodtruck" v Nastavení.
 * @return array [string[] $errors, array<string,string> $values] — values jen pro whitelist klíčů.
 *   ceny: prázdné nebo číslo ≥ 0 (desetinná čárka OK); URL: prázdné nebo http(s);
 *   e-mail: prázdný nebo platný; prefix faktur: A–Z0–9 velkými, default MFT.
 */
function vendor_ft_settings_validate(array $in): array {
    $errors = [];
    $vals = [];
    foreach (VENDOR_FT_SETTING_KEYS as $k) {
        $vals[$k] = trim((string) ($in[$k] ?? ''));
    }
    foreach (['ft_price_month_kc' => 'Měsíční cena', 'ft_price_year_kc' => 'Roční cena'] as $k => $label) {
        if ($vals[$k] === '') continue;
        $n = str_replace([' ', ','], ['', '.'], $vals[$k]);
        if (!is_numeric($n) || (float) $n < 0) { $errors[] = "$label musí být číslo ≥ 0."; continue; }
        $vals[$k] = $n;
    }
    foreach (['ft_web_url' => 'Adresa webu', 'ft_portal_url' => 'Adresa portálu'] as $k => $label) {
        if ($vals[$k] === '') continue;
        if (!filter_var($vals[$k], FILTER_VALIDATE_URL) || !preg_match('#^https?://#i', $vals[$k])) {
            $errors[] = "$label musí být platná adresa (https://…).";
        }
    }
    if ($vals['ft_mail_from_email'] !== '' && !filter_var($vals['ft_mail_from_email'], FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'E-mail odesílatele není platný.';
    }
    $prefix = preg_replace('/[^A-Z0-9]/', '', strtoupper($vals['ft_invoice_prefix']));
    $vals['ft_invoice_prefix'] = $prefix !== '' ? substr($prefix, 0, 8) : 'MFT';
    return [$errors, $vals];
}
