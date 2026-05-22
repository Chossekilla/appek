<?php
/**
 * 🔄 MIGRATION LIB — Universal import / export pro známé restaurační, gastro a účetní systémy.
 *
 * Architektura:
 *   - Každý konektor = jeden array v MIGRATION_CONNECTORS s definicí:
 *     formátu (csv/xml/json/xlsx), encoding, kolumnové mappingu, parser, exporter.
 *   - Hlavní funkce:
 *       migration_connectors() → seznam dostupných konektorů
 *       migration_import($connector, $type, $rawData) → vrátí pole APPEK records
 *       migration_export($connector, $type, $appekRecords) → vrátí raw string (csv/xml/...)
 *
 * Podporované systémy:
 *   ÚČETNICTVÍ:    POHODA (Stormware), Money S3 (Solitea), FlexiBee, Helios, ABRA, iDoklad
 *   POS / Pokladny: Storyous, Dotykačka, AlfaPOS, Septim, Markeeta, Sunmi
 *   E-shop:        Shoptet, Upgates, FastCentrik, WooCommerce, Magento, Shopify
 *   Skladové:      WinShop, K2, OR-systém
 *   Rezervace:     Bookatable, Bookassist, GuestJoy
 *   Univerzální:   CSV (Excel), JSON, XML, ISDOC (česká fakturace)
 *
 * Typy záznamů, které konektory umí převést:
 *   - produkty (vyrobky)
 *   - odberatele (zákazníci)
 *   - objednavky
 *   - faktury (ISDOC)
 *   - skladove_pohyby
 *   - ceny (cenove_skupiny)
 */

const MIGRATION_CONNECTORS = [

    // ─── 🧾 ÚČETNICTVÍ / FAKTURACE ───────────────────────────────────────────
    'pohoda' => [
        'name'         => 'POHODA (Stormware)',
        'icon'         => '🟦',
        'category'     => 'ucetnictvi',
        'desc'         => 'Stormware POHODA — XML formát pro odběratele, výrobky, faktury.',
        'format'       => 'xml',
        'encoding'     => 'Windows-1250',
        'supports'     => ['produkty', 'odberatele', 'faktury'],
        'docs_url'     => 'https://www.stormware.cz/pohoda/xml/',
        'export_template' => 'pohoda_xml',
        'website'      => 'https://www.stormware.cz/pohoda/',
    ],

    'money_s3' => [
        'name'         => 'Money S3 (Solitea)',
        'icon'         => '💰',
        'category'     => 'ucetnictvi',
        'desc'         => 'Solitea Money S3 — XML/CSV pro veškerou agendu.',
        'format'       => 'xml',
        'encoding'     => 'UTF-8',
        'supports'     => ['produkty', 'odberatele', 'objednavky', 'faktury'],
        'docs_url'     => 'https://money.solitea.cz/podpora/xml-rozhrani/',
        'export_template' => 'money_xml',
        'website'      => 'https://money.solitea.cz/',
    ],

    'flexibee' => [
        'name'         => 'FlexiBee / Abra Flexi',
        'icon'         => '🐝',
        'category'     => 'ucetnictvi',
        'desc'         => 'Abra Flexi (dříve FlexiBee) — REST API + XML pro vše.',
        'format'       => 'json',
        'encoding'     => 'UTF-8',
        'supports'     => ['produkty', 'odberatele', 'objednavky', 'faktury'],
        'docs_url'     => 'https://demo.flexibee.eu/devdoc/',
        'export_template' => 'flexibee_json',
        'website'      => 'https://www.abra.eu/flexi/',
    ],

    'helios' => [
        'name'         => 'Helios (Asseco)',
        'icon'         => '☀️',
        'category'     => 'ucetnictvi',
        'desc'         => 'Asseco Helios Orange / iNuvio — XML import.',
        'format'       => 'xml',
        'encoding'     => 'Windows-1250',
        'supports'     => ['produkty', 'odberatele', 'faktury'],
        'docs_url'     => 'https://www.helios.eu/',
        'export_template' => 'helios_xml',
        'website'      => 'https://www.helios.eu/',
    ],

    'abra' => [
        'name'         => 'ABRA Gen / Flexi',
        'icon'         => '🅰️',
        'category'     => 'ucetnictvi',
        'desc'         => 'ABRA Gen — komplexní ERP, XML/REST import.',
        'format'       => 'xml',
        'encoding'     => 'UTF-8',
        'supports'     => ['produkty', 'odberatele', 'objednavky', 'faktury'],
        'docs_url'     => 'https://help.abra.eu/',
        'export_template' => 'abra_xml',
        'website'      => 'https://www.abra.eu/',
    ],

    'idoklad' => [
        'name'         => 'iDoklad (Solitea)',
        'icon'         => '📄',
        'category'     => 'ucetnictvi',
        'desc'         => 'iDoklad online fakturace — CSV/REST import.',
        'format'       => 'csv',
        'encoding'     => 'UTF-8',
        'supports'     => ['odberatele', 'faktury'],
        'docs_url'     => 'https://www.idoklad.cz/api/',
        'export_template' => 'idoklad_csv',
        'website'      => 'https://www.idoklad.cz/',
    ],

    'isdoc' => [
        'name'         => 'ISDOC (česká fakturace)',
        'icon'         => '🇨🇿',
        'category'     => 'ucetnictvi',
        'desc'         => 'Český standard pro elektronickou fakturaci — kompatibilní se všemi CZ účto systémy.',
        'format'       => 'xml',
        'encoding'     => 'UTF-8',
        'supports'     => ['faktury'],
        'docs_url'     => 'http://www.isdoc.cz/',
        'export_template' => 'isdoc_xml',
        'website'      => 'http://www.isdoc.cz/',
    ],

    // ─── 🏪 POS / POKLADNY ───────────────────────────────────────────────────
    'storyous' => [
        'name'         => 'Storyous (Saltpay)',
        'icon'         => '📱',
        'category'     => 'pos',
        'desc'         => 'Storyous restaurační pokladna — CSV pro produkty, REST pro účtenky.',
        'format'       => 'csv',
        'encoding'     => 'UTF-8',
        'supports'     => ['produkty', 'objednavky'],
        'docs_url'     => 'https://api.storyous.com/',
        'export_template' => 'storyous_csv',
        'website'      => 'https://www.storyous.com/',
    ],

    'dotykacka' => [
        'name'         => 'Dotykačka',
        'icon'         => '👆',
        'category'     => 'pos',
        'desc'         => 'Dotykačka POS pro restaurace a obchody — CSV/JSON import produktů.',
        'format'       => 'csv',
        'encoding'     => 'UTF-8',
        'supports'     => ['produkty', 'objednavky'],
        'docs_url'     => 'https://docs.dotykacka.cz/',
        'export_template' => 'dotykacka_csv',
        'website'      => 'https://www.dotykacka.cz/',
    ],

    'septim' => [
        'name'         => 'Septim',
        'icon'         => '7️⃣',
        'category'     => 'pos',
        'desc'         => 'Septim restaurační systém — CSV s rozšířenými atributy.',
        'format'       => 'csv',
        'encoding'     => 'UTF-8',
        'supports'     => ['produkty', 'objednavky'],
        'docs_url'     => 'https://www.septim.cz/',
        'export_template' => 'septim_csv',
        'website'      => 'https://www.septim.cz/',
    ],

    'alfa_pos' => [
        'name'         => 'AlfaPOS',
        'icon'         => '🅰️',
        'category'     => 'pos',
        'desc'         => 'AlfaPOS pokladna pro gastro — CSV import sortimentu.',
        'format'       => 'csv',
        'encoding'     => 'Windows-1250',
        'supports'     => ['produkty'],
        'docs_url'     => 'https://www.alfapos.cz/',
        'export_template' => 'alfapos_csv',
        'website'      => 'https://www.alfapos.cz/',
    ],

    'markeeta' => [
        'name'         => 'Markeeta',
        'icon'         => '🛒',
        'category'     => 'pos',
        'desc'         => 'Markeeta cloud POS — CSV produktový katalog.',
        'format'       => 'csv',
        'encoding'     => 'UTF-8',
        'supports'     => ['produkty'],
        'docs_url'     => 'https://www.markeeta.cz/',
        'export_template' => 'markeeta_csv',
        'website'      => 'https://www.markeeta.cz/',
    ],

    'sunmi' => [
        'name'         => 'Sunmi / SaltPay',
        'icon'         => '☀️',
        'category'     => 'pos',
        'desc'         => 'Sunmi terminály + SaltPay backend — CSV/JSON.',
        'format'       => 'csv',
        'encoding'     => 'UTF-8',
        'supports'     => ['produkty', 'objednavky'],
        'docs_url'     => 'https://docs.sunmi.com/',
        'export_template' => 'sunmi_csv',
        'website'      => 'https://www.sunmi.com/',
    ],

    // ─── 🛍️ E-SHOP / B2B PLATFORMY ──────────────────────────────────────────
    'shoptet' => [
        'name'         => 'Shoptet',
        'icon'         => '🛍️',
        'category'     => 'eshop',
        'desc'         => 'Shoptet e-shop CSV/XML — kompletní katalog produktů.',
        'format'       => 'csv',
        'encoding'     => 'UTF-8',
        'supports'     => ['produkty', 'odberatele', 'objednavky'],
        'docs_url'     => 'https://podpora.shoptet.cz/hc/cs',
        'export_template' => 'shoptet_csv',
        'website'      => 'https://www.shoptet.cz/',
    ],

    'upgates' => [
        'name'         => 'Upgates',
        'icon'         => '🛒',
        'category'     => 'eshop',
        'desc'         => 'Upgates e-shop — XML feed + REST API.',
        'format'       => 'xml',
        'encoding'     => 'UTF-8',
        'supports'     => ['produkty', 'odberatele', 'objednavky'],
        'docs_url'     => 'https://www.upgates.cz/a/api',
        'export_template' => 'upgates_xml',
        'website'      => 'https://www.upgates.cz/',
    ],

    'fastcentrik' => [
        'name'         => 'FastCentrik',
        'icon'         => '⚡',
        'category'     => 'eshop',
        'desc'         => 'NetDirect FastCentrik — CSV/XML produktový feed.',
        'format'       => 'xml',
        'encoding'     => 'UTF-8',
        'supports'     => ['produkty', 'objednavky'],
        'docs_url'     => 'https://www.fastcentrik.cz/',
        'export_template' => 'fastcentrik_xml',
        'website'      => 'https://www.fastcentrik.cz/',
    ],

    'woocommerce' => [
        'name'         => 'WooCommerce (WordPress)',
        'icon'         => '🟪',
        'category'     => 'eshop',
        'desc'         => 'WooCommerce WordPress plugin — REST API + CSV.',
        'format'       => 'csv',
        'encoding'     => 'UTF-8',
        'supports'     => ['produkty', 'odberatele', 'objednavky'],
        'docs_url'     => 'https://woocommerce.github.io/woocommerce-rest-api-docs/',
        'export_template' => 'woocommerce_csv',
        'website'      => 'https://woocommerce.com/',
    ],

    'shopify' => [
        'name'         => 'Shopify',
        'icon'         => '🟩',
        'category'     => 'eshop',
        'desc'         => 'Shopify CSV/REST API — globální e-commerce platforma.',
        'format'       => 'csv',
        'encoding'     => 'UTF-8',
        'supports'     => ['produkty', 'odberatele', 'objednavky'],
        'docs_url'     => 'https://shopify.dev/docs/api',
        'export_template' => 'shopify_csv',
        'website'      => 'https://www.shopify.com/',
    ],

    'magento' => [
        'name'         => 'Magento / Adobe Commerce',
        'icon'         => '🧙',
        'category'     => 'eshop',
        'desc'         => 'Magento 2 / Adobe Commerce — CSV import + REST API.',
        'format'       => 'csv',
        'encoding'     => 'UTF-8',
        'supports'     => ['produkty', 'odberatele', 'objednavky'],
        'docs_url'     => 'https://devdocs.magento.com/',
        'export_template' => 'magento_csv',
        'website'      => 'https://magento.com/',
    ],

    // ─── 📦 SKLADOVÉ SYSTÉMY ────────────────────────────────────────────────
    'winshop' => [
        'name'         => 'WinShop',
        'icon'         => '🪟',
        'category'     => 'sklad',
        'desc'         => 'WinShop skladová evidence — CSV/DBF export.',
        'format'       => 'csv',
        'encoding'     => 'Windows-1250',
        'supports'     => ['produkty', 'skladove_pohyby'],
        'docs_url'     => 'https://www.winshop.cz/',
        'export_template' => 'winshop_csv',
        'website'      => 'https://www.winshop.cz/',
    ],

    'k2' => [
        'name'         => 'K2 Atmitec',
        'icon'         => '🏔️',
        'category'     => 'sklad',
        'desc'         => 'K2 ERP komplexní systém — XML rozhraní.',
        'format'       => 'xml',
        'encoding'     => 'UTF-8',
        'supports'     => ['produkty', 'odberatele', 'objednavky', 'faktury', 'skladove_pohyby'],
        'docs_url'     => 'https://www.k2.cz/',
        'export_template' => 'k2_xml',
        'website'      => 'https://www.k2.cz/',
    ],

    'or_system' => [
        'name'         => 'OR-Systém',
        'icon'         => '🧮',
        'category'     => 'sklad',
        'desc'         => 'OR-Systém — XML pro CZ specifické workflow.',
        'format'       => 'xml',
        'encoding'     => 'Windows-1250',
        'supports'     => ['produkty', 'odberatele', 'faktury'],
        'docs_url'     => 'https://www.orsystem.cz/',
        'export_template' => 'orsystem_xml',
        'website'      => 'https://www.orsystem.cz/',
    ],

    // ─── 📅 REZERVACE ───────────────────────────────────────────────────────
    'bookatable' => [
        'name'         => 'Bookatable',
        'icon'         => '📅',
        'category'     => 'rezervace',
        'desc'         => 'Bookatable rezervační systém pro restaurace — API/CSV.',
        'format'       => 'csv',
        'encoding'     => 'UTF-8',
        'supports'     => ['rezervace'],
        'docs_url'     => 'https://www.bookatable.com/',
        'export_template' => 'bookatable_csv',
        'website'      => 'https://www.bookatable.com/',
    ],

    'bookassist' => [
        'name'         => 'Bookassist',
        'icon'         => '📆',
        'category'     => 'rezervace',
        'desc'         => 'Bookassist — hotel + restaurant booking.',
        'format'       => 'csv',
        'encoding'     => 'UTF-8',
        'supports'     => ['rezervace', 'odberatele'],
        'docs_url'     => 'https://www.bookassist.com/',
        'export_template' => 'bookassist_csv',
        'website'      => 'https://www.bookassist.com/',
    ],

    // ─── 🔧 UNIVERZÁLNÍ FORMÁTY ─────────────────────────────────────────────
    'csv_universal' => [
        'name'         => 'CSV univerzální (Excel)',
        'icon'         => '📊',
        'category'     => 'univerzal',
        'desc'         => 'Univerzální CSV s mapovacím wizard — pro jakýkoliv systém.',
        'format'       => 'csv',
        'encoding'     => 'UTF-8',
        'supports'     => ['produkty', 'odberatele', 'objednavky', 'faktury'],
        'docs_url'     => null,
        'export_template' => 'csv_universal',
        'website'      => null,
    ],

    'json_universal' => [
        'name'         => 'JSON univerzální',
        'icon'         => '{ }',
        'category'     => 'univerzal',
        'desc'         => 'Standardní JSON formát pro REST API mezi systémy.',
        'format'       => 'json',
        'encoding'     => 'UTF-8',
        'supports'     => ['produkty', 'odberatele', 'objednavky', 'faktury'],
        'docs_url'     => null,
        'export_template' => 'json_universal',
        'website'      => null,
    ],

    'xlsx_universal' => [
        'name'         => 'Excel XLSX',
        'icon'         => '📗',
        'category'     => 'univerzal',
        'desc'         => 'Microsoft Excel XLSX — multi-sheet pro celou databázi.',
        'format'       => 'xlsx',
        'encoding'     => 'UTF-8',
        'supports'     => ['produkty', 'odberatele', 'objednavky', 'faktury'],
        'docs_url'     => null,
        'export_template' => 'xlsx_universal',
        'website'      => null,
    ],
];

const MIGRATION_CATEGORIES = [
    'ucetnictvi' => ['name' => '🧾 Účetnictví / Fakturace', 'color' => '#0058b8'],
    'pos'        => ['name' => '🏪 POS / Pokladny',          'color' => '#BA7517'],
    'eshop'      => ['name' => '🛍️ E-shop / B2B',             'color' => '#208438'],
    'sklad'      => ['name' => '📦 Skladové systémy',         'color' => '#6b21a8'],
    'rezervace'  => ['name' => '📅 Rezervace',                'color' => '#c66800'],
    'univerzal'  => ['name' => '🔧 Univerzální formáty',      'color' => '#86868b'],
];

/**
 * Vrátí seznam všech konektorů.
 */
function migration_connectors(): array {
    return MIGRATION_CONNECTORS;
}

/**
 * Vrátí konektory pro daný typ záznamů (produkty / odberatele / ...).
 */
function migration_connectors_for_type(string $type): array {
    $out = [];
    foreach (MIGRATION_CONNECTORS as $key => $c) {
        if (in_array($type, $c['supports'], true)) $out[$key] = $c;
    }
    return $out;
}

/**
 * IMPORT — převede raw data z externího formátu na APPEK records.
 *
 * @param string $connectorKey  např. 'pohoda', 'storyous', 'csv_universal'
 * @param string $type          'produkty' | 'odberatele' | 'objednavky' | 'faktury'
 * @param string $rawData       file_get_contents() z uploadu
 * @return array                normalizované APPEK records (asociativní array)
 * @throws Exception            pokud konektor nepodporuje typ nebo data nelze parsovat
 */
function migration_import(string $connectorKey, string $type, string $rawData): array {
    if (!isset(MIGRATION_CONNECTORS[$connectorKey])) {
        throw new Exception("Neznámý konektor: $connectorKey");
    }
    $c = MIGRATION_CONNECTORS[$connectorKey];
    if (!in_array($type, $c['supports'], true)) {
        throw new Exception("Konektor {$c['name']} nepodporuje typ '$type'.");
    }

    // Převeď encoding na UTF-8 pokud potřeba
    if (strtoupper($c['encoding']) !== 'UTF-8') {
        $rawData = @iconv($c['encoding'], 'UTF-8//IGNORE', $rawData) ?: $rawData;
    }

    // Format-specific parsing
    switch ($c['format']) {
        case 'csv':
            return migration_parse_csv($rawData, $connectorKey, $type);
        case 'xml':
            return migration_parse_xml($rawData, $connectorKey, $type);
        case 'json':
            return migration_parse_json($rawData, $connectorKey, $type);
        default:
            throw new Exception("Formát {$c['format']} zatím nepodporován.");
    }
}

/**
 * EXPORT — z APPEK records vytvoří raw data v externím formátu.
 */
function migration_export(string $connectorKey, string $type, array $appekRecords): string {
    if (!isset(MIGRATION_CONNECTORS[$connectorKey])) {
        throw new Exception("Neznámý konektor: $connectorKey");
    }
    $c = MIGRATION_CONNECTORS[$connectorKey];
    if (!in_array($type, $c['supports'], true)) {
        throw new Exception("Konektor {$c['name']} nepodporuje export typu '$type'.");
    }

    switch ($c['format']) {
        case 'csv':
            return migration_build_csv($appekRecords, $connectorKey, $type, $c['encoding']);
        case 'xml':
            return migration_build_xml($appekRecords, $connectorKey, $type, $c['encoding']);
        case 'json':
            return migration_build_json($appekRecords, $connectorKey, $type);
        default:
            throw new Exception("Export formát {$c['format']} zatím nepodporován.");
    }
}

// ════════════════════════════════════════════════════════════
// CSV PARSER / BUILDER
// ════════════════════════════════════════════════════════════

function migration_parse_csv(string $raw, string $connector, string $type): array {
    $lines = preg_split("/\r\n|\n|\r/", trim($raw));
    if (count($lines) < 2) return [];
    $delim = strpos($lines[0], ';') !== false ? ';' : ',';
    $headers = str_getcsv($lines[0], $delim);
    $headers = array_map(fn($h) => strtolower(trim($h)), $headers);

    $out = [];
    for ($i = 1; $i < count($lines); $i++) {
        if (trim($lines[$i]) === '') continue;
        $cols = str_getcsv($lines[$i], $delim);
        $row = [];
        foreach ($headers as $idx => $h) {
            $row[$h] = $cols[$idx] ?? '';
        }
        $out[] = migration_normalize_row($row, $connector, $type);
    }
    return $out;
}

function migration_build_csv(array $records, string $connector, string $type, string $encoding): string {
    $headers = migration_default_headers($type);
    $out = implode(';', $headers) . "\r\n";
    foreach ($records as $r) {
        $row = [];
        foreach ($headers as $h) {
            $v = (string) ($r[$h] ?? '');
            // Escape CSV
            if (strpos($v, ';') !== false || strpos($v, '"') !== false || strpos($v, "\n") !== false) {
                $v = '"' . str_replace('"', '""', $v) . '"';
            }
            $row[] = $v;
        }
        $out .= implode(';', $row) . "\r\n";
    }
    if (strtoupper($encoding) !== 'UTF-8') {
        $out = @iconv('UTF-8', $encoding . '//IGNORE', $out) ?: $out;
    }
    return $out;
}

function migration_default_headers(string $type): array {
    switch ($type) {
        case 'produkty':
            return ['cislo','nazev','popis','cena_bez_dph','dph','jednotka','kategorie','alergeny','hmotnost_g','ean'];
        case 'odberatele':
            return ['cislo','nazev','ico','dic','ulice','mesto','psc','email','telefon','typ','splatnost_dni'];
        case 'objednavky':
            return ['cislo','odberatel_ico','datum_objednani','datum_dodani','stav','castka_bez_dph','castka_dph','castka_celkem','poznamka'];
        case 'faktury':
            return ['cislo','odberatel_ico','datum_vystaveni','datum_splatnosti','castka_bez_dph','castka_dph','castka_celkem','variabilni_symbol'];
        default:
            return [];
    }
}

// ════════════════════════════════════════════════════════════
// XML PARSER / BUILDER
// ════════════════════════════════════════════════════════════

function migration_parse_xml(string $raw, string $connector, string $type): array {
    libxml_use_internal_errors(true);
    $xml = @simplexml_load_string($raw);
    if ($xml === false) throw new Exception("XML chyba: " . (libxml_get_last_error()->message ?? 'unknown'));

    // POHODA / Money / Helios mají různé namespace, generický walker
    $out = [];
    // Heuristika — najdi nejhlubší repeating element
    $rows = migration_xml_find_records($xml);
    foreach ($rows as $r) {
        $row = migration_xml_to_array($r);
        $out[] = migration_normalize_row($row, $connector, $type);
    }
    return $out;
}

function migration_xml_find_records(SimpleXMLElement $xml): array {
    // Najdi první element, který se opakuje 2+ krát
    foreach ($xml->children() as $child) {
        $name = $child->getName();
        $siblings = $xml->{$name};
        if (count($siblings) >= 1) return iterator_to_array($siblings);
    }
    return [];
}

function migration_xml_to_array(SimpleXMLElement $el): array {
    $out = [];
    foreach ($el->children() as $name => $val) {
        $out[strtolower((string) $name)] = trim((string) $val);
    }
    // attributes too
    foreach ($el->attributes() as $name => $val) {
        $out['@' . strtolower((string) $name)] = trim((string) $val);
    }
    return $out;
}

function migration_build_xml(array $records, string $connector, string $type, string $encoding): string {
    // Generic XML — connector-specific šablony budou v budoucnu
    $root = match ($type) {
        'produkty'   => 'productList',
        'odberatele' => 'customerList',
        'objednavky' => 'orderList',
        'faktury'    => 'invoiceList',
        default      => 'dataList',
    };
    $item = match ($type) {
        'produkty'   => 'product',
        'odberatele' => 'customer',
        'objednavky' => 'order',
        'faktury'    => 'invoice',
        default      => 'item',
    };

    $xml = "<?xml version=\"1.0\" encoding=\"$encoding\"?>\n<$root>\n";
    foreach ($records as $r) {
        $xml .= "  <$item>\n";
        foreach ($r as $k => $v) {
            if ($k === '@id' || $v === null || $v === '') continue;
            $xml .= "    <$k>" . htmlspecialchars((string) $v, ENT_XML1 | ENT_QUOTES, 'UTF-8') . "</$k>\n";
        }
        $xml .= "  </$item>\n";
    }
    $xml .= "</$root>\n";

    if (strtoupper($encoding) !== 'UTF-8') {
        $xml = @iconv('UTF-8', $encoding . '//IGNORE', $xml) ?: $xml;
    }
    return $xml;
}

// ════════════════════════════════════════════════════════════
// JSON PARSER / BUILDER
// ════════════════════════════════════════════════════════════

function migration_parse_json(string $raw, string $connector, string $type): array {
    $data = json_decode($raw, true);
    if (!is_array($data)) throw new Exception('Neplatný JSON.');

    // Najdi pole — buď root je array, nebo má klíč 'data', 'items', 'rows', $type
    $rows = $data;
    foreach (['data', 'items', 'rows', $type, 'records'] as $k) {
        if (isset($data[$k]) && is_array($data[$k])) { $rows = $data[$k]; break; }
    }

    $out = [];
    foreach ($rows as $r) {
        if (!is_array($r)) continue;
        // lowercase keys
        $lower = [];
        foreach ($r as $k => $v) $lower[strtolower($k)] = $v;
        $out[] = migration_normalize_row($lower, $connector, $type);
    }
    return $out;
}

function migration_build_json(array $records, string $connector, string $type): string {
    return json_encode([
        'connector' => $connector,
        'type'      => $type,
        'exported_at' => date('c'),
        'count'     => count($records),
        $type       => $records,
    ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
}

// ════════════════════════════════════════════════════════════
// COLUMN MAPPING — heuristic normalization na APPEK schéma
// ════════════════════════════════════════════════════════════

/**
 * Universal mapping table: external-name → APPEK-name.
 * Lowercase keys. Konektory mohou rozšířit nebo přepsat.
 */
const FIELD_ALIASES = [
    'produkty' => [
        'kod' => 'cislo', 'code' => 'cislo', 'sku' => 'cislo', 'product_code' => 'cislo', 'plu' => 'cislo',
        'name' => 'nazev', 'title' => 'nazev', 'nazev_polozky' => 'nazev', 'product_name' => 'nazev',
        'description' => 'popis', 'desc' => 'popis', 'short_description' => 'popis',
        'price' => 'cena_bez_dph', 'price_excl_vat' => 'cena_bez_dph', 'cena' => 'cena_bez_dph',
            'cena_bez_dane' => 'cena_bez_dph', 'unit_price' => 'cena_bez_dph',
        'vat' => 'dph', 'vat_rate' => 'dph', 'sazba_dph' => 'dph', 'tax_rate' => 'dph',
        'unit' => 'jednotka', 'merna_jednotka' => 'jednotka', 'mj' => 'jednotka',
        'category' => 'kategorie', 'kategorie_nazev' => 'kategorie', 'group' => 'kategorie',
        'allergens' => 'alergeny', 'alergens' => 'alergeny',
        'weight' => 'hmotnost_g', 'hmotnost' => 'hmotnost_g', 'gram' => 'hmotnost_g',
        'barcode' => 'ean', 'gtin' => 'ean',
    ],
    'odberatele' => [
        'code' => 'cislo', 'customer_code' => 'cislo', 'kod' => 'cislo',
        'name' => 'nazev', 'company_name' => 'nazev', 'firma' => 'nazev',
        'company_id' => 'ico', 'business_id' => 'ico',
        'vat_id' => 'dic', 'tax_id' => 'dic',
        'street' => 'ulice', 'address' => 'ulice', 'address1' => 'ulice', 'adresa' => 'ulice',
        'city' => 'mesto', 'town' => 'mesto',
        'zip' => 'psc', 'postal_code' => 'psc', 'postcode' => 'psc',
        'phone' => 'telefon', 'tel' => 'telefon',
        'type' => 'typ', 'segment' => 'typ', 'kategorie' => 'typ',
        'payment_term' => 'splatnost_dni', 'payment_days' => 'splatnost_dni', 'splatnost' => 'splatnost_dni',
    ],
    'objednavky' => [
        'order_number' => 'cislo', 'orderno' => 'cislo', 'cislo_objednavky' => 'cislo',
        'customer_id' => 'odberatel_ico', 'customer_code' => 'odberatel_ico', 'ico' => 'odberatel_ico',
        'date' => 'datum_objednani', 'order_date' => 'datum_objednani',
        'delivery_date' => 'datum_dodani', 'expected_delivery' => 'datum_dodani',
        'status' => 'stav', 'state' => 'stav',
        'total' => 'castka_celkem', 'total_amount' => 'castka_celkem', 'grand_total' => 'castka_celkem',
        'subtotal' => 'castka_bez_dph', 'amount_excl_vat' => 'castka_bez_dph',
        'vat_amount' => 'castka_dph', 'tax' => 'castka_dph',
        'note' => 'poznamka', 'notes' => 'poznamka', 'comment' => 'poznamka',
    ],
    'faktury' => [
        'invoice_number' => 'cislo', 'cislofaktury' => 'cislo',
        'customer_id' => 'odberatel_ico', 'ico' => 'odberatel_ico',
        'issue_date' => 'datum_vystaveni', 'datum_vystaveni' => 'datum_vystaveni',
        'due_date' => 'datum_splatnosti', 'datum_splatnosti' => 'datum_splatnosti',
        'total_excl_vat' => 'castka_bez_dph',
        'vat_amount' => 'castka_dph',
        'total' => 'castka_celkem', 'total_amount' => 'castka_celkem',
        'vs' => 'variabilni_symbol', 'variable_symbol' => 'variabilni_symbol',
    ],
];

function migration_normalize_row(array $row, string $connector, string $type): array {
    $out = [];
    $aliases = FIELD_ALIASES[$type] ?? [];
    foreach ($row as $key => $val) {
        $lk = strtolower(trim($key));
        $appekKey = $aliases[$lk] ?? $lk;
        // Skip namespace prefixes (např. m:invoiceHeader)
        if (strpos($appekKey, ':') !== false) {
            $appekKey = substr($appekKey, strpos($appekKey, ':') + 1);
        }
        // Trim a normalize value
        $v = is_string($val) ? trim($val) : $val;
        if ($v === '') continue;
        // Pokud APPEK už má hodnotu, nepřepiš (priorita externí klíče v původním pořadí)
        if (!isset($out[$appekKey])) $out[$appekKey] = $v;
    }
    return $out;
}

/**
 * Vrátí konektory seskupené dle kategorie (pro UI dlaždice).
 */
function migration_by_category(): array {
    $out = [];
    foreach (MIGRATION_CATEGORIES as $catKey => $catMeta) {
        $out[$catKey] = ['meta' => $catMeta, 'connectors' => []];
    }
    foreach (MIGRATION_CONNECTORS as $key => $c) {
        $cat = $c['category'];
        if (isset($out[$cat])) {
            $out[$cat]['connectors'][$key] = $c;
        }
    }
    return $out;
}
