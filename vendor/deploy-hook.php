<?php
/**
 * 🚀 DEPLOY HOOK — token-autentizovaný endpoint pro GitHub Actions.
 * CI sem POSTne MASTER zip → spustí ověřená apply rutina. Nepoužívá session.
 */
require_once __DIR__ . '/_lib.php';
require_once __DIR__ . '/_self_update.php';

header('Content-Type: application/json; charset=utf-8');

function deploy_fail(int $code, string $msg, array $log = []): void {
    http_response_code($code);
    echo json_encode(['status' => 'error', 'error' => $msg, 'log' => $log]);
    exit;
}

// 🆕 v3.0.259 — persistuj deploy log na disk, ať je dohledatelný i bez CI logu (hpanel File Manager).
//   Drží posledních 20, chráněno .htaccess (logy nejsou web-čitelné).
function deploy_persist_log(string $version, array $log): void {
    try {
        $dir = __DIR__ . '/_deploy_logs';
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
            @file_put_contents($dir . '/.htaccess', "Require all denied\nDeny from all\n");
        }
        $body = '=== ' . date('Y-m-d H:i:s') . ' · v' . $version . " ===\n"
              . implode("\n", array_map(fn($l) => is_string($l) ? $l : json_encode($l), $log));
        @file_put_contents($dir . '/deploy-' . preg_replace('/[^0-9.]/', '', $version) . '-' . date('Ymd-His') . '.log', $body);
        $files = glob($dir . '/deploy-*.log') ?: [];
        if (count($files) > 20) {
            usort($files, fn($a, $b) => @filemtime($a) <=> @filemtime($b));
            foreach (array_slice($files, 0, count($files) - 20) as $old) @unlink($old);
        }
    } catch (Throwable $e) { /* logging je best-effort, nikdy nesmí shodit deploy */ }
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    deploy_fail(405, 'Pouze POST.');
}

if (!defined('DEPLOY_TOKEN') || DEPLOY_TOKEN === '') {
    deploy_fail(500, 'DEPLOY_TOKEN není nastaven v vendor/config.local.php.');
}
$token = $_POST['deploy_token'] ?? '';
if (!is_string($token) || !hash_equals(DEPLOY_TOKEN, $token)) {
    deploy_fail(403, 'Neplatný token.');
}

if (!isset($_FILES['master_zip']) || $_FILES['master_zip']['error'] !== UPLOAD_ERR_OK) {
    deploy_fail(400, 'Chybí master_zip nebo upload selhal.');
}

$lock = sys_get_temp_dir() . '/appek-deploy.lock';
$fh = fopen($lock, 'c');
if (!$fh || !flock($fh, LOCK_EX | LOCK_NB)) {
    deploy_fail(409, 'Jiné nasazení právě probíhá.');
}

$log = [];
try {
    $zipPath = sys_get_temp_dir() . '/appek-deploy-' . date('Ymd-His') . '.zip';
    if (!move_uploaded_file($_FILES['master_zip']['tmp_name'], $zipPath)) {
        deploy_fail(500, 'Nelze uložit nahraný zip.', $log);
    }
    $res = self_update_apply($zipPath, $log);
    @unlink($zipPath);

    // 🆕 v3.0.259 — persistuj log (i pro selhání níže ho už máme na disku)
    deploy_persist_log($res['version'] ?? 'unknown', $log);

    try { vendor_audit(vendor_db(), ['email' => 'ci@github', 'role' => 'ci'], 'deploy_hook', null, $res['version'] ?? '?'); }
    catch (Throwable $e) {}

    if (empty($res['ok'])) {
        deploy_fail(500, $res['error'] ?? 'Apply selhal.', $log);
    }
    // 🆕 v3.0.261 — publish ověření je NEBLOKUJÍCÍ (jen flag + log). Blokující 502 (v3.0.259)
    //   zablokoval deploy 260, když publish flaknul (255/256/260 občas selže). Pro hladký deploy
    //   radši projít a `published:false` nahlásit (response + trvalý log) → vzácný flake = ruční publish.
    $publishedOk = !empty($res['published']);
    if (!$publishedOk) {
        $log[] = '⚠️ POZOR: publish do vendor_updates NEPROBĚHL (v' . ($res['version'] ?? '?') . ') — demo/zákazníci se nezaktualizují, publikuj ručně přes vendor portál. Viz log výše.';
    }
    echo json_encode([
        'status'    => 'ok',
        'version'   => $res['version'] ?? null,
        'published' => $publishedOk,
        'health'    => $res['health'] ?? null,
        'log'       => $log,
    ]);
} catch (Throwable $e) {
    deploy_persist_log('error', $log);
    deploy_fail(500, $e->getMessage(), $log);
} finally {
    flock($fh, LOCK_UN);
    fclose($fh);
}
