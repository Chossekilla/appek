<?php
/**
 * Rozeslání PDF nabídky e-mailem skupině zákazníků.
 *
 * POST { skupina_id, odberatel_ids: [], vyrobek_ids: [], poznamky: {id:text}, predmet, zprava, nazev }
 *   → každému odběrateli pošle HTML email s tabulkou výrobků a cenami pro jeho cenovou skupinu
 *   → odkaz na PDF nabídku (link na katalog_pdf.php?skupina=X&vyrobky=Y) pro stažení
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/_admin_auth.php';
cors_headers();
require_admin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') json_error('POST only', 405);

$pdo = db();

// Zjisti, které volitelné sloupce (obsah/obsah_jednotka) v `vyrobky` opravdu existují.
// Některé starší instalace je nemají a SELECT by spadnul na "Unknown column".
$has_obsah = false;
$has_obsah_jed = false;
try {
    $cols = $pdo->query("
        SELECT COLUMN_NAME FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'vyrobky'
    ")->fetchAll(PDO::FETCH_COLUMN);
    $has_obsah     = in_array('obsah', $cols, true);
    $has_obsah_jed = in_array('obsah_jednotka', $cols, true);
} catch (Throwable $e) {
    error_log('admin_katalog_email columns probe: ' . $e->getMessage());
}

$d = json_input();

// === Master try: jakákoli neošetřená chyba se vrátí jako JSON s detailem ===
try {

$skupina_id = (int) ($d['skupina_id'] ?? 0);
$odberatel_ids = array_filter(array_map('intval', (array) ($d['odberatel_ids'] ?? [])));
$extra_emaily = array_values(array_filter(array_map('trim', (array) ($d['extra_emaily'] ?? [])), function ($e) {
    return filter_var($e, FILTER_VALIDATE_EMAIL) !== false;
}));
$vyrobek_ids = array_filter(array_map('intval', (array) ($d['vyrobek_ids'] ?? [])));
$poznamky = is_array($d['poznamky'] ?? null) ? $d['poznamky'] : [];
$predmet = trim((string) ($d['predmet'] ?? 'Nabídka výrobků'));
$zprava = trim((string) ($d['zprava'] ?? ''));
$nazev_nabidky = trim((string) ($d['nazev'] ?? ''));

// skupina_id == 0 = „bez skupiny" — pošle se jen na extra_emaily se základními cenami
if (empty($odberatel_ids) && empty($extra_emaily)) json_error('Žádní odběratelé ani extra e-maily');
if ($skupina_id === 0 && empty($extra_emaily)) json_error('„Bez skupiny" — musíš zadat alespoň jeden extra e-mail');
if (empty($vyrobek_ids)) json_error('Žádné výrobky');

// =============================================================
// Načti skupinu (volitelně) a její slevy
// =============================================================
$skupina = null;
$slevy = [];
if ($skupina_id > 0) {
    $sk_stmt = $pdo->prepare("SELECT * FROM cenove_skupiny WHERE id = :id");
    $sk_stmt->execute(['id' => $skupina_id]);
    $skupina = $sk_stmt->fetch();
    if (!$skupina) json_error('Skupina nenalezena', 404);

    try {
        $sl_stmt = $pdo->prepare("SELECT * FROM cenove_skupiny_slevy WHERE skupina_id = :id");
        $sl_stmt->execute(['id' => $skupina_id]);
        $slevy = $sl_stmt->fetchAll();
    } catch (Throwable $e) { /* tabulka může chybět */ }
} else {
    // Bez skupiny — žádné slevy, základní ceny
    $skupina = ['nazev' => 'bez skupiny', 'id' => 0];
}

// =============================================================
// Načti odběratele s e-maily + sjednoť s extra e-maily
// =============================================================
$odberatele = [];
if (!empty($odberatel_ids)) {
    $place = implode(',', array_fill(0, count($odberatel_ids), '?'));
    $o_stmt = $pdo->prepare("
        SELECT id, nazev, email
        FROM odberatele
        WHERE id IN ($place)
    ");
    $o_stmt->execute($odberatel_ids);
    $odberatele = $o_stmt->fetchAll();
}
// Přidej extra e-maily jako virtuální „odběratele"
foreach ($extra_emaily as $em) {
    $odberatele[] = [
        'id' => null,
        'nazev' => $em, // jako oslovení použijeme samotnou adresu
        'email' => $em,
        '_extra' => true,
    ];
}

// =============================================================
// Načti výrobky — `obsah` a `obsah_jednotka` jsou volitelné (záleží na verzi schématu)
// =============================================================
$vp = implode(',', array_fill(0, count($vyrobek_ids), '?'));
$col_obsah     = $has_obsah     ? 'v.obsah'          : 'NULL AS obsah';
$col_obsah_jed = $has_obsah_jed ? 'v.obsah_jednotka' : 'NULL AS obsah_jednotka';
$v_stmt = $pdo->prepare("
    SELECT v.id, v.cislo, v.nazev, v.cena_bez_dph, v.sazba_dph_id, v.kategorie_id,
           $col_obsah, $col_obsah_jed, v.hmotnost_g,
           k.nazev AS kategorie_nazev, k.poradi AS kategorie_poradi,
           j.kod AS jednotka_kod,
           sd.sazba AS sazba_dph
    FROM vyrobky v
    LEFT JOIN kategorie_vyrobku k ON v.kategorie_id = k.id
    LEFT JOIN jednotky j ON v.jednotka_id = j.id
    LEFT JOIN sazby_dph sd ON v.sazba_dph_id = sd.id
    WHERE v.id IN ($vp)
    ORDER BY k.poradi, v.poradi, v.nazev
");
$v_stmt->execute($vyrobek_ids);
$vyrobky = $v_stmt->fetchAll();

// =============================================================
// Spočítej cenu pro každý výrobek dle slev skupiny
// =============================================================
function cena_pro_skupinu(array $v, array $slevy): array {
    $base = (float) $v['cena_bez_dph'];
    $sazba = (float) ($v['sazba_dph'] ?? 12);
    // Hledej slevu — nejdřív specifická (vyrobek_id), pak kategorie, pak default
    $sleva_pct = 0;
    $pevna = null;
    foreach ($slevy as $s) {
        if (!empty($s['vyrobek_id']) && (int) $s['vyrobek_id'] === (int) $v['id']) {
            if (!empty($s['pevna_cena'])) $pevna = (float) $s['pevna_cena'];
            elseif (!empty($s['sleva_pct'])) $sleva_pct = (float) $s['sleva_pct'];
            break;
        }
    }
    if ($pevna === null && !$sleva_pct) {
        foreach ($slevy as $s) {
            if (!empty($s['kategorie_id']) && (int) $s['kategorie_id'] === (int) $v['kategorie_id']) {
                if (!empty($s['pevna_cena'])) { $pevna = (float) $s['pevna_cena']; break; }
                if (!empty($s['sleva_pct'])) { $sleva_pct = (float) $s['sleva_pct']; break; }
            }
        }
    }
    $cena_bez = $pevna !== null ? $pevna : $base * (1 - $sleva_pct / 100);
    $cena_s = $cena_bez * (1 + $sazba / 100);
    return [
        'cena_bez_dph' => $cena_bez,
        'cena_s_dph' => $cena_s,
        'sazba_dph' => $sazba,
        'puvodni' => $base,
        'sleva_pct' => $sleva_pct,
        'pevna' => $pevna !== null,
    ];
}

// =============================================================
// Sestaviení HTML emailu — sdílené tělo
// =============================================================
$firma_nazev = nastaveni_get($pdo, 'firma_nazev', 'Provoz');
$firma_ulice = nastaveni_get($pdo, 'firma_ulice', '');
$firma_mesto = nastaveni_get($pdo, 'firma_mesto', '');
$firma_tel = nastaveni_get($pdo, 'firma_telefon', '');
$firma_email = nastaveni_get($pdo, 'firma_email', '');

$base_url = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' ? 'https' : 'http')
          . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost');
$pdf_url_params = http_build_query([
    'vyrobky' => implode(',', $vyrobek_ids),
    'skupina' => $skupina_id,
    'nazev' => $nazev_nabidky,
]);
$pdf_url = $base_url . '/api/katalog_pdf.php?' . $pdf_url_params;

// Pozn.: fmt_kc() už je definovaná globálně v config.php (zdvojení = Fatal error).

// Tabulka výrobků
ob_start();
?>
<table cellpadding="8" cellspacing="0" border="1" style="border-collapse:collapse;width:100%;font-family:Helvetica,Arial,sans-serif;font-size:13px;border-color:#ddd">
  <thead>
    <tr style="background:#FAEEDA;color:#854F0B">
      <th align="left">Č.</th>
      <th align="left">Výrobek</th>
      <th align="right">Cena bez DPH</th>
      <th align="right">DPH %</th>
      <th align="right">Cena s DPH</th>
    </tr>
  </thead>
  <tbody>
    <?php foreach ($vyrobky as $v):
      $c = cena_pro_skupinu($v, $slevy);
      $pozn = $poznamky[$v['id']] ?? '';
    ?>
      <tr>
        <td style="color:#888;font-family:monospace"><?= esc($v['cislo'] ?? '') ?></td>
        <td>
          <strong><?= esc($v['nazev']) ?></strong>
          <?php if ($v['obsah'] && $v['obsah_jednotka']): ?>
            <span style="color:#888;font-size:11px"> · <?= esc($v['obsah']) ?> <?= esc($v['obsah_jednotka']) ?></span>
          <?php elseif ($v['hmotnost_g']): ?>
            <span style="color:#888;font-size:11px"> · <?= esc($v['hmotnost_g']) ?> g</span>
          <?php endif; ?>
          <?php if ($pozn): ?>
            <div style="font-size:11px;color:#92400e;font-style:italic;margin-top:2px"><?= esc($pozn) ?></div>
          <?php endif; ?>
        </td>
        <td align="right" style="font-variant-numeric:tabular-nums">
          <?php if ($c['sleva_pct'] > 0 || $c['pevna']): ?>
            <span style="color:#888;text-decoration:line-through;font-size:11px"><?= fmt_kc($c['puvodni']) ?></span><br>
          <?php endif; ?>
          <strong><?= fmt_kc($c['cena_bez_dph']) ?></strong>
        </td>
        <td align="right"><?= number_format($c['sazba_dph'], 0) ?> %</td>
        <td align="right" style="font-weight:700;color:#854F0B;font-variant-numeric:tabular-nums"><?= fmt_kc($c['cena_s_dph']) ?></td>
      </tr>
    <?php endforeach; ?>
  </tbody>
</table>
<?php
$tabulka_html = ob_get_clean();

// =============================================================
// Rozeslání pro každého odběratele individuálně
// (oslovení v hlavičce + společná tabulka cen — všechny stejné, protože ve stejné skupině)
// =============================================================
function poslat_email_html(string $komu, string $jmeno, string $predmet, string $html_telo, string $plain_telo): bool {
    if (filter_var($komu, FILTER_VALIDATE_EMAIL) === false) return false;
    $pdo = db();
    $from = nastaveni_get($pdo, 'firma_email', '') ?: 'noreply@' . ($_SERVER['HTTP_HOST'] ?? 'localhost');
    $fromName = nastaveni_get($pdo, 'firma_nazev', '') ?: 'Provoz';

    $boundary = 'mlt_' . md5(uniqid('', true));
    $headers = [];
    $headers[] = 'MIME-Version: 1.0';
    $headers[] = 'Content-Type: multipart/alternative; boundary="' . $boundary . '"';
    $headers[] = 'From: ' . mb_encode_mimeheader($fromName, 'UTF-8') . ' <' . $from . '>';
    $headers[] = 'Reply-To: ' . $from;
    $headers[] = 'X-Mailer: PHP/' . phpversion();

    $body = "--$boundary\r\n";
    $body .= "Content-Type: text/plain; charset=UTF-8\r\n";
    $body .= "Content-Transfer-Encoding: 8bit\r\n\r\n";
    $body .= $plain_telo . "\r\n\r\n";
    $body .= "--$boundary\r\n";
    $body .= "Content-Type: text/html; charset=UTF-8\r\n";
    $body .= "Content-Transfer-Encoding: 8bit\r\n\r\n";
    $body .= $html_telo . "\r\n\r\n";
    $body .= "--$boundary--\r\n";

    $predmet_enc = '=?UTF-8?B?' . base64_encode($predmet) . '?=';
    return @mail($komu, $predmet_enc, $body, implode("\r\n", $headers));
}

$odeslano = 0;
$chyby = 0;
$detail = [];

$kontakt_block = "";
if ($firma_tel) $kontakt_block .= "Tel: $firma_tel\n";
if ($firma_email) $kontakt_block .= "E-mail: $firma_email\n";

foreach ($odberatele as $o) {
    $email = trim($o['email'] ?? '');
    if (!$email || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $chyby++;
        $detail[] = ['odberatel' => $o['nazev'], 'stav' => 'chyba', 'duvod' => 'neplatný/chybí e-mail'];
        continue;
    }

    $oslov = $o['nazev'];
    $html = '<!DOCTYPE html><html><body style="font-family:Helvetica,Arial,sans-serif;color:#2C2C2A;background:#f5f5f0;padding:20px;margin:0">'
        . '<div style="max-width:680px;margin:0 auto;background:#fff;padding:24px;border-radius:8px">'
        . '<div style="border-bottom:2px solid #BA7517;padding-bottom:12px;margin-bottom:16px">'
        . '<h1 style="margin:0;font-size:22px;color:#BA7517">' . esc($predmet) . '</h1>'
        . '<p style="margin:6px 0 0;color:#666;font-size:13px">' . esc($firma_nazev) . '</p>'
        . '</div>'
        . '<p style="font-size:14px;margin-bottom:6px"><strong>Pro:</strong> ' . esc($oslov) . '</p>'
        . '<div style="white-space:pre-line;font-size:14px;line-height:1.5;margin-bottom:18px">' . esc($zprava) . '</div>'
        . '<h3 style="font-size:15px;color:#854F0B;margin-bottom:8px">📋 Nabídka výrobků</h3>'
        . $tabulka_html
        . '<div style="margin-top:18px;padding:12px 16px;background:#FFFAF1;border:1px solid #E8C988;border-radius:6px;font-size:13px">'
        . '<strong>📄 Stáhnout PDF nabídku:</strong> <a href="' . esc($pdf_url) . '" style="color:#BA7517">' . esc($pdf_url) . '</a>'
        . '</div>'
        . '<hr style="border:0;border-top:1px solid #ddd;margin:20px 0">'
        . '<div style="font-size:12px;color:#666;line-height:1.5">'
        . '<strong>' . esc($firma_nazev) . '</strong><br>'
        . ($firma_ulice ? esc($firma_ulice) . '<br>' : '')
        . ($firma_mesto ? esc($firma_mesto) . '<br>' : '')
        . ($firma_tel ? 'Tel: ' . esc($firma_tel) . '<br>' : '')
        . ($firma_email ? 'E-mail: <a href="mailto:' . esc($firma_email) . '">' . esc($firma_email) . '</a>' : '')
        . '</div>'
        . '</div></body></html>';

    // Plain-text fallback
    $plain = "Pro: $oslov\n\n"
        . $zprava . "\n\n"
        . "------------------------------------\n"
        . "📋 Nabídka výrobků (" . count($vyrobky) . " položek)\n"
        . "------------------------------------\n";
    foreach ($vyrobky as $v) {
        $c = cena_pro_skupinu($v, $slevy);
        $plain .= sprintf("%-40s  %s s DPH (%s bez DPH, DPH %d%%)\n",
            mb_substr($v['nazev'], 0, 40),
            number_format($c['cena_s_dph'], 2, ',', ' ') . ' Kč',
            number_format($c['cena_bez_dph'], 2, ',', ' ') . ' Kč',
            (int) $c['sazba_dph']
        );
    }
    $plain .= "\nPDF nabídka: $pdf_url\n\n";
    $plain .= "$firma_nazev\n";
    $plain .= $kontakt_block;

    if (poslat_email_html($email, $oslov, $predmet, $html, $plain)) {
        $odeslano++;
        $detail[] = ['odberatel' => $o['nazev'], 'email' => $email, 'stav' => 'odesláno'];
    } else {
        $chyby++;
        $detail[] = ['odberatel' => $o['nazev'], 'email' => $email, 'stav' => 'chyba', 'duvod' => 'mail() neúspěch'];
    }
}

json_response([
    'ok' => true,
    'odeslano' => $odeslano,
    'chyby' => $chyby,
    'celkem' => count($odberatele),
    'detail' => $detail,
]);

} catch (Throwable $e) {
    // Master catch — vrátí konkrétní chybu místo prázdného 500
    error_log('admin_katalog_email FATAL: ' . $e->getMessage() . "\n" . $e->getTraceAsString());
    while (ob_get_level() > 0) { @ob_end_clean(); }
    json_error('Chyba serveru: ' . $e->getMessage() . ' (' . basename($e->getFile()) . ':' . $e->getLine() . ')', 500);
}
