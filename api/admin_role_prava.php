<?php
/**
 * Správa práv v menu podle role.
 *
 * GET  → vrátí aktuální mapování { role => [page_keys] }
 * POST → uloží nové mapování (super admin)
 *
 * Ukládá se do tabulky `nastaveni` pod klíčem `role_menu_prava` jako JSON.
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/_admin_auth.php';
cors_headers();
require_admin();

$pdo = db();
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

// Default mapování (pokud nic neuloženo) — kompletní seznam stránek pro každou roli
// 🐛 v3.0.644 — sjednoceno s DEFAULT_ROLE_PRAVA v admin/src/0260 (dřív chyběla role 'pos' a klíče
//   'nastroje'/'rozvozy' → server je při startu adminu odebral a editor práv je tiše zahodil).
function default_role_prava(): array {
    return [
        // Super admin — všechno
        'admin' => [
            'dashboard', 'objednavky', 'rozvozy', 'vyroba', 'dodaci_listy', 'faktury',
            'vyrobky', 'nastroje', 'katalog', 'stitky', 'haccp', 'odberatele', 'nastaveni',
        ],
        // Prodavač — bez HACCP/Nastavení, vidí běžnou agendu
        'prodavac' => [
            'dashboard', 'objednavky', 'rozvozy', 'dodaci_listy', 'faktury',
            'vyrobky', 'nastroje', 'katalog', 'stitky', 'odberatele',
        ],
        // Výroba — výrobní list + výrobky + HACCP
        'vyroba' => [
            'dashboard', 'vyroba', 'vyrobky', 'haccp',
        ],
        // Expedice — objednávky + dodací listy + rozvozy
        'expedice' => [
            'dashboard', 'objednavky', 'dodaci_listy', 'rozvozy',
        ],
        // POS kasa (bez pos_only) — v adminu stejné jako admin (viz v2.9.309 v JS); kiosk řeší pos_only
        'pos' => [
            'dashboard', 'objednavky', 'rozvozy', 'vyroba', 'dodaci_listy', 'faktury',
            'vyrobky', 'nastroje', 'katalog', 'stitky', 'haccp', 'odberatele', 'nastaveni',
        ],
    ];
}

// Všechny klíče, které jde roli přidělit (= ALL_NAV_PAGES v JS)
function role_prava_allowed_pages(): array {
    return [
        'dashboard', 'objednavky', 'rozvozy', 'vyroba', 'dodaci_listy', 'faktury',
        'vyrobky', 'nastroje', 'katalog', 'stitky', 'haccp', 'odberatele', 'nastaveni',
    ];
}

// Verze uloženého formátu — uložení před v3.0.644 nemohlo obsahovat 'nastroje'/'rozvozy'
// (server je zahazoval), takže jejich nepřítomnost není volba admina → doplň je z výchozích.
const ROLE_PRAVA_SCHEMA = 2;

if ($method === 'GET') {
    $defaults = default_role_prava();
    try {
        $row = $pdo->prepare("SELECT hodnota FROM nastaveni WHERE klic = 'role_menu_prava' LIMIT 1");
        $row->execute();
        $val = $row->fetchColumn();
        if ($val) {
            $custom = json_decode($val, true);
            if (is_array($custom)) {
                $schema = (int) ($custom['_schema'] ?? 1);
                unset($custom['_schema']);
                // Merge — custom má přednost, ale doplníme chybějící role z defaults
                foreach ($defaults as $role => $pages) {
                    if (!isset($custom[$role]) || !is_array($custom[$role])) { $custom[$role] = $pages; continue; }
                    if ($schema < ROLE_PRAVA_SCHEMA) {
                        foreach (['nastroje', 'rozvozy'] as $k) {
                            if (in_array($k, $pages, true) && !in_array($k, $custom[$role], true)) $custom[$role][] = $k;
                        }
                    }
                }
                $custom['admin'] = $defaults['admin']; // admin vždy vše
                json_response(['prava' => $custom, 'defaults' => $defaults]);
            }
        }
    } catch (Throwable $e) {
        // Tabulka může chybět — vrátíme defaults
    }
    json_response(['prava' => $defaults, 'defaults' => $defaults]);
}

if ($method === 'POST') {
    require_super_admin();
    $body = json_input();
    $prava = $body['prava'] ?? null;
    if (!is_array($prava)) json_error('Neplatná data');

    // Validuj — každá hodnota musí být pole stringů
    $allowed_roles = array_keys(default_role_prava()); // 🐛 v3.0.644 — vč. 'pos'
    $allowed_pages = role_prava_allowed_pages();         // 🐛 v3.0.644 — vč. 'nastroje'/'rozvozy'
    $ignorovano = [];
    $clean = [];
    foreach ($prava as $role => $pages) {
        if (!in_array($role, $allowed_roles, true)) { $ignorovano[] = (string) $role; continue; }
        if (!is_array($pages)) continue;
        // Super admin musí vždy vidět Dashboard + Users, jinak by se zablokoval z admin sekce
        $clean[$role] = array_values(array_unique(
            array_filter($pages, fn($p) => in_array($p, $allowed_pages, true))
        ));
        foreach ($pages as $p) {
            if (!in_array($p, $allowed_pages, true)) $ignorovano[] = $role . ':' . (is_scalar($p) ? (string) $p : '?');
        }
        // Dashboard je vždy povinný (úvod) — vrať ho pokud chybí
        if (!in_array('dashboard', $clean[$role], true)) {
            array_unshift($clean[$role], 'dashboard');
        }
    }

    // Admin role nesmí být omezený — vždy vidí všechno (bezpečnost)
    $clean['admin'] = default_role_prava()['admin'];

    // Role, které klient neposlal, doplň z výchozích (ať se neztratí)
    foreach (default_role_prava() as $role => $pages) {
        if (!isset($clean[$role])) $clean[$role] = $pages;
    }

    try {
        $json = json_encode($clean + ['_schema' => ROLE_PRAVA_SCHEMA], JSON_UNESCAPED_UNICODE);
        $pdo->prepare("
            INSERT INTO nastaveni (klic, hodnota) VALUES ('role_menu_prava', :v)
            ON DUPLICATE KEY UPDATE hodnota = :v2
        ")->execute(['v' => $json, 'v2' => $json]);
        json_response(['ok' => true, 'prava' => $clean, 'ignorovano' => array_values(array_unique($ignorovano))]);
    } catch (Throwable $e) {
        error_log('admin_role_prava save: ' . $e->getMessage());
        json_error_safe('Uložení selhalo', $e, 500);
    }
}

json_error('Neznámá metoda');
