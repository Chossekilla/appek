<?php
/**
 * 🔒 GDPR MODUL (v3.0.425) — správa zásad zpracování osobních údajů + práva subjektu.
 *
 * Text zásad se ukládá do nastaveni.gdpr_zasady_text (HTML). Prázdné = použije se
 * obecná šablona předvyplněná firemními údaji (firma_*). Veřejné čtení: gdpr_verejne.php.
 *
 * GET                          → { text, updated, is_default }  (text = uložený, nebo šablona)
 * GET  ?action=template        → { text }  čerstvě vygenerovaná obecná šablona
 * POST ?action=save { text }   → uloží text zásad (+ gdpr_zasady_updated)
 * GET  ?action=customers&q=    → [{ id, nazev, email }]  picker pro práva subjektu
 * GET  ?action=export&id=      → kompletní balík osobních údajů odběratele (JSON)
 * POST ?action=anonymize {id}  → anonymizuje osobní údaje odběratele (účetní doklady zůstávají)
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/_admin_auth.php';
require_once __DIR__ . '/_gdpr_lib.php';
cors_headers();
require_admin();
header('Content-Type: application/json; charset=UTF-8');

$pdo    = db();
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$action = $_GET['action'] ?? '';
$d      = json_decode(file_get_contents('php://input'), true) ?: [];

// ── GET: uložený text (nebo šablona) ─────────────────────────────────────
if ($method === 'GET' && $action === '') {
    $text = '';
    try {
        $st = $pdo->prepare("SELECT hodnota FROM nastaveni WHERE klic = 'gdpr_zasady_text'");
        $st->execute();
        $text = (string) $st->fetchColumn();
    } catch (Throwable $e) { /* ignore */ }
    $updated = '';
    try {
        $st = $pdo->prepare("SELECT hodnota FROM nastaveni WHERE klic = 'gdpr_zasady_updated'");
        $st->execute();
        $updated = (string) $st->fetchColumn();
    } catch (Throwable $e) { /* ignore */ }
    $isDefault = trim($text) === '';
    if ($isDefault) $text = gdpr_default_template(gdpr_firma($pdo));
    json_response(['text' => $text, 'updated' => $updated, 'is_default' => $isDefault]);
}

// ── GET: čerstvá obecná šablona ──────────────────────────────────────────
if ($method === 'GET' && $action === 'template') {
    json_response(['text' => gdpr_default_template(gdpr_firma($pdo))]);
}

// ── POST: uložit text zásad ──────────────────────────────────────────────
if ($method === 'POST' && $action === 'save') {
    $text = trim((string) ($d['text'] ?? ''));
    if (mb_strlen($text) > 200000) json_error('Text je příliš dlouhý', 400);
    $now = date('Y-m-d H:i:s');
    try {
        $up = $pdo->prepare("INSERT INTO nastaveni (klic, hodnota) VALUES (:k, :v)
                             ON DUPLICATE KEY UPDATE hodnota = VALUES(hodnota)");
        $up->execute(['k' => 'gdpr_zasady_text', 'v' => $text]);
        $up->execute(['k' => 'gdpr_zasady_updated', 'v' => $now]);
        json_response(['ok' => true, 'updated' => $now]);
    } catch (Throwable $e) {
        json_error_safe('Uložení selhalo', $e, 500);
    }
}

// ── GET: seznam odběratelů pro picker práv subjektu ──────────────────────
if ($method === 'GET' && $action === 'customers') {
    $q = trim((string) ($_GET['q'] ?? ''));
    try {
        if ($q !== '') {
            $like = '%' . $q . '%';
            $st = $pdo->prepare("SELECT id, nazev, email FROM odberatele
                                 WHERE nazev LIKE :q1 OR email LIKE :q2 OR ico LIKE :q3
                                 ORDER BY nazev LIMIT 50");
            $st->execute(['q1' => $like, 'q2' => $like, 'q3' => $like]);
        } else {
            $st = $pdo->query("SELECT id, nazev, email FROM odberatele ORDER BY nazev LIMIT 50");
        }
        json_response(['customers' => $st->fetchAll(PDO::FETCH_ASSOC)]);
    } catch (Throwable $e) {
        json_error_safe('Načtení selhalo', $e, 500);
    }
}

// ── GET: export osobních údajů odběratele (právo na přístup/přenositelnost) ─
if ($method === 'GET' && $action === 'export') {
    $id = (int) ($_GET['id'] ?? 0);
    if ($id <= 0) json_error('Chybí id odběratele', 400);
    try {
        $st = $pdo->prepare("SELECT * FROM odberatele WHERE id = :id");
        $st->execute(['id' => $id]);
        $odb = $st->fetch(PDO::FETCH_ASSOC);
        if (!$odb) json_error('Odběratel nenalezen', 404);
        // citlivé interní pole ven neposíláme
        unset($odb['heslo_hash']);

        $obj = [];
        try {
            $so = $pdo->prepare("SELECT id, cislo, datum, stav, celkem, poznamka
                                 FROM objednavky WHERE odberatel_id = :id ORDER BY datum DESC");
            $so->execute(['id' => $id]);
            $obj = $so->fetchAll(PDO::FETCH_ASSOC);
        } catch (Throwable $e) { /* tabulka/sloupce se mohou lišit */ }

        $fak = [];
        try {
            $sf = $pdo->prepare("SELECT id, cislo, datum_vystaveni, celkem
                                 FROM faktury WHERE odberatel_id = :id ORDER BY datum_vystaveni DESC");
            $sf->execute(['id' => $id]);
            $fak = $sf->fetchAll(PDO::FETCH_ASSOC);
        } catch (Throwable $e) { /* ignore */ }

        json_response([
            'exported_at' => date('c'),
            'odberatel'   => $odb,
            'objednavky'  => $obj,
            'faktury'     => $fak,
            'poznamka'    => 'Export osobních údajů dle čl. 15/20 GDPR. Účetní doklady podléhají zákonné době uchování.',
        ]);
    } catch (Throwable $e) {
        json_error_safe('Export selhal', $e, 500);
    }
}

// ── POST: anonymizace osobních údajů odběratele ──────────────────────────
//   Účetní doklady (faktury/objednávky) zůstávají kvůli zákonné povinnosti,
//   ale osobní identifikátory u odběratele se přepíší. Nevratné.
if ($method === 'POST' && $action === 'anonymize') {
    $id = (int) ($d['id'] ?? 0);
    if ($id <= 0) json_error('Chybí id odběratele', 400);
    $admin = function_exists('aktualni_admin') ? (aktualni_admin($pdo)['jmeno'] ?? 'Admin') : 'Admin';
    try {
        $st = $pdo->prepare("SELECT nazev FROM odberatele WHERE id = :id");
        $st->execute(['id' => $id]);
        $nazev = $st->fetchColumn();
        if ($nazev === false) json_error('Odběratel nenalezen', 404);

        $anon = 'Anonymizováno #' . $id;
        // sestav UPDATE jen ze sloupců, které reálně existují (kompatibilita se staršími instalacemi)
        $cols = [];
        try {
            foreach ($pdo->query("SHOW COLUMNS FROM odberatele") as $c) $cols[strtolower($c['Field'])] = true;
        } catch (Throwable $e) { /* ignore */ }
        $set = [];
        $params = ['id' => $id, 'anon' => $anon];
        $map = [
            'nazev'           => ':anon',
            'email'           => "''",
            'telefon'         => "''",
            'ulice'           => "''",
            'mesto'           => "''",
            'psc'             => "''",
            'web'             => "''",
            'kontaktni_osoba' => "''",
            'poznamka'        => "''",
            'ico'             => "''",
            'dic'             => "''",
            'login_email'     => 'NULL',
            'heslo_hash'      => 'NULL',
            'notif_emaily'    => "''",
            'notif_souhlas'   => '0',
            'blokovan'        => '1',
        ];
        foreach ($map as $col => $val) {
            if (isset($cols[$col])) $set[] = "$col = $val";
        }
        if (!$set) json_error('Nelze anonymizovat (chybí sloupce)', 500);

        $pdo->prepare("UPDATE odberatele SET " . implode(', ', $set) . " WHERE id = :id")->execute($params);
        try {
            $pdo->prepare("INSERT INTO activity_log (kdo, akce, detail) VALUES (:kdo, 'gdpr_anonymize', :det)")
                ->execute(['kdo' => $admin, 'det' => "Anonymizace odběratele #$id ($nazev)"]);
        } catch (Throwable $e) { /* ignore */ }
        json_response(['ok' => true, 'id' => $id]);
    } catch (Throwable $e) {
        json_error_safe('Anonymizace selhala', $e, 500);
    }
}

json_error('Neznámá akce', 400);
