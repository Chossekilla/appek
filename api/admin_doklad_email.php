<?php
/**
 * Odeslání faktury / dodacího listu / objednávky e-mailem s PDF přílohou.
 *
 * POST { typ: 'fa'|'dl'|'obj', id: N, emails: ['x@y.cz', …], predmet?, zprava? }
 *   → vygeneruje PDF dokladu (přes existující doklady), připojí jako přílohu
 *   → odešle emailem na všechny adresy
 *   → vrátí { ok, odeslano, chyby, detail: [...] }
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/_admin_auth.php';
require_once __DIR__ . '/_email_token.php';
require_once __DIR__ . '/_i18n.php';   // 🌍 v3.0.640 — e-mail v jazyce odběratele (= jazyk dokladu)
cors_headers();
require_admin();
// 🐛 v3.0.644 — uvolni zámek session hned po ověření (dál se do ní nezapisuje). Dřív ho skript držel
//   po celou dobu odesílání → ostatní požadavky stejného admina (jiné záložky, polling) čekaly.
if (session_status() === PHP_SESSION_ACTIVE) session_write_close();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') json_error('POST only', 405);

$pdo = db();
$d = json_input();

$typ = strtolower(trim((string) ($d['typ'] ?? '')));
$id  = (int) ($d['id'] ?? 0);
$emails = array_values(array_filter(array_map('trim', (array) ($d['emails'] ?? [])), function ($e) {
    return filter_var($e, FILTER_VALIDATE_EMAIL) !== false;
}));
$predmet = trim((string) ($d['predmet'] ?? ''));
$zprava  = trim((string) ($d['zprava'] ?? ''));

if (!in_array($typ, ['fa', 'dl', 'obj'], true)) json_error('Neplatný typ (fa/dl/obj)');
if (!$id) json_error('Chybí ID');
if (empty($emails)) json_error('Žádné e-mailové adresy');

// === Konfigurace odesílatele ===
function nastaveni_get_cached(PDO $pdo, string $klic, string $default = ''): string {
    static $cache = null;
    if ($cache === null) {
        $cache = [];
        try {
            $stmt = $pdo->query("SELECT klic, hodnota FROM nastaveni");
            while ($r = $stmt->fetch(PDO::FETCH_ASSOC)) $cache[$r['klic']] = $r['hodnota'];
        } catch (Throwable $e) { /* ignore */ }
    }
    return (string) ($cache[$klic] ?? $default);
}

$firma_email = nastaveni_get_cached($pdo, 'firma_email', '') ?: ('noreply@' . ($_SERVER['HTTP_HOST'] ?? 'localhost'));
$firma_nazev = nastaveni_get_cached($pdo, 'firma_nazev', '') ?: 'Provoz';

// === Načti doklad pro získání čísla a base64 PDF ===
$cislo = '';
$nazev_souboru = '';
$pdf_url = '';

try {
    if ($typ === 'fa') {
        $s = $pdo->prepare("SELECT cislo, odberatel_id, je_dobropis FROM faktury WHERE id = :id LIMIT 1");
        $s->execute(['id' => $id]);
        $row = $s->fetch(PDO::FETCH_ASSOC) ?: [];
        $cislo = $row['cislo'] ?? '';
        if (!$cislo) json_error('Faktura nenalezena', 404);
        $L = doc_lang_resolve($pdo, (int) $row['odberatel_id']);
        $jeDob = !empty($row['je_dobropis']);   // dobropis má vlastní názvosloví (dřív chodil jako „Faktura")
        $nazev_souboru = _t_lang($L, $jeDob ? 'Dobropis-{cislo}.pdf' : 'Faktura-{cislo}.pdf', ['cislo' => $cislo]);
        $renderer = 'faktura.php';
        $default_predmet = _t_lang($L, $jeDob ? 'Dobropis {cislo} · {firma}' : 'Faktura {cislo} · {firma}', ['cislo' => $cislo, 'firma' => $firma_nazev]);
        $druh = $jeDob ? 'Dobropis' : 'Faktura';
    } elseif ($typ === 'dl') {
        $s = $pdo->prepare("SELECT cislo, odberatel_id FROM dodaci_listy WHERE id = :id LIMIT 1");
        $s->execute(['id' => $id]);
        $row = $s->fetch(PDO::FETCH_ASSOC) ?: [];
        $cislo = $row['cislo'] ?? '';
        if (!$cislo) json_error('Dodací list nenalezen', 404);
        $L = doc_lang_resolve($pdo, (int) $row['odberatel_id']);
        $nazev_souboru = _t_lang($L, 'Dodaci-list-{cislo}.pdf', ['cislo' => $cislo]);
        $renderer = 'dodaci_list.php';
        $default_predmet = _t_lang($L, 'Dodací list {cislo} · {firma}', ['cislo' => $cislo, 'firma' => $firma_nazev]);
        $druh = 'Dodací list';
    } else { // obj
        $s = $pdo->prepare("SELECT cislo, odberatel_id FROM objednavky WHERE id = :id LIMIT 1");
        $s->execute(['id' => $id]);
        $row = $s->fetch(PDO::FETCH_ASSOC) ?: [];
        $cislo = $row['cislo'] ?? '';
        if (!$cislo) json_error('Objednávka nenalezena', 404);
        $L = doc_lang_resolve($pdo, (int) $row['odberatel_id']);
        $nazev_souboru = _t_lang($L, 'Objednavka-{cislo}.pdf', ['cislo' => $cislo]);
        $renderer = 'dodaci_list.php';  // objednávka jde přes dodací list endpoint
        $default_predmet = _t_lang($L, 'Objednávka {cislo} · {firma}', ['cislo' => $cislo, 'firma' => $firma_nazev]);
        $druh = 'Objednávka';
    }

    // 🔐 v2.9.164 — generuj signed token pro public e-mail link (vyhne se admin loginu pro odběratele)
    $email_token = create_email_token($pdo, $typ, $id, 30);
    // 🐛 v3.0.644 — včetně podsložky instalace (dřív jen host → u instalace v podsložce odkaz 404)
    $pdf_url = app_public_url('api/' . $renderer . '?token=' . $email_token);
} catch (Throwable $e) {
    json_error_safe('Chyba při načítání dokladu', $e, 500);
}

if ($predmet === '') $predmet = $default_predmet;

// 🐛 v3.0.644 — PDF se tu NEgeneruje: renderery (faktura.php / dodaci_list.php) vrací HTML (tisk z prohlížeče),
//   takže dřívější interní HTTP request na vlastní server byl k ničemu — a navíc s cookie admina čekal na zámek
//   jeho session (vnitřní session_start vs. vnější request) až do 30s timeoutu → každé „Odeslat e-mailem"
//   trvalo ≥30 s (hromadné odeslání minuty, za proxy 504). Zákazník dostane podepsaný odkaz na doklad.
$pdf_content = null;

// === Sestavení emailu (multipart s PDF přílohou) ===
function send_email_with_attachment(string $komu, string $predmet, string $html_telo, string $plain_telo, ?string $pdf_content, string $pdf_filename, string $from_email, string $from_name): bool {
    $boundary_mixed = 'mix_' . md5(uniqid('', true));
    $boundary_alt   = 'alt_' . md5(uniqid('alt', true));

    $headers = [];
    $headers[] = 'MIME-Version: 1.0';
    $headers[] = 'From: ' . mb_encode_mimeheader($from_name, 'UTF-8') . ' <' . $from_email . '>';
    $headers[] = 'Reply-To: ' . $from_email;
    $headers[] = 'X-Mailer: PHP/' . phpversion();

    if ($pdf_content) {
        $headers[] = 'Content-Type: multipart/mixed; boundary="' . $boundary_mixed . '"';
    } else {
        $headers[] = 'Content-Type: multipart/alternative; boundary="' . $boundary_alt . '"';
    }

    $body = '';

    if ($pdf_content) {
        $body .= "--$boundary_mixed\r\n";
        $body .= "Content-Type: multipart/alternative; boundary=\"$boundary_alt\"\r\n\r\n";
    }

    $body .= "--$boundary_alt\r\n";
    $body .= "Content-Type: text/plain; charset=UTF-8\r\n";
    $body .= "Content-Transfer-Encoding: 8bit\r\n\r\n";
    $body .= $plain_telo . "\r\n\r\n";

    $body .= "--$boundary_alt\r\n";
    $body .= "Content-Type: text/html; charset=UTF-8\r\n";
    $body .= "Content-Transfer-Encoding: 8bit\r\n\r\n";
    $body .= $html_telo . "\r\n\r\n";

    $body .= "--$boundary_alt--\r\n\r\n";

    if ($pdf_content) {
        $body .= "--$boundary_mixed\r\n";
        $body .= "Content-Type: application/pdf; name=\"$pdf_filename\"\r\n";
        $body .= "Content-Transfer-Encoding: base64\r\n";
        $body .= "Content-Disposition: attachment; filename=\"$pdf_filename\"\r\n\r\n";
        $body .= chunk_split(base64_encode($pdf_content)) . "\r\n";
        $body .= "--$boundary_mixed--\r\n";
    }

    $predmet_enc = '=?UTF-8?B?' . base64_encode($predmet) . '?=';
    return appek_mail_raw($komu, $predmet_enc, $body, implode("\r\n", $headers));
}

// === HTML šablona ===
// 🌍 v3.0.640 — texty v jazyce odběratele ($L); celé věty z překladového slovníku (pády se liší)
$vetaPriloha = ['Faktura' => 'V příloze najdete PDF faktury {cislo}.', 'Dobropis' => 'V příloze najdete PDF dobropisu {cislo}.',
                'Dodací list' => 'V příloze najdete PDF dodacího listu {cislo}.', 'Objednávka' => 'V příloze najdete PDF objednávky {cislo}.'][$druh];
$vetaOdkaz   = ['Faktura' => 'Fakturu {cislo} najdete zde:', 'Dobropis' => 'Dobropis {cislo} najdete zde:',
                'Dodací list' => 'Dodací list {cislo} najdete zde:', 'Objednávka' => 'Objednávku {cislo} najdete zde:'][$druh];
$typ_label_velky = _t_lang($L, $druh);

$zprava_html = $zprava ? '<p>' . nl2br(htmlspecialchars($zprava)) . '</p>' : '';
$zprava_plain = $zprava ? $zprava . "\n\n" : '';

// 🐛 fix v2.9.162 — body se přizpůsobí podle toho, jestli PDF příloha skutečně proběhla.
// Předtím vždy říkalo "v příloze najdete PDF", i když fallback poslal jen odkaz → uživatel
// dostal e-mail bez přílohy a bez odkazu, slepá ulička.
$has_attachment = $pdf_content !== null;
$intro_html = $has_attachment
    ? str_replace(htmlspecialchars($cislo), '<strong>' . htmlspecialchars($cislo) . '</strong>', htmlspecialchars(_t_lang($L, $vetaPriloha, ['cislo' => $cislo])))
    : str_replace(htmlspecialchars($cislo), '<strong>' . htmlspecialchars($cislo) . '</strong>', htmlspecialchars(_t_lang($L, $vetaOdkaz, ['cislo' => $cislo])))
      . ' <a href="' . htmlspecialchars($pdf_url) . '" style="color:#BA7517;font-weight:600;text-decoration:underline">' . htmlspecialchars($pdf_url) . '</a>';
$intro_plain = $has_attachment
    ? _t_lang($L, $vetaPriloha, ['cislo' => $cislo])
    : _t_lang($L, $vetaOdkaz, ['cislo' => $cislo]) . ' ' . $pdf_url;

$html_telo = '
<!DOCTYPE html>
<html lang="' . $L . '"><head><meta charset="UTF-8"></head>
<body style="font-family:-apple-system,Segoe UI,Roboto,sans-serif;background:#f5f5f5;padding:20px">
  <div style="max-width:600px;margin:0 auto;background:white;border-radius:10px;padding:24px;box-shadow:0 2px 10px rgba(0,0,0,0.05)">
    <h1 style="color:#BA7517;font-size:22px;margin:0 0 12px">' . htmlspecialchars($typ_label_velky) . ' ' . htmlspecialchars($cislo) . '</h1>
    <p style="color:#666;font-size:14px;margin:0 0 20px">' . htmlspecialchars(_t_lang($L, 'Dobrý den,')) . '</p>
    <p style="font-size:14px;line-height:1.6">' . $intro_html . '</p>
    ' . $zprava_html . '
    <p style="margin:24px 0 0;padding-top:16px;border-top:1px solid #eee;font-size:13px;color:#888">
      ' . htmlspecialchars(_t_lang($L, 'S pozdravem,')) . '<br><strong>' . htmlspecialchars($firma_nazev) . '</strong>
    </p>
  </div>
</body></html>';

$plain_telo = _t_lang($L, 'Dobrý den,') . "\n\n" . $intro_plain . "\n\n" . $zprava_plain . _t_lang($L, 'S pozdravem,') . "\n$firma_nazev";

// === Odeslání ===
$odeslano = 0;
$chyby = 0;
$detail = [];

foreach ($emails as $email) {
    $ok = send_email_with_attachment($email, $predmet, $html_telo, $plain_telo, $pdf_content, $nazev_souboru, $firma_email, $firma_nazev);
    if ($ok) {
        $odeslano++;
        $detail[] = ['email' => $email, 'stav' => 'odesláno'];
    } else {
        $chyby++;
        $detail[] = ['email' => $email, 'stav' => 'chyba'];
    }
}

json_response([
    'ok'       => $odeslano > 0,
    'odeslano' => $odeslano,
    'chyby'    => $chyby,
    'detail'   => $detail,
    'priloha'  => $pdf_content ? 'PDF přiloženo' : 'BEZ přílohy (jen odkaz)',
]);
