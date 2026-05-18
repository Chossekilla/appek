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
cors_headers();
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

// === Sečti částky ze všech DL položek ===
$polozky_stmt = $pdo->prepare("
    SELECT mnozstvi, cena_bez_dph, sazba_dph
    FROM dodaci_list_polozky
    WHERE dodaci_list_id IN ($placeholders)
");
$polozky_stmt->execute($dl_ids);
$bez = 0; $dph_celkem = 0;
foreach ($polozky_stmt->fetchAll(PDO::FETCH_ASSOC) as $p) {
    $b = (float) $p['cena_bez_dph'] * (float) $p['mnozstvi'];
    $bez += $b;
    $dph_celkem += $b * ((float) $p['sazba_dph'] / 100);
}
$bez = round($bez, 2);
$dph_celkem = round($dph_celkem, 2);
$celkem = round($bez + $dph_celkem, 2);

// Misto dodání — vezmi z prvního DL (předpokládáme stejné)
$misto_id = $dls[0]['misto_dodani_id'] ?: null;

// Auto-snapshot
require_once __DIR__ . '/_zaloha_helper.php';
try { zaloha_snapshot($pdo, 'Před vystavením FA z ' . count($dl_ids) . ' DL'); } catch (Throwable $e) {}

$pdo->beginTransaction();
try {
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

    // Naváž všechny DL na fakturu + označ jako fakturované
    $link = $pdo->prepare("INSERT INTO faktury_dodaci_listy (faktura_id, dodaci_list_id) VALUES (:f, :d)");
    $mark = $pdo->prepare("UPDATE dodaci_listy SET fakturovano = 1 WHERE id = :id");
    foreach ($dl_ids as $dl_id) {
        $link->execute(['f' => $fa_id, 'd' => $dl_id]);
        $mark->execute(['id' => $dl_id]);
    }

    $pdo->commit();
    json_response([
        'ok' => true,
        'faktura_id' => $fa_id,
        'cislo' => $cislo,
        'pocet_dl' => count($dl_ids),
        'castka_celkem' => $celkem,
    ], 201);
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    error_log('admin_faktura_z_dl: ' . $e->getMessage());
    json_error('Vystavení faktury selhalo: ' . $e->getMessage(), 500);
}
