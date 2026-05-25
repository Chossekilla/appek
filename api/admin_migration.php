<?php
/**
 * 🔄 ADMIN MIGRATION — Universal import / export endpoint.
 *
 *   GET                              → seznam konektorů + kategorií
 *   GET    ?action=connector&key=X   → detail jednoho konektoru
 *   POST   ?action=preview           → multipart upload + connector + type → vrátí parsed records (preview, max 100)
 *   POST   ?action=import            → multipart upload + connector + type + commit=1 → uloží do DB
 *   GET    ?action=export&key=X&type=Y → download export-file pro tento konektor
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/_admin_auth.php';
require_once __DIR__ . '/_migration_lib.php';

cors_headers();
require_admin();
header('Content-Type: application/json; charset=UTF-8');

$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? '';

// ─── GET: seznam konektorů ──────────────────────────────────────
if ($method === 'GET' && $action === '') {
    json_response([
        'categories' => MIGRATION_CATEGORIES,
        'connectors' => MIGRATION_CONNECTORS,
        'by_category' => migration_by_category(),
        'types' => [
            'produkty'    => ['name' => '📦 Produkty / Výrobky',  'desc' => 'Sortiment, ceny, alergeny, kategorie'],
            'odberatele'  => ['name' => '👥 Odběratelé / Zákazníci', 'desc' => 'B2B kontakty, IČO, adresy'],
            'objednavky'  => ['name' => '📋 Objednávky',           'desc' => 'Historie nebo nové objednávky'],
            'faktury'     => ['name' => '💰 Faktury',               'desc' => 'Vystavené i přijaté faktury (ISDOC)'],
        ],
    ]);
}

// ─── GET: detail konektoru ─────────────────────────────────────
if ($method === 'GET' && $action === 'connector') {
    $key = $_GET['key'] ?? '';
    if (!isset(MIGRATION_CONNECTORS[$key])) json_error('Neznámý konektor', 404);
    json_response(['connector' => MIGRATION_CONNECTORS[$key]]);
}

// ─── POST: PREVIEW — parse upload, vrať prvních X záznamů ─────
if ($method === 'POST' && $action === 'preview') {
    $key  = $_POST['connector'] ?? '';
    $type = $_POST['type']      ?? '';
    if (!isset(MIGRATION_CONNECTORS[$key])) json_error('Neznámý konektor', 400);
    if (!in_array($type, ['produkty', 'odberatele', 'objednavky', 'faktury'], true)) json_error('Neplatný typ', 400);

    if (!isset($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
        json_error('Chybí soubor nebo upload selhal', 400);
    }
    if ($_FILES['file']['size'] > 20 * 1024 * 1024) json_error('Soubor max 20 MB', 400);

    $raw = file_get_contents($_FILES['file']['tmp_name']);
    try {
        $records = migration_import($key, $type, $raw);
    } catch (Throwable $e) {
        json_error_safe('Chyba při parsování', , 422);
    }

    json_response([
        'connector' => MIGRATION_CONNECTORS[$key],
        'type'      => $type,
        'total'     => count($records),
        'preview'   => array_slice($records, 0, 50),  // max 50 v preview
    ]);
}

// ─── POST: IMPORT — uloží do DB ───────────────────────────────
if ($method === 'POST' && $action === 'import') {
    $key  = $_POST['connector'] ?? '';
    $type = $_POST['type']      ?? '';
    $skipExisting = !empty($_POST['skip_existing']);

    if (!isset(MIGRATION_CONNECTORS[$key])) json_error('Neznámý konektor', 400);
    if (!isset($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) json_error('Chybí soubor', 400);

    $raw = file_get_contents($_FILES['file']['tmp_name']);
    try {
        $records = migration_import($key, $type, $raw);
    } catch (Throwable $e) {
        json_error_safe('Parsing', , 422);
    }

    $pdo = db();
    $result = ['inserted' => 0, 'skipped' => 0, 'updated' => 0, 'errors' => []];

    foreach ($records as $rec) {
        try {
            if ($type === 'produkty') {
                $cislo = trim($rec['cislo'] ?? '');
                $nazev = trim($rec['nazev'] ?? '');
                if (!$nazev) { $result['errors'][] = 'Bez názvu: ' . json_encode($rec); continue; }

                if ($cislo) {
                    $stmt = $pdo->prepare("SELECT id FROM vyrobky WHERE cislo = :c LIMIT 1");
                    $stmt->execute(['c' => $cislo]);
                    $existId = (int) $stmt->fetchColumn();
                } else { $existId = 0; }

                if ($existId && $skipExisting) { $result['skipped']++; continue; }

                $kategorie_id = null;
                if (!empty($rec['kategorie'])) {
                    $s = $pdo->prepare("SELECT id FROM kategorie_vyrobku WHERE LOWER(nazev) = LOWER(:n) LIMIT 1");
                    $s->execute(['n' => $rec['kategorie']]);
                    $kategorie_id = (int) $s->fetchColumn() ?: null;
                }
                // default jednotka = ks, sazba_dph = 12 %
                $jednotka_id = (int) $pdo->query("SELECT id FROM jednotky WHERE kod = 'ks' LIMIT 1")->fetchColumn() ?: 1;
                $sazba_id    = (int) $pdo->query("SELECT id FROM sazby_dph WHERE sazba = 12 LIMIT 1")->fetchColumn() ?: 1;

                $params = [
                    'cislo' => $cislo, 'nazev' => $nazev,
                    'popis' => $rec['popis'] ?? '',
                    'kat'   => $kategorie_id, 'jed' => $jednotka_id, 'sazba' => $sazba_id,
                    'cena'  => (float) ($rec['cena_bez_dph'] ?? 0),
                    'hmot'  => $rec['hmotnost_g'] ?? null,
                    'al'    => $rec['alergeny'] ?? '',
                    'ean'   => $rec['ean'] ?? null,
                ];
                if ($existId) {
                    $params['id'] = $existId;
                    $pdo->prepare("UPDATE vyrobky SET nazev=:nazev, popis=:popis, kategorie_id=:kat,
                        jednotka_id=:jed, sazba_dph_id=:sazba, cena_bez_dph=:cena, hmotnost_g=:hmot,
                        alergeny=:al, ean=:ean WHERE id=:id")->execute($params);
                    $result['updated']++;
                } else {
                    $pdo->prepare("INSERT INTO vyrobky (cislo, nazev, popis, kategorie_id, jednotka_id,
                        sazba_dph_id, cena_bez_dph, hmotnost_g, alergeny, ean, aktivni)
                        VALUES (:cislo, :nazev, :popis, :kat, :jed, :sazba, :cena, :hmot, :al, :ean, 1)")
                        ->execute($params);
                    $result['inserted']++;
                }

            } elseif ($type === 'odberatele') {
                $ico   = trim($rec['ico'] ?? '');
                $nazev = trim($rec['nazev'] ?? '');
                if (!$nazev) { $result['errors'][] = 'Bez názvu: ' . json_encode($rec); continue; }

                $existId = 0;
                if ($ico) {
                    $s = $pdo->prepare("SELECT id FROM odberatele WHERE ico = :i LIMIT 1");
                    $s->execute(['i' => $ico]);
                    $existId = (int) $s->fetchColumn();
                }
                if ($existId && $skipExisting) { $result['skipped']++; continue; }

                $params = [
                    'cislo' => $rec['cislo'] ?? '',
                    'nazev' => $nazev,
                    'ico'   => $ico ?: null,
                    'dic'   => $rec['dic'] ?? null,
                    'ulice' => $rec['ulice'] ?? '',
                    'mesto' => $rec['mesto'] ?? '',
                    'psc'   => $rec['psc'] ?? '',
                    'email' => $rec['email'] ?? '',
                    'telefon' => $rec['telefon'] ?? '',
                    'typ'   => $rec['typ'] ?? null,
                    'spl'   => (int) ($rec['splatnost_dni'] ?? 14),
                ];
                if ($existId) {
                    $params['id'] = $existId;
                    $pdo->prepare("UPDATE odberatele SET nazev=:nazev, dic=:dic, ulice=:ulice, mesto=:mesto,
                        psc=:psc, email=:email, telefon=:telefon, typ=:typ, splatnost_dni=:spl WHERE id=:id")
                        ->execute($params);
                    $result['updated']++;
                } else {
                    $pdo->prepare("INSERT INTO odberatele (cislo, nazev, ico, dic, ulice, mesto, psc,
                        email, telefon, typ, splatnost_dni) VALUES (:cislo, :nazev, :ico, :dic, :ulice,
                        :mesto, :psc, :email, :telefon, :typ, :spl)")->execute($params);
                    $result['inserted']++;
                }
            }
            // objednavky/faktury — kompletnější logika, vyřeš v další iteraci
        } catch (Throwable $e) {
            $result['errors'][] = $e->getMessage();
            if (count($result['errors']) > 50) {
                $result['errors'][] = '... (více než 50 chyb, zkrácen)';
                break;
            }
        }
    }

    $result['total_records'] = count($records);
    $result['ok'] = true;
    json_response($result);
}

// ─── GET: EXPORT — stáhne soubor v daném formátu ──────────────
if ($method === 'GET' && $action === 'export') {
    $key  = $_GET['key']  ?? '';
    $type = $_GET['type'] ?? '';
    if (!isset(MIGRATION_CONNECTORS[$key])) { http_response_code(404); echo 'Connector not found'; exit; }

    $c = MIGRATION_CONNECTORS[$key];
    if (!in_array($type, $c['supports'], true)) {
        http_response_code(400); echo 'Unsupported type for connector'; exit;
    }

    $pdo = db();
    $records = [];

    if ($type === 'produkty') {
        $records = $pdo->query("
            SELECT v.cislo, v.nazev, v.popis, v.cena_bez_dph,
                   s.sazba AS dph,
                   j.kod AS jednotka,
                   k.nazev AS kategorie,
                   v.alergeny, v.hmotnost_g, v.ean
            FROM vyrobky v
            LEFT JOIN sazby_dph s ON s.id = v.sazba_dph_id
            LEFT JOIN jednotky  j ON j.id = v.jednotka_id
            LEFT JOIN kategorie_vyrobku k ON k.id = v.kategorie_id
            WHERE v.aktivni = 1
            ORDER BY v.id
        ")->fetchAll(PDO::FETCH_ASSOC);
    } elseif ($type === 'odberatele') {
        $records = $pdo->query("
            SELECT cislo, nazev, ico, dic, ulice, mesto, psc, email, telefon, typ, splatnost_dni
            FROM odberatele
            WHERE blokovan = 0
            ORDER BY id
        ")->fetchAll(PDO::FETCH_ASSOC);
    } elseif ($type === 'objednavky') {
        $records = $pdo->query("
            SELECT o.cislo, od.ico AS odberatel_ico, o.datum_objednani, o.datum_dodani,
                   o.stav, o.castka_bez_dph, o.castka_dph, o.castka_celkem, o.poznamka
            FROM objednavky o
            JOIN odberatele od ON od.id = o.odberatel_id
            ORDER BY o.id DESC LIMIT 500
        ")->fetchAll(PDO::FETCH_ASSOC);
    } elseif ($type === 'faktury') {
        $records = $pdo->query("
            SELECT f.cislo, od.ico AS odberatel_ico, f.datum_vystaveni, f.datum_splatnosti,
                   f.castka_bez_dph, f.castka_dph, f.castka_celkem, f.variabilni_symbol
            FROM faktury f
            JOIN odberatele od ON od.id = f.odberatel_id
            ORDER BY f.id DESC
        ")->fetchAll(PDO::FETCH_ASSOC);
    }

    $rawData = migration_export($key, $type, $records);
    $ext = $c['format'];

    $filename = 'appek-export-' . $type . '-' . $key . '-' . date('Y-m-d') . '.' . $ext;
    header('Content-Type: ' . match($ext) {
        'csv'  => 'text/csv; charset=' . $c['encoding'],
        'xml'  => 'application/xml; charset=' . $c['encoding'],
        'json' => 'application/json; charset=utf-8',
        default => 'application/octet-stream',
    });
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Cache-Control: no-cache');
    echo $rawData;
    exit;
}

json_error('Neznámá akce', 400);
