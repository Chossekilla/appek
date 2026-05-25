<?php
/**
 * 🎉 EVENT PLANNER — feature 'event_planner' (balíček Catering).
 *
 * Catering kalkulátor: zadej počet hostů + typ akce → vrátí doporučené množství
 * surovin / hotových výrobků a celkovou cenu.
 *
 * GET  /api/feature_event_planner.php           → seznam typů akcí
 * POST /api/feature_event_planner.php           → vypočti
 *      Body: { event_type: "svatba"|"firemka"|"narozeniny"|"konference"|"raut", guests: int, hours?: int }
 *
 * Gating: feature_enabled('event_planner') — Catering balíček
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/_admin_auth.php';
require_once __DIR__ . '/_features.php';

header('Content-Type: application/json; charset=UTF-8');
if (function_exists('cors_headers')) cors_headers();

if (!feature_enabled('event_planner')) {
    http_response_code(402);
    echo json_encode([
        'error' => 'upsell',
        'feature' => 'event_planner',
        'package' => 'catering',
        'message' => 'Event planner je součástí balíčku Catering.',
    ]);
    exit;
}

require_admin();

/**
 * Šablony eventů: per_guest = porce na hosta na hodinu (nebo na celkový čas).
 * Kategorie produktu se mapuje na kategorie_vyrobku.nazev.
 */
const EVENT_TEMPLATES = [
    'svatba' => [
        'name' => '💍 Svatba',
        'desc' => 'Aperitiv, předkrm, hlavní chod, dort, večerní bufet.',
        'default_hours' => 8,
        'recipe' => [
            // [kategorie, per_guest_count, per_guest_jednotka, label]
            ['Pečivo',     2.0, 'ks',  'Pečivo na rauty (rohlíky, housky)'],
            ['Lahůdky',    3.0, 'ks',  'Studené sendviče a wrap (předkrm + buffet)'],
            ['Cukrářské',  1.5, 'ks',  'Sladké (dorty, řezy, malé sladkosti)'],
            ['Nápoje',     1.5, 'ks',  'Nealko nápoje (kafe, limča)'],
        ],
    ],
    'firemka' => [
        'name' => '🏢 Firemní akce',
        'desc' => 'Coffee break + oběd + odpolední snack.',
        'default_hours' => 6,
        'recipe' => [
            ['Pečivo',     1.5, 'ks',  'Pečivo na coffee break'],
            ['Lahůdky',    2.5, 'ks',  'Obědový catering (sendviče, saláty)'],
            ['Cukrářské',  1.0, 'ks',  'Sladké k odpolední kávě'],
            ['Nápoje',     2.0, 'ks',  'Kafe, čaj, voda, džus'],
        ],
    ],
    'narozeniny' => [
        'name' => '🎂 Narozeniny',
        'desc' => 'Dort + drobné slané/sladké pohoštění.',
        'default_hours' => 4,
        'recipe' => [
            ['Cukrářské',  1.5, 'ks',  'Dort + řezy + cookies'],
            ['Lahůdky',    1.8, 'ks',  'Slané sendviče, mini-quiche'],
            ['Pečivo',     1.0, 'ks',  'Rohlíky, žemle'],
            ['Nápoje',     1.5, 'ks',  'Nealko nápoje'],
        ],
    ],
    'konference' => [
        'name' => '🎤 Konference',
        'desc' => 'Coffee breaks (3×), obědový raut.',
        'default_hours' => 8,
        'recipe' => [
            ['Pečivo',     2.5, 'ks',  '3× coffee break (croissanty, žemle)'],
            ['Lahůdky',    3.0, 'ks',  'Obědový raut (wrapy, saláty, mini-quiche)'],
            ['Cukrářské',  2.0, 'ks',  'Sladké k coffee break'],
            ['Nápoje',     3.5, 'ks',  'Kafe (3×), voda celý den'],
        ],
    ],
    'raut' => [
        'name' => '🥂 Raut (cocktail party)',
        'desc' => 'Studený bufet, jednohubky, drobnosti.',
        'default_hours' => 3,
        'recipe' => [
            ['Lahůdky',    5.0, 'ks',  'Jednohubky, mini-sendviče, wrapy'],
            ['Cukrářské',  1.5, 'ks',  'Mini-dezerty, makronky'],
            ['Pečivo',     0.8, 'ks',  'Bagetky, focaccia'],
            ['Nápoje',     2.5, 'ks',  'Aperitivy nealko, voda'],
        ],
    ],
];

try {
    $pdo = db();

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $input = json_decode(file_get_contents('php://input'), true) ?? [];
        $type   = $input['event_type'] ?? '';
        $guests = max(1, (int)($input['guests'] ?? 1));
        $hours  = max(1, (int)($input['hours'] ?? 0));

        if (!isset(EVENT_TEMPLATES[$type])) {
            http_response_code(400);
            echo json_encode(['error' => 'unknown event_type']);
            exit;
        }

        $tpl = EVENT_TEMPLATES[$type];
        if (!$hours) $hours = $tpl['default_hours'];

        // Najdi produkty v každé kategorii pro doporučení
        $catStmt = $pdo->prepare("SELECT id, nazev FROM kategorie_vyrobku WHERE nazev = :n LIMIT 1");
        $prodStmt = $pdo->prepare("
            SELECT v.id, v.nazev, v.cena_bez_dph, v.hmotnost_g, v.cislo
            FROM vyrobky v
            WHERE v.kategorie_id = :k AND v.aktivni = 1
            ORDER BY v.oblibeny DESC, v.je_novinka DESC, v.id ASC
            LIMIT 6
        ");

        $recipe = [];
        $celkemBezDph = 0;
        foreach ($tpl['recipe'] as $r) {
            [$catName, $perGuest, $jednotka, $label] = $r;
            $catStmt->execute(['n' => $catName]);
            $cat = $catStmt->fetch(PDO::FETCH_ASSOC);

            $totalUnits = ceil($perGuest * $guests);

            $suggestions = [];
            $catTotal = 0;
            if ($cat) {
                $prodStmt->execute(['k' => $cat['id']]);
                $prods = $prodStmt->fetchAll(PDO::FETCH_ASSOC);
                if ($prods) {
                    $perProd = max(1, (int)ceil($totalUnits / count($prods)));
                    foreach ($prods as $p) {
                        $cena = (float)$p['cena_bez_dph'];
                        $subTotal = $perProd * $cena;
                        $catTotal += $subTotal;
                        $suggestions[] = [
                            'vyrobek_id' => (int)$p['id'],
                            'cislo'      => $p['cislo'],
                            'nazev'      => $p['nazev'],
                            'mnozstvi'   => $perProd,
                            'jednotka'   => $jednotka,
                            'cena_per_ks'=> $cena,
                            'subtotal'   => round($subTotal, 2),
                        ];
                    }
                }
            }
            $celkemBezDph += $catTotal;
            $recipe[] = [
                'kategorie'    => $catName,
                'label'        => $label,
                'per_guest'    => $perGuest,
                'total_units'  => $totalUnits,
                'jednotka'     => $jednotka,
                'suggestions'  => $suggestions,
                'cat_subtotal' => round($catTotal, 2),
            ];
        }

        $dph = $celkemBezDph * 0.12;  // 12% snížená pro potraviny
        $celkem = $celkemBezDph + $dph;

        echo json_encode([
            'event_type'  => $type,
            'event_name'  => $tpl['name'],
            'guests'      => $guests,
            'hours'       => $hours,
            'recipe'      => $recipe,
            'totals' => [
                'castka_bez_dph' => round($celkemBezDph, 2),
                'castka_dph'     => round($dph, 2),
                'castka_celkem'  => round($celkem, 2),
                'per_guest'      => $guests > 0 ? round($celkem / $guests, 2) : 0,
            ],
        ]);
        exit;
    }

    // GET — vrať seznam šablon
    $list = [];
    foreach (EVENT_TEMPLATES as $id => $tpl) {
        $list[$id] = [
            'id'   => $id,
            'name' => $tpl['name'],
            'desc' => $tpl['desc'],
            'default_hours' => $tpl['default_hours'],
            'recipe' => array_map(fn($r) => [
                'kategorie' => $r[0],
                'per_guest' => $r[1],
                'jednotka'  => $r[2],
                'label'     => $r[3],
            ], $tpl['recipe']),
        ];
    }
    echo json_encode(['templates' => $list]);

} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}
