<?php
/**
 * 🎟️ VOUCHERY / DÁRKOVÉ KARTY / SLEVOVÉ VOUCHERY (v3.0.281, rozšířeno v3.0.282).
 *
 *   typ = 'voucher'       — poukaz s Kč hodnotou (částečné uplatnění ze zůstatku)
 *   typ = 'darkova_karta' — dárková/věrnostní karta s Kč hodnotou (lze dobít = topup)
 *   typ = 'sleva'         — % slevový voucher (sleva_pct, volitelně sleva_max_kc), JEDNORÁZOVÝ;
 *                           uplatnění dopočítá slevu z částky objednávky a označí 'vycerpany'
 *
 * Voucher lze vystavit pro konkrétního odběratele (odberatel_id) a poslat mu ho emailem
 * (send_email / action=send_email). Email = odberatele.email (fallback login_email).
 *
 * GET  /api/admin_vouchers.php                  → { vouchery:[...], souhrn:{...} }
 * GET  /api/admin_vouchers.php?kod=XXXX         → detail jednoho (pro POS lookup před uplatněním)
 * POST ?action=create   { typ?, hodnota | sleva_pct(+sleva_max_kc?), pocet?, platnost_do?, poznamka?,
 *                         odberatel_id?, send_email? } → vygeneruje kódy (+ volitelně email)
 * POST ?action=redeem   { kod, castka, doklad? } → Kč: min(castka,zustatek); %: castka*pct/100 (cap)
 * POST ?action=topup    { id|kod, castka }       → dobije zůstatek (jen Kč karta, ne % sleva)
 * POST ?action=deactivate { id }                 → stav=zruseny
 * POST ?action=send_email { id }                 → (znovu) odešle voucher emailem odběrateli
 *
 * Konzistence: redeem běží v transakci se SELECT ... FOR UPDATE (zámek řádku) → nelze
 * uplatnit stejný voucher 2× souběžně (double-spend). Každý pohyb se loguje do voucher_pohyby.
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/_admin_auth.php';
cors_headers();
require_admin();
header('Content-Type: application/json; charset=UTF-8');

$pdo = db();

function vouchery_ensure_schema(PDO $pdo): void {
    static $done = false;
    if ($done) return;
    $done = true;
    try {
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS vouchery (
                id INT AUTO_INCREMENT PRIMARY KEY,
                kod VARCHAR(40) NOT NULL UNIQUE,
                typ VARCHAR(20) NOT NULL DEFAULT 'voucher',
                hodnota DECIMAL(10,2) NOT NULL DEFAULT 0,
                zustatek DECIMAL(10,2) NOT NULL DEFAULT 0,
                platnost_do DATE NULL,
                stav VARCHAR(20) NOT NULL DEFAULT 'aktivni',
                poznamka VARCHAR(255) NULL,
                vytvoreno DATETIME DEFAULT CURRENT_TIMESTAMP,
                posledni_pouziti DATETIME NULL,
                INDEX idx_kod (kod), INDEX idx_stav (stav)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS voucher_pohyby (
                id INT AUTO_INCREMENT PRIMARY KEY,
                voucher_id INT NOT NULL,
                typ VARCHAR(20) NOT NULL,         -- 'uplatneni' | 'dobiti' | 'vytvoreni'
                castka DECIMAL(10,2) NOT NULL,    -- + dobití / vytvoření, − uplatnění
                zustatek_po DECIMAL(10,2) NOT NULL,
                doklad VARCHAR(40) NULL,
                kdo VARCHAR(120) NULL,
                kdy DATETIME DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_v (voucher_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
        // v3.0.282 — voucher pro konkrétního odběratele + procentuální sleva (typ='sleva')
        foreach ([
            "ALTER TABLE vouchery ADD COLUMN IF NOT EXISTS odberatel_id INT NULL",
            "ALTER TABLE vouchery ADD COLUMN IF NOT EXISTS odberatel_nazev VARCHAR(255) NULL",
            "ALTER TABLE vouchery ADD COLUMN IF NOT EXISTS odberatel_email VARCHAR(150) NULL",
            "ALTER TABLE vouchery ADD COLUMN IF NOT EXISTS sleva_pct DECIMAL(5,2) NULL",
            "ALTER TABLE vouchery ADD COLUMN IF NOT EXISTS sleva_max_kc DECIMAL(10,2) NULL",
            "ALTER TABLE vouchery ADD COLUMN IF NOT EXISTS odeslano_email TINYINT NOT NULL DEFAULT 0",
            "ALTER TABLE vouchery ADD COLUMN IF NOT EXISTS odeslano_kdy DATETIME NULL",
        ] as $alter) { try { $pdo->exec($alter); } catch (Throwable $e) {} }
    } catch (Throwable $e) { error_log('vouchery_ensure_schema: ' . $e->getMessage()); }
}
vouchery_ensure_schema($pdo);

// Bezpečná abeceda — bez matoucích znaků (0/O, 1/I/L)
function voucher_gen_kod(string $prefix = 'DK'): string {
    $chars = 'ABCDEFGHJKMNPQRSTUVWXYZ23456789';
    $part = function () use ($chars) {
        $s = '';
        for ($i = 0; $i < 4; $i++) $s .= $chars[random_int(0, strlen($chars) - 1)];
        return $s;
    };
    return $prefix . '-' . $part() . '-' . $part() . '-' . $part();
}

/** Aktualizuj stav podle zůstatku/platnosti (nemutuje DB, jen vrací). */
function voucher_stav_runtime(array $v): string {
    if ($v['stav'] === 'zruseny') return 'zruseny';
    if ($v['stav'] === 'vycerpany') return 'vycerpany';
    if (!empty($v['platnost_do']) && $v['platnost_do'] < date('Y-m-d')) return 'expirovany';
    // % sleva = jednorázová: aktivní dokud ji redeem nenastaví na 'vycerpany' (nemá Kč zůstatek)
    if (($v['typ'] ?? '') === 'sleva') return 'aktivni';
    if ((float) $v['zustatek'] <= 0.001) return 'vycerpany';
    return 'aktivni';
}

/** Hodnota voucheru jako čitelný text (Kč nebo %), pro email i UI. */
function voucher_hodnota_text(array $v): string {
    if (($v['typ'] ?? '') === 'sleva') {
        $pct = rtrim(rtrim(number_format((float) ($v['sleva_pct'] ?? 0), 2, ',', ' '), '0'), ',');
        $s = $pct . ' %';
        if (!empty($v['sleva_max_kc'])) $s .= ' (max ' . number_format((float) $v['sleva_max_kc'], 0, ',', ' ') . ' Kč)';
        return $s;
    }
    return number_format((float) ($v['hodnota'] ?? 0), 2, ',', ' ') . ' Kč';
}

/** HTML tělo voucher emailu (samostatné, brandované firmou). */
function voucher_email_html(string $firma, string $kod, string $hodnota, string $platnost, string $typ, string $pozn): string {
    $nadpis = $typ === 'sleva' ? 'Slevový voucher' : ($typ === 'darkova_karta' ? 'Dárková karta' : 'Dárkový voucher');
    $e = fn($s) => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
    $poznHtml = $pozn !== '' ? '<p style="margin:10px 0 0;color:#6b7280;font-size:14px">' . $e($pozn) . '</p>' : '';
    return '<!DOCTYPE html><html lang="cs"><body style="margin:0;background:#f3f4f6;font-family:-apple-system,Segoe UI,Roboto,Arial,sans-serif">'
        . '<div style="max-width:520px;margin:0 auto;padding:28px 18px">'
        . '<div style="background:#fff;border-radius:16px;overflow:hidden;box-shadow:0 4px 18px rgba(0,0,0,.08)">'
        . '<div style="background:linear-gradient(135deg,#16a34a,#059669);padding:26px 28px;color:#fff">'
        . '<div style="font-size:13px;opacity:.85;letter-spacing:.5px;text-transform:uppercase">' . $e($firma) . '</div>'
        . '<div style="font-size:24px;font-weight:700;margin-top:4px">🎟️ ' . $e($nadpis) . '</div></div>'
        . '<div style="padding:28px">'
        . '<p style="margin:0 0 18px;color:#374151;font-size:15px">Dobrý den, posíláme Vám voucher k uplatnění při nákupu:</p>'
        . '<div style="border:2px dashed #16a34a;border-radius:12px;padding:18px;text-align:center;background:#f0fdf4">'
        . '<div style="font-size:12px;color:#059669;text-transform:uppercase;letter-spacing:1px">Kód voucheru</div>'
        . '<div style="font-size:26px;font-weight:800;letter-spacing:2px;color:#065f46;margin:6px 0;font-family:monospace">' . $e($kod) . '</div>'
        . '<div style="font-size:20px;font-weight:700;color:#16a34a">' . $e($hodnota) . '</div></div>'
        . '<p style="margin:16px 0 0;color:#6b7280;font-size:14px">📅 ' . $e($platnost) . '</p>'
        . $poznHtml
        . '<p style="margin:18px 0 0;color:#9ca3af;font-size:13px">Voucher uplatníte na prodejně nebo při objednávce — stačí nahlásit kód.</p>'
        . '</div></div>'
        . '<p style="text-align:center;color:#9ca3af;font-size:12px;margin:16px 0 0">' . $e($firma) . '</p>'
        . '</div></body></html>';
}

/** Odešle voucher emailem odběrateli (multipart/alternative, ověřený mail() jako doklady). */
function voucher_send_email(PDO $pdo, array $v): array {
    $to = trim((string) ($v['odberatel_email'] ?? ''));
    if ($to === '' || !filter_var($to, FILTER_VALIDATE_EMAIL)) return ['ok' => false, 'error' => 'Odběratel nemá platný email'];
    $firma     = (string) (nastaveni_get($pdo, 'firma_nazev', '') ?: 'Provoz');
    $fromEmail = (string) (nastaveni_get($pdo, 'firma_email', '') ?: ('noreply@' . ($_SERVER['HTTP_HOST'] ?? 'localhost')));
    $hodnota   = voucher_hodnota_text($v);
    $platnost  = !empty($v['platnost_do']) ? 'Platnost do ' . date('d.m.Y', strtotime((string) $v['platnost_do'])) : 'Bez omezení platnosti';
    $html  = voucher_email_html($firma, (string) $v['kod'], $hodnota, $platnost, (string) $v['typ'], (string) ($v['poznamka'] ?? ''));
    $plain = "$firma — voucher\n\nKód: {$v['kod']}\nHodnota: $hodnota\n$platnost\n\nVoucher uplatníte na prodejně nebo při objednávce.";
    $subj  = ($v['typ'] === 'sleva' ? 'Slevový voucher' : 'Dárkový voucher') . ' — ' . $firma;

    $boundary = 'alt_' . md5(uniqid('v', true));
    $headers = [
        'MIME-Version: 1.0',
        'From: ' . mb_encode_mimeheader($firma, 'UTF-8') . ' <' . $fromEmail . '>',
        'Reply-To: ' . $fromEmail,
        'X-Mailer: PHP/' . phpversion(),
        'Content-Type: multipart/alternative; boundary="' . $boundary . '"',
    ];
    $body  = "--$boundary\r\nContent-Type: text/plain; charset=UTF-8\r\nContent-Transfer-Encoding: 8bit\r\n\r\n$plain\r\n\r\n";
    $body .= "--$boundary\r\nContent-Type: text/html; charset=UTF-8\r\nContent-Transfer-Encoding: 8bit\r\n\r\n$html\r\n\r\n";
    $body .= "--$boundary--\r\n";
    $subjEnc = '=?UTF-8?B?' . base64_encode($subj) . '?=';
    $ok = appek_mail_raw($to, $subjEnc, $body, implode("\r\n", $headers));
    return ['ok' => (bool) $ok, 'to' => $to];
}

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$action = $_GET['action'] ?? '';
$admin  = function_exists('aktualni_admin') ? (aktualni_admin($pdo)['jmeno'] ?? 'Admin') : 'Admin';

// ── GET ───────────────────────────────────────────────────────────────
if ($method === 'GET') {
    if (!empty($_GET['kod'])) {
        $st = $pdo->prepare("SELECT * FROM vouchery WHERE kod = :k LIMIT 1");
        $st->execute(['k' => strtoupper(trim((string) $_GET['kod']))]);
        $v = $st->fetch(PDO::FETCH_ASSOC);
        if (!$v) json_error('Voucher nenalezen', 404);
        $v['stav_aktualni'] = voucher_stav_runtime($v);
        $v['hodnota_text']  = voucher_hodnota_text($v);
        json_response(['voucher' => $v]);
    }
    $rows = $pdo->query("SELECT * FROM vouchery ORDER BY vytvoreno DESC, id DESC LIMIT 500")->fetchAll(PDO::FETCH_ASSOC);
    $hodnotaCelkem = 0; $zustatekCelkem = 0; $aktivni = 0;
    foreach ($rows as &$v) {
        $v['stav_aktualni'] = voucher_stav_runtime($v);
        $v['hodnota_text']  = voucher_hodnota_text($v);
        $hodnotaCelkem += (float) $v['hodnota'];
        if ($v['stav_aktualni'] === 'aktivni') { $zustatekCelkem += (float) $v['zustatek']; $aktivni++; }
    }
    unset($v);
    json_response([
        'vouchery' => $rows,
        'souhrn'   => [
            'pocet'           => count($rows),
            'aktivnich'       => $aktivni,
            'hodnota_celkem'  => round($hodnotaCelkem, 2),
            'zustatek_aktivni'=> round($zustatekCelkem, 2),
        ],
    ]);
}

if ($method !== 'POST') json_error('Method not allowed', 405);
$d = json_input();

// ── CREATE ────────────────────────────────────────────────────────────
if ($action === 'create') {
    $typ = $d['typ'] ?? 'voucher';
    if (!in_array($typ, ['voucher', 'darkova_karta', 'sleva'], true)) $typ = 'voucher';
    $pocet   = max(1, min(500, (int) ($d['pocet'] ?? 1)));
    $platnost= !empty($d['platnost_do']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $d['platnost_do']) ? $d['platnost_do'] : null;
    $pozn    = isset($d['poznamka']) ? mb_substr(trim((string) $d['poznamka']), 0, 255) : null;

    // % sleva (typ=sleva) vs Kč hodnota (voucher/dárková karta)
    $slevaPct = null; $slevaMax = null; $hodnota = 0.0;
    if ($typ === 'sleva') {
        $slevaPct = round((float) ($d['sleva_pct'] ?? 0), 2);
        if ($slevaPct <= 0 || $slevaPct > 100) json_error('Procento slevy musí být 1–100', 400);
        $slevaMax = isset($d['sleva_max_kc']) && (float) $d['sleva_max_kc'] > 0 ? round((float) $d['sleva_max_kc'], 2) : null;
    } else {
        $hodnota = round((float) ($d['hodnota'] ?? 0), 2);
        if ($hodnota <= 0) json_error('Hodnota musí být kladná', 400);
    }

    // Odběratel (volitelný) — POUZE z tabulky odberatele
    $odbId = (int) ($d['odberatel_id'] ?? 0);
    $odbNazev = null; $odbEmail = null;
    if ($odbId > 0) {
        $os = $pdo->prepare("SELECT nazev, email, login_email FROM odberatele WHERE id = :id LIMIT 1");
        $os->execute(['id' => $odbId]);
        $odb = $os->fetch(PDO::FETCH_ASSOC);
        if (!$odb) json_error('Odběratel nenalezen', 404);
        $odbNazev = $odb['nazev'];
        $odbEmail = trim((string) ($odb['email'] ?: $odb['login_email'] ?: ''));
    }
    $sendEmail = !empty($d['send_email']);
    if ($sendEmail && $odbId <= 0) json_error('Pro odeslání emailem vyber odběratele', 400);
    if ($sendEmail && ($odbEmail === '' || !filter_var($odbEmail, FILTER_VALIDATE_EMAIL))) json_error('Odběratel nemá platný email — nelze odeslat', 400);
    // víc kódů emailem 1 odběrateli nedává smysl
    if ($sendEmail && $pocet > 1) json_error('Při odeslání emailem vytvoř 1 kód (jde jednomu odběrateli)', 400);

    $prefix = $typ === 'darkova_karta' ? 'DK' : ($typ === 'sleva' ? 'SL' : 'VO');
    $ins = $pdo->prepare("INSERT INTO vouchery (kod, typ, hodnota, zustatek, sleva_pct, sleva_max_kc, platnost_do, poznamka, odberatel_id, odberatel_nazev, odberatel_email)
                          VALUES (:k,:t,:h,:z,:sp,:sm,:p,:po,:oid,:on,:oe)");
    $insLog = $pdo->prepare("INSERT INTO voucher_pohyby (voucher_id, typ, castka, zustatek_po, kdo) VALUES (:v,'vytvoreni',:c,:z,:kdo)");
    $vytvoreno = []; $emailVysledek = null;
    $pdo->beginTransaction();
    try {
        for ($i = 0; $i < $pocet; $i++) {
            // generuj unikátní kód (max 6 pokusů)
            $kod = '';
            for ($a = 0; $a < 6; $a++) {
                $kod = voucher_gen_kod($prefix);
                $chk = $pdo->prepare("SELECT 1 FROM vouchery WHERE kod = :k LIMIT 1");
                $chk->execute(['k' => $kod]);
                if (!$chk->fetchColumn()) break;
                $kod = '';
            }
            if ($kod === '') throw new Exception('Nepodařilo se vygenerovat unikátní kód');
            $ins->execute([
                'k' => $kod, 't' => $typ, 'h' => $hodnota, 'z' => $hodnota,
                'sp' => $slevaPct, 'sm' => $slevaMax, 'p' => $platnost, 'po' => $pozn,
                'oid' => $odbId ?: null, 'on' => $odbNazev, 'oe' => ($odbEmail ?: null),
            ]);
            $vid = (int) $pdo->lastInsertId();
            $insLog->execute(['v' => $vid, 'c' => $hodnota, 'z' => $hodnota, 'kdo' => $admin]);
            $vytvoreno[] = ['id' => $vid, 'kod' => $kod];
        }
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        json_error_safe('Vytvoření selhalo', $e, 500);
    }

    // Email až PO commitu — i kdyby mail selhal, voucher existuje
    if ($sendEmail && $vytvoreno) {
        $sent = 0;
        foreach ($vytvoreno as $vt) {
            $vs = $pdo->prepare("SELECT * FROM vouchery WHERE id = :id");
            $vs->execute(['id' => $vt['id']]);
            $vrow = $vs->fetch(PDO::FETCH_ASSOC);
            if (!$vrow) continue;
            $r = voucher_send_email($pdo, $vrow);
            if ($r['ok']) {
                $sent++;
                $pdo->prepare("UPDATE vouchery SET odeslano_email = 1, odeslano_kdy = NOW() WHERE id = :id")->execute(['id' => $vt['id']]);
            }
        }
        $emailVysledek = ['odeslano' => $sent, 'z' => count($vytvoreno), 'na' => $odbEmail];
    }
    json_response(['ok' => true, 'vytvoreno' => $vytvoreno, 'pocet' => count($vytvoreno), 'email' => $emailVysledek], 201);
}

// ── REDEEM (uplatnění) ────────────────────────────────────────────────
if ($action === 'redeem') {
    $kod    = strtoupper(trim((string) ($d['kod'] ?? '')));
    $castka = round((float) ($d['castka'] ?? 0), 2);
    $doklad = isset($d['doklad']) ? mb_substr((string) $d['doklad'], 0, 40) : null;
    if ($kod === '') json_error('Chybí kód', 400);
    if ($castka <= 0) json_error('Částka musí být kladná', 400);

    $pdo->beginTransaction();
    try {
        // 🔒 zámek řádku → žádný double-spend
        $st = $pdo->prepare("SELECT * FROM vouchery WHERE kod = :k LIMIT 1 FOR UPDATE");
        $st->execute(['k' => $kod]);
        $v = $st->fetch(PDO::FETCH_ASSOC);
        if (!$v) { $pdo->rollBack(); json_error('Voucher „' . $kod . '" neexistuje', 404); }
        if ($v['stav'] === 'zruseny') { $pdo->rollBack(); json_error('Voucher je zrušený', 409); }
        if (!empty($v['platnost_do']) && $v['platnost_do'] < date('Y-m-d')) {
            $pdo->prepare("UPDATE vouchery SET stav='expirovany' WHERE id=:id")->execute(['id' => $v['id']]);
            $pdo->commit();
            json_error('Voucher expiroval ' . $v['platnost_do'], 409);
        }

        // ── % SLEVA — jednorázová, dopočítá se z částky objednávky (castka = útrata) ──
        if ($v['typ'] === 'sleva') {
            if ($v['stav'] === 'vycerpany') { $pdo->rollBack(); json_error('Slevový voucher už byl použit', 409); }
            $pct = (float) $v['sleva_pct'];
            $uplatneno = round($castka * $pct / 100, 2);
            if (!empty($v['sleva_max_kc'])) $uplatneno = min($uplatneno, (float) $v['sleva_max_kc']);
            $uplatneno = round(min($uplatneno, $castka), 2);      // nikdy víc než útrata
            $pdo->prepare("UPDATE vouchery SET stav='vycerpany', posledni_pouziti=NOW() WHERE id=:id")->execute(['id' => $v['id']]);
            $pdo->prepare("INSERT INTO voucher_pohyby (voucher_id, typ, castka, zustatek_po, doklad, kdo) VALUES (:v,'uplatneni',:c,0,:dok,:kdo)")
                ->execute(['v' => $v['id'], 'c' => -$uplatneno, 'dok' => $doklad, 'kdo' => $admin]);
            $pdo->commit();
            json_response([
                'ok' => true, 'kod' => $kod, 'typ' => 'sleva',
                'uplatneno' => $uplatneno, 'zbytek_voucheru' => 0,
                'vyrovnano' => ($uplatneno >= $castka - 0.001),
            ]);
        }

        $zust = (float) $v['zustatek'];
        if ($zust <= 0.001) { $pdo->rollBack(); json_error('Voucher je již vyčerpaný', 409); }

        $uplatneno = min($castka, $zust);
        $novyZust  = round($zust - $uplatneno, 2);
        $novyStav  = $novyZust <= 0.001 ? 'vycerpany' : 'aktivni';
        $pdo->prepare("UPDATE vouchery SET zustatek=:z, stav=:s, posledni_pouziti=NOW() WHERE id=:id")
            ->execute(['z' => $novyZust, 's' => $novyStav, 'id' => $v['id']]);
        $pdo->prepare("INSERT INTO voucher_pohyby (voucher_id, typ, castka, zustatek_po, doklad, kdo) VALUES (:v,'uplatneni',:c,:z,:dok,:kdo)")
            ->execute(['v' => $v['id'], 'c' => -$uplatneno, 'z' => $novyZust, 'dok' => $doklad, 'kdo' => $admin]);
        $pdo->commit();
        json_response([
            'ok' => true, 'kod' => $kod, 'typ' => $v['typ'],
            'uplatneno' => round($uplatneno, 2),
            'zbytek_voucheru' => $novyZust,
            'vyrovnano' => ($uplatneno >= $castka - 0.001), // pokrylo celou požadovanou částku?
        ]);
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        json_error_safe('Uplatnění selhalo', $e, 500);
    }
}

// ── TOPUP (dobití) ────────────────────────────────────────────────────
if ($action === 'topup') {
    $castka = round((float) ($d['castka'] ?? 0), 2);
    if ($castka <= 0) json_error('Částka musí být kladná', 400);
    $id  = (int) ($d['id'] ?? 0);
    $kod = strtoupper(trim((string) ($d['kod'] ?? '')));
    $pdo->beginTransaction();
    try {
        $st = $pdo->prepare($id ? "SELECT * FROM vouchery WHERE id=:k FOR UPDATE" : "SELECT * FROM vouchery WHERE kod=:k FOR UPDATE");
        $st->execute(['k' => $id ?: $kod]);
        $v = $st->fetch(PDO::FETCH_ASSOC);
        if (!$v) { $pdo->rollBack(); json_error('Voucher nenalezen', 404); }
        if ($v['stav'] === 'zruseny') { $pdo->rollBack(); json_error('Zrušený voucher nelze dobít', 409); }
        if (($v['typ'] ?? '') === 'sleva') { $pdo->rollBack(); json_error('Slevový voucher (%) nelze dobít', 409); }
        $novyZust = round((float) $v['zustatek'] + $castka, 2);
        $pdo->prepare("UPDATE vouchery SET zustatek=:z, hodnota=hodnota+:c, stav='aktivni' WHERE id=:id")
            ->execute(['z' => $novyZust, 'c' => $castka, 'id' => $v['id']]);
        $pdo->prepare("INSERT INTO voucher_pohyby (voucher_id, typ, castka, zustatek_po, kdo) VALUES (:v,'dobiti',:c,:z,:kdo)")
            ->execute(['v' => $v['id'], 'c' => $castka, 'z' => $novyZust, 'kdo' => $admin]);
        $pdo->commit();
        json_response(['ok' => true, 'zustatek' => $novyZust]);
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        json_error_safe('Dobití selhalo', $e, 500);
    }
}

// ── DEACTIVATE ────────────────────────────────────────────────────────
if ($action === 'deactivate') {
    $id = (int) ($d['id'] ?? 0);
    if (!$id) json_error('Chybí id', 400);
    $pdo->prepare("UPDATE vouchery SET stav='zruseny' WHERE id=:id")->execute(['id' => $id]);
    json_response(['ok' => true]);
}

// ── SEND_EMAIL (odeslat / znovu odeslat voucher odběrateli) ───────────
if ($action === 'send_email') {
    $id = (int) ($d['id'] ?? 0);
    if (!$id) json_error('Chybí id', 400);
    $st = $pdo->prepare("SELECT * FROM vouchery WHERE id = :id LIMIT 1");
    $st->execute(['id' => $id]);
    $v = $st->fetch(PDO::FETCH_ASSOC);
    if (!$v) json_error('Voucher nenalezen', 404);
    if (empty($v['odberatel_email'])) json_error('Voucher nemá přiřazeného odběratele s emailem', 400);
    $r = voucher_send_email($pdo, $v);
    if (empty($r['ok'])) json_error($r['error'] ?? 'Odeslání emailu selhalo (mail server?)', 500);
    $pdo->prepare("UPDATE vouchery SET odeslano_email = 1, odeslano_kdy = NOW() WHERE id = :id")->execute(['id' => $id]);
    json_response(['ok' => true, 'na' => $r['to']]);
}

json_error('Neznámá akce', 400);
