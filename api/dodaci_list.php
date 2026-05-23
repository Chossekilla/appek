<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/_admin_auth.php';

// 🆕 v2.9.164 — public access přes signed token z e-mailu (alternativa k admin login).
// Endpoint slouží pro DL (?dl_id=N) i pro objednávku (?id=N), token typ rozliší.
$_email_token = $_GET['token'] ?? '';
$_token_auth = false;
$_token_typ   = null;  // 🆕 v2.9.171 — pro objednávku změníme titulek na 'Objednávka'
if ($_email_token !== '') {
    require_once __DIR__ . '/_email_token.php';
    // Token může být typu 'dl' nebo 'obj' — povolíme oboje.
    $_tok_row = verify_email_token(db(), $_email_token);
    if ($_tok_row && in_array($_tok_row['typ'], ['dl', 'obj'], true)) {
        $_token_typ = $_tok_row['typ'];
        if ($_tok_row['typ'] === 'dl') {
            $_GET['dl_id'] = (int) $_tok_row['doklad_id'];
        } else {
            $_GET['id'] = (int) $_tok_row['doklad_id'];
        }
        $_token_auth = true;
    }
}
if (!$_token_auth) {
    require_admin();
}

$pdo = db();

// 🐛 fix v2.9.171 — dodaci_list.php SELECTuje snapshot sloupce, které nemusí
// v staré schémě existovat. Bez lazy DDL endpoint padá s SQLSTATE 1054 hned
// jak ho otevře e-mail recipient přes signed URL token. Idempotentní.
(function() use ($pdo) {
    try {
        $dlp = $pdo->query("
            SELECT COLUMN_NAME FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'dodaci_list_polozky'
        ")->fetchAll(PDO::FETCH_COLUMN);
        $dlp_l = array_map('strtolower', $dlp);
        if ($dlp && !in_array('vyrobek_cislo', $dlp_l, true)) {
            $pdo->exec("ALTER TABLE dodaci_list_polozky ADD COLUMN vyrobek_cislo VARCHAR(40) NULL AFTER vyrobek_id");
        }
        if ($dlp && !in_array('vyrobek_nazev', $dlp_l, true)) {
            $pdo->exec("ALTER TABLE dodaci_list_polozky ADD COLUMN vyrobek_nazev VARCHAR(255) NULL AFTER vyrobek_cislo");
        }
        if ($dlp && !in_array('jednotka', $dlp_l, true)) {
            $pdo->exec("ALTER TABLE dodaci_list_polozky ADD COLUMN jednotka VARCHAR(20) NULL AFTER vyrobek_nazev");
        }
        if ($dlp && !in_array('poznamka', $dlp_l, true)) {
            $pdo->exec("ALTER TABLE dodaci_list_polozky ADD COLUMN poznamka TEXT NULL");
        }

        // dodaci_listy snapshot cols (warning v logu na undefined odb_*_snapshot)
        $dlh = $pdo->query("
            SELECT COLUMN_NAME FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'dodaci_listy'
        ")->fetchAll(PDO::FETCH_COLUMN);
        $dlh_l = array_map('strtolower', $dlh);
        foreach (['odb_nazev_snapshot' => 'VARCHAR(255)', 'odb_ico_snapshot' => 'VARCHAR(20)',
                  'odb_dic_snapshot' => 'VARCHAR(20)', 'odb_ulice_snapshot' => 'VARCHAR(255)',
                  'odb_mesto_snapshot' => 'VARCHAR(120)', 'odb_psc_snapshot' => 'VARCHAR(15)'] as $col => $type) {
            if ($dlh && !in_array($col, $dlh_l, true)) {
                $pdo->exec("ALTER TABLE dodaci_listy ADD COLUMN $col $type NULL");
            }
        }
    } catch (Throwable $e) {
        error_log('dodaci_list.php DDL: ' . $e->getMessage());
    }
})();

// =============================================================
// Helpery — načtení DL (single) podle dl_id NEBO objednávka_id
// =============================================================
function nacti_dl_z_objednavky(PDO $pdo, int $id): ?array {
    $stmt = $pdo->prepare("
        SELECT o.*, od.nazev AS odb_nazev, od.ico, od.dic,
               od.ulice AS odb_ulice, od.mesto AS odb_mesto, od.psc AS odb_psc,
               md.nazev AS misto_nazev, md.ulice AS misto_ulice,
               md.mesto AS misto_mesto, md.psc AS misto_psc,
               md.kontaktni_osoba AS misto_kontakt, md.telefon AS misto_tel,
               dl.id AS dl_id, dl.cislo AS dl_cislo, dl.datum_vystaveni AS dl_vystaveni
        FROM objednavky o
        JOIN odberatele od ON od.id = o.odberatel_id
        LEFT JOIN mista_dodani md ON md.id = o.misto_dodani_id
        LEFT JOIN dodaci_listy dl ON dl.objednavka_id = o.id
        WHERE o.id = :id
    ");
    $stmt->execute(['id' => $id]);
    $o = $stmt->fetch();
    if (!$o) return null;

    if (!empty($o['dl_id'])) {
        $stmt = $pdo->prepare("
            SELECT vyrobek_cislo, vyrobek_nazev, jednotka, mnozstvi, cena_bez_dph, sazba_dph
            FROM dodaci_list_polozky
            WHERE dodaci_list_id = :id
            ORDER BY vyrobek_nazev
        ");
        $stmt->execute(['id' => $o['dl_id']]);
        $polozky = $stmt->fetchAll();
        $cislo_dl = $o['dl_cislo'];
    } else {
        $stmt = $pdo->prepare("
            SELECT v.cislo AS vyrobek_cislo, v.nazev AS vyrobek_nazev,
                   j.kod AS jednotka, p.mnozstvi, p.cena_bez_dph, p.sazba_dph
            FROM objednavky_polozky p
            JOIN vyrobky v ON v.id = p.vyrobek_id
            LEFT JOIN jednotky j ON j.id = v.jednotka_id
            WHERE p.objednavka_id = :id
            ORDER BY v.nazev
        ");
        $stmt->execute(['id' => $id]);
        $polozky = $stmt->fetchAll();
        $cislo_dl = 'DL-' . date('Y', strtotime($o['datum_dodani'])) . '-XXXX (náhled)';
    }
    return ['o' => $o, 'polozky' => $polozky, 'cislo_dl' => $cislo_dl];
}

function nacti_dl_primo(PDO $pdo, int $dl_id): ?array {
    $stmt = $pdo->prepare("
        SELECT dl.*,
               od.nazev AS odb_nazev, od.ico, od.dic,
               od.ulice AS odb_ulice, od.mesto AS odb_mesto, od.psc AS odb_psc,
               md.nazev AS misto_nazev, md.ulice AS misto_ulice,
               md.mesto AS misto_mesto, md.psc AS misto_psc,
               md.kontaktni_osoba AS misto_kontakt, md.telefon AS misto_tel,
               o.cislo AS objednavka_cislo
        FROM dodaci_listy dl
        JOIN odberatele od ON od.id = dl.odberatel_id
        LEFT JOIN mista_dodani md ON md.id = dl.misto_dodani_id
        LEFT JOIN objednavky o ON o.id = dl.objednavka_id
        WHERE dl.id = :id
    ");
    $stmt->execute(['id' => $dl_id]);
    $dl = $stmt->fetch();
    if (!$dl) return null;

    $stmt = $pdo->prepare("
        SELECT vyrobek_cislo, vyrobek_nazev, jednotka, mnozstvi, cena_bez_dph, sazba_dph
        FROM dodaci_list_polozky
        WHERE dodaci_list_id = :id
        ORDER BY id
    ");
    $stmt->execute(['id' => $dl_id]);
    $polozky = $stmt->fetchAll();

    $cislo_dl = $dl['cislo'];
    $o = [
        'cislo'        => $dl['objednavka_cislo'] ?? '',
        'datum_dodani' => $dl['datum_dodani'],
        'odb_nazev'    => $dl['odb_nazev_snapshot'] ?: $dl['odb_nazev'],
        'ico'          => $dl['odb_ico_snapshot']   ?: $dl['ico'],
        'dic'          => $dl['odb_dic_snapshot']   ?: $dl['dic'],
        'odb_ulice'    => $dl['odb_ulice_snapshot'] ?: $dl['odb_ulice'],
        'odb_mesto'    => $dl['odb_mesto_snapshot'] ?: $dl['odb_mesto'],
        'odb_psc'      => $dl['odb_psc_snapshot']   ?: $dl['odb_psc'],
        'misto_nazev'  => $dl['misto_nazev'],
        'misto_ulice'  => $dl['misto_ulice'],
        'misto_mesto'  => $dl['misto_mesto'],
        'misto_psc'    => $dl['misto_psc'],
        'misto_kontakt'=> $dl['misto_kontakt'],
        'misto_tel'    => $dl['misto_tel'],
        'poznamka'     => $dl['poznamka'] ?? null,
    ];
    return ['o' => $o, 'polozky' => $polozky, 'cislo_dl' => $cislo_dl];
}

function pripravit_dl_render(array $data): array {
    $o = $data['o'];
    $polozky = $data['polozky'];
    $cislo_dl = $data['cislo_dl'];

    $datum = date('j. n. Y', strtotime($o['datum_dodani']));
    $misto = $o['misto_nazev']
        ? $o['misto_nazev'] . ', ' . trim($o['misto_ulice'] . ', ' . $o['misto_mesto'] . ' ' . $o['misto_psc'], ' ,')
        : trim($o['odb_ulice'] . ', ' . $o['odb_mesto'] . ' ' . $o['odb_psc'], ' ,');

    $celkem_ks = 0; $celkem_kc = 0;
    foreach ($polozky as $p) {
        $celkem_ks += $p['mnozstvi'];
        $celkem_kc += $p['cena_bez_dph'] * $p['mnozstvi'];
    }
    return [
        'o' => $o, 'polozky' => $polozky, 'cislo_dl' => $cislo_dl,
        'datum' => $datum, 'misto' => $misto,
        'celkem_ks' => $celkem_ks, 'celkem_kc' => $celkem_kc,
    ];
}

// =============================================================
// Sběr ID — single (?id=X NEBO ?dl_id=X) nebo bulk (?ids=… / ?dl_ids=…)
// =============================================================
$dl_render = [];

// Bulk přes ?ids= (objednávky) i ?dl_ids= (přímé DL) — lze kombinovat
if (!empty($_GET['ids'])) {
    foreach (array_filter(array_map('intval', explode(',', (string) $_GET['ids']))) as $i) {
        $d = nacti_dl_z_objednavky($pdo, $i);
        if ($d) $dl_render[] = pripravit_dl_render($d);
    }
}
if (!empty($_GET['dl_ids'])) {
    foreach (array_filter(array_map('intval', explode(',', (string) $_GET['dl_ids']))) as $i) {
        $d = nacti_dl_primo($pdo, $i);
        if ($d) $dl_render[] = pripravit_dl_render($d);
    }
}
// Single ?id= nebo ?dl_id= — jen pokud jsme nedostali bulk
if (empty($dl_render)) {
    if (!empty($_GET['dl_id'])) {
        $d = nacti_dl_primo($pdo, (int) $_GET['dl_id']);
        if ($d) $dl_render[] = pripravit_dl_render($d);
    } elseif (!empty($_GET['id'])) {
        $d = nacti_dl_z_objednavky($pdo, (int) $_GET['id']);
        if ($d) $dl_render[] = pripravit_dl_render($d);
    } else {
        http_response_code(400); die('Chybí ID');
    }
}

if (empty($dl_render)) { http_response_code(404); die('Dodací list nenalezen'); }

// Pro single zachovej původní proměnné (kompatibilita)
$o = $dl_render[0]['o'];
$polozky = $dl_render[0]['polozky'];
$cislo_dl = $dl_render[0]['cislo_dl'];
$datum = $dl_render[0]['datum'];
$misto = $dl_render[0]['misto'];
$celkem_ks = $dl_render[0]['celkem_ks'];
$celkem_kc = $dl_render[0]['celkem_kc'];

$bulk_count = count($dl_render);
$autoprint = !empty($_GET['autoprint']);

// 🆕 v2.9.171 — pokud user otevřel odkaz z e-mailu typu 'obj', titulek dokumentu
// změníme z 'Dodací list' na 'Objednávka' (a podle toho i tabulku v <body>).
// Bez tokenu (admin view) zůstává "dodací list" jako historicky.
$_is_objednavka_view = (isset($_token_typ) && $_token_typ === 'obj');
$_titulek_nazev = $_is_objednavka_view ? 'Objednávka' : 'Dodací list';
$_titulek_slovo_2pad = $_is_objednavka_view ? 'objednávky' : 'dodacího listu';
$_cislo_zobrazit = $_is_objednavka_view ? ($o['cislo'] ?: $cislo_dl) : $cislo_dl;
?>
<!DOCTYPE html>
<html lang="cs">
<head>
<meta charset="UTF-8">
<title><?= $bulk_count > 1 ? 'Tisk ' . $bulk_count . ' ' . esc($_titulek_slovo_2pad) : esc($_cislo_zobrazit) . ' - ' . strtolower($_titulek_nazev) ?></title>
<style>
  *{box-sizing:border-box;margin:0;padding:0}
  body{font-family:'Helvetica Neue',Arial,sans-serif;color:#2C2C2A;background:#f5f5f0;padding:20px}
  .toolbar{max-width:210mm;margin:0 auto 16px;padding:12px 16px;background:#fff;border-radius:8px;display:flex;gap:8px;justify-content:flex-end;box-shadow:0 1px 3px rgba(0,0,0,.08)}
  .toolbar .btn{padding:8px 16px;border:1px solid rgba(0,0,0,.16);background:#fff;border-radius:6px;cursor:pointer;font-size:14px;font-family:inherit}
  .toolbar .btn-primary{background:#BA7517;color:#fff;border-color:#BA7517}
  .toolbar .btn-primary:hover{background:#854F0B}
  .info{color:#888;font-size:13px;margin-right:auto;align-self:center}
  .page{max-width:210mm;margin:0 auto;padding:18mm 20mm;background:#fff;min-height:297mm;box-shadow:0 2px 8px rgba(0,0,0,.1);font-size:11pt}
  .header{display:flex;justify-content:space-between;margin-bottom:16mm}
  h1{font-size:28pt;color:#BA7517;font-weight:600}
  .cislo{font-size:14pt;margin-top:4mm;color:#555}
  .firma{text-align:right;line-height:1.6}
  .firma .nazev{font-weight:600;font-size:13pt;margin-bottom:4px}
  .parties{display:grid;grid-template-columns:1fr 1fr;gap:16mm;margin-bottom:8mm}
  .parties h3{font-size:9pt;color:#888;margin-bottom:4mm;text-transform:uppercase;letter-spacing:1px;font-weight:500}
  .parties .name{font-size:13pt;font-weight:600;margin-bottom:4px}
  .parties .row{line-height:1.7;font-size:11pt}
  .info-box{background:#FAEEDA;padding:5mm 8mm;border-radius:6px;margin-bottom:8mm;display:grid;grid-template-columns:repeat(3,1fr);gap:8mm}
  .info-box .lbl{color:#855;font-size:9pt;text-transform:uppercase;margin-bottom:2px}
  .info-box .val{font-weight:600;font-size:13pt;color:#854F0B}
  table.items{width:100%;border-collapse:collapse;margin-bottom:6mm}
  table.items th{background:#F1EFE8;padding:4mm 5mm;text-align:left;font-size:9pt;color:#888;text-transform:uppercase;border-bottom:1px solid #aaa;font-weight:500;letter-spacing:.5px}
  table.items td{padding:4mm 5mm;border-bottom:1px solid #E5E3DD;font-size:11pt}
  table.items td.num,table.items th.num{text-align:right}
  .summary{float:right;width:80mm;margin-top:4mm}
  .summary-row{display:flex;justify-content:space-between;padding:2mm 0;font-size:11pt}
  .summary-row.total{border-top:1px solid #888;padding-top:4mm;margin-top:2mm;font-weight:600;font-size:14pt}
  .pozn{clear:both;background:#FAEEDA;color:#854F0B;padding:4mm 6mm;border-radius:4px;margin:6mm 0;font-size:10pt}
  .signatures{margin-top:24mm;display:grid;grid-template-columns:1fr 1fr;gap:24mm}
  .sig-line{border-top:1px solid #888;padding-top:3mm;text-align:center;font-size:10pt;color:#888}
  .foot{margin-top:16mm;padding-top:4mm;border-top:1px solid #ddd;font-size:9pt;color:#666}
  .foot-row{display:flex;justify-content:space-between;gap:12mm;margin-bottom:3mm}
  .foot-cell{flex:1}
  .foot-cell .lbl{font-size:8pt;color:#aaa;text-transform:uppercase;letter-spacing:0.4pt;margin-bottom:1mm}
  .foot-cell .val{color:#333;font-weight:500}
  .foot-custom{margin-top:3mm;text-align:center;font-size:8.5pt;color:#888;line-height:1.45;white-space:pre-line}
  .foot-meta{margin-top:3mm;text-align:center;font-size:8pt;color:#bbb;border-top:1px dotted #eee;padding-top:2mm}
  @media print {
    body { background: #fff; padding: 0; margin: 0; }
    .toolbar { display: none; }
    .page {
      box-shadow: none;
      padding: 8mm 12mm 6mm 12mm;
      max-width: none;
      margin: 0;
      min-height: 0;
      font-size: 10pt;
    }
    /* Vypnout záhlaví/zápatí prohlížeče (URL, datum, číslo stránky) */
    @page {
      size: A4;
      margin: 0;
    }
    /* Zhuštění mezery pro print, aby se vlezlo na 1 list */
    h1 { font-size: 22pt; }
    .cislo { font-size: 12pt; margin-top: 2mm; }
    .firma .nazev { font-size: 11pt; }
    .header { margin-bottom: 8mm; }
    .parties { margin-bottom: 5mm; gap: 10mm; }
    .parties .row { line-height: 1.45; font-size: 10pt; }
    .parties .name { font-size: 11pt; }
    .info-box { padding: 3mm 5mm; margin-bottom: 5mm; gap: 5mm; }
    .info-box .val { font-size: 11pt; }
    table.items { margin-bottom: 4mm; }
    table.items th { padding: 2.5mm 3mm; font-size: 8pt; }
    table.items td { padding: 2mm 3mm; font-size: 9.5pt; }
    .summary-row { padding: 1.2mm 0; font-size: 10pt; }
    .summary-row.total { padding-top: 2.5mm; font-size: 12pt; }
    .signatures { margin-top: 14mm; gap: 16mm; }
    .sig-line { padding-top: 2mm; font-size: 9pt; }
    .foot { margin-top: 8mm; padding-top: 3mm; font-size: 8pt; }
    .foot-row { margin-bottom: 2mm; gap: 8mm; }
    .foot-custom { margin-top: 2mm; font-size: 8pt; }
    .foot-meta { margin-top: 2mm; font-size: 7.5pt; padding-top: 1.5mm; }
    /* Zabránit zalomení uvnitř tabulky a sekcí */
    table.items, .summary, .signatures, .foot { page-break-inside: avoid; }
    table.items tr { page-break-inside: avoid; }
    /* Bulk tisk — každý DL na vlastní stránce */
    .page + .page { page-break-before: always; }
  }
  /* I v náhledu zviditelnit oddělení mezi DL */
  .page + .page { margin-top: 20px; }
</style>
</head>
<body>
<div class="toolbar">
  <span class="info">
    <?php if ($bulk_count > 1): ?>
      📦 <strong>Hromadný tisk <?= $bulk_count ?> dodacích listů</strong> · v dialogu „Uložit jako PDF" pro 1 PDF s více stránkami
    <?php else: ?>
      💡 Pro PDF klikněte „Tisk" → v dialogu „Uložit jako PDF"
    <?php endif; ?>
  </span>
  <button class="btn" onclick="window.history.back()">← Zpět</button>
  <button class="btn btn-primary" onclick="window.print()">🖨 Tisk / Uložit PDF</button>
</div>
<?php foreach ($dl_render as $idx => $dlr):
    $o = $dlr['o'];
    $polozky = $dlr['polozky'];
    $cislo_dl = $dlr['cislo_dl'];
    $datum = $dlr['datum'];
    $misto = $dlr['misto'];
    $celkem_ks = $dlr['celkem_ks'];
    $celkem_kc = $dlr['celkem_kc'];
?>
<div class="page">
  <div class="header">
    <div>
      <?php $zobrazitLogo = nastaveni_get($pdo, 'firma_logo_na_dokladech', '1') === '1';
            $logoUrl = $zobrazitLogo ? nastaveni_get($pdo, 'firma_logo_url', '') : '';
            if ($logoUrl): ?>
        <img src="<?= esc($logoUrl) ?>" style="max-height:18mm;max-width:60mm;margin-bottom:6mm;object-fit:contain" alt="Logo">
      <?php endif; ?>
      <h1><?= esc($_titulek_nazev) ?></h1>
      <div class="cislo">č. <strong><?= esc($_cislo_zobrazit) ?></strong></div>
    </div>
    <div class="firma">
      <div class="nazev"><?= esc(firma('nazev', 'APPEK B2B')) ?></div>
      <?php if (firma('ulice')): ?><div><?= esc(firma('ulice')) ?></div><?php endif; ?>
      <?php if (firma('mesto') || firma('psc')): ?><div><?= esc(trim(firma('mesto') . ' ' . firma('psc'))) ?></div><?php endif; ?>
      <?php if (firma('ico')): ?><div>IČO: <?= esc(firma('ico')) ?></div><?php endif; ?>
      <?php if (firma('dic')): ?><div>DIČ: <?= esc(firma('dic')) ?></div><?php endif; ?>
    </div>
  </div>
  <div class="parties">
    <div>
      <h3>Odběratel</h3>
      <div class="name"><?= esc($o['odb_nazev']) ?></div>
      <div class="row">
        <?php if ($o['odb_ulice']): ?><?= esc($o['odb_ulice']) ?><br><?php endif; ?>
        <?php if ($o['odb_mesto'] || $o['odb_psc']): ?><?= esc(trim($o['odb_mesto'] . ' ' . $o['odb_psc'])) ?><br><?php endif; ?>
        <?php if ($o['ico']): ?>IČO: <?= esc($o['ico']) ?><br><?php endif; ?>
        <?php if ($o['dic']): ?>DIČ: <?= esc($o['dic']) ?><?php endif; ?>
      </div>
    </div>
    <div>
      <h3>Místo dodání</h3>
      <div class="row">
        <?= esc($misto) ?>
        <?php if ($o['misto_kontakt']): ?><br>Kontakt: <?= esc($o['misto_kontakt']) ?><?php endif; ?>
        <?php if ($o['misto_tel']): ?>, tel. <?= esc($o['misto_tel']) ?><?php endif; ?>
      </div>
    </div>
  </div>
  <div class="info-box">
    <div><div class="lbl">Datum dodání</div><div class="val"><?= esc($datum) ?></div></div>
    <div><div class="lbl">Č. objednávky</div><div class="val"><?= esc($o['cislo']) ?></div></div>
    <div><div class="lbl">Počet kusů</div><div class="val"><?= fmt_ks($celkem_ks) ?> ks</div></div>
  </div>
  <table class="items">
    <thead><tr><th style="width:18mm">Kód</th><th>Výrobek</th><th class="num" style="width:24mm">Množství</th><th class="num" style="width:26mm">Cena/ks</th><th class="num" style="width:30mm">Celkem</th></tr></thead>
    <tbody>
      <?php foreach ($polozky as $p): $cena = $p['cena_bez_dph'] * $p['mnozstvi']; ?>
        <tr>
          <td><?= esc($p['vyrobek_cislo']) ?></td>
          <td><strong><?= esc($p['vyrobek_nazev']) ?></strong></td>
          <td class="num"><?= fmt_ks($p['mnozstvi']) ?> <?= esc($p['jednotka'] ?? 'ks') ?></td>
          <td class="num"><?= fmt_kc($p['cena_bez_dph']) ?></td>
          <td class="num"><strong><?= fmt_kc($cena) ?></strong></td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
  <div class="summary">
    <div class="summary-row total"><span>Celkem bez DPH</span><span><?= fmt_kc($celkem_kc) ?></span></div>
  </div>
  <div style="clear:both"></div>
  <?php if ($o['poznamka']): ?><div class="pozn"><strong>Poznámka:</strong> <?= esc($o['poznamka']) ?></div><?php endif; ?>
  <div class="signatures">
    <div class="sig-line">Vystavil (dodavatel)</div>
    <div class="sig-line">Převzal (odběratel)</div>
  </div>
  <div class="foot">
    <?php
      $tel  = firma('telefon', '');
      $em   = firma('email', '');
      $web  = firma('web', '');
      $pati = firma('paticka_dokladu', '');
    ?>
    <?php if ($tel || $em || $web): ?>
      <div class="foot-row">
        <?php if ($tel): ?>
          <div class="foot-cell"><div class="lbl">Telefon</div><div class="val"><?= esc($tel) ?></div></div>
        <?php endif; ?>
        <?php if ($em): ?>
          <div class="foot-cell"><div class="lbl">E-mail</div><div class="val"><?= esc($em) ?></div></div>
        <?php endif; ?>
        <?php if ($web): ?>
          <div class="foot-cell"><div class="lbl">Web</div><div class="val"><?= esc($web) ?></div></div>
        <?php endif; ?>
      </div>
    <?php endif; ?>
    <?php if ($pati): ?>
      <div class="foot-custom"><?= esc($pati) ?></div>
    <?php endif; ?>
    <div class="foot-meta">Vytištěno <?= date('j. n. Y H:i') ?> · <?= esc(firma('nazev', 'APPEK B2B')) ?></div>
  </div>
</div>
<?php endforeach; ?>
<?php if ($autoprint): ?>
<script>
  window.addEventListener('load', function() { setTimeout(function() { window.print(); }, 250); });
</script>
<?php endif; ?>
</body>
</html>
