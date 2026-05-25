<?php
/**
 * Onboarding wizard — first-run experience.
 *
 * GET ?action=status              → vrátí stav (krok, dokončeno, dovednosti k akci)
 * POST ?action=set_step           → uloží aktuální krok
 * POST ?action=dismiss            → skryje onboarding navždy
 * POST ?action=complete           → označí jako hotový
 * GET ?action=ares&ico=12345678   → načte data z ARES (pro auto-fill)
 * POST ?action=seed_demo          → naplní vzorovými daty (15 výrobků, 3 kategorie)
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/_admin_auth.php';
cors_headers();
require_admin();

$pdo = db();
$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? '';

// Auto-migrace
$pdo->exec("
    CREATE TABLE IF NOT EXISTS onboarding (
        id INT PRIMARY KEY DEFAULT 1,
        step INT NOT NULL DEFAULT 0,
        completed_steps TEXT NULL,
        skipped TINYINT(1) NOT NULL DEFAULT 0,
        completed_at DATETIME NULL,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
");
// Zaruč 1 řádek
$pdo->exec("INSERT IGNORE INTO onboarding (id, step) VALUES (1, 0)");

if ($action === 'status' && $method === 'GET') {
    $row = $pdo->query("SELECT * FROM onboarding WHERE id = 1")->fetch(PDO::FETCH_ASSOC);
    // Detekce: existují už klíčová data? → "should_show" false
    $maFirmu = (bool) trim((string) nastaveni_get($pdo, 'firma_nazev', ''));
    $pocetVyrobku = (int) $pdo->query("SELECT COUNT(*) FROM vyrobky")->fetchColumn();
    $pocetOdb = (int) $pdo->query("SELECT COUNT(*) FROM odberatele")->fetchColumn();
    $isFreshInstall = !$maFirmu && $pocetVyrobku === 0 && $pocetOdb === 0;
    json_response([
        'step' => (int) $row['step'],
        'skipped' => (int) $row['skipped'] === 1,
        'completed' => $row['completed_at'] !== null,
        'is_fresh_install' => $isFreshInstall,
        'should_show' => $isFreshInstall && (int) $row['skipped'] !== 1 && $row['completed_at'] === null,
        'ma_firmu' => $maFirmu,
        'pocet_vyrobku' => $pocetVyrobku,
        'pocet_odberatelu' => $pocetOdb,
    ]);
}

if ($action === 'set_step' && $method === 'POST') {
    $d = json_input();
    $step = (int) ($d['step'] ?? 0);
    $pdo->prepare("UPDATE onboarding SET step = :s WHERE id = 1")->execute(['s' => $step]);
    json_response(['ok' => true]);
}

if ($action === 'dismiss' && $method === 'POST') {
    $pdo->prepare("UPDATE onboarding SET skipped = 1 WHERE id = 1")->execute();
    json_response(['ok' => true]);
}

if ($action === 'complete' && $method === 'POST') {
    $pdo->prepare("UPDATE onboarding SET completed_at = NOW(), step = 99 WHERE id = 1")->execute();
    json_response(['ok' => true]);
}

if ($action === 'restart' && $method === 'POST') {
    $pdo->prepare("UPDATE onboarding SET step = 0, skipped = 0, completed_at = NULL WHERE id = 1")->execute();
    json_response(['ok' => true]);
}

// 🔍 ARES / RPO — načti data z IČO (CZ ARES + SK fallback)
//   - CZ: ares.gov.cz (státní free API, bez klíče)
//   - SK: rpo.statistics.sk (Register právnických osôb, free, bez klíče)
//   Auto-detekce: nejprve zkusí CZ ARES, při 404 zkusí SK RPO.
//   Manual override: parametr ?zeme=cz|sk přeskočí druhý pokus.
if ($action === 'ares' && $method === 'GET') {
    $ico = preg_replace('/\D/', '', $_GET['ico'] ?? '');
    if (strlen($ico) < 6 || strlen($ico) > 8) json_error('IČO musí mít 6-8 číslic');
    $zeme = strtolower(trim($_GET['zeme'] ?? '')); // '', 'cz', 'sk'

    // Helper na fetch s timeoutem
    $fetch = function(string $url, array $headers = []) {
        $hdr = "Accept: application/json\r\nUser-Agent: Appek-B2B/1.0\r\n";
        foreach ($headers as $k => $v) $hdr .= "$k: $v\r\n";
        $ctx = stream_context_create(['http' => [
            'timeout' => 8, 'method' => 'GET', 'header' => $hdr,
            'ignore_errors' => true, // získat i 404 body
        ]]);
        $body = @file_get_contents($url, false, $ctx);
        // Vytáhni HTTP status z $http_response_header
        $status = 0;
        if (isset($http_response_header[0]) && preg_match('#HTTP/\S+\s+(\d+)#', $http_response_header[0], $m)) {
            $status = (int) $m[1];
        }
        return ['body' => $body, 'status' => $status];
    };

    // ── CZ ARES ─────────────────────────────────────────────
    $tryAres = function() use ($ico, $fetch) {
        $url = "https://ares.gov.cz/ekonomicke-subjekty-v-be/rest/ekonomicke-subjekty/$ico";
        $r = $fetch($url);
        if ($r['body'] === false) return ['err' => 'ARES_UNREACHABLE'];
        if ($r['status'] === 404)  return ['err' => 'ARES_NOT_FOUND'];
        $data = json_decode($r['body'], true);
        if (!$data || empty($data['ico'])) return ['err' => 'ARES_NOT_FOUND'];
        return ['ok' => true, 'zdroj' => 'ares', 'data' => [
            'ico'   => $data['ico'] ?? $ico,
            'dic'   => $data['dic'] ?? '',
            'nazev' => $data['obchodniJmeno'] ?? '',
            'ulice' => trim(($data['sidlo']['nazevUlice'] ?? '') . ' ' . ($data['sidlo']['cisloDomovni'] ?? '')),
            'mesto' => $data['sidlo']['nazevObce'] ?? '',
            'psc'   => $data['sidlo']['psc'] ?? '',
            'pravni_forma' => $data['pravniForma'] ?? '',
            'datum_vzniku' => $data['datumVzniku'] ?? '',
            'zeme'  => 'CZ',
        ]];
    };

    // ── SK RPO (Register právnických osôb / Statistický úrad SR) ─
    $tryRpo = function() use ($ico, $fetch) {
        // Endpoint vrací JSON-LD. Public, bez klíče.
        // Pozn.: RPO se občas přejmenovává — proto zkoušíme i finstat scraper jako záložku
        $url = "https://rpo.statistics.sk/rpo/services/rpoBrowser/finder/legalEntityByIco/$ico";
        $r = $fetch($url);
        if ($r['body'] === false || $r['status'] >= 500) return ['err' => 'RPO_UNREACHABLE'];
        if ($r['status'] === 404) return ['err' => 'RPO_NOT_FOUND'];
        $data = json_decode($r['body'], true);
        if (!$data || empty($data)) return ['err' => 'RPO_NOT_FOUND'];

        // RPO obvykle vrací array s 1+ záznamy nebo přímo objekt
        $rec = is_array($data) && isset($data[0]) ? $data[0] : $data;
        if (empty($rec)) return ['err' => 'RPO_NOT_FOUND'];

        // Vytáhni název (FullNames[0].value nebo name)
        $nazev = '';
        if (!empty($rec['fullNames']) && is_array($rec['fullNames'])) {
            $nazev = $rec['fullNames'][0]['value'] ?? '';
        }
        if (!$nazev) $nazev = $rec['name'] ?? $rec['fullName'] ?? '';

        // Adresa: addresses[0] s objektem street/number/municipality/postalCode
        $ulice = $mesto = $psc = '';
        if (!empty($rec['addresses']) && is_array($rec['addresses'])) {
            $a = $rec['addresses'][0];
            $street = trim(($a['street'] ?? '') . ' ' . ($a['buildingNumber'] ?? ''));
            $ulice = $street;
            $mesto = $a['municipality']['value'] ?? $a['municipality'] ?? $a['city'] ?? '';
            $psc   = $a['postalCode'] ?? $a['zip'] ?? '';
            if (is_array($mesto)) $mesto = $mesto['value'] ?? '';
        }

        // DIČ
        $dic = $rec['dic'] ?? ($rec['vatNumber'] ?? '');
        // Právní forma
        $forma = '';
        if (!empty($rec['legalForms']) && is_array($rec['legalForms'])) {
            $f = $rec['legalForms'][0];
            $forma = $f['value'] ?? $f['name'] ?? '';
        }
        // Datum vzniku
        $vznik = $rec['establishmentDate'] ?? ($rec['validFrom'] ?? '');

        return ['ok' => true, 'zdroj' => 'rpo', 'data' => [
            'ico'   => $ico,
            'dic'   => $dic,
            'nazev' => trim($nazev),
            'ulice' => trim($ulice),
            'mesto' => trim((string) $mesto),
            'psc'   => trim((string) $psc),
            'pravni_forma' => $forma,
            'datum_vzniku' => $vznik,
            'zeme'  => 'SK',
        ]];
    };

    // ── Pořadí pokusů podle ?zeme= ────────────────────────────
    $pokusy = [];
    if ($zeme === 'sk') $pokusy = ['rpo'];
    else if ($zeme === 'cz') $pokusy = ['ares'];
    else $pokusy = ['ares', 'rpo']; // auto-fallback

    $posledniChyba = null;
    foreach ($pokusy as $p) {
        $res = ($p === 'ares') ? $tryAres() : $tryRpo();
        if (isset($res['ok'])) {
            $out = $res['data'];
            $out['_zdroj'] = $res['zdroj']; // 'ares' nebo 'rpo' (pro UI hlášku)
            json_response($out);
        }
        $posledniChyba = $res['err'] ?? 'UNKNOWN';
    }

    // Žádný registr nenašel
    if ($posledniChyba === 'ARES_UNREACHABLE' || $posledniChyba === 'RPO_UNREACHABLE') {
        json_error('Registry nedostupný (zkontroluj síť / firewall)', 502);
    }
    json_error('Subjekt s IČO ' . $ico . ' nenalezen v ARES ani v slovenském RPO', 404);
}

// 🎁 Seed — naplní demo daty (kategorie + 15 výrobků)
if ($action === 'seed_demo' && $method === 'POST') {
    $d = json_input();
    $kategorieZvolene = $d['kategorie'] ?? [];   // např. ['Chleby', 'Pečivo', 'Koláče']

    // Naplň kategorie
    $stmtKat = $pdo->prepare("INSERT INTO kategorie_vyrobku (nazev, ikona, poradi, aktivni) VALUES (:n, :i, :p, 1)");
    $kategorieIkony = [
        'Chleby' => '🥖', 'Pečivo' => '🥐', 'Koláče' => '🥧', 'Zákusky' => '🍰',
        'Slané pečivo' => '🥨', 'Káva a sirupy' => '☕', 'Dorty' => '🎂', 'Bezlepkové' => '🌾',
    ];
    $katIds = [];
    foreach ($kategorieZvolene as $i => $nazev) {
        try {
            $stmtKat->execute(['n' => $nazev, 'i' => $kategorieIkony[$nazev] ?? '🥖', 'p' => $i + 1]);
            $katIds[$nazev] = $pdo->lastInsertId();
        } catch (Throwable $e) { /* duplikát = OK */ }
    }

    // Vzorové výrobky (uložené v memo — můžeme rozšířit)
    $vzorky = [
        // Chleby
        ['Chleba selský',     'Chleby',    35, 700, 'g', 'Tradiční selský chleba'],
        ['Chleba pšenično-žitný', 'Chleby', 32, 700, 'g', ''],
        ['Chleba dalamánek',  'Chleby',    25, 500, 'g', ''],
        ['Chleba bezlepkový', 'Bezlepkové',58, 400, 'g', 'Pro celiaky'],
        // Pečivo
        ['Bageta klasická',   'Pečivo',    18, 250, 'g', ''],
        ['Rohlík',            'Pečivo',     5,  50, 'g', ''],
        ['Houska',            'Pečivo',     7,  60, 'g', ''],
        ['Bageta cibulová',   'Pečivo',    22, 250, 'g', ''],
        // Slané
        ['Croissant slaný',   'Slané pečivo', 28, 80, 'g', 'Listové těsto'],
        ['Sýrový šnek',       'Slané pečivo', 25, 100, 'g', ''],
        // Koláče
        ['Koláč švestkový',   'Koláče',    45, 300, 'g', ''],
        ['Koláč tvarohový',   'Koláče',    48, 300, 'g', ''],
        ['Záviny',            'Koláče',    55, 350, 'g', ''],
        // Zákusky
        ['Větrník',           'Zákusky',   38, 90, 'g', ''],
        ['Punčovka',          'Zákusky',   35, 80, 'g', ''],
    ];

    // Najdi default jednotku + sazba DPH
    $jedKs = $pdo->query("SELECT id FROM jednotky WHERE kod = 'ks' LIMIT 1")->fetchColumn();
    if (!$jedKs) {
        $pdo->exec("INSERT INTO jednotky (kod, nazev) VALUES ('ks', 'kus')");
        $jedKs = $pdo->lastInsertId();
    }
    $dph12 = $pdo->query("SELECT id FROM sazby_dph WHERE sazba = 12 LIMIT 1")->fetchColumn();
    if (!$dph12) {
        $pdo->exec("INSERT INTO sazby_dph (sazba, nazev) VALUES (12, 'Snížená 12 %')");
        $dph12 = $pdo->lastInsertId();
    }

    $stmtV = $pdo->prepare("
        INSERT INTO vyrobky (nazev, kategorie_id, cena_bez_dph, hmotnost_g, jednotka_id, sazba_dph_id, popis, aktivni)
        VALUES (:n, :k, :c, :h, :j, :d, :p, 1)
    ");
    $vlozeno = 0;
    foreach ($vzorky as $v) {
        $katId = $katIds[$v[1]] ?? null;
        if (!$katId) continue;
        try {
            $stmtV->execute([
                'n' => $v[0], 'k' => $katId, 'c' => $v[2], 'h' => $v[3],
                'j' => $jedKs, 'd' => $dph12, 'p' => $v[5],
            ]);
            $vlozeno++;
        } catch (Throwable $e) { /* duplikát = OK */ }
    }

    json_response(['ok' => true, 'kategorie_pridano' => count($katIds), 'vyrobky_pridano' => $vlozeno]);
}

json_error('Neznámá akce', 404);
