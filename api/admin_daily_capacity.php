<?php
/**
 * 📅 DENNÍ KAPACITA PEČENÍ — Cukrárna balíček.
 *
 * GET                     → seznam dnů s kapacitou a aktuálním vytížením
 * GET ?date=YYYY-MM-DD    → konkrétní den + obsazenost
 * POST                    → { date, max_dortu, max_chlebicku, max_zakousků } → uloží
 * DELETE ?date=YYYY-MM-DD → smaže nastavení (vrátí na default)
 *
 * Vyžaduje balíček 'cukrarna' nebo 'lahudky'.
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/_admin_auth.php';
require_once __DIR__ . '/_packages_lib.php';

cors_headers();
require_admin();
header('Content-Type: application/json; charset=UTF-8');

if (!package_enabled('cukrarna') && !package_enabled('lahudky')) {
    http_response_code(402);
    json_response(['error' => 'Vyžaduje balíček 🧁 Cukrárna nebo 🥗 Lahůdky']);
}

$pdo = db();
$method = $_SERVER['REQUEST_METHOD'];

// Auto-migrace
$pdo->exec("
    CREATE TABLE IF NOT EXISTS daily_capacity (
        date DATE PRIMARY KEY,
        max_dortu INT NULL,
        max_chlebicku INT NULL,
        max_zakousku INT NULL,
        poznamka VARCHAR(300) NULL,
        zavreno TINYINT(1) NOT NULL DEFAULT 0,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        INDEX idx_date (date)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
");

// Defaultní limity (jeden řádek v nastaveni JSON)
function default_capacity(PDO $pdo): array {
    try {
        $raw = $pdo->query("SELECT hodnota FROM nastaveni WHERE klic = 'default_daily_capacity'")->fetchColumn();
        if ($raw) {
            $d = json_decode($raw, true);
            if (is_array($d)) return $d;
        }
    } catch (Throwable $e) {}
    return ['max_dortu' => 8, 'max_chlebicku' => 200, 'max_zakousku' => 80];
}

// Spočítá obsazenost pro daný den (kolik dortů už je objednáno)
function get_occupancy(PDO $pdo, string $date): array {
    $out = ['dortu' => 0, 'chlebicku' => 0, 'zakousku' => 0];
    try {
        // Heuristika: kategorie obsahuje slovo "dort", "chlebíček", "zákus"
        $rows = $pdo->prepare("
            SELECT k.nazev AS kat, COALESCE(SUM(op.mnozstvi), 0) AS qty
            FROM objednavky o
            JOIN objednavky_polozky op ON op.objednavka_id = o.id
            LEFT JOIN vyrobky v ON v.id = op.vyrobek_id
            LEFT JOIN kategorie_vyrobku k ON k.id = v.kategorie_id
            WHERE o.datum_dodani = :d AND o.stav != 'zrusena'
            GROUP BY k.nazev
        ");
        $rows->execute(['d' => $date]);
        foreach ($rows->fetchAll() as $r) {
            $name = mb_strtolower($r['kat'] ?? '');
            if (str_contains($name, 'dort'))      $out['dortu']     += (float) $r['qty'];
            if (str_contains($name, 'chlebíč') || str_contains($name, 'chlebic')) $out['chlebicku'] += (float) $r['qty'];
            if (str_contains($name, 'zákus')  || str_contains($name, 'zakus') || str_contains($name, 'koláč'))  $out['zakousku']  += (float) $r['qty'];
        }
    } catch (Throwable $e) { /* tabulky možná chybí */ }
    return $out;
}

if ($method === 'GET') {
    $date = $_GET['date'] ?? null;
    $defaults = default_capacity($pdo);

    if ($date) {
        // Konkrétní den
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) json_error('Neplatný formát data', 400);
        $stmt = $pdo->prepare("SELECT * FROM daily_capacity WHERE date = :d");
        $stmt->execute(['d' => $date]);
        $row = $stmt->fetch() ?: ['date' => $date];
        $occ = get_occupancy($pdo, $date);
        json_response([
            'date'        => $date,
            'max_dortu'   => $row['max_dortu']   ?? $defaults['max_dortu'],
            'max_chlebicku' => $row['max_chlebicku'] ?? $defaults['max_chlebicku'],
            'max_zakousku' => $row['max_zakousku']  ?? $defaults['max_zakousku'],
            'zavreno'     => (int) ($row['zavreno'] ?? 0),
            'poznamka'    => $row['poznamka'] ?? null,
            'obsazenost'  => $occ,
            'custom'      => isset($row['max_dortu']),
            'defaults'    => $defaults,
        ]);
    }

    // Seznam — defaultně příštích 30 dní
    $from = $_GET['from'] ?? date('Y-m-d');
    $to   = $_GET['to']   ?? date('Y-m-d', strtotime('+30 days'));
    $stmt = $pdo->prepare("SELECT * FROM daily_capacity WHERE date BETWEEN :f AND :t ORDER BY date");
    $stmt->execute(['f' => $from, 't' => $to]);
    $custom = [];
    foreach ($stmt->fetchAll() as $r) $custom[$r['date']] = $r;

    // Generuj seznam dnů
    $out = [];
    $current = strtotime($from);
    $end = strtotime($to);
    while ($current <= $end) {
        $d = date('Y-m-d', $current);
        $row = $custom[$d] ?? null;
        $occ = get_occupancy($pdo, $d);
        $maxD = $row['max_dortu']   ?? $defaults['max_dortu'];
        $out[] = [
            'date'         => $d,
            'weekday'      => (int) date('N', $current),
            'max_dortu'    => $maxD,
            'obsazenost'   => $occ,
            'volna_kapacita' => max(0, $maxD - $occ['dortu']),
            'zavreno'      => (int) ($row['zavreno'] ?? 0),
            'poznamka'     => $row['poznamka'] ?? null,
            'custom'       => !!$row,
        ];
        $current = strtotime('+1 day', $current);
    }
    json_response(['days' => $out, 'defaults' => $defaults]);
}

if ($method === 'POST') {
    require_super_admin();
    $d = json_input();

    // Defaults update
    if (!empty($d['save_defaults'])) {
        $defaults = [
            'max_dortu'     => max(0, (int) ($d['max_dortu'] ?? 8)),
            'max_chlebicku' => max(0, (int) ($d['max_chlebicku'] ?? 200)),
            'max_zakousku'  => max(0, (int) ($d['max_zakousku'] ?? 80)),
        ];
        $pdo->prepare("
            INSERT INTO nastaveni (klic, hodnota) VALUES ('default_daily_capacity', :v)
            ON DUPLICATE KEY UPDATE hodnota = :v2
        ")->execute(['v' => json_encode($defaults), 'v2' => json_encode($defaults)]);
        json_response(['ok' => true, 'defaults' => $defaults]);
    }

    // Per-day update
    $date = $d['date'] ?? '';
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) json_error('Neplatný formát data', 400);
    try {
        $pdo->prepare("
            INSERT INTO daily_capacity (date, max_dortu, max_chlebicku, max_zakousku, poznamka, zavreno)
            VALUES (:d, :md, :mc, :mz, :p, :z)
            ON DUPLICATE KEY UPDATE
                max_dortu = :md2, max_chlebicku = :mc2, max_zakousku = :mz2, poznamka = :p2, zavreno = :z2
        ")->execute([
            'd'   => $date,
            'md'  => $d['max_dortu']     ?? null,
            'mc'  => $d['max_chlebicku'] ?? null,
            'mz'  => $d['max_zakousku']  ?? null,
            'p'   => $d['poznamka']      ?? null,
            'z'   => !empty($d['zavreno']) ? 1 : 0,
            'md2' => $d['max_dortu']     ?? null,
            'mc2' => $d['max_chlebicku'] ?? null,
            'mz2' => $d['max_zakousku']  ?? null,
            'p2'  => $d['poznamka']      ?? null,
            'z2'  => !empty($d['zavreno']) ? 1 : 0,
        ]);
        json_response(['ok' => true]);
    } catch (Throwable $e) {
        json_error_safe('DB', , 500);
    }
}

if ($method === 'DELETE') {
    require_super_admin();
    $date = $_GET['date'] ?? '';
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) json_error('Neplatný formát data', 400);
    $pdo->prepare("DELETE FROM daily_capacity WHERE date = :d")->execute(['d' => $date]);
    json_response(['ok' => true]);
}

json_error('Neznámá akce', 404);
