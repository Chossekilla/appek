<?php
/**
 * 🎁 PACKAGES LIB — sdílené helpery (bez side-effectů jako handlery).
 *
 * Použij místo include admin_packages.php (které okamžitě fires handlers).
 */

require_once __DIR__ . '/_license.php';

function packages_catalog_def(): array {
    return [
        'core'        => ['nazev' => 'Core',           'always_on' => true],
        'cukrarna'    => ['nazev' => 'Cukrárna',       'always_on' => false],
        'lahudky'     => ['nazev' => 'Lahůdkárna',     'always_on' => false],
        'restaurace'  => ['nazev' => 'Restaurace',     'always_on' => false],
        'catering'    => ['nazev' => 'Catering',       'always_on' => false],
        'sezona'      => ['nazev' => 'Sezónní',        'always_on' => false],
    ];
}

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
        if (!license_has_package($key)) return false;
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
