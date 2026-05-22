<?php
/**
 * Historie výrobních kalkulací — uložené snapshoty s cenami surovin
 * v okamžiku uložení (aby šly načíst i po pozdějších změnách cen).
 *
 *   GET    /api/admin_kalkulace_historie.php                 → seznam (vše)
 *   GET    /api/admin_kalkulace_historie.php?vyrobek_id=X    → seznam pro výrobek
 *   GET    /api/admin_kalkulace_historie.php?id=X            → detail
 *   POST   /api/admin_kalkulace_historie.php                 → uložit snapshot
 *   DELETE /api/admin_kalkulace_historie.php?id=X            → smazat
 *   POST   /api/admin_kalkulace_historie.php?action=clone    → klon { id }
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/_admin_auth.php';
cors_headers();
require_admin();

$pdo = db();
$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? '';

// =============================================================
// AUTO-MIGRACE
// =============================================================
$pdo->exec("
    CREATE TABLE IF NOT EXISTS kalkulace_historie (
        id INT AUTO_INCREMENT PRIMARY KEY,
        nazev VARCHAR(200) NOT NULL DEFAULT '',
        vyrobek_id INT NULL,
        vyrobek_nazev_snapshot VARCHAR(255) NULL,
        data JSON NOT NULL,
        vyrobni_cena_per_kus DECIMAL(12,4) NULL,
        cena_prodej_bez_dph DECIMAL(12,4) NULL,
        cena_prodej_s_dph DECIMAL(12,4) NULL,
        klonku_celkem INT NULL,
        poznamka TEXT NULL,
        uzivatel_id INT NULL,
        vytvoreno DATETIME DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_vyrobek (vyrobek_id),
        INDEX idx_datum (vytvoreno)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
");

// =============================================================
// HELPERS
// =============================================================
function decode_data($raw): array {
    if (is_array($raw)) return $raw;
    $d = json_decode($raw ?: '{}', true);
    return is_array($d) ? $d : [];
}

function row_to_kalkulace(array $r): array {
    return [
        'id'                   => (int) $r['id'],
        'nazev'                => $r['nazev'] ?? '',
        'vyrobek_id'           => $r['vyrobek_id'] ? (int) $r['vyrobek_id'] : null,
        'vyrobek_nazev'        => $r['vyrobek_nazev_snapshot'] ?? '',
        'data'                 => decode_data($r['data'] ?? '{}'),
        'vyrobni_cena_per_kus' => $r['vyrobni_cena_per_kus'] !== null ? (float) $r['vyrobni_cena_per_kus'] : null,
        'cena_prodej_bez_dph'  => $r['cena_prodej_bez_dph']  !== null ? (float) $r['cena_prodej_bez_dph']  : null,
        'cena_prodej_s_dph'    => $r['cena_prodej_s_dph']    !== null ? (float) $r['cena_prodej_s_dph']    : null,
        'klonku_celkem'        => $r['klonku_celkem'] !== null ? (int) $r['klonku_celkem'] : null,
        'poznamka'             => $r['poznamka'] ?? '',
        'vytvoreno'            => $r['vytvoreno'] ?? null,
    ];
}

/**
 * Obohatí recepturu/zdobení o snapshot dat (cena_baleni, obsah_baleni, nazev, jednotka)
 * podle aktuálního stavu surovin v DB. To zaručí že při načtení uvidíš ceny v okamžiku uložení.
 */
function obohatit_kalkulaci_snapshotem(PDO $pdo, array $data): array {
    // Sber všechny surovina_id, které potřebujeme
    $ids = [];
    foreach (($data['receptura'] ?? []) as $r) {
        if (!empty($r['surovina_id'])) $ids[] = (int) $r['surovina_id'];
    }
    foreach (($data['zdobeni'] ?? []) as $z) {
        if (!empty($z['surovina_id'])) $ids[] = (int) $z['surovina_id'];
    }
    $ids = array_unique($ids);
    if (empty($ids)) return $data;

    $place = implode(',', array_fill(0, count($ids), '?'));
    $stmt = $pdo->prepare("SELECT id, nazev, jednotka, cena_baleni, obsah_baleni FROM suroviny WHERE id IN ($place)");
    $stmt->execute($ids);
    $surById = [];
    foreach ($stmt->fetchAll() as $s) $surById[(int) $s['id']] = $s;

    // Obohať receptura
    if (!empty($data['receptura']) && is_array($data['receptura'])) {
        foreach ($data['receptura'] as $i => $r) {
            $sid = (int) ($r['surovina_id'] ?? 0);
            if ($sid && isset($surById[$sid])) {
                $s = $surById[$sid];
                $data['receptura'][$i]['_snapshot'] = [
                    'nazev'         => $s['nazev'],
                    'jednotka'      => $s['jednotka'],
                    'cena_baleni'   => (float) $s['cena_baleni'],
                    'obsah_baleni'  => (float) $s['obsah_baleni'],
                ];
            }
        }
    }
    if (!empty($data['zdobeni']) && is_array($data['zdobeni'])) {
        foreach ($data['zdobeni'] as $i => $z) {
            $sid = (int) ($z['surovina_id'] ?? 0);
            if ($sid && isset($surById[$sid])) {
                $s = $surById[$sid];
                $data['zdobeni'][$i]['_snapshot'] = [
                    'nazev'         => $s['nazev'],
                    'jednotka'      => $s['jednotka'],
                    'cena_baleni'   => (float) $s['cena_baleni'],
                    'obsah_baleni'  => (float) $s['obsah_baleni'],
                ];
            }
        }
    }
    return $data;
}

// =============================================================
// CLONE — POST ?action=clone { id }
// =============================================================
if ($method === 'POST' && $action === 'clone') {
    $d = json_input();
    $src_id = (int) ($d['id'] ?? 0);
    if (!$src_id) json_error('Chybí id zdrojové kalkulace');
    $stmt = $pdo->prepare("SELECT * FROM kalkulace_historie WHERE id = ?");
    $stmt->execute([$src_id]);
    $src = $stmt->fetch();
    if (!$src) json_error('Kalkulace nenalezena', 404);

    $stmt = $pdo->prepare("
        INSERT INTO kalkulace_historie
        (nazev, vyrobek_id, vyrobek_nazev_snapshot, data, vyrobni_cena_per_kus, cena_prodej_bez_dph, cena_prodej_s_dph, klonku_celkem, poznamka, uzivatel_id)
        VALUES (:nazev, :vyrobek_id, :vyrobek_nazev_snapshot, :data, :vyrobni_cena_per_kus, :cena_prodej_bez_dph, :cena_prodej_s_dph, :klonku_celkem, :poznamka, :uzivatel_id)
    ");
    $stmt->execute([
        'nazev'                  => 'Kopie — ' . ($src['nazev'] ?? ''),
        'vyrobek_id'             => $src['vyrobek_id'],
        'vyrobek_nazev_snapshot' => $src['vyrobek_nazev_snapshot'],
        'data'                   => $src['data'],
        'vyrobni_cena_per_kus'   => $src['vyrobni_cena_per_kus'],
        'cena_prodej_bez_dph'    => $src['cena_prodej_bez_dph'],
        'cena_prodej_s_dph'      => $src['cena_prodej_s_dph'],
        'klonku_celkem'          => $src['klonku_celkem'],
        'poznamka'               => $src['poznamka'],
        'uzivatel_id'            => $_SESSION['uzivatel_id'] ?? null,
    ]);
    json_response(['ok' => true, 'id' => (int) $pdo->lastInsertId()]);
}

// =============================================================
// GET seznam / detail
// =============================================================
if ($method === 'GET') {
    $id = (int) ($_GET['id'] ?? 0);
    if ($id) {
        $stmt = $pdo->prepare("SELECT * FROM kalkulace_historie WHERE id = ?");
        $stmt->execute([$id]);
        $r = $stmt->fetch();
        if (!$r) json_error('Nenalezeno', 404);
        json_response(row_to_kalkulace($r));
    }

    $where = '1=1'; $params = [];
    if (!empty($_GET['vyrobek_id'])) {
        $where .= ' AND vyrobek_id = ?';
        $params[] = (int) $_GET['vyrobek_id'];
    }
    if (!empty($_GET['q'])) {
        $where .= ' AND (nazev LIKE ? OR vyrobek_nazev_snapshot LIKE ?)';
        $q = '%' . $_GET['q'] . '%';
        $params[] = $q; $params[] = $q;
    }
    $stmt = $pdo->prepare("
        SELECT id, nazev, vyrobek_id, vyrobek_nazev_snapshot,
               vyrobni_cena_per_kus, cena_prodej_bez_dph, cena_prodej_s_dph,
               klonku_celkem, poznamka, vytvoreno
        FROM kalkulace_historie
        WHERE $where
        ORDER BY vytvoreno DESC
        LIMIT 500
    ");
    $stmt->execute($params);
    $rows = $stmt->fetchAll();
    json_response(array_map('row_to_kalkulace', $rows));
}

// =============================================================
// POST — uložit snapshot
// =============================================================
if ($method === 'POST') {
    $d = json_input();
    $nazev = trim((string) ($d['nazev'] ?? ''));
    $data = is_array($d['data'] ?? null) ? $d['data'] : [];
    $vyrobek_id = !empty($d['vyrobek_id']) ? (int) $d['vyrobek_id'] : null;
    $vyrobek_nazev = trim((string) ($d['vyrobek_nazev'] ?? ''));

    // Pokud user neuvedl název, vygeneruj jeden
    if ($nazev === '') {
        $base = $vyrobek_nazev ?: 'Volná kalkulace';
        $nazev = $base . ' — ' . date('j. n. Y H:i');
    }

    // Obohatit data o snapshot cen surovin
    $data = obohatit_kalkulaci_snapshotem($pdo, $data);

    $stmt = $pdo->prepare("
        INSERT INTO kalkulace_historie
        (nazev, vyrobek_id, vyrobek_nazev_snapshot, data, vyrobni_cena_per_kus, cena_prodej_bez_dph, cena_prodej_s_dph, klonku_celkem, poznamka, uzivatel_id)
        VALUES (:nazev, :vyrobek_id, :vyrobek_nazev_snapshot, :data, :vyrobni_cena_per_kus, :cena_prodej_bez_dph, :cena_prodej_s_dph, :klonku_celkem, :poznamka, :uzivatel_id)
    ");
    $stmt->execute([
        'nazev'                  => $nazev,
        'vyrobek_id'             => $vyrobek_id,
        'vyrobek_nazev_snapshot' => $vyrobek_nazev ?: null,
        'data'                   => json_encode($data, JSON_UNESCAPED_UNICODE),
        'vyrobni_cena_per_kus'   => isset($d['vyrobni_cena_per_kus']) ? (float) $d['vyrobni_cena_per_kus'] : null,
        'cena_prodej_bez_dph'    => isset($d['cena_prodej_bez_dph'])  ? (float) $d['cena_prodej_bez_dph']  : null,
        'cena_prodej_s_dph'      => isset($d['cena_prodej_s_dph'])    ? (float) $d['cena_prodej_s_dph']    : null,
        'klonku_celkem'          => isset($d['klonku_celkem'])        ? (int) $d['klonku_celkem']         : null,
        'poznamka'               => trim((string) ($d['poznamka'] ?? '')),
        'uzivatel_id'            => $_SESSION['uzivatel_id'] ?? null,
    ]);
    json_response(['ok' => true, 'id' => (int) $pdo->lastInsertId()]);
}

// =============================================================
// DELETE
// =============================================================
if ($method === 'DELETE') {
    $id = (int) ($_GET['id'] ?? 0);
    if (!$id) json_error('Chybí id');
    $pdo->prepare("DELETE FROM kalkulace_historie WHERE id = ?")->execute([$id]);
    json_response(['ok' => true]);
}

json_error('Neznámá metoda', 405);
