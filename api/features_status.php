<?php
/**
 * 🎁 FEATURES STATUS — JSON endpoint pro admin frontend.
 *
 * GET /api/features_status.php
 *   → { feature_key: true/false, ... }
 *
 * Admin JS pak může gated featurese skrýt/ukázat:
 *   const feat = await fetch('/api/features_status.php').then(r => r.json());
 *   if (!feat.cake_configurator) document.getElementById('cake-btn').style.display = 'none';
 */

// 🔧 v3.0.389 FIX: bez config.php není definovaná APP_LICENSE_KEY (z config.local.php),
//   takže license_has_package() vracelo core-only → tento endpoint hlásil VLASTNĚNÉ
//   balíčky jako nedostupné → admin UI je schovávalo. feature_*.php config.php includují,
//   proto gate fungoval správně (rozpor odhalil „gating gap" false-positive 2026-06).
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/_features.php';

header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-cache, must-revalidate');

echo json_encode([
    'license_packages' => function_exists('license_status') ? license_status()['packages'] ?? ['core'] : ['core'],
    'features'         => feature_status_all(),
    'feature_map'      => FEATURE_MAP,
], JSON_UNESCAPED_UNICODE);
