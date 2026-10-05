<?php
/**
 * 🧪 APPEK smoke-chain — orchestrátor zátěžového end-to-end testu „od receptu do rozvozu".
 *
 * N náhodných B2B odběratelů (každý s vlastním přihlašovacím účtem) projde přes SKUTEČNÉ HTTP
 * endpointy (auth, CSRF, session) celý řetězec:
 *   recept/BOM (+polotovary) → příjem surovin (systém B) → odběratel + B2B účet → objednávky
 *   přes B2B portál (část telefonicky přes admin) → potvrzení → výroba (plán, spotřeba, výrobní
 *   list, odpis, dávky polotovaru) → dodací listy → rozvozová trasa → expedováno/doručeno →
 *   faktury → úhrady → zákaznický portál → sondy na podezřelá místa → SQL invarianty.
 *
 * Běží na serveru (CLI) a volá web přes loopback (--resolve=127.0.0.1) → mimo WAF/CDN.
 * E-maily: na ne-lokálním hostu VYŽADUJE mail sink (api/.mail-sink, v3.0.556) — zapne na začátku,
 *   vypne na konci. Data se NEMAŽOU (pravidlo „test data narůstají"); vše označené SMK-<RUN>.
 *   Harness admin (smoke-harness+<run>@appek.invalid) se na konci deaktivuje.
 *
 * Použití:
 *   php run.php run --root=/.../demo --base=https://demo.appek.cz --resolve=127.0.0.1 \
 *       --customers=5000 --conc=10 --start=2026-11-02 --days=20 [--run=K5A] [--dir=~/appek-harness/runs]
 *   php verify.php verify --run=K5A --root=/.../demo --base=https://demo.appek.cz --resolve=127.0.0.1
 * Fáze jsou resumable (phase-*.done; workery přeskočí hotové) — po přerušení stačí `run` znovu se stejným --run.
 */
declare(strict_types=1);
require_once __DIR__ . '/phases.php';

function sink_guard(): void {
    if (!cfg('no_sink') && !is_local_base() && !is_file(cfg('root') . '/api/.mail-sink')) throw new RuntimeException('Mail sink zmizel — zastavuji (e-maily by se odesilaly)');
}

/** Spustí K workerů dané fáze jako samostatné procesy (php run.php worker …) a čeká na ně. */
function spawn_workers(string $phase, int $K): void {
    // Smaž staré worker-logy této fáze — jinak FATAL z dřívější iterace (append) zůstane
    // a detekce pádu by každou další iteraci chybně shodila už hotovou fázi.
    foreach (glob(rpath("worker-$phase-*.log")) ?: [] as $f) @unlink($f);
    $procs = [];
    for ($k = 0; $k < $K; $k++) {
        $cmd = [PHP_BINARY, '-d', 'memory_limit=1024M', __FILE__, 'worker', "--phase=$phase", "--shard=$k", "--of=$K",
                '--run=' . cfg('run'), '--dir=' . $GLOBALS['DIR_BASE']];
        $log = rpath("worker-$phase-$k.log");
        $procs[$k] = proc_open($cmd, [0 => ['file', '/dev/null', 'r'], 1 => ['file', $log, 'a'], 2 => ['file', $log, 'a']], $pipes);
        // Stagger startů: každý nový worker na první request spustí apply_full_schema (CREATE/ALTER);
        // N souběžných DDL bootstrapů koliduje na metadata-locku MariaDB a padá prázdným 500.
        if ($k < $K - 1) usleep(500000);
    }
    $t0 = time(); $last = 0;
    while (true) {
        $alive = 0;
        foreach ($procs as $p) if ($p && proc_get_status($p)['running']) $alive++;
        if (time() - $last >= 30 || $alive === 0) {
            $last = time(); $prog = 0;
            foreach (glob(rpath("$phase-*.jsonl")) ?: [] as $f) $prog += count(file($f));
            L("  [$phase] bezi $alive/$K workeru, hotovo $prog zaznamu, " . (time() - $t0) . ' s');
        }
        if ($alive === 0) break;
        sleep(3);
    }
    foreach ($procs as $p) if ($p) proc_close($p);
    $fatal = [];
    foreach (glob(rpath("worker-$phase-*.log")) ?: [] as $f) {
        $txt = (string) file_get_contents($f);
        if (preg_match_all('/^.*(FATAL|BREAKER).*$/m', $txt, $mm)) $fatal[] = basename($f) . ': ' . end($mm[0]);
    }
    if ($fatal) { foreach ($fatal as $x) L("  CHYBA: $x"); throw new RuntimeException("Faze $phase: " . count($fatal) . ' worker(u) spadlo — oprav a spust znovu (resume)'); }
}

/** Dispatch workera (volá se jako `php run.php worker --phase=… --shard=… --of=…`). */
function worker_main(): void {
    $phase = (string) opt('phase'); $k = (int) opt('shard'); $K = (int) opt('of');
    try {
        match ($phase) {
            'customers' => w_customers($k, $K),
            'confirm'   => w_stav($k, $K, 'confirm', ['potvrzena']),
            'deliver'   => w_stav($k, $K, 'deliver', ['expedovana', 'dorucena']),
            'pay'       => w_pay($k, $K),
            'portal'    => w_portal($k, $K),
            default     => throw new RuntimeException("nezname worker phase '$phase'"),
        };
    } catch (Throwable $e) {
        echo '[' . date('H:i:s') . "] FATAL $phase/$k: " . $e->getMessage() . ' @' . basename($e->getFile()) . ':' . $e->getLine() . "\n";
    }
    Stats::save("stats-$phase-$k.json");
    jsave("errors-$phase-$k.json", Http::$errors);
}

// ───────────────────────── server-side fáze (sekvenční, jeden admin) ─────────────────────────

/** Příjem surovin (systém B) + dávky stockovaných polotovarů tak, aby výroba měla z čeho odečítat. */
function phase_stock(): void {
    if (phase_done('stock')) { L('stock: hotovo driv'); return; }
    $A = new Admin();
    $need = []; $polNeed = []; $spot = [];
    foreach (cfg('dates') as $d) {
        $r = $A->call('GET', "api/admin_vyroba.php?action=spotreba&datum=$d");
        $spot[$d] = ['suroviny' => count($r['json']['suroviny'] ?? []), 'polotovary' => count($r['json']['polotovary'] ?? [])];
        foreach (($r['json']['suroviny'] ?? []) as $s) $need[(int) $s['surovina_id']] = ($need[(int) $s['surovina_id']] ?? 0) + (float) $s['potreba'];
        foreach (($r['json']['polotovary'] ?? []) as $p) $polNeed[(int) $p['vyrobek_id']] = ($polNeed[(int) $p['vyrobek_id']] ?? 0) + (float) $p['potreba'];
    }
    $batches = [];
    foreach ($polNeed as $pid => $qn) {
        $have = (float) q1("SELECT COALESCE(SUM(stav),0) FROM sklad_polozky WHERE item_typ='vyrobek' AND item_id=:i", ['i' => $pid]);
        $make = (float) ceil(max(0, $qn - $have) * 1.10) + 2; // 10 % rezerva proti driftu/zaokrouhleni
        if ($make <= 0) continue;
        // 2 dávky RŮZNÉ velikosti (shodná velikost ve stejné minutě → dedup guard 409)
        $b1 = max(1, (int) round($make * 0.4)); $b2 = (int) ($make - $b1);
        $batches[$pid] = array_values(array_filter([$b1, $b2], fn($x) => $x > 0));
        $s2 = []; $p2 = []; bom_x($pid, $make, $s2, $p2);
        foreach ($s2 as $sid => $qq) $need[$sid] = ($need[$sid] ?? 0) + $qq;
    }
    $rcv = []; $n = 0; mt_srand(crc32((string) cfg('run')));
    foreach ($need as $sid => $qn) {
        $cur = (float) q1('SELECT COALESCE(stock_aktualni,0) FROM suroviny WHERE id=:i', ['i' => $sid]);
        $want = $qn - $cur; if ($want <= 0) continue;
        $tot = round($want * (1.10 + mt_rand(0, 15) / 100) + 1, 3);
        $lots = $tot > 5000 ? 2 : 1; $first = round($tot * 0.6, 3);
        $cb = q('SELECT cena_baleni, obsah_baleni FROM suroviny WHERE id=:i', ['i' => $sid])[0] ?? [];
        $cj = (!empty($cb['cena_baleni']) && !empty($cb['obsah_baleni'])) ? round((float) $cb['cena_baleni'] / (float) $cb['obsah_baleni'], 4) : 0;
        for ($l = 1; $l <= $lots; $l++) {
            $mn = $lots === 1 ? $tot : ($l === 1 ? $first : round($tot - $first, 3));
            $pz = cfg('tag') . "-rcv-$sid-$l";
            // resume-safe: příjem NENÍ idempotentní → přeskoč, pokud poznámka už v ledgeru (po restartu orchestratoru)
            if ((int) q1("SELECT COUNT(*) FROM sklad_pohyby_v2 WHERE poznamka=:p", ['p' => $pz]) > 0) { $rcv[] = ['sid' => $sid, 'mn' => $mn, 'pz' => $pz, 'code' => 200, 'skip' => true]; continue; }
            $r = $A->call('POST', 'api/admin_suroviny.php?action=sklad_prijem', ['surovina_id' => $sid, 'mnozstvi' => $mn, 'cena_za_jed' => $cj,
                'sarze' => cfg('tag') . "-L$sid-$l", 'datum_spotreby' => date('Y-m-d', strtotime(dates_last() . ' +60 days')), 'poznamka' => $pz]);
            $rcv[] = ['sid' => $sid, 'mn' => $mn, 'pz' => $pz, 'code' => $r['code'], 'err' => $r['code'] >= 300 ? substr($r['raw'], 0, 200) : null];
            $n++;
        }
    }
    $made = [];
    foreach ($batches as $pid => $sizes) foreach ($sizes as $sz) {
        $r = $A->call('POST', 'api/admin_vyroba.php?action=vyrobit_polotovar', ['vyrobek_id' => $pid, 'mnozstvi' => $sz]);
        $made[] = ['pid' => $pid, 'mn' => $sz, 'code' => $r['code'], 'resp' => $r['json'] ?? substr($r['raw'], 0, 200)];
    }
    // Top-up: po dávkách ověř skutečný stav polotovaru a dorovnej (force), ať výroba neskončí v mínusu
    foreach ($polNeed as $pid => $qn) {
        $have = (float) q1("SELECT COALESCE(SUM(stav),0) FROM sklad_polozky WHERE item_typ='vyrobek' AND item_id=:i", ['i' => $pid]);
        $short = ceil($qn * 1.05 - $have);
        if ($short > 0) {
            $r = $A->call('POST', 'api/admin_vyroba.php?action=vyrobit_polotovar', ['vyrobek_id' => $pid, 'mnozstvi' => $short, 'force' => true]);
            $made[] = ['pid' => $pid, 'mn' => $short, 'topup' => true, 'code' => $r['code'], 'resp' => $r['json'] ?? substr($r['raw'], 0, 200)];
        }
    }
    jsave('stock.json', ['need' => $need, 'pol_need' => $polNeed, 'receipts' => $rcv, 'batches' => $made, 'spotreba_pred' => $spot]);
    Stats::save('stats-stock.json');
    $bad = count(array_filter($rcv, fn($x) => $x['code'] >= 300)) + count(array_filter($made, fn($x) => $x['code'] >= 300));
    mark_done('stock', ['receipts' => $n, 'batches' => count($made), 'chyby' => $bad]);
    L("stock: $n prijmu na " . count($need) . ' surovin, ' . count($made) . ' davek polotovaru' . ($bad ? ", POZOR $bad chyb" : ''));
}

/** Výroba per den: plán, spotřeba, výrobní list (koncept→hotovo), odpis surovin, kontrolní druhý odpis (má vrátit 409). */
function phase_production(): void {
    if (phase_done('production')) { L('production: hotovo driv'); return; }
    $A = new Admin(); $out = jload('production.json', []);
    foreach (cfg('dates') as $d) {
        if (isset($out[$d])) continue;
        $plan = $A->call('GET', "api/admin_vyroba.php?datum=$d");
        $polozky = []; foreach (($plan['json']['souhrn'] ?? []) as $s) $polozky[] = ['vyrobek_id' => (int) $s['id'], 'mnozstvi' => (float) $s['celkem']];
        $vl = $A->call('POST', 'api/admin_vyroba.php', ['datum_dodani' => $d, 'datum_vyroby' => date('Y-m-d', strtotime("$d -1 day")), 'stav' => 'koncept', 'poznamka' => cfg('tag'), 'polozky' => $polozky]);
        $od = $A->call('POST', 'api/admin_vyroba.php?action=odepsat_suroviny', ['datum' => $d, 'poznamka' => cfg('tag') . "-odpis-$d"]);
        $od2 = $A->call('POST', 'api/admin_vyroba.php?action=odepsat_suroviny', ['datum' => $d, 'poznamka' => cfg('tag') . "-odpis2-$d"]);
        if (!empty($vl['json']['id'])) $A->call('PUT', 'api/admin_vyroba.php', ['id' => (int) $vl['json']['id'], 'stav' => 'hotovo']);
        $out[$d] = ['plan_code' => $plan['code'], 'souhrn' => array_map(fn($s) => ['id' => (int) $s['id'], 'celkem' => (float) $s['celkem']], $plan['json']['souhrn'] ?? []),
                    'vyrobni_list' => $vl['code'], 'odpis' => ['code' => $od['code'], 'json' => $od['json'] ?? substr($od['raw'], 0, 300)],
                    'odpis_znovu' => ['code' => $od2['code'], 'msg' => substr($od2['raw'], 0, 200)]];
        jsave('production.json', $out);
        L("  production $d: plan {$plan['code']} (" . count($polozky) . " vyrobku), odpis {$od['code']}" . ($od['code'] !== 200 ? ' ' . substr($od['raw'], 0, 200) : '') . ", opakovany odpis {$od2['code']}");
    }
    Stats::save('stats-production.json'); mark_done('production');
}

/** Dodací listy hromadně (1 DL / objednávku), po dnech, dávky po 40. */
function phase_dl(): void {
    if (phase_done('dl')) { L('dl: hotovo driv'); return; }
    $A = new Admin(); $res = jload('dl.json', ['map' => [], 'skip' => [], 'err' => [], 'dates' => []]);
    $byDate = []; foreach (all_orders() as $o) if (!$o['cancelled']) $byDate[$o['datum']][] = $o['id'];
    ksort($byDate);
    foreach ($byDate as $d => $ids) {
        if (in_array($d, $res['dates'], true)) continue;
        foreach (array_chunk($ids, 40) as $ch) {
            sink_guard();
            $r = $A->call('POST', 'api/admin_objednavky_hromadne.php', ['action' => 'dl', 'objednavka_ids' => $ch, 'datum_vystaveni_dl' => $d], 'POST hromadne (dl)');
            foreach (($r['json']['vytvoreno'] ?? []) as $v) $res['map'][(string) $v['objednavka_id']] = (int) $v['dl_id'];
            foreach (($r['json']['preskoceno'] ?? []) as $v) $res['skip'][] = $v;
            if ($r['code'] !== 200) $res['err'][] = "$d {$r['code']}: " . substr($r['raw'], 0, 200);
        }
        $res['dates'][] = $d; jsave('dl.json', $res);
        L("  dl $d: " . count($ids) . ' objednavek');
    }
    Stats::save('stats-dl.json'); mark_done('dl', ['dl' => count($res['map']), 'err' => count($res['err'])]);
}

/** Rozvozová trasa per den (JSON endpoint + tiskový list). */
function phase_rozvoz(): void {
    if (phase_done('rozvoz')) { L('rozvoz: hotovo driv'); return; }
    $A = new Admin(); $out = [];
    foreach (cfg('dates') as $d) {
        $r = $A->call('GET', "api/admin_rozvozy.php?datum=$d");
        $ids = []; foreach (($r['json']['mesta'] ?? []) as $m) foreach ($m['dl'] as $dl) $ids[] = (int) $dl['id'];
        $p = $A->call('GET', "api/rozvoz_print.php?datum=$d", null, 'GET rozvoz_print.php', true);
        preg_match('/Celkem zast.vek:<\/strong>\s*(\d+)/u', $p['raw'], $mm);
        $out[$d] = ['code' => $r['code'], 'pocet_dl' => $r['json']['pocet_dl'] ?? null, 'pocet_mest' => $r['json']['pocet_mest'] ?? null,
                    'celkem_kc' => $r['json']['celkem_kc'] ?? null, 'dl_ids' => $ids, 'print_code' => $p['code'],
                    'print_zastavek' => isset($mm[1]) ? (int) $mm[1] : null, 'print_divu' => substr_count($p['raw'], 'class="zastavka"'), 'ms' => round($r['ms'])];
    }
    jsave('rozvoz.json', $out); Stats::save('stats-rozvoz.json'); mark_done('rozvoz');
    L('rozvoz: ' . count($out) . ' dni, ' . array_sum(array_map(fn($x) => count($x['dl_ids']), $out)) . ' zastavek');
}

/** Faktury hromadně: 35 % zákazníků měsíčně (1 faktura za víc objednávek), zbytek za každou objednávku. */
function phase_fa(): void {
    if (phase_done('fa')) { L('fa: hotovo driv'); return; }
    $A = new Admin(); $out = jload('fa.json', ['fa' => [], 'skip' => [], 'err' => []]);
    $out['err'] = []; // chyby počítej jen z tohoto průchodu
    // resume-safe: přeskoč objednávky, které už fakturu mají (po restartu orchestratoru)
    $T = cfg('tag');
    $invoiced = array_fill_keys(array_map('intval', array_column(q("SELECT DISTINCT dl.objednavka_id id FROM dodaci_listy dl JOIN faktury_dodaci_listy fdl ON fdl.dodaci_list_id=dl.id JOIN faktury f ON f.id=fdl.faktura_id AND f.je_dobropis=0 JOIN odberatele o ON o.id=dl.odberatel_id WHERE o.cislo LIKE '$T-C%' AND dl.objednavka_id IS NOT NULL"), 'id')), true);
    $haveFa = array_fill_keys(array_map(fn($x) => $x['id'], $out['fa']), true);
    $byCust = []; foreach (all_orders() as $o) if (!$o['cancelled'] && !isset($invoiced[$o['id']])) $byCust[$o['odb']][] = $o;
    if (!$byCust) { Stats::save('stats-fa.json'); mark_done('fa', ['fa' => count($out['fa'])]); L('fa: vse uz vyfakturovano (' . count($out['fa']) . ')'); return; }
    $monthly = []; $perOrder = [];
    foreach ($byCust as $cid => $os) { mt_srand((int) $cid * 31); if (mt_rand(1, 100) <= 35) $monthly[$cid] = $os; else foreach ($os as $o) $perOrder[$o['datum']][] = $o['id']; }
    ksort($perOrder);
    $call = function (array $ids, string $dv) use ($A, &$out) {
        sink_guard();
        $r = $A->call('POST', 'api/admin_objednavky_hromadne.php', ['action' => 'fa', 'objednavka_ids' => $ids, 'datum_vystaveni' => $dv], 'POST hromadne (fa)');
        foreach (($r['json']['vytvoreno'] ?? []) as $v) $out['fa'][] = ['id' => (int) $v['faktura_id'], 'odb' => (int) $v['odberatel_id'], 'n_obj' => (int) $v['pocet_objednavek'], 'celkem' => (float) $v['castka_celkem'], 'dv' => $dv];
        foreach (($r['json']['preskoceno'] ?? []) as $v) $out['skip'][] = $v;
        if ($r['code'] !== 200) $out['err'][] = "{$r['code']}: " . substr($r['raw'], 0, 200);
    };
    foreach ($perOrder as $d => $ids) foreach (array_chunk($ids, 40) as $ch) $call($ch, $d);
    $buf = [];
    foreach ($monthly as $os) { foreach ($os as $o) $buf[] = $o['id']; if (count($buf) >= 35) { $call($buf, cfg('month_end')); $buf = []; } }
    if ($buf) $call($buf, cfg('month_end'));
    $out['monthly_customers'] = array_keys($monthly);
    jsave('fa.json', $out); Stats::save('stats-fa.json'); mark_done('fa', ['fa' => count($out['fa'])]);
    L('fa: ' . count($out['fa']) . ' faktur (' . count($monthly) . ' zakazniku mesicne), preskoceno ' . count($out['skip']) . ', chyb ' . count($out['err']));
}

/** Výkon: medián doby odezvy těžkých obrazovek (před a po zátěži). */
function perf_snapshot(string $name): void {
    if (is_file(rpath("perf-$name.json"))) return;
    $A = new Admin(); $mid = cfg('dates')[(int) floor(count(cfg('dates')) / 2)]; $out = [];
    $eps = ['admin_odberatele (seznam)' => 'api/admin_odberatele.php', 'admin_objednavky?limit=10' => 'api/admin_objednavky.php?limit=10',
            'admin_faktury?limit=10' => 'api/admin_faktury.php?limit=10', 'admin_dodaci_listy?limit=10' => 'api/admin_dodaci_listy.php?limit=10',
            'admin_dashboard' => 'api/admin_dashboard.php', 'admin_vyroba?datum' => "api/admin_vyroba.php?datum=$mid",
            'admin_rozvozy?datum' => "api/admin_rozvozy.php?datum=$mid", 'admin_vyrobky' => 'api/admin_vyrobky.php',
            'integrity?audit' => 'api/admin_integrity.php?action=audit', 'katalog (anon)' => 'api/katalog.php'];
    foreach ($eps as $k => $p) {
        $ms = []; $code = 0; $bytes = 0;
        for ($t = 0; $t < 3; $t++) { $r = $A->call('GET', $p, null, "PERF $k", true); $ms[] = $r['ms']; $code = $r['code']; $bytes = strlen($r['raw']); }
        sort($ms); $out[$k] = ['code' => $code, 'median_ms' => round($ms[1]), 'kb' => round($bytes / 1024, 1)];
    }
    jsave("perf-$name.json", $out);
}

// ───────────────────────── preflight / bootstrap / teardown ─────────────────────────
function preflight(): void {
    app_db();
    $v = (new Http())->req('GET', 'api/version.php');
    L('cil: ' . cfg('base') . ' verze ' . ($v['json']['version'] ?? '?') . ' | DB: ' . q1('SELECT DATABASE()') . ' | zakazniku ' . cfg('customers') . ', workeru ' . cfg('conc'));
    if (!is_local_base() && !cfg('no_sink')) {
        $f = cfg('root') . '/api/.mail-sink';
        if (!is_file($f)) { touch($f); L('mail sink ZAPNUT (' . $f . ')'); }
        if (!function_exists('appek_mail_sink_active')) @require_once cfg('root') . '/api/_smtp_lib.php';
        if (!function_exists('appek_mail_sink_active') || !appek_mail_sink_active()) die("❌ Instalace nema mail sink (v3.0.556+) — e-maily by odchazely. Konec.\n");
    }
    $wh = (int) q1("SELECT COUNT(*) FROM webhooks WHERE aktivni=1");
    if ($wh > 0) L("POZOR: $wh aktivnich webhooku — budou se volat na kazdou objednavku (8s timeout). Zvaz vypnuti.");
    if (is_file(rpath('baseline.json'))) { L('baseline existuje (resume)'); return; }
    $all = array_merge(cfg('dates'), [cfg('probe_date')]);
    $in = implode(',', array_map(fn($d) => "'$d'", $all));
    $foreign = (int) q1("SELECT COUNT(*) FROM objednavky WHERE datum_dodani IN ($in)");
    $markers = (int) q1("SELECT COUNT(*) FROM nastaveni WHERE klic IN (" . implode(',', array_map(fn($d) => "'odpis_vyroba_$d'", $all)) . ')');
    if (($foreign || $markers) && !opt('force-dates')) die("❌ Terminy nejsou izolovane: $foreign cizich objednavek, $markers odpisovych markeru v oknu. Zvol jine --start nebo --force-dates.\n");
    $t = ['odberatele', 'objednavky', 'objednavky_polozky', 'dodaci_listy', 'faktury', 'sklad_pohyby_v2', 'objednavky_zmeny', 'prihlaseni_pokusy', 'notifications', 'vyrobky', 'suroviny'];
    // tolerantní vůči čerstvé instalaci (některé tabulky vznikají líně, nejsou v _full_schema → mohou chybět)
    $cnt = function (string $x) { try { return (int) q1("SELECT COUNT(*) FROM `$x`"); } catch (Throwable $e) { return -1; } };
    $maxid = function (string $x) { try { return (int) q1("SELECT COALESCE(MAX(id),0) FROM `$x`"); } catch (Throwable $e) { return 0; } };
    $snap = [
        'cislovani' => (function () { try { return q('SELECT typ, rok, posledni FROM cislovani'); } catch (Throwable $e) { return []; } })(),
        'counts' => array_combine($t, array_map($cnt, $t)),
        'max_id' => ['sklad_pohyby_v2' => $maxid('sklad_pohyby_v2'), 'objednavky_zmeny' => $maxid('objednavky_zmeny'), 'app_errors' => $maxid('app_errors')],
        'stock' => array_map('floatval', array_column(q('SELECT id, stock_aktualni FROM suroviny'), 'stock_aktualni', 'id')),
        'error_logs' => array_map(fn($f) => ['f' => $f, 'size' => filesize($f)], array_values(array_filter([cfg('root') . '/api/error_log', cfg('root') . '/error_log', cfg('root') . '/admin/error_log', cfg('root') . '/b2b/error_log'], 'is_file'))),
        'sink_lines' => is_file(cfg('root') . '/api/.mail-sink.log') ? count(file(cfg('root') . '/api/.mail-sink.log')) : 0,
        'time' => date('Y-m-d H:i:s'),
    ];
    jsave('baseline.json', $snap);
    L('baseline: ' . $snap['counts']['objednavky'] . ' objednavek, ' . $snap['counts']['odberatele'] . ' odberatelu, ' . count($snap['stock']) . ' surovin');
}
function bootstrap_admin(): void {
    $s = secret();
    q("INSERT INTO admin_users (email, jmeno, heslo_hash, role, aktivni) VALUES (:em, :jm, :h, 'admin', 1)
       ON DUPLICATE KEY UPDATE heslo_hash = VALUES(heslo_hash), aktivni = 1, role = 'admin'",
      ['em' => $s['admin_email'], 'jm' => 'Smoke harness ' . cfg('run'), 'h' => password_hash($s['admin_pw'], PASSWORD_DEFAULT)]);
    try { q('UPDATE admin_users SET pos_only = 0 WHERE email = :em', ['em' => $s['admin_email']]); } catch (Throwable $e) {}
    L('harness admin aktivni (' . $s['admin_email'] . ')');
}
function teardown(): void {
    try { q("UPDATE admin_users SET aktivni = 0 WHERE email = :em", ['em' => secret()['admin_email']]); L('harness admin deaktivovan'); } catch (Throwable $e) {}
    if (!is_local_base() && !cfg('no_sink') && !opt('keep-sink')) { @unlink(cfg('root') . '/api/.mail-sink'); L('mail sink VYPNUT'); }
}

// ───────────────────────── orchestrace ─────────────────────────
if ($CMD === 'worker') { worker_main(); exit(0); }
if ($CMD !== 'run') die("Pouziti: php run.php run --root=… --base=… [--resolve=127.0.0.1] [--customers=N] [--conc=K] [--start=YYYY-MM-DD] [--days=D] [--run=TAG]\n       php verify.php verify --run=TAG --root=… --base=…\n");

cfg(); $t0 = microtime(true);
try {
    preflight();
    bootstrap_admin();
    perf_snapshot('before');
    if (!is_file(rpath('integrity-before.json'))) jsave('integrity-before.json', (new Admin())->call('GET', 'api/admin_integrity.php?action=audit')['json']);
    phase_catalog();
    if (!phase_done('customers')) { L('customers: start (' . cfg('customers') . ' zakazniku, ' . cfg('conc') . ' workeru)'); spawn_workers('customers', (int) cfg('conc')); mark_done('customers'); }
    L('customers: ' . count(jlines('customers-*.jsonl')) . ' odberatelu, ' . count(all_orders()) . ' objednavek');
    if (!phase_done('confirm')) { spawn_workers('confirm', (int) cfg('conc')); mark_done('confirm'); L('confirm: hotovo'); }
    phase_stock();
    phase_production();
    phase_dl();
    phase_rozvoz();
    if (!phase_done('deliver')) { spawn_workers('deliver', (int) cfg('conc')); mark_done('deliver'); L('deliver: hotovo (expedovana+dorucena)'); }
    phase_fa();
    if (!phase_done('pay')) { spawn_workers('pay', (int) cfg('conc')); mark_done('pay'); L('pay: hotovo'); }
    if (!phase_done('portal')) { spawn_workers('portal', min(4, (int) cfg('conc'))); mark_done('portal'); L('portal: hotovo'); }
    if (!cfg('skip_probes') && !phase_done('probes')) { require_once __DIR__ . '/verify.php'; run_probes(); }
    perf_snapshot('after');
    jsave('integrity-after.json', (new Admin())->call('GET', 'api/admin_integrity.php?action=audit')['json']);
    Stats::save('stats-main.json'); jsave('errors-main.json', Http::$errors);
    require_once __DIR__ . '/verify.php';
    $V = run_verify(); mark_done('verify');
    build_report();
    teardown();
    $bad = count(array_filter($V, fn($v) => !$v['ok'] && $v['sev'] === 'fail'));
    L(sprintf('HOTOVO za %.1f min — invarianty %d/%d OK (%d fail), report %s', (microtime(true) - $t0) / 60, count($V) - $bad, count($V), $bad, rpath('report.md')));
    exit($bad ? 1 : 0);
} catch (Throwable $e) {
    L('❌ PRERUSENO: ' . $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine());
    Stats::save('stats-main.json'); jsave('errors-main.json', Http::$errors);
    teardown();
    exit(2);
}
