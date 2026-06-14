<?php
/**
 * 🆕 v3.0.326 — Tisk EAN štítků (čárové kódy) pro produkty.
 *   GET ?ids=1,2,3 [&autoprint=1]  → tisknutelná stránka se štítky (JsBarcode EAN-13 + název + cena).
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/_admin_auth.php';
require_admin();
$pdo = db();

$ids = array_values(array_unique(array_filter(array_map('intval', explode(',', (string)($_GET['ids'] ?? ''))))));
$auto = !empty($_GET['autoprint']);
$rows = [];
if ($ids) {
    $ph = implode(',', array_fill(0, count($ids), '?'));
    $st = $pdo->prepare("SELECT v.id, v.nazev, v.ean, v.cena_bez_dph, COALESCE(s.sazba,21) AS sazba
                         FROM vyrobky v LEFT JOIN sazby_dph s ON s.id = v.sazba_dph_id
                         WHERE v.id IN ($ph)");
    $st->execute($ids);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC);
}
header('Content-Type: text/html; charset=UTF-8');
?><!doctype html><html lang="cs"><head><meta charset="utf-8"><title>EAN štítky</title>
<script src="https://cdn.jsdelivr.net/npm/jsbarcode@3.11.6/dist/JsBarcode.all.min.js"></script>
<style>
  body{font-family:system-ui,Arial,sans-serif;margin:0;padding:8px;background:#fff;color:#111}
  .head{font-size:13px;color:#666;margin-bottom:8px}
  .grid{display:flex;flex-wrap:wrap;gap:6px}
  .lbl{width:50mm;border:1px dashed #ccc;border-radius:4px;padding:6px 4px;text-align:center;box-sizing:border-box}
  .lbl .nm{font-size:11px;font-weight:600;line-height:1.15;margin-bottom:2px;height:26px;overflow:hidden}
  .lbl .pr{font-size:13px;font-weight:700;margin-top:2px}
  .lbl svg{max-width:100%}
  .noean{color:#a00;font-size:10px;padding:14px 0}
  @media print{.head{display:none}.lbl{border:none}@page{margin:6mm}}
</style></head><body>
<div class="head">EAN štítky — <?= count($rows) ?> ks · <button onclick="window.print()">🖨️ Tisk</button></div>
<div class="grid">
<?php if (!$rows): ?><div class="noean">Žádné produkty (zadej ?ids=…).</div><?php endif; ?>
<?php foreach ($rows as $r): $gross = round((float)$r['cena_bez_dph'] * (1 + (float)$r['sazba'] / 100), 2); ?>
  <div class="lbl">
    <div class="nm"><?= htmlspecialchars($r['nazev'], ENT_QUOTES) ?></div>
    <?php if (!empty($r['ean'])): ?>
      <svg class="bc" data-ean="<?= htmlspecialchars($r['ean'], ENT_QUOTES) ?>"></svg>
    <?php else: ?>
      <div class="noean">⚠️ bez EAN<br>(vygeneruj v editoru produktu)</div>
    <?php endif; ?>
    <div class="pr"><?= number_format($gross, 2, ',', ' ') ?> Kč</div>
  </div>
<?php endforeach; ?>
</div>
<script>
  document.querySelectorAll('svg.bc[data-ean]').forEach(function(el){
    try { JsBarcode(el, el.dataset.ean, { format:'EAN13', width:1.6, height:42, fontSize:12, margin:2 }); }
    catch(e){ var d=document.createElement('div'); d.className='noean'; d.textContent='EAN '+el.dataset.ean+' (nevalidní)'; el.replaceWith(d); }
  });
  <?php if ($auto): ?>window.addEventListener('load', function(){ setTimeout(function(){ window.print(); }, 500); });<?php endif; ?>
</script>
</body></html>
