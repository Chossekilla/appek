<?php
/**
 * Vystavení JEDNÉ faktury z více vybraných dodacích listů.
 *
 * POST body { dl_ids: [1,2,3], datum_vystaveni?, datum_splatnosti?, poznamka? }
 *
 * Validace:
 *   - všechny DL musí patřit stejnému odběrateli
 *   - žádný z DL nesmí být už fakturován
 *
 * Vrací { ok, faktura_id, cislo }
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/_admin_auth.php';
require_once __DIR__ . '/_schema_lib.php';
cors_headers();
// 🆕 v3.0.11 — auto-migrace faktury snapshot sloupců
ensure_faktury_schema(db());
require_admin();

$pdo = db();
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') json_error('POST only', 405);

$d = json_input();
$dl_ids = array_values(array_unique(array_filter(array_map('intval', (array) ($d['dl_ids'] ?? [])))));
if (count($dl_ids) < 1) json_error('Vyber alespoň 1 dodací list');

$datum_vystaveni  = $d['datum_vystaveni']  ?? date('Y-m-d');
$datum_splatnosti = $d['datum_splatnosti'] ?? null;
$poznamka         = $d['poznamka']         ?? null;

if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $datum_vystaveni)) json_error('Neplatné datum vystavení');

// === Načti DL + ověř, že patří stejnému odběrateli a nejsou fakturované ===
$placeholders = implode(',', array_fill(0, count($dl_ids), '?'));
$stmt = $pdo->prepare("
    SELECT dl.id, dl.cislo, dl.odberatel_id, dl.misto_dodani_id, dl.castka_celkem, dl.fakturovano,
           dl.datum_dodani, dl.datum_vystaveni
    FROM dodaci_listy dl
    WHERE dl.id IN ($placeholders)
");
$stmt->execute($dl_ids);
$dls = $stmt->fetchAll(PDO::FETCH_ASSOC);

if (count($dls) !== count($dl_ids)) json_error('Některé dodací listy nenalezeny', 404);

$odb_ids = array_unique(array_column($dls, 'odberatel_id'));
if (count($odb_ids) > 1) json_error('Vybrané dodací listy musí být ze stejného odběratele');

$obj_id_set = array_unique(array_filter(array_column($dls, 'odberatel_id')));
$odb_id = (int) reset($odb_ids);

// Už fakturované?
$already_invoiced = array_filter($dls, fn($r) => (int) $r['fakturovano'] === 1);
if (!empty($already_invoiced)) {
    $cisla = array_column($already_invoiced, 'cislo');
    json_error('Tyto dodací listy už jsou fakturované: ' . implode(', ', $cisla), 409);
}

// Načti odběratele pro snapshot adresy + splatnost
$stmt = $pdo->prepare("SELECT * FROM odberatele WHERE id = :id");
$stmt->execute(['id' => $odb_id]);
$odb = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$odb) json_error('Odběratel nenalezen', 404);

if (!$datum_splatnosti) {
    $dni = (int) ($odb['splatnost_dni'] ?? 14);
    $datum_splatnosti = date('Y-m-d', strtotime($datum_vystaveni . ' + ' . $dni . ' days'));
}

// Misto dodání — vezmi z prvního DL (předpokládáme stejné)
$misto_id = $dls[0]['misto_dodani_id'] ?: null;

// Auto-snapshot
require_once __DIR__ . '/_zaloha_helper.php';
try { zaloha_snapshot($pdo, 'Před vystavením FA z ' . count($dl_ids) . ' DL'); } catch (Throwable $e) {}

require_once __DIR__ . '/_faktura_lib.php';
$pdo->beginTransaction();
try {
    // 🐛 v3.0.644 — souběh (2 admini / 2 zařízení): kontrola „už fakturováno" byla jen PŘED transakcí
    //   (+ široké okno kvůli zaloha_snapshot) → oba požadavky vystavily fakturu na tentýž DL.
    //   Teď zamkni řádky DL (zamykací čtení jako PRVNÍ v transakci → následná čtení vidí commit
    //   souběžného požadavku) a stav ověř znovu pod zámkem.
    $lk = $pdo->prepare("SELECT id, cislo, fakturovano FROM dodaci_listy WHERE id IN ($placeholders) ORDER BY id FOR UPDATE");
    $lk->execute($dl_ids);
    $zamceno = $lk->fetchAll(PDO::FETCH_ASSOC);
    if (count($zamceno) !== count($dl_ids)) throw new RuntimeException('DL_ZMIZEL');
    $fakt = array_filter($zamceno, fn($r) => (int) $r['fakturovano'] === 1);
    $lnk = $pdo->prepare("SELECT DISTINCT dl.cislo FROM faktury_dodaci_listy fdl JOIN dodaci_listy dl ON dl.id = fdl.dodaci_list_id
                          WHERE fdl.dodaci_list_id IN ($placeholders)");
    $lnk->execute($dl_ids);
    $cislaFakt = array_values(array_unique(array_merge(array_column($fakt, 'cislo'), $lnk->fetchAll(PDO::FETCH_COLUMN))));
    if ($cislaFakt) {
        $pdo->rollBack();
        json_error('Tyto dodací listy už jsou fakturované: ' . implode(', ', $cislaFakt), 409);
    }

    // === Sečti částky ze všech DL položek (až pod zámkem — aktuální řádky) ===
    $s = faktura_soucty_z_dl($pdo, $dl_ids) ?? ['bez' => 0.0, 'dph' => 0.0, 'celkem' => 0.0];
    $bez = $s['bez'];
    $dph_celkem = $s['dph'];
    $celkem = $s['celkem'];

    $cislo = dalsi_cislo($pdo, 'FA', (int) date('Y', strtotime($datum_vystaveni)));
    $vs = preg_replace('/\D/', '', $cislo);

    $pdo->prepare("
        INSERT INTO faktury (
            cislo, odberatel_id, misto_dodani_id,
            datum_vystaveni, datum_dph, datum_splatnosti,
            castka_bez_dph, castka_dph, castka_celkem,
            variabilni_symbol, poznamka, rucni,
            odb_nazev_snapshot, odb_ico_snapshot, odb_dic_snapshot,
            odb_ulice_snapshot, odb_mesto_snapshot, odb_psc_snapshot
        ) VALUES (
            :c, :o, :m,
            :dv, :dd, :ds,
            :b, :dph, :cel,
            :vs, :p, 0,
            :n, :ico, :dic, :ul, :me, :psc
        )
    ")->execute([
        'c' => $cislo, 'o' => $odb_id, 'm' => $misto_id,
        'dv' => $datum_vystaveni, 'dd' => $datum_vystaveni, 'ds' => $datum_splatnosti,
        'b' => $bez, 'dph' => $dph_celkem, 'cel' => $celkem,
        'vs' => $vs, 'p' => $poznamka,
        'n' => $odb['nazev'], 'ico' => $odb['ico'], 'dic' => $odb['dic'],
        'ul' => $odb['ulice'], 'me' => $odb['mesto'], 'psc' => $odb['psc'],
    ]);
    $fa_id = (int) $pdo->lastInsertId();
    require_once __DIR__ . '/_mena_lib.php';
    mena_zdedit_z_dl($pdo, $fa_id, $dl_ids);   // 💱 v3.0.643 — měna a kurz z DL/objednávek (smíšené měny → 409)

    // Naváž všechny DL na fakturu + označ jako fakturované
    $link = $pdo->prepare("INSERT INTO faktury_dodaci_listy (faktura_id, dodaci_list_id) VALUES (:f, :d)");
    $mark = $pdo->prepare("UPDATE dodaci_listy SET fakturovano = 1 WHERE id = :id");
    foreach ($dl_ids as $dl_id) {
        $link->execute(['f' => $fa_id, 'd' => $dl_id]);
        $mark->execute(['id' => $dl_id]);
    }

    mena_ulozit_castku($pdo, 'faktury', $fa_id);   // 💱 v3.0.643
    $pdo->commit();
    json_response([
        'ok' => true,
        'faktura_id' => $fa_id,
        'cislo' => $cislo,
        'pocet_dl' => count($dl_ids),
        'castka_celkem' => $celkem,
    ], 201);
} catch (MenaException $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    json_error($e->getMessage(), $e->http);
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    if ($e->getMessage() === 'DL_ZMIZEL') json_error('Některé dodací listy mezitím zmizely', 404);
    error_log('admin_faktura_z_dl: ' . $e->getMessage());
    json_error_safe('Vystavení faktury selhalo', $e, 500);
}
