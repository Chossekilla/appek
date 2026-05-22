<?php
/**
 * 🎁 FEATURE FLAGS — Mapování feature → package.
 *
 * Každá funkce v aplikaci může být gated podle balíčku v licenci.
 * Pokud zákazník nemá balíček, funkce není dostupná (nebo zobrazí upsell).
 *
 * Použití:
 *
 *   require_once __DIR__ . '/_features.php';
 *   if (!feature_enabled('cake_configurator')) {
 *       echo feature_upsell_html('cake_configurator');
 *       exit;
 *   }
 *
 * Pro JS (admin frontend):
 *   const features = await fetch('/api/features_status.php').then(r => r.json());
 *   if (!features.cake_configurator) showUpsell('cukrarna');
 */

require_once __DIR__ . '/_license.php';

/**
 * Mapa: feature_key → balíček který ji odemyká.
 * 'core' = vždy dostupné (každá licence má core).
 *
 * Při PŘIDÁVÁNÍ nové funkce do balíčku:
 *   1. Přidej do této mapy (klíč funkce → balíček)
 *   2. V kódu funkce zavolej feature_enabled($key) gating
 *   3. V update bundle (build-update.sh) → push do produkce
 *   4. Zákazníci se správným balíčkem ji uvidí, ostatní upsell
 */
const FEATURE_MAP = [
    // ─── CORE (vždy zahrnuto) ───
    'orders'              => 'core',
    'invoices'            => 'core',
    'production'          => 'core',
    'haccp_basic'         => 'core',
    'price_tags_basic'    => 'core',
    'b2b_portal'          => 'core',
    'ares_lookup'         => 'core',
    'isdoc_export'        => 'core',
    'delivery_routes'     => 'core',  // vlastní rozvozy (vždy)
    'pwa_offline'         => 'core',
    'csv_export'          => 'core',
    'updates_check'       => 'core',

    // ─── CUKRÁRNA ───
    'cake_configurator'   => 'cukrarna',     // konfigurátor dortů (tvar, příchuť, dekorace)
    'patisserie_recipes'  => 'cukrarna',     // databáze cukrářských receptů
    'patisserie_pricing'  => 'cukrarna',     // ceník per dort
    'wedding_orders'      => 'cukrarna',     // svatební objednávky template

    // ─── LAHŮDKY ───
    'deli_grammage'       => 'lahudky',      // gramáže pro krabičkování
    'cold_chain_log'      => 'lahudky',      // detailní log studeného řetězce
    'allergen_matrix'     => 'lahudky',      // alergenová matice

    // ─── RESTAURACE ───
    'menu_engineering'    => 'restaurace',   // analýza ziskovosti menu
    'table_reservations'  => 'restaurace',   // rezervační systém stolů
    'kitchen_display'     => 'restaurace',   // KDS — kitchen display system
    'pos_integration'     => 'restaurace',   // POS pokladna napojení

    // ─── CATERING ───
    'event_planner'       => 'catering',     // kalkulačka eventů (počet hostů → potřebné zdroje)
    'banket_sets'         => 'catering',     // banketové sety / cenové úrovně
    'catering_logistics'  => 'catering',     // rozšířená logistika rozvozů
    'guest_count_calc'    => 'catering',     // přepočet porcí na počet hostů

    // ─── SEZÓNNÍ AKCE ───
    'popup_events'        => 'sezona',       // pop-up trhy & akce
    'seasonal_pricing'    => 'sezona',       // sezónní cenové úrovně
    'christmas_orders'    => 'sezona',       // vánoční objednávky template
    'easter_orders'       => 'sezona',       // velikonoční template
];

/**
 * Hlavní gating funkce.
 *
 * @param string $featureKey Klíč funkce v FEATURE_MAP
 * @return bool true pokud zákazník má balíček odemykající tuto funkci
 */
function feature_enabled(string $featureKey): bool {
    $requiredPackage = FEATURE_MAP[$featureKey] ?? 'core';

    // Core funkce jsou vždy dostupné (každá licence má core)
    if ($requiredPackage === 'core') return true;

    return license_has_package($requiredPackage);
}

/**
 * Vrátí jméno balíčku potřebného pro funkci (pro upsell zprávu).
 */
function feature_required_package(string $featureKey): ?string {
    $pkg = FEATURE_MAP[$featureKey] ?? null;
    return ($pkg && $pkg !== 'core') ? $pkg : null;
}

/**
 * HTML komponenta pro upsell — když zákazník chce funkci kterou nemá.
 */
function feature_upsell_html(string $featureKey): string {
    $pkg = feature_required_package($featureKey);
    if (!$pkg) return '';

    $packageNames = [
        'cukrarna'   => ['name' => '🎂 Cukrárna',     'price' => '2 990 Kč'],
        'lahudky'    => ['name' => '🥪 Lahůdky',      'price' => '2 490 Kč'],
        'restaurace' => ['name' => '🍽️ Restaurace',   'price' => '3 490 Kč'],
        'catering'   => ['name' => '🎉 Catering',     'price' => '2 990 Kč'],
        'sezona'     => ['name' => '🍂 Sezónní akce', 'price' => '1 990 Kč'],
    ];
    $info = $packageNames[$pkg] ?? ['name' => ucfirst($pkg), 'price' => '—'];

    return <<<HTML
<div class="feature-upsell" style="
  max-width: 480px; margin: 30px auto; padding: 28px 32px;
  background: linear-gradient(135deg, #fff, #fafafa);
  border: 1px solid #e5e5e7; border-radius: 16px;
  text-align: center; box-shadow: 0 4px 16px rgba(0,0,0,0.06);
  font-family: -apple-system, sans-serif;
">
  <div style="font-size:48px;margin-bottom:14px">🔒</div>
  <h3 style="font-size:18px;margin:0 0 8px;color:#1d1d1f">Tato funkce vyžaduje balíček</h3>
  <p style="font-size:14px;color:#6e6e73;line-height:1.6;margin:0 0 18px">
    Funkce <code style="background:#f5f5f7;padding:2px 6px;border-radius:4px">{$featureKey}</code>
    je součástí balíčku <strong>{$info['name']}</strong>.
  </p>
  <div style="background:rgba(186,117,23,0.08);padding:14px 18px;border-radius:10px;margin-bottom:18px">
    <div style="font-size:11px;color:#86868b;text-transform:uppercase;letter-spacing:0.4px">Cena balíčku</div>
    <div style="font-size:24px;font-weight:800;color:#BA7517">{$info['price']}</div>
    <div style="font-size:12px;color:#86868b">Jednorázově, doživotně</div>
  </div>
  <a href="https://appek.cz/checkout.html?addon={$pkg}" target="_blank" style="
    display: inline-block; padding: 12px 28px;
    background: linear-gradient(180deg, #BA7517, #854F0B);
    color: #fff; text-decoration: none; border-radius: 999px;
    font-weight: 600; font-size: 14px;
  ">🔓 Dokoupit balíček</a>
</div>
HTML;
}

/**
 * Vrátí seznam všech features dostupných zákazníkovi (pro JS frontend).
 */
function feature_status_all(): array {
    $status = [];
    foreach (FEATURE_MAP as $key => $pkg) {
        $status[$key] = feature_enabled($key);
    }
    return $status;
}
