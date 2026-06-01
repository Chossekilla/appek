<?php
/**
 * 🛵 VLASTNÍ ROZVOZ + KURÝRKY — Restaurace balíček.
 *
 * Eviduje vlastní řidiče + externí kurýrní služby (Wolt/Bolt/Dáme jídlo).
 * Tracking aktivních rozvozů s GPS/telefon (zatím manuální).
 *
 *   GET                              → seznam kurýrů + aktivní rozvozy + integrace
 *   POST   ?action=courier           → CRUD kurýr (vlastní řidič)
 *   DELETE ?action=courier&id=N      → smazat
 *   POST   ?action=delivery          → vytvořit rozvoz (přidá objednávce/DL kurýra)
 *   POST   ?action=delivery_status   → změnit stav rozvozu
 *   POST   ?action=integration       → uložit nastavení integrace s externí službou
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/_admin_auth.php';
require_once __DIR__ . '/_packages_lib.php';
require_once __DIR__ . '/_delivery_aggregators.php'; // 🆕 v3.0.38 — Wolt/Bolt/Dáme jídlo/Foodora live integrace

cors_headers();
require_admin();
header('Content-Type: application/json; charset=UTF-8');

if (!package_enabled('restaurace')) {
    http_response_code(402);
    json_response(['error' => 'Vyžaduje balíček 🍕 Restaurace']);
}

$pdo = db();
$pdo->exec("
    CREATE TABLE IF NOT EXISTS couriers (
        id INT AUTO_INCREMENT PRIMARY KEY,
        jmeno VARCHAR(150) NOT NULL,
        telefon VARCHAR(50) NULL,
        email VARCHAR(150) NULL,
        vozidlo VARCHAR(100) NULL,
        spz VARCHAR(20) NULL,
        zona_obslazi VARCHAR(200) NULL,
        provize_pct DECIMAL(5,2) NOT NULL DEFAULT 0,
        externi TINYINT(1) NOT NULL DEFAULT 0,
        externi_sluzba ENUM('wolt','bolt','dame_jidlo','foodora','vlastni','jiny') NOT NULL DEFAULT 'vlastni',
        aktivni TINYINT(1) NOT NULL DEFAULT 1,
        barva VARCHAR(20) DEFAULT '#10B981',
        ikona VARCHAR(10) DEFAULT '🛵',
        poznamka TEXT NULL,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_aktivni (aktivni)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
");

$pdo->exec("
    CREATE TABLE IF NOT EXISTS courier_deliveries (
        id INT AUTO_INCREMENT PRIMARY KEY,
        courier_id INT NOT NULL,
        objednavka_id INT NULL,
        dl_id INT NULL,
        adresa VARCHAR(300) NOT NULL,
        psc VARCHAR(10) NULL,
        mesto VARCHAR(100) NULL,
        kontakt_jmeno VARCHAR(150) NULL,
        kontakt_telefon VARCHAR(50) NULL,
        cas_naloženo DATETIME NULL,
        cas_doruceno DATETIME NULL,
        cas_planovany TIME NULL,
        stav ENUM('naplanovano','vyzvednuto','na_ceste','doruceno','zruseno') NOT NULL DEFAULT 'naplanovano',
        cena_kc DECIMAL(10,2) NOT NULL DEFAULT 0,
        provize_kc DECIMAL(10,2) NOT NULL DEFAULT 0,
        poznamka TEXT NULL,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_courier (courier_id),
        INDEX idx_stav (stav)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
");

$pdo->exec("
    CREATE TABLE IF NOT EXISTS courier_integrations (
        sluzba VARCHAR(20) PRIMARY KEY,
        povolena TINYINT(1) NOT NULL DEFAULT 0,
        api_key VARCHAR(255) NULL,
        store_id VARCHAR(100) NULL,
        provize_pct DECIMAL(5,2) NOT NULL DEFAULT 0,
        nastaveni JSON NULL,
        updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
");

// Seed defaultní integrace
$cnt = (int) $pdo->query("SELECT COUNT(*) FROM courier_integrations")->fetchColumn();
if ($cnt === 0) {
    $pdo->exec("INSERT INTO courier_integrations (sluzba, provize_pct) VALUES
        ('wolt', 30),
        ('bolt', 28),
        ('dame_jidlo', 25),
        ('foodora', 30)
    ");
}

$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? '';

if ($action === 'courier' && $method === 'POST') {
    $d = json_input();
    $id = (int) ($d['id'] ?? 0);
    if (!trim($d['jmeno'] ?? '')) json_error('Vyplň jméno', 400);
    $params = [
        'j'=>$d['jmeno'], 't'=>$d['telefon'] ?? null, 'e'=>$d['email'] ?? null,
        'v'=>$d['vozidlo'] ?? null, 's'=>$d['spz'] ?? null,
        'z'=>$d['zona_obslazi'] ?? null,
        'p'=>(float)($d['provize_pct'] ?? 0),
        'ex'=>!empty($d['externi']) ? 1 : 0,
        'es'=>$d['externi_sluzba'] ?? 'vlastni',
        'a'=>isset($d['aktivni']) ? (int)!!$d['aktivni'] : 1,
        'b'=>$d['barva'] ?? '#10B981',
        'ik'=>$d['ikona'] ?? '🛵',
        'po'=>$d['poznamka'] ?? null,
    ];
    if ($id) {
        $params['id'] = $id;
        $pdo->prepare("UPDATE couriers SET jmeno=:j, telefon=:t, email=:e, vozidlo=:v, spz=:s,
            zona_obslazi=:z, provize_pct=:p, externi=:ex, externi_sluzba=:es, aktivni=:a,
            barva=:b, ikona=:ik, poznamka=:po WHERE id=:id")->execute($params);
    } else {
        $pdo->prepare("INSERT INTO couriers (jmeno, telefon, email, vozidlo, spz, zona_obslazi,
            provize_pct, externi, externi_sluzba, aktivni, barva, ikona, poznamka)
            VALUES (:j,:t,:e,:v,:s,:z,:p,:ex,:es,:a,:b,:ik,:po)")->execute($params);
        $id = (int) $pdo->lastInsertId();
    }
    json_response(['ok'=>true,'id'=>$id]);
}

if ($action === 'courier' && $method === 'DELETE') {
    $id = (int) ($_GET['id'] ?? 0);
    $aktiv = $pdo->prepare("SELECT COUNT(*) FROM courier_deliveries WHERE courier_id=:id AND stav IN ('naplanovano','vyzvednuto','na_ceste')");
    $aktiv->execute(['id'=>$id]);
    if ((int)$aktiv->fetchColumn() > 0) json_error('Kurýr má aktivní rozvozy', 409);
    $pdo->prepare("DELETE FROM courier_deliveries WHERE courier_id=:id")->execute(['id'=>$id]);
    $pdo->prepare("DELETE FROM couriers WHERE id=:id")->execute(['id'=>$id]);
    json_response(['ok'=>true]);
}

if ($action === 'delivery' && $method === 'POST') {
    $d = json_input();
    if (!($d['courier_id'] ?? 0) || !trim($d['adresa'] ?? '')) json_error('Vyplň kurýra a adresu', 400);
    $pdo->prepare("
        INSERT INTO courier_deliveries
        (courier_id, objednavka_id, dl_id, adresa, psc, mesto, kontakt_jmeno, kontakt_telefon,
         cas_planovany, stav, cena_kc, provize_kc, poznamka)
        VALUES (:c, :o, :dl, :a, :psc, :m, :kj, :kt, :cp, :st, :cn, :pr, :po)
    ")->execute([
        'c'=>(int)$d['courier_id'],
        'o'=>$d['objednavka_id'] ?? null,'dl'=>$d['dl_id'] ?? null,
        'a'=>$d['adresa'],'psc'=>$d['psc'] ?? null,'m'=>$d['mesto'] ?? null,
        'kj'=>$d['kontakt_jmeno'] ?? null,'kt'=>$d['kontakt_telefon'] ?? null,
        'cp'=>$d['cas_planovany'] ?? null,'st'=>$d['stav'] ?? 'naplanovano',
        'cn'=>(float)($d['cena_kc'] ?? 0),'pr'=>(float)($d['provize_kc'] ?? 0),
        'po'=>$d['poznamka'] ?? null,
    ]);
    json_response(['ok'=>true,'id'=>(int)$pdo->lastInsertId()]);
}

if ($action === 'delivery_status' && $method === 'POST') {
    $d = json_input();
    $id = (int)($d['id'] ?? 0);
    $st = $d['stav'] ?? '';
    if (!$id || !in_array($st, ['naplanovano','vyzvednuto','na_ceste','doruceno','zruseno'], true)) {
        json_error('Neplatné parametry', 400);
    }
    $setExtra = '';
    if ($st === 'vyzvednuto') $setExtra = ', cas_naloženo = NOW()';
    if ($st === 'doruceno')   $setExtra = ', cas_doruceno = NOW()';
    $pdo->prepare("UPDATE courier_deliveries SET stav=:s $setExtra WHERE id=:id")
        ->execute(['s'=>$st,'id'=>$id]);

    // 🆕 v3.0.145 — promítni stav i do LOKÁLNÍ objednávky (předtím jen externí/aggregator
    //   objednávky dostaly mapping → vlastní kurýr doručil, ale objednávka zůstala 'nova').
    try {
        $objStav = ['vyzvednuto'=>'expedovana','na_ceste'=>'expedovana','doruceno'=>'dorucena','zruseno'=>'zrusena'][$st] ?? null;
        if ($objStav) {
            $pdo->prepare("UPDATE objednavky SET stav = :st
                WHERE id = (SELECT objednavka_id FROM courier_deliveries WHERE id = :id)
                  AND id IS NOT NULL")
                ->execute(['st'=>$objStav, 'id'=>$id]);
        }
    } catch (Throwable $e) { /* ne-fatal */ }

    // 🆕 v3.0.38 — Auto-push do externí služby pokud delivery má objednavka_id mapovaný na externí
    try {
        da_ensure_mapping_table();
        $extRow = $pdo->prepare("SELECT deo.sluzba, deo.external_id
            FROM courier_deliveries cd
            JOIN delivery_external_orders deo ON deo.objednavka_id = cd.objednavka_id
            WHERE cd.id = :id LIMIT 1");
        $extRow->execute(['id' => $id]);
        $ext = $extRow->fetch();
        if ($ext) {
            // Mapping courier stav → our objednavka stav
            $mapStavu = [
                'vyzvednuto' => 'expedovana',
                'na_ceste'   => 'na_ceste',
                'doruceno'   => 'dorucena',
                'zruseno'    => 'zrusena',
            ];
            $ourStav = $mapStavu[$st] ?? null;
            if ($ourStav) da_push_status($ext['sluzba'], $ext['external_id'], $ourStav);
        }
    } catch (Throwable $e) { /* tichá chyba — sync ne-fatal */ }

    json_response(['ok'=>true]);
}

if ($action === 'integration' && $method === 'POST') {
    $d = json_input();
    $sl = $d['sluzba'] ?? '';
    if (!in_array($sl, ['wolt','bolt','dame_jidlo','foodora','vlastni','jiny'], true)) json_error('Neplatná služba', 400);
    // 🐛 fix v2.9.169 — native PDO neumožňuje reuse placeholderů; každá ON DUPLICATE
    // klauzule potřebuje vlastní suffix.
    $povolena = !empty($d['povolena']) ? 1 : 0;
    $api_key  = $d['api_key'] ?? null;
    $store_id = $d['store_id'] ?? null;
    $provize  = (float) ($d['provize_pct'] ?? 0);
    $pdo->prepare("
        INSERT INTO courier_integrations (sluzba, povolena, api_key, store_id, provize_pct)
        VALUES (:s, :p, :k, :si, :pp)
        ON DUPLICATE KEY UPDATE povolena=:p2, api_key=:k2, store_id=:si2, provize_pct=:pp2
    ")->execute([
        's'=>$sl,
        'p'=>$povolena,  'p2'=>$povolena,
        'k'=>$api_key,   'k2'=>$api_key,
        'si'=>$store_id, 'si2'=>$store_id,
        'pp'=>$provize,  'pp2'=>$provize,
    ]);
    json_response(['ok'=>true]);
}

// 🆕 v3.0.38 — TEST INTEGRACE (ping API, ověří credentials)
if ($action === 'test_integration' && $method === 'POST') {
    $d = json_input();
    $sl = $d['sluzba'] ?? '';
    if (!in_array($sl, ['wolt','bolt','dame_jidlo','foodora'], true)) {
        json_error('Neplatná služba', 400);
    }
    try {
        $r = da_test($sl);
        json_response($r);
    } catch (Throwable $e) {
        json_error_safe('Test selhal', $e, 500);
    }
}

// 🆕 v3.0.38 — SYNC MENU (push náš katalog do služby)
if ($action === 'sync_menu' && $method === 'POST') {
    $d = json_input();
    $sl = $d['sluzba'] ?? '';
    if (!in_array($sl, ['wolt','bolt','dame_jidlo','foodora'], true)) {
        json_error('Neplatná služba', 400);
    }
    try {
        $r = da_sync_menu($sl);
        json_response($r);
    } catch (Throwable $e) {
        json_error_safe('Sync selhal', $e, 500);
    }
}

// 🆕 v3.0.38 — PUSH STATUS (vlastní stav → externí služba)
//   Pokud má objednávka externí mapování, pošli stav do služby.
if ($action === 'push_status' && $method === 'POST') {
    $d = json_input();
    $objId = (int) ($d['objednavka_id'] ?? 0);
    $stav  = $d['stav'] ?? '';
    if (!$objId || !$stav) json_error('Chybí objednavka_id nebo stav', 400);
    try {
        da_ensure_mapping_table();
        $st = $pdo->prepare("SELECT sluzba, external_id FROM delivery_external_orders WHERE objednavka_id = :o");
        $st->execute(['o' => $objId]);
        $rows = $st->fetchAll();
        if (empty($rows)) {
            json_response(['ok' => true, 'skipped' => true, 'message' => 'Objednávka není z externí služby']);
        }
        $results = [];
        foreach ($rows as $row) {
            $results[$row['sluzba']] = da_push_status($row['sluzba'], $row['external_id'], $stav);
        }
        json_response(['ok' => true, 'pushed' => $results]);
    } catch (Throwable $e) {
        json_error_safe('Push status selhal', $e, 500);
    }
}

// 🆕 v3.0.38 — WEBHOOK URLS (vrátí URL které user vloží do partner portalu)
if ($action === 'webhook_urls' && $method === 'GET') {
    json_response([
        'urls' => [
            'wolt'       => da_webhook_url('wolt'),
            'bolt'       => da_webhook_url('bolt'),
            'dame_jidlo' => da_webhook_url('dame_jidlo'),
            'foodora'    => da_webhook_url('foodora'),
        ],
    ]);
}

// 🆕 v3.0.38 — WEBHOOK LOG (poslední příchozí eventy pro debug)
if ($action === 'webhook_log' && $method === 'GET') {
    try {
        $sl = $_GET['sluzba'] ?? '';
        $where = '';
        $params = [];
        if ($sl) { $where = 'WHERE sluzba = :s'; $params['s'] = $sl; }
        $st = $pdo->prepare("SELECT * FROM delivery_webhook_log $where ORDER BY received_at DESC LIMIT 30");
        $st->execute($params);
        json_response(['log' => $st->fetchAll()]);
    } catch (Throwable $e) {
        json_response(['log' => [], 'note' => 'Žádný webhook log zatím (tabulka se vytvoří při prvním přijatém eventu)']);
    }
}

// ────── DEFAULT GET: dashboard ──────
$couriers = $pdo->query("
    SELECT c.*,
        (SELECT COUNT(*) FROM courier_deliveries WHERE courier_id=c.id AND stav IN ('naplanovano','vyzvednuto','na_ceste')) AS aktivni_pocet,
        (SELECT COUNT(*) FROM courier_deliveries WHERE courier_id=c.id AND stav='doruceno' AND DATE(cas_doruceno)=CURDATE()) AS dnes_doruceno,
        (SELECT COUNT(*) FROM courier_deliveries WHERE courier_id=c.id AND stav='doruceno') AS celkem_doruceno
    FROM couriers c
    ORDER BY c.aktivni DESC, c.jmeno
")->fetchAll();

$deliveries = $pdo->query("
    SELECT d.*, c.jmeno AS courier_jmeno, c.ikona AS courier_ikona, c.barva AS courier_barva, c.telefon AS courier_telefon
    FROM courier_deliveries d
    JOIN couriers c ON c.id=d.courier_id
    WHERE d.stav IN ('naplanovano','vyzvednuto','na_ceste')
    ORDER BY d.cas_planovany ASC, d.id ASC LIMIT 50
")->fetchAll();

$integrations = $pdo->query("SELECT * FROM courier_integrations")->fetchAll();

$stats = [
    'kuryru_aktivnich'     => (int) $pdo->query("SELECT COUNT(*) FROM couriers WHERE aktivni=1")->fetchColumn(),
    'rozvozy_aktivni'      => count($deliveries),
    'rozvozy_dnes_doruceno'=> (int) $pdo->query("SELECT COUNT(*) FROM courier_deliveries WHERE stav='doruceno' AND DATE(cas_doruceno)=CURDATE()")->fetchColumn(),
    'rozvozy_dnes_planovano' => (int) $pdo->query("SELECT COUNT(*) FROM courier_deliveries WHERE DATE(created_at)=CURDATE()")->fetchColumn(),
];

json_response([
    'couriers'     => $couriers,
    'deliveries'   => $deliveries,
    'integrations' => $integrations,
    'stats'        => $stats,
]);
