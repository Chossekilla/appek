<?php
/**
 * 🏭 BOM (sestavy / víceúrovňový recept) — v3.0.303
 *
 * Recept výrobku = řádky `vyrobek_suroviny`: BUĎ `surovina_id` (raw surovina)
 * NEBO `slozka_vyrobek_id` (polotovar = jiný výrobek) + `mnozstvi`/`jednotka`.
 *
 * HYBRID sklad polotovarů:
 *   - polotovar se `sleduje_sklad=1` → LEAF: ubere se ze skladu polotovaru (item_typ='vyrobek')
 *   - polotovar se `sleduje_sklad=0` → rekurzivně rozpad na suroviny
 *
 * Cyklus-guard: max hloubka BOM_MAX_DEPTH + visited set (předáváno hodnotou → diamant
 * (stejný polotovar 2× v různých větvích) se počítá vícekrát SPRÁVNĚ, cyklus A→B→A se zastaví).
 */

const BOM_MAX_DEPTH = 8;

/** Řádky receptu výrobku (surovina i polotovar). Per-request cache. */
function bom_recipe_rows(PDO $pdo, int $vyrobek_id): array {
    static $cache = [];
    if (array_key_exists($vyrobek_id, $cache)) return $cache[$vyrobek_id];
    $rows = [];
    try {
        static $hasSlozka = null;
        if ($hasSlozka === null) {
            $hasSlozka = in_array('slozka_vyrobek_id', $pdo->query("SHOW COLUMNS FROM vyrobek_suroviny")->fetchAll(PDO::FETCH_COLUMN), true);
        }
        $sel = $hasSlozka ? 'vs.slozka_vyrobek_id' : 'NULL AS slozka_vyrobek_id';
        $st = $pdo->prepare("SELECT vs.surovina_id, {$sel}, vs.mnozstvi, vs.jednotka, vs.poradi
                             FROM vyrobek_suroviny vs WHERE vs.vyrobek_id = :v ORDER BY vs.poradi, vs.id");
        $st->execute(['v' => $vyrobek_id]);
        $rows = $st->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) { $rows = []; }
    $cache[$vyrobek_id] = $rows;
    return $rows;
}

/** Meta polotovaru (sleduje_sklad, nazev, alergeny). Per-request cache. */
function bom_vyrobek_meta(PDO $pdo, int $vid): array {
    static $cache = [];
    if (array_key_exists($vid, $cache)) return $cache[$vid];
    $m = ['sleduje_sklad' => 0, 'je_polotovar' => 0, 'nazev' => '', 'alergeny' => '', 'jednotka' => 'ks'];
    try {
        static $cols = null;
        if ($cols === null) $cols = $pdo->query("SHOW COLUMNS FROM vyrobky")->fetchAll(PDO::FETCH_COLUMN);
        $sled = in_array('sleduje_sklad', $cols, true) ? 'sleduje_sklad' : '0 AS sleduje_sklad';
        $pol  = in_array('je_polotovar', $cols, true) ? 'je_polotovar' : '0 AS je_polotovar';
        $st = $pdo->prepare("SELECT nazev, alergeny, jednotka, {$sled}, {$pol} FROM vyrobky WHERE id = :id");
        $st->execute(['id' => $vid]);
        $r = $st->fetch(PDO::FETCH_ASSOC);
        if ($r) $m = [
            'sleduje_sklad' => (int) ($r['sleduje_sklad'] ?? 0),
            'je_polotovar'  => (int) ($r['je_polotovar'] ?? 0),
            'nazev'         => (string) $r['nazev'],
            'alergeny'      => (string) ($r['alergeny'] ?? ''),
            'jednotka'      => (string) ($r['jednotka'] ?? 'ks'),
        ];
    } catch (Throwable $e) {}
    $cache[$vid] = $m;
    return $m;
}

/** Jednotková cena suroviny (cena_baleni / obsah_baleni). */
function bom_surovina_unit_cost(PDO $pdo, int $sid): float {
    static $cache = [];
    if (array_key_exists($sid, $cache)) return $cache[$sid];
    $c = 0.0;
    try {
        $st = $pdo->prepare("SELECT cena_baleni / NULLIF(obsah_baleni,0) FROM suroviny WHERE id = :id");
        $st->execute(['id' => $sid]);
        $c = (float) $st->fetchColumn();
    } catch (Throwable $e) {}
    return $cache[$sid] = $c;
}

/**
 * Rozpad sestavy: akumuluje potřebu surovin ($sur[surovina_id]+=) a stockovaných polotovarů
 * ($pol[vyrobek_id]+=) pro `qty` výrobků `vid`.
 */
function bom_explode(PDO $pdo, int $vid, float $qty, array &$sur, array &$pol, int $depth = 0, array $visited = []): void {
    if ($depth > BOM_MAX_DEPTH || isset($visited[$vid]) || $qty <= 0) return;
    $visited[$vid] = true;
    foreach (bom_recipe_rows($pdo, $vid) as $r) {
        $mn = (float) $r['mnozstvi'];
        if ($mn <= 0) continue;
        $need = $qty * $mn;
        if (!empty($r['slozka_vyrobek_id'])) {
            $sub = (int) $r['slozka_vyrobek_id'];
            if (bom_vyrobek_meta($pdo, $sub)['sleduje_sklad']) {
                $pol[$sub] = ($pol[$sub] ?? 0) + $need;          // leaf — ubere ze skladu polotovaru
            } else {
                bom_explode($pdo, $sub, $need, $sur, $pol, $depth + 1, $visited); // rekurze na suroviny
            }
        } elseif (!empty($r['surovina_id'])) {
            $sid = (int) $r['surovina_id'];
            $sur[$sid] = ($sur[$sid] ?? 0) + $need;
        }
    }
}

/** Materiálový náklad výrobku (rekurzivně přes polotovary). */
function bom_cost(PDO $pdo, int $vid, array $visited = []): float {
    if (isset($visited[$vid])) return 0.0;
    $visited[$vid] = true;
    $cost = 0.0;
    foreach (bom_recipe_rows($pdo, $vid) as $r) {
        $mn = (float) $r['mnozstvi'];
        if ($mn <= 0) continue;
        if (!empty($r['slozka_vyrobek_id'])) {
            $cost += bom_cost($pdo, (int) $r['slozka_vyrobek_id'], $visited) * $mn;
        } elseif (!empty($r['surovina_id'])) {
            $cost += bom_surovina_unit_cost($pdo, (int) $r['surovina_id']) * $mn;
        }
    }
    return round($cost, 4);
}

/** Alergeny výrobku (union přes suroviny + polotovary, rekurzivně). */
function bom_allergens(PDO $pdo, int $vid, array $visited = []): array {
    if (isset($visited[$vid])) return [];
    $visited[$vid] = true;
    $set = [];
    foreach (bom_recipe_rows($pdo, $vid) as $r) {
        if (!empty($r['slozka_vyrobek_id'])) {
            foreach (bom_allergens($pdo, (int) $r['slozka_vyrobek_id'], $visited) as $a) $set[mb_strtolower($a)] = $a;
        } elseif (!empty($r['surovina_id'])) {
            try {
                $st = $pdo->prepare("SELECT alergen, slozeni_alergeny FROM suroviny WHERE id = :id");
                $st->execute(['id' => (int) $r['surovina_id']]);
                $sr = $st->fetch(PDO::FETCH_ASSOC) ?: [];
                foreach ([(string) ($sr['alergen'] ?? ''), (string) ($sr['slozeni_alergeny'] ?? '')] as $f) {
                    foreach (preg_split('/[,;]+/', $f) as $a) { $a = trim($a); if ($a !== '') $set[mb_strtolower($a)] = $a; }
                }
            } catch (Throwable $e) {}
        }
    }
    return array_values($set);
}

/** Detekce cyklu: byl by `slozka` v receptu `vid` (přímo či nepřímo) cyklus? (vid je předek slozka?) */
function bom_would_cycle(PDO $pdo, int $vid, int $slozka, int $depth = 0, array $visited = []): bool {
    if ($vid === $slozka) return true;
    if ($depth > BOM_MAX_DEPTH || isset($visited[$slozka])) return $depth > BOM_MAX_DEPTH;
    $visited[$slozka] = true;
    foreach (bom_recipe_rows($pdo, $slozka) as $r) {
        if (!empty($r['slozka_vyrobek_id']) && bom_would_cycle($pdo, $vid, (int) $r['slozka_vyrobek_id'], $depth + 1, $visited)) return true;
    }
    return false;
}
