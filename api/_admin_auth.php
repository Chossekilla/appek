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
// 🆕 licenční enforcement — license_full_lock() pro rental hard-lock v require_admin()
require_once __DIR__ . '/_license_enforce.php';

function require_admin(): array {
    session_secure_start();
    if (empty($_SESSION['admin_id'])) {
        json_error('Vyžadováno admin přihlášení', 401);
    }
    // 🆕 PRONÁJEM vypršel (po grace) → HARD-LOCK celé appky (čtení i zápis). Obnova na my.appek.cz.
    //   Allowlist self-maintenance (heartbeat/whoami/version/js-error), aby refresh licence appku
    //   po prodloužení SÁM odemkl (jinak by po zaplacení zůstala zamčená).
    if (function_exists('license_full_lock') && license_full_lock()) {
        // 🐛 v3.0.644 — + admin_license_update.php (vložení nového klíče musí jít i v zámku)
        $lockAllow = ['license_heartbeat.php', 'whoami.php', 'version.php', 'admin_klient_chyby.php', 'admin_license_update.php'];
        $script = basename((string) ($_SERVER['SCRIPT_NAME'] ?? ($_SERVER['PHP_SELF'] ?? '')));
        if (!in_array($script, $lockAllow, true)) {
            // 🐛 v3.0.644 — license_lock marker: klient odliší od 402 „balíček není v licenci" a spustí ověření
            json_response(['error' => 'Pronájem APPEK vypršel — obnov přístup na https://my.appek.cz', 'license_lock' => 'rental'], 402);
        }
    }
    // 🐛 v3.0.644 — stav licence locked/revoked (z heartbeatu: key_reuse / unknown_key / odvolaný klíč)
    //   se dřív na serveru NEvynucoval (license_enforce_check nikdo nevolal) → blokoval jen klientský
    //   overlay, přímá API volání / POS jela dál. Teď 423 se stejným allowlistem jako u pronájmu
    //   (heartbeat = self-heal po vyřešení u dodavatele, license_update = vložení nového klíče).
    //   Dev/localhost výjimka je uvnitř license_enforce_check().
    if (function_exists('license_enforce_check')) {
        $enf = license_enforce_check();
        if (empty($enf['ok'])) {
            $lockAllow = ['license_heartbeat.php', 'whoami.php', 'version.php', 'admin_klient_chyby.php', 'admin_license_update.php'];
            $script = basename((string) ($_SERVER['SCRIPT_NAME'] ?? ($_SERVER['PHP_SELF'] ?? '')));
            if (!in_array($script, $lockAllow, true)) {
                json_response(['error' => 'Licence APPEK je zablokována — vložte platný licenční klíč nebo kontaktujte dodavatele.', 'license_lock' => (string) ($enf['state'] ?? 'locked')], 423);
            }
        }
    }
    // 🔒 v3.0.315 SECURITY: POS-only účet (přihlášený PINem) smí JEN POS endpointy.
    //   Dřív PIN login obešel blokádu z heslo-loginu a dostal se na všechny require_admin
    //   endpointy (privilege escalation). pos_only=0 účty (plný admin) jsou bez omezení.
    if (!empty($_SESSION['pos_only_user'])) {
        $posAllowed = ['admin_pos.php', 'admin_pos_presets.php', 'admin_pos_print.php',
            'admin_tables.php', 'admin_vouchers.php', 'admin_klient_chyby.php', 'admin_kitchen.php',
            'admin_stanice.php', // 🆕 heartbeat zařízení (jen ping; správa gated na plný admin uvnitř)
            'pay_qr.php', 'payment_methods.php', 'pos_auth.php', 'version.php', 'firma_branding.php', 'whoami.php'];
        $script = basename((string) ($_SERVER['SCRIPT_NAME'] ?? ($_SERVER['PHP_SELF'] ?? '')));
        if (!in_array($script, $posAllowed, true)) {
            json_error('POS účet nemá přístup k této části administrace', 403);
        }
    }
    // 🆕 READ-ONLY role (náhledový účet / veřejné demo): smí JEN číst.
    //   Blokuje všechny mutace (POST/PUT/DELETE/PATCH) v jednom centrálním bodě.
    //   Výjimka = neškodná self-maintenance (heartbeat, JS-error log, whoami),
    //   jinak by náhledová session házela 403 na pozadí. GET/HEAD projdou → vše se dá prohlédnout.
    //   INERTNÍ dokud žádný účet nemá role='readonly'.
    if (($_SESSION['admin_role'] ?? '') === 'readonly') {
        // 🔒 v3.0.611 — veřejné DEMO (APPEK_DEMO_MODE): HACCP zamčený i pro ČTENÍ (šablony dokumentace = know-how);
        //   jen náhledový účet na demo instalaci, zákaznické readonly účty ani admin na demu netknuté.
        if (defined('APPEK_DEMO_MODE') && APPEK_DEMO_MODE) {
            $haccpLocked = ['admin_haccp_autofill.php', 'admin_haccp_dokumenty.php', 'admin_haccp_export_csv.php',
                            'admin_haccp_grafy.php', 'firemni_haccp.php', 'vyrobek_haccp.php'];
            $scriptRo = basename((string) ($_SERVER['SCRIPT_NAME'] ?? ($_SERVER['PHP_SELF'] ?? '')));
            if (in_array($scriptRo, $haccpLocked, true)) {
                json_error('HACCP je v demu zamčený — dostupné v plné verzi. Napiš na info@appek.cz', 403);
            }
        }
        $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
        if (in_array($method, ['POST', 'PUT', 'DELETE', 'PATCH'], true)) {
            $roAllowed = ['license_heartbeat.php', 'admin_klient_chyby.php', 'whoami.php'];
            $script = basename((string) ($_SERVER['SCRIPT_NAME'] ?? ($_SERVER['PHP_SELF'] ?? '')));
            // 🐛 v3.0.599 — heartbeat zařízení (ping) je self-maintenance → jinak 403 každých pár sekund
            //   (diagnostika měření). Jen akce ping; správa stanic (přejmenování/smazání) zůstává zamčená.
            $isPing = $script === 'admin_stanice.php' && (($_GET['action'] ?? '') === 'ping');
            // v3.0.614 — výpočet ceny v konfigurátoru dortů / catering kalkulačce je čistý výpočet (POST kvůli tělu),
            //   nic neukládá → náhledový účet ho smí (jinak demo neukáže cenu). create_order zůstává zamčené.
            $isQuote = in_array($script, ['admin_cake_configurator.php', 'admin_catering_calc.php'], true)
                    && (($_GET['action'] ?? '') === 'quote');
            if (!in_array($script, $roAllowed, true) && !$isPing && !$isQuote) {
                json_error('Náhledový účet — jen prohlížení. Plný přístup na vyžádání: info@appek.cz', 403);
            }
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
