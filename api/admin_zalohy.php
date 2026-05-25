<?php
/**
 * Router pro zálohy databáze.
 * Veškerá logika v _zaloha_helper.php (load níže).
 *
 * GET  ?action=list                — vrátí seznam záloh
 * POST ?action=create              — body: { include_uploads:bool, label:string, typ:'manual'|'snapshot'|'auto' }
 * GET  ?action=download&id=X       — stáhne ZIP soubor
 * DELETE ?id=X                     — smaže zálohu (super admin)
 * POST ?action=restore&id=X        — obnoví DB z dané zálohy (super admin)
 * GET  ?action=cron&token=XXX      — spustí auto-zálohu (pro CRON, bez session)
 * GET  ?action=info                — info o storage (volné místo, počet, poslední čas)
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/_admin_auth.php';
require_once __DIR__ . '/_zaloha_helper.php';
cors_headers();

$pdo = db();
$action = $_GET['action'] ?? '';
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

// =============================================================
// CRON endpoint — bez session, autentizuje se přes token v nastavení
// =============================================================
if ($action === 'cron') {
    $token_request = $_GET['token'] ?? '';
    $token_saved   = nastaveni_get($pdo, 'zaloha_cron_token', '');
    if (empty($token_saved) || $token_request !== $token_saved) {
        json_error('Neplatný CRON token', 403);
    }
    try {
        zaloha_setup($pdo);
        $result = zaloha_vytvor($pdo, [
            'typ'             => 'auto',
            'label'           => 'CRON denní záloha',
            'include_uploads' => false,
        ]);
        zaloha_rotace($pdo);
        json_response(['ok' => true, 'zaloha' => $result]);
    } catch (Throwable $e) {
        error_log('zaloha CRON: ' . $e->getMessage());
        json_error_safe('CRON selhal', $e, 500);
    }
}

// Pro všechno ostatní vyžaduj admin login
require_admin();
zaloha_setup($pdo);

// =============================================================
// ROUTER
// =============================================================
if ($action === 'list') {
    $stmt = $pdo->query("
        SELECT id, soubor, typ, label, velikost, tabulek, zaznamu,
               include_uploads, vytvoreno, vytvoril
        FROM zalohy
        ORDER BY vytvoreno DESC
        LIMIT 200
    ");
    $rows = $stmt->fetchAll();
    $celkova_velikost = 0;
    foreach ($rows as $r) $celkova_velikost += (int) $r['velikost'];

    json_response([
        'zalohy' => $rows,
        'celkova_velikost' => $celkova_velikost,
        'pocet' => count($rows),
    ]);
}

if ($action === 'info') {
    $dir = __DIR__ . '/../zalohy';
    $free  = @disk_free_space($dir) ?: 0;
    $total = @disk_total_space($dir) ?: 0;
    $pocet = (int) $pdo->query("SELECT COUNT(*) FROM zalohy")->fetchColumn();
    $velikost = (int) $pdo->query("SELECT COALESCE(SUM(velikost),0) FROM zalohy")->fetchColumn();
    $posledni = $pdo->query("
        SELECT vytvoreno, typ FROM zalohy ORDER BY vytvoreno DESC LIMIT 1
    ")->fetch();
    $token = nastaveni_get($pdo, 'zaloha_cron_token', '');
    if (empty($token)) {
        // Vygeneruj nový token
        $token = bin2hex(random_bytes(16));
        $pdo->prepare("INSERT INTO nastaveni (klic, hodnota) VALUES ('zaloha_cron_token', :v)
                       ON DUPLICATE KEY UPDATE hodnota = :v2")
            ->execute(['v' => $token, 'v2' => $token]);
    }
    $base_url = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' ? 'https' : 'http')
              . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost');
    json_response([
        'pocet'             => $pocet,
        'celkova_velikost'  => $velikost,
        'disk_free'         => (int) $free,
        'disk_total'        => (int) $total,
        'posledni'          => $posledni,
        'cron_url'          => $base_url . '/api/admin_zalohy.php?action=cron&token=' . $token,
    ]);
}

if ($action === 'create' && $method === 'POST') {
    $body = json_input();
    $opts = [
        'typ'             => $body['typ'] ?? 'manual',
        'label'           => $body['label'] ?? null,
        'include_uploads' => !empty($body['include_uploads']),
        'vytvoril'        => $_SESSION['admin_jmeno'] ?? 'admin',
    ];
    if (!in_array($opts['typ'], ['manual', 'snapshot', 'auto'], true)) $opts['typ'] = 'manual';

    try {
        $result = zaloha_vytvor($pdo, $opts);
        zaloha_rotace($pdo);
        json_response($result);
    } catch (Throwable $e) {
        json_error_safe('Vytvoření zálohy selhalo', $e, 500);
    }
}

if ($action === 'download') {
    $id = (int) ($_GET['id'] ?? 0);
    $z = $pdo->prepare("SELECT * FROM zalohy WHERE id = :id");
    $z->execute(['id' => $id]);
    $z = $z->fetch();
    if (!$z) json_error('Záloha nenalezena', 404);
    $path = __DIR__ . '/../zalohy/' . $z['soubor'];
    if (!file_exists($path)) json_error('Soubor zálohy neexistuje na disku', 404);

    header('Content-Type: application/zip');
    header('Content-Disposition: attachment; filename="' . $z['soubor'] . '"');
    header('Content-Length: ' . filesize($path));
    header('X-Content-Type-Options: nosniff');
    readfile($path);
    exit;
}

if ($action === 'restore' && $method === 'POST') {
    $id = (int) ($_GET['id'] ?? 0);
    $body = json_input();
    if (empty($body['potvrzeni']) || $body['potvrzeni'] !== 'OBNOVIT') {
        json_error('Pro obnovu pošli body { "potvrzeni": "OBNOVIT" }', 400);
    }
    try {
        $r = zaloha_obnov($pdo, $id);
        json_response($r);
    } catch (Throwable $e) {
        json_error_safe('Obnova selhala', $e, 500);
    }
}

if ($method === 'DELETE') {
    require_super_admin();
    $id = (int) ($_GET['id'] ?? 0);
    $z = $pdo->prepare("SELECT soubor FROM zalohy WHERE id = :id");
    $z->execute(['id' => $id]);
    $soubor = $z->fetchColumn();
    if (!$soubor) json_error('Záloha nenalezena', 404);

    $path = __DIR__ . '/../zalohy/' . $soubor;
    if (file_exists($path)) @unlink($path);
    $pdo->prepare("DELETE FROM zalohy WHERE id = :id")->execute(['id' => $id]);
    json_response(['ok' => true]);
}

json_error('Neznámá akce. Použij action=list|info|create|download|restore|cron');
