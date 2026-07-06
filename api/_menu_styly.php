<?php
/**
 * 🎨 VZHLEDOVÉ ŠABLONY TÝDENNÍHO MENU (v3.0.412).
 *
 * Jeden zdroj pravdy pro vzhled veřejné stránky (menu_public.php),
 * tisku (A4) i e-mailové rozesílky. Styl = klíč restaurace|kavarna|brunch.
 * Volá: api/menu_public.php, api/admin_tydenni_menu.php (tisk + rozeslat).
 */

/** Definice všech stylů (klíč → paleta). */
function menu_styly_defs(): array {
    return [
        'restaurace' => [
            'label'   => '🍽️ Restaurace',
            'popis'   => 'Teplá jantarová klasika',
            'bg'      => '#F8F5F0', 'card' => '#FFFFFF',
            'grad_a'  => '#BA7517', 'grad_b' => '#854F0B',
            'accent'  => '#854F0B', 'accent_soft' => '#F0D9B8',
            'text'    => '#1d1d1f', 'muted' => '#777',
            'font'    => "-apple-system, 'Segoe UI', Roboto, sans-serif",
            'nadpis'  => '🗓️',
        ],
        'kavarna' => [
            'label'   => '☕ Kavárna',
            'popis'   => 'Elegantní espresso / mocha tóny',
            'bg'      => '#F4EFEA', 'card' => '#FFFDFB',
            'grad_a'  => '#6F4E37', 'grad_b' => '#3E2723',
            'accent'  => '#5D4037', 'accent_soft' => '#D7CCC8',
            'text'    => '#2E2420', 'muted' => '#8D7B6E',
            'font'    => "Georgia, 'Times New Roman', serif",
            'nadpis'  => '☕',
        ],
        'brunch' => [
            'label'   => '🥐 Brunch',
            'popis'   => 'Svěží pastelový víkendový vzhled',
            'bg'      => '#FFF9F0', 'card' => '#FFFFFF',
            'grad_a'  => '#F59E0B', 'grad_b' => '#EA580C',
            'accent'  => '#C2410C', 'accent_soft' => '#FED7AA',
            'text'    => '#292524', 'muted' => '#A8A29E',
            'font'    => "'Trebuchet MS', 'Segoe UI', sans-serif",
            'nadpis'  => '🥐',
        ],
    ];
}

/** 🆕 v3.0.413 — ROZLOŽENÍ (layout, nezávislé na barevné paletě). */
function menu_layouty_defs(): array {
    return [
        'karta'   => ['label' => '🃏 Karta',       'popis' => 'Mobilní karta s gradientem (výchozí)'],
        'klasik'  => ['label' => '📋 Klasik bistro','popis' => 'Denní menu — dny pod sebou, alergeny, cena dole'],
        'elegant' => ['label' => '✨ Elegant',      'popis' => 'Fine-dining — serif, tečkované vodítko, vzdušné'],
        'sloupce' => ['label' => '🗞️ Editorial',    'popis' => 'Specialita týdne + dvousloupcové sekce'],
        'tabule'  => ['label' => '🖤 Tabule',       'popis' => 'Tmavá tabule / křídový vzhled'],
    ];
}
function menu_layout_key(?string $key): string {
    $key = strtolower(trim((string) $key));
    return isset(menu_layouty_defs()[$key]) ? $key : 'karta';
}

/** Sanitizace klíče stylu → vždy platný klíč (default restaurace). */
function menu_styl_key(?string $key): string {
    $key = strtolower(trim((string) $key));
    return isset(menu_styly_defs()[$key]) ? $key : 'restaurace';
}

/** Paleta pro daný styl (fallback restaurace). */
function menu_styl(?string $key): array {
    $defs = menu_styly_defs();
    return $defs[menu_styl_key($key)];
}
