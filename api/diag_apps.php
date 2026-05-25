<?php
/**
 * 🔍 DIAGNOSTIKA POS + FLOOR PLAN MODULŮ
 *
 * URL: /api/diag_apps.php
 * Bez authu — read-only diagnostika.
 *
 * Ukáže:
 *  - APP_VERSION
 *  - Existuje /pos/index.php?
 *  - Existuje /floorplan/index.php?
 *  - chmod oprávnění
 *  - Posledni update apply log
 */

@require_once __DIR__ . '/config.php';

$root = realpath(__DIR__ . '/..');
$ver  = defined('APP_VERSION') ? APP_VERSION : '?';

header('Content-Type: text/html; charset=UTF-8');
?>
<!DOCTYPE html>
<html lang="cs"><head>
<meta charset="UTF-8">
<title>APPEK · Diagnostika POS/Floor Plan</title>
<style>
body { font-family: -apple-system, sans-serif; max-width: 800px; margin: 40px auto; padding: 24px; color: #1a1d24; }
h1 { font-size: 22px; margin: 0 0 6px; }
.muted { color: #6b7280; font-size: 13px; }
.row { display: grid; grid-template-columns: 1fr auto; gap: 12px; padding: 10px 14px; background: #f3f4f6; border-radius: 8px; margin: 6px 0; font-size: 14px; }
.row strong { font-weight: 700; }
.ok { color: #059669; font-weight: 700; }
.bad { color: #dc2626; font-weight: 700; }
.warn { color: #d97706; font-weight: 700; }
pre { background: #1f2937; color: #d1d5db; padding: 14px; border-radius: 8px; font-size: 12px; overflow-x: auto; }
a { color: #4F46E5; font-weight: 600; }
.tip { background: #fef3c7; border-left: 3px solid #f59e0b; padding: 12px 14px; border-radius: 6px; margin: 14px 0; font-size: 13px; }
</style>
</head><body>
<h1>🔍 APPEK · Diagnostika modulů POS + Floor Plan</h1>
<p class="muted">Verze instalace: <strong>v<?= htmlspecialchars($ver) ?></strong> · Root: <code><?= htmlspecialchars($root) ?></code></p>

<h2>📦 Existence souborů</h2>

<?php
$checks = [
    'admin/pos.php'         => '🆕 Nový primární POS shell',
    'admin/pos.css'         => 'POS styly',
    'admin/pos.js'          => 'POS JS',
    'admin/floorplan.php'   => '🆕 Nový primární Floor Plan shell',
    'admin/floorplan.css'   => 'Floor Plan styly',
    'admin/floorplan.js'    => 'Floor Plan JS',
    'pos/index.php'         => 'Legacy POS shell (subfolder)',
    'pos/health.txt'        => 'POS health check',
    'pos/pos.css'           => 'Legacy POS styly',
    'pos/pos.js'            => 'Legacy POS JS',
    'floorplan/index.php'   => 'Legacy Floor Plan shell (subfolder)',
    'floorplan/health.txt'  => 'Floor Plan health check',
    'api/admin_pos.php'     => 'POS backend API',
    'api/admin_tables.php'  => 'Stoly + Floor Plan backend',
    'api/_packages_lib.php' => 'package_enabled() funkce',
    'api/config.local.php'  => 'DB credentials',
];

foreach ($checks as $rel => $desc) {
    $abs = $root . '/' . $rel;
    $exists = file_exists($abs);
    $size = $exists ? filesize($abs) : 0;
    $perms = $exists ? substr(sprintf('%o', fileperms($abs)), -4) : '—';
    $cls = $exists ? 'ok' : 'bad';
    $label = $exists ? '✓ ' . number_format($size) . ' B · chmod ' . $perms : '✗ NEEXISTUJE';
    echo "<div class=\"row\"><span><strong>{$rel}</strong> <span class=\"muted\">— {$desc}</span></span><span class=\"{$cls}\">{$label}</span></div>";
}
?>

<h2>📁 Stav složek</h2>

<?php
$dirs = ['admin', 'pos', 'floorplan', 'api', 'b2b'];
foreach ($dirs as $d) {
    $abs = $root . '/' . $d;
    $exists = is_dir($abs);
    if ($exists) {
        $count = count(scandir($abs)) - 2; // minus . and ..
        $perms = substr(sprintf('%o', fileperms($abs)), -4);
        $w = is_writable($abs) ? '✓ writable' : '✗ READ-ONLY';
        echo "<div class=\"row\"><span><strong>/{$d}/</strong></span><span class=\"ok\">{$count} souborů · chmod {$perms} · {$w}</span></div>";
    } else {
        echo "<div class=\"row\"><span><strong>/{$d}/</strong></span><span class=\"bad\">✗ SLOŽKA NEEXISTUJE</span></div>";
    }
}

// Check root je writable (pro update)
$rootW = is_writable($root);
echo "<div class=\"row\"><span><strong>Root</strong> (potřeba write access pro update)</span><span class=\"" . ($rootW ? 'ok' : 'bad') . "\">" . ($rootW ? '✓ writable' : '✗ READ-ONLY — update nemůže vytvořit /pos/ ani /floorplan/!') . "</span></div>";
?>

<h2>📜 Poslední update apply log</h2>

<?php
$logDir = $root . '/api/zalohy';
if (is_dir($logDir)) {
    $backups = array_filter(scandir($logDir), fn($f) => str_starts_with($f, 'update-backup-'));
    if ($backups) {
        rsort($backups);
        $last = $backups[0];
        echo "<p class=\"muted\">Nejnovější backup: <code>" . htmlspecialchars($last) . "</code></p>";
        $backupPath = $logDir . '/' . $last;
        if (is_dir($backupPath)) {
            $items = scandir($backupPath);
            $items = array_filter($items, fn($f) => $f !== '.' && $f !== '..');
            echo "<p class=\"muted\">Obsah backupu (před aplikací):</p><pre>";
            foreach (array_slice($items, 0, 20) as $it) echo htmlspecialchars($it) . "\n";
            echo "</pre>";
        }
    } else {
        echo "<p class=\"warn\">⚠️ Žádné backupy — buď update nikdy neběžel, nebo se mažou.</p>";
    }
} else {
    echo "<p class=\"warn\">⚠️ Složka /api/zalohy/ neexistuje — žádné historie updatů.</p>";
}
?>

<h2>🌐 Testovací URL</h2>

<div class="row"><a href="/pos/health.txt" target="_blank"><code>/pos/health.txt</code></a><span class="muted">→ má vrátit "APPEK POS module — health check OK"</span></div>
<div class="row"><a href="/floorplan/health.txt" target="_blank"><code>/floorplan/health.txt</code></a><span class="muted">→ má vrátit "APPEK Floor Plan module — health check OK"</span></div>
<div class="row"><a href="/admin/pos.php" target="_blank"><code>/admin/pos.php</code></a><span class="muted">→ 🆕 nový primární POS shell (vždy funguje)</span></div>
<div class="row"><a href="/admin/floorplan.php" target="_blank"><code>/admin/floorplan.php</code></a><span class="muted">→ 🆕 nový primární Floor Plan shell</span></div>
<div class="row"><a href="/api/version.php" target="_blank"><code>/api/version.php</code></a><span class="muted">→ JSON s aktivní APP_VERSION</span></div>

<div class="tip">
💡 <strong>Tip:</strong> Pokud <code>/pos/health.txt</code> dá 404 ale soubor <strong>existuje výše</strong> (✓ check), je to <strong>hosting cache</strong> — zkus <code>?cb=<?= rand(1000, 9999) ?></code> nebo Hostinger admin → Clear Cache. Pokud soubor <strong>neexistuje</strong>, update bundle nepřenesl podadresáře.
</div>

<p class="muted" style="margin-top:30px">Vygenerováno: <?= date('Y-m-d H:i:s') ?></p>
</body></html>
