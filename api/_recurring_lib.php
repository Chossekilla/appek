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
            // 🐛 v3.0.644 — počítat CELÉ KALENDÁŘNÍ DNY (UTC), ne sekundy v Europe/Prague: přes jarní
            //   změnu času chyběla v rozdílu hodina → floor o 1 níž → objednávka 2 týdny po sobě a pak
            //   celé léto ve špatných týdnech (na podzim zase obráceně).
            $utc = new DateTimeZone('UTC');
            $dnu = (new DateTimeImmutable(substr($dz, 0, 10), $utc))->diff(new DateTimeImmutable(substr($datum, 0, 10), $utc))->days;
            $tydny = intdiv((int) $dnu, 7);
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

    // 🐛 v3.0.644 — stav odběratele (zablokovaný / „smazaný" = soft-delete blokovan=1, GDPR anonymizace)
    $odbBlok = $pdo->prepare("SELECT COALESCE(blokovan, 0) FROM odberatele WHERE id = ?");

    foreach ($rules as $rule) {
        if (!recurring_should_run($rule, $datum_dodani)) {
            $stats['preskoceno']++;
            continue;
        }

        // 🐛 v3.0.644 — zablokovanému / smazanému odběrateli se nic negeneruje (dřív každou noc
        //   potvrzená objednávka + e-mail + výroba + faktura zákazníkovi, se kterým se skončilo).
        //   Pravidlo se NEvypíná — po odblokování pokračuje (zpětně nic nedogeneruje).
        $odbBlok->execute([(int) $rule['odberatel_id']]);
        $blok = $odbBlok->fetchColumn();
        if ($blok === false || (int) $blok === 1) {
            $stats['preskoceno']++;
            $stats['detaily'][] = "Přeskočeno: pravidlo „{$rule['nazev']}“ — odběratel je zablokovaný/smazaný";
            continue;
        }

        // Anti-duplikát: existuje už pro tento datum objednávka z tohoto pravidla?
        // 🐛 v3.0.644 — počítá se i ZRUŠENÁ: zrušení objednávky na daný den je záměr zákazníka/dodavatele,
        //   cron ji nesmí na ten den vytvořit znovu (dřív zrušená = „neexistuje" → nová OPAK- obj.).
        $check = $pdo->prepare("
            SELECT id FROM objednavky
            WHERE odberatel_id = :o AND datum_dodani = :d
              AND poznamka LIKE :p
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

            // 🐛 v3.0.644 — CENY a dostupnost výrobků z AKTUÁLNÍHO ceníku odběratele (skupina/slevy/sezóna):
            //   - prázdná / nulová cena v pravidle = „dle ceníku" (dřív se vzala doslova → objednávka,
            //     DL i faktura za 0 Kč, když admin cenu ručně nevepsal);
            //   - kladná cena v pravidle = vědomý override (jako dřív);
            //   - výrobek mezitím deaktivovaný/smazaný se neobjedná (dřív se objednával dál) → upozornění.
            $cenikRule = [];
            foreach (cenik_pro_odberatele($pdo, (int) $rule['odberatel_id']) as $cr) $cenikRule[(int) $cr['id']] = $cr;
            $vynechane = [];
            $polozkyOk = [];
            foreach ($polozky as $p) {
                if (!is_array($p) || (float) ($p['mnozstvi'] ?? 0) <= 0) continue;
                $vid = (int) ($p['vyrobek_id'] ?? 0);
                $cenaRaw = $p['cena_bez_dph'] ?? null;
                $maCenu = $cenaRaw !== null && $cenaRaw !== '' && (float) $cenaRaw > 0;
                if ($vid > 0) {
                    $cr = $cenikRule[$vid] ?? null;
                    if (!$cr) { $vynechane[] = ($p['vyrobek_nazev'] ?? '') !== '' ? $p['vyrobek_nazev'] : ('výrobek #' . $vid); continue; }
                    if (!$maCenu) {
                        $p['cena_bez_dph'] = (float) $cr['cena_bez_dph'];
                        $p['sazba_dph']    = (float) ($cr['dph'] ?? ($p['sazba_dph'] ?? 12));
                    }
                    if (($p['vyrobek_nazev'] ?? '') === '') $p['vyrobek_nazev'] = $cr['nazev'] ?? '';
                    if (($p['jednotka'] ?? '') === '' && !empty($cr['jednotka'])) $p['jednotka'] = $cr['jednotka'];
                } elseif (!$maCenu) {
                    // volný řádek bez výrobku a bez ceny → nedává smysl objednávat za 0
                    $vynechane[] = ($p['vyrobek_nazev'] ?? '') !== '' ? $p['vyrobek_nazev'] : 'položka bez výrobku a ceny';
                    continue;
                }
                $polozkyOk[] = $p;
            }
            if ($vynechane) {
                $stats['detaily'][] = "⚠️ Pravidlo „{$rule['nazev']}“: vynechány nedostupné položky — " . implode(', ', $vynechane);
                try { $pdo->prepare("INSERT INTO notifications (kind, title, msg, link, severity) VALUES ('recurring_polozky', ?, ?, '#/recurring', 'warn')")
                          ->execute([mb_substr('⚠️ Opakovaná objednávka bez některých položek', 0, 200),
                                     'Pravidlo „' . ($rule['nazev'] ?? ('#' . $rule['id'])) . '" na ' . $datum_dodani . ': výrobky nejsou aktivní/dostupné, vynechány: ' . implode(', ', $vynechane) . '. Uprav nebo pozastav pravidlo.']); } catch (Throwable $e) {}
            }
            if (!$polozkyOk) {
                $stats['preskoceno']++;
                $stats['detaily'][] = "Přeskočeno: pravidlo „{$rule['nazev']}“ — žádná dostupná položka";
                continue;
            }
            $polozky = $polozkyOk;

            // 💱 v3.0.643 — měna opakované objednávky: pravidlo → výchozí měna odběratele → jako dřív (globál).
            //   Kurz = den generování. Volba PŘED transakcí (může líně stáhnout kurz ČNB).
            require_once __DIR__ . '/_mena_lib.php';
            $mRec = null; $mk = '';
            try {
                $mk = strtoupper((string) ($rule['mena'] ?? ''));
                if ($mk === '') {
                    $sm = $pdo->prepare("SELECT mena_platby FROM odberatele WHERE id = ?");
                    $sm->execute([(int) $rule['odberatel_id']]);
                    $mk = strtoupper((string) $sm->fetchColumn());
                }
                if ($mk !== '' && mena_platebni_info($pdo, 'b2b')['aktivni']) $mRec = mena_volba_platby($pdo, $mk, 'b2b');
            } catch (Throwable $e) {
                error_log('recurring mena #' . $rule['id'] . ': ' . $e->getMessage());
                // záloha = měna bez volby (kanál B2B bez cizí měny / bez kurzu → Kč), nikdy výchozí cizí měna naslepo
                try { $mRec = !empty(mena_config_load($pdo)['platba']['aktivni']) ? mena_volba_platby($pdo, null, 'b2b') : null; } catch (Throwable $e3) { $mRec = null; }
                // nemlčet: objednávka vznikne ve výchozí měně (typicky Kč) → upozornit admina
                try { $pdo->prepare("INSERT INTO notifications (kind, title, msg, link, severity) VALUES ('recurring_mena', ?, ?, '#/objednavky', 'warn')")
                          ->execute(['⚠️ Opakovaná objednávka ne v ' . ($mk ?: 'cizí měně'), 'Pravidlo „' . ($rule['nazev'] ?? ('#' . $rule['id'])) . '" na ' . $datum_dodani . ': ' . $e->getMessage() . ' Objednávka vznikla ve výchozí měně — zkontroluj ji.']); } catch (Throwable $e2) {}
            }

            // platby ve více měnách zapnuté, ale bez volby (kanál B2B jen Kč / odběratel bez měny) → měna kanálu, ne globální výchozí
            if (!$mRec && !empty(mena_config_load($pdo)['platba']['aktivni'])) { try { $mRec = mena_volba_platby($pdo, null, 'b2b'); } catch (Throwable $e4) {} }

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
            $cislo = kanal_dalsi_cislo($pdo, 'recurring', $rok); // 🆕 v3.0.212 — řada OPAK-rok-N

            $stmt = $pdo->prepare("
                INSERT INTO objednavky
                    (cislo, odberatel_id, misto_dodani_id, datum_objednani, datum_dodani,
                     stav, castka_bez_dph, castka_dph, castka_celkem, poznamka, puvod)
                VALUES (:c, :o, :m, :do, :dd, 'potvrzena', :cbez, :cdph, :cc, :p, 'recurring')
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

            // 💱 v3.0.643 — snapshot měny (zvolená / globál jako dřív) + autoritativní celkem v měně
            if ($mRec) mena_nastav_doklad($pdo, 'objednavky', (int) $obj_id, $mRec, true);
            else mena_snapshot_doklad($pdo, 'objednavky', (int) $obj_id);
            mena_ulozit_castku($pdo, 'objednavky', (int) $obj_id);

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
