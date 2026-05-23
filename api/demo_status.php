<?php
/**
 * 🧪 DEMO STATUS — odhalí, zda běží v demo módu.
 *
 * GET /api/demo_status.php
 *   → { demo: true|false, credentials?: { admin: {...}, b2b: {...} } }
 *
 * Aktivní pouze pokud config.local.php má APPEK_DEMO_MODE = true.
 * Použito v admin a B2B login pro auto-fill credentials.
 */

header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-cache');

@require_once __DIR__ . '/config.php';

$isDemo = defined('APPEK_DEMO_MODE') && APPEK_DEMO_MODE === true;

if (!$isDemo) {
    echo json_encode(['demo' => false]);
    exit;
}

// Admin (admin_users tabulka)
$adminEmail = defined('APPEK_DEMO_EMAIL')    ? APPEK_DEMO_EMAIL    : 'demo@appek.cz';
$adminPass  = defined('APPEK_DEMO_PASSWORD') ? APPEK_DEMO_PASSWORD : 'demo1234';

// B2B (odberatele.login_email — viz demo-seed.sql odběratel id=1)
$b2bEmail   = defined('APPEK_DEMO_B2B_EMAIL')    ? APPEK_DEMO_B2B_EMAIL    : 'odberatel@demo.cz';
$b2bPass    = defined('APPEK_DEMO_B2B_PASSWORD') ? APPEK_DEMO_B2B_PASSWORD : 'demo1234';

echo json_encode([
    'demo'        => true,
    'session_ttl' => defined('APPEK_DEMO_SESSION_TTL') ? (int)APPEK_DEMO_SESSION_TTL : 900,
    'reset_at'    => date('Y-m-d H:00:00', strtotime('+1 hour')),

    // Legacy zpětně kompatibilní pole (admin login používá toto)
    'credentials' => [
        'email'    => $adminEmail,
        'password' => $adminPass,
    ],

    // Strukturované — admin + B2B (B2B login používá toto)
    'admin' => [
        'email'    => $adminEmail,
        'password' => $adminPass,
    ],
    'b2b' => [
        'email'    => $b2bEmail,
        'password' => $b2bPass,
    ],
]);
