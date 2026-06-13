<?php
/**
 * 🔐 LICENSE EMAIL VERIFICATION — TOFU + Challenge hybrid model.
 *
 * Endpoint: POST /api/license_email_verify.php
 *   Body: {
 *     license_key: "APPEK-XXXX-XXXX-XXXX-XXXX",
 *     email:       "customer@example.com",
 *     action:      "check" | "send_code" | "verify_code",
 *     code:        "123456"  (jen pro action=verify_code)
 *   }
 *
 * Tří-fázový flow:
 *
 *   1. CHECK (action='check') — počáteční ověření při install/onboardingu:
 *      • Licence není evidovaná v vendor_licenses → ok:false, reason:not_found
 *      • Licence ještě nemá bound email (customer_email NULL) → TOFU BIND
 *        → uloží email do vendor_licenses.customer_email
 *        → vrátí: { ok:true, status:"bound_tofu" }
 *      • Licence má bound stejný email (case-insensitive) → instant pass
 *        → vrátí: { ok:true, status:"already_bound" }
 *      • Licence má bound JINÝ email → challenge required
 *        → vrátí: { ok:true, status:"challenge_required", masked_email:"j***@e***.cz" }
 *
 *   2. SEND_CODE (action='send_code') — pošle 6-místný kód na PŮVODNÍ bound email
 *      (ne na ten, co customer právě zadal — to je antifraud!).
 *      Kód platí 15 minut.
 *      → vrátí: { ok:true, sent:true, masked_email:"***" }
 *
 *   3. VERIFY_CODE (action='verify_code') — ověří kód a (volitelně) rebindne email.
 *      → ok:true, rebound_to: novy@email.cz  pokud success
 *      → ok:false, reason:'invalid_code' | 'expired' | 'too_many_attempts'
 *
 * Bezpečnost:
 *   - Codes jsou 6-digit numeric, expire 15min
 *   - Rate limit: max 3 attempty per code, max 5 send_code za hodinu per license
 *   - Code jde na PŮVODNÍ email — útočník nemůže nadělat nové bindings, dokud nemá přístup k mailu
 *   - Po rebind se ALL předchozí codes neplatí (used_at = NOW)
 */

header('Content-Type: application/json; charset=UTF-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') exit;
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok'=>false,'error'=>'method_not_allowed']);
    exit;
}

require_once __DIR__ . '/_license.php';

// Vendor DB
$vendorConfigs = [
    __DIR__ . '/vendor_db_config.local.php',
    realpath(__DIR__ . '/..') . '/vendor/config.local.php',
];
$loaded = false;
foreach ($vendorConfigs as $cfg) {
    if ($cfg && file_exists($cfg)) { require_once $cfg; $loaded = true; break; }
}
if (!$loaded || !defined('VENDOR_DB_HOST')) {
    http_response_code(503);
    echo json_encode(['ok'=>false,'error'=>'vendor_db_not_configured']);
    exit;
}

$d = json_decode(file_get_contents('php://input'), true);
if (!is_array($d)) {
    http_response_code(400);
    echo json_encode(['ok'=>false,'error'=>'invalid_json']);
    exit;
}

$licenseKey = strtoupper(trim($d['license_key'] ?? ''));
$email      = strtolower(trim($d['email'] ?? ''));
$action     = trim($d['action'] ?? 'check');
$inputCode  = trim($d['code'] ?? '');

// Validace formátu
if (!license_format_valid($licenseKey)) {
    echo json_encode(['ok'=>false, 'reason'=>'invalid_format',
        'message'=>'Klíč nemá správný formát APPEK-XXXX-XXXX-XXXX-XXXX.']);
    exit;
}
if (!license_valid($licenseKey)) {
    echo json_encode(['ok'=>false, 'reason'=>'invalid_checksum',
        'message'=>'Checksum klíče nesedí.']);
    exit;
}
if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    echo json_encode(['ok'=>false, 'reason'=>'invalid_email']);
    exit;
}

// === Helpers ===
function mask_email(?string $e): string {
    if (!$e || !str_contains($e, '@')) return '***';
    [$user, $domain] = explode('@', $e, 2);
    $domParts = explode('.', $domain);
    $maskedUser = mb_substr($user, 0, 1) . str_repeat('*', max(1, mb_strlen($user) - 1));
    $maskedDom  = mb_substr($domParts[0], 0, 1) . str_repeat('*', max(1, mb_strlen($domParts[0]) - 1));
    $tld = count($domParts) > 1 ? '.' . implode('.', array_slice($domParts, 1)) : '';
    return "{$maskedUser}@{$maskedDom}{$tld}";
}

function ensure_codes_table(PDO $pdo): void {
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS vendor_license_email_codes (
            id INT AUTO_INCREMENT PRIMARY KEY,
            license_id INT NOT NULL,
            target_email VARCHAR(255) NOT NULL,
            new_email VARCHAR(255) NULL,
            code VARCHAR(10) NOT NULL,
            purpose VARCHAR(40) NOT NULL DEFAULT 'email_verify',
            attempts INT DEFAULT 0,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            expires_at DATETIME NOT NULL,
            used_at DATETIME NULL,
            ip VARCHAR(45) NULL,
            INDEX idx_lic_active (license_id, used_at, expires_at),
            INDEX idx_code (license_id, code)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
}

function send_verify_email(string $to, string $code, string $licenseMasked, int $expiresMinutes): bool {
    $subject = "🔐 Příhlašovací kód pro APPEK licenci ({$licenseMasked})";
    $body = "Dobrý den,\n\n"
        . "někdo se pokusil nainstalovat APPEK aplikaci s vaší licencí na NOVÝ email.\n"
        . "Pokud jste to vy, použijte tento kód v instalátoru:\n\n"
        . "      ╔═══════════════╗\n"
        . "      ║   {$code}      ║\n"
        . "      ╚═══════════════╝\n\n"
        . "Kód je platný {$expiresMinutes} minut.\n\n"
        . "Pokud jste o instalaci NEVĚDĚLI, kontaktujte nás okamžitě —\n"
        . "někdo se možná pokouší zneužít vaši licenci.\n\n"
        . "S pozdravem,\nAPPEK · https://appek.cz · podpora@appek.cz";

    $headers = "From: APPEK Licence <noreply@appek.cz>\r\n"
        . "Reply-To: podpora@appek.cz\r\n"
        . "Content-Type: text/plain; charset=UTF-8\r\n"
        . "X-APPEK-License-Code: 1";

    return appek_mail_raw($to, '=?UTF-8?B?' . base64_encode($subject) . '?=', $body, $headers);
}

try {
    $dsn = sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
        VENDOR_DB_HOST,
        defined('VENDOR_DB_PORT') ? VENDOR_DB_PORT : 3306,
        VENDOR_DB_NAME);
    $pdo = new PDO($dsn, VENDOR_DB_USER, VENDOR_DB_PASS, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);

    // Načti licenci
    $stmt = $pdo->prepare("SELECT * FROM vendor_licenses WHERE license_key = :k LIMIT 1");
    $stmt->execute(['k' => $licenseKey]);
    $row = $stmt->fetch();

    if (!$row) {
        echo json_encode(['ok'=>false, 'reason'=>'not_found',
            'message'=>'Klíč není evidovaný u dodavatele.']);
        exit;
    }
    if (!empty($row['revoked_at']) || ($row['status'] ?? '') === 'revoked') {
        echo json_encode(['ok'=>false, 'reason'=>'revoked',
            'message' => 'Klíč byl revokován.']);
        exit;
    }
    if (!empty($row['expires_at']) && strtotime($row['expires_at']) < time()) {
        echo json_encode(['ok'=>false, 'reason'=>'expired',
            'message' => 'Platnost klíče vypršela.']);
        exit;
    }

    ensure_codes_table($pdo);

    $boundEmail = $row['customer_email'] ? strtolower(trim($row['customer_email'])) : null;
    $licenseId = (int) $row['id'];
    $licenseMasked = substr($licenseKey, 0, 12) . '…';

    // =============================================================
    // ACTION: CHECK
    // =============================================================
    if ($action === 'check') {
        if (!$email) {
            echo json_encode(['ok'=>false, 'reason'=>'email_required']);
            exit;
        }

        // 🎯 STAGE 1A: TOFU bind — licence ještě nikomu nebyla bound
        if (!$boundEmail) {
            try {
                $pdo->prepare("UPDATE vendor_licenses SET customer_email = :e, updated_at = NOW() WHERE id = :id")
                    ->execute(['e' => $email, 'id' => $licenseId]);
            } catch (Throwable $e) { /* tichý fail — možná chybí updated_at column */ }

            // Audit log (pokud existuje)
            try {
                $pdo->prepare("
                    INSERT INTO vendor_audit_log (action, target_license_id, target_key, details, ip)
                    VALUES ('email_tofu_bind', :lid, :k, :d, :ip)
                ")->execute([
                    'lid' => $licenseId, 'k' => $licenseKey,
                    'd' => json_encode(['email' => $email], JSON_UNESCAPED_UNICODE),
                    'ip' => $_SERVER['REMOTE_ADDR'] ?? null,
                ]);
            } catch (Throwable $e) { /* ignore */ }

            echo json_encode([
                'ok' => true,
                'status' => 'bound_tofu',
                'message' => 'První instalace — email byl automaticky bound k licenci.',
            ]);
            exit;
        }

        // 🎯 STAGE 1B: Already bound to same email — instant pass
        if ($boundEmail === $email) {
            echo json_encode([
                'ok' => true,
                'status' => 'already_bound',
                'message' => 'Email odpovídá registrovanému vlastníkovi licence.',
            ]);
            exit;
        }

        // 🚨 STAGE 1C: Different email — challenge required
        $maskedBound = mask_email($boundEmail);
        echo json_encode([
            'ok' => true,
            'status' => 'challenge_required',
            'masked_email' => $maskedBound,
            'message' => "Tato licence je registrovaná na jiný email ({$maskedBound}). "
                       . "Pro bezpečnost ti pošleme ověřovací kód na původní email — musíš k němu mít přístup.",
        ]);
        exit;
    }

    // =============================================================
    // ACTION: SEND_CODE
    // =============================================================
    if ($action === 'send_code') {
        if (!$boundEmail) {
            echo json_encode(['ok'=>false, 'reason'=>'no_bound_email',
                'message' => 'Licence nemá bound email — použij action=check (TOFU bind).']);
            exit;
        }
        if (!$email) {
            echo json_encode(['ok'=>false, 'reason'=>'email_required',
                'message' => 'Zadej email který chceš použít (nový bound).']);
            exit;
        }
        if ($boundEmail === $email) {
            echo json_encode(['ok' => true, 'status' => 'already_bound',
                'message' => 'Tento email už je bound, kód není potřeba.']);
            exit;
        }

        // Rate limit — max 5 send_code per hodinu per license
        $recent = $pdo->prepare("
            SELECT COUNT(*) FROM vendor_license_email_codes
            WHERE license_id = :id AND created_at > DATE_SUB(NOW(), INTERVAL 1 HOUR)
        ");
        $recent->execute(['id' => $licenseId]);
        if ((int) $recent->fetchColumn() >= 5) {
            echo json_encode(['ok'=>false, 'reason'=>'rate_limit',
                'message' => 'Příliš mnoho pokusů (5×/h). Zkus to za hodinu.']);
            exit;
        }

        // Vygeneruj 6-digit kód
        $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
        $expiresMinutes = 15;

        $pdo->prepare("
            INSERT INTO vendor_license_email_codes
              (license_id, target_email, new_email, code, purpose, expires_at, ip)
            VALUES (:lid, :te, :ne, :c, 'rebind', DATE_ADD(NOW(), INTERVAL :m MINUTE), :ip)
        ")->execute([
            'lid' => $licenseId,
            'te'  => $boundEmail,    // KAM se posílá kód
            'ne'  => $email,         // NA CO bude rebind po success
            'c'   => $code,
            'm'   => $expiresMinutes,
            'ip'  => $_SERVER['REMOTE_ADDR'] ?? null,
        ]);

        $sent = send_verify_email($boundEmail, $code, $licenseMasked, $expiresMinutes);

        echo json_encode([
            'ok' => true,
            'sent' => $sent,
            'masked_email' => mask_email($boundEmail),
            'expires_in_minutes' => $expiresMinutes,
            'message' => $sent
                ? "Kód odeslán na " . mask_email($boundEmail) . " (platí {$expiresMinutes} minut)."
                : 'Email se nepodařilo odeslat (zkontroluj logy mailu). Kontaktuj podpora@appek.cz.',
        ]);
        exit;
    }

    // =============================================================
    // ACTION: VERIFY_CODE
    // =============================================================
    if ($action === 'verify_code') {
        if (!$inputCode || !preg_match('/^[0-9]{6}$/', $inputCode)) {
            echo json_encode(['ok'=>false, 'reason'=>'invalid_code_format',
                'message' => 'Kód musí být 6 číslic.']);
            exit;
        }

        // Najdi aktivní (nepoužitý, nevypršelý) kód
        $stmt = $pdo->prepare("
            SELECT * FROM vendor_license_email_codes
            WHERE license_id = :id
              AND used_at IS NULL
              AND expires_at > NOW()
            ORDER BY created_at DESC
            LIMIT 1
        ");
        $stmt->execute(['id' => $licenseId]);
        $codeRow = $stmt->fetch();

        if (!$codeRow) {
            echo json_encode(['ok'=>false, 'reason'=>'no_active_code',
                'message' => 'Žádný aktivní kód. Pošli si nový (action=send_code).']);
            exit;
        }

        // Rate limit — max 3 attempts per code
        if ((int) $codeRow['attempts'] >= 3) {
            $pdo->prepare("UPDATE vendor_license_email_codes SET used_at = NOW() WHERE id = :id")
                ->execute(['id' => $codeRow['id']]);
            echo json_encode(['ok'=>false, 'reason'=>'too_many_attempts',
                'message' => 'Příliš mnoho neúspěšných pokusů. Pošli si nový kód.']);
            exit;
        }

        // Inkrementuj attempts
        $pdo->prepare("UPDATE vendor_license_email_codes SET attempts = attempts + 1 WHERE id = :id")
            ->execute(['id' => $codeRow['id']]);

        if (!hash_equals((string) $codeRow['code'], $inputCode)) {
            $remaining = max(0, 3 - ((int) $codeRow['attempts'] + 1));
            echo json_encode(['ok'=>false, 'reason'=>'invalid_code',
                'attempts_remaining' => $remaining,
                'message' => "Špatný kód. Zbývá pokusů: {$remaining}."]);
            exit;
        }

        // ✅ Code OK — proveď rebind
        $newEmail = $codeRow['new_email'] ?: $email;
        $pdo->prepare("UPDATE vendor_licenses SET customer_email = :e, updated_at = NOW() WHERE id = :id")
            ->execute(['e' => $newEmail, 'id' => $licenseId]);

        $pdo->prepare("UPDATE vendor_license_email_codes SET used_at = NOW() WHERE id = :id")
            ->execute(['id' => $codeRow['id']]);

        // Invalidate VŠECHNY ostatní aktivní kódy pro tuto licenci
        $pdo->prepare("UPDATE vendor_license_email_codes SET used_at = NOW()
                       WHERE license_id = :id AND used_at IS NULL")
            ->execute(['id' => $licenseId]);

        // Audit log
        try {
            $pdo->prepare("
                INSERT INTO vendor_audit_log (action, target_license_id, target_key, details, ip)
                VALUES ('email_rebind', :lid, :k, :d, :ip)
            ")->execute([
                'lid' => $licenseId, 'k' => $licenseKey,
                'd' => json_encode(['from' => $boundEmail, 'to' => $newEmail], JSON_UNESCAPED_UNICODE),
                'ip' => $_SERVER['REMOTE_ADDR'] ?? null,
            ]);
        } catch (Throwable $e) { /* ignore */ }

        echo json_encode([
            'ok' => true,
            'status' => 'rebound',
            'rebound_to' => $newEmail,
            'message' => "Email rebindnut z " . mask_email($boundEmail) . " na " . mask_email($newEmail) . ".",
        ]);
        exit;
    }

    echo json_encode(['ok'=>false, 'reason'=>'unknown_action',
        'message' => 'action musí být check / send_code / verify_code.']);

} catch (Throwable $e) {
    error_log('license_email_verify: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['ok'=>false, 'error'=>'server_error', 'detail'=>$e->getMessage()]);
}
