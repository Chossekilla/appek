<?php
// 🆕 v3.0.168 — SJEDNOCENÝ SKLAD: sklad_polozky = zdroj pravdy,
// suroviny.stock_aktualni = odvozený součet (cache).
// Sdílené funkce pro POS odpis, sklad obrazovku, výrobu, suroviny.
if (!function_exists('sklad_default_id')) {

/** Primární (výchozí) sklad = nejnižší aktivní id. 0 = žádný sklad. */
function sklad_default_id(PDO $pdo): int {
    return (int) $pdo->query("SELECT id FROM sklady WHERE COALESCE(aktivni,1)=1 ORDER BY id LIMIT 1")->fetchColumn();
}

/** Domovský sklad suroviny (fallback = primární). */
function surovina_home_sklad(PDO $pdo, int $surovinaId): int {
    $st = $pdo->prepare("SELECT domovsky_sklad_id FROM suroviny WHERE id=:id");
    $st->execute(['id' => $surovinaId]);
    $home = (int) $st->fetchColumn();
    return $home > 0 ? $home : sklad_default_id($pdo);
}

/** Vrať id řádku sklad_polozky; když neexistuje, vytvoř se stavem 0. */
function sklad_polozky_ensure(PDO $pdo, int $skladId, string $typ, int $itemId): int {
    $st = $pdo->prepare("SELECT id FROM sklad_polozky WHERE sklad_id=:s AND item_typ=:t AND item_id=:i LIMIT 1");
    $st->execute(['s' => $skladId, 't' => $typ, 'i' => $itemId]);
    $id = (int) $st->fetchColumn();
    if ($id) return $id;
    $pdo->prepare("INSERT INTO sklad_polozky (sklad_id,item_typ,item_id,stav) VALUES (:s,:t,:i,0)")
        ->execute(['s' => $skladId, 't' => $typ, 'i' => $itemId]);
    return (int) $pdo->lastInsertId();
}

/** Přepočítej cache: suroviny.stock_aktualni = SUM(sklad_polozky.stav) přes sklady. */
function surovina_recompute_total(PDO $pdo, int $surovinaId): void {
    $pdo->prepare("UPDATE suroviny SET stock_aktualni = (
        SELECT COALESCE(SUM(stav),0) FROM sklad_polozky WHERE item_typ='surovina' AND item_id=:a
    ) WHERE id=:b")->execute(['a' => $surovinaId, 'b' => $surovinaId]);
}

/** Idempotentní migrace A→B. Volá se 1× při načtení admin_suroviny (GET). */
function sklad_unify_migrate(PDO $pdo): void {
    try { $pdo->exec("ALTER TABLE suroviny ADD COLUMN IF NOT EXISTS domovsky_sklad_id INT NULL"); } catch (Throwable $e) {}
    $def = sklad_default_id($pdo);
    if ($def <= 0) return; // žádný sklad → nemigruj
    // levný guard — když mají všechny suroviny domovský sklad i řádek v B, je hotovo
    $pending = (int) $pdo->query("SELECT COUNT(*) FROM suroviny s WHERE s.domovsky_sklad_id IS NULL OR s.domovsky_sklad_id=0 OR NOT EXISTS(SELECT 1 FROM sklad_polozky p WHERE p.item_typ='surovina' AND p.item_id=s.id)")->fetchColumn();
    if ($pending === 0) return; // už sjednoceno → neopakuj loop
    $pdo->prepare("UPDATE suroviny SET domovsky_sklad_id=:d WHERE domovsky_sklad_id IS NULL OR domovsky_sklad_id=0")->execute(['d' => $def]);
    $rows = $pdo->query("SELECT id, COALESCE(stock_aktualni,0) AS s, COALESCE(domovsky_sklad_id,$def) AS dom FROM suroviny")->fetchAll();
    $has = $pdo->prepare("SELECT COUNT(*) FROM sklad_polozky WHERE item_typ='surovina' AND item_id=:i");
    $ins = $pdo->prepare("INSERT INTO sklad_polozky (sklad_id,item_typ,item_id,stav) VALUES (:s,'surovina',:i,:st)");
    foreach ($rows as $r) {
        $sid = (int) $r['id'];
        $has->execute(['i' => $sid]);
        if ((int) $has->fetchColumn() === 0) {
            // surovina nemá řádek v B → přenes A-zásobu do domovského skladu
            $ins->execute(['s' => (int) $r['dom'], 'i' => $sid, 'st' => (float) $r['s']]);
        }
        surovina_recompute_total($pdo, $sid);
    }
}

/** 🆕 v3.0.395 — VRATKA NA SKLAD: vrácené HOTOVÉ výrobky zpět na sklad (+stav),
 *  auditovaný pohyb typu 'vratka' (item_typ='vyrobek'). Zrcadlo POS odpisu polotovarů,
 *  ale s +qty. Suroviny NEřeší (výrobek je hotový, ingredience zůstávají spotřebované).
 *  Soft-fail per řádek — vratka peněz se nikdy nesmí rozbít kvůli skladu.
 *  $lines: [{vyrobek_id, mnozstvi}] — mnozstvi = kladné množství k naskladnění.
 *  Vrací počet úspěšně naskladněných řádků. */
function stock_restock_products(PDO $pdo, array $lines, string $label, string $kdo = 'systém'): int {
    // 🆕 v3.0.397 — zajisti 'vratka' ENUM v sklad_pohyby_v2 (upgrade z <v268 bez otevřené sklad
    //   obrazovky → ENUM bez 'vratka' → INSERT by tiše spadl v soft-failu). Idempotentní, 1×/request.
    static $ensured = false;
    if (!$ensured) { $ensured = true; require_once __DIR__ . '/_schema_lib.php';
        if (function_exists('ensure_sklad_pohyby_schema')) { try { ensure_sklad_pohyby_schema($pdo); } catch (Throwable $e) {} } }
    $sklad = sklad_default_id($pdo);
    if ($sklad <= 0) return 0;
    $done = 0;
    foreach ($lines as $l) {
        $vid = (int) ($l['vyrobek_id'] ?? 0);
        $qty = abs((float) ($l['mnozstvi'] ?? 0));
        if ($vid <= 0 || $qty <= 0) continue;
        // 🆕 v3.0.396 — GATE: naskladni JEN stockovaný výrobek (sleduje_sklad=1). Made-to-order
        //   (sleduje_sklad=0) nemá integrovaný sklad hotových kusů → +stav = fantom, který nic
        //   nespotřebuje (prodej rozpadá na suroviny; výroba SKLADEM čte jen sleduje_sklad=1).
        try { $sled = (int) $pdo->query("SELECT COALESCE(sleduje_sklad,0) FROM vyrobky WHERE id=" . $vid)->fetchColumn(); }
        catch (Throwable $e) { $sled = 0; }
        if ($sled !== 1) continue;
        try {
            $rowId = sklad_polozky_ensure($pdo, $sklad, 'vyrobek', $vid);
            $pdo->prepare("UPDATE sklad_polozky SET stav = stav + :mn WHERE id = :r")->execute(['mn' => $qty, 'r' => $rowId]);
            $po   = (float) $pdo->query("SELECT stav FROM sklad_polozky WHERE id = " . (int) $rowId)->fetchColumn();
            $pred = $po - $qty;
            $pdo->prepare("INSERT INTO sklad_pohyby_v2 (sklad_id,item_typ,item_id,typ,mnozstvi,stav_pred,stav_po,poznamka,kdo,kdy)
                           VALUES (:s,'vyrobek',:i,'vratka',:mn,:pr,:po,:pz,:kdo,NOW())")
                ->execute(['s' => $sklad, 'i' => $vid, 'mn' => $qty, 'pr' => $pred, 'po' => $po, 'pz' => 'Vratka na sklad — ' . $label, 'kdo' => $kdo]);
            $done++;
        } catch (Throwable $e) { /* soft-fail per řádek */ }
    }
    return $done;
}

/** 🔒 v3.0.454 — schema pro karanténu/blokaci šarží (hold). Idempotentní. */
function ensure_sarze_stav_schema(PDO $pdo): void {
    $pdo->exec("CREATE TABLE IF NOT EXISTS sklad_sarze_stav (
        id INT AUTO_INCREMENT PRIMARY KEY,
        surovina_id INT NOT NULL,
        sarze VARCHAR(80) NOT NULL,
        stav ENUM('hold','ok') NOT NULL DEFAULT 'hold',
        poznamka VARCHAR(255) NULL,
        kdo VARCHAR(120) NULL,
        kdy DATETIME DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uniq_sur_sarze (surovina_id, sarze),
        INDEX idx_sur (surovina_id),
        INDEX idx_stav (stav)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
}

/**
 * 🔎 v3.0.454 — detekuj problémové šarže mezi zadanými surovinami:
 *   - prošlé (příjem s datum_spotreby < dnes)
 *   - držené v karanténě (sklad_sarze_stav.stav='hold')
 * Vrací pole ['surovina_id','nazev','sarze','datum_spotreby'|null,'poznamka'?,'duvod'=>'expirace'|'karantena'].
 * Read-only. Používá výrobní precheck i sledovatelnost.
 */
function sarze_problem_batches(PDO $pdo, array $surIds): array {
    $surIds = array_values(array_unique(array_filter(array_map('intval', $surIds), fn($x) => $x > 0)));
    if (empty($surIds)) return [];
    ensure_sarze_stav_schema($pdo);
    $in = implode(',', array_fill(0, count($surIds), '?'));
    $out = [];
    // prošlé šarže (příjem/vratka s datum_spotreby v minulosti)
    $q1 = $pdo->prepare("SELECT p.item_id surovina_id, s.nazev, p.sarze, p.datum_spotreby
                         FROM sklad_pohyby_v2 p JOIN suroviny s ON s.id = p.item_id
                         WHERE p.item_typ='surovina' AND p.typ IN ('prijem','vratka')
                           AND p.item_id IN ($in) AND p.datum_spotreby IS NOT NULL AND p.datum_spotreby < CURDATE()
                         ORDER BY p.datum_spotreby ASC");
    $q1->execute($surIds);
    foreach ($q1->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $out[] = ['surovina_id' => (int) $r['surovina_id'], 'nazev' => $r['nazev'], 'sarze' => $r['sarze'],
                  'datum_spotreby' => $r['datum_spotreby'], 'duvod' => 'expirace'];
    }
    // držené šarže (karanténa)
    $q2 = $pdo->prepare("SELECT st.surovina_id, s.nazev, st.sarze, st.poznamka
                         FROM sklad_sarze_stav st JOIN suroviny s ON s.id = st.surovina_id
                         WHERE st.stav='hold' AND st.surovina_id IN ($in)");
    $q2->execute($surIds);
    foreach ($q2->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $out[] = ['surovina_id' => (int) $r['surovina_id'], 'nazev' => $r['nazev'], 'sarze' => $r['sarze'],
                  'datum_spotreby' => null, 'poznamka' => $r['poznamka'], 'duvod' => 'karantena'];
    }
    return $out;
}

} // function_exists
