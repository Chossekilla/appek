<?php
/**
 * 📊 POHODA mServer — Live XML API klient pro Stormware POHODA.
 *
 * mServer poskytuje HTTP XML rozhraní k běžícímu Pohoda klientu (server na PC s Pohodou).
 * Funguje takto:
 *   1. Účetní spustí "Pohoda mServer" v lokální síti / přes VPN / přes externí IP
 *   2. APPEK posílá XML přes POST na http://<pohoda-ip>:<port>/xml
 *   3. mServer odpovídá XML s výsledkem
 *
 * Workflow:
 *   - export_faktura()    → vytvoří fakturu v Pohoda
 *   - export_dodaci_list()→ vytvoří DL v Pohoda
 *   - export_partner()    → vytvoří/update odběratele
 *   - export_vyrobek()    → vytvoří/update zásobu (skladovou kartu)
 *   - import_prijate_fa() → načte přijaté faktury z Pohoda do APPEK
 *
 * Config v nastaveni (klíče pohoda_*):
 *   pohoda_enabled         '1'/'0'
 *   pohoda_url             http://192.168.1.100:444  (URL mServeru)
 *   pohoda_username        uživatel Pohoda
 *   pohoda_password        heslo
 *   pohoda_ico             IČO firmy v Pohoda (vyžadováno v dataPack)
 *   pohoda_strediska       (volitelné) středisko default
 *   pohoda_cinnost         (volitelné) činnost default
 *
 * @see https://www.stormware.cz/pohoda/xml/dokumentace/  (oficiální dokumentace)
 */

require_once __DIR__ . '/config.php';

function pohoda_settings(): array {
    static $cache = null;
    if ($cache !== null) return $cache;
    $pdo = db();
    $keys = ['enabled','url','username','password','ico','strediska','cinnost'];
    $cache = [];
    foreach ($keys as $k) {
        $cache['pohoda_' . $k] = nastaveni_get($pdo, 'pohoda_' . $k, '');
    }
    return $cache;
}

function pohoda_is_enabled(): bool {
    $cfg = pohoda_settings();
    return $cfg['pohoda_enabled'] === '1' && $cfg['pohoda_url'] && $cfg['pohoda_username'] && $cfg['pohoda_ico'];
}

/**
 * Pošle XML request na Pohoda mServer.
 * @return array{ok:bool, response_xml?:string, parsed?:array, error?:string, http?:int}
 */
function pohoda_request(string $xmlBody): array {
    $cfg = pohoda_settings();
    if (!pohoda_is_enabled()) {
        return ['ok' => false, 'error' => 'pohoda_disabled'];
    }
    $url = rtrim($cfg['pohoda_url'], '/') . '/xml';
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $xmlBody,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 60,
        CURLOPT_CONNECTTIMEOUT => 15,
        CURLOPT_HTTPHEADER     => [
            'Content-Type: application/xml; charset=utf-8',
            'STW-Application: APPEK-B2B/1.0',
            'STW-Instance: APPEK',
            'STW-Authorization: Basic ' . base64_encode($cfg['pohoda_username'] . ':' . $cfg['pohoda_password']),
        ],
        CURLOPT_SSL_VERIFYPEER => false, // mServer často běží na samopodepsaném certifikátu / HTTP
    ]);
    $resp = curl_exec($ch);
    $http = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err  = curl_error($ch);
    curl_close($ch);
    if ($resp === false || $http >= 400) {
        return ['ok' => false, 'error' => $err ?: ('http_' . $http), 'http' => $http, 'response_xml' => $resp];
    }
    // Pokus o parse — Pohoda odpovídá XML s namespacy
    libxml_use_internal_errors(true);
    $sx = @simplexml_load_string($resp);
    return [
        'ok' => true,
        'http' => $http,
        'response_xml' => $resp,
        'parsed' => $sx ? json_decode(json_encode($sx), true) : null,
    ];
}

/**
 * Helper — wrap dataPack envelope kolem listingu.
 * Pohoda XML formát:
 *   <dat:dataPack id="..." ico="..." application="..." version="2.0">
 *     <dat:dataPackItem>
 *       <inv:invoice version="..."> ... </inv:invoice>
 *     </dat:dataPackItem>
 *   </dat:dataPack>
 */
function pohoda_data_pack(string $itemsXml, string $packId = ''): string {
    $cfg = pohoda_settings();
    $ico = htmlspecialchars($cfg['pohoda_ico'], ENT_XML1, 'UTF-8');
    $pid = $packId ?: 'appek-' . date('YmdHis') . '-' . random_int(1000, 9999);
    return '<?xml version="1.0" encoding="utf-8"?>' .
        '<dat:dataPack xmlns:dat="http://www.stormware.cz/schema/version_2/data.xsd"' .
        '  xmlns:typ="http://www.stormware.cz/schema/version_2/type.xsd"' .
        '  xmlns:inv="http://www.stormware.cz/schema/version_2/invoice.xsd"' .
        '  xmlns:lAdb="http://www.stormware.cz/schema/version_2/list_addBook.xsd"' .
        '  xmlns:adb="http://www.stormware.cz/schema/version_2/addressbook.xsd"' .
        '  xmlns:lStk="http://www.stormware.cz/schema/version_2/list_stock.xsd"' .
        '  xmlns:stk="http://www.stormware.cz/schema/version_2/stock.xsd"' .
        '  xmlns:lst="http://www.stormware.cz/schema/version_2/list.xsd"' .
        '  id="' . $pid . '" ico="' . $ico . '" application="APPEK" version="2.0" note="APPEK B2B export">' .
        $itemsXml .
        '</dat:dataPack>';
}

function _xml($s): string {
    return htmlspecialchars((string) $s, ENT_XML1 | ENT_QUOTES, 'UTF-8');
}

/**
 * Export jedné faktury do Pohoda.
 *
 * @param array $faktura  ['cislo','datum_vystaveni','datum_splatnosti','datum_zd_plneni',
 *                         'odberatel'=>['nazev','ic','dic','ulice','mesto','psc'],
 *                         'polozky'=>[['nazev','mnozstvi','jednotka','cena_bez_dph','dph_pct'], ...],
 *                         'celkem_bez_dph','celkem_dph','celkem_s_dph',
 *                         'cislo_objednavky','poznamka']
 */
function pohoda_export_faktura(array $faktura): array {
    $o = $faktura['odberatel'] ?? [];
    $polozky = $faktura['polozky'] ?? [];

    $polozkyXml = '';
    foreach ($polozky as $p) {
        $dphPct = (int) ($p['dph_pct'] ?? 21);
        $dphType = $dphPct == 21 ? 'high' : ($dphPct == 15 ? 'low' : ($dphPct == 10 ? 'third' : 'none'));
        $polozkyXml .= '<inv:invoiceItem>' .
            '<inv:text>' . _xml($p['nazev'] ?? 'Položka') . '</inv:text>' .
            '<inv:quantity>' . _xml((float) ($p['mnozstvi'] ?? 1)) . '</inv:quantity>' .
            '<inv:unit>' . _xml($p['jednotka'] ?? 'ks') . '</inv:unit>' .
            '<inv:rateVAT>' . $dphType . '</inv:rateVAT>' .
            '<inv:homeCurrency>' .
                '<typ:unitPrice>' . _xml((float) ($p['cena_bez_dph'] ?? 0)) . '</typ:unitPrice>' .
            '</inv:homeCurrency>' .
            '</inv:invoiceItem>';
    }

    $invoice = '<inv:invoice version="2.0">' .
        '<inv:invoiceHeader>' .
            '<inv:invoiceType>issuedInvoice</inv:invoiceType>' .
            '<inv:number><typ:numberRequested>' . _xml($faktura['cislo'] ?? '') . '</typ:numberRequested></inv:number>' .
            '<inv:symVar>' . _xml(preg_replace('/[^0-9]/', '', $faktura['cislo'] ?? '')) . '</inv:symVar>' .
            '<inv:date>' . _xml($faktura['datum_vystaveni'] ?? date('Y-m-d')) . '</inv:date>' .
            '<inv:dateTax>' . _xml($faktura['datum_zd_plneni'] ?? $faktura['datum_vystaveni'] ?? date('Y-m-d')) . '</inv:dateTax>' .
            '<inv:dateAccounting>' . _xml($faktura['datum_vystaveni'] ?? date('Y-m-d')) . '</inv:dateAccounting>' .
            '<inv:dateDue>' . _xml($faktura['datum_splatnosti'] ?? '') . '</inv:dateDue>' .
            '<inv:classificationVAT><typ:classificationVATType>inland</typ:classificationVATType></inv:classificationVAT>' .
            '<inv:text>' . _xml($faktura['poznamka'] ?? '') . '</inv:text>' .
            '<inv:partnerIdentity>' .
                '<typ:address>' .
                    '<typ:company>' . _xml($o['nazev'] ?? '') . '</typ:company>' .
                    '<typ:street>' . _xml($o['ulice'] ?? '') . '</typ:street>' .
                    '<typ:city>' . _xml($o['mesto'] ?? '') . '</typ:city>' .
                    '<typ:zip>' . _xml($o['psc'] ?? '') . '</typ:zip>' .
                    '<typ:ico>' . _xml($o['ic'] ?? '') . '</typ:ico>' .
                    ($o['dic'] ? '<typ:dic>' . _xml($o['dic']) . '</typ:dic>' : '') .
                '</typ:address>' .
            '</inv:partnerIdentity>' .
            '<inv:paymentType><typ:ids>převodem</typ:ids></inv:paymentType>' .
            ($faktura['cislo_objednavky'] ? '<inv:numberOrder>' . _xml($faktura['cislo_objednavky']) . '</inv:numberOrder>' : '') .
        '</inv:invoiceHeader>' .
        '<inv:invoiceDetail>' . $polozkyXml . '</inv:invoiceDetail>' .
        '<inv:invoiceSummary>' .
            '<inv:roundingDocument>math2one</inv:roundingDocument>' .
            '<inv:homeCurrency>' .
                '<typ:priceNone>0</typ:priceNone>' .
                '<typ:priceLow>0</typ:priceLow>' .
                '<typ:priceLowVAT>0</typ:priceLowVAT>' .
                '<typ:priceHigh>' . _xml((float) ($faktura['celkem_bez_dph'] ?? 0)) . '</typ:priceHigh>' .
                '<typ:priceHighVAT>' . _xml((float) ($faktura['celkem_dph'] ?? 0)) . '</typ:priceHighVAT>' .
            '</inv:homeCurrency>' .
        '</inv:invoiceSummary>' .
        '</inv:invoice>';

    $dataPack = pohoda_data_pack('<dat:dataPackItem id="fa-' . _xml($faktura['cislo'] ?? '') . '" version="2.0">' . $invoice . '</dat:dataPackItem>');
    return pohoda_request($dataPack);
}

/**
 * Export odběratele/dodavatele do Pohoda addressbooku.
 */
function pohoda_export_partner(array $partner): array {
    $address = '<adb:addressbook version="2.0">' .
        '<adb:addressbookHeader>' .
            '<adb:identity>' .
                '<typ:address>' .
                    '<typ:company>' . _xml($partner['nazev'] ?? '') . '</typ:company>' .
                    '<typ:street>' . _xml($partner['ulice'] ?? '') . '</typ:street>' .
                    '<typ:city>' . _xml($partner['mesto'] ?? '') . '</typ:city>' .
                    '<typ:zip>' . _xml($partner['psc'] ?? '') . '</typ:zip>' .
                    '<typ:ico>' . _xml($partner['ic'] ?? '') . '</typ:ico>' .
                    ($partner['dic'] ? '<typ:dic>' . _xml($partner['dic']) . '</typ:dic>' : '') .
                    ($partner['email'] ? '<typ:email>' . _xml($partner['email']) . '</typ:email>' : '') .
                    ($partner['telefon'] ? '<typ:mobilPhone>' . _xml($partner['telefon']) . '</typ:mobilPhone>' : '') .
                '</typ:address>' .
            '</adb:identity>' .
            ($partner['note'] ? '<adb:note>' . _xml($partner['note']) . '</adb:note>' : '') .
        '</adb:addressbookHeader>' .
        '</adb:addressbook>';
    $dataPack = pohoda_data_pack('<dat:dataPackItem id="ab-' . random_int(1000, 99999) . '" version="2.0">' . $address . '</dat:dataPackItem>');
    return pohoda_request($dataPack);
}

/**
 * Import přijatých faktur z Pohoda (POHODA → APPEK).
 *
 * Query mServeru: list invoices za období.
 */
function pohoda_import_prijate_fa(string $fromDate, string $toDate): array {
    $cfg = pohoda_settings();
    $ico = _xml($cfg['pohoda_ico']);
    $listReq = '<lst:listInvoiceRequest version="2.0" invoiceVersion="2.0" invoiceType="receivedInvoice">' .
        '<lst:requestInvoice>' .
            '<ftr:filter xmlns:ftr="http://www.stormware.cz/schema/version_2/filter.xsd">' .
                '<ftr:dateFrom>' . _xml($fromDate) . '</ftr:dateFrom>' .
                '<ftr:dateTill>' . _xml($toDate) . '</ftr:dateTill>' .
            '</ftr:filter>' .
        '</lst:requestInvoice>' .
        '</lst:listInvoiceRequest>';
    $dataPack = pohoda_data_pack('<dat:dataPackItem id="list-' . date('YmdHis') . '" version="2.0">' . $listReq . '</dat:dataPackItem>');
    return pohoda_request($dataPack);
}

/**
 * Test connection — zkusí prázdný list request → mServer odpoví s OK/error
 */
function pohoda_test_connection(): array {
    $cfg = pohoda_settings();
    if (!pohoda_is_enabled()) {
        return ['ok' => false, 'error' => 'POHODA mServer není aktivní v Nastavení (chybí URL, username, IČO nebo enabled).'];
    }
    // Pokus o list (žádné období → vrátí prázdno, ale validuje credentials + connect)
    $r = pohoda_import_prijate_fa(date('Y-m-d', strtotime('-1 day')), date('Y-m-d'));
    if (!$r['ok']) return $r;
    // Look for response error
    $rx = $r['response_xml'] ?? '';
    if (stripos($rx, 'state="ok"') !== false || stripos($rx, 'list') !== false) {
        return ['ok' => true, 'message' => 'Spojení OK — mServer reaguje.'];
    }
    if (stripos($rx, 'state="error"') !== false) {
        // Parse error msg
        if (preg_match('/<rdc:note>(.*?)<\/rdc:note>/s', $rx, $m)) {
            return ['ok' => false, 'error' => 'mServer: ' . html_entity_decode($m[1])];
        }
        return ['ok' => false, 'error' => 'mServer vrátil error stav.', 'response_xml' => substr($rx, 0, 500)];
    }
    return ['ok' => true, 'message' => 'Spojení navázáno (mServer odpovídá).'];
}
