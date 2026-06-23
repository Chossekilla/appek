<?php
/**
 * 🆕 v3.0.385 — vendor/demo-track.php — CENTRÁLNÍ záznam demo přístupů.
 *
 * Vendor je master všeho a hlídá VEŠKERÉ přístupy → demo přístupy se logují do VENDOR DB.
 * Dřív se volalo api/demo_track.php (app DB), jenže appek.cz není plná app instalace
 * (db() tam 302/install) → fetch v demo/index.html dostal odpověď na první URL a fallback
 * nezkusil → nezapsalo se nikam, kam by se vendor díval. demo-log.php čte tutéž vendor DB.
 *
 * POST {akce, jazyk} → INSERT do demo_pristupy. Veřejný (CORS *), rate-limited per IP.
 */
require_once __DIR__ . '/_lib.php';
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');          // demo.appek.cz / appek.cz/demo — veřejný tracking, nic citlivého
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') { http_response_code(204); exit; }
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') { http_response_code(405); echo json_encode(['ok' => false, 'error' => 'POST only']); exit; }

$pdo = vendor_db();
demotrack_ensure_tables($pdo);

$ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
if (demotrack_rate_limited($pdo, $ip)) { http_response_code(429); echo json_encode(['ok' => false, 'error' => 'rate']); exit; }

$d = json_decode(file_get_contents('php://input'), true) ?: [];
$allowed = ['enter', 'login', 'page_view', 'logout'];
$akceRaw = (string) ($d['akce'] ?? 'enter');
$akce  = in_array($akceRaw, $allowed, true) ? $akceRaw : 'enter';
$jazyk = substr(preg_replace('/[^a-z]/', '', strtolower((string) ($d['jazyk'] ?? 'cs'))), 0, 5) ?: 'cs';
$ua    = substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 500);
$ref   = substr((string) ($_SERVER['HTTP_REFERER'] ?? ''), 0, 255);

try {
    $pdo->prepare("INSERT INTO demo_pristupy (ip, user_agent, akce, referer, jazyk) VALUES (:ip,:ua,:akce,:ref,:jazyk)")
        ->execute(['ip' => $ip, 'ua' => $ua, 'akce' => $akce, 'ref' => $ref, 'jazyk' => $jazyk]);
    echo json_encode(['ok' => true]);
} catch (Throwable $e) {
    error_log('demo-track: ' . $e->getMessage());
    http_response_code(500); echo json_encode(['ok' => false]);
}

function demotrack_ensure_tables(PDO $pdo): void {
    // Shodné schéma s demo-log.php (čtenář)
    $pdo->exec("CREATE TABLE IF NOT EXISTS demo_pristupy (
        id INT AUTO_INCREMENT PRIMARY KEY,
        ip VARCHAR(45), user_agent TEXT, akce VARCHAR(50), referer VARCHAR(255), jazyk VARCHAR(5),
        cas DATETIME DEFAULT CURRENT_TIMESTAMP, INDEX idx_cas (cas), INDEX idx_ip (ip)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $pdo->exec("CREATE TABLE IF NOT EXISTS demo_track_hits (
        id INT AUTO_INCREMENT PRIMARY KEY, ip VARCHAR(64) NOT NULL, ts DATETIME NOT NULL, INDEX idx_ip_ts (ip, ts)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
}
function demotrack_rate_limited(PDO $pdo, string $ip): bool {
    try {
        $pdo->prepare("INSERT INTO demo_track_hits (ip, ts) VALUES (:ip, NOW())")->execute(['ip' => $ip]);
        $pdo->exec("DELETE FROM demo_track_hits WHERE ts < DATE_SUB(NOW(), INTERVAL 1 HOUR)");
        $c = $pdo->prepare("SELECT COUNT(*) FROM demo_track_hits WHERE ip = :ip AND ts > DATE_SUB(NOW(), INTERVAL 10 MINUTE)");
        $c->execute(['ip' => $ip]);
        return ((int) $c->fetchColumn()) > 200;   // > 200 / 10 min / IP (page_view může být častý)
    } catch (Throwable $e) { return false; }
}
