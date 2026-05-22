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
echo implode("\n", $log) . "\n";
if ($fails) { echo "❌ TEST FAIL:\n - " . implode("\n - ", $fails) . "\n"; exit(1); }
echo "✅ TEST PASS — apply + preserve OK\n";
