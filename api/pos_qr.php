<?php
/**
 * 📲 POS QR — Public endpoint pro hosty s naskenovaným QR (token v URL).
 *
 * GET ?action=menu&token=XXX        → menu + info o stole
 * POST ?action=order&token=XXX      → odešle objednávku (do queue → admin schvaluje)
 * POST ?action=call_waiter&token=XXX → "zavolat číšníka" (set table state attention)
 *
 * Bez admin auth — token v URL je auth. Session expiruje za 3 hodiny.
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/_packages_lib.php';

header('Content-Type: application/json; charset=UTF-8');
header('Access-Control-Allow-Origin: *');

if (!package_enabled('restaurace')) {
    http_response_code(402);
    echo json_encode(['error' => 'Restaurace balíček není aktivní']);
    exit;
}

$pdo = db();
$action = $_GET['action'] ?? '';
$token = trim($_GET['token'] ?? $_POST['token'] ?? '');

if (!preg_match('/^[a-f0-9]{16,64}$/', $token)) {
    http_response_code(400);
    echo json_encode(['error' => 'Neplatný token']);
    exit;
}

// Najdi stůl podle qr_token (per-table persistent token)
$st = $pdo->prepare("SELECT id, nazev, stav, mist FROM restaurant_tables WHERE qr_token = :t LIMIT 1");
$st->execute(['t' => $token]);
$stul = $st->fetch();
if (!$stul) {
    http_response_code(404);
    echo json_encode(['error' => 'QR neplatný — kontaktujte obsluhu']);
    exit;
}

// Session — TTL 3 hodiny (znovu po každé akci)
$expiresAt = date('Y-m-d H:i:s', time() + 3 * 3600);
$pdo->prepare("
    INSERT INTO restaurant_qr_sessions (token, stul_id, ip, ua, expires_at)
    VALUES (:t, :s, :ip, :ua, :ex)
    ON DUPLICATE KEY UPDATE expires_at = :ex2, ip = :ip2, ua = :ua2
")->execute([
    't' => $token, 's' => $stul['id'],
    'ip' => $_SERVER['REMOTE_ADDR'] ?? null,
    'ua' => substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 200),
    'ex' => $expiresAt, 'ex2' => $expiresAt,
    'ip2' => $_SERVER['REMOTE_ADDR'] ?? null,
    'ua2' => substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 200),
]);

// ─── GET menu ───────────────────────────────────────────────
if ($action === 'menu') {
    // Načti aktivní produkty seskupené po kategoriích
    $vyrobky = $pdo->query("
        SELECT v.id, v.nazev, v.cena_bez_dph AS cena, v.popis, v.kategorie_id,
               k.nazev AS kategorie_nazev, k.barva AS kategorie_barva
        FROM vyrobky v
        LEFT JOIN kategorie_vyrobku k ON k.id = v.kategorie_id
        WHERE v.aktivni = 1
        ORDER BY k.poradi, k.nazev, v.nazev
    ")->fetchAll();

    // Filter alergeny pokud existují
    $kategorieMap = [];
    foreach ($vyrobky as $v) {
        $kid = $v['kategorie_id'] ?: 0;
        $kategorieMap[$kid] ??= [
            'id' => $kid,
            'nazev' => $v['kategorie_nazev'] ?: '— Bez kategorie —',
            'barva' => $v['kategorie_barva'] ?: '#94A3B8',
            'items' => [],
        ];
        $kategorieMap[$kid]['items'][] = [
            'id' => (int) $v['id'],
            'nazev' => $v['nazev'],
            'cena' => (float) $v['cena'],
            'popis' => $v['popis'] ?: '',
        ];
    }

    echo json_encode([
        'stul' => $stul,
        'kategorie' => array_values($kategorieMap),
        'menu_pocet' => count($vyrobky),
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

// ─── POST order ─────────────────────────────────────────────
if ($action === 'order' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $raw = file_get_contents('php://input');
    $d = json_decode($raw, true);
    if (!is_array($d) || empty($d['items'])) {
        http_response_code(400);
        echo json_encode(['error' => 'Chybí items']);
        exit;
    }
    $host = trim($d['host_jmeno'] ?? '');
    $tel  = trim($d['host_telefon'] ?? '');

    // Update session jméno/telefon
    if ($host || $tel) {
        $pdo->prepare("UPDATE restaurant_qr_sessions SET host_jmeno = :h, host_telefon = :t WHERE token = :tok")
            ->execute(['h' => $host, 't' => $tel, 'tok' => $token]);
    }

    // 🔒 v3.0.146 SECURITY (#4) — cena A název MUSÍ být serverové, ne od klienta.
    // Tohle je VEŘEJNÝ endpoint (token v URL = jediná auth). Dřív se ukládala
    // klientská 'cena' i 'nazev' → host mohl poslat cena:0 (podhodnotit účet)
    // nebo podvrhnout název. Teď dohledáme autoritativní cenu/název pro každé
    // vyrobek_id z katalogu (jen aktivní výrobky — stejné scoping jako menu).
    $reqIds = [];
    foreach ($d['items'] as $i) {
        if (!empty($i['vyrobek_id'])) $reqIds[(int) $i['vyrobek_id']] = true;
    }
    $katalog = [];
    if ($reqIds) {
        $ids = array_keys($reqIds);
        $ph  = implode(',', array_fill(0, count($ids), '?'));
        $qs  = $pdo->prepare("SELECT id, nazev, cena_bez_dph FROM vyrobky WHERE id IN ($ph) AND aktivni = 1");
        $qs->execute($ids);
        foreach ($qs->fetchAll() as $r) {
            $katalog[(int) $r['id']] = ['nazev' => $r['nazev'], 'cena' => (float) $r['cena_bez_dph']];
        }
    }

    $inserted = 0;
    $skipped  = 0;
    $stmt = $pdo->prepare("
        INSERT INTO restaurant_qr_orders
          (stul_id, session_token, vyrobek_id, nazev, jednotkova_cena, mnozstvi, poznamka, host_jmeno, host_telefon, stav)
        VALUES
          (:s, :t, :v, :n, :c, :m, :p, :h, :tel, 'pending')
    ");
    foreach ($d['items'] as $i) {
        $vId = !empty($i['vyrobek_id']) ? (int) $i['vyrobek_id'] : null;
        // jen položky s platným, aktivním výrobkem — cena/název bereme serverově
        if ($vId === null || !isset($katalog[$vId])) { $skipped++; continue; }
        $cena  = $katalog[$vId]['cena'];     // 🔒 server, ne $i['cena']
        $nazev = $katalog[$vId]['nazev'];    // 🔒 server, ne $i['nazev']
        $mnoz  = (float) ($i['mnozstvi'] ?? 1);
        if ($mnoz <= 0) { $skipped++; continue; }
        if ($mnoz > 99) $mnoz = 99;          // sanity cap proti griefingu
        $stmt->execute([
            's' => $stul['id'], 't' => $token,
            'v' => $vId, 'n' => $nazev, 'c' => $cena, 'm' => $mnoz,
            'p' => isset($i['poznamka']) ? substr(trim((string) $i['poznamka']), 0, 255) : null,
            'h' => $host ?: null, 'tel' => $tel ?: null,
        ]);
        $inserted++;
    }

    // Žádná platná položka → nic nezakládej (typicky manipulovaný/zastaralý payload)
    if ($inserted === 0) {
        http_response_code(400);
        echo json_encode(['error' => 'Žádná platná položka v objednávce', 'skipped' => $skipped], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // Mark table jako "vyžaduje pozornost" — obsluha vidí
    $pdo->prepare("
        UPDATE restaurant_tables
        SET stav = CASE WHEN stav IN ('free','cleaning') THEN 'attention' ELSE stav END
        WHERE id = :id
    ")->execute(['id' => $stul['id']]);

    echo json_encode(['ok' => true, 'inserted' => $inserted, 'skipped' => $skipped, 'message' => 'Objednávka odeslána. Obsluha ji během chvíle potvrdí.'], JSON_UNESCAPED_UNICODE);
    exit;
}

// ─── POST call_waiter ───────────────────────────────────────
if ($action === 'call_waiter' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $pdo->prepare("
        UPDATE restaurant_tables
        SET stav = 'attention', stav_od = COALESCE(stav_od, NOW())
        WHERE id = :id
    ")->execute(['id' => $stul['id']]);
    echo json_encode(['ok' => true, 'message' => 'Obsluha byla zavolána.']);
    exit;
}

http_response_code(404);
echo json_encode(['error' => 'Neznámá akce']);
