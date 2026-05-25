<?php
/**
 * Rozvozový list pro řidiče — A4 tisková stránka.
 * GET ?datum=YYYY-MM-DD
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/_admin_auth.php';
require_admin();

$pdo = db();
$datum = $_GET['datum'] ?? date('Y-m-d');
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $datum)) {
    http_response_code(400);
    echo 'Neplatný datum';
    exit;
}

// Stejný query jako v admin_rozvozy.php
$stmt = $pdo->prepare("
    SELECT dl.id, dl.cislo, dl.castka_celkem,
           o.cislo AS objednavka_cislo,
           od.nazev AS odberatel_nazev,
           od.telefon AS odberatel_telefon,
           md.nazev AS misto_nazev,
           md.ulice AS misto_ulice,
           md.mesto AS misto_mesto,
           md.psc AS misto_psc,
           md.telefon AS misto_telefon,
           md.kontaktni_osoba,
           md.cas_dodani,
           md.pokyny_pro_ridice,
           COALESCE(md.mesto, od.mesto, '') AS rozvoz_mesto,
           COALESCE(md.psc, od.psc, '') AS rozvoz_psc,
           COALESCE(NULLIF(CONCAT_WS(', ', md.ulice, md.mesto), ''), CONCAT_WS(', ', od.ulice, od.mesto)) AS rozvoz_adresa,
           (SELECT COUNT(*) FROM dodaci_list_polozky WHERE dodaci_list_id = dl.id) AS pocet_polozek,
           (SELECT SUM(mnozstvi) FROM dodaci_list_polozky WHERE dodaci_list_id = dl.id) AS celkem_ks
    FROM dodaci_listy dl
    LEFT JOIN objednavky o ON o.id = dl.objednavka_id
    JOIN odberatele od ON od.id = dl.odberatel_id
    LEFT JOIN mista_dodani md ON md.id = dl.misto_dodani_id
    WHERE dl.datum_dodani = :datum
    ORDER BY rozvoz_mesto, rozvoz_psc, od.nazev
");
$stmt->execute(['datum' => $datum]);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Seskup
$mesta = [];
foreach ($rows as $r) {
    $m = trim($r['rozvoz_mesto']) ?: '— bez města —';
    $mesta[$m][] = $r;
}
ksort($mesta);

$firma = nastaveni_get($pdo, 'firma_nazev', 'Provoz');
$logo = nastaveni_get($pdo, 'firma_logo_url', '');
?><!DOCTYPE html>
<html lang="cs"><head>
<meta charset="UTF-8">
<title>Rozvozový list — <?= htmlspecialchars($datum) ?></title>
<style>
@page { size: A4; margin: 12mm; }
body { font-family: -apple-system, "Segoe UI", Arial, sans-serif; color: #1a1a1a; font-size: 11pt; margin: 0; }
.head { display: flex; justify-content: space-between; align-items: center; margin-bottom: 14px; border-bottom: 2px solid #1a1a1a; padding-bottom: 10px; }
.head .firma { font-weight: 700; font-size: 18pt; }
.head .info { text-align: right; font-size: 10pt; color: #666; }
h2 { margin: 18px 0 8px; padding: 6px 10px; background: #f0f0f0; border-left: 4px solid #BA7517; font-size: 14pt; }
.zastavka { margin-bottom: 12px; padding: 8px 10px; border: 1px solid #ccc; border-radius: 4px; page-break-inside: avoid; }
.zastavka-head { display: flex; justify-content: space-between; align-items: center; margin-bottom: 6px; }
.poradi { display: inline-block; background: #BA7517; color: #fff; padding: 2px 8px; border-radius: 4px; font-weight: 700; margin-right: 6px; }
.adresa { font-size: 10pt; color: #444; margin: 2px 0 }
.kontakt { font-size: 10pt; color: #444; }
.pokyny { background: #FFF8E5; border: 1px solid #E8C988; padding: 4px 8px; margin-top: 6px; font-size: 10pt; font-style: italic; color: #854F0B; border-radius: 3px; }
.cas { background: #D89940; color: #fff; padding: 2px 8px; border-radius: 4px; font-weight: 700; font-size: 10pt; }
.podpis { margin-top: 18px; display: flex; gap: 20px; justify-content: space-between; }
.podpis-pole { flex: 1; border-top: 1px solid #1a1a1a; padding-top: 6px; font-size: 10pt; color: #666; }
@media print { body { padding: 0; } .no-print { display: none; } }
</style>
</head>
<body>

<div class="head">
  <div>
    <div class="firma"><?= htmlspecialchars($firma) ?></div>
    <div style="font-size:11pt;color:#666;margin-top:4px">🛣️ Rozvozový list</div>
  </div>
  <div class="info">
    <strong>Datum:</strong> <?= htmlspecialchars(fmt_date($datum)) ?><br>
    <strong>Vytištěno:</strong> <?= date('j. n. Y H:i') ?><br>
    <strong>Celkem zastávek:</strong> <?= count($rows) ?>
  </div>
</div>

<?php $celkemZastavek = 0; foreach ($mesta as $nazevMesta => $dl_list): ?>
  <h2>📍 <?= htmlspecialchars($nazevMesta) ?> <span style="font-weight:400;font-size:11pt;color:#666">(<?= count($dl_list) ?> zastávek)</span></h2>
  <?php foreach ($dl_list as $dl): $celkemZastavek++; ?>
    <div class="zastavka">
      <div class="zastavka-head">
        <div>
          <span class="poradi"><?= $celkemZastavek ?>.</span>
          <strong><?= htmlspecialchars($dl['odberatel_nazev']) ?></strong>
          <?php if ($dl['misto_nazev']): ?>
            <span style="color:#666">— <?= htmlspecialchars($dl['misto_nazev']) ?></span>
          <?php endif; ?>
        </div>
        <?php if ($dl['cas_dodani']): ?>
          <span class="cas">⏰ <?= htmlspecialchars($dl['cas_dodani']) ?></span>
        <?php endif; ?>
      </div>
      <div class="adresa">📌 <?= htmlspecialchars($dl['rozvoz_adresa']) ?> <?= htmlspecialchars($dl['rozvoz_psc']) ?></div>
      <div class="kontakt">
        <?php if ($dl['kontaktni_osoba']): ?>👤 <?= htmlspecialchars($dl['kontaktni_osoba']) ?> · <?php endif; ?>
        <?php if ($dl['misto_telefon'] || $dl['odberatel_telefon']): ?>📞 <?= htmlspecialchars($dl['misto_telefon'] ?: $dl['odberatel_telefon']) ?> · <?php endif; ?>
        📦 <strong><?= (int)($dl['pocet_polozek'] ?? 0) ?> položek · <?= round($dl['celkem_ks'] ?? 0) ?> ks</strong>
        · <strong><?= htmlspecialchars(number_format($dl['castka_celkem'], 2, ',', ' ')) ?> Kč</strong>
        · DL <?= htmlspecialchars($dl['cislo']) ?>
      </div>
      <?php if ($dl['pokyny_pro_ridice']): ?>
        <div class="pokyny">🚚 <?= htmlspecialchars($dl['pokyny_pro_ridice']) ?></div>
      <?php endif; ?>
    </div>
  <?php endforeach; ?>
<?php endforeach; ?>

<div class="podpis">
  <div class="podpis-pole">Řidič / kdo dovezl (podpis)</div>
  <div class="podpis-pole">Datum: ____________________</div>
</div>

<div class="no-print" style="margin-top:24px;text-align:center">
  <button onclick="window.print()" style="padding:10px 24px;font-size:14px;font-weight:700;background:#BA7517;color:#fff;border:none;border-radius:8px;cursor:pointer">🖨️ Vytisknout</button>
</div>

<script>setTimeout(() => window.print(), 600);</script>
</body></html>
