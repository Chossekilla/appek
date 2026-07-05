<?php
/**
 * 🍰 SEZÓNNÍ KATALOG — Sezónní balíček.
 *
 * GET                          → seznam sezón (default + custom) + stats
 * GET ?action=products         → seznam výrobků s jejich sezónou
 * GET ?action=active&date=...  → seznam aktivních sezón na daný den (default dnes)
 * POST ?action=assign          → { product_id, sezona } přiřadit
 * POST ?action=save_season     → { id?, key, label, start_md, end_md, color } create/update custom
 * DELETE ?action=delete_season&id=N → smaž custom sezónu (a unassign výrobky)
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/_admin_auth.php';
require_once __DIR__ . '/_packages_lib.php';
require_once __DIR__ . '/_seasonal_lib.php'; // 🍰 v3.0.331 — sdílená logika sezón (sdílí s katalog.php/cenik)

cors_headers();
require_admin();
header('Content-Type: application/json; charset=UTF-8');

if (!package_enabled('sezona')) {
    http_response_code(402);
    json_response(['error' => 'Vyžaduje balíček 🍰 Sezónní']);
}

$pdo = db();
// Auto-migrace sloupce ve vyrobky
try {
    $cols = $pdo->query("SHOW COLUMNS FROM vyrobky")->fetchAll(PDO::FETCH_COLUMN);
    if (!in_array('sezona', $cols, true)) {
        $pdo->exec("ALTER TABLE vyrobky ADD COLUMN sezona VARCHAR(120) NULL");
        $pdo->exec("ALTER TABLE vyrobky ADD INDEX idx_sezona (sezona)");
    } else {
        // 🆕 v3.0.406 — CSV více sezón ("vanoce,mikulas") potřebuje víc místa než VARCHAR(40)
        $type = $pdo->query("SELECT COLUMN_TYPE FROM information_schema.COLUMNS
                             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'vyrobky' AND COLUMN_NAME = 'sezona'")->fetchColumn();
        if ($type && preg_match('/varchar\((\d+)\)/i', $type, $m) && (int) $m[1] < 120) {
            $pdo->exec("ALTER TABLE vyrobky MODIFY COLUMN sezona VARCHAR(120) NULL");
        }
    }
} catch (Throwable $e) { /* ignore */ }

// Tabulka pro CUSTOM sezóny (mimo default 6) — sleva_pct = sezónní úprava ceny (kladné=sleva, záporné=přirážka)
$pdo->exec("
    CREATE TABLE IF NOT EXISTS seasons_custom (
        id INT AUTO_INCREMENT PRIMARY KEY,
        sezona_key VARCHAR(40) UNIQUE NOT NULL,
        label VARCHAR(100) NOT NULL,
        start_md VARCHAR(5) NOT NULL,
        end_md VARCHAR(5) NOT NULL,
        color VARCHAR(20) DEFAULT '#888888',
        sleva_pct DECIMAL(6,2) NOT NULL DEFAULT 0,
        aktivni TINYINT(1) NOT NULL DEFAULT 1,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
");
seasonal_ensure_schema($pdo); // doplní sleva_pct u starších instalací

// Definice/aktivita/cena sezón = sdílená _seasonal_lib.php (seasonal_default_defs / seasonal_all / seasonal_is_active).

$action = $_GET['action'] ?? '';
$method = $_SERVER['REQUEST_METHOD'];

if ($action === 'products' && $method === 'GET') {
    $rows = $pdo->query("
        SELECT v.id, v.cislo, v.nazev, v.kategorie_id, v.sezona, v.cena_bez_dph,
               k.nazev AS kategorie_nazev
        FROM vyrobky v LEFT JOIN kategorie_vyrobku k ON k.id = v.kategorie_id
        ORDER BY k.nazev, v.nazev
    ")->fetchAll();
    json_response(['products' => $rows]);
}

if ($action === 'assign' && $method === 'POST') {
    $d = json_input();
    $id = (int) ($d['product_id'] ?? 0);
    $sezona = trim($d['sezona'] ?? '');
    if (!$id) json_error('Chybí product_id', 400);
    $valid = array_map(fn($s) => $s['key'], seasonal_all($pdo));
    // 🆕 v3.0.406 — CSV: výrobek může být ve VÍCE sezónách ("vanoce,mikulas")
    if ($sezona !== '') {
        $keys = seasonal_split($sezona);
        foreach ($keys as $k) {
            if (!in_array($k, $valid, true)) json_error("Neplatná sezóna: $k", 400);
        }
        $sezona = implode(',', array_values(array_unique($keys)));
    }
    try {
        $pdo->prepare("UPDATE vyrobky SET sezona = :s WHERE id = :id")->execute(['s' => $sezona ?: null, 'id' => $id]);
        json_response(['ok' => true]);
    } catch (Throwable $e) { json_error_safe('DB', $e, 500); }
}

if ($action === 'active') {
    $date = $_GET['date'] ?? date('Y-m-d');
    $md = substr($date, 5);
    $seasons = array_filter(seasonal_all($pdo), fn($s) => seasonal_is_active($s, $md));
    json_response(['date' => $date, 'active' => array_values($seasons)]);
}

if ($action === 'save_season' && $method === 'POST') {
    require_super_admin();
    $d = json_input();
    $key   = trim($d['key']   ?? '');
    $label = trim($d['label'] ?? '');
    $start = trim($d['start_md'] ?? '');
    $end   = trim($d['end_md']   ?? '');
    $color = trim($d['color'] ?? '#888888');
    $id    = (int) ($d['id']    ?? 0);
    $sleva = max(-90, min(90, (float) ($d['sleva_pct'] ?? 0))); // kladné=sleva, záporné=přirážka (cap ±90 %)
    // 🆕 v3.0.406 — předobjednávky + jednorázová akce s konkrétním rokem + auto-letos
    $predstih  = max(0, min(120, (int) ($d['predstih_dni'] ?? 0)));
    $startFull = trim($d['start_full'] ?? '');
    $endFull   = trim($d['end_full'] ?? '');
    $autoLetos = !empty($d['auto_letos']) ? 1 : 0;
    $isDate = fn($x) => preg_match('/^\d{4}-\d{2}-\d{2}$/', $x);
    if (($startFull !== '' || $endFull !== '') && (!$isDate($startFull) || !$isDate($endFull) || $endFull < $startFull)) {
        json_error('Jednorázová akce: obě data ve formátu RRRR-MM-DD, konec ≥ začátek', 400);
    }

    if (!$key || !$label) json_error('Vyplň klíč a název', 400);
    if (!preg_match('/^\d{2}-\d{2}$/', $start) || !preg_match('/^\d{2}-\d{2}$/', $end)) {
        json_error('Datum ve formátu MM-DD', 400);
    }
    $defaultKeys = array_map(fn($s) => $s['key'], seasonal_default_defs());
    // 🆕 v3.0.339 — výchozí sezóna se NEpřepisuje v seasons_custom, ale jako OVERRIDE (label/datum/barva/sleva)
    if (in_array($key, $defaultKeys, true)) {
        try {
            $ovr = [];
            $st = $pdo->prepare("SELECT hodnota FROM nastaveni WHERE klic = 'seasonal_default_overrides' LIMIT 1");
            $st->execute();
            if ($raw = $st->fetchColumn()) { $j = json_decode($raw, true); if (is_array($j)) $ovr = $j; }
            $ovr[$key] = ['label' => $label, 'start_md' => $start, 'end_md' => $end, 'color' => $color, 'sleva_pct' => $sleva,
                          'predstih_dni' => $predstih, 'auto_letos' => $autoLetos];
            $pdo->prepare("INSERT INTO nastaveni (klic, hodnota) VALUES ('seasonal_default_overrides', :v) ON DUPLICATE KEY UPDATE hodnota = :v2")
                ->execute(['v' => json_encode($ovr, JSON_UNESCAPED_UNICODE), 'v2' => json_encode($ovr, JSON_UNESCAPED_UNICODE)]);
            json_response(['ok' => true, 'override' => true]);
        } catch (Throwable $e) { json_error_safe('DB', $e, 500); }
    }

    try {
        $sf = $startFull !== '' ? $startFull : null;
        $ef = $endFull !== '' ? $endFull : null;
        if ($id > 0) {
            $pdo->prepare("UPDATE seasons_custom SET label = :l, start_md = :s, end_md = :e, color = :c, sleva_pct = :sp,
                           predstih_dni = :pd, start_full = :sf, end_full = :ef WHERE id = :id")
                ->execute(['l' => $label, 's' => $start, 'e' => $end, 'c' => $color, 'sp' => $sleva,
                           'pd' => $predstih, 'sf' => $sf, 'ef' => $ef, 'id' => $id]);
        } else {
            $pdo->prepare("INSERT INTO seasons_custom (sezona_key, label, start_md, end_md, color, sleva_pct, predstih_dni, start_full, end_full)
                           VALUES (:k, :l, :s, :e, :c, :sp, :pd, :sf, :ef)")
                ->execute(['k' => $key, 'l' => $label, 's' => $start, 'e' => $end, 'c' => $color, 'sp' => $sleva,
                           'pd' => $predstih, 'sf' => $sf, 'ef' => $ef]);
        }
        json_response(['ok' => true]);
    } catch (Throwable $e) { json_error_safe('DB', $e, 500); }
}

// 🆕 v3.0.406 — 📊 SEZÓNNÍ REPORT: prodeje per sezóna, aktuální cyklus vs. loni (stejné okno −1 rok).
if ($action === 'report' && $method === 'GET') {
    $out = [];
    $sum = function (string $key, string $od, string $do) use ($pdo): array {
        $st = $pdo->prepare("
            SELECT COALESCE(SUM(op.mnozstvi), 0) AS ks,
                   COALESCE(SUM(op.mnozstvi * op.cena_bez_dph), 0) AS trzba,
                   COUNT(DISTINCT o.id) AS objednavek
            FROM objednavky_polozky op
            JOIN objednavky o ON o.id = op.objednavka_id
            JOIN vyrobky v ON v.id = op.vyrobek_id
            WHERE FIND_IN_SET(:k, REPLACE(COALESCE(v.sezona, ''), ' ', ''))
              AND o.datum_objednani >= :od AND o.datum_objednani < DATE_ADD(:do2, INTERVAL 1 DAY)
              AND COALESCE(o.stav, '') NOT IN ('stornovana', 'storno', 'zrusena')
        ");
        $st->execute(['k' => $key, 'od' => $od, 'do2' => $do]);
        $r = $st->fetch();
        return ['ks' => (float) $r['ks'], 'trzba' => round((float) $r['trzba'], 2), 'objednavek' => (int) $r['objednavek']];
    };
    foreach (seasonal_meta($pdo) as $k => $m) {
        if (empty($m['start']) || empty($m['end'])) continue;
        $lonyOd = date('Y-m-d', strtotime($m['start'] . ' -1 year'));
        $lonyDo = date('Y-m-d', strtotime($m['end'] . ' -1 year'));
        $out[] = [
            'key' => $k, 'label' => $m['label'], 'color' => $m['color'],
            'active' => $m['active'], 'preorder' => $m['preorder'],
            'days_left' => $m['days_left'], 'starts_in' => $m['starts_in'],
            'okno' => ['od' => $m['start'], 'do' => $m['end']],
            'letos' => $sum($k, $m['start'], $m['end']),
            'loni'  => $sum($k, $lonyOd, $lonyDo),
        ];
    }
    json_response(['report' => $out, 'today' => date('Y-m-d')]);
}

// 🆕 v3.0.406 — 🏭 PREDIKCE VÝROBY: suroviny na sezónu = BOM rozpad výrobků sezóny × loňský prodej.
if ($action === 'vyroba_predikce' && $method === 'GET') {
    $key = trim($_GET['key'] ?? '');
    $meta = seasonal_meta($pdo);
    if (!$key || !isset($meta[$key])) json_error('Neznámá sezóna', 400);
    $m = $meta[$key];
    require_once __DIR__ . '/_bom_lib.php';

    // Výrobky sezóny + loňský prodej ve stejném okně
    $lonyOd = date('Y-m-d', strtotime($m['start'] . ' -1 year'));
    $lonyDo = date('Y-m-d', strtotime($m['end'] . ' -1 year'));
    $st = $pdo->prepare("
        SELECT v.id, v.nazev, v.cislo,
               COALESCE((SELECT SUM(op.mnozstvi) FROM objednavky_polozky op
                         JOIN objednavky o ON o.id = op.objednavka_id
                         WHERE op.vyrobek_id = v.id
                           AND o.datum_objednani >= :od AND o.datum_objednani < DATE_ADD(:do2, INTERVAL 1 DAY)
                           AND COALESCE(o.stav, '') NOT IN ('stornovana', 'storno', 'zrusena')), 0) AS loni_ks
        FROM vyrobky v
        WHERE v.aktivni = 1 AND FIND_IN_SET(:k, REPLACE(COALESCE(v.sezona, ''), ' ', ''))
        ORDER BY loni_ks DESC, v.nazev
    ");
    $st->execute(['k' => $key, 'od' => $lonyOd, 'do2' => $lonyDo]);
    $produkty = $st->fetchAll();

    // BOM rozpad × loňské množství → agregované suroviny
    $sur = []; $pol = [];
    foreach ($produkty as $p) {
        $ks = (float) $p['loni_ks'];
        if ($ks > 0) bom_explode($pdo, (int) $p['id'], $ks, $sur, $pol);
    }
    $suroviny = [];
    if ($sur) {
        $ids = implode(',', array_map('intval', array_keys($sur)));
        foreach ($pdo->query("SELECT id, nazev, jednotka, COALESCE(stock_aktualni, 0) AS skladem FROM suroviny WHERE id IN ($ids)") as $r) {
            $need = round((float) $sur[(int) $r['id']], 3);
            $suroviny[] = [
                'id' => (int) $r['id'], 'nazev' => $r['nazev'], 'jednotka' => $r['jednotka'],
                'potreba' => $need, 'skladem' => (float) $r['skladem'],
                'chybi' => max(0, round($need - (float) $r['skladem'], 3)),
            ];
        }
        usort($suroviny, fn($a, $b) => $b['chybi'] <=> $a['chybi']);
    }
    json_response([
        'sezona' => ['key' => $key, 'label' => $m['label'], 'start' => $m['start'], 'end' => $m['end'],
                     'starts_in' => $m['starts_in'], 'active' => $m['active']],
        'zdroj' => ['od' => $lonyOd, 'do' => $lonyDo, 'pozn' => 'loňský prodej ve stejném okně'],
        'produkty' => $produkty,
        'suroviny' => $suroviny,
        'polotovary' => $pol ? array_map(fn($id, $q) => ['vyrobek_id' => (int) $id, 'potreba' => round((float) $q, 3)], array_keys($pol), $pol) : [],
    ]);
}

// 🆕 v3.0.339 — reset výchozí sezóny na původní (smaž override + sleva map)
if ($action === 'reset_default' && $method === 'POST') {
    require_super_admin();
    $d = json_input();
    $key = trim($d['key'] ?? '');
    $defaultKeys = array_map(fn($s) => $s['key'], seasonal_default_defs());
    if (!in_array($key, $defaultKeys, true)) json_error('Neznámá výchozí sezóna', 400);
    try {
        foreach (['seasonal_default_overrides', 'seasonal_default_sleva'] as $klic) {
            $st = $pdo->prepare("SELECT hodnota FROM nastaveni WHERE klic = :k LIMIT 1"); $st->execute(['k' => $klic]);
            $cur = []; if ($raw = $st->fetchColumn()) { $j = json_decode($raw, true); if (is_array($j)) $cur = $j; }
            unset($cur[$key]);
            $pdo->prepare("INSERT INTO nastaveni (klic, hodnota) VALUES (:k, :v) ON DUPLICATE KEY UPDATE hodnota = :v2")
                ->execute(['k' => $klic, 'v' => json_encode($cur, JSON_UNESCAPED_UNICODE), 'v2' => json_encode($cur, JSON_UNESCAPED_UNICODE)]);
        }
        json_response(['ok' => true]);
    } catch (Throwable $e) { json_error_safe('DB', $e, 500); }
}

// 🍰 v3.0.331 — nastav sezónní slevu/přirážku pro VÝCHOZÍ sezónu (klíč → pct do nastaveni.seasonal_default_sleva)
if ($action === 'save_default_sleva' && $method === 'POST') {
    require_super_admin();
    $d = json_input();
    $key   = trim($d['key'] ?? '');
    $sleva = max(-90, min(90, (float) ($d['sleva_pct'] ?? 0)));
    $defaultKeys = array_map(fn($s) => $s['key'], seasonal_default_defs());
    if (!in_array($key, $defaultKeys, true)) json_error('Neznámá výchozí sezóna', 400);
    try {
        $cur = [];
        $st = $pdo->prepare("SELECT hodnota FROM nastaveni WHERE klic = 'seasonal_default_sleva' LIMIT 1");
        $st->execute();
        if ($raw = $st->fetchColumn()) { $j = json_decode($raw, true); if (is_array($j)) $cur = $j; }
        if ($sleva == 0.0) unset($cur[$key]); else $cur[$key] = $sleva;
        $pdo->prepare("INSERT INTO nastaveni (klic, hodnota) VALUES ('seasonal_default_sleva', :v) ON DUPLICATE KEY UPDATE hodnota = :v2")
            ->execute(['v' => json_encode($cur, JSON_UNESCAPED_UNICODE), 'v2' => json_encode($cur, JSON_UNESCAPED_UNICODE)]);
        json_response(['ok' => true]);
    } catch (Throwable $e) { json_error_safe('DB', $e, 500); }
}

if ($action === 'delete_season' && $method === 'DELETE') {
    require_super_admin();
    $id = (int) ($_GET['id'] ?? 0);
    if (!$id) json_error('Chybí id', 400);
    try {
        $key = $pdo->prepare("SELECT sezona_key FROM seasons_custom WHERE id = :id");
        $key->execute(['id' => $id]);
        $sk = $key->fetchColumn();
        if ($sk) {
            $pdo->prepare("UPDATE vyrobky SET sezona = NULL WHERE sezona = :k")->execute(['k' => $sk]);
            $pdo->prepare("DELETE FROM seasons_custom WHERE id = :id")->execute(['id' => $id]);
        }
        json_response(['ok' => true]);
    } catch (Throwable $e) { json_error_safe('DB', $e, 500); }
}

// Default: full list with stats + currently active
$seasons = seasonal_all($pdo);
// 🆕 v3.0.406 — CSV počty (výrobek může být ve více sezónách)
$counts = [];
try {
    foreach ($pdo->query("SELECT sezona FROM vyrobky WHERE sezona IS NOT NULL AND sezona != ''") as $r) {
        foreach (seasonal_split($r['sezona']) as $k) $counts[$k] = ($counts[$k] ?? 0) + 1;
    }
} catch (Throwable $e) {}

$selectedDate = $_GET['date'] ?? date('Y-m-d');
$selectedMd = substr($selectedDate, 5);
$metaAll = seasonal_meta($pdo);

$out = [];
foreach ($seasons as $s) {
    $m = $metaAll[$s['key']] ?? null;
    $s['active_today'] = $m ? $m['active'] : seasonal_is_active($s, date('m-d'));
    $s['active_on_selected'] = seasonal_is_active($s, $selectedMd);
    $s['count'] = $counts[$s['key']] ?? 0;
    // 🆕 v3.0.406 — resolved okno + countdown + preorder pro UI
    if ($m) {
        $s['okno_od']    = $m['start'];
        $s['okno_do']    = $m['end'];
        $s['preorder']   = $m['preorder'];
        $s['days_left']  = $m['days_left'];
        $s['starts_in']  = $m['starts_in'];
    }
    $out[] = $s;
}
json_response([
    'seasons' => $out,
    'selected_date' => $selectedDate,
    'today' => date('Y-m-d'),
]);
