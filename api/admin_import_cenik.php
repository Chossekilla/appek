<?php
/**
 * 📥 IMPORT CENÍKU — 3-fázový wizard pro hromadný import výrobků/surovin.
 *
 * POST /api/admin_import_cenik.php?action=upload     (multipart/form-data)
 *   file       — XLSX/CSV/TSV soubor
 *   target     — 'vyrobky' | 'suroviny'
 *   → vrátí: { headers, sample (max 5 rows), total_rows, target, auto_mapping, session_id }
 *
 * POST ?action=match                                  (JSON)
 *   session_id, mapping, match_fields ['ean', 'nazev']
 *   → vrátí: { results: [{row, status, target_id, target_name, similarity}, ...], stats }
 *
 * POST ?action=apply                                   (JSON)
 *   session_id, mapping, decisions [{row_idx, action: 'update'|'create'|'skip', target_id}]
 *   → vrátí: { updated, created, skipped, errors }
 *
 * Uploady se ukládají do /uploads/import/{session_id}.json (parsed data + meta).
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/_admin_auth.php';
require_once __DIR__ . '/_xlsx_reader.php';

cors_headers();
require_admin();
header('Content-Type: application/json; charset=UTF-8');

$pdo    = db();
$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? '';

// Adresář pro uloženou state mezi kroky
$importDir = __DIR__ . '/../uploads/import';
if (!is_dir($importDir)) @mkdir($importDir, 0775, true);

// Cleanup: smaž session files starší než 1 hodina
foreach (glob("$importDir/*.json") ?: [] as $f) {
    if (file_exists($f) && filemtime($f) < time() - 3600) @unlink($f);
}

// ════════════════════════════════════════════════════════════
// 1. UPLOAD — parse souboru, vrať preview + auto-mapping
// ════════════════════════════════════════════════════════════
if ($action === 'upload' && $method === 'POST') {
    $target = $_POST['target'] ?? 'vyrobky';
    if (!in_array($target, ['vyrobky', 'suroviny'], true)) {
        json_error('Neplatný target (vyrobky/suroviny)', 400);
    }
    if (empty($_FILES['file']['tmp_name'])) json_error('Nahraj soubor', 400);
    if ($_FILES['file']['size'] > 10 * 1024 * 1024) json_error('Soubor je moc velký (max 10 MB)', 413);

    $origName = $_FILES['file']['name'];
    $ext = strtolower(pathinfo($origName, PATHINFO_EXTENSION));
    if (!in_array($ext, ['xlsx', 'csv', 'tsv', 'txt'], true)) {
        json_error("Nepodporovaný formát .$ext (chceme .xlsx, .csv, .tsv)", 415);
    }

    try {
        $parsed = read_spreadsheet($_FILES['file']['tmp_name'], $ext);
    } catch (Throwable $e) {
        json_error_safe('Parser chyba', , 400);
    }

    $headers = $parsed['headers'];
    $rows    = $parsed['rows'];

    if (count($headers) === 0 || count($rows) === 0) {
        json_error('Soubor neobsahuje hlavičky nebo řádky', 400);
    }

    // Auto-detect column → DB field mapping
    $autoMapping = auto_detect_mapping($headers, $target);

    // Ulož celý parsed obsah do session souboru
    $sessionId = bin2hex(random_bytes(8));
    $sessionPath = "$importDir/$sessionId.json";
    file_put_contents($sessionPath, json_encode([
        'target'   => $target,
        'filename' => $origName,
        'headers'  => $headers,
        'rows'     => $rows,
        'meta'     => $parsed['meta'],
        'created'  => time(),
    ], JSON_UNESCAPED_UNICODE));

    json_response([
        'session_id'   => $sessionId,
        'filename'     => $origName,
        'target'       => $target,
        'headers'      => $headers,
        'sample'       => array_slice($rows, 0, 5),
        'total_rows'   => count($rows),
        'auto_mapping' => $autoMapping,
        'available_fields' => available_fields($target),
    ]);
}

// ════════════════════════════════════════════════════════════
// 2. MATCH — spočítej kolize s existujícími záznamy
// ════════════════════════════════════════════════════════════
if ($action === 'match' && $method === 'POST') {
    $d = json_input();
    $sessionId   = $d['session_id'] ?? '';
    $mapping     = $d['mapping'] ?? [];
    $matchFields = $d['match_fields'] ?? ['ean', 'nazev'];

    $session = load_session($importDir, $sessionId);
    $target  = $session['target'];
    $rows    = $session['rows'];

    // Načti existující záznamy pro matching
    $existingByEan  = [];
    $existingByCode = [];
    $existing       = [];
    try {
        if ($target === 'vyrobky') {
            $rs = $pdo->query("SELECT id, nazev, cislo, ean, cena_bez_dph FROM vyrobky")->fetchAll();
        } else {
            $rs = $pdo->query("SELECT id, nazev, cena_baleni FROM suroviny")->fetchAll();
        }
        foreach ($rs as $r) {
            $existing[] = $r;
            if (!empty($r['ean']))  $existingByEan[trim($r['ean'])] = $r;
            if (!empty($r['cislo'])) $existingByCode[trim($r['cislo'])] = $r;
        }
    } catch (Throwable $e) { /* tabulky nemusí mít všechny sloupce */ }

    $results = [];
    $stats = ['matched' => 0, 'new' => 0, 'ambiguous' => 0];

    foreach ($rows as $rowIdx => $row) {
        $extracted = extract_from_row($row, $mapping);
        $matched = null;
        $matchedBy = null;
        $similarity = null;

        // EAN exact match (priorita)
        if (in_array('ean', $matchFields, true) && !empty($extracted['ean'])) {
            $ean = trim($extracted['ean']);
            if (isset($existingByEan[$ean])) {
                $matched = $existingByEan[$ean];
                $matchedBy = 'ean';
                $similarity = 1.0;
            }
        }

        // Číslo/kód exact match
        if (!$matched && in_array('cislo', $matchFields, true) && !empty($extracted['cislo'])) {
            $code = trim($extracted['cislo']);
            if (isset($existingByCode[$code])) {
                $matched = $existingByCode[$code];
                $matchedBy = 'cislo';
                $similarity = 1.0;
            }
        }

        // Fuzzy name match
        if (!$matched && in_array('nazev', $matchFields, true) && !empty($extracted['nazev'])) {
            $best = find_best_name_match($extracted['nazev'], $existing, 0.75);
            if ($best) {
                $matched = $best;
                $matchedBy = 'nazev';
                $similarity = $best['similarity'];
            }
        }

        $status = $matched
            ? ($similarity >= 0.95 ? 'matched' : 'ambiguous')
            : 'new';
        $stats[$status]++;

        $results[] = [
            'row_idx'       => $rowIdx,
            'extracted'     => $extracted,
            'status'        => $status,
            'matched_by'    => $matchedBy,
            'similarity'    => $similarity,
            'target_id'     => $matched['id'] ?? null,
            'target_name'   => $matched['nazev'] ?? null,
            'target_price'  => $matched['cena_bez_dph'] ?? $matched['cena_baleni'] ?? null,
        ];
    }

    json_response([
        'session_id' => $sessionId,
        'target'     => $target,
        'results'    => $results,
        'stats'      => $stats,
        'candidates' => array_map(fn($r) => ['id' => $r['id'], 'nazev' => $r['nazev']], $existing),
    ]);
}

// ════════════════════════════════════════════════════════════
// 3. APPLY — aplikuj rozhodnutí (update / create / skip)
// ════════════════════════════════════════════════════════════
if ($action === 'apply' && $method === 'POST') {
    require_super_admin();
    $d = json_input();
    $sessionId = $d['session_id'] ?? '';
    $mapping   = $d['mapping']    ?? [];
    $decisions = $d['decisions']  ?? [];

    $session = load_session($importDir, $sessionId);
    $target  = $session['target'];
    $rows    = $session['rows'];

    $stats = ['updated' => 0, 'created' => 0, 'skipped' => 0, 'errors' => []];

    $pdo->beginTransaction();
    try {
        foreach ($decisions as $dec) {
            $rowIdx = (int) ($dec['row_idx'] ?? -1);
            $action = $dec['action'] ?? 'skip';
            $targetId = !empty($dec['target_id']) ? (int) $dec['target_id'] : null;

            if ($rowIdx < 0 || !isset($rows[$rowIdx])) continue;
            if ($action === 'skip') { $stats['skipped']++; continue; }

            $row = $rows[$rowIdx];
            $data = extract_from_row($row, $mapping);

            try {
                if ($action === 'update' && $targetId) {
                    apply_update($pdo, $target, $targetId, $data);
                    $stats['updated']++;
                } elseif ($action === 'create') {
                    apply_create($pdo, $target, $data);
                    $stats['created']++;
                } else {
                    $stats['skipped']++;
                }
            } catch (Throwable $e) {
                $stats['errors'][] = "Řádek " . ($rowIdx + 2) . ": " . $e->getMessage();
                $stats['skipped']++;
            }
        }
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        json_error_safe('Transakce selhala', , 500);
    }

    // Cleanup session file
    @unlink("$importDir/$sessionId.json");

    json_response($stats);
}

json_error('Neznámá akce', 404);

// ════════════════════════════════════════════════════════════
// HELPERS
// ════════════════════════════════════════════════════════════

function load_session(string $dir, string $sessionId): array {
    if (!preg_match('/^[a-f0-9]{16}$/', $sessionId)) json_error('Neplatný session_id', 400);
    $path = "$dir/$sessionId.json";
    if (!file_exists($path)) json_error('Session vypršela — nahraj soubor znovu', 410);
    $data = json_decode(file_get_contents($path), true);
    if (!is_array($data)) json_error('Korupce session souboru', 500);
    return $data;
}

function extract_from_row(array $row, array $mapping): array {
    $out = [];
    foreach ($mapping as $field => $idx) {
        if ($idx === null || $idx === '' || !isset($row[(int) $idx])) continue;
        $val = $row[(int) $idx];
        if (is_string($val)) $val = trim($val);
        if ($val === '' || $val === null) continue;
        // Numerické fields — parsuj čísla (1 234,56 → 1234.56)
        if (in_array($field, ['cena_bez_dph', 'cena_baleni', 'hmotnost_g', 'obsah', 'obsah_baleni', 'dph'], true)) {
            $val = parse_number($val);
        }
        $out[$field] = $val;
    }
    return $out;
}

function parse_number($v) {
    if (is_numeric($v)) return (float) $v;
    if (!is_string($v)) return null;
    // "1 234,56" → "1234.56", "1,234.56" → "1234.56", "12.50 Kč" → "12.50"
    $s = preg_replace('/[^\d,.\-]/', '', $v);
    // Pokud je tam i čárka i tečka, předpokládáme tečka = thousands, čárka = decimal
    if (strpos($s, ',') !== false && strpos($s, '.') !== false) {
        $s = str_replace('.', '', $s);
        $s = str_replace(',', '.', $s);
    } else {
        $s = str_replace(',', '.', $s);
    }
    return is_numeric($s) ? (float) $s : null;
}

function apply_update(PDO $pdo, string $target, int $id, array $data): void {
    if (empty($data)) return;
    $allowed = allowed_fields($target);
    $sets = [];
    $params = ['id' => $id];
    foreach ($data as $k => $v) {
        if (!in_array($k, $allowed, true)) continue;
        $sets[] = "`$k` = :$k";
        $params[$k] = $v;
    }
    if (empty($sets)) return;
    $sql = "UPDATE `$target` SET " . implode(', ', $sets) . " WHERE id = :id";
    $pdo->prepare($sql)->execute($params);
}

function apply_create(PDO $pdo, string $target, array $data): void {
    $allowed = allowed_fields($target);
    $cols = []; $vals = []; $params = [];
    foreach ($data as $k => $v) {
        if (!in_array($k, $allowed, true)) continue;
        $cols[] = "`$k`";
        $vals[] = ":$k";
        $params[$k] = $v;
    }
    if (empty($cols)) throw new Exception('Žádné platné sloupce pro vytvoření');
    if ($target === 'vyrobky' && !isset($params['nazev'])) {
        throw new Exception('Chybí název');
    }
    // Doplň defaulty
    if ($target === 'vyrobky') {
        if (!in_array('aktivni', array_keys($params), true)) {
            $cols[] = '`aktivni`'; $vals[] = '1';
        }
        if (!isset($params['jednotka_id'])) {
            $jId = $pdo->query("SELECT id FROM jednotky LIMIT 1")->fetchColumn();
            if ($jId) { $cols[] = '`jednotka_id`'; $vals[] = ':_jid'; $params['_jid'] = (int) $jId; }
        }
        if (!isset($params['sazba_dph_id'])) {
            $sId = $pdo->query("SELECT id FROM sazby_dph ORDER BY id DESC LIMIT 1")->fetchColumn();
            if ($sId) { $cols[] = '`sazba_dph_id`'; $vals[] = ':_sid'; $params['_sid'] = (int) $sId; }
        }
    }
    $sql = "INSERT INTO `$target` (" . implode(',', $cols) . ") VALUES (" . implode(',', $vals) . ")";
    $pdo->prepare($sql)->execute($params);
}

function allowed_fields(string $target): array {
    if ($target === 'vyrobky') {
        return ['nazev', 'cislo', 'ean', 'cena_bez_dph', 'hmotnost_g', 'obsah', 'obsah_jednotka',
                'trvanlivost', 'alergeny', 'poznamka', 'aktivni'];
    }
    return ['nazev', 'jednotka', 'alergen', 'cena_baleni', 'obsah_baleni', 'slozeni', 'slozeni_alergeny', 'poznamka'];
}

function available_fields(string $target): array {
    if ($target === 'vyrobky') {
        return [
            'nazev'        => 'Název *',
            'cislo'        => 'Číslo/kód',
            'ean'          => 'EAN',
            'cena_bez_dph' => 'Cena bez DPH',
            'hmotnost_g'   => 'Hmotnost (g)',
            'obsah'        => 'Obsah',
            'trvanlivost'  => 'Trvanlivost',
            'alergeny'     => 'Alergeny',
            'poznamka'     => 'Poznámka',
        ];
    }
    return [
        'nazev'            => 'Název *',
        'jednotka'         => 'Jednotka',
        'alergen'          => 'Alergen',
        'cena_baleni'      => 'Cena balení',
        'obsah_baleni'     => 'Obsah balení',
        'slozeni'          => 'Složení',
        'poznamka'         => 'Poznámka',
    ];
}
