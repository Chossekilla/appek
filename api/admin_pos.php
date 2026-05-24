<?php
/**
 * 🧾 POS — Účty per stůl, split/merge, KDS, QR self-order.
 *
 * Workflow:
 *   1. Provoz klikne na stůl → `?action=ucet&stul_id=N`
 *      → vrátí existující open ucet nebo vytvoří nový + nastaví stůl jako 'occupied'
 *   2. Přidává položky: POST `?action=item` { ucet_id, vyrobek_id, mnozstvi }
 *   3. Tisk pro kuchyni: GET `?action=print_kitchen&ucet_id=N` (HTML pro thermal 80mm)
 *   4. Zaplaceno: POST `?action=pay` { ucet_id, platby: [{castka, zpusob}] } → close + free stůl
 *   5. Split:    POST `?action=split` { ucet_id, parts: [{nazev, polozky:[id]}] }
 *   6. Merge:    POST `?action=merge` { source_ucet_ids: [], target_stul_id }
 *   7. KDS:      GET  `?action=kds` → live položky napříč všemi otevřenými účty
 *   8. QR:       POST `?action=qr_approve` { qr_order_id } → přidá do ucetu
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

// 🆕 v2.9.39 — Idempotent migrace POS sloupců v objednavky (běží při každém requestu)
// 🆕 v2.9.270 — Performance indexy (hot queries: dashboard, quick_history)
(function() use ($pdo) {
    try {
        $cols = $pdo->query("
            SELECT COLUMN_NAME FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'objednavky'
        ")->fetchAll(PDO::FETCH_COLUMN);
        if (!in_array('puvod', $cols, true)) {
            try { $pdo->exec("ALTER TABLE objednavky ADD COLUMN puvod VARCHAR(20) DEFAULT 'interni' AFTER stav"); } catch (Throwable $e) {}
            try { $pdo->exec("CREATE INDEX idx_puvod ON objednavky (puvod)"); } catch (Throwable $e) {}
        }
        if (!in_array('pos_typ', $cols, true))      try { $pdo->exec("ALTER TABLE objednavky ADD COLUMN pos_typ VARCHAR(20) DEFAULT NULL"); } catch (Throwable $e) {}
        if (!in_array('pos_payment', $cols, true))  try { $pdo->exec("ALTER TABLE objednavky ADD COLUMN pos_payment VARCHAR(20) DEFAULT NULL"); } catch (Throwable $e) {}
        if (!in_array('pos_tip', $cols, true))      try { $pdo->exec("ALTER TABLE objednavky ADD COLUMN pos_tip DECIMAL(10,2) DEFAULT 0"); } catch (Throwable $e) {}
        if (!in_array('pos_uzivatel', $cols, true)) try { $pdo->exec("ALTER TABLE objednavky ADD COLUMN pos_uzivatel VARCHAR(100) DEFAULT NULL"); } catch (Throwable $e) {}
        // 🆕 v2.9.270 — kompozitní indexy pro hot queries (dashboard provoz, quick_history)
        try { $pdo->exec("CREATE INDEX idx_puvod_datum ON objednavky (puvod, datum_objednani)"); } catch (Throwable $e) {}
        try { $pdo->exec("CREATE INDEX idx_pos_payment ON objednavky (pos_payment, datum_objednani)"); } catch (Throwable $e) {}
        try { $pdo->exec("CREATE INDEX idx_pos_stav ON restaurant_pos_polozky (stav, cas_objednavky)"); } catch (Throwable $e) {}
        try { $pdo->exec("CREATE INDEX idx_pos_ucet_stav ON restaurant_pos_ucty (stav, otevreno_v)"); } catch (Throwable $e) {}
        try { $pdo->exec("CREATE INDEX idx_kitchen_stav_pridani ON kitchen_queue (stav, cas_pridani)"); } catch (Throwable $e) {}
        try { $pdo->exec("CREATE INDEX idx_courier_stav_planovany ON courier_deliveries (stav, cas_planovany)"); } catch (Throwable $e) {}
    } catch (Throwable $e) { /* ignore — staci diagnostika v adminu */ }
})();

// =============================================================
// 📐 SCHEMA (idempotentní)
// =============================================================
$pdo->exec("
    CREATE TABLE IF NOT EXISTS restaurant_pos_ucty (
        id INT AUTO_INCREMENT PRIMARY KEY,
        stul_id INT NOT NULL,
        otevreno_v DATETIME DEFAULT CURRENT_TIMESTAMP,
        zaplaceno_v DATETIME NULL,
        otevrel_id INT NULL,
        otevrel_jmeno VARCHAR(120) NULL,
        stav ENUM('open','awaiting_payment','paid','cancelled','merged','split') NOT NULL DEFAULT 'open',
        suma_kc DECIMAL(10,2) NOT NULL DEFAULT 0,
        suma_zaplaceno DECIMAL(10,2) NOT NULL DEFAULT 0,
        pocet_hostu INT DEFAULT 1,
        poznamka TEXT NULL,
        parent_ucet_id INT NULL,
        objednavka_typ ENUM('inhouse','takeaway','delivery') DEFAULT 'inhouse',
        cislo_dokladu VARCHAR(40) NULL,
        INDEX idx_stul (stul_id),
        INDEX idx_stav (stav),
        INDEX idx_otevreno (otevreno_v),
        FOREIGN KEY (stul_id) REFERENCES restaurant_tables(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
");

$pdo->exec("
    CREATE TABLE IF NOT EXISTS restaurant_pos_polozky (
        id INT AUTO_INCREMENT PRIMARY KEY,
        ucet_id INT NOT NULL,
        vyrobek_id INT NULL,
        nazev VARCHAR(200) NOT NULL,
        jednotkova_cena DECIMAL(10,2) NOT NULL,
        mnozstvi DECIMAL(8,2) NOT NULL DEFAULT 1,
        kategorie VARCHAR(60) NULL,
        kurz INT DEFAULT 1,
        cas_objednavky DATETIME DEFAULT CURRENT_TIMESTAMP,
        cas_vari_se DATETIME NULL,
        cas_pripraveno DATETIME NULL,
        cas_servirovano DATETIME NULL,
        stav ENUM('objednano','vari_se','hotovo','servirovano','storno') NOT NULL DEFAULT 'objednano',
        kuchyne_tisk TINYINT(1) DEFAULT 0,
        poznamka VARCHAR(300) NULL,
        objednal_kdo VARCHAR(120) NULL,
        zdroj ENUM('staff','qr','app') DEFAULT 'staff',
        INDEX idx_ucet (ucet_id),
        INDEX idx_stav (stav),
        INDEX idx_kurz (ucet_id, kurz),
        FOREIGN KEY (ucet_id) REFERENCES restaurant_pos_ucty(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
");

$pdo->exec("
    CREATE TABLE IF NOT EXISTS restaurant_pos_platby (
        id INT AUTO_INCREMENT PRIMARY KEY,
        ucet_id INT NOT NULL,
        castka DECIMAL(10,2) NOT NULL,
        zpusob ENUM('hotovost','karta','qr','online','poukaz','prevod') NOT NULL DEFAULT 'hotovost',
        zaplaceno_v DATETIME DEFAULT CURRENT_TIMESTAMP,
        doklad_cislo VARCHAR(40) NULL,
        poznamka VARCHAR(200) NULL,
        INDEX idx_ucet (ucet_id),
        FOREIGN KEY (ucet_id) REFERENCES restaurant_pos_ucty(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
");

$pdo->exec("
    CREATE TABLE IF NOT EXISTS restaurant_qr_sessions (
        token VARCHAR(64) PRIMARY KEY,
        stul_id INT NOT NULL,
        ip VARCHAR(45) NULL,
        ua VARCHAR(200) NULL,
        vytvoreno DATETIME DEFAULT CURRENT_TIMESTAMP,
        expires_at DATETIME NOT NULL,
        host_jmeno VARCHAR(120) NULL,
        host_telefon VARCHAR(40) NULL,
        INDEX idx_stul (stul_id),
        INDEX idx_expires (expires_at),
        FOREIGN KEY (stul_id) REFERENCES restaurant_tables(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
");

$pdo->exec("
    CREATE TABLE IF NOT EXISTS restaurant_qr_orders (
        id INT AUTO_INCREMENT PRIMARY KEY,
        stul_id INT NOT NULL,
        session_token VARCHAR(64) NULL,
        ucet_id INT NULL,
        vyrobek_id INT NULL,
        nazev VARCHAR(200) NOT NULL,
        jednotkova_cena DECIMAL(10,2) NOT NULL,
        mnozstvi DECIMAL(8,2) NOT NULL DEFAULT 1,
        poznamka VARCHAR(300) NULL,
        stav ENUM('pending','approved','rejected','duplicated') DEFAULT 'pending',
        vytvoreno DATETIME DEFAULT CURRENT_TIMESTAMP,
        approved_v DATETIME NULL,
        approved_by VARCHAR(120) NULL,
        host_jmeno VARCHAR(120) NULL,
        host_telefon VARCHAR(40) NULL,
        INDEX idx_stul (stul_id),
        INDEX idx_stav (stav),
        FOREIGN KEY (stul_id) REFERENCES restaurant_tables(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
");

// QR token na stůl (persistent, regenerated only on demand)
function pos_table_qr_token(PDO $pdo, int $stulId): string {
    try {
        $cols = $pdo->query("SHOW COLUMNS FROM restaurant_tables")->fetchAll(PDO::FETCH_COLUMN);
        if (!in_array('qr_token', $cols, true)) {
            $pdo->exec("ALTER TABLE restaurant_tables ADD COLUMN qr_token VARCHAR(64) NULL UNIQUE");
        }
    } catch (Throwable $e) { /* ignore */ }
    $tok = $pdo->prepare("SELECT qr_token FROM restaurant_tables WHERE id = :id");
    $tok->execute(['id' => $stulId]);
    $existing = $tok->fetchColumn();
    if ($existing) return (string) $existing;
    $new = bin2hex(random_bytes(12));
    $pdo->prepare("UPDATE restaurant_tables SET qr_token = :t WHERE id = :id")->execute(['t' => $new, 'id' => $stulId]);
    return $new;
}

// Recalc + ulož sumu úctu (po každé změně položek)
function recalc_ucet_total(PDO $pdo, int $ucetId): float {
    $stmt = $pdo->prepare("
        SELECT COALESCE(SUM(jednotkova_cena * mnozstvi), 0)
        FROM restaurant_pos_polozky
        WHERE ucet_id = :u AND stav != 'storno'
    ");
    $stmt->execute(['u' => $ucetId]);
    $total = (float) $stmt->fetchColumn();
    $pdo->prepare("UPDATE restaurant_pos_ucty SET suma_kc = :s WHERE id = :id")
        ->execute(['s' => $total, 'id' => $ucetId]);
    return $total;
}

function get_ucet_with_polozky(PDO $pdo, int $ucetId): ?array {
    $stmt = $pdo->prepare("SELECT * FROM restaurant_pos_ucty WHERE id = :id");
    $stmt->execute(['id' => $ucetId]);
    $ucet = $stmt->fetch();
    if (!$ucet) return null;
    $st = $pdo->prepare("SELECT * FROM restaurant_pos_polozky WHERE ucet_id = :u ORDER BY kurz, id");
    $st->execute(['u' => $ucetId]);
    $ucet['polozky'] = $st->fetchAll();
    $st2 = $pdo->prepare("SELECT * FROM restaurant_pos_platby WHERE ucet_id = :u ORDER BY zaplaceno_v");
    $st2->execute(['u' => $ucetId]);
    $ucet['platby'] = $st2->fetchAll();
    return $ucet;
}

function aktualni_admin(PDO $pdo): array {
    return [
        'id' => $_SESSION['admin_id'] ?? null,
        'jmeno' => $_SESSION['admin_jmeno'] ?? ($_SESSION['admin_email'] ?? 'staff'),
    ];
}

$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? '';

// =============================================================
// ENDPOINTS
// =============================================================

// 🟢 GET ?action=ucet&stul_id=N → otevři/vrať otevřený účet
if ($method === 'GET' && $action === 'ucet') {
    $stulId = (int) ($_GET['stul_id'] ?? 0);
    if (!$stulId) json_error('Chybí stul_id', 400);
    $stmt = $pdo->prepare("
        SELECT * FROM restaurant_pos_ucty
        WHERE stul_id = :s AND stav = 'open'
        ORDER BY id DESC LIMIT 1
    ");
    $stmt->execute(['s' => $stulId]);
    $ucet = $stmt->fetch();
    if (!$ucet) {
        // Vytvoř nový + nastav stůl jako occupied
        $admin = aktualni_admin($pdo);
        $pdo->prepare("
            INSERT INTO restaurant_pos_ucty (stul_id, otevrel_id, otevrel_jmeno, pocet_hostu)
            VALUES (:s, :uid, :uname, 1)
        ")->execute([
            's' => $stulId, 'uid' => $admin['id'], 'uname' => $admin['jmeno'],
        ]);
        $newId = (int) $pdo->lastInsertId();
        $pdo->prepare("
            UPDATE restaurant_tables
            SET stav = 'occupied', stav_od = NOW(), hostu_aktual = 1, obsluhuje = :obs
            WHERE id = :id
        ")->execute(['obs' => $admin['jmeno'], 'id' => $stulId]);
        $ucet = get_ucet_with_polozky($pdo, $newId);
    } else {
        $ucet = get_ucet_with_polozky($pdo, (int) $ucet['id']);
    }
    // Připoj info o stole
    $st = $pdo->prepare("SELECT id, nazev, mist FROM restaurant_tables WHERE id = :id");
    $st->execute(['id' => $stulId]);
    $ucet['stul'] = $st->fetch();
    json_response($ucet);
}

// 🆕 POST ?action=item → přidat položku
if ($method === 'POST' && $action === 'item') {
    $d = json_input();
    $ucetId = (int) ($d['ucet_id'] ?? 0);
    if (!$ucetId) json_error('Chybí ucet_id', 400);

    $vyrobekId = !empty($d['vyrobek_id']) ? (int) $d['vyrobek_id'] : null;
    $nazev = trim($d['nazev'] ?? '');
    $cena  = (float) ($d['jednotkova_cena'] ?? 0);
    $kategorie = $d['kategorie'] ?? null;

    // Pokud vyrobek_id, načti data z vyrobky
    if ($vyrobekId && !$nazev) {
        $v = $pdo->prepare("SELECT nazev, cena_bez_dph, kategorie_id FROM vyrobky WHERE id = :id");
        $v->execute(['id' => $vyrobekId]);
        $vd = $v->fetch();
        if ($vd) {
            $nazev = $vd['nazev'];
            if (!$cena) $cena = (float) $vd['cena_bez_dph'];
        }
    }
    if (!$nazev) json_error('Chybí název', 400);

    $admin = aktualni_admin($pdo);
    $pdo->prepare("
        INSERT INTO restaurant_pos_polozky
          (ucet_id, vyrobek_id, nazev, jednotkova_cena, mnozstvi, kategorie, kurz, poznamka, objednal_kdo, zdroj)
        VALUES
          (:u, :v, :n, :c, :m, :k, :ku, :p, :ob, 'staff')
    ")->execute([
        'u' => $ucetId, 'v' => $vyrobekId,
        'n' => $nazev, 'c' => $cena,
        'm' => (float) ($d['mnozstvi'] ?? 1),
        'k' => $kategorie,
        'ku' => (int) ($d['kurz'] ?? 1),
        'p' => $d['poznamka'] ?? null,
        'ob' => $admin['jmeno'],
    ]);
    $itemId = (int) $pdo->lastInsertId();
    recalc_ucet_total($pdo, $ucetId);
    json_response(['ok' => true, 'id' => $itemId]);
}

// 🔄 POST ?action=item_state → změna stavu položky (KDS workflow)
if ($method === 'POST' && $action === 'item_state') {
    $d = json_input();
    $itemId = (int) ($d['id'] ?? 0);
    $stav = $d['stav'] ?? '';
    $allowed = ['objednano','vari_se','hotovo','servirovano','storno'];
    if (!$itemId || !in_array($stav, $allowed, true)) json_error('Neplatné', 400);
    $timeCol = match($stav) {
        'vari_se' => 'cas_vari_se',
        'hotovo' => 'cas_pripraveno',
        'servirovano' => 'cas_servirovano',
        default => null,
    };
    $sql = "UPDATE restaurant_pos_polozky SET stav = :s";
    if ($timeCol) $sql .= ", $timeCol = NOW()";
    $sql .= " WHERE id = :id";
    $pdo->prepare($sql)->execute(['s' => $stav, 'id' => $itemId]);

    // Pokud storno, recalc total
    if ($stav === 'storno') {
        $u = $pdo->prepare("SELECT ucet_id FROM restaurant_pos_polozky WHERE id = :id");
        $u->execute(['id' => $itemId]);
        $uid = (int) $u->fetchColumn();
        if ($uid) recalc_ucet_total($pdo, $uid);
    }
    json_response(['ok' => true]);
}

// ❌ DELETE ?action=item&id=N → smaž (nebo storno)
if ($method === 'DELETE' && $action === 'item') {
    $id = (int) ($_GET['id'] ?? 0);
    $u = $pdo->prepare("SELECT ucet_id, stav FROM restaurant_pos_polozky WHERE id = :id");
    $u->execute(['id' => $id]);
    $row = $u->fetch();
    if (!$row) json_error('Položka nenalezena', 404);
    if ($row['stav'] === 'objednano') {
        // Nezačala se vařit → reálné smazání
        $pdo->prepare("DELETE FROM restaurant_pos_polozky WHERE id = :id")->execute(['id' => $id]);
    } else {
        $pdo->prepare("UPDATE restaurant_pos_polozky SET stav = 'storno' WHERE id = :id")->execute(['id' => $id]);
    }
    recalc_ucet_total($pdo, (int) $row['ucet_id']);
    json_response(['ok' => true]);
}

// 💰 POST ?action=pay → zaplaceno (close ucet, free stůl)
if ($method === 'POST' && $action === 'pay') {
    $d = json_input();
    $ucetId = (int) ($d['ucet_id'] ?? 0);
    $platby = $d['platby'] ?? [];
    if (!$ucetId || !is_array($platby) || empty($platby)) json_error('Chybí ucet_id nebo platby', 400);

    try {
        $pdo->beginTransaction();
        // Insert platby
        $sumPaid = 0;
        $cislo = 'POS-' . date('Ymd') . '-' . $ucetId;
        $st = $pdo->prepare("
            INSERT INTO restaurant_pos_platby (ucet_id, castka, zpusob, doklad_cislo, poznamka)
            VALUES (:u, :c, :z, :d, :p)
        ");
        foreach ($platby as $p) {
            $c = (float) ($p['castka'] ?? 0);
            $z = $p['zpusob'] ?? 'hotovost';
            $st->execute(['u' => $ucetId, 'c' => $c, 'z' => $z, 'd' => $cislo, 'p' => $p['poznamka'] ?? null]);
            $sumPaid += $c;
        }
        // Update ucet
        $pdo->prepare("
            UPDATE restaurant_pos_ucty
            SET stav = 'paid', zaplaceno_v = NOW(), suma_zaplaceno = :s, cislo_dokladu = :d
            WHERE id = :id
        ")->execute(['s' => $sumPaid, 'd' => $cislo, 'id' => $ucetId]);

        // Get stul_id pak uvolni
        $st = $pdo->prepare("SELECT stul_id FROM restaurant_pos_ucty WHERE id = :id");
        $st->execute(['id' => $ucetId]);
        $stulId = (int) $st->fetchColumn();

        // Check if other open ucet for this stůl (např. merged variant)
        $o = $pdo->prepare("SELECT COUNT(*) FROM restaurant_pos_ucty WHERE stul_id = :s AND stav = 'open'");
        $o->execute(['s' => $stulId]);
        $hasOther = (int) $o->fetchColumn() > 0;

        if (!$hasOther && $stulId) {
            $pdo->prepare("
                UPDATE restaurant_tables
                SET stav = 'cleaning', stav_od = NOW(), hostu_aktual = 0, obsluhuje = NULL
                WHERE id = :id
            ")->execute(['id' => $stulId]);
        }
        $pdo->commit();
        json_response(['ok' => true, 'doklad' => $cislo, 'sum_paid' => $sumPaid]);
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        json_error('Platba selhala: ' . $e->getMessage(), 500);
    }
}

// ✂️ POST ?action=split → rozděl účet na N pod-účtů
// Payload: { ucet_id, parts: [ { nazev, polozka_ids: [ids] }, ... ] }
if ($method === 'POST' && $action === 'split') {
    $d = json_input();
    $ucetId = (int) ($d['ucet_id'] ?? 0);
    $parts  = $d['parts'] ?? [];
    if (!$ucetId || !is_array($parts) || count($parts) < 2) json_error('Min 2 části', 400);

    try {
        $pdo->beginTransaction();
        $origStmt = $pdo->prepare("SELECT * FROM restaurant_pos_ucty WHERE id = :id");
        $origStmt->execute(['id' => $ucetId]);
        $orig = $origStmt->fetch();
        if (!$orig) throw new Exception('Účet nenalezen');

        $newIds = [];
        foreach ($parts as $i => $p) {
            $polozkaIds = array_map('intval', $p['polozka_ids'] ?? []);
            if (empty($polozkaIds)) continue;
            // Nový sub-ucet
            $pdo->prepare("
                INSERT INTO restaurant_pos_ucty
                  (stul_id, otevreno_v, otevrel_jmeno, parent_ucet_id, poznamka)
                VALUES
                  (:s, :ov, :on, :p, :pz)
            ")->execute([
                's'  => $orig['stul_id'],
                'ov' => $orig['otevreno_v'],
                'on' => trim($p['nazev'] ?? 'Část ' . ($i + 1)),
                'p'  => $ucetId,
                'pz' => 'Split z účtu #' . $ucetId,
            ]);
            $newId = (int) $pdo->lastInsertId();
            $newIds[] = $newId;
            // Re-link položky
            $place = implode(',', array_fill(0, count($polozkaIds), '?'));
            $args = array_merge([$newId, $ucetId], $polozkaIds);
            $pdo->prepare("
                UPDATE restaurant_pos_polozky
                SET ucet_id = ?
                WHERE ucet_id = ? AND id IN ($place)
            ")->execute($args);
            recalc_ucet_total($pdo, $newId);
        }
        // Pokud zůstaly nějaké polozky u original, recalc & zachovej
        $left = $pdo->prepare("SELECT COUNT(*) FROM restaurant_pos_polozky WHERE ucet_id = :u");
        $left->execute(['u' => $ucetId]);
        if ((int) $left->fetchColumn() > 0) {
            recalc_ucet_total($pdo, $ucetId);
        } else {
            // Vše rozděleno → original mark 'split' a zavři
            $pdo->prepare("UPDATE restaurant_pos_ucty SET stav = 'split' WHERE id = :id")->execute(['id' => $ucetId]);
        }
        $pdo->commit();
        json_response(['ok' => true, 'new_ucet_ids' => $newIds]);
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        json_error('Split selhal: ' . $e->getMessage(), 500);
    }
}

// 🔗 POST ?action=merge → sluč 2+ účtů do prvního
// Payload: { target_ucet_id, source_ucet_ids: [ids] }
if ($method === 'POST' && $action === 'merge') {
    $d = json_input();
    $target = (int) ($d['target_ucet_id'] ?? 0);
    $sources = array_map('intval', $d['source_ucet_ids'] ?? []);
    if (!$target || empty($sources)) json_error('Chybí target nebo sources', 400);

    try {
        $pdo->beginTransaction();
        // Re-link položky a platby
        $place = implode(',', array_fill(0, count($sources), '?'));
        $args = array_merge([$target], $sources);
        $pdo->prepare("UPDATE restaurant_pos_polozky SET ucet_id = ? WHERE ucet_id IN ($place)")->execute($args);
        $pdo->prepare("UPDATE restaurant_pos_platby  SET ucet_id = ? WHERE ucet_id IN ($place)")->execute($args);
        // Source účty mark merged
        $pdo->prepare("UPDATE restaurant_pos_ucty SET stav = 'merged' WHERE id IN ($place)")->execute($sources);
        // Recalc target
        recalc_ucet_total($pdo, $target);
        // Uvolni stoly source (pokud na nich není jiný open ucet)
        $sStmt = $pdo->prepare("SELECT DISTINCT stul_id FROM restaurant_pos_ucty WHERE id IN ($place)");
        $sStmt->execute($sources);
        $sourceStuly = $sStmt->fetchAll(PDO::FETCH_COLUMN);
        foreach ($sourceStuly as $sid) {
            $o = $pdo->prepare("SELECT COUNT(*) FROM restaurant_pos_ucty WHERE stul_id = :s AND stav = 'open'");
            $o->execute(['s' => $sid]);
            if ((int) $o->fetchColumn() === 0) {
                $pdo->prepare("UPDATE restaurant_tables SET stav = 'free', stav_od = NULL, hostu_aktual = 0 WHERE id = :id")->execute(['id' => $sid]);
            }
        }
        $pdo->commit();
        json_response(['ok' => true, 'merged_count' => count($sources), 'target_total' => recalc_ucet_total($pdo, $target)]);
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        json_error('Merge selhal: ' . $e->getMessage(), 500);
    }
}

// 🚚 POST ?action=move → přesuň ucet na jiný stůl
if ($method === 'POST' && $action === 'move') {
    $d = json_input();
    $ucetId = (int) ($d['ucet_id'] ?? 0);
    $novyStulId = (int) ($d['novy_stul_id'] ?? 0);
    if (!$ucetId || !$novyStulId) json_error('Chybí', 400);
    try {
        $pdo->beginTransaction();
        $old = $pdo->prepare("SELECT stul_id FROM restaurant_pos_ucty WHERE id = :id");
        $old->execute(['id' => $ucetId]);
        $oldStul = (int) $old->fetchColumn();
        $pdo->prepare("UPDATE restaurant_pos_ucty SET stul_id = :s WHERE id = :id")->execute(['s' => $novyStulId, 'id' => $ucetId]);
        // Uvolni starý stůl pokud žádný open ucet
        $o = $pdo->prepare("SELECT COUNT(*) FROM restaurant_pos_ucty WHERE stul_id = :s AND stav = 'open'");
        $o->execute(['s' => $oldStul]);
        if ((int) $o->fetchColumn() === 0) {
            $pdo->prepare("UPDATE restaurant_tables SET stav = 'free', stav_od = NULL WHERE id = :id")->execute(['id' => $oldStul]);
        }
        // Označ nový stůl occupied
        $pdo->prepare("UPDATE restaurant_tables SET stav = 'occupied', stav_od = NOW() WHERE id = :id")->execute(['id' => $novyStulId]);
        $pdo->commit();
        json_response(['ok' => true]);
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        json_error('Move selhal: ' . $e->getMessage(), 500);
    }
}

// 👨‍🍳 GET ?action=kds → kitchen display data (všechny aktivní položky)
if ($method === 'GET' && $action === 'kds') {
    $stmt = $pdo->query("
        SELECT p.*, u.stul_id, u.id AS ucet_id,
               t.nazev AS stul_nazev, t.zone_id
        FROM restaurant_pos_polozky p
        INNER JOIN restaurant_pos_ucty u ON u.id = p.ucet_id
        INNER JOIN restaurant_tables t   ON t.id = u.stul_id
        WHERE u.stav = 'open' AND p.stav IN ('objednano','vari_se','hotovo')
        ORDER BY p.cas_objednavky ASC
    ");
    $items = $stmt->fetchAll();
    // Group by ucet/stůl
    $grouped = [];
    foreach ($items as $i) {
        $key = (int) $i['ucet_id'];
        $grouped[$key] ??= [
            'ucet_id' => (int) $i['ucet_id'],
            'stul_id' => (int) $i['stul_id'],
            'stul_nazev' => $i['stul_nazev'],
            'zone_id' => $i['zone_id'],
            'polozky' => [],
            'first_objednavka' => $i['cas_objednavky'],
        ];
        $grouped[$key]['polozky'][] = $i;
    }
    json_response(['orders' => array_values($grouped), 'total_items' => count($items)]);
}

// 📜 GET ?action=open_ucty → seznam všech open účtů (pro shift dashboard)
if ($method === 'GET' && $action === 'open_ucty') {
    $rows = $pdo->query("
        SELECT u.*, t.nazev AS stul_nazev, t.zone_id,
               (SELECT COUNT(*) FROM restaurant_pos_polozky WHERE ucet_id = u.id AND stav != 'storno') AS pocet_polozek
        FROM restaurant_pos_ucty u
        INNER JOIN restaurant_tables t ON t.id = u.stul_id
        WHERE u.stav = 'open'
        ORDER BY u.otevreno_v
    ")->fetchAll();
    json_response(['ucty' => $rows]);
}

// 📲 POST ?action=qr_generate → vrať QR token + URL pro stůl
if ($method === 'POST' && $action === 'qr_generate') {
    $d = json_input();
    $stulId = (int) ($d['stul_id'] ?? 0);
    $reset = !empty($d['reset']);
    if (!$stulId) json_error('Chybí stul_id', 400);
    if ($reset) {
        $new = bin2hex(random_bytes(12));
        $pdo->prepare("UPDATE restaurant_tables SET qr_token = :t WHERE id = :id")->execute(['t' => $new, 'id' => $stulId]);
        $token = $new;
    } else {
        $token = pos_table_qr_token($pdo, $stulId);
    }
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $url = $scheme . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost') . '/qr/?t=' . $token;
    json_response(['token' => $token, 'url' => $url, 'stul_id' => $stulId]);
}

// 📋 GET ?action=qr_pending → seznam QR objednávek čekajících na schválení
if ($method === 'GET' && $action === 'qr_pending') {
    $rows = $pdo->query("
        SELECT q.*, t.nazev AS stul_nazev
        FROM restaurant_qr_orders q
        INNER JOIN restaurant_tables t ON t.id = q.stul_id
        WHERE q.stav = 'pending'
        ORDER BY q.vytvoreno
    ")->fetchAll();
    json_response(['orders' => $rows]);
}

// ✅ POST ?action=qr_approve → schvál QR order → přidá do otevřeného účtu
if ($method === 'POST' && $action === 'qr_approve') {
    $d = json_input();
    $qrId = (int) ($d['qr_order_id'] ?? 0);
    if (!$qrId) json_error('Chybí ID', 400);

    $stmt = $pdo->prepare("SELECT * FROM restaurant_qr_orders WHERE id = :id");
    $stmt->execute(['id' => $qrId]);
    $qr = $stmt->fetch();
    if (!$qr) json_error('Objednávka nenalezena', 404);
    if ($qr['stav'] !== 'pending') json_error('Už zpracováno', 400);

    try {
        $pdo->beginTransaction();
        // Najdi/vytvoř ucet
        $stulId = (int) $qr['stul_id'];
        $u = $pdo->prepare("SELECT id FROM restaurant_pos_ucty WHERE stul_id = :s AND stav = 'open' LIMIT 1");
        $u->execute(['s' => $stulId]);
        $ucetId = (int) $u->fetchColumn();
        if (!$ucetId) {
            $pdo->prepare("INSERT INTO restaurant_pos_ucty (stul_id, otevrel_jmeno) VALUES (:s, 'QR self-order')")
                ->execute(['s' => $stulId]);
            $ucetId = (int) $pdo->lastInsertId();
            $pdo->prepare("UPDATE restaurant_tables SET stav = 'occupied', stav_od = NOW() WHERE id = :id")
                ->execute(['id' => $stulId]);
        }
        // Add položku
        $pdo->prepare("
            INSERT INTO restaurant_pos_polozky
              (ucet_id, vyrobek_id, nazev, jednotkova_cena, mnozstvi, poznamka, objednal_kdo, zdroj)
            VALUES (:u, :v, :n, :c, :m, :p, :o, 'qr')
        ")->execute([
            'u' => $ucetId,
            'v' => $qr['vyrobek_id'] ?: null,
            'n' => $qr['nazev'],
            'c' => $qr['jednotkova_cena'],
            'm' => $qr['mnozstvi'],
            'p' => $qr['poznamka'],
            'o' => $qr['host_jmeno'] ?: 'QR host',
        ]);
        $admin = aktualni_admin($pdo);
        $pdo->prepare("UPDATE restaurant_qr_orders SET stav = 'approved', approved_v = NOW(), approved_by = :by, ucet_id = :u WHERE id = :id")
            ->execute(['by' => $admin['jmeno'], 'u' => $ucetId, 'id' => $qrId]);
        recalc_ucet_total($pdo, $ucetId);
        $pdo->commit();
        json_response(['ok' => true, 'ucet_id' => $ucetId]);
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        json_error('Approve selhal: ' . $e->getMessage(), 500);
    }
}

// ❌ POST ?action=qr_reject → odmítni QR order
if ($method === 'POST' && $action === 'qr_reject') {
    $d = json_input();
    $qrId = (int) ($d['qr_order_id'] ?? 0);
    if (!$qrId) json_error('Chybí ID', 400);
    $pdo->prepare("UPDATE restaurant_qr_orders SET stav = 'rejected' WHERE id = :id")->execute(['id' => $qrId]);
    json_response(['ok' => true]);
}

// 📊 GET ?action=ucet_detail&id=N → detail účtu (pro historie a recovery)
if ($method === 'GET' && $action === 'ucet_detail') {
    $id = (int) ($_GET['id'] ?? 0);
    if (!$id) json_error('Chybí ID', 400);
    $u = get_ucet_with_polozky($pdo, $id);
    if (!$u) json_error('Nenalezeno', 404);
    json_response($u);
}

// ═════════════════════════════════════════════════════════════════
// 🆕 v2.9.25 — TOUCH-GRID POS (Quick Register)
// Bezstolový POS pro pultový prodej / take-away.
// ═════════════════════════════════════════════════════════════════

// 📦 GET ?action=catalog — kategorie + výrobky pro POS touch grid
if ($method === 'GET' && $action === 'catalog') {
    try {
        // Auto-migrace POS sloupců v objednavky (jen jednou)
        (function() use ($pdo) {
            try {
                $cols = $pdo->query("
                    SELECT COLUMN_NAME FROM information_schema.COLUMNS
                    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'objednavky'
                ")->fetchAll(PDO::FETCH_COLUMN);
                if (!in_array('puvod', $cols, true)) {
                    $pdo->exec("ALTER TABLE objednavky ADD COLUMN puvod VARCHAR(20) DEFAULT 'interni' AFTER stav, ADD INDEX idx_puvod (puvod)");
                }
                if (!in_array('pos_typ', $cols, true)) {
                    $pdo->exec("ALTER TABLE objednavky ADD COLUMN pos_typ VARCHAR(20) DEFAULT NULL AFTER puvod");
                }
                if (!in_array('pos_payment', $cols, true)) {
                    $pdo->exec("ALTER TABLE objednavky ADD COLUMN pos_payment VARCHAR(20) DEFAULT NULL AFTER pos_typ");
                }
                if (!in_array('pos_tip', $cols, true)) {
                    $pdo->exec("ALTER TABLE objednavky ADD COLUMN pos_tip DECIMAL(10,2) DEFAULT 0 AFTER pos_payment");
                }
                if (!in_array('pos_uzivatel', $cols, true)) {
                    $pdo->exec("ALTER TABLE objednavky ADD COLUMN pos_uzivatel VARCHAR(100) DEFAULT NULL AFTER pos_tip");
                }
            } catch (Throwable $e) {}
        })();

        // Detekuj jestli kategorie_vyrobku má sloupec barva (může chybět ve starších DB)
        $hasBarva = (function() use ($pdo) {
            try {
                $cols = $pdo->query("
                    SELECT COLUMN_NAME FROM information_schema.COLUMNS
                    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'kategorie_vyrobku'
                ")->fetchAll(PDO::FETCH_COLUMN);
                return in_array('barva', $cols, true);
            } catch (Throwable $e) { return false; }
        })();
        $hasObrazek = (function() use ($pdo) {
            try {
                $cols = $pdo->query("
                    SELECT COLUMN_NAME FROM information_schema.COLUMNS
                    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'kategorie_vyrobku'
                ")->fetchAll(PDO::FETCH_COLUMN);
                return in_array('obrazek_url', $cols, true);
            } catch (Throwable $e) { return false; }
        })();
        $barvaCol   = $hasBarva   ? 'k.barva'       : 'NULL AS barva';
        $obrazekCol = $hasObrazek ? 'k.obrazek_url' : 'NULL AS obrazek_url';

        $cats = $pdo->query("
            SELECT k.id, k.nazev,
                   COALESCE(NULLIF(k.ikona, ''), '📦') AS ikona,
                   {$barvaCol}, {$obrazekCol},
                   COALESCE(k.poradi, 999) AS poradi,
                   (SELECT COUNT(*) FROM vyrobky v WHERE v.kategorie_id = k.id AND v.aktivni = 1) AS pocet
            FROM kategorie_vyrobku k
            WHERE COALESCE(k.aktivni, 1) = 1
            ORDER BY poradi ASC, k.nazev ASC
        ")->fetchAll(PDO::FETCH_ASSOC);

        // Detekuj které sloupce vyrobky existují (oblibeny/je_akce/atd. mohly chybět ve starších DB)
        $vyrCols = (function() use ($pdo) {
            try {
                return $pdo->query("
                    SELECT COLUMN_NAME FROM information_schema.COLUMNS
                    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'vyrobky'
                ")->fetchAll(PDO::FETCH_COLUMN);
            } catch (Throwable $e) { return []; }
        })();
        $col = function($name, $default = 'NULL') use ($vyrCols) {
            return in_array($name, $vyrCols, true) ? "v.{$name}" : "{$default} AS {$name}";
        };
        // DPH sazba: vyrobky má FK sazba_dph_id → sazby_dph.sazba (skutečná %)
        // Fallback pokud sazby_dph tabulka neexistuje nebo FK chybí → použij 21
        $hasSazbyDph = (function() use ($pdo) {
            try {
                $t = $pdo->query("SHOW TABLES LIKE 'sazby_dph'")->fetchColumn();
                return !empty($t);
            } catch (Throwable $e) { return false; }
        })();
        $sazbaSelect = (in_array('sazba_dph_id', $vyrCols, true) && $hasSazbyDph)
            ? "COALESCE(s.sazba, 21) AS sazba_dph"
            : "21 AS sazba_dph";
        $sazbaJoin = (in_array('sazba_dph_id', $vyrCols, true) && $hasSazbyDph)
            ? "LEFT JOIN sazby_dph s ON s.id = v.sazba_dph_id"
            : "";

        $vyrobky = $pdo->query("
            SELECT v.id, v.nazev, v.kategorie_id, v.cislo,
                   {$col('ean')}, {$col('jednotka', "'ks'")},
                   v.cena_bez_dph, {$sazbaSelect},
                   {$col('obrazek_url')},
                   {$col('je_akce', '0')}, {$col('je_novinka', '0')}, {$col('je_doprodej', '0')}, {$col('oblibeny', '0')},
                   {$col('alergeny')},
                   k.nazev AS kategorie_nazev
            FROM vyrobky v
            LEFT JOIN kategorie_vyrobku k ON k.id = v.kategorie_id
            {$sazbaJoin}
            WHERE COALESCE(v.aktivni, 1) = 1
            ORDER BY " . (in_array('oblibeny', $vyrCols, true) ? 'v.oblibeny DESC, ' : '') . "v.nazev ASC
            LIMIT 500
        ")->fetchAll(PDO::FETCH_ASSOC);

        foreach ($vyrobky as &$v) {
            $v['cena_bez_dph']  = (float)$v['cena_bez_dph'];
            $v['sazba_dph']     = (float)($v['sazba_dph'] ?? 0);
            $v['cena_s_dph']    = round($v['cena_bez_dph'] * (1 + $v['sazba_dph']/100), 2);
            $v['je_akce']       = (int)($v['je_akce'] ?? 0);
            $v['je_novinka']    = (int)($v['je_novinka'] ?? 0);
            $v['je_doprodej']   = (int)($v['je_doprodej'] ?? 0);
            $v['oblibeny']      = (int)($v['oblibeny'] ?? 0);
        }
        unset($v);

        json_response([
            'ok'        => true,
            'kategorie' => $cats,
            'vyrobky'   => $vyrobky,
            'pocet'     => count($vyrobky),
        ]);
    } catch (Throwable $e) {
        json_error('Chyba načtení katalogu: ' . $e->getMessage(), 500);
    }
}

// 👥 GET ?action=customers — seznam odběratelů pro výběr "+ Customer"
if ($method === 'GET' && $action === 'customers') {
    try {
        $q = trim($_GET['q'] ?? '');

        // Detekuj existující sloupce (různé hostingy mají různé schémy)
        $odbCols = $pdo->query("
            SELECT COLUMN_NAME FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'odberatele'
        ")->fetchAll(PDO::FETCH_COLUMN);

        $select = ['id', 'nazev'];
        foreach (['ico', 'telefon', 'email'] as $c) {
            if (in_array($c, $odbCols, true)) $select[] = $c;
            else $select[] = "NULL AS {$c}";
        }
        $sql = "SELECT " . implode(', ', $select) . " FROM odberatele";

        $wheres = [];
        // Filtr aktivni jen pokud sloupec existuje
        if (in_array('aktivni', $odbCols, true)) {
            $wheres[] = "COALESCE(aktivni, 1) = 1";
        }
        // Exclude POS Walk-in z user-facing listu — to je interní fallback
        $wheres[] = "nazev != 'POS Walk-in'";

        $params = [];
        if ($q !== '') {
            $wheres[] = "(nazev LIKE :q"
                     . (in_array('ico', $odbCols, true) ? " OR ico LIKE :q" : "")
                     . (in_array('telefon', $odbCols, true) ? " OR telefon LIKE :q" : "")
                     . (in_array('email', $odbCols, true) ? " OR email LIKE :q" : "")
                     . ")";
            $params['q'] = "%{$q}%";
        }
        if ($wheres) $sql .= " WHERE " . implode(' AND ', $wheres);
        $sql .= " ORDER BY nazev ASC LIMIT 100";

        $st = $pdo->prepare($sql);
        $st->execute($params);
        json_response(['ok' => true, 'odberatele' => $st->fetchAll(PDO::FETCH_ASSOC)]);
    } catch (Throwable $e) {
        json_error('Chyba načtení zákazníků: ' . $e->getMessage(), 500);
    }
}

// 🧾 POST ?action=quick_order — vytvoří POS objednávku z košíku (bez stolu)
// 🆕 v2.9.270 — Idempotence: idempotency_key (klient generuje UUID) →
//   pokud stejný klíč přijde dvakrát (síťový retry, dvojklik), vrátíme původní výsledek.
//   Bez tohoto by retry vytvořilo 2× účtenku.
if ($method === 'POST' && $action === 'quick_order') {
    $data         = json_decode(file_get_contents('php://input'), true) ?? [];
    $polozky      = $data['polozky']      ?? [];
    $odberatel_id = $data['odberatel_id'] ?? null;
    $pos_typ      = $data['pos_typ']      ?? 'sebou';
    $pos_payment  = $data['pos_payment']  ?? 'hotove';
    $pos_tip      = (float)($data['pos_tip']   ?? 0);
    $sleva_pct    = (float)($data['sleva_pct'] ?? 0);
    $poznamka     = trim((string)($data['poznamka'] ?? ''));
    $idempKey     = trim((string)($data['idempotency_key'] ?? ''));

    $allowed_typy     = ['sebou', 'na_miste', 'rozvoz', 'vyzvednuti'];
    $allowed_payments = ['hotove', 'karta', 'paypal', 'gift_card', 'voucher', 'mobile'];
    if (!in_array($pos_typ, $allowed_typy, true))         json_error('Neplatný typ objednávky', 400);
    if (!in_array($pos_payment, $allowed_payments, true)) json_error('Neplatná platební metoda', 400);
    if (empty($polozky) || !is_array($polozky))           json_error('Prázdný košík', 400);

    // 🆕 Idempotence — pokud klient pošle stejný key 2×, vrátíme předchozí výsledek
    if ($idempKey !== '' && strlen($idempKey) <= 80) {
        try {
            // Idempotency tabulka (idempotent CREATE)
            $pdo->exec("
                CREATE TABLE IF NOT EXISTS pos_idempotency (
                    idempotency_key VARCHAR(80) PRIMARY KEY,
                    objednavka_id INT NULL,
                    response_json TEXT NULL,
                    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                    INDEX idx_created (created_at)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
            ");
            $st = $pdo->prepare("SELECT response_json FROM pos_idempotency WHERE idempotency_key = :k LIMIT 1");
            $st->execute(['k' => $idempKey]);
            $existing = $st->fetchColumn();
            if ($existing) {
                // Vrátíme původní response — žádná duplikace
                $cached = json_decode($existing, true);
                if (is_array($cached)) {
                    $cached['idempotent_replay'] = true;
                    json_response($cached);
                }
            }
        } catch (Throwable $e) { /* soft-fail — pokračujeme bez idempotence */ }
    }

    try {
        $pdo->beginTransaction();

        // Vygeneruj číslo objednávky: POS-YYYYMMDD-NNN
        $today = date('Ymd');
        $st = $pdo->prepare("SELECT MAX(CAST(SUBSTRING_INDEX(cislo, '-', -1) AS UNSIGNED)) AS m
                              FROM objednavky WHERE cislo LIKE :p");
        $st->execute(['p' => "POS-{$today}-%"]);
        $next  = (int)($st->fetchColumn() ?? 0) + 1;
        $cislo = sprintf('POS-%s-%03d', $today, $next);

        // Spočítej částky
        $bezDph = 0.0;
        $dphSum = 0.0;
        foreach ($polozky as $p) {
            $mn   = (float)($p['mnozstvi']     ?? 1);
            $cena = (float)($p['cena_bez_dph'] ?? 0);
            $saz  = (float)($p['sazba_dph']    ?? 21);
            $line = $mn * $cena;
            $bezDph += $line;
            $dphSum += $line * ($saz / 100);
        }
        if ($sleva_pct > 0 && $sleva_pct <= 100) {
            $bezDph = $bezDph * (1 - $sleva_pct/100);
            $dphSum = $dphSum * (1 - $sleva_pct/100);
        }
        $celkem = round($bezDph + $dphSum + $pos_tip, 2);
        $bezDph = round($bezDph, 2);
        $dphSum = round($dphSum, 2);

        // POS metadata
        $meta_arr = [
            'typ'       => $pos_typ,
            'payment'   => $pos_payment,
            'tip'       => $pos_tip,
            'sleva_pct' => $sleva_pct,
        ];
        $meta_json = json_encode($meta_arr, JSON_UNESCAPED_UNICODE);

        // Stav: na místě/sebou/vyzvednutí → zaplaceno; rozvoz → nova (čeká na kurýra)
        $stav = ($pos_typ === 'rozvoz') ? 'nova' : 'zaplaceno';

        // 🆕 v2.9.39 — Walk-in customer fallback
        // `objednavky.odberatel_id` je NOT NULL + FK → "Neznámý zákazník" nelze
        // poslat jako NULL. Auto-vytvoříme "POS Walk-in" odběratele a použijeme jeho ID.
        if (!$odberatel_id) {
            $walkin = $pdo->query("SELECT id FROM odberatele WHERE nazev = 'POS Walk-in' LIMIT 1")->fetchColumn();
            if (!$walkin) {
                // Detekuj které sloupce odberatele má (různé hostingy mají různé sloupce)
                $odbCols = $pdo->query("
                    SELECT COLUMN_NAME FROM information_schema.COLUMNS
                    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'odberatele'
                ")->fetchAll(PDO::FETCH_COLUMN);
                $cols = ['nazev'];
                $vals = [':n'];
                $params = ['n' => 'POS Walk-in'];
                if (in_array('aktivni', $odbCols, true)) { $cols[] = 'aktivni'; $vals[] = '1'; }
                if (in_array('typ', $odbCols, true))     { $cols[] = 'typ';     $vals[] = "'pos_walkin'"; }
                $sql = "INSERT INTO odberatele (" . implode(',', $cols) . ") VALUES (" . implode(',', $vals) . ")";
                $pdo->prepare($sql)->execute($params);
                $walkin = $pdo->lastInsertId();
            }
            $odberatel_id = (int)$walkin;
        }

        // Hlavička
        $st = $pdo->prepare("
            INSERT INTO objednavky (
                cislo, typ, odberatel_id, datum_objednani, datum_dodani,
                castka_bez_dph, castka_dph, castka_celkem, stav, puvod,
                pos_typ, pos_payment, pos_tip, pos_uzivatel, poznamka
            ) VALUES (
                :cislo, 'pos', :ob, NOW(), CURDATE(),
                :bd, :d, :c, :stav, 'pos',
                :pt, :pp, :tip, :uziv, :poz
            )
        ");
        $admin_login = $_SESSION['admin_login'] ?? ($_SESSION['admin_user']['login'] ?? null);
        $st->execute([
            'cislo' => $cislo,
            'ob'    => $odberatel_id,
            'bd'    => $bezDph,
            'd'     => $dphSum,
            'c'     => $celkem,
            'stav'  => $stav,
            'pt'    => $pos_typ,
            'pp'    => $pos_payment,
            'tip'   => $pos_tip,
            'uziv'  => $admin_login,
            'poz'   => $poznamka . ($poznamka ? "\n\n" : '') . "POS-META: " . $meta_json,
        ]);
        $objId = (int)$pdo->lastInsertId();

        // Položky
        $stItem = $pdo->prepare("
            INSERT INTO objednavky_polozky
                (objednavka_id, vyrobek_id, vyrobek_nazev, jednotka, mnozstvi, cena_bez_dph, sazba_dph)
            VALUES
                (:o, :vid, :n, :j, :m, :c, :s)
        ");
        foreach ($polozky as $p) {
            $stItem->execute([
                'o'   => $objId,
                'vid' => isset($p['vyrobek_id']) ? (int)$p['vyrobek_id'] : null,
                'n'   => substr((string)($p['nazev'] ?? '—'), 0, 200),
                'j'   => substr((string)($p['jednotka'] ?? 'ks'), 0, 10),
                'm'   => (float)($p['mnozstvi']     ?? 1),
                'c'   => (float)($p['cena_bez_dph'] ?? 0),
                's'   => (float)($p['sazba_dph']    ?? 21),
            ]);
        }

        $pdo->commit();

        // Notifikace
        try {
            if (function_exists('notifikace_nova_objednavka')) {
                notifikace_nova_objednavka($pdo, $objId);
            }
        } catch (Throwable $e) {}

        $response = [
            'ok'      => true,
            'id'      => $objId,
            'cislo'   => $cislo,
            'celkem'  => $celkem,
            'bez_dph' => $bezDph,
            'dph'     => $dphSum,
            'stav'    => $stav,
            'message' => 'POS objednávka vytvořena',
        ];

        // 🆕 Ulož pro idempotence (pokud klient poslal key)
        if ($idempKey !== '') {
            try {
                $pdo->prepare("
                    INSERT INTO pos_idempotency (idempotency_key, objednavka_id, response_json)
                    VALUES (:k, :oid, :r)
                    ON DUPLICATE KEY UPDATE response_json = :r2
                ")->execute([
                    'k'   => $idempKey,
                    'oid' => $objId,
                    'r'   => json_encode($response, JSON_UNESCAPED_UNICODE),
                    'r2'  => json_encode($response, JSON_UNESCAPED_UNICODE),
                ]);
                // Cleanup starých záznamů (>30 dnů) — async friendly, prevention overgrowth
                $pdo->exec("DELETE FROM pos_idempotency WHERE created_at < DATE_SUB(NOW(), INTERVAL 30 DAY)");
            } catch (Throwable $e) { /* soft-fail */ }
        }

        json_response($response);
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        json_error('Chyba vytvoření POS objednávky: ' . $e->getMessage(), 500);
    }
}

// 📜 GET ?action=quick_history — POS quick objednávky pro daný den (s detaily)
if ($method === 'GET' && $action === 'quick_history') {
    try {
        $date = $_GET['date'] ?? date('Y-m-d');
        $limit = max(10, min(200, (int)($_GET['limit'] ?? 50)));

        $st = $pdo->prepare("
            SELECT o.id, o.cislo, o.datum_objednani, o.castka_celkem, o.castka_bez_dph, o.castka_dph, o.stav,
                   o.pos_typ, o.pos_payment, o.pos_tip, o.pos_uzivatel, o.poznamka,
                   o.odberatel_id, COALESCE(od.nazev, '—') AS odberatel_nazev,
                   (SELECT COUNT(*) FROM objednavky_polozky p WHERE p.objednavka_id = o.id) AS pocet_polozek
            FROM objednavky o
            LEFT JOIN odberatele od ON od.id = o.odberatel_id
            WHERE o.puvod = 'pos' AND DATE(o.datum_objednani) = :d
            ORDER BY o.datum_objednani DESC
            LIMIT {$limit}
        ");
        $st->execute(['d' => $date]);
        $orders = $st->fetchAll(PDO::FETCH_ASSOC);

        // Souhrn dne
        $sum = $pdo->prepare("
            SELECT
                COUNT(*) AS pocet,
                COALESCE(SUM(castka_celkem), 0) AS tržby,
                COALESCE(SUM(pos_tip), 0) AS tip_sum,
                COALESCE(SUM(CASE WHEN pos_payment='hotove' THEN castka_celkem ELSE 0 END), 0) AS hotove,
                COALESCE(SUM(CASE WHEN pos_payment='karta'  THEN castka_celkem ELSE 0 END), 0) AS karta,
                COALESCE(SUM(CASE WHEN pos_typ='sebou'      THEN 1 ELSE 0 END), 0) AS pocet_sebou,
                COALESCE(SUM(CASE WHEN pos_typ='na_miste'   THEN 1 ELSE 0 END), 0) AS pocet_na_miste,
                COALESCE(SUM(CASE WHEN pos_typ='rozvoz'     THEN 1 ELSE 0 END), 0) AS pocet_rozvoz,
                COALESCE(SUM(CASE WHEN pos_typ='vyzvednuti' THEN 1 ELSE 0 END), 0) AS pocet_vyzvednuti
            FROM objednavky
            WHERE puvod = 'pos' AND DATE(datum_objednani) = :d
        ");
        $sum->execute(['d' => $date]);
        $souhrn = $sum->fetch(PDO::FETCH_ASSOC) ?: [];

        json_response([
            'ok'         => true,
            'date'       => $date,
            'objednavky' => $orders,
            'souhrn'     => $souhrn,
        ]);
    } catch (Throwable $e) {
        json_error('Chyba načtení historie: ' . $e->getMessage(), 500);
    }
}

json_error('Neznámá akce: ' . $action, 404);
