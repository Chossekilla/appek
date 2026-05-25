<?php
/**
 * Export dodacích listů — pro účetnictví / sklad / Money S3 / Pohoda
 *
 * Použití:
 *   GET ?action=csv&od=YYYY-MM-DD&do=YYYY-MM-DD     — flat CSV s položkami za období
 *   GET ?action=csv-souhrn&od=...&do=...             — CSV jen s hlavičkami DL (bez položek)
 *   GET ?action=csv-detail&id=X                      — CSV pro 1 DL (s položkami)
 *   POST ?action=csv-zip                             — body { ids:[..] } → ZIP s 1 CSV per DL
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/_admin_auth.php';
cors_headers();
require_admin();

$pdo = db();
$action = $_GET['action'] ?? '';

// =============================================================
// Pomocné: načte DL header + položky
// =============================================================
function nacti_dl(PDO $pdo, int $id): ?array {
    $stmt = $pdo->prepare("
        SELECT dl.*, od.nazev AS odberatel_nazev, od.ico AS odberatel_ico, od.dic AS odberatel_dic,
               od.ulice AS odberatel_ulice, od.mesto AS odberatel_mesto, od.psc AS odberatel_psc,
               md.nazev AS misto_nazev, md.ulice AS misto_ulice, md.mesto AS misto_mesto, md.psc AS misto_psc,
               o.cislo AS objednavka_cislo
        FROM dodaci_listy dl
        JOIN odberatele od ON od.id = dl.odberatel_id
        LEFT JOIN mista_dodani md ON md.id = dl.misto_dodani_id
        LEFT JOIN objednavky o ON o.id = dl.objednavka_id
        WHERE dl.id = :id
    ");
    $stmt->execute(['id' => $id]);
    $dl = $stmt->fetch();
    if (!$dl) return null;

    $stmt = $pdo->prepare("
        SELECT vyrobek_cislo, vyrobek_nazev, jednotka, mnozstvi, cena_bez_dph, sazba_dph, poznamka
        FROM dodaci_list_polozky
        WHERE dodaci_list_id = :id
        ORDER BY id
    ");
    $stmt->execute(['id' => $id]);
    $dl['polozky'] = $stmt->fetchAll();
    return $dl;
}

function csv_fmt_num(float $n, int $dec = 2): string {
    return number_format($n, $dec, ',', '');
}

function csv_filename_safe(string $s): string {
    $s = preg_replace('/[^A-Za-z0-9_-]+/', '_', $s);
    return trim($s, '_') ?: 'DL';
}

/**
 * Hlavičky CSV s položkami (jeden řádek = jedna položka DL).
 */
function csv_header_polozky(): array {
    return [
        'DL číslo', 'Datum vystavení', 'Datum dodání', 'Objednávka',
        'Odběratel', 'IČO', 'DIČ',
        'Adresa', 'Pobočka', 'Pobočka adresa',
        'Výrobek kód', 'Výrobek název', 'Jednotka',
        'Množství', 'Cena bez DPH/ks', 'DPH %',
        'Řádek bez DPH', 'Řádek DPH', 'Řádek s DPH',
        'Poznámka',
    ];
}

function csv_row_polozka(array $dl, array $p): array {
    $mn  = (float) $p['mnozstvi'];
    $cena = (float) $p['cena_bez_dph'];
    $sazba = (float) $p['sazba_dph'];
    $bez = $mn * $cena;
    $dph = $bez * $sazba / 100;
    return [
        $dl['cislo'],
        $dl['datum_vystaveni'],
        $dl['datum_dodani'],
        $dl['objednavka_cislo'] ?? '',
        $dl['odberatel_nazev'],
        $dl['odberatel_ico'] ?? '',
        $dl['odberatel_dic'] ?? '',
        trim(implode(', ', array_filter([$dl['odberatel_ulice'] ?? '', trim(($dl['odberatel_psc'] ?? '') . ' ' . ($dl['odberatel_mesto'] ?? ''))]))),
        $dl['misto_nazev'] ?? '',
        trim(implode(', ', array_filter([$dl['misto_ulice'] ?? '', trim(($dl['misto_psc'] ?? '') . ' ' . ($dl['misto_mesto'] ?? ''))]))),
        $p['vyrobek_cislo'] ?? '',
        $p['vyrobek_nazev'],
        $p['jednotka'] ?? 'ks',
        csv_fmt_num($mn, 3),
        csv_fmt_num($cena, 2),
        (int) $sazba,
        csv_fmt_num($bez, 2),
        csv_fmt_num($dph, 2),
        csv_fmt_num($bez + $dph, 2),
        $p['poznamka'] ?? '',
    ];
}

function csv_header_souhrn(): array {
    return [
        'DL číslo', 'Datum vystavení', 'Datum dodání', 'Objednávka',
        'Odběratel', 'IČO', 'DIČ', 'Pobočka',
        'Položek', 'Částka celkem', 'Fakturováno',
    ];
}

function pocet_polozek(PDO $pdo, int $dl_id): int {
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM dodaci_list_polozky WHERE dodaci_list_id = :id");
    $stmt->execute(['id' => $dl_id]);
    return (int) $stmt->fetchColumn();
}

// =============================================================
// CSV za období (s položkami)
// =============================================================
if ($action === 'csv') {
    $od = $_GET['od'] ?? date('Y-m-01');
    $do = $_GET['do'] ?? date('Y-m-t');
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $od)) json_error('Neplatné datum od');
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $do)) json_error('Neplatné datum do');

    $stmt = $pdo->prepare("
        SELECT id FROM dodaci_listy
        WHERE datum_vystaveni BETWEEN :od AND :do
        ORDER BY datum_vystaveni, id
    ");
    $stmt->execute(['od' => $od, 'do' => $do]);
    $ids = array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));

    $filename = 'DL_' . str_replace('-', '', $od) . '_' . str_replace('-', '', $do) . '.csv';
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF");
    fputcsv($out, csv_header_polozky(), ';');
    foreach ($ids as $id) {
        $dl = nacti_dl($pdo, $id);
        if (!$dl) continue;
        foreach ($dl['polozky'] as $p) {
            fputcsv($out, csv_row_polozka($dl, $p), ';');
        }
    }
    fclose($out);
    exit;
}

// =============================================================
// CSV souhrn (bez položek — jen hlavičky DL)
// =============================================================
if ($action === 'csv-souhrn') {
    $od = $_GET['od'] ?? date('Y-m-01');
    $do = $_GET['do'] ?? date('Y-m-t');
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $od)) json_error('Neplatné datum od');
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $do)) json_error('Neplatné datum do');

    $stmt = $pdo->prepare("
        SELECT dl.cislo, dl.datum_vystaveni, dl.datum_dodani, dl.castka_celkem, dl.fakturovano,
               od.nazev AS odberatel, od.ico, od.dic,
               md.nazev AS misto_nazev, o.cislo AS objednavka_cislo,
               (SELECT COUNT(*) FROM dodaci_list_polozky WHERE dodaci_list_id = dl.id) AS pocet_polozek
        FROM dodaci_listy dl
        JOIN odberatele od ON od.id = dl.odberatel_id
        LEFT JOIN mista_dodani md ON md.id = dl.misto_dodani_id
        LEFT JOIN objednavky o ON o.id = dl.objednavka_id
        WHERE dl.datum_vystaveni BETWEEN :od AND :do
        ORDER BY dl.datum_vystaveni, dl.id
    ");
    $stmt->execute(['od' => $od, 'do' => $do]);
    $rows = $stmt->fetchAll();

    $filename = 'DL_souhrn_' . str_replace('-', '', $od) . '_' . str_replace('-', '', $do) . '.csv';
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF");
    fputcsv($out, csv_header_souhrn(), ';');
    foreach ($rows as $r) {
        fputcsv($out, [
            $r['cislo'], $r['datum_vystaveni'], $r['datum_dodani'], $r['objednavka_cislo'] ?? '',
            $r['odberatel'], $r['ico'] ?? '', $r['dic'] ?? '', $r['misto_nazev'] ?? '',
            (int) $r['pocet_polozek'],
            csv_fmt_num((float) $r['castka_celkem'], 2),
            $r['fakturovano'] ? 'ano' : 'ne',
        ], ';');
    }
    fclose($out);
    exit;
}

// =============================================================
// CSV jednoho DL (header + položky)
// =============================================================
if ($action === 'csv-detail') {
    $id = (int) ($_GET['id'] ?? 0);
    if (!$id) json_error('Chybí ID');
    $dl = nacti_dl($pdo, $id);
    if (!$dl) json_error('DL nenalezen', 404);

    $filename = 'DL_' . csv_filename_safe($dl['cislo']) . '.csv';
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF");
    fputcsv($out, csv_header_polozky(), ';');
    foreach ($dl['polozky'] as $p) {
        fputcsv($out, csv_row_polozka($dl, $p), ';');
    }
    fclose($out);
    exit;
}

// =============================================================
// ZIP s 1 CSV per DL
// =============================================================
if ($action === 'csv-zip') {
    $body = json_input();
    $ids  = $body['ids'] ?? [];
    if (!is_array($ids) || count($ids) === 0) json_error('Vyber alespoň jeden DL');
    $ids  = array_filter(array_map('intval', $ids));
    if (empty($ids)) json_error('Neplatná ID');

    if (!class_exists('ZipArchive')) json_error('PHP ZipArchive není dostupné na serveru', 500);

    $tmp = tempnam(sys_get_temp_dir(), 'dl_csv_');
    $zip = new ZipArchive();
    if ($zip->open($tmp, ZipArchive::OVERWRITE) !== true) json_error('Nelze vytvořit ZIP', 500);

    $added = 0;
    foreach ($ids as $id) {
        $dl = nacti_dl($pdo, $id);
        if (!$dl) continue;
        $rows = "\xEF\xBB\xBF" . csv_to_string([csv_header_polozky()]) . "\n";
        foreach ($dl['polozky'] as $p) {
            $rows .= csv_to_string([csv_row_polozka($dl, $p)]) . "\n";
        }
        $zip->addFromString('DL_' . csv_filename_safe($dl['cislo']) . '.csv', $rows);
        $added++;
    }
    $zip->close();

    if ($added === 0) { unlink($tmp); json_error('Nic k exportu'); }

    $filename = 'DL_csv_' . date('Ymd_His') . '.zip';
    header('Content-Type: application/zip');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Content-Length: ' . filesize($tmp));
    readfile($tmp);
    unlink($tmp);
    exit;
}

function csv_to_string(array $rows): string {
    $fp = fopen('php://memory', 'r+');
    foreach ($rows as $r) fputcsv($fp, $r, ';');
    rewind($fp);
    $s = stream_get_contents($fp);
    fclose($fp);
    return rtrim($s, "\n\r");
}

json_error('Neznámá akce. Použij action=csv | csv-souhrn | csv-detail | csv-zip');
