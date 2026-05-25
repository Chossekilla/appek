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

    try { vendor_audit(vendor_db(), ['email' => 'ci@github', 'role' => 'ci'], 'deploy_hook', null, $res['version'] ?? '?'); }
    catch (Throwable $e) {}

    if (empty($res['ok'])) {
        deploy_fail(500, $res['error'] ?? 'Apply selhal.', $log);
    }
    echo json_encode([
        'status'  => 'ok',
        'version' => $res['version'] ?? null,
        'health'  => $res['health'] ?? null,
        'log'     => $log,
    ]);
} catch (Throwable $e) {
    deploy_fail(500, $e->getMessage(), $log);
} finally {
    flock($fh, LOCK_UN);
    fclose($fh);
}
