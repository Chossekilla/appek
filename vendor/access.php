<?php
/**
 * 🔐 ACCESS MANAGEMENT — Správa všech přístupů napříč subdoménami.
 *
 * Centralizovaný pohled na:
 *   - Admin users (admin — admin_users tabulka)
 *   - B2B users (b2b — b2b_uzivatele tabulka)
 *   - Vendor users (vendor_users tabulka)
 *   - Aktivní sessions
 *   - API tokeny
 *   - Failed login attempts (rate limiting)
 *   - Demo access log
 */
require_once __DIR__ . '/_lib.php';
require_once __DIR__ . '/_layout.php';

$user = vendor_require_login();
$currentPage = 'access';

$tab = $_GET['tab'] ?? 'admins';

// ─── Akce: deaktivace/aktivace uživatele ───
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $action = $_POST['action'];
    $target_type = $_POST['target_type'] ?? '';
    $target_id = (int) ($_POST['target_id'] ?? 0);

    try {
        // Připoj se k hlavní DB (customer admin)
        // Sdílíme stejnou DB jako customer aplikace (z config.local.php)
        $mainConfig = realpath(__DIR__ . '/..') . '/api/config.local.php';
        if (file_exists($mainConfig)) {
            require_once $mainConfig;
            $mainPdo = new PDO(
                "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8mb4",
                DB_USER, DB_PASS,
                [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
            );

            // B2B uživatelé jsou odberatele s login_email (ne samostatná tabulka)
            if ($action === 'deactivate' && in_array($target_type, ['admin', 'b2b'])) {
                if ($target_type === 'admin') {
                    $mainPdo->prepare("UPDATE admin_users SET aktivni = 0 WHERE id = :id")->execute(['id' => $target_id]);
                } else {
                    $mainPdo->prepare("UPDATE odberatele SET blokovan = 1 WHERE id = :id")->execute(['id' => $target_id]);
                }
                $_SESSION['_flash_ok'] = "Uživatel deaktivován.";
            } elseif ($action === 'activate' && in_array($target_type, ['admin', 'b2b'])) {
                if ($target_type === 'admin') {
                    $mainPdo->prepare("UPDATE admin_users SET aktivni = 1 WHERE id = :id")->execute(['id' => $target_id]);
                } else {
                    $mainPdo->prepare("UPDATE odberatele SET blokovan = 0 WHERE id = :id")->execute(['id' => $target_id]);
                }
                $_SESSION['_flash_ok'] = "Uživatel aktivován.";
            } elseif ($action === 'reset_password' && in_array($target_type, ['admin', 'b2b'])) {
                $newPass = bin2hex(random_bytes(6));
                $hash = password_hash($newPass, PASSWORD_BCRYPT);
                if ($target_type === 'admin') {
                    $mainPdo->prepare("UPDATE admin_users SET heslo_hash = :h WHERE id = :id")
                        ->execute(['h' => $hash, 'id' => $target_id]);
                } else {
                    $mainPdo->prepare("UPDATE odberatele SET heslo_hash = :h WHERE id = :id")
                        ->execute(['h' => $hash, 'id' => $target_id]);
                }
                $_SESSION['_flash_ok'] = "Heslo resetováno. Nové heslo: <code>$newPass</code> (sděl uživateli a zajisti změnu po loginu)";
            }
        }

        // Vendor users akce (vlastní DB)
        if ($target_type === 'vendor') {
            $pdo = vendor_db();
            if ($action === 'deactivate') {
                $pdo->prepare("UPDATE vendor_users SET aktivni = 0 WHERE id = :id")
                    ->execute(['id' => $target_id]);
                $_SESSION['_flash_ok'] = "Vendor user deaktivován.";
            } elseif ($action === 'activate') {
                $pdo->prepare("UPDATE vendor_users SET aktivni = 1 WHERE id = :id")
                    ->execute(['id' => $target_id]);
                $_SESSION['_flash_ok'] = "Vendor user aktivován.";
            }
        }

        header('Location: access.php?tab=' . $tab);
        exit;
    } catch (Throwable $e) {
        $_SESSION['_flash_err'] = $e->getMessage();
    }
}

// Načti data
$mainPdo = null;
try {
    $mainConfig = realpath(__DIR__ . '/..') . '/api/config.local.php';
    if (file_exists($mainConfig)) {
        require_once $mainConfig;
        $mainPdo = new PDO(
            "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8mb4",
            DB_USER, DB_PASS,
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
        );
    }
} catch (Throwable $e) {}

$admins = [];
$b2bUsers = [];
$vendorUsers = [];
$apiTokens = [];
$failedAttempts = [];
$demoLog = [];

if ($mainPdo) {
    try {
        $admins = $mainPdo->query("SELECT id, email, jmeno, role, aktivni, vytvoreno, posledni_login FROM admin_users ORDER BY id DESC")->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {}
    // B2B uživatelé = odberatele.login_email is not null (samostatná tabulka b2b_uzivatele neexistuje v reálném schématu)
    try {
        $b2bUsers = $mainPdo->query("
            SELECT id,
                   login_email AS email,
                   nazev AS jmeno,
                   id AS odberatel_id,
                   IF(blokovan = 0, 1, 0) AS aktivni,
                   created_at AS vytvoreno,
                   NULL AS posledni_login
            FROM odberatele
            WHERE login_email IS NOT NULL AND login_email <> ''
            ORDER BY id DESC LIMIT 100
        ")->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {}
    try {
        $apiTokens = $mainPdo->query("SELECT id, nazev, typ, vytvoreno, posledni_pouziti, aktivni FROM api_tokeny ORDER BY id DESC")->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {}
    try {
        $failedAttempts = $mainPdo->query("
            SELECT email, ip, typ, COUNT(*) as pocet, MAX(cas) as posledni
            FROM prihlaseni_pokusy
            WHERE uspesny = 0 AND cas > DATE_SUB(NOW(), INTERVAL 7 DAY)
            GROUP BY email, ip, typ
            ORDER BY posledni DESC
            LIMIT 50
        ")->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {}
    try {
        $demoLog = $mainPdo->query("
            SELECT id, ip, user_agent, cas, akce
            FROM demo_pristupy
            ORDER BY id DESC LIMIT 50
        ")->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        // Tabulka možná neexistuje — vytvořit
        try {
            $mainPdo->exec("
                CREATE TABLE IF NOT EXISTS demo_pristupy (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    ip VARCHAR(45),
                    user_agent TEXT,
                    akce VARCHAR(50),
                    referer VARCHAR(255),
                    cas DATETIME DEFAULT CURRENT_TIMESTAMP,
                    INDEX idx_cas (cas)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
            ");
        } catch (Throwable $e2) {}
    }
}

// Vendor users (vlastní DB)
try {
    $pdo = vendor_db();
    $cols = $pdo->query("SHOW COLUMNS FROM vendor_users")->fetchAll(PDO::FETCH_COLUMN);
    if (!in_array('aktivni', $cols, true)) {
        $pdo->exec("ALTER TABLE vendor_users ADD COLUMN aktivni TINYINT(1) DEFAULT 1");
    }
    $vendorUsers = $pdo->query("SELECT id, username, display_name, role, totp_enabled, last_login, IFNULL(aktivni, 1) as aktivni FROM vendor_users ORDER BY id DESC")->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {}

$flash_ok = $_SESSION['_flash_ok'] ?? null;
$flash_err = $_SESSION['_flash_err'] ?? null;
unset($_SESSION['_flash_ok'], $_SESSION['_flash_err']);

?><!DOCTYPE html>
<html lang="cs">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="robots" content="noindex,nofollow">
<title>🔐 Access Management — APPEK Master</title>
<link rel="stylesheet" href="style.css?v=1.3">
<style>
  .tabs {
    display: flex; gap: 6px; flex-wrap: wrap; margin-bottom: 20px;
    background: #fff; padding: 6px; border-radius: 10px;
    box-shadow: 0 1px 3px rgba(0,0,0,0.05);
  }
  .tab-btn {
    padding: 10px 16px; border-radius: 8px; border: none; cursor: pointer;
    background: none; color: #6e6e73; font-family: inherit; font-size: 13.5px;
    font-weight: 600; text-decoration: none; transition: all 0.15s;
  }
  .tab-btn:hover { background: #f5f5f7; color: #1d1d1f; }
  .tab-btn.active {
    background: linear-gradient(180deg, #BA7517, #854F0B);
    color: #fff;
  }
  .access-table { width: 100%; border-collapse: collapse; font-size: 13.5px; }
  .access-table th, .access-table td {
    padding: 12px 14px; text-align: left; border-bottom: 1px solid #f0f0f3;
  }
  .access-table th {
    background: #fafafa; font-weight: 700; color: #6e6e73;
    text-transform: uppercase; letter-spacing: 0.4px; font-size: 11.5px;
  }
  .access-table tr:hover td { background: #fafafa; }
  .badge {
    display: inline-block; padding: 3px 8px; border-radius: 999px;
    font-size: 11px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.3px;
  }
  .badge.green { background: rgba(52,199,89,0.15); color: #208438; }
  .badge.red { background: rgba(255,59,48,0.15); color: #c62828; }
  .badge.orange { background: rgba(255,149,0,0.15); color: #c66800; }
  .badge.blue { background: rgba(0,122,255,0.15); color: #0058b8; }
  .badge.purple { background: rgba(147,51,234,0.15); color: #6b21a8; }
  .access-actions { display: flex; gap: 6px; }
  .btn-mini {
    padding: 5px 10px; border: 1px solid #e5e5e7; background: #fff;
    border-radius: 6px; cursor: pointer; font-family: inherit;
    font-size: 11.5px; font-weight: 600; color: #424245;
  }
  .btn-mini:hover { background: #f5f5f7; }
  .btn-mini.danger { color: #ff3b30; border-color: rgba(255,59,48,0.3); }
  .btn-mini.danger:hover { background: rgba(255,59,48,0.05); }
  .stat-mini {
    display: grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
    gap: 14px; margin-bottom: 20px;
  }
  .stat-mini .card {
    background: #fff; border-radius: 10px; padding: 16px;
    border: 1px solid #e5e5e7;
  }
  .stat-mini .label {
    font-size: 11px; color: #6e6e73; text-transform: uppercase; letter-spacing: 0.5px;
  }
  .stat-mini .value { font-size: 24px; font-weight: 800; margin-top: 4px; }
</style>
</head>
<body>

<?php vendor_render_topbar($user, $currentPage); ?>
<?php vendor_render_back(); ?>

<main class="page-master">

  <div class="page-header-master">
    <h1>🔐 Access Management</h1>
    <div style="font-size:13px;color:#86868b">Správa všech přístupů napříč subdoménami</div>
  </div>

  <?php if ($flash_ok): ?>
    <div style="background:#d4edda;color:#155724;padding:12px 16px;border-radius:10px;margin-bottom:14px"><?= $flash_ok ?></div>
  <?php endif; ?>
  <?php if ($flash_err): ?>
    <div style="background:#f8d7da;color:#721c24;padding:12px 16px;border-radius:10px;margin-bottom:14px">❌ <?= htmlspecialchars($flash_err) ?></div>
  <?php endif; ?>

  <!-- KPI stats -->
  <div class="stat-mini">
    <div class="card">
      <div class="label">Admin uživatelé</div>
      <div class="value"><?= count($admins) ?></div>
    </div>
    <div class="card">
      <div class="label">B2B uživatelé</div>
      <div class="value"><?= count($b2bUsers) ?></div>
    </div>
    <div class="card">
      <div class="label">Vendor admini</div>
      <div class="value"><?= count($vendorUsers) ?></div>
    </div>
    <div class="card">
      <div class="label">API tokeny</div>
      <div class="value"><?= count($apiTokens) ?></div>
    </div>
    <div class="card">
      <div class="label">Failed pokusy (7 dní)</div>
      <div class="value" style="color:<?= count($failedAttempts) > 0 ? '#ff3b30' : '#34c759' ?>"><?= count($failedAttempts) ?></div>
    </div>
    <div class="card">
      <div class="label">Demo přístupy</div>
      <div class="value"><?= count($demoLog) ?></div>
    </div>
  </div>

  <!-- TABS -->
  <div class="tabs">
    <a href="?tab=admins" class="tab-btn <?= $tab === 'admins' ? 'active' : '' ?>">👤 Admin</a>
    <a href="?tab=b2b" class="tab-btn <?= $tab === 'b2b' ? 'active' : '' ?>">🛍️ B2B uživatelé</a>
    <a href="?tab=vendor" class="tab-btn <?= $tab === 'vendor' ? 'active' : '' ?>">🏢 Vendor admini</a>
    <a href="?tab=tokens" class="tab-btn <?= $tab === 'tokens' ? 'active' : '' ?>">🔑 API tokeny</a>
    <a href="?tab=failed" class="tab-btn <?= $tab === 'failed' ? 'active' : '' ?>">🚨 Failed login (7d)</a>
    <a href="?tab=demo" class="tab-btn <?= $tab === 'demo' ? 'active' : '' ?>">🧪 Demo přístupy</a>
  </div>

  <!-- CONTENT -->
  <div class="panel-master" style="padding:0;overflow:hidden">

    <?php if ($tab === 'admins'): ?>
      <table class="access-table">
        <thead>
          <tr><th>ID</th><th>E-mail</th><th>Jméno</th><th>Role</th><th>Stav</th><th>Poslední login</th><th>Akce</th></tr>
        </thead>
        <tbody>
        <?php foreach ($admins as $a): ?>
          <tr>
            <td>#<?= (int)$a['id'] ?></td>
            <td><strong><?= htmlspecialchars($a['email']) ?></strong></td>
            <td><?= htmlspecialchars($a['jmeno'] ?: '—') ?></td>
            <td><span class="badge <?= $a['role'] === 'admin' ? 'purple' : 'blue' ?>"><?= htmlspecialchars($a['role'] ?: 'admin') ?></span></td>
            <td>
              <?php if ($a['aktivni']): ?>
                <span class="badge green">Aktivní</span>
              <?php else: ?>
                <span class="badge red">Deaktivován</span>
              <?php endif; ?>
            </td>
            <td style="color:#6e6e73"><?= $a['posledni_login'] ? htmlspecialchars(date('d.m.Y H:i', strtotime($a['posledni_login']))) : '—' ?></td>
            <td>
              <form method="POST" style="display:inline">
                <input type="hidden" name="target_type" value="admin">
                <input type="hidden" name="target_id" value="<?= $a['id'] ?>">
                <?php if ($a['aktivni']): ?>
                  <button class="btn-mini danger" name="action" value="deactivate" onclick="return confirm('Deaktivovat?')">Deaktivovat</button>
                <?php else: ?>
                  <button class="btn-mini" name="action" value="activate">Aktivovat</button>
                <?php endif; ?>
                <button class="btn-mini" name="action" value="reset_password" onclick="return confirm('Reset hesla?')">Reset hesla</button>
              </form>
            </td>
          </tr>
        <?php endforeach; ?>
        <?php if (empty($admins)): ?>
          <tr><td colspan="7" style="text-align:center;color:#86868b;padding:40px">Žádní admin uživatelé</td></tr>
        <?php endif; ?>
        </tbody>
      </table>

    <?php elseif ($tab === 'b2b'): ?>
      <table class="access-table">
        <thead>
          <tr><th>ID</th><th>E-mail</th><th>Jméno</th><th>Odběratel</th><th>Stav</th><th>Poslední login</th><th>Akce</th></tr>
        </thead>
        <tbody>
        <?php foreach ($b2bUsers as $b): ?>
          <tr>
            <td>#<?= (int)$b['id'] ?></td>
            <td><strong><?= htmlspecialchars($b['email']) ?></strong></td>
            <td><?= htmlspecialchars($b['jmeno'] ?: '—') ?></td>
            <td><?= $b['odberatel_id'] ? '#' . $b['odberatel_id'] : '—' ?></td>
            <td><?= $b['aktivni'] ? '<span class="badge green">Aktivní</span>' : '<span class="badge red">Deaktivován</span>' ?></td>
            <td style="color:#6e6e73"><?= $b['posledni_login'] ? htmlspecialchars(date('d.m.Y H:i', strtotime($b['posledni_login']))) : '—' ?></td>
            <td>
              <form method="POST" style="display:inline">
                <input type="hidden" name="target_type" value="b2b">
                <input type="hidden" name="target_id" value="<?= $b['id'] ?>">
                <?php if ($b['aktivni']): ?>
                  <button class="btn-mini danger" name="action" value="deactivate" onclick="return confirm('Deaktivovat?')">Deaktivovat</button>
                <?php else: ?>
                  <button class="btn-mini" name="action" value="activate">Aktivovat</button>
                <?php endif; ?>
                <button class="btn-mini" name="action" value="reset_password" onclick="return confirm('Reset hesla?')">Reset</button>
              </form>
            </td>
          </tr>
        <?php endforeach; ?>
        <?php if (empty($b2bUsers)): ?>
          <tr><td colspan="7" style="text-align:center;color:#86868b;padding:40px">Žádní B2B uživatelé</td></tr>
        <?php endif; ?>
        </tbody>
      </table>

    <?php elseif ($tab === 'vendor'): ?>
      <table class="access-table">
        <thead>
          <tr><th>ID</th><th>Username</th><th>Display name</th><th>Role</th><th>2FA</th><th>Stav</th><th>Last login</th><th>Akce</th></tr>
        </thead>
        <tbody>
        <?php foreach ($vendorUsers as $v): ?>
          <tr>
            <td>#<?= (int)$v['id'] ?></td>
            <td><strong><?= htmlspecialchars($v['username']) ?></strong></td>
            <td><?= htmlspecialchars($v['display_name'] ?: '—') ?></td>
            <td><span class="badge purple"><?= htmlspecialchars($v['role'] ?: 'admin') ?></span></td>
            <td><?= $v['totp_enabled'] ? '<span class="badge green">✓ Zapnuto</span>' : '<span class="badge orange">✗ Vypnuto</span>' ?></td>
            <td><?= $v['aktivni'] ? '<span class="badge green">Aktivní</span>' : '<span class="badge red">Deaktivován</span>' ?></td>
            <td style="color:#6e6e73"><?= $v['last_login'] ? htmlspecialchars(date('d.m.Y H:i', strtotime($v['last_login']))) : '—' ?></td>
            <td>
              <form method="POST" style="display:inline">
                <input type="hidden" name="target_type" value="vendor">
                <input type="hidden" name="target_id" value="<?= $v['id'] ?>">
                <?php if ($v['id'] != $user['id']): ?>
                  <?php if ($v['aktivni']): ?>
                    <button class="btn-mini danger" name="action" value="deactivate" onclick="return confirm('Deaktivovat?')">Deaktivovat</button>
                  <?php else: ?>
                    <button class="btn-mini" name="action" value="activate">Aktivovat</button>
                  <?php endif; ?>
                <?php else: ?>
                  <span style="font-size:11px;color:#86868b">(ty)</span>
                <?php endif; ?>
              </form>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>

    <?php elseif ($tab === 'tokens'): ?>
      <table class="access-table">
        <thead>
          <tr><th>ID</th><th>Název</th><th>Typ</th><th>Vytvořeno</th><th>Poslední použití</th><th>Stav</th></tr>
        </thead>
        <tbody>
        <?php foreach ($apiTokens as $t): ?>
          <tr>
            <td>#<?= (int)$t['id'] ?></td>
            <td><strong><?= htmlspecialchars($t['nazev']) ?></strong></td>
            <td><span class="badge blue"><?= htmlspecialchars($t['typ'] ?: 'api') ?></span></td>
            <td><?= htmlspecialchars(date('d.m.Y', strtotime($t['vytvoreno']))) ?></td>
            <td style="color:#6e6e73"><?= $t['posledni_pouziti'] ? htmlspecialchars(date('d.m.Y H:i', strtotime($t['posledni_pouziti']))) : '<span class="badge orange">Nepoužitý</span>' ?></td>
            <td><?= $t['aktivni'] ? '<span class="badge green">Aktivní</span>' : '<span class="badge red">Revokován</span>' ?></td>
          </tr>
        <?php endforeach; ?>
        <?php if (empty($apiTokens)): ?>
          <tr><td colspan="6" style="text-align:center;color:#86868b;padding:40px">Žádné API tokeny</td></tr>
        <?php endif; ?>
        </tbody>
      </table>

    <?php elseif ($tab === 'failed'): ?>
      <table class="access-table">
        <thead>
          <tr><th>E-mail</th><th>IP</th><th>Typ</th><th>Počet pokusů</th><th>Poslední pokus</th></tr>
        </thead>
        <tbody>
        <?php foreach ($failedAttempts as $f): ?>
          <tr>
            <td><strong><?= htmlspecialchars($f['email'] ?: '(prázdné)') ?></strong></td>
            <td><code style="font-family:'SF Mono',monospace;font-size:12px"><?= htmlspecialchars($f['ip']) ?></code></td>
            <td><span class="badge blue"><?= htmlspecialchars($f['typ']) ?></span></td>
            <td><strong style="color:<?= $f['pocet'] >= 5 ? '#ff3b30' : '#ff9500' ?>"><?= $f['pocet'] ?>×</strong></td>
            <td style="color:#6e6e73"><?= htmlspecialchars(date('d.m.Y H:i', strtotime($f['posledni']))) ?></td>
          </tr>
        <?php endforeach; ?>
        <?php if (empty($failedAttempts)): ?>
          <tr><td colspan="5" style="text-align:center;color:#86868b;padding:40px">✅ Žádné neúspěšné pokusy za posledních 7 dní</td></tr>
        <?php endif; ?>
        </tbody>
      </table>

    <?php elseif ($tab === 'demo'): ?>
      <table class="access-table">
        <thead>
          <tr><th>Kdy</th><th>IP</th><th>Akce</th><th>User Agent</th></tr>
        </thead>
        <tbody>
        <?php foreach ($demoLog as $d): ?>
          <tr>
            <td style="color:#6e6e73;white-space:nowrap"><?= htmlspecialchars(date('d.m.Y H:i:s', strtotime($d['cas']))) ?></td>
            <td><code style="font-family:'SF Mono',monospace;font-size:12px"><?= htmlspecialchars($d['ip']) ?></code></td>
            <td><span class="badge blue"><?= htmlspecialchars($d['akce']) ?></span></td>
            <td style="font-size:11.5px;color:#6e6e73;max-width:400px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap" title="<?= htmlspecialchars($d['user_agent']) ?>">
              <?= htmlspecialchars(substr($d['user_agent'], 0, 80)) ?>
            </td>
          </tr>
        <?php endforeach; ?>
        <?php if (empty($demoLog)): ?>
          <tr><td colspan="4" style="text-align:center;color:#86868b;padding:40px">Žádné demo přístupy zatím</td></tr>
        <?php endif; ?>
        </tbody>
      </table>
    <?php endif; ?>

  </div>

</main>

<?php vendor_render_footer(); ?>
</body>
</html>
