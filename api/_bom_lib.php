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
    $m = ['sleduje_sklad' => 0, 'je_polotovar' => 0, 'nazev' => '', 'alergeny' => '', 'jednotka' => 'ks', 'hmotnost_g' => 0.0];
    try {
        static $cols = null;
        if ($cols === null) $cols = $pdo->query("SHOW COLUMNS FROM vyrobky")->fetchAll(PDO::FETCH_COLUMN);
        $sled = in_array('sleduje_sklad', $cols, true) ? 'v.sleduje_sklad' : '0 AS sleduje_sklad';
        $pol  = in_array('je_polotovar', $cols, true) ? 'v.je_polotovar' : '0 AS je_polotovar';
        $hm   = in_array('hmotnost_g', $cols, true) ? 'v.hmotnost_g' : 'NULL AS hmotnost_g';
        // POZOR: vyrobky má jednotka_id (FK), NE 'jednotka' → kód jednotky přes JOIN jednotky
        // 🐛 v3.0.644 — jednotka + hmotnost polotovaru (dřív natvrdo 'ks' → řádek „50 g těsta" = 50 ks)
        $st = $pdo->prepare("SELECT v.nazev, v.alergeny, {$sled}, {$pol}, {$hm}, j.kod AS jednotka_kod
                             FROM vyrobky v LEFT JOIN jednotky j ON j.id = v.jednotka_id WHERE v.id = :id");
        $st->execute(['id' => $vid]);
        $r = $st->fetch(PDO::FETCH_ASSOC);
        if ($r) $m = [
            'sleduje_sklad' => (int) ($r['sleduje_sklad'] ?? 0),
            'je_polotovar'  => (int) ($r['je_polotovar'] ?? 0),
            'nazev'         => (string) $r['nazev'],
            'alergeny'      => (string) ($r['alergeny'] ?? ''),
            'jednotka'      => trim((string) ($r['jednotka_kod'] ?? '')) !== '' ? mb_strtolower(trim((string) $r['jednotka_kod'])) : 'ks',
            'hmotnost_g'    => (float) ($r['hmotnost_g'] ?? 0),
        ];
    } catch (Throwable $e) {}
    $cache[$vid] = $m;
    return $m;
}

/**
 * 🐛 PR09 — konverze jednotky řádku receptu (vyrobek_suroviny.jednotka) na jednotku suroviny
 * (suroviny.jednotka). Bez ní se „0,01 kg" suroviny vedené v g odepsalo jako 0,01 g (1000× míň).
 * Převádí jen v rámci stejného rozměru (hmotnost g/kg/mg, objem ml/l/dl/cl, počet ks); neznámá nebo
 * křížová dvojice (bal, lžíce…) → 1:1 (zachová původní chování, běžné recepty se nemění).
 */
function bom_surovina_jednotka(PDO $pdo, int $sid): string {
    static $cache = [];
    if (array_key_exists($sid, $cache)) return $cache[$sid];
    $u = '';
    try { $st = $pdo->prepare("SELECT jednotka FROM suroviny WHERE id = :id"); $st->execute(['id' => $sid]); $u = (string) $st->fetchColumn(); } catch (Throwable $e) {}
    return $cache[$sid] = $u;
}
/** Přísná varianta: null = nelze převést (prázdná/neznámá jednotka nebo jiný rozměr). v3.0.640 */
if (!function_exists('bom_unit_factor_strict')) {
function bom_unit_factor_strict(?string $from, ?string $to): ?float {
    $f = mb_strtolower(trim((string) $from));
    $t = mb_strtolower(trim((string) $to));
    if ($f !== '' && $f === $t) return 1.0;
    static $base = ['g' => ['m', 1.0], 'kg' => ['m', 1000.0], 'mg' => ['m', 0.001],
                    'ml' => ['v', 1.0], 'l' => ['v', 1000.0], 'dl' => ['v', 100.0], 'cl' => ['v', 10.0],
                    'ks' => ['k', 1.0]];
    if (isset($base[$f], $base[$t]) && $base[$f][0] === $base[$t][0]) return $base[$f][1] / $base[$t][1];
    return null;
}
}
function bom_unit_factor(?string $from, ?string $to): float {
    $f = mb_strtolower(trim((string) $from));
    $t = mb_strtolower(trim((string) $to));
    if ($f === '' || $t === '' || $f === $t) return 1.0;
    return bom_unit_factor_strict($f, $t) ?? 1.0;   // neznámá/křížová dvojice → 1:1 (původní chování)
}

/**
 * 🐛 v3.0.644 — převod jednotky ŘÁDKU receptu s polotovarem (vyrobek_suroviny.jednotka) na jednotku
 *   polotovaru (vyrobky.jednotka_id → jednotky.kod). Dřív se ignorovala: „50 g těsta" (těsto vedené v ks)
 *   se počítalo jako 50 ks → odpis/kalkulace 1000× vedle.
 *   • stejný rozměr (g↔kg, ml↔l, ks↔ks) → poměr,
 *   • hmotnost řádku a polotovar v ks se známou hmotností (vyrobky.hmotnost_g) → gramy / hmotnost kusu,
 *   • jinak null = nelze převést (volající rozhodne: uložení odmítne, výpočet zachová staré 1:1).
 */
function bom_polotovar_factor_strict(PDO $pdo, int $sub, ?string $rowUnit): ?float {
    $meta = bom_vyrobek_meta($pdo, $sub);
    $to   = $meta['jednotka'] ?: 'ks';
    $from = mb_strtolower(trim((string) $rowUnit));
    if ($from === '') return 1.0;                       // bez jednotky = jednotka polotovaru (staré řádky)
    $f = bom_unit_factor_strict($from, $to);
    if ($f !== null) return $f;
    $g = bom_unit_factor_strict($from, 'g');             // řádek v hmotnosti?
    if ($g !== null && $to === 'ks' && $meta['hmotnost_g'] > 0) return $g / $meta['hmotnost_g'];
    return null;
}
/** Výpočtová varianta: nepřevoditelná dvojice → 1:1 (zpětná kompatibilita starých receptů; nové uložení validuje sync_slozeni). */
function bom_polotovar_factor(PDO $pdo, int $sub, ?string $rowUnit): float {
    return bom_polotovar_factor_strict($pdo, $sub, $rowUnit) ?? 1.0;
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
            $need *= bom_polotovar_factor($pdo, $sub, $r['jednotka'] ?? null);   // 🐛 v3.0.644 — jednotka řádku → jednotka polotovaru
            if (bom_vyrobek_meta($pdo, $sub)['sleduje_sklad']) {
                $pol[$sub] = ($pol[$sub] ?? 0) + $need;          // leaf — ubere ze skladu polotovaru
            } else {
                bom_explode($pdo, $sub, $need, $sur, $pol, $depth + 1, $visited); // rekurze na suroviny
            }
        } elseif (!empty($r['surovina_id'])) {
            $sid = (int) $r['surovina_id'];
            $factor = bom_unit_factor($r['jednotka'] ?? null, bom_surovina_jednotka($pdo, $sid)); // 🐛 PR09
            $sur[$sid] = ($sur[$sid] ?? 0) + $need * $factor;
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
            $sub = (int) $r['slozka_vyrobek_id'];
            $cost += bom_cost($pdo, $sub, $visited) * $mn * bom_polotovar_factor($pdo, $sub, $r['jednotka'] ?? null); // 🐛 v3.0.644
        } elseif (!empty($r['surovina_id'])) {
            $sid = (int) $r['surovina_id'];
            $cost += bom_surovina_unit_cost($pdo, $sid) * $mn * bom_unit_factor($r['jednotka'] ?? null, bom_surovina_jednotka($pdo, $sid)); // 🐛 PR09
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

/**
 * 🐛 v3.0.644 — alergeny RECEPTU výrobku pro zápis do vyrobky.alergeny (bezpečnost potravin):
 *   suroviny (alergen + slozeni_alergeny) + POLOTOVARY (jejich deklarované vyrobky.alergeny
 *   + rekurzivně jejich recept). Dřív se bral jen JOIN na suroviny → lepek z „Těsta“ se do rohlíku
 *   nepropsal. Čte čerstvě z DB (bez per-request cache — volá se hned po přepsání receptu).
 */
function bom_alergeny_receptu(PDO $pdo, int $vid, array $visited = []): array {
    if ($vid <= 0 || isset($visited[$vid]) || count($visited) > BOM_MAX_DEPTH) return [];
    $visited[$vid] = true;
    $set = [];
    $add = function (?string $field) use (&$set) {
        foreach (preg_split('/[,;]+/', (string) $field) as $a) {
            $a = trim($a);
            if ($a !== '') $set[mb_strtolower($a)] = $set[mb_strtolower($a)] ?? $a; // dedup, zachová první podobu
        }
    };
    try {
        $st = $pdo->prepare("SELECT s.alergen, s.slozeni_alergeny
                             FROM vyrobek_suroviny vs JOIN suroviny s ON s.id = vs.surovina_id
                             WHERE vs.vyrobek_id = :v");
        $st->execute(['v' => $vid]);
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) { $add($r['alergen'] ?? ''); $add($r['slozeni_alergeny'] ?? ''); }
    } catch (Throwable $e) { /* tabulky nemusí existovat */ }
    try {
        $st = $pdo->prepare("SELECT DISTINCT v.id, v.alergeny
                             FROM vyrobek_suroviny vs JOIN vyrobky v ON v.id = vs.slozka_vyrobek_id
                             WHERE vs.vyrobek_id = :v");
        $st->execute(['v' => $vid]);
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $add($r['alergeny'] ?? '');
            foreach (bom_alergeny_receptu($pdo, (int) $r['id'], $visited) as $a) $add($a);
        }
    } catch (Throwable $e) { /* sloupec slozka_vyrobek_id nemusí existovat (stará instalace) */ }
    return array_values($set);
}

/**
 * Sloučí alergeny receptu do vyrobky.alergeny (UNION, add-only — nikdy neodebírá ruční záznamy
 * typu „může obsahovat stopy ořechů“). Vrací true, pokud něco doplnil.
 */
function bom_merge_alergeny(PDO $pdo, int $vid): bool {
    $recipe = bom_alergeny_receptu($pdo, $vid);
    if (!$recipe) return false;
    $st = $pdo->prepare("SELECT alergeny FROM vyrobky WHERE id = :id");
    $st->execute(['id' => $vid]);
    $cur = (string) ($st->fetchColumn() ?: '');
    $curSet = [];
    foreach (preg_split('/[,;]+/', $cur) as $a) { $a = trim($a); if ($a !== '') $curSet[mb_strtolower($a)] = $a; }
    $changed = false;
    foreach ($recipe as $a) {
        $k = mb_strtolower($a);
        if (!isset($curSet[$k])) { $curSet[$k] = $a; $changed = true; }
    }
    if ($changed) {
        $pdo->prepare("UPDATE vyrobky SET alergeny = :a WHERE id = :id")
            ->execute(['a' => implode(', ', array_values($curSet)), 'id' => $vid]);
    }
    return $changed;
}

/**
 * 🐛 v3.0.644 — změna alergenů/receptu polotovaru → doplň alergeny všem NADŘAZENÝM výrobkům
 * (reverzní BOM přes slozka_vyrobek_id, uzávěr). Add-only. Vrací počet doplněných výrobků.
 */
function bom_propaguj_alergeny_nahoru(PDO $pdo, int $vid): int {
    $n = 0;
    try {
        $up = $pdo->prepare("SELECT DISTINCT vyrobek_id FROM vyrobek_suroviny WHERE slozka_vyrobek_id = ?");
        $seen = [$vid => true]; $queue = [$vid]; $guard = 0;
        while ($queue && $guard++ < 10000) {
            $cur = array_shift($queue);
            $up->execute([$cur]);
            foreach ($up->fetchAll(PDO::FETCH_COLUMN) as $p) {
                $p = (int) $p;
                if ($p <= 0 || isset($seen[$p])) continue;
                $seen[$p] = true; $queue[] = $p;
                if (bom_merge_alergeny($pdo, $p)) $n++;
            }
        }
    } catch (Throwable $e) { /* sloupec slozka_vyrobek_id nemusí existovat */ }
    return $n;
}

/** Surovina změnila alergen/složení → doplň alergeny všem výrobkům, které ji obsahují (i přes polotovary). */
function bom_propaguj_alergeny_suroviny(PDO $pdo, int $sid): int {
    $n = 0;
    try {
        // pořadí: nejdřív přímí uživatelé, pak nadřazení (BFS v bom_products_using_surovina) → stačí jeden průchod,
        // protože bom_alergeny_receptu čte i deklarované alergeny polotovarů, které se doplnily dřív
        foreach (bom_products_using_surovina($pdo, $sid) as $v) { if (bom_merge_alergeny($pdo, (int) $v)) $n++; }
    } catch (Throwable $e) {}
    return $n;
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

/**
 * 🔎 REVERZNÍ BOM — které výrobky (přímo i přes polotovary) obsahují surovinu $sid.
 * Vrací pole vyrobek_id (uzávěr). Pro recall/trasovatelnost šarže.
 *
 * Krok 1: přímí uživatelé (vyrobek_suroviny.surovina_id = $sid).
 * Krok 2: kdokoli, kdo některý z těch výrobků používá jako polotovar
 *         (slozka_vyrobek_id), ho také obsahuje → propaguj nahoru do uzávěru.
 */
function bom_products_using_surovina(PDO $pdo, int $sid): array {
    if ($sid <= 0) return [];
    $direct = $pdo->prepare("SELECT DISTINCT vyrobek_id FROM vyrobek_suroviny WHERE surovina_id = ?");
    $direct->execute([$sid]);
    $result = [];   // vyrobek_id => true (uzávěr)
    $queue = [];
    foreach ($direct->fetchAll(PDO::FETCH_COLUMN) as $v) {
        $v = (int) $v;
        if ($v > 0 && !isset($result[$v])) { $result[$v] = true; $queue[] = $v; }
    }
    $up = $pdo->prepare("SELECT DISTINCT vyrobek_id FROM vyrobek_suroviny WHERE slozka_vyrobek_id = ?");
    $guard = 0;
    while ($queue && $guard++ < 100000) {
        $cur = array_pop($queue);
        $up->execute([$cur]);
        foreach ($up->fetchAll(PDO::FETCH_COLUMN) as $v) {
            $v = (int) $v;
            if ($v > 0 && !isset($result[$v])) { $result[$v] = true; $queue[] = $v; }
        }
    }
    return array_map('intval', array_keys($result));
}

/**
 * 🐛 PR12 — vrácení surovin na sklad při STORNU objednávky, jejíž den už byl odepsán.
 * Dřív zrušení objednávky po výrobním odpisu nevracelo spotřebované suroviny → trvalý úbytek
 * skladu bez pokrytí. Vrací se jen když (a) den má odpisový marker (jinak sklad nikdy neklesl)
 * a (b) tato objednávka ještě vrácena nebyla (idempotence přes nastaveni). Musí běžet uvnitř
 * transakce volajícího. Vrací přehled nebo důvod přeskočení.
 */
function bom_vrat_objednavku_na_sklad(PDO $pdo, int $objId, string $kdo = 'storno'): array {
    require_once __DIR__ . '/_sklad_lib.php';
    $o = $pdo->prepare("SELECT id, cislo, datum_dodani FROM objednavky WHERE id = :id");
    $o->execute(['id' => $objId]);
    $ord = $o->fetch(PDO::FETCH_ASSOC);
    if (!$ord) return ['vraceno' => false, 'duvod' => 'objednávka neexistuje'];
    // vrať jen když byl den odepsán (jinak se sklad za tuto objednávku nikdy nesnížil)
    $marker = $pdo->prepare("SELECT 1 FROM nastaveni WHERE klic = :k");
    $marker->execute(['k' => 'odpis_vyroba_' . $ord['datum_dodani']]);
    if (!$marker->fetchColumn()) return ['vraceno' => false, 'duvod' => 'den nebyl odepsán'];
    $vrKey = 'vraceno_sklad_obj_' . $objId;
    $done = $pdo->prepare("SELECT 1 FROM nastaveni WHERE klic = :k");
    $done->execute(['k' => $vrKey]);
    if ($done->fetchColumn()) return ['vraceno' => false, 'duvod' => 'už vráceno'];

    $sur = []; $pol = [];
    $lp = $pdo->prepare("SELECT vyrobek_id, mnozstvi FROM objednavky_polozky WHERE objednavka_id = :id AND vyrobek_id IS NOT NULL");
    $lp->execute(['id' => $objId]);
    foreach ($lp->fetchAll(PDO::FETCH_ASSOC) as $ln) bom_explode($pdo, (int) $ln['vyrobek_id'], (float) $ln['mnozstvi'], $sur, $pol);

    $pozn = 'Vratka storno obj ' . ($ord['cislo'] ?? ('#' . $objId));
    $addBack = function (int $skladId, string $typ, int $itemId, float $qty) use ($pdo, $pozn, $kdo) {
        if ($qty <= 0) return;
        $rowId = sklad_polozky_ensure($pdo, $skladId, $typ, $itemId);
        $pdo->prepare("UPDATE sklad_polozky SET stav = stav + :q WHERE id = :r")->execute(['q' => $qty, 'r' => $rowId]);
        $po = (float) $pdo->query("SELECT stav FROM sklad_polozky WHERE id = " . (int) $rowId)->fetchColumn();
        $pdo->prepare("INSERT INTO sklad_pohyby_v2 (sklad_id,item_typ,item_id,typ,mnozstvi,stav_pred,stav_po,poznamka,kdo,kdy)
                       VALUES (:s,:t,:i,'vratka',:mn,:pr,:po,:pz,:kdo,NOW())")
            ->execute(['s' => $skladId, 't' => $typ, 'i' => $itemId, 'mn' => $qty, 'pr' => $po - $qty, 'po' => $po, 'pz' => mb_substr($pozn, 0, 255), 'kdo' => $kdo]);
    };
    foreach ($sur as $sid => $qty) { $addBack(surovina_home_sklad($pdo, (int) $sid), 'surovina', (int) $sid, (float) $qty); surovina_recompute_total($pdo, (int) $sid); }
    $defSklad = sklad_default_id($pdo);
    foreach ($pol as $pid => $qty) $addBack($defSklad, 'vyrobek', (int) $pid, (float) $qty);

    $pdo->prepare("INSERT INTO nastaveni (klic, hodnota) VALUES (:k, :v) ON DUPLICATE KEY UPDATE hodnota = :v2")
        ->execute(['k' => $vrKey, 'v' => date('Y-m-d H:i') . ' · ' . $kdo, 'v2' => date('Y-m-d H:i') . ' · ' . $kdo]);
    return ['vraceno' => true, 'suroviny' => count($sur), 'polotovary' => count($pol)];
}
