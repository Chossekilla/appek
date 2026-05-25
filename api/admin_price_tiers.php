<?php
/**
 * 👥 CENOVÉ ÚROVNĚ podle počtu osob — Catering balíček.
 *
 * Stupňovité ceny: do 20 osob X Kč/os, 21-50 Y Kč/os, 51-100 Z Kč/os…
 *
 *   GET                                → seznam tier sets + ukázky kalkulací
 *   GET ?action=set&id=N               → detail setu s úrovněmi
 *   POST ?action=set                   → create/update sady úrovní {id?, nazev, popis}
 *   DELETE ?action=set&id=N            → smazat sadu
 *   POST ?action=tier                  → CRUD tier {id?, set_id, od_osob, do_osob, cena_per_osobu, popis}
 *   DELETE ?action=tier&id=N           → smazat úroveň
 *   POST ?action=calc                  → spočítej cenu {set_id, osob}
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/_admin_auth.php';
require_once __DIR__ . '/_packages_lib.php';

cors_headers();
require_admin();
header('Content-Type: application/json; charset=UTF-8');

if (!package_enabled('catering') && !package_enabled('lahudky')) {
    http_response_code(402);
    json_response(['error' => 'Vyžaduje balíček 🎉 Catering nebo 🥗 Lahůdky']);
}

$pdo = db();

$pdo->exec("
    CREATE TABLE IF NOT EXISTS catering_price_tier_sets (
        id INT AUTO_INCREMENT PRIMARY KEY,
        nazev VARCHAR(150) NOT NULL,
        popis TEXT NULL,
        ikona VARCHAR(10) DEFAULT '👥',
        zaloha_pct DECIMAL(5,2) NOT NULL DEFAULT 50,
        aktivni TINYINT(1) NOT NULL DEFAULT 1,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
");

$pdo->exec("
    CREATE TABLE IF NOT EXISTS catering_price_tiers (
        id INT AUTO_INCREMENT PRIMARY KEY,
        set_id INT NOT NULL,
        od_osob INT NOT NULL,
        do_osob INT NULL,
        cena_per_osobu DECIMAL(10,2) NOT NULL,
        popis VARCHAR(200) NULL,
        poradi INT NOT NULL DEFAULT 0,
        INDEX idx_set (set_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
");

// Seed default sets
$cnt = (int) $pdo->query("SELECT COUNT(*) FROM catering_price_tier_sets")->fetchColumn();
if ($cnt === 0) {
    $pdo->exec("INSERT INTO catering_price_tier_sets (nazev, popis, ikona, zaloha_pct) VALUES
        ('Standard catering',     'Klasický catering — chlebíčky, jednohubky, mini koláčky.', '🍱', 50),
        ('Firemní raut',          'Premium nabídka — teplé pokrmy, obsluha.',                  '🍽️', 50),
        ('Svatba — gala',         'Svatební menu — předkrm, polévka, hlavní jídlo, zákusek.',  '💍', 50)
    ");
    $sets = $pdo->query("SELECT id, nazev FROM catering_price_tier_sets ORDER BY id")->fetchAll();
    foreach ($sets as $s) {
        $id = (int) $s['id'];
        if (strpos($s['nazev'], 'Standard') !== false) {
            $pdo->exec("INSERT INTO catering_price_tiers (set_id, od_osob, do_osob, cena_per_osobu, popis, poradi) VALUES
                ($id, 10, 20, 250, 'Malá skupina', 1),
                ($id, 21, 50, 220, 'Střední skupina', 2),
                ($id, 51, 100, 200, 'Velká skupina', 3),
                ($id, 101, NULL, 180, 'Hromadná akce — sleva', 4)
            ");
        } elseif (strpos($s['nazev'], 'raut') !== false) {
            $pdo->exec("INSERT INTO catering_price_tiers (set_id, od_osob, do_osob, cena_per_osobu, popis, poradi) VALUES
                ($id, 20, 50, 850, 'Menší firemní akce', 1),
                ($id, 51, 100, 750, 'Střední akce', 2),
                ($id, 101, 200, 690, 'Velká akce', 3),
                ($id, 201, NULL, 620, 'Mega akce', 4)
            ");
        } elseif (strpos($s['nazev'], 'Svatba') !== false) {
            $pdo->exec("INSERT INTO catering_price_tiers (set_id, od_osob, do_osob, cena_per_osobu, popis, poradi) VALUES
                ($id, 20, 50, 1290, '4-chodové menu', 1),
                ($id, 51, 100, 1190, '4-chodové menu', 2),
                ($id, 101, 200, 1090, '4-chodové menu — sleva', 3)
            ");
        }
    }
}

$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? '';
$id     = (int) ($_GET['id'] ?? 0);

if ($action === 'set' && $method === 'GET' && $id) {
    $set = $pdo->prepare("SELECT * FROM catering_price_tier_sets WHERE id=:id");
    $set->execute(['id'=>$id]);
    $s = $set->fetch();
    if (!$s) json_error('Set neexistuje', 404);
    $t = $pdo->prepare("SELECT * FROM catering_price_tiers WHERE set_id=:id ORDER BY od_osob");
    $t->execute(['id'=>$id]);
    $s['tiers'] = $t->fetchAll();
    json_response($s);
}

if ($action === 'set' && $method === 'POST') {
    $d = json_input();
    $id = (int) ($d['id'] ?? 0);
    $nazev = trim($d['nazev'] ?? '');
    if (!$nazev) json_error('Vyplň název', 400);
    if ($id) {
        $pdo->prepare("UPDATE catering_price_tier_sets SET nazev=:n, popis=:p, ikona=:i, zaloha_pct=:z, aktivni=:a WHERE id=:id")
            ->execute([
                'n'=>$nazev,'p'=>$d['popis'] ?? null,
                'i'=>$d['ikona'] ?? '👥',
                'z'=>(float)($d['zaloha_pct'] ?? 50),
                'a'=>isset($d['aktivni']) ? (int)!!$d['aktivni'] : 1,
                'id'=>$id,
            ]);
    } else {
        $pdo->prepare("INSERT INTO catering_price_tier_sets (nazev, popis, ikona, zaloha_pct) VALUES (:n,:p,:i,:z)")
            ->execute([
                'n'=>$nazev,'p'=>$d['popis'] ?? null,
                'i'=>$d['ikona'] ?? '👥',
                'z'=>(float)($d['zaloha_pct'] ?? 50),
            ]);
        $id = (int) $pdo->lastInsertId();
    }
    json_response(['ok'=>true, 'id'=>$id]);
}

if ($action === 'set' && $method === 'DELETE' && $id) {
    $pdo->prepare("DELETE FROM catering_price_tiers WHERE set_id=:id")->execute(['id'=>$id]);
    $pdo->prepare("DELETE FROM catering_price_tier_sets WHERE id=:id")->execute(['id'=>$id]);
    json_response(['ok'=>true]);
}

if ($action === 'tier' && $method === 'POST') {
    $d = json_input();
    $tid = (int) ($d['id'] ?? 0);
    if (!($d['set_id'] ?? 0)) json_error('Chybí set_id', 400);
    $params = [
        's'  => (int)$d['set_id'],
        'od' => (int)($d['od_osob'] ?? 0),
        'do' => isset($d['do_osob']) && $d['do_osob'] ? (int)$d['do_osob'] : null,
        'c'  => (float)($d['cena_per_osobu'] ?? 0),
        'p'  => $d['popis'] ?? null,
        'po' => (int)($d['poradi'] ?? 0),
    ];
    if ($tid) {
        $params['id'] = $tid;
        $pdo->prepare("UPDATE catering_price_tiers SET set_id=:s, od_osob=:od, do_osob=:do, cena_per_osobu=:c, popis=:p, poradi=:po WHERE id=:id")->execute($params);
    } else {
        $pdo->prepare("INSERT INTO catering_price_tiers (set_id, od_osob, do_osob, cena_per_osobu, popis, poradi) VALUES (:s,:od,:do,:c,:p,:po)")->execute($params);
        $tid = (int) $pdo->lastInsertId();
    }
    json_response(['ok'=>true,'id'=>$tid]);
}

if ($action === 'tier' && $method === 'DELETE' && $id) {
    $pdo->prepare("DELETE FROM catering_price_tiers WHERE id=:id")->execute(['id'=>$id]);
    json_response(['ok'=>true]);
}

if ($action === 'calc' && $method === 'POST') {
    $d = json_input();
    $setId = (int) ($d['set_id'] ?? 0);
    $osob  = max(1, (int) ($d['osob'] ?? 0));
    if (!$setId || !$osob) json_error('Chybí set_id nebo osob', 400);

    $stmt = $pdo->prepare("
        SELECT * FROM catering_price_tiers
        WHERE set_id=:s AND od_osob <= :o AND (do_osob IS NULL OR do_osob >= :o2)
        ORDER BY od_osob DESC LIMIT 1
    ");
    $stmt->execute(['s'=>$setId, 'o'=>$osob, 'o2'=>$osob]);
    $tier = $stmt->fetch();
    if (!$tier) json_error('Pro tento počet osob není definovaná úroveň', 404);

    $set = $pdo->prepare("SELECT * FROM catering_price_tier_sets WHERE id=:id");
    $set->execute(['id'=>$setId]);
    $s = $set->fetch();

    $cenaPerOsobu = (float) $tier['cena_per_osobu'];
    $celkem = $cenaPerOsobu * $osob;
    $zalohaPct = (float) ($s['zaloha_pct'] ?? 50);
    $zaloha = round($celkem * $zalohaPct / 100, 2);
    $doplatek = $celkem - $zaloha;

    json_response([
        'set'             => $s,
        'tier'            => $tier,
        'osob'            => $osob,
        'cena_per_osobu'  => $cenaPerOsobu,
        'cena_celkem'     => $celkem,
        'zaloha_pct'      => $zalohaPct,
        'zaloha_kc'       => $zaloha,
        'doplatek_kc'     => $doplatek,
    ]);
}

// GET (default) — všechny sety s tiers
$sets = $pdo->query("SELECT * FROM catering_price_tier_sets ORDER BY aktivni DESC, nazev")->fetchAll();
foreach ($sets as &$s) {
    $t = $pdo->prepare("SELECT * FROM catering_price_tiers WHERE set_id=:id ORDER BY od_osob");
    $t->execute(['id'=>$s['id']]);
    $s['tiers'] = $t->fetchAll();
}
unset($s);
json_response(['sets' => $sets]);
