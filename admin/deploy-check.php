<?php
/**
 * ✅ DEPLOY CHECK — ověří že nasazené soubory SKUTEČNĚ odpovídají buildu.
 *
 * Problém který řeší:
 *   Patička ukazuje "APPEK v2.9.65", ale admin.css na serveru zůstal starý.
 *   Verze v patičce = jen string v souboru — může se přepsat, i když OBSAH ne.
 *   Tahle stránka NEVĚŘÍ verzím. Spočítá SHA-256 každého souboru na disku
 *   a porovná ho s api/.build-manifest.json (= co build skutečně vyrobil).
 *
 *   ❌ STALE  = soubor na serveru má JINÝ obsah než build → deploy SELHAL
 *   ✅ MATCH  = soubor je bajt po bajtu správný → deploy OK
 *               (pokud i tak vidíš staré UI → browser/SW cache, ne deploy)
 *
 * URL:  /admin/deploy-check.php            — HTML report
 *       /admin/deploy-check.php?format=json — JSON pro skripty / patičku
 *
 * Manifest api/.build-manifest.json se generuje v build-zip.sh po version-bumpu
 * a cestuje s každým deployem (customer ZIP / MASTER ZIP / update bundle).
 */

session_start();
@require_once __DIR__ . '/../api/config.php';
@require_once __DIR__ . '/../api/_admin_auth.php';

$dcUser = function_exists('aktualni_uzivatel_z_session') ? aktualni_uzivatel_z_session() : null;
if (!$dcUser || ($dcUser['role'] ?? '') !== 'admin') {
    http_response_code(403);
    die('<!DOCTYPE html><html lang="cs"><head><meta charset="UTF-8"><title>403</title></head>'
      . '<body style="font-family:system-ui;padding:40px;text-align:center">'
      . '<h1>🔒 403 Forbidden</h1><p>Deploy-check vyžaduje admin session.</p>'
      . '<p><a href="/admin/">→ Přihlásit</a></p></body></html>');
}

$root        = realpath(__DIR__ . '/..');                 // install root (webroot nebo demo/)
$manifestPath = $root . '/api/.build-manifest.json';
$appVersion  = defined('APP_VERSION') ? APP_VERSION : '?';

// ─── Načti build manifest ────────────────────────────────────────
$manifest = null;
$manifestErr = null;
if (!file_exists($manifestPath)) {
    $manifestErr = 'api/.build-manifest.json neexistuje — instalace je starší než v2.9.65, '
                 . 'nebo deploy nepřenesl manifest. Nasaď build v2.9.65+ a zkus znovu.';
} else {
    $manifest = json_decode((string) file_get_contents($manifestPath), true);
    if (!is_array($manifest) || empty($manifest['files'])) {
        $manifestErr = 'api/.build-manifest.json je poškozený nebo prázdný.';
        $manifest = null;
    }
}

// ─── Porovnej každý soubor: disk SHA-256 vs manifest ─────────────
$rows       = [];   // [{rel, status, expected, actual, exp_size, act_size}]
$cntOk = $cntStale = $cntMissing = 0;

if ($manifest) {
    foreach ($manifest['files'] as $rel => $meta) {
        $expHash = is_array($meta) ? ($meta['sha256'] ?? '') : (string) $meta;
        $expSize = is_array($meta) ? (int) ($meta['size'] ?? 0) : 0;
        $abs     = $root . '/' . $rel;
        @clearstatcache(true, $abs);

        if (!file_exists($abs)) {
            $rows[] = ['rel' => $rel, 'status' => 'missing', 'expected' => $expHash,
                       'actual' => null, 'exp_size' => $expSize, 'act_size' => null];
            $cntMissing++;
            continue;
        }
        $actHash = hash_file('sha256', $abs);
        $actSize = filesize($abs);
        if (is_string($actHash) && $expHash !== '' && hash_equals(strtolower($expHash), strtolower($actHash))) {
            $rows[] = ['rel' => $rel, 'status' => 'ok', 'expected' => $expHash,
                       'actual' => $actHash, 'exp_size' => $expSize, 'act_size' => $actSize];
            $cntOk++;
        } else {
            $rows[] = ['rel' => $rel, 'status' => 'stale', 'expected' => $expHash,
                       'actual' => $actHash, 'exp_size' => $expSize, 'act_size' => $actSize];
            $cntStale++;
        }
    }
}

$total    = count($rows);
$problems = $cntStale + $cntMissing;
$allOk    = $manifest && $problems === 0;
$buildVer = $manifest['version'] ?? null;
$builtAt  = $manifest['built_at'] ?? null;

// Křížová kontrola: verze v manifestu vs APP_VERSION v configu
$versionMismatch = $buildVer && $appVersion !== '?' && $buildVer !== $appVersion;

// Poslední výsledek z updateru (zapisuje api/updates_apply.php)
$lastApply = null;
$lastApplyPath = $root . '/api/.deploy-check.json';
if (file_exists($lastApplyPath)) {
    $lastApply = json_decode((string) file_get_contents($lastApplyPath), true);
    if (!is_array($lastApply)) $lastApply = null;
}

// Status jednotlivého souboru (pro spotlight)
function dc_file_status(array $rows, string $rel): array {
    foreach ($rows as $r) {
        if ($r['rel'] === $rel) return $r;
    }
    return ['rel' => $rel, 'status' => 'absent', 'expected' => null,
            'actual' => null, 'exp_size' => null, 'act_size' => null];
}

// ─── JSON výstup ─────────────────────────────────────────────────
if (($_GET['format'] ?? '') === 'json') {
    header('Content-Type: application/json; charset=UTF-8');
    header('Cache-Control: no-cache, no-store, must-revalidate');
    echo json_encode([
        'ok'               => $allOk,
        'has_manifest'     => (bool) $manifest,
        'manifest_error'   => $manifestErr,
        'app_version'      => $appVersion,
        'build_version'    => $buildVer,
        'built_at'         => $builtAt,
        'version_mismatch' => $versionMismatch,
        'total'            => $total,
        'ok_count'         => $cntOk,
        'stale_count'      => $cntStale,
        'missing_count'    => $cntMissing,
        'problem_files'    => array_values(array_map(
            fn($r) => ['file' => $r['rel'], 'status' => $r['status'],
                       'expected' => substr((string) $r['expected'], 0, 16),
                       'actual'   => $r['actual'] ? substr($r['actual'], 0, 16) : null],
            array_filter($rows, fn($r) => $r['status'] !== 'ok')
        )),
        'checked_at'       => date('c'),
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

// Seřaď: problémy nahoru, pak podle cesty
usort($rows, function ($a, $b) {
    $rank = ['stale' => 0, 'missing' => 1, 'ok' => 2];
    $ra = $rank[$a['status']] ?? 3;
    $rb = $rank[$b['status']] ?? 3;
    return $ra === $rb ? strcmp($a['rel'], $b['rel']) : $ra - $rb;
});

function dc_h($s) { return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8'); }
function dc_kb($b) { return $b === null ? '—' : number_format($b / 1024, 1, ',', ' ') . ' kB'; }
$spotKeys = ['admin/admin.css', 'admin/admin.js', 'admin/index.html'];
?><!DOCTYPE html>
<html lang="cs">
<head>
<meta charset="UTF-8">
<meta http-equiv="Cache-Control" content="no-cache, no-store, must-revalidate">
<meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="robots" content="noindex,nofollow">
<title>✅ APPEK · Deploy Check</title>
<style>
  :root { --ok:#15803d; --okbg:#dcfce7; --bad:#b91c1c; --badbg:#fee2e2;
          --warn:#854F0B; --warnbg:#FEF3C7; --ink:#1d1d1f; --mut:#6e6e73; }
  * { box-sizing: border-box; }
  body { font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',sans-serif; background:#FBFAF7;
         color:var(--ink); padding:24px; max-width:1000px; margin:0 auto; }
  h1 { font-size:22px; margin:0 0 4px; }
  .sub { color:var(--mut); font-size:13px; margin-bottom:20px; }
  code { font-family:'SF Mono',Menlo,monospace; font-size:0.92em; }

  .verdict { border-radius:14px; padding:22px 24px; margin-bottom:18px; }
  .verdict.pass { background:linear-gradient(135deg,#dcfce7,#bbf7d0); border:1.5px solid #4ade80; }
  .verdict.fail { background:linear-gradient(135deg,#fee2e2,#fecaca); border:1.5px solid #f87171; }
  .verdict.unknown { background:linear-gradient(135deg,#FEF3C7,#FDE68A); border:1.5px solid #FBBF24; }
  .verdict .big { font-size:24px; font-weight:800; margin-bottom:6px; }
  .verdict .desc { font-size:14px; line-height:1.55; }
  .verdict.pass .big { color:var(--ok); }
  .verdict.fail .big { color:var(--bad); }
  .verdict.unknown .big { color:var(--warn); }

  .stats { display:grid; grid-template-columns:repeat(4,1fr); gap:10px; margin-bottom:18px; }
  .stat { background:#fff; border:1px solid #e8e8ea; border-radius:12px; padding:14px 16px; }
  .stat .n { font-size:26px; font-weight:800; font-family:'SF Mono',Menlo,monospace; }
  .stat .l { font-size:11.5px; color:var(--mut); text-transform:uppercase; letter-spacing:.4px; margin-top:2px; }
  .stat.ok .n { color:var(--ok); }
  .stat.stale .n { color:var(--bad); }
  .stat.miss .n { color:var(--bad); }
  .stat.total .n { color:var(--ink); }

  .meta { background:#fff; border:1px solid #e8e8ea; border-radius:12px; padding:14px 18px;
          margin-bottom:18px; font-size:13px; line-height:1.7; }
  .meta b { font-family:'SF Mono',Menlo,monospace; }
  .pill { display:inline-block; padding:1px 8px; border-radius:999px; font-size:11px; font-weight:700; }
  .pill.ok { background:var(--okbg); color:var(--ok); }
  .pill.bad { background:var(--badbg); color:var(--bad); }

  h2 { font-size:15px; margin:22px 0 10px; }
  .spot { display:grid; grid-template-columns:repeat(3,1fr); gap:10px; margin-bottom:8px; }
  .card { background:#fff; border:1px solid #e8e8ea; border-radius:12px; padding:14px 16px; }
  .card.stale { border-color:#f87171; background:#fff5f5; }
  .card.missing { border-color:#f87171; background:#fff5f5; }
  .card.ok { border-color:#86efac; }
  .card .fname { font-family:'SF Mono',Menlo,monospace; font-size:12.5px; font-weight:700; }
  .card .fstat { font-size:13px; font-weight:700; margin-top:6px; }
  .card .fhash { font-family:'SF Mono',Menlo,monospace; font-size:10.5px; color:var(--mut);
                 margin-top:6px; word-break:break-all; line-height:1.5; }
  .card.ok .fstat { color:var(--ok); }
  .card.stale .fstat, .card.missing .fstat { color:var(--bad); }

  table { width:100%; border-collapse:collapse; background:#fff; border-radius:12px; overflow:hidden;
          box-shadow:0 1px 3px rgba(0,0,0,.05); font-size:12.5px; }
  th { text-align:left; padding:9px 12px; background:#f7f8fa; font-weight:700; color:var(--mut);
       border-bottom:1px solid #e5e5e7; font-size:11px; text-transform:uppercase; letter-spacing:.4px; }
  td { padding:8px 12px; border-bottom:1px solid #f0f0f3; vertical-align:middle; }
  tr:last-child td { border-bottom:none; }
  tr.r-stale { background:#fff5f5; }
  tr.r-missing { background:#fff5f5; }
  .fp { font-family:'SF Mono',Menlo,monospace; font-size:11.5px; }
  .hash { font-family:'SF Mono',Menlo,monospace; font-size:10.5px; color:var(--mut); }
  .badge { font-size:10.5px; padding:2px 8px; border-radius:6px; font-weight:700; white-space:nowrap; }
  .badge.ok { background:var(--okbg); color:var(--ok); }
  .badge.stale { background:var(--badbg); color:var(--bad); }
  .badge.missing { background:var(--badbg); color:var(--bad); }

  .diag { border-radius:12px; padding:16px 20px; margin:18px 0; font-size:13.5px; line-height:1.6; }
  .diag.fail { background:var(--badbg); border-left:4px solid var(--bad); color:#7f1d1d; }
  .diag.pass { background:var(--okbg); border-left:4px solid var(--ok); color:#14532d; }
  .diag.unknown { background:var(--warnbg); border-left:4px solid #FBBF24; color:var(--warn); }
  .diag b { font-weight:800; }
  .diag ol { margin:8px 0 0; padding-left:20px; }
  .diag li { margin:3px 0; }

  .actions { margin:22px 0; display:flex; gap:8px; flex-wrap:wrap; }
  .btn { padding:10px 18px; border-radius:10px; border:none; cursor:pointer; font:inherit;
         font-size:13px; font-weight:600; text-decoration:none; display:inline-block; }
  .btn-primary { background:linear-gradient(180deg,#BA7517,#854F0B); color:#fff; }
  .btn-sec { background:#f0f0f2; color:var(--ink); }
  details { margin-top:6px; }
  summary { cursor:pointer; font-size:13px; font-weight:600; color:var(--mut); padding:6px 0; }
  .foot { margin-top:28px; font-size:11px; color:#9a9a9e; font-family:'SF Mono',Menlo,monospace; }
</style>
</head>
<body>

<h1>✅ APPEK · Deploy Check</h1>
<div class="sub">Porovná <b>SHA-256 každého souboru na disku</b> s tím, co build vyrobil
  (<code>api/.build-manifest.json</code>). Nevěří verzi v patičce — ta může lhát.</div>

<?php if (!$manifest): ?>

  <div class="verdict unknown">
    <div class="big">⚠️ Nelze ověřit — chybí manifest</div>
    <div class="desc"><?= dc_h($manifestErr) ?></div>
  </div>
  <div class="diag unknown">
    <b>Co s tím:</b>
    <ol>
      <li>Lokálně postav nový build: <code>./build-zip.sh 2.9.65</code> — ten vygeneruje <code>api/.build-manifest.json</code>.</li>
      <li>Nasaď MASTER ZIP přes vendor self-update, pak na instalaci spusť „Aktualizovat".</li>
      <li>Vrať se sem — manifest dorazí s deployem a tahle stránka začne ověřovat.</li>
    </ol>
  </div>

<?php else: ?>

  <?php if ($allOk && !$versionMismatch): ?>
    <div class="verdict pass">
      <div class="big">✅ Deploy je kompletní</div>
      <div class="desc">Všech <b><?= $total ?></b> souborů má bajt po bajtu správný obsah
        (SHA-256 sedí). Build <b>v<?= dc_h($buildVer) ?></b> je na serveru celý.</div>
    </div>
  <?php elseif ($allOk && $versionMismatch): ?>
    <div class="verdict unknown">
      <div class="big">⚠️ Soubory sedí, ale verze ne</div>
      <div class="desc">Obsah všech <?= $total ?> souborů je správný, ale manifest hlásí build
        <b>v<?= dc_h($buildVer) ?></b> a <code>APP_VERSION</code> v configu je
        <b>v<?= dc_h($appVersion) ?></b>. Nasaď čerstvý build, ať to sedí.</div>
    </div>
  <?php else: ?>
    <div class="verdict fail">
      <div class="big">❌ Deploy NENÍ kompletní — <?= $problems ?> <?= $problems === 1 ? 'soubor' : ($problems < 5 ? 'soubory' : 'souborů') ?> nesedí</div>
      <div class="desc">Soubory označené <b>STALE</b> mají na serveru <b>jiný obsah</b> než
        vyrobil build — deploy je <b>nepřepsal</b>. Verze v patičce může klidně ukazovat
        novou — ale samotný kód je starý. Přesně tohle hledáme.</div>
    </div>
  <?php endif; ?>

  <div class="stats">
    <div class="stat total"><div class="n"><?= $total ?></div><div class="l">souborů ověřeno</div></div>
    <div class="stat ok"><div class="n"><?= $cntOk ?></div><div class="l">✅ obsah sedí</div></div>
    <div class="stat stale"><div class="n"><?= $cntStale ?></div><div class="l">❌ starý obsah</div></div>
    <div class="stat miss"><div class="n"><?= $cntMissing ?></div><div class="l">⚠️ chybí na disku</div></div>
  </div>

  <div class="meta">
    Build verze (manifest): <b>v<?= dc_h($buildVer) ?></b>
      <?php if ($versionMismatch): ?><span class="pill bad">≠ config</span><?php else: ?><span class="pill ok">✓</span><?php endif; ?><br>
    <code>APP_VERSION</code> (api/config.php): <b>v<?= dc_h($appVersion) ?></b><br>
    Build vyroben: <b><?= dc_h($builtAt ?: '—') ?></b><br>
    Instalace root: <code><?= dc_h($root) ?></code>
  </div>

  <h2>🎯 Klíčové soubory</h2>
  <div class="spot">
    <?php foreach ($spotKeys as $sk):
        $st = dc_file_status($rows, $sk);
        $cls = $st['status'] === 'ok' ? 'ok' : ($st['status'] === 'absent' ? '' : $st['status']);
        $lbl = ['ok' => '✅ Obsah sedí', 'stale' => '❌ STALE — starý obsah',
                'missing' => '⚠️ Chybí na disku', 'absent' => '— Není v manifestu'][$st['status']] ?? '?';
    ?>
    <div class="card <?= $cls ?>">
      <div class="fname"><?= dc_h($sk) ?></div>
      <div class="fstat"><?= $lbl ?></div>
      <?php if ($st['status'] === 'stale'): ?>
        <div class="fhash">build&nbsp;: <?= dc_h(substr((string) $st['expected'], 0, 24)) ?>…<br>
                           disk&nbsp;&nbsp;: <?= dc_h(substr((string) $st['actual'], 0, 24)) ?>…</div>
      <?php elseif ($st['status'] === 'ok'): ?>
        <div class="fhash">SHA-256 <?= dc_h(substr((string) $st['actual'], 0, 24)) ?>…</div>
      <?php endif; ?>
    </div>
    <?php endforeach; ?>
  </div>

  <?php if (!$allOk): ?>
    <div class="diag fail">
      <b>Diagnóza — deploy selhal.</b> <?= $cntStale ?> souborů má na serveru jiný obsah
      a <?= $cntMissing ?> chybí. Tohle <u>není</u> problém browser cache — soubory na disku
      jsou fyzicky staré. Postup:
      <ol>
        <li>Lokálně: <code>./build-zip.sh <?= dc_h($buildVer ?: '2.9.65') ?></code></li>
        <li>vendor.appek.cz → Self-update → nahraj <code>appek-MASTER-v…zip</code></li>
        <li>Na instalaci (admin → Nastavení) → <b>Aktualizovat</b></li>
        <li>Vrať se sem a klikni <b>Spustit kontrolu znovu</b> — musí být 0 STALE</li>
      </ol>
      Pokud STALE zůstane i po updatu → soubor nejde přepsat (práva složky / hosting cache).
      Zkontroluj <code>chmod 755</code> složek a <code>644</code> souborů.
    </div>
  <?php else: ?>
    <div class="diag pass">
      <b>Soubory na serveru jsou v pořádku.</b> Každý ověřený soubor má přesně ten obsah,
      který build vyrobil. Pokud i tak <u>vidíš staré UI</u>, je to <b>cache prohlížeče
      nebo Service Workeru</b> — ne deploy. Použij
      <a href="clear-cache.html" style="color:#14532d;font-weight:700">🧹 Clear Cache</a>
      nebo hard refresh (Ctrl/Cmd+Shift+R).
    </div>
  <?php endif; ?>

  <?php if ($lastApply): ?>
    <details>
      <summary>📋 Poslední „Aktualizovat" (z api/updates_apply.php)</summary>
      <div class="meta" style="margin-top:8px">
        Verze: <b>v<?= dc_h($lastApply['version'] ?? '?') ?></b> ·
        ověřeno: <b><?= dc_h($lastApply['verified_ok'] ?? '?') ?></b> /
        <b><?= dc_h($lastApply['verified_total'] ?? '?') ?></b> souborů ·
        čas: <b><?= dc_h($lastApply['checked_at'] ?? '?') ?></b>
        <?php if (!empty($lastApply['failed_files'])): ?>
          <br>Nesedlo: <code><?= dc_h(implode(', ', (array) $lastApply['failed_files'])) ?></code>
        <?php endif; ?>
      </div>
    </details>
  <?php endif; ?>

  <h2>📄 Všechny soubory (<?= $total ?>)</h2>
  <table>
    <thead><tr>
      <th>Soubor</th><th>Stav</th><th>Build SHA-256</th><th>Disk SHA-256</th><th>Velikost</th>
    </tr></thead>
    <tbody>
    <?php foreach ($rows as $r):
        $badge = ['ok' => 'ok', 'stale' => 'stale', 'missing' => 'missing'][$r['status']];
        $blabel = ['ok' => '✅ sedí', 'stale' => '❌ STALE', 'missing' => '⚠️ CHYBÍ'][$r['status']];
    ?>
      <tr class="r-<?= $r['status'] ?>">
        <td class="fp"><?= dc_h($r['rel']) ?></td>
        <td><span class="badge <?= $badge ?>"><?= $blabel ?></span></td>
        <td class="hash"><?= dc_h(substr((string) $r['expected'], 0, 16)) ?>…</td>
        <td class="hash"><?= $r['actual'] ? dc_h(substr($r['actual'], 0, 16)) . '…' : '—' ?></td>
        <td class="hash">
          <?= dc_kb($r['act_size']) ?>
          <?php if ($r['status'] === 'stale' && $r['act_size'] !== (int) $r['exp_size']): ?>
            <span style="color:#b91c1c">(build <?= dc_kb($r['exp_size']) ?>)</span>
          <?php endif; ?>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>

<?php endif; ?>

<div class="actions">
  <a href="?t=<?= time() ?>" class="btn btn-primary">🔄 Spustit kontrolu znovu</a>
  <a href="clear-cache.html" class="btn btn-sec">🧹 Clear Cache</a>
  <a href="verify-version.php" class="btn btn-sec">🔍 Version diagnostic</a>
  <a href="?format=json" class="btn btn-sec">📋 JSON</a>
  <a href="index.html" class="btn btn-sec">🏠 Admin</a>
</div>

<div class="foot">
  PHP <?= dc_h(PHP_VERSION) ?> · <?= dc_h(date('Y-m-d H:i:s')) ?> ·
  <?= dc_h($_SERVER['HTTP_HOST'] ?? 'localhost') ?> · deploy-check v1
</div>

</body>
</html>
