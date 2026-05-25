<?php
/**
 * Z textového složení výrobku spárovat suroviny v naší databázi
 * a chybějící doplnit jako nové (s automatickou jednotkou a alergenem).
 *
 * GET  ?action=preview&id=X       — náhled: co se napáruje a co se vytvoří
 * POST ?action=apply               — body: { id?: int, prepsat_alergeny?: bool, jen_existujici?: bool }
 *                                    (id chybí = aplikuj na všechny výrobky se slozeni)
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/_admin_auth.php';
cors_headers();
require_admin();

$pdo = db();
$action = $_GET['action'] ?? '';

// =============================================================
// Pomocné funkce
// =============================================================

function ms_normalize(string $s): string {
    $s = mb_strtolower(trim($s), 'UTF-8');
    // Odstraň diakritiku pro fuzzy match
    $s = strtr($s, [
        'á'=>'a','č'=>'c','ď'=>'d','é'=>'e','ě'=>'e','í'=>'i','ň'=>'n',
        'ó'=>'o','ř'=>'r','š'=>'s','ť'=>'t','ú'=>'u','ů'=>'u','ý'=>'y','ž'=>'z',
    ]);
    return $s;
}

function ms_parse_slozeni(string $text): array {
    if (trim($text) === '') return [];
    // Strip parenthetical content (recursive)
    for ($i = 0; $i < 4; $i++) {
        $new = preg_replace('/\([^()]*\)/u', '', $text);
        if ($new === $text) break;
        $text = $new;
    }
    // Strip percentages and qualifiers
    $tokens = preg_split('/[,;]/u', $text);
    $out = [];
    $seen = [];
    foreach ($tokens as $t) {
        $t = trim($t);
        // Odstraň leading kvantifikátory
        $t = preg_replace('/^(?:více|méně|nad|do|nejméně|jen)\s+než\s+\d+\s*%\s*[-–:]?\s*/iu', '', $t);
        $t = preg_replace('/^\d+(?:[.,]\d+)?\s*%\s*[-–:]?\s*/u', '', $t);
        // Odstraň trailing % číslo
        $t = preg_replace('/\s*\d+(?:[.,]\d+)?\s*%\s*$/u', '', $t);
        // Odstraň E-čísla (E 471, E322)
        $t = preg_replace('/\bE\s*\d+[a-z]?\b/iu', '', $t);
        // Odstraň "emulgátor", "látka zlepšující" prefixy které jsou popisem
        $t = preg_replace('/^(?:emulgátor|emulgátor:|stabilizátor|barvivo|konzervant|látka\s+\w+|kyselina|enzym[yu]?|aroma|aromata|přípravek\s+\w+|oddělovací\s+prostředek)\s*[-–:]?\s*/iu', '', $t);
        // Trim
        $t = preg_replace('/^[\s\-–:]+|[\s\-–:.]+$/u', '', $t);
        $t = preg_replace('/\s+/u', ' ', $t);
        if (mb_strlen($t) < 2) continue;
        if (mb_strlen($t) > 80) continue;
        // Odfiltruj samé velké písmena (typicky kódy směsí jako "MAROKO", "ULDO")
        if (preg_match('/^[A-ZÁ-Ž ]+$/u', $t) && mb_strlen($t) > 4) continue;
        $key = ms_normalize($t);
        if (isset($seen[$key])) continue;
        $seen[$key] = true;
        $out[] = $t;
    }
    return $out;
}

function ms_match_surovina(string $name, array $allSur): ?array {
    $nLow = ms_normalize($name);
    // 1) Exact normalized match
    foreach ($allSur as $s) {
        if (ms_normalize($s['nazev']) === $nLow) return $s;
    }
    // 2) Substring match (oboustranný)
    foreach ($allSur as $s) {
        $sLow = ms_normalize($s['nazev']);
        if (mb_strpos($sLow, $nLow) !== false || mb_strpos($nLow, $sLow) !== false) {
            return $s;
        }
    }
    // 3) Token match — všechny tokeny ≥3 znaky musí být v názvu
    $tokens = array_values(array_filter(preg_split('/\s+/u', $nLow), fn($t) => mb_strlen($t) >= 3));
    if (count($tokens) === 0) return null;
    $best = null; $bestScore = 0;
    foreach ($allSur as $s) {
        $sLow = ms_normalize($s['nazev']);
        $matchCount = 0;
        foreach ($tokens as $tok) {
            if (mb_strpos($sLow, $tok) !== false) $matchCount++;
        }
        if ($matchCount === count($tokens) && $matchCount > $bestScore) {
            $best = $s; $bestScore = $matchCount;
        }
    }
    return $best;
}

function ms_detect_unit(string $name): string {
    $low = ms_normalize($name);
    if (preg_match('/\bvejc/u', $low)) return 'ks';
    if (preg_match('/\b(olej|voda|vino|ocet|smetan|mlek|mlecn|stav|sirup|alkohol|likvor|tekut)/u', $low)) return 'ml';
    return 'g';
}

function ms_detect_alergeny(string $name): array {
    $low = ms_normalize($name);
    $a = [];
    // Lepek
    if (preg_match('/\b(psen|zit|jecm|oves|spald|krupic|strouhank|chleb|sušenk|testovin|tousty|piskotov|moučk|mouka|skoricov)/u', $low) || preg_match('/lepek|gluten/u', $low)) $a[] = 'lepek';
    // Mléko
    if (preg_match('/\b(mlek|smetan|maslo|syr|jogurt|tvaroh|kefir|mlecn|laktoz)/u', $low)) $a[] = 'mléko';
    // Vejce
    if (preg_match('/\bvejc/u', $low)) $a[] = 'vejce';
    // Arašídy
    if (preg_match('/\b(arasid|bursky)/u', $low)) $a[] = 'arašídy';
    // Skořápkové ořechy
    if (preg_match('/\b(mandl|lisk|vlassk|para|kesu|pekan|pistac|makad|orech|nuts)/u', $low)) $a[] = 'skořápkové ořechy';
    // Sója
    if (preg_match('/\b(soja|sojov)/u', $low)) $a[] = 'sója';
    // Sezam
    if (preg_match('/\bsezam/u', $low)) $a[] = 'sezam';
    // Hořčice
    if (preg_match('/\bhorcic/u', $low)) $a[] = 'hořčice';
    // Celer
    if (preg_match('/\bceler/u', $low)) $a[] = 'celer';
    // Ryby
    if (preg_match('/\b(ryba|rybi|losos|tunak|sled|treska)/u', $low)) $a[] = 'ryby';
    // Korýši
    if (preg_match('/\b(korys|krevet|krab|humr)/u', $low)) $a[] = 'korýši';
    // Měkkýši
    if (preg_match('/\b(mekkys|chobotnice|kalamar|mušle)/u', $low)) $a[] = 'měkkýši';
    // Sulfity (E 220-228)
    if (preg_match('/\b(siric|sulfit)/u', $low)) $a[] = 'oxid siřičitý';
    // Lupina
    if (preg_match('/\blupin/u', $low)) $a[] = 'lupina';
    return $a;
}

// =============================================================
// PREVIEW (single product or all)
// =============================================================
function nacti_preview(PDO $pdo, ?int $id = null): array {
    if ($id) {
        $stmt = $pdo->prepare("SELECT id, nazev, slozeni FROM vyrobky WHERE id = :id");
        $stmt->execute(['id' => $id]);
        $vyrobky = [$stmt->fetch()];
        $vyrobky = array_filter($vyrobky);
    } else {
        $stmt = $pdo->query("SELECT id, nazev, slozeni FROM vyrobky WHERE slozeni IS NOT NULL AND TRIM(slozeni) != '' AND aktivni = 1");
        $vyrobky = $stmt->fetchAll();
    }

    $allSur = $pdo->query("SELECT id, nazev, jednotka, alergen FROM suroviny WHERE aktivni = 1")->fetchAll();

    $result = [];
    $newSet = []; // názvy nových surovin (aby se nezduplikovaly v rámci jednoho preview)
    foreach ($vyrobky as $v) {
        $tokens = ms_parse_slozeni($v['slozeni'] ?? '');
        $matched = []; $missing = [];
        foreach ($tokens as $t) {
            $m = ms_match_surovina($t, $allSur);
            if ($m) {
                $matched[] = ['nazev_z_slozeni' => $t, 'surovina_id' => (int) $m['id'], 'surovina_nazev' => $m['nazev'], 'alergen' => $m['alergen']];
            } else {
                $key = ms_normalize($t);
                if (!isset($newSet[$key])) {
                    $newSet[$key] = true;
                    $missing[] = ['nazev' => $t, 'detected_jednotka' => ms_detect_unit($t), 'detected_alergeny' => ms_detect_alergeny($t)];
                } else {
                    $missing[] = ['nazev' => $t, 'detected_jednotka' => ms_detect_unit($t), 'detected_alergeny' => ms_detect_alergeny($t), 'duplicate' => true];
                }
            }
        }
        $result[] = [
            'vyrobek_id' => (int) $v['id'],
            'vyrobek_nazev' => $v['nazev'],
            'tokenu' => count($tokens),
            'naparovano' => count($matched),
            'matched' => $matched,
            'missing' => $missing,
        ];
    }
    return $result;
}

if ($action === 'preview') {
    $id = isset($_GET['id']) ? (int) $_GET['id'] : null;
    $data = nacti_preview($pdo, $id);
    // Souhrn nových surovin (deduplikace)
    $unique = [];
    foreach ($data as $v) {
        foreach ($v['missing'] as $m) {
            if (!empty($m['duplicate'])) continue;
            $key = ms_normalize($m['nazev']);
            $unique[$key] = $m;
        }
    }
    json_response([
        'vyrobky' => $data,
        'pocet_vyrobku' => count($data),
        'pocet_napar' => array_sum(array_map(fn($v) => $v['naparovano'], $data)),
        'pocet_chybi' => array_sum(array_map(fn($v) => count(array_filter($v['missing'], fn($m) => empty($m['duplicate']))), $data)),
        'nove_suroviny' => array_values($unique),
    ]);
}

// =============================================================
// APPLY
// =============================================================
if ($action === 'apply') {
    $body = json_input();
    $jen_id = isset($body['id']) ? (int) $body['id'] : null;
    $prepsatAlerg = !empty($body['prepsat_alergeny']);
    $jenExist = !empty($body['jen_existujici']); // pokud true, NEvytváří nové

    // Načti výrobky
    if ($jen_id) {
        $stmt = $pdo->prepare("SELECT id, nazev, slozeni, alergeny FROM vyrobky WHERE id = :id");
        $stmt->execute(['id' => $jen_id]);
        $vyrobky = array_filter([$stmt->fetch()]);
    } else {
        $stmt = $pdo->query("SELECT id, nazev, slozeni, alergeny FROM vyrobky WHERE slozeni IS NOT NULL AND TRIM(slozeni) != '' AND aktivni = 1");
        $vyrobky = $stmt->fetchAll();
    }

    $allSur = $pdo->query("SELECT id, nazev, jednotka, alergen FROM suroviny")->fetchAll();
    $surByLow = [];
    foreach ($allSur as $s) $surByLow[ms_normalize($s['nazev'])] = $s;

    $stat = [
        'vyrobky_zpracovano' => 0,
        'naparovano_existujicich' => 0,
        'novych_surovin' => 0,
        'aktualizovano_alergenu' => 0,
        'detail' => [],
    ];
    $vytvorene_suroviny = [];

    $pdo->beginTransaction();
    try {
        foreach ($vyrobky as $v) {
            $stat['vyrobky_zpracovano']++;
            $tokens = ms_parse_slozeni($v['slozeni'] ?? '');
            if (empty($tokens)) continue;

            $linkedIds = []; $linkedAler = [];
            foreach ($tokens as $t) {
                $m = ms_match_surovina($t, array_values($surByLow));
                $sid = null;
                if ($m) {
                    $sid = (int) $m['id'];
                    $stat['naparovano_existujicich']++;
                    if (!empty($m['alergen'])) {
                        foreach (preg_split('/[,;]/u', $m['alergen']) as $a) {
                            $a = trim($a);
                            if ($a) $linkedAler[mb_strtolower($a)] = $a;
                        }
                    }
                } elseif (!$jenExist) {
                    // Vytvoř novou surovinu (název v title case)
                    $jed = ms_detect_unit($t);
                    $aler = ms_detect_alergeny($t);
                    $aler_str = $aler ? implode(', ', $aler) : null;
                    $tNazev = mb_convert_case(trim($t), MB_CASE_TITLE, 'UTF-8');
                    try {
                        $pdo->prepare("INSERT INTO suroviny (nazev, jednotka, alergen, aktivni) VALUES (:n, :j, :a, 1)")
                            ->execute(['n' => $tNazev, 'j' => $jed, 'a' => $aler_str]);
                        $sid = (int) $pdo->lastInsertId();
                        $stat['novych_surovin']++;
                        $vytvorene_suroviny[] = ['id' => $sid, 'nazev' => $tNazev, 'jednotka' => $jed, 'alergen' => $aler_str];
                        // Přidej do indexu pro další iterace
                        $surByLow[ms_normalize($tNazev)] = ['id' => $sid, 'nazev' => $tNazev, 'jednotka' => $jed, 'alergen' => $aler_str];
                        if ($aler) foreach ($aler as $a) $linkedAler[mb_strtolower($a)] = $a;
                    } catch (PDOException $e) {
                        if ($e->getCode() === '23000') {
                            // Existuje — najdi
                            $st = $pdo->prepare("SELECT id, alergen FROM suroviny WHERE LOWER(nazev) = LOWER(:n) LIMIT 1");
                            $st->execute(['n' => $t]);
                            $r = $st->fetch();
                            if ($r) {
                                $sid = (int) $r['id'];
                                if ($r['alergen']) foreach (preg_split('/[,;]/u', $r['alergen']) as $a) {
                                    $a = trim($a); if ($a) $linkedAler[mb_strtolower($a)] = $a;
                                }
                            }
                        }
                    }
                }
                if ($sid && !in_array($sid, $linkedIds, true)) $linkedIds[] = $sid;
            }

            // Přilink suroviny k výrobku (pivot)
            try {
                $maxPor = (int) $pdo->query("SELECT COALESCE(MAX(poradi), 0) FROM vyrobek_suroviny WHERE vyrobek_id = " . (int) $v['id'])->fetchColumn();
                $insStmt = $pdo->prepare("
                    INSERT IGNORE INTO vyrobek_suroviny (vyrobek_id, surovina_id, mnozstvi, jednotka, poradi)
                    VALUES (:vid, :sid, 0, 'g', :p)
                ");
                $por = $maxPor;
                foreach ($linkedIds as $sid) {
                    $por++;
                    $insStmt->execute(['vid' => $v['id'], 'sid' => $sid, 'p' => $por]);
                }
            } catch (PDOException $e) { /* ignore */ }

            // Aktualizuj alergeny
            if (!empty($linkedAler)) {
                $alerStr = implode(', ', array_values($linkedAler));
                if ($prepsatAlerg || empty($v['alergeny'])) {
                    $pdo->prepare("UPDATE vyrobky SET alergeny = :a WHERE id = :id")
                        ->execute(['a' => $alerStr, 'id' => $v['id']]);
                    $stat['aktualizovano_alergenu']++;
                }
            }

            $stat['detail'][] = [
                'vyrobek_id' => (int) $v['id'],
                'vyrobek_nazev' => $v['nazev'],
                'pocet_surovin' => count($linkedIds),
                'alergeny' => array_values($linkedAler),
            ];
        }
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        json_error_safe('Chyba', , 500);
    }

    $stat['vytvorene_suroviny'] = $vytvorene_suroviny;
    json_response($stat);
}

json_error('Neznámá akce. Použij action=preview|apply');
