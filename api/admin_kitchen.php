<?php
/**
 * 👨‍🍳 KAPACITA KUCHYNĚ — Restaurace balíček.
 *
 *   GET                          → nastavení + aktivní objednávky + statistika
 *   POST ?action=settings        → uložit nastavení (max paralelní, sloty)
 *   POST ?action=station         → vytvořit/upravit stanici
 *   DELETE ?action=station&id=N  → smazat stanici
 *   POST ?action=order_status    → změnit status položky v queue (preparing/ready/served)
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
$pdo->exec("
    CREATE TABLE IF NOT EXISTS kitchen_settings (
        id INT PRIMARY KEY DEFAULT 1,
        max_paralelni_objednavky INT NOT NULL DEFAULT 8,
        max_min_priprava INT NOT NULL DEFAULT 25,
        slot_velikost_min INT NOT NULL DEFAULT 15,
        otevreno_od TIME NULL,
        otevreno_do TIME NULL,
        auto_block TINYINT(1) NOT NULL DEFAULT 1,
        updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
");
$pdo->exec("INSERT IGNORE INTO kitchen_settings (id, max_paralelni_objednavky) VALUES (1, 8)");

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

// Seed default stanice
$cnt = (int) $pdo->query("SELECT COUNT(*) FROM kitchen_stations")->fetchColumn();
if ($cnt === 0) {
    $pdo->exec("INSERT INTO kitchen_stations (nazev, ikona, max_paralelni, poradi, barva) VALUES
        ('Pec / pizza', '🔥', 4, 1, '#EF4444'),
        ('Studená kuchyně', '🥗', 3, 2, '#10B981'),
        ('Gril', '🍖', 2, 3, '#F97316'),
        ('Bar / nápoje', '🍹', 6, 4, '#3B82F6')
    ");
}

// Queue: items v jednotlivých stanicích
$pdo->exec("
    CREATE TABLE IF NOT EXISTS kitchen_queue (
        id INT AUTO_INCREMENT PRIMARY KEY,
        objednavka_id INT NULL,
        polozka_id INT NULL,
        station_id INT NULL,
        vyrobek_nazev VARCHAR(200) NOT NULL,
        mnozstvi DECIMAL(10,3) NOT NULL DEFAULT 1,
        priprava_min INT NOT NULL DEFAULT 10,
        stav ENUM('queued','preparing','ready','served','cancelled') NOT NULL DEFAULT 'queued',
        cas_pridani DATETIME DEFAULT CURRENT_TIMESTAMP,
        cas_zacatek DATETIME NULL,
        cas_hotovo DATETIME NULL,
        priorita INT NOT NULL DEFAULT 0,
        poznamka TEXT NULL,
        INDEX idx_stav (stav),
        INDEX idx_obj (objednavka_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
");

$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? '';

if ($action === 'settings' && $method === 'POST') {
    $d = json_input();
    $pdo->prepare("
        UPDATE kitchen_settings SET
            max_paralelni_objednavky = :m,
            max_min_priprava = :mp,
            slot_velikost_min = :s,
            otevreno_od = :od,
            otevreno_do = :do,
            auto_block = :ab
        WHERE id = 1
    ")->execute([
        'm'  => (int) ($d['max_paralelni_objednavky'] ?? 8),
        'mp' => (int) ($d['max_min_priprava'] ?? 25),
        's'  => (int) ($d['slot_velikost_min'] ?? 15),
        'od' => $d['otevreno_od'] ?? null,
        'do' => $d['otevreno_do'] ?? null,
        'ab' => !empty($d['auto_block']) ? 1 : 0,
    ]);
    json_response(['ok'=>true]);
}

if ($action === 'station' && $method === 'POST') {
    $d  = json_input();
    $id = (int) ($d['id'] ?? 0);
    $nazev = trim($d['nazev'] ?? '');
    if (!$nazev) json_error('Vyplň název', 400);
    if ($id) {
        $pdo->prepare("UPDATE kitchen_stations SET nazev=:n, ikona=:i, max_paralelni=:m, barva=:b, poradi=:p, aktivni=:a WHERE id=:id")
            ->execute([
                'n'=>$nazev,'i'=>$d['ikona'] ?? '🔥',
                'm'=>(int)($d['max_paralelni'] ?? 4),
                'b'=>$d['barva'] ?? '#F59E0B',
                'p'=>(int)($d['poradi'] ?? 0),
                'a'=>isset($d['aktivni']) ? (int)!!$d['aktivni'] : 1,
                'id'=>$id,
            ]);
    } else {
        $pdo->prepare("INSERT INTO kitchen_stations (nazev, ikona, max_paralelni, barva, poradi) VALUES (:n,:i,:m,:b,:p)")
            ->execute([
                'n'=>$nazev,'i'=>$d['ikona'] ?? '🔥',
                'm'=>(int)($d['max_paralelni'] ?? 4),
                'b'=>$d['barva'] ?? '#F59E0B',
                'p'=>(int)($d['poradi'] ?? 0),
            ]);
        $id = (int) $pdo->lastInsertId();
    }
    json_response(['ok'=>true,'id'=>$id]);
}

if ($action === 'station' && $method === 'DELETE') {
    $id = (int) ($_GET['id'] ?? 0);
    if (!$id) json_error('Chybí id', 400);
    $pdo->prepare("UPDATE kitchen_queue SET station_id = NULL WHERE station_id=:id")->execute(['id'=>$id]);
    $pdo->prepare("DELETE FROM kitchen_stations WHERE id=:id")->execute(['id'=>$id]);
    json_response(['ok'=>true]);
}

if ($action === 'order_status' && $method === 'POST') {
    $d = json_input();
    $id  = (int) ($d['id'] ?? 0);
    $st  = $d['stav'] ?? '';
    if (!$id || !in_array($st, ['queued','preparing','ready','served','cancelled'], true)) {
        json_error('Neplatné parametry', 400);
    }
    $setExtra = '';
    if ($st === 'preparing') $setExtra = ', cas_zacatek = NOW()';
    if ($st === 'ready')     $setExtra = ', cas_hotovo = NOW()';
    $pdo->prepare("UPDATE kitchen_queue SET stav=:s $setExtra WHERE id=:id")
        ->execute(['s'=>$st,'id'=>$id]);
    json_response(['ok'=>true]);
}

// ────── DEFAULT GET: dashboard ──────
$settings = $pdo->query("SELECT * FROM kitchen_settings WHERE id=1")->fetch();
$stanice  = $pdo->query("SELECT * FROM kitchen_stations WHERE aktivni=1 ORDER BY poradi, id")->fetchAll();

// Aktivní queue items
$queue = $pdo->query("
    SELECT q.*, s.nazev AS station_nazev, s.ikona AS station_ikona, s.barva AS station_barva,
        TIMESTAMPDIFF(MINUTE, q.cas_pridani, NOW()) AS minut_v_queue,
        IF(q.cas_zacatek IS NOT NULL, TIMESTAMPDIFF(MINUTE, q.cas_zacatek, NOW()), NULL) AS minut_pripravuje
    FROM kitchen_queue q
    LEFT JOIN kitchen_stations s ON s.id = q.station_id
    WHERE q.stav IN ('queued','preparing','ready')
    ORDER BY q.priorita DESC, q.cas_pridani ASC
    LIMIT 100
")->fetchAll();

// Active orders count (z queue, distinct objednavka_id)
$activeOrders = $pdo->query("SELECT COUNT(DISTINCT objednavka_id) FROM kitchen_queue WHERE stav IN ('queued','preparing','ready') AND objednavka_id IS NOT NULL")->fetchColumn();
$preparing = $pdo->query("SELECT COUNT(*) FROM kitchen_queue WHERE stav='preparing'")->fetchColumn();
$ready     = $pdo->query("SELECT COUNT(*) FROM kitchen_queue WHERE stav='ready'")->fetchColumn();

// Per-station load
$stationLoad = [];
foreach ($stanice as $s) {
    $cnt = (int) $pdo->query("SELECT COUNT(*) FROM kitchen_queue WHERE station_id={$s['id']} AND stav IN ('queued','preparing')")->fetchColumn();
    $stationLoad[(int)$s['id']] = [
        'queued'     => (int) $pdo->query("SELECT COUNT(*) FROM kitchen_queue WHERE station_id={$s['id']} AND stav='queued'")->fetchColumn(),
        'preparing'  => (int) $pdo->query("SELECT COUNT(*) FROM kitchen_queue WHERE station_id={$s['id']} AND stav='preparing'")->fetchColumn(),
        'ready'      => (int) $pdo->query("SELECT COUNT(*) FROM kitchen_queue WHERE station_id={$s['id']} AND stav='ready'")->fetchColumn(),
        'load_pct'   => $s['max_paralelni'] > 0 ? min(100, round($cnt / $s['max_paralelni'] * 100)) : 0,
    ];
}

$globalLoad = $settings['max_paralelni_objednavky'] > 0
    ? min(100, round(((int)$activeOrders) / (int)$settings['max_paralelni_objednavky'] * 100))
    : 0;

json_response([
    'settings'      => $settings,
    'stanice'       => $stanice,
    'station_load'  => $stationLoad,
    'queue'         => $queue,
    'stats' => [
        'active_orders' => (int) $activeOrders,
        'preparing'     => (int) $preparing,
        'ready'         => (int) $ready,
        'global_load'   => $globalLoad,
        'is_full'       => $globalLoad >= 100,
    ],
]);
