<?php
// CLI test: php vendor/tests/test_self_update.php <cesta-k-MASTER-zipu>
// Ověří self_update_apply() proti dočasnému webrootu (NE produkci).
require __DIR__ . '/../_self_update.php';

$masterZip = $argv[1] ?? '';
if (!is_file($masterZip)) { fwrite(STDERR, "Použití: php test_self_update.php appek-MASTER-vX.Y.Z.zip\n"); exit(2); }

$tmpRoot = sys_get_temp_dir() . '/appek-test-webroot-' . getmypid();
@mkdir($tmpRoot . '/api', 0777, true);
@mkdir($tmpRoot . '/vendor', 0777, true);
file_put_contents($tmpRoot . '/api/config.local.php', "<?php // SENTINEL preserve test\n");
file_put_contents($tmpRoot . '/api/.installed', 'sentinel');

$log = [];
$res = self_update_apply($masterZip, $log, $tmpRoot);

$fails = [];
if (empty($res['ok']))                                              $fails[] = 'apply nevrátil ok=true: ' . ($res['error'] ?? '');
if (!is_file($tmpRoot . '/vendor/index.php'))                       $fails[] = 'vendor/index.php se nenasadil';
if (!is_file($tmpRoot . '/index.html'))                             $fails[] = 'index.html se nenasadil';
if (@file_get_contents($tmpRoot . '/api/config.local.php') !== "<?php // SENTINEL preserve test\n")
                                                                    $fails[] = 'config.local.php NEBYL zachován (preserve selhal)';

exec('rm -rf ' . escapeshellarg($tmpRoot));

// — rollback test —
$rbRoot = sys_get_temp_dir() . '/appek-test-rb-' . getmypid();
$rbBak  = sys_get_temp_dir() . '/appek-test-bak-' . getmypid();
@mkdir($rbRoot . '/admin', 0777, true);
@mkdir($rbBak  . '/admin', 0777, true);
file_put_contents($rbBak  . '/admin/admin.js', 'PUVODNI');   // záloha = dobrý stav
file_put_contents($rbRoot . '/admin/admin.js', 'ROZBITE');   // webroot = rozbitý stav
$rbLog = [];
self_update_rollback($rbBak, $rbRoot, $rbLog);
if (@file_get_contents($rbRoot . '/admin/admin.js') !== 'PUVODNI') $fails[] = 'rollback neobnovil admin/admin.js';
exec('rm -rf ' . escapeshellarg($rbRoot) . ' ' . escapeshellarg($rbBak));

echo implode("\n", $log) . "\n";
if ($fails) { echo "❌ TEST FAIL:\n - " . implode("\n - ", $fails) . "\n"; exit(1); }
echo "✅ TEST PASS — apply + preserve + rollback OK\n";
