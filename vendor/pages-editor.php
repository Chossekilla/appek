<?php
/**
 * ✏️ PAGES EDITOR — Strukturovaný editor klíčových textů.
 *
 * Edituje 3 oblasti:
 *   1. SALES (appek.cz) — hero title, lead, pricing texty
 *   2. CHECKOUT — labels, consent texty
 *   3. EMAIL templates — license e-mail subject + body
 *
 * Ukládá do `sales-content.json` v rootu projektu.
 * Sales index.html ho načte při loadu (cache-busted) a override-uje SALES_I18N.
 */
require_once __DIR__ . '/_lib.php';
require_once __DIR__ . '/_layout.php';
require_once __DIR__ . '/_mail.php';  // vendor_mail_set / vendor_ensure_settings_table (téma frontpage)

$user = vendor_require_login();
$currentPage = 'pages-editor';
$root = realpath(__DIR__ . '/..');

if ($_SERVER['REQUEST_METHOD'] === 'POST') vendor_csrf_check();  // 🔐 CSRF
$contentFile = $root . '/sales-content.json';

$flash_ok = null;
$flash_err = null;

// Strukturovaná definice editovatelných polí (vendor je vidí jako form, ne JSON)
$schema = [
    'hero' => [
        'label' => '🎯 Hero (úvodní sekce)',
        'fields' => [
            'hero_title_1' => ['label' => 'Hlavní nadpis (1. řádek)', 'cs' => 'Vše pro váš provoz'],
            'hero_title_2' => ['label' => 'Hlavní nadpis (2. řádek, gradient)', 'cs' => 'v jednom systému'],
            'hero_lead'    => ['label' => 'Lead text pod nadpisem', 'cs' => '', 'multiline' => true],
            'hero_badge'   => ['label' => 'Badge nahoře (📦 …)', 'cs' => '📦 Krabicovka pro gastro výrobce'],
        ],
    ],
    'pricing' => [
        'label' => '💰 Pricing — popisky tarifů',
        'fields' => [
            'tier_starter_tag' => ['label' => 'Starter — tagline', 'cs' => ''],
            'tier_starter_n'   => ['label' => 'Starter — název', 'cs' => ''],
            'tier_profi_tag'   => ['label' => 'Profi — tagline', 'cs' => ''],
            'tier_premium_tag' => ['label' => 'Premium — tagline', 'cs' => ''],
        ],
    ],
    'integrations' => [
        'label' => '🔌 Integrace — texty karet',
        'fields' => [
            'int_title' => ['label' => 'Hlavní nadpis integrací', 'cs' => 'Funguje s tím, co používáte'],
            'int_sub'   => ['label' => 'Podtext', 'cs' => '', 'multiline' => true],
        ],
    ],
    'delivery' => [
        'label' => '🚛 Rozvozy — texty sekce',
        'fields' => [
            'dl_title' => ['label' => 'Hlavní nadpis', 'cs' => 'Doručujeme jídlo. Doslova.'],
            'dl_sub'   => ['label' => 'Podtext', 'cs' => '', 'multiline' => true],
        ],
    ],
];

// Načti aktuální JSON
$content = file_exists($contentFile) ? json_decode(file_get_contents($contentFile), true) : [];
if (!is_array($content)) $content = [];

// POST — uložit
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'save') {
    try {
        foreach ($schema as $sectionKey => $section) {
            foreach ($section['fields'] as $fieldKey => $fieldDef) {
                foreach (['cs', 'en', 'es'] as $lang) {
                    $val = trim((string) ($_POST["{$fieldKey}_{$lang}"] ?? ''));
                    if ($val !== '') {
                        if (!isset($content[$fieldKey])) $content[$fieldKey] = [];
                        $content[$fieldKey][$lang] = $val;
                    } elseif (isset($content[$fieldKey][$lang])) {
                        unset($content[$fieldKey][$lang]);
                    }
                }
                if (isset($content[$fieldKey]) && empty($content[$fieldKey])) {
                    unset($content[$fieldKey]);
                }
            }
        }

        // Auto-backup před uložením
        if (file_exists($contentFile)) {
            @copy($contentFile, $contentFile . '.bak.' . date('Ymd-His'));
        }

        $json = json_encode($content, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        if (@file_put_contents($contentFile, $json) === false) {
            throw new Exception('Nelze zapsat sales-content.json (zkontroluj oprávnění).');
        }

        vendor_audit(vendor_db(), $user, 'pages_editor_save', null, null);
        $flash_ok = 'Texty uloženy. Změny se promítnou na appek.cz po obnovení stránky (cache 5 s).';
    } catch (Throwable $e) {
        $flash_err = $e->getMessage();
    }
}

// ─── 🎨 Téma frontpage (přesunuto z Nastavení) — čte theme.css.php ──
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'save_site_theme') {
    try {
        vendor_ensure_settings_table(vendor_db());
        $t = preg_replace('/[^a-z0-9_-]/', '', strtolower((string) ($_POST['site_theme'] ?? 'classic')));
        if (!in_array($t, ['classic', 'studio', 'noir'], true)) $t = 'classic';
        vendor_mail_set('site_theme', $t);
        vendor_audit(vendor_db(), $user, 'site_theme_save', null, 'theme=' . $t);
        $flash_ok = 'Téma frontpage přepnuto na „' . $t . '“ — na appek.cz je změna vidět okamžitě.';
    } catch (Throwable $e) {
        $flash_err = $e->getMessage();
    }
}

// ─── 🅰️🅱️ Landing varianta (A/B) — čte landing.php ──
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'save_landing_variant') {
    try {
        vendor_ensure_settings_table(vendor_db());
        $lv = preg_replace('/[^a-z0-9_-]/', '', strtolower((string) ($_POST['landing_variant'] ?? 'classic')));
        if ($lv === '') $lv = 'classic';
        vendor_mail_set('landing_variant', $lv);
        vendor_audit(vendor_db(), $user, 'landing_variant_save', null, 'variant=' . $lv);
        $flash_ok = 'Landing varianta přepnuta na „' . $lv . '“ — na appek.cz je změna vidět okamžitě.';
    } catch (Throwable $e) { $flash_err = $e->getMessage(); }
}

// Aktuální téma pro přepínač
$curTheme = 'classic';
try {
    vendor_ensure_settings_table(vendor_db());
    $v = vendor_db()->query("SELECT `value` FROM vendor_settings WHERE `key`='site_theme'")->fetchColumn();
    if ($v) $curTheme = preg_replace('/[^a-z0-9_-]/', '', strtolower((string) $v));
} catch (Throwable $e) { /* fail-safe → classic */ }

// 🅰️🅱️ Aktuální landing varianta + funnel statistiky (views/clicks + objednávky per varianta)
$curVariant = 'classic';
$lpStats = [];
try {
    $db = vendor_db();
    $vv = $db->query("SELECT `value` FROM vendor_settings WHERE `key`='landing_variant'")->fetchColumn();
    if ($vv) $curVariant = preg_replace('/[^a-z0-9_-]/', '', strtolower((string) $vv));
    foreach (($db->query("SELECT variant, SUM(views) v, SUM(checkout_clicks) c, SUM(demo_clicks) d FROM vendor_landing_stats GROUP BY variant")->fetchAll(PDO::FETCH_ASSOC) ?: []) as $r) {
        $lpStats[$r['variant']] = ['views' => (int) $r['v'], 'clicks' => (int) $r['c'], 'demo' => (int) $r['d'], 'orders' => 0, 'paid' => 0];
    }
    foreach (($db->query("SELECT COALESCE(NULLIF(landing_variant,''),'classic') variant, COUNT(*) n, SUM(payment_status='paid') paid FROM vendor_shop_orders GROUP BY COALESCE(NULLIF(landing_variant,''),'classic')")->fetchAll(PDO::FETCH_ASSOC) ?: []) as $r) {
        $k = $r['variant'] ?: 'classic';
        if (!isset($lpStats[$k])) $lpStats[$k] = ['views' => 0, 'clicks' => 0, 'demo' => 0, 'orders' => 0, 'paid' => 0];
        $lpStats[$k]['orders'] = (int) $r['n'];
        $lpStats[$k]['paid'] = (int) $r['paid'];
    }
} catch (Throwable $e) { /* fail-safe → prázdné statistiky */ }
// Dostupné varianty = classic + každý lp/*.html soubor
$lpVariants = ['classic'];
foreach (glob(__DIR__ . '/../lp/*.html') ?: [] as $f) { $lpVariants[] = basename($f, '.html'); }
$lpVariants = array_values(array_unique(array_merge($lpVariants, array_keys($lpStats))));
?><!DOCTYPE html>
<html lang="cs">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="robots" content="noindex,nofollow">
<title>✏️ Pages Editor — APPEK Master</title>
<link rel="stylesheet" href="style.css?v=1.5">
<style>
  .pe-section {
    background: #fff; border-radius: 14px; padding: 24px 28px;
    margin-bottom: 18px;
    box-shadow: 0 1px 3px rgba(0,0,0,0.04);
  }
  .pe-section h2 { font-size: 16px; margin: 0 0 16px; color: #1d1d1f; }
  .pe-field { margin-bottom: 16px; padding-bottom: 16px; border-bottom: 1px dashed #f0f0f3; }
  .pe-field:last-child { border-bottom: none; padding-bottom: 0; }
  .pe-field .pe-label { font-size: 13px; font-weight: 600; color: #1d1d1f; margin-bottom: 8px; }
  .pe-langs { display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 10px; }
  .pe-lang { position: relative; }
  .pe-lang .flag {
    position: absolute; top: 9px; left: 12px;
    font-size: 11px; color: #86868b;
    background: #fafafa; padding: 1px 5px; border-radius: 4px;
    font-weight: 700; letter-spacing: 0.3px;
  }
  .pe-lang input, .pe-lang textarea {
    width: 100%; padding: 28px 12px 10px;
    border: 1px solid #d2d2d7; border-radius: 8px;
    font-family: inherit; font-size: 13px;
    box-sizing: border-box;
    resize: vertical;
  }
  .pe-lang input:focus, .pe-lang textarea:focus { outline: 2px solid #BA7517; border-color: #BA7517; }
  .save-bar {
    position: sticky; bottom: 14px;
    background: rgba(255,255,255,0.96); backdrop-filter: blur(10px);
    padding: 16px 24px; border-radius: 12px;
    box-shadow: 0 8px 24px rgba(0,0,0,0.08);
    display: flex; justify-content: space-between; align-items: center;
    margin-top: 20px;
    border: 1px solid #e5e5e7;
  }
  .flash { padding: 12px 16px; border-radius: 10px; margin-bottom: 14px; font-size: 14px; }
  .flash.ok  { background: #d4edda; color: #155724; }
  .flash.err { background: #f8d7da; color: #721c24; }
  @media (max-width: 720px) {
    .pe-langs { grid-template-columns: 1fr; }
  }
</style>
</head>
<body>

<?php vendor_render_topbar($user, $currentPage); ?>
<?php vendor_render_back(); ?>

<main class="page-master">

  <div class="page-header-master">
    <h1>✏️ Pages Editor</h1>
    <div style="display:flex;gap:8px;align-items:center">
      <a href="https://appek.cz/" target="_blank" rel="noopener" class="btn-master secondary">👁️ Otevřít sales</a>
      <a href="sales-cms.php" class="btn-master secondary">📄 JSON editor</a>
    </div>
  </div>

  <?php if ($flash_ok): ?><div class="flash ok">✅ <?= htmlspecialchars($flash_ok) ?></div><?php endif; ?>
  <?php if ($flash_err): ?><div class="flash err">❌ <?= htmlspecialchars($flash_err) ?></div><?php endif; ?>

  <!-- 🎨 VZHLED WEBU — přesunuto z Nastavení -->
  <div class="pe-section">
    <h2>🎨 Vzhled webu <small style="font-weight:400;color:#86868b;font-size:12px">— téma appek.cz, přepnutí okamžité (bez deploye)</small></h2>
    <form method="POST" style="display:flex;flex-direction:column;gap:12px;max-width:560px">
      <?php vendor_csrf_field(); ?>
      <input type="hidden" name="action" value="save_site_theme">
      <div>
        <div class="pe-label">Aktivní téma</div>
        <select name="site_theme" style="width:100%;padding:10px 12px;border:1px solid #d2d2d7;border-radius:8px;font-family:inherit;font-size:13px">
          <option value="classic" <?= $curTheme === 'classic' ? 'selected' : '' ?>>🥖 Classic — původní teplý vzhled</option>
          <option value="studio"  <?= $curTheme === 'studio'  ? 'selected' : '' ?>>◻️ Studio — čistý profi SaaS (světlý)</option>
          <option value="noir"    <?= $curTheme === 'noir'    ? 'selected' : '' ?>>◼️ Noir — tmavý prémiový (zlatá)</option>
        </select>
      </div>
      <div style="font-size:12px;color:#888;line-height:1.6">
        Náhledy (nepřepnou nic pro návštěvníky):
        <a href="https://appek.cz/?theme=classic" target="_blank" rel="noopener">Classic ↗</a> ·
        <a href="https://appek.cz/?theme=studio" target="_blank" rel="noopener">Studio ↗</a> ·
        <a href="https://appek.cz/?theme=noir" target="_blank" rel="noopener">Noir ↗</a>
      </div>
      <div><button type="submit" class="btn-master primary">💾 Uložit téma</button></div>
    </form>
  </div>

  <!-- 🅰️🅱️ LANDING VARIANTA (A/B TEST) -->
  <div class="pe-section">
    <h2>🅰️🅱️ Landing varianta <small style="font-weight:400;color:#86868b;font-size:12px">— přepínatelný vzhled prodejní stránky appek.cz + měření, na co zákazníci reagují nejvíc</small></h2>
    <form method="POST" style="display:flex;flex-direction:column;gap:12px;max-width:560px">
      <?php vendor_csrf_field(); ?>
      <input type="hidden" name="action" value="save_landing_variant">
      <div>
        <div class="pe-label">Aktivní vzhled (dostane každý návštěvník)</div>
        <select name="landing_variant" style="width:100%;padding:10px 12px;border:1px solid #d2d2d7;border-radius:8px;font-family:inherit;font-size:13px">
          <?php foreach ($lpVariants as $vv): ?>
            <option value="<?= htmlspecialchars($vv) ?>" <?= $curVariant === $vv ? 'selected' : '' ?>><?= $vv === 'classic' ? 'Classic — původní landing (index.html)' : htmlspecialchars($vv) . ' — lp/' . htmlspecialchars($vv) . '.html' ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div style="font-size:12px;color:#888;line-height:1.6">
        Náhled bez přepnutí pro návštěvníky:
        <?php foreach ($lpVariants as $vv): ?><a href="https://appek.cz/?lp=<?= urlencode($vv) ?>" target="_blank" rel="noopener"><?= htmlspecialchars($vv) ?> ↗</a><?= $vv !== end($lpVariants) ? ' · ' : '' ?><?php endforeach; ?>
      </div>
      <div><button type="submit" class="btn-master primary">💾 Přepnout vzhled</button></div>
    </form>

    <div style="margin-top:18px;overflow-x:auto">
      <div style="font-size:12px;font-weight:700;color:#6e6e73;margin-bottom:8px">📊 Funnel per varianta (zhlédnutí → klik „Objednat" → zaplaceno)</div>
      <table style="width:100%;border-collapse:collapse;font-size:13px;min-width:640px">
        <thead>
          <tr style="background:#fafafa;color:#6e6e73;font-size:11px;text-transform:uppercase;letter-spacing:0.4px">
            <th style="padding:9px 12px;text-align:left">Varianta</th>
            <th style="padding:9px 12px;text-align:right">Zhlédnutí</th>
            <th style="padding:9px 12px;text-align:right">Klik „Objednat"</th>
            <th style="padding:9px 12px;text-align:right">Klik demo</th>
            <th style="padding:9px 12px;text-align:right">Objednávky</th>
            <th style="padding:9px 12px;text-align:right">Zaplaceno</th>
            <th style="padding:9px 12px;text-align:right">Klik→ z zhlédnutí</th>
            <th style="padding:9px 12px;text-align:right">Konverze</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($lpVariants as $vv):
            $s = $lpStats[$vv] ?? ['views' => 0, 'clicks' => 0, 'demo' => 0, 'orders' => 0, 'paid' => 0];
            $ctr  = $s['views'] > 0 ? round($s['clicks'] / $s['views'] * 100, 1) : 0;
            $conv = $s['views'] > 0 ? round($s['paid'] / $s['views'] * 100, 2) : 0;
            $isActive = ($vv === $curVariant);
          ?>
            <tr style="border-bottom:1px solid #f0f0f3;<?= $isActive ? 'background:#f0f7ff;font-weight:700' : '' ?>">
              <td style="padding:9px 12px"><?= $isActive ? '▶ ' : '' ?><?= htmlspecialchars($vv) ?><?= $isActive ? ' <span style="font-size:10px;color:#0071e3;font-weight:600">AKTIVNÍ</span>' : '' ?></td>
              <td style="padding:9px 12px;text-align:right"><?= number_format($s['views'], 0, ',', ' ') ?></td>
              <td style="padding:9px 12px;text-align:right"><?= number_format($s['clicks'], 0, ',', ' ') ?></td>
              <td style="padding:9px 12px;text-align:right;color:#86868b"><?= number_format($s['demo'], 0, ',', ' ') ?></td>
              <td style="padding:9px 12px;text-align:right"><?= number_format($s['orders'], 0, ',', ' ') ?></td>
              <td style="padding:9px 12px;text-align:right;color:#15803d;font-weight:700"><?= number_format($s['paid'], 0, ',', ' ') ?></td>
              <td style="padding:9px 12px;text-align:right;color:#86868b"><?= $ctr ?> %</td>
              <td style="padding:9px 12px;text-align:right;font-weight:700"><?= $conv ?> %</td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
      <div style="font-size:11px;color:#86868b;margin-top:8px;line-height:1.5">
        „Na co reagují nejvíc" = nejvyšší <strong>konverze</strong> (zaplaceno ÷ zhlédnutí) a klik-rate. Přepínač je ruční — nech každý vzhled běžet dostatečně dlouho, ať je srovnání férové (sezónnost zkresluje). Zhlédnutí/kliky sbírá <code>lp/track.php</code>, objednávky se párují přes <code>?lp=</code> na checkoutu.
      </div>
    </div>
  </div>

  <form method="POST">
    <?php vendor_csrf_field(); ?>
    <input type="hidden" name="action" value="save">

    <?php foreach ($schema as $sectionKey => $section): ?>
      <div class="pe-section">
        <h2><?= htmlspecialchars($section['label']) ?></h2>

        <?php foreach ($section['fields'] as $fieldKey => $fieldDef):
          $isMulti = !empty($fieldDef['multiline']);
          $tag = $isMulti ? 'textarea' : 'input';
          $rows = $isMulti ? 'rows="3"' : '';
        ?>
          <div class="pe-field">
            <div class="pe-label">
              <?= htmlspecialchars($fieldDef['label']) ?>
              <small style="color:#86868b;font-weight:400;font-family:'SF Mono',Menlo,monospace;font-size:11px;margin-left:6px"><?= htmlspecialchars($fieldKey) ?></small>
            </div>
            <div class="pe-langs">
              <?php foreach (['cs' => 'CS', 'en' => 'EN', 'es' => 'ES'] as $langKey => $flag):
                $value = $content[$fieldKey][$langKey] ?? '';
                $placeholder = $langKey === 'cs' ? ($fieldDef['cs'] ?? '') : '';
              ?>
                <div class="pe-lang">
                  <span class="flag"><?= $flag ?></span>
                  <?php if ($isMulti): ?>
                    <textarea name="<?= htmlspecialchars($fieldKey . '_' . $langKey) ?>" rows="3" placeholder="<?= htmlspecialchars($placeholder) ?>"><?= htmlspecialchars($value) ?></textarea>
                  <?php else: ?>
                    <input type="text" name="<?= htmlspecialchars($fieldKey . '_' . $langKey) ?>" value="<?= htmlspecialchars($value) ?>" placeholder="<?= htmlspecialchars($placeholder) ?>">
                  <?php endif; ?>
                </div>
              <?php endforeach; ?>
            </div>
          </div>
        <?php endforeach; ?>
      </div>
    <?php endforeach; ?>

    <div class="save-bar">
      <span style="font-size:13px;color:#6e6e73">💾 Změny se ukládají do <code>sales-content.json</code> · auto-backup</span>
      <button type="submit" class="btn-master primary">💾 Uložit změny</button>
    </div>
  </form>

  <div class="panel-master" style="margin-top:20px;background:#fafafa;border:1px dashed #d2d2d7">
    <h2 style="font-size:13px;color:#6e6e73">💡 Jak to funguje</h2>
    <ol style="font-size:13px;color:#3a3a3c;line-height:1.7;padding-left:20px;margin:0">
      <li>Editor zapisuje do <code>sales-content.json</code> v rootu webu</li>
      <li>Příští load <code>appek.cz/</code> tento JSON načte JS-em a přepíše výchozí texty</li>
      <li>Cache 5 s — refreshni stránku 2× pokud nevidíš změny ihned</li>
      <li>Před uložením auto-záloha (<code>.bak.YYYYMMDD-HHMMSS</code>)</li>
      <li>Pro pokročilou editaci JSON přímo: <a href="sales-cms.php">📄 JSON editor</a></li>
    </ol>
  </div>

</main>

<?php vendor_render_footer(); ?>
</body>
</html>
