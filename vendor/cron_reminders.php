<?php
/**
 * 🗓️ CRON — připomínky pronájmu (3 dny před vypršením).
 *
 * Spouštět 1× denně z Hostinger cronu (CLI):
 *   php ~/domains/appek.cz/public_html/vendor/cron_reminders.php
 *
 * Najde rental licence, které vyprší za ≤ REMINDER_DAYS dní, a pošle zákazníkovi
 * výzvu k prodloužení na my.appek.cz. Marker `reminded_until` = expirace, pro kterou
 * už byla připomínka odeslána → po prodloužení (nová expirace) se pošle znovu příště.
 *
 * CLI-ONLY (bez HTTP expozice).
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("CLI only\n");
}

require __DIR__ . '/_lib.php';   // vendor_db()
require __DIR__ . '/_mail.php';  // vendor_send_rental_reminder()

const REMINDER_DAYS = 3;

$pdo = vendor_db();

// Idempotentní migrace markeru
try {
    $has = $pdo->query("SHOW COLUMNS FROM vendor_licenses LIKE 'reminded_until'")->fetchAll();
    if (!$has) $pdo->exec("ALTER TABLE vendor_licenses ADD COLUMN reminded_until DATE NULL");
} catch (Throwable $e) { fwrite(STDERR, "migrace: " . $e->getMessage() . "\n"); }

// Rental licence, které vyprší v okně [dnes .. dnes+REMINDER_DAYS], aktivní, a ještě
// nepřipomenuté pro tuto konkrétní expiraci.
$sql = "SELECT id, license_key, customer_name, customer_email, expires_at
        FROM vendor_licenses
        WHERE rental = 1
          AND status = 'active'
          AND expires_at IS NOT NULL
          AND expires_at >= CURDATE()
          AND expires_at <= DATE_ADD(CURDATE(), INTERVAL :d DAY)
          AND (reminded_until IS NULL OR reminded_until <> expires_at)
          AND customer_email IS NOT NULL AND customer_email <> ''";
$st = $pdo->prepare($sql);
$st->execute(['d' => REMINDER_DAYS]);
$rows = $st->fetchAll(PDO::FETCH_ASSOC);

$sent = 0; $fail = 0;
$mark = $pdo->prepare("UPDATE vendor_licenses SET reminded_until = expires_at WHERE id = :id");
foreach ($rows as $lic) {
    try {
        if (vendor_send_rental_reminder($lic)) {
            $mark->execute(['id' => $lic['id']]);
            $sent++;
            echo "✔ " . $lic['customer_email'] . " (expiruje " . $lic['expires_at'] . ")\n";
        } else {
            $fail++;
            echo "✘ odeslání selhalo: " . $lic['customer_email'] . "\n";
        }
    } catch (Throwable $e) {
        $fail++;
        fwrite(STDERR, "chyba " . $lic['customer_email'] . ": " . $e->getMessage() . "\n");
    }
}

echo date('Y-m-d H:i') . " — připomínky pronájmu: kandidátů=" . count($rows) . " odesláno=$sent selhalo=$fail\n";
