<?php
/**
 * 🪑 STOLOVÁ SPRÁVA — Restaurace balíček (v2.2 — Floor Plan Editor).
 *
 * Stoly jsou rozmístěné na 2D plátně (canvas) per zóna (sál/terasa/bar/salon),
 * mají reálný tvar/velikost a real-time stav (free/reserved/occupied/cleaning/attention).
 *
 * ENDPOINTS:
 *   GET  /api/admin_tables.php                      → seznam stolů + zón pro aktuální datum
 *   GET  /api/admin_tables.php?action=zones         → jen zóny
 *   POST /api/admin_tables.php                      → přidat stůl
 *   PUT  /api/admin_tables.php?id=N                 → upravit stůl
 *   DELETE /api/admin_tables.php?id=N               → smazat stůl
 *   POST /api/admin_tables.php?action=save_layout   → bulk save (x/y/w/h/zone) po drag-drop
 *   POST /api/admin_tables.php?action=set_state     → změna stavu (free → occupied atd.)
 *   POST /api/admin_tables.php?action=zone_save     → CRUD zón
 *   DELETE /api/admin_tables.php?action=zone&id=N   → smazat zónu
 *   GET  /api/admin_tables.php?action=templates     → seznam šablon (Pizzerie / Restaurace / ...)
 *   POST /api/admin_tables.php?action=apply_template → naimportovat šablonu (WIPE + INSERT)
 *   POST /api/admin_tables.php?action=reserve       → rezervace (jako dřív)
 *   DELETE /api/admin_tables.php?action=reservation&id=N → zrušit rezervaci
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

// =============================================================
// 📐 SCHEMA (idempotentní)
// =============================================================
$pdo->exec("
    CREATE TABLE IF NOT EXISTS restaurant_tables (
        id INT AUTO_INCREMENT PRIMARY KEY,
        nazev VARCHAR(60) NOT NULL,
        mist INT NOT NULL DEFAULT 2,
        sekce VARCHAR(40) DEFAULT NULL,
        x INT DEFAULT 0,
        y INT DEFAULT 0,
        tvar ENUM('round','square','rect') DEFAULT 'square',
        aktivni TINYINT(1) NOT NULL DEFAULT 1,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_sekce (sekce)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
");

$pdo->exec("
    CREATE TABLE IF NOT EXISTS table_reservations (
        id INT AUTO_INCREMENT PRIMARY KEY,
        stul_id INT NOT NULL,
        datum DATE NOT NULL,
        cas_od TIME NOT NULL,
        cas_do TIME NOT NULL,
        jmeno VARCHAR(150) NOT NULL,
        telefon VARCHAR(50) NULL,
        pocet_osob INT NOT NULL DEFAULT 2,
        poznamka VARCHAR(300) NULL,
        stav ENUM('pending','confirmed','cancelled','no_show') DEFAULT 'confirmed',
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_datum (datum, cas_od),
        INDEX idx_stul (stul_id),
        FOREIGN KEY (stul_id) REFERENCES restaurant_tables(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
");

// 🆕 v2.2 — zóny (sál / terasa / bar / salon)
$pdo->exec("
    CREATE TABLE IF NOT EXISTS restaurant_zones (
        id INT AUTO_INCREMENT PRIMARY KEY,
        nazev VARCHAR(60) NOT NULL,
        ikona VARCHAR(8) DEFAULT '🍽️',
        canvas_w INT DEFAULT 800,
        canvas_h INT DEFAULT 500,
        bg_barva VARCHAR(12) DEFAULT '#FFFAF1',
        sort_order INT DEFAULT 0,
        aktivni TINYINT(1) DEFAULT 1,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_sort (sort_order)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
");

// 🆕 v3.0.201 — otevírací doba po dnech v týdnu (0=Po … 6=Ne). Kalendář rezervací se podle ní přizpůsobí.
$pdo->exec("
    CREATE TABLE IF NOT EXISTS restaurant_hours (
        den TINYINT NOT NULL PRIMARY KEY,
        otevreno_od TIME NOT NULL DEFAULT '11:00:00',
        otevreno_do TIME NOT NULL DEFAULT '23:00:00',
        zavreno TINYINT(1) NOT NULL DEFAULT 0
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
");
// Seed všech 7 dnů (jen pokud chybí) — výchozí 11:00–23:00, nic zavřeno
for ($d = 0; $d <= 6; $d++) {
    $pdo->prepare("INSERT IGNORE INTO restaurant_hours (den, otevreno_od, otevreno_do, zavreno) VALUES (:d, '11:00:00', '23:00:00', 0)")
        ->execute(['d' => $d]);
}

// 🆕 v2.2 — rozšíření restaurant_tables o floor plan + stav + POS hooks
function ensure_table_columns(PDO $pdo): void {
    static $done = false;
    if ($done) return;
    $done = true;
    try {
        $cols = $pdo->query("SHOW COLUMNS FROM restaurant_tables")->fetchAll(PDO::FETCH_COLUMN);
        $migrations = [
            'width'        => "ALTER TABLE restaurant_tables ADD COLUMN width INT NOT NULL DEFAULT 80",
            'height'       => "ALTER TABLE restaurant_tables ADD COLUMN height INT NOT NULL DEFAULT 80",
            'rotace'       => "ALTER TABLE restaurant_tables ADD COLUMN rotace INT NOT NULL DEFAULT 0",
            'barva'        => "ALTER TABLE restaurant_tables ADD COLUMN barva VARCHAR(12) DEFAULT NULL",
            'zone_id'      => "ALTER TABLE restaurant_tables ADD COLUMN zone_id INT NULL",
            'stav'         => "ALTER TABLE restaurant_tables ADD COLUMN stav ENUM('free','reserved','occupied','cleaning','attention','disabled') NOT NULL DEFAULT 'free'",
            'stav_od'      => "ALTER TABLE restaurant_tables ADD COLUMN stav_od DATETIME NULL",
            'hostu_aktual' => "ALTER TABLE restaurant_tables ADD COLUMN hostu_aktual INT DEFAULT 0",
            'cislista'     => "ALTER TABLE restaurant_tables ADD COLUMN cislista INT DEFAULT 0",
            'obsluhuje'    => "ALTER TABLE restaurant_tables ADD COLUMN obsluhuje VARCHAR(80) DEFAULT NULL",
        ];
        foreach ($migrations as $col => $sql) {
            if (!in_array($col, $cols, true)) {
                try { $pdo->exec($sql); } catch (Throwable $e) { /* ignore */ }
            }
        }
        // index na zone_id (po přidání sloupce)
        try { $pdo->exec("CREATE INDEX idx_zone_id ON restaurant_tables(zone_id)"); } catch (Throwable $e) { /* exists */ }
        try { $pdo->exec("CREATE INDEX idx_stav ON restaurant_tables(stav)"); } catch (Throwable $e) { /* exists */ }
    } catch (Throwable $e) { /* ignore */ }
}
ensure_table_columns($pdo);

// 🆕 v3.0.209 — kolik OTEVŘENÝCH POS účtů (kasa) je na daných stolech (nebo globálně).
//   Guard proti zničení nezaplacených účtů destruktivními floor-plan operacemi:
//   restaurant_pos_ucty.stul_id má FK ON DELETE CASCADE → smazání stolu smaže i otevřený účet
//   (+ table_reservations, restaurant_qr_orders/sessions). Proto guard + soft-delete (aktivni=0).
function pos_open_count(PDO $pdo, ?array $tableIds = null): int {
    try {
        $sql = "SELECT COUNT(*) FROM restaurant_pos_ucty WHERE stav = 'open'";
        if ($tableIds !== null) {
            $ids = array_values(array_filter(array_map('intval', $tableIds)));
            if (empty($ids)) return 0;
            $sql .= " AND stul_id IN (" . implode(',', $ids) . ")";
        }
        return (int) $pdo->query($sql)->fetchColumn();
    } catch (Throwable $e) { return 0; }
}

// Default zone — pokud žádná není, vytvoř "Hlavní sál"
try {
    $cnt = (int) $pdo->query("SELECT COUNT(*) FROM restaurant_zones")->fetchColumn();
    if ($cnt === 0) {
        $pdo->exec("INSERT INTO restaurant_zones (nazev, ikona, sort_order) VALUES ('Hlavní sál', '🍽️', 0)");
    }
} catch (Throwable $e) { /* ignore */ }

$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? '';
$id     = (int) ($_GET['id'] ?? 0);
$date   = $_GET['date'] ?? date('Y-m-d');

// =============================================================
// 🗺️ ŠABLONY (Pizzerie / Restaurace / Cafe / Bar / Banquet)
// =============================================================
function default_templates(): array {
    // 🆕 v3.0.31 — KOMPLETNĚ PŘEPRACOVANÉ PĚKNÉ TEMPLATES
    // Smazány staré gridové (pizzerie/restaurace/cafe/bar/banquet)
    // Nové: vyladěné spacingy, asymetrie, profi rozložení
    return [
        // ─── 🍕 PIZZERIA MODENA ─────────────────────────────────
        'pizzerie_modena' => [
            'key' => 'pizzerie_modena',
            'nazev' => '🍕 Pizzeria Modena',
            'popis' => 'Italské bistro · bar pult + 12 stolů ve 3 sekcích · venkovní terasa',
            'canvas_w' => 1000, 'canvas_h' => 680,
            'zones' => [
                ['nazev' => 'Vnitřek',  'ikona' => '🏠', 'canvas_w' => 1000, 'canvas_h' => 680, 'bg_barva' => '#FFF8F0', 'sort_order' => 0],
                ['nazev' => 'Terasa',   'ikona' => '🌳', 'canvas_w' => 800,  'canvas_h' => 500, 'bg_barva' => '#F0FDF4', 'sort_order' => 1],
            ],
            'tables' => [
                // VNITŘEK — Bar pult nahoře (přes celou šířku, vyšší)
                ['nazev' => '🍺 Bar pult',  'tvar' => 'rect',  'mist' => 8, 'x' => 80,  'y' => 60,  'width' => 840, 'height' => 80,  'zone_idx' => 0],
                // Sekce A: 4 stolky pro 2 v řadě (kruhy)
                ['nazev' => 'A1', 'tvar' => 'round', 'mist' => 2, 'x' => 80,  'y' => 200, 'width' => 90, 'height' => 90, 'zone_idx' => 0],
                ['nazev' => 'A2', 'tvar' => 'round', 'mist' => 2, 'x' => 220, 'y' => 200, 'width' => 90, 'height' => 90, 'zone_idx' => 0],
                ['nazev' => 'A3', 'tvar' => 'round', 'mist' => 2, 'x' => 360, 'y' => 200, 'width' => 90, 'height' => 90, 'zone_idx' => 0],
                ['nazev' => 'A4', 'tvar' => 'round', 'mist' => 2, 'x' => 500, 'y' => 200, 'width' => 90, 'height' => 90, 'zone_idx' => 0],
                // Sekce B: 4 čtverce pro 4
                ['nazev' => 'B1', 'tvar' => 'square', 'mist' => 4, 'x' => 660, 'y' => 200, 'width' => 110, 'height' => 110, 'zone_idx' => 0],
                ['nazev' => 'B2', 'tvar' => 'square', 'mist' => 4, 'x' => 810, 'y' => 200, 'width' => 110, 'height' => 110, 'zone_idx' => 0],
                ['nazev' => 'B3', 'tvar' => 'square', 'mist' => 4, 'x' => 660, 'y' => 350, 'width' => 110, 'height' => 110, 'zone_idx' => 0],
                ['nazev' => 'B4', 'tvar' => 'square', 'mist' => 4, 'x' => 810, 'y' => 350, 'width' => 110, 'height' => 110, 'zone_idx' => 0],
                // Sekce C: Rodinný + VIP boxy
                ['nazev' => '🍕 Rodinný',  'tvar' => 'rect',   'mist' => 8, 'x' => 80,  'y' => 380, 'width' => 280, 'height' => 100, 'zone_idx' => 0],
                ['nazev' => '🥂 VIP box',  'tvar' => 'square', 'mist' => 6, 'x' => 420, 'y' => 380, 'width' => 200, 'height' => 100, 'zone_idx' => 0],
                // Dolní řada — 6 stolků pro 2 (krátké)
                ['nazev' => 'C1', 'tvar' => 'round', 'mist' => 2, 'x' => 80,  'y' => 540, 'width' => 80, 'height' => 80, 'zone_idx' => 0],
                ['nazev' => 'C2', 'tvar' => 'round', 'mist' => 2, 'x' => 200, 'y' => 540, 'width' => 80, 'height' => 80, 'zone_idx' => 0],
                ['nazev' => 'C3', 'tvar' => 'round', 'mist' => 2, 'x' => 320, 'y' => 540, 'width' => 80, 'height' => 80, 'zone_idx' => 0],
                ['nazev' => 'C4', 'tvar' => 'round', 'mist' => 2, 'x' => 440, 'y' => 540, 'width' => 80, 'height' => 80, 'zone_idx' => 0],
                ['nazev' => 'C5', 'tvar' => 'round', 'mist' => 2, 'x' => 560, 'y' => 540, 'width' => 80, 'height' => 80, 'zone_idx' => 0],
                ['nazev' => 'C6', 'tvar' => 'round', 'mist' => 2, 'x' => 680, 'y' => 540, 'width' => 80, 'height' => 80, 'zone_idx' => 0],
                // TERASA — 8 stolků pod stromem
                ['nazev' => '🌳',     'tvar' => 'round',  'mist' => 0, 'x' => 340, 'y' => 200, 'width' => 120, 'height' => 100, 'zone_idx' => 1],
                ['nazev' => 'T1', 'tvar' => 'round', 'mist' => 2, 'x' => 80,  'y' => 80,  'width' => 80, 'height' => 80, 'zone_idx' => 1],
                ['nazev' => 'T2', 'tvar' => 'round', 'mist' => 2, 'x' => 220, 'y' => 80,  'width' => 80, 'height' => 80, 'zone_idx' => 1],
                ['nazev' => 'T3', 'tvar' => 'round', 'mist' => 4, 'x' => 500, 'y' => 80,  'width' => 100, 'height' => 100, 'zone_idx' => 1],
                ['nazev' => 'T4', 'tvar' => 'round', 'mist' => 4, 'x' => 660, 'y' => 80,  'width' => 100, 'height' => 100, 'zone_idx' => 1],
                ['nazev' => 'T5', 'tvar' => 'square','mist' => 4, 'x' => 80,  'y' => 340, 'width' => 100, 'height' => 100, 'zone_idx' => 1],
                ['nazev' => 'T6', 'tvar' => 'square','mist' => 4, 'x' => 240, 'y' => 340, 'width' => 100, 'height' => 100, 'zone_idx' => 1],
                ['nazev' => 'T7', 'tvar' => 'square','mist' => 6, 'x' => 500, 'y' => 340, 'width' => 130, 'height' => 110, 'zone_idx' => 1],
                ['nazev' => 'T8', 'tvar' => 'square','mist' => 4, 'x' => 670, 'y' => 340, 'width' => 100, 'height' => 100, 'zone_idx' => 1],
            ],
        ],
        // ─── 🍽️ BISTRO VERDE ─────────────────────────────────
        'bistro_verde' => [
            'key' => 'bistro_verde',
            'nazev' => '🍽️ Bistro Verde',
            'popis' => 'Moderní bistro · 3 zóny · lounge sezení + 2 VIP salonky',
            'canvas_w' => 1100, 'canvas_h' => 720,
            'zones' => [
                ['nazev' => 'Hlavní sál', 'ikona' => '🍽️', 'canvas_w' => 1100, 'canvas_h' => 720, 'bg_barva' => '#FFFAF1', 'sort_order' => 0],
                ['nazev' => 'Salonek A',  'ikona' => '🥂', 'canvas_w' => 560,  'canvas_h' => 420, 'bg_barva' => '#FEF3C7', 'sort_order' => 1],
                ['nazev' => 'Salonek B',  'ikona' => '🍷', 'canvas_w' => 560,  'canvas_h' => 420, 'bg_barva' => '#FCE7F3', 'sort_order' => 2],
            ],
            'tables' => [
                // HLAVNÍ SÁL — Vlevo lounge sekce (3 sedačky)
                ['nazev' => '🛋️ Lounge A', 'tvar' => 'rect', 'mist' => 4, 'x' => 80,  'y' => 80,  'width' => 240, 'height' => 100, 'zone_idx' => 0],
                ['nazev' => '🛋️ Lounge B', 'tvar' => 'rect', 'mist' => 4, 'x' => 80,  'y' => 220, 'width' => 240, 'height' => 100, 'zone_idx' => 0],
                ['nazev' => '🛋️ Lounge C', 'tvar' => 'rect', 'mist' => 4, 'x' => 80,  'y' => 360, 'width' => 240, 'height' => 100, 'zone_idx' => 0],
                // Středová zóna — kruhy v diamondu
                ['nazev' => 'M1', 'tvar' => 'round',  'mist' => 4, 'x' => 400, 'y' => 100, 'width' => 110, 'height' => 110, 'zone_idx' => 0],
                ['nazev' => 'M2', 'tvar' => 'round',  'mist' => 4, 'x' => 580, 'y' => 100, 'width' => 110, 'height' => 110, 'zone_idx' => 0],
                ['nazev' => 'M3', 'tvar' => 'round',  'mist' => 6, 'x' => 760, 'y' => 100, 'width' => 130, 'height' => 130, 'zone_idx' => 0],
                ['nazev' => 'M4', 'tvar' => 'round',  'mist' => 4, 'x' => 400, 'y' => 280, 'width' => 110, 'height' => 110, 'zone_idx' => 0],
                ['nazev' => 'M5', 'tvar' => 'round',  'mist' => 4, 'x' => 580, 'y' => 280, 'width' => 110, 'height' => 110, 'zone_idx' => 0],
                ['nazev' => 'M6', 'tvar' => 'round',  'mist' => 6, 'x' => 760, 'y' => 280, 'width' => 130, 'height' => 130, 'zone_idx' => 0],
                // Společný stůl
                ['nazev' => '🍽️ Společný',  'tvar' => 'rect', 'mist' => 14, 'x' => 360, 'y' => 500, 'width' => 700, 'height' => 100, 'zone_idx' => 0],
                ['nazev' => '🍺 Bar pult',   'tvar' => 'rect', 'mist' => 8,  'x' => 80,  'y' => 500, 'width' => 240, 'height' => 70,  'zone_idx' => 0],
                // SALONEK A
                ['nazev' => '🥂 VIP-A1', 'tvar' => 'rect',  'mist' => 8, 'x' => 80, 'y' => 80,  'width' => 400, 'height' => 100, 'zone_idx' => 1],
                ['nazev' => 'A2',  'tvar' => 'round', 'mist' => 4, 'x' => 120, 'y' => 240, 'width' => 110, 'height' => 110, 'zone_idx' => 1],
                ['nazev' => 'A3',  'tvar' => 'round', 'mist' => 4, 'x' => 330, 'y' => 240, 'width' => 110, 'height' => 110, 'zone_idx' => 1],
                // SALONEK B
                ['nazev' => '🍷 VIP-B1', 'tvar' => 'rect',   'mist' => 10, 'x' => 80, 'y' => 80,  'width' => 400, 'height' => 100, 'zone_idx' => 2],
                ['nazev' => '🍷 B2',     'tvar' => 'square', 'mist' => 6,  'x' => 100, 'y' => 240, 'width' => 130, 'height' => 130, 'zone_idx' => 2],
                ['nazev' => '🍷 B3',     'tvar' => 'square', 'mist' => 6,  'x' => 320, 'y' => 240, 'width' => 130, 'height' => 130, 'zone_idx' => 2],
            ],
        ],
        // ─── 🍷 WINE BAR APOLLO ─────────────────────────────────
        'wine_bar_apollo' => [
            'key' => 'wine_bar_apollo',
            'nazev' => '🍷 Wine bar Apollo',
            'popis' => 'Vinotéka · U-shaped bar + lounge stolky · intimní atmosféra',
            'canvas_w' => 1000, 'canvas_h' => 620,
            'zones' => [
                ['nazev' => 'Wine bar', 'ikona' => '🍷', 'canvas_w' => 1000, 'canvas_h' => 620, 'bg_barva' => '#1F1B16', 'sort_order' => 0],
            ],
            'tables' => [
                // U-shaped bar pult
                ['nazev' => '🍷 Bar L',  'tvar' => 'rect', 'mist' => 4, 'x' => 80,  'y' => 80,  'width' => 90,  'height' => 320, 'zone_idx' => 0],
                ['nazev' => '🍷 Bar T',  'tvar' => 'rect', 'mist' => 8, 'x' => 180, 'y' => 80,  'width' => 640, 'height' => 90,  'zone_idx' => 0],
                ['nazev' => '🍷 Bar R',  'tvar' => 'rect', 'mist' => 4, 'x' => 830, 'y' => 80,  'width' => 90,  'height' => 320, 'zone_idx' => 0],
                // Barové stoličky kolem T-baru (6 ks)
                ['nazev' => 'b1', 'tvar' => 'round', 'mist' => 1, 'x' => 220, 'y' => 200, 'width' => 60, 'height' => 60, 'zone_idx' => 0],
                ['nazev' => 'b2', 'tvar' => 'round', 'mist' => 1, 'x' => 320, 'y' => 200, 'width' => 60, 'height' => 60, 'zone_idx' => 0],
                ['nazev' => 'b3', 'tvar' => 'round', 'mist' => 1, 'x' => 420, 'y' => 200, 'width' => 60, 'height' => 60, 'zone_idx' => 0],
                ['nazev' => 'b4', 'tvar' => 'round', 'mist' => 1, 'x' => 520, 'y' => 200, 'width' => 60, 'height' => 60, 'zone_idx' => 0],
                ['nazev' => 'b5', 'tvar' => 'round', 'mist' => 1, 'x' => 620, 'y' => 200, 'width' => 60, 'height' => 60, 'zone_idx' => 0],
                ['nazev' => 'b6', 'tvar' => 'round', 'mist' => 1, 'x' => 720, 'y' => 200, 'width' => 60, 'height' => 60, 'zone_idx' => 0],
                // Lounge stolky uprostřed (4 ks)
                ['nazev' => 'V1', 'tvar' => 'square', 'mist' => 4, 'x' => 220, 'y' => 320, 'width' => 130, 'height' => 130, 'zone_idx' => 0],
                ['nazev' => 'V2', 'tvar' => 'square', 'mist' => 4, 'x' => 400, 'y' => 320, 'width' => 130, 'height' => 130, 'zone_idx' => 0],
                ['nazev' => 'V3', 'tvar' => 'square', 'mist' => 4, 'x' => 580, 'y' => 320, 'width' => 130, 'height' => 130, 'zone_idx' => 0],
                ['nazev' => 'V4', 'tvar' => 'square', 'mist' => 4, 'x' => 760, 'y' => 460, 'width' => 130, 'height' => 130, 'zone_idx' => 0],
                // Dolní řada — kruhy pro 2
                ['nazev' => 'D1', 'tvar' => 'round', 'mist' => 2, 'x' => 240, 'y' => 490, 'width' => 80, 'height' => 80, 'zone_idx' => 0],
                ['nazev' => 'D2', 'tvar' => 'round', 'mist' => 2, 'x' => 420, 'y' => 490, 'width' => 80, 'height' => 80, 'zone_idx' => 0],
                ['nazev' => 'D3', 'tvar' => 'round', 'mist' => 2, 'x' => 600, 'y' => 490, 'width' => 80, 'height' => 80, 'zone_idx' => 0],
            ],
        ],
        // ─── ☕ CAFE AURELIO ─────────────────────────────────
        'cafe_aurelio' => [
            'key' => 'cafe_aurelio',
            'nazev' => '☕ Cafe Aurelio',
            'popis' => 'Útulná kavárna · 8 stolků pro 2 + 4 pro 4 · pultové sezení',
            'canvas_w' => 900, 'canvas_h' => 580,
            'zones' => [
                ['nazev' => 'Kavárna', 'ikona' => '☕', 'canvas_w' => 900, 'canvas_h' => 580, 'bg_barva' => '#FFF8F0', 'sort_order' => 0],
            ],
            'tables' => [
                // Pultové sezení nahoře (3 sedátka)
                ['nazev' => '☕ Pult',    'tvar' => 'rect',   'mist' => 5, 'x' => 80,  'y' => 60,  'width' => 740, 'height' => 70, 'zone_idx' => 0],
                // 2 řady kulatých stolků pro 2
                ['nazev' => 'K1', 'tvar' => 'round', 'mist' => 2, 'x' => 80,  'y' => 180, 'width' => 80, 'height' => 80, 'zone_idx' => 0],
                ['nazev' => 'K2', 'tvar' => 'round', 'mist' => 2, 'x' => 220, 'y' => 180, 'width' => 80, 'height' => 80, 'zone_idx' => 0],
                ['nazev' => 'K3', 'tvar' => 'round', 'mist' => 2, 'x' => 360, 'y' => 180, 'width' => 80, 'height' => 80, 'zone_idx' => 0],
                ['nazev' => 'K4', 'tvar' => 'round', 'mist' => 2, 'x' => 500, 'y' => 180, 'width' => 80, 'height' => 80, 'zone_idx' => 0],
                ['nazev' => 'K5', 'tvar' => 'round', 'mist' => 2, 'x' => 640, 'y' => 180, 'width' => 80, 'height' => 80, 'zone_idx' => 0],
                ['nazev' => 'K6', 'tvar' => 'round', 'mist' => 2, 'x' => 760, 'y' => 180, 'width' => 80, 'height' => 80, 'zone_idx' => 0],
                ['nazev' => 'K7', 'tvar' => 'round', 'mist' => 2, 'x' => 80,  'y' => 300, 'width' => 80, 'height' => 80, 'zone_idx' => 0],
                ['nazev' => 'K8', 'tvar' => 'round', 'mist' => 2, 'x' => 220, 'y' => 300, 'width' => 80, 'height' => 80, 'zone_idx' => 0],
                // Větší stolky pro 4 ve spodní polovině
                ['nazev' => 'L1', 'tvar' => 'square', 'mist' => 4, 'x' => 380, 'y' => 290, 'width' => 110, 'height' => 110, 'zone_idx' => 0],
                ['nazev' => 'L2', 'tvar' => 'square', 'mist' => 4, 'x' => 540, 'y' => 290, 'width' => 110, 'height' => 110, 'zone_idx' => 0],
                ['nazev' => 'L3', 'tvar' => 'square', 'mist' => 4, 'x' => 700, 'y' => 290, 'width' => 110, 'height' => 110, 'zone_idx' => 0],
                // Dlouhý společný stůl dole
                ['nazev' => '☕ Komunitní', 'tvar' => 'rect', 'mist' => 10, 'x' => 80, 'y' => 440, 'width' => 740, 'height' => 90, 'zone_idx' => 0],
            ],
        ],
        // ─── 🌳 LETNÍ ZAHRADA ─────────────────────────────────
        // 🆕 v3.0.37, 🎨 v3.0.40 — Symetrické rozmístění, vyplněný střed,
        //   strom vizuálně dominantní, žádné mrtvé prostory
        'letni_zahrada' => [
            'key' => 'letni_zahrada',
            'nazev' => '🌳 Letní zahrada',
            'popis' => 'Venkovní zahrada · outdoor bar + pergola · symetrické rozmístění s dominantním stromem',
            'canvas_w' => 1200, 'canvas_h' => 720,
            'zones' => [
                ['nazev' => 'Zahrada',  'ikona' => '🌳', 'canvas_w' => 1200, 'canvas_h' => 720, 'bg_barva' => '#ECFDF5', 'sort_order' => 0],
                ['nazev' => 'Pergola',  'ikona' => '🍃', 'canvas_w' => 800,  'canvas_h' => 460, 'bg_barva' => '#D1FAE5', 'sort_order' => 1],
            ],
            'tables' => [
                // ─── ZAHRADA ─────────────────────────────────────
                // 1) Outdoor bar — top full width
                ['nazev' => '🍹 Outdoor bar', 'tvar' => 'rect', 'mist' => 8, 'x' => 60, 'y' => 50, 'width' => 1080, 'height' => 75, 'zone_idx' => 0],

                // 2) Levá zóna — 4 slunečníkové stolky (2x2)
                ['nazev' => 'Z1', 'tvar' => 'round', 'mist' => 4, 'x' => 80,  'y' => 160, 'width' => 100, 'height' => 100, 'zone_idx' => 0],
                ['nazev' => 'Z2', 'tvar' => 'round', 'mist' => 4, 'x' => 210, 'y' => 160, 'width' => 100, 'height' => 100, 'zone_idx' => 0],
                ['nazev' => 'Z3', 'tvar' => 'round', 'mist' => 4, 'x' => 80,  'y' => 290, 'width' => 100, 'height' => 100, 'zone_idx' => 0],
                ['nazev' => 'Z4', 'tvar' => 'round', 'mist' => 4, 'x' => 210, 'y' => 290, 'width' => 100, 'height' => 100, 'zone_idx' => 0],

                // 3) Středová vizuální dominanta — VELKÝ STROM (0 míst, dekorace)
                ['nazev' => '🌳', 'tvar' => 'round', 'mist' => 0, 'x' => 360, 'y' => 160, 'width' => 220, 'height' => 230, 'zone_idx' => 0],

                // 4) Květinová dekorace v rozích kolem stromu
                ['nazev' => '🌹', 'tvar' => 'round', 'mist' => 0, 'x' => 340, 'y' => 110, 'width' => 50, 'height' => 50, 'zone_idx' => 0],
                ['nazev' => '🌷', 'tvar' => 'round', 'mist' => 0, 'x' => 560, 'y' => 110, 'width' => 50, 'height' => 50, 'zone_idx' => 0],

                // 5) Pravá zóna — 4 rodinné čtverce + 2 menší kruhy nahoře pravý
                ['nazev' => 'Z5', 'tvar' => 'round', 'mist' => 4, 'x' => 640, 'y' => 160, 'width' => 100, 'height' => 100, 'zone_idx' => 0],
                ['nazev' => 'Z6', 'tvar' => 'round', 'mist' => 4, 'x' => 770, 'y' => 160, 'width' => 100, 'height' => 100, 'zone_idx' => 0],
                ['nazev' => 'Z7', 'tvar' => 'round', 'mist' => 4, 'x' => 900, 'y' => 160, 'width' => 100, 'height' => 100, 'zone_idx' => 0],
                ['nazev' => 'Z8', 'tvar' => 'round', 'mist' => 4, 'x' => 1030,'y' => 160, 'width' => 100, 'height' => 100, 'zone_idx' => 0],
                ['nazev' => 'Z9',  'tvar' => 'square', 'mist' => 6, 'x' => 640, 'y' => 290, 'width' => 110, 'height' => 110, 'zone_idx' => 0],
                ['nazev' => 'Z10', 'tvar' => 'square', 'mist' => 6, 'x' => 780, 'y' => 290, 'width' => 110, 'height' => 110, 'zone_idx' => 0],
                ['nazev' => 'Z11', 'tvar' => 'square', 'mist' => 6, 'x' => 920, 'y' => 290, 'width' => 110, 'height' => 110, 'zone_idx' => 0],
                ['nazev' => 'Z12', 'tvar' => 'square', 'mist' => 6, 'x' => 1060,'y' => 290, 'width' => 80,  'height' => 110, 'zone_idx' => 0],

                // 6) Spodní řada — 2 rodinné stoly + dva piknikové komunitní vedle sebe
                ['nazev' => '🍽️ Rodinný 1', 'tvar' => 'rect', 'mist' => 8,  'x' => 60,  'y' => 430, 'width' => 280, 'height' => 95, 'zone_idx' => 0],
                ['nazev' => '🍽️ Rodinný 2', 'tvar' => 'rect', 'mist' => 8,  'x' => 360, 'y' => 430, 'width' => 280, 'height' => 95, 'zone_idx' => 0],
                ['nazev' => '🍽️ Rodinný 3', 'tvar' => 'rect', 'mist' => 8,  'x' => 660, 'y' => 430, 'width' => 280, 'height' => 95, 'zone_idx' => 0],
                ['nazev' => '🍽️ Rodinný 4', 'tvar' => 'rect', 'mist' => 6,  'x' => 960, 'y' => 430, 'width' => 180, 'height' => 95, 'zone_idx' => 0],

                // 7) Piknik komunitní stůl — celá šíře dole
                ['nazev' => '🌳 Piknik komunitní', 'tvar' => 'rect', 'mist' => 18, 'x' => 60, 'y' => 555, 'width' => 1080, 'height' => 105, 'zone_idx' => 0],

                // ─── PERGOLA ─────────────────────────────────────
                ['nazev' => '🍃 Pergola Lounge A', 'tvar' => 'rect',  'mist' => 8, 'x' => 60,  'y' => 60,  'width' => 320, 'height' => 100, 'zone_idx' => 1],
                ['nazev' => '🍃 Pergola Lounge B', 'tvar' => 'rect',  'mist' => 8, 'x' => 400, 'y' => 60,  'width' => 320, 'height' => 100, 'zone_idx' => 1],
                ['nazev' => 'P1', 'tvar' => 'round',  'mist' => 4, 'x' => 80,  'y' => 200, 'width' => 110, 'height' => 110, 'zone_idx' => 1],
                ['nazev' => 'P2', 'tvar' => 'round',  'mist' => 4, 'x' => 230, 'y' => 200, 'width' => 110, 'height' => 110, 'zone_idx' => 1],
                ['nazev' => 'P3', 'tvar' => 'round',  'mist' => 4, 'x' => 380, 'y' => 200, 'width' => 110, 'height' => 110, 'zone_idx' => 1],
                ['nazev' => 'P4', 'tvar' => 'round',  'mist' => 4, 'x' => 530, 'y' => 200, 'width' => 110, 'height' => 110, 'zone_idx' => 1],
                ['nazev' => 'P5', 'tvar' => 'square', 'mist' => 6, 'x' => 100, 'y' => 340, 'width' => 130, 'height' => 100, 'zone_idx' => 1],
                ['nazev' => 'P6', 'tvar' => 'square', 'mist' => 6, 'x' => 260, 'y' => 340, 'width' => 130, 'height' => 100, 'zone_idx' => 1],
                ['nazev' => 'P7', 'tvar' => 'square', 'mist' => 6, 'x' => 420, 'y' => 340, 'width' => 130, 'height' => 100, 'zone_idx' => 1],
                ['nazev' => 'P8', 'tvar' => 'square', 'mist' => 6, 'x' => 580, 'y' => 340, 'width' => 130, 'height' => 100, 'zone_idx' => 1],
            ],
        ],
        // ─── 🍺 PIVNICE PLZEŇ ─────────────────────────────────
        // 🆕 v3.0.37 — Czech beer hall s communal tables
        'pivnice_plzen' => [
            'key' => 'pivnice_plzen',
            'nazev' => '🍺 Pivnice Plzeň',
            'popis' => 'Česká pivnice · dlouhé stoly · pivní tap bar · party',
            'canvas_w' => 1100, 'canvas_h' => 680,
            'zones' => [
                ['nazev' => 'Pivnice', 'ikona' => '🍺', 'canvas_w' => 1100, 'canvas_h' => 680, 'bg_barva' => '#FFFBEB', 'sort_order' => 0],
            ],
            'tables' => [
                // Tap bar nalevo (vertical)
                ['nazev' => '🍺 Tap bar',  'tvar' => 'rect', 'mist' => 10, 'x' => 60,  'y' => 60,  'width' => 110, 'height' => 560, 'zone_idx' => 0],
                // 4 dlouhé stoly horizontálně (každý pro 12)
                ['nazev' => '🍻 Dlouhý 1', 'tvar' => 'rect', 'mist' => 12, 'x' => 220, 'y' => 80,  'width' => 800, 'height' => 90, 'zone_idx' => 0],
                ['nazev' => '🍻 Dlouhý 2', 'tvar' => 'rect', 'mist' => 12, 'x' => 220, 'y' => 200, 'width' => 800, 'height' => 90, 'zone_idx' => 0],
                ['nazev' => '🍻 Dlouhý 3', 'tvar' => 'rect', 'mist' => 12, 'x' => 220, 'y' => 320, 'width' => 800, 'height' => 90, 'zone_idx' => 0],
                ['nazev' => '🍻 Dlouhý 4', 'tvar' => 'rect', 'mist' => 12, 'x' => 220, 'y' => 440, 'width' => 800, 'height' => 90, 'zone_idx' => 0],
                // Spodní řada — menší stolky pro 4
                ['nazev' => 'M1', 'tvar' => 'square', 'mist' => 4, 'x' => 220, 'y' => 570, 'width' => 100, 'height' => 100, 'zone_idx' => 0],
                ['nazev' => 'M2', 'tvar' => 'square', 'mist' => 4, 'x' => 360, 'y' => 570, 'width' => 100, 'height' => 100, 'zone_idx' => 0],
                ['nazev' => 'M3', 'tvar' => 'square', 'mist' => 4, 'x' => 500, 'y' => 570, 'width' => 100, 'height' => 100, 'zone_idx' => 0],
                ['nazev' => 'M4', 'tvar' => 'square', 'mist' => 4, 'x' => 640, 'y' => 570, 'width' => 100, 'height' => 100, 'zone_idx' => 0],
                ['nazev' => 'M5', 'tvar' => 'square', 'mist' => 4, 'x' => 780, 'y' => 570, 'width' => 100, 'height' => 100, 'zone_idx' => 0],
                ['nazev' => 'M6', 'tvar' => 'square', 'mist' => 4, 'x' => 920, 'y' => 570, 'width' => 100, 'height' => 100, 'zone_idx' => 0],
            ],
        ],
        // ─── 🎉 BANKETNÍ SÁL ─────────────────────────────────
        // 🆕 v3.0.37 — Event/banquet hall pro svatby, oslavy
        'banketni_sal' => [
            'key' => 'banketni_sal',
            'nazev' => '🎉 Banketní sál',
            'popis' => 'Eventy · svatby · oslavy · 6 dlouhých stolů + parket',
            'canvas_w' => 1200, 'canvas_h' => 720,
            'zones' => [
                ['nazev' => 'Banketní sál', 'ikona' => '🎉', 'canvas_w' => 1200, 'canvas_h' => 720, 'bg_barva' => '#FAF5FF', 'sort_order' => 0],
                ['nazev' => 'Bar zóna',     'ikona' => '🍾', 'canvas_w' => 700,  'canvas_h' => 420, 'bg_barva' => '#F3E8FF', 'sort_order' => 1],
            ],
            'tables' => [
                // Hlavní stůl novomanželů (nahoře, široký)
                ['nazev' => '💍 Hlavní stůl', 'tvar' => 'rect', 'mist' => 12, 'x' => 240, 'y' => 60, 'width' => 720, 'height' => 90, 'zone_idx' => 0],
                // 6 dlouhých stolů (2 řady po 3) — každý pro 10
                ['nazev' => 'B1', 'tvar' => 'rect', 'mist' => 10, 'x' => 100, 'y' => 200, 'width' => 320, 'height' => 80, 'zone_idx' => 0],
                ['nazev' => 'B2', 'tvar' => 'rect', 'mist' => 10, 'x' => 440, 'y' => 200, 'width' => 320, 'height' => 80, 'zone_idx' => 0],
                ['nazev' => 'B3', 'tvar' => 'rect', 'mist' => 10, 'x' => 780, 'y' => 200, 'width' => 320, 'height' => 80, 'zone_idx' => 0],
                ['nazev' => 'B4', 'tvar' => 'rect', 'mist' => 10, 'x' => 100, 'y' => 310, 'width' => 320, 'height' => 80, 'zone_idx' => 0],
                ['nazev' => 'B5', 'tvar' => 'rect', 'mist' => 10, 'x' => 440, 'y' => 310, 'width' => 320, 'height' => 80, 'zone_idx' => 0],
                ['nazev' => 'B6', 'tvar' => 'rect', 'mist' => 10, 'x' => 780, 'y' => 310, 'width' => 320, 'height' => 80, 'zone_idx' => 0],
                // Taneční parket (visuální označení, 0 míst)
                ['nazev' => '💃 Parket', 'tvar' => 'rect', 'mist' => 0, 'x' => 280, 'y' => 440, 'width' => 640, 'height' => 200, 'zone_idx' => 0],
                // Boční DJ pulty
                ['nazev' => '🎵 DJ', 'tvar' => 'square', 'mist' => 0, 'x' => 100, 'y' => 480, 'width' => 130, 'height' => 130, 'zone_idx' => 0],
                ['nazev' => '🎤 Pódium', 'tvar' => 'rect', 'mist' => 0, 'x' => 980, 'y' => 480, 'width' => 130, 'height' => 130, 'zone_idx' => 0],
                // BAR ZÓNA — cocktail bar + lounge
                ['nazev' => '🍾 Champagne bar', 'tvar' => 'rect', 'mist' => 8, 'x' => 80, 'y' => 80, 'width' => 540, 'height' => 90, 'zone_idx' => 1],
                ['nazev' => '🛋️ Lounge 1', 'tvar' => 'rect', 'mist' => 6, 'x' => 80,  'y' => 220, 'width' => 250, 'height' => 100, 'zone_idx' => 1],
                ['nazev' => '🛋️ Lounge 2', 'tvar' => 'rect', 'mist' => 6, 'x' => 370, 'y' => 220, 'width' => 250, 'height' => 100, 'zone_idx' => 1],
            ],
        ],
        // ─── 🥩 STEAKHOUSE GRAND ─────────────────────────────────
        // 🆕 v3.0.37 — Premium steakhouse s booths
        'steakhouse_grand' => [
            'key' => 'steakhouse_grand',
            'nazev' => '🥩 Steakhouse Grand',
            'popis' => 'Premium steakhouse · booths podél stěn · VIP boxy · grill bar',
            'canvas_w' => 1200, 'canvas_h' => 720,
            'zones' => [
                ['nazev' => 'Hlavní sál', 'ikona' => '🥩', 'canvas_w' => 1200, 'canvas_h' => 720, 'bg_barva' => '#1F1410', 'sort_order' => 0],
                ['nazev' => 'VIP boxy',   'ikona' => '🥃', 'canvas_w' => 700,  'canvas_h' => 440, 'bg_barva' => '#3D2817', 'sort_order' => 1],
            ],
            'tables' => [
                // Levá stěna — booth tables (vertikální obdélníky)
                ['nazev' => 'B1', 'tvar' => 'rect', 'mist' => 4, 'x' => 60, 'y' => 80,  'width' => 130, 'height' => 100, 'zone_idx' => 0],
                ['nazev' => 'B2', 'tvar' => 'rect', 'mist' => 4, 'x' => 60, 'y' => 200, 'width' => 130, 'height' => 100, 'zone_idx' => 0],
                ['nazev' => 'B3', 'tvar' => 'rect', 'mist' => 4, 'x' => 60, 'y' => 320, 'width' => 130, 'height' => 100, 'zone_idx' => 0],
                ['nazev' => 'B4', 'tvar' => 'rect', 'mist' => 4, 'x' => 60, 'y' => 440, 'width' => 130, 'height' => 100, 'zone_idx' => 0],
                ['nazev' => 'B5', 'tvar' => 'rect', 'mist' => 6, 'x' => 60, 'y' => 560, 'width' => 130, 'height' => 130, 'zone_idx' => 0],
                // Pravá stěna — booth tables
                ['nazev' => 'B6', 'tvar' => 'rect', 'mist' => 4, 'x' => 1010, 'y' => 80,  'width' => 130, 'height' => 100, 'zone_idx' => 0],
                ['nazev' => 'B7', 'tvar' => 'rect', 'mist' => 4, 'x' => 1010, 'y' => 200, 'width' => 130, 'height' => 100, 'zone_idx' => 0],
                ['nazev' => 'B8', 'tvar' => 'rect', 'mist' => 4, 'x' => 1010, 'y' => 320, 'width' => 130, 'height' => 100, 'zone_idx' => 0],
                ['nazev' => 'B9', 'tvar' => 'rect', 'mist' => 4, 'x' => 1010, 'y' => 440, 'width' => 130, 'height' => 100, 'zone_idx' => 0],
                ['nazev' => 'B10','tvar' => 'rect', 'mist' => 6, 'x' => 1010, 'y' => 560, 'width' => 130, 'height' => 130, 'zone_idx' => 0],
                // Open grill bar uprostřed nahoře
                ['nazev' => '🔥 Open grill', 'tvar' => 'rect', 'mist' => 6, 'x' => 280, 'y' => 80, 'width' => 640, 'height' => 110, 'zone_idx' => 0],
                // Středové stoly pro 2-4
                ['nazev' => 'S1', 'tvar' => 'square', 'mist' => 4, 'x' => 280, 'y' => 230, 'width' => 110, 'height' => 110, 'zone_idx' => 0],
                ['nazev' => 'S2', 'tvar' => 'square', 'mist' => 4, 'x' => 420, 'y' => 230, 'width' => 110, 'height' => 110, 'zone_idx' => 0],
                ['nazev' => 'S3', 'tvar' => 'square', 'mist' => 4, 'x' => 560, 'y' => 230, 'width' => 110, 'height' => 110, 'zone_idx' => 0],
                ['nazev' => 'S4', 'tvar' => 'square', 'mist' => 4, 'x' => 700, 'y' => 230, 'width' => 110, 'height' => 110, 'zone_idx' => 0],
                ['nazev' => 'S5', 'tvar' => 'square', 'mist' => 4, 'x' => 840, 'y' => 230, 'width' => 110, 'height' => 110, 'zone_idx' => 0],
                // Středová promenáda — větší stoly pro 6-8
                ['nazev' => '🥩 Chef Tasting', 'tvar' => 'rect', 'mist' => 8, 'x' => 320, 'y' => 380, 'width' => 280, 'height' => 110, 'zone_idx' => 0],
                ['nazev' => '🥃 Whisky stůl',  'tvar' => 'rect', 'mist' => 6, 'x' => 640, 'y' => 380, 'width' => 250, 'height' => 110, 'zone_idx' => 0],
                ['nazev' => 'S6', 'tvar' => 'round', 'mist' => 6, 'x' => 320, 'y' => 540, 'width' => 130, 'height' => 130, 'zone_idx' => 0],
                ['nazev' => 'S7', 'tvar' => 'round', 'mist' => 6, 'x' => 490, 'y' => 540, 'width' => 130, 'height' => 130, 'zone_idx' => 0],
                ['nazev' => 'S8', 'tvar' => 'round', 'mist' => 6, 'x' => 660, 'y' => 540, 'width' => 130, 'height' => 130, 'zone_idx' => 0],
                ['nazev' => 'S9', 'tvar' => 'round', 'mist' => 6, 'x' => 830, 'y' => 540, 'width' => 130, 'height' => 130, 'zone_idx' => 0],
                // VIP BOXY — 3 privátní salonky
                ['nazev' => '🥃 VIP 1', 'tvar' => 'rect', 'mist' => 8,  'x' => 60,  'y' => 80, 'width' => 180, 'height' => 280, 'zone_idx' => 1],
                ['nazev' => '🥃 VIP 2', 'tvar' => 'rect', 'mist' => 10, 'x' => 280, 'y' => 80, 'width' => 180, 'height' => 280, 'zone_idx' => 1],
                ['nazev' => '🥃 VIP 3', 'tvar' => 'rect', 'mist' => 12, 'x' => 500, 'y' => 80, 'width' => 180, 'height' => 280, 'zone_idx' => 1],
            ],
        ],
        // ─── 🍔 BURGER BISTRO ─────────────────────────────────
        // 🆕 v3.0.37 — Modern fast-casual
        'burger_bistro' => [
            'key' => 'burger_bistro',
            'nazev' => '🍔 Burger Bistro',
            'popis' => 'Fast-casual · counter sezení · booths · pickup okénko',
            'canvas_w' => 1000, 'canvas_h' => 620,
            'zones' => [
                ['nazev' => 'Bistro', 'ikona' => '🍔', 'canvas_w' => 1000, 'canvas_h' => 620, 'bg_barva' => '#FFF7ED', 'sort_order' => 0],
            ],
            'tables' => [
                // Counter / kasa nahoře
                ['nazev' => '💳 Counter', 'tvar' => 'rect', 'mist' => 0, 'x' => 80, 'y' => 60, 'width' => 540, 'height' => 70, 'zone_idx' => 0],
                ['nazev' => '🛍️ Pickup', 'tvar' => 'rect', 'mist' => 0, 'x' => 660, 'y' => 60, 'width' => 260, 'height' => 70, 'zone_idx' => 0],
                // Counter seating barpult (vlevo)
                ['nazev' => '🍟 Bar pult', 'tvar' => 'rect', 'mist' => 6, 'x' => 80, 'y' => 160, 'width' => 540, 'height' => 80, 'zone_idx' => 0],
                // Booth tables podél pravé stěny (4)
                ['nazev' => '🪑 Box 1', 'tvar' => 'rect', 'mist' => 4, 'x' => 670, 'y' => 160, 'width' => 250, 'height' => 100, 'zone_idx' => 0],
                ['nazev' => '🪑 Box 2', 'tvar' => 'rect', 'mist' => 4, 'x' => 670, 'y' => 280, 'width' => 250, 'height' => 100, 'zone_idx' => 0],
                ['nazev' => '🪑 Box 3', 'tvar' => 'rect', 'mist' => 4, 'x' => 670, 'y' => 400, 'width' => 250, 'height' => 100, 'zone_idx' => 0],
                ['nazev' => '🪑 Box 4', 'tvar' => 'rect', 'mist' => 6, 'x' => 670, 'y' => 520, 'width' => 250, 'height' => 80,  'zone_idx' => 0],
                // Středové stoly pro 4
                ['nazev' => 'T1', 'tvar' => 'square', 'mist' => 4, 'x' => 80,  'y' => 280, 'width' => 110, 'height' => 110, 'zone_idx' => 0],
                ['nazev' => 'T2', 'tvar' => 'square', 'mist' => 4, 'x' => 220, 'y' => 280, 'width' => 110, 'height' => 110, 'zone_idx' => 0],
                ['nazev' => 'T3', 'tvar' => 'square', 'mist' => 4, 'x' => 360, 'y' => 280, 'width' => 110, 'height' => 110, 'zone_idx' => 0],
                ['nazev' => 'T4', 'tvar' => 'square', 'mist' => 4, 'x' => 500, 'y' => 280, 'width' => 110, 'height' => 110, 'zone_idx' => 0],
                // Spodní řada — kruhy pro 2
                ['nazev' => 'T5', 'tvar' => 'round', 'mist' => 2, 'x' => 80,  'y' => 420, 'width' => 80, 'height' => 80, 'zone_idx' => 0],
                ['nazev' => 'T6', 'tvar' => 'round', 'mist' => 2, 'x' => 200, 'y' => 420, 'width' => 80, 'height' => 80, 'zone_idx' => 0],
                ['nazev' => 'T7', 'tvar' => 'round', 'mist' => 2, 'x' => 320, 'y' => 420, 'width' => 80, 'height' => 80, 'zone_idx' => 0],
                ['nazev' => 'T8', 'tvar' => 'round', 'mist' => 2, 'x' => 440, 'y' => 420, 'width' => 80, 'height' => 80, 'zone_idx' => 0],
                ['nazev' => 'T9', 'tvar' => 'round', 'mist' => 4, 'x' => 80,  'y' => 530, 'width' => 100, 'height' => 100, 'zone_idx' => 0],
                ['nazev' => 'T10','tvar' => 'round', 'mist' => 4, 'x' => 220, 'y' => 530, 'width' => 100, 'height' => 100, 'zone_idx' => 0],
                ['nazev' => 'T11','tvar' => 'round', 'mist' => 4, 'x' => 360, 'y' => 530, 'width' => 100, 'height' => 100, 'zone_idx' => 0],
                ['nazev' => 'T12','tvar' => 'round', 'mist' => 4, 'x' => 500, 'y' => 530, 'width' => 100, 'height' => 100, 'zone_idx' => 0],
            ],
        ],
        // ─── 🏙️ ROOF TOP PRAHA ─────────────────────────────────
        // 🆕 v3.0.37 — Rooftop terrace s výhledem
        'rooftop_praha' => [
            'key' => 'rooftop_praha',
            'nazev' => '🏙️ Rooftop Praha',
            'popis' => 'Střešní terasa · cocktail bar · lounge sofas · sunset views',
            'canvas_w' => 1100, 'canvas_h' => 650,
            'zones' => [
                ['nazev' => 'Rooftop', 'ikona' => '🏙️', 'canvas_w' => 1100, 'canvas_h' => 650, 'bg_barva' => '#0F172A', 'sort_order' => 0],
            ],
            'tables' => [
                // Cocktail bar (rohem)
                ['nazev' => '🍸 Cocktail bar', 'tvar' => 'rect', 'mist' => 8, 'x' => 60, 'y' => 60, 'width' => 130, 'height' => 540, 'zone_idx' => 0],
                // Lounge sofas podél horního "okraje" (vyhlídka)
                ['nazev' => '🛋️ Sunset 1', 'tvar' => 'rect', 'mist' => 6, 'x' => 220, 'y' => 60, 'width' => 280, 'height' => 100, 'zone_idx' => 0],
                ['nazev' => '🛋️ Sunset 2', 'tvar' => 'rect', 'mist' => 6, 'x' => 520, 'y' => 60, 'width' => 280, 'height' => 100, 'zone_idx' => 0],
                ['nazev' => '🛋️ Sunset 3', 'tvar' => 'rect', 'mist' => 6, 'x' => 820, 'y' => 60, 'width' => 220, 'height' => 100, 'zone_idx' => 0],
                // Cocktail high stoly (round, malé)
                ['nazev' => 'H1', 'tvar' => 'round', 'mist' => 4, 'x' => 240, 'y' => 200, 'width' => 90, 'height' => 90, 'zone_idx' => 0],
                ['nazev' => 'H2', 'tvar' => 'round', 'mist' => 4, 'x' => 380, 'y' => 200, 'width' => 90, 'height' => 90, 'zone_idx' => 0],
                ['nazev' => 'H3', 'tvar' => 'round', 'mist' => 4, 'x' => 520, 'y' => 200, 'width' => 90, 'height' => 90, 'zone_idx' => 0],
                ['nazev' => 'H4', 'tvar' => 'round', 'mist' => 4, 'x' => 660, 'y' => 200, 'width' => 90, 'height' => 90, 'zone_idx' => 0],
                ['nazev' => 'H5', 'tvar' => 'round', 'mist' => 4, 'x' => 800, 'y' => 200, 'width' => 90, 'height' => 90, 'zone_idx' => 0],
                ['nazev' => 'H6', 'tvar' => 'round', 'mist' => 4, 'x' => 940, 'y' => 200, 'width' => 90, 'height' => 90, 'zone_idx' => 0],
                // Větší dining tables
                ['nazev' => 'D1', 'tvar' => 'square', 'mist' => 6, 'x' => 240, 'y' => 320, 'width' => 130, 'height' => 130, 'zone_idx' => 0],
                ['nazev' => 'D2', 'tvar' => 'square', 'mist' => 6, 'x' => 410, 'y' => 320, 'width' => 130, 'height' => 130, 'zone_idx' => 0],
                ['nazev' => 'D3', 'tvar' => 'square', 'mist' => 6, 'x' => 580, 'y' => 320, 'width' => 130, 'height' => 130, 'zone_idx' => 0],
                ['nazev' => 'D4', 'tvar' => 'square', 'mist' => 6, 'x' => 750, 'y' => 320, 'width' => 130, 'height' => 130, 'zone_idx' => 0],
                ['nazev' => 'D5', 'tvar' => 'square', 'mist' => 8, 'x' => 920, 'y' => 320, 'width' => 130, 'height' => 130, 'zone_idx' => 0],
                // DJ a fire pit dole
                ['nazev' => '🎧 DJ booth', 'tvar' => 'rect', 'mist' => 0, 'x' => 240, 'y' => 490, 'width' => 240, 'height' => 110, 'zone_idx' => 0],
                ['nazev' => '🔥 Fire pit', 'tvar' => 'round', 'mist' => 6, 'x' => 540, 'y' => 480, 'width' => 150, 'height' => 150, 'zone_idx' => 0],
                ['nazev' => 'D6', 'tvar' => 'round', 'mist' => 6, 'x' => 740, 'y' => 490, 'width' => 130, 'height' => 130, 'zone_idx' => 0],
                ['nazev' => 'D7', 'tvar' => 'round', 'mist' => 6, 'x' => 920, 'y' => 490, 'width' => 130, 'height' => 130, 'zone_idx' => 0],
            ],
        ],
        // ─── 🍣 SUSHI & ASIA ─────────────────────────────────
        // 🆕 v3.0.37 — Asian fusion s sushi barem
        'sushi_asia' => [
            'key' => 'sushi_asia',
            'nazev' => '🍣 Sushi & Asia',
            'popis' => 'Asijský fusion · sushi bar · low-seating · privátní booths',
            'canvas_w' => 1100, 'canvas_h' => 680,
            'zones' => [
                ['nazev' => 'Hlavní sál', 'ikona' => '🍣', 'canvas_w' => 1100, 'canvas_h' => 680, 'bg_barva' => '#1A1A2E', 'sort_order' => 0],
                ['nazev' => 'Tatami room', 'ikona' => '🎋', 'canvas_w' => 700,  'canvas_h' => 460, 'bg_barva' => '#16213E', 'sort_order' => 1],
            ],
            'tables' => [
                // Sushi bar U-shape (3 segmenty)
                ['nazev' => '🍣 Sushi L', 'tvar' => 'rect', 'mist' => 5, 'x' => 80,  'y' => 80,  'width' => 100, 'height' => 360, 'zone_idx' => 0],
                ['nazev' => '🍣 Sushi T', 'tvar' => 'rect', 'mist' => 10,'x' => 190, 'y' => 80,  'width' => 660, 'height' => 100, 'zone_idx' => 0],
                ['nazev' => '🍣 Sushi R', 'tvar' => 'rect', 'mist' => 5, 'x' => 860, 'y' => 80,  'width' => 100, 'height' => 360, 'zone_idx' => 0],
                // Bar stools u Sushi T (8 ks)
                ['nazev' => 's1', 'tvar' => 'round', 'mist' => 1, 'x' => 220, 'y' => 200, 'width' => 60, 'height' => 60, 'zone_idx' => 0],
                ['nazev' => 's2', 'tvar' => 'round', 'mist' => 1, 'x' => 300, 'y' => 200, 'width' => 60, 'height' => 60, 'zone_idx' => 0],
                ['nazev' => 's3', 'tvar' => 'round', 'mist' => 1, 'x' => 380, 'y' => 200, 'width' => 60, 'height' => 60, 'zone_idx' => 0],
                ['nazev' => 's4', 'tvar' => 'round', 'mist' => 1, 'x' => 460, 'y' => 200, 'width' => 60, 'height' => 60, 'zone_idx' => 0],
                ['nazev' => 's5', 'tvar' => 'round', 'mist' => 1, 'x' => 540, 'y' => 200, 'width' => 60, 'height' => 60, 'zone_idx' => 0],
                ['nazev' => 's6', 'tvar' => 'round', 'mist' => 1, 'x' => 620, 'y' => 200, 'width' => 60, 'height' => 60, 'zone_idx' => 0],
                ['nazev' => 's7', 'tvar' => 'round', 'mist' => 1, 'x' => 700, 'y' => 200, 'width' => 60, 'height' => 60, 'zone_idx' => 0],
                ['nazev' => 's8', 'tvar' => 'round', 'mist' => 1, 'x' => 780, 'y' => 200, 'width' => 60, 'height' => 60, 'zone_idx' => 0],
                // Středové stoly (low Asian seating)
                ['nazev' => 'A1', 'tvar' => 'square', 'mist' => 4, 'x' => 220, 'y' => 320, 'width' => 110, 'height' => 110, 'zone_idx' => 0],
                ['nazev' => 'A2', 'tvar' => 'square', 'mist' => 4, 'x' => 360, 'y' => 320, 'width' => 110, 'height' => 110, 'zone_idx' => 0],
                ['nazev' => 'A3', 'tvar' => 'square', 'mist' => 4, 'x' => 500, 'y' => 320, 'width' => 110, 'height' => 110, 'zone_idx' => 0],
                ['nazev' => 'A4', 'tvar' => 'square', 'mist' => 4, 'x' => 640, 'y' => 320, 'width' => 110, 'height' => 110, 'zone_idx' => 0],
                ['nazev' => 'A5', 'tvar' => 'square', 'mist' => 4, 'x' => 780, 'y' => 320, 'width' => 110, 'height' => 110, 'zone_idx' => 0],
                // Dolní řada — Teppanyaki grill tables
                ['nazev' => '🔥 Teppanyaki 1', 'tvar' => 'rect', 'mist' => 8, 'x' => 80,  'y' => 470, 'width' => 380, 'height' => 130, 'zone_idx' => 0],
                ['nazev' => '🔥 Teppanyaki 2', 'tvar' => 'rect', 'mist' => 8, 'x' => 500, 'y' => 470, 'width' => 380, 'height' => 130, 'zone_idx' => 0],
                ['nazev' => '🍵', 'tvar' => 'round', 'mist' => 0, 'x' => 920, 'y' => 480, 'width' => 110, 'height' => 110, 'zone_idx' => 0],
                // TATAMI ROOM — privátní zone (3 low tables)
                ['nazev' => '🎋 Tatami 1', 'tvar' => 'square', 'mist' => 6, 'x' => 80,  'y' => 80,  'width' => 180, 'height' => 180, 'zone_idx' => 1],
                ['nazev' => '🎋 Tatami 2', 'tvar' => 'square', 'mist' => 6, 'x' => 280, 'y' => 80,  'width' => 180, 'height' => 180, 'zone_idx' => 1],
                ['nazev' => '🎋 Tatami 3', 'tvar' => 'square', 'mist' => 6, 'x' => 480, 'y' => 80,  'width' => 180, 'height' => 180, 'zone_idx' => 1],
                ['nazev' => '🎋 VIP suite', 'tvar' => 'rect', 'mist' => 12, 'x' => 80, 'y' => 280, 'width' => 580, 'height' => 140, 'zone_idx' => 1],
            ],
        ],
        // ─── ⬜ PRÁZDNÉ ─────────────────────────────────
        'empty' => [
            'key' => 'empty',
            'nazev' => '⬜ Prázdné',
            'popis' => 'Začni od nuly — žádné stoly, jen výchozí zóna',
            'canvas_w' => 900, 'canvas_h' => 600,
            'zones' => [['nazev' => 'Hlavní sál', 'ikona' => '🍽️', 'canvas_w' => 900, 'canvas_h' => 600, 'sort_order' => 0]],
            'tables' => [],
        ],
    ];
}

// =============================================================
// 📤 ENDPOINTS
// =============================================================

// 📋 SEZNAM ŠABLON
if ($method === 'GET' && $action === 'templates') {
    json_response(['templates' => array_values(default_templates())]);
}

// 📦 APPLY TEMPLATE (wipe + insert)
if ($method === 'POST' && $action === 'apply_template') {
    require_super_admin();
    $d = json_input();
    $tplKey = $d['template'] ?? '';
    $merge  = !empty($d['merge']); // pokud true, NESMAŽE existující, přidá vedle
    // 🆕 v3.0.189 — keep_zones: přepiš JEN stoly, existující zóny zachovej (user:
    //   „potřebuji jenom stoly přepsat, ostatní zóny zůstanou jak jsou"). Stoly šablony
    //   se namapují na existující zóny dle pořadí (zone_idx → N-tá zóna, clamp).
    $keepZones = !empty($d['keep_zones']);
    $templates = default_templates();
    if (!isset($templates[$tplKey])) json_error('Neznámá šablona: ' . $tplKey, 400);
    $tpl = $templates[$tplKey];

    // 🆕 v3.0.208 — ADITIVNÍ mód: přidat stoly šablony do JEDNÉ (aktuální) zóny.
    //   Nemaže NIC (stávající stoly i ostatní zóny zůstanou). User: „přidám ze šablony
    //   stoly do zóny bar" — dřív to šlo jen destruktivně (replace + rozhoz dle indexu).
    if (($d['mode'] ?? '') === 'add_zone') {
        ensure_table_columns($pdo);
        $targetZone = (int) ($d['target_zone_id'] ?? 0);
        if (!$targetZone) json_error('Chybí cílová zóna', 400);
        $zq = $pdo->prepare("SELECT nazev FROM restaurant_zones WHERE id = :id AND COALESCE(aktivni,1)=1");
        $zq->execute(['id' => $targetZone]);
        $zoneName = $zq->fetchColumn();
        if ($zoneName === false) json_error('Cílová zóna neexistuje', 404);
        try {
            $pdo->beginTransaction();
            // Nové stoly umísti POD stávající stoly v zóně (offset Y) + unikátní názvy.
            $maxBottom = (int) $pdo->query("SELECT COALESCE(MAX(y + height), 0) FROM restaurant_tables WHERE zone_id = " . (int) $targetZone . " AND COALESCE(aktivni,1)=1")->fetchColumn();
            $offsetY = $maxBottom > 0 ? $maxBottom + 30 : 0;
            $used = array_flip($pdo->query("SELECT nazev FROM restaurant_tables WHERE zone_id = " . (int) $targetZone)->fetchAll(PDO::FETCH_COLUMN));
            $tIns = $pdo->prepare("
                INSERT INTO restaurant_tables (nazev, mist, sekce, x, y, width, height, tvar, zone_id, stav, aktivni)
                VALUES (:n,:m,:s,:x,:y,:w,:h,:t,:z,'free',1)
            ");
            $cnt = 0;
            foreach ($tpl['tables'] as $t) {
                $base = (string) ($t['nazev'] ?? 'S'); $name = $base; $k = 2;
                while (isset($used[$name])) { $name = $base . '·' . $k; $k++; }
                $used[$name] = true;
                $tIns->execute([
                    'n' => $name, 'm' => (int) ($t['mist'] ?? 2), 's' => $zoneName,
                    'x' => (int) ($t['x'] ?? 0), 'y' => (int) ($t['y'] ?? 0) + $offsetY,
                    'w' => (int) ($t['width'] ?? 80), 'h' => (int) ($t['height'] ?? 80),
                    't' => $t['tvar'] ?? 'square', 'z' => $targetZone,
                ]);
                $cnt++;
            }
            $pdo->commit();
            json_response(['ok' => true, 'template' => $tplKey, 'stoly' => $cnt, 'mode' => 'add_zone', 'zone_id' => $targetZone, 'zone_nazev' => $zoneName]);
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            json_error_safe('Přidání stolů do zóny selhalo', $e, 500);
        }
    }

    // 🆕 v3.0.209 — destruktivní režimy (keep/full) nesmí běžet s otevřenými účty (CASCADE wipe kasy)
    if (pos_open_count($pdo) > 0) {
        json_error('Máš otevřené účty (kasa) — nejdřív je zavři, pak měň layout šablonou.', 409);
    }

    try {
        $pdo->beginTransaction();
        $zoneIds = [];  // template zone_idx → DB zone id
        if ($keepZones) {
            // 🆕 v3.0.209 — soft-delete stolů (zachová rezervace/historii, žádný CASCADE), pak namapuj na existující zóny.
            $pdo->exec("UPDATE restaurant_tables SET aktivni = 0 WHERE COALESCE(aktivni,1) = 1");
            $existing = $pdo->query("SELECT id FROM restaurant_zones WHERE COALESCE(aktivni,1)=1 ORDER BY sort_order, id")->fetchAll(PDO::FETCH_COLUMN);
            if (empty($existing)) {
                // Žádná zóna neexistuje → fallback: vytvoř zóny ze šablony (jako full).
                foreach ($tpl['zones'] as $idx => $z) {
                    $stmt = $pdo->prepare("INSERT INTO restaurant_zones (nazev, ikona, canvas_w, canvas_h, sort_order) VALUES (:n, :i, :w, :h, :s)");
                    $stmt->execute(['n' => $z['nazev'], 'i' => $z['ikona'] ?? '🍽️', 'w' => $z['canvas_w'] ?? 800, 'h' => $z['canvas_h'] ?? 500, 's' => $z['sort_order'] ?? $idx]);
                    $zoneIds[$idx] = (int) $pdo->lastInsertId();
                }
            } else {
                // Mapuj každou template-zónu na existující (clamp na poslední).
                foreach ($tpl['zones'] as $idx => $z) {
                    $zoneIds[$idx] = (int) ($existing[$idx] ?? $existing[count($existing) - 1]);
                }
            }
        } else {
            if (!$merge) {
                // 🆕 v3.0.209 — soft-delete (aktivni=0) místo hard DELETE → zachová rezervace/historii (žádný CASCADE)
                $pdo->exec("UPDATE restaurant_tables SET aktivni = 0 WHERE COALESCE(aktivni,1) = 1");
                $pdo->exec("UPDATE restaurant_zones SET aktivni = 0 WHERE COALESCE(aktivni,1) = 1");
            }
            // Insert zóny
            foreach ($tpl['zones'] as $idx => $z) {
                $stmt = $pdo->prepare("
                    INSERT INTO restaurant_zones (nazev, ikona, canvas_w, canvas_h, sort_order)
                    VALUES (:n, :i, :w, :h, :s)
                ");
                $stmt->execute([
                    'n' => $z['nazev'], 'i' => $z['ikona'] ?? '🍽️',
                    'w' => $z['canvas_w'] ?? 800, 'h' => $z['canvas_h'] ?? 500,
                    's' => $z['sort_order'] ?? $idx,
                ]);
                $zoneIds[$idx] = (int) $pdo->lastInsertId();
            }
        }
        // Insert stoly
        $tStmt = $pdo->prepare("
            INSERT INTO restaurant_tables (nazev, mist, sekce, x, y, width, height, tvar, zone_id, stav)
            VALUES (:n, :m, :s, :x, :y, :w, :h, :t, :z, 'free')
        ");
        foreach ($tpl['tables'] as $t) {
            $zi = $t['zone_idx'] ?? 0;
            $tStmt->execute([
                'n' => $t['nazev'], 'm' => (int) ($t['mist'] ?? 2),
                's' => $tpl['zones'][$zi]['nazev'] ?? null, // legacy sekce
                'x' => (int) ($t['x'] ?? 0), 'y' => (int) ($t['y'] ?? 0),
                'w' => (int) ($t['width'] ?? 80), 'h' => (int) ($t['height'] ?? 80),
                't' => $t['tvar'] ?? 'square',
                'z' => $zoneIds[$zi] ?? null,
            ]);
        }
        $pdo->commit();
        json_response(['ok' => true, 'template' => $tplKey, 'stoly' => count($tpl['tables']), 'zones' => ($keepZones ? 'zachovány' : count($tpl['zones'])), 'mode' => ($keepZones ? 'keep_zones' : ($merge ? 'merge' : 'full'))]);
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        json_error_safe('Apply template selhalo', $e, 500);
    }
}

// 🗂️ ZÓNY — list / create / update / delete
if ($method === 'GET' && $action === 'zones') {
    $z = $pdo->query("SELECT * FROM restaurant_zones WHERE aktivni = 1 ORDER BY sort_order, id")->fetchAll();
    json_response(['zones' => $z]);
}
if ($method === 'POST' && $action === 'zone_save') {
    require_super_admin();
    $d = json_input();
    $zid = (int) ($d['id'] ?? 0);
    $params = [
        'n' => trim($d['nazev'] ?? 'Nová zóna'),
        'i' => trim($d['ikona'] ?? '🍽️'),
        'w' => (int) ($d['canvas_w'] ?? 800),
        'h' => (int) ($d['canvas_h'] ?? 500),
        's' => (int) ($d['sort_order'] ?? 0),
    ];
    if ($zid > 0) {
        $params['id'] = $zid;
        $pdo->prepare("UPDATE restaurant_zones SET nazev=:n, ikona=:i, canvas_w=:w, canvas_h=:h, sort_order=:s WHERE id=:id")->execute($params);
        json_response(['ok' => true, 'id' => $zid]);
    } else {
        $pdo->prepare("INSERT INTO restaurant_zones (nazev, ikona, canvas_w, canvas_h, sort_order) VALUES (:n, :i, :w, :h, :s)")->execute($params);
        json_response(['ok' => true, 'id' => (int) $pdo->lastInsertId()]);
    }
}
if ($method === 'DELETE' && $action === 'zone' && $id) {
    require_super_admin();
    // 🆕 v3.0.209 — guard: žádný stůl v zóně nesmí mít otevřený účet (jinak CASCADE smaže kasu)
    $zTableIds = $pdo->query("SELECT id FROM restaurant_tables WHERE zone_id = " . (int) $id . " AND COALESCE(aktivni,1)=1")->fetchAll(PDO::FETCH_COLUMN);
    if (pos_open_count($pdo, $zTableIds) > 0) {
        json_error('V zóně je otevřený účet — nejdřív ho zavři, pak smaž zónu.', 409);
    }
    // Soft-delete (aktivni=0) zóny i jejích stolů → zachová rezervace/historii (žádný CASCADE).
    $pdo->prepare("UPDATE restaurant_tables SET aktivni = 0 WHERE zone_id = :z")->execute(['z' => $id]);
    $pdo->prepare("UPDATE restaurant_zones SET aktivni = 0 WHERE id = :id")->execute(['id' => $id]);
    json_response(['ok' => true]);
}

// 💾 BULK SAVE LAYOUT (po drag-drop)
if ($method === 'POST' && $action === 'save_layout') {
    require_super_admin();
    $d = json_input();
    $tables = $d['tables'] ?? [];
    if (!is_array($tables)) json_error('tables musí být pole', 400);
    try {
        $pdo->beginTransaction();
        $stmt = $pdo->prepare("
            UPDATE restaurant_tables
            SET x = :x, y = :y, width = :w, height = :h, zone_id = :z, rotace = :r
            WHERE id = :id
        ");
        $upd = 0;
        foreach ($tables as $t) {
            if (empty($t['id'])) continue;
            $stmt->execute([
                'id' => (int) $t['id'],
                'x'  => (int) ($t['x'] ?? 0),
                'y'  => (int) ($t['y'] ?? 0),
                'w'  => (int) ($t['width'] ?? 80),
                'h'  => (int) ($t['height'] ?? 80),
                'z'  => $t['zone_id'] !== null ? (int) $t['zone_id'] : null,
                'r'  => (int) ($t['rotace'] ?? 0),
            ]);
            $upd++;
        }
        $pdo->commit();
        json_response(['ok' => true, 'updated' => $upd]);
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        json_error_safe('Save layout selhalo', $e, 500);
    }
}

// 🚦 SET STATE (rychlá změna stavu jednoho stolu)
if ($method === 'POST' && $action === 'set_state') {
    $d = json_input();
    $tid = (int) ($d['id'] ?? 0);
    $stav = $d['stav'] ?? '';
    $allowed = ['free','reserved','occupied','cleaning','attention','disabled'];
    if (!$tid || !in_array($stav, $allowed, true)) json_error('Neplatný stav/ID', 400);
    $stmt = $pdo->prepare("
        UPDATE restaurant_tables
        SET stav = :s,
            stav_od = CASE WHEN :s2 IN ('occupied','reserved') THEN NOW() ELSE NULL END,
            hostu_aktual = :h,
            obsluhuje = :ob
        WHERE id = :id
    ");
    $stmt->execute([
        's'  => $stav, 's2' => $stav,
        'h'  => (int) ($d['hostu_aktual'] ?? 0),
        'ob' => $d['obsluhuje'] ?? null,
        'id' => $tid,
    ]);
    json_response(['ok' => true]);
}

// 📋 LIST (s rezervacemi pro datum)
if ($method === 'GET' && !$action) {
    $stoly = $pdo->query("SELECT * FROM restaurant_tables WHERE aktivni = 1 ORDER BY zone_id, nazev")->fetchAll();
    $zones = $pdo->query("SELECT * FROM restaurant_zones WHERE aktivni = 1 ORDER BY sort_order, id")->fetchAll();
    // Připoj aktuální rezervace
    $resStmt = $pdo->prepare("
        SELECT * FROM table_reservations
        WHERE datum = :d AND stav IN ('pending', 'confirmed')
        ORDER BY cas_od
    ");
    $resStmt->execute(['d' => $date]);
    $rezervace = $resStmt->fetchAll();
    $byTable = [];
    foreach ($rezervace as $r) {
        $byTable[$r['stul_id']] ??= [];
        $byTable[$r['stul_id']][] = $r;
    }
    foreach ($stoly as &$s) {
        $s['rezervace_dnes'] = $byTable[$s['id']] ?? [];
        $s['obsazenost_dnes'] = count($s['rezervace_dnes']);
        // Auto-derive zone for legacy data
        if (!$s['zone_id'] && $s['sekce']) {
            $zMatch = array_filter($zones, fn($z) => $z['nazev'] === $s['sekce']);
            if ($zMatch) $s['zone_id'] = (int) reset($zMatch)['id'];
        }
    }
    // 🆕 v3.0.201 — otevírací doba: celý týden + den vybraného data (0=Po..6=Ne)
    $hoursAll = $pdo->query("SELECT den, otevreno_od, otevreno_do, zavreno FROM restaurant_hours ORDER BY den")->fetchAll();
    $denIdx = (int) date('N', strtotime($date)) - 1;
    $denDnes = null;
    foreach ($hoursAll as $h) { if ((int)$h['den'] === $denIdx) { $denDnes = $h; break; } }
    json_response([
        'stoly' => $stoly,
        'zones' => $zones,
        'datum' => $date,
        'rezervace_celkem' => count($rezervace),
        'oteviraci_doba' => $hoursAll,
        'den_idx' => $denIdx,
        'den_dnes' => $denDnes,
    ]);
}

// 🆕 v3.0.201 — uložit otevírací dobu (celý týden najednou)
if ($method === 'POST' && $action === 'save_hours') {
    $d = json_input();
    $hours = $d['hours'] ?? [];
    if (!is_array($hours)) json_error('Chybí hours', 400);
    $stmt = $pdo->prepare("
        INSERT INTO restaurant_hours (den, otevreno_od, otevreno_do, zavreno)
        VALUES (:den, :od, :do_, :z)
        ON DUPLICATE KEY UPDATE otevreno_od = VALUES(otevreno_od), otevreno_do = VALUES(otevreno_do), zavreno = VALUES(zavreno)
    ");
    foreach ($hours as $h) {
        $den = (int) ($h['den'] ?? -1);
        if ($den < 0 || $den > 6) continue;
        $od = (isset($h['otevreno_od']) && preg_match('/^\d{1,2}:\d{2}/', $h['otevreno_od'])) ? substr($h['otevreno_od'], 0, 5) : '11:00';
        $do = (isset($h['otevreno_do']) && preg_match('/^\d{1,2}:\d{2}/', $h['otevreno_do'])) ? substr($h['otevreno_do'], 0, 5) : '23:00';
        $z  = !empty($h['zavreno']) ? 1 : 0;
        $stmt->execute(['den' => $den, 'od' => $od, 'do_' => $do, 'z' => $z]);
    }
    json_response(['ok' => true]);
}

// 🗓️ REZERVACE
if ($method === 'POST' && $action === 'reserve') {
    $d = json_input();
    $stulId    = (int) ($d['stul_id'] ?? 0);
    if (!$stulId) json_error('Chybí stůl', 400);
    // 🐛 v3.0.214 — validace + anti-double-booking. Dřív slepý INSERT → překryvy rezervací,
    //   obrácený čas (cas_od>cas_do) i rezervace v minulosti prošly.
    $datum = trim((string) ($d['datum'] ?? ''));
    $casOd = substr(trim((string) ($d['cas_od'] ?? '')), 0, 5);
    $casDo = substr(trim((string) ($d['cas_do'] ?? '')), 0, 5);
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $datum))         json_error('Neplatné datum', 400);
    if (!preg_match('/^\d{1,2}:\d{2}$/', $casOd) || !preg_match('/^\d{1,2}:\d{2}$/', $casDo)) json_error('Neplatný čas', 400);
    if ($datum < date('Y-m-d'))                               json_error('Nelze rezervovat v minulosti', 400);

    // 🐛 v3.0.222 — PROVÁZÁNÍ s otevírací dobou: u provozů otevřených PŘES PŮLNOC (otevreno_do
    //   ≤ otevreno_od, např. 11–03) je rezervace 23:00–01:00 legitimní. Časy < otevírací hodina
    //   patří do dalšího dne → normalizujeme na minuty (+24 h). Tím funguje i validace i overlap.
    $den = (int) date('N', strtotime($datum)) - 1; // 0=Po … 6=Ne
    $openH = 0; $crossDay = false;
    try {
        $hrs = $pdo->prepare("SELECT otevreno_od, otevreno_do, zavreno FROM restaurant_hours WHERE den = :den");
        $hrs->execute(['den' => $den]);
        if (($hrow = $hrs->fetch()) && empty($hrow['zavreno'])) {
            $oOd = substr((string) $hrow['otevreno_od'], 0, 5);
            $oDo = substr((string) $hrow['otevreno_do'], 0, 5);
            $openH = (int) substr($oOd, 0, 2);
            $crossDay = ($oDo !== '' && $oDo <= $oOd); // zavírá po půlnoci
        }
    } catch (Throwable $e) { /* tabulka nemusí existovat → bez cross-midnight */ }
    $nm = function (string $t) use ($crossDay, $openH): int {
        $h = (int) substr($t, 0, 2); $m = (int) substr($t, 3, 2);
        if ($crossDay && $h < $openH) $h += 24;
        return $h * 60 + $m;
    };
    $odMin = $nm($casOd); $doMin = $nm($casDo);
    if ($doMin <= $odMin)                                     json_error('Konec rezervace musí být po začátku', 400);

    try {
        // Překryv s aktivní rezervací téhož stolu/dne — normalizovaně (zvládá i přes půlnoc).
        $ex = $pdo->prepare("
            SELECT cas_od, cas_do FROM table_reservations
            WHERE stul_id = :s AND datum = :d AND COALESCE(stav,'confirmed') <> 'cancelled'
        ");
        $ex->execute(['s' => $stulId, 'd' => $datum]);
        foreach ($ex->fetchAll() as $r) {
            $eOd = $nm(substr((string) $r['cas_od'], 0, 5));
            $eDo = $nm(substr((string) $r['cas_do'], 0, 5));
            if ($odMin < $eDo && $doMin > $eOd) json_error('Stůl je v tomto čase už rezervovaný', 409);
        }

        $pdo->prepare("
            INSERT INTO table_reservations (stul_id, datum, cas_od, cas_do, jmeno, telefon, pocet_osob, poznamka, stav)
            VALUES (:s, :d, :od, :do_, :j, :t, :p, :pz, 'confirmed')
        ")->execute([
            's'   => $stulId,
            'd'   => $datum,
            'od'  => $casOd,
            'do_' => $casDo,
            'j'   => trim($d['jmeno'] ?? ''),
            't'   => $d['telefon'] ?? null,
            'p'   => (int) ($d['pocet_osob'] ?? 2),
            'pz'  => $d['poznamka'] ?? null,
        ]);
        json_response(['ok' => true, 'id' => (int) $pdo->lastInsertId()]);
    } catch (Throwable $e) { json_error_safe('DB', $e, 500); }
}

if ($method === 'DELETE' && $action === 'reservation') {
    require_super_admin();
    $pdo->prepare("UPDATE table_reservations SET stav = 'cancelled' WHERE id = :id")->execute(['id' => $id]);
    json_response(['ok' => true]);
}

// 🪑 CRUD STOLŮ
if ($method === 'POST' && !$action) {
    require_super_admin();
    $d = json_input();
    try {
        $pdo->prepare("
            INSERT INTO restaurant_tables
              (nazev, mist, sekce, x, y, width, height, tvar, zone_id, stav)
            VALUES
              (:n, :m, :s, :x, :y, :w, :h, :t, :z, 'free')
        ")->execute([
            'n' => $d['nazev'] ?? 'Stůl',
            'm' => (int) ($d['mist'] ?? 2),
            's' => $d['sekce'] ?? null,
            'x' => (int) ($d['x'] ?? 0),
            'y' => (int) ($d['y'] ?? 0),
            'w' => (int) ($d['width'] ?? 80),
            'h' => (int) ($d['height'] ?? 80),
            't' => $d['tvar'] ?? 'square',
            'z' => $d['zone_id'] !== null ? (int) $d['zone_id'] : null,
        ]);
        json_response(['ok' => true, 'id' => (int) $pdo->lastInsertId()]);
    } catch (Throwable $e) { json_error_safe('DB', $e, 500); }
}

if ($method === 'PUT' && $id) {
    require_super_admin();
    $d = json_input();
    $fields = ['nazev', 'mist', 'sekce', 'x', 'y', 'width', 'height', 'tvar', 'aktivni', 'zone_id', 'rotace', 'barva'];
    $sets = []; $params = ['id' => $id];
    foreach ($fields as $f) {
        if (array_key_exists($f, $d)) { $sets[] = "$f = :$f"; $params[$f] = $d[$f]; }
    }
    if ($sets) {
        $pdo->prepare("UPDATE restaurant_tables SET " . implode(', ', $sets) . " WHERE id = :id")->execute($params);
    }
    json_response(['ok' => true]);
}

if ($method === 'DELETE' && $id) {
    require_super_admin();
    // 🆕 v3.0.209 — neumaž stůl s otevřeným účtem (CASCADE by smazal nezaplacenou kasu!)
    if (pos_open_count($pdo, [$id]) > 0) {
        json_error('Stůl má otevřený účet — nejdřív ho zaplať/zavři nebo přesuň jinam.', 409);
    }
    // Soft-delete (aktivni=0) místo hard DELETE → zachová rezervace + historii (FK necascaduje).
    $pdo->prepare("UPDATE restaurant_tables SET aktivni = 0 WHERE id = :id")->execute(['id' => $id]);
    json_response(['ok' => true]);
}

// ═════════════════════════════════════════════════════════════════
// 🆕 v2.9.29 — USER-DEFINED FLOOR PLAN TEMPLATES
// Uložení/načtení vlastních šablon mapy stolů (full snapshot zóny + stoly)
// ═════════════════════════════════════════════════════════════════

// Auto-create user templates table
(function() use ($pdo) {
    try {
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS floorplan_templates (
                id INT AUTO_INCREMENT PRIMARY KEY,
                nazev VARCHAR(120) NOT NULL,
                popis VARCHAR(300) DEFAULT NULL,
                ikona VARCHAR(10) DEFAULT '🗺️',
                snapshot LONGTEXT NOT NULL,
                created_by VARCHAR(100) DEFAULT NULL,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                INDEX idx_nazev (nazev)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");
    } catch (Throwable $e) {}
})();

// 📋 GET ?action=user_templates — seznam user-defined šablon
if ($method === 'GET' && $action === 'user_templates') {
    $rows = $pdo->query("
        SELECT id, nazev, popis, ikona, created_by, created_at, updated_at,
               JSON_LENGTH(snapshot, '$.tables') AS pocet_stolu,
               JSON_LENGTH(snapshot, '$.zones')  AS pocet_zon
        FROM floorplan_templates
        ORDER BY updated_at DESC
    ")->fetchAll();
    json_response(['templates' => $rows]);
}

// 💾 POST ?action=save_user_template — uložit aktuální stav jako novou šablonu
//   body: { id?: number, nazev, popis?, ikona? }
//   Pokud id ≠ 0 → update existing; jinak insert new
//   Snapshot bere aktuální stoly + zóny
if ($method === 'POST' && $action === 'save_user_template') {
    require_super_admin();
    $d = json_input();
    $tid   = (int)($d['id'] ?? 0);
    $nazev = trim($d['nazev'] ?? '');
    if ($nazev === '') json_error('Vyplňte název šablony', 400);
    $popis = trim($d['popis'] ?? '');
    $ikona = trim($d['ikona'] ?? '🗺️');

    // Snapshot aktuálního stavu
    $zones  = $pdo->query("SELECT id, nazev, ikona, canvas_w, canvas_h, sort_order FROM restaurant_zones WHERE aktivni = 1 ORDER BY sort_order, id")->fetchAll(PDO::FETCH_ASSOC);
    $tables = $pdo->query("SELECT id, nazev, mist, x, y, width, height, tvar, zone_id, rotace, barva FROM restaurant_tables WHERE aktivni = 1")->fetchAll(PDO::FETCH_ASSOC);
    // Re-mapujeme zone_id na index (aby šablona nezávisela na DB ID)
    $zoneIdx = [];
    foreach ($zones as $i => $z) $zoneIdx[$z['id']] = $i;
    foreach ($tables as &$t) {
        $t['zone_idx'] = $zoneIdx[$t['zone_id']] ?? 0;
        unset($t['id'], $t['zone_id']);
    }
    unset($t);
    foreach ($zones as &$z) unset($z['id']);
    unset($z);

    $snapshot = json_encode([
        'version' => 1,
        'zones'   => $zones,
        'tables'  => $tables,
        'saved_at'=> date('c'),
    ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);

    $user = $_SESSION['admin_jmeno'] ?? null;
    try {
        if ($tid > 0) {
            $pdo->prepare("
                UPDATE floorplan_templates
                SET nazev=:n, popis=:p, ikona=:i, snapshot=:s
                WHERE id=:id
            ")->execute(['n'=>$nazev,'p'=>$popis,'i'=>$ikona,'s'=>$snapshot,'id'=>$tid]);
            json_response(['ok'=>true,'id'=>$tid,'message'=>'Šablona aktualizována']);
        } else {
            $pdo->prepare("
                INSERT INTO floorplan_templates (nazev, popis, ikona, snapshot, created_by)
                VALUES (:n, :p, :i, :s, :u)
            ")->execute(['n'=>$nazev,'p'=>$popis,'i'=>$ikona,'s'=>$snapshot,'u'=>$user]);
            json_response(['ok'=>true,'id'=>(int)$pdo->lastInsertId(),'message'=>'Šablona uložena']);
        }
    } catch (Throwable $e) {
        json_error_safe('Chyba uložení', $e, 500);
    }
}

// 🔄 POST ?action=apply_user_template — aplikuj uloženou šablonu (WIPE + INSERT)
//   body: { id: number, merge?: bool }
if ($method === 'POST' && $action === 'apply_user_template') {
    require_super_admin();
    $d = json_input();
    $tid = (int)($d['id'] ?? 0);
    $merge = !empty($d['merge']);
    if ($tid <= 0) json_error('Chybí id', 400);
    $st = $pdo->prepare("SELECT * FROM floorplan_templates WHERE id=:id");
    $st->execute(['id'=>$tid]);
    $tpl = $st->fetch(PDO::FETCH_ASSOC);
    if (!$tpl) json_error('Šablona nenalezena', 404);
    $snap = json_decode($tpl['snapshot'], true);
    if (!is_array($snap) || !isset($snap['zones']) || !isset($snap['tables'])) {
        json_error('Šablona je poškozená', 500);
    }
    // 🆕 v3.0.209 — destruktivní apply nesmí běžet s otevřenými účty (CASCADE wipe kasy)
    if (!$merge && pos_open_count($pdo) > 0) {
        json_error('Máš otevřené účty (kasa) — nejdřív je zavři, pak aplikuj šablonu.', 409);
    }
    try {
        $pdo->beginTransaction();
        if (!$merge) {
            // soft-delete (aktivni=0) místo hard DELETE → zachová rezervace/historii (žádný CASCADE)
            $pdo->exec("UPDATE restaurant_tables SET aktivni = 0 WHERE COALESCE(aktivni,1) = 1");
            $pdo->exec("UPDATE restaurant_zones SET aktivni = 0 WHERE COALESCE(aktivni,1) = 1");
        }
        // 🆕 v3.0.241 — při merge posuň sort_order nových zón ZA stávající (jinak by se
        //   proložily se stávajícími a taby zón by skákaly). Bez merge začínáme od 0.
        $sortBase = 0;
        if ($merge) {
            $sortBase = (int)$pdo->query("SELECT COALESCE(MAX(sort_order), -1) + 1 FROM restaurant_zones WHERE COALESCE(aktivni,1) = 1")->fetchColumn();
        }
        $zoneIds = [];
        foreach ($snap['zones'] as $idx => $z) {
            $pdo->prepare("INSERT INTO restaurant_zones (nazev, ikona, canvas_w, canvas_h, sort_order) VALUES (:n,:i,:w,:h,:s)")
                ->execute([
                    'n'=>$z['nazev'] ?? 'Zóna',
                    'i'=>$z['ikona'] ?? '🍽️',
                    'w'=>(int)($z['canvas_w'] ?? 800),
                    'h'=>(int)($z['canvas_h'] ?? 500),
                    's'=>$sortBase + (int)($z['sort_order'] ?? $idx),
                ]);
            $zoneIds[$idx] = (int)$pdo->lastInsertId();
        }
        $tStmt = $pdo->prepare("
            INSERT INTO restaurant_tables (nazev, mist, sekce, x, y, width, height, tvar, zone_id, stav, rotace, barva)
            VALUES (:n,:m,:s,:x,:y,:w,:h,:t,:z,'free',:r,:b)
        ");
        foreach ($snap['tables'] as $t) {
            $zi = $t['zone_idx'] ?? 0;
            $tStmt->execute([
                'n'=>$t['nazev'] ?? 'Stůl',
                'm'=>(int)($t['mist'] ?? 2),
                's'=>$snap['zones'][$zi]['nazev'] ?? null,
                'x'=>(int)($t['x'] ?? 0),
                'y'=>(int)($t['y'] ?? 0),
                'w'=>(int)($t['width'] ?? 80),
                'h'=>(int)($t['height'] ?? 80),
                't'=>$t['tvar'] ?? 'square',
                'z'=>$zoneIds[$zi] ?? null,
                'r'=>(int)($t['rotace'] ?? 0),
                'b'=>$t['barva'] ?? null,
            ]);
        }
        $pdo->commit();
        json_response(['ok'=>true,'stoly'=>count($snap['tables']),'zones'=>count($snap['zones'])]);
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        json_error_safe('Apply selhalo', $e, 500);
    }
}

// 🗑️ DELETE ?action=user_template&id=N
if ($method === 'DELETE' && $action === 'user_template' && $id) {
    require_super_admin();
    $pdo->prepare("DELETE FROM floorplan_templates WHERE id=:id")->execute(['id'=>$id]);
    json_response(['ok'=>true]);
}

// 📤 GET ?action=export_template&id=N — export jako JSON download
if ($method === 'GET' && $action === 'export_template' && $id) {
    $st = $pdo->prepare("SELECT * FROM floorplan_templates WHERE id=:id");
    $st->execute(['id'=>$id]);
    $tpl = $st->fetch(PDO::FETCH_ASSOC);
    if (!$tpl) json_error('Nenalezeno', 404);
    header('Content-Type: application/json');
    header('Content-Disposition: attachment; filename="floorplan-' . preg_replace('/[^a-z0-9_-]+/i', '_', $tpl['nazev']) . '.json"');
    echo $tpl['snapshot'];
    exit;
}

// 🆕 v2.9.48 — DEDICATED endpoint pro Floor Plan Editor live apply
// POST ?action=apply_editor_state
// Body: { zones: [{nazev, ikona, canvas_w, canvas_h}], tables: [{nazev, mist, x, y, w, h, type, rotace, barva, zone_idx}] }
// → WIPE restaurant_tables + restaurant_zones, INSERT fresh
if ($method === 'POST' && $action === 'apply_editor_state') {
    require_super_admin();
    $d = json_input();
    $zones  = $d['zones']  ?? [];
    $items  = $d['tables'] ?? [];
    if (!is_array($zones) || !is_array($items)) {
        json_error('Neplatný formát (zones/tables musí být pole)', 400);
    }

    $TYP_MAP = [
        'round'=>'round','square'=>'square','rect'=>'rect',
        'bar'=>'square','lounge'=>'rect',
        'wall'=>'rect','door'=>'square','bar-cnt'=>'rect',
        'kitchen'=>'rect','wc'=>'square','plant'=>'round','text'=>'square',
    ];

    try {
        ensure_table_columns($pdo); // zajisti x/y/width/height/tvar/zone_id/stav/rotace/barva/aktivni
        $pdo->beginTransaction();

        // 🆕 v3.0.202 — NEDESTRUKTIVNÍ sync. Dřív: DELETE all + reinsert → FK ON DELETE CASCADE
        //   smazal VŠECHNY rezervace a nová ID rozsynchronizovala editor (následný drag-save
        //   trefil neexistující id → nic se neuložilo). Teď: UPDATE podle dbId / INSERT nové /
        //   soft-delete odebrané (aktivni=0) → rezervace + otevřené účty + ID zůstanou.
        if (empty($zones)) {
            $zones = [['nazev' => 'Hlavní sál', 'ikona' => '🍽️', 'canvas_w' => 1200, 'canvas_h' => 800]];
        }

        // ── ZÓNY: upsert podle dbId, deaktivuj odebrané ──
        $zUpd = $pdo->prepare("UPDATE restaurant_zones SET nazev=:n, ikona=:i, canvas_w=:w, canvas_h=:h, sort_order=:s, aktivni=1 WHERE id=:id");
        $zIns = $pdo->prepare("INSERT INTO restaurant_zones (nazev, ikona, canvas_w, canvas_h, sort_order, aktivni) VALUES (:n,:i,:w,:h,:s,1)");
        $zExists = $pdo->prepare("SELECT 1 FROM restaurant_zones WHERE id=:id");
        $zoneIds = [];
        $keptZoneIds = [];
        foreach ($zones as $idx => $z) {
            $zp = [
                'n' => trim($z['nazev'] ?? ('Zóna ' . ($idx+1))),
                'i' => trim($z['ikona'] ?? '🍽️'),
                'w' => (int)($z['canvas_w'] ?? 1200),
                'h' => (int)($z['canvas_h'] ?? 800),
                's' => $idx,
            ];
            $zdb = (isset($z['dbId']) && (int)$z['dbId'] > 0) ? (int)$z['dbId'] : 0;
            if ($zdb) { $zExists->execute(['id'=>$zdb]); if (!$zExists->fetchColumn()) $zdb = 0; }
            if ($zdb) { $zUpd->execute($zp + ['id'=>$zdb]); $zoneIds[$idx] = $zdb; }
            else      { $zIns->execute($zp);                 $zoneIds[$idx] = (int)$pdo->lastInsertId(); }
            $keptZoneIds[] = $zoneIds[$idx];
        }
        if ($keptZoneIds) {
            $pdo->exec("UPDATE restaurant_zones SET aktivni=0 WHERE id NOT IN (" . implode(',', array_map('intval', $keptZoneIds)) . ")");
        }

        // ── STOLY: upsert podle dbId, soft-delete odebrané (jen skutečné stoly) ──
        $stoly_types = ['round','square','rect','bar','lounge'];
        $tUpd = $pdo->prepare("UPDATE restaurant_tables SET nazev=:n, mist=:m, sekce=:s, x=:x, y=:y, width=:w, height=:h, tvar=:t, zone_id=:z, rotace=:r, barva=:b, aktivni=1 WHERE id=:id");
        $tIns = $pdo->prepare("INSERT INTO restaurant_tables (nazev, mist, sekce, x, y, width, height, tvar, zone_id, stav, rotace, barva, aktivni) VALUES (:n,:m,:s,:x,:y,:w,:h,:t,:z,'free',:r,:b,1)");
        $tExists = $pdo->prepare("SELECT 1 FROM restaurant_tables WHERE id=:id");
        $keptTableIds = [];
        $pocet_stolu = 0;
        $celkem_mist = 0;
        foreach ($items as $it) {
            $type = $it['type'] ?? 'square';
            if (!in_array($type, $stoly_types, true)) continue; // přeskoč zeď/dveře/kuchyň/WC/text…
            $zi   = (int)($it['zone_idx'] ?? 0);
            $tvar = $TYP_MAP[$type] ?? 'square';
            $mist = (int)($it['mist'] ?? 2);
            $tp = [
                'n' => substr(trim((string)($it['nazev'] ?? 'S')), 0, 60),
                'm' => $mist,
                's' => $zones[$zi]['nazev'] ?? null,
                'x' => (int)($it['x'] ?? 0),
                'y' => (int)($it['y'] ?? 0),
                'w' => (int)($it['w'] ?? 80),
                'h' => (int)($it['h'] ?? 80),
                't' => $tvar,
                'z' => $zoneIds[$zi] ?? null,
                'r' => (int)($it['rotace'] ?? 0),
                'b' => $it['barva'] ?? null,
            ];
            $tdb = (isset($it['dbId']) && (int)$it['dbId'] > 0) ? (int)$it['dbId'] : 0;
            if ($tdb) { $tExists->execute(['id'=>$tdb]); if (!$tExists->fetchColumn()) $tdb = 0; }
            if ($tdb) { $tUpd->execute($tp + ['id'=>$tdb]); $keptTableIds[] = $tdb; }
            else      { $tIns->execute($tp);                 $keptTableIds[] = (int)$pdo->lastInsertId(); }
            $pocet_stolu++;
            $celkem_mist += $mist;
        }
        // Odebrané stoly → aktivni=0 (NEmaž → zachová rezervace/historii; LIST filtruje aktivni=1)
        if ($keptTableIds) {
            $pdo->exec("UPDATE restaurant_tables SET aktivni=0 WHERE id NOT IN (" . implode(',', array_map('intval', $keptTableIds)) . ")");
        } else {
            $pdo->exec("UPDATE restaurant_tables SET aktivni=0");
        }

        $pdo->commit();
        json_response([
            'ok' => true,
            'message' => 'Floor plan aplikován do produkce',
            'pocet_zon' => count($keptZoneIds),
            'pocet_stolu' => $pocet_stolu,
            'celkem_mist' => $celkem_mist,
        ]);
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        json_error_safe('Apply selhalo', $e, 500);
    }
}

// 🆕 v2.9.48 — Vytíženost / kapacita (pro hlavičku Floor Plan)
if ($method === 'GET' && $action === 'capacity') {
    try {
        $row = $pdo->query("
            SELECT
                COUNT(*) AS pocet_stolu,
                COALESCE(SUM(mist), 0) AS celkem_mist,
                COALESCE(SUM(CASE WHEN stav='occupied' THEN mist ELSE 0 END), 0) AS obsazeno_mist,
                COALESCE(SUM(CASE WHEN stav='reserved' THEN mist ELSE 0 END), 0) AS rezerv_mist,
                COALESCE(SUM(CASE WHEN stav IN ('free','attention') THEN mist ELSE 0 END), 0) AS volno_mist,
                COALESCE(SUM(CASE WHEN stav='occupied' THEN 1 ELSE 0 END), 0) AS pocet_obsazenych,
                COALESCE(SUM(CASE WHEN stav='reserved' THEN 1 ELSE 0 END), 0) AS pocet_rezerv
            FROM restaurant_tables WHERE aktivni = 1
        ")->fetch(PDO::FETCH_ASSOC) ?: [];
        json_response(['ok' => true, 'capacity' => $row]);
    } catch (Throwable $e) {
        json_error_safe('Chyba vytíženosti', $e, 500);
    }
}

json_error('Neznámá akce', 404);
