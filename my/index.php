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

require_once __DIR__ . '/../vendor/_lib.php';   // vendor_db(), VENDOR_DB_*
require_once __DIR__ . '/../vendor/_mail.php';  // vendor_send_mail()

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

function e(?string $s): string { return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8'); }

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
          <div style="margin-top:14px"><a class="btn" href="<?= e($ESHOP) ?>">Prodloužit</a></div>
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
