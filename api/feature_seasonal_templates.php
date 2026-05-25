<?php
/**
 * 🍂 SEZÓNNÍ ŠABLONY — feature 'seasonal_pricing' / 'christmas_orders' / 'easter_orders'.
 *
 * One-click vložení sezónního sortimentu do produkce.
 *
 * GET  /api/feature_seasonal_templates.php          → seznam šablon
 * GET  /api/feature_seasonal_templates.php?id=X     → detail šablony (preview položek)
 * POST /api/feature_seasonal_templates.php          → aplikuj šablonu
 *      Body: { template_id: "vanoce" | "velikonoce" | "valentyn" | "halloween", action: "insert" | "deactivate" }
 *
 * Gating: feature_enabled('seasonal_pricing') — Sezónní balíček
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/_admin_auth.php';
require_once __DIR__ . '/_features.php';

header('Content-Type: application/json; charset=UTF-8');
if (function_exists('cors_headers')) cors_headers();

if (!feature_enabled('seasonal_pricing')) {
    http_response_code(402);
    echo json_encode([
        'error' => 'upsell',
        'feature' => 'seasonal_pricing',
        'package' => 'sezona',
        'message' => 'Sezónní šablony jsou součástí balíčku Sezónní akce.',
    ]);
    exit;
}

require_admin();

const SEASONAL_TEMPLATES = [
    'vanoce' => [
        'name' => '🎄 Vánoční sortiment',
        'desc' => 'Vánočka, perníčky, linecké cukroví, čokoládové ozdoby. Aktivní v listopadu-prosinci.',
        'period' => ['from' => '11-15', 'to' => '12-31'],
        'kategorie_id_hint' => 2, // Cukrářské
        'items' => [
            ['nazev' => 'Vánočka máslová 500g',       'cislo' => 'VAN-XMS-001', 'cena' => 85.00,  'hmotnost_g' => 500,  'alergeny' => 'lepek,vejce,mleko,oresky', 'popis' => 'Tradiční s mandlemi a rozinkami.'],
            ['nazev' => 'Vánoční perníčky 500g',      'cislo' => 'PER-XMS-001', 'cena' => 148.00, 'hmotnost_g' => 500,  'alergeny' => 'lepek,vejce',                 'popis' => 'Zdobené glazurou, ručně.'],
            ['nazev' => 'Linecké cukroví 250g',       'cislo' => 'LIN-XMS-001', 'cena' => 78.00,  'hmotnost_g' => 250,  'alergeny' => 'lepek,vejce,mleko',           'popis' => 'Máslové, s rybízovou marmeládou.'],
            ['nazev' => 'Vanilkové rohlíčky 250g',    'cislo' => 'VRO-XMS-001', 'cena' => 88.00,  'hmotnost_g' => 250,  'alergeny' => 'lepek,vejce,mleko,oresky',    'popis' => 'Domácí máslové.'],
            ['nazev' => 'Vánoční štola 600g',          'cislo' => 'STO-XMS-001', 'cena' => 195.00, 'hmotnost_g' => 600,  'alergeny' => 'lepek,vejce,mleko,oresky',    'popis' => 'Drážďanská receptura.'],
            ['nazev' => 'Chlupaté kuličky 250g',      'cislo' => 'KUL-XMS-001', 'cena' => 92.00,  'hmotnost_g' => 250,  'alergeny' => 'lepek,vejce,mleko,oresky,soja','popis' => 'V kokosu / strouhance.'],
            ['nazev' => 'Pracny 250g',                 'cislo' => 'PRA-XMS-001', 'cena' => 95.00,  'hmotnost_g' => 250,  'alergeny' => 'lepek,vejce,mleko,oresky',    'popis' => 'V tvarech, máslové.'],
            ['nazev' => 'Vánoční mafiny (6ks)',        'cislo' => 'MAF-XMS-001', 'cena' => 132.00, 'hmotnost_g' => 360,  'alergeny' => 'lepek,vejce,mleko',           'popis' => 'Skořice + jablko / čokoláda.'],
        ],
    ],
    'velikonoce' => [
        'name' => '🐰 Velikonoční sortiment',
        'desc' => 'Mazanec, beránek, jidáše, perníky. Aktivní v březnu-dubnu.',
        'period' => ['from' => '03-01', 'to' => '04-30'],
        'kategorie_id_hint' => 1, // Pečivo
        'items' => [
            ['nazev' => 'Mazanec velikonoční 450g',   'cislo' => 'MAZ-EAS-001', 'cena' => 78.00,  'hmotnost_g' => 450,  'alergeny' => 'lepek,vejce,mleko,oresky', 'popis' => 'S rozinkami a mandlemi.'],
            ['nazev' => 'Velikonoční beránek 350g',    'cislo' => 'BER-EAS-001', 'cena' => 88.00,  'hmotnost_g' => 350,  'alergeny' => 'lepek,vejce,mleko',         'popis' => 'Tradiční, s polevou.'],
            ['nazev' => 'Jidáše (6ks) 240g',           'cislo' => 'JID-EAS-001', 'cena' => 42.00,  'hmotnost_g' => 240,  'alergeny' => 'lepek,vejce,mleko',         'popis' => 'Pomazané medem.'],
            ['nazev' => 'Velikonoční perníčky 300g',   'cislo' => 'PER-EAS-001', 'cena' => 95.00,  'hmotnost_g' => 300,  'alergeny' => 'lepek,vejce',               'popis' => 'V tvarech zajíčků a vajíček.'],
            ['nazev' => 'Velikonoční vejce čokoláda',  'cislo' => 'VEJ-EAS-001', 'cena' => 58.00,  'hmotnost_g' => 80,   'alergeny' => 'mleko,sója,oresky',         'popis' => '80g, mléčná/hořká.'],
            ['nazev' => 'Velikonoční zákusek (6ks)',   'cislo' => 'ZAK-EAS-001', 'cena' => 168.00, 'hmotnost_g' => 480,  'alergeny' => 'lepek,vejce,mleko,oresky',  'popis' => 'Sortiment dle sezóny.'],
        ],
    ],
    'valentyn' => [
        'name' => '💝 Valentýn',
        'desc' => 'Srdce, makronky, čokoláda. Aktivní 1.-14. února.',
        'period' => ['from' => '02-01', 'to' => '02-14'],
        'kategorie_id_hint' => 2,
        'items' => [
            ['nazev' => 'Perníkové srdce 80g',         'cislo' => 'SRD-VAL-001', 'cena' => 28.00,  'hmotnost_g' => 80,   'alergeny' => 'lepek,vejce',          'popis' => 'Zdobené glazurou, s nápisem.'],
            ['nazev' => 'Macarons mix srdce (6ks)',    'cislo' => 'MAC-VAL-001', 'cena' => 248.00, 'hmotnost_g' => 150,  'alergeny' => 'vejce,oresky,mleko',   'popis' => 'V krabičce, růžové/červené.'],
            ['nazev' => 'Cupcake romantic (6ks)',      'cislo' => 'CUP-VAL-001', 'cena' => 198.00, 'hmotnost_g' => 480,  'alergeny' => 'lepek,vejce,mleko',    'popis' => 'S křemovými ozdobami.'],
            ['nazev' => 'Box čokoládových bonbónů',    'cislo' => 'BON-VAL-001', 'cena' => 290.00, 'hmotnost_g' => 200,  'alergeny' => 'mleko,sója,oresky',    'popis' => 'Ručně dělané, 12ks.'],
        ],
    ],
    'halloween' => [
        'name' => '🎃 Halloween',
        'desc' => 'Dýně, duch, netopýři. Aktivní v říjnu.',
        'period' => ['from' => '10-15', 'to' => '11-01'],
        'kategorie_id_hint' => 2,
        'items' => [
            ['nazev' => 'Perník dýně 100g',            'cislo' => 'PER-HAL-001', 'cena' => 32.00,  'hmotnost_g' => 100,  'alergeny' => 'lepek,vejce',     'popis' => 'Ve tvaru dýně, zdobený.'],
            ['nazev' => 'Halloweenské cupcake (6ks)',  'cislo' => 'CUP-HAL-001', 'cena' => 215.00, 'hmotnost_g' => 480,  'alergeny' => 'lepek,vejce,mleko','popis' => 'Černé/oranžové, s motivy.'],
            ['nazev' => 'Sušenky duch (8ks)',          'cislo' => 'SUS-HAL-001', 'cena' => 145.00, 'hmotnost_g' => 240,  'alergeny' => 'lepek,vejce,mleko','popis' => 'Bílá glazura, ručně.'],
            ['nazev' => 'Dýňový muffin (6ks)',         'cislo' => 'MUF-HAL-001', 'cena' => 138.00, 'hmotnost_g' => 360,  'alergeny' => 'lepek,vejce,mleko','popis' => 'S dýní a skořicí.'],
        ],
    ],
];

try {
    $pdo = db();

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $input = json_decode(file_get_contents('php://input'), true) ?? [];
        $tplId  = $input['template_id'] ?? '';
        $action = $input['action'] ?? 'insert';

        if (!isset(SEASONAL_TEMPLATES[$tplId])) {
            http_response_code(400);
            echo json_encode(['error' => 'unknown template']);
            exit;
        }

        $tpl = SEASONAL_TEMPLATES[$tplId];

        if ($action === 'deactivate') {
            // Deaktivuj všechny vyrobky s cislo začínajícím TPL prefixem
            $prefix = strtoupper(substr($tplId, 0, 3));
            $stmt = $pdo->prepare("UPDATE vyrobky SET aktivni = 0 WHERE cislo LIKE :p");
            $stmt->execute(['p' => '%-' . $prefix . '-%']);
            echo json_encode(['ok' => true, 'deactivated' => $stmt->rowCount()]);
            exit;
        }

        // INSERT — zjisti default jednotka_id a sazba_dph_id
        $jednotkaId = (int)$pdo->query("SELECT id FROM jednotky WHERE kod = 'ks' LIMIT 1")->fetchColumn();
        $sazbaId    = (int)$pdo->query("SELECT id FROM sazby_dph WHERE sazba = 12 LIMIT 1")->fetchColumn();
        if (!$jednotkaId) $jednotkaId = 1;
        if (!$sazbaId)    $sazbaId    = 1;

        $kategorieId = $tpl['kategorie_id_hint'] ?? null;
        // Ověř že kategorie existuje
        if ($kategorieId) {
            $kc = $pdo->prepare("SELECT COUNT(*) FROM kategorie_vyrobku WHERE id = :i");
            $kc->execute(['i' => $kategorieId]);
            if ((int)$kc->fetchColumn() === 0) $kategorieId = null;
        }

        $inserted = 0;
        $skipped  = 0;
        $stmt = $pdo->prepare("
            INSERT INTO vyrobky (cislo, nazev, popis, kategorie_id, jednotka_id, sazba_dph_id, cena_bez_dph, hmotnost_g, alergeny, aktivni, je_novinka, je_akce, created_at)
            VALUES (:cislo, :nazev, :popis, :kat, :jed, :sazba, :cena, :hmot, :al, 1, 1, 0, NOW())
            ON DUPLICATE KEY UPDATE
                nazev = VALUES(nazev),
                cena_bez_dph = VALUES(cena_bez_dph),
                aktivni = 1,
                je_novinka = 1
        ");

        // Check existing cisla to count truly inserted vs updated
        $checkStmt = $pdo->prepare("SELECT COUNT(*) FROM vyrobky WHERE cislo = :c");
        foreach ($tpl['items'] as $it) {
            $checkStmt->execute(['c' => $it['cislo']]);
            $existing = (int)$checkStmt->fetchColumn();
            $stmt->execute([
                'cislo' => $it['cislo'],
                'nazev' => $it['nazev'],
                'popis' => $it['popis'] ?? '',
                'kat'   => $kategorieId,
                'jed'   => $jednotkaId,
                'sazba' => $sazbaId,
                'cena'  => $it['cena'],
                'hmot'  => $it['hmotnost_g'] ?? null,
                'al'    => $it['alergeny'] ?? '',
            ]);
            if ($existing) $skipped++; else $inserted++;
        }

        echo json_encode([
            'ok'       => true,
            'template' => $tpl['name'],
            'inserted' => $inserted,
            'updated'  => $skipped,
            'total'    => count($tpl['items']),
        ]);
        exit;
    }

    // GET — vrať seznam šablon (with active period info)
    $now = date('m-d');
    $list = [];
    $itemCheck = $pdo->prepare("SELECT COUNT(*) FROM vyrobky WHERE cislo = :c");
    foreach (SEASONAL_TEMPLATES as $id => $tpl) {
        $isActive = $tpl['period']['from'] <= $now && $now <= $tpl['period']['to'];
        // Spočítej kolik položek šablony už existuje v DB
        $existCount = 0;
        foreach ($tpl['items'] as $it) {
            $itemCheck->execute(['c' => $it['cislo']]);
            $existCount += (int)$itemCheck->fetchColumn();
        }
        $list[$id] = [
            'id'           => $id,
            'name'         => $tpl['name'],
            'desc'         => $tpl['desc'],
            'period'       => $tpl['period'],
            'is_in_season' => $isActive,
            'item_count'   => count($tpl['items']),
            'items'        => $tpl['items'],
        ];
    }
    echo json_encode([ 'templates' => $list ]);

} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}
