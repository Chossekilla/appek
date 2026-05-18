<?php
/**
 * Import výrobků z JSON souboru (TVyrobky export).
 *
 * POST multipart/form-data:
 *   - soubor (file)         : JSON soubor
 *   - mode (string)         : 'preview' | 'import'
 *   - prepsat (0|1)         : pokud true, přepíše existující záznam podle 'cislo'
 *
 * Vrací:
 *   - preview: { celkem, validnich, neuplnych, vzorek: [...] }
 *   - import:  { vlozeno, prepsano, preskoceno, neuplnych, chyby: [...] }
 *
 * Auto-vytvoří sloupec trvanlivost ve vyrobky (pokud neexistuje).
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/_admin_auth.php';
cors_headers();
require_super_admin();

$pdo = db();

// ===========================================================
// Auto-migrace — sloupec trvanlivost
// ===========================================================
function ensure_trvanlivost_column(PDO $pdo): void {
    $stmt = $pdo->query("
        SELECT COUNT(*) FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = 'vyrobky'
          AND COLUMN_NAME = 'trvanlivost'
    ");
    if ((int) $stmt->fetchColumn() === 0) {
        $pdo->exec("ALTER TABLE vyrobky ADD COLUMN trvanlivost VARCHAR(50) DEFAULT NULL AFTER hmotnost_g");
    }
}
ensure_trvanlivost_column($pdo);

// ===========================================================
// Pomocné funkce
// ===========================================================

/**
 * Parse váhu "90gr", "30 gr", "25 gr", "1" + jednotka kg → 1000g.
 * Vrací int gramů nebo null.
 */
function parse_vaha(?string $raw, ?string $jednotka): ?int {
    if (!$raw) return null;
    $t = trim($raw);
    if ($t === '') return null;

    // Najdi první číslo (s desetinnou tečkou nebo čárkou)
    if (!preg_match('/(\d+(?:[.,]\d+)?)/', $t, $m)) return null;
    $num = (float) str_replace(',', '.', $m[1]);
    if ($num <= 0) return null;

    $low = mb_strtolower($t);
    // Explicitní gram jednotka v textu
    if (preg_match('/\b(g|gr|gram|gramů)\b/u', $low) || str_contains($low, 'gr')) {
        return (int) round($num);
    }
    // Explicitní kg jednotka v textu
    if (preg_match('/\b(kg|kilo)\b/u', $low)) {
        return (int) round($num * 1000);
    }
    // Žádná jednotka v textu — řiď se hlavní jednotkou výrobku
    if ($jednotka && in_array(mb_strtolower($jednotka), ['kg'])) {
        return (int) round($num * 1000);
    }
    // ks bez gramáže = nech null (číslo je pravděpodobně počet, ne hmotnost)
    return null;
}

/**
 * Najdi/vytvoř jednotku (z 'kod') a vrať id.
 */
function jednotka_id(PDO $pdo, ?string $kod): ?int {
    if (!$kod) return null;
    $kod = trim($kod);
    if ($kod === '') return null;

    $stmt = $pdo->prepare("SELECT id FROM jednotky WHERE LOWER(kod) = LOWER(:k) LIMIT 1");
    $stmt->execute(['k' => $kod]);
    $id = $stmt->fetchColumn();
    if ($id) return (int) $id;

    // Vytvoř novou
    $pdo->prepare("INSERT INTO jednotky (kod, nazev) VALUES (:k, :n)")
        ->execute(['k' => $kod, 'n' => $kod]);
    return (int) $pdo->lastInsertId();
}

/**
 * Najdi/vytvoř sazbu DPH (sazba je v procentech, 12, 21, 0).
 */
function sazba_dph_id(PDO $pdo, float $sazba_pct): ?int {
    $stmt = $pdo->prepare("SELECT id FROM sazby_dph WHERE ABS(sazba - :s) < 0.01 LIMIT 1");
    $stmt->execute(['s' => $sazba_pct]);
    $id = $stmt->fetchColumn();
    if ($id) return (int) $id;

    // Vytvoř novou
    $nazev = $sazba_pct == 0 ? 'nulová' : ($sazba_pct < 15 ? 'snížená' : 'základní');
    $pdo->prepare("INSERT INTO sazby_dph (nazev, sazba, platne_od) VALUES (:n, :s, CURDATE())")
        ->execute(['n' => $nazev, 's' => $sazba_pct]);
    return (int) $pdo->lastInsertId();
}

/**
 * Validuj 1 řádek. Vrátí ['ok' => true, 'item' => [...]] nebo ['ok' => false, 'duvod' => ...].
 */
function validate_item(array $r): array {
    $nazev = trim((string) ($r['Nazev'] ?? ''));
    if ($nazev === '') return ['ok' => false, 'duvod' => 'chybí Nazev'];

    $cena_bez = (float) ($r['CenaBezDPH'] ?? 0);
    if ($cena_bez <= 0) return ['ok' => false, 'duvod' => 'chybí/neplatná CenaBezDPH'];

    $jednotka = trim((string) ($r['MernaJednotka'] ?? ''));
    if ($jednotka === '') return ['ok' => false, 'duvod' => 'chybí MernaJednotka'];

    return ['ok' => true];
}

// ===========================================================
// Načti soubor / data
// ===========================================================
$mode = $_POST['mode'] ?? 'preview';
$prepsat = !empty($_POST['prepsat']);

if (empty($_FILES['soubor']) || $_FILES['soubor']['error'] !== UPLOAD_ERR_OK) {
    json_error('Chybí soubor nebo se nepodařilo nahrát');
}

$tmp = $_FILES['soubor']['tmp_name'];
$nazev = strtolower($_FILES['soubor']['name'] ?? '');
$obsah = file_get_contents($tmp);
if (!$obsah) json_error('Soubor je prázdný');

// Detekce formátu: JSON vs CSV
$je_csv = (str_ends_with($nazev, '.csv') || str_ends_with($nazev, '.tsv') ||
           (!str_ends_with($nazev, '.json') && ltrim($obsah)[0] !== '[' && ltrim($obsah)[0] !== '{'));

if ($je_csv) {
    // Parse CSV/TSV — autodetekce oddělovače z první řádky
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
        // Konverze typů — Smazano text → bool, čísla zůstanou jako string (PHP cast později)
        if (isset($rec['Smazano'])) {
            $v = strtolower($rec['Smazano']);
            $rec['Smazano'] = in_array($v, ['1', 'true', 'ano', 'yes'], true);
        }
        $data[] = $rec;
    }
} else {
    // JSON
    $data = json_decode($obsah, true);
    if (!is_array($data)) json_error('Soubor není platný JSON');
    if (!array_is_list($data)) {
        foreach (['data', 'items', 'vyrobky', 'records'] as $k) {
            if (isset($data[$k]) && is_array($data[$k])) { $data = $data[$k]; break; }
        }
        if (!array_is_list($data)) json_error('JSON neobsahuje seznam výrobků');
    }
}

// ===========================================================
// Validace + statistika
// ===========================================================
$validni = [];
$neuplnych = [];
foreach ($data as $r) {
    $v = validate_item($r);
    if ($v['ok']) {
        $validni[] = $r;
    } else {
        $neuplnych[] = ['nazev' => $r['Nazev'] ?? '?', 'duvod' => $v['duvod']];
    }
}

// ===========================================================
// PREVIEW MODE
// ===========================================================
if ($mode === 'preview') {
    $vzorek = [];
    foreach (array_slice($validni, 0, 10) as $r) {
        $vzorek[] = [
            'cislo'        => (string) ($r['IDVyrobek'] ?? ''),
            'nazev'        => $r['Nazev'],
            'jednotka'     => $r['MernaJednotka'] ?? '',
            'vaha_raw'     => $r['Vaha'] ?? '',
            'vaha_g'       => parse_vaha($r['Vaha'] ?? null, $r['MernaJednotka'] ?? null),
            'cena_bez_dph' => round((float) $r['CenaBezDPH'], 2),
            'sazba_dph'    => round((float) ($r['SazbaDPH'] ?? 0) * 100, 0),
            'trvanlivost'  => $r['Trvanlivost'] ?? null,
            'aktivni'      => empty($r['Smazano']),
        ];
    }
    json_response([
        'celkem'      => count($data),
        'validnich'   => count($validni),
        'neuplnych'   => count($neuplnych),
        'vzorek'      => $vzorek,
        'duvody'      => array_slice($neuplnych, 0, 20),
    ]);
}

// ===========================================================
// IMPORT MODE
// ===========================================================
$vlozeno = 0;
$prepsano = 0;
$preskoceno = 0;
$chyby = [];

$stmt_find = $pdo->prepare("SELECT id FROM vyrobky WHERE cislo = :c LIMIT 1");
$stmt_insert = $pdo->prepare("
    INSERT INTO vyrobky
        (cislo, nazev, popis, trvanlivost, jednotka_id, hmotnost_g,
         cena_bez_dph, sazba_dph_id, aktivni, oblibeny, poradi, min_objednavka)
    VALUES (:c, :n, :p, :t, :j, :h, :cb, :s, :a, 0, 0, 1)
");
$stmt_update = $pdo->prepare("
    UPDATE vyrobky SET
        nazev = :n, popis = :p, trvanlivost = :t,
        jednotka_id = :j, hmotnost_g = :h,
        cena_bez_dph = :cb, sazba_dph_id = :s, aktivni = :a
    WHERE id = :id
");

$pdo->beginTransaction();
try {
    foreach ($validni as $r) {
        $cislo = isset($r['IDVyrobek']) ? (string) $r['IDVyrobek'] : null;
        $nazev = trim($r['Nazev']);
        $popis = trim((string) ($r['Popis'] ?? '')) ?: null;
        $trvanlivost = trim((string) ($r['Trvanlivost'] ?? '')) ?: null;
        $jed_kod = trim((string) ($r['MernaJednotka'] ?? ''));
        $vaha_g = parse_vaha($r['Vaha'] ?? null, $jed_kod);
        $cena_bez = round((float) $r['CenaBezDPH'], 2);
        $sazba_pct = round((float) ($r['SazbaDPH'] ?? 0.12) * 100);
        $aktivni = empty($r['Smazano']) ? 1 : 0;

        try {
            $jed_id = jednotka_id($pdo, $jed_kod);
            $sazba_id = sazba_dph_id($pdo, $sazba_pct);

            // Zjisti existenci podle cislo
            $existing_id = null;
            if ($cislo) {
                $stmt_find->execute(['c' => $cislo]);
                $existing_id = $stmt_find->fetchColumn();
            }

            $params = [
                'n'  => $nazev,
                'p'  => $popis,
                't'  => $trvanlivost,
                'j'  => $jed_id,
                'h'  => $vaha_g,
                'cb' => $cena_bez,
                's'  => $sazba_id,
                'a'  => $aktivni,
            ];

            if ($existing_id) {
                if ($prepsat) {
                    $params['id'] = (int) $existing_id;
                    $stmt_update->execute($params);
                    $prepsano++;
                } else {
                    $preskoceno++;
                }
            } else {
                $params['c'] = $cislo;
                $stmt_insert->execute($params);
                $vlozeno++;
            }
        } catch (Throwable $e) {
            $chyby[] = ['nazev' => $nazev, 'chyba' => $e->getMessage()];
        }
    }
    $pdo->commit();
} catch (Throwable $e) {
    $pdo->rollBack();
    json_error('Import selhal: ' . $e->getMessage(), 500);
}

json_response([
    'ok'         => true,
    'vlozeno'    => $vlozeno,
    'prepsano'   => $prepsano,
    'preskoceno' => $preskoceno,
    'neuplnych'  => count($neuplnych),
    'chyby'      => $chyby,
]);
