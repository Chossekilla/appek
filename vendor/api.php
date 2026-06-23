<?php
/**
 * 🏢 VENDOR API — AJAX endpointy pro license management.
 *
 * Všechny endpointy vyžadují login (vendor_require_login).
 *
 *  GET  ?action=list          — vrátí všechny licence (s filtrem ?status=, ?q=)
 *  GET  ?action=stats         — dashboard counters
 *  POST ?action=generate      — vygeneruje + uloží novou licenci
 *  POST ?action=update        — upravit customer info
 *  POST ?action=revoke        — revoke licenci s důvodem
 *  POST ?action=unrevoke      — vrátit zpět na active
 *  POST ?action=delete        — fyzicky smazat (jen draft/test klíče)
 *  GET  ?action=audit_log     — posledních N audit záznamů
 *  POST ?action=change_password — změna hesla aktuálního uživatele
 */

require_once __DIR__ . '/_lib.php';

$user   = vendor_require_login();
$pdo    = vendor_db();
$action = $_GET['action'] ?? '';
$method = $_SERVER['REQUEST_METHOD'];

// ── LIST ────────────────────────────────────────────────────────
if ($action === 'list' && $method === 'GET') {
    $status = $_GET['status'] ?? '';
    $q      = trim($_GET['q'] ?? '');
    $where = [];
    $params = [];
    if (in_array($status, ['active','expired','revoked'], true)) {
        $where[] = "status = :s";
        $params['s'] = $status;
    }
    if ($q !== '') {
        $where[] = "(customer_name LIKE :q OR customer_company LIKE :q OR customer_email LIKE :q OR license_key LIKE :q)";
        $params['q'] = "%$q%";
    }
    $sql = "SELECT * FROM vendor_licenses"
         . ($where ? " WHERE " . implode(' AND ', $where) : '')
         . " ORDER BY issued_at DESC, id DESC LIMIT 500";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll();

    foreach ($rows as &$r) {
        $r['days_to_expiry'] = $r['expires_at']
            ? (int) ((strtotime($r['expires_at']) - time()) / 86400)
            : null;
    }

    vendor_json(['licenses' => $rows]);
}

// ── STATS ───────────────────────────────────────────────────────
if ($action === 'stats' && $method === 'GET') {
    $stats = [
        'total'         => (int) $pdo->query("SELECT COUNT(*) FROM vendor_licenses")->fetchColumn(),
        'active'        => (int) $pdo->query("SELECT COUNT(*) FROM vendor_licenses WHERE status='active'")->fetchColumn(),
        'expired'       => (int) $pdo->query("SELECT COUNT(*) FROM vendor_licenses WHERE status='expired'")->fetchColumn(),
        'revoked'       => (int) $pdo->query("SELECT COUNT(*) FROM vendor_licenses WHERE status='revoked'")->fetchColumn(),
        'expiring_soon' => (int) $pdo->query("
            SELECT COUNT(*) FROM vendor_licenses
            WHERE status='active' AND expires_at IS NOT NULL
              AND expires_at <= DATE_ADD(CURDATE(), INTERVAL 30 DAY)
              AND expires_at >= CURDATE()
        ")->fetchColumn(),
        'this_month'    => (int) $pdo->query("
            SELECT COUNT(*) FROM vendor_licenses
            WHERE issued_at >= DATE_FORMAT(CURDATE(), '%Y-%m-01')
        ")->fetchColumn(),
        'revenue_total' => (float) ($pdo->query("SELECT COALESCE(SUM(price_kc),0) FROM vendor_licenses WHERE paid=1")->fetchColumn() ?: 0),
    ];
    vendor_json($stats);
}

// ── GENERATE ────────────────────────────────────────────────────
if ($action === 'generate' && $method === 'POST') {
    $d = vendor_json_input();
    $name = trim($d['customer_name'] ?? '');
    if ($name === '') vendor_json_error('Jméno zákazníka je povinné');

    // Balíčky (může být prázdné = core only)
    $packages = $d['packages'] ?? [];
    if (!is_array($packages)) $packages = [];
    // Vyfiltruj jen známé balíčky
    $packages = array_values(array_filter($packages, fn($p) => isset(LICENSE_PACKAGE_BITS[$p])));

    // Snaž se vygenerovat unique klíč (do 5 pokusů — kolize je extrémně nepravděpodobná)
    $key = null;
    for ($i = 0; $i < 5; $i++) {
        $candidate = license_generate_with_packages($packages);
        $c = $pdo->prepare("SELECT 1 FROM vendor_licenses WHERE license_key = :k");
        $c->execute(['k' => $candidate]);
        if (!$c->fetchColumn()) { $key = $candidate; break; }
    }
    if (!$key) vendor_json_error('Nepodařilo se vygenerovat unique klíč — zkus znovu', 500);

    try {
        $pdo->prepare("
            INSERT INTO vendor_licenses
                (license_key, customer_name, customer_company, customer_email, customer_phone,
                 install_url, note, issued_at, expires_at, status, issued_by_id, price_kc, paid)
            VALUES (:k, :n, :co, :e, :p, :u, :nt, CURDATE(), :ex, 'active', :uid, :pr, :pd)
        ")->execute([
            'k'   => $key,
            'n'   => $name,
            'co'  => $d['customer_company'] ?? null,
            'e'   => $d['customer_email']   ?? null,
            'p'   => $d['customer_phone']   ?? null,
            'u'   => $d['install_url']      ?? null,
            'nt'  => $d['note']             ?? null,
            // 🆕 v3.0.302 — default ROČNÍ licence: bez zadaného data → platnost 1 rok od vystavení.
            'ex'  => !empty($d['expires_at']) ? $d['expires_at'] : date('Y-m-d', strtotime('+1 year')),
            'uid' => $user['id'],
            'pr'  => isset($d['price_kc']) ? (float) $d['price_kc'] : null,
            'pd'  => !empty($d['paid']) ? 1 : 0,
        ]);
        $id = (int) $pdo->lastInsertId();
        $license = $pdo->prepare("SELECT * FROM vendor_licenses WHERE id = :id");
        $license->execute(['id' => $id]);
        $row = $license->fetch();
        vendor_audit($pdo, $user, 'generate', $row, "Pro: $name");
        vendor_json(['ok' => true, 'license' => $row]);
    } catch (Throwable $e) {
        vendor_json_error('DB chyba: ' . $e->getMessage(), 500);
    }
}

// ── UPDATE ──────────────────────────────────────────────────────
if ($action === 'update' && $method === 'POST') {
    $d = vendor_json_input();
    $id = (int) ($d['id'] ?? 0);
    if (!$id) vendor_json_error('Chybí ID');

    $fields = ['customer_name','customer_company','customer_email','customer_phone',
               'install_url','note','expires_at','price_kc','paid'];
    $set = []; $params = ['id' => $id];
    foreach ($fields as $f) {
        if (array_key_exists($f, $d)) {
            $set[] = "$f = :$f";
            $params[$f] = $d[$f] === '' ? null : $d[$f];
        }
    }
    if (!$set) vendor_json_error('Nic ke změně');

    try {
        $sql = "UPDATE vendor_licenses SET " . implode(', ', $set) . " WHERE id = :id";
        $pdo->prepare($sql)->execute($params);
        $row = $pdo->prepare("SELECT * FROM vendor_licenses WHERE id = :id");
        $row->execute(['id' => $id]);
        $r = $row->fetch();
        vendor_audit($pdo, $user, 'update', $r, null);
        vendor_json(['ok' => true, 'license' => $r]);
    } catch (Throwable $e) {
        vendor_json_error('DB chyba: ' . $e->getMessage(), 500);
    }
}

// ── REISSUE — generuje nový klíč s novou množinou balíčků pro stejnou licenci ───
if ($action === 'reissue' && $method === 'POST') {
    $d = vendor_json_input();
    $id = (int) ($d['id'] ?? 0);
    $packages = $d['packages'] ?? [];
    if (!is_array($packages)) $packages = [];
    $packages = array_values(array_filter($packages, fn($p) => isset(LICENSE_PACKAGE_BITS[$p])));
    if (!$id) vendor_json_error('Chybí ID');

    $row = $pdo->prepare("SELECT * FROM vendor_licenses WHERE id = :id");
    $row->execute(['id' => $id]);
    $r = $row->fetch();
    if (!$r) vendor_json_error('Licence neexistuje', 404);

    // Reissue zachová random část původního klíče, jen přepíše balíčky
    $newKey = license_reissue_with_packages($r['license_key'], $packages);
    // Ověř že nový klíč nekonfliktuje
    $c = $pdo->prepare("SELECT id FROM vendor_licenses WHERE license_key = :k AND id != :id");
    $c->execute(['k' => $newKey, 'id' => $id]);
    if ($c->fetchColumn()) vendor_json_error('Nový klíč by konfliktoval — zkus znovu', 500);

    try {
        // 🆕 v3.0.384 — reissue dá ČISTÝ klíč: vynuluj fingerprint binding + anti-piracy lock
        //   (jinak nový klíč zdědí starý fingerprint/lock → zůstal by zamčený). Re-nabinduje se na příští heartbeat.
        $pdo->prepare("UPDATE vendor_licenses
                SET license_key = :k,
                    install_fingerprint = NULL, fingerprint_first_seen = NULL,
                    lock_state = 'active', lock_reason = NULL, lock_until = NULL, mismatch_count = 0
                WHERE id = :id")
            ->execute(['k' => $newKey, 'id' => $id]);
        $updated = $pdo->prepare("SELECT * FROM vendor_licenses WHERE id = :id");
        $updated->execute(['id' => $id]);
        $newRow = $updated->fetch();
        vendor_audit($pdo, $user, 'reissue', $newRow, "Balíčky: " . implode(',', $packages));
        vendor_json(['ok' => true, 'license' => $newRow, 'old_key' => $r['license_key']]);
    } catch (Throwable $e) {
        vendor_json_error('DB chyba: ' . $e->getMessage(), 500);
    }
}

// ── REVOKE ──────────────────────────────────────────────────────
if ($action === 'revoke' && $method === 'POST') {
    $d = vendor_json_input();
    $id     = (int) ($d['id'] ?? 0);
    $reason = trim($d['reason'] ?? '');
    if (!$id) vendor_json_error('Chybí ID');
    if (!$reason) vendor_json_error('Důvod revokace je povinný');

    try {
        $pdo->prepare("
            UPDATE vendor_licenses
            SET status = 'revoked', revoked_at = NOW(), revoke_reason = :r
            WHERE id = :id
        ")->execute(['r' => $reason, 'id' => $id]);
        $row = $pdo->prepare("SELECT * FROM vendor_licenses WHERE id = :id");
        $row->execute(['id' => $id]);
        $r = $row->fetch();
        vendor_audit($pdo, $user, 'revoke', $r, $reason);
        vendor_json(['ok' => true, 'license' => $r]);
    } catch (Throwable $e) {
        vendor_json_error('DB chyba: ' . $e->getMessage(), 500);
    }
}

// ── UNREVOKE ────────────────────────────────────────────────────
if ($action === 'unrevoke' && $method === 'POST') {
    $d = vendor_json_input();
    $id = (int) ($d['id'] ?? 0);
    if (!$id) vendor_json_error('Chybí ID');
    try {
        // Zpět na active, ale pokud datum expirace už uplynulo, dej expired
        $pdo->prepare("
            UPDATE vendor_licenses
            SET status = CASE
                WHEN expires_at IS NOT NULL AND expires_at < CURDATE() THEN 'expired'
                ELSE 'active'
            END,
            revoked_at = NULL, revoke_reason = NULL,
            lock_state = 'active', lock_reason = NULL, lock_until = NULL, mismatch_count = 0
            WHERE id = :id
        ")->execute(['id' => $id]);
        $row = $pdo->prepare("SELECT * FROM vendor_licenses WHERE id = :id");
        $row->execute(['id' => $id]);
        $r = $row->fetch();
        vendor_audit($pdo, $user, 'unrevoke', $r, null);
        vendor_json(['ok' => true, 'license' => $r]);
    } catch (Throwable $e) {
        vendor_json_error('DB chyba: ' . $e->getMessage(), 500);
    }
}

// ── UNLOCK (🆕 v3.0.384 — zruš anti-piracy lock; pro false-positive po legit migraci serveru) ──
if ($action === 'unlock' && $method === 'POST') {
    $d = vendor_json_input();
    $id = (int) ($d['id'] ?? 0);
    if (!$id) vendor_json_error('Chybí ID');
    try {
        // Vynuluj lock + fingerprint → klíč se re-nabinduje na aktuální (legit) instalaci při příštím
        //   heartbeatu (jinak by se po unlocku hned zase zamkl, protože fingerprint pořád nesedí).
        $pdo->prepare("
            UPDATE vendor_licenses
            SET lock_state = 'active', lock_reason = NULL, lock_until = NULL, mismatch_count = 0,
                install_fingerprint = NULL, fingerprint_first_seen = NULL
            WHERE id = :id
        ")->execute(['id' => $id]);
        $row = $pdo->prepare("SELECT * FROM vendor_licenses WHERE id = :id");
        $row->execute(['id' => $id]);
        $r = $row->fetch();
        vendor_audit($pdo, $user, 'unlock', $r, 'Anti-piracy lock zrušen (re-bind na příští heartbeat)');
        vendor_json(['ok' => true, 'license' => $r]);
    } catch (Throwable $e) {
        vendor_json_error('DB chyba: ' . $e->getMessage(), 500);
    }
}

// ── DELETE (pouze admin role) ───────────────────────────────────
if ($action === 'delete' && $method === 'POST') {
    if ($user['role'] !== 'admin') vendor_json_error('Jen admin smí mazat', 403);
    $d = vendor_json_input();
    $id = (int) ($d['id'] ?? 0);
    if (!$id) vendor_json_error('Chybí ID');
    try {
        $row = $pdo->prepare("SELECT * FROM vendor_licenses WHERE id = :id");
        $row->execute(['id' => $id]);
        $r = $row->fetch();
        if (!$r) vendor_json_error('Neexistuje', 404);
        $pdo->prepare("DELETE FROM vendor_licenses WHERE id = :id")->execute(['id' => $id]);
        vendor_audit($pdo, $user, 'delete', $r, 'physical delete');
        vendor_json(['ok' => true]);
    } catch (Throwable $e) {
        vendor_json_error('DB chyba: ' . $e->getMessage(), 500);
    }
}

// ── AUDIT LOG ───────────────────────────────────────────────────
if ($action === 'audit_log' && $method === 'GET') {
    $limit = max(1, min(500, (int) ($_GET['limit'] ?? 100)));
    $stmt = $pdo->prepare("SELECT * FROM vendor_audit_log ORDER BY id DESC LIMIT :l");
    $stmt->bindValue(':l', $limit, PDO::PARAM_INT);
    $stmt->execute();
    vendor_json(['logs' => $stmt->fetchAll()]);
}

// ── 2FA: get status ─────────────────────────────────────────────
if ($action === '2fa_status' && $method === 'GET') {
    $u = $pdo->prepare("SELECT totp_enabled FROM vendor_users WHERE id = :id");
    $u->execute(['id' => $user['id']]);
    vendor_json(['enabled' => (int) ($u->fetchColumn() ?: 0)]);
}

// ── 2FA: start setup (vygeneruje secret + otpauth url + qr) ─────
if ($action === '2fa_setup_start' && $method === 'POST') {
    $secret = totp_generate_secret(16);
    // Ulož secret jako pending v session (neuložíme do DB dokud user neověří kód)
    vendor_session_start();
    $_SESSION['totp_pending'] = $secret;
    $otpauth = totp_otpauth_url($secret, $user['username'], 'Appek Vendor');
    vendor_json([
        'secret'   => $secret,
        'otpauth'  => $otpauth,
        'qr_url'   => totp_qr_url($otpauth),
    ]);
}

// ── 2FA: confirm setup (uživatel zadá kód → uložíme secret + enable) ──
if ($action === '2fa_setup_confirm' && $method === 'POST') {
    $d = vendor_json_input();
    $code = trim($d['code'] ?? '');
    vendor_session_start();
    $secret = $_SESSION['totp_pending'] ?? '';
    if (!$secret) vendor_json_error('Setup nezačal — vygeneruj secret znovu', 400);
    if (!totp_verify($secret, $code)) vendor_json_error('Neplatný kód — zkus znovu', 400);
    try {
        $pdo->prepare("UPDATE vendor_users SET totp_secret = :s, totp_enabled = 1 WHERE id = :id")
            ->execute(['s' => $secret, 'id' => $user['id']]);
        unset($_SESSION['totp_pending']);
        vendor_audit($pdo, $user, '2fa_enabled', null, null);
        vendor_json(['ok' => true, 'enabled' => 1]);
    } catch (Throwable $e) {
        vendor_json_error('DB chyba: ' . $e->getMessage(), 500);
    }
}

// ── 2FA: disable ────────────────────────────────────────────────
if ($action === '2fa_disable' && $method === 'POST') {
    $d = vendor_json_input();
    $code = trim($d['code'] ?? '');
    $u = $pdo->prepare("SELECT totp_secret FROM vendor_users WHERE id = :id");
    $u->execute(['id' => $user['id']]);
    $secret = $u->fetchColumn();
    if (!$secret) vendor_json_error('2FA není zapnuté', 400);
    if (!totp_verify($secret, $code)) vendor_json_error('Neplatný kód — chrání proti náhodnému vypnutí', 400);
    try {
        $pdo->prepare("UPDATE vendor_users SET totp_secret = NULL, totp_enabled = 0 WHERE id = :id")
            ->execute(['id' => $user['id']]);
        vendor_audit($pdo, $user, '2fa_disabled', null, null);
        vendor_json(['ok' => true, 'enabled' => 0]);
    } catch (Throwable $e) {
        vendor_json_error('DB chyba: ' . $e->getMessage(), 500);
    }
}

// ── CHANGE PASSWORD ─────────────────────────────────────────────
if ($action === 'change_password' && $method === 'POST') {
    $d = vendor_json_input();
    $old = $d['old'] ?? '';
    $new = $d['new'] ?? '';
    if (strlen($new) < 10) vendor_json_error('Heslo: min 10 znaků');
    $u = $pdo->prepare("SELECT password_hash FROM vendor_users WHERE id = :id");
    $u->execute(['id' => $user['id']]);
    $h = $u->fetchColumn();
    if (!$h || !password_verify($old, $h)) vendor_json_error('Staré heslo nesedí');
    try {
        $pdo->prepare("UPDATE vendor_users SET password_hash = :h WHERE id = :id")
            ->execute(['h' => password_hash($new, PASSWORD_BCRYPT), 'id' => $user['id']]);
        vendor_audit($pdo, $user, 'change_password', null, null);
        vendor_json(['ok' => true]);
    } catch (Throwable $e) {
        vendor_json_error('Chyba: ' . $e->getMessage(), 500);
    }
}

vendor_json_error('Neznámá akce: ' . $action, 404);
