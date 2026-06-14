<?php
/**
 * Admin autentizace + role-based přístup.
 *
 * Role:
 *   admin       = super admin, vidí a smí všechno (vč. mazání)
 *   prodavac    = restricted, vidí vše, NESMÍ mazat (faktury, odběratele, výrobky, objednávky)
 *   vyroba      = (rezervováno) — pro budoucí omezení
 *   expedice    = (rezervováno)
 */

// 🔒 v2.6.0 SECURITY: auto-include CSRF helper pro všechny admin endpointy
require_once __DIR__ . '/_csrf.php';

function require_admin(): array {
    session_secure_start();
    if (empty($_SESSION['admin_id'])) {
        json_error('Vyžadováno admin přihlášení', 401);
    }
    // 🔒 v3.0.315 SECURITY: POS-only účet (přihlášený PINem) smí JEN POS endpointy.
    //   Dřív PIN login obešel blokádu z heslo-loginu a dostal se na všechny require_admin
    //   endpointy (privilege escalation). pos_only=0 účty (plný admin) jsou bez omezení.
    if (!empty($_SESSION['pos_only_user'])) {
        $posAllowed = ['admin_pos.php', 'admin_pos_presets.php', 'admin_pos_print.php',
            'admin_tables.php', 'admin_vouchers.php', 'admin_klient_chyby.php', 'admin_kitchen.php',
            'pay_qr.php', 'payment_methods.php', 'pos_auth.php', 'version.php', 'firma_branding.php', 'whoami.php'];
        $script = basename((string) ($_SERVER['SCRIPT_NAME'] ?? ($_SERVER['PHP_SELF'] ?? '')));
        if (!in_array($script, $posAllowed, true)) {
            json_error('POS účet nemá přístup k této části administrace', 403);
        }
    }
    // 🔒 v2.6.0 SECURITY: CSRF check pro POST/PUT/DELETE.
    //    GET jsou idempotentní → bez CSRF.
    //    Lze deaktivovat per-endpoint definicí konstanty SKIP_CSRF před require.
    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
    if (!defined('SKIP_CSRF') && in_array($method, ['POST', 'PUT', 'DELETE'], true)) {
        // 🔒 v3.0.228 STRICT: frontend rozjel tokeny všude (admin.js api()+raw fetche, pos.js,
        // whoami self-heal). Neplatný/chybějící token = 403. Klient se zotaví přes whoami retry.
        if (!csrf_check()) {
            error_log('⛔ CSRF_REJECT admin endpoint ' . ($_SERVER['REQUEST_URI'] ?? '?')
                    . ' from ' . ($_SERVER['REMOTE_ADDR'] ?? '?')
                    . ' user_id=' . ($_SESSION['admin_id'] ?? '?'));
            json_error('csrf_invalid', 403);
        }
    }
    return [
        'id'    => (int) $_SESSION['admin_id'],
        'jmeno' => $_SESSION['admin_jmeno'] ?? '',
        'role'  => $_SESSION['admin_role'] ?? 'admin',
    ];
}

/**
 * Vyžaduje super admina (role = 'admin').
 * Pro destruktivní operace: DELETE faktur, odběratelů, výrobků, objednávek.
 */
function require_super_admin(): array {
    $admin = require_admin();
    if (($admin['role'] ?? '') !== 'admin') {
        json_error('Tato akce vyžaduje oprávnění super admina', 403);
    }
    return $admin;
}

/**
 * Vrací true, pokud je aktuální admin super admin.
 * (Pro kontroly v rámci endpointu, ne jako gating.)
 */
function is_super_admin(): bool {
    return (($_SESSION['admin_role'] ?? '') === 'admin');
}
