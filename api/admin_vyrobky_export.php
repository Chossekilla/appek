<?php
/**
 * 📤 Export katalogu výrobků — XML / CSV / JSON / Heureka
 *
 * GET ?format=xml      → Univerzální XML (Money S3 / Pohoda / e-shop)
 * GET ?format=csv      → CSV s BOM + středník (Excel-friendly, UTF-8)
 * GET ?format=json     → JSON pro API integrace
 * GET ?format=heureka  → XML feed pro Heureku.cz
 * GET ?format=zbozi    → XML feed pro Zboží.cz
 *
 * Volitelné parametry:
 *   ?aktivni=0    → exportuj i skryté výrobky (default jen aktivní)
 *   ?kategorie=5  → jen vybraná kategorie
 *
 * Bezpečnost: vyžaduje admin login (require_admin).
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/_admin_auth.php';
cors_headers();
require_admin();

$pdo = db();
$format = strtolower(trim($_GET['format'] ?? 'csv'));
$jenAktivni = !isset($_GET['aktivni']) || $_GET['aktivni'] !== '0';
$katFilter = isset($_GET['kategorie']) ? (int) $_GET['kategorie'] : null;

// Načti firma data pro hlavičku
$firmaNazev = (string) nastaveni_get($pdo, 'firma_nazev', 'APPEK B2B');
$firmaIco   = (string) nastaveni_get($pdo, 'firma_ico', '');
$firmaWeb   = (string) nastaveni_get($pdo, 'firma_web', '');

// Detekce existujících sloupců — některé jsou v základním schématu, jiné auto-migrované.
// Pokud sloupec neexistuje, do SELECT ho nepřidávat (jinak SQL error).
$colsExist = [];
try {
    $cols = $pdo->query("
        SELECT COLUMN_NAME FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'vyrobky'
    ")->fetchAll(PDO::FETCH_COLUMN);
    $colsExist = array_flip(array_map('strtolower', $cols));
} catch (Throwable $e) { /* pokud nedokáže zjistit, používej safe default */ }
$hasCol = function(string $name) use ($colsExist): bool {
    return isset($colsExist[strtolower($name)]);
};
// Volitelné sloupce — vlož COALESCE/NULL pokud neexistují, aby SQL nepadalo
$selOptional = function(string $name, string $alias = null) use ($hasCol): string {
    $alias = $alias ?? $name;
    return $hasCol($name) ? "v.$name AS $alias" : "NULL AS $alias";
};

// SQL — výrobky se všemi potřebnými daty (resilientní vůči chybějícím sloupcům)
$sql = "
    SELECT
        v.id, v.cislo, v.nazev, v.popis,
        " . $selOptional('ean') . ",
        v.cena_bez_dph,
        " . $selOptional('vyrobni_cena') . ",
        " . $selOptional('hmotnost_g') . ",
        " . $selOptional('obsah') . ",
        " . $selOptional('obsah_jednotka') . ",
        v.aktivni,
        " . $selOptional('oblibeny') . ",
        " . $selOptional('je_akce') . ",
        " . $selOptional('je_novinka') . ",
        " . $selOptional('je_doprodej') . ",
        " . $selOptional('je_vyprodano') . ",
        " . $selOptional('obrazek_url') . ",
        " . $selOptional('slozeni') . ",
        " . $selOptional('alergeny') . ",
        " . $selOptional('nutricni_hodnoty') . ",
        " . $selOptional('poradi') . ",
        " . ($hasCol('min_objednavka') ? "v.min_objednavka" : "1 AS min_objednavka") . ",
        j.kod  AS jednotka, j.nazev AS jednotka_nazev,
        s.sazba AS sazba_dph, s.nazev AS sazba_dph_nazev,
        k.id   AS kategorie_id, k.nazev AS kategorie, k.ikona AS kategorie_ikona
    FROM vyrobky v
    LEFT JOIN jednotky j         ON j.id = v.jednotka_id
    LEFT JOIN sazby_dph s        ON s.id = v.sazba_dph_id
    LEFT JOIN kategorie_vyrobku k ON k.id = v.kategorie_id
    WHERE 1=1
";
$params = [];
if ($jenAktivni)             { $sql .= " AND v.aktivni = 1"; }
if ($katFilter !== null)     { $sql .= " AND v.kategorie_id = :kat"; $params['kat'] = $katFilter; }
// ORDER BY — kategorie.poradi a vyrobky.poradi jen pokud existují
$orderBy = [];
$orderBy[] = "k.poradi";
if ($hasCol('poradi')) $orderBy[] = "v.poradi";
$orderBy[] = "v.nazev";
$sql .= " ORDER BY " . implode(', ', $orderBy);

try {
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $vyrobky = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    // Posli detailní chybu místo aby skript spadl bez výstupu
    header('Content-Type: application/json; charset=UTF-8');
    http_response_code(500);
    echo json_encode([
        'error'   => 'Chyba při načítání výrobků',
        'detail'  => $e->getMessage(),
        'hint'    => 'Zkontroluj schéma databáze — možná chybí sloupec. Otevři Nastavení → 🩺 Diagnostika.',
        'sql_state' => $e->getCode(),
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    exit;
}

// Helper: cena s DPH
$cenaSDph = function ($v) {
    $bez = (float) ($v['cena_bez_dph'] ?? 0);
    $dph = (float) ($v['sazba_dph'] ?? 0);
    return round($bez * (1 + $dph / 100), 2);
};

// Dispatch podle formátu
switch ($format) {
    case 'csv':
        export_csv($vyrobky, $cenaSDph);
        break;
    case 'json':
        export_json($vyrobky, $firmaNazev);
        break;
    case 'xml':
        export_xml($vyrobky, $cenaSDph, $firmaNazev, $firmaIco);
        break;
    case 'heureka':
        export_heureka($vyrobky, $cenaSDph, $firmaNazev, $firmaWeb);
        break;
    case 'zbozi':
        export_zbozi($vyrobky, $cenaSDph, $firmaNazev, $firmaWeb);
        break;
    default:
        json_error('Neznámý formát. Použij: xml | csv | json | heureka | zbozi', 400);
}

// ────────────────────────────────────────────────────────────
// FORMÁTY EXPORTU
// ────────────────────────────────────────────────────────────

function export_csv(array $vyrobky, callable $cenaSDph): void {
    $datum = date('Y-m-d');
    header('Content-Type: text/csv; charset=UTF-8');
    header("Content-Disposition: attachment; filename=\"vyrobky-$datum.csv\"");

    $out = fopen('php://output', 'w');
    // UTF-8 BOM pro Excel (jinak Excel zobrazí "ÄŤeĹˇtina")
    fwrite($out, "\xEF\xBB\xBF");

    // Hlavička
    fputcsv($out, [
        'Číslo', 'EAN', 'Název', 'Kategorie', 'Cena bez DPH', 'Sazba DPH (%)', 'Cena s DPH',
        'Jednotka', 'Hmotnost (g)', 'Obsah', 'Obsah jednotka',
        'Popis', 'Složení', 'Alergeny', 'Nutriční hodnoty',
        'Aktivní', 'Oblíbený', 'Akce', 'Novinka', 'Doprodej',
        'Obrázek URL',
    ], ';');

    foreach ($vyrobky as $v) {
        fputcsv($out, [
            $v['cislo']           ?? '',
            $v['ean']             ?? '',
            $v['nazev']           ?? '',
            $v['kategorie']       ?? '',
            number_format((float)($v['cena_bez_dph'] ?? 0), 2, ',', ''),
            (int) ($v['sazba_dph'] ?? 0),
            number_format($cenaSDph($v), 2, ',', ''),
            $v['jednotka']        ?? 'ks',
            $v['hmotnost_g']      ?? '',
            $v['obsah']           ?? '',
            $v['obsah_jednotka']  ?? '',
            $v['popis']           ?? '',
            $v['slozeni']         ?? '',
            $v['alergeny']        ?? '',
            $v['nutricni_hodnoty']?? '',
            $v['aktivni'] ? 'ano' : 'ne',
            $v['oblibeny'] ? 'ano' : 'ne',
            $v['je_akce'] ? 'ano' : 'ne',
            $v['je_novinka'] ? 'ano' : 'ne',
            $v['je_doprodej'] ? 'ano' : 'ne',
            $v['obrazek_url']     ?? '',
        ], ';');
    }
    fclose($out);
    exit;
}

function export_json(array $vyrobky, string $firma): void {
    $datum = date('Y-m-d');
    header('Content-Type: application/json; charset=UTF-8');
    header("Content-Disposition: attachment; filename=\"vyrobky-$datum.json\"");

    $data = [
        'exported_at' => date('c'),
        'firma'       => $firma,
        'pocet'       => count($vyrobky),
        'vyrobky'     => $vyrobky,
    ];
    echo json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function export_xml(array $vyrobky, callable $cenaSDph, string $firma, string $ico): void {
    $datum = date('Y-m-d');
    header('Content-Type: application/xml; charset=UTF-8');
    header("Content-Disposition: attachment; filename=\"vyrobky-$datum.xml\"");

    $xml = new XMLWriter();
    $xml->openMemory();
    $xml->startDocument('1.0', 'UTF-8');
    $xml->setIndent(true);
    $xml->setIndentString('  ');
    $xml->startElement('katalog');
    $xml->writeAttribute('verze', '1.0');
    $xml->writeAttribute('vygenerovano', date('c'));
    $xml->writeElement('firma', $firma);
    if ($ico) $xml->writeElement('ico', $ico);
    $xml->writeElement('pocet_vyrobku', (string) count($vyrobky));

    $xml->startElement('vyrobky');
    foreach ($vyrobky as $v) {
        $xml->startElement('vyrobek');
        $xml->writeAttribute('id', (string) $v['id']);

        $xml->writeElement('cislo',   (string) ($v['cislo'] ?? ''));
        if (!empty($v['ean'])) $xml->writeElement('ean', (string) $v['ean']);
        $xml->writeElement('nazev',   (string) $v['nazev']);
        $xml->writeElement('kategorie', (string) ($v['kategorie'] ?? ''));

        $xml->startElement('cena');
        $xml->writeAttribute('mena', 'CZK');
        $xml->writeElement('bez_dph', number_format((float)($v['cena_bez_dph'] ?? 0), 2, '.', ''));
        $xml->writeElement('sazba_dph', (string) (int)($v['sazba_dph'] ?? 0));
        $xml->writeElement('s_dph', number_format($cenaSDph($v), 2, '.', ''));
        $xml->endElement();

        $xml->writeElement('jednotka', (string) ($v['jednotka'] ?? 'ks'));
        if ($v['hmotnost_g']) $xml->writeElement('hmotnost_g', (string) $v['hmotnost_g']);
        if ($v['obsah']) {
            $xml->startElement('obsah');
            $xml->writeAttribute('jednotka', (string) ($v['obsah_jednotka'] ?? ''));
            $xml->text((string) $v['obsah']);
            $xml->endElement();
        }
        if (!empty($v['popis']))           $xml->writeElement('popis', (string) $v['popis']);
        if (!empty($v['slozeni']))         $xml->writeElement('slozeni', (string) $v['slozeni']);
        if (!empty($v['alergeny']))        $xml->writeElement('alergeny', (string) $v['alergeny']);
        if (!empty($v['nutricni_hodnoty']))$xml->writeElement('nutricni_hodnoty', (string) $v['nutricni_hodnoty']);

        $xml->startElement('stav');
        $xml->writeElement('aktivni',  $v['aktivni'] ? 'true' : 'false');
        $xml->writeElement('oblibeny', $v['oblibeny'] ? 'true' : 'false');
        $xml->writeElement('akce',     $v['je_akce'] ? 'true' : 'false');
        $xml->writeElement('novinka',  $v['je_novinka'] ? 'true' : 'false');
        $xml->writeElement('doprodej', $v['je_doprodej'] ? 'true' : 'false');
        $xml->endElement();

        if (!empty($v['obrazek_url'])) {
            $xml->writeElement('obrazek_url', (string) $v['obrazek_url']);
        }

        $xml->endElement(); // vyrobek
    }
    $xml->endElement(); // vyrobky
    $xml->endElement(); // katalog
    $xml->endDocument();

    echo $xml->outputMemory();
    exit;
}

/**
 * Heureka XML feed — https://sluzby.heureka.cz/napoveda/xml-feed/
 */
function export_heureka(array $vyrobky, callable $cenaSDph, string $firma, string $web): void {
    $datum = date('Y-m-d');
    header('Content-Type: application/xml; charset=UTF-8');
    header("Content-Disposition: attachment; filename=\"heureka-feed-$datum.xml\"");

    $base = $web ? rtrim($web, '/') : '';
    if ($base && !preg_match('#^https?://#', $base)) $base = 'https://' . $base;

    $xml = new XMLWriter();
    $xml->openMemory();
    $xml->startDocument('1.0', 'UTF-8');
    $xml->setIndent(true);
    $xml->startElement('SHOP');

    foreach ($vyrobky as $v) {
        $xml->startElement('SHOPITEM');
        $xml->writeElement('ITEM_ID', (string) $v['id']);
        $xml->writeElement('PRODUCTNAME', (string) $v['nazev']);
        if (!empty($v['popis'])) {
            $xml->startElement('DESCRIPTION');
            $xml->writeCData(strip_tags((string) $v['popis']));
            $xml->endElement();
        }
        if ($base) $xml->writeElement('URL', $base);
        if (!empty($v['obrazek_url'])) {
            $imgUrl = (string) $v['obrazek_url'];
            if ($base && !preg_match('#^https?://#', $imgUrl)) {
                $imgUrl = $base . '/' . ltrim($imgUrl, '/');
            }
            $xml->writeElement('IMGURL', $imgUrl);
        }
        $xml->writeElement('PRICE_VAT', number_format($cenaSDph($v), 2, '.', ''));
        if (!empty($v['ean'])) $xml->writeElement('EAN', (string) $v['ean']);
        if (!empty($v['kategorie'])) $xml->writeElement('CATEGORYTEXT', 'Potraviny | Potravinářské výrobky | ' . (string) $v['kategorie']);
        $xml->writeElement('MANUFACTURER', $firma);
        $xml->writeElement('DELIVERY_DATE', $v['aktivni'] ? '0' : '7'); // 0 = skladem
        $xml->endElement(); // SHOPITEM
    }
    $xml->endElement(); // SHOP
    $xml->endDocument();

    echo $xml->outputMemory();
    exit;
}

/**
 * Zboží.cz XML feed — https://napoveda.seznam.cz/cz/zbozi/specifikace-xml/
 */
function export_zbozi(array $vyrobky, callable $cenaSDph, string $firma, string $web): void {
    $datum = date('Y-m-d');
    header('Content-Type: application/xml; charset=UTF-8');
    header("Content-Disposition: attachment; filename=\"zbozi-feed-$datum.xml\"");

    $base = $web ? rtrim($web, '/') : '';
    if ($base && !preg_match('#^https?://#', $base)) $base = 'https://' . $base;

    $xml = new XMLWriter();
    $xml->openMemory();
    $xml->startDocument('1.0', 'UTF-8');
    $xml->setIndent(true);
    $xml->startElement('SHOP');
    $xml->writeAttribute('xmlns', 'http://www.zbozi.cz/ns/offer/1.0');

    foreach ($vyrobky as $v) {
        $xml->startElement('SHOPITEM');
        $xml->writeElement('PRODUCT', (string) $v['nazev']);
        $xml->writeElement('PRODUCTNAME', (string) $v['nazev']);
        if (!empty($v['popis'])) {
            $xml->startElement('DESCRIPTION');
            $xml->writeCData(strip_tags((string) $v['popis']));
            $xml->endElement();
        }
        if ($base) $xml->writeElement('URL', $base);
        if (!empty($v['obrazek_url'])) {
            $imgUrl = (string) $v['obrazek_url'];
            if ($base && !preg_match('#^https?://#', $imgUrl)) {
                $imgUrl = $base . '/' . ltrim($imgUrl, '/');
            }
            $xml->writeElement('IMGURL', $imgUrl);
        }
        $xml->writeElement('PRICE_VAT', number_format($cenaSDph($v), 2, '.', ''));
        $xml->writeElement('ITEM_ID', (string) $v['id']);
        if (!empty($v['ean'])) $xml->writeElement('EAN', (string) $v['ean']);
        if (!empty($v['kategorie'])) $xml->writeElement('CATEGORYTEXT', (string) $v['kategorie']);
        $xml->writeElement('MANUFACTURER', $firma);
        $xml->writeElement('DELIVERY_DATE', $v['aktivni'] ? '0' : '7');
        $xml->endElement();
    }
    $xml->endElement();
    $xml->endDocument();

    echo $xml->outputMemory();
    exit;
}
