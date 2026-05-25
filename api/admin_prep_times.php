<?php
/**
 * ⏱️ DOBA PŘÍPRAVY PER VÝROBEK — Restaurace balíček.
 *
 * Per-produkt: kolik minut trvá příprava, na které stanici se vaří.
 * Sčítá se pro celou objednávku — orientační SLA.
 *
 *   GET                       → seznam výrobků s prep_min + stanice
 *   POST                      → uložit per-produkt: {vyrobek_id, priprava_min, station_id}
 *   POST ?action=bulk         → bulk update více najednou
 *   POST ?action=sum_order    → spočítá doba přípravy pro celou objednávku {order_id}
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/_admin_auth.php';
require_once __DIR__ . '/_packages_lib.php';

cors_headers();
require_admin();
header('Content-Type: application/json; charset=UTF-8');

if (!package_enabled('restaurace')) {
    http_response_code(402);
    json_response(['error' => 'Vyžaduje balíček 🍕 Restaurace']);
}

$pdo = db();
// Auto-migrace sloupců ve vyrobky
try {
    $cols = $pdo->query("SHOW COLUMNS FROM vyrobky")->fetchAll(PDO::FETCH_COLUMN);
    if (!in_array('priprava_min', $cols, true)) {
        // Bez AFTER (pokud cena_s_dph neexistuje, ALTER by selhal)
        $pdo->exec("ALTER TABLE vyrobky ADD COLUMN priprava_min INT NOT NULL DEFAULT 10");
    }
    if (!in_array('kitchen_station_id', $cols, true)) {
        $pdo->exec("ALTER TABLE vyrobky ADD COLUMN kitchen_station_id INT NULL");
    }
} catch (Throwable $e) { /* ignore */ }

// 🔧 BUGFIX: Auto-vytvoř kitchen_stations pokud uživatel otevřel Doba přípravy před Kapacitou kuchyně
$pdo->exec("
    CREATE TABLE IF NOT EXISTS kitchen_stations (
        id INT AUTO_INCREMENT PRIMARY KEY,
        nazev VARCHAR(100) NOT NULL,
        ikona VARCHAR(10) NOT NULL DEFAULT '🔥',
        max_paralelni INT NOT NULL DEFAULT 4,
        aktivni TINYINT(1) NOT NULL DEFAULT 1,
        poradi INT NOT NULL DEFAULT 0,
        barva VARCHAR(20) DEFAULT '#F59E0B'
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
");
$cnt = (int) $pdo->query("SELECT COUNT(*) FROM kitchen_stations")->fetchColumn();
if ($cnt === 0) {
    $pdo->exec("INSERT INTO kitchen_stations (nazev, ikona, max_paralelni, poradi, barva) VALUES
        ('Pec / pizza', '🔥', 4, 1, '#EF4444'),
        ('Studená kuchyně', '🥗', 3, 2, '#10B981'),
        ('Gril', '🍖', 2, 3, '#F97316'),
        ('Bar / nápoje', '🍹', 6, 4, '#3B82F6')
    ");
}

$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? '';

if ($action === 'sum_order' && $method === 'POST') {
    $d = json_input();
    $id = (int) ($d['order_id'] ?? 0);
    if (!$id) json_error('Chybí order_id', 400);
    try {
        $stmt = $pdo->prepare("
            SELECT op.vyrobek_id, op.vyrobek_nazev, op.mnozstvi,
                v.priprava_min, v.kitchen_station_id
            FROM objednavky_polozky op
            LEFT JOIN vyrobky v ON v.id = op.vyrobek_id
            WHERE op.objednavka_id = :id
        ");
        $stmt->execute(['id'=>$id]);
        $polozky = $stmt->fetchAll();
        $stations = [];
        $totalSekvencne = 0;
        foreach ($polozky as $p) {
            $min = (int) ($p['priprava_min'] ?? 10);
            $totalSekvencne += $min * max(1, (int)$p['mnozstvi']);
            $st = (int) ($p['kitchen_station_id'] ?? 0);
            $stations[$st] = ($stations[$st] ?? 0) + $min * max(1, (int)$p['mnozstvi']);
        }
        // Paralelní: nejdelší stanice
        $totalParalelni = !empty($stations) ? max($stations) : 0;
        json_response([
            'polozky' => $polozky,
            'doba_min_sekvencne' => $totalSekvencne,
            'doba_min_paralelne' => $totalParalelni,
            'odhad_min' => $totalParalelni > 0 ? $totalParalelni : $totalSekvencne,
            'stanice_load' => $stations,
        ]);
    } catch (Throwable $e) { json_error_safe('DB', , 500); }
}

if ($action === 'bulk' && $method === 'POST') {
    $d = json_input();
    $updates = $d['updates'] ?? [];
    if (!is_array($updates)) json_error('updates musí být pole', 400);
    try {
        $pdo->beginTransaction();
        $stmt = $pdo->prepare("UPDATE vyrobky SET priprava_min=:p, kitchen_station_id=:s WHERE id=:id");
        foreach ($updates as $u) {
            $id = (int)($u['id'] ?? 0);
            if (!$id) continue;
            $stmt->execute([
                'p' => (int)($u['priprava_min'] ?? 10),
                's' => isset($u['station_id']) ? ((int)$u['station_id'] ?: null) : null,
                'id' => $id,
            ]);
        }
        $pdo->commit();
        json_response(['ok'=>true, 'count'=>count($updates)]);
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        json_error_safe('DB', , 500);
    }
}

if ($method === 'POST') {
    $d = json_input();
    $id = (int)($d['vyrobek_id'] ?? 0);
    if (!$id) json_error('Chybí vyrobek_id', 400);
    try {
        $pdo->prepare("UPDATE vyrobky SET priprava_min=:p, kitchen_station_id=:s WHERE id=:id")
            ->execute([
                'p' => (int)($d['priprava_min'] ?? 10),
                's' => isset($d['station_id']) ? ((int)$d['station_id'] ?: null) : null,
                'id' => $id,
            ]);
        json_response(['ok'=>true]);
    } catch (Throwable $e) { json_error_safe('DB', , 500); }
}

// GET — seznam výrobků s prep dobou + stanice (pro UI)
try {
    $stanice = [];
    try {
        $stanice = $pdo->query("SELECT id, nazev, ikona, barva FROM kitchen_stations WHERE aktivni=1 ORDER BY poradi")->fetchAll();
    } catch (Throwable $e) {}

    // Detekuj zda kategorie_vyrobku tabulka existuje
    $hasKat = false;
    try {
        $pdo->query("SELECT 1 FROM kategorie_vyrobku LIMIT 1");
        $hasKat = true;
    } catch (Throwable $e) {}

    $sql = "
        SELECT v.id, v.cislo, v.nazev, v.kategorie_id, v.priprava_min, v.kitchen_station_id,
               " . ($hasKat ? "k.nazev AS kategorie_nazev," : "NULL AS kategorie_nazev,") . "
               s.nazev AS station_nazev, s.ikona AS station_ikona, s.barva AS station_barva
        FROM vyrobky v
        " . ($hasKat ? "LEFT JOIN kategorie_vyrobku k ON k.id = v.kategorie_id" : "") . "
        LEFT JOIN kitchen_stations s ON s.id = v.kitchen_station_id
        ORDER BY " . ($hasKat ? "k.nazev," : "") . " v.nazev
    ";
    $vyrobky = $pdo->query($sql)->fetchAll();

    json_response([
        'vyrobky' => $vyrobky,
        'stanice' => $stanice,
        'stats' => [
            'total'    => count($vyrobky),
            's_dobou'  => count(array_filter($vyrobky, fn($v) => (int)$v['priprava_min'] > 0)),
            'avg_min'  => count($vyrobky) ? round(array_sum(array_map(fn($v)=>(int)$v['priprava_min'], $vyrobky)) / count($vyrobky), 1) : 0,
        ],
    ]);
} catch (Throwable $e) {
    json_error_safe('DB chyba (admin_prep_times)', , 500);
}
