<?php
/**
 * ↩️ VRATKY — centrální správa vrácení (v3.0.277).
 *
 * Sjednocuje DVA druhy vratek do jednoho přehledu, propojené s původním dokladem:
 *   - POS refundace  → záporné účtenky řady VRA- (objednavky.refund_of)
 *   - Dobropisy       → opravné daňové doklady řady DOB- (faktury.je_dobropis)
 *
 * GET  /api/admin_vratky.php            → { vratky:[...], souhrn:{...}, lhuta_dni:int }
 * GET  /api/admin_vratky.php?typ=pos|faktura  → filtr
 * PUT  /api/admin_vratky.php  {lhuta_dni}     → ulož lhůtu na vrácení (dní)
 *
 * „Lhůta na vrácení" = za kolik dní od prodeje lze výrobek vrátit (politika).
 * U každé vratky počítáme `dni_od_prodeje` (datum vrácení − datum původního dokladu)
 * → vidíš, jestli proběhla v rámci lhůty (`v_lhute`).
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/_admin_auth.php';
require_once __DIR__ . '/_schema_lib.php';
cors_headers();
require_admin();
header('Content-Type: application/json; charset=UTF-8');

$pdo = db();
ensure_faktury_schema($pdo); // je_dobropis / puvodni_faktura_id
try { $pdo->exec("ALTER TABLE objednavky ADD COLUMN IF NOT EXISTS refund_of INT NULL"); } catch (Throwable $e) {}

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

function vratky_lhuta(PDO $pdo): int {
    try {
        $st = $pdo->prepare("SELECT hodnota FROM nastaveni WHERE klic = 'vratka_lhuta_dni' LIMIT 1");
        $st->execute();
        $v = $st->fetchColumn();
        $n = (int) $v;
        return ($v !== false && $n > 0) ? $n : 14;
    } catch (Throwable $e) { return 14; }
}

if ($method === 'PUT' || $method === 'POST') {
    $d = json_input();
    $lhuta = max(0, min(3650, (int) ($d['lhuta_dni'] ?? 14)));
    $pdo->prepare("INSERT INTO nastaveni (klic, hodnota) VALUES ('vratka_lhuta_dni', :v) ON DUPLICATE KEY UPDATE hodnota = :v2")
        ->execute(['v' => (string) $lhuta, 'v2' => (string) $lhuta]);
    json_response(['ok' => true, 'lhuta_dni' => $lhuta]);
}

$typ = $_GET['typ'] ?? '';
$lhuta = vratky_lhuta($pdo);
$vratky = [];

// ── POS refundace (VRA-) ──────────────────────────────────────────────
if ($typ === '' || $typ === 'pos') {
    try {
        $st = $pdo->query("
            SELECT o.id, o.cislo, o.datum_objednani AS datum_vratky, o.castka_celkem,
                   o.poznamka, o.pos_uzivatel, o.pos_payment,
                   orig.id AS puvodni_id, orig.cislo AS puvodni_cislo, orig.datum_objednani AS puvodni_datum
            FROM objednavky o
            LEFT JOIN objednavky orig ON orig.id = o.refund_of
            WHERE o.refund_of IS NOT NULL
            ORDER BY o.datum_objednani DESC, o.id DESC
            LIMIT 500
        ");
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $vratky[] = [
                'typ'            => 'pos',
                'id'             => (int) $r['id'],
                'cislo'          => $r['cislo'],
                'datum_vratky'   => $r['datum_vratky'],
                'castka'         => (float) $r['castka_celkem'],
                'puvodni_id'     => $r['puvodni_id'] ? (int) $r['puvodni_id'] : null,
                'puvodni_cislo'  => $r['puvodni_cislo'],
                'puvodni_datum'  => $r['puvodni_datum'],
                'kdo'            => $r['pos_uzivatel'] ?: '—',
                'duvod'          => (preg_match('/—\s*důvod:\s*(.+)$/u', (string) $r['poznamka'], $m) ? trim($m[1]) : ''),
            ];
        }
    } catch (Throwable $e) { /* tabulka/sloupec chybí — prázdné */ }
}

// ── Dobropisy (DOB-) ──────────────────────────────────────────────────
if ($typ === '' || $typ === 'faktura') {
    try {
        $st = $pdo->query("
            SELECT f.id, f.cislo, f.datum_vystaveni AS datum_vratky, f.castka_celkem, f.poznamka,
                   od.nazev AS odberatel,
                   pf.id AS puvodni_id, pf.cislo AS puvodni_cislo, pf.datum_vystaveni AS puvodni_datum
            FROM faktury f
            LEFT JOIN faktury pf ON pf.id = f.puvodni_faktura_id
            LEFT JOIN odberatele od ON od.id = f.odberatel_id
            WHERE f.je_dobropis = 1
            ORDER BY f.datum_vystaveni DESC, f.id DESC
            LIMIT 500
        ");
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $vratky[] = [
                'typ'            => 'faktura',
                'id'             => (int) $r['id'],
                'cislo'          => $r['cislo'],
                'datum_vratky'   => $r['datum_vratky'],
                'castka'         => (float) $r['castka_celkem'],
                'puvodni_id'     => $r['puvodni_id'] ? (int) $r['puvodni_id'] : null,
                'puvodni_cislo'  => $r['puvodni_cislo'],
                'puvodni_datum'  => $r['puvodni_datum'],
                'kdo'            => $r['odberatel'] ?: '—',
                'duvod'          => (preg_match('/—\s*důvod:\s*(.+)$/u', (string) $r['poznamka'], $m) ? trim($m[1]) : ''),
            ];
        }
    } catch (Throwable $e) { /* prázdné */ }
}

// Seřaď podle data vrácení (sestupně) + dopočítej dni od prodeje / v lhůtě
usort($vratky, fn($a, $b) => strcmp((string) $b['datum_vratky'], (string) $a['datum_vratky']));
$celkem = 0.0;
foreach ($vratky as &$v) {
    $celkem += $v['castka'];
    $v['dni_od_prodeje'] = null;
    $v['v_lhute'] = null;
    if (!empty($v['puvodni_datum']) && !empty($v['datum_vratky'])) {
        $d1 = strtotime($v['puvodni_datum']);
        $d2 = strtotime($v['datum_vratky']);
        if ($d1 && $d2) {
            $dni = (int) floor(($d2 - $d1) / 86400);
            $v['dni_od_prodeje'] = $dni;
            $v['v_lhute'] = ($dni <= $lhuta);
        }
    }
}
unset($v);

json_response([
    'vratky'    => $vratky,
    'lhuta_dni' => $lhuta,
    'souhrn'    => [
        'pocet'         => count($vratky),
        'celkem_kc'     => round($celkem, 2),
        'pocet_pos'     => count(array_filter($vratky, fn($x) => $x['typ'] === 'pos')),
        'pocet_faktura' => count(array_filter($vratky, fn($x) => $x['typ'] === 'faktura')),
    ],
]);
