<?php
/**
 * 🆕 vendor/resolve.php — public endpoint pro CENTRÁLNÍ LOGIN mobilní appky (Fáze 1).
 * POST {email} → najde instalaci(e) dle e-mailu (z vendor_install_emails, plněno heartbeatem).
 * Rate-limited (per IP). Vrací JEN url+název instalace — nic citlivého, žádné ověření hesla.
 */
require_once __DIR__ . '/_lib.php';
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');          // appka (Capacitor origin) — veřejný directory lookup
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') { http_response_code(204); exit; }
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') { http_response_code(405); echo json_encode(['ok' => false, 'error' => 'POST only']); exit; }

$pdo = vendor_db();
resolve_ensure_tables($pdo);

$ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
if (resolve_rate_limited($pdo, $ip)) { http_response_code(429); echo json_encode(['ok' => false, 'error' => 'Příliš mnoho pokusů — zkus za chvíli']); exit; }

$d = json_decode(file_get_contents('php://input'), true) ?: [];
$email = strtolower(trim((string) ($d['email'] ?? '')));
if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) { http_response_code(400); echo json_encode(['ok' => false, 'error' => 'Neplatný e-mail']); exit; }

$st = $pdo->prepare("SELECT install_url, nazev FROM vendor_install_emails WHERE email = :e ORDER BY updated_at DESC LIMIT 5");
$st->execute(['e' => $email]);
$installs = array_map(fn($r) => ['url' => $r['install_url'], 'nazev' => $r['nazev'] ?: ''], $st->fetchAll(PDO::FETCH_ASSOC));
echo json_encode(['ok' => true, 'installs' => $installs], JSON_UNESCAPED_UNICODE);

function resolve_ensure_tables(PDO $pdo): void {
    $pdo->exec("CREATE TABLE IF NOT EXISTS vendor_install_emails (
        email VARCHAR(190) NOT NULL,
        install_url VARCHAR(255) NOT NULL,
        license_id INT NULL,
        nazev VARCHAR(190) NULL,
        updated_at DATETIME NOT NULL,
        PRIMARY KEY (email, install_url),
        INDEX idx_email (email)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $pdo->exec("CREATE TABLE IF NOT EXISTS vendor_resolve_hits (
        id INT AUTO_INCREMENT PRIMARY KEY, ip VARCHAR(64) NOT NULL, ts DATETIME NOT NULL, INDEX idx_ip_ts (ip, ts)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
}
function resolve_rate_limited(PDO $pdo, string $ip): bool {
    try {
        $pdo->prepare("INSERT INTO vendor_resolve_hits (ip, ts) VALUES (:ip, NOW())")->execute(['ip' => $ip]);
        $pdo->exec("DELETE FROM vendor_resolve_hits WHERE ts < DATE_SUB(NOW(), INTERVAL 1 HOUR)");
        $c = $pdo->prepare("SELECT COUNT(*) FROM vendor_resolve_hits WHERE ip = :ip AND ts > DATE_SUB(NOW(), INTERVAL 10 MINUTE)");
        $c->execute(['ip' => $ip]);
        return ((int) $c->fetchColumn()) > 20;   // > 20 / 10 min / IP
    } catch (Throwable $e) { return false; }
}
