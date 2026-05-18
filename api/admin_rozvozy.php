<?php
/**
 * Rozvozové trasy — agregace dodacích listů na daný den + seskupení podle města.
 * GET ?datum=YYYY-MM-DD  → seznam DL na ten den seskupený podle města + adresy
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/_admin_auth.php';
cors_headers();
require_admin();

$pdo = db();
$method = $_SERVER['REQUEST_METHOD'];

if ($method !== 'GET') json_error('Method not allowed', 405);

$datum = $_GET['datum'] ?? date('Y-m-d');
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $datum)) json_error('Neplatný datum');

// Načti DL na daný den s adresou (z mista_dodani nebo z odberatele jako fallback)
$stmt = $pdo->prepare("
    SELECT dl.id, dl.cislo, dl.castka_celkem, dl.datum_vystaveni, dl.datum_dodani,
           o.cislo AS objednavka_cislo,
           od.id AS odberatel_id,
           od.nazev AS odberatel_nazev,
           od.telefon AS odberatel_telefon,
           md.id AS misto_id,
           md.nazev AS misto_nazev,
           md.ulice AS misto_ulice,
           md.mesto AS misto_mesto,
           md.psc AS misto_psc,
           md.telefon AS misto_telefon,
           md.kontaktni_osoba,
           md.cas_dodani,
           md.pokyny_pro_ridice,
           COALESCE(md.mesto, od.mesto, '') AS rozvoz_mesto,
           COALESCE(md.psc, od.psc, '') AS rozvoz_psc,
           COALESCE(NULLIF(CONCAT_WS(', ', md.ulice, md.mesto), ''), CONCAT_WS(', ', od.ulice, od.mesto)) AS rozvoz_adresa,
           (SELECT COUNT(*) FROM dodaci_list_polozky WHERE dodaci_list_id = dl.id) AS pocet_polozek,
           (SELECT SUM(mnozstvi) FROM dodaci_list_polozky WHERE dodaci_list_id = dl.id) AS celkem_ks
    FROM dodaci_listy dl
    LEFT JOIN objednavky o ON o.id = dl.objednavka_id
    JOIN odberatele od ON od.id = dl.odberatel_id
    LEFT JOIN mista_dodani md ON md.id = dl.misto_dodani_id
    WHERE dl.datum_dodani = :datum
    ORDER BY rozvoz_mesto, rozvoz_psc, od.nazev
");
$stmt->execute(['datum' => $datum]);
$dl_list = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Seskup podle města/PSČ
$mesta = [];
$celkem_castka = 0;
foreach ($dl_list as $dl) {
    $mesto = trim($dl['rozvoz_mesto']) ?: '— neznámé město —';
    if (!isset($mesta[$mesto])) {
        $mesta[$mesto] = [
            'mesto' => $mesto,
            'dl_count' => 0,
            'celkem_ks' => 0,
            'celkem_kc' => 0,
            'dl' => [],
        ];
    }
    $mesta[$mesto]['dl_count']++;
    $mesta[$mesto]['celkem_ks'] += (float) $dl['celkem_ks'];
    $mesta[$mesto]['celkem_kc'] += (float) $dl['castka_celkem'];
    $celkem_castka += (float) $dl['castka_celkem'];
    $mesta[$mesto]['dl'][] = $dl;
}

// Vrátíme seřazené (po městech)
ksort($mesta);
$mesta = array_values($mesta);

json_response([
    'datum' => $datum,
    'pocet_dl' => count($dl_list),
    'pocet_mest' => count($mesta),
    'celkem_kc' => $celkem_castka,
    'mesta' => $mesta,
]);
