<?php
/**
 * 🏢 BUSINESS INFO — Centrální údaje o provozovateli.
 *
 * Tyto údaje se automaticky vsadí do:
 *   - obchodni-podminky.html (sekce 2 — Identifikace prodávajícího)
 *   - zasady-ochrany-soukromi.html (sekce 2 — Správce)
 *   - faktury, e-maily, atd.
 *
 * Stored: vendor_settings tabulka, klíče `business_*`.
 * Public API: /api/business_info.php (jen veřejné údaje — bez bankovních dat).
 */
require_once __DIR__ . '/_lib.php';
require_once __DIR__ . '/_layout.php';
require_once __DIR__ . '/_mail.php';

$user = vendor_require_login();
$pdo  = vendor_db();
vendor_ensure_settings_table($pdo);
$currentPage = 'business-info';

if ($_SERVER['REQUEST_METHOD'] === 'POST') vendor_csrf_check();  // 🔐 CSRF

$flash_ok = null;
$flash_err = null;

$fields = [
    // ─── Identifikace ───
    'business_name'         => ['label' => 'Obchodní jméno', 'placeholder' => 'APPEK s.r.o.', 'required' => true],
    'business_ico'          => ['label' => 'IČO', 'placeholder' => '12345678', 'required' => true],
    'business_dic'          => ['label' => 'DIČ', 'placeholder' => 'CZ12345678', 'required' => false],
    'business_vat_payer'    => ['label' => 'Plátce DPH', 'type' => 'checkbox'],
    // ─── Adresa ───
    'business_street'       => ['label' => 'Ulice a č.p.', 'placeholder' => 'Václavské 1'],
    'business_city'         => ['label' => 'Město', 'placeholder' => 'Praha'],
    'business_zip'          => ['label' => 'PSČ', 'placeholder' => '11000'],
    'business_country'      => ['label' => 'Země', 'placeholder' => 'Česká republika'],
    'business_register'     => ['label' => 'Zápis (rejstřík)', 'placeholder' => 'C 12345 vedená u Městského soudu v Praze'],
    // ─── Kontakty ───
    'business_email'        => ['label' => 'Hlavní e-mail', 'placeholder' => 'info@appek.cz', 'type' => 'email'],
    'business_support_email'=> ['label' => 'Support e-mail', 'placeholder' => 'support@appek.cz', 'type' => 'email'],
    'business_gdpr_email'   => ['label' => 'GDPR e-mail', 'placeholder' => 'gdpr@appek.cz', 'type' => 'email'],
    'business_phone'        => ['label' => 'Telefon', 'placeholder' => '+420 777 123 456', 'type' => 'tel'],
    'business_web'          => ['label' => 'Webová adresa', 'placeholder' => 'https://appek.cz', 'type' => 'url'],
    // ─── Banka ───
    'business_bank_account' => ['label' => 'Číslo účtu', 'placeholder' => '123456789/0100'],
    'business_bank_iban'    => ['label' => 'IBAN', 'placeholder' => 'CZ65 0100 0000 0000 0000 0000'],
    'business_bank_swift'   => ['label' => 'SWIFT/BIC', 'placeholder' => 'KOMBCZPP'],
    'business_bank_name'    => ['label' => 'Banka', 'placeholder' => 'Komerční banka'],
    // ─── Právní ───
    'business_tos_effective'=> ['label' => 'Datum účinnosti VOP', 'placeholder' => '17. května 2026'],
    'business_tos_version'  => ['label' => 'Verze VOP', 'placeholder' => '1.0'],
];

// POST — save
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        foreach ($fields as $key => $cfg) {
            $val = $_POST[$key] ?? '';
            if (($cfg['type'] ?? '') === 'checkbox') {
                $val = isset($_POST[$key]) ? '1' : '0';
            } else {
                $val = trim((string) $val);
            }
            if (!empty($cfg['required']) && $val === '') {
                throw new Exception('Povinné pole: ' . $cfg['label']);
            }
            vendor_mail_set($key, $val);
        }
        vendor_audit($pdo, $user, 'business_info_save', null, null);
        $flash_ok = 'Údaje uloženy. Automaticky se aplikují na VOP a Zásady ochrany soukromí.';
    } catch (Throwable $e) {
        $flash_err = $e->getMessage();
    }
}

// Load current values
$current = $pdo->query("SELECT `key`, `value` FROM vendor_settings WHERE `key` LIKE 'business_%'")->fetchAll(PDO::FETCH_KEY_PAIR);
?><!DOCTYPE html>
<html lang="cs">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="robots" content="noindex,nofollow">
<title>🏢 Business Info — APPEK Master</title>
<link rel="stylesheet" href="style.css?v=1.5">
<style>
  .bi-grid { display: grid; grid-template-columns: 1fr; gap: 20px; }
  .bi-card {
    background: #fff; border-radius: 14px; padding: 24px 28px;
    box-shadow: 0 1px 3px rgba(0,0,0,0.04);
  }
  .bi-card h2 {
    font-size: 15px; margin: 0 0 16px;
    display: flex; align-items: center; gap: 8px;
    color: #1d1d1f;
  }
  .bi-card h2 .ico { font-size: 22px; }
  .bi-row {
    display: grid; grid-template-columns: repeat(auto-fit, minmax(240px, 1fr));
    gap: 14px; margin-bottom: 12px;
  }
  .bi-field label {
    display: block; font-size: 12px; font-weight: 600;
    color: #1d1d1f; margin-bottom: 4px;
  }
  .bi-field label .req { color: #b30019; }
  .bi-field input[type="text"], .bi-field input[type="email"],
  .bi-field input[type="tel"], .bi-field input[type="url"] {
    width: 100%; padding: 10px 14px;
    border: 1px solid #d2d2d7; border-radius: 9px;
    font-family: inherit; font-size: 14px;
    box-sizing: border-box;
  }
  .bi-field input:focus { outline: 2px solid #BA7517; border-color: #BA7517; }
  .bi-check {
    display: flex; align-items: center; gap: 8px;
    font-size: 14px; padding: 8px 0;
  }
  .save-bar {
    position: sticky; bottom: 14px;
    background: rgba(255,255,255,0.96); backdrop-filter: blur(10px);
    padding: 16px 24px; border-radius: 12px;
    box-shadow: 0 8px 24px rgba(0,0,0,0.08);
    display: flex; justify-content: space-between; align-items: center;
    margin-top: 20px;
    border: 1px solid #e5e5e7;
  }
  .preview-note {
    background: rgba(0,122,255,0.06);
    border-left: 3px solid #0058b8;
    padding: 14px 18px; border-radius: 10px;
    font-size: 13px; color: #0058b8;
    margin-bottom: 20px;
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
    <h1>🏢 Business Info</h1>
    <div style="font-size:13px;color:#86868b">Údaje provozovatele — automaticky se aplikují na legální dokumenty, faktury, e-maily.</div>
  </div>

  <div class="preview-note">
    💡 Po uložení se údaje <strong>okamžitě promítnou</strong> do
    <a href="https://appek.cz/obchodni-podminky.html" target="_blank" rel="noopener" style="color:#0058b8;font-weight:600">VOP</a> a
    <a href="https://appek.cz/zasady-ochrany-soukromi.html" target="_blank" rel="noopener" style="color:#0058b8;font-weight:600">Zásad ochrany soukromí</a>
    (přes <code>/api/business_info.php</code>).
  </div>

  <?php if ($flash_ok): ?><div class="flash ok">✅ <?= htmlspecialchars($flash_ok) ?></div><?php endif; ?>
  <?php if ($flash_err): ?><div class="flash err">❌ <?= htmlspecialchars($flash_err) ?></div><?php endif; ?>

  <form method="POST" class="bi-grid">
    <?php vendor_csrf_field(); ?>

    <!-- IDENTIFIKACE -->
    <div class="bi-card">
      <h2><span class="ico">🆔</span>Identifikace</h2>
      <div class="bi-row">
        <?php foreach (['business_name', 'business_ico', 'business_dic'] as $k):
          $f = $fields[$k]; ?>
          <div class="bi-field">
            <label><?= htmlspecialchars($f['label']) ?><?= !empty($f['required']) ? ' <span class="req">*</span>' : '' ?></label>
            <input type="<?= htmlspecialchars($f['type'] ?? 'text') ?>" name="<?= htmlspecialchars($k) ?>"
                   value="<?= htmlspecialchars($current[$k] ?? '') ?>"
                   placeholder="<?= htmlspecialchars($f['placeholder']) ?>"
                   <?= !empty($f['required']) ? 'required' : '' ?>>
          </div>
        <?php endforeach; ?>
      </div>
      <div class="bi-check">
        <input type="checkbox" id="cb-vat" name="business_vat_payer" value="1" <?= ($current['business_vat_payer'] ?? '0') === '1' ? 'checked' : '' ?>>
        <label for="cb-vat">Jsem <strong>plátce DPH</strong> (ceny zobrazované včetně DPH, faktura obsahuje rozpis DPH)</label>
      </div>
    </div>

    <!-- ADRESA -->
    <div class="bi-card">
      <h2><span class="ico">📍</span>Sídlo</h2>
      <div class="bi-row">
        <div class="bi-field">
          <label>Ulice a č.p.</label>
          <input type="text" name="business_street" value="<?= htmlspecialchars($current['business_street'] ?? '') ?>" placeholder="Václavské 1">
        </div>
        <div class="bi-field">
          <label>Město</label>
          <input type="text" name="business_city" value="<?= htmlspecialchars($current['business_city'] ?? '') ?>" placeholder="Praha">
        </div>
        <div class="bi-field">
          <label>PSČ</label>
          <input type="text" name="business_zip" value="<?= htmlspecialchars($current['business_zip'] ?? '') ?>" placeholder="11000">
        </div>
        <div class="bi-field">
          <label>Země</label>
          <input type="text" name="business_country" value="<?= htmlspecialchars($current['business_country'] ?? 'Česká republika') ?>" placeholder="Česká republika">
        </div>
      </div>
      <div class="bi-field">
        <label>Zápis v rejstříku <small style="color:#86868b">(obchodní / živnostenský)</small></label>
        <input type="text" name="business_register" value="<?= htmlspecialchars($current['business_register'] ?? '') ?>" placeholder="C 12345 vedená u Městského soudu v Praze">
      </div>
    </div>

    <!-- KONTAKTY -->
    <div class="bi-card">
      <h2><span class="ico">📞</span>Kontakty</h2>
      <div class="bi-row">
        <div class="bi-field">
          <label>Hlavní e-mail</label>
          <input type="email" name="business_email" value="<?= htmlspecialchars($current['business_email'] ?? '') ?>" placeholder="info@appek.cz">
        </div>
        <div class="bi-field">
          <label>Support e-mail</label>
          <input type="email" name="business_support_email" value="<?= htmlspecialchars($current['business_support_email'] ?? '') ?>" placeholder="support@appek.cz">
        </div>
        <div class="bi-field">
          <label>GDPR e-mail</label>
          <input type="email" name="business_gdpr_email" value="<?= htmlspecialchars($current['business_gdpr_email'] ?? '') ?>" placeholder="gdpr@appek.cz">
        </div>
        <div class="bi-field">
          <label>Telefon</label>
          <input type="tel" name="business_phone" value="<?= htmlspecialchars($current['business_phone'] ?? '') ?>" placeholder="+420 777 123 456">
        </div>
        <div class="bi-field">
          <label>Web</label>
          <input type="url" name="business_web" value="<?= htmlspecialchars($current['business_web'] ?? 'https://appek.cz') ?>" placeholder="https://appek.cz">
        </div>
      </div>
    </div>

    <!-- BANKA -->
    <div class="bi-card">
      <h2><span class="ico">🏦</span>Bankovní údaje <small style="font-size:11px;color:#86868b;font-weight:400">(pro faktury a převody)</small></h2>
      <div class="bi-row">
        <div class="bi-field">
          <label>Číslo účtu</label>
          <input type="text" name="business_bank_account" value="<?= htmlspecialchars($current['business_bank_account'] ?? '') ?>" placeholder="123456789/0100">
        </div>
        <div class="bi-field">
          <label>Banka</label>
          <input type="text" name="business_bank_name" value="<?= htmlspecialchars($current['business_bank_name'] ?? '') ?>" placeholder="Komerční banka">
        </div>
        <div class="bi-field">
          <label>IBAN</label>
          <input type="text" name="business_bank_iban" value="<?= htmlspecialchars($current['business_bank_iban'] ?? '') ?>" placeholder="CZ65 0100 0000 0000 0000 0000">
        </div>
        <div class="bi-field">
          <label>SWIFT/BIC</label>
          <input type="text" name="business_bank_swift" value="<?= htmlspecialchars($current['business_bank_swift'] ?? '') ?>" placeholder="KOMBCZPP">
        </div>
      </div>
    </div>

    <!-- PRÁVNÍ -->
    <div class="bi-card">
      <h2><span class="ico">📜</span>Právní</h2>
      <div class="bi-row">
        <div class="bi-field">
          <label>Datum účinnosti VOP</label>
          <input type="text" name="business_tos_effective" value="<?= htmlspecialchars($current['business_tos_effective'] ?? '17. května 2026') ?>" placeholder="17. května 2026">
        </div>
        <div class="bi-field">
          <label>Verze VOP</label>
          <input type="text" name="business_tos_version" value="<?= htmlspecialchars($current['business_tos_version'] ?? '1.0') ?>" placeholder="1.0">
        </div>
      </div>
    </div>

    <div class="save-bar">
      <span style="font-size:13px;color:#6e6e73">💾 Změny se promítnou po uložení do všech dokumentů.</span>
      <button type="submit" class="btn-master primary">💾 Uložit Business Info</button>
    </div>
  </form>

</main>

<?php vendor_render_footer(); ?>
</body>
</html>
