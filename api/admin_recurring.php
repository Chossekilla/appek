<?php
/**
 * Opakující se objednávky — pravidla pro automatické vytváření obj.
 *
 * Schema:
 *   GET                  → seznam pravidel
 *   GET ?id=N            → detail
 *   POST                 → vytvořit
 *   PUT                  → upravit
 *   DELETE ?id=N         → smazat
 *   POST ?action=spustit_ted → vygenerovat objednávky teď (test/manuální spuštění)
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/_admin_auth.php';
cors_headers();
require_admin();

$pdo = db();
$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? '';

// Auto-migrace
$pdo->exec("
    CREATE TABLE IF NOT EXISTS recurring_orders (
        id              INT AUTO_INCREMENT PRIMARY KEY,
        nazev           VARCHAR(150) NOT NULL,
        odberatel_id    INT NOT NULL,
        misto_dodani_id INT NULL,
        frekvence       ENUM('denne','tydne','dvouty','mesicne') NOT NULL DEFAULT 'tydne',
        dny_v_tydnu     VARCHAR(20) DEFAULT NULL,
        cas_dodani      VARCHAR(20) DEFAULT NULL,
        polozky_json    MEDIUMTEXT NOT NULL,
        poznamka        TEXT NULL,
        aktivni         TINYINT(1) NOT NULL DEFAULT 1,
        datum_zacatku   DATE NOT NULL,
        datum_konce     DATE NULL,
        posledni_beh    DATETIME NULL,
        pocet_vygen     INT NOT NULL DEFAULT 0,
        created_at      DATETIME DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_odb (odberatel_id),
        INDEX idx_aktivni (aktivni),
        FOREIGN KEY (odberatel_id) REFERENCES odberatele(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
");

if ($method === 'GET') {
    if (isset($_GET['id'])) {
        $stmt = $pdo->prepare("
            SELECT r.*, o.nazev AS odberatel_nazev, m.nazev AS misto_nazev
            FROM recurring_orders r
            JOIN odberatele o ON o.id = r.odberatel_id
            LEFT JOIN mista_dodani m ON m.id = r.misto_dodani_id
            WHERE r.id = :id
        ");
        $stmt->execute(['id' => (int) $_GET['id']]);
        $r = $stmt->fetch();
        if (!$r) json_error('Pravidlo nenalezeno', 404);
        $r['polozky'] = json_decode($r['polozky_json'], true) ?: [];
        unset($r['polozky_json']);
        json_response($r);
    }
    $stmt = $pdo->query("
        SELECT r.id, r.nazev, r.frekvence, r.dny_v_tydnu, r.cas_dodani, r.aktivni,
               r.datum_zacatku, r.datum_konce, r.posledni_beh, r.pocet_vygen,
               o.nazev AS odberatel_nazev, m.nazev AS misto_nazev,
               JSON_LENGTH(r.polozky_json) AS pocet_polozek
        FROM recurring_orders r
        JOIN odberatele o ON o.id = r.odberatel_id
        LEFT JOIN mista_dodani m ON m.id = r.misto_dodani_id
        ORDER BY r.aktivni DESC, r.nazev
    ");
    json_response($stmt->fetchAll());
}

if ($method === 'POST' && $action !== 'spustit_ted') {
    $d = json_input();
    if (empty($d['nazev']) || empty($d['odberatel_id']) || empty($d['polozky'])) {
        json_error('Chybí povinné údaje (název, odběratel, položky)');
    }
    $pdo->prepare("
        INSERT INTO recurring_orders
            (nazev, odberatel_id, misto_dodani_id, frekvence, dny_v_tydnu, cas_dodani, polozky_json, poznamka, datum_zacatku, datum_konce, aktivni)
        VALUES (:n, :o, :m, :f, :dt, :ct, :p, :pz, :dz, :dk, :a)
    ")->execute([
        'n'  => trim($d['nazev']),
        'o'  => (int) $d['odberatel_id'],
        'm'  => !empty($d['misto_dodani_id']) ? (int) $d['misto_dodani_id'] : null,
        'f'  => $d['frekvence'] ?? 'tydne',
        'dt' => $d['dny_v_tydnu'] ?? null,
        'ct' => $d['cas_dodani'] ?? null,
        'p'  => json_encode($d['polozky'], JSON_UNESCAPED_UNICODE),
        'pz' => $d['poznamka'] ?? null,
        'dz' => $d['datum_zacatku'] ?? date('Y-m-d'),
        'dk' => !empty($d['datum_konce']) ? $d['datum_konce'] : null,
        'a'  => isset($d['aktivni']) ? (int)(bool)$d['aktivni'] : 1,
    ]);
    json_response(['id' => $pdo->lastInsertId()], 201);
}

if ($method === 'PUT') {
    $d = json_input();
    if (empty($d['id'])) json_error('Chybí ID');
    $pdo->prepare("
        UPDATE recurring_orders SET
            nazev = :n, odberatel_id = :o, misto_dodani_id = :m,
            frekvence = :f, dny_v_tydnu = :dt, cas_dodani = :ct,
            polozky_json = :p, poznamka = :pz,
            datum_zacatku = :dz, datum_konce = :dk, aktivni = :a
        WHERE id = :id
    ")->execute([
        'id' => (int) $d['id'],
        'n'  => trim($d['nazev'] ?? ''),
        'o'  => (int) ($d['odberatel_id'] ?? 0),
        'm'  => !empty($d['misto_dodani_id']) ? (int) $d['misto_dodani_id'] : null,
        'f'  => $d['frekvence'] ?? 'tydne',
        'dt' => $d['dny_v_tydnu'] ?? null,
        'ct' => $d['cas_dodani'] ?? null,
        'p'  => json_encode($d['polozky'] ?? [], JSON_UNESCAPED_UNICODE),
        'pz' => $d['poznamka'] ?? null,
        'dz' => $d['datum_zacatku'] ?? date('Y-m-d'),
        'dk' => !empty($d['datum_konce']) ? $d['datum_konce'] : null,
        'a'  => isset($d['aktivni']) ? (int)(bool)$d['aktivni'] : 1,
    ]);
    json_response(['ok' => true]);
}

if ($method === 'DELETE') {
    $id = (int) ($_GET['id'] ?? 0);
    if (!$id) json_error('Chybí ID');
    $pdo->prepare("DELETE FROM recurring_orders WHERE id = :id")->execute(['id' => $id]);
    json_response(['ok' => true]);
}

// Manuální spuštění generování (test)
if ($method === 'POST' && $action === 'spustit_ted') {
    require_once __DIR__ . '/_recurring_lib.php';
    $res = recurring_generate($pdo, date('Y-m-d', strtotime('+1 day')));
    json_response($res);
}

json_error('Method not allowed', 405);
