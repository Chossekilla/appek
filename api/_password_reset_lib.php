<?php
/**
 * 🔑 v3.0.591 — Odkaz na nastavení hesla (admin uživatelé + B2B odběratelé).
 *
 * Bez závislosti na config.php (jen PDO) → používá ho admin (api/admin_users.php)
 * i vendor (vendor/access.php, vlastní mailer). Stránka pro nastavení hesla = api/password_reset.php.
 *
 * Bezpečnost:
 *   • token = 32 náhodných bajtů (hex), v DB JEN sha256 hash → únik DB nedá použitelný odkaz
 *   • platnost 2 h, jednorázový (used_at), nový odkaz zneplatní starší nepoužité
 *   • limit 3 odkazy / uživatel / hodinu (proti zahlcení schránky)
 */

const PR_TTL_MIN      = 120;
const PR_MAX_PER_HOUR = 3;
const PR_MIN_LEN      = 8;

function pr_ensure_table(PDO $pdo): void {
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS password_resets (
            id INT AUTO_INCREMENT PRIMARY KEY,
            typ VARCHAR(10) NOT NULL,
            user_id INT NOT NULL,
            token_hash CHAR(64) NOT NULL,
            expires_at DATETIME NOT NULL,
            used_at DATETIME NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            created_by VARCHAR(120) NULL,
            UNIQUE KEY uq_token (token_hash),
            KEY idx_user (typ, user_id, created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
}

/** Uživatel pro odkaz: ['id','email','jmeno'] nebo null (neaktivní/blokovaný/bez e-mailu). */
function pr_find_user(PDO $pdo, string $typ, int $id): ?array {
    if ($typ === 'admin') {
        $st = $pdo->prepare("SELECT id, email, jmeno FROM admin_users WHERE id = :id AND aktivni = 1");
    } elseif ($typ === 'b2b') {
        $st = $pdo->prepare("SELECT id, login_email AS email, nazev AS jmeno FROM odberatele
                             WHERE id = :id AND blokovan = 0 AND login_email IS NOT NULL AND login_email <> ''");
    } else {
        return null;
    }
    $st->execute(['id' => $id]);
    $u = $st->fetch(PDO::FETCH_ASSOC);
    return ($u && filter_var($u['email'], FILTER_VALIDATE_EMAIL)) ? $u : null;
}

/** Vytvoří token. Vrací plaintext token (jen pro odkaz v e-mailu). Hodí RuntimeException při překročení limitu. */
function pr_create(PDO $pdo, string $typ, int $userId, string $createdBy = ''): string {
    pr_ensure_table($pdo);
    $st = $pdo->prepare("SELECT COUNT(*) FROM password_resets
                         WHERE typ = :t AND user_id = :u AND created_at > DATE_SUB(NOW(), INTERVAL 1 HOUR)");
    $st->execute(['t' => $typ, 'u' => $userId]);
    if ((int) $st->fetchColumn() >= PR_MAX_PER_HOUR) {
        throw new RuntimeException('Odkaz už byl odeslán ' . PR_MAX_PER_HOUR . '× za poslední hodinu — zkuste to později.');
    }
    // nový odkaz zneplatní dřívější nepoužité
    $pdo->prepare("UPDATE password_resets SET used_at = NOW() WHERE typ = :t AND user_id = :u AND used_at IS NULL")
        ->execute(['t' => $typ, 'u' => $userId]);
    $token = bin2hex(random_bytes(32));
    $pdo->prepare("INSERT INTO password_resets (typ, user_id, token_hash, expires_at, created_by)
                   VALUES (:t, :u, :h, DATE_ADD(NOW(), INTERVAL " . PR_TTL_MIN . " MINUTE), :c)")
        ->execute(['t' => $typ, 'u' => $userId, 'h' => hash('sha256', $token), 'c' => mb_substr($createdBy, 0, 120)]);
    return $token;
}

/** Platný (nepoužitý, nevypršelý) záznam pro token, jinak null. */
function pr_lookup(PDO $pdo, string $token): ?array {
    if (!preg_match('/^[a-f0-9]{64}$/', $token)) return null;
    pr_ensure_table($pdo);
    $st = $pdo->prepare("SELECT * FROM password_resets
                         WHERE token_hash = :h AND used_at IS NULL AND expires_at > NOW() LIMIT 1");
    $st->execute(['h' => hash('sha256', $token)]);
    $r = $st->fetch(PDO::FETCH_ASSOC);
    return $r ?: null;
}

/** Nastaví nové heslo a token spotřebuje (atomicky). Vrací typ ('admin'|'b2b') pro přesměrování na login. */
function pr_consume(PDO $pdo, string $token, string $newPass): string {
    if (mb_strlen($newPass) < PR_MIN_LEN) throw new InvalidArgumentException('Heslo musí mít alespoň ' . PR_MIN_LEN . ' znaků.');
    $pdo->beginTransaction();
    try {
        $st = $pdo->prepare("SELECT * FROM password_resets
                             WHERE token_hash = :h AND used_at IS NULL AND expires_at > NOW() LIMIT 1 FOR UPDATE");
        $st->execute(['h' => hash('sha256', $token)]);
        $r = $st->fetch(PDO::FETCH_ASSOC);
        if (!$r) throw new InvalidArgumentException('Odkaz je neplatný nebo vypršel. Požádejte o nový.');
        if (!pr_find_user($pdo, $r['typ'], (int) $r['user_id'])) throw new InvalidArgumentException('Účet není aktivní.');
        $hash = password_hash($newPass, PASSWORD_BCRYPT);
        $sql = $r['typ'] === 'admin'
            ? "UPDATE admin_users SET heslo_hash = :h WHERE id = :id"
            : "UPDATE odberatele SET heslo_hash = :h WHERE id = :id";
        $pdo->prepare($sql)->execute(['h' => $hash, 'id' => (int) $r['user_id']]);
        $pdo->prepare("UPDATE password_resets SET used_at = NOW() WHERE id = :id")->execute(['id' => $r['id']]);
        $pdo->commit();
        return $r['typ'];
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
}

/** [předmět, HTML tělo] e-mailu s odkazem. */
function pr_email(string $jmeno, string $link, string $appName): array {
    $e = fn($s) => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
    $subject = "Nastavení hesla — {$appName}";
    $html = '<div style="font-family:-apple-system,Segoe UI,Arial,sans-serif;max-width:520px;margin:0 auto;color:#1d1d1f">'
        . '<h2 style="font-size:20px;margin:0 0 14px">Nastavení hesla</h2>'
        . '<p style="font-size:15px;line-height:1.55">Dobrý den' . ($jmeno !== '' ? ', ' . $e($jmeno) : '') . ',</p>'
        . '<p style="font-size:15px;line-height:1.55">pro váš účet v aplikaci <strong>' . $e($appName) . '</strong> byl vyžádán odkaz na nastavení nového hesla.</p>'
        . '<p style="margin:22px 0"><a href="' . $e($link) . '" style="display:inline-block;background:#BA7517;color:#fff;text-decoration:none;font-weight:700;padding:13px 22px;border-radius:10px">Nastavit nové heslo</a></p>'
        . '<p style="font-size:13px;color:#6e6e73;line-height:1.5">Odkaz platí ' . (PR_TTL_MIN / 60) . ' hodiny a lze ho použít jen jednou. Pokud jste o změnu nežádali, e-mail ignorujte — vaše heslo zůstává beze změny.</p>'
        . '<p style="font-size:12px;color:#86868b;word-break:break-all">' . $e($link) . '</p>'
        . '</div>';
    return [$subject, $html];
}
