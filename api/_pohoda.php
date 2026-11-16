<?php
/**
 * 📊 POHODA (Stormware) — XML builder + mServer klient.
 *
 * Builder funkce jsou ČISTÉ (berou pole, vrací string) → testovatelné bez DB i bez POHODY.
 * Výstup odpovídá oficiálním XSD (www.stormware.cz/schema/version_2/*.xsd, dataPack verze 2.0)
 * a je validovaný xmllintem v scripts/tests/ucetni/.
 *
 * Kódování: POHODA pracuje s XML ve Windows-1250 (dokumentace mServeru, oficiální vzory).
 *   Dokument se staví v UTF-8, na konci se převede do Windows-1250 (znaky mimo 1250 → '?')
 *   a deklaruje se encoding="Windows-1250".
 *
 * Mapování APPEK → POHODA (vydaná faktura, agenda Vydané faktury):
 *   faktura            → invoiceType issuedInvoice
 *   dobropis (DOB-)    → invoiceType issuedCorrectiveTax (opravný daňový doklad, CZ verze);
 *                        vazba na původní FA (correctiveDocument) jen když je původní faktura v POHODĚ
 *   číslo dokladu      → number/numberRequested checkDuplicity="true" (POHODA odmítne duplicitu)
 *   sazby DPH          → rateVAT high (21 %) / low (12 %, dř. 15 %) / third (10 %) / none (0 %)
 *   ceny               → bez DPH (payVAT=false), základ + DPH každé položky dopočtené tak,
 *                        aby rekapitulace přesně odpovídala PDF faktury; souhrn bez zaokrouhlení
 *   měna               → domácí (CZK); doklad v cizí měně (v3.0.640) navíc foreignCurrency
 *                        u položek + v souhrnu (currency/rate/amount/priceSum) — Kč částky zůstávají
 *
 * mServer: POST {url}/xml, Content-Type text/xml, hlavička STW-Authorization: Basic base64(user:heslo),
 *          test spojení GET {url}/status?companyDetail. Odpověď = rsp:responsePack (state ok|error).
 */

require_once __DIR__ . '/_ucetni_lib.php';

const POHODA_NS = [
    'dat'  => 'http://www.stormware.cz/schema/version_2/data.xsd',
    'typ'  => 'http://www.stormware.cz/schema/version_2/type.xsd',
    'inv'  => 'http://www.stormware.cz/schema/version_2/invoice.xsd',
    'adb'  => 'http://www.stormware.cz/schema/version_2/addressbook.xsd',
    'lst'  => 'http://www.stormware.cz/schema/version_2/list.xsd',
    'lAdb' => 'http://www.stormware.cz/schema/version_2/list_addBook.xsd',
    'ftr'  => 'http://www.stormware.cz/schema/version_2/filter.xsd',
];

/** XML escape (UTF-8 vstup; odstraní řídicí znaky; volitelně ořízne na max znaků). */
function pohoda_x($s, int $max = 0): string {
    return htmlspecialchars(ucetni_clean((string) $s, $max), ENT_XML1 | ENT_QUOTES, 'UTF-8');
}

/** Element jen když hodnota není prázdná. */
function pohoda_el(string $name, $value, int $max = 0): string {
    $v = ucetni_clean((string) $value, $max);
    return $v === '' ? '' : "<$name>" . pohoda_x($v) . "</$name>";
}

/** APPEK sazba DPH → POHODA rateVAT. null = nepodporovaná sazba. */
function pohoda_rate_vat(float $sazba): ?string {
    $s = round($sazba, 2);
    if ($s == 21.0) return 'high';
    if ($s == 12.0 || $s == 15.0) return 'low';
    if ($s == 10.0) return 'third';
    if ($s == 0.0) return 'none';
    return null;
}

/** APPEK platba → POHODA paymentType. */
function pohoda_payment_type(string $kod): string {
    return ['hotove' => 'cash', 'karta' => 'creditcard', 'dobirka' => 'delivery'][$kod] ?? 'draft';
}

/** UTF-8 → Windows-1250 (znak po znaku; nepřevoditelné → '?'). */
function pohoda_to_1250(string $utf8): string {
    $out = @iconv('UTF-8', 'WINDOWS-1250', $utf8);
    if ($out !== false) return $out;
    $out = '';
    foreach (mb_str_split($utf8) as $ch) {
        $c = @iconv('UTF-8', 'WINDOWS-1250//TRANSLIT', $ch);
        $out .= ($c === false || $c === '') ? '?' : $c;
    }
    return $out;
}

/**
 * Obálka dataPack. $items = [['id'=>'fa-1', 'xml'=>'<inv:invoice …>'], …].
 * Vrací hotový dokument ve Windows-1250 (bajty), připravený k odeslání / uložení.
 */
function pohoda_datapack(array $items, string $ico, string $packId, string $note = 'APPEK export'): string {
    $ns = '';
    foreach (POHODA_NS as $p => $u) $ns .= ' xmlns:' . $p . '="' . $u . '"';
    $body = '<dat:dataPack' . $ns . ' version="2.0" id="' . pohoda_x($packId, 64) . '"'
          . ($ico !== '' ? ' ico="' . pohoda_x($ico, 15) . '"' : '')
          . ' application="APPEK" note="' . pohoda_x($note, 200) . '">' . "\n";
    foreach ($items as $it) {
        $body .= '<dat:dataPackItem version="2.0" id="' . pohoda_x($it['id'], 64) . '">' . "\n" . $it['xml'] . "\n</dat:dataPackItem>\n";
    }
    $body .= '</dat:dataPack>';
    return '<?xml version="1.0" encoding="Windows-1250"?>' . "\n" . pohoda_to_1250($body);
}

/** Adresa partnera (typ:address) — pořadí elementů dle typ:addressType. */
function pohoda_address_xml(array $o): string {
    $ico = preg_replace('/\s+/', '', (string) ($o['ico'] ?? ''));
    $dic = preg_replace('/\s+/', '', (string) ($o['dic'] ?? ''));
    return '<typ:address>'
        . pohoda_el('typ:company', $o['nazev'] ?? '', 255)
        . pohoda_el('typ:city', $o['mesto'] ?? '', 45)
        . pohoda_el('typ:street', $o['ulice'] ?? '', 64)
        . pohoda_el('typ:zip', $o['psc'] ?? '', 15)
        . pohoda_el('typ:ico', $ico, 15)
        . pohoda_el('typ:dic', $dic, 18)
        . pohoda_el('typ:phone', $o['telefon'] ?? '', 40)
        . pohoda_el('typ:email', $o['email'] ?? '', 98)
        . '</typ:address>';
}

/**
 * Vydaná faktura / opravný daňový doklad (inv:invoice).
 * $inv = výstup ucetni_nacti_fakturu(); $opt = ['predkontace'=>'', 'cleneni'=>'', 'puvodni_v_pohode'=>bool]
 * Vrací ['ok'=>true,'xml'=>…] nebo ['ok'=>false,'error'=>česky].
 */
function pohoda_xml_invoice(array $inv, array $opt = []): array {
    foreach ($inv['polozky'] as $p) {
        if (pohoda_rate_vat((float) $p['sazba']) === null) {
            return ['ok' => false, 'error' => 'Položka „' . $p['nazev'] . '" má sazbu DPH ' . ucetni_num($p['sazba'], 2)
                . ' %, kterou POHODA nezná (podporované: 21, 12, 15, 10, 0 %).'];
        }
    }
    foreach ($inv['rekapitulace'] as $r) {
        if (pohoda_rate_vat((float) $r['sazba']) === null) return ['ok' => false, 'error' => 'Nepodporovaná sazba DPH ' . ucetni_num($r['sazba'], 2) . ' %.'];
    }
    $dob = !empty($inv['je_dobropis']);
    $cislo = ucetni_clean($inv['cislo'], 32);
    if ($cislo === '') return ['ok' => false, 'error' => 'Faktura nemá číslo.'];

    $text = $dob
        ? 'Opravný daňový doklad ' . $inv['cislo'] . ($inv['puvodni_cislo'] ? ' k faktuře ' . $inv['puvodni_cislo'] : '')
        : 'Faktura ' . $inv['cislo'];
    $note = 'APPEK ' . $inv['cislo'];
    if (!empty($inv['objednavky'])) $note .= ' · objednávky: ' . implode(', ', $inv['objednavky']);
    if (!empty($inv['dodaci_listy'])) $note .= ' · DL: ' . implode(', ', $inv['dodaci_listy']);
    if ($inv['poznamka'] !== '') $note .= "\n" . $inv['poznamka'];
    $numberOrder = $inv['objednavky'] ? ucetni_clean(implode(', ', $inv['objednavky']), 32) : '';

    $x = '<inv:invoice version="2.0">';
    if ($dob && !empty($opt['puvodni_v_pohode']) && $inv['puvodni_cislo']) {
        $x .= '<inv:correctiveDocument><typ:sourceDocument><typ:number>' . pohoda_x($inv['puvodni_cislo'], 32)
            . '</typ:number></typ:sourceDocument></inv:correctiveDocument>';
    }
    $x .= '<inv:invoiceHeader>'
        . '<inv:invoiceType>' . ($dob ? 'issuedCorrectiveTax' : 'issuedInvoice') . '</inv:invoiceType>'
        . '<inv:number><typ:numberRequested checkDuplicity="true">' . pohoda_x($cislo) . '</typ:numberRequested></inv:number>'
        . pohoda_el('inv:symVar', $inv['vs'], 20)
        . '<inv:date>' . pohoda_x($inv['datum_vystaveni']) . '</inv:date>'
        . '<inv:dateTax>' . pohoda_x($inv['datum_dph']) . '</inv:dateTax>'
        . '<inv:dateAccounting>' . pohoda_x($inv['datum_vystaveni']) . '</inv:dateAccounting>'
        . '<inv:dateDue>' . pohoda_x($inv['datum_splatnosti']) . '</inv:dateDue>'
        . (($opt['predkontace'] ?? '') !== '' ? '<inv:accounting><typ:ids>' . pohoda_x($opt['predkontace'], 19) . '</typ:ids></inv:accounting>' : '')
        . (($opt['cleneni'] ?? '') !== '' ? '<inv:classificationVAT><typ:ids>' . pohoda_x($opt['cleneni'], 19) . '</typ:ids></inv:classificationVAT>' : '')
        . pohoda_el('inv:text', $text, 240)
        . '<inv:partnerIdentity>' . pohoda_address_xml($inv['odberatel']) . '</inv:partnerIdentity>'
        . ($numberOrder !== '' ? '<inv:numberOrder>' . pohoda_x($numberOrder) . '</inv:numberOrder>' : '')
        . '<inv:paymentType><typ:paymentType>' . pohoda_payment_type($inv['platba']) . '</typ:paymentType></inv:paymentType>'
        . '<inv:note>' . pohoda_x(mb_substr($note, 0, 2000)) . '</inv:note>'
        . '<inv:intNote>' . pohoda_x('Export z APPEK (id ' . (int) $inv['id'] . ')') . '</inv:intNote>'
        . '</inv:invoiceHeader>';

    $cizi = $inv['cizi'] ?? null;   // 💱 v3.0.640 — doklad v cizí měně (EUR…): částky i v ní + kurz
    if ($inv['polozky']) {
        $x .= '<inv:invoiceDetail>';
        foreach ($inv['polozky'] as $ix => $p) {
            $pc = $cizi['polozky'][$ix] ?? null;
            $x .= '<inv:invoiceItem>'
                . '<inv:text>' . pohoda_x($p['nazev'], 90) . '</inv:text>'
                . '<inv:quantity>' . ucetni_num($p['mnozstvi'], 3) . '</inv:quantity>'
                . '<inv:unit>' . pohoda_x($p['jednotka'], 10) . '</inv:unit>'
                . '<inv:payVAT>false</inv:payVAT>'
                . '<inv:rateVAT>' . pohoda_rate_vat((float) $p['sazba']) . '</inv:rateVAT>'
                . '<inv:homeCurrency>'
                .   '<typ:unitPrice>' . ucetni_num($p['cena_mj'], 2) . '</typ:unitPrice>'
                .   '<typ:price>' . ucetni_num($p['zaklad'], 2) . '</typ:price>'
                .   '<typ:priceVAT>' . ucetni_num($p['dph'], 2) . '</typ:priceVAT>'
                .   '<typ:priceSum>' . ucetni_num($p['zaklad'] + $p['dph'], 2) . '</typ:priceSum>'
                . '</inv:homeCurrency>'
                . ($pc ? '<inv:foreignCurrency>'
                    .   '<typ:unitPrice>' . ucetni_num($pc['cena_mj'], 2) . '</typ:unitPrice>'
                    .   '<typ:price>' . ucetni_num($pc['zaklad'], 2) . '</typ:price>'
                    .   '<typ:priceVAT>' . ucetni_num($pc['dph'], 2) . '</typ:priceVAT>'
                    .   '<typ:priceSum>' . ucetni_num($pc['zaklad'] + $pc['dph'], 2) . '</typ:priceSum>'
                    . '</inv:foreignCurrency>' : '')
                . ($p['poznamka'] !== '' ? '<inv:note>' . pohoda_x($p['poznamka'], 90) . '</inv:note>' : '')
                . ($p['kod'] !== '' ? '<inv:code>' . pohoda_x($p['kod'], 64) . '</inv:code>' : '')
                . '</inv:invoiceItem>';
        }
        $x .= '</inv:invoiceDetail>';
    }

    // Souhrn — přesně rekapitulace z PDF (bez zaokrouhlení dokladu)
    $g = ['none' => [0.0, 0.0], 'low' => [0.0, 0.0], 'high' => [0.0, 0.0], 'third' => [0.0, 0.0]];
    if ($inv['rekapitulace']) {
        foreach ($inv['rekapitulace'] as $r) {
            $k = pohoda_rate_vat((float) $r['sazba']);
            $g[$k][0] += $r['zaklad']; $g[$k][1] += $r['dph'];
        }
    } else {
        // faktura bez položek (výjimečně) — vše do hlavičkových částek; sazbu nelze určit → základní
        $g[$inv['celkem_dph'] != 0 ? 'high' : 'none'] = [$inv['celkem_bez'], $inv['celkem_dph']];
    }
    $x .= '<inv:invoiceSummary>'
        . '<inv:roundingDocument>none</inv:roundingDocument>'
        . '<inv:roundingVAT>none</inv:roundingVAT>'
        . '<inv:homeCurrency>'
        . '<typ:priceNone>' . ucetni_num($g['none'][0]) . '</typ:priceNone>'
        . '<typ:priceLow>' . ucetni_num($g['low'][0]) . '</typ:priceLow>'
        . '<typ:priceLowVAT>' . ucetni_num($g['low'][1]) . '</typ:priceLowVAT>'
        . '<typ:priceLowSum>' . ucetni_num($g['low'][0] + $g['low'][1]) . '</typ:priceLowSum>'
        . '<typ:priceHigh>' . ucetni_num($g['high'][0]) . '</typ:priceHigh>'
        . '<typ:priceHighVAT>' . ucetni_num($g['high'][1]) . '</typ:priceHighVAT>'
        . '<typ:priceHighSum>' . ucetni_num($g['high'][0] + $g['high'][1]) . '</typ:priceHighSum>'
        . ($g['third'][0] != 0 || $g['third'][1] != 0
            ? '<typ:price3>' . ucetni_num($g['third'][0]) . '</typ:price3><typ:price3VAT>' . ucetni_num($g['third'][1])
              . '</typ:price3VAT><typ:price3Sum>' . ucetni_num($g['third'][0] + $g['third'][1]) . '</typ:price3Sum>'
            : '')
        . '</inv:homeCurrency>'
        . ($cizi ? '<inv:foreignCurrency>'
            . '<typ:currency><typ:ids>' . pohoda_x($cizi['mena'], 3) . '</typ:ids></typ:currency>'
            . '<typ:rate>' . ucetni_num($cizi['kurz'], 3) . '</typ:rate>'
            . '<typ:amount>1</typ:amount>'
            . '<typ:priceSum>' . ucetni_num($cizi['celkem'], 2) . '</typ:priceSum>'
            . '</inv:foreignCurrency>' : '')
        . '</inv:invoiceSummary>';
    $x .= '</inv:invoice>';
    return ['ok' => true, 'xml' => $x];
}

/**
 * Záznam adresáře (adb:addressbook) — upsert: aktualizuj existující se stejným IČO, jinak přidej.
 * Bez IČO (fyzická osoba) se jen přidává (POHODA nemá jiný spolehlivý klíč z APPEK).
 */
function pohoda_xml_addressbook(array $o): string {
    $ico = preg_replace('/\s+/', '', (string) ($o['ico'] ?? ''));
    $x = '<adb:addressbook version="2.0">';
    if ($ico !== '') {
        $x .= '<adb:actionType><adb:update update="true" add="true"><ftr:filter><ftr:ico>' . pohoda_x($ico, 15)
            . '</ftr:ico></ftr:filter></adb:update></adb:actionType>';
    }
    $x .= '<adb:addressbookHeader>'
        . '<adb:identity>' . pohoda_address_xml(['nazev' => $o['nazev'] ?? '', 'mesto' => $o['mesto'] ?? '', 'ulice' => $o['ulice'] ?? '',
                                                   'psc' => $o['psc'] ?? '', 'ico' => $ico, 'dic' => $o['dic'] ?? '']) . '</adb:identity>'
        . pohoda_el('adb:phone', $o['telefon'] ?? '', 40)
        . pohoda_el('adb:email', $o['email'] ?? '', 98)
        . pohoda_el('adb:web', $o['web'] ?? '', 96)
        . (isset($o['splatnost_dni']) && $o['splatnost_dni'] !== null && $o['splatnost_dni'] !== '' ? '<adb:maturity>' . (int) $o['splatnost_dni'] . '</adb:maturity>' : '')
        . '<adb:note>' . pohoda_x('APPEK odběratel #' . (int) ($o['id'] ?? 0) . (($o['cislo'] ?? '') !== '' ? ' (' . $o['cislo'] . ')' : '')) . '</adb:note>'
        . '</adb:addressbookHeader>'
        . '</adb:addressbook>';
    return $x;
}

/** Požadavek na seznam přijatých faktur za období (lst:listInvoiceRequest). */
function pohoda_xml_list_received(string $od, string $do): string {
    return '<lst:listInvoiceRequest version="2.0" invoiceType="receivedInvoice" invoiceVersion="2.0">'
        . '<lst:requestInvoice><ftr:filter>'
        . '<ftr:dateFrom>' . pohoda_x($od) . '</ftr:dateFrom><ftr:dateTill>' . pohoda_x($do) . '</ftr:dateTill>'
        . '</ftr:filter></lst:requestInvoice>'
        . '</lst:listInvoiceRequest>';
}

// ═════════════════════════════════════════════════════════════════════
// responsePack parser (čistá funkce)
// ═════════════════════════════════════════════════════════════════════

/**
 * Rozparsuje rsp:responsePack. Vrací:
 *  ['ok'=>bool(parse), 'error'=>?, 'state'=>'ok|error', 'note'=>…, 'items'=>[
 *     ['id','state'(ok|warning|error),'note','produced_id','produced_number','details'=>[['state','errno','note','xpath']],
 *      'invoices'=>[…] (u list odpovědi)]
 *  ]]
 */
function pohoda_parse_response(string $raw): array {
    $raw = ltrim($raw, "\xEF\xBB\xBF \t\r\n");
    if ($raw === '' || $raw[0] !== '<') {
        return ['ok' => false, 'error' => 'Server nevrátil XML odpověď POHODY' . ($raw !== '' ? ' (začátek: „' . mb_substr(strip_tags(@iconv('WINDOWS-1250', 'UTF-8//IGNORE', $raw) ?: ''), 0, 120) . '")' : '') . '.', 'items' => []];
    }
    $prev = libxml_use_internal_errors(true);
    $dom = new DOMDocument();
    $loaded = $dom->loadXML($raw, LIBXML_NONET | LIBXML_NOCDATA);
    libxml_clear_errors();
    libxml_use_internal_errors($prev);
    if (!$loaded) return ['ok' => false, 'error' => 'Odpověď POHODY není platné XML.', 'items' => []];
    $xp = new DOMXPath($dom);
    $root = $dom->documentElement;
    if (!$root || $root->localName !== 'responsePack') {
        return ['ok' => false, 'error' => 'Neočekávaná odpověď (chybí responsePack).', 'items' => []];
    }
    $txt = function (?DOMNode $ctx, string $q) use ($xp): string {
        $n = $xp->query($q, $ctx);
        return ($n && $n->length) ? trim($n->item(0)->textContent) : '';
    };
    $res = ['ok' => true, 'error' => null, 'state' => $root->getAttribute('state') ?: 'error',
            'note' => $root->getAttribute('note'), 'id' => $root->getAttribute('id'), 'items' => []];
    foreach ($xp->query("./*[local-name()='responsePackItem']", $root) as $it) {
        $item = ['id' => $it->getAttribute('id'), 'state' => $it->getAttribute('state') ?: 'error', 'note' => $it->getAttribute('note'),
                 'produced_id' => '', 'produced_number' => '', 'details' => [], 'invoices' => []];
        // *Response element (invoiceResponse/addressbookResponse) s vlastním state
        $resp = $xp->query("./*[@state]", $it);
        if ($resp->length) {
            $rs = $resp->item(0)->getAttribute('state');
            if ($rs === 'error' || ($rs === 'warning' && $item['state'] === 'ok')) $item['state'] = $rs;
        }
        $item['produced_id'] = $txt($it, ".//*[local-name()='producedDetails']/*[local-name()='id']");
        $item['produced_number'] = $txt($it, ".//*[local-name()='producedDetails']/*[local-name()='number']");
        foreach ($xp->query(".//*[local-name()='importDetails']/*[local-name()='detail']", $it) as $d) {
            $item['details'][] = [
                'state' => $txt($d, "./*[local-name()='state']"),
                'errno' => $txt($d, "./*[local-name()='errno']"),
                'note'  => $txt($d, "./*[local-name()='note']"),
                'xpath' => $txt($d, "./*[local-name()='XPath']"),
            ];
        }
        // XSD: state položky i *Response je jen ok|error; upozornění jsou v importDetails/detail/state=warning
        if ($item['state'] === 'ok') {
            foreach ($item['details'] as $dd) if ($dd['state'] === 'warning') { $item['state'] = 'warning'; break; }
            foreach ($item['details'] as $dd) if ($dd['state'] === 'error') { $item['state'] = 'error'; break; }
        }
        foreach ($xp->query(".//*[local-name()='listInvoice']/*[local-name()='invoice']", $it) as $invEl) {
            $h = $xp->query("./*[local-name()='invoiceHeader']", $invEl)->item(0);
            $s = $xp->query("./*[local-name()='invoiceSummary']", $invEl)->item(0);
            $num = function (string $q) use ($txt, $s) { return $s ? (float) str_replace(',', '.', $txt($s, $q) ?: '0') : 0.0; };
            $hc = "./*[local-name()='homeCurrency']/*[local-name()='%s']";
            $celkem = $num(sprintf($hc, 'priceNone')) + $num(sprintf($hc, 'priceLowSum')) + $num(sprintf($hc, 'priceHighSum'))
                    + $num(sprintf($hc, 'price3Sum')) + $num("./*[local-name()='homeCurrency']/*[local-name()='round']/*[local-name()='priceRound']");
            $fc = $s ? $txt($s, "./*[local-name()='foreignCurrency']/*[local-name()='priceSum']") : '';
            $mena = $s ? $txt($s, "./*[local-name()='foreignCurrency']/*[local-name()='currency']/*[local-name()='ids']") : '';
            $item['invoices'][] = [
                'cislo'      => $h ? $txt($h, "./*[local-name()='number']/*[local-name()='numberRequested']") : '',
                'doslo_cislo'=> $h ? $txt($h, "./*[local-name()='originalDocument']") : '',
                'vs'         => $h ? $txt($h, "./*[local-name()='symVar']") : '',
                'datum'      => $h ? $txt($h, "./*[local-name()='date']") : '',
                'splatnost'  => $h ? $txt($h, "./*[local-name()='dateDue']") : '',
                'dodavatel'  => $h ? ($txt($h, "./*[local-name()='partnerIdentity']//*[local-name()='company']") ?: $txt($h, "./*[local-name()='partnerIdentity']//*[local-name()='name']")) : '',
                'ico'        => $h ? $txt($h, "./*[local-name()='partnerIdentity']//*[local-name()='ico']") : '',
                'text'       => $h ? $txt($h, "./*[local-name()='text']") : '',
                'celkem'     => round($fc !== '' && (float) $fc != 0 ? (float) $fc : $celkem, 2),
                'mena'       => ($fc !== '' && (float) $fc != 0 && $mena !== '') ? $mena : 'CZK',
            ];
        }
        $res['items'][] = $item;
    }
    return $res;
}

/** Lidsky čitelné shrnutí chyb/varování položky odpovědi. */
function pohoda_item_messages(array $item): string {
    $msgs = [];
    foreach ($item['details'] as $d) {
        if ($d['note'] === '') continue;
        $prefix = $d['state'] === 'error' ? 'Chyba' : ($d['state'] === 'warning' ? 'Upozornění' : 'Info');
        $msgs[] = $prefix . ($d['errno'] !== '' ? ' ' . $d['errno'] : '') . ': ' . $d['note'];
    }
    if (!$msgs && $item['note'] !== '') $msgs[] = $item['note'];
    return implode(' | ', array_unique($msgs));
}

// ═════════════════════════════════════════════════════════════════════
// mServer klient
// ═════════════════════════════════════════════════════════════════════

/** Ověří, že je POHODA nastavená pro přímé odesílání. Vrací null nebo českou chybu. */
function pohoda_cfg_chyba(array $cfg, bool $proOdeslani = true): ?string {
    if ($proOdeslani && ($cfg['pohoda_enabled'] ?? '') !== '1') return 'Integrace POHODA je vypnutá (Nastavení → Integrace).';
    if (($cfg['pohoda_url'] ?? '') === '') return 'Chybí adresa POHODA mServeru.';
    if (($cfg['pohoda_username'] ?? '') === '') return 'Chybí uživatel POHODY.';
    if (($cfg['pohoda_ico'] ?? '') === '') return 'Chybí IČO účetní jednotky v POHODĚ.';
    return null;
}

function pohoda_headers(array $cfg, bool $xml): array {
    $h = [
        'STW-Authorization: Basic ' . base64_encode(($cfg['pohoda_username'] ?? '') . ':' . ($cfg['pohoda_password'] ?? '')),
        'STW-Application: APPEK',
        'STW-Instance: APPEK-' . substr(hash('sha256', (string) ($cfg['pohoda_url'] ?? '')), 0, 8),
        'Accept-Encoding: gzip, deflate',
    ];
    if ($xml) $h[] = 'Content-Type: text/xml; charset=Windows-1250';
    return $h;
}

/** Odešle dataPack (bajty Windows-1250) na mServer. Vrací ['ok','error','parsed','raw','http']. */
function pohoda_send(array $cfg, string $dataPack, int $timeout = 60): array {
    if ($e = pohoda_cfg_chyba($cfg)) return ['ok' => false, 'error' => $e, 'parsed' => null, 'raw' => '', 'http' => 0];
    $r = ucetni_http('POST', rtrim($cfg['pohoda_url'], '/') . '/xml', pohoda_headers($cfg, true), $dataPack,
                     ['timeout' => $timeout, 'povolit_lan' => ($cfg['ucetni_povolit_lan'] ?? '') === '1']);
    $raw = (string) $r['body'];
    if (!$r['ok']) {
        $err = $r['error'] ?? 'Odeslání selhalo.';
        if ($r['http'] === 401) $err = 'POHODA odmítla přihlášení — špatný uživatel nebo heslo, případně uživatel v POHODĚ neexistuje (HTTP 401).';
        if ($r['http'] === 403) $err = 'Uživatel nemá v POHODĚ právo otevřít účetní jednotku nebo XML komunikaci (Práva → Soubor → Datová komunikace) (HTTP 403).';
        if ($r['http'] === 404) $err = 'mServer na této adrese neodpovídá (HTTP 404) — zadej adresu ve tvaru http://server:444 (bez /xml).';
        return ['ok' => false, 'error' => $err, 'parsed' => null, 'raw' => $raw, 'http' => $r['http']];
    }
    $p = pohoda_parse_response($raw);
    if (!$p['ok']) return ['ok' => false, 'error' => $p['error'], 'parsed' => null, 'raw' => $raw, 'http' => $r['http']];
    if ($p['state'] !== 'ok') {
        return ['ok' => false, 'error' => 'POHODA odmítla data: ' . ($p['note'] ?: 'neznámá chyba') , 'parsed' => $p, 'raw' => $raw, 'http' => $r['http']];
    }
    return ['ok' => true, 'error' => null, 'parsed' => $p, 'raw' => $raw, 'http' => $r['http']];
}

/** Test spojení: GET /status?companyDetail (ověří server, přihlášení i otevřenou firmu). */
function pohoda_test(array $cfg): array {
    if ($e = pohoda_cfg_chyba($cfg, false)) return ['ok' => false, 'error' => $e];
    $r = ucetni_http('GET', rtrim($cfg['pohoda_url'], '/') . '/status?companyDetail', pohoda_headers($cfg, false), null,
                     ['timeout' => 15, 'povolit_lan' => ($cfg['ucetni_povolit_lan'] ?? '') === '1']);
    if (!$r['ok']) {
        $err = $r['error'];
        if ($r['http'] === 401) $err = 'POHODA odmítla přihlášení — špatný uživatel nebo heslo (HTTP 401).';
        if ($r['http'] === 403) $err = 'Uživatel nemá v POHODĚ právo k mServeru / účetní jednotce (HTTP 403).';
        return ['ok' => false, 'error' => $err];
    }
    $raw = (string) $r['body'];
    $prev = libxml_use_internal_errors(true);
    $sx = @simplexml_load_string($raw, 'SimpleXMLElement', LIBXML_NONET);
    libxml_clear_errors(); libxml_use_internal_errors($prev);
    if (!$sx) return ['ok' => false, 'error' => 'Server odpověděl, ale nejde o POHODA mServer (neplatná odpověď na /status).'];
    $find = function (string $name) use ($sx): string {
        $n = $sx->xpath("//*[local-name()='$name']");
        return $n ? trim((string) $n[0]) : '';
    };
    $status = $find('status');
    $firma = $find('company');
    $rok = $find('year');
    $ico = $find('ico');
    $msg = 'Spojení s mServerem OK' . ($status ? " (stav: $status)" : '') . ($firma ? " — účetní jednotka: $firma" : '') . ($rok ? ", rok $rok" : '') . '.';
    $warn = '';
    if ($ico !== '' && ($cfg['pohoda_ico'] ?? '') !== '' && preg_replace('/\D/', '', $ico) !== preg_replace('/\D/', '', $cfg['pohoda_ico'])) {
        $warn = 'Pozor: mServer má otevřenou firmu s IČO ' . $ico . ', v nastavení je ' . $cfg['pohoda_ico'] . '.';
    }
    return ['ok' => true, 'message' => $msg, 'warning' => $warn, 'firma' => $firma];
}
