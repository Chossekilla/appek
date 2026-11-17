<?php
/**
 * 🔐 POS AUTH — PIN login pro POS kasu.
 *
 * 🆕 v2.9.270
 *
 * Endpoints:
 *   GET  ?action=users     → seznam aktivních uživatelů s PIN (id, jmeno, role, barva)
 *   POST ?action=login     → { user_id, pin } → nastaví POS session + vrátí csrf
 *   POST ?action=logout    → smaže POS session
 *   GET  ?action=me        → aktuální POS uživatel (nebo 401)
 *
 * Robustnost:
 *   - Rate-limit: 5 selhaných pokusů / IP / 15 min (sdílí prihlaseni_pokusy)
 *   - Konstantní časový password_verify (timing-attack mitigation)
 *   - bcrypt cost=8 (rychlé pro POS — ~30ms — bez DoS rizika)
 *   - Session-pinned: pos_session_id ≠ admin_id (oddělené flow)
 *   - CSRF token pro mutace
 *   - Audit log do prihlaseni_pokusy (typ='pos_pin')
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/_csrf.php';
require_once __DIR__ . '/_packages_lib.php';

cors_headers();
session_secure_start();
header('Content-Type: application/json; charset=UTF-8');

$pdo = db();

// POS Auth vyžaduje balíček Restaurace
if (!package_enabled('restaurace')) {
    http_response_code(402);
    json_response(['error' => 'POS Kasa vyžaduje balíček 🍕 Restaurace']);
}

// Idempotent migrace — zaručí PIN sloupce existují
(function() use ($pdo) {
    try {
        $cols = $pdo->query("
            SELECT COLUMN_NAME FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'admin_users'
        ")->fetchAll(PDO::FETCH_COLUMN);
        if (!in_array('pin_hash', $cols, true)) {
            try { $pdo->exec("ALTER TABLE admin_users ADD COLUMN pin_hash VARCHAR(255) NULL"); } catch (Throwable $e) {}
        }
        if (!in_array('pos_only', $cols, true)) {
            try { $pdo->exec("ALTER TABLE admin_users ADD COLUMN pos_only TINYINT(1) NOT NULL DEFAULT 0"); } catch (Throwable $e) {}
        }
        if (!in_array('posledni_pos_login', $cols, true)) {
            try { $pdo->exec("ALTER TABLE admin_users ADD COLUMN posledni_pos_login DATETIME NULL"); } catch (Throwable $e) {}
        }
    } catch (Throwable $e) {}
})();

// 🐛 v3.0.644 SECURITY — jednorázová migrace: ukázková data (admin_demo_seed) dřív zakládala účty
//   *@demo.cz s PINy natvrdo v distribuovaném kódu (0000/9999 u role admin, 1234/5678 u pokladních).
//   Na zákaznické instalaci (ne APPEK_DEMO_MODE) takový PIN smažeme — admin ho může nastavit znovu
//   v Uživatelé. Maže se jen PIN, který STÁLE odpovídá veřejnému demo PINu (vlastní PIN zůstane).
(function() use ($pdo) {
    if (defined('APPEK_DEMO_MODE') && APPEK_DEMO_MODE) return;
    try {
        $done = $pdo->prepare("SELECT hodnota FROM nastaveni WHERE klic = 'sec_demo_pin_reset_644' LIMIT 1");
        $done->execute();
        if ($done->fetchColumn() === '1') return;
        $demoPins = ['jarmila@demo.cz' => '1234', 'evzen@demo.cz' => '5678',
                     'prodavac1@demo.cz' => '0000', 'vedouci@demo.cz' => '9999'];
        $st = $pdo->prepare("SELECT id, email, pin_hash FROM admin_users
                              WHERE email IN ('jarmila@demo.cz','evzen@demo.cz','prodavac1@demo.cz','vedouci@demo.cz')
                                AND pin_hash IS NOT NULL");
        $st->execute();
        $clr = $pdo->prepare("UPDATE admin_users SET pin_hash = NULL WHERE id = :id");
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $known = $demoPins[strtolower((string) $r['email'])] ?? null;
            if ($known !== null && password_verify($known, (string) $r['pin_hash'])) {
                $clr->execute(['id' => (int) $r['id']]);
            }
        }
        $pdo->prepare("INSERT INTO nastaveni (klic, hodnota) VALUES ('sec_demo_pin_reset_644', '1')
                       ON DUPLICATE KEY UPDATE hodnota = '1'")->execute();
    } catch (Throwable $e) { error_log('pos_auth demo PIN reset: ' . $e->getMessage()); }
})();

$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? '';

// =============================================================
// GET ?action=users — keypad chip list (jen aktivní + má PIN)
// Bez autentizace — zobrazujeme pouze veřejně bezpečná data
// =============================================================
if ($method === 'GET' && $action === 'users') {
    try {
        $rows = $pdo->query("
            SELECT id, jmeno, role
            FROM admin_users
            WHERE aktivni = 1 AND pin_hash IS NOT NULL
            ORDER BY role = 'pos' DESC, role = 'prodavac' DESC, jmeno
            LIMIT 50
        ")->fetchAll(PDO::FETCH_ASSOC);
        // Sanitize — jen veřejně bezpečné fields, žádné email/posledni_login
        $safe = array_map(fn($r) => [
            'id'    => (int) $r['id'],
            'jmeno' => $r['jmeno'] ?? 'Uživatel',
            'role'  => $r['role']  ?? 'pos',
            'iniciala' => mb_substr($r['jmeno'] ?? 'X', 0, 1),
        ], $rows);
        json_response(['ok' => true, 'users' => $safe]);
    } catch (Throwable $e) {
        json_error('Chyba načtení uživatelů: ' . $e->getMessage(), 500);
    }
}

// =============================================================
// POST ?action=login — PIN přihlášení
// =============================================================
if ($method === 'POST' && $action === 'login') {
    $d = json_input();
    $userId = (int) ($d['user_id'] ?? 0);
    $pin    = trim((string) ($d['pin'] ?? ''));
    $ip     = client_ip();

    if (!$userId || $pin === '') json_error('Vyberte uživatele a zadejte PIN', 400);
    if (!preg_match('/^\d{4,6}$/', $pin)) json_error('PIN musí mít 4-6 cifer', 400);

    // Rate limit — 5 pokusů / 15 min / IP (sdílí prihlaseni_pokusy)
    if (login_rate_limited('pos:' . $userId, $ip, 'pos_pin')) {
        json_error('Příliš mnoho pokusů. Zkuste to za 15 minut.', 429);
    }
    // 🐛 v3.0.644 SECURITY — progresivní zámek účtu: 4místný PIN (10 000 kombinací) šel při 5 pokusech / 15 min
    //   uhodnout za dny. Navíc max 20 neúspěchů / 24 h na účet (od posledního úspěšného PIN přihlášení)
    //   → hrubá síla trvá roky. Zablokovaný pokladní se přihlásí heslem nebo admin změní PIN.
    if (!(defined('APPEK_DEMO_MODE') && APPEK_DEMO_MODE) && !in_array($ip, ['::1', '127.0.0.1'], true)) {
        try {
            $rl = $pdo->prepare("
                SELECT COUNT(*) FROM prihlaseni_pokusy
                WHERE typ = 'pos_pin' AND uspesny = 0 AND email = :em
                  AND cas > GREATEST(DATE_SUB(NOW(), INTERVAL 24 HOUR),
                      COALESCE((SELECT MAX(p2.cas) FROM prihlaseni_pokusy p2
                                WHERE p2.typ = 'pos_pin' AND p2.uspesny = 1 AND p2.email = :em2), '1970-01-01'))
            ");
            $rl->execute(['em' => 'pos:' . $userId, 'em2' => 'pos:' . $userId]);
            if ((int) $rl->fetchColumn() >= 20) {
                json_error('PIN tohoto účtu je kvůli opakovaným chybám dočasně zablokovaný (24 h). Požádejte administrátora o nový PIN.', 429);
            }
        } catch (Throwable $e) { error_log('pos_auth lockout: ' . $e->getMessage()); }
    }

    try {
        $stmt = $pdo->prepare("
            SELECT id, jmeno, role, aktivni, pin_hash, pos_only
            FROM admin_users WHERE id = :id LIMIT 1
        ");
        $stmt->execute(['id' => $userId]);
        $user = $stmt->fetch();

        // Konstantní časový check (timing-attack mitigation)
        $dummyHash = '$2y$08$' . str_repeat('a', 53);
        $hash = $user && $user['pin_hash'] ? $user['pin_hash'] : $dummyHash;
        $ok = password_verify($pin, $hash);

        if (!$user || !$ok) {
            login_log('pos:' . $userId, $ip, 'pos_pin', false);
            // Generic msg — neprozrazujeme jestli existuje uživatel
            json_error('Nesprávný PIN', 401);
        }
        if (!$user['aktivni']) {
            login_log('pos:' . $userId, $ip, 'pos_pin', false);
            json_error('Účet je deaktivován', 403);
        }

        // Anti session fixation
        session_regenerate_id(true);

        // POS session — sdílí admin_id (pos.php věří admin_id),
        // ale označí pos_session=1 (POS-only flow, žádné admin přístupy)
        $_SESSION['admin_id']      = (int) $user['id'];
        $_SESSION['admin_jmeno']   = $user['jmeno'];
        $_SESSION['admin_role']    = $user['role'];
        $_SESSION['pos_session']   = 1; // marker — login proběhl PIN
        // 🐛 v3.0.644 SECURITY — PIN session je VŽDY omezená na POS allowlist (i u účtu s rolí admin a pos_only=0).
        //   Dřív PIN (4 cifry, veřejný seznam uživatelů) u plného admina dal plnou admin session a obešel heslo.
        //   Do administrace se jde jen heslem (admin_login.php příznak zase smaže).
        $_SESSION['pos_only_user'] = 1;
        $_SESSION['pos_login_at']  = time();

        // Update posledni_pos_login
        $pdo->prepare("UPDATE admin_users SET posledni_pos_login = NOW() WHERE id = :id")
            ->execute(['id' => $user['id']]);

        login_log('pos:' . $userId, $ip, 'pos_pin', true);

        json_response([
            'ok' => true,
            'user' => [
                'id'    => (int) $user['id'],
                'jmeno' => $user['jmeno'],
                'role'  => $user['role'],
                'pos_only' => 1, // 🐛 v3.0.644 — PIN session je vždy POS-only
                'pos_only_account' => (int) ($user['pos_only'] ?? 0),
            ],
            'csrf_token' => csrf_token(),
        ]);
    } catch (Throwable $e) {
        error_log('pos_auth login: ' . $e->getMessage());
        json_error('Přihlášení selhalo', 500);
    }
}

// =============================================================
// GET ?action=me — kdo je přihlášený
// =============================================================
if ($method === 'GET' && $action === 'me') {
    if (empty($_SESSION['admin_id'])) {
        http_response_code(401);
        json_response(['error' => 'Nepřihlášen', 'authenticated' => false]);
    }
    json_response([
        'ok' => true,
        'authenticated' => true,
        'user' => [
            'id'    => (int) $_SESSION['admin_id'],
            'jmeno' => $_SESSION['admin_jmeno'] ?? '',
            'role'  => $_SESSION['admin_role']  ?? '',
            'pos_only' => (int) ($_SESSION['pos_only_user'] ?? 0),
            'is_pos_session' => !empty($_SESSION['pos_session']),
        ],
        'csrf_token' => csrf_token(),
    ]);
}

// =============================================================
// POST ?action=logout — zruš POS session
// =============================================================
if ($method === 'POST' && $action === 'logout') {
    $_SESSION = [];
    if (session_status() === PHP_SESSION_ACTIVE) {
        if (ini_get('session.use_cookies')) {
            $p = session_get_cookie_params();
            setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'], $p['secure'], $p['httponly']);
        }
        session_destroy();
    }
    json_response(['ok' => true]);
}

json_error('Neznámá akce', 404);
