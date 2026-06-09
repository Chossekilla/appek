<?php
require_once __DIR__ . '/config.php';
cors_headers();
$odberatel_id = require_odberatel();

$pdo = db();

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

        $stmt = $pdo->prepare("
            SELECT p.*, v.nazev AS vyrobek_nazev, v.cislo AS vyrobek_cislo,
                   j.kod AS jednotka
            FROM objednavky_polozky p
            JOIN vyrobky v ON v.id = p.vyrobek_id
            LEFT JOIN jednotky j ON j.id = v.jednotka_id
            WHERE p.objednavka_id = :id
            ORDER BY v.nazev
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

        json_response($obj);
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
        }

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
    json_response($stmt->fetchAll());
}

// =============================================================
// POST - vytvoření nové objednávky
// =============================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $data = json_input();

    // Validace typu
    $typ = $data['typ'] ?? 'jednorazova';
    if (!in_array($typ, ['jednorazova','pravidelna_denni','tydenni_plan'])) {
        json_error('Neplatný typ');
    }
    if (empty($data['datum_dodani'])) json_error('Chybí datum dodání');
    if (empty($data['polozky']) || !is_array($data['polozky'])) {
        json_error('Žádné položky');
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

    $pdo->beginTransaction();
    try {
        // FIX #2: Atomické číslování přes cislovani tabulku
        $cislo = kanal_dalsi_cislo($pdo, 'b2b'); // 🆕 v3.0.212 — vlastní řada B2B-rok-N

        // FIX #4: Načítej ceny POUZE pro aktivní výrobky
        $vyrobek_ids = array_unique(array_map('intval',
                                              array_column($data['polozky'], 'vyrobek_id')));
        if (empty($vyrobek_ids)) throw new Exception('Žádné platné položky');

        $placeholders = implode(',', array_fill(0, count($vyrobek_ids), '?'));
        $stmt = $pdo->prepare("
            SELECT v.id, v.nazev, v.cena_bez_dph, v.min_objednavka, s.sazba
            FROM vyrobky v
            JOIN sazby_dph s ON s.id = v.sazba_dph_id
            WHERE v.aktivni = 1 AND v.id IN ($placeholders)
        ");
        $stmt->execute($vyrobek_ids);
        $cenik = [];
        foreach ($stmt->fetchAll() as $row) $cenik[$row['id']] = $row;

        // Validace položek
        $polozky_clean = [];
        $bez = 0; $dph = 0;
        foreach ($data['polozky'] as $p) {
            $vid = (int) ($p['vyrobek_id'] ?? 0);
            $mn  = (float) ($p['mnozstvi'] ?? 0);

            $c = $cenik[$vid] ?? null;
            if (!$c) throw new Exception("Výrobek $vid není dostupný");
            if ($mn <= 0) throw new Exception("Neplatné množství u výrobku $vid");
            $min = (float) ($c['min_objednavka'] ?? 1);
            if ($mn < $min) throw new Exception("Minimální objednávka výrobku $vid je $min");

            $bez += $c['cena_bez_dph'] * $mn;
            $dph += $c['cena_bez_dph'] * $mn * ($c['sazba'] / 100);

            $polozky_clean[] = [
                'vyrobek_id' => $vid, 'mnozstvi' => $mn,
                'nazev' => $c['nazev'] ?? '',
                'cena' => $c['cena_bez_dph'], 'sazba' => $c['sazba'],
                'poznamka' => $p['poznamka'] ?? null,
            ];
        }

        // Vlož hlavičku (🆕 v3.0.212 — puvod='b2b' → kanál B2B portál, vlastní řada B2B-rok-N)
        $stmt = $pdo->prepare("
            INSERT INTO objednavky (cislo, odberatel_id, misto_dodani_id, typ, datum_dodani,
                                    plati_od, plati_do, dny_v_tydnu,
                                    castka_bez_dph, castka_dph, castka_celkem, poznamka,
                                    zpusob_doruceni, zpusob_platby, puvod)
            VALUES (:c,:o,:m,:t,:d,:po,:pdo_,:dny,:b,:dph,:cel,:pozn,:dor,:plt,'b2b')
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
            'dor' => (substr(trim((string)($data['doprava'] ?? '')), 0, 30) ?: null),
            'plt' => (substr(trim((string)($data['platba'] ?? '')), 0, 30) ?: null),
        ]);
        $obj_id = (int) $pdo->lastInsertId();

        // Vlož položky
        $stmt = $pdo->prepare("
            INSERT INTO objednavky_polozky
                (objednavka_id, vyrobek_id, mnozstvi, cena_bez_dph, sazba_dph, poznamka)
            VALUES (:o,:v,:m,:c,:s,:p)
        ");
        foreach ($polozky_clean as $p) {
            $stmt->execute([
                'o' => $obj_id, 'v' => $p['vyrobek_id'], 'm' => $p['mnozstvi'],
                'c' => $p['cena'], 's' => $p['sazba'], 'p' => $p['poznamka'],
            ]);
        }

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
                $recPolozky = array_map(fn($p) => [
                    'vyrobek_id'    => $p['vyrobek_id'],
                    'vyrobek_nazev' => $p['nazev'] ?? '',
                    'mnozstvi'      => $p['mnozstvi'],
                    'cena_bez_dph'  => $p['cena'],
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
        if ($platba === 'stripe' || $platba === 'gopay' || $platba === 'paypal') {
            require_once __DIR__ . '/_customer_integrace.php';
            $celkemKc = round($bez + $dph, 2);
            $baseUrl = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' ? 'https' : 'http')
                     . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost');

            // Načti email odběratele pro Stripe Checkout / GoPay
            $stmtOdb = $pdo->prepare("SELECT nazev, login_email, email FROM odberatele WHERE id = :id");
            $stmtOdb->execute(['id' => $odberatel_id]);
            $odb = $stmtOdb->fetch() ?: [];
            $custEmail = $odb['login_email'] ?: ($odb['email'] ?: '');
            $custName  = $odb['nazev'] ?: 'Zákazník';

            $payload = [
                'order_no'         => $cislo,
                'amount_kc'        => $celkemKc,
                'currency'         => 'CZK',
                'description'      => 'Objednávka ' . $cislo,
                'customer_email'   => $custEmail,
                'customer_name'    => $custName,
                'return_url'       => $baseUrl . '/b2b/index.html?paid=' . urlencode($cislo),
                'cancel_url'       => $baseUrl . '/b2b/index.html?cancelled=' . urlencode($cislo),
                'notification_url' => $baseUrl . '/api/customer_payment_webhook.php?gw=' . $platba . '&order=' . urlencode($cislo),
            ];
            try {
                if ($platba === 'stripe' && function_exists('customer_int_stripe_create_checkout')) {
                    $sess = customer_int_stripe_create_checkout($payload);
                    if (!empty($sess['ok']) && !empty($sess['session_url'])) {
                        $paymentUrl = $sess['session_url'];
                    }
                }
                if ($platba === 'gopay' && function_exists('customer_int_gopay_create_payment')) {
                    $sess = customer_int_gopay_create_payment($payload);
                    if (!empty($sess['ok']) && !empty($sess['gateway_url'])) {
                        $paymentUrl = $sess['gateway_url'];
                    }
                }
                if ($platba === 'paypal' && function_exists('customer_int_paypal_create_order')) {
                    // PayPal vyžaduje return_url s ?token={order_id} přidáme query parametr ve return
                    $payload['return_url'] = $baseUrl . '/api/paypal_callback.php?gw=paypal&order=' . urlencode($cislo);
                    $sess = customer_int_paypal_create_order($payload);
                    if (!empty($sess['ok']) && !empty($sess['approve_url'])) {
                        $paymentUrl = $sess['approve_url'];
                        // Uložíme PayPal order_id pro pozdější capture
                        try {
                            $pdo->prepare("UPDATE objednavky SET interni_pozn = CONCAT(IFNULL(interni_pozn,''), '\nPayPal order_id: " . ($sess['order_id'] ?? '') . "') WHERE id = :id")
                                ->execute(['id' => $obj_id]);
                        } catch (Throwable $e) { /* idempotent */ }
                    }
                }
            } catch (Throwable $e) {
                error_log('objednavky payment_init (' . $platba . '): ' . $e->getMessage());
            }
        }

        json_response([
            'id' => $obj_id,
            'cislo' => $cislo,
            'platba' => $platba,
            'payment_url' => $paymentUrl,
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

    // Editovatelnost
    $edit = objednavka_editovatelna($pdo, $orig);
    if (!$edit['lze']) json_error($edit['duvod'], 409);

    // Ověř místo dodání
    $misto_id = (int) ($data['misto_dodani_id'] ?? $orig['misto_dodani_id']);
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

    $polozky_clean = [];
    foreach ($polozky as $p) {
        $vid = (int) ($p['vyrobek_id'] ?? 0);
        $mn  = (float) ($p['mnozstvi'] ?? 0);
        if (!$vid || $mn <= 0) continue;
        if (!isset($cenikIdx[$vid])) continue;
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

    try {
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
                castka_bez_dph = :b,
                castka_dph = :d,
                castka_celkem = :c,
                upraveno_kdy = NOW()
            WHERE id = :id
        ")->execute([
            'id' => $id,
            'm'  => $misto_id,
            'p'  => $data['poznamka'] ?? $orig['poznamka'],
            'b'  => round($bez, 2),
            'd'  => round($dph, 2),
            'c'  => round($bez + $dph, 2),
        ]);

        // Smaž staré položky a vlož nové
        $pdo->prepare("DELETE FROM objednavky_polozky WHERE objednavka_id = :id")
            ->execute(['id' => $id]);

        $stmt = $pdo->prepare("
            INSERT INTO objednavky_polozky
                (objednavka_id, vyrobek_id, mnozstvi, cena_bez_dph, sazba_dph, poznamka)
            VALUES (:o, :v, :m, :c, :s, :p)
        ");
        foreach ($polozky_clean as $p) {
            $stmt->execute([
                'o' => $id, 'v' => $p['vyrobek_id'], 'm' => $p['mnozstvi'],
                'c' => $p['cena'], 's' => $p['sazba'], 'p' => $p['poznamka'],
            ]);
        }

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

    $edit = objednavka_editovatelna($pdo, $orig);
    if (!$edit['lze']) json_error($edit['duvod'], 409);

    try {
        $pdo->prepare("UPDATE objednavky SET stav = 'zrusena', upraveno_kdy = NOW() WHERE id = :id")
            ->execute(['id' => $id]);

        $jm = $pdo->prepare("SELECT nazev FROM odberatele WHERE id = :id");
        $jm->execute(['id' => $odberatel_id]);
        $odberatel_nazev = $jm->fetchColumn() ?: 'Odběratel';

        log_zmena_objednavky($pdo, $id, 'odberatel', $odberatel_id, $odberatel_nazev, 'zrusena');

        notifikace_zmena_objednavky($pdo, $id, $orig, [
            'zruseno' => true,
            'kdo'     => $odberatel_nazev,
        ]);

        json_response(['ok' => true]);
    } catch (Exception $e) {
        error_log('objednavky DELETE: ' . $e->getMessage());
        json_error('Nepodařilo se zrušit: ' . $e->getMessage(), 500);
    }
}

// =============================================================
// Helper: pošle email odběrateli i pekárně
// (definováno v config.php)
// =============================================================

json_error('Neplatná metoda', 405);
