<?php
/**
 * 🔐 EMAIL TOKEN — jednorázové signed URL pro veřejný přístup k dokladu z e-mailu.
 *
 * Customer dostane e-mail s odkazem typu /api/faktura.php?token=XYZ a klikne → server
 * ověří token (existuje, není revokovaný, není expirovaný) a pustí HTML render
 * dokladu BEZ require_admin(). Token je vázaný na konkrétní typ+id, takže prozrazení
 * jednoho tokenu nepustí útočníka k ostatním dokladům.
 *
 * Tabulka:
 *   email_tokens (id, token UNIQUE, typ enum('fa','dl','obj'), doklad_id,
 *                 created_at, expires_at, last_accessed, access_count, revoked)
 *
 * Funkce:
 *   create_email_token(pdo, typ, doklad_id, valid_days = 30): string
 *   verify_email_token(pdo, token, expected_typ = null): ?array
 *
 * Bezpečnost:
 *   - Token = bin2hex(random_bytes(24)) = 48 hex chars = 192 bitů entropie
 *   - Bound to specific typ+doklad_id, leak jednoho tokenu nepustí k ostatním
 *   - Expirace default 30 dní, lze revokovat (admin nastavení)
 *   - Každý přístup loguje last_accessed + zvyšuje access_count
 */

function ensure_email_tokens_table(PDO $pdo): void {
    static $checked = false;
    if ($checked) return;
    try {
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS email_tokens (
                id INT AUTO_INCREMENT PRIMARY KEY,
                token VARCHAR(64) NOT NULL UNIQUE,
                typ VARCHAR(8) NOT NULL,
                doklad_id INT NOT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                expires_at TIMESTAMP NULL,
                last_accessed TIMESTAMP NULL,
                access_count INT NOT NULL DEFAULT 0,
                revoked TINYINT(1) NOT NULL DEFAULT 0,
                INDEX idx_token (token),
                INDEX idx_typ_doklad (typ, doklad_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
    } catch (Throwable $e) {
        error_log('ensure_email_tokens_table: ' . $e->getMessage());
    }
    $checked = true;
}

function create_email_token(PDO $pdo, string $typ, int $doklad_id, int $valid_days = 30): string {
    if (!in_array($typ, ['fa', 'dl', 'obj'], true)) {
        throw new InvalidArgumentException('Invalid token typ');
    }
    if ($doklad_id <= 0) {
        throw new InvalidArgumentException('Invalid doklad_id');
    }
    ensure_email_tokens_table($pdo);
    $token = bin2hex(random_bytes(24));
    $stmt = $pdo->prepare("
        INSERT INTO email_tokens (token, typ, doklad_id, expires_at)
        VALUES (:t, :typ, :id, DATE_ADD(NOW(), INTERVAL :days DAY))
    ");
    $stmt->execute([
        't'    => $token,
        'typ'  => $typ,
        'id'   => $doklad_id,
        'days' => $valid_days,
    ]);
    return $token;
}

function verify_email_token(PDO $pdo, string $token, ?string $expected_typ = null): ?array {
    // Sanity check formátu — odmítne i prázdné / nesmyslné stringy bez DB queries
    if (!preg_match('/^[a-f0-9]{40,64}$/', $token)) return null;
    ensure_email_tokens_table($pdo);
    $stmt = $pdo->prepare("
        SELECT id, token, typ, doklad_id, created_at, expires_at, access_count
        FROM email_tokens
        WHERE token = :t
          AND revoked = 0
          AND (expires_at IS NULL OR expires_at > NOW())
        LIMIT 1
    ");
    $stmt->execute(['t' => $token]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) return null;
    if ($expected_typ !== null && $row['typ'] !== $expected_typ) return null;

    // Audit: log access
    try {
        $pdo->prepare("UPDATE email_tokens SET last_accessed = NOW(), access_count = access_count + 1 WHERE id = :id")
            ->execute(['id' => $row['id']]);
    } catch (Throwable $e) { /* non-fatal */ }

    return $row;
}
