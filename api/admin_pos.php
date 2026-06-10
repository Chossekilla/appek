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
require_once __DIR__ . '/_sklad_lib.php';

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

/**
 * 🆕 v3.0.212 — Atomické číslo POS dokladu: POS-YYYYMMDD-NNNN.
 * Dine-in i rychlý prodej sdílí JEDEN denní čítač (cislovani typ='POS', rok=Ymd),
 * číslo NIKDY = ID účtu → konec kolizí v UNIQUE objednavky.cislo.
 */
function pos_next_doklad(PDO $pdo, ?string $ymd = null): string {
    $ymd = $ymd ?: date('Ymd');
    return dalsi_cislo($pdo, 'POS', (int) $ymd);
}

/**
 * 🆕 v3.0.212 — SQL fragment "puvod IN (...)" pro pokladní kanály (Účtenky/uzávěrka).
 * Klíče pocházejí z pevné sady kanaly_defaults() → sanitizováno, bezpečné do SQL.
 */
function pos_pokladni_sql(PDO $pdo, string $col = 'puvod'): string {
    $safe = [];
    foreach (kanaly_pokladni($pdo) as $k) {
        $k = preg_replace('/[^a-z0-9_]/', '', (string) $k);
        if ($k !== '') $safe[] = "'" . $k . "'";
    }
    if (!$safe) $safe = ["'pos'"];
    // 🐛 v3.0.214 PERF — single-value: '=' (optimalizátor vezme idx_puvod_datum range).
    //   Dříve i 1 hodnota → IN(...), a 2-hodnotový IN na POS-heavy DB padal do full scanu.
    if (count($safe) === 1) return $col . ' = ' . $safe[0];
    return $col . ' IN (' . implode(',', $safe) . ')';
}

function aktualni_admin(PDO $pdo): array {
    return [
        'id' => $_SESSION['admin_id'] ?? null,
        'jmeno' => $_SESSION['admin_jmeno'] ?? ($_SESSION['admin_email'] ?? 'staff'),
    ];
}

/**
 * 🆕 v3.0.156 — POS → kuchyně (KDS). Položky se vkládají do kitchen_queue se stanicí
 * a dobou přípravy načtenou z výrobku. Volá se SOFT-FAIL (try/catch na call-site), aby
 * výpadek kuchyně nikdy neshodil objednávku/účet.
 */
function kitchen_auto_fire(PDO $pdo): bool {
    try {
        $v = $pdo->query("SELECT auto_fire FROM kitchen_settings WHERE id=1")->fetchColumn();
        return $v === false ? true : (bool) $v; // default ZAP; chybí-li tabulka → catch → ne
    } catch (Throwable $e) { return false; }
}

function pos_kitchen_insert(PDO $pdo, array $items, ?int $objId, string $pozn): int {
    if (!$items) return 0;
    $ins = $pdo->prepare("INSERT INTO kitchen_queue
        (objednavka_id, polozka_id, station_id, vyrobek_nazev, mnozstvi, priprava_min, stav, cas_pridani, poznamka)
        VALUES (:o,:p,:s,:n,:m,:pm,'queued',NOW(),:pozn)");
    $vget = $pdo->prepare("SELECT priprava_min, kitchen_station_id FROM vyrobky WHERE id = :id");
    $n = 0;
    foreach ($items as $it) {
        $pm = 10; $sid = null;
        if (!empty($it['vyrobek_id'])) {
            $vget->execute(['id' => (int) $it['vyrobek_id']]);
            $v = $vget->fetch(PDO::FETCH_ASSOC);
            if ($v) {
                $pm  = (int) ($v['priprava_min'] ?? 10);
                $sid = ($v['kitchen_station_id'] ?? null) !== null ? (int) $v['kitchen_station_id'] : null;
            }
        }
        $ins->execute([
            'o' => $objId, 'p' => $it['polozka_id'] ?? null, 's' => $sid,
            'n' => $it['nazev'] ?? '?', 'm' => (float) ($it['mnozstvi'] ?? 1), 'pm' => $pm, 'pozn' => $pozn,
        ]);
        $n++;
    }
    return $n;
}

/** Vystřelí nevystřelené (ne-storno) položky stolního účtu do kuchyně. Idempotentní (dle polozka_id). Vrací počet. */
function pos_fire_ucet_to_kitchen(PDO $pdo, int $ucetId): int {
    // 🐛 fix v3.0.164 — dine-in položky se v kuchyni (Kapacita + KDS) zobrazují PŘÍMO
    // z restaurant_pos_polozky (viz admin_kitchen.php + admin_pos.php?action=kds).
    // Samostatná kitchen_queue se proto pro dine-in už neplní (dřív hromadila stale,
    // nesynchronizované řádky bez stanice). Položka je „v kuchyni" automaticky, jakmile
    // je na otevřeném účtu. No-op zachováno kvůli volajícím (auto-fire + ruční tlačítko
    // „Odeslat do kuchyně") → KDS pokryt. Rozvoz/s sebou se plní dál přes pos_kitchen_insert().
    //
    // 🆕 v3.0.203 — navíc rozešle kuchyňské bony na SÍŤOVÉ tiskárny stanic (pokud jsou
    //   nastavené v Nastavení → Tiskárny + pos_print_kitchen_mode != 'off'). Stoly tak tisknou
    //   per stanici jako takeaway. Soft-fail — tisk NIKDY neblokuje POS.
    try {
        require_once __DIR__ . '/_printer_lib.php';
        $mode = (string) setting_get($pdo, 'pos_print_kitchen_mode', 'auto');
        if ($mode !== 'off') {
            $ctx = [];
            try {
                $st = $pdo->prepare("SELECT t.nazev, u.otevrel_jmeno FROM restaurant_pos_ucty u JOIN restaurant_tables t ON t.id = u.stul_id WHERE u.id = :id");
                $st->execute(['id' => $ucetId]);
                if ($row = $st->fetch()) {
                    $ctx['stul_nazev']   = $row['nazev'] ?? ('Účet #' . $ucetId);
                    $ctx['cislo']        = (string) $ucetId;
                    if (!empty($row['otevrel_jmeno'])) $ctx['pos_uzivatel'] = $row['otevrel_jmeno'];
                }
            } catch (Throwable $e) {}
            $res = printer_dispatch_pos_ucet($pdo, $ucetId, $ctx);
            return is_array($res) ? count($res) : 0;
        }
    } catch (Throwable $e) { /* tisk nesmí blokovat POS */ }
    return 0;
}

/**
 * 🆕 v3.0.143 — POS prodej → odpis surovin dle receptury ze skladu.
 * Fix díry: POS/stolní prodej dříve neodepisoval suroviny (sklad ingrediencí nepřesný).
 *
 * @param array $items  [{vyrobek_id, mnozstvi}, ...] — prodané položky (jen s vyrobek_id + recepturou se odepíše)
 * @param string $label do poznámky pohybu (např. "účet #12" / "POS-2026...")
 * @return array ['deducted'=>počet surovin, 'items'=>[surovina_id=>mnozstvi]]
 *
 * Pozn.: povolí záporný stav (prodej se NEBLOKUJE — jídlo je už vydané). Soft-fail.
 * Idempotence: voláno 1× per uzavřený účet / vytvořenou quick objednávku.
 */
function pos_deduct_ingredients(PDO $pdo, array $items, string $label, string $kdo = 'POS'): array {
    // Agreguj potřebu surovin: SUM(recept.mnozstvi * prodané_mnozstvi) per surovina
    $need = [];   // surovina_id => mnozstvi
    $recStmt = $pdo->prepare("SELECT surovina_id, mnozstvi FROM vyrobek_suroviny WHERE vyrobek_id = :v");
    foreach ($items as $it) {
        $vid = (int) ($it['vyrobek_id'] ?? 0);
        $qty = (float) ($it['mnozstvi'] ?? 0);
        if ($vid <= 0 || $qty <= 0) continue;
        $recStmt->execute(['v' => $vid]);
        foreach ($recStmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $sid = (int) $r['surovina_id'];
            $need[$sid] = ($need[$sid] ?? 0) + (float) $r['mnozstvi'] * $qty;
        }
    }
    if (!$need) return ['deducted' => 0, 'items' => []];

    // 🐛 v3.0.168 — odpis ze systému B (sklad_polozky domovského skladu) + log sklad_pohyby_v2.
    // Běží post-commit (soft-fail per surovina). Atomický odečet `stav = stav - :mn`
    // (žádný read-then-write race) → stav_pred/po dopočítáme po odečtu.
    $done = 0;
    foreach ($need as $sid => $mn) {
        if ($mn <= 0) continue;
        try {
            $sid   = (int) $sid;
            $home  = surovina_home_sklad($pdo, $sid);
            $rowId = sklad_polozky_ensure($pdo, $home, 'surovina', $sid);
            $pdo->prepare("UPDATE sklad_polozky SET stav = stav - :mn WHERE id = :r")->execute(['mn' => $mn, 'r' => $rowId]);
            $po   = (float) $pdo->query("SELECT stav FROM sklad_polozky WHERE id = " . $rowId)->fetchColumn();
            $pred = $po + $mn;
            $pdo->prepare("INSERT INTO sklad_pohyby_v2 (sklad_id,item_typ,item_id,typ,mnozstvi,stav_pred,stav_po,poznamka,kdo,kdy)
                           VALUES (:s,'surovina',:i,'vydej',:mn,:pr,:po,:pz,:kdo,NOW())")
                ->execute(['s' => $home, 'i' => $sid, 'mn' => $mn, 'pr' => $pred, 'po' => $po, 'pz' => 'POS prodej — ' . $label, 'kdo' => $kdo]);
            surovina_recompute_total($pdo, $sid);
            $done++;
        } catch (Throwable $e) { /* soft-fail per surovina — prodej nesmí spadnout */ }
    }
    return ['deducted' => $done, 'items' => $need];
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

    // 🆕 v3.0.154 BUG E — nelze přidávat položky na uzavřený (ani neexistující) účet
    $uStav = $pdo->prepare("SELECT stav FROM restaurant_pos_ucty WHERE id = :id");
    $uStav->execute(['id' => $ucetId]);
    $uStavVal = $uStav->fetchColumn();
    if ($uStavVal === false) json_error('Účet neexistuje', 404);
    if (in_array($uStavVal, ['paid','cancelled','merged','split'], true)) json_error('Účet je uzavřený (' . $uStavVal . '), nelze přidávat položky', 409);

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

    // 🆕 v3.0.156 — auto-fire do kuchyně (KDS), pokud je v nastavení zapnuto. Soft-fail.
    $fired = 0;
    try { if (kitchen_auto_fire($pdo)) $fired = pos_fire_ucet_to_kitchen($pdo, $ucetId); }
    catch (Throwable $e) { /* kuchyně ne-fatal */ }

    json_response(['ok' => true, 'id' => $itemId, 'kitchen_fired' => $fired]);
}

// 🆕 v3.0.156 POST ?action=fire_kitchen → ručně vystřelí nevystřelené položky účtu do kuchyně (KDS)
//   Použití v ručním režimu (kitchen_settings.auto_fire = 0) tlačítkem v POS.
if ($method === 'POST' && $action === 'fire_kitchen') {
    $d = json_input();
    $ucetId = (int) ($d['ucet_id'] ?? 0);
    if (!$ucetId) json_error('Chybí ucet_id', 400);
    $stEx = $pdo->prepare("SELECT 1 FROM restaurant_pos_ucty WHERE id = :id");
    $stEx->execute(['id' => $ucetId]);
    if (!$stEx->fetchColumn()) json_error('Účet neexistuje', 404);
    try {
        $n = pos_fire_ucet_to_kitchen($pdo, $ucetId);
        json_response(['ok' => true, 'fired' => $n]);
    } catch (Throwable $e) {
        json_error_safe('Odeslání do kuchyně selhalo', $e, 500);
    }
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

// 🆕 v3.0.204 — změna množství položky otevřeného účtu (+/− přímo v účtu stolu)
if ($method === 'POST' && $action === 'item_qty') {
    $d = json_input();
    $itemId   = (int) ($d['id'] ?? 0);
    $mnozstvi = (float) ($d['mnozstvi'] ?? 0);
    if (!$itemId) json_error('Chybí id', 400);
    if ($mnozstvi < 0.01) json_error('Množství musí být ≥ 0.01', 400);
    $q = $pdo->prepare("
        SELECT p.ucet_id, p.stav AS p_stav, u.stav AS u_stav
        FROM restaurant_pos_polozky p JOIN restaurant_pos_ucty u ON u.id = p.ucet_id
        WHERE p.id = :id
    ");
    $q->execute(['id' => $itemId]);
    $row = $q->fetch();
    if (!$row) json_error('Položka nenalezena', 404);
    if (($row['u_stav'] ?? '') !== 'open') json_error('Účet je uzavřený — množství nelze měnit', 409);
    if (($row['p_stav'] ?? '') === 'storno') json_error('Stornovanou položku nelze měnit', 409);
    $pdo->prepare("UPDATE restaurant_pos_polozky SET mnozstvi = :m WHERE id = :id")->execute(['m' => $mnozstvi, 'id' => $itemId]);
    recalc_ucet_total($pdo, (int) $row['ucet_id']);
    json_response(['ok' => true, 'mnozstvi' => $mnozstvi]);
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
    // 🆕 v3.0.206 — fallback „zaplatit a zavřít" jedním klikem: když volající nepošle pole `platby`
    //   (jen `payment`/`zpusob`), vytvoř jednu platbu na CELOU částku účtu. Fix: floor-plan POS
    //   (admin/pos.js posTableCloseUcet) posílal `payment:'hotove'` → pay vracel 400 → modal se
    //   nezavřel (účtenka se přesto otevřela). Zpětně kompatibilní s callery, co posílají `platby`.
    if (!is_array($platby) || empty($platby)) {
        if ($ucetId) {
            $tot = (float) $pdo->query("SELECT suma_kc FROM restaurant_pos_ucty WHERE id = " . (int)$ucetId)->fetchColumn();
            $zp  = (string) ($d['payment'] ?? $d['zpusob'] ?? 'hotove');
            if ($tot > 0) $platby = [['zpusob' => $zp, 'castka' => round($tot, 2)]];
        }
    }
    if (!$ucetId || !is_array($platby) || empty($platby)) json_error('Chybí ucet_id nebo platby', 400);

    // 🆕 v3.0.154 BUG F — žádná platba ≤ 0 (záporná/nulová částka korumpuje tržbu)
    foreach ($platby as $p) {
        if ((float) ($p['castka'] ?? 0) <= 0) json_error('Částka platby musí být kladná', 400);
    }

    try {
        $pdo->beginTransaction();
        // 🆕 v3.0.154 BUG D — zamkni účet + ověř, že není už uzavřený (double-pay → duplicitní tržba)
        $lock = $pdo->prepare("SELECT stav FROM restaurant_pos_ucty WHERE id = :id FOR UPDATE");
        $lock->execute(['id' => $ucetId]);
        $payStav = $lock->fetchColumn();
        if ($payStav === false) { $pdo->rollBack(); json_error('Účet neexistuje', 404); }
        if (!in_array($payStav, ['open','awaiting_payment'], true)) { $pdo->rollBack(); json_error('Účet už je uzavřený (' . $payStav . ')', 409); }
        // Insert platby
        $sumPaid = 0;
        // 🆕 v3.0.212 — atomický denní čítač (ne ID účtu) → konec kolizí s rychlým prodejem
        $cislo = pos_next_doklad($pdo);
        $st = $pdo->prepare("
            INSERT INTO restaurant_pos_platby (ucet_id, castka, zpusob, doklad_cislo, poznamka)
            VALUES (:u, :c, :z, :d, :p)
        ");
        // 🆕 v3.0.206 — normalizace způsobu platby na ENUM(hotovost,karta,qr,online,poukaz,prevod).
        //   Klient (POS) posílá 'hotove' → dřív „Data truncated for column 'zpusob'" → celá platba
        //   spadla (500) a modal se nezavřel. Mapuje aliasy, neznámé → 'hotovost' (nikdy netruncuje).
        $ZPUSOB_MAP = [
            'hotove' => 'hotovost', 'hotovost' => 'hotovost', 'cash' => 'hotovost', 'hotove_platba' => 'hotovost',
            'karta' => 'karta', 'card' => 'karta', 'terminal' => 'karta',
            'qr' => 'qr', 'online' => 'online', 'poukaz' => 'poukaz', 'voucher' => 'poukaz',
            'prevod' => 'prevod', 'faktura' => 'prevod', 'bankovni_prevod' => 'prevod',
        ];
        foreach ($platby as $p) {
            $c = (float) ($p['castka'] ?? 0);
            $zRaw = strtolower(trim((string) ($p['zpusob'] ?? 'hotovost')));
            $z = $ZPUSOB_MAP[$zRaw] ?? 'hotovost';
            $st->execute(['u' => $ucetId, 'c' => $c, 'z' => $z, 'd' => $cislo, 'p' => $p['poznamka'] ?? null]);
            $sumPaid += $c;
        }
        // Update ucet
        $pdo->prepare("
            UPDATE restaurant_pos_ucty
            SET stav = 'paid', zaplaceno_v = NOW(), suma_zaplaceno = :s, cislo_dokladu = :d
            WHERE id = :id
        ")->execute(['s' => $sumPaid, 'd' => $cislo, 'id' => $ucetId]);

        // 🆕 v3.0.145 — finalizuj nedovařené položky (nápoje apod.) na 'servirovano' při platbě.
        //   Předtím zůstávaly 'objednano' navždy (KDS je sice skrývá, ale data-hygiene).
        $pdo->prepare("UPDATE restaurant_pos_polozky SET stav = 'servirovano'
            WHERE ucet_id = :u AND stav IN ('objednano','vari_se','hotovo')")
            ->execute(['u' => $ucetId]);

        // Get stul_id pak uvolni
        $st = $pdo->prepare("SELECT stul_id FROM restaurant_pos_ucty WHERE id = :id");
        $st->execute(['id' => $ucetId]);
        $stulId = (int) $st->fetchColumn();

        // Check if other open ucet for this stůl (např. merged variant)
        $o = $pdo->prepare("SELECT COUNT(*) FROM restaurant_pos_ucty WHERE stul_id = :s AND stav = 'open'");
        $o->execute(['s' => $stulId]);
        $hasOther = (int) $o->fetchColumn() > 0;

        if (!$hasOther && $stulId) {
            // 🆕 v3.0.189 — po zaplacení stůl rovnou UVOLNI (free), ne 'cleaning'.
            //   User: „zaplatit a zavřít" → stůl má být zavřený/volný hned, ne viset v úklidu.
            $pdo->prepare("
                UPDATE restaurant_tables
                SET stav = 'free', stav_od = NULL, hostu_aktual = 0, obsluhuje = NULL
                WHERE id = :id
            ")->execute(['id' => $stulId]);
        }
        $pdo->commit();

        // 🆕 v3.0.143 — Odpis surovin dle receptury za prodané (ne-storno) položky účtu.
        //   Po commitu + soft-fail (vlastní transakce uvnitř) → platba nikdy nespadne kvůli skladu.
        try {
            $polUcet = $pdo->prepare("SELECT vyrobek_id, mnozstvi FROM restaurant_pos_polozky WHERE ucet_id = :u AND stav != 'storno' AND vyrobek_id IS NOT NULL");
            $polUcet->execute(['u' => $ucetId]);
            $sold = $polUcet->fetchAll(PDO::FETCH_ASSOC);
            if ($sold) pos_deduct_ingredients($pdo, $sold, 'účet #' . $ucetId, aktualni_admin($pdo)['jmeno']);
        } catch (Throwable $e) { /* sklad odpis ne-fatal */ }

        // 🆕 v3.0.211 — prodejní záznam (objednávka puvod='pos') z paid účtu → Účtenky/Statistiky/Přehledy.
        try { pos_ucet_create_sale($pdo, $ucetId); } catch (Throwable $e) { /* reporting ne-fatal */ }

        json_response(['ok' => true, 'doklad' => $cislo, 'sum_paid' => $sumPaid]);
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        json_error_safe('Platba selhala', $e, 500);
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
        // 🐛 v3.0.196 — nedělit UZAVŘENÝ účet. Dřív split na 'paid' vytvořil otevřené
        //   pod-účty z už zaplacených položek → šly zaplatit znovu (dvojí tržba).
        //   Odhaleno race testem. Jen 'open' lze dělit.
        if (($orig['stav'] ?? '') !== 'open') { $pdo->rollBack(); json_error('Účet je uzavřený (' . ($orig['stav'] ?? '?') . ') — nelze rozdělit', 409); }

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
        json_error_safe('Split selhal', $e, 500);
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
        // 🐛 v3.0.196 — slučovat lze jen OTEVŘENÉ účty (target i zdroje). Dřív šly
        //   re-linkovat položky z už zaplaceného účtu → korupce tržby. Odhaleno race testem.
        $allIds = array_merge([$target], $sources);
        $ph0 = implode(',', array_fill(0, count($allIds), '?'));
        $chk = $pdo->prepare("SELECT COUNT(*) FROM restaurant_pos_ucty WHERE id IN ($ph0) AND stav = 'open'");
        $chk->execute($allIds);
        if ((int) $chk->fetchColumn() !== count($allIds)) { $pdo->rollBack(); json_error('Sloučit lze jen otevřené účty (některý je uzavřený)', 409); }
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
        json_error_safe('Merge selhal', $e, 500);
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
        $old = $pdo->prepare("SELECT stul_id, stav FROM restaurant_pos_ucty WHERE id = :id");
        $old->execute(['id' => $ucetId]);
        $oldRow = $old->fetch();
        // 🐛 v3.0.196 — přesouvat lze jen OTEVŘENÝ účet (ne paid/merged/split). Odhaleno race testem.
        if (!$oldRow) { $pdo->rollBack(); json_error('Účet nenalezen', 404); }
        if (($oldRow['stav'] ?? '') !== 'open') { $pdo->rollBack(); json_error('Účet je uzavřený (' . ($oldRow['stav'] ?? '?') . ') — nelze přesunout', 409); }
        // 🐛 v3.0.217 — cílový stůl musí existovat/být aktivní a NESMÍ mít jiný otevřený účet.
        //   Jinak vzniknou 2 open účty na 1 stole → `ucet` GET vrátí jen poslední → první se skryje
        //   z mapy = obsluha o něm neví → ztracená/nezaplacená tržba.
        $tgt = $pdo->prepare("SELECT COALESCE(aktivni,1) FROM restaurant_tables WHERE id = :id");
        $tgt->execute(['id' => $novyStulId]);
        $tgtAkt = $tgt->fetchColumn();
        if ($tgtAkt === false) { $pdo->rollBack(); json_error('Cílový stůl neexistuje', 404); }
        if ((int) $tgtAkt === 0) { $pdo->rollBack(); json_error('Cílový stůl není aktivní', 409); }
        $busy = $pdo->prepare("SELECT COUNT(*) FROM restaurant_pos_ucty WHERE stul_id = :s AND stav = 'open' AND id <> :u");
        $busy->execute(['s' => $novyStulId, 'u' => $ucetId]);
        if ((int) $busy->fetchColumn() > 0) { $pdo->rollBack(); json_error('Cílový stůl má otevřený účet — nejdřív účty slučte (merge)', 409); }
        $oldStul = (int) $oldRow['stul_id'];
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
        json_error_safe('Move selhal', $e, 500);
    }
}

// 👨‍🍳 GET ?action=kds → kitchen display data (všechny aktivní položky)
if ($method === 'GET' && $action === 'kds') {
    $stmt = $pdo->query("
        SELECT p.*, p.nazev AS vyrobek_nazev, u.stul_id, u.id AS ucet_id,
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
        json_error_safe('Approve selhal', $e, 500);
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
        // 🆕 v3.0.213 — skryj výrobky vypnuté pro POS (zobrazit_na_pos=0). Guard: jen pokud sloupec existuje.
        $posFilter = in_array('zobrazit_na_pos', $vyrCols, true) ? "AND COALESCE(v.zobrazit_na_pos, 1) = 1" : "";

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
            WHERE COALESCE(v.aktivni, 1) = 1 {$posFilter}
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
        json_error_safe('Chyba načtení katalogu', $e, 500);
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
        json_error_safe('Chyba načtení zákazníků', $e, 500);
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
    // 🆕 v3.0.160 — sjednoceno se zdrojem pravdy payment_methods.php (dřív chybělo 'qr_platba'
    //   → POS ji nabídl, ale quick_order ji odmítl 400; 'paypal' byl naopak b2b-only).
    //   Celý katalog metod → žádná zapnutá metoda nepadne na „Neplatná platební metoda".
    $allowed_payments = ['hotove', 'karta', 'qr_platba', 'gift_card', 'voucher', 'mobile',
                         'paypal', 'stripe', 'gopay', 'prevod', 'dobirka', 'faktura'];
    if (!in_array($pos_typ, $allowed_typy, true))         json_error('Neplatný typ objednávky', 400);
    if (!in_array($pos_payment, $allowed_payments, true)) json_error('Neplatná platební metoda', 400);
    if (empty($polozky) || !is_array($polozky))           json_error('Prázdný košík', 400);

    // 🐛 v2.9.313 — Input bounds (DoS / overflow guard). Předtím se přijal libovolný tip
    // (1e18 by přetekl DECIMAL(10,2)) nebo 100k položek (DoS přes 100k INSERTů v transakci).
    if (count($polozky) > 200)                            json_error('Max 200 položek na účtenku', 400);
    if ($pos_tip < 0 || $pos_tip > 100000)                json_error('Spropitné mimo rozsah (0–100000)', 400);
    if ($sleva_pct < 0 || $sleva_pct > 100)               json_error('Sleva mimo rozsah (0–100%)', 400);
    // Per-položka validace (záporné množství by kombinací se slevou udělalo větší slevu než 100%)
    foreach ($polozky as $i => $p) {
        $mn = (float)($p['mnozstvi'] ?? 0);
        $cena = (float)($p['cena_bez_dph'] ?? 0);
        if ($mn <= 0 || $mn > 9999)                       json_error('Položka #' . ($i + 1) . ': množství mimo rozsah (0–9999)', 400);
        // 🆕 v3.0.153 BUG A — záporná cena dřív prošla (abs() validoval jen velikost) → záporná tržba
        if ($cena < 0 || $cena > 1000000)                 json_error('Položka #' . ($i + 1) . ': cena mimo rozsah (0–1000000)', 400);
    }
    // 🆕 v3.0.153 BUG B — ověř existenci vyrobek_id PŘED insertem (jinak FK violation → 500 + leak schématu)
    $reqVids = [];
    foreach ($polozky as $p) { if (!empty($p['vyrobek_id'])) $reqVids[(int) $p['vyrobek_id']] = true; }
    if ($reqVids) {
        $ids = array_keys($reqVids);
        $ph = implode(',', array_fill(0, count($ids), '?'));
        $stEx = $pdo->prepare("SELECT id FROM vyrobky WHERE id IN ($ph)");
        $stEx->execute($ids);
        $missing = array_values(array_diff($ids, array_map('intval', $stEx->fetchAll(PDO::FETCH_COLUMN))));
        if ($missing) json_error('Neznámý výrobek (id ' . implode(', ', $missing) . ')', 400);
    }

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

        // 🆕 v3.0.212 — atomický denní čítač (dine-in i rychlý prodej sdílí cislovani 'POS').
        //   Dříve MAX+1 nad objednavky kolidoval s dine-in číslem (= ID účtu) → přebíjení.
        $today = date('Ymd');
        $cislo = pos_next_doklad($pdo);

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
        $headerParams = [
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
        ];
        // 🆕 v3.0.212 — číslo z atomického čítače (pos_next_doklad). Retry je už jen pojistka:
        //   na nepravděpodobnou duplicitu vezme další atomické číslo (dříve MAX+1 race ~2%).
        $objId = 0;
        for ($attempt = 0; $attempt < 8; $attempt++) {
            try {
                $st->execute(array_merge(['cislo' => $cislo], $headerParams));
                $objId = (int)$pdo->lastInsertId();
                break;
            } catch (PDOException $e) {
                $dup = ($e->getCode() === '23000') || (isset($e->errorInfo[1]) && (int)$e->errorInfo[1] === 1062);
                if ($dup && $attempt < 7) {
                    // atomický čítač by neměl kolidovat; pro jistotu vezmi další číslo
                    $cislo = pos_next_doklad($pdo);
                    usleep(mt_rand(500, 4000));
                    continue;
                }
                throw $e;
            }
        }

        // Položky
        $stItem = $pdo->prepare("
            INSERT INTO objednavky_polozky
                (objednavka_id, vyrobek_id, vyrobek_nazev, jednotka, mnozstvi, cena_bez_dph, sazba_dph)
            VALUES
                (:o, :vid, :n, :j, :m, :c, :s)
        ");
        // Pre-discount totals (před aplikací slevy) — pro výpočet sleva polozky
        $bezDph_orig = 0.0;
        $dphSum_orig = 0.0;
        foreach ($polozky as $p) {
            $mn   = (float)($p['mnozstvi']     ?? 1);
            $cena = (float)($p['cena_bez_dph'] ?? 0);
            $saz  = (float)($p['sazba_dph']    ?? 21);
            $bezDph_orig += $mn * $cena;
            $dphSum_orig += $mn * $cena * ($saz / 100);
            $stItem->execute([
                'o'   => $objId,
                'vid' => isset($p['vyrobek_id']) ? (int)$p['vyrobek_id'] : null,
                'n'   => substr((string)($p['nazev'] ?? '—'), 0, 200),
                'j'   => substr((string)($p['jednotka'] ?? 'ks'), 0, 10),
                'm'   => $mn,
                'c'   => $cena,
                's'   => $saz,
            ]);
        }

        // 🆕 v2.9.309 — sleva + spropitné JAKO POLOZKY (vidí je admin v detailu objednávky)
        // Důvod: user "nepropisuje volný řádek-korkovné-sleva-diško do objednávek v adminu"
        // Vlastní položky (Korkovné apod.) už šly přes hlavní foreach (vyrobek_id=null).
        // Sleva + tip se ukládaly jen do POS-META poznámky → admin je neviděl jako řádky.
        // Teď je vložíme jako pseudo-polozky s vyrobek_id=NULL a popisným názvem.
        if ($sleva_pct > 0 && $sleva_pct <= 100) {
            $sleva_celkem = round(($bezDph_orig + $dphSum_orig) * ($sleva_pct / 100), 2);
            $stItem->execute([
                'o'   => $objId,
                'vid' => null,
                'n'   => sprintf('💰 Sleva %s%%', rtrim(rtrim(number_format($sleva_pct, 1, '.', ''), '0'), '.')),
                'j'   => 'ks',
                'm'   => 1,
                'c'   => -$sleva_celkem, // záporná cena
                's'   => 0,              // sleva v plné výši (bez DPH split)
            ]);
        }
        if ($pos_tip > 0) {
            $stItem->execute([
                'o'   => $objId,
                'vid' => null,
                'n'   => '🎁 Spropitné',
                'j'   => 'ks',
                'm'   => 1,
                'c'   => $pos_tip,
                's'   => 0,
            ]);
        }

        $pdo->commit();

        // 🆕 v3.0.143 — Odpis surovin dle receptury za walk-in/rozvoz prodej (po commitu, soft-fail).
        try {
            $sold = [];
            foreach ($polozky as $p) {
                if (!empty($p['vyrobek_id'])) $sold[] = ['vyrobek_id' => (int) $p['vyrobek_id'], 'mnozstvi' => (float) ($p['mnozstvi'] ?? 1)];
            }
            if ($sold) pos_deduct_ingredients($pdo, $sold, $cislo, 'POS');
        } catch (Throwable $e) { /* sklad odpis ne-fatal */ }

        // 🆕 v3.0.156 — auto-fire do kuchyně (KDS): s sebou/rozvoz = hotová objednávka → vždy poslat
        //   (jen reálné výrobky s vyrobek_id; sleva/tip/free-text se nevystřelují). Po commitu, soft-fail.
        try {
            if (kitchen_auto_fire($pdo)) {
                $kitchenItems = [];
                foreach ($polozky as $p) {
                    if (!empty($p['vyrobek_id'])) $kitchenItems[] = [
                        'vyrobek_id' => (int) $p['vyrobek_id'],
                        'nazev'      => $p['nazev'] ?? '?',
                        'mnozstvi'   => (float) ($p['mnozstvi'] ?? 1),
                        'polozka_id' => null,
                    ];
                }
                $poznKit = ($pos_typ === 'rozvoz' ? '🛵 Rozvoz' : '🥡 S sebou') . ' · ' . $cislo;
                pos_kitchen_insert($pdo, $kitchenItems, $objId, $poznKit);
            }
        } catch (Throwable $e) { /* kuchyně ne-fatal */ }

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
                // 🆕 v2.9.277 — Rate-limited cleanup (max 1× za hodinu)
                // Předtím se DELETE spouštělo při každém POS requestu (perf hit při peak).
                // Teď držíme last_run timestamp v nastaveni a skipneme pokud <60min.
                try {
                    $lastRun = (int) $pdo->query("SELECT hodnota FROM nastaveni WHERE klic = 'pos_idemp_cleanup_at'")->fetchColumn();
                    $now = time();
                    if (!$lastRun || ($now - $lastRun) >= 3600) {
                        $pdo->exec("DELETE FROM pos_idempotency WHERE created_at < DATE_SUB(NOW(), INTERVAL 30 DAY)");
                        $pdo->prepare("
                            INSERT INTO nastaveni (klic, hodnota) VALUES ('pos_idemp_cleanup_at', :v)
                            ON DUPLICATE KEY UPDATE hodnota = :v2
                        ")->execute(['v' => $now, 'v2' => $now]);
                    }
                } catch (Throwable $e) { /* soft-fail */ }
            } catch (Throwable $e) { /* soft-fail */ }
        }

        // 🆕 v3.0.5 — Auto-dispatch bonů na kuchyň/bar/sklad podle kategorie
        //   pos_print_kitchen_mode: 'auto' (default) | 'manual' | 'off'
        //   Soft-fail (tisk se nesmí dotknout user-facing flow)
        try {
            require_once __DIR__ . '/_printer_lib.php';
            $kitchen_mode = (string) setting_get($pdo, 'pos_print_kitchen_mode', 'auto');
            if ($kitchen_mode === 'auto') {
                $context = [
                    'cislo'         => $cislo,
                    'pos_uzivatel'  => $admin_login,
                    'poznamka'      => $poznamka,
                ];
                $dispatch = printer_dispatch_order($pdo, $objId, $context);
                $response['printer_dispatch'] = $dispatch;
            }
            // Vrátíme klientovi co dělat s účtenkou
            $response['print_receipt_mode'] = (string) setting_get($pdo, 'pos_print_receipt_mode', 'ask');
        } catch (Throwable $e) {
            // Soft-fail — tisk nesmí blokovat POS
            $response['printer_dispatch_error'] = $e->getMessage();
        }

        json_response($response);
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        json_error_safe('Chyba vytvoření POS objednávky', $e, 500);
    }
}

// 🆕 v3.0.5 POST ?action=print_receipt { objednavka_id } — vytištění účtenky na kasa
if ($method === 'POST' && $action === 'print_receipt') {
    require_once __DIR__ . '/_printer_lib.php';
    $d = json_decode(file_get_contents('php://input'), true) ?? [];
    $id = (int)($d['objednavka_id'] ?? $d['id'] ?? 0);
    if (!$id) json_error('Chybí objednavka_id', 400);
    $res = printer_print_receipt($pdo, $id);
    json_response($res);
}

// 📜 GET ?action=quick_history — POS quick objednávky pro daný den (s detaily)
if ($method === 'GET' && $action === 'quick_history') {
    try {
        $date = $_GET['date'] ?? date('Y-m-d');
        $limit = max(10, min(200, (int)($_GET['limit'] ?? 50)));
        $offset = max(0, (int)($_GET['offset'] ?? 0)); // 🆕 v3.0.219 — paging Účtenek
        pos_backfill_sales($pdo, $date); // 🆕 v3.0.211 — dine-in paid účty → objednávky, ať jsou v Účtenkách/Statistikách
        // 🚀 v2.9.314 — range scan místo DATE(datum_objednani)=:d. Předtím DATE() wrap
        // znemožnil použití idx_puvod_datum (puvod, datum_objednani) → full table scan.
        // Teď klasický range — index funguje, 5-50× rychlejší při >10k řádků/měsíc.
        $date_next = date('Y-m-d', strtotime($date . ' +1 day'));
        $pokIn  = pos_pokladni_sql($pdo);          // 🆕 v3.0.212 — puvod IN (pokladní kanály)
        $pokInO = pos_pokladni_sql($pdo, 'o.puvod');

        $st = $pdo->prepare("
            SELECT o.id, o.cislo, o.datum_objednani, o.castka_celkem, o.castka_bez_dph, o.castka_dph, o.stav,
                   o.pos_typ, o.pos_payment, o.pos_tip, o.pos_uzivatel, o.poznamka,
                   o.odberatel_id, COALESCE(od.nazev, '—') AS odberatel_nazev,
                   (SELECT COUNT(*) FROM objednavky_polozky p WHERE p.objednavka_id = o.id) AS pocet_polozek
            FROM objednavky o
            LEFT JOIN odberatele od ON od.id = o.odberatel_id
            WHERE {$pokInO} AND o.datum_objednani >= :d AND o.datum_objednani < :d_next
            ORDER BY o.datum_objednani DESC
            LIMIT {$limit} OFFSET {$offset}
        ");
        $st->execute(['d' => $date, 'd_next' => $date_next]);
        $orders = $st->fetchAll(PDO::FETCH_ASSOC);

        // Souhrn dne — 🐛 v3.0.230: částky (tržby/tip/hotově/karta) NEPOČÍTAJÍ zrušené;
        // počet a typy zůstávají vč. zrušených (provozní přehled, storno je vidět v seznamu).
        $sum = $pdo->prepare("
            SELECT
                COUNT(*) AS pocet,
                COALESCE(SUM(CASE WHEN stav <> 'zrusena' THEN castka_celkem ELSE 0 END), 0) AS tržby,
                COALESCE(SUM(CASE WHEN stav <> 'zrusena' THEN pos_tip ELSE 0 END), 0) AS tip_sum,
                COALESCE(SUM(CASE WHEN pos_payment='hotove' AND stav <> 'zrusena' THEN castka_celkem ELSE 0 END), 0) AS hotove,
                COALESCE(SUM(CASE WHEN pos_payment='karta'  AND stav <> 'zrusena' THEN castka_celkem ELSE 0 END), 0) AS karta,
                COALESCE(SUM(CASE WHEN pos_typ='sebou'      THEN 1 ELSE 0 END), 0) AS pocet_sebou,
                COALESCE(SUM(CASE WHEN pos_typ='na_miste'   THEN 1 ELSE 0 END), 0) AS pocet_na_miste,
                COALESCE(SUM(CASE WHEN pos_typ='rozvoz'     THEN 1 ELSE 0 END), 0) AS pocet_rozvoz,
                COALESCE(SUM(CASE WHEN pos_typ='vyzvednuti' THEN 1 ELSE 0 END), 0) AS pocet_vyzvednuti
            FROM objednavky
            WHERE {$pokIn} AND datum_objednani >= :d AND datum_objednani < :d_next
        ");
        $sum->execute(['d' => $date, 'd_next' => $date_next]);
        $souhrn = $sum->fetch(PDO::FETCH_ASSOC) ?: [];

        $total = (int) ($souhrn['pocet'] ?? count($orders));
        json_response([
            'ok'         => true,
            'date'       => $date,
            'objednavky' => $orders,
            'souhrn'     => $souhrn,
            'total'      => $total,                                  // 🆕 v3.0.219 — paging
            'offset'     => $offset,
            'limit'      => $limit,
            'has_more'   => ($offset + count($orders)) < $total,
        ]);
    } catch (Throwable $e) {
        json_error_safe('Chyba načtení historie', $e, 500);
    }
}

// 🆕 v3.0.210 — DENNÍ UZÁVĚRKA na stanice (= obsluha). Sčítá OBA zdroje POS prodeje:
//   dine-in (restaurant_pos_ucty paid + restaurant_pos_platby, obsluha=otevrel_jmeno)
//   + takeaway (objednavky puvod='pos', obsluha=pos_uzivatel, pos_payment).
// 🆕 v3.0.211 — dine-in účet (paid) → objednávka (puvod='pos'), aby prodej tekl do
//   Účtenek / Statistik / launcheru / detailu (jednotná POS pipeline). Dřív dine-in žil
//   jen v restaurant_pos_ucty → v Účtenkách/Historii „nic". Idempotentní (marker
//   [POS účet #X] v poznámce). Volá pay (forward) + pos_backfill_sales (self-heal).
function pos_ucet_create_sale(PDO $pdo, int $ucetId): ?int {
    try {
        $u = $pdo->prepare("SELECT u.*, t.nazev AS stul_nazev FROM restaurant_pos_ucty u LEFT JOIN restaurant_tables t ON t.id = u.stul_id WHERE u.id = :id");
        $u->execute(['id' => $ucetId]);
        $ucet = $u->fetch(PDO::FETCH_ASSOC);
        if (!$ucet || ($ucet['stav'] ?? '') !== 'paid') return null;
        $marker = '[POS účet #' . $ucetId . ']';
        $ex = $pdo->prepare("SELECT id FROM objednavky WHERE puvod='pos' AND poznamka LIKE :m LIMIT 1");
        $ex->execute(['m' => '%' . $marker . '%']);
        $had = $ex->fetchColumn();
        if ($had) return (int) $had;
        $pol = $pdo->prepare("SELECT vyrobek_id, nazev, mnozstvi, jednotkova_cena FROM restaurant_pos_polozky WHERE ucet_id = :u AND stav <> 'storno'");
        $pol->execute(['u' => $ucetId]);
        $items = $pol->fetchAll(PDO::FETCH_ASSOC);
        if (!$items) return null;
        $celkem = 0.0;
        foreach ($items as $it) $celkem += (float) $it['mnozstvi'] * (float) $it['jednotkova_cena'];
        $celkem = round($celkem, 2);
        $bez = round($celkem / 1.21, 2); $dph = round($celkem - $bez, 2);
        $zp = $pdo->prepare("SELECT zpusob FROM restaurant_pos_platby WHERE ucet_id = :u ORDER BY castka DESC LIMIT 1");
        $zp->execute(['u' => $ucetId]);
        $zpusob = strtolower((string) ($zp->fetchColumn() ?: 'hotovost'));
        $payMap = ['hotovost'=>'hotove','karta'=>'karta','qr'=>'karta','online'=>'karta','prevod'=>'karta','poukaz'=>'hotove'];
        $pos_payment = $payMap[$zpusob] ?? 'hotove';
        $walkin = $pdo->query("SELECT id FROM odberatele WHERE nazev = 'POS Walk-in' LIMIT 1")->fetchColumn();
        if (!$walkin) { $pdo->exec("INSERT INTO odberatele (nazev, aktivni) VALUES ('POS Walk-in', 1)"); $walkin = $pdo->lastInsertId(); }
        $datum = $ucet['zaplaceno_v'] ?: date('Y-m-d H:i:s');
        $cislo = $ucet['cislo_dokladu'] ?: pos_next_doklad($pdo, date('Ymd', strtotime($datum)));
        $poz = '🍽️ ' . ($ucet['stul_nazev'] ?? ('Stůl #' . $ucet['stul_id'])) . ' ' . $marker;
        $ins = $pdo->prepare("
            INSERT INTO objednavky (cislo, typ, odberatel_id, datum_objednani, datum_dodani, castka_bez_dph, castka_dph, castka_celkem, stav, puvod, pos_typ, pos_payment, pos_tip, pos_uzivatel, poznamka)
            VALUES (:c, 'pos', :ob, :dt, :dd, :bd, :d, :cel, 'zaplaceno', 'pos', 'na_miste', :pp, 0, :uz, :poz)
        ");
        $objId = 0;
        for ($a = 0; $a < 5; $a++) {
            try {
                $ins->execute(['c'=>$cislo,'ob'=>(int)$walkin,'dt'=>$datum,'dd'=>date('Y-m-d', strtotime($datum)),'bd'=>$bez,'d'=>$dph,'cel'=>$celkem,'pp'=>$pos_payment,'uz'=>($ucet['otevrel_jmeno'] ?: 'POS'),'poz'=>$poz]);
                $objId = (int) $pdo->lastInsertId(); break;
            } catch (PDOException $e) {
                if ((int)($e->errorInfo[1] ?? 0) === 1062 && $a < 4) { $cislo .= '-' . ($a + 2); continue; }
                throw $e;
            }
        }
        if (!$objId) return null;
        $ip = $pdo->prepare("INSERT INTO objednavky_polozky (objednavka_id, vyrobek_id, vyrobek_nazev, mnozstvi, jednotka, cena_bez_dph, sazba_dph, poznamka) VALUES (:o,:v,:n,:m,'ks',:c,21,NULL)");
        foreach ($items as $it) {
            $cbez = round(((float) $it['jednotkova_cena']) / 1.21, 4);
            $ip->execute(['o'=>$objId,'v'=>($it['vyrobek_id'] ?: null),'n'=>$it['nazev'],'m'=>$it['mnozstvi'],'c'=>$cbez]);
        }
        return $objId;
    } catch (Throwable $e) { error_log('pos_ucet_create_sale #' . $ucetId . ': ' . $e->getMessage()); return null; }
}
// Self-heal: pro daný den dotvoř chybějící prodejní záznamy z paid dine-in účtů (idempotentní).
function pos_backfill_sales(PDO $pdo, string $date): void {
    try {
        $dn = date('Y-m-d', strtotime($date . ' +1 day'));
        $ids = $pdo->prepare("SELECT id FROM restaurant_pos_ucty WHERE stav='paid' AND zaplaceno_v >= :d AND zaplaceno_v < :dn");
        $ids->execute(['d' => $date . ' 00:00:00', 'dn' => $dn . ' 00:00:00']);
        foreach ($ids->fetchAll(PDO::FETCH_COLUMN) as $uid) pos_ucet_create_sale($pdo, (int) $uid);
    } catch (Throwable $e) {}
}

function pos_uzaverka_data(PDO $pdo, string $date): array {
    $dNext = date('Y-m-d', strtotime($date . ' +1 day'));
    $METODY = ['hotovost','karta','qr','online','poukaz','prevod','ostatni'];
    $normZ = function ($z) {
        $z = strtolower(trim((string) $z));
        $map = [
            'hotove'=>'hotovost','hotovost'=>'hotovost','cash'=>'hotovost',
            'karta'=>'karta','card'=>'karta','terminal'=>'karta',
            'qr'=>'qr','online'=>'online','poukaz'=>'poukaz','voucher'=>'poukaz',
            'prevod'=>'prevod','faktura'=>'prevod','bankovni_prevod'=>'prevod',
        ];
        return $map[$z] ?? 'ostatni';
    };
    $stanice = [];
    $touch = function (&$st, $jmeno) use ($METODY) {
        if (!isset($st[$jmeno])) $st[$jmeno] = ['obsluha'=>$jmeno,'pocet'=>0,'trzba'=>0.0,'tip'=>0.0,'metody'=>array_fill_keys($METODY, 0.0)];
    };
    // 🆕 v3.0.211 — dine-in i takeaway jsou teď OBA v objednavky (puvod='pos'): dine-in se
    //   vytvoří z paid účtu (pos_ucet_create_sale / backfill). Čteme JEN objednavky → žádné
    //   dvojí počítání. (Volající spustí pos_backfill_sales($date) předem.)
    // POS prodeje (objednavky pokladních kanálů, datum dle datum_objednani)
    $pokIn = pos_pokladni_sql($pdo); // 🆕 v3.0.212
    try {
        $tw = $pdo->prepare("
            SELECT COALESCE(NULLIF(pos_uzivatel,''),'(bez obsluhy)') AS obsluha, pos_payment, castka_celkem, COALESCE(pos_tip,0) AS tip
            FROM objednavky
            WHERE {$pokIn} AND stav <> 'zrusena' AND datum_objednani >= :d AND datum_objednani < :dn
        ");
        $tw->execute(['d' => $date, 'dn' => $dNext]);
        foreach ($tw->fetchAll() as $r) {
            $j = $r['obsluha']; $touch($stanice, $j);
            $stanice[$j]['metody'][$normZ($r['pos_payment'])] += (float) $r['castka_celkem'];
            $stanice[$j]['trzba'] += (float) $r['castka_celkem'];
            $stanice[$j]['tip']   += (float) $r['tip'];
            $stanice[$j]['pocet']++;
        }
    } catch (Throwable $e) {}
    // Součty + zaokrouhlení
    $total = ['trzba'=>0.0,'pocet'=>0,'tip'=>0.0,'metody'=>array_fill_keys($METODY, 0.0)];
    foreach ($stanice as &$s) {
        $total['trzba'] += $s['trzba']; $total['pocet'] += $s['pocet']; $total['tip'] += $s['tip'];
        foreach ($METODY as $m) { $total['metody'][$m] += $s['metody'][$m]; $s['metody'][$m] = round($s['metody'][$m], 2); }
        $s['trzba'] = round($s['trzba'], 2); $s['tip'] = round($s['tip'], 2);
    }
    unset($s);
    $total['trzba'] = round($total['trzba'], 2); $total['tip'] = round($total['tip'], 2);
    foreach ($METODY as $m) $total['metody'][$m] = round($total['metody'][$m], 2);
    $arr = array_values($stanice);
    usort($arr, fn($a, $b) => $b['trzba'] <=> $a['trzba']);
    return ['date' => $date, 'metody' => $METODY, 'stanice' => $arr, 'total' => $total];
}
function pos_uzaverky_ensure(PDO $pdo): void {
    static $done = false; if ($done) return; $done = true;
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS pos_uzaverky (
            id INT AUTO_INCREMENT PRIMARY KEY,
            datum DATE NOT NULL,
            celkem DECIMAL(12,2) NOT NULL DEFAULT 0,
            pocet_dokladu INT NOT NULL DEFAULT 0,
            snapshot_json MEDIUMTEXT NOT NULL,
            kdo VARCHAR(120) NULL,
            vytvoreno DATETIME DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_datum (datum)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    } catch (Throwable $e) {}
}

// GET ?action=uzaverka&date=YYYY-MM-DD → X-přehled (rozpad na obsluhu + platby + součty)
if ($method === 'GET' && $action === 'uzaverka') {
    $date = $_GET['date'] ?? date('Y-m-d');
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) json_error('Neplatné datum', 400);
    pos_uzaverky_ensure($pdo);
    pos_backfill_sales($pdo, $date); // dine-in účty → objednávky (než spočítáme)
    $data = pos_uzaverka_data($pdo, $date);
    $uz = $pdo->prepare("SELECT id, kdo, vytvoreno, celkem, pocet_dokladu FROM pos_uzaverky WHERE datum = :d ORDER BY id DESC LIMIT 1");
    $uz->execute(['d' => $date]);
    $data['uzavreno'] = $uz->fetch(PDO::FETCH_ASSOC) ?: null;
    json_response($data);
}

// POST ?action=uzaverka_close { date } → Z-uzávěrka: ulož snapshot dne (audit + tisk)
if ($method === 'POST' && $action === 'uzaverka_close') {
    $d = json_input();
    $date = $d['date'] ?? date('Y-m-d');
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) json_error('Neplatné datum', 400);
    pos_uzaverky_ensure($pdo);
    pos_backfill_sales($pdo, $date); // dine-in účty → objednávky (než uložíme snapshot)
    $data = pos_uzaverka_data($pdo, $date);
    $kdo = $_SESSION['admin_jmeno'] ?? 'admin';
    $pdo->prepare("INSERT INTO pos_uzaverky (datum, celkem, pocet_dokladu, snapshot_json, kdo) VALUES (:d,:c,:p,:s,:k)")
        ->execute([
            'd' => $date, 'c' => $data['total']['trzba'], 'p' => $data['total']['pocet'],
            's' => json_encode($data, JSON_UNESCAPED_UNICODE), 'k' => $kdo,
        ]);
    json_response(['ok' => true, 'id' => (int) $pdo->lastInsertId(), 'celkem' => $data['total']['trzba'], 'pocet' => $data['total']['pocet'], 'kdo' => $kdo]);
}

// 🆕 v2.9.308 — GET ?action=quick_order_detail&id=X — detail jedné POS účtenky (modal)
// Vrací objednavku + polozky. LEFT JOIN odberatele aby fungovalo i pro POS quick orders
// bez zákazníka (puvod='pos', odberatel_id může být null nebo dummy).
// 🐛 v2.9.313 — security fix: přidáno AND o.puvod = 'pos' do WHERE — bez toho mohl POS-only
// user fetchnout libovolnou B2B objednávku iterací přes ?id=1,2,3 (privilege scope expansion).
if ($method === 'GET' && $action === 'quick_order_detail') {
    $id = (int) ($_GET['id'] ?? 0);
    if (!$id) json_error('Chybí ID', 400);
    try {
        $pokInO = pos_pokladni_sql($pdo, 'o.puvod'); // 🆕 v3.0.212 — detail jen pokladních kanálů
        $st = $pdo->prepare("
            SELECT o.id, o.cislo, o.datum_objednani, o.castka_celkem, o.castka_bez_dph, o.castka_dph,
                   o.stav, o.poznamka, o.puvod, o.pos_typ, o.pos_payment, o.pos_tip, o.pos_uzivatel,
                   o.odberatel_id, COALESCE(od.nazev, '—') AS odberatel_nazev
            FROM objednavky o
            LEFT JOIN odberatele od ON od.id = o.odberatel_id
            WHERE o.id = :id AND {$pokInO}
        ");
        $st->execute(['id' => $id]);
        $obj = $st->fetch(PDO::FETCH_ASSOC);
        if (!$obj) json_error('Účtenka nenalezena', 404);

        $p = $pdo->prepare("
            SELECT p.id, p.vyrobek_id,
                   COALESCE(NULLIF(p.vyrobek_nazev, ''), v.nazev, '?') AS vyrobek_nazev,
                   p.mnozstvi, COALESCE(p.jednotka, 'ks') AS jednotka,
                   p.cena_bez_dph, COALESCE(p.sazba_dph, 12) AS sazba_dph,
                   p.poznamka
            FROM objednavky_polozky p
            LEFT JOIN vyrobky v ON v.id = p.vyrobek_id
            WHERE p.objednavka_id = :id
            ORDER BY p.id ASC
        ");
        $p->execute(['id' => $id]);
        $obj['polozky'] = $p->fetchAll(PDO::FETCH_ASSOC);

        json_response($obj);
    } catch (Throwable $e) {
        json_error_safe('Chyba detailu účtenky', $e, 500);
    }
}

// 🆕 v2.9.303 — GET ?action=launcher_summary — pro POS Kasa hub stránku
// Vrací: souhrn dne (tržby, počet účtenek, hotově/karta split) + last 8 účtenek + TOP 8 prodaných položek
if ($method === 'GET' && $action === 'launcher_summary') {
    try {
        $date = $_GET['date'] ?? date('Y-m-d');
        $limit_orders = max(3, min(30, (int)($_GET['limit_orders'] ?? 8)));
        $limit_items  = max(3, min(30, (int)($_GET['limit_items']  ?? 8)));
        pos_backfill_sales($pdo, $date); // 🆕 v3.0.211 — dine-in paid účty → objednávky (do souhrnu)
        // 🚀 v2.9.314 — range scan (viz quick_history fix)
        $date_next = date('Y-m-d', strtotime($date . ' +1 day'));
        $pokIn  = pos_pokladni_sql($pdo);          // 🆕 v3.0.212
        $pokInO = pos_pokladni_sql($pdo, 'o.puvod');

        // Souhrn dne (kompaktní) — 🐛 v3.0.230: částky bez zrušených
        $sum = $pdo->prepare("
            SELECT
                COUNT(*) AS pocet,
                COALESCE(SUM(CASE WHEN stav <> 'zrusena' THEN castka_celkem ELSE 0 END), 0) AS trzby,
                COALESCE(SUM(CASE WHEN stav <> 'zrusena' THEN pos_tip ELSE 0 END), 0) AS tipy,
                COALESCE(SUM(CASE WHEN pos_payment='hotove' AND stav <> 'zrusena' THEN castka_celkem ELSE 0 END), 0) AS hotove,
                COALESCE(SUM(CASE WHEN pos_payment='karta'  AND stav <> 'zrusena' THEN castka_celkem ELSE 0 END), 0) AS karta
            FROM objednavky
            WHERE {$pokIn} AND datum_objednani >= :d AND datum_objednani < :d_next
        ");
        $sum->execute(['d' => $date, 'd_next' => $date_next]);
        $souhrn = $sum->fetch(PDO::FETCH_ASSOC) ?: [];

        // Posledních N účtenek
        $ord = $pdo->prepare("
            SELECT id, cislo, datum_objednani, castka_celkem, pos_typ, pos_payment, pos_uzivatel,
                   (SELECT COUNT(*) FROM objednavky_polozky p WHERE p.objednavka_id = o.id) AS pocet_polozek
            FROM objednavky o
            WHERE {$pokIn} AND datum_objednani >= :d AND datum_objednani < :d_next
            ORDER BY datum_objednani DESC
            LIMIT {$limit_orders}
        ");
        $ord->execute(['d' => $date, 'd_next' => $date_next]);
        $orders = $ord->fetchAll(PDO::FETCH_ASSOC);

        // TOP N prodaných položek (agregace dle vyrobek_nazev)
        $top = $pdo->prepare("
            SELECT p.vyrobek_nazev, COALESCE(p.vyrobek_id, 0) AS vyrobek_id,
                   SUM(p.mnozstvi) AS mnozstvi_sum,
                   SUM(p.mnozstvi * COALESCE(p.cena_bez_dph, 0) * (1 + COALESCE(p.sazba_dph, 0) / 100)) AS trzba_sum
            FROM objednavky_polozky p
            JOIN objednavky o ON o.id = p.objednavka_id
            WHERE {$pokInO} AND o.stav <> 'zrusena' AND o.datum_objednani >= :d AND o.datum_objednani < :d_next
              AND p.vyrobek_nazev IS NOT NULL
            GROUP BY p.vyrobek_nazev, p.vyrobek_id
            ORDER BY mnozstvi_sum DESC, trzba_sum DESC
            LIMIT {$limit_items}
        ");
        $top->execute(['d' => $date, 'd_next' => $date_next]);
        $top_items = $top->fetchAll(PDO::FETCH_ASSOC);

        json_response([
            'ok'        => true,
            'date'      => $date,
            'souhrn'    => $souhrn,
            'orders'    => $orders,
            'top_items' => $top_items,
        ]);
    } catch (Throwable $e) {
        json_error_safe('Chyba načtení dnešních prodejů', $e, 500);
    }
}

json_error('Neznámá akce: ' . $action, 404);
