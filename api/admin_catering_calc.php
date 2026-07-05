<?php
/**
 * 🥗 CATERING KALKULÁTOR — Lahůdky balíček
 *
 * 🆕 v3.0.298 — PROVÁZÁNO SE SUROVINAMI / VÝROBOU / KALKULACÍ (jako dort konfigurátor).
 *   Položky jídla se mapují na REÁLNÉ lahůdkové výrobky (obor='lahudka') s recepturou.
 *   Cena a materiálové náklady (kalkulace) berou z výrobku; create_order zakládá objednávku
 *   (kanál 'catering', řada CAT-) s řádky `vyrobek_id × mnozstvi` → výroba/odpis surovin/
 *   inventura/kalkulace běží STANDARDNÍM tokem. Nenamapované položky + nápoje = volné řádky.
 *
 *   GET  ?action=options       — typy událostí + položky (s vyrobek_id kde existuje) + nápoje
 *   POST ?action=quote         — { osob, typ_udalosti, prilohy[], napoje[] } → cena + kalkulace
 *   POST ?action=create_order  — { osob, typ_udalosti, prilohy[], napoje[], odberatel_id, datum_dodani }
 *
 * Vyžaduje balíček 'lahudky'.
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/_admin_auth.php';
require_once __DIR__ . '/_packages_lib.php';

cors_headers();
require_admin();
header('Content-Type: application/json; charset=UTF-8');

if (!package_enabled('lahudky')) {
    http_response_code(402);
    json_response(['error' => 'Vyžaduje balíček 🥗 Lahůdky']);
}

$action = $_GET['action'] ?? '';
$method = $_SERVER['REQUEST_METHOD'];

function catering_typy(): array {
    return [
        ['id' => 'standard',  'nazev' => 'Standard (recepce, pohoštění)', 'ikona' => '🍽️', 'koef' => 1.0],
        ['id' => 'firemni',   'nazev' => 'Firemní (raut, meeting)',        'ikona' => '🏢', 'koef' => 1.1],
        ['id' => 'svatba',    'nazev' => 'Svatba',                         'ikona' => '💍', 'koef' => 1.5],
        ['id' => 'narozeniny','nazev' => 'Narozeniny / oslava',            'ikona' => '🎂', 'koef' => 1.3],
        ['id' => 'pohreb',    'nazev' => 'Pohřební hostina',               'ikona' => '🕊️', 'koef' => 0.9],
        ['id' => 'pohoseni',  'nazev' => 'Krátké pohoštění',                'ikona' => '🥂', 'koef' => 0.6],
    ];
}

// Jídlo: per_osobu plánovací heuristika + klíčová slova pro napárování na reálný výrobek (obor='lahudka').
function catering_jidlo(): array {
    return [
        'chlebicky'    => ['nazev' => '🥪 Chlebíčky',          'per_osobu' => 3,    'cena_kc' => 18,  'jednotka' => 'ks', 'match' => ['chlebíč', 'chlebic']],
        'jednohubky'   => ['nazev' => '🍢 Jednohubky',         'per_osobu' => 4,    'cena_kc' => 12,  'jednotka' => 'ks', 'match' => ['jednohub']],
        'wraps'        => ['nazev' => '🌯 Wraps',               'per_osobu' => 1.5,  'cena_kc' => 45,  'jednotka' => 'ks', 'match' => ['wrap']],
        'salaty'       => ['nazev' => '🥗 Saláty',              'per_osobu' => 0.20, 'cena_kc' => 95,  'jednotka' => 'kg', 'match' => ['oblož', 'obloz', 'salát', 'salat']],
        'mini_kolacky' => ['nazev' => '🧁 Mini koláčky',        'per_osobu' => 2,    'cena_kc' => 22,  'jednotka' => 'ks', 'match' => ['koláč', 'kolac']],
        'zakousky'     => ['nazev' => '🍰 Zákusky / dezerty',   'per_osobu' => 1,    'cena_kc' => 38,  'jednotka' => 'ks', 'match' => ['zákus', 'zakus']],
        'ovoce'        => ['nazev' => '🍇 Ovocné mísy',         'per_osobu' => 0.15, 'cena_kc' => 80,  'jednotka' => 'kg', 'match' => ['ovocn']],
        'syrove_misy'  => ['nazev' => '🧀 Sýrové mísy',         'per_osobu' => 0.10, 'cena_kc' => 180, 'jednotka' => 'kg', 'match' => ['sýrová mís', 'syrova mis']],
        'tatarak'      => ['nazev' => '🥩 Tatarák s topinkami', 'per_osobu' => 0.08, 'cena_kc' => 320, 'jednotka' => 'kg', 'match' => ['tatar']],
        'pastiky'      => ['nazev' => '🥖 Paštiky',             'per_osobu' => 0.05, 'cena_kc' => 280, 'jednotka' => 'kg', 'match' => ['paštik', 'pastik']],
    ];
}
function catering_napoje(): array {
    return [
        'voda'         => ['nazev' => '💧 Voda neperlivá (0.33L)', 'per_osobu' => 1,   'cena_kc' => 12, 'jednotka' => 'ks', 'match' => ['voda neperliv']],
        'voda_perliva' => ['nazev' => '💧 Voda perlivá (0.33L)',  'per_osobu' => 0.5, 'cena_kc' => 14, 'jednotka' => 'ks', 'match' => ['voda perliv']],
        'dzus'         => ['nazev' => '🧃 Džus (0.2L)',           'per_osobu' => 0.6, 'cena_kc' => 22, 'jednotka' => 'ks', 'match' => ['džus', 'dzus']],
        'kafa'         => ['nazev' => '☕ Káva',                  'per_osobu' => 0.8, 'cena_kc' => 25, 'jednotka' => 'ks', 'match' => ['káva', 'kava']],
        'caj'          => ['nazev' => '🍵 Čaj',                   'per_osobu' => 0.4, 'cena_kc' => 18, 'jednotka' => 'ks', 'match' => ['čaj', 'caj']],
        'pivo'         => ['nazev' => '🍺 Pivo (0.5L)',           'per_osobu' => 0.8, 'cena_kc' => 38, 'jednotka' => 'ks', 'match' => ['pivo']],
        'vino'         => ['nazev' => '🍷 Víno (0.2L)',           'per_osobu' => 0.5, 'cena_kc' => 55, 'jednotka' => 'ks', 'match' => ['víno', 'vino']],
        'sekt'         => ['nazev' => '🥂 Sekt (0.15L)',          'per_osobu' => 0.4, 'cena_kc' => 75, 'jednotka' => 'ks', 'match' => ['sekt']],
    ];
}

const CATERING_DPH = 12;

// ════════════════════════════════════════════════════════════
// 🆕 v3.0.306 — ADMIN-EDITOVATELNÝ CONFIG (nastaveni klic='catering_config')
//   Default = hardcoded typy/jídlo/nápoje. Admin může položky přidat/upravit/smazat,
//   nastavit per_osobu/cenu/jednotku a EXPLICITNĚ napárovat na výrobek (odpis ze skladu).
// ════════════════════════════════════════════════════════════
function catering_default_config(): array {
    return [
        'typy_udalosti' => catering_typy(),
        'jidlo'         => catering_jidlo(),
        'napoje'        => catering_napoje(),
        'dph'           => CATERING_DPH,
    ];
}
function catering_config(PDO $pdo): array {
    static $cache = null;
    if ($cache !== null) return $cache;
    $cfg = catering_default_config();
    try {
        $raw = $pdo->query("SELECT hodnota FROM nastaveni WHERE klic = 'catering_config' LIMIT 1")->fetchColumn();
        if ($raw) {
            $saved = json_decode($raw, true);
            if (is_array($saved)) {
                if (!empty($saved['typy_udalosti']) && is_array($saved['typy_udalosti'])) $cfg['typy_udalosti'] = array_values($saved['typy_udalosti']);
                if (isset($saved['jidlo'])  && is_array($saved['jidlo']))  $cfg['jidlo']  = $saved['jidlo'];
                if (isset($saved['napoje']) && is_array($saved['napoje'])) $cfg['napoje'] = $saved['napoje'];
                if (isset($saved['dph']))   $cfg['dph'] = (float) $saved['dph'];
            }
        }
    } catch (Throwable $e) {}
    return $cache = $cfg;
}
function catering_cfg_typy(PDO $pdo): array   { return catering_config($pdo)['typy_udalosti'] ?: catering_typy(); }
function catering_cfg_jidlo(PDO $pdo): array  { return catering_config($pdo)['jidlo']  ?? catering_jidlo(); }
function catering_cfg_napoje(PDO $pdo): array { return catering_config($pdo)['napoje'] ?? catering_napoje(); }
function catering_cfg_dph(PDO $pdo): float    { return (float) (catering_config($pdo)['dph'] ?? CATERING_DPH); }

// Materiálové náklady výrobku z receptury (Σ mnozstvi × jednotková cena suroviny).
function catering_material_cost(PDO $pdo, int $vyrobek_id): float {
    try {
        $st = $pdo->prepare("
            SELECT COALESCE(SUM(vs.mnozstvi * (s.cena_baleni / NULLIF(s.obsah_baleni, 0))), 0)
            FROM vyrobek_suroviny vs JOIN suroviny s ON s.id = vs.surovina_id
            WHERE vs.vyrobek_id = :v
        ");
        $st->execute(['v' => $vyrobek_id]);
        return round((float) $st->fetchColumn(), 2);
    } catch (Throwable $e) { return 0.0; }
}

// Napáruje klíče položek na reálné výrobky (preferuje obor='lahudka', jinak shoda názvu).
//   Vrací: klic => ['vyrobek_id','nazev','cena','material','dph','recept_polozek']
function catering_resolve_vyrobky(PDO $pdo): array {
    $vyr = [];
    try {
        $vyr = $pdo->query("
            SELECT v.id, v.nazev, v.cena_bez_dph, v.obor, COALESCE(sd.sazba, 12) AS dph,
                   (SELECT COUNT(*) FROM vyrobek_suroviny WHERE vyrobek_id = v.id) AS recept
            FROM vyrobky v LEFT JOIN sazby_dph sd ON sd.id = v.sazba_dph_id
            WHERE v.aktivni = 1
        ")->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) { $vyr = []; }

    $pack = function (array $v) use ($pdo) {
        return [
            'vyrobek_id' => (int) $v['id'],
            'nazev'      => $v['nazev'],
            'cena'       => round((float) $v['cena_bez_dph'], 2),
            'material'   => catering_material_cost($pdo, (int) $v['id']),
            'dph'        => (float) $v['dph'],
            'recept_polozek' => (int) $v['recept'],
        ];
    };
    $resolve = function (array $item) use ($vyr, $pack) {
        // 1) EXPLICITNÍ link (admin napároval) má přednost — povol jakýkoli aktivní výrobek.
        $eid = (int) ($item['vyrobek_id'] ?? 0);
        if ($eid > 0) {
            foreach ($vyr as $v) if ((int) $v['id'] === $eid) return $pack($v);
            return null; // napárovaný výrobek smazán/neaktivní → volný řádek
        }
        // 2) Fuzzy match POUZE výrobky obor='lahudka' s recepturou (jinak by „ovocné mísy" chytlo
        //    Ovocný dort a „sýrové mísy" chlebíček „…sýrový"). Nenamapované → volný řádek.
        foreach ($vyr as $v) {
            if (($v['obor'] ?? '') !== 'lahudka' || (int) $v['recept'] <= 0) continue;
            $n = mb_strtolower((string) $v['nazev']);
            foreach ((array) ($item['match'] ?? []) as $m) {
                if ($m !== '' && str_contains($n, $m)) return $pack($v);
            }
        }
        return null;
    };

    $out = [];
    foreach (array_merge(catering_cfg_jidlo($pdo), catering_cfg_napoje($pdo)) as $key => $item) {
        $out[$key] = $resolve((array) $item);
    }
    return $out;
}

// ════════════════════════════════════════════════════════════
// SEED — idempotentní: lahůdkové výrobky s recepturou (jen když žádný obor='lahudka' neexistuje)
// ════════════════════════════════════════════════════════════
function catering_ensure_demo_lahudky(PDO $pdo): void {
    try {
        $cnt = (int) $pdo->query("SELECT COUNT(*) FROM vyrobky WHERE obor = 'lahudka'")->fetchColumn();
        if ($cnt > 0) return;
    } catch (Throwable $e) { return; }

    $katId = (int) $pdo->query("SELECT id FROM kategorie_vyrobku WHERE nazev LIKE '%Sendvič%' OR nazev LIKE '%Lahůd%' LIMIT 1")->fetchColumn();
    if (!$katId) {
        try {
            $maxPor = (int) $pdo->query("SELECT COALESCE(MAX(poradi),0)+1 FROM kategorie_vyrobku")->fetchColumn();
            $pdo->prepare("INSERT INTO kategorie_vyrobku (nazev, ikona, poradi) VALUES ('Lahůdky', '🥗', :p)")->execute(['p' => $maxPor]);
            $katId = (int) $pdo->lastInsertId();
        } catch (Throwable $e) { $katId = (int) $pdo->query("SELECT id FROM kategorie_vyrobku ORDER BY id LIMIT 1")->fetchColumn(); }
    }
    $dph12 = (int) $pdo->query("SELECT id FROM sazby_dph WHERE sazba = 12 LIMIT 1")->fetchColumn() ?: (int) $pdo->query("SELECT id FROM sazby_dph ORDER BY id LIMIT 1")->fetchColumn();
    $jedKs = (int) $pdo->query("SELECT id FROM jednotky WHERE kod = 'ks' LIMIT 1")->fetchColumn() ?: 1;
    $jedKg = (int) $pdo->query("SELECT id FROM jednotky WHERE kod = 'kg' LIMIT 1")->fetchColumn() ?: $jedKs;

    $sur = [];
    foreach ($pdo->query("SELECT id, nazev FROM suroviny")->fetchAll(PDO::FETCH_ASSOC) as $s) $sur[mb_strtolower($s['nazev'])] = (int) $s['id'];
    $find = function (array $needles) use ($sur) {
        foreach ($sur as $n => $id) foreach ($needles as $ndl) if (str_contains($n, $ndl)) return $id;
        return null;
    };
    $S = [
        'mouka' => $find(['mouka pšeničná hladká', 'mouka hladká', 'mouka pšeničná']),
        'maslo' => $find(['máslo']),
        'sunka' => $find(['šunka']),
        'syr'   => $find(['sýr eidam', 'sýr', 'eidam']),
        'vejce' => $find(['vejce']),
        'salat' => $find(['salát ledový', 'salát', 'salat']),
        'rajce' => $find(['rajčata', 'rajče', 'rajce']),
        'olej'  => $find(['olej']),
    ];

    // base: 1 ks (chlebíček/jednohubka/wrap) nebo 1 kg (salát). receptura: [klíč, mnozstvi, jednotka]
    $items = [
        ['nazev' => 'Chlebíček šunkový-sýrový', 'cena' => 18, 'jed' => $jedKs, 'jedKod' => 'ks', 'recept' => [
            ['mouka',40,'g'],['maslo',8,'g'],['sunka',25,'g'],['syr',15,'g'],['vejce',0.2,'ks']]],
        ['nazev' => 'Jednohubka', 'cena' => 12, 'jed' => $jedKs, 'jedKod' => 'ks', 'recept' => [
            ['mouka',15,'g'],['maslo',4,'g'],['sunka',12,'g'],['syr',8,'g']]],
        ['nazev' => 'Wrap kuřecí', 'cena' => 45, 'jed' => $jedKs, 'jedKod' => 'ks', 'recept' => [
            ['mouka',60,'g'],['sunka',40,'g'],['syr',20,'g'],['salat',20,'g'],['rajce',20,'g']]],
        ['nazev' => 'Obložený salát', 'cena' => 240, 'jed' => $jedKg, 'jedKod' => 'kg', 'recept' => [
            ['salat',400,'g'],['rajce',200,'g'],['syr',150,'g'],['vejce',3,'ks'],['olej',50,'ml']]],
    ];

    $vcols = [];
    try { $vcols = $pdo->query("SHOW COLUMNS FROM vyrobky")->fetchAll(PDO::FETCH_COLUMN); } catch (Throwable $e) {}
    $has = fn($c) => in_array($c, $vcols, true);

    $pdo->beginTransaction();
    try {
        $pordBase = (int) $pdo->query("SELECT COALESCE(MAX(poradi),0) FROM vyrobky")->fetchColumn();
        $i = 0;
        foreach ($items as $it) {
            $i++;
            $fields = ['nazev' => $it['nazev'], 'aktivni' => 1];
            if ($has('obor'))         $fields['obor']         = 'lahudka';
            if ($has('cena_bez_dph')) $fields['cena_bez_dph'] = $it['cena'];
            if ($has('kategorie_id')) $fields['kategorie_id'] = $katId;
            if ($has('jednotka_id'))  $fields['jednotka_id']  = $it['jed'];
            if ($has('sazba_dph_id')) $fields['sazba_dph_id'] = $dph12;
            if ($has('jednotka'))     $fields['jednotka']     = $it['jedKod'];
            if ($has('poradi'))       $fields['poradi']       = $pordBase + $i;
            if ($has('cislo'))        $fields['cislo']        = 'LAH-' . str_pad((string) $i, 2, '0', STR_PAD_LEFT);
            if ($has('popis'))        $fields['popis']        = 'Lahůdka — receptura na 1 ' . $it['jedKod'] . ', množství škáluje catering kalkulačka.';

            $cols = array_keys($fields);
            $pdo->prepare('INSERT INTO vyrobky (' . implode(',', $cols) . ') VALUES (' . implode(',', array_map(fn($c) => ':' . $c, $cols)) . ')')->execute($fields);
            $vid = (int) $pdo->lastInsertId();

            $por = 0;
            foreach ($it['recept'] as $r) {
                $sid = $S[$r[0]] ?? null;
                if (!$sid) continue;
                $por++;
                $pdo->prepare("INSERT INTO vyrobek_suroviny (vyrobek_id, surovina_id, mnozstvi, jednotka, poradi) VALUES (:v,:s,:m,:j,:p)")
                    ->execute(['v' => $vid, 's' => $sid, 'm' => $r[1], 'j' => $r[2], 'p' => $por]);
            }
        }
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
    }
}

// Spočítá položky pro zadané vstupy (sdílené quote + create_order)
function catering_compute(PDO $pdo, array $d): array {
    $osob   = max(2, min(500, (int) ($d['osob'] ?? 20)));
    $typId  = $d['typ_udalosti'] ?? 'standard';
    $vJidlo = (array) ($d['prilohy'] ?? []);
    $vNapoj = (array) ($d['napoje'] ?? []);

    $typy = catering_cfg_typy($pdo);
    $typ = current(array_filter($typy, fn($t) => $t['id'] === $typId)) ?: $typy[0];
    $koef = (float) $typ['koef'];
    $dphDef = catering_cfg_dph($pdo);

    $vyrMap = catering_resolve_vyrobky($pdo);
    $all = array_merge(catering_cfg_jidlo($pdo), catering_cfg_napoje($pdo));
    $vybrano = array_values(array_unique(array_merge($vJidlo, $vNapoj)));

    $polozky = [];
    foreach ($vybrano as $key) {
        if (!isset($all[$key])) continue;
        $item = $all[$key];
        $mnozstvi = round($item['per_osobu'] * $osob * $koef, 2);
        if ($mnozstvi <= 0) continue;
        $vyr = $vyrMap[$key] ?? null;
        $cenaJed = $vyr ? $vyr['cena'] : (float) $item['cena_kc'];   // cena z výrobku má přednost
        $cena    = round($mnozstvi * $cenaJed, 2);
        $polozky[] = [
            'klic'      => $key,
            'nazev'     => $vyr ? $vyr['nazev'] : $item['nazev'],
            'mnozstvi'  => $mnozstvi,
            'jednotka'  => $item['jednotka'],
            'cena_per_jednotku' => $cenaJed,
            'cena_kc'   => $cena,
            'vyrobek_id'=> $vyr['vyrobek_id'] ?? null,
            'material_kc' => $vyr ? round($vyr['material'] * $mnozstvi, 2) : null,
            'dph'       => $vyr ? $vyr['dph'] : $dphDef,
            'odecte_sklad' => (bool) $vyr,
        ];
    }

    // 🆕 v3.0.407 — MENU Z VÝROBKŮ (vzor catering SW: menu builder z produktů).
    //   d['menu'] = [{vyrobek_id, per_osobu}] — cena/DPH/název VŽDY z výrobku,
    //   množství = per_osobu × osob × koef, odpis surovin standardně přes
    //   vyrobek_id řádek → výroba → BOM (když má výrobek recepturu).
    $menu = (array) ($d['menu'] ?? []);
    if ($menu) {
        $ids = array_values(array_unique(array_filter(array_map(fn($m) => (int) ($m['vyrobek_id'] ?? 0), $menu))));
        if ($ids) {
            $ph = implode(',', array_fill(0, count($ids), '?'));
            $st = $pdo->prepare("
                SELECT v.id, v.nazev, v.cena_bez_dph, COALESCE(sd.sazba, 12) AS dph,
                       COALESCE(j.kod, 'ks') AS jednotka,
                       (SELECT COUNT(*) FROM vyrobek_suroviny WHERE vyrobek_id = v.id) AS recept
                FROM vyrobky v
                LEFT JOIN sazby_dph sd ON sd.id = v.sazba_dph_id
                LEFT JOIN jednotky j ON j.id = v.jednotka_id
                WHERE v.aktivni = 1 AND v.id IN ($ph)
            ");
            $st->execute($ids);
            $vmap = [];
            foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $v) $vmap[(int) $v['id']] = $v;
            foreach ($menu as $m) {
                $vid = (int) ($m['vyrobek_id'] ?? 0);
                $per = max(0.01, min(50, (float) ($m['per_osobu'] ?? 1)));
                $v = $vmap[$vid] ?? null;
                if (!$v) continue; // smazaný/neaktivní výrobek přeskoč
                $mnozstvi = round($per * $osob * $koef, 2);
                if ($mnozstvi <= 0) continue;
                $cenaJed = round((float) $v['cena_bez_dph'], 2);
                $maRecept = (int) $v['recept'] > 0;
                $polozky[] = [
                    'klic'      => 'menu_' . $vid,
                    'nazev'     => $v['nazev'],
                    'mnozstvi'  => $mnozstvi,
                    'jednotka'  => $v['jednotka'],
                    'cena_per_jednotku' => $cenaJed,
                    'cena_kc'   => round($mnozstvi * $cenaJed, 2),
                    'vyrobek_id'=> $vid,
                    'material_kc' => $maRecept ? round(catering_material_cost($pdo, $vid) * $mnozstvi, 2) : null,
                    'dph'       => (float) $v['dph'],
                    'odecte_sklad' => $maRecept,
                ];
            }
        }
    }
    return ['osob' => $osob, 'typ' => $typ, 'koef' => $koef, 'polozky' => $polozky];
}

// ════════════════════════════════════════════════════════════
if ($action === 'options') {
    $pdo = db();
    catering_ensure_demo_lahudky($pdo);
    $vyrMap = catering_resolve_vyrobky($pdo);
    $deco = function (array $items) use ($vyrMap) {
        $out = [];
        foreach ($items as $k => $v) {
            $vyr = $vyrMap[$k] ?? null;
            $out[$k] = $v + ['vyrobek_id' => $vyr['vyrobek_id'] ?? null, 'material_kc' => $vyr['material'] ?? null, 'odecte_sklad' => (bool) $vyr];
            if ($vyr) { $out[$k]['cena_kc'] = $vyr['cena']; $out[$k]['vyrobek_nazev'] = $vyr['nazev']; }
        }
        return $out;
    };
    // 🆕 v3.0.407 — katalog výrobků pro „Menu z výrobků" picker (cena z výrobku, recept → odpis)
    $vyrobkyKatalog = [];
    try {
        $vyrobkyKatalog = $pdo->query("
            SELECT v.id, v.nazev, ROUND(v.cena_bez_dph, 2) AS cena, COALESCE(j.kod, 'ks') AS jednotka,
                   (SELECT COUNT(*) FROM vyrobek_suroviny WHERE vyrobek_id = v.id) > 0 AS ma_recept,
                   COALESCE(k.nazev, '') AS kategorie
            FROM vyrobky v
            LEFT JOIN jednotky j ON j.id = v.jednotka_id
            LEFT JOIN kategorie_vyrobku k ON k.id = v.kategorie_id
            WHERE v.aktivni = 1
            ORDER BY k.nazev, v.nazev
            LIMIT 500
        ")->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {}
    json_response([
        'typy_udalosti' => array_values(catering_cfg_typy($pdo)),
        'doporuceni'    => $deco(catering_cfg_jidlo($pdo)),
        'napoje'        => $deco(catering_cfg_napoje($pdo)),
        'sazba_dph'     => catering_cfg_dph($pdo),
        'vyrobky'       => $vyrobkyKatalog,
    ]);
}

// 🆕 v3.0.306 — surová konfigurace pro editor (+ seznam výrobků pro párování)
if ($action === 'config') {
    $pdo = db();
    catering_ensure_demo_lahudky($pdo);
    $vyrobky = [];
    try {
        $vyrobky = $pdo->query("
            SELECT v.id, v.nazev, v.obor, COALESCE(v.cena_bez_dph,0) AS cena_bez_dph,
                   (SELECT COUNT(*) FROM vyrobek_suroviny WHERE vyrobek_id = v.id) AS recept
            FROM vyrobky v WHERE v.aktivni = 1 ORDER BY (v.obor='lahudka') DESC, v.nazev
        ")->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {}
    json_response([
        'config'   => catering_config($pdo),
        'default'  => catering_default_config(),
        'vyrobky'  => array_map(fn($v) => [
            'id' => (int) $v['id'], 'nazev' => $v['nazev'], 'obor' => $v['obor'],
            'cena' => round((float) $v['cena_bez_dph'], 2), 'ma_recept' => ((int) $v['recept']) > 0,
        ], $vyrobky),
    ]);
}

// 🆕 v3.0.306 — uložení konfigurace (jen super_admin)
if ($action === 'save_config' && $method === 'POST') {
    $pdo = db();
    require_super_admin();
    $in = json_input();
    $cfg = is_array($in['config'] ?? null) ? $in['config'] : $in;

    // sanitizace: jídlo/nápoje = mapa key => {nazev,per_osobu,cena_kc,jednotka,vyrobek_id?,match?}
    $cleanItems = function ($items) {
        $out = [];
        if (!is_array($items)) return $out;
        foreach ($items as $key => $it) {
            $key = preg_replace('~[^a-z0-9_]~', '', mb_strtolower((string) $key));
            if ($key === '' || !is_array($it)) continue;
            $nazev = trim((string) ($it['nazev'] ?? ''));
            if ($nazev === '') continue;
            $jed = in_array(($it['jednotka'] ?? 'ks'), ['ks', 'kg', 'l', 'porce'], true) ? $it['jednotka'] : 'ks';
            $out[$key] = [
                'nazev'      => mb_substr($nazev, 0, 60),
                'per_osobu'  => max(0, round((float) ($it['per_osobu'] ?? 0), 4)),
                'cena_kc'    => max(0, round((float) ($it['cena_kc'] ?? 0), 2)),
                'jednotka'   => $jed,
                'vyrobek_id' => (int) ($it['vyrobek_id'] ?? 0) ?: null,
                'match'      => array_values(array_filter(array_map(fn($m) => trim((string) $m), (array) ($it['match'] ?? [])), fn($m) => $m !== '')),
            ];
        }
        return $out;
    };
    $cleanTypy = function ($typy) {
        $out = [];
        foreach ((array) $typy as $t) {
            $id = preg_replace('~[^a-z0-9_]~', '', mb_strtolower((string) ($t['id'] ?? '')));
            $nazev = trim((string) ($t['nazev'] ?? ''));
            if ($id === '' || $nazev === '') continue;
            $out[] = ['id' => $id, 'nazev' => mb_substr($nazev, 0, 50), 'ikona' => mb_substr(trim((string) ($t['ikona'] ?? '🍽️')), 0, 4) ?: '🍽️', 'koef' => max(0.1, min(5, round((float) ($t['koef'] ?? 1), 2)))];
        }
        return $out;
    };
    $save = [
        'typy_udalosti' => $cleanTypy($cfg['typy_udalosti'] ?? []) ?: catering_typy(),
        'jidlo'         => $cleanItems($cfg['jidlo'] ?? []),
        'napoje'        => $cleanItems($cfg['napoje'] ?? []),
        'dph'           => max(0, min(30, round((float) ($cfg['dph'] ?? CATERING_DPH), 2))),
    ];
    if (empty($save['jidlo']) && empty($save['napoje'])) json_error('Musí zůstat aspoň jedna položka (jídlo nebo nápoj)', 400);
    try {
        $json = json_encode($save, JSON_UNESCAPED_UNICODE);
        $pdo->prepare("INSERT INTO nastaveni (klic, hodnota) VALUES ('catering_config', :v) ON DUPLICATE KEY UPDATE hodnota = :v2")
            ->execute(['v' => $json, 'v2' => $json]);
        json_response(['ok' => true, 'config' => $save]);
    } catch (Throwable $e) {
        json_error_safe('Uložení konfigurace selhalo', $e, 500);
    }
}

// 🆕 v3.0.306 — upload fotky předlohy (sdílené s konfigurátorem dortů)
if ($action === 'upload_predloha' && $method === 'POST') {
    require_once __DIR__ . '/_upload_lib.php';
    $url = upload_predloha_image('foto');
    json_response(['ok' => true, 'url' => $url, 'nazev' => $_FILES['foto']['name'] ?? '']);
}

if ($action === 'quote' && $method === 'POST') {
    $pdo = db();
    catering_ensure_demo_lahudky($pdo);
    $c = catering_compute($pdo, json_input());
    $dph = catering_cfg_dph($pdo);
    $bezDPH = round(array_sum(array_column($c['polozky'], 'cena_kc')), 2);
    $material = round(array_sum(array_map(fn($p) => (float) ($p['material_kc'] ?? 0), $c['polozky'])), 2);
    $sDPH = round($bezDPH * (1 + $dph / 100), 2);
    $odecitanych = count(array_filter($c['polozky'], fn($p) => $p['odecte_sklad']));
    json_response([
        'osob'        => $c['osob'],
        'typ'         => $c['typ'],
        'polozky'     => $c['polozky'],
        'sazba_dph'   => $dph,
        'cena_bez_dph'=> $bezDPH,
        'cena_dph'    => round($sDPH - $bezDPH, 2),
        'cena_s_dph'  => $sDPH,
        'cena_per_osobu' => round($sDPH / max(1, $c['osob']), 2),
        'kalkulace'   => [
            'material_kc' => $material,
            'marze_kc'    => round($bezDPH - $material, 2),
            'marze_pct'   => $bezDPH > 0 ? round(($bezDPH - $material) / $bezDPH * 100, 1) : 0,
            'polozek_se_skladem' => $odecitanych,
            'polozek_celkem'     => count($c['polozky']),
        ],
    ]);
}

// ════════════════════════════════════════════════════════════
// CREATE ORDER — řádky s vyrobek_id (kde napárováno) × mnozstvi → standardní odpis
// ════════════════════════════════════════════════════════════
if ($action === 'create_order' && $method === 'POST') {
    $pdo = db();
    catering_ensure_demo_lahudky($pdo);
    $d = json_input();
    $odberatel_id = (int) ($d['odberatel_id'] ?? 0);
    $datum_dodani = $d['datum_dodani'] ?? null;
    if (!$odberatel_id) json_error('Vyber odběratele', 400);
    if (!$datum_dodani) json_error('Vyber datum dodání', 400);

    $c = catering_compute($pdo, $d);
    if (empty($c['polozky'])) json_error('Nevybral jsi žádné položky', 400);

    $dph = catering_cfg_dph($pdo);
    $bezDPH = round(array_sum(array_column($c['polozky'], 'cena_kc')), 2);
    $sDPH   = round($bezDPH * (1 + $dph / 100), 2);
    // 🆕 v3.0.306 — volitelná fotka předlohy + poznámka (jako u dortů) → do poznámky objednávky
    $pozn = "🥗 Catering z kalkulačky: {$c['osob']} osob · {$c['typ']['nazev']}";
    $textPozn = trim((string) ($d['poznamka'] ?? ''));
    if ($textPozn !== '') $pozn .= "\n📝 " . mb_substr($textPozn, 0, 300);
    $foto = trim((string) ($d['foto'] ?? ''));
    if ($foto !== '' && preg_match('~^(https?://|/uploads/)~i', $foto) && mb_strlen($foto) <= 500) $pozn .= "\n📸 Předloha: " . $foto;

    try {
        $cislo = kanal_dalsi_cislo($pdo, 'catering');
        $pdo->beginTransaction();

        $pdo->prepare("
            INSERT INTO objednavky (cislo, odberatel_id, datum_objednani, datum_dodani,
                                    castka_bez_dph, castka_dph, castka_celkem, stav, poznamka, puvod)
            VALUES (:c, :o, NOW(), :dd, :bd, :dh, :sd, 'nova', :p, 'catering')
        ")->execute([
            'c' => $cislo, 'o' => $odberatel_id, 'dd' => $datum_dodani,
            'bd' => $bezDPH, 'dh' => round($sDPH - $bezDPH, 2), 'sd' => $sDPH,
            'p' => $pozn,
        ]);
        $objId = (int) $pdo->lastInsertId();

        $stmt = $pdo->prepare("
            INSERT INTO objednavky_polozky
            (objednavka_id, vyrobek_id, vyrobek_nazev, jednotka, mnozstvi, cena_bez_dph, sazba_dph)
            VALUES (:o, :vid, :n, :jed, :mn, :c, :s)
        ");
        foreach ($c['polozky'] as $p) {
            // cena_bez_dph je PER jednotku (× mnozstvi = řádek); vyrobek_id kde napárováno → odpis.
            $stmt->execute([
                'o'   => $objId,
                'vid' => $p['vyrobek_id'],   // null pro nenamapované/nápoje → volný řádek bez odpisu
                'n'   => ($p['vyrobek_id'] ? '' : '~ ') . $p['nazev'],
                'jed' => $p['jednotka'],
                'mn'  => $p['mnozstvi'],
                'c'   => $p['cena_per_jednotku'],
                's'   => $p['dph'],
            ]);
        }
        $pdo->commit();

        try { if (function_exists('notifikace_nova_objednavka')) notifikace_nova_objednavka($pdo, $objId); } catch (Throwable $e) {}

        $odecitanych = count(array_filter($c['polozky'], fn($p) => $p['odecte_sklad']));
        json_response([
            'ok'      => true,
            'id'      => $objId,
            'cislo'   => $cislo,
            'castka'  => $sDPH,
            'polozek' => count($c['polozky']),
            'odecitanych' => $odecitanych,
            'message' => "Objednávka vytvořena — {$odecitanych} z " . count($c['polozky']) . " položek se odečte ze skladu při výrobě.",
        ], 201);
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        json_error_safe('Chyba vytvoření', $e, 500);
    }
}

json_error('Neznámá akce (options|quote|create_order)', 404);
