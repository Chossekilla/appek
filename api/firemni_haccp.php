<?php
/**
 * Tisk HACCP dokumentu — buď jeden dokument (?id=X) nebo master příručka (?master=1)
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/_admin_auth.php';
require_admin();

$pdo = db();

$id = (int) ($_GET['id'] ?? 0);
$master = !empty($_GET['master']);
$autoprint = !empty($_GET['autoprint']);
$download  = !empty($_GET['download']); // ?download=1 → server pošle Content-Disposition: attachment

$firma_nazev = nastaveni_get($pdo, 'firma_nazev', 'APPEK pekařství s.r.o.');
$firma_ulice = nastaveni_get($pdo, 'firma_ulice', '');
$firma_mesto = nastaveni_get($pdo, 'firma_mesto', '');
$firma_psc   = nastaveni_get($pdo, 'firma_psc', '');
$firma_ico   = nastaveni_get($pdo, 'firma_ico', '');
$firma_tel   = nastaveni_get($pdo, 'firma_telefon', '');

// Kategorie pro hlavičky
$kategorieLabel = [
    'plan_haccp'         => 'Plán systému kritických bodů',
    'sanitacni_rad'      => 'Sanitační řád',
    'instruktaz_hygieny' => 'Vstupní instruktáž — osobní hygiena',
    'postupy_ccp'        => 'Postupy CCP',
    'formulare'          => 'Formuláře',
    'skoleni'            => 'Záznamy o školení',
];

if ($id > 0) {
    $stmt = $pdo->prepare("SELECT * FROM haccp_dokumenty WHERE id = ?");
    $stmt->execute([$id]);
    $row = $stmt->fetch();
    if (!$row) { http_response_code(404); die('Dokument nenalezen'); }
    $dokumenty = [$row];
    $title = $row['nazev'];
} elseif ($master) {
    $stmt = $pdo->query("SELECT * FROM haccp_dokumenty WHERE aktivni = 1 ORDER BY kategorie, poradi, nazev");
    $dokumenty = $stmt->fetchAll();
    $title = 'Příručka systému kritických bodů (HACCP)';
} else {
    http_response_code(400); die('Chybí id nebo master=1');
}

// 💾 Download hlavička — prohlížeč nabídne uložení jako .html (lze otevřít a vytisknout do PDF)
if ($download) {
    $safeName = preg_replace('/[^A-Za-z0-9\-_ěščřžýáíéúůďťňĎŤŇĚŠČŘŽÝÁÍÉÚŮ]+/u', '_', $title);
    $safeName = trim($safeName, '_');
    if ($safeName === '') $safeName = 'haccp-dokument';
    header('Content-Type: text/html; charset=UTF-8');
    header('Content-Disposition: attachment; filename="' . $safeName . '.html"');
    header('X-Content-Type-Options: nosniff');
}
?>
<!DOCTYPE html>
<html lang="cs">
<head>
<meta charset="UTF-8">
<title><?= esc($title) ?> — <?= esc($firma_nazev) ?></title>
<style>
  *{box-sizing:border-box;margin:0;padding:0}
  body{font-family:'Times New Roman',Georgia,serif;color:#000;background:#f5f5f0;padding:20px;font-size:11pt;line-height:1.55}
  .toolbar{max-width:210mm;margin:0 auto 16px;padding:12px 16px;background:#fff;border-radius:8px;display:flex;gap:8px;justify-content:flex-end;box-shadow:0 1px 3px rgba(0,0,0,.08);font-family:-apple-system,sans-serif}
  .toolbar .btn{padding:8px 16px;border:1px solid rgba(0,0,0,.16);background:#fff;border-radius:6px;cursor:pointer;font-size:14px;font-family:inherit}
  .toolbar .btn-primary{background:#BA7517;color:#fff;border-color:#BA7517}

  .page{max-width:210mm;margin:0 auto 16px;padding:18mm 18mm;background:#fff;min-height:297mm;box-shadow:0 2px 8px rgba(0,0,0,.1)}
  .page + .page{page-break-before:always}

  .doc-head{border-bottom:2px solid #BA7517;padding-bottom:6mm;margin-bottom:6mm;display:flex;justify-content:space-between;align-items:end}
  .doc-head .firma{font-size:9pt;color:#666;line-height:1.3}
  .doc-head .firma strong{color:#000;font-size:10pt}
  .doc-head .doc-meta{text-align:right;font-size:9pt;color:#666}

  .doc-kategorie{font-size:10pt;color:#854F0B;text-transform:uppercase;letter-spacing:1pt;font-weight:600;margin-bottom:1mm}
  .doc-title{font-size:18pt;font-weight:700;color:#000;margin-bottom:6mm}

  .obsah{font-size:11pt;line-height:1.6}
  .obsah h2{font-size:16pt;margin-top:6mm;margin-bottom:3mm;color:#854F0B}
  .obsah h3{font-size:13pt;margin-top:5mm;margin-bottom:2mm;color:#000;border-bottom:1px solid #ddd;padding-bottom:1mm}
  .obsah h4{font-size:11.5pt;margin-top:4mm;margin-bottom:1mm;color:#000;font-weight:700}
  .obsah p{margin-bottom:3mm}
  .obsah ul, .obsah ol{margin:2mm 0 3mm 8mm}
  .obsah li{margin-bottom:1mm}
  .obsah dl{margin:2mm 0 3mm 4mm}
  .obsah dt{font-weight:700;margin-top:2mm}
  .obsah dd{margin-left:4mm;margin-bottom:1mm}
  .obsah table{width:100%;border-collapse:collapse;margin:3mm 0;font-size:10pt}
  .obsah th, .obsah td{border:0.4mm solid #999;padding:2mm 3mm;vertical-align:top;text-align:left}
  .obsah th{background:#f5f5f0;font-weight:600}
  .obsah strong{color:#000}
  .obsah em{color:#444}
  .obsah hr{border:none;border-top:1px dashed #999;margin:3mm 0}

  .doc-foot{margin-top:auto;padding-top:6mm;border-top:1px solid #ccc;display:flex;justify-content:space-between;font-size:8.5pt;color:#666}

  /* Master cover page */
  .cover{display:flex;flex-direction:column;justify-content:center;align-items:center;height:240mm;text-align:center}
  .cover .firma{font-size:13pt;font-weight:600;margin-bottom:8mm;color:#854F0B}
  .cover .nadpis{font-size:36pt;font-weight:700;color:#000;line-height:1.2;margin-bottom:14mm;letter-spacing:1pt}
  .cover .podtitulek{font-size:14pt;color:#666;margin-bottom:12mm;font-style:italic}
  .cover .firma-box{margin-top:30mm;padding:8mm 12mm;border:2px solid #BA7517;border-radius:6mm;background:#FFFAF1}
  .cover .firma-box strong{font-size:13pt;color:#854F0B;display:block;margin-bottom:2mm}
  .cover .datum{margin-top:14mm;font-size:11pt;color:#666}

  /* Obsah (TOC) */
  .toc{padding:8mm 0}
  .toc h2{font-size:20pt;color:#854F0B;margin-bottom:8mm}
  .toc-kat{font-size:13pt;font-weight:700;color:#000;margin-top:6mm;margin-bottom:2mm;border-bottom:1px solid #ddd;padding-bottom:1mm}
  .toc-doc{padding:1mm 0 1mm 6mm;font-size:10.5pt;display:flex;justify-content:space-between}
  .toc-doc .name{flex:1}
  .toc-doc .dots{flex:0 0 auto;color:#bbb;padding:0 2mm}

  @media print {
    body{background:#fff;padding:0;margin:0}
    .toolbar{display:none}
    .page{box-shadow:none;padding:14mm 14mm;margin:0;max-width:none;min-height:auto}
    @page{size:A4;margin:0}
    .obsah table{page-break-inside:avoid}
    .obsah tr{page-break-inside:avoid}
  }
</style>
</head>
<body>

<div class="toolbar">
  <span style="color:#888;font-size:13px;margin-right:auto;align-self:center">
    <?php if ($master): ?>
      📚 Příručka systému kritických bodů — <?= count($dokumenty) ?> dokumentů
    <?php else: ?>
      📄 <?= esc($title) ?>
    <?php endif; ?>
  </span>
  <button class="btn" onclick="window.history.back()">← Zpět</button>
  <button class="btn btn-primary" onclick="window.print()">🖨 Tisk / PDF</button>
</div>

<?php if ($master): ?>
  <!-- Cover page -->
  <div class="page">
    <div class="cover">
      <div class="firma"><?= esc($firma_nazev) ?></div>
      <div class="nadpis">PŘÍRUČKA<br>SYSTÉMU<br>KRITICKÝCH BODŮ</div>
      <div class="podtitulek">(HACCP)</div>
      <div class="firma-box">
        <strong>PEKAŘSTVÍ A CUKRÁŘSTVÍ</strong>
        <?= esc($firma_ulice) ?><br>
        <?= esc($firma_mesto) ?> <?= esc($firma_psc) ?><br>
        <?php if ($firma_tel): ?>Tel: <?= esc($firma_tel) ?><?php endif; ?>
        <?php if ($firma_ico): ?><br>IČO: <?= esc($firma_ico) ?><?php endif; ?>
      </div>
      <div class="datum">V <?= esc($firma_mesto ?: 'Karlových Varech') ?> dne <?= date('j. n. Y') ?></div>
    </div>
  </div>

  <!-- Table of contents -->
  <div class="page">
    <div class="toc">
      <h2>📚 Obsah</h2>
      <?php
        $byKat = [];
        foreach ($dokumenty as $d) $byKat[$d['kategorie']][] = $d;
        foreach ($kategorieLabel as $kat => $label):
          if (empty($byKat[$kat])) continue;
      ?>
        <div class="toc-kat"><?= esc($label) ?></div>
        <?php foreach ($byKat[$kat] as $d): ?>
          <div class="toc-doc">
            <span class="name"><?= esc($d['nazev']) ?></span>
            <span class="dots">·······</span>
          </div>
        <?php endforeach; ?>
      <?php endforeach; ?>
    </div>
  </div>
<?php endif; ?>

<?php foreach ($dokumenty as $idx => $d): ?>
<div class="page">
  <div class="doc-head">
    <div class="firma">
      <strong><?= esc($firma_nazev) ?></strong><br>
      <?= esc($firma_ulice) ?>, <?= esc($firma_mesto) ?> <?= esc($firma_psc) ?>
      <?php if ($firma_tel): ?> · tel: <?= esc($firma_tel) ?><?php endif; ?>
    </div>
    <div class="doc-meta">
      <?= date('j. n. Y', strtotime($d['upraveno'] ?: $d['vytvoreno'] ?? 'now')) ?>
    </div>
  </div>

  <div class="doc-kategorie"><?= esc($kategorieLabel[$d['kategorie']] ?? $d['kategorie']) ?></div>
  <div class="doc-title"><?= esc($d['nazev']) ?></div>

  <div class="obsah"><?= $d['obsah'] /* HTML — uložené z editoru, věřím že je čisté */ ?></div>

  <div class="doc-foot">
    <div><?= esc($firma_nazev) ?></div>
    <div>str. <?= $idx + 1 ?><?= $master ? ' / ' . count($dokumenty) : '' ?></div>
  </div>
</div>
<?php endforeach; ?>

<?php if ($autoprint): ?>
<script>
  window.addEventListener('load', () => setTimeout(() => window.print(), 300));
</script>
<?php endif; ?>
</body>
</html>
