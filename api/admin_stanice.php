<?php
/**
 * 📱 STANICE / ZAŘÍZENÍ — evidence připojených zařízení + živý přehled online.
 *
 * Každé zařízení (kasa, kuchyň, admin) posílá periodicky heartbeat (ping) se svým
 * trvalým tokenem z localStorage → server si pamatuje last_seen. „Online" = viděno
 * v posledních N sekundách.
 *
 *   POST ?action=ping    {token, role, nazev}  → upsert last_seen  (smí i POS PIN účet)
 *   GET  (?action=list)                        → seznam stanic + online flag (jen admin)
 *   POST ?action=rename  {id, nazev}           → přejmenovat (jen admin)
 *   POST ?action=delete  {id}                  → zapomenout zařízení (jen admin)
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/_admin_auth.php';

cors_headers();
require_admin();  // POS PIN účet je povolen (endpoint v allowlistu _admin_auth.php) — gating níže
header('Content-Type: application/json; charset=UTF-8');

$pdo = db();

// Lazy-create (fresh install nemá tabulku ve _full_schema — vytvoř at-use, viz [[appek-fresh-install-schema]])
$pdo->exec("CREATE TABLE IF NOT EXISTS stanice (
  id INT AUTO_INCREMENT PRIMARY KEY,
  token VARCHAR(64) NOT NULL UNIQUE,
  nazev VARCHAR(120) DEFAULT NULL,
  role VARCHAR(30) DEFAULT NULL,
  ip VARCHAR(45) DEFAULT NULL,
  user_agent VARCHAR(255) DEFAULT NULL,
  watch TINYINT(1) NOT NULL DEFAULT 0,
  printer_id INT NULL DEFAULT NULL,
  pokladna VARCHAR(40) NULL DEFAULT NULL,
  cmd VARCHAR(20) NULL DEFAULT NULL,
  home VARCHAR(30) NULL DEFAULT NULL,
  approved TINYINT(1) NULL DEFAULT NULL,
  first_seen DATETIME DEFAULT CURRENT_TIMESTAMP,
  last_seen DATETIME DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

// idempotentní přidání sloupců pro starší instalace (tabulka už mohla vzniknout dřív)
foreach (['watch' => 'TINYINT(1) NOT NULL DEFAULT 0', 'printer_id' => 'INT NULL DEFAULT NULL', 'pokladna' => 'VARCHAR(40) NULL DEFAULT NULL', 'cmd' => 'VARCHAR(20) NULL DEFAULT NULL', 'home' => 'VARCHAR(30) NULL DEFAULT NULL', 'approved' => 'TINYINT(1) NULL DEFAULT NULL'] as $col => $def) {
    try {
        if (!$pdo->query("SHOW COLUMNS FROM stanice LIKE " . $pdo->quote($col))->fetchColumn()) {
            $pdo->exec("ALTER TABLE stanice ADD COLUMN $col $def");
        }
    } catch (Throwable $e) { /* ignore */ }
}

$action = $_GET['action'] ?? '';
$isPos  = !empty($_SESSION['pos_only_user']);

function _stanice_body(): array {
    $raw = file_get_contents('php://input');
    $j = json_decode((string) $raw, true);
    return is_array($j) ? $j : [];
}

// ─── PING — heartbeat z libovolného přihlášeného zařízení (vč. POS PINu) ─────
if ($action === 'ping') {
    $in    = _stanice_body();
    $token = preg_replace('/[^a-zA-Z0-9]/', '', (string) ($in['token'] ?? ''));
    if (strlen($token) < 8 || strlen($token) > 64) {
        json_error('Neplatný token zařízení', 400);
    }
    $role  = substr(preg_replace('/[^a-z_]/', '', strtolower((string) ($in['role'] ?? ''))), 0, 30);
    $nazev = trim((string) ($in['nazev'] ?? ''));
    if (mb_strlen($nazev) > 120) $nazev = mb_substr($nazev, 0, 120);
    $ip = (string) ($_SERVER['REMOTE_ADDR'] ?? '');
    $ua = substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255);

    // Upsert; jméno zadané adminem (ruční přejmenování) se pingem NEPŘEPÍŠE (COALESCE/NULLIF).
    $st = $pdo->prepare("INSERT INTO stanice (token, nazev, role, ip, user_agent, first_seen, last_seen)
        VALUES (:t, :n, :r, :ip, :ua, NOW(), NOW())
        ON DUPLICATE KEY UPDATE
          last_seen  = NOW(),
          ip         = VALUES(ip),
          user_agent = VALUES(user_agent),
          role       = VALUES(role),
          nazev      = COALESCE(NULLIF(nazev, ''), VALUES(nazev))");
    $st->execute([
        ':t' => $token, ':n' => ($nazev !== '' ? $nazev : null),
        ':r' => ($role !== '' ? $role : null), ':ip' => $ip, ':ua' => $ua,
    ]);
    // Doruč tomuto zařízení případný příkaz (reload, one-shot) + výchozí obrazovku (home)
    $cmd = null; $home = null;
    try {
        $q = $pdo->prepare("SELECT cmd, home FROM stanice WHERE token = :t LIMIT 1");
        $q->execute([':t' => $token]);
        $rd = $q->fetch(PDO::FETCH_ASSOC) ?: [];
        $cmd  = (($rd['cmd'] ?? '') !== '') ? $rd['cmd'] : null;
        $home = (($rd['home'] ?? '') !== '') ? $rd['home'] : null;
        if ($cmd !== null) {
            $pdo->prepare("UPDATE stanice SET cmd = NULL WHERE token = :t")->execute([':t' => $token]); // one-shot
        }
    } catch (Throwable $e) { /* sloupce nemusí existovat na staré instalaci */ }
    json_response(['ok' => true, 'cmd' => $cmd, 'home' => $home]);
}

// ─── Od tud dál jen plný admin (POS PIN účet nesmí spravovat stanice) ────────
if ($isPos) {
    json_error('POS účet nemá přístup ke správě stanic', 403);
}

// ─── LIST — přehled stanic + online flag ─────────────────────────────────────
if ($action === '' || $action === 'list') {
    $threshold = 90; // s — do kolika sekund od heartbeatu je zařízení „online"
    $rows = $pdo->query("SELECT id, nazev, role, ip, user_agent, watch, printer_id, pokladna, home, approved, first_seen, last_seen,
        TIMESTAMPDIFF(SECOND, last_seen, NOW()) AS sec_ago
        FROM stanice ORDER BY (TIMESTAMPDIFF(SECOND, last_seen, NOW()) <= $threshold) DESC, last_seen DESC
    ")->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rows as &$r) {
        $r['sec_ago']    = (int) $r['sec_ago'];
        $r['online']     = ($r['sec_ago'] <= $threshold);
        $r['watch']      = ((int) $r['watch'] === 1);
        $r['printer_id'] = ($r['printer_id'] !== null) ? (int) $r['printer_id'] : null;
        $r['approved']   = ($r['approved'] === null) ? null : (int) $r['approved']; // null=čeká, 1=schváleno, 0=blokováno
    }
    unset($r);
    // Dostupné tiskárny pro přiřazení (tabulka nemusí existovat, když balíček/tisk není zapnutý)
    $printers = [];
    try {
        $printers = $pdo->query("SELECT id, nazev, typ FROM restaurant_printers WHERE aktivni = 1 ORDER BY typ, nazev")
            ->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) { /* bez tiskáren */ }
    // Globální přepínač allowlistu (opt-in): vyžadovat schválení zařízení pro POS prodej
    $allowlist = false;
    try { $allowlist = (string) nastaveni_get($pdo, 'stanice_allowlist', '0') === '1'; } catch (Throwable $e) {}
    json_response(['stanice' => $rows, 'online_threshold' => $threshold, 'printers' => $printers, 'allowlist' => $allowlist]);
}

// ─── RENAME ──────────────────────────────────────────────────────────────────
if ($action === 'rename') {
    $in = _stanice_body();
    $id = (int) ($in['id'] ?? 0);
    $nazev = trim((string) ($in['nazev'] ?? ''));
    if (mb_strlen($nazev) > 120) $nazev = mb_substr($nazev, 0, 120);
    if ($id <= 0) json_error('Chybí id', 400);
    $pdo->prepare("UPDATE stanice SET nazev = :n WHERE id = :id")
        ->execute([':n' => ($nazev !== '' ? $nazev : null), ':id' => $id]);
    json_response(['ok' => true]);
}

// ─── WATCH — hlídat/nehlídat (alarm při offline) ─────────────────────────────
if ($action === 'watch') {
    $in = _stanice_body();
    $id = (int) ($in['id'] ?? 0);
    $w  = !empty($in['watch']) ? 1 : 0;
    if ($id <= 0) json_error('Chybí id', 400);
    $pdo->prepare("UPDATE stanice SET watch = :w WHERE id = :id")->execute([':w' => $w, ':id' => $id]);
    json_response(['ok' => true, 'watch' => (bool) $w]);
}

// ─── SET_PRINTER — přiřaď tiskárnu zařízení (účtenka půjde na ni) ─────────────
if ($action === 'set_printer') {
    $in  = _stanice_body();
    $id  = (int) ($in['id'] ?? 0);
    $pid = (isset($in['printer_id']) && $in['printer_id'] !== '' && $in['printer_id'] !== null)
        ? (int) $in['printer_id'] : null;
    if ($id <= 0) json_error('Chybí id', 400);
    $pdo->prepare("UPDATE stanice SET printer_id = :p WHERE id = :id")->execute([':p' => $pid, ':id' => $id]);
    json_response(['ok' => true, 'printer_id' => $pid]);
}

// ─── SET_POKLADNA — přiřaď pokladnu (kasu) zařízení → uzávěrka/tržby per kasa ─
if ($action === 'set_pokladna') {
    $in = _stanice_body();
    $id = (int) ($in['id'] ?? 0);
    $pk = trim((string) ($in['pokladna'] ?? ''));
    if (mb_strlen($pk) > 40) $pk = mb_substr($pk, 0, 40);
    if ($id <= 0) json_error('Chybí id', 400);
    $pdo->prepare("UPDATE stanice SET pokladna = :p WHERE id = :id")
        ->execute([':p' => ($pk !== '' ? $pk : null), ':id' => $id]);
    json_response(['ok' => true, 'pokladna' => ($pk !== '' ? $pk : null)]);
}

// ─── CMD — pošli zařízení příkaz (reload); doručí se při dalším heartbeatu ────
if ($action === 'cmd') {
    $in = _stanice_body();
    $id = (int) ($in['id'] ?? 0);
    $c  = preg_replace('/[^a-z_]/', '', strtolower((string) ($in['cmd'] ?? '')));
    if ($id <= 0) json_error('Chybí id', 400);
    if (!in_array($c, ['reload'], true)) json_error('Neznámý příkaz', 400);
    $pdo->prepare("UPDATE stanice SET cmd = :c WHERE id = :id")->execute([':c' => $c, ':id' => $id]);
    json_response(['ok' => true, 'cmd' => $c]);
}

// ─── SET_HOME — výchozí obrazovka po startu appky na tomto zařízení ──────────
if ($action === 'set_home') {
    $in = _stanice_body();
    $id = (int) ($in['id'] ?? 0);
    $h  = substr(preg_replace('/[^a-z_]/', '', strtolower((string) ($in['home'] ?? ''))), 0, 30);
    if ($id <= 0) json_error('Chybí id', 400);
    $pdo->prepare("UPDATE stanice SET home = :h WHERE id = :id")
        ->execute([':h' => ($h !== '' ? $h : null), ':id' => $id]);
    json_response(['ok' => true, 'home' => ($h !== '' ? $h : null)]);
}

// ─── APPROVE / BLOCK — allowlist zařízení (schválit prodej / zablokovat) ──────
if ($action === 'approve' || $action === 'block') {
    $in = _stanice_body();
    $id = (int) ($in['id'] ?? 0);
    if ($id <= 0) json_error('Chybí id', 400);
    $val = ($action === 'approve') ? 1 : 0;
    $pdo->prepare("UPDATE stanice SET approved = :a WHERE id = :id")->execute([':a' => $val, ':id' => $id]);
    json_response(['ok' => true, 'approved' => $val]);
}

// ─── SET_ALLOWLIST — globální přepínač (vyžadovat schválení pro POS prodej) ───
if ($action === 'set_allowlist') {
    $in = _stanice_body();
    $on = !empty($in['on']) ? '1' : '0';
    $pdo->prepare("INSERT INTO nastaveni (klic, hodnota, popis) VALUES ('stanice_allowlist', :v1, 'Vyžadovat schválení zařízení pro POS prodej')
        ON DUPLICATE KEY UPDATE hodnota = :v2")->execute([':v1' => $on, ':v2' => $on]);
    json_response(['ok' => true, 'allowlist' => ($on === '1')]);
}

// ─── DELETE (zapomenout) ─────────────────────────────────────────────────────
if ($action === 'delete') {
    $in = _stanice_body();
    $id = (int) ($in['id'] ?? 0);
    if ($id <= 0) json_error('Chybí id', 400);
    $pdo->prepare("DELETE FROM stanice WHERE id = :id")->execute([':id' => $id]);
    json_response(['ok' => true]);
}

json_error('Neznámá akce', 400);
