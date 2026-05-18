-- ════════════════════════════════════════════════════════════
-- 🧪 APPEK DEMO SEED — realistická data pro demo.appek.cz
-- ════════════════════════════════════════════════════════════
-- ⚠️ DŮLEŽITÉ — tento seed POUZE plní data (INSERT).
--    Tabulky vytváří customer install.php (api/_schema.sql + _full_schema.php).
--    Pokud běžíš seed bez install: nejdřív otevři https://demo.appek.cz/install.php
--
-- Hodinový reset volá tento samý SQL (admin_reset_demo.php → demo_reset.sh).
--
-- DEMO PŘIHLÁŠENÍ:
--   Admin:   demo@appek.cz / demo1234    (vyroba@appek.cz / vyroba1234, expedice@appek.cz / expedice1234)
--   B2B:     odberatel@demo.cz / demo1234
-- ════════════════════════════════════════════════════════════

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

-- ─── Truncate (čistý stav, FK-safe pořadí) ──────────────────────
DELETE FROM faktura_polozky;
DELETE FROM faktury;
DELETE FROM dodaci_list_polozky;
DELETE FROM dodaci_listy;
DELETE FROM objednavky_polozky;
DELETE FROM objednavky;
DELETE FROM vyrobky;
DELETE FROM odberatele;
DELETE FROM kategorie_vyrobku;
DELETE FROM cislovani;
DELETE FROM admin_users WHERE email LIKE '%@appek.cz';

-- Reset AUTO_INCREMENT pro pěkné ID
ALTER TABLE kategorie_vyrobku AUTO_INCREMENT = 1;
ALTER TABLE vyrobky           AUTO_INCREMENT = 1;
ALTER TABLE odberatele        AUTO_INCREMENT = 1;
ALTER TABLE objednavky        AUTO_INCREMENT = 1;
ALTER TABLE objednavky_polozky AUTO_INCREMENT = 1;
ALTER TABLE dodaci_listy      AUTO_INCREMENT = 1;
ALTER TABLE dodaci_list_polozky AUTO_INCREMENT = 1;
ALTER TABLE faktury           AUTO_INCREMENT = 1;
ALTER TABLE faktura_polozky   AUTO_INCREMENT = 1;

-- ════════════════════════════════════════════════════════════
-- 🔐 ADMIN ÚČTY — 3× (admin/vyroba/expedice)
-- Bcrypt: cost 10, ověřené hashe pro demo1234 / vyroba1234 / expedice1234
-- ════════════════════════════════════════════════════════════
INSERT INTO admin_users (id, email, heslo_hash, jmeno, role, aktivni, created_at) VALUES
(1, 'demo@appek.cz',     '$2y$10$3.q8dHGvYZpwC0pYC/lKLOTjxFs/L.8uQxVsSEr4Aq8Heojh.tqL2', 'Demo Admin',    'admin',    1, NOW()),
(2, 'vyroba@appek.cz',   '$2y$10$/9T9fCq/ykWR41JTlppUsOOYEmnYOufGvKxONOOMt9kUJkbjr3kWS', 'Demo Výroba',   'vyroba',   1, NOW()),
(3, 'expedice@appek.cz', '$2y$10$CfLLMnqbijzFlexIMfhVlOSIQ6PZG.kY.ll75oTJ8TtXRA2KIRlGi', 'Demo Expedice', 'expedice', 1, NOW());

-- ════════════════════════════════════════════════════════════
-- 🏷️ KATEGORIE VÝROBKŮ — 5× (Pečivo, Cukrářské, Lahůdky, Nápoje, Suroviny)
-- ════════════════════════════════════════════════════════════
INSERT INTO kategorie_vyrobku (id, nazev, ikona, barva, poradi, aktivni) VALUES
(1, 'Pečivo',     '🥖', '#BA7517', 1, 1),
(2, 'Cukrářské',  '🎂', '#E91E63', 2, 1),
(3, 'Lahůdky',    '🥪', '#4CAF50', 3, 1),
(4, 'Nápoje',     '☕', '#795548', 4, 1),
(5, 'Suroviny',   '🌾', '#9E9E9E', 5, 1);

-- ════════════════════════════════════════════════════════════
-- 📦 VÝROBKY — 50× (jednotka_id/sazba_dph_id viz _schema.sql seedy)
-- jednotky:  1='ks'  2='kg'  3='g'  4='l'  5='ml'  6='bal'
-- sazby_dph: 1=12%   2=21%   3=0%
-- ════════════════════════════════════════════════════════════
INSERT INTO vyrobky (id, cislo, nazev, popis, kategorie_id, jednotka_id, sazba_dph_id, cena_bez_dph, hmotnost_g, alergeny, aktivni, oblibeny, je_novinka, je_akce) VALUES
-- ── Pečivo (1) ──
(1,  'CHL001', 'Chléb pšenično-žitný 1kg', 'Tradiční receptura, šárová výroba',     1, 1, 1, 38.00, 1000, 'lepek', 1, 1, 0, 0),
(2,  'BAG001', 'Bageta francouzská 250g',  'Křupavá kůrka, vzdušná střídka',         1, 1, 1, 18.00,  250, 'lepek', 1, 1, 0, 0),
(3,  'HOU001', 'Houska s mákem',           '60g, kynuté těsto',                       1, 1, 1,  8.50,   60, 'lepek,mák', 1, 1, 0, 0),
(4,  'HOU002', 'Houska se sezamem',        '60g, kynuté těsto',                       1, 1, 1,  8.50,   60, 'lepek,sezam', 1, 0, 0, 0),
(5,  'ROH001', 'Rohlík tukový',            '40g klasik',                              1, 1, 1,  3.20,   40, 'lepek', 1, 1, 0, 0),
(6,  'CIA001', 'Ciabatta 200g',            'Italský styl, olivový olej',              1, 1, 1, 22.00,  200, 'lepek', 1, 0, 1, 0),
(7,  'VAN001', 'Vánočka máslová',          '500g, mandle, rozinky · jen prosinec',    1, 1, 1, 85.00,  500, 'lepek,vejce,mléko', 1, 0, 0, 0),
(8,  'MAZ001', 'Mazanec velikonoční',      '450g · jen březen/duben',                 1, 1, 1, 78.00,  450, 'lepek,vejce,mléko', 1, 0, 0, 0),
(9,  'FOC001', 'Focaccia s rozmarýnem',    '250g · italský styl',                     1, 1, 1, 68.00,  250, 'lepek', 1, 0, 1, 0),
(10, 'SOU001', 'Sourdough chléb 800g',     'Žitný kvas, 24h kynutí',                  1, 1, 1, 72.00,  800, 'lepek', 1, 1, 0, 0),
(11, 'BLG001', 'Bezlepkový chleba 500g',   'Certifikovaně bezlepkový',                1, 1, 1, 138.00, 500, '', 1, 0, 0, 0),
(12, 'VEL001', 'Velikonoční beránek',      '350g · jaro',                             1, 1, 1, 88.00,  350, 'lepek,vejce,mléko', 1, 0, 0, 0),
-- ── Cukrářské (2) ──
(13, 'VET001', 'Větrník',                  'Klasika · šlehačka + karamel',            2, 1, 1, 32.00,  100, 'lepek,vejce,mléko', 1, 1, 0, 0),
(14, 'VRO001', 'Vanilkový rohlíček',       'Domácí máslové těsto',                    2, 1, 1, 25.00,   30, 'lepek,vejce,mléko,ořechy', 1, 1, 0, 0),
(15, 'TIR001', 'Tiramisu porce',           'Mascarpone, kafe Lavazza',                2, 1, 1, 48.00,  120, 'lepek,vejce,mléko', 1, 1, 0, 0),
(16, 'CHE001', 'Cheesecake malinový',      'Tvarohový, lesní maliny',                 2, 1, 1, 55.00,  130, 'lepek,vejce,mléko', 1, 0, 0, 0),
(17, 'CRO001', 'Croissant s čokoládou',    'Belgická čokoláda',                       2, 1, 1, 28.00,   80, 'lepek,mléko,sója', 1, 1, 0, 0),
(18, 'DON001', 'Donut glazovaný',          'Domácí kynuté',                           2, 1, 1, 22.00,   70, 'lepek,vejce,mléko', 1, 0, 0, 0),
(19, 'DRT001', 'Dort čokoládový 1kg',      'Belgická čokoláda 70%',                   2, 1, 1, 380.00,1000, 'lepek,vejce,mléko,sója', 1, 0, 0, 0),
(20, 'DRT002', 'Dort jahodový 1kg',        'Sezónní · jaro/léto',                     2, 1, 1, 360.00,1000, 'lepek,vejce,mléko', 1, 0, 1, 0),
(21, 'PER001', 'Perníkové srdíčko',        '50g · valentýn nebo prosinec',            2, 1, 1, 18.00,   50, 'lepek,vejce', 1, 0, 0, 0),
(22, 'LIN001', 'Linecké cukroví 250g',     'Máslové · prosinec',                      2, 1, 1, 78.00,  250, 'lepek,vejce,mléko', 1, 0, 0, 0),
(23, 'PER002', 'Vánoční perníčky 500g',    'Tradiční · prosinec',                     2, 1, 1,148.00,  500, 'lepek,vejce', 1, 0, 0, 0),
(24, 'VEG001', 'Vegan brownie',            '100g · bez vajec a mléka',                2, 1, 1, 48.00,  100, 'lepek,sója', 1, 0, 1, 0),
(25, 'MAC001', 'Macarons mix (6ks)',       'Vanilka, čokoláda, malina',               2, 6, 1,198.00,  150, 'vejce,ořechy,mléko', 1, 0, 0, 0),
-- ── Lahůdky (3) ──
(26, 'SEN001', 'Sendvič šunkový',          'Pšeničný chléb, kvalitní šunka, máslo',   3, 1, 2, 42.00,  180, 'lepek,mléko,hořčice', 1, 1, 0, 0),
(27, 'SEN002', 'Sendvič vegetariánský',    'Hummus, rajče, salát, okurka',            3, 1, 2, 45.00,  180, 'lepek,sezam', 1, 0, 0, 0),
(28, 'WRP001', 'Wrap kuřecí',              'Grilované kuřecí, salát',                 3, 1, 2, 68.00,  220, 'lepek,mléko,hořčice', 1, 1, 0, 0),
(29, 'WRP002', 'Wrap losos',               'Uzený losos, smetanový sýr',              3, 1, 2, 85.00,  220, 'lepek,ryby,mléko', 1, 0, 1, 0),
(30, 'BSL001', 'Bramborový salát 500g',    'Klasická receptura',                      3, 2, 2, 62.00,  500, 'vejce,hořčice', 1, 1, 0, 0),
(31, 'MSL001', 'Mexický salát 500g',       'Fazole, kukuřice, paprika',               3, 2, 2, 78.00,  500, '', 1, 0, 1, 0),
(32, 'ROL001', 'Šunkový rolled',           'Šunka, sýr, okurka',                      3, 1, 2, 45.00,  120, 'lepek,mléko', 1, 0, 0, 0),
(33, 'QUI001', 'Slaný koláč Quiche Lorraine','300g · slanina, sýr, smetana',          3, 1, 2,125.00,  300, 'lepek,vejce,mléko', 1, 0, 0, 0),
-- ── Nápoje (4) ──
(34, 'KAV001', 'Káva Lungo 0.5l',          'Lavazza, čerstvě uvařeno',                4, 1, 1, 45.00,  500, '', 1, 1, 0, 0),
(35, 'KAV002', 'Latte 0.4l',               'Espresso + plnotučné mléko',              4, 1, 1, 55.00,  400, 'mléko', 1, 1, 0, 0),
(36, 'KAV003', 'Cappuccino 0.3l',          'Klasický recept',                         4, 1, 1, 48.00,  300, 'mléko', 1, 0, 0, 0),
(37, 'LIM001', 'Limonáda bezová 0.5l',     'Domácí sirup',                            4, 1, 1, 35.00,  500, '', 1, 0, 1, 0),
(38, 'LIM002', 'Limonáda mátová 0.5l',     'Domácí sirup',                            4, 1, 1, 35.00,  500, '', 1, 0, 0, 0),
(39, 'DZU001', 'Čerstvý džus pomerančový', '0.3l · 100% ovoce',                       4, 1, 1, 42.00,  300, '', 1, 0, 0, 0),
(40, 'CAJ001', 'Čaj černý Earl Grey',      '0.3l · Twinings',                         4, 1, 1, 28.00,  300, '', 1, 0, 0, 0),
-- ── Suroviny (5) ──
(41, 'MOU001', 'Mouka pšeničná hladká',    '00 · prémium',                            5, 2, 1, 18.00, 1000, 'lepek', 1, 0, 0, 0),
(42, 'MOU002', 'Mouka chlebová',           'BIO',                                     5, 2, 1, 17.50, 1000, 'lepek', 1, 0, 0, 0),
(43, 'MAS001', 'Máslo 250g',               '82% tuk · pravé',                         5, 1, 1, 62.00,  250, 'mléko', 1, 1, 0, 0),
(44, 'MLE001', 'Mléko plnotučné 1l',       '3.5% čerstvé',                            5, 1, 1, 26.00, 1000, 'mléko', 1, 1, 0, 0),
(45, 'VEJ001', 'Vejce velikost L (30ks)',  'Stáj A',                                  5, 6, 1,168.00, 1800, 'vejce', 1, 0, 0, 0),
(46, 'CUK001', 'Cukr krystal 1kg',         '',                                        5, 2, 1, 22.00, 1000, '', 1, 0, 0, 0),
(47, 'CUK002', 'Cukr moučka 1kg',          'Jemný',                                   5, 2, 1, 28.00, 1000, '', 1, 0, 0, 0),
(48, 'KAK001', 'Kakao prémium 500g',       '',                                        5, 1, 1, 98.00,  500, '', 1, 0, 0, 0),
(49, 'VAN002', 'Vanilkový lusk',           'Madagaskar',                              5, 1, 1, 45.00,    5, '', 1, 0, 1, 0),
(50, 'SKO001', 'Skořice mletá 100g',       'Cejlonská',                               5, 1, 1, 35.00,  100, '', 1, 0, 0, 0);

-- ════════════════════════════════════════════════════════════
-- 👥 ODBĚRATELÉ — 12× (B2B zákazníci)
-- První 3 mají LOGIN PŘÍSTUP do B2B portálu (login_email + heslo_hash)
-- B2B demo: odberatel@demo.cz / demo1234
-- ════════════════════════════════════════════════════════════
INSERT INTO odberatele (id, cislo, nazev, ico, dic, ulice, mesto, psc, email, telefon, typ, splatnost_dni, login_email, heslo_hash, notif_emaily, created_at) VALUES
-- s loginem (B2B portál)
(1,  'ODB-001', 'Penzion U Lípy',                '12345001', 'CZ12345001', 'Lipová 12',     'Brno',     '60200', 'penzion@uliny.cz',      '+420 777 100 001', 'penzion',    14, 'odberatel@demo.cz', '$2y$10$3.q8dHGvYZpwC0pYC/lKLOTjxFs/L.8uQxVsSEr4Aq8Heojh.tqL2', 'penzion@uliny.cz', NOW()),
(2,  'ODB-002', 'Restaurace Pod Lípou',          '12345002', 'CZ12345002', 'Hlavní 24',     'Praha 5',  '15000', 'objednavky@podlipou.cz','+420 777 100 002', 'restaurace', 14, 'restaurace@demo.cz','$2y$10$3.q8dHGvYZpwC0pYC/lKLOTjxFs/L.8uQxVsSEr4Aq8Heojh.tqL2', 'objednavky@podlipou.cz', NOW()),
(3,  'ODB-003', 'Hotel Continental ****',        '12345003', 'CZ12345003', 'Václavské 7',   'Praha 1',  '11000', 'fb@continental.cz',     '+420 777 100 003', 'hotel',      14, 'hotel@demo.cz',     '$2y$10$3.q8dHGvYZpwC0pYC/lKLOTjxFs/L.8uQxVsSEr4Aq8Heojh.tqL2', 'fb@continental.cz', NOW()),
-- ostatní bez loginu (admin-only)
(4,  'ODB-004', 'Kavárna Sladký Sen',            '12345004', 'CZ12345004', 'Masarykova 5',  'Brno',     '60200', 'sklad@sladkysen.cz',    '+420 777 100 004', 'kavarna',    14, NULL, NULL, 'sklad@sladkysen.cz', NOW()),
(5,  'ODB-005', 'Školní jídelna ZŠ Komenského',  '12345005', 'CZ12345005', 'Komenského 8',  'Plzeň',    '30100', 'jidelna@zskom.cz',      '+420 777 100 005', 'jidelna',    30, NULL, NULL, 'jidelna@zskom.cz', NOW()),
(6,  'ODB-006', 'Catering Premium s.r.o.',       '12345006', 'CZ12345006', 'Průmyslová 3',  'Ostrava',  '70200', 'objednavky@catpre.cz',  '+420 777 100 006', 'catering',   14, NULL, NULL, 'objednavky@catpre.cz', NOW()),
(7,  'ODB-007', 'Pivnice Na Kopečku',            '12345007', 'CZ12345007', 'Kopcová 18',    'Liberec',  '46001', 'pavel@nakopecku.cz',    '+420 777 100 007', 'restaurace', 7,  NULL, NULL, 'pavel@nakopecku.cz', NOW()),
(8,  'ODB-008', 'Bistro Vegan Garden',           '12345008', 'CZ12345008', 'Bezručova 11',  'Praha 6',  '16000', 'order@vegangarden.cz',  '+420 777 100 008', 'bistro',     14, NULL, NULL, 'order@vegangarden.cz', NOW()),
(9,  'ODB-009', 'Pension Hluboká nad Vltavou',   '12345009', 'CZ12345009', 'Zámecká 2',     'Hluboká',  '37341', 'pension@hluboka.cz',    '+420 777 100 009', 'penzion',    14, NULL, NULL, 'pension@hluboka.cz', NOW()),
(10, 'ODB-010', 'Restaurace Stará pošta',        '12345010', 'CZ12345010', 'Hlavní 100',    'Olomouc',  '77900', 'restaurant@staraposta.cz','+420 777 100 010','restaurace', 14, NULL, NULL, 'restaurant@staraposta.cz', NOW()),
(11, 'ODB-011', 'Cukrárna U Tří Kytek',          '12345011', 'CZ12345011', 'Květinová 5',   'Hradec K.','50000', 'cukrarna@trikytky.cz',  '+420 777 100 011', 'cukrarna',   14, NULL, NULL, 'cukrarna@trikytky.cz', NOW()),
(12, 'ODB-012', 'Senior dům Slunečnice',         '12345012', 'CZ12345012', 'Slunná 14',     'Pardubice','53002', 'strava@slunecnice.cz',  '+420 777 100 012', 'jidelna',    30, NULL, NULL, 'strava@slunecnice.cz', NOW());

-- ════════════════════════════════════════════════════════════
-- 📋 OBJEDNÁVKY — 30× přes různé stavy (nova/priprava/expedice/doruceno)
-- ════════════════════════════════════════════════════════════
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

-- ════════════════════════════════════════════════════════════
-- 📋 OBJEDNÁVKY — položky (pár vzorků, aby dashboard ukázal data)
-- ════════════════════════════════════════════════════════════
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
(15, 6, 'Ciabatta 200g',            40, 'ks', 22.00, 12);

-- ════════════════════════════════════════════════════════════
-- 💰 FAKTURY — 5× ukázkových (z doručených objednávek)
-- ════════════════════════════════════════════════════════════
INSERT INTO faktury (id, cislo, odberatel_id, datum_vystaveni, datum_splatnosti, datum_dph, castka_bez_dph, castka_dph, castka_celkem, castka_uhrazeno, variabilni_symbol) VALUES
(1, 'FAK-2026-001', 1, DATE_SUB(CURDATE(), INTERVAL 5 DAY), DATE_ADD(CURDATE(), INTERVAL 9 DAY),  DATE_SUB(CURDATE(), INTERVAL 5 DAY), 1850.00, 222.00, 2072.00, 2072.00, '20260001'),
(2, 'FAK-2026-002', 2, DATE_SUB(CURDATE(), INTERVAL 4 DAY), DATE_ADD(CURDATE(), INTERVAL 10 DAY), DATE_SUB(CURDATE(), INTERVAL 4 DAY), 2980.00, 360.00, 3340.00, 3340.00, '20260002'),
(3, 'FAK-2026-003', 3, DATE_SUB(CURDATE(), INTERVAL 3 DAY), DATE_ADD(CURDATE(), INTERVAL 11 DAY), DATE_SUB(CURDATE(), INTERVAL 3 DAY), 5760.00, 691.00, 6451.00,    0.00, '20260003'),
(4, 'FAK-2026-004', 6, DATE_SUB(CURDATE(), INTERVAL 2 DAY), DATE_ADD(CURDATE(), INTERVAL 12 DAY), DATE_SUB(CURDATE(), INTERVAL 2 DAY), 8500.00,1020.00, 9520.00,    0.00, '20260004'),
(5, 'FAK-2026-005', 9, DATE_SUB(CURDATE(), INTERVAL 1 DAY), DATE_ADD(CURDATE(), INTERVAL 13 DAY), DATE_SUB(CURDATE(), INTERVAL 1 DAY), 1980.00, 238.00, 2218.00,    0.00, '20260005');

-- ════════════════════════════════════════════════════════════
-- 📄 DODACÍ LISTY — 3× ukázkové
-- ════════════════════════════════════════════════════════════
INSERT INTO dodaci_listy (id, cislo, objednavka_id, odberatel_id, datum_vystaveni, datum_dodani, castka_celkem, fakturovano) VALUES
(1, 'DL-2026-001', 1, 1, DATE_SUB(CURDATE(), INTERVAL 6 DAY), DATE_SUB(CURDATE(), INTERVAL 6 DAY), 2072.00, 1),
(2, 'DL-2026-002', 2, 2, DATE_SUB(CURDATE(), INTERVAL 5 DAY), DATE_SUB(CURDATE(), INTERVAL 5 DAY), 3340.00, 1),
(3, 'DL-2026-003', 3, 3, DATE_SUB(CURDATE(), INTERVAL 4 DAY), DATE_SUB(CURDATE(), INTERVAL 4 DAY), 6451.00, 1);

SET FOREIGN_KEY_CHECKS = 1;

-- ════════════════════════════════════════════════════════════
-- ✅ Hotovo
-- ════════════════════════════════════════════════════════════
SELECT
  'Demo seed OK' AS info,
  (SELECT COUNT(*) FROM vyrobky)            AS vyrobku,
  (SELECT COUNT(*) FROM odberatele)          AS odberatelu,
  (SELECT COUNT(*) FROM odberatele WHERE login_email IS NOT NULL) AS s_loginem_B2B,
  (SELECT COUNT(*) FROM objednavky)          AS objednavek,
  (SELECT COUNT(*) FROM faktury)             AS faktur,
  (SELECT COUNT(*) FROM admin_users WHERE email LIKE '%@appek.cz') AS admin_uctu;
