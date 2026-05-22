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
function vendor_mail_template_license(array $license, array $order = []): array {
    $html = <<<HTML
<!DOCTYPE html>
<html><body style="font-family:-apple-system,sans-serif;background:#f5f5f7;padding:24px;color:#1d1d1f">
  <div style="max-width:560px;margin:0 auto;background:#fff;border-radius:16px;overflow:hidden;box-shadow:0 4px 14px rgba(0,0,0,0.06)">
    <div style="background:linear-gradient(135deg,#1d1d1f,#2d2d30);padding:24px 28px;color:#fff">
      <div style="font-size:28px;font-weight:800;background:linear-gradient(135deg,#BA7517,#F59E0B);-webkit-background-clip:text;-webkit-text-fill-color:transparent">📦 APPEK</div>
      <div style="font-size:13px;opacity:0.85;margin-top:4px">Vaše licenční klíč je připravený</div>
    </div>
    <div style="padding:28px">
      <h2 style="margin:0 0 10px;font-size:18px">Dobrý den, {$license['customer_name']},</h2>
      <p style="margin:0 0 16px;color:#3a3a3c;line-height:1.65">
        Děkujeme za objednávku. Vaše licence pro APPEK B2B je aktivní. Použijete ji při instalaci nebo v adminu.
      </p>
      <div style="background:linear-gradient(135deg,rgba(186,117,23,0.08),rgba(186,117,23,0.02));border-left:4px solid #BA7517;padding:18px 20px;border-radius:8px;margin:20px 0">
        <div style="font-size:11px;color:#86868b;text-transform:uppercase;letter-spacing:0.5px;font-weight:600">🔑 Licenční klíč</div>
        <div style="font-family:'SF Mono',Menlo,monospace;font-size:18px;font-weight:700;color:#1d1d1f;margin-top:6px;word-break:break-all">{$license['license_key']}</div>
      </div>
      <h3 style="font-size:15px;margin:24px 0 8px">📥 Co dál?</h3>
      <ol style="color:#3a3a3c;line-height:1.7;padding-left:20px;margin:0">
        <li>Stáhněte si <strong>customer ZIP</strong> z odkazu níže</li>
        <li>Rozbalte na svém hostingu (Hostinger, Forpsi, …) do <code>public_html/</code></li>
        <li>Otevřete <code>https://váš-web.cz/install.php</code></li>
        <li>Vložte tento klíč → projdete instalací</li>
        <li>Hotovo — APPEK běží</li>
      </ol>
      <div style="text-align:center;margin:28px 0">
        <a href="https://appek.cz/" style="display:inline-block;background:linear-gradient(180deg,#BA7517,#854F0B);color:#fff;padding:12px 26px;border-radius:999px;text-decoration:none;font-weight:600">📥 Stáhnout APPEK</a>
      </div>
      <p style="font-size:12px;color:#86868b;border-top:1px solid #e5e5e7;padding-top:16px;margin-top:24px;line-height:1.6">
        Tato licence je vázána na e-mail <strong>{$license['customer_email']}</strong>.
        Klíč si bezpečně uchovejte. Při problémech kontaktujte podporu.
      </p>
    </div>
    <div style="background:#fafafa;padding:14px 28px;font-size:11px;color:#86868b;text-align:center">
      © APPEK · noreply (na tento e-mail neodpovídejte)
    </div>
  </div>
</body></html>
HTML;

    $text  = "APPEK — Vaše licence\n\n";
    $text .= "Dobrý den, {$license['customer_name']},\n\n";
    $text .= "Vaše licenční klíč pro APPEK B2B je aktivní:\n\n";
    $text .= "    {$license['license_key']}\n\n";
    $text .= "Co dál:\n";
    $text .= "  1. Stáhněte customer ZIP z https://appek.cz/\n";
    $text .= "  2. Rozbalte na hostingu do public_html/\n";
    $text .= "  3. Otevřete https://váš-web.cz/install.php\n";
    $text .= "  4. Vložte tento klíč\n\n";
    $text .= "Děkujeme za důvěru!\n\n— APPEK\n";

    return ['html' => $html, 'text' => $text];
}
