# APPEK mobil — Fáze 1A: vendor resolve endpoint (e-mail → instalace) — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Vendor umí podle e-mailu vrátit URL zákazníkovy APPEK instalace — backend pro centrální login mobilní appky (Fáze 1). Bez Macu, testovatelné curlem.

**Architecture:** Install v heartbeatu posílá VŠECHNY admin e-maily → vendor je uloží do nové tabulky `vendor_install_emails` (e-mail → install_url) → nový public endpoint `vendor/resolve.php` vrátí instalaci(e) dle e-mailu (rate-limited).

**Tech Stack:** PHP 8 + PDO/MariaDB. Vendor bootstrap `vendor/_lib.php` (`vendor_db()`). Testy = curl proti vendor.appek.cz po deployi + spuštění demo heartbeatu (codebase nemá unit framework; ověřujeme integračně jako smoke.sh).

---

## File Structure
- **Create:** `vendor/resolve.php` — public POST {email} → {installs:[{url,nazev}]}; vlastní tabulky (lazy migrace) + rate-limit.
- **Modify:** `vendor/heartbeat.php` — v legit větvi upsert každého admin e-mailu → `vendor_install_emails`.
- **Modify:** `api/license_heartbeat.php` — do payloadu přidat `admin_emails` (všechny `admin_users.email`).

Vše se nasazuje normálním bundlem (vendor/ i api/ jsou v něm). Ověření po deployi na demu.

---

### Task 1: `vendor/resolve.php` + tabulky + rate-limit

**Files:**
- Create: `vendor/resolve.php`

- [ ] **Step 1: Vytvoř `vendor/resolve.php`**

```php
<?php
/**
 * 🆕 vendor/resolve.php — public endpoint pro CENTRÁLNÍ LOGIN mobilní appky (Fáze 1).
 * POST {email} → najde instalaci(e) dle e-mailu (z vendor_install_emails, plněno heartbeatem).
 * Rate-limited (per IP). Vrací JEN url+název instalace — nic citlivého, žádné ověření hesla.
 */
require_once __DIR__ . '/_lib.php';
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');          // appka (Capacitor origin) — veřejný directory lookup
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') { http_response_code(204); exit; }
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') { http_response_code(405); echo json_encode(['ok'=>false,'error'=>'POST only']); exit; }

$pdo = vendor_db();
resolve_ensure_tables($pdo);

$ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
if (resolve_rate_limited($pdo, $ip)) { http_response_code(429); echo json_encode(['ok'=>false,'error'=>'Příliš mnoho pokusů — zkus za chvíli']); exit; }

$d = json_decode(file_get_contents('php://input'), true) ?: [];
$email = strtolower(trim((string)($d['email'] ?? '')));
if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) { http_response_code(400); echo json_encode(['ok'=>false,'error'=>'Neplatný e-mail']); exit; }

$st = $pdo->prepare("SELECT install_url, nazev FROM vendor_install_emails WHERE email = :e ORDER BY updated_at DESC LIMIT 5");
$st->execute(['e' => $email]);
$installs = array_map(fn($r) => ['url' => $r['install_url'], 'nazev' => $r['nazev'] ?: ''], $st->fetchAll(PDO::FETCH_ASSOC));
echo json_encode(['ok' => true, 'installs' => $installs], JSON_UNESCAPED_UNICODE);

function resolve_ensure_tables(PDO $pdo): void {
    $pdo->exec("CREATE TABLE IF NOT EXISTS vendor_install_emails (
        email VARCHAR(190) NOT NULL,
        install_url VARCHAR(255) NOT NULL,
        license_id INT NULL,
        nazev VARCHAR(190) NULL,
        updated_at DATETIME NOT NULL,
        PRIMARY KEY (email, install_url),
        INDEX idx_email (email)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $pdo->exec("CREATE TABLE IF NOT EXISTS vendor_resolve_hits (
        id INT AUTO_INCREMENT PRIMARY KEY, ip VARCHAR(64) NOT NULL, ts DATETIME NOT NULL, INDEX idx_ip_ts (ip, ts)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
}
function resolve_rate_limited(PDO $pdo, string $ip): bool {
    $pdo->prepare("INSERT INTO vendor_resolve_hits (ip, ts) VALUES (:ip, NOW())")->execute(['ip' => $ip]);
    try { $pdo->exec("DELETE FROM vendor_resolve_hits WHERE ts < DATE_SUB(NOW(), INTERVAL 1 HOUR)"); } catch (Throwable $e) {}
    $c = $pdo->prepare("SELECT COUNT(*) FROM vendor_resolve_hits WHERE ip = :ip AND ts > DATE_SUB(NOW(), INTERVAL 10 MINUTE)");
    $c->execute(['ip' => $ip]);
    return ((int) $c->fetchColumn()) > 20;   // > 20 / 10 min / IP
}
```

- [ ] **Step 2: php -l**

Run: `php -l vendor/resolve.php`
Expected: `No syntax errors detected`

- [ ] **Step 3: Commit**

```bash
git add vendor/resolve.php
git commit -m "feat(vendor): resolve.php — email→install lookup (mobil centrální login) + rate-limit"
```

*(Funkční ověření je v Tasku 4 po deployi — vendor běží jen na vendor.appek.cz s vendor DB.)*

---

### Task 2: heartbeat ukládá admin e-maily → `vendor_install_emails`

**Files:**
- Modify: `vendor/heartbeat.php` (legit větev, za insertem do `vendor_license_heartbeats`, ~ř. 248-262)

- [ ] **Step 1: Přidej upsert e-mailů do legit větve**

Najdi v `vendor/heartbeat.php` konec legit větve (po `INSERT INTO vendor_license_heartbeats ... ->execute([...])`). Hned za něj vlož:

```php
    // 🆕 Fáze 1A — ulož admin e-maily → instalace (pro vendor/resolve.php = centrální login mobilní appky)
    $emails = $data['admin_emails'] ?? [];
    if (!is_array($emails)) $emails = [];
    if ($adminEmail !== '' && !in_array($adminEmail, $emails, true)) $emails[] = $adminEmail; // back-compat: i jediný admin_email
    $nazevInst = preg_replace('/^https?:\/\//', '', (string) $installUrl);
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS vendor_install_emails (
            email VARCHAR(190) NOT NULL, install_url VARCHAR(255) NOT NULL, license_id INT NULL,
            nazev VARCHAR(190) NULL, updated_at DATETIME NOT NULL,
            PRIMARY KEY (email, install_url), INDEX idx_email (email)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $ins = $pdo->prepare("INSERT INTO vendor_install_emails (email, install_url, license_id, nazev, updated_at)
            VALUES (:e, :u, :lid, :n, NOW())
            ON DUPLICATE KEY UPDATE license_id = VALUES(license_id), nazev = VALUES(nazev), updated_at = NOW()");
        foreach ($emails as $em) {
            $em = strtolower(trim((string) $em));
            if ($em === '' || !filter_var($em, FILTER_VALIDATE_EMAIL)) continue;
            $ins->execute(['e' => $em, 'u' => $installUrl, 'lid' => $licenseRow['id'], 'n' => $nazevInst]);
        }
    } catch (Throwable $e) { /* heartbeat nesmí spadnout kvůli tomuhle */ }
```

*(Pozn.: `$adminEmail`, `$installUrl`, `$licenseRow`, `$pdo` v této větvi existují — viz ř. 64/56/94/79.)*

- [ ] **Step 2: php -l**

Run: `php -l vendor/heartbeat.php`
Expected: `No syntax errors detected`

- [ ] **Step 3: Commit**

```bash
git add vendor/heartbeat.php
git commit -m "feat(vendor): heartbeat ukládá admin e-maily do vendor_install_emails (resolve)"
```

---

### Task 3: install posílá VŠECHNY admin e-maily v heartbeatu

**Files:**
- Modify: `api/license_heartbeat.php` (payload, ~ř. 63-75)

- [ ] **Step 1: Přidej `admin_emails` do payloadu**

Najdi v `api/license_heartbeat.php` řádek `'admin_email' => $ctx['email'] ?? '',` a HNED ZA něj vlož:

```php
        'admin_emails'       => (function () {
            // 🆕 Fáze 1A — všechny admin e-maily (pro centrální login mobilní appky / vendor resolve)
            try {
                $rows = db()->query("SELECT email FROM admin_users WHERE email IS NOT NULL AND email != ''")->fetchAll(PDO::FETCH_COLUMN);
                return array_values(array_unique(array_map('strtolower', array_map('trim', $rows))));
            } catch (Throwable $e) { return []; }
        })(),
```

- [ ] **Step 2: php -l**

Run: `php -l api/license_heartbeat.php`
Expected: `No syntax errors detected`

- [ ] **Step 3: Commit**

```bash
git add api/license_heartbeat.php
git commit -m "feat: heartbeat posílá všechny admin e-maily (mobil centrální login)"
```

---

### Task 4: E2E ověření (deploy → demo heartbeat → resolve)

- [ ] **Step 1: Build + deploy** (postup [[appek-deploy-distribution]])

```bash
export PATH="$PATH:/usr/local/bin:/Applications/XAMPP/bin"
bash scripts/build-update.sh 3.0.373
git add -A && git commit -m "v3.0.373 — mobil Fáze 1A: vendor resolve + heartbeat admin e-maily" && git tag v3.0.373 && git push origin main --tags
# počkej na feed 3.0.373 (případně re-push tagu když flakne); demo se zaktualizuje
```

- [ ] **Step 2: Spusť heartbeat na demu** (naplní vendor_install_emails)

V přihlášeném demo adminu (Chrome MCP tab demo) přes JS:
```js
await api('license_heartbeat.php', { method: 'POST' });
```
Expected: vrátí ok (heartbeat odeslán na vendor).

- [ ] **Step 3: Ověř resolve na vendoru**

Run (nahraď e-mailem demo admina, např. demo@appek.cz):
```bash
curl -s -m 10 -X POST https://vendor.appek.cz/resolve.php -H 'Content-Type: application/json' --data '{"email":"demo@appek.cz"}'
```
Expected: `{"ok":true,"installs":[{"url":"https://demo.appek.cz","nazev":"demo.appek.cz"}]}`

- [ ] **Step 4: Ověř guardy**

```bash
curl -s -o /dev/null -w "%{http_code}\n" -X POST https://vendor.appek.cz/resolve.php -H 'Content-Type: application/json' --data '{"email":"xx"}'   # → 400
curl -s -X POST https://vendor.appek.cz/resolve.php -H 'Content-Type: application/json' --data '{"email":"nikdo@nikde.cz"}'   # → {"ok":true,"installs":[]}
```
Expected: 400 pro neplatný e-mail; prázdné installs pro neznámý.

- [ ] **Step 5: Commit (pokud byly úpravy z ověření)** — jinak hotovo.

---

## Self-Review
- **Spec coverage:** „Vendor resolve endpoint (e-mail→instalace, naplněný z heartbeatu)" = Tasky 1-3; E2E = Task 4. ✓ GDPR (centrální evidence e-mailů) — řešeno minimem dat (jen email+url+název), bez hesel; plnou GDPR cestu řeší samostatně.
- **Placeholdery:** žádné — exact kód v každém kroku.
- **Type/consistency:** tabulka `vendor_install_emails` (email, install_url, license_id, nazev, updated_at) shodná v resolve.php (Task 1) i heartbeat.php (Task 2). Payload klíč `admin_emails` shodný v install (Task 3) i vendoru (Task 2).
- **Riziko:** resolve.php je PUBLIC (jako heartbeat) → rate-limit + jen nesensitivní data; ověř, že `_lib.php` nepožaduje admin session (heartbeat.php je taky public a používá `_lib.php`).

## Závislosti / pozn.
- Vendor běží jen na vendor.appek.cz (vlastní DB) → funkční test až po deployi (Task 4), ne lokálně.
- Po 1A následuje **Fáze 1B** (Capacitor appka: login obrazovka volá `resolve.php` → `admin_login.php` → webview) — vlastní plán.
