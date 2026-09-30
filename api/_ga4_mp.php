<?php
/**
 * 📈 GA4 Measurement Protocol — SERVEROVÉ odeslání události „purchase".
 *
 * Pošle prodej přímo ze serveru do GA4 ve chvíli, kdy je objednávka zaplacená —
 * NEZÁVISLE na prohlížeči, cookies i souhlasu (na rozdíl od gtag v <head>).
 * → KAŽDÝ zaplacený prodej se započítá, i když návštěvník cookies odmítl / má adblock.
 *
 * Oficiální postup: https://developers.google.com/analytics/devguides/collection/protocol/ga4/sending-events
 *   POST https://www.google-analytics.com/mp/collect?measurement_id=…&api_secret=…
 *   body: {client_id, events:[{name:'purchase', params:{transaction_id,value,currency,items}}]}
 *
 * Config (server-side, z vendor_settings — NEexponovat api_secret klientovi):
 *   google_ga4_id         … Measurement ID G-XXXXXXXXXX  (už nastaveno pro landing)
 *   google_ga4_mp_secret  … Measurement Protocol API secret
 *                           (GA4 → Admin → Datové proudy → web → „Measurement Protocol API secrets" → Vytvořit)
 *
 * Fail-safe: cokoli chybí/selže → tiše nic (měření NIKDY neshodí objednávku).
 * Idempotence: voláno jen ve větvi „právě zaplaceno"; GA4 navíc dedupuje dle transaction_id (~stejný client_id).
 */

require_once __DIR__ . '/../vendor/_mail.php'; // vendor_mail_settings()

if (!function_exists('ga4_mp_purchase')) {
    /**
     * @param array $order řádek vendor_shop_orders (order_no, total_kc, packages_json)
     * @param bool  $debug true → pošle na /debug/mp/collect a VRÁTÍ odpověď (validace); jinak vrací ''
     */
    function ga4_mp_purchase(array $order, bool $debug = false): string
    {
        try {
            $cfg    = function_exists('vendor_mail_settings') ? vendor_mail_settings() : [];
            $mid    = trim((string) ($cfg['google_ga4_id'] ?? ''));
            $secret = trim((string) ($cfg['google_ga4_mp_secret'] ?? ''));
            if ($mid === '' || $secret === '') return $debug ? 'NOT_CONFIGURED (chybí google_ga4_id nebo google_ga4_mp_secret)' : '';

            $orderNo = (string) ($order['order_no'] ?? '');
            if ($orderNo === '') return $debug ? 'NO_ORDER_NO' : '';
            $value = round((float) ($order['total_kc'] ?? 0), 2);

            // client_id v GA4 formátu "int.int", stabilní per objednávka → čistá deduplikace při retry
            $clientId = sprintf('%u.%u', crc32($orderNo), crc32('cid' . $orderNo) % 2147483647);

            // položky z balíčků objednávky
            $items = [];
            foreach ((json_decode($order['packages_json'] ?? '[]', true) ?: []) as $p) {
                if ($p === 'core') continue;
                $items[] = ['item_id' => (string) $p, 'item_name' => 'APPEK ' . $p, 'quantity' => 1];
            }
            if (!$items) {
                $items[] = ['item_id' => 'appek-license', 'item_name' => 'APPEK licence', 'quantity' => 1, 'price' => $value];
            }

            $payload = json_encode([
                'client_id' => $clientId,
                'events' => [[
                    'name' => 'purchase',
                    'params' => [
                        // session_id + engagement_time_msec → GA4 událost připíše k session
                        // a ukáže ji v Realtime i standardních reportech (doporučeno pro MP)
                        'session_id'           => (string) sprintf('%u', crc32('sess' . $orderNo)),
                        'engagement_time_msec' => 100,
                        'transaction_id'       => $orderNo,
                        'value'                => $value,
                        'currency'             => 'CZK',
                        'items'                => $items,
                    ],
                ]],
            ], JSON_UNESCAPED_UNICODE);

            $base = $debug
                ? 'https://www.google-analytics.com/debug/mp/collect'
                : 'https://www.google-analytics.com/mp/collect';
            $url = $base . '?measurement_id=' . rawurlencode($mid) . '&api_secret=' . rawurlencode($secret);

            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_POST           => true,
                CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
                CURLOPT_POSTFIELDS     => $payload,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT        => 5,
            ]);
            $resp = curl_exec($ch);
            $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            return $debug ? ('HTTP ' . $code . ' ' . (string) $resp) : '';
        } catch (Throwable $e) {
            error_log('ga4_mp_purchase: ' . $e->getMessage());
            return $debug ? ('EXCEPTION ' . $e->getMessage()) : '';
        }
    }
}
