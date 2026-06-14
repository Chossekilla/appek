<?php
/**
 * 🆕 v3.0.329 — Expediční zásilky (české přepravce). Vytvoření u přepravce + uložení + štítek.
 *   GET  ?action=carriers                         → seznam přepravců + zda zapnutý
 *   GET  ?action=list[&objednavka_id=N]           → uložené zásilky
 *   POST ?action=create {objednavka_id?, carrier, pickup_point_id?, weight_kg?, cod_kc?, recipient_*?}
 *   GET  ?action=label&id=N                        → štítek (PDF / redirect na label_url)
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/_admin_auth.php';
require_once __DIR__ . '/_shipping_lib.php';
require_admin();
$pdo = db();
$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? '';

if ($method === 'GET' && $action === 'carriers') {
    $out = [];
    foreach (shipping_supported_carriers() as $c) {
        $out[] = ['key' => $c, 'label' => shipping_carrier_label($c), 'enabled' => shipping_carrier_enabled($c)];
    }
    json_response(['ok' => true, 'carriers' => $out]);
}

// 🆕 v3.0.342 — meta pro předvyplnění expedičního dialogu (hmotnost = Σ produktů × množství)
if ($method === 'GET' && $action === 'order_meta') {
    $oid = (int) ($_GET['objednavka_id'] ?? 0);
    if (!$oid) json_error('Chybí objednavka_id', 400);
    $grams = 0.0; $maxDim = null;
    try {
        $w = $pdo->prepare("SELECT COALESCE(SUM(op.mnozstvi * COALESCE(v.hmotnost_g, 0)), 0) AS g
                            FROM objednavky_polozky op LEFT JOIN vyrobky v ON v.id = op.vyrobek_id
                            WHERE op.objednavka_id = :o");
        $w->execute(['o' => $oid]);
        $grams = (float) $w->fetchColumn();
        // největší rozměr napříč položkami (orientační — pro nadrozměr)
        $colsVy = $pdo->query("SHOW COLUMNS FROM vyrobky")->fetchAll(PDO::FETCH_COLUMN);
        if (in_array('rozmer_d', $colsVy, true)) {
            $dm = $pdo->prepare("SELECT MAX(v.rozmer_d) d, MAX(v.rozmer_s) s, MAX(v.rozmer_v) vv
                                 FROM objednavky_polozky op JOIN vyrobky v ON v.id = op.vyrobek_id WHERE op.objednavka_id = :o");
            $dm->execute(['o' => $oid]);
            $r = $dm->fetch(PDO::FETCH_ASSOC);
            if ($r && ($r['d'] || $r['s'] || $r['vv'])) $maxDim = ['d' => $r['d'], 's' => $r['s'], 'v' => $r['vv']];
        }
    } catch (Throwable $e) {}
    json_response(['ok' => true, 'weight_kg' => $grams > 0 ? round($grams / 1000, 2) : 0, 'rozmery' => $maxDim]);
}

if ($method === 'GET' && ($action === 'list' || $action === '')) {
    $where = ''; $params = [];
    if (!empty($_GET['objednavka_id'])) { $where = 'WHERE objednavka_id = :o'; $params['o'] = (int) $_GET['objednavka_id']; }
    $st = $pdo->prepare("SELECT * FROM zasilky $where ORDER BY id DESC LIMIT 200");
    $st->execute($params);
    json_response(['ok' => true, 'zasilky' => $st->fetchAll(PDO::FETCH_ASSOC)]);
}

if ($method === 'POST' && $action === 'create') {
    $d = json_input();
    $carrier = (string) ($d['carrier'] ?? '');
    $oid = (int) ($d['objednavka_id'] ?? 0);
    if (!$carrier) json_error('Chybí přepravce', 400);

    $payload = [
        'reference' => '', 'recipient_name' => '', 'recipient_last_name' => '', 'recipient_street' => '',
        'recipient_city' => '', 'recipient_zip' => '', 'recipient_country' => 'CZ', 'recipient_email' => '',
        'recipient_phone' => '', 'weight_kg' => (float) ($d['weight_kg'] ?? 1), 'value_kc' => 0,
        'cod_kc' => (float) ($d['cod_kc'] ?? 0), 'pickup_point_id' => (int) ($d['pickup_point_id'] ?? 0),
    ];
    if ($oid) {
        $o = $pdo->prepare("SELECT o.cislo, o.castka_celkem, od.nazev AS odb_nazev, od.email, od.telefon, od.ulice, od.mesto, od.psc
                            FROM objednavky o LEFT JOIN odberatele od ON od.id = o.odberatel_id WHERE o.id = :id");
        $o->execute(['id' => $oid]);
        if ($row = $o->fetch(PDO::FETCH_ASSOC)) {
            $payload['reference']       = $row['cislo'] ?: (string) $oid;
            $payload['recipient_name']  = $row['odb_nazev'] ?? '';
            $payload['recipient_last_name'] = $row['odb_nazev'] ?? '';
            $payload['recipient_street'] = $row['ulice'] ?? '';
            $payload['recipient_city']  = $row['mesto'] ?? '';
            $payload['recipient_zip']   = $row['psc'] ?? '';
            $payload['recipient_email'] = $row['email'] ?? '';
            $payload['recipient_phone'] = $row['telefon'] ?? '';
            $payload['value_kc']        = (float) ($row['castka_celkem'] ?? 0);
        }
    }
    // ruční override z requestu
    foreach (['reference','recipient_name','recipient_last_name','recipient_street','recipient_city','recipient_zip','recipient_email','recipient_phone'] as $k) {
        if (!empty($d[$k])) $payload[$k] = $d[$k];
    }

    $r = shipping_create($carrier, $payload);
    $ins = $pdo->prepare("INSERT INTO zasilky (objednavka_id, carrier, tracking_number, label_url, pickup_point, cod_kc, vaha_kg, stav, ext_id, chyba)
                          VALUES (:o,:c,:t,:l,:pp,:cod,:w,:s,:e,:err)");
    $ins->execute([
        'o' => $oid ?: null, 'c' => $carrier, 't' => $r['tracking'] ?? null, 'l' => $r['label_url'] ?? null,
        'pp' => !empty($d['pickup_point_id']) ? (string) $d['pickup_point_id'] : null,
        'cod' => $payload['cod_kc'] ?: null, 'w' => $payload['weight_kg'] ?: null,
        's' => ($r['ok'] ?? false) ? 'created' : 'error', 'e' => $r['ext_id'] ?? null,
        'err' => ($r['ok'] ?? false) ? null : trim(($r['error'] ?? '') . ' ' . ($r['hint'] ?? '')),
    ]);
    $zid = (int) $pdo->lastInsertId();
    if (!($r['ok'] ?? false)) json_error('Přepravce ' . shipping_carrier_label($carrier) . ': ' . ($r['error'] ?? 'chyba') . (!empty($r['hint']) ? ' — ' . $r['hint'] : ''), 502);
    json_response(['ok' => true, 'zasilka_id' => $zid, 'tracking' => $r['tracking'], 'carrier' => $carrier, 'label_url' => $r['label_url']]);
}

if ($method === 'GET' && $action === 'label') {
    $zid = (int) ($_GET['id'] ?? 0);
    $z = $pdo->prepare("SELECT * FROM zasilky WHERE id = :id"); $z->execute(['id' => $zid]);
    $zas = $z->fetch(PDO::FETCH_ASSOC);
    if (!$zas) json_error('Zásilka nenalezena', 404);
    if (!empty($zas['label_url'])) { header('Location: ' . $zas['label_url']); exit; }
    $lab = shipping_label($zas['carrier'], (string) $zas['tracking_number']);
    if (($lab['ok'] ?? false) && !empty($lab['pdf_base64'])) {
        header('Content-Type: application/pdf');
        header('Content-Disposition: inline; filename="stitek-' . $zid . '.pdf"');
        echo base64_decode($lab['pdf_base64']); exit;
    }
    if (($lab['ok'] ?? false) && !empty($lab['url'])) { header('Location: ' . $lab['url']); exit; }
    json_error('Štítek nedostupný: ' . ($lab['error'] ?? 'neznámá chyba'), 502);
}

json_error('Neznámá akce', 400);
