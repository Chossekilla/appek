<?php
/**
 * 🏭 ADMIN SKLADY — CRUD pro multi-warehouse správu.
 *
 * Z multi-warehouse spec (2026-05-23-multi-warehouse-design.md), PR1.
 * Sklady jako entita (kod, nazev, typ, teplota, adresa, aktivni).
 * Položky a pohyby budou v dalších PR.
 *
 * GET    /api/admin_sklady.php              → seznam
 * GET    /api/admin_sklady.php?id=N         → detail (s počty položek — později)
 * POST   /api/admin_sklady.php              → vytvořit (body: nazev, typ?, teplota_*?, adresa?, poznamka?)
 *                                              → auto-generuje kod (SK0N — první volné)
 * PUT    /api/admin_sklady.php?id=N         → update všech atributů
 * DELETE /api/admin_sklady.php?id=N         → smaže (pokud nemá pohyby/položky)
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/_admin_auth.php';
require_once __DIR__ . '/_schema_lib.php';
cors_headers();
require_admin();

$pdo = db();
ensure_sklady_schema($pdo);

$method = $_SERVER['REQUEST_METHOD'];
$id = (int) ($_GET['id'] ?? 0);

// ─── GET ────────────────────────────────────────────────────────
if ($method === 'GET') {
    if ($id > 0) {
        $stmt = $pdo->prepare("SELECT * FROM sklady WHERE id = :id LIMIT 1");
        $stmt->execute(['id' => $id]);
        $sklad = $stmt->fetch();
        if (!$sklad) json_error('Sklad nenalezen', 404);
        json_response($sklad);
    }
    $rows = $pdo->query("SELECT * FROM sklady ORDER BY aktivni DESC, poradi, id")->fetchAll();
    json_response(['sklady' => $rows]);
}

// ─── POST: vytvořit ────────────────────────────────────────────
if ($method === 'POST') {
    $d = json_input();
    $nazev = trim((string) ($d['nazev'] ?? ''));
    if ($nazev === '') json_error('Chybí název skladu');

    $typ = trim((string) ($d['typ'] ?? 'jiny'));
    if (!in_array($typ, ['suchy','lednice','mrazak','jiny'], true)) $typ = 'jiny';

    // Auto-generuj kod: SK01, SK02, ... (první volné)
    $maxCislo = 0;
    foreach ($pdo->query("SELECT kod FROM sklady WHERE kod REGEXP '^SK[0-9]+$'")->fetchAll(PDO::FETCH_COLUMN) as $k) {
        $n = (int) substr($k, 2);
        if ($n > $maxCislo) $maxCislo = $n;
    }
    $kod = 'SK' . str_pad((string)($maxCislo + 1), 2, '0', STR_PAD_LEFT);

    // Validace teplot
    $tMin = isset($d['teplota_min']) && $d['teplota_min'] !== '' ? (float) $d['teplota_min'] : null;
    $tMax = isset($d['teplota_max']) && $d['teplota_max'] !== '' ? (float) $d['teplota_max'] : null;
    if ($tMin !== null && $tMax !== null && $tMin > $tMax) {
        json_error('Teplota min nesmí být vyšší než max');
    }

    $maxPoradi = (int) $pdo->query("SELECT COALESCE(MAX(poradi), 0) FROM sklady")->fetchColumn();

    $stmt = $pdo->prepare("
        INSERT INTO sklady (kod, nazev, typ, teplota_min, teplota_max, adresa, poznamka, aktivni, poradi)
        VALUES (:k, :n, :t, :tmin, :tmax, :a, :p, 1, :por)
    ");
    $stmt->execute([
        'k' => $kod, 'n' => $nazev, 't' => $typ,
        'tmin' => $tMin, 'tmax' => $tMax,
        'a' => trim((string) ($d['adresa'] ?? '')) ?: null,
        'p' => trim((string) ($d['poznamka'] ?? '')) ?: null,
        'por' => $maxPoradi + 1,
    ]);
    $newId = (int) $pdo->lastInsertId();
    json_response(['ok' => true, 'id' => $newId, 'kod' => $kod], 201);
}

// ─── PUT: update ────────────────────────────────────────────────
if ($method === 'PUT') {
    if (!$id) json_error('Chybí ID');
    $d = json_input();

    $sets = [];
    $params = ['id' => $id];

    // kod editovatelný (validace formátu)
    if (isset($d['kod'])) {
        $kod = trim((string) $d['kod']);
        if (!preg_match('/^[A-Z0-9_-]{2,20}$/i', $kod)) json_error('Kód musí být 2-20 alfanumerických znaků');
        $sets[] = 'kod = :kod'; $params['kod'] = strtoupper($kod);
    }
    if (isset($d['nazev'])) {
        $nazev = trim((string) $d['nazev']);
        if ($nazev === '') json_error('Název nesmí být prázdný');
        $sets[] = 'nazev = :nazev'; $params['nazev'] = $nazev;
    }
    if (isset($d['typ'])) {
        $typ = in_array($d['typ'], ['suchy','lednice','mrazak','jiny'], true) ? $d['typ'] : 'jiny';
        $sets[] = 'typ = :typ'; $params['typ'] = $typ;
    }
    if (array_key_exists('teplota_min', $d)) {
        $sets[] = 'teplota_min = :tmin';
        $params['tmin'] = $d['teplota_min'] === '' || $d['teplota_min'] === null ? null : (float) $d['teplota_min'];
    }
    if (array_key_exists('teplota_max', $d)) {
        $sets[] = 'teplota_max = :tmax';
        $params['tmax'] = $d['teplota_max'] === '' || $d['teplota_max'] === null ? null : (float) $d['teplota_max'];
    }
    if (array_key_exists('adresa', $d)) {
        $sets[] = 'adresa = :adresa';
        $params['adresa'] = trim((string) $d['adresa']) ?: null;
    }
    if (array_key_exists('poznamka', $d)) {
        $sets[] = 'poznamka = :pozn';
        $params['pozn'] = trim((string) $d['poznamka']) ?: null;
    }
    if (isset($d['aktivni'])) {
        $sets[] = 'aktivni = :akt';
        $params['akt'] = $d['aktivni'] ? 1 : 0;
    }

    if (empty($sets)) json_error('Nic k uložení');

    try {
        $pdo->prepare("UPDATE sklady SET " . implode(', ', $sets) . " WHERE id = :id")->execute($params);
        json_response(['ok' => true]);
    } catch (PDOException $e) {
        if (strpos($e->getMessage(), 'Duplicate') !== false) json_error('Kód skladu už existuje', 409);
        throw $e;
    }
}

// ─── DELETE ─────────────────────────────────────────────────────
if ($method === 'DELETE') {
    if (!$id) json_error('Chybí ID');
    // TODO PR2/3: zkontrolovat zda nejsou položky / pohyby — pro teď jen soft delete
    try {
        // Pokud má aktivní pohyby (až existuje sklad_pohyby table) → soft delete
        // Pro PR1: hard delete (žádné FK constraints zatím nejsou)
        $pdo->prepare("DELETE FROM sklady WHERE id = :id")->execute(['id' => $id]);
        json_response(['ok' => true]);
    } catch (PDOException $e) {
        // FK violation v budoucnu → soft delete
        $pdo->prepare("UPDATE sklady SET aktivni = 0 WHERE id = :id")->execute(['id' => $id]);
        json_response(['ok' => true, 'soft_deleted' => true]);
    }
}

json_error('Method not allowed', 405);
