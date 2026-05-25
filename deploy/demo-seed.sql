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
-- Pozn.: restaurant_pos_* nemusí existovat při úplně prvním resetu —
--        admin_reset_demo.php chyby polyká a seed je dál CREATE-IF-NOT-EXISTS.
DELETE FROM restaurant_pos_platby;
DELETE FROM restaurant_pos_polozky;
DELETE FROM restaurant_pos_ucty;
DELETE FROM restaurant_tables;
DELETE FROM faktury_dodaci_listy;
DELETE FROM faktura_polozky;
DELETE FROM faktury;
DELETE FROM dodaci_list_polozky;
DELETE FROM dodaci_listy;
DELETE FROM objednavky_polozky;
DELETE FROM objednavky;
DELETE FROM vyrobni_list_polozky;
DELETE FROM vyrobni_listy;
DELETE FROM vyrobek_suroviny;
DELETE FROM suroviny;
DELETE FROM mista_dodani;
DELETE FROM vyrobky;
DELETE FROM odberatele;
DELETE FROM kategorie_vyrobku;
DELETE FROM cislovani;
DELETE FROM admin_users WHERE email LIKE '%@appek.cz';

-- Reset AUTO_INCREMENT pro pěkné ID
ALTER TABLE kategorie_vyrobku AUTO_INCREMENT = 1;
ALTER TABLE vyrobky           AUTO_INCREMENT = 1;
ALTER TABLE odberatele        AUTO_INCREMENT = 1;
ALTER TABLE mista_dodani      AUTO_INCREMENT = 1;
ALTER TABLE objednavky        AUTO_INCREMENT = 1;
ALTER TABLE objednavky_polozky AUTO_INCREMENT = 1;
ALTER TABLE dodaci_listy      AUTO_INCREMENT = 1;
ALTER TABLE dodaci_list_polozky AUTO_INCREMENT = 1;
ALTER TABLE faktury           AUTO_INCREMENT = 1;
ALTER TABLE faktura_polozky   AUTO_INCREMENT = 1;
ALTER TABLE suroviny          AUTO_INCREMENT = 1;
ALTER TABLE vyrobek_suroviny  AUTO_INCREMENT = 1;
ALTER TABLE vyrobni_listy     AUTO_INCREMENT = 1;
ALTER TABLE vyrobni_list_polozky AUTO_INCREMENT = 1;

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
-- 🏠 MÍSTA DODÁNÍ — dodací adresy odběratelů
-- ════════════════════════════════════════════════════════════
INSERT INTO mista_dodani (id, odberatel_id, nazev, ulice, mesto, psc, kontaktni_osoba, telefon, cas_dodani, vychozi, aktivni, poradi) VALUES
(1, 1, 'Penzion U Lípy — recepce', 'Lipová 12', 'Brno', '60200', 'Marie Lípová', '+420 777 100 001', '06:30–07:30', 1, 1, 1),
(2, 1, 'Penzion U Lípy — kavárna', 'Lipová 12a', 'Brno', '60200', 'Jan Lípa', '+420 777 100 021', '07:00–08:00', 0, 1, 2),
(3, 2, 'Restaurace Pod Lípou — kuchyně', 'Hlavní 24', 'Praha 5', '15000', 'Petr Novák', '+420 777 100 002', 'do 08:00', 1, 1, 3),
(4, 3, 'Hotel Continental — zásobování', 'Václavské 7', 'Praha 1', '11000', 'Jana Veselá', '+420 777 100 003', '05:30–06:30', 1, 1, 4),
(5, 6, 'Catering Premium — sklad', 'Průmyslová 3', 'Ostrava', '70200', 'Tomáš Svoboda', '+420 777 100 006', 'dle dohody', 1, 1, 5),
(6, 9, 'Pension Hluboká — recepce', 'Zámecká 2', 'Hluboká', '37341', 'Karel Vondra', '+420 777 100 009', '07:00–08:00', 1, 1, 6),
(7, 6, 'Catering Premium — event hala', 'Výstavní 10', 'Ostrava', '70030', 'Lenka Eventová', '+420 777 100 026', 'dle eventu', 0, 1, 7),
(8, 9, 'Pension Hluboká — restaurace', 'Zámecká 2b', 'Hluboká', '37341', 'Eva Vondrová', '+420 777 100 029', '07:30–08:30', 0, 1, 8);

-- ════════════════════════════════════════════════════════════
-- 📋 OBJEDNÁVKY — 30× (validní stavy, částky = součet položek)
-- ════════════════════════════════════════════════════════════
INSERT INTO objednavky (id, cislo, odberatel_id, misto_dodani_id, datum_objednani, datum_dodani, stav, castka_bez_dph, castka_dph, castka_celkem, poznamka) VALUES
(1, 'OBJ-2026-001', 1, 1, DATE_SUB(CURDATE(), INTERVAL 7 DAY), DATE_SUB(CURDATE(), INTERVAL 6 DAY), 'dorucena', 1665.00, 199.80, 1864.80, 'Pravidelný odběr pondělí'),
(2, 'OBJ-2026-002', 2, 3, DATE_SUB(CURDATE(), INTERVAL 6 DAY), DATE_SUB(CURDATE(), INTERVAL 5 DAY), 'dorucena', 2080.00, 249.60, 2329.60, 'Termín do 8:00'),
(3, 'OBJ-2026-003', 3, 4, DATE_SUB(CURDATE(), INTERVAL 5 DAY), DATE_SUB(CURDATE(), INTERVAL 4 DAY), 'dorucena', 4140.00, 869.40, 5009.40, 'Snídaně pro hotel'),
(4, 'OBJ-2026-004', 4, NULL, DATE_SUB(CURDATE(), INTERVAL 4 DAY), DATE_SUB(CURDATE(), INTERVAL 3 DAY), 'dorucena', 796.00, 95.52, 891.52, ''),
(5, 'OBJ-2026-005', 5, NULL, DATE_SUB(CURDATE(), INTERVAL 4 DAY), DATE_SUB(CURDATE(), INTERVAL 3 DAY), 'dorucena', 910.00, 109.20, 1019.20, 'Školní obědy'),
(6, 'OBJ-2026-006', 6, 7, DATE_SUB(CURDATE(), INTERVAL 4 DAY), DATE_SUB(CURDATE(), INTERVAL 2 DAY), 'dorucena', 8560.00, 1635.60, 10195.60, 'Catering svatba'),
(7, 'OBJ-2026-007', 7, NULL, DATE_SUB(CURDATE(), INTERVAL 3 DAY), DATE_SUB(CURDATE(), INTERVAL 2 DAY), 'dorucena', 575.00, 69.00, 644.00, ''),
(8, 'OBJ-2026-008', 8, NULL, DATE_SUB(CURDATE(), INTERVAL 3 DAY), DATE_SUB(CURDATE(), INTERVAL 2 DAY), 'dorucena', 690.00, 82.80, 772.80, 'Vegan sendviče'),
(9, 'OBJ-2026-009', 9, 8, DATE_SUB(CURDATE(), INTERVAL 3 DAY), DATE_SUB(CURDATE(), INTERVAL 1 DAY), 'dorucena', 1670.00, 200.40, 1870.40, ''),
(10, 'OBJ-2026-010', 10, NULL, DATE_SUB(CURDATE(), INTERVAL 2 DAY), DATE_SUB(CURDATE(), INTERVAL 1 DAY), 'dorucena', 1780.00, 213.60, 1993.60, 'Pension týdenní'),
(11, 'OBJ-2026-011', 11, NULL, DATE_SUB(CURDATE(), INTERVAL 2 DAY), DATE_SUB(CURDATE(), INTERVAL 1 DAY), 'dorucena', 2920.00, 613.20, 3533.20, ''),
(12, 'OBJ-2026-012', 12, NULL, DATE_SUB(CURDATE(), INTERVAL 2 DAY), DATE_SUB(CURDATE(), INTERVAL 1 DAY), 'dorucena', 2120.00, 254.40, 2374.40, 'Cukrárna týdenní'),
(13, 'OBJ-2026-013', 1, 2, DATE_SUB(CURDATE(), INTERVAL 2 DAY), CURDATE(), 'expedovana', 1194.00, 143.28, 1337.28, '90 obědů'),
(14, 'OBJ-2026-014', 2, 3, DATE_SUB(CURDATE(), INTERVAL 1 DAY), CURDATE(), 'expedovana', 1104.00, 132.48, 1236.48, ''),
(15, 'OBJ-2026-015', 3, 4, DATE_SUB(CURDATE(), INTERVAL 1 DAY), CURDATE(), 'expedovana', 2884.00, 346.08, 3230.08, 'Hotel ranní dodávka'),
(16, 'OBJ-2026-016', 4, NULL, DATE_SUB(CURDATE(), INTERVAL 1 DAY), CURDATE(), 'pripravena', 681.00, 81.72, 762.72, ''),
(17, 'OBJ-2026-017', 5, NULL, DATE_SUB(CURDATE(), INTERVAL 1 DAY), CURDATE(), 'pripravena', 1360.00, 163.20, 1523.20, 'Školní obědy'),
(18, 'OBJ-2026-018', 6, 7, CURDATE(), CURDATE(), 've_vyrobe', 5240.00, 1100.40, 6340.40, 'Svatební hostina sobota'),
(19, 'OBJ-2026-019', 7, NULL, CURDATE(), CURDATE(), 've_vyrobe', 558.00, 66.96, 624.96, ''),
(20, 'OBJ-2026-020', 8, NULL, CURDATE(), CURDATE(), 've_vyrobe', 1560.00, 187.20, 1747.20, ''),
(21, 'OBJ-2026-021', 1, 1, CURDATE(), DATE_ADD(CURDATE(), INTERVAL 1 DAY), 'potvrzena', 1665.00, 199.80, 1864.80, 'Týdenní objednávka'),
(22, 'OBJ-2026-022', 2, 3, CURDATE(), DATE_ADD(CURDATE(), INTERVAL 1 DAY), 'potvrzena', 2080.00, 249.60, 2329.60, ''),
(23, 'OBJ-2026-023', 3, 4, CURDATE(), DATE_ADD(CURDATE(), INTERVAL 1 DAY), 'nova', 4140.00, 869.40, 5009.40, ''),
(24, 'OBJ-2026-024', 4, NULL, CURDATE(), DATE_ADD(CURDATE(), INTERVAL 1 DAY), 'nova', 796.00, 95.52, 891.52, ''),
(25, 'OBJ-2026-025', 9, NULL, CURDATE(), DATE_ADD(CURDATE(), INTERVAL 1 DAY), 'nova', 1780.00, 213.60, 1993.60, ''),
(26, 'OBJ-2026-026', 10, NULL, CURDATE(), DATE_ADD(CURDATE(), INTERVAL 2 DAY), 'nova', 2920.00, 613.20, 3533.20, ''),
(27, 'OBJ-2026-027', 11, NULL, CURDATE(), DATE_ADD(CURDATE(), INTERVAL 2 DAY), 'nova', 2120.00, 254.40, 2374.40, ''),
(28, 'OBJ-2026-028', 12, NULL, CURDATE(), DATE_ADD(CURDATE(), INTERVAL 2 DAY), 'nova', 532.00, 63.84, 595.84, ''),
(29, 'OBJ-2026-029', 5, NULL, CURDATE(), DATE_ADD(CURDATE(), INTERVAL 3 DAY), 'nova', 1360.00, 163.20, 1523.20, ''),
(30, 'OBJ-2026-030', 6, 7, CURDATE(), DATE_ADD(CURDATE(), INTERVAL 5 DAY), 'nova', 8560.00, 1635.60, 10195.60, 'Catering velký event');

-- ── Objednávky — položky ──
INSERT INTO objednavky_polozky (objednavka_id, vyrobek_id, vyrobek_nazev, mnozstvi, jednotka, cena_bez_dph, sazba_dph) VALUES
(1, 1, 'Chléb pšenično-žitný 1kg', 20, 'ks', 38.00, 12),
(1, 3, 'Houska s mákem', 50, 'ks', 8.50, 12),
(1, 5, 'Rohlík tukový', 150, 'ks', 3.20, 12),
(2, 13, 'Větrník', 30, 'ks', 32.00, 12),
(2, 17, 'Croissant s čokoládou', 40, 'ks', 28.00, 12),
(3, 26, 'Sendvič šunkový', 50, 'ks', 42.00, 21),
(3, 28, 'Wrap kuřecí', 30, 'ks', 68.00, 21),
(4, 2, 'Bageta francouzská 250g', 30, 'ks', 18.00, 12),
(4, 5, 'Rohlík tukový', 80, 'ks', 3.20, 12),
(5, 1, 'Chléb pšenično-žitný 1kg', 15, 'ks', 38.00, 12),
(5, 3, 'Houska s mákem', 40, 'ks', 8.50, 12),
(6, 26, 'Sendvič šunkový', 80, 'ks', 42.00, 21),
(6, 28, 'Wrap kuřecí', 50, 'ks', 68.00, 21),
(6, 34, 'Káva Lungo 0.5l', 40, 'ks', 45.00, 12),
(7, 5, 'Rohlík tukový', 100, 'ks', 3.20, 12),
(7, 3, 'Houska s mákem', 30, 'ks', 8.50, 12),
(8, 2, 'Bageta francouzská 250g', 20, 'ks', 18.00, 12),
(8, 6, 'Ciabatta 200g', 15, 'ks', 22.00, 12),
(9, 1, 'Chléb pšenično-žitný 1kg', 25, 'ks', 38.00, 12),
(9, 10, 'Sourdough chléb 800g', 10, 'ks', 72.00, 12),
(10, 1, 'Chléb pšenično-žitný 1kg', 30, 'ks', 38.00, 12),
(10, 13, 'Větrník', 20, 'ks', 32.00, 12),
(11, 26, 'Sendvič šunkový', 40, 'ks', 42.00, 21),
(11, 30, 'Bramborový salát 500g', 20, 'ks', 62.00, 21),
(12, 13, 'Větrník', 40, 'ks', 32.00, 12),
(12, 17, 'Croissant s čokoládou', 30, 'ks', 28.00, 12),
(13, 1, 'Chléb pšenično-žitný 1kg', 18, 'ks', 38.00, 12),
(13, 3, 'Houska s mákem', 60, 'ks', 8.50, 12),
(14, 2, 'Bageta francouzská 250g', 40, 'ks', 18.00, 12),
(14, 5, 'Rohlík tukový', 120, 'ks', 3.20, 12),
(15, 1, 'Chléb pšenično-žitný 1kg', 30, 'ks', 38.00, 12),
(15, 6, 'Ciabatta 200g', 40, 'ks', 22.00, 12),
(15, 10, 'Sourdough chléb 800g', 12, 'ks', 72.00, 12),
(16, 3, 'Houska s mákem', 50, 'ks', 8.50, 12),
(16, 5, 'Rohlík tukový', 80, 'ks', 3.20, 12),
(17, 13, 'Větrník', 25, 'ks', 32.00, 12),
(17, 17, 'Croissant s čokoládou', 20, 'ks', 28.00, 12),
(18, 26, 'Sendvič šunkový', 60, 'ks', 42.00, 21),
(18, 28, 'Wrap kuřecí', 40, 'ks', 68.00, 21),
(19, 5, 'Rohlík tukový', 90, 'ks', 3.20, 12),
(19, 2, 'Bageta francouzská 250g', 15, 'ks', 18.00, 12),
(20, 6, 'Ciabatta 200g', 30, 'ks', 22.00, 12),
(20, 34, 'Káva Lungo 0.5l', 20, 'ks', 45.00, 12),
(21, 1, 'Chléb pšenično-žitný 1kg', 20, 'ks', 38.00, 12),
(21, 3, 'Houska s mákem', 50, 'ks', 8.50, 12),
(21, 5, 'Rohlík tukový', 150, 'ks', 3.20, 12),
(22, 13, 'Větrník', 30, 'ks', 32.00, 12),
(22, 17, 'Croissant s čokoládou', 40, 'ks', 28.00, 12),
(23, 26, 'Sendvič šunkový', 50, 'ks', 42.00, 21),
(23, 28, 'Wrap kuřecí', 30, 'ks', 68.00, 21),
(24, 2, 'Bageta francouzská 250g', 30, 'ks', 18.00, 12),
(24, 5, 'Rohlík tukový', 80, 'ks', 3.20, 12),
(25, 1, 'Chléb pšenično-žitný 1kg', 30, 'ks', 38.00, 12),
(25, 13, 'Větrník', 20, 'ks', 32.00, 12),
(26, 26, 'Sendvič šunkový', 40, 'ks', 42.00, 21),
(26, 30, 'Bramborový salát 500g', 20, 'ks', 62.00, 21),
(27, 13, 'Větrník', 40, 'ks', 32.00, 12),
(27, 17, 'Croissant s čokoládou', 30, 'ks', 28.00, 12),
(28, 5, 'Rohlík tukový', 60, 'ks', 3.20, 12),
(28, 3, 'Houska s mákem', 40, 'ks', 8.50, 12),
(29, 13, 'Větrník', 25, 'ks', 32.00, 12),
(29, 17, 'Croissant s čokoládou', 20, 'ks', 28.00, 12),
(30, 26, 'Sendvič šunkový', 80, 'ks', 42.00, 21),
(30, 28, 'Wrap kuřecí', 50, 'ks', 68.00, 21),
(30, 34, 'Káva Lungo 0.5l', 40, 'ks', 45.00, 12);

-- ════════════════════════════════════════════════════════════
-- 📄 DODACÍ LISTY — 12× (každá doručená objednávka má dodací list)
-- ════════════════════════════════════════════════════════════
INSERT INTO dodaci_listy (id, cislo, objednavka_id, odberatel_id, misto_dodani_id, datum_vystaveni, datum_dodani, castka_celkem, fakturovano) VALUES
(1, 'DL-2026-001', 1, 1, 1, DATE_SUB(CURDATE(), INTERVAL 6 DAY), DATE_SUB(CURDATE(), INTERVAL 6 DAY), 1864.80, 1),
(2, 'DL-2026-002', 2, 2, 3, DATE_SUB(CURDATE(), INTERVAL 5 DAY), DATE_SUB(CURDATE(), INTERVAL 5 DAY), 2329.60, 1),
(3, 'DL-2026-003', 3, 3, 4, DATE_SUB(CURDATE(), INTERVAL 4 DAY), DATE_SUB(CURDATE(), INTERVAL 4 DAY), 5009.40, 1),
(4, 'DL-2026-004', 4, 4, NULL, DATE_SUB(CURDATE(), INTERVAL 3 DAY), DATE_SUB(CURDATE(), INTERVAL 3 DAY), 891.52, 1),
(5, 'DL-2026-005', 5, 5, NULL, DATE_SUB(CURDATE(), INTERVAL 3 DAY), DATE_SUB(CURDATE(), INTERVAL 3 DAY), 1019.20, 1),
(6, 'DL-2026-006', 6, 6, 7, DATE_SUB(CURDATE(), INTERVAL 2 DAY), DATE_SUB(CURDATE(), INTERVAL 2 DAY), 10195.60, 1),
(7, 'DL-2026-007', 7, 7, NULL, DATE_SUB(CURDATE(), INTERVAL 2 DAY), DATE_SUB(CURDATE(), INTERVAL 2 DAY), 644.00, 1),
(8, 'DL-2026-008', 8, 8, NULL, DATE_SUB(CURDATE(), INTERVAL 2 DAY), DATE_SUB(CURDATE(), INTERVAL 2 DAY), 772.80, 1),
(9, 'DL-2026-009', 9, 9, 8, DATE_SUB(CURDATE(), INTERVAL 1 DAY), DATE_SUB(CURDATE(), INTERVAL 1 DAY), 1870.40, 1),
(10, 'DL-2026-010', 10, 10, NULL, DATE_SUB(CURDATE(), INTERVAL 1 DAY), DATE_SUB(CURDATE(), INTERVAL 1 DAY), 1993.60, 0),
(11, 'DL-2026-011', 11, 11, NULL, DATE_SUB(CURDATE(), INTERVAL 1 DAY), DATE_SUB(CURDATE(), INTERVAL 1 DAY), 3533.20, 0),
(12, 'DL-2026-012', 12, 12, NULL, DATE_SUB(CURDATE(), INTERVAL 1 DAY), DATE_SUB(CURDATE(), INTERVAL 1 DAY), 2374.40, 0);

-- ── Dodací listy — položky ──
INSERT INTO dodaci_list_polozky (dodaci_list_id, vyrobek_id, vyrobek_nazev, mnozstvi, jednotka, cena_bez_dph, sazba_dph) VALUES
(1, 1, 'Chléb pšenično-žitný 1kg', 20, 'ks', 38.00, 12),
(1, 3, 'Houska s mákem', 50, 'ks', 8.50, 12),
(1, 5, 'Rohlík tukový', 150, 'ks', 3.20, 12),
(2, 13, 'Větrník', 30, 'ks', 32.00, 12),
(2, 17, 'Croissant s čokoládou', 40, 'ks', 28.00, 12),
(3, 26, 'Sendvič šunkový', 50, 'ks', 42.00, 21),
(3, 28, 'Wrap kuřecí', 30, 'ks', 68.00, 21),
(4, 2, 'Bageta francouzská 250g', 30, 'ks', 18.00, 12),
(4, 5, 'Rohlík tukový', 80, 'ks', 3.20, 12),
(5, 1, 'Chléb pšenično-žitný 1kg', 15, 'ks', 38.00, 12),
(5, 3, 'Houska s mákem', 40, 'ks', 8.50, 12),
(6, 26, 'Sendvič šunkový', 80, 'ks', 42.00, 21),
(6, 28, 'Wrap kuřecí', 50, 'ks', 68.00, 21),
(6, 34, 'Káva Lungo 0.5l', 40, 'ks', 45.00, 12),
(7, 5, 'Rohlík tukový', 100, 'ks', 3.20, 12),
(7, 3, 'Houska s mákem', 30, 'ks', 8.50, 12),
(8, 2, 'Bageta francouzská 250g', 20, 'ks', 18.00, 12),
(8, 6, 'Ciabatta 200g', 15, 'ks', 22.00, 12),
(9, 1, 'Chléb pšenično-žitný 1kg', 25, 'ks', 38.00, 12),
(9, 10, 'Sourdough chléb 800g', 10, 'ks', 72.00, 12),
(10, 1, 'Chléb pšenično-žitný 1kg', 30, 'ks', 38.00, 12),
(10, 13, 'Větrník', 20, 'ks', 32.00, 12),
(11, 26, 'Sendvič šunkový', 40, 'ks', 42.00, 21),
(11, 30, 'Bramborový salát 500g', 20, 'ks', 62.00, 21),
(12, 13, 'Větrník', 40, 'ks', 32.00, 12),
(12, 17, 'Croissant s čokoládou', 30, 'ks', 28.00, 12);

-- ════════════════════════════════════════════════════════════
-- 💰 FAKTURY — 9× (z doručených objednávek 1-9, každá s dodacím listem)
-- v2.9.104: snapshot sloupce odběratele se plní z řádku odberatele
-- ════════════════════════════════════════════════════════════
INSERT INTO faktury (id, cislo, odberatel_id, datum_vystaveni, datum_splatnosti, datum_dph, castka_bez_dph, castka_dph, castka_celkem, castka_uhrazeno, datum_uhrazeni, variabilni_symbol, misto_dodani_id, odb_nazev_snapshot, odb_ico_snapshot, odb_dic_snapshot, odb_ulice_snapshot, odb_mesto_snapshot, odb_psc_snapshot) VALUES
(1, 'FAK-2026-001', 1, DATE_SUB(CURDATE(), INTERVAL 5 DAY), DATE_ADD(CURDATE(), INTERVAL 9 DAY), DATE_SUB(CURDATE(), INTERVAL 5 DAY), 1665.00, 199.80, 1864.80, 1864.80, DATE_SUB(CURDATE(), INTERVAL 3 DAY), '20260001', 1, 'Penzion U Lípy', '12345001', 'CZ12345001', 'Lipová 12', 'Brno', '60200'),
(2, 'FAK-2026-002', 2, DATE_SUB(CURDATE(), INTERVAL 4 DAY), DATE_ADD(CURDATE(), INTERVAL 10 DAY), DATE_SUB(CURDATE(), INTERVAL 4 DAY), 2080.00, 249.60, 2329.60, 2329.60, DATE_SUB(CURDATE(), INTERVAL 2 DAY), '20260002', 3, 'Restaurace Pod Lípou', '12345002', 'CZ12345002', 'Hlavní 24', 'Praha 5', '15000'),
(3, 'FAK-2026-003', 3, DATE_SUB(CURDATE(), INTERVAL 3 DAY), DATE_ADD(CURDATE(), INTERVAL 11 DAY), DATE_SUB(CURDATE(), INTERVAL 3 DAY), 4140.00, 869.40, 5009.40, 5009.40, DATE_SUB(CURDATE(), INTERVAL 1 DAY), '20260003', 4, 'Hotel Continental ****', '12345003', 'CZ12345003', 'Václavské 7', 'Praha 1', '11000'),
(4, 'FAK-2026-004', 4, DATE_SUB(CURDATE(), INTERVAL 2 DAY), DATE_ADD(CURDATE(), INTERVAL 12 DAY), DATE_SUB(CURDATE(), INTERVAL 2 DAY), 796.00, 95.52, 891.52, 891.52, CURDATE(), '20260004', NULL, 'Kavárna Sladký Sen', '12345004', 'CZ12345004', 'Masarykova 5', 'Brno', '60200'),
(5, 'FAK-2026-005', 5, DATE_SUB(CURDATE(), INTERVAL 2 DAY), DATE_ADD(CURDATE(), INTERVAL 12 DAY), DATE_SUB(CURDATE(), INTERVAL 2 DAY), 910.00, 109.20, 1019.20, 0.00, NULL, '20260005', NULL, 'Školní jídelna ZŠ Komenského', '12345005', 'CZ12345005', 'Komenského 8', 'Plzeň', '30100'),
(6, 'FAK-2026-006', 6, DATE_SUB(CURDATE(), INTERVAL 1 DAY), DATE_ADD(CURDATE(), INTERVAL 13 DAY), DATE_SUB(CURDATE(), INTERVAL 1 DAY), 8560.00, 1635.60, 10195.60, 0.00, NULL, '20260006', 7, 'Catering Premium s.r.o.', '12345006', 'CZ12345006', 'Průmyslová 3', 'Ostrava', '70200'),
(7, 'FAK-2026-007', 7, DATE_SUB(CURDATE(), INTERVAL 1 DAY), DATE_ADD(CURDATE(), INTERVAL 13 DAY), DATE_SUB(CURDATE(), INTERVAL 1 DAY), 575.00, 69.00, 644.00, 0.00, NULL, '20260007', NULL, 'Pivnice Na Kopečku', '12345007', 'CZ12345007', 'Kopcová 18', 'Liberec', '46001'),
(8, 'FAK-2026-008', 8, DATE_SUB(CURDATE(), INTERVAL 1 DAY), DATE_ADD(CURDATE(), INTERVAL 13 DAY), DATE_SUB(CURDATE(), INTERVAL 1 DAY), 690.00, 82.80, 772.80, 0.00, NULL, '20260008', NULL, 'Bistro Vegan Garden', '12345008', 'CZ12345008', 'Bezručova 11', 'Praha 6', '16000'),
(9, 'FAK-2026-009', 9, CURDATE(), DATE_ADD(CURDATE(), INTERVAL 14 DAY), CURDATE(), 1670.00, 200.40, 1870.40, 0.00, NULL, '20260009', 8, 'Pension Hluboká nad Vltavou', '12345009', 'CZ12345009', 'Zámecká 2', 'Hluboká', '37341');

-- ── Faktury — položky ──
INSERT INTO faktura_polozky (faktura_id, vyrobek_id, vyrobek_nazev, mnozstvi, jednotka, cena_bez_dph, sazba_dph, poradi) VALUES
(1, 1, 'Chléb pšenično-žitný 1kg', 20, 'ks', 38.00, 12, 1),
(1, 3, 'Houska s mákem', 50, 'ks', 8.50, 12, 2),
(1, 5, 'Rohlík tukový', 150, 'ks', 3.20, 12, 3),
(2, 13, 'Větrník', 30, 'ks', 32.00, 12, 1),
(2, 17, 'Croissant s čokoládou', 40, 'ks', 28.00, 12, 2),
(3, 26, 'Sendvič šunkový', 50, 'ks', 42.00, 21, 1),
(3, 28, 'Wrap kuřecí', 30, 'ks', 68.00, 21, 2),
(4, 2, 'Bageta francouzská 250g', 30, 'ks', 18.00, 12, 1),
(4, 5, 'Rohlík tukový', 80, 'ks', 3.20, 12, 2),
(5, 1, 'Chléb pšenično-žitný 1kg', 15, 'ks', 38.00, 12, 1),
(5, 3, 'Houska s mákem', 40, 'ks', 8.50, 12, 2),
(6, 26, 'Sendvič šunkový', 80, 'ks', 42.00, 21, 1),
(6, 28, 'Wrap kuřecí', 50, 'ks', 68.00, 21, 2),
(6, 34, 'Káva Lungo 0.5l', 40, 'ks', 45.00, 12, 3),
(7, 5, 'Rohlík tukový', 100, 'ks', 3.20, 12, 1),
(7, 3, 'Houska s mákem', 30, 'ks', 8.50, 12, 2),
(8, 2, 'Bageta francouzská 250g', 20, 'ks', 18.00, 12, 1),
(8, 6, 'Ciabatta 200g', 15, 'ks', 22.00, 12, 2),
(9, 1, 'Chléb pšenično-žitný 1kg', 25, 'ks', 38.00, 12, 1),
(9, 10, 'Sourdough chléb 800g', 10, 'ks', 72.00, 12, 2);

-- ── Vazba faktura ⇄ dodací list (každá faktura → svůj DL) ──
INSERT INTO faktury_dodaci_listy (faktura_id, dodaci_list_id) VALUES
(1, 1),
(2, 2),
(3, 3),
(4, 4),
(5, 5),
(6, 6),
(7, 7),
(8, 8),
(9, 9);

-- ════════════════════════════════════════════════════════════
-- 🌾 SUROVINY — 20× (sklad / kalkulace / HACCP)
-- ════════════════════════════════════════════════════════════
INSERT INTO suroviny (id, nazev, jednotka, alergen, cena_baleni, obsah_baleni, stock_aktualni, stock_minimalni, stock_cilove, sklad_stav, sklad_min, sklad_cil, aktivni) VALUES
(1, 'Mouka pšeničná hladká T530', 'kg', 'lepek', 18.50, 25.000, 180.000, 40.000, 250.000, 180.000, 40.000, 250.000, 1),
(2, 'Mouka chlebová T1050', 'kg', 'lepek', 17.90, 25.000, 220.000, 50.000, 300.000, 220.000, 50.000, 300.000, 1),
(3, 'Mouka žitná T960', 'kg', 'lepek', 19.40, 25.000, 95.000, 30.000, 150.000, 95.000, 30.000, 150.000, 1),
(4, 'Droždí čerstvé', 'kg', NULL, 42.00, 1.000, 14.500, 5.000, 20.000, 14.500, 5.000, 20.000, 1),
(5, 'Sůl jedlá', 'kg', NULL, 9.90, 25.000, 60.000, 10.000, 80.000, 60.000, 10.000, 80.000, 1),
(6, 'Cukr krystal', 'kg', NULL, 21.50, 50.000, 120.000, 20.000, 150.000, 120.000, 20.000, 150.000, 1),
(7, 'Cukr moučka', 'kg', NULL, 27.00, 25.000, 38.000, 10.000, 60.000, 38.000, 10.000, 60.000, 1),
(8, 'Máslo 82%', 'kg', 'mléko', 248.00, 5.000, 42.000, 12.000, 60.000, 42.000, 12.000, 60.000, 1),
(9, 'Mléko plnotučné 3,5%', 'l', 'mléko', 24.90, 1.000, 85.000, 30.000, 120.000, 85.000, 30.000, 120.000, 1),
(10, 'Vejce slepičí L', 'ks', 'vejce', 4.20, 360.000, 720.000, 240.000, 900.000, 720.000, 240.000, 900.000, 1),
(11, 'Olej slunečnicový', 'l', NULL, 38.00, 10.000, 46.000, 10.000, 60.000, 46.000, 10.000, 60.000, 1),
(12, 'Olivový olej extra virgin', 'l', NULL, 219.00, 5.000, 12.000, 4.000, 20.000, 12.000, 4.000, 20.000, 1),
(13, 'Kakao 100%', 'kg', NULL, 189.00, 5.000, 8.500, 3.000, 15.000, 8.500, 3.000, 15.000, 1),
(14, 'Čokoláda hořká 70%', 'kg', 'sója', 165.00, 5.000, 22.000, 8.000, 30.000, 22.000, 8.000, 30.000, 1),
(15, 'Mák modrý mletý', 'kg', 'mák', 98.00, 5.000, 17.000, 5.000, 25.000, 17.000, 5.000, 25.000, 1),
(16, 'Sezam loupaný', 'kg', 'sezam', 76.00, 5.000, 9.000, 3.000, 15.000, 9.000, 3.000, 15.000, 1),
(17, 'Šunka dušená výběrová', 'kg', NULL, 159.00, 3.000, 28.000, 10.000, 40.000, 28.000, 10.000, 40.000, 1),
(18, 'Kuřecí prsní řízek', 'kg', NULL, 149.00, 5.000, 34.000, 15.000, 50.000, 34.000, 15.000, 50.000, 1),
(19, 'Sýr Eidam 30%', 'kg', 'mléko', 119.00, 3.000, 19.000, 8.000, 30.000, 19.000, 8.000, 30.000, 1),
(20, 'Brambory varný typ B', 'kg', NULL, 14.50, 25.000, 140.000, 40.000, 200.000, 140.000, 40.000, 200.000, 1);

-- ════════════════════════════════════════════════════════════
-- 🧾 RECEPTURY — vazba výrobek ⇄ surovina (10 výrobků)
-- ════════════════════════════════════════════════════════════
INSERT INTO vyrobek_suroviny (vyrobek_id, surovina_id, mnozstvi, jednotka, poradi) VALUES
(1, 2, 0.620, 'kg', 1),
(1, 3, 0.180, 'kg', 2),
(1, 4, 0.018, 'kg', 3),
(1, 5, 0.016, 'kg', 4),
(2, 1, 0.170, 'kg', 1),
(2, 4, 0.006, 'kg', 2),
(2, 5, 0.004, 'kg', 3),
(3, 1, 0.052, 'kg', 1),
(3, 4, 0.002, 'kg', 2),
(3, 6, 0.005, 'kg', 3),
(3, 15, 0.004, 'kg', 4),
(5, 1, 0.034, 'kg', 1),
(5, 4, 0.001, 'kg', 2),
(5, 11, 0.003, 'l', 3),
(6, 1, 0.165, 'kg', 1),
(6, 4, 0.005, 'kg', 2),
(6, 12, 0.012, 'l', 3),
(6, 5, 0.004, 'kg', 4),
(10, 3, 0.480, 'kg', 1),
(10, 2, 0.260, 'kg', 2),
(10, 5, 0.014, 'kg', 3),
(13, 1, 0.040, 'kg', 1),
(13, 10, 1.000, 'ks', 2),
(13, 8, 0.030, 'kg', 3),
(13, 7, 0.020, 'kg', 4),
(17, 1, 0.045, 'kg', 1),
(17, 8, 0.022, 'kg', 2),
(17, 14, 0.018, 'kg', 3),
(17, 9, 0.020, 'l', 4),
(26, 1, 0.090, 'kg', 1),
(26, 17, 0.060, 'kg', 2),
(26, 8, 0.010, 'kg', 3),
(28, 1, 0.110, 'kg', 1),
(28, 18, 0.090, 'kg', 2),
(28, 19, 0.020, 'kg', 3);

-- ════════════════════════════════════════════════════════════
-- 🏭 VÝROBNÍ LISTY — 3× (plán výroby + položky)
-- ════════════════════════════════════════════════════════════
INSERT INTO vyrobni_listy (id, cislo, datum_vyroby, datum_dodani, stav, poznamka, created_by) VALUES
(1, 'VL-2026-001', DATE_SUB(CURDATE(), INTERVAL 6 DAY), DATE_SUB(CURDATE(), INTERVAL 6 DAY), 'dokonceno', 'Ranní šára pondělí', 'Demo Výroba'),
(2, 'VL-2026-002', DATE_SUB(CURDATE(), INTERVAL 4 DAY), DATE_SUB(CURDATE(), INTERVAL 4 DAY), 'dokonceno', 'Cukrářská výroba — catering', 'Demo Výroba'),
(3, 'VL-2026-003', CURDATE(), CURDATE(), 'rozpracovano', 'Dnešní plán výroby', 'Demo Výroba');

-- ── Výrobní listy — položky ──
INSERT INTO vyrobni_list_polozky (vyrobni_list_id, vyrobek_id, mnozstvi, vyrobeno) VALUES
(1, 1, 53.000, 53.000),
(1, 3, 110.000, 110.000),
(1, 5, 150.000, 150.000),
(2, 13, 70.000, 70.000),
(2, 17, 70.000, 68.000),
(3, 1, 48.000, 20.000),
(3, 6, 70.000, 30.000),
(3, 10, 12.000, 0.000);

-- ════════════════════════════════════════════════════════════
-- 🍽️ RESTAURACE POS — stoly, účtenky, položky, platby
-- ════════════════════════════════════════════════════════════
-- POS tabulky NEJSOU v api/_schema.sql (vytváří je admin_pos.php /
-- admin_tables.php při prvním načtení). Hodinový reset jede čisté SQL,
-- proto je tu zaručíme přes CREATE TABLE IF NOT EXISTS (kopie definic).
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS restaurant_pos_ucty (
    id INT AUTO_INCREMENT PRIMARY KEY,
    stul_id INT NOT NULL,
    otevreno_v DATETIME DEFAULT CURRENT_TIMESTAMP,
    zaplaceno_v DATETIME NULL,
    otevrel_id INT NULL,
    otevrel_jmeno VARCHAR(120) NULL,
    stav ENUM('open','awaiting_payment','paid','cancelled','merged','split') NOT NULL DEFAULT 'open',
    suma_kc DECIMAL(10,2) NOT NULL DEFAULT 0,
    suma_zaplaceno DECIMAL(10,2) NOT NULL DEFAULT 0,
    pocet_hostu INT DEFAULT 1,
    poznamka TEXT NULL,
    parent_ucet_id INT NULL,
    objednavka_typ ENUM('inhouse','takeaway','delivery') DEFAULT 'inhouse',
    cislo_dokladu VARCHAR(40) NULL,
    INDEX idx_stul (stul_id),
    INDEX idx_stav (stav),
    INDEX idx_otevreno (otevreno_v),
    FOREIGN KEY (stul_id) REFERENCES restaurant_tables(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS restaurant_pos_polozky (
    id INT AUTO_INCREMENT PRIMARY KEY,
    ucet_id INT NOT NULL,
    vyrobek_id INT NULL,
    nazev VARCHAR(200) NOT NULL,
    jednotkova_cena DECIMAL(10,2) NOT NULL,
    mnozstvi DECIMAL(8,2) NOT NULL DEFAULT 1,
    kategorie VARCHAR(60) NULL,
    kurz INT DEFAULT 1,
    cas_objednavky DATETIME DEFAULT CURRENT_TIMESTAMP,
    cas_vari_se DATETIME NULL,
    cas_pripraveno DATETIME NULL,
    cas_servirovano DATETIME NULL,
    stav ENUM('objednano','vari_se','hotovo','servirovano','storno') NOT NULL DEFAULT 'objednano',
    kuchyne_tisk TINYINT(1) DEFAULT 0,
    poznamka VARCHAR(300) NULL,
    objednal_kdo VARCHAR(120) NULL,
    zdroj ENUM('staff','qr','app') DEFAULT 'staff',
    INDEX idx_ucet (ucet_id),
    INDEX idx_stav (stav),
    INDEX idx_kurz (ucet_id, kurz),
    FOREIGN KEY (ucet_id) REFERENCES restaurant_pos_ucty(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS restaurant_pos_platby (
    id INT AUTO_INCREMENT PRIMARY KEY,
    ucet_id INT NOT NULL,
    castka DECIMAL(10,2) NOT NULL,
    zpusob ENUM('hotovost','karta','qr','online','poukaz','prevod') NOT NULL DEFAULT 'hotovost',
    zaplaceno_v DATETIME DEFAULT CURRENT_TIMESTAMP,
    doklad_cislo VARCHAR(40) NULL,
    poznamka VARCHAR(200) NULL,
    INDEX idx_ucet (ucet_id),
    FOREIGN KEY (ucet_id) REFERENCES restaurant_pos_ucty(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT INTO restaurant_tables (id, nazev, mist, sekce, tvar, aktivni) VALUES
(1, 'Stůl 1', 2, 'Lokál', 'round', 1),
(2, 'Stůl 2', 4, 'Lokál', 'square', 1),
(3, 'Stůl 3', 4, 'Lokál', 'square', 1),
(4, 'Stůl 4', 6, 'Salonek', 'rect', 1),
(5, 'Stůl 5', 2, 'Zahrádka', 'round', 1),
(6, 'Bar', 8, 'Bar', 'rect', 1);

INSERT INTO restaurant_pos_ucty (id, stul_id, otevreno_v, zaplaceno_v, otevrel_jmeno, stav, suma_kc, suma_zaplaceno, pocet_hostu, objednavka_typ, cislo_dokladu) VALUES
(1, 2, TIMESTAMP(DATE_SUB(CURDATE(), INTERVAL 1 DAY), '12:00:00'), TIMESTAMP(DATE_SUB(CURDATE(), INTERVAL 1 DAY), '13:00:00'), 'Demo Obsluha', 'paid', 303.66, 303.66, 3, 'inhouse', 'POS-DEMO-001'),
(2, 1, TIMESTAMP(DATE_SUB(CURDATE(), INTERVAL 1 DAY), '13:00:00'), TIMESTAMP(DATE_SUB(CURDATE(), INTERVAL 1 DAY), '14:00:00'), 'Demo Obsluha', 'paid', 236.24, 236.24, 2, 'inhouse', 'POS-DEMO-002'),
(3, 6, TIMESTAMP(DATE_SUB(CURDATE(), INTERVAL 1 DAY), '18:00:00'), TIMESTAMP(DATE_SUB(CURDATE(), INTERVAL 1 DAY), '19:00:00'), 'Demo Obsluha', 'paid', 398.72, 398.72, 4, 'inhouse', 'POS-DEMO-003'),
(4, 3, TIMESTAMP(DATE_SUB(CURDATE(), INTERVAL 1 DAY), '19:00:00'), TIMESTAMP(DATE_SUB(CURDATE(), INTERVAL 1 DAY), '20:00:00'), 'Demo Obsluha', 'paid', 656.14, 656.14, 5, 'inhouse', 'POS-DEMO-004'),
(5, 5, TIMESTAMP(CURDATE(), '09:00:00'), TIMESTAMP(CURDATE(), '10:00:00'), 'Demo Obsluha', 'paid', 163.52, 163.52, 2, 'takeaway', 'POS-DEMO-005'),
(6, 4, TIMESTAMP(CURDATE(), '11:00:00'), TIMESTAMP(CURDATE(), '12:00:00'), 'Demo Obsluha', 'paid', 948.54, 948.54, 6, 'inhouse', 'POS-DEMO-006'),
(7, 1, TIMESTAMP(CURDATE(), '12:00:00'), TIMESTAMP(CURDATE(), '13:00:00'), 'Demo Obsluha', 'paid', 78.40, 78.40, 1, 'takeaway', 'POS-DEMO-007'),
(8, 6, TIMESTAMP(CURDATE(), '14:00:00'), TIMESTAMP(CURDATE(), '15:00:00'), 'Demo Obsluha', 'paid', 258.72, 258.72, 3, 'inhouse', 'POS-DEMO-008'),
(9, 2, TIMESTAMP(CURDATE(), '16:00:00'), NULL, 'Demo Obsluha', 'open', 164.36, 0.00, 2, 'inhouse', NULL),
(10, 3, TIMESTAMP(CURDATE(), '18:00:00'), NULL, 'Demo Obsluha', 'open', 566.56, 0.00, 4, 'inhouse', NULL);

-- ── POS — položky účtenek ──
INSERT INTO restaurant_pos_polozky (ucet_id, vyrobek_id, nazev, jednotkova_cena, mnozstvi, kategorie, kurz, stav, zdroj) VALUES
(1, 26, 'Sendvič šunkový', 50.82, 3, 'Lahůdky', 1, 'servirovano', 'staff'),
(1, 34, 'Káva Lungo 0.5l', 50.40, 3, 'Nápoje', 1, 'servirovano', 'staff'),
(2, 28, 'Wrap kuřecí', 82.28, 2, 'Lahůdky', 1, 'servirovano', 'staff'),
(2, 13, 'Větrník', 35.84, 2, 'Cukrářské', 1, 'servirovano', 'staff'),
(3, 34, 'Káva Lungo 0.5l', 50.40, 4, 'Nápoje', 1, 'servirovano', 'staff'),
(3, 17, 'Croissant s čokoládou', 31.36, 4, 'Cukrářské', 1, 'servirovano', 'staff'),
(3, 13, 'Větrník', 35.84, 2, 'Cukrářské', 1, 'servirovano', 'staff'),
(4, 26, 'Sendvič šunkový', 50.82, 5, 'Lahůdky', 1, 'servirovano', 'staff'),
(4, 30, 'Bramborový salát 500g', 75.02, 2, 'Lahůdky', 1, 'servirovano', 'staff'),
(4, 34, 'Káva Lungo 0.5l', 50.40, 5, 'Nápoje', 1, 'servirovano', 'staff'),
(5, 17, 'Croissant s čokoládou', 31.36, 2, 'Cukrářské', 1, 'servirovano', 'staff'),
(5, 34, 'Káva Lungo 0.5l', 50.40, 2, 'Nápoje', 1, 'servirovano', 'staff'),
(6, 28, 'Wrap kuřecí', 82.28, 6, 'Lahůdky', 1, 'servirovano', 'staff'),
(6, 26, 'Sendvič šunkový', 50.82, 3, 'Lahůdky', 1, 'servirovano', 'staff'),
(6, 34, 'Káva Lungo 0.5l', 50.40, 6, 'Nápoje', 1, 'servirovano', 'staff'),
(7, 2, 'Bageta francouzská 250g', 20.16, 2, 'Pečivo', 1, 'servirovano', 'staff'),
(7, 3, 'Houska s mákem', 9.52, 4, 'Pečivo', 1, 'servirovano', 'staff'),
(8, 13, 'Větrník', 35.84, 3, 'Cukrářské', 1, 'servirovano', 'staff'),
(8, 34, 'Káva Lungo 0.5l', 50.40, 3, 'Nápoje', 1, 'servirovano', 'staff'),
(9, 26, 'Sendvič šunkový', 50.82, 2, 'Lahůdky', 1, 'objednano', 'staff'),
(9, 17, 'Croissant s čokoládou', 31.36, 2, 'Cukrářské', 1, 'objednano', 'staff'),
(10, 28, 'Wrap kuřecí', 82.28, 4, 'Lahůdky', 1, 'objednano', 'staff'),
(10, 34, 'Káva Lungo 0.5l', 50.40, 4, 'Nápoje', 1, 'objednano', 'staff'),
(10, 13, 'Větrník', 35.84, 1, 'Cukrářské', 1, 'objednano', 'staff');

-- ── POS — platby (jen zaplacené účtenky; součet = suma účtu) ──
INSERT INTO restaurant_pos_platby (ucet_id, castka, zpusob, zaplaceno_v, doklad_cislo) VALUES
(1, 182.20, 'karta', TIMESTAMP(DATE_SUB(CURDATE(), INTERVAL 1 DAY), '13:00:00'), 'POS-DEMO-001'),
(1, 121.46, 'hotovost', TIMESTAMP(DATE_SUB(CURDATE(), INTERVAL 1 DAY), '13:00:00'), 'POS-DEMO-001'),
(2, 236.24, 'karta', TIMESTAMP(DATE_SUB(CURDATE(), INTERVAL 1 DAY), '14:00:00'), 'POS-DEMO-002'),
(3, 398.72, 'hotovost', TIMESTAMP(DATE_SUB(CURDATE(), INTERVAL 1 DAY), '19:00:00'), 'POS-DEMO-003'),
(4, 656.14, 'karta', TIMESTAMP(DATE_SUB(CURDATE(), INTERVAL 1 DAY), '20:00:00'), 'POS-DEMO-004'),
(5, 163.52, 'hotovost', TIMESTAMP(CURDATE(), '10:00:00'), 'POS-DEMO-005'),
(6, 948.54, 'karta', TIMESTAMP(CURDATE(), '12:00:00'), 'POS-DEMO-006'),
(7, 78.40, 'qr', TIMESTAMP(CURDATE(), '13:00:00'), 'POS-DEMO-007'),
(8, 258.72, 'hotovost', TIMESTAMP(CURDATE(), '15:00:00'), 'POS-DEMO-008');

-- ════════════════════════════════════════════════════════════
-- 🔢 ČÍSELNÉ ŘADY — posledni = nejvyšší seedované číslo dokladu
-- v2.9.104: typ='FA' (kód volá dalsi_cislo(...,'FA',...)), predcisli s rokem
-- ════════════════════════════════════════════════════════════
INSERT INTO cislovani (typ, rok, posledni, predcisli) VALUES
('OBJ', YEAR(CURDATE()), 30, CONCAT('OBJ-', YEAR(CURDATE()), '-')),
('FA',  YEAR(CURDATE()), 9, CONCAT('FAK-', YEAR(CURDATE()), '-')),
('DL',  YEAR(CURDATE()), 12, CONCAT('DL-',  YEAR(CURDATE()), '-')),
('VL',  YEAR(CURDATE()), 3, CONCAT('VL-',  YEAR(CURDATE()), '-'));

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

