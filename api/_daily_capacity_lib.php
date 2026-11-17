<?php
/**
 * 📅 DENNÍ KAPACITA PEČENÍ — sdílené funkce (admin_daily_capacity.php, admin_cake_configurator.php).
 * 🐛 v3.0.644 — vyčleněno z admin_daily_capacity.php, aby šla kapacita kontrolovat i při vytvoření objednávky.
 */

// Defaultní limity (jeden řádek v nastaveni JSON)
function default_capacity(PDO $pdo): array {
    try {
        $raw = $pdo->query("SELECT hodnota FROM nastaveni WHERE klic = 'default_daily_capacity'")->fetchColumn();
        if ($raw) {
            $d = json_decode($raw, true);
            if (is_array($d)) return $d;
        }
    } catch (Throwable $e) {}
    return ['max_dortu' => 8, 'max_chlebicku' => 200, 'max_zakousku' => 80];
}

// Spočítá obsazenost pro daný den (kolik dortů už je objednáno)
function get_occupancy(PDO $pdo, string $date): array {
    $out = ['dortu' => 0, 'chlebicku' => 0, 'zakousku' => 0];
    try {
        // 🆕 v3.0.295 — primárně podle EXPLICITNÍHO vyrobky.obor (provázání s balíčky);
        //   fallback na starou heuristiku z názvu kategorie pro výrobky bez nastaveného oboru.
        $hasObor = false;
        try { $hasObor = in_array('obor', $pdo->query("SHOW COLUMNS FROM vyrobky")->fetchAll(PDO::FETCH_COLUMN), true); } catch (Throwable $e) {}
        $oborSel = $hasObor ? 'v.obor' : 'NULL';
        $rows = $pdo->prepare("
            SELECT $oborSel AS obor, k.nazev AS kat, COALESCE(SUM(op.mnozstvi), 0) AS qty
            FROM objednavky o
            JOIN objednavky_polozky op ON op.objednavka_id = o.id
            LEFT JOIN vyrobky v ON v.id = op.vyrobek_id
            LEFT JOIN kategorie_vyrobku k ON k.id = v.kategorie_id
            WHERE o.datum_dodani = :d AND o.stav != 'zrusena'
              AND (o.puvod IS NULL OR o.puvod <> 'dort')   -- 🐛 v3.0.644 — dorty z konfigurátoru počítá dotaz níže (1 = 1 dort)
            GROUP BY obor, k.nazev
        ");
        $rows->execute(['d' => $date]);
        foreach ($rows->fetchAll() as $r) {
            $qty = (float) $r['qty'];
            $obor = $r['obor'] ?? '';
            if ($obor === 'dort')      { $out['dortu']     += $qty; continue; }
            if ($obor === 'chlebicek') { $out['chlebicku'] += $qty; continue; }
            if ($obor === 'zakusek')   { $out['zakousku']  += $qty; continue; }
            // bez oboru → fallback heuristika z názvu kategorie
            $name = mb_strtolower($r['kat'] ?? '');
            if (str_contains($name, 'dort'))      $out['dortu']     += $qty;
            elseif (str_contains($name, 'chlebíč') || str_contains($name, 'chlebic')) $out['chlebicku'] += $qty;
            elseif (str_contains($name, 'zákus')  || str_contains($name, 'zakus') || str_contains($name, 'koláč'))  $out['zakousku']  += $qty;
        }
        // 🆕 v3.0.295 — objednávky z dort konfigurátoru (puvod='dort') = 1 dort každá.
        // 🐛 v3.0.644 — jejich řádky dnes nesou vyrobek_id dortu (obor='dort', mnozstvi = NÁSOBIČ velikosti
        //   0,6–5) → dřív se započetly 2× (násobič + 1): 4 střední dorty = 9,6/8 „plno", patrový = 6 míst.
        //   Teď jsou v součtu výše vyřazené a každá objednávka z konfigurátoru = právě 1 dort.
        $cfg = $pdo->prepare("SELECT COUNT(*) FROM objednavky WHERE puvod = 'dort' AND datum_dodani = :d AND stav != 'zrusena'");
        $cfg->execute(['d' => $date]);
        $out['dortu'] += (float) $cfg->fetchColumn();
    } catch (Throwable $e) { /* tabulky možná chybí */ }
    return $out;
}

/**
 * 🐛 v3.0.644 — kontrola kapacity dne před vytvořením objednávky (konfigurátor dortů).
 * Vrací null (v pořádku / kapacitní plán se nepoužívá) nebo text důvodu (den zavřený / plno).
 */
function daily_capacity_problem(PDO $pdo, string $date, float $plusDortu = 1): ?string {
    try {
        $st = $pdo->prepare("SELECT * FROM daily_capacity WHERE date = ?");
        $st->execute([$date]);
        $row = $st->fetch() ?: [];
    } catch (Throwable $e) { return null; }   // tabulka neexistuje = kapacitní plán se nepoužívá
    $datumTxt = date('j. n. Y', strtotime($date));
    if ((int) ($row['zavreno'] ?? 0) === 1) {
        return "Den {$datumTxt} je v kapacitním plánu označený jako zavřený" . (!empty($row['poznamka']) ? ' (' . $row['poznamka'] . ')' : '') . '.';
    }
    $max = $row['max_dortu'] ?? (default_capacity($pdo)['max_dortu'] ?? null);
    if ($max === null || $max === '') return null;
    $occ = get_occupancy($pdo, $date)['dortu'];
    if ($occ + $plusDortu > (float) $max + 0.0001) {
        return "Kapacita dortů na {$datumTxt} je vyčerpaná (objednáno " . rtrim(rtrim(number_format($occ, 1, ',', ''), '0'), ',') . " z max. " . (int) $max . ').';
    }
    return null;
}
