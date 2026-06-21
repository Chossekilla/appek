<?php
/**
 * Export faktur do ISDOC XML (Money S3 / Pohoda / účetní software podporující ISDOC).
 *
 * Použití:
 *   GET ?action=isdoc&id=X            — stáhne 1 ISDOC XML
 *   POST ?action=zip                  — body: { ids: [...] } → stáhne ZIP s víc XMLi
 *   GET ?action=csv&od=YYYY-MM-DD&do=YYYY-MM-DD — flat CSV faktur za období
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/_admin_auth.php';
cors_headers();
require_admin();

$pdo = db();
$action = $_GET['action'] ?? '';

// =============================================================
// Pomocné funkce
// =============================================================

function isdoc_xml(string $s): string {
    return htmlspecialchars((string) $s, ENT_XML1 | ENT_QUOTES, 'UTF-8');
}

/**
 * Načte fakturu i s položkami a stranami (dodavatel = firma, odběratel = zákazník).
 */
/** 🆕 v3.0.357 — CZ účet (prefix-číslo/kód) → IBAN (mod-97). '' když nejde rozparsovat. */
function isdoc_iban_cz(string $acct): string {
    if (!preg_match('~(?:(\d+)-)?(\d+)/(\d{4})~', $acct, $m)) return '';
    $bban = $m[3] . str_pad($m[1] ?? '', 6, '0', STR_PAD_LEFT) . str_pad($m[2], 10, '0', STR_PAD_LEFT);
    $mod = 0;
    foreach (str_split($bban . '123500') as $d) $mod = ($mod * 10 + (int) $d) % 97; // CZ00 → C=12 Z=35 0 0
    return 'CZ' . str_pad((string) (98 - $mod), 2, '0', STR_PAD_LEFT) . $bban;
}

function isdoc_nacti_fakturu(PDO $pdo, int $fa_id): ?array {
    $stmt = $pdo->prepare("
        SELECT f.*, od.nazev AS odb_nazev_aktualni,
               od.ico AS odb_ico_aktualni, od.dic AS odb_dic_aktualni,
               od.ulice AS odb_ulice_aktualni, od.mesto AS odb_mesto_aktualni,
               od.psc AS odb_psc_aktualni
        FROM faktury f
        JOIN odberatele od ON od.id = f.odberatel_id
        WHERE f.id = :id
    ");
    $stmt->execute(['id' => $fa_id]);
    $f = $stmt->fetch();
    if (!$f) return null;

    // Snapshot pro ruční fakturu, jinak aktuální
    if (!empty($f['rucni'])) {
        $f['odb_nazev'] = $f['odb_nazev_snapshot'] ?: $f['odb_nazev_aktualni'];
        $f['odb_ico']   = $f['odb_ico_snapshot']   ?: $f['odb_ico_aktualni'];
        $f['odb_dic']   = $f['odb_dic_snapshot']   ?: $f['odb_dic_aktualni'];
        $f['odb_ulice'] = $f['odb_ulice_snapshot'] ?: $f['odb_ulice_aktualni'];
        $f['odb_mesto'] = $f['odb_mesto_snapshot'] ?: $f['odb_mesto_aktualni'];
        $f['odb_psc']   = $f['odb_psc_snapshot']   ?: $f['odb_psc_aktualni'];
    } else {
        $f['odb_nazev'] = $f['odb_nazev_aktualni'];
        $f['odb_ico']   = $f['odb_ico_aktualni'];
        $f['odb_dic']   = $f['odb_dic_aktualni'];
        $f['odb_ulice'] = $f['odb_ulice_aktualni'];
        $f['odb_mesto'] = $f['odb_mesto_aktualni'];
        $f['odb_psc']   = $f['odb_psc_aktualni'];
    }

    // Položky - rozdílné zdroje pro ruční vs automatickou fakturu
    if (!empty($f['rucni'])) {
        $stmt = $pdo->prepare("
            SELECT vyrobek_cislo, vyrobek_nazev, jednotka, mnozstvi,
                   cena_bez_dph, sazba_dph, poznamka
            FROM faktura_polozky
            WHERE faktura_id = :id
            ORDER BY poradi, id
        ");
        $stmt->execute(['id' => $fa_id]);
        $f['polozky'] = $stmt->fetchAll();
    } else {
        $stmt = $pdo->prepare("
            SELECT dlp.vyrobek_cislo, dlp.vyrobek_nazev, dlp.jednotka,
                   dlp.mnozstvi, dlp.cena_bez_dph, dlp.sazba_dph, dlp.poznamka,
                   dl.cislo AS dl_cislo, o.cislo AS objednavka_cislo, o.datum_dodani
            FROM faktury_dodaci_listy fdl
            JOIN dodaci_listy dl ON dl.id = fdl.dodaci_list_id
            LEFT JOIN objednavky o ON o.id = dl.objednavka_id
            JOIN dodaci_list_polozky dlp ON dlp.dodaci_list_id = dl.id
            WHERE fdl.faktura_id = :id
            ORDER BY o.datum_dodani, dlp.id
        ");
        $stmt->execute(['id' => $fa_id]);
        $f['polozky'] = $stmt->fetchAll();
    }

    return $f;
}

function generuj_isdoc(PDO $pdo, array $f): string {
    $firma_nazev = nastaveni_get($pdo, 'firma_nazev', 'Provoz');
    $firma_ico   = nastaveni_get($pdo, 'firma_ico', '');
    $firma_dic   = nastaveni_get($pdo, 'firma_dic', '');
    $firma_ulice = nastaveni_get($pdo, 'firma_ulice', '');
    $firma_mesto = nastaveni_get($pdo, 'firma_mesto', '');
    $firma_psc   = nastaveni_get($pdo, 'firma_psc', '');
    $firma_email = nastaveni_get($pdo, 'firma_email', '');
    $firma_tel   = nastaveni_get($pdo, 'firma_telefon', '');
    $firma_banka = nastaveni_get($pdo, 'firma_banka', '');

    // UUID — generujeme z čísla faktury (deterministicky)
    $uuid = sprintf('%08s-%04s-%04s-%04s-%012s',
        substr(md5($f['cislo']), 0, 8),
        substr(md5($f['cislo']), 8, 4),
        substr(md5($f['cislo']), 12, 4),
        substr(md5($f['cislo']), 16, 4),
        substr(md5($f['cislo']), 20, 12)
    );

    // Sazby DPH — seskupení
    $sazby = []; // sazba => ['bez' => N, 'dph' => N]
    foreach ($f['polozky'] as $p) {
        $bez = (float) $p['cena_bez_dph'] * (float) $p['mnozstvi'];
        $sazba = (float) $p['sazba_dph'];
        $dph = $bez * $sazba / 100;
        $key = (string) $sazba;
        if (!isset($sazby[$key])) $sazby[$key] = ['sazba' => $sazba, 'bez' => 0, 'dph' => 0];
        $sazby[$key]['bez'] += $bez;
        $sazby[$key]['dph'] += $dph;
    }

    // Bankovní účet — rozdělit na číslo a kód banky
    $bankaCislo = '';
    $bankaKod   = '';
    if ($firma_banka && preg_match('/(\d+)\/(\d{4})/', $firma_banka, $m)) {
        $bankaCislo = $m[1];
        $bankaKod = $m[2];
    }
    // 🆕 v3.0.357 — IBAN (mod-97 z CZ účtu) + BIC (mapa hl. CZ bank); oba povinné v ISDOC BankAccount group
    $iban = $bankaCislo ? isdoc_iban_cz($firma_banka) : '';
    $bic  = ['0100'=>'KOMBCZPP','0300'=>'CEKOCZPP','0600'=>'AGBACZPP','0710'=>'CNBACZPP','0800'=>'GIBACZPX','2010'=>'FIOBCZPP','2250'=>'CTASCZ22','2700'=>'BACXCZPP','3030'=>'AIRACZPP','5500'=>'RZBCCZPP','6210'=>'BREXCZPP'][$bankaKod] ?? '';

    $datum_vys = $f['datum_vystaveni'];
    $datum_dph = $f['datum_dph'] ?: $datum_vys;
    $datum_spl = $f['datum_splatnosti'];
    // 🐛 fix v2.9.184 — VS fallback z čísla faktury: extract rok + pad sekvence na 4.
    // Předtím preg_replace('/\D/', '') vrátil 'FA-2026-1' → '20261' (5 cifer, nestandardní
    // pro účetní programy). Teď '20260001' (8 cifer = rok + 4-pad seq). Pokud sloupec
    // variabilni_symbol vyplněn → použít beze změny (právní stopa, neopravujeme).
    if (!empty($f['variabilni_symbol'])) {
        $vs = $f['variabilni_symbol'];
    } else {
        // Extract pattern XX-YYYY-NNNN
        if (preg_match('/(\d{4})-?(\d+)$/', $f['cislo'], $m)) {
            $vs = $m[1] . str_pad($m[2], 4, '0', STR_PAD_LEFT);
        } else {
            $vs = preg_replace('/\D/', '', $f['cislo']);
        }
    }

    $bez_total = round((float) $f['castka_bez_dph'], 2);
    $dph_total = round((float) $f['castka_dph'], 2);
    $cel_total = round((float) $f['castka_celkem'], 2);

    // Build XML
    $xml = '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
    $xml .= '<Invoice xmlns="http://isdoc.cz/namespace/2013" version="6.0.1">' . "\n";
    $xml .= '  <DocumentType>' . (!empty($f['je_dobropis']) ? '2' : '1') . '</DocumentType>' . "\n"; // 🆕 v3.0.357 — 2 = opravný daňový doklad (dobropis)
    $xml .= '  <ID>' . isdoc_xml($f['cislo']) . '</ID>' . "\n";
    $xml .= '  <UUID>' . $uuid . '</UUID>' . "\n";
    $xml .= '  <IssuingSystem>APPEK</IssuingSystem>' . "\n"; // 🆕 v3.0.356 — povinný element ISDOC (chyběl)
    $xml .= '  <IssueDate>' . $datum_vys . '</IssueDate>' . "\n";
    $xml .= '  <TaxPointDate>' . $datum_dph . '</TaxPointDate>' . "\n";
    $xml .= '  <VATApplicable>true</VATApplicable>' . "\n";
    $xml .= '  <ElectronicPossibilityAgreementReference />' . "\n";
    $xml .= '  <Note>' . isdoc_xml($f['poznamka'] ?? '') . '</Note>' . "\n";
    $xml .= '  <LocalCurrencyCode>CZK</LocalCurrencyCode>' . "\n";
    $xml .= '  <CurrRate>1</CurrRate>' . "\n";
    $xml .= '  <RefCurrRate>1</RefCurrRate>' . "\n";

    // Dodavatel (Seller)
    $xml .= '  <AccountingSupplierParty>' . "\n";
    $xml .= '    <Party>' . "\n";
    // 🆕 v3.0.357 — PartyIdentification je v XSD povinný (prázdné ID je validní string)
    $xml .= '      <PartyIdentification><ID>' . isdoc_xml($firma_ico) . '</ID></PartyIdentification>' . "\n";
    $xml .= '      <PartyName><Name>' . isdoc_xml($firma_nazev) . '</Name></PartyName>' . "\n";
    $xml .= '      <PostalAddress>' . "\n";
    $xml .= '        <StreetName>' . isdoc_xml($firma_ulice) . '</StreetName>' . "\n";
    $xml .= '        <BuildingNumber></BuildingNumber>' . "\n";
    $xml .= '        <CityName>' . isdoc_xml($firma_mesto) . '</CityName>' . "\n";
    $xml .= '        <PostalZone>' . isdoc_xml(preg_replace('/\s+/', '', $firma_psc)) . '</PostalZone>' . "\n";
    $xml .= '        <Country><IdentificationCode>CZ</IdentificationCode><Name>Česká republika</Name></Country>' . "\n";
    $xml .= '      </PostalAddress>' . "\n";
    if ($firma_dic) {
        $xml .= '      <PartyTaxScheme>' . "\n";
        $xml .= '        <CompanyID>' . isdoc_xml($firma_dic) . '</CompanyID>' . "\n";
        $xml .= '        <TaxScheme>VAT</TaxScheme>' . "\n";
        $xml .= '      </PartyTaxScheme>' . "\n";
    }
    if ($firma_email || $firma_tel) {
        $xml .= '      <Contact>' . "\n";
        if ($firma_email) $xml .= '        <ElectronicMail>' . isdoc_xml($firma_email) . '</ElectronicMail>' . "\n";
        if ($firma_tel)   $xml .= '        <Telephone>' . isdoc_xml($firma_tel) . '</Telephone>' . "\n";
        $xml .= '      </Contact>' . "\n";
    }
    $xml .= '    </Party>' . "\n";
    $xml .= '  </AccountingSupplierParty>' . "\n";

    // Odběratel (Buyer)
    $xml .= '  <AccountingCustomerParty>' . "\n";
    $xml .= '    <Party>' . "\n";
    // 🆕 v3.0.357 — PartyIdentification povinný i u odběratele
    $xml .= '      <PartyIdentification><ID>' . isdoc_xml($f['odb_ico'] ?? '') . '</ID></PartyIdentification>' . "\n";
    $xml .= '      <PartyName><Name>' . isdoc_xml($f['odb_nazev']) . '</Name></PartyName>' . "\n";
    $xml .= '      <PostalAddress>' . "\n";
    $xml .= '        <StreetName>' . isdoc_xml($f['odb_ulice']) . '</StreetName>' . "\n";
    $xml .= '        <BuildingNumber></BuildingNumber>' . "\n";
    $xml .= '        <CityName>' . isdoc_xml($f['odb_mesto']) . '</CityName>' . "\n";
    $xml .= '        <PostalZone>' . isdoc_xml(preg_replace('/\s+/', '', $f['odb_psc'])) . '</PostalZone>' . "\n";
    $xml .= '        <Country><IdentificationCode>CZ</IdentificationCode><Name>Česká republika</Name></Country>' . "\n";
    $xml .= '      </PostalAddress>' . "\n";
    if ($f['odb_dic']) {
        $xml .= '      <PartyTaxScheme>' . "\n";
        $xml .= '        <CompanyID>' . isdoc_xml($f['odb_dic']) . '</CompanyID>' . "\n";
        $xml .= '        <TaxScheme>VAT</TaxScheme>' . "\n";
        $xml .= '      </PartyTaxScheme>' . "\n";
    }
    $xml .= '    </Party>' . "\n";
    $xml .= '  </AccountingCustomerParty>' . "\n";

    // Položky
    $xml .= '  <InvoiceLines>' . "\n";
    $i = 0;
    foreach ($f['polozky'] as $p) {
        $i++;
        $mn  = (float) $p['mnozstvi'];
        $cena = (float) $p['cena_bez_dph'];
        $sazba = (float) $p['sazba_dph'];
        $bez = $mn * $cena;
        $dph = $bez * $sazba / 100;
        $jed = $p['jednotka'] ?: 'ks';

        $xml .= '    <InvoiceLine>' . "\n";
        $xml .= '      <ID>' . $i . '</ID>' . "\n";
        $xml .= '      <InvoicedQuantity unitCode="' . isdoc_xml($jed) . '">' . number_format($mn, 3, '.', '') . '</InvoicedQuantity>' . "\n";
        $xml .= '      <LineExtensionAmount>' . number_format($bez, 2, '.', '') . '</LineExtensionAmount>' . "\n";
        $xml .= '      <LineExtensionAmountTaxInclusive>' . number_format($bez + $dph, 2, '.', '') . '</LineExtensionAmountTaxInclusive>' . "\n";
        $xml .= '      <LineExtensionTaxAmount>' . number_format($dph, 2, '.', '') . '</LineExtensionTaxAmount>' . "\n";
        $xml .= '      <UnitPrice>' . number_format($cena, 2, '.', '') . '</UnitPrice>' . "\n";
        $xml .= '      <UnitPriceTaxInclusive>' . number_format($cena * (1 + $sazba / 100), 2, '.', '') . '</UnitPriceTaxInclusive>' . "\n";
        $xml .= '      <ClassifiedTaxCategory>' . "\n";
        $xml .= '        <Percent>' . number_format($sazba, 0, '.', '') . '</Percent>' . "\n";
        $xml .= '        <VATCalculationMethod>0</VATCalculationMethod>' . "\n";
        $xml .= '      </ClassifiedTaxCategory>' . "\n";
        $xml .= '      <Item><Description>' . isdoc_xml($p['vyrobek_nazev']) . '</Description>';
        if (!empty($p['vyrobek_cislo'])) {
            $xml .= '<SellersItemIdentification><ID>' . isdoc_xml($p['vyrobek_cislo']) . '</ID></SellersItemIdentification>';
        }
        $xml .= '</Item>' . "\n";
        $xml .= '    </InvoiceLine>' . "\n";
    }
    $xml .= '  </InvoiceLines>' . "\n";

    // TaxTotal
    $xml .= '  <TaxTotal>' . "\n";
    foreach ($sazby as $s) {
        $b = round($s['bez'], 2);
        $d = round($s['dph'], 2);
        $xml .= '    <TaxSubTotal>' . "\n";
        $xml .= '      <TaxableAmount>' . number_format($b, 2, '.', '') . '</TaxableAmount>' . "\n";
        $xml .= '      <TaxAmount>' . number_format($d, 2, '.', '') . '</TaxAmount>' . "\n";
        $xml .= '      <TaxInclusiveAmount>' . number_format($b + $d, 2, '.', '') . '</TaxInclusiveAmount>' . "\n";
        $xml .= '      <AlreadyClaimedTaxableAmount>0</AlreadyClaimedTaxableAmount>' . "\n";
        $xml .= '      <AlreadyClaimedTaxAmount>0</AlreadyClaimedTaxAmount>' . "\n";
        $xml .= '      <AlreadyClaimedTaxInclusiveAmount>0</AlreadyClaimedTaxInclusiveAmount>' . "\n";
        $xml .= '      <DifferenceTaxableAmount>' . number_format($b, 2, '.', '') . '</DifferenceTaxableAmount>' . "\n";
        $xml .= '      <DifferenceTaxAmount>' . number_format($d, 2, '.', '') . '</DifferenceTaxAmount>' . "\n";
        $xml .= '      <DifferenceTaxInclusiveAmount>' . number_format($b + $d, 2, '.', '') . '</DifferenceTaxInclusiveAmount>' . "\n";
        $xml .= '      <TaxCategory><Percent>' . number_format($s['sazba'], 0, '.', '') . '</Percent></TaxCategory>' . "\n";
        $xml .= '    </TaxSubTotal>' . "\n";
    }
    $xml .= '    <TaxAmount>' . number_format($dph_total, 2, '.', '') . '</TaxAmount>' . "\n";
    $xml .= '  </TaxTotal>' . "\n";

    // LegalMonetaryTotal
    $xml .= '  <LegalMonetaryTotal>' . "\n";
    $xml .= '    <TaxExclusiveAmount>' . number_format($bez_total, 2, '.', '') . '</TaxExclusiveAmount>' . "\n";
    $xml .= '    <TaxInclusiveAmount>' . number_format($cel_total, 2, '.', '') . '</TaxInclusiveAmount>' . "\n";
    $xml .= '    <AlreadyClaimedTaxExclusiveAmount>0</AlreadyClaimedTaxExclusiveAmount>' . "\n";
    $xml .= '    <AlreadyClaimedTaxInclusiveAmount>0</AlreadyClaimedTaxInclusiveAmount>' . "\n";
    $xml .= '    <DifferenceTaxExclusiveAmount>' . number_format($bez_total, 2, '.', '') . '</DifferenceTaxExclusiveAmount>' . "\n";
    $xml .= '    <DifferenceTaxInclusiveAmount>' . number_format($cel_total, 2, '.', '') . '</DifferenceTaxInclusiveAmount>' . "\n";
    $xml .= '    <PayableRoundingAmount>0</PayableRoundingAmount>' . "\n";
    $xml .= '    <PaidDepositsAmount>0</PaidDepositsAmount>' . "\n";
    $xml .= '    <PayableAmount>' . number_format($cel_total, 2, '.', '') . '</PayableAmount>' . "\n";
    $xml .= '  </LegalMonetaryTotal>' . "\n";

    // Platba — bankovní účet a VS
    if ($bankaCislo) {
        $xml .= '  <PaymentMeans>' . "\n";
        $xml .= '    <Payment>' . "\n";
        $xml .= '      <PaidAmount>' . number_format($cel_total, 2, '.', '') . '</PaidAmount>' . "\n";
        $xml .= '      <PaymentMeansCode>42</PaymentMeansCode>' . "\n"; // 42 = bankovní převod
        $xml .= '      <Details>' . "\n";
        $xml .= '        <PaymentDueDate>' . $datum_spl . '</PaymentDueDate>' . "\n";
        $xml .= '        <ID>' . isdoc_xml($bankaCislo) . '</ID>' . "\n";
        $xml .= '        <BankCode>' . isdoc_xml($bankaKod) . '</BankCode>' . "\n";
        $xml .= '        <Name>' . isdoc_xml($firma_nazev) . '</Name>' . "\n";
        $xml .= '        <IBAN>' . isdoc_xml($iban) . '</IBAN>' . "\n"; // 🆕 v3.0.357 — pořadí BankAccount: …Name,IBAN,BIC,VariableSymbol
        $xml .= '        <BIC>' . isdoc_xml($bic) . '</BIC>' . "\n";
        $xml .= '        <VariableSymbol>' . isdoc_xml($vs) . '</VariableSymbol>' . "\n";
        $xml .= '      </Details>' . "\n";
        $xml .= '    </Payment>' . "\n";
        $xml .= '  </PaymentMeans>' . "\n";
    }

    $xml .= '</Invoice>' . "\n";
    return $xml;
}

function safe_filename(string $s): string {
    $s = preg_replace('/[^A-Za-z0-9_-]+/', '_', $s);
    return trim($s, '_') ?: 'faktura';
}

// =============================================================
// SINGLE ISDOC
// =============================================================
if ($action === 'isdoc') {
    $id = (int) ($_GET['id'] ?? 0);
    if (!$id) json_error('Chybí ID');
    $f = isdoc_nacti_fakturu($pdo, $id);
    if (!$f) json_error('Faktura nenalezena', 404);

    $xml = generuj_isdoc($pdo, $f);
    $filename = 'FA_' . safe_filename($f['cislo']) . '.isdoc';
    header('Content-Type: application/xml; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    echo $xml;
    exit;
}

// =============================================================
// BULK ZIP
// =============================================================
if ($action === 'zip') {
    $body = json_input();
    $ids  = $body['ids'] ?? [];
    if (!is_array($ids) || count($ids) === 0) json_error('Vyber alespoň jednu fakturu');
    $ids  = array_filter(array_map('intval', $ids));
    if (empty($ids)) json_error('Neplatná ID');

    if (!class_exists('ZipArchive')) json_error('PHP ZipArchive není dostupné na serveru', 500);

    $tmpFile = tempnam(sys_get_temp_dir(), 'isdoc_');
    $zip = new ZipArchive();
    if ($zip->open($tmpFile, ZipArchive::OVERWRITE) !== true) json_error('Nelze vytvořit ZIP', 500);

    $added = 0;
    foreach ($ids as $id) {
        $f = isdoc_nacti_fakturu($pdo, $id);
        if (!$f) continue;
        $xml = generuj_isdoc($pdo, $f);
        $name = 'FA_' . safe_filename($f['cislo']) . '.isdoc';
        $zip->addFromString($name, $xml);
        $added++;
    }
    $zip->close();

    if ($added === 0) {
        unlink($tmpFile);
        json_error('Nic k exportu');
    }

    $filename = 'faktury_isdoc_' . date('Ymd_His') . '.zip';
    header('Content-Type: application/zip');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Content-Length: ' . filesize($tmpFile));
    readfile($tmpFile);
    unlink($tmpFile);
    exit;
}

// =============================================================
// CSV faktur za období (jednodušší export)
// =============================================================
if ($action === 'csv') {
    $od = $_GET['od'] ?? date('Y-m-01');
    $do = $_GET['do'] ?? date('Y-m-t');
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $od)) json_error('Neplatné datum od');
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $do)) json_error('Neplatné datum do');

    $stmt = $pdo->prepare("
        SELECT f.cislo, f.datum_vystaveni, f.datum_dph, f.datum_splatnosti,
               f.castka_bez_dph, f.castka_dph, f.castka_celkem, f.castka_uhrazeno,
               f.variabilni_symbol, f.poznamka,
               od.nazev AS odberatel, od.ico, od.dic
        FROM faktury f
        JOIN odberatele od ON od.id = f.odberatel_id
        WHERE f.datum_vystaveni BETWEEN :od AND :do
        ORDER BY f.datum_vystaveni, f.id
    ");
    $stmt->execute(['od' => $od, 'do' => $do]);
    $rows = $stmt->fetchAll();

    $filename = 'faktury_' . str_replace('-', '', $od) . '_' . str_replace('-', '', $do) . '.csv';
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF");
    fputcsv($out, ['Číslo', 'Datum vystavení', 'Datum DPH', 'Splatnost', 'VS',
                   'Odběratel', 'IČO', 'DIČ',
                   'Bez DPH', 'DPH', 'Celkem', 'Uhrazeno', 'Poznámka'], ';');
    foreach ($rows as $r) {
        // 🐛 fix v2.9.184 — VS fallback (stejně jako v ISDOC výše): pokud sloupec
        // variabilni_symbol není vyplněn, extract rok + pad sekvence z čísla.
        $vsCsv = $r['variabilni_symbol'];
        if (empty($vsCsv)) {
            if (preg_match('/(\d{4})-?(\d+)$/', $r['cislo'], $m)) {
                $vsCsv = $m[1] . str_pad($m[2], 4, '0', STR_PAD_LEFT);
            } else {
                $vsCsv = preg_replace('/\D/', '', $r['cislo']);
            }
        }
        fputcsv($out, [
            $r['cislo'],
            $r['datum_vystaveni'],
            $r['datum_dph'],
            $r['datum_splatnosti'],
            $vsCsv,
            $r['odberatel'],
            $r['ico'],
            $r['dic'],
            number_format((float) $r['castka_bez_dph'], 2, ',', ''),
            number_format((float) $r['castka_dph'], 2, ',', ''),
            number_format((float) $r['castka_celkem'], 2, ',', ''),
            number_format((float) $r['castka_uhrazeno'], 2, ',', ''),
            $r['poznamka'] ?? '',
        ], ';');
    }
    fclose($out);
    exit;
}

// =============================================================
// Pošli na email — ZIP s ISDOC v příloze
// =============================================================
if ($action === 'email') {
    $body = json_input();
    $ids = $body['ids'] ?? null;
    $od  = $body['od'] ?? null;
    $do  = $body['do'] ?? null;
    $email = trim($body['email'] ?? '');
    $predmet = trim($body['predmet'] ?? '');
    $zprava  = trim($body['zprava'] ?? '');
    if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) json_error('Neplatný email');

    // Pokud nejsou ids, načti je z období
    if ((!is_array($ids) || count($ids) === 0) && $od && $do) {
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $od)) json_error('Neplatné datum od');
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $do)) json_error('Neplatné datum do');
        $stmt = $pdo->prepare("
            SELECT id FROM faktury
            WHERE datum_vystaveni BETWEEN :od AND :do
            ORDER BY datum_vystaveni, id
        ");
        $stmt->execute(['od' => $od, 'do' => $do]);
        $ids = array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
    }
    if (!is_array($ids) || count($ids) === 0) json_error('Vyber faktury (ids nebo období)');
    $ids = array_values(array_filter(array_map('intval', $ids)));
    if (empty($ids)) json_error('Žádné platné ID');

    if (!class_exists('ZipArchive')) json_error('PHP ZipArchive není dostupné', 500);

    $tmp = tempnam(sys_get_temp_dir(), 'isdoc_mail_');
    $zip = new ZipArchive();
    if ($zip->open($tmp, ZipArchive::OVERWRITE) !== true) json_error('Nelze vytvořit ZIP', 500);
    $added = 0;
    foreach ($ids as $id) {
        $f = isdoc_nacti_fakturu($pdo, $id);
        if (!$f) continue;
        $xml = generuj_isdoc($pdo, $f);
        $zip->addFromString('FA_' . safe_filename($f['cislo']) . '.isdoc', $xml);
        $added++;
    }
    $zip->close();
    if ($added === 0) { unlink($tmp); json_error('Žádná z faktur nenalezena'); }

    $zipBytes = file_get_contents($tmp);
    unlink($tmp);
    $attachName = 'faktury_isdoc_' . date('Ymd_His') . '.zip';

    // Defaultní subject + body
    $firmaNazev = nastaveni_get($pdo, 'firma_nazev', 'Provoz');
    if ($predmet === '') $predmet = "Export faktur ISDOC ($added) — $firmaNazev";
    if ($zprava === '') {
        $zprava  = "Dobrý den,\n\n";
        $zprava .= "v příloze posílám export $added " . ($added === 1 ? 'faktury' : ($added < 5 ? 'faktur' : 'faktur')) . " ve formátu ISDOC pro účetnictví.\n";
        $zprava .= "Soubor lze importovat do Money S3, Pohoda, Helios a dalších účetních systémů s podporou ISDOC.\n\n";
        $zprava .= "S pozdravem\n$firmaNazev";
    }

    // Sestav multipart email s přílohou
    $boundary = md5(uniqid('', true));
    $fromEmail = nastaveni_get($pdo, 'firma_email', '') ?: 'noreply@' . ($_SERVER['HTTP_HOST'] ?? 'localhost');
    $fromName  = $firmaNazev;

    $headers = [];
    $headers[] = 'MIME-Version: 1.0';
    $headers[] = 'From: ' . mb_encode_mimeheader($fromName, 'UTF-8') . ' <' . $fromEmail . '>';
    $headers[] = 'Reply-To: ' . $fromEmail;
    $headers[] = 'Content-Type: multipart/mixed; boundary="' . $boundary . '"';
    $headers[] = 'X-Mailer: PHP/' . phpversion();

    $message  = '--' . $boundary . "\r\n";
    $message .= 'Content-Type: text/plain; charset=UTF-8' . "\r\n";
    $message .= 'Content-Transfer-Encoding: 8bit' . "\r\n\r\n";
    $message .= $zprava . "\r\n\r\n";
    $message .= '--' . $boundary . "\r\n";
    $message .= 'Content-Type: application/zip; name="' . $attachName . '"' . "\r\n";
    $message .= 'Content-Transfer-Encoding: base64' . "\r\n";
    $message .= 'Content-Disposition: attachment; filename="' . $attachName . '"' . "\r\n\r\n";
    $message .= chunk_split(base64_encode($zipBytes)) . "\r\n";
    $message .= '--' . $boundary . '--';

    $predmet_enc = '=?UTF-8?B?' . base64_encode($predmet) . '?=';
    $ok = appek_mail_raw($email, $predmet_enc, $message, implode("\r\n", $headers));
    if (!$ok) {
        error_log("ISDOC email failed for $email");
        json_error('Email se nepodařilo odeslat (PHP mail() selhal)', 500);
    }

    // Ulož email pro pohodlí příště
    try {
        $pdo->prepare("INSERT INTO nastaveni (klic, hodnota) VALUES ('export_isdoc_email', :v)
                       ON DUPLICATE KEY UPDATE hodnota = :v2")
            ->execute(['v' => $email, 'v2' => $email]);
    } catch (Throwable $e) { /* ignore */ }

    json_response([
        'ok' => true,
        'odeslano' => $added,
        'email' => $email,
        'velikost_kb' => round(strlen($zipBytes) / 1024, 1),
    ]);
}

json_error('Neznámá akce. Použij action=isdoc | zip | csv | email');
