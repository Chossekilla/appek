<?php
/**
 * Generování objednávek z opakujících se pravidel.
 *
 * Hlavní logika: pro daný cílový datum projde všechna aktivní pravidla
 * a vytvoří objednávky pokud se má v ten den spustit (dle frekvence + dnů v týdnu).
 *
 * Volá se z:
 *   - cron_recurring.php (denní cron)
 *   - admin_recurring.php?action=spustit_ted (manuální)
 */

require_once __DIR__ . '/config.php';

function recurring_should_run(array $rule, string $datum): bool {
    $dz = $rule['datum_zacatku'];
    $dk = $rule['datum_konce'];
    if ($datum < $dz) return false;
    if ($dk && $datum > $dk) return false;
    if ((int) $rule['aktivni'] !== 1) return false;

    $den = (int) date('N', strtotime($datum));   // 1 = pondělí, 7 = neděle
    $dnyAktivni = array_filter(array_map('intval', explode(',', $rule['dny_v_tydnu'] ?? '')));

    switch ($rule['frekvence']) {
        case 'denne':
            return true;
        case 'tydne':
            return in_array($den, $dnyAktivni, true);
        case 'dvouty':
            // Každý druhý týden: spočítáme týden od datum_zacatku
            $tydny = floor((strtotime($datum) - strtotime($dz)) / (7 * 86400));
            return in_array($den, $dnyAktivni, true) && ($tydny % 2 === 0);
        case 'mesicne':
            // První den měsíce, kde připadne na zaškrtnutý den
            $denVMesici = (int) date('j', strtotime($datum));
            return in_array($den, $dnyAktivni, true) && $denVMesici <= 7; // první výskyt v měsíci
    }
    return false;
}

/**
 * Vygeneruje objednávky z opakujících pravidel pro daný cílový datum.
 * Vrací statistiku: ['vytvoreno' => N, 'preskoceno' => N, 'detaily' => [...]]
 */
function recurring_generate(PDO $pdo, string $datum_dodani): array {
    $stats = ['vytvoreno' => 0, 'preskoceno' => 0, 'chyby' => 0, 'detaily' => []];

    $stmt = $pdo->query("SELECT * FROM recurring_orders WHERE aktivni = 1");
    $rules = $stmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($rules as $rule) {
        if (!recurring_should_run($rule, $datum_dodani)) {
            $stats['preskoceno']++;
            continue;
        }

        // Anti-duplikát: existuje už pro tento datum objednávka z tohoto pravidla?
        $check = $pdo->prepare("
            SELECT id FROM objednavky
            WHERE odberatel_id = :o AND datum_dodani = :d
              AND poznamka LIKE :p
              AND stav NOT IN ('zrusena')
            LIMIT 1
        ");
        $check->execute([
            'o' => $rule['odberatel_id'],
            'd' => $datum_dodani,
            'p' => '%[Recurring #' . $rule['id'] . ']%',
        ]);
        if ($check->fetchColumn()) {
            $stats['preskoceno']++;
            $stats['detaily'][] = "Přeskočeno: pravidlo „{$rule['nazev']}“ — již existuje obj na $datum_dodani";
            continue;
        }

        try {
            $polozky = json_decode($rule['polozky_json'], true);
            if (!is_array($polozky) || empty($polozky)) {
                $stats['chyby']++;
                continue;
            }

            $pdo->beginTransaction();

            // Spočítej celkovou částku
            $castka_bez_dph = 0;
            $castka_dph = 0;
            foreach ($polozky as $p) {
                $mn = (float) $p['mnozstvi'];
                $cena = (float) $p['cena_bez_dph'];
                $dph = (float) ($p['sazba_dph'] ?? 12);
                $castka_bez_dph += $mn * $cena;
                $castka_dph += $mn * $cena * ($dph / 100);
            }
            $castka_celkem = $castka_bez_dph + $castka_dph;

            // Vygeneruj číslo objednávky
            $rok = (int) date('Y', strtotime($datum_dodani));
            $cislo = dalsi_cislo($pdo, 'OBJ', $rok);

            $stmt = $pdo->prepare("
                INSERT INTO objednavky
                    (cislo, odberatel_id, misto_dodani_id, datum_objednani, datum_dodani,
                     stav, castka_bez_dph, castka_dph, castka_celkem, poznamka)
                VALUES (:c, :o, :m, :do, :dd, 'potvrzena', :cbez, :cdph, :cc, :p)
            ");
            $pozn = "[Recurring #{$rule['id']}] " . ($rule['nazev'] ?? '');
            if ($rule['poznamka']) $pozn .= " — " . $rule['poznamka'];
            $stmt->execute([
                'c'   => $cislo,
                'o'   => $rule['odberatel_id'],
                'm'   => $rule['misto_dodani_id'],
                'do'  => date('Y-m-d'),
                'dd'  => $datum_dodani,
                'cbez' => $castka_bez_dph,
                'cdph' => $castka_dph,
                'cc'  => $castka_celkem,
                'p'   => $pozn,
            ]);
            $obj_id = $pdo->lastInsertId();

            // Položky
            $insPol = $pdo->prepare("
                INSERT INTO objednavky_polozky
                    (objednavka_id, vyrobek_id, vyrobek_nazev, mnozstvi, jednotka, cena_bez_dph, sazba_dph, poznamka)
                VALUES (:o, :v, :n, :m, :j, :c, :d, :p)
            ");
            foreach ($polozky as $p) {
                $insPol->execute([
                    'o' => $obj_id,
                    'v' => $p['vyrobek_id'] ?? null,
                    'n' => $p['vyrobek_nazev'] ?? '',
                    'm' => (float) $p['mnozstvi'],
                    'j' => $p['jednotka'] ?? 'ks',
                    'c' => (float) $p['cena_bez_dph'],
                    'd' => (float) ($p['sazba_dph'] ?? 12),
                    'p' => $p['poznamka'] ?? null,
                ]);
            }

            // Update pravidla
            $pdo->prepare("UPDATE recurring_orders SET posledni_beh = NOW(), pocet_vygen = pocet_vygen + 1 WHERE id = :id")
                ->execute(['id' => $rule['id']]);

            $pdo->commit();
            $stats['vytvoreno']++;
            $stats['detaily'][] = "✓ Vytvořeno: $cislo (z „{$rule['nazev']}“)";

            // Pošli email notifikaci jako u normální obj
            notifikace_nova_objednavka($pdo, $obj_id);
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            $stats['chyby']++;
            $stats['detaily'][] = "✗ Chyba u pravidla „{$rule['nazev']}“: " . $e->getMessage();
        }
    }

    return $stats;
}
