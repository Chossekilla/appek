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
    // 🔒 v2.6.0 SECURITY: CSRF check pro POST/PUT/DELETE.
    //    GET jsou idempotentní → bez CSRF.
    //    Lze deaktivovat per-endpoint definicí konstanty SKIP_CSRF před require.
    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
    if (!defined('SKIP_CSRF') && in_array($method, ['POST', 'PUT', 'DELETE'], true)) {
        // GRACE: pokud klient ještě neposlal token (přechod), warning v logu, neblokujeme.
        // Po prosince 2026 nahradit za csrf_require() (strict).
        if (!csrf_check()) {
            error_log('⚠️ CSRF_MISSING admin endpoint ' . ($_SERVER['REQUEST_URI'] ?? '?')
                    . ' from ' . ($_SERVER['REMOTE_ADDR'] ?? '?')
                    . ' user_id=' . ($_SESSION['admin_id'] ?? '?'));
            // TODO v2.7: enable strict mode after frontend rolls out CSRF tokens
            // json_error('csrf_invalid', 403);
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
