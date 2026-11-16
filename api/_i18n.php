<?php
/**
 * 🌍 Doklady v jazyce odběratele — server-side překlady pro fakturu, dobropis,
 *    dodací list / objednávku a e-mail s dokladem (cs / en / es).
 *
 * Jazyk dokladu (v pořadí priority):
 *   1. explicitní ?lang=cs|en|es v URL dokladu (ruční volba v adminu)
 *   2. odberatele.jazyk_dokladu       (nastavení u odběratele; NULL = výchozí firmy)
 *   3. nastaveni.firma_jazyk_dokladu  (výchozí jazyk dokladů firmy)
 *   4. 'cs'
 * Jazyk prohlížeče ani jazyk rozhraní adminu doklad NEovlivňují — rozhoduje odběratel.
 * Bez nastavení zůstává vše česky (stávající instalace se nemění).
 *
 * Měna dokladu je nezávislá na jazyce: doc_mena_set(mena_doklad(...)) (viz _mena_lib.php).
 * doc_money($ulozenaCastka) ji přepočte z měny uložení do měny dokladu a naformátuje.
 *
 * Použití v šabloně:
 *   doc_lang_set(doc_lang_resolve($pdo, $odberatelId));
 *   _t('Faktura'), _t('Hromadný tisk {n} faktur', ['n' => 3])
 *   _fmt_date_loc($datum), _fmt_money_loc($castka), _fmt_qty_loc($mnozstvi), _unit_loc('ks')
 * V češtině vrací helpery přesně totéž co fmt_date()/fmt_kc(); množství navíc nezaokrouhlí
 * desetinná místa (2,5 kg dřív ukázalo „3").
 */

const APPEK_DOC_LANGS = ['cs', 'en', 'es'];

const APPEK_I18N = [
    // ── Druhy dokladů + záhlaví
    'Faktura'                          => ['en' => 'Invoice',                                  'es' => 'Factura'],
    'Opravný daňový doklad (dobropis)' => ['en' => 'Credit note (corrective tax document)',    'es' => 'Factura rectificativa (abono)'],
    'Dodací list'                      => ['en' => 'Delivery note',                            'es' => 'Albarán'],
    'Objednávka'                       => ['en' => 'Order',                                    'es' => 'Pedido'],
    'Daňový doklad'                    => ['en' => 'Tax document',                             'es' => 'Documento fiscal'],
    'č.'                               => ['en' => 'No.',                                      'es' => 'N.º'],
    'k faktuře'                        => ['en' => 'relating to invoice',                      'es' => 'rectifica la factura'],
    '{cislo} - faktura'                => ['en' => '{cislo} - invoice',                        'es' => '{cislo} - factura'],
    '{cislo} - dobropis'               => ['en' => '{cislo} - credit note',                    'es' => '{cislo} - abono'],
    '{cislo} - dodací list'            => ['en' => '{cislo} - delivery note',                  'es' => '{cislo} - albarán'],
    '{cislo} - objednávka'             => ['en' => '{cislo} - order',                          'es' => '{cislo} - pedido'],
    'Tisk {n} faktur'                  => ['en' => 'Printing {n} invoices',                    'es' => 'Impresión de {n} facturas'],
    'Tisk {n} dodacích listů'          => ['en' => 'Printing {n} delivery notes',              'es' => 'Impresión de {n} albaranes'],
    'Tisk {n} objednávek'              => ['en' => 'Printing {n} orders',                      'es' => 'Impresión de {n} pedidos'],

    // ── Strany
    'Dodavatel'              => ['en' => 'Supplier',                 'es' => 'Proveedor'],
    'Odběratel'              => ['en' => 'Customer',                 'es' => 'Cliente'],
    'Místo dodání'           => ['en' => 'Delivery address',         'es' => 'Dirección de entrega'],
    'IČO'                    => ['en' => 'Company ID',               'es' => 'CIF/NIF'],
    'DIČ'                    => ['en' => 'VAT ID',                   'es' => 'NIF-IVA'],
    'IČ DPH'                 => ['en' => 'VAT ID',                   'es' => 'NIF-IVA'],
    'Kontakt'                => ['en' => 'Contact',                  'es' => 'Contacto'],
    'tel.'                   => ['en' => 'tel.',                     'es' => 'tel.'],

    // ── Údaje dokladu
    'Datum vystavení'        => ['en' => 'Issue date',               'es' => 'Fecha de emisión'],
    'Datum splatnosti'       => ['en' => 'Due date',                 'es' => 'Fecha de vencimiento'],
    'Datum zd. plnění'       => ['en' => 'Date of taxable supply',   'es' => 'Fecha de la operación'],
    'Datum uskutečnění plnění' => ['en' => 'Date of supply',         'es' => 'Fecha de la operación'],
    'Datum dodání'           => ['en' => 'Delivery date',            'es' => 'Fecha de entrega'],
    'Č. objednávky'          => ['en' => 'Order No.',                'es' => 'N.º de pedido'],
    'Počet kusů'             => ['en' => 'Total quantity',           'es' => 'Cantidad total'],
    'Bankovní spojení'       => ['en' => 'Bank account',             'es' => 'Cuenta bancaria'],
    'Variabilní symbol'      => ['en' => 'Payment reference (VS)',   'es' => 'Referencia de pago (VS)'],
    'Konstantní symbol'      => ['en' => 'Constant symbol',          'es' => 'Símbolo constante'],
    'Forma úhrady'           => ['en' => 'Payment method',           'es' => 'Forma de pago'],
    'Bankovní převod'        => ['en' => 'Bank transfer',            'es' => 'Transferencia bancaria'],
    'V hotovosti'            => ['en' => 'Cash',                     'es' => 'Efectivo'],
    'Číslo účtu'             => ['en' => 'Account number',           'es' => 'N.º de cuenta'],
    'Banka'                  => ['en' => 'Bank',                     'es' => 'Banco'],
    'IBAN'                   => ['en' => 'IBAN',                     'es' => 'IBAN'],
    'SWIFT'                  => ['en' => 'SWIFT',                    'es' => 'SWIFT'],

    // ── Položky + rekapitulace
    'Kód'                    => ['en' => 'Code',                     'es' => 'Código'],
    'Položka'                => ['en' => 'Item',                     'es' => 'Artículo'],
    'Výrobek'                => ['en' => 'Product',                  'es' => 'Producto'],
    'Množství'               => ['en' => 'Quantity',                 'es' => 'Cantidad'],
    'Cena'                   => ['en' => 'Unit price',               'es' => 'Precio unitario'],
    'Cena/ks'                => ['en' => 'Unit price',               'es' => 'Precio unitario'],
    'DPH'                    => ['en' => 'VAT',                      'es' => 'IVA'],
    'Sazba'                  => ['en' => 'Rate',                     'es' => 'Tipo'],
    'Bez DPH'                => ['en' => 'Excl. VAT',                'es' => 'Base imponible'],
    'Celkem'                 => ['en' => 'Total',                    'es' => 'Total'],
    'Celkem s DPH'           => ['en' => 'Total incl. VAT',          'es' => 'Total con IVA'],
    'Celkem bez DPH'         => ['en' => 'Total excl. VAT',          'es' => 'Total sin IVA'],
    'K úhradě'               => ['en' => 'Amount due',               'es' => 'Total a pagar'],
    'DL z'                   => ['en' => 'Delivery note of',         'es' => 'Albarán del'],
    'kurz'                   => ['en' => 'exchange rate',            'es' => 'tipo de cambio'],
    'QR Platba'              => ['en' => 'QR payment',               'es' => 'Pago QR'],
    'Rekapitulace DPH v Kč'  => ['en' => 'VAT summary in CZK',       'es' => 'Resumen del IVA en CZK'],
    'Měna dokladu'           => ['en' => 'Document currency',        'es' => 'Moneda del documento'],
    'Poznámka'               => ['en' => 'Note',                     'es' => 'Nota'],
    'náhled'                 => ['en' => 'preview',                  'es' => 'vista previa'],

    // ── Podpisy
    'Vystavil (dodavatel)'   => ['en' => 'Issued by (supplier)',     'es' => 'Emitido por (proveedor)'],
    'Převzal (odběratel)'    => ['en' => 'Received by (customer)',   'es' => 'Recibido por (cliente)'],
    'Objednatel (odběratel)' => ['en' => 'Ordered by (customer)',    'es' => 'Pedido por (cliente)'],

    // ── Patička
    'Telefon'                => ['en' => 'Phone',                    'es' => 'Teléfono'],
    'E-mail'                 => ['en' => 'E-mail',                   'es' => 'Correo electrónico'],
    'Web'                    => ['en' => 'Web',                      'es' => 'Web'],
    'Vystaveno'              => ['en' => 'Issued',                   'es' => 'Emitido'],
    'Vytištěno'              => ['en' => 'Printed',                  'es' => 'Impreso'],

    // ── Panel nad dokladem (náhled v prohlížeči)
    'Hromadný tisk {n} faktur'          => ['en' => 'Batch print of {n} invoices',        'es' => 'Impresión por lotes de {n} facturas'],
    'Hromadný tisk {n} dodacích listů'  => ['en' => 'Batch print of {n} delivery notes',  'es' => 'Impresión por lotes de {n} albaranes'],
    'Hromadný tisk {n} objednávek'      => ['en' => 'Batch print of {n} orders',          'es' => 'Impresión por lotes de {n} pedidos'],
    'v dialogu „Uložit jako PDF" pro 1 PDF s více stránkami' => ['en' => 'choose “Save as PDF” in the print dialog for one multi-page PDF', 'es' => 'elija «Guardar como PDF» en el diálogo de impresión para un PDF de varias páginas'],
    'Pro PDF klikněte „Tisk" → v dialogu „Uložit jako PDF"'  => ['en' => 'For a PDF click “Print” → choose “Save as PDF”', 'es' => 'Para obtener un PDF pulse «Imprimir» → «Guardar como PDF»'],
    '← Zpět'                 => ['en' => '← Back',                   'es' => '← Volver'],
    '🖨 Tisk / Uložit PDF'   => ['en' => '🖨 Print / Save PDF',       'es' => '🖨 Imprimir / Guardar PDF'],

    // ── Chybové stavy dokladu
    'Chybí ID'                     => ['en' => 'Missing ID',                    'es' => 'Falta el ID'],
    'Přístup k faktuře zamítnut'   => ['en' => 'Access to the invoice denied',  'es' => 'Acceso a la factura denegado'],
    'Žádná z faktur nenalezena'    => ['en' => 'No invoice found',              'es' => 'No se encontró ninguna factura'],
    'Dodací list nenalezen'        => ['en' => 'Delivery note not found',       'es' => 'Albarán no encontrado'],

    // ── E-mail s dokladem
    'Faktura {cislo} · {firma}'      => ['en' => 'Invoice {cislo} · {firma}',        'es' => 'Factura {cislo} · {firma}'],
    'Dobropis {cislo} · {firma}'     => ['en' => 'Credit note {cislo} · {firma}',    'es' => 'Abono {cislo} · {firma}'],
    'Dodací list {cislo} · {firma}'  => ['en' => 'Delivery note {cislo} · {firma}',  'es' => 'Albarán {cislo} · {firma}'],
    'Objednávka {cislo} · {firma}'   => ['en' => 'Order {cislo} · {firma}',          'es' => 'Pedido {cislo} · {firma}'],
    'Dobrý den,'                     => ['en' => 'Hello,',                           'es' => 'Buenos días:'],
    'S pozdravem,'                   => ['en' => 'Kind regards,',                    'es' => 'Atentamente,'],
    'V příloze najdete PDF faktury {cislo}.'      => ['en' => 'Please find attached the PDF of invoice {cislo}.',       'es' => 'Le adjuntamos el PDF de la factura {cislo}.'],
    'V příloze najdete PDF dobropisu {cislo}.'    => ['en' => 'Please find attached the PDF of credit note {cislo}.',   'es' => 'Le adjuntamos el PDF del abono {cislo}.'],
    'V příloze najdete PDF dodacího listu {cislo}.' => ['en' => 'Please find attached the PDF of delivery note {cislo}.', 'es' => 'Le adjuntamos el PDF del albarán {cislo}.'],
    'V příloze najdete PDF objednávky {cislo}.'   => ['en' => 'Please find attached the PDF of order {cislo}.',         'es' => 'Le adjuntamos el PDF del pedido {cislo}.'],
    'Fakturu {cislo} najdete zde:'      => ['en' => 'Invoice {cislo} is available here:',       'es' => 'La factura {cislo} está disponible aquí:'],
    'Dobropis {cislo} najdete zde:'     => ['en' => 'Credit note {cislo} is available here:',   'es' => 'El abono {cislo} está disponible aquí:'],
    'Dodací list {cislo} najdete zde:'  => ['en' => 'Delivery note {cislo} is available here:', 'es' => 'El albarán {cislo} está disponible aquí:'],
    'Objednávku {cislo} najdete zde:'   => ['en' => 'Order {cislo} is available here:',         'es' => 'El pedido {cislo} está disponible aquí:'],
    'Faktura-{cislo}.pdf'            => ['en' => 'Invoice-{cislo}.pdf',        'es' => 'Factura-{cislo}.pdf'],
    'Dobropis-{cislo}.pdf'           => ['en' => 'CreditNote-{cislo}.pdf',     'es' => 'Abono-{cislo}.pdf'],
    'Dodaci-list-{cislo}.pdf'         => ['en' => 'DeliveryNote-{cislo}.pdf',   'es' => 'Albaran-{cislo}.pdf'],
    'Objednavka-{cislo}.pdf'         => ['en' => 'Order-{cislo}.pdf',          'es' => 'Pedido-{cislo}.pdf'],

    // ── Obecné (stavy, platby — pro další doklady)
    'Uhrazeno'               => ['en' => 'Paid',                     'es' => 'Pagado'],
    'Neuhrazeno'             => ['en' => 'Unpaid',                   'es' => 'No pagado'],
    'Po splatnosti'          => ['en' => 'Overdue',                  'es' => 'Vencido'],
    'Zrušeno'                => ['en' => 'Cancelled',                'es' => 'Cancelado'],
    'Datum'                  => ['en' => 'Date',                     'es' => 'Fecha'],
    'Podpis'                 => ['en' => 'Signature',                'es' => 'Firma'],
    'Razítko a podpis'       => ['en' => 'Stamp and signature',      'es' => 'Sello y firma'],
    'Stránka'                => ['en' => 'Page',                     'es' => 'Página'],
    'Děkujeme za Vaši objednávku.' => ['en' => 'Thank you for your order.', 'es' => 'Gracias por su pedido.'],

    // ── 📧 v3.0.643 — automatické e-maily a push zákazníkům (config.php notifikace_*)
    // Stavy objednávky ({stav} v šablonách, push)
    'přijata'                => ['en' => 'received',                 'es' => 'recibido'],
    'potvrzena'              => ['en' => 'confirmed',                'es' => 'confirmado'],
    've výrobě'              => ['en' => 'in production',            'es' => 'en producción'],
    'připravena k expedici'  => ['en' => 'ready for dispatch',       'es' => 'listo para el envío'],
    'EXPEDOVÁNA'             => ['en' => 'DISPATCHED',               'es' => 'ENVIADO'],
    'DORUČENA'               => ['en' => 'DELIVERED',                'es' => 'ENTREGADO'],
    'ZRUŠENA'                => ['en' => 'CANCELLED',                'es' => 'CANCELADO'],
    // Push notifikace
    'Objednávka přijata'     => ['en' => 'Order received',           'es' => 'Pedido recibido'],
    'Vaše obj. {cislo} byla přijata. Dodání: {datum}' => ['en' => 'Your order {cislo} has been received. Delivery: {datum}', 'es' => 'Hemos recibido su pedido {cislo}. Entrega: {datum}'],
    'Objednávka {cislo}'     => ['en' => 'Order {cislo}',            'es' => 'Pedido {cislo}'],
    'Stav: {stav} · Dodání: {datum}' => ['en' => 'Status: {stav} · Delivery: {datum}', 'es' => 'Estado: {stav} · Entrega: {datum}'],
    // Úprava / zrušení objednávky (notifikace_zmena_objednavky)
    'Objednávka #{cislo} byla ZRUŠENA'  => ['en' => 'Order #{cislo} has been CANCELLED', 'es' => 'El pedido n.º {cislo} ha sido CANCELADO'],
    'Objednávka #{cislo} byla UPRAVENA' => ['en' => 'Order #{cislo} has been CHANGED',   'es' => 'El pedido n.º {cislo} ha sido MODIFICADO'],
    'Vážení,'                => ['en' => 'Dear customer,',           'es' => 'Estimado cliente:'],
    'objednávka #{cislo} (datum dodání {datum}) byla zrušena.' => ['en' => 'Order #{cislo} (delivery date {datum}) has been cancelled.', 'es' => 'El pedido n.º {cislo} (fecha de entrega {datum}) ha sido cancelado.'],
    'Změnu provedl: {kdo}'   => ['en' => 'Changed by: {kdo}',        'es' => 'Modificado por: {kdo}'],
    'Pokud k tomu došlo omylem, vytvořte prosím novou objednávku.' => ['en' => 'If this happened by mistake, please place a new order.', 'es' => 'Si se trata de un error, realice por favor un nuevo pedido.'],
    'S pozdravem'            => ['en' => 'Kind regards',             'es' => 'Atentamente'],
    'v objednávce #{cislo} (datum dodání {datum}) byly provedeny tyto změny:' => ['en' => 'The following changes have been made to order #{cislo} (delivery date {datum}):', 'es' => 'En el pedido n.º {cislo} (fecha de entrega {datum}) se han realizado los siguientes cambios:'],
    'PŘIDÁNO:'               => ['en' => 'ADDED:',                   'es' => 'AÑADIDO:'],
    'ZMĚNĚNO:'               => ['en' => 'CHANGED:',                 'es' => 'MODIFICADO:'],
    'ODEBRÁNO:'              => ['en' => 'REMOVED:',                 'es' => 'ELIMINADO:'],
    'Původní částka:'        => ['en' => 'Original amount:',         'es' => 'Importe original:'],
    'Nová částka:'           => ['en' => 'New amount:',              'es' => 'Nuevo importe:'],
    'administrace'           => ['en' => 'administration',           'es' => 'administración'],
    // Voucher (admin_vouchers.php)
    'Slevový voucher'        => ['en' => 'Discount voucher',         'es' => 'Vale de descuento'],
    'Dárková karta'          => ['en' => 'Gift card',                'es' => 'Tarjeta regalo'],
    'Dárkový voucher'        => ['en' => 'Gift voucher',             'es' => 'Vale regalo'],
    'Dobrý den, posíláme Vám voucher k uplatnění při nákupu:' => ['en' => 'Hello, please find below your voucher to redeem with your next purchase:', 'es' => 'Buenos días: le enviamos un vale para canjear en su compra:'],
    'Kód voucheru'           => ['en' => 'Voucher code',             'es' => 'Código del vale'],
    'Platnost do {datum}'    => ['en' => 'Valid until {datum}',      'es' => 'Válido hasta el {datum}'],
    'Bez omezení platnosti'  => ['en' => 'No expiry date',           'es' => 'Sin fecha de caducidad'],
    'Voucher uplatníte na prodejně nebo při objednávce — stačí nahlásit kód.' => ['en' => 'You can redeem the voucher in our shop or with your order — just quote the code.', 'es' => 'Puede canjear el vale en la tienda o al hacer un pedido; basta con indicar el código.'],
    'Voucher uplatníte na prodejně nebo při objednávce.' => ['en' => 'You can redeem the voucher in our shop or with your order.', 'es' => 'Puede canjear el vale en la tienda o al hacer un pedido.'],
    'Hodnota'                => ['en' => 'Value',                    'es' => 'Valor'],
    // Online objednávka (web_objednavky.php) + vyzvednutí (admin_pos.php)
    'Objednávka {cislo} přijata' => ['en' => 'Order {cislo} received', 'es' => 'Pedido {cislo} recibido'],
    'Dobrý den, {jmeno},'    => ['en' => 'Hello {jmeno},',           'es' => 'Buenos días, {jmeno}:'],
    'děkujeme, vaši objednávku {cislo} jsme přijali.' => ['en' => 'Thank you, we have received your order {cislo}.', 'es' => 'Gracias, hemos recibido su pedido {cislo}.'],
    'Doručíme ji na: {adresa}' => ['en' => 'We will deliver it to: {adresa}', 'es' => 'Lo entregaremos en: {adresa}'],
    'Vyzvednutí:'            => ['en' => 'Pickup:',                  'es' => 'Recogida:'],
    'Kdy:'                   => ['en' => 'When:',                    'es' => 'Cuándo:'],
    'K úhradě při převzetí:' => ['en' => 'Payable on delivery:',     'es' => 'A pagar en la entrega:'],
    'K úhradě při vyzvednutí:' => ['en' => 'Payable on pickup:',     'es' => 'A pagar en la recogida:'],
    'Stav objednávky:'       => ['en' => 'Order status:',            'es' => 'Estado del pedido:'],
    'Děkujeme'               => ['en' => 'Thank you',                'es' => 'Gracias'],
    'Vaše objednávka {cislo} je připravena k vyzvednutí' => ['en' => 'Your order {cislo} is ready for pickup', 'es' => 'Su pedido {cislo} está listo para recoger'],
    'Dobrý den'              => ['en' => 'Hello',                    'es' => 'Buenos días'],
    'vaše objednávka {cislo} je připravena k vyzvednutí.' => ['en' => 'Your order {cislo} is ready for pickup.', 'es' => 'Su pedido {cislo} está listo para recoger.'],
];

/** Měrné jednotky, které se na dokladu překládají (ostatní — kg, g, l, ml… — jsou mezinárodní). */
const APPEK_I18N_UNITS = [
    'ks'  => ['en' => 'pcs',  'es' => 'ud.'],
    'kus' => ['en' => 'pcs',  'es' => 'ud.'],
    'bal' => ['en' => 'pack', 'es' => 'paq.'],
];

/** Vrátí 'cs'|'en'|'es', nebo null pro neplatnou hodnotu. */
function doc_lang_normalize($lang): ?string {
    $l = strtolower(trim((string) $lang));
    return in_array($l, APPEK_DOC_LANGS, true) ? $l : null;
}

/** Výchozí jazyk dokladů firmy (Nastavení → Údaje firmy), jinak čeština. */
function doc_lang_firma(PDO $pdo): string {
    static $cache = null;
    if ($cache !== null) return $cache;
    $v = null;
    try { $v = doc_lang_normalize(nastaveni_get($pdo, 'firma_jazyk_dokladu', '')); } catch (Throwable $e) { /* bez nastavení */ }
    return $cache = ($v ?? 'cs');
}

/**
 * Jazyk dokladu pro odběratele. Explicitní volba (?lang= nebo parametr) má přednost,
 * pak jazyk u odběratele, pak výchozí jazyk dokladů firmy.
 */
function doc_lang_resolve(PDO $pdo, ?int $odberatelId = null, $explicit = null): string {
    $x = doc_lang_normalize($explicit ?? ($_GET['lang'] ?? ''));
    if ($x !== null) return $x;
    if ($odberatelId) {
        try {
            $st = $pdo->prepare('SELECT jazyk_dokladu FROM odberatele WHERE id = ?');
            $st->execute([$odberatelId]);
            $x = doc_lang_normalize($st->fetchColumn());
            if ($x !== null) return $x;
        } catch (Throwable $e) { /* sloupec ještě nevznikl → výchozí firmy */ }
    }
    return doc_lang_firma($pdo);
}

/** Nastaví jazyk právě vykreslovaného dokladu (u hromadného tisku před každým dokladem). */
function doc_lang_set(string $lang): void {
    $GLOBALS['__appek_doc_lang'] = doc_lang_normalize($lang) ?? 'cs';
}

/** Jazyk právě vykreslovaného dokladu. */
function doc_lang(): string {
    return $GLOBALS['__appek_doc_lang'] ?? 'cs';
}

/** Alias původního názvu (aktuální jazyk dokladu). */
function _current_lang(): string {
    return doc_lang();
}

/** Překlad do jazyka aktuálního dokladu; {klic} v textu nahradí hodnotami z $vars. */
function _t(string $key, array $vars = []): string {
    return _t_lang(doc_lang(), $key, $vars);
}

/** Překlad do explicitního jazyka (např. e-mail podle odběratele). Chybějící překlad = český originál. */
function _t_lang(string $lang, string $key, array $vars = []): string {
    $s = ($lang === 'cs') ? $key : (APPEK_I18N[$key][$lang] ?? $key);
    foreach ($vars as $k => $v) $s = str_replace('{' . $k . '}', (string) $v, $s);
    return $s;
}

/** Datum podle jazyka: cs 15. 11. 2026 (= fmt_date) · en 15 Nov 2026 · es 15/11/2026. */
function _fmt_date_loc($date, ?string $lang = null, bool $withTime = false): string {
    if ($date === null || $date === '' || $date === false) return '';
    $ts = is_int($date) ? $date : strtotime((string) $date);
    if ($ts === false) return '';
    $fmt = ['cs' => 'j. n. Y', 'en' => 'j M Y', 'es' => 'd/m/Y'][$lang ?? doc_lang()] ?? 'j. n. Y';
    return date($fmt . ($withTime ? ' H:i' : ''), $ts);
}

/** Desetinný a tisícový oddělovač podle jazyka. */
function _num_seps(?string $lang = null): array {
    return ['cs' => [',', ' '], 'en' => ['.', ','], 'es' => [',', '.']][$lang ?? doc_lang()] ?? [',', ' '];
}

/** Číslo s pevným počtem desetinných míst podle jazyka (cs = number_format($n, $d, ',', ' ')). */
function _fmt_num_loc($n, int $decimals = 2, ?string $lang = null): string {
    [$dec, $thou] = _num_seps($lang);
    return number_format((float) $n, $decimals, $dec, $thou);
}

/**
 * Částka v dané měně (výchozí CZK): cs 1 234,50 Kč (= fmt_kc) · en 1,234.50 CZK · es 1.234,50 CZK;
 * EUR → „1 234,50 €" / „1,234.50 EUR". Číslo se NEpřepočítává (na to je doc_money).
 */
function _fmt_money_loc($n, ?string $lang = null, string $kod = 'CZK'): string {
    $lang = $lang ?? doc_lang();
    $kod = strtoupper($kod);
    if ($kod === 'CZK') $suf = $lang === 'cs' ? 'Kč' : 'CZK';
    elseif ($kod === 'EUR') $suf = $lang === 'en' ? 'EUR' : '€';
    else $suf = $kod;
    return _fmt_num_loc($n, 2, $lang) . ' ' . $suf;
}

/** Měna právě renderovaného dokladu (výstup mena_doklad()); default CZK bez přepočtu. */
function doc_mena_set(array $m): void { $GLOBALS['__appek_doc_mena'] = $m; }
function doc_mena(): array {
    return $GLOBALS['__appek_doc_mena'] ?? ['mena' => 'CZK', 'mena_kurz' => 1.0, 'mena_zaklad' => 'CZK', 'k_doklad' => 1.0, 'k_czk' => 1.0, 'symbol' => 'Kč'];
}
/** Uložená částka → měna dokladu (zaokrouhleno na 2 místa). */
function doc_castka($n): float { return round((float) $n * (float) doc_mena()['k_doklad'], 2); }
/** Uložená částka → naformátovaná v měně a jazyce dokladu. */
function doc_money($n): string { return _fmt_money_loc(doc_castka($n), null, doc_mena()['mena']); }
/** Částka UŽ v měně dokladu → naformátovaná (bez dalšího přepočtu). */
function doc_money_raw($n): string { return _fmt_money_loc($n, null, doc_mena()['mena']); }

/** Množství: celé číslo bez desetin (= fmt_ks), jinak až 3 desetinná místa bez koncových nul (2,5). */
function _fmt_qty_loc($n, ?string $lang = null): string {
    [$dec, $thou] = _num_seps($lang);
    $r = round((float) $n, 3);
    if (abs($r - round($r)) < 0.0000001) return number_format($r, 0, $dec, $thou);
    return rtrim(rtrim(number_format($r, 3, $dec, $thou), '0'), $dec);
}

/** Měrná jednotka (ks → pcs / ud.); neznámé jednotky beze změny. */
function _unit_loc($unit, ?string $lang = null): string {
    $u = (string) $unit;
    $lang = $lang ?? doc_lang();
    if ($lang === 'cs') return $u;
    return APPEK_I18N_UNITS[mb_strtolower($u)][$lang] ?? $u;
}
