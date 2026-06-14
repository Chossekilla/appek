<?php
/**
 * PDF Katalog výrobků — generuje tisknutelnou HTML stránku (Tisk → PDF z prohlížeče).
 *
 * Filtry:
 *   ?kategorie=1,2     - jen výrobky z těchto kategorií
 *   ?vyrobky=10,11,12  - jen tyto konkrétní výrobky (priorita před kategoriemi)
 *   ?skupina=5         - aplikuj ceník z této cenové skupiny (pravidla)
 *   ?nazev=Letní+nabídka - vlastní nadpis nabídky
 */
header('Content-Type: text/html; charset=utf-8');
require_once __DIR__ . '/config.php';

$pdo = db();

$kategorie_ids = !empty($_GET['kategorie']) ? array_filter(array_map('intval', explode(',', $_GET['kategorie']))) : [];
$vyrobek_ids   = !empty($_GET['vyrobky'])   ? array_filter(array_map('intval', explode(',', $_GET['vyrobky'])))   : [];
$skupina_id    = !empty($_GET['skupina'])   ? (int) $_GET['skupina'] : 0;
$nazev_nabidky = trim($_GET['nazev'] ?? '');

// Per-product poznámky: pozn[ID]=text (např. "poslední kusy", "akce -10%")
$poznamky_raw = $_GET['pozn'] ?? [];
$poznamky = [];
if (is_array($poznamky_raw)) {
    foreach ($poznamky_raw as $id => $txt) {
        $iid = (int) $id;
        if ($iid > 0 && trim((string) $txt) !== '') {
            $poznamky[$iid] = mb_substr(trim((string) $txt), 0, 120);
        }
    }
}

try {
    // --- Výrobky (s aktuálními sloupci) ---
    // 🆕 v3.0.335 — subkategorie: vyber hlavní kat. zahrne i produkty v jejích subkategoriích
    if ($kategorie_ids) {
        $in = implode(',', array_map('intval', $kategorie_ids));
        try {
            $subs = $pdo->query("SELECT id FROM kategorie_vyrobku WHERE parent_id IN ($in)")->fetchAll(PDO::FETCH_COLUMN);
            if ($subs) $kategorie_ids = array_values(array_unique(array_merge($kategorie_ids, array_map('intval', $subs))));
        } catch (Throwable $e) {}
    }

    $sql = "
        SELECT v.id, v.cislo, v.nazev, v.popis, v.alergeny, v.hmotnost_g,
               v.cena_bez_dph, v.kategorie_id, v.obrazek_url, v.poradi,
               k.nazev AS kategorie_nazev, k.poradi AS kategorie_poradi, k.parent_id AS kategorie_parent_id,
               p.nazev AS hlavni_nazev, COALESCE(k.parent_id, k.id) AS hlavni_id,
               COALESCE(p.poradi, k.poradi) AS hlavni_poradi,
               j.kod AS jednotka,
               s.sazba AS dph
        FROM vyrobky v
        LEFT JOIN kategorie_vyrobku k ON v.kategorie_id = k.id
        LEFT JOIN kategorie_vyrobku p ON p.id = k.parent_id
        LEFT JOIN jednotky j ON v.jednotka_id = j.id
        LEFT JOIN sazby_dph s ON v.sazba_dph_id = s.id
        WHERE v.aktivni = 1
    ";
    $params = [];

    if ($vyrobek_ids) {
        $ph = implode(',', array_fill(0, count($vyrobek_ids), '?'));
        $sql .= " AND v.id IN ($ph)";
        $params = array_merge($params, $vyrobek_ids);
    } elseif ($kategorie_ids) {
        $ph = implode(',', array_fill(0, count($kategorie_ids), '?'));
        $sql .= " AND v.kategorie_id IN ($ph)";
        $params = array_merge($params, $kategorie_ids);
    }
    // hlavní kategorie → uvnitř přímé produkty (parent NULL) před subkategoriemi
    $sql .= " ORDER BY hlavni_poradi, k.parent_id IS NOT NULL, k.poradi, v.poradi, v.nazev";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $vyrobky = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // --- Cenová skupina + pravidla (volitelné) ---
    $skupina_nazev = 'Základní ceník';
    $globalni_sleva = null;   // 💰 sleva pro celý ceník (např. -3 %)
    $idx_vyrobek = []; $idx_kategorie = []; $idx_sortiment = null;
    if ($skupina_id) {
        $stmt = $pdo->prepare("SELECT nazev, globalni_sleva_pct FROM cenove_skupiny WHERE id = :id");
        $stmt->execute(['id' => $skupina_id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row) {
            if ($row['nazev']) $skupina_nazev = $row['nazev'];
            if ($row['globalni_sleva_pct'] !== null) $globalni_sleva = (float) $row['globalni_sleva_pct'];
        }

        $stmt = $pdo->prepare("
            SELECT kategorie_id, vyrobek_id, sleva_pct, pevna_cena
            FROM cenove_skupiny_slevy
            WHERE skupina_id = :s
        ");
        $stmt->execute(['s' => $skupina_id]);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
            if ($r['vyrobek_id'])         $idx_vyrobek[(int)$r['vyrobek_id']] = $r;
            elseif ($r['kategorie_id'])   $idx_kategorie[(int)$r['kategorie_id']] = $r;
            else                          $idx_sortiment = $r;
        }
    }

    // --- Aplikuj ceník na každý výrobek ---
    // Priorita: per-vyrobek > per-kategorie > sortiment rule > globalni_sleva_pct > základní cena
    foreach ($vyrobky as &$v) {
        $base = (float) $v['cena_bez_dph'];
        $rule = $idx_vyrobek[(int)$v['id']]
              ?? $idx_kategorie[(int)$v['kategorie_id']]
              ?? $idx_sortiment
              ?? null;

        $v['cena_zakl'] = $base;
        if ($rule) {
            if ($rule['pevna_cena'] !== null) {
                $v['cena_finalni'] = (float) $rule['pevna_cena'];
                $v['sleva_pct'] = null;
            } else {
                $sl = (float) $rule['sleva_pct'];
                $v['cena_finalni'] = round($base * (1 - $sl / 100), 2);
                $v['sleva_pct'] = $sl;
            }
        } elseif ($globalni_sleva !== null && $globalni_sleva > 0) {
            // 💰 Globální sleva ceníku (fallback když není specifické pravidlo)
            $v['cena_finalni'] = round($base * (1 - $globalni_sleva / 100), 2);
            $v['sleva_pct'] = $globalni_sleva;
        } else {
            $v['cena_finalni'] = $base;
            $v['sleva_pct'] = null;
        }
        $dph = (float) ($v['dph'] ?? 0);
        $v['cena_s_dph'] = round($v['cena_finalni'] * (1 + $dph / 100), 2);
    }
    unset($v);

    // 🆕 v3.0.335 — vnořené seskupení: hlavní kategorie → přímé produkty → subkategorie
    $grouped = []; // hlavni_id => ['nazev', 'direct'=>[], 'subs'=>[subId=>['nazev','items'=>[]]], 'total']
    foreach ($vyrobky as $v) {
        $hid = (int) ($v['hlavni_id'] ?: 0);
        if (!isset($grouped[$hid])) {
            $grouped[$hid] = ['nazev' => $v['hlavni_nazev'] ?: ($v['kategorie_nazev'] ?: 'Bez kategorie'), 'direct' => [], 'subs' => [], 'total' => 0];
        }
        if (!empty($v['kategorie_parent_id'])) {
            $sid = (int) $v['kategorie_id'];
            if (!isset($grouped[$hid]['subs'][$sid])) $grouped[$hid]['subs'][$sid] = ['nazev' => $v['kategorie_nazev'], 'items' => []];
            $grouped[$hid]['subs'][$sid]['items'][] = $v;
        } else {
            $grouped[$hid]['direct'][] = $v;
        }
        $grouped[$hid]['total']++;
    }

    // Sdílený renderer produktové karty (přímé i subkategorie)
    $renderCard = function ($v) use ($poznamky) {
        $sl_zobrazit = $v['sleva_pct'] !== null && $v['cena_finalni'] < $v['cena_zakl'];
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
        ob_start(); ?>
        <div class="pc">
          <?php if ($img): ?><img src="<?= esc($img) ?>" alt=""><?php endif; ?>
          <div class="name"><?= esc($v['nazev']) ?></div>
          <?php if (!empty($v['cislo'])): ?><div class="cislo">č. <?= esc($v['cislo']) ?></div><?php endif; ?>
          <div class="meta">
            <?php
              $bits = [];
              if (!empty($v['hmotnost_g'])) $bits[] = (int)$v['hmotnost_g'] . ' g';
              if (!empty($v['jednotka']))   $bits[] = esc($v['jednotka']);
              echo implode(' · ', $bits);
            ?>
          </div>
          <?php if (!empty($poznamky[(int)$v['id']])): ?>
            <div class="badge-pozn">⭐ <?= esc($poznamky[(int)$v['id']]) ?></div>
          <?php endif; ?>
          <?php if (!empty($v['popis'])): ?>
            <div class="popis"><?= esc(mb_substr($v['popis'], 0, 140)) ?><?= mb_strlen($v['popis']) > 140 ? '…' : '' ?></div>
          <?php endif; ?>
          <?php if (!empty($v['alergeny'])): ?>
            <div class="alergeny">Alergeny: <?= esc($v['alergeny']) ?></div>
          <?php endif; ?>
          <div class="price-row">
            <div>
              <span class="price"><?= number_format($v['cena_s_dph'], 2, ',', ' ') ?> Kč</span>
              <?php if ($sl_zobrazit): ?>
                <span class="price-old"><?= number_format($v['cena_zakl'] * (1 + ((float)$v['dph']) / 100), 2, ',', ' ') ?> Kč</span>
                <span class="badge-sleva">−<?= round($v['sleva_pct']) ?>%</span>
              <?php endif; ?>
              <div class="price-dph">bez DPH <?= number_format($v['cena_finalni'], 2, ',', ' ') ?> Kč · DPH <?= round((float)$v['dph']) ?>%</div>
            </div>
          </div>
        </div>
        <?php return ob_get_clean();
    };

    // Údaje firmy z nastavení
    $firma_nazev   = nastaveni_get($pdo, 'firma_nazev', 'APPEK B2B');
    $firma_ulice   = nastaveni_get($pdo, 'firma_ulice', '');
    $firma_mesto   = nastaveni_get($pdo, 'firma_mesto', '');
    $firma_telefon = nastaveni_get($pdo, 'firma_telefon', '');
    $firma_email   = nastaveni_get($pdo, 'firma_email', '');
    $firma_web     = nastaveni_get($pdo, 'firma_web', '');

} catch (Exception $e) {
    die('Chyba: ' . htmlspecialchars($e->getMessage()));
}

$titulek = $nazev_nabidky !== '' ? $nazev_nabidky : 'Nabídka výrobků';
?><!DOCTYPE html>
<html lang="cs">
<head>
<meta charset="UTF-8">
<title><?= esc($titulek) ?> — <?= esc($firma_nazev) ?></title>
<style>
*{box-sizing:border-box;margin:0;padding:0}
body{font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,sans-serif;color:#222;padding:24px;background:#fff}
.toolbar{position:sticky;top:0;background:#fff;border-bottom:1px solid #eee;padding:12px 0;margin-bottom:24px;display:flex;gap:8px;align-items:center;z-index:10}
.toolbar button{padding:8px 16px;font-size:14px;border:none;border-radius:6px;cursor:pointer;font-weight:600}
.btn-print{background:#22863a;color:#fff}
.btn-close{background:#f5f5f5;color:#333}
.toolbar .info{margin-left:auto;font-size:12px;color:#888}
.header{text-align:center;margin-bottom:32px;padding-bottom:20px;border-bottom:3px solid #BA7517}
.header h1{font-size:32px;color:#BA7517;margin-bottom:6px;letter-spacing:-0.5px}
.header h2{font-size:18px;color:#555;font-weight:500;margin-bottom:8px}
.header .meta{font-size:13px;color:#888}
.kat-section{margin:42px 0 0;padding-top:28px;border-top:2px solid #E8D5B0;position:relative}
.kat-section:first-of-type{border-top:none;margin-top:24px;padding-top:0}
.kat-title{background:#FFF9F0;border-left:4px solid #BA7517;padding:14px 22px;font-size:20px;font-weight:700;color:#854F0B;margin:0 0 18px;border-radius:0 6px 6px 0;display:flex;align-items:baseline;gap:10px}
.kat-title .kat-pocet{font-weight:400;color:#aaa;font-size:13px;letter-spacing:0.3px}
.products{display:grid;grid-template-columns:repeat(3,1fr);gap:14px}
.pc{border:1px solid #E8D5B0;border-radius:10px;padding:14px;background:#fff;page-break-inside:avoid;display:flex;flex-direction:column}
.pc img{width:100%;height:120px;object-fit:cover;border-radius:6px;margin-bottom:10px;background:#FFF9F0}
.pc .name{font-size:15px;font-weight:600;margin-bottom:4px;line-height:1.3}
.pc .cislo{font-size:11px;color:#888;margin-bottom:6px}
.pc .meta{font-size:11px;color:#666;margin-bottom:8px;min-height:14px}
.pc .popis{font-size:11px;color:#555;margin-bottom:8px;line-height:1.4;flex:1}
.pc .alergeny{font-size:10px;color:#999;margin-bottom:8px}
.pc .price-row{display:flex;align-items:baseline;justify-content:space-between;border-top:1px dashed #E8D5B0;padding-top:8px;margin-top:auto}
.pc .price{font-size:18px;font-weight:700;color:#BA7517}
.pc .price-old{font-size:12px;color:#999;text-decoration:line-through;margin-left:6px}
.pc .price-dph{font-size:10px;color:#888}
.pc .badge-sleva{display:inline-block;background:#22863a;color:#fff;font-size:10px;padding:2px 6px;border-radius:3px;margin-left:6px;font-weight:700}
.pc .badge-pozn{display:block;background:#fff3cd;color:#854F0B;font-size:11px;padding:5px 8px;border-radius:5px;margin:6px 0;font-weight:600;border-left:3px solid #BA7517}
.footer{margin-top:40px;padding-top:20px;border-top:1px solid #ddd;font-size:12px;color:#666;display:flex;justify-content:space-between;flex-wrap:wrap;gap:8px}
.empty{text-align:center;padding:60px 20px;color:#999;font-style:italic}
@media print{
  .toolbar{display:none!important}
  /* Vlastní okraje místo browser default — schová URL/datum z hlavičky/patičky */
  body{padding:1.5cm;margin:0}
  .pc{break-inside:avoid}
  .kat-section{break-inside:avoid-page}
  .kat-title{break-after:avoid;page-break-after:avoid}
  @page{size:A4;margin:0}
}
@media (max-width:900px){.products{grid-template-columns:repeat(2,1fr)}}
@media (max-width:600px){.products{grid-template-columns:1fr}}
</style>
</head>
<body>

<div class="toolbar">
  <button class="btn-print" onclick="window.print()">🖨️ Tisk / Uložit jako PDF</button>
  <button class="btn-close" onclick="window.close()">✕ Zavřít</button>
  <span class="info"><?= count($vyrobky) ?> výrobků · <?= esc($skupina_nazev) ?></span>
</div>

<div class="header">
  <h1><?= esc($firma_nazev) ?></h1>
  <h2><?= esc($titulek) ?></h2>
  <div class="meta">
    <?= esc($skupina_nazev) ?> · vystaveno <?= date('j. n. Y') ?>
  </div>
</div>

<?php if (empty($vyrobky)): ?>
  <div class="empty">Žádné výrobky odpovídající zadaným filtrům.</div>
<?php else: ?>
  <?php foreach ($grouped as $g): ?>
    <div class="kat-section">
    <div class="kat-title"><?= esc($g['nazev']) ?> <span class="kat-pocet">(<?= (int)$g['total'] ?>)</span></div>
    <?php if (!empty($g['direct'])): ?>
      <div class="products"><?php foreach ($g['direct'] as $v) echo $renderCard($v); ?></div>
    <?php endif; ?>
    <?php foreach ($g['subs'] as $sub): ?>
      <div class="kat-subtitle" style="font-size:13px;font-weight:700;margin:10px 0 4px;opacity:.82">↳ <?= esc($sub['nazev']) ?> <span class="kat-pocet">(<?= count($sub['items']) ?>)</span></div>
      <div class="products"><?php foreach ($sub['items'] as $v) echo $renderCard($v); ?></div>
    <?php endforeach; ?>
    </div>
  <?php endforeach; ?>
<?php endif; ?>

<div class="footer">
  <div>
    <strong><?= esc($firma_nazev) ?></strong>
    <?php if ($firma_ulice || $firma_mesto): ?> · <?= esc(trim("$firma_ulice $firma_mesto")) ?><?php endif; ?>
  </div>
  <div>
    <?php if ($firma_telefon): ?>📞 <?= esc($firma_telefon) ?> <?php endif; ?>
    <?php if ($firma_email): ?>· ✉ <?= esc($firma_email) ?> <?php endif; ?>
    <?php if ($firma_web): ?>· 🌐 <?= esc($firma_web) ?><?php endif; ?>
  </div>
</div>

</body>
</html>
