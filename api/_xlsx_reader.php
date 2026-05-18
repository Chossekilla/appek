<?php
/**
 * 📊 XLSX/CSV/TSV READER — zero-dependency parser ceníků.
 *
 * Public API:
 *   read_spreadsheet(string $path, ?string $forceType = null): array
 *     → ['headers' => [...], 'rows' => [[...], ...], 'meta' => [...]]
 *
 * Podporované formáty: .xlsx, .csv, .tsv, .txt
 * (ODS plánováno do v2.)
 *
 * XLSX implementace: parsuje ZipArchive → xl/sharedStrings.xml + xl/worksheets/sheet1.xml.
 * Zvládá string refs (t="s"), inline strings, numbers, formulas (čte cached value).
 */

/**
 * Hlavní vstupní bod.
 */
function read_spreadsheet(string $path, ?string $forceType = null): array {
    if (!is_readable($path)) {
        throw new Exception("Soubor není čitelný: $path");
    }
    $ext = strtolower($forceType ?? pathinfo($path, PATHINFO_EXTENSION));
    switch ($ext) {
        case 'xlsx': return read_xlsx($path);
        case 'csv':  return read_csv_tsv($path, ',');
        case 'tsv':
        case 'txt':  return read_csv_tsv($path, "\t");
        case 'ods':  throw new Exception('ODS zatím nepodporováno — exportuj jako XLSX.');
        default:     throw new Exception("Neznámý formát: .$ext (podporujeme .xlsx, .csv, .tsv)");
    }
}

// ════════════════════════════════════════════════════════════
// CSV / TSV
// ════════════════════════════════════════════════════════════

function read_csv_tsv(string $path, string $delimiter): array {
    $rows = [];
    $headers = [];
    $h = fopen($path, 'r');
    if (!$h) throw new Exception('Nepodařilo se otevřít soubor.');

    // Auto-detekce BOM (Excel exportuje CSV s UTF-8 BOM)
    $first = fgets($h);
    if (substr($first, 0, 3) === "\xEF\xBB\xBF") $first = substr($first, 3);
    rewind($h);
    // Skipni BOM pokud existoval
    if (substr(fread($h, 3), 0, 3) !== "\xEF\xBB\xBF") rewind($h);

    $lineNum = 0;
    while (($row = fgetcsv($h, 0, $delimiter, '"', '\\')) !== false) {
        if ($row === [null]) continue;
        // Auto-detekce: pokud delimiter čárka selže, zkus středník
        if ($delimiter === ',' && $lineNum === 0 && count($row) === 1 && strpos($row[0], ';') !== false) {
            fclose($h);
            return read_csv_tsv($path, ';');
        }
        // Vyčisti hodnoty od overflow whitespace
        $row = array_map(fn($v) => is_string($v) ? trim($v) : $v, $row);
        if ($lineNum === 0) {
            $headers = $row;
        } else {
            // Doplň prázdné hodnoty na šířku headers
            $padded = array_pad($row, count($headers), null);
            $rows[] = array_slice($padded, 0, count($headers));
        }
        $lineNum++;
    }
    fclose($h);
    return [
        'headers' => $headers,
        'rows'    => $rows,
        'meta'    => ['format' => $delimiter === ',' ? 'csv' : 'tsv', 'count' => count($rows)],
    ];
}

// ════════════════════════════════════════════════════════════
// XLSX (ZipArchive + SimpleXML)
// ════════════════════════════════════════════════════════════

function read_xlsx(string $path): array {
    if (!class_exists('ZipArchive')) {
        throw new Exception('PHP ZipArchive extension není dostupné.');
    }
    $zip = new ZipArchive();
    if ($zip->open($path) !== true) {
        throw new Exception('Soubor není platný XLSX (nelze otevřít jako ZIP).');
    }

    // 1. Načti shared strings
    $sharedStrings = [];
    $ssXml = $zip->getFromName('xl/sharedStrings.xml');
    if ($ssXml !== false) {
        $doc = @simplexml_load_string($ssXml);
        if ($doc) {
            foreach ($doc->si as $si) {
                // Sloučí všechny <t> elementy (může být kvůli rich text)
                $text = '';
                foreach ($si->xpath('.//*[local-name()="t"]') as $t) {
                    $text .= (string) $t;
                }
                $sharedStrings[] = $text;
            }
        }
    }

    // 2. Najdi první sheet
    $sheetXml = $zip->getFromName('xl/worksheets/sheet1.xml');
    if ($sheetXml === false) {
        $zip->close();
        throw new Exception('XLSX neobsahuje worksheet (sheet1.xml chybí).');
    }
    $zip->close();

    $sheet = @simplexml_load_string($sheetXml);
    if (!$sheet) throw new Exception('Nepodařilo se parsovat sheet1.xml.');

    // 3. Iteruj řádky → buňky
    $rows = [];
    $headers = [];
    $rowIdx = 0;
    foreach ($sheet->sheetData->row as $row) {
        $cells = [];
        $maxCol = 0;
        foreach ($row->c as $c) {
            $ref  = (string) $c['r'];           // např. "A1", "B2"
            $type = (string) $c['t'];           // "s" = shared string, "" = number, "str" = inline, "b" = bool
            $col  = column_letter_to_index($ref);

            $val = null;
            if ($type === 's') {
                $idx = (int) $c->v;
                $val = $sharedStrings[$idx] ?? '';
            } elseif ($type === 'inlineStr') {
                $val = (string) $c->is->t;
            } elseif ($type === 'b') {
                $val = ((string) $c->v) === '1';
            } else {
                $val = isset($c->v) ? (string) $c->v : null;
            }

            $cells[$col] = $val;
            if ($col > $maxCol) $maxCol = $col;
        }

        // Pole buněk v správném pořadí (0..maxCol), prázdné jako null
        $rowArr = [];
        for ($i = 0; $i <= $maxCol; $i++) {
            $rowArr[] = $cells[$i] ?? null;
        }

        if ($rowIdx === 0) {
            $headers = array_map(fn($v) => is_string($v) ? trim($v) : (string) $v, $rowArr);
        } else {
            // Pad/trim na šířku headers
            $padded = array_pad($rowArr, count($headers), null);
            $rows[] = array_slice($padded, 0, count($headers));
        }
        $rowIdx++;
    }

    return [
        'headers' => $headers,
        'rows'    => $rows,
        'meta'    => ['format' => 'xlsx', 'count' => count($rows)],
    ];
}

/**
 * "A" → 0, "B" → 1, ..., "Z" → 25, "AA" → 26, ...
 * Z buňky reference "B5" extrahuje sloupec a převede.
 */
function column_letter_to_index(string $cellRef): int {
    $letters = preg_replace('/[0-9]/', '', $cellRef);
    $idx = 0;
    $len = strlen($letters);
    for ($i = 0; $i < $len; $i++) {
        $idx = $idx * 26 + (ord($letters[$i]) - ord('A') + 1);
    }
    return $idx - 1;
}

// ════════════════════════════════════════════════════════════
// FUZZY MATCHING (pro auto-match jmen)
// ════════════════════════════════════════════════════════════

/**
 * Normalizuj jméno pro porovnání: lowercase, bez diakritiky, jen alfanum + mezery.
 */
function normalize_name(string $s): string {
    $s = mb_strtolower(trim($s), 'UTF-8');
    // Odstraň diakritiku
    $s = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $s) ?: $s;
    $s = preg_replace('/[^a-z0-9\s]+/', ' ', $s);
    $s = preg_replace('/\s+/', ' ', $s);
    return trim($s);
}

/**
 * Levenshtein vzdálenost normalizovaná na délku (0=identical, 1=úplně jiné).
 */
function fuzzy_similarity(string $a, string $b): float {
    $a = normalize_name($a);
    $b = normalize_name($b);
    if ($a === '' || $b === '') return 0;
    if ($a === $b) return 1.0;
    $maxLen = max(strlen($a), strlen($b));
    if ($maxLen === 0) return 1.0;
    // PHP levenshtein max 255 chars
    if (strlen($a) > 255 || strlen($b) > 255) {
        return similar_text($a, $b) / $maxLen;
    }
    $dist = levenshtein($a, $b);
    return 1.0 - ($dist / $maxLen);
}

/**
 * Najde nejlepší shodu v $candidates podle jména. Vrátí null pokud žádná >= threshold.
 *
 * @param string $name        Hledané jméno
 * @param array  $candidates  Pole [['id' => X, 'nazev' => 'Y', ...], ...]
 * @param float  $threshold   0.0-1.0 (default 0.75)
 * @return array|null Best match s přidaným 'similarity' nebo null
 */
function find_best_name_match(string $name, array $candidates, float $threshold = 0.75): ?array {
    $best = null;
    $bestScore = 0;
    foreach ($candidates as $c) {
        $score = fuzzy_similarity($name, $c['nazev'] ?? '');
        if ($score > $bestScore) {
            $bestScore = $score;
            $best = $c;
        }
    }
    if ($best && $bestScore >= $threshold) {
        $best['similarity'] = round($bestScore, 3);
        return $best;
    }
    return null;
}

// ════════════════════════════════════════════════════════════
// AUTO-DETECT MAPPING (header → DB field)
// ════════════════════════════════════════════════════════════

/**
 * Z hlaviček (např. ["Název", "EAN", "Cena bez DPH"]) auto-mapuje na DB pole.
 * Vrátí: ['nazev' => 0, 'ean' => 1, 'cena_bez_dph' => 2, ...] (index v headers)
 */
function auto_detect_mapping(array $headers, string $targetType = 'vyrobky'): array {
    // Patterns: db_field => regex patterns (case-insensitive, normalized)
    $patterns = [
        'nazev'        => ['/^n[áa]zev$/u', '/^jm[eé]no$/u', '/^name$/u', '/^produkt$/u', '/^v[ýy]robek$/u', '/^zbo[žz][íi]$/u', '/^surovina$/u'],
        'cislo'        => ['/^[čc][íi]slo$/u', '/^k[oó]d$/u', '/^c[íi]slo[\s_]*polo[žz]ky/u', '/^sku$/u', '/^id$/u'],
        'ean'          => ['/^ean$/u', '/^[čc][áa]rov[ýy][\s_]*k[oó]d$/u', '/^barcode$/u', '/^isbn$/u'],
        'cena_bez_dph' => ['/cena.*bez.*dph$/u', '/^cena$/u', '/^price$/u', '/^netto$/u', '/^cena.*n[áa]kup/u', '/^cena.*prodejn/u'],
        'jednotka'     => ['/^jednotka$/u', '/^unit$/u', '/^m\.?j\.?$/u', '/^j\.?$/u'],
        'hmotnost_g'   => ['/hmotnost.*g$/u', '/^hmotnost$/u', '/^v[áa]ha$/u', '/^weight$/u', '/^g$/u', '/^gram/u'],
        'obsah'        => ['/^obsah$/u', '/^objem$/u', '/^volume$/u', '/^obsah_baleni$/u'],
        'dph'          => ['/^dph$/u', '/^sazba.*dph$/u', '/^vat$/u', '/^tax$/u'],
        'alergeny'     => ['/^alerge?ny?$/u', '/^allergens$/u'],
        'trvanlivost'  => ['/^trvanlivost$/u', '/^shelf.*life$/u', '/^min\.?[\s_]*trvanlivost$/u'],
        'poznamka'     => ['/^pozn(?:[áa]mka)?$/u', '/^note$/u', '/^description$/u', '/^popis$/u'],
        'kategorie'    => ['/^kategorie$/u', '/^category$/u'],
    ];

    // Specifické pro suroviny
    if ($targetType === 'suroviny') {
        $patterns['cena_baleni']   = ['/^cena.*bale[ne]n?[íi]/u', '/^cena.*za.*bal/u', '/^bale[ne]n[íi].*cena/u'];
        $patterns['obsah_baleni']  = ['/^obsah.*bale[ne]n?[íi]/u', '/^bale[ne]n[íi].*obsah/u', '/^velikost.*bale[ne]n?[íi]/u'];
    }

    $mapping = [];
    foreach ($headers as $idx => $header) {
        $norm = mb_strtolower(trim((string) $header), 'UTF-8');
        if ($norm === '') continue;
        foreach ($patterns as $field => $patList) {
            if (isset($mapping[$field])) continue; // Už spojeno
            foreach ($patList as $pat) {
                if (preg_match($pat, $norm)) {
                    $mapping[$field] = $idx;
                    break 2;
                }
            }
        }
    }
    return $mapping;
}
