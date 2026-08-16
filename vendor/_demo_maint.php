<?php
/**
 * 🔧 DEMO MAINTENANCE — knihovna pro zapnutí/vypnutí údržby dema z vendoru.
 *
 * Vendor sdílí filesystem s demem (public_html/vendor vedle public_html/demo),
 * takže „přepnutí z vendoru" = zápis/smazání lokálního markeru — žádná cross-server auth.
 *
 *   demo/.maintenance        … marker (existuje = údržba ZAPNUTA)
 *   demo/maintenance.php      … self-contained 503 stránka + email box (self-healed)
 *   demo/.htaccess            … guard blok (self-healed) → routuje vše na maintenance.php
 *   demo/.demo-leads.jsonl    … zachycené e-maily (1 JSON řádek na lead)
 *
 * Guard v .htaccess (Apache) chytí i statickou SPA skořápku (admin/index.html),
 * což PHP-only guard neumí. Stránka posílá 503 + Retry-After → neublíží SEO.
 */

if (!defined('DEMO_MAINT_LIB')) {
    define('DEMO_MAINT_LIB', 1);

    /** Najdi docroot dema (public_html/demo vedle vendoru). Vrací absolutní cestu nebo null. */
    function demo_docroot(): ?string
    {
        $candidates = [
            __DIR__ . '/../demo',          // public_html/vendor → public_html/demo
            dirname(__DIR__) . '/demo',
        ];
        foreach ($candidates as $c) {
            if (is_dir($c) && (is_dir($c . '/admin') || is_file($c . '/index.html'))) {
                return realpath($c) ?: $c;
            }
        }
        return null;
    }

    function demo_maint_marker(): ?string
    {
        $root = demo_docroot();
        return $root ? $root . '/.maintenance' : null;
    }

    function demo_leads_file(): ?string
    {
        $root = demo_docroot();
        return $root ? $root . '/.demo-leads.jsonl' : null;
    }

    /** Je údržba právě zapnutá? */
    function demo_maint_is_on(): bool
    {
        $m = demo_maint_marker();
        return $m !== null && file_exists($m);
    }

    /**
     * Entry-složky, do jejichž .htaccess se guard injektuje. mod_rewrite pravidla se
     * do podsložek NEDĚDÍ, takže (stejně jako landing-only) musí být guard v každé zvlášť.
     * '' = root demo docroot.
     */
    function demo_maint_entry_dirs(): array
    {
        return ['', 'admin', 'api', 'b2b', 'pos', 'floorplan'];
    }

    /**
     * Vlož guard blok do jednoho .htaccess (idempotentně dle markeru). Vrací ?warning.
     * Guard zůstává trvale — bez souboru .maintenance je RewriteCond nesplněn = no-op.
     */
    function demo_maint_inject_guard(string $ht): ?string
    {
        $sentinel = '# >>> APPEK-DEMO-MAINTENANCE-GUARD';
        $body = is_file($ht) ? (@file_get_contents($ht) ?: '') : '';
        if (strpos($body, $sentinel) !== false) return null; // už injektováno
        $guard = demo_maint_htaccess_guard();
        if (preg_match('/RewriteEngine\s+On/i', $body)) {
            // hned za "RewriteEngine On" → guard běží před ostatními pravidly složky
            $body = preg_replace('/(RewriteEngine\s+On[^\n]*\n)/i', "$1\n" . $guard . "\n", $body, 1);
        } else {
            // .htaccess bez rewrite enginu → přidej vlastní blok + guard
            $body = "<IfModule mod_rewrite.c>\n  RewriteEngine On\n</IfModule>\n" . $guard . "\n" . $body;
        }
        if (@file_put_contents($ht, $body) === false) {
            $label = basename(dirname($ht)) . '/.htaccess';
            return 'Nepodařilo se zapsat ' . $label . ' (práva?).';
        }
        return null;
    }

    /**
     * Self-healing: zajisti maintenance.php + guard v .htaccess všech entry-složek.
     * Volá se před zapnutím údržby, aby stránka fungovala i po rebuildu dema.
     * Vrací ['ok'=>bool, 'warnings'=>string[]].
     */
    function demo_maint_ensure_assets(string $docroot): array
    {
        $warnings = [];

        // 1) maintenance.php (v rootu dema)
        $php = $docroot . '/maintenance.php';
        $desired = demo_maint_asset_php();
        $current = is_file($php) ? @file_get_contents($php) : null;
        if ($current !== $desired) {
            if (@file_put_contents($php, $desired) === false) {
                $warnings[] = 'Nepodařilo se zapsat maintenance.php (práva?).';
            }
        }

        // 2) guard do root + každé entry-složky (mod_rewrite se nedědí)
        foreach (demo_maint_entry_dirs() as $sub) {
            $ht = $docroot . ($sub === '' ? '' : '/' . $sub) . '/.htaccess';
            if ($sub !== '' && !is_file($ht)) continue; // podsložka bez .htaccess → přeskoč
            $w = demo_maint_inject_guard($ht);
            if ($w) $warnings[] = $w;
        }

        return ['ok' => empty($warnings), 'warnings' => $warnings];
    }

    /** Zapni údržbu. Vrací ['ok'=>bool, 'error'=>?string, 'warnings'=>string[]]. */
    function demo_maint_on(): array
    {
        $docroot = demo_docroot();
        if (!$docroot) {
            return ['ok' => false, 'error' => 'Demo docroot nenalezen (public_html/demo).', 'warnings' => []];
        }
        $assets = demo_maint_ensure_assets($docroot);
        $marker = $docroot . '/.maintenance';
        $stamp = date('Y-m-d H:i:s');
        if (@file_put_contents($marker, "on since {$stamp}\n") === false) {
            return ['ok' => false, 'error' => 'Nepodařilo se vytvořit marker .maintenance.', 'warnings' => $assets['warnings']];
        }
        return ['ok' => true, 'error' => null, 'warnings' => $assets['warnings']];
    }

    /**
     * Vypni údržbu a (volitelně) rozešli čekajícím leadům „demo je zpět".
     * Vrací ['ok'=>bool, 'error'=>?string, 'notified'=>int, 'notify_errors'=>int].
     */
    function demo_maint_off(bool $notify = true): array
    {
        $marker = demo_maint_marker();
        if ($marker === null) {
            return ['ok' => false, 'error' => 'Demo docroot nenalezen.', 'notified' => 0, 'notify_errors' => 0];
        }
        if (file_exists($marker)) {
            if (!@unlink($marker)) {
                return ['ok' => false, 'error' => 'Nepodařilo se smazat marker .maintenance.', 'notified' => 0, 'notify_errors' => 0];
            }
        }
        $notified = 0;
        $notifyErr = 0;
        if ($notify) {
            $res = demo_leads_notify_pending();
            $notified = $res['sent'];
            $notifyErr = $res['errors'];
        }
        return ['ok' => true, 'error' => null, 'notified' => $notified, 'notify_errors' => $notifyErr];
    }

    /** Načti leady (nejnovější první). Vrací pole ['email','ts','ip','ua','notified']. */
    function demo_leads_read(int $limit = 0): array
    {
        $f = demo_leads_file();
        if ($f === null || !is_file($f)) return [];
        $lines = @file($f, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
        $out = [];
        foreach ($lines as $ln) {
            $row = json_decode($ln, true);
            if (is_array($row) && !empty($row['email'])) $out[] = $row;
        }
        $out = array_reverse($out); // nejnovější první
        if ($limit > 0) $out = array_slice($out, 0, $limit);
        return $out;
    }

    /**
     * Rozešli všem leadům s notified=0 mail „demo je zpět" a přepiš soubor s notified=1.
     * Vyžaduje vendor_send_mail() z _mail.php. Vrací ['sent'=>int, 'errors'=>int].
     */
    function demo_leads_notify_pending(): array
    {
        $f = demo_leads_file();
        if ($f === null || !is_file($f) || !function_exists('vendor_send_mail')) {
            return ['sent' => 0, 'errors' => 0];
        }
        $lines = @file($f, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
        $sent = 0;
        $errors = 0;
        $rewritten = [];
        $seen = [];
        foreach ($lines as $ln) {
            $row = json_decode($ln, true);
            if (!is_array($row) || empty($row['email'])) continue;
            $email = strtolower(trim((string) $row['email']));
            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) continue;
            // dedupe podle e-mailu (poslední záznam vyhrává)
            $seen[$email] = $row;
        }
        foreach ($seen as $email => $row) {
            if (($row['notified'] ?? 0) == 1) {
                $rewritten[] = $row;
                continue;
            }
            $err = null;
            $subject = 'Váš přístup do APPEK dema 🎉';
            $html = '<div style="font-family:-apple-system,Segoe UI,Arial,sans-serif;max-width:520px;margin:0 auto">'
                . '<h2 style="color:#BA7517">Vaše APPEK demo je připravené 🎉</h2>'
                . '<p>Můžete si ho vyzkoušet tady:</p>'
                . '<p><a href="https://demo.appek.cz/admin/" style="display:inline-block;padding:12px 24px;background:#BA7517;color:#fff;text-decoration:none;border-radius:10px;font-weight:600">→ Otevřít demo</a></p>'
                . '<p style="color:#888;font-size:13px">Přihlášení proběhne automaticky. Kdyby ne: demo@appek.cz / demo1234</p>'
                . '<hr style="border:none;border-top:1px solid #eee;margin:22px 0">'
                . '<p style="color:#aaa;font-size:12px">Tento e-mail jste dostali, protože jste požádali o přístup do APPEK dema. Nic dalšího vám posílat nebudeme.</p>'
                . '</div>';
            $ok = vendor_send_mail($email, $subject, $html, null, null, $err);
            if ($ok) {
                $row['notified'] = 1;
                $row['notified_at'] = date('Y-m-d H:i:s');
                $sent++;
            } else {
                $errors++;
            }
            $rewritten[] = $row;
        }
        // přepiš soubor (dedupikovaný, s aktualizovaným notified)
        $buf = '';
        foreach ($rewritten as $r) $buf .= json_encode($r, JSON_UNESCAPED_UNICODE) . "\n";
        @file_put_contents($f, $buf, LOCK_EX);
        return ['sent' => $sent, 'errors' => $errors];
    }

    /** Guard blok vkládaný do demo/.htaccess (idempotentní dle markeru). */
    function demo_maint_htaccess_guard(): string
    {
        return <<<'HT'
# >>> APPEK-DEMO-MAINTENANCE-GUARD (spravováno vendorem, neupravovat ručně)
<IfModule mod_rewrite.c>
  RewriteCond %{DOCUMENT_ROOT}/.maintenance -f
  RewriteCond %{REQUEST_URI} !^/maintenance\.php$ [NC]
  RewriteRule ^ /maintenance.php [L]
</IfModule>
# <<< APPEK-DEMO-MAINTENANCE-GUARD
HT;
    }

    /** Obsah self-contained maintenance.php stránky (503 + email box). */
    function demo_maint_asset_php(): string
    {
        return <<<'PHP'
<?php
/**
 * 🔧 APPEK DEMO — MAINTENANCE PAGE (auto-generováno vendorem, neupravovat ručně).
 * Servíruje se, když existuje ../.maintenance marker (viz .htaccess guard).
 * 503 + Retry-After (SEO-safe) + volitelný sběr e-mailů (nech kontakt → dáme vědět).
 */
$leadsFile = __DIR__ . '/.demo-leads.jsonl';
$sent = false;
$err = null;

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    // honeypot — boti vyplní skryté pole
    if (trim((string)($_POST['website'] ?? '')) !== '') {
        $sent = true; // tvař se OK, ale zahoď
    } else {
        $email = strtolower(trim((string)($_POST['email'] ?? '')));
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $err = 'Zadej prosím platný e-mail.';
        } else {
            // jednoduchý rate-limit: max 30 řádků/hod z jedné IP
            $ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
            $recent = 0;
            if (is_file($leadsFile)) {
                $lines = @file($leadsFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
                $cutoff = time() - 3600;
                foreach ($lines as $ln) {
                    $r = json_decode($ln, true);
                    if (is_array($r) && ($r['ip'] ?? '') === $ip && strtotime($r['ts'] ?? '') > $cutoff) $recent++;
                }
            }
            if ($recent >= 30) {
                $err = 'Příliš mnoho pokusů, zkus to prosím později.';
            } else {
                $row = [
                    'email'    => $email,
                    'ts'       => date('Y-m-d H:i:s'),
                    'ip'       => $ip,
                    'ua'       => substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 200),
                    'notified' => 0,
                ];
                @file_put_contents($leadsFile, json_encode($row, JSON_UNESCAPED_UNICODE) . "\n", FILE_APPEND | LOCK_EX);
                $sent = true;
            }
        }
    }
}

// bilingválně dle Accept-Language (cs default)
$al = strtolower(substr($_SERVER['HTTP_ACCEPT_LANGUAGE'] ?? 'cs', 0, 2));
$en = ($al === 'en' || $al === 'es' || $al === 'de');
$T = $en ? [
    'title'   => 'Try the APPEK demo',
    'lead'    => 'Leave your e-mail and we will send you access to the live APPEK demo — orders, POS, invoicing, stock and HACCP.',
    'formlbl' => 'Your e-mail:',
    'ph'      => 'you@company.com',
    'btn'     => 'I want a demo',
    'thanks'  => 'Thanks! We will get back to you with demo access. 🎉',
    'retry'   => 'Please try again shortly.',
] : [
    'title'   => 'Vyzkoušejte si APPEK demo',
    'lead'    => 'Nechte nám e-mail a pošleme vám přístup do živého dema APPEK — objednávky, kasa, fakturace, sklad i HACCP.',
    'formlbl' => 'Váš e-mail:',
    'ph'      => 'vy@firma.cz',
    'btn'     => 'Chci demo',
    'thanks'  => 'Díky! Ozveme se vám s přístupem do dema. 🎉',
    'retry'   => 'Zkuste to prosím za chvíli.',
];

http_response_code(503);
header('Content-Type: text/html; charset=utf-8');
header('Retry-After: 300');
header('Cache-Control: no-store, no-cache, must-revalidate');
header('X-Robots-Tag: noindex, nofollow');
$esc = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
?><!DOCTYPE html>
<html lang="<?= $en ? 'en' : 'cs' ?>">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="robots" content="noindex,nofollow">
<title><?= $esc($T['title']) ?> · APPEK</title>
<style>
  :root{--brand:#BA7517;--brand-dark:#854F0B}
  *{box-sizing:border-box;margin:0;padding:0}
  body{font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Helvetica,Arial,sans-serif;
       min-height:100vh;min-height:100dvh;display:flex;align-items:center;justify-content:center;
       padding:24px;background:linear-gradient(135deg,#FDFDFE 0%,#FAEEDA 100%);color:#2C2C2A}
  .card{background:#fff;border:1px solid rgba(0,0,0,.06);border-radius:22px;
        box-shadow:0 12px 40px rgba(133,79,11,.10);padding:40px 34px;max-width:460px;width:100%;text-align:center}
  .logo{width:64px;height:64px;border-radius:16px;background:linear-gradient(180deg,var(--brand),var(--brand-dark));
        display:flex;align-items:center;justify-content:center;font-size:32px;margin:0 auto 20px;color:#fff}
  h1{font-size:23px;margin-bottom:10px;color:#1D1D1F}
  p.lead{color:#5F5E5A;line-height:1.6;margin-bottom:26px}
  form{display:flex;flex-direction:column;gap:10px;text-align:left}
  label{font-size:13px;font-weight:600;color:#5F5E5A}
  input[type=email]{padding:13px 15px;border:1.5px solid #E5E7EB;border-radius:12px;font-size:15px;width:100%}
  input[type=email]:focus{outline:none;border-color:var(--brand)}
  button{padding:13px 20px;border:none;border-radius:12px;background:linear-gradient(180deg,var(--brand),var(--brand-dark));
         color:#fff;font-weight:700;font-size:15px;cursor:pointer}
  button:hover{filter:brightness(1.05)}
  .ok{background:#EAF3DE;color:#27500A;padding:16px 18px;border-radius:14px;font-weight:600;line-height:1.5}
  .err{background:#FCEBEB;color:#A32D2D;padding:10px 14px;border-radius:10px;font-size:13px;margin-bottom:6px}
  .hp{position:absolute;left:-9999px;width:1px;height:1px;overflow:hidden}
  .foot{margin-top:24px;font-size:12px;color:#B8B7B0}
</style>
</head>
<body>
<div class="card">
  <div class="logo">🎬</div>
  <h1><?= $esc($T['title']) ?></h1>
  <p class="lead"><?= $esc($T['lead']) ?></p>
  <?php if ($sent): ?>
    <div class="ok"><?= $esc($T['thanks']) ?></div>
  <?php else: ?>
    <?php if ($err): ?><div class="err"><?= $esc($err) ?></div><?php endif; ?>
    <form method="post" action="/maintenance.php">
      <label for="email"><?= $esc($T['formlbl']) ?></label>
      <input type="email" id="email" name="email" placeholder="<?= $esc($T['ph']) ?>" required autocomplete="email">
      <div class="hp"><label>Website<input type="text" name="website" tabindex="-1" autocomplete="off"></label></div>
      <button type="submit"><?= $esc($T['btn']) ?></button>
    </form>
  <?php endif; ?>
  <div class="foot">APPEK · demo.appek.cz</div>
</div>
</body>
</html>
PHP;
    }
}
