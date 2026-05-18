<?php
/**
 * 📑 PDF SMLOUVY + NABÍDKY pro Catering balíček.
 *
 * Generuje:
 *   GET ?id=N&type=nabidka       → HTML nabídka pro klienta (print-friendly, browser uloží jako PDF)
 *   GET ?id=N&type=smlouva       → HTML smlouva o cateringu (s ICEW/zalohou)
 *   GET ?id=N&type=zaloha_fa     → HTML zálohová faktura 50%
 *
 * Pozn.: použijeme HTML print layout (uživatel klikne tisknout → uloží PDF).
 * Pro plnou PDF generaci v PHP by se použil mPDF/dompdf — v krabicovce nechceme composer.
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/_admin_auth.php';
require_once __DIR__ . '/_packages_lib.php';

cors_headers();
require_admin();

if (!package_enabled('catering') && !package_enabled('lahudky')) {
    http_response_code(402);
    header('Content-Type: application/json; charset=UTF-8');
    json_response(['error' => 'Vyžaduje balíček 🎉 Catering nebo 🥗 Lahůdky']);
}

$pdo = db();
$id   = (int) ($_GET['id'] ?? 0);
$type = $_GET['type'] ?? 'nabidka';

if (!$id) {
    http_response_code(400);
    header('Content-Type: application/json; charset=UTF-8');
    json_response(['error' => 'Chybí id']);
}

$stmt = $pdo->prepare("SELECT * FROM catering_orders WHERE id=:id");
$stmt->execute(['id'=>$id]);
$akce = $stmt->fetch();
if (!$akce) {
    http_response_code(404);
    header('Content-Type: application/json; charset=UTF-8');
    json_response(['error' => 'Akce neexistuje']);
}

// Firma — z nastaveni
$firma = [];
try {
    $rows = $pdo->query("SELECT klic, hodnota FROM nastaveni WHERE klic LIKE 'firma_%'")->fetchAll();
    foreach ($rows as $r) $firma[$r['klic']] = $r['hodnota'];
} catch (Throwable $e) {}

$polozky = [];
if (!empty($akce['polozky_json'])) {
    $polozky = json_decode($akce['polozky_json'], true) ?: [];
}

$datumAkce = $akce['datum_akce'] ? date('d.m.Y', strtotime($akce['datum_akce'])) : '—';
$cas = '';
if ($akce['cas_od']) $cas = substr($akce['cas_od'], 0, 5);
if ($akce['cas_do']) $cas .= ' – ' . substr($akce['cas_do'], 0, 5);

$cenaCelkem = (float) $akce['castka_celkem'];
$zaloha     = (float) ($akce['zaloha_kc'] ?: round($cenaCelkem * 0.5, 2));
$doplatek   = $cenaCelkem - $zaloha;
$dph        = 12;
$bezDph     = round($cenaCelkem / (1 + $dph/100), 2);
$dphKc      = round($cenaCelkem - $bezDph, 2);

$titles = [
    'nabidka'   => '📋 Nabídka cateringu',
    'smlouva'   => '📑 Smlouva o cateringu',
    'zaloha_fa' => '💰 Zálohová faktura (50 %)',
];
$title = $titles[$type] ?? '📋 Dokument cateringu';

header('Content-Type: text/html; charset=UTF-8');
?><!DOCTYPE html>
<html lang="cs">
<head>
<meta charset="UTF-8">
<title><?= htmlspecialchars($title) ?> · <?= htmlspecialchars($akce['nazev']) ?></title>
<style>
  @page { size: A4; margin: 18mm 16mm 18mm 16mm; }
  body {
    font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', system-ui, sans-serif;
    font-size: 11.5pt; line-height: 1.5; color: #1d1d1f; background: #fff;
    margin: 0; padding: 30px;
  }
  .doc-wrap { max-width: 740px; margin: 0 auto; }
  .doc-head {
    display: flex; justify-content: space-between; align-items: flex-start;
    border-bottom: 3px solid #BA7517; padding-bottom: 18px; margin-bottom: 24px;
  }
  .doc-head h1 { margin: 0; font-size: 22pt; color: #1d1d1f; }
  .doc-head .meta { text-align: right; font-size: 10pt; color: #666; }
  .doc-head .meta strong { color: #1d1d1f; }
  .firma { font-size: 10pt; color: #555; }
  .firma strong { color: #1d1d1f; font-size: 11pt; display: block; margin-bottom: 4px; }

  h2 { font-size: 13pt; margin: 22px 0 10px; color: #BA7517; border-bottom: 1px solid #e8c988; padding-bottom: 5px; }

  table { width: 100%; border-collapse: collapse; margin: 10px 0; }
  table th, table td { padding: 7px 10px; text-align: left; border-bottom: 1px solid #eee; }
  table th { background: #faf6ed; font-weight: 600; font-size: 10pt; color: #854F0B; }
  table td.num { text-align: right; font-variant-numeric: tabular-nums; }
  table tfoot td { font-weight: 600; border-top: 2px solid #BA7517; }
  table tfoot tr.total td { font-size: 13pt; color: #BA7517; }

  .grid-2 { display: grid; grid-template-columns: 1fr 1fr; gap: 24px; margin: 14px 0; }
  .info-box { background: #faf6ed; border-left: 4px solid #BA7517; padding: 12px 14px; border-radius: 6px; }
  .info-box .label { font-size: 9pt; text-transform: uppercase; color: #854F0B; font-weight: 700; letter-spacing: 0.4px; margin-bottom: 4px; }
  .info-box .val { font-size: 13pt; color: #1d1d1f; font-weight: 600; }

  .signature-area {
    margin-top: 50px; display: grid; grid-template-columns: 1fr 1fr; gap: 50px; page-break-inside: avoid;
  }
  .sig-line {
    border-top: 1px solid #999; padding-top: 6px; font-size: 10pt; color: #666; text-align: center; margin-top: 60px;
  }

  .terms { font-size: 10pt; color: #555; line-height: 1.6; }
  .terms ol { padding-left: 18px; }
  .terms li { margin-bottom: 7px; }

  .footer { margin-top: 40px; text-align: center; font-size: 9pt; color: #888; padding-top: 14px; border-top: 1px solid #eee; }

  .print-btn {
    position: fixed; bottom: 20px; right: 20px;
    background: #BA7517; color: #fff; padding: 12px 18px; border-radius: 30px;
    border: none; cursor: pointer; font-size: 13pt; font-weight: 600;
    box-shadow: 0 4px 12px rgba(186, 117, 23, 0.4);
  }
  @media print { .print-btn { display: none; } body { padding: 0; } }
</style>
</head>
<body>
<div class="doc-wrap">

  <div class="doc-head">
    <div>
      <h1><?= htmlspecialchars($title) ?></h1>
      <div class="firma">
        <strong><?= htmlspecialchars($firma['firma_nazev'] ?? 'APPEK B2B') ?></strong>
        <?= htmlspecialchars($firma['firma_ulice'] ?? '') ?><br>
        <?= htmlspecialchars($firma['firma_psc'] ?? '') ?> <?= htmlspecialchars($firma['firma_mesto'] ?? '') ?><br>
        IČO: <?= htmlspecialchars($firma['firma_ico'] ?? '—') ?>
        <?php if (!empty($firma['firma_dic'])): ?>· DIČ: <?= htmlspecialchars($firma['firma_dic']) ?><?php endif; ?>
        <br>
        <?php if (!empty($firma['firma_email'])): ?>✉️ <?= htmlspecialchars($firma['firma_email']) ?> <?php endif; ?>
        <?php if (!empty($firma['firma_telefon'])): ?>· 📞 <?= htmlspecialchars($firma['firma_telefon']) ?><?php endif; ?>
      </div>
    </div>
    <div class="meta">
      <div><strong>Datum vystavení:</strong><br><?= date('d.m.Y') ?></div>
      <?php if ($type === 'nabidka'): ?>
        <div style="margin-top:8px"><strong>Platnost nabídky:</strong><br>14 dní</div>
      <?php endif; ?>
      <?php if ($type === 'zaloha_fa'): ?>
        <div style="margin-top:8px"><strong>Číslo:</strong><br>ZF-<?= str_pad((string)$akce['id'], 6, '0', STR_PAD_LEFT) ?></div>
        <div style="margin-top:8px"><strong>Splatnost:</strong><br>14 dní od vystavení</div>
      <?php endif; ?>
    </div>
  </div>

  <h2>👤 Odběratel / Zákazník</h2>
  <div class="info-box" style="margin:8px 0">
    <div style="font-size:13pt;font-weight:600"><?= htmlspecialchars($akce['zakaznik']) ?></div>
    <?php if (!empty($akce['ico'])): ?><div style="font-size:11pt;margin-top:4px">IČO: <strong><?= htmlspecialchars($akce['ico']) ?></strong></div><?php endif; ?>
    <?php if (!empty($akce['kontaktni_email']) || !empty($akce['kontaktni_telefon'])): ?>
      <div style="font-size:10.5pt;color:#666;margin-top:6px">
        <?php if (!empty($akce['kontaktni_email'])): ?>✉️ <?= htmlspecialchars($akce['kontaktni_email']) ?><?php endif; ?>
        <?php if (!empty($akce['kontaktni_telefon'])): ?> · 📞 <?= htmlspecialchars($akce['kontaktni_telefon']) ?><?php endif; ?>
      </div>
    <?php endif; ?>
  </div>

  <h2>🎉 Detail akce</h2>
  <div class="grid-2">
    <div class="info-box">
      <div class="label">Akce</div>
      <div class="val"><?= htmlspecialchars($akce['nazev']) ?></div>
    </div>
    <div class="info-box">
      <div class="label">Datum a čas</div>
      <div class="val"><?= htmlspecialchars($datumAkce) ?><?php if ($cas): ?> · <?= htmlspecialchars($cas) ?><?php endif; ?></div>
    </div>
    <div class="info-box">
      <div class="label">Počet osob</div>
      <div class="val">👥 <?= (int)$akce['osob'] ?></div>
    </div>
    <div class="info-box">
      <div class="label">Místo</div>
      <div class="val" style="font-size:11pt">📍 <?= htmlspecialchars($akce['misto'] ?: '—') ?></div>
    </div>
  </div>

  <?php if (!empty($polozky)): ?>
    <h2>📋 Položky / Menu</h2>
    <table>
      <thead>
        <tr>
          <th>Položka</th>
          <th class="num">Množství</th>
          <th class="num">Cena/ks</th>
          <th class="num">Celkem</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($polozky as $p):
          $mnozstvi   = (float)($p['mnozstvi'] ?? 1);
          $cenaJ      = (float)($p['cena'] ?? 0);
          $cenaCelkemP = (float)($p['cena_celkem'] ?? ($cenaJ * $mnozstvi));
        ?>
        <tr>
          <td><?= htmlspecialchars($p['nazev'] ?? '—') ?></td>
          <td class="num"><?= $mnozstvi ?></td>
          <td class="num"><?= number_format($cenaJ, 2, ',', ' ') ?> Kč</td>
          <td class="num"><?= number_format($cenaCelkemP, 2, ',', ' ') ?> Kč</td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  <?php endif; ?>

  <h2>💰 Cena</h2>
  <table>
    <tbody>
      <tr><td>Cena bez DPH (<?= $dph ?> %)</td><td class="num"><?= number_format($bezDph, 2, ',', ' ') ?> Kč</td></tr>
      <tr><td>DPH <?= $dph ?> %</td><td class="num"><?= number_format($dphKc, 2, ',', ' ') ?> Kč</td></tr>
    </tbody>
    <tfoot>
      <tr class="total"><td>Cena celkem s DPH</td><td class="num"><?= number_format($cenaCelkem, 2, ',', ' ') ?> Kč</td></tr>
      <?php if ($type !== 'zaloha_fa'): ?>
        <tr><td>z toho záloha (50 %)</td><td class="num"><?= number_format($zaloha, 2, ',', ' ') ?> Kč</td></tr>
        <tr><td>z toho doplatek</td><td class="num"><?= number_format($doplatek, 2, ',', ' ') ?> Kč</td></tr>
      <?php endif; ?>
      <?php if ($type === 'zaloha_fa'): ?>
        <tr class="total"><td>💳 K úhradě nyní (zálohová faktura)</td><td class="num"><?= number_format($zaloha, 2, ',', ' ') ?> Kč</td></tr>
      <?php endif; ?>
    </tfoot>
  </table>

  <?php if ($akce['poznamka']): ?>
    <h2>📝 Poznámka</h2>
    <div style="background:#fafafa;padding:12px;border-radius:6px;white-space:pre-wrap;font-size:10.5pt"><?= htmlspecialchars($akce['poznamka']) ?></div>
  <?php endif; ?>

  <?php if ($type === 'smlouva'): ?>
    <h2>📋 Smluvní podmínky</h2>
    <div class="terms">
      <ol>
        <li><strong>Předmět smlouvy:</strong> Dodavatel se zavazuje poskytnout cateringové služby pro objednatele dle specifikace výše.</li>
        <li><strong>Datum a místo plnění:</strong> Akce se uskuteční dne <?= htmlspecialchars($datumAkce) ?> na adrese <?= htmlspecialchars($akce['misto'] ?: 'dle dohody') ?>.</li>
        <li><strong>Cena a platba:</strong> Celková cena je <?= number_format($cenaCelkem, 2, ',', ' ') ?> Kč včetně DPH. Záloha ve výši <?= number_format($zaloha, 2, ',', ' ') ?> Kč (50 %) je splatná do 14 dnů od podpisu smlouvy. Doplatek <?= number_format($doplatek, 2, ',', ' ') ?> Kč je splatný do 7 dnů od konání akce.</li>
        <li><strong>Storno podmínky:</strong> Při zrušení akce ze strany objednatele více než 14 dní před konáním se vrací 100 % zálohy. 7–14 dní 50 % zálohy. Méně než 7 dní záloha propadá.</li>
        <li><strong>Změny:</strong> Změny počtu osob jsou možné nejpozději 5 pracovních dní před akcí. Dodatečné požadavky se účtují dle ceníku.</li>
        <li><strong>Reklamace:</strong> Případné reklamace je nutno uplatnit nejpozději 3 dny po konání akce, písemně.</li>
        <li><strong>Závěrečná ujednání:</strong> Smlouva se řídí českým právem. Vztahy neupravené smlouvou se řídí občanským zákoníkem ČR.</li>
      </ol>
    </div>

    <div class="signature-area">
      <div>
        <div class="sig-line">Dodavatel</div>
        <div style="text-align:center;font-size:10pt;color:#666;margin-top:4px"><?= htmlspecialchars($firma['firma_nazev'] ?? 'APPEK B2B') ?></div>
      </div>
      <div>
        <div class="sig-line">Objednatel</div>
        <div style="text-align:center;font-size:10pt;color:#666;margin-top:4px"><?= htmlspecialchars($akce['zakaznik']) ?></div>
      </div>
    </div>
  <?php endif; ?>

  <?php if ($type === 'zaloha_fa'): ?>
    <h2>💳 Platební údaje</h2>
    <div class="grid-2">
      <div class="info-box">
        <div class="label">Bankovní účet</div>
        <div class="val" style="font-size:12pt"><?= htmlspecialchars($firma['firma_ucet'] ?? '—') ?></div>
      </div>
      <div class="info-box">
        <div class="label">Variabilní symbol</div>
        <div class="val">VS<?= str_pad((string)$akce['id'], 6, '0', STR_PAD_LEFT) ?></div>
      </div>
    </div>
    <p style="font-size:10.5pt;color:#555;margin-top:14px">
      Tato faktura slouží pouze jako podklad k úhradě zálohy. Daňový doklad bude vystaven po obdržení platby.
    </p>
  <?php endif; ?>

  <?php if ($type === 'nabidka'): ?>
    <h2>ℹ️ Informace</h2>
    <p style="font-size:10.5pt;color:#555">
      Tato nabídka je platná 14 dní od data vystavení. Pro závazné objednání nás kontaktujte na uvedeném e-mailu nebo telefonu.
      Po vašem schválení vystavíme smlouvu a zálohovou fakturu na 50 % částky.
    </p>
  <?php endif; ?>

  <div class="footer">
    Vygenerováno systémem APPEK B2B · <?= date('d.m.Y H:i') ?> · Doklad č. <?= str_pad((string)$akce['id'], 6, '0', STR_PAD_LEFT) ?>
  </div>
</div>

<button class="print-btn" onclick="window.print()">🖨️ Tisknout / Uložit PDF</button>

</body>
</html>
