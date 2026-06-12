<?php
/**
 * 📄 FAKTURY ODBĚRATELE — customer-facing seznam faktur + dobropisů (v3.0.276).
 *
 * GET /api/faktury_odberatele.php  → { faktury: [ {id, cislo, datum_*, castka_*, je_dobropis, puvodni_cislo, stav_uhrady} ] }
 *
 * Zákazník (B2B portál) vidí své faktury i vystavené dobropisy (DOB-) → transparentnost
 * vratek/oprav. Jen vlastní doklady (require_odberatel scope).
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/_schema_lib.php';
cors_headers();
$odberatel_id = require_odberatel();
$pdo = db();
ensure_faktury_schema($pdo); // je_dobropis / puvodni_faktura_id na fresh installu
header('Content-Type: application/json; charset=UTF-8');

$st = $pdo->prepare("
    SELECT f.id, f.cislo, f.datum_vystaveni, f.datum_splatnosti,
           f.castka_celkem, f.castka_uhrazeno, f.je_dobropis, f.puvodni_faktura_id,
           pf.cislo AS puvodni_cislo
    FROM faktury f
    LEFT JOIN faktury pf ON pf.id = f.puvodni_faktura_id
    WHERE f.odberatel_id = :odb
    ORDER BY f.datum_vystaveni DESC, f.id DESC
    LIMIT 100
");
$st->execute(['odb' => $odberatel_id]);

$dnes = date('Y-m-d');
$out = [];
foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $f) {
    $celkem = (float) $f['castka_celkem'];
    $uhr    = (float) $f['castka_uhrazeno'];
    $jeDob  = ((int) $f['je_dobropis']) === 1;
    $stav   = 'uhrazena';
    if (!$jeDob && $uhr + 0.01 < $celkem) {
        $stav = (!empty($f['datum_splatnosti']) && $f['datum_splatnosti'] < $dnes) ? 'po_splatnosti' : 'neuhrazena';
    }
    $out[] = [
        'id'              => (int) $f['id'],
        'cislo'           => $f['cislo'],
        'datum_vystaveni' => $f['datum_vystaveni'],
        'datum_splatnosti'=> $f['datum_splatnosti'],
        'castka_celkem'   => $celkem,
        'castka_uhrazeno' => $uhr,
        'je_dobropis'     => $jeDob,
        'puvodni_cislo'   => $f['puvodni_cislo'],
        'stav_uhrady'     => $stav,
    ];
}
json_response(['faktury' => $out]);
