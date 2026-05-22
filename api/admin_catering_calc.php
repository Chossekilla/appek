<?php
/**
 * 🥗 CATERING KALKULÁTOR — Lahůdky balíček
 *
 * GET ?action=options    — typy událostí + per-osobu doporučení
 * POST ?action=quote     — body { osob, typ_udalosti, prilohy[], napoje[] }
 *                          → vrátí { polozky: [{nazev, mnozstvi, cena}], celkem }
 *
 * Vyžaduje balíček 'lahudky'.
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/_admin_auth.php';
require_once __DIR__ . '/_packages_lib.php';

cors_headers();
require_admin();
header('Content-Type: application/json; charset=UTF-8');

if (!package_enabled('lahudky')) {
    http_response_code(402);
    json_response(['error' => 'Vyžaduje balíček 🥗 Lahůdky']);
}

$action = $_GET['action'] ?? '';
$method = $_SERVER['REQUEST_METHOD'];

function catering_options(): array {
    return [
        'typy_udalosti' => [
            ['id' => 'standard',  'nazev' => 'Standard (recepce, pohoštění)', 'ikona' => '🍽️', 'koef' => 1.0],
            ['id' => 'firemni',   'nazev' => 'Firemní (raut, meeting)',        'ikona' => '🏢', 'koef' => 1.1],
            ['id' => 'svatba',    'nazev' => 'Svatba',                         'ikona' => '💍', 'koef' => 1.5],
            ['id' => 'narozeniny','nazev' => 'Narozeniny / oslava',            'ikona' => '🎂', 'koef' => 1.3],
            ['id' => 'pohreb',    'nazev' => 'Pohřební hostina',               'ikona' => '🕊️', 'koef' => 0.9],
            ['id' => 'pohoseni',  'nazev' => 'Krátké pohoštění',                'ikona' => '🥂', 'koef' => 0.6],
        ],
        // Per-osobu doporučení (množství × koef události)
        'doporuceni' => [
            'chlebicky'         => ['nazev' => '🥪 Chlebíčky',           'per_osobu' => 3,    'cena_kc' => 18,  'jednotka' => 'ks'],
            'jednohubky'        => ['nazev' => '🍢 Jednohubky',          'per_osobu' => 4,    'cena_kc' => 12,  'jednotka' => 'ks'],
            'wraps'             => ['nazev' => '🌯 Wraps',                'per_osobu' => 1.5,  'cena_kc' => 45,  'jednotka' => 'ks'],
            'mini_kolacky'      => ['nazev' => '🧁 Mini koláčky',         'per_osobu' => 2,    'cena_kc' => 22,  'jednotka' => 'ks'],
            'zakousky'          => ['nazev' => '🍰 Zákusky / dezerty',    'per_osobu' => 1,    'cena_kc' => 38,  'jednotka' => 'ks'],
            'ovoce'             => ['nazev' => '🍇 Ovocné mísy',          'per_osobu' => 0.15, 'cena_kc' => 80,  'jednotka' => 'kg'],
            'syrove_mísy'       => ['nazev' => '🧀 Sýrové mísy',          'per_osobu' => 0.10, 'cena_kc' => 180, 'jednotka' => 'kg'],
            'tatarák'           => ['nazev' => '🥩 Tatarák s topinkami',  'per_osobu' => 0.08, 'cena_kc' => 320, 'jednotka' => 'kg'],
            'paštiky'           => ['nazev' => '🥖 Paštiky',              'per_osobu' => 0.05, 'cena_kc' => 280, 'jednotka' => 'kg'],
            'salaty'            => ['nazev' => '🥗 Saláty',                'per_osobu' => 0.20, 'cena_kc' => 95,  'jednotka' => 'kg'],
        ],
        'napoje' => [
            'voda'              => ['nazev' => '💧 Voda neperlivá (0.33L)', 'per_osobu' => 1,   'cena_kc' => 12, 'jednotka' => 'ks'],
            'voda_perliva'      => ['nazev' => '💧 Voda perlivá (0.33L)',  'per_osobu' => 0.5, 'cena_kc' => 14, 'jednotka' => 'ks'],
            'dzus'              => ['nazev' => '🧃 Džus (0.2L)',           'per_osobu' => 0.6, 'cena_kc' => 22, 'jednotka' => 'ks'],
            'kafa'              => ['nazev' => '☕ Káva',                  'per_osobu' => 0.8, 'cena_kc' => 25, 'jednotka' => 'ks'],
            'caj'               => ['nazev' => '🍵 Čaj',                   'per_osobu' => 0.4, 'cena_kc' => 18, 'jednotka' => 'ks'],
            'pivo'              => ['nazev' => '🍺 Pivo (0.5L)',           'per_osobu' => 0.8, 'cena_kc' => 38, 'jednotka' => 'ks'],
            'vino'              => ['nazev' => '🍷 Víno (0.2L)',           'per_osobu' => 0.5, 'cena_kc' => 55, 'jednotka' => 'ks'],
            'sekt'              => ['nazev' => '🥂 Sekt (0.15L)',          'per_osobu' => 0.4, 'cena_kc' => 75, 'jednotka' => 'ks'],
        ],
        'sazba_dph' => 12,
    ];
}

if ($action === 'options') {
    json_response(catering_options());
}

if ($action === 'quote' && $method === 'POST') {
    $d = json_input();
    $osob       = max(2, min(500, (int) ($d['osob'] ?? 20)));
    $typId      = $d['typ_udalosti'] ?? 'standard';
    $vybraneJidlo = $d['prilohy'] ?? [];   // pole klíčů ['chlebicky', 'wraps', …]
    $vybraneNapoje = $d['napoje'] ?? [];   // pole klíčů ['voda', 'kafa', …]

    $opts = catering_options();
    $typ = current(array_filter($opts['typy_udalosti'], fn($t) => $t['id'] === $typId)) ?: $opts['typy_udalosti'][0];
    $koef = (float) $typ['koef'];

    $polozky = [];
    $combined = array_merge($opts['doporuceni'], $opts['napoje']);
    $vsechnoVybrano = array_unique(array_merge($vybraneJidlo, $vybraneNapoje));

    foreach ($vsechnoVybrano as $key) {
        if (!isset($combined[$key])) continue;
        $item = $combined[$key];
        $mnozstvi = round($item['per_osobu'] * $osob * $koef, 2);
        $cena = round($mnozstvi * $item['cena_kc'], 2);
        $polozky[] = [
            'klic'    => $key,
            'nazev'   => $item['nazev'],
            'mnozstvi'=> $mnozstvi,
            'jednotka'=> $item['jednotka'],
            'cena_per_jednotku' => $item['cena_kc'],
            'cena_kc' => $cena,
        ];
    }

    $bezDPH = array_sum(array_column($polozky, 'cena_kc'));
    $dph    = $opts['sazba_dph'];
    $sDPH   = round($bezDPH * (1 + $dph / 100), 2);

    json_response([
        'osob'        => $osob,
        'typ'         => $typ,
        'polozky'     => $polozky,
        'sazba_dph'   => $dph,
        'cena_bez_dph'=> $bezDPH,
        'cena_dph'    => round($sDPH - $bezDPH, 2),
        'cena_s_dph'  => $sDPH,
        'cena_per_osobu' => round($sDPH / $osob, 2),
    ]);
}

json_error('Neznámá akce', 404);
