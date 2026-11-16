<?php
/**
 * 🧾 Ceny surovin — orientační velkoobchodní ceny + výchozí sada surovin (v3.0.640).
 * Sdílí admin_suroviny_autofill_ceny.php (náhled/doplnění cen) a admin_suroviny.php (seed).
 *
 * ZÁSADA: jednotka suroviny (g/ml/ks…) se NIKDY nemění — sklad, minima i recepty jsou v ní.
 * Návrh (cena za 1 kg / l / ks) se převede do jednotky suroviny (bom_unit_factor_strict);
 * nepřevoditelné (lžíce, bal, ks↔kg…) = „nekompatibilni", zůstane ručně.
 * Testy: php scripts/test-suroviny-ceny.php
 */
require_once __DIR__ . '/_bom_lib.php';

if (!defined('SUROVINY_CENY_ZDROJ')) {
    define('SUROVINY_CENY_ZDROJ', 'Orientační velkoobchodní ceny pro pekařství v ČR (odhad 2024–2025), bez DPH — nejsou to aktuální tržní ceny.');
}

if (!function_exists('suroviny_cenove_pravidla')) {
    /** Pořadí = priorita (první shoda vyhrává). cena = Kč za 1 `jed`. */
    function suroviny_cenove_pravidla(): array {
        return [
            // === MOUKY ===
            ['match' => '/mouka.*pšen.*hladk|pšen.*mouka.*hladk|hladká.*mouka|mouka\s*t?\s*5(30|50)/iu', 'cena' => 14.00, 'jed' => 'kg', 'label' => 'Mouka pšeničná hladká T530'],
            ['match' => '/mouka.*pšen.*polohrub|polohrub.*mouka|mouka\s*t?\s*65/iu', 'cena' => 15.00, 'jed' => 'kg', 'label' => 'Mouka pšeničná polohrubá T650'],
            ['match' => '/mouka.*pšen.*hrub|hrub[áá].*mouka|mouka\s*t?\s*4[05]0/iu', 'cena' => 16.00, 'jed' => 'kg', 'label' => 'Mouka pšeničná hrubá'],
            ['match' => '/mouka.*celozrn|celozrn.*mouka/iu', 'cena' => 22.00, 'jed' => 'kg', 'label' => 'Mouka celozrnná'],
            ['match' => '/mouka.*žitn.*chleb|žitn.*chleb.*mouka|mouka\s*t?\s*1700/iu', 'cena' => 19.00, 'jed' => 'kg', 'label' => 'Mouka žitná chlebová'],
            ['match' => '/mouka.*žitn|žitn.*mouka/iu', 'cena' => 18.00, 'jed' => 'kg', 'label' => 'Mouka žitná'],
            ['match' => '/mouka.*pšen.*chleb|pšen.*mouka.*chleb|^(?!.*žitn).*(chleb.*mouka|mouka.*chleb)|mouka\s*t?\s*1[01][05]0/iu', 'cena' => 17.00, 'jed' => 'kg', 'label' => 'Mouka pšeničná chlebová'],
            ['match' => '/mouka.*špald|špald.*mouka/iu', 'cena' => 35.00, 'jed' => 'kg', 'label' => 'Mouka špaldová'],
            ['match' => '/^(?!.*(kukuřič|rýž|pohank|ovesn|špald|mandl|kokos|kaštan|cizrn|sój|bramb|tapiok|jáhl|amarant|quinoa|konopn|lněn|kamut)).*mouka/iu', 'cena' => 14.00, 'jed' => 'kg', 'label' => 'Mouka pšeničná'],

            // === TEKUTINY ===
            ['match' => '/^(pitn[áa]\s*)?voda(\s*z\s*vodovodu)?$/iu', 'cena' => 0.05, 'jed' => 'l', 'label' => 'Pitná voda'],
            ['match' => '/(rostlin|řepk|slunečnic)\S*\s+olej|olej\s+(rostlin|řepk|slunečnic)|^olej$/iu', 'cena' => 55.00, 'jed' => 'l', 'label' => 'Rostlinný olej'],
            ['match' => '/(plnotuč.*mléko|mléko\s*plnotuč)/iu', 'cena' => 28.00, 'jed' => 'l', 'label' => 'Mléko plnotučné'],
            ['match' => '/^mléko\s*polotuč|polotuč.*mléko/iu', 'cena' => 22.00, 'jed' => 'l', 'label' => 'Mléko polotučné'],
            ['match' => '/^mléko$/iu', 'cena' => 25.00, 'jed' => 'l', 'label' => 'Mléko'],
            ['match' => '/smetana.*ke šl|šlehač/iu', 'cena' => 90.00, 'jed' => 'l', 'label' => 'Smetana ke šlehání'],
            ['match' => '/smetana/iu', 'cena' => 75.00, 'jed' => 'l', 'label' => 'Smetana'],

            // === DROŽDÍ A KVAS ===
            ['match' => '/čerstv.*droždí|droždí.*čerstv/iu', 'cena' => 90.00, 'jed' => 'kg', 'label' => 'Čerstvé droždí'],
            ['match' => '/sušen.*droždí|droždí.*sušen/iu', 'cena' => 160.00, 'jed' => 'kg', 'label' => 'Sušené droždí'],
            ['match' => '/^droždí$/iu', 'cena' => 90.00, 'jed' => 'kg', 'label' => 'Droždí'],
            ['match' => '/žitn.*kvas|kvas.*žitn/iu', 'cena' => 60.00, 'jed' => 'kg', 'label' => 'Žitný kvas'],
            ['match' => '/kvas(?!nic)/iu', 'cena' => 60.00, 'jed' => 'kg', 'label' => 'Kvas'],

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
            ['match' => '/^máslo(\s|$)(?!.*(arašíd|kakao|bambuck|bylink))|sladk.*máslo|máslo\s*8[02]/iu', 'cena' => 270.00, 'jed' => 'kg', 'label' => 'Máslo'],
            ['match' => '/sádlo.*vepř|vepř.*sádlo|^sádlo$/iu', 'cena' => 90.00, 'jed' => 'kg', 'label' => 'Sádlo vepřové'],
            ['match' => '/palmov.*tuk|tuk.*palmov/iu', 'cena' => 75.00, 'jed' => 'kg', 'label' => 'Palmový tuk'],
            ['match' => '/margarí|margarin/iu', 'cena' => 65.00, 'jed' => 'kg', 'label' => 'Margarín'],

            // === SEMENA, OŘECHY ===
            ['match' => '/^mák$/iu', 'cena' => 180.00, 'jed' => 'kg', 'label' => 'Mák'],
            ['match' => '/^sezam$|sezamov/iu', 'cena' => 150.00, 'jed' => 'kg', 'label' => 'Sezam'],
            ['match' => '/slunečnic.*loupan|loupan.*slunečnic/iu', 'cena' => 110.00, 'jed' => 'kg', 'label' => 'Slunečnice loupaná'],
            ['match' => '/slunečnic/iu', 'cena' => 90.00, 'jed' => 'kg', 'label' => 'Slunečnice'],
            ['match' => '/dýňov.*sem|tykv.*sem/iu', 'cena' => 200.00, 'jed' => 'kg', 'label' => 'Dýňová semínka'],
            ['match' => '/lněn|lnu\b|^len\b/iu', 'cena' => 80.00, 'jed' => 'kg', 'label' => 'Lněná semínka'],
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
            ['match' => '/^laktóz/iu', 'cena' => 80.00, 'jed' => 'kg', 'label' => 'Laktóza'],
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
            ['match' => '/^pikant\b|směs.*pikant/iu', 'cena' => 95.00, 'jed' => 'kg', 'label' => 'Směs Pikant'],
            ['match' => '/fénix|fenix/iu', 'cena' => 105.00, 'jed' => 'kg', 'label' => 'Směs Fénix'],
            ['match' => '/vegipan/iu', 'cena' => 110.00, 'jed' => 'kg', 'label' => 'Vegipan'],
            ['match' => '/victor/iu', 'cena' => 115.00, 'jed' => 'kg', 'label' => 'Směs Victor'],
            ['match' => '/(pekař|chleb|pečiv).*směs|směs.*(na|pro)\s+(chléb|pečivo|pečení|rohlík|bagety)/iu', 'cena' => 100.00, 'jed' => 'kg', 'label' => 'Pekařská směs'],
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

    function suroviny_najdi_cenu(string $nazev): ?array {
        foreach (suroviny_cenove_pravidla() as $r) {
            if (preg_match($r['match'], $nazev)) return $r;
        }
        return null;
    }

    /**
     * Návrh ceny v JEDNOTCE SUROVINY. $s = řádek suroviny (jednotka, cena_baleni, obsah_baleni).
     * → stav: doplnit | ma_cenu | beze_zmeny | nekompatibilni | nerozpoznano
     */
    function suroviny_cena_navrh(array $s, ?array $hit): array {
        $out = ['stav' => 'nerozpoznano', 'duvod' => '', 'cena_baleni' => null, 'obsah_baleni' => null,
                'cena_za_jed_akt' => null, 'cena_za_jed_navrh' => null, 'jed_navrh' => $hit['jed'] ?? null];
        if (!$hit) return $out;
        $jed = trim((string) ($s['jednotka'] ?? ''));
        $f = bom_unit_factor_strict($jed, $hit['jed']);
        if ($f === null) {
            $out['stav'] = 'nekompatibilni';
            $out['duvod'] = 'Surovina je vedena v „' . ($jed !== '' ? $jed : '—') . '", návrh je za 1 ' . $hit['jed'] . ' — nelze převést';
            return $out;
        }
        $perU = (float) $hit['cena'] * $f;                       // cena za 1 jednotku suroviny
        $obAkt = (float) ($s['obsah_baleni'] ?? 0);
        if ($obAkt > 0 && round($perU * $obAkt, 2) >= 1.00) {    // zachovej balení uživatele, když cena nezaokrouhlí na nulu
            $out['obsah_baleni'] = $obAkt;
            $out['cena_baleni'] = round($perU * $obAkt, 2);
        } else {
            $out['obsah_baleni'] = round(1 / $f, 3);               // g→1000, kg→1, ml→1000, ks→1
            $out['cena_baleni'] = round((float) $hit['cena'], 2);
        }
        $out['cena_za_jed_navrh'] = round((float) $hit['cena'], 4);  // za 1 jed_navrh (kg/l/ks)
        $cb = (float) ($s['cena_baleni'] ?? 0);
        $hasPrice = $cb > 0 && $obAkt > 0;
        if ($hasPrice) {
            $akt = ($cb / $obAkt) / $f;                             // aktuální cena za 1 jed_navrh
            $out['cena_za_jed_akt'] = round($akt, 4);
            $out['stav'] = abs($akt - (float) $hit['cena']) < 0.005 ? 'beze_zmeny' : 'ma_cenu';
        } else {
            $out['stav'] = 'doplnit';
        }
        return $out;
    }

    /**
     * Smí se doplnit ceny v Kč? Po trvalém přepočtu ceníku (mena_config.zakladni ≠ CZK) jsou uložené
     * ceny v cizí měně → orientační Kč ceny by je rozbily → 'blok'.
     */
    function suroviny_mena_stav(PDO $pdo): array {
        try {
            require_once __DIR__ . '/_mena_lib.php';
            $c = mena_config_load($pdo);
            if ($c['zakladni'] !== 'CZK') return ['kod' => $c['kod'], 'db_kod' => $c['zakladni'], 'rezim' => 'blok'];
            return ['kod' => $c['kod'], 'db_kod' => 'CZK', 'rezim' => 'ok'];
        } catch (Throwable $e) { return ['kod' => 'CZK', 'db_kod' => 'CZK', 'rezim' => 'ok']; }
    }

    /** Název pro porovnání (bez diakritiky, malá písmena, jedna mezera) — explicitní mapa, ne iconv. */
    function suroviny_normalizuj_nazev(string $s): string {
        $s = mb_strtolower($s, 'UTF-8');
        $s = strtr($s, ['á'=>'a','č'=>'c','ď'=>'d','é'=>'e','ě'=>'e','í'=>'i','ň'=>'n','ó'=>'o','ř'=>'r','š'=>'s','ť'=>'t',
                        'ú'=>'u','ů'=>'u','ý'=>'y','ž'=>'z','ľ'=>'l','ĺ'=>'l','ô'=>'o','ä'=>'a','ŕ'=>'r']);
        return trim(preg_replace('/\s+/u', ' ', $s));
    }

    /** Výchozí pekařské suroviny [název, jednotka, alergen] — bez cen (ty doplní „Doplnit ceny"). */
    function suroviny_vychozi_seznam(): array {
        return [
            // Mouky
            ['Mouka pšeničná hladká', 'g', 'lepek'],
            ['Mouka pšeničná polohrubá', 'g', 'lepek'],
            ['Mouka pšeničná hrubá', 'g', 'lepek'],
            ['Mouka chlebová T1050', 'g', 'lepek'],
            ['Mouka chlebová T1150', 'g', 'lepek'],
            ['Mouka žitná chlebová T960', 'g', 'lepek'],
            ['Mouka špaldová', 'g', 'lepek'],
            ['Mouka kukuřičná', 'g', null],
            ['Mouka ovesná', 'g', 'lepek'],
            ['Mouka pohanková', 'g', null],
            ['Mouka rýžová', 'g', null],
            ['Krupice pšeničná', 'g', 'lepek'],
            ['Strouhanka', 'g', 'lepek'],
            // Sypké přídavky
            ['Otruby pšeničné', 'g', 'lepek'],
            ['Vločky ovesné', 'g', 'lepek'],
            ['Lněné semínko', 'g', null],
            ['Slunečnicová semínka', 'g', null],
            ['Dýňová semínka', 'g', null],
            ['Sezamová semínka', 'g', 'sezam'],
            ['Mák mletý', 'g', null],
            ['Mák celý', 'g', null],
            ['Chia semínka', 'g', null],
            // Cukry
            ['Cukr krystal', 'g', null],
            ['Cukr moučka', 'g', null],
            ['Cukr hnědý třtinový', 'g', null],
            ['Cukr vanilkový', 'g', null],
            ['Med', 'g', null],
            ['Melasa', 'g', null],
            // Tuky
            ['Máslo', 'g', 'mléko'],
            ['Sádlo', 'g', null],
            ['Margarín', 'g', 'mléko'],
            ['Olej slunečnicový', 'ml', null],
            ['Olej řepkový', 'ml', null],
            ['Olej olivový', 'ml', null],
            // Vejce a mléčné
            ['Vejce', 'ks', 'vejce'],
            ['Žloutek', 'ks', 'vejce'],
            ['Bílek', 'ks', 'vejce'],
            ['Mléko plnotučné', 'ml', 'mléko'],
            ['Smetana ke šlehání 33%', 'ml', 'mléko'],
            ['Smetana 12%', 'ml', 'mléko'],
            ['Tvaroh měkký', 'g', 'mléko'],
            ['Mascarpone', 'g', 'mléko'],
            ['Zakysaná smetana', 'g', 'mléko'],
            ['Jogurt bílý', 'g', 'mléko'],
            // Kvasnice / kypřící
            ['Droždí čerstvé', 'g', null],
            ['Droždí sušené', 'g', null],
            ['Prášek do pečiva', 'g', null],
            ['Jedlá soda', 'g', null],
            ['Pšeničný kvas', 'g', 'lepek'],
            ['Žitný kvas', 'g', 'lepek'],
            // Sůl, koření, aroma
            ['Sůl', 'g', null],
            ['Skořice mletá', 'g', null],
            ['Vanilkový extrakt', 'ml', null],
            ['Kakao', 'g', null],
            ['Čokoláda hořká 70%', 'g', 'sója'],
            ['Čokoláda mléčná', 'g', 'mléko, sója'],
            ['Kokos strouhaný', 'g', null],
            // Ořechy
            ['Vlašské ořechy', 'g', 'ořechy'],
            ['Lískové ořechy', 'g', 'ořechy'],
            ['Mandle', 'g', 'ořechy'],
            ['Kešu', 'g', 'ořechy'],
            // Sušené ovoce
            ['Rozinky', 'g', null],
            ['Brusinky sušené', 'g', null],
            ['Datle', 'g', null],
            ['Sušené meruňky', 'g', 'oxid siřičitý / siřičitany'],
            // Náplně
            ['Marmeláda meruňková', 'g', null],
            ['Povidla švestková', 'g', null],
            // Citrus
            ['Citronová šťáva', 'ml', null],
            ['Citronová kůra', 'g', null],
            ['Pomerančová kůra', 'g', null],
        ];
    }
}
