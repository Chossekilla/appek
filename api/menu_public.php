<?php
/**
 * 🗓️ VEŘEJNÉ TÝDENNÍ MENU — sdílecí stránka (v3.0.409, Restaurace balíček).
 *
 * GET ?t=TOKEN — BEZ přihlášení (token = kapabilita). Renderuje týdenní menu
 * s og: metadaty pro hezký náhled na sociálních sítích (FB/X/WhatsApp).
 * Žádná citlivá data — jen jídla a ceny, jako vývěska před restaurací.
 */

require_once __DIR__ . '/config.php';

$tok = preg_replace('/[^a-f0-9]/', '', strtolower((string) ($_GET['t'] ?? '')));
header('Content-Type: text/html; charset=UTF-8');

$w = null;
if (strlen($tok) === 32) {
    try {
        $st = db()->prepare("SELECT * FROM tydenni_menu WHERE public_token = :t");
        $st->execute(['t' => $tok]);
        $w = $st->fetch(PDO::FETCH_ASSOC);
    } catch (Throwable $e) { $w = null; }
}
if (!$w) {
    http_response_code(404);
    echo '<!DOCTYPE html><html lang="cs"><head><meta charset="UTF-8"><title>Menu nenalezeno</title></head><body style="font-family:sans-serif;text-align:center;padding:60px"><h1>🍽️</h1><p>Toto menu už není dostupné.</p></body></html>';
    exit;
}

$pdo = db();
$dny = json_decode($w['dny'] ?? '[]', true) ?: [];
$DNY = ['po' => 'Pondělí', 'ut' => 'Úterý', 'st' => 'Středa', 'ct' => 'Čtvrtek', 'pa' => 'Pátek', 'so' => 'Sobota', 'ne' => 'Neděle'];

$ids = [];
foreach ($DNY as $k => $_) foreach ((array) ($dny[$k] ?? []) as $r) $ids[] = (int) ($r['vyrobek_id'] ?? 0);
$ids = array_values(array_unique(array_filter($ids)));
$map = [];
if ($ids) {
    $ph = implode(',', array_fill(0, count($ids), '?'));
    $st = $pdo->prepare("
        SELECT v.id, v.nazev, ROUND(v.cena_bez_dph * (1 + COALESCE(sd.sazba, 12) / 100), 0) AS cena,
               COALESCE(v.alergeny, '') AS alergeny
        FROM vyrobky v LEFT JOIN sazby_dph sd ON sd.id = v.sazba_dph_id WHERE v.id IN ($ph)
    ");
    $st->execute($ids);
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) $map[(int) $r['id']] = $r;
}

$firma = [];
try { foreach ($pdo->query("SELECT klic, hodnota FROM nastaveni WHERE klic LIKE 'firma_%'") as $r) $firma[$r['klic']] = $r['hodnota']; } catch (Throwable $e) {}
$fN = htmlspecialchars($firma['firma_nazev'] ?? 'Restaurace');

$odS = date('j. n.', strtotime($w['tyden_od']));
$doS = date('j. n. Y', strtotime($w['tyden_od'] . ' +6 days'));
$titulek = "Týdenní menu {$odS} – {$doS}";
// og:description = první ~3 jídla
$prvni = [];
foreach ($DNY as $k => $_) {
    foreach ((array) ($dny[$k] ?? []) as $r) {
        $v = $map[(int) ($r['vyrobek_id'] ?? 0)] ?? null;
        if ($v) $prvni[] = $v['nazev'];
        if (count($prvni) >= 3) break 2;
    }
}
$ogDesc = htmlspecialchars(implode(' · ', $prvni) . (count($prvni) >= 3 ? ' …' : ''));
require_once __DIR__ . '/_menu_styly.php'; // 🆕 v3.0.412
$st = menu_styl($w['styl'] ?? 'restaurace');
?>
<!DOCTYPE html>
<html lang="cs">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= htmlspecialchars($titulek) ?> · <?= $fN ?></title>
<meta property="og:title" content="🗓️ <?= htmlspecialchars($titulek) ?>">
<meta property="og:description" content="<?= $ogDesc ?>">
<meta property="og:type" content="website">
<meta property="og:site_name" content="<?= $fN ?>">
<meta name="robots" content="noindex">
<style>
  * { box-sizing: border-box; }
  body { font-family: <?= $st['font'] ?>; background: <?= $st['bg'] ?>; color: <?= $st['text'] ?>; margin: 0; padding: 20px 14px 40px; }
  .card { max-width: 560px; margin: 0 auto; background: <?= $st['card'] ?>; border-radius: 18px; box-shadow: 0 8px 30px rgba(0,0,0,.08); overflow: hidden; }
  .hd { background: linear-gradient(135deg, <?= $st['grad_a'] ?>, <?= $st['grad_b'] ?>); color: #fff; padding: 22px 22px 18px; }
  .hd h1 { margin: 0; font-size: 21px; letter-spacing: -0.01em; }
  .hd .sub { opacity: .9; font-size: 13px; margin-top: 3px; }
  .ct { padding: 12px 22px; font-size: 14px; color: <?= $st['accent'] ?>; font-weight: 600; text-align: center; }
  .day { padding: 14px 22px 4px; }
  .day h2 { font-size: 13px; text-transform: uppercase; letter-spacing: .08em; color: <?= $st['accent'] ?>; margin: 0 0 6px; border-bottom: 2px solid <?= $st['accent_soft'] ?>; padding-bottom: 4px; }
  .it { display: flex; justify-content: space-between; gap: 12px; padding: 7px 0; border-bottom: 1px dashed #eee; font-size: 14.5px; }
  .it:last-child { border-bottom: 0; }
  .it .n small { color: <?= $st['muted'] ?>; font-size: 11px; }
  .it .c { white-space: nowrap; font-weight: 700; font-variant-numeric: tabular-nums; }
  .note { padding: 10px 22px; font-size: 12.5px; color: <?= $st['muted'] ?>; font-style: italic; }
  .ft { padding: 16px 22px 20px; font-size: 12px; color: <?= $st['muted'] ?>; border-top: 1px solid #f0f0f0; margin-top: 10px; }
</style>
</head>
<body>
<div class="card">
  <div class="hd">
    <h1><?= $st['nadpis'] ?> <?= htmlspecialchars($titulek) ?></h1>
    <div class="sub"><?= $fN ?></div>
  </div>
  <?php if (!empty($w['custom_text'])): ?><div class="ct"><?= nl2br(htmlspecialchars($w['custom_text'])) ?></div><?php endif; ?>
  <?php if (!empty($w['poznamka'])): ?><div class="note">📝 <?= htmlspecialchars($w['poznamka']) ?></div><?php endif; ?>
  <?php $i = 0; foreach ($DNY as $k => $label): $items = (array) ($dny[$k] ?? []); if (!$items) { $i++; continue; } ?>
  <div class="day">
    <h2><?= $label ?> <?= date('j. n.', strtotime($w['tyden_od'] . " +{$i} days")) ?></h2>
    <?php foreach ($items as $r): $v = $map[(int) ($r['vyrobek_id'] ?? 0)] ?? null; if (!$v) continue; ?>
    <div class="it">
      <div class="n"><?= htmlspecialchars($v['nazev']) ?><?= !empty($r['pozn']) ? ' <small>(' . htmlspecialchars($r['pozn']) . ')</small>' : '' ?><?= $v['alergeny'] !== '' ? ' <small>al. ' . htmlspecialchars($v['alergeny']) . '</small>' : '' ?></div>
      <div class="c"><?= number_format((float) $v['cena'], 0, ',', ' ') ?> Kč</div>
    </div>
    <?php endforeach; ?>
  </div>
  <?php $i++; endforeach; ?>
  <div class="ft">
    <?= $fN ?>
    <?= ($firma['firma_telefon'] ?? '') !== '' ? ' · 📞 ' . htmlspecialchars($firma['firma_telefon']) : '' ?>
    <?= ($firma['firma_ulice'] ?? '') !== '' ? ' · 📍 ' . htmlspecialchars($firma['firma_ulice'] . ', ' . ($firma['firma_mesto'] ?? '')) : '' ?>
  </div>
</div>
</body>
</html>
