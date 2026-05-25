<?php
/**
 * ✏️ SALES CMS — Editace obsahu appek.cz (hero, ceny, FAQ).
 *
 * MVP: editor JSON souboru sales/content.json, který sales/index.html bude
 * jednou číst. Zatím jen rozhraní — propojení v další iteraci.
 */
require_once __DIR__ . '/_lib.php';
require_once __DIR__ . '/_layout.php';

$user = vendor_require_login();
$currentPage = 'sales-cms';

$contentFile = realpath(__DIR__ . '/..') . '/sales-content.json';
$content = file_exists($contentFile) ? file_get_contents($contentFile) : '{}';
$flash_ok = null;
$flash_err = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['json'])) {
    $raw = $_POST['json'];
    $parsed = json_decode($raw, true);
    if ($parsed === null && json_last_error() !== JSON_ERROR_NONE) {
        $flash_err = 'Neplatný JSON: ' . json_last_error_msg();
        $content = $raw;
    } else {
        $pretty = json_encode($parsed, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        // Záloha předchozí verze
        if (file_exists($contentFile)) {
            @copy($contentFile, $contentFile . '.bak.' . date('Ymd-His'));
        }
        if (@file_put_contents($contentFile, $pretty) === false) {
            $flash_err = 'Nelze zapsat sales/content.json (zkontroluj oprávnění).';
        } else {
            $flash_ok = 'Obsah uložen. Backup: ' . basename($contentFile) . '.bak.' . date('Ymd-His');
            $content = $pretty;
            vendor_audit(vendor_db(), $user, 'sales_cms_save', null, null);
        }
    }
}
?><!DOCTYPE html>
<html lang="cs">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="robots" content="noindex,nofollow">
<title>✏️ Sales CMS — APPEK Master</title>
<link rel="stylesheet" href="style.css?v=1.4">
<style>
  .editor textarea {
    width: 100%; min-height: 480px; padding: 14px;
    font-family: 'SF Mono', Menlo, Consolas, monospace;
    font-size: 13px; line-height: 1.6;
    border: 1px solid #d2d2d7; border-radius: 10px;
    box-sizing: border-box;
  }
  .flash { padding: 12px 16px; border-radius: 10px; margin-bottom: 14px; font-size: 14px; }
  .flash.ok  { background: #d4edda; color: #155724; }
  .flash.err { background: #f8d7da; color: #721c24; }
</style>
</head>
<body>

<?php vendor_render_topbar($user, $currentPage); ?>
<?php vendor_render_back(); ?>

<main class="page-master">

  <div class="page-header-master">
    <h1>✏️ Sales CMS</h1>
    <div style="font-size:13px;color:#86868b">Edituje <code>sales/content.json</code> — texty pro appek.cz</div>
  </div>

  <?php if ($flash_ok): ?><div class="flash ok">✅ <?= htmlspecialchars($flash_ok) ?></div><?php endif; ?>
  <?php if ($flash_err): ?><div class="flash err">❌ <?= htmlspecialchars($flash_err) ?></div><?php endif; ?>

  <div class="panel-master">
    <h2>JSON editor</h2>
    <form method="POST" class="editor">
      <textarea name="json" spellcheck="false"><?= htmlspecialchars($content) ?></textarea>
      <div style="display:flex;gap:10px;margin-top:12px">
        <button type="submit" class="btn-master primary">💾 Uložit</button>
        <a href="https://appek.cz/" target="_blank" rel="noopener" class="btn-master secondary">👁️ Otevřít sales (appek.cz/)</a>
      </div>
    </form>
  </div>

  <div class="panel-master">
    <h2>ℹ️ Struktura</h2>
    <p style="font-size:13px;color:#3a3a3c;line-height:1.7">
      JSON objekt s klíči pro každou sekci. Po uložení se vytvoří záloha <code>content.json.bak.YYYYMMDD-HHMMSS</code>.
      Aktuální verze <code>sales/index.html</code> má texty zatím přímo v souboru — propojení s JSONem přidáme až nasadíš první draft obsahu.
    </p>
    <pre style="background:#fafafa;padding:14px;border-radius:8px;font-size:12px;overflow-x:auto"><code>{
  "hero": {
    "title": { "cs": "...", "en": "...", "es": "..." },
    "subtitle": { "cs": "...", "en": "...", "es": "..." }
  },
  "pricing": [ { "key": "starter", "name": "...", "price_kc": 12990 } ],
  "faq": [ { "q": "...", "a": "..." } ]
}</code></pre>
  </div>

</main>

<?php vendor_render_footer(); ?>
</body>
</html>
