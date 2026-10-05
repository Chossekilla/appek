<?php
/**
 * 🧪 APPEK smoke-chain — generátory testovacích dat + jednotlivé fáze řetězce.
 * Vyžaduje lib.php. Volá run.php (orchestrace + workery).
 */
declare(strict_types=1);
require_once __DIR__ . '/lib.php';

// ───────────────────────── generátor českých dat ─────────────────────────
const JM_M = ['Jan','Petr','Josef','Pavel','Martin','Tomáš','Jiří','Lukáš','Jakub','David','Ondřej','Michal','Karel','Milan','Vojtěch','Filip','Adam','Marek','Roman','Zdeněk','Radek','Libor','Stanislav','Vladimír'];
const JM_Z = ['Jana','Eva','Hana','Anna','Lenka','Kateřina','Lucie','Věra','Alena','Petra','Veronika','Martina','Tereza','Michaela','Barbora','Klára','Monika','Ivana','Zuzana','Markéta','Dana','Simona'];
const PRIJ = [['Novák','Nováková'],['Svoboda','Svobodová'],['Novotný','Novotná'],['Dvořák','Dvořáková'],['Černý','Černá'],['Procházka','Procházková'],['Kučera','Kučerová'],['Veselý','Veselá'],['Horák','Horáková'],['Němec','Němcová'],['Marek','Marková'],['Pospíšil','Pospíšilová'],['Pokorný','Pokorná'],['Hájek','Hájková'],['Král','Králová'],['Jelínek','Jelínková'],['Růžička','Růžičková'],['Beneš','Benešová'],['Fiala','Fialová'],['Sedláček','Sedláčková'],['Doležal','Doležalová'],['Zeman','Zemanová'],['Kolář','Kolářová'],['Navrátil','Navrátilová'],['Čermák','Čermáková'],['Vaněk','Vaňková'],['Urban','Urbanová'],['Blažek','Blažková']];
const ULICE = ['Hlavní','Nádražní','Školní','Masarykova','Husova','Palackého','Komenského','Smetanova','Jiráskova','Tyršova','Žižkova','Nerudova','Sokolská','Lidická','Zahradní','Polní','Lipová','Krátká','Dlouhá','Mírová','Vítězná','Pražská','Brněnská','Revoluční','Štefánikova','Havlíčkova','Riegrova','Karlova'];
const MESTA = [['Praha','110 00'],['Praha','130 00'],['Praha','150 00'],['Praha','170 00'],['Brno','602 00'],['Brno','612 00'],['Ostrava','702 00'],['Ostrava','708 00'],['Plzeň','301 00'],['Liberec','460 01'],['Olomouc','779 00'],['České Budějovice','370 01'],['Hradec Králové','500 02'],['Ústí nad Labem','400 01'],['Pardubice','530 02'],['Zlín','760 01'],['Havířov','736 01'],['Kladno','272 01'],['Most','434 01'],['Opava','746 01'],['Frýdek-Místek','738 01'],['Jihlava','586 01'],['Karviná','733 01'],['Teplice','415 01'],['Děčín','405 02'],['Karlovy Vary','360 01'],['Chomutov','430 01'],['Jablonec nad Nisou','466 01'],['Mladá Boleslav','293 01'],['Prostějov','796 01'],['Přerov','750 02'],['Česká Lípa','470 01'],['Třebíč','674 01'],['Tábor','390 02'],['Znojmo','669 02'],['Příbram','261 01'],['Cheb','350 02'],['Kolín','280 02'],['Trutnov','541 01'],['Písek','397 01'],['Kroměříž','767 01'],['Šumperk','787 01'],['Vsetín','755 01'],['Litoměřice','412 01'],['Nový Jičín','741 01'],['Uherské Hradiště','686 01'],['Havlíčkův Brod','580 01'],['Hodonín','695 01'],['Břeclav','690 02'],['Strakonice','386 01'],['Klatovy','339 01'],['Beroun','266 01']];
const NAZVY = ['U Lípy','Na Rohu','U Zlatého klasu','Modrá hvězda','U Kapličky','Na Náměstí','Pod Lesem','U Mlýna','Zelený dům','U Tří lip','Na Hrázi','Pod Hradem','U Kostela','Na Kopečku','Slunečnice','Panorama','U Fontány','Stará pošta','U Bílého koníčka','Na Spilce','Kotva','Radnice','Na Statku','U Rybníka','Vyhlídka','Bohemia','Morava','Centrum','U Zvonu','Pohoda','Sluníčko','U Pramene'];
// segment => [typ, prefixy názvu, preferovaná cenová skupina (název), váha]
const SEGMENTY = [
    'restaurace' => ['restaurace', ['Restaurace','Hostinec','Pivnice','Restaurant'], 'Restaurace', 22],
    'bistro'     => ['bistro', ['Bistro','Bufet','Snack bar','Jídelna'], 'Restaurace', 18],
    'kavarna'    => ['kavarna', ['Kavárna','Café','Cukrárna','Kafírna'], 'Kavárny', 18],
    'hotel'      => ['hotel', ['Hotel','Penzion','Apartmány','Wellness hotel'], 'Hotely', 12],
    'jidelna'    => ['jidelna', ['Školní jídelna ZŠ','Jídelna MŠ','Menza','Závodní jídelna'], 'Školní a závodní jídelny', 15],
    'obchod'     => ['obchod', ['Potraviny','Večerka','Smíšené zboží','Minimarket'], null, 15],
];
function pick(array $a) { $a = array_values($a); return $a[mt_rand(0, count($a) - 1)]; }
function wpick(array $w) { $s = (int) array_sum($w); $r = mt_rand(1, max(1, $s)); foreach ($w as $k => $v) { $r -= $v; if ($r <= 0) return $k; } return array_key_first($w); }
function slug(string $s): string {
    $s = strtr($s, ['á'=>'a','č'=>'c','ď'=>'d','é'=>'e','ě'=>'e','í'=>'i','ň'=>'n','ó'=>'o','ř'=>'r','š'=>'s','ť'=>'t','ú'=>'u','ů'=>'u','ý'=>'y','ž'=>'z','Á'=>'a','Č'=>'c','É'=>'e','Ě'=>'e','Í'=>'i','Ř'=>'r','Š'=>'s','Ú'=>'u','Ý'=>'y','Ž'=>'z']);
    return trim((string) preg_replace('/[^a-z0-9]+/', '-', strtolower($s)), '-');
}
function ico_gen(): string {
    $d = []; for ($k = 0; $k < 7; $k++) $d[] = mt_rand($k === 0 ? 1 : 0, 9);
    $s = 0; foreach ($d as $k => $v) $s += $v * (8 - $k);
    $r = $s % 11; $c = $r === 0 ? 1 : ($r === 1 ? 0 : 11 - $r);
    return implode('', $d) . $c;
}
function gen_customer(int $i, array $groups): array {
    mt_srand(crc32((string) cfg('run')) + $i * 7919);
    $seg = wpick(array_map(fn($s) => $s[3], SEGMENTY));
    [$typ, $pref, $grpName] = SEGMENTY[$seg];
    $zena = mt_rand(0, 1) === 1; $pr = pick(PRIJ);
    $jmeno = ($zena ? pick(JM_Z) : pick(JM_M)) . ' ' . ($zena ? $pr[1] : $pr[0]);
    [$mesto, $psc] = pick(MESTA);
    $base = pick($pref) . ' ' . (mt_rand(0, 3) === 0 ? $mesto : pick(NAZVY));
    $suf = wpick(['s.r.o.' => 55, 'a.s.' => 5, 'v.o.s.' => 4, 'osvc' => 36]);
    $nazev = $suf === 'osvc' ? "$jmeno – $base" : "$base $suf";
    $ico = ico_gen(); $platce = mt_rand(1, 100) <= 70;
    $grp = null; $r1 = mt_rand(1, 100); $r2 = mt_rand(1, 100);
    if ($r1 <= 75 && $grpName && isset($groups[$grpName])) $grp = $groups[$grpName];
    elseif ($r2 <= 40 && $groups) $grp = pick($groups);
    return [
        'i' => $i, 'cislo' => cfg('tag') . '-C' . sprintf('%05d', $i), 'nazev' => $nazev, 'typ' => $typ,
        'ico' => $ico, 'dic' => $platce ? 'CZ' . $ico : null,
        'ulice' => pick(ULICE) . ' ' . mt_rand(1, 220), 'mesto' => $mesto, 'psc' => $psc,
        'telefon' => '+420 ' . pick(['6', '7']) . mt_rand(0, 9) . mt_rand(0, 9) . ' ' . mt_rand(100, 999) . ' ' . mt_rand(100, 999),
        'kontaktni_osoba' => $jmeno, 'email' => null, 'notif_emaily' => 0,
        'login_email' => slug(explode(' ', $base)[0]) . '.' . $i . '@' . strtolower((string) cfg('tag')) . '.invalid',
        'heslo' => cust_pw($i), 'splatnost_dni' => wpick([0 => 5, 7 => 15, 14 => 50, 21 => 15, 30 => 15]),
        'cenova_skupina_id' => $grp, 'vytvorit_hlavni_pobocku' => 1,
        'poznamka' => 'Smoke-chain ' . cfg('run') . ' (automaticky zatezovy test, nemazat)',
    ];
}

// suroviny: název => [jednotka, cena_baleni, obsah_baleni]  (podle názvu; chybějící se vytvoří)
const SUROVINY_DEF = [
    'Mouka pšeničná chlebová T1050' => ['g', 340, 25000], 'Mouka žitná chlebová T960' => ['g', 380, 25000],
    'Špaldová mouka' => ['g', 880, 25000], 'Sůl jedlá' => ['g', 65, 25000], 'Droždí čerstvé pekařské' => ['g', 95, 1000],
    'Kvásek žitný startér' => ['g', 180, 1000], 'Máslo selské 82%' => ['g', 75, 250], 'Olej slunečnicový' => ['ml', 250, 5000],
    'Tvaroh měkký' => ['g', 78, 500], 'Vejce slepičí M (1 ks ≈ 60 g)' => ['ks', 240, 60], 'Cukr moučka' => ['g', 720, 25000],
    'Cukr krystal' => ['g', 720, 25000], 'Sezamová semínka' => ['g', 1850, 25000], 'Slunečnicová semínka loupaná' => ['g', 1450, 25000],
    'Lněné semínko' => ['g', 1250, 25000], 'Mandle plátky' => ['g', 3150, 5000], 'Rozinky' => ['g', 480, 5000],
    'Povidla švestková' => ['g', 380, 5000], 'Kmín celý' => ['g', 420, 1000], 'Citronová kůra sušená' => ['g', 690, 500],
    'Sádlo vepřové škvařené' => ['g', 160, 1000],
];
// výrobky: klíč => [název, kategorie, cena, moq, je_polotovar, sleduje_sklad, recept[[surovina|@klíč, mn]]]
const VYROBKY_DEF = [
    'P1' => ['Těsto kynuté pšeničné (polotovar)', 'Pečivo', 0, 1, 1, 0, [['Mouka pšeničná chlebová T1050', 600], ['Droždí čerstvé pekařské', 20], ['Sůl jedlá', 12], ['Olej slunečnicový', 30], ['Cukr krystal', 15]]],
    'P2' => ['Kvásek žitný aktivní (polotovar)', 'Chleby', 0, 1, 1, 1, [['Mouka žitná chlebová T960', 500], ['Kvásek žitný startér', 50]]],
    'V1' => ['Kváskový chléb žitný 1 kg', 'Chleby', 52.0, 1, 0, 0, [['@P2', 0.4], ['Mouka žitná chlebová T960', 350], ['Sůl jedlá', 18], ['Kmín celý', 5]]],
    'V2' => ['Rohlík sádlový 43 g', 'Pečivo', 3.2, 10, 0, 0, [['@P1', 0.05], ['Sádlo vepřové škvařené', 3]]],
    'V3' => ['Houska sezamová 60 g', 'Pečivo', 6.5, 5, 0, 0, [['@P1', 0.07], ['Sezamová semínka', 4]]],
    'V4' => ['Kaiserka cereální 70 g', 'Pečivo', 8.9, 5, 0, 0, [['@P1', 0.06], ['Lněné semínko', 3], ['Slunečnicová semínka loupaná', 3], ['Špaldová mouka', 10]]],
    'V5' => ['Koláč tvarohový 80 g', 'Sladké pečivo', 16.0, 1, 0, 0, [['@P1', 0.04], ['Tvaroh měkký', 30], ['Cukr moučka', 8], ['Vejce slepičí M (1 ks ≈ 60 g)', 0.1]]],
    'V6' => ['Buchta povidlová 90 g', 'Sladké pečivo', 17.0, 1, 0, 0, [['@P1', 0.05], ['Povidla švestková', 25], ['Máslo selské 82%', 5]]],
    'V7' => ['Chléb Šumava 1,2 kg', 'Chleby', 62.0, 1, 0, 0, [['Mouka pšeničná chlebová T1050', 500], ['Mouka žitná chlebová T960', 250], ['@P2', 0.3], ['Sůl jedlá', 20], ['Kmín celý', 6], ['Droždí čerstvé pekařské', 10]]],
    'V8' => ['Vánočka máslová 400 g', 'Sladké pečivo', 79.0, 1, 0, 0, [['@P1', 0.3], ['Máslo selské 82%', 40], ['Rozinky', 30], ['Mandle plátky', 10], ['Vejce slepičí M (1 ks ≈ 60 g)', 0.5], ['Citronová kůra sušená', 2]]],
];

function phase_catalog(): void {
    if (phase_done('catalog')) { L('catalog: hotovo driv'); return; }
    $A = new Admin();
    $ref = $A->call('GET', 'api/admin_vyrobky.php');
    if ($ref['code'] !== 200) throw new RuntimeException('GET admin_vyrobky ' . $ref['code']);
    $jed = []; foreach ($ref['json']['jednotky'] ?? [] as $j) $jed[$j['kod']] = (int) $j['id'];
    $sazba12 = null; foreach ($ref['json']['sazby'] ?? [] as $s) if ((float) $s['sazba'] === 12.0) $sazba12 = (int) $s['id'];
    $kat = []; foreach ($ref['json']['kategorie'] ?? [] as $k) $kat[mb_strtolower($k['nazev'])] = (int) $k['id'];

    $sur = [];
    $all = $A->call('GET', 'api/admin_suroviny.php');
    $byName = []; foreach (($all['json']['rows'] ?? $all['json'] ?? []) as $r) if (is_array($r) && isset($r['nazev'])) $byName[mb_strtolower($r['nazev'])] = $r;
    foreach (SUROVINY_DEF as $n => [$j, $cb, $ob]) {
        $hit = $byName[mb_strtolower($n)] ?? null;
        if ($hit && $hit['jednotka'] !== $j) L("pozor: surovina '$n' existuje s jednotkou {$hit['jednotka']} (cekal $j) — pouziju jeji");
        if (!$hit) {
            $r = $A->call('POST', 'api/admin_suroviny.php', ['nazev' => $n, 'jednotka' => $j, 'cena_baleni' => $cb, 'obsah_baleni' => $ob, 'stock_minimalni' => 0, 'stock_cilove' => 0]);
            if ($r['code'] !== 201) throw new RuntimeException("surovina $n: {$r['code']} " . substr($r['raw'], 0, 200));
            $sur[$n] = ['id' => (int) $r['json']['id'], 'jednotka' => $j, 'new' => true];
        } else $sur[$n] = ['id' => (int) $hit['id'], 'jednotka' => $hit['jednotka'], 'new' => false];
    }
    $vyr = [];
    foreach (VYROBKY_DEF as $key => [$nazev, $katn, $cena, $moq, $jp, $ss, $recept]) {
        $rows = []; $por = 0;
        foreach ($recept as [$what, $mn]) {
            if ($what[0] === '@') $rows[] = ['slozka_vyrobek_id' => $vyr[substr($what, 1)]['id'], 'mnozstvi' => $mn, 'jednotka' => 'ks', 'poradi' => $por++];
            else $rows[] = ['surovina_id' => $sur[$what]['id'], 'mnozstvi' => $mn, 'jednotka' => $sur[$what]['jednotka'], 'poradi' => $por++];
        }
        $body = ['nazev' => $nazev, 'cislo' => cfg('tag') . '-' . $key, 'jednotka_id' => $jed['ks'] ?? 1, 'sazba_dph_id' => $sazba12 ?? 1,
                 'kategorie_id' => $kat[mb_strtolower($katn)] ?? null, 'cena_bez_dph' => $cena, 'aktivni' => $jp ? 0 : 1,
                 'min_objednavka' => $moq, 'je_polotovar' => $jp, 'sleduje_sklad' => $ss, 'slozeni_polozky' => $rows];
        $r = $A->call('POST', 'api/admin_vyrobky.php', $body);
        if ($r['code'] !== 201) throw new RuntimeException("vyrobek $key: {$r['code']} " . substr($r['raw'], 0, 200));
        $id = (int) $r['json']['id'];
        $fl = q('SELECT COALESCE(je_polotovar,0) jp, COALESCE(sleduje_sklad,0) ss FROM vyrobky WHERE id=:v', ['v' => $id])[0] ?? ['jp' => -1, 'ss' => -1];
        if ($jp || $ss) $A->call('PUT', 'api/admin_vyrobky.php', ['id' => $id, 'je_polotovar' => $jp, 'sleduje_sklad' => $ss]);
        $vyr[$key] = ['id' => $id, 'nazev' => $nazev, 'cena' => $cena, 'jp' => $jp, 'ss' => $ss, 'recept' => $rows, 'flags_after_post' => ['je_polotovar' => (int) $fl['jp'], 'sleduje_sklad' => (int) $fl['ss']]];
    }
    $grp = [];
    foreach (q('SELECT id, nazev FROM cenove_skupiny WHERE aktivni = 1') as $g) $grp[$g['nazev']] = (int) $g['id'];
    $gn = 'Školní a závodní jídelny';
    if (!isset($grp[$gn])) {
        $r = $A->call('POST', 'api/admin_cenove_skupiny.php', ['nazev' => $gn, 'popis' => 'Jidelny ZS/MS, menzy, zavodni stravovani', 'globalni_sleva_pct' => 4, 'aktivni' => 1]);
        if ($r['code'] === 201) {
            $gid = (int) $r['json']['id']; $grp[$gn] = $gid;
            if (isset($kat['chleby'])) $A->call('POST', 'api/admin_cenove_skupiny.php?action=sleva', ['skupina_id' => $gid, 'kategorie_id' => $kat['chleby'], 'sleva_pct' => 10]);
            $A->call('POST', 'api/admin_cenove_skupiny.php?action=sleva', ['skupina_id' => $gid, 'vyrobek_id' => $vyr['V2']['id'], 'pevna_cena' => 2.90]);
            $rk = q1("SELECT id FROM vyrobky WHERE cislo = 'RK01' AND aktivni = 1 LIMIT 1");
            if ($rk) $A->call('POST', 'api/admin_cenove_skupiny.php?action=sleva', ['skupina_id' => $gid, 'vyrobek_id' => (int) $rk, 'pevna_cena' => 2.20]);
        } else L('pozor: nova cenova skupina: ' . $r['code']);
    }
    // povolené výrobky: viditelné v katalogu, bez receptů s nesouhlasnou jednotkou (ty testuje sonda PR_UNIT)
    $kat0 = (new Http())->req('GET', 'api/katalog.php', null, 'GET katalog.php (anon)');
    $mism = array_map('intval', array_column(q("SELECT DISTINCT vs.vyrobek_id FROM vyrobek_suroviny vs JOIN suroviny s ON s.id = vs.surovina_id WHERE vs.jednotka IS NOT NULL AND vs.jednotka <> '' AND vs.jednotka <> s.jednotka"), 'vyrobek_id'));
    $ours = array_column($vyr, 'id');
    $allowed = []; $excluded = [];
    foreach (($kat0['json']['vyrobky'] ?? []) as $v) {
        $id = (int) $v['id'];
        if (in_array($id, $mism, true)) { $excluded[] = ['id' => $id, 'nazev' => $v['nazev'], 'duvod' => 'recept: jednotka radku != jednotka suroviny']; continue; }
        $katName = mb_strtolower((string) ($v['kategorie_nazev'] ?? $v['kategorie'] ?? ''));
        $w = in_array($katName, ['pečivo', 'chleby', 'sladké pečivo'], true) ? 6 : (str_contains($katName, 'sendvi') ? 3 : 1);
        if (in_array($id, $ours, true)) $w = 7;
        $allowed[$id] = ['w' => $w, 'nazev' => $v['nazev']];
    }
    if (!$allowed) throw new RuntimeException('Katalog je prazdny — neni z ceho objednavat');
    jsave('catalog.json', ['suroviny' => $sur, 'vyrobky' => $vyr, 'groups' => $grp, 'allowed' => $allowed, 'excluded' => $excluded, 'unit_mismatch_products' => $mism]);
    Stats::save('stats-catalog.json');
    mark_done('catalog', ['allowed' => count($allowed), 'excluded' => count($excluded)]);
    L('catalog: ' . count($sur) . ' surovin, ' . count($vyr) . ' vyrobku (2 polotovary), ' . count($grp) . ' cenovych skupin, povoleno ' . count($allowed) . ' vyrobku, vyrazeno ' . count($excluded));
}

/** Hlavní fáze: odběratel + B2B účet + objednávky přes portál (a část telefonicky přes admin). */
function w_customers(int $k, int $K): void {
    $cat = jload('catalog.json'); $N = (int) cfg('customers'); $dates = cfg('dates');
    $done = []; foreach (jlines("customers-$k.jsonl") as $r) $done[$r['i']] = 1;
    $A = new Admin(); $n = 0; $prevOrder = null;
    for ($i = $k + 1; $i <= $N; $i += $K) {
        if (isset($done[$i])) continue;
        sink_guard();
        $c = gen_customer($i, $cat['groups']);
        mt_srand(crc32(cfg('run') . 'ord') + $i * 104729);
        $rec = ['i' => $i, 'cislo' => $c['cislo'], 'grp' => $c['cenova_skupina_id'], 'splatnost' => $c['splatnost_dni'], 'orders' => [], 'err' => [], 'chk' => []];
        $r = $A->call('POST', 'api/admin_odberatele.php', $c);
        if ($r['code'] !== 201 && $r['code'] >= 500) { // prázdný 500 (kolize DDL) — zkus znovu, pak dohledej v DB
            $rec['err'][] = "create 500 (retry): " . substr($r['raw'], 0, 80);
            usleep(400000); $r = $A->call('POST', 'api/admin_odberatele.php', $c);
        }
        $oid = (int) ($r['json']['id'] ?? 0);
        if (!$oid) { try { $oid = (int) q1('SELECT id FROM odberatele WHERE login_email=:e LIMIT 1', ['e' => $c['login_email']]); } catch (Throwable $e) { $oid = 0; } if ($oid) $rec['err'][] = "create {$r['code']} ale ucet vznikl (dohledano id $oid)"; }
        if (!$oid) { $rec['err'][] = "create {$r['code']}: " . substr($r['raw'], 0, 160); jappend("customers-$k.jsonl", $rec); continue; }
        $rec['id'] = $oid;
        if (mt_rand(1, 100) <= 20) {
            [$m2, $p2] = pick(MESTA);
            $rp = $A->call('POST', 'api/admin_pobocky.php', ['odberatel_id' => $oid, 'nazev' => 'Pobočka ' . pick(NAZVY), 'ulice' => pick(ULICE) . ' ' . mt_rand(1, 120), 'mesto' => mt_rand(0, 1) ? $c['mesto'] : $m2, 'psc' => $p2, 'cas_dodani' => pick(['05:30-06:30', '06:00-07:00', '07:00-08:00']), 'pokyny_pro_ridice' => pick(['zadni vchod', 'zvonit 2x', 'rampa u skladu', '']), 'vychozi' => 0, 'aktivni' => 1]);
            if ($rp['code'] >= 300) $rec['err'][] = "pobocka {$rp['code']}";
        }
        $B = new Http();
        $lg = $B->req('POST', 'api/login.php', ['email' => $c['login_email'], 'heslo' => $c['heslo']], 'POST login.php');
        $rec['login'] = $lg['code'];
        if ($lg['code'] !== 200) { $rec['err'][] = "login {$lg['code']}: " . substr($lg['raw'], 0, 120); jappend("customers-$k.jsonl", $rec); continue; }
        $me = $B->req('GET', 'api/login.php?action=me', null, 'GET login.php?action=me');
        if ((int) ($me['json']['odberatel']['id'] ?? 0) !== $oid) $rec['err'][] = 'me: jine id ' . json_encode($me['json']);
        $kat = $B->req('GET', 'api/katalog.php', null, 'GET katalog.php (b2b)');
        $km = []; foreach (($kat['json']['vyrobky'] ?? []) as $v) $km[(int) $v['id']] = $v;
        $md = $B->req('GET', 'api/mista_dodani.php', null, 'GET mista_dodani.php');
        $mista = is_array($md['json']) ? array_values(array_filter($md['json'], 'is_array')) : [];
        $defMisto = null; foreach ($mista as $m) if (!empty($m['vychozi'])) { $defMisto = (int) $m['id']; break; }
        if (!$defMisto && $mista) $defMisto = (int) $mista[0]['id'];
        if (!$defMisto) $rec['err'][] = 'zadne misto dodani';
        $pool = array_filter($cat['allowed'], fn($v, $id) => isset($km[(int) $id]), ARRAY_FILTER_USE_BOTH);
        $weights = array_map(fn($v) => $v['w'], $pool);
        $nOrd = (int) wpick([1 => 55, 2 => 30, 3 => 15]);
        $ds = $dates; shuffle($ds); $ds = array_slice($ds, 0, $nOrd);
        foreach ($weights ? $ds : [] as $d) {
            $lines = []; $nl = (int) wpick([1 => 10, 2 => 25, 3 => 30, 4 => 20, 5 => 10, 6 => 5]);
            for ($t = 0; $t < $nl * 3 && count($lines) < $nl; $t++) {
                $vid = (int) wpick($weights); if (isset($lines[$vid])) continue;
                $p = (float) $km[$vid]['cena_bez_dph']; $moq = max(1.0, (float) ($km[$vid]['min_objednavka'] ?? 1));
                $jedn = $km[$vid]['jednotka'] ?? 'ks';
                if ($jedn === 'kg') $mn = round(mt_rand(5, 50) / 10, 1);
                elseif ($p < 5) $mn = mt_rand(2, 20) * 10; elseif ($p < 15) $mn = mt_rand(2, 16) * 5;
                elseif ($p < 40) $mn = mt_rand(4, 40); elseif ($p < 100) $mn = mt_rand(2, 20); else $mn = mt_rand(1, 8);
                $lines[$vid] = ['vyrobek_id' => $vid, 'mnozstvi' => max((float) $mn, $moq)];
            }
            if (!$lines) continue;
            $misto = $defMisto; if (count($mista) > 1 && mt_rand(1, 100) <= 40) $misto = (int) pick($mista)['id'];
            $nonce = cfg('tag') . ":$i:$d:" . (count($rec['orders']) + 1); // kotva pro dohledání po prázdném 500
            $pozn = (mt_rand(1, 100) <= 30 ? pick(['Prosim rano do 6:30.', 'Zadni vchod, zvonit.', 'Dekujeme!', 'Volat pri prijezdu.']) . ' ' : '') . "[#$nonce]";
            $body = ['typ' => 'jednorazova', 'misto_dodani_id' => $misto, 'polozky' => array_values($lines), 'poznamka' => $pozn,
                     'doprava' => mt_rand(1, 100) <= 85 ? 'rozvoz' : 'vlastni', 'platba' => mt_rand(1, 100) <= 60 ? 'faktura' : 'prevod',
                     'gdpr_souhlas' => true, 'datum_dodani' => $d];
            $ro = $B->req('POST', 'api/objednavky.php', $body, 'POST objednavky.php (b2b)');
            $oidNew = (int) ($ro['json']['id'] ?? 0);
            if (!$oidNew && $ro['code'] >= 500) { // prázdný 500 — objednávka mohla vzniknout; dohledej přes nonce
                usleep(300000);
                $last = $B->req('GET', 'api/objednavky.php?last=6', null, 'GET objednavky.php?last (recover)');
                foreach (($last['json']['objednavky'] ?? $last['json'] ?? []) as $lo) if (is_array($lo) && str_contains((string) ($lo['poznamka'] ?? ''), "[#$nonce]")) { $oidNew = (int) $lo['id']; break; }
                $rec['err'][] = "order 500" . ($oidNew ? " ale vznikla (dohledano $oidNew)" : " ztracena: " . substr($ro['raw'], 0, 80));
            }
            if (!$oidNew) { if ($ro['code'] !== 201) $rec['err'][] = "order {$ro['code']}: " . substr($ro['raw'], 0, 160); continue; }
            $ord = ['id' => $oidNew, 'cislo' => $ro['json']['cislo'] ?? null, 'via' => 'b2b', 'datum' => $d, 'misto' => $misto,
                    'doprava' => $body['doprava'], 'platba' => $body['platba'], 'stav' => 'nova', 'lines' => [], 'cancelled' => false, 'edited' => false];
            $readLines = function (array $polozky) use ($km): array {
                $o = []; foreach ($polozky as $pl) if (!empty($pl['vyrobek_id'])) $o[] = ['v' => (int) $pl['vyrobek_id'], 'q' => (float) $pl['mnozstvi'], 'c' => (float) $pl['cena_bez_dph'], 'kat' => (float) ($km[(int) $pl['vyrobek_id']]['cena_bez_dph'] ?? -1)];
                return $o;
            };
            $det = $B->req('GET', 'api/objednavky.php?id=' . $ord['id'], null, 'GET objednavky.php?id (b2b)');
            $ord['lines'] = $readLines($det['json']['polozky'] ?? []);
            foreach ($ord['lines'] as $ln) {
                if (abs($ln['kat'] - $ln['c']) > 0.005) $rec['chk'][] = "cena!=katalog obj {$ord['id']} v{$ln['v']}: {$ln['c']} vs {$ln['kat']}";
                if (isset($lines[$ln['v']]) && abs($ln['q'] - (float) $lines[$ln['v']]['mnozstvi']) > 0.0005) $rec['chk'][] = "mnozstvi!=poslane obj {$ord['id']} v{$ln['v']}";
            }
            if (count($ord['lines']) !== count($lines)) $rec['chk'][] = "pocet radku obj {$ord['id']}: " . count($ord['lines']) . ' vs ' . count($lines);
            $ord['total'] = (float) ($det['json']['castka_celkem'] ?? 0);
            if (mt_rand(1, 100) <= 8) {
                $pol = array_values($lines); $pol[0]['mnozstvi'] = $pol[0]['mnozstvi'] + mt_rand(1, 10);
                $re = $B->req('PUT', 'api/objednavky.php', ['id' => $ord['id'], 'misto_dodani_id' => $misto, 'poznamka' => $body['poznamka'], 'polozky' => $pol], 'PUT objednavky.php (b2b)');
                if ($re['code'] === 200) {
                    $ord['edited'] = true;
                    $det = $B->req('GET', 'api/objednavky.php?id=' . $ord['id'], null, 'GET objednavky.php?id (b2b)');
                    $ord['lines'] = $readLines($det['json']['polozky'] ?? []);
                    $ord['total'] = (float) ($det['json']['castka_celkem'] ?? 0);
                } else $rec['err'][] = "edit {$re['code']}: " . substr($re['raw'], 0, 160);
            }
            if (mt_rand(1, 100) <= 4) {
                $rd = $B->req('DELETE', 'api/objednavky.php?id=' . $ord['id'], null, 'DELETE objednavky.php (b2b)');
                if ($rd['code'] === 200) { $ord['cancelled'] = true; $ord['stav'] = 'zrusena'; } else $rec['err'][] = "cancel {$rd['code']}";
            }
            $rec['orders'][] = $ord;
        }
        if ($prevOrder && mt_rand(1, 100) <= 3) {
            $rx = $B->req('GET', 'api/objednavky.php?id=' . $prevOrder, null, 'GET objednavky.php?id (cizi)');
            $rec['iso'] = $rx['code'];
            if ($rx['code'] === 200) $rec['chk'][] = "IZOLACE: cizi objednavka $prevOrder citelna (200)";
        }
        if ($rec['orders']) $prevOrder = $rec['orders'][0]['id'];
        $B->req('POST', 'api/logout.php', null, 'POST logout.php');
        if (mt_rand(1, 100) <= 10 && $weights) {
            $d = pick($dates); $pol = [];
            for ($t = 0; $t < 3; $t++) { $vid = (int) wpick($weights); $pol[$vid] = ['vyrobek_id' => $vid, 'mnozstvi' => max((float) mt_rand(2, 30), (float) ($km[$vid]['min_objednavka'] ?? 1))]; }
            $ra = $A->call('POST', 'api/admin_objednavky.php', ['action' => 'vytvorit', 'odberatel_id' => $oid, 'misto_dodani_id' => $defMisto, 'datum_dodani' => $d, 'polozky' => array_values($pol), 'interni_pozn' => cfg('tag') . ' telefon']);
            if ($ra['code'] === 201) {
                $ord = ['id' => (int) $ra['json']['id'], 'cislo' => $ra['json']['cislo'] ?? null, 'via' => 'admin', 'datum' => $d, 'misto' => $defMisto, 'doprava' => null, 'stav' => 'potvrzena', 'lines' => [], 'cancelled' => false, 'edited' => false];
                $det = $A->call('GET', 'api/admin_objednavky.php?id=' . $ord['id']);
                foreach (($det['json']['polozky'] ?? []) as $pl) if (!empty($pl['vyrobek_id'])) $ord['lines'][] = ['v' => (int) $pl['vyrobek_id'], 'q' => (float) $pl['mnozstvi'], 'c' => (float) $pl['cena_bez_dph'], 'kat' => (float) ($km[(int) $pl['vyrobek_id']]['cena_bez_dph'] ?? -1)];
                $ord['total'] = (float) ($det['json']['castka_celkem'] ?? 0);
                foreach ($ord['lines'] as $ln) if (abs($ln['c'] - $ln['kat']) > 0.005) $rec['chk'][] = "admin obj {$ord['id']} cena!=cenik zakaznika v{$ln['v']}: {$ln['c']} vs {$ln['kat']}";
                $rec['orders'][] = $ord;
            } else $rec['err'][] = "admin order {$ra['code']}: " . substr($ra['raw'], 0, 160);
        }
        jappend("customers-$k.jsonl", $rec);
        if (++$n % 50 === 0) { echo '[' . date('H:i:s') . "] shard $k: $n zakazniku\n"; Stats::save("stats-customers-$k.json"); }
    }
}

/** Přechody stavů přes admin PUT (bez datum_dodani — jinak 409 u objednávky s DL). */
function w_stav(int $k, int $K, string $tag, array $stavy): void {
    $done = []; foreach (jlines("$tag-$k.jsonl") as $r) $done[$r['id']] = 1;
    $A = new Admin(); $n = 0;
    foreach (all_orders() as $id => $o) {
        if ($o['cancelled'] || $id % $K !== $k || isset($done[$id])) continue;
        if ($tag === 'confirm' && $o['stav'] !== 'nova') continue;
        sink_guard();
        $res = ['id' => $id];
        foreach ($stavy as $s) {
            // PUT stav je idempotentní (nastavení téhož stavu 2× = stejný výsledek) → prázdný 500
            // sdíleného hostingu bezpečně zopakuj, ať objednávka nezůstane viset.
            for ($try = 1; $try <= 4; $try++) {
                $r = $A->call('PUT', 'api/admin_objednavky.php', ['id' => $id, 'stav' => $s], "PUT admin_objednavky.php (stav=$s)");
                if ($r['code'] === 200 || $r['code'] < 500) break;
                usleep(400000 * $try);
            }
            $res[$s] = $r['code'];
            if ($r['code'] !== 200) $res['err'][] = "$s {$r['code']}: " . substr($r['raw'], 0, 160);
        }
        jappend("$tag-$k.jsonl", $res);
        if (++$n % 250 === 0) echo '[' . date('H:i:s') . "] $tag shard $k: $n\n";
    }
}

/** Úhrady: 70 % plně, 10 % polovina, 20 % neuhrazeno. */
function w_pay(int $k, int $K): void {
    $A = new Admin(); $n = 0;
    $done = []; foreach (jlines("pay-$k.jsonl") as $r) $done[$r['id']] = 1;
    foreach (jload('fa.json')['fa'] ?? [] as $f) {
        if ($f['id'] % $K !== $k || isset($done[$f['id']])) continue;
        mt_srand($f['id'] * 13);
        $x = mt_rand(1, 100); $amt = $x <= 70 ? $f['celkem'] : ($x <= 80 ? round($f['celkem'] / 2, 2) : null);
        $res = ['id' => $f['id'], 'want' => $amt];
        if ($amt !== null) {
            // úhrada = absolutní částka → idempotentní, prázdný 500 hostingu bezpečně zopakuj
            for ($try = 1; $try <= 4; $try++) {
                $r = $A->call('PUT', 'api/admin_faktury.php', ['id' => $f['id'], 'castka_uhrazeno' => $amt], 'PUT admin_faktury.php (uhrada)');
                if ($r['code'] === 200 || $r['code'] < 500) break;
                usleep(400000 * $try);
            }
            $res['code'] = $r['code']; if ($r['code'] !== 200) $res['err'] = substr($r['raw'], 0, 200);
        }
        jappend("pay-$k.jsonl", $res);
        if (++$n % 300 === 0) echo '[' . date('H:i:s') . "] pay shard $k: $n\n";
    }
}

/** Zákaznický portál (vzorek ~6 %): faktury + stavy, vlastní doklady ano, cizí ne. */
function w_portal(int $k, int $K): void {
    $custs = array_values(array_filter(jlines('customers-*.jsonl'), fn($c) => !empty($c['id']) && !empty($c['orders'])));
    usort($custs, fn($a, $b) => $a['i'] <=> $b['i']);
    $sample = array_values(array_filter($custs, fn($c) => $c['i'] % 17 === 0));
    $fa = []; foreach (jload('fa.json')['fa'] ?? [] as $f) $fa[$f['odb']][] = $f['id'];
    $dlmap = jload('dl.json', [])['map'] ?? [];
    $groups = jload('catalog.json')['groups'];
    foreach ($sample as $idx => $c) {
        if ($idx % $K !== $k) continue;
        $B = new Http(); $cc = gen_customer($c['i'], $groups);
        $lg = $B->req('POST', 'api/login.php', ['email' => $cc['login_email'], 'heslo' => $cc['heslo']], 'POST login.php (portal)');
        $res = ['i' => $c['i'], 'odb' => $c['id'], 'login' => $lg['code']];
        if ($lg['code'] === 200) {
            $f = $B->req('GET', 'api/faktury_odberatele.php', null, 'GET faktury_odberatele.php');
            $res['faktury'] = array_map(fn($x) => ['id' => (int) $x['id'], 'stav' => $x['stav_uhrady'] ?? null], $f['json']['faktury'] ?? []);
            $own = $fa[$c['id']][0] ?? null;
            if ($own) { $p = $B->req('GET', "api/faktura.php?id=$own", null, 'GET faktura.php (vlastni)', true); $res['own_fa'] = $p['code']; }
            $other = null; foreach ($fa as $oid => $ids) if ((int) $oid !== (int) $c['id']) { $other = $ids[0]; break; }
            if ($other) { $p = $B->req('GET', "api/faktura.php?id=$other", null, 'GET faktura.php (cizi)', true); $res['other_fa'] = $p['code']; }
            $mine = array_map('strval', array_column($c['orders'], 'id'));
            $otherDl = null; foreach ($dlmap as $oid => $dlid) if (!in_array((string) $oid, $mine, true)) { $otherDl = $dlid; break; }
            if ($otherDl) { $p = $B->req('GET', "api/dodaci_list.php?dl_id=$otherDl", null, 'GET dodaci_list.php (cizi)', true); $res['other_dl'] = $p['code']; }
            $B->req('POST', 'api/logout.php', null, 'POST logout.php');
        }
        jappend("portal-$k.jsonl", $res);
    }
}
