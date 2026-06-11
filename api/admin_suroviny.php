<?php
/**
 * Suroviny — správa databáze surovin a vazba na výrobky.
 *
 * GET                          - seznam surovin (?q= hledání)
 * GET     ?id=N                - detail suroviny
 * POST                         - vytvořit
 * PUT                          - upravit
 * DELETE  ?id=N                - smazat
 *
 * GET    ?action=pro_vyrobek&vyrobek_id=N  - složení daného výrobku
 * POST   ?action=seed                       - jednorázový seed defaultních surovin
 *
 * Tabulky se vytvoří při prvním zavolání (CREATE TABLE IF NOT EXISTS).
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/_admin_auth.php';
require_once __DIR__ . '/_sklad_lib.php';
cors_headers();
require_admin();

$pdo = db();
$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? '';

// =============================================================
// Auto-migrace - vytvoří tabulky, pokud neexistují
// =============================================================
function ensure_suroviny_tables(PDO $pdo): void {
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS suroviny (
            id           INT AUTO_INCREMENT PRIMARY KEY,
            nazev        VARCHAR(150) NOT NULL,
            jednotka     VARCHAR(20)  NOT NULL DEFAULT 'g',
            alergen      VARCHAR(150) DEFAULT NULL,
            cena_baleni  DECIMAL(10,2) DEFAULT NULL,
            obsah_baleni DECIMAL(10,3) DEFAULT NULL,
            poznamka     TEXT         DEFAULT NULL,
            aktivni      TINYINT(1)   NOT NULL DEFAULT 1,
            created_at   TIMESTAMP    DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY ux_suroviny_nazev (nazev)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
    // Auto-migrace pro existující tabulku
    $cols = $pdo->query("
        SELECT COLUMN_NAME FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'suroviny'
    ")->fetchAll(PDO::FETCH_COLUMN);
    if (!in_array('cena_baleni', $cols, true)) {
        $pdo->exec("ALTER TABLE suroviny ADD COLUMN cena_baleni DECIMAL(10,2) DEFAULT NULL AFTER alergen");
    }
    if (!in_array('obsah_baleni', $cols, true)) {
        $pdo->exec("ALTER TABLE suroviny ADD COLUMN obsah_baleni DECIMAL(10,3) DEFAULT NULL AFTER cena_baleni");
    }
    // Složení suroviny — pro kompozitní suroviny (Diasauer = pšeničný slad + sůl + ječný slad)
    if (!in_array('slozeni', $cols, true)) {
        $pdo->exec("ALTER TABLE suroviny ADD COLUMN slozeni TEXT DEFAULT NULL AFTER obsah_baleni");
    }
    // Auto-detekované alergeny ze složení (nezávislé na ručním 'alergen')
    if (!in_array('slozeni_alergeny', $cols, true)) {
        $pdo->exec("ALTER TABLE suroviny ADD COLUMN slozeni_alergeny VARCHAR(255) DEFAULT NULL AFTER slozeni");
    }
    // Nutriční hodnoty na 100 g/ml — pro výpočet nutri u výrobků
    $nutri_cols = [
        'nutri_energie_kj'    => 'DECIMAL(8,1)',
        'nutri_energie_kcal'  => 'DECIMAL(8,1)',
        'nutri_tuky'          => 'DECIMAL(7,2)',
        'nutri_tuky_nasycene' => 'DECIMAL(7,2)',
        'nutri_sacharidy'     => 'DECIMAL(7,2)',
        'nutri_cukry'         => 'DECIMAL(7,2)',
        'nutri_bilkoviny'     => 'DECIMAL(7,2)',
        'nutri_sul'           => 'DECIMAL(7,3)',
    ];
    foreach ($nutri_cols as $col => $type) {
        if (!in_array($col, $cols, true)) {
            $pdo->exec("ALTER TABLE suroviny ADD COLUMN $col $type DEFAULT NULL");
        }
    }
    // 📦 Sklad — stock, minimum, cíl pro naskladnění
    $stock_cols = [
        'stock_aktualni'  => 'DECIMAL(12,3) NOT NULL DEFAULT 0',
        'stock_minimalni' => 'DECIMAL(12,3) DEFAULT NULL',
        'stock_cilove'    => 'DECIMAL(12,3) DEFAULT NULL',
    ];
    foreach ($stock_cols as $col => $type) {
        if (!in_array($col, $cols, true)) {
            $pdo->exec("ALTER TABLE suroviny ADD COLUMN $col $type");
        }
    }
    // 📋 Pohyby skladu — kompletní audit trail
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS sklad_pohyby (
            id           INT AUTO_INCREMENT PRIMARY KEY,
            surovina_id  INT NOT NULL,
            typ          ENUM('prijem','vydej','inventura','korekce') NOT NULL,
            mnozstvi     DECIMAL(12,3) NOT NULL,
            jednotka     VARCHAR(20) DEFAULT NULL,
            stock_pred   DECIMAL(12,3) DEFAULT NULL,
            stock_po     DECIMAL(12,3) DEFAULT NULL,
            cena_za_jed  DECIMAL(10,4) DEFAULT NULL,
            poznamka     VARCHAR(300) DEFAULT NULL,
            kdo          VARCHAR(120) DEFAULT NULL,
            kdy          DATETIME DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_pohyb_sur (surovina_id),
            INDEX idx_pohyb_typ (typ),
            FOREIGN KEY (surovina_id) REFERENCES suroviny(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS vyrobek_suroviny (
            id           INT AUTO_INCREMENT PRIMARY KEY,
            vyrobek_id   INT NOT NULL,
            surovina_id  INT NOT NULL,
            mnozstvi     DECIMAL(10,3) NOT NULL DEFAULT 0,
            jednotka     VARCHAR(20)   DEFAULT 'g',
            poradi       INT           DEFAULT 0,
            poznamka     VARCHAR(200)  DEFAULT NULL,
            FOREIGN KEY (vyrobek_id)  REFERENCES vyrobky(id)  ON DELETE CASCADE,
            FOREIGN KEY (surovina_id) REFERENCES suroviny(id) ON DELETE CASCADE,
            UNIQUE KEY ux_vyr_sur (vyrobek_id, surovina_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    // 🚀 PERFORMANCE INDEXY — výrazně urychlí hledání u 1000+ surovin
    // CREATE INDEX IF NOT EXISTS funguje od MySQL 8.0.29 / MariaDB 10.5+
    // Pro starší verze použijeme try/catch — chyba „Duplicate key" je OK
    $indexy = [
        'idx_suroviny_aktivni' => "ALTER TABLE suroviny ADD INDEX idx_suroviny_aktivni (aktivni)",
        'idx_suroviny_alergen' => "ALTER TABLE suroviny ADD INDEX idx_suroviny_alergen (alergen(50))",
        'idx_vs_surovina'      => "ALTER TABLE vyrobek_suroviny ADD INDEX idx_vs_surovina (surovina_id)",
    ];
    $existIdx = [];
    try {
        $r = $pdo->query("
            SELECT INDEX_NAME FROM information_schema.STATISTICS
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME IN ('suroviny', 'vyrobek_suroviny')
        ")->fetchAll(PDO::FETCH_COLUMN);
        $existIdx = array_flip($r);
    } catch (Throwable $e) { /* ignore */ }
    foreach ($indexy as $name => $sql) {
        if (!isset($existIdx[$name])) {
            try { $pdo->exec($sql); } catch (Throwable $e) { /* index už existuje */ }
        }
    }
}
ensure_suroviny_tables($pdo);

// Title case helper — první písmeno každého slova velké, zbytek malé (UTF-8 / český)
function title_case_cs(string $s): string {
    $s = trim($s);
    if ($s === '') return $s;
    return mb_convert_case($s, MB_CASE_TITLE, 'UTF-8');
}

// Detekce 14 EU alergenů z textu (složení) — vrací Kč-list odděleno čárkami
function detekuj_alergeny_z_textu(string $text): string {
    $text = mb_strtolower($text, 'UTF-8');
    if (trim($text) === '') return '';

    // 🆕 v2.9.286 — Lepek pattern fix: odstraněno `sladk` (false positive "sladký = sweet").
    //              Slad (ječný/pšeničný/diasauer) se chytá přes `\bslad\b` (boundary).
    //              Mouka kokosová/mandlová by stále falešně matchla lepek — to je known
    //              limitation (vyžaduje context-aware classification, mimo regex scope).
    $patterns = [
        'lepek (obiloviny)' => '/(pšenic|žitn|ječm|ovsen|oves\s|špald|kamut|trit[ic]al|\bslad\b|sladovan|mouka|otrub|krupic|krupk|strouhank|těstovin|kuskus|bulgur|cous?cous|seitan)/u',
        'korýši'           => '/(krevet|krab|humr|languost|korýš|raček)/u',
        'vejce'            => '/(vejce|vajíčk|vaječ|žloutek|bílek|albumin)/u',
        'ryby'             => '/(ryba|tuňák|losos|sardin|tresk|filé\s+rybí|kavi|sleď)/u',
        'arašídy'          => '/(arašíd|burský|peanut)/u',
        'sója'             => '/(sój|tofu|edamame|tempeh)/u',
        'mléko'            => '/(mléko|mlék|smetan|máslo|tvaroh|sýr|jogurt|laktóz|laktoz|kasein|syrovátk|šlehačk)/u',
        'ořechy'           => '/(mandle|lískov.*ořech|vlašsk.*ořech|kešu|pekan|para\s+ořech|pistác|makadam|brazilsk.*ořech|ořechy|ořech\b)/u',
        'celer'            => '/(celer)/u',
        'hořčice'          => '/(hořčic|hořčič)/u',
        'sezam'            => '/(sezam)/u',
        'oxid siřičitý / siřičitany' => '/(siřič|sulfit|e22[01-8])/u',
        'vlčí bob (lupina)' => '/(lupin|vlčí\s+bob)/u',
        'měkkýši'          => '/(slimák|šnek|mušle|chobotnic|olihn|kalmar|měkkýš|ústřic|hřebenatk)/u',
    ];

    $found = [];
    foreach ($patterns as $label => $rgx) {
        if (preg_match($rgx, $text)) $found[] = $label;
    }
    return implode(', ', $found);
}

// Jednorázová migrace: zformátovat existující názvy do Title Case
(function() use ($pdo) {
    try {
        // Spustit jen pokud je v nastavení flag (nebo jeden-čas)
        $done = $pdo->query("SELECT COUNT(*) FROM nastaveni WHERE klic = 'suroviny_titlecase_done'")->fetchColumn();
        if ((int) $done > 0) return;
        $stmt = $pdo->query("SELECT id, nazev FROM suroviny");
        $upd = $pdo->prepare("UPDATE suroviny SET nazev = :n WHERE id = :id");
        foreach ($stmt->fetchAll() as $row) {
            $tc = mb_convert_case(trim($row['nazev']), MB_CASE_TITLE, 'UTF-8');
            if ($tc !== $row['nazev']) {
                try { $upd->execute(['n' => $tc, 'id' => $row['id']]); }
                catch (PDOException $e) { /* duplikát — přeskoč */ }
            }
        }
        $pdo->prepare("INSERT INTO nastaveni (klic, hodnota) VALUES ('suroviny_titlecase_done', '1')
                       ON DUPLICATE KEY UPDATE hodnota = '1'")->execute();
    } catch (Throwable $e) {
        error_log('admin_suroviny title-case migrace: ' . $e->getMessage());
    }
})();

// =============================================================
// SEED - jednorázové naplnění
// =============================================================
if ($action === 'seed' && $method === 'POST') {
    $existuje = (int) $pdo->query("SELECT COUNT(*) FROM suroviny")->fetchColumn();
    if ($existuje > 0) {
        json_response(['ok' => true, 'inserted' => 0, 'message' => 'Suroviny už existují (' . $existuje . ').']);
    }

    $seed = [
        // Mouky
        ['Mouka pšeničná hladká', 'g', 'lepek'],
        ['Mouka pšeničná polohrubá', 'g', 'lepek'],
        ['Mouka pšeničná hrubá', 'g', 'lepek'],
        ['Mouka chlebová T1050', 'g', 'lepek'],
        ['Mouka chlebová T1150', 'g', 'lepek'],
        ['Mouka žitná chlebová T960', 'g', 'lepek'],
        ['Mouka špaldová', 'g', 'lepek'],
        ['Mouka kukuřičná', 'g', null],
        ['Mouka ovesná', 'g', 'lepek'],
        ['Mouka pohanková', 'g', null],
        ['Mouka rýžová', 'g', null],
        ['Krupice pšeničná', 'g', 'lepek'],
        ['Strouhanka', 'g', 'lepek'],
        // Sypké přídavky
        ['Otruby pšeničné', 'g', 'lepek'],
        ['Vločky ovesné', 'g', 'lepek'],
        ['Lněné semínko', 'g', null],
        ['Slunečnicová semínka', 'g', null],
        ['Dýňová semínka', 'g', null],
        ['Sezamová semínka', 'g', 'sezam'],
        ['Mák mletý', 'g', null],
        ['Mák celý', 'g', null],
        ['Chia semínka', 'g', null],
        // Cukry
        ['Cukr krystal', 'g', null],
        ['Cukr moučka', 'g', null],
        ['Cukr hnědý třtinový', 'g', null],
        ['Cukr vanilkový', 'g', null],
        ['Med', 'g', null],
        ['Melasa', 'g', null],
        // Tuky
        ['Máslo', 'g', 'mléko'],
        ['Sádlo', 'g', null],
        ['Margarín', 'g', 'mléko'],
        ['Olej slunečnicový', 'ml', null],
        ['Olej řepkový', 'ml', null],
        ['Olej olivový', 'ml', null],
        // Vejce a mléčné
        ['Vejce', 'ks', 'vejce'],
        ['Žloutek', 'ks', 'vejce'],
        ['Bílek', 'ks', 'vejce'],
        ['Mléko plnotučné', 'ml', 'mléko'],
        ['Smetana 33%', 'ml', 'mléko'],
        ['Smetana 12%', 'ml', 'mléko'],
        ['Tvaroh měkký', 'g', 'mléko'],
        ['Mascarpone', 'g', 'mléko'],
        ['Zakysaná smetana', 'g', 'mléko'],
        ['Jogurt bílý', 'g', 'mléko'],
        // Kvasnice / kypřící
        ['Droždí čerstvé', 'g', null],
        ['Droždí sušené', 'g', null],
        ['Prášek do pečiva', 'g', null],
        ['Jedlá soda', 'g', null],
        ['Pšeničný kvas', 'g', 'lepek'],
        ['Žitný kvas', 'g', 'lepek'],
        // Sůl, koření, aroma
        ['Sůl', 'g', null],
        ['Skořice mletá', 'g', null],
        ['Vanilka (lusk/extrakt)', 'g', null],
        ['Kakao', 'g', null],
        ['Čokoláda hořká 70%', 'g', 'mléko'],
        ['Čokoláda mléčná', 'g', 'mléko'],
        ['Kokos strouhaný', 'g', null],
        // Ořechy
        ['Vlašské ořechy', 'g', 'ořechy'],
        ['Lískové ořechy', 'g', 'ořechy'],
        ['Mandle', 'g', 'ořechy'],
        ['Kešu', 'g', 'ořechy'],
        // Sušené ovoce
        ['Rozinky', 'g', null],
        ['Brusinky sušené', 'g', null],
        ['Datle', 'g', null],
        ['Sušené meruňky', 'g', null],
        // Náplně
        ['Marmeláda meruňková', 'g', null],
        ['Povidla švestková', 'g', null],
        // Citrus
        ['Citron - šťáva', 'ml', null],
        ['Citronová kůra', 'g', null],
        ['Pomeranč - kůra', 'g', null],
    ];

    $stmt = $pdo->prepare("INSERT IGNORE INTO suroviny (nazev, jednotka, alergen) VALUES (:n, :j, :a)");
    $vlozeno = 0;
    foreach ($seed as $s) {
        $stmt->execute(['n' => $s[0], 'j' => $s[1], 'a' => $s[2]]);
        if ($stmt->rowCount() > 0) $vlozeno++;
    }
    json_response(['ok' => true, 'inserted' => $vlozeno]);
}

// =============================================================
// 📦 SKLAD — pohyby (příjem / výdej / inventura)
// =============================================================
// Helper: aktuální uživatel pro audit log
function _aktualni_uzivatel(): string {
    return $_SESSION['admin_email'] ?? $_SESSION['user_email'] ?? 'system';
}

// GET seznam pohybů (s filtrem)
//   ?action=sklad_pohyby                 → posledních 100
//   ?action=sklad_pohyby&surovina_id=N   → historie konkrétní suroviny
if ($action === 'sklad_pohyby' && $method === 'GET') {
    $sid = (int) ($_GET['surovina_id'] ?? 0);
    $limit = max(1, min(500, (int) ($_GET['limit'] ?? 100)));

    // 🐛 v3.0.168 — historie ze sjednoceného logu sklad_pohyby_v2 (aliasy na staré názvy
    // kvůli zpětné kompatibilitě frontendu) + název skladu.
    $sql = "
        SELECT p.id, p.item_id AS surovina_id, p.typ, p.mnozstvi,
               p.stav_pred AS stock_pred, p.stav_po AS stock_po,
               p.poznamka, p.kdo, p.kdy, p.sklad_id,
               s.nazev AS surovina_nazev, s.jednotka AS surovina_jednotka,
               sk.nazev AS sklad_nazev
        FROM sklad_pohyby_v2 p
        JOIN suroviny s ON s.id = p.item_id
        LEFT JOIN sklady sk ON sk.id = p.sklad_id
        WHERE p.item_typ = 'surovina'
    ";
    $params = [];
    if ($sid > 0) {
        $sql .= " AND p.item_id = :sid";
        $params['sid'] = $sid;
    }
    $sql .= " ORDER BY p.kdy DESC LIMIT $limit";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    json_response($stmt->fetchAll(PDO::FETCH_ASSOC));
}

// POST pohyb — přijem / výdej / inventura / korekce
//   { surovina_id, typ, mnozstvi, cena_za_jed?, poznamka? }
if (in_array($action, ['sklad_prijem','sklad_vydej','sklad_inventura','sklad_korekce'], true) && $method === 'POST') {
    $d = json_input();
    $sid = (int) ($d['surovina_id'] ?? 0);
    $mnozstvi = (float) ($d['mnozstvi'] ?? 0);
    if (!$sid) json_error('Chybí surovina_id');
    if ($mnozstvi <= 0) json_error('Množství musí být > 0');

    $sur = $pdo->prepare("SELECT id, nazev, jednotka FROM suroviny WHERE id = :id");
    $sur->execute(['id' => $sid]);
    $surData = $sur->fetch(PDO::FETCH_ASSOC);
    if (!$surData) json_error('Surovina nenalezena', 404);

    $typ = str_replace('sklad_', '', $action);

    // 🆕 v3.0.262 — BUGFIX: zápis do SYSTÉMU B (sklad_polozky domovského skladu) + recompute,
    //   konzistentně s admin_sklad_pohyby.php / restock / odpisem výroby. Dřív se psalo jen
    //   suroviny.stock_aktualni (systém A) → recompute z B (po výrobě / restocku / GET migraci)
    //   ho přepsal → stav spadl do mínusu (příjem přes kartu suroviny + výroba = drift).
    //   Číselné chování i tvar odpovědi zachovány (vydej clampuje na 0, inventura = nový stav).
    try {
        $home  = surovina_home_sklad($pdo, $sid);
        $rowId = sklad_polozky_ensure($pdo, $home, 'surovina', $sid);
        $pdo->beginTransaction();
        $stockPred = (float) $pdo->query("SELECT stav FROM sklad_polozky WHERE id=" . (int) $rowId . " FOR UPDATE")->fetchColumn();
        if ($typ === 'prijem')        $stockPo = $stockPred + $mnozstvi;
        elseif ($typ === 'vydej')     $stockPo = max(0, $stockPred - $mnozstvi);
        elseif ($typ === 'inventura') $stockPo = $mnozstvi;            // mnozstvi = nový reálný stav
        else /* korekce */            $stockPo = $stockPred + $mnozstvi;
        $delta = $stockPo - $stockPred;
        $pdo->prepare("UPDATE sklad_polozky SET stav = :s WHERE id = :r")
            ->execute(['s' => $stockPo, 'r' => $rowId]);
        // Zaznamenat pohyb (audit) do systému B
        $pdo->prepare("
            INSERT INTO sklad_pohyby_v2 (sklad_id, item_typ, item_id, typ, mnozstvi, stav_pred, stav_po, cena_za_jed, poznamka, kdo)
            VALUES (:s, 'surovina', :i, :typ, :mn, :pred, :po, :cz, :pz, :kdo)
        ")->execute([
            's'    => $home,
            'i'    => $sid,
            'typ'  => $typ,
            'mn'   => $delta,
            'pred' => $stockPred,
            'po'   => $stockPo,
            'cz'   => isset($d['cena_za_jed']) && $d['cena_za_jed'] !== '' ? (float) $d['cena_za_jed'] : null,
            'pz'   => isset($d['poznamka']) && trim($d['poznamka']) !== '' ? trim($d['poznamka']) : null,
            'kdo'  => _aktualni_uzivatel(),
        ]);
        $pdo->commit();
        surovina_recompute_total($pdo, $sid); // systém A (stock_aktualni) = SUM(B)
        json_response(['ok' => true, 'stock_pred' => $stockPred, 'stock_po' => $stockPo]);
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        json_error_safe('Chyba pohybu skladu', $e, 500);
    }
}

// 🆕 v3.0.204 — 1-klik doplnění suroviny na cílovou zásobu (systém B: sklad_polozky + recompute)
//   { id }  → příjem (stock_cilove − aktuální) do domovského skladu suroviny. Pro low-stock list.
if ($action === 'restock' && $method === 'POST') {
    $d = json_input();
    $sid = (int) ($d['id'] ?? $d['surovina_id'] ?? 0);
    if (!$sid) json_error('Chybí id', 400);
    $sur = $pdo->prepare("SELECT id, nazev, jednotka, stock_minimalni, stock_cilove FROM suroviny WHERE id = :id");
    $sur->execute(['id' => $sid]);
    $surData = $sur->fetch(PDO::FETCH_ASSOC);
    if (!$surData) json_error('Surovina nenalezena', 404);
    $cil    = (float) ($surData['stock_cilove'] ?? 0);
    $min    = (float) ($surData['stock_minimalni'] ?? 0);
    $target = $cil > 0 ? $cil : $min;   // cíl, jinak aspoň minimum
    if ($target <= 0) json_error('Surovina nemá cílovou ani minimální zásobu — nelze doplnit jedním klikem', 400);
    // živý stav ze systému B
    $cur   = (float) $pdo->query("SELECT COALESCE(SUM(stav),0) FROM sklad_polozky WHERE item_typ='surovina' AND item_id=" . (int)$sid)->fetchColumn();
    $delta = round($target - $cur, 3);
    if ($delta <= 0) json_response(['ok' => true, 'doplneno' => 0, 'stav' => $cur, 'nazev' => $surData['nazev'], 'msg' => 'Už na/nad cílem']);
    try {
        $home  = surovina_home_sklad($pdo, $sid);
        $rowId = sklad_polozky_ensure($pdo, $home, 'surovina', $sid);
        $pdo->beginTransaction();
        $stavPred = (float) $pdo->query("SELECT stav FROM sklad_polozky WHERE id=" . (int)$rowId . " FOR UPDATE")->fetchColumn();
        $stavPo   = $stavPred + $delta;
        $pdo->prepare("UPDATE sklad_polozky SET stav = :s WHERE id = :r")->execute(['s' => $stavPo, 'r' => $rowId]);
        $pdo->prepare("
            INSERT INTO sklad_pohyby_v2 (sklad_id, item_typ, item_id, typ, mnozstvi, stav_pred, stav_po, poznamka, kdo)
            VALUES (:s, 'surovina', :i, 'prijem', :m, :sp, :sP, :p, :k)
        ")->execute(['s' => $home, 'i' => $sid, 'm' => $delta, 'sp' => $stavPred, 'sP' => $stavPo, 'p' => '1-klik doplnění na cíl', 'k' => _aktualni_uzivatel()]);
        $pdo->commit();
        surovina_recompute_total($pdo, $sid);
        json_response(['ok' => true, 'doplneno' => $delta, 'stav' => $target, 'nazev' => $surData['nazev'], 'jednotka' => $surData['jednotka']]);
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        json_error_safe('Doplnění selhalo', $e, 500);
    }
}

// =============================================================
// GET složení výrobku
// =============================================================
if ($action === 'pro_vyrobek' && $method === 'GET') {
    $vid = (int) ($_GET['vyrobek_id'] ?? 0);
    if (!$vid) json_error('Chybí vyrobek_id');

    $stmt = $pdo->prepare("
        SELECT vs.id, vs.vyrobek_id, vs.surovina_id, vs.mnozstvi, vs.jednotka, vs.poradi, vs.poznamka,
               s.nazev AS surovina_nazev, s.alergen AS surovina_alergen
        FROM vyrobek_suroviny vs
        JOIN suroviny s ON s.id = vs.surovina_id
        WHERE vs.vyrobek_id = :v
        ORDER BY vs.poradi, s.nazev
    ");
    $stmt->execute(['v' => $vid]);
    json_response($stmt->fetchAll(PDO::FETCH_ASSOC));
}

// =============================================================
// GET seznam / detail
// =============================================================
if ($method === 'GET') {
    sklad_unify_migrate($pdo); // 🆕 v3.0.168 idempotentní — 1× sjednotí A↔B (guard uvnitř)
    if (isset($_GET['id'])) {
        $stmt = $pdo->prepare("SELECT * FROM suroviny WHERE id = :id");
        $stmt->execute(['id' => (int) $_GET['id']]);
        $s = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$s) json_error('Surovina nenalezena', 404);

        // Doplň seznam výrobků, kde se tato surovina používá (pro click-through z detailu)
        try {
            $vyrStmt = $pdo->prepare("
                SELECT v.id, v.nazev, v.cislo, v.aktivni, vs.mnozstvi, vs.jednotka
                FROM vyrobek_suroviny vs
                JOIN vyrobky v ON v.id = vs.vyrobek_id
                WHERE vs.surovina_id = :sid
                ORDER BY v.aktivni DESC, v.nazev
            ");
            $vyrStmt->execute(['sid' => (int) $_GET['id']]);
            $s['pouzito_ve_vyrobcich'] = $vyrStmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (Throwable $e) {
            $s['pouzito_ve_vyrobcich'] = [];
        }
        $s['sklady'] = $pdo->query("SELECT id, nazev FROM sklady WHERE COALESCE(aktivni,1)=1 ORDER BY id")->fetchAll(PDO::FETCH_ASSOC); // 🆕 v3.0.168 pro picker domovského skladu
        json_response($s);
    }

    $q = trim($_GET['q'] ?? '');
    // 📄 Volitelná paginace (?page=1&limit=50). Když nedodáš, vrátí všechno (zpětná kompatibilita).
    $page  = max(1, (int) ($_GET['page'] ?? 0));
    $limit = (int) ($_GET['limit'] ?? 0);
    $usePagination = ($limit > 0);
    if ($usePagination && $limit > 500) $limit = 500;

    // 🚀 LEFT JOIN + GROUP BY místo N+1 subquery — 50× rychlejší u velkých seznamů
    $whereSql = '';
    $params = [];
    if ($q !== '') {
        $whereSql = " WHERE (s.nazev LIKE :q OR s.alergen LIKE :q OR s.slozeni LIKE :q) ";
        $params['q'] = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $q) . '%';
    }

    // Total count pro paginaci
    $total = null;
    if ($usePagination) {
        $cs = $pdo->prepare("SELECT COUNT(*) FROM suroviny s $whereSql");
        $cs->execute($params);
        $total = (int) $cs->fetchColumn();
    }

    // 🐛 v3.0.195 — list dříve VŮBEC nevracel stock_* (vypadly při LEFT JOIN optimalizaci)
    //   → obrazovka Suroviny ukazovala 0/— u všech a záporný sklad nebyl vidět (odhaleno
    //   zátěžovým testem). Vracíme je zpět + stock_aktualni počítáme ŽIVĚ ze sklad_polozky
    //   (zdroj pravdy, vč. mínusů) s fallbackem na cache sloupec.
    $sql = "
        SELECT s.id, s.nazev, s.jednotka, s.alergen, s.cena_baleni, s.obsah_baleni,
               s.slozeni, s.slozeni_alergeny, s.poznamka, s.aktivni, s.created_at,
               s.nutri_energie_kj, s.nutri_energie_kcal, s.nutri_tuky, s.nutri_tuky_nasycene,
               s.nutri_sacharidy, s.nutri_cukry, s.nutri_bilkoviny, s.nutri_sul,
               s.stock_minimalni, s.stock_cilove,
               COALESCE(sp.stav_sum, s.stock_aktualni, 0) AS stock_aktualni,
               COALESCE(vs.pocet, 0) AS pocet_vyrobku
        FROM suroviny s
        LEFT JOIN (
            SELECT surovina_id, COUNT(*) AS pocet
            FROM vyrobek_suroviny
            GROUP BY surovina_id
        ) vs ON vs.surovina_id = s.id
        LEFT JOIN (
            SELECT item_id, SUM(stav) AS stav_sum
            FROM sklad_polozky
            WHERE item_typ='surovina'
            GROUP BY item_id
        ) sp ON sp.item_id = s.id
        $whereSql
        ORDER BY s.aktivni DESC, s.nazev
    ";

    if ($usePagination) {
        $offset = ($page - 1) * $limit;
        $sql .= " LIMIT :lim OFFSET :off";
        $stmt = $pdo->prepare($sql);
        foreach ($params as $k => $v) $stmt->bindValue(':' . $k, $v);
        $stmt->bindValue(':lim', $limit, PDO::PARAM_INT);
        $stmt->bindValue(':off', $offset, PDO::PARAM_INT);
        $stmt->execute();
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        json_response(['rows' => $rows, 'total' => $total, 'page' => $page, 'limit' => $limit]);
    }

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    json_response($stmt->fetchAll(PDO::FETCH_ASSOC));
}

// =============================================================
// POST - vytvořit
// =============================================================
if ($method === 'POST') {
    $d = json_input();
    $nazev = title_case_cs($d['nazev'] ?? '');
    if ($nazev === '') json_error('Chybí název');

    $slozeni = isset($d['slozeni']) ? trim((string) $d['slozeni']) : '';
    $slozeni_alergeny = $slozeni !== '' ? detekuj_alergeny_z_textu($slozeni) : '';

    $nutri_keys = ['nutri_energie_kj','nutri_energie_kcal','nutri_tuky','nutri_tuky_nasycene','nutri_sacharidy','nutri_cukry','nutri_bilkoviny','nutri_sul'];
    $nutri = [];
    foreach ($nutri_keys as $k) {
        $nutri[$k] = (isset($d[$k]) && $d[$k] !== '' && $d[$k] !== null) ? (float) $d[$k] : null;
    }

    try {
        $stmt = $pdo->prepare("
            INSERT INTO suroviny (nazev, jednotka, alergen, cena_baleni, obsah_baleni, slozeni, slozeni_alergeny,
                nutri_energie_kj, nutri_energie_kcal, nutri_tuky, nutri_tuky_nasycene, nutri_sacharidy, nutri_cukry, nutri_bilkoviny, nutri_sul,
                stock_aktualni, stock_minimalni, stock_cilove,
                poznamka, aktivni)
            VALUES (:n, :j, :a, :cb, :ob, :sl, :sla,
                :nkj, :nkcal, :nt, :ntn, :nsa, :ncu, :nb, :nsl,
                :sa, :sm, :sc,
                :p, :ak)
        ");
        $stmt->execute(array_merge([
            'n'  => $nazev,
            'j'  => trim($d['jednotka'] ?? 'g') ?: 'g',
            'a'  => isset($d['alergen']) && trim($d['alergen']) !== '' ? trim($d['alergen']) : null,
            'cb' => isset($d['cena_baleni']) && $d['cena_baleni'] !== '' ? (float) $d['cena_baleni'] : null,
            'ob' => isset($d['obsah_baleni']) && $d['obsah_baleni'] !== '' ? (float) $d['obsah_baleni'] : null,
            'sl' => $slozeni !== '' ? $slozeni : null,
            'sla'=> $slozeni_alergeny !== '' ? $slozeni_alergeny : null,
            'nkj'   => $nutri['nutri_energie_kj'],
            'nkcal' => $nutri['nutri_energie_kcal'],
            'nt'    => $nutri['nutri_tuky'],
            'ntn'   => $nutri['nutri_tuky_nasycene'],
            'nsa'   => $nutri['nutri_sacharidy'],
            'ncu'   => $nutri['nutri_cukry'],
            'nb'    => $nutri['nutri_bilkoviny'],
            'nsl'   => $nutri['nutri_sul'],
            'sa' => isset($d['stock_aktualni'])  && $d['stock_aktualni']  !== '' ? (float) $d['stock_aktualni'] : 0,
            'sm' => isset($d['stock_minimalni']) && $d['stock_minimalni'] !== '' ? (float) $d['stock_minimalni'] : null,
            'sc' => isset($d['stock_cilove'])    && $d['stock_cilove']    !== '' ? (float) $d['stock_cilove'] : null,
            'p'  => isset($d['poznamka']) && trim($d['poznamka']) !== '' ? trim($d['poznamka']) : null,
            'ak' => isset($d['aktivni']) ? (int) $d['aktivni'] : 1,
        ]));
        $newId = (int) $pdo->lastInsertId();
        // 🆕 v3.0.168 — nová surovina rovnou do systému B (domovský sklad = default)
        $home = sklad_default_id($pdo);
        if ($home > 0) {
            $pdo->prepare("UPDATE suroviny SET domovsky_sklad_id=:d WHERE id=:id")->execute(['d' => $home, 'id' => $newId]);
            $rowId = sklad_polozky_ensure($pdo, $home, 'surovina', $newId);
            $stockInit = (isset($d['stock_aktualni']) && $d['stock_aktualni'] !== '') ? (float) $d['stock_aktualni'] : 0;
            $pdo->prepare("UPDATE sklad_polozky SET stav=:st WHERE id=:r")->execute(['st' => $stockInit, 'r' => $rowId]);
            surovina_recompute_total($pdo, $newId);
        }
        json_response(['id' => $newId, 'slozeni_alergeny' => $slozeni_alergeny], 201);
    } catch (PDOException $e) {
        if ($e->getCode() === '23000') json_error('Surovina s tímto názvem už existuje');
        json_error_safe('Chyba uložení', $e, 500);
    }
}

// =============================================================
// PUT - upravit
// =============================================================
if ($method === 'PUT') {
    $d = json_input();
    $id = (int) ($d['id'] ?? 0);
    if (!$id) json_error('Chybí ID');

    $slozeni = isset($d['slozeni']) ? trim((string) $d['slozeni']) : '';
    $slozeni_alergeny = $slozeni !== '' ? detekuj_alergeny_z_textu($slozeni) : '';

    $nutri_keys = ['nutri_energie_kj','nutri_energie_kcal','nutri_tuky','nutri_tuky_nasycene','nutri_sacharidy','nutri_cukry','nutri_bilkoviny','nutri_sul'];
    $nutri = [];
    foreach ($nutri_keys as $k) {
        $nutri[$k] = (isset($d[$k]) && $d[$k] !== '' && $d[$k] !== null) ? (float) $d[$k] : null;
    }

    try {
        $pdo->prepare("
            UPDATE suroviny
            SET nazev = :n, jednotka = :j, alergen = :a,
                cena_baleni = :cb, obsah_baleni = :ob,
                slozeni = :sl, slozeni_alergeny = :sla,
                nutri_energie_kj = :nkj, nutri_energie_kcal = :nkcal,
                nutri_tuky = :nt, nutri_tuky_nasycene = :ntn,
                nutri_sacharidy = :nsa, nutri_cukry = :ncu,
                nutri_bilkoviny = :nb, nutri_sul = :nsl,
                stock_minimalni = :sm, stock_cilove = :sc,
                poznamka = :p, aktivni = :ak,
                domovsky_sklad_id = COALESCE(:dom, domovsky_sklad_id)
            WHERE id = :id
        ")->execute([
            'n'  => title_case_cs($d['nazev'] ?? ''),
            'j'  => trim($d['jednotka'] ?? 'g') ?: 'g',
            'a'  => isset($d['alergen']) && trim($d['alergen']) !== '' ? trim($d['alergen']) : null,
            'cb' => isset($d['cena_baleni']) && $d['cena_baleni'] !== '' ? (float) $d['cena_baleni'] : null,
            'ob' => isset($d['obsah_baleni']) && $d['obsah_baleni'] !== '' ? (float) $d['obsah_baleni'] : null,
            'sl' => $slozeni !== '' ? $slozeni : null,
            'sla'=> $slozeni_alergeny !== '' ? $slozeni_alergeny : null,
            'nkj'   => $nutri['nutri_energie_kj'],
            'nkcal' => $nutri['nutri_energie_kcal'],
            'nt'    => $nutri['nutri_tuky'],
            'ntn'   => $nutri['nutri_tuky_nasycene'],
            'nsa'   => $nutri['nutri_sacharidy'],
            'ncu'   => $nutri['nutri_cukry'],
            'nb'    => $nutri['nutri_bilkoviny'],
            'nsl'   => $nutri['nutri_sul'],
            'sm' => isset($d['stock_minimalni']) && $d['stock_minimalni'] !== '' ? (float) $d['stock_minimalni'] : null,
            'sc' => isset($d['stock_cilove'])    && $d['stock_cilove']    !== '' ? (float) $d['stock_cilove'] : null,
            'p'  => isset($d['poznamka']) && trim($d['poznamka']) !== '' ? trim($d['poznamka']) : null,
            'ak' => isset($d['aktivni']) ? (int) $d['aktivni'] : 1,
            'dom' => (isset($d['domovsky_sklad_id']) && $d['domovsky_sklad_id']) ? (int) $d['domovsky_sklad_id'] : null,
            'id' => $id,
        ]);
        json_response(['ok' => true, 'slozeni_alergeny' => $slozeni_alergeny]);
    } catch (PDOException $e) {
        if ($e->getCode() === '23000') json_error('Surovina s tímto názvem už existuje');
        json_error_safe('Chyba úpravy', $e, 500);
    }
}

// =============================================================
// DELETE
// =============================================================
if ($method === 'DELETE') {
    $id = (int) ($_GET['id'] ?? 0);
    if (!$id) json_error('Chybí ID');

    // Pokud je v některém receptu, zeptej se / soft-delete:
    $cnt = $pdo->prepare("SELECT COUNT(*) FROM vyrobek_suroviny WHERE surovina_id = :id");
    $cnt->execute(['id' => $id]);
    $pouzita = (int) $cnt->fetchColumn();

    if ($pouzita > 0) {
        // Soft-delete - jen deaktivuj
        $pdo->prepare("UPDATE suroviny SET aktivni = 0 WHERE id = :id")->execute(['id' => $id]);
        json_response(['ok' => true, 'deactivated' => true, 'pouzita_v_vyrobcich' => $pouzita]);
    }

    // 🐛 fix v3.0.163 — integrita vazeb: hard-delete suroviny dříve nechával
    // osiřelé skladové stavy + pohyby. sklad_polozky / sklad_pohyby_v2 nemají
    // FK na suroviny (app-managed), proto je čistíme ručně. Soft-fail kvůli
    // starším instancím, kde tyto tabulky ještě nemusí existovat.
    try {
        $pdo->prepare("DELETE FROM sklad_polozky WHERE item_typ = 'surovina' AND item_id = :id")->execute(['id' => $id]);
    } catch (Exception $e) { error_log('admin_suroviny DELETE sklad_polozky: ' . $e->getMessage()); }
    try {
        $pdo->prepare("DELETE FROM sklad_pohyby_v2 WHERE item_typ = 'surovina' AND item_id = :id")->execute(['id' => $id]);
    } catch (Exception $e) { error_log('admin_suroviny DELETE sklad_pohyby_v2: ' . $e->getMessage()); }

    $pdo->prepare("DELETE FROM suroviny WHERE id = :id")->execute(['id' => $id]);
    json_response(['ok' => true, 'deleted' => true]);
}

json_error('Neplatná metoda', 405);
