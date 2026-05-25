<?php
/**
 * Import odběratelů z JSON souboru (TOdberatele export).
 *
 * POST multipart/form-data:
 *   - soubor (file)        : JSON
 *   - mode (string)        : 'preview' | 'import'
 *   - prepsat (0|1)        : pokud true, přepíše existující odběratele podle 'cislo'
 *
 * Vrací:
 *   - preview: { celkem, validnich, neuplnych, vzorek, duvody }
 *   - import:  { vlozeno, prepsano, preskoceno, neuplnych, vlozeno_pobocek, chyby }
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/_admin_auth.php';
cors_headers();
require_super_admin();

$pdo = db();

// -----------------------------------------------------------
// Validace záznamu
// -----------------------------------------------------------
function validate_odb(array $r): array {
    $nazev = trim((string) ($r['ONazev'] ?? ''));
    if ($nazev === '') return ['ok' => false, 'duvod' => 'chybí ONazev'];
    return ['ok' => true];
}

// -----------------------------------------------------------
// Soubor
// -----------------------------------------------------------
$mode = $_POST['mode'] ?? 'preview';
$prepsat = !empty($_POST['prepsat']);

if (empty($_FILES['soubor']) || $_FILES['soubor']['error'] !== UPLOAD_ERR_OK) {
    json_error('Chybí soubor nebo se nepodařilo nahrát');
}

$tmp = $_FILES['soubor']['tmp_name'];
$nazev = strtolower($_FILES['soubor']['name'] ?? '');
$obsah = file_get_contents($tmp);
if (!$obsah) json_error('Soubor je prázdný');

$je_csv = (str_ends_with($nazev, '.csv') || str_ends_with($nazev, '.tsv') ||
           (!str_ends_with($nazev, '.json') && ltrim($obsah)[0] !== '[' && ltrim($obsah)[0] !== '{'));

if ($je_csv) {
    $lines = preg_split('/\r\n|\r|\n/', trim($obsah));
    if (count($lines) < 2) json_error('CSV musí obsahovat hlavičku a alespoň 1 řádek');

    $first = $lines[0];
    $sep = ',';
    if (substr_count($first, ';') > substr_count($first, ',')) $sep = ';';
    if (substr_count($first, "\t") > substr_count($first, $sep)) $sep = "\t";

    $headers = str_getcsv($first, $sep);
    $headers = array_map('trim', $headers);
    $data = [];
    for ($i = 1; $i < count($lines); $i++) {
        $line = trim($lines[$i]);
        if ($line === '') continue;
        $row = str_getcsv($line, $sep);
        $rec = [];
        foreach ($headers as $j => $h) {
            $rec[$h] = isset($row[$j]) ? trim($row[$j]) : null;
        }
        if (isset($rec['PlatceDane'])) {
            $v = strtolower($rec['PlatceDane']);
            $rec['PlatceDane'] = in_array($v, ['1', 'true', 'ano', 'yes'], true);
        }
        $data[] = $rec;
    }
} else {
    $data = json_decode($obsah, true);
    if (!is_array($data)) json_error('Soubor není platný JSON');
    if (!array_is_list($data)) {
        foreach (['data', 'items', 'odberatele', 'records'] as $k) {
            if (isset($data[$k]) && is_array($data[$k])) { $data = $data[$k]; break; }
        }
        if (!array_is_list($data)) json_error('JSON neobsahuje seznam odběratelů');
    }
}

// -----------------------------------------------------------
// Validace
// -----------------------------------------------------------
$validni = [];
$neuplnych = [];
foreach ($data as $r) {
    $v = validate_odb($r);
    if ($v['ok']) {
        $validni[] = $r;
    } else {
        $neuplnych[] = ['nazev' => $r['ONazev'] ?? '?', 'duvod' => $v['duvod']];
    }
}

// -----------------------------------------------------------
// PREVIEW
// -----------------------------------------------------------
if ($mode === 'preview') {
    $vzorek = [];
    foreach (array_slice($validni, 0, 10) as $r) {
        $vzorek[] = [
            'cislo'        => (string) ($r['IDOdberatel'] ?? ''),
            'nazev'        => $r['ONazev'],
            'ico'          => $r['Identifikacni'] ?? '',
            'dic'          => $r['Danove'] ?? '',
            'sidlo'        => trim(($r['Ulice'] ?? '') . ', ' . ($r['PSC'] ?? '') . ' ' . ($r['Mesto'] ?? ''), ', '),
            'pobocka'      => $r['MistoDodani'] ?? '',
            'pobocka_adr'  => trim(($r['UliceDodani'] ?? '') . ', ' . ($r['PSCDodani'] ?? '') . ' ' . ($r['MestoDodani'] ?? ''), ', '),
            'platce'       => !empty($r['PlatceDane']),
        ];
    }
    json_response([
        'celkem'    => count($data),
        'validnich' => count($validni),
        'neuplnych' => count($neuplnych),
        'vzorek'    => $vzorek,
        'duvody'    => array_slice($neuplnych, 0, 20),
    ]);
}

// -----------------------------------------------------------
// IMPORT
// -----------------------------------------------------------
// === AUTO-SNAPSHOT před hromadným importem (může přepsat existující data) ===
require_once __DIR__ . '/_zaloha_helper.php';
zaloha_snapshot($pdo, 'Před hromadným importem odběratelů' . ($prepsat ? ' (s přepsáním)' : ''));

$vlozeno = 0;
$prepsano = 0;
$preskoceno = 0;
$vlozeno_pobocek = 0;
$chyby = [];

$stmt_find = $pdo->prepare("SELECT id FROM odberatele WHERE cislo = :c LIMIT 1");

$stmt_insert = $pdo->prepare("
    INSERT INTO odberatele
        (cislo, nazev, ico, dic, ulice, mesto, psc,
         splatnost_dni, sleva_pct, blokovan)
    VALUES (:c, :n, :ico, :dic, :ul, :me, :psc, 14, 0, 0)
");

$stmt_update = $pdo->prepare("
    UPDATE odberatele SET
        nazev = :n, ico = :ico, dic = :dic,
        ulice = :ul, mesto = :me, psc = :psc
    WHERE id = :id
");

// Pobočka
$stmt_pobocka_find = $pdo->prepare("
    SELECT id FROM mista_dodani
    WHERE odberatel_id = :o AND nazev = :n
    LIMIT 1
");
$stmt_pobocka_insert = $pdo->prepare("
    INSERT INTO mista_dodani
        (odberatel_id, nazev, ulice, mesto, psc, vychozi, aktivni, poradi)
    VALUES (:o, :n, :ul, :me, :psc, 1, 1, 0)
");

$pdo->beginTransaction();
try {
    foreach ($validni as $r) {
        $cislo = isset($r['IDOdberatel']) ? (string) $r['IDOdberatel'] : null;
        $nazev = trim($r['ONazev']);
        $ico   = trim((string) ($r['Identifikacni'] ?? '')) ?: null;
        $dic   = !empty($r['PlatceDane']) ? (trim((string) ($r['Danove'] ?? '')) ?: null) : null;
        $ul    = trim((string) ($r['Ulice'] ?? '')) ?: null;
        $me    = trim((string) ($r['Mesto'] ?? '')) ?: null;
        $psc   = trim((string) ($r['PSC'] ?? '')) ?: null;

        try {
            $existing_id = null;
            if ($cislo) {
                $stmt_find->execute(['c' => $cislo]);
                $existing_id = $stmt_find->fetchColumn();
            }

            if ($existing_id) {
                if ($prepsat) {
                    $stmt_update->execute([
                        'n' => $nazev, 'ico' => $ico, 'dic' => $dic,
                        'ul' => $ul, 'me' => $me, 'psc' => $psc,
                        'id' => (int) $existing_id,
                    ]);
                    $odb_id = (int) $existing_id;
                    $prepsano++;
                } else {
                    $preskoceno++;
                    continue;
                }
            } else {
                $stmt_insert->execute([
                    'c' => $cislo, 'n' => $nazev, 'ico' => $ico, 'dic' => $dic,
                    'ul' => $ul, 'me' => $me, 'psc' => $psc,
                ]);
                $odb_id = (int) $pdo->lastInsertId();
                $vlozeno++;
            }

            // Pobočka — přidej, pokud má MistoDodani vyplněné
            $pob_nazev = trim((string) ($r['MistoDodani'] ?? ''));
            if ($pob_nazev !== '' && $odb_id) {
                $stmt_pobocka_find->execute(['o' => $odb_id, 'n' => $pob_nazev]);
                if (!$stmt_pobocka_find->fetchColumn()) {
                    $pob_ul  = trim((string) ($r['UliceDodani'] ?? '')) ?: $ul;
                    $pob_me  = trim((string) ($r['MestoDodani'] ?? '')) ?: $me;
                    $pob_psc = trim((string) ($r['PSCDodani'] ?? ''))   ?: $psc;
                    $stmt_pobocka_insert->execute([
                        'o' => $odb_id, 'n' => $pob_nazev,
                        'ul' => $pob_ul, 'me' => $pob_me, 'psc' => $pob_psc,
                    ]);
                    $vlozeno_pobocek++;
                }
            }
        } catch (Throwable $e) {
            $chyby[] = ['nazev' => $nazev, 'chyba' => $e->getMessage()];
        }
    }
    $pdo->commit();
} catch (Throwable $e) {
    $pdo->rollBack();
    json_error_safe('Import selhal', , 500);
}

json_response([
    'ok'              => true,
    'vlozeno'         => $vlozeno,
    'prepsano'        => $prepsano,
    'preskoceno'      => $preskoceno,
    'neuplnych'       => count($neuplnych),
    'vlozeno_pobocek' => $vlozeno_pobocek,
    'chyby'           => $chyby,
]);
