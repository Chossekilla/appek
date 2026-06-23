<?php
/**
 * 📦 PACKAGES MANAGEMENT — CRUD balíčků prodávaných v eshopu.
 *
 * Balíčky tvoří matrix: každý má bit_pos (0–19) mapující na license bitmask.
 * Edit zde mění:
 *   - co se zobrazuje v sales/index.html (přes API)
 *   - co se nabízí v checkout
 *   - jaká cena se uvede k vygenerování licence
 *
 * Vendor (ty) tady definuješ ceník + popisky pro CS/EN/ES.
 */
require_once __DIR__ . '/_lib.php';
require_once __DIR__ . '/_layout.php';

$user = vendor_require_login();
$pdo  = vendor_db();
$currentPage = 'packages';

if ($_SERVER['REQUEST_METHOD'] === 'POST') vendor_csrf_check();  // 🔐 CSRF

$flash_ok = null;
$flash_err = null;

// ─── POST: save/delete/toggle ───────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    try {
        if ($action === 'save') {
            $id = (int) ($_POST['id'] ?? 0);
            $data = [
                'key'            => trim($_POST['key'] ?? ''),
                'name_cs'        => trim($_POST['name_cs'] ?? ''),
                'name_en'        => trim($_POST['name_en'] ?? ''),
                'name_es'        => trim($_POST['name_es'] ?? ''),
                'description_cs' => trim($_POST['description_cs'] ?? ''),
                'description_en' => trim($_POST['description_en'] ?? ''),
                'description_es' => trim($_POST['description_es'] ?? ''),
                'icon'           => trim($_POST['icon'] ?? ''),
                'price_kc'       => (float) ($_POST['price_kc'] ?? 0),
                'price_eur'      => $_POST['price_eur'] === '' ? null : (float) $_POST['price_eur'],
                'price_usd'      => $_POST['price_usd'] === '' ? null : (float) $_POST['price_usd'],
                'bit_pos'        => $_POST['bit_pos'] === '' ? null : (int) $_POST['bit_pos'],
                'is_core'        => isset($_POST['is_core']) ? 1 : 0,
                'is_active'      => isset($_POST['is_active']) ? 1 : 0,
                'sort_order'     => (int) ($_POST['sort_order'] ?? 0),
                'features_json'  => trim($_POST['features_json'] ?? ''),
            ];
            if ($data['key'] === '' || $data['name_cs'] === '') {
                throw new Exception('Klíč a název CS jsou povinné.');
            }
            // Validace JSON features
            if ($data['features_json'] !== '') {
                $parsed = json_decode($data['features_json'], true);
                if (!is_array($parsed)) throw new Exception('features_json musí být validní JSON pole.');
            } else {
                $data['features_json'] = null;
            }

            if ($id > 0) {
                $sql = "UPDATE vendor_packages SET
                    `key` = :key, name_cs = :name_cs, name_en = :name_en, name_es = :name_es,
                    description_cs = :description_cs, description_en = :description_en, description_es = :description_es,
                    icon = :icon, price_kc = :price_kc, price_eur = :price_eur, price_usd = :price_usd,
                    bit_pos = :bit_pos, is_core = :is_core, is_active = :is_active, sort_order = :sort_order,
                    features_json = :features_json
                    WHERE id = :id";
                $data['id'] = $id;
                $pdo->prepare($sql)->execute($data);
                $flash_ok = "Balíček uložen.";
            } else {
                $sql = "INSERT INTO vendor_packages
                    (`key`, name_cs, name_en, name_es, description_cs, description_en, description_es,
                     icon, price_kc, price_eur, price_usd, bit_pos, is_core, is_active, sort_order, features_json)
                    VALUES
                    (:key, :name_cs, :name_en, :name_es, :description_cs, :description_en, :description_es,
                     :icon, :price_kc, :price_eur, :price_usd, :bit_pos, :is_core, :is_active, :sort_order, :features_json)";
                $pdo->prepare($sql)->execute($data);
                $flash_ok = "Balíček vytvořen.";
            }
            vendor_audit($pdo, $user, 'package_save', null, $data['key']);
        } elseif ($action === 'delete') {
            $id = (int) ($_POST['id'] ?? 0);
            if ($id > 0) {
                $key = $pdo->prepare("SELECT `key` FROM vendor_packages WHERE id = :id");
                $key->execute(['id' => $id]);
                $keyVal = $key->fetchColumn();
                $pdo->prepare("DELETE FROM vendor_packages WHERE id = :id")->execute(['id' => $id]);
                vendor_audit($pdo, $user, 'package_delete', null, (string) $keyVal);
                $flash_ok = "Balíček smazán.";
            }
        } elseif ($action === 'toggle') {
            $id = (int) ($_POST['id'] ?? 0);
            $pdo->prepare("UPDATE vendor_packages SET is_active = 1 - is_active WHERE id = :id")
                ->execute(['id' => $id]);
            $flash_ok = "Stav přepnut.";
        }
        header('Location: packages.php' . (isset($_POST['edit_after']) ? '?edit=' . (int) $_POST['edit_after'] : ''));
        exit;
    } catch (Throwable $e) {
        $flash_err = $e->getMessage();
    }
}

// ─── Načti všechny balíčky ──────────────────────────────────────
$packages = $pdo->query("SELECT * FROM vendor_packages ORDER BY sort_order, id")->fetchAll();

// ─── 🔗 PROVÁZANOST: spočti features per balíček (z api/_features.php) ─────
$featureMap = [];
$rootApiFeatures = realpath(__DIR__ . '/..') . '/api/_features.php';
if (file_exists($rootApiFeatures)) {
    // Načti FEATURE_MAP bez registrace funkce 'feature_enabled' (kolize není problém — nepoužijeme zde)
    $contents = file_get_contents($rootApiFeatures);
    // Extrahuj const array statickou regex - bezpečné, máme známý formát
    if (preg_match('/const FEATURE_MAP\s*=\s*\[(.*?)\];/s', $contents, $m)) {
        preg_match_all("/'([a-z_]+)'\s*=>\s*'([a-z_]+)'/", $m[1], $pairs, PREG_SET_ORDER);
        foreach ($pairs as $p) $featureMap[$p[1]] = $p[2];
    }
}
$featuresPerPackage = [];
foreach ($featureMap as $feat => $pkg) {
    $featuresPerPackage[$pkg] = ($featuresPerPackage[$pkg] ?? 0) + 1;
}

// ─── 🔗 PROVÁZANOST: poslední update + features per balíček ──────
$updatesPerPackage = [];
try {
    $rows = $pdo->query("
        SELECT u.version, u.required_packages, u.status, u.id
        FROM vendor_updates u
        WHERE u.status = 'published'
        ORDER BY u.id DESC
    ")->fetchAll();
    foreach ($rows as $r) {
        $pkgs = $r['required_packages'] ? json_decode($r['required_packages'], true) : [];
        if (!is_array($pkgs)) $pkgs = [];
        foreach ($pkgs as $pkg) {
            if (!isset($updatesPerPackage[$pkg])) {
                $updatesPerPackage[$pkg] = ['count' => 0, 'latest' => null];
            }
            $updatesPerPackage[$pkg]['count']++;
            if (!$updatesPerPackage[$pkg]['latest']) {
                $updatesPerPackage[$pkg]['latest'] = $r['version'];
            }
        }
    }
} catch (Throwable $e) { /* tabulka může neexistovat */ }

// ─── Edit mode? ─────────────────────────────────────────────────
$editId = (int) ($_GET['edit'] ?? 0);
$editing = null;
if ($editId > 0) {
    foreach ($packages as $p) if ((int) $p['id'] === $editId) { $editing = $p; break; }
}
$isNew = isset($_GET['new']);
?><!DOCTYPE html>
<html lang="cs">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="robots" content="noindex,nofollow">
<title>📦 Balíčky — APPEK Master</title>
<link rel="stylesheet" href="style.css?v=1.4">
<style>
  /* 🆕 v2.9.197 — produktové karty: větší PRICE TAG, jasný visual hierarchy */
  .pkg-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(260px, 1fr)); gap: 14px; margin-top: 14px; }
  .pkg-card {
    background: #fff; border: 1px solid #e5e5e7; border-radius: 14px;
    padding: 20px; display: flex; flex-direction: column; gap: 12px;
    box-shadow: 0 1px 3px rgba(0,0,0,0.04);
    position: relative; transition: transform .15s, box-shadow .15s;
  }
  .pkg-card:hover { transform: translateY(-2px); box-shadow: 0 6px 20px rgba(0,0,0,0.08); }
  .pkg-card.inactive { opacity: 0.55; border-style: dashed; }
  .pkg-card.core-card {
    border: 2px solid #BA7517; background: linear-gradient(180deg, #fff 0%, rgba(186,117,23,0.04) 100%);
  }
  .pkg-card.core-card::before {
    content: '⭐ DOPORUČENO'; position: absolute; top: -10px; left: 16px;
    background: #BA7517; color: #fff; font-size: 10px; font-weight: 800;
    padding: 4px 10px; border-radius: 4px; letter-spacing: 0.5px;
  }
  .pkg-card .head { display: flex; align-items: center; gap: 12px; }
  .pkg-card .icon { font-size: 36px; line-height: 1; }
  .pkg-card .name { font-size: 17px; font-weight: 700; line-height: 1.2; }
  .pkg-card .key  { font-size: 11px; color: #86868b; font-family: 'SF Mono', Menlo, monospace; margin-top: 2px; }
  .pkg-card .desc { font-size: 13px; color: #424245; line-height: 1.5; min-height: 36px; }
  .pkg-card .meta { display: flex; gap: 10px; font-size: 12px; color: #86868b; }
  /* PRICE TAG — výraznější */
  .pkg-card .price-tag {
    background: #f5f5f7; border-radius: 10px; padding: 10px 14px;
    display: flex; align-items: baseline; gap: 8px; flex-wrap: wrap;
  }
  .pkg-card .price {
    font-size: 24px; font-weight: 800; color: #1d1d1f; line-height: 1;
  }
  .pkg-card .price-currency { font-size: 13px; font-weight: 600; color: #6e6e73; }
  .pkg-card .price-alt { font-size: 12px; color: #86868b; }
  .pkg-card .price-onetime { font-size: 10px; color: #208438; font-weight: 700; text-transform: uppercase; letter-spacing: 0.5px; }
  .pkg-card .actions { display: flex; gap: 6px; margin-top: auto; padding-top: 6px; }
  .pkg-card .actions form { margin: 0; }
  .pkg-card .actions .btn-master { padding: 7px 12px; font-size: 12px; }
  .pkg-badges { display: flex; gap: 4px; flex-wrap: wrap; }
  .pkg-bd { padding: 2px 8px; border-radius: 999px; font-size: 10px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.5px; }
  .pkg-bd.core { background: rgba(255,149,0,0.15); color: #c66800; }
  .pkg-bd.active { background: rgba(52,199,89,0.15); color: #208438; }
  .pkg-bd.inactive { background: #f5f5f7; color: #86868b; }
  /* 🔗 Provázanost: links na features + updates */
  .pkg-links { display: flex; gap: 8px; flex-wrap: wrap; padding: 8px 0; border-top: 1px dashed #f0f0f3; }
  .pkg-link {
    display: inline-flex; align-items: center; gap: 4px;
    padding: 4px 10px; border-radius: 8px;
    background: #f5f5f7; color: #424245;
    font-size: 11.5px; text-decoration: none;
  }
  .pkg-link strong { color: #1d1d1f; font-weight: 700; }
  .pkg-link.muted { opacity: 0.55; }
  .pkg-link.clickable:hover { background: #BA7517; color: #fff; transform: translateY(-1px); }
  .pkg-link.clickable:hover strong { color: #fff; }

  .pkg-form { background: #fff; border-radius: 14px; padding: 24px; box-shadow: 0 1px 3px rgba(0,0,0,0.05); margin-bottom: 20px; }
  .pkg-form h2 { margin: 0 0 14px; font-size: 18px; }
  .pkg-form .row { display: grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: 12px; margin-bottom: 12px; }
  .pkg-form label { display: block; font-size: 12px; font-weight: 600; color: #1d1d1f; margin-bottom: 4px; }
  .pkg-form label small { font-weight: 400; color: #86868b; }
  .pkg-form input[type="text"], .pkg-form input[type="number"], .pkg-form textarea {
    width: 100%; padding: 9px 12px; border: 1px solid #d2d2d7; border-radius: 8px;
    font-family: inherit; font-size: 13px; box-sizing: border-box;
  }
  .pkg-form textarea { resize: vertical; min-height: 60px; font-family: 'SF Mono', Menlo, monospace; }
  .pkg-form .checks { display: flex; gap: 18px; flex-wrap: wrap; margin: 12px 0; font-size: 13px; }
  .pkg-form .checks label { display: flex; align-items: center; gap: 6px; font-weight: 500; }
  .pkg-form .form-actions { display: flex; gap: 10px; margin-top: 16px; padding-top: 16px; border-top: 1px solid #e5e5e7; }
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
    <h1>📦 Správa balíčků</h1>
    <div style="display:flex;gap:8px;align-items:center">
      <span style="font-size:13px;color:#86868b"><?= count($packages) ?> balíčků · <?= count(array_filter($packages, fn($p) => $p['is_active'])) ?> aktivních</span>
      <a href="packages.php?new=1" class="btn-master primary">➕ Nový balíček</a>
    </div>
  </div>

  <?php if ($flash_ok): ?><div class="flash ok">✅ <?= htmlspecialchars($flash_ok) ?></div><?php endif; ?>
  <?php if ($flash_err): ?><div class="flash err">❌ <?= htmlspecialchars($flash_err) ?></div><?php endif; ?>

  <?php if ($editing || $isNew):
    $p = $editing ?: [
      'id' => 0, 'key' => '', 'name_cs' => '', 'name_en' => '', 'name_es' => '',
      'description_cs' => '', 'description_en' => '', 'description_es' => '',
      'icon' => '📦', 'price_kc' => 0, 'price_eur' => null, 'price_usd' => null,
      'bit_pos' => null, 'is_core' => 0, 'is_active' => 1, 'sort_order' => count($packages) + 1,
      'features_json' => '[]',
    ];
  ?>
    <div class="pkg-form">
      <h2><?= $editing ? 'Upravit balíček: ' . htmlspecialchars($p['name_cs']) : 'Nový balíček' ?></h2>
      <form method="POST">
        <?php vendor_csrf_field(); ?>
        <input type="hidden" name="action" value="save">
        <input type="hidden" name="id" value="<?= (int) $p['id'] ?>">

        <div class="row">
          <div>
            <label>Klíč <small>(unikátní, a-z0-9_)</small></label>
            <input type="text" name="key" value="<?= htmlspecialchars($p['key']) ?>" required pattern="[a-z0-9_]+">
          </div>
          <div>
            <label>Ikona <small>(emoji)</small></label>
            <input type="text" name="icon" value="<?= htmlspecialchars($p['icon']) ?>" maxlength="8">
          </div>
          <div>
            <label>Pořadí <small>(sort)</small></label>
            <input type="number" name="sort_order" value="<?= (int) $p['sort_order'] ?>" min="0">
          </div>
          <div>
            <label>Bit pozice <small>(0–19, prázdné = core)</small></label>
            <input type="number" name="bit_pos" value="<?= $p['bit_pos'] === null ? '' : (int) $p['bit_pos'] ?>" min="0" max="19">
          </div>
        </div>

        <div class="row">
          <div>
            <label>Název CS *</label>
            <input type="text" name="name_cs" value="<?= htmlspecialchars($p['name_cs']) ?>" required>
          </div>
          <div>
            <label>Název EN</label>
            <input type="text" name="name_en" value="<?= htmlspecialchars($p['name_en'] ?? '') ?>">
          </div>
          <div>
            <label>Název ES</label>
            <input type="text" name="name_es" value="<?= htmlspecialchars($p['name_es'] ?? '') ?>">
          </div>
        </div>

        <div class="row">
          <div>
            <label>Popis CS</label>
            <textarea name="description_cs" rows="3"><?= htmlspecialchars($p['description_cs'] ?? '') ?></textarea>
          </div>
          <div>
            <label>Popis EN</label>
            <textarea name="description_en" rows="3"><?= htmlspecialchars($p['description_en'] ?? '') ?></textarea>
          </div>
          <div>
            <label>Popis ES</label>
            <textarea name="description_es" rows="3"><?= htmlspecialchars($p['description_es'] ?? '') ?></textarea>
          </div>
        </div>

        <div class="row">
          <div>
            <label>Cena Kč *</label>
            <input type="number" name="price_kc" value="<?= (float) $p['price_kc'] ?>" min="0" step="1" required>
          </div>
          <div>
            <label>Cena EUR <small>(volitelné)</small></label>
            <input type="number" name="price_eur" value="<?= $p['price_eur'] === null ? '' : (float) $p['price_eur'] ?>" min="0" step="0.01">
          </div>
          <div>
            <label>Cena USD <small>(volitelné)</small></label>
            <input type="number" name="price_usd" value="<?= $p['price_usd'] === null ? '' : (float) $p['price_usd'] ?>" min="0" step="0.01">
          </div>
        </div>

        <div>
          <label>Features <small>(JSON array — např. ["HACCP","Cenovky","Push notifikace"])</small></label>
          <textarea name="features_json" rows="3"><?= htmlspecialchars($p['features_json'] ?? '[]') ?></textarea>
        </div>

        <div class="checks">
          <label><input type="checkbox" name="is_core" <?= $p['is_core'] ? 'checked' : '' ?>> Core (zahrnuto vždy)</label>
          <label><input type="checkbox" name="is_active" <?= $p['is_active'] ? 'checked' : '' ?>> Aktivní (zobrazovat v eshopu)</label>
        </div>

        <div class="form-actions">
          <button type="submit" class="btn-master primary">💾 Uložit</button>
          <a href="packages.php" class="btn-master secondary">← Zpět</a>
        </div>
      </form>
    </div>
  <?php endif; ?>

  <div class="pkg-grid">
    <?php foreach ($packages as $p): ?>
      <div class="pkg-card <?= $p['is_active'] ? '' : 'inactive' ?> <?= $p['is_core'] ? 'core-card' : '' ?>">
        <div class="head">
          <span class="icon"><?= htmlspecialchars($p['icon'] ?: '📦') ?></span>
          <div>
            <div class="name"><?= htmlspecialchars($p['name_cs']) ?></div>
            <div class="key"><?= htmlspecialchars($p['key']) ?></div>
          </div>
        </div>

        <!-- 💰 PRICE TAG — vrchol karty pro produktovou prezentaci -->
        <div class="price-tag">
          <span class="price"><?= number_format((float) $p['price_kc'], 0, ',', ' ') ?></span>
          <span class="price-currency">Kč</span>
          <?php if ($p['price_eur']): ?><span class="price-alt">· €<?= number_format((float) $p['price_eur'], 0) ?></span><?php endif; ?>
          <span class="price-onetime" style="margin-left:auto">JEDNORÁZOVĚ</span>
        </div>

        <div class="desc"><?= htmlspecialchars($p['description_cs'] ?: '—') ?></div>

        <div class="pkg-badges">
          <?php if ($p['is_active']): ?><span class="pkg-bd active">✓ Aktivní v eshopu</span><?php else: ?><span class="pkg-bd inactive">Skryto z eshopu</span><?php endif; ?>
          <?php if ($p['bit_pos'] !== null): ?><span class="pkg-bd" style="background:#eaf3fc;color:#0a4a8b">bit <?= (int) $p['bit_pos'] ?></span><?php endif; ?>
        </div>

        <!-- 🔗 PROVÁZANOST: funkce + updates -->
        <div class="pkg-links">
          <?php $featCount = $featuresPerPackage[$p['key']] ?? 0; ?>
          <?php $updInfo = $updatesPerPackage[$p['key']] ?? null; ?>
          <span class="pkg-link" title="Počet funkcí v balíčku (z api/_features.php FEATURE_MAP)">
            ⚡ <strong><?= $featCount ?></strong> <?= $featCount === 1 ? 'funkce' : ($featCount >= 2 && $featCount <= 4 ? 'funkce' : 'funkcí') ?>
          </span>
          <?php if ($updInfo): ?>
            <a href="updates.php?filter_package=<?= htmlspecialchars($p['key']) ?>" class="pkg-link clickable" title="Updates s tímto balíčkem">
              🔄 <strong><?= $updInfo['count'] ?></strong> update<?= $updInfo['count'] === 1 ? '' : 'y' ?> · v<?= htmlspecialchars($updInfo['latest']) ?>
            </a>
          <?php else: ?>
            <span class="pkg-link muted" title="Zatím žádné updates pro tento balíček">🔄 0 updates</span>
          <?php endif; ?>
        </div>

        <div class="actions">
          <a href="packages.php?edit=<?= (int) $p['id'] ?>" class="btn-master primary">✏️ Edit</a>
          <form method="POST" style="display:inline">
            <?php vendor_csrf_field(); ?>
            <input type="hidden" name="action" value="toggle">
            <input type="hidden" name="id" value="<?= (int) $p['id'] ?>">
            <button type="submit" class="btn-master secondary"><?= $p['is_active'] ? '🚫 Skrýt' : '✅ Aktivovat' ?></button>
          </form>
          <form method="POST" style="display:inline" onsubmit="return confirm('Opravdu smazat balíček &quot;<?= htmlspecialchars($p['name_cs']) ?>&quot;?')">
            <?php vendor_csrf_field(); ?>
            <input type="hidden" name="action" value="delete">
            <input type="hidden" name="id" value="<?= (int) $p['id'] ?>">
            <button type="submit" class="btn-master secondary" style="background:#fde7e9;color:#a8232f" title="Smazat balíček">🗑️</button>
          </form>
        </div>
      </div>
    <?php endforeach; ?>
  </div>

  <div class="panel-master" style="margin-top:24px">
    <h2>ℹ️ Jak to funguje</h2>
    <ul style="font-size:13px;color:#3a3a3c;line-height:1.7;padding-left:20px">
      <li><strong>Klíč</strong> je technický identifikátor (např. <code>cukrarna</code>). Musí ladit s <code>LICENSE_PACKAGE_BITS</code> v <code>api/_license.php</code>.</li>
      <li><strong>Bit pozice</strong> určuje, jaký bit v 20-bitové masce licence reprezentuje tento balíček (0–19). Core balíčky bit nemají.</li>
      <li>Balíček označený jako <strong>Core</strong> je vždy součástí licence — nezávisle na bitmaskě.</li>
      <li>Eshop na appek.cz čte JEN balíčky kde <code>is_active = 1</code>.</li>
      <li>Při změně ceny/popisu se zobrazí na webu <em>během 5 minut</em> (cachování).</li>
    </ul>
  </div>

</main>

<?php vendor_render_footer(); ?>
</body>
</html>
