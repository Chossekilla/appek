<?php
/**
 * 🐝 ABRA Flexi (FlexiBee) — REST JSON klient + builder dokladů.
 *
 * REST: {url}/c/{firma}/{evidence}.json, Basic auth, JSON obálka {"winstrom":{"@version":"1.0", evidence:[…]}}.
 * Upsert přes externí ID: "id": ["ext:APPEK:fa-<id>"] — POST se stejným ext ID záznam AKTUALIZUJE,
 * takže opakovaný export nikdy nevytvoří duplicitu. Odběratel → adresar (ext:APPEK:odb-<id>),
 * výrobek → cenik (ext:APPEK:vyr-<id>).
 *
 * Mapování:
 *   faktura → faktura-vydana, typDokl = code:{flexibee_typ_faktura|FAKTURA}
 *   dobropis (DOB-) → faktura-vydana, typDokl = typ s typDoklK=typDokladu.dobropis
 *                     (nastavení flexibee_typ_dobropis, jinak auto: DOBROPIS / první dobropisový typ)
 *   sazby → typSzbDph.dphZakl (21 %) / dphSniz (12 %, 15 %) / dphSniz2 (10 %) / dphOsv (0 %)
 *   platba → formaUhradyCis code:PREVOD / HOTOVE / KARTA / DOBIRKA
 *   měna → code:CZK, u dokladu v cizí měně code:EUR… + kurz, ceny položek v ní (v3.0.640); zaokrouhlení dokladu vypnuto (APPEK nezaokrouhluje), DPH na haléře
 *
 * Builder funkce (flexi_build_*) jsou čisté → testovatelné bez sítě.
 */

require_once __DIR__ . '/_ucetni_lib.php';

const FLEXI_EXT = 'ext:APPEK:';

function flexi_cfg_chyba(array $cfg, bool $proOdeslani = true): ?string {
    if ($proOdeslani && ($cfg['flexibee_enabled'] ?? '') !== '1') return 'Integrace FlexiBee je vypnutá (Nastavení → Integrace).';
    if (($cfg['flexibee_url'] ?? '') === '') return 'Chybí adresa serveru FlexiBee.';
    if (($cfg['flexibee_company'] ?? '') === '') return 'Chybí kód firmy ve FlexiBee.';
    if (($cfg['flexibee_username'] ?? '') === '') return 'Chybí uživatel FlexiBee.';
    return null;
}

function flexi_rate(float $sazba): ?string {
    $s = round($sazba, 2);
    if ($s == 21.0) return 'typSzbDph.dphZakl';
    if ($s == 12.0 || $s == 15.0) return 'typSzbDph.dphSniz';
    if ($s == 10.0) return 'typSzbDph.dphSniz2';
    if ($s == 0.0) return 'typSzbDph.dphOsv';
    return null;
}

function flexi_forma_uhrady(string $kod): string {
    return ['hotove' => 'code:HOTOVE', 'karta' => 'code:KARTA', 'dobirka' => 'code:DOBIRKA'][$kod] ?? 'code:PREVOD';
}

function flexi_ext(string $typ, int $id): string { return FLEXI_EXT . $typ . '-' . $id; }

/** Adresář (odběratel) — upsert přes ext ID. */
function flexi_build_partner(array $o): array {
    $r = [
        'id'      => [flexi_ext('odb', (int) $o['id'])],
        'nazev'   => ucetni_clean($o['nazev'] ?? '', 255) ?: ('Odběratel ' . (int) $o['id']),
        'ulice'   => ucetni_clean($o['ulice'] ?? '', 255),
        'mesto'   => ucetni_clean($o['mesto'] ?? '', 255),
        'psc'     => ucetni_clean($o['psc'] ?? '', 255),
        'ic'      => ucetni_clean(preg_replace('/\s+/', '', (string) ($o['ico'] ?? '')), 20),
        'dic'     => ucetni_clean(preg_replace('/\s+/', '', (string) ($o['dic'] ?? '')), 20),
        'email'   => ucetni_clean($o['email'] ?? '', 255),
        'tel'     => ucetni_clean($o['telefon'] ?? '', 255),
        'poznam'  => 'APPEK odběratel #' . (int) $o['id'],
    ];
    if (isset($o['splatnost_dni']) && $o['splatnost_dni'] !== null && $o['splatnost_dni'] !== '') $r['splatDny'] = (int) $o['splatnost_dni'];
    return $r;
}

/** Ceník (výrobek) — upsert přes ext ID. $v = ['id','cislo','nazev','cena_bez_dph','sazba','ean'] */
function flexi_build_product(array $v): array {
    $r = [
        'id'          => [flexi_ext('vyr', (int) $v['id'])],
        'kod'         => ucetni_clean(($v['cislo'] ?? '') !== '' ? $v['cislo'] : 'APPEK-' . (int) $v['id'], 64),
        'nazev'       => ucetni_clean($v['nazev'] ?? '', 255) ?: ('Výrobek ' . (int) $v['id']),
        'cenaZakl'    => round((float) ($v['cena_bez_dph'] ?? 0), 2),
        'typCenyDphK' => 'typCeny.bezDph',
        'poznam'      => 'APPEK výrobek #' . (int) $v['id'],
    ];
    $rate = flexi_rate((float) ($v['sazba'] ?? 21));
    if ($rate) { $r['typSzbDphK'] = $rate; }
    $ean = preg_replace('/\D/', '', (string) ($v['ean'] ?? ''));
    if ($ean !== '' && strlen($ean) <= 20) $r['eanKod'] = $ean;
    return $r;
}

/**
 * Vydaná faktura / dobropis. $opt = ['typ_faktura'=>'FAKTURA','typ_dobropis'=>'DOBROPIS','mj'=>['KS'=>true,…]]
 * Vrací ['ok'=>true,'data'=>[…]] nebo ['ok'=>false,'error'=>česky].
 */
function flexi_build_invoice(array $inv, array $opt = []): array {
    $dob = !empty($inv['je_dobropis']);
    $typ = $dob ? ($opt['typ_dobropis'] ?? '') : (($opt['typ_faktura'] ?? '') ?: 'FAKTURA');
    if ($typ === '') return ['ok' => false, 'error' => 'Ve FlexiBee nebyl nalezen typ dokladu pro dobropis — doplň jeho kód v nastavení.'];
    if (mb_strlen($inv['cislo']) > 20) return ['ok' => false, 'error' => 'Číslo faktury je delší než 20 znaků (limit FlexiBee).'];
    $o = $inv['odberatel'];
    $popis = $dob ? 'Opravný daňový doklad ' . $inv['cislo'] . ($inv['puvodni_cislo'] ? ' k faktuře ' . $inv['puvodni_cislo'] : '')
                  : 'Faktura ' . $inv['cislo'];
    $poznam = 'APPEK ' . $inv['cislo'];
    if ($inv['objednavky']) $poznam .= ' · objednávky: ' . implode(', ', $inv['objednavky']);
    if ($inv['dodaci_listy']) $poznam .= ' · DL: ' . implode(', ', $inv['dodaci_listy']);
    if ($inv['poznamka'] !== '') $poznam .= "\n" . $inv['poznamka'];

    $d = [
        'id'             => [flexi_ext('fa', (int) $inv['id'])],
        'kod'            => $inv['cislo'],
        'typDokl'        => 'code:' . $typ,
        'varSym'         => $inv['vs'],
        'datVyst'        => $inv['datum_vystaveni'],
        'duzpPuv'        => $inv['datum_dph'],
        'datSplat'       => $inv['datum_splatnosti'],
        'mena'           => 'code:' . (!empty($inv['cizi']) ? $inv['cizi']['mena'] : 'CZK'),
        'formaUhradyCis' => flexi_forma_uhrady($inv['platba']),
        'firma'          => flexi_ext('odb', (int) $o['id']),
        'nazFirmy'       => ucetni_clean($o['nazev'], 255),
        'ulice'          => ucetni_clean($o['ulice'], 255),
        'mesto'          => ucetni_clean($o['mesto'], 255),
        'psc'            => ucetni_clean($o['psc'], 255),
        'ic'             => ucetni_clean(preg_replace('/\s+/', '', $o['ico']), 20),
        'dic'            => ucetni_clean(preg_replace('/\s+/', '', $o['dic']), 20),
        'popis'          => mb_substr($popis, 0, 255),
        'poznam'         => $poznam,
        'zaokrJakSumK'   => 'zaokrJak.matem',
        'zaokrNaSumK'    => 'zaokrNa.zadne',
        'zaokrJakDphK'   => 'zaokrJak.matem',
        'zaokrNaDphK'    => 'zaokrNa.setiny',
    ];
    if ($inv['objednavky']) $d['cisObj'] = mb_substr(implode(', ', $inv['objednavky']), 0, 2000);
    // 💱 v3.0.640 — doklad v cizí měně: kurz + ceny položek v ní (Flexi dopočte Kč kurzem)
    $cizi = $inv['cizi'] ?? null;
    if ($cizi) { $d['kurz'] = round((float) $cizi['kurz'], 4); $d['kurzMnozstvi'] = 1; }

    if (!$inv['polozky']) {
        return ['ok' => false, 'error' => 'Faktura nemá žádné položky — do FlexiBee ji nelze odeslat.'];
    }
    $pol = [];
    foreach ($inv['polozky'] as $ix => $p) {
        $rate = flexi_rate((float) $p['sazba']);
        if ($rate === null) return ['ok' => false, 'error' => 'Položka „' . $p['nazev'] . '" má sazbu DPH ' . ucetni_num($p['sazba'], 2) . ' %, kterou FlexiBee nezná.'];
        $mn = round((float) $p['mnozstvi'], 3);
        $cena = $cizi ? round((float) $cizi['polozky'][$ix]['cena_mj'], 2) : round((float) $p['cena_mj'], 2);
        // Flexi u dobropisu vyžaduje ZÁPORNÉ množství a kladnou cenu (APPEK ukládá záporné ceny,
        //   kladné množství) — znaménko součinu zůstane stejné, částky sedí.
        if ($dob && $cena < 0 && $mn > 0) { $mn = -$mn; $cena = -$cena; }
        $row = [
            'nazev'       => mb_substr($p['nazev'], 0, 255),
            'mnozMj'      => $mn,
            'cenaMj'      => $cena,
            'typCenyDphK' => 'typCeny.bezDph',
            'typSzbDphK'  => $rate,
            'szbDph'      => round((float) $p['sazba'], 2),
        ];
        if ($p['kod'] !== '') $row['kod'] = mb_substr($p['kod'], 0, 64);
        if ($p['poznamka'] !== '') $row['poznam'] = $p['poznamka'];
        $mj = strtoupper((string) $p['jednotka']);
        if ($mj !== '' && !empty($opt['mj'][$mj])) $row['mj'] = 'code:' . $mj;
        $pol[] = $row;
    }
    $d['bezPolozek'] = false;
    $d['polozkyFaktury@removeAll'] = 'true'; // re-export nahradí položky (žádné zdvojení)
    $d['polozkyFaktury'] = $pol;
    return ['ok' => true, 'data' => $d];
}

function flexi_envelope(string $evidence, array $records): string {
    return json_encode(['winstrom' => ['@version' => '1.0', $evidence => array_values($records)]], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION);
}

/**
 * Rozparsuje odpověď Flexi (čistá funkce). Vrací
 *  ['ok'=>bool, 'error'=>?česky, 'results'=>[['id','ref','errors'=>[…]]], 'stats'=>[…], 'data'=>pole winstrom]
 */
function flexi_parse(string $body, int $http): array {
    if (trim($body) === '' && $http >= 200 && $http < 300) {
        return ['ok' => true, 'error' => null, 'results' => [], 'stats' => [], 'data' => []]; // např. DELETE → 200 bez těla
    }
    $j = json_decode($body, true);
    if (!is_array($j)) {
        if (in_array($http, [401, 403, 404, 429], true)) {
            $j = ['winstrom' => ['success' => 'false']]; // HTML chybová stránka → mapuj podle HTTP kódu níž
        }
    }
    if (!is_array($j)) {
        $txt = trim(strip_tags($body));
        return ['ok' => false, 'error' => 'FlexiBee nevrátil platnou odpověď' . ($http ? ' (HTTP ' . $http . ')' : '') . ($txt !== '' ? ': ' . mb_substr($txt, 0, 200) : '.'), 'results' => [], 'stats' => [], 'data' => []];
    }
    $w = $j['winstrom'] ?? $j;
    $results = [];
    $msgs = [];
    foreach (($w['results'] ?? []) as $res) {
        $errs = [];
        foreach (($res['errors'] ?? []) as $e) {
            $m = trim((string) ($e['message'] ?? ''));
            if ($m === '') continue;
            if (!empty($e['for'])) $m .= ' [' . $e['for'] . ']';
            $errs[] = $m;
        }
        $results[] = ['id' => (string) ($res['id'] ?? ''), 'ref' => (string) ($res['ref'] ?? ''), 'code' => (string) ($res['code'] ?? ''), 'errors' => $errs];
        foreach ($errs as $m) $msgs[] = $m;
    }
    if (!empty($w['message'])) array_unshift($msgs, (string) $w['message']);
    $success = !isset($w['success']) || $w['success'] === true || $w['success'] === 'true';
    $ok = $success && $http >= 200 && $http < 300 && !$msgs;
    $err = null;
    if (!$ok) {
        if ($http === 401) $err = 'FlexiBee odmítl přihlášení — špatné uživatelské jméno nebo heslo (HTTP 401).';
        elseif ($http === 403) $err = 'Uživatel nemá ve FlexiBee oprávnění k této operaci (HTTP 403).' . ($msgs ? ' ' . implode(' | ', array_unique($msgs)) : '');
        elseif ($http === 404) $err = 'Firma nebo evidence ve FlexiBee neexistuje (HTTP 404) — zkontroluj kód firmy.';
        elseif ($http === 429) $err = 'FlexiBee dočasně omezil počet požadavků (HTTP 429) — zkus to za chvíli znovu.';
        else $err = 'FlexiBee: ' . ($msgs ? implode(' | ', array_unique($msgs)) : ('chyba HTTP ' . $http));
    }
    return ['ok' => $ok, 'error' => $err, 'results' => $results, 'stats' => $w['stats'] ?? [], 'data' => $w];
}

/** Základní URL firmy. */
function flexi_base(array $cfg): string {
    return rtrim($cfg['flexibee_url'], '/') . '/c/' . rawurlencode($cfg['flexibee_company']);
}

/** REST volání. $path relativně k firmě (např. 'faktura-vydana.json?limit=1'). */
function flexi_request(array $cfg, string $method, string $path, ?string $jsonBody = null, int $timeout = 30): array {
    $h = [
        'Authorization: Basic ' . base64_encode(($cfg['flexibee_username'] ?? '') . ':' . ($cfg['flexibee_password'] ?? '')),
        'Accept: application/json',
    ];
    if ($jsonBody !== null) $h[] = 'Content-Type: application/json; charset=utf-8';
    $r = ucetni_http($method, flexi_base($cfg) . '/' . ltrim($path, '/'), $h, $jsonBody,
                     ['timeout' => $timeout, 'povolit_lan' => ($cfg['ucetni_povolit_lan'] ?? '') === '1']);
    if ($r['http'] === 0) return ['ok' => false, 'error' => $r['error'], 'results' => [], 'stats' => [], 'data' => [], 'http' => 0, 'raw' => ''];
    $p = flexi_parse((string) $r['body'], $r['http']);
    $p['http'] = $r['http'];
    $p['raw'] = mb_substr((string) $r['body'], 0, 2000);
    if (!$p['ok'] && $p['error'] === null) $p['error'] = $r['error'] ?? 'Neznámá chyba FlexiBee.';
    return $p;
}

function flexi_test(array $cfg): array {
    if ($e = flexi_cfg_chyba($cfg, false)) return ['ok' => false, 'error' => $e];
    $r = flexi_request($cfg, 'GET', 'faktura-vydana.json?limit=1&detail=custom:id,kod', null, 15);
    if (!$r['ok']) return ['ok' => false, 'error' => $r['error']];
    return ['ok' => true, 'message' => 'Spojení s FlexiBee OK — firma „' . $cfg['flexibee_company'] . '" je dostupná a uživatel má přístup k vydaným fakturám.'];
}

/** Kódy existujících měrných jednotek (pro vazbu položek). Chyba = prázdné pole (položky pak bez mj). */
function flexi_merne_jednotky(array $cfg): array {
    static $cache = [];
    $k = $cfg['flexibee_url'] . '|' . $cfg['flexibee_company'];
    if (isset($cache[$k])) return $cache[$k];
    $r = flexi_request($cfg, 'GET', 'merna-jednotka.json?limit=300&detail=custom:kod', null, 15);
    $out = [];
    if ($r['ok']) foreach (($r['data']['merna-jednotka'] ?? []) as $m) if (!empty($m['kod'])) $out[strtoupper($m['kod'])] = true;
    return $cache[$k] = $out;
}

/** Zjistí kód typu dobropisu (nastavení → DOBROPIS → první typDokladu.dobropis). */
function flexi_typ_dobropis(array $cfg): array {
    if (($cfg['flexibee_typ_dobropis'] ?? '') !== '') return ['ok' => true, 'kod' => $cfg['flexibee_typ_dobropis']];
    $r = flexi_request($cfg, 'GET', 'typ-faktury-vydane.json?limit=500&detail=custom:kod,typDoklK', null, 20);
    if (!$r['ok']) return ['ok' => false, 'error' => $r['error']];
    $kody = [];
    foreach (($r['data']['typ-faktury-vydane'] ?? []) as $t) if (($t['typDoklK'] ?? '') === 'typDokladu.dobropis') $kody[] = (string) $t['kod'];
    if (!$kody) return ['ok' => false, 'error' => 'Ve FlexiBee není žádný typ vydaného dokladu „dobropis" — založ ho nebo zadej kód v nastavení.'];
    return ['ok' => true, 'kod' => in_array('DOBROPIS', $kody, true) ? 'DOBROPIS' : $kody[0]];
}

/** Upsert záznamů do evidence. Vrací výsledek flexi_request. */
function flexi_upsert(array $cfg, string $evidence, array $records, int $timeout = 60): array {
    return flexi_request($cfg, 'POST', $evidence . '.json', flexi_envelope($evidence, $records), $timeout);
}

/**
 * Export faktury (vč. upsertu odběratele). $inv = ucetni_nacti_fakturu().
 * Vrací ['ok','error','externi_id','externi_cislo','raw'].
 */
function flexi_export_invoice(array $cfg, array $inv): array {
    if ($e = flexi_cfg_chyba($cfg)) return ['ok' => false, 'error' => $e];
    $opt = ['typ_faktura' => $cfg['flexibee_typ_faktura'] ?? 'FAKTURA', 'mj' => flexi_merne_jednotky($cfg)];
    if (!empty($inv['je_dobropis'])) {
        $t = flexi_typ_dobropis($cfg);
        if (!$t['ok']) return ['ok' => false, 'error' => $t['error']];
        $opt['typ_dobropis'] = $t['kod'];
    }
    $b = flexi_build_invoice($inv, $opt);
    if (!$b['ok']) return ['ok' => false, 'error' => $b['error']];
    $p = flexi_upsert($cfg, 'adresar', [flexi_build_partner($inv['odberatel'])]);
    if (!$p['ok']) return ['ok' => false, 'error' => 'Odběratele se nepodařilo zapsat do adresáře: ' . $p['error'], 'raw' => $p['raw'] ?? ''];
    $r = flexi_upsert($cfg, 'faktura-vydana', [$b['data']]);
    if (!$r['ok']) return ['ok' => false, 'error' => $r['error'], 'raw' => $r['raw'] ?? ''];
    $id = $r['results'][0]['id'] ?? '';
    return ['ok' => true, 'error' => null, 'externi_id' => $id, 'externi_cislo' => $inv['cislo'], 'raw' => $r['raw'] ?? '', 'stats' => $r['stats']];
}

/** Přijaté faktury za období (jen čtení). */
function flexi_prijate(array $cfg, string $od, string $do): array {
    if ($e = flexi_cfg_chyba($cfg, false)) return ['ok' => false, 'error' => $e];
    $filtr = rawurlencode("(datVyst >= '$od' and datVyst <= '$do')");
    $r = flexi_request($cfg, 'GET', "faktura-prijata/$filtr.json?limit=1000&order=datVyst&detail=custom:kod,cisDosle,varSym,datVyst,datSplat,nazFirmy,ic,sumCelkem,sumCelkemMen,mena,stavUhrK,popis", null, 45);
    if (!$r['ok']) return ['ok' => false, 'error' => $r['error']];
    $out = [];
    foreach (($r['data']['faktura-prijata'] ?? []) as $f) {
        $mena = preg_replace('/^code:/', '', (string) ($f['mena'] ?? 'CZK')) ?: 'CZK';
        $out[] = [
            'cislo'      => (string) ($f['kod'] ?? ''),
            'doslo_cislo'=> (string) ($f['cisDosle'] ?? ''),
            'vs'         => (string) ($f['varSym'] ?? ''),
            'datum'      => substr((string) ($f['datVyst'] ?? ''), 0, 10),
            'splatnost'  => substr((string) ($f['datSplat'] ?? ''), 0, 10),
            'dodavatel'  => (string) ($f['nazFirmy'] ?? ''),
            'ico'        => (string) ($f['ic'] ?? ''),
            'text'       => (string) ($f['popis'] ?? ''),
            'celkem'     => round((float) ($mena !== 'CZK' && (float) ($f['sumCelkemMen'] ?? 0) != 0 ? $f['sumCelkemMen'] : ($f['sumCelkem'] ?? 0)), 2),
            'mena'       => $mena,
            'uhrazeno'   => (string) ($f['stavUhrK@showAs'] ?? ($f['stavUhrK'] ?? '')),
        ];
    }
    return ['ok' => true, 'faktury' => $out];
}
