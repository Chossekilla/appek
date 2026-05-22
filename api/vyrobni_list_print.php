<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/_admin_auth.php';
require_admin();

$pdo = db();
$datum = $_GET['datum'] ?? date('Y-m-d', strtotime('+1 day'));
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $datum)) {
    http_response_code(400); die('Neplatné datum');
}

$stmt = $pdo->prepare("
    SELECT v.id, v.nazev, v.cislo, v.hmotnost_g,
           k.nazev AS kategorie, k.ikona,
           SUM(p.mnozstvi) AS celkem,
           j.kod AS jednotka
    FROM objednavky_polozky p
    JOIN objednavky o ON o.id = p.objednavka_id
    JOIN vyrobky v ON v.id = p.vyrobek_id
    LEFT JOIN kategorie_vyrobku k ON k.id = v.kategorie_id
    LEFT JOIN jednotky j ON j.id = v.jednotka_id
    WHERE o.datum_dodani = :datum AND o.stav NOT IN ('zrusena')
    GROUP BY v.id, v.nazev, v.cislo, v.hmotnost_g, k.nazev, k.ikona, k.poradi, v.poradi, j.kod
    ORDER BY k.poradi, v.poradi, v.nazev
");
$stmt->execute(['datum' => $datum]);
$souhrn = $stmt->fetchAll();

$stmt = $pdo->prepare("
    SELECT od.id AS odberatel_id, od.nazev AS odberatel,
           md.id AS misto_id, md.nazev AS misto,
           md.ulice, md.mesto, md.psc, md.kontaktni_osoba,
           md.telefon AS misto_tel, md.cas_dodani, md.pokyny_pro_ridice,
           o.cislo AS objednavka_cislo, o.poznamka,
           v.nazev AS vyrobek, p.mnozstvi
    FROM objednavky o
    JOIN odberatele od ON od.id = o.odberatel_id
    LEFT JOIN mista_dodani md ON md.id = o.misto_dodani_id
    JOIN objednavky_polozky p ON p.objednavka_id = o.id
    JOIN vyrobky v ON v.id = p.vyrobek_id
    WHERE o.datum_dodani = :datum AND o.stav NOT IN ('zrusena')
    ORDER BY od.nazev, md.nazev, o.cislo, v.nazev
");
$stmt->execute(['datum' => $datum]);
$rozpis = $stmt->fetchAll();

$po_pobockach = [];
foreach ($rozpis as $r) {
    $key = ($r['misto_id'] ?? 'no') . '|' . $r['odberatel_id'];
    if (!isset($po_pobockach[$key])) {
        $po_pobockach[$key] = [
            'odberatel' => $r['odberatel'],
            'misto' => $r['misto'] ?? '—',
            'adresa' => trim(($r['ulice'] ?? '') . ', ' . ($r['mesto'] ?? '') . ' ' . ($r['psc'] ?? ''), ' ,'),
            'kontakt' => $r['kontaktni_osoba'],
            'telefon' => $r['misto_tel'],
            'cas_dodani' => $r['cas_dodani'],
            'pokyny' => $r['pokyny_pro_ridice'],
            'objednavka_cislo' => $r['objednavka_cislo'],
            'poznamka' => $r['poznamka'],
            'polozky' => [],
        ];
    }
    $po_pobockach[$key]['polozky'][] = ['vyrobek' => $r['vyrobek'], 'mnozstvi' => $r['mnozstvi']];
}
?>
<!DOCTYPE html>
<html lang="cs">
<head>
<meta charset="UTF-8">
<title>Výrobní list <?= esc(fmt_date($datum)) ?></title>
<style>
  *{box-sizing:border-box;margin:0;padding:0}
  body{font-family:'Helvetica Neue',Arial,sans-serif;color:#2C2C2A;background:#f5f5f0;padding:20px}
  .toolbar{max-width:210mm;margin:0 auto 16px;padding:12px 16px;background:#fff;border-radius:8px;display:flex;gap:8px;justify-content:flex-end;box-shadow:0 1px 3px rgba(0,0,0,.08)}
  .toolbar .btn{padding:8px 16px;border:1px solid rgba(0,0,0,.16);background:#fff;border-radius:6px;cursor:pointer;font-size:14px;font-family:inherit}
  .toolbar .btn-primary{background:#BA7517;color:#fff;border-color:#BA7517}
  .info{color:#888;font-size:13px;margin-right:auto;align-self:center}
  .page{max-width:210mm;margin:0 auto;padding:18mm 20mm;background:#fff;min-height:297mm;box-shadow:0 2px 8px rgba(0,0,0,.1);font-size:11pt}
  h1{font-size:28pt;color:#BA7517;font-weight:600;margin-bottom:4mm}
  .subtitle{font-size:14pt;color:#555;margin-bottom:12mm}
  h2{font-size:16pt;color:#854F0B;margin:8mm 0 4mm;padding-bottom:2mm;border-bottom:2px solid #FAEEDA}
  table.souhrn{width:100%;border-collapse:collapse;margin-bottom:4mm}
  table.souhrn th{background:#F1EFE8;padding:3mm 5mm;text-align:left;font-size:9pt;color:#888;text-transform:uppercase;border-bottom:1px solid #aaa;font-weight:500}
  table.souhrn td{padding:3mm 5mm;border-bottom:1px solid #E5E3DD;font-size:11pt}
  table.souhrn td.num,table.souhrn th.num{text-align:right}
  table.souhrn .ikona{font-size:18pt;width:30px;text-align:center}
  table.souhrn .pocet{font-size:14pt;font-weight:700;color:#854F0B}
  /* Stats bar nahoře */
  .stats-bar{display:grid;grid-template-columns:repeat(4,1fr);gap:3mm;margin-bottom:8mm}
  .stat-mini{background:#FFF9F0;border:1px solid #E8D5B0;border-radius:4px;padding:3mm 4mm;text-align:center}
  .stat-mini-label{font-size:8pt;color:#854F0B;text-transform:uppercase;letter-spacing:0.5px;font-weight:500}
  .stat-mini-val{font-size:18pt;font-weight:700;color:#BA7517;margin-top:1mm;line-height:1}
  /* Kategorie sekce */
  .kat-section{margin-bottom:6mm;page-break-inside:avoid}
  .kat-head{background:#FAEEDA;padding:3mm 5mm;border-left:4px solid #BA7517;border-radius:0 4px 4px 0;font-size:13pt;font-weight:600;color:#854F0B;margin-bottom:1mm}
  .kat-count{color:#888;font-weight:400;font-size:11pt}
  .pobocka{background:#FAFAF8;padding:5mm 7mm;border-radius:6px;margin-bottom:6mm;page-break-inside:avoid;border-left:4px solid #FAC775}
  .pobocka-head{display:flex;justify-content:space-between;align-items:flex-start;margin-bottom:4mm}
  .pobocka-name{font-weight:700;font-size:13pt;margin-bottom:2px}
  .pobocka-misto{font-size:11pt;color:#854F0B;margin-bottom:1mm}
  .pobocka-meta{font-size:9pt;color:#888;line-height:1.6}
  .pobocka-cislo{font-size:9pt;color:#888;text-align:right}
  .cas-box{background:#FAEEDA;padding:2mm 5mm;border-radius:4px;margin:2mm 0;display:inline-block;font-size:10pt;color:#854F0B;font-weight:500}
  .pobocka-polozky{margin-top:3mm}
  .pobocka-polozky table{width:100%}
  .pobocka-polozky tr{border-bottom:1px dashed #ddd}
  .pobocka-polozky td{padding:2mm 0;font-size:10pt}
  .pobocka-polozky td:last-child{text-align:right;font-weight:600;width:80px}
  .pobocka-pokyny{background:#E6F1FB;color:#0C447C;padding:2mm 4mm;border-radius:4px;margin-top:3mm;font-size:9pt}
  .pobocka-pozn{background:#FAEEDA;color:#854F0B;padding:2mm 4mm;border-radius:4px;margin-top:2mm;font-size:9pt}
  .foot{margin-top:12mm;text-align:center;font-size:9pt;color:#aaa;padding-top:4mm;border-top:1px solid #eee}
  @media print {
    body { background: #fff; padding: 0; margin: 0; }
    .toolbar { display: none; }
    .page {
      box-shadow: none;
      padding: 8mm 12mm;
      max-width: none;
      margin: 0;
      min-height: 0;
    }
    @page {
      size: A4;
      margin: 0;
    }
    table tr { page-break-inside: avoid; }
  }
</style>
</head>
<body>
<div class="toolbar">
  <span class="info">💡 Pro PDF klikněte „Tisk" → v dialogu „Uložit jako PDF"</span>
  <button class="btn" onclick="window.history.back()">← Zpět</button>
  <button class="btn btn-primary" onclick="window.print()">🖨 Tisk / Uložit PDF</button>
</div>
<?php
  // Statistiky pro hlavičku
  $celkem_kusu = 0;
  $kategorie_grouped = [];
  foreach ($souhrn as $s) {
    $celkem_kusu += (float) $s['celkem'];
    $kat = $s['kategorie'] ?? 'Bez kategorie';
    if (!isset($kategorie_grouped[$kat])) {
      $kategorie_grouped[$kat] = ['ikona' => $s['ikona'] ?? '🥖', 'items' => []];
    }
    $kategorie_grouped[$kat]['items'][] = $s;
  }
  $pocet_objednavek = count(array_unique(array_column($rozpis, 'objednavka_cislo')));
  $pocet_mist = count($po_pobockach);

  // Den v týdnu
  $dny_cz = ['neděle','pondělí','úterý','středa','čtvrtek','pátek','sobota'];
  $den_nazev = $dny_cz[(int) date('w', strtotime($datum))];
?>
<div class="page">
  <h1>🥖 Výrobní list</h1>
  <div class="subtitle"><?= esc(ucfirst($den_nazev)) ?> · <strong><?= esc(fmt_date($datum)) ?></strong></div>

  <?php if (!empty($souhrn)): ?>
    <div class="stats-bar">
      <div class="stat-mini"><div class="stat-mini-label">Objednávek</div><div class="stat-mini-val"><?= $pocet_objednavek ?></div></div>
      <div class="stat-mini"><div class="stat-mini-label">Míst dodání</div><div class="stat-mini-val"><?= $pocet_mist ?></div></div>
      <div class="stat-mini"><div class="stat-mini-label">Druhů výrobků</div><div class="stat-mini-val"><?= count($souhrn) ?></div></div>
      <div class="stat-mini"><div class="stat-mini-label">Celkem ks</div><div class="stat-mini-val"><?= fmt_ks($celkem_kusu) ?></div></div>
    </div>
  <?php endif; ?>

  <h2>📋 Souhrn výroby — kolik celkem upéct</h2>
  <?php if (empty($souhrn)): ?>
    <p style="color:#888;padding:20mm 0;text-align:center;">Žádné objednávky na tento den.</p>
  <?php else: ?>
    <?php foreach ($kategorie_grouped as $kat => $kdata): ?>
      <div class="kat-section">
        <div class="kat-head"><?= esc($kdata['ikona']) ?> <?= esc($kat) ?> <span class="kat-count">(<?= count($kdata['items']) ?>)</span></div>
        <table class="souhrn">
          <tbody>
            <?php foreach ($kdata['items'] as $s): ?>
              <tr>
                <td><strong><?= esc($s['nazev']) ?></strong> <span style="color:#888;font-size:9pt;">č. <?= esc($s['cislo']) ?></span></td>
                <td style="color:#888;font-size:10pt;width:80px"><?= $s['hmotnost_g'] ? esc($s['hmotnost_g']) . ' g/ks' : '' ?></td>
                <td class="num pocet" style="width:120px"><?= fmt_ks($s['celkem']) ?> <?= esc($s['jednotka'] ?? 'ks') ?></td>
                <td class="num" style="width:90px;color:#666"><?= $s['hmotnost_g'] ? round($s['celkem'] * $s['hmotnost_g'] / 1000, 1) . ' kg' : '—' ?></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php endforeach; ?>
  <?php endif; ?>

  <?php if (!empty($po_pobockach)): ?>
    <h2>📦 Rozpis pro expedici (po pobočkách odběratelů)</h2>
    <?php foreach ($po_pobockach as $p): ?>
      <div class="pobocka">
        <div class="pobocka-head">
          <div>
            <div class="pobocka-name"><?= esc($p['odberatel']) ?></div>
            <div class="pobocka-misto">📍 <?= esc($p['misto']) ?></div>
            <div class="pobocka-meta">
              <?php if ($p['adresa']): ?><?= esc($p['adresa']) ?><br><?php endif; ?>
              <?php if ($p['kontakt']): ?>👤 <?= esc($p['kontakt']) ?><?php endif; ?>
              <?php if ($p['telefon']): ?> · 📞 <?= esc($p['telefon']) ?><?php endif; ?>
            </div>
          </div>
          <div class="pobocka-cislo">
            <?= esc($p['objednavka_cislo']) ?>
            <?php if ($p['cas_dodani']): ?>
              <div class="cas-box">⏰ <?= esc($p['cas_dodani']) ?></div>
            <?php endif; ?>
          </div>
        </div>
        <div class="pobocka-polozky">
          <table>
            <?php foreach ($p['polozky'] as $pol): ?>
              <tr>
                <td><?= esc($pol['vyrobek']) ?></td>
                <td><?= fmt_ks($pol['mnozstvi']) ?> ks</td>
              </tr>
            <?php endforeach; ?>
          </table>
        </div>
        <?php if ($p['pokyny']): ?>
          <div class="pobocka-pokyny">🚚 Pokyny pro řidiče: <?= esc($p['pokyny']) ?></div>
        <?php endif; ?>
        <?php if ($p['poznamka']): ?>
          <div class="pobocka-pozn">📝 <?= esc($p['poznamka']) ?></div>
        <?php endif; ?>
      </div>
    <?php endforeach; ?>
  <?php endif; ?>

  <div class="foot">Vytištěno <?= date('j. n. Y H:i') ?> · <?= esc(firma('nazev', 'APPEK B2B')) ?></div>
</div>
</body>
</html>
