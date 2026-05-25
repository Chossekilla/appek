<?php
/**
 * 🔒 CSRF — Cross-Site Request Forgery protection (v2.6.0 SECURITY).
 *
 * Použití (v admin endpointu):
 *   require_once __DIR__ . '/_csrf.php';
 *   csrf_require();         // Strict check — die() při chybě
 *   // … nebo …
 *   if (!csrf_check()) { json_error('csrf_invalid', 403); }
 *
 * Token se vygeneruje při admin loginu a uloží do session.
 * Frontend ho čte z meta tagu / cookie a posílá v X-CSRF-Token headeru
 * NEBO v form fieldu `csrf_token`.
 *
 * Pro JSON XHR: header `X-CSRF-Token: <hash>`
 * Pro classic form POST: hidden input `<input name="csrf_token" value="<hash>">`
 *
 * GET requesty CSRF nemusí — měly by být idempotentní (žádné side-effects).
 */

if (!function_exists('csrf_token')) {
    /**
     * Vygeneruj/vrat session-bound CSRF token (32 hex chars).
     */
    function csrf_token(): string {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            session_start();
        }
        if (empty($_SESSION['_csrf_token'])) {
            $_SESSION['_csrf_token'] = bin2hex(random_bytes(16));
        }
        return $_SESSION['_csrf_token'];
    }

    /**
     * Ověř CSRF token z requestu (header X-CSRF-Token nebo POST 'csrf_token').
     * @return bool true pokud token sedí
     */
    function csrf_check(): bool {
        if (session_status() !== PHP_SESSION_ACTIVE) session_start();
        $expected = $_SESSION['_csrf_token'] ?? '';
        if (!$expected) return false;

        $provided = $_SERVER['HTTP_X_CSRF_TOKEN']
                 ?? $_POST['csrf_token']
                 ?? '';

        // Pokus z JSON body
        if (!$provided && in_array($_SERVER['REQUEST_METHOD'] ?? '', ['POST','PUT','DELETE'], true)) {
            $raw = file_get_contents('php://input');
            $body = json_decode($raw, true);
            if (is_array($body) && !empty($body['csrf_token'])) {
                $provided = $body['csrf_token'];
            }
        }
        return $provided && hash_equals($expected, $provided);
    }

    /**
     * Strict: failuj při neplatném tokenu.
     * Pro JSON endpoints (admin_*.php) — vrátí 403 + JSON.
     */
    function csrf_require(): void {
        // GET requesty jsou bezpečné — žádný CSRF check.
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET') return;

        if (!csrf_check()) {
            http_response_code(403);
            $isJson = str_contains($_SERVER['HTTP_ACCEPT'] ?? '', 'application/json')
                   || str_contains($_SERVER['HTTP_CONTENT_TYPE'] ?? $_SERVER['CONTENT_TYPE'] ?? '', 'application/json');
            if ($isJson) {
                header('Content-Type: application/json; charset=UTF-8');
                echo json_encode(['error' => 'csrf_invalid', 'message' => 'Missing or invalid CSRF token. Refresh login page.']);
            } else {
                header('Content-Type: text/plain; charset=UTF-8');
                echo "403 Forbidden — CSRF token mismatch.\nPlease log in again.";
            }
            exit;
        }
    }
}
