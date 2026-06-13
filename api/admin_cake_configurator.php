<?php
/**
 * 🎂 KONFIGURÁTOR DORTŮ — Cukrárna balíček
 *
 * Vrátí možnosti konfigurace + počítá cenu:
 *   GET  ?action=options   — vrátí dostupné velikosti / příchutě / dekorace + ceny
 *   POST ?action=quote     — body { porcí, prichut_id, dekorace_id, text, fotka }
 *                            → vrátí { hmotnost_g, cena_bez_dph, cena_s_dph, polozky[] }
 *
 * Vyžaduje balíček 'cukrarna' aktivní.
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/_admin_auth.php';
require_once __DIR__ . '/_packages_lib.php';

cors_headers();
require_admin();
header('Content-Type: application/json; charset=UTF-8');

if (!package_enabled('cukrarna')) {
    http_response_code(402);
    json_response(['error' => 'Vyžaduje balíček 🧁 Cukrárna']);
}

$action = $_GET['action'] ?? '';
$method = $_SERVER['REQUEST_METHOD'];

// ════════════════════════════════════════════════════════════
// CONFIG — možnosti (zatím hardcoded, později z DB)
// ════════════════════════════════════════════════════════════

function cake_options(): array {
    return [
        'velikosti' => [
            ['porci' => 6,  'hmotnost_g' => 600,  'cena_bez_dph' => 380,  'label' => 'Mini (6 porcí)',     'prumer_cm' => 16],
            ['porci' => 10, 'hmotnost_g' => 1000, 'cena_bez_dph' => 620,  'label' => 'Malý (10 porcí)',    'prumer_cm' => 20],
            ['porci' => 14, 'hmotnost_g' => 1400, 'cena_bez_dph' => 860,  'label' => 'Střední (14 porcí)', 'prumer_cm' => 22],
            ['porci' => 20, 'hmotnost_g' => 2000, 'cena_bez_dph' => 1180, 'label' => 'Velký (20 porcí)',   'prumer_cm' => 26],
            ['porci' => 30, 'hmotnost_g' => 3000, 'cena_bez_dph' => 1690, 'label' => 'XL (30 porcí)',      'prumer_cm' => 30],
            ['porci' => 50, 'hmotnost_g' => 5000, 'cena_bez_dph' => 2780, 'label' => 'Patrový (50 porcí)', 'prumer_cm' => 36, 'patro' => 2],
        ],
        'prichute' => [
            ['id' => 'cokoladovy',  'nazev' => 'Čokoládový',   'priplatek_kc' => 0,   'ikona' => '🍫'],
            ['id' => 'vanilkovy',   'nazev' => 'Vanilkový',    'priplatek_kc' => 0,   'ikona' => '🍦'],
            ['id' => 'jahodovy',    'nazev' => 'Jahodový',     'priplatek_kc' => 50,  'ikona' => '🍓'],
            ['id' => 'oreskovy',    'nazev' => 'Oříškový',     'priplatek_kc' => 80,  'ikona' => '🌰'],
            ['id' => 'tiramisu',    'nazev' => 'Tiramisu',     'priplatek_kc' => 120, 'ikona' => '☕'],
            ['id' => 'medovnik',    'nazev' => 'Medovník',     'priplatek_kc' => 100, 'ikona' => '🍯'],
            ['id' => 'birthday',    'nazev' => 'Birthday',     'priplatek_kc' => 60,  'ikona' => '🎂'],
            ['id' => 'svatecni',    'nazev' => 'Sváteční mix', 'priplatek_kc' => 150, 'ikona' => '✨'],
        ],
        'dekorace' => [
            ['id' => 'zadna',       'nazev' => 'Bez dekorace',         'cena_kc' => 0,   'ikona' => '⭕'],
            ['id' => 'kvety',       'nazev' => 'Květy z marcipánu',     'cena_kc' => 200, 'ikona' => '🌸'],
            ['id' => 'plody',       'nazev' => 'Čerstvé ovoce',         'cena_kc' => 180, 'ikona' => '🍓'],
            ['id' => 'cokolada',    'nazev' => 'Čokoládové ornamenty',  'cena_kc' => 250, 'ikona' => '🍫'],
            ['id' => 'svatebni',    'nazev' => 'Svatební dekor',        'cena_kc' => 600, 'ikona' => '💍'],
            ['id' => 'detsky',      'nazev' => 'Dětské figurky',        'cena_kc' => 350, 'ikona' => '🧸'],
            ['id' => 'foto',        'nazev' => 'Jedlé foto (A4)',       'cena_kc' => 280, 'ikona' => '📷'],
        ],
        'text_na_dortu' => [
            'max_chars'   => 40,
            'cena_kc'     => 80,
            'fonty'       => ['Klasický', 'Skript', 'Tučný'],
        ],
        'fotka_predlohy' => [
            'cena_kc'     => 0, // jen pro inspiraci
            'max_size_mb' => 5,
        ],
        'sazba_dph' => 12,
    ];
}

if ($action === 'options') {
    json_response(cake_options());
}

if ($action === 'quote' && $method === 'POST') {
    $d = json_input();
    $porci      = max(4, min(100, (int) ($d['porci'] ?? 10)));
    $prichut    = $d['prichut_id']  ?? 'cokoladovy';
    $dekorace   = $d['dekorace_id'] ?? 'zadna';
    $text       = trim((string) ($d['text'] ?? ''));
    $maFotku    = !empty($d['fotka_predlohy_url']);

    $opts = cake_options();

    // Vyber nejbližší velikost
    $velikost = null;
    foreach ($opts['velikosti'] as $v) {
        if ($v['porci'] >= $porci) { $velikost = $v; break; }
    }
    if (!$velikost) $velikost = end($opts['velikosti']);

    // Najdi příchuť a dekorace
    $prichutObj  = current(array_filter($opts['prichute'], fn($p) => $p['id'] === $prichut)) ?: $opts['prichute'][0];
    $dekoraceObj = current(array_filter($opts['dekorace'], fn($p) => $p['id'] === $dekorace)) ?: $opts['dekorace'][0];

    $polozky = [];
    $base = (float) $velikost['cena_bez_dph'];
    $polozky[] = ['nazev' => 'Korpus + krém: ' . $velikost['label'] . ' (' . $velikost['hmotnost_g'] . ' g)', 'cena_kc' => $base];

    if ($prichutObj['priplatek_kc'] > 0) {
        $polozky[] = ['nazev' => 'Příchuť: ' . $prichutObj['nazev'], 'cena_kc' => (float) $prichutObj['priplatek_kc']];
    }
    if ($dekoraceObj['cena_kc'] > 0) {
        $polozky[] = ['nazev' => 'Dekorace: ' . $dekoraceObj['nazev'], 'cena_kc' => (float) $dekoraceObj['cena_kc']];
    }
    if ($text) {
        $polozky[] = ['nazev' => 'Text na dortu: „' . $text . '"', 'cena_kc' => (float) $opts['text_na_dortu']['cena_kc']];
    }

    $bezDPH = array_sum(array_column($polozky, 'cena_kc'));
    $dph    = $opts['sazba_dph'];
    $sDPH   = round($bezDPH * (1 + $dph / 100), 2);

    json_response([
        'velikost' => $velikost,
        'prichut'  => $prichutObj,
        'dekorace' => $dekoraceObj,
        'text'     => $text,
        'polozky'  => $polozky,
        'sazba_dph'   => $dph,
        'cena_bez_dph'=> $bezDPH,
        'cena_dph'    => round($sDPH - $bezDPH, 2),
        'cena_s_dph'  => $sDPH,
        'cena_per_porci' => round($sDPH / $velikost['porci'], 2),
        'doba_pripravy_dni' => max(2, ceil($porci / 20)), // min 2 dny, +1 den za 20 porcí
    ]);
}


// ════════════════════════════════════════════════════════════
// CREATE ORDER — vytvoří objednávku z konfigurátoru
// ════════════════════════════════════════════════════════════
if ($action === 'create_order' && $method === 'POST') {
    $d = json_input();
    $odberatel_id = (int) ($d['odberatel_id'] ?? 0);
    $datum_dodani = $d['datum_dodani'] ?? null;
    if (!$odberatel_id) json_error('Vyber odběratele', 400);
    if (!$datum_dodani) json_error('Vyber datum dodání', 400);

    // Z konfigurace spočítej cenu znovu (zabezpečení)
    $porci    = max(4, min(100, (int) ($d['porci'] ?? 10)));
    $prichut  = $d['prichut_id']  ?? 'cokoladovy';
    $dekorace = $d['dekorace_id'] ?? 'zadna';
    $text     = trim((string) ($d['text'] ?? ''));
    $opts = cake_options();
    $velikost = null;
    foreach ($opts['velikosti'] as $v) { if ($v['porci'] >= $porci) { $velikost = $v; break; } }
    if (!$velikost) $velikost = end($opts['velikosti']);
    $prichutObj  = current(array_filter($opts['prichute'], fn($p) => $p['id'] === $prichut)) ?: $opts['prichute'][0];
    $dekoraceObj = current(array_filter($opts['dekorace'], fn($p) => $p['id'] === $dekorace)) ?: $opts['dekorace'][0];

    $base = (float) $velikost['cena_bez_dph']
          + (float) ($prichutObj['priplatek_kc'] ?? 0)
          + (float) ($dekoraceObj['cena_kc'] ?? 0)
          + ($text ? (float) $opts['text_na_dortu']['cena_kc'] : 0);
    $dph = (float) $opts['sazba_dph'];
    $sDPH = round($base * (1 + $dph / 100), 2);

    $pdo = db();
    try {
        // Najdi DPH sazbu pro 12%
        $sazbaDphId = (int) $pdo->query("SELECT id FROM sazby_dph WHERE sazba = $dph LIMIT 1")->fetchColumn();
        if (!$sazbaDphId) $sazbaDphId = (int) $pdo->query("SELECT id FROM sazby_dph LIMIT 1")->fetchColumn();

        // Generuj číslo objednávky (🆕 v3.0.212 — kanál dort, vlastní řada DORT-rok-N)
        $cislo = kanal_dalsi_cislo($pdo, 'dort');

        $pdo->beginTransaction();

        // Vytvořit hlavičku
        $popisDortu = $velikost['label'] . ' · ' . $prichutObj['nazev']
                    . ($dekoraceObj['cena_kc'] > 0 ? ' · ' . $dekoraceObj['nazev'] : '')
                    . ($text ? ' · text: „' . $text . '"' : '');

        $pdo->prepare("
            INSERT INTO objednavky (cislo, odberatel_id, datum_objednani, datum_dodani,
                                    castka_bez_dph, castka_dph, castka_celkem, stav, poznamka, puvod)
            VALUES (:c, :o, NOW(), :dd, :bd, :d, :sd, 'nova', :p, 'dort')
        ")->execute([
            'c'  => $cislo,
            'o'  => $odberatel_id,
            'dd' => $datum_dodani,
            'bd' => $base,
            'd'  => round($sDPH - $base, 2),
            'sd' => $sDPH,
            'p'  => "🎂 Dort z konfigurátoru:\n" . $popisDortu,
        ]);
        $objId = (int) $pdo->lastInsertId();

        // Vytvořit volnou položku (bez vyrobek_id)
        $pdo->prepare("
            INSERT INTO objednavky_polozky
            (objednavka_id, vyrobek_id, vyrobek_nazev, jednotka, mnozstvi, cena_bez_dph, sazba_dph)
            VALUES (:o, NULL, :n, 'ks', 1, :c, :s)
        ")->execute([
            'o' => $objId,
            'n' => '🎂 ' . $velikost['label'] . ' (dort z konfigurátoru)',
            'c' => $base,
            's' => $dph,
        ]);

        $pdo->commit();

        // Notifikace + webhook
        try {
            if (function_exists('notifikace_nova_objednavka')) notifikace_nova_objednavka($pdo, $objId);
        } catch (Throwable $e) {}

        json_response([
            'ok'      => true,
            'id'      => $objId,
            'cislo'   => $cislo,
            'castka'  => $sDPH,
            'message' => 'Objednávka vytvořena',
        ]);
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        json_error_safe('Chyba vytvoření', $e, 500);
    }
}

json_error('Neznámá akce (options|quote|create_order)', 404);
