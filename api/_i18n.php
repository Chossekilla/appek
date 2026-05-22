<?php
/**
 * 🌍 PHP i18n — server-side překlady pro PDF/email/print výstupy.
 *
 * Jazyk se určuje (v pořadí priority):
 *   1. $_GET['lang']  (např. faktura.php?id=5&lang=en)
 *   2. $_COOKIE['appek_lang']
 *   3. Accept-Language header (en/cs/es prefix)
 *   4. nastaveni.firma_lang (firma default)
 *   5. 'cs' fallback
 *
 * Použití:
 *   _t('Faktura')                → "Faktura" / "Invoice" / "Factura"
 *   _t('Datum splatnosti')       → překlad
 *   _t_lang($lang, 'Faktura')    → explicitní jazyk (např. pro email per-odběratel)
 */

const APPEK_I18N = [
    // Faktura / DL záhlaví
    'Faktura'                => ['en' => 'Invoice',           'es' => 'Factura'],
    'Dodací list'            => ['en' => 'Delivery note',     'es' => 'Albarán'],
    'Daňový doklad'          => ['en' => 'Tax document',      'es' => 'Documento fiscal'],
    'Objednávka'             => ['en' => 'Order',             'es' => 'Pedido'],
    'Číslo'                  => ['en' => 'Number',            'es' => 'Número'],
    'Variabilní symbol'      => ['en' => 'Variable symbol',   'es' => 'Símbolo variable'],
    'Datum vystavení'        => ['en' => 'Issue date',        'es' => 'Fecha de emisión'],
    'Datum splatnosti'       => ['en' => 'Due date',          'es' => 'Fecha de vencimiento'],
    'Datum dodání'           => ['en' => 'Delivery date',     'es' => 'Fecha de entrega'],
    'Datum uskutečnění plnění' => ['en' => 'Date of supply',  'es' => 'Fecha de suministro'],
    'Dodavatel'              => ['en' => 'Supplier',          'es' => 'Proveedor'],
    'Odběratel'              => ['en' => 'Customer',          'es' => 'Cliente'],
    'Odběratel:'             => ['en' => 'Customer:',         'es' => 'Cliente:'],
    'Místo dodání'           => ['en' => 'Delivery address',  'es' => 'Dirección de entrega'],
    'IČO'                    => ['en' => 'Tax ID',            'es' => 'CIF'],
    'DIČ'                    => ['en' => 'VAT ID',            'es' => 'NIF'],
    'IČ DPH'                 => ['en' => 'VAT ID',            'es' => 'NIF'],

    // Položky
    'Položka'                => ['en' => 'Item',              'es' => 'Artículo'],
    'Položky'                => ['en' => 'Items',             'es' => 'Artículos'],
    'Popis'                  => ['en' => 'Description',       'es' => 'Descripción'],
    'Množství'               => ['en' => 'Quantity',          'es' => 'Cantidad'],
    'Jednotka'               => ['en' => 'Unit'  ,            'es' => 'Unidad'],
    'MJ'                     => ['en' => 'Unit',              'es' => 'Ud.'],
    'Cena bez DPH'           => ['en' => 'Price excl. VAT',   'es' => 'Precio sin IVA'],
    'Cena s DPH'             => ['en' => 'Price incl. VAT',   'es' => 'Precio con IVA'],
    'Cena za MJ'             => ['en' => 'Unit price',        'es' => 'Precio unitario'],
    'Cena/MJ'                => ['en' => 'Unit price',        'es' => 'Precio/Ud.'],
    'Sazba DPH'              => ['en' => 'VAT rate',          'es' => 'Tipo IVA'],
    'DPH'                    => ['en' => 'VAT',               'es' => 'IVA'],
    'Bez DPH'                => ['en' => 'Excl. VAT',         'es' => 'Sin IVA'],
    'S DPH'                  => ['en' => 'Incl. VAT',         'es' => 'Con IVA'],
    'Mezisoučet'             => ['en' => 'Subtotal',          'es' => 'Subtotal'],
    'Celkem'                 => ['en' => 'Total',             'es' => 'Total'],
    'Celkem bez DPH'         => ['en' => 'Total excl. VAT',   'es' => 'Total sin IVA'],
    'Celkem s DPH'           => ['en' => 'Total incl. VAT',   'es' => 'Total con IVA'],
    'Celkem DPH'             => ['en' => 'Total VAT',         'es' => 'Total IVA'],
    'Celkem k úhradě'        => ['en' => 'Total due',         'es' => 'Total a pagar'],
    'K úhradě'               => ['en' => 'To be paid',        'es' => 'A pagar'],

    // Platba
    'Platba'                 => ['en' => 'Payment',           'es' => 'Pago'],
    'Způsob platby'          => ['en' => 'Payment method',    'es' => 'Forma de pago'],
    'Bankovním převodem'     => ['en' => 'Bank transfer',     'es' => 'Transferencia bancaria'],
    'V hotovosti'            => ['en' => 'Cash',              'es' => 'Efectivo'],
    'Číslo účtu'             => ['en' => 'Account number',    'es' => 'Nº de cuenta'],
    'IBAN'                   => ['en' => 'IBAN',              'es' => 'IBAN'],
    'SWIFT'                  => ['en' => 'SWIFT',             'es' => 'SWIFT'],
    'Banka'                  => ['en' => 'Bank',              'es' => 'Banco'],
    'Konstantní symbol'      => ['en' => 'Constant symbol',   'es' => 'Símbolo constante'],

    // Stavy
    'Uhrazeno'               => ['en' => 'Paid',              'es' => 'Pagado'],
    'Neuhrazeno'             => ['en' => 'Unpaid',            'es' => 'No pagado'],
    'Po splatnosti'          => ['en' => 'Overdue',           'es' => 'Vencido'],
    'Zrušeno'                => ['en' => 'Cancelled',         'es' => 'Cancelado'],

    // Patička
    'Faktura vystavena podle § 26 zákona č. 235/2004 Sb., o DPH.' => ['en' => 'Invoice issued per VAT Act.', 'es' => 'Factura emitida según la Ley del IVA.'],
    'Děkujeme za Vaši objednávku.' => ['en' => 'Thank you for your order.', 'es' => 'Gracias por su pedido.'],
    'Stránka'                => ['en' => 'Page',              'es' => 'Página'],
    'z'                      => ['en' => 'of',                'es' => 'de'],

    // Common
    'Poznámka'               => ['en' => 'Note',              'es' => 'Nota'],
    'Razítko a podpis'       => ['en' => 'Stamp and signature','es' => 'Sello y firma'],
    'Předal'                 => ['en' => 'Handed over by',    'es' => 'Entregado por'],
    'Převzal'                => ['en' => 'Received by',       'es' => 'Recibido por'],
    'Datum'                  => ['en' => 'Date',              'es' => 'Fecha'],
    'Podpis'                 => ['en' => 'Signature',         'es' => 'Firma'],
];

/**
 * Detekuje aktuální jazyk pro PHP požadavek.
 */
function _detect_lang(): string {
    if (!empty($_GET['lang']) && in_array($_GET['lang'], ['cs', 'en', 'es'], true)) {
        return $_GET['lang'];
    }
    if (!empty($_COOKIE['appek_lang']) && in_array($_COOKIE['appek_lang'], ['cs', 'en', 'es'], true)) {
        return $_COOKIE['appek_lang'];
    }
    // Firma default v DB
    try {
        $pdo = db();
        $lang = $pdo->query("SELECT hodnota FROM nastaveni WHERE klic = 'firma_lang'")->fetchColumn();
        if ($lang && in_array($lang, ['cs', 'en', 'es'], true)) return $lang;
    } catch (Throwable $e) { /* ignore */ }
    // Accept-Language
    $accept = $_SERVER['HTTP_ACCEPT_LANGUAGE'] ?? '';
    if (preg_match('/^(en|es|cs)/i', $accept, $m)) {
        return strtolower($m[1]);
    }
    return 'cs';
}

/**
 * Translation helper s auto-detekcí jazyka.
 */
function _t(string $key): string {
    static $lang = null;
    if ($lang === null) $lang = _detect_lang();
    return _t_lang($lang, $key);
}

/**
 * Explicitní jazyk (např. pro email per-customer).
 */
function _t_lang(string $lang, string $key): string {
    if ($lang === 'cs') return $key;
    return APPEK_I18N[$key][$lang] ?? $key;
}

/**
 * Helper: vrátí aktuální jazyk (cached).
 */
function _current_lang(): string {
    static $lang = null;
    if ($lang === null) $lang = _detect_lang();
    return $lang;
}

/**
 * Formátování data podle locale.
 */
function _fmt_date_loc(string $date, ?string $lang = null): string {
    if (!$date) return '';
    $lang = $lang ?? _current_lang();
    $ts = strtotime($date);
    if ($ts === false) return $date;
    switch ($lang) {
        case 'en': return date('M j, Y', $ts);          // Jan 15, 2026
        case 'es': return date('j/n/Y', $ts);           // 15/1/2026
        default:   return date('j. n. Y', $ts);         // 15. 1. 2026
    }
}

/**
 * Formátování měny podle locale (Kč pořád, ale tvar závisí).
 */
function _fmt_money_loc(float $n, ?string $lang = null): string {
    $lang = $lang ?? _current_lang();
    switch ($lang) {
        case 'en': return number_format($n, 2, '.', ',') . ' CZK';
        case 'es': return number_format($n, 2, ',', '.') . ' CZK';
        default:   return number_format($n, 2, ',', ' ') . ' Kč';
    }
}
