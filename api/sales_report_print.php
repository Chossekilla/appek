<?php
/**
 * Sales report — A4 tisková stránka (HTML s print CSS).
 * GET ?od=YYYY-MM-DD&do=YYYY-MM-DD
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/_admin_auth.php';
require_admin();

$pdo = db();
$od = $_GET['od'] ?? date('Y-m-01');
$do = $_GET['do'] ?? date('Y-m-t');

// Stejná data jako v admin_sales_report.php — duplikovat by bylo nepěkné, includneme
$_GET['od'] = $od;
$_GET['do'] = $do;

// Re-run queries (rychlejší než HTTP roundtrip)
$stats = $pdo->prepare("
    SELECT COUNT(DISTINCT o.id) AS celkem_obj,
           COUNT(DISTINCT o.odberatel_id) AS pocet_odberatelu,
           COALESCE(SUM(o.castka_celkem), 0) AS celkem_kc,
           COALESCE(AVG(o.castka_celkem), 0) AS prum_obj
    FROM objednavky o
    WHERE o.datum_dodani BETWEEN :od AND :do AND o.stav NOT IN ('zrusena')
");
$stats->execute(['od' => $od, 'do' => $do]);
$summary = $stats->fetch(PDO::FETCH_ASSOC);

$topVyr = $pdo->prepare("
    SELECT v.cislo, v.nazev, k.nazev AS kat_nazev,
           SUM(op.mnozstvi) AS celkem_ks,
           SUM(op.mnozstvi * op.cena_bez_dph * (1 + op.sazba_dph/100)) AS celkem_kc
    FROM objednavky o
    JOIN objednavky_polozky op ON op.objednavka_id = o.id
    LEFT JOIN vyrobky v ON v.id = op.vyrobek_id
    LEFT JOIN kategorie_vyrobku k ON k.id = v.kategorie_id
    WHERE o.datum_dodani BETWEEN :od AND :do AND o.stav NOT IN ('zrusena')
    GROUP BY v.id, v.cislo, v.nazev, k.nazev
    ORDER BY celkem_kc DESC LIMIT 15
");
$topVyr->execute(['od' => $od, 'do' => $do]);
$top_vyrobky = $topVyr->fetchAll(PDO::FETCH_ASSOC);

$topOdb = $pdo->prepare("
    SELECT od.nazev, COUNT(o.id) AS pocet_obj, SUM(o.castka_celkem) AS celkem_kc
    FROM objednavky o JOIN odberatele od ON od.id = o.odberatel_id
    WHERE o.datum_dodani BETWEEN :od AND :do AND o.stav NOT IN ('zrusena')
    GROUP BY od.id, od.nazev
    ORDER BY celkem_kc DESC LIMIT 10
");
$topOdb->execute(['od' => $od, 'do' => $do]);
$top_odberatele = $topOdb->fetchAll(PDO::FETCH_ASSOC);

$firma = nastaveni_get($pdo, 'firma_nazev', 'Provoz');
$ico   = nastaveni_get($pdo, 'firma_ico', '');
$dic   = nastaveni_get($pdo, 'firma_dic', '');
?><!DOCTYPE html>
<html lang="cs"><head>
<meta charset="UTF-8">
<title>Sales report <?= htmlspecialchars($od) ?> — <?= htmlspecialchars($do) ?></title>
<style>
@page { size: A4 portrait; margin: 14mm; }
* { box-sizing: border-box; }
body { font-family: -apple-system, "Segoe UI", Arial, sans-serif; color: #1a1a1a; font-size: 11pt; margin: 0; }
.head { display: flex; justify-content: space-between; align-items: flex-end; margin-bottom: 18px; border-bottom: 3px solid #BA7517; padding-bottom: 12px; }
.head .firma { font-size: 22pt; font-weight: 700; color: #1a1a1a; }
.head .obdobi { font-size: 14pt; color: #BA7517; font-weight: 700; }
.head .meta { text-align: right; font-size: 9pt; color: #888; }

.stats { display: grid; grid-template-columns: repeat(4, 1fr); gap: 10px; margin: 18px 0; }
.stat { background: #f7f8fa; padding: 14px; border-radius: 8px; border-left: 4px solid #BA7517; }
.stat-label { font-size: 9pt; color: #888; text-transform: uppercase; letter-spacing: 0.5px; }
.stat-value { font-size: 18pt; font-weight: 700; color: #1a1a1a; margin-top: 4px; }

h2 { margin: 22px 0 8px; padding: 6px 12px; background: linear-gradient(90deg, #BA7517, transparent); color: #fff; border-radius: 4px; font-size: 13pt; }
table { width: 100%; border-collapse: collapse; font-size: 10pt; }
th, td { padding: 6px 10px; border-bottom: 1px solid #eee; text-align: left; }
th { background: #f0f0f0; font-weight: 700; color: #444; font-size: 9pt; text-transform: uppercase; letter-spacing: 0.3px; }
td.num, th.num { text-align: right; font-variant-numeric: tabular-nums; }
.rank { font-weight: 700; color: #BA7517; width: 40px; }
.footer { margin-top: 28px; padding-top: 12px; border-top: 1px solid #ccc; font-size: 9pt; color: #888; text-align: center; }

@media print { .no-print { display: none; } body { padding: 0; } }
.no-print { margin: 20px auto; text-align: center; }
.no-print button { padding: 10px 24px; font-size: 14px; background: #BA7517; color: #fff; border: none; border-radius: 6px; cursor: pointer; font-weight: 700; }
</style>
</head>
<body>

<div class="head">
  <div>
    <div class="firma"><?= htmlspecialchars($firma) ?></div>
    <?php if ($ico): ?><div style="font-size:10pt;color:#666;margin-top:4px">IČO: <?= htmlspecialchars($ico) ?><?php if ($dic): ?> · DIČ: <?= htmlspecialchars($dic) ?><?php endif; ?></div><?php endif; ?>
  </div>
  <div>
    <div class="obdobi">📊 Sales report</div>
    <div class="meta">
      <?= htmlspecialchars(fmt_date($od)) ?> — <?= htmlspecialchars(fmt_date($do)) ?><br>
      Vytištěno <?= date('j. n. Y H:i') ?>
    </div>
  </div>
</div>

<!-- Souhrnné statistiky -->
<div class="stats">
  <div class="stat">
    <div class="stat-label">Objednávek</div>
    <div class="stat-value"><?= (int) $summary['celkem_obj'] ?></div>
  </div>
  <div class="stat">
    <div class="stat-label">Aktivních odběratelů</div>
    <div class="stat-value"><?= (int) $summary['pocet_odberatelu'] ?></div>
  </div>
  <div class="stat">
    <div class="stat-label">Celková tržba (s DPH)</div>
    <div class="stat-value" style="color:#22863a"><?= number_format($summary['celkem_kc'], 0, ',', ' ') ?> Kč</div>
  </div>
  <div class="stat">
    <div class="stat-label">Průměrná obj.</div>
    <div class="stat-value"><?= number_format($summary['prum_obj'], 0, ',', ' ') ?> Kč</div>
  </div>
</div>

<!-- Top výrobky -->
<h2>🏆 TOP 15 nejprodávanějších výrobků</h2>
<table>
  <thead>
    <tr>
      <th class="rank">#</th>
      <th>Kód</th>
      <th>Výrobek</th>
      <th>Kategorie</th>
      <th class="num">Prodáno (ks)</th>
      <th class="num">Tržba</th>
      <th class="num">% z celku</th>
    </tr>
  </thead>
  <tbody>
    <?php $celkem = (float) $summary['celkem_kc']; foreach ($top_vyrobky as $i => $v): ?>
      <tr>
        <td class="rank"><?= $i + 1 ?>.</td>
        <td style="color:#666;font-size:9pt"><?= htmlspecialchars($v['cislo'] ?? '') ?></td>
        <td><strong><?= htmlspecialchars($v['nazev'] ?? '—') ?></strong></td>
        <td style="color:#666;font-size:9pt"><?= htmlspecialchars($v['kat_nazev'] ?? '—') ?></td>
        <td class="num"><?= number_format((float)$v['celkem_ks'], 0, ',', ' ') ?></td>
        <td class="num"><strong><?= number_format((float)$v['celkem_kc'], 2, ',', ' ') ?> Kč</strong></td>
        <td class="num" style="color:#888"><?= $celkem > 0 ? round((float)$v['celkem_kc'] / $celkem * 100, 1) : 0 ?> %</td>
      </tr>
    <?php endforeach; ?>
  </tbody>
</table>

<!-- Top odběratelé -->
<h2>👥 TOP 10 odběratelů</h2>
<table>
  <thead>
    <tr>
      <th class="rank">#</th>
      <th>Odběratel</th>
      <th class="num">Objednávek</th>
      <th class="num">Tržba</th>
      <th class="num">Průměr/obj.</th>
      <th class="num">% z celku</th>
    </tr>
  </thead>
  <tbody>
    <?php foreach ($top_odberatele as $i => $o): ?>
      <tr>
        <td class="rank"><?= $i + 1 ?>.</td>
        <td><strong><?= htmlspecialchars($o['nazev']) ?></strong></td>
        <td class="num"><?= (int) $o['pocet_obj'] ?></td>
        <td class="num"><strong><?= number_format((float)$o['celkem_kc'], 2, ',', ' ') ?> Kč</strong></td>
        <td class="num"><?= (int)$o['pocet_obj'] > 0 ? number_format((float)$o['celkem_kc'] / (int)$o['pocet_obj'], 0, ',', ' ') : 0 ?> Kč</td>
        <td class="num" style="color:#888"><?= $celkem > 0 ? round((float)$o['celkem_kc'] / $celkem * 100, 1) : 0 ?> %</td>
      </tr>
    <?php endforeach; ?>
  </tbody>
</table>

<div class="footer">
  Vygenerováno systémem <strong><?= htmlspecialchars($firma) ?></strong> · <?= date('j. n. Y H:i') ?>
</div>

<div class="no-print">
  <button onclick="window.print()">🖨️ Vytisknout / Uložit jako PDF</button>
</div>

<script>setTimeout(() => window.print(), 800);</script>
</body></html>
