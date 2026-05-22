<?php
/**
 * Import faktur z ISDOC XML / ZIP.
 *
 * Použití:
 *   POST ?action=preview    — multipart upload souboru `file` (ZIP nebo .isdoc) → vrátí náhled
 *   POST ?action=import     — multipart upload souboru `file`, opt. `skip_duplicates`=1 → vytvoří ruční faktury
 *
 * Importované faktury jsou vždy uloženy jako "ruční" (rucni=1) — bez napojení na DL,
 * se snapshotem odběratele tak, jak byl v ISDOC souboru.
 * Pokud odběratel s daným IČO existuje, je přiřazen; jinak se vytvoří nový.
 * Duplicitní čísla faktur jsou ve výchozím nastavení přeskočena.
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/_admin_auth.php';
cors_headers();
require_admin();

$pdo    = db();
$action = $_GET['action'] ?? '';
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

if ($method !== 'POST') json_error('Použij POST');
if (!in_array($action, ['preview', 'import'], true)) {
    json_error('Neznámá akce. Použij action=preview | import');
}

// =============================================================
// Soubor — multipart upload
// =============================================================
if (empty($_FILES['file']['tmp_name']) || !is_uploaded_file($_FILES['file']['tmp_name'])) {
    json_error('Chybí soubor (multipart field "file")');
}
$tmp      = $_FILES['file']['tmp_name'];
$origName = $_FILES['file']['name'] ?? 'soubor';
$mime     = mime_content_type($tmp) ?: '';
$ext      = strtolower(pathinfo($origName, PATHINFO_EXTENSION));

// =============================================================
// Načti XML soubory z uploadu (ZIP nebo jednotlivý .isdoc/.xml)
// =============================================================
/** @return array<string,string> name => xmlContent */
function nacti_isdoc_soubory(string $tmp, string $ext, string $mime): array {
    $vystup = [];

    $isZip = ($ext === 'zip') || $mime === 'application/zip' || $mime === 'application/x-zip-compressed';
    if ($isZip) {
        if (!class_exists('ZipArchive')) json_error('PHP ZipArchive není dostupné', 500);
        $zip = new ZipArchive();
        if ($zip->open($tmp) !== true) json_error('Nelze otevřít ZIP soubor');
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $stat = $zip->statIndex($i);
            $name = $stat['name'];
            // Skip dirs and macOS junk
            if (substr($name, -1) === '/') continue;
            if (strpos($name, '__MACOSX/') === 0) continue;
            $base = basename($name);
            if ($base === '' || $base[0] === '.') continue;
            $extIn = strtolower(pathinfo($name, PATHINFO_EXTENSION));
            if (!in_array($extIn, ['isdoc', 'xml', 'isdocx'], true)) continue;
            $content = $zip->getFromIndex($i);
            if ($content === false || $content === '') continue;
            // ISDOCX je ZIP — v hlavním ZIPu nepodporujeme, jen plain XML
            $vystup[$base] = $content;
        }
        $zip->close();
        return $vystup;
    }

    // Jediný soubor (.isdoc / .xml)
    if (!in_array($ext, ['isdoc', 'xml'], true)) {
        json_error('Podporované typy: .zip, .isdoc, .xml');
    }
    $content = file_get_contents($tmp);
    if ($content === false || $content === '') json_error('Soubor je prázdný');
    $vystup[$origName] = $content;
    return $vystup;
}

/**
 * Parsuj jeden ISDOC XML.
 * @return array struktura faktury nebo ['error' => string]
 */
function parsuj_isdoc(string $xml): array {
    $prev = libxml_use_internal_errors(true);
    $dom = new DOMDocument();
    $loaded = $dom->loadXML($xml, LIBXML_NONET | LIBXML_NOCDATA);
    libxml_clear_errors();
    libxml_use_internal_errors($prev);
    if (!$loaded) return ['error' => 'Neplatný XML'];

    $root = $dom->documentElement;
    if (!$root || strtolower($root->localName) !== 'invoice') {
        return ['error' => 'Není to ISDOC Invoice'];
    }

    // Namespace (pokud chybí, ber libovolný)
    $ns = $root->namespaceURI ?: 'http://isdoc.cz/namespace/2013';
    $xpath = new DOMXPath($dom);
    $xpath->registerNamespace('is', $ns);
    $X = function(string $expr, ?DOMNode $ctx = null) use ($xpath) {
        $r = $xpath->evaluate($expr, $ctx);
        if (is_string($r)) return trim($r);
        if (is_float($r)) return $r;
        return $r;
    };

    $cislo = (string) $X('string(/is:Invoice/is:ID)');
    if ($cislo === '') return ['error' => 'Chybí číslo faktury (ID)'];

    $datum_vys = (string) $X('string(/is:Invoice/is:IssueDate)');
    $datum_dph = (string) $X('string(/is:Invoice/is:TaxPointDate)');
    if ($datum_dph === '') $datum_dph = $datum_vys;

    $datum_spl = (string) $X('string(/is:Invoice/is:PaymentMeans/is:Payment/is:Details/is:PaymentDueDate)');
    if ($datum_spl === '') {
        // Fallback — splatnost = vystaveni + 14 dní
        if ($datum_vys !== '') {
            $datum_spl = date('Y-m-d', strtotime($datum_vys . ' +14 days'));
        }
    }

    $vs = (string) $X('string(/is:Invoice/is:PaymentMeans/is:Payment/is:Details/is:VariableSymbol)');
    if ($vs === '') $vs = preg_replace('/\D/', '', $cislo);
    $note = (string) $X('string(/is:Invoice/is:Note)');

    // Odběratel = AccountingCustomerParty
    $cust = '/is:Invoice/is:AccountingCustomerParty/is:Party';
    $odb = [
        'nazev' => (string) $X("string($cust/is:PartyName/is:Name)"),
        'ico'   => (string) $X("string($cust/is:PartyIdentification/is:ID)"),
        'dic'   => (string) $X("string($cust/is:PartyTaxScheme/is:CompanyID)"),
        'ulice' => (string) $X("string($cust/is:PostalAddress/is:StreetName)"),
        'mesto' => (string) $X("string($cust/is:PostalAddress/is:CityName)"),
        'psc'   => (string) $X("string($cust/is:PostalAddress/is:PostalZone)"),
    ];
    if ($odb['nazev'] === '') return ['error' => 'Chybí název odběratele'];

    // Položky
    $polozky = [];
    $lines = $xpath->query('/is:Invoice/is:InvoiceLines/is:InvoiceLine');
    foreach ($lines as $line) {
        $popis  = (string) $X('string(is:Item/is:Description)', $line);
        $mn     = (float)  $X('number(is:InvoicedQuantity)', $line);
        // unitCode atribut — jednotky
        $jed = '';
        $qNode = $xpath->query('is:InvoicedQuantity', $line)->item(0);
        if ($qNode instanceof DOMElement) $jed = $qNode->getAttribute('unitCode');
        if ($jed === '') $jed = 'ks';

        $cena   = (float) $X('number(is:UnitPrice)', $line);
        $sazba  = (float) $X('number(is:ClassifiedTaxCategory/is:Percent)', $line);
        $vyr_c  = (string) $X('string(is:Item/is:SellersItemIdentification/is:ID)', $line);
        $bez    = (float) $X('number(is:LineExtensionAmount)', $line);

        // Pokud UnitPrice chybí, ale máme bez_dph a množství > 0, dopočítej
        if ($cena <= 0 && $mn > 0 && $bez > 0) $cena = $bez / $mn;

        if ($popis === '' && $mn <= 0) continue; // skip prázdné

        $polozky[] = [
            'vyrobek_cislo' => $vyr_c,
            'vyrobek_nazev' => $popis,
            'jednotka'      => $jed,
            'mnozstvi'      => $mn > 0 ? $mn : 1,
            'cena_bez_dph'  => round($cena, 4),
            'sazba_dph'     => round($sazba, 2),
        ];
    }
    if (empty($polozky)) return ['error' => 'Faktura nemá žádné položky'];

    // Souhrny
    $bez_total = (float) $X('number(/is:Invoice/is:LegalMonetaryTotal/is:TaxExclusiveAmount)');
    $cel_total = (float) $X('number(/is:Invoice/is:LegalMonetaryTotal/is:TaxInclusiveAmount)');
    if ($cel_total <= 0) $cel_total = (float) $X('number(/is:Invoice/is:LegalMonetaryTotal/is:PayableAmount)');
    $dph_total = (float) $X('number(/is:Invoice/is:TaxTotal/is:TaxAmount)');

    // Pokud souhrny chybí, dopočítej z položek
    if ($bez_total <= 0 || $cel_total <= 0) {
        $bez_total = 0;
        $dph_total = 0;
        foreach ($polozky as $p) {
            $b = $p['cena_bez_dph'] * $p['mnozstvi'];
            $bez_total += $b;
            $dph_total += $b * ($p['sazba_dph'] / 100);
        }
        $cel_total = $bez_total + $dph_total;
    }

    return [
        'cislo' => $cislo,
        'datum_vystaveni'   => $datum_vys ?: date('Y-m-d'),
        'datum_dph'         => $datum_dph ?: $datum_vys ?: date('Y-m-d'),
        'datum_splatnosti'  => $datum_spl ?: date('Y-m-d'),
        'variabilni_symbol' => $vs,
        'poznamka'          => $note,
        'odberatel'         => $odb,
        'polozky'           => $polozky,
        'castka_bez_dph'    => round($bez_total, 2),
        'castka_dph'        => round($dph_total, 2),
        'castka_celkem'     => round($cel_total, 2),
    ];
}

// =============================================================
// PREVIEW — jen parsuj a vrať souhrn
// =============================================================
if ($action === 'preview') {
    $soubory = nacti_isdoc_soubory($tmp, $ext, $mime);
    if (empty($soubory)) json_error('V uploadu nebyl nalezen žádný ISDOC soubor');

    $items   = [];
    $errors  = [];

    foreach ($soubory as $name => $xml) {
        $f = parsuj_isdoc($xml);
        if (!empty($f['error'])) {
            $errors[] = ['file' => $name, 'msg' => $f['error']];
            continue;
        }
        // Existuje již faktura s tímto číslem?
        $stmt = $pdo->prepare("SELECT id FROM faktury WHERE cislo = :c LIMIT 1");
        $stmt->execute(['c' => $f['cislo']]);
        $existing = $stmt->fetchColumn();

        $items[] = [
            'file'           => $name,
            'cislo'          => $f['cislo'],
            'datum_vystaveni'=> $f['datum_vystaveni'],
            'castka_celkem'  => $f['castka_celkem'],
            'odberatel'      => $f['odberatel']['nazev'],
            'ico'            => $f['odberatel']['ico'],
            'pocet_polozek'  => count($f['polozky']),
            'duplicate'      => $existing ? true : false,
            'existing_id'    => $existing ? (int) $existing : null,
        ];
    }

    json_response([
        'ok'         => true,
        'pocet'      => count($items),
        'duplicate'  => count(array_filter($items, fn($i) => $i['duplicate'])),
        'items'      => $items,
        'errors'     => $errors,
    ]);
}

// =============================================================
// IMPORT — skutečně vytvoř ruční faktury
// =============================================================
if ($action === 'import') {
    $skipDup = !empty($_POST['skip_duplicates']) ? true : false;
    if (isset($_POST['skip_duplicates']) && $_POST['skip_duplicates'] === '0') $skipDup = false;

    $soubory = nacti_isdoc_soubory($tmp, $ext, $mime);
    if (empty($soubory)) json_error('V uploadu nebyl nalezen žádný ISDOC soubor');

    $created      = [];
    $skipped      = [];
    $errors       = [];
    $newOdbCount  = 0;

    foreach ($soubory as $name => $xml) {
        $f = parsuj_isdoc($xml);
        if (!empty($f['error'])) {
            $errors[] = ['file' => $name, 'msg' => $f['error']];
            continue;
        }

        // Duplicita?
        $stmt = $pdo->prepare("SELECT id FROM faktury WHERE cislo = :c LIMIT 1");
        $stmt->execute(['c' => $f['cislo']]);
        $existingId = $stmt->fetchColumn();
        if ($existingId) {
            if ($skipDup) {
                $skipped[] = ['file' => $name, 'cislo' => $f['cislo'], 'reason' => 'duplicate', 'existing_id' => (int) $existingId];
                continue;
            }
            $errors[] = ['file' => $name, 'msg' => "Faktura {$f['cislo']} už existuje (ID {$existingId})"];
            continue;
        }

        // Najdi nebo vytvoř odběratele
        $odb = $f['odberatel'];
        $odbId = null;
        if (!empty($odb['ico'])) {
            $s = $pdo->prepare("SELECT id FROM odberatele WHERE ico = :i LIMIT 1");
            $s->execute(['i' => $odb['ico']]);
            $odbId = $s->fetchColumn() ?: null;
        }
        if (!$odbId) {
            // Match by name (fallback)
            $s = $pdo->prepare("SELECT id FROM odberatele WHERE nazev = :n LIMIT 1");
            $s->execute(['n' => $odb['nazev']]);
            $odbId = $s->fetchColumn() ?: null;
        }
        if (!$odbId) {
            try {
                $ins = $pdo->prepare("
                    INSERT INTO odberatele (nazev, ico, dic, ulice, mesto, psc, splatnost_dni, sleva_pct, blokovan)
                    VALUES (:n, :i, :d, :u, :m, :p, 14, 0, 0)
                ");
                $ins->execute([
                    'n' => $odb['nazev'], 'i' => $odb['ico'] ?: null,
                    'd' => $odb['dic'] ?: null, 'u' => $odb['ulice'] ?: null,
                    'm' => $odb['mesto'] ?: null, 'p' => $odb['psc'] ?: null,
                ]);
                $odbId = (int) $pdo->lastInsertId();
                $newOdbCount++;
            } catch (Throwable $e) {
                $errors[] = ['file' => $name, 'msg' => 'Nepodařilo se vytvořit odběratele: ' . $e->getMessage()];
                continue;
            }
        }
        $odbId = (int) $odbId;

        // Vytvoř fakturu jako ruční
        $pdo->beginTransaction();
        try {
            $pdo->prepare("
                INSERT INTO faktury (
                    cislo, odberatel_id,
                    datum_vystaveni, datum_dph, datum_splatnosti,
                    castka_bez_dph, castka_dph, castka_celkem,
                    variabilni_symbol, poznamka, rucni,
                    odb_nazev_snapshot, odb_ico_snapshot, odb_dic_snapshot,
                    odb_ulice_snapshot, odb_mesto_snapshot, odb_psc_snapshot
                ) VALUES (
                    :c, :o,
                    :dv, :dd, :ds,
                    :b, :dph, :cel,
                    :vs, :p, 1,
                    :n, :ico, :dic, :ul, :me, :psc
                )
            ")->execute([
                'c' => $f['cislo'], 'o' => $odbId,
                'dv' => $f['datum_vystaveni'], 'dd' => $f['datum_dph'], 'ds' => $f['datum_splatnosti'],
                'b' => $f['castka_bez_dph'], 'dph' => $f['castka_dph'], 'cel' => $f['castka_celkem'],
                'vs' => $f['variabilni_symbol'],
                'p'  => trim(($f['poznamka'] ? $f['poznamka'] . "\n" : '') . 'Importováno z ISDOC: ' . $name),
                'n'   => $odb['nazev'],
                'ico' => $odb['ico'] ?: null,
                'dic' => $odb['dic'] ?: null,
                'ul'  => $odb['ulice'] ?: null,
                'me'  => $odb['mesto'] ?: null,
                'psc' => $odb['psc'] ?: null,
            ]);
            $faId = (int) $pdo->lastInsertId();

            // Pokus o párování položek na existující výrobky podle čísla
            $matchVyr = $pdo->prepare("SELECT id FROM vyrobky WHERE cislo = :c LIMIT 1");
            $stmtPol = $pdo->prepare("
                INSERT INTO faktura_polozky
                    (faktura_id, vyrobek_id, vyrobek_cislo, vyrobek_nazev, jednotka,
                     mnozstvi, cena_bez_dph, sazba_dph, poznamka, poradi)
                VALUES (:f, :v, :c, :n, :j, :m, :ce, :s, :p, :po)
            ");
            $poradi = 0;
            foreach ($f['polozky'] as $p) {
                $poradi++;
                $vid = null;
                if ($p['vyrobek_cislo']) {
                    $matchVyr->execute(['c' => $p['vyrobek_cislo']]);
                    $rid = $matchVyr->fetchColumn();
                    if ($rid) $vid = (int) $rid;
                }
                $stmtPol->execute([
                    'f' => $faId, 'v' => $vid,
                    'c' => $p['vyrobek_cislo'] ?: null,
                    'n' => $p['vyrobek_nazev'], 'j' => $p['jednotka'],
                    'm' => $p['mnozstvi'], 'ce' => $p['cena_bez_dph'],
                    's' => $p['sazba_dph'], 'p' => null, 'po' => $poradi,
                ]);
            }

            $pdo->commit();
            $created[] = ['file' => $name, 'id' => $faId, 'cislo' => $f['cislo'], 'castka' => $f['castka_celkem']];
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            $errors[] = ['file' => $name, 'msg' => 'DB chyba: ' . $e->getMessage()];
        }
    }

    json_response([
        'ok'             => true,
        'vytvoreno'      => count($created),
        'preskoceno'     => count($skipped),
        'chyb'           => count($errors),
        'novych_odb'     => $newOdbCount,
        'created'        => $created,
        'skipped'        => $skipped,
        'errors'         => $errors,
    ]);
}

json_error('Neznámá akce');
