<?php
/**
 * 🧪 APPEK — NÁHODNÝ test SESTAV (víceúrovňový BOM), VÝROBY a KUCHYNĚ (KDS) přes reálné HTTP.
 *
 * Doplněk smoke-chainu (run.php): ten pokrývá pevný katalog V1–V8 a kuchyň vůbec. Tady se pokaždé
 * vygeneruje JINÝ náhodný strom receptů (seed v logu → reprodukovatelné) a ověří se nezávislou replikou:
 *
 *   A) SESTAVY   — rozpad na suroviny (vč. převodu jednotek kg↔g / l↔ml), škálování, ochrana proti cyklu,
 *                  výroba dávky stockovaného polotovaru (odpis surovin + naskladnění), dvojklik guard
 *   B) VÝROBA    — plán (spotreba) na izolovaný den = součet objednávek × rozpad; odpis surovin jen 1×
 *   C) KUCHYNĚ   — POS účet → fronta kuchyně (admin_kitchen) i KDS (admin_pos?action=kds) vidí totéž,
 *                  stanice + doba přípravy z výrobku, stavy queued→preparing→ready→served, storno a útrata,
 *                  zaplacený účet už nejde měnit, s sebou → kitchen_queue
 *
 * Použití (na serveru, jako run.php):
 *   php kitchen_bom.php run --root=/…/demo --base=https://demo.appek.cz --resolve=127.0.0.1 --run=KB1 [--seed=N] --no-sink
 * Data se NEMAŽOU (pravidlo „test data narůstají"); vše má značku SMK-<RUN>. Testovací stůl se na konci vypne.
 */
declare(strict_types=1);
require_once __DIR__ . '/lib.php';

if ($CMD !== 'run') { echo "Použití: php kitchen_bom.php run --root=… --base=… [--resolve=127.0.0.1] [--run=KB1] [--seed=N] --no-sink\n"; exit(1); }

$SEED = (int) opt('seed', random_int(1, 999999));
mt_srand($SEED);
$TAG = cfg('tag');
$R = [];   // výsledky: [id, title, ok(true/false/null), detail]
function rec(string $id, string $title, ?bool $ok, $detail = null): void {
    global $R;
    $R[] = ['id' => $id, 'title' => $title, 'ok' => $ok, 'detail' => $detail];
    L(sprintf('  %-4s %s — %s%s', $id, $ok === true ? 'ok   ' : ($ok === false ? 'CHYBA' : '?    '), $title,
        $detail !== null && $ok !== true ? ' → ' . (is_string($detail) ? $detail : json_encode($detail, JSON_UNESCAPED_UNICODE)) : ''));
}
function near(float $a, float $b, float $eps = 0.002): bool { return abs($a - $b) <= max($eps, abs($b) * 1e-6); }
function rnd(float $a, float $b, int $dec = 2): float { return round($a + (mt_rand() / mt_getrandmax()) * ($b - $a), $dec); }

// převod jednotek — STEJNÁ pravidla jako produkce (bom_unit_factor), ale vlastní implementace
function kb_factor(string $from, string $to): float {
    $b = ['g' => ['m', 1], 'kg' => ['m', 1000], 'ml' => ['v', 1], 'l' => ['v', 1000], 'ks' => ['k', 1]];
    if ($from === $to || !isset($b[$from], $b[$to]) || $b[$from][0] !== $b[$to][0]) return 1.0;
    return $b[$from][1] / $b[$to][1];
}

// ── bootstrap: harness admin (jako run.php) ──
app_db();
$s = secret();
q("INSERT INTO admin_users (email, jmeno, heslo_hash, role, aktivni) VALUES (:em, :jm, :h, 'admin', 1)
   ON DUPLICATE KEY UPDATE heslo_hash = VALUES(heslo_hash), aktivni = 1, role = 'admin'",
  ['em' => $s['admin_email'], 'jm' => 'KB harness ' . cfg('run'), 'h' => password_hash($s['admin_pw'], PASSWORD_DEFAULT)]);
try { q('UPDATE admin_users SET pos_only = 0 WHERE email = :em', ['em' => $s['admin_email']]); } catch (Throwable $e) {}
$v = (new Http())->req('GET', 'api/version.php');
L('cil: ' . cfg('base') . ' verze ' . ($v['json']['version'] ?? '?') . ' | DB ' . q1('SELECT DATABASE()') . " | seed $SEED | tag $TAG");
$A = new Admin();
$tableId = 0;

try {
// ═══════════════════════════ A) SESTAVY ═══════════════════════════
L('A) sestavy — náhodný strom receptů');
$ref = $A->call('GET', 'api/admin_vyrobky.php');
$jedKs = 1; foreach ($ref['json']['jednotky'] ?? [] as $j) if ($j['kod'] === 'ks') $jedKs = (int) $j['id'];
$sazba = (int) (($ref['json']['sazby'][0]['id'] ?? 1));

// suroviny: náhodné jednotky + ceny
$SUR = [];
$units = ['g', 'g', 'ml', 'ks', 'g'];
$nSur = mt_rand(5, 8);
for ($i = 1; $i <= $nSur; $i++) {
    $u = $units[array_rand($units)];
    $ob = $u === 'ks' ? (float) mt_rand(10, 60) : (float) mt_rand(500, 5000);
    $cb = rnd(20, 400);
    $r = $A->call('POST', 'api/admin_suroviny.php', ['nazev' => "$TAG surovina $i ($u)", 'jednotka' => $u, 'cena_baleni' => $cb, 'obsah_baleni' => $ob, 'stock_minimalni' => 0, 'stock_cilove' => 0]);
    if ($r['code'] !== 201) throw new RuntimeException("surovina $i: {$r['code']} " . substr($r['raw'], 0, 160));
    $SUR[(int) $r['json']['id']] = ['jednotka' => $u, 'unit_cost' => $cb / $ob, 'nazev' => "$TAG surovina $i ($u)"];
}
$sids = array_keys($SUR);

// řádek receptu se surovinou — občas v „velké" jednotce (kg/l) kvůli převodu
$surRow = function (int $sid) use ($SUR): array {
    $u = $SUR[$sid]['jednotka'];
    if ($u === 'g' && mt_rand(0, 2) === 0)  return ['surovina_id' => $sid, 'mnozstvi' => rnd(0.01, 0.4, 3), 'jednotka' => 'kg'];
    if ($u === 'ml' && mt_rand(0, 2) === 0) return ['surovina_id' => $sid, 'mnozstvi' => rnd(0.01, 0.3, 3), 'jednotka' => 'l'];
    return ['surovina_id' => $sid, 'mnozstvi' => $u === 'ks' ? (float) mt_rand(1, 3) : rnd(5, 250, 1), 'jednotka' => $u];
};

$VYR = []; // id => [nazev, rows, jp, ss]
$mkVyr = function (string $nazev, array $rows, int $jp, int $ss, float $cena) use ($A, $TAG, $jedKs, $sazba, &$VYR): int {
    $por = 0; foreach ($rows as &$x) $x['poradi'] = $por++; unset($x);
    $body = ['nazev' => $nazev, 'cislo' => $TAG . '-' . substr(md5($nazev), 0, 6), 'jednotka_id' => $jedKs, 'sazba_dph_id' => $sazba,
             'cena_bez_dph' => $cena, 'aktivni' => $jp ? 0 : 1, 'min_objednavka' => 1, 'je_polotovar' => $jp, 'sleduje_sklad' => $ss, 'slozeni_polozky' => $rows];
    $r = $A->call('POST', 'api/admin_vyrobky.php', $body);
    if ($r['code'] !== 201) throw new RuntimeException("vyrobek $nazev: {$r['code']} " . substr($r['raw'], 0, 160));
    $id = (int) $r['json']['id'];
    if ($jp || $ss) $A->call('PUT', 'api/admin_vyrobky.php', ['id' => $id, 'je_polotovar' => $jp, 'sleduje_sklad' => $ss]);
    $VYR[$id] = ['nazev' => $nazev, 'rows' => $rows, 'jp' => $jp, 'ss' => $ss];
    return $id;
};
$pickSur = function (int $n) use ($sids, $surRow): array { $k = (array) array_rand(array_flip($sids), min($n, count($sids))); return array_map($surRow, $k); };

// polotovary: P1 (jen suroviny) → P2 (P1 + suroviny) → P3 (P2 + suroviny, STOCKOVANÝ)
$P1 = $mkVyr("$TAG polotovar P1", $pickSur(mt_rand(2, 3)), 1, 0, 0);
$P2 = $mkVyr("$TAG polotovar P2", array_merge([['slozka_vyrobek_id' => $P1, 'mnozstvi' => rnd(0.1, 0.9, 3), 'jednotka' => 'ks']], $pickSur(mt_rand(1, 2))), 1, 0, 0);
$P3 = $mkVyr("$TAG polotovar P3 (sklad)", array_merge([['slozka_vyrobek_id' => $P2, 'mnozstvi' => rnd(0.2, 1.5, 3), 'jednotka' => 'ks']], $pickSur(1)), 1, 1, 0);
// finální výrobky: náhodný mix surovin a polotovarů
$FIN = [];
$nFin = mt_rand(3, 5);
for ($i = 1; $i <= $nFin; $i++) {
    $rows = $pickSur(mt_rand(1, 3));
    foreach ([$P1, $P2, $P3] as $p) if (mt_rand(0, 2) === 0) $rows[] = ['slozka_vyrobek_id' => $p, 'mnozstvi' => rnd(0.05, 1.2, 3), 'jednotka' => 'ks'];
    $FIN[] = $mkVyr("$TAG výrobek F$i", $rows, 0, 0, rnd(20, 180));
}
L('  ' . count($SUR) . ' surovin, polotovary P1→P2→P3(sklad), ' . count($FIN) . ' výrobků');

// nezávislá replika rozpadu (suroviny v jednotce suroviny; stockované polotovary jako leaf)
$explode = function (int $vid, float $qty, array &$sur, array &$pol, array $path = []) use (&$explode, &$VYR, $SUR): void {
    if ($qty <= 0 || isset($path[$vid])) return; $path[$vid] = 1;
    foreach ($VYR[$vid]['rows'] as $r) {
        $need = $qty * (float) $r['mnozstvi'];
        if (!empty($r['surovina_id'])) { $sid = (int) $r['surovina_id']; $sur[$sid] = ($sur[$sid] ?? 0) + $need * kb_factor($r['jednotka'], $SUR[$sid]['jednotka']); }
        else { $sv = (int) $r['slozka_vyrobek_id']; if ($VYR[$sv]['ss']) $pol[$sv] = ($pol[$sv] ?? 0) + $need; else $explode($sv, $need, $sur, $pol, $path); }
    }
};

// A1: recept uložený tak, jak poslaný (počet řádků)
$bad = [];
foreach ($VYR as $id => $v) {
    $db = q('SELECT surovina_id, slozka_vyrobek_id, mnozstvi, jednotka FROM vyrobek_suroviny WHERE vyrobek_id = :v', ['v' => $id]);
    if (count($db) !== count($v['rows'])) $bad[] = "{$v['nazev']}: řádků " . count($db) . ' ≠ ' . count($v['rows']);
}
rec('A1', 'Recepty uložené kompletně (vč. vnořených polotovarů)', !$bad, $bad ?: null);

// A2: škálování (per kus) = replika, vč. převodu jednotek
$bad = []; $checked = 0;
foreach (array_merge($FIN, [$P3]) as $vid) {
    $sur = []; $pol = []; $explode($vid, 1, $sur, $pol);
    $sk = $A->call('GET', "api/admin_vyroba.php?action=skalovat&vyrobek_id=$vid&mnozstvi=1");
    // názvy surovin backend normalizuje (Velká Písmena) → páruj bez ohledu na velikost
    $got = []; foreach (($sk['json']['polozky'] ?? []) as $p) $got[mb_strtolower($p['nazev'])] = (float) $p['per_kus'];
    foreach ($sur as $sid => $per) { $checked++; $g = $got[mb_strtolower($SUR[$sid]['nazev'])] ?? null; if ($g === null || !near($g, $per, 0.0002 + $per * 0.001)) $bad[] = "{$VYR[$vid]['nazev']} / {$SUR[$sid]['nazev']}: API " . var_export($g, true) . ' vs ' . round($per, 4); }
    foreach ($pol as $pv => $per) { $checked++; $g = $got[mb_strtolower($VYR[$pv]['nazev'] . ' (polotovar)')] ?? null; if ($g === null || !near($g, $per, 0.0002 + $per * 0.001)) $bad[] = "{$VYR[$vid]['nazev']} / {$VYR[$pv]['nazev']}: API " . var_export($g, true) . ' vs ' . round($per, 4); }
}
rec('A2', "Škálování receptu = nezávislá replika rozpadu ($checked kontrol, převod kg↔g / l↔ml)", !$bad, $bad ? array_slice($bad, 0, 6) : null);

// A3: ochrana proti cyklu — P1 dostane do receptu P2 (který P1 obsahuje)
$rows1 = $VYR[$P1]['rows']; $rows1[] = ['slozka_vyrobek_id' => $P2, 'mnozstvi' => 1, 'jednotka' => 'ks', 'poradi' => 99];
$rc = $A->call('PUT', 'api/admin_vyrobky.php', ['id' => $P1, 'slozeni_polozky' => $rows1]);
$hasCycle = (int) q1('SELECT COUNT(*) FROM vyrobek_suroviny WHERE vyrobek_id = :v AND slozka_vyrobek_id = :s', ['v' => $P1, 's' => $P2]);
rec('A3', 'Cyklus v receptu (P1 ⊃ P2 ⊃ P1) se neuloží', $hasCycle === 0, "PUT {$rc['code']}, cyklický řádek v DB: $hasCycle");
$cntAfter = (int) q1('SELECT COUNT(*) FROM vyrobek_suroviny WHERE vyrobek_id = :v', ['v' => $P1]);
rec('A3b', 'Uživatel se dozví, že cyklická složka byla zahozena (ne tiché 200)', $rc['code'] >= 400 || !empty($rc['json']['warnings']) || !empty($rc['json']['skipped']),
    "PUT {$rc['code']} " . substr($rc['raw'], 0, 120) . " · řádků po uložení $cntAfter (posláno " . count($rows1) . ')');
// vrať recept P1 do původního stavu (bez cyklu) — zbytek testu počítá s původním
$A->call('PUT', 'api/admin_vyrobky.php', ['id' => $P1, 'slozeni_polozky' => $VYR[$P1]['rows']]);

// A4: výroba dávky stockovaného P3 → odpis surovin = replika, naskladnění P3
$qty = (float) mt_rand(3, 12);
$sur = []; $pol = []; $explode($P3, $qty, $sur, $pol);
$stockBefore = []; foreach ($sids as $sid) $stockBefore[$sid] = (float) q1('SELECT COALESCE(stock_aktualni,0) FROM suroviny WHERE id = :i', ['i' => $sid]);
$p3Before = (float) q1("SELECT COALESCE(SUM(stav),0) FROM sklad_polozky WHERE item_typ='vyrobek' AND item_id = :i", ['i' => $P3]);
$r0 = $A->call('POST', 'api/admin_vyroba.php?action=vyrobit_polotovar', ['vyrobek_id' => $P3, 'mnozstvi' => $qty]);
rec('A4a', 'Výroba dávky bez zásob surovin → 409 s výpisem chybějících', $r0['code'] === 409 && !empty($r0['json']['deficity']), "HTTP {$r0['code']}");
sleep(61);   // dvojklik guard drží minutu — nová dávka (force) musí dostat nový klíč
$r1 = $A->call('POST', 'api/admin_vyroba.php?action=vyrobit_polotovar', ['vyrobek_id' => $P3, 'mnozstvi' => $qty, 'force' => true]);
$bad = [];
foreach ($sur as $sid => $need) {
    $after = (float) q1('SELECT COALESCE(stock_aktualni,0) FROM suroviny WHERE id = :i', ['i' => $sid]);
    if (!near($stockBefore[$sid] - $after, $need, 0.01)) $bad[] = "{$SUR[$sid]['nazev']}: odepsáno " . round($stockBefore[$sid] - $after, 3) . ' vs ' . round($need, 3);
}
$p3After = (float) q1("SELECT COALESCE(SUM(stav),0) FROM sklad_polozky WHERE item_typ='vyrobek' AND item_id = :i", ['i' => $P3]);
if (!near($p3After - $p3Before, $qty)) $bad[] = "P3 naskladněno " . ($p3After - $p3Before) . " vs $qty";
rec('A4b', "Výroba $qty ks P3 (force): odpis surovin přes P2→P1 = replika, P3 naskladněn", $r1['code'] === 200 && !$bad, $bad ?: "HTTP {$r1['code']}");
$r2 = $A->call('POST', 'api/admin_vyroba.php?action=vyrobit_polotovar', ['vyrobek_id' => $P3, 'mnozstvi' => $qty]);
$p3After2 = (float) q1("SELECT COALESCE(SUM(stav),0) FROM sklad_polozky WHERE item_typ='vyrobek' AND item_id = :i", ['i' => $P3]);
rec('A4c', 'Okamžité opakování stejné dávky → odmítnuto (žádný druhý odpis)', $r2['code'] === 409 && near($p3After2, $p3After), "HTTP {$r2['code']}, P3 $p3After → $p3After2");

// ═══════════════════════════ B) VÝROBA ═══════════════════════════
L('B) výroba — plán na izolovaný den');
$day = null;
for ($t = 0; $t < 40 && !$day; $t++) {
    $d = date('Y-m-d', strtotime('2029-01-01 +' . mt_rand(0, 360) . ' days'));
    if ((int) date('N', strtotime($d)) > 5) continue;
    if ((int) q1('SELECT COUNT(*) FROM objednavky WHERE datum_dodani = :d', ['d' => $d]) === 0
        && (int) q1("SELECT COUNT(*) FROM nastaveni WHERE klic = :k", ['k' => 'odpis_vyroba_' . $d]) === 0) $day = $d;
}
$odb = q("SELECT o.id, (SELECT m.id FROM mista_dodani m WHERE m.odberatel_id = o.id ORDER BY m.id LIMIT 1) AS misto
          FROM odberatele o WHERE o.cislo LIKE 'SMK-%' AND COALESCE(o.blokovan,0) = 0 ORDER BY o.id DESC LIMIT 5");
if (!$day || !$odb) {
    rec('B0', 'Příprava výroby (volný den + testovací odběratel)', null, 'nelze — den ' . var_export($day, true) . ', odběratelů ' . count($odb));
} else {
    $expSur = []; $expPol = []; $nOrd = 0;
    foreach ($odb as $o) {
        $pol = []; foreach ((array) array_rand(array_flip($FIN), min(count($FIN), mt_rand(1, 3))) as $vid) $pol[] = ['vyrobek_id' => (int) $vid, 'mnozstvi' => (float) mt_rand(2, 25)];
        $ro = $A->call('POST', 'api/admin_objednavky.php', ['action' => 'vytvorit', 'odberatel_id' => (int) $o['id'], 'misto_dodani_id' => $o['misto'] ? (int) $o['misto'] : null,
                                                            'datum_dodani' => $day, 'polozky' => $pol, 'interni_pozn' => "$TAG výroba"]);
        if ($ro['code'] !== 201) { rec('B0', 'Založení objednávky pro výrobu', false, "HTTP {$ro['code']} " . substr($ro['raw'], 0, 120)); continue; }
        $nOrd++;
        foreach ($pol as $p) $explode($p['vyrobek_id'], $p['mnozstvi'], $expSur, $expPol);
    }
    $sp = $A->call('GET', "api/admin_vyroba.php?action=spotreba&datum=$day");
    $got = []; foreach (($sp['json']['suroviny'] ?? []) as $r) $got[(int) $r['surovina_id']] = (float) $r['potreba'];
    $gotPol = []; foreach (($sp['json']['polotovary'] ?? []) as $p) $gotPol[(int) $p['vyrobek_id']] = (float) ($p['potreba'] ?? $p['mnozstvi'] ?? 0);
    $bad = [];
    foreach ($expSur as $sid => $need) if (!near($got[$sid] ?? -1, $need, 0.01)) $bad[] = "{$SUR[$sid]['nazev']}: plán " . var_export($got[$sid] ?? null, true) . ' vs ' . round($need, 3);
    foreach ($got as $sid => $g) if (isset($SUR[$sid]) && !isset($expSur[$sid])) $bad[] = "navíc {$SUR[$sid]['nazev']} $g";
    foreach ($expPol as $pv => $need) if (!near($gotPol[$pv] ?? -1, $need, 0.01)) $bad[] = "polotovar {$VYR[$pv]['nazev']}: plán " . var_export($gotPol[$pv] ?? null, true) . ' vs ' . round($need, 3);
    rec('B1', "Plán výroby na $day ($nOrd objednávek) = součet objednávek × rozpad sestav", $sp['code'] === 200 && $nOrd > 0 && !$bad, $bad ? array_slice($bad, 0, 6) : "HTTP {$sp['code']}");

    // B2a: bez zásob → 409 a den NESMÍ zůstat zablokovaný (🐛 v3.0.598 — marker „PROBÍHÁ" visel)
    $o0 = $A->call('POST', 'api/admin_vyroba.php?action=odepsat_suroviny', ['datum' => $day]);
    $mark = (string) (q1('SELECT hodnota FROM nastaveni WHERE klic = :k', ['k' => 'odpis_vyroba_' . $day]) ?: '');
    rec('B2a', 'Odpis bez zásob → 409 s deficity a den zůstane volný pro opakování', $o0['code'] === 409 && !empty($o0['json']['deficity']) && $mark === '',
        "HTTP {$o0['code']}, marker '" . $mark . "'");
    // B2b: s force odepíše přesně plán
    $before = []; foreach (array_keys($expSur) as $sid) $before[$sid] = (float) q1('SELECT COALESCE(stock_aktualni,0) FROM suroviny WHERE id = :i', ['i' => $sid]);
    $o1 = $A->call('POST', 'api/admin_vyroba.php?action=odepsat_suroviny', ['datum' => $day, 'force' => true]);
    $bad = [];
    foreach ($expSur as $sid => $need) {
        $after = (float) q1('SELECT COALESCE(stock_aktualni,0) FROM suroviny WHERE id = :i', ['i' => $sid]);
        if (!near($before[$sid] - $after, $need, 0.01)) $bad[] = "{$SUR[$sid]['nazev']}: odepsáno " . round($before[$sid] - $after, 3) . ' vs ' . round($need, 3);
    }
    rec('B2b', 'Odpis surovin za den (force) = plán', in_array($o1['code'], [200, 201], true) && !$bad, $bad ?: "HTTP {$o1['code']} " . substr($o1['raw'], 0, 100));
    $o2 = $A->call('POST', 'api/admin_vyroba.php?action=odepsat_suroviny', ['datum' => $day]);
    rec('B3', 'Druhý odpis téhož dne → 409 (sklad neklesne podruhé)', $o2['code'] === 409, "HTTP {$o2['code']}");
}

// ═══════════════════════════ C) KUCHYNĚ ═══════════════════════════
L('C) kuchyně — POS účet → fronta / KDS');
$k0 = $A->call('GET', 'api/admin_kitchen.php');
if ($k0['code'] === 402) { rec('C0', 'Balíček Restaurace aktivní', null, '402 — kuchyň nelze testovat'); throw new RuntimeException('skip-kitchen'); }
$stations = array_map(fn($s) => (int) $s['id'], $k0['json']['stanice'] ?? []);
rec('C0', 'Kuchyň dostupná (stanice: ' . count($stations) . ')', $k0['code'] === 200 && $stations, "HTTP {$k0['code']}");
// výrobkům náhodná stanice + doba přípravy
$KIT = [];
foreach ($FIN as $vid) {
    $st = $stations[array_rand($stations)]; $pm = mt_rand(3, 25);
    $A->call('PUT', 'api/admin_vyrobky.php', ['id' => $vid, 'kitchen_station_id' => $st, 'priprava_min' => $pm]);
    $KIT[$vid] = ['st' => $st, 'pm' => $pm];
}
$rt = $A->call('POST', 'api/admin_tables.php', ['nazev' => "$TAG stůl", 'mist' => 4, 'x' => 0, 'y' => 0]);
$tableId = (int) ($rt['json']['id'] ?? 0);
if (!$tableId) $tableId = (int) q1('SELECT id FROM restaurant_tables WHERE nazev = :n ORDER BY id DESC LIMIT 1', ['n' => "$TAG stůl"]);
$u = $A->call('GET', "api/admin_pos.php?action=ucet&stul_id=$tableId");
$ucetId = (int) ($u['json']['id'] ?? 0);
rec('C1', 'Otevření účtu na testovacím stole', $ucetId > 0, "stůl $tableId, HTTP {$u['code']}");
$items = []; $sumExp = 0.0;
foreach ($FIN as $vid) {
    $mn = (float) mt_rand(1, 3);
    $ri = $A->call('POST', 'api/admin_pos.php?action=item', ['ucet_id' => $ucetId, 'vyrobek_id' => $vid, 'mnozstvi' => $mn]);
    if (!empty($ri['json']['id'])) {
        $cena = (float) q1('SELECT jednotkova_cena FROM restaurant_pos_polozky WHERE id = :i', ['i' => (int) $ri['json']['id']]);
        $items[(int) $ri['json']['id']] = ['vid' => $vid, 'mn' => $mn, 'cena' => $cena]; $sumExp += $cena * $mn;
    }
}
$suma = (float) q1('SELECT suma_kc FROM restaurant_pos_ucty WHERE id = :u', ['u' => $ucetId]);
rec('C2', count($items) . ' položek na účtu, útrata účtu = součet položek', count($items) === count($FIN) && near($suma, $sumExp, 0.01), "suma_kc $suma vs " . round($sumExp, 2));

$queue = function () use ($A): array { $r = $A->call('GET', 'api/admin_kitchen.php'); $m = []; foreach (($r['json']['queue'] ?? []) as $q) $m[$q['src'] . ':' . $q['id']] = $q; return $m; };
$kds = function () use ($A, &$ucetId): array { $r = $A->call('GET', 'api/admin_pos.php?action=kds'); $m = []; foreach (($r['json']['orders'] ?? []) as $o) if ((int) $o['ucet_id'] === $ucetId) foreach ($o['polozky'] as $p) $m[(int) $p['id']] = $p; return $m; };
$Q = $queue(); $K = $kds();
$bad = [];
foreach ($items as $iid => $it) {
    $q = $Q["pos:$iid"] ?? null;
    if (!$q) { $bad[] = "položka $iid chybí ve frontě kuchyně"; continue; }
    if ((int) $q['station_id'] !== $KIT[$it['vid']]['st']) $bad[] = "položka $iid stanice {$q['station_id']} vs {$KIT[$it['vid']]['st']}";
    if ((int) $q['priprava_min'] !== $KIT[$it['vid']]['pm']) $bad[] = "položka $iid příprava {$q['priprava_min']} vs {$KIT[$it['vid']]['pm']}";
    if ($q['stav'] !== 'queued') $bad[] = "položka $iid stav {$q['stav']}";
    if (!isset($K[$iid])) $bad[] = "položka $iid chybí v KDS";
}
rec('C3', 'Fronta kuchyně i KDS vidí všechny položky (stanice + doba přípravy z výrobku)', !$bad, $bad ?: null);

// C4: stavy přes obrazovku kuchyně, kontrola v KDS
$ids = array_keys($items);
$iA = $ids[0];
$bad = [];
foreach (['preparing' => 'vari_se', 'ready' => 'hotovo'] as $kst => $posSt) {
    $A->call('POST', 'api/admin_kitchen.php?action=order_status', ['id' => $iA, 'stav' => $kst, 'src' => 'pos']);
    $K = $kds(); $Q = $queue();
    if (($K[$iA]['stav'] ?? null) !== $posSt) $bad[] = "po '$kst' KDS stav " . var_export($K[$iA]['stav'] ?? null, true);
    if (($Q["pos:$iA"]['stav'] ?? null) !== $kst) $bad[] = "po '$kst' fronta stav " . var_export($Q["pos:$iA"]['stav'] ?? null, true);
}
$tm = q('SELECT cas_vari_se, cas_pripraveno FROM restaurant_pos_polozky WHERE id = :i', ['i' => $iA])[0] ?? [];
if (empty($tm['cas_vari_se']) || empty($tm['cas_pripraveno'])) $bad[] = 'chybí časy přípravy ' . json_encode($tm);
$A->call('POST', 'api/admin_kitchen.php?action=order_status', ['id' => $iA, 'stav' => 'served', 'src' => 'pos']);
$Q = $queue(); $K = $kds();
if (isset($Q["pos:$iA"]) || isset($K[$iA])) $bad[] = 'vydaná položka zůstala ve frontě/KDS';
rec('C4', 'Stavy queued→preparing→ready→served z kuchyně se propíšou do KDS (+ časy), vydané zmizí', !$bad, $bad ?: null);

// C5: storno z KDS (POS item_state) i z obrazovky kuchyně → útrata účtu
$iB = $ids[1] ?? null; $iC = $ids[2] ?? null;
if ($iB) {
    $A->call('POST', 'api/admin_pos.php?action=item_state', ['id' => $iB, 'stav' => 'storno']);
    $exp = $sumExp - $items[$iB]['cena'] * $items[$iB]['mn'];
    $suma = (float) q1('SELECT suma_kc FROM restaurant_pos_ucty WHERE id = :u', ['u' => $ucetId]);
    rec('C5a', 'Storno položky v KDS sníží útratu účtu', near($suma, $exp, 0.01), "suma_kc $suma vs " . round($exp, 2));
    $sumExp = $exp;
}
if ($iC) {
    $A->call('POST', 'api/admin_kitchen.php?action=order_status', ['id' => $iC, 'stav' => 'cancelled', 'src' => 'pos']);
    $st = (string) q1('SELECT stav FROM restaurant_pos_polozky WHERE id = :i', ['i' => $iC]);
    $exp = $sumExp - $items[$iC]['cena'] * $items[$iC]['mn'];
    $suma = (float) q1('SELECT suma_kc FROM restaurant_pos_ucty WHERE id = :u', ['u' => $ucetId]);
    rec('C5b', 'Zrušení položky na obrazovce kuchyně sníží útratu účtu', $st === 'storno' && near($suma, $exp, 0.01), "stav $st, suma_kc $suma vs " . round($exp, 2));
}

// C6: zaplacený účet už nejde měnit
$pay = $A->call('POST', 'api/admin_pos.php?action=pay', ['ucet_id' => $ucetId, 'payment' => 'hotove']);
$paidSum = (float) q1('SELECT suma_kc FROM restaurant_pos_ucty WHERE id = :u', ['u' => $ucetId]);
$ustav = (string) q1('SELECT stav FROM restaurant_pos_ucty WHERE id = :u', ['u' => $ucetId]);
rec('C6a', 'Zaplacení účtu (hotově) uzavře účet', $ustav === 'paid', "HTTP {$pay['code']}, stav $ustav");
$iD = end($ids);
$sx = $A->call('POST', 'api/admin_pos.php?action=item_state', ['id' => $iD, 'stav' => 'storno']);
$paidSum2 = (float) q1('SELECT suma_kc FROM restaurant_pos_ucty WHERE id = :u', ['u' => $ucetId]);
$iDst = (string) q1('SELECT stav FROM restaurant_pos_polozky WHERE id = :i', ['i' => $iD]);
rec('C6b', 'Storno položky na ZAPLACENÉM účtu se odmítne (částka se nezmění)', $sx['code'] >= 400 && near($paidSum2, $paidSum),
    "HTTP {$sx['code']}, položka $iDst, suma $paidSum → $paidSum2");
$sk = $A->call('POST', 'api/admin_kitchen.php?action=order_status', ['id' => $iD, 'stav' => 'cancelled', 'src' => 'pos']);
rec('C6c', 'Zrušení z kuchyně na ZAPLACENÉM účtu se odmítne', $sk['code'] >= 400, "HTTP {$sk['code']}");
$ri = $A->call('POST', 'api/admin_pos.php?action=item', ['ucet_id' => $ucetId, 'vyrobek_id' => $FIN[0], 'mnozstvi' => 1]);
rec('C6d', 'Přidat položku na zaplacený účet nejde (409)', $ri['code'] === 409, "HTTP {$ri['code']}");

// C7: s sebou → kitchen_queue (src=queue) se stanicí; stavy přes kuchyň
$pol = []; foreach (array_slice($FIN, 0, 2) as $vid) $pol[] = ['vyrobek_id' => $vid, 'nazev' => $VYR[$vid]['nazev'], 'mnozstvi' => 1, 'cena_bez_dph' => 10, 'sazba_dph' => 12];
$qo = $A->call('POST', 'api/admin_pos.php?action=quick_order', ['polozky' => $pol, 'pos_typ' => 'sebou', 'pos_payment' => 'hotove', 'idempotency_key' => "$TAG-" . mt_rand()]);
$objId = (int) ($qo['json']['id'] ?? $qo['json']['objednavka_id'] ?? 0);
$kq = $objId ? q('SELECT id, station_id, priprava_min, stav, vyrobek_nazev FROM kitchen_queue WHERE objednavka_id = :o', ['o' => $objId]) : [];
$bad = [];
if (count($kq) !== count($pol)) $bad[] = 've frontě ' . count($kq) . ' z ' . count($pol);
foreach ($kq as $k) {
    $vid = null; foreach ($pol as $p) if ($VYR[$p['vyrobek_id']]['nazev'] === $k['vyrobek_nazev']) $vid = $p['vyrobek_id'];
    if ($vid && (int) $k['station_id'] !== $KIT[$vid]['st']) $bad[] = "{$k['vyrobek_nazev']} stanice {$k['station_id']} vs {$KIT[$vid]['st']}";
}
if ($kq) {
    $kid = (int) $kq[0]['id'];
    $A->call('POST', 'api/admin_kitchen.php?action=order_status', ['id' => $kid, 'stav' => 'preparing', 'src' => 'queue']);
    $A->call('POST', 'api/admin_kitchen.php?action=order_status', ['id' => $kid, 'stav' => 'ready', 'src' => 'queue']);
    $rowK = q('SELECT stav, cas_zacatek, cas_hotovo FROM kitchen_queue WHERE id = :i', ['i' => $kid])[0] ?? [];
    if (($rowK['stav'] ?? '') !== 'ready' || empty($rowK['cas_zacatek']) || empty($rowK['cas_hotovo'])) $bad[] = 'stavy s sebou: ' . json_encode($rowK);
    $Q = $queue(); if (!isset($Q["queue:$kid"])) $bad[] = 'hotová položka s sebou chybí ve frontě (má čekat na výdej)';
}
rec('C7', 'S sebou → fronta kuchyně se stanicí, stavy preparing→ready s časy', $objId > 0 && !$bad, $bad ?: "HTTP {$qo['code']} obj $objId " . substr($qo['raw'], 0, 120));

} catch (Throwable $e) {
    if ($e->getMessage() !== 'skip-kitchen') { rec('ERR', 'Výjimka v testu', false, $e->getMessage() . ' @' . $e->getLine()); }
} finally {
    if ($tableId) { try { q('UPDATE restaurant_tables SET aktivni = 0 WHERE id = :i', ['i' => $tableId]); } catch (Throwable $e) {} }
    try { q("UPDATE admin_users SET aktivni = 0 WHERE email = :em", ['em' => secret()['admin_email']]); } catch (Throwable $e) {}
}

$ok = count(array_filter($R, fn($r) => $r['ok'] === true));
$fail = array_values(array_filter($R, fn($r) => $r['ok'] === false));
jsave('kb-results.json', ['seed' => $SEED, 'results' => $R, 'errors_5xx' => Http::$errors]);
L(sprintf('HOTOVO — %d/%d ok, %d chyb, %d nejasných · 5xx: %d · seed %d', $ok, count($R), count($fail), count($R) - $ok - count($fail), count(Http::$errors), $SEED));
exit($fail ? 1 : 0);
