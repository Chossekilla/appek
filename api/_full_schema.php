<?php
/**
 * 🛡️ FULL SCHEMA ENSURE — komplexní idempotentní schema migrace.
 *
 * Konsoliduje VŠECHNY CREATE TABLE / ALTER TABLE auto-migrace co byly historicky
 * scattered across admin_*.php files. Volá se z config.php db() při každém requestu
 * a z install.php po _schema.sql.
 *
 * Vše je idempotentní (CREATE TABLE IF NOT EXISTS + check before ADD COLUMN).
 *
 * Pokud přidáváš novou tabulku/sloupec v produkčním kódu, přidej to SEM.
 */

function apply_full_schema(PDO $pdo): void {
    static $done = false;
    if ($done) return;
    $done = true;

    // ════════════════════════════════════════════════════════════
    // 1. CHYBĚJÍCÍ TABULKY (auto-migrace z admin_*.php konsolidováno)
    // ════════════════════════════════════════════════════════════

    $tables = [
        // 🔌 API tokeny (admin_api_tokens.php)
        'api_tokens' => "
            CREATE TABLE IF NOT EXISTS api_tokens (
                id INT AUTO_INCREMENT PRIMARY KEY,
                token VARCHAR(64) NOT NULL UNIQUE,
                nazev VARCHAR(150) NOT NULL,
                opravneni VARCHAR(255) DEFAULT 'read',
                aktivni TINYINT(1) NOT NULL DEFAULT 1,
                vytvoreno DATETIME DEFAULT CURRENT_TIMESTAMP,
                posledni_pouziti DATETIME NULL,
                pocet_volani INT NOT NULL DEFAULT 0,
                INDEX idx_token (token)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ",

        // 📋 HACCP dokumenty + audity + grafy (admin_haccp_*.php)
        'haccp_dokumenty' => "
            CREATE TABLE IF NOT EXISTS haccp_dokumenty (
                id INT AUTO_INCREMENT PRIMARY KEY,
                kategorie VARCHAR(40) NOT NULL,
                nazev VARCHAR(200) NOT NULL,
                poradi INT DEFAULT 0,
                obsah LONGTEXT,
                aktivni TINYINT(1) DEFAULT 1,
                vytvoreno DATETIME DEFAULT CURRENT_TIMESTAMP,
                upraveno DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                INDEX idx_kategorie (kategorie, poradi)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ",
        'haccp_audity' => "
            CREATE TABLE IF NOT EXISTS haccp_audity (
                id INT AUTO_INCREMENT PRIMARY KEY,
                rok SMALLINT NOT NULL,
                datum DATE,
                auditor VARCHAR(200),
                vysledek VARCHAR(40) DEFAULT 'v_poradku',
                napravna_opatreni TEXT,
                poznamka TEXT,
                vytvoreno DATETIME DEFAULT CURRENT_TIMESTAMP,
                vytvoril VARCHAR(100),
                UNIQUE KEY uniq_rok (rok),
                INDEX idx_rok (rok)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ",
        'haccp_grafy' => "
            CREATE TABLE IF NOT EXISTS haccp_grafy (
                id INT AUTO_INCREMENT PRIMARY KEY,
                nazev VARCHAR(160) NOT NULL,
                popis TEXT,
                suroviny JSON,
                kroky JSON,
                poradi INT DEFAULT 0,
                aktivni TINYINT(1) DEFAULT 1,
                vytvoreno DATETIME DEFAULT CURRENT_TIMESTAMP,
                upraveno DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ",

        // 🧮 Kalkulace historie (admin_kalkulace_historie.php)
        'kalkulace_historie' => "
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
        ",

        // 🐞 Klient chyby (admin_klient_chyby.php)
        'klient_chyby' => "
            CREATE TABLE IF NOT EXISTS klient_chyby (
                id INT AUTO_INCREMENT PRIMARY KEY,
                kdy DATETIME DEFAULT CURRENT_TIMESTAMP,
                app VARCHAR(20) DEFAULT 'frontend',
                msg TEXT,
                source VARCHAR(500),
                line INT,
                col INT,
                stack TEXT,
                url VARCHAR(500),
                ua VARCHAR(300),
                user_info VARCHAR(150),
                ip VARCHAR(50),
                INDEX idx_kdy (kdy),
                INDEX idx_app (app)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ",

        // 🏷️ Moje štítky (admin_moje_stitky.php)
        'moje_stitky' => "
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
        ",

        // 📌 Onboarding state (admin_onboarding.php)
        'onboarding' => "
            CREATE TABLE IF NOT EXISTS onboarding (
                id INT PRIMARY KEY DEFAULT 1,
                step INT NOT NULL DEFAULT 0,
                completed_steps TEXT NULL,
                skipped TINYINT(1) NOT NULL DEFAULT 0,
                completed_at DATETIME NULL,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ",

        // 🔁 Recurring orders (admin_recurring.php)
        'recurring_orders' => "
            CREATE TABLE IF NOT EXISTS recurring_orders (
                id INT AUTO_INCREMENT PRIMARY KEY,
                nazev VARCHAR(150) NOT NULL,
                odberatel_id INT NOT NULL,
                misto_dodani_id INT NULL,
                frekvence ENUM('denne','tydne','dvouty','mesicne') NOT NULL DEFAULT 'tydne',
                dny_v_tydnu VARCHAR(20) DEFAULT NULL,
                cas_dodani VARCHAR(20) DEFAULT NULL,
                polozky_json MEDIUMTEXT NOT NULL,
                poznamka TEXT NULL,
                aktivni TINYINT(1) NOT NULL DEFAULT 1,
                datum_zacatku DATE NOT NULL,
                datum_konce DATE NULL,
                posledni_beh DATETIME NULL,
                pocet_vygen INT NOT NULL DEFAULT 0,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_odb (odberatel_id),
                INDEX idx_aktivni (aktivni),
                FOREIGN KEY (odberatel_id) REFERENCES odberatele(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ",

        // 📦 Sklad pohyby surovin (admin_suroviny.php)
        'sklad_pohyby' => "
            CREATE TABLE IF NOT EXISTS sklad_pohyby (
                id INT AUTO_INCREMENT PRIMARY KEY,
                surovina_id INT NOT NULL,
                typ ENUM('prijem','vydej','inventura','korekce') NOT NULL,
                mnozstvi DECIMAL(12,3) NOT NULL,
                jednotka VARCHAR(20) DEFAULT NULL,
                stock_pred DECIMAL(12,3) DEFAULT NULL,
                stock_po DECIMAL(12,3) DEFAULT NULL,
                cena_za_jed DECIMAL(10,4) DEFAULT NULL,
                poznamka VARCHAR(300) DEFAULT NULL,
                kdo VARCHAR(120) DEFAULT NULL,
                kdy DATETIME DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_pohyb_sur (surovina_id),
                INDEX idx_pohyb_typ (typ),
                FOREIGN KEY (surovina_id) REFERENCES suroviny(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ",

        // 🏷️ Štítky šablony (admin_stitky_sablony.php)
        'stitky_sablony' => "
            CREATE TABLE IF NOT EXISTS stitky_sablony (
                id INT AUTO_INCREMENT PRIMARY KEY,
                nazev VARCHAR(150) NOT NULL,
                format_id VARCHAR(40) NOT NULL,
                layout LONGTEXT NOT NULL,
                nahled_url VARCHAR(255) DEFAULT NULL,
                vytvoreno DATETIME DEFAULT CURRENT_TIMESTAMP,
                upraveno DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
            ) DEFAULT CHARSET=utf8mb4
        ",

        // 💾 Zálohy DB (admin_zalohy.php)
        'zalohy' => "
            CREATE TABLE IF NOT EXISTS zalohy (
                id INT AUTO_INCREMENT PRIMARY KEY,
                nazev_souboru VARCHAR(255) NOT NULL DEFAULT '',
                velikost_bytes BIGINT NOT NULL DEFAULT 0,
                typ ENUM('manual','auto','snapshot') NOT NULL DEFAULT 'manual',
                poznamka VARCHAR(500) DEFAULT NULL,
                vytvoreno DATETIME DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_typ_datum (typ, vytvoreno)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ",

        // 📝 Logy změn objednávek (admin_objednavky.php)
        'objednavky_zmeny' => "
            CREATE TABLE IF NOT EXISTS objednavky_zmeny (
                id INT AUTO_INCREMENT PRIMARY KEY,
                objednavka_id INT NOT NULL,
                kdo_typ VARCHAR(30) NOT NULL,
                kdo_id INT NULL,
                kdo_jmeno VARCHAR(150) NULL,
                akce VARCHAR(50) NOT NULL,
                detail TEXT NULL,
                kdy DATETIME DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_obj (objednavka_id),
                INDEX idx_kdy (kdy)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ",

        // 🏢 Pobočky (admin_pobocky.php)
        'pobocky' => "
            CREATE TABLE IF NOT EXISTS pobocky (
                id INT AUTO_INCREMENT PRIMARY KEY,
                nazev VARCHAR(200) NOT NULL,
                ulice VARCHAR(255) NULL,
                mesto VARCHAR(120) NULL,
                psc VARCHAR(15) NULL,
                telefon VARCHAR(50) NULL,
                email VARCHAR(150) NULL,
                je_hlavni TINYINT(1) NOT NULL DEFAULT 0,
                aktivni TINYINT(1) NOT NULL DEFAULT 1,
                poradi INT DEFAULT 0,
                vytvoreno DATETIME DEFAULT CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ",
    ];

    foreach ($tables as $name => $sql) {
        try { $pdo->exec($sql); }
        catch (Throwable $e) { error_log("apply_full_schema CREATE $name: " . $e->getMessage()); }
    }

    // ════════════════════════════════════════════════════════════
    // 2. CHYBĚJÍCÍ SLOUPCE V EXISTUJÍCÍCH TABULKÁCH (schema drift fixes)
    // ════════════════════════════════════════════════════════════

    $columnPatches = [
        // admin_users — kolega `posledni_login` (z _schema.sql)
        'admin_users' => [
            'posledni_login' => "ADD COLUMN posledni_login DATETIME NULL",
        ],
        // prihlaseni_pokusy (legacy: uspesne/kdy → uspesny/cas; přidat typ)
        'prihlaseni_pokusy' => [
            'typ' => "ADD COLUMN typ VARCHAR(30) DEFAULT 'admin' AFTER email",
        ],
        // vyrobky (admin_vyrobky.php + katalog.php auto-migrace)
        'vyrobky' => [
            'ean'              => "ADD COLUMN ean VARCHAR(13) DEFAULT NULL AFTER cislo",
            'popis'            => "ADD COLUMN popis TEXT DEFAULT NULL",
            'obrazek_url'      => "ADD COLUMN obrazek_url VARCHAR(500) DEFAULT NULL",
            'obsah'            => "ADD COLUMN obsah DECIMAL(10,3) DEFAULT NULL AFTER hmotnost_g",
            'obsah_jednotka'   => "ADD COLUMN obsah_jednotka VARCHAR(5) DEFAULT NULL AFTER obsah",
            'trvanlivost'      => "ADD COLUMN trvanlivost VARCHAR(50) DEFAULT NULL AFTER obsah_jednotka",
            'nutricni_hodnoty' => "ADD COLUMN nutricni_hodnoty TEXT DEFAULT NULL",
            'vyrobni_cena'     => "ADD COLUMN vyrobni_cena DECIMAL(10,4) DEFAULT NULL",
            'kalkulace_data'   => "ADD COLUMN kalkulace_data TEXT DEFAULT NULL",
            'haccp_data'       => "ADD COLUMN haccp_data TEXT DEFAULT NULL",
            'haccp_graf_id'    => "ADD COLUMN haccp_graf_id INT NULL, ADD INDEX idx_haccp_graf (haccp_graf_id)",
            'je_akce'          => "ADD COLUMN je_akce TINYINT(1) DEFAULT 0",
            'je_novinka'       => "ADD COLUMN je_novinka TINYINT(1) DEFAULT 0",
            'je_doprodej'      => "ADD COLUMN je_doprodej TINYINT(1) DEFAULT 0",
            'je_vyprodano'     => "ADD COLUMN je_vyprodano TINYINT(1) DEFAULT 0",
            'oblibeny'         => "ADD COLUMN oblibeny TINYINT(1) DEFAULT 0",
            'min_objednavka'   => "ADD COLUMN min_objednavka DECIMAL(10,2) DEFAULT NULL",
            'objednat_do_hod'  => "ADD COLUMN objednat_do_hod TINYINT DEFAULT NULL",
            'poradi'           => "ADD COLUMN poradi INT DEFAULT 0",
            'sklad_stav'       => "ADD COLUMN sklad_stav DECIMAL(12,3) DEFAULT NULL",
            'sklad_min'        => "ADD COLUMN sklad_min DECIMAL(12,3) DEFAULT NULL",
            // 🆕 v3.0.323 — fresh-install díry: priprava_min/kitchen_station_id (admin_kitchen.php
            //   GET je čte bez guardu → 500) + obor (konfigurátor dort/catering → jinak „žádné dorty"
            //   dokud se neotevře Výrobky). Dřív jen v admin_vyrobky.php migraci → sem = univerzálně
            //   na každém připojení (idempotentní check-before-ADD).
            'priprava_min'       => "ADD COLUMN priprava_min INT NOT NULL DEFAULT 10",
            'kitchen_station_id' => "ADD COLUMN kitchen_station_id INT NULL",
            'obor'               => "ADD COLUMN obor VARCHAR(20) NULL, ADD INDEX idx_obor (obor)",
        ],
        // suroviny (admin_suroviny.php auto-migrace)
        'suroviny' => [
            'ean'              => "ADD COLUMN ean VARCHAR(13) DEFAULT NULL", // 🆕 v3.0.326 skener
            'cena_baleni'      => "ADD COLUMN cena_baleni DECIMAL(10,2) DEFAULT NULL",
            'obsah_baleni'     => "ADD COLUMN obsah_baleni DECIMAL(10,3) DEFAULT NULL",
            'slozeni'          => "ADD COLUMN slozeni TEXT DEFAULT NULL",
            'slozeni_alergeny' => "ADD COLUMN slozeni_alergeny VARCHAR(255) DEFAULT NULL",
            'sklad_stav'       => "ADD COLUMN sklad_stav DECIMAL(12,3) DEFAULT 0",
            'sklad_min'        => "ADD COLUMN sklad_min DECIMAL(12,3) DEFAULT NULL",
            'sklad_cil'        => "ADD COLUMN sklad_cil DECIMAL(12,3) DEFAULT NULL",
        ],
        // kategorie_vyrobku — obrazek_url pro vizuál v katalogu
        'kategorie_vyrobku' => [
            'obrazek_url' => "ADD COLUMN obrazek_url VARCHAR(500) DEFAULT NULL",
            'barva'       => "ADD COLUMN barva VARCHAR(20) DEFAULT NULL",
        ],
        // odberatele (admin_odberatele.php auto-migrace)
        'odberatele' => [
            'typ'              => "ADD COLUMN typ VARCHAR(50) DEFAULT NULL",
            'notif_emaily'     => "ADD COLUMN notif_emaily TINYINT(1) NOT NULL DEFAULT 1",
            'kontaktni_osoba'  => "ADD COLUMN kontaktni_osoba VARCHAR(150) DEFAULT NULL",
            'blokovan'         => "ADD COLUMN blokovan TINYINT(1) NOT NULL DEFAULT 0",
        ],
        // cenove_skupiny (admin_cenove_skupiny.php auto-migrace)
        'cenove_skupiny' => [
            'globalni_sleva_pct' => "ADD COLUMN globalni_sleva_pct DECIMAL(5,2) DEFAULT NULL",
            'minimum_obj_kc'     => "ADD COLUMN minimum_obj_kc DECIMAL(10,2) DEFAULT NULL",
            'splatnost_dni'      => "ADD COLUMN splatnost_dni INT DEFAULT NULL",
        ],
        // objednavky — vratky/refund. 🆕 v3.0.323: dřív jen `ADD COLUMN IF NOT EXISTS refund_of`
        //   (MariaDB-only syntax) uvnitř akcí → na MySQL selhal → POS vratka „Unknown column
        //   'refund_of'" (potvrzeno v error logu). Tady check-before-ADD = funguje na MySQL i MariaDB.
        'objednavky' => [
            'refund_of' => "ADD COLUMN refund_of INT NULL",
        ],
        // objednavky_polozky (admin_objednavky.php — vyrobek_id NULL + nazev snapshot; vraci_polozku_id = vratka)
        'objednavky_polozky' => [
            'vyrobek_nazev'    => "ADD COLUMN vyrobek_nazev VARCHAR(255) NULL AFTER vyrobek_id",
            'jednotka'         => "ADD COLUMN jednotka VARCHAR(20) NULL AFTER vyrobek_nazev",
            'vraci_polozku_id' => "ADD COLUMN vraci_polozku_id INT NULL",
        ],
        // moje_stitky (admin_moje_stitky.php — možná legacy bez sloupců)
        'moje_stitky' => [
            'obsah'          => "ADD COLUMN obsah DECIMAL(10,3) DEFAULT NULL AFTER hmotnost_g",
            'obsah_jednotka' => "ADD COLUMN obsah_jednotka VARCHAR(5) DEFAULT NULL AFTER obsah",
        ],
        // email_templates (config.php ensure_email_templates_table)
        'email_templates' => [
            'format' => "ADD COLUMN format VARCHAR(10) NOT NULL DEFAULT 'text' AFTER telo",
        ],
        // dodaci_listy (admin_dashboard.php query)
        'dodaci_listy' => [
            'rucni' => "ADD COLUMN rucni TINYINT(1) NOT NULL DEFAULT 0",
            'obsah_upraveno' => "ADD COLUMN obsah_upraveno DATETIME NULL",
        ],
        // faktury (admin_faktury.php query)
        'faktury' => [
            'odeslano_email' => "ADD COLUMN odeslano_email DATETIME NULL",
            'obsah_upraveno' => "ADD COLUMN obsah_upraveno DATETIME NULL",
        ],
        // nastaveni — zajisti sloupce
        'nastaveni' => [
            'updated_at' => "ADD COLUMN updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP",
        ],
        // cislovani — legacy `posledni_cislo` → `posledni`
        'cislovani' => [
            'posledni' => "ADD COLUMN posledni INT NOT NULL DEFAULT 0",
        ],
        // sazby_dph — chybějící sloupce
        'sazby_dph' => [
            'platne_od'  => "ADD COLUMN platne_od DATE NULL",
            'platne_do'  => "ADD COLUMN platne_do DATE NULL",
            'aktivni'    => "ADD COLUMN aktivni TINYINT(1) NOT NULL DEFAULT 1",
        ],
        // mista_dodani — pořadí pro sort
        'mista_dodani' => [
            'poradi'     => "ADD COLUMN poradi INT NOT NULL DEFAULT 0",
            'kontakt'    => "ADD COLUMN kontakt VARCHAR(150) DEFAULT NULL",
            'telefon'    => "ADD COLUMN telefon VARCHAR(50) DEFAULT NULL",
            'email'      => "ADD COLUMN email VARCHAR(150) DEFAULT NULL",
            'poznamka'   => "ADD COLUMN poznamka TEXT DEFAULT NULL",
            'aktivni'    => "ADD COLUMN aktivni TINYINT(1) NOT NULL DEFAULT 1",
        ],
        // zalohy — schema má nazev_souboru, code chce soubor (alias)
        'zalohy' => [
            'soubor'     => "ADD COLUMN soubor VARCHAR(255) NULL",
            'velikost'   => "ADD COLUMN velikost BIGINT NOT NULL DEFAULT 0",
            'label'      => "ADD COLUMN label VARCHAR(200) DEFAULT NULL",
            'tabulek'    => "ADD COLUMN tabulek INT DEFAULT NULL",
            'zaznamu'    => "ADD COLUMN zaznamu INT DEFAULT NULL",
        ],
    ];

    // Speciální rename pro cislovani.posledni_cislo → posledni
    try {
        $cislCols = $pdo->query("SHOW COLUMNS FROM cislovani")->fetchAll(PDO::FETCH_COLUMN);
        if (in_array('posledni_cislo', $cislCols, true) && !in_array('posledni', $cislCols, true)) {
            $pdo->exec("ALTER TABLE cislovani CHANGE COLUMN posledni_cislo posledni INT NOT NULL DEFAULT 0");
        } elseif (in_array('posledni_cislo', $cislCols, true) && in_array('posledni', $cislCols, true)) {
            // Copy data ze starého do nového sloupce a dropuj
            $pdo->exec("UPDATE cislovani SET posledni = posledni_cislo WHERE posledni = 0 AND posledni_cislo > 0");
            $pdo->exec("ALTER TABLE cislovani DROP COLUMN posledni_cislo");
        }
    } catch (Throwable $e) { /* table not yet created */ }

    foreach ($columnPatches as $table => $cols) {
        try {
            $existing = $pdo->query("SHOW COLUMNS FROM `$table`")->fetchAll(PDO::FETCH_COLUMN);
            foreach ($cols as $colName => $ddl) {
                if (!in_array($colName, $existing, true)) {
                    try { $pdo->exec("ALTER TABLE `$table` $ddl"); }
                    catch (Throwable $e) { error_log("apply_full_schema $table.$colName: " . $e->getMessage()); }
                }
            }
        } catch (Throwable $e) { /* tabulka může chybět — vytvoří se výše */ }
    }

    // ════════════════════════════════════════════════════════════
    // 3. INDEXES (idempotentní — fail = duplicate, ignorovat)
    // ════════════════════════════════════════════════════════════

    $indexes = [
        "ALTER TABLE suroviny ADD INDEX idx_suroviny_aktivni (aktivni)",
        "ALTER TABLE suroviny ADD INDEX idx_suroviny_alergen (alergen(50))",
        "ALTER TABLE vyrobek_suroviny ADD INDEX idx_vs_surovina (surovina_id)",
    ];
    foreach ($indexes as $sql) {
        try { $pdo->exec($sql); }
        catch (Throwable $e) { /* duplicate key name = OK, ignoruj */ }
    }
}
