<?php
/**
 * HACCP grafy — šablony technologického postupu (vstupy + kroky)
 * Přiřazují se k výrobkům přes vyrobky.haccp_graf_id
 *
 * Endpointy:
 *   GET    /api/admin_haccp_grafy.php             → seznam všech šablon
 *   GET    /api/admin_haccp_grafy.php?id=X        → detail šablony
 *   POST   /api/admin_haccp_grafy.php             → vytvořit šablonu
 *   PUT    /api/admin_haccp_grafy.php?id=X        → upravit šablonu
 *   DELETE /api/admin_haccp_grafy.php?id=X        → smazat (uvolní výrobky)
 *   POST   /api/admin_haccp_grafy.php?action=import_default → naimportuje výchozí sadu
 *   POST   /api/admin_haccp_grafy.php?action=assign         → { graf_id, vyrobky_ids: [] }
 *   POST   /api/admin_haccp_grafy.php?action=unassign       → { vyrobky_ids: [] }
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/_admin_auth.php';
cors_headers();
require_admin();

$pdo = db();
$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? '';

// =============================================================
// AUTO-MIGRACE
// =============================================================
$pdo->exec("
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
");

// Sloupec haccp_graf_id ve vyrobky
$col = $pdo->query("
    SELECT COUNT(*) FROM information_schema.columns
    WHERE table_schema = DATABASE() AND table_name = 'vyrobky' AND column_name = 'haccp_graf_id'
")->fetchColumn();
if (!$col) {
    $pdo->exec("ALTER TABLE vyrobky ADD COLUMN haccp_graf_id INT NULL, ADD INDEX idx_haccp_graf (haccp_graf_id)");
}

// =============================================================
// VÝCHOZÍ SADA ŠABLON (z reálných grafů APPEK B2B)
// =============================================================
function default_grafy_set(): array {
    return [
        [
            'nazev' => 'Pšeničné pečivo — základní',
            'popis' => 'Bagety, banketky, rohlíky bez zdobení (bez postraních směsí).',
            'suroviny' => [
                ['nazev' => 'mouka pšeničná hladká', 'krok_idx' => 0],
                ['nazev' => 'pitná voda', 'krok_idx' => 0],
                ['nazev' => 'rostlinný olej', 'krok_idx' => 0],
                ['nazev' => 'čerstvé droždí', 'krok_idx' => 0],
                ['nazev' => 'Uldo Spartakus', 'krok_idx' => 0],
                ['nazev' => 'sůl', 'krok_idx' => 0],
                ['nazev' => 'cukr', 'krok_idx' => 0],
            ],
            'kroky' => [
                ['nazev' => 'Dávkování a smísení surovin', 'ccp' => false, 'popis' => ''],
                ['nazev' => 'Zrání těsta',                'ccp' => false, 'popis' => ''],
                ['nazev' => 'Dělení na klonky',           'ccp' => false, 'popis' => ''],
                ['nazev' => 'Tvarování',                  'ccp' => false, 'popis' => ''],
                ['nazev' => 'Kynutí',                     'ccp' => false, 'popis' => ''],
                ['nazev' => 'Pečení',                     'ccp' => true,  'popis' => 'CCP — kontrola teploty pece a doby pečení.'],
                ['nazev' => 'Chladnutí',                  'ccp' => false, 'popis' => ''],
                ['nazev' => 'Ukládání do přepravek',      'ccp' => false, 'popis' => ''],
                ['nazev' => 'Skladování',                 'ccp' => false, 'popis' => ''],
                ['nazev' => 'Expedice',                   'ccp' => false, 'popis' => ''],
            ],
        ],
        [
            'nazev' => 'Pšeničné pečivo se zdobením',
            'popis' => 'Bagety celozrnné, žemle, housky, rohlíky s mákem/sezamem/směsí.',
            'suroviny' => [
                ['nazev' => 'mouka pšeničná hladká', 'krok_idx' => 0],
                ['nazev' => 'pitná voda',            'krok_idx' => 0],
                ['nazev' => 'rostlinný olej',        'krok_idx' => 0],
                ['nazev' => 'čerstvé droždí',        'krok_idx' => 0],
                ['nazev' => 'Uldo Spartakus',        'krok_idx' => 0],
                ['nazev' => 'sůl',                   'krok_idx' => 0],
                ['nazev' => 'cukr',                  'krok_idx' => 0],
                ['nazev' => 'směs (mák / sezam / sůl / kmín / Maroko / Pikant)', 'krok_idx' => 5],
            ],
            'kroky' => [
                ['nazev' => 'Dávkování a smísení surovin', 'ccp' => false, 'popis' => ''],
                ['nazev' => 'Zrání těsta',                'ccp' => false, 'popis' => ''],
                ['nazev' => 'Dělení na klonky',           'ccp' => false, 'popis' => ''],
                ['nazev' => 'Tvarování',                  'ccp' => false, 'popis' => ''],
                ['nazev' => 'Kynutí',                     'ccp' => false, 'popis' => ''],
                ['nazev' => 'Zdobení',                    'ccp' => false, 'popis' => 'Vstup postraní směsi (mák, sezam, kmín, sůl, Maroko, Pikant).'],
                ['nazev' => 'Pečení',                     'ccp' => true,  'popis' => 'CCP — kontrola teploty pece a doby pečení.'],
                ['nazev' => 'Chladnutí',                  'ccp' => false, 'popis' => ''],
                ['nazev' => 'Ukládání do přepravek',      'ccp' => false, 'popis' => ''],
                ['nazev' => 'Skladování',                 'ccp' => false, 'popis' => ''],
                ['nazev' => 'Expedice',                   'ccp' => false, 'popis' => ''],
            ],
        ],
        [
            'nazev' => 'Chleba',
            'popis' => 'Tradiční kvašený chleba s žitnou moukou a kvasem.',
            'suroviny' => [
                ['nazev' => 'mouka pšeničná hladká', 'krok_idx' => 0],
                ['nazev' => 'mouka žitná',           'krok_idx' => 0],
                ['nazev' => 'pitná voda',            'krok_idx' => 0],
                ['nazev' => 'žitný kvas',            'krok_idx' => 0],
                ['nazev' => 'droždí',                'krok_idx' => 0],
                ['nazev' => 'sůl',                   'krok_idx' => 0],
                ['nazev' => 'kmín',                  'krok_idx' => 0],
                ['nazev' => 'malzkraft / Bass tmavý', 'krok_idx' => 0],
                ['nazev' => 'směs (Pikant / Maroko / Vegipan / Victor)', 'krok_idx' => 0],
            ],
            'kroky' => [
                ['nazev' => 'Dávkování',                  'ccp' => false, 'popis' => ''],
                ['nazev' => 'Smísení surovin',            'ccp' => false, 'popis' => ''],
                ['nazev' => 'Zrání těsta',                'ccp' => false, 'popis' => ''],
                ['nazev' => 'Dělení',                     'ccp' => false, 'popis' => ''],
                ['nazev' => 'Tvarování',                  'ccp' => false, 'popis' => ''],
                ['nazev' => 'Kynutí',                     'ccp' => false, 'popis' => ''],
                ['nazev' => 'Pečení',                     'ccp' => true,  'popis' => 'CCP — kontrola teploty pece a doby pečení.'],
                ['nazev' => 'Chladnutí',                  'ccp' => false, 'popis' => ''],
                ['nazev' => 'Ukládání do přepravek',      'ccp' => false, 'popis' => ''],
                ['nazev' => 'Skladování',                 'ccp' => false, 'popis' => ''],
                ['nazev' => 'Expedice',                   'ccp' => false, 'popis' => ''],
            ],
        ],
        [
            'nazev' => 'Speciální pečivo (dalamánky)',
            'popis' => 'Pečivo s žitnou moukou, sýrem, ořechy, slunečnicí, ovesnými vločkami.',
            'suroviny' => [
                ['nazev' => 'mouka pšeničná hladká', 'krok_idx' => 0],
                ['nazev' => 'mouka žitná',           'krok_idx' => 0],
                ['nazev' => 'pitná voda',            'krok_idx' => 0],
                ['nazev' => 'rostlinný olej',        'krok_idx' => 0],
                ['nazev' => 'čerstvé droždí',        'krok_idx' => 0],
                ['nazev' => 'Uldo Spartakus',        'krok_idx' => 0],
                ['nazev' => 'sůl',                   'krok_idx' => 0],
                ['nazev' => 'sýr / ořechy / slunečnice / ovesné vločky / sezam / škvarky / sádlo / rozinky', 'krok_idx' => 5],
            ],
            'kroky' => [
                ['nazev' => 'Dávkování a smísení surovin', 'ccp' => false, 'popis' => ''],
                ['nazev' => 'Zrání těsta',                'ccp' => false, 'popis' => ''],
                ['nazev' => 'Dělení na klonky',           'ccp' => false, 'popis' => ''],
                ['nazev' => 'Tvarování',                  'ccp' => false, 'popis' => ''],
                ['nazev' => 'Kynutí',                     'ccp' => false, 'popis' => ''],
                ['nazev' => 'Zdobení',                    'ccp' => false, 'popis' => 'Vstup postraních surovin (sýr, ořechy, slunečnice, sezam, škvarky, vločky).'],
                ['nazev' => 'Pečení',                     'ccp' => true,  'popis' => 'CCP — kontrola teploty pece a doby pečení.'],
                ['nazev' => 'Chladnutí',                  'ccp' => false, 'popis' => ''],
                ['nazev' => 'Ukládání do přepravek',      'ccp' => false, 'popis' => ''],
                ['nazev' => 'Skladování',                 'ccp' => false, 'popis' => ''],
                ['nazev' => 'Expedice',                   'ccp' => false, 'popis' => ''],
            ],
        ],
        [
            'nazev' => 'Jemné pečivo',
            'popis' => 'Sladké/jemné pečivo s mašlováním, plněním, zdobením (koláčky, šátečky, závinky, placky).',
            'suroviny' => [
                ['nazev' => 'mouka pšeničná hladká', 'krok_idx' => 0],
                ['nazev' => 'pitná voda',            'krok_idx' => 0],
                ['nazev' => 'rostlinný olej',        'krok_idx' => 0],
                ['nazev' => 'čerstvé droždí',        'krok_idx' => 0],
                ['nazev' => 'Uldo Spartakus',        'krok_idx' => 0],
                ['nazev' => 'cukr',                  'krok_idx' => 0],
                ['nazev' => 'vanilínový cukr',       'krok_idx' => 0],
                ['nazev' => 'sůl',                   'krok_idx' => 0],
                ['nazev' => 'vejce',                 'krok_idx' => 0],
                ['nazev' => 'citropasta',            'krok_idx' => 0],
                ['nazev' => 'náplň (tvaroh / mák / povidla / ovoce)', 'krok_idx' => 6],
                ['nazev' => 'mašlovací vejce',       'krok_idx' => 7],
                ['nazev' => 'zdobení (mandle / hrubý cukr / rozinky / strouhanka / skořice)', 'krok_idx' => 8],
            ],
            'kroky' => [
                ['nazev' => 'Dávkování a smísení surovin', 'ccp' => false, 'popis' => ''],
                ['nazev' => 'Zrání těsta',                'ccp' => false, 'popis' => ''],
                ['nazev' => 'Kynutí',                     'ccp' => false, 'popis' => ''],
                ['nazev' => 'Dělení na klonky',           'ccp' => false, 'popis' => ''],
                ['nazev' => 'Tvarování',                  'ccp' => false, 'popis' => ''],
                ['nazev' => 'Konečné kynutí',             'ccp' => false, 'popis' => ''],
                ['nazev' => 'Plnění',                     'ccp' => false, 'popis' => 'Vložení náplně (tvaroh, mák, povidla, ovoce).'],
                ['nazev' => 'Mašlování',                  'ccp' => false, 'popis' => 'Potření vejcem.'],
                ['nazev' => 'Zdobení',                    'ccp' => false, 'popis' => 'Posyp / dekorace.'],
                ['nazev' => 'Pečení',                     'ccp' => true,  'popis' => 'CCP — kontrola teploty pece a doby pečení.'],
                ['nazev' => 'Chladnutí',                  'ccp' => false, 'popis' => ''],
                ['nazev' => 'Ukládání do přepravek',      'ccp' => false, 'popis' => ''],
                ['nazev' => 'Skladování',                 'ccp' => false, 'popis' => ''],
                ['nazev' => 'Expedice',                   'ccp' => false, 'popis' => ''],
            ],
        ],
    ];
}

// =============================================================
// HELPERS
// =============================================================
function row_to_graf(array $r): array {
    return [
        'id'        => (int) $r['id'],
        'nazev'     => $r['nazev'],
        'popis'     => $r['popis'] ?? '',
        'suroviny'  => is_string($r['suroviny']) ? (json_decode($r['suroviny'], true) ?: []) : ($r['suroviny'] ?: []),
        'kroky'     => is_string($r['kroky']) ? (json_decode($r['kroky'], true) ?: []) : ($r['kroky'] ?: []),
        'poradi'    => (int) ($r['poradi'] ?? 0),
        'aktivni'   => (int) ($r['aktivni'] ?? 1),
        'pocet_vyrobku' => (int) ($r['pocet_vyrobku'] ?? 0),
        'vytvoreno' => $r['vytvoreno'] ?? null,
        'upraveno'  => $r['upraveno'] ?? null,
    ];
}

function normalize_graf(array $d): array {
    $suroviny = [];
    foreach ((array) ($d['suroviny'] ?? []) as $s) {
        $nazev = trim((string) ($s['nazev'] ?? ''));
        if ($nazev === '') continue;
        $suroviny[] = [
            'nazev'    => $nazev,
            'krok_idx' => (int) ($s['krok_idx'] ?? 0),
        ];
    }
    $kroky = [];
    foreach ((array) ($d['kroky'] ?? []) as $k) {
        $nazev = trim((string) ($k['nazev'] ?? ''));
        if ($nazev === '') continue;
        $kroky[] = [
            'nazev' => $nazev,
            'ccp'   => !empty($k['ccp']),
            'popis' => trim((string) ($k['popis'] ?? '')),
        ];
    }
    return [
        'nazev'    => trim((string) ($d['nazev'] ?? '')),
        'popis'    => trim((string) ($d['popis'] ?? '')),
        'suroviny' => $suroviny,
        'kroky'    => $kroky,
        'poradi'   => (int) ($d['poradi'] ?? 0),
        'aktivni'  => !empty($d['aktivni']) ? 1 : 0,
    ];
}

// =============================================================
// VÝCHOZÍ POPISY KROKŮ — vrací mapu nazev kroku → popis
// Specifika dle typu grafu
// =============================================================
function default_postup_popisy(string $graf_nazev): array {
    $base = [
        'Dávkování'                  => 'Naváží se základní suroviny dle receptury (mouka 100 %, voda 60–65 %, droždí 2–4 %, sůl 1.5–2 %, olej 2 %, cukr 1–3 %, zlepšovadlo). Kontrola DMT, vizuální kontrola surovin, čistota nádobí a vah.',
        'Dávkování a smísení surovin'=> 'Naváží se suroviny dle receptury (mouka 100 %, voda 60–65 %, droždí 2–4 %, sůl 1.5–2 %, olej 2 %, cukr 1–3 %, Uldo Spartakus 0.5 %). Smísení v hnětači 4 min pomalu + 6 min rychle při 26–28 °C. Konečná teplota těsta 28–30 °C.',
        'Smísení surovin'            => 'Hnětení v hnětači 4 min pomalu + 6–8 min rychle při 26–28 °C. Kontrola hladkosti a pružnosti těsta. Konečná teplota 28–30 °C.',
        'Zrání těsta'                => 'Odpočinek těsta 30–60 min při teplotě 26–28 °C a vlhkosti 70–75 % v zakryté nádobě nebo na válu. Probíhá první kvasný proces.',
        'Dělení'                     => 'Rozdělení těsta na rovnoměrné kusy dle hmotnosti výrobku pomocí dělicího stroje. Kontrola hmotnosti namátkově (váha).',
        'Dělení na klonky'           => 'Rozdělení těsta na klonky dle hmotnosti výrobku (rohlík/žemle 60–90 g, bageta 80–110 g, dalamánek 50–80 g) na dělicím stroji. Namátková kontrola hmotnosti.',
        'Tvarování'                  => 'Ruční nebo strojové tvarování — rohlíky se válí, žemle se kulatí, bagety se táhnou na podlouhlý tvar s nářezem, banketky se mašlují.',
        'Kynutí'                     => 'Kynutí v kynárně 35–45 min při teplotě 32–35 °C a vlhkosti 75–80 % až do dvojnásobku objemu.',
        'Konečné kynutí'             => 'Konečné kynutí 25–35 min při 30–32 °C a vlhkosti 75 % až do plného nakynutí. Vizuální kontrola objemu.',
        'Zdobení'                    => 'Vlhčení vodou nebo mašlovacím vejcem, posyp dle typu výrobku (mák, sezam, sůl, kmín, sýr, směs Maroko/Pikant/Fénix, ořechy, slunečnice). Posyp rovnoměrně před zasazením do pece.',
        'Plnění'                     => 'Vložení náplně dle receptury (tvaroh stabilizovaný, mák, povidla, ovoce, čokoláda) na střed klonku, uzavření tvarováním.',
        'Mašlování'                  => 'Potření povrchu rozšlehaným vejcem (1 vejce na 10 ks pečiva) pro lesklý zlatohnědý povrch po pečení.',
        'Pečení'                     => 'Pečení v parní troubě dle typu výrobku. Kontrola teploty pece a doby pečení dle receptury, vnitřní teplota produktu min. 90 °C. Záznam do provozního deníku — CCP (datum, šarže, teplota, doba, jméno obsluhy).',
        'Chladnutí'                  => 'Chlazení na chladicí ploše ve větraném prostoru 30–90 min, dokud teplota produktu neklesne pod 30 °C. Oddělení od surového těsta — zákaz křížové kontaminace.',
        'Ukládání do přepravek'      => 'Ruční přemístění do čistých plastových přepravek (max. 30 ks/přepravka u pečiva, 6–8 ks u chleba). Třídění dle druhu, kontrola integrity přepravek.',
        'Skladování'                 => 'Krátkodobé skladování v expediční místnosti při teplotě do 25 °C, vlhkost < 70 %. FIFO (first in — first out). Maximální doba do expedice 4 hodiny.',
        'Skladování hotových výrobků'=> 'Skladování v expediční místnosti do 25 °C, vlhkost < 70 %. FIFO, čistota přepravek, oddělení od surovin.',
        'Expedice'                   => 'Nakládka do čistých vozidel, kontrola DMT před nakládkou, předání dle dodacích listů. Distribuce do provozoven APPEK B2B.',
    ];

    // Specifika podle typu grafu
    if (stripos($graf_nazev, 'Chleba') !== false || stripos($graf_nazev, 'chléb') !== false) {
        $base['Dávkování'] = 'Mouka pšeničná 60 % + mouka žitná 40 % (dle receptury), voda 60–65 %, žitný kvas 25–30 %, droždí 1–1.5 %, sůl 1.8–2 %, kmín 0.5–1 %, malzkraft / Bass tmavý dle receptury.';
        $base['Zrání těsta'] = 'Hlavní kvasný proces 60–90 min při 27–29 °C, vlhkost 75–80 %. Pravidelná kontrola objemu a aroma kvasu.';
        $base['Dělení'] = 'Rozdělení těsta na bochníky 800–1500 g pomocí dělicího stroje na chléb. Kontrola hmotnosti.';
        $base['Tvarování'] = 'Ruční tvarování bochníku do oválného nebo kulatého tvaru, vložení do ošatky vyložené žitnou moukou pro konečné kynutí.';
        $base['Kynutí'] = 'Kynutí 60–90 min v ošatkách při 32–35 °C, vlhkost 75–80 %. Před zasazením do pece nářez nože.';
        $base['Pečení'] = 'Pečení v chlebové peci při 240–260 °C s vydatnou parou na začátku (5–8 sek). Doba pečení 30–45 min dle hmotnosti bochníku. Vnitřní teplota produktu min. 92 °C. Záznam do provozního deníku — CCP.';
        $base['Chladnutí'] = 'Chlazení na chladicím regálu 60–120 min až do teploty pod 30 °C, ideálně přes noc pro stabilizaci střídy.';
    }

    if (stripos($graf_nazev, 'Jemné') !== false) {
        $base['Dávkování a smísení surovin'] = 'Mouka pšeničná 100 %, voda nebo mléko 40–45 %, droždí 4–6 %, cukr 12–18 %, sůl 1 %, vejce 8–10 % (cca 4 ks/kg mouky), vanilínový cukr 1 %, citropasta 0.3 %, máslo/olej 8–15 %. Hnětení 5 + 8 min při 26 °C.';
        $base['Zrání těsta'] = 'Zrání 30–45 min při 28–30 °C v zakryté nádobě.';
        $base['Pečení'] = 'Pečení v elektrické peci 180–210 °C, doba 12–18 min dle velikosti. Vnitřní teplota produktu min. 88 °C. Záznam do provozního deníku — CCP.';
        $base['Plnění'] = 'Vložení náplně dle typu výrobku — tvaroh se stabilizátorem (cca 35–45 % hmotnosti), mák (30–40 %), povidla (25–30 %), ovocná náplň, čokoláda — vždy ve správném poměru.';
        $base['Mašlování'] = 'Potření povrchu rozšlehaným vejcem nebo vaječným bílkem před pečením pro lesklý zlatohnědý povrch.';
        $base['Zdobení'] = 'Posyp dle typu: mandle plátkové (3–5 g/ks), hrubý cukr (2 g/ks), rozinky, strouhanka, skořice s cukrem. Posyp se nanáší na mašlovaný povrch.';
        $base['Konečné kynutí'] = 'Konečné kynutí 25–35 min při 30–32 °C, vlhkost 75 %.';
    }

    if (stripos($graf_nazev, 'Speciální') !== false || stripos($graf_nazev, 'dalam') !== false) {
        $base['Dávkování a smísení surovin'] = 'Mouka pšeničná 70 % + mouka žitná 30 %, voda 60 %, droždí 3 %, sůl 1.8 %, olej 2 %, Uldo Spartakus 0.5 %. Hnětení 4 + 6 min při 26–28 °C.';
        $base['Pečení'] = 'Pečení v parní peci 220–230 °C, doba 15–20 min. Slabší napaření na začátku (3 sek). Vnitřní teplota produktu min. 90 °C. Záznam do provozního deníku — CCP.';
        $base['Zdobení'] = 'Postraní vstup: sýr Eidam 30 % (5–8 g/ks), vlašské ořechy, slunečnice, sezam, ovesné vločky, škvarky, sádlo, rozinky — dle typu výrobku. Posyp se aplikuje vlhčením a obalením před pečením.';
    }

    if (stripos($graf_nazev, 'se zdobením') !== false) {
        $base['Zdobení'] = 'Vlhčení vodou (vlhčicím pásem nebo postříkáním), posyp dle typu výrobku: mák (1.5 g/ks), sezam (1 g/ks), sůl + kmín, sýr Eidam 30 % (5 g/ks), směs Maroko/Pikant/Fénix (3 g/ks). Posyp rovnoměrně před zasazením do pece.';
        $base['Pečení'] = 'Pečení v parní peci 220–240 °C s napařením 3–5 sek na začátku. Doba pečení 12–22 min dle velikosti výrobku. Vnitřní teplota produktu min. 90 °C. Záznam do provozního deníku — CCP.';
    }

    if (stripos($graf_nazev, 'základní') !== false) {
        $base['Pečení'] = 'Pečení v parní peci 220–240 °C s napařením 3–5 sek na začátku. Doba pečení 12–22 min dle velikosti výrobku. Vnitřní teplota produktu min. 90 °C. Záznam do provozního deníku — CCP.';
    }

    return $base;
}

// =============================================================
// AUTO-VYPLNĚNÍ POPISŮ KROKŮ — POST ?action=fill_postup_popis
// Doplní popisy ve všech existujících šablonách (jen prázdné, nebo force=1 přepíše vše)
// =============================================================
if ($method === 'POST' && $action === 'fill_postup_popis') {
    $force = !empty($_GET['force']);
    $rows = $pdo->query("SELECT id, nazev, kroky FROM haccp_grafy")->fetchAll();
    $updated = 0;
    $touched_steps = 0;
    $upd = $pdo->prepare("UPDATE haccp_grafy SET kroky = :kroky WHERE id = :id");

    foreach ($rows as $r) {
        $kroky = json_decode($r['kroky'] ?? '[]', true);
        if (!is_array($kroky)) continue;
        $popisy = default_postup_popisy($r['nazev']);
        $changed = false;

        foreach ($kroky as $i => $k) {
            $nazev = (string) ($k['nazev'] ?? '');
            $existing = trim((string) ($k['popis'] ?? ''));
            if (!isset($popisy[$nazev])) continue;
            if (!$force && $existing !== '') continue;
            $kroky[$i]['popis'] = $popisy[$nazev];
            $changed = true;
            $touched_steps++;
        }

        if ($changed) {
            $upd->execute([
                'id' => $r['id'],
                'kroky' => json_encode($kroky, JSON_UNESCAPED_UNICODE),
            ]);
            $updated++;
        }
    }
    json_response([
        'ok' => true,
        'force' => $force,
        'updated_grafu' => $updated,
        'touched_kroku' => $touched_steps,
    ]);
}

// =============================================================
// IMPORT VÝCHOZÍ SADY — POST ?action=import_default
// =============================================================
if ($method === 'POST' && $action === 'import_default') {
    $existing = (int) $pdo->query("SELECT COUNT(*) FROM haccp_grafy")->fetchColumn();
    $force = !empty($_GET['force']);
    if ($existing > 0 && !$force) {
        json_response([
            'ok' => false,
            'message' => "Už existuje $existing šablon. Pro přepsání pošli ?force=1.",
            'existing' => $existing,
        ]);
    }
    $created = 0;
    foreach (default_grafy_set() as $i => $g) {
        $g['poradi']  = $i + 1;
        $g['aktivni'] = 1;
        // Aplikuj výchozí popisy kroků hned při importu
        $popisy = default_postup_popisy($g['nazev']);
        foreach ($g['kroky'] as $j => $k) {
            $kn = (string) ($k['nazev'] ?? '');
            if (isset($popisy[$kn]) && empty(trim((string) ($k['popis'] ?? '')))) {
                $g['kroky'][$j]['popis'] = $popisy[$kn];
            }
        }
        $stmt = $pdo->prepare("
            INSERT INTO haccp_grafy (nazev, popis, suroviny, kroky, poradi, aktivni)
            VALUES (:nazev, :popis, :suroviny, :kroky, :poradi, :aktivni)
        ");
        $stmt->execute([
            'nazev'    => $g['nazev'],
            'popis'    => $g['popis'],
            'suroviny' => json_encode($g['suroviny'], JSON_UNESCAPED_UNICODE),
            'kroky'    => json_encode($g['kroky'], JSON_UNESCAPED_UNICODE),
            'poradi'   => $g['poradi'],
            'aktivni'  => $g['aktivni'],
        ]);
        $created++;
    }
    json_response(['ok' => true, 'created' => $created]);
}

// =============================================================
// PŘIŘAZENÍ ŠABLONY K VÝROBKŮM — POST ?action=assign
// =============================================================
if ($method === 'POST' && $action === 'assign') {
    $d = json_input();
    $graf_id = (int) ($d['graf_id'] ?? 0);
    $ids = array_filter(array_map('intval', (array) ($d['vyrobky_ids'] ?? [])));
    if (!$graf_id) json_error('Chybí graf_id');
    if (empty($ids)) json_error('Žádné výrobky k přiřazení');

    $exists = $pdo->prepare("SELECT id FROM haccp_grafy WHERE id = ?");
    $exists->execute([$graf_id]);
    if (!$exists->fetchColumn()) json_error('Šablona neexistuje');

    $place = implode(',', array_fill(0, count($ids), '?'));
    $stmt = $pdo->prepare("UPDATE vyrobky SET haccp_graf_id = ? WHERE id IN ($place)");
    $params = array_merge([$graf_id], $ids);
    $stmt->execute($params);

    json_response(['ok' => true, 'updated' => $stmt->rowCount()]);
}

// =============================================================
// ODEBRÁNÍ ŠABLONY Z VÝROBKŮ — POST ?action=unassign
// =============================================================
if ($method === 'POST' && $action === 'unassign') {
    $d = json_input();
    $ids = array_filter(array_map('intval', (array) ($d['vyrobky_ids'] ?? [])));
    $onlyGraf = (int) ($d['only_graf_id'] ?? 0);
    if (empty($ids)) json_error('Žádné výrobky');

    $place = implode(',', array_fill(0, count($ids), '?'));
    if ($onlyGraf > 0) {
        // Odeber jen těm, kteří mají právě tento graf (chrání cizí přiřazení)
        $stmt = $pdo->prepare("UPDATE vyrobky SET haccp_graf_id = NULL WHERE id IN ($place) AND haccp_graf_id = ?");
        $stmt->execute(array_merge($ids, [$onlyGraf]));
    } else {
        $stmt = $pdo->prepare("UPDATE vyrobky SET haccp_graf_id = NULL WHERE id IN ($place)");
        $stmt->execute($ids);
    }
    json_response(['ok' => true, 'updated' => $stmt->rowCount()]);
}

// =============================================================
// GET seznam / detail
// =============================================================
if ($method === 'GET') {
    $id = (int) ($_GET['id'] ?? 0);
    if ($id) {
        $stmt = $pdo->prepare("
            SELECT g.*, (SELECT COUNT(*) FROM vyrobky v WHERE v.haccp_graf_id = g.id) AS pocet_vyrobku
            FROM haccp_grafy g WHERE g.id = ?
        ");
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        if (!$row) json_error('Šablona nenalezena', 404);

        // Připoj seznam výrobků
        $vStmt = $pdo->prepare("SELECT id, cislo, nazev FROM vyrobky WHERE haccp_graf_id = ? ORDER BY nazev");
        $vStmt->execute([$id]);
        $g = row_to_graf($row);
        $g['vyrobky'] = $vStmt->fetchAll();
        json_response($g);
    }

    $rows = $pdo->query("
        SELECT g.*, (SELECT COUNT(*) FROM vyrobky v WHERE v.haccp_graf_id = g.id) AS pocet_vyrobku
        FROM haccp_grafy g
        ORDER BY g.poradi, g.nazev
    ")->fetchAll();
    json_response(array_map('row_to_graf', $rows));
}

// =============================================================
// POST — vytvořit
// =============================================================
if ($method === 'POST') {
    $d = json_input();
    $g = normalize_graf($d);
    if ($g['nazev'] === '') json_error('Chybí název');

    $stmt = $pdo->prepare("
        INSERT INTO haccp_grafy (nazev, popis, suroviny, kroky, poradi, aktivni)
        VALUES (:nazev, :popis, :suroviny, :kroky, :poradi, :aktivni)
    ");
    $stmt->execute([
        'nazev'    => $g['nazev'],
        'popis'    => $g['popis'],
        'suroviny' => json_encode($g['suroviny'], JSON_UNESCAPED_UNICODE),
        'kroky'    => json_encode($g['kroky'], JSON_UNESCAPED_UNICODE),
        'poradi'   => $g['poradi'],
        'aktivni'  => $g['aktivni'],
    ]);
    json_response(['ok' => true, 'id' => (int) $pdo->lastInsertId()]);
}

// =============================================================
// PUT — upravit
// =============================================================
if ($method === 'PUT') {
    $id = (int) ($_GET['id'] ?? 0);
    if (!$id) json_error('Chybí id');
    $d = json_input();
    $g = normalize_graf($d);
    if ($g['nazev'] === '') json_error('Chybí název');

    $stmt = $pdo->prepare("
        UPDATE haccp_grafy
        SET nazev = :nazev, popis = :popis, suroviny = :suroviny, kroky = :kroky,
            poradi = :poradi, aktivni = :aktivni
        WHERE id = :id
    ");
    $stmt->execute([
        'id'       => $id,
        'nazev'    => $g['nazev'],
        'popis'    => $g['popis'],
        'suroviny' => json_encode($g['suroviny'], JSON_UNESCAPED_UNICODE),
        'kroky'    => json_encode($g['kroky'], JSON_UNESCAPED_UNICODE),
        'poradi'   => $g['poradi'],
        'aktivni'  => $g['aktivni'],
    ]);
    json_response(['ok' => true]);
}

// =============================================================
// DELETE
// =============================================================
if ($method === 'DELETE') {
    $id = (int) ($_GET['id'] ?? 0);
    if (!$id) json_error('Chybí id');
    // Uvolni výrobky
    $pdo->prepare("UPDATE vyrobky SET haccp_graf_id = NULL WHERE haccp_graf_id = ?")->execute([$id]);
    $pdo->prepare("DELETE FROM haccp_grafy WHERE id = ?")->execute([$id]);
    json_response(['ok' => true]);
}

json_error('Neznámá metoda', 405);
