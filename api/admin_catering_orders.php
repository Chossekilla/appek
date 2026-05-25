<?php
/**
 * 🎉 CATERING ORDERS — Velkokapacitní firemní objednávky.
 *
 * GET                → seznam akcí
 * GET ?id=N          → detail
 * POST               → vytvořit
 * PUT ?id=N          → upravit
 * DELETE ?id=N       → smazat
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
    CREATE TABLE IF NOT EXISTS catering_orders (
        id INT AUTO_INCREMENT PRIMARY KEY,
        nazev VARCHAR(200) NOT NULL,
        zakaznik VARCHAR(200) NOT NULL,
        ico VARCHAR(20) NULL,
        kontaktni_email VARCHAR(150) NULL,
        kontaktni_telefon VARCHAR(50) NULL,
        datum_akce DATE NOT NULL,
        cas_od TIME NULL,
        cas_do TIME NULL,
        osob INT NOT NULL DEFAULT 50,
        misto VARCHAR(300) NULL,
        castka_bez_dph DECIMAL(12,2) NOT NULL DEFAULT 0,
        castka_dph DECIMAL(12,2) NOT NULL DEFAULT 0,
        castka_celkem DECIMAL(12,2) NOT NULL DEFAULT 0,
        zaloha_kc DECIMAL(12,2) NOT NULL DEFAULT 0,
        zaloha_uhrazena TINYINT(1) NOT NULL DEFAULT 0,
        zaloha_uhrazena_dne DATE NULL,
        doplatek_uhrazen TINYINT(1) NOT NULL DEFAULT 0,
        stav ENUM('poptavka','nabidka','potvrzeno','zaloha_uhraz','realizace','dokonceno','zruseno') NOT NULL DEFAULT 'poptavka',
        poznamka TEXT NULL,
        polozky_json LONGTEXT NULL,
        smlouva_pdf_url VARCHAR(500) NULL,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        INDEX idx_datum (datum_akce),
        INDEX idx_stav (stav)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
");

$method = $_SERVER['REQUEST_METHOD'];
$id     = (int) ($_GET['id'] ?? 0);

if ($method === 'GET' && !$id) {
    $rows = $pdo->query("SELECT * FROM catering_orders ORDER BY datum_akce DESC, id DESC LIMIT 200")->fetchAll();
    json_response(['akce' => $rows]);
}

if ($method === 'GET' && $id) {
    $stmt = $pdo->prepare("SELECT * FROM catering_orders WHERE id = :id");
    $stmt->execute(['id' => $id]);
    $row = $stmt->fetch();
    if (!$row) json_error('Neexistuje', 404);
    if ($row['polozky_json']) $row['polozky'] = json_decode($row['polozky_json'], true);
    json_response($row);
}

if ($method === 'POST') {
    require_super_admin();
    $d = json_input();
    try {
        $pdo->prepare("
            INSERT INTO catering_orders
            (nazev, zakaznik, ico, kontaktni_email, kontaktni_telefon, datum_akce, cas_od, cas_do,
             osob, misto, castka_celkem, zaloha_kc, stav, poznamka)
            VALUES (:n, :z, :i, :em, :tl, :d, :co, :cd, :os, :m, :c, :za, :st, :p)
        ")->execute([
            'n'  => trim($d['nazev']    ?? ''),
            'z'  => trim($d['zakaznik'] ?? ''),
            'i'  => $d['ico'] ?? null,
            'em' => $d['kontaktni_email'] ?? null,
            'tl' => $d['kontaktni_telefon'] ?? null,
            'd'  => $d['datum_akce'],
            'co' => $d['cas_od'] ?? null,
            'cd' => $d['cas_do'] ?? null,
            'os' => (int) ($d['osob'] ?? 50),
            'm'  => $d['misto'] ?? null,
            'c'  => (float) ($d['castka_celkem'] ?? 0),
            'za' => (float) ($d['zaloha_kc'] ?? 0),
            'st' => $d['stav'] ?? 'poptavka',
            'p'  => $d['poznamka'] ?? null,
        ]);
        json_response(['ok' => true, 'id' => (int) $pdo->lastInsertId()]);
    } catch (Throwable $e) { json_error_safe('DB', $e, 500); }
}

if ($method === 'PUT' && $id) {
    require_super_admin();
    $d = json_input();
    $allowed = ['nazev','zakaznik','ico','kontaktni_email','kontaktni_telefon','datum_akce','cas_od','cas_do','osob','misto',
                'castka_bez_dph','castka_dph','castka_celkem','zaloha_kc','zaloha_uhrazena','zaloha_uhrazena_dne',
                'doplatek_uhrazen','stav','poznamka','polozky_json'];
    $sets = []; $params = ['id' => $id];
    foreach ($allowed as $f) {
        if (array_key_exists($f, $d)) { $sets[] = "$f = :$f"; $params[$f] = $d[$f]; }
    }
    if (!$sets) json_error('Nic ke změně', 400);
    try {
        $pdo->prepare("UPDATE catering_orders SET " . implode(', ', $sets) . " WHERE id = :id")->execute($params);
        json_response(['ok' => true]);
    } catch (Throwable $e) { json_error_safe('DB', $e, 500); }
}

if ($method === 'DELETE' && $id) {
    require_super_admin();
    $pdo->prepare("DELETE FROM catering_orders WHERE id = :id")->execute(['id' => $id]);
    json_response(['ok' => true]);
}

json_error('Neznámá akce', 404);
