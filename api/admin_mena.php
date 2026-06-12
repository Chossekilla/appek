<?php
/**
 * 💱 MĚNA & PŘEPOČET (v3.0.283).
 *
 * Config v nastaveni.mena_config_json:
 *   { kod:'EUR', kurz:25.000, zobrazeni:'kc'|'mena', dual_doklady:bool }
 *   - kurz = kolik Kč za 1 jednotku cílové měny (ČNB konvence, např. 1 EUR = 25,265 Kč)
 *   - zobrazeni='mena' → admin/POS/B2B ukazují ceny PŘEPOČTENÉ do cílové měny
 *     (DB zůstává v Kč — vratné přepnutím zpět na 'kc')
 *   - dual_doklady → faktura/účtenka ukáže pod součtem informativní přepočet
 *
 * GET                                → { config }
 * GET  ?action=cnb&kod=EUR           → { kurz, datum } z denního kurzovního lístku ČNB
 * POST ?action=save { config }       → uloží (validace kod/kurz)
 * GET  ?action=prepocet_nahled&kurz= → { pocet, ukazky:[{id,nazev,stara,nova}] }
 * POST ?action=prepocet { kurz }     → TRVALE přepíše vyrobky.cena_bez_dph (cena/kurz),
 *                                      transakce + zápis do activity logu. UI před tím
 *                                      vynucuje zálohu DB (admin_zalohy create).
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/_admin_auth.php';
cors_headers();
require_admin();
header('Content-Type: application/json; charset=UTF-8');

$pdo = db();

const MENA_PODPOROVANE = ['CZK' => 'Kč', 'EUR' => '€', 'USD' => '$', 'GBP' => '£', 'PLN' => 'zł', 'HUF' => 'Ft', 'CHF' => 'CHF'];

function mena_config_load(PDO $pdo): array {
    $def = ['kod' => 'CZK', 'kurz' => 1.0, 'zobrazeni' => 'kc', 'dual_doklady' => false];
    try {
        $st = $pdo->prepare("SELECT hodnota FROM nastaveni WHERE klic = 'mena_config_json'");
        $st->execute();
        $raw = $st->fetchColumn();
        $cfg = $raw ? json_decode($raw, true) : null;
        if (!is_array($cfg)) return $def;
        $out = array_merge($def, $cfg);
        $out['kod']  = strtoupper((string) $out['kod']);
        if (!isset(MENA_PODPOROVANE[$out['kod']])) $out['kod'] = 'CZK';
        $out['kurz'] = max(0.0001, (float) $out['kurz']);
        $out['zobrazeni'] = $out['zobrazeni'] === 'mena' ? 'mena' : 'kc';
        $out['dual_doklady'] = !empty($out['dual_doklady']);
        return $out;
    } catch (Throwable $e) { return $def; }
}

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$action = $_GET['action'] ?? '';

// ── GET config ────────────────────────────────────────────────────────
if ($method === 'GET' && $action === '') {
    $cfg = mena_config_load($pdo);
    $cfg['symbol'] = MENA_PODPOROVANE[$cfg['kod']];
    json_response(['config' => $cfg, 'podporovane' => MENA_PODPOROVANE]);
}

// ── GET kurz z ČNB ────────────────────────────────────────────────────
if ($method === 'GET' && $action === 'cnb') {
    $kod = strtoupper(trim((string) ($_GET['kod'] ?? 'EUR')));
    if ($kod === 'CZK') json_error('CZK je základní měna — kurz 1', 400);
    if (!isset(MENA_PODPOROVANE[$kod])) json_error('Nepodporovaná měna', 400);
    $url = 'https://www.cnb.cz/cs/financni-trhy/devizovy-trh/kurzy-devizoveho-trhu/kurzy-devizoveho-trhu/denni_kurz.txt';
    $ctx = stream_context_create(['http' => ['timeout' => 15, 'header' => "User-Agent: Appek-B2B/1.0\r\n"]]);
    $txt = @file_get_contents($url, false, $ctx);
    if ($txt === false || strlen($txt) < 50) json_error('ČNB kurzovní lístek nedostupný — zadej kurz ručně', 502);
    // formát: "12.06.2026 #113\nzemě|měna|množství|kód|kurz\nEMU|euro|1|EUR|25,265\n..."
    $lines = preg_split('/\r?\n/', trim($txt));
    $datum = explode(' ', $lines[0] ?? '')[0] ?? '';
    foreach ($lines as $ln) {
        $p = explode('|', $ln);
        if (count($p) === 5 && strtoupper(trim($p[3])) === $kod) {
            $mnozstvi = max(1, (int) $p[2]);
            $kurz = (float) str_replace(',', '.', $p[4]);
            if ($kurz <= 0) break;
            json_response(['kurz' => round($kurz / $mnozstvi, 4), 'datum' => $datum, 'kod' => $kod]);
        }
    }
    json_error("Měna $kod v lístku ČNB nenalezena", 404);
}

// ── náhled trvalého přepočtu ──────────────────────────────────────────
if ($method === 'GET' && $action === 'prepocet_nahled') {
    $kurz = (float) ($_GET['kurz'] ?? 0);
    if ($kurz <= 0) json_error('Neplatný kurz', 400);
    $pocet = (int) $pdo->query("SELECT COUNT(*) FROM vyrobky")->fetchColumn();
    $rows = $pdo->query("SELECT id, nazev, cena_bez_dph FROM vyrobky WHERE cena_bez_dph > 0 ORDER BY cena_bez_dph DESC LIMIT 6")->fetchAll(PDO::FETCH_ASSOC);
    $ukazky = array_map(fn($r) => [
        'id' => (int) $r['id'], 'nazev' => $r['nazev'],
        'stara' => (float) $r['cena_bez_dph'],
        'nova'  => round((float) $r['cena_bez_dph'] / $kurz, 2),
    ], $rows);
    json_response(['pocet' => $pocet, 'kurz' => $kurz, 'ukazky' => $ukazky]);
}

if ($method !== 'POST') json_error('Method not allowed', 405);
$d = json_input();

// ── uložit config ─────────────────────────────────────────────────────
if ($action === 'save') {
    $cfg = $d['config'] ?? $d;
    $kod = strtoupper(trim((string) ($cfg['kod'] ?? 'CZK')));
    if (!isset(MENA_PODPOROVANE[$kod])) json_error('Nepodporovaná měna', 400);
    $kurz = (float) ($cfg['kurz'] ?? 1);
    if ($kod !== 'CZK' && $kurz <= 0) json_error('Kurz musí být kladný', 400);
    $save = [
        'kod' => $kod,
        'kurz' => $kod === 'CZK' ? 1.0 : round($kurz, 4),
        'zobrazeni' => (($cfg['zobrazeni'] ?? 'kc') === 'mena' && $kod !== 'CZK') ? 'mena' : 'kc',
        'dual_doklady' => !empty($cfg['dual_doklady']) && $kod !== 'CZK',
    ];
    $pdo->prepare("INSERT INTO nastaveni (klic, hodnota) VALUES ('mena_config_json', :v)
                   ON DUPLICATE KEY UPDATE hodnota = VALUES(hodnota)")
        ->execute(['v' => json_encode($save, JSON_UNESCAPED_UNICODE)]);
    $save['symbol'] = MENA_PODPOROVANE[$save['kod']];
    json_response(['ok' => true, 'config' => $save]);
}

// ── TRVALÝ přepočet ceníku ────────────────────────────────────────────
if ($action === 'prepocet') {
    $kurz = (float) ($d['kurz'] ?? 0);
    if ($kurz <= 0.0001 || $kurz > 10000) json_error('Neplatný kurz', 400);
    if (abs($kurz - 1.0) < 0.000001) json_error('Kurz 1 = žádná změna', 400);
    $admin = function_exists('aktualni_admin') ? (aktualni_admin($pdo)['jmeno'] ?? 'Admin') : 'Admin';
    $pdo->beginTransaction();
    try {
        $pocet = (int) $pdo->query("SELECT COUNT(*) FROM vyrobky WHERE cena_bez_dph > 0")->fetchColumn();
        $pdo->exec("UPDATE vyrobky SET cena_bez_dph = ROUND(cena_bez_dph / $kurz, 2)");
        // audit do activity logu (best-effort, tabulka nemusí existovat na staré instalaci)
        try {
            $pdo->prepare("INSERT INTO activity_log (kdo, akce, detail) VALUES (:kdo, 'mena_prepocet', :det)")
                ->execute(['kdo' => $admin, 'det' => "Trvalý přepočet ceníku kurzem $kurz ($pocet výrobků)"]);
        } catch (Throwable $e) { /* ignore */ }
        $pdo->commit();
        json_response(['ok' => true, 'prepocteno' => $pocet, 'kurz' => $kurz]);
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        json_error_safe('Přepočet selhal', $e, 500);
    }
}

json_error('Neznámá akce', 400);
