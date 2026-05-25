<?php
/**
 * 🔐 AUTHZ — tichá varianta require_admin().
 *
 * Některé endpointy potřebují zjistit, jestli je uživatel přihlášený a jaká
 * je jeho role, ALE nesmí volat json_error() při neúspěchu. Typicky:
 *   - admin_pirate_status — vrací JSON s vlastním 403 message
 *   - license_heartbeat   — vrací 403 s pirate flag místo 401 redirectu
 *   - updates_apply       — předem ověřuje práva před spuštěním update
 *   - force-update.php    — bootstrap rescue endpoint mimo standardní auth
 *
 * Funkce:
 *   aktualni_uzivatel_z_session(): ?array
 *     Vrátí ['id', 'jmeno', 'role'] když je session OK, jinak null.
 *     Žádný redirect, žádný exit, žádný JSON output — pouze data.
 *     CSRF taky neřeší (volající si ho zkontroluje sám, pokud potřebuje).
 */

require_once __DIR__ . '/config.php';

if (!function_exists('aktualni_uzivatel_z_session')) {
    function aktualni_uzivatel_z_session(): ?array {
        if (function_exists('session_secure_start')) {
            session_secure_start();
        } elseif (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        if (empty($_SESSION['admin_id'])) {
            return null;
        }
        return [
            'id'    => (int) $_SESSION['admin_id'],
            'jmeno' => $_SESSION['admin_jmeno'] ?? '',
            'role'  => $_SESSION['admin_role'] ?? 'admin',
        ];
    }
}
