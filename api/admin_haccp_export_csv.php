<?php
/**
 * Export HACCP karet do CSV (souhrn všech standardních polí + jakost + mikrobio).
 * Použití: GET ?ids=1,2,3
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/_admin_auth.php';
require_admin();

$pdo = db();
$ids = [];
if (!empty($_GET['ids'])) {
    $ids = array_filter(array_map('intval', explode(',', (string) $_GET['ids'])));
}
if (empty($ids)) { http_response_code(400); die('Chybí ids'); }

// Defaulty pro fallback prázdných polí
$defaults = [];
$nsRaw = nastaveni_get($pdo, 'haccp_defaults', null);
if ($nsRaw) {
    $tmp = json_decode($nsRaw, true);
    if (is_array($tmp)) $defaults = $tmp;
}

$place = implode(',', array_fill(0, count($ids), '?'));
$stmt = $pdo->prepare("
    SELECT v.id, v.cislo, v.nazev, v.popis, v.haccp_data, v.haccp_graf_id,
           k.nazev AS kategorie_nazev,
           g.nazev AS graf_nazev
    FROM vyrobky v
    LEFT JOIN kategorie_vyrobku k ON v.kategorie_id = k.id
    LEFT JOIN haccp_grafy g ON v.haccp_graf_id = g.id
    WHERE v.id IN ($place)
    ORDER BY v.poradi, v.nazev
");
$stmt->execute($ids);
$rows = $stmt->fetchAll();

// Hlavičky CSV
$columns = [
    'cislo'           => 'Číslo',
    'nazev'           => 'Název',
    'kategorie_nazev' => 'Kategorie',
    'graf_nazev'      => 'HACCP graf',
    'produkt'         => 'Produkt',
    'obchodni_jmeno'  => 'Obchodní jméno',
    'misto_vyroby'    => 'Místo výroby',
    'cilovy_trh'      => 'Cílový trh',
    'skupina'         => 'Skupina',
    'popis_produktu'  => 'Popis produktu',
    'zpusob_uziti'    => 'Způsob užití',
    'baleni'          => 'Balení',
    'trvanlivost'     => 'Trvanlivost',
    'skladovani'      => 'Skladování',
    'distribuce'      => 'Distribuce',
    'omezeni'         => 'Omezení',
    'vzhled'          => 'Vzhled',
    'tvar'            => 'Tvar',
    'vune'            => 'Vůně',
    'chut'            => 'Chuť',
    'struktura'       => 'Struktura',
    'mikrobio'        => 'Mikrobiologie',
];

$ts = date('Ymd_Hi');
$filename = "haccp_karty_$ts.csv";

header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');

$out = fopen('php://output', 'w');
// BOM pro Excel (UTF-8)
fwrite($out, "\xEF\xBB\xBF");

// Hlavička
fputcsv($out, array_values($columns), ';');

foreach ($rows as $r) {
    $hd = [];
    if (!empty($r['haccp_data'])) {
        $tmp = json_decode($r['haccp_data'], true);
        if (is_array($tmp)) $hd = $tmp;
    }
    $j = is_array($hd['jakost'] ?? null) ? $hd['jakost'] : [];

    $line = [];
    foreach ($columns as $k => $label) {
        if ($k === 'cislo')           { $line[] = $r['cislo'] ?? ''; continue; }
        if ($k === 'nazev')           { $line[] = $r['nazev'] ?? ''; continue; }
        if ($k === 'kategorie_nazev') { $line[] = $r['kategorie_nazev'] ?? ''; continue; }
        if ($k === 'graf_nazev')      { $line[] = $r['graf_nazev'] ?? ''; continue; }
        if (in_array($k, ['vzhled', 'tvar', 'vune', 'chut', 'struktura'], true)) {
            $line[] = $j[$k] ?? '';
            continue;
        }
        if ($k === 'mikrobio') { $line[] = $hd['mikrobio'] ?? ''; continue; }
        // Standardní HACCP_FIELDS klíče: hd > defaults
        $val = (!empty($hd[$k])) ? $hd[$k] : ($defaults[$k] ?? '');
        $line[] = is_array($val) ? json_encode($val, JSON_UNESCAPED_UNICODE) : $val;
    }
    fputcsv($out, $line, ';');
}

fclose($out);
exit;
