<?php
/**
 * 🗓️ v3.0.597 — PRODLOUŽENÍ licence (my.appek.cz) — sdílené pro platbu kartou i převodem.
 *
 *   • api/gopay_callback.php  → po PAID (karta / Apple Pay / Google Pay)
 *   • vendor/shop.php         → „Označit zaplaceno" (bankovní převod)
 *
 * Objednávka s renew_license_id posune expiraci STÁVAJÍCÍ licence o rental_months měsíců
 * (žádný nový klíč) — od pozdějšího z (dnes, stávající expirace), zbytek nepropadne.
 * Typ licence se NEMĚNÍ: pronájem zůstane pronájmem, roční (12 měs.) zůstane roční.
 * Idempotentní: objednávka, která už má license_id, se nepřičte podruhé.
 *
 * Vyžaduje vendor/_lib.php (vendor_audit) a vendor/_mail.php (vendor_send_mail).
 */

/** Provede prodloužení. Vrací ['months','expires_at','license'] nebo null (není renewal / už provedeno). */
function vendor_apply_renewal(PDO $pdo, array $order, string $actor = 'system'): ?array {
    $renewId = (int) ($order['renew_license_id'] ?? 0);
    if ($renewId <= 0) return null;
    if (!empty($order['license_id'])) return null;           // už prodlouženo (idempotence)
    $months = max(1, (int) ($order['rental_months'] ?? 1));

    $pdo->beginTransaction();
    try {
        // zámek objednávky — souběh callbacku a ručního „zaplaceno" nesmí prodloužit dvakrát
        $st = $pdo->prepare("SELECT license_id FROM vendor_shop_orders WHERE id = :id FOR UPDATE");
        $st->execute(['id' => $order['id']]);
        if ((int) $st->fetchColumn() > 0) { $pdo->commit(); return null; }
        $pdo->prepare("UPDATE vendor_licenses
            SET expires_at = DATE_ADD(GREATEST(CURDATE(), COALESCE(expires_at, CURDATE())), INTERVAL :m MONTH),
                status = 'active'
            WHERE id = :id")->execute(['m' => $months, 'id' => $renewId]);
        $pdo->prepare("UPDATE vendor_shop_orders SET license_id = :lid WHERE id = :oid")
            ->execute(['lid' => $renewId, 'oid' => $order['id']]);
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }

    $lic = $pdo->prepare("SELECT * FROM vendor_licenses WHERE id = :id LIMIT 1");
    $lic->execute(['id' => $renewId]);
    $licRow = $lic->fetch() ?: [];
    $newExp = !empty($licRow['expires_at']) ? date('j. n. Y', strtotime($licRow['expires_at'])) : '';
    $isRental = !empty($licRow['rental']);
    $obdobi = $isRental ? ($months . ' ' . ($months === 1 ? 'měsíc' : ($months < 5 ? 'měsíce' : 'měsíců')))
                        : ($months % 12 === 0 ? ($months / 12 === 1 ? '1 rok' : ($months / 12) . ' roky') : $months . ' měs.');
    $co = $isRental ? 'pronájem APPEK' : 'licence APPEK';

    try {
        $to = $order['customer_email'] ?: ($licRow['customer_email'] ?? '');
        if ($to && function_exists('vendor_send_mail')) {
            $html = '<div style="font-family:-apple-system,Segoe UI,Arial,sans-serif;max-width:520px;margin:0 auto;padding:24px;color:#1d1d1f">'
                . '<h2 style="margin:0 0 10px">Prodlouženo do ' . htmlspecialchars($newExp) . '</h2>'
                . '<p style="color:#3a3a3c;line-height:1.6">Děkujeme, platba za objednávku <strong>' . htmlspecialchars((string) $order['order_no']) . '</strong> je přijata. '
                . 'Vaše ' . $co . ' je prodloužena o <strong>' . htmlspecialchars($obdobi) . '</strong>.</p>'
                . '<p style="color:#3a3a3c">Nově platí do <strong>' . htmlspecialchars($newExp) . '</strong>. Aplikace si novou platnost načte sama do pár minut.</p>'
                . '<p style="font-size:13px;color:#86868b">Stav a historii plateb najdete na <a href="https://my.appek.cz" style="color:#BA7517">my.appek.cz</a>.</p></div>';
            vendor_send_mail($to, 'APPEK — prodlouženo do ' . $newExp, $html,
                "Platba přijata. {$co} prodloužena o {$obdobi}, platí do {$newExp}. Stav: https://my.appek.cz");
        }
    } catch (Throwable $e) { error_log('vendor_apply_renewal mail: ' . $e->getMessage()); }
    try {
        if (function_exists('vendor_send_admin_notification')) {
            vendor_send_admin_notification($order, ['license_key' => $licRow['license_key'] ?? '—'], 'payment');
        }
    } catch (Throwable $e) { error_log('vendor_apply_renewal admin-notif: ' . $e->getMessage()); }
    if (function_exists('vendor_audit')) {
        vendor_audit($pdo, ['username' => $actor], 'shop_renew_paid', ['id' => $renewId, 'months' => $months], (string) $order['order_no']);
    }
    return ['months' => $months, 'expires_at' => $licRow['expires_at'] ?? null, 'license' => $licRow];
}
