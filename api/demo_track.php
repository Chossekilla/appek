<?php
/**
 * 🧪 DEMO TRACKING ENDPOINT — Loguje přístupy na demo.appek.cz.
 *
 * Veřejný endpoint (bez auth), ale chráněný rate limiting.
 *
 * POST /api/demo_track.php
 *   Body: { "akce": "enter|login|page_view|logout", "jazyk": "cs|en|es" }
 *
 * Zapisuje do tabulky demo_pristupy v hlavní DB.
 */

require_once __DIR__ . '/config.php';

// CORS pro demo subdoménu
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') exit;

header('Content-Type: application/json; charset=UTF-8');

try {
    $pdo = db();

    // Auto-create tabulky pokud chybí
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS demo_pristupy (
            id INT AUTO_INCREMENT PRIMARY KEY,
            ip VARCHAR(45),
            user_agent TEXT,
            akce VARCHAR(50),
            referer VARCHAR(255),
            jazyk VARCHAR(5),
            cas DATETIME DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_cas (cas),
            INDEX idx_ip (ip)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");

    // Načti data
    $raw = file_get_contents('php://input');
    $data = json_decode($raw, true) ?: [];

    $akce = trim($data['akce'] ?? 'enter');
    if (!in_array($akce, ['enter', 'login', 'page_view', 'logout'], true)) {
        $akce = 'enter';
    }

    $jazyk = substr(trim($data['jazyk'] ?? 'cs'), 0, 5);
    $ip = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
    $ip = explode(',', $ip)[0]; // První IP pokud X-Forwarded-For obsahuje řetězec
    $userAgent = substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 500);
    $referer = substr($_SERVER['HTTP_REFERER'] ?? '', 0, 255);

    // Rate limit — max 30 záznamů z jedné IP za 5 minut
    $count = (int) $pdo->prepare("
        SELECT COUNT(*) FROM demo_pristupy
        WHERE ip = :ip AND cas > DATE_SUB(NOW(), INTERVAL 5 MINUTE)
    ");
    $count->execute(['ip' => $ip]);
    if ($count->fetchColumn() > 30) {
        http_response_code(429);
        echo json_encode(['ok' => false, 'error' => 'rate_limited']);
        exit;
    }

    // Zapiš
    $pdo->prepare("
        INSERT INTO demo_pristupy (ip, user_agent, akce, referer, jazyk)
        VALUES (:ip, :ua, :akce, :ref, :jazyk)
    ")->execute([
        'ip' => $ip,
        'ua' => $userAgent,
        'akce' => $akce,
        'ref' => $referer,
        'jazyk' => $jazyk,
    ]);

    echo json_encode(['ok' => true]);
} catch (Throwable $e) {
    error_log('demo_track: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'server_error']);
}
