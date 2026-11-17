<?php
/**
 * 📧 SMTP odesílání (v3.0.289) — pure-PHP klient (žádná závislost / Composer).
 *
 * Když je SMTP v Nastavení zapnuté a nakonfigurované, e-maily (faktury, vouchery,
 * notifikace) jdou přes SMTP server zákazníka (lepší doručitelnost než PHP mail()).
 * Jinak fallback na nativní mail(). `appek_mail_raw()` je drop-in náhrada za `mail()`.
 *
 * Config v `nastaveni`: smtp_enabled, smtp_host, smtp_port, smtp_user, smtp_pass,
 *   smtp_secure ('none'|'ssl'|'tls'), smtp_from, smtp_from_name.
 */

if (!function_exists('smtp_cfg')) {

function smtp_cfg(PDO $pdo): array {
    static $cache = null;
    if ($cache !== null) return $cache;
    $keys = ['smtp_enabled','smtp_host','smtp_port','smtp_user','smtp_pass','smtp_secure','smtp_from','smtp_from_name'];
    $vals = [];
    try {
        $in = implode(',', array_fill(0, count($keys), '?'));
        $st = $pdo->prepare("SELECT klic, hodnota FROM nastaveni WHERE klic IN ($in)");
        $st->execute($keys);
        $vals = $st->fetchAll(PDO::FETCH_KEY_PAIR);
    } catch (Throwable $e) { $vals = []; }
    $sec = $vals['smtp_secure'] ?? 'tls';  // 🐛 v3.0.291 — bez ?? padalo „Undefined key" na každém mailu když SMTP nenastaveno
    $cache = [
        'enabled'   => !empty($vals['smtp_enabled']) && $vals['smtp_enabled'] !== '0',
        'host'      => trim((string)($vals['smtp_host'] ?? '')),
        'port'      => (int)($vals['smtp_port'] ?? 587),
        'user'      => trim((string)($vals['smtp_user'] ?? '')),
        'pass'      => (string)($vals['smtp_pass'] ?? ''),
        'secure'    => in_array($sec, ['none','ssl','tls'], true) ? $sec : 'tls',
        'from'      => trim((string)($vals['smtp_from'] ?? '')),
        'from_name' => trim((string)($vals['smtp_from_name'] ?? '')),
    ];
    if ($cache['port'] <= 0) $cache['port'] = 587;
    return $cache;
}

function smtp_is_on(array $cfg): bool {
    return !empty($cfg['enabled']) && $cfg['host'] !== '';
}

/** Vytáhne holý e-mail z hlavičky "From: Name <a@b.cz>" nebo z plain adresy. */
function smtp_extract_email(string $s): string {
    if (preg_match('/<([^>]+)>/', $s, $m)) return trim($m[1]);
    if (preg_match('/[\w.+\-]+@[\w.\-]+\.\w+/', $s, $m)) return trim($m[0]);
    return trim($s);
}

/**
 * 🧪 v3.0.556 — Mail sink (testovací režim): když existuje `api/.mail-sink` nebo je
 * v config.local.php `APPEK_MAIL_SINK = true`, e-maily se NEODESÍLAJÍ — jen se zapíšou
 * do `api/.mail-sink.log` (JSONL: čas, komu, předmět). Pro zátěžové testy (smoke-chain
 * na demu), aby notifikace nezahltily schránku ani limit odesílání hostingu. Dotfiles
 * jsou z webu blokované (api/.htaccess) a nikdy se nebalí do bundlu.
 */
function appek_mail_sink_active(): bool {
    return (defined('APPEK_MAIL_SINK') && APPEK_MAIL_SINK) || is_file(__DIR__ . '/.mail-sink');
}

function appek_mail_sink_write(string $to, string $subjectEnc): bool {
    $subj = function_exists('mb_decode_mimeheader') ? mb_decode_mimeheader($subjectEnc) : $subjectEnc;
    $line = json_encode(['t' => date('c'), 'to' => $to, 'subj' => $subj], JSON_UNESCAPED_UNICODE) . "\n";
    @file_put_contents(__DIR__ . '/.mail-sink.log', $line, FILE_APPEND | LOCK_EX);
    return true;
}

/**
 * Drop-in náhrada mail(): SMTP když zapnuté, jinak @mail(). Nikdy nehází — vrací bool.
 * $headers = už složené hlavičky (From/MIME/Content-Type…), stejně jako pro mail().
 */
/**
 * 🐛 v3.0.644 — sjednocení hlaviček odchozího e-mailu:
 *   - SMTP zapnuté + vyplněný Odesílatel (smtp_from): From = „smtp_from_name <smtp_from>" (dřív šel smtp_from
 *     jen do obálky MAIL FROM a From zůstal firma_email → O365 SendAsDenied / DMARC fail → spam);
 *     původní From (firma_email) se zachová jako Reply-To, ať odpovědi chodí firmě.
 *   - chybí From úplně (potvrzení web objednávky, „připraveno k vyzvednutí") → doplní se
 *     (smtp_from / firma_email / noreply@host) — bez From zprávu Gmail/Seznam odmítnou (RFC 5322).
 *   - doplní MIME-Version a Message-ID, pokud chybí.
 */
function appek_mail_headers_doplnit(string $headers, array $cfg, bool $smtpOn): string {
    $hdr = trim(preg_replace('/\r\n|\r|\n/', "\r\n", $headers), "\r\n");
    $fromRe = '/^From:[^\r\n]*(?:\r\n[ \t][^\r\n]*)*/mi';
    $maFrom = (bool) preg_match($fromRe, $hdr, $mFrom);
    $puvodniFrom = $maFrom ? smtp_extract_email(substr($mFrom[0], 5)) : '';

    $novyFrom = null;
    if ($smtpOn && $cfg['from'] !== '') {
        $fromEmail = smtp_extract_email($cfg['from']);
        $jmeno = $cfg['from_name'];
        if ($jmeno === '' && $maFrom && preg_match('/^From:\s*(.*?)\s*<[^>]+>/si', $mFrom[0], $mj)) {
            $jmeno = trim($mj[1]); // zachovej (už zakódované) jméno z původní hlavičky
            $novyFrom = 'From: ' . $jmeno . ' <' . $fromEmail . '>';
        } elseif ($jmeno !== '') {
            $novyFrom = 'From: ' . (function_exists('mb_encode_mimeheader') ? mb_encode_mimeheader($jmeno, 'UTF-8') : $jmeno) . ' <' . $fromEmail . '>';
        } else {
            $novyFrom = 'From: ' . $fromEmail;
        }
        if ($maFrom && $puvodniFrom !== '' && strcasecmp($puvodniFrom, $fromEmail) !== 0 && !preg_match('/^Reply-To:/mi', $hdr)) {
            $hdr .= "\r\nReply-To: " . $puvodniFrom;
        }
    } elseif (!$maFrom) {
        $fromEmail = '';
        $jmeno = '';
        try {
            $pdo = db();
            $fromEmail = trim((string) nastaveni_get($pdo, 'firma_email', ''));
            $jmeno = trim((string) nastaveni_get($pdo, 'firma_nazev', ''));
        } catch (Throwable $e) {}
        if ($smtpOn && $cfg['from'] !== '') $fromEmail = smtp_extract_email($cfg['from']);
        if ($fromEmail === '' && $smtpOn && $cfg['user'] !== '' && strpos($cfg['user'], '@') !== false) $fromEmail = $cfg['user'];
        if ($fromEmail === '') {
            // 🆕 v3.0.645 — v CLI (cron) doména z veřejné adresy instalace (dřív noreply@localhost)
            $hh = (string) ($_SERVER['HTTP_HOST'] ?? '');
            if ($hh === '' && PHP_SAPI === 'cli' && function_exists('app_public_base')) $hh = (string) (parse_url(app_public_base(), PHP_URL_HOST) ?? '');
            $host = preg_replace('/[^a-zA-Z0-9.\-]/', '', preg_replace('/:\d+$/', '', $hh)) ?: 'localhost';
            $fromEmail = 'noreply@' . preg_replace('/^www\./i', '', $host);
        }
        $novyFrom = 'From: ' . ($jmeno !== '' && function_exists('mb_encode_mimeheader') ? mb_encode_mimeheader($jmeno, 'UTF-8') . ' <' . $fromEmail . '>' : $fromEmail);
    }
    if ($novyFrom !== null) {
        if ($maFrom) $hdr = preg_replace($fromRe, str_replace(['\\', '$'], ['\\\\', '\\$'], $novyFrom), $hdr, 1);
        else $hdr = $hdr === '' ? $novyFrom : ($novyFrom . "\r\n" . $hdr);
    }
    if (!preg_match('/^MIME-Version:/mi', $hdr)) $hdr .= "\r\nMIME-Version: 1.0";
    if (!preg_match('/^Message-ID:/mi', $hdr)) {
        $dom = substr(strrchr(smtp_extract_email(preg_match($fromRe, $hdr, $mf) ? substr($mf[0], 5) : ''), '@') ?: '@appek.local', 1);
        $hdr .= "\r\nMessage-ID: <" . bin2hex(random_bytes(12)) . '.' . time() . '@' . ($dom ?: 'appek.local') . '>';
    }
    return $hdr;
}

function appek_mail_raw(string $to, string $subjectEnc, string $body, string $headers): bool {
    if (appek_mail_sink_active()) return appek_mail_sink_write($to, $subjectEnc);
    $cfg = smtp_cfg(db());
    $smtpOn = smtp_is_on($cfg);
    try { $headers = appek_mail_headers_doplnit($headers, $cfg, $smtpOn); } catch (Throwable $e) { error_log('mail hlavičky: ' . $e->getMessage()); }
    if (!$smtpOn) return @mail($to, $subjectEnc, $body, $headers);
    try {
        smtp_deliver($cfg, $to, $subjectEnc, $body, $headers);
        return true;
    } catch (Throwable $e) {
        error_log('SMTP selhalo (' . $e->getMessage() . '), fallback na mail()');
        return @mail($to, $subjectEnc, $body, $headers);
    }
}

/**
 * Doručí přes SMTP. Hází Exception s detailem při chybě (chytá appek_mail_raw).
 * $log (volitelně referencí) sbírá konverzaci pro testovací tlačítko.
 */
function smtp_deliver(array $cfg, string $to, string $subjectEnc, string $body, string $headers, ?array &$log = null): bool {
    $host = $cfg['host'];
    $port = $cfg['port'];
    $secure = $cfg['secure'];
    $transport = ($secure === 'ssl') ? "ssl://$host" : $host;
    $logFn = function ($dir, $msg) use (&$log) { if ($log !== null) $log[] = $dir . ' ' . rtrim($msg); };

    $ctx = stream_context_create(['ssl' => ['verify_peer' => false, 'verify_peer_name' => false]]);
    $errno = 0; $errstr = '';
    $fp = @stream_socket_client("$transport:$port", $errno, $errstr, 12, STREAM_CLIENT_CONNECT, $ctx);
    if (!$fp) throw new RuntimeException("Spojení selhalo ($host:$port): $errstr");
    stream_set_timeout($fp, 12);

    $read = function () use ($fp, $logFn) {
        $data = '';
        while (($line = fgets($fp, 515)) !== false) {
            $data .= $line;
            // víceřádková odpověď: "250-..." pokračuje, "250 ..." končí
            if (strlen($line) >= 4 && $line[3] === ' ') break;
        }
        $logFn('S:', $data);
        return $data;
    };
    $write = function ($cmd, $hide = false) use ($fp, $logFn) {
        fwrite($fp, $cmd . "\r\n");
        $logFn('C:', $hide ? '***' : $cmd);
    };
    $expect = function ($resp, $codes) {
        $code = (int) substr(ltrim($resp), 0, 3);
        if (!in_array($code, (array) $codes, true)) throw new RuntimeException('SMTP neočekávaná odpověď: ' . trim($resp));
    };

    $expect($read(), 220);
    $ehloHost = preg_replace('/[^a-zA-Z0-9.\-]/', '', $_SERVER['HTTP_HOST'] ?? 'appek.local') ?: 'appek.local';
    $write("EHLO $ehloHost");
    $ehlo = $read();
    $expect($ehlo, 250);

    // STARTTLS (port 587 / secure=tls)
    if ($secure === 'tls') {
        $write('STARTTLS');
        $expect($read(), 220);
        if (!@stream_socket_enable_crypto($fp, true, STREAM_CRYPTO_METHOD_TLS_CLIENT | STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT | STREAM_CRYPTO_METHOD_TLSv1_1_CLIENT)) {
            throw new RuntimeException('STARTTLS šifrování selhalo');
        }
        $write("EHLO $ehloHost");
        $expect($read(), 250);
    }

    // AUTH LOGIN (jen když jsou creds)
    if ($cfg['user'] !== '') {
        $write('AUTH LOGIN');
        $expect($read(), 334);
        $write(base64_encode($cfg['user']));
        $expect($read(), 334);
        $write(base64_encode($cfg['pass']), true);
        $expect($read(), 235);
    }

    // Obálka
    $fromEmail = $cfg['from'] !== '' ? smtp_extract_email($cfg['from']) : smtp_extract_email($headers);
    if ($fromEmail === '') $fromEmail = $cfg['user'] ?: ('noreply@' . $host);
    $write('MAIL FROM:<' . $fromEmail . '>');
    $expect($read(), 250);

    $recipients = array_filter(array_map('trim', explode(',', $to)), fn($r) => $r !== '');
    foreach ($recipients as $rcpt) {
        $write('RCPT TO:<' . smtp_extract_email($rcpt) . '>');
        $expect($read(), [250, 251]);
    }

    $write('DATA');
    $expect($read(), 354);

    // Sestav zprávu: existující hlavičky + Subject/To/Date pokud chybí
    $hdr = rtrim($headers, "\r\n");
    if (!preg_match('/^Subject:/mi', $hdr)) $hdr .= "\r\nSubject: " . $subjectEnc;
    if (!preg_match('/^To:/mi', $hdr))      $hdr .= "\r\nTo: " . $to;
    if (!preg_match('/^Date:/mi', $hdr))    $hdr .= "\r\nDate: " . date('r');
    $message = $hdr . "\r\n\r\n" . $body;
    // CRLF normalizace + dot-stuffing (řádek začínající '.' → '..')
    $message = preg_replace('/\r\n|\r|\n/', "\r\n", $message);
    $message = preg_replace('/^\./m', '..', $message);
    $write($message . "\r\n.");
    $expect($read(), 250);

    $write('QUIT');
    @fgets($fp, 515);
    fclose($fp);
    return true;
}

}  // function_exists guard
