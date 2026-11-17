<?php
/**
 * 💼 PAYPAL CALLBACK — return URL po PayPal approve.
 *
 * Flow:
 *   1. /api/objednavky.php POST vytvoří PayPal order → vrátí approve_url
 *   2. Customer klikne, schválí na paypal.com
 *   3. PayPal redirect na ?token={order_id}&PayerID={payer_id}&order={our_order_no}
 *   4. Sem zavoláme capture → marker order paid → redirect na /b2b/?paid=...
 *
 * GET /api/paypal_callback.php?order=OBJ-...&token=PAYPAL_ORDER_ID&PayerID=...
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/_customer_integrace.php';
require_once __DIR__ . '/_online_platba_lib.php';   // 💱 v3.0.643 — ověření částky + měny, ledger

$ourOrderNo = trim($_GET['order'] ?? '');
$paypalOrderId = trim($_GET['token'] ?? '');  // PayPal posílá ho jako 'token'
// 🐛 v3.0.644 — základ vč. podsložky instalace (jinak redirect na /b2b/ kořene domény = 404)
$baseUrl = rtrim(app_public_url(''), '/');

if (!$ourOrderNo || !$paypalOrderId) {
    header('Location: ' . $baseUrl . '/b2b/index.html?cancelled=' . urlencode($ourOrderNo ?: 'unknown'));
    exit;
}

try {
    $pdo = db();
    $stmt = $pdo->prepare("SELECT id, cislo, castka_celkem FROM objednavky WHERE cislo = :c LIMIT 1");
    $stmt->execute(['c' => $ourOrderNo]);
    $order = $stmt->fetch();
    if (!$order) {
        header('Location: ' . $baseUrl . '/b2b/index.html?cancelled=' . urlencode($ourOrderNo));
        exit;
    }

    // Capture PayPal order
    $r = customer_int_paypal_capture_order($paypalOrderId);
    $captureOk = ($r['ok'] ?? false) && (($r['status'] ?? '') === 'COMPLETED');

    // 🔒 v3.0.352 — capture MUSÍ patřit k NAŠÍ objednávce: reference_id se nastavuje při create
    //   na naše číslo objednávky. Bez kontroly šlo zaplatit vlastní levný PayPal order
    //   a potvrdit cizí drahou objednávku (order-swap). reference_id útočník nepodvrhne.
    if ($captureOk) {
        $refId = $r['purchase_units'][0]['reference_id'] ?? '';
        if ($refId !== $ourOrderNo) {
            error_log("paypal_callback: reference_id '$refId' != objednávka '$ourOrderNo' — odmítnuto");
            $captureOk = false;
        }
    }

    if ($captureOk) {
        // 💱 v3.0.643 — zaplacená částka + měna z capture MUSÍ sedět s očekávanou platbou
        //   (pay_castka/pay_mena zapsané při vytvoření PayPal order). Nesedí → 'failed', ne zaplaceno.
        $cap = $r['purchase_units'][0]['payments']['captures'][0]['amount'] ?? null;
        if (!is_array($cap) || !isset($cap['value'], $cap['currency_code'])) {
            error_log('paypal_callback: capture bez částky — odmítnuto');
            $captureOk = false;
        } else {
            $res = online_platba_potvrd($pdo, ['typ' => 'objednavky', 'id' => (int) $order['id'], 'minor' => (int) round((float) $cap['value'] * 100),
                                               'mena' => (string) $cap['currency_code'], 'brana' => 'paypal', 'ext_id' => (string) ($r['id'] ?? $paypalOrderId), 'zdroj' => 'online']);
            if ($res['stav'] === 'paid' || $res['stav'] === 'uz_zaplaceno') {
                try {
                    $pdo->prepare("UPDATE objednavky SET stav = 'potvrzena', interni_pozn = CONCAT(IFNULL(interni_pozn,''), :p) WHERE id = :id AND stav IN ('nova','novy','rozpracovano')")
                        ->execute(['p' => "\nPayPal CAPTURED: " . ($r['id'] ?? ''), 'id' => $order['id']]);
                } catch (Throwable $e) { /* OK pokud sloupec nemá tu stav hodnotu */ }
                header('Location: ' . $baseUrl . '/b2b/index.html?paid=' . urlencode($ourOrderNo));
                exit;
            }
            error_log('paypal_callback: platba nesedí (' . ($res['duvod'] ?? $res['stav']) . ') ' . $ourOrderNo);
            $captureOk = false;
        }
    }

    error_log('paypal_callback capture failed: ' . json_encode($r));
    header('Location: ' . $baseUrl . '/b2b/index.html?cancelled=' . urlencode($ourOrderNo));
    exit;
} catch (Throwable $e) {
    error_log('paypal_callback: ' . $e->getMessage());
    header('Location: ' . $baseUrl . '/b2b/index.html?cancelled=' . urlencode($ourOrderNo));
    exit;
}
