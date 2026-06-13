<?php
/**
 * 🎂 KONFIGURÁTOR DORTŮ — Cukrárna balíček
 *
 * 🆕 v3.0.297 — PROVÁZÁNO SE SUROVINAMI / VÝROBOU / KALKULACÍ.
 *   Příchuť = REÁLNÝ dort výrobek (obor='dort') s recepturou (vyrobek_suroviny).
 *   Velikost = škála na base 1000 g (Malý=1.0, Velký=2.0…). Cena = cena výrobku × škála
 *   (+ dekorace/text příplatky) a v nabídce se ukazují materiálové náklady Z KALKULACE
 *   (Σ surovina.cena_baleni/obsah_baleni × receptura.mnozstvi × škála) + marže.
 *   create_order zakládá řádek s vyrobek_id příchuti + mnozstvi=škála → výroba/odpis
 *   surovin/inventura/kalkulace běží STANDARDNÍM tokem (žádné nové odpisové kódy).
 *
 *   GET  ?action=options       — velikosti + příchutě (dort výrobky) + dekorace + ceny
 *   POST ?action=quote         — { porci, vyrobek_id, dekorace_id, text } → cena + kalkulace
 *   POST ?action=create_order  — založí objednávku (kanál 'dort', řádek = vyrobek_id × škála)
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
// VELIKOSTI — base 1000 g / 10 porcí; škála = hmotnost_g / 1000
// ════════════════════════════════════════════════════════════
function cake_sizes(): array {
    return [
        ['porci' => 6,  'hmotnost_g' => 600,  'label' => 'Mini (6 porcí)',     'prumer_cm' => 16],
        ['porci' => 10, 'hmotnost_g' => 1000, 'label' => 'Malý (10 porcí)',    'prumer_cm' => 20],
        ['porci' => 14, 'hmotnost_g' => 1400, 'label' => 'Střední (14 porcí)', 'prumer_cm' => 22],
        ['porci' => 20, 'hmotnost_g' => 2000, 'label' => 'Velký (20 porcí)',   'prumer_cm' => 26],
        ['porci' => 30, 'hmotnost_g' => 3000, 'label' => 'XL (30 porcí)',      'prumer_cm' => 30],
        ['porci' => 50, 'hmotnost_g' => 5000, 'label' => 'Patrový (50 porcí)', 'prumer_cm' => 36, 'patro' => 2],
    ];
}
const CAKE_BASE_G = 1000.0; // base receptura je počítaná na 1000 g (≈ 10 porcí)

function cake_decorations(): array {
    return [
        ['id' => 'zadna',    'nazev' => 'Bez dekorace',         'cena_kc' => 0,   'ikona' => '⭕'],
        ['id' => 'kvety',    'nazev' => 'Květy z marcipánu',     'cena_kc' => 200, 'ikona' => '🌸'],
        ['id' => 'plody',    'nazev' => 'Čerstvé ovoce',         'cena_kc' => 180, 'ikona' => '🍓'],
        ['id' => 'cokolada', 'nazev' => 'Čokoládové ornamenty',  'cena_kc' => 250, 'ikona' => '🍫'],
        ['id' => 'svatebni', 'nazev' => 'Svatební dekor',        'cena_kc' => 600, 'ikona' => '💍'],
        ['id' => 'detsky',   'nazev' => 'Dětské figurky',        'cena_kc' => 350, 'ikona' => '🧸'],
        ['id' => 'foto',     'nazev' => 'Jedlé foto (A4)',       'cena_kc' => 280, 'ikona' => '📷'],
    ];
}
function cake_text_opts(): array {
    return ['max_chars' => 40, 'cena_kc' => 80, 'fonty' => ['Klasický', 'Skript', 'Tučný']];
}

// Materiálové náklady výrobku z receptury (Σ mnozstvi × jednotková cena suroviny).
function cake_material_cost(PDO $pdo, int $vyrobek_id): float {
    try {
        $st = $pdo->prepare("
            SELECT COALESCE(SUM(vs.mnozstvi * (s.cena_baleni / NULLIF(s.obsah_baleni, 0))), 0) AS naklad
            FROM vyrobek_suroviny vs JOIN suroviny s ON s.id = vs.surovina_id
            WHERE vs.vyrobek_id = :v
        ");
        $st->execute(['v' => $vyrobek_id]);
        return round((float) $st->fetchColumn(), 2);
    } catch (Throwable $e) { return 0.0; }
}

function cake_flavor_icon(string $nazev): string {
    $n = mb_strtolower($nazev);
    if (str_contains($n, 'čokolád') || str_contains($n, 'cokolad')) return '🍫';
    if (str_contains($n, 'vanilk')) return '🍦';
    if (str_contains($n, 'medovník') || str_contains($n, 'medovnik') || str_contains($n, 'med')) return '🍯';
    if (str_contains($n, 'tiramis')) return '☕';
    if (str_contains($n, 'oříšk') || str_contains($n, 'orisk') || str_contains($n, 'ořech')) return '🌰';
    if (str_contains($n, 'ovoc') || str_contains($n, 'meruň') || str_contains($n, 'jahod')) return '🍓';
    return '🎂';
}

// Příchutě = dort výrobky (obor='dort') s recepturou + cena (base 1000 g) + kalkulace.
function cake_flavors(PDO $pdo): array {
    $rows = [];
    try {
        $rows = $pdo->query("
            SELECT v.id, v.nazev, v.cena_bez_dph AS cena, COALESCE(sd.sazba, 12) AS dph,
                   (SELECT COUNT(*) FROM vyrobek_suroviny WHERE vyrobek_id = v.id) AS recept_polozek
            FROM vyrobky v
            LEFT JOIN sazby_dph sd ON sd.id = v.sazba_dph_id
            WHERE v.obor = 'dort' AND v.aktivni = 1
            ORDER BY v.poradi, v.nazev
        ")->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) { $rows = []; }
    $out = [];
    foreach ($rows as $r) {
        $vid = (int) $r['id'];
        $out[] = [
            'vyrobek_id'    => $vid,
            'nazev'         => $r['nazev'],
            'ikona'         => cake_flavor_icon((string) $r['nazev']),
            'cena_bez_dph'  => round((float) $r['cena'], 2),          // base 1000 g
            'material_kc'   => cake_material_cost($pdo, $vid),         // base 1000 g
            'sazba_dph'     => (float) $r['dph'],
            'recept_polozek' => (int) $r['recept_polozek'],
        ];
    }
    return $out;
}

// ════════════════════════════════════════════════════════════
// SEED — idempotentní: 6 editovatelných dortů s recepturou (jen když žádný neexistuje)
// ════════════════════════════════════════════════════════════
function cake_ensure_demo_dorty(PDO $pdo): void {
    try {
        $cnt = (int) $pdo->query("SELECT COUNT(*) FROM vyrobky WHERE obor = 'dort'")->fetchColumn();
        if ($cnt > 0) return;
    } catch (Throwable $e) { return; } // bez obor sloupce / tabulky → nelze seedovat

    // Kategorie „Dorty"
    $katId = (int) $pdo->query("SELECT id FROM kategorie_vyrobku WHERE nazev = 'Dorty' LIMIT 1")->fetchColumn();
    if (!$katId) {
        try {
            $maxPor = (int) $pdo->query("SELECT COALESCE(MAX(poradi),0)+1 FROM kategorie_vyrobku")->fetchColumn();
            $ins = $pdo->prepare("INSERT INTO kategorie_vyrobku (nazev, ikona, poradi) VALUES ('Dorty', '🎂', :p)");
            $ins->execute(['p' => $maxPor]);
            $katId = (int) $pdo->lastInsertId();
        } catch (Throwable $e) { $katId = (int) $pdo->query("SELECT id FROM kategorie_vyrobku ORDER BY id LIMIT 1")->fetchColumn(); }
    }
    $dph12 = (int) $pdo->query("SELECT id FROM sazby_dph WHERE sazba = 12 LIMIT 1")->fetchColumn();
    if (!$dph12) $dph12 = (int) $pdo->query("SELECT id FROM sazby_dph ORDER BY id LIMIT 1")->fetchColumn();
    $jedKs = (int) $pdo->query("SELECT id FROM jednotky WHERE kod = 'ks' LIMIT 1")->fetchColumn();
    if (!$jedKs) $jedKs = 1;

    // mapování surovin podle NÁZVU (robustní vůči různým id napříč instalacemi)
    $sur = [];
    foreach ($pdo->query("SELECT id, nazev FROM suroviny")->fetchAll(PDO::FETCH_ASSOC) as $s) {
        $sur[mb_strtolower($s['nazev'])] = (int) $s['id'];
    }
    $find = function (array $needles) use ($sur) {
        foreach ($sur as $n => $id) {
            foreach ($needles as $ndl) { if (str_contains($n, $ndl)) return $id; }
        }
        return null;
    };
    $S = [
        'mouka'  => $find(['mouka pšeničná hladká', 'mouka hladká', 'mouka pšeničná']),
        'maslo'  => $find(['máslo']),
        'cukrk'  => $find(['cukr krystal']),
        'cukrm'  => $find(['cukr moučka']),
        'cukrv'  => $find(['cukr vanilkový']),
        'vejce'  => $find(['vejce']),
        'smetana'=> $find(['smetana']),
        'mleko'  => $find(['mléko']),
        'kakao'  => $find(['kakao']),
        'cokolada'=> $find(['čokoláda']),
        'med'    => $find(['med květový', 'med']),
        'kava'   => $find(['káva']),
        'mandle' => $find(['mandle']),
        'orechy' => $find(['vlašské ořechy', 'ořech']),
        'tvaroh' => $find(['tvaroh']),
        'marmelada'=> $find(['marmeláda']),
        'skorice'=> $find(['skořice']),
    ];

    // Definice dortů (base = 1000 g / 10 porcí). receptura: [klíč suroviny, mnozstvi, jednotka]
    $dorty = [
        ['nazev' => 'Čokoládový dort', 'cena' => 620, 'recept' => [
            ['mouka',250,'g'],['cukrk',200,'g'],['maslo',150,'g'],['vejce',4,'ks'],['kakao',60,'g'],['cokolada',100,'g'],['smetana',200,'ml']]],
        ['nazev' => 'Vanilkový dort', 'cena' => 600, 'recept' => [
            ['mouka',260,'g'],['cukrk',200,'g'],['maslo',150,'g'],['vejce',4,'ks'],['cukrv',20,'g'],['smetana',250,'ml'],['mleko',80,'ml']]],
        ['nazev' => 'Medovník', 'cena' => 700, 'recept' => [
            ['mouka',300,'g'],['med',150,'g'],['maslo',120,'g'],['vejce',3,'ks'],['cukrk',150,'g'],['smetana',250,'ml'],['skorice',5,'g']]],
        ['nazev' => 'Tiramisu dort', 'cena' => 740, 'recept' => [
            ['mouka',150,'g'],['vejce',5,'ks'],['cukrk',150,'g'],['tvaroh',300,'g'],['smetana',200,'ml'],['kava',30,'g'],['kakao',20,'g']]],
        ['nazev' => 'Oříškový dort', 'cena' => 700, 'recept' => [
            ['mouka',220,'g'],['cukrk',200,'g'],['maslo',150,'g'],['vejce',4,'ks'],['orechy',150,'g'],['smetana',150,'ml']]],
        ['nazev' => 'Ovocný dort (meruňka)', 'cena' => 670, 'recept' => [
            ['mouka',250,'g'],['cukrm',180,'g'],['maslo',140,'g'],['vejce',4,'ks'],['marmelada',200,'g'],['smetana',200,'ml']]],
    ];

    // Dostupné sloupce vyrobky (whitelist filtr — robustní vůči schématu)
    $vcols = [];
    try { $vcols = $pdo->query("SHOW COLUMNS FROM vyrobky")->fetchAll(PDO::FETCH_COLUMN); } catch (Throwable $e) {}
    $has = fn($c) => in_array($c, $vcols, true);

    $pdo->beginTransaction();
    try {
        $pordBase = (int) $pdo->query("SELECT COALESCE(MAX(poradi),0) FROM vyrobky")->fetchColumn();
        $i = 0;
        foreach ($dorty as $d) {
            $i++;
            $fields = ['nazev' => $d['nazev'], 'aktivni' => 1];
            if ($has('obor'))          $fields['obor']          = 'dort';
            if ($has('cena_bez_dph'))  $fields['cena_bez_dph']  = $d['cena'];
            if ($has('kategorie_id'))  $fields['kategorie_id']  = $katId;
            if ($has('jednotka_id'))   $fields['jednotka_id']   = $jedKs;
            if ($has('sazba_dph_id'))  $fields['sazba_dph_id']  = $dph12;
            if ($has('hmotnost_g'))    $fields['hmotnost_g']    = CAKE_BASE_G; // 1000 g base
            if ($has('jednotka'))      $fields['jednotka']      = 'ks';
            if ($has('poradi'))        $fields['poradi']        = $pordBase + $i;
            if ($has('cislo'))         $fields['cislo']         = 'DORT-' . str_pad((string) $i, 2, '0', STR_PAD_LEFT);
            if ($has('popis'))         $fields['popis']         = 'Konfigurovatelný dort — receptura na 1000 g (≈10 porcí), škáluje se dle velikosti.';

            $cols = array_keys($fields);
            $place = array_map(fn($c) => ':' . $c, $cols);
            $sql = 'INSERT INTO vyrobky (' . implode(',', $cols) . ') VALUES (' . implode(',', $place) . ')';
            $pdo->prepare($sql)->execute($fields);
            $vid = (int) $pdo->lastInsertId();

            // receptura
            $por = 0;
            foreach ($d['recept'] as $r) {
                $sid = $S[$r[0]] ?? null;
                if (!$sid) continue; // surovina nenalezena → přeskoč (recept lze doplnit ručně)
                $por++;
                $pdo->prepare("
                    INSERT INTO vyrobek_suroviny (vyrobek_id, surovina_id, mnozstvi, jednotka, poradi)
                    VALUES (:v, :s, :m, :j, :p)
                ")->execute(['v' => $vid, 's' => $sid, 'm' => $r[1], 'j' => $r[2], 'p' => $por]);
            }
        }
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        // seed je best-effort; když selže, konfigurátor prostě nemá příchutě (UI to ošetří)
    }
}

// Vybere velikost (nejmenší pokrývající počet porcí) + škálu
function cake_pick_size(int $porci): array {
    $sizes = cake_sizes();
    $velikost = null;
    foreach ($sizes as $v) { if ($v['porci'] >= $porci) { $velikost = $v; break; } }
    if (!$velikost) $velikost = end($sizes);
    $velikost['skala'] = round((float) $velikost['hmotnost_g'] / CAKE_BASE_G, 4);
    return $velikost;
}

// ════════════════════════════════════════════════════════════
if ($action === 'options') {
    cake_ensure_demo_dorty($pdo = db());
    json_response([
        'velikosti'     => cake_sizes(),
        'prichute'      => cake_flavors($pdo),     // dort výrobky s recepturou + kalkulací
        'dekorace'      => cake_decorations(),
        'text_na_dortu' => cake_text_opts(),
        'sazba_dph'     => 12,
        'base_g'        => CAKE_BASE_G,
    ]);
}

if ($action === 'quote' && $method === 'POST') {
    $pdo = db();
    cake_ensure_demo_dorty($pdo);
    $d = json_input();
    $porci    = max(4, min(100, (int) ($d['porci'] ?? 10)));
    $vyrobekId= (int) ($d['vyrobek_id'] ?? 0);
    $dekorace = $d['dekorace_id'] ?? 'zadna';
    $text     = trim((string) ($d['text'] ?? ''));

    $velikost = cake_pick_size($porci);
    $skala    = $velikost['skala'];

    $flavors  = cake_flavors($pdo);
    if (!$flavors) json_error('Zatím není žádný dort výrobek (obor=dort) s recepturou. Založ dort ve Výrobky.', 409);
    $flavor = null;
    foreach ($flavors as $f) { if ($f['vyrobek_id'] === $vyrobekId) { $flavor = $f; break; } }
    if (!$flavor) $flavor = $flavors[0];

    $decos = cake_decorations();
    $dekoraceObj = current(array_filter($decos, fn($p) => $p['id'] === $dekorace)) ?: $decos[0];
    $textOpts = cake_text_opts();

    // CENA z výrobku (base) × škála + příplatky
    $baseCena   = round($flavor['cena_bez_dph'] * $skala, 2);
    $materialKc = round($flavor['material_kc'] * $skala, 2);

    $polozky = [];
    $polozky[] = ['nazev' => $flavor['ikona'] . ' ' . $flavor['nazev'] . ' · ' . $velikost['label'] . ' (' . $velikost['hmotnost_g'] . ' g)', 'cena_kc' => $baseCena, 'typ' => 'dort'];
    if (($dekoraceObj['cena_kc'] ?? 0) > 0) $polozky[] = ['nazev' => 'Dekorace: ' . $dekoraceObj['nazev'], 'cena_kc' => (float) $dekoraceObj['cena_kc'], 'typ' => 'priplatek'];
    if ($text !== '')                       $polozky[] = ['nazev' => 'Text na dortu: „' . $text . '"', 'cena_kc' => (float) $textOpts['cena_kc'], 'typ' => 'priplatek'];

    $bezDPH = round(array_sum(array_column($polozky, 'cena_kc')), 2);
    $dph    = $flavor['sazba_dph'] ?: 12;
    $sDPH   = round($bezDPH * (1 + $dph / 100), 2);

    json_response([
        'velikost'   => $velikost,
        'prichut'    => $flavor,
        'dekorace'   => $dekoraceObj,
        'text'       => $text,
        'polozky'    => $polozky,
        'sazba_dph'  => $dph,
        'cena_bez_dph' => $bezDPH,
        'cena_dph'   => round($sDPH - $bezDPH, 2),
        'cena_s_dph' => $sDPH,
        'cena_per_porci' => round($sDPH / max(1, $velikost['porci']), 2),
        // 🧮 KALKULACE — materiálové náklady z receptury (× škála) + marže
        'kalkulace'  => [
            'material_kc' => $materialKc,
            'marze_kc'    => round($baseCena - $materialKc, 2),
            'marze_pct'   => $baseCena > 0 ? round(($baseCena - $materialKc) / $baseCena * 100, 1) : 0,
            'recept_polozek' => $flavor['recept_polozek'],
        ],
        'doba_pripravy_dni' => max(2, (int) ceil($porci / 20)),
    ]);
}

// ════════════════════════════════════════════════════════════
// CREATE ORDER — řádek = vyrobek_id příchuti × škála (→ standardní odpis surovin)
// ════════════════════════════════════════════════════════════
if ($action === 'create_order' && $method === 'POST') {
    $pdo = db();
    cake_ensure_demo_dorty($pdo);
    $d = json_input();
    $odberatel_id = (int) ($d['odberatel_id'] ?? 0);
    $datum_dodani = $d['datum_dodani'] ?? null;
    if (!$odberatel_id) json_error('Vyber odběratele', 400);
    if (!$datum_dodani) json_error('Vyber datum dodání', 400);

    $porci    = max(4, min(100, (int) ($d['porci'] ?? 10)));
    $vyrobekId= (int) ($d['vyrobek_id'] ?? 0);
    $dekorace = $d['dekorace_id'] ?? 'zadna';
    $text     = trim((string) ($d['text'] ?? ''));

    $velikost = cake_pick_size($porci);
    $skala    = $velikost['skala'];

    $flavors = cake_flavors($pdo);
    if (!$flavors) json_error('Není žádný dort výrobek s recepturou — založ dort ve Výrobky (obor=dort).', 409);
    $flavor = null;
    foreach ($flavors as $f) { if ($f['vyrobek_id'] === $vyrobekId) { $flavor = $f; break; } }
    if (!$flavor) $flavor = $flavors[0];

    $decos = cake_decorations();
    $dekoraceObj = current(array_filter($decos, fn($p) => $p['id'] === $dekorace)) ?: $decos[0];
    $textOpts = cake_text_opts();

    $dph        = (float) ($flavor['sazba_dph'] ?: 12);
    $dortRadek  = round($flavor['cena_bez_dph'] * $skala, 2); // cena dortu bez DPH (= cena výr. × škála)
    $dekoKc     = (float) ($dekoraceObj['cena_kc'] ?? 0);
    $textKc     = $text !== '' ? (float) $textOpts['cena_kc'] : 0;
    $bezDPH     = round($dortRadek + $dekoKc + $textKc, 2);
    $sDPH       = round($bezDPH * (1 + $dph / 100), 2);

    try {
        $cislo = kanal_dalsi_cislo($pdo, 'dort');
        $pdo->beginTransaction();

        $popis = $flavor['nazev'] . ' · ' . $velikost['label']
               . ($dekoKc > 0 ? ' · ' . $dekoraceObj['nazev'] : '')
               . ($text !== '' ? ' · text: „' . $text . '"' : '');

        $pdo->prepare("
            INSERT INTO objednavky (cislo, odberatel_id, datum_objednani, datum_dodani,
                                    castka_bez_dph, castka_dph, castka_celkem, stav, poznamka, puvod)
            VALUES (:c, :o, NOW(), :dd, :bd, :dd2, :sd, 'nova', :p, 'dort')
        ")->execute([
            'c' => $cislo, 'o' => $odberatel_id, 'dd' => $datum_dodani,
            'bd' => $bezDPH, 'dd2' => round($sDPH - $bezDPH, 2), 'sd' => $sDPH,
            'p' => "🎂 Dort z konfigurátoru:\n" . $popis . "\n(receptura × " . rtrim(rtrim(number_format($skala, 2, '.', ''), '0'), '.') . ")",
        ]);
        $objId = (int) $pdo->lastInsertId();

        // ŘÁDEK 1 — DORT = reálný výrobek příchuti, mnozstvi = škála velikosti.
        //   cena_bez_dph je PER bázovou jednotku (1000 g) → × mnozstvi(škála) = cena dortu.
        //   → výroba/odpis surovin/inventura/kalkulace jedou standardně přes vyrobek_id.
        $pdo->prepare("
            INSERT INTO objednavky_polozky
            (objednavka_id, vyrobek_id, vyrobek_nazev, jednotka, mnozstvi, cena_bez_dph, sazba_dph)
            VALUES (:o, :vid, :n, 'ks', :mn, :c, :s)
        ")->execute([
            'o'  => $objId,
            'vid'=> $flavor['vyrobek_id'],
            'n'  => '🎂 ' . $flavor['nazev'] . ' · ' . $velikost['label'] . ' (' . $velikost['hmotnost_g'] . ' g)',
            'mn' => $skala,
            'c'  => $flavor['cena_bez_dph'],
            's'  => $dph,
        ]);

        // ŘÁDEK 2/3 — dekorace / text = volné příplatky (bez receptury, bez odpisu)
        if ($dekoKc > 0) {
            $pdo->prepare("INSERT INTO objednavky_polozky (objednavka_id, vyrobek_id, vyrobek_nazev, jednotka, mnozstvi, cena_bez_dph, sazba_dph)
                           VALUES (:o, NULL, :n, 'ks', 1, :c, :s)")
                ->execute(['o' => $objId, 'n' => 'Dekorace: ' . $dekoraceObj['nazev'], 'c' => $dekoKc, 's' => $dph]);
        }
        if ($textKc > 0) {
            $pdo->prepare("INSERT INTO objednavky_polozky (objednavka_id, vyrobek_id, vyrobek_nazev, jednotka, mnozstvi, cena_bez_dph, sazba_dph)
                           VALUES (:o, NULL, :n, 'ks', 1, :c, :s)")
                ->execute(['o' => $objId, 'n' => 'Text na dortu: „' . mb_substr($text, 0, 40) . '"', 'c' => $textKc, 's' => $dph]);
        }

        $pdo->commit();

        try { if (function_exists('notifikace_nova_objednavka')) notifikace_nova_objednavka($pdo, $objId); } catch (Throwable $e) {}

        json_response([
            'ok'       => true,
            'id'       => $objId,
            'cislo'    => $cislo,
            'castka'   => $sDPH,
            'vyrobek_id' => $flavor['vyrobek_id'],
            'mnozstvi' => $skala,
            'message'  => 'Objednávka vytvořena — dort se vyrobí ze surovin dle receptury (× ' . rtrim(rtrim(number_format($skala, 2, '.', ''), '0'), '.') . ').',
        ], 201);
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        json_error_safe('Chyba vytvoření', $e, 500);
    }
}

json_error('Neznámá akce (options|quote|create_order)', 404);
