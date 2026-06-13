<?php
/**
 * 🎂 KONFIGURÁTOR DORTŮ — Cukrárna balíček
 *
 * 🆕 v3.0.299 — PLNĚ ADMIN-KONFIGUROVATELNÝ (nastaveni.cake_config JSON).
 *   - Velikosti editovatelné (label/hmotnost/NÁSOBIČ). Cena = příchuť.cena × nasobic.
 *   - Skupiny možností (single/multi) s volbami; volba linkuje SUROVINU nebo VÝROBEK
 *     (+ množství) → odečte se ze skladu + počítá do kalkulace. Volba bez linku = příplatek.
 *   - Příchuť = dort výrobek (obor='dort') s recepturou (v3.0.297, beze změny).
 *   create_order: řádek příchuti (vyrobek_id × nasobic) + volby (vyrobek_id×mn / surovina_id×mn
 *     / volný příplatek). Surovina-volby odečítá výroba přes objednavky_polozky.surovina_id.
 *
 *   GET  ?action=options       — config (velikosti+moznosti) + příchutě + materiálové náklady
 *   POST ?action=quote         — { vyrobek_id, velikost_id|porci, volby:{skupina:volba|[volby]}, text }
 *   POST ?action=create_order  — quote vstup + { odberatel_id, datum_dodani }
 *   GET  ?action=config        — surová konfigurace (pro editor)
 *   POST ?action=save_config   — uloží konfiguraci (super_admin)
 *
 * Vyžaduje balíček 'cukrarna'.
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
const CAKE_BASE_G = 1000.0;

// ════════════════════════════════════════════════════════════
// 🆕 v3.0.305 — UPLOAD FOTKY PŘEDLOHY (reference foto k dortu, volitelné/zdarma)
//   POST ?action=upload_predloha (multipart: 'foto') → /uploads/predlohy/predloha_*.ext → { url }
//   Unikátní jména (víc objednávek) · zmenší na max 1600 px · .htaccess zakáže PHP.
// ════════════════════════════════════════════════════════════
if ($method === 'POST' && $action === 'upload_predloha') {
    require_once __DIR__ . '/_upload_lib.php';
    $url = upload_predloha_image('foto');   // sdílené s catering kalkulačkou
    json_response(['ok' => true, 'url' => $url, 'nazev' => $_FILES['foto']['name'] ?? '']);
}

// ════════════════════════════════════════════════════════════
// KONFIGURACE — default + load/save (nastaveni klic='cake_config')
// ════════════════════════════════════════════════════════════
function cake_default_config(PDO $pdo): array {
    // resolve surovina_id podle názvu (pro out-of-box odpis u pár voleb)
    $sur = [];
    try { foreach ($pdo->query("SELECT id, nazev FROM suroviny")->fetchAll(PDO::FETCH_ASSOC) as $s) $sur[mb_strtolower($s['nazev'])] = (int) $s['id']; } catch (Throwable $e) {}
    $find = function (array $needles) use ($sur) {
        foreach ($sur as $n => $id) foreach ($needles as $x) if (str_contains($n, $x)) return $id;
        return null;
    };
    $sLink = function (?int $id, float $mn) {
        return $id ? ['link_typ' => 'surovina', 'link_id' => $id, 'mnozstvi' => $mn, 'jednotka' => 'g']
                   : ['link_typ' => 'none', 'link_id' => null, 'mnozstvi' => 0, 'jednotka' => ''];
    };

    return [
        'velikosti' => [
            ['id' => 'mini',    'label' => 'Mini (6 porcí)',     'porci' => 6,  'hmotnost_g' => 600,  'nasobic' => 0.6, 'prumer_cm' => 16],
            ['id' => 'maly',    'label' => 'Malý (10 porcí)',    'porci' => 10, 'hmotnost_g' => 1000, 'nasobic' => 1.0, 'prumer_cm' => 20],
            ['id' => 'stredni', 'label' => 'Střední (14 porcí)', 'porci' => 14, 'hmotnost_g' => 1400, 'nasobic' => 1.4, 'prumer_cm' => 22],
            ['id' => 'velky',   'label' => 'Velký (20 porcí)',   'porci' => 20, 'hmotnost_g' => 2000, 'nasobic' => 2.0, 'prumer_cm' => 26],
            ['id' => 'xl',      'label' => 'XL (30 porcí)',      'porci' => 30, 'hmotnost_g' => 3000, 'nasobic' => 3.0, 'prumer_cm' => 30],
            ['id' => 'patrovy', 'label' => 'Patrový (50 porcí)', 'porci' => 50, 'hmotnost_g' => 5000, 'nasobic' => 5.0, 'prumer_cm' => 36],
        ],
        'moznosti' => [
            ['id' => 'naplne', 'nazev' => 'Náplň navíc', 'typ' => 'single', 'povinne' => false, 'volby' => [
                ['id' => 'zakl',   'nazev' => 'Základní krém',       'priplatek_kc' => 0,  'link_typ' => 'none', 'link_id' => null, 'mnozstvi' => 0, 'jednotka' => ''],
                array_merge(['id' => 'marmelada', 'nazev' => 'Marmeládová vrstva', 'priplatek_kc' => 60], $sLink($find(['marmeláda']), 120)),
                array_merge(['id' => 'cokovrstva', 'nazev' => 'Čokoládová vrstva',  'priplatek_kc' => 80], $sLink($find(['čokoláda']), 80)),
            ]],
            ['id' => 'poleva', 'nazev' => 'Poleva', 'typ' => 'single', 'povinne' => false, 'volby' => [
                ['id' => 'zadna',  'nazev' => 'Bez polevy',           'priplatek_kc' => 0,   'link_typ' => 'none', 'link_id' => null, 'mnozstvi' => 0, 'jednotka' => ''],
                array_merge(['id' => 'cokopoleva', 'nazev' => 'Čokoládová poleva', 'priplatek_kc' => 90],  $sLink($find(['čokoláda']), 100)),
                array_merge(['id' => 'mandle',     'nazev' => 'Mandlové plátky',   'priplatek_kc' => 70],  $sLink($find(['mandle']), 60)),
            ]],
            ['id' => 'dekorace', 'nazev' => 'Dekorace', 'typ' => 'single', 'povinne' => false, 'volby' => [
                ['id' => 'zadna',    'nazev' => 'Bez dekorace',        'priplatek_kc' => 0,   'link_typ' => 'none', 'link_id' => null, 'mnozstvi' => 0, 'jednotka' => ''],
                ['id' => 'kvety',    'nazev' => 'Květy z marcipánu',    'priplatek_kc' => 200, 'link_typ' => 'none', 'link_id' => null, 'mnozstvi' => 0, 'jednotka' => ''],
                ['id' => 'plody',    'nazev' => 'Čerstvé ovoce',        'priplatek_kc' => 180, 'link_typ' => 'none', 'link_id' => null, 'mnozstvi' => 0, 'jednotka' => ''],
                ['id' => 'svatebni', 'nazev' => 'Svatební dekor',       'priplatek_kc' => 600, 'link_typ' => 'none', 'link_id' => null, 'mnozstvi' => 0, 'jednotka' => ''],
                ['id' => 'foto',     'nazev' => 'Jedlé foto (A4)',      'priplatek_kc' => 280, 'link_typ' => 'none', 'link_id' => null, 'mnozstvi' => 0, 'jednotka' => ''],
            ]],
            ['id' => 'svicky', 'nazev' => 'Svíčky', 'typ' => 'single', 'povinne' => false, 'volby' => [
                ['id' => 'zadne',    'nazev' => 'Bez svíček',          'priplatek_kc' => 0,  'link_typ' => 'none', 'link_id' => null, 'mnozstvi' => 0, 'jednotka' => ''],
                ['id' => 'cislice',  'nazev' => 'Číslicová svíčka',     'priplatek_kc' => 40, 'link_typ' => 'none', 'link_id' => null, 'mnozstvi' => 0, 'jednotka' => ''],
                ['id' => 'prskavky', 'nazev' => 'Prskavky (sada)',      'priplatek_kc' => 60, 'link_typ' => 'none', 'link_id' => null, 'mnozstvi' => 0, 'jednotka' => ''],
            ]],
        ],
        'text_na_dortu' => ['povolit' => true, 'max_chars' => 40, 'priplatek_kc' => 80],
        'sazba_dph' => 12,
    ];
}

function cake_config(PDO $pdo): array {
    try {
        $raw = nastaveni_get($pdo, 'cake_config', null);
        if ($raw) {
            $cfg = is_array($raw) ? $raw : json_decode((string) $raw, true);
            if (is_array($cfg) && !empty($cfg['velikosti'])) {
                $cfg['sazba_dph'] = $cfg['sazba_dph'] ?? 12;
                $cfg['text_na_dortu'] = $cfg['text_na_dortu'] ?? ['povolit' => true, 'max_chars' => 40, 'priplatek_kc' => 80];
                $cfg['moznosti'] = $cfg['moznosti'] ?? [];
                return $cfg;
            }
        }
    } catch (Throwable $e) {}
    return cake_default_config($pdo);
}

function cake_config_save(PDO $pdo, array $cfg): array {
    // sanitizace + dopočet nasobic
    $velikosti = [];
    foreach (($cfg['velikosti'] ?? []) as $i => $v) {
        $hm = max(1, (float) ($v['hmotnost_g'] ?? 1000));
        $velikosti[] = [
            'id'         => (string) ($v['id'] ?? ('v' . $i)),
            'label'      => trim((string) ($v['label'] ?? ('Velikost ' . ($i + 1)))),
            'porci'      => max(1, (int) ($v['porci'] ?? 10)),
            'hmotnost_g' => $hm,
            'nasobic'    => round((float) ($v['nasobic'] ?? ($hm / CAKE_BASE_G)), 4),
            'prumer_cm'  => (int) ($v['prumer_cm'] ?? 0),
        ];
    }
    $moznosti = [];
    foreach (($cfg['moznosti'] ?? []) as $gi => $g) {
        $volby = [];
        foreach (($g['volby'] ?? []) as $vi => $vo) {
            $lt = in_array(($vo['link_typ'] ?? 'none'), ['none', 'surovina', 'vyrobek'], true) ? $vo['link_typ'] : 'none';
            $volby[] = [
                'id'          => (string) ($vo['id'] ?? ('o' . $vi)),
                'nazev'       => trim((string) ($vo['nazev'] ?? '')),
                'priplatek_kc'=> round((float) ($vo['priplatek_kc'] ?? 0), 2),
                'link_typ'    => $lt,
                'link_id'     => $lt === 'none' ? null : (((int) ($vo['link_id'] ?? 0)) ?: null),
                'mnozstvi'    => round((float) ($vo['mnozstvi'] ?? 0), 3),
                'jednotka'    => trim((string) ($vo['jednotka'] ?? '')),
            ];
        }
        $moznosti[] = [
            'id'      => (string) ($g['id'] ?? ('g' . $gi)),
            'nazev'   => trim((string) ($g['nazev'] ?? ('Skupina ' . ($gi + 1)))),
            'typ'     => (($g['typ'] ?? 'single') === 'multi') ? 'multi' : 'single',
            'povinne' => !empty($g['povinne']),
            'volby'   => $volby,
        ];
    }
    $clean = [
        'velikosti'     => $velikosti ?: cake_default_config($pdo)['velikosti'],
        'moznosti'      => $moznosti,
        'text_na_dortu' => [
            'povolit'      => !empty($cfg['text_na_dortu']['povolit']),
            'max_chars'    => max(1, (int) ($cfg['text_na_dortu']['max_chars'] ?? 40)),
            'priplatek_kc' => round((float) ($cfg['text_na_dortu']['priplatek_kc'] ?? 80), 2),
        ],
        'sazba_dph'     => (float) ($cfg['sazba_dph'] ?? 12),
    ];
    $json = json_encode($clean, JSON_UNESCAPED_UNICODE);
    $pdo->prepare("INSERT INTO nastaveni (klic, hodnota) VALUES ('cake_config', :v) ON DUPLICATE KEY UPDATE hodnota = :v2")
        ->execute(['v' => $json, 'v2' => $json]);
    return $clean;
}

// ════════════════════════════════════════════════════════════
// MATERIÁLOVÉ NÁKLADY
// ════════════════════════════════════════════════════════════
function cake_material_cost(PDO $pdo, int $vyrobek_id): float {
    try {
        $st = $pdo->prepare("SELECT COALESCE(SUM(vs.mnozstvi * (s.cena_baleni / NULLIF(s.obsah_baleni,0))),0)
                             FROM vyrobek_suroviny vs JOIN suroviny s ON s.id = vs.surovina_id WHERE vs.vyrobek_id = :v");
        $st->execute(['v' => $vyrobek_id]);
        return round((float) $st->fetchColumn(), 2);
    } catch (Throwable $e) { return 0.0; }
}

// Materiálový náklad jedné volby (dle linku) za 1 ks volby.
function cake_link_material(PDO $pdo, string $linkTyp, ?int $linkId, float $mnozstvi): float {
    if (!$linkId || $mnozstvi <= 0) return 0.0;
    try {
        if ($linkTyp === 'surovina') {
            $st = $pdo->prepare("SELECT cena_baleni / NULLIF(obsah_baleni,0) FROM suroviny WHERE id = :id");
            $st->execute(['id' => $linkId]);
            return round((float) $st->fetchColumn() * $mnozstvi, 2);
        }
        if ($linkTyp === 'vyrobek') {
            return round(cake_material_cost($pdo, $linkId) * $mnozstvi, 2);
        }
    } catch (Throwable $e) {}
    return 0.0;
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

function cake_flavors(PDO $pdo): array {
    $rows = [];
    try {
        $rows = $pdo->query("
            SELECT v.id, v.nazev, v.cena_bez_dph AS cena, COALESCE(sd.sazba, 12) AS dph,
                   (SELECT COUNT(*) FROM vyrobek_suroviny WHERE vyrobek_id = v.id) AS recept_polozek
            FROM vyrobky v LEFT JOIN sazby_dph sd ON sd.id = v.sazba_dph_id
            WHERE v.obor = 'dort' AND v.aktivni = 1 ORDER BY v.poradi, v.nazev
        ")->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) { $rows = []; }
    $out = [];
    foreach ($rows as $r) {
        $vid = (int) $r['id'];
        $out[] = [
            'vyrobek_id'    => $vid,
            'nazev'         => $r['nazev'],
            'ikona'         => cake_flavor_icon((string) $r['nazev']),
            'cena_bez_dph'  => round((float) $r['cena'], 2),
            'material_kc'   => cake_material_cost($pdo, $vid),
            'sazba_dph'     => (float) $r['dph'],
            'recept_polozek' => (int) $r['recept_polozek'],
        ];
    }
    return $out;
}

// Seed 6 editovatelných dortů s recepturou — jen když žádný obor='dort' neexistuje.
function cake_ensure_demo_dorty(PDO $pdo): void {
    try { if ((int) $pdo->query("SELECT COUNT(*) FROM vyrobky WHERE obor = 'dort'")->fetchColumn() > 0) return; }
    catch (Throwable $e) { return; }
    $katId = (int) $pdo->query("SELECT id FROM kategorie_vyrobku WHERE nazev = 'Dorty' LIMIT 1")->fetchColumn();
    if (!$katId) { try { $mp = (int) $pdo->query("SELECT COALESCE(MAX(poradi),0)+1 FROM kategorie_vyrobku")->fetchColumn(); $pdo->prepare("INSERT INTO kategorie_vyrobku (nazev, ikona, poradi) VALUES ('Dorty','🎂',:p)")->execute(['p' => $mp]); $katId = (int) $pdo->lastInsertId(); } catch (Throwable $e) { $katId = (int) $pdo->query("SELECT id FROM kategorie_vyrobku ORDER BY id LIMIT 1")->fetchColumn(); } }
    $dph12 = (int) $pdo->query("SELECT id FROM sazby_dph WHERE sazba = 12 LIMIT 1")->fetchColumn() ?: (int) $pdo->query("SELECT id FROM sazby_dph ORDER BY id LIMIT 1")->fetchColumn();
    $jedKs = (int) $pdo->query("SELECT id FROM jednotky WHERE kod = 'ks' LIMIT 1")->fetchColumn() ?: 1;
    $sur = [];
    foreach ($pdo->query("SELECT id, nazev FROM suroviny")->fetchAll(PDO::FETCH_ASSOC) as $s) $sur[mb_strtolower($s['nazev'])] = (int) $s['id'];
    $find = function (array $n) use ($sur) { foreach ($sur as $k => $id) foreach ($n as $x) if (str_contains($k, $x)) return $id; return null; };
    $S = ['mouka' => $find(['mouka pšeničná hladká', 'mouka hladká', 'mouka pšeničná']), 'maslo' => $find(['máslo']), 'cukrk' => $find(['cukr krystal']), 'cukrm' => $find(['cukr moučka']), 'cukrv' => $find(['cukr vanilkový']), 'vejce' => $find(['vejce']), 'smetana' => $find(['smetana']), 'mleko' => $find(['mléko']), 'kakao' => $find(['kakao']), 'cokolada' => $find(['čokoláda']), 'med' => $find(['med květový', 'med']), 'kava' => $find(['káva']), 'orechy' => $find(['vlašské ořechy', 'ořech']), 'tvaroh' => $find(['tvaroh']), 'marmelada' => $find(['marmeláda']), 'skorice' => $find(['skořice'])];
    $dorty = [
        ['nazev' => 'Čokoládový dort', 'cena' => 620, 'recept' => [['mouka', 250, 'g'], ['cukrk', 200, 'g'], ['maslo', 150, 'g'], ['vejce', 4, 'ks'], ['kakao', 60, 'g'], ['cokolada', 100, 'g'], ['smetana', 200, 'ml']]],
        ['nazev' => 'Vanilkový dort', 'cena' => 600, 'recept' => [['mouka', 260, 'g'], ['cukrk', 200, 'g'], ['maslo', 150, 'g'], ['vejce', 4, 'ks'], ['cukrv', 20, 'g'], ['smetana', 250, 'ml'], ['mleko', 80, 'ml']]],
        ['nazev' => 'Medovník', 'cena' => 700, 'recept' => [['mouka', 300, 'g'], ['med', 150, 'g'], ['maslo', 120, 'g'], ['vejce', 3, 'ks'], ['cukrk', 150, 'g'], ['smetana', 250, 'ml'], ['skorice', 5, 'g']]],
        ['nazev' => 'Tiramisu dort', 'cena' => 740, 'recept' => [['mouka', 150, 'g'], ['vejce', 5, 'ks'], ['cukrk', 150, 'g'], ['tvaroh', 300, 'g'], ['smetana', 200, 'ml'], ['kava', 30, 'g'], ['kakao', 20, 'g']]],
        ['nazev' => 'Oříškový dort', 'cena' => 700, 'recept' => [['mouka', 220, 'g'], ['cukrk', 200, 'g'], ['maslo', 150, 'g'], ['vejce', 4, 'ks'], ['orechy', 150, 'g'], ['smetana', 150, 'ml']]],
        ['nazev' => 'Ovocný dort (meruňka)', 'cena' => 670, 'recept' => [['mouka', 250, 'g'], ['cukrm', 180, 'g'], ['maslo', 140, 'g'], ['vejce', 4, 'ks'], ['marmelada', 200, 'g'], ['smetana', 200, 'ml']]],
    ];
    $vcols = []; try { $vcols = $pdo->query("SHOW COLUMNS FROM vyrobky")->fetchAll(PDO::FETCH_COLUMN); } catch (Throwable $e) {}
    $has = fn($c) => in_array($c, $vcols, true);
    $pdo->beginTransaction();
    try {
        $pb = (int) $pdo->query("SELECT COALESCE(MAX(poradi),0) FROM vyrobky")->fetchColumn(); $i = 0;
        foreach ($dorty as $d) {
            $i++;
            $f = ['nazev' => $d['nazev'], 'aktivni' => 1];
            if ($has('obor')) $f['obor'] = 'dort';
            if ($has('cena_bez_dph')) $f['cena_bez_dph'] = $d['cena'];
            if ($has('kategorie_id')) $f['kategorie_id'] = $katId;
            if ($has('jednotka_id')) $f['jednotka_id'] = $jedKs;
            if ($has('sazba_dph_id')) $f['sazba_dph_id'] = $dph12;
            if ($has('hmotnost_g')) $f['hmotnost_g'] = CAKE_BASE_G;
            if ($has('jednotka')) $f['jednotka'] = 'ks';
            if ($has('poradi')) $f['poradi'] = $pb + $i;
            if ($has('cislo')) $f['cislo'] = 'DORT-' . str_pad((string) $i, 2, '0', STR_PAD_LEFT);
            $cols = array_keys($f);
            $pdo->prepare('INSERT INTO vyrobky (' . implode(',', $cols) . ') VALUES (' . implode(',', array_map(fn($c) => ':' . $c, $cols)) . ')')->execute($f);
            $vid = (int) $pdo->lastInsertId(); $por = 0;
            foreach ($d['recept'] as $r) { $sid = $S[$r[0]] ?? null; if (!$sid) continue; $por++; $pdo->prepare("INSERT INTO vyrobek_suroviny (vyrobek_id,surovina_id,mnozstvi,jednotka,poradi) VALUES (:v,:s,:m,:j,:p)")->execute(['v' => $vid, 's' => $sid, 'm' => $r[1], 'j' => $r[2], 'p' => $por]); }
        }
        $pdo->commit();
    } catch (Throwable $e) { if ($pdo->inTransaction()) $pdo->rollBack(); }
}

// Zajistí sloupec objednavky_polozky.surovina_id (pro volby linkující přímo surovinu).
function cake_ensure_op_surovina_col(PDO $pdo): void {
    try {
        $cols = $pdo->query("SHOW COLUMNS FROM objednavky_polozky")->fetchAll(PDO::FETCH_COLUMN);
        if (!in_array('surovina_id', $cols, true)) {
            $pdo->exec("ALTER TABLE objednavky_polozky ADD COLUMN surovina_id INT NULL AFTER vyrobek_id");
            $pdo->exec("ALTER TABLE objednavky_polozky ADD INDEX idx_op_surovina (surovina_id)");
        }
    } catch (Throwable $e) {}
}

// Vybere velikost z configu dle velikost_id nebo počtu porcí (nejmenší pokrývající).
function cake_pick_velikost(array $cfg, ?string $velikostId, int $porci): array {
    $vs = $cfg['velikosti'];
    if ($velikostId) foreach ($vs as $v) if (($v['id'] ?? '') === $velikostId) return $v;
    foreach ($vs as $v) if ((int) ($v['porci'] ?? 0) >= $porci) return $v;
    return end($vs) ?: ['id' => 'maly', 'label' => 'Malý', 'porci' => 10, 'hmotnost_g' => 1000, 'nasobic' => 1.0];
}

// Z vstupu vybraných voleb { skupina_id: volba_id | [volba_id,…] } → ploché pole vybraných voleb.
function cake_selected_volby(array $cfg, $volbyInput): array {
    $sel = [];
    if (!is_array($volbyInput)) return $sel;
    foreach ($cfg['moznosti'] as $g) {
        $picked = $volbyInput[$g['id']] ?? null;
        if ($picked === null || $picked === '' || $picked === []) continue;
        $pickedIds = is_array($picked) ? $picked : [$picked];
        foreach ($g['volby'] as $vo) {
            if (in_array((string) $vo['id'], array_map('strval', $pickedIds), true)) {
                $sel[] = $vo + ['skupina' => $g['nazev'], 'skupina_id' => $g['id']];
            }
        }
    }
    return $sel;
}

// Spočítá celou nabídku (sdílené quote + create_order)
function cake_compute(PDO $pdo, array $cfg, array $d, array $flavors): array {
    $porci    = max(1, (int) ($d['porci'] ?? 0));
    $velikost = cake_pick_velikost($cfg, $d['velikost_id'] ?? null, $porci ?: 10);
    $nasobic  = (float) ($velikost['nasobic'] ?? 1.0);

    $flavor = null;
    $vid = (int) ($d['vyrobek_id'] ?? 0);
    foreach ($flavors as $f) if ($f['vyrobek_id'] === $vid) { $flavor = $f; break; }
    if (!$flavor) $flavor = $flavors[0] ?? null;

    $volby = cake_selected_volby($cfg, $d['volby'] ?? []);
    $text  = trim((string) ($d['text'] ?? ''));

    return ['velikost' => $velikost, 'nasobic' => $nasobic, 'flavor' => $flavor, 'volby' => $volby, 'text' => $text];
}

// ════════════════════════════════════════════════════════════
if ($action === 'options') {
    $pdo = db();
    cake_ensure_demo_dorty($pdo);
    $cfg = cake_config($pdo);
    // doplň materiálový náklad ke každé volbě
    foreach ($cfg['moznosti'] as &$g) foreach ($g['volby'] as &$vo) {
        $vo['material_kc'] = cake_link_material($pdo, $vo['link_typ'] ?? 'none', $vo['link_id'] ?? null, (float) ($vo['mnozstvi'] ?? 0));
        $vo['odecte_sklad'] = ($vo['link_typ'] ?? 'none') !== 'none' && !empty($vo['link_id']);
    }
    unset($g, $vo);
    json_response([
        'velikosti'     => $cfg['velikosti'],
        'prichute'      => cake_flavors($pdo),
        'moznosti'      => $cfg['moznosti'],
        'text_na_dortu' => $cfg['text_na_dortu'],
        'sazba_dph'     => $cfg['sazba_dph'],
        'base_g'        => CAKE_BASE_G,
    ]);
}

if ($action === 'config') {
    json_response(cake_config(db()));
}

if ($action === 'save_config' && $method === 'POST') {
    require_super_admin();
    $saved = cake_config_save(db(), json_input());
    json_response(['ok' => true, 'config' => $saved]);
}

if ($action === 'quote' && $method === 'POST') {
    $pdo = db();
    cake_ensure_demo_dorty($pdo);
    $cfg = cake_config($pdo);
    $flavors = cake_flavors($pdo);
    if (!$flavors) json_error('Zatím není žádný dort výrobek (obor=dort) s recepturou. Založ dort ve Výrobky.', 409);

    $r = cake_compute($pdo, $cfg, json_input(), $flavors);
    $f = $r['flavor']; $nas = $r['nasobic'];

    $baseCena   = round($f['cena_bez_dph'] * $nas, 2);
    $materialKc = round($f['material_kc'] * $nas, 2);

    $polozky = [['nazev' => $f['ikona'] . ' ' . $f['nazev'] . ' · ' . $r['velikost']['label'] . ' (' . $r['velikost']['hmotnost_g'] . ' g)', 'cena_kc' => $baseCena, 'typ' => 'dort']];
    foreach ($r['volby'] as $vo) {
        if (($vo['priplatek_kc'] ?? 0) != 0 || ($vo['link_typ'] ?? 'none') !== 'none') {
            $polozky[] = ['nazev' => $vo['skupina'] . ': ' . $vo['nazev'], 'cena_kc' => (float) $vo['priplatek_kc'], 'typ' => 'volba'];
            $materialKc += cake_link_material($pdo, $vo['link_typ'] ?? 'none', $vo['link_id'] ?? null, (float) ($vo['mnozstvi'] ?? 0));
        }
    }
    if ($r['text'] !== '' && !empty($cfg['text_na_dortu']['povolit'])) {
        $polozky[] = ['nazev' => 'Text na dortu: „' . $r['text'] . '"', 'cena_kc' => (float) $cfg['text_na_dortu']['priplatek_kc'], 'typ' => 'volba'];
    }
    $materialKc = round($materialKc, 2);

    $bezDPH = round(array_sum(array_column($polozky, 'cena_kc')), 2);
    $dph    = $f['sazba_dph'] ?: $cfg['sazba_dph'];
    $sDPH   = round($bezDPH * (1 + $dph / 100), 2);

    json_response([
        'velikost'   => $r['velikost'],
        'prichut'    => $f,
        'volby'      => $r['volby'],
        'text'       => $r['text'],
        'polozky'    => $polozky,
        'sazba_dph'  => $dph,
        'cena_bez_dph' => $bezDPH,
        'cena_dph'   => round($sDPH - $bezDPH, 2),
        'cena_s_dph' => $sDPH,
        'cena_per_porci' => round($sDPH / max(1, (int) $r['velikost']['porci']), 2),
        'kalkulace'  => [
            'material_kc' => $materialKc,
            'marze_kc'    => round($bezDPH - $materialKc, 2),
            'marze_pct'   => $bezDPH > 0 ? round(($bezDPH - $materialKc) / $bezDPH * 100, 1) : 0,
            'recept_polozek' => $f['recept_polozek'],
        ],
        'doba_pripravy_dni' => max(2, (int) ceil(((int) $r['velikost']['porci']) / 20)),
    ]);
}

// ════════════════════════════════════════════════════════════
if ($action === 'create_order' && $method === 'POST') {
    $pdo = db();
    cake_ensure_demo_dorty($pdo);
    cake_ensure_op_surovina_col($pdo);
    $cfg = cake_config($pdo);
    $flavors = cake_flavors($pdo);
    if (!$flavors) json_error('Není žádný dort výrobek s recepturou — založ dort ve Výrobky (obor=dort).', 409);

    $d = json_input();
    $odberatel_id = (int) ($d['odberatel_id'] ?? 0);
    $datum_dodani = $d['datum_dodani'] ?? null;
    if (!$odberatel_id) json_error('Vyber odběratele', 400);
    if (!$datum_dodani) json_error('Vyber datum dodání', 400);

    $r = cake_compute($pdo, $cfg, $d, $flavors);
    $f = $r['flavor']; $nas = $r['nasobic'];
    $dph = (float) ($f['sazba_dph'] ?: $cfg['sazba_dph']);

    // řádky: [vyrobek_id, surovina_id, nazev, mnozstvi, cena_per_jed]
    $radky = [];
    $radky[] = ['vid' => $f['vyrobek_id'], 'sid' => null, 'n' => '🎂 ' . $f['nazev'] . ' · ' . $r['velikost']['label'] . ' (' . $r['velikost']['hmotnost_g'] . ' g)', 'mn' => $nas, 'c' => $f['cena_bez_dph'], 'jed' => 'ks'];
    foreach ($r['volby'] as $vo) {
        $lt = $vo['link_typ'] ?? 'none';
        $vid = ($lt === 'vyrobek') ? ((int) $vo['link_id'] ?: null) : null;
        $sid = ($lt === 'surovina') ? ((int) $vo['link_id'] ?: null) : null;
        $mn  = ($lt !== 'none' && (float) ($vo['mnozstvi'] ?? 0) > 0) ? (float) $vo['mnozstvi'] : 1;
        if (($vo['priplatek_kc'] ?? 0) == 0 && $lt === 'none') continue; // prázdná volba „Bez…" — přeskoč
        // příplatek je PLOCHÝ za volbu; mnozstvi nese SPOTŘEBU (g suroviny) → cena/jednotku = příplatek/mnozstvi,
        // aby řádek (cena_bez_dph × mnozstvi) = příplatek a zároveň výroba odečetla správné množství.
        $cenaJed = $mn > 0 ? round((float) $vo['priplatek_kc'] / $mn, 4) : (float) $vo['priplatek_kc'];
        $radky[] = ['vid' => $vid, 'sid' => $sid, 'n' => $vo['skupina'] . ': ' . $vo['nazev'], 'mn' => $mn, 'c' => $cenaJed, 'jed' => ($vo['jednotka'] ?: 'ks')];
    }
    if ($r['text'] !== '' && !empty($cfg['text_na_dortu']['povolit'])) {
        $radky[] = ['vid' => null, 'sid' => null, 'n' => 'Text na dortu: „' . mb_substr($r['text'], 0, 60) . '"', 'mn' => 1, 'c' => (float) $cfg['text_na_dortu']['priplatek_kc'], 'jed' => 'ks'];
    }

    $bezDPH = 0.0; foreach ($radky as $ra) $bezDPH += $ra['c'] * $ra['mn'];
    $bezDPH = round($bezDPH, 2);
    $sDPH   = round($bezDPH * (1 + $dph / 100), 2);

    try {
        $cislo = kanal_dalsi_cislo($pdo, 'dort');
        $pdo->beginTransaction();
        $popis = $f['nazev'] . ' · ' . $r['velikost']['label']
               . (count($r['volby']) ? ' · ' . implode(', ', array_map(fn($v) => $v['nazev'], $r['volby'])) : '')
               . ($r['text'] !== '' ? ' · text: „' . $r['text'] . '"' : '');
        // 🆕 v3.0.305 — fotka předlohy (volitelná) → do poznámky (povol jen http(s) nebo náš /uploads/ path)
        $foto = trim((string) ($d['foto'] ?? ''));
        if ($foto !== '' && preg_match('~^(https?://|/uploads/)~i', $foto) && mb_strlen($foto) <= 500) {
            $popis .= "\n📸 Předloha: " . $foto;
        }
        $pdo->prepare("INSERT INTO objednavky (cislo, odberatel_id, datum_objednani, datum_dodani, castka_bez_dph, castka_dph, castka_celkem, stav, poznamka, puvod)
                       VALUES (:c,:o,NOW(),:dd,:bd,:dh,:sd,'nova',:p,'dort')")
            ->execute(['c' => $cislo, 'o' => $odberatel_id, 'dd' => $datum_dodani, 'bd' => $bezDPH, 'dh' => round($sDPH - $bezDPH, 2), 'sd' => $sDPH, 'p' => "🎂 Dort z konfigurátoru:\n" . $popis]);
        $objId = (int) $pdo->lastInsertId();
        $stmt = $pdo->prepare("INSERT INTO objednavky_polozky (objednavka_id, vyrobek_id, surovina_id, vyrobek_nazev, jednotka, mnozstvi, cena_bez_dph, sazba_dph)
                               VALUES (:o,:vid,:sid,:n,:jed,:mn,:c,:s)");
        foreach ($radky as $ra) {
            $stmt->execute(['o' => $objId, 'vid' => $ra['vid'], 'sid' => $ra['sid'], 'n' => $ra['n'], 'jed' => $ra['jed'], 'mn' => $ra['mn'], 'c' => $ra['c'], 's' => $dph]);
        }
        $pdo->commit();
        try { if (function_exists('notifikace_nova_objednavka')) notifikace_nova_objednavka($pdo, $objId); } catch (Throwable $e) {}
        $odec = count(array_filter($radky, fn($ra) => $ra['vid'] || $ra['sid']));
        json_response(['ok' => true, 'id' => $objId, 'cislo' => $cislo, 'castka' => $sDPH, 'radku' => count($radky), 'odecitanych' => $odec, 'message' => 'Objednávka vytvořena — dort + volby se odečtou ze surovin při výrobě.'], 201);
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        json_error_safe('Chyba vytvoření', $e, 500);
    }
}

json_error('Neznámá akce (options|quote|create_order|config|save_config)', 404);
