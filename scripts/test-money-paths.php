<?php
/**
 * 🆕 v3.0.356 — Money-path regrese test (READ-ONLY, žádné side-effecty).
 *   php scripts/test-money-paths.php   → asserty na jádro peněžní logiky:
 *     T1 ceník chokepoint (cenik_pro_odberatele) — struktura + sanity
 *     T2 sleva cenové skupiny — matematika konzistentní
 *     T3 BOM (odpis výroby) — bom_cost + bom_explode
 *     T4 sezónní úprava ceny — chokepoint
 *   Exit 1 při jakémkoli failu (CI-friendly). Nic nezapisuje (jen apply_full_schema z db()).
 */
$ROOT = dirname(__DIR__);
$_SERVER['REQUEST_METHOD'] = 'GET';
ob_start(); require $ROOT . '/api/config.php'; @ob_end_clean();
require_once $ROOT . '/api/_bom_lib.php';
require_once $ROOT . '/api/_seasonal_lib.php';

$pass = 0; $fail = 0;
function ok($cond, $msg) { global $pass, $fail; if ($cond) { $pass++; } else { $fail++; fwrite(STDOUT, "  ❌ $msg\n"); } }

$pdo = db();
echo "🧪 APPEK money-path test\n";

// ── T1 — ceník chokepoint ──────────────────────────────────────
echo "── T1 ceník (cenik_pro_odberatele) ──\n";
$odbs = $pdo->query("SELECT id FROM odberatele LIMIT 5")->fetchAll(PDO::FETCH_COLUMN);
ok(count($odbs) > 0, "existují odběratelé");
$checked = 0;
foreach ($odbs as $oid) {
    $cenik = cenik_pro_odberatele($pdo, (int) $oid);
    foreach (array_slice($cenik, 0, 20) as $v) {
        $checked++;
        ok(isset($v['cena_bez_dph']) && is_numeric($v['cena_bez_dph']) && $v['cena_bez_dph'] >= 0, "cena_bez_dph číslo>=0 (vyr {$v['id']} odb $oid)");
        ok(isset($v['cena_zakladni']) && $v['cena_zakladni'] >= 0, "cena_zakladni present (vyr {$v['id']})");
        ok(isset($v['dph']) && $v['dph'] >= 0 && $v['dph'] <= 30, "dph 0–30 (vyr {$v['id']})");
        ok(array_key_exists('min_objednavka', $v), "min_objednavka klíč (vyr {$v['id']})");
        if (!empty($v['pevna_cena'])) ok(abs($v['cena_bez_dph'] - $v['pevna_cena']) < 0.01, "pevná cena vyhrává (vyr {$v['id']})");
    }
}
ok($checked > 0, "zkontrolováno >0 ceníkových položek ($checked)");

// ── T2 — sleva cenové skupiny ──────────────────────────────────
echo "── T2 sleva cenové skupiny ──\n";
$grp = $pdo->query("SELECT id, globalni_sleva_pct FROM cenove_skupiny WHERE globalni_sleva_pct > 0 LIMIT 1")->fetch(PDO::FETCH_ASSOC);
if ($grp) {
    $st = $pdo->prepare("SELECT id FROM odberatele WHERE cenova_skupina_id = :g LIMIT 1");
    $st->execute(['g' => $grp['id']]);
    $oid = $st->fetchColumn();
    if ($oid) {
        $cenik = cenik_pro_odberatele($pdo, (int) $oid);
        $tested = false;
        foreach ($cenik as $v) {
            if (empty($v['sleva_pct']) && empty($v['pevna_cena']) && empty($v['sezona_sleva_pct']) && $v['cena_zakladni'] > 0) {
                $expected = round($v['cena_zakladni'] * (1 - $grp['globalni_sleva_pct'] / 100), 2);
                ok(abs($v['cena_bez_dph'] - $expected) < 0.02, "sleva {$grp['globalni_sleva_pct']}% sedí (vyr {$v['id']}: {$v['cena_bez_dph']} ~ $expected)");
                $tested = true; break;
            }
        }
        if (!$tested) echo "  (žádný produkt bez override k ověření — skip)\n";
    } else { echo "  (skupina nemá odběratele — skip)\n"; }
} else { echo "  (žádná globální sleva>0 — skip)\n"; }

// ── T3 — BOM (odpis výroby) ────────────────────────────────────
echo "── T3 BOM (bom_cost + bom_explode) ──\n";
$recipeVids = $pdo->query("SELECT DISTINCT vyrobek_id FROM vyrobek_suroviny LIMIT 5")->fetchAll(PDO::FETCH_COLUMN);
if ($recipeVids) {
    foreach ($recipeVids as $vid) {
        $cost = bom_cost($pdo, (int) $vid);
        ok(is_finite($cost) && $cost >= 0, "bom_cost finite>=0 (vyr $vid: $cost)");
        $sur = []; $pol = [];
        bom_explode($pdo, (int) $vid, 1.0, $sur, $pol);
        ok((count($sur) + count($pol)) > 0, "bom_explode rozpadne na suroviny/polotovary (vyr $vid: " . count($sur) . "+" . count($pol) . ")");
        foreach ($sur as $q) { ok($q > 0, "spotřeba suroviny>0 (vyr $vid)"); break; }
    }
} else { echo "  (žádné recepty — skip)\n"; }

// ── T4 — sezónní úprava (chokepoint) ───────────────────────────
echo "── T4 sezónní úprava ceny ──\n";
$adj = seasonal_adjust_price($pdo, 100.0, null);
ok(abs($adj['cena'] - 100.0) < 0.001 && (float) $adj['pct'] == 0.0, "bez sezóny cena beze změny (100→{$adj['cena']})");

echo "\n✅ PASS=$pass  ❌ FAIL=$fail\n";
exit($fail > 0 ? 1 : 0);
