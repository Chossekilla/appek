<?php
/**
 * 💳 PLATBY & DOPRAVA — konfigurační vrstva (v3.0.272).
 *
 * Settings keys v `nastaveni`:
 *   payment_methods_json        = { key: bool }   — enabled (viz payment_methods.php)
 *   payment_methods_config_json = { key: { poplatek, poplatek_typ:'kc'|'pct', splatnost_dni, ucet_iban } }
 *   doprava_config_json         = { zdarma_od: float|null, metody: { key: {nazev, cena, aktivni} } }
 *
 * Příplatky (dobírka poplatek + doprava) se promítají do CELKEM objednávky
 *   server-side při vytvoření (B2B objednavky.php, admin_objednavky.php, POS).
 */

if (!defined('DOPRAVA_METODY_MASTER')) {
    define('DOPRAVA_METODY_MASTER', [
        'rozvoz'     => ['nazev' => 'Rozvoz na adresu',       'cena' => 0],
        'kuryr'      => ['nazev' => 'Kurýr',                  'cena' => 0],
        'vlastni'    => ['nazev' => 'Vlastní odvoz / pickup', 'cena' => 0],
        'zasilkovna' => ['nazev' => 'Zásilkovna',             'cena' => 0],
        'dpd'        => ['nazev' => 'DPD CZ',                 'cena' => 0],
    ]);
}

/** Načti config plateb + dopravy (master + uložené overrides). */
function platby_config_load(PDO $pdo): array {
    $get = function (string $klic) use ($pdo): array {
        try {
            $st = $pdo->prepare("SELECT hodnota FROM nastaveni WHERE klic = :k LIMIT 1");
            $st->execute(['k' => $klic]);
            $j = $st->fetchColumn();
            $a = $j ? json_decode($j, true) : [];
            return is_array($a) ? $a : [];
        } catch (Throwable $e) { return []; }
    };
    $cfg = $get('payment_methods_config_json');
    $dop = $get('doprava_config_json');

    $metody = [];
    foreach (DOPRAVA_METODY_MASTER as $k => $m) {
        $o = $dop['metody'][$k] ?? [];
        $metody[$k] = [
            'key'     => $k,
            'nazev'   => $o['nazev'] ?? $m['nazev'],
            'cena'    => isset($o['cena']) && $o['cena'] !== '' ? (float) $o['cena'] : (float) $m['cena'],
            'aktivni' => array_key_exists('aktivni', $o) ? (bool) $o['aktivni'] : true,
        ];
    }
    return [
        'methods_config' => $cfg,
        'doprava' => [
            'zdarma_od' => (isset($dop['zdarma_od']) && $dop['zdarma_od'] !== '' && $dop['zdarma_od'] !== null) ? (float) $dop['zdarma_od'] : null,
            'metody'    => $metody,
        ],
    ];
}

/** Ulož config (jen poslané sekce). */
function platby_config_save(PDO $pdo, ?array $methodsConfig, ?array $doprava): void {
    $put = function (string $klic, array $val) use ($pdo): void {
        $json = json_encode($val, JSON_UNESCAPED_UNICODE);
        $pdo->prepare("INSERT INTO nastaveni (klic, hodnota) VALUES (:k, :v) ON DUPLICATE KEY UPDATE hodnota = :v2")
            ->execute(['k' => $klic, 'v' => $json, 'v2' => $json]);
    };
    if (is_array($methodsConfig)) {
        // sanitizuj: jen známé klíče + bezpečné hodnoty
        $clean = [];
        foreach ($methodsConfig as $key => $c) {
            if (!is_array($c)) continue;
            $row = [];
            if (isset($c['poplatek']) && $c['poplatek'] !== '') $row['poplatek'] = max(0, (float) $c['poplatek']);
            $row['poplatek_typ'] = (($c['poplatek_typ'] ?? 'kc') === 'pct') ? 'pct' : 'kc';
            if (isset($c['splatnost_dni']) && $c['splatnost_dni'] !== '') $row['splatnost_dni'] = max(0, min(365, (int) $c['splatnost_dni']));
            if (isset($c['ucet_iban'])) $row['ucet_iban'] = substr(trim((string) $c['ucet_iban']), 0, 60);
            if ($row) $clean[preg_replace('/[^a-z_]/', '', (string) $key)] = $row;
        }
        $put('payment_methods_config_json', $clean);
    }
    if (is_array($doprava)) {
        $clean = ['metody' => []];
        if (array_key_exists('zdarma_od', $doprava)) {
            $clean['zdarma_od'] = ($doprava['zdarma_od'] === '' || $doprava['zdarma_od'] === null) ? null : max(0, (float) $doprava['zdarma_od']);
        }
        foreach (($doprava['metody'] ?? []) as $key => $m) {
            $k = preg_replace('/[^a-z_]/', '', (string) $key);
            if (!isset(DOPRAVA_METODY_MASTER[$k]) || !is_array($m)) continue;
            $clean['metody'][$k] = [
                'nazev'   => substr(trim((string) ($m['nazev'] ?? DOPRAVA_METODY_MASTER[$k]['nazev'])), 0, 60),
                'cena'    => max(0, (float) ($m['cena'] ?? 0)),
                'aktivni' => array_key_exists('aktivni', $m) ? (bool) $m['aktivni'] : true,
            ];
        }
        $put('doprava_config_json', $clean);
    }
}

/**
 * Spočítej příplatky pro objednávku.
 * @param float $subtotal Částka zboží S DPH PŘED příplatky.
 * @return array { platba_poplatek, doprava_cena, doprava_zdarma, splatnost_dni, priplatky_celkem }
 */
function platby_surcharges(PDO $pdo, string $payment, ?string $shipping, float $subtotal): array {
    $cfg = platby_config_load($pdo);
    $pc = $cfg['methods_config'][$payment] ?? [];

    $popl = 0.0;
    if (!empty($pc['poplatek'])) {
        $popl = (($pc['poplatek_typ'] ?? 'kc') === 'pct')
            ? round($subtotal * (float) $pc['poplatek'] / 100, 2)
            : (float) $pc['poplatek'];
    }

    $dCena = 0.0; $dZdarma = false;
    if ($shipping && isset($cfg['doprava']['metody'][$shipping])) {
        $dCena = (float) $cfg['doprava']['metody'][$shipping]['cena'];
        $prah = $cfg['doprava']['zdarma_od'];
        if ($prah !== null && $subtotal >= $prah) { $dCena = 0.0; $dZdarma = true; }
    }

    return [
        'platba_poplatek'  => round($popl, 2),
        'doprava_cena'     => round($dCena, 2),
        'doprava_zdarma'   => $dZdarma,
        'splatnost_dni'    => (isset($pc['splatnost_dni']) && $pc['splatnost_dni'] !== '') ? (int) $pc['splatnost_dni'] : null,
        'priplatky_celkem' => round($popl + $dCena, 2),
    ];
}
