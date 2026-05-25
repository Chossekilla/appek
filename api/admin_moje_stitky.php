<?php
/**
 * "Moje štítky" — vlastní cenovky uživatele, mimo katalog výrobků.
 * CRUD nad tabulkou moje_stitky (auto-migrace při prvním zavolání).
 *
 * GET                — seznam
 * GET ?id=X          — jeden záznam
 * POST               — vytvořit (JSON body)
 * PUT                — upravit (JSON body s id)
 * DELETE ?id=X       — smazat
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/_admin_auth.php';
cors_headers();
require_admin();

$pdo = db();

// Auto-migrace
$pdo->exec("
    CREATE TABLE IF NOT EXISTS moje_stitky (
        id INT AUTO_INCREMENT PRIMARY KEY,
        nazev VARCHAR(200) NOT NULL,
        cislo VARCHAR(50) DEFAULT NULL,
        ean VARCHAR(13) DEFAULT NULL,
        cena_s_dph DECIMAL(10,2) NOT NULL DEFAULT 0,
        sazba_dph DECIMAL(4,1) NOT NULL DEFAULT 12.0,
        jednotka VARCHAR(20) DEFAULT 'ks',
        hmotnost_g INT DEFAULT NULL,
        obsah DECIMAL(10,3) DEFAULT NULL,
        obsah_jednotka VARCHAR(5) DEFAULT NULL,
        slozeni TEXT DEFAULT NULL,
        alergeny VARCHAR(255) DEFAULT NULL,
        badge VARCHAR(20) DEFAULT NULL,
        badge_text VARCHAR(50) DEFAULT NULL,
        poradi INT DEFAULT 0,
        vytvoreno DATETIME DEFAULT CURRENT_TIMESTAMP,
        upraveno DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) DEFAULT CHARSET=utf8mb4
");

// Auto-migrace pro existující tabulku — přidá obsah + obsah_jednotka, pokud chybí
(function() use ($pdo) {
    $cols = $pdo->query("
        SELECT COLUMN_NAME FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'moje_stitky'
    ")->fetchAll(PDO::FETCH_COLUMN);
    if (!in_array('obsah', $cols, true)) {
        $pdo->exec("ALTER TABLE moje_stitky ADD COLUMN obsah DECIMAL(10,3) DEFAULT NULL AFTER hmotnost_g");
    }
    if (!in_array('obsah_jednotka', $cols, true)) {
        $pdo->exec("ALTER TABLE moje_stitky ADD COLUMN obsah_jednotka VARCHAR(5) DEFAULT NULL AFTER obsah");
    }
})();

$method = $_SERVER['REQUEST_METHOD'];

try {
    if ($method === 'GET') {
        if (isset($_GET['id'])) {
            $stmt = $pdo->prepare("SELECT * FROM moje_stitky WHERE id = :id");
            $stmt->execute(['id' => (int) $_GET['id']]);
            $row = $stmt->fetch();
            if (!$row) json_error('Štítek nenalezen', 404);
            json_response($row);
        }
        $stmt = $pdo->query("SELECT * FROM moje_stitky ORDER BY poradi, nazev");
        json_response($stmt->fetchAll());
    }

    if ($method === 'POST') {
        $d = json_input();
        if (empty($d['nazev'])) json_error('Chybí název');

        $stmt = $pdo->prepare("
            INSERT INTO moje_stitky
                (nazev, cislo, ean, cena_s_dph, sazba_dph, jednotka,
                 hmotnost_g, obsah, obsah_jednotka,
                 slozeni, alergeny, badge, badge_text, poradi)
            VALUES (:n, :c, :e, :cs, :sz, :j, :h, :ob, :obj, :sl, :al, :b, :bt, :por)
        ");
        $stmt->execute([
            'n'   => trim($d['nazev']),
            'c'   => $d['cislo'] ?? null,
            'e'   => $d['ean'] ?? null,
            'cs'  => (float) ($d['cena_s_dph'] ?? 0),
            'sz'  => (float) ($d['sazba_dph'] ?? 12),
            'j'   => $d['jednotka'] ?? 'ks',
            'h'   => isset($d['hmotnost_g']) && $d['hmotnost_g'] !== '' ? (int) $d['hmotnost_g'] : null,
            'ob'  => isset($d['obsah']) && $d['obsah'] !== '' ? (float) $d['obsah'] : null,
            'obj' => $d['obsah_jednotka'] ?? null,
            'sl'  => $d['slozeni'] ?? null,
            'al'  => $d['alergeny'] ?? null,
            'b'   => $d['badge'] ?? null,
            'bt'  => $d['badge_text'] ?? null,
            'por' => (int) ($d['poradi'] ?? 0),
        ]);
        json_response(['id' => (int) $pdo->lastInsertId()], 201);
    }

    if ($method === 'PUT') {
        $d = json_input();
        if (empty($d['id'])) json_error('Chybí ID');

        $stmt = $pdo->prepare("
            UPDATE moje_stitky SET
                nazev = :n, cislo = :c, ean = :e,
                cena_s_dph = :cs, sazba_dph = :sz, jednotka = :j,
                hmotnost_g = :h, obsah = :ob, obsah_jednotka = :obj,
                slozeni = :sl, alergeny = :al,
                badge = :b, badge_text = :bt, poradi = :por
            WHERE id = :id
        ");
        $stmt->execute([
            'id'  => (int) $d['id'],
            'n'   => trim($d['nazev'] ?? ''),
            'c'   => $d['cislo'] ?? null,
            'e'   => $d['ean'] ?? null,
            'cs'  => (float) ($d['cena_s_dph'] ?? 0),
            'sz'  => (float) ($d['sazba_dph'] ?? 12),
            'j'   => $d['jednotka'] ?? 'ks',
            'h'   => isset($d['hmotnost_g']) && $d['hmotnost_g'] !== '' ? (int) $d['hmotnost_g'] : null,
            'ob'  => isset($d['obsah']) && $d['obsah'] !== '' ? (float) $d['obsah'] : null,
            'obj' => $d['obsah_jednotka'] ?? null,
            'sl'  => $d['slozeni'] ?? null,
            'al'  => $d['alergeny'] ?? null,
            'b'   => $d['badge'] ?? null,
            'bt'  => $d['badge_text'] ?? null,
            'por' => (int) ($d['poradi'] ?? 0),
        ]);
        json_response(['ok' => true]);
    }

    if ($method === 'DELETE') {
        $id = (int) ($_GET['id'] ?? 0);
        if (!$id) json_error('Chybí ID');
        $pdo->prepare("DELETE FROM moje_stitky WHERE id = :id")->execute(['id' => $id]);
        json_response(['ok' => true]);
    }

    json_error('Method not allowed', 405);
} catch (Throwable $e) {
    json_error_safe('Server error', $e, 500);
}
