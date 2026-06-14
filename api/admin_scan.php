<?php
/**
 * 🆕 v3.0.326 — Skener čárových kódů: rozpoznání naskenovaného kódu (EAN/cislo).
 *
 * GET ?code=XXX  → najde napříč PRODUKTY i SUROVINAMI (podle ean, fallback cislo u produktů).
 *   Vrací { ok, code, match:{type:'vyrobek'|'surovina', id, nazev, cislo, ean, cena_bez_dph?, jednotka?},
 *           candidates:[...], found:N }.  match = první kandidát (nebo null když nic).
 *
 * Sdílený resolver pro: HW čtečku (keyboard-wedge), kamerový sken, sklad příjem/inventura.
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/_admin_auth.php';
require_admin();
$pdo = db();

// 🆕 POST ?action=gen_ean { vyrobek_id } — vygeneruj interní EAN-13 (prefix 28 = in-store) a ulož produktu.
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && ($_GET['action'] ?? '') === 'gen_ean') {
    $d = json_input();
    $vid = (int)($d['vyrobek_id'] ?? 0);
    if (!$vid) json_error('Chybí vyrobek_id', 400);
    $base = '28' . str_pad((string)$vid, 10, '0', STR_PAD_LEFT); // 12 číslic (prefix 28 = interní)
    $s = 0;
    for ($i = 0; $i < 12; $i++) { $s += ((int)$base[$i]) * (($i % 2 === 0) ? 1 : 3); }
    $ean = $base . ((10 - ($s % 10)) % 10); // + EAN-13 kontrolní číslice
    try { $pdo->prepare("UPDATE vyrobky SET ean = :e WHERE id = :id")->execute(['e' => $ean, 'id' => $vid]); }
    catch (Throwable $e) { json_error('Uložení EAN selhalo', 500); }
    json_response(['ok' => true, 'ean' => $ean, 'vyrobek_id' => $vid]);
}

$code = preg_replace('/\s+/', '', (string)($_GET['code'] ?? $_GET['ean'] ?? ''));
if ($code === '') json_error('Chybí kód', 400);

$matches = [];

// 1) Produkty — podle EAN, fallback podle čísla výrobku
try {
    $st = $pdo->prepare("SELECT id, cislo, ean, nazev, cena_bez_dph FROM vyrobky
                         WHERE aktivni = 1 AND (ean = :e OR cislo = :c) ORDER BY (ean = :e2) DESC LIMIT 10");
    $st->execute(['e' => $code, 'c' => $code, 'e2' => $code]);
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $matches[] = ['type' => 'vyrobek', 'id' => (int)$r['id'], 'cislo' => $r['cislo'],
                      'ean' => $r['ean'], 'nazev' => $r['nazev'], 'cena_bez_dph' => (float)$r['cena_bez_dph']];
    }
} catch (Throwable $e) {}

// 2) Suroviny — podle EAN (pokud sloupec existuje)
try {
    $cols = $pdo->query("SHOW COLUMNS FROM suroviny")->fetchAll(PDO::FETCH_COLUMN);
    if (in_array('ean', $cols, true)) {
        $st = $pdo->prepare("SELECT id, nazev, ean, jednotka FROM suroviny WHERE aktivni = 1 AND ean = :e LIMIT 10");
        $st->execute(['e' => $code]);
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $matches[] = ['type' => 'surovina', 'id' => (int)$r['id'], 'ean' => $r['ean'],
                          'nazev' => $r['nazev'], 'jednotka' => $r['jednotka']];
        }
    }
} catch (Throwable $e) {}

json_response(['ok' => true, 'code' => $code, 'match' => ($matches[0] ?? null), 'candidates' => $matches, 'found' => count($matches)]);
