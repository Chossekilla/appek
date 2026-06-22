<?php
/**
 * 🆕 v3.0.338 — Import produktů z CSV (Shoptet / WooCommerce / Excel / generický CSV).
 * 🆕 v3.0.343 — XML produktový feed (Shoptet <SHOPITEM> apod.).
 * 🆕 v3.0.371 — Import z URL feedu dodavatele (XML/CSV) + uložené feedy + automatický CRON.
 *
 *   POST ?action=preview      (multipart: file)  → { columns, rows, suggested, total, delimiter, fields }
 *   POST ?action=preview_url  (JSON: { url })     → totéž (stáhne feed z URL dodavatele)
 *   POST ?action=commit       (JSON: { rows, mapping{field:colIdx}, match_key, update_existing, create_categories })
 *   GET  ?action=feed_list                        → { feeds[], cron_url }
 *   POST ?action=feed_save    (JSON: { id?, nazev, url, columns[], mapping{field:colIdx}, match_key, update_existing, create_categories, enabled })
 *   POST ?action=feed_delete  (JSON: { id })
 *   POST ?action=feed_run     (JSON: { id })      → stáhne + naimportuje 1 uložený feed hned
 *   GET  ?action=cron&token=  (bez admin session — volá wget z Hostinger CRONu) → spustí všechny enabled feedy
 *
 * Match key: cislo (kód) NEBO ean → update existující / jinak insert. Kategorie dle názvu.
 * Mapping uloženého feedu se ukládá jako field→NÁZEV sloupce (odolné vůči změně pořadí sloupců
 * ve feedu); při běhu se název přemapuje na aktuální index.
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/_admin_auth.php';
cors_headers();

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

// 🆕 v3.0.371 — sjednocený parse (XML vs CSV dle prvního znaku) → {columns, rows, delimiter}. Hází Exception.
function import_parse_raw(string $raw): array {
    if (strpos(ltrim($raw), '<') === 0) {
        $xr = import_parse_xml($raw);
        if (!$xr || empty($xr['rows'])) throw new Exception('XML se nepodařilo načíst — očekávám produktový feed (např. Shoptet <SHOPITEM>)');
        return ['columns' => $xr['columns'], 'rows' => $xr['rows'], 'delimiter' => 'xml'];
    }
    if (!mb_check_encoding($raw, 'UTF-8')) {
        $conv = @mb_convert_encoding($raw, 'UTF-8', 'Windows-1250, ISO-8859-2, Windows-1252');
        if ($conv) $raw = $conv;
    }
    $p = import_parse_csv($raw);
    $rows = $p['rows'];
    if (count($rows) < 2) throw new Exception('CSV musí mít hlavičku + alespoň 1 řádek dat');
    $header = array_map(fn($h) => trim((string) $h), array_shift($rows));
    return ['columns' => $header, 'rows' => $rows, 'delimiter' => $p['delim']];
}

// 🆕 v3.0.371 — auto-mapování (název sloupce → pole) — sdílené preview + preview_url.
function import_suggest_mapping(array $header): array {
    $suggested = []; $usedCols = [];
    foreach (import_field_defs() as $field => $def) {
        foreach ($header as $i => $col) {
            if (in_array($i, $usedCols, true)) continue; // sloupec použij max 1×
            $colN = mb_strtolower(trim((string) $col));
            if ($colN === '') continue;
            foreach ($def['kw'] as $kw) {
                if ($colN === $kw || mb_strpos($colN, $kw) !== false) { $suggested[$field] = $i; $usedCols[] = $i; break 2; }
            }
        }
    }
    return $suggested;
}

// 🆕 v3.0.371 — stažení feedu z URL (User-Agent kvůli hcdn/CDN 403; SSRF guard; timeout; cap 24 MB).
function import_fetch_url(string $url): string {
    $url = trim($url);
    if (!preg_match('~^https?://~i', $url)) throw new Exception('URL musí začínat http:// nebo https://');
    $host = strtolower((string) (parse_url($url, PHP_URL_HOST) ?: ''));
    if ($host === '' || preg_match('~^(localhost|127\.|10\.|192\.168\.|169\.254\.|172\.(1[6-9]|2[0-9]|3[01])\.|0\.|::1|\[)~', $host)) {
        throw new Exception('Interní / loopback adresy nejsou povolené');
    }
    $ua = 'APPEK-FeedImport/1.0 (+https://appek.cz)';
    $raw = false;
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS      => 3,
            CURLOPT_TIMEOUT        => 40,
            CURLOPT_CONNECTTIMEOUT => 12,
            CURLOPT_USERAGENT      => $ua,
            CURLOPT_ENCODING       => '', // gzip/deflate
        ]);
        $raw  = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err  = curl_error($ch);
        curl_close($ch);
        if ($raw === false) throw new Exception('Stažení selhalo: ' . ($err ?: 'neznámá chyba'));
        if ($code >= 400) throw new Exception("Server feedu vrátil HTTP $code");
    } else {
        $ctx = stream_context_create(['http' => ['header' => "User-Agent: $ua\r\n", 'timeout' => 40, 'follow_location' => 1, 'max_redirects' => 3]]);
        $raw = @file_get_contents($url, false, $ctx);
        if ($raw === false) throw new Exception('Stažení selhalo (file_get_contents)');
    }
    if ($raw === '') throw new Exception('Feed je prázdný');
    if (strlen($raw) > 24 * 1024 * 1024) throw new Exception('Feed je větší než 24 MB');
    return $raw;
}

// 🆕 v3.0.371 — tabulka uložených feedů (lazy migrace).
function import_feeds_table(PDO $pdo): void {
    static $done = false;
    if ($done) return;
    $pdo->exec("CREATE TABLE IF NOT EXISTS supplier_feeds (
        id INT AUTO_INCREMENT PRIMARY KEY,
        nazev VARCHAR(190) NOT NULL DEFAULT '',
        url TEXT NOT NULL,
        format VARCHAR(10) NOT NULL DEFAULT 'auto',
        mapping TEXT NOT NULL,
        match_key VARCHAR(10) NOT NULL DEFAULT 'cislo',
        update_existing TINYINT(1) NOT NULL DEFAULT 1,
        create_categories TINYINT(1) NOT NULL DEFAULT 0,
        enabled TINYINT(1) NOT NULL DEFAULT 1,
        last_run DATETIME NULL,
        last_result TEXT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $done = true;
}

// 🆕 v3.0.371 — CRON token (uložený v nastaveni; vytvoří se při prvním čtení).
function import_cron_token(PDO $pdo): string {
    $t = (string) (nastaveni_get($pdo, 'import_cron_token', '') ?? '');
    if ($t === '') {
        $t = bin2hex(random_bytes(16));
        $pdo->prepare("INSERT INTO nastaveni (klic, hodnota) VALUES ('import_cron_token', :t) ON DUPLICATE KEY UPDATE hodnota = VALUES(hodnota)")->execute(['t' => $t]);
    }
    return $t;
}

// 🆕 v3.0.371 — commit řádků do vyrobky (vytaženo z action=commit; sdílí cron / feed_run / commit).
//   mapping = field→colIndex. Vrací statistiky. Při chybě rollback + rethrow (řeší volající).
function import_commit_rows(PDO $pdo, array $rows, array $mapping, string $matchKey, bool $updateExisting, bool $createCats): array {
    $matchKey = in_array($matchKey, ['cislo', 'ean'], true) ? $matchKey : 'cislo';

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
        throw $e;
    }
    return ['inserted' => $inserted, 'updated' => $updated, 'skipped' => $skipped, 'categories_created' => $catsCreated, 'priced_zero' => $pricedZero];
}

// 🆕 v3.0.371 — spuštění 1 uloženého feedu: stáhni → parsuj → přemapuj NÁZVY sloupců na indexy → commit.
function import_run_feed(PDO $pdo, array $feed): array {
    $raw    = import_fetch_url((string) $feed['url']);
    $parsed = import_parse_raw($raw);
    $cols   = $parsed['columns'];
    $savedMap = json_decode((string) ($feed['mapping'] ?: '{}'), true) ?: []; // field → colName
    $mapping  = [];
    foreach ($savedMap as $field => $colName) {
        $idx = array_search($colName, $cols, true);
        if ($idx !== false) $mapping[$field] = $idx;
    }
    if (!isset($mapping['nazev'])) {
        throw new Exception('Sloupec pro „Název" (' . (string) ($savedMap['nazev'] ?? '?') . ') se ve feedu nenašel — zkontroluj mapování.');
    }
    $rows = array_slice($parsed['rows'], 0, 20000);
    return import_commit_rows($pdo, $rows, $mapping, (string) $feed['match_key'], (bool) $feed['update_existing'], (bool) $feed['create_categories']);
}

// ─────────────────────────────────────────────────────────────────────────
// CRON — token-based, BEZ admin session (volá wget z Hostinger CRONu). MUSÍ být před require_admin().
// ─────────────────────────────────────────────────────────────────────────
if ($action === 'cron') {
    import_feeds_table($pdo);
    $token = (string) ($_GET['token'] ?? '');
    if (!hash_equals(import_cron_token($pdo), $token)) {
        http_response_code(403);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok' => false, 'error' => 'Neplatný token']);
        exit;
    }
    $feeds = $pdo->query("SELECT * FROM supplier_feeds WHERE enabled = 1")->fetchAll(PDO::FETCH_ASSOC);
    $results = [];
    foreach ($feeds as $f) {
        try {
            $st  = import_run_feed($pdo, $f);
            $msg = "OK: +{$st['inserted']} nových / {$st['updated']} aktualizováno / {$st['skipped']} přeskočeno";
            $results[] = ['id' => (int) $f['id'], 'nazev' => $f['nazev'], 'ok' => true] + $st;
        } catch (Throwable $e) {
            $msg = 'CHYBA: ' . $e->getMessage();
            $results[] = ['id' => (int) $f['id'], 'nazev' => $f['nazev'], 'ok' => false, 'error' => $e->getMessage()];
        }
        try { $pdo->prepare("UPDATE supplier_feeds SET last_run = NOW(), last_result = :r WHERE id = :id")->execute(['r' => $msg, 'id' => $f['id']]); } catch (Throwable $e) {}
    }
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['ok' => true, 'ran' => count($feeds), 'results' => $results], JSON_UNESCAPED_UNICODE);
    exit;
}

require_admin();

// ─── preview (nahraný soubor) ───
if ($method === 'POST' && $action === 'preview') {
    if (empty($_FILES['file']) || ($_FILES['file']['error'] ?? 1) !== UPLOAD_ERR_OK) json_error('Chybí soubor (CSV / XML)', 400);
    if ($_FILES['file']['size'] > 24 * 1024 * 1024) json_error('Soubor je větší než 24 MB', 400);
    $raw = file_get_contents($_FILES['file']['tmp_name']);
    if ($raw === false || $raw === '') json_error('Prázdný soubor', 400);
    try { $parsed = import_parse_raw($raw); } catch (Throwable $e) { json_error($e->getMessage(), 400); }
    $header = $parsed['columns'];
    $rows = $parsed['rows'];
    $cap = 3000;
    json_response([
        'ok' => true,
        'columns' => $header,
        'rows' => array_slice($rows, 0, $cap),
        'total' => count($rows),
        'capped' => count($rows) > $cap,
        'suggested' => import_suggest_mapping($header),
        'delimiter' => $parsed['delimiter'],
        'fields' => array_map(fn($d) => $d['label'], import_field_defs()),
    ]);
}

// ─── preview_url (stáhne feed z URL dodavatele) ───
if ($method === 'POST' && $action === 'preview_url') {
    $d = json_input();
    $url = trim((string) ($d['url'] ?? ''));
    if ($url === '') json_error('Chybí URL feedu', 400);
    try {
        $raw = import_fetch_url($url);
        $parsed = import_parse_raw($raw);
    } catch (Throwable $e) {
        json_error($e->getMessage(), 400);
    }
    $header = $parsed['columns'];
    $rows = $parsed['rows'];
    $cap = 3000;
    json_response([
        'ok' => true,
        'columns' => $header,
        'rows' => array_slice($rows, 0, $cap),
        'total' => count($rows),
        'capped' => count($rows) > $cap,
        'suggested' => import_suggest_mapping($header),
        'delimiter' => $parsed['delimiter'],
        'fields' => array_map(fn($d) => $d['label'], import_field_defs()),
    ]);
}

// ─── commit (jednorázový import z preview) ───
if ($method === 'POST' && $action === 'commit') {
    require_super_admin();
    $d = json_input();
    $rows = $d['rows'] ?? [];
    $mapping = $d['mapping'] ?? [];
    $matchKey = $d['match_key'] ?? 'cislo';
    $updateExisting = !empty($d['update_existing']);
    $createCats = !empty($d['create_categories']);
    if (!$rows || !isset($mapping['nazev']) || $mapping['nazev'] === '' || $mapping['nazev'] === null) {
        json_error('Chybí data nebo mapování pole „Název"', 400);
    }
    if (count($rows) > 5000) json_error('Příliš mnoho řádků (max 5000 na jeden import)', 400);
    try {
        $st = import_commit_rows($pdo, $rows, $mapping, $matchKey, $updateExisting, $createCats);
    } catch (Throwable $e) {
        json_error_safe('Import selhal: ' . $e->getMessage(), $e, 500);
    }
    json_response(['ok' => true] + $st);
}

// ─── feed_list (uložené feedy + cron URL) ───
if ($method === 'GET' && $action === 'feed_list') {
    require_super_admin();
    import_feeds_table($pdo);
    $feeds = $pdo->query("SELECT id, nazev, url, format, match_key, update_existing, create_categories, enabled, last_run, last_result, created_at FROM supplier_feeds ORDER BY id DESC")->fetchAll(PDO::FETCH_ASSOC);
    $token = import_cron_token($pdo);
    json_response([
        'ok' => true,
        'feeds' => $feeds,
        'cron_url' => rtrim(APP_URL, '/') . '/api/admin_import.php?action=cron&token=' . $token,
    ]);
}

// ─── feed_save (nový / úprava uloženého feedu) ───
if ($method === 'POST' && $action === 'feed_save') {
    require_super_admin();
    import_feeds_table($pdo);
    $d = json_input();
    $nazev   = trim((string) ($d['nazev'] ?? ''));
    $url     = trim((string) ($d['url'] ?? ''));
    $columns = $d['columns'] ?? [];      // hlavička z preview (pro převod index→název)
    $mapIdx  = $d['mapping'] ?? [];       // field → colIndex (z UI)
    if ($url === '') json_error('Chybí URL feedu', 400);
    if (!preg_match('~^https?://~i', $url)) json_error('URL musí začínat http:// nebo https://', 400);
    if (!isset($mapIdx['nazev']) || $mapIdx['nazev'] === '' || $mapIdx['nazev'] === null) json_error('Chybí mapování pole „Název"', 400);
    // field→index → field→NÁZEV sloupce (odolné vůči změně pořadí ve feedu)
    $mapName = [];
    foreach ($mapIdx as $field => $i) {
        if ($i === '' || $i === null) continue;
        $i = (int) $i;
        if (isset($columns[$i]) && $columns[$i] !== '') $mapName[$field] = (string) $columns[$i];
    }
    if (!isset($mapName['nazev'])) json_error('Sloupec „Název" je mimo rozsah hlavičky', 400);
    $matchKey = in_array($d['match_key'] ?? 'cislo', ['cislo', 'ean'], true) ? $d['match_key'] : 'cislo';
    $upd = !empty($d['update_existing']) ? 1 : 0;
    $cc  = !empty($d['create_categories']) ? 1 : 0;
    $en  = array_key_exists('enabled', $d) ? (!empty($d['enabled']) ? 1 : 0) : 1;
    $mapJson = json_encode($mapName, JSON_UNESCAPED_UNICODE);

    if (!empty($d['id'])) {
        $pdo->prepare("UPDATE supplier_feeds SET nazev = :n, url = :u, mapping = :m, match_key = :mk, update_existing = :ue, create_categories = :cc, enabled = :en WHERE id = :id")
            ->execute(['n' => $nazev ?: ('Feed ' . (int) $d['id']), 'u' => $url, 'm' => $mapJson, 'mk' => $matchKey, 'ue' => $upd, 'cc' => $cc, 'en' => $en, 'id' => (int) $d['id']]);
        $id = (int) $d['id'];
    } else {
        $pdo->prepare("INSERT INTO supplier_feeds (nazev, url, format, mapping, match_key, update_existing, create_categories, enabled) VALUES (:n, :u, 'auto', :m, :mk, :ue, :cc, :en)")
            ->execute(['n' => $nazev ?: 'Feed dodavatele', 'u' => $url, 'm' => $mapJson, 'mk' => $matchKey, 'ue' => $upd, 'cc' => $cc, 'en' => $en]);
        $id = (int) $pdo->lastInsertId();
    }
    json_response(['ok' => true, 'id' => $id]);
}

// ─── feed_delete ───
if ($method === 'POST' && $action === 'feed_delete') {
    require_super_admin();
    import_feeds_table($pdo);
    $d = json_input();
    $pdo->prepare("DELETE FROM supplier_feeds WHERE id = :id")->execute(['id' => (int) ($d['id'] ?? 0)]);
    json_response(['ok' => true]);
}

// ─── feed_run (spustit 1 uložený feed teď) ───
if ($method === 'POST' && $action === 'feed_run') {
    require_super_admin();
    import_feeds_table($pdo);
    $d = json_input();
    $st = $pdo->prepare("SELECT * FROM supplier_feeds WHERE id = :id");
    $st->execute(['id' => (int) ($d['id'] ?? 0)]);
    $feed = $st->fetch(PDO::FETCH_ASSOC);
    if (!$feed) json_error('Feed nenalezen', 404);
    try {
        $res = import_run_feed($pdo, $feed);
        $msg = "OK: +{$res['inserted']} nových / {$res['updated']} aktualizováno / {$res['skipped']} přeskočeno";
        $pdo->prepare("UPDATE supplier_feeds SET last_run = NOW(), last_result = :r WHERE id = :id")->execute(['r' => $msg, 'id' => $feed['id']]);
        json_response(['ok' => true] + $res);
    } catch (Throwable $e) {
        try { $pdo->prepare("UPDATE supplier_feeds SET last_run = NOW(), last_result = :r WHERE id = :id")->execute(['r' => 'CHYBA: ' . $e->getMessage(), 'id' => $feed['id']]); } catch (Throwable $e2) {}
        json_error_safe('Spuštění feedu selhalo: ' . $e->getMessage(), $e, 500);
    }
}

json_error('Neznámá akce', 400);
