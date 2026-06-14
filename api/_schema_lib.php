<?php
/**
 * 🛡️ SCHEMA LIB — sdílené lazy DDL helpery pro idempotentní migrace.
 *
 * Místo aby každý admin endpoint kopíroval ALTER TABLE … ADD COLUMN bloky,
 * volá zde jednu funkci. Funkce použijí information_schema.COLUMNS check
 * a běží jen pokud sloupec/FK chybí. Bezpečné spustit opakovaně.
 *
 * Návrh: každá funkce má static $done flag, takže v rámci jednoho requestu
 * běží query maximálně jednou. Mezi requesty se znovu provede information_schema
 * check (low cost — řekne se jí, že sloupec existuje, a vrátí se hned).
 *
 * Použití:
 *   require_once __DIR__ . '/_schema_lib.php';
 *   ensure_objednavky_schema($pdo);
 *   ensure_dodaci_list_schema($pdo);
 */

/**
 * `objednavky.interni_pozn` (TEXT NULL) — sloupec očekává INSERT v admin_objednavky.php
 * ale ne všechny CREATE TABLE schémata ho měla. Bez tohohle padá vytvoření objednávky.
 */
function ensure_objednavky_schema(PDO $pdo): void {
    static $done = false;
    if ($done) return;
    $done = true;
    try {
        $cols = $pdo->query("
            SELECT COLUMN_NAME FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'objednavky'
        ")->fetchAll(PDO::FETCH_COLUMN);
        if (!$cols) return; // tabulka neexistuje — installer ji vytvoří
        if (!in_array('interni_pozn', $cols, true)) {
            $pdo->exec("ALTER TABLE objednavky ADD COLUMN interni_pozn TEXT NULL AFTER poznamka");
        }
        // 🐛 v3.0.274 — upraveno_kdy chybělo ve schématu i migracích, ale objednavky.php
        //   PUT do něj zapisuje → B2B edit objednávky 500-oval na KAŽDÉ čisté instalaci.
        if (!in_array('upraveno_kdy', $cols, true)) {
            $pdo->exec("ALTER TABLE objednavky ADD COLUMN upraveno_kdy DATETIME NULL");
        }
        // způsob platby/doručení — zapisuje POST i (nově) PUT; ať je má i fresh install
        if (!in_array('zpusob_doruceni', $cols, true)) {
            $pdo->exec("ALTER TABLE objednavky ADD COLUMN zpusob_doruceni VARCHAR(30) NULL");
        }
        if (!in_array('zpusob_platby', $cols, true)) {
            $pdo->exec("ALTER TABLE objednavky ADD COLUMN zpusob_platby VARCHAR(30) NULL");
        }
    } catch (Throwable $e) {
        error_log('ensure_objednavky_schema: ' . $e->getMessage());
    }
}

/**
 * `objednavky_polozky`:
 *   - vyrobek_id musí být NULL-able (volné řádky bez katalogu) + FK ON DELETE SET NULL
 *   - vyrobek_nazev VARCHAR(255), jednotka VARCHAR(20) — snapshot pro volné řádky
 */
function ensure_objednavky_polozky_schema(PDO $pdo): void {
    static $done = false;
    if ($done) return;
    $done = true;
    try {
        // 1. vyrobek_id → NULL + FK ON DELETE SET NULL
        $stmt = $pdo->query("
            SELECT IS_NULLABLE FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME = 'objednavky_polozky' AND COLUMN_NAME = 'vyrobek_id'
        ");
        $isNullable = $stmt->fetchColumn();
        if ($isNullable === 'NO') {
            $fk = $pdo->query("
                SELECT CONSTRAINT_NAME FROM information_schema.KEY_COLUMN_USAGE
                WHERE TABLE_SCHEMA = DATABASE()
                  AND TABLE_NAME = 'objednavky_polozky'
                  AND COLUMN_NAME = 'vyrobek_id'
                  AND REFERENCED_TABLE_NAME = 'vyrobky'
            ")->fetchColumn();
            if ($fk) $pdo->exec("ALTER TABLE objednavky_polozky DROP FOREIGN KEY `$fk`");
            $pdo->exec("ALTER TABLE objednavky_polozky MODIFY vyrobek_id INT NULL");
            if ($fk) {
                try {
                    $pdo->exec("
                        ALTER TABLE objednavky_polozky
                        ADD CONSTRAINT `$fk` FOREIGN KEY (vyrobek_id)
                        REFERENCES vyrobky(id) ON DELETE SET NULL
                    ");
                } catch (Throwable $e) { /* idempotent */ }
            }
        }

        // 2. snapshot sloupce
        $cols = $pdo->query("
            SELECT COLUMN_NAME FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'objednavky_polozky'
        ")->fetchAll(PDO::FETCH_COLUMN);
        if (!$cols) return;
        if (!in_array('vyrobek_nazev', $cols, true)) {
            $pdo->exec("ALTER TABLE objednavky_polozky ADD COLUMN vyrobek_nazev VARCHAR(255) NULL AFTER vyrobek_id");
        }
        if (!in_array('jednotka', $cols, true)) {
            $pdo->exec("ALTER TABLE objednavky_polozky ADD COLUMN jednotka VARCHAR(20) NULL AFTER vyrobek_nazev");
        }
        // 🆕 v3.0.275 — částečná POS refundace: řádek VRA- ukazuje na původní řádek účtenky
        //   → kumulativně sledujeme kolik z každé položky už vráceno (nelze přefakturovat víc).
        if (!in_array('vraci_polozku_id', $cols, true)) {
            $pdo->exec("ALTER TABLE objednavky_polozky ADD COLUMN vraci_polozku_id INT NULL");
        }
    } catch (Throwable $e) {
        error_log('ensure_objednavky_polozky_schema: ' . $e->getMessage());
    }
}

/**
 * `dodaci_list_polozky`:
 *   - vyrobek_id NULL-able + FK ON DELETE SET NULL
 *   - vyrobek_cislo VARCHAR(40), vyrobek_nazev VARCHAR(255), jednotka VARCHAR(20), poznamka TEXT
 *     — snapshot sloupce pro INSERT z hromadného generátoru i dodaci_list.php SELECTu.
 */
function ensure_dodaci_list_polozky_schema(PDO $pdo): void {
    static $done = false;
    if ($done) return;
    $done = true;
    try {
        // 1. vyrobek_id NULL + FK ON DELETE SET NULL
        $stmt = $pdo->query("
            SELECT IS_NULLABLE FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME = 'dodaci_list_polozky' AND COLUMN_NAME = 'vyrobek_id'
        ");
        $isNullable = $stmt->fetchColumn();
        if ($isNullable === 'NO') {
            $fk = $pdo->query("
                SELECT CONSTRAINT_NAME FROM information_schema.KEY_COLUMN_USAGE
                WHERE TABLE_SCHEMA = DATABASE()
                  AND TABLE_NAME = 'dodaci_list_polozky'
                  AND COLUMN_NAME = 'vyrobek_id'
                  AND REFERENCED_TABLE_NAME = 'vyrobky'
            ")->fetchColumn();
            if ($fk) $pdo->exec("ALTER TABLE dodaci_list_polozky DROP FOREIGN KEY `$fk`");
            $pdo->exec("ALTER TABLE dodaci_list_polozky MODIFY vyrobek_id INT NULL");
            if ($fk) {
                try {
                    $pdo->exec("
                        ALTER TABLE dodaci_list_polozky
                        ADD CONSTRAINT `$fk` FOREIGN KEY (vyrobek_id)
                        REFERENCES vyrobky(id) ON DELETE SET NULL
                    ");
                } catch (Throwable $e) { /* idempotent */ }
            }
        }

        // 2. snapshot sloupce — case-insensitive check (legacy DB může mít CamelCase)
        $cols = $pdo->query("
            SELECT COLUMN_NAME FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'dodaci_list_polozky'
        ")->fetchAll(PDO::FETCH_COLUMN);
        if (!$cols) return;
        $cols_lower = array_map('strtolower', $cols);
        if (!in_array('vyrobek_cislo', $cols_lower, true)) {
            $pdo->exec("ALTER TABLE dodaci_list_polozky ADD COLUMN vyrobek_cislo VARCHAR(40) NULL AFTER vyrobek_id");
        }
        if (!in_array('vyrobek_nazev', $cols_lower, true)) {
            $pdo->exec("ALTER TABLE dodaci_list_polozky ADD COLUMN vyrobek_nazev VARCHAR(255) NULL AFTER vyrobek_cislo");
        }
        if (!in_array('jednotka', $cols_lower, true)) {
            $pdo->exec("ALTER TABLE dodaci_list_polozky ADD COLUMN jednotka VARCHAR(20) NULL AFTER vyrobek_nazev");
        }
        if (!in_array('poznamka', $cols_lower, true)) {
            $pdo->exec("ALTER TABLE dodaci_list_polozky ADD COLUMN poznamka TEXT NULL");
        }
    } catch (Throwable $e) {
        error_log('ensure_dodaci_list_polozky_schema: ' . $e->getMessage());
    }
}

/**
 * `dodaci_listy` — snapshot sloupce odběratele pro doklady v čase vystavení.
 * Bez nich dodaci_list.php zahlcuje error log warnings 'Undefined array key'.
 */
function ensure_dodaci_listy_schema(PDO $pdo): void {
    static $done = false;
    if ($done) return;
    $done = true;
    try {
        $cols = $pdo->query("
            SELECT COLUMN_NAME FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'dodaci_listy'
        ")->fetchAll(PDO::FETCH_COLUMN);
        if (!$cols) return;
        $cols_lower = array_map('strtolower', $cols);
        foreach ([
            'odb_nazev_snapshot' => 'VARCHAR(255)',
            'odb_ico_snapshot'   => 'VARCHAR(20)',
            'odb_dic_snapshot'   => 'VARCHAR(20)',
            'odb_ulice_snapshot' => 'VARCHAR(255)',
            'odb_mesto_snapshot' => 'VARCHAR(120)',
            'odb_psc_snapshot'   => 'VARCHAR(15)',
        ] as $col => $type) {
            if (!in_array(strtolower($col), $cols_lower, true)) {
                $pdo->exec("ALTER TABLE dodaci_listy ADD COLUMN $col $type NULL");
            }
        }
    } catch (Throwable $e) {
        error_log('ensure_dodaci_listy_schema: ' . $e->getMessage());
    }
}

/**
 * 🆕 v3.0.11 — faktury snapshot sloupce (paralelní s dodaci_listy).
 * Diagnostika hlásí chybějící sloupce odb_*_snapshot.
 * Tyto sloupce existují v admin_faktura_z_dl.php INSERTu — bez nich faktura selže.
 */
function ensure_faktury_schema(PDO $pdo): void {
    static $done = false;
    if ($done) return;
    $done = true;
    try {
        $cols = $pdo->query("
            SELECT COLUMN_NAME FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'faktury'
        ")->fetchAll(PDO::FETCH_COLUMN);
        if (!$cols) return;
        $cols_lower = array_map('strtolower', $cols);
        foreach ([
            'odb_nazev_snapshot' => 'VARCHAR(255)',
            'odb_ico_snapshot'   => 'VARCHAR(20)',
            'odb_dic_snapshot'   => 'VARCHAR(20)',
            'odb_ulice_snapshot' => 'VARCHAR(255)',
            'odb_mesto_snapshot' => 'VARCHAR(120)',
            'odb_psc_snapshot'   => 'VARCHAR(15)',
            'rucni'              => "TINYINT(1) NOT NULL DEFAULT 0",
            // 🆕 misto_dodani_id — ruční faktura INSERT (admin_faktury.php) ho vyžaduje;
            //   bez něj "Column not found 'misto_dodani_id'" → ruční faktura nešla vytvořit.
            'misto_dodani_id'    => 'INT',
            // 🆕 v3.0.268 — VRATKY: dobropis (opravný daňový doklad) = faktura se zápornou
            //   částkou, řadou DOB- a vazbou na původní fakturu.
            'je_dobropis'        => "TINYINT(1) NOT NULL DEFAULT 0",
            'puvodni_faktura_id' => 'INT',
        ] as $col => $type) {
            if (!in_array(strtolower($col), $cols_lower, true)) {
                $pdo->exec("ALTER TABLE faktury ADD COLUMN $col $type NULL");
            }
        }

        // 🆕 faktura_polozky — admin_faktury.php detail SELECT + ruční INSERT vyžadují
        //   vyrobek_cislo + poznamka. Bez nich detail KAŽDÉ faktury padá 500
        //   (Column not found 'vyrobek_cislo') a ruční faktura nejde vytvořit.
        $pcols = $pdo->query("
            SELECT COLUMN_NAME FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'faktura_polozky'
        ")->fetchAll(PDO::FETCH_COLUMN);
        if ($pcols) {
            $pcols_lower = array_map('strtolower', $pcols);
            foreach ([
                'vyrobek_cislo' => 'VARCHAR(50)',
                'poznamka'      => 'VARCHAR(255)',
                // 🆕 v3.0.275 — částečný dobropis: řádek DOB- ukazuje na původní řádek faktury
                //   → kumulativně sledujeme kolik z každé položky už dobropisováno.
                'vraci_polozku_id' => 'INT',
            ] as $col => $type) {
                if (!in_array(strtolower($col), $pcols_lower, true)) {
                    $pdo->exec("ALTER TABLE faktura_polozky ADD COLUMN $col $type NULL");
                }
            }
        }
    } catch (Throwable $e) {
        error_log('ensure_faktury_schema: ' . $e->getMessage());
    }
}

/**
 * 🆕 v2.9.217 — sklad_pohyby tabulka (PR3 z multi-warehouse spec).
 * Audit trail všech pohybů: příjem, výdej, inventura, korekce, přesun.
 * Per pohyb: stav_pred + stav_po + cena_za_jed + poznámka + kdo + kdy.
 */
function ensure_sklad_pohyby_schema(PDO $pdo): void {
    static $done = false;
    if ($done) return;
    $done = true;
    try {
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS sklad_pohyby_v2 (
                id INT AUTO_INCREMENT PRIMARY KEY,
                sklad_id INT NOT NULL,
                sklad_id_cil INT NULL,
                item_typ ENUM('surovina','vyrobek') NOT NULL,
                item_id INT NOT NULL,
                typ ENUM('prijem','vydej','inventura','korekce','presun','vratka') NOT NULL,
                mnozstvi DECIMAL(12,3) NOT NULL,
                stav_pred DECIMAL(12,3) NULL,
                stav_po DECIMAL(12,3) NULL,
                cena_za_jed DECIMAL(10,4) NULL,
                poznamka VARCHAR(300) NULL,
                kdo VARCHAR(120) NULL,
                kdy DATETIME DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_sklad (sklad_id),
                INDEX idx_kdy (kdy),
                INDEX idx_item (item_typ, item_id),
                INDEX idx_typ (typ)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
        // Pozn.: nová tabulka 'sklad_pohyby_v2' aby koexistovala s legacy
        // 'sklad_pohyby' (jen surovina_id, bez sklad_id) — žádný conflict.

        // 🆕 v3.0.268 — VRATKY: existující instalace mají ENUM bez 'vratka' → dorovnej.
        $typCol = $pdo->query("
            SELECT COLUMN_TYPE FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'sklad_pohyby_v2' AND COLUMN_NAME = 'typ'
        ")->fetchColumn();
        if ($typCol && stripos($typCol, 'vratka') === false) {
            $pdo->exec("ALTER TABLE sklad_pohyby_v2 MODIFY typ ENUM('prijem','vydej','inventura','korekce','presun','vratka') NOT NULL");
        }
    } catch (Throwable $e) {
        error_log('ensure_sklad_pohyby_schema: ' . $e->getMessage());
    }
}

/**
 * 🆕 v2.9.216 — sklad_polozky pivot (PR2 z multi-warehouse spec).
 * Stav per (sklad × item). Migrace existujících suroviny.sklad_stav +
 * vyrobky.sklad_stav do SK01 (defaultní sklad).
 */
function ensure_sklad_polozky_schema(PDO $pdo): void {
    static $done = false;
    if ($done) return;
    $done = true;
    try {
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS sklad_polozky (
                id INT AUTO_INCREMENT PRIMARY KEY,
                sklad_id INT NOT NULL,
                item_typ ENUM('surovina','vyrobek') NOT NULL,
                item_id INT NOT NULL,
                stav DECIMAL(12,3) NOT NULL DEFAULT 0,
                min_stav DECIMAL(12,3) NULL,
                cil_stav DECIMAL(12,3) NULL,
                vytvoreno DATETIME DEFAULT CURRENT_TIMESTAMP,
                upraveno DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                UNIQUE KEY uk_sklad_item (sklad_id, item_typ, item_id),
                INDEX idx_item (item_typ, item_id),
                INDEX idx_sklad (sklad_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        // 🆕 v3.0.332 — pozice/lokace ve skladu (regál/police/bin), per (sklad × položka). Volitelné.
        $cols = $pdo->query("SHOW COLUMNS FROM sklad_polozky")->fetchAll(PDO::FETCH_COLUMN);
        if ($cols && !in_array('pozice', $cols, true)) {
            $pdo->exec("ALTER TABLE sklad_polozky ADD COLUMN pozice VARCHAR(50) NULL AFTER cil_stav");
            try { $pdo->exec("ALTER TABLE sklad_polozky ADD INDEX idx_pozice (pozice)"); } catch (Throwable $e) {}
        }

        // Migrace: pokud sklad_polozky je prázdná, naimportovat z suroviny.sklad_stav + vyrobky.sklad_stav do SK01
        $cnt = (int) $pdo->query("SELECT COUNT(*) FROM sklad_polozky")->fetchColumn();
        if ($cnt === 0) {
            $sk01Id = (int) $pdo->query("SELECT id FROM sklady WHERE kod = 'SK01' LIMIT 1")->fetchColumn();
            if ($sk01Id > 0) {
                // Suroviny — všechny (i s NULL stav → 0)
                try {
                    $pdo->exec("
                        INSERT IGNORE INTO sklad_polozky (sklad_id, item_typ, item_id, stav, min_stav)
                        SELECT $sk01Id, 'surovina', id, COALESCE(stock_aktualni, 0), stock_minimalni
                        FROM suroviny WHERE aktivni = 1
                    ");
                } catch (Throwable $e) { /* tabulka surovin možná chybí sloupce */ }
                // Výrobky
                try {
                    $pdo->exec("
                        INSERT IGNORE INTO sklad_polozky (sklad_id, item_typ, item_id, stav, min_stav, cil_stav)
                        SELECT $sk01Id, 'vyrobek', id, COALESCE(sklad_stav, 0), sklad_min, sklad_cil
                        FROM vyrobky WHERE aktivni = 1
                    ");
                } catch (Throwable $e) { /* vyrobky možná nemá sklad_* sloupce */ }
            }
        }
    } catch (Throwable $e) {
        error_log('ensure_sklad_polozky_schema: ' . $e->getMessage());
    }
}

/**
 * 🆕 v2.9.215 — Multi-warehouse `sklady` tabulka (z spec 2026-05-23).
 * Customer (pekař/firma) může mít více skladů: suchý, lednice, mrazák, atd.
 * Idempotent — vytvoří tabulku + default SK01 'Hlavní sklad'.
 */
function ensure_sklady_schema(PDO $pdo): void {
    static $done = false;
    if ($done) return;
    $done = true;
    try {
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS sklady (
                id INT AUTO_INCREMENT PRIMARY KEY,
                kod VARCHAR(20) UNIQUE NOT NULL,
                nazev VARCHAR(100) NOT NULL,
                typ ENUM('suchy','lednice','mrazak','jiny') DEFAULT 'jiny',
                teplota_min DECIMAL(4,1) NULL,
                teplota_max DECIMAL(4,1) NULL,
                adresa VARCHAR(255) NULL,
                poznamka TEXT NULL,
                aktivni TINYINT(1) NOT NULL DEFAULT 1,
                poradi INT NOT NULL DEFAULT 0,
                vytvoreno DATETIME DEFAULT CURRENT_TIMESTAMP,
                upraveno DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        // Default sklad SK01 jen pokud tabulka byla prázdná
        $cnt = (int) $pdo->query("SELECT COUNT(*) FROM sklady")->fetchColumn();
        if ($cnt === 0) {
            $pdo->exec("
                INSERT INTO sklady (kod, nazev, typ, aktivni, poradi)
                VALUES ('SK01', 'Hlavní sklad', 'jiny', 1, 0)
            ");
        }
    } catch (Throwable $e) {
        error_log('ensure_sklady_schema: ' . $e->getMessage());
    }
}
