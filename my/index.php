<?php
/**
 * 🗓️ my.appek.cz — ZÁKAZNICKÝ MINIPORTÁL
 *
 * Účel: zákazník po přihlášení (magic-link e-mailem) vidí stav své licence/pronájmu:
 *   „zaplaceno do", „zbývá X dní", typ (pronájem / roční), a tlačítko Prodloužit.
 *
 * Read-only pohled na vendor_licenses (žádná mutace zákaznických instalací).
 * Docroot: public_html/my  →  vendor je sourozenec (../vendor).
 *
 * Bezpečnost:
 *   - magic-link (bez hesla): jednorázový token, platnost 20 min, single-use
 *   - žádná enumerace e-mailů (vždy stejná neutrální hláška po odeslání)
 *   - session regenerace po loginu (anti-fixation)
 */

require_once __DIR__ . '/../vendor/_lib.php';   // vendor_db(), VENDOR_DB_*, license_packages()
require_once __DIR__ . '/../vendor/_mail.php';  // vendor_send_mail()
require_once __DIR__ . '/../vendor/_gopay.php'; // gopay_create_payment() — prodloužení pronájmu

session_name('APPEKMYSID');
session_set_cookie_params(['lifetime' => 0, 'path' => '/', 'secure' => true, 'httponly' => true, 'samesite' => 'Lax']);
session_start();

const MY_TOKEN_TTL_MIN = 20;
const MY_GRACE_DAYS    = 14;   // musí sedět s api/_license_enforce.php LICENSE_GRACE_DAYS
$BASE  = 'https://my.appek.cz';
$ESHOP = 'https://appek.cz/checkout.html';   // Prodloužit → eshop (Fáze 2 napojí konkrétní rental produkt)

$pdo = vendor_db();

// ── Tabulka login tokenů (self-healing) ────────────────────────────
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS my_login_tokens (
        token CHAR(64) NOT NULL PRIMARY KEY,
        email VARCHAR(190) NOT NULL,
        created_at DATETIME NOT NULL,
        expires_at DATETIME NOT NULL,
        used TINYINT(1) NOT NULL DEFAULT 0,
        INDEX idx_email (email)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
} catch (Throwable $e) { /* portál nesmí spadnout kvůli migraci */ }

// 🆕 PRODLOUŽENÍ: sloupec pro navázání objednávky na prodlužovanou licenci (idempotentně)
try {
    $hasRn = $pdo->query("SHOW COLUMNS FROM vendor_shop_orders LIKE 'renew_license_id'")->fetchAll();
    if (!$hasRn) $pdo->exec("ALTER TABLE vendor_shop_orders ADD COLUMN renew_license_id INT NULL");
} catch (Throwable $e) { /* ignore */ }

function e(?string $s): string { return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8'); }

/** Měsíční cena pronájmu licence = součet price_month_kc balíčků obsažených v klíči (vč. core). */
function my_license_monthly_price(PDO $pdo, string $key): float {
    $pkgs = function_exists('license_packages') ? license_packages($key) : ['core'];
    $pkgs = array_values(array_unique(array_map('strtolower', $pkgs)));
    if (!$pkgs) return 0.0;
    $in = implode(',', array_fill(0, count($pkgs), '?'));
    try {
        $st = $pdo->prepare("SELECT COALESCE(SUM(price_month_kc),0) FROM vendor_packages WHERE LOWER(`key`) IN ($in)");
        $st->execute($pkgs);
        return (float) $st->fetchColumn();
    } catch (Throwable $e) { return 0.0; }
}

/** Najdi licence patřící e-mailu (přímý customer_email nebo přes admin login instalace). */
function my_licenses(PDO $pdo, string $email): array {
    $em = strtolower(trim($email));
    $sql = "SELECT DISTINCT l.* FROM vendor_licenses l
            LEFT JOIN vendor_install_emails ie ON ie.license_id = l.id
            WHERE LOWER(l.customer_email) = :e1 OR LOWER(ie.email) = :e2
            ORDER BY (l.expires_at IS NULL) ASC, l.expires_at DESC";
    $st = $pdo->prepare($sql);
    $st->execute(['e1' => $em, 'e2' => $em]);
    return $st->fetchAll();
}

/** Stav platnosti — zrcadlí api/_license_enforce.php::license_validity(). */
function my_status(array $l): array {
    $vu = $l['expires_at'] ?? null;
    $rental = !empty($l['rental']);
    if (($l['status'] ?? '') === 'revoked') {
        return ['key' => 'revoked', 'label' => 'Odvolána', 'cls' => 'bad', 'days' => null, 'rental' => $rental, 'vu' => $vu];
    }
    if (!$vu || !preg_match('/^\d{4}-\d{2}-\d{2}/', (string) $vu)) {
        return ['key' => 'perpetual', 'label' => 'Bez expirace (∞)', 'cls' => 'ok', 'days' => null, 'rental' => $rental, 'vu' => null];
    }
    $now = time();
    $exp = strtotime(substr($vu, 0, 10) . ' 23:59:59');
    $graceEnd = $exp + MY_GRACE_DAYS * 86400;
    if ($now <= $exp) {
        $d = (int) ceil(($exp - $now) / 86400);
        return ['key' => $d <= 30 ? 'expiring' : 'active', 'label' => $d <= 30 ? 'Brzy vyprší' : 'Aktivní',
                'cls' => $d <= 30 ? 'warn' : 'ok', 'days' => $d, 'rental' => $rental, 'vu' => $vu];
    }
    if ($now <= $graceEnd) {
        $d = (int) ceil(($graceEnd - $now) / 86400);
        return ['key' => 'grace', 'label' => 'Po splatnosti (odklad)', 'cls' => 'warn', 'days' => $d, 'rental' => $rental, 'vu' => $vu];
    }
    // Po grace
    return ['key' => $rental ? 'locked' : 'expired',
            'label' => $rental ? 'Pronájem vypršel — appka zamčena' : 'Vypršelo (balíčky vypnuty)',
            'cls' => 'bad', 'days' => 0, 'rental' => $rental, 'vu' => $vu];
}

function my_layout(string $title, string $body): void {
    global $BASE;
    ?><!doctype html>
<html lang="cs"><head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex,nofollow">
<title><?= e($title) ?> — APPEK</title>
<style>
  :root { --blue:#0071e3; --ink:#1d1d1f; --mut:#6e6e73; --line:#e5e5ea; --bg:#f5f5f7; }
  * { box-sizing:border-box; }
  body { margin:0; font:16px/1.55 -apple-system,"Segoe UI",Roboto,Arial,sans-serif; color:var(--ink); background:var(--bg); }
  .wrap { max-width:640px; margin:0 auto; padding:32px 20px 60px; }
  .brand { font-weight:800; font-size:22px; letter-spacing:-.02em; }
  .brand small { color:var(--mut); font-weight:600; }
  .card { background:#fff; border:1px solid var(--line); border-radius:16px; padding:24px; margin-top:20px; }
  h1 { font-size:24px; margin:6px 0 4px; letter-spacing:-.02em; }
  p.lead { color:var(--mut); margin:0 0 18px; }
  label { display:block; font-weight:600; font-size:14px; margin:0 0 6px; }
  input[type=email] { width:100%; padding:13px 14px; font-size:16px; border:1px solid var(--line); border-radius:11px; }
  button, .btn { display:inline-block; border:0; border-radius:11px; padding:13px 20px; font-size:16px; font-weight:600;
    background:var(--blue); color:#fff; cursor:pointer; text-decoration:none; text-align:center; }
  button.wide { width:100%; margin-top:14px; }
  .btn.ghost { background:#fff; color:var(--blue); border:1px solid var(--blue); }
  .muted { color:var(--mut); font-size:14px; }
  .lic { border:1px solid var(--line); border-radius:14px; padding:18px; margin-top:14px; }
  .lic h3 { margin:0 0 2px; font-size:17px; }
  .row { display:flex; justify-content:space-between; gap:12px; padding:7px 0; border-top:1px solid var(--line); }
  .row:first-of-type { border-top:0; }
  .row .k { color:var(--mut); } .row .v { font-weight:600; text-align:right; }
  .pill { display:inline-block; padding:3px 10px; border-radius:999px; font-size:12px; font-weight:700; }
  .pill.ok { background:#e3f6e8; color:#137a3a; } .pill.warn { background:#fff4e0; color:#9a6400; } .pill.bad { background:#fde5e5; color:#b3261e; }
  .tag { display:inline-block; padding:2px 9px; border-radius:999px; font-size:12px; font-weight:600; background:#eef1f6; color:#3a3a3c; }
  .alert { border-radius:11px; padding:13px 15px; margin:0 0 16px; font-size:15px; }
  .alert.ok { background:#e3f6e8; color:#137a3a; } .alert.bad { background:#fde5e5; color:#b3261e; }
  .top { display:flex; justify-content:space-between; align-items:center; }
  a.logout { color:var(--mut); font-size:14px; text-decoration:none; }
  .foot { text-align:center; margin-top:26px; color:var(--mut); font-size:13px; }
  .foot a { color:var(--mut); }
</style></head>
<body><div class="wrap">
  <div class="top"><div class="brand">APPEK <small>portál</small></div><?= isset($_SESSION['my_email']) ? '<a class="logout" href="?logout=1">Odhlásit</a>' : '' ?></div>
  <?= $body ?>
  <div class="foot">APPEK · <a href="https://appek.cz">appek.cz</a> · <a href="mailto:info@appek.cz">info@appek.cz</a></div>
</div></body></html><?php
}

// ═══════════════════════════════════════════════════════════════════
// ROUTER
// ═══════════════════════════════════════════════════════════════════

// ── Odhlášení ──
if (isset($_GET['logout'])) {
    $_SESSION = [];
    session_destroy();
    header('Location: ' . $BASE);
    exit;
}

// ── Ověření magic-link tokenu (?t=…) ──
if (isset($_GET['t'])) {
    $tok = (string) $_GET['t'];
    if (preg_match('/^[a-f0-9]{64}$/', $tok)) {
        $st = $pdo->prepare("SELECT * FROM my_login_tokens WHERE token = :t AND used = 0 AND expires_at >= NOW() LIMIT 1");
        $st->execute(['t' => $tok]);
        if ($r = $st->fetch()) {
            $pdo->prepare("UPDATE my_login_tokens SET used = 1 WHERE token = :t")->execute(['t' => $tok]);
            session_regenerate_id(true);   // anti-fixation
            $_SESSION['my_email'] = $r['email'];
            header('Location: ' . $BASE);
            exit;
        }
    }
    my_layout('Přihlášení', '<div class="card"><div class="alert bad">Odkaz je neplatný nebo vypršel (platí 20 minut, jen jednou).</div><a class="btn ghost" href="' . e($BASE) . '">Zkusit znovu</a></div>');
    exit;
}

// ── Odeslání magic-linku (POST) ──
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'send_link') {
    $email = strtolower(trim((string) ($_POST['email'] ?? '')));
    // Vždy stejná odpověď (žádná enumerace). Odešli jen když e-mail reálně má licenci.
    if (filter_var($email, FILTER_VALIDATE_EMAIL) && my_licenses($pdo, $email)) {
        $token = bin2hex(random_bytes(32));
        $pdo->prepare("INSERT INTO my_login_tokens (token, email, created_at, expires_at)
                       VALUES (:t, :e, NOW(), DATE_ADD(NOW(), INTERVAL :ttl MINUTE))")
            ->execute(['t' => $token, 'e' => $email, 'ttl' => MY_TOKEN_TTL_MIN]);
        $link = $BASE . '/?t=' . $token;
        $html = '<p>Dobrý den,</p><p>klikněte pro přihlášení do zákaznického portálu APPEK:</p>'
              . '<p><a href="' . e($link) . '" style="display:inline-block;background:#0071e3;color:#fff;padding:12px 22px;border-radius:10px;text-decoration:none;font-weight:600">Přihlásit se</a></p>'
              . '<p style="color:#6e6e73;font-size:13px">Odkaz platí 20 minut a lze jej použít jen jednou. Pokud jste o přihlášení nežádali, ignorujte tento e-mail.</p>';
        $text = "Přihlášení do portálu APPEK:\n$link\n\nOdkaz platí 20 minut, jednorázově.";
        try { vendor_send_mail($email, 'Přihlášení do portálu APPEK', $html, $text); } catch (Throwable $e) { /* neprozrazuj */ }
    }
    my_layout('Odkaz odeslán', '<div class="card"><div class="alert ok">Pokud e-mail patří k licenci APPEK, poslali jsme na něj přihlašovací odkaz. Zkontrolujte schránku (i spam).</div><p class="muted">Odkaz platí 20 minut.</p></div>');
    exit;
}

// ── PRODLOUŽENÍ: platba přes GoPay (přihlášený) → vytvoří renewal objednávku a přesměruje na bránu ──
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'renew_pay' && !empty($_SESSION['my_email'])) {
    $email = $_SESSION['my_email'];
    $licId = (int) ($_POST['license_id'] ?? 0);
    $months = (int) ($_POST['months'] ?? 0);
    if (!in_array($months, [1, 3, 6, 12], true)) $months = 1;
    $lic = null;
    foreach (my_licenses($pdo, $email) as $l) { if ((int) $l['id'] === $licId) { $lic = $l; break; } }
    if (!$lic) { my_layout('Chyba', '<div class="card"><div class="alert bad">Licence nenalezena.</div><a class="btn ghost" href="' . e($BASE) . '">← Zpět</a></div>'); exit; }
    $monthly = my_license_monthly_price($pdo, $lic['license_key']);
    $total = (int) round($monthly * $months);
    if ($total <= 0) { my_layout('Chyba', '<div class="card"><div class="alert bad">Pro tuto licenci není nastavena měsíční cena — napište na <a href="mailto:info@appek.cz">info@appek.cz</a>.</div></div>'); exit; }
    $pkgsJson = json_encode(function_exists('license_packages') ? license_packages($lic['license_key']) : ['core']);
    $orderNo = 'APPEK-REN-' . date('Ymd') . '-' . strtoupper(bin2hex(random_bytes(2)));
    $ip = trim(explode(',', $_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0')[0]);
    $pdo->prepare("INSERT INTO vendor_shop_orders
        (order_no, customer_name, customer_company, customer_email, customer_phone, install_url,
         tier, packages_json, total_kc, currency, payment_method, payment_status, rental_months, renew_license_id, ip, created_at)
        VALUES (:no,:n,:co,:e,:p,:u,'renewal',:pkg,:total,'CZK','card','pending',:m,:rid,:ip,NOW())")
        ->execute([
            'no' => $orderNo, 'n' => $lic['customer_name'] ?: 'Zákazník', 'co' => $lic['customer_company'] ?? null,
            'e' => $lic['customer_email'] ?: $email, 'p' => $lic['customer_phone'] ?? null, 'u' => $lic['install_url'] ?? null,
            'pkg' => $pkgsJson, 'total' => $total, 'm' => $months, 'rid' => $licId, 'ip' => $ip,
        ]);
    $oid = (int) $pdo->lastInsertId();
    try {
        $session = gopay_create_payment([
            'order_no'         => $orderNo,
            'amount_kc'        => (float) $total,
            'currency'         => 'CZK',
            'description'      => 'APPEK pronájem — prodloužení ' . $months . ' měs.',
            'customer_email'   => $lic['customer_email'] ?: $email,
            'customer_name'    => $lic['customer_name'] ?: '',
            'return_url'       => 'https://appek.cz/payment-done.html?order=' . urlencode($orderNo),
            'notification_url' => 'https://appek.cz/api/gopay_callback.php',
        ]);
    } catch (Throwable $e) { $session = ['ok' => false]; error_log('my renew gopay: ' . $e->getMessage()); }
    if (!empty($session['ok']) && !empty($session['gateway_url'])) {
        $pdo->prepare("UPDATE vendor_shop_orders SET payment_id = :pid WHERE id = :id")
            ->execute(['pid' => $session['payment_id'] ?? '', 'id' => $oid]);
        header('Location: ' . $session['gateway_url']); exit;
    }
    my_layout('Chyba platby', '<div class="card"><div class="alert bad">Platbu se teď nepodařilo spustit. Zkuste to prosím znovu, nebo napište na <a href="mailto:info@appek.cz">info@appek.cz</a>.</div><a class="btn ghost" href="' . e($BASE) . '">← Zpět</a></div>');
    exit;
}

// ── PRODLOUŽENÍ: výběr období (přihlášený) ──
if (isset($_GET['renew']) && !empty($_SESSION['my_email'])) {
    $email = $_SESSION['my_email'];
    $licId = (int) $_GET['renew'];
    $lic = null;
    foreach (my_licenses($pdo, $email) as $l) { if ((int) $l['id'] === $licId) { $lic = $l; break; } }
    if (!$lic) { my_layout('Chyba', '<div class="card"><div class="alert bad">Licence nenalezena.</div><a class="btn ghost" href="' . e($BASE) . '">← Zpět</a></div>'); exit; }
    $monthly = my_license_monthly_price($pdo, $lic['license_key']);
    $nazev = $lic['customer_company'] ?: ($lic['install_url'] ?: $lic['customer_name']);
    ob_start(); ?>
    <div class="card">
      <h1>Prodloužit pronájem</h1>
      <p class="lead"><?= e($nazev ?: 'APPEK licence') ?></p>
      <?php if ($monthly <= 0): ?>
        <div class="alert bad">Pro tuto licenci zatím není nastavena měsíční cena. Napište na <a href="mailto:info@appek.cz">info@appek.cz</a> a rádi pomůžeme.</div>
      <?php else: foreach ([1, 3, 6] as $m): $cena = number_format($monthly * $m, 0, ',', ' '); ?>
        <form method="post" style="margin:0 0 10px">
          <input type="hidden" name="action" value="renew_pay">
          <input type="hidden" name="license_id" value="<?= (int) $lic['id'] ?>">
          <input type="hidden" name="months" value="<?= $m ?>">
          <button class="btn wide" type="submit" style="display:flex;justify-content:space-between;align-items:center;gap:10px">
            <span><?= $m ?> <?= $m === 1 ? 'měsíc' : ($m < 5 ? 'měsíce' : 'měsíců') ?></span>
            <strong><?= $cena ?> Kč</strong>
          </button>
        </form>
      <?php endforeach; endif; ?>
      <p class="muted" style="margin-top:14px">Platba kartou · Apple Pay · Google Pay přes GoPay. Po zaplacení se pronájem prodlouží a aplikace se do pár minut sama odemkne. Vaše data zůstávají beze změny.</p>
      <a class="btn ghost" href="<?= e($BASE) ?>">← Zpět</a>
    </div>
    <?php
    my_layout('Prodloužit', ob_get_clean());
    exit;
}

// ── Dashboard (přihlášený) ──
if (!empty($_SESSION['my_email'])) {
    $email = $_SESSION['my_email'];
    $lics = my_licenses($pdo, $email);
    ob_start(); ?>
    <div class="card">
      <h1>Vaše licence</h1>
      <p class="lead"><?= e($email) ?></p>
      <?php if (!$lics): ?>
        <div class="alert bad">K tomuto e-mailu nevidíme žádnou licenci. Napište nám na <a href="mailto:info@appek.cz">info@appek.cz</a>.</div>
      <?php else: foreach ($lics as $l): $s = my_status($l);
        $nazev = $l['customer_company'] ?: ($l['install_url'] ?: $l['customer_name']); ?>
        <div class="lic">
          <h3><?= e($nazev ?: 'APPEK licence') ?>
            <span class="tag"><?= $s['rental'] ? '🗓️ Pronájem' : '🗓️ Roční' ?></span></h3>
          <div class="row"><span class="k">Stav</span><span class="v"><span class="pill <?= e($s['cls']) ?>"><?= e($s['label']) ?></span></span></div>
          <div class="row"><span class="k">Zaplaceno do</span><span class="v"><?= $s['vu'] ? e(date('j. n. Y', strtotime($s['vu']))) : '∞' ?></span></div>
          <?php if ($s['days'] !== null): ?>
          <div class="row"><span class="k"><?= in_array($s['key'], ['expired','locked'], true) ? 'Vypršelo' : 'Zbývá' ?></span>
            <span class="v"><?= in_array($s['key'], ['expired','locked'], true) ? 'ano' : (e((string) $s['days']) . ' ' . ($s['days'] === 1 ? 'den' : ($s['days'] < 5 ? 'dny' : 'dní'))) ?></span></div>
          <?php endif; ?>
          <?php if ($s['rental'] || in_array($s['key'], ['expiring','grace','locked','expired'], true)): ?>
          <div style="margin-top:14px"><a class="btn" href="?renew=<?= (int) $l['id'] ?>">Prodloužit</a></div>
          <?php endif; ?>
        </div>
      <?php endforeach; endif; ?>
    </div>
    <?php
    my_layout('Vaše licence', ob_get_clean());
    exit;
}

// ── Login form (nepřihlášený) ──
ob_start(); ?>
<div class="card">
  <h1>Přihlášení do portálu</h1>
  <p class="lead">Zadejte e-mail, kterým jste APPEK objednali. Pošleme vám přihlašovací odkaz — bez hesla.</p>
  <form method="post">
    <input type="hidden" name="action" value="send_link">
    <label for="email">E-mail</label>
    <input type="email" id="email" name="email" required autocomplete="email" placeholder="vas@email.cz">
    <button class="wide" type="submit">Poslat přihlašovací odkaz</button>
  </form>
  <p class="muted" style="margin-top:16px">Zde uvidíte stav licence: do kdy máte zaplaceno a kolik zbývá. Nespravujete tu vlastní instalaci APPEK.</p>
</div>
<?php
my_layout('Přihlášení', ob_get_clean());
