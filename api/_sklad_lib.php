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

} // function_exists
