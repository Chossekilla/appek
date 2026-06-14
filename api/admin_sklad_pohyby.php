<?php
/**
 * 📊 ADMIN SKLAD POHYBY — audit trail pohybů per (sklad × item).
 * PR3 z multi-warehouse spec (2026-05-23).
 *
 * GET   ?sklad_id=N         → historie skladu (limit 100)
 * GET   ?item_typ=&item_id= → historie konkrétní položky napříč sklady
 * GET   ?limit=N            → posledních N pohybů (vše)
 *
 * POST ?action=prijem     {sklad_id, item_typ, item_id, mnozstvi, cena_za_jed?, poznamka?}
 * POST ?action=vydej      {sklad_id, item_typ, item_id, mnozstvi, poznamka?}
 *                         — odmítne pokud mnozstvi > aktuální stav (chyba 409)
 * POST ?action=inventura  {sklad_id, item_typ, item_id, novy_stav, poznamka?}
 *                         — pohyb = (novy_stav - stary_stav)
 * POST ?action=korekce    {sklad_id, item_typ, item_id, mnozstvi, poznamka}
 *                         — manuální korekce (např. ztráta, krádež, nálezy)
 * POST ?action=presun     {sklad_id_z, sklad_id_do, item_typ, item_id, mnozstvi, poznamka?}
 *                         — atomická transakce: výdej z A + příjem do B
 *
 * Vše v transakci: INSERT pohyb + UPDATE sklad_polozky.stav.
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/_admin_auth.php';
require_once __DIR__ . '/_schema_lib.php';
require_once __DIR__ . '/_sklad_lib.php';
cors_headers();
require_admin();

$pdo = db();
ensure_sklady_schema($pdo);
ensure_sklad_polozky_schema($pdo);
ensure_sklad_pohyby_schema($pdo);

$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? '';

// Aktuální uživatel pro audit
$admin = $_SESSION['admin_jmeno'] ?? ($_SESSION['admin_id'] ?? 'admin');

// ─── GET — historie ─────────────────────────────────────────────
if ($method === 'GET') {
    $skladId = (int) ($_GET['sklad_id'] ?? 0);
    $itemTyp = trim((string) ($_GET['item_typ'] ?? ''));
    $itemId  = (int) ($_GET['item_id'] ?? 0);
    $limit   = max(1, min(500, (int) ($_GET['limit'] ?? 100)));

    $where = []; $params = [];
    if ($skladId > 0) { $where[] = '(p.sklad_id = :sid OR p.sklad_id_cil = :sid2)'; $params['sid'] = $skladId; $params['sid2'] = $skladId; }
    if ($itemTyp && $itemId > 0) {
        $where[] = 'p.item_typ = :t AND p.item_id = :iid';
        $params['t'] = $itemTyp; $params['iid'] = $itemId;
    }
    $whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

    $stmt = $pdo->prepare("
        SELECT p.*,
               s.kod  AS sklad_kod,  s.nazev  AS sklad_nazev,
               sc.kod AS sklad_kod_cil, sc.nazev AS sklad_nazev_cil,
               CASE p.item_typ
                   WHEN 'surovina' THEN (SELECT nazev FROM suroviny WHERE id = p.item_id LIMIT 1)
                   WHEN 'vyrobek'  THEN (SELECT nazev FROM vyrobky  WHERE id = p.item_id LIMIT 1)
               END AS item_nazev
        FROM sklad_pohyby_v2 p
        LEFT JOIN sklady s  ON s.id  = p.sklad_id
        LEFT JOIN sklady sc ON sc.id = p.sklad_id_cil
        $whereSql
        ORDER BY p.kdy DESC, p.id DESC
        LIMIT $limit
    ");
    $stmt->execute($params);
    json_response(['pohyby' => $stmt->fetchAll()]);
}

// ─── Helper: získat aktuální stav nebo vytvořit položku ────────
// 🆕 v2.9.286 — $lock=true → FOR UPDATE (pessimistic lock proti race condition)
//              Použij vždy pokud uvnitř transakce a budeš měnit stav.
function get_or_create_polozka(PDO $pdo, int $skladId, string $itemTyp, int $itemId, bool $lock = false): array {
    $sql = "SELECT id, stav FROM sklad_polozky WHERE sklad_id = :s AND item_typ = :t AND item_id = :i LIMIT 1";
    if ($lock) $sql .= " FOR UPDATE";
    $stmt = $pdo->prepare($sql);
    $stmt->execute(['s' => $skladId, 't' => $itemTyp, 'i' => $itemId]);
    $row = $stmt->fetch();
    if ($row) return ['id' => (int) $row['id'], 'stav' => (float) $row['stav']];
    // Auto-create při příjmu (= když položka v skladu ještě není)
    $pdo->prepare("INSERT INTO sklad_polozky (sklad_id, item_typ, item_id, stav) VALUES (:s, :t, :i, 0)")
        ->execute(['s' => $skladId, 't' => $itemTyp, 'i' => $itemId]);
    return ['id' => (int) $pdo->lastInsertId(), 'stav' => 0.0];
}

// ─── POST — pohyby ──────────────────────────────────────────────
if ($method === 'POST') {
    $d = json_input();
    $skladId = (int) ($d['sklad_id'] ?? 0);
    $itemTyp = trim((string) ($d['item_typ'] ?? ''));
    $itemId  = (int) ($d['item_id'] ?? 0);
    $poznamka = trim((string) ($d['poznamka'] ?? ''));

    if (!in_array($action, ['prijem','vydej','inventura','korekce','presun','vratka'], true)) {
        json_error('Neplatná akce: ' . $action, 400);
    }
    if (!in_array($itemTyp, ['surovina','vyrobek'], true)) json_error('item_typ musí být surovina nebo vyrobek');
    if ($itemId <= 0) json_error('Chybí item_id');

    // PŘESUN má speciální handling (2 sklady, atomic transaction)
    if ($action === 'presun') {
        $skladIdZ = (int) ($d['sklad_id_z'] ?? 0);
        $skladIdDo = (int) ($d['sklad_id_do'] ?? 0);
        $mn = (float) ($d['mnozstvi'] ?? 0);
        if ($skladIdZ <= 0 || $skladIdDo <= 0) json_error('Chybí sklad_id_z / sklad_id_do');
        if ($skladIdZ === $skladIdDo) json_error('Zdrojový a cílový sklad jsou stejné');
        if ($mn <= 0) json_error('Množství musí být kladné');

        $pdo->beginTransaction();
        try {
            // 🆕 v2.9.286 — FOR UPDATE lock proti race condition (lost-update problém)
            // Lock v deterministickém pořadí (nižší ID první) → zabráníš deadlocku.
            $orderedIds = [$skladIdZ, $skladIdDo];
            sort($orderedIds);
            $first = $orderedIds[0]; $second = $orderedIds[1];
            // Pre-lock obou (vždy v stejném pořadí napříč concurrent transactions)
            get_or_create_polozka($pdo, $first, $itemTyp, $itemId, true);
            if ($first !== $second) get_or_create_polozka($pdo, $second, $itemTyp, $itemId, true);

            $z = get_or_create_polozka($pdo, $skladIdZ, $itemTyp, $itemId, true);
            $do = get_or_create_polozka($pdo, $skladIdDo, $itemTyp, $itemId, true);
            if ($z['stav'] < $mn) {
                $pdo->rollBack();
                json_error("Nedostatek na zdrojovém skladu (k dispozici: {$z['stav']})", 409);
            }
            $stavZNov = $z['stav'] - $mn;
            $stavDoNov = $do['stav'] + $mn;
            $pdo->prepare("UPDATE sklad_polozky SET stav = :s WHERE id = :id")->execute(['s' => $stavZNov, 'id' => $z['id']]);
            $pdo->prepare("UPDATE sklad_polozky SET stav = :s WHERE id = :id")->execute(['s' => $stavDoNov, 'id' => $do['id']]);
            if ($itemTyp === 'surovina') surovina_recompute_total($pdo, (int) $itemId); // 🆕 v3.0.168 přepočet cache
            // 2 pohyby: výdej ze zdroj + příjem do cíl (oba s typ='presun' a vzájemnými sklad_id)
            $insert = $pdo->prepare("
                INSERT INTO sklad_pohyby_v2 (sklad_id, sklad_id_cil, item_typ, item_id, typ, mnozstvi, stav_pred, stav_po, poznamka, kdo)
                VALUES (:s, :sc, :t, :i, 'presun', :m, :sp, :sP, :p, :k)
            ");
            $insert->execute(['s' => $skladIdZ, 'sc' => $skladIdDo, 't' => $itemTyp, 'i' => $itemId,
                'm' => -$mn, 'sp' => $z['stav'], 'sP' => $stavZNov, 'p' => $poznamka ?: null, 'k' => $admin]);
            $insert->execute(['s' => $skladIdDo, 'sc' => $skladIdZ, 't' => $itemTyp, 'i' => $itemId,
                'm' => $mn, 'sp' => $do['stav'], 'sP' => $stavDoNov, 'p' => $poznamka ?: null, 'k' => $admin]);
            $pdo->commit();
            json_response(['ok' => true, 'stav_z' => $stavZNov, 'stav_do' => $stavDoNov]);
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            error_log('admin_sklad_pohyby presun: ' . $e->getMessage());
            json_error_safe('Chyba při přesunu', $e, 500);
        }
    }

    // Ostatní akce (prijem/vydej/inventura/korekce) — jeden sklad
    if ($skladId <= 0) json_error('Chybí sklad_id');

    $pdo->beginTransaction();
    try {
        // 🆕 v2.9.286 — FOR UPDATE lock pro all single-warehouse akce (prijem/vydej/inventura/korekce)
        $p = get_or_create_polozka($pdo, $skladId, $itemTyp, $itemId, true);
        $stavPred = $p['stav'];
        $mnozstvi = 0;
        $stavPo = $stavPred;

        if ($action === 'vratka') { // 🆕 v3.0.268 — vratka zboží zpět na sklad (matematicky příjem, auditovaný vlastní typ)
            $mnozstvi = (float) ($d['mnozstvi'] ?? 0);
            if ($mnozstvi <= 0) { $pdo->rollBack(); json_error('Množství musí být > 0'); }
            $stavPo = $stavPred + $mnozstvi;
        }
        elseif ($action === 'prijem') {
            $mnozstvi = (float) ($d['mnozstvi'] ?? 0);
            if ($mnozstvi <= 0) { $pdo->rollBack(); json_error('Množství musí být kladné'); }
            $stavPo = $stavPred + $mnozstvi;
        }
        elseif ($action === 'vydej') {
            $mnozstvi = (float) ($d['mnozstvi'] ?? 0);
            if ($mnozstvi <= 0) { $pdo->rollBack(); json_error('Množství musí být kladné'); }
            if ($stavPred < $mnozstvi) {
                $pdo->rollBack();
                json_error("Nedostatek na skladu (k dispozici: $stavPred)", 409);
            }
            $stavPo = $stavPred - $mnozstvi;
            $mnozstvi = -$mnozstvi; // záporná v audit (vydej)
        }
        elseif ($action === 'inventura') {
            $novyStav = (float) ($d['novy_stav'] ?? 0);
            // 🆕 v3.0.162 — inventura nesmí nastavit ZÁPORNÝ stav (fyzicky nesmysl + překlep
            //   -5 místo 5 to tiše uložil). Konzistentní s korekcí (ř. níž), která zápor odmítá.
            //   Dřív: novy_stav=-100 → stav -100, HTTP 200.
            if ($novyStav < 0) { $pdo->rollBack(); json_error('Inventura nesmí být záporná', 400); }
            $mnozstvi = $novyStav - $stavPred;
            $stavPo = $novyStav;
        }
        elseif ($action === 'korekce') {
            $mnozstvi = (float) ($d['mnozstvi'] ?? 0); // může být záporné (např. odpis)
            if ($mnozstvi === 0.0) { $pdo->rollBack(); json_error('Korekce musí být nenulová'); }
            if (!$poznamka) { $pdo->rollBack(); json_error('Korekce vyžaduje poznámku (důvod)'); }
            $stavPo = $stavPred + $mnozstvi;
            if ($stavPo < 0) { $pdo->rollBack(); json_error('Korekce by způsobila záporný stav', 409); }
        }

        $cenaZaJed = isset($d['cena_za_jed']) && $d['cena_za_jed'] !== '' ? (float) $d['cena_za_jed'] : null;
        $pdo->prepare("UPDATE sklad_polozky SET stav = :s WHERE id = :id")
            ->execute(['s' => $stavPo, 'id' => $p['id']]);
        // 🆕 v3.0.332 — putaway: příjem/inventura/vratka může rovnou nastavit pozici (regál/police)
        $pozice = isset($d['pozice']) && $d['pozice'] !== '' ? mb_substr(trim((string) $d['pozice']), 0, 50) : null;
        if ($pozice !== null && in_array($action, ['prijem', 'inventura', 'vratka', 'korekce'], true)) {
            $pdo->prepare("UPDATE sklad_polozky SET pozice = :pz WHERE id = :id")->execute(['pz' => $pozice, 'id' => $p['id']]);
        }
        if ($itemTyp === 'surovina') surovina_recompute_total($pdo, (int) $itemId); // 🆕 v3.0.168 přepočet cache
        $pdo->prepare("
            INSERT INTO sklad_pohyby_v2 (sklad_id, item_typ, item_id, typ, mnozstvi, stav_pred, stav_po, cena_za_jed, poznamka, kdo)
            VALUES (:s, :t, :i, :typ, :m, :sp, :sP, :c, :p, :k)
        ")->execute([
            's' => $skladId, 't' => $itemTyp, 'i' => $itemId, 'typ' => $action,
            'm' => $mnozstvi, 'sp' => $stavPred, 'sP' => $stavPo,
            'c' => $cenaZaJed, 'p' => $poznamka ?: null, 'k' => $admin,
        ]);
        $pdo->commit();
        json_response(['ok' => true, 'stav_po' => $stavPo, 'mnozstvi' => $mnozstvi]);
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        error_log('admin_sklad_pohyby ' . $action . ': ' . $e->getMessage());
        json_error_safe('Chyba', $e, 500);
    }
}

json_error('Method not allowed', 405);
