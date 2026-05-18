<?php
/**
 * 🔄 SYNC FOUNDATION — Schema migrations pro hybrid sync feature
 *
 * Spouští se z config.php při každém requestu (idempotentní).
 * Vytvoří tabulky a sloupce potřebné pro sync engine.
 *
 * Sync je OFF by default — instalace bez sync zapnuto pokračují bez změny chování.
 * Toggle: nastaveni.sync_enabled = '1' to aktivuje.
 */

function ensure_sync_schema(PDO $pdo): void {
    static $done = false;
    if ($done) return;
    $done = true;

    try {
        // ─────────────────────────────────────────────────────
        // 1. SYNC CONFIG — jediný řádek s nastavením sync engine
        // ─────────────────────────────────────────────────────
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS sync_config (
                id INT PRIMARY KEY DEFAULT 1,
                mode ENUM('local','hybrid','cloud') NOT NULL DEFAULT 'cloud',
                role ENUM('master','mirror') NOT NULL DEFAULT 'master',
                cloud_endpoint VARCHAR(255) NULL,
                shared_secret VARCHAR(128) NULL,
                interval_minutes INT NOT NULL DEFAULT 15,
                last_sync_at DATETIME NULL,
                last_sync_status VARCHAR(20) DEFAULT 'never',
                last_sync_records_pushed INT DEFAULT 0,
                last_sync_records_pulled INT DEFAULT 0,
                last_error TEXT NULL,
                enabled TINYINT(1) NOT NULL DEFAULT 0,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");
        // Zaruč 1 row (singleton config)
        $pdo->exec("INSERT IGNORE INTO sync_config (id, mode) VALUES (1, 'cloud')");

        // ─────────────────────────────────────────────────────
        // 2. SYNC LOG — historie všech sync operací (pro debug/audit)
        // ─────────────────────────────────────────────────────
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS sync_log (
                id INT AUTO_INCREMENT PRIMARY KEY,
                direction ENUM('push','pull','manual','cron') NOT NULL,
                status ENUM('success','partial','error','timeout') NOT NULL,
                records_count INT DEFAULT 0,
                duration_ms INT DEFAULT 0,
                error_message TEXT NULL,
                detail TEXT NULL,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_created (created_at),
                INDEX idx_status (status)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");

        // ─────────────────────────────────────────────────────
        // 3. SYNC QUEUE — pending operace čekající na sync
        // (záznamy které jsou upraveny lokálně a čekají na push)
        // ─────────────────────────────────────────────────────
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS sync_queue (
                id INT AUTO_INCREMENT PRIMARY KEY,
                table_name VARCHAR(64) NOT NULL,
                record_id INT NOT NULL,
                operation ENUM('insert','update','delete') NOT NULL,
                payload LONGTEXT NULL,
                attempts TINYINT NOT NULL DEFAULT 0,
                last_attempt_at DATETIME NULL,
                last_error TEXT NULL,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_pending (table_name, record_id),
                INDEX idx_attempts (attempts)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");

        // ─────────────────────────────────────────────────────
        // 4. SYNCABLE TABLES — přidání sloupců pro sync tracking
        // Tabulky které potřebují sync: vyrobky, odberatele, objednavky,
        // dodaci_listy, faktury, mista_dodani, cenove_skupiny, sazby_dph,
        // kategorie_vyrobku, suroviny
        // ─────────────────────────────────────────────────────
        $syncTables = [
            'vyrobky',
            'kategorie_vyrobku',
            'odberatele',
            'mista_dodani',
            'objednavky',
            'objednavky_polozky',
            'dodaci_listy',
            'dodaci_list_polozky',
            'faktury',
            'faktura_polozky',
            'sazby_dph',
            'jednotky',
            'cenove_skupiny',
            'suroviny',
        ];

        foreach ($syncTables as $table) {
            // Zkontroluj existenci tabulky
            $exists = $pdo->query("SHOW TABLES LIKE " . $pdo->quote($table))->fetch();
            if (!$exists) continue;

            // Zjisti existující sloupce
            $cols = $pdo->query("
                SELECT COLUMN_NAME FROM information_schema.COLUMNS
                WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = " . $pdo->quote($table)
            )->fetchAll(PDO::FETCH_COLUMN);

            // Přidej `sync_uuid` — globální identifikátor (pro idempotentní sync)
            if (!in_array('sync_uuid', $cols, true)) {
                try {
                    $pdo->exec("ALTER TABLE `$table` ADD COLUMN sync_uuid CHAR(36) NULL");
                    $pdo->exec("ALTER TABLE `$table` ADD UNIQUE INDEX idx_sync_uuid (sync_uuid)");
                } catch (Throwable $e) { error_log("sync_schema: $table sync_uuid failed: " . $e->getMessage()); }
            }

            // Přidej `synced_at` — kdy byl záznam naposled úspěšně syncnutý
            if (!in_array('synced_at', $cols, true)) {
                try {
                    $pdo->exec("ALTER TABLE `$table` ADD COLUMN synced_at DATETIME NULL");
                    $pdo->exec("ALTER TABLE `$table` ADD INDEX idx_synced (synced_at)");
                } catch (Throwable $e) { error_log("sync_schema: $table synced_at failed: " . $e->getMessage()); }
            }

            // Přidej `updated_at` — auto-update při změně (kdyby chyběl)
            if (!in_array('updated_at', $cols, true)) {
                try {
                    $pdo->exec("ALTER TABLE `$table` ADD COLUMN updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP");
                    $pdo->exec("ALTER TABLE `$table` ADD INDEX idx_updated (updated_at)");
                } catch (Throwable $e) { error_log("sync_schema: $table updated_at failed: " . $e->getMessage()); }
            }

            // Backfill UUID pro existující záznamy (jen pokud sync_uuid je NULL)
            try {
                $pdo->exec("
                    UPDATE `$table`
                    SET sync_uuid = LOWER(CONCAT(
                        HEX(RANDOM_BYTES(4)), '-',
                        HEX(RANDOM_BYTES(2)), '-',
                        HEX(RANDOM_BYTES(2)), '-',
                        HEX(RANDOM_BYTES(2)), '-',
                        HEX(RANDOM_BYTES(6))
                    ))
                    WHERE sync_uuid IS NULL
                ");
            } catch (Throwable $e) {
                // RANDOM_BYTES dostupné jen v MySQL 8+. Fallback pro starší:
                try {
                    $pdo->exec("
                        UPDATE `$table`
                        SET sync_uuid = LOWER(CONCAT(
                            LPAD(HEX(FLOOR(RAND()*4294967295)), 8, '0'), '-',
                            LPAD(HEX(FLOOR(RAND()*65535)), 4, '0'), '-',
                            LPAD(HEX(FLOOR(RAND()*65535)), 4, '0'), '-',
                            LPAD(HEX(FLOOR(RAND()*65535)), 4, '0'), '-',
                            LPAD(HEX(FLOOR(RAND()*281474976710655)), 12, '0')
                        ))
                        WHERE sync_uuid IS NULL
                    ");
                } catch (Throwable $e2) { /* ignore — UUID bude null */ }
            }
        }
    } catch (Throwable $e) {
        error_log('sync_schema migration error: ' . $e->getMessage());
    }
}

// ─────────────────────────────────────────────────────────────
// HELPER FUNCTIONS pro sync engine (Phase 2 použije)
// ─────────────────────────────────────────────────────────────

/**
 * Vrátí true pokud je sync zapnutý.
 */
function sync_is_enabled(PDO $pdo): bool {
    try {
        $r = $pdo->query("SELECT enabled FROM sync_config WHERE id = 1")->fetchColumn();
        return (int) $r === 1;
    } catch (Throwable $e) { return false; }
}

/**
 * Vrátí aktuální sync config (singleton).
 */
function sync_get_config(PDO $pdo): array {
    try {
        $r = $pdo->query("SELECT * FROM sync_config WHERE id = 1")->fetch(PDO::FETCH_ASSOC);
        return $r ?: [];
    } catch (Throwable $e) { return []; }
}

/**
 * Zapíše do sync_log.
 */
function sync_log(PDO $pdo, string $direction, string $status, int $records = 0, int $durationMs = 0, ?string $error = null, ?string $detail = null): void {
    try {
        $stmt = $pdo->prepare("
            INSERT INTO sync_log (direction, status, records_count, duration_ms, error_message, detail)
            VALUES (:d, :s, :r, :dr, :e, :dt)
        ");
        $stmt->execute([
            'd' => $direction,
            's' => $status,
            'r' => $records,
            'dr' => $durationMs,
            'e' => $error,
            'dt' => $detail,
        ]);
    } catch (Throwable $e) { error_log('sync_log failed: ' . $e->getMessage()); }
}
