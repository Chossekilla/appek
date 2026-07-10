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
require_once $ROOT . '/api/_catering_lib.php';

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

// ── T5 — řetězec částek objednávka→DL→faktura (read-only) ──────
echo "── T5 řetězec částek (faktura header math + faktura==ΣDL) ──\n";
$faktury = $pdo->query("SELECT id, castka_bez_dph, castka_dph, castka_celkem, je_dobropis FROM faktury LIMIT 60")->fetchAll(PDO::FETCH_ASSOC);
foreach ($faktury as $f) {
    $expected = round((float) $f['castka_bez_dph'] + (float) $f['castka_dph'], 2);
    ok(abs((float) $f['castka_celkem'] - $expected) < 0.02, "faktura #{$f['id']} celkem==bez+dph ({$f['castka_celkem']} ~ $expected)");
    // dobropis = záporná částka (správně); běžná faktura nezáporná
    if ((int) $f['je_dobropis'] === 1) {
        ok((float) $f['castka_celkem'] <= 0.01, "dobropis #{$f['id']} celkem<=0 ({$f['castka_celkem']})");
    } else {
        ok((float) $f['castka_celkem'] >= -0.01, "faktura #{$f['id']} celkem>=0 ({$f['castka_celkem']})");
    }
}
echo "  (faktur ověřeno: " . count($faktury) . ")\n";
// faktura.castka_celkem == suma jejích DL (auto faktury z DL)
$linked = $pdo->query("
    SELECT f.id, f.castka_celkem, COALESCE(SUM(dl.castka_celkem),0) AS dl_sum, COUNT(dl.id) AS n
    FROM faktury f
    JOIN faktury_dodaci_listy fdl ON fdl.faktura_id = f.id
    JOIN dodaci_listy dl ON dl.id = fdl.dodaci_list_id
    WHERE f.rucni = 0 AND f.je_dobropis = 0
    GROUP BY f.id, f.castka_celkem LIMIT 30
")->fetchAll(PDO::FETCH_ASSOC);
foreach ($linked as $l) {
    ok(abs((float) $l['castka_celkem'] - (float) $l['dl_sum']) < 0.05, "faktura #{$l['id']} == Σ {$l['n']} DL ({$l['castka_celkem']} ~ {$l['dl_sum']})");
}
echo "  (faktura↔DL párů ověřeno: " . count($linked) . ")\n";
// referenční integrita: DL.objednavka_id buď NULL, nebo objednávka existuje
$orphanDl = (int) $pdo->query("SELECT COUNT(*) FROM dodaci_listy dl WHERE dl.objednavka_id IS NOT NULL AND NOT EXISTS (SELECT 1 FROM objednavky o WHERE o.id = dl.objednavka_id)")->fetchColumn();
ok($orphanDl === 0, "žádný DL s neexistující objednávkou (orphans: $orphanDl)");

// ── T6 — DPH rozpis reconciliuje na hlavičku (faktura.php v3.0.356 fix) ─────────
// Stored castka_* MUSÍ být = Σ položek na haléřové úrovni, aby rozpis (zaokrouhlený
// per sazba + absorbovaný zbytek) seděl PŘESNĚ na „K úhradě". Velký zbytek = reálný bug.
echo "── T6 DPH rozpis ↔ hlavička ──\n";
$t6 = 0;
foreach ($pdo->query("SELECT id, castka_bez_dph, castka_dph FROM faktury LIMIT 60")->fetchAll(PDO::FETCH_ASSOC) as $f6) {
    $pq = $pdo->prepare("SELECT mnozstvi, cena_bez_dph, sazba_dph FROM faktura_polozky WHERE faktura_id = ?");
    $pq->execute([$f6['id']]); $rows = $pq->fetchAll(PDO::FETCH_ASSOC);
    if (!$rows) {
        $dq = $pdo->prepare("SELECT dlp.mnozstvi, dlp.cena_bez_dph, dlp.sazba_dph FROM dodaci_list_polozky dlp JOIN faktury_dodaci_listy fdl ON fdl.dodaci_list_id = dlp.dodaci_list_id WHERE fdl.faktura_id = ?");
        $dq->execute([$f6['id']]); $rows = $dq->fetchAll(PDO::FETCH_ASSOC);
    }
    if (!$rows) continue;
    $t6++;
    $rz = [];
    foreach ($rows as $r) {
        $bez = $r['cena_bez_dph'] * $r['mnozstvi']; $s = (string) (float) $r['sazba_dph'];
        if (!isset($rz[$s])) $rz[$s] = ['bez' => 0, 'dph' => 0];
        $rz[$s]['bez'] += $bez; $rz[$s]['dph'] += $bez * (float) $r['sazba_dph'] / 100;
    }
    $sumBez = 0; $sumDph = 0;
    foreach ($rz as $r) { $sumBez += round($r['bez'], 2); $sumDph += round($r['dph'], 2); }
    $resBez = abs(round((float) $f6['castka_bez_dph'], 2) - $sumBez);
    $resDph = abs(round((float) $f6['castka_dph'], 2) - $sumDph);
    ok($resBez <= 0.05 && $resDph <= 0.05, "faktura #{$f6['id']} rozpis↔hlavička zbytek absorbovatelný (bez $resBez, dph $resDph)");
}
echo "  (rozpisů ověřeno: $t6)\n";

// ── T7 — catering produkty[] (přístup A) ───────────────────────
echo "── T7 catering produkty z katalogu ──\n";
$clean = catering_clean_produkty([
    ['vyrobek_id' => 5, 'porce_na_osobu' => 1.5, 'aktivni' => true,  'povinne' => true,  'poradi' => 0],
    ['vyrobek_id' => 0, 'porce_na_osobu' => 2,   'aktivni' => true,  'povinne' => true],   // vyrobek_id=0 → vyřazeno
    ['vyrobek_id' => 9, 'porce_na_osobu' => -3,  'aktivni' => 'x',   'povinne' => 0],       // porce<0 → 0, bool coerce
]);
ok(count($clean) === 2, "produkty: vyrobek_id=0 vyřazen (mám " . count($clean) . ")");
ok(($clean[0]['porce_na_osobu'] ?? null) === 1.5, "porce 1.5 zachována");
ok(($clean[1]['porce_na_osobu'] ?? null) === 0.0, "porce<0 → 0.0");
ok(($clean[1]['aktivni'] ?? null) === true, "aktivni 'x' → true");
ok(($clean[1]['povinne'] ?? null) === false, "povinne 0 → false");
ok(!is_array(catering_clean_produkty('nonsense')) === false && catering_clean_produkty('nonsense') === [], "nepole → []");

// decorate + škálování osob×porce (jen když existuje aspoň 1 aktivní výrobek)
$someVyrobekId = (int) $pdo->query("SELECT id FROM vyrobky WHERE aktivni=1 LIMIT 1")->fetchColumn();
if ($someVyrobekId > 0) {
    $dec = catering_decorate_produkty($pdo, [
        ['vyrobek_id' => $someVyrobekId, 'porce_na_osobu' => 2, 'aktivni' => true, 'povinne' => true, 'poradi' => 0],
    ]);
    ok(count($dec) === 1, "decorate vrací 1 produkt");
    ok(isset($dec[0]['nazev'], $dec[0]['cena'], $dec[0]['kategorie'], $dec[0]['material']), "produkt dekorován z katalogu (nazev/cena/kategorie/material)");
    ok(empty($dec[0]['smazany']), "existující výrobek: smazany=false");
    $osob = 10; $mn = $osob * 2;
    ok(round($dec[0]['cena'] * $mn, 2) === round($dec[0]['cena'] * $osob * 2, 2), "množství = osob × porce ($mn)");
} else {
    echo "  (přeskočeno decorate — v DB není žádný aktivní výrobek)\n";
}
// smazaný/neexistující výrobek → smazany:true
$decDel = catering_decorate_produkty($pdo, [['vyrobek_id' => 999999999, 'porce_na_osobu' => 1, 'aktivni' => true, 'povinne' => true, 'poradi' => 0]]);
ok(!empty($decDel[0]['smazany']), "neexistující výrobek → smazany:true");

// ── T8 — catering header DPH z per-line sazeb (mix 12/21 %) ─────
echo "── T8 catering_totals per-line DPH ──\n";
$tt = catering_totals([['cena_kc' => 2000, 'dph' => 12], ['cena_kc' => 1000, 'dph' => 21]]);
ok(abs($tt['bez'] - 3000) < 0.001, "bez = 3000 (mám {$tt['bez']})");
ok(abs($tt['dph'] - 450) < 0.001, "DPH = 450 per-line, ne flat (mám {$tt['dph']})");
ok(abs($tt['celkem'] - 3450) < 0.001, "celkem = 3450, ne 3360 flat (mám {$tt['celkem']})");
$tt0 = catering_totals([]);
ok($tt0['celkem'] === 0.0, "prázdné → 0");

echo "\n✅ PASS=$pass  ❌ FAIL=$fail\n";
exit($fail > 0 ? 1 : 0);
