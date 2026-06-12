<?php
/**
 * 🎟️ VOUCHERY / DÁRKOVÉ KARTY — kódy s hodnotou, částečné uplatnění, dobíjení (v3.0.281).
 *
 *   typ = 'voucher'       — jednorázový poukaz (lze dobít? ne — ale technicky stejný balance model)
 *   typ = 'darkova_karta' — dárková/věrnostní karta (lze dobít = topup)
 *
 * GET  /api/admin_vouchers.php                  → { vouchery:[...], souhrn:{...} }
 * GET  /api/admin_vouchers.php?kod=XXXX         → detail jednoho (pro POS lookup před uplatněním)
 * POST ?action=create   { hodnota, typ?, pocet?, platnost_do?, poznamka? } → vygeneruje kódy
 * POST ?action=redeem   { kod, castka, doklad? } → uplatní min(castka, zustatek), vrátí {uplatneno, zbytek}
 * POST ?action=topup    { id|kod, castka }       → dobije zůstatek (dárková karta)
 * POST ?action=deactivate { id }                 → stav=zruseny
 *
 * Konzistence: redeem běží v transakci se SELECT ... FOR UPDATE (zámek řádku) → nelze
 * uplatnit stejný voucher 2× souběžně (double-spend). Každý pohyb se loguje do voucher_pohyby.
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/_admin_auth.php';
cors_headers();
require_admin();
header('Content-Type: application/json; charset=UTF-8');

$pdo = db();

function vouchery_ensure_schema(PDO $pdo): void {
    static $done = false;
    if ($done) return;
    $done = true;
    try {
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS vouchery (
                id INT AUTO_INCREMENT PRIMARY KEY,
                kod VARCHAR(40) NOT NULL UNIQUE,
                typ VARCHAR(20) NOT NULL DEFAULT 'voucher',
                hodnota DECIMAL(10,2) NOT NULL DEFAULT 0,
                zustatek DECIMAL(10,2) NOT NULL DEFAULT 0,
                platnost_do DATE NULL,
                stav VARCHAR(20) NOT NULL DEFAULT 'aktivni',
                poznamka VARCHAR(255) NULL,
                vytvoreno DATETIME DEFAULT CURRENT_TIMESTAMP,
                posledni_pouziti DATETIME NULL,
                INDEX idx_kod (kod), INDEX idx_stav (stav)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS voucher_pohyby (
                id INT AUTO_INCREMENT PRIMARY KEY,
                voucher_id INT NOT NULL,
                typ VARCHAR(20) NOT NULL,         -- 'uplatneni' | 'dobiti' | 'vytvoreni'
                castka DECIMAL(10,2) NOT NULL,    -- + dobití / vytvoření, − uplatnění
                zustatek_po DECIMAL(10,2) NOT NULL,
                doklad VARCHAR(40) NULL,
                kdo VARCHAR(120) NULL,
                kdy DATETIME DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_v (voucher_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
    } catch (Throwable $e) { error_log('vouchery_ensure_schema: ' . $e->getMessage()); }
}
vouchery_ensure_schema($pdo);

// Bezpečná abeceda — bez matoucích znaků (0/O, 1/I/L)
function voucher_gen_kod(string $prefix = 'DK'): string {
    $chars = 'ABCDEFGHJKMNPQRSTUVWXYZ23456789';
    $part = function () use ($chars) {
        $s = '';
        for ($i = 0; $i < 4; $i++) $s .= $chars[random_int(0, strlen($chars) - 1)];
        return $s;
    };
    return $prefix . '-' . $part() . '-' . $part() . '-' . $part();
}

/** Aktualizuj stav podle zůstatku/platnosti (nemutuje DB, jen vrací). */
function voucher_stav_runtime(array $v): string {
    if ($v['stav'] === 'zruseny') return 'zruseny';
    if (!empty($v['platnost_do']) && $v['platnost_do'] < date('Y-m-d')) return 'expirovany';
    if ((float) $v['zustatek'] <= 0.001) return 'vycerpany';
    return 'aktivni';
}

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$action = $_GET['action'] ?? '';
$admin  = function_exists('aktualni_admin') ? (aktualni_admin($pdo)['jmeno'] ?? 'Admin') : 'Admin';

// ── GET ───────────────────────────────────────────────────────────────
if ($method === 'GET') {
    if (!empty($_GET['kod'])) {
        $st = $pdo->prepare("SELECT * FROM vouchery WHERE kod = :k LIMIT 1");
        $st->execute(['k' => strtoupper(trim((string) $_GET['kod']))]);
        $v = $st->fetch(PDO::FETCH_ASSOC);
        if (!$v) json_error('Voucher nenalezen', 404);
        $v['stav_aktualni'] = voucher_stav_runtime($v);
        json_response(['voucher' => $v]);
    }
    $rows = $pdo->query("SELECT * FROM vouchery ORDER BY vytvoreno DESC, id DESC LIMIT 500")->fetchAll(PDO::FETCH_ASSOC);
    $hodnotaCelkem = 0; $zustatekCelkem = 0; $aktivni = 0;
    foreach ($rows as &$v) {
        $v['stav_aktualni'] = voucher_stav_runtime($v);
        $hodnotaCelkem += (float) $v['hodnota'];
        if ($v['stav_aktualni'] === 'aktivni') { $zustatekCelkem += (float) $v['zustatek']; $aktivni++; }
    }
    unset($v);
    json_response([
        'vouchery' => $rows,
        'souhrn'   => [
            'pocet'           => count($rows),
            'aktivnich'       => $aktivni,
            'hodnota_celkem'  => round($hodnotaCelkem, 2),
            'zustatek_aktivni'=> round($zustatekCelkem, 2),
        ],
    ]);
}

if ($method !== 'POST') json_error('Method not allowed', 405);
$d = json_input();

// ── CREATE ────────────────────────────────────────────────────────────
if ($action === 'create') {
    $hodnota = round((float) ($d['hodnota'] ?? 0), 2);
    $typ     = ($d['typ'] ?? 'voucher') === 'darkova_karta' ? 'darkova_karta' : 'voucher';
    $pocet   = max(1, min(500, (int) ($d['pocet'] ?? 1)));
    $platnost= !empty($d['platnost_do']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $d['platnost_do']) ? $d['platnost_do'] : null;
    $pozn    = isset($d['poznamka']) ? mb_substr(trim((string) $d['poznamka']), 0, 255) : null;
    if ($hodnota <= 0) json_error('Hodnota musí být kladná', 400);

    $prefix = $typ === 'darkova_karta' ? 'DK' : 'VO';
    $ins = $pdo->prepare("INSERT INTO vouchery (kod, typ, hodnota, zustatek, platnost_do, poznamka) VALUES (:k,:t,:h,:z,:p,:po)");
    $insLog = $pdo->prepare("INSERT INTO voucher_pohyby (voucher_id, typ, castka, zustatek_po, kdo) VALUES (:v,'vytvoreni',:c,:z,:kdo)");
    $vytvoreno = [];
    $pdo->beginTransaction();
    try {
        for ($i = 0; $i < $pocet; $i++) {
            // generuj unikátní kód (max 6 pokusů)
            $kod = '';
            for ($a = 0; $a < 6; $a++) {
                $kod = voucher_gen_kod($prefix);
                $chk = $pdo->prepare("SELECT 1 FROM vouchery WHERE kod = :k LIMIT 1");
                $chk->execute(['k' => $kod]);
                if (!$chk->fetchColumn()) break;
                $kod = '';
            }
            if ($kod === '') throw new Exception('Nepodařilo se vygenerovat unikátní kód');
            $ins->execute(['k' => $kod, 't' => $typ, 'h' => $hodnota, 'z' => $hodnota, 'p' => $platnost, 'po' => $pozn]);
            $vid = (int) $pdo->lastInsertId();
            $insLog->execute(['v' => $vid, 'c' => $hodnota, 'z' => $hodnota, 'kdo' => $admin]);
            $vytvoreno[] = ['id' => $vid, 'kod' => $kod];
        }
        $pdo->commit();
        json_response(['ok' => true, 'vytvoreno' => $vytvoreno, 'pocet' => count($vytvoreno)], 201);
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        json_error_safe('Vytvoření selhalo', $e, 500);
    }
}

// ── REDEEM (uplatnění) ────────────────────────────────────────────────
if ($action === 'redeem') {
    $kod    = strtoupper(trim((string) ($d['kod'] ?? '')));
    $castka = round((float) ($d['castka'] ?? 0), 2);
    $doklad = isset($d['doklad']) ? mb_substr((string) $d['doklad'], 0, 40) : null;
    if ($kod === '') json_error('Chybí kód', 400);
    if ($castka <= 0) json_error('Částka musí být kladná', 400);

    $pdo->beginTransaction();
    try {
        // 🔒 zámek řádku → žádný double-spend
        $st = $pdo->prepare("SELECT * FROM vouchery WHERE kod = :k LIMIT 1 FOR UPDATE");
        $st->execute(['k' => $kod]);
        $v = $st->fetch(PDO::FETCH_ASSOC);
        if (!$v) { $pdo->rollBack(); json_error('Voucher „' . $kod . '" neexistuje', 404); }
        if ($v['stav'] === 'zruseny') { $pdo->rollBack(); json_error('Voucher je zrušený', 409); }
        if (!empty($v['platnost_do']) && $v['platnost_do'] < date('Y-m-d')) {
            $pdo->prepare("UPDATE vouchery SET stav='expirovany' WHERE id=:id")->execute(['id' => $v['id']]);
            $pdo->commit();
            json_error('Voucher expiroval ' . $v['platnost_do'], 409);
        }
        $zust = (float) $v['zustatek'];
        if ($zust <= 0.001) { $pdo->rollBack(); json_error('Voucher je již vyčerpaný', 409); }

        $uplatneno = min($castka, $zust);
        $novyZust  = round($zust - $uplatneno, 2);
        $novyStav  = $novyZust <= 0.001 ? 'vycerpany' : 'aktivni';
        $pdo->prepare("UPDATE vouchery SET zustatek=:z, stav=:s, posledni_pouziti=NOW() WHERE id=:id")
            ->execute(['z' => $novyZust, 's' => $novyStav, 'id' => $v['id']]);
        $pdo->prepare("INSERT INTO voucher_pohyby (voucher_id, typ, castka, zustatek_po, doklad, kdo) VALUES (:v,'uplatneni',:c,:z,:dok,:kdo)")
            ->execute(['v' => $v['id'], 'c' => -$uplatneno, 'z' => $novyZust, 'dok' => $doklad, 'kdo' => $admin]);
        $pdo->commit();
        json_response([
            'ok' => true, 'kod' => $kod, 'typ' => $v['typ'],
            'uplatneno' => round($uplatneno, 2),
            'zbytek_voucheru' => $novyZust,
            'vyrovnano' => ($uplatneno >= $castka - 0.001), // pokrylo celou požadovanou částku?
        ]);
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        json_error_safe('Uplatnění selhalo', $e, 500);
    }
}

// ── TOPUP (dobití) ────────────────────────────────────────────────────
if ($action === 'topup') {
    $castka = round((float) ($d['castka'] ?? 0), 2);
    if ($castka <= 0) json_error('Částka musí být kladná', 400);
    $id  = (int) ($d['id'] ?? 0);
    $kod = strtoupper(trim((string) ($d['kod'] ?? '')));
    $pdo->beginTransaction();
    try {
        $st = $pdo->prepare($id ? "SELECT * FROM vouchery WHERE id=:k FOR UPDATE" : "SELECT * FROM vouchery WHERE kod=:k FOR UPDATE");
        $st->execute(['k' => $id ?: $kod]);
        $v = $st->fetch(PDO::FETCH_ASSOC);
        if (!$v) { $pdo->rollBack(); json_error('Voucher nenalezen', 404); }
        if ($v['stav'] === 'zruseny') { $pdo->rollBack(); json_error('Zrušený voucher nelze dobít', 409); }
        $novyZust = round((float) $v['zustatek'] + $castka, 2);
        $pdo->prepare("UPDATE vouchery SET zustatek=:z, hodnota=hodnota+:c, stav='aktivni' WHERE id=:id")
            ->execute(['z' => $novyZust, 'c' => $castka, 'id' => $v['id']]);
        $pdo->prepare("INSERT INTO voucher_pohyby (voucher_id, typ, castka, zustatek_po, kdo) VALUES (:v,'dobiti',:c,:z,:kdo)")
            ->execute(['v' => $v['id'], 'c' => $castka, 'z' => $novyZust, 'kdo' => $admin]);
        $pdo->commit();
        json_response(['ok' => true, 'zustatek' => $novyZust]);
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        json_error_safe('Dobití selhalo', $e, 500);
    }
}

// ── DEACTIVATE ────────────────────────────────────────────────────────
if ($action === 'deactivate') {
    $id = (int) ($d['id'] ?? 0);
    if (!$id) json_error('Chybí id', 400);
    $pdo->prepare("UPDATE vouchery SET stav='zruseny' WHERE id=:id")->execute(['id' => $id]);
    json_response(['ok' => true]);
}

json_error('Neznámá akce', 400);
