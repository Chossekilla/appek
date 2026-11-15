<?php
/**
 * 🧪 MOCK POHODA mServeru pro testy (NENÍ to POHODA — jen napodobuje HTTP rozhraní mServeru).
 *   php -S 127.0.0.1:8098 scripts/tests/ucetni/mock_mserver.php
 *   Přihlášení: STW-Authorization: Basic base64(MOCK_USER:MOCK_PASS) (výchozí mock:mock, env MOCK_MSERVER_USER/PASS)
 *   GET  /status?companyDetail → stav + firma
 *   POST /xml → přijme dataPack (Windows-1250), volitelně validuje proti XSD (env UCETNI_XSD_DIR),
 *        vrátí responsePack: faktury ok (producedDetails), číslo „DUPL-*" → chyba duplicity,
 *        listInvoiceRequest → 2 přijaté faktury. Přijaté dataPacky ukládá do env MOCK_MSERVER_DIR (pokud je).
 */
$user = getenv('MOCK_MSERVER_USER') ?: 'mock';
$pass = getenv('MOCK_MSERVER_PASS') ?: 'mock';
$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
$auth = $_SERVER['HTTP_STW_AUTHORIZATION'] ?? '';
$okAuth = hash_equals('Basic ' . base64_encode("$user:$pass"), $auth);

function out(string $xml, int $code = 200): void {
    http_response_code($code);
    header('Content-Type: text/xml; charset=Windows-1250');
    echo '<?xml version="1.0" encoding="Windows-1250"?>' . "\n" . iconv('UTF-8', 'WINDOWS-1250//TRANSLIT', $xml);
    exit;
}

if ($path === '/status') {
    if (isset($_GET['companyDetail']) && !$okAuth) { http_response_code(401); exit; }
    out('<mServer><message>mock mServer</message><server>127.0.0.1</server><status>idle</status><processing>0</processing>'
        . (isset($_GET['companyDetail']) ? '<companyDetail><company>APPEK TEST s.r.o. (mock)</company><ico>12345678</ico><databaseName>StwPh_12345678_2026</databaseName><year>2026</year></companyDetail>' : '')
        . '</mServer>');
}
if ($path !== '/xml') { http_response_code(404); exit; }
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') { http_response_code(405); exit; }
if (!$okAuth) { http_response_code(401); exit; }
if (stripos($_SERVER['CONTENT_TYPE'] ?? '', 'text/xml') !== 0) { http_response_code(400); exit; }

$raw = file_get_contents('php://input');
if ($dir = getenv('MOCK_MSERVER_DIR')) { @mkdir($dir, 0777, true); file_put_contents($dir . '/datapack_' . date('His') . '_' . bin2hex(random_bytes(2)) . '.xml', $raw); }
$xsd = getenv('UCETNI_XSD_DIR');
if ($xsd && is_file("$xsd/data.xsd")) {
    libxml_use_internal_errors(true);
    $dom = new DOMDocument();
    if (!$dom->loadXML($raw) || !$dom->schemaValidate("$xsd/data.xsd")) {
        $e = libxml_get_errors();
        out('<rsp:responsePack xmlns:rsp="http://www.stormware.cz/schema/version_2/response.xsd" version="2.0" id="x" state="error" note="'
            . htmlspecialchars('XSD: ' . trim($e[0]->message ?? 'neplatné XML'), ENT_XML1 | ENT_QUOTES) . '"/>');
    }
}
$sx = simplexml_load_string($raw);
$packId = (string) $sx['id'];
$items = '';
$n = 100;
foreach ($sx->children('dat', true)->dataPackItem as $it) {
    $id = (string) $it->attributes()->id;
    $inv = $it->children('inv', true)->invoice;
    $adb = $it->children('adb', true)->addressbook;
    $lst = $it->children('lst', true)->listInvoiceRequest;
    if ($inv && $inv->count()) {
        $num = (string) $inv->children('inv', true)->invoiceHeader->children('inv', true)->number->children('typ', true)->numberRequested;
        if (str_starts_with($num, 'DUPL')) {
            $items .= '<rsp:responsePackItem version="2.0" id="' . $id . '" state="error"><inv:invoiceResponse version="2.0" state="error"><rdc:importDetails><rdc:detail><rdc:state>error</rdc:state><rdc:errno>103</rdc:errno><rdc:note>Doklad s tímto číslem již existuje.</rdc:note></rdc:detail></rdc:importDetails></inv:invoiceResponse></rsp:responsePackItem>';
        } else {
            $n++;
            $items .= '<rsp:responsePackItem version="2.0" id="' . $id . '" state="ok"><inv:invoiceResponse version="2.0" state="ok"><rdc:producedDetails><rdc:id>' . $n . '</rdc:id><rdc:number>' . htmlspecialchars($num, ENT_XML1) . '</rdc:number></rdc:producedDetails></inv:invoiceResponse></rsp:responsePackItem>';
        }
    } elseif ($adb && $adb->count()) {
        $n++;
        $items .= '<rsp:responsePackItem version="2.0" id="' . $id . '" state="ok"><adb:addressbookResponse version="2.0" state="ok"><rdc:producedDetails><rdc:id>' . $n . '</rdc:id></rdc:producedDetails></adb:addressbookResponse></rsp:responsePackItem>';
    } elseif ($lst && $lst->count()) {
        $items .= '<rsp:responsePackItem version="2.0" id="' . $id . '" state="ok"><lst:listInvoice version="2.0" state="ok">'
            . '<lst:invoice version="2.0"><inv:invoiceHeader><inv:invoiceType>receivedInvoice</inv:invoiceType><inv:number><typ:numberRequested>260100001</typ:numberRequested></inv:number><inv:symVar>11223344</inv:symVar><inv:originalDocument>FV-778</inv:originalDocument><inv:date>2026-11-03</inv:date><inv:dateDue>2026-11-17</inv:dateDue><inv:text>Mouka</inv:text><inv:partnerIdentity><typ:address><typ:company>Mlýn Dodavatel a.s.</typ:company><typ:ico>11111111</typ:ico></typ:address></inv:partnerIdentity></inv:invoiceHeader>'
            . '<inv:invoiceSummary><inv:homeCurrency><typ:priceNone>0</typ:priceNone><typ:priceLow>1000</typ:priceLow><typ:priceLowVAT>120</typ:priceLowVAT><typ:priceLowSum>1120</typ:priceLowSum><typ:priceHigh>0</typ:priceHigh><typ:priceHighVAT>0</typ:priceHighVAT><typ:priceHighSum>0</typ:priceHighSum><typ:round><typ:priceRound>0</typ:priceRound></typ:round></inv:homeCurrency></inv:invoiceSummary></lst:invoice>'
            . '<lst:invoice version="2.0"><inv:invoiceHeader><inv:invoiceType>receivedInvoice</inv:invoiceType><inv:number><typ:numberRequested>260100002</typ:numberRequested></inv:number><inv:date>2026-11-05</inv:date><inv:partnerIdentity><typ:address><typ:company>Energie s.r.o.</typ:company></typ:address></inv:partnerIdentity></inv:invoiceHeader>'
            . '<inv:invoiceSummary><inv:homeCurrency><typ:priceNone>0</typ:priceNone><typ:priceHigh>826.45</typ:priceHigh><typ:priceHighVAT>173.55</typ:priceHighVAT><typ:priceHighSum>1000</typ:priceHighSum><typ:round><typ:priceRound>0.4</typ:priceRound></typ:round></inv:homeCurrency></inv:invoiceSummary></lst:invoice>'
            . '</lst:listInvoice></rsp:responsePackItem>';
    }
}
out('<rsp:responsePack version="2.0" id="' . htmlspecialchars($packId, ENT_XML1) . '" state="ok" note="" programVersion="mock"'
    . ' xmlns:rsp="http://www.stormware.cz/schema/version_2/response.xsd" xmlns:rdc="http://www.stormware.cz/schema/version_2/documentresponse.xsd"'
    . ' xmlns:typ="http://www.stormware.cz/schema/version_2/type.xsd" xmlns:inv="http://www.stormware.cz/schema/version_2/invoice.xsd"'
    . ' xmlns:adb="http://www.stormware.cz/schema/version_2/addressbook.xsd" xmlns:lst="http://www.stormware.cz/schema/version_2/list.xsd">'
    . $items . '</rsp:responsePack>');
