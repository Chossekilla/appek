<?php
/**
 * 🎁 PACKAGES LIB — sdílené helpery (bez side-effectů jako handlery).
 *
 * Použij místo include admin_packages.php (které okamžitě fires handlers).
 */

require_once __DIR__ . '/_license.php';


function packages_state_get(PDO $pdo): array {
    try {
        $raw = $pdo->query("SELECT hodnota FROM nastaveni WHERE klic = 'packages_enabled'")->fetchColumn();
        $state = $raw ? json_decode($raw, true) : [];
        return is_array($state) ? $state : [];
    } catch (Throwable $e) { return []; }
}

/**
 * Vrátí true pokud je balíček aktivní (v licenci + v customer toggle).
 */
if (!function_exists('package_enabled')) {
    function package_enabled(string $key): bool {
        if ($key === 'core') return true;
        // 🆕 v3.0.588 — Sezónní nabídky jsou zdarma (core): nevypne je toggle ani vypršení licence
        if ($key === 'sezona') return true;
        // 🆕 v3.0.588 — Velký catering je součástí Lahůdek: jede, když jedou Lahůdky
        //   (+ zpětně: kdo má v klíči starý samostatný Catering, má ho dál)
        if ($key === 'catering') {
            if (package_enabled('lahudky')) return true;
            // samostatný Catering jen podle SUROVÉHO bitu v klíči (efektivní seznam ho odvozuje z Lahůdek)
            $rawPk = function_exists('license_status') ? (license_status()['packages'] ?? []) : [];
            if (!in_array('catering', $rawPk, true)) return false;
        }
        if (!license_has_package($key)) return false;
        // 🆕 v3.0.301 — po vypršení roční licence (po grace období) se add-on balíčky VYPNOU
        //   (core/POS jede dál). Řídí valid_until z heartbeatu, viz _license_enforce.php.
        if (!function_exists('license_packages_active')) {
            $enf = __DIR__ . '/_license_enforce.php';
            if (is_file($enf)) { require_once $enf; }
        }
        if (function_exists('license_packages_active') && !license_packages_active()) {
            // 🎁 v3.0.647 — balíček zdarma ke core je součást core → po vypršení ROČNÍ licence jede dál.
            //   Pronájem po vypršení = vše zamčené (platí se měsíčně i za core) → výjimka neplatí.
            $lst = function_exists('license_state_load') ? license_state_load() : [];
            $free = strtolower((string) ($lst['free_package'] ?? ''));
            if (!empty($lst['rental']) || $free === '' || $free !== $key) return false;
        }
        static $cache = null;
        if ($cache === null) {
            try { $cache = packages_state_get(db()); }
            catch (Throwable $e) { $cache = []; }
        }
        // 💡 Pokud customer ještě nikdy nenastavil toggle (čerstvá instalace),
        //    default = ZAPNUTO pro vše, co je v licenci. Aktivní explicit OFF
        //    musí být false (ne empty), aby fungovalo vypnutí.
        if (!array_key_exists($key, $cache)) return true;
        return !empty($cache[$key]);
    }
}
