<?php
/**
 * Vrací info o aktuálně přihlášeném adminovi.
 * Použití: bootstrap admin frontendu pro získání role.
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/_admin_auth.php';
cors_headers();
$admin = require_admin();
// 🔒 v3.0.228 — vrať i čerstvý CSRF token, aby se frontend při každém loadu/reloadu
// sesynchronizoval se session (self-heal pro CSRF strict mód). whoami je GET → vždy projde.
$admin['csrf_token'] = csrf_token();
json_response($admin);
