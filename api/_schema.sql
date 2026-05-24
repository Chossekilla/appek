-- 🗄️ Master schema pro Appek B2B systém — krabicovka v1.0
-- Spouští se automaticky při instalaci (install.php step 2).
-- Bezpečné spustit i opakovaně (CREATE TABLE IF NOT EXISTS).

SET NAMES utf8mb4;
SET time_zone = '+01:00';

-- ============================================================
-- 🔐 ADMIN UŽIVATELÉ (super admin / prodavač / výroba / expedice)
-- ============================================================
CREATE TABLE IF NOT EXISTS admin_users (
    id INT AUTO_INCREMENT PRIMARY KEY,
    email VARCHAR(150) UNIQUE NOT NULL,
    jmeno VARCHAR(150),
    heslo_hash VARCHAR(255) NOT NULL,
    role VARCHAR(30) DEFAULT 'admin',
    aktivni TINYINT(1) NOT NULL DEFAULT 1,
    -- 🆕 v2.9.270 — POS PIN login (4-6 cifer, bcrypt hash)
    pin_hash VARCHAR(255) NULL,
    pos_only TINYINT(1) NOT NULL DEFAULT 0,
    posledni_pos_login DATETIME NULL,
    vytvoreno DATETIME DEFAULT CURRENT_TIMESTAMP,
    posledni_login DATETIME NULL,
    INDEX idx_pos_only (pos_only),
    INDEX idx_aktivni_role (aktivni, role)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- ⚙️ NASTAVENÍ (key-value)
-- ============================================================
CREATE TABLE IF NOT EXISTS nastaveni (
    klic VARCHAR(80) PRIMARY KEY,
    hodnota TEXT,
    popis VARCHAR(255),
    upraveno DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- 📏 JEDNOTKY
-- ============================================================
CREATE TABLE IF NOT EXISTS jednotky (
    id INT AUTO_INCREMENT PRIMARY KEY,
    kod VARCHAR(20) UNIQUE NOT NULL,
    nazev VARCHAR(80) NOT NULL,
    poradi INT DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Seed základních jednotek (idempotentní)
INSERT IGNORE INTO jednotky (kod, nazev, poradi) VALUES
    ('ks', 'kus', 1),
    ('kg', 'kilogram', 2),
    ('g',  'gram', 3),
    ('l',  'litr', 4),
    ('ml', 'mililitr', 5),
    ('bal','balení', 6);

-- ============================================================
-- 💸 SAZBY DPH
-- ============================================================
CREATE TABLE IF NOT EXISTS sazby_dph (
    id INT AUTO_INCREMENT PRIMARY KEY,
    sazba DECIMAL(5,2) NOT NULL,
    nazev VARCHAR(80),
    poradi INT DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Seed CZ legislativy: 12 % (potraviny), 21 % (ostatní), 0 % (osvobozené)
INSERT IGNORE INTO sazby_dph (sazba, nazev, poradi) VALUES
    (12.00, 'Snížená 12 % (potraviny)', 1),
    (21.00, 'Základní 21 %', 2),
    (0.00, 'Osvobozeno', 3);

-- ============================================================
-- 🏷️ KATEGORIE VÝROBKŮ
-- ============================================================
CREATE TABLE IF NOT EXISTS kategorie_vyrobku (
    id INT AUTO_INCREMENT PRIMARY KEY,
    nazev VARCHAR(150) NOT NULL,
    ikona VARCHAR(30) DEFAULT '🥖',
    barva VARCHAR(20) DEFAULT NULL,
    poradi INT DEFAULT 0,
    aktivni TINYINT(1) NOT NULL DEFAULT 1
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- 📦 VÝROBKY
-- ============================================================
CREATE TABLE IF NOT EXISTS vyrobky (
    id INT AUTO_INCREMENT PRIMARY KEY,
    cislo VARCHAR(50),
    nazev VARCHAR(255) NOT NULL,
    popis TEXT,
    slozeni TEXT,
    alergeny VARCHAR(255),
    obrazek_url VARCHAR(255),
    kategorie_id INT NULL,
    jednotka_id INT NOT NULL,
    sazba_dph_id INT NOT NULL,
    cena_bez_dph DECIMAL(10,2) NOT NULL DEFAULT 0,
    hmotnost_g INT NULL,
    obsah DECIMAL(10,3) NULL,
    obsah_jednotka VARCHAR(20) NULL,
    ean VARCHAR(20) NULL,
    min_objednavka INT NOT NULL DEFAULT 1,
    aktivni TINYINT(1) NOT NULL DEFAULT 1,
    oblibeny TINYINT(1) NOT NULL DEFAULT 0,
    je_novinka TINYINT(1) NOT NULL DEFAULT 0,
    je_akce TINYINT(1) NOT NULL DEFAULT 0,
    je_doprodej TINYINT(1) NOT NULL DEFAULT 0,
    je_vyprodano TINYINT(1) NOT NULL DEFAULT 0,
    poradi INT DEFAULT 0,
    nutricni_hodnoty TEXT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_kategorie (kategorie_id),
    INDEX idx_aktivni (aktivni),
    FOREIGN KEY (kategorie_id) REFERENCES kategorie_vyrobku(id) ON DELETE SET NULL,
    FOREIGN KEY (jednotka_id) REFERENCES jednotky(id),
    FOREIGN KEY (sazba_dph_id) REFERENCES sazby_dph(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- 💰 CENOVÉ SKUPINY (ceníky) + SLEVY
-- ============================================================
CREATE TABLE IF NOT EXISTS cenove_skupiny (
    id INT AUTO_INCREMENT PRIMARY KEY,
    nazev VARCHAR(150) NOT NULL,
    popis VARCHAR(255),
    globalni_sleva_pct DECIMAL(5,2) DEFAULT NULL,
    minimum_obj_kc DECIMAL(10,2) DEFAULT NULL,
    splatnost_dni INT DEFAULT NULL,
    aktivni TINYINT(1) NOT NULL DEFAULT 1,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS cenove_skupiny_slevy (
    id INT AUTO_INCREMENT PRIMARY KEY,
    skupina_id INT NOT NULL,
    kategorie_id INT NULL,
    vyrobek_id INT NULL,
    sleva_pct DECIMAL(5,2) NULL,
    pevna_cena DECIMAL(10,2) NULL,
    poznamka VARCHAR(255),
    FOREIGN KEY (skupina_id) REFERENCES cenove_skupiny(id) ON DELETE CASCADE,
    FOREIGN KEY (kategorie_id) REFERENCES kategorie_vyrobku(id) ON DELETE CASCADE,
    FOREIGN KEY (vyrobek_id) REFERENCES vyrobky(id) ON DELETE CASCADE,
    INDEX idx_skupina (skupina_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- 👥 ODBĚRATELÉ
-- ============================================================
CREATE TABLE IF NOT EXISTS odberatele (
    id INT AUTO_INCREMENT PRIMARY KEY,
    cislo VARCHAR(50),
    nazev VARCHAR(255) NOT NULL,
    ico VARCHAR(20),
    dic VARCHAR(20),
    ulice VARCHAR(255),
    mesto VARCHAR(120),
    psc VARCHAR(15),
    email VARCHAR(150),
    telefon VARCHAR(50),
    web VARCHAR(150),
    poznamka TEXT,
    typ VARCHAR(50) DEFAULT NULL,
    splatnost_dni INT DEFAULT 14,
    sleva_pct DECIMAL(5,2) DEFAULT 0,
    cenova_skupina_id INT NULL,
    login_email VARCHAR(150) UNIQUE,
    heslo_hash VARCHAR(255),
    notif_emaily VARCHAR(500),
    notif_souhlas TINYINT(1) NOT NULL DEFAULT 1,
    blokovan TINYINT(1) NOT NULL DEFAULT 0,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_login (login_email),
    INDEX idx_cenova_skupina (cenova_skupina_id),
    INDEX idx_typ (typ),
    FOREIGN KEY (cenova_skupina_id) REFERENCES cenove_skupiny(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- 📍 MÍSTA DODÁNÍ (pobočky odběratele)
-- ============================================================
CREATE TABLE IF NOT EXISTS mista_dodani (
    id INT AUTO_INCREMENT PRIMARY KEY,
    odberatel_id INT NOT NULL,
    nazev VARCHAR(255) NOT NULL,
    ulice VARCHAR(255),
    mesto VARCHAR(120),
    psc VARCHAR(15),
    kontaktni_osoba VARCHAR(150),
    telefon VARCHAR(50),
    email VARCHAR(150),
    cas_dodani VARCHAR(50),
    pokyny_pro_ridice TEXT,
    vychozi TINYINT(1) NOT NULL DEFAULT 0,
    aktivni TINYINT(1) NOT NULL DEFAULT 1,
    FOREIGN KEY (odberatel_id) REFERENCES odberatele(id) ON DELETE CASCADE,
    INDEX idx_odb (odberatel_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- 🔢 ČÍSELNÉ ŘADY (cislovani)
-- ============================================================
CREATE TABLE IF NOT EXISTS cislovani (
    id INT AUTO_INCREMENT PRIMARY KEY,
    typ VARCHAR(20) NOT NULL,
    rok INT NOT NULL,
    posledni INT NOT NULL DEFAULT 0,
    predcisli VARCHAR(20),
    UNIQUE KEY ux_typ_rok (typ, rok)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- 📋 OBJEDNÁVKY
-- ============================================================
CREATE TABLE IF NOT EXISTS objednavky (
    id INT AUTO_INCREMENT PRIMARY KEY,
    cislo VARCHAR(50) UNIQUE NOT NULL,
    typ VARCHAR(30) DEFAULT 'standard',
    odberatel_id INT NOT NULL,
    misto_dodani_id INT NULL,
    datum_objednani DATE NOT NULL,
    datum_dodani DATE NOT NULL,
    stav VARCHAR(30) NOT NULL DEFAULT 'nova',
    castka_bez_dph DECIMAL(10,2) NOT NULL DEFAULT 0,
    castka_dph DECIMAL(10,2) NOT NULL DEFAULT 0,
    castka_celkem DECIMAL(10,2) NOT NULL DEFAULT 0,
    poznamka TEXT,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_odb (odberatel_id),
    INDEX idx_dodani (datum_dodani),
    INDEX idx_stav (stav),
    FOREIGN KEY (odberatel_id) REFERENCES odberatele(id),
    FOREIGN KEY (misto_dodani_id) REFERENCES mista_dodani(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS objednavky_polozky (
    id INT AUTO_INCREMENT PRIMARY KEY,
    objednavka_id INT NOT NULL,
    vyrobek_id INT NULL,
    vyrobek_nazev VARCHAR(255),
    mnozstvi DECIMAL(10,3) NOT NULL,
    jednotka VARCHAR(20),
    cena_bez_dph DECIMAL(10,2) NOT NULL,
    sazba_dph DECIMAL(5,2) NOT NULL,
    poznamka VARCHAR(255),
    FOREIGN KEY (objednavka_id) REFERENCES objednavky(id) ON DELETE CASCADE,
    FOREIGN KEY (vyrobek_id) REFERENCES vyrobky(id) ON DELETE SET NULL,
    INDEX idx_obj (objednavka_id),
    INDEX idx_vyrobek (vyrobek_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- 📄 DODACÍ LISTY
-- ============================================================
CREATE TABLE IF NOT EXISTS dodaci_listy (
    id INT AUTO_INCREMENT PRIMARY KEY,
    cislo VARCHAR(50) UNIQUE NOT NULL,
    objednavka_id INT NULL,
    odberatel_id INT NOT NULL,
    misto_dodani_id INT NULL,
    datum_vystaveni DATE NOT NULL,
    datum_dodani DATE NOT NULL,
    castka_celkem DECIMAL(10,2) NOT NULL DEFAULT 0,
    fakturovano TINYINT(1) NOT NULL DEFAULT 0,
    rucni TINYINT(1) NOT NULL DEFAULT 0,
    poznamka TEXT,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_odb (odberatel_id),
    INDEX idx_obj (objednavka_id),
    INDEX idx_datum (datum_dodani),
    FOREIGN KEY (odberatel_id) REFERENCES odberatele(id),
    FOREIGN KEY (objednavka_id) REFERENCES objednavky(id) ON DELETE SET NULL,
    FOREIGN KEY (misto_dodani_id) REFERENCES mista_dodani(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS dodaci_list_polozky (
    id INT AUTO_INCREMENT PRIMARY KEY,
    dodaci_list_id INT NOT NULL,
    vyrobek_id INT NULL,
    vyrobek_nazev VARCHAR(255),
    mnozstvi DECIMAL(10,3) NOT NULL,
    jednotka VARCHAR(20),
    cena_bez_dph DECIMAL(10,2) NOT NULL,
    sazba_dph DECIMAL(5,2) NOT NULL,
    FOREIGN KEY (dodaci_list_id) REFERENCES dodaci_listy(id) ON DELETE CASCADE,
    FOREIGN KEY (vyrobek_id) REFERENCES vyrobky(id) ON DELETE SET NULL,
    INDEX idx_dl (dodaci_list_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- 💰 FAKTURY
-- ============================================================
CREATE TABLE IF NOT EXISTS faktury (
    id INT AUTO_INCREMENT PRIMARY KEY,
    cislo VARCHAR(50) UNIQUE NOT NULL,
    odberatel_id INT NOT NULL,
    datum_vystaveni DATE NOT NULL,
    datum_splatnosti DATE NOT NULL,
    datum_dph DATE NULL,
    castka_bez_dph DECIMAL(10,2) NOT NULL DEFAULT 0,
    castka_dph DECIMAL(10,2) NOT NULL DEFAULT 0,
    castka_celkem DECIMAL(10,2) NOT NULL DEFAULT 0,
    castka_uhrazeno DECIMAL(10,2) NOT NULL DEFAULT 0,
    datum_uhrazeni DATE NULL,
    variabilni_symbol VARCHAR(20),
    rucni TINYINT(1) NOT NULL DEFAULT 0,
    odeslano_email DATETIME NULL,
    poznamka TEXT,
    misto_dodani_id INT NULL,
    odb_nazev_snapshot VARCHAR(255) NULL,
    odb_ico_snapshot VARCHAR(20) NULL,
    odb_dic_snapshot VARCHAR(20) NULL,
    odb_ulice_snapshot VARCHAR(255) NULL,
    odb_mesto_snapshot VARCHAR(120) NULL,
    odb_psc_snapshot VARCHAR(20) NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_odb (odberatel_id),
    INDEX idx_datum (datum_vystaveni),
    FOREIGN KEY (odberatel_id) REFERENCES odberatele(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS faktura_polozky (
    id INT AUTO_INCREMENT PRIMARY KEY,
    faktura_id INT NOT NULL,
    vyrobek_id INT NULL,
    vyrobek_nazev VARCHAR(255),
    mnozstvi DECIMAL(10,3) NOT NULL,
    jednotka VARCHAR(20),
    cena_bez_dph DECIMAL(10,2) NOT NULL,
    sazba_dph DECIMAL(5,2) NOT NULL,
    poradi INT DEFAULT 0,
    FOREIGN KEY (faktura_id) REFERENCES faktury(id) ON DELETE CASCADE,
    FOREIGN KEY (vyrobek_id) REFERENCES vyrobky(id) ON DELETE SET NULL,
    INDEX idx_fa (faktura_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS faktury_dodaci_listy (
    faktura_id INT NOT NULL,
    dodaci_list_id INT NOT NULL,
    PRIMARY KEY (faktura_id, dodaci_list_id),
    FOREIGN KEY (faktura_id) REFERENCES faktury(id) ON DELETE CASCADE,
    FOREIGN KEY (dodaci_list_id) REFERENCES dodaci_listy(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- 🥖 VÝROBNÍ LISTY
-- ============================================================
CREATE TABLE IF NOT EXISTS vyrobni_listy (
    id INT AUTO_INCREMENT PRIMARY KEY,
    cislo VARCHAR(50),
    datum_vyroby DATE NOT NULL,
    datum_dodani DATE NOT NULL,
    stav VARCHAR(30) DEFAULT 'koncept',
    poznamka TEXT,
    created_by VARCHAR(150),
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_datum (datum_dodani)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS vyrobni_list_polozky (
    id INT AUTO_INCREMENT PRIMARY KEY,
    vyrobni_list_id INT NOT NULL,
    vyrobek_id INT NOT NULL,
    mnozstvi DECIMAL(10,3) NOT NULL,
    vyrobeno DECIMAL(10,3) DEFAULT 0,
    poznamka VARCHAR(255),
    FOREIGN KEY (vyrobni_list_id) REFERENCES vyrobni_listy(id) ON DELETE CASCADE,
    FOREIGN KEY (vyrobek_id) REFERENCES vyrobky(id),
    INDEX idx_vl (vyrobni_list_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- 🔐 RATE LIMITING (přihlašovací pokusy)
-- ============================================================
CREATE TABLE IF NOT EXISTS prihlaseni_pokusy (
    id INT AUTO_INCREMENT PRIMARY KEY,
    ip VARCHAR(45) NOT NULL,
    email VARCHAR(150),
    typ VARCHAR(30) DEFAULT 'admin',
    uspesny TINYINT(1) NOT NULL DEFAULT 0,
    cas DATETIME DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_ip_cas (ip, cas),
    INDEX idx_typ_cas (typ, cas)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- 🌾 SUROVINY (pro recepty výrobků + sklad)
-- ============================================================
CREATE TABLE IF NOT EXISTS suroviny (
    id INT AUTO_INCREMENT PRIMARY KEY,
    nazev VARCHAR(150) NOT NULL,
    jednotka VARCHAR(20) NOT NULL DEFAULT 'g',
    alergen VARCHAR(150) DEFAULT NULL,
    cena_baleni DECIMAL(10,2) DEFAULT NULL,
    obsah_baleni DECIMAL(10,3) DEFAULT NULL,
    slozeni TEXT DEFAULT NULL,
    slozeni_alergeny VARCHAR(255) DEFAULT NULL,
    nutri_energie_kj DECIMAL(8,1) DEFAULT NULL,
    nutri_energie_kcal DECIMAL(8,1) DEFAULT NULL,
    nutri_tuky DECIMAL(7,2) DEFAULT NULL,
    nutri_tuky_nasycene DECIMAL(7,2) DEFAULT NULL,
    nutri_sacharidy DECIMAL(7,2) DEFAULT NULL,
    nutri_cukry DECIMAL(7,2) DEFAULT NULL,
    nutri_bilkoviny DECIMAL(7,2) DEFAULT NULL,
    nutri_sul DECIMAL(7,3) DEFAULT NULL,
    stock_aktualni DECIMAL(12,3) NOT NULL DEFAULT 0,
    stock_minimalni DECIMAL(12,3) DEFAULT NULL,
    stock_cilove DECIMAL(12,3) DEFAULT NULL,
    poznamka TEXT DEFAULT NULL,
    aktivni TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY ux_suroviny_nazev (nazev),
    INDEX idx_suroviny_aktivni (aktivni),
    INDEX idx_suroviny_alergen (alergen(50))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS vyrobek_suroviny (
    id INT AUTO_INCREMENT PRIMARY KEY,
    vyrobek_id INT NOT NULL,
    surovina_id INT NOT NULL,
    mnozstvi DECIMAL(10,3) NOT NULL DEFAULT 0,
    jednotka VARCHAR(20) DEFAULT 'g',
    poradi INT DEFAULT 0,
    poznamka VARCHAR(200) DEFAULT NULL,
    FOREIGN KEY (vyrobek_id) REFERENCES vyrobky(id) ON DELETE CASCADE,
    FOREIGN KEY (surovina_id) REFERENCES suroviny(id) ON DELETE CASCADE,
    UNIQUE KEY ux_vyr_sur (vyrobek_id, surovina_id),
    INDEX idx_vs_surovina (surovina_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- ✅ HOTOVO — ostatní tabulky se vytvoří auto-migrací při prvním použití:
-- onboarding, recurring_orders, sklad_pohyby, api_tokens, email_templates,
-- haccp_*, kalkulace_historie, klient_chyby, moje_stitky, push_*,
-- stitky_sablony, zalohy, objednavky_zmeny
-- ============================================================

-- Seed: výchozí cenové skupiny (pro filter odběratelů)
-- (volitelné — uživatel může vytvořit vlastní)
-- INSERT IGNORE INTO cenove_skupiny (nazev, popis) VALUES
--     ('Hotely',       'Hoteliéři a ubytovací zařízení'),
--     ('Restaurace',   'Provozovny veřejného stravování'),
--     ('Kavárny',      'Kavárny a cukrárny'),
--     ('Nemocnice',    'Nemocnice a sociální zařízení'),
--     ('Školy',        'Školní jídelny'),
--     ('Maloobchod',   'Prodejny a obchody');
