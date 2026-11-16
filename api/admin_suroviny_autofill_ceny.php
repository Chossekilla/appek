<?php
/**
 * 🧾 Doplnění orientačních cen surovin (v3.0.640 — přepracováno).
 *
 *   GET  → náhled: návrhy v JEDNOTCE SUROVINY + stav (doplnit/ma_cenu/beze_zmeny/nekompatibilni/nerozpoznano)
 *   POST { force?, ids?[], potvrzeno_kc? } → uloží vybrané (ceny počítá server, nikdy z klienta);
 *        před zápisem záloha DB (zaloha_snapshot), vše v transakci, vrací seznam změn.
 * Jednotka suroviny se NIKDY nemění (sklad, minima i recepty jsou v ní). Pravidla + převod:
 * _suroviny_ceny_lib.php (test scripts/test-suroviny-ceny.php). Po trvalém přepočtu ceníku do cizí
 * měny je doplnění Kč cen zablokované.
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/_admin_auth.php';
require_once __DIR__ . '/_suroviny_ceny_lib.php';
require_once __DIR__ . '/_zaloha_helper.php';
cors_headers();
require_admin();

$pdo = db();
$method = $_SERVER['REQUEST_METHOD'];

try {
    $suroviny = $pdo->query("SELECT id, nazev, jednotka, cena_baleni, obsah_baleni FROM suroviny WHERE aktivni = 1 ORDER BY nazev")->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    $suroviny = [];
}

$navrhy = [];
$pocty = ['doplnit' => 0, 'ma_cenu' => 0, 'beze_zmeny' => 0, 'nekompatibilni' => 0, 'nerozpoznano' => 0];
foreach ($suroviny as $s) {
    $hit = suroviny_najdi_cenu((string) $s['nazev']);
    $n = suroviny_cena_navrh($s, $hit);
    $pocty[$n['stav']]++;
    $navrhy[] = [
        'id'       => (int) $s['id'],
        'nazev'    => $s['nazev'],
        'jednotka' => $s['jednotka'],
        'aktualni' => ['cena_baleni' => (float) ($s['cena_baleni'] ?? 0), 'obsah_baleni' => (float) ($s['obsah_baleni'] ?? 0)],
        'navrh'    => ($hit && $n['cena_baleni'] !== null) ? [
            'cena_baleni' => $n['cena_baleni'], 'obsah_baleni' => $n['obsah_baleni'], 'label' => $hit['label'],
            'cena_za_jed' => $n['cena_za_jed_navrh'], 'jed' => $n['jed_navrh'],
        ] : ($hit ? ['label' => $hit['label'], 'jed' => $hit['jed']] : null),
        'cena_za_jed_akt' => $n['cena_za_jed_akt'],
        'stav'     => $n['stav'],
        'duvod'    => $n['duvod'],
    ];
}

if ($method === 'GET') {
    json_response(['preview' => true, 'zdroj' => SUROVINY_CENY_ZDROJ, 'mena' => suroviny_mena_stav($pdo),
                   'celkem' => count($navrhy), 'pocty' => $pocty, 'navrhy' => $navrhy]);
}
if ($method !== 'POST') json_error('Neznámá metoda', 405);

$d = json_input();
$force = !empty($d['force']) || !empty($_GET['force']);
$ids = array_values(array_filter(array_map('intval', (array) ($d['ids'] ?? [])), fn($x) => $x > 0));
$mena = suroviny_mena_stav($pdo);
if ($mena['rezim'] === 'blok') {
    json_error('Ceny ve vaší databázi byly trvale přepočteny do ' . $mena['db_kod'] . '. Orientační ceny jsou v Kč, proto je automatické doplnění vypnuté — doplňte ceny ručně.', 409);
}

$vybrane = array_values(array_filter($navrhy, function ($n) use ($force, $ids) {
    if (!in_array($n['stav'], $force ? ['doplnit', 'ma_cenu'] : ['doplnit'], true)) return false;
    return !$ids || in_array($n['id'], $ids, true);
}));
$skipped = count($navrhy) - count($vybrane);
if (!$vybrane) json_response(['ok' => true, 'force' => $force, 'applied' => 0, 'skipped' => $skipped, 'zmeny' => [], 'zaloha' => false]);

$zal = zaloha_snapshot($pdo, 'Před doplněním cen surovin (' . count($vybrane) . ' ks' . ($force ? ', přepis' : '') . ')');
$zmeny = [];
$pdo->beginTransaction();
try {
    $up = $pdo->prepare("UPDATE suroviny SET cena_baleni = :cb, obsah_baleni = :ob WHERE id = :id AND aktivni = 1");
    foreach ($vybrane as $n) {
        $up->execute(['cb' => $n['navrh']['cena_baleni'], 'ob' => $n['navrh']['obsah_baleni'], 'id' => $n['id']]);
        $zmeny[] = ['id' => $n['id'], 'nazev' => $n['nazev'], 'pred' => $n['aktualni'],
                    'po' => ['cena_baleni' => $n['navrh']['cena_baleni'], 'obsah_baleni' => $n['navrh']['obsah_baleni']]];
    }
    $pdo->commit();
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    json_error_safe('Doplnění cen selhalo', $e, 500);
}
try {
    $pdo->prepare("INSERT INTO nastaveni (klic, hodnota) VALUES ('suroviny_autofill_posledni', :v) ON DUPLICATE KEY UPDATE hodnota = VALUES(hodnota)")
        ->execute(['v' => json_encode(['kdy' => date('c'), 'kdo' => $_SESSION['admin_jmeno'] ?? 'admin', 'applied' => count($zmeny), 'force' => $force], JSON_UNESCAPED_UNICODE)]);
} catch (Throwable $e) { /* jen evidence */ }

json_response(['ok' => true, 'force' => $force, 'applied' => count($zmeny), 'skipped' => $skipped,
               'nekompatibilni' => $pocty['nekompatibilni'], 'nerozpoznano' => $pocty['nerozpoznano'],
               'zaloha' => (bool) $zal, 'zmeny' => $zmeny]);
