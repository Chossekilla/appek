<?php
// php tests/test_sync.php — 🔄 cloudová synchronizace: číselné řady, cizí klíče, kurzory (plný běh dvou instancí = scripts/sync_e2e.sh)
$f = 0; $n = 0;
function ok($c, $m) { global $f, $n; $n++; if (!$c) { $f++; echo "FAIL: $m\n"; } }
$root = __DIR__ . '/..';
$eng = (string) file_get_contents($root . '/api/sync/_engine.php');
$pen = (string) file_get_contents($root . '/api/sync/pending.php');
$sch = (string) file_get_contents($root . '/api/_sync_schema.php');
$cfg = (string) file_get_contents($root . '/api/config.php');

// ── pořadí tabulek: rodič se odesílá před dítětem (jinak dítě nenajde rodiče a čeká na další kolo) ──
preg_match('/function sync_tables_config\(\): array \{.*?\n\}/s', $eng, $m1);
ok(!empty($m1[0]), 'sync_tables_config existuje');
if (!empty($m1[0])) {
    eval($m1[0]);
    $t = sync_tables_config(); $poz = array_flip(array_keys($t));
    foreach ($t as $tab => $o) foreach ($o['fk'] ?? [] as $col => $rodic) {
        ok(isset($t[$rodic]), "$tab.$col → $rodic je synchronizovaná tabulka");
        if ($rodic !== $tab && isset($poz[$rodic])) ok($poz[$rodic] < $poz[$tab], "$rodic se posílá před $tab");
    }
    ok(($t['objednavky_polozky']['fk']['objednavka_id'] ?? '') === 'objednavky', 'položky objednávky nesou odkaz na objednávku');
    ok(($t['objednavky_polozky']['fk']['vyrobek_id'] ?? '') === 'vyrobky', 'položky objednávky nesou odkaz na výrobek');
}

// ── číselné řady: přijatý doklad posune místní řadu, cloudová řada „C“ se vede zvlášť ──
preg_match('/function sync_cislo_posun\(PDO \$pdo, string \$cislo\): void \{.*?\n\}/s', $eng, $m2);
ok(!empty($m2[0]), 'sync_cislo_posun existuje');
if (!empty($m2[0]) && in_array('sqlite', PDO::getAvailableDrivers(), true)) {
    eval(str_replace('INSERT IGNORE', 'INSERT OR IGNORE', $m2[0]));   // MySQL → SQLite (jen pro test)
    $db = new PDO('sqlite::memory:'); $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $db->sqliteCreateFunction('GREATEST', fn($a, $b) => max((int) $a, (int) $b), 2);
    $db->exec("CREATE TABLE cislovani (typ TEXT, rok INT, predcisli TEXT, posledni INT, UNIQUE(typ, rok))");
    $db->exec("INSERT INTO cislovani VALUES ('WEB',2026,'WEB-2026-',10), ('WEB@C',2026,'WEB-2026-C',5), ('FA',2026,'FA-2026-',3)");
    $p = fn($typ) => (int) $db->query("SELECT posledni FROM cislovani WHERE typ='$typ'")->fetchColumn();
    sync_cislo_posun($db, 'WEB-2026-0048');
    ok($p('WEB') === 48, 'WEB-2026-0048 posune řadu WEB na 48');
    sync_cislo_posun($db, 'WEB-2026-0012');
    ok($p('WEB') === 48, 'starší číslo řadu nevrací zpět');
    sync_cislo_posun($db, 'WEB-2026-C0121');
    ok($p('WEB@C') === 121 && $p('WEB') === 48, 'cloudové C0121 posune jen řadu WEB@C');
    sync_cislo_posun($db, 'FA-2026-0007');
    ok($p('FA') === 7 && $p('WEB') === 48, 'faktura posune jen svou řadu');
    sync_cislo_posun($db, 'NECO-JINEHO'); sync_cislo_posun($db, '');
    ok($p('WEB') === 48, 'neznámé / prázdné číslo nic nerozbije');
} else ok(false, 'pdo_sqlite chybí — test čísel se nespustil');
ok(str_contains($eng, 'sync_cislo_posun($pdo,'), 'příjem dávky volá sync_cislo_posun');
ok(str_contains($cfg, 'function cislo_uzel(PDO $pdo): string') && str_contains($cfg, "\$typ . '@' . \$uzel"), 'cloud (mirror) čísluje vlastní řadou');

// ── cizí klíče přes sync_uuid, nezapsané se pošlou znovu ──
ok(str_contains($eng, 'function sync_attach_refs(') && str_contains($pen, 'sync_attach_refs($pdo, $table, $rows)'), 'odesílání přikládá uuid rodičů (__ref) i z cloudu');
ok(str_contains($eng, "unset(\$rec['__ref'])"), '__ref se nezapisuje do tabulky');
ok(str_contains($eng, "\$stats['failed'][] = (string) \$rec['sync_uuid']"), 'nezapsaný záznam se hlásí jako failed');
ok(str_contains($eng, '!isset($failed[(string) $u])'), 'failed se neoznačí jako synchronizované (pošle se znovu)');

// ── stahování z cloudu: kurzor (updated_at, id) pro každou tabulku zvlášť ──
ok(str_contains($pen, 'WHERE updated_at > :ts OR (updated_at = :ts2 AND id > :id)') && str_contains($pen, 'ORDER BY updated_at ASC, id ASC'), 'stránkování podle (updated_at, id) — nic se nepřeskočí ani neuvázne');
ok(str_contains($pen, "'kurzor' => \$novyKurzor + \$kurzor"), 'cloud vrací nové kurzory');
ok(str_contains($eng, 'UPDATE sync_config SET pull_kurzor'), 'lokální PC si kurzory ukládá');
ok(str_contains($sch, 'ADD COLUMN pull_kurzor TEXT NULL'), 'migrace sloupce pull_kurzor');

// ── appek: updated_at (ON UPDATE CURRENT_TIMESTAMP) se při syncu nepřepisuje ──
ok(str_contains($eng, 'SET synced_at = NOW(), updated_at = updated_at'), 'označení „odesláno“ zachová updated_at');
ok(substr_count($sch, 'updated_at = updated_at') >= 2, 'backfill sync_uuid zachová updated_at');
ok(str_contains($eng, "'`updated_at` = `updated_at`'"), 'příjem bez updated_at od odesílatele zachová místní updated_at');

// ── appek: cloudová řada převezme vlastní předčíslí místní řady (FA → „FAK-2026-“ → „FAK-2026-C“) ──
ok(str_contains($cfg, '$vychozi_predcisli = $zakl_predcisli . $uzel'), 'cloudová řada dědí vlastní předčíslí');

// ── výkon: vypnutý sync nezatěžuje každý požadavek ──
$posM = strpos($sch, 'if (!sync_is_enabled($pdo)) return;'); $posT = strpos($sch, '$syncTables = [');
ok($posM !== false && $posT !== false && $posM < $posT, 'při vypnutém sync se neprochází 14 tabulek');

echo $f ? "FAIL $f/$n\n" : "OK $n/$n\n";
exit($f ? 1 : 0);
