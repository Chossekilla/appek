<?php
/**
 * 📧 VENDOR MAIL — lightweight mailer (PHP mail() nebo SMTP).
 *
 * Použití:
 *   vendor_send_mail($to, $subject, $bodyHtml, $bodyText, $replyTo = null);
 *
 * Config v vendor_settings tabulce (key/value):
 *   mail_from_email     z čeho posíláme (default: noreply@appek.cz)
 *   mail_from_name      jméno odesílatele (default: APPEK)
 *   smtp_enabled        '1' = používat SMTP, jinak PHP mail()
 *   smtp_host           např. smtp.gmail.com
 *   smtp_port           465 (SSL) / 587 (TLS) / 25
 *   smtp_user
 *   smtp_pass
 *   smtp_encryption     'ssl' / 'tls' / ''
 */

function vendor_mail_settings(): array {
    static $cache = null;
    if ($cache !== null) return $cache;
    require_once __DIR__ . '/_lib.php';
    $pdo = vendor_db();
    vendor_ensure_settings_table($pdo);
    $rows = $pdo->query("SELECT `key`, `value` FROM vendor_settings")->fetchAll(PDO::FETCH_KEY_PAIR);
    $cache = array_merge([
        'mail_from_email'  => 'noreply@appek.cz',
        'mail_from_name'   => 'APPEK',
        'smtp_enabled'     => '0',
        'smtp_host'        => '',
        'smtp_port'        => '587',
        'smtp_user'        => '',
        'smtp_pass'        => '',
        'smtp_encryption'  => 'tls',
    ], $rows);
    return $cache;
}

function vendor_ensure_settings_table(PDO $pdo): void {
    static $done = false;
    if ($done) return;
    $done = true;
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS vendor_settings (
            `key`   VARCHAR(64) PRIMARY KEY,
            `value` TEXT NULL,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
}

function vendor_mail_set(string $key, string $value): void {
    require_once __DIR__ . '/_lib.php';
    $pdo = vendor_db();
    vendor_ensure_settings_table($pdo);
    $stmt = $pdo->prepare("
        INSERT INTO vendor_settings (`key`, `value`) VALUES (:k, :v)
        ON DUPLICATE KEY UPDATE `value` = :v2
    ");
    $stmt->execute(['k' => $key, 'v' => $value, 'v2' => $value]);
}

/**
 * Pošle mail. Vrátí true pokud OK, false pokud chyba (errMsg v $errOut).
 */
function vendor_send_mail(
    string $to,
    string $subject,
    string $bodyHtml,
    ?string $bodyText = null,
    ?string $replyTo = null,
    ?string &$errOut = null
): bool {
    $cfg = vendor_mail_settings();
    $fromEmail = $cfg['mail_from_email'] ?: 'noreply@appek.cz';
    $fromName  = $cfg['mail_from_name']  ?: 'APPEK';
    $bodyText  = $bodyText ?: strip_tags(str_replace(['<br>','<br/>','<br />','</p>'], "\n", $bodyHtml));

    if (!filter_var($to, FILTER_VALIDATE_EMAIL)) {
        $errOut = 'Neplatný příjemce: ' . $to;
        return false;
    }

    // ─── SMTP route ───
    if ($cfg['smtp_enabled'] === '1' && $cfg['smtp_host']) {
        return vendor_smtp_send($cfg, $to, $subject, $bodyHtml, $bodyText, $replyTo, $errOut);
    }

    // ─── PHP mail() fallback ───
    $boundary = '=_=appek_' . bin2hex(random_bytes(8));
    $headers = [
        'MIME-Version: 1.0',
        'Content-Type: multipart/alternative; boundary="' . $boundary . '"',
        'From: ' . mb_encode_mimeheader($fromName) . ' <' . $fromEmail . '>',
        'X-Mailer: APPEK',
    ];
    if ($replyTo) $headers[] = 'Reply-To: ' . $replyTo;

    $body  = "--$boundary\r\nContent-Type: text/plain; charset=UTF-8\r\n\r\n$bodyText\r\n";
    $body .= "--$boundary\r\nContent-Type: text/html; charset=UTF-8\r\n\r\n$bodyHtml\r\n";
    $body .= "--$boundary--\r\n";

    $ok = @mail($to, mb_encode_mimeheader($subject), $body, implode("\r\n", $headers));
    if (!$ok) {
        $errOut = 'PHP mail() vrátilo false (sendmail config nebo blocked).';
        return false;
    }
    return true;
}

/**
 * SMTP odeslání (raw socket — žádné externí deps).
 */
function vendor_smtp_send(array $cfg, string $to, string $subject, string $bodyHtml, string $bodyText, ?string $replyTo, ?string &$errOut): bool {
    $host = $cfg['smtp_host'];
    $port = (int) ($cfg['smtp_port'] ?: 587);
    $user = $cfg['smtp_user'];
    $pass = $cfg['smtp_pass'];
    $enc  = $cfg['smtp_encryption'] ?: 'tls';
    $fromEmail = $cfg['mail_from_email'];
    $fromName  = $cfg['mail_from_name'];

    $transport = ($enc === 'ssl') ? 'ssl://' : '';
    $sock = @stream_socket_client(
        $transport . $host . ':' . $port,
        $errno, $errstr, 30, STREAM_CLIENT_CONNECT
    );
    if (!$sock) { $errOut = "SMTP connect: $errstr ($errno)"; return false; }

    $read = function() use ($sock) {
        $out = '';
        while ($l = fgets($sock, 1024)) {
            $out .= $l;
            if (substr($l, 3, 1) === ' ') break;
        }
        return $out;
    };
    $write = function($cmd) use ($sock) { fputs($sock, $cmd . "\r\n"); };

    $read(); // banner
    $write('EHLO appek.cz'); $read();

    if ($enc === 'tls') {
        $write('STARTTLS'); $read();
        if (!@stream_socket_enable_crypto($sock, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
            $errOut = 'STARTTLS selhalo'; fclose($sock); return false;
        }
        $write('EHLO appek.cz'); $read();
    }

    if ($user && $pass) {
        $write('AUTH LOGIN'); $read();
        $write(base64_encode($user)); $read();
        $write(base64_encode($pass));
        $resp = $read();
        if (strpos($resp, '235') !== 0) { $errOut = 'SMTP AUTH selhal: ' . trim($resp); fclose($sock); return false; }
    }

    $write('MAIL FROM:<' . $fromEmail . '>'); $read();
    $write('RCPT TO:<' . $to . '>');
    $resp = $read();
    if (!preg_match('/^25[0-1]/', $resp)) { $errOut = 'RCPT odmítnut: ' . trim($resp); fclose($sock); return false; }

    $write('DATA'); $read();

    $boundary = '=_=appek_' . bin2hex(random_bytes(8));
    $payload  = "From: " . mb_encode_mimeheader($fromName) . " <$fromEmail>\r\n";
    $payload .= "To: <$to>\r\n";
    if ($replyTo) $payload .= "Reply-To: <$replyTo>\r\n";
    $payload .= "Subject: " . mb_encode_mimeheader($subject) . "\r\n";
    $payload .= "MIME-Version: 1.0\r\n";
    $payload .= "Content-Type: multipart/alternative; boundary=\"$boundary\"\r\n";
    $payload .= "X-Mailer: APPEK\r\n\r\n";
    $payload .= "--$boundary\r\nContent-Type: text/plain; charset=UTF-8\r\n\r\n$bodyText\r\n";
    $payload .= "--$boundary\r\nContent-Type: text/html; charset=UTF-8\r\n\r\n$bodyHtml\r\n";
    $payload .= "--$boundary--\r\n";
    $payload .= "\r\n.\r\n";
    fputs($sock, $payload);
    $resp = $read();
    if (strpos($resp, '250') !== 0) { $errOut = 'DATA odmítnuto: ' . trim($resp); fclose($sock); return false; }

    $write('QUIT'); fclose($sock);
    return true;
}

/**
 * Hotová šablona pro license e-mail.
 */
// 🆕 v2.9.208 — Notifikace pro vendor admina o nové platbě.
// Příjemce: vendor_settings.admin_notification_email → fallback mail_from_email.
function vendor_send_admin_notification(array $order, array $license): bool {
    $cfg = vendor_mail_settings();
    $to  = $cfg['admin_notification_email'] ?? '';
    if (!$to) $to = $cfg['mail_from_email'] ?? '';
    if (!$to || !filter_var($to, FILTER_VALIDATE_EMAIL)) return false;

    $orderNo = $order['order_no']     ?? ($order['id'] ?? '—');
    $amount  = !empty($order['total_kc']) ? number_format((float) $order['total_kc'], 0, ',', ' ') . ' Kč' : '—';
    $custN   = $order['customer_name']    ?? '—';
    $custCo  = $order['customer_company'] ?? '';
    $custE   = $order['customer_email']   ?? '';
    $custP   = $order['customer_phone']   ?? '';
    $installUrl = $order['install_url']   ?? '';
    $licKey  = $license['license_key']    ?? '—';
    $packages = '';
    if (!empty($order['packages_json'])) {
        $pkgs = json_decode($order['packages_json'], true) ?: [];
        $packages = is_array($pkgs) ? implode(', ', $pkgs) : '';
    }

    $html = '<!DOCTYPE html><html><body style="font-family:-apple-system,sans-serif;background:#f5f5f7;padding:24px;color:#1d1d1f">'
        . '<div style="max-width:560px;margin:0 auto;background:#fff;border-radius:14px;overflow:hidden;box-shadow:0 4px 14px rgba(0,0,0,0.06)">'
        . '<div style="background:linear-gradient(135deg,#208438,#34c759);padding:20px 24px;color:#fff">'
        . '<div style="font-size:22px;font-weight:800">💰 Nová platba</div>'
        . '<div style="font-size:13px;opacity:0.92;margin-top:4px">' . htmlspecialchars($amount) . ' · ' . htmlspecialchars($orderNo) . '</div>'
        . '</div>'
        . '<div style="padding:24px">'
        . '<table style="width:100%;font-size:13px;color:#3a3a3c;border-collapse:collapse">'
        . '<tr><td style="color:#86868b;padding:5px 10px 5px 0">Č. objednávky:</td><td style="padding:5px 0"><strong>' . htmlspecialchars($orderNo) . '</strong></td></tr>'
        . '<tr><td style="color:#86868b;padding:5px 10px 5px 0">Částka:</td><td style="padding:5px 0"><strong>' . htmlspecialchars($amount) . '</strong></td></tr>'
        . '<tr><td style="color:#86868b;padding:5px 10px 5px 0">Zákazník:</td><td style="padding:5px 0">' . htmlspecialchars($custN) . ($custCo ? ' (' . htmlspecialchars($custCo) . ')' : '') . '</td></tr>'
        . '<tr><td style="color:#86868b;padding:5px 10px 5px 0">E-mail:</td><td style="padding:5px 0"><a href="mailto:' . htmlspecialchars($custE) . '" style="color:#BA7517">' . htmlspecialchars($custE) . '</a></td></tr>'
        . ($custP ? '<tr><td style="color:#86868b;padding:5px 10px 5px 0">Telefon:</td><td style="padding:5px 0">' . htmlspecialchars($custP) . '</td></tr>' : '')
        . ($installUrl ? '<tr><td style="color:#86868b;padding:5px 10px 5px 0">URL:</td><td style="padding:5px 0"><a href="' . htmlspecialchars($installUrl) . '" style="color:#BA7517">' . htmlspecialchars($installUrl) . '</a></td></tr>' : '')
        . ($packages ? '<tr><td style="color:#86868b;padding:5px 10px 5px 0">Balíčky:</td><td style="padding:5px 0">' . htmlspecialchars($packages) . '</td></tr>' : '')
        . '</table>'
        . '<div style="background:#f5f5f7;border-radius:8px;padding:12px 14px;margin-top:18px;font-family:\'SF Mono\',Menlo,monospace;font-size:13px;font-weight:700;color:#1d1d1f">' . htmlspecialchars($licKey) . '</div>'
        . '<p style="font-size:12px;color:#86868b;margin:18px 0 0">Licence byla automaticky vygenerována a odeslána zákazníkovi.</p>'
        . '</div>'
        . '<div style="background:#fafafa;padding:12px 24px;font-size:11px;color:#86868b;text-align:center">APPEK vendor master · auto-notifikace</div>'
        . '</div></body></html>';

    $text  = "💰 NOVÁ PLATBA — {$amount}\n";
    $text .= str_repeat('=', 40) . "\n\n";
    $text .= "Č. objednávky:  {$orderNo}\n";
    $text .= "Částka:          {$amount}\n";
    $text .= "Zákazník:        {$custN}" . ($custCo ? " ({$custCo})" : "") . "\n";
    $text .= "E-mail:          {$custE}\n";
    if ($custP) $text .= "Telefon:         {$custP}\n";
    if ($installUrl) $text .= "URL:             {$installUrl}\n";
    if ($packages) $text .= "Balíčky:         {$packages}\n";
    $text .= "Licenční klíč:   {$licKey}\n\n";
    $text .= "Licence automaticky vygenerována a odeslána zákazníkovi.\n— APPEK vendor master\n";

    return vendor_send_mail($to, "💰 Nová platba: {$amount} · {$orderNo}", $html, $text);
}

function vendor_mail_template_license(array $license, array $order = []): array {
    // 🆕 v2.9.198 — rozšířený e-mail: serial nr (order ID), datum, částka, ToS link,
    // limit liability disclaimer (backup povinnost).
    $serialNr = $order['order_no'] ?? $order['id'] ?? '—';
    $orderDate = !empty($order['created_at'])
        ? date('j. n. Y', strtotime($order['created_at']))
        : date('j. n. Y');
    $amount = !empty($order['total_kc'])
        ? number_format((float) $order['total_kc'], 0, ',', ' ') . ' Kč'
        : (!empty($order['amount']) ? number_format((float) $order['amount'], 0, ',', ' ') . ' Kč' : '—');
    $vs = $order['variable_symbol'] ?? preg_replace('/\D/', '', (string) $serialNr);
    $downloadUrl = 'https://appek.cz/download.php?key=' . urlencode($license['license_key']);

    $html = <<<HTML
<!DOCTYPE html>
<html><body style="font-family:-apple-system,sans-serif;background:#f5f5f7;padding:24px;color:#1d1d1f">
  <div style="max-width:580px;margin:0 auto;background:#fff;border-radius:16px;overflow:hidden;box-shadow:0 4px 14px rgba(0,0,0,0.06)">
    <div style="background:linear-gradient(135deg,#1d1d1f,#2d2d30);padding:24px 28px;color:#fff">
      <div style="font-size:28px;font-weight:800;background:linear-gradient(135deg,#BA7517,#F59E0B);-webkit-background-clip:text;-webkit-text-fill-color:transparent">📦 APPEK</div>
      <div style="font-size:13px;opacity:0.85;margin-top:4px">Vaše licence je připravená</div>
    </div>
    <div style="padding:28px">
      <h2 style="margin:0 0 10px;font-size:18px">Dobrý den, {$license['customer_name']},</h2>
      <p style="margin:0 0 16px;color:#3a3a3c;line-height:1.65">
        Děkujeme za objednávku. Vaše licence pro APPEK B2B je aktivní. Použijete ji při instalaci.
      </p>

      <!-- 📋 SOUHRN OBJEDNÁVKY -->
      <div style="background:#f5f5f7;border-radius:10px;padding:14px 18px;margin:18px 0;font-size:13px">
        <div style="display:grid;grid-template-columns:auto 1fr;gap:6px 14px;color:#3a3a3c">
          <div style="color:#86868b">Č. objednávky:</div><div><strong>{$serialNr}</strong></div>
          <div style="color:#86868b">Datum:</div><div>{$orderDate}</div>
          <div style="color:#86868b">Částka:</div><div><strong>{$amount}</strong></div>
          <div style="color:#86868b">Variabilní symbol:</div><div>{$vs}</div>
        </div>
      </div>

      <!-- 🔑 LICENČNÍ KLÍČ -->
      <div style="background:linear-gradient(135deg,rgba(186,117,23,0.08),rgba(186,117,23,0.02));border-left:4px solid #BA7517;padding:18px 20px;border-radius:8px;margin:20px 0">
        <div style="font-size:11px;color:#86868b;text-transform:uppercase;letter-spacing:0.5px;font-weight:600">🔑 Licenční klíč</div>
        <div style="font-family:'SF Mono',Menlo,monospace;font-size:18px;font-weight:700;color:#1d1d1f;margin-top:6px;word-break:break-all">{$license['license_key']}</div>
        <div style="font-size:11px;color:#86868b;margin-top:8px">Doživotní licence pro jedno zařízení · Aktualizace zdarma 12 měsíců</div>
      </div>

      <h3 style="font-size:15px;margin:24px 0 8px">📥 Co dál?</h3>
      <ol style="color:#3a3a3c;line-height:1.7;padding-left:20px;margin:0">
        <li>Klikněte na <strong>Stáhnout APPEK</strong> níže (link obsahuje váš klíč)</li>
        <li>Rozbalte ZIP na svém hostingu do <code>public_html/</code></li>
        <li>Otevřete <code>https://váš-web.cz/install.php</code></li>
        <li>Vložte výše uvedený klíč → projdete instalací</li>
        <li>Hotovo — APPEK běží</li>
      </ol>

      <div style="text-align:center;margin:28px 0">
        <a href="{$downloadUrl}" style="display:inline-block;background:linear-gradient(180deg,#BA7517,#854F0B);color:#fff;padding:12px 26px;border-radius:999px;text-decoration:none;font-weight:600">📥 Stáhnout APPEK</a>
      </div>

      <!-- ⚠️ DŮLEŽITÉ UPOZORNĚNÍ -->
      <div style="background:rgba(255,149,0,0.08);border-left:3px solid #c66800;padding:12px 16px;border-radius:6px;margin:18px 0;font-size:12px;color:#854F0B;line-height:1.55">
        <strong>⚠️ Záloha dat je vaše povinnost.</strong> APPEK má vestavěné automatické zálohy databáze
        — aktivujte je v Nastavení → Údržba → Zálohy. Zálohy uchovávejte mimo produkční hosting.
        Prodávající nenese odpovědnost za ztrátu dat (§ 8.6 a 8.8 obchodních podmínek).
      </div>

      <p style="font-size:12px;color:#86868b;border-top:1px solid #e5e5e7;padding-top:16px;margin-top:24px;line-height:1.6">
        Tato licence je vázána na e-mail <strong>{$license['customer_email']}</strong>.
        Klíč si bezpečně uchovejte. Při problémech: <a href="mailto:support@appek.cz" style="color:#BA7517">support@appek.cz</a>.
        <br><br>
        <a href="https://appek.cz/obchodni-podminky.html" style="color:#86868b">Obchodní podmínky</a>
        ·
        <a href="https://appek.cz/zasady-ochrany-soukromi.html" style="color:#86868b">Zásady ochrany soukromí (GDPR)</a>
      </p>
    </div>
    <div style="background:#fafafa;padding:14px 28px;font-size:11px;color:#86868b;text-align:center">
      © APPEK · noreply (na tento e-mail neodpovídejte)
    </div>
  </div>
</body></html>
HTML;

    $text  = "APPEK — Vaše licence\n";
    $text .= str_repeat('=', 40) . "\n\n";
    $text .= "Dobrý den, {$license['customer_name']},\n\n";
    $text .= "Děkujeme za objednávku. Vaše licence pro APPEK B2B je aktivní.\n\n";
    $text .= "SOUHRN OBJEDNÁVKY\n";
    $text .= "  Č. objednávky:      {$serialNr}\n";
    $text .= "  Datum:              {$orderDate}\n";
    $text .= "  Částka:             {$amount}\n";
    $text .= "  Variabilní symbol:  {$vs}\n\n";
    $text .= "LICENČNÍ KLÍČ\n";
    $text .= "    {$license['license_key']}\n\n";
    $text .= "Doživotní licence pro jedno zařízení · Aktualizace zdarma 12 měsíců.\n\n";
    $text .= "CO DÁL?\n";
    $text .= "  1. Stáhněte APPEK z: {$downloadUrl}\n";
    $text .= "  2. Rozbalte ZIP na hostingu do public_html/\n";
    $text .= "  3. Otevřete https://váš-web.cz/install.php\n";
    $text .= "  4. Vložte výše uvedený klíč\n\n";
    $text .= "⚠️ DŮLEŽITÉ: Záloha dat je vaše povinnost. APPEK má vestavěné automatické\n";
    $text .= "zálohy — aktivujte je v Nastavení > Údržba > Zálohy. Zálohy uchovávejte\n";
    $text .= "mimo produkční hosting. Prodávající nenese odpovědnost za ztrátu dat\n";
    $text .= "(§ 8.6 a 8.8 obchodních podmínek).\n\n";
    $text .= "Tato licence je vázána na e-mail: {$license['customer_email']}\n";
    $text .= "Support: support@appek.cz\n";
    $text .= "Obchodní podmínky: https://appek.cz/obchodni-podminky.html\n";
    $text .= "GDPR: https://appek.cz/zasady-ochrany-soukromi.html\n\n";
    $text .= "Děkujeme za důvěru!\n— APPEK\n";

    return ['html' => $html, 'text' => $text];
}
