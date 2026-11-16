<?php
/**
 * Testovací doklady v TESTOVACÍ DB (idempotentní — založí jen když chybí):
 *   - odběratel „APPEK TEST s.r.o." (IČO 99999999)
 *   - FA-TEST-0001: ruční faktura se 3 sazbami DPH (21 / 12 / 0 %), haléřové ceny,
 *     speciální znaky (& < > " „ “ €, emoji), název položky > 90 znaků
 *   - DOB-TEST-0001: dobropis k FA-TEST-0001 (záporné ceny, 2 sazby)
 * Součty hlavičky počítané stejně jako admin_faktury.php (Σ nezaokrouhleně → round 2).
 */
function fixture_testovaci_doklady(PDO $pdo): array {
    $st = $pdo->prepare("SELECT id FROM odberatele WHERE ico = '99999999' AND nazev = 'APPEK TEST s.r.o.' LIMIT 1");
    $st->execute();
    $odb = (int) $st->fetchColumn();
    if (!$odb) {
        $pdo->prepare("INSERT INTO odberatele (cislo, nazev, ico, dic, ulice, mesto, psc, email, telefon, splatnost_dni)
                       VALUES ('TEST1', 'APPEK TEST s.r.o.', '99999999', 'CZ99999999', 'Testovací 1 & <2>', 'Praha', '110 00', 'test@example.invalid', '+420 000 000 000', 10)")
            ->execute();
        $odb = (int) $pdo->lastInsertId();
    }
    $fa = fixture_faktura($pdo, 'FA-TEST-0001', $odb, false, null, [
        ['Čokoládový dort „Sacher" & <speciál> € 🎂 — velmi dlouhý název položky, který přesahuje devadesát znaků limitu POHODY', 3, 'ks', 33.33, 21],
        ['Rohlík tukový', 7, 'ks', 1.99, 12],
        ['Mouka hladká', 2.5, 'kg', 12.99, 12],
        ['Zálohovaná přepravka (osvobozeno)', 1, 'ks', 100.00, 0],
    ], 'APPEK integration test — vícesazbová faktura');
    $dob = fixture_faktura($pdo, 'DOB-TEST-0001', $odb, true, $fa, [
        ['Rohlík tukový', 2, 'ks', -1.99, 12],
        ['Čokoládový dort „Sacher" & <speciál>', 1, 'ks', -33.33, 21],
    ], 'APPEK integration test — dobropis');
    return ['odberatel_id' => $odb, 'faktura_id' => $fa, 'dobropis_id' => $dob];
}

function fixture_faktura(PDO $pdo, string $cislo, int $odb, bool $dobropis, ?int $puvodni, array $polozky, string $pozn): int {
    $st = $pdo->prepare("SELECT id FROM faktury WHERE cislo = :c");
    $st->execute(['c' => $cislo]);
    if ($id = (int) $st->fetchColumn()) return $id;
    $bez = 0.0; $dph = 0.0;
    foreach ($polozky as $p) { $b = $p[1] * $p[3]; $bez += $b; $dph += $b * $p[4] / 100; }
    $bez = round($bez, 2); $dph = round($dph, 2);
    $vs = ($dobropis ? '9' : '') . '20269' . substr(preg_replace('/\D/', '', $cislo), -3);
    $pdo->prepare("INSERT INTO faktury (cislo, odberatel_id, datum_vystaveni, datum_dph, datum_splatnosti, castka_bez_dph, castka_dph, castka_celkem,
                                        variabilni_symbol, rucni, poznamka, odb_nazev_snapshot, odb_ico_snapshot, odb_dic_snapshot,
                                        odb_ulice_snapshot, odb_mesto_snapshot, odb_psc_snapshot, je_dobropis, puvodni_faktura_id)
                   VALUES (:c, :o, '2026-11-10', '2026-11-09', '2026-11-24', :b, :d, :t, :vs, 1, :p,
                           'APPEK TEST s.r.o.', '99999999', 'CZ99999999', 'Testovací 1 & <2>', 'Praha', '110 00', :dob, :puv)")
        ->execute(['c' => $cislo, 'o' => $odb, 'b' => $bez, 'd' => $dph, 't' => round($bez + $dph, 2), 'vs' => $vs, 'p' => $pozn,
                   'dob' => $dobropis ? 1 : 0, 'puv' => $puvodni]);
    $id = (int) $pdo->lastInsertId();
    $ins = $pdo->prepare("INSERT INTO faktura_polozky (faktura_id, vyrobek_id, vyrobek_nazev, mnozstvi, jednotka, cena_bez_dph, sazba_dph, poradi, vyrobek_cislo)
                          VALUES (:f, NULL, :n, :m, :j, :c, :s, :p, :k)");
    foreach ($polozky as $i => $p) {
        $ins->execute(['f' => $id, 'n' => $p[0], 'm' => $p[1], 'j' => $p[2], 'c' => $p[3], 's' => $p[4], 'p' => $i + 1, 'k' => 'TST-' . ($i + 1)]);
    }
    return $id;
}

/**
 * Nezávislý přepočet rozpisu DPH přesně podle api/faktura.php (nacti_fakturu) — referenční hodnota PDF.
 * Položky bere stejnou cestou jako PDF pro ruční faktury (faktura_polozky).
 */
function reference_pdf_rozpis(PDO $pdo, int $id): array {
    $f = $pdo->query("SELECT castka_bez_dph, castka_dph, castka_celkem FROM faktury WHERE id = " . (int) $id)->fetch(PDO::FETCH_ASSOC);
    $pol = $pdo->query("SELECT mnozstvi, cena_bez_dph, sazba_dph FROM faktura_polozky WHERE faktura_id = " . (int) $id)->fetchAll(PDO::FETCH_ASSOC);
    $r = [];
    foreach ($pol as $p) {
        $bez = $p['cena_bez_dph'] * $p['mnozstvi'];
        $s = (float) $p['sazba_dph'];
        if (!isset($r[(string) $s])) $r[(string) $s] = ['bez' => 0, 'dph' => 0];
        $r[(string) $s]['bez'] += $bez; $r[(string) $s]['dph'] += $bez * $s / 100;
    }
    foreach ($r as $k => $x) $r[$k] = ['bez' => round($x['bez'], 2), 'dph' => round($x['dph'], 2)];
    $maxK = null; $maxB = -INF;
    foreach ($r as $k => $x) if (abs($x['bez']) > $maxB) { $maxB = abs($x['bez']); $maxK = $k; }
    if ($maxK !== null) {
        $r[$maxK]['bez'] = round($r[$maxK]['bez'] + round((float) $f['castka_bez_dph'], 2) - array_sum(array_column($r, 'bez')), 2);
        $r[$maxK]['dph'] = round($r[$maxK]['dph'] + round((float) $f['castka_dph'], 2) - array_sum(array_column($r, 'dph')), 2);
    }
    return ['rozpis' => $r, 'celkem' => round((float) $f['castka_celkem'], 2), 'bez' => round((float) $f['castka_bez_dph'], 2), 'dph' => round((float) $f['castka_dph'], 2)];
}
