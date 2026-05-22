<?php
/**
 * 🐝 FLEXIBEE (ABRA Flexi) — REST API klient pro https://demo.flexibee.eu/devdoc/.
 *
 * REST endpoint pattern:
 *   GET    /c/{firma}/{evidence}/{kod}.json    → načti záznam
 *   PUT    /c/{firma}/{evidence}.json          → create/update (winstrom envelope)
 *   GET    /c/{firma}/{evidence}.json          → list
 *   DELETE /c/{firma}/{evidence}/{kod}         → smaž
 *
 * Authentication: Basic Auth (uživatel + heslo) NEBO Bearer token (od FlexiBee Cloud).
 *
 * Klíčové evidence:
 *   - faktura-vydana  (vydané FA)
 *   - faktura-prijata (přijaté FA)
 *   - adresar         (partneři)
 *   - cenik           (zboží/zásoby)
 *   - skladovy-doklad (DL)
 *
 * Config v nastaveni (klíče flexibee_*):
 *   flexibee_enabled    '1'/'0'
 *   flexibee_url        https://muj-server.flexibee.eu:5434
 *   flexibee_company    kod_firmy_v_flexibee   (firma slug v URL)
 *   flexibee_username   user
 *   flexibee_password   pass
 */

require_once __DIR__ . '/config.php';

function flexibee_settings(): array {
    static $cache = null;
    if ($cache !== null) return $cache;
    $pdo = db();
    $keys = ['enabled','url','company','username','password'];
    $cache = [];
    foreach ($keys as $k) {
        $cache['flexibee_' . $k] = nastaveni_get($pdo, 'flexibee_' . $k, '');
    }
    return $cache;
}

function flexibee_is_enabled(): bool {
    $cfg = flexibee_settings();
    return $cfg['flexibee_enabled'] === '1'
        && $cfg['flexibee_url']
        && $cfg['flexibee_company']
        && $cfg['flexibee_username'];
}

/**
 * Sestaví base URL pro konkrétní firmu.
 */
function flexibee_base_url(): string {
    $cfg = flexibee_settings();
    return rtrim($cfg['flexibee_url'], '/') . '/c/' . rawurlencode($cfg['flexibee_company']);
}

/**
 * Univerzální FlexiBee request.
 *
 * @param string $method  GET, PUT, POST, DELETE
 * @param string $path    "/faktura-vydana.json" nebo "/adresar/MUJ-KOD"
 * @param array|null $body  pro PUT — bude obaleno do winstrom envelope
 * @param array $params   query params (např. filter)
 */
function flexibee_request(string $method, string $path, ?array $body = null, array $params = []): array {
    $cfg = flexibee_settings();
    if (!flexibee_is_enabled()) {
        return ['ok' => false, 'error' => 'flexibee_disabled'];
    }
    $url = flexibee_base_url() . $path;
    if ($params) $url .= '?' . http_build_query($params);

    $ch = curl_init($url);
    $opts = [
        CURLOPT_CUSTOMREQUEST  => $method,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 60,
        CURLOPT_CONNECTTIMEOUT => 15,
        CURLOPT_HTTPHEADER     => [
            'Accept: application/json',
            'Content-Type: application/json',
        ],
        CURLOPT_USERPWD        => $cfg['flexibee_username'] . ':' . $cfg['flexibee_password'],
        CURLOPT_SSL_VERIFYPEER => false, // FlexiBee často self-signed
    ];
    if ($body !== null) {
        $opts[CURLOPT_POSTFIELDS] = json_encode(['winstrom' => $body], JSON_UNESCAPED_UNICODE);
    }
    curl_setopt_array($ch, $opts);
    $resp = curl_exec($ch);
    $http = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err  = curl_error($ch);
    curl_close($ch);
    $data = json_decode($resp, true);
    if ($resp === false || $http >= 400) {
        $errMsg = $err ?: ('http_' . $http);
        if (is_array($data) && !empty($data['winstrom']['results'])) {
            $errMsg = $data['winstrom']['results'][0]['errors'][0]['message'] ?? $errMsg;
        }
        return ['ok' => false, 'error' => $errMsg, 'http' => $http, 'response' => $data];
    }
    return ['ok' => true, 'http' => $http, 'data' => $data['winstrom'] ?? $data];
}

/**
 * Test connection — pokus o GET /info.json (vrátí firmu).
 */
function flexibee_test_connection(): array {
    $cfg = flexibee_settings();
    if (!flexibee_is_enabled()) {
        return ['ok' => false, 'error' => 'FlexiBee není aktivní v Nastavení (chybí URL, company, username nebo enabled).'];
    }
    $r = flexibee_request('GET', '/firma.json');
    if (!$r['ok']) return $r;
    $info = $r['data']['firma'][0] ?? null;
    return [
        'ok' => true,
        'message' => 'Spojení OK · firma: ' . ($info['nazev'] ?? '—') . ($info['ic'] ? ' (IČ ' . $info['ic'] . ')' : ''),
        'firma' => $info,
    ];
}

/**
 * Export 1 vydané faktury do FlexiBee.
 */
function flexibee_export_faktura(array $faktura): array {
    $polozky = $faktura['polozky'] ?? [];
    $polozkyXml = array_map(function ($p) {
        $dphPct = (int) ($p['dph_pct'] ?? 21);
        $sazba = $dphPct == 21 ? 'typSzbDph.dphZakl' : ($dphPct == 15 ? 'typSzbDph.dphSniz' : ($dphPct == 10 ? 'typSzbDph.dphDruhaSniz' : 'typSzbDph.dphOsv'));
        return [
            'nazev'        => $p['nazev'] ?? 'Položka',
            'mnozMj'       => (float) ($p['mnozstvi'] ?? 1),
            'mj'           => 'code:KS',
            'cenaMj'       => (float) ($p['cena_bez_dph'] ?? 0),
            'typSzbDphK'   => $sazba,
        ];
    }, $polozky);

    $body = [
        'faktura-vydana' => [
            [
                'kod'              => 'APPEK-' . preg_replace('/[^A-Z0-9]/i', '', $faktura['cislo'] ?? ''),
                'cisDosle'         => $faktura['cislo'] ?? null,
                'varSym'           => preg_replace('/[^0-9]/', '', $faktura['cislo'] ?? ''),
                'datVyst'          => $faktura['datum_vystaveni'] ?? date('Y-m-d'),
                'duzpPuv'          => $faktura['datum_zd_plneni'] ?? $faktura['datum_vystaveni'] ?? date('Y-m-d'),
                'duzpUcto'         => $faktura['datum_vystaveni'] ?? date('Y-m-d'),
                'datSplat'         => $faktura['datum_splatnosti'] ?? null,
                'popis'            => $faktura['poznamka'] ?? null,
                'sumZklZakl'       => (float) ($faktura['celkem_bez_dph'] ?? 0),
                'sumDphZakl'       => (float) ($faktura['celkem_dph'] ?? 0),
                'sumCelkem'        => (float) ($faktura['celkem_s_dph'] ?? 0),
                'firma'            => 'ext:APPEK-PARTNER:' . preg_replace('/[^A-Z0-9]/i', '', $faktura['odberatel']['ic'] ?? 'unknown'),
                'nazFirmy'         => $faktura['odberatel']['nazev'] ?? '',
                'ulice'            => $faktura['odberatel']['ulice'] ?? '',
                'mesto'            => $faktura['odberatel']['mesto'] ?? '',
                'psc'              => $faktura['odberatel']['psc'] ?? '',
                'ic'               => $faktura['odberatel']['ic'] ?? '',
                'dic'              => $faktura['odberatel']['dic'] ?? '',
                'polozkyFaktury'   => $polozkyXml,
            ],
        ],
    ];
    return flexibee_request('PUT', '/faktura-vydana.json', $body);
}

/**
 * Export partnera do adresáře.
 */
function flexibee_export_partner(array $p): array {
    $kod = 'APPEK-PARTNER:' . preg_replace('/[^A-Z0-9]/i', '', $p['ic'] ?? 'unknown');
    $body = [
        'adresar' => [
            [
                'kod'      => $kod,
                'nazev'    => $p['nazev'] ?? '',
                'ic'       => $p['ic'] ?? null,
                'dic'      => $p['dic'] ?? null,
                'ulice'    => $p['ulice'] ?? null,
                'mesto'    => $p['mesto'] ?? null,
                'psc'      => $p['psc'] ?? null,
                'email'    => $p['email'] ?? null,
                'tel'      => $p['telefon'] ?? null,
                'poznam'   => 'APPEK B2B sync',
            ],
        ],
    ];
    return flexibee_request('PUT', '/adresar.json', $body);
}

/**
 * Export ceníkové položky (výrobku).
 */
function flexibee_export_vyrobek(array $v): array {
    $kod = 'APPEK-V:' . preg_replace('/[^A-Z0-9]/i', '', $v['cislo'] ?? $v['nazev'] ?? '');
    $body = [
        'cenik' => [
            [
                'kod'        => $kod,
                'nazev'      => $v['nazev'] ?? '',
                'cenaZakl'   => (float) ($v['cena_bez_dph'] ?? 0),
                'mj'         => 'code:KS',
                'typSzbDphK' => 'typSzbDph.dphZakl',
                'poznam'     => $v['popis'] ?? null,
            ],
        ],
    ];
    return flexibee_request('PUT', '/cenik.json', $body);
}

/**
 * List přijatých faktur (import).
 */
function flexibee_list_prijate(string $from, string $to): array {
    $filter = sprintf("datVyst >= '%s' and datVyst <= '%s'", $from, $to);
    return flexibee_request('GET', '/faktura-prijata.json', null, [
        'limit'  => 100,
        'detail' => 'full',
        '$filter'=> $filter,
    ]);
}
