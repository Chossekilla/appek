<?php
/**
 * 🎬 DEMO DATA SEEDER — One-click "Naplnit ukázkovými daty".
 *
 * POST /api/admin_demo_seed.php?action=preview   → vrátí counts co se vytvoří
 * POST /api/admin_demo_seed.php?action=apply     → naplní DB (jen pokud je prázdná)
 * POST /api/admin_demo_seed.php?action=clear     → SMAŽE všechna demo data (jen super admin, opatrně!)
 *
 * Bezpečnostní pojistka: nikdy nepřepíše existující záznamy
 * (insertne jen pokud daný počet < threshold).
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/_admin_auth.php';
cors_headers();
require_super_admin();
header('Content-Type: application/json; charset=UTF-8');

$pdo = db();
$action = $_GET['action'] ?? '';

// ════════════════════════════════════════════════════════════
// DEMO DATA — sample sets
// ════════════════════════════════════════════════════════════

function demo_categories(): array {
    return [
        ['nazev' => 'Pečivo',           'ikona' => '🥖', 'poradi' => 1],
        ['nazev' => 'Chleby',           'ikona' => '🍞', 'poradi' => 2],
        ['nazev' => 'Sladké pečivo',    'ikona' => '🥐', 'poradi' => 3],
        ['nazev' => 'Sendviče & svačiny','ikona' => '🥪', 'poradi' => 4],
        ['nazev' => 'Nápoje',           'ikona' => '☕', 'poradi' => 5],
    ];
}

function demo_products(array $kategorieIds): array {
    return [
        ['nazev' => 'Rohlík klasik',           'cislo' => 'RK01', 'cena_bez_dph' => 2.50, 'hmotnost_g' => 50,  'kategorie' => 'Pečivo',           'dph' => 12],
        ['nazev' => 'Veka 500 g',              'cislo' => 'VK01', 'cena_bez_dph' => 22.00,'hmotnost_g' => 500, 'kategorie' => 'Pečivo',           'dph' => 12],
        ['nazev' => 'Bageta sezamová',         'cislo' => 'BS01', 'cena_bez_dph' => 35.00,'hmotnost_g' => 250, 'kategorie' => 'Pečivo',           'dph' => 12],
        ['nazev' => 'Chléb konzumní 1 kg',     'cislo' => 'CH01', 'cena_bez_dph' => 45.00,'hmotnost_g' => 1000,'kategorie' => 'Chleby',           'dph' => 12],
        ['nazev' => 'Žitný chléb 1 kg',        'cislo' => 'CH02', 'cena_bez_dph' => 55.00,'hmotnost_g' => 1000,'kategorie' => 'Chleby',           'dph' => 12],
        ['nazev' => 'Croissant máslový',       'cislo' => 'CR01', 'cena_bez_dph' => 28.00,'hmotnost_g' => 70,  'kategorie' => 'Sladké pečivo',    'dph' => 12],
        ['nazev' => 'Šáteček s tvarohem',      'cislo' => 'SP01', 'cena_bez_dph' => 18.00,'hmotnost_g' => 80,  'kategorie' => 'Sladké pečivo',    'dph' => 12],
        ['nazev' => 'Záviny ořechové',         'cislo' => 'SP02', 'cena_bez_dph' => 32.00,'hmotnost_g' => 90,  'kategorie' => 'Sladké pečivo',    'dph' => 12],
        ['nazev' => 'Sendvič šunka-sýr',       'cislo' => 'SE01', 'cena_bez_dph' => 65.00,'hmotnost_g' => 180, 'kategorie' => 'Sendviče & svačiny','dph' => 12],
        ['nazev' => 'Káva americano',          'cislo' => 'NA01', 'cena_bez_dph' => 35.00,'hmotnost_g' => null,'kategorie' => 'Nápoje',           'dph' => 12],
    ];
}

function demo_customers(): array {
    // 🆕 v2.0.77 — John Doe jako prvotřídní demo zákazník (univerzální placeholder).
    // Plné údaje včetně login_email/heslo pro B2B portal testing.
    return [
        [
            'cislo' => 'O0001', 'nazev' => 'John Doe s.r.o.',
            'ico' => '11111111', 'dic' => 'CZ11111111',
            'ulice' => 'Demo 1', 'mesto' => 'Praha', 'psc' => '11000',
            'email' => 'john.doe@example.com', 'telefon' => '+420 777 111 111',
            'typ' => 'bistro',
            'login_email' => 'odberatel@demo.cz', 'heslo' => 'demo1234',
            'is_primary_demo' => true,  // marker pro auto-create order/DL/faktury
        ],
        ['cislo' => 'O0002', 'nazev' => 'John Doe Bakery & Café',  'ico' => '22222222', 'mesto' => 'Brno',       'email' => 'bakery@johndoe.cz', 'typ' => 'kavarna'],
        ['cislo' => 'O0003', 'nazev' => 'John Doe Hotel',          'ico' => '33333333', 'mesto' => 'Olomouc',    'email' => 'hotel@johndoe.cz',  'typ' => 'hotel'],
        ['cislo' => 'O0004', 'nazev' => 'John Doe Bistro',         'ico' => '44444444', 'mesto' => 'Plzeň',      'email' => 'bistro@johndoe.cz', 'typ' => 'bistro'],
        ['cislo' => 'O0005', 'nazev' => 'John Doe Catering',       'ico' => '55555555', 'mesto' => 'České Budějovice', 'email' => 'catering@johndoe.cz', 'typ' => 'jidelna'],
    ];
}

function demo_suroviny(): array {
    // 🆕 v2.9.271 — Rozšířené demo s reálnými velkoobchodními cenami (CZ 2024-2026).
    // 🆕 v2.9.298 — nutri = reálné USDA / EU food database hodnoty NA 100 g/ml
    //   energie_kj, energie_kcal, tuky, tuky_nasycene, sacharidy, cukry, bilkoviny, sul
    return [
        // 🌾 Mouky a krupice
        ['nazev' => 'Mouka pšeničná hladká T530', 'jednotka' => 'g', 'cena_baleni' => 360, 'obsah_baleni' => 25000, 'alergen' => 'lepek', 'naskladnit_baleni' => 8, 'stock_min' => 5000,
         'nutri' => ['kj' => 1490, 'kcal' => 350, 'tuky' => 1.2, 'tuky_n' => 0.2, 'sach' => 73, 'cukry' => 1.0, 'bilk' => 10, 'sul' => 0.01]],
        ['nazev' => 'Mouka pšeničná chlebová T1050', 'jednotka' => 'g', 'cena_baleni' => 340, 'obsah_baleni' => 25000, 'alergen' => 'lepek', 'naskladnit_baleni' => 6, 'stock_min' => 5000,
         'nutri' => ['kj' => 1450, 'kcal' => 342, 'tuky' => 1.4, 'tuky_n' => 0.2, 'sach' => 70, 'cukry' => 1.5, 'bilk' => 11, 'sul' => 0.02]],
        ['nazev' => 'Mouka žitná chlebová T960', 'jednotka' => 'g', 'cena_baleni' => 380, 'obsah_baleni' => 25000, 'alergen' => 'lepek', 'naskladnit_baleni' => 4, 'stock_min' => 3000,
         'nutri' => ['kj' => 1400, 'kcal' => 330, 'tuky' => 1.5, 'tuky_n' => 0.2, 'sach' => 68, 'cukry' => 1.0, 'bilk' => 9, 'sul' => 0.02]],
        ['nazev' => 'Špaldová mouka', 'jednotka' => 'g', 'cena_baleni' => 880, 'obsah_baleni' => 25000, 'alergen' => 'lepek', 'naskladnit_baleni' => 2, 'stock_min' => 2000,
         'nutri' => ['kj' => 1470, 'kcal' => 347, 'tuky' => 2.4, 'tuky_n' => 0.4, 'sach' => 70, 'cukry' => 2.5, 'bilk' => 14, 'sul' => 0.02]],
        // 🧂 Sůl + droždí
        ['nazev' => 'Sůl jedlá', 'jednotka' => 'g', 'cena_baleni' => 65, 'obsah_baleni' => 25000, 'naskladnit_baleni' => 3, 'stock_min' => 2000,
         'nutri' => ['kj' => 0, 'kcal' => 0, 'tuky' => 0, 'tuky_n' => 0, 'sach' => 0, 'cukry' => 0, 'bilk' => 0, 'sul' => 99.9]],
        ['nazev' => 'Droždí čerstvé pekařské', 'jednotka' => 'g', 'cena_baleni' => 95, 'obsah_baleni' => 1000, 'naskladnit_baleni' => 15, 'stock_min' => 1000,
         'nutri' => ['kj' => 460, 'kcal' => 110, 'tuky' => 2, 'tuky_n' => 0.3, 'sach' => 12, 'cukry' => 0.5, 'bilk' => 14, 'sul' => 0.03]],
        ['nazev' => 'Kvásek žitný startér', 'jednotka' => 'g', 'cena_baleni' => 180, 'obsah_baleni' => 1000, 'naskladnit_baleni' => 5, 'stock_min' => 500,
         'nutri' => ['kj' => 870, 'kcal' => 205, 'tuky' => 1.0, 'tuky_n' => 0.2, 'sach' => 42, 'cukry' => 1.0, 'bilk' => 6, 'sul' => 0.02]],
        // 🧈 Tuky
        ['nazev' => 'Máslo selské 82%', 'jednotka' => 'g', 'cena_baleni' => 75, 'obsah_baleni' => 250, 'alergen' => 'mléko', 'naskladnit_baleni' => 40, 'stock_min' => 2000,
         'nutri' => ['kj' => 3060, 'kcal' => 744, 'tuky' => 82, 'tuky_n' => 56, 'sach' => 0.5, 'cukry' => 0.5, 'bilk' => 0.8, 'sul' => 0.02]],
        ['nazev' => 'Margarín pekařský', 'jednotka' => 'g', 'cena_baleni' => 470, 'obsah_baleni' => 10000, 'naskladnit_baleni' => 2, 'stock_min' => 3000,
         'nutri' => ['kj' => 2980, 'kcal' => 720, 'tuky' => 80, 'tuky_n' => 25, 'sach' => 0.5, 'cukry' => 0.3, 'bilk' => 0.2, 'sul' => 1.0]],
        ['nazev' => 'Olej slunečnicový', 'jednotka' => 'ml', 'cena_baleni' => 250, 'obsah_baleni' => 5000, 'naskladnit_baleni' => 4, 'stock_min' => 2000,
         'nutri' => ['kj' => 3700, 'kcal' => 900, 'tuky' => 100, 'tuky_n' => 11, 'sach' => 0, 'cukry' => 0, 'bilk' => 0, 'sul' => 0]],
        // 🥛 Mléčné
        ['nazev' => 'Mléko polotučné 1,5%', 'jednotka' => 'ml', 'cena_baleni' => 28, 'obsah_baleni' => 1000, 'alergen' => 'mléko', 'naskladnit_baleni' => 30, 'stock_min' => 5000,
         'nutri' => ['kj' => 200, 'kcal' => 48, 'tuky' => 1.5, 'tuky_n' => 1.0, 'sach' => 4.8, 'cukry' => 4.8, 'bilk' => 3.4, 'sul' => 0.1]],
        ['nazev' => 'Smetana 33% ke šlehání', 'jednotka' => 'ml', 'cena_baleni' => 65, 'obsah_baleni' => 1000, 'alergen' => 'mléko', 'naskladnit_baleni' => 10, 'stock_min' => 2000,
         'nutri' => ['kj' => 1350, 'kcal' => 326, 'tuky' => 33, 'tuky_n' => 22, 'sach' => 3.0, 'cukry' => 3.0, 'bilk' => 2.2, 'sul' => 0.07]],
        ['nazev' => 'Tvaroh měkký', 'jednotka' => 'g', 'cena_baleni' => 78, 'obsah_baleni' => 500, 'alergen' => 'mléko', 'naskladnit_baleni' => 20, 'stock_min' => 2000,
         'nutri' => ['kj' => 400, 'kcal' => 95, 'tuky' => 0.5, 'tuky_n' => 0.3, 'sach' => 3.5, 'cukry' => 3.5, 'bilk' => 18, 'sul' => 0.05]],
        ['nazev' => 'Sýr eidam', 'jednotka' => 'g', 'cena_baleni' => 220, 'obsah_baleni' => 1000, 'alergen' => 'mléko', 'naskladnit_baleni' => 5, 'stock_min' => 1000,
         'nutri' => ['kj' => 1490, 'kcal' => 358, 'tuky' => 28, 'tuky_n' => 18, 'sach' => 1.0, 'cukry' => 1.0, 'bilk' => 25, 'sul' => 1.7]],
        // 🥚 Vejce (per ks ≈ 60 g)
        ['nazev' => 'Vejce slepičí M (1 ks ≈ 60 g)', 'jednotka' => 'ks', 'cena_baleni' => 240, 'obsah_baleni' => 60, 'alergen' => 'vejce', 'naskladnit_baleni' => 8, 'stock_min' => 30,
         'nutri' => ['kj' => 615, 'kcal' => 147, 'tuky' => 10, 'tuky_n' => 3.1, 'sach' => 1.0, 'cukry' => 0.4, 'bilk' => 13, 'sul' => 0.4]],
        // 🍬 Cukry + med
        ['nazev' => 'Cukr krystal', 'jednotka' => 'g', 'cena_baleni' => 1290, 'obsah_baleni' => 50000, 'naskladnit_baleni' => 2, 'stock_min' => 5000,
         'nutri' => ['kj' => 1700, 'kcal' => 400, 'tuky' => 0, 'tuky_n' => 0, 'sach' => 100, 'cukry' => 100, 'bilk' => 0, 'sul' => 0]],
        ['nazev' => 'Cukr moučka',                  'jednotka' => 'g',  'cena_baleni' => 720, 'obsah_baleni' => 25000, 'naskladnit_baleni' => 2, 'stock_min' => 3000],
        ['nazev' => 'Cukr vanilkový',               'jednotka' => 'g',  'cena_baleni' => 95,  'obsah_baleni' => 1000,  'naskladnit_baleni' => 5, 'stock_min' => 500],
        ['nazev' => 'Med květový',                  'jednotka' => 'g',  'cena_baleni' => 1450,'obsah_baleni' => 5000,  'naskladnit_baleni' => 2, 'stock_min' => 1000],
        // 🥜 Semínka a ořechy
        ['nazev' => 'Sezamová semínka',             'jednotka' => 'g',  'cena_baleni' => 1850,'obsah_baleni' => 25000, 'alergen' => 'sezam',   'naskladnit_baleni' => 1, 'stock_min' => 3000],
        ['nazev' => 'Mák modrý',                    'jednotka' => 'g',  'cena_baleni' => 2950,'obsah_baleni' => 25000, 'naskladnit_baleni' => 1, 'stock_min' => 2000],
        ['nazev' => 'Slunečnicová semínka loupaná', 'jednotka' => 'g',  'cena_baleni' => 1450,'obsah_baleni' => 25000, 'naskladnit_baleni' => 1, 'stock_min' => 2000],
        ['nazev' => 'Vlašské ořechy',               'jednotka' => 'g',  'cena_baleni' => 2150,'obsah_baleni' => 5000,  'alergen' => 'ořechy',  'naskladnit_baleni' => 3, 'stock_min' => 1000],
        ['nazev' => 'Mandle plátky',                'jednotka' => 'g',  'cena_baleni' => 3150,'obsah_baleni' => 5000,  'alergen' => 'mandle, ořechy', 'naskladnit_baleni' => 2, 'stock_min' => 500],
        // 🍫 Sladké přísady
        ['nazev' => 'Čokoláda hořká 70%',           'jednotka' => 'g',  'cena_baleni' => 580, 'obsah_baleni' => 2500,  'alergen' => 'mléko (stopy)', 'naskladnit_baleni' => 4, 'stock_min' => 1000],
        ['nazev' => 'Kakao tmavé',                  'jednotka' => 'g',  'cena_baleni' => 380, 'obsah_baleni' => 1000,  'naskladnit_baleni' => 4, 'stock_min' => 500],
        ['nazev' => 'Rozinky',                      'jednotka' => 'g',  'cena_baleni' => 480, 'obsah_baleni' => 5000,  'naskladnit_baleni' => 2, 'stock_min' => 1000],
        // 🍎 Náplně + ovoce
        ['nazev' => 'Povidla švestková',            'jednotka' => 'g',  'cena_baleni' => 380, 'obsah_baleni' => 5000,  'naskladnit_baleni' => 3, 'stock_min' => 1500],
        ['nazev' => 'Marmeláda meruňková',          'jednotka' => 'g',  'cena_baleni' => 420, 'obsah_baleni' => 5000,  'naskladnit_baleni' => 2, 'stock_min' => 1500],
        // 🥪 Sendviče
        ['nazev' => 'Šunka dušená',                 'jednotka' => 'g',  'cena_baleni' => 380, 'obsah_baleni' => 1000,  'naskladnit_baleni' => 10, 'stock_min' => 2000],
        ['nazev' => 'Salát ledový',                 'jednotka' => 'g',  'cena_baleni' => 38,  'obsah_baleni' => 400,   'naskladnit_baleni' => 8, 'stock_min' => 1000],
        ['nazev' => 'Rajčata cherry',               'jednotka' => 'g',  'cena_baleni' => 95,  'obsah_baleni' => 500,   'naskladnit_baleni' => 6, 'stock_min' => 1000],
        // ☕ Nápoje
        ['nazev' => 'Káva zrnková 100% Arabica',    'jednotka' => 'g',  'cena_baleni' => 690, 'obsah_baleni' => 1000,  'naskladnit_baleni' => 6, 'stock_min' => 1000],
        // 🍞 Specialitky
        ['nazev' => 'Lněné semínko',                'jednotka' => 'g',  'cena_baleni' => 1250,'obsah_baleni' => 25000, 'naskladnit_baleni' => 1, 'stock_min' => 2000],
        ['nazev' => 'Skořice mletá',                'jednotka' => 'g',  'cena_baleni' => 480, 'obsah_baleni' => 1000,  'naskladnit_baleni' => 2, 'stock_min' => 300],
    ];
}

// 🆕 v2.9.271 — Recepty (vyrobek_suroviny) — klasické pekařské/cukrárnické receptury
// Mapování: cislo výrobku → array [{surovina_nazev, mnozstvi, jednotka, poznamka?}]
// Množství jsou per 1 ks výrobku. Pro 1kg chleba = 600g mouky (60% mouky v těstě).
function demo_recepty(): array {
    return [
        // 🥖 Rohlík klasik (50 g)
        'RK01' => [
            ['surovina' => 'Mouka pšeničná hladká T530', 'mnozstvi' => 30, 'jednotka' => 'g', 'poznamka' => '60% hmotnosti'],
            ['surovina' => 'Droždí čerstvé pekařské',    'mnozstvi' => 1,  'jednotka' => 'g'],
            ['surovina' => 'Sůl jedlá',                  'mnozstvi' => 0.5,'jednotka' => 'g'],
            ['surovina' => 'Olej slunečnicový',          'mnozstvi' => 1,  'jednotka' => 'ml'],
            ['surovina' => 'Cukr krystal',               'mnozstvi' => 0.3,'jednotka' => 'g'],
        ],
        // 🥖 Veka 500 g (60% mouky → 280g mouky)
        'VK01' => [
            ['surovina' => 'Mouka pšeničná hladká T530', 'mnozstvi' => 280, 'jednotka' => 'g'],
            ['surovina' => 'Droždí čerstvé pekařské',    'mnozstvi' => 8,   'jednotka' => 'g'],
            ['surovina' => 'Sůl jedlá',                  'mnozstvi' => 5,   'jednotka' => 'g'],
            ['surovina' => 'Olej slunečnicový',          'mnozstvi' => 8,   'jednotka' => 'ml'],
            ['surovina' => 'Cukr krystal',               'mnozstvi' => 3,   'jednotka' => 'g'],
        ],
        // 🥖 Bageta sezamová 250 g
        'BS01' => [
            ['surovina' => 'Mouka pšeničná hladká T530', 'mnozstvi' => 140, 'jednotka' => 'g'],
            ['surovina' => 'Droždí čerstvé pekařské',    'mnozstvi' => 4,   'jednotka' => 'g'],
            ['surovina' => 'Sůl jedlá',                  'mnozstvi' => 2.5, 'jednotka' => 'g'],
            ['surovina' => 'Sezamová semínka',           'mnozstvi' => 8,   'jednotka' => 'g', 'poznamka' => 'posypka navrch'],
            ['surovina' => 'Olej slunečnicový',          'mnozstvi' => 3,   'jednotka' => 'ml'],
        ],
        // 🍞 Chléb konzumní 1 kg
        'CH01' => [
            ['surovina' => 'Mouka pšeničná chlebová T1050', 'mnozstvi' => 600, 'jednotka' => 'g'],
            ['surovina' => 'Droždí čerstvé pekařské',    'mnozstvi' => 12,  'jednotka' => 'g'],
            ['surovina' => 'Sůl jedlá',                  'mnozstvi' => 18,  'jednotka' => 'g'],
            ['surovina' => 'Olej slunečnicový',          'mnozstvi' => 12,  'jednotka' => 'ml'],
        ],
        // 🍞 Žitný chléb 1 kg (kvásek)
        'CH02' => [
            ['surovina' => 'Mouka žitná chlebová T960',  'mnozstvi' => 500, 'jednotka' => 'g'],
            ['surovina' => 'Mouka pšeničná chlebová T1050', 'mnozstvi' => 100, 'jednotka' => 'g'],
            ['surovina' => 'Kvásek žitný startér',       'mnozstvi' => 50,  'jednotka' => 'g'],
            ['surovina' => 'Droždí čerstvé pekařské',    'mnozstvi' => 4,   'jednotka' => 'g'],
            ['surovina' => 'Sůl jedlá',                  'mnozstvi' => 18,  'jednotka' => 'g'],
        ],
        // 🥐 Croissant 70 g máslový
        'CR01' => [
            ['surovina' => 'Mouka pšeničná hladká T530', 'mnozstvi' => 35,  'jednotka' => 'g'],
            ['surovina' => 'Máslo selské 82%',           'mnozstvi' => 18,  'jednotka' => 'g', 'poznamka' => 'laminace - kvalita křehkosti'],
            ['surovina' => 'Mléko polotučné 1,5%',       'mnozstvi' => 12,  'jednotka' => 'ml'],
            ['surovina' => 'Droždí čerstvé pekařské',    'mnozstvi' => 1.5, 'jednotka' => 'g'],
            ['surovina' => 'Cukr krystal',               'mnozstvi' => 3,   'jednotka' => 'g'],
            ['surovina' => 'Vejce slepičí M (1 ks ≈ 60 g)', 'mnozstvi' => 0.05, 'jednotka' => 'ks', 'poznamka' => 'pomazávka navrch'],
        ],
        // 🥐 Šáteček s tvarohem 80 g
        'SP01' => [
            ['surovina' => 'Mouka pšeničná hladká T530', 'mnozstvi' => 35,  'jednotka' => 'g'],
            ['surovina' => 'Máslo selské 82%',           'mnozstvi' => 10,  'jednotka' => 'g'],
            ['surovina' => 'Vejce slepičí M (1 ks ≈ 60 g)', 'mnozstvi' => 0.1, 'jednotka' => 'ks'],
            ['surovina' => 'Tvaroh měkký',               'mnozstvi' => 20,  'jednotka' => 'g', 'poznamka' => 'náplň'],
            ['surovina' => 'Cukr krystal',               'mnozstvi' => 8,   'jednotka' => 'g'],
            ['surovina' => 'Cukr vanilkový',             'mnozstvi' => 1,   'jednotka' => 'g'],
        ],
        // 🥐 Záviny ořechové 90 g
        'SP02' => [
            ['surovina' => 'Mouka pšeničná hladká T530', 'mnozstvi' => 35,  'jednotka' => 'g'],
            ['surovina' => 'Máslo selské 82%',           'mnozstvi' => 12,  'jednotka' => 'g'],
            ['surovina' => 'Vlašské ořechy',             'mnozstvi' => 18,  'jednotka' => 'g', 'poznamka' => 'náplň mletá'],
            ['surovina' => 'Cukr krystal',               'mnozstvi' => 12,  'jednotka' => 'g'],
            ['surovina' => 'Vejce slepičí M (1 ks ≈ 60 g)', 'mnozstvi' => 0.1, 'jednotka' => 'ks'],
            ['surovina' => 'Skořice mletá',              'mnozstvi' => 0.5, 'jednotka' => 'g'],
            ['surovina' => 'Rozinky',                    'mnozstvi' => 5,   'jednotka' => 'g'],
        ],
        // 🥪 Sendvič šunka-sýr 180 g
        'SE01' => [
            ['surovina' => 'Mouka pšeničná hladká T530', 'mnozstvi' => 60,  'jednotka' => 'g', 'poznamka' => 'pečivo na sendvič'],
            ['surovina' => 'Droždí čerstvé pekařské',    'mnozstvi' => 2,   'jednotka' => 'g'],
            ['surovina' => 'Sůl jedlá',                  'mnozstvi' => 1,   'jednotka' => 'g'],
            ['surovina' => 'Máslo selské 82%',           'mnozstvi' => 10,  'jednotka' => 'g'],
            ['surovina' => 'Šunka dušená',               'mnozstvi' => 35,  'jednotka' => 'g'],
            ['surovina' => 'Sýr eidam',                  'mnozstvi' => 30,  'jednotka' => 'g'],
            ['surovina' => 'Salát ledový',               'mnozstvi' => 8,   'jednotka' => 'g'],
            ['surovina' => 'Rajčata cherry',             'mnozstvi' => 25,  'jednotka' => 'g'],
        ],
        // ☕ Káva americano (1 šálek)
        'NA01' => [
            ['surovina' => 'Káva zrnková 100% Arabica',  'mnozstvi' => 9,   'jednotka' => 'g', 'poznamka' => 'doppio dávka'],
        ],
    ];
}

// 🆕 v2.9.284 — POS PIN uživatelé (zaměstnanci kasy)
// Pro plný demo workflow POS — užívatelé s PIN přihlášením
function demo_pos_users(): array {
    return [
        ['email' => 'jarmila@demo.cz',   'jmeno' => 'Jarmila Nováková',  'role' => 'prodavac', 'pin' => '1234', 'pos_only' => 1, 'heslo' => 'demo1234'],
        ['email' => 'evzen@demo.cz',     'jmeno' => 'Evžen Procházka',   'role' => 'prodavac', 'pin' => '5678', 'pos_only' => 1, 'heslo' => 'demo1234'],
        ['email' => 'prodavac1@demo.cz', 'jmeno' => 'Prodavač 1',         'role' => 'pos',      'pin' => '0000', 'pos_only' => 1, 'heslo' => 'demo1234'],
        ['email' => 'vedouci@demo.cz',   'jmeno' => 'Karel Vedoucí',     'role' => 'admin',    'pin' => '9999', 'pos_only' => 0, 'heslo' => 'demo1234'],
    ];
}

// 🆕 v2.9.284 — Kurýrky pro Restaurace rozvoz
function demo_couriers(): array {
    return [
        ['jmeno' => 'Pavel Řidič',    'telefon' => '+420 601 111 222', 'vozidlo' => 'Scooter Honda', 'spz' => '1AB 1234', 'externi' => 0, 'externi_sluzba' => 'vlastni', 'barva' => '#10B981', 'ikona' => '🛵'],
        ['jmeno' => 'Michal Kurýr',   'telefon' => '+420 602 333 444', 'vozidlo' => 'E-kolo',         'spz' => null,        'externi' => 0, 'externi_sluzba' => 'vlastni', 'barva' => '#3B82F6', 'ikona' => '🚴'],
        ['jmeno' => 'Wolt (externí)', 'telefon' => null,                'vozidlo' => null,             'spz' => null,        'externi' => 1, 'externi_sluzba' => 'wolt',    'barva' => '#00C2E8', 'ikona' => '🌊', 'provize_pct' => 30],
        ['jmeno' => 'Bolt Food',      'telefon' => null,                'vozidlo' => null,             'spz' => null,        'externi' => 1, 'externi_sluzba' => 'bolt',    'barva' => '#34D186', 'ikona' => '⚡', 'provize_pct' => 28],
    ];
}

// 🆕 v2.9.284 — Cenové skupiny pro B2B slevy
function demo_cenove_skupiny(): array {
    return [
        ['nazev' => 'Restaurace',  'popis' => 'Restaurace, bistra (objednávky 2× týdně)', 'globalni_sleva_pct' => 5,  'minimum_obj_kc' => 500,  'splatnost_dni' => 14],
        ['nazev' => 'Hotely',      'popis' => 'Hotely a ubytovací zařízení (denní dodávky)', 'globalni_sleva_pct' => 8, 'minimum_obj_kc' => 1500, 'splatnost_dni' => 30],
        ['nazev' => 'Kavárny',     'popis' => 'Kavárny a cukrárny',                       'globalni_sleva_pct' => 3,  'minimum_obj_kc' => 300,  'splatnost_dni' => 14],
    ];
}

// 🆕 v2.9.284 — Místa dodání pro John Doe (multi-branch)
function demo_mista_dodani(): array {
    return [
        ['nazev' => 'Centrála — Praha',  'ulice' => 'Václavské náměstí 1', 'mesto' => 'Praha',       'psc' => '11000', 'kontaktni_osoba' => 'John Doe',         'telefon' => '+420 777 111 111', 'cas_dodani' => '07:00-09:00', 'vychozi' => 1],
        ['nazev' => 'Pobočka — Brno',    'ulice' => 'Náměstí Svobody 5',   'mesto' => 'Brno',         'psc' => '60200', 'kontaktni_osoba' => 'Jane Doe',        'telefon' => '+420 777 222 333', 'cas_dodani' => '06:30-08:30', 'vychozi' => 0],
        ['nazev' => 'Pobočka — Plzeň',   'ulice' => 'Hlavní 10',           'mesto' => 'Plzeň',        'psc' => '30100', 'kontaktni_osoba' => 'Jim Doe',         'telefon' => '+420 777 444 555', 'cas_dodani' => '07:30-09:30', 'vychozi' => 0],
    ];
}

// 🆕 v2.9.271 — Fixní náklady (alikvotně per 1 ks výrobku)
// Spočítáno průměrně pro malou pekárnu s denní produkcí ~500 ks pečiva.
function demo_fixni_naklady(): array {
    return [
        ['nazev' => '⚡ Energie pec (plyn + elektřina)',  'cena_kc' => 0.50],
        ['nazev' => '👷 Mzda pekař (alikvotně)',           'cena_kc' => 1.20],
        ['nazev' => '📦 Obal / sáček',                     'cena_kc' => 0.15],
        ['nazev' => '🏠 Nájem provozovny (alikvotně)',    'cena_kc' => 0.40],
        ['nazev' => '🧽 Úklid + hygiena',                  'cena_kc' => 0.10],
        ['nazev' => '🚛 Doprava na prodejnu',              'cena_kc' => 0.20],
    ];
}

// ════════════════════════════════════════════════════════════
// PREVIEW — co bude vytvořeno
// ════════════════════════════════════════════════════════════
if ($action === 'preview') {
    $stats = [];
    try {
        $stats['kategorie'] = (int) $pdo->query("SELECT COUNT(*) FROM kategorie_vyrobku")->fetchColumn();
        $stats['vyrobky']   = (int) $pdo->query("SELECT COUNT(*) FROM vyrobky")->fetchColumn();
        $stats['odberatele']= (int) $pdo->query("SELECT COUNT(*) FROM odberatele")->fetchColumn();
        $stats['suroviny']  = (int) $pdo->query("SELECT COUNT(*) FROM suroviny")->fetchColumn();
    } catch (Throwable $e) { $stats = ['error' => $e->getMessage()]; }

    json_response([
        'current'  => $stats,
        'will_add' => [
            'kategorie'    => count(demo_categories()),
            'vyrobky'      => count(demo_products([])),
            'odberatele'   => count(demo_customers()),
            'suroviny'     => count(demo_suroviny()),
            // 🆕 v2.0.77 — John Doe ekosystém (auto-create)
            'objednavky'   => 1,
            'dodaci_listy' => 1,
            'faktury'      => 1,
            '_john_doe'    => 'John Doe s.r.o. + objednávka + DL + faktura (komplet test data flow)',
            // 🆕 v2.9.271 — rozšířená demo
            'recepty'              => count(demo_recepty()),
            'naskladneno_polozek'  => count(array_filter(demo_suroviny(), fn($s) => ($s['naskladnit_baleni'] ?? 0) > 0)),
            'fixni_naklady_polozek' => count(demo_fixni_naklady()),
            'kalkulace_ulozeno'    => 5,
            // 🆕 v2.9.284 — full demo (Restaurace balíček, POS workflow)
            'pos_users'        => count(demo_pos_users()),
            'cenove_skupiny'   => count(demo_cenove_skupiny()),
            'mista_dodani'     => count(demo_mista_dodani()),
            'kuryrky'          => count(demo_couriers()),
            'stoly'            => 14,
            'kuchyne_stanice'  => 4,
            'historie_obj'     => 12,
            '_full_demo' => 'FULL DEMO: 35+ surovin · 10 receptů · 5 kalkulací · 12 obj historie · 4 POS users s PIN · 14 stolů + 2 zóny · 4 kuchyně stanice · 4 kurýrky · 3 cenové skupiny · 3 pobočky John Doe',
        ],
    ]);
}

// ════════════════════════════════════════════════════════════
// APPLY — naplň DB demo daty
// ════════════════════════════════════════════════════════════
if ($action === 'apply') {
    // 🆕 v2.9.271 — rozšířené demo: recepty, naskladnění, kalkulace, fixní náklady
    $stats = [
        'kategorie' => 0, 'vyrobky' => 0, 'odberatele' => 0, 'suroviny' => 0,
        'recepty' => 0, 'naskladneno_polozek' => 0, 'kalkulace_ulozeno' => 0,
        'fixni_naklady_polozek' => 0, 'errors' => [],
    ];

    $pdo->beginTransaction();
    try {
        // 1. Kategorie
        $katIds = [];
        foreach (demo_categories() as $k) {
            try {
                $cnt = $pdo->prepare("SELECT id FROM kategorie_vyrobku WHERE nazev = :n");
                $cnt->execute(['n' => $k['nazev']]);
                $existId = $cnt->fetchColumn();
                if ($existId) {
                    $katIds[$k['nazev']] = (int) $existId;
                    continue;
                }
                $cols = $pdo->query("SHOW COLUMNS FROM kategorie_vyrobku")->fetchAll(PDO::FETCH_COLUMN);
                $hasIkona = in_array('ikona', $cols, true);
                $hasPoradi = in_array('poradi', $cols, true);
                $sql = "INSERT INTO kategorie_vyrobku (nazev" . ($hasIkona ? ", ikona" : "") . ($hasPoradi ? ", poradi" : "") . ") VALUES (:n" . ($hasIkona ? ", :i" : "") . ($hasPoradi ? ", :p" : "") . ")";
                $params = ['n' => $k['nazev']];
                if ($hasIkona) $params['i'] = $k['ikona'];
                if ($hasPoradi) $params['p'] = $k['poradi'];
                $pdo->prepare($sql)->execute($params);
                $katIds[$k['nazev']] = (int) $pdo->lastInsertId();
                $stats['kategorie']++;
            } catch (Throwable $e) { $stats['errors'][] = "Kategorie {$k['nazev']}: " . $e->getMessage(); }
        }

        // 2. Jednotky default (kus) a sazba DPH 12% — pro nové výrobky
        $jId = (int) $pdo->query("SELECT id FROM jednotky LIMIT 1")->fetchColumn();
        if (!$jId) {
            try {
                $pdo->exec("INSERT INTO jednotky (kod, nazev) VALUES ('ks', 'kus')");
                $jId = (int) $pdo->lastInsertId();
            } catch (Throwable $e) { /* tabulka jednotky možná nemá tyto sloupce */ }
        }
        $sId = (int) $pdo->query("SELECT id FROM sazby_dph WHERE sazba = 12 LIMIT 1")->fetchColumn();
        if (!$sId) {
            try {
                $pdo->exec("INSERT INTO sazby_dph (sazba, nazev) VALUES (12, '12 %')");
                $sId = (int) $pdo->lastInsertId();
            } catch (Throwable $e) { /* ignore */ }
        }
        if (!$sId) $sId = (int) $pdo->query("SELECT id FROM sazby_dph LIMIT 1")->fetchColumn();

        // 3. Výrobky
        foreach (demo_products($katIds) as $p) {
            try {
                $cnt = $pdo->prepare("SELECT 1 FROM vyrobky WHERE cislo = :c OR nazev = :n");
                $cnt->execute(['c' => $p['cislo'], 'n' => $p['nazev']]);
                if ($cnt->fetchColumn()) continue;
                $katId = $katIds[$p['kategorie']] ?? null;
                $pdo->prepare("
                    INSERT INTO vyrobky (cislo, nazev, kategorie_id, cena_bez_dph, hmotnost_g, jednotka_id, sazba_dph_id, aktivni)
                    VALUES (:c, :n, :k, :cn, :h, :j, :s, 1)
                ")->execute([
                    'c' => $p['cislo'], 'n' => $p['nazev'], 'k' => $katId,
                    'cn' => $p['cena_bez_dph'], 'h' => $p['hmotnost_g'],
                    'j' => $jId, 's' => $sId,
                ]);
                $stats['vyrobky']++;
            } catch (Throwable $e) { $stats['errors'][] = "Výrobek {$p['nazev']}: " . $e->getMessage(); }
        }

        // 4. Odběratelé (s plnými údaji + login pro primárního John Doe)
        $johnDoeId = null;
        foreach (demo_customers() as $o) {
            try {
                $cnt = $pdo->prepare("SELECT id FROM odberatele WHERE cislo = :c OR nazev = :n");
                $cnt->execute(['c' => $o['cislo'], 'n' => $o['nazev']]);
                $existId = $cnt->fetchColumn();
                if ($existId) {
                    if (!empty($o['is_primary_demo'])) $johnDoeId = (int) $existId;
                    continue;
                }
                // Detekuj které sloupce tabulka má (defenzivně)
                $cols = $pdo->query("SHOW COLUMNS FROM odberatele")->fetchAll(PDO::FETCH_COLUMN);
                $colSet = array_flip($cols);

                $fields = ['cislo', 'nazev', 'ico', 'mesto', 'email', 'typ'];
                $values = [
                    'cislo' => $o['cislo'], 'nazev' => $o['nazev'], 'ico' => $o['ico'],
                    'mesto' => $o['mesto'], 'email' => $o['email'], 'typ' => $o['typ'],
                ];

                // Volitelně přidej další sloupce pokud existují v tabulce
                $optional = [
                    'dic' => $o['dic'] ?? null,
                    'ulice' => $o['ulice'] ?? null,
                    'psc' => $o['psc'] ?? null,
                    'telefon' => $o['telefon'] ?? null,
                    'login_email' => $o['login_email'] ?? null,
                    'splatnost_dni' => 14,
                ];
                foreach ($optional as $col => $val) {
                    if (isset($colSet[$col]) && $val !== null) {
                        $fields[] = $col;
                        $values[$col] = $val;
                    }
                }
                // Heslo (bcrypt) — pokud má login_email
                if (!empty($o['heslo']) && isset($colSet['heslo_hash']) && isset($colSet['login_email'])) {
                    $fields[] = 'heslo_hash';
                    $values['heslo_hash'] = password_hash($o['heslo'], PASSWORD_BCRYPT);
                }

                $cols_sql = implode(', ', $fields);
                $params_sql = ':' . implode(', :', $fields);
                $pdo->prepare("INSERT INTO odberatele ({$cols_sql}) VALUES ({$params_sql})")
                    ->execute($values);
                $newId = (int) $pdo->lastInsertId();
                if (!empty($o['is_primary_demo'])) $johnDoeId = $newId;
                $stats['odberatele']++;
            } catch (Throwable $e) { $stats['errors'][] = "Odběratel {$o['nazev']}: " . $e->getMessage(); }
        }

        // 🆕 v2.0.77 — Auto-create John Doe ekosystém: objednávka → DL → faktura
        $stats['objednavky'] = 0;
        $stats['dodaci_listy'] = 0;
        $stats['faktury'] = 0;
        if ($johnDoeId) {
            try {
                // Vyber 4 výrobky z DB pro John Doe objednávku
                $vyrobkyForOrder = $pdo->query("
                    SELECT v.id, v.nazev, v.cislo, v.cena_bez_dph, v.jednotka_id, v.sazba_dph_id,
                           j.kod AS jednotka_kod, s.sazba AS dph_sazba
                    FROM vyrobky v
                    LEFT JOIN jednotky j ON j.id = v.jednotka_id
                    LEFT JOIN sazby_dph s ON s.id = v.sazba_dph_id
                    WHERE v.aktivni = 1
                    ORDER BY v.id LIMIT 4
                ")->fetchAll();

                if (!empty($vyrobkyForOrder)) {
                    $dnes = date('Y-m-d');
                    $zitra = date('Y-m-d', strtotime('+1 day'));
                    $rok = date('Y');

                    // ─── 1. Objednávka ────────────────────────────
                    // 🐛 fix v2.9.182 — dalsi_cislo() místo hardcoded 'OBJ-2026-0001'.
                    $cisloObj = dalsi_cislo($pdo, 'OBJ', (int) $rok);
                    $cnt = $pdo->prepare("SELECT 1 FROM objednavky WHERE cislo = :c");
                    $cnt->execute(['c' => $cisloObj]);
                    if (!$cnt->fetchColumn()) {
                        // Mnozstvi randomly 2-12 per item
                        $polozkyData = [];
                        $bezDph = 0; $dphSum = 0;
                        foreach ($vyrobkyForOrder as $idx => $v) {
                            $mn = [3, 5, 7, 10][$idx % 4];
                            $cena = (float) $v['cena_bez_dph'];
                            $dph = (float) ($v['dph_sazba'] ?? 12);
                            $polozkyData[] = [
                                'vyrobek_id' => $v['id'], 'nazev' => $v['nazev'],
                                'mnozstvi' => $mn, 'jednotka' => $v['jednotka_kod'] ?? 'ks',
                                'cena_bez_dph' => $cena, 'sazba_dph' => $dph,
                            ];
                            $bezDph += $cena * $mn;
                            $dphSum += $cena * $mn * ($dph / 100);
                        }
                        $celkem = $bezDph + $dphSum;

                        $pdo->prepare("
                            INSERT INTO objednavky (cislo, typ, odberatel_id, datum_objednani, datum_dodani, stav, castka_bez_dph, castka_dph, castka_celkem, poznamka)
                            VALUES (:c, 'standard', :oid, :do, :dd, 'nova', :bdz, :dph, :ce, :pz)
                        ")->execute([
                            'c' => $cisloObj, 'oid' => $johnDoeId,
                            'do' => $dnes, 'dd' => $zitra,
                            'bdz' => round($bezDph, 2), 'dph' => round($dphSum, 2), 'ce' => round($celkem, 2),
                            'pz' => 'Ukázková objednávka pro John Doe (demo seed).',
                        ]);
                        $objId = (int) $pdo->lastInsertId();
                        foreach ($polozkyData as $p) {
                            $pdo->prepare("
                                INSERT INTO objednavky_polozky (objednavka_id, vyrobek_id, vyrobek_nazev, mnozstvi, jednotka, cena_bez_dph, sazba_dph)
                                VALUES (:oid, :vid, :vn, :mn, :j, :cb, :sd)
                            ")->execute([
                                'oid' => $objId, 'vid' => $p['vyrobek_id'], 'vn' => $p['nazev'],
                                'mn' => $p['mnozstvi'], 'j' => $p['jednotka'],
                                'cb' => $p['cena_bez_dph'], 'sd' => $p['sazba_dph'],
                            ]);
                        }
                        $stats['objednavky']++;

                        // ─── 2. Dodací list (z objednávky) ────────
                        // 🐛 fix v2.9.182 — použít dalsi_cislo() místo hardcoded
                        // 'DL-2026-0001'. Předtím seed kolidovalo s nově generovanými
                        // čísly (cislovani.posledni se zvyšuje při generaci, takže by
                        // dalsi_cislo() vrátil 'DL-2026-0002' a hardcoded by konflikt).
                        $cisloDl = dalsi_cislo($pdo, 'DL', (int) $rok);
                        $pdo->prepare("
                            INSERT INTO dodaci_listy (cislo, objednavka_id, odberatel_id, datum_vystaveni, datum_dodani, castka_celkem, poznamka)
                            VALUES (:c, :oid, :odb, :dv, :dd, :ce, :pz)
                        ")->execute([
                            'c' => $cisloDl, 'oid' => $objId, 'odb' => $johnDoeId,
                            'dv' => $dnes, 'dd' => $zitra, 'ce' => round($celkem, 2),
                            'pz' => 'Auto-vygenerováno z objednávky ' . $cisloObj . ' (demo seed).',
                        ]);
                        $dlId = (int) $pdo->lastInsertId();
                        foreach ($polozkyData as $p) {
                            $pdo->prepare("
                                INSERT INTO dodaci_list_polozky (dodaci_list_id, vyrobek_id, vyrobek_nazev, mnozstvi, jednotka, cena_bez_dph, sazba_dph)
                                VALUES (:dl, :vid, :vn, :mn, :j, :cb, :sd)
                            ")->execute([
                                'dl' => $dlId, 'vid' => $p['vyrobek_id'], 'vn' => $p['nazev'],
                                'mn' => $p['mnozstvi'], 'j' => $p['jednotka'],
                                'cb' => $p['cena_bez_dph'], 'sd' => $p['sazba_dph'],
                            ]);
                        }
                        $stats['dodaci_listy']++;

                        // ─── 3. Faktura (z DL) ────────────────────
                        // 🐛 fix v2.9.182 — dalsi_cislo() místo hardcoded 'FA-2026-0001'.
                        $cisloFa = dalsi_cislo($pdo, 'FA', (int) $rok);
                        $datumSplat = date('Y-m-d', strtotime('+14 days'));
                        $varSym = preg_replace('/\D/', '', $cisloFa); // VS = jen číslice z čísla
                        $pdo->prepare("
                            INSERT INTO faktury (cislo, odberatel_id, datum_vystaveni, datum_splatnosti, datum_dph, castka_bez_dph, castka_dph, castka_celkem, variabilni_symbol, poznamka)
                            VALUES (:c, :odb, :dv, :ds, :ddph, :bdz, :dph, :ce, :vs, :pz)
                        ")->execute([
                            'c' => $cisloFa, 'odb' => $johnDoeId,
                            'dv' => $dnes, 'ds' => $datumSplat, 'ddph' => $dnes,
                            'bdz' => round($bezDph, 2), 'dph' => round($dphSum, 2), 'ce' => round($celkem, 2),
                            'vs' => $varSym,
                            'pz' => 'Ukázková faktura pro John Doe — vystavena z dodacího listu ' . $cisloDl . ' (demo seed).',
                        ]);
                        $faId = (int) $pdo->lastInsertId();
                        foreach ($polozkyData as $idx => $p) {
                            $pdo->prepare("
                                INSERT INTO faktura_polozky (faktura_id, vyrobek_id, vyrobek_nazev, mnozstvi, jednotka, cena_bez_dph, sazba_dph, poradi)
                                VALUES (:fa, :vid, :vn, :mn, :j, :cb, :sd, :po)
                            ")->execute([
                                'fa' => $faId, 'vid' => $p['vyrobek_id'], 'vn' => $p['nazev'],
                                'mn' => $p['mnozstvi'], 'j' => $p['jednotka'],
                                'cb' => $p['cena_bez_dph'], 'sd' => $p['sazba_dph'], 'po' => $idx + 1,
                            ]);
                        }
                        // Link DL → Faktura
                        try {
                            $pdo->prepare("INSERT INTO faktury_dodaci_listy (faktura_id, dodaci_list_id) VALUES (:f, :d)")
                                ->execute(['f' => $faId, 'd' => $dlId]);
                            $pdo->prepare("UPDATE dodaci_listy SET fakturovano = 1 WHERE id = :id")
                                ->execute(['id' => $dlId]);
                        } catch (Throwable $e) { /* link table optional */ }
                        $stats['faktury']++;
                    }
                }
            } catch (Throwable $e) {
                $stats['errors'][] = 'John Doe ekosystém: ' . $e->getMessage();
            }
        }

        // 🆕 v2.9.276 — HISTORIE OBJEDNÁVEK pro grafy/sparkliny/top odběratele
        // Vytvoří 10-12 objednávek napříč posledních 14 dní, různí odběratelé, variabilní výrobky.
        // Idempotentní: pokud už je >=5 objednávek za posledních 14 dní, přeskočí.
        $stats['historie_objednavky'] = 0;
        $stats['historie_dl'] = 0;
        $stats['historie_faktury'] = 0;
        try {
            $cnt14 = (int) $pdo->query("
                SELECT COUNT(*) FROM objednavky
                WHERE datum_objednani >= DATE_SUB(CURDATE(), INTERVAL 14 DAY)
            ")->fetchColumn();

            if ($cnt14 < 5) {
                // Načti aktivní výrobky + odběratele
                $vyrobkyPool = $pdo->query("
                    SELECT v.id, v.nazev, v.cislo, v.cena_bez_dph, v.jednotka_id, v.sazba_dph_id,
                           j.kod AS jednotka_kod, s.sazba AS dph_sazba
                    FROM vyrobky v
                    LEFT JOIN jednotky j ON j.id = v.jednotka_id
                    LEFT JOIN sazby_dph s ON s.id = v.sazba_dph_id
                    WHERE v.aktivni = 1
                    ORDER BY v.id LIMIT 10
                ")->fetchAll();

                // 🆕 v2.9.295 — defenzivně detekuj zda `aktivni` sloupec existuje (může chybět ve staré DB)
                $odbColsList = $pdo->query("SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'odberatele'")->fetchAll(PDO::FETCH_COLUMN);
                $hasAktivni = in_array('aktivni', $odbColsList, true);
                $aktivniWhere = $hasAktivni ? "COALESCE(aktivni, 1) = 1 AND " : "";
                $odbStmt = $pdo->query("
                    SELECT id, nazev FROM odberatele
                    WHERE {$aktivniWhere} nazev != 'POS Walk-in'
                    ORDER BY id LIMIT 8
                ");
                $odberatelePool = $odbStmt->fetchAll();

                if (!empty($vyrobkyPool) && !empty($odberatelePool)) {
                    $rok = (int) date('Y');
                    // Distribuce: poslední 14 dnů, peak Po-Pá (přeskočit některé víkendy)
                    // Vytvoříme ~10 objednávek s mezerami pro reálnou křivku
                    $offsets = [13, 12, 10, 9, 8, 7, 5, 4, 3, 2, 1, 0]; // 12 dnů s daty
                    shuffle($vyrobkyPool); // pomocné — různé výrobky v každé objednávce

                    foreach ($offsets as $dayOffset) {
                        try {
                            $datum = date('Y-m-d', strtotime("-{$dayOffset} days"));
                            $datumDodani = date('Y-m-d', strtotime("-{$dayOffset} days +1 day"));

                            // Pro každý den 1 objednávka, ale občas 2 (peak)
                            $orderCount = ($dayOffset % 3 === 0) ? 2 : 1;

                            for ($oi = 0; $oi < $orderCount; $oi++) {
                                $odb = $odberatelePool[($dayOffset + $oi) % count($odberatelePool)];

                                // Pseudo-random výběr 3-5 výrobků
                                $startIdx = ($dayOffset * 2 + $oi) % count($vyrobkyPool);
                                $itemCount = 3 + ($dayOffset % 3); // 3-5 položek
                                $polozkyData = [];
                                $bezDph = 0.0; $dphSum = 0.0;
                                for ($i = 0; $i < $itemCount && $i < count($vyrobkyPool); $i++) {
                                    $v = $vyrobkyPool[($startIdx + $i) % count($vyrobkyPool)];
                                    $mn = [2, 3, 4, 5, 6, 8, 10, 12][($dayOffset + $i) % 8];
                                    $cena = (float) $v['cena_bez_dph'];
                                    $dph = (float) ($v['dph_sazba'] ?? 12);
                                    $polozkyData[] = [
                                        'vyrobek_id' => $v['id'], 'nazev' => $v['nazev'],
                                        'mnozstvi' => $mn, 'jednotka' => $v['jednotka_kod'] ?? 'ks',
                                        'cena_bez_dph' => $cena, 'sazba_dph' => $dph,
                                    ];
                                    $bezDph += $cena * $mn;
                                    $dphSum += $cena * $mn * ($dph / 100);
                                }
                                $celkem = round($bezDph + $dphSum, 2);

                                // Stav: starší jsou „dorucena"/„zaplacena", novější „nova"/„potvrzena"
                                $stav = $dayOffset > 7 ? 'dorucena' : ($dayOffset > 2 ? 'potvrzena' : 'nova');

                                $cisloObjH = dalsi_cislo($pdo, 'OBJ', $rok);
                                $pdo->prepare("
                                    INSERT INTO objednavky (cislo, typ, odberatel_id, datum_objednani, datum_dodani, stav, castka_bez_dph, castka_dph, castka_celkem, poznamka)
                                    VALUES (:c, 'standard', :oid, :do, :dd, :st, :bdz, :dph, :ce, :pz)
                                ")->execute([
                                    'c' => $cisloObjH, 'oid' => $odb['id'],
                                    'do' => $datum, 'dd' => $datumDodani, 'st' => $stav,
                                    'bdz' => round($bezDph, 2), 'dph' => round($dphSum, 2), 'ce' => $celkem,
                                    'pz' => '🎬 Demo historie — automaticky vygenerováno pro grafy.',
                                ]);
                                $objHId = (int) $pdo->lastInsertId();

                                foreach ($polozkyData as $p) {
                                    $pdo->prepare("
                                        INSERT INTO objednavky_polozky (objednavka_id, vyrobek_id, vyrobek_nazev, mnozstvi, jednotka, cena_bez_dph, sazba_dph)
                                        VALUES (:oid, :vid, :vn, :mn, :j, :cb, :sd)
                                    ")->execute([
                                        'oid' => $objHId, 'vid' => $p['vyrobek_id'], 'vn' => $p['nazev'],
                                        'mn' => $p['mnozstvi'], 'j' => $p['jednotka'],
                                        'cb' => $p['cena_bez_dph'], 'sd' => $p['sazba_dph'],
                                    ]);
                                }
                                $stats['historie_objednavky']++;

                                // Starší 4+ dny → vytvoř DL + fakturu (kompletní cyklus)
                                if ($dayOffset >= 4) {
                                    try {
                                        $cisloDlH = dalsi_cislo($pdo, 'DL', $rok);
                                        $pdo->prepare("
                                            INSERT INTO dodaci_listy (cislo, objednavka_id, odberatel_id, datum_vystaveni, datum_dodani, castka_celkem, poznamka)
                                            VALUES (:c, :oid, :odb, :dv, :dd, :ce, :pz)
                                        ")->execute([
                                            'c' => $cisloDlH, 'oid' => $objHId, 'odb' => $odb['id'],
                                            'dv' => $datumDodani, 'dd' => $datumDodani, 'ce' => $celkem,
                                            'pz' => 'Demo historie DL (z OBJ ' . $cisloObjH . ').',
                                        ]);
                                        $dlHId = (int) $pdo->lastInsertId();
                                        foreach ($polozkyData as $p) {
                                            $pdo->prepare("
                                                INSERT INTO dodaci_list_polozky (dodaci_list_id, vyrobek_id, vyrobek_nazev, mnozstvi, jednotka, cena_bez_dph, sazba_dph)
                                                VALUES (:dl, :vid, :vn, :mn, :j, :cb, :sd)
                                            ")->execute([
                                                'dl' => $dlHId, 'vid' => $p['vyrobek_id'], 'vn' => $p['nazev'],
                                                'mn' => $p['mnozstvi'], 'j' => $p['jednotka'],
                                                'cb' => $p['cena_bez_dph'], 'sd' => $p['sazba_dph'],
                                            ]);
                                        }
                                        $stats['historie_dl']++;

                                        // 2/3 dostanou fakturu
                                        if ($dayOffset >= 5 && ($dayOffset % 2 === 0)) {
                                            $cisloFaH = dalsi_cislo($pdo, 'FA', $rok);
                                            $varSymH = preg_replace('/\D/', '', $cisloFaH);
                                            $datumSplatH = date('Y-m-d', strtotime($datumDodani . ' +14 days'));
                                            $pdo->prepare("
                                                INSERT INTO faktury (cislo, odberatel_id, datum_vystaveni, datum_splatnosti, datum_dph, castka_bez_dph, castka_dph, castka_celkem, variabilni_symbol, poznamka)
                                                VALUES (:c, :odb, :dv, :ds, :ddph, :bdz, :dph, :ce, :vs, :pz)
                                            ")->execute([
                                                'c' => $cisloFaH, 'odb' => $odb['id'],
                                                'dv' => $datumDodani, 'ds' => $datumSplatH, 'ddph' => $datumDodani,
                                                'bdz' => round($bezDph, 2), 'dph' => round($dphSum, 2), 'ce' => $celkem,
                                                'vs' => $varSymH,
                                                'pz' => 'Demo historie faktura.',
                                            ]);
                                            $faHId = (int) $pdo->lastInsertId();
                                            foreach ($polozkyData as $idx => $p) {
                                                $pdo->prepare("
                                                    INSERT INTO faktura_polozky (faktura_id, vyrobek_id, vyrobek_nazev, mnozstvi, jednotka, cena_bez_dph, sazba_dph, poradi)
                                                    VALUES (:fa, :vid, :vn, :mn, :j, :cb, :sd, :po)
                                                ")->execute([
                                                    'fa' => $faHId, 'vid' => $p['vyrobek_id'], 'vn' => $p['nazev'],
                                                    'mn' => $p['mnozstvi'], 'j' => $p['jednotka'],
                                                    'cb' => $p['cena_bez_dph'], 'sd' => $p['sazba_dph'], 'po' => $idx + 1,
                                                ]);
                                            }
                                            $stats['historie_faktury']++;
                                        }
                                    } catch (Throwable $e) { /* DL/FA optional */ }
                                }
                            }
                        } catch (Throwable $e) {
                            $stats['errors'][] = "Historie den -{$dayOffset}: " . $e->getMessage();
                        }
                    }
                }
            }
        } catch (Throwable $e) {
            $stats['errors'][] = 'Historie objednávek: ' . $e->getMessage();
        }

        // 5. Suroviny + naskladnění
        // 🆕 v2.9.271 — bohatší demo: 30+ surovin s reálnými velkoobchodními cenami,
        // automatické naskladnění (sklad_pohyby_v2 nebo legacy suroviny.stock_aktualni)
        // 🆕 v2.9.273 — MERGE MODE: pokud surovina už existuje ale chybí naskladnění/stock_min,
        //              doplnit (nepřepisovat existující ceny/název). To umožní upgrade ze starší demo.
        $surovinaIdByName = []; // pro mapování názvů na ID (pro recepty)
        $stats['suroviny_doplneno_stock'] = 0;

        // 🆕 v2.9.300 — FORCE migrace stock + nutri sloupců (pokud chybí)
        // Předtím se spoléhalo na admin_suroviny.php auto-migrace, ale demo seed
        // může běžet PŘED prvním voláním admin_suroviny.php → sloupce chybí → naskladnění selže.
        $surCols = $pdo->query("SHOW COLUMNS FROM suroviny")->fetchAll(PDO::FETCH_COLUMN);
        $forceCols = [
            'stock_aktualni'  => 'DECIMAL(12,3) NOT NULL DEFAULT 0',
            'stock_minimalni' => 'DECIMAL(12,3) DEFAULT NULL',
            'stock_cilove'    => 'DECIMAL(12,3) DEFAULT NULL',
            'nutri_energie_kj'    => 'DECIMAL(8,1) DEFAULT NULL',
            'nutri_energie_kcal'  => 'DECIMAL(8,1) DEFAULT NULL',
            'nutri_tuky'          => 'DECIMAL(7,2) DEFAULT NULL',
            'nutri_tuky_nasycene' => 'DECIMAL(7,2) DEFAULT NULL',
            'nutri_sacharidy'     => 'DECIMAL(7,2) DEFAULT NULL',
            'nutri_cukry'         => 'DECIMAL(7,2) DEFAULT NULL',
            'nutri_bilkoviny'     => 'DECIMAL(7,2) DEFAULT NULL',
            'nutri_sul'           => 'DECIMAL(7,3) DEFAULT NULL',
            'slozeni'             => 'TEXT DEFAULT NULL',
            'slozeni_alergeny'    => 'VARCHAR(255) DEFAULT NULL',
        ];
        foreach ($forceCols as $col => $type) {
            if (!in_array($col, $surCols, true)) {
                try {
                    $pdo->exec("ALTER TABLE suroviny ADD COLUMN $col $type");
                    $surCols[] = $col;
                } catch (Throwable $e) { /* sloupec možná stejně existuje */ }
            }
        }
        $hasStockAkt = in_array('stock_aktualni', $surCols, true);
        $hasStockMin = in_array('stock_minimalni', $surCols, true);

        // Helper: zápis pohybu pro audit
        // 🆕 v2.9.277 — paralelní zápis do legacy sklad_pohyby + nové sklad_pohyby_v2
        //              + update sklad_polozky pro výchozí sklad SK01 (pokud existuje)
        $logPrijem = function(int $surId, float $mnozstvi, float $cenaJed, string $note) use ($pdo, &$stats) {
            try {
                // 1. Legacy sklad_pohyby (single warehouse, just surovina_id)
                $pdo->exec("
                    CREATE TABLE IF NOT EXISTS sklad_pohyby (
                        id INT AUTO_INCREMENT PRIMARY KEY,
                        surovina_id INT NOT NULL,
                        typ VARCHAR(20) NOT NULL,
                        mnozstvi DECIMAL(12,3) NOT NULL,
                        cena_za_jed DECIMAL(10,4) NULL,
                        poznamka VARCHAR(300) NULL,
                        kdo VARCHAR(120) NULL,
                        kdy DATETIME DEFAULT CURRENT_TIMESTAMP,
                        INDEX idx_sur (surovina_id),
                        INDEX idx_kdy (kdy)
                    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
                ");
                $pdo->prepare("
                    INSERT INTO sklad_pohyby (surovina_id, typ, mnozstvi, cena_za_jed, poznamka, kdo, kdy)
                    VALUES (:sid, 'prijem', :mn, :cj, :pz, 'Demo seed', NOW())
                ")->execute([
                    'sid' => $surId, 'mn' => $mnozstvi, 'cj' => $cenaJed, 'pz' => $note,
                ]);
                $stats['naskladneno_polozek']++;

                // 2. 🆕 v2.9.277 — Multi-warehouse: paralelní zápis do sklad_polozky + sklad_pohyby_v2
                try {
                    // Zajisti tabulky existují
                    $pdo->exec("
                        CREATE TABLE IF NOT EXISTS sklady (
                            id INT AUTO_INCREMENT PRIMARY KEY,
                            kod VARCHAR(20) UNIQUE,
                            nazev VARCHAR(120) NOT NULL,
                            typ VARCHAR(20) DEFAULT 'jiny',
                            aktivni TINYINT(1) DEFAULT 1,
                            poradi INT DEFAULT 0,
                            vytvoreno DATETIME DEFAULT CURRENT_TIMESTAMP
                        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
                    ");
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
                            UNIQUE KEY uk_sklad_item (sklad_id, item_typ, item_id)
                        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
                    ");
                    $pdo->exec("
                        CREATE TABLE IF NOT EXISTS sklad_pohyby_v2 (
                            id INT AUTO_INCREMENT PRIMARY KEY,
                            sklad_id INT NOT NULL,
                            sklad_id_cil INT NULL,
                            item_typ ENUM('surovina','vyrobek') NOT NULL,
                            item_id INT NOT NULL,
                            typ ENUM('prijem','vydej','inventura','korekce','presun') NOT NULL,
                            mnozstvi DECIMAL(12,3) NOT NULL,
                            stav_pred DECIMAL(12,3) NULL,
                            stav_po DECIMAL(12,3) NULL,
                            cena_za_jed DECIMAL(10,4) NULL,
                            poznamka VARCHAR(300) NULL,
                            kdo VARCHAR(120) NULL,
                            kdy DATETIME DEFAULT CURRENT_TIMESTAMP,
                            INDEX idx_sklad (sklad_id),
                            INDEX idx_kdy (kdy),
                            INDEX idx_item (item_typ, item_id)
                        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
                    ");

                    // Najdi/vytvoř výchozí sklad SK01
                    $sk01 = (int) $pdo->query("SELECT id FROM sklady WHERE kod = 'SK01' LIMIT 1")->fetchColumn();
                    if (!$sk01) {
                        $pdo->prepare("INSERT INTO sklady (kod, nazev, typ, aktivni, poradi) VALUES ('SK01', 'Hlavní sklad', 'suchy', 1, 0)")->execute();
                        $sk01 = (int) $pdo->lastInsertId();
                    }

                    // Aktualizuj sklad_polozky (UPSERT)
                    $polStmt = $pdo->prepare("SELECT id, stav FROM sklad_polozky WHERE sklad_id = :s AND item_typ = 'surovina' AND item_id = :i LIMIT 1");
                    $polStmt->execute(['s' => $sk01, 'i' => $surId]);
                    $pol = $polStmt->fetch();
                    if ($pol) {
                        $stavPred = (float) $pol['stav'];
                        $stavPo = $stavPred + $mnozstvi;
                        $pdo->prepare("UPDATE sklad_polozky SET stav = :s WHERE id = :id")->execute(['s' => $stavPo, 'id' => $pol['id']]);
                    } else {
                        $stavPred = 0;
                        $stavPo = $mnozstvi;
                        $pdo->prepare("INSERT INTO sklad_polozky (sklad_id, item_typ, item_id, stav) VALUES (:s, 'surovina', :i, :st)")
                            ->execute(['s' => $sk01, 'i' => $surId, 'st' => $stavPo]);
                    }

                    // Pohyb do v2 audit trailu
                    $pdo->prepare("
                        INSERT INTO sklad_pohyby_v2 (sklad_id, item_typ, item_id, typ, mnozstvi, stav_pred, stav_po, cena_za_jed, poznamka, kdo)
                        VALUES (:s, 'surovina', :i, 'prijem', :m, :sp, :sP, :c, :p, 'Demo seed')
                    ")->execute([
                        's' => $sk01, 'i' => $surId, 'm' => $mnozstvi,
                        'sp' => $stavPred, 'sP' => $stavPo, 'c' => $cenaJed, 'p' => $note,
                    ]);
                } catch (Throwable $e) {
                    // v2 multi-warehouse je optional — pokud schémá selže, neporušíme legacy
                }
            } catch (Throwable $e) { /* sklad_pohyby legacy not critical */ }
        };

        // 🆕 v2.9.283 — alias map pro merge mode (kratší názvy v staré DB)
        $surMergeAliases = [
            'Mouka pšeničná hladká T530'       => ['Mouka pšeničná hladká', 'Mouka hladká', 'Mouka pšeničná'],
            'Mouka pšeničná chlebová T1050'    => ['Mouka pšeničná chlebová', 'Mouka chlebová'],
            'Mouka žitná chlebová T960'        => ['Mouka žitná chlebová', 'Mouka žitná'],
            'Sůl jedlá'                         => ['Sůl'],
            'Droždí čerstvé pekařské'           => ['Droždí čerstvé', 'Droždí'],
            'Vejce slepičí M (1 ks ≈ 60 g)'    => ['Vejce slepičí', 'Vejce'],
            'Mléko polotučné 1,5%'              => ['Mléko polotučné', 'Mléko'],
            'Máslo selské 82%'                  => ['Máslo', 'Máslo selské'],
            'Cukr krystal'                      => ['Cukr'],
            'Káva zrnková 100% Arabica'         => ['Káva zrnková', 'Káva'],
            'Mák modrý'                         => ['Mák'],
        ];

        foreach (demo_suroviny() as $s) {
            try {
                $naskladnit = ((float) ($s['naskladnit_baleni'] ?? 0)) * ((float) ($s['obsah_baleni'] ?? 0));
                $stockMin   = (float) ($s['stock_min'] ?? 0);
                $cenaJed    = ((float) $s['cena_baleni']) / max(1, (float) $s['obsah_baleni']);

                // Existuje surovina? Pokud ano, MERGE chybějící data
                $selCols = "id" . ($hasStockAkt ? ", stock_aktualni" : "") . ($hasStockMin ? ", stock_minimalni" : "");
                $stmt = $pdo->prepare("SELECT {$selCols} FROM suroviny WHERE nazev = :n");
                $stmt->execute(['n' => $s['nazev']]);
                $existing = $stmt->fetch();

                // 🆕 v2.9.283 — Pokud přesný název nematchuje, zkus aliasy (např. "Mouka pšeničná hladká" → "Mouka pšeničná hladká T530")
                if (!$existing && isset($surMergeAliases[$s['nazev']])) {
                    foreach ($surMergeAliases[$s['nazev']] as $alias) {
                        $stmt->execute(['n' => $alias]);
                        $existing = $stmt->fetch();
                        if ($existing) break;
                    }
                }

                if ($existing) {
                    $existId = (int) $existing['id'];
                    $surovinaIdByName[$s['nazev']] = $existId;

                    // Merge: pokud chybí naskladnění → naskladnit; pokud chybí stock_min → doplnit
                    // 🆕 v2.9.283 — <= 0 místo === 0.0 (defenzivně proti float precision)
                    $sets = []; $params = ['id' => $existId];
                    $doplnitStock = $hasStockAkt && (!isset($existing['stock_aktualni']) || (float) $existing['stock_aktualni'] <= 0);
                    $doplnitMin   = $hasStockMin && (empty($existing['stock_minimalni']) || (float) $existing['stock_minimalni'] <= 0);

                    if ($doplnitStock && $naskladnit > 0) {
                        $sets[] = "stock_aktualni = :sa";
                        $params['sa'] = $naskladnit;
                    }
                    if ($doplnitMin && $stockMin > 0) {
                        $sets[] = "stock_minimalni = :sm";
                        $params['sm'] = $stockMin;
                    }
                    if ($sets) {
                        $pdo->prepare("UPDATE suroviny SET " . implode(', ', $sets) . " WHERE id = :id")->execute($params);
                        $stats['suroviny_doplneno_stock']++;
                        if ($doplnitStock && $naskladnit > 0) {
                            $logPrijem($existId, $naskladnit, $cenaJed,
                                '🎬 Demo doplnění zásob — ' . (int) $s['naskladnit_baleni'] . ' balení (' . $naskladnit . ' ' . $s['jednotka'] . ')');
                        }
                    }
                    continue;
                }

                // INSERT — nová surovina
                $fields  = ['nazev', 'jednotka', 'cena_baleni', 'obsah_baleni', 'alergen', 'aktivni'];
                $values  = [
                    'nazev' => $s['nazev'], 'jednotka' => $s['jednotka'],
                    'cena_baleni' => $s['cena_baleni'] ?? null, 'obsah_baleni' => $s['obsah_baleni'] ?? null,
                    'alergen' => $s['alergen'] ?? null, 'aktivni' => 1,
                ];
                if ($hasStockAkt) { $fields[] = 'stock_aktualni'; $values['stock_aktualni'] = $naskladnit; }
                if ($hasStockMin) { $fields[] = 'stock_minimalni'; $values['stock_minimalni'] = $stockMin; }

                // 🆕 v2.9.298 — nutriční hodnoty (na 100 g/ml) — jen pokud surovina má `nutri`
                if (!empty($s['nutri']) && is_array($s['nutri'])) {
                    $nutriMap = [
                        'kj' => 'nutri_energie_kj', 'kcal' => 'nutri_energie_kcal',
                        'tuky' => 'nutri_tuky', 'tuky_n' => 'nutri_tuky_nasycene',
                        'sach' => 'nutri_sacharidy', 'cukry' => 'nutri_cukry',
                        'bilk' => 'nutri_bilkoviny', 'sul' => 'nutri_sul',
                    ];
                    foreach ($nutriMap as $shortKey => $col) {
                        if (in_array($col, $surCols, true) && isset($s['nutri'][$shortKey])) {
                            $fields[] = $col;
                            $values[$col] = (float) $s['nutri'][$shortKey];
                        }
                    }
                }

                $colsSql = implode(', ', $fields);
                $paramSql = ':' . implode(', :', $fields);
                $pdo->prepare("INSERT INTO suroviny ({$colsSql}) VALUES ({$paramSql})")->execute($values);
                $newSurId = (int) $pdo->lastInsertId();
                $surovinaIdByName[$s['nazev']] = $newSurId;
                $stats['suroviny']++;

                if ($naskladnit > 0) {
                    $logPrijem($newSurId, $naskladnit, $cenaJed,
                        '🎬 Demo naskladnění — ' . (int) $s['naskladnit_baleni'] . ' balení (' . $naskladnit . ' ' . $s['jednotka'] . ')');
                }
            } catch (Throwable $e) { $stats['errors'][] = "Surovina {$s['nazev']}: " . $e->getMessage(); }
        }

        // 🆕 v2.9.273 — Backfill surovinaIdByName pro existující suroviny (recepty potřebují ID)
        // Pokud surovina existovala před seedem a nebyla v aktuálním foreach, načti ID z DB
        try {
            $allSur = $pdo->query("SELECT id, nazev FROM suroviny WHERE aktivni = 1")->fetchAll();
            foreach ($allSur as $row) {
                if (!isset($surovinaIdByName[$row['nazev']])) {
                    $surovinaIdByName[$row['nazev']] = (int) $row['id'];
                }
            }
        } catch (Throwable $e) {}

        // 🆕 v2.9.275 — Alias map pro fuzzy match (starší demo data nemají detail v názvech)
        // Klíč = názvy v demo_recepty(), hodnota = pole alternativ které mohou být v DB
        $surovinaAliases = [
            'Mouka pšeničná hladká T530'       => ['Mouka pšeničná hladká', 'Mouka hladká', 'Mouka pšeničná', 'Mouka'],
            'Mouka pšeničná chlebová T1050'    => ['Mouka pšeničná chlebová', 'Mouka chlebová', 'Mouka pšeničná hrubá'],
            'Mouka žitná chlebová T960'        => ['Mouka žitná chlebová', 'Mouka žitná'],
            'Sůl jedlá'                         => ['Sůl'],
            'Droždí čerstvé pekařské'           => ['Droždí čerstvé', 'Droždí'],
            'Kvásek žitný startér'              => ['Kvásek žitný', 'Kvásek'],
            'Máslo selské 82%'                  => ['Máslo', 'Máslo selské'],
            'Vejce slepičí M (1 ks ≈ 60 g)'    => ['Vejce slepičí', 'Vejce'],
            'Mléko polotučné 1,5%'              => ['Mléko polotučné', 'Mléko'],
            'Cukr krystal'                      => ['Cukr', 'Cukr krystal'],
            'Sezamová semínka'                  => ['Sezam', 'Sezamová semínka'],
            'Káva zrnková 100% Arabica'         => ['Káva zrnková', 'Káva'],
            'Tvaroh měkký'                      => ['Tvaroh'],
            'Sýr eidam'                         => ['Sýr', 'Eidam'],
            'Šunka dušená'                      => ['Šunka'],
            'Salát ledový'                      => ['Salát'],
            'Rajčata cherry'                    => ['Rajčata'],
            'Vlašské ořechy'                    => ['Ořechy vlašské', 'Vlašské ořechy'],
            'Skořice mletá'                     => ['Skořice'],
            'Mák modrý'                         => ['Mák'],
        ];

        // Helper: najdi surovina_id s fuzzy fallback (exact → alias → LIKE)
        $najdiSurId = function(string $name) use (&$surovinaIdByName, $surovinaAliases, $pdo): int {
            // 1. Exact match v hashmapě
            if (isset($surovinaIdByName[$name])) return $surovinaIdByName[$name];
            // 2. Aliasy — zkusit alternativy
            $alts = $surovinaAliases[$name] ?? [];
            foreach ($alts as $alt) {
                if (isset($surovinaIdByName[$alt])) {
                    $surovinaIdByName[$name] = $surovinaIdByName[$alt]; // cache
                    return $surovinaIdByName[$alt];
                }
            }
            // 3. DB exact lookup
            $st = $pdo->prepare("SELECT id FROM suroviny WHERE nazev = :n LIMIT 1");
            $st->execute(['n' => $name]);
            $id = (int) $st->fetchColumn();
            if ($id) { $surovinaIdByName[$name] = $id; return $id; }
            // 4. DB alias lookup
            foreach ($alts as $alt) {
                $st->execute(['n' => $alt]);
                $id = (int) $st->fetchColumn();
                if ($id) { $surovinaIdByName[$name] = $id; return $id; }
            }
            // 5. DB LIKE — částečný match (poslední pokus)
            $stLike = $pdo->prepare("SELECT id FROM suroviny WHERE nazev LIKE :n LIMIT 1");
            // Vezmi první 2 slova z názvu pro LIKE (např. "Mouka pšeničná")
            $words = preg_split('/\s+/', $name);
            $needle = implode(' ', array_slice($words, 0, 2));
            $stLike->execute(['n' => $needle . '%']);
            $id = (int) $stLike->fetchColumn();
            if ($id) { $surovinaIdByName[$name] = $id; }
            return $id;
        };

        // Helper: najdi výrobek_id (cislo PRVNÍ, fallback název)
        $najdiVyrobekId = function(string $cislo, ?string $altNazev) use ($pdo): int {
            $st = $pdo->prepare("SELECT id FROM vyrobky WHERE cislo = :c LIMIT 1");
            $st->execute(['c' => $cislo]);
            $id = (int) $st->fetchColumn();
            if ($id) return $id;
            if ($altNazev) {
                $st2 = $pdo->prepare("SELECT id FROM vyrobky WHERE nazev = :n LIMIT 1");
                $st2->execute(['n' => $altNazev]);
                $id = (int) $st2->fetchColumn();
                if ($id) return $id;
            }
            return 0;
        };

        // Build cislo→nazev mapu z demo_products pro fallback lookup
        $prodNazevByCislo = [];
        foreach (demo_products([]) as $p) $prodNazevByCislo[$p['cislo']] = $p['nazev'];

        // 🆕 v2.9.271 — 6. RECEPTY (vyrobek_suroviny) — pro každý výrobek z demo_products
        try {
            $pdo->exec("
                CREATE TABLE IF NOT EXISTS vyrobek_suroviny (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    vyrobek_id INT NOT NULL,
                    surovina_id INT NOT NULL,
                    mnozstvi DECIMAL(10,3) NOT NULL DEFAULT 0,
                    jednotka VARCHAR(20) DEFAULT 'g',
                    poradi INT DEFAULT 0,
                    poznamka VARCHAR(200) DEFAULT NULL,
                    UNIQUE KEY ux_vyr_sur (vyrobek_id, surovina_id),
                    INDEX idx_vs_surovina (surovina_id)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
            ");

            $recepty = demo_recepty();
            $stats['recepty_skip_nenalezen_vyrobek'] = 0;
            $stats['recepty_skip_nenalezena_surovina'] = []; // pole názvů které selhaly
            foreach ($recepty as $cislo => $polozky) {
                try {
                    // 🆕 v2.9.275 — lookup podle čísla NEBO názvu (fallback)
                    $altNazev = $prodNazevByCislo[$cislo] ?? null;
                    $vyrId = $najdiVyrobekId($cislo, $altNazev);
                    if (!$vyrId) {
                        $stats['recepty_skip_nenalezen_vyrobek']++;
                        continue;
                    }
                    // Pokud výrobek už recept má, přeskoč (nepřepisuj user-data)
                    $recCheck = $pdo->prepare("SELECT COUNT(*) FROM vyrobek_suroviny WHERE vyrobek_id = :v");
                    $recCheck->execute(['v' => $vyrId]);
                    if ((int) $recCheck->fetchColumn() > 0) continue;

                    $polozekVlozeno = 0;
                    foreach ($polozky as $i => $p) {
                        // 🆕 v2.9.275 — fuzzy lookup s aliasy
                        $surId = $najdiSurId($p['surovina']);
                        if (!$surId) {
                            $stats['recepty_skip_nenalezena_surovina'][] = $p['surovina'];
                            continue;
                        }
                        $pdo->prepare("
                            INSERT INTO vyrobek_suroviny (vyrobek_id, surovina_id, mnozstvi, jednotka, poradi, poznamka)
                            VALUES (:v, :s, :m, :j, :p, :pz)
                            ON DUPLICATE KEY UPDATE mnozstvi = VALUES(mnozstvi), jednotka = VALUES(jednotka)
                        ")->execute([
                            'v'  => $vyrId, 's' => $surId,
                            'm'  => (float) $p['mnozstvi'],
                            'j'  => $p['jednotka'],
                            'p'  => $i,
                            'pz' => $p['poznamka'] ?? null,
                        ]);
                        $polozekVlozeno++;
                    }
                    if ($polozekVlozeno > 0) $stats['recepty']++;
                } catch (Throwable $e) {
                    $stats['errors'][] = "Recept {$cislo}: " . $e->getMessage();
                }
            }
            // Dedupe seznam nenalezených surovin (uniq)
            $stats['recepty_skip_nenalezena_surovina'] = array_values(array_unique($stats['recepty_skip_nenalezena_surovina']));
        } catch (Throwable $e) {
            $stats['errors'][] = 'Recepty: ' . $e->getMessage();
        }

        // 🆕 v2.9.271 — 7. FIXNÍ NÁKLADY (do nastaveni)
        try {
            $existingFixni = $pdo->query("SELECT hodnota FROM nastaveni WHERE klic = 'naklady_polozky'")->fetchColumn();
            if (!$existingFixni || $existingFixni === '[]') {
                $fixni = demo_fixni_naklady();
                $pdo->prepare("
                    INSERT INTO nastaveni (klic, hodnota, popis) VALUES ('naklady_polozky', :v, 'Fixní náklady alikvotně per ks výrobku (demo seed)')
                    ON DUPLICATE KEY UPDATE hodnota = :v2
                ")->execute([
                    'v'  => json_encode($fixni, JSON_UNESCAPED_UNICODE),
                    'v2' => json_encode($fixni, JSON_UNESCAPED_UNICODE),
                ]);
                $stats['fixni_naklady_polozek'] = count($fixni);
            }
        } catch (Throwable $e) {
            $stats['errors'][] = 'Fixní náklady: ' . $e->getMessage();
        }

        // 🆕 v2.9.271 — 8. UloŽené KALKULACE pro top 5 výrobků (demo wow effect)
        try {
            $pdo->exec("
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
            ");

            $fixniNakladyArr = demo_fixni_naklady();
            $fixniNakladySum = array_sum(array_column($fixniNakladyArr, 'cena_kc'));

            $hotVyrobky = ['RK01', 'CH01', 'CH02', 'CR01', 'BS01']; // top 5 pro wow demo
            foreach ($hotVyrobky as $cisloV) {
                try {
                    // 🆕 v2.9.275 — lookup výrobku podle čísla NEBO názvu
                    $altNazev = $prodNazevByCislo[$cisloV] ?? null;
                    $vyrId = $najdiVyrobekId($cisloV, $altNazev);
                    if (!$vyrId) continue;
                    $vyrInfoStmt = $pdo->prepare("SELECT nazev, cena_bez_dph FROM vyrobky WHERE id = :id LIMIT 1");
                    $vyrInfoStmt->execute(['id' => $vyrId]);
                    $vyr = $vyrInfoStmt->fetch();
                    if (!$vyr) continue;

                    // Check duplikát kalkulace (pokud již existuje pro tento výrobek, skip)
                    $existsCheck = $pdo->prepare("SELECT COUNT(*) FROM kalkulace_historie WHERE vyrobek_id = :v");
                    $existsCheck->execute(['v' => $vyrId]);
                    if ((int) $existsCheck->fetchColumn() > 0) continue;

                    // Načti recept
                    $rec = demo_recepty()[$cisloV] ?? null;
                    if (!$rec) continue;

                    // Spočítej náklady na suroviny
                    $surovinySum = 0.0;
                    $recepturaData = [];
                    foreach ($rec as $r) {
                        // 🆕 v2.9.275 — fuzzy lookup s aliasy
                        $surId = $najdiSurId($r['surovina']);
                        if (!$surId) continue;
                        $surData = $pdo->prepare("SELECT nazev, jednotka, cena_baleni, obsah_baleni FROM suroviny WHERE id = :id");
                        $surData->execute(['id' => $surId]);
                        $sd = $surData->fetch();
                        if (!$sd) continue;
                        $cb = (float) $sd['cena_baleni'];
                        $ob = (float) $sd['obsah_baleni'];
                        $mn = (float) $r['mnozstvi'];
                        $cenaJed = ($cb > 0 && $ob > 0) ? ($cb / $ob) : 0;
                        $cenaLine = $cenaJed * $mn;
                        $surovinySum += $cenaLine;
                        $recepturaData[] = [
                            'surovina_id' => $surId,
                            'mnozstvi'    => $mn,
                            'jednotka'    => $r['jednotka'],
                            'poznamka'    => $r['poznamka'] ?? null,
                            '_snapshot'   => [
                                'nazev'        => $sd['nazev'],
                                'jednotka'     => $sd['jednotka'],
                                'cena_baleni'  => $cb,
                                'obsah_baleni' => $ob,
                            ],
                        ];
                    }

                    $celkemNaklady = $surovinySum + $fixniNakladySum;
                    $cenaProdej = (float) $vyr['cena_bez_dph'];
                    $cenaProdejSDph = $cenaProdej * 1.12; // 12% DPH (potraviny)

                    $kalkData = [
                        'receptura'     => $recepturaData,
                        'zdobeni'       => [],
                        'fixni_naklady' => $fixniNakladyArr,
                        'pocet_kusu'    => 1,
                        'vytvoreno_at'  => date('c'),
                    ];

                    $pdo->prepare("
                        INSERT INTO kalkulace_historie
                            (nazev, vyrobek_id, vyrobek_nazev_snapshot, data,
                             vyrobni_cena_per_kus, cena_prodej_bez_dph, cena_prodej_s_dph,
                             klonku_celkem, poznamka)
                        VALUES (:n, :vid, :vn, :d, :vcpk, :cpbdz, :cpsdz, 1, :pz)
                    ")->execute([
                        'n'    => 'Kalkulace ' . $vyr['nazev'] . ' (demo)',
                        'vid'  => $vyrId,
                        'vn'   => $vyr['nazev'],
                        'd'    => json_encode($kalkData, JSON_UNESCAPED_UNICODE),
                        'vcpk' => round($celkemNaklady, 4),
                        'cpbdz' => round($cenaProdej, 4),
                        'cpsdz' => round($cenaProdejSDph, 4),
                        'pz'   => sprintf(
                            'Suroviny: %.2f Kč + Fixní: %.2f Kč = %.2f Kč náklady. Prodej %.2f Kč bez DPH (marže %.0f%%). Demo seed.',
                            $surovinySum, $fixniNakladySum, $celkemNaklady,
                            $cenaProdej, $celkemNaklady > 0 ? (($cenaProdej - $celkemNaklady) / $celkemNaklady * 100) : 0
                        ),
                    ]);
                    $stats['kalkulace_ulozeno']++;
                } catch (Throwable $e) {
                    $stats['errors'][] = "Kalkulace {$cisloV}: " . $e->getMessage();
                }
            }
        } catch (Throwable $e) {
            $stats['errors'][] = 'Kalkulace history: ' . $e->getMessage();
        }

        // ═══════════════════════════════════════════════════════════════
        // 🆕 v2.9.284 — FULL DEMO: POS users, kurýrky, cenové skupiny,
        // místa dodání, restaurace stoly, kuchyně stanice
        // ═══════════════════════════════════════════════════════════════

        // 9. POS PIN USERS (4 zaměstnanci)
        $stats['pos_users'] = 0;
        try {
            // Ujisti se že schema podporuje PIN (idempotent migrace)
            $cols = $pdo->query("SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'admin_users'")->fetchAll(PDO::FETCH_COLUMN);
            if (!in_array('pin_hash', $cols, true)) {
                try { $pdo->exec("ALTER TABLE admin_users ADD COLUMN pin_hash VARCHAR(255) NULL"); } catch (Throwable $e) {}
            }
            if (!in_array('pos_only', $cols, true)) {
                try { $pdo->exec("ALTER TABLE admin_users ADD COLUMN pos_only TINYINT(1) NOT NULL DEFAULT 0"); } catch (Throwable $e) {}
            }
            foreach (demo_pos_users() as $u) {
                try {
                    $check = $pdo->prepare("SELECT id FROM admin_users WHERE email = :e");
                    $check->execute(['e' => $u['email']]);
                    if ($check->fetchColumn()) continue; // skip existing

                    $pdo->prepare("
                        INSERT INTO admin_users (email, jmeno, heslo_hash, role, pin_hash, pos_only, aktivni)
                        VALUES (:em, :j, :h, :r, :pin, :po, 1)
                    ")->execute([
                        'em'  => $u['email'],
                        'j'   => $u['jmeno'],
                        'h'   => password_hash($u['heslo'], PASSWORD_DEFAULT),
                        'r'   => $u['role'],
                        'pin' => password_hash($u['pin'], PASSWORD_BCRYPT, ['cost' => 8]),
                        'po'  => (int) $u['pos_only'],
                    ]);
                    $stats['pos_users']++;
                } catch (Throwable $e) { $stats['errors'][] = "POS user {$u['email']}: " . $e->getMessage(); }
            }
        } catch (Throwable $e) { $stats['errors'][] = 'POS users: ' . $e->getMessage(); }

        // 10. CENOVÉ SKUPINY (Restaurace 5%, Hotely 8%, Kavárny 3%)
        $stats['cenove_skupiny'] = 0;
        $skupinaIdByName = [];
        try {
            foreach (demo_cenove_skupiny() as $sk) {
                try {
                    $check = $pdo->prepare("SELECT id FROM cenove_skupiny WHERE nazev = :n");
                    $check->execute(['n' => $sk['nazev']]);
                    $existId = (int) $check->fetchColumn();
                    if ($existId) { $skupinaIdByName[$sk['nazev']] = $existId; continue; }

                    $pdo->prepare("
                        INSERT INTO cenove_skupiny (nazev, popis, globalni_sleva_pct, minimum_obj_kc, splatnost_dni, aktivni)
                        VALUES (:n, :p, :sl, :mn, :spl, 1)
                    ")->execute([
                        'n' => $sk['nazev'], 'p' => $sk['popis'],
                        'sl' => $sk['globalni_sleva_pct'], 'mn' => $sk['minimum_obj_kc'], 'spl' => $sk['splatnost_dni'],
                    ]);
                    $skupinaIdByName[$sk['nazev']] = (int) $pdo->lastInsertId();
                    $stats['cenove_skupiny']++;
                } catch (Throwable $e) { $stats['errors'][] = "Skupina {$sk['nazev']}: " . $e->getMessage(); }
            }
            // Přiřaď John Doe do skupiny Restaurace
            if ($johnDoeId && isset($skupinaIdByName['Restaurace'])) {
                $cols = $pdo->query("SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'odberatele'")->fetchAll(PDO::FETCH_COLUMN);
                if (in_array('cenova_skupina_id', $cols, true)) {
                    try { $pdo->prepare("UPDATE odberatele SET cenova_skupina_id = :s WHERE id = :id AND cenova_skupina_id IS NULL")
                          ->execute(['s' => $skupinaIdByName['Restaurace'], 'id' => $johnDoeId]); } catch (Throwable $e) {}
                }
            }
        } catch (Throwable $e) { $stats['errors'][] = 'Cenové skupiny: ' . $e->getMessage(); }

        // 11. MÍSTA DODÁNÍ pro John Doe (3 pobočky)
        $stats['mista_dodani'] = 0;
        try {
            if ($johnDoeId) {
                // 🆕 v2.9.295 — fix: byl tu dead code `$cnt = (int) $pdo->prepare(...)` → PDOStatement cast warning
                $cntStmt = $pdo->prepare("SELECT COUNT(*) FROM mista_dodani WHERE odberatel_id = :id");
                $cntStmt->execute(['id' => $johnDoeId]);
                if ((int) $cntStmt->fetchColumn() === 0) {
                    foreach (demo_mista_dodani() as $m) {
                        try {
                            $pdo->prepare("
                                INSERT INTO mista_dodani (odberatel_id, nazev, ulice, mesto, psc, kontaktni_osoba, telefon, cas_dodani, vychozi, aktivni)
                                VALUES (:oid, :n, :u, :me, :p, :ko, :t, :cd, :v, 1)
                            ")->execute([
                                'oid' => $johnDoeId,
                                'n' => $m['nazev'], 'u' => $m['ulice'], 'me' => $m['mesto'], 'p' => $m['psc'],
                                'ko' => $m['kontaktni_osoba'], 't' => $m['telefon'], 'cd' => $m['cas_dodani'],
                                'v' => (int) $m['vychozi'],
                            ]);
                            $stats['mista_dodani']++;
                        } catch (Throwable $e) { /* skip duplicate */ }
                    }
                }
            }
        } catch (Throwable $e) { $stats['errors'][] = 'Místa dodání: ' . $e->getMessage(); }

        // 12. KURÝRKY (Restaurace balíček) — 4 (2 vlastní + Wolt + Bolt)
        $stats['kuryrky'] = 0;
        try {
            // Zajisti tabulku couriers (idempotent migrace — replicate z admin_couriers.php)
            $pdo->exec("
                CREATE TABLE IF NOT EXISTS couriers (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    jmeno VARCHAR(150) NOT NULL,
                    telefon VARCHAR(50) NULL, email VARCHAR(150) NULL,
                    vozidlo VARCHAR(100) NULL, spz VARCHAR(20) NULL,
                    zona_obslazi VARCHAR(200) NULL,
                    provize_pct DECIMAL(5,2) NOT NULL DEFAULT 0,
                    externi TINYINT(1) NOT NULL DEFAULT 0,
                    externi_sluzba ENUM('wolt','bolt','dame_jidlo','foodora','vlastni','jiny') NOT NULL DEFAULT 'vlastni',
                    aktivni TINYINT(1) NOT NULL DEFAULT 1,
                    barva VARCHAR(20) DEFAULT '#10B981', ikona VARCHAR(10) DEFAULT '🛵',
                    poznamka TEXT NULL, created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                    INDEX idx_aktivni (aktivni)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
            ");
            foreach (demo_couriers() as $k) {
                try {
                    $check = $pdo->prepare("SELECT id FROM couriers WHERE jmeno = :j");
                    $check->execute(['j' => $k['jmeno']]);
                    if ($check->fetchColumn()) continue;

                    $pdo->prepare("
                        INSERT INTO couriers (jmeno, telefon, vozidlo, spz, externi, externi_sluzba, barva, ikona, provize_pct, aktivni)
                        VALUES (:j, :t, :v, :s, :ex, :es, :b, :ik, :p, 1)
                    ")->execute([
                        'j' => $k['jmeno'], 't' => $k['telefon'] ?? null, 'v' => $k['vozidlo'] ?? null, 's' => $k['spz'] ?? null,
                        'ex' => (int) $k['externi'], 'es' => $k['externi_sluzba'],
                        'b' => $k['barva'], 'ik' => $k['ikona'],
                        'p' => (float) ($k['provize_pct'] ?? 0),
                    ]);
                    $stats['kuryrky']++;
                } catch (Throwable $e) { $stats['errors'][] = "Kurýr {$k['jmeno']}: " . $e->getMessage(); }
            }
        } catch (Throwable $e) { $stats['errors'][] = 'Kurýrky: ' . $e->getMessage(); }

        // 13. RESTAURACE STOLY (apply_template 'pizzerie' — 14 stolů + 2 zóny)
        // Idempotentní: skipne se pokud už nějaké stoly existují
        $stats['stoly'] = 0;
        $stats['kuchyne_stanice'] = 0;
        try {
            // Ujisti se že restaurant_tables tabulka existuje
            $tableExists = (bool) $pdo->query("SHOW TABLES LIKE 'restaurant_tables'")->fetchColumn();
            if (!$tableExists) {
                $pdo->exec("
                    CREATE TABLE restaurant_tables (
                        id INT AUTO_INCREMENT PRIMARY KEY,
                        nazev VARCHAR(60) NOT NULL,
                        mist INT NOT NULL DEFAULT 2,
                        sekce VARCHAR(40) DEFAULT NULL,
                        x INT DEFAULT 0, y INT DEFAULT 0,
                        width INT NOT NULL DEFAULT 80, height INT NOT NULL DEFAULT 80,
                        tvar ENUM('round','square','rect') DEFAULT 'square',
                        zone_id INT NULL,
                        stav ENUM('free','reserved','occupied','cleaning','attention','disabled') NOT NULL DEFAULT 'free',
                        stav_od DATETIME NULL,
                        hostu_aktual INT DEFAULT 0,
                        rotace INT NOT NULL DEFAULT 0,
                        barva VARCHAR(12) DEFAULT NULL,
                        obsluhuje VARCHAR(80) DEFAULT NULL,
                        aktivni TINYINT(1) NOT NULL DEFAULT 1,
                        created_at DATETIME DEFAULT CURRENT_TIMESTAMP
                    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
                ");
            }
            $pdo->exec("
                CREATE TABLE IF NOT EXISTS restaurant_zones (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    nazev VARCHAR(60) NOT NULL,
                    ikona VARCHAR(8) DEFAULT '🍽️',
                    canvas_w INT DEFAULT 800, canvas_h INT DEFAULT 500,
                    bg_barva VARCHAR(12) DEFAULT '#FFFAF1',
                    sort_order INT DEFAULT 0,
                    aktivni TINYINT(1) DEFAULT 1,
                    created_at DATETIME DEFAULT CURRENT_TIMESTAMP
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
            ");

            $existingTables = (int) $pdo->query("SELECT COUNT(*) FROM restaurant_tables")->fetchColumn();
            if ($existingTables === 0) {
                // Insert zóny: Sál + Terasa
                $pdo->prepare("INSERT INTO restaurant_zones (nazev, ikona, canvas_w, canvas_h, sort_order) VALUES (:n, :i, :w, :h, :s)")
                    ->execute(['n' => 'Sál', 'i' => '🍽️', 'w' => 800, 'h' => 500, 's' => 0]);
                $salId = (int) $pdo->lastInsertId();
                $pdo->prepare("INSERT INTO restaurant_zones (nazev, ikona, canvas_w, canvas_h, sort_order) VALUES (:n, :i, :w, :h, :s)")
                    ->execute(['n' => 'Terasa', 'i' => '☀️', 'w' => 600, 'h' => 400, 's' => 1]);
                $terasaId = (int) $pdo->lastInsertId();

                // 8 stolů v sále + 4 na terase + bar + rodinný
                $demoTables = [
                    // Sál
                    ['S1', 'round',  2, 60,  60,  70, 70, $salId],
                    ['S2', 'round',  2, 200, 60,  70, 70, $salId],
                    ['S3', 'round',  2, 340, 60,  70, 70, $salId],
                    ['S4', 'round',  2, 480, 60,  70, 70, $salId],
                    ['S5', 'square', 4, 60,  200, 90, 90, $salId],
                    ['S6', 'square', 4, 220, 200, 90, 90, $salId],
                    ['S7', 'square', 4, 380, 200, 90, 90, $salId],
                    ['S8', 'square', 4, 540, 200, 90, 90, $salId],
                    ['🍕 Rodinný', 'rect', 8, 60, 360, 380, 80, $salId],
                    ['🍺 Bar', 'rect', 4, 480, 360, 240, 50, $salId],
                    // Terasa
                    ['T1', 'round', 2, 60,  60,  70, 70, $terasaId],
                    ['T2', 'round', 2, 200, 60,  70, 70, $terasaId],
                    ['T3', 'round', 2, 340, 60,  70, 70, $terasaId],
                    ['T4', 'square', 4, 60, 200, 90, 90, $terasaId],
                ];
                $tStmt = $pdo->prepare("
                    INSERT INTO restaurant_tables (nazev, mist, x, y, width, height, tvar, zone_id, stav, aktivni)
                    VALUES (:n, :m, :x, :y, :w, :h, :t, :z, 'free', 1)
                ");
                foreach ($demoTables as $t) {
                    $tStmt->execute([
                        'n' => $t[0], 't' => $t[1], 'm' => $t[2],
                        'x' => $t[3], 'y' => $t[4], 'w' => $t[5], 'h' => $t[6],
                        'z' => $t[7],
                    ]);
                    $stats['stoly']++;
                }
            }

            // KUCHYNĚ STANICE (4 — pec, studená, gril, bar)
            $pdo->exec("
                CREATE TABLE IF NOT EXISTS kitchen_stations (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    nazev VARCHAR(100) NOT NULL, ikona VARCHAR(10) NOT NULL DEFAULT '🔥',
                    max_paralelni INT NOT NULL DEFAULT 4,
                    aktivni TINYINT(1) NOT NULL DEFAULT 1, poradi INT NOT NULL DEFAULT 0,
                    barva VARCHAR(20) DEFAULT '#F59E0B'
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
            ");
            $existingStations = (int) $pdo->query("SELECT COUNT(*) FROM kitchen_stations")->fetchColumn();
            if ($existingStations === 0) {
                $stations = [
                    ['Pec / pizza',     '🔥', 4, 1, '#EF4444'],
                    ['Studená kuchyně', '🥗', 3, 2, '#10B981'],
                    ['Gril',            '🍖', 2, 3, '#F97316'],
                    ['Bar / nápoje',    '🍹', 6, 4, '#3B82F6'],
                ];
                $kStmt = $pdo->prepare("INSERT INTO kitchen_stations (nazev, ikona, max_paralelni, poradi, barva) VALUES (:n, :i, :m, :p, :b)");
                foreach ($stations as $st) {
                    $kStmt->execute(['n' => $st[0], 'i' => $st[1], 'm' => $st[2], 'p' => $st[3], 'b' => $st[4]]);
                    $stats['kuchyne_stanice']++;
                }
            }
        } catch (Throwable $e) { $stats['errors'][] = 'Restaurace stoly/kuchyně: ' . $e->getMessage(); }

        // 14. FLAGGED VÝROBKY — oblíbený, novinka, akce (pro pěkný katalog)
        try {
            $cols = $pdo->query("SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'vyrobky'")->fetchAll(PDO::FETCH_COLUMN);
            if (in_array('oblibeny', $cols, true)) {
                $pdo->exec("UPDATE vyrobky SET oblibeny = 1 WHERE cislo IN ('RK01', 'CH01', 'CR01') AND oblibeny = 0");
            }
            if (in_array('je_novinka', $cols, true)) {
                $pdo->exec("UPDATE vyrobky SET je_novinka = 1 WHERE cislo IN ('SP02', 'BS01') AND je_novinka = 0");
            }
        } catch (Throwable $e) { /* optional */ }

        // 🆕 v2.9.295 — DDL (CREATE TABLE) v MySQL dělá implicit commit, takže outer
        // commit/rollBack může selhat s "no active transaction". Bezpečný guard:
        if ($pdo->inTransaction()) $pdo->commit();
        json_response($stats);
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        json_error('Seed selhal: ' . $e->getMessage(), 500);
    }
}

if ($action === 'clear') {
    // POZOR: smaže VŠECHNA data (ne jen demo) — používat opatrně.
    // Vyžaduje explicitní confirm parametr.
    $d = json_input();
    if (($d['confirm'] ?? '') !== 'SMAZAT VSE') {
        json_error('Vyžadováno confirm="SMAZAT VSE"', 400);
    }
    try {
        $pdo->exec("SET FOREIGN_KEY_CHECKS=0");
        // 🆕 v2.9.297 — rozšířený clear: Restaurace balíček + demo POS users
        foreach ([
            'objednavky_polozky', 'objednavky', 'dodaci_list_polozky', 'dodaci_listy',
            'faktura_polozky', 'faktury', 'vyrobni_list_polozky', 'vyrobni_listy',
            'mista_dodani', 'cenove_skupiny_slevy', 'vyrobek_suroviny',
            'vyrobky', 'odberatele', 'suroviny', 'sklad_pohyby', 'kategorie_vyrobku',
            // 🆕 Restaurace balíček
            'cenove_skupiny',
            'couriers', 'courier_deliveries', 'courier_integrations',
            'restaurant_tables', 'restaurant_zones',
            'restaurant_pos_polozky', 'restaurant_pos_ucty', 'restaurant_pos_platby',
            'restaurant_qr_sessions', 'restaurant_qr_orders',
            'kitchen_queue', 'kitchen_stations', 'kitchen_settings',
            // 🆕 Multi-warehouse + calc + idempotency
            'sklady', 'sklad_polozky', 'sklad_pohyby_v2',
            'kalkulace_historie',
            'pos_idempotency',
            'floorplan_templates',
        ] as $t) {
            try { $pdo->exec("DELETE FROM `$t`"); } catch (Throwable $e) { /* ignore */ }
        }
        // Demo POS users (jen @demo.cz, neumaže reálné admin)
        try { $pdo->exec("DELETE FROM admin_users WHERE email LIKE '%@demo.cz' AND email != 'demo@appek.cz'"); } catch (Throwable $e) {}
        // Demo-related nastaveni klíče
        try { $pdo->exec("DELETE FROM nastaveni WHERE klic IN ('naklady_polozky', 'suroviny_kategorie', 'default_daily_capacity', 'pos_idemp_cleanup_at')"); } catch (Throwable $e) {}
        $pdo->exec("SET FOREIGN_KEY_CHECKS=1");
        json_response(['ok' => true, 'cleared' => true]);
    } catch (Throwable $e) {
        json_error('Clear selhal: ' . $e->getMessage(), 500);
    }
}

// 🆕 v2.9.290 — Seed recept pro JEDEN výrobek (na vyžádání z editVyrobek modal)
// POST ?action=seed_one_recipe { cislo: 'RK01' } → vytvoří/přepíše recept
if ($action === 'seed_one_recipe') {
    $d = json_input();
    $cislo = trim((string) ($d['cislo'] ?? ''));
    if (!$cislo) json_error('Chybí cislo výrobku', 400);

    $recepty = demo_recepty();
    if (!isset($recepty[$cislo])) {
        json_error("Pro výrobek {$cislo} není demo recept dostupný", 404);
    }

    // Najdi výrobek v DB (cislo nebo název)
    $vyrStmt = $pdo->prepare("SELECT id, nazev FROM vyrobky WHERE cislo = :c LIMIT 1");
    $vyrStmt->execute(['c' => $cislo]);
    $vyr = $vyrStmt->fetch();
    if (!$vyr) json_error("Výrobek s číslem {$cislo} nenalezen v DB", 404);
    $vyrId = (int) $vyr['id'];

    // Alias mapping pro fuzzy lookup surovin (sdílený s apply)
    $aliases = [
        'Mouka pšeničná hladká T530'       => ['Mouka pšeničná hladká', 'Mouka hladká', 'Mouka pšeničná', 'Mouka'],
        'Mouka pšeničná chlebová T1050'    => ['Mouka pšeničná chlebová', 'Mouka chlebová'],
        'Mouka žitná chlebová T960'        => ['Mouka žitná chlebová', 'Mouka žitná'],
        'Sůl jedlá'                         => ['Sůl'],
        'Droždí čerstvé pekařské'           => ['Droždí čerstvé', 'Droždí'],
        'Kvásek žitný startér'              => ['Kvásek žitný', 'Kvásek'],
        'Máslo selské 82%'                  => ['Máslo', 'Máslo selské'],
        'Vejce slepičí M (1 ks ≈ 60 g)'    => ['Vejce slepičí', 'Vejce'],
        'Mléko polotučné 1,5%'              => ['Mléko polotučné', 'Mléko'],
        'Cukr krystal'                      => ['Cukr', 'Cukr krystal'],
        'Sezamová semínka'                  => ['Sezam', 'Sezamová semínka'],
        'Káva zrnková 100% Arabica'         => ['Káva zrnková', 'Káva'],
        'Tvaroh měkký'                      => ['Tvaroh'],
        'Sýr eidam'                         => ['Sýr', 'Eidam'],
        'Šunka dušená'                      => ['Šunka'],
        'Salát ledový'                      => ['Salát'],
        'Rajčata cherry'                    => ['Rajčata'],
        'Vlašské ořechy'                    => ['Ořechy vlašské', 'Vlašské ořechy'],
        'Skořice mletá'                     => ['Skořice'],
        'Mák modrý'                         => ['Mák'],
    ];

    $najdiSur = function(string $name) use ($pdo, $aliases): int {
        $st = $pdo->prepare("SELECT id FROM suroviny WHERE nazev = :n LIMIT 1");
        $st->execute(['n' => $name]);
        $id = (int) $st->fetchColumn();
        if ($id) return $id;
        foreach ($aliases[$name] ?? [] as $alt) {
            $st->execute(['n' => $alt]);
            $id = (int) $st->fetchColumn();
            if ($id) return $id;
        }
        // LIKE první 2 slova
        $stLike = $pdo->prepare("SELECT id FROM suroviny WHERE nazev LIKE :n LIMIT 1");
        $words = preg_split('/\s+/', $name);
        $stLike->execute(['n' => implode(' ', array_slice($words, 0, 2)) . '%']);
        return (int) $stLike->fetchColumn();
    };

    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS vyrobek_suroviny (
            id INT AUTO_INCREMENT PRIMARY KEY,
            vyrobek_id INT NOT NULL, surovina_id INT NOT NULL,
            mnozstvi DECIMAL(10,3) NOT NULL DEFAULT 0,
            jednotka VARCHAR(20) DEFAULT 'g', poradi INT DEFAULT 0,
            poznamka VARCHAR(200) DEFAULT NULL,
            UNIQUE KEY ux_vyr_sur (vyrobek_id, surovina_id),
            INDEX idx_vs_surovina (surovina_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        $pdo->beginTransaction();
        // Smaž existing recept (replace mode)
        $pdo->prepare("DELETE FROM vyrobek_suroviny WHERE vyrobek_id = :v")->execute(['v' => $vyrId]);

        $vlozeno = 0;
        $nenalezeno = [];
        $ins = $pdo->prepare("
            INSERT INTO vyrobek_suroviny (vyrobek_id, surovina_id, mnozstvi, jednotka, poradi, poznamka)
            VALUES (:v, :s, :m, :j, :p, :pz)
        ");
        foreach ($recepty[$cislo] as $i => $p) {
            $surId = $najdiSur($p['surovina']);
            if (!$surId) { $nenalezeno[] = $p['surovina']; continue; }
            $ins->execute([
                'v' => $vyrId, 's' => $surId,
                'm' => (float) $p['mnozstvi'],
                'j' => $p['jednotka'],
                'p' => $i,
                'pz' => $p['poznamka'] ?? null,
            ]);
            $vlozeno++;
        }
        $pdo->commit();
        json_response([
            'ok' => true,
            'vyrobek' => $vyr['nazev'],
            'cislo' => $cislo,
            'vlozeno' => $vlozeno,
            'celkem_v_demo' => count($recepty[$cislo]),
            'nenalezeno' => $nenalezeno,
        ]);
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        json_error('Seed receptu selhal: ' . $e->getMessage(), 500);
    }
}

json_error('Neznámá akce (preview|apply|clear|seed_one_recipe)', 404);
