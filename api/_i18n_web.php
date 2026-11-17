<?php
/**
 * 🆕 v3.0.645 — 🌍 Veřejné stránky v jazycích: platba odkazem/QR (pay/) a online objednávka (objednat/).
 *
 * Jazyky: cs (výchozí, = klíče slovníku) · en · es · de · sk.
 * Výběr jazyka stránky (web_lang_resolve):
 *   1. ?lang= v URL (přepínač na stránce, odkaz z e-mailu)
 *   2. jazyk odběratele/dokladu (pay/: odberatele.jazyk_dokladu objednávky, ke které platba patří)
 *   3. Accept-Language prohlížeče
 *   4. cs
 * Výchozí jazyk dokladů FIRMY (firma_jazyk_dokladu) se tu záměrně nepoužívá — host u stolu / anonymní
 * zákazník má dostat jazyk svého prohlížeče, ne jazyk faktur firmy.
 *
 * Soubor je samostatný (žádné závislosti, jen konstanty a funkce) → jde vložit i ze stránky bez DB
 * (objednat/index.php). Přes web je chráněný jako ostatní api/_*.php (api/.htaccess FilesMatch "^_").
 *
 * Slovník:
 *   APPEK_WEB_I18N      — texty stránek (přesná shoda klíče)
 *   APPEK_WEB_I18N_SRV  — hlášky serveru (pay_qr.php, web_objednavky.php, _voucher_lib.php, _mena_lib.php),
 *                         stránka je překládá v JS: přesná shoda, nebo šablona s {promenna} (regex).
 *                         Neznámá hláška → v češtině originál, v jiném jazyce obecná „Něco se nepovedlo…".
 *   APPEK_WEB_I18N_MAIL — potvrzovací e-mail web objednávky pro de/sk (cs/en/es řeší _i18n.php).
 * Chybějící překlad = český originál.
 */

const APPEK_WEB_LANGS = ['cs', 'en', 'es', 'de', 'sk'];

/** Popisky přepínače (vlastní jméno jazyka). */
const APPEK_WEB_LANG_NAMES = ['cs' => 'Čeština', 'en' => 'English', 'es' => 'Español', 'de' => 'Deutsch', 'sk' => 'Slovenčina'];

/** Locale pro Intl (toLocaleString) v JS. */
const APPEK_WEB_LOCALES = ['cs' => 'cs-CZ', 'en' => 'en-GB', 'es' => 'es-ES', 'de' => 'de-DE', 'sk' => 'sk-SK'];

const APPEK_WEB_I18N = [
    // ── společné
    'Jazyk'                         => ['en' => 'Language', 'es' => 'Idioma', 'de' => 'Sprache', 'sk' => 'Jazyk'],
    'Chyba'                         => ['en' => 'Error', 'es' => 'Error', 'de' => 'Fehler', 'sk' => 'Chyba'],
    'Chyba {n}'                     => ['en' => 'Error {n}', 'es' => 'Error {n}', 'de' => 'Fehler {n}', 'sk' => 'Chyba {n}'],
    'Něco se nepovedlo. Zkuste to prosím znovu.' => ['en' => 'Something went wrong. Please try again.', 'es' => 'Algo ha salido mal. Inténtelo de nuevo.', 'de' => 'Etwas ist schiefgelaufen. Bitte versuchen Sie es erneut.', 'sk' => 'Niečo sa nepodarilo. Skúste to, prosím, znova.'],
    'Děkujeme'                      => ['en' => 'Thank you', 'es' => 'Gracias', 'de' => 'Vielen Dank', 'sk' => 'Ďakujeme'],
    'Zaplaceno'                     => ['en' => 'Paid', 'es' => 'Pagado', 'de' => 'Bezahlt', 'sk' => 'Zaplatené'],

    // ── pay/ — platba odkazem / QR
    'Zaplatit účtenku'              => ['en' => 'Pay the bill', 'es' => 'Pagar la cuenta', 'de' => 'Rechnung bezahlen', 'sk' => 'Zaplatiť účet'],
    'Neplatný platební QR kód'      => ['en' => 'Invalid payment QR code', 'es' => 'Código QR de pago no válido', 'de' => 'Ungültiger Zahlungs-QR-Code', 'sk' => 'Neplatný platobný QR kód'],
    'Načítám účtenku…'              => ['en' => 'Loading the bill…', 'es' => 'Cargando la cuenta…', 'de' => 'Rechnung wird geladen…', 'sk' => 'Načítavam účet…'],
    'Účtenka nenalezena'            => ['en' => 'Bill not found', 'es' => 'Cuenta no encontrada', 'de' => 'Rechnung nicht gefunden', 'sk' => 'Účet sa nenašiel'],
    'Platba úspěšná'                => ['en' => 'Payment successful', 'es' => 'Pago realizado correctamente', 'de' => 'Zahlung erfolgreich', 'sk' => 'Platba bola úspešná'],
    'Účtenka'                       => ['en' => 'Bill', 'es' => 'Cuenta', 'de' => 'Rechnung', 'sk' => 'Účet'],
    'Částka:'                       => ['en' => 'Amount:', 'es' => 'Importe:', 'de' => 'Betrag:', 'sk' => 'Suma:'],
    'Zaplaceno:'                    => ['en' => 'Paid on:', 'es' => 'Pagado el:', 'de' => 'Bezahlt am:', 'sk' => 'Zaplatené:'],
    'Metoda:'                       => ['en' => 'Method:', 'es' => 'Método:', 'de' => 'Zahlungsart:', 'sk' => 'Spôsob:'],
    'hotovost'                      => ['en' => 'cash', 'es' => 'efectivo', 'de' => 'Bargeld', 'sk' => 'hotovosť'],
    'kartou (terminál)'             => ['en' => 'card (terminal)', 'es' => 'tarjeta (terminal)', 'de' => 'Karte (Terminal)', 'sk' => 'kartou (terminál)'],
    'voucher'                       => ['en' => 'voucher', 'es' => 'vale', 'de' => 'Gutschein', 'sk' => 'poukážka'],
    'Čekám na číšníka'              => ['en' => 'Waiting for the waiter', 'es' => 'Esperando al camarero', 'de' => 'Warten auf die Bedienung', 'sk' => 'Čakám na čašníka'],
    'Číšník je informován'          => ['en' => 'The waiter has been notified', 'es' => 'Se ha avisado al camarero', 'de' => 'Die Bedienung wurde informiert', 'sk' => 'Čašník je informovaný'],
    'Přijde si pro platbu hotovostí.' => ['en' => 'They will come to collect your cash payment.', 'es' => 'Vendrá a cobrarle en efectivo.', 'de' => 'Die Bedienung kommt gleich und kassiert bar.', 'sk' => 'Príde si po platbu v hotovosti.'],
    'Stránka se obnoví automaticky.' => ['en' => 'This page will refresh automatically.', 'es' => 'La página se actualizará automáticamente.', 'de' => 'Die Seite wird automatisch aktualisiert.', 'sk' => 'Stránka sa obnoví automaticky.'],
    'Platba se zpracovává… stránka se obnoví automaticky.' => ['en' => 'Your payment is being processed… this page will refresh automatically.', 'es' => 'Su pago se está procesando… la página se actualizará automáticamente.', 'de' => 'Ihre Zahlung wird verarbeitet … die Seite wird automatisch aktualisiert.', 'sk' => 'Platba sa spracúva… stránka sa obnoví automaticky.'],
    'Platba se nezdařila nebo byla zamítnuta. Zkuste to prosím znovu.' => ['en' => 'The payment failed or was declined. Please try again.', 'es' => 'El pago no se ha completado o ha sido rechazado. Inténtelo de nuevo.', 'de' => 'Die Zahlung ist fehlgeschlagen oder wurde abgelehnt. Bitte versuchen Sie es erneut.', 'sk' => 'Platba sa nepodarila alebo bola zamietnutá. Skúste to, prosím, znova.'],
    'Vybrat platbu'                 => ['en' => 'Choose a payment method', 'es' => 'Elija la forma de pago', 'de' => 'Zahlungsart wählen', 'sk' => 'Vyberte spôsob platby'],
    'Kartou online (Stripe)'        => ['en' => 'Pay by card online (Stripe)', 'es' => 'Pagar con tarjeta en línea (Stripe)', 'de' => 'Online mit Karte bezahlen (Stripe)', 'sk' => 'Kartou online (Stripe)'],
    'Kartou / bankou (GoPay)'       => ['en' => 'Card / bank transfer (GoPay)', 'es' => 'Tarjeta / banco (GoPay)', 'de' => 'Karte / Bank (GoPay)', 'sk' => 'Kartou / bankou (GoPay)'],
    'Zaplatím hotovostí číšníkovi'  => ['en' => 'I will pay the waiter in cash', 'es' => 'Pagaré en efectivo al camarero', 'de' => 'Ich zahle bar bei der Bedienung', 'sk' => 'Zaplatím v hotovosti čašníkovi'],
    'Online platby nejsou nakonfigurované.' => ['en' => 'Online payments are not set up.', 'es' => 'Los pagos en línea no están configurados.', 'de' => 'Online-Zahlungen sind nicht eingerichtet.', 'sk' => 'Online platby nie sú nastavené.'],
    'Označit jako "platím hotovostí číšníkovi"? Číšník bude informován a přijde si pro platbu.' => ['en' => 'Mark as "I will pay the waiter in cash"? The waiter will be notified and will come to collect the payment.', 'es' => '¿Marcar como «pagaré en efectivo al camarero»? Se avisará al camarero y vendrá a cobrarle.', 'de' => 'Als „Ich zahle bar bei der Bedienung" markieren? Die Bedienung wird informiert und kommt zum Kassieren.', 'sk' => 'Označiť ako „zaplatím v hotovosti čašníkovi"? Čašník bude informovaný a príde si po platbu.'],
    'Stripe init selhal'            => ['en' => 'The Stripe payment could not be started', 'es' => 'No se ha podido iniciar el pago con Stripe', 'de' => 'Die Stripe-Zahlung konnte nicht gestartet werden', 'sk' => 'Platbu cez Stripe sa nepodarilo spustiť'],
    'GoPay init selhal'             => ['en' => 'The GoPay payment could not be started', 'es' => 'No se ha podido iniciar el pago con GoPay', 'de' => 'Die GoPay-Zahlung konnte nicht gestartet werden', 'sk' => 'Platbu cez GoPay sa nepodarilo spustiť'],

    // ── objednat/ — online objednávka
    'Objednávka online'             => ['en' => 'Online order', 'es' => 'Pedido en línea', 'de' => 'Online-Bestellung', 'sk' => 'Objednávka online'],
    'Načítám…'                      => ['en' => 'Loading…', 'es' => 'Cargando…', 'de' => 'Wird geladen…', 'sk' => 'Načítavam…'],
    'Pokračovat'                    => ['en' => 'Continue', 'es' => 'Continuar', 'de' => 'Weiter', 'sk' => 'Pokračovať'],
    'Objednávka — {firma}'          => ['en' => 'Order — {firma}', 'es' => 'Pedido — {firma}', 'de' => 'Bestellung — {firma}', 'sk' => 'Objednávka — {firma}'],
    'Dnes ({den})'                  => ['en' => 'Today ({den})', 'es' => 'Hoy ({den})', 'de' => 'Heute ({den})', 'sk' => 'Dnes ({den})'],
    'Zítra ({den})'                 => ['en' => 'Tomorrow ({den})', 'es' => 'Mañana ({den})', 'de' => 'Morgen ({den})', 'sk' => 'Zajtra ({den})'],
    'dnes'                          => ['en' => 'today', 'es' => 'hoy', 'de' => 'heute', 'sk' => 'dnes'],
    'zítra'                         => ['en' => 'tomorrow', 'es' => 'mañana', 'de' => 'morgen', 'sk' => 'zajtra'],
    'vyzvednutí'                    => ['en' => 'pickup', 'es' => 'recogida', 'de' => 'Abholung', 'sk' => 'vyzdvihnutie'],
    'rozvoz'                        => ['en' => 'delivery', 'es' => 'entrega a domicilio', 'de' => 'Lieferung', 'sk' => 'rozvoz'],
    '{a} i {b}'                     => ['en' => '{a} and {b}', 'es' => '{a} y {b}', 'de' => '{a} und {b}', 'sk' => '{a} aj {b}'],
    'Online objednávka · {typy} · {od}–{do}' => ['en' => 'Online ordering · {typy} · {od}–{do}', 'es' => 'Pedidos en línea · {typy} · {od}–{do}', 'de' => 'Online-Bestellung · {typy} · {od}–{do}', 'sk' => 'Online objednávka · {typy} · {od}–{do}'],
    'Min. objednávka {castka}'      => ['en' => 'Minimum order {castka}', 'es' => 'Pedido mínimo {castka}', 'de' => 'Mindestbestellwert {castka}', 'sk' => 'Min. objednávka {castka}'],
    'Rozvoz {cena}'                 => ['en' => 'Delivery {cena}', 'es' => 'Entrega {cena}', 'de' => 'Lieferung {cena}', 'sk' => 'Rozvoz {cena}'],
    'Rozvoz {cena}, od {od} zdarma' => ['en' => 'Delivery {cena}, free from {od}', 'es' => 'Entrega {cena}, gratis a partir de {od}', 'de' => 'Lieferung {cena}, ab {od} kostenlos', 'sk' => 'Rozvoz {cena}, od {od} zadarmo'],
    'Rozvoz zdarma'                 => ['en' => 'Free delivery', 'es' => 'Entrega gratuita', 'de' => 'Kostenlose Lieferung', 'sk' => 'Rozvoz zadarmo'],
    'Platba při převzetí'           => ['en' => 'Pay on pickup or delivery', 'es' => 'Pago a la entrega', 'de' => 'Bezahlung bei Übergabe', 'sk' => 'Platba pri prevzatí'],
    'Nejdřív {den} v {cas}'         => ['en' => 'Earliest {den} at {cas}', 'es' => 'Lo antes posible: {den} a las {cas}', 'de' => 'Frühestens {den} um {cas}', 'sk' => 'Najskôr {den} o {cas}'],
    'Momentálně nelze objednat — dnes už je zavřeno a předobjednávky nejsou k dispozici.' => ['en' => 'Ordering is not possible right now — we are closed for today and pre-orders are not available.', 'es' => 'Ahora mismo no es posible hacer pedidos: hoy ya hemos cerrado y no se admiten pedidos anticipados.', 'de' => 'Bestellen ist momentan nicht möglich – heute ist bereits geschlossen und Vorbestellungen sind nicht verfügbar.', 'sk' => 'Momentálne nie je možné objednať — dnes už máme zatvorené a predobjednávky nie sú k dispozícii.'],
    'Ostatní'                       => ['en' => 'Other', 'es' => 'Otros', 'de' => 'Sonstiges', 'sk' => 'Ostatné'],
    'Nabídka je zatím prázdná'      => ['en' => 'The menu is empty for now', 'es' => 'La carta está vacía por ahora', 'de' => 'Das Angebot ist noch leer', 'sk' => 'Ponuka je zatiaľ prázdna'],
    'Moje poslední objednávka'      => ['en' => 'My last order', 'es' => 'Mi último pedido', 'de' => 'Meine letzte Bestellung', 'sk' => 'Moja posledná objednávka'],
    'Alergeny:'                     => ['en' => 'Allergens:', 'es' => 'Alérgenos:', 'de' => 'Allergene:', 'sk' => 'Alergény:'],
    'Ubrat'                         => ['en' => 'Remove one', 'es' => 'Quitar uno', 'de' => 'Eins weniger', 'sk' => 'Ubrať'],
    'Přidat'                        => ['en' => 'Add', 'es' => 'Añadir', 'de' => 'Hinzufügen', 'sk' => 'Pridať'],
    '{n} ks v košíku'               => ['en' => 'Items in cart: {n}', 'es' => 'Artículos en el carrito: {n}', 'de' => 'Artikel im Warenkorb: {n}', 'sk' => '{n} ks v košíku'],
    'Dokončit objednávku'           => ['en' => 'Complete your order', 'es' => 'Finalizar el pedido', 'de' => 'Bestellung abschließen', 'sk' => 'Dokončiť objednávku'],
    'Zavřít'                        => ['en' => 'Close', 'es' => 'Cerrar', 'de' => 'Schließen', 'sk' => 'Zavrieť'],
    'Vyzvednu si'                   => ['en' => "I'll pick it up", 'es' => 'Lo recojo yo', 'de' => 'Ich hole ab', 'sk' => 'Vyzdvihnem si'],
    'Rozvoz'                        => ['en' => 'Delivery', 'es' => 'Entrega a domicilio', 'de' => 'Lieferung', 'sk' => 'Rozvoz'],
    'Den'                           => ['en' => 'Day', 'es' => 'Día', 'de' => 'Tag', 'sk' => 'Deň'],
    'Čas'                           => ['en' => 'Time', 'es' => 'Hora', 'de' => 'Uhrzeit', 'sk' => 'Čas'],
    'Ulice a číslo'                 => ['en' => 'Street and number', 'es' => 'Calle y número', 'de' => 'Straße und Hausnummer', 'sk' => 'Ulica a číslo'],
    'PSČ'                           => ['en' => 'Postcode', 'es' => 'Código postal', 'de' => 'PLZ', 'sk' => 'PSČ'],
    'Město'                         => ['en' => 'City', 'es' => 'Ciudad', 'de' => 'Ort', 'sk' => 'Mesto'],
    'Jméno'                         => ['en' => 'Name', 'es' => 'Nombre', 'de' => 'Name', 'sk' => 'Meno'],
    'Telefon'                       => ['en' => 'Phone', 'es' => 'Teléfono', 'de' => 'Telefon', 'sk' => 'Telefón'],
    'E-mail (potvrzení)'            => ['en' => 'E-mail (for confirmation)', 'es' => 'Correo electrónico (confirmación)', 'de' => 'E-Mail (Bestätigung)', 'sk' => 'E-mail (potvrdenie)'],
    'Poznámka (nepovinné)'          => ['en' => 'Note (optional)', 'es' => 'Nota (opcional)', 'de' => 'Anmerkung (optional)', 'sk' => 'Poznámka (nepovinné)'],
    // [ ] = text odkazu na zásady zpracování osobních údajů
    'Souhlasím se [zpracováním osobních údajů] pro vyřízení objednávky.' => ['en' => 'I agree to the [processing of my personal data] for the purpose of handling my order.', 'es' => 'Acepto el [tratamiento de mis datos personales] para la gestión del pedido.', 'de' => 'Ich stimme der [Verarbeitung meiner personenbezogenen Daten] zur Abwicklung der Bestellung zu.', 'sk' => 'Súhlasím so [spracúvaním osobných údajov] na účely vybavenia objednávky.'],
    'Objednat'                      => ['en' => 'Place order', 'es' => 'Hacer el pedido', 'de' => 'Bestellen', 'sk' => 'Objednať'],
    'Doručit v'                     => ['en' => 'Deliver at', 'es' => 'Entregar a las', 'de' => 'Lieferung um', 'sk' => 'Doručiť o'],
    'Vyzvednu v'                    => ['en' => 'Pick up at', 'es' => 'Recoger a las', 'de' => 'Abholung um', 'sk' => 'Vyzdvihnem o'],
    'Platíte až při převzetí (hotově nebo kartou).' => ['en' => 'You pay on delivery (cash or card).', 'es' => 'Pagará a la entrega (en efectivo o con tarjeta).', 'de' => 'Sie bezahlen erst bei der Übergabe (bar oder mit Karte).', 'sk' => 'Platíte až pri prevzatí (v hotovosti alebo kartou).'],
    'Platíte až při vyzvednutí (hotově nebo kartou).' => ['en' => 'You pay when you pick up (cash or card).', 'es' => 'Pagará al recoger el pedido (en efectivo o con tarjeta).', 'de' => 'Sie bezahlen erst bei der Abholung (bar oder mit Karte).', 'sk' => 'Platíte až pri vyzdvihnutí (v hotovosti alebo kartou).'],
    'Doprava'                       => ['en' => 'Delivery fee', 'es' => 'Gastos de envío', 'de' => 'Liefergebühr', 'sk' => 'Doprava'],
    'zdarma'                        => ['en' => 'free', 'es' => 'gratis', 'de' => 'kostenlos', 'sk' => 'zadarmo'],
    'Uhrazeno voucherem'            => ['en' => 'Paid by voucher', 'es' => 'Pagado con vale', 'de' => 'Mit Gutschein bezahlt', 'sk' => 'Uhradené poukážkou'],
    'Sleva'                         => ['en' => 'Discount', 'es' => 'Descuento', 'de' => 'Rabatt', 'sk' => 'Zľava'],
    'Celkem'                        => ['en' => 'Total', 'es' => 'Total', 'de' => 'Gesamt', 'sk' => 'Spolu'],
    'Platit v měně'                 => ['en' => 'Pay in currency', 'es' => 'Pagar en', 'de' => 'Bezahlen in', 'sk' => 'Platiť v mene'],
    'K úhradě v {mena}'             => ['en' => 'Amount due in {mena}', 'es' => 'Importe a pagar en {mena}', 'de' => 'Zu zahlen in {mena}', 'sk' => 'Na úhradu v {mena}'],
    'Kurz 1 {sym} = {kurz}'         => ['en' => 'Exchange rate 1 {sym} = {kurz}', 'es' => 'Tipo de cambio 1 {sym} = {kurz}', 'de' => 'Wechselkurs 1 {sym} = {kurz}', 'sk' => 'Kurz 1 {sym} = {kurz}'],
    'sleva z kódu se odečte v {mena}' => ['en' => 'the code discount will be deducted in {mena}', 'es' => 'el descuento del código se aplicará en {mena}', 'de' => 'der Rabatt aus dem Code wird in {mena} abgezogen', 'sk' => 'zľava z kódu sa odpočíta v {mena}'],
    'Kód'                           => ['en' => 'Code', 'es' => 'Código', 'de' => 'Code', 'sk' => 'Kód'],
    'Odebrat kód'                   => ['en' => 'Remove code', 'es' => 'Quitar el código', 'de' => 'Code entfernen', 'sk' => 'Odobrať kód'],
    'Slevový kód / voucher (nepovinné)' => ['en' => 'Discount code / voucher (optional)', 'es' => 'Código de descuento / vale (opcional)', 'de' => 'Rabattcode / Gutschein (optional)', 'sk' => 'Zľavový kód / poukážka (nepovinné)'],
    'Uplatnit'                      => ['en' => 'Apply', 'es' => 'Aplicar', 'de' => 'Einlösen', 'sk' => 'Uplatniť'],
    'Zadejte slevový kód.'          => ['en' => 'Please enter a discount code.', 'es' => 'Introduzca un código de descuento.', 'de' => 'Bitte geben Sie einen Rabattcode ein.', 'sk' => 'Zadajte zľavový kód.'],
    'Ověřuji…'                      => ['en' => 'Checking…', 'es' => 'Comprobando…', 'de' => 'Wird geprüft…', 'sk' => 'Overujem…'],
    'Zpracování osobních údajů'     => ['en' => 'Personal data processing', 'es' => 'Tratamiento de datos personales', 'de' => 'Verarbeitung personenbezogener Daten', 'sk' => 'Spracúvanie osobných údajov'],
    'Vyplňte jméno.'                => ['en' => 'Please enter your name.', 'es' => 'Introduzca su nombre.', 'de' => 'Bitte geben Sie Ihren Namen ein.', 'sk' => 'Vyplňte meno.'],
    'Vyplňte telefon.'              => ['en' => 'Please enter your phone number.', 'es' => 'Introduzca su número de teléfono.', 'de' => 'Bitte geben Sie Ihre Telefonnummer ein.', 'sk' => 'Vyplňte telefón.'],
    'Vyplňte adresu, PSČ a město.'  => ['en' => 'Please enter your address, postcode and city.', 'es' => 'Introduzca la dirección, el código postal y la ciudad.', 'de' => 'Bitte geben Sie Adresse, PLZ und Ort ein.', 'sk' => 'Vyplňte adresu, PSČ a mesto.'],
    'Potvrďte prosím souhlas se zpracováním údajů.' => ['en' => 'Please confirm your consent to the processing of your data.', 'es' => 'Confirme su consentimiento para el tratamiento de sus datos.', 'de' => 'Bitte bestätigen Sie Ihre Einwilligung zur Datenverarbeitung.', 'sk' => 'Potvrďte, prosím, súhlas so spracúvaním údajov.'],
    'Odesílám…'                     => ['en' => 'Sending…', 'es' => 'Enviando…', 'de' => 'Wird gesendet…', 'sk' => 'Odosielam…'],
    'Přijato'                       => ['en' => 'Received', 'es' => 'Recibido', 'de' => 'Eingegangen', 'sk' => 'Prijaté'],
    'Připravujeme'                  => ['en' => 'Being prepared', 'es' => 'En preparación', 'de' => 'In Zubereitung', 'sk' => 'Pripravujeme'],
    'Připraveno k vyzvednutí'       => ['en' => 'Ready for pickup', 'es' => 'Listo para recoger', 'de' => 'Abholbereit', 'sk' => 'Pripravené na vyzdvihnutie'],
    'Vydáno'                        => ['en' => 'Collected', 'es' => 'Recogido', 'de' => 'Abgeholt', 'sk' => 'Vydané'],
    'Na cestě'                      => ['en' => 'On the way', 'es' => 'En camino', 'de' => 'Unterwegs', 'sk' => 'Na ceste'],
    'Doručeno'                      => ['en' => 'Delivered', 'es' => 'Entregado', 'de' => 'Zugestellt', 'sk' => 'Doručené'],
    'Objednávka {cislo} — {firma}'  => ['en' => 'Order {cislo} — {firma}', 'es' => 'Pedido {cislo} — {firma}', 'de' => 'Bestellung {cislo} — {firma}', 'sk' => 'Objednávka {cislo} — {firma}'],
    'Stav objednávky'               => ['en' => 'Order status', 'es' => 'Estado del pedido', 'de' => 'Bestellstatus', 'sk' => 'Stav objednávky'],
    'Děkujeme, objednávku jsme přijali.' => ['en' => 'Thank you, we have received your order.', 'es' => 'Gracias, hemos recibido su pedido.', 'de' => 'Vielen Dank, wir haben Ihre Bestellung erhalten.', 'sk' => 'Ďakujeme, objednávku sme prijali.'],
    'Objednávka {cislo}'            => ['en' => 'Order {cislo}', 'es' => 'Pedido {cislo}', 'de' => 'Bestellung {cislo}', 'sk' => 'Objednávka {cislo}'],
    'Vyzvednutí'                    => ['en' => 'Pickup', 'es' => 'Recogida', 'de' => 'Abholung', 'sk' => 'Vyzdvihnutie'],
    'K úhradě při převzetí'         => ['en' => 'Payable on delivery', 'es' => 'A pagar en la entrega', 'de' => 'Zahlbar bei Übergabe', 'sk' => 'Na úhradu pri prevzatí'],
    'K úhradě při vyzvednutí'       => ['en' => 'Payable on pickup', 'es' => 'A pagar al recoger', 'de' => 'Zahlbar bei Abholung', 'sk' => 'Na úhradu pri vyzdvihnutí'],
    'Objednávka byla zrušena. V případě dotazů nás prosím kontaktujte.' => ['en' => 'The order has been cancelled. If you have any questions, please contact us.', 'es' => 'El pedido ha sido cancelado. Si tiene alguna pregunta, póngase en contacto con nosotros.', 'de' => 'Die Bestellung wurde storniert. Bei Fragen kontaktieren Sie uns bitte.', 'sk' => 'Objednávka bola zrušená. V prípade otázok nás, prosím, kontaktujte.'],
    'Stránka se sama obnovuje.'     => ['en' => 'This page refreshes automatically.', 'es' => 'La página se actualiza automáticamente.', 'de' => 'Die Seite aktualisiert sich automatisch.', 'sk' => 'Stránka sa sama obnovuje.'],
    'Nová objednávka'               => ['en' => 'New order', 'es' => 'Nuevo pedido', 'de' => 'Neue Bestellung', 'sk' => 'Nová objednávka'],
    'Zpět na nabídku'               => ['en' => 'Back to the menu', 'es' => 'Volver a la carta', 'de' => 'Zurück zum Angebot', 'sk' => 'Späť na ponuku'],
    'Zkuste to prosím později.'     => ['en' => 'Please try again later.', 'es' => 'Inténtelo de nuevo más tarde.', 'de' => 'Bitte versuchen Sie es später erneut.', 'sk' => 'Skúste to, prosím, neskôr.'],
    'Nepodařilo se načíst nabídku'  => ['en' => 'The menu could not be loaded', 'es' => 'No se ha podido cargar la carta', 'de' => 'Das Angebot konnte nicht geladen werden', 'sk' => 'Ponuku sa nepodarilo načítať'],
];

/** Hlášky serveru zobrazované zákazníkovi (přesná shoda, nebo šablona s {promenna}). */
const APPEK_WEB_I18N_SRV = [
    // pay_qr.php
    'Neplatný nebo expirovaný token' => ['en' => 'This payment link is invalid or has expired', 'es' => 'Este enlace de pago no es válido o ha caducado', 'de' => 'Dieser Zahlungslink ist ungültig oder abgelaufen', 'sk' => 'Tento platobný odkaz je neplatný alebo mu vypršala platnosť'],
    'Neplatný token'                 => ['en' => 'Invalid payment link', 'es' => 'Enlace de pago no válido', 'de' => 'Ungültiger Zahlungslink', 'sk' => 'Neplatný platobný odkaz'],
    'Již zaplaceno'                  => ['en' => 'Already paid', 'es' => 'Ya está pagado', 'de' => 'Bereits bezahlt', 'sk' => 'Už zaplatené'],
    'Účet je už uzavřený — o platbě se domluvte s obsluhou.' => ['en' => 'This bill is already closed — please arrange payment with the staff.', 'es' => 'Esta cuenta ya está cerrada; acuerde el pago con el personal.', 'de' => 'Diese Rechnung ist bereits abgeschlossen – bitte klären Sie die Zahlung mit dem Personal.', 'sk' => 'Účet je už uzavretý — o platbe sa dohodnite s obsluhou.'],
    'Objednávka je zrušená'          => ['en' => 'The order has been cancelled', 'es' => 'El pedido ha sido cancelado', 'de' => 'Die Bestellung wurde storniert', 'sk' => 'Objednávka je zrušená'],
    'Objednávka je už zaplacená'     => ['en' => 'The order has already been paid', 'es' => 'El pedido ya está pagado', 'de' => 'Die Bestellung ist bereits bezahlt', 'sk' => 'Objednávka je už zaplatená'],
    '{brana} není zapnutý'           => ['en' => '{brana} is not enabled', 'es' => '{brana} no está activado', 'de' => '{brana} ist nicht aktiviert', 'sk' => '{brana} nie je zapnutý'],
    'Objednávka je vystavená v {mena} — měnu platby nelze změnit.' => ['en' => 'The order is issued in {mena} — the payment currency cannot be changed.', 'es' => 'El pedido está emitido en {mena}; no se puede cambiar la moneda de pago.', 'de' => 'Die Bestellung lautet auf {mena} – die Zahlungswährung kann nicht geändert werden.', 'sk' => 'Objednávka je vystavená v {mena} — menu platby nemožno zmeniť.'],
    'Není co platit'                 => ['en' => 'There is nothing to pay', 'es' => 'No hay nada que pagar', 'de' => 'Es gibt nichts zu bezahlen', 'sk' => 'Nie je čo platiť'],
    '{brana} init selhal: {chyba}'   => ['en' => 'The {brana} payment could not be started: {chyba}', 'es' => 'No se ha podido iniciar el pago con {brana}: {chyba}', 'de' => 'Die {brana}-Zahlung konnte nicht gestartet werden: {chyba}', 'sk' => 'Platbu cez {brana} sa nepodarilo spustiť: {chyba}'],
    'neznámá chyba'                  => ['en' => 'unknown error', 'es' => 'error desconocido', 'de' => 'unbekannter Fehler', 'sk' => 'neznáma chyba'],
    'Účet #{id}'                     => ['en' => 'Bill #{id}', 'es' => 'Cuenta n.º {id}', 'de' => 'Rechnung Nr. {id}', 'sk' => 'Účet č. {id}'],
    'Chybí id'                       => ['en' => 'Missing ID', 'es' => 'Falta el identificador', 'de' => 'ID fehlt', 'sk' => 'Chýba ID'],
    'Objednávka nenalezena'          => ['en' => 'Order not found', 'es' => 'Pedido no encontrado', 'de' => 'Bestellung nicht gefunden', 'sk' => 'Objednávka sa nenašla'],

    // web_objednavky.php
    'Online objednávky teď nepřijímáme' => ['en' => 'We are not accepting online orders right now', 'es' => 'En este momento no aceptamos pedidos en línea', 'de' => 'Wir nehmen derzeit keine Online-Bestellungen an', 'sk' => 'Online objednávky teraz neprijímame'],
    'Max 99 ks od jedné položky'     => ['en' => 'Maximum of 99 pcs per item', 'es' => 'Máximo 99 unidades por artículo', 'de' => 'Maximal 99 Stück pro Artikel', 'sk' => 'Max. 99 ks z jednej položky'],
    'Položka už není v nabídce — obnovte prosím stránku' => ['en' => 'An item is no longer available — please refresh the page', 'es' => 'Un artículo ya no está disponible; actualice la página', 'de' => 'Ein Artikel ist nicht mehr im Angebot – bitte laden Sie die Seite neu', 'sk' => 'Položka už nie je v ponuke — obnovte, prosím, stránku'],
    'Košík je prázdný'               => ['en' => 'Your cart is empty', 'es' => 'El carrito está vacío', 'de' => 'Der Warenkorb ist leer', 'sk' => 'Košík je prázdny'],
    'Max 50 různých položek'         => ['en' => 'Maximum of 50 different items', 'es' => 'Máximo 50 artículos distintos', 'de' => 'Maximal 50 verschiedene Artikel', 'sk' => 'Max. 50 rôznych položiek'],
    'Minimální objednávka je {castka}' => ['en' => 'The minimum order is {castka}', 'es' => 'El pedido mínimo es de {castka}', 'de' => 'Der Mindestbestellwert beträgt {castka}', 'sk' => 'Minimálna objednávka je {castka}'],
    'Příliš mnoho neplatných kódů. Zkuste to prosím za chvíli.' => ['en' => 'Too many invalid codes. Please try again in a while.', 'es' => 'Demasiados códigos no válidos. Inténtelo de nuevo dentro de un rato.', 'de' => 'Zu viele ungültige Codes. Bitte versuchen Sie es in Kürze erneut.', 'sk' => 'Príliš veľa neplatných kódov. Skúste to, prosím, o chvíľu.'],
    'Neplatný požadavek'             => ['en' => 'Invalid request', 'es' => 'Solicitud no válida', 'de' => 'Ungültige Anfrage', 'sk' => 'Neplatná požiadavka'],
    'Příliš mnoho objednávek za krátkou dobu. Zkuste to prosím později nebo zavolejte.' => ['en' => 'Too many orders in a short time. Please try again later or give us a call.', 'es' => 'Demasiados pedidos en poco tiempo. Inténtelo más tarde o llámenos.', 'de' => 'Zu viele Bestellungen in kurzer Zeit. Bitte versuchen Sie es später erneut oder rufen Sie uns an.', 'sk' => 'Príliš veľa objednávok za krátky čas. Skúste to, prosím, neskôr alebo zavolajte.'],
    'Rozvoz teď nenabízíme'          => ['en' => 'Delivery is not available at the moment', 'es' => 'En este momento no ofrecemos entrega a domicilio', 'de' => 'Lieferung bieten wir derzeit nicht an', 'sk' => 'Rozvoz teraz neponúkame'],
    'Vyzvednutí teď nenabízíme'      => ['en' => 'Pickup is not available at the moment', 'es' => 'En este momento no ofrecemos recogida', 'de' => 'Abholung bieten wir derzeit nicht an', 'sk' => 'Vyzdvihnutie teraz neponúkame'],
    'Vyplňte jméno'                  => ['en' => 'Please enter your name', 'es' => 'Introduzca su nombre', 'de' => 'Bitte geben Sie Ihren Namen ein', 'sk' => 'Vyplňte meno'],
    'Vyplňte telefon'                => ['en' => 'Please enter your phone number', 'es' => 'Introduzca su número de teléfono', 'de' => 'Bitte geben Sie Ihre Telefonnummer ein', 'sk' => 'Vyplňte telefón'],
    'Neplatný e-mail'                => ['en' => 'Invalid e-mail address', 'es' => 'Correo electrónico no válido', 'de' => 'Ungültige E-Mail-Adresse', 'sk' => 'Neplatný e-mail'],
    'Potvrďte souhlas se zpracováním údajů' => ['en' => 'Please confirm your consent to data processing', 'es' => 'Confirme su consentimiento para el tratamiento de datos', 'de' => 'Bitte bestätigen Sie Ihre Einwilligung zur Datenverarbeitung', 'sk' => 'Potvrďte súhlas so spracúvaním údajov'],
    'Vyplňte adresu, PSČ a město'    => ['en' => 'Please enter your address, postcode and city', 'es' => 'Introduzca la dirección, el código postal y la ciudad', 'de' => 'Bitte geben Sie Adresse, PLZ und Ort ein', 'sk' => 'Vyplňte adresu, PSČ a mesto'],
    'Na tuto adresu bohužel nerozvážíme (PSČ {psc})' => ['en' => 'Unfortunately, we do not deliver to this address (postcode {psc})', 'es' => 'Lo sentimos, no hacemos entregas a esta dirección (código postal {psc})', 'de' => 'An diese Adresse liefern wir leider nicht (PLZ {psc})', 'sk' => 'Na túto adresu, žiaľ, nerozvážame (PSČ {psc})'],
    'Vyberte čas'                    => ['en' => 'Please choose a time', 'es' => 'Elija una hora', 'de' => 'Bitte wählen Sie eine Uhrzeit', 'sk' => 'Vyberte čas'],
    'Vybraný čas už není dostupný — vyberte prosím jiný' => ['en' => 'The selected time is no longer available — please choose another one', 'es' => 'La hora seleccionada ya no está disponible; elija otra', 'de' => 'Die gewählte Uhrzeit ist nicht mehr verfügbar – bitte wählen Sie eine andere', 'sk' => 'Vybraný čas už nie je dostupný — vyberte, prosím, iný'],
    'Objednávku se nepodařilo uložit' => ['en' => 'The order could not be saved', 'es' => 'No se ha podido guardar el pedido', 'de' => 'Die Bestellung konnte nicht gespeichert werden', 'sk' => 'Objednávku sa nepodarilo uložiť'],

    // _voucher_lib.php (slevový kód na /objednat)
    'Voucher je zrušený'             => ['en' => 'The voucher has been cancelled', 'es' => 'El vale ha sido anulado', 'de' => 'Der Gutschein wurde storniert', 'sk' => 'Poukážka je zrušená'],
    'Voucher expiroval {datum}'      => ['en' => 'The voucher expired on {datum}', 'es' => 'El vale caducó el {datum}', 'de' => 'Der Gutschein ist am {datum} abgelaufen', 'sk' => 'Platnosť poukážky vypršala {datum}'],
    'Tento voucher nelze uplatnit zde ({kanal}). Platí jen: {kde}' => ['en' => 'This voucher cannot be used here ({kanal}). Valid only for: {kde}', 'es' => 'Este vale no se puede usar aquí ({kanal}). Solo es válido en: {kde}', 'de' => 'Dieser Gutschein kann hier nicht eingelöst werden ({kanal}). Gültig nur für: {kde}', 'sk' => 'Túto poukážku tu nemožno uplatniť ({kanal}). Platí len: {kde}'],
    'Pokladna'                       => ['en' => 'Point of sale', 'es' => 'Caja', 'de' => 'Kasse', 'sk' => 'Pokladňa'],
    'Objednávky v adminu'            => ['en' => 'Orders in administration', 'es' => 'Pedidos en la administración', 'de' => 'Bestellungen in der Verwaltung', 'sk' => 'Objednávky v administrácii'],
    'B2B portál'                     => ['en' => 'B2B portal', 'es' => 'Portal B2B', 'de' => 'B2B-Portal', 'sk' => 'B2B portál'],
    'Online objednávky'              => ['en' => 'Online orders', 'es' => 'Pedidos en línea', 'de' => 'Online-Bestellungen', 'sk' => 'Online objednávky'],
    'Tento voucher je vystavený na jméno odběratele — online ho použít nelze' => ['en' => 'This voucher is issued to a named customer and cannot be used online', 'es' => 'Este vale es nominativo y no se puede usar en línea', 'de' => 'Dieser Gutschein ist auf einen bestimmten Kunden ausgestellt und kann online nicht eingelöst werden', 'sk' => 'Táto poukážka je vystavená na meno odberateľa — online ju nemožno použiť'],
    'Slevový voucher už byl použit'  => ['en' => 'This discount voucher has already been used', 'es' => 'Este vale de descuento ya se ha utilizado', 'de' => 'Dieser Rabattgutschein wurde bereits verwendet', 'sk' => 'Zľavová poukážka už bola použitá'],
    'Voucher je již vyčerpaný'       => ['en' => 'The voucher balance has been used up', 'es' => 'El saldo del vale está agotado', 'de' => 'Das Guthaben des Gutscheins ist aufgebraucht', 'sk' => 'Poukážka je už vyčerpaná'],
    'Zadejte kód'                    => ['en' => 'Please enter the code', 'es' => 'Introduzca el código', 'de' => 'Bitte geben Sie den Code ein', 'sk' => 'Zadajte kód'],
    'Kód „{kod}" neplatí'            => ['en' => 'The code "{kod}" is not valid', 'es' => 'El código «{kod}» no es válido', 'de' => 'Der Code „{kod}" ist ungültig', 'sk' => 'Kód „{kod}" neplatí'],
    'Chybí kód voucheru'             => ['en' => 'The voucher code is missing', 'es' => 'Falta el código del vale', 'de' => 'Der Gutscheincode fehlt', 'sk' => 'Chýba kód poukážky'],
    'Voucher „{kod}" neexistuje'     => ['en' => 'The voucher "{kod}" does not exist', 'es' => 'El vale «{kod}» no existe', 'de' => 'Der Gutschein „{kod}" existiert nicht', 'sk' => 'Poukážka „{kod}" neexistuje'],
    'Objednávka nemá žádné zboží, ze kterého by šlo slevu odečíst' => ['en' => 'The order contains no goods from which the discount could be deducted', 'es' => 'El pedido no contiene productos a los que aplicar el descuento', 'de' => 'Die Bestellung enthält keine Waren, von denen der Rabatt abgezogen werden kann', 'sk' => 'Objednávka neobsahuje žiadny tovar, z ktorého by sa dala odpočítať zľava'],
    // popis uplatněného kódu (voucher_odhad)
    'Sleva {pct} %'                  => ['en' => '{pct} % discount', 'es' => 'Descuento del {pct} %', 'de' => '{pct} % Rabatt', 'sk' => 'Zľava {pct} %'],
    'Sleva {pct} % (max. {max} Kč)'  => ['en' => '{pct} % discount (max. {max} CZK)', 'es' => 'Descuento del {pct} % (máx. {max} CZK)', 'de' => '{pct} % Rabatt (max. {max} CZK)', 'sk' => 'Zľava {pct} % (max. {max} Kč)'],
    'Dárková karta — zůstatek {z} Kč' => ['en' => 'Gift card — balance {z} CZK', 'es' => 'Tarjeta regalo: saldo {z} CZK', 'de' => 'Geschenkkarte – Guthaben {z} CZK', 'sk' => 'Darčeková karta — zostatok {z} Kč'],
    'Voucher — zůstatek {z} Kč'      => ['en' => 'Voucher — balance {z} CZK', 'es' => 'Vale: saldo {z} CZK', 'de' => 'Gutschein – Guthaben {z} CZK', 'sk' => 'Poukážka — zostatok {z} Kč'],

    // _mena_lib.php (platba v cizí měně)
    'Platba v jiné měně není zapnutá.' => ['en' => 'Payment in another currency is not enabled.', 'es' => 'El pago en otra moneda no está activado.', 'de' => 'Die Zahlung in einer anderen Währung ist nicht aktiviert.', 'sk' => 'Platba v inej mene nie je zapnutá.'],
    'Platba v jiné měně není zapnutá (Nastavení → Přístupy, ceny & měna).' => ['en' => 'Payment in another currency is not enabled.', 'es' => 'El pago en otra moneda no está activado.', 'de' => 'Die Zahlung in einer anderen Währung ist nicht aktiviert.', 'sk' => 'Platba v inej mene nie je zapnutá.'],
    'Nepodporovaná měna {mena}'      => ['en' => 'Unsupported currency {mena}', 'es' => 'Moneda no admitida: {mena}', 'de' => 'Nicht unterstützte Währung {mena}', 'sk' => 'Nepodporovaná mena {mena}'],
    'Chybí kurz pro {mena}'          => ['en' => 'The exchange rate for {mena} is missing', 'es' => 'Falta el tipo de cambio de {mena}', 'de' => 'Für {mena} fehlt der Wechselkurs', 'sk' => 'Chýba kurz pre {mena}'],
    'Není zadaný vlastní kurz pro {mena} (Nastavení → Přístupy, ceny & měna).' => ['en' => 'No exchange rate is set for {mena}.', 'es' => 'No hay ningún tipo de cambio definido para {mena}.', 'de' => 'Für {mena} ist kein Wechselkurs hinterlegt.', 'sk' => 'Pre {mena} nie je zadaný kurz.'],
    'Kurz ČNB pro {mena} není k dispozici (ČNB nedostupná). Zkus to později nebo nastav vlastní kurz.' => ['en' => 'The exchange rate for {mena} is currently unavailable. Please try again later.', 'es' => 'El tipo de cambio de {mena} no está disponible en este momento. Inténtelo más tarde.', 'de' => 'Der Wechselkurs für {mena} ist derzeit nicht verfügbar. Bitte versuchen Sie es später erneut.', 'sk' => 'Kurz pre {mena} momentálne nie je k dispozícii. Skúste to, prosím, neskôr.'],
    'Ceník je trvale přepočtený do {mena} — platba v jiné měně není možná.' => ['en' => 'Prices are permanently set in {mena} — payment in another currency is not possible.', 'es' => 'Los precios están fijados en {mena}; no es posible pagar en otra moneda.', 'de' => 'Die Preise sind dauerhaft in {mena} festgelegt – eine Zahlung in einer anderen Währung ist nicht möglich.', 'sk' => 'Cenník je natrvalo prepočítaný na {mena} — platba v inej mene nie je možná.'],
    'V tomto kanálu je platba jen v Kč.' => ['en' => 'Only payment in CZK is possible here.', 'es' => 'Aquí solo se puede pagar en CZK.', 'de' => 'Hier ist nur die Zahlung in CZK möglich.', 'sk' => 'Tu je možná platba len v Kč.'],
    'Platba v {mena} není povolená.' => ['en' => 'Payment in {mena} is not allowed.', 'es' => 'No se admite el pago en {mena}.', 'de' => 'Die Zahlung in {mena} ist nicht zulässig.', 'sk' => 'Platba v {mena} nie je povolená.'],
    'Kurz {mena} je starý {dni} dní (poslední z {datum}) — platba v {mena2} je dočasně vypnutá.' => ['en' => 'The {mena} exchange rate is out of date ({dni} days, last from {datum}) — payment in {mena2} is temporarily unavailable.', 'es' => 'El tipo de cambio de {mena} está desactualizado ({dni} días, el último del {datum}); el pago en {mena2} no está disponible temporalmente.', 'de' => 'Der Wechselkurs für {mena} ist veraltet ({dni} Tage, zuletzt vom {datum}) – die Zahlung in {mena2} ist vorübergehend nicht möglich.', 'sk' => 'Kurz {mena} je starý {dni} dní (posledný z {datum}) — platba v {mena2} je dočasne vypnutá.'],
    'Účet nenalezen'                 => ['en' => 'Bill not found', 'es' => 'Cuenta no encontrada', 'de' => 'Rechnung nicht gefunden', 'sk' => 'Účet sa nenašiel'],
    'Doklad nenalezen'               => ['en' => 'Document not found', 'es' => 'Documento no encontrado', 'de' => 'Beleg nicht gefunden', 'sk' => 'Doklad sa nenašiel'],
];

/** Potvrzovací e-mail web objednávky pro jazyky mimo doklady (cs/en/es řeší _i18n.php → _t_lang). */
const APPEK_WEB_I18N_MAIL = [
    'Objednávka {cislo} přijata'     => ['de' => 'Bestellung {cislo} eingegangen', 'sk' => 'Objednávka {cislo} prijatá'],
    'Dobrý den, {jmeno},'            => ['de' => 'Guten Tag {jmeno},', 'sk' => 'Dobrý deň, {jmeno},'],
    'děkujeme, vaši objednávku {cislo} jsme přijali.' => ['de' => 'vielen Dank, wir haben Ihre Bestellung {cislo} erhalten.', 'sk' => 'ďakujeme, vašu objednávku {cislo} sme prijali.'],
    'Doručíme ji na: {adresa}'       => ['de' => 'Wir liefern sie an: {adresa}', 'sk' => 'Doručíme ju na: {adresa}'],
    'Vyzvednutí:'                    => ['de' => 'Abholung:', 'sk' => 'Vyzdvihnutie:'],
    'Kdy:'                           => ['de' => 'Wann:', 'sk' => 'Kedy:'],
    'K úhradě při převzetí:'         => ['de' => 'Zahlbar bei Übergabe:', 'sk' => 'Na úhradu pri prevzatí:'],
    'K úhradě při vyzvednutí:'       => ['de' => 'Zahlbar bei Abholung:', 'sk' => 'Na úhradu pri vyzdvihnutí:'],
    'Stav objednávky:'               => ['de' => 'Bestellstatus:', 'sk' => 'Stav objednávky:'],
    'Děkujeme'                       => ['de' => 'Vielen Dank', 'sk' => 'Ďakujeme'],
];

/** 'cs'|'en'|'es'|'de'|'sk', nebo null pro neplatnou hodnotu (přijme i 'en-GB', 'DE'). */
function web_lang_normalize($lang): ?string {
    if (!is_string($lang) && !is_int($lang)) return null;
    $l = strtolower(substr(trim((string) $lang), 0, 2));
    return in_array($l, APPEK_WEB_LANGS, true) ? $l : null;
}

/** Nejvhodnější podporovaný jazyk z hlavičky Accept-Language (podle q), jinak null. */
function web_lang_from_accept(?string $header = null): ?string {
    $header = $header ?? (string) ($_SERVER['HTTP_ACCEPT_LANGUAGE'] ?? '');
    if (trim($header) === '') return null;
    $best = null; $bestQ = -1.0; $i = 0;
    foreach (explode(',', substr($header, 0, 500)) as $part) {
        $i++;
        $bits = explode(';', trim($part));
        $code = web_lang_normalize($bits[0] ?? '');
        if ($code === null) continue;
        $q = 1.0;
        foreach (array_slice($bits, 1) as $b) {
            if (preg_match('/^\s*q\s*=\s*([0-9.]+)\s*$/i', $b, $m)) $q = (float) $m[1];
        }
        if ($q <= 0) continue;
        $q -= $i * 0.0001;   // při shodě q vyhrává dřívější položka
        if ($q > $bestQ) { $bestQ = $q; $best = $code; }
    }
    return $best;
}

/**
 * Jazyk stránky: ?lang= → jazyk dokladu ($docLang) → Accept-Language → cs.
 * Vrací [jazyk, zdroj] — zdroj 'url'|'doklad'|'prohlizec'|'vychozi'.
 */
function web_lang_resolve(?string $docLang = null, $explicit = null): array {
    $x = web_lang_normalize($explicit ?? ($_GET['lang'] ?? ''));
    if ($x !== null) return [$x, 'url'];
    $x = web_lang_normalize($docLang);
    if ($x !== null) return [$x, 'doklad'];
    $x = web_lang_from_accept();
    if ($x !== null) return [$x, 'prohlizec'];
    return ['cs', 'vychozi'];
}

/**
 * Jazyk dokladu pro platební token (pay/): jazyk odběratele objednávky, ke které platba patří
 * (odberatele.jazyk_dokladu). Účet stolu ani objednávka bez nastaveného jazyka nemají → null.
 * Nikdy nehází (chybějící sloupec / tabulka na starší instalaci = null).
 */
function web_lang_pay_token(PDO $pdo, string $token): ?string {
    $token = preg_replace('/[^a-f0-9]/i', '', $token);
    if (strlen($token) < 16) return null;
    try {
        $st = $pdo->prepare("SELECT d.jazyk_dokladu FROM objednavky o JOIN odberatele d ON d.id = o.odberatel_id WHERE o.pay_token = :t LIMIT 1");
        $st->execute(['t' => $token]);
        return web_lang_normalize($st->fetchColumn() ?: null);
    } catch (Throwable $e) {
        return null;
    }
}

/** Překlad textu stránky / hlášky do $lang; {klic} nahradí hodnotami z $vars. */
function web_t(string $lang, string $key, array $vars = []): string {
    $s = $key;
    if ($lang !== 'cs') {
        $s = APPEK_WEB_I18N[$key][$lang] ?? APPEK_WEB_I18N_SRV[$key][$lang] ?? APPEK_WEB_I18N_MAIL[$key][$lang] ?? $key;
    }
    foreach ($vars as $k => $v) $s = str_replace('{' . $k . '}', (string) $v, $s);
    return $s;
}

/** Slovník pro JS stránky: {ui: {cs klíč: překlad}, srv: {…}}. Čeština = prázdné objekty (klíče jsou texty). */
function web_dict_js(string $lang): array {
    $ui = []; $srv = [];
    if ($lang !== 'cs') {
        foreach (APPEK_WEB_I18N as $k => $tr) if (isset($tr[$lang])) $ui[$k] = $tr[$lang];
        foreach (APPEK_WEB_I18N_SRV as $k => $tr) if (isset($tr[$lang])) $srv[$k] = $tr[$lang];
    }
    return ['ui' => (object) $ui, 'srv' => (object) $srv];
}

/** Locale pro Intl v JS (cs-CZ, en-GB, …). */
function web_locale(string $lang): string {
    return APPEK_WEB_LOCALES[$lang] ?? 'cs-CZ';
}

/**
 * Nenápadný přepínač jazyka — odkazy na stejnou stránku se zachovanými parametry a jiným ?lang=
 * (funguje i bez JS; volba zůstane v URL, takže přežije obnovení stránky).
 */
function web_lang_switcher(string $lang, string $class = 'lang-sw'): string {
    $q = $_GET;
    $out = '<nav class="' . htmlspecialchars($class, ENT_QUOTES) . '" aria-label="' . htmlspecialchars(web_t($lang, 'Jazyk'), ENT_QUOTES) . '">';
    foreach (APPEK_WEB_LANGS as $l) {
        $q['lang'] = $l;
        $href = '?' . http_build_query($q, '', '&', PHP_QUERY_RFC3986);
        $out .= '<a href="' . htmlspecialchars($href, ENT_QUOTES) . '" hreflang="' . $l . '" lang="' . $l . '" title="' . htmlspecialchars(APPEK_WEB_LANG_NAMES[$l], ENT_QUOTES) . '"'
              . ($l === $lang ? ' class="on" aria-current="true"' : '') . '>' . strtoupper($l) . '</a>';
    }
    return $out . '</nav>';
}

/** Datum (a čas) pro e-mail: cs/en/es jako doklady (_fmt_date_loc), de 17.11.2026, sk 17. 11. 2026. */
function web_fmt_date($date, string $lang, bool $withTime = false): string {
    if (in_array($lang, ['cs', 'en', 'es'], true) && function_exists('_fmt_date_loc')) return _fmt_date_loc($date, $lang, $withTime);
    if ($date === null || $date === '' || $date === false) return '';
    $ts = is_int($date) ? $date : strtotime((string) $date);
    if ($ts === false) return '';
    $fmt = ['de' => 'd.m.Y', 'sk' => 'j. n. Y'][$lang] ?? 'j. n. Y';
    return date($fmt . ($withTime ? ' H:i' : ''), $ts);
}

/** Částka pro e-mail: cs/en/es jako doklady (_fmt_money_loc), de 1.234,50 CZK / €, sk 1 234,50 Kč / €. */
function web_fmt_money($n, string $lang, string $kod = 'CZK'): string {
    if (in_array($lang, ['cs', 'en', 'es'], true) && function_exists('_fmt_money_loc')) return _fmt_money_loc($n, $lang, $kod);
    $kod = strtoupper($kod);
    $suf = $kod === 'EUR' ? '€' : ($kod === 'CZK' ? ($lang === 'sk' ? 'Kč' : 'CZK') : $kod);
    return number_format((float) $n, 2, ',', $lang === 'de' ? '.' : ' ') . ' ' . $suf;
}

/**
 * Sdílené JS pro stránky: T(klíč, vars) — překlad UI; TS(hláška) — překlad hlášky serveru
 * (přesná shoda → šablona {x} → v češtině originál, jinak obecná chyba; TS(x, true) = neznámé ponechá); MONEY(n, kod, celeBezDesetin)
 * a SYM(kod) — formát částky dle jazyka (čeština beze změny: „1 234,50 Kč", „12,50 €").
 * Vkládá se jako inline <script> (data jsou JSON → bezpečné pro </script>).
 */
function web_i18n_js(string $lang): string {
    $flags = JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT;
    $d = web_dict_js($lang);
    return 'var LANG=' . json_encode($lang) . ',LOCALE=' . json_encode(web_locale($lang)) . ',I18N=' . json_encode($d['ui'], $flags) . ',I18N_SRV=' . json_encode($d['srv'], $flags) . ";\n" . <<<'JS'
function _fill(s, v){ return v ? String(s).replace(/\{(\w+)\}/g, function(m, k){ return v[k] != null ? v[k] : m; }) : String(s); }
function T(k, v){ return _fill(I18N[k] != null ? I18N[k] : (I18N_SRV[k] != null ? I18N_SRV[k] : k), v); }
var _TPL = null;
function TS(msg, keep){
  msg = String(msg == null ? '' : msg);
  if (LANG === 'cs') return msg;
  if (I18N_SRV[msg] != null) return I18N_SRV[msg];
  if (I18N[msg] != null) return I18N[msg];
  if (!_TPL) _TPL = Object.keys(I18N_SRV).filter(function(k){ return k.indexOf('{') >= 0; }).sort(function(a, b){ return b.length - a.length; }).map(function(k){
    var names = [], re = '^' + k.replace(/\{(\w+)\}|([.*+?^$()|[\]\\])/g, function(m, n, sp){ if (n) { names.push(n); return '(.+?)'; } return '\\' + sp; }) + '$';
    return {re: new RegExp(re), names: names, tr: I18N_SRV[k]};
  });
  for (var i = 0; i < _TPL.length; i++) {
    var m = msg.match(_TPL[i].re); if (!m) continue;
    var v = {};
    _TPL[i].names.forEach(function(n, ix){ v[n] = m[ix + 1].split(', ').map(function(p){ return I18N_SRV[p] != null ? I18N_SRV[p] : (I18N[p] != null ? I18N[p] : p); }).join(', '); });
    return _fill(_TPL[i].tr, v);
  }
  return keep ? msg : T('Něco se nepovedlo. Zkuste to prosím znovu.');
}
function SYM(m){ if (m === 'CZK') return (LANG === 'cs' || LANG === 'sk') ? 'Kč' : 'CZK'; return ({EUR: '€', USD: '$', GBP: '£', PLN: 'zł', HUF: 'Ft', CHF: 'CHF'})[m] || m; }
function NUM(n, minD, maxD){ return Number(n || 0).toLocaleString(LOCALE, {minimumFractionDigits: minD, maximumFractionDigits: maxD}); }
function MONEY(n, m, whole){ var r = Math.round((+n || 0) * 100) / 100, d = whole && !(r % 1) ? 0 : 2; return NUM(r, d, d) + ' ' + SYM(m || 'CZK'); }
JS;
}
