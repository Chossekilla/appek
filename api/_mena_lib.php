<?php
/**
 * 💱 Měna prodeje + měna dokladů (v3.0.640).
 *
 * Config v nastaveni.mena_config_json (spravuje admin_mena.php):
 *   kod        — cílová měna prodeje (CZK/EUR/…)
 *   kurz       — kolik Kč za 1 jednotku `kod` (ČNB konvence)
 *   zobrazeni  — 'kc' | 'mena' (prodej zobrazený v cílové měně)
 *   zakladni   — v jaké měně jsou ULOŽENÉ částky v DB. 'CZK' dokud neproběhl trvalý přepočet
 *                ceníku; po přepočtu = `kod` (pak se už nedělí kurzem → žádný dvojí přepočet).
 *
 * Doklady (faktura, dobropis, dodací list, objednávka) mají snapshot:
 *   mena        — měna dokladu
 *   mena_kurz   — Kč za 1 jednotku `mena` (pro rekapitulaci DPH v Kč dle §29 ZDPH)
 *   mena_zaklad — měna, ve které jsou částky dokladu uložené v DB
 * Snapshot se zapíše při vystavení (mena_snapshot_doklad) nebo nejpozději při prvním tisku;
 * staré doklady dostaly při migraci CZK → pozdější přepnutí měny/kurzu je nezmění.
 */

if (!defined('MENA_PODPOROVANE')) {
    define('MENA_PODPOROVANE', ['CZK' => 'Kč', 'EUR' => '€', 'USD' => '$', 'GBP' => '£', 'PLN' => 'zł', 'HUF' => 'Ft', 'CHF' => 'CHF']);
}

if (!function_exists('mena_config_load')) {
    function mena_config_load(PDO $pdo): array {
        $def = ['kod' => 'CZK', 'kurz' => 1.0, 'zobrazeni' => 'kc', 'dual_doklady' => false, 'kurz_zdroj' => 'rucni', 'zakladni' => 'CZK'];
        try {
            $st = $pdo->prepare("SELECT hodnota FROM nastaveni WHERE klic = 'mena_config_json'");
            $st->execute();
            $raw = $st->fetchColumn();
            $cfg = $raw ? json_decode($raw, true) : null;
            if (!is_array($cfg)) return mena_config_finish($def);
            $out = array_merge($def, $cfg);
            $out['kod']  = strtoupper((string) $out['kod']);
            if (!isset(MENA_PODPOROVANE[$out['kod']])) $out['kod'] = 'CZK';
            $out['kurz'] = max(0.0001, (float) $out['kurz']);
            $out['zobrazeni'] = $out['zobrazeni'] === 'mena' ? 'mena' : 'kc';
            $out['dual_doklady'] = !empty($out['dual_doklady']);
            $out['kurz_zdroj'] = ($out['kurz_zdroj'] ?? 'rucni') === 'cnb' ? 'cnb' : 'rucni';
            $out['zakladni'] = strtoupper((string) ($out['zakladni'] ?? 'CZK'));
            if (!isset(MENA_PODPOROVANE[$out['zakladni']])) $out['zakladni'] = 'CZK';
            // Po trvalém přepočtu je ceník v cílové měně → prodej se v ní i zobrazuje.
            if ($out['zakladni'] !== 'CZK') { $out['kod'] = $out['zakladni']; $out['zobrazeni'] = 'mena'; }
            if ($out['kod'] === 'CZK') { $out['kurz'] = 1.0; $out['zobrazeni'] = 'kc'; }
            return mena_config_finish($out);
        } catch (Throwable $e) { return mena_config_finish($def); }
    }

    /** Doplní odvozená pole: symbol + delitel (čím dělit uloženou částku pro zobrazení). */
    function mena_config_finish(array $c): array {
        $c['symbol']  = MENA_PODPOROVANE[$c['kod']] ?? $c['kod'];
        $zobrazujeMenu = $c['zobrazeni'] === 'mena' && $c['kod'] !== 'CZK';
        $c['delitel'] = ($zobrazujeMenu && $c['zakladni'] === 'CZK') ? (float) $c['kurz'] : 1.0;
        return $c;
    }

    /** Měna, ve které se má vystavit NOVÝ doklad (= jak se právě prodává). */
    function mena_pro_novy_doklad(PDO $pdo): array {
        $c = mena_config_load($pdo);
        if ($c['zobrazeni'] === 'mena' && $c['kod'] !== 'CZK') {
            return ['mena' => $c['kod'], 'mena_kurz' => (float) $c['kurz'], 'mena_zaklad' => $c['zakladni']];
        }
        return ['mena' => 'CZK', 'mena_kurz' => 1.0, 'mena_zaklad' => 'CZK'];
    }

    /** Zapíše snapshot měny na doklad, pokud ještě žádný nemá. Tabulky: faktury|objednavky|dodaci_listy. */
    function mena_snapshot_doklad(PDO $pdo, string $tabulka, int $id): void {
        if (!in_array($tabulka, ['faktury', 'objednavky', 'dodaci_listy'], true) || $id <= 0) return;
        try {
            $m = mena_pro_novy_doklad($pdo);
            $pdo->prepare("UPDATE `$tabulka` SET mena = ?, mena_kurz = ?, mena_zaklad = ? WHERE id = ? AND mena IS NULL")
                ->execute([$m['mena'], $m['mena_kurz'], $m['mena_zaklad'], $id]);
        } catch (Throwable $e) { error_log("mena_snapshot_doklad $tabulka#$id: " . $e->getMessage()); }
    }

    /**
     * Měna dokladu z načteného řádku (lazy snapshot, když chybí).
     * → ['mena','mena_kurz','mena_zaklad','symbol',
     *    'k_doklad' (násobitel uložená částka → měna dokladu), 'k_czk' (uložená částka → Kč)]
     */
    function mena_doklad(PDO $pdo, string $tabulka, array $row): array {
        if (empty($row['mena'])) {
            if (!empty($row['id'])) mena_snapshot_doklad($pdo, $tabulka, (int) $row['id']);
            $m = mena_pro_novy_doklad($pdo);
        } else {
            $m = ['mena' => strtoupper((string) $row['mena']),
                  'mena_kurz' => max(0.0001, (float) ($row['mena_kurz'] ?? 1)),
                  'mena_zaklad' => strtoupper((string) (($row['mena_zaklad'] ?? '') ?: 'CZK'))];
        }
        if (!isset(MENA_PODPOROVANE[$m['mena']])) $m['mena'] = 'CZK';
        if ($m['mena'] === 'CZK') { $m['mena_kurz'] = 1.0; $m['mena_zaklad'] = 'CZK'; }
        $kurz = (float) $m['mena_kurz'];
        $m['k_doklad'] = ($m['mena_zaklad'] === $m['mena']) ? 1.0 : 1.0 / $kurz;   // uloženo v Kč → ÷kurz
        $m['k_czk']    = ($m['mena_zaklad'] === 'CZK') ? 1.0 : $kurz;               // uloženo v EUR → ×kurz
        $m['symbol']   = MENA_PODPOROVANE[$m['mena']];
        return $m;
    }

    /**
     * Rozpis DPH {sazba:{bez,dph}} (v uložené měně) → měna dokladu, s haléřovým/centovým
     * zbytkem v sazbě s největším základem, aby Σ rozpisu = celkem dokladu přesně.
     */
    function mena_prevod_rozpisu(array $rozpis, float $k, float $bezCelkem, float $dphCelkem): array {
        $out = [];
        foreach ($rozpis as $s => $r) $out[$s] = ['bez' => round($r['bez'] * $k, 2), 'dph' => round($r['dph'] * $k, 2)];
        if ($out) {
            $maxKey = null; $maxBez = -INF;
            foreach ($out as $s => $r) { if (abs($r['bez']) > $maxBez) { $maxBez = abs($r['bez']); $maxKey = $s; } }
            $out[$maxKey]['bez'] = round($out[$maxKey]['bez'] + round($bezCelkem * $k, 2) - array_sum(array_column($out, 'bez')), 2);
            $out[$maxKey]['dph'] = round($out[$maxKey]['dph'] + round($dphCelkem * $k, 2) - array_sum(array_column($out, 'dph')), 2);
        }
        return $out;
    }
}
