<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/_admin_auth.php';
cors_headers();
$auth = require_admin();
require_super_admin(); // jen super admin smí měnit číselné řady

$method = $_SERVER['REQUEST_METHOD'];
$pdo = db();

try {
    if ($method === 'GET') {
        // Seznam číselných řad za daný (nebo aktuální) rok
        $rok = isset($_GET['rok']) ? (int) $_GET['rok'] : (int) date('Y');

        // Načti existující řady
        $stmt = $pdo->prepare("
            SELECT typ, rok, predcisli, posledni
            FROM cislovani
            WHERE rok = :r
            ORDER BY FIELD(typ, 'FA', 'DL', 'OBJ', 'VL')
        ");
        $stmt->execute(['r' => $rok]);
        $existing = $stmt->fetchAll();

        // Pro každý známý typ vrátíme řadu (existující nebo defaultní)
        $typy = [
            ['typ' => 'FA',  'nazev' => 'Faktury',       'ikona' => '💰'],
            ['typ' => 'DL',  'nazev' => 'Dodací listy',  'ikona' => '📄'],
            ['typ' => 'OBJ', 'nazev' => 'Objednávky',    'ikona' => '🛒'],
            ['typ' => 'VL',  'nazev' => 'Výrobní listy', 'ikona' => '🥖'],
        ];

        $rady = [];
        foreach ($typy as $t) {
            // Najdi existující řádek pro tento typ
            $r = null;
            foreach ($existing as $e) {
                if ($e['typ'] === $t['typ']) { $r = $e; break; }
            }
            $predcisli = $r['predcisli'] ?? ($t['typ'] . '-' . $rok . '-');
            $posledni = (int) ($r['posledni'] ?? 0);

            $rady[] = [
                'typ'        => $t['typ'],
                'nazev'      => $t['nazev'],
                'ikona'      => $t['ikona'],
                'rok'        => $rok,
                'predcisli'  => $predcisli,
                'posledni'   => $posledni,
                'priste'     => $predcisli . str_pad((string) ($posledni + 1), 4, '0', STR_PAD_LEFT),
                'existuje'   => $r !== null,
            ];
        }

        json_response([
            'rok'  => $rok,
            'rady' => $rady,
        ]);
    }

    if ($method === 'PUT' || $method === 'POST') {
        $body = json_decode(file_get_contents('php://input'), true);

        if (!isset($body['typ']) || !isset($body['rok'])) {
            json_error('Chybí typ nebo rok', 400);
        }

        $typ = strtoupper(trim($body['typ']));
        $rok = (int) $body['rok'];
        $predcisli = isset($body['predcisli']) ? (string) $body['predcisli'] : '';
        // Posledni = uživatelem zadané "počáteční číslo" - 1
        // (tj. další generované bude přesně "počáteční číslo")
        $pocatecni = isset($body['pocatecni']) ? max(1, (int) $body['pocatecni']) : 1;
        $posledni = $pocatecni - 1;

        // Validace typu
        if (!in_array($typ, ['FA', 'DL', 'OBJ', 'VL'], true)) {
            json_error('Neznámý typ řady', 400);
        }

        // Validace předčíslí
        if (mb_strlen($predcisli) > 40) {
            json_error('Předčíslí je příliš dlouhé (max 40 znaků)', 400);
        }

        // Pokud už existují faktury/DL/atd. v této řadě a uživatel snižuje počet, varování
        // Nicméně přepisujeme to vždy — admin ví co dělá
        $stmt = $pdo->prepare("
            INSERT INTO cislovani (typ, rok, predcisli, posledni)
            VALUES (:t, :r, :p, :n)
            ON DUPLICATE KEY UPDATE
                predcisli = VALUES(predcisli),
                posledni  = VALUES(posledni)
        ");
        $stmt->execute([
            't' => $typ,
            'r' => $rok,
            'p' => $predcisli,
            'n' => $posledni,
        ]);

        json_response([
            'ok'        => true,
            'typ'       => $typ,
            'rok'       => $rok,
            'predcisli' => $predcisli,
            'posledni'  => $posledni,
            'priste'    => $predcisli . str_pad((string) ($posledni + 1), 4, '0', STR_PAD_LEFT),
        ]);
    }

    json_error('Method not allowed', 405);

} catch (Throwable $e) {
    json_error_safe('Server error', $e, 500);
}
