<?php
/**
 * 🧾 FAKTURY — lehký fakturační modul vendoru.
 *
 * Pro fakturaci IT služeb (a případně prodeje licencí). Neplátce DPH → bez DPH rozpisu.
 * Identita prodávajícího z vendor_settings (business_*). QR Platba (SPAYD) + e-mail + tisk.
 *
 *   GET                       → seznam faktur
 *   GET  ?new=1 | ?edit=N     → formulář
 *   GET  ?view=N              → tisknutelný doklad (QR platba)
 *   POST ?action=save         → vytvořit/upravit
 *   POST ?action=mark_paid&id → označit zaplaceno
 *   POST ?action=storno&id    → storno
 *   POST ?action=send&id      → odeslat e-mailem
 */
require_once __DIR__ . '/_lib.php';
require_once __DIR__ . '/_layout.php';
require_once __DIR__ . '/_mail.php';

$user = vendor_require_login();
$pdo  = vendor_db();
$currentPage = 'faktury';

$pdo->exec("CREATE TABLE IF NOT EXISTS vendor_invoices (
    id INT AUTO_INCREMENT PRIMARY KEY,
    cislo VARCHAR(20) NOT NULL UNIQUE,
    klient_nazev VARCHAR(200) NOT NULL,
    klient_ico VARCHAR(20) NULL,
    klient_dic VARCHAR(20) NULL,
    klient_adresa VARCHAR(300) NULL,
    klient_email VARCHAR(150) NULL,
    polozky MEDIUMTEXT NOT NULL,
    celkem DECIMAL(12,2) NOT NULL DEFAULT 0,
    mena VARCHAR(3) NOT NULL DEFAULT 'CZK',
    datum_vystaveni DATE NOT NULL,
    datum_splatnosti DATE NOT NULL,
    datum_uhrady DATE NULL,
    vs VARCHAR(20) NULL,
    stav ENUM('vystaveno','zaplaceno','storno') NOT NULL DEFAULT 'vystaveno',
    poznamka TEXT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_stav (stav), INDEX idx_datum (datum_vystaveni)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

// 🆕 Registr klientů — pro opakovanou (měsíční) fakturaci: klienta stačí vybrat a předvyplní se.
$pdo->exec("CREATE TABLE IF NOT EXISTS vendor_invoice_clients (
    id INT AUTO_INCREMENT PRIMARY KEY,
    nazev VARCHAR(200) NOT NULL,
    ico VARCHAR(20) NULL, dic VARCHAR(20) NULL,
    adresa VARCHAR(300) NULL, email VARCHAR(150) NULL,
    sazba_hod DECIMAL(10,2) NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

// 🆕 Per-klient / per-faktura jméno dodavatele (přebije globální business_name — např.
//   fakturuji-li konkrétnímu klientovi jako „Josef Mašek" místo brandu „APPEK").
foreach (['vendor_invoices', 'vendor_invoice_clients'] as $t) {
    try { $pdo->exec("ALTER TABLE $t ADD COLUMN dodavatel_nazev VARCHAR(200) NULL"); } catch (Throwable $e) {}
}

/** Firemní identita prodávajícího z vendor_settings. */
function faktury_biz(PDO $pdo): array {
    $b = [];
    try {
        foreach ($pdo->query("SELECT `key`,`value` FROM vendor_settings WHERE `key` LIKE 'business_%'")->fetchAll(PDO::FETCH_KEY_PAIR) as $k => $v) $b[$k] = $v;
    } catch (Throwable $e) {}
    return $b;
}

/** Další číslo faktury: RRRR + 3místné pořadí (2026001…). */
function faktury_next_cislo(PDO $pdo): string {
    $rok = date('Y');
    $last = $pdo->query("SELECT cislo FROM vendor_invoices WHERE cislo LIKE '" . $rok . "%' ORDER BY cislo DESC LIMIT 1")->fetchColumn();
    $seq = $last ? ((int) substr($last, 4) + 1) : 1;
    return $rok . str_pad((string) $seq, 3, '0', STR_PAD_LEFT);
}

function faktury_parse_polozky(array $d): array {
    $out = [];
    $pop = $d['p_popis'] ?? []; $mn = $d['p_mn'] ?? []; $jd = $d['p_jed'] ?? []; $ce = $d['p_cena'] ?? [];
    for ($i = 0; $i < count($pop); $i++) {
        $popis = trim((string) ($pop[$i] ?? ''));
        if ($popis === '') continue;
        $out[] = [
            'popis'    => mb_substr($popis, 0, 200),
            'mnozstvi' => round((float) str_replace(',', '.', (string) ($mn[$i] ?? 1)), 3),
            'jednotka' => mb_substr(trim((string) ($jd[$i] ?? 'ks')), 0, 20),
            'cena'     => round((float) str_replace(',', '.', (string) ($ce[$i] ?? 0)), 2),
        ];
    }
    return $out;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') vendor_csrf_check();
$flash_err = null;
$action = $_GET['action'] ?? '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'save') {
    $d = $_POST;
    $id = (int) ($d['id'] ?? 0);
    $klient = trim((string) ($d['klient_nazev'] ?? ''));
    $polozky = faktury_parse_polozky($d);
    $reDate = fn($s) => (is_string($s) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $s)) ? $s : null;
    $dv = $reDate($d['datum_vystaveni'] ?? null) ?: date('Y-m-d');
    $ds = $reDate($d['datum_splatnosti'] ?? null) ?: date('Y-m-d', strtotime('+14 days'));
    if ($klient === '') { $flash_err = 'Vyplň název klienta.'; }
    elseif (!$polozky) { $flash_err = 'Přidej aspoň jednu položku.'; }
    else {
        $celkem = 0.0;
        foreach ($polozky as $p) $celkem += $p['mnozstvi'] * $p['cena'];
        $celkem = round($celkem, 2);
        $pj = json_encode($polozky, JSON_UNESCAPED_UNICODE);
        if ($id) {
            $pdo->prepare("UPDATE vendor_invoices SET klient_nazev=:kn,klient_ico=:ki,klient_dic=:kd,klient_adresa=:ka,klient_email=:ke,
                           polozky=:po,celkem=:c,datum_vystaveni=:dv,datum_splatnosti=:ds,poznamka=:pz WHERE id=:id")
                ->execute(['kn'=>$klient,'ki'=>trim($d['klient_ico']??''),'kd'=>trim($d['klient_dic']??''),
                    'ka'=>trim($d['klient_adresa']??''),'ke'=>trim($d['klient_email']??''),'po'=>$pj,'c'=>$celkem,
                    'dv'=>$dv,'ds'=>$ds,'pz'=>trim($d['poznamka']??''),'id'=>$id]);
        } else {
            $cislo = faktury_next_cislo($pdo);
            // per-klient jméno dodavatele (přebije globální business_name na faktuře) — dle IČO/názvu
            $cIco0 = trim($d['klient_ico'] ?? '');
            $dq = $pdo->prepare($cIco0 !== '' ? "SELECT dodavatel_nazev FROM vendor_invoice_clients WHERE ico=:v LIMIT 1"
                                              : "SELECT dodavatel_nazev FROM vendor_invoice_clients WHERE nazev=:v LIMIT 1");
            $dq->execute(['v' => $cIco0 !== '' ? $cIco0 : $klient]);
            $dodNazev = $dq->fetchColumn() ?: null;
            $pdo->prepare("INSERT INTO vendor_invoices (cislo,klient_nazev,klient_ico,klient_dic,klient_adresa,klient_email,polozky,celkem,datum_vystaveni,datum_splatnosti,vs,poznamka,dodavatel_nazev)
                           VALUES (:cs,:kn,:ki,:kd,:ka,:ke,:po,:c,:dv,:ds,:vs,:pz,:dn)")
                ->execute(['cs'=>$cislo,'kn'=>$klient,'ki'=>trim($d['klient_ico']??''),'kd'=>trim($d['klient_dic']??''),
                    'ka'=>trim($d['klient_adresa']??''),'ke'=>trim($d['klient_email']??''),'po'=>$pj,'c'=>$celkem,
                    'dv'=>$dv,'ds'=>$ds,'vs'=>$cislo,'pz'=>trim($d['poznamka']??''),'dn'=>$dodNazev]);
            $id = (int) $pdo->lastInsertId();
        }
        // 🆕 upsert klienta do registru (aby šel příště jen vybrat) — dle IČO, jinak dle názvu
        $cIco = trim($d['klient_ico'] ?? '');
        $chk = $pdo->prepare($cIco !== '' ? "SELECT id FROM vendor_invoice_clients WHERE ico=:v LIMIT 1"
                                          : "SELECT id FROM vendor_invoice_clients WHERE nazev=:v LIMIT 1");
        $chk->execute(['v' => $cIco !== '' ? $cIco : $klient]);
        if (!$chk->fetchColumn()) {
            $pdo->prepare("INSERT INTO vendor_invoice_clients (nazev,ico,dic,adresa,email) VALUES (:n,:i,:d,:a,:e)")
                ->execute(['n'=>$klient,'i'=>$cIco ?: null,'d'=>trim($d['klient_dic']??'') ?: null,
                           'a'=>trim($d['klient_adresa']??'') ?: null,'e'=>trim($d['klient_email']??'') ?: null]);
        }
        header('Location: faktury.php?view=' . $id . '&saved=1'); exit;
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'mark_paid') {
    $id = (int) ($_POST['id'] ?? 0);
    $pdo->prepare("UPDATE vendor_invoices SET stav='zaplaceno', datum_uhrady=CURDATE() WHERE id=:id AND stav<>'storno'")->execute(['id'=>$id]);
    header('Location: faktury.php?ok=paid'); exit;
}
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'storno') {
    $id = (int) ($_POST['id'] ?? 0);
    $pdo->prepare("UPDATE vendor_invoices SET stav='storno' WHERE id=:id")->execute(['id'=>$id]);
    header('Location: faktury.php?ok=storno'); exit;
}
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'send') {
    $id = (int) ($_POST['id'] ?? 0);
    $f = $pdo->prepare("SELECT * FROM vendor_invoices WHERE id=:id"); $f->execute(['id'=>$id]);
    $inv = $f->fetch(PDO::FETCH_ASSOC);
    if ($inv && filter_var($inv['klient_email'], FILTER_VALIDATE_EMAIL)) {
        $biz = faktury_biz($pdo);
        $dodav = ($inv['dodavatel_nazev'] ?? '') !== '' ? $inv['dodavatel_nazev'] : ($biz['business_name'] ?? 'APPEK');
        $link = (isset($_SERVER['HTTPS']) ? 'https' : 'http') . '://' . $_SERVER['HTTP_HOST'] . dirname($_SERVER['REQUEST_URI']) . '/faktury.php?view=' . $id;
        $html = '<div style="font-family:-apple-system,Segoe UI,Arial,sans-serif;max-width:520px;margin:0 auto">'
            . '<h2 style="color:#BA7517">Faktura ' . htmlspecialchars($inv['cislo']) . '</h2>'
            . '<p>Dobrý den,<br>zasíláme fakturu č. <strong>' . htmlspecialchars($inv['cislo']) . '</strong> na částku <strong>'
            . number_format((float) $inv['celkem'], 2, ',', ' ') . ' ' . htmlspecialchars($inv['mena']) . '</strong>, splatnost '
            . date('d.m.Y', strtotime($inv['datum_splatnosti'])) . '.</p>'
            . '<p><a href="' . htmlspecialchars($link) . '" style="display:inline-block;padding:12px 24px;background:#BA7517;color:#fff;text-decoration:none;border-radius:10px;font-weight:600">→ Otevřít fakturu</a></p>'
            . '<p style="color:#888;font-size:13px">Účet: ' . htmlspecialchars($biz['business_bank_account'] ?? '') . ' · VS: ' . htmlspecialchars($inv['vs']) . '</p>'
            . '<hr style="border:none;border-top:1px solid #eee;margin:20px 0"><p style="color:#aaa;font-size:12px">' . htmlspecialchars($dodav) . ' · IČO ' . htmlspecialchars($biz['business_ico'] ?? '') . ' · Neplátce DPH</p></div>';
        $err = null;
        $ok = vendor_send_mail($inv['klient_email'], 'Faktura ' . $inv['cislo'] . ' — ' . $dodav, $html, null, null, $err);
        header('Location: faktury.php?view=' . $id . ($ok ? '&ok=sent' : '&err=mail')); exit;
    }
    header('Location: faktury.php?view=' . $id . '&err=noemail'); exit;
}

// ─────────────────────────── VIEW: tisknutelný doklad ───────────────────────────
if (isset($_GET['view'])) {
    $id = (int) $_GET['view'];
    $f = $pdo->prepare("SELECT * FROM vendor_invoices WHERE id=:id"); $f->execute(['id'=>$id]);
    $inv = $f->fetch(PDO::FETCH_ASSOC);
    if (!$inv) { http_response_code(404); exit('Faktura nenalezena'); }
    $biz = faktury_biz($pdo);
    $polozky = json_decode($inv['polozky'], true) ?: [];
    $esc = fn($s) => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
    $kc  = fn($n) => number_format((float) $n, 2, ',', ' ');
    $sidlo = trim(implode(', ', array_filter([
        $biz['business_street'] ?? '', trim(($biz['business_zip'] ?? '') . ' ' . ($biz['business_city'] ?? '')), $biz['business_country'] ?? ''
    ], fn($x) => trim($x) !== '')));
    // SPAYD (QR Platba) — jen když je IBAN a faktura je vystavená
    $spd = '';
    $iban = preg_replace('/\s+/', '', (string) ($biz['business_bank_iban'] ?? ''));
    if ($iban !== '' && $inv['stav'] === 'vystaveno') {
        $msg = 'Faktura ' . $inv['cislo'];
        $msg = preg_replace('/[*]/', '', iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $msg) ?: $msg);
        $spd = 'SPD*1.0*ACC:' . $iban . '*AM:' . number_format((float) $inv['celkem'], 2, '.', '') . '*CC:CZK'
             . ($inv['vs'] ? '*X-VS:' . preg_replace('/\D/', '', $inv['vs']) : '') . '*MSG:' . mb_substr($msg, 0, 60);
    }
    $stavBadge = ['vystaveno'=>['#B25E00','#FBEDDC','Vystaveno'],'zaplaceno'=>['#27500A','#EAF3DE','Zaplaceno'],'storno'=>['#A32D2D','#FCEBEB','Storno']][$inv['stav']];
    ?><!DOCTYPE html><html lang="cs"><head>
    <meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1">
    <meta name="robots" content="noindex,nofollow"><title>Faktura <?= $esc($inv['cislo']) ?></title>
    <style>
      *{box-sizing:border-box;margin:0;padding:0}
      body{font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Arial,sans-serif;color:#1d1d1f;background:#f2f2f4;padding:20px;line-height:1.5}
      .toolbar{max-width:800px;margin:0 auto 14px;display:flex;gap:8px;flex-wrap:wrap;justify-content:flex-end}
      .toolbar a,.toolbar button{font:inherit;font-size:13px;font-weight:600;padding:9px 14px;border-radius:9px;border:1px solid #d2d2d7;background:#fff;color:#1d1d1f;text-decoration:none;cursor:pointer}
      .toolbar .prim{background:#BA7517;color:#fff;border-color:#854F0B}
      .doc{max-width:800px;margin:0 auto;background:#fff;border-radius:14px;padding:44px;box-shadow:0 2px 16px rgba(0,0,0,.06)}
      .head{display:flex;justify-content:space-between;align-items:flex-start;gap:20px;flex-wrap:wrap;border-bottom:2px solid #1d1d1f;padding-bottom:18px;margin-bottom:24px}
      .head h1{font-size:26px}.head .cislo{color:#6e6e73;font-size:15px;margin-top:4px}
      .badge{display:inline-block;padding:5px 13px;border-radius:999px;font-size:12px;font-weight:800;text-transform:uppercase}
      .parties{display:grid;grid-template-columns:1fr 1fr;gap:24px;margin-bottom:24px}
      @media(max-width:560px){.parties{grid-template-columns:1fr}}
      .party h3{font-size:11px;text-transform:uppercase;letter-spacing:.5px;color:#8e8e93;margin-bottom:6px}
      .party .nm{font-weight:700;font-size:16px}.party div{font-size:14px;color:#3a3a3c}
      .meta{display:flex;gap:28px;flex-wrap:wrap;margin-bottom:20px;font-size:14px}
      .meta b{display:block;font-size:11px;text-transform:uppercase;color:#8e8e93;letter-spacing:.4px;font-weight:700}
      table{width:100%;border-collapse:collapse;margin-bottom:20px;font-size:14px}
      th{text-align:left;font-size:11px;text-transform:uppercase;color:#8e8e93;border-bottom:1.5px solid #e5e5e7;padding:8px 6px}
      td{padding:9px 6px;border-bottom:1px solid #f0f0f3}
      .num{text-align:right;white-space:nowrap}
      .total{display:flex;justify-content:flex-end;margin-bottom:8px}
      .total .box{min-width:240px}
      .total .row{display:flex;justify-content:space-between;padding:6px 0}
      .total .grand{font-size:22px;font-weight:800;border-top:2px solid #1d1d1f;padding-top:10px;margin-top:6px}
      .pay{display:flex;gap:24px;flex-wrap:wrap;align-items:center;background:#FAF7F1;border:1px solid #EBDFC9;border-radius:12px;padding:18px;margin-top:16px}
      .pay .info{flex:1;min-width:220px;font-size:14px}.pay .info .k{color:#8e8e93;font-size:12px}
      #qr{width:120px;height:120px}
      .note{margin-top:20px;font-size:12px;color:#8e8e93;line-height:1.6}
      @media print{body{background:#fff;padding:0}.toolbar{display:none}.doc{box-shadow:none;border-radius:0;max-width:none;padding:0}}
    </style></head><body>
    <div class="toolbar">
      <a href="faktury.php">← Seznam</a>
      <a href="faktury.php?edit=<?= (int)$inv['id'] ?>">✏️ Upravit</a>
      <button type="button" class="prim" onclick="window.print()">🖨️ Tisk / uložit PDF</button>
      <?php if ($inv['stav'] !== 'storno'): ?>
        <?php if ($inv['stav'] !== 'zaplaceno'): ?><form method="post" action="faktury.php?action=mark_paid" style="display:inline"><?php vendor_csrf_field(); ?><input type="hidden" name="id" value="<?= (int)$inv['id'] ?>"><button type="submit">✅ Zaplaceno</button></form><?php endif; ?>
        <?php if ($inv['klient_email']): ?><form method="post" action="faktury.php?action=send" style="display:inline"><?php vendor_csrf_field(); ?><input type="hidden" name="id" value="<?= (int)$inv['id'] ?>"><button type="submit">✉️ Odeslat</button></form><?php endif; ?>
      <?php endif; ?>
      <button class="prim" onclick="window.print()">🖨️ Tisk / PDF</button>
    </div>
    <div class="doc">
      <div class="head">
        <div><h1>Faktura</h1><div class="cislo">č. <?= $esc($inv['cislo']) ?></div></div>
        <span class="badge" style="color:<?= $stavBadge[0] ?>;background:<?= $stavBadge[1] ?>"><?= $stavBadge[2] ?></span>
      </div>
      <div class="parties">
        <div class="party">
          <h3>Dodavatel</h3>
          <div class="nm"><?= $esc(($inv['dodavatel_nazev'] ?? '') !== '' ? $inv['dodavatel_nazev'] : ($biz['business_name'] ?? 'APPEK')) ?></div>
          <?php if ($sidlo): ?><div><?= $esc($sidlo) ?></div><?php endif; ?>
          <?php if (!empty($biz['business_ico'])): ?><div>IČO: <?= $esc($biz['business_ico']) ?></div><?php endif; ?>
          <div>Neplátce DPH</div>
          <?php if (!empty($biz['business_email'])): ?><div><?= $esc($biz['business_email']) ?></div><?php endif; ?>
        </div>
        <div class="party">
          <h3>Odběratel</h3>
          <div class="nm"><?= $esc($inv['klient_nazev']) ?></div>
          <?php if ($inv['klient_adresa']): ?><div><?= $esc($inv['klient_adresa']) ?></div><?php endif; ?>
          <?php if ($inv['klient_ico']): ?><div>IČO: <?= $esc($inv['klient_ico']) ?></div><?php endif; ?>
          <?php if ($inv['klient_dic']): ?><div>DIČ: <?= $esc($inv['klient_dic']) ?></div><?php endif; ?>
        </div>
      </div>
      <div class="meta">
        <div><b>Vystaveno</b><?= date('d.m.Y', strtotime($inv['datum_vystaveni'])) ?></div>
        <div><b>Splatnost</b><?= date('d.m.Y', strtotime($inv['datum_splatnosti'])) ?></div>
        <div><b>Variabilní symbol</b><?= $esc($inv['vs']) ?></div>
        <?php if ($inv['datum_uhrady']): ?><div><b>Uhrazeno</b><?= date('d.m.Y', strtotime($inv['datum_uhrady'])) ?></div><?php endif; ?>
      </div>
      <table>
        <thead><tr><th>Popis</th><th class="num">Množství</th><th class="num">Cena/j.</th><th class="num">Celkem</th></tr></thead>
        <tbody>
        <?php foreach ($polozky as $p): ?>
          <tr>
            <td><?= $esc($p['popis']) ?></td>
            <td class="num"><?= rtrim(rtrim(number_format((float)$p['mnozstvi'],3,',',' '),'0'),',') ?> <?= $esc($p['jednotka']) ?></td>
            <td class="num"><?= $kc($p['cena']) ?></td>
            <td class="num"><?= $kc($p['mnozstvi'] * $p['cena']) ?></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
      <div class="total"><div class="box">
        <div class="row grand"><span>Celkem k úhradě</span><span><?= $kc($inv['celkem']) ?> <?= $esc($inv['mena']) ?></span></div>
      </div></div>
      <?php if ($spd): ?>
      <div class="pay">
        <div id="qr" data-spd="<?= $esc($spd) ?>"></div>
        <div class="info">
          <div style="font-weight:700;margin-bottom:6px">💸 QR Platba</div>
          <div><span class="k">Účet:</span> <?= $esc($biz['business_bank_account'] ?? '') ?></div>
          <?php if (!empty($biz['business_bank_iban'])): ?><div><span class="k">IBAN:</span> <?= $esc($biz['business_bank_iban']) ?></div><?php endif; ?>
          <div><span class="k">Částka:</span> <?= $kc($inv['celkem']) ?> Kč · <span class="k">VS:</span> <?= $esc($inv['vs']) ?></div>
        </div>
      </div>
      <?php elseif (!empty($biz['business_bank_account'])): ?>
      <div class="pay"><div class="info"><div style="font-weight:700;margin-bottom:6px">Platba převodem</div>
        <div><span class="k">Účet:</span> <?= $esc($biz['business_bank_account']) ?> · <span class="k">VS:</span> <?= $esc($inv['vs']) ?></div></div></div>
      <?php endif; ?>
      <?php if ($inv['poznamka']): ?><div class="note"><strong>Poznámka:</strong> <?= nl2br($esc($inv['poznamka'])) ?></div><?php endif; ?>
      <div class="note">Dodavatel není plátcem DPH. Fyzická osoba zapsaná v živnostenském rejstříku.</div>
    </div>
    <?php if ($spd): ?>
    <script src="../admin/lib/qrcode.min.js"></script>
    <script>
      (function(){ var el=document.getElementById('qr'); if(el&&window.QRCode){ try{ new QRCode(el,{text:el.getAttribute('data-spd'),width:120,height:120,correctLevel:QRCode.CorrectLevel.M}); }catch(e){} } })();
    </script>
    <?php endif; ?>
    </body></html>
    <?php
    exit;
}

// ─────────────────────────── FORM: nová / úprava ───────────────────────────
$editInv = null;
if (isset($_GET['edit'])) {
    $f = $pdo->prepare("SELECT * FROM vendor_invoices WHERE id=:id"); $f->execute(['id'=>(int)$_GET['edit']]);
    $editInv = $f->fetch(PDO::FETCH_ASSOC);
}
$isForm = isset($_GET['new']) || $editInv;
$clients = $isForm ? $pdo->query("SELECT * FROM vendor_invoice_clients ORDER BY nazev")->fetchAll(PDO::FETCH_ASSOC) : [];

$biz = faktury_biz($pdo);
$hasBank = !empty($biz['business_bank_account']) || !empty($biz['business_bank_iban']);
?><!DOCTYPE html>
<html lang="cs">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="robots" content="noindex,nofollow">
<title>🧾 Faktury — APPEK Vendor</title>
<link rel="stylesheet" href="style.css?v=1.3">
<style>
  .inv-table{width:100%;border-collapse:collapse;font-size:14px}
  .inv-table th,.inv-table td{padding:10px 14px;text-align:left;border-bottom:1px solid #f0f0f3}
  .inv-table th{background:#fafafa;font-size:11px;text-transform:uppercase;color:#6e6e73;letter-spacing:.4px}
  .inv-table tr:hover td{background:#fafafa}
  .st{display:inline-block;padding:3px 9px;border-radius:999px;font-size:11px;font-weight:700}
  .form-grid2{display:grid;grid-template-columns:1fr 1fr;gap:12px}
  @media(max-width:600px){.form-grid2{grid-template-columns:1fr}}
  .fld label{display:block;font-size:12px;font-weight:600;color:#3a3a3c;margin-bottom:4px}
  .fld input,.fld textarea{width:100%;padding:9px 12px;border:1px solid #d2d2d7;border-radius:8px;font:inherit;font-size:14px}
  .pol-row{display:grid;grid-template-columns:1fr 78px 58px 104px 104px 34px;gap:8px;margin-bottom:8px;align-items:center}
  @media(max-width:600px){.pol-row{grid-template-columns:1fr 1fr;gap:6px}}
  .pol-row .rm{background:#fde3e3;border:none;border-radius:8px;height:38px;cursor:pointer;font-size:15px}
  .pol-row .pol-sum{text-align:right;font-weight:700;font-size:14px;color:#1d1d1f;padding-right:2px;white-space:nowrap}
  @media(max-width:600px){.pol-row .pol-sum{grid-column:1/-1;text-align:left;color:#6e6e73;font-size:12.5px}}
  .pol-head{font-size:11px;text-transform:uppercase;color:#8e8e93;letter-spacing:.3px}
</style>
</head>
<body>
<?php vendor_render_topbar($user, $currentPage); ?>
<?php vendor_render_back(); ?>

<main class="page-master">

<?php if (!$hasBank): ?>
  <div style="background:#fff3cd;color:#856404;padding:12px 16px;border-radius:10px;margin-bottom:16px;font-size:13px">
    ⚠️ Nemáš vyplněný bankovní účet/IBAN v <a href="business-info.php" style="color:inherit;font-weight:700">🏢 Firma</a> — QR Platba se na faktuře nezobrazí. Doplň účet a IBAN.
  </div>
<?php endif; ?>

<?php if (!empty($flash_err)): ?><div style="background:#f8d7da;color:#721c24;padding:12px 16px;border-radius:10px;margin-bottom:14px">❌ <?= htmlspecialchars($flash_err) ?></div><?php endif; ?>
<?php if (($_GET['ok'] ?? '') === 'paid'): ?><div style="background:#e6f4ea;color:#1e7d34;padding:12px 16px;border-radius:10px;margin-bottom:14px">✅ Označeno jako zaplaceno.</div><?php endif; ?>
<?php if (($_GET['ok'] ?? '') === 'storno'): ?><div style="background:#fdecea;color:#8a1f1f;padding:12px 16px;border-radius:10px;margin-bottom:14px">Faktura stornována.</div><?php endif; ?>

<?php if ($isForm): ?>
  <?php $p0 = $editInv ? (json_decode($editInv['polozky'], true) ?: []) : [['popis'=>'','mnozstvi'=>1,'jednotka'=>'hod','cena'=>'']]; ?>
  <div class="page-header-master"><h1><?= $editInv ? '✏️ Faktura ' . htmlspecialchars($editInv['cislo']) : '🧾 Nová faktura' ?></h1></div>
  <form method="post" action="faktury.php?action=save">
    <?php vendor_csrf_field(); ?>
    <?php if ($editInv): ?><input type="hidden" name="id" value="<?= (int)$editInv['id'] ?>"><?php endif; ?>
    <div class="panel-master" style="margin-bottom:16px">
      <h2 style="font-size:14px;margin-bottom:12px">Odběratel</h2>
      <?php if ($clients): ?>
      <div class="fld" style="margin-bottom:12px">
        <label>📇 Předvyplnit uloženého klienta</label>
        <select id="client-picker" onchange="fPrefillClient(this)" style="width:100%;padding:9px 12px;border:1px solid #d2d2d7;border-radius:8px;font:inherit;font-size:14px">
          <option value="">— nový klient / ručně —</option>
          <?php foreach ($clients as $c): ?>
            <option value="<?= (int)$c['id'] ?>"
              data-nazev="<?= htmlspecialchars($c['nazev']) ?>" data-ico="<?= htmlspecialchars($c['ico']??'') ?>"
              data-dic="<?= htmlspecialchars($c['dic']??'') ?>" data-adresa="<?= htmlspecialchars($c['adresa']??'') ?>"
              data-email="<?= htmlspecialchars($c['email']??'') ?>" data-sazba="<?= htmlspecialchars((string)($c['sazba_hod']??'')) ?>">
              <?= htmlspecialchars($c['nazev']) ?><?= $c['ico'] ? ' · IČO '.htmlspecialchars($c['ico']) : '' ?>
            </option>
          <?php endforeach; ?>
        </select>
      </div>
      <?php endif; ?>
      <div class="form-grid2">
        <div class="fld"><label>Název / jméno *</label><input name="klient_nazev" required value="<?= htmlspecialchars($editInv['klient_nazev'] ?? '') ?>" placeholder="Firma s.r.o. / Jan Novák"></div>
        <div class="fld"><label>E-mail (pro odeslání)</label><input type="email" name="klient_email" value="<?= htmlspecialchars($editInv['klient_email'] ?? '') ?>" placeholder="klient@firma.cz"></div>
        <div class="fld"><label>IČO</label><input name="klient_ico" value="<?= htmlspecialchars($editInv['klient_ico'] ?? '') ?>"></div>
        <div class="fld"><label>DIČ</label><input name="klient_dic" value="<?= htmlspecialchars($editInv['klient_dic'] ?? '') ?>"></div>
        <div class="fld" style="grid-column:1/-1"><label>Adresa</label><input name="klient_adresa" value="<?= htmlspecialchars($editInv['klient_adresa'] ?? '') ?>" placeholder="Ulice 1, 100 00 Praha"></div>
      </div>
    </div>
    <div class="panel-master" style="margin-bottom:16px">
      <h2 style="font-size:14px;margin-bottom:12px">Položky (IT služby)</h2>
      <div class="pol-row pol-head"><div>Popis</div><div>Množství</div><div>Jedn.</div><div>Cena/j. (Kč)</div><div style="text-align:right">Celkem</div><div></div></div>
      <div id="pol-list">
        <?php foreach ($p0 as $p): ?>
        <div class="pol-row">
          <input name="p_popis[]" value="<?= htmlspecialchars($p['popis'] ?? '') ?>" placeholder="např. Konzultace / vývoj / nasazení">
          <input name="p_mn[]" value="<?= htmlspecialchars((string)($p['mnozstvi'] ?? 1)) ?>" inputmode="decimal">
          <input name="p_jed[]" value="<?= htmlspecialchars($p['jednotka'] ?? 'hod') ?>">
          <input name="p_cena[]" value="<?= htmlspecialchars((string)($p['cena'] ?? '')) ?>" inputmode="decimal" placeholder="0">
          <div class="pol-sum">0,00</div>
          <button type="button" class="rm" onclick="this.closest('.pol-row').remove();fRecalc()">🗑️</button>
        </div>
        <?php endforeach; ?>
      </div>
      <button type="button" class="btn-master secondary" onclick="fAddRow()">＋ Přidat položku</button>
      <div style="display:flex;justify-content:flex-end;align-items:baseline;gap:12px;margin-top:14px;padding-top:12px;border-top:1px solid #eee">
        <span style="font-size:13px;color:#6e6e73">Celkem k úhradě</span>
        <span style="font-size:22px;font-weight:800"><span id="f-celkem">0,00</span> Kč</span>
      </div>
      <div style="text-align:right;font-size:11.5px;color:#8e8e93;margin-top:3px">Neplátce DPH — částka je konečná</div>
    </div>
    <div class="panel-master" style="margin-bottom:16px">
      <div class="form-grid2">
        <div class="fld"><label>Datum vystavení</label><input type="date" name="datum_vystaveni" value="<?= htmlspecialchars($editInv['datum_vystaveni'] ?? date('Y-m-d')) ?>"></div>
        <div class="fld"><label>Datum splatnosti</label><input type="date" name="datum_splatnosti" value="<?= htmlspecialchars($editInv['datum_splatnosti'] ?? date('Y-m-d', strtotime('+14 days'))) ?>"></div>
        <div class="fld" style="grid-column:1/-1"><label>Poznámka</label><textarea name="poznamka" rows="2"><?= htmlspecialchars($editInv['poznamka'] ?? '') ?></textarea></div>
      </div>
    </div>
    <div style="display:flex;gap:8px;justify-content:flex-end">
      <a href="faktury.php" class="btn-master secondary">Zrušit</a>
      <button type="submit" class="btn-master">💾 Uložit a zobrazit</button>
    </div>
  </form>
  <script>
    function fMoney(n){return (isFinite(n)?n:0).toLocaleString('cs-CZ',{minimumFractionDigits:2,maximumFractionDigits:2});}
    function fNum(v){return parseFloat(String(v==null?'':v).replace(/\s/g,'').replace(',','.'))||0;}
    function fAddRow(){
      var w=document.getElementById('pol-list'); var d=document.createElement('div'); d.className='pol-row';
      d.innerHTML='<input name="p_popis[]" placeholder="Popis"><input name="p_mn[]" value="1" inputmode="decimal"><input name="p_jed[]" value="hod"><input name="p_cena[]" inputmode="decimal" placeholder="0"><div class="pol-sum">0,00</div><button type="button" class="rm" onclick="this.closest(\'.pol-row\').remove();fRecalc()">🗑️</button>';
      w.appendChild(d); fRecalc(); var fi=d.querySelector('input'); if(fi) fi.focus();
    }
    function fRecalc(){
      var t=0;
      document.querySelectorAll('#pol-list .pol-row').forEach(function(r){
        var s=fNum(r.querySelector('[name="p_mn[]"]').value)*fNum(r.querySelector('[name="p_cena[]"]').value);
        t+=s; var sc=r.querySelector('.pol-sum'); if(sc) sc.textContent=fMoney(s);
      });
      document.getElementById('f-celkem').textContent=fMoney(t);
    }
    function fPrefillClient(sel){
      var o=sel.options[sel.selectedIndex]; if(!o||!o.value) return;
      function set(n,v){var el=document.querySelector('[name="'+n+'"]'); if(el) el.value=v||'';}
      set('klient_nazev',o.dataset.nazev); set('klient_ico',o.dataset.ico);
      set('klient_dic',o.dataset.dic); set('klient_adresa',o.dataset.adresa); set('klient_email',o.dataset.email);
      var sazba=fNum(o.dataset.sazba);
      if(sazba>0){
        var r=document.querySelector('#pol-list .pol-row');
        if(r){ var ce=r.querySelector('[name="p_cena[]"]'); if(ce && !ce.value){ ce.value=o.dataset.sazba;
               var po=r.querySelector('[name="p_popis[]"]'); if(po && !po.value) po.value='IT služby'; fRecalc(); } }
      }
    }
    // Event delegation — jeden listener chytí i dynamicky přidané řádky (spolehlivější než per-input)
    document.getElementById('pol-list').addEventListener('input', fRecalc);
    fRecalc();
  </script>

<?php else: ?>
  <div class="page-header-master">
    <h1>🧾 Faktury</h1>
    <a href="faktury.php?new=1" class="btn-master">＋ Nová faktura</a>
  </div>
  <?php
    $rows = $pdo->query("SELECT * FROM vendor_invoices ORDER BY id DESC LIMIT 300")->fetchAll(PDO::FETCH_ASSOC);
    $sumOpen = 0.0; foreach ($rows as $r) if ($r['stav']==='vystaveno') $sumOpen += (float)$r['celkem'];
  ?>
  <div class="stats-row" style="display:grid;grid-template-columns:repeat(auto-fit,minmax(160px,1fr));gap:12px;margin-bottom:18px">
    <div class="stat-card" style="background:#fff;border:1px solid #e5e5e7;border-radius:10px;padding:14px 18px"><div style="font-size:11px;color:#6e6e73;text-transform:uppercase">Faktur celkem</div><div style="font-size:24px;font-weight:800"><?= count($rows) ?></div></div>
    <div class="stat-card" style="background:#fff;border:1px solid #e5e5e7;border-radius:10px;padding:14px 18px"><div style="font-size:11px;color:#6e6e73;text-transform:uppercase">Nezaplaceno</div><div style="font-size:24px;font-weight:800;color:#B25E00"><?= number_format($sumOpen,2,',',' ') ?> Kč</div></div>
  </div>
  <?php if (!$rows): ?>
    <div class="panel-master" style="text-align:center;padding:40px;color:#6e6e73">
      Zatím žádné faktury. <a href="faktury.php?new=1" style="color:#BA7517;font-weight:700">Vystav první →</a>
    </div>
  <?php else: ?>
    <div class="panel-master" style="padding:0;overflow:hidden">
      <table class="inv-table">
        <thead><tr><th>Číslo</th><th>Odběratel</th><th>Vystaveno</th><th>Splatnost</th><th style="text-align:right">Celkem</th><th>Stav</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($rows as $r):
          $st = ['vystaveno'=>['#B25E00','#FBEDDC','Vystaveno'],'zaplaceno'=>['#27500A','#EAF3DE','Zaplaceno'],'storno'=>['#A32D2D','#FCEBEB','Storno']][$r['stav']];
          $overdue = $r['stav']==='vystaveno' && $r['datum_splatnosti'] < date('Y-m-d'); ?>
          <tr>
            <td><strong><?= htmlspecialchars($r['cislo']) ?></strong></td>
            <td><?= htmlspecialchars($r['klient_nazev']) ?></td>
            <td><?= date('d.m.Y', strtotime($r['datum_vystaveni'])) ?></td>
            <td style="<?= $overdue ? 'color:#A32D2D;font-weight:700' : '' ?>"><?= date('d.m.Y', strtotime($r['datum_splatnosti'])) ?><?= $overdue ? ' ⚠️' : '' ?></td>
            <td style="text-align:right;font-weight:700"><?= number_format((float)$r['celkem'],2,',',' ') ?> Kč</td>
            <td><span class="st" style="color:<?= $st[0] ?>;background:<?= $st[1] ?>"><?= $st[2] ?></span></td>
            <td style="text-align:right;white-space:nowrap"><a href="faktury.php?view=<?= (int)$r['id'] ?>" class="btn-master secondary" style="padding:6px 12px;font-size:12px">Otevřít</a></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php endif; ?>
<?php endif; ?>

</main>
<?php vendor_render_footer(); ?>
</body>
</html>
