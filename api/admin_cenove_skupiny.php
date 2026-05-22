<?php
/**
 * Správa cenových skupin a slev v nich.
 *
 * GET                              → seznam skupin
 * GET    ?id=N                     → detail skupiny + její slevy + počet odběratelů
 * POST   {nazev, popis}            → vytvořit skupinu
 * PUT    {id, nazev, popis, ...}   → upravit skupinu
 * DELETE ?id=N                     → smazat skupinu (uvolní odběratele)
 *
 * GET    ?id=N&action=slevy        → seznam slev ve skupině s názvy kategorií/výrobků
 * POST   ?action=sleva             → přidat slevu (skupina_id, kategorie_id|vyrobek_id, sleva_pct|pevna_cena)
 * PUT    ?action=sleva             → upravit slevu
 * DELETE ?action=sleva&id=N        → smazat slevu
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/_admin_auth.php';
cors_headers();
require_super_admin();

$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? '';
$pdo = db();

// 🚀 Auto-migrace — přidej globální slevu + min. objednávky + splatnost přímo na ceník
try {
    $cols = $pdo->query("
        SELECT COLUMN_NAME FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'cenove_skupiny'
    ")->fetchAll(PDO::FETCH_COLUMN);
    if (!in_array('globalni_sleva_pct', $cols, true)) {
        $pdo->exec("ALTER TABLE cenove_skupiny ADD COLUMN globalni_sleva_pct DECIMAL(5,2) DEFAULT NULL AFTER popis");
    }
    if (!in_array('minimum_obj_kc', $cols, true)) {
        $pdo->exec("ALTER TABLE cenove_skupiny ADD COLUMN minimum_obj_kc DECIMAL(10,2) DEFAULT NULL");
    }
    if (!in_array('splatnost_dni', $cols, true)) {
        $pdo->exec("ALTER TABLE cenove_skupiny ADD COLUMN splatnost_dni INT DEFAULT NULL");
    }
} catch (Throwable $e) { /* tabulka neexistuje, vznikne jinde */ }

// =============================================================
// PŘIŘAZENÍ ODBĚRATELE DO SKUPINY
// POST   ?action=pridat_odberatele   { skupina_id, odberatel_ids: [] }
// POST   ?action=odebrat_odberatele  { odberatel_ids: [] }
// =============================================================
if ($action === 'pridat_odberatele' && $method === 'POST') {
    $d = json_input();
    $sk_id = (int) ($d['skupina_id'] ?? 0);
    $ids = array_filter(array_map('intval', (array) ($d['odberatel_ids'] ?? [])));
    if (!$sk_id) json_error('Chybí skupina_id');
    if (empty($ids)) json_error('Žádní odběratelé');

    $place = implode(',', array_fill(0, count($ids), '?'));
    $stmt = $pdo->prepare("UPDATE odberatele SET cenova_skupina_id = ? WHERE id IN ($place)");
    $stmt->execute(array_merge([$sk_id], $ids));
    json_response(['ok' => true, 'updated' => $stmt->rowCount()]);
}

if ($action === 'odebrat_odberatele' && $method === 'POST') {
    $d = json_input();
    $ids = array_filter(array_map('intval', (array) ($d['odberatel_ids'] ?? [])));
    if (empty($ids)) json_error('Žádní odběratelé');
    $place = implode(',', array_fill(0, count($ids), '?'));
    $stmt = $pdo->prepare("UPDATE odberatele SET cenova_skupina_id = NULL WHERE id IN ($place)");
    $stmt->execute($ids);
    json_response(['ok' => true, 'updated' => $stmt->rowCount()]);
}

// =============================================================
// SLEVY VE SKUPINĚ
// =============================================================
if ($action === 'slevy' && $method === 'GET') {
    $sk_id = (int) ($_GET['id'] ?? 0);
    if (!$sk_id) json_error('Chybí ID skupiny');

    $stmt = $pdo->prepare("
        SELECT s.*,
               k.nazev AS kategorie_nazev, k.ikona AS kategorie_ikona,
               v.nazev AS vyrobek_nazev, v.cislo AS vyrobek_cislo,
               v.cena_bez_dph AS vyrobek_cena_zakladni
        FROM cenove_skupiny_slevy s
        LEFT JOIN kategorie_vyrobku k ON k.id = s.kategorie_id
        LEFT JOIN vyrobky v ON v.id = s.vyrobek_id
        WHERE s.skupina_id = :s
        ORDER BY k.poradi, v.poradi, v.nazev
    ");
    $stmt->execute(['s' => $sk_id]);
    json_response($stmt->fetchAll());
}

if ($action === 'sleva' && $method === 'POST') {
    $d = json_input();
    $sk_id    = (int) ($d['skupina_id'] ?? 0);
    $kat_id   = !empty($d['kategorie_id']) ? (int) $d['kategorie_id'] : null;
    $vyr_id   = !empty($d['vyrobek_id'])   ? (int) $d['vyrobek_id']   : null;
    $sleva    = isset($d['sleva_pct'])     ? (float) $d['sleva_pct']  : null;
    $pevna    = isset($d['pevna_cena'])    ? (float) $d['pevna_cena'] : null;
    $poznamka = $d['poznamka'] ?? null;

    if (!$sk_id) json_error('Chybí ID skupiny');
    // Cíl: vyrobek (specifický) NEBO kategorie NEBO oboje NULL = celý sortiment
    if ($kat_id && $vyr_id) json_error('Vyberte BUĎ kategorii, NEBO výrobek (ne oboje)');
    if ($sleva === null && $pevna === null) json_error('Zadejte buď slevu, nebo pevnou cenu');
    if ($sleva !== null && $pevna !== null) json_error('Zadejte BUĎ slevu, NEBO pevnou cenu');
    if ($sleva !== null && ($sleva < 0 || $sleva > 100)) json_error('Sleva musí být 0-100%');
    if ($pevna !== null && $pevna < 0) json_error('Pevná cena nesmí být záporná');
    // Sortiment-wide podporuje jen procento (pevná cena nedává smysl pro celý sortiment)
    if (!$kat_id && !$vyr_id && $pevna !== null) {
        json_error('Pro celý sortiment lze nastavit pouze slevu v %, ne pevnou cenu');
    }

    // Kontrola: pro skupinu už nesmí existovat jiné sortiment-wide pravidlo
    if (!$kat_id && !$vyr_id) {
        $check = $pdo->prepare("
            SELECT COUNT(*) FROM cenove_skupiny_slevy
            WHERE skupina_id = :s AND kategorie_id IS NULL AND vyrobek_id IS NULL
        ");
        $check->execute(['s' => $sk_id]);
        if ((int) $check->fetchColumn() > 0) {
            json_error('Pro tuto skupinu už existuje pravidlo na celý sortiment. Upravte ho místo přidání nového.');
        }
    }

    try {
        // Pro sortiment-wide pravidla automaticky odstraň starý CHECK constraint
        // (z verze před migrací 03), který vyžadoval kategorie_id NEBO vyrobek_id != NULL
        if (!$kat_id && !$vyr_id) {
            try {
                $cn = $pdo->query("
                    SELECT CONSTRAINT_NAME FROM information_schema.CHECK_CONSTRAINTS
                    WHERE CONSTRAINT_SCHEMA = DATABASE()
                      AND TABLE_NAME = 'cenove_skupiny_slevy'
                    LIMIT 1
                ")->fetchColumn();
                if ($cn) {
                    $pdo->exec("ALTER TABLE cenove_skupiny_slevy DROP CONSTRAINT `" . $cn . "`");
                }
            } catch (PDOException $e) {
                // Ignoruj - jestli to nejde shodit, INSERT padne dál a ukáže chybu
                error_log('Could not drop CHECK constraint: ' . $e->getMessage());
            }
        }

        $pdo->prepare("
            INSERT INTO cenove_skupiny_slevy
                (skupina_id, kategorie_id, vyrobek_id, sleva_pct, pevna_cena, poznamka)
            VALUES (:s, :k, :v, :sl, :p, :pozn)
        ")->execute([
            's' => $sk_id, 'k' => $kat_id, 'v' => $vyr_id,
            'sl' => $sleva, 'p' => $pevna, 'pozn' => $poznamka,
        ]);
        json_response(['id' => $pdo->lastInsertId()], 201);
    } catch (PDOException $e) {
        error_log('cenove_skupiny add sleva: ' . $e->getMessage());
        json_error('Nepodařilo se uložit slevu: ' . $e->getMessage(), 500);
    }
}

if ($action === 'sleva' && $method === 'PUT') {
    $d = json_input();
    $id = (int) ($d['id'] ?? 0);
    if (!$id) json_error('Chybí ID');

    $sleva = isset($d['sleva_pct'])  ? (float) $d['sleva_pct']  : null;
    $pevna = isset($d['pevna_cena']) ? (float) $d['pevna_cena'] : null;

    if ($sleva === null && $pevna === null) json_error('Zadejte buď slevu, nebo pevnou cenu');
    if ($sleva !== null && $pevna !== null) json_error('Zadejte BUĎ slevu, NEBO pevnou cenu');

    $pdo->prepare("
        UPDATE cenove_skupiny_slevy
        SET sleva_pct = :sl, pevna_cena = :p, poznamka = :pozn
        WHERE id = :id
    ")->execute([
        'sl' => $sleva, 'p' => $pevna,
        'pozn' => $d['poznamka'] ?? null, 'id' => $id,
    ]);
    json_response(['ok' => true]);
}

if ($action === 'sleva' && $method === 'DELETE') {
    $id = (int) ($_GET['id'] ?? 0);
    if (!$id) json_error('Chybí ID');
    $pdo->prepare("DELETE FROM cenove_skupiny_slevy WHERE id = :id")->execute(['id' => $id]);
    json_response(['ok' => true]);
}

// =============================================================
// SAMOTNÉ SKUPINY
// =============================================================
if ($method === 'GET') {
    if (isset($_GET['id'])) {
        $stmt = $pdo->prepare("SELECT * FROM cenove_skupiny WHERE id = :id");
        $stmt->execute(['id' => (int) $_GET['id']]);
        $sk = $stmt->fetch();
        if (!$sk) json_error('Skupina nenalezena', 404);

        $sk['pocet_odberatelu'] = 0;
        $sk['odberatele'] = [];
        $sk['odberatele_volni'] = [];
        try {
            // Detekuj jaké sloupce odberatele má (různé instalace)
            $cols = $pdo->query("
                SELECT COLUMN_NAME FROM information_schema.COLUMNS
                WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'odberatele'
            ")->fetchAll(PDO::FETCH_COLUMN);
            $hasAktivni = in_array('aktivni', $cols, true);
            $hasIco     = in_array('ico', $cols, true);
            $hasMesto   = in_array('mesto', $cols, true);
            $hasEmail   = in_array('email', $cols, true);
            $hasSkupina = in_array('cenova_skupina_id', $cols, true);

            if (!$hasSkupina) {
                $sk['_warning'] = 'Tabulka odberatele nemá sloupec cenova_skupina_id — nelze načíst členy.';
                json_response($sk);
            }

            // Sestav SELECT dynamicky podle dostupných sloupců
            $selectCols = ['id', 'nazev'];
            if ($hasIco)     $selectCols[] = 'ico';
            if ($hasMesto)   $selectCols[] = 'mesto';
            if ($hasEmail)   $selectCols[] = 'email';
            if ($hasAktivni) $selectCols[] = 'aktivni';
            $selectSql = implode(', ', $selectCols);

            $cnt = $pdo->prepare("SELECT COUNT(*) FROM odberatele WHERE cenova_skupina_id = :id");
            $cnt->execute(['id' => $sk['id']]);
            $sk['pocet_odberatelu'] = (int) $cnt->fetchColumn();

            // Seznam odběratelů ve skupině
            $orderClause = $hasAktivni ? 'COALESCE(aktivni, 1) DESC, nazev' : 'nazev';
            $oStmt = $pdo->prepare("
                SELECT $selectSql
                FROM odberatele
                WHERE cenova_skupina_id = :id
                ORDER BY $orderClause
            ");
            $oStmt->execute(['id' => $sk['id']]);
            $rows = $oStmt->fetchAll();
            // Doplň aktivni=1 pokud sloupec není (frontend si očekává hodnotu)
            if (!$hasAktivni) foreach ($rows as &$r) $r['aktivni'] = 1;
            $sk['odberatele'] = $rows;

            // Volní odběratelé bez skupiny
            $whereVolni = 'cenova_skupina_id IS NULL';
            if ($hasAktivni) $whereVolni .= ' AND COALESCE(aktivni, 1) = 1';
            $vStmt = $pdo->query("
                SELECT $selectSql
                FROM odberatele
                WHERE $whereVolni
                ORDER BY nazev
            ");
            $rowsV = $vStmt->fetchAll();
            if (!$hasAktivni) foreach ($rowsV as &$r) $r['aktivni'] = 1;
            $sk['odberatele_volni'] = $rowsV;
        } catch (Throwable $e) {
            error_log('admin_cenove_skupiny GET ?id query odberatele: ' . $e->getMessage());
            $sk['_warning'] = 'Seznam odběratelů nelze načíst: ' . $e->getMessage();
        }

        json_response($sk);
    }

    $stmt = $pdo->query("
        SELECT s.id, s.nazev, s.popis, s.aktivni, s.created_at,
               s.globalni_sleva_pct, s.minimum_obj_kc, s.splatnost_dni,
               (SELECT COUNT(*) FROM odberatele o WHERE o.cenova_skupina_id = s.id) AS pocet_odberatelu,
               (SELECT COUNT(*) FROM cenove_skupiny_slevy x WHERE x.skupina_id = s.id) AS pocet_slev
        FROM cenove_skupiny s
        ORDER BY s.aktivni DESC, s.nazev
    ");
    json_response($stmt->fetchAll());
}

if ($method === 'POST') {
    $d = json_input();
    if (empty($d['nazev'])) json_error('Chybí název');

    $pdo->prepare("
        INSERT INTO cenove_skupiny (nazev, popis, globalni_sleva_pct, minimum_obj_kc, splatnost_dni, aktivni)
        VALUES (:n, :p, :g, :m, :sp, :a)
    ")->execute([
        'n'  => trim($d['nazev']),
        'p'  => $d['popis'] ?? null,
        'g'  => (isset($d['globalni_sleva_pct']) && $d['globalni_sleva_pct'] !== '' && $d['globalni_sleva_pct'] !== null) ? (float) $d['globalni_sleva_pct'] : null,
        'm'  => (isset($d['minimum_obj_kc']) && $d['minimum_obj_kc'] !== '' && $d['minimum_obj_kc'] !== null) ? (float) $d['minimum_obj_kc'] : null,
        'sp' => (isset($d['splatnost_dni']) && $d['splatnost_dni'] !== '' && $d['splatnost_dni'] !== null) ? (int) $d['splatnost_dni'] : null,
        'a'  => isset($d['aktivni']) ? (int) $d['aktivni'] : 1,
    ]);
    json_response(['id' => $pdo->lastInsertId()], 201);
}

if ($method === 'PUT') {
    $d = json_input();
    if (empty($d['id'])) json_error('Chybí ID');

    $pdo->prepare("
        UPDATE cenove_skupiny
        SET nazev = :n, popis = :p,
            globalni_sleva_pct = :g, minimum_obj_kc = :m, splatnost_dni = :sp,
            aktivni = :a
        WHERE id = :id
    ")->execute([
        'n'  => trim($d['nazev'] ?? ''),
        'p'  => $d['popis'] ?? null,
        'g'  => (isset($d['globalni_sleva_pct']) && $d['globalni_sleva_pct'] !== '' && $d['globalni_sleva_pct'] !== null) ? (float) $d['globalni_sleva_pct'] : null,
        'm'  => (isset($d['minimum_obj_kc']) && $d['minimum_obj_kc'] !== '' && $d['minimum_obj_kc'] !== null) ? (float) $d['minimum_obj_kc'] : null,
        'sp' => (isset($d['splatnost_dni']) && $d['splatnost_dni'] !== '' && $d['splatnost_dni'] !== null) ? (int) $d['splatnost_dni'] : null,
        'a'  => isset($d['aktivni']) ? (int) $d['aktivni'] : 1,
        'id' => (int) $d['id'],
    ]);
    json_response(['ok' => true]);
}

if ($method === 'DELETE') {
    $id = (int) ($_GET['id'] ?? 0);
    if (!$id) json_error('Chybí ID');

    // FK má ON DELETE SET NULL na odberatele.cenova_skupina_id,
    // takže odběratelé se uvolní, ale nesmaže.
    $pdo->prepare("DELETE FROM cenove_skupiny WHERE id = :id")->execute(['id' => $id]);
    json_response(['ok' => true]);
}

json_error('Neplatná metoda', 405);
