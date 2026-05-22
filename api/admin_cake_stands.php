<?php
/**
 * ♻️ EVIDENCE VRATNÝCH STOJANŮ — Cukrárna balíček.
 *
 *   GET                            → seznam stojanů + aktivní výpůjčky
 *   POST   ?action=stand            → vytvořit/upravit stojan {id?, kod, popis, foto_url, zaloha_kc}
 *   DELETE ?action=stand&id=N       → smazat stojan (jen pokud není zapůjčen)
 *   POST   ?action=loan             → půjčit {stand_id, odberatel_id, datum_vraceni_planovane, poznamka}
 *   POST   ?action=return&id=N      → vrátit půjčku
 *   POST   ?action=lost&id=N        → označit půjčku jako ztracenou
 *   GET    ?action=loans&stand_id=N → historie půjček daného stojanu
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/_admin_auth.php';
require_once __DIR__ . '/_packages_lib.php';

cors_headers();
require_admin();
header('Content-Type: application/json; charset=UTF-8');

if (!package_enabled('cukrarna')) {
    http_response_code(402);
    json_response(['error' => 'Vyžaduje balíček 🧁 Cukrárna']);
}

$pdo = db();

// Auto-migrace
$pdo->exec("
    CREATE TABLE IF NOT EXISTS cake_stands (
        id INT AUTO_INCREMENT PRIMARY KEY,
        kod VARCHAR(40) UNIQUE NOT NULL,
        popis VARCHAR(200) NULL,
        foto_url VARCHAR(500) NULL,
        zaloha_kc DECIMAL(10,2) NOT NULL DEFAULT 0,
        stav ENUM('sklad','pujceno','ztraceno','vyrazeno') NOT NULL DEFAULT 'sklad',
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_stav (stav)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
");

$pdo->exec("
    CREATE TABLE IF NOT EXISTS cake_stands_loans (
        id INT AUTO_INCREMENT PRIMARY KEY,
        stand_id INT NOT NULL,
        odberatel_id INT NULL,
        odberatel_nazev VARCHAR(200) NULL,
        objednavka_id INT NULL,
        datum_pujceni DATE NOT NULL,
        datum_vraceni_planovane DATE NULL,
        datum_vraceni_skutecne DATE NULL,
        zaloha_uhrazena TINYINT(1) NOT NULL DEFAULT 0,
        zaloha_vracena TINYINT(1) NOT NULL DEFAULT 0,
        stav ENUM('aktivni','vraceno','ztraceno','po_termin') NOT NULL DEFAULT 'aktivni',
        poznamka TEXT NULL,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_stand (stand_id),
        INDEX idx_stav (stav),
        INDEX idx_planovane (datum_vraceni_planovane)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
");

$action = $_GET['action'] ?? '';
$method = $_SERVER['REQUEST_METHOD'];

// ────── CREATE / UPDATE STOJAN ──────
if ($action === 'stand' && $method === 'POST') {
    $d = json_input();
    $id    = (int) ($d['id'] ?? 0);
    $kod   = trim($d['kod'] ?? '');
    $popis = trim($d['popis'] ?? '');
    $foto  = trim($d['foto_url'] ?? '');
    $zal   = (float) ($d['zaloha_kc'] ?? 0);
    if (!$kod) json_error('Vyplň kód stojanu', 400);
    try {
        if ($id > 0) {
            $pdo->prepare("UPDATE cake_stands SET kod=:k, popis=:p, foto_url=:f, zaloha_kc=:z WHERE id=:id")
                ->execute(['k'=>$kod,'p'=>$popis,'f'=>$foto ?: null,'z'=>$zal,'id'=>$id]);
        } else {
            $pdo->prepare("INSERT INTO cake_stands (kod, popis, foto_url, zaloha_kc) VALUES (:k,:p,:f,:z)")
                ->execute(['k'=>$kod,'p'=>$popis,'f'=>$foto ?: null,'z'=>$zal]);
            $id = (int) $pdo->lastInsertId();
        }
        json_response(['ok'=>true, 'id'=>$id]);
    } catch (Throwable $e) { json_error('DB: ' . $e->getMessage(), 500); }
}

// ────── DELETE STOJAN ──────
if ($action === 'stand' && $method === 'DELETE') {
    $id = (int) ($_GET['id'] ?? 0);
    if (!$id) json_error('Chybí id', 400);
    $aktiv = $pdo->prepare("SELECT COUNT(*) FROM cake_stands_loans WHERE stand_id=:id AND stav='aktivni'");
    $aktiv->execute(['id'=>$id]);
    if ((int)$aktiv->fetchColumn() > 0) json_error('Stojan je zapůjčen, nejprve ho vrať', 409);
    $pdo->prepare("DELETE FROM cake_stands_loans WHERE stand_id=:id")->execute(['id'=>$id]);
    $pdo->prepare("DELETE FROM cake_stands WHERE id=:id")->execute(['id'=>$id]);
    json_response(['ok'=>true]);
}

// ────── PŮJČIT ──────
if ($action === 'loan' && $method === 'POST') {
    $d = json_input();
    $standId = (int) ($d['stand_id'] ?? 0);
    $odb     = (int) ($d['odberatel_id'] ?? 0);
    $odbNaz  = trim($d['odberatel_nazev'] ?? '');
    $dRet    = $d['datum_vraceni_planovane'] ?? null;
    if (!$standId) json_error('Chybí stand_id', 400);
    // Načti název odběratele pokud máme jen id
    if ($odb && !$odbNaz) {
        try {
            $stmt = $pdo->prepare("SELECT nazev FROM odberatele WHERE id=:id");
            $stmt->execute(['id'=>$odb]);
            $odbNaz = (string) $stmt->fetchColumn();
        } catch (Throwable $e) {}
    }
    // Ověř, že stojan není už zapůjčen
    $check = $pdo->prepare("SELECT stav FROM cake_stands WHERE id=:id");
    $check->execute(['id'=>$standId]);
    $stav = $check->fetchColumn();
    if ($stav === false) json_error('Stojan neexistuje', 404);
    if ($stav === 'pujceno') json_error('Stojan je už zapůjčený', 409);
    if ($stav === 'ztraceno' || $stav === 'vyrazeno') json_error('Stojan není dostupný (stav: ' . $stav . ')', 409);
    try {
        $pdo->beginTransaction();
        $pdo->prepare("
            INSERT INTO cake_stands_loans
            (stand_id, odberatel_id, odberatel_nazev, datum_pujceni, datum_vraceni_planovane, poznamka)
            VALUES (:s, :o, :on, CURDATE(), :dr, :p)
        ")->execute([
            's'=>$standId, 'o'=>$odb ?: null,
            'on'=>$odbNaz ?: null, 'dr'=>$dRet ?: null,
            'p'=>$d['poznamka'] ?? null,
        ]);
        $pdo->prepare("UPDATE cake_stands SET stav='pujceno' WHERE id=:id")->execute(['id'=>$standId]);
        $pdo->commit();
        json_response(['ok'=>true, 'loan_id'=>(int)$pdo->lastInsertId()]);
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        json_error('DB: ' . $e->getMessage(), 500);
    }
}

// ────── VRÁTIT ──────
if ($action === 'return' && $method === 'POST') {
    $loanId = (int) ($_GET['id'] ?? 0);
    if (!$loanId) json_error('Chybí id', 400);
    try {
        $pdo->beginTransaction();
        $stmt = $pdo->prepare("SELECT stand_id FROM cake_stands_loans WHERE id=:id");
        $stmt->execute(['id'=>$loanId]);
        $standId = (int) $stmt->fetchColumn();
        if (!$standId) { $pdo->rollBack(); json_error('Půjčka neexistuje', 404); }
        $pdo->prepare("UPDATE cake_stands_loans SET stav='vraceno', datum_vraceni_skutecne=CURDATE(), zaloha_vracena=1 WHERE id=:id")
            ->execute(['id'=>$loanId]);
        $pdo->prepare("UPDATE cake_stands SET stav='sklad' WHERE id=:id")->execute(['id'=>$standId]);
        $pdo->commit();
        json_response(['ok'=>true]);
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        json_error('DB: ' . $e->getMessage(), 500);
    }
}

// ────── ZTRACENO ──────
if ($action === 'lost' && $method === 'POST') {
    $loanId = (int) ($_GET['id'] ?? 0);
    if (!$loanId) json_error('Chybí id', 400);
    try {
        $pdo->beginTransaction();
        $stmt = $pdo->prepare("SELECT stand_id FROM cake_stands_loans WHERE id=:id");
        $stmt->execute(['id'=>$loanId]);
        $standId = (int) $stmt->fetchColumn();
        $pdo->prepare("UPDATE cake_stands_loans SET stav='ztraceno' WHERE id=:id")->execute(['id'=>$loanId]);
        if ($standId) $pdo->prepare("UPDATE cake_stands SET stav='ztraceno' WHERE id=:id")->execute(['id'=>$standId]);
        $pdo->commit();
        json_response(['ok'=>true]);
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        json_error('DB: ' . $e->getMessage(), 500);
    }
}

// ────── HISTORIE PŮJČEK DANÉHO STOJANU ──────
if ($action === 'loans' && $method === 'GET') {
    $standId = (int) ($_GET['stand_id'] ?? 0);
    $stmt = $pdo->prepare("
        SELECT l.*, COALESCE(l.odberatel_nazev, o.nazev) AS nazev_clienta
        FROM cake_stands_loans l
        LEFT JOIN odberatele o ON o.id = l.odberatel_id
        WHERE l.stand_id = :s ORDER BY l.datum_pujceni DESC, l.id DESC
    ");
    $stmt->execute(['s'=>$standId]);
    json_response(['loans' => $stmt->fetchAll()]);
}

// ────── DEFAULT: seznam stojanů + aktivní výpůjčky ──────
// Označ po termínu jako 'po_termin' (jen informativně, nemění stand stav)
$pdo->exec("
    UPDATE cake_stands_loans
    SET stav='po_termin'
    WHERE stav='aktivni' AND datum_vraceni_planovane IS NOT NULL
      AND datum_vraceni_planovane < CURDATE()
");

$stands = $pdo->query("
    SELECT s.*,
        (SELECT COUNT(*) FROM cake_stands_loans WHERE stand_id=s.id) AS pujcek_celkem,
        (SELECT l.datum_pujceni FROM cake_stands_loans l WHERE l.stand_id=s.id AND l.stav IN ('aktivni','po_termin') LIMIT 1) AS aktivni_od,
        (SELECT l.datum_vraceni_planovane FROM cake_stands_loans l WHERE l.stand_id=s.id AND l.stav IN ('aktivni','po_termin') LIMIT 1) AS aktivni_planovane,
        (SELECT COALESCE(l.odberatel_nazev, o.nazev) FROM cake_stands_loans l LEFT JOIN odberatele o ON o.id=l.odberatel_id
            WHERE l.stand_id=s.id AND l.stav IN ('aktivni','po_termin') LIMIT 1) AS aktivni_klient,
        (SELECT l.id FROM cake_stands_loans l WHERE l.stand_id=s.id AND l.stav IN ('aktivni','po_termin') LIMIT 1) AS aktivni_loan_id
    FROM cake_stands s
    ORDER BY (s.stav='pujceno') DESC, s.kod
")->fetchAll();

$aktivni = $pdo->query("
    SELECT l.*, s.kod AS stand_kod, s.popis AS stand_popis,
           COALESCE(l.odberatel_nazev, o.nazev) AS nazev_clienta,
           DATEDIFF(CURDATE(), l.datum_pujceni) AS dnu_zapujceno,
           IF(l.datum_vraceni_planovane IS NOT NULL,
              DATEDIFF(CURDATE(), l.datum_vraceni_planovane), NULL) AS dnu_po_termin
    FROM cake_stands_loans l
    JOIN cake_stands s ON s.id=l.stand_id
    LEFT JOIN odberatele o ON o.id=l.odberatel_id
    WHERE l.stav IN ('aktivni','po_termin')
    ORDER BY l.datum_vraceni_planovane ASC, l.datum_pujceni ASC
")->fetchAll();

$stats = [
    'total'    => count($stands),
    'sklad'    => count(array_filter($stands, fn($s) => $s['stav'] === 'sklad')),
    'pujceno'  => count(array_filter($stands, fn($s) => $s['stav'] === 'pujceno')),
    'ztraceno' => count(array_filter($stands, fn($s) => $s['stav'] === 'ztraceno')),
    'po_termin'=> count(array_filter($aktivni, fn($l) => $l['stav'] === 'po_termin')),
];

json_response([
    'stands'  => $stands,
    'aktivni' => $aktivni,
    'stats'   => $stats,
]);
