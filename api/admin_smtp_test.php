<?php
/**
 * 📧 SMTP test (v3.0.289) — pošle testovací e-mail přes zadané/uložené SMTP a vrátí
 * konverzaci se serverem (pro tlačítko „Odeslat testovací" v Nastavení).
 *
 * POST { host, port, user, pass?, secure, from, from_name, to }
 *   pass prázdné → použije uložené smtp_pass (UI heslo maskuje).
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/_admin_auth.php';
require_once __DIR__ . '/_smtp_lib.php';
cors_headers();
require_admin();
header('Content-Type: application/json; charset=UTF-8');

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') json_error('Method not allowed', 405);
$d = json_input();
$pdo = db();

$saved = smtp_cfg($pdo);
$cfg = [
    'enabled'   => true,
    'host'      => trim((string)($d['host'] ?? $saved['host'])),
    'port'      => (int)($d['port'] ?? $saved['port']),
    'user'      => trim((string)($d['user'] ?? $saved['user'])),
    'pass'      => ($d['pass'] ?? '') !== '' ? (string)$d['pass'] : $saved['pass'],
    'secure'    => in_array(($d['secure'] ?? $saved['secure']), ['none','ssl','tls'], true) ? $d['secure'] : $saved['secure'],
    'from'      => trim((string)($d['from'] ?? $saved['from'])),
    'from_name' => trim((string)($d['from_name'] ?? $saved['from_name'])),
];
if ($cfg['host'] === '') json_error('Vyplň SMTP host', 400);
if ($cfg['port'] <= 0) $cfg['port'] = 587;

$to = trim((string)($d['to'] ?? ''));
if ($to === '') $to = $cfg['from'] !== '' ? smtp_extract_email($cfg['from']) : ($cfg['user'] ?: '');
if (!filter_var($to, FILTER_VALIDATE_EMAIL)) json_error('Zadej platný cílový e-mail pro test', 400);

$firma = nastaveni_get($pdo, 'firma_nazev', '') ?: 'APPEK';
$fromName = $cfg['from_name'] !== '' ? $cfg['from_name'] : $firma;
$fromEmail = $cfg['from'] !== '' ? smtp_extract_email($cfg['from']) : ($cfg['user'] ?: 'noreply@' . $cfg['host']);
$subject = '=?UTF-8?B?' . base64_encode("✅ SMTP test — $firma") . '?=';
$body = "<!DOCTYPE html><html lang=\"cs\"><body style=\"font-family:sans-serif\">"
      . "<h2 style=\"color:#16a34a\">✅ SMTP funguje</h2>"
      . "<p>Toto je testovací e-mail z APPEK. Pokud ho čteš, odesílání přes SMTP "
      . "(<strong>" . htmlspecialchars($cfg['host']) . ":" . $cfg['port'] . "</strong>, "
      . htmlspecialchars($cfg['secure']) . ") je správně nastavené.</p>"
      . "<p style=\"color:#888;font-size:12px\">$firma · " . date('d.m.Y H:i') . "</p></body></html>";
$headers = implode("\r\n", [
    'MIME-Version: 1.0',
    'Content-Type: text/html; charset=UTF-8',
    'From: ' . mb_encode_mimeheader($fromName, 'UTF-8') . ' <' . $fromEmail . '>',
    'Reply-To: ' . $fromEmail,
    'X-Mailer: APPEK SMTP test',
]);

$log = [];
try {
    smtp_deliver($cfg, $to, $subject, $body, $headers, $log);
    json_response(['ok' => true, 'message' => "Testovací e-mail odeslán na $to", 'log' => $log]);
} catch (Throwable $e) {
    json_response(['ok' => false, 'error' => $e->getMessage(), 'log' => $log], 200);
}
