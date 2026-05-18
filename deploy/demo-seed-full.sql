-- ════════════════════════════════════════════════════════════════
-- 🧪 APPEK DEMO — KOMPLETNÍ SQL (DROP + CREATE + SEED)
-- ════════════════════════════════════════════════════════════════
-- Použití (phpMyAdmin):
--   1. Vyber demo databázi
--   2. Import → tento soubor
--   3. Go
--
-- DEMO PŘIHLÁŠENÍ (po importu):
--   Admin:  demo@appek.cz     / demo1234
--           vyroba@appek.cz   / vyroba1234
--           expedice@appek.cz / expedice1234
--   B2B:    odberatel@demo.cz  / demo1234
--           restaurace@demo.cz / demo1234
--           hotel@demo.cz      / demo1234
-- ════════════════════════════════════════════════════════════════

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;
SET sql_mode = '';

-- ════════════════════════════════════════════════════════════════
-- 1. DROP — smaž vše ve správném pořadí
-- ════════════════════════════════════════════════════════════════
DROP TABLE IF EXISTS faktury_dodaci_listy;
DROP TABLE IF EXISTS faktura_polozky;
DROP TABLE IF EXISTS faktury;
DROP TABLE IF EXISTS dodaci_list_polozky;
DROP TABLE IF EXISTS dodaci_listy;
DROP TABLE IF EXISTS objednavky_polozky;
DROP TABLE IF EXISTS objednavky;
DROP TABLE IF EXISTS vyrobni_list_polozky;
DROP TABLE IF EXISTS vyrobni_listy;
DROP TABLE IF EXISTS vyrobek_suroviny;
DROP TABLE IF EXISTS suroviny;
DROP TABLE IF EXISTS mista_dodani;
DROP TABLE IF EXISTS vyrobky;
DROP TABLE IF EXISTS odberatele;
DROP TABLE IF EXISTS cenove_skupiny_slevy;
DROP TABLE IF EXISTS cenove_skupiny;
DROP TABLE IF EXISTS kategorie_vyrobku;
DROP TABLE IF EXISTS sazby_dph;
DROP TABLE IF EXISTS jednotky;
DROP TABLE IF EXISTS cislovani;
DROP TABLE IF EXISTS prihlaseni_pokusy;
DROP TABLE IF EXISTS admin_users;
DROP TABLE IF EXISTS nastaveni;
DROP TABLE IF EXISTS demo_pristupy;

-- ════════════════════════════════════════════════════════════════
-- 2. CREATE — vytvoř všechny tabulky (kopie z api/_schema.sql)
-- ════════════════════════════════════════════════════════════════

CREATE TABLE admin_users (
    id INT AUTO_INCREMENT PRIMARY KEY,
    email VARCHAR(150) UNIQUE NOT NULL,
    jmeno VARCHAR(150),
    heslo_hash VARCHAR(255) NOT NULL,
    role VARCHAR(30) DEFAULT 'admin',
    aktivni TINYINT(1) NOT NULL DEFAULT 1,
    vytvoreno DATETIME DEFAULT CURRENT_TIMESTAMP,
    posledni_login DATETIME NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE nastaveni (
    klic VARCHAR(80) PRIMARY KEY,
    hodnota TEXT,
    popis VARCHAR(255),
    upraveno DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE jednotky (
    id INT AUTO_INCREMENT PRIMARY KEY,
    kod VARCHAR(20) UNIQUE NOT NULL,
    nazev VARCHAR(80) NOT NULL,
    poradi INT DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO jednotky (id, kod, nazev, poradi) VALUES
    (1, 'ks', 'kus',       1),
    (2, 'kg', 'kilogram',  2),
    (3, 'g',  'gram',      3),
    (4, 'l',  'litr',      4),
    (5, 'ml', 'mililitr',  5),
    (6, 'bal','balení',    6);

CREATE TABLE sazby_dph (
    id INT AUTO_INCREMENT PRIMARY KEY,
    sazba DECIMAL(5,2) NOT NULL,
    nazev VARCHAR(80),
    poradi INT DEFAULT 0,
    platne_od DATE NULL,
    platne_do DATE NULL,
    aktivni TINYINT(1) NOT NULL DEFAULT 1
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO sazby_dph (id, sazba, nazev, poradi) VALUES
    (1, 12.00, 'Snížená 12 % (potraviny)', 1),
    (2, 21.00, 'Základní 21 %',            2),
    (3,  0.00, 'Osvobozeno',                3);

CREATE TABLE kategorie_vyrobku (
    id INT AUTO_INCREMENT PRIMARY KEY,
    nazev VARCHAR(150) NOT NULL,
    ikona VARCHAR(30) DEFAULT '🥖',
    barva VARCHAR(20) DEFAULT NULL,
    obrazek_url VARCHAR(500) DEFAULT NULL,
    poradi INT DEFAULT 0,
    aktivni TINYINT(1) NOT NULL DEFAULT 1
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE vyrobky (
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
    FOREIGN KEY (jednotka_id)  REFERENCES jednotky(id),
    FOREIGN KEY (sazba_dph_id) REFERENCES sazby_dph(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE cenove_skupiny (
    id INT AUTO_INCREMENT PRIMARY KEY,
    nazev VARCHAR(150) NOT NULL,
    popis VARCHAR(255),
    globalni_sleva_pct DECIMAL(5,2) DEFAULT NULL,
    minimum_obj_kc DECIMAL(10,2) DEFAULT NULL,
    splatnost_dni INT DEFAULT NULL,
    aktivni TINYINT(1) NOT NULL DEFAULT 1,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE cenove_skupiny_slevy (
    id INT AUTO_INCREMENT PRIMARY KEY,
    skupina_id INT NOT NULL,
    kategorie_id INT NULL,
    vyrobek_id INT NULL,
    sleva_pct DECIMAL(5,2) NULL,
    pevna_cena DECIMAL(10,2) NULL,
    poznamka VARCHAR(255),
    FOREIGN KEY (skupina_id)   REFERENCES cenove_skupiny(id)    ON DELETE CASCADE,
    FOREIGN KEY (kategorie_id) REFERENCES kategorie_vyrobku(id) ON DELETE CASCADE,
    FOREIGN KEY (vyrobek_id)   REFERENCES vyrobky(id)            ON DELETE CASCADE,
    INDEX idx_skupina (skupina_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE odberatele (
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
    kontaktni_osoba VARCHAR(150) DEFAULT NULL,
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
    INDEX idx_typ (typ),
    INDEX idx_cenova_skupina (cenova_skupina_id),
    FOREIGN KEY (cenova_skupina_id) REFERENCES cenove_skupiny(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE mista_dodani (
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
    poradi INT NOT NULL DEFAULT 0,
    kontakt VARCHAR(150) DEFAULT NULL,
    FOREIGN KEY (odberatel_id) REFERENCES odberatele(id) ON DELETE CASCADE,
    INDEX idx_odb (odberatel_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE cislovani (
    id INT AUTO_INCREMENT PRIMARY KEY,
    typ VARCHAR(20) NOT NULL,
    rok INT NOT NULL,
    posledni INT NOT NULL DEFAULT 0,
    predcisli VARCHAR(20),
    UNIQUE KEY ux_typ_rok (typ, rok)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE objednavky (
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
    FOREIGN KEY (odberatel_id)     REFERENCES odberatele(id),
    FOREIGN KEY (misto_dodani_id) REFERENCES mista_dodani(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE objednavky_polozky (
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
    FOREIGN KEY (vyrobek_id)    REFERENCES vyrobky(id)     ON DELETE SET NULL,
    INDEX idx_obj (objednavka_id),
    INDEX idx_vyrobek (vyrobek_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE dodaci_listy (
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
    FOREIGN KEY (odberatel_id)     REFERENCES odberatele(id),
    FOREIGN KEY (objednavka_id)   REFERENCES objednavky(id)    ON DELETE SET NULL,
    FOREIGN KEY (misto_dodani_id) REFERENCES mista_dodani(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE dodaci_list_polozky (
    id INT AUTO_INCREMENT PRIMARY KEY,
    dodaci_list_id INT NOT NULL,
    vyrobek_id INT NULL,
    vyrobek_nazev VARCHAR(255),
    mnozstvi DECIMAL(10,3) NOT NULL,
    jednotka VARCHAR(20),
    cena_bez_dph DECIMAL(10,2) NOT NULL,
    sazba_dph DECIMAL(5,2) NOT NULL,
    FOREIGN KEY (dodaci_list_id) REFERENCES dodaci_listy(id) ON DELETE CASCADE,
    FOREIGN KEY (vyrobek_id)     REFERENCES vyrobky(id)     ON DELETE SET NULL,
    INDEX idx_dl (dodaci_list_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE faktury (
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
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_odb (odberatel_id),
    INDEX idx_datum (datum_vystaveni),
    FOREIGN KEY (odberatel_id) REFERENCES odberatele(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE faktura_polozky (
    id INT AUTO_INCREMENT PRIMARY KEY,
    faktura_id INT NOT NULL,
    vyrobek_id INT NULL,
    vyrobek_cislo VARCHAR(50),
    vyrobek_nazev VARCHAR(255),
    mnozstvi DECIMAL(10,3) NOT NULL,
    jednotka VARCHAR(20),
    cena_bez_dph DECIMAL(10,2) NOT NULL,
    sazba_dph DECIMAL(5,2) NOT NULL,
    poznamka VARCHAR(255),
    poradi INT DEFAULT 0,
    FOREIGN KEY (faktura_id) REFERENCES faktury(id) ON DELETE CASCADE,
    FOREIGN KEY (vyrobek_id) REFERENCES vyrobky(id) ON DELETE SET NULL,
    INDEX idx_fa (faktura_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ─── Spojka faktura ⇄ dodací list (M:N) — používá ji admin_dodaci_listy.php ──
CREATE TABLE faktury_dodaci_listy (
    faktura_id INT NOT NULL,
    dodaci_list_id INT NOT NULL,
    PRIMARY KEY (faktura_id, dodaci_list_id),
    FOREIGN KEY (faktura_id)     REFERENCES faktury(id)     ON DELETE CASCADE,
    FOREIGN KEY (dodaci_list_id) REFERENCES dodaci_listy(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ─── Výrobní listy + položky (pro admin_vyroba.php) ────────────────────────
CREATE TABLE vyrobni_listy (
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

CREATE TABLE vyrobni_list_polozky (
    id INT AUTO_INCREMENT PRIMARY KEY,
    vyrobni_list_id INT NOT NULL,
    vyrobek_id INT NOT NULL,
    mnozstvi DECIMAL(10,3) NOT NULL,
    vyrobeno DECIMAL(10,3) DEFAULT 0,
    poznamka VARCHAR(255),
    FOREIGN KEY (vyrobni_list_id) REFERENCES vyrobni_listy(id) ON DELETE CASCADE,
    FOREIGN KEY (vyrobek_id)      REFERENCES vyrobky(id),
    INDEX idx_vl (vyrobni_list_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ─── Suroviny (pro recepty + sklad) ─────────────────────────────────────────
CREATE TABLE suroviny (
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
    sklad_stav DECIMAL(12,3) DEFAULT 0,
    sklad_min DECIMAL(12,3) DEFAULT NULL,
    sklad_cil DECIMAL(12,3) DEFAULT NULL,
    poznamka TEXT DEFAULT NULL,
    aktivni TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY ux_suroviny_nazev (nazev),
    INDEX idx_suroviny_aktivni (aktivni)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE vyrobek_suroviny (
    id INT AUTO_INCREMENT PRIMARY KEY,
    vyrobek_id INT NOT NULL,
    surovina_id INT NOT NULL,
    mnozstvi DECIMAL(10,3) NOT NULL DEFAULT 0,
    jednotka VARCHAR(20) DEFAULT 'g',
    poradi INT DEFAULT 0,
    poznamka VARCHAR(200) DEFAULT NULL,
    FOREIGN KEY (vyrobek_id)  REFERENCES vyrobky(id)  ON DELETE CASCADE,
    FOREIGN KEY (surovina_id) REFERENCES suroviny(id) ON DELETE CASCADE,
    UNIQUE KEY ux_vyr_sur (vyrobek_id, surovina_id),
    INDEX idx_vs_surovina (surovina_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE prihlaseni_pokusy (
    id INT AUTO_INCREMENT PRIMARY KEY,
    ip VARCHAR(45) NOT NULL,
    email VARCHAR(150),
    typ VARCHAR(30) DEFAULT 'admin',
    uspesny TINYINT(1) NOT NULL DEFAULT 0,
    cas DATETIME DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_ip_cas (ip, cas),
    INDEX idx_typ_cas (typ, cas)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE demo_pristupy (
    id INT AUTO_INCREMENT PRIMARY KEY,
    ip VARCHAR(45),
    user_agent TEXT,
    akce VARCHAR(50),
    referer VARCHAR(255),
    cas DATETIME DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_cas (cas)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ════════════════════════════════════════════════════════════════
-- 3. SEED — vlož demo data
-- ════════════════════════════════════════════════════════════════

-- ─── Admin účty (3×) — bcrypt hashe ověřeny ──────────────────────
INSERT INTO admin_users (id, email, heslo_hash, jmeno, role, aktivni, vytvoreno) VALUES
(1, 'demo@appek.cz',     '$2y$10$3.q8dHGvYZpwC0pYC/lKLOTjxFs/L.8uQxVsSEr4Aq8Heojh.tqL2', 'Demo Admin',    'admin',    1, NOW()),
(2, 'vyroba@appek.cz',   '$2y$10$/9T9fCq/ykWR41JTlppUsOOYEmnYOufGvKxONOOMt9kUJkbjr3kWS', 'Demo Výroba',   'vyroba',   1, NOW()),
(3, 'expedice@appek.cz', '$2y$10$CfLLMnqbijzFlexIMfhVlOSIQ6PZG.kY.ll75oTJ8TtXRA2KIRlGi', 'Demo Expedice', 'expedice', 1, NOW());

-- ─── Nastavení (info o firmě pro faktury, doklady) ────────────────
INSERT INTO nastaveni (klic, hodnota, popis) VALUES
('firma_nazev',     'APPEK Demo s.r.o.',                'Název firmy na dokladech'),
('firma_ico',       '12345678',                          'IČO'),
('firma_dic',       'CZ12345678',                        'DIČ'),
('firma_ulice',     'Demonstrační 42',                   'Ulice'),
('firma_mesto',     'Praha 1',                           'Město'),
('firma_psc',       '11000',                              'PSČ'),
('firma_email',     'info@appek.cz',                     'Email firmy'),
('firma_telefon',   '+420 777 123 456',                  'Telefon'),
('firma_web',       'https://appek.cz',                  'Web'),
('firma_banka',     '1234567890/0100',                   'Bankovní účet'),
('firma_iban',      'CZ1201000000001234567890',         'IBAN'),
('firma_swift',     'KOMBCZPP',                           'SWIFT/BIC'),
-- 🎁 Zapnuté balíčky (customer toggle) — všech 5 ON pro demo, aby gating endpointy nepadaly
('packages_enabled', '{"cukrarna":true,"lahudky":true,"restaurace":true,"catering":true,"sezona":true}', 'Aktivní balíčky vedle licence');

-- ─── Kategorie výrobků (5) ───────────────────────────────────────
INSERT INTO kategorie_vyrobku (id, nazev, ikona, barva, poradi, aktivni) VALUES
(1, 'Pečivo',     '🥖', '#BA7517', 1, 1),
(2, 'Cukrářské',  '🎂', '#E91E63', 2, 1),
(3, 'Lahůdky',    '🥪', '#4CAF50', 3, 1),
(4, 'Nápoje',     '☕', '#795548', 4, 1),
(5, 'Suroviny',   '🌾', '#9E9E9E', 5, 1);

-- ─── Cenové skupiny (3) ──────────────────────────────────────────
INSERT INTO cenove_skupiny (id, nazev, popis, globalni_sleva_pct, splatnost_dni) VALUES
(1, 'Hotely',     'Hoteliéři a ubytovací zařízení',   5.00, 14),
(2, 'Restaurace', 'Provozovny veřejného stravování',  3.00, 14),
(3, 'VIP',        'Top zákazníci s prioritou',       10.00,  7);

-- ─── Výrobky (50) ────────────────────────────────────────────────
INSERT INTO vyrobky (id, cislo, nazev, popis, kategorie_id, jednotka_id, sazba_dph_id, cena_bez_dph, hmotnost_g, alergeny, aktivni, oblibeny, je_novinka, je_akce) VALUES
-- Pečivo
(1,  'CHL001', 'Chléb pšenično-žitný 1kg', 'Tradiční receptura, šárová výroba',     1, 1, 1, 38.00, 1000, 'lepek',                            1, 1, 0, 0),
(2,  'BAG001', 'Bageta francouzská 250g',  'Křupavá kůrka, vzdušná střídka',        1, 1, 1, 18.00,  250, 'lepek',                            1, 1, 0, 0),
(3,  'HOU001', 'Houska s mákem',           '60g, kynuté těsto',                      1, 1, 1,  8.50,   60, 'lepek,mák',                        1, 1, 0, 0),
(4,  'HOU002', 'Houska se sezamem',        '60g, kynuté těsto',                      1, 1, 1,  8.50,   60, 'lepek,sezam',                      1, 0, 0, 0),
(5,  'ROH001', 'Rohlík tukový',            '40g klasik',                             1, 1, 1,  3.20,   40, 'lepek',                            1, 1, 0, 0),
(6,  'CIA001', 'Ciabatta 200g',            'Italský styl, olivový olej',             1, 1, 1, 22.00,  200, 'lepek',                            1, 0, 1, 0),
(7,  'VAN001', 'Vánočka máslová 500g',     'Mandle, rozinky · jen prosinec',         1, 1, 1, 85.00,  500, 'lepek,vejce,mléko',                1, 0, 0, 0),
(8,  'MAZ001', 'Mazanec velikonoční 450g', 'Jen březen/duben',                       1, 1, 1, 78.00,  450, 'lepek,vejce,mléko',                1, 0, 0, 0),
(9,  'FOC001', 'Focaccia s rozmarýnem',    '250g · italský styl',                    1, 1, 1, 68.00,  250, 'lepek',                            1, 0, 1, 0),
(10, 'SOU001', 'Sourdough chléb 800g',     'Žitný kvas, 24h kynutí',                 1, 1, 1, 72.00,  800, 'lepek',                            1, 1, 0, 0),
(11, 'BLG001', 'Bezlepkový chleba 500g',   'Certifikovaně bezlepkový',               1, 1, 1, 138.00, 500, '',                                  1, 0, 0, 0),
(12, 'VEL001', 'Velikonoční beránek 350g', 'Jaro',                                    1, 1, 1, 88.00,  350, 'lepek,vejce,mléko',                1, 0, 0, 0),
-- Cukrářské
(13, 'VET001', 'Větrník',                  'Klasika · šlehačka + karamel',           2, 1, 1, 32.00,  100, 'lepek,vejce,mléko',                1, 1, 0, 0),
(14, 'VRO001', 'Vanilkový rohlíček',       'Domácí máslové těsto',                   2, 1, 1, 25.00,   30, 'lepek,vejce,mléko,oresky',         1, 1, 0, 0),
(15, 'TIR001', 'Tiramisu porce',           'Mascarpone, Lavazza',                    2, 1, 1, 48.00,  120, 'lepek,vejce,mléko',                1, 1, 0, 0),
(16, 'CHE001', 'Cheesecake malinový',      'Tvarohový, lesní maliny',                2, 1, 1, 55.00,  130, 'lepek,vejce,mléko',                1, 0, 0, 0),
(17, 'CRO001', 'Croissant s čokoládou',    'Belgická čokoláda',                      2, 1, 1, 28.00,   80, 'lepek,mléko,soja',                 1, 1, 0, 0),
(18, 'DON001', 'Donut glazovaný',          'Domácí kynuté',                          2, 1, 1, 22.00,   70, 'lepek,vejce,mléko',                1, 0, 0, 0),
(19, 'DRT001', 'Dort čokoládový 1kg',      'Belgická čokoláda 70%',                  2, 1, 1, 380.00,1000, 'lepek,vejce,mléko,soja',           1, 0, 0, 0),
(20, 'DRT002', 'Dort jahodový 1kg',        'Sezónní · jaro/léto',                    2, 1, 1, 360.00,1000, 'lepek,vejce,mléko',                1, 0, 1, 0),
(21, 'PER001', 'Perníkové srdíčko',        '50g · valentýn/prosinec',                2, 1, 1, 18.00,   50, 'lepek,vejce',                       1, 0, 0, 0),
(22, 'LIN001', 'Linecké cukroví 250g',     'Máslové · prosinec',                     2, 1, 1, 78.00,  250, 'lepek,vejce,mléko',                1, 0, 0, 0),
(23, 'PER002', 'Vánoční perníčky 500g',    'Tradiční · prosinec',                    2, 1, 1, 148.00, 500, 'lepek,vejce',                       1, 0, 0, 0),
(24, 'VEG001', 'Vegan brownie',            '100g · bez vajec a mléka',               2, 1, 1, 48.00,  100, 'lepek,soja',                        1, 0, 1, 0),
(25, 'MAC001', 'Macarons mix (6ks)',       'Vanilka, čokoláda, malina',              2, 6, 1, 198.00, 150, 'vejce,oresky,mléko',                1, 0, 0, 0),
-- Lahůdky
(26, 'SEN001', 'Sendvič šunkový',          'Šunka, máslo',                           3, 1, 2, 42.00,  180, 'lepek,mléko,horcice',               1, 1, 0, 0),
(27, 'SEN002', 'Sendvič vegetariánský',    'Hummus, rajče, salát, okurka',           3, 1, 2, 45.00,  180, 'lepek,sezam',                       1, 0, 0, 0),
(28, 'WRP001', 'Wrap kuřecí',              'Grilované kuřecí, salát',                3, 1, 2, 68.00,  220, 'lepek,mléko,horcice',               1, 1, 0, 0),
(29, 'WRP002', 'Wrap losos',               'Uzený losos, smetanový sýr',             3, 1, 2, 85.00,  220, 'lepek,ryby,mléko',                  1, 0, 1, 0),
(30, 'BSL001', 'Bramborový salát 500g',    'Klasická receptura',                     3, 2, 2, 62.00,  500, 'vejce,horcice',                     1, 1, 0, 0),
(31, 'MSL001', 'Mexický salát 500g',       'Fazole, kukuřice, paprika',              3, 2, 2, 78.00,  500, '',                                   1, 0, 1, 0),
(32, 'ROL001', 'Šunkový rolled',           'Šunka, sýr, okurka',                     3, 1, 2, 45.00,  120, 'lepek,mléko',                       1, 0, 0, 0),
(33, 'QUI001', 'Quiche Lorraine 300g',     'Slanina, sýr, smetana',                  3, 1, 2, 125.00, 300, 'lepek,vejce,mléko',                 1, 0, 0, 0),
-- Nápoje
(34, 'KAV001', 'Káva Lungo 0.5l',          'Lavazza',                                 4, 1, 1, 45.00,  500, '',                                   1, 1, 0, 0),
(35, 'KAV002', 'Latte 0.4l',               'Espresso + plnotučné mléko',             4, 1, 1, 55.00,  400, 'mléko',                              1, 1, 0, 0),
(36, 'KAV003', 'Cappuccino 0.3l',          'Klasický recept',                        4, 1, 1, 48.00,  300, 'mléko',                              1, 0, 0, 0),
(37, 'LIM001', 'Limonáda bezová 0.5l',     'Domácí sirup',                           4, 1, 1, 35.00,  500, '',                                   1, 0, 1, 0),
(38, 'LIM002', 'Limonáda mátová 0.5l',     'Domácí sirup',                           4, 1, 1, 35.00,  500, '',                                   1, 0, 0, 0),
(39, 'DZU001', 'Čerstvý džus pomerančový', '0.3l · 100% ovoce',                      4, 1, 1, 42.00,  300, '',                                   1, 0, 0, 0),
(40, 'CAJ001', 'Čaj černý Earl Grey',      '0.3l · Twinings',                        4, 1, 1, 28.00,  300, '',                                   1, 0, 0, 0),
-- Suroviny
(41, 'MOU001', 'Mouka pšeničná hladká',    '00 · prémium',                           5, 2, 1, 18.00, 1000, 'lepek',                              1, 0, 0, 0),
(42, 'MOU002', 'Mouka chlebová',           'BIO',                                     5, 2, 1, 17.50, 1000, 'lepek',                              1, 0, 0, 0),
(43, 'MAS001', 'Máslo 250g',               '82% tuk · pravé',                        5, 1, 1, 62.00,  250, 'mléko',                              1, 1, 0, 0),
(44, 'MLE001', 'Mléko plnotučné 1l',       '3.5% čerstvé',                           5, 1, 1, 26.00, 1000, 'mléko',                              1, 1, 0, 0),
(45, 'VEJ001', 'Vejce velikost L (30ks)',  'Stáj A',                                 5, 6, 1, 168.00,1800, 'vejce',                              1, 0, 0, 0),
(46, 'CUK001', 'Cukr krystal 1kg',         '',                                        5, 2, 1, 22.00, 1000, '',                                   1, 0, 0, 0),
(47, 'CUK002', 'Cukr moučka 1kg',          'Jemný',                                   5, 2, 1, 28.00, 1000, '',                                   1, 0, 0, 0),
(48, 'KAK001', 'Kakao prémium 500g',       '',                                        5, 1, 1, 98.00,  500, '',                                   1, 0, 0, 0),
(49, 'VAN002', 'Vanilkový lusk',           'Madagaskar',                              5, 1, 1, 45.00,    5, '',                                   1, 0, 1, 0),
(50, 'SKO001', 'Skořice mletá 100g',       'Cejlonská',                               5, 1, 1, 35.00,  100, '',                                   1, 0, 0, 0);

-- ─── Odběratelé (12), první 3 s B2B login právy ───────────────────
INSERT INTO odberatele (id, cislo, nazev, ico, dic, ulice, mesto, psc, email, telefon, typ, kontaktni_osoba, splatnost_dni, cenova_skupina_id, login_email, heslo_hash, notif_emaily) VALUES
(1,  'ODB-001', 'Penzion U Lípy',                '12345001', 'CZ12345001', 'Lipová 12',     'Brno',     '60200', 'penzion@uliny.cz',         '+420 777 100 001', 'penzion',    'Marie Lípová',  14, 1,    'odberatel@demo.cz',  '$2y$10$3.q8dHGvYZpwC0pYC/lKLOTjxFs/L.8uQxVsSEr4Aq8Heojh.tqL2', 'penzion@uliny.cz'),
(2,  'ODB-002', 'Restaurace Pod Lípou',          '12345002', 'CZ12345002', 'Hlavní 24',     'Praha 5',  '15000', 'objednavky@podlipou.cz',   '+420 777 100 002', 'restaurace', 'Petr Novák',    14, 2,    'restaurace@demo.cz', '$2y$10$3.q8dHGvYZpwC0pYC/lKLOTjxFs/L.8uQxVsSEr4Aq8Heojh.tqL2', 'objednavky@podlipou.cz'),
(3,  'ODB-003', 'Hotel Continental ****',        '12345003', 'CZ12345003', 'Václavské 7',   'Praha 1',  '11000', 'fb@continental.cz',         '+420 777 100 003', 'hotel',      'Jana Veselá',   14, 1,    'hotel@demo.cz',       '$2y$10$3.q8dHGvYZpwC0pYC/lKLOTjxFs/L.8uQxVsSEr4Aq8Heojh.tqL2', 'fb@continental.cz'),
(4,  'ODB-004', 'Kavárna Sladký Sen',            '12345004', 'CZ12345004', 'Masarykova 5',  'Brno',     '60200', 'sklad@sladkysen.cz',        '+420 777 100 004', 'kavarna',    'Eva Krásná',    14, NULL, NULL,                  NULL, 'sklad@sladkysen.cz'),
(5,  'ODB-005', 'Školní jídelna ZŠ Komenského',  '12345005', 'CZ12345005', 'Komenského 8',  'Plzeň',    '30100', 'jidelna@zskom.cz',          '+420 777 100 005', 'jidelna',    'Hana Kovářová', 30, NULL, NULL,                  NULL, 'jidelna@zskom.cz'),
(6,  'ODB-006', 'Catering Premium s.r.o.',       '12345006', 'CZ12345006', 'Průmyslová 3',  'Ostrava',  '70200', 'objednavky@catpre.cz',      '+420 777 100 006', 'catering',   'Tomáš Svoboda', 14, 3,    NULL,                  NULL, 'objednavky@catpre.cz'),
(7,  'ODB-007', 'Pivnice Na Kopečku',            '12345007', 'CZ12345007', 'Kopcová 18',    'Liberec',  '46001', 'pavel@nakopecku.cz',        '+420 777 100 007', 'restaurace', 'Pavel Hodný',    7, 2,    NULL,                  NULL, 'pavel@nakopecku.cz'),
(8,  'ODB-008', 'Bistro Vegan Garden',           '12345008', 'CZ12345008', 'Bezručova 11',  'Praha 6',  '16000', 'order@vegangarden.cz',      '+420 777 100 008', 'bistro',     'Lucie Mladá',   14, 2,    NULL,                  NULL, 'order@vegangarden.cz'),
(9,  'ODB-009', 'Pension Hluboká nad Vltavou',   '12345009', 'CZ12345009', 'Zámecká 2',     'Hluboká',  '37341', 'pension@hluboka.cz',        '+420 777 100 009', 'penzion',    'Karel Vondra',  14, 1,    NULL,                  NULL, 'pension@hluboka.cz'),
(10, 'ODB-010', 'Restaurace Stará pošta',        '12345010', 'CZ12345010', 'Hlavní 100',    'Olomouc',  '77900', 'restaurant@staraposta.cz','+420 777 100 010', 'restaurace', 'Anna Veselá',   14, 2,    NULL,                  NULL, 'restaurant@staraposta.cz'),
(11, 'ODB-011', 'Cukrárna U Tří Kytek',          '12345011', 'CZ12345011', 'Květinová 5',   'Hradec K.','50000', 'cukrarna@trikytky.cz',      '+420 777 100 011', 'cukrarna',   'Helena Květová',14, NULL, NULL,                  NULL, 'cukrarna@trikytky.cz'),
(12, 'ODB-012', 'Senior dům Slunečnice',         '12345012', 'CZ12345012', 'Slunná 14',     'Pardubice','53002', 'strava@slunecnice.cz',      '+420 777 100 012', 'jidelna',    'Iva Sluneční',  30, NULL, NULL,                  NULL, 'strava@slunecnice.cz');

-- ─── Objednávky (30, různé stavy) ────────────────────────────────
INSERT INTO objednavky (id, cislo, odberatel_id, datum_objednani, datum_dodani, stav, castka_bez_dph, castka_dph, castka_celkem, poznamka) VALUES
(1,  'OBJ-2026-001', 1,  DATE_SUB(CURDATE(), INTERVAL 7 DAY),  DATE_SUB(CURDATE(), INTERVAL 6 DAY), 'doruceno', 1850.00, 222.00, 2072.00, 'Pravidelný odběr pondělí'),
(2,  'OBJ-2026-002', 2,  DATE_SUB(CURDATE(), INTERVAL 6 DAY),  DATE_SUB(CURDATE(), INTERVAL 5 DAY), 'doruceno', 2980.00, 360.00, 3340.00, 'Termín do 8:00'),
(3,  'OBJ-2026-003', 3,  DATE_SUB(CURDATE(), INTERVAL 5 DAY),  DATE_SUB(CURDATE(), INTERVAL 4 DAY), 'doruceno', 5760.00, 691.00, 6451.00, 'Snídaně pro hotel'),
(4,  'OBJ-2026-004', 4,  DATE_SUB(CURDATE(), INTERVAL 5 DAY),  DATE_SUB(CURDATE(), INTERVAL 4 DAY), 'doruceno',  920.00, 110.00, 1030.00, ''),
(5,  'OBJ-2026-005', 5,  DATE_SUB(CURDATE(), INTERVAL 4 DAY),  DATE_SUB(CURDATE(), INTERVAL 3 DAY), 'doruceno', 1240.00, 149.00, 1389.00, 'Školní obědy'),
(6,  'OBJ-2026-006', 6,  DATE_SUB(CURDATE(), INTERVAL 4 DAY),  DATE_SUB(CURDATE(), INTERVAL 2 DAY), 'doruceno', 8500.00,1020.00, 9520.00, 'Catering svatba'),
(7,  'OBJ-2026-007', 7,  DATE_SUB(CURDATE(), INTERVAL 3 DAY),  DATE_SUB(CURDATE(), INTERVAL 2 DAY), 'doruceno',  580.00,  70.00,  650.00, ''),
(8,  'OBJ-2026-008', 8,  DATE_SUB(CURDATE(), INTERVAL 3 DAY),  DATE_SUB(CURDATE(), INTERVAL 2 DAY), 'doruceno',  780.00,  94.00,  874.00, 'Vegan sendviče'),
(9,  'OBJ-2026-009', 1,  DATE_SUB(CURDATE(), INTERVAL 3 DAY),  DATE_SUB(CURDATE(), INTERVAL 1 DAY), 'doruceno', 1620.00, 194.00, 1814.00, ''),
(10, 'OBJ-2026-010', 9,  DATE_SUB(CURDATE(), INTERVAL 2 DAY),  DATE_SUB(CURDATE(), INTERVAL 1 DAY), 'doruceno', 1980.00, 238.00, 2218.00, 'Pension týdenní'),
(11, 'OBJ-2026-011', 10, DATE_SUB(CURDATE(), INTERVAL 2 DAY),  DATE_SUB(CURDATE(), INTERVAL 1 DAY), 'doruceno', 3260.00, 391.00, 3651.00, ''),
(12, 'OBJ-2026-012', 11, DATE_SUB(CURDATE(), INTERVAL 2 DAY),  DATE_SUB(CURDATE(), INTERVAL 1 DAY), 'doruceno', 1840.00, 221.00, 2061.00, 'Cukrárna týdenní'),
(13, 'OBJ-2026-013', 12, DATE_SUB(CURDATE(), INTERVAL 2 DAY),  CURDATE(),                            'expedice', 1450.00, 174.00, 1624.00, '90 obědů'),
(14, 'OBJ-2026-014', 2,  DATE_SUB(CURDATE(), INTERVAL 1 DAY),  CURDATE(),                            'expedice', 1890.00, 227.00, 2117.00, ''),
(15, 'OBJ-2026-015', 3,  DATE_SUB(CURDATE(), INTERVAL 1 DAY),  CURDATE(),                            'expedice', 4250.00, 510.00, 4760.00, 'Hotel ranní dodávka'),
(16, 'OBJ-2026-016', 4,  DATE_SUB(CURDATE(), INTERVAL 1 DAY),  CURDATE(),                            'priprava',  680.00,  82.00,  762.00, ''),
(17, 'OBJ-2026-017', 5,  DATE_SUB(CURDATE(), INTERVAL 1 DAY),  CURDATE(),                            'priprava', 1320.00, 158.00, 1478.00, 'Školní obědy'),
(18, 'OBJ-2026-018', 6,  CURDATE(),                            CURDATE(),                            'priprava', 6200.00, 744.00, 6944.00, 'Svatební hostina sobota'),
(19, 'OBJ-2026-019', 7,  CURDATE(),                            CURDATE(),                            'priprava',  420.00,  50.00,  470.00, ''),
(20, 'OBJ-2026-020', 8,  CURDATE(),                            CURDATE(),                            'priprava',  960.00, 115.00, 1075.00, ''),
(21, 'OBJ-2026-021', 1,  CURDATE(),                            DATE_ADD(CURDATE(), INTERVAL 1 DAY),  'nova',     1850.00, 222.00, 2072.00, 'Týdenní objednávka'),
(22, 'OBJ-2026-022', 2,  CURDATE(),                            DATE_ADD(CURDATE(), INTERVAL 1 DAY),  'nova',     2980.00, 360.00, 3340.00, ''),
(23, 'OBJ-2026-023', 3,  CURDATE(),                            DATE_ADD(CURDATE(), INTERVAL 1 DAY),  'nova',     5760.00, 691.00, 6451.00, ''),
(24, 'OBJ-2026-024', 4,  CURDATE(),                            DATE_ADD(CURDATE(), INTERVAL 1 DAY),  'nova',      920.00, 110.00, 1030.00, ''),
(25, 'OBJ-2026-025', 9,  CURDATE(),                            DATE_ADD(CURDATE(), INTERVAL 1 DAY),  'nova',     1980.00, 238.00, 2218.00, ''),
(26, 'OBJ-2026-026', 10, CURDATE(),                            DATE_ADD(CURDATE(), INTERVAL 2 DAY),  'nova',     3260.00, 391.00, 3651.00, ''),
(27, 'OBJ-2026-027', 11, CURDATE(),                            DATE_ADD(CURDATE(), INTERVAL 2 DAY),  'nova',     1840.00, 221.00, 2061.00, ''),
(28, 'OBJ-2026-028', 12, CURDATE(),                            DATE_ADD(CURDATE(), INTERVAL 2 DAY),  'nova',     1450.00, 174.00, 1624.00, ''),
(29, 'OBJ-2026-029', 5,  CURDATE(),                            DATE_ADD(CURDATE(), INTERVAL 3 DAY),  'nova',     1320.00, 158.00, 1478.00, ''),
(30, 'OBJ-2026-030', 6,  CURDATE(),                            DATE_ADD(CURDATE(), INTERVAL 5 DAY),  'nova',     8500.00,1020.00, 9520.00, 'Catering velký event');

-- ─── Objednávky položky (ukázky pro pár objednávek) ─────────────
INSERT INTO objednavky_polozky (objednavka_id, vyrobek_id, vyrobek_nazev, mnozstvi, jednotka, cena_bez_dph, sazba_dph) VALUES
(1,  1, 'Chléb pšenično-žitný 1kg', 20, 'ks', 38.00, 12),
(1,  3, 'Houska s mákem',           50, 'ks',  8.50, 12),
(1,  5, 'Rohlík tukový',           150, 'ks',  3.20, 12),
(2, 13, 'Větrník',                  30, 'ks', 32.00, 12),
(2, 17, 'Croissant s čokoládou',    40, 'ks', 28.00, 12),
(3, 26, 'Sendvič šunkový',          50, 'ks', 42.00, 21),
(3, 28, 'Wrap kuřecí',              30, 'ks', 68.00, 21),
(15, 1, 'Chléb pšenično-žitný 1kg', 30, 'ks', 38.00, 12),
(15, 4, 'Houska se sezamem',        80, 'ks',  8.50, 12),
(15, 6, 'Ciabatta 200g',            40, 'ks', 22.00, 12),
(21, 1, 'Chléb pšenično-žitný 1kg', 20, 'ks', 38.00, 12),
(21, 3, 'Houska s mákem',           50, 'ks',  8.50, 12);

-- ─── Faktury (5 ukázkových) ──────────────────────────────────────
INSERT INTO faktury (id, cislo, odberatel_id, datum_vystaveni, datum_splatnosti, datum_dph, castka_bez_dph, castka_dph, castka_celkem, castka_uhrazeno, variabilni_symbol) VALUES
(1, 'FAK-2026-001', 1, DATE_SUB(CURDATE(), INTERVAL 5 DAY), DATE_ADD(CURDATE(), INTERVAL  9 DAY), DATE_SUB(CURDATE(), INTERVAL 5 DAY), 1850.00, 222.00, 2072.00, 2072.00, '20260001'),
(2, 'FAK-2026-002', 2, DATE_SUB(CURDATE(), INTERVAL 4 DAY), DATE_ADD(CURDATE(), INTERVAL 10 DAY), DATE_SUB(CURDATE(), INTERVAL 4 DAY), 2980.00, 360.00, 3340.00, 3340.00, '20260002'),
(3, 'FAK-2026-003', 3, DATE_SUB(CURDATE(), INTERVAL 3 DAY), DATE_ADD(CURDATE(), INTERVAL 11 DAY), DATE_SUB(CURDATE(), INTERVAL 3 DAY), 5760.00, 691.00, 6451.00,    0.00, '20260003'),
(4, 'FAK-2026-004', 6, DATE_SUB(CURDATE(), INTERVAL 2 DAY), DATE_ADD(CURDATE(), INTERVAL 12 DAY), DATE_SUB(CURDATE(), INTERVAL 2 DAY), 8500.00,1020.00, 9520.00,    0.00, '20260004'),
(5, 'FAK-2026-005', 9, DATE_SUB(CURDATE(), INTERVAL 1 DAY), DATE_ADD(CURDATE(), INTERVAL 13 DAY), DATE_SUB(CURDATE(), INTERVAL 1 DAY), 1980.00, 238.00, 2218.00,    0.00, '20260005');

-- ─── Dodací listy (3) ───────────────────────────────────────────
INSERT INTO dodaci_listy (id, cislo, objednavka_id, odberatel_id, datum_vystaveni, datum_dodani, castka_celkem, fakturovano) VALUES
(1, 'DL-2026-001', 1, 1, DATE_SUB(CURDATE(), INTERVAL 6 DAY), DATE_SUB(CURDATE(), INTERVAL 6 DAY), 2072.00, 1),
(2, 'DL-2026-002', 2, 2, DATE_SUB(CURDATE(), INTERVAL 5 DAY), DATE_SUB(CURDATE(), INTERVAL 5 DAY), 3340.00, 1),
(3, 'DL-2026-003', 3, 3, DATE_SUB(CURDATE(), INTERVAL 4 DAY), DATE_SUB(CURDATE(), INTERVAL 4 DAY), 6451.00, 1);

-- ─── Číselné řady (aby další doklady navazovaly) ────────────────
INSERT INTO cislovani (typ, rok, posledni, predcisli) VALUES
('OBJ', YEAR(CURDATE()), 30, 'OBJ'),
('FAK', YEAR(CURDATE()),  5, 'FAK'),
('DL',  YEAR(CURDATE()),  3, 'DL');

SET FOREIGN_KEY_CHECKS = 1;

-- ════════════════════════════════════════════════════════════════
-- ✅ HOTOVO — kontrolní výpis
-- ════════════════════════════════════════════════════════════════
SELECT
  'Demo databáze připravena' AS info,
  (SELECT COUNT(*) FROM admin_users)             AS admin_uctu,
  (SELECT COUNT(*) FROM kategorie_vyrobku)       AS kategorii,
  (SELECT COUNT(*) FROM vyrobky)                 AS vyrobku,
  (SELECT COUNT(*) FROM odberatele)              AS odberatelu,
  (SELECT COUNT(*) FROM odberatele WHERE login_email IS NOT NULL) AS B2B_loginu,
  (SELECT COUNT(*) FROM objednavky)              AS objednavek,
  (SELECT COUNT(*) FROM faktury)                  AS faktur,
  (SELECT COUNT(*) FROM dodaci_listy)            AS dodacich;
