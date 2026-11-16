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
        if (empty($row['mena']) && !empty($row['id'])) mena_snapshot_doklad($pdo, $tabulka, (int) $row['id']);
        return mena_doklad_ro($pdo, $row);
    }

    /** Totéž co mena_doklad(), ale BEZ zápisu snapshotu (pro seznamy — GET nic nezapisuje).
     *  Doklad bez snapshotu = měna, ve které by se vystavil teď (stejně jako ho vytiskne PDF). */
    function mena_doklad_ro(PDO $pdo, array $row): array {
        if (empty($row['mena'])) {
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
     * Doklad v cizí měně jako „nativní" doklad v ní — STEJNÝ výpočet jako účetní programy
     * (ověřeno živě proti FlexiBee): jednotková cena zaokrouhlená na 2 místa, řádek = round(mn × cena),
     * DPH po řádcích round(řádek × sazba), součty po sazbách. Kč (rekapitulace §29 ZDPH, export) = ×kurz.
     * $polozky = [[mnozstvi, cena (v uložené měně), sazba], …] ve stejném pořadí jako na dokladu.
     */
    function mena_doklad_polozky(array $polozky, array $m): array {
        $k = (float) $m['k_doklad']; $kurz = (float) $m['mena_kurz'];
        $out = []; $rozpis = [];
        foreach ($polozky as $i => [$mn, $cena, $sazba]) {
            $c = round((float) $cena * $k, 2);
            $z = round((float) $mn * $c, 2);
            $d = round($z * (float) $sazba / 100, 2);
            $out[$i] = ['cena' => $c, 'zaklad' => $z, 'dph' => $d];
            $key = (string) (0 + (float) $sazba);
            if (!isset($rozpis[$key])) $rozpis[$key] = ['bez' => 0.0, 'dph' => 0.0];
            $rozpis[$key]['bez'] = round($rozpis[$key]['bez'] + $z, 2);
            $rozpis[$key]['dph'] = round($rozpis[$key]['dph'] + $d, 2);
        }
        $czk = [];
        foreach ($rozpis as $key => $r) $czk[$key] = ['bez' => round($r['bez'] * $kurz, 2), 'dph' => round($r['dph'] * $kurz, 2)];
        $bez = round(array_sum(array_column($rozpis, 'bez')), 2);
        $dph = round(array_sum(array_column($rozpis, 'dph')), 2);
        return ['polozky' => $out, 'rozpis' => $rozpis, 'rozpis_czk' => $czk, 'bez' => $bez, 'dph' => $dph, 'celkem' => round($bez + $dph, 2)];
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

    /**
     * 💱 v3.0.642 — částka KONKRÉTNÍHO dokladu v jeho měně pro seznamy/detaily (= jako na PDF).
     * Doplní do každého řádku $rows (podle $idKey):
     *   doc_mena  — kód měny dokladu (CZK u starých dokladů, i když se teď prodává v EUR)
     *   doc_celkem, doc_bez, doc_dph — v měně dokladu (CZK = castka_* beze změny)
     *   doc_k     — násobitel uložená částka → měna dokladu (1 u Kč)
     *   doc_uhrazeno (jen faktury)
     * Cizí měna = mena_doklad_polozky nad položkami (shodně s api/faktura.php a dodaci_list.php).
     * Výkon: 1 dotaz na hlavičky; položky se načítají DÁVKOVĚ jen pro doklady s měnou ≠ CZK.
     * $typ: 'faktury' | 'objednavky' | 'dodaci_listy'
     */
    function mena_doc_pole(PDO $pdo, string $typ, array &$rows, string $idKey = 'id'): void {
        if (!$rows) return;
        $ids = [];
        foreach ($rows as $r) { $i = (int) ($r[$idKey] ?? 0); if ($i > 0) $ids[$i] = true; }
        if (!$ids) return;
        $in = implode(',', array_keys($ids));
        try {
            if ($typ === 'faktury') {
                $meta = $pdo->query("SELECT id, mena, mena_kurz, mena_zaklad, rucni, castka_celkem, castka_bez_dph, castka_dph, castka_uhrazeno
                                     FROM faktury WHERE id IN ($in)")->fetchAll(PDO::FETCH_ASSOC);
            } elseif ($typ === 'objednavky') {
                $meta = $pdo->query("SELECT id, mena, mena_kurz, mena_zaklad, castka_celkem, castka_bez_dph, castka_dph
                                     FROM objednavky WHERE id IN ($in)")->fetchAll(PDO::FETCH_ASSOC);
            } elseif ($typ === 'dodaci_listy') {
                // DL k objednávce dědí měnu objednávky (jako dodaci_list.php), ruční DL má vlastní snapshot
                $meta = $pdo->query("SELECT dl.id, dl.castka_celkem, dl.objednavka_id,
                                            IF(dl.objednavka_id IS NULL, dl.mena, o.mena) AS mena,
                                            IF(dl.objednavka_id IS NULL, dl.mena_kurz, o.mena_kurz) AS mena_kurz,
                                            IF(dl.objednavka_id IS NULL, dl.mena_zaklad, o.mena_zaklad) AS mena_zaklad
                                     FROM dodaci_listy dl LEFT JOIN objednavky o ON o.id = dl.objednavka_id
                                     WHERE dl.id IN ($in)")->fetchAll(PDO::FETCH_ASSOC);
            } else return;
        } catch (Throwable $e) { error_log("mena_doc_pole $typ: " . $e->getMessage()); return; }

        $info = []; $cizi = [];
        foreach ($meta as $h) {
            $m = mena_doklad_ro($pdo, $h);
            $info[(int) $h['id']] = ['h' => $h, 'm' => $m];
            if ($m['mena'] !== 'CZK') $cizi[(int) $h['id']] = $h;
        }

        // Položky jen pro cizoměnové doklady — dávkově (žádné N+1)
        $pol = [];   // id dokladu => [[mn, cena, sazba], …]
        if ($cizi) {
            try { $pol = mena_doc_polozky_davka($pdo, $typ, $cizi); }
            catch (Throwable $e) { error_log("mena_doc_pole polozky $typ: " . $e->getMessage()); }
        }

        $out = [];
        foreach ($info as $id => ['h' => $h, 'm' => $m]) {
            $cel = round((float) ($h['castka_celkem'] ?? 0), 2);
            if ($m['mena'] === 'CZK') {
                $o = ['doc_mena' => 'CZK', 'doc_k' => 1.0, 'doc_celkem' => $cel,
                      'doc_bez' => array_key_exists('castka_bez_dph', $h) ? round((float) $h['castka_bez_dph'], 2) : null,
                      'doc_dph' => array_key_exists('castka_dph', $h) ? round((float) $h['castka_dph'], 2) : null];
                if ($typ === 'faktury') $o['doc_uhrazeno'] = round((float) $h['castka_uhrazeno'], 2);
            } else {
                $calc = mena_doklad_polozky($pol[$id] ?? [], $m);
                $o = ['doc_mena' => $m['mena'], 'doc_k' => (float) $m['k_doklad'], 'doc_celkem' => $calc['celkem'],
                      'doc_bez' => $calc['bez'], 'doc_dph' => $calc['dph']];
                if ($typ === 'faktury') {
                    // Plně uhrazená = celá částka dokladu (castka_uhrazeno je v uložené měně → ÷kurz by ujel o centy)
                    $uh = (float) $h['castka_uhrazeno'];
                    $o['doc_uhrazeno'] = ($uh >= (float) $h['castka_celkem'] - 0.005 && $uh > 0)
                        ? $calc['celkem'] : round($uh * (float) $m['k_doklad'], 2);
                }
            }
            $out[$id] = $o;
        }
        foreach ($rows as &$r) {
            $i = (int) ($r[$idKey] ?? 0);
            if (isset($out[$i])) $r = array_merge($r, $out[$i]);
        }
        unset($r);
    }

    /** Dávkové načtení položek [[mn, cena, sazba]] pro doklady $hlavicky (id => řádek) — stejné zdroje jako PDF. */
    function mena_doc_polozky_davka(PDO $pdo, string $typ, array $hlavicky): array {
        $pol = [];
        $add = function (array $rows) use (&$pol) {
            foreach ($rows as $p) $pol[(int) $p['doc_id']][] = [(float) $p['mnozstvi'], (float) $p['cena_bez_dph'], (float) ($p['sazba_dph'] ?? 0)];
        };
        $ids = implode(',', array_map('intval', array_keys($hlavicky)));
        if ($typ === 'objednavky') {
            $add($pdo->query("SELECT objednavka_id AS doc_id, mnozstvi, cena_bez_dph, sazba_dph FROM objednavky_polozky
                              WHERE objednavka_id IN ($ids) ORDER BY objednavka_id, id")->fetchAll(PDO::FETCH_ASSOC));
        } elseif ($typ === 'dodaci_listy') {
            $add($pdo->query("SELECT dodaci_list_id AS doc_id, mnozstvi, cena_bez_dph, sazba_dph FROM dodaci_list_polozky
                              WHERE dodaci_list_id IN ($ids) ORDER BY dodaci_list_id, id")->fetchAll(PDO::FETCH_ASSOC));
        } elseif ($typ === 'faktury') {
            // = nacti_fakturu() v faktura.php: ruční → faktura_polozky; z DL → dodaci_list_polozky; fallback objednavky_polozky
            $rucni = []; $zDl = [];
            foreach ($hlavicky as $id => $h) { if (!empty($h['rucni'])) $rucni[] = (int) $id; else $zDl[] = (int) $id; }
            if ($rucni) {
                $add($pdo->query("SELECT faktura_id AS doc_id, mnozstvi, cena_bez_dph, sazba_dph FROM faktura_polozky
                                  WHERE faktura_id IN (" . implode(',', $rucni) . ") ORDER BY faktura_id, poradi, id")->fetchAll(PDO::FETCH_ASSOC));
            }
            if ($zDl) {
                $add($pdo->query("SELECT fdl.faktura_id AS doc_id, dlp.mnozstvi, dlp.cena_bez_dph, dlp.sazba_dph
                                  FROM faktury_dodaci_listy fdl
                                  JOIN dodaci_listy dl ON dl.id = fdl.dodaci_list_id
                                  JOIN dodaci_list_polozky dlp ON dlp.dodaci_list_id = dl.id
                                  WHERE fdl.faktura_id IN (" . implode(',', $zDl) . ")")->fetchAll(PDO::FETCH_ASSOC));
                $bez = array_values(array_filter($zDl, fn($i) => empty($pol[$i])));
                if ($bez) {
                    $add($pdo->query("SELECT fdl.faktura_id AS doc_id, p.mnozstvi, p.cena_bez_dph, p.sazba_dph
                                      FROM faktury_dodaci_listy fdl
                                      JOIN dodaci_listy dl ON dl.id = fdl.dodaci_list_id
                                      JOIN objednavky o ON o.id = dl.objednavka_id
                                      JOIN objednavky_polozky p ON p.objednavka_id = o.id
                                      JOIN vyrobky v ON v.id = p.vyrobek_id
                                      WHERE fdl.faktura_id IN (" . implode(',', $bez) . ")")->fetchAll(PDO::FETCH_ASSOC));
                }
            }
        }
        return $pol;
    }
}
