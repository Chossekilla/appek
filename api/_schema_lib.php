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
