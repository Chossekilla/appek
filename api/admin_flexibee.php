<?php
/**
 * 🐝 ADMIN FLEXIBEE — Endpointy pro správu FlexiBee REST API integrace.
 *
 * GET  ?action=settings              → načte config
 * POST ?action=save_settings         → uloží config
 * GET  ?action=test                  → test spojení
 * POST ?action=export_faktura        → export FA
 * POST ?action=export_faktura_bulk   → bulk
 * POST ?action=export_partner        → export odběratele
 * POST ?action=export_vyrobek        → export ceníkové položky
 * GET  ?action=import_prijate&from=&to= → načti přijaté FA
 * GET  ?action=audit_log
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/_admin_auth.php';
require_once __DIR__ . '/_flexibee.php';

cors_headers();
require_admin();
header('Content-Type: application/json; charset=UTF-8');

$pdo = db();

// Helper: upsert do nastaveni
if (!function_exists('nastaveni_set')) {
    function nastaveni_set(PDO $pdo, string $klic, string $hodnota): void {
        $pdo->prepare("INSERT INTO nastaveni (klic, hodnota) VALUES (:k, :v) ON DUPLICATE KEY UPDATE hodnota = :v2")
            ->execute(['k' => $klic, 'v' => $hodnota, 'v2' => $hodnota]);
    }
}
$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? '';

// Reuse accounting_sync_log schema (vytvoří admin_pohoda.php pokud chybí)
$pdo->exec("
    CREATE TABLE IF NOT EXISTS accounting_sync_log (
        id INT AUTO_INCREMENT PRIMARY KEY,
        system ENUM('pohoda','flexibee','money_s3','helios','other') NOT NULL DEFAULT 'pohoda',
        action VARCHAR(40) NOT NULL,
        target_type VARCHAR(40) NULL,
        target_id INT NULL,
        target_ref VARCHAR(80) NULL,
        success TINYINT(1) DEFAULT 0,
        message TEXT NULL,
        request_xml_len INT NULL,
        response_xml LONGTEXT NULL,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        created_by VARCHAR(120) NULL,
        INDEX idx_system (system),
        INDEX idx_action (action),
        INDEX idx_created (created_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
");

function fbee_log(PDO $pdo, string $action, array $result, ?int $targetId = null, ?string $targetRef = null, ?string $targetType = null): void {
    try {
        $by = $_SESSION['admin_jmeno'] ?? ($_SESSION['admin_email'] ?? 'admin');
        $pdo->prepare("
            INSERT INTO accounting_sync_log (system, action, target_type, target_id, target_ref, success, message, response_xml, created_by)
            VALUES ('flexibee', :a, :tt, :ti, :tr, :ok, :m, :r, :by)
        ")->execute([
            'a' => $action, 'tt' => $targetType, 'ti' => $targetId, 'tr' => $targetRef,
            'ok' => !empty($result['ok']) ? 1 : 0,
            'm' => $result['error'] ?? $result['message'] ?? null,
            'r' => json_encode($result['data'] ?? $result['response'] ?? null, JSON_UNESCAPED_UNICODE),
            'by' => $by,
        ]);
    } catch (Throwable $e) { /* ignore */ }
}

// ─── GET settings
if ($method === 'GET' && $action === 'settings') {
    $cfg = flexibee_settings();
    if (!empty($cfg['flexibee_password'])) {
        $cfg['flexibee_password'] = str_repeat('•', 8);
        $cfg['flexibee_password_set'] = true;
    } else {
        $cfg['flexibee_password_set'] = false;
    }
    json_response($cfg);
}

// ─── POST save_settings
if ($method === 'POST' && $action === 'save_settings') {
    require_super_admin();
    $d = json_input();
    $keys = ['enabled','url','company','username','password'];
    foreach ($keys as $k) {
        if (array_key_exists($k, $d)) {
            if ($k === 'password' && (str_contains($d[$k], '•') || $d[$k] === '')) continue;
            nastaveni_set($pdo, 'flexibee_' . $k, (string) $d[$k]);
        }
    }
    json_response(['ok' => true]);
}

// ─── GET test
if ($method === 'GET' && $action === 'test') {
    $r = flexibee_test_connection();
    fbee_log($pdo, 'test', $r);
    json_response($r);
}

// ─── POST export_faktura
if ($method === 'POST' && $action === 'export_faktura') {
    $d = json_input();
    $faId = (int) ($d['id'] ?? 0);
    if (!$faId) json_error('Chybí ID', 400);

    $fa = $pdo->prepare("
        SELECT f.*, o.nazev AS odb_nazev, o.ulice AS odb_ulice, o.mesto AS odb_mesto, o.psc AS odb_psc, o.ic AS odb_ic, o.dic AS odb_dic
        FROM faktury f LEFT JOIN odberatele o ON o.id = f.odberatel_id
        WHERE f.id = :id LIMIT 1
    ");
    $fa->execute(['id' => $faId]);
    $faktura = $fa->fetch();
    if (!$faktura) json_error('Faktura nenalezena', 404);

    $pol = $pdo->prepare("SELECT * FROM faktury_polozky WHERE faktura_id = :id ORDER BY id");
    $pol->execute(['id' => $faId]);
    $polozky = $pol->fetchAll();

    $r = flexibee_export_faktura([
        'cislo' => $faktura['cislo'],
        'datum_vystaveni' => $faktura['datum_vystaveni'],
        'datum_splatnosti' => $faktura['datum_splatnosti'],
        'datum_zd_plneni' => $faktura['datum_zd_plneni'] ?? $faktura['datum_vystaveni'],
        'celkem_bez_dph' => $faktura['celkem_bez_dph'],
        'celkem_dph' => $faktura['celkem_dph'],
        'celkem_s_dph' => $faktura['celkem_s_dph'],
        'poznamka' => $faktura['poznamka'] ?? '',
        'odberatel' => [
            'nazev' => $faktura['odb_nazev'] ?? '',
            'ulice' => $faktura['odb_ulice'] ?? '',
            'mesto' => $faktura['odb_mesto'] ?? '',
            'psc' => $faktura['odb_psc'] ?? '',
            'ic' => $faktura['odb_ic'] ?? '',
            'dic' => $faktura['odb_dic'] ?? '',
        ],
        'polozky' => array_map(fn($p) => [
            'nazev' => $p['nazev'],
            'mnozstvi' => $p['mnozstvi'],
            'jednotka' => $p['jednotka'] ?? 'ks',
            'cena_bez_dph' => $p['cena_bez_dph'] ?? $p['cena_jednotkova'] ?? 0,
            'dph_pct' => $p['dph_pct'] ?? 21,
        ], $polozky),
    ]);
    fbee_log($pdo, 'export_faktura', $r, $faId, $faktura['cislo'], 'faktura');
    json_response($r);
}

// ─── POST export_partner
if ($method === 'POST' && $action === 'export_partner') {
    $d = json_input();
    $oId = (int) ($d['id'] ?? 0);
    if (!$oId) json_error('Chybí ID', 400);
    $st = $pdo->prepare("SELECT * FROM odberatele WHERE id = :id");
    $st->execute(['id' => $oId]);
    $o = $st->fetch();
    if (!$o) json_error('Nenalezeno', 404);
    $r = flexibee_export_partner([
        'nazev' => $o['nazev'] ?? '',
        'ulice' => $o['ulice'] ?? '',
        'mesto' => $o['mesto'] ?? '',
        'psc' => $o['psc'] ?? '',
        'ic' => $o['ic'] ?? '',
        'dic' => $o['dic'] ?? '',
        'email' => $o['email'] ?? '',
        'telefon' => $o['telefon'] ?? '',
    ]);
    fbee_log($pdo, 'export_partner', $r, $oId, $o['nazev'] ?? null, 'partner');
    json_response($r);
}

// ─── POST export_vyrobek
if ($method === 'POST' && $action === 'export_vyrobek') {
    $d = json_input();
    $vId = (int) ($d['id'] ?? 0);
    if (!$vId) json_error('Chybí ID', 400);
    $st = $pdo->prepare("SELECT * FROM vyrobky WHERE id = :id");
    $st->execute(['id' => $vId]);
    $v = $st->fetch();
    if (!$v) json_error('Nenalezeno', 404);
    $r = flexibee_export_vyrobek([
        'nazev' => $v['nazev'] ?? '',
        'cislo' => $v['cislo'] ?? '',
        'cena_bez_dph' => $v['cena_bez_dph'] ?? 0,
        'popis' => $v['popis'] ?? '',
    ]);
    fbee_log($pdo, 'export_vyrobek', $r, $vId, $v['nazev'] ?? null, 'vyrobek');
    json_response($r);
}

// ─── GET import_prijate
if ($method === 'GET' && $action === 'import_prijate') {
    $from = $_GET['from'] ?? date('Y-m-01');
    $to   = $_GET['to']   ?? date('Y-m-d');
    $r = flexibee_list_prijate($from, $to);
    fbee_log($pdo, 'import_prijate', $r, null, "$from / $to", 'invoice_list');
    json_response($r);
}

// ─── GET audit_log
if ($method === 'GET' && $action === 'audit_log') {
    $rows = $pdo->query("
        SELECT * FROM accounting_sync_log
        WHERE system = 'flexibee'
        ORDER BY created_at DESC LIMIT 100
    ")->fetchAll();
    json_response(['log' => $rows]);
}

json_error('Neznámá akce: ' . $action, 404);
