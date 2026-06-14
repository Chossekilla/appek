<?php
/**
 * 🔗 INTEGRITA PROVOZU — audit propojení stanic „ať nikde nic neuvázne" (v3.0.308)
 *
 * Jeden zdroj pravdy o tom, jestli celý kombinovaný provoz (restaurace + cukrárna +
 * lahůdky/catering + b2b + ostatní kanály) běží jako stroj — každá objednávka z každého
 * kanálu doteče do výroby/skladu/uzávěrky a nic nepropadne škvírou.
 *
 *   GET ?action=audit  → strukturovaný report (checks[], stav: ok|warn|fail, problemy)
 *
 * Bez nové obrazovky — backend kontrola (lze volat ručně, z CI, nebo později napojit na UI).
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/_admin_auth.php';
cors_headers();
require_admin();
header('Content-Type: application/json; charset=UTF-8');

$pdo = db();
$action = $_GET['action'] ?? 'audit';
if ($action !== 'audit') json_error('Neznámá akce (audit)', 404);

// 🛡️ surovina_id na objednavky_polozky je z runtime migrace konfigurátoru (nemusí existovat na čisté instalaci)
$hasSur = false;
try { $hasSur = in_array('surovina_id', $pdo->query("SHOW COLUMNS FROM objednavky_polozky")->fetchAll(PDO::FETCH_COLUMN), true); } catch (Throwable $e) {}
$surPosP   = $hasSur ? "COALESCE(p.surovina_id,0)>0"  : "0";   // p má surovina link
$surZeroOp = $hasSur ? "COALESCE(op.surovina_id,0)=0" : "1";   // op nemá surovina link (bez sloupce = vždy bez)

$checks = [];
$add = function (string $klic, string $nazev, bool $ok, string $uroven, string $detail, array $vzorky = []) use (&$checks) {
    $checks[] = ['klic' => $klic, 'nazev' => $nazev, 'ok' => $ok, 'uroven' => $uroven, 'detail' => $detail, 'vzorky' => array_slice($vzorky, 0, 8)];
};
// legitimní volné řádky (poplatky/doprava/text/dekorace/slevy/vouchery/nápoje bez receptu) — NEjsou slepé místo
$LEGIT = "(op.vyrobek_nazev LIKE '%Text na dortu%' OR op.vyrobek_nazev LIKE '%Dekorace%' OR op.vyrobek_nazev LIKE '%Doprava%'
   OR op.vyrobek_nazev LIKE '%Poplatek%' OR op.vyrobek_nazev LIKE '%Sleva%' OR op.vyrobek_nazev LIKE '%Dárková karta%'
   OR op.vyrobek_nazev LIKE '%voucher%' OR op.vyrobek_nazev LIKE '%Spropitné%' OR op.vyrobek_nazev LIKE '~ %'
   OR op.vyrobek_nazev LIKE 'Text na%')";

// ── CHECK 1: registr kanálů — každý puvod v objednávkách musí být v registru (jinak nezařazen v reportech) ──
try {
    $reg = array_keys(kanaly_defaults());
    $rows = $pdo->query("SELECT DISTINCT COALESCE(puvod,'(NULL)') p FROM objednavky")->fetchAll(PDO::FETCH_COLUMN);
    $neznam = array_values(array_filter($rows, fn($p) => $p !== '(NULL)' && !in_array($p, $reg, true)));
    $nullCnt = (int) $pdo->query("SELECT COUNT(*) FROM objednavky WHERE puvod IS NULL OR puvod=''")->fetchColumn();
    $ok = empty($neznam) && $nullCnt === 0;
    $add('kanaly_registr', 'Kanály v centrálním registru', $ok, $ok ? 'ok' : 'warn',
        $ok ? count($rows) . ' kanálů, vše registrováno' : ('neregistrované: ' . (implode(',', $neznam) ?: '—') . ($nullCnt ? ", bez puvod: $nullCnt obj" : '')), $neznam);
} catch (Throwable $e) { $add('kanaly_registr', 'Kanály v registru', false, 'fail', 'chyba: ' . $e->getMessage()); }

// ── CHECK 2: SLEPÉ OBJEDNÁVKY — mají položky, ale žádný řádek neodečítá (no vyrobek_id ∧ no surovina_id) ──
try {
    $sql = "SELECT o.id, o.cislo, COALESCE(o.puvod,'?') puvod, o.datum_objednani
            FROM objednavky o
            WHERE o.stav<>'zrusena'
              AND EXISTS (SELECT 1 FROM objednavky_polozky p WHERE p.objednavka_id=o.id)
              AND NOT EXISTS (SELECT 1 FROM objednavky_polozky p WHERE p.objednavka_id=o.id AND (p.vyrobek_id IS NOT NULL OR $surPosP))
            ORDER BY o.datum_objednani DESC";
    $blind = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);
    $ok = count($blind) === 0;
    $add('slepe_objednavky', 'Objednávky bez odečítacího řádku (production/sklad blind)', $ok, $ok ? 'ok' : 'warn',
        $ok ? 'žádná — každá objednávka odečítá nebo má jen legitimní volné řádky' : (count($blind) . ' objednávek neodečte nic ze skladu'),
        array_map(fn($r) => $r['cislo'] . ' [' . $r['puvod'] . '] ' . substr((string) $r['datum_objednani'], 0, 10), $blind));
} catch (Throwable $e) { $add('slepe_objednavky', 'Slepé objednávky', false, 'fail', 'chyba: ' . $e->getMessage()); }

// ── CHECK 3: ORPHAN HLAVNÍ PRODUKT — řádek bez vyrobek_id/surovina_id, který NENÍ legitimní volný řádek ──
try {
    $sql = "SELECT o.cislo, COALESCE(o.puvod,'?') puvod, op.vyrobek_nazev
            FROM objednavky_polozky op JOIN objednavky o ON o.id=op.objednavka_id
            WHERE o.stav<>'zrusena' AND op.vyrobek_id IS NULL AND $surZeroOp AND NOT $LEGIT";
    $orph = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);
    $ok = count($orph) === 0;
    $add('orphan_produkt', 'Produktové řádky bez vazby na výrobek (propadnou výrobu/odpis)', $ok, $ok ? 'ok' : 'warn',
        $ok ? 'žádný — produkty jsou vždy navázané (volné řádky jen poplatky/text/dekorace)' : (count($orph) . ' podezřelých řádků (možná produkt bez odpisu)'),
        array_map(fn($r) => '[' . $r['puvod'] . '] ' . $r['vyrobek_nazev'], $orph));
} catch (Throwable $e) { $add('orphan_produkt', 'Orphan produkt', false, 'fail', 'chyba: ' . $e->getMessage()); }

// ── CHECK 4: výrobek bez receptury, ale objednávaný — appears in spotřebě, ale neodečte (tichý) ──
try {
    $sql = "SELECT DISTINCT v.id, v.nazev FROM objednavky_polozky op
            JOIN objednavky o ON o.id=op.objednavka_id
            JOIN vyrobky v ON v.id=op.vyrobek_id
            WHERE o.stav<>'zrusena' AND (SELECT COUNT(*) FROM vyrobek_suroviny WHERE vyrobek_id=v.id)=0";
    $norec = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);
    $ok = count($norec) === 0;
    $add('vyrobek_bez_receptu', 'Objednávané výrobky bez receptury (neodečtou suroviny)', $ok, $ok ? 'ok' : 'warn',
        $ok ? 'žádný — každý objednávaný výrobek má recepturu' : (count($norec) . ' výrobků bez receptury se prodává'),
        array_map(fn($r) => $r['nazev'], $norec));
} catch (Throwable $e) { $add('vyrobek_bez_receptu', 'Výrobek bez receptu', false, 'fail', 'chyba: ' . $e->getMessage()); }

// ── CHECK 5: hlavička = DPH součet & = součet řádků (peněžní konzistence) ──
try {
    $hdr = (int) $pdo->query("SELECT COUNT(*) FROM objednavky WHERE stav<>'zrusena' AND ABS(castka_celkem - ROUND(castka_bez_dph+castka_dph,2)) > 0.05")->fetchColumn();
    $ok = $hdr === 0;
    $add('castky_hlavicka', 'Hlavička: celkem = bez DPH + DPH', $ok, $ok ? 'ok' : 'warn', $ok ? 'všechny objednávky sedí' : "$hdr objednávek nesedí");
} catch (Throwable $e) { $add('castky_hlavicka', 'Částky hlavička', false, 'fail', 'chyba: ' . $e->getMessage()); }

// ── CHECK 6: SKLAD systém A (suroviny.stock_aktualni) = SUM systém B (sklad_polozky) ──
try {
    $sur = $pdo->query("SELECT s.id,s.nazev FROM suroviny s WHERE ABS(COALESCE(s.stock_aktualni,0) - COALESCE((SELECT SUM(stav) FROM sklad_polozky WHERE item_typ='surovina' AND item_id=s.id),0)) > 0.01")->fetchAll(PDO::FETCH_ASSOC);
    $ok = count($sur) === 0;
    $add('sklad_a_vs_b', 'Sklad: cache (A) = sklad_polozky (B)', $ok, $ok ? 'ok' : 'fail',
        $ok ? 'konzistentní (žádný drift)' : (count($sur) . ' surovin s driftem'), array_map(fn($r) => $r['nazev'], $sur));
} catch (Throwable $e) { $add('sklad_a_vs_b', 'Sklad A=B', false, 'fail', 'chyba: ' . $e->getMessage()); }

// ── CHECK 7: uvízlé objednávky — ne-terminální stav starší než 14 dní (zaseklé ve stroji) ──
try {
    $stuck = (int) $pdo->query("SELECT COUNT(*) FROM objednavky WHERE stav NOT IN ('zaplaceno','dorucena','zrusena','expedovana','vyrizena','hotovo') AND datum_objednani < (NOW() - INTERVAL 14 DAY)")->fetchColumn();
    $ok = $stuck === 0;
    $add('uvazle_objednavky', 'Žádné staré uvízlé objednávky (>14 dní ne-terminální)', $ok, $ok ? 'ok' : 'warn', $ok ? 'žádná' : "$stuck objednávek visí >14 dní");
} catch (Throwable $e) { $add('uvazle_objednavky', 'Uvízlé objednávky', false, 'fail', 'chyba: ' . $e->getMessage()); }

// ── CHECK 8: aktivní výrobky bez receptury (informativní — lze prodat, neodečte) ──
try {
    $cnt = (int) $pdo->query("SELECT COUNT(*) FROM vyrobky WHERE aktivni=1 AND (SELECT COUNT(*) FROM vyrobek_suroviny WHERE vyrobek_id=vyrobky.id)=0")->fetchColumn();
    $tot = (int) $pdo->query("SELECT COUNT(*) FROM vyrobky WHERE aktivni=1")->fetchColumn();
    $add('vyrobky_receptura', 'Pokrytí výrobků recepturou', $cnt === 0, $cnt === 0 ? 'ok' : 'info',
        ($tot - $cnt) . '/' . $tot . ' aktivních výrobků má recepturu' . ($cnt ? " ($cnt bez — neodečtou se)" : ''));
} catch (Throwable $e) { $add('vyrobky_receptura', 'Pokrytí recepturou', false, 'fail', 'chyba: ' . $e->getMessage()); }

// ── souhrn ──
$fail = count(array_filter($checks, fn($c) => !$c['ok'] && $c['uroven'] === 'fail'));
$warn = count(array_filter($checks, fn($c) => !$c['ok'] && $c['uroven'] === 'warn'));
$stav = $fail > 0 ? 'fail' : ($warn > 0 ? 'warn' : 'ok');
json_response([
    'stav'       => $stav,
    'shrnuti'    => $stav === 'ok' ? '✅ Stroj běží — všechny stanice propojené, žádné slepé místo'
                  : ($fail > 0 ? "❌ $fail kritických + $warn varování" : "⚠️ $warn varování (žádné kritické)"),
    'fail'       => $fail,
    'warn'       => $warn,
    'checks'     => $checks,
    'cas'        => date('c'),
]);
