<?php
/**
 * 🆕 v3.0.7 — QR pay-at-table (Customer payment landing API)
 *
 * Workflow:
 *   1. Číšník v adminu klikne "📲 QR platba" na objednávce → POST ?action=create_token
 *      Vrátí pay_url = https://restaurace.cz/pay/?t=<token>
 *   2. Číšník QR vytiskne / přiloží na účtenku / na stůl
 *   3. Host naskenuje QR → otevře /pay/?t=TOKEN
 *      Frontend (pay/index.php) zavolá GET ?action=info&t=TOKEN → vrátí amount, items, firmu
 *      Host vybere platbu:
 *        - Stripe Checkout → POST ?action=stripe_init → redirect na Stripe → po platbě webhook
 *        - GoPay → POST ?action=gopay_init → redirect na GoPay
 *        - Manuální (hotovost u číšníka) → POST ?action=mark_paid_manual → marks "pending_manual"
 *   4. Webhook (Stripe / GoPay) potvrdí platbu → objednavka.pay_status='paid' + paid_at
 *
 * Endpointy:
 *   POST ?action=create_token { objednavka_id }        — admin only, generuje token
 *   GET  ?action=info&t=TOKEN                          — public, info pro pay page
 *   POST ?action=stripe_init { token }                 — public, vrátí Stripe checkout URL
 *   POST ?action=gopay_init  { token }                 — public, vrátí GoPay redirect URL
 *   POST ?action=mark_paid_manual { token, method }    — public, host řekne "platím hotovostí"
 *   POST ?action=stripe_webhook                        — Stripe → mark paid
 *   POST ?action=gopay_callback                        — GoPay → mark paid
 *   GET  ?action=status&t=TOKEN                        — public, polling jestli zaplaceno
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/_customer_integrace.php';
require_once __DIR__ . '/_online_platba_lib.php';   // 💱 v3.0.643 — platba v měně dokladu + ověření částky ve webhooku

cors_headers();
header('Content-Type: application/json; charset=UTF-8');

$method = $_SERVER['REQUEST_METHOD'];
$pdo = db();
$action = $_GET['action'] ?? '';

// ─────────────────────────────────────────────────────────────
// SCHEMA — auto-migrate (přidej pay_* sloupce do objednavky)
// ─────────────────────────────────────────────────────────────
function pay_qr_ensure_schema(PDO $pdo): void {
    static $done = false;
    if ($done) return;
    try {
        $cols = $pdo->query("SHOW COLUMNS FROM objednavky")->fetchAll(PDO::FETCH_COLUMN);
        if (!in_array('pay_token', $cols, true)) {
            $pdo->exec("ALTER TABLE objednavky ADD COLUMN pay_token VARCHAR(48) NULL UNIQUE");
        }
        if (!in_array('pay_status', $cols, true)) {
            $pdo->exec("ALTER TABLE objednavky ADD COLUMN pay_status ENUM('none','pending','pending_manual','paid','failed','expired','refunded') DEFAULT 'none'");
        }
        if (!in_array('pay_method', $cols, true)) {
            $pdo->exec("ALTER TABLE objednavky ADD COLUMN pay_method VARCHAR(40) NULL");
        }
        if (!in_array('paid_at', $cols, true)) {
            $pdo->exec("ALTER TABLE objednavky ADD COLUMN paid_at DATETIME NULL");
        }
        if (!in_array('pay_extra', $cols, true)) {
            $pdo->exec("ALTER TABLE objednavky ADD COLUMN pay_extra TEXT NULL");
        }
    } catch (Throwable $e) {}
    $done = true;
}

function pay_get_order_by_token(PDO $pdo, string $token): ?array {
    pay_qr_ensure_schema($pdo);
    $token = preg_replace('/[^a-f0-9]/i', '', $token);
    if (strlen($token) < 16) return null;
    $s = $pdo->prepare("SELECT * FROM objednavky WHERE pay_token = :t LIMIT 1");
    $s->execute(['t' => $token]);
    $row = $s->fetch();
    return $row ?: null;
}

// 🆕 v3.0.325 — token může patřit OBJEDNÁVCE (klasická QR pay) NEBO POS ÚČTU (QR u stolu).
//   Objednávku zkouší PRVNÍ → existující chování beze změny. Vrací normalizovaná pole.
function pay_resolve_token(PDO $pdo, string $token): ?array {
    $o = pay_get_order_by_token($pdo, $token);
    if ($o) return ['type'=>'obj', 'id'=>(int)$o['id'], 'cislo'=>$o['cislo'], 'castka'=>(float)$o['castka_celkem'], 'mena'=>$o['mena'] ?? null,
                    // 🐛 v3.0.644 — zaplaceno na pokladně (stav) = pro hosta „zaplaceno", i když pay_status zůstal 'pending'
                    'pay_status'=>(($o['stav'] ?? '') === 'zaplaceno' ? 'paid' : $o['pay_status']), 'paid_at'=>$o['paid_at'] ?? null, 'pay_method'=>$o['pay_method'] ?? null, 'datum'=>$o['datum_objednani'] ?? null,
                    'stav'=>$o['stav'] ?? null, 'puvod'=>$o['puvod'] ?? null];
    $tok = preg_replace('/[^a-f0-9]/i', '', $token);
    if (strlen($tok) < 16) return null;
    try {
        $cols = $pdo->query("SHOW COLUMNS FROM restaurant_pos_ucty")->fetchAll(PDO::FETCH_COLUMN);
        if (!in_array('pay_token', $cols, true)) return null;
        $u = $pdo->prepare("SELECT * FROM restaurant_pos_ucty WHERE pay_token = :t LIMIT 1");
        $u->execute(['t' => $tok]);
        $r = $u->fetch(PDO::FETCH_ASSOC);
        if (!$r) return null;
        $ps = ($r['stav'] === 'paid' || ($r['pay_status'] ?? '') === 'paid') ? 'paid' : (($r['pay_status'] ?? 'pending') ?: 'pending');
        return ['type'=>'ucet', 'id'=>(int)$r['id'], 'cislo'=>($r['cislo_dokladu'] ?: ('Účet #' . $r['id'])), 'mena'=>$r['mena'] ?? null,
                'castka'=>(float)$r['suma_kc'], 'pay_status'=>$ps, 'paid_at'=>$r['zaplaceno_v'] ?? null, 'pay_method'=>null, 'datum'=>null,
                'stav'=>$r['stav'] ?? null];
    } catch (Throwable $e) { return null; }
}

function pay_items_for(PDO $pdo, array $t): array {
    if ($t['type'] === 'ucet') {
        // 💱 v3.0.643 — jednotkova_cena účtu je S DPH; sazba z položky (balík A plní sazba_dph), jinak 21 %.
        //   cena_bez_dph = jako mena_ucet_polozky (2 místa) → frontend dopočte stejnou cenu s DPH jako dřív bez 21 % navíc.
        $saz = mena_ma_sloupec($pdo, 'restaurant_pos_polozky', 'sazba_dph') ? 'COALESCE(sazba_dph, 21)' : '21';
        $p = $pdo->prepare("SELECT mnozstvi, jednotkova_cena, $saz AS sazba_dph, nazev
                            FROM restaurant_pos_polozky WHERE ucet_id = :id AND stav <> 'storno' ORDER BY id");
        $p->execute(['id' => $t['id']]);
        $out = [];
        foreach ($p->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $s = (float) $r['sazba_dph'];
            $out[] = ['mnozstvi' => (float) $r['mnozstvi'], 'cena_bez_dph' => round((float) $r['jednotkova_cena'] / (1 + $s / 100), 2),
                      'sazba_dph' => $s, 'nazev' => $r['nazev'], 'castka_czk' => round((float) $r['mnozstvi'] * (float) $r['jednotkova_cena'], 2)];
        }
        return $out;
    }
    $p = $pdo->prepare("SELECT op.mnozstvi, op.cena_bez_dph, op.sazba_dph, COALESCE(op.vyrobek_nazev, v.nazev) AS nazev
                        FROM objednavky_polozky op LEFT JOIN vyrobky v ON v.id = op.vyrobek_id WHERE op.objednavka_id = :id ORDER BY op.id");
    $p->execute(['id' => $t['id']]);
    return $p->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * 💱 v3.0.643 — částky položek v měně $m (shodně s dokladem = mena_doklad_polozky po řádcích).
 *   Kč u účtu = mn × cena s DPH (jak ji vidí obsluha); Kč u objednávky = jako dřív (bez DPH × (1+sazba)).
 */
function pay_items_castky(PDO $pdo, array $t, array $items, array $m): array {
    $mm = mena_doklad_ro($pdo, ['mena' => $m['mena'], 'mena_kurz' => $m['mena_kurz'] ?? 1, 'mena_kurz_prodej' => $m['mena_kurz_prodej'] ?? null, 'mena_zaklad' => 'CZK']);
    if ($mm['mena'] === 'CZK') {
        return array_map(fn($it) => isset($it['castka_czk']) ? $it['castka_czk']
            : round((float) $it['cena_bez_dph'] * (float) $it['mnozstvi'] * (1 + (float) $it['sazba_dph'] / 100), 2), $items);
    }
    $calc = mena_doklad_polozky(array_map(fn($it) => [(float) $it['mnozstvi'], (float) $it['cena_bez_dph'], (float) $it['sazba_dph']], $items), $mm);
    return array_map(fn($o) => round($o['zaklad'] + $o['dph'], 2), array_values($calc['polozky']));
}

/** 💱 v3.0.643 — částka k úhradě: objednávka = měna dokladu (pevná), účet stolu = zvolená měna. */
function pay_castka_pro(PDO $pdo, array $t, ?array $m = null): array {
    if ($t['type'] === 'ucet') return mena_doc_castka($pdo, 'restaurant_pos_ucty', $t['id'], $m);
    return mena_doc_castka($pdo, 'objednavky', $t['id']);
}

// Online platba účtu stolu: 'paid' nastaví _online_platba_lib (po kontrole částky/měny); skutečnou
// finalizaci (odpis/prodej/uvolnění stolu) dodělá admin_pos.php self-heal při načtení POS.

function pay_set_status(PDO $pdo, int $orderId, string $status, ?string $method = null, ?string $extra = null): void {
    $sql = "UPDATE objednavky SET pay_status = :s";
    $params = ['s' => $status, 'id' => $orderId];
    if ($method !== null) { $sql .= ", pay_method = :m"; $params['m'] = $method; }
    if ($status === 'paid') { $sql .= ", paid_at = NOW(), stav = IF(stav IN ('rozpracovano','novy','zaplaceno_predem'), 'zaplaceno', stav)"; }
    if ($extra !== null) { $sql .= ", pay_extra = :e"; $params['e'] = $extra; }
    $sql .= " WHERE id = :id";
    $pdo->prepare($sql)->execute($params);
}

function pay_base_url(): string {
    return app_public_base();   // 🐛 v3.0.644 — vč. podsložky instalace (dřív /pay/ → 404)
}

/**
 * 🐛 v3.0.644 — smí host k tokenu zahájit (online/manuální) platbu? Objednávka zaplacená na pokladně
 *   (pickup_complete apod. nechá pay_status 'pending') nebo zrušená, účet stolu už uzavřený (zaplacený jinak,
 *   rozdělený, sloučený) → ne; dřív init kontroloval jen pay_status a host zaplatil podruhé. Null = OK.
 */
function pay_init_blokace(array $t): ?string {
    $stav = (string) ($t['stav'] ?? '');
    if ($t['type'] === 'ucet') {
        return in_array($stav, ['open', 'awaiting_payment', ''], true) ? null : 'Účet je už uzavřený — o platbě se domluvte s obsluhou.';
    }
    if ($stav === 'zrusena') return 'Objednávka je zrušená';
    if ($stav === 'zaplaceno') return 'Objednávka je už zaplacená';
    return null;
}

// ─────────────────────────────────────────────────────────────
// POST ?action=create_token — Admin only, vygeneruj pay_token
// ─────────────────────────────────────────────────────────────
if ($method === 'POST' && $action === 'create_token') {
    require_once __DIR__ . '/_admin_auth.php';
    require_admin();
    pay_qr_ensure_schema($pdo);

    $d = json_decode(file_get_contents('php://input'), true) ?? [];
    $id = (int)($d['objednavka_id'] ?? $d['id'] ?? 0);
    if (!$id) json_error('Chybí objednavka_id', 400);

    $s = $pdo->prepare("SELECT id, pay_token, pay_status, stav, puvod FROM objednavky WHERE id = :id");
    $s->execute(['id' => $id]);
    $o = $s->fetch();
    if (!$o) json_error('Objednávka nenalezena', 404);
    // 🐛 v3.0.644 — pokladní (PIN) smí QR platbu založit jen u pokladní objednávky / webového vyzvednutí (ne B2B/fakturované)
    if (!empty($_SESSION['pos_only_user']) && !in_array((string) ($o['puvod'] ?? ''), array_merge(kanaly_pokladni($pdo), ['web']), true)) {
        json_error('POS účet může vytvořit QR platbu jen u pokladní objednávky', 403);
    }
    if ($o['pay_status'] === 'paid') json_error('Objednávka je již zaplacena', 409);
    // 🐛 v3.0.643 — účtenka zaplacená na pokladně (stav zaplaceno) → druhá platba přes QR = dvojí úhrada
    if (($o['stav'] ?? '') === 'zaplaceno') json_error('Účtenka je už zaplacená na pokladně — QR platba není potřeba.', 409);
    if (($o['stav'] ?? '') === 'zrusena') json_error('Objednávka je zrušená', 409);

    $token = $o['pay_token'] ?: bin2hex(random_bytes(16));
    if (!$o['pay_token']) {
        $pdo->prepare("UPDATE objednavky SET pay_token = :t, pay_status = 'pending' WHERE id = :id")
            ->execute(['t' => $token, 'id' => $id]);
    }
    $url = pay_base_url() . '/pay/?t=' . $token;
    json_response(['ok' => true, 'token' => $token, 'pay_url' => $url]);
}

// ─────────────────────────────────────────────────────────────
// GET ?action=info — Public, vrátí info o objednávce (pro pay page)
// ─────────────────────────────────────────────────────────────
if ($method === 'GET' && $action === 'info') {
    $token = (string)($_GET['t'] ?? '');
    $t = pay_resolve_token($pdo, $token);
    if (!$t) json_error('Neplatný nebo expirovaný token', 404);
    $items = pay_items_for($pdo, $t);

    // Firma info (název pro display)
    $firma = [];
    foreach (['firma_nazev', 'firma_logo_url'] as $k) {
        $stmt = $pdo->prepare("SELECT hodnota FROM nastaveni WHERE klic = :k");
        $stmt->execute(['k' => $k]);
        $firma[$k] = $stmt->fetchColumn() ?: null;
    }

    // 💱 v3.0.643 — měna platby: objednávka má měnu dokladu PEVNOU; účet stolu nabídne Kč / cizí měnu
    //   (Nastavení → Přístupy, ceny & měna → kanál „QR"). Částky položek i celkem po řádcích jako doklad.
    $mInfo = mena_platebni_info($pdo, 'qr');
    $moznosti = [];
    try {
        if ($t['type'] === 'ucet' && $t['pay_status'] !== 'paid') {
            foreach ($mInfo['prijimane'] as $kod) {
                $mi = $mInfo['meny'][$kod] ?? null;
                if (!$mi || empty($mi['ok'])) continue;
                $m = ['mena' => $kod, 'mena_kurz' => $mi['kurz'], 'mena_kurz_prodej' => $mi['kurz_prodej']];
                $dc = pay_castka_pro($pdo, $t, $m);
                $moznosti[$kod] = ['castka' => $dc['celkem'], 'symbol' => $dc['symbol'], 'kurz' => $mi['kurz'], 'kurz_prodej' => $mi['kurz_prodej'],
                                   'kurz_datum' => $mi['datum'], 'polozky' => pay_items_castky($pdo, $t, $items, $m)];
            }
            $vych = (!empty($t['mena']) && isset($moznosti[strtoupper($t['mena'])])) ? strtoupper($t['mena'])
                  : (isset($moznosti[$mInfo['vychozi']]) ? $mInfo['vychozi'] : 'CZK');
        } else {
            $dc = pay_castka_pro($pdo, $t);
            $m = ['mena' => $dc['mena'], 'mena_kurz' => $dc['kurz'], 'mena_kurz_prodej' => $dc['kurz_prodej']];
            $moznosti[$dc['mena']] = ['castka' => $dc['celkem'], 'symbol' => $dc['symbol'], 'kurz' => $dc['kurz'], 'kurz_prodej' => $dc['kurz_prodej'],
                                      'kurz_datum' => null, 'polozky' => pay_items_castky($pdo, $t, $items, $m)];
            $vych = $dc['mena'];
        }
    } catch (MenaException $e) {
        $moznosti = ['CZK' => ['castka' => $t['castka'], 'symbol' => 'Kč', 'kurz' => 1.0, 'kurz_prodej' => 1.0, 'kurz_datum' => null,
                               'polozky' => pay_items_castky($pdo, $t, $items, ['mena' => 'CZK'])]];
        $vych = 'CZK';
    }
    if (!isset($moznosti[$vych])) $vych = array_key_first($moznosti);
    foreach ($items as $i => &$it) $it['castka'] = $moznosti[$vych]['polozky'][$i] ?? null;
    unset($it);

    // Které metody jsou dostupné (podle integrace) — + v jakých měnách je brána umí
    $methods = [
        'manual' => true,
        'stripe' => customer_int_enabled('stripe'),
        'gopay'  => customer_int_enabled('gopay'),
    ];
    $methodsMeny = ['stripe' => customer_int_brana_meny('stripe'), 'gopay' => customer_int_brana_meny('gopay')];

    json_response([
        'ok' => true,
        'order' => [
            'cislo'    => $t['cislo'],
            'castka'   => $moznosti[$vych]['castka'],
            'castka_czk' => round((float) $t['castka'], 2),
            'mena'     => $vych,
            'mena_pevna' => $t['type'] !== 'ucet' || count($moznosti) < 2,
            'datum'    => $t['datum'],
            'pay_status' => $t['pay_status'],
            'paid_at'  => $t['paid_at'],
            'pay_method' => $t['pay_method'],
        ],
        'moznosti_men' => $moznosti,
        'items' => $items,
        'firma' => $firma,
        'methods' => $methods,
        'methods_meny' => $methodsMeny,
    ]);
}

// ─────────────────────────────────────────────────────────────
// GET ?action=status — Public, polling jestli zaplaceno
// ─────────────────────────────────────────────────────────────
if ($method === 'GET' && $action === 'status') {
    $token = (string)($_GET['t'] ?? '');
    $t = pay_resolve_token($pdo, $token);
    if (!$t) json_error('Neplatný token', 404);
    json_response([
        'ok' => true,
        'pay_status' => $t['pay_status'],
        'paid_at' => $t['paid_at'],
        'pay_method' => $t['pay_method'],
    ]);
}

// ─────────────────────────────────────────────────────────────
// POST ?action=stripe_init | gopay_init { token, mena? } — Public, vrátí URL brány
//   💱 v3.0.643 — částka = mena_doc_castka v měně dokladu (objednávka: pevná; účet stolu: zvolená
//   měna → snapshot na účet). Před voláním brány se zapíše očekávaná platba (pay_mena/pay_castka),
//   webhook pak potvrdí jen přesně tuto částku a měnu.
// ─────────────────────────────────────────────────────────────
if ($method === 'POST' && ($action === 'stripe_init' || $action === 'gopay_init')) {
    $brana = $action === 'stripe_init' ? 'stripe' : 'gopay';
    $d = json_decode(file_get_contents('php://input'), true) ?? [];
    $token = (string)($d['token'] ?? '');
    $t = pay_resolve_token($pdo, $token);
    if (!$t) json_error('Neplatný token', 404);
    if ($t['pay_status'] === 'paid') json_error('Již zaplaceno', 409);
    if ($blk = pay_init_blokace($t)) json_error($blk, 409);   // 🐛 v3.0.644 — zaplaceno na pokladně / zrušeno / účet zavřený
    if (!customer_int_enabled($brana)) json_error(($brana === 'stripe' ? 'Stripe' : 'GoPay') . ' není zapnutý', 503);
    $volba = isset($d['mena']) && $d['mena'] !== '' ? strtoupper((string) $d['mena']) : null;

    try {
        if ($t['type'] === 'ucet') {
            if (mena_platebni_info($pdo, 'qr')['aktivni']) {
                $m = mena_volba_platby($pdo, $volba, 'qr');               // může líně stáhnout kurz → mimo transakci
                mena_nastav_doklad($pdo, 'restaurant_pos_ucty', $t['id'], $m, true);   // jen nezaplacený účet
            } elseif ($volba !== null && $volba !== 'CZK') {
                json_error('Platba v jiné měně není zapnutá.', 400);
            }   // vypnuto: účet bez snapshotu = Kč (jako dřív — suma_kc)
            $dc = pay_castka_pro($pdo, $t);
        } else {
            $dc = pay_castka_pro($pdo, $t);
            if ($volba !== null && $volba !== $dc['mena']) json_error('Objednávka je vystavená v ' . $dc['mena'] . ' — měnu platby nelze změnit.', 409);
        }
    } catch (MenaException $e) {
        json_error($e->getMessage(), $e->http);
    }
    if ($dc['celkem_minor'] <= 0) json_error('Není co platit', 409);

    $base = pay_base_url();
    $ref  = ($t['type'] === 'ucet' ? 'UCET-' : 'PAY-') . $t['id'] . '-' . preg_replace('/[^a-f0-9]/i', '', $token);
    $r = online_platba_zaloz($pdo, $t['type'] === 'ucet' ? 'restaurant_pos_ucty' : 'objednavky', $t['id'], $dc, [
        'brana'            => $brana,
        'reference'        => $ref,
        'description'      => 'Účtenka ' . $t['cislo'],
        'return_url'       => $base . '/pay/?t=' . $token . '&paid=1',
        'cancel_url'       => $base . '/pay/?t=' . $token,
        // 🐛 v3.0.323 — GoPay čte 'notification_url' (dřív 'notify_url' → platba navždy 'pending')
        'notification_url' => $base . '/api/pay_qr.php?action=gopay_callback',
    ]);
    if (!$r['ok']) json_error(($brana === 'stripe' ? 'Stripe' : 'GoPay') . ' init selhal: ' . ($r['error'] ?? 'neznámá chyba'), 500);
    json_response(['ok' => true, 'redirect' => $r['url'], 'mena' => $dc['mena'], 'castka' => $dc['celkem']]);
}

// ─────────────────────────────────────────────────────────────
// POST ?action=mark_paid_manual — "platím hotovostí u číšníka"
// ─────────────────────────────────────────────────────────────
if ($method === 'POST' && $action === 'mark_paid_manual') {
    $d = json_decode(file_get_contents('php://input'), true) ?? [];
    $token = (string)($d['token'] ?? '');
    $methodLbl = (string)($d['method'] ?? 'cash');
    if (!in_array($methodLbl, ['cash', 'card_terminal', 'voucher'], true)) $methodLbl = 'cash';
    $t = pay_resolve_token($pdo, $token);
    if (!$t) json_error('Neplatný token', 404);
    if ($t['pay_status'] === 'paid') json_error('Již zaplaceno', 409);
    if ($blk = pay_init_blokace($t)) json_error($blk, 409);   // 🐛 v3.0.644
    if ($t['type'] === 'ucet') {
        // 🆕 v3.0.325 — POS účet u stolu: host platí hotově číšníkovi. Účet zůstává
        //   'awaiting_payment' a číšník ho uzavře běžným „Zaplatit" (ten awaiting_payment přijme).
        json_response(['ok' => true, 'message' => 'Řekni číšníkovi — uzavře účet hotově u stolu']);
    }
    // Objednávka: pending_manual — číšník v adminu musí potvrdit, že obdržel
    pay_set_status($pdo, $t['id'], 'pending_manual', $methodLbl);
    json_response(['ok' => true, 'message' => 'Číšník byl informován — přijde si pro platbu']);
}

// ─────────────────────────────────────────────────────────────
// POST ?action=admin_confirm_manual — admin potvrdí přijatou platbu
// ─────────────────────────────────────────────────────────────
if ($method === 'POST' && $action === 'admin_confirm_manual') {
    require_once __DIR__ . '/_admin_auth.php';
    require_admin();
    $d = json_decode(file_get_contents('php://input'), true) ?? [];
    $id = (int)($d['objednavka_id'] ?? 0);
    if (!$id) json_error('Chybí objednavka_id', 400);
    pay_qr_ensure_schema($pdo);
    // 🐛 v3.0.644 — potvrdit jde JEN čekající ruční platbu (host zvolil „platím u číšníka" → pending_manual)
    //   nezrušené objednávky; pokladní (PIN, pos_only) jen u pokladních objednávek. Dřív šlo komukoli
    //   s require_admin označit za zaplacenou libovolnou (i zrušenou B2B) objednávku + hotovost do ledgeru.
    $st0 = $pdo->prepare("SELECT id, pay_status, stav, puvod FROM objednavky WHERE id = ?");
    $st0->execute([$id]);
    $o0 = $st0->fetch(PDO::FETCH_ASSOC);
    if (!$o0) json_error('Objednávka nenalezena', 404);
    if (($o0['pay_status'] ?? '') === 'paid') json_response(['ok' => true, 'uz_zaplaceno' => true]);   // idempotentní
    if (($o0['stav'] ?? '') === 'zrusena') json_error('Objednávka je zrušená', 409);
    if (($o0['pay_status'] ?? '') !== 'pending_manual') json_error('Objednávka nečeká na ruční potvrzení platby', 409);
    if (!empty($_SESSION['pos_only_user']) && !in_array((string) ($o0['puvod'] ?? ''), array_merge(kanaly_pokladni($pdo), ['web']), true)) {
        json_error('POS účet může potvrdit platbu jen u pokladní objednávky', 403);
    }
    $byloZaplaceno = false;
    pay_set_status($pdo, $id, 'paid', null);
    // 💱 v3.0.643 — hotovost u číšníka = přijatá platba → do ledgeru v měně dokladu (uzávěrka dle měn)
    if (!$byloZaplaceno) {
        try {
            $dc = mena_doc_castka($pdo, 'objednavky', $id);
            if ($dc['celkem'] > 0) {
                $pdo->prepare("UPDATE objednavky SET pay_prijato = ? WHERE id = ?")->execute([$dc['celkem'], $id]);
                // 🐛 v3.0.644 — aktualni_admin() tu není (je v admin_pos.php) → kdo potvrdil, bereme přímo ze session
                $adm = function_exists('aktualni_admin') ? (aktualni_admin($pdo)['jmeno'] ?? null)
                     : ($_SESSION['admin_jmeno'] ?? ($_SESSION['admin_email'] ?? null));
                mena_platba_zapis($pdo, ['objednavka_id' => $id, 'zdroj' => 'qr', 'metoda' => 'hotovost', 'mena' => $dc['mena'],
                                         'castka_mena' => $dc['celkem'], 'kurz' => $dc['mena'] === 'CZK' ? 1.0 : (float) $dc['kurz'],
                                         'uzivatel' => $adm, 'idem' => 'manual:' . $id]);
            }
        } catch (Throwable $e) { error_log('admin_confirm_manual ledger: ' . $e->getMessage()); }
    }
    json_response(['ok' => true]);
}

// ─────────────────────────────────────────────────────────────
// POST ?action=stripe_webhook — Stripe → potvrdí platbu
// (Public — Stripe ho volá. Verifikace přes signature.)
// ─────────────────────────────────────────────────────────────
if ($method === 'POST' && $action === 'stripe_webhook') {
    // 🔒 v3.0.228 SECURITY: ověř Stripe-Signature (HMAC) proti tenant webhook secretu.
    //   secret nastaven → neplatný podpis = 400 · secret NEnastaven → grace (token v ref chrání)
    $payload = file_get_contents('php://input');
    $sig = $_SERVER['HTTP_STRIPE_SIGNATURE'] ?? '';
    $sigValid = customer_int_stripe_verify_signature($payload, $sig);
    if ($sigValid === false) {
        error_log('pay_qr stripe_webhook: NEPLATNÝ podpis from ' . ($_SERVER['REMOTE_ADDR'] ?? '?'));
        json_error('invalid signature', 400);
    }
    if ($sigValid === null) {
        error_log('⚠️ pay_qr stripe_webhook: webhook secret nenastaven — grace (ověřuji jen token v ref)');
    }
    $event = json_decode($payload, true);
    if (!$event) json_error('Bad payload', 400);
    // 💱 v3.0.643 — PAY-/UCET-/B2B- reference; potvrdí jen při shodě částky A měny (jinak 'failed')
    try { $res = online_platba_stripe_event($pdo, $event, $sigValid === true); }
    catch (Throwable $e) { error_log('pay_qr stripe_webhook: ' . $e->getMessage()); json_error('Chyba zpracování', 500); }
    json_response(['ok' => true, 'stav' => $res['stav']]);
}

// ─────────────────────────────────────────────────────────────
// GET/POST ?action=gopay_callback — GoPay async notifikace → ověř stav → potvrď platbu
//   (🆕 v3.0.323 — dřív handler chyběl → 400. GoPay pošle ?id=<payment_id>; stav OVĚŘÍME
//   dotazem na GoPay API, ne slepou důvěrou. order_number = PAY-<id>-<token> jako u Stripe.)
// ─────────────────────────────────────────────────────────────
if ($action === 'gopay_callback') {
    $gpId = (int)($_GET['id'] ?? $_POST['id'] ?? 0);
    if (!$gpId) json_error('Chybí id', 400);
    // 💱 v3.0.643 — stav i částku/měnu ověří dotaz na GoPay; nesedí → 'failed' (viz _online_platba_lib)
    try { $res = online_platba_gopay($pdo, $gpId); }
    catch (Throwable $e) { error_log('pay_qr gopay_callback: ' . $e->getMessage()); $res = ['stav' => 'chyba']; }
    json_response(['ok' => true, 'stav' => $res['stav']]); // GoPay očekává 200
}

json_error('Neznámá akce: ' . $action, 400);
