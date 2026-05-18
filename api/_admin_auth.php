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

function require_admin(): array {
    session_secure_start();
    if (empty($_SESSION['admin_id'])) {
        json_error('Vyžadováno admin přihlášení', 401);
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
