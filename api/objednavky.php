<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/_mena_lib.php'; // 💱 v3.0.642 — částky v měně dokladu (mena_doc_pole)
require_once __DIR__ . '/_schema_lib.php'; // 🐛 v3.0.274 — ensure upraveno_kdy/zpusob_* (B2B edit padal na fresh installu)
require_once __DIR__ . '/_voucher_lib.php'; // 🆕 v3.0.631 — voucher z adminu přežije úpravu/storno odběratelem
cors_headers();
$odberatel_id = require_odberatel();

$pdo = db();
ensure_objednavky_schema($pdo);

/**
 * 🐛 v3.0.644 — odběratel zastaví svoje pravidlo opakované objednávky (recurring_orders.aktivni=0).
 *   Jen vlastní a aktivní pravidlo; dodavatel dostane upozornění. Vrací true, když se něco zastavilo.
 */
function b2b_recurring_zastavit(PDO $pdo, int $odberatel_id, int $ruleId, string $duvod): bool {
    if ($ruleId <= 0) return false;
    try {
        $st = $pdo->prepare("UPDATE recurring_orders SET aktivni = 0 WHERE id = ? AND odberatel_id = ? AND aktivni = 1");
        $st->execute([$ruleId, $odberatel_id]);
        if ($st->rowCount() < 1) return false;
    } catch (Throwable $e) { error_log('b2b recurring stop: ' . $e->getMessage()); return false; }
    try {
        $r = $pdo->prepare("SELECT r.nazev, o.nazev AS odb FROM recurring_orders r LEFT JOIN odberatele o ON o.id = r.odberatel_id WHERE r.id = ?");
        $r->execute([$ruleId]);
        $row = $r->fetch() ?: [];
        $pdo->prepare("INSERT INTO notifications (kind, title, msg, link, severity) VALUES ('recurring_stop', ?, ?, '#/recurring', 'info')")
            ->execute([mb_substr('⏹️ Odběratel zastavil pravidelnou objednávku', 0, 200),
                       ($row['odb'] ?? 'Odběratel') . ' zastavil pravidlo „' . ($row['nazev'] ?? ('#' . $ruleId)) . '" (' . $duvod . '). Další objednávky z něj nevzniknou.']);
    } catch (Throwable $e) { /* tabulka notifikací nemusí existovat — zastavení platí i tak */ }
    return true;
}

/** ID pravidla z tagu „[Recurring #N]" v poznámce objednávky (0 = není z pravidla). */
function b2b_recurring_id_z_poznamky(?string $pozn): int {
    return preg_match('/\[Recurring #(\d+)\]/', (string) $pozn, $m) ? (int) $m[1] : 0;
}

/**
 * 🐛 v3.0.644 — sdílený guard PŘEDOBJEDNÁVKY sezónního výrobku (POST i PUT): vrací chybovou hlášku,
 *   když se výrobek dodává až od startu sezóny a datum dodání je dřív; jinak null.
 */
function b2b_predobjednavka_chyba(PDO $pdo, array $c, string $datum, int $vid): ?string {
    if (empty($c['sezona'])) return null;
    require_once __DIR__ . '/_seasonal_lib.php';
    $spm = seasonal_product_meta($pdo, $c['sezona']);
    if ($spm['predobjednavka'] && $spm['dodani_od'] && $datum < $spm['dodani_od']) {
        $odKdy = date('j. n. Y', strtotime($spm['dodani_od']));
        return '„' . (($c['nazev'] ?? '') !== '' ? $c['nazev'] : "výrobek $vid") . "“ je předobjednávka — dodání možné od {$odKdy}. Posuňte datum dodání.";
    }
    return null;
}

// =============================================================
// GET - seznam nebo detail (pouze vlastní)
// =============================================================
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    if (isset($_GET['id'])) {
        $stmt = $pdo->prepare("
            SELECT o.*, md.nazev AS misto_nazev, md.ulice AS misto_ulice,
                   md.mesto AS misto_mesto, md.psc AS misto_psc
            FROM objednavky o
            LEFT JOIN mista_dodani md ON md.id = o.misto_dodani_id
            WHERE o.id = :id AND o.odberatel_id = :odb
        ");
        $stmt->execute(['id' => (int) $_GET['id'], 'odb' => $odberatel_id]);
        $obj = $stmt->fetch();
        if (!$obj) json_error('Objednávka nenalezena', 404);
        unset($obj['interni_pozn']);   // 🔒 v3.0.640 — interní poznámka admina se zákazníkovi neposílá

        // 🆕 v3.0.274 — LEFT JOIN + COALESCE: zákazník MUSÍ v detailu objednávky vidět
        //   i řádky příplatků (doprava/poplatek, vyrobek_id=NULL), které platí.
        $stmt = $pdo->prepare("
            SELECT p.*, COALESCE(v.nazev, p.vyrobek_nazev) AS vyrobek_nazev, v.cislo AS vyrobek_cislo,
                   COALESCE(j.kod, p.jednotka) AS jednotka
            FROM objednavky_polozky p
            LEFT JOIN vyrobky v ON v.id = p.vyrobek_id
            LEFT JOIN jednotky j ON j.id = v.jednotka_id
            WHERE p.objednavka_id = :id
            ORDER BY (p.vyrobek_id IS NULL), COALESCE(v.nazev, p.vyrobek_nazev)
        ");
        $stmt->execute(['id' => $obj['id']]);
        $obj['polozky'] = $stmt->fetchAll();

        // Editovatelnost
        $edit = objednavka_editovatelna($pdo, $obj);
        $obj['lze_editovat'] = $edit['lze'];
        $obj['duvod_zamceni'] = $edit['duvod'];
        $obj['uzaverka'] = $edit['uzaverka'] ? $edit['uzaverka']->format('Y-m-d H:i:s') : null;

        // Historie změn
        try {
            $stmt = $pdo->prepare("
                SELECT kdy, kdo_typ, kdo_jmeno, akce, detail
                FROM objednavky_zmeny
                WHERE objednavka_id = :id
                ORDER BY kdy DESC
            ");
            $stmt->execute(['id' => $obj['id']]);
            $obj['historie_zmen'] = $stmt->fetchAll();
        } catch (Exception $e) {
            // Tabulka možná zatím neexistuje - vrátíme prázdné
            $obj['historie_zmen'] = [];
        }

        // 💱 v3.0.642 — částky v měně DOKLADU (jako na PDF), ne dnešním kurzem
        $_one = [$obj]; mena_doc_pole($pdo, 'objednavky', $_one); $obj = $_one[0];
        json_response($obj);
    }

    // 🐛 v3.0.644 — pravidelné objednávky (pravidla) přihlášeného odběratele → v Historii je může zastavit
    if (isset($_GET['recurring'])) {
        try {
            $st = $pdo->prepare("
                SELECT id, nazev, frekvence, dny_v_tydnu, datum_zacatku, datum_konce, aktivni, posledni_beh, pocet_vygen,
                       JSON_LENGTH(polozky_json) AS pocet_polozek
                FROM recurring_orders
                WHERE odberatel_id = :odb
                ORDER BY aktivni DESC, id DESC
                LIMIT 100
            ");
            $st->execute(['odb' => $odberatel_id]);
            json_response($st->fetchAll());
        } catch (Throwable $e) {
            json_response([]);   // tabulka zatím neexistuje (žádné pravidlo nebylo založeno)
        }
    }

    // Speciální endpoint: posledních N objednávek včetně položek (pro znovu-objednání)
    if (isset($_GET['last'])) {
        $limit = max(1, min(100, (int) $_GET['last']));
        $stmt = $pdo->prepare("
            SELECT o.id, o.cislo, o.stav, o.datum_objednani, o.datum_dodani,
                   o.castka_celkem,
                   (SELECT COUNT(*) FROM objednavky_polozky WHERE objednavka_id = o.id) AS pocet_polozek,
                   (SELECT COUNT(*) FROM dodaci_listy WHERE objednavka_id = o.id) AS pocet_dl,
                   (SELECT id FROM dodaci_listy WHERE objednavka_id = o.id ORDER BY id ASC LIMIT 1) AS prvni_dl_id,
                   (
                     SELECT COUNT(DISTINCT fdl.faktura_id)
                     FROM faktury_dodaci_listy fdl
                     INNER JOIN dodaci_listy dl ON dl.id = fdl.dodaci_list_id
                     WHERE dl.objednavka_id = o.id
                   ) AS pocet_faktur,
                   (
                     SELECT fdl.faktura_id
                     FROM faktury_dodaci_listy fdl
                     INNER JOIN dodaci_listy dl ON dl.id = fdl.dodaci_list_id
                     WHERE dl.objednavka_id = o.id
                     ORDER BY fdl.faktura_id ASC LIMIT 1
                   ) AS prvni_faktura_id
            FROM objednavky o
            WHERE o.odberatel_id = :odb
            ORDER BY o.datum_objednani DESC
            LIMIT $limit
        ");
        $stmt->execute(['odb' => $odberatel_id]);
        $list = $stmt->fetchAll();

        // Načti položky pro každou
        if (!empty($list)) {
            $ids = array_column($list, 'id');
            $placeholders = implode(',', array_fill(0, count($ids), '?'));
            $stmt = $pdo->prepare("
                SELECT op.objednavka_id, op.vyrobek_id, op.mnozstvi,
                       op.cena_bez_dph, op.sazba_dph,
                       v.nazev AS vyrobek_nazev
                FROM objednavky_polozky op
                LEFT JOIN vyrobky v ON v.id = op.vyrobek_id
                WHERE op.objednavka_id IN ($placeholders)
            ");
            $stmt->execute($ids);
            $polozkyMap = [];
            foreach ($stmt->fetchAll() as $p) {
                $polozkyMap[$p['objednavka_id']][] = [
                    'vyrobek_id'    => (int) $p['vyrobek_id'],
                    'vyrobek_nazev' => $p['vyrobek_nazev'],
                    'mnozstvi'      => (float) $p['mnozstvi'],
                    'cena_bez_dph'  => (float) $p['cena_bez_dph'],
                    'sazba_dph'     => (float) $p['sazba_dph'],
                ];
            }
            foreach ($list as &$o) {
                $o['polozky'] = $polozkyMap[$o['id']] ?? [];
            }
            unset($o);
        }
        mena_doc_pole($pdo, 'objednavky', $list);   // 💱 v3.0.642

        json_response($list);
    }

    $stmt = $pdo->prepare("
        SELECT o.id, o.cislo, o.typ, o.stav, o.datum_objednani, o.datum_dodani,
               o.castka_celkem, COUNT(p.id) AS pocet_polozek
        FROM objednavky o
        LEFT JOIN objednavky_polozky p ON p.objednavka_id = o.id
        WHERE o.odberatel_id = :odb
        GROUP BY o.id
        ORDER BY o.datum_objednani DESC
        LIMIT 50
    ");
    $stmt->execute(['odb' => $odberatel_id]);
    $list = $stmt->fetchAll();
    mena_doc_pole($pdo, 'objednavky', $list);   // 💱 v3.0.642 — doc_mena/doc_celkem
    json_response($list);
}

// =============================================================
// POST - vytvoření nové objednávky
// =============================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $data = json_input();

    // 🆕 v3.0.276 — ŽÁDOST O ZMĚNU/STORNO po vystavení DL (zákazník už needituje přímo, jen požádá).
    //   Zapíše se do historie objednávky + do interní poznámky → pekárna to vidí v adminu.
    if (($_GET['action'] ?? '') === 'zadost') {
        $id = (int) ($data['id'] ?? 0);
        $zprava = trim((string) ($data['zprava'] ?? ''));
        if (!$id) json_error('Chybí ID objednávky', 400);
        if ($zprava === '') json_error('Napiš o co jde', 400);
        $st = $pdo->prepare("SELECT id FROM objednavky WHERE id = :id AND odberatel_id = :odb");
        $st->execute(['id' => $id, 'odb' => $odberatel_id]);
        if (!$st->fetchColumn()) json_error('Objednávka nenalezena', 404);
        $jm = $pdo->prepare("SELECT nazev FROM odberatele WHERE id = :id");
        $jm->execute(['id' => $odberatel_id]);
        $odbNazev = $jm->fetchColumn() ?: 'Odběratel';
        $zprava = mb_substr($zprava, 0, 1000);
        try { log_zmena_objednavky($pdo, $id, 'odberatel', $odberatel_id, $odbNazev, 'zadost_zmena', ['zprava' => $zprava]); } catch (Throwable $e) {}
        // Prominentně do interní poznámky (admin ji vidí hned v detailu)
        try {
            $pdo->prepare("UPDATE objednavky SET interni_pozn = TRIM(CONCAT(IFNULL(interni_pozn,''), '\n📩 ŽÁDOST ZÁKAZNÍKA (', :d, '): ', :z)) WHERE id = :id")
                ->execute(['d' => date('j.n. H:i'), 'z' => $zprava, 'id' => $id]);
        } catch (Throwable $e) {}
        json_response(['ok' => true]);
    }

    // 🐛 v3.0.644 — odběratel zastaví svůj pravidelný plán (Denně / Týdenní plán / pravidlo od dodavatele)
    if (($_GET['action'] ?? '') === 'recurring_stop') {
        $rid = (int) ($data['id'] ?? 0);
        if (!$rid) json_error('Chybí ID plánu', 400);
        if (!b2b_recurring_zastavit($pdo, $odberatel_id, $rid, 'v portálu')) {
            // neexistuje / cizí / už zastavené → nic neprozrazovat
            try { $chk = $pdo->prepare("SELECT aktivni FROM recurring_orders WHERE id = ? AND odberatel_id = ?"); $chk->execute([$rid, $odberatel_id]); $akt = $chk->fetchColumn(); }
            catch (Throwable $e) { $akt = false; }
            if ($akt === false) json_error('Plán nenalezen', 404);
        }
        json_response(['ok' => true]);
    }

    // 🎟️ v3.0.634 — odhad slevového kódu pro košík (nic nezapisuje; počítá jako vytvoření níže:
    //   ceny z ceníku odběratele + příplatky dopravy/platby, z nich se sleva počítá)
    if (($_GET['action'] ?? '') === 'voucher_odhad') {
        $cen = [];
        foreach (cenik_pro_odberatele($pdo, $odberatel_id) as $row) $cen[(int) $row['id']] = $row;
        $zb = 0.0;
        foreach ((array) ($data['polozky'] ?? []) as $p) {
            $c = $cen[(int) ($p['vyrobek_id'] ?? 0)] ?? null;
            $mn = (float) ($p['mnozstvi'] ?? 0);
            if (!$c || $mn <= 0) continue;
            $zb += (float) $c['cena_bez_dph'] * $mn * (1 + (float) ($c['dph'] ?? 0) / 100);
        }
        if ($zb <= 0) json_error('Košík je prázdný', 400);
        require_once __DIR__ . '/_platby_lib.php';
        $sur = platby_surcharges($pdo, preg_replace('/[^a-z_]/', '', strtolower((string) ($data['platba'] ?? ''))),
                                       preg_replace('/[^a-z_]/', '', strtolower((string) ($data['doprava'] ?? ''))), round($zb, 2));
        $zb += (float) $sur['doprava_cena'] + (float) $sur['platba_poplatek'];
        try {
            json_response(['ok' => true] + voucher_odhad($pdo, (string) ($data['kod'] ?? ''), 'b2b', $odberatel_id, round($zb, 2)));
        } catch (RuntimeException $e) {
            json_error($e->getMessage(), 400);
        }
    }

    // 💱 v3.0.643 — měna platby B2B: volba z košíku → výchozí měna odběratele → výchozí z nastavení.
    //   Volí se PŘED transakcí (mena_volba_platby může líně stáhnout kurz ČNB).
    $b2bMenaVolba = function () use ($pdo, $data, $odberatel_id): array {
        $pi = mena_platebni_info($pdo, 'b2b');
        $volba = strtoupper(trim((string) ($data['mena'] ?? '')));
        $zOdberatele = false;
        if ($volba === '' && $pi['aktivni']) {
            try { $st = $pdo->prepare("SELECT mena_platby FROM odberatele WHERE id = ?"); $st->execute([$odberatel_id]);
                  $mo = strtoupper((string) $st->fetchColumn()); if ($mo !== '' && in_array($mo, $pi['prijimane'], true)) { $volba = $mo; $zOdberatele = true; } } catch (Throwable $e) {}
        }
        try { return mena_volba_platby($pdo, $volba !== '' ? $volba : null, 'b2b'); }
        catch (MenaException $e) {
            // výchozí měna odběratele bez platného kurzu (svátky) → jako bez volby (Kč), objednávka nesmí spadnout
            if ($zOdberatele) { try { return mena_volba_platby($pdo, null, 'b2b'); } catch (MenaException $e2) {} }
            json_error($e->getMessage(), $e->http);
        }
    };
    // Metoda platby musí měnu umět (dobírka a hotově jen Kč; převod v € jen s EUR IBAN; brána dle integrace)
    $b2bMetodaMena = function (string $platba, string $mena) use ($pdo): ?string {
        if ($mena === 'CZK' || $platba === '') return null;
        if (in_array($platba, ['dobirka', 'hotove', 'hotovost'], true)) return 'Dobírku a platbu hotově lze jen v Kč.';
        if ($platba === 'prevod' && $mena === 'EUR' && mena_iban_eur($pdo) === '') return 'Převodem v € nelze — firma nemá vyplněný EUR účet (IBAN).';
        if ($platba === 'prevod' && $mena !== 'EUR') return 'Převodem lze platit jen v Kč nebo v €.';
        if (in_array($platba, ['stripe', 'gopay', 'paypal'], true)) {
            require_once __DIR__ . '/_customer_integrace.php';
            if (!in_array($mena, customer_int_brana_meny($platba), true)) return 'Tato platební brána neumí platbu v ' . $mena . '.';
        }
        return null;
    };

    // 💱 v3.0.643 — POST ?action=quote: košík přepočtený do měny platby PŘESNĚ jako doklad (= brána/e-mail/PDF)
    if (($_GET['action'] ?? '') === 'quote') {
        $m = $b2bMenaVolba();
        $cen = [];
        foreach (cenik_pro_odberatele($pdo, $odberatel_id) as $row) $cen[(int) $row['id']] = $row;
        $lines = []; $sub = 0.0;
        foreach ((array) ($data['polozky'] ?? []) as $p) {
            $c = $cen[(int) ($p['vyrobek_id'] ?? 0)] ?? null;
            $mn = (float) ($p['mnozstvi'] ?? 0);
            if (!$c || $mn <= 0) continue;
            $lines[] = [$mn, (float) $c['cena_bez_dph'], (float) ($c['dph'] ?? 0)];
            $sub += (float) $c['cena_bez_dph'] * $mn * (1 + (float) ($c['dph'] ?? 0) / 100);
        }
        require_once __DIR__ . '/_platby_lib.php';
        $sur = platby_surcharges($pdo, preg_replace('/[^a-z_]/', '', strtolower((string) ($data['platba'] ?? ''))),
                                       preg_replace('/[^a-z_]/', '', strtolower((string) ($data['doprava'] ?? ''))), round($sub, 2));
        $dphPr = (float) nastaveni_get($pdo, 'dph_sazba_priplatky', 21);
        foreach ([(float) $sur['doprava_cena'], (float) $sur['platba_poplatek']] as $amt) {
            if ($amt > 0) $lines[] = [1, round($amt / (1 + $dphPr / 100), 2), $dphPr];
        }
        // 🎟️ slevový kód → stejné řádky jako voucher_objednavka_sync (sleva po sazbách / úhrada voucherem 0 %)
        $vkod = strtoupper(trim((string) ($data['voucher_kod'] ?? '')));
        if ($vkod !== '') {
            $poS = [];
            foreach ($lines as [$mn, $c, $sz]) { $k = (string) (float) $sz; $poS[$k] = ($poS[$k] ?? 0) + $mn * $c * (1 + $sz / 100); }
            $poS = array_filter($poS, fn($x) => $x > 0.004);
            $zb = round(array_sum($poS), 2);
            try {
                $vo = voucher_odhad($pdo, $vkod, 'b2b', $odberatel_id, $zb);
                $cil = round((float) ($vo['odecet'] ?? 0), 2);
                if ($cil > 0) {
                    if (($vo['chovani'] ?? '') === 'platba') { $lines[] = [1, -$cil, 0]; }
                    else {
                        $zbyva = $cil; $sazby = array_keys($poS);
                        foreach ($sazby as $i => $sk) {
                            $dil = $i === count($sazby) - 1 ? $zbyva : round($cil * $poS[$sk] / $zb, 2);
                            $zbyva = round($zbyva - $dil, 2);
                            $lines[] = [1, -round($dil / (1 + (float) $sk / 100), 2), (float) $sk];
                        }
                    }
                }
            } catch (Throwable $e) { /* neplatný kód → quote bez slevy (chybu ukáže voucher_odhad v košíku) */ }
        }
        $czk = 0.0; foreach ($lines as [$mn, $c, $sz]) $czk += round($mn * $c, 2) + round($mn * $c * $sz / 100, 2);
        $mm = mena_doklad_ro($pdo, $m);
        $calc = $mm['mena'] === 'CZK' ? ['celkem' => round($czk, 2), 'bez' => null, 'dph' => null] : mena_doklad_polozky($lines, $mm);
        json_response(['ok' => true, 'mena' => $mm['mena'], 'symbol' => $m['symbol'] ?? $mm['mena'], 'celkem' => $calc['celkem'],
                       'celkem_czk' => round($czk, 2), 'kurz' => $m['mena_kurz'], 'kurz_prodej' => $m['mena_kurz_prodej'],
                       'kurz_datum' => $m['kurz_datum'], 'doprava' => (float) $sur['doprava_cena'], 'poplatek' => (float) $sur['platba_poplatek'],
                       'metoda_chyba' => empty(mena_config_load($pdo)['platba']['aktivni']) ? null : $b2bMetodaMena(preg_replace('/[^a-z_]/', '', strtolower((string) ($data['platba'] ?? ''))), $mm['mena'])]);
    }

    // Validace typu
    $typ = $data['typ'] ?? 'jednorazova';
    if (!in_array($typ, ['jednorazova','pravidelna_denni','tydenni_plan'])) {
        json_error('Neplatný typ');
    }
    if (empty($data['datum_dodani'])) json_error('Chybí datum dodání');
    if (empty($data['polozky']) || !is_array($data['polozky'])) {
        json_error('Žádné položky');
    }

    $jeDatum = fn($d) => is_string($d) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $d) && strtotime($d) !== false;
    if (!$jeDatum($data['datum_dodani'])) json_error('Neplatné datum dodání');

    // 🐛 v3.0.644 — pravidelné typy: období Platí od–do + první dodání.
    //   Týdenní plán: první objednávka na NEJBLIŽŠÍ VYBRANÝ den v týdnu ≥ Platí od (dřív vždy na Platí od,
    //   i když ten den vybraný nebyl → dodávka + faktura navíc, kterou zákazník nechtěl).
    if ($typ !== 'jednorazova') {
        if (empty($data['plati_od'])) $data['plati_od'] = $data['datum_dodani'];
        if (!$jeDatum($data['plati_od'])) json_error('Neplatné datum „Platí od"');
        if (!empty($data['plati_do'])) {
            if (!$jeDatum($data['plati_do'])) json_error('Neplatné datum „Platí do"');
            if ($data['plati_do'] < $data['plati_od']) json_error('„Platí do" nemůže být dřív než „Platí od"');
        } else {
            $data['plati_do'] = null;
        }
        $data['datum_dodani'] = $data['plati_od'];
        if ($typ === 'tydenni_plan') {
            $dnyPlan = (isset($data['dny_v_tydnu']) && is_array($data['dny_v_tydnu']))
                ? array_values(array_unique(array_filter(array_map('intval', $data['dny_v_tydnu']), fn($x) => $x >= 1 && $x <= 7)))
                : [];
            if (!$dnyPlan) $dnyPlan = [1, 2, 3, 4, 5];   // stejný fallback jako pravidlo níže
            $data['dny_v_tydnu'] = $dnyPlan;
            $prvni = null;
            for ($i = 0; $i < 7; $i++) {
                $d = date('Y-m-d', strtotime($data['plati_od'] . " +$i day"));
                if (in_array((int) date('N', strtotime($d)), $dnyPlan, true)) { $prvni = $d; break; }
            }
            if (!$prvni || (!empty($data['plati_do']) && $prvni > $data['plati_do'])) {
                json_error('V období „Platí od–do" nepřipadá žádný z vybraných dnů v týdnu');
            }
            $data['datum_dodani'] = $prvni;
        }
    }

    // Validace data dodání - nesmí být v minulosti
    if ($data['datum_dodani'] < date('Y-m-d')) {
        json_error('Datum dodání nesmí být v minulosti');
    }

    // FIX #5: Ověř, že místo dodání patří přihlášenému odběrateli
    $misto_id = !empty($data['misto_dodani_id']) ? (int) $data['misto_dodani_id'] : null;
    if (!misto_patri_odberateli($pdo, $misto_id, $odberatel_id)) {
        json_error('Neplatné místo dodání', 403);
    }

    // 🆕 v3.0.176 — sloupce pro způsob doručení (rozvoz/kurýr/…) + platby (faktura/online/…).
    //   DDL MIMO transakci (ALTER dělá implicit commit). Soft-fail.
    try {
        $ocols = $pdo->query("SHOW COLUMNS FROM objednavky")->fetchAll(PDO::FETCH_COLUMN);
        if (!in_array('zpusob_doruceni', $ocols, true)) $pdo->exec("ALTER TABLE objednavky ADD COLUMN zpusob_doruceni VARCHAR(30) NULL");
        if (!in_array('zpusob_platby', $ocols, true))   $pdo->exec("ALTER TABLE objednavky ADD COLUMN zpusob_platby VARCHAR(30) NULL");
        // 🐛 v3.0.194 — sloupce opakované objednávky. Bez nich padal INSERT na
        //   „Unknown column 'plati_od'" (1054) → VŠECHNY B2B objednávky selhaly
        //   (odhaleno zátěžovým testem). POS cesta je neinsertuje, proto jela.
        if (!in_array('plati_od', $ocols, true))    $pdo->exec("ALTER TABLE objednavky ADD COLUMN plati_od DATE NULL");
        if (!in_array('plati_do', $ocols, true))    $pdo->exec("ALTER TABLE objednavky ADD COLUMN plati_do DATE NULL");
        if (!in_array('dny_v_tydnu', $ocols, true)) $pdo->exec("ALTER TABLE objednavky ADD COLUMN dny_v_tydnu VARCHAR(30) NULL");
    } catch (Throwable $e) { /* soft-fail — uložení proběhne bez těchto sloupců */ }

    // 🎟️ v3.0.634 — slevový kód / voucher (jen jednorázová objednávka; opakované by ho čerpaly každý týden)
    $voucherKod = strtoupper(trim((string) ($data['voucher_kod'] ?? '')));
    if ($voucherKod !== '') {
        if ($typ !== 'jednorazova') json_error('Slevový kód lze použít jen u jednorázové objednávky', 400);
        voucher_ensure_schema($pdo);   // DDL mimo transakci
    }

    // 💱 v3.0.643 — měna platby (mimo transakci) + kontrola, že ji zvolená metoda umí
    $mObj = $b2bMenaVolba();
    // platby ve více měnách zapnuté (globálně) → měna dokladu = zvolená (u kanálu bez cizí měny = Kč, nikdy výchozí cizí měna);
    // vypnuté → přesně jako v3.0.642 (snapshot podle zobrazení, metody platby bez omezení)
    $platbaAktivni = !empty(mena_config_load($pdo)['platba']['aktivni']);
    // kurz, který zákazník viděl v košíku (quote) — změnil-li se mezitím (nový kurz ČNB), neodeslat potichu jinou částku
    if ($platbaAktivni && ($mObj['mena'] ?? 'CZK') !== 'CZK' && isset($data['kurz_klient']) && is_numeric($data['kurz_klient'])
        && abs((float) $data['kurz_klient'] - (float) ($mObj['mena_kurz_prodej'] ?: $mObj['mena_kurz'])) > 0.000001) {
        json_response(['error' => 'Kurz ' . $mObj['mena'] . ' se mezitím změnil — zkontrolujte novou částku v košíku a odešlete znovu.', 'kod' => 'kurz_zmenen'], 409);
    }
    if ($platbaAktivni && ($chyba = $b2bMetodaMena(preg_replace('/[^a-z_]/', '', strtolower(trim((string) ($data['platba'] ?? '')))), $mObj['mena'])) !== null) json_error($chyba, 400);

    $pdo->beginTransaction();
    try {
        // FIX #2: Atomické číslování přes cislovani tabulku
        $cislo = kanal_dalsi_cislo($pdo, 'b2b'); // 🆕 v3.0.212 — vlastní řada B2B-rok-N

        // FIX #4: Načítej ceny POUZE pro aktivní výrobky
        $vyrobek_ids = array_unique(array_map('intval',
                                              array_column($data['polozky'], 'vyrobek_id')));
        if (empty($vyrobek_ids)) throw new Exception('Žádné platné položky');

        // 🐛 v3.0.215 — ceny z ceníku odběratele (cenová skupina/slevy), ne základní cena.
        //   Dřív create bral v.cena_bez_dph (base) → zákazník ve slevové skupině platil plnou cenu;
        //   edit (PUT) přitom cenik_pro_odberatele používal → nekonzistence. Sjednoceno (server-authoritative).
        //   Bez skupiny vrací cenik_pro_odberatele základní ceny → beze změny chování.
        $cenik = [];
        foreach (cenik_pro_odberatele($pdo, $odberatel_id) as $row) {
            $cenik[(int) $row['id']] = [
                'nazev'          => $row['nazev'] ?? '',
                'cena_bez_dph'   => (float) $row['cena_bez_dph'],
                'sazba'          => (float) ($row['dph'] ?? 0),
                'min_objednavka' => $row['min_objednavka'] ?? 1,
                'sezona'         => $row['sezona'] ?? null, // 🍰 v3.0.406 — pro guard předobjednávek
                'jednotka'       => $row['jednotka'] ?? '',
            ];
        }

        // Validace položek
        $polozky_clean = [];
        $bez = 0; $dph = 0;
        foreach ($data['polozky'] as $p) {
            $vid = (int) ($p['vyrobek_id'] ?? 0);
            $mn  = (float) ($p['mnozstvi'] ?? 0);

            $c = $cenik[$vid] ?? null;
            // 🐛 v3.0.644 — hlášky s NÁZVEM výrobku (dřív jen interní ID „výrobku 57" → zákazník nevěděl který)
            if (!$c) {
                $nz = '';
                try { $sn = $pdo->prepare("SELECT nazev FROM vyrobky WHERE id = ?"); $sn->execute([$vid]); $nz = (string) $sn->fetchColumn(); } catch (Throwable $e) {}
                throw new Exception(($nz !== '' ? '„' . $nz . '“' : 'Jeden z výrobků v košíku') . ' už není v nabídce — odeberte ho z košíku.');
            }
            $nzC = ($c['nazev'] ?? '') !== '' ? $c['nazev'] : "výrobek $vid";
            if ($mn <= 0) throw new Exception("Neplatné množství u „{$nzC}“");
            $min = (float) ($c['min_objednavka'] ?? 1);
            if ($mn < $min) throw new Exception("Minimální objednávka „{$nzC}“ je " . rtrim(rtrim(number_format($min, 3, ',', ''), '0'), ',') . ' ' . ($c['jednotka'] ?: 'ks'));

            // 🍰 v3.0.406 — PŘEDOBJEDNÁVKA: sezónní výrobek viditelný v předstihu
            //   (predstih_dni před startem) lze objednat jen s dodáním OD startu sezóny.
            if ($chybaPred = b2b_predobjednavka_chyba($pdo, $c, (string) ($data['datum_dodani'] ?? ''), $vid)) throw new Exception($chybaPred);

            $bez += $c['cena_bez_dph'] * $mn;
            $dph += $c['cena_bez_dph'] * $mn * ($c['sazba'] / 100);

            $polozky_clean[] = [
                'vyrobek_id' => $vid, 'mnozstvi' => $mn,
                'nazev' => $c['nazev'] ?? '',
                'cena' => $c['cena_bez_dph'], 'sazba' => $c['sazba'],
                'poznamka' => $p['poznamka'] ?? null,
            ];
        }

        // 🆕 v3.0.272 — PŘÍPLATKY: doprava (s prahem „zdarma od") + poplatek platby (dobírka…).
        //   Promítnou se do CELKEM a uloží jako řádky dokladu → propíšou se i do DL/faktury.
        require_once __DIR__ . '/_platby_lib.php';
        $platbaKey  = preg_replace('/[^a-z_]/', '', strtolower(trim((string) ($data['platba'] ?? ''))));
        $dopravaKey = preg_replace('/[^a-z_]/', '', strtolower(trim((string) ($data['doprava'] ?? ''))));
        $subtotal   = round($bez + $dph, 2);
        $sur = platby_surcharges($pdo, $platbaKey, $dopravaKey, $subtotal);
        $dopCfg = platby_config_load($pdo)['doprava']['metody'][$dopravaKey] ?? null;
        $surLines = [];
        if ($sur['doprava_cena'] > 0) $surLines[] = ['nazev' => '🚚 Doprava — ' . ($dopCfg['nazev'] ?? $dopravaKey), 'castka' => $sur['doprava_cena']];
        if ($sur['platba_poplatek'] > 0) $surLines[] = ['nazev' => '➕ Poplatek za platbu (' . $platbaKey . ')', 'castka' => $sur['platba_poplatek']];
        // 🆕 v3.0.317 — sazba DPH příplatků (doprava/poplatek) konfigurovatelná (nastaveni
        //   'dph_sazba_priplatky', default 21 % = beze změny). Pro neplátce/jiné sazby lze přenastavit.
        $dphPripl = (float) nastaveni_get($pdo, 'dph_sazba_priplatky', 21);
        $dphPriplDiv = 1 + $dphPripl / 100;
        foreach ($surLines as $sl) {
            $cenaBez = round($sl['castka'] / $dphPriplDiv, 2);
            $bez += $cenaBez;
            $dph += round($sl['castka'] - $cenaBez, 2);
            $polozky_clean[] = ['vyrobek_id' => null, 'mnozstvi' => 1, 'nazev' => $sl['nazev'], 'cena' => $cenaBez, 'sazba' => $dphPripl, 'poznamka' => null];
        }

        // Vlož hlavičku (🆕 v3.0.212 — puvod='b2b' → kanál B2B portál, vlastní řada B2B-rok-N)
        // 🐛 v3.0.235 FIX — datum_objednani CHYBĚL → b2b objednávky měly 0000-00-00
        //   a MIZELY z dashboardu/tržeb/statistik (vše filtruje DATE(datum_objednani)).
        //   Datum přijetí objednávky = dnes (CURDATE), nezávisle na datum_dodani.
        $stmt = $pdo->prepare("
            INSERT INTO objednavky (cislo, odberatel_id, misto_dodani_id, typ, datum_objednani, datum_dodani,
                                    plati_od, plati_do, dny_v_tydnu,
                                    castka_bez_dph, castka_dph, castka_celkem, poznamka,
                                    zpusob_doruceni, zpusob_platby, puvod,
                                    gdpr_souhlas, gdpr_souhlas_at)
            VALUES (:c,:o,:m,:t,CURDATE(),:d,:po,:pdo_,:dny,:b,:dph,:cel,:pozn,:dor,:plt,'b2b',
                    :gs, :gsat)
        ");
        $stmt->execute([
            'c' => $cislo, 'o' => $odberatel_id, 'm' => $misto_id,
            't' => $typ, 'd' => $data['datum_dodani'],
            'po' => $data['plati_od'] ?? null,
            'pdo_' => $data['plati_do'] ?? null,
            'dny' => isset($data['dny_v_tydnu']) && is_array($data['dny_v_tydnu'])
                     ? implode(',', array_map('intval', $data['dny_v_tydnu']))
                     : null,
            'b' => round($bez, 2), 'dph' => round($dph, 2),
            'cel' => round($bez + $dph, 2), 'pozn' => $data['poznamka'] ?? null,
            // 🐛 PR17 — ukládej NORMALIZOVANÝ klíč (stejný jako pro příplatek), ne syrový vstup;
            //   jinak „Rozvoz"/„Prevod" uložené s velkým písmenem rozbíjí filtry/reporty i příplatky.
            'dor' => ($dopravaKey ?: null),
            'plt' => ($platbaKey ?: null),
            // 🔒 v3.0.425 — evidence GDPR souhlasu (server-authoritative čas)
            'gs' => !empty($data['gdpr_souhlas']) ? 1 : 0,
            'gsat' => !empty($data['gdpr_souhlas']) ? date('Y-m-d H:i:s') : null,
        ]);
        $obj_id = (int) $pdo->lastInsertId();
        // 💱 v3.0.640/643 — měna dokladu: zvolená měna platby (zapnuto) / globální zobrazení (vypnuto, jako dřív)
        if ($platbaAktivni) mena_nastav_doklad($pdo, 'objednavky', $obj_id, $mObj, true);
        else mena_snapshot_doklad($pdo, 'objednavky', $obj_id);

        // Vlož položky (🆕 v3.0.272 — vyrobek_nazev kvůli volným řádkům příplatků s vyrobek_id=NULL)
        $stmt = $pdo->prepare("
            INSERT INTO objednavky_polozky
                (objednavka_id, vyrobek_id, vyrobek_nazev, mnozstvi, cena_bez_dph, sazba_dph, poznamka)
            VALUES (:o,:v,:n,:m,:c,:s,:p)
        ");
        foreach ($polozky_clean as $p) {
            $stmt->execute([
                'o' => $obj_id, 'v' => $p['vyrobek_id'], 'n' => $p['nazev'] ?? null,
                'm' => $p['mnozstvi'], 'c' => $p['cena'], 's' => $p['sazba'], 'p' => $p['poznamka'],
            ]);
        }

        // 🎟️ v3.0.634 — voucher v téže transakci (neplatný kód → RuntimeException → rollback, objednávka nevznikne)
        if ($voucherKod !== '') {
            $odbJm = (string) $pdo->query("SELECT nazev FROM odberatele WHERE id = " . (int) $odberatel_id)->fetchColumn();
            voucher_objednavka_uplatnit($pdo, $obj_id, $voucherKod, 'b2b', 'odběratel (B2B)' . ($odbJm !== '' ? ' — ' . $odbJm : ''));
        }
        mena_ulozit_castku($pdo, 'objednavky', $obj_id);   // 💱 v3.0.643 — autoritativní celkem v měně dokladu

        $pdo->commit();

        // 🆕 v3.0.207 — pravidelné typy z portálu (pravidelna_denni / tydenni_plan) založí
        //   RECURRING PRAVIDLO (recurring_orders) → cron_recurring pak generuje opakované
        //   objednávky. Dřív se uložil jen plati_od/dny na 1 obj. a nic se neopakovalo.
        //   Soft-fail (objednávka už je commitnutá). První obj. otagujeme [Recurring #id],
        //   aby ji cron u datum_zacatku znovu nevygeneroval (anti-duplikát).
        $recurringCreated = false;
        try {
            $freqMap = ['pravidelna_denni' => 'denne', 'tydenni_plan' => 'tydne'];
            if (isset($freqMap[$typ])) {
                $frekvence = $freqMap[$typ];
                $dny = (isset($data['dny_v_tydnu']) && is_array($data['dny_v_tydnu']))
                    ? implode(',', array_filter(array_map('intval', $data['dny_v_tydnu']), fn($x) => $x >= 1 && $x <= 7))
                    : null;
                // 'tydne' bez dnů by se nikdy nespustil → fallback Po–Pá
                if ($frekvence === 'tydne' && empty($dny)) $dny = '1,2,3,4,5';
                // 🐛 v3.0.644 — cena_bez_dph = null → generátor bere při každém vytvoření AKTUÁLNÍ ceník
                //   odběratele (jako každá jiná B2B objednávka); dřív se navždy zmrazila cena z prvního dne.
                //   Řádky příplatků (doprava/poplatek, vyrobek_id=NULL) si cenu nesou dál jako dřív.
                $recPolozky = array_map(fn($p) => [
                    'vyrobek_id'    => $p['vyrobek_id'],
                    'vyrobek_nazev' => $p['nazev'] ?? '',
                    'mnozstvi'      => $p['mnozstvi'],
                    'jednotka'      => !empty($p['vyrobek_id']) ? ($cenik[(int) $p['vyrobek_id']]['jednotka'] ?? null) : null,
                    'cena_bez_dph'  => !empty($p['vyrobek_id']) ? null : round((float) $p['cena'], 2),
                    'sazba_dph'     => $p['sazba'],
                ], $polozky_clean);
                $odbNazev = (string) $pdo->query("SELECT nazev FROM odberatele WHERE id = " . (int) $odberatel_id)->fetchColumn();
                $recNazev = ($frekvence === 'denne' ? '🔁 Denní objednávka' : '🔁 Týdenní plán')
                          . ($odbNazev !== '' ? ' — ' . $odbNazev : '');
                $pdo->prepare("
                    INSERT INTO recurring_orders
                        (nazev, odberatel_id, misto_dodani_id, frekvence, dny_v_tydnu, polozky_json, poznamka, datum_zacatku, datum_konce, aktivni)
                    VALUES (:n,:o,:m,:f,:dt,:pj,:pz,:dz,:dk,1)
                ")->execute([
                    'n'  => mb_substr($recNazev, 0, 100),
                    'o'  => $odberatel_id,
                    'm'  => $misto_id ?: null,
                    'f'  => $frekvence,
                    'dt' => $dny,
                    'pj' => json_encode($recPolozky, JSON_UNESCAPED_UNICODE),
                    'pz' => 'Automaticky z B2B portálu (obj. ' . $cislo . ')',
                    'dz' => $data['plati_od'] ?? $data['datum_dodani'] ?? date('Y-m-d'),
                    'dk' => $data['plati_do'] ?? null,
                ]);
                $ruleId = (int) $pdo->lastInsertId();
                // 💱 v3.0.643 — pravidlo si pamatuje měnu objednávky (generuje se kurzem dne generování)
                try { $pdo->prepare("UPDATE recurring_orders SET mena = ? WHERE id = ?")->execute([$mObj['mena'] ?? null, $ruleId]); } catch (Throwable $e) {}
                $pdo->prepare("UPDATE objednavky SET poznamka = TRIM(CONCAT(IFNULL(poznamka,''), ' [Recurring #', :rid, ']')) WHERE id = :id")
                    ->execute(['rid' => $ruleId, 'id' => $obj_id]);
                $recurringCreated = true;
            }
        } catch (Throwable $e) { error_log('objednavky recurring rule: ' . $e->getMessage()); }

        // Notifikace odběrateli (po commitu, chyby nelogují selhání objednávky)
        notifikace_nova_objednavka($pdo, $obj_id);

        // 🆕 v2.9.203/209 — pokud zvolil online platbu (stripe/gopay/paypal), vytvoř session
        // a vrať payment_url pro redirect. Pro 'prevod' / 'dobirka' jen json OK.
        $platba = strtolower(trim((string) ($data['platba'] ?? 'prevod')));
        $paymentUrl = null;
        // 💱 v3.0.643 — k úhradě = částka v MĚNĚ DOKLADU (po voucheru); brána dostane přesně ji
        //   (mena_doc_castka = jediný zdroj: košík/quote, e-mail, PDF, brána, webhook).
        $paymentErr = null; $docC = null;
        try { $docC = mena_doc_castka($pdo, 'objednavky', $obj_id); } catch (Throwable $e) { error_log('objednavky doc_castka: ' . $e->getMessage()); }
        if (($platba === 'stripe' || $platba === 'gopay' || $platba === 'paypal') && $docC && $docC['celkem'] > 0) {   // voucher pokryl vše → není co platit
            require_once __DIR__ . '/_online_platba_lib.php';
            // 🐛 v3.0.644 — vč. podsložky instalace (dřív https://firma.cz/api/… → 404 webhook i návrat z brány)
            $baseUrl = app_public_base();
            $stmtOdb = $pdo->prepare("SELECT nazev, login_email, email FROM odberatele WHERE id = :id");
            $stmtOdb->execute(['id' => $odberatel_id]);
            $odb = $stmtOdb->fetch() ?: [];
            try {
                $tok = online_obj_token($pdo, $obj_id);
                $r = online_platba_zaloz($pdo, 'objednavky', $obj_id, $docC, [
                    'brana'            => $platba,
                    'reference'        => 'B2B-' . $obj_id . '-' . $tok,
                    'order_no'         => $cislo,
                    'description'      => 'Objednávka ' . $cislo,
                    'customer_email'   => ($odb['login_email'] ?? '') ?: (($odb['email'] ?? '') ?: null),
                    'return_url'       => $platba === 'paypal'
                        ? $baseUrl . '/api/paypal_callback.php?gw=paypal&order=' . urlencode($cislo)
                        : $baseUrl . '/b2b/index.html?paid=' . urlencode($cislo),
                    'cancel_url'       => $baseUrl . '/b2b/index.html?cancelled=' . urlencode($cislo),
                    'notification_url' => $baseUrl . '/api/customer_payment_webhook.php?gw=gopay',
                ]);
                if ($r['ok']) {
                    $paymentUrl = $r['url'];
                    if ($platba === 'paypal' && !empty($r['id'])) {
                        $pdo->prepare("UPDATE objednavky SET interni_pozn = CONCAT(IFNULL(interni_pozn,''), :p) WHERE id = :id")
                            ->execute(['p' => "\nPayPal order_id: " . $r['id'], 'id' => $obj_id]);
                    }
                } else {
                    $paymentErr = $r['error'] ?? 'brana';
                    error_log('objednavky payment_init (' . $platba . '): ' . $paymentErr);
                }
            } catch (Throwable $e) {
                $paymentErr = 'brana';
                error_log('objednavky payment_init (' . $platba . '): ' . $e->getMessage());
            }
        }

        json_response([
            'id' => $obj_id,
            'cislo' => $cislo,
            'platba' => $platba,
            'payment_url' => $paymentUrl,
            'payment_error' => $paymentErr,
            'mena' => $docC['mena'] ?? 'CZK',
            'celkem' => $docC['celkem'] ?? null,
            'recurring_created' => $recurringCreated,
        ], 201);
    } catch (Exception $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        // Logujeme detaily, klientovi vracíme bezpečnou zprávu
        error_log('objednavky POST: ' . $e->getMessage());
        json_error($e->getMessage(), 400);
    }
}

// =============================================================
// PUT - úprava existující objednávky odběratelem
// =============================================================
if ($_SERVER['REQUEST_METHOD'] === 'PUT') {
    $data = json_input();
    $id = (int) ($data['id'] ?? 0);
    if (!$id) json_error('Chybí ID objednávky');

    // Načti původní objednávku - musí patřit přihlášenému odběrateli
    $stmt = $pdo->prepare("
        SELECT * FROM objednavky WHERE id = :id AND odberatel_id = :odb
    ");
    $stmt->execute(['id' => $id, 'odb' => $odberatel_id]);
    $orig = $stmt->fetch();
    if (!$orig) json_error('Objednávka nenalezena', 404);
    // 💱 v3.0.643 — původní částka v měně dokladu PŘED úpravou (pro e-mail o úpravě = jako potvrzení/PDF)
    if (!empty($orig['id'])) {
        try { require_once __DIR__ . '/_mena_lib.php'; $tmpDoc = [['id' => (int) $orig['id']]]; mena_doc_pole($pdo, 'objednavky', $tmpDoc);
              if (isset($tmpDoc[0]['doc_celkem'])) $orig['_doc_celkem'] = (float) $tmpDoc[0]['doc_celkem']; } catch (Throwable $e) { /* fallback v notifikaci */ }
    }

    // 💱 v3.0.643 — zaplacenou objednávku (online brána) nelze měnit: zaplacená částka by neseděla s dokladem
    if (($orig['pay_status'] ?? '') === 'paid') json_error('Objednávka je už zaplacená — změnu domluvte s dodavatelem.', 409);

    // Editovatelnost
    $edit = objednavka_editovatelna($pdo, $orig);
    if (!$edit['lze']) json_error($edit['duvod'], 409);

    // Ověř místo dodání
    // v3.0.586 — objednávka bez místa (NULL) nesmí spadnout na 0 → FK 500 (smoke-chain PR04); sjednoceno s POST
    $mistoRaw = array_key_exists('misto_dodani_id', $data) ? $data['misto_dodani_id'] : $orig['misto_dodani_id'];
    $misto_id = !empty($mistoRaw) ? (int) $mistoRaw : null;
    if (!misto_patri_odberateli($pdo, $misto_id, $odberatel_id)) {
        json_error('Místo dodání nepatří k odběrateli', 400);
    }

    // Validuj položky
    $polozky = $data['polozky'] ?? [];
    if (!is_array($polozky) || count($polozky) === 0) {
        json_error('Objednávka musí obsahovat alespoň jednu položku');
    }

    // Ceny vždy z aktuálního ceníku odběratele
    $cenik = cenik_pro_odberatele($pdo, $odberatel_id);
    $cenikIdx = [];
    foreach ($cenik as $v) $cenikIdx[(int) $v['id']] = $v;

    // 🐛 v3.0.644 — výrobky, které už v objednávce JSOU (guard předobjednávky jen pro nově přidané:
    //   co prošlo při vytvoření, nesmí po změně nastavení sezóny zablokovat úpravu množství)
    $puvodniVids = [];
    try {
        $sv = $pdo->prepare("SELECT DISTINCT vyrobek_id FROM objednavky_polozky WHERE objednavka_id = ? AND vyrobek_id IS NOT NULL");
        $sv->execute([$id]);
        $puvodniVids = array_map('intval', $sv->fetchAll(PDO::FETCH_COLUMN));
    } catch (Throwable $e) {}

    $polozky_clean = [];
    foreach ($polozky as $p) {
        $vid = (int) ($p['vyrobek_id'] ?? 0);
        $mn  = (float) ($p['mnozstvi'] ?? 0);
        if (!$vid || $mn <= 0) continue;
        if (!isset($cenikIdx[$vid])) continue;
        $min = (float) ($cenikIdx[$vid]['min_objednavka'] ?? 1); // 🆕 v3.0.351 — MOQ i při editaci (parita s create)
        if ($mn < $min) json_error('Minimální objednávka výrobku ' . ($cenikIdx[$vid]['nazev'] ?? $vid) . ' je ' . $min, 400);
        // 🐛 v3.0.644 — PŘEDOBJEDNÁVKA i při úpravě (parita s POST): sezónní výrobek s dodáním až od startu
        //   sezóny nejde přidat do objednávky s dřívějším datem dodání (dřív PUT prošel → výroba/DL/faktura předčasně)
        if (!in_array($vid, $puvodniVids, true)
            && ($chybaPred = b2b_predobjednavka_chyba($pdo, $cenikIdx[$vid], (string) $orig['datum_dodani'], $vid))) {
            json_error($chybaPred, 400);
        }
        $polozky_clean[] = [
            'vyrobek_id' => $vid,
            'mnozstvi'   => $mn,
            'cena'       => (float) $cenikIdx[$vid]['cena_bez_dph'],
            'sazba'      => (float) $cenikIdx[$vid]['dph'],
            'poznamka'   => $p['poznamka'] ?? null,
        ];
    }
    if (empty($polozky_clean)) json_error('Žádná platná položka');

    // Spočítej součty
    $bez = 0.0; $dph = 0.0;
    foreach ($polozky_clean as $p) {
        $b = $p['cena'] * $p['mnozstvi'];
        $bez += $b;
        $dph += $b * $p['sazba'] / 100;
    }

    // 🆕 v3.0.274 — PŘÍPLATKY i při EDITaci. Bez tohoto edit B2B objednávky smazal
    //   řádky dopravy + poplatku (DELETE všech položek + reinsert jen produktů) →
    //   CELKEM spadlo a doklady přišly o příplatky. Přepočítej z ULOŽENÉ metody
    //   platby/dopravy (nebo z dat, pokud je portál pošle). Řádky držíme zvlášť
    //   (mimo $polozky_clean), ať nezašpiní diff/log pro notifikaci.
    require_once __DIR__ . '/_platby_lib.php';
    $platbaKey  = preg_replace('/[^a-z_]/', '', strtolower(trim((string) ($data['platba']  ?? $orig['zpusob_platby']   ?? ''))));
    $dopravaKey = preg_replace('/[^a-z_]/', '', strtolower(trim((string) ($data['doprava'] ?? $orig['zpusob_doruceni'] ?? ''))));
    $sur = platby_surcharges($pdo, $platbaKey, $dopravaKey, round($bez + $dph, 2));
    $dopCfg = platby_config_load($pdo)['doprava']['metody'][$dopravaKey] ?? null;
    $surRows = [];
    if ($sur['doprava_cena'] > 0)    $surRows[] = ['nazev' => '🚚 Doprava — ' . ($dopCfg['nazev'] ?? $dopravaKey), 'castka' => $sur['doprava_cena']];
    if ($sur['platba_poplatek'] > 0) $surRows[] = ['nazev' => '➕ Poplatek za platbu (' . $platbaKey . ')', 'castka' => $sur['platba_poplatek']];
    $dphPripl = (float) nastaveni_get($pdo, 'dph_sazba_priplatky', 21); // 🆕 v3.0.317 konfigurovatelné
    $dphPriplDiv = 1 + $dphPripl / 100;
    foreach ($surRows as &$sr) {
        $sr['cena']  = round($sr['castka'] / $dphPriplDiv, 2);
        $sr['sazba'] = $dphPripl;
        $bez += $sr['cena'];
        $dph += round($sr['castka'] - $sr['cena'], 2);
    }
    unset($sr);

    try {
        voucher_ensure_schema($pdo);   // 🎟️ v3.0.633 — DDL mimo transakci (jinak implicitní commit → commit() hodí výjimku)
        $pdo->beginTransaction();

        // Načti staré položky pro porovnání
        $stmt = $pdo->prepare("
            SELECT op.vyrobek_id, op.mnozstvi, v.nazev AS vyrobek_nazev
            FROM objednavky_polozky op
            JOIN vyrobky v ON v.id = op.vyrobek_id
            WHERE op.objednavka_id = :id
        ");
        $stmt->execute(['id' => $id]);
        $stareItems = [];
        foreach ($stmt->fetchAll() as $r) {
            $stareItems[(int) $r['vyrobek_id']] = [
                'mnozstvi' => (float) $r['mnozstvi'],
                'nazev'    => $r['vyrobek_nazev'],
            ];
        }

        // Update objednávky
        $pdo->prepare("
            UPDATE objednavky SET
                misto_dodani_id = :m,
                poznamka = :p,
                zpusob_platby = :plt,
                zpusob_doruceni = :dor,
                castka_bez_dph = :b,
                castka_dph = :d,
                castka_celkem = :c,
                upraveno_kdy = NOW()
            WHERE id = :id
        ")->execute([
            'id' => $id,
            'm'  => $misto_id,
            'p'  => $data['poznamka'] ?? $orig['poznamka'],
            'plt' => $platbaKey ?: null,
            'dor' => $dopravaKey ?: null,
            'b'  => round($bez, 2),
            'd'  => round($dph, 2),
            'c'  => round($bez + $dph, 2),
        ]);

        // Smaž staré položky a vlož nové
        $pdo->prepare("DELETE FROM objednavky_polozky WHERE objednavka_id = :id")
            ->execute(['id' => $id]);

        $stmt = $pdo->prepare("
            INSERT INTO objednavky_polozky
                (objednavka_id, vyrobek_id, vyrobek_nazev, mnozstvi, cena_bez_dph, sazba_dph, poznamka)
            VALUES (:o, :v, :n, :m, :c, :s, :p)
        ");
        foreach ($polozky_clean as $p) {
            $stmt->execute([
                'o' => $id, 'v' => $p['vyrobek_id'], 'n' => null, 'm' => $p['mnozstvi'],
                'c' => $p['cena'], 's' => $p['sazba'], 'p' => $p['poznamka'],
            ]);
        }
        // 🆕 v3.0.274 — znovu vlož volné řádky příplatků (doprava/poplatek), vyrobek_id=NULL
        foreach ($surRows as $sr) {
            $stmt->execute([
                'o' => $id, 'v' => null, 'n' => $sr['nazev'], 'm' => 1,
                'c' => $sr['cena'], 's' => $sr['sazba'], 'p' => null,
            ]);
        }
        // 🆕 v3.0.631 — voucher uplatněný v adminu: řádky jsou smazané výše → znovu podle knihy
        //   pohybů (zaúčtuje jen rozdíl, % sleva se dopočítá z nového zboží) + přepočet hlavičky
        voucher_objednavka_sync($pdo, $id, 'odběratel (B2B úprava)');

        // Spočítej rozdíly pro log + email
        $noveItems = [];
        foreach ($polozky_clean as $p) {
            $vid = (int) $p['vyrobek_id'];
            $noveItems[$vid] = [
                'mnozstvi' => (float) $p['mnozstvi'],
                'nazev'    => $cenikIdx[$vid]['nazev'] ?? '?',
            ];
        }

        $diff = ['pridane' => [], 'odebrane' => [], 'zmenene' => []];
        foreach ($noveItems as $vid => $n) {
            if (!isset($stareItems[$vid])) {
                $diff['pridane'][] = ['nazev' => $n['nazev'], 'mnozstvi' => $n['mnozstvi']];
            } elseif (abs($stareItems[$vid]['mnozstvi'] - $n['mnozstvi']) > 0.001) {
                $diff['zmenene'][] = [
                    'nazev'   => $n['nazev'],
                    'puvodne' => $stareItems[$vid]['mnozstvi'],
                    'nove'    => $n['mnozstvi'],
                ];
            }
        }
        foreach ($stareItems as $vid => $s) {
            if (!isset($noveItems[$vid])) {
                $diff['odebrane'][] = ['nazev' => $s['nazev'], 'mnozstvi' => $s['mnozstvi']];
            }
        }

        // Načti jméno odběratele
        $jm = $pdo->prepare("SELECT nazev FROM odberatele WHERE id = :id");
        $jm->execute(['id' => $odberatel_id]);
        $odberatel_nazev = $jm->fetchColumn() ?: 'Odběratel';

        log_zmena_objednavky($pdo, $id, 'odberatel', $odberatel_id, $odberatel_nazev, 'upravena', [
            'puvodni_castka' => (float) $orig['castka_celkem'],
            'nova_castka'    => round($bez + $dph, 2),
            'rozdil'         => $diff,
        ]);
        try { mena_ulozit_castku($pdo, 'objednavky', $id); } catch (Throwable $e) { error_log('PUT castka_mena: ' . $e->getMessage()); }
        // 💱 v3.0.643 — rozjetá online platba platí pro STAROU částku → zneplatnit očekávání; pozdní potvrzení
        //   brány pak porovná s novou částkou dokladu (nesedí → 'failed' + upozornění, nikdy tiché „zaplaceno")
        if (($orig['pay_status'] ?? '') === 'pending') {
            try { $pdo->prepare("UPDATE objednavky SET pay_mena = NULL, pay_castka = NULL, pay_status = 'none' WHERE id = ? AND pay_status = 'pending'")->execute([$id]); }
            catch (Throwable $e) { error_log('PUT pay reset: ' . $e->getMessage()); }
        }

        $pdo->commit();

        // Email notifikace - mimo transakci
        notifikace_zmena_objednavky($pdo, $id, $orig, [
            'castka' => round($bez + $dph, 2),
            'diff'   => $diff,
            'kdo'    => $odberatel_nazev,
        ]);

        json_response(['ok' => true, 'id' => $id]);
    } catch (Exception $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        error_log('objednavky PUT: ' . $e->getMessage());
        json_error('Nepodařilo se uložit změny: ' . $e->getMessage(), 500);
    }
}

// =============================================================
// DELETE - zrušení objednávky odběratelem
// =============================================================
if ($_SERVER['REQUEST_METHOD'] === 'DELETE') {
    $id = (int) ($_GET['id'] ?? 0);
    if (!$id) json_error('Chybí ID objednávky');

    $stmt = $pdo->prepare("
        SELECT * FROM objednavky WHERE id = :id AND odberatel_id = :odb
    ");
    $stmt->execute(['id' => $id, 'odb' => $odberatel_id]);
    $orig = $stmt->fetch();
    if (!$orig) json_error('Objednávka nenalezena', 404);
    // 💱 v3.0.643 — původní částka v měně dokladu PŘED úpravou (pro e-mail o úpravě = jako potvrzení/PDF)
    if (!empty($orig['id'])) {
        try { require_once __DIR__ . '/_mena_lib.php'; $tmpDoc = [['id' => (int) $orig['id']]]; mena_doc_pole($pdo, 'objednavky', $tmpDoc);
              if (isset($tmpDoc[0]['doc_celkem'])) $orig['_doc_celkem'] = (float) $tmpDoc[0]['doc_celkem']; } catch (Throwable $e) { /* fallback v notifikaci */ }
    }

    $edit = objednavka_editovatelna($pdo, $orig);
    if (!$edit['lze']) json_error($edit['duvod'], 409);
    // 💱 v3.0.643 — zaplacenou online objednávku zákazník sám nezruší (peníze vrací dodavatel)
    if (($orig['pay_status'] ?? '') === 'paid') json_error('Objednávka je už zaplacená — o zrušení a vrácení peněz požádejte dodavatele.', 409);

    try {
        voucher_ensure_schema($pdo);   // 🎟️ v3.0.633 — DDL mimo transakci (jinak implicitní commit → commit() hodí výjimku)
        $pdo->beginTransaction();
        $pdo->prepare("UPDATE objednavky SET stav = 'zrusena', upraveno_kdy = NOW() WHERE id = :id")
            ->execute(['id' => $id]);
        // 🐛 PR12 — storno po výrobním odpisu vrátí spotřebované suroviny na sklad (idempotentně)
        require_once __DIR__ . '/_bom_lib.php';
        bom_vrat_objednavku_na_sklad($pdo, $id, 'storno zákazník');
        voucher_objednavka_sync($pdo, $id, 'odběratel (storno)', true); // 🆕 v3.0.631 — voucher zpět
        $pdo->commit();

        $jm = $pdo->prepare("SELECT nazev FROM odberatele WHERE id = :id");
        $jm->execute(['id' => $odberatel_id]);
        $odberatel_nazev = $jm->fetchColumn() ?: 'Odběratel';

        log_zmena_objednavky($pdo, $id, 'odberatel', $odberatel_id, $odberatel_nazev, 'zrusena');

        // 🐛 v3.0.644 — objednávka z PRAVIDELNÉHO PLÁNU (tag [Recurring #N]): dřív zrušení pravidlo nezastavilo
        //   a cron zrušený den dokonce vytvořil znovu. Teď: ?plan=stop → zastavit celý plán, ?plan=keep → jen
        //   tento den (cron ho už znovu nevytvoří — anti-duplikát počítá i se zrušenými). Bez parametru:
        //   zrušení objednávky, která plán ZALOŽILA (typ Denně / Týdenní plán), zastaví plán; zrušení
        //   jednotlivé vygenerované objednávky jen ten den.
        $planZastaven = false;
        $ruleId = b2b_recurring_id_z_poznamky($orig['poznamka'] ?? '');
        if ($ruleId) {
            $planParam = (string) ($_GET['plan'] ?? '');
            $zastavit = $planParam === 'stop'
                || ($planParam === '' && in_array($orig['typ'] ?? '', ['pravidelna_denni', 'tydenni_plan'], true));
            if ($zastavit) $planZastaven = b2b_recurring_zastavit($pdo, $odberatel_id, $ruleId, 'zrušením objednávky ' . ($orig['cislo'] ?? ('#' . $id)));
        }

        notifikace_zmena_objednavky($pdo, $id, $orig, [
            'zruseno' => true,
            'kdo'     => $odberatel_nazev,
        ]);

        json_response(['ok' => true, 'plan_zastaven' => $planZastaven]);
    } catch (Exception $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        error_log('objednavky DELETE: ' . $e->getMessage());
        json_error('Nepodařilo se zrušit: ' . $e->getMessage(), 500);
    }
}

// =============================================================
// Helper: pošle email odběrateli i pekárně
// (definováno v config.php)
// =============================================================

json_error('Neplatná metoda', 405);
