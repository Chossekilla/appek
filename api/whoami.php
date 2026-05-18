<?php
/**
 * Vrací info o aktuálně přihlášeném adminovi.
 * Použití: bootstrap admin frontendu pro získání role.
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/_admin_auth.php';
cors_headers();
$admin = require_admin();
json_response($admin);
