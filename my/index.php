<?php
/**
 * 🗓️ my.appek.cz — ZÁKAZNICKÝ PORTÁL
 *
 * Zákazník po přihlášení (magic-link e-mailem) vidí stav svých licencí (pronájem / roční),
 * může PRODLOUŽIT (karta přes GoPay nebo bankovní převod s QR Platbou), DOPLATIT nezaplacené
 * objednávky a vidí HISTORII PLATEB.
 *
 * 🆕 v3.0.597 — profesionální vzhled + platby:
 *   • prodloužení pronájmu 1/3/6/12 měs. i roční licence o 1 rok (cena = roční ceny balíčků v klíči)
 *   • volba platby: karta (GoPay) / převod (účet, IBAN, VS, QR SPAYD z vendor → Firma)
 *   • nezaplacené objednávky (Zaplatit) + detail objednávky + historie plateb
 *   • CSRF token pro všechny POST akce
 * Prodloužení provede po zaplacení vendor_apply_renewal() (gopay_callback / vendor „zaplaceno").
 *
 * Docroot: public_html/my  →  vendor je sourozenec (../vendor).
 *
 * Bezpečnost:
 *   - magic-link (bez hesla): jednorázový token, platnost 20 min, single-use
 *   - žádná enumerace e-mailů (vždy stejná neutrální hláška po odeslání)
 *   - session regenerace po loginu (anti-fixation), CSRF na POST
 *   - zákazník vidí a platí JEN objednávky/licence svého e-mailu
 */

require_once __DIR__ . '/../vendor/_lib.php';   // vendor_db(), VENDOR_DB_*, license_packages()
require_once __DIR__ . '/../vendor/_mail.php';  // vendor_send_mail(), vendor_send_admin_notification()
require_once __DIR__ . '/../vendor/_gopay.php'; // gopay_create_payment()

session_name('APPEKMYSID');
session_set_cookie_params(['lifetime' => 0, 'path' => '/', 'secure' => true, 'httponly' => true, 'samesite' => 'Lax']);
session_start();

header('X-Frame-Options: DENY');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: same-origin');

const MY_TOKEN_TTL_MIN = 20;
const MY_GRACE_DAYS    = 14;   // musí sedět s api/_license_enforce.php LICENSE_GRACE_DAYS
$BASE  = 'https://my.appek.cz';
// lokální vývoj (php -S localhost:…) — jinak natvrdo produkční adresa (Host hlavička nesmí měnit odkazy v e-mailech)
if (preg_match('/^(localhost|127\.0\.0\.1)(:\d+)?$/', (string) ($_SERVER['HTTP_HOST'] ?? ''))) {
    $BASE = 'http://' . $_SERVER['HTTP_HOST'] . '/my';
}

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

// PRODLOUŽENÍ: sloupec pro navázání objednávky na prodlužovanou licenci (idempotentně)
try {
    $hasRn = $pdo->query("SHOW COLUMNS FROM vendor_shop_orders LIKE 'renew_license_id'")->fetchAll();
    if (!$hasRn) $pdo->exec("ALTER TABLE vendor_shop_orders ADD COLUMN renew_license_id INT NULL");
    // rental_months zakládá i api/shop_buy.php — tady jistota, kdyby první objednávka přišla z portálu
    $hasRm = $pdo->query("SHOW COLUMNS FROM vendor_shop_orders LIKE 'rental_months'")->fetchAll();
    if (!$hasRm) $pdo->exec("ALTER TABLE vendor_shop_orders ADD COLUMN rental_months INT NOT NULL DEFAULT 0");
} catch (Throwable $e) { /* ignore */ }

function e(?string $s): string { return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8'); }
function kc($n): string { return number_format((float) $n, 0, ',', ' ') . ' Kč'; }
function d($s): string { return $s ? date('j. n. Y', strtotime((string) $s)) : '—'; }
function mes(int $m): string { return $m . ' ' . ($m === 1 ? 'měsíc' : ($m < 5 ? 'měsíce' : 'měsíců')); }
function dni(int $n): string { return $n === 1 ? 'den' : ($n < 5 ? 'dny' : 'dní'); }
/** +N měsíců stejně jako MySQL DATE_ADD (31. 10. + 1 měs. = 30. 11., ne PHP přetečení na 1. 12.). */
function add_months(int $ts, int $m): int {
    $y = (int) date('Y', $ts); $mo = (int) date('n', $ts) + $m; $dd = (int) date('j', $ts);
    $y += intdiv($mo - 1, 12); $mo = (($mo - 1) % 12) + 1;
    $last = (int) date('t', mktime(0, 0, 0, $mo, 1, $y));
    return mktime(0, 0, 0, $mo, min($dd, $last), $y);
}

// ── CSRF ──
if (empty($_SESSION['my_csrf'])) $_SESSION['my_csrf'] = bin2hex(random_bytes(16));
function csrf_field(): string { return '<input type="hidden" name="csrf" value="' . e($_SESSION['my_csrf']) . '">'; }
function csrf_ok(): bool { return hash_equals($_SESSION['my_csrf'] ?? '', (string) ($_POST['csrf'] ?? '')); }

/** Klíče balíčků v licenci (malými písmeny, včetně core). */
function my_pkgs(string $key): array {
    $p = function_exists('license_packages') ? license_packages($key) : ['core'];
    return array_values(array_unique(array_map('strtolower', $p)));
}

/** Řádky vendor_packages pro balíčky licence (pořadí jako v eshopu). */
function my_pkg_rows(PDO $pdo, string $key): array {
    $pkgs = my_pkgs($key);
    if (!$pkgs) return [];
    $in = implode(',', array_fill(0, count($pkgs), '?'));
    try {
        $st = $pdo->prepare("SELECT `key`, name_cs, icon, price_kc, price_month_kc, is_core FROM vendor_packages WHERE LOWER(`key`) IN ($in) ORDER BY sort_order, id");
        $st->execute($pkgs);
        return $st->fetchAll();
    } catch (Throwable $e) { return []; }
}

/** Cena prodloužení: pronájem = součet měsíčních cen (vč. core); roční = součet ročních cen add-on balíčků (core je napořád). */
function my_renew_price(PDO $pdo, array $lic, int $months): float {
    $rows = my_pkg_rows($pdo, (string) $lic['license_key']);
    if (!empty($lic['rental'])) {
        return array_sum(array_map(fn($r) => (float) $r['price_month_kc'], $rows)) * $months;
    }
    $rocne = array_sum(array_map(fn($r) => !empty($r['is_core']) ? 0.0 : (float) $r['price_kc'], $rows));
    return $rocne * ($months / 12);
}

/** Nabídka období prodloužení pro licenci. */
function my_renew_periods(array $lic): array {
    return !empty($lic['rental']) ? [1, 3, 6, 12] : [12];
}

/** Najdi licence patřící e-mailu (přímý customer_email nebo přes admin login instalace). */
function my_licenses(PDO $pdo, string $email): array {
    $em = strtolower(trim($email));
    $sql = "SELECT DISTINCT l.* FROM vendor_licenses l
            LEFT JOIN vendor_install_emails ie ON ie.license_id = l.id
            WHERE LOWER(l.customer_email) = :e1 OR LOWER(ie.email) = :e2
            ORDER BY (l.expires_at IS NULL) ASC, l.expires_at ASC";
    try {
        $st = $pdo->prepare($sql);
        $st->execute(['e1' => $em, 'e2' => $em]);
        return $st->fetchAll();
    } catch (Throwable $e) {
        // vendor_install_emails nemusí existovat (čerstvý vendor) → aspoň přímý e-mail licence
        $st = $pdo->prepare("SELECT * FROM vendor_licenses WHERE LOWER(customer_email) = :e
                             ORDER BY (expires_at IS NULL) ASC, expires_at ASC");
        $st->execute(['e' => $em]);
        return $st->fetchAll();
    }
}

function my_license_of(PDO $pdo, string $email, int $id): ?array {
    foreach (my_licenses($pdo, $email) as $l) if ((int) $l['id'] === $id) return $l;
    return null;
}

/** Objednávky zákazníka (podle e-mailu objednávky NEBO licencí, které mu patří). */
function my_orders(PDO $pdo, string $email, string $status = ''): array {
    $em = strtolower(trim($email));
    $licIds = array_map(fn($l) => (int) $l['id'], my_licenses($pdo, $email));
    $licSql = $licIds ? ' OR renew_license_id IN (' . implode(',', $licIds) . ') OR license_id IN (' . implode(',', $licIds) . ')' : '';
    $stSql = $status !== '' ? ' AND payment_status = :s' : '';
    try {
        $st = $pdo->prepare("SELECT * FROM vendor_shop_orders WHERE (LOWER(customer_email) = :e $licSql) $stSql ORDER BY created_at DESC LIMIT 50");
        $p = ['e' => $em]; if ($status !== '') $p['s'] = $status;
        $st->execute($p);
        return $st->fetchAll();
    } catch (Throwable $e) { return []; }
}

function my_order_of(PDO $pdo, string $email, string $orderNo): ?array {
    foreach (my_orders($pdo, $email) as $o) if ($o['order_no'] === $orderNo) return $o;
    return null;
}

/** Bankovní údaje dodavatele (vendor → Firma). */
function my_bank(PDO $pdo): array {
    $b = [];
    try {
        foreach ($pdo->query("SELECT `key`,`value` FROM vendor_settings WHERE `key` LIKE 'business_%'")->fetchAll(PDO::FETCH_KEY_PAIR) as $k => $v) $b[$k] = $v;
    } catch (Throwable $e) {}
    return $b;
}

/** Variabilní symbol objednávky = její ID (číselné, max 10 číslic). */
function my_vs(array $o): string { return (string) (int) $o['id']; }

function my_order_label(array $o): string {
    $m = (int) ($o['rental_months'] ?? 0);
    if (($o['tier'] ?? '') === 'renewal') {
        return 'Prodloužení ' . ($m >= 12 && $m % 12 === 0 ? ($m === 12 ? 'o 1 rok' : 'o ' . ($m / 12) . ' roky') : 'o ' . mes(max(1, $m)));
    }
    return $m > 0 ? 'Pronájem ' . mes($m) : 'Licence APPEK';
}

function my_order_pill(array $o): string {
    $s = $o['payment_status'] ?? '';
    return match ($s) {
        'paid'      => '<span class="pill ok">Zaplaceno</span>',
        'pending'   => '<span class="pill warn">Čeká na platbu</span>',
        'cancelled' => '<span class="pill mute">Zrušeno</span>',
        default     => '<span class="pill mute">' . e($s) . '</span>',
    };
}

/** Stav platnosti — zrcadlí api/_license_enforce.php::license_validity(). */
function my_status(array $l): array {
    $vu = $l['expires_at'] ?? null;
    $rental = !empty($l['rental']);
    if (($l['status'] ?? '') === 'revoked') {
        return ['key' => 'revoked', 'label' => 'Odvolána', 'cls' => 'bad', 'days' => null, 'rental' => $rental, 'vu' => $vu, 'pct' => 0];
    }
    if (!$vu || !preg_match('/^\d{4}-\d{2}-\d{2}/', (string) $vu)) {
        return ['key' => 'perpetual', 'label' => 'Bez expirace', 'cls' => 'ok', 'days' => null, 'rental' => $rental, 'vu' => null, 'pct' => 100];
    }
    $now = time();
    $exp = strtotime(substr($vu, 0, 10) . ' 23:59:59');
    $graceEnd = $exp + MY_GRACE_DAYS * 86400;
    $period = ($rental ? 31 : 366) * 86400;     // pro ukazatel „kolik zbývá z období"
    if ($now <= $exp) {
        $d = (int) ceil(($exp - $now) / 86400);
        $warnAt = $rental ? 7 : 30;
        return ['key' => $d <= $warnAt ? 'expiring' : 'active', 'label' => $d <= $warnAt ? 'Brzy vyprší' : 'Aktivní',
                'cls' => $d <= $warnAt ? 'warn' : 'ok', 'days' => $d, 'rental' => $rental, 'vu' => $vu,
                'pct' => max(3, min(100, (int) round(($exp - $now) / $period * 100)))];
    }
    if ($now <= $graceEnd) {
        $d = (int) ceil(($graceEnd - $now) / 86400);
        return ['key' => 'grace', 'label' => 'Po splatnosti — odklad', 'cls' => 'warn', 'days' => $d, 'rental' => $rental, 'vu' => $vu, 'pct' => 3];
    }
    return ['key' => $rental ? 'locked' : 'expired',
            'label' => $rental ? 'Pronájem vypršel — aplikace zamčena' : 'Vypršelo — balíčky vypnuté',
            'cls' => 'bad', 'days' => 0, 'rental' => $rental, 'vu' => $vu, 'pct' => 0];
}

function my_layout(string $title, string $body, string $active = ''): void {
    global $BASE;
    $logged = !empty($_SESSION['my_email']);
    ?><!doctype html>
<html lang="cs"><head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex,nofollow">
<meta name="theme-color" content="#BA7517">
<title><?= e($title) ?> — APPEK portál</title>
<style>
  :root { --acc:#BA7517; --acc-2:#9A5F0F; --acc-wash:#FBF1E1; --ink:#1d1d1f; --ink-2:#3a3a3c; --mut:#6e6e73; --line:#e6e6ea;
          --bg:#f5f5f7; --card:#fff; --ok:#137a3a; --ok-bg:#e3f6e8; --warn:#9a6400; --warn-bg:#fff4e0; --bad:#b3261e; --bad-bg:#fde5e5; }
  @media (prefers-color-scheme: dark) {
    :root { --ink:#f2f2f4; --ink-2:#d8d8dc; --mut:#a1a1a6; --line:#2e2e33; --bg:#111113; --card:#1b1b1f; --acc-wash:#2a2115;
            --ok:#6fd38f; --ok-bg:#163322; --warn:#f0b25a; --warn-bg:#33270f; --bad:#ff7b72; --bad-bg:#3a1714; }
  }
  * { box-sizing:border-box; }
  body { margin:0; font:15.5px/1.55 -apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,Arial,sans-serif; color:var(--ink); background:var(--bg); -webkit-font-smoothing:antialiased; }
  a { color:var(--acc); }
  .hdr { position:sticky; top:0; z-index:5; background:color-mix(in srgb, var(--card) 88%, transparent); backdrop-filter:saturate(1.4) blur(12px); -webkit-backdrop-filter:saturate(1.4) blur(12px); border-bottom:1px solid var(--line); }
  .hdr-in { max-width:760px; margin:0 auto; padding:12px 16px; display:flex; align-items:center; gap:14px; }
  .logo { display:flex; align-items:center; gap:10px; text-decoration:none; color:var(--ink); }
  .logo-mark { width:34px; height:34px; border-radius:9px; background:linear-gradient(145deg,#D08A23,#8F560C); color:#fff; display:grid; place-items:center; font-weight:800; font-size:17px; flex:none; }
  .logo b { font-size:16px; letter-spacing:-.01em; } .logo .sub { display:block; font-size:11.5px; color:var(--mut); font-weight:600; margin-top:-2px; }
  .nav { margin-left:auto; display:flex; gap:4px; align-items:center; }
  .nav a { color:var(--ink-2); text-decoration:none; font-size:14px; font-weight:600; padding:7px 11px; border-radius:9px; }
  .nav a.on { background:var(--acc-wash); color:var(--acc); }
  .nav a.out { color:var(--mut); font-weight:500; }
  .wrap { max-width:760px; margin:0 auto; padding:22px 16px 60px; }
  h1 { font-size:26px; line-height:1.2; margin:4px 0 4px; letter-spacing:-.025em; }
  h2 { font-size:16px; margin:26px 0 10px; letter-spacing:-.01em; }
  .lead { color:var(--mut); margin:0 0 18px; }
  .back { color:var(--mut); font-size:13.5px; text-decoration:none; }
  .card { background:var(--card); border:1px solid var(--line); border-radius:18px; padding:22px; margin-top:14px; box-shadow:0 1px 2px rgba(0,0,0,.03); }
  .card.narrow { max-width:440px; margin:40px auto 0; }
  label.f { display:block; font-weight:650; font-size:13.5px; margin:0 0 8px; }
  input[type=email] { width:100%; height:48px; padding:0 14px; font-size:16px; color:var(--ink); background:transparent; border:1.5px solid var(--line); border-radius:12px; }
  input[type=email]:focus { outline:none; border-color:var(--acc); }
  .btn { display:inline-flex; align-items:center; justify-content:center; gap:8px; border:0; border-radius:12px; padding:0 18px; height:46px; font-size:15px; font-weight:700;
         background:var(--acc); color:#fff; cursor:pointer; text-decoration:none; font-family:inherit; }
  .btn:hover { background:var(--acc-2); }
  .btn.ghost { background:transparent; color:var(--acc); border:1.5px solid color-mix(in srgb, var(--acc) 45%, transparent); }
  .btn.ghost:hover { background:var(--acc-wash); }
  .btn.sm { height:34px; font-size:13px; padding:0 12px; border-radius:10px; }
  .btn.wide { width:100%; }
  .muted { color:var(--mut); font-size:13.5px; }
  .pill { display:inline-block; padding:3px 10px; border-radius:999px; font-size:12px; font-weight:700; white-space:nowrap; }
  .pill.ok { background:var(--ok-bg); color:var(--ok); } .pill.warn { background:var(--warn-bg); color:var(--warn); }
  .pill.bad { background:var(--bad-bg); color:var(--bad); } .pill.mute { background:color-mix(in srgb, var(--mut) 15%, transparent); color:var(--mut); }
  .alert { border-radius:12px; padding:13px 15px; margin:0 0 14px; font-size:14.5px; }
  .alert.ok { background:var(--ok-bg); color:var(--ok); } .alert.bad { background:var(--bad-bg); color:var(--bad); } .alert.warn { background:var(--warn-bg); color:var(--warn); }
  .lic-top { display:flex; justify-content:space-between; align-items:flex-start; gap:12px; }
  .lic-name { font-size:18px; font-weight:750; letter-spacing:-.01em; margin:0; }
  .lic-sub { color:var(--mut); font-size:13px; margin-top:2px; word-break:break-all; }
  .lic-big { display:flex; align-items:baseline; gap:10px; margin:18px 0 8px; flex-wrap:wrap; }
  .lic-big .n { font-size:34px; font-weight:800; letter-spacing:-.03em; line-height:1; }
  .lic-big .t { color:var(--mut); font-size:14px; }
  .bar { height:8px; background:color-mix(in srgb, var(--mut) 18%, transparent); border-radius:999px; overflow:hidden; }
  .bar > i { display:block; height:100%; background:var(--acc); border-radius:999px; }
  .bar.warn > i { background:#E09A2D; } .bar.bad > i { background:#D9483B; }
  .kv { display:grid; grid-template-columns:auto 1fr; gap:10px 16px; margin:16px 0 0; font-size:14px; align-items:center; }
  .kv dt { color:var(--mut); } .kv dd { margin:0; font-weight:600; text-align:right; word-break:break-all; }
  .chips { display:flex; flex-wrap:wrap; gap:6px; margin-top:14px; }
  .chip { display:inline-flex; align-items:center; gap:5px; padding:5px 10px; border-radius:999px; background:var(--acc-wash); color:var(--ink-2); font-size:12.5px; font-weight:600; }
  .actions { display:flex; gap:8px; flex-wrap:wrap; margin-top:18px; }
  .key { font-family:ui-monospace,SFMono-Regular,Menlo,monospace; font-size:13px; }
  .tbl { width:100%; border-collapse:collapse; font-size:14px; }
  .tbl td { padding:12px 6px; border-top:1px solid var(--line); vertical-align:middle; }
  .tbl tr:first-child td { border-top:0; }
  .tbl .r { text-align:right; white-space:nowrap; } .tbl .no { font-weight:650; }
  .opts { display:grid; gap:10px; }
  .opt { position:relative; display:flex; align-items:center; justify-content:space-between; gap:12px; padding:14px 16px; border:1.5px solid var(--line); border-radius:14px; cursor:pointer; }
  .opt input { position:absolute; opacity:0; pointer-events:none; }
  .opt:has(input:checked) { border-color:var(--acc); background:var(--acc-wash); }
  .opt b { font-size:15.5px; } .opt .p { font-weight:800; font-size:16px; white-space:nowrap; }
  .opt small { display:block; color:var(--mut); font-weight:500; font-size:12.5px; }
  .pay { display:grid; grid-template-columns:1fr 1fr; gap:10px; }
  .pay .opt { flex-direction:column; align-items:flex-start; gap:2px; }
  .bank { display:grid; grid-template-columns:1fr auto; gap:18px; align-items:center; }
  .bank .qr { background:#fff; padding:8px; border-radius:12px; border:1px solid var(--line); line-height:0; }
  @media (max-width:560px) { .pay { grid-template-columns:1fr; } .bank { grid-template-columns:1fr; } .bank .qr { justify-self:center; }
    .bank .kv { grid-template-columns:1fr; gap:2px; } .bank .kv dt { font-size:12px; margin-top:10px; } .bank .kv dt:first-child { margin-top:0; }
    .bank .kv dd { text-align:left; display:flex; align-items:center; justify-content:space-between; gap:10px; font-size:15px; }
    .lic-big .n { font-size:30px; } .hdr-in { padding:10px 14px; } .nav a { padding:7px 8px; font-size:13.5px; } .logo .sub { display:none; } h1 { font-size:23px; } }
  .foot { text-align:center; margin-top:34px; color:var(--mut); font-size:12.5px; }
  .foot a { color:var(--mut); }
</style></head>
<body>
<header class="hdr"><div class="hdr-in">
  <a class="logo" href="<?= e($BASE) ?>"><span class="logo-mark">A</span><span><b>APPEK</b><span class="sub">Zákaznický portál</span></span></a>
  <?php if ($logged): ?>
  <nav class="nav">
    <a href="<?= e($BASE) ?>/" class="<?= $active === 'home' ? 'on' : '' ?>">Licence</a>
    <a href="?platby=1" class="<?= $active === 'platby' ? 'on' : '' ?>">Platby</a>
    <a href="?logout=1" class="out">Odhlásit</a>
  </nav>
  <?php endif; ?>
</div></header>
<main class="wrap">
  <?= $body ?>
  <div class="foot">APPEK · <a href="https://appek.cz">appek.cz</a> · <a href="mailto:info@appek.cz">info@appek.cz</a></div>
</main>
<script src="qrcode.min.js"></script>
<script>
  document.querySelectorAll('[data-spd]').forEach(function (el) { try { new QRCode(el, { text: el.getAttribute('data-spd'), width: 132, height: 132, correctLevel: QRCode.CorrectLevel.M }); } catch (e) {} });
  document.querySelectorAll('[data-copy]').forEach(function (b) { b.addEventListener('click', function () {
    if (!navigator.clipboard) return;
    navigator.clipboard.writeText(b.getAttribute('data-copy')).then(function () { var t = b.textContent; b.textContent = 'Zkopírováno'; setTimeout(function () { b.textContent = t; }, 1400); });
  }); });
</script>
</body></html><?php
}

function my_fail(string $msg): void {
    global $BASE;
    my_layout('Chyba', '<div class="card narrow"><div class="alert bad">' . $msg . '</div><a class="btn ghost" href="' . e($BASE) . '">← Zpět na přehled</a></div>');
    exit;
}

/** Spustí platbu kartou (GoPay) pro objednávku a přesměruje na bránu. */
function my_pay_card(PDO $pdo, array $o): void {
    try {
        $session = gopay_create_payment([
            'order_no'         => $o['order_no'],
            'amount_kc'        => (float) $o['total_kc'],
            'currency'         => 'CZK',
            'description'      => 'APPEK — ' . my_order_label($o),
            'customer_email'   => $o['customer_email'] ?? '',
            'customer_name'    => $o['customer_name'] ?? '',
            'return_url'       => 'https://appek.cz/payment-done.html?order=' . urlencode($o['order_no']),
            'notification_url' => 'https://appek.cz/api/gopay_callback.php',
        ]);
    } catch (Throwable $e) { $session = ['ok' => false]; error_log('my pay gopay: ' . $e->getMessage()); }
    if (!empty($session['ok']) && !empty($session['gateway_url'])) {
        $pdo->prepare("UPDATE vendor_shop_orders SET payment_id = :pid, payment_method = 'card' WHERE id = :id")
            ->execute(['pid' => $session['payment_id'] ?? '', 'id' => $o['id']]);
        header('Location: ' . $session['gateway_url']); exit;
    }
    my_fail('Platbu kartou se teď nepodařilo spustit. Zkuste to prosím za chvíli znovu, nebo zaplaťte převodem.');
}

// ═══════════════════════════════════════════════════════════════════
// ROUTER
// ═══════════════════════════════════════════════════════════════════

// ── Odhlášení ──
if (isset($_GET['logout'])) {
    $_SESSION = [];
    session_destroy();
    header('Location: ' . $BASE . '/');
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
            $_SESSION['my_csrf'] = bin2hex(random_bytes(16));
            header('Location: ' . $BASE . '/');
            exit;
        }
    }
    my_layout('Přihlášení', '<div class="card narrow"><div class="alert bad">Odkaz je neplatný nebo vypršel (platí 20 minut, jen jednou).</div><a class="btn ghost" href="' . e($BASE) . '">Poslat nový odkaz</a></div>');
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
        $html = '<div style="font-family:-apple-system,Segoe UI,Arial,sans-serif;max-width:520px;margin:0 auto;color:#1d1d1f">'
              . '<p>Dobrý den,</p><p>klikněte pro přihlášení do zákaznického portálu APPEK:</p>'
              . '<p><a href="' . e($link) . '" style="display:inline-block;background:#BA7517;color:#fff;padding:12px 22px;border-radius:10px;text-decoration:none;font-weight:700">Přihlásit se</a></p>'
              . '<p style="color:#6e6e73;font-size:13px">Odkaz platí 20 minut a lze jej použít jen jednou. Pokud jste o přihlášení nežádali, ignorujte tento e-mail.</p></div>';
        $text = "Přihlášení do portálu APPEK:\n$link\n\nOdkaz platí 20 minut, jednorázově.";
        try { vendor_send_mail($email, 'Přihlášení do portálu APPEK', $html, $text); } catch (Throwable $e) { /* neprozrazuj */ }
    }
    my_layout('Odkaz odeslán', '<div class="card narrow"><h1>Zkontrolujte e-mail</h1><p class="lead">Pokud e-mail patří k licenci APPEK, poslali jsme na něj přihlašovací odkaz. Podívejte se i do spamu.</p><p class="muted">Odkaz platí 20 minut a dá se použít jen jednou.</p><a class="btn ghost" href="' . e($BASE) . '">← Zpět</a></div>');
    exit;
}

// Všechno níže jen pro přihlášené
$email = $_SESSION['my_email'] ?? '';

// ── POST: vytvořit objednávku prodloužení (karta → GoPay, převod → platební údaje) ──
if ($email && $_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'renew_order') {
    if (!csrf_ok()) my_fail('Platnost formuláře vypršela, načtěte stránku znovu.');
    $lic = my_license_of($pdo, $email, (int) ($_POST['license_id'] ?? 0));
    if (!$lic) my_fail('Licence nenalezena.');
    $months = (int) ($_POST['months'] ?? 0);
    if (!in_array($months, my_renew_periods($lic), true)) my_fail('Neplatné období.');
    $method = ($_POST['method'] ?? 'card') === 'bank' ? 'bank' : 'card';
    $total = (int) round(my_renew_price($pdo, $lic, $months));
    if ($total <= 0) my_fail('Pro tuto licenci zatím není nastavena cena prodloužení — napište na <a href="mailto:info@appek.cz">info@appek.cz</a>.');
    $orderNo = 'APPEK-REN-' . date('Ymd') . '-' . strtoupper(bin2hex(random_bytes(2)));
    $ip = trim(explode(',', $_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0')[0]);
    $pdo->prepare("INSERT INTO vendor_shop_orders
        (order_no, customer_name, customer_company, customer_email, customer_phone, install_url,
         tier, packages_json, total_kc, currency, payment_method, payment_status, rental_months, renew_license_id, ip, created_at)
        VALUES (:no,:n,:co,:e,:p,:u,'renewal',:pkg,:total,'CZK',:pm,'pending',:m,:rid,:ip,NOW())")
        ->execute([
            'no' => $orderNo, 'n' => $lic['customer_name'] ?: 'Zákazník', 'co' => $lic['customer_company'] ?? null,
            'e' => $lic['customer_email'] ?: $email, 'p' => $lic['customer_phone'] ?? null, 'u' => $lic['install_url'] ?? null,
            'pkg' => json_encode(my_pkgs((string) $lic['license_key'])), 'total' => $total, 'pm' => $method,
            'm' => $months, 'rid' => (int) $lic['id'], 'ip' => $ip,
        ]);
    $o = my_order_of($pdo, $email, $orderNo);
    if (!$o) my_fail('Objednávku se nepodařilo založit.');
    if ($method === 'bank') {
        try { vendor_send_admin_notification($o, ['license_key' => $lic['license_key'] ?? '—'], 'order'); } catch (Throwable $e) {}
    }
    if ($method === 'card') my_pay_card($pdo, $o);
    header('Location: ' . $BASE . '/?order=' . urlencode($orderNo) . '&new=1');
    exit;
}

// ── POST: zaplatit existující nezaplacenou objednávku kartou ──
if ($email && $_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'pay_card') {
    if (!csrf_ok()) my_fail('Platnost formuláře vypršela, načtěte stránku znovu.');
    $o = my_order_of($pdo, $email, (string) ($_POST['order_no'] ?? ''));
    if (!$o || ($o['payment_status'] ?? '') !== 'pending') my_fail('Objednávka nenalezena nebo už je zaplacená.');
    my_pay_card($pdo, $o);
}

// ── Detail objednávky + platební údaje (?order=…) ──
if ($email && isset($_GET['order'])) {
    $o = my_order_of($pdo, $email, (string) $_GET['order']);
    if (!$o) my_fail('Objednávka nenalezena.');
    $bank = my_bank($pdo);
    $acc  = trim((string) ($bank['business_bank_account'] ?? ''));
    $iban = preg_replace('/\s+/', '', (string) ($bank['business_bank_iban'] ?? ''));
    $vs   = my_vs($o);
    $pending = ($o['payment_status'] ?? '') === 'pending';
    $spd = '';
    if ($pending && $iban !== '') {
        $msg = preg_replace('/[*]/', '', iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', 'APPEK ' . $o['order_no']) ?: $o['order_no']);
        $spd = 'SPD*1.0*ACC:' . $iban . '*AM:' . number_format((float) $o['total_kc'], 2, '.', '') . '*CC:CZK*X-VS:' . $vs . '*MSG:' . mb_substr($msg, 0, 60);
    }
    ob_start(); ?>
    <a href="?platby=1" class="back">← Platby</a>
    <h1>Objednávka <?= e($o['order_no']) ?></h1>
    <p class="lead"><?= e(my_order_label($o)) ?> · <?= e(d($o['created_at'])) ?></p>
    <?php if (!empty($_GET['new']) && $pending): ?><div class="alert ok">Objednávka je založená. Zaplaťte ji převodem podle údajů níže, nebo kartou.</div><?php endif; ?>
    <div class="card">
      <div class="lic-top"><div><div class="muted">K úhradě</div><div class="lic-big" style="margin:4px 0 0"><span class="n"><?= e(kc($o['total_kc'])) ?></span></div></div><?= my_order_pill($o) ?></div>
      <?php if ($pending): ?>
        <h2>Platba převodem</h2>
        <?php if ($acc === '' && $iban === ''): ?>
          <div class="alert warn">Bankovní údaje zatím nejsou k dispozici — zaplaťte prosím kartou, nebo napište na <a href="mailto:info@appek.cz">info@appek.cz</a>.</div>
        <?php else: ?>
        <div class="bank">
          <dl class="kv" style="margin:0">
            <?php if ($acc !== ''): ?><dt>Číslo účtu</dt><dd><?= e($acc) ?> <button class="btn ghost sm" type="button" data-copy="<?= e($acc) ?>">Kopírovat</button></dd><?php endif; ?>
            <?php if ($iban !== ''): ?><dt>IBAN</dt><dd class="key"><?= e(trim(chunk_split($iban, 4, ' '))) ?></dd><?php endif; ?>
            <dt>Variabilní symbol</dt><dd><?= e($vs) ?> <button class="btn ghost sm" type="button" data-copy="<?= e($vs) ?>">Kopírovat</button></dd>
            <dt>Částka</dt><dd><?= e(number_format((float) $o['total_kc'], 2, ',', ' ')) ?> Kč</dd>
            <dt>Zpráva</dt><dd><?= e($o['order_no']) ?></dd>
          </dl>
          <?php if ($spd !== ''): ?><div class="qr" data-spd="<?= e($spd) ?>" title="QR Platba — naskenujte v bankovní aplikaci"></div><?php endif; ?>
        </div>
        <p class="muted" style="margin-top:12px">Po připsání platby prodloužení potvrdíme e-mailem (obvykle do 1 pracovního dne). Aplikace si novou platnost načte sama.</p>
        <?php endif; ?>
        <h2>Nebo kartou hned</h2>
        <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="pay_card"><input type="hidden" name="order_no" value="<?= e($o['order_no']) ?>">
          <button class="btn wide" type="submit">Zaplatit kartou · Apple Pay · Google Pay</button></form>
        <p class="muted" style="margin-top:8px">Platba proběhne přes zabezpečenou bránu GoPay. Po zaplacení kartou se prodloužení provede automaticky během pár minut.</p>
      <?php elseif (($o['payment_status'] ?? '') === 'paid'): ?>
        <p class="muted" style="margin-top:14px">Zaplaceno <?= e(d($o['paid_at'] ?? null)) ?>. Děkujeme.</p>
      <?php endif; ?>
    </div>
    <?php
    my_layout('Objednávka ' . $o['order_no'], ob_get_clean(), 'platby');
    exit;
}

// ── Prodloužení: výběr období + způsobu platby (?renew=ID) ──
if ($email && isset($_GET['renew'])) {
    $lic = my_license_of($pdo, $email, (int) $_GET['renew']);
    if (!$lic) my_fail('Licence nenalezena.');
    $s = my_status($lic);
    $periods = my_renew_periods($lic);
    $bank = my_bank($pdo);
    $hasBank = trim((string) ($bank['business_bank_account'] ?? '')) !== '' || trim((string) ($bank['business_bank_iban'] ?? '')) !== '';
    $nazev = $lic['customer_company'] ?: ($lic['install_url'] ?: $lic['customer_name']);
    $prices = []; foreach ($periods as $m) $prices[$m] = my_renew_price($pdo, $lic, $m);
    $base = ($s['vu'] && strtotime($s['vu']) > time()) ? strtotime($s['vu']) : time();
    ob_start(); ?>
    <a href="<?= e($BASE) ?>" class="back">← Licence</a>
    <h1>Prodloužit <?= $s['rental'] ? 'pronájem' : 'licenci' ?></h1>
    <p class="lead"><?= e($nazev ?: 'APPEK licence') ?> · nyní platí do <strong><?= e($s['vu'] ? d($s['vu']) : '∞') ?></strong></p>
    <?php if (max($prices ?: [0]) <= 0): ?>
      <div class="card"><div class="alert warn" style="margin:0">Pro tuto licenci zatím není nastavena cena prodloužení. Napište na <a href="mailto:info@appek.cz">info@appek.cz</a> a rádi pomůžeme.</div></div>
    <?php else: ?>
    <form method="post" class="card">
      <?= csrf_field() ?><input type="hidden" name="action" value="renew_order"><input type="hidden" name="license_id" value="<?= (int) $lic['id'] ?>">
      <label class="f">Období</label>
      <div class="opts">
        <?php foreach ($periods as $i => $m): $do = date('j. n. Y', add_months($base, $m)); ?>
        <label class="opt"><input type="radio" name="months" value="<?= $m ?>" <?= $i === 0 ? 'checked' : '' ?>>
          <span><b><?= $s['rental'] ? e(mes($m)) : '1 rok' ?></b><small>platnost do <?= e($do) ?></small></span>
          <span class="p"><?= e(kc($prices[$m])) ?></span></label>
        <?php endforeach; ?>
      </div>
      <label class="f" style="margin-top:20px">Způsob platby</label>
      <div class="pay">
        <label class="opt"><input type="radio" name="method" value="card" checked><b>Kartou</b><small>Apple Pay, Google Pay · prodlouží se hned</small></label>
        <?php if ($hasBank): ?><label class="opt"><input type="radio" name="method" value="bank"><b>Převodem</b><small>QR Platba · do 1 pracovního dne</small></label><?php endif; ?>
      </div>
      <button class="btn wide" type="submit" style="margin-top:20px">Pokračovat k platbě</button>
      <p class="muted" style="margin-top:10px">Prodlužuje se od konce současného období — nic nepropadne. Vaše data i nastavení zůstávají beze změny.</p>
    </form>
    <?php endif; ?>
    <?php
    my_layout('Prodloužit', ob_get_clean(), 'home');
    exit;
}

// ── Platby: nezaplacené + historie (?platby=1) ──
if ($email && isset($_GET['platby'])) {
    $all = my_orders($pdo, $email);
    $pending = array_values(array_filter($all, fn($o) => ($o['payment_status'] ?? '') === 'pending'));
    $rest    = array_values(array_filter($all, fn($o) => ($o['payment_status'] ?? '') !== 'pending'));
    ob_start(); ?>
    <h1>Platby</h1>
    <p class="lead"><?= e($email) ?></p>
    <?php if ($pending): ?>
      <h2>K zaplacení</h2>
      <div class="card" style="padding:6px 18px">
        <table class="tbl"><?php foreach ($pending as $o): ?>
          <tr><td><div class="no"><?= e(my_order_label($o)) ?></div><div class="muted"><?= e($o['order_no']) ?> · <?= e(d($o['created_at'])) ?></div></td>
              <td class="r"><strong><?= e(kc($o['total_kc'])) ?></strong></td>
              <td class="r"><a class="btn sm" href="?order=<?= e(urlencode($o['order_no'])) ?>">Zaplatit</a></td></tr>
        <?php endforeach; ?></table>
      </div>
    <?php endif; ?>
    <h2>Historie</h2>
    <div class="card" style="padding:6px 18px">
      <?php if (!$rest): ?><p class="muted" style="padding:10px 0;margin:0">Zatím žádné platby.</p><?php else: ?>
      <table class="tbl"><?php foreach ($rest as $o): ?>
        <tr><td><div class="no"><?= e(my_order_label($o)) ?></div><div class="muted"><?= e($o['order_no']) ?> · <?= e(d($o['paid_at'] ?: $o['created_at'])) ?></div></td>
            <td class="r"><?= e(kc($o['total_kc'])) ?></td><td class="r"><?= my_order_pill($o) ?></td></tr>
      <?php endforeach; ?></table>
      <?php endif; ?>
    </div>
    <?php
    my_layout('Platby', ob_get_clean(), 'platby');
    exit;
}

// ── Přehled licencí (přihlášený) ──
if ($email) {
    $lics = my_licenses($pdo, $email);
    $pending = my_orders($pdo, $email, 'pending');
    ob_start(); ?>
    <h1>Vaše licence</h1>
    <p class="lead"><?= e($email) ?></p>
    <?php if ($pending): $p0 = $pending[0]; ?>
      <div class="alert warn" style="display:flex;justify-content:space-between;align-items:center;gap:12px;flex-wrap:wrap">
        <span><?= count($pending) === 1 ? 'Máte nezaplacenou objednávku' : 'Máte ' . count($pending) . ' nezaplacené objednávky' ?> · <?= e(kc($p0['total_kc'])) ?></span>
        <a class="btn sm" href="?order=<?= e(urlencode($p0['order_no'])) ?>">Zaplatit</a>
      </div>
    <?php endif; ?>
    <?php if (!$lics): ?>
      <div class="card"><div class="alert bad" style="margin:0">K tomuto e-mailu nevidíme žádnou licenci. Napište nám na <a href="mailto:info@appek.cz">info@appek.cz</a>.</div></div>
    <?php else: foreach ($lics as $l): $s = my_status($l);
        $nazev = $l['customer_company'] ?: ($l['install_url'] ?: $l['customer_name']);
        $rows = my_pkg_rows($pdo, (string) $l['license_key']);
        $key = (string) $l['license_key'];
        $masked = strlen($key) > 14 ? substr($key, 0, 10) . '••••' . substr($key, -4) : $key;
        $vydano = $l['issued_at'] ?? ($l['created_at'] ?? null);
        $renewFrom = my_renew_price($pdo, $l, my_renew_periods($l)[0]);
        $canRenew = ($l['status'] ?? '') !== 'revoked' && ($s['rental'] || $s['vu']) && $renewFrom > 0;
        $coreOnly = !$s['rental'] && $s['vu'] && $renewFrom <= 0; ?>
      <section class="card">
        <div class="lic-top">
          <div><p class="lic-name"><?= e($nazev ?: 'APPEK licence') ?></p>
            <div class="lic-sub"><?= $s['rental'] ? 'Měsíční pronájem' : 'Licence' ?><?= (!empty($l['install_url']) && $l['install_url'] !== $nazev) ? ' · ' . e($l['install_url']) : '' ?></div></div>
          <span class="pill <?= e($s['cls']) ?>"><?= e($s['label']) ?></span>
        </div>
        <?php if ($s['vu']): ?>
          <div class="lic-big">
            <?php if (in_array($s['key'], ['expired', 'locked'], true)): ?><span class="n">Vypršelo</span><span class="t"><?= e(d($s['vu'])) ?></span>
            <?php elseif ($s['key'] === 'grace'): ?><span class="n"><?= (int) $s['days'] ?></span><span class="t"><?= dni((int) $s['days']) ?> odkladu · vypršelo <?= e(d($s['vu'])) ?></span>
            <?php else: ?><span class="n"><?= (int) $s['days'] ?></span><span class="t"><?= dni((int) $s['days']) ?> zbývá · platí do <?= e(d($s['vu'])) ?></span><?php endif; ?>
          </div>
          <div class="bar <?= e($s['cls'] === 'ok' ? '' : $s['cls']) ?>"><i style="width:<?= (int) $s['pct'] ?>%"></i></div>
        <?php else: ?>
          <div class="lic-big"><span class="n">∞</span><span class="t">bez expirace</span></div>
        <?php endif; ?>
        <?php if ($rows): ?><div class="chips"><?php foreach ($rows as $r): ?><span class="chip"><?= e($r['icon'] ?? '') ?> <?= e($r['name_cs'] ?: $r['key']) ?></span><?php endforeach; ?></div><?php endif; ?>
        <dl class="kv">
          <dt>Licenční klíč</dt><dd><span class="key"><?= e($masked) ?></span> <button class="btn ghost sm" type="button" data-copy="<?= e($key) ?>">Kopírovat</button></dd>
          <?php if ($vydano): ?><dt>Vydáno</dt><dd><?= e(d($vydano)) ?></dd><?php endif; ?>
        </dl>
        <?php if ($coreOnly): ?>
        <p class="muted" style="margin:14px 0 0">Základ (Core) vám běží napořád — datum platnosti se týká jen placených balíčků. Balíčky můžete přikoupit na <a href="https://appek.cz/checkout.html">appek.cz</a>.</p>
        <?php endif; ?>
        <?php if ($canRenew): ?>
        <div class="actions">
          <a class="btn" href="?renew=<?= (int) $l['id'] ?>">Prodloužit<?= $s['rental'] ? ' pronájem' : ' o rok' ?> · od <?= e(kc($renewFrom)) ?></a>
          <a class="btn ghost" href="?platby=1">Historie plateb</a>
        </div>
        <?php endif; ?>
      </section>
    <?php endforeach; endif; ?>
    <?php
    my_layout('Vaše licence', ob_get_clean(), 'home');
    exit;
}

// ── Přihlášení (nepřihlášený) ──
ob_start(); ?>
<div class="card narrow">
  <h1>Přihlášení</h1>
  <p class="lead">Zadejte e-mail, kterým jste APPEK objednali. Pošleme vám přihlašovací odkaz — bez hesla.</p>
  <form method="post">
    <input type="hidden" name="action" value="send_link">
    <label class="f" for="email">E-mail</label>
    <input type="email" id="email" name="email" required autocomplete="email" placeholder="vas@email.cz" autofocus>
    <button class="btn wide" type="submit" style="margin-top:14px">Poslat přihlašovací odkaz</button>
  </form>
  <p class="muted" style="margin-top:16px">V portálu uvidíte stav licence a do kdy máte zaplaceno, licenci prodloužíte kartou nebo převodem a najdete tu historii plateb.</p>
</div>
<?php
my_layout('Přihlášení', ob_get_clean());
