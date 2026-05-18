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
function default_role_prava(): array {
    return [
        // Super admin — všechno
        'admin' => [
            'dashboard', 'objednavky', 'vyroba', 'dodaci_listy', 'faktury',
            'vyrobky', 'katalog', 'stitky', 'haccp', 'odberatele', 'nastaveni',
        ],
        // Prodavač — bez HACCP/Nastavení, vidí běžnou agendu
        'prodavac' => [
            'dashboard', 'objednavky', 'dodaci_listy', 'faktury',
            'vyrobky', 'katalog', 'stitky', 'odberatele',
        ],
        // Výroba — výrobní list + výrobky + HACCP
        'vyroba' => [
            'dashboard', 'vyroba', 'vyrobky', 'haccp',
        ],
        // Expedice — jen objednávky + dodací listy
        'expedice' => [
            'dashboard', 'objednavky', 'dodaci_listy',
        ],
    ];
}

if ($method === 'GET') {
    $defaults = default_role_prava();
    try {
        $row = $pdo->prepare("SELECT hodnota FROM nastaveni WHERE klic = 'role_menu_prava' LIMIT 1");
        $row->execute();
        $val = $row->fetchColumn();
        if ($val) {
            $custom = json_decode($val, true);
            if (is_array($custom)) {
                // Merge — custom má přednost, ale doplníme chybějící role z defaults
                foreach ($defaults as $role => $pages) {
                    if (!isset($custom[$role])) $custom[$role] = $pages;
                }
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
    $allowed_roles = ['admin', 'prodavac', 'vyroba', 'expedice'];
    $allowed_pages = [
        'dashboard', 'objednavky', 'vyroba', 'dodaci_listy', 'faktury',
        'vyrobky', 'katalog', 'stitky', 'haccp', 'odberatele', 'nastaveni',
    ];
    $clean = [];
    foreach ($prava as $role => $pages) {
        if (!in_array($role, $allowed_roles, true)) continue;
        if (!is_array($pages)) continue;
        // Super admin musí vždy vidět Dashboard + Users, jinak by se zablokoval z admin sekce
        $clean[$role] = array_values(array_unique(
            array_filter($pages, fn($p) => in_array($p, $allowed_pages, true))
        ));
        // Dashboard je vždy povinný (úvod) — vrať ho pokud chybí
        if (!in_array('dashboard', $clean[$role], true)) {
            array_unshift($clean[$role], 'dashboard');
        }
    }

    // Admin role nesmí být omezený — vždy vidí všechno (bezpečnost)
    $clean['admin'] = default_role_prava()['admin'];

    try {
        $json = json_encode($clean, JSON_UNESCAPED_UNICODE);
        $pdo->prepare("
            INSERT INTO nastaveni (klic, hodnota) VALUES ('role_menu_prava', :v)
            ON DUPLICATE KEY UPDATE hodnota = :v2
        ")->execute(['v' => $json, 'v2' => $json]);
        json_response(['ok' => true, 'prava' => $clean]);
    } catch (Throwable $e) {
        error_log('admin_role_prava save: ' . $e->getMessage());
        json_error('Uložení selhalo: ' . $e->getMessage(), 500);
    }
}

json_error('Neznámá metoda');
