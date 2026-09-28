<?php
/**
 * 🎨 LANDING VARIANT ROUTER — servíruje aktivní variantu prodejní stránky.
 *
 * Ruční přepínač: vendor vybere aktivní variantu (vendor_settings.landing_variant),
 * všichni návštěvníci dostanou tu samou. Měření konverze per varianta zvlášť
 * (lp/track.php + vendor_shop_orders.landing_variant).
 *
 * 📊 GOOGLE KÓDY: měřicí značky (GA4 / Ads / GTM) se NEHARDKÓDUJÍ do HTML —
 * čtou se z vendor_settings (vendor → Stránky → „Google kódy") a vkládají se
 * na místo markeru `<!--GOOGLE_TAGS-->` v servírované HTML. Jedno místo pro
 * celý web, přepnutí kódu ve vendoru je vidět okamžitě (.php se necachuje).
 * Consent Mode v2 (vše 'denied' dokud návštěvník neodsouhlasí) → GDPR OK.
 *
 * Aktivuje se JEN na apexu (appek.cz) — .htaccess přepíše `/` → landing.php pouze
 * když existuje marker `.landing-only`. Zákaznické instalace (bez markeru) tento
 * soubor nikdy nespustí, servírují svůj index.html napřímo.
 *
 * PHP schválně — hcdn CDN necachuje .php → přepnutí varianty ve vendoru je vidět
 * okamžitě. Fail-safe: cokoli selže → 'classic' (původní index.html), bez značek.
 *
 * Preview bez přepnutí pro všechny: appek.cz/?lp=<varianta>
 */

$variant = 'classic';
$google  = ['ga4' => '', 'ads' => '', 'label' => '', 'gtm' => ''];

// ─── Vendor settings (varianta + Google kódy) — jeden dotaz, fail-safe ───
$settings = [];
if (is_dir(__DIR__ . '/vendor')) {
    try {
        require_once __DIR__ . '/vendor/_lib.php';
        $settings = vendor_db()->query(
            "SELECT `key`, `value` FROM vendor_settings
             WHERE `key` IN ('landing_variant','google_ga4_id','google_ads_id','google_ads_conv_label','google_gtm_id')"
        )->fetchAll(PDO::FETCH_KEY_PAIR) ?: [];
    } catch (Throwable $e) { $settings = []; /* fail-safe */ }
}

// Preview override z URL (?lp=modernist) — nepřepíná nic pro ostatní návštěvníky
if (!empty($_GET['lp'])) {
    $variant = preg_replace('/[^a-z0-9_-]/', '', strtolower((string) $_GET['lp']));
} elseif (!empty($settings['landing_variant'])) {
    $variant = preg_replace('/[^a-z0-9_-]/', '', strtolower((string) $settings['landing_variant']));
}

// Google ID sanitizace (povolené jen [A-Za-z0-9_-], tvar G-…, AW-…, GT-…, label)
$sid = static fn($s) => preg_replace('/[^A-Za-z0-9_-]/', '', (string) $s);
$google['ga4']   = $sid($settings['google_ga4_id']        ?? '');
$google['ads']   = $sid($settings['google_ads_id']        ?? '');
$google['label'] = $sid($settings['google_ads_conv_label'] ?? '');
$google['gtm']   = $sid($settings['google_gtm_id']        ?? '');

// Mapa variant → soubor. 'classic' = původní index.html; ostatní = lp/<varianta>.html
// (rozšiřitelné: stačí přidat lp/<nová>.html a vybrat ji ve vendoru).
if ($variant === 'classic' || $variant === '') {
    $file = __DIR__ . '/index.html';
} else {
    $cand = __DIR__ . '/lp/' . $variant . '.html';
    $file = is_file($cand) ? $cand : __DIR__ . '/index.html';
}
if (!is_file($file)) { $file = __DIR__ . '/index.html'; }

header('Content-Type: text/html; charset=UTF-8');
header('Cache-Control: no-cache, must-revalidate');

// Vlož Google značky na místo markeru. Když se čtení nepovede → servíruj napřímo.
$html = @file_get_contents($file);
if ($html === false) { readfile($file); exit; }
echo str_replace('<!--GOOGLE_TAGS-->', appek_build_google_tag_block($google), $html);

/**
 * Sestaví <head> blok Google značek z vendor kódů (Consent Mode v2).
 * Prázdné vstupy → prázdný string (žádné značky, žádné zbytečné requesty).
 */
function appek_build_google_tag_block(array $g): string
{
    if ($g['ga4'] === '' && $g['ads'] === '' && $g['gtm'] === '') return '';

    // Primární loader = Ads (konverze), jinak GA4, jinak GTM/Google tag.
    $loader = $g['ads'] !== '' ? $g['ads'] : ($g['ga4'] !== '' ? $g['ga4'] : $g['gtm']);

    $out  = "<!-- Google tag (gtag.js) — vendor-managed (vendor/pages-editor.php → Google kódy) — Consent Mode v2 -->\n";
    $out .= "<script async src=\"https://www.googletagmanager.com/gtag/js?id={$loader}\"></script>\n";
    $out .= "<script>\n";
    $out .= "  window.dataLayer = window.dataLayer || [];\n";
    $out .= "  function gtag(){dataLayer.push(arguments);}\n";
    // Vše 'denied' dokud návštěvník neodsouhlasí (cookie lišta) → bezcookie pingy, GDPR OK.
    $out .= "  gtag('consent','default',{ad_storage:'denied',analytics_storage:'denied',ad_user_data:'denied',ad_personalization:'denied',wait_for_update:500});\n";
    $out .= "  gtag('js', new Date());\n";
    if ($g['ads'] !== '') $out .= "  gtag('config','{$g['ads']}');\n";
    if ($g['ga4'] !== '') {
        $out .= "  gtag('config','{$g['ga4']}',{anonymize_ip:true});\n";
        $out .= "  window.APPEK_GA_ID='{$g['ga4']}';\n";
    }
    if ($g['gtm'] !== '') $out .= "  gtag('config','{$g['gtm']}');\n";
    // Zpřístupni „AW-…/label" pro konverzní event (payment-done.html si může přečíst).
    if ($g['ads'] !== '' && $g['label'] !== '') $out .= "  window.APPEK_ADS_CONV='{$g['ads']}/{$g['label']}';\n";
    $out .= "</script>\n";
    $out .= "<!-- /Google tag -->";
    return $out;
}
