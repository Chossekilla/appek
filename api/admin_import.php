<?php
/**
 * 🆕 v3.0.338 — Import produktů z CSV (Shoptet / WooCommerce / Excel / generický CSV).
 *   POST ?action=preview  (multipart: file)  → { columns, rows, suggested, total, delimiter, fields }
 *   POST ?action=commit   (JSON: { rows, mapping{field:colIdx}, match_key, update_existing, create_categories })
 *        → { inserted, updated, skipped, categories_created }
 *
 * Match key: cislo (kód) NEBO ean → update existující / jinak insert. Kategorie dle názvu
 * (volitelně založí novou). Jednotka/DPH dle kódu/sazby, jinak default instalace.
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/_admin_auth.php';
cors_headers();
require_admin();

$pdo = db();
$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? '';

function import_field_defs(): array {
    return [
        'cislo'          => ['label' => 'Kód / číslo',       'kw' => ['cislo', 'kód', 'kod', 'code', 'sku', 'katalog', 'product code']],
        'ean'            => ['label' => 'EAN / čárový kód',   'kw' => ['ean', 'barcode', 'čárový', 'carovy', 'gtin']],
        'nazev'          => ['label' => 'Název *',            'kw' => ['nazev', 'název', 'name', 'title', 'produkt', 'product', 'zboží', 'zbozi']],
        'cena_s_dph'     => ['label' => 'Cena s DPH',         'kw' => ['price_vat', 'price vat', 'pricevat', 'cena s dph', 'cena_s_dph', 'vč. dph', 'vč dph', 'vc dph', 'včetně dph', 'vcetne dph', 's dph', 'brutto', 'gross']],
        'cena_bez_dph'   => ['label' => 'Cena bez DPH',       'kw' => ['cena bez', 'cena_bez', 'bez dph', 'netto', 'price', 'cena']],
        'kategorie'      => ['label' => 'Kategorie (název)',  'kw' => ['kategorie', 'categor', 'sekce', 'skupina']],
        'hmotnost_g'     => ['label' => 'Hmotnost (g)',       'kw' => ['hmotnost', 'weight', 'váha', 'vaha', 'gram']],
        'popis'          => ['label' => 'Popis',              'kw' => ['popis', 'description', 'desc', 'anotace']],
        'jednotka'       => ['label' => 'Jednotka (kód)',     'kw' => ['jednotka', 'unit', 'mj', 'měrná', 'merna']],
        'dph'            => ['label' => 'DPH (%)',            'kw' => ['dph', 'vat', 'sazba', 'tax']],
        'min_objednavka' => ['label' => 'Min. objednávka',   'kw' => ['min objedn', 'minimum', 'min_obj', 'min mn']],
    ];
}

function import_parse_csv(string $raw): array {
    $raw = preg_replace('/^\xEF\xBB\xBF/', '', $raw); // BOM
    $firstLine = strtok($raw, "\r\n") ?: '';
    $delims = [';' => substr_count($firstLine, ';'), ',' => substr_count($firstLine, ','), "\t" => substr_count($firstLine, "\t"), '|' => substr_count($firstLine, '|')];
    arsort($delims);
    $delim = key($delims);
    if (($delims[$delim] ?? 0) === 0) $delim = ';';
    $rows = [];
    $fh = fopen('php://temp', 'r+');
    fwrite($fh, $raw);
    rewind($fh);
    while (($r = fgetcsv($fh, 0, $delim)) !== false) {
        if (count($r) === 1 && trim((string) $r[0]) === '') continue;
        $rows[] = $r;
    }
    fclose($fh);
    return ['delim' => $delim, 'rows' => $rows];
}

// 🆕 v3.0.343 — XML produktový feed → sloupce + řádky (reuse stejného mapování jako CSV).
//   Najde opakující se item element (Shoptet <SHOPITEM>, generický <item>/<product>, i WXR <item>).
function import_parse_xml(string $raw): ?array {
    $raw = trim($raw);
    if ($raw === '' || $raw[0] !== '<') return null;
    libxml_use_internal_errors(true);
    $xml = simplexml_load_string($raw, 'SimpleXMLElement', LIBXML_NOCDATA | LIBXML_NONET);
    if ($xml === false) return null;

    $findItems = function ($node) {
        $counts = [];
        foreach ($node->children() as $ch) { $n = $ch->getName(); $counts[$n] = ($counts[$n] ?? 0) + 1; }
        if (!$counts) return null;
        arsort($counts);
        $tag = array_key_first($counts);
        return $node->{$tag};
    };
    $items = $findItems($xml);
    // root obaluje jediný wrapper (<SHOP>→<SHOPITEM> nebo <rss>→<channel>→<item>) → ponoř se
    if ($items !== null && count($items) === 1) {
        $deeper = $findItems($items[0]);
        if ($deeper !== null && count($deeper) > 1) $items = $deeper;
    }
    if ($items === null || count($items) === 0) return null;

    $cols = []; $rows = [];
    foreach ($items as $it) {
        $row = [];
        foreach ($it->children() as $f) {
            $name = $f->getName();
            if (array_key_exists($name, $row)) continue; // jen první výskyt tagu (víc <CATEGORY> → první)
            $val = trim((string) $f);
            if ($val === '' && count($f->children())) {
                foreach ($f->children() as $cc) { $t = trim((string) $cc); if ($t !== '') { $val = $t; break; } }
            }
            $row[$name] = $val;
            if (!in_array($name, $cols, true)) $cols[] = $name;
        }
        foreach ($it->attributes() as $an => $av) {
            $k = '@' . $an;
            if (array_key_exists($k, $row)) continue;
            $row[$k] = trim((string) $av);
            if (!in_array($k, $cols, true)) $cols[] = $k;
        }
        $rows[] = $row;
    }
    $out = [];
    foreach ($rows as $r) { $line = []; foreach ($cols as $c) $line[] = $r[$c] ?? ''; $out[] = $line; }
    return ['columns' => $cols, 'rows' => $out];
}

if ($method === 'POST' && $action === 'preview') {
    if (empty($_FILES['file']) || ($_FILES['file']['error'] ?? 1) !== UPLOAD_ERR_OK) json_error('Chybí soubor (CSV)', 400);
    if ($_FILES['file']['size'] > 8 * 1024 * 1024) json_error('Soubor je větší než 8 MB', 400);
    $raw = file_get_contents($_FILES['file']['tmp_name']);
    if ($raw === false || $raw === '') json_error('Prázdný soubor', 400);

    // 🆕 v3.0.343 — XML feed (Shoptet apod.) vs CSV — detekce dle prvního znaku
    if (strpos(ltrim($raw), '<') === 0) {
        $xr = import_parse_xml($raw);
        if (!$xr || empty($xr['rows'])) json_error('XML se nepodařilo načíst — očekávám produktový feed (např. Shoptet <SHOPITEM>)', 400);
        $header = $xr['columns'];
        $rows = $xr['rows'];
        $delim = 'xml';
    } else {
        if (!mb_check_encoding($raw, 'UTF-8')) {
            $conv = @mb_convert_encoding($raw, 'UTF-8', 'Windows-1250, ISO-8859-2, Windows-1252');
            if ($conv) $raw = $conv;
        }
        $p = import_parse_csv($raw);
        $rows = $p['rows'];
        if (count($rows) < 2) json_error('CSV musí mít hlavičku + alespoň 1 řádek dat', 400);
        $header = array_map(fn($h) => trim((string) $h), array_shift($rows));
        $delim = $p['delim'];
    }

    $suggested = [];
    $usedCols = [];
    foreach (import_field_defs() as $field => $def) {
        foreach ($header as $i => $col) {
            if (in_array($i, $usedCols, true)) continue; // sloupec použij max 1× (ať „Cena bez DPH" nesedí i na DPH)
            $colN = mb_strtolower(trim($col));
            if ($colN === '') continue;
            foreach ($def['kw'] as $kw) {
                if ($colN === $kw || mb_strpos($colN, $kw) !== false) { $suggested[$field] = $i; $usedCols[] = $i; break 2; }
            }
        }
    }
    $cap = 3000;
    json_response([
        'ok' => true,
        'columns' => $header,
        'rows' => array_slice($rows, 0, $cap),
        'total' => count($rows),
        'capped' => count($rows) > $cap,
        'suggested' => $suggested,
        'delimiter' => $delim,
        'fields' => array_map(fn($d) => $d['label'], import_field_defs()),
    ]);
}

if ($method === 'POST' && $action === 'commit') {
    require_super_admin();
    $d = json_input();
    $rows = $d['rows'] ?? [];
    $mapping = $d['mapping'] ?? [];
    $matchKey = in_array($d['match_key'] ?? 'cislo', ['cislo', 'ean'], true) ? $d['match_key'] : 'cislo';
    $updateExisting = !empty($d['update_existing']);
    $createCats = !empty($d['create_categories']);
    if (!$rows || !isset($mapping['nazev']) || $mapping['nazev'] === '' || $mapping['nazev'] === null) {
        json_error('Chybí data nebo mapování pole „Název"', 400);
    }
    if (count($rows) > 5000) json_error('Příliš mnoho řádků (max 5000 na jeden import)', 400);

    $defJed   = (int) ($pdo->query("SELECT id FROM jednotky ORDER BY id LIMIT 1")->fetchColumn() ?: 0);
    $jedByKod = $pdo->query("SELECT LOWER(kod), id FROM jednotky")->fetchAll(PDO::FETCH_KEY_PAIR);
    $defSazba = (int) ($pdo->query("SELECT id FROM sazby_dph ORDER BY sazba DESC LIMIT 1")->fetchColumn() ?: 0);
    $defSazbaPct = (float) ($pdo->query("SELECT sazba FROM sazby_dph ORDER BY sazba DESC LIMIT 1")->fetchColumn() ?: 0);
    $sazbaByVal = $pdo->query("SELECT CAST(sazba AS UNSIGNED), id FROM sazby_dph")->fetchAll(PDO::FETCH_KEY_PAIR);
    $catByName = [];
    foreach ($pdo->query("SELECT id, LOWER(nazev) AS n FROM kategorie_vyrobku") as $kr) $catByName[$kr['n']] = (int) $kr['id'];

    $get = function ($row, $field) use ($mapping) {
        if (!isset($mapping[$field]) || $mapping[$field] === '' || $mapping[$field] === null) return null;
        $i = (int) $mapping[$field];
        return isset($row[$i]) ? trim((string) $row[$i]) : null;
    };
    $num = function ($v) {
        if ($v === null || $v === '') return null;
        $v = str_replace([' ', "\xc2\xa0", 'Kč', 'kč', 'CZK', 'czk'], '', $v);
        $v = str_replace(',', '.', $v);
        return is_numeric($v) ? (float) $v : null;
    };

    $inserted = 0; $updated = 0; $skipped = 0; $catsCreated = 0; $pricedZero = 0;
    $pdo->beginTransaction();
    try {
        $insCat = $pdo->prepare("INSERT INTO kategorie_vyrobku (nazev, ikona, poradi, aktivni) VALUES (:n, '📦', 999, 1)");
        foreach ($rows as $row) {
            $nazev = $get($row, 'nazev');
            if (!$nazev) { $skipped++; continue; }
            $cislo = $get($row, 'cislo');
            $ean   = $get($row, 'ean');
            $dphVal = $num($get($row, 'dph'));
            $cena = $num($get($row, 'cena_bez_dph'));
            if ($cena === null) {                                   // jen „cena s DPH" → dopočti základ dle DPH (řádek/default)
                $gross = $num($get($row, 'cena_s_dph'));
                if ($gross !== null) {
                    $pct = $dphVal !== null ? $dphVal : $defSazbaPct;
                    $cena = ($pct > 0) ? round($gross / (1 + $pct / 100), 2) : $gross;
                }
            }
            $cena = $cena ?? 0;
            $hm    = $num($get($row, 'hmotnost_g'));
            $popis = $get($row, 'popis');
            $minObj = $num($get($row, 'min_objednavka'));

            $katId = null;
            $katNazev = $get($row, 'kategorie');
            if ($katNazev) {
                $kn = mb_strtolower($katNazev);
                if (isset($catByName[$kn])) $katId = $catByName[$kn];
                elseif ($createCats) { $insCat->execute(['n' => $katNazev]); $katId = (int) $pdo->lastInsertId(); $catByName[$kn] = $katId; $catsCreated++; }
            }
            $jedKod = $get($row, 'jednotka');
            $jedId = ($jedKod && isset($jedByKod[mb_strtolower($jedKod)])) ? (int) $jedByKod[mb_strtolower($jedKod)] : $defJed;
            $sazbaId = ($dphVal !== null && isset($sazbaByVal[(string) (int) $dphVal])) ? (int) $sazbaByVal[(string) (int) $dphVal] : $defSazba;

            $existId = null;
            if ($matchKey === 'ean' && $ean) {
                $st = $pdo->prepare("SELECT id FROM vyrobky WHERE ean = :e LIMIT 1"); $st->execute(['e' => $ean]); $existId = $st->fetchColumn() ?: null;
            } elseif ($matchKey === 'cislo' && $cislo) {
                $st = $pdo->prepare("SELECT id FROM vyrobky WHERE cislo = :c LIMIT 1"); $st->execute(['c' => $cislo]); $existId = $st->fetchColumn() ?: null;
            }

            if ($existId) {
                if (!$updateExisting) { $skipped++; continue; }
                $sets = ['nazev = :nazev', 'cena_bez_dph = :cena'];
                $params = ['id' => $existId, 'nazev' => $nazev, 'cena' => $cena];
                if ($ean !== null && $ean !== '')   { $sets[] = 'ean = :ean'; $params['ean'] = $ean; }
                if ($popis !== null)                 { $sets[] = 'popis = :popis'; $params['popis'] = $popis; }
                if ($hm !== null)                    { $sets[] = 'hmotnost_g = :hm'; $params['hm'] = $hm; }
                if ($katId !== null)                 { $sets[] = 'kategorie_id = :kat'; $params['kat'] = $katId; }
                if ($minObj !== null)                { $sets[] = 'min_objednavka = :min'; $params['min'] = $minObj; }
                $pdo->prepare("UPDATE vyrobky SET " . implode(', ', $sets) . " WHERE id = :id")->execute($params);
                $updated++;
                if ($cena == 0) $pricedZero++;
            } else {
                $pdo->prepare("
                    INSERT INTO vyrobky (cislo, ean, nazev, popis, kategorie_id, jednotka_id, cena_bez_dph, sazba_dph_id, hmotnost_g, min_objednavka, aktivni)
                    VALUES (:cislo, :ean, :nazev, :popis, :kat, :jed, :cena, :sazba, :hm, :min, 1)
                ")->execute([
                    'cislo' => $cislo ?: null, 'ean' => $ean ?: null, 'nazev' => $nazev, 'popis' => $popis,
                    'kat' => $katId, 'jed' => $jedId ?: null, 'cena' => $cena, 'sazba' => $sazbaId ?: null,
                    'hm' => $hm, 'min' => $minObj ?? 1,
                ]);
                $inserted++;
                if ($cena == 0) $pricedZero++;
            }
        }
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        json_error_safe('Import selhal: ' . $e->getMessage(), $e, 500);
    }
    json_response(['ok' => true, 'inserted' => $inserted, 'updated' => $updated, 'skipped' => $skipped, 'categories_created' => $catsCreated, 'priced_zero' => $pricedZero]);
}

json_error('Neznámá akce', 400);
