<?php
/**
 * 📊 ADMIN POHODA — Endpoints pro správu Pohoda mServer integrace.
 *
 * Akce:
 *   GET  ?action=settings              → načte aktuální config
 *   POST ?action=save_settings         → uloží config (URL, credentials, IČO)
 *   GET  ?action=test                  → otestuj spojení
 *   POST ?action=export_faktura        → export 1 faktury (id v body) do Pohoda
 *   POST ?action=export_faktura_bulk   → bulk export (ids v body)
 *   POST ?action=export_partner        → export odběratele
 *   GET  ?action=import_prijate&from=&to= → import přijatých faktur z Pohoda
 *   GET  ?action=audit_log             → log posledních exportů (sync history)
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/_admin_auth.php';
require_once __DIR__ . '/_pohoda.php';

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

// Schema for sync log
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

function log_sync(PDO $pdo, string $system, string $action, array $result, ?int $targetId = null, ?string $targetRef = null, ?string $targetType = null): void {
    try {
        $by = $_SESSION['admin_jmeno'] ?? ($_SESSION['admin_email'] ?? 'admin');
        $pdo->prepare("
            INSERT INTO accounting_sync_log (system, action, target_type, target_id, target_ref, success, message, response_xml, created_by)
            VALUES (:s, :a, :tt, :ti, :tr, :ok, :m, :r, :by)
        ")->execute([
            's'  => $system,
            'a'  => $action,
            'tt' => $targetType,
            'ti' => $targetId,
            'tr' => $targetRef,
            'ok' => !empty($result['ok']) ? 1 : 0,
            'm'  => $result['error'] ?? $result['message'] ?? null,
            'r'  => substr((string) ($result['response_xml'] ?? ''), 0, 65535),
            'by' => $by,
        ]);
    } catch (Throwable $e) { /* ignore */ }
}

// ─── GET settings
if ($method === 'GET' && $action === 'settings') {
    $cfg = pohoda_settings();
    // Skryj heslo
    if (!empty($cfg['pohoda_password'])) {
        $cfg['pohoda_password'] = str_repeat('•', 8);
        $cfg['pohoda_password_set'] = true;
    } else {
        $cfg['pohoda_password_set'] = false;
    }
    json_response($cfg);
}

// ─── POST save_settings
if ($method === 'POST' && $action === 'save_settings') {
    require_super_admin();
    $d = json_input();
    $keys = ['enabled','url','username','password','ico','strediska','cinnost'];
    foreach ($keys as $k) {
        if (array_key_exists($k, $d)) {
            // Heslo: nepřepisuj pokud je placeholder (••••••••)
            if ($k === 'password' && (str_contains($d[$k], '•') || $d[$k] === '')) continue;
            nastaveni_set($pdo, 'pohoda_' . $k, (string) $d[$k]);
        }
    }
    // Vymaž cache
    json_response(['ok' => true]);
}

// ─── GET test connection
if ($method === 'GET' && $action === 'test') {
    $r = pohoda_test_connection();
    log_sync($pdo, 'pohoda', 'test', $r);
    json_response($r);
}

// ─── POST export_faktura
if ($method === 'POST' && $action === 'export_faktura') {
    $d = json_input();
    $faId = (int) ($d['id'] ?? 0);
    if (!$faId) json_error('Chybí ID faktury', 400);

    $fa = $pdo->prepare("
        SELECT f.*, o.nazev AS odb_nazev, o.ulice AS odb_ulice, o.mesto AS odb_mesto, o.psc AS odb_psc, o.ic AS odb_ic, o.dic AS odb_dic
        FROM faktury f
        LEFT JOIN odberatele o ON o.id = f.odberatel_id
        WHERE f.id = :id LIMIT 1
    ");
    $fa->execute(['id' => $faId]);
    $faktura = $fa->fetch();
    if (!$faktura) json_error('Faktura nenalezena', 404);

    $pol = $pdo->prepare("SELECT * FROM faktury_polozky WHERE faktura_id = :id ORDER BY id");
    $pol->execute(['id' => $faId]);
    $polozky = $pol->fetchAll();

    $payload = [
        'cislo'              => $faktura['cislo'],
        'datum_vystaveni'    => $faktura['datum_vystaveni'],
        'datum_splatnosti'   => $faktura['datum_splatnosti'],
        'datum_zd_plneni'    => $faktura['datum_zd_plneni'] ?? $faktura['datum_vystaveni'],
        'celkem_bez_dph'     => $faktura['celkem_bez_dph'],
        'celkem_dph'         => $faktura['celkem_dph'],
        'celkem_s_dph'       => $faktura['celkem_s_dph'],
        'poznamka'           => $faktura['poznamka'] ?? '',
        'cislo_objednavky'   => $faktura['cislo_objednavky'] ?? '',
        'odberatel'          => [
            'nazev'  => $faktura['odb_nazev']  ?? $faktura['odberatel_nazev'] ?? '',
            'ulice'  => $faktura['odb_ulice']  ?? $faktura['odberatel_ulice'] ?? '',
            'mesto'  => $faktura['odb_mesto']  ?? $faktura['odberatel_mesto'] ?? '',
            'psc'    => $faktura['odb_psc']    ?? $faktura['odberatel_psc']   ?? '',
            'ic'     => $faktura['odb_ic']     ?? $faktura['odberatel_ic']    ?? '',
            'dic'    => $faktura['odb_dic']    ?? $faktura['odberatel_dic']   ?? '',
        ],
        'polozky'            => array_map(fn($p) => [
            'nazev'        => $p['nazev'],
            'mnozstvi'     => $p['mnozstvi'],
            'jednotka'     => $p['jednotka'] ?? 'ks',
            'cena_bez_dph' => $p['cena_bez_dph'] ?? $p['cena_jednotkova'] ?? 0,
            'dph_pct'      => $p['dph_pct'] ?? 21,
        ], $polozky),
    ];

    $r = pohoda_export_faktura($payload);
    log_sync($pdo, 'pohoda', 'export_faktura', $r, $faId, $faktura['cislo'], 'faktura');
    json_response($r);
}

// ─── POST export_faktura_bulk
if ($method === 'POST' && $action === 'export_faktura_bulk') {
    $d = json_input();
    $ids = $d['ids'] ?? [];
    if (!is_array($ids) || empty($ids)) json_error('Chybí ids', 400);
    $results = [];
    $ok = 0; $fail = 0;
    foreach ($ids as $faId) {
        $_GET['action'] = 'export_faktura';
        // Pseudo-recursive call — emulate by directly building payload
        // Toto je jen lehký wrapper, plnou logiku má export_faktura, použijeme replikaci:
        $faId = (int) $faId;
        $fa = $pdo->prepare("SELECT f.*, o.nazev AS odb_nazev, o.ulice AS odb_ulice, o.mesto AS odb_mesto, o.psc AS odb_psc, o.ic AS odb_ic, o.dic AS odb_dic FROM faktury f LEFT JOIN odberatele o ON o.id = f.odberatel_id WHERE f.id = :id LIMIT 1");
        $fa->execute(['id' => $faId]);
        $faktura = $fa->fetch();
        if (!$faktura) { $results[$faId] = ['ok' => false, 'error' => 'not_found']; $fail++; continue; }
        $pol = $pdo->prepare("SELECT * FROM faktury_polozky WHERE faktura_id = :id ORDER BY id");
        $pol->execute(['id' => $faId]);
        $polozky = $pol->fetchAll();
        $payload = [
            'cislo' => $faktura['cislo'], 'datum_vystaveni' => $faktura['datum_vystaveni'],
            'datum_splatnosti' => $faktura['datum_splatnosti'], 'datum_zd_plneni' => $faktura['datum_zd_plneni'] ?? $faktura['datum_vystaveni'],
            'celkem_bez_dph' => $faktura['celkem_bez_dph'], 'celkem_dph' => $faktura['celkem_dph'], 'celkem_s_dph' => $faktura['celkem_s_dph'],
            'poznamka' => $faktura['poznamka'] ?? '', 'cislo_objednavky' => $faktura['cislo_objednavky'] ?? '',
            'odberatel' => [
                'nazev' => $faktura['odb_nazev'] ?? '', 'ulice' => $faktura['odb_ulice'] ?? '',
                'mesto' => $faktura['odb_mesto'] ?? '', 'psc' => $faktura['odb_psc'] ?? '',
                'ic' => $faktura['odb_ic'] ?? '', 'dic' => $faktura['odb_dic'] ?? '',
            ],
            'polozky' => array_map(fn($p) => [
                'nazev' => $p['nazev'], 'mnozstvi' => $p['mnozstvi'], 'jednotka' => $p['jednotka'] ?? 'ks',
                'cena_bez_dph' => $p['cena_bez_dph'] ?? $p['cena_jednotkova'] ?? 0, 'dph_pct' => $p['dph_pct'] ?? 21,
            ], $polozky),
        ];
        $r = pohoda_export_faktura($payload);
        log_sync($pdo, 'pohoda', 'export_faktura', $r, $faId, $faktura['cislo'], 'faktura');
        $results[$faId] = ['ok' => $r['ok'], 'error' => $r['error'] ?? null];
        if ($r['ok']) $ok++; else $fail++;
    }
    json_response(['ok' => true, 'success_count' => $ok, 'fail_count' => $fail, 'results' => $results]);
}

// ─── POST export_partner
if ($method === 'POST' && $action === 'export_partner') {
    $d = json_input();
    $oId = (int) ($d['id'] ?? 0);
    if (!$oId) json_error('Chybí ID', 400);
    $st = $pdo->prepare("SELECT * FROM odberatele WHERE id = :id");
    $st->execute(['id' => $oId]);
    $o = $st->fetch();
    if (!$o) json_error('Odběratel nenalezen', 404);
    $r = pohoda_export_partner([
        'nazev'   => $o['nazev'] ?? '',
        'ulice'   => $o['ulice'] ?? '',
        'mesto'   => $o['mesto'] ?? '',
        'psc'     => $o['psc'] ?? '',
        'ic'      => $o['ic'] ?? '',
        'dic'     => $o['dic'] ?? '',
        'email'   => $o['email'] ?? '',
        'telefon' => $o['telefon'] ?? '',
        'note'    => 'APPEK B2B sync',
    ]);
    log_sync($pdo, 'pohoda', 'export_partner', $r, $oId, $o['nazev'] ?? null, 'partner');
    json_response($r);
}

// ─── GET import_prijate
if ($method === 'GET' && $action === 'import_prijate') {
    $from = $_GET['from'] ?? date('Y-m-01');
    $to   = $_GET['to']   ?? date('Y-m-d');
    $r = pohoda_import_prijate_fa($from, $to);
    log_sync($pdo, 'pohoda', 'import_prijate', $r, null, "$from / $to", 'invoice_list');
    json_response($r);
}

// ─── GET audit_log
if ($method === 'GET' && $action === 'audit_log') {
    $rows = $pdo->query("
        SELECT * FROM accounting_sync_log
        WHERE system = 'pohoda'
        ORDER BY created_at DESC
        LIMIT 100
    ")->fetchAll();
    json_response(['log' => $rows]);
}

json_error('Neznámá akce: ' . $action, 404);
