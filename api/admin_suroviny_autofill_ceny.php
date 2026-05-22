<?php
/**
 * Auto-doplnění cen surovin podle průměrných velkoobchodních cen
 * pro pekařství v ČR (2024–2025).
 *
 *   GET    /api/admin_suroviny_autofill_ceny.php          → preview
 *   POST   /api/admin_suroviny_autofill_ceny.php          → aplikovat (jen prázdné)
 *   POST   /api/admin_suroviny_autofill_ceny.php?force=1  → přepsat vše
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/_admin_auth.php';
cors_headers();
require_admin();

$pdo = db();
$method = $_SERVER['REQUEST_METHOD'];
$force = !empty($_GET['force']);

// =============================================================
// Tabulka průměrných velkoobchodních cen (Kč) — pro pekařství 2024–2025
// Hodnoty jsou vždy normalizované na 1 jednotku (1 kg / 1 l / 1 ks)
// → cena_baleni = cena za 1 jed., obsah_baleni = 1 → cena_per_jed = cena_baleni
// =============================================================
function cenove_pravidla(): array {
    return [
        // === MOUKY ===
        ['match' => '/mouka.*pšen.*hladk|pšen.*mouka.*hladk|hladká.*mouka|mouka\s*t?\s*5(30|50)/iu', 'cena' => 14.00, 'jed' => 'kg', 'label' => 'Mouka pšeničná hladká T530'],
        ['match' => '/mouka.*pšen.*polohrub|polohrub.*mouka|mouka\s*t?\s*65/iu', 'cena' => 15.00, 'jed' => 'kg', 'label' => 'Mouka pšeničná polohrubá T650'],
        ['match' => '/mouka.*pšen.*hrub|hrub[áá].*mouka|mouka\s*t?\s*4[05]0/iu', 'cena' => 16.00, 'jed' => 'kg', 'label' => 'Mouka pšeničná hrubá'],
        ['match' => '/mouka.*pšen.*chleb|chleb.*mouka|mouka\s*t?\s*1000/iu', 'cena' => 17.00, 'jed' => 'kg', 'label' => 'Mouka pšeničná chlebová T1000'],
        ['match' => '/mouka.*celozrn|celozrn.*mouka/iu', 'cena' => 22.00, 'jed' => 'kg', 'label' => 'Mouka celozrnná'],
        ['match' => '/mouka.*žitn.*chleb|žitn.*chleb.*mouka|mouka\s*t?\s*1700/iu', 'cena' => 19.00, 'jed' => 'kg', 'label' => 'Mouka žitná chlebová'],
        ['match' => '/mouka.*žitn|žitn.*mouka/iu', 'cena' => 18.00, 'jed' => 'kg', 'label' => 'Mouka žitná'],
        ['match' => '/mouka(?!.*kokos)/iu', 'cena' => 14.00, 'jed' => 'kg', 'label' => 'Mouka pšeničná'],

        // === TEKUTINY ===
        ['match' => '/^(pitn[áá]?\s*voda|voda)$/iu', 'cena' => 0.05, 'jed' => 'l', 'label' => 'Pitná voda'],
        ['match' => '/voda/iu', 'cena' => 0.05, 'jed' => 'l', 'label' => 'Voda'],
        ['match' => '/(rostlin.*olej|řepkov.*olej|slunečnic.*olej|olej.*rostlin)/iu', 'cena' => 55.00, 'jed' => 'l', 'label' => 'Rostlinný olej'],
        ['match' => '/^olej$/iu', 'cena' => 55.00, 'jed' => 'l', 'label' => 'Olej'],
        ['match' => '/(plnotuč.*mléko|mléko\s*plnotuč)/iu', 'cena' => 28.00, 'jed' => 'l', 'label' => 'Mléko plnotučné'],
        ['match' => '/^mléko$/iu', 'cena' => 25.00, 'jed' => 'l', 'label' => 'Mléko'],
        ['match' => '/smetana.*ke šl|šlehač/iu', 'cena' => 90.00, 'jed' => 'l', 'label' => 'Smetana ke šlehání'],
        ['match' => '/smetana/iu', 'cena' => 75.00, 'jed' => 'l', 'label' => 'Smetana'],

        // === DROŽDÍ A KVAS ===
        ['match' => '/čerstv.*droždí|droždí.*čerstv/iu', 'cena' => 90.00, 'jed' => 'kg', 'label' => 'Čerstvé droždí'],
        ['match' => '/sušen.*droždí|droždí.*sušen/iu', 'cena' => 160.00, 'jed' => 'kg', 'label' => 'Sušené droždí'],
        ['match' => '/^droždí$/iu', 'cena' => 90.00, 'jed' => 'kg', 'label' => 'Droždí'],
        ['match' => '/žitn.*kvas|kvas.*žitn/iu', 'cena' => 60.00, 'jed' => 'kg', 'label' => 'Žitný kvas'],
        ['match' => '/kvas/iu', 'cena' => 60.00, 'jed' => 'kg', 'label' => 'Kvas'],

        // === SŮL, CUKR, KOŘENÍ ===
        ['match' => '/(jedl.*sůl|sůl.*jedl)/iu', 'cena' => 12.00, 'jed' => 'kg', 'label' => 'Jedlá sůl'],
        ['match' => '/^sůl$/iu', 'cena' => 12.00, 'jed' => 'kg', 'label' => 'Sůl'],
        ['match' => '/cukr.*moučk|moučkov.*cukr/iu', 'cena' => 32.00, 'jed' => 'kg', 'label' => 'Cukr moučka'],
        ['match' => '/cukr.*hrub|hrub.*cukr/iu', 'cena' => 32.00, 'jed' => 'kg', 'label' => 'Hrubý cukr'],
        ['match' => '/vanil.*cukr|cukr.*vanil/iu', 'cena' => 150.00, 'jed' => 'kg', 'label' => 'Vanilínový cukr'],
        ['match' => '/^cukr$|cukr.*krystal|krystal.*cukr/iu', 'cena' => 28.00, 'jed' => 'kg', 'label' => 'Cukr krystal'],
        ['match' => '/skořic|cinnamon/iu', 'cena' => 250.00, 'jed' => 'kg', 'label' => 'Skořice mletá'],
        ['match' => '/^kmín$/iu', 'cena' => 180.00, 'jed' => 'kg', 'label' => 'Kmín'],
        ['match' => '/anýz/iu', 'cena' => 220.00, 'jed' => 'kg', 'label' => 'Anýz'],
        ['match' => '/citropast|citr.*pasta/iu', 'cena' => 280.00, 'jed' => 'kg', 'label' => 'Citropasta'],

        // === VEJCE ===
        ['match' => '/(vaječ.*žloutek|žloutek)/iu', 'cena' => 4.00, 'jed' => 'ks', 'label' => 'Vaječný žloutek'],
        ['match' => '/(vaječ.*bílek|bílek)/iu', 'cena' => 3.00, 'jed' => 'ks', 'label' => 'Vaječný bílek'],
        ['match' => '/(sušen.*vejc|vaječ.*sušen)/iu', 'cena' => 280.00, 'jed' => 'kg', 'label' => 'Sušená vejce'],
        ['match' => '/(vejce|vajíčk)/iu', 'cena' => 6.00, 'jed' => 'ks', 'label' => 'Vejce'],

        // === TUKY ===
        ['match' => '/^máslo$|sladk.*máslo|máslo\s*8[02]/iu', 'cena' => 270.00, 'jed' => 'kg', 'label' => 'Máslo'],
        ['match' => '/sádlo.*vepř|vepř.*sádlo|^sádlo$/iu', 'cena' => 90.00, 'jed' => 'kg', 'label' => 'Sádlo vepřové'],
        ['match' => '/palmov.*tuk|tuk.*palmov/iu', 'cena' => 75.00, 'jed' => 'kg', 'label' => 'Palmový tuk'],
        ['match' => '/margarí|margarin/iu', 'cena' => 65.00, 'jed' => 'kg', 'label' => 'Margarín'],

        // === SEMENA, OŘECHY ===
        ['match' => '/^mák$/iu', 'cena' => 180.00, 'jed' => 'kg', 'label' => 'Mák'],
        ['match' => '/^sezam$|sezamov/iu', 'cena' => 150.00, 'jed' => 'kg', 'label' => 'Sezam'],
        ['match' => '/slunečnic.*loupan|loupan.*slunečnic/iu', 'cena' => 110.00, 'jed' => 'kg', 'label' => 'Slunečnice loupaná'],
        ['match' => '/slunečnic/iu', 'cena' => 90.00, 'jed' => 'kg', 'label' => 'Slunečnice'],
        ['match' => '/dýňov.*sem|tykv.*sem/iu', 'cena' => 200.00, 'jed' => 'kg', 'label' => 'Dýňová semínka'],
        ['match' => '/lněn.*sem|len/iu', 'cena' => 80.00, 'jed' => 'kg', 'label' => 'Lněná semínka'],
        ['match' => '/vlašsk.*ořech|ořech.*vlašsk/iu', 'cena' => 350.00, 'jed' => 'kg', 'label' => 'Vlašské ořechy'],
        ['match' => '/lískov.*ořech|ořech.*lískov/iu', 'cena' => 300.00, 'jed' => 'kg', 'label' => 'Lískové ořechy'],
        ['match' => '/mandle.*plát|plát.*mandl/iu', 'cena' => 450.00, 'jed' => 'kg', 'label' => 'Mandle plátkované'],
        ['match' => '/mandle/iu', 'cena' => 380.00, 'jed' => 'kg', 'label' => 'Mandle'],
        ['match' => '/^kokos$|strouhan.*kokos|kokos.*strouhan/iu', 'cena' => 150.00, 'jed' => 'kg', 'label' => 'Strouhaný kokos'],
        ['match' => '/^ořech/iu', 'cena' => 320.00, 'jed' => 'kg', 'label' => 'Ořechy'],

        // === SUŠENÉ OVOCE ===
        ['match' => '/rozink/iu', 'cena' => 140.00, 'jed' => 'kg', 'label' => 'Rozinky'],
        ['match' => '/brusink/iu', 'cena' => 280.00, 'jed' => 'kg', 'label' => 'Brusinky sušené'],
        ['match' => '/datl/iu', 'cena' => 200.00, 'jed' => 'kg', 'label' => 'Datle'],
        ['match' => '/meruňk.*sušen|sušen.*meruňk/iu', 'cena' => 250.00, 'jed' => 'kg', 'label' => 'Sušené meruňky'],

        // === MLÉČNÉ ===
        ['match' => '/(tvaroh.*polotuč|polotuč.*tvaroh|tvaroh\s*polotuč)/iu', 'cena' => 95.00, 'jed' => 'kg', 'label' => 'Tvaroh polotučný'],
        ['match' => '/^tvaroh$/iu', 'cena' => 95.00, 'jed' => 'kg', 'label' => 'Tvaroh'],
        ['match' => '/sýr.*niva|niva/iu', 'cena' => 280.00, 'jed' => 'kg', 'label' => 'Sýr Niva'],
        ['match' => '/(sýr.*eidam|eidam)/iu', 'cena' => 165.00, 'jed' => 'kg', 'label' => 'Sýr Eidam 30 %'],
        ['match' => '/(parmaz|parmes)/iu', 'cena' => 600.00, 'jed' => 'kg', 'label' => 'Parmazán'],
        ['match' => '/^sýr/iu', 'cena' => 180.00, 'jed' => 'kg', 'label' => 'Sýr'],
        ['match' => '/laktóz|laktóza/iu', 'cena' => 80.00, 'jed' => 'kg', 'label' => 'Laktóza'],
        ['match' => '/mléč.*prot|prot.*mléč/iu', 'cena' => 280.00, 'jed' => 'kg', 'label' => 'Mléčný protein'],

        // === NÁPLNĚ ===
        ['match' => '/povid/iu', 'cena' => 95.00, 'jed' => 'kg', 'label' => 'Povidla'],
        ['match' => '/marmelád/iu', 'cena' => 80.00, 'jed' => 'kg', 'label' => 'Marmeláda'],
        ['match' => '/náplň|nápln/iu', 'cena' => 110.00, 'jed' => 'kg', 'label' => 'Náplň'],
        ['match' => '/strouhank/iu', 'cena' => 35.00, 'jed' => 'kg', 'label' => 'Strouhanka'],

        // === MASNÉ ===
        ['match' => '/škvark/iu', 'cena' => 180.00, 'jed' => 'kg', 'label' => 'Škvarky'],
        ['match' => '/uzen.*rolk|rolka.*uzen|uzen.*šunk/iu', 'cena' => 220.00, 'jed' => 'kg', 'label' => 'Uzená rolka / šunka'],
        ['match' => '/slanin/iu', 'cena' => 180.00, 'jed' => 'kg', 'label' => 'Slanina'],

        // === VLOČKY, OBILOVINY ===
        ['match' => '/oves.*vločk|vločk.*oves/iu', 'cena' => 40.00, 'jed' => 'kg', 'label' => 'Ovesné vločky'],
        ['match' => '/vločk/iu', 'cena' => 40.00, 'jed' => 'kg', 'label' => 'Vločky'],
        ['match' => '/pšen.*škrob|škrob.*pšen/iu', 'cena' => 35.00, 'jed' => 'kg', 'label' => 'Pšeničný škrob'],
        ['match' => '/^škrob/iu', 'cena' => 30.00, 'jed' => 'kg', 'label' => 'Škrob'],
        ['match' => '/glukóz.*sirup|sirup.*glukóz/iu', 'cena' => 50.00, 'jed' => 'kg', 'label' => 'Glukózový sirup'],

        // === PEKAŘSKÉ ZLEPŠOVADLA A SMĚSI ===
        ['match' => '/uldo.*spartak|spartak.*uldo|uldo|spartakus/iu', 'cena' => 110.00, 'jed' => 'kg', 'label' => 'Uldo Spartakus'],
        ['match' => '/uniferm|favorit/iu', 'cena' => 105.00, 'jed' => 'kg', 'label' => 'Uniferm Favorit'],
        ['match' => '/malzkraft|malt[ck]raft/iu', 'cena' => 90.00, 'jed' => 'kg', 'label' => 'Malzkraft'],
        ['match' => '/bass\s*tmav/iu', 'cena' => 120.00, 'jed' => 'kg', 'label' => 'Bass tmavý'],
        ['match' => '/diasauer|dia.*sauer/iu', 'cena' => 130.00, 'jed' => 'kg', 'label' => 'Diasauer'],
        ['match' => '/natursoft/iu', 'cena' => 140.00, 'jed' => 'kg', 'label' => 'Natursoft'],
        ['match' => '/maroko/iu', 'cena' => 100.00, 'jed' => 'kg', 'label' => 'Směs Maroko'],
        ['match' => '/pikant/iu', 'cena' => 95.00, 'jed' => 'kg', 'label' => 'Směs Pikant'],
        ['match' => '/fénix|fenix/iu', 'cena' => 105.00, 'jed' => 'kg', 'label' => 'Směs Fénix'],
        ['match' => '/vegipan/iu', 'cena' => 110.00, 'jed' => 'kg', 'label' => 'Vegipan'],
        ['match' => '/victor/iu', 'cena' => 115.00, 'jed' => 'kg', 'label' => 'Směs Victor'],
        ['match' => '/směs/iu', 'cena' => 100.00, 'jed' => 'kg', 'label' => 'Mouka/směs'],
        ['match' => '/zlepš/iu', 'cena' => 95.00, 'jed' => 'kg', 'label' => 'Zlepšovadlo'],

        // === EMULGÁTORY, KYPŘIČE ===
        ['match' => '/lecitin/iu', 'cena' => 180.00, 'jed' => 'kg', 'label' => 'Řepkový lecitin'],
        ['match' => '/emulgát/iu', 'cena' => 150.00, 'jed' => 'kg', 'label' => 'Emulgátor'],
        ['match' => '/kyprč|kypř/iu', 'cena' => 80.00, 'jed' => 'kg', 'label' => 'Kypřící látka'],
        ['match' => '/stabiliz/iu', 'cena' => 200.00, 'jed' => 'kg', 'label' => 'Stabilizátor'],

        // === ČOKOLÁDA ===
        ['match' => '/čokolád.*hořk|hořk.*čokolád/iu', 'cena' => 220.00, 'jed' => 'kg', 'label' => 'Hořká čokoláda'],
        ['match' => '/čokolád.*mléč|mléč.*čokolád/iu', 'cena' => 180.00, 'jed' => 'kg', 'label' => 'Mléčná čokoláda'],
        ['match' => '/čokolád/iu', 'cena' => 200.00, 'jed' => 'kg', 'label' => 'Čokoláda'],
        ['match' => '/kakao/iu', 'cena' => 280.00, 'jed' => 'kg', 'label' => 'Kakao'],

        // === OSTATNÍ ===
        ['match' => '/aroma|aromat/iu', 'cena' => 250.00, 'jed' => 'kg', 'label' => 'Aroma'],
        ['match' => '/jablk|jabl/iu', 'cena' => 35.00, 'jed' => 'kg', 'label' => 'Jablka'],
        ['match' => '/^ovoce|ovoc.*čerst/iu', 'cena' => 60.00, 'jed' => 'kg', 'label' => 'Ovoce'],
        ['match' => '/cookies|cookie|sušenk/iu', 'cena' => 220.00, 'jed' => 'kg', 'label' => 'Sušenky / cookies'],
    ];
}

function najdi_cenu(string $nazev): ?array {
    $rules = cenove_pravidla();
    foreach ($rules as $r) {
        if (preg_match($r['match'], $nazev)) {
            return $r;
        }
    }
    return null;
}

// =============================================================
// Načti suroviny
// =============================================================
$suroviny = $pdo->query("SELECT id, nazev, jednotka, cena_baleni, obsah_baleni FROM suroviny WHERE aktivni = 1 ORDER BY nazev")->fetchAll();

// Vyhodnoť návrhy
$navrhy = [];
$matched = 0; $unmatched = 0;
foreach ($suroviny as $s) {
    $hit = najdi_cenu($s['nazev']);
    if (!$hit) {
        $navrhy[] = [
            'id'         => (int) $s['id'],
            'nazev'      => $s['nazev'],
            'aktualni'   => [
                'cena_baleni'  => (float) ($s['cena_baleni']  ?? 0),
                'obsah_baleni' => (float) ($s['obsah_baleni'] ?? 0),
                'jednotka'     => $s['jednotka'],
            ],
            'navrh'      => null,
            'matched'    => false,
        ];
        $unmatched++;
        continue;
    }
    $navrhy[] = [
        'id'         => (int) $s['id'],
        'nazev'      => $s['nazev'],
        'aktualni'   => [
            'cena_baleni'  => (float) ($s['cena_baleni']  ?? 0),
            'obsah_baleni' => (float) ($s['obsah_baleni'] ?? 0),
            'jednotka'     => $s['jednotka'],
        ],
        'navrh'      => [
            'cena_baleni'  => $hit['cena'],
            'obsah_baleni' => 1,
            'jednotka'     => $hit['jed'],
            'label'        => $hit['label'],
        ],
        'matched'    => true,
    ];
    $matched++;
}

// =============================================================
// GET = preview
// =============================================================
if ($method === 'GET') {
    json_response([
        'preview'   => true,
        'force'     => $force,
        'celkem'    => count($navrhy),
        'matched'   => $matched,
        'unmatched' => $unmatched,
        'navrhy'    => $navrhy,
    ]);
}

// =============================================================
// POST = aplikovat
// =============================================================
if ($method === 'POST') {
    $upd = $pdo->prepare("UPDATE suroviny SET cena_baleni = :cb, obsah_baleni = :ob, jednotka = :j WHERE id = :id");
    $applied = 0; $skipped = 0;
    foreach ($navrhy as $n) {
        if (!$n['matched']) { $skipped++; continue; }
        $hasPrice = ((float) $n['aktualni']['cena_baleni'] > 0 && (float) $n['aktualni']['obsah_baleni'] > 0);
        if (!$force && $hasPrice) { $skipped++; continue; }
        $upd->execute([
            'id' => $n['id'],
            'cb' => $n['navrh']['cena_baleni'],
            'ob' => $n['navrh']['obsah_baleni'],
            'j'  => $n['navrh']['jednotka'],
        ]);
        $applied++;
    }
    json_response([
        'ok'        => true,
        'force'     => $force,
        'applied'   => $applied,
        'skipped'   => $skipped,
        'unmatched' => $unmatched,
    ]);
}

json_error('Neznámá metoda', 405);
