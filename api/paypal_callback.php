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

$ourOrderNo = trim($_GET['order'] ?? '');
$paypalOrderId = trim($_GET['token'] ?? '');  // PayPal posílá ho jako 'token'
$baseUrl = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' ? 'https' : 'http')
         . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost');

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
        // Mark paid v objednavky (sloupec uhrazena_kc / stav)
        try {
            $pdo->prepare("UPDATE objednavky SET stav = 'potvrzena', interni_pozn = CONCAT(IFNULL(interni_pozn,''), '\nPayPal CAPTURED: " . ($r['id'] ?? '') . "') WHERE id = :id")
                ->execute(['id' => $order['id']]);
        } catch (Throwable $e) { /* OK pokud sloupec nemá tu stav hodnotu */ }
        header('Location: ' . $baseUrl . '/b2b/index.html?paid=' . urlencode($ourOrderNo));
        exit;
    }

    error_log('paypal_callback capture failed: ' . json_encode($r));
    header('Location: ' . $baseUrl . '/b2b/index.html?cancelled=' . urlencode($ourOrderNo));
    exit;
} catch (Throwable $e) {
    error_log('paypal_callback: ' . $e->getMessage());
    header('Location: ' . $baseUrl . '/b2b/index.html?cancelled=' . urlencode($ourOrderNo));
    exit;
}
