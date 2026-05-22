<?php
/**
 * Přehled výroby za období — agregace položek objednávek podle datumu dodání.
 *
 * Parametry:
 *   GET ?od=YYYY-MM-DD&do=YYYY-MM-DD
 *      &mode=souhrn (default) | denni
 *      &format=json (default) | csv
 *
 * Response (souhrn):
 *   - obdobi, polozky[], souhrn
 *
 * Response (denni — pivot výrobky × dny):
 *   - obdobi, dny[]  (seznam YYYY-MM-DD v období)
 *   - vyrobky[]: { vyrobek_id, cislo, nazev, jednotka, mnozstvi_celkem, po_dnech: { 'YYYY-MM-DD': mnozstvi, ... } }
 *   - souhrn_dny: { 'YYYY-MM-DD': celkem_kusu_v_dni, ... }
 *   - souhrn (jako u souhrn módu)
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/_admin_auth.php';
cors_headers();
require_admin();

$pdo = db();

$od     = $_GET['od']     ?? date('Y-m-01');
$do     = $_GET['do']     ?? date('Y-m-t');
$mode   = $_GET['mode']   ?? 'souhrn';
$format = $_GET['format'] ?? 'json';

if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $od)) json_error('Neplatné datum od');
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $do)) json_error('Neplatné datum do');
if ($od > $do) json_error('Datum od musí být před do');

$dni = (int) ((strtotime($do) - strtotime($od)) / 86400 + 1);

// =============================================================
// Souhrnná data (vždy potřebujeme pro souhrn)
// =============================================================
$stmt = $pdo->prepare("
    SELECT
        v.id AS vyrobek_id,
        v.cislo,
        v.nazev,
        j.kod AS jednotka,
        s.sazba AS sazba_dph,
        SUM(op.mnozstvi)                                        AS mnozstvi,
        ROUND(AVG(op.cena_bez_dph), 2)                          AS cena_prumer,
        ROUND(SUM(op.mnozstvi * op.cena_bez_dph), 2)            AS celkem_bez_dph,
        ROUND(SUM(op.mnozstvi * op.cena_bez_dph * op.sazba_dph / 100), 2) AS celkem_dph,
        COUNT(DISTINCT op.objednavka_id)                        AS pocet_objednavek
    FROM objednavky_polozky op
    JOIN objednavky o ON o.id = op.objednavka_id
    JOIN vyrobky v   ON v.id = op.vyrobek_id
    LEFT JOIN jednotky j ON j.id = v.jednotka_id
    LEFT JOIN sazby_dph s ON s.id = v.sazba_dph_id
    WHERE o.stav <> 'zrusena'
      AND o.datum_dodani BETWEEN :od AND :do
    GROUP BY v.id, v.cislo, v.nazev, j.kod, s.sazba
    ORDER BY mnozstvi DESC, v.nazev
");
$stmt->execute(['od' => $od, 'do' => $do]);
$polozky = $stmt->fetchAll(PDO::FETCH_ASSOC);

$celkem_kusu = 0;
$celkem_bez  = 0;
$celkem_dph  = 0;
foreach ($polozky as &$p) {
    $p['celkem_s_dph']  = round($p['celkem_bez_dph'] + $p['celkem_dph'], 2);
    $celkem_kusu += $p['mnozstvi'];
    $celkem_bez  += $p['celkem_bez_dph'];
    $celkem_dph  += $p['celkem_dph'];
}
unset($p);

$stmt = $pdo->prepare("
    SELECT COUNT(DISTINCT o.id)
    FROM objednavky o
    WHERE o.stav <> 'zrusena' AND o.datum_dodani BETWEEN :od AND :do
");
$stmt->execute(['od' => $od, 'do' => $do]);
$unikatnich_objednavek = (int) $stmt->fetchColumn();

$souhrn = [
    'celkem_kusu'           => round($celkem_kusu, 2),
    'celkem_bez_dph'        => round($celkem_bez, 2),
    'celkem_dph'            => round($celkem_dph, 2),
    'celkem_s_dph'          => round($celkem_bez + $celkem_dph, 2),
    'unikatnich_vyrobku'    => count($polozky),
    'unikatnich_objednavek' => $unikatnich_objednavek,
];

// =============================================================
// PIVOT (denní rozpis: výrobky × dny)
// =============================================================
$pivot_data = null;
if ($mode === 'denni' || ($format === 'csv' && $mode === 'denni')) {
    $stmt = $pdo->prepare("
        SELECT v.id AS vyrobek_id, v.cislo, v.nazev, j.kod AS jednotka,
               o.datum_dodani AS den, SUM(op.mnozstvi) AS mnozstvi
        FROM objednavky_polozky op
        JOIN objednavky o ON o.id = op.objednavka_id
        JOIN vyrobky v   ON v.id = op.vyrobek_id
        LEFT JOIN jednotky j ON j.id = v.jednotka_id
        WHERE o.stav <> 'zrusena'
          AND o.datum_dodani BETWEEN :od AND :do
        GROUP BY v.id, v.cislo, v.nazev, j.kod, o.datum_dodani
        ORDER BY v.nazev, o.datum_dodani
    ");
    $stmt->execute(['od' => $od, 'do' => $do]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Sestav seznam dnů v intervalu
    $dny = [];
    $cur = strtotime($od);
    $end = strtotime($do);
    while ($cur <= $end) {
        $dny[] = date('Y-m-d', $cur);
        $cur = strtotime('+1 day', $cur);
    }

    // Pivot
    $vyrobky_pivot = [];
    $souhrn_dny = array_fill_keys($dny, 0);
    foreach ($rows as $r) {
        $vid = (int) $r['vyrobek_id'];
        if (!isset($vyrobky_pivot[$vid])) {
            $vyrobky_pivot[$vid] = [
                'vyrobek_id'      => $vid,
                'cislo'           => $r['cislo'],
                'nazev'           => $r['nazev'],
                'jednotka'        => $r['jednotka'] ?? 'ks',
                'mnozstvi_celkem' => 0,
                'po_dnech'        => [],
            ];
        }
        $mn = (float) $r['mnozstvi'];
        $vyrobky_pivot[$vid]['po_dnech'][$r['den']] = $mn;
        $vyrobky_pivot[$vid]['mnozstvi_celkem'] += $mn;
        $souhrn_dny[$r['den']] += $mn;
    }
    // Seřaď výrobky podle celku desc
    usort($vyrobky_pivot, fn($a, $b) => $b['mnozstvi_celkem'] <=> $a['mnozstvi_celkem']);

    $pivot_data = [
        'dny'        => $dny,
        'vyrobky'    => array_values($vyrobky_pivot),
        'souhrn_dny' => $souhrn_dny,
    ];
}

// =============================================================
// CSV export
// =============================================================
if ($format === 'csv') {
    $base = 'vyroba_' . str_replace('-', '', $od) . '_' . str_replace('-', '', $do);
    $filename = ($mode === 'denni' ? $base . '_denni' : $base) . '.csv';
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');

    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF"); // BOM pro Excel

    if ($mode === 'denni' && $pivot_data) {
        // Index cen z $polozky podle vyrobek_id pro rychlé dohledání
        $ceny_idx = [];
        foreach ($polozky as $p) {
            $ceny_idx[(int) $p['vyrobek_id']] = $p;
        }

        // Pivot CSV: výrobky × dny + ceny
        $hdr = ['Číslo', 'Název', 'Jed.'];
        foreach ($pivot_data['dny'] as $d) {
            $hdr[] = date('j.n.', strtotime($d));
        }
        $hdr[] = 'CELKEM ks';
        $hdr[] = 'Cena/ks Ø';
        $hdr[] = 'Bez DPH';
        $hdr[] = 'DPH';
        $hdr[] = 'S DPH';
        fputcsv($out, $hdr, ';');

        foreach ($pivot_data['vyrobky'] as $v) {
            $row = [$v['cislo'] ?? '', $v['nazev'], $v['jednotka']];
            foreach ($pivot_data['dny'] as $d) {
                $mn = $v['po_dnech'][$d] ?? 0;
                $row[] = $mn > 0 ? number_format($mn, 0, ',', '') : '';
            }
            $row[] = number_format((float) $v['mnozstvi_celkem'], 0, ',', '');

            $cena = $ceny_idx[(int) $v['vyrobek_id']] ?? null;
            $row[] = $cena ? number_format((float) $cena['cena_prumer'], 2, ',', '') : '';
            $row[] = $cena ? number_format((float) $cena['celkem_bez_dph'], 2, ',', '') : '';
            $row[] = $cena ? number_format((float) $cena['celkem_dph'], 2, ',', '') : '';
            $row[] = $cena ? number_format((float) $cena['celkem_s_dph'], 2, ',', '') : '';
            fputcsv($out, $row, ';');
        }

        // Patička: součet po dnech + grand totals cen
        fputcsv($out, [], ';');
        $foot = ['', 'CELKEM', ''];
        foreach ($pivot_data['dny'] as $d) {
            $mn = $pivot_data['souhrn_dny'][$d] ?? 0;
            $foot[] = $mn > 0 ? number_format($mn, 0, ',', '') : '';
        }
        $foot[] = number_format((float) $souhrn['celkem_kusu'], 0, ',', '');
        $foot[] = ''; // Cena/ks Ø nemá smysl sumarizovat
        $foot[] = number_format((float) $souhrn['celkem_bez_dph'], 2, ',', '');
        $foot[] = number_format((float) $souhrn['celkem_dph'], 2, ',', '');
        $foot[] = number_format((float) $souhrn['celkem_s_dph'], 2, ',', '');
        fputcsv($out, $foot, ';');
    } else {
        // Souhrn CSV (jako dřív)
        fputcsv($out, ['Číslo', 'Název', 'Jednotka', 'Množství', 'Cena/ks (Ø)', 'Celkem bez DPH', 'DPH %', 'DPH', 'Celkem s DPH', 'Objednávek'], ';');
        foreach ($polozky as $p) {
            fputcsv($out, [
                $p['cislo'] ?? '',
                $p['nazev'],
                $p['jednotka'] ?? 'ks',
                number_format((float) $p['mnozstvi'], 2, ',', ''),
                number_format((float) $p['cena_prumer'], 2, ',', ''),
                number_format((float) $p['celkem_bez_dph'], 2, ',', ''),
                (int) ($p['sazba_dph'] ?? 0),
                number_format((float) $p['celkem_dph'], 2, ',', ''),
                number_format((float) $p['celkem_s_dph'], 2, ',', ''),
                (int) $p['pocet_objednavek'],
            ], ';');
        }
        fputcsv($out, [], ';');
        fputcsv($out, [
            '', 'CELKEM', '',
            number_format((float) $souhrn['celkem_kusu'], 2, ',', ''),
            '',
            number_format((float) $souhrn['celkem_bez_dph'], 2, ',', ''),
            '',
            number_format((float) $souhrn['celkem_dph'], 2, ',', ''),
            number_format((float) $souhrn['celkem_s_dph'], 2, ',', ''),
            (int) $souhrn['unikatnich_objednavek'],
        ], ';');
    }
    fclose($out);
    exit;
}

// =============================================================
// JSON
// =============================================================
$response = [
    'obdobi'  => ['od' => $od, 'do' => $do, 'dni' => $dni],
    'mode'    => $mode,
    'polozky' => $polozky,
    'souhrn'  => $souhrn,
];
if ($pivot_data) {
    $response['dny']        = $pivot_data['dny'];
    $response['vyrobky']    = $pivot_data['vyrobky'];
    $response['souhrn_dny'] = $pivot_data['souhrn_dny'];
}
json_response($response);
