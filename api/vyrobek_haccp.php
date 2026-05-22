<?php
/**
 * HACCP karta výrobku — print HTML formát.
 * Použití: vyrobek_haccp.php?id=X
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/_admin_auth.php';
require_admin();

$pdo = db();

// Resolve hodnoty: per-výrobek > defaults > auto z výrobku
function pole(array $hd, array $def, string $k, ?string $auto = null): string {
    if (!empty($hd[$k])) return $hd[$k];
    if (!empty($def[$k])) return $def[$k];
    return $auto ?? '';
}

// Společné hodnoty (firma, defaults) — načti jen jednou
$defaults = [];
$nsRaw = nastaveni_get($pdo, 'haccp_defaults', null);
if ($nsRaw) {
    $tmp = json_decode($nsRaw, true);
    if (is_array($tmp)) $defaults = $tmp;
}
$firma_nazev = nastaveni_get($pdo, 'firma_nazev', 'APPEK B2B');
$firma_ulice = nastaveni_get($pdo, 'firma_ulice', '');
$firma_mesto = nastaveni_get($pdo, 'firma_mesto', '');
$firma_psc   = nastaveni_get($pdo, 'firma_psc', '');
$firma_ico   = nastaveni_get($pdo, 'firma_ico', '');

/**
 * Načte všechny HACCP-relevantní data pro jeden výrobek
 * a vrátí asociativní pole proměnných pro extract() do view.
 */
function nacti_haccp_vyrobek(PDO $pdo, int $id, array $defaults, string $firma_nazev, string $firma_ulice, string $firma_mesto): ?array {
    $stmt = $pdo->prepare("
        SELECT v.*, k.nazev AS kategorie_nazev, k.ikona AS kategorie_ikona,
               j.kod AS jednotka_kod
        FROM vyrobky v
        LEFT JOIN kategorie_vyrobku k ON v.kategorie_id = k.id
        LEFT JOIN jednotky j ON v.jednotka_id = j.id
        WHERE v.id = :id
    ");
    $stmt->execute(['id' => $id]);
    $v = $stmt->fetch();
    if (!$v) return null;

    $hd = [];
    if (!empty($v['haccp_data'])) {
        $tmp = json_decode($v['haccp_data'], true);
        if (is_array($tmp)) $hd = $tmp;
    }

    $hmotnost_text = '';
    if (!empty($v['obsah']) && !empty($v['obsah_jednotka'])) {
        $hmotnost_text = rtrim(rtrim(number_format((float) $v['obsah'], 3, '.', ''), '0'), '.') . ' ' . $v['obsah_jednotka'];
    } elseif (!empty($v['hmotnost_g'])) {
        $hmotnost_text = $v['hmotnost_g'] . ' g';
    }

    $produkt        = pole($hd, $defaults, 'produkt',        $v['kategorie_nazev']);
    $obchodni_jmeno = pole($hd, $defaults, 'obchodni_jmeno', $v['nazev']);
    $vyrobce_text   = $firma_nazev;
    if ($firma_ulice) $vyrobce_text .= ', ' . $firma_ulice;
    if ($firma_mesto) $vyrobce_text .= ($firma_ulice ? ', ' : ', ') . $firma_mesto;
    $misto_vyroby   = pole($hd, $defaults, 'misto_vyroby', 'ČR');
    $cilovy_trh     = pole($hd, $defaults, 'cilovy_trh', $firma_nazev . ($firma_ulice ? ' ' . $firma_ulice : '') . ($firma_mesto ? ' ' . $firma_mesto : ''));
    $skupina        = pole($hd, $defaults, 'skupina', $v['kategorie_nazev']);
    $popis_produktu = pole($hd, $defaults, 'popis_produktu', $v['popis']);
    $zpusob_uziti   = pole($hd, $defaults, 'zpusob_uziti', 'k přímé konzumaci');
    $baleni         = pole($hd, $defaults, 'baleni', 'nebalený');
    $trvanlivost    = pole($hd, $defaults, 'trvanlivost', '24 hodin od doby výroby');
    $skladovani     = pole($hd, $defaults, 'skladovani', 'do 25 °C');
    $distribuce     = pole($hd, $defaults, 'distribuce', 'výrobek určen pro prodej');
    $omezeni        = pole($hd, $defaults, 'omezeni', 'bez omezení (nevhodné pro diabetiky, coeliky)');

    $slozeni_text   = $v['slozeni'] ?? '';
    $alergeny_text  = $v['alergeny'] ?? '';

    // Suroviny z pivotu
    $suroviny_vstup = [];
    try {
        $surStmt = $pdo->prepare("
            SELECT s.nazev, COALESCE(s.jednotka, '') AS jednotka,
                   vs.mnozstvi, COALESCE(vs.poradi, 9999) AS poradi
            FROM vyrobek_suroviny vs
            JOIN suroviny s ON vs.surovina_id = s.id
            WHERE vs.vyrobek_id = :id
            ORDER BY vs.poradi, s.nazev
        ");
        $surStmt->execute(['id' => $id]);
        $suroviny_vstup = $surStmt->fetchAll();
    } catch (Throwable $e) { /* tabulka může chybět */ }

    // Fallback: parsuj ze slozeni textu
    if (empty($suroviny_vstup) && trim($slozeni_text) !== '') {
        $depth = 0; $cur = ''; $tokens = [];
        for ($i2 = 0, $len = mb_strlen($slozeni_text); $i2 < $len; $i2++) {
            $ch = mb_substr($slozeni_text, $i2, 1);
            if ($ch === '(') $depth++;
            elseif ($ch === ')') $depth = max(0, $depth - 1);
            elseif ($ch === ',' && $depth === 0) {
                $cur = trim(preg_replace('/\s*\d+(?:[.,]\d+)?\s*%/u', '', $cur));
                if (mb_strlen($cur) > 1 && mb_strlen($cur) < 60) $tokens[] = $cur;
                $cur = ''; continue;
            }
            $cur .= $ch;
        }
        $cur = trim(preg_replace('/\s*\d+(?:[.,]\d+)?\s*%/u', '', $cur));
        if (mb_strlen($cur) > 1) $tokens[] = $cur;
        foreach ($tokens as $t) $suroviny_vstup[] = ['nazev' => $t, 'jednotka' => '', 'mnozstvi' => null];
    }

    // HACCP graf šablona
    $graf_template = null;
    if (!empty($v['haccp_graf_id'])) {
        try {
            $gStmt = $pdo->prepare("SELECT * FROM haccp_grafy WHERE id = ? AND aktivni = 1");
            $gStmt->execute([(int) $v['haccp_graf_id']]);
            $gRow = $gStmt->fetch();
            if ($gRow) {
                $graf_template = [
                    'nazev'    => $gRow['nazev'],
                    'suroviny' => json_decode($gRow['suroviny'] ?? '[]', true) ?: [],
                    'kroky'    => json_decode($gRow['kroky'] ?? '[]', true) ?: [],
                ];
            }
        } catch (Throwable $e) { /* tabulka může chybět */ }
    }

    $title = $v['nazev'] . ($hmotnost_text ? '   ' . $hmotnost_text : '');

    return compact(
        'v', 'hd', 'hmotnost_text', 'produkt', 'obchodni_jmeno', 'vyrobce_text',
        'misto_vyroby', 'cilovy_trh', 'skupina', 'popis_produktu', 'zpusob_uziti',
        'baleni', 'trvanlivost', 'skladovani', 'distribuce', 'omezeni',
        'slozeni_text', 'alergeny_text', 'suroviny_vstup', 'graf_template', 'title'
    );
}

// =============================================================
// Sběr ID — single ?id=X nebo bulk ?ids=1,2,3
// =============================================================
$ids = [];
if (!empty($_GET['ids'])) {
    $ids = array_filter(array_map('intval', explode(',', (string) $_GET['ids'])));
} elseif (!empty($_GET['id'])) {
    $ids = [(int) $_GET['id']];
}
if (empty($ids)) { http_response_code(400); die('Chybí ID výrobku'); }

$haccp_render = [];
foreach ($ids as $i) {
    $data = nacti_haccp_vyrobek($pdo, $i, $defaults, $firma_nazev, $firma_ulice, $firma_mesto);
    if ($data) $haccp_render[] = $data;
}
if (empty($haccp_render)) { http_response_code(404); die('Žádný z výrobků nenalezen'); }

// Pro single výrobek extract globálně (zachová původní variabilní obal)
extract($haccp_render[0]);
$id = $ids[0];

$bulk_count = count($haccp_render);
$autoprint = !empty($_GET['autoprint']);
?>
<!DOCTYPE html>
<html lang="cs">
<head>
<meta charset="UTF-8">
<title><?= $bulk_count > 1 ? 'Tisk ' . $bulk_count . ' HACCP karet' : 'HACCP karta — ' . esc($v['nazev']) ?></title>
<style>
  *{box-sizing:border-box;margin:0;padding:0}
  body{font-family:'Times New Roman',Georgia,serif;color:#000;background:#f5f5f0;padding:20px;font-size:11pt;line-height:1.45}
  .toolbar{max-width:210mm;margin:0 auto 16px;padding:12px 16px;background:#fff;border-radius:8px;display:flex;gap:8px;justify-content:flex-end;box-shadow:0 1px 3px rgba(0,0,0,.08);font-family:-apple-system,sans-serif}
  .toolbar .btn{padding:8px 16px;border:1px solid rgba(0,0,0,.16);background:#fff;border-radius:6px;cursor:pointer;font-size:14px;font-family:inherit}
  .toolbar .btn-primary{background:#BA7517;color:#fff;border-color:#BA7517}
  .info{color:#888;font-size:13px;margin-right:auto;align-self:center}
  .page{max-width:210mm;margin:0 auto;padding:18mm 18mm;background:#fff;min-height:297mm;box-shadow:0 2px 8px rgba(0,0,0,.1)}

  .haccp-title{font-size:14pt;font-weight:700;margin-bottom:14mm;padding-bottom:3mm}
  .haccp-section{font-size:12pt;font-weight:700;margin-bottom:6mm;padding-bottom:1mm}

  table.haccp-tbl{width:100%;border-collapse:collapse;font-size:11pt;margin-bottom:8mm}
  table.haccp-tbl td{border:0.4mm solid #000;padding:2.5mm 4mm;vertical-align:top}
  table.haccp-tbl td.label{width:42%;font-weight:600;background:#FAFAFA}
  table.haccp-tbl td.value{font-weight:400}
  table.haccp-tbl td.value.empty{color:#999;font-style:italic}

  .haccp-block{margin-bottom:6mm}
  .haccp-block-title{font-weight:600;margin-bottom:1mm}
  .haccp-block-text{padding:2mm 0;border-bottom:0.3mm solid #999}

  .haccp-foot{margin-top:14mm;display:flex;justify-content:space-between;font-size:9pt;color:#555;border-top:1px solid #ccc;padding-top:4mm}

  /* Postup */
  .postup-list{display:flex;flex-direction:column;gap:3mm}
  .postup-step{display:flex;gap:5mm;padding:3mm 4mm;background:#FAFAFA;border-left:0.6mm solid #BA7517;border-radius:0 1mm 1mm 0}
  .postup-step.is-ccp{background:#FEE2E2;border-left-color:#dc2626}
  .postup-num{font-weight:700;font-size:11pt;color:#BA7517;flex-shrink:0;width:8mm}
  .postup-step.is-ccp .postup-num{color:#dc2626}
  .postup-body{flex:1}
  .postup-nazev{font-weight:600;font-size:11pt;margin-bottom:0.5mm}

  /* HACCP Flow diagram (Vstupy → Postup) */
  .flow-diagram{display:grid;grid-template-columns:60mm 1fr;gap:8mm;margin:6mm 0 8mm;align-items:start}
  .flow-col-title{font-size:9.5pt;font-weight:700;color:#666;text-transform:uppercase;letter-spacing:0.6pt;padding-bottom:1.5mm;border-bottom:0.4mm solid #999;margin-bottom:3mm}
  .flow-inputs-col{display:flex;flex-direction:column;gap:1.6mm}
  .flow-input-box{display:flex;align-items:center;gap:2mm;background:#FFF8E7;border:0.3mm solid #BA7517;border-radius:1.5mm;padding:1.6mm 2.5mm;font-size:9pt;line-height:1.2}
  .flow-input-num{font-weight:700;color:#BA7517;min-width:6mm;font-size:8.5pt;text-align:right}
  .flow-input-body{flex:1;display:flex;flex-direction:column;gap:0.3mm;min-width:0}
  .flow-input-name{color:#000;font-size:9pt;line-height:1.2}
  .flow-input-target{font-size:7.5pt;color:#854F0B;font-style:italic;line-height:1.15}
  .flow-input-arrow{color:#BA7517;font-weight:700;font-size:11pt;margin-left:1mm;line-height:1}
  .flow-steps-col{display:flex;flex-direction:column;gap:0.8mm}
  .flow-step{display:flex;align-items:center;gap:3mm;background:#F4F0E8;border:0.4mm solid #BA7517;border-left:1mm solid #BA7517;border-radius:1.5mm;padding:2.5mm 4mm}
  .flow-step.is-ccp{background:#FEE2E2;border-color:#dc2626;border-left-color:#dc2626}
  .flow-step-num{font-weight:700;color:#BA7517;min-width:7mm;font-size:11pt}
  .flow-step.is-ccp .flow-step-num{color:#dc2626}
  .flow-step-name{flex:1;font-size:10pt;font-weight:600}
  .flow-ccp-badge{background:#dc2626;color:#fff;font-size:7.5pt;padding:0.5mm 2mm;border-radius:1mm;font-weight:700;letter-spacing:0.4pt}
  .flow-arrow-down{text-align:center;font-size:13pt;color:#BA7517;line-height:0.7;margin-left:7mm;height:3.5mm}
  .postup-popis{font-size:10pt;color:#555}
  .postup-ccp{background:#dc2626;color:#fff;font-size:8pt;padding:0.5mm 2mm;border-radius:1mm;margin-left:2mm;font-weight:700;letter-spacing:0.3pt}

  /* Tabulka kritických bodů */
  table.haccp-kb th{background:#FAFAFA;font-weight:600;font-size:10pt;text-align:left;padding:3mm 3mm}
  table.haccp-kb td{font-size:10pt}

  .page + .page{page-break-before:always}

  @media print {
    body{background:#fff;padding:0;margin:0;font-size:11pt}
    .toolbar{display:none}
    .page{box-shadow:none;padding:14mm 16mm;margin:0;max-width:none;min-height:0}
    @page{size:A4;margin:0}
  }
</style>
</head>
<body>

<div class="toolbar">
  <span class="info">
    <?php if ($bulk_count > 1): ?>
      📦 <strong>Hromadný tisk <?= $bulk_count ?> HACCP karet</strong> · v dialogu „Uložit jako PDF" pro 1 PDF se všemi
    <?php else: ?>
      💡 Pro PDF: Tisk → Uložit jako PDF
    <?php endif; ?>
  </span>
  <button class="btn" onclick="window.history.back()">← Zpět</button>
  <button class="btn btn-primary" onclick="window.print()">🖨️ Tisk / Uložit PDF</button>
</div>

<?php foreach ($haccp_render as $hacIdx => $hacItem):
    extract($hacItem);
    $id = (int) $v['id'];
?>
<?php if ($hacIdx > 0): ?>
<div class="haccp-card-separator" style="page-break-before:always"></div>
<?php endif; ?>

<div class="page">
  <div class="haccp-title"><?= esc($title) ?></div>

  <div class="haccp-section">Popis a složení výrobku:</div>

  <table class="haccp-tbl">
    <tr>
      <td class="label">Produkt:</td>
      <td class="value <?= $produkt ? '' : 'empty' ?>"><?= esc($produkt ?: '—') ?></td>
    </tr>
    <tr>
      <td class="label">Obchodní jméno:</td>
      <td class="value <?= $obchodni_jmeno ? '' : 'empty' ?>"><?= esc($obchodni_jmeno ?: '—') ?></td>
    </tr>
    <tr>
      <td class="label">Výrobce:</td>
      <td class="value"><?= esc($vyrobce_text) ?></td>
    </tr>
    <tr>
      <td class="label">Místo výroby:</td>
      <td class="value <?= $misto_vyroby ? '' : 'empty' ?>"><?= esc($misto_vyroby ?: '—') ?></td>
    </tr>
    <tr>
      <td class="label">Cílový trh:</td>
      <td class="value <?= $cilovy_trh ? '' : 'empty' ?>"><?= nl2br(esc($cilovy_trh ?: '—')) ?></td>
    </tr>
    <tr>
      <td class="label">Skupina:</td>
      <td class="value <?= $skupina ? '' : 'empty' ?>"><?= esc($skupina ?: '—') ?></td>
    </tr>
    <tr>
      <td class="label">Popis produktu:</td>
      <td class="value <?= $popis_produktu ? '' : 'empty' ?>"><?= nl2br(esc($popis_produktu ?: '—')) ?></td>
    </tr>
    <tr>
      <td class="label">Způsob užití:</td>
      <td class="value <?= $zpusob_uziti ? '' : 'empty' ?>"><?= esc($zpusob_uziti ?: '—') ?></td>
    </tr>
    <tr>
      <td class="label">Balení:</td>
      <td class="value <?= $baleni ? '' : 'empty' ?>"><?= esc($baleni ?: '—') ?></td>
    </tr>
    <tr>
      <td class="label">Doba minimální trvanlivosti:</td>
      <td class="value <?= $trvanlivost ? '' : 'empty' ?>"><?= esc($trvanlivost ?: '—') ?></td>
    </tr>
    <tr>
      <td class="label">Skladování:</td>
      <td class="value <?= $skladovani ? '' : 'empty' ?>"><?= esc($skladovani ?: '—') ?></td>
    </tr>
    <tr>
      <td class="label">Podmínky a způsob distribuce:</td>
      <td class="value <?= $distribuce ? '' : 'empty' ?>"><?= nl2br(esc($distribuce ?: '—')) ?></td>
    </tr>
    <tr>
      <td class="label">Seznam surovin použitých k výrobě:</td>
      <td class="value <?= $slozeni_text ? '' : 'empty' ?>"><?= esc($slozeni_text ?: '—') ?></td>
    </tr>
    <?php if ($alergeny_text): ?>
      <tr>
        <td class="label">Alergeny:</td>
        <td class="value"><?= esc($alergeny_text) ?></td>
      </tr>
    <?php endif; ?>
    <tr>
      <td class="label">Skupiny spotřebitelů, pro které je spotřeba omezena:</td>
      <td class="value <?= $omezeni ? '' : 'empty' ?>"><?= esc($omezeni ?: '—') ?></td>
    </tr>
    <?php
    // Vlastní pole — z defaultů + override per-výrobek (pokud někdy přijde)
    $customFields = [];
    if (is_array($defaults['_custom'] ?? null)) {
        foreach ($defaults['_custom'] as $c) {
            if (!empty($c['label'])) {
                $customFields[$c['label']] = $c['value'] ?? '';
            }
        }
    }
    if (is_array($hd['_custom'] ?? null)) {
        foreach ($hd['_custom'] as $c) {
            if (!empty($c['label'])) {
                $customFields[$c['label']] = $c['value'] ?? '';
            }
        }
    }
    foreach ($customFields as $cl => $cv): ?>
      <tr>
        <td class="label"><?= esc($cl) ?>:</td>
        <td class="value <?= $cv ? '' : 'empty' ?>"><?= nl2br(esc($cv ?: '—')) ?></td>
      </tr>
    <?php endforeach; ?>
  </table>

  <div class="haccp-foot">
    <div><?= esc($firma_nazev) ?><?php if ($firma_ico): ?> · IČO <?= esc($firma_ico) ?><?php endif; ?></div>
    <div>Vystaveno <?= date('j. n. Y') ?> · str. 1</div>
  </div>
</div>

<?php
// =============================================================
// STRÁNKA 2 — Výrobní postup (technologický)
// Priorita zdroje: 1) přiřazená šablona (haccp_grafy)  2) hd.postup  3) skip
// =============================================================
$postup = [];          // kroky pro flow diagram
$tpl_suroviny = [];    // vstupy ze šablony (s napojením na krok_idx)
$tpl_nazev = '';

if ($graf_template) {
    $postup = $graf_template['kroky'];
    $tpl_suroviny = $graf_template['suroviny'];
    $tpl_nazev = $graf_template['nazev'];
} elseif (is_array($hd['postup'] ?? null) && !empty($hd['postup'])) {
    $postup = $hd['postup'];
}

// Příprava: vstupy seskupené podle krok_idx (pokud šablona) nebo všechny do kroku 0
$inputs_by_step = [];  // [krok_idx => [{nazev}, ...]]
if (!empty($tpl_suroviny)) {
    foreach ($tpl_suroviny as $s) {
        $idx = (int) ($s['krok_idx'] ?? 0);
        $inputs_by_step[$idx][] = ['nazev' => $s['nazev']];
    }
} else {
    // Z pivotu/složení — všechno jde do kroku 0 (Dávkování)
    foreach ($suroviny_vstup as $s) {
        $inputs_by_step[0][] = ['nazev' => $s['nazev']];
    }
}

if (!empty($postup)):
?>
<div class="page page-postup">
  <div class="haccp-title">
    Výrobní postup — <?= esc($v['nazev']) ?>
    <?php if ($tpl_nazev): ?>
      <span style="font-size:9pt;font-weight:400;color:#888;margin-left:4mm">(šablona: <?= esc($tpl_nazev) ?>)</span>
    <?php endif; ?>
  </div>

  <!-- Flow diagram: vlevo vstupy, vpravo posloupnost technologických kroků -->
  <div class="flow-diagram">
    <!-- LEVÁ STRANA — Vstupy (suroviny) -->
    <div class="flow-inputs-col">
      <div class="flow-col-title">Vstupy (suroviny)</div>
      <?php
      // Zobraz vstupy seskupené podle krok_idx
      $allInputs = [];
      foreach ($inputs_by_step as $idx => $list) {
          foreach ($list as $s) $allInputs[] = ['idx' => $idx, 'nazev' => $s['nazev']];
      }
      if (!empty($allInputs)): foreach ($allInputs as $i => $s):
        $kn = $postup[$s['idx']]['nazev'] ?? '';
      ?>
        <div class="flow-input-box">
          <span class="flow-input-num"><?= ($i + 1) ?>)</span>
          <div class="flow-input-body">
            <div class="flow-input-name"><?= esc($s['nazev']) ?></div>
            <?php if ($s['idx'] > 0 && $kn): ?>
              <div class="flow-input-target">→ krok <?= $s['idx'] + 1 ?>. <?= esc($kn) ?></div>
            <?php endif; ?>
          </div>
          <span class="flow-input-arrow">→</span>
        </div>
      <?php endforeach; else: ?>
        <div style="font-size:9pt;color:#999;font-style:italic;padding:2mm">Suroviny nebyly nalezeny — doplň složení, přiřaď suroviny nebo zvol HACCP graf.</div>
      <?php endif; ?>
    </div>

    <!-- PRAVÁ STRANA — Technologický postup (vertikální flow s šipkami) -->
    <div class="flow-steps-col">
      <div class="flow-col-title">Technologický postup</div>
      <?php foreach ($postup as $i => $p):
        $isCcp = !empty($p['ccp']);
      ?>
        <div class="flow-step <?= $isCcp ? 'is-ccp' : '' ?>">
          <span class="flow-step-num"><?= ($i + 1) ?>.</span>
          <span class="flow-step-name"><?= esc($p['nazev'] ?? '') ?></span>
          <?php if ($isCcp): ?><span class="flow-ccp-badge">CCP</span><?php endif; ?>
        </div>
        <?php if ($i < count($postup) - 1): ?>
          <div class="flow-arrow-down">↓</div>
        <?php endif; ?>
      <?php endforeach; ?>
    </div>
  </div>

  <!-- Detail kroků s popisem (jen pro kroky, kde je něco vyplněno) -->
  <?php
  $postup_s_popisem = array_filter($postup, fn($p) => !empty(trim($p['popis'] ?? '')));
  if (!empty($postup_s_popisem)):
  ?>
  <div class="haccp-section" style="margin-top:8mm">Detail kroků:</div>
  <div class="postup-list">
    <?php foreach ($postup as $i => $p):
      if (empty(trim($p['popis'] ?? ''))) continue;
      $isCcp = !empty($p['ccp']);
    ?>
      <div class="postup-step <?= $isCcp ? 'is-ccp' : '' ?>">
        <div class="postup-num"><?= ($i + 1) ?>.</div>
        <div class="postup-body">
          <div class="postup-nazev"><?= esc($p['nazev'] ?? '') ?><?php if ($isCcp): ?> <span class="postup-ccp">CCP</span><?php endif; ?></div>
          <div class="postup-popis"><?= nl2br(esc($p['popis'])) ?></div>
        </div>
      </div>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>

  <div class="haccp-foot">
    <div><?= esc($firma_nazev) ?></div>
    <div>str. 2 · <?= esc($v['nazev']) ?></div>
  </div>
</div>
<?php endif; ?>

<?php
// =============================================================
// STRÁNKA 3 — Kritické body (analýza nebezpečí)
// =============================================================
$kb = is_array($hd['kriticke_body'] ?? null) ? $hd['kriticke_body'] : [];
if (!empty($kb)):
?>
<div class="page page-kb">
  <div class="haccp-title">Analýza nebezpečí — <?= esc($v['nazev']) ?></div>
  <div class="haccp-section">Kritické body (CCP) a kontrolní body (CP):</div>

  <table class="haccp-tbl haccp-kb">
    <thead>
      <tr>
        <th>Operace</th>
        <th style="width:15mm">Typ</th>
        <th>Popis nebezpečí</th>
        <th>Ovládací opatření</th>
        <th style="width:18mm">Riziko</th>
        <th style="width:18mm">CCP/CP</th>
      </tr>
    </thead>
    <tbody>
      <?php foreach ($kb as $row):
        $isCcp = ($row['ccp'] ?? '') === 'CCP';
      ?>
        <tr <?= $isCcp ? 'style="background:#FEE2E2"' : '' ?>>
          <td><?= esc($row['krok'] ?? '') ?></td>
          <td style="text-align:center;font-weight:700"><?= esc($row['typ'] ?? '') ?></td>
          <td><?= nl2br(esc($row['popis'] ?? '')) ?></td>
          <td><?= nl2br(esc($row['opatreni'] ?? '')) ?></td>
          <td style="text-align:center;font-weight:600"><?= esc($row['riziko'] ?? '') ?></td>
          <td style="text-align:center;font-weight:700;<?= $isCcp ? 'color:#dc2626' : '' ?>"><?= esc($row['ccp'] ?? '') ?></td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table>

  <div style="margin-top:6mm;font-size:9pt;color:#555;border-top:0.3mm solid #ccc;padding-top:3mm">
    <strong>Vysvětlivky:</strong>
    <strong>Typ nebezpečí:</strong> B = biologické, CH = chemické, F = fyzikální ·
    <strong>Riziko:</strong> N = nízké, M = střední, S = stredni, V = vysoké ·
    <strong>CCP</strong> = kritický kontrolní bod, <strong>CP</strong> = kontrolní bod
  </div>

  <div class="haccp-foot">
    <div><?= esc($firma_nazev) ?></div>
    <div>str. 3 · <?= esc($v['nazev']) ?></div>
  </div>
</div>
<?php endif; ?>

<?php
// =============================================================
// STRÁNKA 4 — Jakostní parametry
// =============================================================
$j = is_array($hd['jakost'] ?? null) ? $hd['jakost'] : [];
$mikrobio = $hd['mikrobio'] ?? '';
$hasJakost = !empty($j['vzhled']) || !empty($j['tvar']) || !empty($j['vune']) || !empty($j['chut']) || !empty($j['struktura']) || !empty($mikrobio);
if ($hasJakost):
?>
<div class="page page-jakost">
  <div class="haccp-title">Jakostní parametry — <?= esc($v['nazev']) ?></div>
  <div class="haccp-section">Senzorické a kvalitativní požadavky:</div>

  <table class="haccp-tbl">
    <?php if (!empty($j['vzhled'])): ?><tr><td class="label">Vzhled:</td><td class="value"><?= esc($j['vzhled']) ?></td></tr><?php endif; ?>
    <?php if (!empty($j['tvar'])): ?><tr><td class="label">Tvar / hmotnost:</td><td class="value"><?= esc($j['tvar']) ?></td></tr><?php endif; ?>
    <?php if (!empty($j['vune'])): ?><tr><td class="label">Vůně:</td><td class="value"><?= esc($j['vune']) ?></td></tr><?php endif; ?>
    <?php if (!empty($j['chut'])): ?><tr><td class="label">Chuť:</td><td class="value"><?= esc($j['chut']) ?></td></tr><?php endif; ?>
    <?php if (!empty($j['struktura'])): ?><tr><td class="label">Struktura / konzistence:</td><td class="value"><?= esc($j['struktura']) ?></td></tr><?php endif; ?>
    <?php if (!empty($mikrobio)): ?><tr><td class="label">Mikrobiologické požadavky:</td><td class="value"><?= nl2br(esc($mikrobio)) ?></td></tr><?php endif; ?>
  </table>

  <div class="haccp-foot">
    <div><?= esc($firma_nazev) ?></div>
    <div>str. 4 · <?= esc($v['nazev']) ?></div>
  </div>
</div>
<?php endif; ?>
<?php endforeach; /* /haccp_render */ ?>

<?php if ($autoprint): ?>
<script>
  window.addEventListener('load', function() { setTimeout(function() { window.print(); }, 250); });
</script>
<?php endif; ?>
</body>
</html>
