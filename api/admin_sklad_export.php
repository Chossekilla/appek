<?php
/**
 * 📤 ADMIN SKLAD EXPORT — exporty per-sklad (položky + volitelně historie pohybů).
 *
 * GET ?sklad_id=N&format=csv               → CSV — položky skladu (sloupce: kód, název, typ, stav, jednotka, min_stav, cil_stav)
 * GET ?sklad_id=N&format=json              → JSON dump (machine-readable, vč. metadata)
 * GET ?sklad_id=N&format=xml               → XML (POHODA/Money S3 kompat. struktura)
 * GET ?sklad_id=N&format=pdf               → HTML print-ready (s window.print() klientsky)
 * GET ?sklad_id=N&format=csv&pohyby=1      → CSV + sekce pohybů (kompletní audit trail)
 *
 * Bezpečnost: vyžaduje admin auth (require_admin). Filename obsahuje datum.
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/_admin_auth.php';
require_once __DIR__ . '/_schema_lib.php';
cors_headers();
require_admin();

$pdo = db();
ensure_sklady_schema($pdo);
ensure_sklad_polozky_schema($pdo);
ensure_sklad_pohyby_schema($pdo);

$skladId = (int) ($_GET['sklad_id'] ?? 0);
$format  = strtolower(trim((string) ($_GET['format'] ?? 'csv')));
$pohyby  = !empty($_GET['pohyby']);

if ($skladId <= 0) {
    http_response_code(400);
    echo "Chybí sklad_id";
    exit;
}

// Načti sklad metadata
$stmt = $pdo->prepare("SELECT * FROM sklady WHERE id = :id LIMIT 1");
$stmt->execute(['id' => $skladId]);
$sklad = $stmt->fetch();
if (!$sklad) {
    http_response_code(404);
    echo "Sklad nenalezen";
    exit;
}

// Načti položky skladu s názvy / jednotkami
$itemsStmt = $pdo->prepare("
    SELECT sp.*,
           CASE sp.item_typ
               WHEN 'surovina' THEN (SELECT nazev FROM suroviny WHERE id = sp.item_id LIMIT 1)
               WHEN 'vyrobek'  THEN (SELECT nazev FROM vyrobky  WHERE id = sp.item_id LIMIT 1)
           END AS nazev,
           CASE sp.item_typ
               WHEN 'surovina' THEN (SELECT jednotka FROM suroviny WHERE id = sp.item_id LIMIT 1)
               WHEN 'vyrobek'  THEN 'ks'
           END AS jednotka,
           CASE sp.item_typ
               WHEN 'vyrobek'  THEN (SELECT cislo FROM vyrobky WHERE id = sp.item_id LIMIT 1)
               ELSE NULL
           END AS cislo
    FROM sklad_polozky sp
    WHERE sp.sklad_id = :sid
    ORDER BY sp.item_typ, nazev
");
$itemsStmt->execute(['sid' => $skladId]);
$items = $itemsStmt->fetchAll();

// Pokud chce pohyby, načti
$pohybyData = [];
if ($pohyby) {
    $stmt2 = $pdo->prepare("
        SELECT p.*,
               CASE p.item_typ
                   WHEN 'surovina' THEN (SELECT nazev FROM suroviny WHERE id = p.item_id LIMIT 1)
                   WHEN 'vyrobek'  THEN (SELECT nazev FROM vyrobky  WHERE id = p.item_id LIMIT 1)
               END AS item_nazev,
               sc.kod AS sklad_kod_cil, sc.nazev AS sklad_nazev_cil
        FROM sklad_pohyby_v2 p
        LEFT JOIN sklady sc ON sc.id = p.sklad_id_cil
        WHERE p.sklad_id = :sid OR p.sklad_id_cil = :sid2
        ORDER BY p.kdy DESC, p.id DESC
        LIMIT 5000
    ");
    $stmt2->execute(['sid' => $skladId, 'sid2' => $skladId]);
    $pohybyData = $stmt2->fetchAll();
}

$dateStr = date('Y-m-d');
$safeKod = preg_replace('/[^A-Za-z0-9_-]/', '', $sklad['kod'] ?? 'sklad');
$baseFilename = "appek-sklad-{$safeKod}-{$dateStr}";

// ─── CSV ───────────────────────────────────────────────────────
if ($format === 'csv') {
    header('Content-Type: text/csv; charset=UTF-8');
    header("Content-Disposition: attachment; filename=\"{$baseFilename}.csv\"");
    // BOM pro správné Excel UTF-8 rozpoznání
    echo "\xEF\xBB\xBF";
    $out = fopen('php://output', 'w');

    // Header s metadaty
    fputcsv($out, ["Sklad", $sklad['kod'] . ' · ' . $sklad['nazev']], ';');
    fputcsv($out, ["Typ", $sklad['typ'] ?? '']);
    if (!empty($sklad['teplota_min']) || !empty($sklad['teplota_max'])) {
        $tepl = ($sklad['teplota_min'] ?? '—') . '°C až ' . ($sklad['teplota_max'] ?? '—') . '°C';
        fputcsv($out, ["Teplota", $tepl], ';');
    }
    fputcsv($out, ["Adresa", $sklad['adresa'] ?? ''], ';');
    fputcsv($out, ["Datum exportu", $dateStr], ';');
    fputcsv($out, ["Počet položek", (string) count($items)], ';');
    fputcsv($out, [], ';');  // blank line

    // Položky — header row
    fputcsv($out, ['Číslo', 'Název', 'Typ', 'Aktuální stav', 'Jednotka', 'Min. stav', 'Cíl. stav', 'Hodnota'], ';');
    $celkemHodnota = 0;
    foreach ($items as $it) {
        $stav = (float) ($it['stav'] ?? 0);
        $cena = (float) ($it['cena_za_jed'] ?? 0);
        $hodnota = $stav * $cena;
        $celkemHodnota += $hodnota;
        fputcsv($out, [
            $it['cislo'] ?? '',
            $it['nazev'] ?? '(neznámá položka)',
            $it['item_typ'] === 'surovina' ? 'Surovina' : 'Výrobek',
            number_format($stav, 2, ',', ''),
            $it['jednotka'] ?? '',
            $it['min_stav'] !== null ? number_format((float) $it['min_stav'], 2, ',', '') : '',
            $it['cil_stav'] !== null ? number_format((float) $it['cil_stav'], 2, ',', '') : '',
            $hodnota > 0 ? number_format($hodnota, 2, ',', '') . ' Kč' : '',
        ], ';');
    }
    fputcsv($out, [], ';');
    fputcsv($out, ['', '', '', '', '', '', 'CELKEM', number_format($celkemHodnota, 2, ',', '') . ' Kč'], ';');

    // Optional: pohyby section
    if ($pohyby && !empty($pohybyData)) {
        fputcsv($out, [], ';');
        fputcsv($out, ['HISTORIE POHYBŮ'], ';');
        fputcsv($out, ['Datum', 'Typ', 'Položka', 'Množství', 'Stav před', 'Stav po', 'Cena/jed.', 'Cílový sklad', 'Poznámka', 'Kdo'], ';');
        foreach ($pohybyData as $p) {
            fputcsv($out, [
                $p['kdy'] ?? '',
                $p['typ'] ?? '',
                $p['item_nazev'] ?? '(?)',
                number_format((float) $p['mnozstvi'], 2, ',', ''),
                $p['stav_pred'] !== null ? number_format((float) $p['stav_pred'], 2, ',', '') : '',
                $p['stav_po']   !== null ? number_format((float) $p['stav_po'], 2, ',', '')   : '',
                $p['cena_za_jed'] !== null ? number_format((float) $p['cena_za_jed'], 2, ',', '') : '',
                $p['sklad_kod_cil'] ? ($p['sklad_kod_cil'] . ' ' . $p['sklad_nazev_cil']) : '',
                $p['poznamka'] ?? '',
                $p['kdo'] ?? '',
            ], ';');
        }
    }
    fclose($out);
    exit;
}

// ─── JSON ──────────────────────────────────────────────────────
if ($format === 'json') {
    header('Content-Type: application/json; charset=UTF-8');
    header("Content-Disposition: attachment; filename=\"{$baseFilename}.json\"");
    echo json_encode([
        'export_date' => date('c'),
        'sklad' => [
            'id' => (int) $sklad['id'],
            'kod' => $sklad['kod'],
            'nazev' => $sklad['nazev'],
            'typ' => $sklad['typ'],
            'teplota_min' => $sklad['teplota_min'] !== null ? (float) $sklad['teplota_min'] : null,
            'teplota_max' => $sklad['teplota_max'] !== null ? (float) $sklad['teplota_max'] : null,
            'adresa' => $sklad['adresa'],
            'aktivni' => (int) $sklad['aktivni'],
        ],
        'polozky' => array_map(fn($it) => [
            'item_typ' => $it['item_typ'],
            'item_id' => (int) $it['item_id'],
            'cislo' => $it['cislo'],
            'nazev' => $it['nazev'],
            'jednotka' => $it['jednotka'],
            'stav' => (float) $it['stav'],
            'min_stav' => $it['min_stav'] !== null ? (float) $it['min_stav'] : null,
            'cil_stav' => $it['cil_stav'] !== null ? (float) $it['cil_stav'] : null,
            'cena_za_jed' => $it['cena_za_jed'] !== null ? (float) $it['cena_za_jed'] : null,
        ], $items),
        'pohyby' => $pohyby ? array_map(fn($p) => [
            'kdy' => $p['kdy'],
            'typ' => $p['typ'],
            'item_typ' => $p['item_typ'],
            'item_id' => (int) $p['item_id'],
            'item_nazev' => $p['item_nazev'],
            'mnozstvi' => (float) $p['mnozstvi'],
            'stav_pred' => $p['stav_pred'] !== null ? (float) $p['stav_pred'] : null,
            'stav_po' => $p['stav_po'] !== null ? (float) $p['stav_po'] : null,
            'cena_za_jed' => $p['cena_za_jed'] !== null ? (float) $p['cena_za_jed'] : null,
            'sklad_id_cil' => $p['sklad_id_cil'] !== null ? (int) $p['sklad_id_cil'] : null,
            'sklad_kod_cil' => $p['sklad_kod_cil'],
            'poznamka' => $p['poznamka'],
            'kdo' => $p['kdo'],
        ], $pohybyData) : null,
    ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    exit;
}

// ─── XML (POHODA/Money S3 kompat.) ─────────────────────────────
if ($format === 'xml') {
    header('Content-Type: application/xml; charset=UTF-8');
    header("Content-Disposition: attachment; filename=\"{$baseFilename}.xml\"");
    echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
    echo '<sklad>' . "\n";
    echo "  <kod>" . htmlspecialchars($sklad['kod']) . "</kod>\n";
    echo "  <nazev>" . htmlspecialchars($sklad['nazev']) . "</nazev>\n";
    echo "  <typ>" . htmlspecialchars($sklad['typ'] ?? '') . "</typ>\n";
    echo "  <exportDate>" . date('c') . "</exportDate>\n";
    echo "  <polozky>\n";
    foreach ($items as $it) {
        echo "    <polozka>\n";
        echo "      <kod>" . htmlspecialchars($it['cislo'] ?? '') . "</kod>\n";
        echo "      <nazev>" . htmlspecialchars($it['nazev'] ?? '') . "</nazev>\n";
        echo "      <typ>" . htmlspecialchars($it['item_typ']) . "</typ>\n";
        echo "      <stav>" . number_format((float) $it['stav'], 4, '.', '') . "</stav>\n";
        echo "      <jednotka>" . htmlspecialchars($it['jednotka'] ?? '') . "</jednotka>\n";
        if ($it['min_stav'] !== null) echo "      <minStav>" . number_format((float) $it['min_stav'], 4, '.', '') . "</minStav>\n";
        if ($it['cil_stav'] !== null) echo "      <cilStav>" . number_format((float) $it['cil_stav'], 4, '.', '') . "</cilStav>\n";
        if ($it['cena_za_jed'] !== null) echo "      <cenaZaJed>" . number_format((float) $it['cena_za_jed'], 4, '.', '') . "</cenaZaJed>\n";
        echo "    </polozka>\n";
    }
    echo "  </polozky>\n";
    echo "</sklad>\n";
    exit;
}

// ─── PDF (print-ready HTML) ────────────────────────────────────
if ($format === 'pdf' || $format === 'html') {
    header('Content-Type: text/html; charset=UTF-8');
    $typLabel = ['suchy' => 'Suchý sklad', 'lednice' => 'Lednice', 'mrazak' => 'Mrazák', 'jiny' => 'Jiný'];
    $tepl = '';
    if (!empty($sklad['teplota_min']) || !empty($sklad['teplota_max'])) {
        $tepl = ($sklad['teplota_min'] ?? '—') . '°C až ' . ($sklad['teplota_max'] ?? '—') . '°C';
    }
    $celkem = 0;
    foreach ($items as $it) {
        $celkem += ((float) $it['stav']) * ((float) ($it['cena_za_jed'] ?? 0));
    }
    ?>
<!DOCTYPE html>
<html lang="cs">
<head>
<meta charset="UTF-8">
<title>Sklad <?= htmlspecialchars($sklad['kod']) ?> · <?= htmlspecialchars($sklad['nazev']) ?> — <?= $dateStr ?></title>
<style>
* { box-sizing: border-box; }
body { font-family: -apple-system, BlinkMacSystemFont, "SF Pro Text", "Inter", Arial, sans-serif; color: #1d1d1f; padding: 24px; max-width: 1100px; margin: 0 auto; }
.toolbar { position: sticky; top: 0; background: #fff; padding: 12px 0; border-bottom: 1px solid #e5e5e7; margin-bottom: 18px; display: flex; gap: 8px; justify-content: space-between; align-items: center; }
@media print { .toolbar { display: none; } body { padding: 0; max-width: none; } }
.btn { padding: 8px 16px; border-radius: 8px; border: 1px solid #BA7517; background: linear-gradient(180deg, #FFC966, #BA7517); color: #fff; cursor: pointer; font-weight: 600; font-size: 13px; }
.btn-back { background: #fff; color: #424245; border: 1px solid #d2d2d7; text-decoration: none; display: inline-block; }
h1 { font-size: 24px; margin: 0 0 6px; }
.meta { display: grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap: 8px 18px; font-size: 12.5px; color: #515154; margin-bottom: 18px; padding: 14px 16px; background: #f5f5f7; border-radius: 10px; }
.meta strong { color: #1d1d1f; }
table { width: 100%; border-collapse: collapse; font-size: 12.5px; }
th, td { padding: 8px 10px; text-align: left; border-bottom: 1px solid #e5e5e7; }
th { background: #f5f5f7; font-weight: 600; color: #424245; text-transform: uppercase; font-size: 11px; letter-spacing: 0.5px; }
td.num { text-align: right; font-variant-numeric: tabular-nums; }
.section-title { font-size: 14px; font-weight: 700; margin: 22px 0 10px; color: #1d1d1f; }
.warn { color: #c66800; font-weight: 600; }
.muted { color: #86868b; }
.empty { text-align: center; padding: 40px; color: #86868b; }
.tfoot { font-weight: 700; background: #fafaf9; }
@page { size: A4; margin: 18mm 14mm; }
</style>
</head>
<body>
<div class="toolbar">
  <a class="btn btn-back" href="javascript:window.close()">✕ Zavřít</a>
  <div>
    <strong>📦 Sklad export</strong> · <?= htmlspecialchars($sklad['kod']) ?> · <?= htmlspecialchars($sklad['nazev']) ?>
  </div>
  <button class="btn" onclick="window.print()">🖨️ Tisk / PDF</button>
</div>

<h1>📦 <?= htmlspecialchars($sklad['kod']) ?> · <?= htmlspecialchars($sklad['nazev']) ?></h1>
<div class="meta">
  <div><strong>Typ:</strong> <?= htmlspecialchars($typLabel[$sklad['typ']] ?? $sklad['typ'] ?? '—') ?></div>
  <?php if ($tepl): ?><div><strong>Teplota:</strong> <?= htmlspecialchars($tepl) ?></div><?php endif; ?>
  <?php if (!empty($sklad['adresa'])): ?><div><strong>Adresa:</strong> <?= htmlspecialchars($sklad['adresa']) ?></div><?php endif; ?>
  <div><strong>Datum exportu:</strong> <?= htmlspecialchars(date('j. n. Y H:i')) ?></div>
  <div><strong>Počet položek:</strong> <?= count($items) ?></div>
  <?php if ($celkem > 0): ?><div><strong>Hodnota skladu:</strong> <?= number_format($celkem, 2, ',', ' ') ?> Kč</div><?php endif; ?>
</div>

<div class="section-title">📋 Položky skladu</div>
<?php if (empty($items)): ?>
  <div class="empty">Ve skladu nejsou žádné položky.</div>
<?php else: ?>
<table>
  <thead>
    <tr>
      <th>Číslo</th>
      <th>Název</th>
      <th>Typ</th>
      <th class="num">Stav</th>
      <th>Jednotka</th>
      <th class="num">Min</th>
      <th class="num">Cíl</th>
      <th class="num">Hodnota</th>
    </tr>
  </thead>
  <tbody>
    <?php foreach ($items as $it): ?>
      <?php
      $stav = (float) ($it['stav'] ?? 0);
      $min = $it['min_stav'] !== null ? (float) $it['min_stav'] : null;
      $cil = $it['cil_stav'] !== null ? (float) $it['cil_stav'] : null;
      $cena = (float) ($it['cena_za_jed'] ?? 0);
      $hodnota = $stav * $cena;
      $underMin = $min !== null && $stav <= $min;
      ?>
      <tr>
        <td class="muted"><?= htmlspecialchars($it['cislo'] ?? '—') ?></td>
        <td><strong><?= htmlspecialchars($it['nazev'] ?? '(neznámá)') ?></strong></td>
        <td><?= $it['item_typ'] === 'surovina' ? '🌾 Surovina' : '📦 Výrobek' ?></td>
        <td class="num <?= $underMin ? 'warn' : '' ?>"><?= number_format($stav, 2, ',', ' ') ?><?= $underMin ? ' ⚠️' : '' ?></td>
        <td class="muted"><?= htmlspecialchars($it['jednotka'] ?? '') ?></td>
        <td class="num muted"><?= $min !== null ? number_format($min, 2, ',', ' ') : '—' ?></td>
        <td class="num muted"><?= $cil !== null ? number_format($cil, 2, ',', ' ') : '—' ?></td>
        <td class="num"><?= $hodnota > 0 ? number_format($hodnota, 2, ',', ' ') . ' Kč' : '—' ?></td>
      </tr>
    <?php endforeach; ?>
  </tbody>
  <?php if ($celkem > 0): ?>
  <tfoot>
    <tr class="tfoot">
      <td colspan="7" class="num">CELKEM</td>
      <td class="num"><?= number_format($celkem, 2, ',', ' ') ?> Kč</td>
    </tr>
  </tfoot>
  <?php endif; ?>
</table>
<?php endif; ?>

<?php if ($pohyby && !empty($pohybyData)): ?>
<div class="section-title">📊 Historie pohybů (posledních <?= count($pohybyData) ?>)</div>
<table>
  <thead>
    <tr>
      <th>Datum</th>
      <th>Typ</th>
      <th>Položka</th>
      <th class="num">Množství</th>
      <th class="num">Stav po</th>
      <th>Poznámka</th>
      <th>Kdo</th>
    </tr>
  </thead>
  <tbody>
    <?php
    $typIcon = ['prijem' => '➕ Příjem', 'vydej' => '➖ Výdej', 'inventura' => '📝 Inventura', 'korekce' => '🔧 Korekce', 'presun' => '↔ Přesun'];
    foreach ($pohybyData as $p):
    ?>
      <tr>
        <td class="muted"><?= htmlspecialchars(date('j. n. Y H:i', strtotime($p['kdy']))) ?></td>
        <td><?= htmlspecialchars($typIcon[$p['typ']] ?? $p['typ']) ?></td>
        <td><?= htmlspecialchars($p['item_nazev'] ?? '(?)') ?></td>
        <td class="num"><?= number_format((float) $p['mnozstvi'], 2, ',', ' ') ?></td>
        <td class="num"><?= $p['stav_po'] !== null ? number_format((float) $p['stav_po'], 2, ',', ' ') : '—' ?></td>
        <td class="muted"><?= htmlspecialchars($p['poznamka'] ?? '') ?></td>
        <td class="muted"><?= htmlspecialchars($p['kdo'] ?? '') ?></td>
      </tr>
    <?php endforeach; ?>
  </tbody>
</table>
<?php endif; ?>

<p style="margin-top: 28px; padding-top: 14px; border-top: 1px solid #e5e5e7; font-size: 11px; color: #86868b; text-align: center;">
  📦 Vygenerováno z APPEK B2B · <?= htmlspecialchars(date('j. n. Y H:i')) ?> · sklad <?= htmlspecialchars($sklad['kod']) ?>
</p>

</body>
</html>
    <?php
    exit;
}

// Fallback
http_response_code(400);
echo "Neznámý formát: $format. Podporované: csv, json, xml, pdf, html";
