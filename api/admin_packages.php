<?php
/**
 * 🎁 BALÍČKY FUNKCÍ — feature flag systém pro modulární licencování.
 *
 * Endpointy:
 *   GET  /api/admin_packages.php           — vrátí všechny balíčky + aktuální stav (on/off)
 *   POST /api/admin_packages.php           — body { key, enabled } — toggle
 *   GET  /api/admin_packages.php?check=KEY — vrátí jen 1 = on / 0 = off
 *
 * Uložiště: `nastaveni.packages_enabled` (JSON object, mapuje package key → bool).
 *
 * Backend helper (pro ostatní soubory): `package_enabled(string $key): bool`
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/_admin_auth.php';
require_once __DIR__ . '/_license.php';
require_once __DIR__ . '/_packages_lib.php';
cors_headers();
require_admin();
header('Content-Type: application/json; charset=UTF-8');

$pdo = db();
$method = $_SERVER['REQUEST_METHOD'];

// ════════════════════════════════════════════════════════════
// KATALOG BALÍČKŮ — definice (přidávej sem nové)
// ════════════════════════════════════════════════════════════

function packages_catalog(): array {
    return [
        // Core — vždy zapnuto (uvedeno pro úplnost)
        'core' => [
            'nazev'       => 'Core (základ)',
            'ikona'       => '⭐',
            'cena_kc'     => 0,
            'mena'        => 'Kč',
            'opakovani'   => 'zdarma',
            'popis'       => 'Objednávky, dodací listy, faktury, výrobky, odběratelé, HACCP, sklad.',
            'features'    => ['Objednávky', 'Dodací listy', 'Faktury (ISDOC)', 'Výrobky a sklad', 'Odběratelé', 'HACCP', 'Push notifikace', 'REST API'],
            'always_on'   => true,
        ],
        'cukrarna' => [
            'nazev'       => 'Cukrárna',
            'ikona'       => '🧁',
            'cena_kc'     => 5000,
            'mena'        => 'Kč',
            'opakovani'   => 'ročně',
            'popis'       => 'Specifické funkce pro cukrárny a pečírny dortů.',
            'features'    => [
                '🎂 Konfigurátor dortů (porce → hmotnost → cena · text na dortu · foto předlohy)',
                '📅 Denní kapacita pečení (kalendář s "dnes max 12 dortů")',
                '🖼️ Foto galerie produktu (1 velký + 3 vedlejší)',
                '♻️ Evidence vratných stojanů na dorty',
            ],
        ],
        'lahudky' => [
            'nazev'       => 'Lahůdkárna',
            'ikona'       => '🥗',
            'cena_kc'     => 3000,
            'mena'        => 'Kč',
            'opakovani'   => 'ročně',
            'popis'       => 'Catering, šaržová evidence, mix-and-match objednávky.',
            'features'    => [
                '🍱 Catering kalkulátor (X osob → návrh chlebíčků/zákusků/nápojů)',
                '📋 Šaržová HACCP evidence (každá várka = vlastní šarže + DMT)',
                '🧩 Mix-and-match objednávka (zákazník volí ingredience na sendviči)',
                '🚚 Catering objednávky s časem dodání',
            ],
        ],
        'restaurace' => [
            'nazev'       => 'Restaurace / Pizzerie',
            'ikona'       => '🍕',
            'cena_kc'     => 4000,
            'mena'        => 'Kč',
            'opakovani'   => 'ročně',
            'popis'       => 'Stolová správa, kapacita kuchyně, rozvoz.',
            'features'    => [
                '🪑 Stolová správa (rezervace, mapa stolů)',
                '👨‍🍳 Kapacita kuchyně (max paralelních objednávek)',
                '⏱️ Doba přípravy per výrobek',
                '🛵 Vlastní rozvoz / spolupráce s kurýrkou',
            ],
        ],
        'catering' => [
            'nazev'       => 'Velký catering',
            'ikona'       => '🎉',
            'cena_kc'     => 2500,
            'mena'        => 'Kč',
            'opakovani'   => 'ročně',
            'popis'       => 'Velkokapacitní objednávky pro firmy a akce.',
            'features'    => [
                '🏢 Firemní objednávky (faktura na IČO, vlastní cenové úrovně)',
                '👥 Cenové úrovně podle počtu osob',
                '📑 Generování smluv + nabídek (PDF)',
                '🎯 Předzaplacená záloha (50%)',
            ],
        ],
        'sezona' => [
            'nazev'       => 'Sezónní balíček',
            'ikona'       => '🍰',
            'cena_kc'     => 1500,
            'mena'        => 'Kč',
            'opakovani'   => 'ročně',
            'popis'       => 'Speciální módy pro Velikonoce, Vánoce, sv. Valentýna.',
            'features'    => [
                '🐰 Sezónní katalog (auto on/off podle data)',
                '🎄 Předobjednávky s deadlinem',
                '🎁 Dárkové balení (vrstva + cena)',
                '🏷️ Sezónní akce a slevy',
            ],
        ],
    ];
}

// Funkce package_enabled() je teď v _packages_lib.php (sdílená bez side-effectů).
// packages_state() — local helper:
if (!function_exists('packages_state')) {
    function packages_state(PDO $pdo): array {
        return packages_state_get($pdo); // alias na lib funkci
    }
}

// ════════════════════════════════════════════════════════════
// HANDLERS
// ════════════════════════════════════════════════════════════

// Quick check ?check=KEY
if ($method === 'GET' && !empty($_GET['check'])) {
    json_response(['enabled' => package_enabled($_GET['check']) ? 1 : 0]);
}

if ($method === 'GET') {
    $catalog = packages_catalog();
    $state = packages_state($pdo);
    $licStatus = license_status();
    $licensedPkgs = $licStatus['packages'] ?? ['core'];
    $out = [];
    foreach ($catalog as $key => $pkg) {
        $pkg['key']      = $key;
        $pkg['licensed'] = !empty($pkg['always_on']) || in_array($key, $licensedPkgs, true);
        $pkg['enabled']  = !empty($pkg['always_on']) || (!empty($state[$key]) && $pkg['licensed']);
        $out[] = $pkg;
    }
    json_response([
        'packages' => $out,
        'license' => [
            'ok'           => $licStatus['ok'],
            'masked'       => license_masked($licStatus['key']),
            'licensed_pkgs' => $licensedPkgs,
            // 🆕 v3.0.301 — stav roční platnosti (expiring_soon/grace/expired) pro upozornění + gating balíčků
            'validity'     => (function () { $f = __DIR__ . '/_license_enforce.php'; if (is_file($f)) require_once $f; return function_exists('license_validity') ? license_validity() : ['expiry_state' => 'active', 'valid_until' => null, 'days_left' => null, 'packages_active' => true]; })(),
        ],
    ]);
}

if ($method === 'POST') {
    require_super_admin();
    $d = json_input();
    $key = $d['key'] ?? '';
    $enabled = !empty($d['enabled']);
    $catalog = packages_catalog();
    if (!isset($catalog[$key])) json_error('Neznámý balíček', 404);
    if (!empty($catalog[$key]['always_on'])) json_error('Tenhle balíček je always-on a nelze vypnout', 400);
    // 🔒 Gate: pokud balíček není v licenčním klíči, nelze aktivovat
    if ($enabled && !license_has_package($key)) {
        json_error('Tento balíček není v tvé licenci. Kontaktuj dodavatele pro upgrade klíče.', 402);
    }

    $state = packages_state($pdo);
    $state[$key] = $enabled;

    try {
        $payload = json_encode($state, JSON_UNESCAPED_UNICODE);
        $pdo->prepare("
            INSERT INTO nastaveni (klic, hodnota) VALUES ('packages_enabled', :v)
            ON DUPLICATE KEY UPDATE hodnota = :v2
        ")->execute(['v' => $payload, 'v2' => $payload]);
        json_response(['ok' => true, 'enabled' => $enabled]);
    } catch (Throwable $e) {
        json_error_safe('Chyba uložení', $e, 500);
    }
}

json_error('Neznámá metoda', 405);
