<?php
/**
 * 🖨️ POS PRINT — Tisk pro kuchyni (80mm thermal) + účet pro hosta.
 *
 * URL:
 *   GET ?ucet_id=N&typ=kuchyne   → kuchyňský bon (jen nové položky, oddělené po kurzu)
 *   GET ?ucet_id=N&typ=ucet      → účet pro hosta (kompletní)
 *   GET ?ucet_id=N&typ=ucet&autoprint=1 → vytiskne automaticky
 *   GET ?ucet_id=N&typ=preview   → A4 preview pro tisk laserovou
 *
 * Po tisku kuchyňského bonu se polozky označí kuchyne_tisk=1 (aby se netisklo znovu).
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/_admin_auth.php';
require_once __DIR__ . '/_packages_lib.php';

require_admin();

if (!package_enabled('restaurace')) {
    http_response_code(402);
    die('Vyžaduje balíček 🍕 Restaurace');
}

$pdo = db();
$ucetId = (int) ($_GET['ucet_id'] ?? 0);
$typ = $_GET['typ'] ?? 'ucet';
$autoprint = !empty($_GET['autoprint']);

if (!$ucetId) { http_response_code(400); die('Chybí ucet_id'); }

$u = $pdo->prepare("SELECT u.*, t.nazev AS stul_nazev FROM restaurant_pos_ucty u INNER JOIN restaurant_tables t ON t.id = u.stul_id WHERE u.id = :id");
$u->execute(['id' => $ucetId]);
$ucet = $u->fetch();
if (!$ucet) { http_response_code(404); die('Účet nenalezen'); }

// Filter položek
if ($typ === 'kuchyne') {
    // Jen netisknuté + není storno
    $ps = $pdo->prepare("
        SELECT * FROM restaurant_pos_polozky
        WHERE ucet_id = :u AND kuchyne_tisk = 0 AND stav != 'storno'
        ORDER BY kurz, id
    ");
} else {
    // Vše vyjma storno
    $ps = $pdo->prepare("
        SELECT * FROM restaurant_pos_polozky
        WHERE ucet_id = :u AND stav != 'storno'
        ORDER BY kurz, id
    ");
}
$ps->execute(['u' => $ucetId]);
$polozky = $ps->fetchAll();

// Po tisku kuchyne: mark printed
if ($typ === 'kuchyne' && !empty($polozky)) {
    $ids = array_column($polozky, 'id');
    $place = implode(',', array_fill(0, count($ids), '?'));
    $pdo->prepare("UPDATE restaurant_pos_polozky SET kuchyne_tisk = 1 WHERE id IN ($place)")->execute($ids);
}

// Firma data
$firma = $pdo->query("SELECT nastaveni_klic, hodnota FROM nastaveni WHERE nastaveni_klic LIKE 'firma_%'")->fetchAll(PDO::FETCH_KEY_PAIR);
$firmaNazev = $firma['firma_nazev'] ?? 'Restaurace';

// Group items by kurz (pro kuchyni)
$kurzy = [];
foreach ($polozky as $p) {
    $k = (int) ($p['kurz'] ?? 1);
    $kurzy[$k][] = $p;
}
ksort($kurzy);

$kurzNazev = [1 => '1. Předkrm', 2 => '2. Hlavní', 3 => '3. Dezert', 4 => '4. Nápoje', 5 => '5. Extra'];

$total = 0;
foreach ($polozky as $p) {
    $total += $p['jednotkova_cena'] * $p['mnozstvi'];
}
?><!DOCTYPE html>
<html lang="cs"><head>
<meta charset="UTF-8">
<title><?= $typ === 'kuchyne' ? '🍳 Kuchyně' : '🧾 Účet' ?> #<?= $ucetId ?> · <?= esc($ucet['stul_nazev']) ?></title>
<style>
  * { margin: 0; padding: 0; box-sizing: border-box; }
  body {
    font-family: 'SF Mono', 'Courier New', monospace;
    background: #f3f4f6;
    padding: 20px;
    color: #000;
  }
  .toolbar {
    max-width: 80mm; margin: 0 auto 12px; display: flex; gap: 6px;
    background: #fff; padding: 10px; border-radius: 8px;
    font-family: -apple-system, sans-serif;
  }
  .toolbar button {
    flex: 1; padding: 10px; border: 1px solid rgba(0,0,0,0.16);
    background: #fff; border-radius: 6px; font-size: 14px; cursor: pointer;
  }
  .toolbar button.primary { background: #BA7517; color: #fff; border-color: #BA7517; }
  .ticket {
    width: 80mm; margin: 0 auto; background: #fff;
    padding: 8mm 6mm; box-shadow: 0 2px 12px rgba(0,0,0,0.1);
    font-size: 12pt; line-height: 1.4;
  }
  .ticket .center { text-align: center; }
  .ticket .big { font-size: 16pt; font-weight: 700; }
  .ticket .huge { font-size: 22pt; font-weight: 800; letter-spacing: 1pt; }
  .ticket hr { border: 0; border-top: 1px dashed #000; margin: 4mm 0; }
  .ticket .row { display: flex; justify-content: space-between; gap: 4mm; }
  .ticket .item-row { display: flex; gap: 4mm; margin: 1mm 0; }
  .ticket .item-row .qty { width: 12mm; font-weight: 700; }
  .ticket .item-row .name { flex: 1; }
  .ticket .item-row .price { text-align: right; font-weight: 600; }
  .ticket .kurz-header { font-weight: 700; font-size: 13pt; background: #000; color: #fff; padding: 2mm 3mm; margin: 3mm 0 2mm; text-transform: uppercase; }
  .ticket .footer { margin-top: 4mm; font-size: 10pt; text-align: center; color: #444; }
  .ticket .poznamka { font-size: 10pt; color: #444; font-style: italic; padding-left: 16mm; }
  @media print {
    body { background: white; padding: 0; margin: 0; }
    .toolbar { display: none; }
    .ticket { box-shadow: none; width: 80mm; padding: 4mm; margin: 0; }
    @page { size: 80mm auto; margin: 0; }
  }
</style>
</head>
<body>

<div class="toolbar">
  <button onclick="window.close()">← Zavřít</button>
  <button class="primary" onclick="window.print()">🖨️ Tisk</button>
</div>

<?php if ($typ === 'kuchyne'): ?>

<div class="ticket">
  <div class="center huge">KUCHYNĚ</div>
  <div class="center big">🍽️ <?= esc($ucet['stul_nazev']) ?></div>
  <hr>
  <div class="row">
    <div>Účet: <strong>#<?= $ucetId ?></strong></div>
    <div><?= date('d.m.Y H:i') ?></div>
  </div>
  <div>Obsluha: <strong><?= esc($ucet['otevrel_jmeno'] ?? '—') ?></strong></div>
  <hr>

  <?php if (empty($polozky)): ?>
    <div class="center" style="padding: 8mm 0;color:#999">Žádné nové položky k tisku.</div>
  <?php else: ?>
    <?php foreach ($kurzy as $k => $items): ?>
      <div class="kurz-header"><?= esc($kurzNazev[$k] ?? "Kurz $k") ?></div>
      <?php foreach ($items as $p): ?>
        <div class="item-row">
          <div class="qty"><?= rtrim(rtrim(number_format($p['mnozstvi'], 2, ',', ''), '0'), ',') ?>×</div>
          <div class="name"><strong><?= esc($p['nazev']) ?></strong></div>
        </div>
        <?php if ($p['poznamka']): ?>
          <div class="poznamka">⚠️ <?= esc($p['poznamka']) ?></div>
        <?php endif; ?>
      <?php endforeach; ?>
    <?php endforeach; ?>
  <?php endif; ?>

  <hr>
  <div class="center footer">
    Bon vytištěn <?= date('H:i:s') ?>
  </div>
</div>

<?php else: ?>

<div class="ticket">
  <div class="center big"><?= esc($firmaNazev) ?></div>
  <?php if (!empty($firma['firma_ulice'])): ?>
    <div class="center" style="font-size:10pt"><?= esc($firma['firma_ulice'] ?? '') ?>, <?= esc($firma['firma_mesto'] ?? '') ?></div>
  <?php endif; ?>
  <?php if (!empty($firma['firma_ico'])): ?>
    <div class="center" style="font-size:10pt">IČO: <?= esc($firma['firma_ico']) ?><?= !empty($firma['firma_dic']) ? ' · DIČ: ' . esc($firma['firma_dic']) : '' ?></div>
  <?php endif; ?>
  <hr>

  <div class="row">
    <div>🍽️ <?= esc($ucet['stul_nazev']) ?></div>
    <div>#<?= $ucetId ?></div>
  </div>
  <div class="row">
    <div><?= date('d.m.Y H:i', strtotime($ucet['otevreno_v'])) ?></div>
    <div>Hostů: <?= (int) $ucet['pocet_hostu'] ?></div>
  </div>
  <?php if ($ucet['otevrel_jmeno']): ?>
    <div>Obsluha: <?= esc($ucet['otevrel_jmeno']) ?></div>
  <?php endif; ?>
  <hr>

  <?php foreach ($kurzy as $k => $items): ?>
    <?php foreach ($items as $p):
      $sum = $p['jednotkova_cena'] * $p['mnozstvi'];
    ?>
      <div class="item-row">
        <div class="qty"><?= rtrim(rtrim(number_format($p['mnozstvi'], 2, ',', ''), '0'), ',') ?>×</div>
        <div class="name"><?= esc($p['nazev']) ?></div>
        <div class="price"><?= number_format($sum, 2, ',', ' ') ?></div>
      </div>
    <?php endforeach; ?>
  <?php endforeach; ?>

  <hr>
  <div class="row big" style="margin-top:3mm">
    <div>CELKEM</div>
    <div><?= number_format($total, 2, ',', ' ') ?> Kč</div>
  </div>

  <?php
    $platby = $pdo->prepare("SELECT * FROM restaurant_pos_platby WHERE ucet_id = :u");
    $platby->execute(['u' => $ucetId]);
    $platbySeznam = $platby->fetchAll();
    if ($platbySeznam):
  ?>
    <hr>
    <?php foreach ($platbySeznam as $pl): ?>
      <div class="row">
        <div><?= esc(ucfirst($pl['zpusob'])) ?></div>
        <div><?= number_format($pl['castka'], 2, ',', ' ') ?> Kč</div>
      </div>
    <?php endforeach; ?>
  <?php endif; ?>

  <hr>
  <div class="center footer">
    Doklad: <?= esc($ucet['cislo_dokladu'] ?: 'POS-' . date('Ymd') . '-' . $ucetId) ?><br>
    Vystaveno <?= date('d.m.Y H:i:s') ?><br><br>
    Děkujeme za návštěvu! 🙏
  </div>
</div>

<?php endif; ?>

<?php if ($autoprint): ?>
<script>setTimeout(() => window.print(), 400);</script>
<?php endif; ?>

</body></html>
