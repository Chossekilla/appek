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
    // Každá surovina má i stock_min (pro upozornění "doobjednat") a poradi pro sortování.
    // Naskladnění se počítá z `naskladnit_baleni` × `obsah_baleni`.
    return [
        // 🌾 Mouky a krupice (jednotka g, balení 25 kg = 25000 g)
        ['nazev' => 'Mouka pšeničná hladká T530', 'jednotka' => 'g',  'cena_baleni' => 360, 'obsah_baleni' => 25000, 'alergen' => 'lepek', 'naskladnit_baleni' => 8, 'stock_min' => 5000],
        ['nazev' => 'Mouka pšeničná chlebová T1050', 'jednotka' => 'g', 'cena_baleni' => 340, 'obsah_baleni' => 25000, 'alergen' => 'lepek', 'naskladnit_baleni' => 6, 'stock_min' => 5000],
        ['nazev' => 'Mouka žitná chlebová T960',   'jednotka' => 'g',  'cena_baleni' => 380, 'obsah_baleni' => 25000, 'alergen' => 'lepek', 'naskladnit_baleni' => 4, 'stock_min' => 3000],
        ['nazev' => 'Špaldová mouka',               'jednotka' => 'g',  'cena_baleni' => 880, 'obsah_baleni' => 25000, 'alergen' => 'lepek', 'naskladnit_baleni' => 2, 'stock_min' => 2000],
        // 🧂 Sůl + droždí
        ['nazev' => 'Sůl jedlá',                   'jednotka' => 'g',  'cena_baleni' => 65,  'obsah_baleni' => 25000, 'naskladnit_baleni' => 3, 'stock_min' => 2000],
        ['nazev' => 'Droždí čerstvé pekařské',     'jednotka' => 'g',  'cena_baleni' => 95,  'obsah_baleni' => 1000,  'naskladnit_baleni' => 15, 'stock_min' => 1000],
        ['nazev' => 'Kvásek žitný startér',         'jednotka' => 'g',  'cena_baleni' => 180, 'obsah_baleni' => 1000,  'naskladnit_baleni' => 5,  'stock_min' => 500],
        // 🧈 Tuky
        ['nazev' => 'Máslo selské 82%',             'jednotka' => 'g',  'cena_baleni' => 75,  'obsah_baleni' => 250,   'alergen' => 'mléko',   'naskladnit_baleni' => 40, 'stock_min' => 2000],
        ['nazev' => 'Margarín pekařský',            'jednotka' => 'g',  'cena_baleni' => 470, 'obsah_baleni' => 10000, 'naskladnit_baleni' => 2, 'stock_min' => 3000],
        ['nazev' => 'Olej slunečnicový',            'jednotka' => 'ml', 'cena_baleni' => 250, 'obsah_baleni' => 5000,  'naskladnit_baleni' => 4, 'stock_min' => 2000],
        // 🥛 Mléčné
        ['nazev' => 'Mléko polotučné 1,5%',         'jednotka' => 'ml', 'cena_baleni' => 28,  'obsah_baleni' => 1000,  'alergen' => 'mléko',   'naskladnit_baleni' => 30, 'stock_min' => 5000],
        ['nazev' => 'Smetana 33% ke šlehání',       'jednotka' => 'ml', 'cena_baleni' => 65,  'obsah_baleni' => 1000,  'alergen' => 'mléko',   'naskladnit_baleni' => 10, 'stock_min' => 2000],
        ['nazev' => 'Tvaroh měkký',                 'jednotka' => 'g',  'cena_baleni' => 78,  'obsah_baleni' => 500,   'alergen' => 'mléko',   'naskladnit_baleni' => 20, 'stock_min' => 2000],
        ['nazev' => 'Sýr eidam',                    'jednotka' => 'g',  'cena_baleni' => 220, 'obsah_baleni' => 1000,  'alergen' => 'mléko',   'naskladnit_baleni' => 5,  'stock_min' => 1000],
        // 🥚 Vejce
        ['nazev' => 'Vejce slepičí M (1 ks ≈ 60 g)','jednotka' => 'ks', 'cena_baleni' => 240, 'obsah_baleni' => 60,    'alergen' => 'vejce',   'naskladnit_baleni' => 8,  'stock_min' => 30],
        // 🍬 Cukry + med
        ['nazev' => 'Cukr krystal',                 'jednotka' => 'g',  'cena_baleni' => 1290,'obsah_baleni' => 50000, 'naskladnit_baleni' => 2, 'stock_min' => 5000],
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
            '_wow_demo' => '30+ surovin naskladněno (kartonů/balení) · recepty pro každý výrobek · uložené kalkulace s marží · fixní náklady (energie/mzdy/nájem)',
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

        // 5. Suroviny + naskladnění
        // 🆕 v2.9.271 — bohatší demo: 30+ surovin s reálnými velkoobchodními cenami,
        // automatické naskladnění (sklad_pohyby_v2 nebo legacy suroviny.stock_aktualni)
        // 🆕 v2.9.273 — MERGE MODE: pokud surovina už existuje ale chybí naskladnění/stock_min,
        //              doplnit (nepřepisovat existující ceny/název). To umožní upgrade ze starší demo.
        $surovinaIdByName = []; // pro mapování názvů na ID (pro recepty)
        $stats['suroviny_doplneno_stock'] = 0;

        // Detekuj sloupce jednou (cached pro celou smyčku)
        $surCols = $pdo->query("SHOW COLUMNS FROM suroviny")->fetchAll(PDO::FETCH_COLUMN);
        $hasStockAkt = in_array('stock_aktualni', $surCols, true);
        $hasStockMin = in_array('stock_minimalni', $surCols, true);

        // Helper: zápis sklad_pohyby pro audit (legacy tabulka)
        $logPrijem = function(int $surId, float $mnozstvi, float $cenaJed, string $note) use ($pdo, &$stats) {
            try {
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
            } catch (Throwable $e) { /* sklad_pohyby legacy not critical */ }
        };

        foreach (demo_suroviny() as $s) {
            try {
                $naskladnit = ((float) ($s['naskladnit_baleni'] ?? 0)) * ((float) ($s['obsah_baleni'] ?? 0));
                $stockMin   = (float) ($s['stock_min'] ?? 0);
                $cenaJed    = ((float) $s['cena_baleni']) / max(1, (float) $s['obsah_baleni']);

                // Existuje surovina? Pokud ano, MERGE chybějící data
                $stmt = $pdo->prepare("SELECT id" . ($hasStockAkt ? ", stock_aktualni" : "") . ($hasStockMin ? ", stock_minimalni" : "") . " FROM suroviny WHERE nazev = :n");
                $stmt->execute(['n' => $s['nazev']]);
                $existing = $stmt->fetch();

                if ($existing) {
                    $existId = (int) $existing['id'];
                    $surovinaIdByName[$s['nazev']] = $existId;

                    // Merge: pokud chybí naskladnění → naskladnit; pokud chybí stock_min → doplnit
                    $sets = []; $params = ['id' => $existId];
                    $doplnitStock = $hasStockAkt && (!isset($existing['stock_aktualni']) || (float) $existing['stock_aktualni'] === 0.0);
                    $doplnitMin   = $hasStockMin && (empty($existing['stock_minimalni']) || (float) $existing['stock_minimalni'] === 0.0);

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
            foreach ($recepty as $cislo => $polozky) {
                try {
                    $vyrStmt = $pdo->prepare("SELECT id FROM vyrobky WHERE cislo = :c LIMIT 1");
                    $vyrStmt->execute(['c' => $cislo]);
                    $vyrId = (int) $vyrStmt->fetchColumn();
                    if (!$vyrId) continue;
                    // Pokud výrobek už recept má, přeskoč (nepřepisuj user-data)
                    $recCheck = $pdo->prepare("SELECT COUNT(*) FROM vyrobek_suroviny WHERE vyrobek_id = :v");
                    $recCheck->execute(['v' => $vyrId]);
                    if ((int) $recCheck->fetchColumn() > 0) continue;

                    foreach ($polozky as $i => $p) {
                        $surId = $surovinaIdByName[$p['surovina']] ?? null;
                        if (!$surId) {
                            // Fallback — try DB lookup
                            $surId = (int) $pdo->query("SELECT id FROM suroviny WHERE nazev = " . $pdo->quote($p['surovina']) . " LIMIT 1")->fetchColumn();
                        }
                        if (!$surId) continue;
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
                    }
                    $stats['recepty']++;
                } catch (Throwable $e) {
                    $stats['errors'][] = "Recept {$cislo}: " . $e->getMessage();
                }
            }
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
                    $vyrStmt = $pdo->prepare("SELECT id, nazev, cena_bez_dph FROM vyrobky WHERE cislo = :c LIMIT 1");
                    $vyrStmt->execute(['c' => $cisloV]);
                    $vyr = $vyrStmt->fetch();
                    if (!$vyr) continue;
                    $vyrId = (int) $vyr['id'];

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
                        $surId = $surovinaIdByName[$r['surovina']] ?? 0;
                        if (!$surId) {
                            $surId = (int) $pdo->query("SELECT id FROM suroviny WHERE nazev = " . $pdo->quote($r['surovina']) . " LIMIT 1")->fetchColumn();
                        }
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

        $pdo->commit();
        json_response($stats);
    } catch (Throwable $e) {
        $pdo->rollBack();
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
        foreach (['objednavky_polozky', 'objednavky', 'dodaci_list_polozky', 'dodaci_listy',
                  'faktura_polozky', 'faktury', 'vyrobni_list_polozky', 'vyrobni_listy',
                  'mista_dodani', 'cenove_skupiny_slevy', 'vyrobek_suroviny',
                  'vyrobky', 'odberatele', 'suroviny', 'sklad_pohyby', 'kategorie_vyrobku'] as $t) {
            try { $pdo->exec("DELETE FROM `$t`"); } catch (Throwable $e) { /* ignore */ }
        }
        $pdo->exec("SET FOREIGN_KEY_CHECKS=1");
        json_response(['ok' => true, 'cleared' => true]);
    } catch (Throwable $e) {
        json_error('Clear selhal: ' . $e->getMessage(), 500);
    }
}

json_error('Neznámá akce (preview|apply|clear)', 404);
