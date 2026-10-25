<?php
/**
 * 🔑 v3.0.591 — Veřejná stránka „Nastavit nové heslo" (z odkazu v e-mailu).
 *   GET  ?t=TOKEN → formulář (nebo hláška „odkaz vypršel")
 *   POST t, heslo, heslo2 → nastaví heslo, token spotřebuje, nabídne přihlášení
 * Token je tajný a jednorázový (viz _password_reset_lib.php) → CSRF token netřeba.
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/_password_reset_lib.php';

header('Content-Type: text/html; charset=UTF-8');
header('Cache-Control: no-store');
header('Referrer-Policy: no-referrer');      // token v URL nesmí utéct v Refereru
header('X-Frame-Options: DENY');
header('X-Content-Type-Options: nosniff');

$pdo = db();
$e = fn($s) => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
$token = (string) ($_POST['t'] ?? $_GET['t'] ?? '');
$firma = '';
try { $firma = (string) nastaveni_get($pdo, 'firma_nazev', ''); } catch (Throwable $ex) {}
$appName = $firma !== '' ? $firma : 'APPEK';

$state = 'form'; $err = ''; $loginUrl = '../admin/';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $h1 = (string) ($_POST['heslo'] ?? ''); $h2 = (string) ($_POST['heslo2'] ?? '');
    if ($h1 !== $h2) {
        $err = 'Hesla se neshodují.';
    } else {
        try {
            $typ = pr_consume($pdo, $token, $h1);
            $loginUrl = $typ === 'b2b' ? '../b2b/' : '../admin/';
            $state = 'done';
        } catch (InvalidArgumentException $ex) {
            $err = $ex->getMessage();
        } catch (Throwable $ex) {
            error_log('password_reset: ' . $ex->getMessage());
            $err = 'Heslo se nepodařilo uložit. Zkuste to prosím znovu.';
        }
    }
}
if ($state === 'form') {
    $row = pr_lookup($pdo, $token);
    if (!$row) $state = 'invalid';
    elseif ($row['typ'] === 'b2b') $loginUrl = '../b2b/';
}
?><!DOCTYPE html>
<html lang="cs"><head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="robots" content="noindex,nofollow">
<title>Nastavení hesla — <?= $e($appName) ?></title>
<style>
  :root{--bg:#f5f5f7;--card:#fff;--text:#1d1d1f;--muted:#6e6e73;--line:#d2d2d7;--acc:#BA7517;--err:#b42318}
  @media (prefers-color-scheme:dark){:root{--bg:#111113;--card:#1c1c1f;--text:#f2f2f2;--muted:#a1a1a6;--line:#3a3a3f;--err:#ff6b5e}}
  *{box-sizing:border-box}
  body{margin:0;min-height:100dvh;display:flex;align-items:center;justify-content:center;padding:16px;background:var(--bg);color:var(--text);font:16px/1.5 -apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,Arial,sans-serif}
  .card{width:100%;max-width:400px;background:var(--card);border:1px solid var(--line);border-radius:18px;padding:28px 24px;box-shadow:0 10px 30px rgba(0,0,0,.06)}
  h1{font-size:21px;margin:0 0 6px}
  p{margin:0 0 16px;color:var(--muted);font-size:14.5px}
  label{display:block;font-size:13px;font-weight:600;margin:14px 0 6px}
  input{width:100%;height:46px;padding:0 14px;border:1.5px solid var(--line);border-radius:11px;background:transparent;color:var(--text);font-size:16px}
  input:focus{outline:none;border-color:var(--acc)}
  .btn{display:block;width:100%;height:48px;margin-top:20px;border:0;border-radius:12px;background:var(--acc);color:#fff;font-size:16px;font-weight:700;text-align:center;line-height:48px;text-decoration:none;cursor:pointer}
  .err{background:color-mix(in srgb,var(--err) 12%,transparent);color:var(--err);border-radius:10px;padding:10px 12px;font-size:14px;margin:0 0 6px}
</style>
</head><body>
<main class="card">
<?php if ($state === 'done'): ?>
  <h1>Heslo je nastavené</h1>
  <p>Nové heslo platí. Teď se můžete přihlásit.</p>
  <a class="btn" href="<?= $e($loginUrl) ?>">Přihlásit se</a>
<?php elseif ($state === 'invalid'): ?>
  <h1>Odkaz už neplatí</h1>
  <p>Odkaz vypršel, byl už použitý, nebo byl nahrazen novějším. Požádejte správce o nový odkaz na nastavení hesla.</p>
  <a class="btn" href="<?= $e($loginUrl) ?>">Na přihlášení</a>
<?php else: ?>
  <h1>Nastavení nového hesla</h1>
  <p><?= $e($appName) ?> — zadejte nové heslo (alespoň <?= PR_MIN_LEN ?> znaků).</p>
  <?php if ($err !== ''): ?><div class="err" role="alert"><?= $e($err) ?></div><?php endif; ?>
  <form method="post" autocomplete="off">
    <input type="hidden" name="t" value="<?= $e($token) ?>">
    <label for="h1">Nové heslo</label>
    <input id="h1" name="heslo" type="password" minlength="<?= PR_MIN_LEN ?>" required autocomplete="new-password" autofocus>
    <label for="h2">Nové heslo znovu</label>
    <input id="h2" name="heslo2" type="password" minlength="<?= PR_MIN_LEN ?>" required autocomplete="new-password">
    <button class="btn" type="submit">Uložit heslo</button>
  </form>
<?php endif; ?>
</main>
</body></html>
