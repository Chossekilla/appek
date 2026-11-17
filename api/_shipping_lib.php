<?php
/**
 * 🆕 v3.0.329 — Jednotný shipping framework: vytvoření zásilky + štítek napříč přepravci.
 *   Dispatch na customer_int_<service>_*; normalizuje výstup na {ok, tracking, label_url, ext_id}.
 *   Služby (= klíče integrace): zas (Zásilkovna), dpd, ppl, cp (Česká pošta). zas+dpd hotové; ppl+cp = v330.
 */
require_once __DIR__ . '/_customer_integrace.php';

function shipping_supported_carriers(): array { return ['zas', 'dpd', 'ppl', 'cp']; }

function shipping_carrier_label(string $c): string {
    return ['zas' => 'Zásilkovna', 'dpd' => 'DPD', 'ppl' => 'PPL', 'cp' => 'Česká pošta'][$c] ?? $c;
}

// Přepravce dostupný = zapnutá integrace (klíče v Nastavení → Integrace) + existuje create adaptér.
function shipping_carrier_enabled(string $c): bool {
    if (!function_exists('customer_int_enabled') || !customer_int_enabled($c)) return false;
    return function_exists(shipping_create_fn($c));
}

function shipping_create_fn(string $c): string {
    return $c === 'zas' ? 'customer_int_zas_create_packet' : 'customer_int_' . $c . '_create_shipment';
}
function shipping_label_fn(string $c): string {
    return 'customer_int_' . $c . '_label';
}

// Vytvoř zásilku. $payload = {reference, recipient_*, weight_kg, value_kc, cod_kc, pickup_point_id}.
// Vrací normalizované {ok, tracking, label_url, ext_id, raw}.
function shipping_create(string $carrier, array $payload): array {
    if (!in_array($carrier, shipping_supported_carriers(), true)) return ['ok' => false, 'error' => 'unknown_carrier'];
    if (!function_exists('customer_int_enabled') || !customer_int_enabled($carrier)) {
        return ['ok' => false, 'error' => $carrier . '_disabled', 'hint' => 'Zapni přepravce + klíče v Nastavení → Integrace.'];
    }
    // 💱 v3.0.643 — dobírka (COD) jde u přepravců jen v Kč → u dokladu v cizí měně ji nezakládej
    //   (zákazník by zaplatil Kč částku místo eur). Měnu bere z payloadu, jinak z objednávky dle čísla.
    if ((float) ($payload['cod_kc'] ?? 0) > 0 && shipping_mena_dokladu($payload) !== 'CZK') {
        return ['ok' => false, 'error' => 'dobirka_jen_czk', 'hint' => 'Objednávka je v ' . shipping_mena_dokladu($payload) . ' — dobírku přepravci vybírají jen v Kč. Zásilku založ bez dobírky (zaplaceno předem).'];
    }
    $fn = shipping_create_fn($carrier);
    if (!function_exists($fn)) return ['ok' => false, 'error' => 'carrier_not_implemented', 'hint' => 'Přepravce ' . shipping_carrier_label($carrier) . ' zatím není napojen.'];
    $r = $fn($payload);
    if (!($r['ok'] ?? false)) return $r;
    $tracking = $r['tracking'] ?? $r['tracking_number'] ?? $r['trackingNumber'] ?? $r['packet_id'] ?? $r['barcode'] ?? $r['shipmentId'] ?? $r['id'] ?? null;
    $label    = $r['label_url'] ?? $r['labelUrl'] ?? null;
    $extId    = $r['ext_id'] ?? $r['packet_id'] ?? $r['id'] ?? $tracking;
    return ['ok' => true, 'tracking' => $tracking !== null ? (string) $tracking : null, 'label_url' => $label, 'ext_id' => $extId !== null ? (string) $extId : null, 'raw' => $r];
}

// Vrátí štítek (PDF). Adaptér customer_int_<c>_label vrací {ok, pdf_base64|url}. Pokud neexistuje → not_implemented.
function shipping_label(string $carrier, string $tracking): array {
    if (!function_exists('customer_int_enabled') || !customer_int_enabled($carrier)) return ['ok' => false, 'error' => $carrier . '_disabled'];
    $fn = shipping_label_fn($carrier);
    if (!function_exists($fn)) return ['ok' => false, 'error' => 'carrier_label_not_implemented'];
    return $fn($tracking);
}

/** 💱 v3.0.643 — měna dokladu zásilky: payload['mena'] | objednávka (id / číslo = reference). Default Kč. */
function shipping_mena_dokladu(array $payload): string {
    if (!empty($payload['mena'])) return strtoupper((string) $payload['mena']);
    try {
        $pdo = db();
        if (!empty($payload['objednavka_id'])) {
            $st = $pdo->prepare("SELECT mena FROM objednavky WHERE id = ?");
            $st->execute([(int) $payload['objednavka_id']]);
        } elseif (!empty($payload['reference'])) {
            $st = $pdo->prepare("SELECT mena FROM objednavky WHERE cislo = ? LIMIT 1");
            $st->execute([(string) $payload['reference']]);
        } else {
            return 'CZK';
        }
        $m = strtoupper((string) $st->fetchColumn());
        return $m !== '' ? $m : 'CZK';
    } catch (Throwable $e) { return 'CZK'; }
}
