<?php
/**
 * 📋 ŠARŽOVÁ HACCP EVIDENCE — Lahůdky balíček.
 *
 * Každá várka výroby = vlastní šarže s teplotou skladu + DMT pro audit.
 *
 *   GET                            → seznam šarží + stats
 *   GET ?id=N                      → detail šarže + kontroly
 *   POST                           → vytvořit šarži
 *   PUT ?id=N                      → upravit
 *   DELETE ?id=N                   → smazat
 *   POST ?action=check&id=N        → záznam kontroly (teplota apod.)
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/_admin_auth.php';
require_once __DIR__ . '/_packages_lib.php';

cors_headers();
require_admin();
header('Content-Type: application/json; charset=UTF-8');

if (!package_enabled('lahudky')) {
    http_response_code(402);
    json_response(['error' => 'Vyžaduje balíček 🥗 Lahůdky']);
}

$pdo = db();
$pdo->exec("
    CREATE TABLE IF NOT EXISTS production_batches (
        id INT AUTO_INCREMENT PRIMARY KEY,
        sarze_kod VARCHAR(40) UNIQUE NOT NULL,
        vyrobek_id INT NULL,
        vyrobek_nazev VARCHAR(200) NOT NULL,
        datum_vyroby DATE NOT NULL,
        cas_vyroby TIME NULL,
        mnozstvi DECIMAL(12,3) NOT NULL DEFAULT 0,
        jednotka VARCHAR(10) NOT NULL DEFAULT 'ks',
        dmt DATE NOT NULL,
        teplota_skladu DECIMAL(5,2) NULL,
        teplota_min DECIMAL(5,2) NULL,
        teplota_max DECIMAL(5,2) NULL,
        sklad_misto VARCHAR(100) NULL,
        operator VARCHAR(100) NULL,
        stav ENUM('vyrabi_se','sklad','prodej','expirovano','staženo') NOT NULL DEFAULT 'sklad',
        poznamka TEXT NULL,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        INDEX idx_dmt (dmt),
        INDEX idx_stav (stav),
        INDEX idx_datum (datum_vyroby)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
");
$pdo->exec("
    CREATE TABLE IF NOT EXISTS production_batch_checks (
        id INT AUTO_INCREMENT PRIMARY KEY,
        batch_id INT NOT NULL,
        cas DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        typ ENUM('teplota','vizual','prijem','expirace','jiny') NOT NULL DEFAULT 'teplota',
        hodnota VARCHAR(50) NULL,
        operator VARCHAR(100) NULL,
        v_normě TINYINT(1) NOT NULL DEFAULT 1,
        poznamka TEXT NULL,
        INDEX idx_batch (batch_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
");

// Auto-update expirovaných
$pdo->exec("UPDATE production_batches SET stav='expirovano' WHERE dmt < CURDATE() AND stav NOT IN ('expirovano','staženo')");

$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? '';
$id     = (int) ($_GET['id'] ?? 0);

if ($action === 'check' && $method === 'POST' && $id) {
    $d = json_input();
    try {
        $pdo->prepare("
            INSERT INTO production_batch_checks (batch_id, typ, hodnota, operator, v_normě, poznamka)
            VALUES (:b,:t,:h,:o,:n,:p)
        ")->execute([
            'b'=>$id,'t'=>$d['typ'] ?? 'teplota',
            'h'=>$d['hodnota'] ?? null,'o'=>$d['operator'] ?? null,
            'n'=>!empty($d['v_normě']) ? 1 : 0,
            'p'=>$d['poznamka'] ?? null,
        ]);
        // Update aktuální teplotu na šarži (jen pro typ=teplota)
        if (($d['typ'] ?? 'teplota') === 'teplota' && isset($d['hodnota'])) {
            $pdo->prepare("UPDATE production_batches SET teplota_skladu = :t WHERE id=:id")
                ->execute(['t'=>(float)$d['hodnota'],'id'=>$id]);
        }
        json_response(['ok'=>true]);
    } catch (Throwable $e) { json_error_safe('DB', , 500); }
}

if ($method === 'GET' && $id) {
    $stmt = $pdo->prepare("SELECT * FROM production_batches WHERE id = :id");
    $stmt->execute(['id'=>$id]);
    $row = $stmt->fetch();
    if (!$row) json_error('Neexistuje', 404);
    $chk = $pdo->prepare("SELECT * FROM production_batch_checks WHERE batch_id=:id ORDER BY cas DESC");
    $chk->execute(['id'=>$id]);
    $row['kontroly'] = $chk->fetchAll();
    json_response($row);
}

if ($method === 'GET') {
    $filter = $_GET['filter'] ?? 'aktivni';
    $where = '1=1';
    if ($filter === 'aktivni')    $where = "stav IN ('vyrabi_se','sklad','prodej')";
    if ($filter === 'expirujici') $where = "stav IN ('sklad','prodej') AND dmt <= DATE_ADD(CURDATE(), INTERVAL 3 DAY)";
    if ($filter === 'expirovane') $where = "stav = 'expirovano'";
    if ($filter === 'vse')        $where = '1=1';

    $rows = $pdo->query("
        SELECT b.*,
            DATEDIFF(b.dmt, CURDATE()) AS dnu_do_dmt,
            (SELECT COUNT(*) FROM production_batch_checks WHERE batch_id=b.id) AS kontrol_celkem,
            (SELECT MAX(cas) FROM production_batch_checks WHERE batch_id=b.id) AS posledni_kontrola
        FROM production_batches b
        WHERE $where
        ORDER BY b.dmt ASC, b.id DESC LIMIT 300
    ")->fetchAll();

    $stats = [
        'total'      => (int) $pdo->query("SELECT COUNT(*) FROM production_batches")->fetchColumn(),
        'aktivni'    => (int) $pdo->query("SELECT COUNT(*) FROM production_batches WHERE stav IN ('sklad','prodej','vyrabi_se')")->fetchColumn(),
        'expirujici' => (int) $pdo->query("SELECT COUNT(*) FROM production_batches WHERE stav IN ('sklad','prodej') AND dmt <= DATE_ADD(CURDATE(), INTERVAL 3 DAY)")->fetchColumn(),
        'expirovane' => (int) $pdo->query("SELECT COUNT(*) FROM production_batches WHERE stav='expirovano'")->fetchColumn(),
    ];
    json_response(['batches'=>$rows, 'stats'=>$stats, 'filter'=>$filter]);
}

if ($method === 'POST') {
    $d = json_input();
    $kod = trim($d['sarze_kod'] ?? '');
    if (!$kod) {
        // Auto-generuj kód: YYMMDD-NNN
        $prefix = date('ymd');
        $cnt = (int) $pdo->query("SELECT COUNT(*)+1 FROM production_batches WHERE sarze_kod LIKE '$prefix-%'")->fetchColumn();
        $kod = $prefix . '-' . str_pad((string)$cnt, 3, '0', STR_PAD_LEFT);
    }
    if (!($d['vyrobek_nazev'] ?? '') || !($d['datum_vyroby'] ?? '') || !($d['dmt'] ?? '')) {
        json_error('Vyplň výrobek, datum výroby a DMT', 400);
    }
    try {
        $pdo->prepare("
            INSERT INTO production_batches
            (sarze_kod, vyrobek_id, vyrobek_nazev, datum_vyroby, cas_vyroby, mnozstvi, jednotka,
             dmt, teplota_min, teplota_max, sklad_misto, operator, stav, poznamka)
            VALUES (:k,:vi,:vn,:dv,:cv,:mn,:j,:dmt,:tmin,:tmax,:sm,:op,:st,:p)
        ")->execute([
            'k'=>$kod, 'vi'=>$d['vyrobek_id'] ?? null,
            'vn'=>$d['vyrobek_nazev'], 'dv'=>$d['datum_vyroby'],
            'cv'=>$d['cas_vyroby'] ?? null, 'mn'=>$d['mnozstvi'] ?? 0,
            'j'=>$d['jednotka'] ?? 'ks', 'dmt'=>$d['dmt'],
            'tmin'=>$d['teplota_min'] ?? null, 'tmax'=>$d['teplota_max'] ?? null,
            'sm'=>$d['sklad_misto'] ?? null, 'op'=>$d['operator'] ?? null,
            'st'=>$d['stav'] ?? 'sklad', 'p'=>$d['poznamka'] ?? null,
        ]);
        json_response(['ok'=>true, 'id'=>(int)$pdo->lastInsertId(), 'sarze_kod'=>$kod]);
    } catch (Throwable $e) { json_error_safe('DB', , 500); }
}

if ($method === 'PUT' && $id) {
    $d = json_input();
    $allowed = ['vyrobek_nazev','mnozstvi','jednotka','dmt','teplota_skladu','teplota_min','teplota_max',
                'sklad_misto','operator','stav','poznamka','cas_vyroby','datum_vyroby'];
    $sets = []; $params = ['id'=>$id];
    foreach ($allowed as $f) {
        if (array_key_exists($f, $d)) { $sets[] = "$f = :$f"; $params[$f] = $d[$f]; }
    }
    if (!$sets) json_error('Nic ke změně', 400);
    try {
        $pdo->prepare("UPDATE production_batches SET " . implode(', ', $sets) . " WHERE id=:id")->execute($params);
        json_response(['ok'=>true]);
    } catch (Throwable $e) { json_error_safe('DB', , 500); }
}

if ($method === 'DELETE' && $id) {
    $pdo->prepare("DELETE FROM production_batch_checks WHERE batch_id=:id")->execute(['id'=>$id]);
    $pdo->prepare("DELETE FROM production_batches WHERE id=:id")->execute(['id'=>$id]);
    json_response(['ok'=>true]);
}

json_error('Neznámá akce', 404);
