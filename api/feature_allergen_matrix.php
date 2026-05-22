<?php
/**
 * 🧪 ALERGENOVÁ MATICE — feature 'allergen_matrix' (balíček Lahůdky).
 *
 * GET  /api/feature_allergen_matrix.php          → seznam vyrobků × EU14 alergenů
 * POST /api/feature_allergen_matrix.php          → uložení alergenů pro vyrobek
 *      Body: { vyrobek_id: int, alergeny: ["lepek","mleko",...] }
 *
 * EU14 alergenů (nařízení EU 1169/2011):
 *   lepek, korysi, vejce, ryby, podzemnice, soja, mleko, oresky,
 *   celer, horcice, sezam, sirici, vlcimak, mekkysi
 *
 * Gating: feature_enabled('allergen_matrix') — Lahůdky balíček
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/_admin_auth.php';
require_once __DIR__ . '/_features.php';

header('Content-Type: application/json; charset=UTF-8');
if (function_exists('cors_headers')) cors_headers();

// ─── Gate: vyžaduje balíček Lahůdky ──────────────────────────
if (!feature_enabled('allergen_matrix')) {
    http_response_code(402);
    echo json_encode([
        'error' => 'upsell',
        'feature' => 'allergen_matrix',
        'package' => 'lahudky',
        'message' => 'Tato funkce je součástí balíčku Lahůdky. Pro odemčení kontaktujte dodavatele.',
    ]);
    exit;
}

require_admin();

const EU14_ALLERGENS = [
    'lepek'        => '🌾 Obilniny obsahující lepek',
    'korysi'       => '🦐 Korýši a výrobky z nich',
    'vejce'        => '🥚 Vejce',
    'ryby'         => '🐟 Ryby',
    'podzemnice'   => '🥜 Podzemnice olejná (arašídy)',
    'soja'         => '🌱 Sójové boby',
    'mleko'        => '🥛 Mléko a výrobky z něj (vč. laktózy)',
    'oresky'       => '🌰 Skořápkové plody',
    'celer'        => '🥬 Celer',
    'horcice'      => '🌶️ Hořčice',
    'sezam'        => '🌻 Sezamová semena',
    'sirici'       => '🍷 Oxid siřičitý a siřičitany',
    'vlcimak'      => '🌿 Vlčí bob (lupina)',
    'mekkysi'      => '🐚 Měkkýši',
];

try {
    $pdo = db();

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        // Save allergens for a single product
        $input = json_decode(file_get_contents('php://input'), true) ?? [];
        $vyrobek_id = (int)($input['vyrobek_id'] ?? 0);
        $alergeny = $input['alergeny'] ?? [];

        if (!$vyrobek_id) {
            http_response_code(400);
            echo json_encode(['error' => 'missing vyrobek_id']);
            exit;
        }

        // Validate allergen keys
        $validAlergeny = array_values(array_intersect((array)$alergeny, array_keys(EU14_ALLERGENS)));
        $alergenyStr = implode(',', $validAlergeny);

        $pdo->prepare("UPDATE vyrobky SET alergeny = :a WHERE id = :id")
            ->execute(['a' => $alergenyStr, 'id' => $vyrobek_id]);

        echo json_encode([
            'ok' => true,
            'vyrobek_id' => $vyrobek_id,
            'alergeny' => $validAlergeny,
            'count' => count($validAlergeny),
        ]);
        exit;
    }

    // GET — vrátí všechny vyrobky × alergeny
    $stmt = $pdo->query("
        SELECT v.id, v.nazev, v.cislo, v.alergeny, k.nazev AS kategorie_nazev, k.ikona AS kategorie_ikona
        FROM vyrobky v
        LEFT JOIN kategorie_vyrobku k ON k.id = v.kategorie_id
        WHERE v.aktivni = 1
        ORDER BY k.poradi, v.nazev
    ");
    $vyrobky = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Rozparsuj alergeny string → array
    foreach ($vyrobky as &$v) {
        $v['alergeny_arr'] = $v['alergeny']
            ? array_values(array_filter(array_map('trim', explode(',', $v['alergeny']))))
            : [];
    }
    unset($v);

    // Statistiky
    $totalProducts = count($vyrobky);
    $allergenStats = [];
    foreach (EU14_ALLERGENS as $key => $label) {
        $count = 0;
        foreach ($vyrobky as $v) {
            if (in_array($key, $v['alergeny_arr'], true)) $count++;
        }
        $allergenStats[$key] = ['count' => $count, 'label' => $label];
    }

    echo json_encode([
        'allergens' => EU14_ALLERGENS,
        'vyrobky'   => $vyrobky,
        'stats'     => [
            'total_products'         => $totalProducts,
            'products_with_allergens' => count(array_filter($vyrobky, fn($v) => !empty($v['alergeny_arr']))),
            'allergen_distribution'  => $allergenStats,
        ],
    ]);

} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}
