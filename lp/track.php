<?php
/**
 * 🎯 LANDING FUNNEL TRACKER — počítá zhlédnutí a kliky per varianta prodejní stránky.
 *
 * Volá se beaconem z landingu (lp/*.html):
 *   /lp/track.php?e=view|checkout_click|demo_click&v=<varianta>
 *
 * Agreguje do vendor_landing_stats (varianta × den × počítadla). Objednávky (zaplaceno)
 * se počítají zvlášť z vendor_shop_orders.landing_variant (přijdou async přes platbu).
 *
 * Tiché a fail-safe: návštěvník nikdy neuvidí chybu, vždy vrátí 1×1 GIF.
 * Same-origin (appek.cz) → žádné CORS. sendBeacon (POST) i <img> (GET) čtou z $_GET.
 */

header('Content-Type: image/gif');
header('Cache-Control: no-store, no-cache, must-revalidate');

// 1×1 transparentní GIF (pošli vždy, ať beacon/img nehlásí chybu)
$gif = base64_decode('R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7');

$e = preg_replace('/[^a-z_]/', '', strtolower((string) ($_GET['e'] ?? '')));
$v = preg_replace('/[^a-z0-9_-]/', '', strtolower((string) ($_GET['v'] ?? '')));

// Whitelist událost → sloupec (bezpečné pro interpolaci do SQL)
$colMap = ['view' => 'views', 'checkout_click' => 'checkout_clicks', 'demo_click' => 'demo_clicks'];
$col = $colMap[$e] ?? null;

if ($col !== null && $v !== '' && strlen($v) <= 32) {
    try {
        require_once __DIR__ . '/../vendor/_lib.php';
        $db = vendor_db();
        $db->exec("CREATE TABLE IF NOT EXISTS vendor_landing_stats (
            variant VARCHAR(32) NOT NULL,
            day DATE NOT NULL,
            views INT NOT NULL DEFAULT 0,
            checkout_clicks INT NOT NULL DEFAULT 0,
            demo_clicks INT NOT NULL DEFAULT 0,
            PRIMARY KEY (variant, day)
        )");
        $st = $db->prepare("INSERT INTO vendor_landing_stats (variant, day, `$col`)
                            VALUES (:v, CURDATE(), 1)
                            ON DUPLICATE KEY UPDATE `$col` = `$col` + 1");
        $st->execute([':v' => $v]);
    } catch (Throwable $ex) { /* tiché — tracking nesmí shodit landing */ }
}

echo $gif;
