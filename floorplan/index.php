<?php
/**
 * 🗺️ APPEK FLOOR PLAN EDITOR — Standalone aplikace pro mapování stolů
 */

ini_set('display_errors', '0');
error_reporting(E_ALL);
set_error_handler(function($errno, $errstr, $errfile, $errline) {
    if (!(error_reporting() & $errno)) return false;
    fp_show_error('PHP Error', "$errstr\nv souboru: $errfile, řádek $errline");
});
set_exception_handler(function($e) {
    fp_show_error('Uncaught Exception', $e->getMessage() . "\nv souboru: " . $e->getFile() . ":" . $e->getLine());
});
function fp_show_error($title, $detail) {
    http_response_code(500);
    if (!headers_sent()) header('Content-Type: text/html; charset=utf-8');
    echo '<!DOCTYPE html><html lang="cs"><head><meta charset="utf-8"><title>Floor Plan — chyba</title>'
       . '<style>body{font-family:-apple-system,sans-serif;max-width:720px;margin:60px auto;padding:24px;color:#1a1d24}'
       . 'h1{font-size:22px;color:#dc2626;margin:0 0 14px}pre{background:#f3f4f6;padding:14px;border-radius:8px;'
       . 'overflow-x:auto;font-size:12px;color:#374151;white-space:pre-wrap;word-break:break-word}'
       . '.tip{background:#fef3c7;border-left:3px solid #f59e0b;padding:12px 14px;border-radius:6px;margin:14px 0;font-size:13px}'
       . 'a{color:#4F46E5}</style></head><body>'
       . '<h1>🗺️ ' . htmlspecialchars($title) . '</h1>'
       . '<pre>' . htmlspecialchars($detail) . '</pre>'
       . '<div class="tip">💡 <strong>Tip:</strong> Zkontroluj že <code>/api/config.local.php</code> existuje. '
       . '<a href="/admin/">← zpět do adminu</a></div>'
       . '</body></html>';
    exit;
}

try {
    require_once __DIR__ . '/../api/config.php';
    require_once __DIR__ . '/../api/_admin_auth.php';
    require_once __DIR__ . '/../api/_packages_lib.php';
} catch (Throwable $e) {
    fp_show_error('Chyba načtení knihoven', $e->getMessage() . "\nv souboru: " . $e->getFile() . ":" . $e->getLine());
}

foreach (['session_secure_start', 'csrf_token', 'package_enabled'] as $fn) {
    if (!function_exists($fn)) {
        fp_show_error('Chybí funkce: ' . $fn,
            "Aplikace nebyla správně nainstalována.\n"
          . "Zkontroluj že /api/ obsahuje: config.php, _admin_auth.php, _packages_lib.php, _csrf.php");
    }
}

session_secure_start();
if (empty($_SESSION['admin_id'])) {
    if (!headers_sent()) {
        header('Location: /admin/?return=' . urlencode('/floorplan/'));
    }
    exit;
}

if (!package_enabled('restaurace')) {
    http_response_code(403);
    ?><!DOCTYPE html><html lang="cs"><head><meta charset="utf-8"><title>Floor Plan — nedostupné</title>
    <style>body{font-family:-apple-system,sans-serif;max-width:520px;margin:80px auto;padding:24px;text-align:center}
    h1{font-size:24px}a{color:#BA7517;text-decoration:none;font-weight:600}</style></head><body>
    <h1>🗺️ Floor Plan Editor není v tvém balíčku</h1>
    <p>Floor Plan Editor je součást balíčku <strong>🍕 Restaurace / Pizzerie</strong>.</p>
    <p><a href="/admin/#pkg_restaurace">← Aktivovat balíček</a></p>
    </body></html><?php
    exit;
}

$adminJmeno = $_SESSION['admin_jmeno'] ?? '';
$appVersion = defined('APP_VERSION') ? APP_VERSION : '0.0.0';
$csrfToken  = csrf_token();
?><!DOCTYPE html>
<html lang="cs">
<head>
<meta charset="UTF-8">
<meta http-equiv="Cache-Control" content="no-cache, no-store, must-revalidate">
<meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
<meta name="theme-color" content="#1a1d24">
<meta name="robots" content="noindex, nofollow">
<title>APPEK Floor Plan — Editor stolů</title>
<link rel="icon" type="image/svg+xml" href="/admin/icons/icon-192.svg">
<link rel="stylesheet" href="floorplan.css?v=<?= htmlspecialchars($appVersion) ?>">
<script>
  window.FP_CONFIG = {
    version:   <?= json_encode($appVersion) ?>,
    adminName: <?= json_encode($adminJmeno) ?>,
    csrfToken: <?= json_encode($csrfToken) ?>,
    apiBase:   '/api/',
  };
</script>
</head>
<body class="fp-app">

<!-- HEADER -->
<header class="fp-header">
  <div class="fp-h-left">
    <button class="fp-btn fp-back" onclick="window.close()" title="Zavřít">←</button>
    <div class="fp-brand">
      <span class="fp-brand-ic">🗺️</span>
      <span class="fp-brand-name">Floor Plan</span>
      <span class="fp-brand-sub">Editor stolů</span>
    </div>
  </div>
  <div class="fp-h-center" id="fp-zone-tabs"><!-- tab per zone --></div>
  <div class="fp-h-right">
    <button class="fp-btn" id="fp-undo-btn" onclick="FP.undo()" title="Zpět (Cmd+Z)" disabled>↶</button>
    <button class="fp-btn" id="fp-redo-btn" onclick="FP.redo()" title="Znovu (Cmd+Shift+Z)" disabled>↷</button>
    <span class="fp-divider"></span>
    <button class="fp-btn fp-btn-primary" onclick="FP.openSaveTemplate()" title="Uložit jako šablonu">
      💾 <span class="fp-btn-label">Uložit šablonu</span>
    </button>
    <button class="fp-btn" onclick="FP.openTemplates()" title="Načíst šablonu">
      📂 <span class="fp-btn-label">Šablony</span>
    </button>
    <button class="fp-btn fp-btn-success" onclick="FP.applyToProduction()" title="Aplikovat do produkce (přepíše stoly)">
      🚀 <span class="fp-btn-label">Použít</span>
    </button>
  </div>
</header>

<!-- TOOLBAR (levý sloupec — knihovna prefab + tools) -->
<aside class="fp-sidebar">

  <div class="fp-tool-section">
    <div class="fp-section-title">🪑 Stoly</div>
    <div class="fp-tool-grid">
      <button class="fp-tool" data-add="round-2"  title="Kruhový 2 osoby (60×60)">
        <span class="fp-tool-shape fp-shape-round" style="width:34px;height:34px">2</span>
        <span class="fp-tool-label">2 os.</span>
      </button>
      <button class="fp-tool" data-add="round-4"  title="Kruhový 4 osoby (80×80)">
        <span class="fp-tool-shape fp-shape-round" style="width:42px;height:42px">4</span>
        <span class="fp-tool-label">4 os.</span>
      </button>
      <button class="fp-tool" data-add="round-6"  title="Kruhový 6 osob (100×100)">
        <span class="fp-tool-shape fp-shape-round" style="width:50px;height:50px">6</span>
        <span class="fp-tool-label">6 os.</span>
      </button>
      <button class="fp-tool" data-add="square-4" title="Čtvercový 4 (80×80)">
        <span class="fp-tool-shape fp-shape-square" style="width:42px;height:42px">4</span>
        <span class="fp-tool-label">Čtv.</span>
      </button>
      <button class="fp-tool" data-add="rect-6"   title="Obdélník 6 (120×80)">
        <span class="fp-tool-shape fp-shape-rect" style="width:54px;height:36px">6</span>
        <span class="fp-tool-label">Obdél.</span>
      </button>
      <button class="fp-tool" data-add="rect-8"   title="Obdélník 8 (160×80)">
        <span class="fp-tool-shape fp-shape-rect" style="width:60px;height:30px">8</span>
        <span class="fp-tool-label">Dlouhý</span>
      </button>
      <button class="fp-tool" data-add="bar-2"    title="Barová židle (40×40)">
        <span class="fp-tool-shape fp-shape-bar" style="width:28px;height:28px">B</span>
        <span class="fp-tool-label">Bar</span>
      </button>
      <button class="fp-tool" data-add="lounge"   title="Salonek (140×100)">
        <span class="fp-tool-shape fp-shape-lounge" style="width:54px;height:38px">L</span>
        <span class="fp-tool-label">Salon</span>
      </button>
    </div>
  </div>

  <div class="fp-tool-section">
    <div class="fp-section-title">🏛️ Nábytek</div>
    <div class="fp-tool-grid">
      <button class="fp-tool" data-add="wall-h"   title="Zeď horizontální">
        <span class="fp-tool-shape fp-shape-wall" style="width:50px;height:8px"></span>
        <span class="fp-tool-label">Zeď —</span>
      </button>
      <button class="fp-tool" data-add="wall-v"   title="Zeď vertikální">
        <span class="fp-tool-shape fp-shape-wall" style="width:8px;height:50px"></span>
        <span class="fp-tool-label">Zeď │</span>
      </button>
      <button class="fp-tool" data-add="door"     title="Dveře">
        <span class="fp-tool-shape fp-shape-door" style="width:36px;height:30px">🚪</span>
        <span class="fp-tool-label">Dveře</span>
      </button>
      <button class="fp-tool" data-add="bar-cnt"  title="Barový pult">
        <span class="fp-tool-shape fp-shape-counter" style="width:48px;height:24px">🍸</span>
        <span class="fp-tool-label">Pult</span>
      </button>
      <button class="fp-tool" data-add="kitchen"  title="Kuchyně">
        <span class="fp-tool-shape fp-shape-kitchen" style="width:48px;height:36px">👨‍🍳</span>
        <span class="fp-tool-label">Kuchyně</span>
      </button>
      <button class="fp-tool" data-add="wc"       title="WC">
        <span class="fp-tool-shape fp-shape-wc" style="width:36px;height:30px">🚻</span>
        <span class="fp-tool-label">WC</span>
      </button>
      <button class="fp-tool" data-add="plant"    title="Květina">
        <span class="fp-tool-shape fp-shape-plant" style="width:30px;height:30px">🌿</span>
        <span class="fp-tool-label">Květina</span>
      </button>
      <button class="fp-tool" data-add="text"     title="Popisek (text)">
        <span class="fp-tool-shape fp-shape-text" style="width:40px;height:24px">T</span>
        <span class="fp-tool-label">Text</span>
      </button>
    </div>
  </div>

  <div class="fp-tool-section">
    <div class="fp-section-title">⚙️ Akce</div>
    <button class="fp-tool fp-tool-wide" onclick="FP.addZone()" title="Přidat zónu">➕ Nová zóna</button>
    <button class="fp-tool fp-tool-wide" onclick="FP.exportPNG()" title="Stáhnout jako PNG">🖼️ Export PNG</button>
    <button class="fp-tool fp-tool-wide" onclick="FP.exportJSON()" title="Stáhnout JSON">📤 Export JSON</button>
    <button class="fp-tool fp-tool-wide" onclick="FP.importJSON()" title="Nahrát JSON">📥 Import JSON</button>
    <button class="fp-tool fp-tool-wide fp-tool-danger" onclick="FP.clearCanvas()" title="Vymazat aktuální zónu">🗑️ Vymazat zónu</button>
  </div>

</aside>

<!-- CANVAS -->
<main class="fp-canvas-wrap" id="fp-canvas-wrap">
  <div class="fp-canvas-toolbar">
    <div class="fp-tb-left">
      <span class="fp-current-zone" id="fp-zone-name">— vyberte zónu —</span>
      <span class="fp-zone-info" id="fp-zone-info"></span>
    </div>
    <div class="fp-tb-right">
      <button class="fp-mini-btn" onclick="FP.editZone()" title="Upravit zónu">⚙️</button>
      <span class="fp-zoom">
        <button class="fp-mini-btn" onclick="FP.zoom(-0.1)" title="Oddálit">−</button>
        <span class="fp-zoom-val" id="fp-zoom-val">100%</span>
        <button class="fp-mini-btn" onclick="FP.zoom(0.1)" title="Přiblížit">+</button>
        <button class="fp-mini-btn" onclick="FP.zoomReset()" title="Reset">⊙</button>
      </span>
      <label class="fp-grid-toggle">
        <input type="checkbox" id="fp-grid-cb" checked onchange="FP.toggleGrid(this.checked)">
        <span>Mřížka</span>
      </label>
      <label class="fp-grid-toggle">
        <input type="checkbox" id="fp-snap-cb" checked onchange="FP.toggleSnap(this.checked)">
        <span>Snap</span>
      </label>
    </div>
  </div>

  <div class="fp-canvas-scroll" id="fp-canvas-scroll">
    <div class="fp-canvas" id="fp-canvas">
      <div class="fp-canvas-grid" id="fp-canvas-grid"></div>
      <div class="fp-canvas-items" id="fp-canvas-items"></div>
    </div>
  </div>
</main>

<!-- PROPERTIES PANEL (pravý sloupec — vlastnosti vybraného prvku) -->
<aside class="fp-props" id="fp-props">
  <div class="fp-props-empty">
    <div class="fp-props-empty-ic">👆</div>
    <div class="fp-props-empty-title">Vyber prvek</div>
    <div class="fp-props-empty-sub">Klikni na stůl nebo přetáhni z knihovny vlevo</div>
  </div>
</aside>

<!-- MODAL host -->
<div id="fp-modal" class="fp-modal-host" hidden></div>

<!-- TOAST host -->
<div id="fp-toast" class="fp-toast-host"></div>

<!-- FOOTER -->
<footer class="fp-footer">
  <span>APPEK Floor Plan · v<?= htmlspecialchars($appVersion) ?></span>
  <span class="fp-foot-sep">·</span>
  <span id="fp-foot-stoly">0 stolů</span>
  <span class="fp-foot-sep">·</span>
  <span id="fp-foot-zones">0 zón</span>
  <span class="fp-foot-sep">·</span>
  <span id="fp-foot-status">💾 Změny neuloženy</span>
</footer>

<script src="floorplan.js?v=<?= htmlspecialchars($appVersion) ?>"></script>
</body>
</html>
