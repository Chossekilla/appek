<?php
/**
 * 🎂 CAKE CONFIGURATOR — feature 'cake_configurator' (balíček Cukrárna).
 *
 * Visual konfigurátor svatebních/narozeninových dortů.
 *
 * GET  /api/feature_cake_configurator.php           → seznam variant (tvary, příchutě…)
 * POST /api/feature_cake_configurator.php           → vypočti cenu + náhled
 *      Body: { shape, size_cm, tiers, flavor, fillings: [], decorations: [] }
 *
 * Gating: feature_enabled('cake_configurator') — Cukrárna balíček
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/_admin_auth.php';
require_once __DIR__ . '/_features.php';

header('Content-Type: application/json; charset=UTF-8');
if (function_exists('cors_headers')) cors_headers();

if (!feature_enabled('cake_configurator')) {
    http_response_code(402);
    echo json_encode([
        'error' => 'upsell',
        'feature' => 'cake_configurator',
        'package' => 'cukrarna',
        'message' => 'Cake konfigurátor je součástí balíčku Cukrárna.',
    ]);
    exit;
}

require_admin();

const CAKE_OPTIONS = [
    'shapes' => [
        ['id' => 'round',  'name' => '⚪ Kulatý',     'price_mult' => 1.0],
        ['id' => 'square', 'name' => '⬛ Hranatý',    'price_mult' => 1.1],
        ['id' => 'heart',  'name' => '❤️ Srdcový',    'price_mult' => 1.25],
        ['id' => 'custom', 'name' => '🎨 Tvarový',    'price_mult' => 1.5],
    ],
    'sizes' => [
        ['cm' => 12, 'serves' => 4,   'base_price' => 380],
        ['cm' => 18, 'serves' => 8,   'base_price' => 580],
        ['cm' => 24, 'serves' => 16,  'base_price' => 950],
        ['cm' => 30, 'serves' => 24,  'base_price' => 1450],
        ['cm' => 36, 'serves' => 36,  'base_price' => 2100],
    ],
    'flavors' => [
        ['id' => 'vanilla',    'name' => '🌼 Vanilkový',    'price_add' => 0],
        ['id' => 'chocolate',  'name' => '🍫 Čokoládový',   'price_add' => 80],
        ['id' => 'strawberry', 'name' => '🍓 Jahodový',     'price_add' => 60],
        ['id' => 'lemon',      'name' => '🍋 Citronový',    'price_add' => 50],
        ['id' => 'caramel',    'name' => '🍯 Karamelový',   'price_add' => 90],
        ['id' => 'redvelvet',  'name' => '❤️ Red velvet',    'price_add' => 120],
    ],
    'fillings' => [
        ['id' => 'cream',    'name' => '🥛 Šlehačka',          'price_add' => 40],
        ['id' => 'fruit',    'name' => '🍒 Ovocná náplň',      'price_add' => 60],
        ['id' => 'nutella',  'name' => '🌰 Nutella',           'price_add' => 90],
        ['id' => 'caramel',  'name' => '🍯 Karamel',           'price_add' => 80],
        ['id' => 'mascar',   'name' => '🧀 Mascarpone',        'price_add' => 110],
        ['id' => 'mousse',   'name' => '🍫 Čokoládová pěna',   'price_add' => 95],
    ],
    'decorations' => [
        ['id' => 'fondant',   'name' => '🎨 Fondán potah',          'price_add' => 200],
        ['id' => 'fresh',     'name' => '🌸 Čerstvé květiny',       'price_add' => 250],
        ['id' => 'figures',   'name' => '🦄 Figurky z fondánu',     'price_add' => 350],
        ['id' => 'macaron',   'name' => '🌈 Macarons na vrchu',     'price_add' => 180],
        ['id' => 'gold',      'name' => '✨ Zlaté detaily',          'price_add' => 220],
        ['id' => 'drip',      'name' => '💧 Drip-cake (poleva)',    'price_add' => 100],
        ['id' => 'photo',     'name' => '📸 Fotka (jedlý papír)',   'price_add' => 150],
        ['id' => 'topper',    'name' => '🎂 Topper s nápisem',      'price_add' => 80],
    ],
];

try {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $input = json_decode(file_get_contents('php://input'), true) ?? [];
        $shapeId    = $input['shape']    ?? 'round';
        $sizeCm     = (int)($input['size_cm']  ?? 18);
        $tiers      = max(1, min(5, (int)($input['tiers'] ?? 1)));
        $flavorId   = $input['flavor']   ?? 'vanilla';
        $fillings   = $input['fillings']    ?? [];
        $decorations = $input['decorations'] ?? [];

        // Find option meta
        $shape  = current(array_filter(CAKE_OPTIONS['shapes'],  fn($x) => $x['id'] === $shapeId)) ?: CAKE_OPTIONS['shapes'][0];
        $size   = current(array_filter(CAKE_OPTIONS['sizes'],   fn($x) => $x['cm'] === $sizeCm)) ?: CAKE_OPTIONS['sizes'][1];
        $flavor = current(array_filter(CAKE_OPTIONS['flavors'], fn($x) => $x['id'] === $flavorId)) ?: CAKE_OPTIONS['flavors'][0];

        // Price calculation
        $basePrice = (float)$size['base_price'];
        $shapeMult = (float)$shape['price_mult'];
        $tierMult  = 1 + (($tiers - 1) * 0.65); // 2-tier = 1.65×, 3-tier = 2.30× etc.
        $flavorAdd = (float)$flavor['price_add'];
        $tier1     = $basePrice * $shapeMult * $tierMult + $flavorAdd;

        $fillingsTotal = 0;
        $fillingsMeta = [];
        foreach ($fillings as $fid) {
            $f = current(array_filter(CAKE_OPTIONS['fillings'], fn($x) => $x['id'] === $fid));
            if ($f) {
                $fillingsTotal += (float)$f['price_add'];
                $fillingsMeta[] = $f;
            }
        }

        $decTotal = 0;
        $decMeta = [];
        foreach ($decorations as $did) {
            $d = current(array_filter(CAKE_OPTIONS['decorations'], fn($x) => $x['id'] === $did));
            if ($d) {
                $decTotal += (float)$d['price_add'];
                $decMeta[] = $d;
            }
        }

        $totalBezDph = $tier1 + $fillingsTotal + $decTotal;
        $dph         = $totalBezDph * 0.12;
        $totalSDph   = $totalBezDph + $dph;
        $serves      = (int)$size['serves'] * (1 + ($tiers - 1) * 0.7);

        echo json_encode([
            'spec' => [
                'shape'       => $shape,
                'size'        => $size,
                'tiers'       => $tiers,
                'flavor'      => $flavor,
                'fillings'    => $fillingsMeta,
                'decorations' => $decMeta,
            ],
            'pricing' => [
                'base'        => round($basePrice, 2),
                'shape_mult'  => $shapeMult,
                'tier_mult'   => round($tierMult, 2),
                'flavor_add'  => $flavorAdd,
                'fillings'    => round($fillingsTotal, 2),
                'decorations' => round($decTotal, 2),
                'bez_dph'     => round($totalBezDph, 2),
                'dph'         => round($dph, 2),
                'celkem'      => round($totalSDph, 2),
            ],
            'serves'      => (int)round($serves),
            'lead_time_days' => 3 + ($tiers - 1) * 2 + (count($decorations) > 3 ? 2 : 0),
        ]);
        exit;
    }

    // GET — options
    echo json_encode(CAKE_OPTIONS);

} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}
