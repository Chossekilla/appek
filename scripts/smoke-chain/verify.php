<?php
/**
 * 🧪 APPEK smoke-chain — invarianty (SQL), reprodukční sondy a report.
 * Volá run.php na konci; lze spustit i samostatně: php verify.php verify --run=TAG --root=… --base=…
 */
declare(strict_types=1);
require_once __DIR__ . '/phases.php';

/** Spustí jen invarianty (z uložených artefaktů) a vypíše stručný stav. Samostatný vstup. */
function cli_verify(): int {
    app_db();
    $V = run_verify();
    build_report();
    $bad = count(array_filter($V, fn($v) => !$v['ok'] && $v['sev'] === 'fail'));
    L('verify: ' . (count($V) - $bad) . '/' . count($V) . " OK, $bad fail — report " . rpath('report.md'));
    return $bad ? 1 : 0;
}

// ───────────────────────── invarianty ─────────────────────────
function run_verify(): array {
    $T = cfg('tag'); $C = "SELECT id FROM odberatele WHERE cislo LIKE '$T-C%'";
    $LIVE = "o.odberatel_id IN ($C) AND o.stav <> 'zrusena'";
    $V = [];
    $chk = function (string $id, string $title, $rows, ?string $note = null, string $sev = 'fail') use (&$V) {
        $n = is_array($rows) ? count($rows) : (int) $rows;
        $V[] = ['id' => $id, 'title' => $title, 'ok' => $n === 0, 'n' => $n, 'sev' => $sev, 'note' => $note, 'samples' => is_array($rows) ? array_slice(array_values($rows), 0, 6) : []];
    };
    $N = (int) cfg('customers'); $orders = all_orders(); $custRecs = jlines('customers-*.jsonl');

    // — odběratelé + účty —
    $nCust = (int) q1("SELECT COUNT(*) FROM odberatele WHERE cislo LIKE '$T-C%'");
    $chk('V01', "Vytvoreno $N odberatelu (DB: $nCust)", abs($nCust - $N), "DB $nCust / cil $N");
    $chk('V02', 'Kazdy odberatel ma B2B ucet (login+hash), neni blokovan, notifikace vypnute', q("SELECT id, cislo FROM odberatele WHERE cislo LIKE '$T-C%' AND (login_email IS NULL OR heslo_hash IS NULL OR blokovan <> 0 OR COALESCE(CAST(notif_emaily AS SIGNED),1) <> 0)"));
    $chk('V03', 'Kazdy odberatel ma prave 1 aktivni vychozi misto dodani', q("SELECT o.id, (SELECT COUNT(*) FROM mista_dodani m WHERE m.odberatel_id=o.id AND m.aktivni=1 AND m.vychozi=1) n FROM odberatele o WHERE o.cislo LIKE '$T-C%' HAVING n <> 1"));
    $chk('V04', 'Kazdy odberatel se prihlasil do B2B portalu (HTTP 200)', count(array_filter($custRecs, fn($c) => ($c['login'] ?? 0) !== 200 && empty($c['err'][0]))), count($custRecs) . ' zaznamu');
    $errs = []; foreach ($custRecs as $c) foreach ($c['err'] as $e) $errs[] = "#{$c['i']}: $e";
    $chk('V05', 'Zakaznicka faze bez chyb HTTP (odberatel/login/objednavka/uprava/storno)', $errs);
    $cc = []; foreach ($custRecs as $c) foreach ($c['chk'] as $e) $cc[] = "#{$c['i']}: $e";
    $chk('V06', 'Cena na objednavce = cena v katalogu, kterou zakaznik videl; radky = poslane; izolace objednavek', $cc);

    // — hlavička objednávky vs řádky —
    $chk('V07', 'Objednavka: hlavicka bez DPH = suma radku (±0,011) a celkem = bez + DPH (±0,011)', q("SELECT o.id, o.cislo, o.castka_bez_dph, ROUND(SUM(p.mnozstvi*p.cena_bez_dph),3) s, o.castka_dph, o.castka_celkem FROM objednavky o JOIN objednavky_polozky p ON p.objednavka_id=o.id WHERE o.odberatel_id IN ($C) GROUP BY o.id HAVING ABS(o.castka_bez_dph - s) > 0.011 OR ABS(o.castka_celkem - (o.castka_bez_dph + o.castka_dph)) > 0.011"));
    $chk('V08', 'Objednavka: DPH v hlavicce = suma radek×sazba (±0,02)', q("SELECT o.id, o.cislo, o.castka_dph, ROUND(SUM(p.mnozstvi*p.cena_bez_dph*p.sazba_dph/100),3) s FROM objednavky o JOIN objednavky_polozky p ON p.objednavka_id=o.id WHERE o.odberatel_id IN ($C) GROUP BY o.id HAVING ABS(o.castka_dph - s) > 0.02"));

    // — nezávislá replika ceníku (priorita výrobek > kategorie > nadřazená > sortiment > globální %) —
    $base = array_column(q('SELECT id, cena_bez_dph, kategorie_id FROM vyrobky'), null, 'id');
    $par = array_column(q('SELECT id, parent_id FROM kategorie_vyrobku WHERE parent_id IS NOT NULL'), 'parent_id', 'id');
    $grpG = array_column(q('SELECT id, globalni_sleva_pct FROM cenove_skupiny'), 'globalni_sleva_pct', 'id');
    $rules = []; foreach (q('SELECT skupina_id, kategorie_id, vyrobek_id, sleva_pct, pevna_cena FROM cenove_skupiny_slevy') as $r) {
        $g = (int) $r['skupina_id'];
        if ($r['vyrobek_id']) $rules[$g]['v'][(int) $r['vyrobek_id']] = $r; elseif ($r['kategorie_id']) $rules[$g]['k'][(int) $r['kategorie_id']] = $r; else $rules[$g]['s'] = $r;
    }
    $grpOf = array_column(q("SELECT id, cenova_skupina_id FROM odberatele WHERE cislo LIKE '$T-C%'"), 'cenova_skupina_id', 'id');
    $pm = [];
    foreach ($orders as $o) foreach ($o['lines'] as $ln) {
        if (!isset($base[$ln['v']])) continue;
        $b = (float) $base[$ln['v']]['cena_bez_dph']; $k = (int) $base[$ln['v']]['kategorie_id']; $g = (int) ($grpOf[$o['odb']] ?? 0);
        $exp = $b;
        if ($g) {
            $r = $rules[$g]['v'][$ln['v']] ?? $rules[$g]['k'][$k] ?? (isset($par[$k]) ? ($rules[$g]['k'][(int) $par[$k]] ?? null) : null) ?? $rules[$g]['s'] ?? null;
            if ($r) $exp = $r['pevna_cena'] !== null ? (float) $r['pevna_cena'] : round($b * (1 - (float) $r['sleva_pct'] / 100), 2);
            elseif ((float) ($grpG[$g] ?? 0) > 0) $exp = round($b * (1 - (float) $grpG[$g] / 100), 2);
        }
        if (abs($exp - $ln['c']) > 0.011) $pm[] = "obj {$o['id']} ({$o['via']}) v{$ln['v']} sk$g: uctovano {$ln['c']}, replika $exp";
    }
    $chk('V09', 'Uctovana cena = nezavisla replika pravidel cenika', $pm, 'sezonni upravy nejsou v replice (zadne sezonni vyrobky v poolu)');

    // — DL —
    $chk('V10', 'Kazda ziva objednavka ma prave 1 DL', q("SELECT o.id, o.cislo, COUNT(dl.id) n FROM objednavky o LEFT JOIN dodaci_listy dl ON dl.objednavka_id=o.id WHERE $LIVE GROUP BY o.id HAVING n <> 1"));
    $chk('V11', 'DL: odberatel, misto, datum dodani a celkem = objednavka', q("SELECT o.id, o.cislo, dl.cislo dl, o.castka_celkem, dl.castka_celkem dlc FROM objednavky o JOIN dodaci_listy dl ON dl.objednavka_id=o.id WHERE $LIVE AND (dl.odberatel_id<>o.odberatel_id OR NOT (dl.misto_dodani_id <=> o.misto_dodani_id) OR dl.datum_dodani<>o.datum_dodani OR ABS(dl.castka_celkem-o.castka_celkem)>0.005)"));
    $chk('V12', 'DL radky = radky objednavky (vyrobek, mnozstvi, cena) v obou smerech', q("SELECT o.id, p.vyrobek_id, p.mnozstvi, (SELECT SUM(x.mnozstvi) FROM dodaci_list_polozky x WHERE x.dodaci_list_id=dl.id AND x.vyrobek_id=p.vyrobek_id) dq FROM objednavky o JOIN dodaci_listy dl ON dl.objednavka_id=o.id JOIN objednavky_polozky p ON p.objednavka_id=o.id WHERE $LIVE AND p.vyrobek_id IS NOT NULL HAVING dq IS NULL OR ABS(dq - p.mnozstvi) > 0.0005
        UNION ALL SELECT o.id, x.vyrobek_id, x.mnozstvi, NULL FROM objednavky o JOIN dodaci_listy dl ON dl.objednavka_id=o.id JOIN dodaci_list_polozky x ON x.dodaci_list_id=dl.id WHERE $LIVE AND x.vyrobek_id IS NOT NULL AND NOT EXISTS (SELECT 1 FROM objednavky_polozky p WHERE p.objednavka_id=o.id AND p.vyrobek_id=x.vyrobek_id AND ABS(p.cena_bez_dph-x.cena_bez_dph)<0.005)"));
    $cancelledIds = implode(',', array_keys(array_filter($orders, fn($x) => $x['cancelled']))) ?: '0';
    $chk('V13', 'Zrusene objednavky nemaji DL a maji stav zrusena', q("SELECT o.id, o.cislo, o.stav FROM objednavky o WHERE o.id IN ($cancelledIds) AND (o.stav<>'zrusena' OR (SELECT COUNT(*) FROM dodaci_listy d WHERE d.objednavka_id=o.id)>0)"));
    $chk('V14', 'Kazda ziva objednavka skoncila „dorucena"', q("SELECT o.id, o.cislo, o.stav FROM objednavky o WHERE $LIVE AND o.stav <> 'dorucena'"));
    $chk('V15', 'Log stavu: expedovana zapsana pred dorucena', q("SELECT o.id FROM objednavky o WHERE $LIVE AND o.stav='dorucena' AND NOT EXISTS (SELECT 1 FROM objednavky_zmeny z1 JOIN objednavky_zmeny z2 ON z2.objednavka_id=z1.objednavka_id AND z2.id>z1.id WHERE z1.objednavka_id=o.id AND z1.detail LIKE '%expedovana%' AND z2.detail LIKE '%dorucena%')"), 'cte objednavky_zmeny.detail', 'warn');

    // — faktury + úhrady —
    $FA = "SELECT f.id FROM faktury f WHERE f.odberatel_id IN ($C) AND f.je_dobropis=0";
    $chk('V16', 'Kazdy DL zive objednavky je na prave 1 (ne-dobropisove) fakture', q("SELECT o.id, dl.id dl, COUNT(f.id) n FROM objednavky o JOIN dodaci_listy dl ON dl.objednavka_id=o.id LEFT JOIN faktury_dodaci_listy fdl ON fdl.dodaci_list_id=dl.id LEFT JOIN faktury f ON f.id=fdl.faktura_id AND f.je_dobropis=0 WHERE $LIVE GROUP BY o.id, dl.id HAVING n <> 1"));
    $chk('V17', 'Faktura: celkem = bez + DPH (±0,011)', q("SELECT f.id, f.cislo, f.castka_bez_dph, f.castka_dph, f.castka_celkem FROM faktury f WHERE f.id IN ($FA) AND ABS(f.castka_celkem-(f.castka_bez_dph+f.castka_dph))>0.011"));
    $chk('V18', 'Faktura celkem = suma hlavicek jejich DL (±0,01 × pocet DL)', q("SELECT f.id, f.cislo, f.castka_celkem, SUM(dl.castka_celkem) s, COUNT(*) n FROM faktury f JOIN faktury_dodaci_listy fdl ON fdl.faktura_id=f.id JOIN dodaci_listy dl ON dl.id=fdl.dodaci_list_id WHERE f.id IN ($FA) GROUP BY f.id HAVING ABS(f.castka_celkem - s) > 0.01*n"));
    $chk('V19', 'Faktura bez DPH = suma radku jejich DL (to, co se tiskne) (±0,005 × radku + 0,01)', q("SELECT f.id, f.cislo, f.castka_bez_dph, ROUND(SUM(x.mnozstvi*x.cena_bez_dph),3) s, COUNT(*) n FROM faktury f JOIN faktury_dodaci_listy fdl ON fdl.faktura_id=f.id JOIN dodaci_list_polozky x ON x.dodaci_list_id=fdl.dodaci_list_id WHERE f.id IN ($FA) GROUP BY f.id HAVING ABS(f.castka_bez_dph - s) > 0.005*n + 0.01"));
    $chk('V20', 'Splatnost faktury = vystaveni + splatnost odberatele', q("SELECT f.id, f.cislo, o.splatnost_dni, DATEDIFF(f.datum_splatnosti, f.datum_vystaveni) d FROM faktury f JOIN odberatele o ON o.id=f.odberatel_id WHERE f.id IN ($FA) AND DATEDIFF(f.datum_splatnosti, f.datum_vystaveni) <> COALESCE(o.splatnost_dni,14)"), 'odberatele se splatnosti 0 dni mohou dostat 14 (znama vetev ?: 14)', 'warn');
    $chk('V21', 'Penize per odberatel: suma zivych objednavek = suma faktur (±0,01 × obj.)', q("SELECT c.id, ob.n, ob.s, fa.s fs FROM odberatele c JOIN (SELECT odberatel_id, COUNT(*) n, SUM(castka_celkem) s FROM objednavky WHERE stav<>'zrusena' GROUP BY odberatel_id) ob ON ob.odberatel_id=c.id LEFT JOIN (SELECT odberatel_id, SUM(castka_celkem) s FROM faktury WHERE je_dobropis=0 GROUP BY odberatel_id) fa ON fa.odberatel_id=c.id WHERE c.cislo LIKE '$T-C%' AND ABS(ob.s - COALESCE(fa.s,0)) > 0.01*ob.n"));
    $want = []; foreach (jlines('pay-*.jsonl') as $p) $want[$p['id']] = $p['want'];
    $pv = [];
    foreach (array_chunk(array_keys($want), 1000) as $ch) foreach (q('SELECT id, castka_celkem, castka_uhrazeno, datum_uhrazeni FROM faktury WHERE id IN (' . implode(',', $ch) . ')') as $f) {
        $w = $want[$f['id']] ?? null; $paid = (float) $f['castka_uhrazeno'];
        if ($w === null ? abs($paid) > 0.005 : abs($paid - $w) > 0.005) $pv[] = "FA {$f['id']}: chtel " . ($w ?? 'null') . ", je $paid";
        $full = $paid >= (float) $f['castka_celkem'] - 0.005 && (float) $f['castka_celkem'] > 0;
        if ($full !== ($f['datum_uhrazeni'] !== null)) $pv[] = "FA {$f['id']}: datum_uhrazeni nesedi (plne=" . (int) $full . ')';
    }
    $chk('V22', 'Uhrady: uhrazena castka a datum uhrady presne dle zadani', $pv);

    // — číselné řady —
    $chk('V23', 'Cisla dokladu unikatni (objednavky, DL, faktury)', q("SELECT 'obj' t, cislo, COUNT(*) n FROM objednavky GROUP BY cislo HAVING n>1 UNION ALL SELECT 'dl', cislo, COUNT(*) FROM dodaci_listy GROUP BY cislo HAVING COUNT(*)>1 UNION ALL SELECT 'fa', cislo, COUNT(*) FROM faktury GROUP BY cislo HAVING COUNT(*)>1"));
    $b2bNums = [];
    foreach ($orders as $o) if ($o['via'] === 'b2b' && $o['cislo'] && preg_match('/-(\d+)$/', (string) $o['cislo'], $mm)) $b2bNums[] = (int) $mm[1];
    sort($b2bNums); $gaps = 0;
    for ($x = 1; $x < count($b2bNums); $x++) $gaps += max(0, $b2bNums[$x] - $b2bNums[$x - 1] - 1);
    $chk('V24', 'B2B cisla objednavek harnessu jsou souvisla (bez velkych der)', $gaps > 5 ? ["$gaps chybejicich cisel mezi " . ($b2bNums[0] ?? 0) . ' a ' . (end($b2bNums) ?: 0)] : 0, 'mensi mezery = soubezne cizi objednavky nebo jine kanaly', 'warn');

    // — sklad —
    $chk('V25', 'Sklad A = suma B pro vsechny suroviny', q("SELECT s.id, s.nazev, s.stock_aktualni, COALESCE((SELECT SUM(sp.stav) FROM sklad_polozky sp WHERE sp.item_typ='surovina' AND sp.item_id=s.id),0) b FROM suroviny s HAVING ABS(s.stock_aktualni - b) > 0.0015"));
    $bl = jload('baseline.json', []); $mid = (int) ($bl['max_id']['sklad_pohyby_v2'] ?? 0);
    $chk('V26', 'Ledger skladu: stav_pred pohybu = stav_po predchoziho (pohyby za beh testu)', q("SELECT a.id, a.item_typ, a.item_id, a.stav_pred, (SELECT b.stav_po FROM sklad_pohyby_v2 b WHERE b.sklad_id=a.sklad_id AND b.item_typ=a.item_typ AND b.item_id=a.item_id AND b.id<a.id ORDER BY b.id DESC LIMIT 1) prev FROM sklad_pohyby_v2 a WHERE a.id > $mid HAVING prev IS NOT NULL AND ABS(prev - a.stav_pred) > 0.0015"));
    $CL = 'COLLATE utf8mb4_unicode_ci'; // sklad_pohyby_v2 je general_ci, sklad_polozky unicode_ci
    $chk('V27', 'Stav skladu = stav_po posledniho pohybu (zadna neevidovana zmena)', q("SELECT sp.id, sp.item_typ, sp.item_id, sp.stav, (SELECT b.stav_po FROM sklad_pohyby_v2 b WHERE b.sklad_id=sp.sklad_id AND b.item_typ $CL =sp.item_typ AND b.item_id=sp.item_id ORDER BY b.id DESC LIMIT 1) last FROM sklad_polozky sp WHERE EXISTS (SELECT 1 FROM sklad_pohyby_v2 x WHERE x.id > $mid AND x.sklad_id=sp.sklad_id AND x.item_typ $CL =sp.item_typ AND x.item_id=sp.item_id) HAVING ABS(sp.stav - last) > 0.0015"));
    $chk('V28', 'Zadne zaporne zasoby po odpisu (polozky dotcene testem)', q("SELECT sp.item_typ, sp.item_id, sp.stav FROM sklad_polozky sp WHERE sp.stav < -0.0005 AND EXISTS (SELECT 1 FROM sklad_pohyby_v2 x WHERE x.id > $mid AND x.item_typ $CL =sp.item_typ AND x.item_id=sp.item_id)"));
    $recv = jload('stock.json', [])['receipts'] ?? [];
    $rbad = [];
    foreach ($recv as $r) { $x = q("SELECT COUNT(*) n, COALESCE(SUM(stav_po-stav_pred),0) d FROM sklad_pohyby_v2 WHERE typ='prijem' AND poznamka=:p", ['p' => $r['pz']])[0]; if ((int) $x['n'] !== 1 || abs((float) $x['d'] - $r['mn']) > 0.0015) $rbad[] = $r['pz'] . " n={$x['n']} delta={$x['d']} chtel {$r['mn']}"; }
    $chk('V29', 'Kazdy prijem testu zapsan prave 1× se zadanym mnozstvim', $rbad);

    // — výrobní odpis = nezávislá BOM replika —
    $wo = []; $plan = []; $prod = jload('production.json', []);
    foreach (cfg('dates') as $d) {
        $sur = []; $pol = []; $byProd = [];
        foreach ($orders as $o) if ($o['datum'] === $d && !$o['cancelled']) foreach ($o['lines'] as $ln) { bom_x($ln['v'], $ln['q'], $sur, $pol); $byProd[$ln['v']] = ($byProd[$ln['v']] ?? 0) + $ln['q']; }
        $act = array_column(q("SELECT item_id, SUM(stav_pred-stav_po) q FROM sklad_pohyby_v2 WHERE typ='vydej' AND item_typ='surovina' AND poznamka=:p GROUP BY item_id", ['p' => "$T-odpis-$d"]), 'q', 'item_id');
        foreach (array_keys($sur + $act) as $sid) { $e = round($sur[$sid] ?? 0, 3); $a = round((float) ($act[$sid] ?? 0), 3); if (abs($e - $a) > 0.003) $wo[] = "$d surovina $sid: kusovnik $e, odepsano $a"; }
        $actP = array_column(q("SELECT item_id, SUM(stav_pred-stav_po) q FROM sklad_pohyby_v2 WHERE typ='vydej' AND item_typ='vyrobek' AND poznamka=:p GROUP BY item_id", ['p' => "$T-odpis-$d (polotovar)"]), 'q', 'item_id');
        foreach (array_keys($pol + $actP) as $pid) { $e = round($pol[$pid] ?? 0, 3); $a = round((float) ($actP[$pid] ?? 0), 3); if (abs($e - $a) > 0.003) $wo[] = "$d polotovar $pid: kusovnik $e, odepsano $a"; }
        if (!q1("SELECT 1 FROM nastaveni WHERE klic=:k", ['k' => "odpis_vyroba_$d"])) $wo[] = "$d: chybi marker odpisu";
        $srv = []; foreach (($prod[$d]['souhrn'] ?? []) as $s) $srv[(int) $s['id']] = (float) $s['celkem'];
        foreach (array_keys($byProd + $srv) as $vid) if (abs(($byProd[$vid] ?? 0) - ($srv[$vid] ?? 0)) > 0.0005) $plan[] = "$d vyrobek $vid: objednano " . ($byProd[$vid] ?? 0) . ', plan ' . ($srv[$vid] ?? 0);
        if (($prod[$d]['odpis_znovu']['code'] ?? 0) !== 409) $wo[] = "$d: opakovany odpis vratil " . ($prod[$d]['odpis_znovu']['code'] ?? '?') . ' (cekal 409)';
    }
    $chk('V30', 'Vyrobni odpis per den = nezavisla BOM replika (suroviny i stockovany polotovar), marker, opakovani=409', $wo);
    $chk('V31', 'Vyrobni plan (souhrn) per den = suma objednanych kusu', $plan);

    // — trasa —
    $rz = jload('rozvoz.json', []); $rv = []; $pickup = 0;
    foreach (cfg('dates') as $d) {
        $db = array_map('intval', array_column(q('SELECT id FROM dodaci_listy WHERE datum_dodani=:d', ['d' => $d]), 'id'));
        $api = $rz[$d]['dl_ids'] ?? [];
        sort($db); $a2 = $api; sort($a2);
        if ($db !== $a2) $rv[] = "$d: DB " . count($db) . ' DL vs trasa ' . count($api) . ' (navic ' . count(array_diff($a2, $db)) . ', chybi ' . count(array_diff($db, $a2)) . ')';
        if (count($api) !== count(array_unique($api))) $rv[] = "$d: DL na trase vicekrat";
        if (($rz[$d]['print_zastavek'] ?? -1) !== count($api)) $rv[] = "$d: tisk " . ($rz[$d]['print_zastavek'] ?? '?') . ' zastavek vs ' . count($api);
        $sum = (float) q1('SELECT COALESCE(SUM(castka_celkem),0) FROM dodaci_listy WHERE datum_dodani=:d', ['d' => $d]);
        if (abs($sum - (float) ($rz[$d]['celkem_kc'] ?? 0)) > 0.05) $rv[] = "$d: trasa celkem " . ($rz[$d]['celkem_kc'] ?? '?') . " vs DB $sum";
    }
    foreach ($orders as $o) if (!$o['cancelled'] && ($o['doprava'] ?? '') === 'vlastni') $pickup++;
    $chk('V32', 'Rozvozova trasa per den = vsechny DL dne (JSON i tisk), soucty sedi', $rv);
    $chk('V33', 'Osobni odber (doprava=vlastni) neni na trase ridice', $pickup, "$pickup objednavek s osobnim odberem — trasa ignoruje zpusob dopravy (znama vlastnost)", 'warn');

    // — e-maily (mail sink) —
    $sink = is_file(cfg('root') . '/api/.mail-sink.log') ? array_slice(file(cfg('root') . '/api/.mail-sink.log', FILE_IGNORE_NEW_LINES), (int) ($bl['sink_lines'] ?? 0)) : [];
    $toCust = 0; $subj = [];
    foreach ($sink as $l) { $m = json_decode($l, true); if (!$m) continue; if (str_contains(mb_strtolower((string) ($m['to'] ?? '')), strtolower($T) . '.invalid')) $toCust++; $s = preg_replace('/\d+/u', '#', (string) ($m['subj'] ?? '')); $subj[$s] = ($subj[$s] ?? 0) + 1; }
    arsort($subj);
    $chk('V34', 'Zakaznik s vypnutymi notifikacemi (notif_emaily=0) nedostal zadny e-mail', $toCust, count($sink) . ' e-mailu zachyceno sinkem; temata: ' . json_encode(array_slice($subj, 0, 6, true), JSON_UNESCAPED_UNICODE), 'warn');

    // — chyby serveru —
    $ae = []; foreach (['SELECT id, endpoint, message, created_at FROM app_errors WHERE id > :m ORDER BY id LIMIT 20', 'SELECT * FROM app_errors WHERE id > :m ORDER BY id LIMIT 20'] as $sql) { try { $ae = q($sql, ['m' => (int) ($bl['max_id']['app_errors'] ?? 0)]); break; } catch (Throwable $e) {} }
    $chk('V35', 'Zadne nove zaznamy v app_errors', $ae);
    $el = []; foreach ($bl['error_logs'] ?? [] as $f) if (is_file($f['f']) && filesize($f['f']) > $f['size']) { $h = fopen($f['f'], 'r'); fseek($h, $f['size']); $el = array_merge($el, array_slice(array_values(array_filter(explode("\n", stream_get_contents($h)))), 0, 30)); fclose($h); }
    $chk('V36', 'Zadne nove radky v PHP error_logu', $el);
    $h5 = []; foreach (glob(rpath('errors-*.json')) ?: [] as $f) foreach (json_decode((string) file_get_contents($f), true) ?: [] as $e) if (!str_contains((string) $e['label'], 'sonda')) $h5[] = ['label' => $e['label'], 'code' => $e['code'], 'body' => mb_substr((string) $e['body'], 0, 120)];
    $chk('V37', 'Zadna HTTP odpoved 5xx v hlavnim retezci (mimo sondy)', $h5);

    // — recepty + příznaky polotovarů —
    $cat = jload('catalog.json'); $rv2 = [];
    foreach (($cat['vyrobky'] ?? []) as $key => $v) {
        $rows = q('SELECT 1 FROM vyrobek_suroviny WHERE vyrobek_id=:v', ['v' => $v['id']]);
        if (count($rows) !== count($v['recept'])) $rv2[] = "$key: ulozeno " . count($rows) . ' radku receptu, poslano ' . count($v['recept']);
        $fl = q('SELECT COALESCE(je_polotovar,0) jp, COALESCE(sleduje_sklad,0) ss FROM vyrobky WHERE id=:v', ['v' => $v['id']])[0] ?? [];
        if ((int) ($fl['jp'] ?? -1) !== $v['jp'] || (int) ($fl['ss'] ?? -1) !== $v['ss']) $rv2[] = "$key: finalni priznaky polotovaru nesedi";
        if (($v['jp'] || $v['ss']) && ($v['flags_after_post']['je_polotovar'] !== $v['jp'] || $v['flags_after_post']['sleduje_sklad'] !== $v['ss'])) $rv2[] = "$key: POST zahodil je_polotovar/sleduje_sklad (po POST " . json_encode($v['flags_after_post']) . ', srovnano az PUTem)';
    }
    $chk('V38', 'Recepty a priznaky polotovaru ulozene tak, jak poslany (vc. POST)', $rv2, 'POST vyrobku ignoruje je_polotovar/sleduje_sklad — zname, harness srovnava PUTem', 'warn');

    // — portál —
    $pr = [];
    foreach (jlines('portal-*.jsonl') as $p) {
        if (($p['login'] ?? 0) !== 200) { $pr[] = "#{$p['i']} login " . ($p['login'] ?? '?'); continue; }
        if (($p['other_fa'] ?? 401) === 200) $pr[] = "#{$p['i']} vidi CIZI fakturu";
        if (($p['other_dl'] ?? 401) === 200) $pr[] = "#{$p['i']} vidi CIZI DL";
        if (isset($p['own_fa']) && $p['own_fa'] !== 200) $pr[] = "#{$p['i']} nevidi vlastni fakturu ({$p['own_fa']})";
        foreach ($p['faktury'] ?? [] as $f) {
            $db = q('SELECT castka_celkem, castka_uhrazeno FROM faktury WHERE id=:i', ['i' => $f['id']])[0] ?? null;
            if (!$db) continue;
            $paid = (float) $db['castka_uhrazeno'] >= (float) $db['castka_celkem'] - 0.005;
            if ($paid && $f['stav'] !== 'uhrazena') $pr[] = "#{$p['i']} FA {$f['id']}: portal '{$f['stav']}', DB uhrazena";
            if (!$paid && $f['stav'] === 'uhrazena') $pr[] = "#{$p['i']} FA {$f['id']}: portal 'uhrazena', DB ne";
        }
    }
    $chk('V39', 'Zakaznicky portal (vzorek): prihlaseni, stavy faktur = DB, cizi doklady nedostupne', $pr);

    // — integrity audit před/po —
    $ib = jload('integrity-before.json', []); $ia = jload('integrity-after.json', []);
    $okBefore = []; foreach (($ib['checks'] ?? []) as $c) if (!empty($c['ok'])) $okBefore[$c['klic']] = 1;
    $regr = []; foreach (($ia['checks'] ?? []) as $c) if (isset($okBefore[$c['klic']]) && empty($c['ok'])) $regr[] = $c['klic'] . ': ' . mb_substr((string) ($c['detail'] ?? ''), 0, 120);
    $chk('V40', 'Vestaveny integrity audit: zadna kontrola OK→FAIL vlivem testu', $regr, 'audit je globalni (demo ma i legacy data)', 'warn');

    jsave('verify.json', $V);
    return $V;
}

// ───────────────────────── reprodukční sondy (funkční chování; oddělení zákazníci + vlastní datum) ─────────────────────────
function run_probes(): void {
    if (cfg('skip_probes') || phase_done('probes')) { L('probes: preskoceno'); return; }
    $A = new Admin(); $cat = jload('catalog.json'); $P = []; $D = cfg('probe_date'); $tag = cfg('tag');
    $mk = function (int $n, array $extra = []) use ($A, $tag) {
        $c = gen_customer(900000 + $n, []);
        $c['cislo'] = "$tag-P" . sprintf('%02d', $n); $c['nazev'] = "Sonda $n – " . $c['nazev'];
        $c['login_email'] = "sonda$n@" . strtolower($tag) . '.invalid';
        $c = array_merge($c, ['cenova_skupina_id' => null], $extra);
        $r = $A->call('POST', 'api/admin_odberatele.php', $c);
        return [(int) ($r['json']['id'] ?? 0), $c];
    };
    $b2b = function (array $c) { $B = new Http(); $B->req('POST', 'api/login.php', ['email' => $c['login_email'], 'heslo' => $c['heslo']], 'POST login.php (sonda)'); return $B; };
    $misto = fn(int $odb) => (int) q1('SELECT id FROM mista_dodani WHERE odberatel_id=:o AND aktivni=1 ORDER BY vychozi DESC, id LIMIT 1', ['o' => $odb]);
    $vid = (int) $cat['vyrobky']['V3']['id'];
    $order = fn(Http $B, int $odb, float $qq, array $over = []) => $B->req('POST', 'api/objednavky.php', array_merge(['typ' => 'jednorazova', 'misto_dodani_id' => $misto($odb) ?: null, 'polozky' => [['vyrobek_id' => $vid, 'mnozstvi' => $qq]], 'doprava' => 'rozvoz', 'platba' => 'faktura', 'gdpr_souhlas' => true, 'datum_dodani' => $D], $over), 'POST objednavky.php (sonda)');
    $rec = function (string $id, string $title, string $expect, string $actual, ?bool $bug, $detail = null) use (&$P) {
        $P[] = ['id' => $id, 'title' => $title, 'expect' => $expect, 'actual' => $actual, 'detail' => $detail, 'verdict' => $bug === null ? 'NEJASNE' : ($bug ? 'CHYBA POTVRZENA' : 'OK (nereprodukovano)')];
        L("  sonda $id: " . ($bug === null ? '?' : ($bug ? 'CHYBA' : 'ok')) . " — $title → $actual");
    };
    $stock = fn(int $sid) => (float) q1('SELECT stock_aktualni FROM suroviny WHERE id=:i', ['i' => $sid]);
    try {
        // PR01/02 zablokovaný zákazník s živou session
        [$o1, $c1] = $mk(1); $B1 = $b2b($c1);
        $A->call('POST', 'api/admin_inline_edit.php', ['table' => 'odberatele', 'id' => $o1, 'field' => 'blokovan', 'value' => 1]);
        $r = $order($B1, $o1, 10);
        $rec('PR01', 'Zablokovany odberatel s otevrenou session vytvori objednavku', '401/403', 'HTTP ' . $r['code'], $r['code'] === 201, $r['json']);
        if ($r['code'] === 201) $A->call('PUT', 'api/admin_objednavky.php', ['id' => (int) $r['json']['id'], 'stav' => 'zrusena']);
        $B1b = new Http(); $r = $B1b->req('POST', 'api/login.php', ['email' => $c1['login_email'], 'heslo' => $c1['heslo']], 'POST login.php (sonda)');
        $rec('PR02', 'Zablokovany odberatel se znovu neprihlasi', '401', 'HTTP ' . $r['code'], $r['code'] === 200);

        // PR03 cross-customer faktura.php?action=vytvor
        [$oa, $ca] = $mk(3); [$ob, $cb] = $mk(4);
        $BA = $b2b($ca); $BB = $b2b($cb);
        $ra = $order($BA, $oa, 10); $rbb = $order($BB, $ob, 20);
        $hf = $A->call('POST', 'api/admin_objednavky_hromadne.php', ['action' => 'fa', 'objednavka_ids' => [(int) $ra['json']['id']], 'datum_vystaveni' => $D], 'POST hromadne (fa)');
        $faA = (int) ($hf['json']['vytvoreno'][0]['faktura_id'] ?? 0); $objB = (int) ($rbb['json']['id'] ?? 0);
        $before = (int) q1('SELECT COUNT(*) FROM dodaci_listy WHERE objednavka_id=:o', ['o' => $objB]);
        $x = $BA->req('POST', "api/faktura.php?action=vytvor&id=$faA", ['objednavka_id' => $objB], 'POST faktura.php?action=vytvor (sonda)');
        $after = (int) q1('SELECT COUNT(*) FROM dodaci_listy WHERE objednavka_id=:o', ['o' => $objB]);
        $rec('PR03', 'B2B zakaznik A vystavi DL+fakturu k CIZI objednavce (faktura.php?action=vytvor)', '401/403, zadny DL u B', 'HTTP ' . $x['code'] . ', DL u B ' . $before . '->' . $after, $after > $before, $x['json'] ?? substr($x['raw'], 0, 150));

        // PR04 B2B PUT bez místa → misto 0 → FK 500
        [$o5, $c5] = $mk(5, ['vytvorit_hlavni_pobocku' => 0]); $B5 = $b2b($c5);
        $r = $B5->req('POST', 'api/objednavky.php', ['typ' => 'jednorazova', 'misto_dodani_id' => null, 'polozky' => [['vyrobek_id' => $vid, 'mnozstvi' => 10]], 'doprava' => 'vlastni', 'platba' => 'prevod', 'gdpr_souhlas' => true, 'datum_dodani' => $D], 'POST objednavky.php (sonda)');
        if ($r['code'] === 201) {
            $u = $B5->req('PUT', 'api/objednavky.php', ['id' => (int) $r['json']['id'], 'polozky' => [['vyrobek_id' => $vid, 'mnozstvi' => 15]]], 'PUT objednavky.php (sonda)');
            $rec('PR04', 'B2B uprava objednavky bez mista dodani (misto=0 → FK)', '200', 'HTTP ' . $u['code'], $u['code'] >= 500, substr($u['raw'], 0, 150));
        } else $rec('PR04', 'B2B objednavka bez mista dodani', '201', 'HTTP ' . $r['code'], null, substr($r['raw'], 0, 150));

        // PR05 admin pridat_polozku → základní cena místo ceníku skupiny
        $gid = $cat['groups']['Hotely'] ?? (array_values($cat['groups'])[0] ?? null);
        [$o6] = $mk(6, ['cenova_skupina_id' => $gid]);
        $ao = $A->call('POST', 'api/admin_objednavky.php', ['action' => 'vytvorit', 'odberatel_id' => $o6, 'misto_dodani_id' => $misto($o6), 'datum_dodani' => $D, 'polozky' => [['vyrobek_id' => $vid, 'mnozstvi' => 5]]]);
        $v7 = (int) $cat['vyrobky']['V7']['id'];
        $A->call('POST', 'api/admin_objednavky.php', ['action' => 'pridat_polozku', 'objednavka_id' => (int) $ao['json']['id'], 'vyrobek_id' => $v7, 'mnozstvi' => 2]);
        $cen = $A->call('GET', "api/cenik_odberatele.php?odberatel_id=$o6");
        $exp = null; foreach (($cen['json']['vyrobky'] ?? $cen['json'] ?? []) as $v) if ((int) ($v['id'] ?? 0) === $v7) $exp = (float) $v['cena_bez_dph'];
        $got = (float) q1('SELECT cena_bez_dph FROM objednavky_polozky WHERE objednavka_id=:o AND vyrobek_id=:v', ['o' => (int) $ao['json']['id'], 'v' => $v7]);
        $rec('PR05', 'Admin „pridat polozku" pouzije cenik zakaznika (skupina Hotely)', "cena $exp", "cena $got", $exp !== null ? abs($exp - $got) > 0.005 : null);

        // PR06 3 souběžné DL na stejnou objednávku (3 admin sessions) → race
        [$o7, $c7] = $mk(7); $B7 = $b2b($c7);
        $oid7 = (int) $order($B7, $o7, 10)['json']['id'];
        parallel_post([new Admin(), new Admin(), new Admin()], 'api/admin_objednavky_hromadne.php', ['action' => 'dl', 'objednavka_ids' => [$oid7]]);
        $par = (int) q1('SELECT COUNT(*) FROM dodaci_listy WHERE objednavka_id=:o', ['o' => $oid7]);
        $rec('PR06', '3 soubezne „vystavit DL" na tutez objednavku (3 admin sessions)', '1 DL', "$par DL", $par > 1);

        // PR07 admin UI posílá datum_dodani → 409 u objednávky s DL
        $u = $A->call('PUT', 'api/admin_objednavky.php', ['id' => $oid7, 'stav' => 'dorucena', 'datum_dodani' => $D, 'poznamka' => '', 'interni_pozn' => ''], 'PUT admin_objednavky.php (jako UI)');
        $rec('PR07', 'Stav „doruceno" s telem jako posila admin UI (vc. nezmeneneho datum_dodani) u obj. s DL', '200', 'HTTP ' . $u['code'], $u['code'] === 409, substr($u['raw'], 0, 150));

        // PR08 zrušená objednávka s DL zůstane na trase
        $A->call('PUT', 'api/admin_objednavky.php', ['id' => $oid7, 'stav' => 'zrusena']);
        $rz = $A->call('GET', "api/admin_rozvozy.php?datum=$D");
        $dl7 = (int) q1('SELECT id FROM dodaci_listy WHERE objednavka_id=:o LIMIT 1', ['o' => $oid7]);
        $on = false; foreach (($rz['json']['mesta'] ?? []) as $m) foreach ($m['dl'] as $x) if ((int) $x['id'] === $dl7) $on = true;
        $rec('PR08', 'DL zrusene objednavky zmizi z rozvozove trasy', 'neni na trase', $on ? 'JE na trase' : 'neni', $on);

        // Zásobení surovin pro sondové objednávky dne D + jednotková sonda
        $kmin = (int) $cat['suroviny']['Kmín celý']['id'];
        $pv = $A->call('POST', 'api/admin_vyrobky.php', ['nazev' => "Sonda jednotek ($tag)", 'cislo' => "$tag-PROBE-UNIT", 'jednotka_id' => 1, 'sazba_dph_id' => 1, 'cena_bez_dph' => 1, 'aktivni' => 1,
            'slozeni_polozky' => [['surovina_id' => $kmin, 'mnozstvi' => 0.01, 'jednotka' => 'kg', 'poradi' => 0]]]);
        $pvid = (int) ($pv['json']['id'] ?? 0);
        [$o9] = $mk(9);
        $A->call('POST', 'api/admin_objednavky.php', ['action' => 'vytvorit', 'odberatel_id' => $o9, 'misto_dodani_id' => $misto($o9), 'datum_dodani' => $D, 'polozky' => [['vyrobek_id' => $pvid, 'mnozstvi' => 100]]]);
        $sp = $A->call('GET', "api/admin_vyroba.php?action=spotreba&datum=$D");
        foreach (($sp['json']['suroviny'] ?? []) as $s) if (empty($s['ok'])) $A->call('POST', 'api/admin_suroviny.php?action=sklad_prijem', ['surovina_id' => (int) $s['surovina_id'], 'mnozstvi' => ceil(((float) $s['potreba'] - (float) $s['skladem']) * 1.2 + 10), 'cena_za_jed' => 0, 'poznamka' => "$tag-rcv-probe-" . (int) $s['surovina_id']]);
        foreach (($sp['json']['polotovary'] ?? []) as $p) if ((float) $p['chybi'] > 0) $A->call('POST', 'api/admin_vyroba.php?action=vyrobit_polotovar', ['vyrobek_id' => (int) $p['vyrobek_id'], 'mnozstvi' => ceil((float) $p['chybi']) + 1, 'force' => true]);

        // PR09 (jednotka v receptu ignorována) + PR10 (2 souběžné odpisy téhož dne)
        $codes = parallel_post([new Admin(), new Admin()], 'api/admin_vyroba.php?action=odepsat_suroviny', ['datum' => $D, 'poznamka' => "$tag-odpis-$D"]);
        $mv = q("SELECT id, mnozstvi FROM sklad_pohyby_v2 WHERE item_typ='surovina' AND item_id=:i AND typ='vydej' AND poznamka=:p ORDER BY id", ['i' => $kmin, 'p' => "$tag-odpis-$D"]);
        $rec('PR10', '2 soubezne odpisy vyroby tehoz dne', '1× odecteno (druhy 409)', 'HTTP ' . implode('+', $codes) . ', vydej radku kminu: ' . count($mv), count($mv) > 1);
        $odepsano = (float) ($mv[0]['mnozstvi'] ?? 0);
        $rec('PR09', 'Recept 0,01 kg kminu (surovina vedena v g) × 100 ks', 'odepsano 1000 g', "odepsano $odepsano g", $mv ? abs($odepsano - 1000) > 0.5 : null);

        // PR11 storno výrobního odpisu má vrátit (chyba znaménka odečte znovu)
        if ($mv) {
            $s0 = $stock($kmin);
            $st = $A->call('POST', 'api/admin_sklad_pohyby.php?action=storno', ['pohyb_id' => (int) $mv[0]['id']]);
            $s1 = $stock($kmin);
            $rec('PR11', 'Storno vyrobniho odpisu (pohyb vydej) vrati surovinu na sklad', "stav vzroste o $odepsano", "HTTP {$st['code']}, stav " . round($s0, 1) . ' → ' . round($s1, 1), $st['code'] === 200 ? ($s1 < $s0 - 0.001) : null, substr($st['raw'], 0, 150));
        }
        // PR12 storno objednávky po odpisu suroviny nevrátí
        $ord9 = (int) q1('SELECT id FROM objednavky WHERE odberatel_id=:o LIMIT 1', ['o' => $o9]);
        $s0 = $stock($kmin);
        $A->call('PUT', 'api/admin_objednavky.php', ['id' => $ord9, 'stav' => 'zrusena']);
        $s1 = $stock($kmin);
        $rec('PR12', 'Zruseni objednavky po odpisu vyroby vrati suroviny na sklad', 'stav se zvysi', 'stav ' . round($s0, 1) . ' → ' . round($s1, 1), abs($s1 - $s0) < 0.0005);

        // PR13 úprava řádku auto-faktury (rucni=0) vynuluje fakturu
        if ($faA) {
            $det = $A->call('GET', "api/admin_faktury.php?id=$faA");
            $pl = $det['json']['polozky'][0] ?? null; $c0 = (float) q1('SELECT castka_celkem FROM faktury WHERE id=:f', ['f' => $faA]);
            if ($pl) {
                $A->call('PUT', 'api/admin_faktury.php', ['id' => $faA, 'polozky_zmeny' => [['polozka_id' => (int) $pl['id'], 'mnozstvi' => (float) $pl['mnozstvi']]]]);
                $c1 = (float) q1('SELECT castka_celkem FROM faktury WHERE id=:f', ['f' => $faA]);
                $rec('PR13', 'Ulozeni NEZMENENEHO mnozstvi radku auto-faktury (rucni=0)', "celkem $c0", "celkem $c1", abs($c1 - $c0) > 0.005);
            }
        }
        // PR14 plně dobropisovaná nezaplacená faktura → originál stav úhrady
        $ff = $A->call('POST', 'api/admin_objednavky_hromadne.php', ['action' => 'fa', 'objednavka_ids' => [$objB], 'datum_vystaveni' => $D], 'POST hromadne (fa)');
        $faB2 = (int) ($ff['json']['vytvoreno'][0]['faktura_id'] ?? (int) q1('SELECT fdl.faktura_id FROM faktury_dodaci_listy fdl JOIN dodaci_listy dl ON dl.id=fdl.dodaci_list_id WHERE dl.objednavka_id=:o LIMIT 1', ['o' => $objB]));
        if ($faB2) {
            $dob = $A->call('POST', 'api/admin_faktury.php?action=dobropis', ['faktura_id' => $faB2, 'duvod' => "$tag sonda", 'vynutit' => true, 'vratit_na_sklad' => false]);
            $cisloB = (string) q1('SELECT cislo FROM faktury WHERE id=:f', ['f' => $faB2]);
            $lst = $A->call('GET', 'api/admin_faktury.php?q=' . urlencode($cisloB));
            $stav = null; foreach (($lst['json']['faktury'] ?? []) as $f) if ((int) $f['id'] === $faB2) $stav = $f['stav_uhrady'];
            $rec('PR14', 'Plne dobropisovana, nezaplacena faktura — stav uhrady originalu', 'vyrovnana (ne cekajici/po splatnosti)', "dobropis HTTP {$dob['code']}, stav: " . ($stav ?? '?'), $stav !== null ? in_array($stav, ['cekajici', 'po_splatnosti'], true) : null);
        }
        // PR15 faktura z více DL — doba odezvy (plná záloha DB před každým voláním)
        [$o15, $c15] = $mk(15); $B15 = $b2b($c15); $ids15 = [];
        foreach ([10, 12] as $q15) { $ids15[] = (int) $order($B15, $o15, $q15)['json']['id']; }
        $hd = $A->call('POST', 'api/admin_objednavky_hromadne.php', ['action' => 'dl', 'objednavka_ids' => $ids15, 'datum_vystaveni_dl' => $D], 'POST hromadne (dl)');
        $dlids = array_map(fn($v) => (int) $v['dl_id'], $hd['json']['vytvoreno'] ?? []);
        $zd = $A->call('POST', 'api/admin_faktura_z_dl.php', ['dl_ids' => $dlids, 'datum_vystaveni' => $D]);
        $rec('PR15', 'Faktura ze 2 DL (admin_faktura_z_dl) — doba odezvy (pokazde plna zaloha DB)', '< 5 s', 'HTTP ' . $zd['code'] . ', ' . round($zd['ms']) . ' ms', $zd['ms'] > 5000);
        // PR16 objednávka do minulosti
        [$o17, $c17] = $mk(17); $B17 = $b2b($c17);
        $r = $order($B17, $o17, 10, ['datum_dodani' => date('Y-m-d', strtotime('-1 day'))]);
        $rec('PR16', 'B2B objednavka s datem dodani v minulosti', '400', 'HTTP ' . $r['code'], $r['code'] === 201);
        // PR17 klíče platby/dopravy s velkým písmenem (sanitizer maže velká písmena PŘED lowercase)
        $r = $order($B17, $o17, 10, ['doprava' => 'Rozvoz', 'platba' => 'Prevod']);
        $zp = q('SELECT zpusob_platby, zpusob_doruceni FROM objednavky WHERE id=:i', ['i' => (int) ($r['json']['id'] ?? 0)])[0] ?? [];
        $rec('PR17', 'Klice platby/dopravy s velkym pismenem („Prevod", „Rozvoz")', 'normalizovano na prevod/rozvoz', 'HTTP ' . $r['code'] . ', ulozeno: ' . json_encode($zp, JSON_UNESCAPED_UNICODE), !empty($zp) && (($zp['zpusob_platby'] ?? '') !== 'prevod' || ($zp['zpusob_doruceni'] ?? '') !== 'rozvoz'));
    } catch (Throwable $e) {
        $P[] = ['id' => 'ERR', 'title' => 'Vyjimka v sondach', 'expect' => '', 'actual' => $e->getMessage(), 'verdict' => 'CHYBA HARNESSU', 'detail' => $e->getTraceAsString()];
        L('  POZOR sondy: ' . $e->getMessage() . ' @' . $e->getLine());
    }
    jsave('probes.json', $P); Stats::save('stats-probes.json'); mark_done('probes', ['n' => count($P)]);
}

// ───────────────────────── report ─────────────────────────
function build_report(): void {
    $V = jload('verify.json', []); $P = jload('probes.json', []);
    $stats = [];
    foreach (glob(rpath('stats-*.json')) ?: [] as $f) foreach (json_decode((string) file_get_contents($f), true) ?: [] as $lab => $s) {
        if (!isset($stats[$lab])) $stats[$lab] = ['n' => 0, 'ms' => 0, 'codes' => [], 'samples' => []];
        $stats[$lab]['n'] += $s['n']; $stats[$lab]['ms'] += $s['ms'];
        foreach ($s['codes'] as $c => $n) $stats[$lab]['codes'][$c] = ($stats[$lab]['codes'][$c] ?? 0) + $n;
        $stats[$lab]['samples'] = array_merge($stats[$lab]['samples'], $s['samples']);
    }
    $pct = function (array $a, float $p) { if (!$a) return 0; sort($a); return $a[(int) min(count($a) - 1, floor(count($a) * $p))]; };
    $orders = all_orders();
    $o = '# Smoke-chain ' . cfg('run') . ' — ' . cfg('base') . "\n\n";
    $o .= '- Start: ' . cfg('started') . ' · konec: ' . date('c') . "\n";
    $o .= '- Odberatelu: ' . cfg('customers') . ' · objednavek: ' . count($orders) . ' (B2B ' . count(array_filter($orders, fn($x) => $x['via'] === 'b2b')) . ', admin ' . count(array_filter($orders, fn($x) => $x['via'] === 'admin')) . ', zruseno ' . count(array_filter($orders, fn($x) => $x['cancelled'])) . ', upraveno ' . count(array_filter($orders, fn($x) => $x['edited'])) . ")\n";
    $o .= '- Terminy dodani: ' . (cfg('dates')[0] ?? '?') . ' … ' . dates_last() . ' (' . count(cfg('dates')) . ' dni), sondy ' . cfg('probe_date') . "\n";
    $o .= '- HTTP pozadavku: ' . array_sum(array_column($stats, 'n')) . "\n\n";
    $fail = count(array_filter($V, fn($v) => !$v['ok'] && $v['sev'] === 'fail'));
    $warn = count(array_filter($V, fn($v) => !$v['ok'] && $v['sev'] === 'warn'));
    $o .= "## Souhrn\n\n- Invarianty: **" . (count($V) - $fail - $warn) . ' OK, ' . $fail . ' FAIL, ' . $warn . " WARN** z " . count($V) . "\n";
    $o .= '- Sondy: ' . count(array_filter($P, fn($p) => $p['verdict'] === 'CHYBA POTVRZENA')) . ' potvrzenych chyb, ' . count(array_filter($P, fn($p) => $p['verdict'] === 'OK (nereprodukovano)')) . " ok\n\n";
    $o .= "## Invarianty\n\n| | ID | Kontrola | Poruseni |\n|---|---|---|---|\n";
    foreach ($V as $v) $o .= '| ' . ($v['ok'] ? '✅' : ($v['sev'] === 'warn' ? '⚠️' : '❌')) . " | {$v['id']} | {$v['title']}" . ($v['note'] ? " <br><sub>{$v['note']}</sub>" : '') . " | {$v['n']} |\n";
    $o .= "\n### Vzorky poruseni\n";
    foreach ($V as $v) if (!$v['ok'] && $v['samples']) $o .= "\n**{$v['id']}** {$v['title']}\n```\n" . implode("\n", array_map(fn($s) => is_string($s) ? $s : json_encode($s, JSON_UNESCAPED_UNICODE), $v['samples'])) . "\n```\n";
    $o .= "\n## Sondy na podezrela mista\n\n| ID | Co | Cekano | Skutecnost | Verdikt |\n|---|---|---|---|---|\n";
    foreach ($P as $p) $o .= "| {$p['id']} | {$p['title']} | {$p['expect']} | " . str_replace('|', '/', (string) $p['actual']) . " | **{$p['verdict']}** |\n";
    $o .= "\n## Vykon (pred → po)\n\n| Obrazovka/endpoint | pred ms | po ms | po KB |\n|---|---|---|---|\n";
    $pb = jload('perf-before.json', []); $pa = jload('perf-after.json', []);
    foreach ($pa as $k => $x) $o .= "| $k | " . ($pb[$k]['median_ms'] ?? '–') . " | {$x['median_ms']} | {$x['kb']} |\n";
    $o .= "\n## HTTP podle endpointu\n\n| Endpoint | n | p50 ms | p95 ms | max ms | kody |\n|---|---|---|---|---|---|\n";
    uasort($stats, fn($a, $b) => $b['n'] <=> $a['n']);
    foreach ($stats as $lab => $s) if (!str_starts_with($lab, 'PERF')) $o .= "| $lab | {$s['n']} | " . $pct($s['samples'], 0.5) . ' | ' . $pct($s['samples'], 0.95) . ' | ' . ($s['samples'] ? max($s['samples']) : 0) . ' | ' . json_encode($s['codes']) . " |\n";
    file_put_contents(rpath('report.md'), $o);
    L('report: ' . rpath('report.md'));
}

if ($CMD === 'verify') exit(cli_verify());
if ($CMD === 'report') { app_db(); build_report(); exit(0); }
if ($CMD === 'probes') { app_db(); run_probes(); exit(0); }
