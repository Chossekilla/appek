<?php
/**
 * Hromadné akce nad objednávkami:
 *   - vytvořit dodací listy (DL) — 1 DL per objednávka, ale spuštěno hromadně
 *   - vytvořit faktury (FA)     — seskupení per odběratel, jedna FA = N DL
 *
 * Vstup (POST JSON):
 *   {
 *     action: 'dl' | 'fa' | 'preview',
 *     objednavka_ids: [int, ...],
 *     // FA-only:
 *     datum_vystaveni?: 'YYYY-MM-DD',  (default: dnes)
 *     splatnost_dni?:   int,           (override z odběratele)
 *     // DL-only:
 *     datum_vystaveni_dl?: 'YYYY-MM-DD' (default: datum_dodani objednávky)
 *   }
 *
 * Výstup:
 *   { ok: true, vytvoreno: { dl: [ids], fa: [ids] }, preskoceno: [ {id, duvod} ], skupin: int }
 *
 * Pravidla:
 *   - 'preview' jen vrátí seskupení a varování — nic nemění.
 *   - DL: pokud objednávka už má DL, přeskočí se.
 *   - FA: ke všem objednávkám se nejdřív zajistí DL (vytvoří se chybějící),
 *         pak se objednávky seskupí podle odberatel_id a vytvoří se 1 FA per skupina.
 *         Faktura linkuje všechny DL přes faktury_dodaci_listy.
 *   - Stav objednávek se NEMĚNÍ automaticky (zůstává např. potvrzena, expedovana atp.).
 *     Faktura ale objednávku zamkne (existuje DL).
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/_admin_auth.php';
cors_headers();
require_admin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_error('Metoda musí být POST', 405);
}

$pdo = db();

// 🔄 v2.9.175 — DDL přesunut do _schema_lib.php (sdílený helper, idempotentní).
require_once __DIR__ . '/_schema_lib.php';
require_once __DIR__ . '/_platby_lib.php'; // 🆕 v3.0.279 — splatnost faktury dle platební metody
ensure_dodaci_list_polozky_schema($pdo);
ensure_dodaci_listy_schema($pdo);
ensure_objednavky_schema($pdo); // zajistí o.zpusob_platby (fresh-install safe)

$d = json_input();

$action = $d['action'] ?? '';
$ids    = $d['objednavka_ids'] ?? [];
if (!is_array($ids) || count($ids) === 0) json_error('Vyber alespoň jednu objednávku');
$ids = array_values(array_unique(array_map('intval', $ids)));
$ids = array_filter($ids, fn($x) => $x > 0);
if (count($ids) === 0) json_error('Neplatné ID objednávek');

$placeholders = implode(',', array_fill(0, count($ids), '?'));

// =============================================================
// Načti objednávky se vším potřebným
// =============================================================
$stmt = $pdo->prepare("
    SELECT o.id, o.cislo, o.odberatel_id, o.misto_dodani_id, o.datum_dodani,
           o.datum_objednani, o.castka_bez_dph, o.castka_dph, o.castka_celkem,
           o.stav, o.zpusob_platby,
           od.nazev AS odberatel_nazev, od.splatnost_dni,
           md.nazev AS misto_nazev,
           (SELECT COUNT(*) FROM objednavky_polozky op WHERE op.objednavka_id = o.id) AS pocet_polozek,
           (SELECT id FROM dodaci_listy dl WHERE dl.objednavka_id = o.id LIMIT 1) AS dl_id,
           (SELECT MIN(fdl.faktura_id)
              FROM dodaci_listy dl2
              JOIN faktury_dodaci_listy fdl ON fdl.dodaci_list_id = dl2.id
              WHERE dl2.objednavka_id = o.id) AS faktura_id
    FROM objednavky o
    JOIN odberatele od ON od.id = o.odberatel_id
    LEFT JOIN mista_dodani md ON md.id = o.misto_dodani_id
    WHERE o.id IN ($placeholders)
");
$stmt->execute($ids);
$obj_list = $stmt->fetchAll(PDO::FETCH_ASSOC);

if (count($obj_list) === 0) json_error('Žádná z vybraných objednávek nebyla nalezena');

// =============================================================
// PREVIEW — vrátí seskupení a varování
// =============================================================
if ($action === 'preview') {
    $skupiny_dl = []; // klíč: odb_id . '|' . misto_id
    $skupiny_fa = []; // klíč: odb_id
    foreach ($obj_list as $o) {
        $kdl = $o['odberatel_id'] . '|' . ($o['misto_dodani_id'] ?? 0);
        if (!isset($skupiny_dl[$kdl])) {
            $skupiny_dl[$kdl] = [
                'odberatel_id'    => (int) $o['odberatel_id'],
                'odberatel_nazev' => $o['odberatel_nazev'],
                'misto_dodani_id' => $o['misto_dodani_id'] ? (int) $o['misto_dodani_id'] : null,
                'misto_nazev'     => $o['misto_nazev'],
                'objednavky'      => [],
                'datumy'          => [],
            ];
        }
        $skupiny_dl[$kdl]['objednavky'][] = (int) $o['id'];
        $skupiny_dl[$kdl]['datumy'][] = $o['datum_dodani'];

        $kfa = (string) $o['odberatel_id'];
        if (!isset($skupiny_fa[$kfa])) {
            $skupiny_fa[$kfa] = [
                'odberatel_id'    => (int) $o['odberatel_id'],
                'odberatel_nazev' => $o['odberatel_nazev'],
                'splatnost_dni'   => (int) ($o['splatnost_dni'] ?: 14),
                'objednavky'      => [],
                'castka_celkem'   => 0,
            ];
        }
        $skupiny_fa[$kfa]['objednavky'][] = (int) $o['id'];
        $skupiny_fa[$kfa]['castka_celkem'] += (float) $o['castka_celkem'];
    }
    json_response([
        'ok'         => true,
        'objednavky' => $obj_list,
        'skupiny_dl' => array_values($skupiny_dl),
        'skupiny_fa' => array_values($skupiny_fa),
    ]);
}

// =============================================================
// HROMADNÉ DL — 1 DL per objednávka
// =============================================================
function vytvor_dl_pro_objednavku(PDO $pdo, array $obj, ?string $datum_vystaveni_dl): int {
    $datum_vystaveni = $datum_vystaveni_dl ?: date('Y-m-d');
    $cislo_dl = dalsi_cislo($pdo, 'DL', (int) date('Y', strtotime($datum_vystaveni)));

    $pdo->prepare("
        INSERT INTO dodaci_listy (cislo, objednavka_id, odberatel_id, misto_dodani_id,
                                  datum_vystaveni, datum_dodani, castka_celkem, fakturovano, rucni)
        VALUES (:c, :o, :odb, :m, :dv, :dd, :cel, 0, 0)
    ")->execute([
        'c'   => $cislo_dl,
        'o'   => $obj['id'],
        'odb' => $obj['odberatel_id'],
        'm'   => $obj['misto_dodani_id'],
        'dv'  => $datum_vystaveni,
        'dd'  => $obj['datum_dodani'],
        'cel' => round((float) $obj['castka_celkem'], 2),
    ]);
    $dl_id = (int) $pdo->lastInsertId();

    // Snapshot položek (LEFT JOIN — volné řádky)
    $pdo->prepare("
        INSERT INTO dodaci_list_polozky
            (dodaci_list_id, vyrobek_id, vyrobek_cislo, vyrobek_nazev, jednotka,
             mnozstvi, cena_bez_dph, sazba_dph, poznamka)
        SELECT :dl, p.vyrobek_id, v.cislo,
               COALESCE(v.nazev, p.vyrobek_nazev),
               COALESCE(j.kod, p.jednotka),
               p.mnozstvi, p.cena_bez_dph, p.sazba_dph, p.poznamka
        FROM objednavky_polozky p
        LEFT JOIN vyrobky v ON v.id = p.vyrobek_id
        LEFT JOIN jednotky j ON j.id = v.jednotka_id
        WHERE p.objednavka_id = :o
    ")->execute(['dl' => $dl_id, 'o' => $obj['id']]);

    return $dl_id;
}

if ($action === 'dl') {
    $datum_vystaveni_dl = $d['datum_vystaveni_dl'] ?? null;
    if ($datum_vystaveni_dl && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $datum_vystaveni_dl)) {
        json_error('Neplatné datum vystavení DL');
    }

    $vytvoreno = [];
    $preskoceno = [];

    $pdo->beginTransaction();
    try {
        foreach ($obj_list as $o) {
            if ($o['stav'] === 'zrusena') {
                $preskoceno[] = ['id' => (int) $o['id'], 'cislo' => $o['cislo'], 'duvod' => 'Objednávka je zrušena'];
                continue;
            }
            if ($o['pocet_polozek'] == 0) {
                $preskoceno[] = ['id' => (int) $o['id'], 'cislo' => $o['cislo'], 'duvod' => 'Objednávka nemá žádné položky'];
                continue;
            }
            if ($o['dl_id']) {
                $preskoceno[] = ['id' => (int) $o['id'], 'cislo' => $o['cislo'], 'duvod' => 'Už má DL #' . $o['dl_id']];
                continue;
            }
            $dl_id = vytvor_dl_pro_objednavku($pdo, $o, $datum_vystaveni_dl);
            $vytvoreno[] = ['objednavka_id' => (int) $o['id'], 'cislo' => $o['cislo'], 'dl_id' => $dl_id];
        }
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        json_error_safe('Chyba při vytváření DL', $e, 500);
    }

    json_response([
        'ok'         => true,
        'akce'       => 'dl',
        'vytvoreno'  => $vytvoreno,
        'preskoceno' => $preskoceno,
    ]);
}

// =============================================================
// HROMADNÉ FA — seskupení per odběratel
// =============================================================
if ($action === 'fa') {
    $datum_vystaveni = $d['datum_vystaveni'] ?? date('Y-m-d');
    $splatnost_override = isset($d['splatnost_dni']) ? max(0, (int) $d['splatnost_dni']) : null;
    $platbyCfg = platby_config_load($pdo)['methods_config']; // 🆕 v3.0.279 — splatnost dle metody

    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $datum_vystaveni)) {
        json_error('Neplatné datum vystavení');
    }

    // Seskupit per odběratel
    $skupiny = [];
    $preskoceno = [];

    foreach ($obj_list as $o) {
        if ($o['stav'] === 'zrusena') {
            $preskoceno[] = ['id' => (int) $o['id'], 'cislo' => $o['cislo'], 'duvod' => 'Objednávka je zrušena'];
            continue;
        }
        if ($o['pocet_polozek'] == 0) {
            $preskoceno[] = ['id' => (int) $o['id'], 'cislo' => $o['cislo'], 'duvod' => 'Objednávka nemá žádné položky'];
            continue;
        }
        if ($o['faktura_id']) {
            $preskoceno[] = ['id' => (int) $o['id'], 'cislo' => $o['cislo'], 'duvod' => 'Už je na faktuře #' . $o['faktura_id']];
            continue;
        }
        $k = (int) $o['odberatel_id'];
        if (!isset($skupiny[$k])) {
            $skupiny[$k] = [
                'odberatel_id'    => $k,
                'odberatel_nazev' => $o['odberatel_nazev'],
                'splatnost_dni'   => (int) ($o['splatnost_dni'] ?: 14),
                'platby'          => [],
                'objednavky'      => [],
            ];
        }
        if (!empty($o['zpusob_platby'])) $skupiny[$k]['platby'][] = $o['zpusob_platby'];
        $skupiny[$k]['objednavky'][] = $o;
    }

    if (count($skupiny) === 0) {
        json_response([
            'ok' => true,
            'akce' => 'fa',
            'vytvoreno' => [],
            'preskoceno' => $preskoceno,
            'message' => 'Nic k vyfakturování',
        ]);
    }

    $vytvoreno = [];

    $pdo->beginTransaction();
    try {
        foreach ($skupiny as $sk) {
            $dl_ids = [];
            $sum_bez = 0; $sum_dph = 0; $sum_cel = 0;

            // Zajisti DL pro každou objednávku ve skupině
            foreach ($sk['objednavky'] as $o) {
                if ($o['dl_id']) {
                    $dl_id = (int) $o['dl_id'];
                    // Zajisti že je označen jako fakturovaný
                    $pdo->prepare("UPDATE dodaci_listy SET fakturovano = 1 WHERE id = :id")
                        ->execute(['id' => $dl_id]);
                } else {
                    $dl_id = vytvor_dl_pro_objednavku($pdo, $o, null);
                    // Označ jako fakturovaný
                    $pdo->prepare("UPDATE dodaci_listy SET fakturovano = 1 WHERE id = :id")
                        ->execute(['id' => $dl_id]);
                }
                $dl_ids[] = $dl_id;
                $sum_bez += (float) $o['castka_bez_dph'];
                $sum_dph += (float) $o['castka_dph'];
                $sum_cel += (float) $o['castka_celkem'];
            }

            // Vytvoř fakturu
            $cislo_fa = dalsi_cislo($pdo, 'FA', (int) date('Y', strtotime($datum_vystaveni)));
            $vs = preg_replace('/\D/', '', $cislo_fa);
            // 🆕 v3.0.279 — splatnost: jednotná platební metoda skupiny s nastavenou splatností (config) PŘEBÍJÍ zákazníka.
            //   Když objednávky skupiny mají různé metody → fallback na splatnost odběratele.
            $methodSpl = null;
            $metody = array_values(array_unique(array_filter($sk['platby'] ?? [])));
            if (count($metody) === 1 && isset($platbyCfg[$metody[0]]['splatnost_dni']) && $platbyCfg[$metody[0]]['splatnost_dni'] !== '') {
                $methodSpl = (int) $platbyCfg[$metody[0]]['splatnost_dni'];
            }
            $spl_dni = $splatnost_override !== null ? $splatnost_override : ($methodSpl !== null ? $methodSpl : $sk['splatnost_dni']);

            // 🐛 fix v2.9.169 — PDO native prepared statements neumožňují reuse stejného
            // pojmenovaného placeholderu 3x (PDO::ATTR_EMULATE_PREPARES=false). Místo
            // 3× :dv máme tři distinct placeholdery se stejnou hodnotou.
            $pdo->prepare("
                INSERT INTO faktury (cislo, odberatel_id, datum_vystaveni, datum_dph, datum_splatnosti,
                                     castka_bez_dph, castka_dph, castka_celkem, variabilni_symbol)
                VALUES (:c, :o, :dv1, :dv2, DATE_ADD(:dv3, INTERVAL :sp DAY), :bez, :dph, :cel, :vs)
            ")->execute([
                'c'   => $cislo_fa,
                'o'   => $sk['odberatel_id'],
                'dv1' => $datum_vystaveni,
                'dv2' => $datum_vystaveni,
                'dv3' => $datum_vystaveni,
                'sp'  => $spl_dni,
                'bez' => round($sum_bez, 2),
                'dph' => round($sum_dph, 2),
                'cel' => round($sum_cel, 2),
                'vs'  => $vs,
            ]);
            $fa_id = (int) $pdo->lastInsertId();

            // Linkuj všechny DL k faktuře
            $stmt = $pdo->prepare("INSERT INTO faktury_dodaci_listy (faktura_id, dodaci_list_id) VALUES (:f, :d)");
            foreach ($dl_ids as $dl_id) {
                $stmt->execute(['f' => $fa_id, 'd' => $dl_id]);
            }

            $vytvoreno[] = [
                'faktura_id'      => $fa_id,
                'cislo'           => $cislo_fa,
                'odberatel_id'    => $sk['odberatel_id'],
                'odberatel_nazev' => $sk['odberatel_nazev'],
                'pocet_objednavek'=> count($sk['objednavky']),
                'pocet_dl'        => count($dl_ids),
                'castka_celkem'   => round($sum_cel, 2),
            ];
        }

        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        json_error_safe('Chyba při vytváření faktur', $e, 500);
    }

    json_response([
        'ok'         => true,
        'akce'       => 'fa',
        'vytvoreno'  => $vytvoreno,
        'preskoceno' => $preskoceno,
    ]);
}

json_error('Neznámá akce. Použij action: "dl", "fa" nebo "preview".');
