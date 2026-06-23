-- ════════════════════════════════════════════════════════════
-- 🏢 VENDOR PANEL — Schema pro správu licenčních klíčů
-- ════════════════════════════════════════════════════════════
-- Tyto tabulky existují JEN na hostingu dodavatele (appek.cz),
-- NEVKLÁDAJÍ SE do customer instalací (vendor/ je excluded
-- z build-zip.sh).

CREATE TABLE IF NOT EXISTS vendor_users (
    id INT AUTO_INCREMENT PRIMARY KEY,
    username VARCHAR(64) UNIQUE NOT NULL,
    password_hash VARCHAR(255) NOT NULL,
    display_name VARCHAR(150) NULL,
    role ENUM('admin','readonly') DEFAULT 'admin',
    totp_secret VARCHAR(32) NULL,
    totp_enabled TINYINT(1) NOT NULL DEFAULT 0,
    last_login DATETIME NULL,
    last_ip VARCHAR(45) NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS vendor_licenses (
    id INT AUTO_INCREMENT PRIMARY KEY,
    license_key VARCHAR(32) UNIQUE NOT NULL,
    customer_name VARCHAR(255) NOT NULL,
    customer_company VARCHAR(255) NULL,
    customer_email VARCHAR(255) NULL,
    customer_phone VARCHAR(50) NULL,
    install_url VARCHAR(500) NULL,
    note TEXT NULL,
    issued_at DATE NOT NULL DEFAULT (CURRENT_DATE),
    expires_at DATE NULL,
    status ENUM('active','expired','revoked') NOT NULL DEFAULT 'active',
    revoked_at DATETIME NULL,
    revoke_reason TEXT NULL,
    issued_by_id INT NULL,
    price_kc DECIMAL(10,2) NULL,
    paid TINYINT(1) DEFAULT 1,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_status (status),
    INDEX idx_expires (expires_at),
    INDEX idx_customer (customer_name),
    INDEX idx_email (customer_email)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS vendor_audit_log (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NULL,
    username VARCHAR(64) NULL,
    action VARCHAR(64) NOT NULL,
    target_license_id INT NULL,
    target_key VARCHAR(32) NULL,
    details TEXT NULL,
    ip VARCHAR(45) NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_created (created_at),
    INDEX idx_action (action)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 📦 PACKAGES — definice balíčků zobrazovaných v sales / nabízených v eshopu
CREATE TABLE IF NOT EXISTS vendor_packages (
    id INT AUTO_INCREMENT PRIMARY KEY,
    `key` VARCHAR(32) UNIQUE NOT NULL,        -- 'cukrarna', 'lahudky', 'restaurace'…
    name_cs VARCHAR(120) NOT NULL,
    name_en VARCHAR(120) NULL,
    name_es VARCHAR(120) NULL,
    description_cs TEXT NULL,
    description_en TEXT NULL,
    description_es TEXT NULL,
    icon VARCHAR(16) NULL,                    -- emoji nebo název ikony
    price_kc DECIMAL(10,2) NOT NULL DEFAULT 0,
    price_eur DECIMAL(10,2) NULL,
    price_usd DECIMAL(10,2) NULL,
    bit_pos TINYINT UNSIGNED NULL,            -- 0..19 (mapuje na LICENSE_PACKAGE_BITS)
    is_core TINYINT(1) NOT NULL DEFAULT 0,    -- 1 = zahrnut vždy (core/starter)
    is_active TINYINT(1) NOT NULL DEFAULT 1,  -- 0 = skrýt v eshopu
    sort_order INT NOT NULL DEFAULT 0,
    features_json TEXT NULL,                  -- JSON: ['HACCP','Cenovky',…]
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_active (is_active),
    INDEX idx_sort (sort_order)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 🛒 SHOP ORDERS — objednávky z appek.cz/checkout (před vygenerováním licence)
CREATE TABLE IF NOT EXISTS vendor_shop_orders (
    id INT AUTO_INCREMENT PRIMARY KEY,
    order_no VARCHAR(32) UNIQUE NOT NULL,     -- APPEK-ORD-20260517-A8F2
    customer_name VARCHAR(255) NOT NULL,
    customer_company VARCHAR(255) NULL,
    customer_email VARCHAR(255) NOT NULL,
    customer_phone VARCHAR(50) NULL,
    customer_country VARCHAR(2) DEFAULT 'CZ',
    customer_ico VARCHAR(20) NULL,
    customer_dic VARCHAR(20) NULL,
    customer_address TEXT NULL,
    tier VARCHAR(32) NULL,                    -- 'starter','profi','premium'
    packages_json TEXT NULL,                  -- JSON: ['cukrarna','lahudky']
    install_url VARCHAR(500) NULL,
    notes TEXT NULL,
    total_kc DECIMAL(10,2) NOT NULL DEFAULT 0,
    currency VARCHAR(3) DEFAULT 'CZK',
    payment_method ENUM('bank','card','crypto','manual') DEFAULT 'bank',
    payment_status ENUM('pending','paid','failed','refunded','cancelled') DEFAULT 'pending',
    payment_id VARCHAR(255) NULL,             -- ID transakce z platební brány
    license_id INT NULL,                      -- po payment_status=paid se vytvoří
    license_key VARCHAR(40) NULL,             -- pro rychlý lookup
    locale VARCHAR(2) DEFAULT 'cs',
    ip VARCHAR(45) NULL,
    user_agent TEXT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    paid_at DATETIME NULL,
    INDEX idx_status (payment_status),
    INDEX idx_email (customer_email),
    INDEX idx_created (created_at),
    INDEX idx_license (license_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 🔄 UPDATES — verze ke stažení do zákaznických instalací
CREATE TABLE IF NOT EXISTS vendor_updates (
    id INT AUTO_INCREMENT PRIMARY KEY,
    version VARCHAR(32) UNIQUE NOT NULL,                -- 2.1.0, 2.1.1, 3.0.0-beta
    channel ENUM('stable','beta','alpha') DEFAULT 'stable',
    file_path VARCHAR(500) NOT NULL,                    -- relativní cesta v vendor/updates_storage/
    file_size BIGINT NOT NULL DEFAULT 0,
    checksum_sha256 VARCHAR(64) NOT NULL,
    manifest_json TEXT NULL,                            -- {files: {path: sha256}, packages_required: [...]}
    signature TEXT NULL,                                -- 🔐 base64 RSA-2048/SHA-256 podpis manifest.json (supply-chain)
    changelog_md TEXT NULL,
    min_version VARCHAR(32) NULL,                       -- vyžaduje minimálně tuto verzi
    packages_required TEXT NULL,                        -- JSON: ['core'] nebo ['cukrarna']
    status ENUM('draft','published','deprecated') NOT NULL DEFAULT 'draft',
    download_count INT NOT NULL DEFAULT 0,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    published_at DATETIME NULL,
    deprecated_at DATETIME NULL,
    INDEX idx_status (status),
    INDEX idx_version (version),
    INDEX idx_channel (channel)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 📥 UPDATE INSTALLS — kdo si co stáhl (per license)
CREATE TABLE IF NOT EXISTS vendor_update_installs (
    id INT AUTO_INCREMENT PRIMARY KEY,
    update_id INT NOT NULL,
    license_id INT NULL,
    license_key VARCHAR(40) NOT NULL,
    customer_url VARCHAR(500) NULL,                    -- z kterého URL si stáhl
    ip VARCHAR(45) NULL,
    user_agent TEXT NULL,
    success TINYINT(1) DEFAULT 1,                       -- 0 = error reportován zpět
    error_msg TEXT NULL,
    downloaded_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_license_key (license_key),
    INDEX idx_update (update_id),
    INDEX idx_downloaded (downloaded_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 🏴‍☠️ PIRATE INSTALLS — instalace bez platné licence (detected via heartbeat)
-- Detekce: customer admin posílá denně heartbeat na vendor s URL+key.
-- Pokud klíč chybí ve vendor_licenses, nebo URL nesedí (key reuse) → pirate.
CREATE TABLE IF NOT EXISTS vendor_pirate_installs (
    id INT AUTO_INCREMENT PRIMARY KEY,
    install_url VARCHAR(500) NOT NULL,
    install_host VARCHAR(255) NOT NULL,                 -- normalizovaný hostname
    license_key_attempted VARCHAR(40) NULL,             -- co klíč poslal (může být fake)
    reason ENUM('no_key','invalid_format','unknown_key','key_reuse','revoked_used','expired_used') NOT NULL,
    matched_license_id INT NULL,                        -- pokud key_reuse, kterému klíči patří
    first_seen DATETIME DEFAULT CURRENT_TIMESTAMP,
    last_seen DATETIME DEFAULT CURRENT_TIMESTAMP,
    heartbeat_count INT NOT NULL DEFAULT 1,
    app_version VARCHAR(32) NULL,
    php_version VARCHAR(32) NULL,
    admin_emails TEXT NULL,                              -- JSON: emails of customer admins (z heartbeat)
    customer_b2b_count INT NULL,                        -- # B2B uživatelů (z heartbeat)
    ip_first VARCHAR(45) NULL,
    ip_last VARCHAR(45) NULL,
    status ENUM('new','contacted','warned','licensed','closed','ignored') NOT NULL DEFAULT 'new',
    contact_note TEXT NULL,                              -- co jsme s nimi řešili
    contacted_at DATETIME NULL,
    UNIQUE KEY uniq_host_key (install_host, license_key_attempted),
    INDEX idx_status (status),
    INDEX idx_last_seen (last_seen),
    INDEX idx_reason (reason)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 📡 LICENSE HEARTBEATS — všechny heartbeaty z legitimních instalací (rolling 30 days)
CREATE TABLE IF NOT EXISTS vendor_license_heartbeats (
    id INT AUTO_INCREMENT PRIMARY KEY,
    license_id INT NOT NULL,
    license_key VARCHAR(40) NOT NULL,
    install_url VARCHAR(500) NOT NULL,
    install_host VARCHAR(255) NOT NULL,
    app_version VARCHAR(32) NULL,
    php_version VARCHAR(32) NULL,
    customer_b2b_count INT NULL,
    customer_orders_count INT NULL,
    days_since_install INT NULL,
    ip VARCHAR(45) NULL,
    seen_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_license (license_id),
    INDEX idx_seen (seen_at),
    INDEX idx_host (install_host)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- (vendor_licenses sloupce last_seen_*, heartbeat_count se přidávají
--  idempotentně z vendor_ensure_pirate_columns() v _lib.php — kompatibilní
--  s MySQL < 8.0.29 / MariaDB < 10.0.2 kde IF NOT EXISTS u ALTER nefunguje.)

-- Seed základních balíčků (idempotentně — INSERT IGNORE)
INSERT IGNORE INTO vendor_packages
  (`key`,           name_cs,            name_en,            name_es,            icon, bit_pos, is_core, price_kc, sort_order, description_cs)
VALUES
  ('core',          'Core (Starter)',   'Core (Starter)',   'Core (Starter)',   '⭐', NULL,    1,       12990,    1, 'Základ — objednávky, faktury, výroba, HACCP, cenovky, B2B portál'),
  ('cukrarna',      'Cukrárna',         'Patisserie',       'Pastelería',       '🎂', 0,       0,       2990,     2, 'Speciality pro cukrárny: torty, dorty, konfigurátor zakázek'),
  ('lahudky',       'Lahůdky',          'Deli',             'Charcutería',      '🥪', 1,       0,       2490,     3, 'Lahůdky / studená kuchyně: bedny, gramáže, krabičkování'),
  ('restaurace',    'Restaurace',       'Restaurant',       'Restaurante',      '🍽️', 2,       0,       3490,     4, 'Restaurace: menu engineering, rezervace, kuchyně & sklad'),
  ('catering',      'Catering',         'Catering',         'Catering',         '🎉', 3,       0,       2990,     5, 'Eventy a banketing — kalkulace, sety, logistika rozvozů'),
  ('sezona',        'Sezónní akce',     'Seasonal events',  'Eventos de temporada','🍂', 4,    0,       1990,     6, 'Pop-up sezónní moduly: trhy, vánoce, velikonoce, festivaly');
