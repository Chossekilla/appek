<?php
/**
 * ⚙️ SETTINGS — Globální nastavení vendor panelu.
 *
 * - Vlastní heslo
 * - 2FA setup
 * - SMTP konfigurace (pro odesílání licencí e-mailem)
 * - Platební brány (Stripe / GoPay / manual)
 */
require_once __DIR__ . '/_lib.php';
require_once __DIR__ . '/_layout.php';
require_once __DIR__ . '/_mail.php';

$user = vendor_require_login();
$pdo  = vendor_db();
vendor_ensure_settings_table($pdo);
$currentPage = 'settings';

$flash_ok = null;
$flash_err = null;

// ─── Stripe uložení ─────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'save_stripe') {
    try {
        $keys = ['stripe_enabled', 'stripe_environment', 'stripe_secret_key', 'stripe_webhook_secret', 'stripe_currency'];
        foreach ($keys as $k) {
            $v = trim((string) ($_POST[$k] ?? ''));
            if ($k === 'stripe_enabled') $v = isset($_POST['stripe_enabled']) ? '1' : '0';
            // 🐛 fix v2.9.190/192 — pro secret keys: pokud prázdné NEBO obsahuje
            // sentinel '__KEEP__' (kdyby si user kolem masky něco připsal) →
            // skip (zachovat původní). Validní klíče vždy začínají 'sk_' / 'whsec_'.
            if (in_array($k, ['stripe_secret_key', 'stripe_webhook_secret'], true)) {
                if ($v === '' || strpos($v, '__KEEP__') !== false) {
                    continue;
                }
            }
            vendor_mail_set($k, $v);
        }
        vendor_audit($pdo, $user, 'stripe_settings_save', null, null);
        $flash_ok = 'Stripe nastavení uloženo.';
    } catch (Throwable $e) { $flash_err = $e->getMessage(); }
}

// ─── Stripe test connection (v2.9.190/192) ──────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'test_stripe') {
    try {
        require_once __DIR__ . '/_stripe.php';
        $resp = stripe_request('GET', '/account');
        if ($resp['ok'] ?? false) {
            $name = $resp['business_profile']['name'] ?? $resp['settings']['dashboard']['display_name'] ?? $resp['email'] ?? $resp['id'];
            $charges = $resp['charges_enabled'] ?? false;
            $payouts = $resp['payouts_enabled'] ?? false;
            $country = $resp['country'] ?? '?';
            $currency = strtoupper($resp['default_currency'] ?? '?');
            if ($charges && $payouts) {
                $flash_ok = "✅ Stripe připojen: <strong>" . htmlspecialchars($name) . "</strong> · {$country} · {$currency} · charges + payouts ENABLED.";
            } else {
                $issues = [];
                if (!$charges) $issues[] = 'charges_disabled';
                if (!$payouts) $issues[] = 'payouts_disabled';
                $flash_err = "⚠️ Připojeno k <strong>" . htmlspecialchars($name) . "</strong>, ale účet není plně aktivní: " . implode(', ', $issues) . ". Doplň údaje v Stripe Dashboardu (verifikace identity).";
            }
            // 🐛 fix v2.9.192 — vendor_audit 5. parametr je ?string, ne array. JSON encode.
            vendor_audit($pdo, $user, 'stripe_test', null, json_encode(['ok' => true, 'charges' => $charges, 'payouts' => $payouts]));
        } else {
            $flash_err = '❌ Stripe test selhal: ' . htmlspecialchars($resp['error'] ?? 'unknown_error');
            vendor_audit($pdo, $user, 'stripe_test_fail', null, json_encode($resp));
        }
    } catch (Throwable $e) {
        error_log('vendor stripe test exception: ' . $e->getMessage());
        $flash_err = '❌ Test exception: ' . htmlspecialchars($e->getMessage());
    }
}

// ─── DPD uložení ────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'save_dpd') {
    try {
        $keys = ['dpd_enabled', 'dpd_environment', 'dpd_client_id', 'dpd_client_secret', 'dpd_sender_id'];
        foreach ($keys as $k) {
            $v = trim((string) ($_POST[$k] ?? ''));
            if ($k === 'dpd_enabled') $v = isset($_POST['dpd_enabled']) ? '1' : '0';
            vendor_mail_set($k, $v);
        }
        vendor_audit($pdo, $user, 'dpd_settings_save', null, null);
        $flash_ok = 'DPD nastavení uloženo.';
    } catch (Throwable $e) { $flash_err = $e->getMessage(); }
}

// ─── GoPay uložení ──────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'save_gopay') {
    try {
        $keys = ['gopay_enabled', 'gopay_environment', 'gopay_goid', 'gopay_client_id', 'gopay_client_secret'];
        foreach ($keys as $k) {
            $v = trim((string) ($_POST[$k] ?? ''));
            if ($k === 'gopay_enabled') $v = isset($_POST['gopay_enabled']) ? '1' : '0';
            vendor_mail_set($k, $v);
        }
        vendor_audit($pdo, $user, 'gopay_settings_save', null, null);
        $flash_ok = 'GoPay nastavení uloženo.';
    } catch (Throwable $e) { $flash_err = $e->getMessage(); }
}

// ─── Zásilkovna uložení ─────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'save_packeta') {
    try {
        $keys = ['packeta_enabled', 'packeta_api_password', 'packeta_api_id', 'packeta_widget_key'];
        foreach ($keys as $k) {
            $v = trim((string) ($_POST[$k] ?? ''));
            if ($k === 'packeta_enabled') $v = isset($_POST['packeta_enabled']) ? '1' : '0';
            vendor_mail_set($k, $v);
        }
        vendor_audit($pdo, $user, 'packeta_settings_save', null, null);
        $flash_ok = 'Zásilkovna nastavení uloženo.';
    } catch (Throwable $e) { $flash_err = $e->getMessage(); }
}

// ─── SMTP / Mail uložení ────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'save_mail') {
    try {
        $keys = ['mail_from_email', 'mail_from_name', 'smtp_enabled', 'smtp_host',
                 'smtp_port', 'smtp_user', 'smtp_pass', 'smtp_encryption'];
        foreach ($keys as $k) {
            $v = trim((string) ($_POST[$k] ?? ''));
            if ($k === 'smtp_enabled') $v = isset($_POST['smtp_enabled']) ? '1' : '0';
            vendor_mail_set($k, $v);
        }
        vendor_audit($pdo, $user, 'mail_settings_save', null, null);
        $flash_ok = 'E-mail nastavení uloženo.';
    } catch (Throwable $e) {
        $flash_err = 'Uložení selhalo: ' . $e->getMessage();
    }
}

// ─── Test mail ──────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'test_mail') {
    $target = trim($_POST['test_to'] ?? '');
    $err = null;
    if (!filter_var($target, FILTER_VALIDATE_EMAIL)) {
        $flash_err = 'Neplatný e-mail.';
    } else {
        $ok = vendor_send_mail(
            $target,
            '✅ APPEK SMTP test',
            '<h2>SMTP test úspěšný 🎉</h2><p>Tento e-mail byl odeslán z vendor.appek.cz. Pokud ho vidíš, doručování funguje.</p>',
            "SMTP test úspěšný!\n\nTento e-mail byl odeslán z vendor.appek.cz.",
            null,
            $err
        );
        if ($ok) {
            $flash_ok = "Test e-mail odeslán na $target. Zkontroluj inbox + spam.";
        } else {
            $flash_err = "Odeslání selhalo: " . ($err ?: 'unknown');
        }
    }
}

$mailCfg = vendor_mail_settings();

// Načti GoPay + Packeta config přes settings table
$allSettings = $pdo->query("SELECT `key`, `value` FROM vendor_settings")->fetchAll(PDO::FETCH_KEY_PAIR);
$gpCfg = array_merge([
    'gopay_enabled' => '0', 'gopay_environment' => 'sandbox',
    'gopay_goid' => '', 'gopay_client_id' => '', 'gopay_client_secret' => '',
], $allSettings);
$pkCfg = array_merge([
    'packeta_enabled' => '0', 'packeta_api_password' => '',
    'packeta_api_id' => '', 'packeta_widget_key' => '',
], $allSettings);
$strCfg = array_merge([
    'stripe_enabled' => '0', 'stripe_environment' => 'test',
    'stripe_secret_key' => '', 'stripe_webhook_secret' => '', 'stripe_currency' => 'czk',
], $allSettings);
$dpdCfg = array_merge([
    'dpd_enabled' => '0', 'dpd_environment' => 'sandbox',
    'dpd_client_id' => '', 'dpd_client_secret' => '', 'dpd_sender_id' => '',
], $allSettings);

// ─── Změna hesla ────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'change_password') {
    $current = $_POST['current_password'] ?? '';
    $new1 = $_POST['new_password'] ?? '';
    $new2 = $_POST['new_password2'] ?? '';
    try {
        $stmt = $pdo->prepare("SELECT password_hash FROM vendor_users WHERE id = :id");
        $stmt->execute(['id' => $user['id']]);
        $hash = $stmt->fetchColumn();
        if (!password_verify($current, $hash)) throw new Exception('Současné heslo není správně.');
        if (strlen($new1) < 10) throw new Exception('Nové heslo musí mít alespoň 10 znaků.');
        if ($new1 !== $new2) throw new Exception('Nová hesla se neshodují.');
        $pdo->prepare("UPDATE vendor_users SET password_hash = :h WHERE id = :id")
            ->execute(['h' => password_hash($new1, PASSWORD_BCRYPT), 'id' => $user['id']]);
        vendor_audit($pdo, $user, 'password_change', null, null);
        $flash_ok = 'Heslo změněno.';
    } catch (Throwable $e) {
        $flash_err = $e->getMessage();
    }
}

// ─── 2FA setup ──────────────────────────────────────────────────
$stmt = $pdo->prepare("SELECT totp_enabled, totp_secret FROM vendor_users WHERE id = :id");
$stmt->execute(['id' => $user['id']]);
$totpInfo = $stmt->fetch();
$totpEnabled = !empty($totpInfo['totp_enabled']);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'enable_2fa') {
    $secret = $_POST['secret'] ?? '';
    $code = trim($_POST['code'] ?? '');
    if (!totp_verify($secret, $code)) {
        $flash_err = 'Neplatný kód. Zkus znovu.';
    } else {
        $pdo->prepare("UPDATE vendor_users SET totp_secret = :s, totp_enabled = 1 WHERE id = :id")
            ->execute(['s' => $secret, 'id' => $user['id']]);
        vendor_audit($pdo, $user, '2fa_enable', null, null);
        $flash_ok = '2FA aktivováno. Při příštím přihlášení budeš potřebovat 6-místný kód.';
        $totpEnabled = true;
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'disable_2fa') {
    $pdo->prepare("UPDATE vendor_users SET totp_secret = NULL, totp_enabled = 0 WHERE id = :id")
        ->execute(['id' => $user['id']]);
    vendor_audit($pdo, $user, '2fa_disable', null, null);
    $flash_ok = '2FA deaktivováno.';
    $totpEnabled = false;
}

// Generuj nový secret pokud uživatel nemá 2FA
$newSecret = null;
$qrUrl = null;
if (!$totpEnabled) {
    $newSecret = totp_generate_secret();
    $issuer = 'APPEK Vendor';
    $account = $user['username'] . '@vendor.appek.cz';
    $otpauth = sprintf('otpauth://totp/%s:%s?secret=%s&issuer=%s', rawurlencode($issuer), rawurlencode($account), $newSecret, rawurlencode($issuer));
    $qrUrl = 'https://api.qrserver.com/v1/create-qr-code/?size=180x180&data=' . urlencode($otpauth);
}
?><!DOCTYPE html>
<html lang="cs">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="robots" content="noindex,nofollow">
<title>⚙️ Nastavení — APPEK Master</title>
<link rel="stylesheet" href="style.css?v=2.0.37">
<style>
  /* 🆕 v2.9.193 — kompaktnější density (user request).
     Desktop XL (1600+): 4 cols, desktop: 3 cols, laptop: 2 cols, mobile: 1 col.
     Padding/font snížen, info boxy menší. Karty s 'span 2' jsou pro formy
     s 2 sloupci uvnitř. */
  .page-master .settings-grid {
    display: grid !important;
    grid-template-columns: repeat(3, 1fr) !important;
    gap: 12px;
    align-items: start;
    width: 100%;
  }
  @media (min-width: 1600px) {
    .page-master .settings-grid { grid-template-columns: repeat(4, 1fr) !important; }
  }
  @media (max-width: 1280px) {
    .page-master .settings-grid { grid-template-columns: repeat(2, 1fr) !important; }
  }
  @media (max-width: 820px) {
    .page-master .settings-grid { grid-template-columns: 1fr !important; }
  }
  .settings-card {
    background: #fff; border-radius: 10px; padding: 14px 16px;
    box-shadow: 0 1px 3px rgba(0,0,0,0.05);
    min-width: 0;
    overflow: hidden;
    display: flex; flex-direction: column; gap: 8px;
  }
  .settings-card h2 {
    margin: 0; font-size: 14px; font-weight: 600;
    display: flex; align-items: center; gap: 6px;
    padding-bottom: 6px; border-bottom: 1px solid #f0f0f2;
  }
  .settings-card h2 small { font-size: 10px; font-weight: 500; }
  .settings-card .form-row { margin-bottom: 0; }
  .settings-card label {
    display: block; font-size: 11px; font-weight: 600; color: #1d1d1f;
    margin-bottom: 3px; letter-spacing: 0.1px;
  }
  .settings-card input[type="text"],
  .settings-card input[type="password"],
  .settings-card input[type="email"],
  .settings-card select {
    width: 100%; padding: 7px 10px; border: 1px solid #d2d2d7; border-radius: 6px;
    font-family: inherit; font-size: 12px; box-sizing: border-box;
  }
  .settings-card p { margin: 0; font-size: 12px; line-height: 1.45; color: #6e6e73; }
  .settings-card .btn-master { padding: 8px 14px; font-size: 13px; }
  .settings-card form { margin: 0; display: flex; flex-direction: column; gap: 8px; }
  /* Info box — kompaktnější */
  .settings-card .info-box {
    background: rgba(0,122,255,0.06); border-left: 2px solid #0058b8;
    padding: 6px 10px; border-radius: 4px; font-size: 11px; color: #0058b8;
    line-height: 1.4;
  }
  .settings-card .info-box code { font-size: 10px; padding: 1px 4px; }
  .status-line { display: flex; align-items: center; gap: 8px; padding: 8px 12px; border-radius: 8px; font-size: 13px; }
  .status-line.on  { background: rgba(52,199,89,0.12); color: #208438; }
  .status-line.off { background: rgba(255,149,0,0.12); color: #c66800; }
  .flash { padding: 10px 14px; border-radius: 8px; margin-bottom: 12px; font-size: 13px; }
  .flash.ok  { background: #d4edda; color: #155724; }
  .flash.err { background: #f8d7da; color: #721c24; }
  code { font-family: 'SF Mono', Menlo, monospace; font-size: 11px; background: #f5f5f7; padding: 1px 5px; border-radius: 3px; }
  /* Helper: 2-col row uvnitř karty */
  .settings-card .row-2 { display: grid; grid-template-columns: 1fr 1fr; gap: 8px; }
</style>
</head>
<body>

<?php vendor_render_topbar($user, $currentPage); ?>
<?php vendor_render_back(); ?>

<main class="page-master">

  <div class="page-header-master">
    <h1>⚙️ Nastavení</h1>
    <div style="font-size:13px;color:#86868b">Přihlášen: <strong><?= htmlspecialchars($user['display_name'] ?: $user['username']) ?></strong></div>
  </div>

  <?php if ($flash_ok): ?><div class="flash ok">✅ <?= htmlspecialchars($flash_ok) ?></div><?php endif; ?>
  <?php if ($flash_err): ?><div class="flash err">❌ <?= htmlspecialchars($flash_err) ?></div><?php endif; ?>

  <div class="settings-grid">

    <!-- ZMĚNA HESLA -->
    <div class="settings-card">
      <h2>🔒 Změna hesla</h2>
      <form method="POST">
        <input type="hidden" name="action" value="change_password">
        <div class="form-row">
          <label>Současné heslo</label>
          <input type="password" name="current_password" required autocomplete="current-password">
        </div>
        <div class="form-row">
          <label>Nové heslo <small>(min 10 znaků)</small></label>
          <input type="password" name="new_password" required minlength="10" autocomplete="new-password">
        </div>
        <div class="form-row">
          <label>Nové heslo znovu</label>
          <input type="password" name="new_password2" required minlength="10" autocomplete="new-password">
        </div>
        <button type="submit" class="btn-master primary">Změnit heslo</button>
      </form>
    </div>

    <!-- 2FA -->
    <div class="settings-card">
      <h2>🔐 Dvoufaktorové ověření</h2>
      <?php if ($totpEnabled): ?>
        <div class="status-line on">
          <strong>✅ Aktivní</strong> — při příštím přihlášení budeš potřebovat 6-místný kód
        </div>
        <form method="POST" style="margin-top:14px" onsubmit="return confirm('Opravdu deaktivovat 2FA? Účet bude méně chráněn.')">
          <input type="hidden" name="action" value="disable_2fa">
          <button type="submit" class="btn-master secondary">Deaktivovat 2FA</button>
        </form>
      <?php else: ?>
        <div class="status-line off">
          <strong>⚠️ Není aktivní</strong> — doporučujeme zapnout pro produkci
        </div>
        <form method="POST" style="margin-top:14px">
          <input type="hidden" name="action" value="enable_2fa">
          <input type="hidden" name="secret" value="<?= htmlspecialchars($newSecret) ?>">
          <p style="font-size:13px;color:#3a3a3c;margin:0 0 10px">1. Naskenuj QR v Google Authenticator / Authy / 1Password:</p>
          <img src="<?= htmlspecialchars($qrUrl) ?>" alt="QR" style="display:block;margin:0 auto 10px;border:1px solid #e5e5e7;border-radius:8px;padding:6px;background:#fff">
          <p style="font-size:11px;color:#86868b;text-align:center;margin:0 0 14px">Nebo zadej ručně: <code><?= htmlspecialchars($newSecret) ?></code></p>
          <div class="form-row">
            <label>2. Zadej kód z aplikace</label>
            <input type="text" name="code" required pattern="[0-9]{6}" maxlength="6" inputmode="numeric"
                   placeholder="123456" style="text-align:center;letter-spacing:0.2em;font-family:'SF Mono',Menlo,monospace">
          </div>
          <button type="submit" class="btn-master primary">Aktivovat 2FA</button>
        </form>
      <?php endif; ?>
    </div>

    <!-- INFO -->
    <div class="settings-card">
      <h2>ℹ️ Účet</h2>
      <dl style="margin:0;font-size:13px;line-height:1.8">
        <dt style="color:#86868b">Uživatelské jméno:</dt><dd><strong><?= htmlspecialchars($user['username']) ?></strong></dd>
        <dt style="color:#86868b">Role:</dt><dd><strong><?= htmlspecialchars($user['role']) ?></strong></dd>
        <dt style="color:#86868b">Vendor DB:</dt><dd><code><?= htmlspecialchars(VENDOR_DB_NAME) ?>@<?= htmlspecialchars(VENDOR_DB_HOST) ?></code></dd>
        <dt style="color:#86868b">License salt:</dt><dd><code><?= htmlspecialchars(LICENSE_SALT) ?></code></dd>
      </dl>
    </div>

    <!-- SMTP / MAIL -->
    <div class="settings-card">
      <h2>📬 E-mail (auto-odeslání licence)</h2>
      <p style="font-size:13px;color:#6e6e73;line-height:1.5;margin-bottom:14px">
        Po označení objednávky jako zaplacena se zákazníkovi auto-odešle e-mail s licencí.
        Default je PHP <code>mail()</code> (funguje na většině hostingů). Pro spolehlivost zapni SMTP.
      </p>

      <form method="POST">
        <input type="hidden" name="action" value="save_mail">

        <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;margin-bottom:14px">
          <div>
            <label>From e-mail <small>(odesílatel)</small></label>
            <input type="email" name="mail_from_email" value="<?= htmlspecialchars($mailCfg['mail_from_email']) ?>" placeholder="noreply@appek.cz">
          </div>
          <div>
            <label>From jméno</label>
            <input type="text" name="mail_from_name" value="<?= htmlspecialchars($mailCfg['mail_from_name']) ?>" placeholder="APPEK">
          </div>
        </div>

        <label style="display:flex;align-items:center;gap:8px;margin:14px 0;font-weight:600;font-size:13px">
          <input type="checkbox" name="smtp_enabled" value="1" <?= $mailCfg['smtp_enabled'] === '1' ? 'checked' : '' ?>>
          Používat SMTP server <small style="font-weight:400;color:#86868b">(jinak PHP mail())</small>
        </label>

        <div style="display:grid;grid-template-columns:2fr 1fr;gap:12px;margin-bottom:12px">
          <div>
            <label>SMTP host</label>
            <input type="text" name="smtp_host" value="<?= htmlspecialchars($mailCfg['smtp_host']) ?>" placeholder="smtp.gmail.com / smtp.seznam.cz / …">
          </div>
          <div>
            <label>Port</label>
            <input type="text" name="smtp_port" value="<?= htmlspecialchars($mailCfg['smtp_port']) ?>" placeholder="587">
          </div>
        </div>

        <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;margin-bottom:12px">
          <div>
            <label>SMTP user</label>
            <input type="text" name="smtp_user" value="<?= htmlspecialchars($mailCfg['smtp_user']) ?>" placeholder="user@gmail.com">
          </div>
          <div>
            <label>SMTP heslo / app pass</label>
            <input type="password" name="smtp_pass" value="<?= htmlspecialchars($mailCfg['smtp_pass']) ?>" placeholder="••••••••">
          </div>
        </div>

        <div>
          <label>Šifrování</label>
          <select name="smtp_encryption" style="width:100%;padding:9px 12px;border:1px solid #d2d2d7;border-radius:8px;font-family:inherit;font-size:13px">
            <option value="tls" <?= $mailCfg['smtp_encryption'] === 'tls' ? 'selected' : '' ?>>TLS (STARTTLS · port 587)</option>
            <option value="ssl" <?= $mailCfg['smtp_encryption'] === 'ssl' ? 'selected' : '' ?>>SSL (port 465)</option>
            <option value=""    <?= $mailCfg['smtp_encryption'] === '' ? 'selected' : '' ?>>Bez šifrování (port 25)</option>
          </select>
        </div>

        <div style="display:flex;gap:10px;margin-top:16px;padding-top:16px;border-top:1px solid #e5e5e7">
          <button type="submit" class="btn-master primary">💾 Uložit nastavení</button>
        </div>
      </form>

      <div style="margin-top:18px;padding-top:18px;border-top:1px solid #e5e5e7">
        <strong style="font-size:13px">🧪 Test odeslání</strong>
        <form method="POST" style="display:flex;gap:8px;margin-top:10px;flex-wrap:wrap">
          <input type="hidden" name="action" value="test_mail">
          <input type="email" name="test_to" placeholder="tvuj@email.cz" required style="flex:1;min-width:200px;padding:9px 12px;border:1px solid #d2d2d7;border-radius:8px;font-family:inherit;font-size:13px">
          <button type="submit" class="btn-master secondary">📤 Odeslat test</button>
        </form>
      </div>
    </div>

    <!-- STRIPE -->
    <div class="settings-card">
      <h2>💳 Stripe <small style="color:#208438">✅</small></h2>
      <p>Karty, Apple/Google Pay. <a href="https://dashboard.stripe.com/apikeys" target="_blank" style="color:#0058b8">Získat klíče →</a></p>

      <?php
        $hasSk  = !empty($strCfg['stripe_secret_key']);
        $hasWh  = !empty($strCfg['stripe_webhook_secret']);
        $skMask = $hasSk ? substr($strCfg['stripe_secret_key'], 0, 7) . '••••' . substr($strCfg['stripe_secret_key'], -4) : '';
        $whMask = $hasWh ? substr($strCfg['stripe_webhook_secret'], 0, 6) . '••••' . substr($strCfg['stripe_webhook_secret'], -4) : '';
      ?>
      <form method="POST">
        <input type="hidden" name="action" value="save_stripe" id="stripe-action">

        <label style="display:flex;align-items:center;gap:6px;font-weight:600;font-size:12px">
          <input type="checkbox" name="stripe_enabled" value="1" <?= $strCfg['stripe_enabled'] === '1' ? 'checked' : '' ?>>
          Zapnout v checkout
        </label>

        <div class="row-2">
          <div>
            <label>Prostředí</label>
            <select name="stripe_environment">
              <option value="test" <?= $strCfg['stripe_environment'] === 'test' ? 'selected' : '' ?>>Test</option>
              <option value="live" <?= $strCfg['stripe_environment'] === 'live' ? 'selected' : '' ?>>Live</option>
            </select>
          </div>
          <div>
            <label>Měna</label>
            <select name="stripe_currency">
              <option value="czk" <?= $strCfg['stripe_currency'] === 'czk' ? 'selected' : '' ?>>CZK</option>
              <option value="eur" <?= $strCfg['stripe_currency'] === 'eur' ? 'selected' : '' ?>>EUR</option>
              <option value="usd" <?= $strCfg['stripe_currency'] === 'usd' ? 'selected' : '' ?>>USD</option>
            </select>
          </div>
        </div>

        <div>
          <label>Secret Key <?= $hasSk ? '<span style="color:#208438">✓</span>' : '<span style="color:#bf2026">chybí</span>' ?></label>
          <input type="password" name="stripe_secret_key" value="" placeholder="<?= $hasSk ? htmlspecialchars($skMask) : 'sk_test_... / sk_live_...' ?>" style="font-family:'SF Mono',Menlo,monospace" autocomplete="off">
        </div>

        <div>
          <label>Webhook Secret <?= $hasWh ? '<span style="color:#208438">✓</span>' : '<span style="color:#999">volitelné</span>' ?></label>
          <input type="password" name="stripe_webhook_secret" value="" placeholder="<?= $hasWh ? htmlspecialchars($whMask) : 'whsec_...' ?>" style="font-family:'SF Mono',Menlo,monospace" autocomplete="off">
        </div>

        <div class="info-box">
          Webhook URL: <code><?= htmlspecialchars(($_SERVER['REQUEST_SCHEME'] ?? 'https') . '://' . ($_SERVER['HTTP_HOST'] ?? 'appek.cz')) ?>/api/stripe_webhook.php</code>
        </div>

        <div style="display:flex;gap:6px;flex-wrap:wrap">
          <button type="submit" class="btn-master primary" onclick="document.getElementById('stripe-action').value='save_stripe'">💾 Uložit</button>
          <?php if ($hasSk): ?>
            <button type="submit" class="btn-master" onclick="document.getElementById('stripe-action').value='test_stripe'" style="background:#0058b8;color:#fff;border-color:#0058b8" title="GET /v1/account → ověří klíč + status">
              🔌 Test
            </button>
          <?php endif; ?>
        </div>
      </form>
    </div>

    <!-- GOPAY -->
    <div class="settings-card">
      <h2>💳 GoPay — platební brána <small style="font-size:11px;color:#208438">✅ Hotovo</small></h2>
      <p style="font-size:13px;color:#6e6e73;line-height:1.5;margin-bottom:14px">
        Karty (Visa/Mastercard), Apple Pay, Google Pay, bank převod, PayPal. Pro získání credentials:
        <a href="https://help.gopay.com/cs/" target="_blank" style="color:#0058b8">GoPay obchodní účet</a> →
        Nastavení → Integrace → API klíče.
      </p>

      <form method="POST">
        <input type="hidden" name="action" value="save_gopay">

        <label style="display:flex;align-items:center;gap:8px;margin:8px 0 14px;font-weight:600;font-size:13px">
          <input type="checkbox" name="gopay_enabled" value="1" <?= $gpCfg['gopay_enabled'] === '1' ? 'checked' : '' ?>>
          Zapnout GoPay platby v checkout
        </label>

        <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;margin-bottom:12px">
          <div>
            <label>Prostředí</label>
            <select name="gopay_environment" style="width:100%;padding:9px 12px;border:1px solid #d2d2d7;border-radius:8px;font-family:inherit;font-size:13px">
              <option value="sandbox" <?= $gpCfg['gopay_environment'] === 'sandbox' ? 'selected' : '' ?>>Sandbox (testovací)</option>
              <option value="production" <?= $gpCfg['gopay_environment'] === 'production' ? 'selected' : '' ?>>Production (ostrý)</option>
            </select>
          </div>
          <div>
            <label>GoID <small>(číslo obchodníka)</small></label>
            <input type="text" name="gopay_goid" value="<?= htmlspecialchars($gpCfg['gopay_goid']) ?>" placeholder="8123456789">
          </div>
        </div>

        <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;margin-bottom:12px">
          <div>
            <label>Client ID</label>
            <input type="text" name="gopay_client_id" value="<?= htmlspecialchars($gpCfg['gopay_client_id']) ?>" placeholder="1234567890">
          </div>
          <div>
            <label>Client Secret</label>
            <input type="password" name="gopay_client_secret" value="<?= htmlspecialchars($gpCfg['gopay_client_secret']) ?>" placeholder="••••••••">
          </div>
        </div>

        <div style="background:rgba(0,122,255,0.06);border-left:3px solid #0058b8;padding:10px 14px;border-radius:6px;font-size:12px;color:#0058b8;margin-top:8px">
          ℹ️ Callback URL nastav v GoPay administraci: <code><?= htmlspecialchars(($_SERVER['REQUEST_SCHEME'] ?? 'https') . '://' . ($_SERVER['HTTP_HOST'] ?? 'appek.cz')) ?>/api/gopay_callback.php</code>
        </div>

        <button type="submit" class="btn-master primary" style="margin-top:14px">💾 Uložit GoPay</button>
      </form>
    </div>

    <!-- DPD -->
    <div class="settings-card">
      <h2>🚚 DPD CZ — kurýrní doprava <small style="font-size:11px;color:#208438">✅ Hotovo</small></h2>
      <p style="font-size:13px;color:#6e6e73;line-height:1.5;margin-bottom:14px">
        DPD Shipping Service REST API — vyžaduje B2B kontrakt. Pro získání credentials kontaktuj
        <a href="https://www.dpd.cz/business/" target="_blank" style="color:#0058b8">obchodního zástupce DPD CZ</a>.
      </p>

      <form method="POST">
        <input type="hidden" name="action" value="save_dpd">

        <label style="display:flex;align-items:center;gap:8px;margin:8px 0 14px;font-weight:600;font-size:13px">
          <input type="checkbox" name="dpd_enabled" value="1" <?= $dpdCfg['dpd_enabled'] === '1' ? 'checked' : '' ?>>
          Zapnout DPD pro customer admin
        </label>

        <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;margin-bottom:12px">
          <div>
            <label>Prostředí</label>
            <select name="dpd_environment" style="width:100%;padding:9px 12px;border:1px solid #d2d2d7;border-radius:8px;font-family:inherit;font-size:13px">
              <option value="sandbox" <?= $dpdCfg['dpd_environment'] === 'sandbox' ? 'selected' : '' ?>>Sandbox</option>
              <option value="production" <?= $dpdCfg['dpd_environment'] === 'production' ? 'selected' : '' ?>>Production</option>
            </select>
          </div>
          <div>
            <label>Sender ID</label>
            <input type="text" name="dpd_sender_id" value="<?= htmlspecialchars($dpdCfg['dpd_sender_id']) ?>" placeholder="12345">
          </div>
        </div>

        <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;margin-bottom:12px">
          <div>
            <label>Client ID</label>
            <input type="text" name="dpd_client_id" value="<?= htmlspecialchars($dpdCfg['dpd_client_id']) ?>" placeholder="dpd_cz_xxx">
          </div>
          <div>
            <label>Client Secret</label>
            <input type="password" name="dpd_client_secret" value="<?= htmlspecialchars($dpdCfg['dpd_client_secret']) ?>" placeholder="••••••••">
          </div>
        </div>

        <div style="background:rgba(0,122,255,0.06);border-left:3px solid #0058b8;padding:10px 14px;border-radius:6px;font-size:12px;color:#0058b8;margin-top:8px">
          ℹ️ Endpoint v customer adminu: <code>/api/dpd_create_shipment.php</code> · Štítek: <code>/api/dpd_label.php?shipment_id=...</code>
        </div>

        <button type="submit" class="btn-master primary" style="margin-top:14px">💾 Uložit DPD</button>
      </form>
    </div>

    <!-- ZÁSILKOVNA -->
    <div class="settings-card">
      <h2>📦 Zásilkovna (Packeta) <small style="font-size:11px;color:#208438">✅ Hotovo</small></h2>
      <p style="font-size:13px;color:#6e6e73;line-height:1.5;margin-bottom:14px">
        REST API pro vytvoření zásilek + tisk štítků. Pro získání credentials:
        <a href="https://client.packeta.com" target="_blank" style="color:#0058b8">client.packeta.com</a> →
        Klient → API nastavení.
      </p>

      <form method="POST">
        <input type="hidden" name="action" value="save_packeta">

        <label style="display:flex;align-items:center;gap:8px;margin:8px 0 14px;font-weight:600;font-size:13px">
          <input type="checkbox" name="packeta_enabled" value="1" <?= $pkCfg['packeta_enabled'] === '1' ? 'checked' : '' ?>>
          Zapnout Zásilkovnu pro customer admin
        </label>

        <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;margin-bottom:12px">
          <div>
            <label>API password</label>
            <input type="password" name="packeta_api_password" value="<?= htmlspecialchars($pkCfg['packeta_api_password']) ?>" placeholder="••••••••••••">
          </div>
          <div>
            <label>API ID (sender ID)</label>
            <input type="text" name="packeta_api_id" value="<?= htmlspecialchars($pkCfg['packeta_api_id']) ?>" placeholder="12345">
          </div>
        </div>

        <div>
          <label>Widget API key <small>(veřejný — pro výběr výdejního místa)</small></label>
          <input type="text" name="packeta_widget_key" value="<?= htmlspecialchars($pkCfg['packeta_widget_key']) ?>" placeholder="abcd1234efgh5678">
        </div>

        <div style="background:rgba(0,122,255,0.06);border-left:3px solid #0058b8;padding:10px 14px;border-radius:6px;font-size:12px;color:#0058b8;margin-top:12px">
          ℹ️ Pro customer admin: Widget v2 (JS) pro výběr výdejního místa.
          API endpoint pro tvorbu zásilky: <code>/api/zasilkovna_create_packet.php</code>
        </div>

        <button type="submit" class="btn-master primary" style="margin-top:14px">💾 Uložit Zásilkovnu</button>
      </form>
    </div>

  </div>

</main>

<?php vendor_render_footer(); ?>
</body>
</html>
