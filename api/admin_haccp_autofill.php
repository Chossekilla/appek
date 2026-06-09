<?php
/**
 * HACCP autofill — automaticky vyplní HACCP data pro všechny výrobky
 *
 * Pravidla:
 *   - Typ výrobku se detekuje podle názvu, kategorie a složení
 *   - Vyplní jen prázdná pole (zachová manuální úpravy)
 *   - Volitelně lze přepsat existující data: ?force=1
 *   - Také přiřadí haccp_graf_id k odpovídající šabloně
 *
 * Endpointy:
 *   GET    /api/admin_haccp_autofill.php             → preview (vrátí návrh, neuloží)
 *   POST   /api/admin_haccp_autofill.php             → aplikuje (uloží)
 *   POST   /api/admin_haccp_autofill.php?force=1     → aplikuje + přepíše existující hodnoty
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/_admin_auth.php';

// 🆕 v3.0.221 — soubor lze includnout jako knihovnu: `define('HACCP_AUTOFILL_LIB',1)` před
//   require → definují se jen funkce (haccp_autofill_one / build_haccp_for_vyrobek), endpoint
//   se nespustí. Používá admin_vyrobky.php pro auto-HACCP nového výrobku (drží provázání).
if (!defined('HACCP_AUTOFILL_LIB')) {
    cors_headers();
    require_admin();
}

$pdo = db();
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$force = !empty($_GET['force']);

// =============================================================
// Detekce typu výrobku z názvu / kategorie / složení
// =============================================================
function detect_typ(string $nazev, string $kat, string $sloz): string {
    $n = mb_strtolower($nazev);
    $k = mb_strtolower($kat);
    $s = mb_strtolower($sloz);

    // Chléb
    if (preg_match('/chl[eé]b|šumav|konzum|selsk[ýý]\s+chl/u', $n) ||
        preg_match('/chl[eé]b/u', $k)) return 'chleba';

    // Jemné pečivo
    if (preg_match('/kol[áa]č|š[áa]ne[čc]e[kn]?|z[áa]vinka|placka|t[yý][čc]ka|v[áa]no[čc]ka|buchta|jemn[éě]|šne[kc]|donut|cookie|sušenk|vějíř|roláda|závin|šáteček/u', $n) ||
        preg_match('/jemn[éě]|sladk|cukr/u', $k)) return 'jemne';

    // Dalamánky / speciální se žitnou moukou
    if (preg_match('/dalam[áa]nek|speci[áa]ln/u', $n) ||
        (preg_match('/žitn|vlašsk|sl[uú]ne[čc]nic|sezam|s[ýý]r|ořech/u', $s) && !preg_match('/chl[eé]b/u', $n))) return 'specialni';

    // Pšeničné se zdobením
    if (preg_match('/m[áa]k|sezam|s[ýý]r|směs|maroko|pikant|f[eé]nix|cel[oz]rnn|sypan[ýý]|s[uů]l\s+kmín|kmín/u', $n) ||
        preg_match('/m[áa]k|sezam|s[ýý]r|maroko|pikant|f[eé]nix/u', $s)) return 'psenicne_zdobeni';

    return 'psenicne_zaklad';
}

// =============================================================
// Detekce tvaru z názvu (pro popis_produktu)
// =============================================================
function detect_tvar(string $nazev, string $typ): string {
    $n = mb_strtolower($nazev);

    if (preg_match('/baget/u', $n)) return 'výrobek podlouhlého tvaru s podélným nářezem, nebalený';
    if (preg_match('/rohl[íi]k/u', $n)) return 'výrobek podlouhlého zatočeného tvaru, nebalený';
    if (preg_match('/žeml/u', $n)) return 'výrobek kulatého tvaru, nebalený';
    if (preg_match('/houska/u', $n)) return 'výrobek kulatého tvaru s nářezem, nebalený';
    if (preg_match('/banketka/u', $n)) return 'výrobek malého podlouhlého tvaru, nebalený';
    if (preg_match('/veka/u', $n)) return 'výrobek podlouhlého tvaru, nebalený';
    if (preg_match('/placka/u', $n)) return 'výrobek plochého kulatého tvaru, nebalený';
    if (preg_match('/t[yý][čc]k/u', $n)) return 'výrobek podlouhlého obdélníkového tvaru, nebalený';
    if (preg_match('/dalam[áa]nek/u', $n)) return 'výrobek menšího kulatého tvaru, nebalený';
    if (preg_match('/chl[eé]b/u', $n)) return 'výrobek bochníkového tvaru, nebalený';
    if (preg_match('/kol[áa]č|š[áa]te[čc]ek|závin|roláda/u', $n)) return 'výrobek různého jemného tvaru, nebalený';
    if (preg_match('/v[áa]no[čc]k/u', $n)) return 'výrobek pleteného podlouhlého tvaru, nebalený';

    if ($typ === 'chleba') return 'výrobek bochníkového tvaru, nebalený';
    if ($typ === 'jemne') return 'výrobek různého jemného tvaru, nebalený';
    if ($typ === 'specialni') return 'výrobek menšího kulatého tvaru, nebalený';
    return 'výrobek různého tvaru, nebalený';
}

// =============================================================
// Pravidla per typ
// =============================================================
function rules_for_typ(string $typ): array {
    switch ($typ) {
        case 'chleba': return [
            'graf_nazev' => 'Chleba',
            'skupina' => 'chléb',
            'produkt' => 'chléb',
            'trvanlivost' => '72 hodin od doby výroby',
            'pec_t' => '240–260 °C',
            'pec_d' => '30–45 minut',
            'vnitrni_t' => 'min. 92 °C',
        ];
        case 'jemne': return [
            'graf_nazev' => 'Jemné pečivo',
            'skupina' => 'jemné pečivo',
            'produkt' => 'jemné pečivo',
            'trvanlivost' => '120 hodin od doby výroby',
            'pec_t' => '180–210 °C',
            'pec_d' => '12–18 minut',
            'vnitrni_t' => 'min. 88 °C',
        ];
        case 'specialni': return [
            'graf_nazev' => 'Speciální pečivo (dalamánky)',
            'skupina' => 'speciální pečivo',
            'produkt' => 'speciální pečivo',
            'trvanlivost' => '48 hodin od doby výroby',
            'pec_t' => '220–230 °C',
            'pec_d' => '15–20 minut',
            'vnitrni_t' => 'min. 90 °C',
        ];
        case 'psenicne_zdobeni': return [
            'graf_nazev' => 'Pšeničné pečivo se zdobením',
            'skupina' => 'běžné pšeničné pečivo',
            'produkt' => 'běžné pečivo pšeničné',
            'trvanlivost' => '24 hodin od doby výroby',
            'pec_t' => '220–240 °C',
            'pec_d' => '12–22 minut',
            'vnitrni_t' => 'min. 90 °C',
        ];
        default: /* psenicne_zaklad */ return [
            'graf_nazev' => 'Pšeničné pečivo — základní',
            'skupina' => 'běžné pšeničné pečivo',
            'produkt' => 'běžné pečivo pšeničné',
            'trvanlivost' => '24 hodin od doby výroby',
            'pec_t' => '220–240 °C',
            'pec_d' => '12–22 minut',
            'vnitrni_t' => 'min. 90 °C',
        ];
    }
}

// =============================================================
// Jakost (organoleptika) per typ
// =============================================================
function jakost_pro_typ(string $typ): array {
    switch ($typ) {
        case 'chleba': return [
            'vzhled' => 'pravidelný bochníkový tvar, kůrka pevná zlatohnědá až tmavě hnědá, případně s nářezy a posypem',
            'tvar'   => 'bochník (kulatý nebo podlouhlý) bez deformací a popraskání',
            'vune'   => 'specifická chlebová, příjemná po žitném kvasu, případně kmínu',
            'chut'   => 'výrazná chlebová, mírně nakyslá (ne přepálená, ne kyselá)',
            'struktura' => 'pružná pórovitá střída bez velkých dutin a hrudek mouky',
        ];
        case 'jemne': return [
            'vzhled' => 'rovnoměrně propečený, kůrka zlatohnědá s lesklým povrchem (mašlování), případně posyp či glazura',
            'tvar'   => 'pravidelný (kulatý / obdélníkový / podlouhlý / pletený), bez popraskaných okrajů',
            'vune'   => 'sladká pečenice, po vanilce / másle / náplni',
            'chut'   => 'sladká, příjemná, charakteristická pro náplň, bez cizích pachutí',
            'struktura' => 'jemná, křehká, nadýchaná střída',
        ];
        case 'specialni': return [
            'vzhled' => 'menší výrobek s tmavší kůrkou (žitná složka), případně posyp (ořechy, slunečnice, sýr, vločky, sezam)',
            'tvar'   => 'kulatý nebo podlouhlý, kompaktní bez deformací',
            'vune'   => 'po žitné mouce, kvasu, případně po posypu',
            'chut'   => 'plnější, mírně nakyslá, charakteristická pro posyp',
            'struktura' => 'pevná pružná střída s viditelnými kousky posypu',
        ];
        case 'psenicne_zdobeni': return [
            'vzhled' => 'rovnoměrně propečený, kůrka zlatohnědá křupavá, posyp rovnoměrně rozprostřený (mák, sezam, sůl, kmín, sýr nebo směs)',
            'tvar'   => 'pravidelný dle typu (rohlík/žemle/bageta/houska/banketka), bez deformací',
            'vune'   => 'příjemná po čerstvě upečeném pečivu, případně po posypu',
            'chut'   => 'výrazná pekařská, mírně sladká, charakteristická pro daný posyp',
            'struktura' => 'lehká pórovitá střída, kůrka křupavá',
        ];
        default: /* psenicne_zaklad */ return [
            'vzhled' => 'rovnoměrně propečený, kůrka zlatohnědá křupavá, bez ohnívání',
            'tvar'   => 'pravidelný dle typu (rohlík/žemle/bageta/houska/banketka), bez deformací',
            'vune'   => 'příjemná po čerstvě upečeném pečivu, bez cizích pachů',
            'chut'   => 'výrazná pekařská, mírně sladká, bez cizích pachutí',
            'struktura' => 'lehká pórovitá střída, kůrka křupavá',
        ];
    }
}

// =============================================================
// Kritické body s konkrétními teplotami pečení
// =============================================================
function kriticke_body_pro_typ(array $rules): array {
    $pec_t = $rules['pec_t'];
    $pec_d = $rules['pec_d'];
    $vnitrni = $rules['vnitrni_t'];
    return [
        ['krok' => 'Příjem surovin',     'typ' => 'B',  'popis' => 'Kontaminace mikroorganizmy a škůdci',
         'opatreni' => 'Ověření dodavatelů (atesty), vizuální kontrola obalů a DMT, kontrola teploty chlazených surovin',
         'riziko' => 'S', 'ccp' => 'CP'],
        ['krok' => 'Příjem surovin',     'typ' => 'CH', 'popis' => 'Mykotoxiny, rezidua pesticidů, těžké kovy',
         'opatreni' => 'Atesty dodavatelů, kontrola specifikací, audity dodavatelů',
         'riziko' => 'S', 'ccp' => 'CP'],
        ['krok' => 'Příjem surovin',     'typ' => 'F',  'popis' => 'Cizí tělesa (kameny, sklo, kovy, plast)',
         'opatreni' => 'Vizuální kontrola, sítování mouky, kovová detekce u rizikových surovin',
         'riziko' => 'M', 'ccp' => 'CP'],
        ['krok' => 'Skladování surovin', 'typ' => 'B',  'popis' => 'Pomnožení mikroorganizmů, tvorba toxinů',
         'opatreni' => 'Dodržení podmínek skladování (do 25 °C, vlhkost <70 %), FIFO, kontrola teploty a vlhkosti',
         'riziko' => 'S', 'ccp' => 'CP'],
        ['krok' => 'Hnětení / zrání',    'typ' => 'B',  'popis' => 'Kontaminace MO z prostředí, nářadí a obsluhy',
         'opatreni' => 'Hygiena pracoviště a pracovníků (deníky úklidu, pravidelná dezinfekce), kontrola zdravotního stavu zaměstnanců',
         'riziko' => 'M', 'ccp' => 'CP'],
        ['krok' => 'Pečení',             'typ' => 'B',  'popis' => 'Nedostatečné prohřátí — přežití patogenů (Salmonella, E. coli, Bacillus cereus)',
         'opatreni' => "Kontrola teploty pece $pec_t a doby pečení $pec_d, kontrola vnitřní teploty produktu $vnitrni, denní záznamy do provozního deníku",
         'riziko' => 'V', 'ccp' => 'CCP'],
        ['krok' => 'Chladnutí',          'typ' => 'B',  'popis' => 'Sekundární kontaminace, kondenzace par',
         'opatreni' => 'Chlazení v čistém prostředí, dostatečný odvod par, oddělení od surového těsta',
         'riziko' => 'M', 'ccp' => 'CP'],
        ['krok' => 'Skladování hotových výrobků','typ' => 'B','popis' => 'Pomnožení MO při nesprávné teplotě a vlhkosti',
         'opatreni' => 'Kontrola teploty skladu (do 25 °C), FIFO, čistota a integrita přepravek',
         'riziko' => 'M', 'ccp' => 'CP'],
        ['krok' => 'Expedice',           'typ' => 'F',  'popis' => 'Mechanická kontaminace při manipulaci',
         'opatreni' => 'Čisté přepravky a vozidla, hygiena řidičů, kontrola DMT před nakládkou',
         'riziko' => 'N', 'ccp' => 'CP'],
    ];
}

function mikrobio_text(): string {
    return "Mikrobiologické limity dle Nařízení EK 2073/2005 a interní směrnice:\n"
         . "• Salmonella spp.: nedetekováno v 25 g\n"
         . "• E. coli: < 10 KTJ/g\n"
         . "• Staphylococcus aureus: < 100 KTJ/g\n"
         . "• Plísně a kvasinky: < 10³ KTJ/g (po 24 h skladování při 25 °C)\n"
         . "• Listeria monocytogenes: nedetekováno v 25 g (u trvanlivějších výrobků)";
}

// =============================================================
// Načti firmu pro cilovy_trh
// =============================================================
$firma_nazev = nastaveni_get($pdo, 'firma_nazev', 'APPEK B2B s.r.o.');
$firma_ulice = nastaveni_get($pdo, 'firma_ulice', '');
$firma_mesto = nastaveni_get($pdo, 'firma_mesto', '');
$cilovy_trh_default = trim($firma_nazev . ', ' . $firma_ulice . ', ' . $firma_mesto, ' ,');

// =============================================================
// Načti grafy → mapování graf_nazev → { id, kroky[] } a id → graf
// =============================================================
$grafyMapa = [];      // nazev → id
$grafyKrokyById = []; // id    → array kroky
try {
    $rows = $pdo->query("SELECT id, nazev, kroky FROM haccp_grafy WHERE aktivni = 1")->fetchAll();
    foreach ($rows as $r) {
        $grafyMapa[$r['nazev']] = (int) $r['id'];
        $kroky = is_string($r['kroky']) ? json_decode($r['kroky'], true) : ($r['kroky'] ?? []);
        $grafyKrokyById[(int) $r['id']] = is_array($kroky) ? $kroky : [];
    }
} catch (Throwable $e) { /* tabulka nemusí existovat */ }

// =============================================================
// Vygeneruj návrh dat per výrobek
// =============================================================
function build_haccp_for_vyrobek(array $v, string $cilovy_trh, array $grafyMapa, array $grafyKrokyById, bool $force): array {
    $typ = detect_typ($v['nazev'] ?? '', $v['kategorie_nazev'] ?? '', $v['slozeni'] ?? '');
    $tvar = detect_tvar($v['nazev'] ?? '', $typ);
    $rules = rules_for_typ($typ);

    // Existující haccp_data
    $hd = [];
    if (!empty($v['haccp_data'])) {
        $tmp = json_decode($v['haccp_data'], true);
        if (is_array($tmp)) $hd = $tmp;
    }
    $orig = $hd;

    // Helper: nastav klíč jen pokud je prázdný (nebo force)
    $set = function(string $k, $val) use (&$hd, $force, $orig) {
        if ($force || empty($hd[$k])) $hd[$k] = $val;
    };
    $setIfEmpty = function(string $k, $val) use (&$hd) {
        if (empty($hd[$k])) $hd[$k] = $val;
    };

    // Standardní pole
    $set('produkt',        $rules['produkt']);
    $set('obchodni_jmeno', $v['nazev'] ?? '');
    $set('misto_vyroby',   'ČR');
    $set('cilovy_trh',     $cilovy_trh);
    $set('skupina',        $rules['skupina']);
    $set('popis_produktu', $tvar);
    $set('zpusob_uziti',   'k přímé konzumaci');
    $set('baleni',         'nebalený');
    $set('trvanlivost',    $rules['trvanlivost']);
    $set('skladovani',     'do 25 °C, suché místo bez výrazných pachů, vlhkost < 70 %');
    $set('distribuce',     'výrobek určen pro prodej v provozovnách APPEK B2B');
    $set('omezeni',        'bez omezení (nevhodné pro diabetiky, coeliky)');

    // Kritické body — pouze pokud chybí nebo force
    if ($force || empty($hd['kriticke_body'])) {
        $hd['kriticke_body'] = kriticke_body_pro_typ($rules);
    }

    // Jakost
    $jak = jakost_pro_typ($typ);
    if (!is_array($hd['jakost'] ?? null)) $hd['jakost'] = [];
    foreach ($jak as $k => $v2) {
        if ($force || empty($hd['jakost'][$k])) $hd['jakost'][$k] = $v2;
    }

    // Mikrobio
    if ($force || empty($hd['mikrobio'])) $hd['mikrobio'] = mikrobio_text();

    // Graf_id: přiřaď, pokud výrobek dosud nemá nebo force
    $graf_id = (int) ($v['haccp_graf_id'] ?? 0);
    if (!$graf_id || $force) {
        $graf_id = (int) ($grafyMapa[$rules['graf_nazev']] ?? 0);
    }

    // Postup — zkopíruj kroky z přiřazené šablony do per-výrobek dat
    // (PDF generátor by je sice vzal i ze šablony, ale uživatel chce vidět vyplněný editor)
    if ($graf_id && isset($grafyKrokyById[$graf_id])) {
        $tplKroky = $grafyKrokyById[$graf_id];
        $shouldCopy = $force
            || empty($hd['postup'])
            || !is_array($hd['postup'])
            || count($hd['postup']) !== count($tplKroky);
        if ($shouldCopy) {
            $hd['postup'] = $tplKroky;
        } else {
            // Zachovej ruční úpravy: doplň prázdné popisy z template
            foreach ($hd['postup'] as $i => $k) {
                if (empty(trim((string) ($k['popis'] ?? '')))) {
                    $hd['postup'][$i]['popis'] = $tplKroky[$i]['popis'] ?? '';
                }
                if (empty(trim((string) ($k['nazev'] ?? '')))) {
                    $hd['postup'][$i]['nazev'] = $tplKroky[$i]['nazev'] ?? '';
                }
            }
        }
    }

    return [
        'id' => (int) $v['id'],
        'nazev' => $v['nazev'],
        'cislo' => $v['cislo'],
        'typ' => $typ,
        'graf_nazev' => $rules['graf_nazev'],
        'graf_id' => $graf_id ?: null,
        'haccp_data' => $hd,
        'changes' => array_diff_key($hd, $orig),
    ];
}

// =============================================================
// 🆕 v3.0.221 — auto-HACCP pro JEDEN výrobek (volá admin_vyrobky.php po vytvoření).
//   Self-contained (rebuilds grafy mapu + cílový trh). No-op, pokud nejsou grafy.
// =============================================================
function haccp_autofill_one(PDO $pdo, int $vyrobekId, bool $force = false): array {
    $st = $pdo->prepare("SELECT v.id, v.cislo, v.nazev, v.popis, v.slozeni, v.haccp_data, v.haccp_graf_id,
                                k.nazev AS kategorie_nazev
                         FROM vyrobky v LEFT JOIN kategorie_vyrobku k ON v.kategorie_id = k.id
                         WHERE v.id = :id");
    $st->execute(['id' => $vyrobekId]);
    $row = $st->fetch();
    if (!$row) return ['ok' => false, 'reason' => 'not_found'];

    $grafyMapa = []; $grafyKrokyById = [];
    try {
        foreach ($pdo->query("SELECT id, nazev, kroky FROM haccp_grafy WHERE aktivni = 1")->fetchAll() as $r) {
            $grafyMapa[$r['nazev']] = (int) $r['id'];
            $kroky = is_string($r['kroky']) ? json_decode($r['kroky'], true) : ($r['kroky'] ?? []);
            $grafyKrokyById[(int) $r['id']] = is_array($kroky) ? $kroky : [];
        }
    } catch (Throwable $e) {}
    if (empty($grafyMapa)) return ['ok' => false, 'reason' => 'no_grafy']; // žádné šablony → nic

    $cilovy = trim(nastaveni_get($pdo, 'firma_nazev', '') . ', '
                 . nastaveni_get($pdo, 'firma_ulice', '') . ', '
                 . nastaveni_get($pdo, 'firma_mesto', ''), ' ,');
    $n = build_haccp_for_vyrobek($row, $cilovy, $grafyMapa, $grafyKrokyById, $force);
    $hdJson = json_encode($n['haccp_data'], JSON_UNESCAPED_UNICODE);
    if (!empty($n['graf_id'])) {
        $pdo->prepare("UPDATE vyrobky SET haccp_data = :hd, haccp_graf_id = :gid WHERE id = :id")
            ->execute(['hd' => $hdJson, 'gid' => $n['graf_id'], 'id' => $vyrobekId]);
    } else {
        $pdo->prepare("UPDATE vyrobky SET haccp_data = :hd WHERE id = :id")
            ->execute(['hd' => $hdJson, 'id' => $vyrobekId]);
    }
    return ['ok' => true, 'graf_id' => $n['graf_id'] ?? 0, 'typ' => $n['typ'] ?? ''];
}

// === Endpoint runtime (přeskočí se v lib módu) ===
if (!defined('HACCP_AUTOFILL_LIB')):

// =============================================================
// Načti všechny výrobky
// =============================================================
$vyrobky = $pdo->query("
    SELECT v.id, v.cislo, v.nazev, v.popis, v.slozeni, v.haccp_data, v.haccp_graf_id,
           k.nazev AS kategorie_nazev
    FROM vyrobky v
    LEFT JOIN kategorie_vyrobku k ON v.kategorie_id = k.id
    WHERE v.aktivni = 1
    ORDER BY v.poradi, v.nazev
")->fetchAll();

// Build návrhy
$navrhy = [];
$type_summary = [];
foreach ($vyrobky as $v) {
    $n = build_haccp_for_vyrobek($v, $cilovy_trh_default, $grafyMapa, $grafyKrokyById, $force);
    $navrhy[] = $n;
    $type_summary[$n['typ']] = ($type_summary[$n['typ']] ?? 0) + 1;
}

// =============================================================
// GET = preview, POST = aplikuj
// =============================================================
if ($method === 'GET') {
    json_response([
        'preview' => true,
        'force' => $force,
        'celkem' => count($navrhy),
        'po_typu' => $type_summary,
        'cilovy_trh' => $cilovy_trh_default,
        'grafy_dostupne' => $grafyMapa,
        'navrhy' => $navrhy,
    ]);
}

if ($method === 'POST') {
    if (empty($grafyMapa)) {
        json_error('Nejprve naimportuj výchozí sadu HACCP grafů (HACCP → Grafy → Importovat výchozí sadu).');
    }

    $upd_haccp = $pdo->prepare("UPDATE vyrobky SET haccp_data = :hd, haccp_graf_id = :gid WHERE id = :id");
    $upd_haccp_only = $pdo->prepare("UPDATE vyrobky SET haccp_data = :hd WHERE id = :id");

    $count_haccp = 0;
    $count_graf = 0;
    foreach ($navrhy as $n) {
        $hdJson = json_encode($n['haccp_data'], JSON_UNESCAPED_UNICODE);
        if ($n['graf_id']) {
            $upd_haccp->execute(['hd' => $hdJson, 'gid' => $n['graf_id'], 'id' => $n['id']]);
            $count_graf++;
        } else {
            $upd_haccp_only->execute(['hd' => $hdJson, 'id' => $n['id']]);
        }
        $count_haccp++;
    }

    json_response([
        'ok' => true,
        'force' => $force,
        'updated_haccp' => $count_haccp,
        'assigned_graf' => $count_graf,
        'po_typu' => $type_summary,
    ]);
}

json_error('Neznámá metoda', 405);

endif; // 🆕 v3.0.221 — konec endpoint runtime (lib mód běží jen funkce výše)
