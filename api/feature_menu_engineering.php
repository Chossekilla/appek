<?php
/**
 * 🍽️ MENU ENGINEERING — feature 'menu_engineering' (balíček Restaurace).
 *
 * Analýza ziskovosti jídel: prodej (popularita) × marže = matrix.
 *
 *   STARS    (vysoká marže + vysoký prodej) — KEEP & PROMOTE
 *   PLOWHORSES (nízká marže + vysoký prodej) — zvýšit cenu / snížit náklady
 *   PUZZLES  (vysoká marže + nízký prodej)  — propagovat
 *   DOGS     (nízká marže + nízký prodej)   — odstranit
 *
 * GET /api/feature_menu_engineering.php
 *
 * Gating: feature_enabled('menu_engineering') — Restaurace balíček
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/_admin_auth.php';
require_once __DIR__ . '/_features.php';

header('Content-Type: application/json; charset=UTF-8');
if (function_exists('cors_headers')) cors_headers();

if (!feature_enabled('menu_engineering')) {
    http_response_code(402);
    echo json_encode([
        'error' => 'upsell',
        'feature' => 'menu_engineering',
        'package' => 'restaurace',
        'message' => 'Menu engineering je součástí balíčku Restaurace.',
    ]);
    exit;
}

require_admin();

try {
    $pdo = db();

    // Sběr dat za posledních 30 dní z objednávek
    $days = (int)($_GET['days'] ?? 30);
    $days = max(7, min(365, $days));

    $sql = "
        SELECT
            v.id,
            v.cislo,
            v.nazev,
            v.cena_bez_dph,
            v.hmotnost_g,
            v.kategorie_id,
            k.nazev AS kategorie_nazev,
            k.ikona AS kategorie_ikona,
            COALESCE(SUM(op.mnozstvi), 0) AS sold_qty,
            COALESCE(SUM(op.mnozstvi * op.cena_bez_dph), 0) AS revenue
        FROM vyrobky v
        LEFT JOIN kategorie_vyrobku k ON k.id = v.kategorie_id
        LEFT JOIN objednavky_polozky op ON op.vyrobek_id = v.id
        LEFT JOIN objednavky o ON o.id = op.objednavka_id
            AND o.datum_objednani >= DATE_SUB(CURDATE(), INTERVAL :d DAY)
            AND o.stav IN ('doruceno', 'expedice', 'priprava')
        WHERE v.aktivni = 1
        GROUP BY v.id
        HAVING sold_qty > 0
        ORDER BY revenue DESC
    ";
    $stmt = $pdo->prepare($sql);
    $stmt->execute(['d' => $days]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if (!$rows) {
        echo json_encode([
            'period_days' => $days,
            'items' => [],
            'summary' => ['stars' => 0, 'plowhorses' => 0, 'puzzles' => 0, 'dogs' => 0],
            'message' => 'Žádná data za posledních ' . $days . ' dní — vytvoř objednávky abys viděl analýzu.',
        ]);
        exit;
    }

    // Compute medians (popularity vs profitability)
    $sortedSold = array_map(fn($r) => (float)$r['sold_qty'], $rows);
    sort($sortedSold);
    $medianSold = $sortedSold[count($sortedSold) >> 1];

    // 🆕 v2.9.279 — Použít vyrobni_cena_per_kus z poslední kalkulace_historie pro každý výrobek
    // Fallback: pokud nemá kalkulaci, default 60% marže (typická restaurace)
    $kalkCache = [];
    try {
        // Načti POSLEDNÍ kalkulaci per výrobek (vytvoreno DESC)
        $vyrobekIds = array_filter(array_map(fn($r) => (int)$r['id'], $rows));
        if (!empty($vyrobekIds)) {
            $place = implode(',', $vyrobekIds);
            $kalkRows = $pdo->query("
                SELECT vyrobek_id, vyrobni_cena_per_kus, vytvoreno
                FROM kalkulace_historie
                WHERE vyrobek_id IN ($place)
                  AND vyrobni_cena_per_kus IS NOT NULL
                ORDER BY vyrobek_id, vytvoreno DESC
            ")->fetchAll();
            // Vezmi POSLEDNÍ kalkulaci per vyrobek_id (první výskyt v sorted DESC)
            foreach ($kalkRows as $k) {
                $vid = (int) $k['vyrobek_id'];
                if (!isset($kalkCache[$vid])) {
                    $kalkCache[$vid] = (float) $k['vyrobni_cena_per_kus'];
                }
            }
        }
    } catch (Throwable $e) { /* kalkulace_historie možná neexistuje */ }

    foreach ($rows as &$r) {
        $cena = (float) $r['cena_bez_dph'];
        $vyrobiCena = $kalkCache[(int) $r['id']] ?? null;

        if ($vyrobiCena !== null && $vyrobiCena > 0 && $cena > $vyrobiCena) {
            // Použij skutečnou kalkulaci
            $r['margin_kc']  = $cena - $vyrobiCena;
            $r['margin_pct'] = ($r['margin_kc'] / $cena) * 100;
            $r['cost_source'] = 'kalkulace';
            $r['vyrobni_cena'] = $vyrobiCena;
        } else {
            // Default 60% marže
            $r['margin_pct'] = 60.0;
            $r['margin_kc']  = $cena * 0.60;
            $r['cost_source'] = 'odhad';
            $r['vyrobni_cena'] = $cena * 0.40;
        }
        $r['profit'] = (float) $r['sold_qty'] * $r['margin_kc'];
    }
    unset($r);

    $sortedMargin = array_map(fn($r) => (float)$r['margin_pct'], $rows);
    sort($sortedMargin);
    $medianMargin = $sortedMargin[count($sortedMargin) >> 1];

    $categories = [];
    foreach ($rows as &$r) {
        $hiPop = (float)$r['sold_qty']    >= $medianSold;
        $hiMar = (float)$r['margin_pct']  >= $medianMargin;

        if ($hiPop && $hiMar)       $r['category'] = 'star';
        elseif ($hiPop && !$hiMar)  $r['category'] = 'plowhorse';
        elseif (!$hiPop && $hiMar)  $r['category'] = 'puzzle';
        else                        $r['category'] = 'dog';

        $r['recommendation'] = match ($r['category']) {
            'star'      => '⭐ Udrž & propaguj — top performer',
            'plowhorse' => '🐴 Zvyš cenu / sniž food cost',
            'puzzle'    => '🧩 Marketing potřeba — vysoká marže, malý prodej',
            'dog'       => '🗑️ Zvaž odstranění z menu',
        };

        $categories[$r['category']] = ($categories[$r['category']] ?? 0) + 1;
    }
    unset($r);

    echo json_encode([
        'period_days'  => $days,
        'median_sold'  => $medianSold,
        'median_margin'=> $medianMargin,
        'items'        => $rows,
        'summary'      => [
            'star'      => $categories['star']      ?? 0,
            'plowhorse' => $categories['plowhorse'] ?? 0,
            'puzzle'    => $categories['puzzle']    ?? 0,
            'dog'       => $categories['dog']       ?? 0,
            'total'     => count($rows),
        ],
        'total_revenue' => array_sum(array_column($rows, 'revenue')),
        'total_profit'  => array_sum(array_column($rows, 'profit')),
    ]);

} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}
