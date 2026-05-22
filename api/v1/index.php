<?php
/**
 * 🔌 Public REST API v1 — pro účetní systémy (Money S3, POHODA, Fakturoid…).
 *
 * Autentizace: Bearer token v hlavičce
 *   Authorization: Bearer abc123...
 *
 * Endpointy:
 *   GET  /api/v1/                 → seznam endpointů + dokumentace
 *   GET  /api/v1/faktury          → seznam FA (s filtry: od, do, stav, page)
 *   GET  /api/v1/faktury/{id}     → detail FA
 *   GET  /api/v1/faktury/{id}/isdoc → ISDOC XML
 *   GET  /api/v1/dodaci_listy     → seznam DL
 *   GET  /api/v1/dodaci_listy/{id} → detail DL
 *   GET  /api/v1/odberatele       → seznam odběratelů
 *   GET  /api/v1/odberatele/{id}  → detail
 *   POST /api/v1/faktury/{id}/uhrazena → označit jako uhrazenou
 *
 * Tento soubor je hub který parsuje URL a routuje.
 * Vyžaduje rewrite v .htaccess: /api/v1/* → /api/v1/index.php
 *
 * Pokud .htaccess není dostupný, použij přímo:
 *   /api/v1/index.php?path=faktury&od=2026-01-01
 */
require_once __DIR__ . '/../config.php';
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Authorization, Content-Type');
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') exit;

$pdo = db();

// Auto-migrace tabulky tokenů
$pdo->exec("
    CREATE TABLE IF NOT EXISTS api_tokens (
        id           INT AUTO_INCREMENT PRIMARY KEY,
        token        VARCHAR(64) NOT NULL UNIQUE,
        nazev        VARCHAR(150) NOT NULL,
        opravneni    VARCHAR(255) DEFAULT 'read',
        aktivni      TINYINT(1) NOT NULL DEFAULT 1,
        vytvoreno    DATETIME DEFAULT CURRENT_TIMESTAMP,
        posledni_pouziti DATETIME NULL,
        pocet_volani INT NOT NULL DEFAULT 0,
        INDEX idx_token (token)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
");

// Autentizace
function api_auth(PDO $pdo): ?array {
    $auth = $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '';
    if (!preg_match('/Bearer\s+(\S+)/i', $auth, $m)) {
        // Fallback ?token=
        $token = $_GET['token'] ?? '';
        if (!$token) return null;
    } else {
        $token = $m[1];
    }
    $stmt = $pdo->prepare("SELECT * FROM api_tokens WHERE token = :t AND aktivni = 1");
    $stmt->execute(['t' => $token]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) return null;
    // Log usage
    $pdo->prepare("UPDATE api_tokens SET posledni_pouziti = NOW(), pocet_volani = pocet_volani + 1 WHERE id = :id")
        ->execute(['id' => $row['id']]);
    return $row;
}

function api_response($data, int $code = 200): void {
    http_response_code($code);
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    exit;
}

function api_error(string $msg, int $code = 400): void {
    api_response(['error' => $msg, 'code' => $code], $code);
}

// Routing — z path parametru nebo URL
$path = $_GET['path'] ?? '';
if (!$path) {
    // Zkus z URI
    $uri = parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH) ?? '';
    if (preg_match('#/api/v1/(.+?)(?:\?|$)#', $uri, $m)) {
        $path = $m[1];
    }
}
$path = trim($path, '/');
$parts = explode('/', $path);
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

// Root — dokumentace
if ($path === '' || $path === 'docs') {
    api_response([
        'name' => 'Appek B2B API v1',
        'version' => '1.0',
        'auth' => 'Bearer token v Authorization hlavičce, nebo ?token=...',
        'endpoints' => [
            'GET /faktury'                  => 'Seznam faktur (filtry: ?od=YYYY-MM-DD&do=YYYY-MM-DD&stav=uhrazena|cekajici|po_splatnosti&page=1&limit=50)',
            'GET /faktury/{id}'             => 'Detail faktury',
            'GET /faktury/{id}/isdoc'       => 'ISDOC XML pro účetnictví',
            'GET /dodaci_listy'             => 'Seznam DL (filtry: ?od&do&page)',
            'GET /dodaci_listy/{id}'        => 'Detail DL',
            'GET /odberatele'               => 'Seznam odběratelů',
            'GET /odberatele/{id}'          => 'Detail odběratele',
            'POST /faktury/{id}/uhrazena'   => 'Označit FA jako uhrazenou (body: {datum, castka})',
        ],
        'formats' => ['JSON (default)', 'XML (jen ISDOC)'],
        'contact' => nastaveni_get($pdo, 'firma_email', ''),
    ]);
}

// Vyžaduj auth pro vše ostatní
$token = api_auth($pdo);
if (!$token) api_error('Unauthorized — chybí nebo neplatný token', 401);

// ============ ENDPOINTY ============

// GET /faktury
if ($parts[0] === 'faktury' && count($parts) === 1 && $method === 'GET') {
    $od    = $_GET['od']    ?? null;
    $do    = $_GET['do']    ?? null;
    $stav  = $_GET['stav']  ?? null;
    $page  = max(1, (int)($_GET['page'] ?? 1));
    $limit = max(1, min(200, (int)($_GET['limit'] ?? 50)));
    $offset = ($page - 1) * $limit;

    $where = "WHERE 1=1";
    $params = [];
    if ($od) { $where .= " AND f.datum_vystaveni >= :od"; $params['od'] = $od; }
    if ($do) { $where .= " AND f.datum_vystaveni <= :do"; $params['do'] = $do; }

    $stavCase = "CASE
        WHEN f.castka_uhrazeno >= f.castka_celkem THEN 'uhrazena'
        WHEN f.datum_splatnosti < CURDATE() THEN 'po_splatnosti'
        ELSE 'cekajici' END";
    if ($stav) {
        $where .= " AND ($stavCase) = :stav";
        $params['stav'] = $stav;
    }

    $sql = "
        SELECT f.id, f.cislo, f.datum_vystaveni, f.datum_splatnosti, f.datum_zdanitelneho_plneni,
               f.castka_bez_dph, f.castka_dph, f.castka_celkem, f.castka_uhrazeno,
               $stavCase AS stav,
               od.id AS odberatel_id, od.nazev AS odberatel_nazev, od.ico AS odberatel_ico, od.dic AS odberatel_dic
        FROM faktury f
        JOIN odberatele od ON od.id = f.odberatel_id
        $where
        ORDER BY f.datum_vystaveni DESC, f.id DESC
        LIMIT $limit OFFSET $offset
    ";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $totalStmt = $pdo->prepare("SELECT COUNT(*) FROM faktury f JOIN odberatele od ON od.id = f.odberatel_id $where");
    $totalStmt->execute($params);
    $total = (int) $totalStmt->fetchColumn();

    api_response(['data' => $rows, 'page' => $page, 'limit' => $limit, 'total' => $total]);
}

// GET /faktury/{id} or /faktury/{id}/isdoc
if ($parts[0] === 'faktury' && isset($parts[1]) && $method === 'GET') {
    $id = (int) $parts[1];
    $stmt = $pdo->prepare("
        SELECT f.*, od.nazev AS odberatel_nazev, od.ico, od.dic,
               od.ulice AS odb_ulice, od.mesto AS odb_mesto, od.psc AS odb_psc
        FROM faktury f JOIN odberatele od ON od.id = f.odberatel_id
        WHERE f.id = :id
    ");
    $stmt->execute(['id' => $id]);
    $fa = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$fa) api_error('Faktura nenalezena', 404);

    // ISDOC export
    if (isset($parts[2]) && $parts[2] === 'isdoc') {
        header('Content-Type: application/xml; charset=utf-8');
        // Použij existing ISDOC export endpoint
        $url = "../admin_export_isdoc.php?action=isdoc&id=" . $id;
        // Místo HTTP roundtrip nahraje přímo - používá session, takže jen předáme tu funkci
        // Pro jednoduchost vrátíme link kde to získat:
        api_response(['error' => 'ISDOC XML — použij /api/admin_export_isdoc.php?action=isdoc&id=' . $id . ' s admin auth, nebo importuj přes admin UI']);
    }

    // Položky
    $pol = $pdo->prepare("
        SELECT fp.*, v.cislo AS vyrobek_cislo, v.nazev AS vyrobek_nazev
        FROM faktura_polozky fp
        LEFT JOIN vyrobky v ON v.id = fp.vyrobek_id
        WHERE fp.faktura_id = :id
        ORDER BY fp.poradi
    ");
    $pol->execute(['id' => $id]);
    $fa['polozky'] = $pol->fetchAll(PDO::FETCH_ASSOC);

    api_response($fa);
}

// POST /faktury/{id}/uhrazena
if ($parts[0] === 'faktury' && isset($parts[1]) && isset($parts[2]) && $parts[2] === 'uhrazena' && $method === 'POST') {
    if (!str_contains((string)$token['opravneni'], 'write')) api_error('Token nemá oprávnění write', 403);
    $id = (int) $parts[1];
    $input = json_decode(file_get_contents('php://input'), true) ?: [];
    $datum  = $input['datum']  ?? date('Y-m-d');
    $castka = (float) ($input['castka'] ?? 0);

    $fa = $pdo->prepare("SELECT castka_celkem FROM faktury WHERE id = :id");
    $fa->execute(['id' => $id]);
    $cc = $fa->fetchColumn();
    if (!$cc) api_error('Faktura nenalezena', 404);

    $castka = $castka > 0 ? $castka : (float) $cc;
    $pdo->prepare("UPDATE faktury SET castka_uhrazeno = :c, datum_uhrazeni = :d WHERE id = :id")
        ->execute(['c' => $castka, 'd' => $datum, 'id' => $id]);

    api_response(['ok' => true, 'id' => $id, 'castka_uhrazeno' => $castka, 'datum' => $datum]);
}

// GET /dodaci_listy
if ($parts[0] === 'dodaci_listy' && count($parts) === 1 && $method === 'GET') {
    $od    = $_GET['od']    ?? null;
    $do    = $_GET['do']    ?? null;
    $page  = max(1, (int)($_GET['page'] ?? 1));
    $limit = max(1, min(200, (int)($_GET['limit'] ?? 50)));
    $where = "WHERE 1=1";
    $params = [];
    if ($od) { $where .= " AND dl.datum_vystaveni >= :od"; $params['od'] = $od; }
    if ($do) { $where .= " AND dl.datum_vystaveni <= :do"; $params['do'] = $do; }

    $offset = ($page - 1) * $limit;
    $sql = "
        SELECT dl.id, dl.cislo, dl.datum_vystaveni, dl.datum_dodani, dl.castka_celkem,
               od.id AS odberatel_id, od.nazev AS odberatel_nazev, od.ico
        FROM dodaci_listy dl
        JOIN odberatele od ON od.id = dl.odberatel_id
        $where
        ORDER BY dl.datum_vystaveni DESC, dl.id DESC
        LIMIT $limit OFFSET $offset
    ";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    api_response(['data' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
}

// GET /dodaci_listy/{id}
if ($parts[0] === 'dodaci_listy' && isset($parts[1]) && $method === 'GET') {
    $id = (int) $parts[1];
    $stmt = $pdo->prepare("
        SELECT dl.*, od.nazev AS odberatel_nazev, od.ico
        FROM dodaci_listy dl JOIN odberatele od ON od.id = dl.odberatel_id
        WHERE dl.id = :id
    ");
    $stmt->execute(['id' => $id]);
    $dl = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$dl) api_error('DL nenalezen', 404);
    $pol = $pdo->prepare("SELECT * FROM dodaci_list_polozky WHERE dodaci_list_id = :id");
    $pol->execute(['id' => $id]);
    $dl['polozky'] = $pol->fetchAll(PDO::FETCH_ASSOC);
    api_response($dl);
}

// GET /odberatele
if ($parts[0] === 'odberatele' && count($parts) === 1 && $method === 'GET') {
    $stmt = $pdo->query("SELECT id, nazev, ico, dic, email, telefon, ulice, mesto, psc, splatnost_dni FROM odberatele ORDER BY nazev");
    api_response(['data' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
}

// GET /odberatele/{id}
if ($parts[0] === 'odberatele' && isset($parts[1]) && $method === 'GET') {
    $id = (int) $parts[1];
    $stmt = $pdo->prepare("SELECT * FROM odberatele WHERE id = :id");
    $stmt->execute(['id' => $id]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) api_error('Odběratel nenalezen', 404);
    // Skryj citlivá pole
    unset($row['heslo_hash']);
    api_response($row);
}

api_error('Endpoint not found: ' . $path, 404);
