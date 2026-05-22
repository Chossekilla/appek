<?php
/**
 * Tiskový jednostranný karta výrobku — layout jako e-shop product page.
 * Použití: vyrobek_pdf.php?id=X
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/_admin_auth.php';
require_admin();

$pdo = db();
$id = (int) ($_GET['id'] ?? 0);
if (!$id) { http_response_code(400); die('Chybí ID výrobku'); }

$stmt = $pdo->prepare("
    SELECT v.*, k.nazev AS kategorie_nazev, k.ikona AS kategorie_ikona,
           j.kod AS jednotka_kod, s.sazba AS dph_sazba
    FROM vyrobky v
    LEFT JOIN kategorie_vyrobku k ON v.kategorie_id = k.id
    LEFT JOIN jednotky j ON v.jednotka_id = j.id
    LEFT JOIN sazby_dph s ON v.sazba_dph_id = s.id
    WHERE v.id = :id
");
$stmt->execute(['id' => $id]);
$v = $stmt->fetch();
if (!$v) { http_response_code(404); die('Výrobek nenalezen'); }

// Suroviny (pro detail složení v podtitulku, pokud je text složení prázdný)
$stmt = $pdo->prepare("
    SELECT s.nazev, vs.mnozstvi, vs.jednotka, s.alergen
    FROM vyrobek_suroviny vs
    JOIN suroviny s ON s.id = vs.surovina_id
    WHERE vs.vyrobek_id = :id
    ORDER BY vs.poradi, s.nazev
");
try { $stmt->execute(['id' => $id]); $suroviny = $stmt->fetchAll(); } catch (Throwable $e) { $suroviny = []; }

$cena = (float) $v['cena_bez_dph'];
$dph  = (float) ($v['dph_sazba'] ?? 12);
$cenaSDph = $cena * (1 + $dph / 100);

// Obrázek
$img = '';
if (!empty($v['obrazek_url'])) {
    $url = $v['obrazek_url'];
    $disk = __DIR__ . '/..' . (str_starts_with($url, '/') ? $url : '/' . $url);
    if (file_exists($disk)) $img = $url;
}
if (!$img) {
    $cand = __DIR__ . '/../uploads/vyrobky/' . $v['id'] . '.jpg';
    if (file_exists($cand)) $img = '/uploads/vyrobky/' . $v['id'] . '.jpg';
}

// Nutriční hodnoty
$nutr = null;
if (!empty($v['nutricni_hodnoty'])) {
    $tmp = json_decode($v['nutricni_hodnoty'], true);
    if (is_array($tmp) && count($tmp) > 0) $nutr = $tmp;
}

// Obsah balení
$obsah_text = '';
if (!empty($v['obsah']) && !empty($v['obsah_jednotka'])) {
    $obsah_text = rtrim(rtrim(number_format((float) $v['obsah'], 3, '.', ''), '0'), '.') . ' ' . $v['obsah_jednotka'];
} elseif (!empty($v['hmotnost_g'])) {
    $obsah_text = $v['hmotnost_g'] . ' g';
}

// Cena za kg/l
$cena_per_unit = '';
if (!empty($v['obsah']) && !empty($v['obsah_jednotka'])) {
    $o = (float) $v['obsah'];
    $u = $v['obsah_jednotka'];
    $kg = ($u === 'g') ? $o / 1000 : (($u === 'kg') ? $o : 0);
    $l  = ($u === 'ml') ? $o / 1000 : (($u === 'l') ? $o : 0);
    if ($kg > 0) $cena_per_unit = number_format($cenaSDph / $kg, 2, ',', ' ') . ' Kč / kg';
    elseif ($l > 0) $cena_per_unit = number_format($cenaSDph / $l, 2, ',', ' ') . ' Kč / l';
} elseif (!empty($v['hmotnost_g']) && (float) $v['hmotnost_g'] > 0) {
    $cena_per_unit = number_format($cenaSDph / ((float) $v['hmotnost_g'] / 1000), 2, ',', ' ') . ' Kč / kg';
}

$firma = nastaveni_get($pdo, 'firma_nazev', 'APPEK B2B');
$firma_email = nastaveni_get($pdo, 'firma_email', '');
$firma_tel   = nastaveni_get($pdo, 'firma_telefon', '');
$firma_web   = nastaveni_get($pdo, 'firma_web', '');
?>
<!DOCTYPE html>
<html lang="cs">
<head>
<meta charset="UTF-8">
<title><?= esc($v['nazev']) ?> · Karta výrobku</title>
<style>
  *{box-sizing:border-box;margin:0;padding:0}
  body{font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,sans-serif;color:#222;background:#f5f5f0;padding:24px}
  .toolbar{max-width:210mm;margin:0 auto 16px;padding:12px 16px;background:#fff;border-radius:8px;display:flex;gap:8px;justify-content:flex-end;box-shadow:0 1px 3px rgba(0,0,0,.08)}
  .toolbar .btn{padding:8px 16px;border:1px solid rgba(0,0,0,.16);background:#fff;border-radius:6px;cursor:pointer;font-size:14px;font-family:inherit}
  .toolbar .btn-primary{background:#BA7517;color:#fff;border-color:#BA7517}
  .info{color:#888;font-size:13px;margin-right:auto;align-self:center}
  .page{max-width:210mm;margin:0 auto;padding:18mm 18mm;background:#fff;min-height:297mm;box-shadow:0 2px 8px rgba(0,0,0,.1)}

  /* Header */
  .vp-header{display:flex;justify-content:space-between;align-items:center;border-bottom:2px solid #BA7517;padding-bottom:8mm;margin-bottom:8mm}
  .vp-firma{font-size:18px;font-weight:700;color:#BA7517;letter-spacing:-0.3px}
  .vp-meta-top{font-size:11px;color:#888}
  .vp-kategorie{display:inline-block;background:#FFF9F0;color:#854F0B;font-size:11px;padding:3px 10px;border-radius:999px;font-weight:600;margin-bottom:6mm}

  /* HERO sekce — obrázek vlevo, info vpravo */
  .vp-hero{display:grid;grid-template-columns:90mm 1fr;gap:10mm;margin-bottom:10mm}
  .vp-img{width:100%;height:90mm;background:#FFF9F0;border-radius:8px;overflow:hidden;display:flex;align-items:center;justify-content:center}
  .vp-img img{width:100%;height:100%;object-fit:cover}
  .vp-img-empty{font-size:60px;opacity:0.3}
  .vp-info{display:flex;flex-direction:column}
  .vp-cislo{font-size:12px;color:#999;margin-bottom:4px;font-family:monospace}
  .vp-nazev{font-size:24px;font-weight:800;line-height:1.15;letter-spacing:-0.5px;margin-bottom:4mm;color:#222}
  .vp-popis{font-size:13px;color:#555;line-height:1.5;margin-bottom:4mm}
  .vp-meta{display:flex;flex-wrap:wrap;gap:3mm 6mm;font-size:11px;color:#666;margin-bottom:5mm}
  .vp-meta-item{display:inline-flex;align-items:center;gap:4px}
  .vp-meta-item strong{color:#222}

  /* Cena box */
  .vp-cena-box{background:linear-gradient(135deg,#FFF9F0,#FAEEDA);border:1px solid #E8C988;border-radius:10px;padding:6mm 7mm;margin-top:auto}
  .vp-cena-row{display:flex;align-items:baseline;gap:3mm;margin-bottom:2mm}
  .vp-cena{font-size:32pt;font-weight:900;color:#854F0B;letter-spacing:-0.03em;line-height:0.95}
  .vp-cena-mena{font-size:14pt;color:#BA7517;font-weight:700}
  .vp-cena-info{font-size:11px;color:#854F0B}
  .vp-cena-perkg{display:inline-block;margin-top:2mm;background:white;color:#854F0B;font-weight:700;padding:3px 10px;border-radius:5px;font-size:12px;border:1px solid #E8C988}

  /* Detail sekce */
  .vp-detail{display:grid;grid-template-columns:1fr 1fr;gap:6mm 8mm;margin-bottom:8mm}
  .vp-detail-card{background:#FBFAF6;border:1px solid #E5E3DD;border-radius:8px;padding:5mm 6mm}
  .vp-detail-card h3{font-size:11px;text-transform:uppercase;letter-spacing:0.7px;color:#888;margin-bottom:3mm;font-weight:700}
  .vp-detail-card .body{font-size:12px;color:#333;line-height:1.5}
  .vp-detail-card.aler{background:#FEF3C7;border-color:#F59E0B}
  .vp-detail-card.aler h3{color:#92400e}
  .vp-detail-card.aler .body{color:#78350f;font-weight:500}

  /* Nutrice tabulka */
  .vp-nutr{margin-bottom:6mm}
  .vp-nutr h3{font-size:13px;font-weight:700;color:#222;margin-bottom:3mm;padding-bottom:2mm;border-bottom:1px solid #E5E3DD}
  .vp-nutr table{width:100%;border-collapse:collapse;font-size:12px}
  .vp-nutr th,.vp-nutr td{padding:2mm 4mm;text-align:left;border-bottom:1px solid #F0EDE5}
  .vp-nutr th{background:#FBFAF6;font-weight:600;font-size:10px;text-transform:uppercase;color:#888;letter-spacing:0.4px}
  .vp-nutr td.num{text-align:right;font-variant-numeric:tabular-nums;font-weight:600}
  .vp-nutr tr:last-child td{border-bottom:none}
  .vp-nutr-foot{font-size:10px;color:#999;margin-top:2mm;font-style:italic}

  /* Footer */
  .vp-footer{margin-top:auto;padding-top:5mm;border-top:1px solid #E5E3DD;font-size:10px;color:#888;display:flex;justify-content:space-between;flex-wrap:wrap;gap:8px}

  @media print{
    body{background:#fff;padding:0;margin:0}
    .toolbar{display:none}
    .page{box-shadow:none;padding:12mm 14mm;margin:0;max-width:none;min-height:0}
    @page{size:A4;margin:0}
    .vp-cena{font-size:30pt}
  }
  @media (max-width:900px){
    .page{padding:14mm 12mm}
    .vp-hero{grid-template-columns:1fr;gap:6mm}
    .vp-img{height:60mm}
    .vp-detail{grid-template-columns:1fr}
  }
</style>
</head>
<body>

<div class="toolbar">
  <span class="info">💡 Pro PDF: Tisk → Uložit jako PDF</span>
  <button class="btn" onclick="window.history.back()">← Zpět</button>
  <button class="btn btn-primary" onclick="window.print()">🖨️ Tisk / Uložit PDF</button>
</div>

<div class="page">
  <div class="vp-header">
    <div class="vp-firma"><?= esc($firma) ?></div>
    <div class="vp-meta-top">Karta výrobku · <?= date('j. n. Y') ?></div>
  </div>

  <?php if (!empty($v['kategorie_nazev'])): ?>
    <div class="vp-kategorie"><?= esc($v['kategorie_ikona'] ?? '') ?> <?= esc($v['kategorie_nazev']) ?></div>
  <?php endif; ?>

  <div class="vp-hero">
    <div class="vp-img">
      <?php if ($img): ?>
        <img src="<?= esc($img) ?>" alt="">
      <?php else: ?>
        <span class="vp-img-empty">🥖</span>
      <?php endif; ?>
    </div>
    <div class="vp-info">
      <?php if (!empty($v['cislo'])): ?>
        <div class="vp-cislo">kód <?= esc($v['cislo']) ?><?php if (!empty($v['ean'])): ?> · EAN <?= esc($v['ean']) ?><?php endif; ?></div>
      <?php endif; ?>
      <div class="vp-nazev"><?= esc($v['nazev']) ?></div>
      <?php if (!empty($v['popis'])): ?>
        <div class="vp-popis"><?= nl2br(esc($v['popis'])) ?></div>
      <?php endif; ?>
      <div class="vp-meta">
        <?php if ($obsah_text): ?>
          <span class="vp-meta-item">⚖️ <strong><?= esc($obsah_text) ?></strong></span>
        <?php endif; ?>
        <?php if (!empty($v['jednotka_kod'])): ?>
          <span class="vp-meta-item">📦 <?= esc($v['jednotka_kod']) ?></span>
        <?php endif; ?>
        <span class="vp-meta-item">📊 DPH <?= round($dph) ?>%</span>
      </div>

      <div class="vp-cena-box">
        <div class="vp-cena-row">
          <span class="vp-cena"><?= number_format($cenaSDph, 2, ',', ' ') ?></span>
          <span class="vp-cena-mena">Kč</span>
        </div>
        <div class="vp-cena-info">
          za <?= esc($v['jednotka_kod'] ?? 'ks') ?> · vč. DPH <?= round($dph) ?>%
          <?php if ($cena_per_unit): ?>
            <span class="vp-cena-perkg"><?= esc($cena_per_unit) ?></span>
          <?php endif; ?>
        </div>
      </div>
    </div>
  </div>

  <div class="vp-detail">
    <?php if (!empty($v['slozeni'])): ?>
      <div class="vp-detail-card">
        <h3>🌾 Složení</h3>
        <div class="body"><?= esc($v['slozeni']) ?></div>
      </div>
    <?php elseif (!empty($suroviny)): ?>
      <div class="vp-detail-card">
        <h3>🌾 Složení</h3>
        <div class="body"><?= esc(implode(', ', array_column($suroviny, 'nazev'))) ?></div>
      </div>
    <?php endif; ?>

    <?php if (!empty($v['alergeny'])): ?>
      <div class="vp-detail-card aler">
        <h3>⚠️ Alergeny</h3>
        <div class="body"><?= esc($v['alergeny']) ?></div>
      </div>
    <?php endif; ?>
  </div>

  <?php if ($nutr): ?>
    <div class="vp-nutr">
      <h3>🥗 Nutriční hodnoty</h3>
      <table>
        <thead>
          <tr>
            <th>Hodnota</th>
            <th class="num">Na 100 g</th>
          </tr>
        </thead>
        <tbody>
          <?php
          $rows = [
            'energie_kj'    => ['Energie', 'kJ'],
            'energie_kcal'  => ['Energie', 'kcal'],
            'tuky'          => ['Tuky', 'g'],
            'tuky_nasycene' => ['— z toho nasycené', 'g'],
            'sacharidy'     => ['Sacharidy', 'g'],
            'cukry'         => ['— z toho cukry', 'g'],
            'bilkoviny'     => ['Bílkoviny', 'g'],
            'sul'           => ['Sůl', 'g'],
          ];
          foreach ($rows as $k => [$l, $u]):
            if (!isset($nutr[$k]) || $nutr[$k] === null || $nutr[$k] === '') continue;
            $val = (float) $nutr[$k];
          ?>
            <tr>
              <td><?= esc($l) ?></td>
              <td class="num"><?= rtrim(rtrim(number_format($val, 2, ',', ''), '0'), ',') ?> <?= esc($u) ?></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
      <div class="vp-nutr-foot">Hodnoty na 100 g výrobku.</div>
    </div>
  <?php endif; ?>

  <div class="vp-footer">
    <div>
      <strong><?= esc($firma) ?></strong>
      <?php if ($firma_email): ?> · <?= esc($firma_email) ?><?php endif; ?>
      <?php if ($firma_tel): ?> · <?= esc($firma_tel) ?><?php endif; ?>
    </div>
    <div><?php if ($firma_web): ?><?= esc($firma_web) ?> · <?php endif; ?>Vystaveno <?= date('j. n. Y') ?></div>
  </div>
</div>

</body>
</html>
