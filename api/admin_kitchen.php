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
        auto_fire TINYINT(1) NOT NULL DEFAULT 1,
        updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
");
$pdo->exec("INSERT IGNORE INTO kitchen_settings (id, max_paralelni_objednavky) VALUES (1, 8)");
// 🆕 v3.0.156 — auto_fire (posílat položky do kuchyně hned při přidání, vs ručně tlačítkem). Idempotentní pro existující instalace.
try { $pdo->exec("ALTER TABLE kitchen_settings ADD COLUMN IF NOT EXISTS auto_fire TINYINT(1) NOT NULL DEFAULT 1"); } catch (Throwable $e) {}

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
            auto_block = :ab,
            auto_fire = :af
        WHERE id = 1
    ")->execute([
        'm'  => (int) ($d['max_paralelni_objednavky'] ?? 8),
        'mp' => (int) ($d['max_min_priprava'] ?? 25),
        's'  => (int) ($d['slot_velikost_min'] ?? 15),
        'od' => $d['otevreno_od'] ?? null,
        'do' => $d['otevreno_do'] ?? null,
        'ab' => !empty($d['auto_block']) ? 1 : 0,
        'af' => isset($d['auto_fire']) ? (!empty($d['auto_fire']) ? 1 : 0) : 1,
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
    $src = $d['src'] ?? 'queue';
    if (!$id || !in_array($st, ['queued','preparing','ready','served','cancelled'], true)) {
        json_error('Neplatné parametry', 400);
    }
    // 🐛 fix v3.0.164 — kapacita kuchyně sdílí zdroj pravdy s KDS:
    //   • 'pos'   → dine-in položka v restaurant_pos_polozky (mapuj stav, sync s KDS)
    //   • 'queue' → rozvoz/s sebou v kitchen_queue (jen tam existuje)
    if ($src === 'pos') {
        $map = ['queued'=>'objednano','preparing'=>'vari_se','ready'=>'hotovo','served'=>'servirovano','cancelled'=>'storno'];
        $posStav = $map[$st];
        $timeCol = match($posStav) {
            'vari_se'     => 'cas_vari_se',
            'hotovo'      => 'cas_pripraveno',
            'servirovano' => 'cas_servirovano',
            default       => null,
        };
        $sql = "UPDATE restaurant_pos_polozky SET stav = :s" . ($timeCol ? ", $timeCol = NOW()" : "") . " WHERE id = :id";
        $pdo->prepare($sql)->execute(['s'=>$posStav,'id'=>$id]);
    } else {
        $setExtra = '';
        if ($st === 'preparing') $setExtra = ', cas_zacatek = NOW()';
        if ($st === 'ready')     $setExtra = ', cas_hotovo = NOW()';
        $pdo->prepare("UPDATE kitchen_queue SET stav=:s $setExtra WHERE id=:id")
            ->execute(['s'=>$st,'id'=>$id]);
    }
    json_response(['ok'=>true]);
}

// ────── DEFAULT GET: dashboard ──────
$settings = $pdo->query("SELECT * FROM kitchen_settings WHERE id=1")->fetch();
$stanice  = $pdo->query("SELECT * FROM kitchen_stations WHERE aktivni=1 ORDER BY poradi, id")->fetchAll();

// 🐛 fix v3.0.164 — fronta výroby = JEDEN zdroj pravdy:
//   • dine-in: živě z restaurant_pos_polozky (otevřené účty) — stejně jako KDS,
//     takže stav je vždy aktuální (dřív se kitchen_queue nesynchronizovala → stale data).
//     Stanici + dobu přípravy bereme z výrobku (vyrobky.kitchen_station_id / priprava_min).
//   • rozvoz / s sebou: z kitchen_queue (objednavka_id IS NOT NULL) — ty žijí jen tam.
$queue = $pdo->query("
    SELECT x.*, s.nazev AS station_nazev, s.ikona AS station_ikona, s.barva AS station_barva,
           TIMESTAMPDIFF(MINUTE, x.cas_ref, NOW()) AS minut_v_queue,
           IF(x.cas_zacatek IS NOT NULL, TIMESTAMPDIFF(MINUTE, x.cas_zacatek, NOW()), NULL) AS minut_pripravuje
    FROM (
        SELECT p.id AS id, 'pos' AS src,
               CASE p.stav WHEN 'objednano' THEN 'queued' WHEN 'vari_se' THEN 'preparing' WHEN 'hotovo' THEN 'ready' END AS stav,
               p.nazev AS vyrobek_nazev, p.mnozstvi, COALESCE(v.priprava_min, 10) AS priprava_min,
               v.kitchen_station_id AS station_id, p.ucet_id AS objednavka_id,
               p.cas_vari_se AS cas_zacatek, p.cas_objednavky AS cas_ref
        FROM restaurant_pos_polozky p
        INNER JOIN restaurant_pos_ucty u ON u.id = p.ucet_id AND u.stav = 'open'
        LEFT JOIN vyrobky v ON v.id = p.vyrobek_id
        WHERE p.stav IN ('objednano','vari_se','hotovo')
        UNION ALL
        SELECT k.id AS id, 'queue' AS src, k.stav,
               k.vyrobek_nazev, k.mnozstvi, k.priprava_min,
               k.station_id, k.objednavka_id,
               k.cas_zacatek, k.cas_pridani AS cas_ref
        FROM kitchen_queue k
        WHERE k.objednavka_id IS NOT NULL AND k.stav IN ('queued','preparing','ready')
    ) x
    LEFT JOIN kitchen_stations s ON s.id = x.station_id
    ORDER BY (x.stav = 'ready') ASC, x.cas_ref ASC
    LIMIT 100
")->fetchAll();

// Statistiky + zátěž stanic — odvozeno z $queue (žádné N+1 dotazy)
$stationLoad = [];
foreach ($stanice as $s) $stationLoad[(int)$s['id']] = ['queued'=>0,'preparing'=>0,'ready'=>0,'load_pct'=>0];
$activeKeys = []; $preparing = 0; $ready = 0;
foreach ($queue as $q) {
    if ($q['objednavka_id'] !== null) $activeKeys[$q['src'] . ':' . $q['objednavka_id']] = true;
    if ($q['stav'] === 'preparing') $preparing++;
    elseif ($q['stav'] === 'ready') $ready++;
    $sid = (int) $q['station_id'];
    if ($sid && isset($stationLoad[$sid][$q['stav']])) $stationLoad[$sid][$q['stav']]++;
}
$activeOrders = count($activeKeys);
foreach ($stanice as $s) {
    $sid  = (int) $s['id'];
    $busy = $stationLoad[$sid]['queued'] + $stationLoad[$sid]['preparing'];
    $stationLoad[$sid]['load_pct'] = $s['max_paralelni'] > 0 ? min(100, (int) round($busy / $s['max_paralelni'] * 100)) : 0;
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
