<?php
/**
 * 💱 Měna prodeje + měna dokladů (v3.0.640) + PLATBA V KČ A EUR SOUČASNĚ (v3.0.643, balík 0).
 *
 * ══════════════════════════════════════════════════════════════════════════════════════════
 *  1. GLOBÁLNÍ KONFIGURACE  nastaveni.mena_config_json  (spravuje api/admin_mena.php)
 * ══════════════════════════════════════════════════════════════════════════════════════════
 *   kod        — cílová měna prodeje (CZK/EUR/…)                       [legacy, v3.0.640]
 *   kurz       — kolik Kč za 1 jednotku `kod` (ČNB konvence)
 *   zobrazeni  — 'kc' | 'mena' (prodej zobrazený v cílové měně)
 *   zakladni   — v jaké měně jsou ULOŽENÉ částky v DB. 'CZK' dokud neproběhl trvalý přepočet
 *                ceníku; po přepočtu = `kod` (pak se už nedělí kurzem → žádný dvojí přepočet).
 *   platba     — 🆕 v3.0.643 blok „platba v Kč i v cizí měně" (normalizuje mena_platba_norm):
 *       aktivni             bool   — VYPNUTO = chování přesně jako v3.0.642 (vše přes legacy kod/zobrazeni)
 *       meny                ['EUR'] — přijímané CIZÍ měny (Kč se přijímá vždy)
 *       prijimane           ['CZK','EUR'] — odvozené (Kč + meny), jen pro čtení
 *       vychozi             'CZK'  — výchozí měna platby (když zákazník/prodavač nic nezvolí)
 *       prirazka_pct        {EUR: 0} — přirážka ke kurzu v % (kurz_prodej = kurz_cnb / (1 + p/100))
 *       max_stari_kurzu_dni 3      — starší kurz ČNB = platbu v té měně odmítnout
 *       terminal_meny       ['CZK','EUR'] — v čem umí účtovat platební terminál (karta)
 *       kanaly              ['pos','stul','b2b','web','qr'] — kde se smí volit měna platby
 *       vraceni             'mena_platby' | 'czk' | 'volba' — v čem se vrací hotovost (drobné)
 *       kurz_zdroj          'cnb' | 'rucni'  — kurz ČNB (denně cron + líně) nebo vlastní
 *       kurzy_rucni         {EUR: 25.0} — vlastní kurz (Kč za 1 jednotku) při kurz_zdroj='rucni'
 *       iban_eur            ''     — EUR účet pro QR platbu převodem (bez něj QR v EUR NEVZNIKNE)
 *     Pravidla: aktivni jde jen při zakladni='CZK' (trvalý přepočet ceníku a platby ve více
 *     měnách se vylučují — admin_mena.php vrací 409). Při aktivni je legacy zobrazeni vždy 'kc'
 *     (ceník a zobrazení v Kč, měna se volí u každé platby); legacy zobrazeni='mena' se při
 *     zapnutí přeloží na platba.vychozi = kod.
 *
 * ══════════════════════════════════════════════════════════════════════════════════════════
 *  2. DOKLADY — snapshot měny (objednavky | faktury | dodaci_listy | restaurant_pos_ucty)
 * ══════════════════════════════════════════════════════════════════════════════════════════
 *   mena             — měna dokladu (v ní zákazník platí, v ní je PDF/účtenka/e-mail/brána/vratka)
 *   mena_kurz        — kurz ČNB (Kč za 1 jednotku) → rekapitulace DPH v Kč (§29/§38 ZDPH), export
 *   mena_kurz_prodej — prodejní kurz s přirážkou = mena_kurz / (1 + p/100); NULL = mena_kurz.
 *                      Převod uložených Kč → měna dokladu jde VŽDY přes něj (k_doklad = 1/kurz_prodej).
 *   mena_zaklad      — měna uložených částek (CZK; restaurant_pos_ucty sloupec nemá = vždy CZK)
 *   castka_mena      — (objednavky/faktury/dodaci_listy) AUTORITATIVNÍ celkem v měně dokladu
 *                      = mena_doc_castka()['celkem']; NULL = ještě nespočteno → volej mena_doc_castka.
 *   Uložené částky (castka_*, cena_bez_dph, jednotkova_cena) zůstávají VŽDY v Kč.
 *
 * ══════════════════════════════════════════════════════════════════════════════════════════
 *  3. SMLOUVA PRO BALÍKY A (pokladny) / B (online) / C (doklady) — jen tyhle funkce volat:
 * ══════════════════════════════════════════════════════════════════════════════════════════
 *  mena_ensure_schema(PDO, bool $force=false): void
 *      Idempotentní migrace balíku 0. Běží z apply_full_schema; balík, který si SÁM zakládá
 *      tabulku (restaurant_pos_*), ji zavolá s $force=true hned po svém CREATE TABLE.
 *      NIKDY ne uvnitř transakce (DDL = implicitní commit).
 *
 *  mena_kurz_dne(PDO, string $mena, ?string $datum=null, bool $stahnout=true): array
 *      → {kurz (Kč/1 jedn.), datum (Y-m-d vyhlášení), zdroj 'cnb'|'rucni'|'pevny', stari_dni}
 *      cnb: poslední kurz ≤ $datum z tabulky mena_kurzy; chybí-li dnešní, líně stáhne ČNB
 *      (max 1 pokus / 30 min); výpadek ČNB = poslední známý kurz + jeho stáří.
 *      Bez jakéhokoli kurzu → MenaException('kurz_chybi', 503).
 *
 *  mena_volba_platby(PDO, ?string $volba, string $kanal): array
 *      → {mena, mena_kurz, mena_kurz_prodej, mena_zaklad:'CZK', kurz_datum, kurz_zdroj,
 *         kurz_stari_dni, prirazka_pct, symbol}
 *      $kanal ∈ pos|stul|b2b|web|qr (podléhá platba.kanaly) nebo 'admin' (ruční doklady, vždy povolen).
 *      $volba null/'' = výchozí (platba.vychozi; v nepovoleném kanálu Kč).
 *      platba.aktivni=false → legacy měna (mena_pro_novy_doklad), mena_kurz_prodej = null;
 *        volba jiné měny než legacy → MenaException('platba_vypnuta').
 *      MenaException kódy: platba_vypnuta, mena_nepovolena, kanal_nepovolen, kanal_neznamy,
 *        cenik_prepocten (zakladni≠CZK), kurz_chybi (503), kurz_stary (503).
 *      VOLAT PŘED beginTransaction (může líně stahovat kurz ze sítě).
 *
 *  mena_nastav_doklad(PDO, string $tab, int $id, array $m, bool $prepsat=false): bool
 *      Zapíše snapshot $m (výstup mena_volba_platby) na doklad. Bez $prepsat jen když doklad
 *      snapshot ještě nemá (mena IS NULL). $prepsat=true: objednávka jen nezaplacená
 *      (pay_status≠paid, stav≠zaplaceno), bez DL, bez faktury, bez přijaté platby v ledgeru;
 *      restaurant_pos_ucty jen nezaplacený účet. Jinak MenaException('doklad_zamceny', 409).
 *      faktury/dodaci_listy přepsat NELZE. castka_mena NEPOČÍTÁ → po položkách mena_ulozit_castku.
 *      Starý mena_snapshot_doklad($pdo,$t,$id) = wrapper s legacy měnou (beze změny chování).
 *
 *  mena_doklad_ro(PDO, array $row): array  (+ mena_doklad = s lazy snapshotem)
 *      → {mena, mena_kurz, mena_kurz_prodej, mena_zaklad, symbol, k_doklad, k_czk}
 *      k_doklad = 1/(mena_kurz_prodej ?: mena_kurz) (uložené Kč → měna dokladu), k_czk = Kč.
 *
 *  mena_doklad_polozky(array $pol, array $m, bool $vyrovnat_nulu=true): array
 *      $pol = [[mnozstvi, cena_bez_dph (uložená), sazba], …]; počítá jako účetní programy
 *      (cena za kus na 2 místa, řádek, DPH po řádcích). Vrací {polozky:[{cena,zaklad,dph}],
 *      rozpis, rozpis_czk (= rozpis × kurz ČNB), rozpis_czk_cnb (alias), bez, dph, celkem,
 *      vyrovnano (posunutý zbytek v měně dokladu, 0 = nic)}.
 *      Vyrovnání na nulu: Σ(mn × cena × (1+sazba)) v Kč < 0,005 (100% voucher/sleva) a v cizí
 *      měně vyjde ±cent → zbytek se přičte k POSLEDNÍMU zápornému řádku → celkem = 0,00.
 *
 *  mena_doc_castka(PDO, string $typ, int $id, ?array $m=null): array
 *      → {mena, celkem, celkem_minor (centy, int), kurz, kurz_prodej, celkem_czk, symbol}
 *      JEDINÝ zdroj částky pro bránu, QR, e-mail, uzávěrku. $typ = objednavky | faktury |
 *      dodaci_listy | restaurant_pos_ucty. $m = náhled v jiné měně (quote před snapshotem).
 *      Účet stolu bez snapshotu = CZK (nikdy globál). Položky účtu viz mena_ucet_polozky.
 *
 *  mena_ucet_polozky(PDO, int $ucetId): array  — [[mn, round(jedn_cena/(1+s/100), 2), s]] (2 místa = jako uložený prodejní doklad)
 *      s = COALESCE(restaurant_pos_polozky.sazba_dph, 21). Stejný vzorec MUSÍ použít
 *      pos_ucet_create_sale pro objednavky_polozky, jinak se EUR doklad o cent rozejde s platbou.
 *
 *  mena_ulozit_castku(PDO, string $typ, int $id): float
 *      Zapíše castka_mena (objednavky|faktury|dodaci_listy). Volat po KAŽDÉ změně položek.
 *
 *  mena_platba_zapis(PDO, array $row): int  — INSERT do ledgeru platby_doklad (v transakci volajícího)
 *      povinné: zdroj (quick|stul|pickup|vratka|vymena|qr|online|faktura), metoda (hotovost|karta|
 *      qr|online|poukaz|prevod), mena, castka_mena (záporná = výdej/vratka), kurz (ČNB, CZK=1)
 *      volitelné: objednavka_id, ucet_id, castka_czk (default round(castka_mena × kurz, 2)),
 *      prijato_mena, vraceno_mena, pokladna, uzivatel, idem (klíč idempotence — opakovaný zápis
 *      se stejným idem vrátí id původního řádku; webhooky/dvojklik).
 *
 *  mena_platebni_info(PDO, string $kanal): array — pro front-endy (POS CFG, B2B, pay/):
 *      {aktivni, vychozi, prijimane, terminal_meny, vraceni, meny:{KOD:{symbol, kurz, kurz_prodej,
 *       datum, stari_dni, ok, chyba}}}
 *  mena_iban_eur(PDO): string — EUR IBAN pro QR (platba.iban_eur → payment_methods prevod.ucet_iban_eur)
 *  mena_fmt(float, string $mena): string — „1 234,50 Kč" / „1 234,50 €"
 *  MenaException — ->kod (strojový), ->http (400/404/409/503), getMessage() = česká hláška pro UI
 */

if (!defined('MENA_PODPOROVANE')) {
    define('MENA_PODPOROVANE', ['CZK' => 'Kč', 'EUR' => '€', 'USD' => '$', 'GBP' => '£', 'PLN' => 'zł', 'HUF' => 'Ft', 'CHF' => 'CHF']);
}
if (!defined('MENA_KANALY')) {
    define('MENA_KANALY', ['pos', 'stul', 'b2b', 'web', 'qr']);
}
if (!defined('MENA_CNB_URL')) {
    define('MENA_CNB_URL', 'https://www.cnb.cz/cs/financni-trhy/devizovy-trh/kurzy-devizoveho-trhu/kurzy-devizoveho-trhu/denni_kurz.txt');
}

if (!class_exists('MenaException')) {
    /** Chyba volby měny/kurzu — kod = strojový, http = doporučený status, message = česky pro UI. */
    class MenaException extends RuntimeException {
        public string $kod;
        public int $http;
        public function __construct(string $kod, string $zprava, int $http = 400) {
            parent::__construct($zprava);
            $this->kod = $kod;
            $this->http = $http;
        }
    }
}

if (!function_exists('mena_config_load')) {
    function mena_config_load(PDO $pdo): array {
        $def = ['kod' => 'CZK', 'kurz' => 1.0, 'zobrazeni' => 'kc', 'dual_doklady' => false, 'kurz_zdroj' => 'rucni', 'zakladni' => 'CZK'];
        try {
            $st = $pdo->prepare("SELECT hodnota FROM nastaveni WHERE klic = 'mena_config_json'");
            $st->execute();
            $raw = $st->fetchColumn();
            $cfg = $raw ? json_decode($raw, true) : null;
            if (!is_array($cfg)) return mena_config_finish($def);
            $out = array_merge($def, $cfg);
            $out['kod']  = strtoupper((string) $out['kod']);
            if (!isset(MENA_PODPOROVANE[$out['kod']])) $out['kod'] = 'CZK';
            $out['kurz'] = max(0.0001, (float) $out['kurz']);
            $out['zobrazeni'] = $out['zobrazeni'] === 'mena' ? 'mena' : 'kc';
            $out['dual_doklady'] = !empty($out['dual_doklady']);
            $out['kurz_zdroj'] = ($out['kurz_zdroj'] ?? 'rucni') === 'cnb' ? 'cnb' : 'rucni';
            $out['zakladni'] = strtoupper((string) ($out['zakladni'] ?? 'CZK'));
            if (!isset(MENA_PODPOROVANE[$out['zakladni']])) $out['zakladni'] = 'CZK';
            // Po trvalém přepočtu je ceník v cílové měně → prodej se v ní i zobrazuje.
            if ($out['zakladni'] !== 'CZK') { $out['kod'] = $out['zakladni']; $out['zobrazeni'] = 'mena'; }
            if ($out['kod'] === 'CZK') { $out['kurz'] = 1.0; $out['zobrazeni'] = 'kc'; }
            return mena_config_finish($out);
        } catch (Throwable $e) { return mena_config_finish($def); }
    }

    /** Doplní odvozená pole: symbol + delitel (čím dělit uloženou částku pro zobrazení) + platba{}. */
    function mena_config_finish(array $c): array {
        $c['platba'] = mena_platba_norm(is_array($c['platba'] ?? null) ? $c['platba'] : null, $c);
        // Platby ve více měnách: ceník i zobrazení v Kč, měna se volí u každé platby
        if ($c['platba']['aktivni']) $c['zobrazeni'] = 'kc';
        $c['symbol']  = MENA_PODPOROVANE[$c['kod']] ?? $c['kod'];
        $zobrazujeMenu = $c['zobrazeni'] === 'mena' && $c['kod'] !== 'CZK';
        $c['delitel'] = ($zobrazujeMenu && $c['zakladni'] === 'CZK') ? (float) $c['kurz'] : 1.0;
        return $c;
    }

    /**
     * 🆕 v3.0.643 — normalizace bloku platba{} (výchozí hodnoty = „vypnuto, Kč + EUR připraveno").
     * $legacy = zbytek configu (kod/zobrazeni/zakladni/kurz) kvůli překladu starého režimu.
     */
    function mena_platba_norm(?array $p, array $legacy = []): array {
        $zakladni = strtoupper((string) ($legacy['zakladni'] ?? 'CZK'));
        $legKod = strtoupper((string) ($legacy['kod'] ?? 'CZK'));
        $nove = $p === null;
        $p = $p ?? [];
        $cizi = fn($k) => is_string($k) && strtoupper($k) !== 'CZK' && isset(MENA_PODPOROVANE[strtoupper($k)]);

        $meny = array_values(array_unique(array_map('strtoupper', array_filter((array) ($p['meny'] ?? ['EUR']), $cizi))));
        if (!$meny) $meny = ['EUR'];
        $prijimane = array_merge(['CZK'], $meny);

        // Legacy „prodávat v cílové měně" → výchozí měna platby (jen když blok platba ještě neexistuje)
        $vychozi = strtoupper((string) ($p['vychozi'] ?? (($nove && ($legacy['zobrazeni'] ?? 'kc') === 'mena' && $zakladni === 'CZK') ? $legKod : 'CZK')));
        if (!in_array($vychozi, $prijimane, true)) $vychozi = 'CZK';

        $prir = [];
        foreach ($meny as $m) {
            $v = (float) (((array) ($p['prirazka_pct'] ?? []))[$m] ?? 0);
            $prir[$m] = round(min(20.0, max(0.0, $v)), 2);
        }
        $ruc = [];
        foreach ($meny as $m) {
            $v = (float) (((array) ($p['kurzy_rucni'] ?? []))[$m] ?? 0);
            if ($v <= 0 && $m === $legKod && (float) ($legacy['kurz'] ?? 0) > 1.0001) $v = (float) $legacy['kurz'];
            $ruc[$m] = $v > 0 ? round($v, 6) : null;
        }
        $term = array_values(array_intersect(array_map('strtoupper', (array) ($p['terminal_meny'] ?? $prijimane)), $prijimane));
        if (!in_array('CZK', $term, true)) array_unshift($term, 'CZK');
        $kanaly = array_values(array_intersect((array) ($p['kanaly'] ?? MENA_KANALY), MENA_KANALY));
        $vraceni = in_array($p['vraceni'] ?? '', ['mena_platby', 'czk', 'volba'], true) ? $p['vraceni'] : 'mena_platby';
        $iban = strtoupper(preg_replace('/\s+/', '', (string) ($p['iban_eur'] ?? '')));
        if ($iban !== '' && !preg_match('/^[A-Z]{2}\d{2}[A-Z0-9]{8,30}$/', $iban)) $iban = '';

        return [
            'aktivni'             => !empty($p['aktivni']) && $zakladni === 'CZK',
            'meny'                => $meny,
            'prijimane'           => $prijimane,
            'vychozi'             => $vychozi,
            'prirazka_pct'        => $prir,
            'max_stari_kurzu_dni' => max(1, min(14, (int) ($p['max_stari_kurzu_dni'] ?? 3))),
            'terminal_meny'       => $term,
            'kanaly'              => $kanaly,
            'vraceni'             => $vraceni,
            'kurz_zdroj'          => ($p['kurz_zdroj'] ?? 'cnb') === 'rucni' ? 'rucni' : 'cnb',
            'kurzy_rucni'         => $ruc,
            'iban_eur'            => $iban,
        ];
    }

    /**
     * Měna, ve které se má vystavit NOVÝ doklad bez explicitní volby (= jak se právě prodává).
     * Při platba.aktivni = výchozí měna platby (kurz jen z DB, bez sítě; bez platného kurzu → Kč).
     */
    function mena_pro_novy_doklad(PDO $pdo): array {
        $c = mena_config_load($pdo);
        if ($c['platba']['aktivni']) {
            $v = $c['platba']['vychozi'];
            if ($v !== 'CZK') {
                try { return mena_volba_platby_cfg($pdo, $c, $v, 'admin', false); }
                catch (Throwable $e) { /* bez kurzu → Kč */ }
            }
            return ['mena' => 'CZK', 'mena_kurz' => 1.0, 'mena_zaklad' => 'CZK'];
        }
        if ($c['zobrazeni'] === 'mena' && $c['kod'] !== 'CZK') {
            return ['mena' => $c['kod'], 'mena_kurz' => (float) $c['kurz'], 'mena_zaklad' => $c['zakladni']];
        }
        return ['mena' => 'CZK', 'mena_kurz' => 1.0, 'mena_zaklad' => 'CZK'];
    }

    /** Zapíše snapshot měny na doklad, pokud ještě žádný nemá. Tabulky: faktury|objednavky|dodaci_listy.
     *  (legacy wrapper — měna = mena_pro_novy_doklad; nikdy nehází) */
    function mena_snapshot_doklad(PDO $pdo, string $tabulka, int $id): void {
        if (!in_array($tabulka, ['faktury', 'objednavky', 'dodaci_listy'], true) || $id <= 0) return;
        try {
            mena_nastav_doklad($pdo, $tabulka, $id, mena_pro_novy_doklad($pdo));
        } catch (Throwable $e) { error_log("mena_snapshot_doklad $tabulka#$id: " . $e->getMessage()); }
    }

    /**
     * 🆕 v3.0.643 — snapshot měny $m (výstup mena_volba_platby) na doklad. Viz smlouva v hlavičce.
     * @return bool true = zapsáno, false = doklad už snapshot má (bez $prepsat) / neexistuje
     */
    function mena_nastav_doklad(PDO $pdo, string $tab, int $id, array $m, bool $prepsat = false): bool {
        if (!in_array($tab, ['faktury', 'objednavky', 'dodaci_listy', 'restaurant_pos_ucty'], true) || $id <= 0) return false;
        $mena = strtoupper((string) ($m['mena'] ?? 'CZK'));
        if (!isset(MENA_PODPOROVANE[$mena])) throw new MenaException('mena_nepovolena', "Nepodporovaná měna $mena");
        $kurz = $mena === 'CZK' ? 1.0 : (float) ($m['mena_kurz'] ?? 0);
        if ($kurz <= 0) throw new MenaException('kurz_chybi', "Chybí kurz pro $mena", 503);
        $prodej = isset($m['mena_kurz_prodej']) && (float) $m['mena_kurz_prodej'] > 0 ? (float) $m['mena_kurz_prodej'] : null;
        if ($mena === 'CZK' && $prodej !== null) $prodej = 1.0;
        $zaklad = strtoupper((string) (($m['mena_zaklad'] ?? '') ?: 'CZK'));

        if ($prepsat) {
            // stejná měna i kurzy jako už na dokladu → nic se nemění, zámek netřeba (např. výdej v měně objednávky)
            $kpCol = ($tab === 'restaurant_pos_ucty' || mena_ma_sloupec($pdo, $tab, 'mena_kurz_prodej')) ? ', mena_kurz_prodej' : '';
            $cur = $pdo->prepare("SELECT mena, mena_kurz$kpCol FROM `$tab` WHERE id = ?");
            $cur->execute([$id]);
            $c = $cur->fetch(PDO::FETCH_ASSOC);
            if ($c && strtoupper((string) $c['mena']) === $mena && abs((float) $c['mena_kurz'] - $kurz) < 1e-6
                && abs((float) ($c['mena_kurz_prodej'] ?? 0) - (float) ($prodej ?? 0)) < 1e-6) return false;
            mena_prepis_povolen($pdo, $tab, $id);
        }

        $set = ['mena = ?', 'mena_kurz = ?']; $par = [$mena, $kurz];
        if ($tab !== 'restaurant_pos_ucty') { $set[] = 'mena_zaklad = ?'; $par[] = $zaklad; }
        // mena_kurz_prodej jen když ho volba nese (legacy snapshot zapisuje přesně jako v3.0.642)
        if ($prodej !== null || $prepsat) { $set[] = 'mena_kurz_prodej = ?'; $par[] = $prodej; }
        $par[] = $id;
        $st = $pdo->prepare("UPDATE `$tab` SET " . implode(', ', $set) . " WHERE id = ?" . ($prepsat ? '' : ' AND mena IS NULL'));
        $st->execute($par);
        return $st->rowCount() > 0;
    }

    /**
     * 🆕 v3.0.643 — faktura DĚDÍ měnu a kurz ze svých dodacích listů (= z objednávek), ne z globálu
     * (EUR objednávka → EUR faktura i po přepnutí výchozí měny). DL v různých měnách na jedné faktuře
     * → MenaException 409 (hromadná fakturace je předem dělí podle měny). Bez snapshotu → jako dřív.
     */
    function mena_zdedit_z_dl(PDO $pdo, int $faId, array $dlIds): void {
        $dlIds = array_values(array_filter(array_map('intval', $dlIds)));
        $rows = [];
        if ($dlIds) {
            $kp = fn(string $t, string $a) => mena_ma_sloupec($pdo, $t, 'mena_kurz_prodej') ? "$a.mena_kurz_prodej" : 'NULL';
            $in = implode(',', $dlIds);
            $rows = $pdo->query("SELECT IF(dl.objednavka_id IS NULL, dl.mena, o.mena) AS mena,
                                        IF(dl.objednavka_id IS NULL, dl.mena_kurz, o.mena_kurz) AS mena_kurz,
                                        IF(dl.objednavka_id IS NULL, " . $kp('dodaci_listy', 'dl') . ", " . $kp('objednavky', 'o') . ") AS mena_kurz_prodej,
                                        IF(dl.objednavka_id IS NULL, dl.mena_zaklad, o.mena_zaklad) AS mena_zaklad
                                 FROM dodaci_listy dl LEFT JOIN objednavky o ON o.id = dl.objednavka_id
                                 WHERE dl.id IN ($in) ORDER BY dl.id")->fetchAll(PDO::FETCH_ASSOC);
            $rows = array_values(array_filter($rows, fn($r) => !empty($r['mena'])));
        }
        $meny = array_values(array_unique(array_map(fn($r) => strtoupper((string) $r['mena']), $rows)));
        if (count($meny) > 1) {
            throw new MenaException('meny_smisene', 'Dodací listy jsou v různých měnách (' . implode(', ', $meny) . ') — vystavte fakturu zvlášť pro každou měnu.', 409);
        }
        if (!$rows) { mena_snapshot_doklad($pdo, 'faktury', $faId); return; }
        // cizí měna: faktura má JEDEN kurz → DL s různými kurzy by se přepočetly kurzem prvního (jiné EUR částky než objednávky)
        if (strtoupper((string) $rows[0]['mena']) !== 'CZK') {
            $kurzy = array_unique(array_map(fn($r) => round((float) $r['mena_kurz'], 6) . '|' . round((float) ($r['mena_kurz_prodej'] ?? 0), 6), $rows));
            if (count($kurzy) > 1) throw new MenaException('kurzy_smisene', 'Dodací listy v ' . strtoupper((string) $rows[0]['mena']) . ' mají různé kurzy (objednávky z různých dnů) — vystavte fakturu zvlášť pro každý kurz.', 409);
        }
        mena_nastav_doklad($pdo, 'faktury', $faId, $rows[0] + ['mena_kurz_prodej' => null]);
    }

    /** Smí se snapshot přepsat? Jinak MenaException('doklad_zamceny', 409). */
    function mena_prepis_povolen(PDO $pdo, string $tab, int $id): void {
        $zamek = fn(string $proc) => new MenaException('doklad_zamceny', "Měnu dokladu už nelze změnit: $proc.", 409);
        if ($tab === 'objednavky') {
            // pay_status vzniká líně (první QR/online platba) → na instalaci bez nich sloupec není
            $st = $pdo->prepare("SELECT stav" . (mena_ma_sloupec($pdo, 'objednavky', 'pay_status') ? ', pay_status' : '') . " FROM objednavky WHERE id = ?");
            $st->execute([$id]);
            $o = $st->fetch(PDO::FETCH_ASSOC);
            if (!$o) throw new MenaException('doklad_nenalezen', 'Doklad nenalezen', 404);
            if (($o['pay_status'] ?? '') === 'paid' || ($o['stav'] ?? '') === 'zaplaceno') throw $zamek('objednávka je zaplacená');
            if (($o['pay_status'] ?? '') === 'pending') throw $zamek('u platební brány probíhá platba (dokončete ji nebo zrušte)');
            $q = fn(string $sql) => (function () use ($pdo, $sql, $id) { $s = $pdo->prepare($sql); $s->execute([$id]); return (bool) $s->fetchColumn(); })();
            if ($q("SELECT 1 FROM dodaci_listy WHERE objednavka_id = ? LIMIT 1")) throw $zamek('k objednávce je dodací list');
            // faktura k objednávce vzniká jen přes DL (faktury_dodaci_listy) → pokrývá kontrola DL výše
            try { if ($q("SELECT 1 FROM platby_doklad WHERE objednavka_id = ? AND castka_mena > 0 LIMIT 1")) throw $zamek('platba už byla přijata'); }
            catch (PDOException $e) { /* ledger ještě neexistuje */ }
            return;
        }
        if ($tab === 'restaurant_pos_ucty') {
            $st = $pdo->prepare("SELECT stav" . (mena_ma_sloupec($pdo, 'restaurant_pos_ucty', 'pay_status') ? ', pay_status' : '') . " FROM restaurant_pos_ucty WHERE id = ?");
            $st->execute([$id]);
            $u = $st->fetch(PDO::FETCH_ASSOC);
            if (!$u) throw new MenaException('doklad_nenalezen', 'Účet nenalezen', 404);
            if (($u['stav'] ?? '') === 'paid' || ($u['pay_status'] ?? '') === 'paid') throw $zamek('účet je zaplacený');
            return;
        }
        throw $zamek('vystavenou fakturu ani dodací list nelze převést do jiné měny');
    }

    /**
     * Měna dokladu z načteného řádku (lazy snapshot, když chybí).
     * → ['mena','mena_kurz','mena_kurz_prodej','mena_zaklad','symbol',
     *    'k_doklad' (násobitel uložená částka → měna dokladu), 'k_czk' (uložená částka → Kč)]
     */
    function mena_doklad(PDO $pdo, string $tabulka, array $row): array {
        if (empty($row['mena']) && !empty($row['id'])) mena_snapshot_doklad($pdo, $tabulka, (int) $row['id']);
        return mena_doklad_ro($pdo, $row);
    }

    /** Totéž co mena_doklad(), ale BEZ zápisu snapshotu (pro seznamy — GET nic nezapisuje).
     *  Doklad bez snapshotu = měna, ve které by se vystavil teď (stejně jako ho vytiskne PDF). */
    function mena_doklad_ro(PDO $pdo, array $row): array {
        if (empty($row['mena'])) {
            $m = mena_pro_novy_doklad($pdo);
        } else {
            $m = ['mena' => strtoupper((string) $row['mena']),
                  'mena_kurz' => max(0.0001, (float) ($row['mena_kurz'] ?? 1)),
                  'mena_kurz_prodej' => $row['mena_kurz_prodej'] ?? null,
                  'mena_zaklad' => strtoupper((string) (($row['mena_zaklad'] ?? '') ?: 'CZK'))];
        }
        if (!isset(MENA_PODPOROVANE[$m['mena']])) $m['mena'] = 'CZK';
        $prodej = (float) ($m['mena_kurz_prodej'] ?? 0);
        $m['mena_kurz_prodej'] = $prodej > 0 ? $prodej : null;
        if ($m['mena'] === 'CZK') { $m['mena_kurz'] = 1.0; $m['mena_kurz_prodej'] = null; $m['mena_zaklad'] = 'CZK'; }
        $kurz = (float) $m['mena_kurz'];
        $m['k_doklad'] = ($m['mena_zaklad'] === $m['mena']) ? 1.0 : 1.0 / ($m['mena_kurz_prodej'] ?: $kurz);  // uloženo v Kč → ÷kurz prodeje
        $m['k_czk']    = ($m['mena_zaklad'] === 'CZK') ? 1.0 : $kurz;                                          // uloženo v EUR → ×kurz
        $m['symbol']   = MENA_PODPOROVANE[$m['mena']];
        return $m;
    }

    /**
     * Doklad v cizí měně jako „nativní" doklad v ní — STEJNÝ výpočet jako účetní programy
     * (ověřeno živě proti FlexiBee): jednotková cena zaokrouhlená na 2 místa, řádek = round(mn × cena),
     * DPH po řádcích round(řádek × sazba), součty po sazbách. Kč (rekapitulace §29 ZDPH, export) = ×kurz ČNB.
     * $polozky = [[mnozstvi, cena (v uložené měně), sazba], …] ve stejném pořadí jako na dokladu.
     * 🆕 v3.0.643 — $vyrovnat_nulu: doklad, který je v Kč přesně 0 (100% voucher/sleva), je 0,00 i v měně.
     */
    function mena_doklad_polozky(array $polozky, array $m, bool $vyrovnat_nulu = true): array {
        $k = (float) $m['k_doklad']; $kurz = (float) $m['mena_kurz'];
        $out = []; $sazby = []; $kcSum = 0.0;
        foreach ($polozky as $i => [$mn, $cena, $sazba]) {
            $c = round((float) $cena * $k, 2);
            $z = round((float) $mn * $c, 2);
            $d = round($z * (float) $sazba / 100, 2);
            $out[$i] = ['cena' => $c, 'zaklad' => $z, 'dph' => $d];
            $sazby[$i] = (float) $sazba;
            $kcSum += (float) $mn * (float) $cena * (1 + (float) $sazba / 100);
        }
        $vyrovnano = 0.0;
        if ($vyrovnat_nulu && $out && abs($k - 1.0) > 1e-12 && abs($kcSum) < 0.005) {
            $cel = round(array_sum(array_column($out, 'zaklad')) + array_sum(array_column($out, 'dph')), 2);
            if (abs($cel) >= 0.005) {
                $last = null;
                foreach ($out as $i => $o) { if ($o['zaklad'] < 0) $last = $i; }
                if ($last !== null) {
                    $s = $sazby[$last];
                    $dz = round(-$cel / (1 + $s / 100), 2);
                    $dd = round(-$cel - $dz, 2);
                    $out[$last]['zaklad'] = round($out[$last]['zaklad'] + $dz, 2);
                    $out[$last]['dph'] = round($out[$last]['dph'] + $dd, 2);
                    $mnL = (float) ($polozky[$last][0] ?? 1);
                    if (abs($mnL - 1.0) < 1e-9) $out[$last]['cena'] = $out[$last]['zaklad'];
                    $vyrovnano = -$cel;
                }
            }
        }
        $rozpis = [];
        foreach ($out as $i => $o) {
            $key = (string) (0 + $sazby[$i]);
            if (!isset($rozpis[$key])) $rozpis[$key] = ['bez' => 0.0, 'dph' => 0.0];
            $rozpis[$key]['bez'] = round($rozpis[$key]['bez'] + $o['zaklad'], 2);
            $rozpis[$key]['dph'] = round($rozpis[$key]['dph'] + $o['dph'], 2);
        }
        $czk = [];
        foreach ($rozpis as $key => $r) $czk[$key] = ['bez' => round($r['bez'] * $kurz, 2), 'dph' => round($r['dph'] * $kurz, 2)];
        $bez = round(array_sum(array_column($rozpis, 'bez')), 2);
        $dph = round(array_sum(array_column($rozpis, 'dph')), 2);
        return ['polozky' => $out, 'rozpis' => $rozpis, 'rozpis_czk' => $czk, 'rozpis_czk_cnb' => $czk,
                'bez' => $bez, 'dph' => $dph, 'celkem' => round($bez + $dph, 2), 'vyrovnano' => round($vyrovnano, 2)];
    }

    /**
     * Rozpis DPH {sazba:{bez,dph}} (v uložené měně) → měna dokladu, s haléřovým/centovým
     * zbytkem v sazbě s největším základem, aby Σ rozpisu = celkem dokladu přesně.
     */
    function mena_prevod_rozpisu(array $rozpis, float $k, float $bezCelkem, float $dphCelkem): array {
        $out = [];
        foreach ($rozpis as $s => $r) $out[$s] = ['bez' => round($r['bez'] * $k, 2), 'dph' => round($r['dph'] * $k, 2)];
        if ($out) {
            $maxKey = null; $maxBez = -INF;
            foreach ($out as $s => $r) { if (abs($r['bez']) > $maxBez) { $maxBez = abs($r['bez']); $maxKey = $s; } }
            $out[$maxKey]['bez'] = round($out[$maxKey]['bez'] + round($bezCelkem * $k, 2) - array_sum(array_column($out, 'bez')), 2);
            $out[$maxKey]['dph'] = round($out[$maxKey]['dph'] + round($dphCelkem * $k, 2) - array_sum(array_column($out, 'dph')), 2);
        }
        return $out;
    }

    /** Má tabulka sloupec? (cache na request; přenositelné MySQL/SQLite) */
    function mena_ma_sloupec(PDO $pdo, string $tab, string $col): bool {
        static $cache = [];
        $key = spl_object_id($pdo) . "|$tab|$col";
        if (!array_key_exists($key, $cache)) {
            try { $pdo->query("SELECT `$col` FROM `$tab` LIMIT 0"); $cache[$key] = true; }
            catch (Throwable $e) { $cache[$key] = false; }
        }
        return $cache[$key];
    }

    /**
     * 💱 v3.0.642 — částka KONKRÉTNÍHO dokladu v jeho měně pro seznamy/detaily (= jako na PDF).
     * Doplní do každého řádku $rows (podle $idKey):
     *   doc_mena  — kód měny dokladu (CZK u starých dokladů, i když se teď prodává v EUR)
     *   doc_celkem, doc_bez, doc_dph — v měně dokladu (CZK = castka_* beze změny)
     *   doc_k     — násobitel uložená částka → měna dokladu (1 u Kč)
     *   doc_kurz, doc_kurz_prodej — 🆕 v3.0.643 kurz ČNB / prodejní kurz dokladu
     *   doc_uhrazeno (jen faktury)
     * Cizí měna = mena_doklad_polozky nad položkami (shodně s api/faktura.php a dodaci_list.php).
     * Výkon: 1 dotaz na hlavičky; položky se načítají DÁVKOVĚ jen pro doklady s měnou ≠ CZK.
     * $typ: 'faktury' | 'objednavky' | 'dodaci_listy'
     */
    function mena_doc_pole(PDO $pdo, string $typ, array &$rows, string $idKey = 'id'): void {
        if (!$rows) return;
        $ids = [];
        foreach ($rows as $r) { $i = (int) ($r[$idKey] ?? 0); if ($i > 0) $ids[$i] = true; }
        if (!$ids) return;
        $in = implode(',', array_keys($ids));
        $kp = fn(string $t, string $a = '') => mena_ma_sloupec($pdo, $t, 'mena_kurz_prodej') ? ($a ? "$a." : '') . 'mena_kurz_prodej' : 'NULL';
        try {
            if ($typ === 'faktury') {
                $meta = $pdo->query("SELECT id, mena, mena_kurz, " . $kp('faktury') . " AS mena_kurz_prodej, mena_zaklad, rucni, castka_celkem, castka_bez_dph, castka_dph, castka_uhrazeno
                                     FROM faktury WHERE id IN ($in)")->fetchAll(PDO::FETCH_ASSOC);
            } elseif ($typ === 'objednavky') {
                $meta = $pdo->query("SELECT id, mena, mena_kurz, " . $kp('objednavky') . " AS mena_kurz_prodej, mena_zaklad, castka_celkem, castka_bez_dph, castka_dph
                                     FROM objednavky WHERE id IN ($in)")->fetchAll(PDO::FETCH_ASSOC);
            } elseif ($typ === 'dodaci_listy') {
                // DL k objednávce dědí měnu objednávky (jako dodaci_list.php), ruční DL má vlastní snapshot
                $meta = $pdo->query("SELECT dl.id, dl.castka_celkem, dl.objednavka_id,
                                            IF(dl.objednavka_id IS NULL, dl.mena, o.mena) AS mena,
                                            IF(dl.objednavka_id IS NULL, dl.mena_kurz, o.mena_kurz) AS mena_kurz,
                                            IF(dl.objednavka_id IS NULL, " . $kp('dodaci_listy', 'dl') . ", " . $kp('objednavky', 'o') . ") AS mena_kurz_prodej,
                                            IF(dl.objednavka_id IS NULL, dl.mena_zaklad, o.mena_zaklad) AS mena_zaklad
                                     FROM dodaci_listy dl LEFT JOIN objednavky o ON o.id = dl.objednavka_id
                                     WHERE dl.id IN ($in)")->fetchAll(PDO::FETCH_ASSOC);
            } else return;
        } catch (Throwable $e) { error_log("mena_doc_pole $typ: " . $e->getMessage()); return; }

        $info = []; $cizi = [];
        foreach ($meta as $h) {
            $m = mena_doklad_ro($pdo, $h);
            $info[(int) $h['id']] = ['h' => $h, 'm' => $m];
            if ($m['mena'] !== 'CZK') $cizi[(int) $h['id']] = $h;
        }

        // Položky jen pro cizoměnové doklady — dávkově (žádné N+1)
        $pol = [];   // id dokladu => [[mn, cena, sazba], …]
        if ($cizi) {
            try { $pol = mena_doc_polozky_davka($pdo, $typ, $cizi); }
            catch (Throwable $e) { error_log("mena_doc_pole polozky $typ: " . $e->getMessage()); }
        }

        $out = [];
        foreach ($info as $id => ['h' => $h, 'm' => $m]) {
            $cel = round((float) ($h['castka_celkem'] ?? 0), 2);
            if ($m['mena'] === 'CZK') {
                $o = ['doc_mena' => 'CZK', 'doc_k' => 1.0, 'doc_celkem' => $cel,
                      'doc_bez' => array_key_exists('castka_bez_dph', $h) ? round((float) $h['castka_bez_dph'], 2) : null,
                      'doc_dph' => array_key_exists('castka_dph', $h) ? round((float) $h['castka_dph'], 2) : null,
                      'doc_kurz' => 1.0, 'doc_kurz_prodej' => 1.0];
                if ($typ === 'faktury') $o['doc_uhrazeno'] = round((float) $h['castka_uhrazeno'], 2);
            } else {
                $calc = mena_doklad_polozky($pol[$id] ?? [], $m);
                $o = ['doc_mena' => $m['mena'], 'doc_k' => (float) $m['k_doklad'], 'doc_celkem' => $calc['celkem'],
                      'doc_bez' => $calc['bez'], 'doc_dph' => $calc['dph'],
                      'doc_kurz' => (float) $m['mena_kurz'], 'doc_kurz_prodej' => (float) ($m['mena_kurz_prodej'] ?: $m['mena_kurz'])];
                if ($typ === 'faktury') {
                    // Plně uhrazená = celá částka dokladu (castka_uhrazeno je v uložené měně → ÷kurz by ujel o centy)
                    $uh = (float) $h['castka_uhrazeno'];
                    $o['doc_uhrazeno'] = ($uh >= (float) $h['castka_celkem'] - 0.005 && $uh > 0)
                        ? $calc['celkem'] : round($uh * (float) $m['k_doklad'], 2);
                }
            }
            $out[$id] = $o;
        }
        foreach ($rows as &$r) {
            $i = (int) ($r[$idKey] ?? 0);
            if (isset($out[$i])) $r = array_merge($r, $out[$i]);
        }
        unset($r);
    }

    /** Dávkové načtení položek [[mn, cena, sazba]] pro doklady $hlavicky (id => řádek) — stejné zdroje jako PDF. */
    function mena_doc_polozky_davka(PDO $pdo, string $typ, array $hlavicky): array {
        $pol = [];
        $add = function (array $rows) use (&$pol) {
            foreach ($rows as $p) $pol[(int) $p['doc_id']][] = [(float) $p['mnozstvi'], (float) $p['cena_bez_dph'], (float) ($p['sazba_dph'] ?? 0)];
        };
        $ids = implode(',', array_map('intval', array_keys($hlavicky)));
        if ($typ === 'objednavky') {
            $add($pdo->query("SELECT objednavka_id AS doc_id, mnozstvi, cena_bez_dph, sazba_dph FROM objednavky_polozky
                              WHERE objednavka_id IN ($ids) ORDER BY objednavka_id, id")->fetchAll(PDO::FETCH_ASSOC));
        } elseif ($typ === 'dodaci_listy') {
            $add($pdo->query("SELECT dodaci_list_id AS doc_id, mnozstvi, cena_bez_dph, sazba_dph FROM dodaci_list_polozky
                              WHERE dodaci_list_id IN ($ids) ORDER BY dodaci_list_id, id")->fetchAll(PDO::FETCH_ASSOC));
        } elseif ($typ === 'faktury') {
            // = nacti_fakturu() v faktura.php: faktura_polozky (ruční / vlastní řádky automatické faktury)
            //   → dodaci_list_polozky → fallback objednavky_polozky
            // 🐛 v3.0.644 — vlastní řádky (faktura_polozky) mají přednost i u automatické faktury
            //   (upravené položky faktury / materializace) — dřív se u ní četly řádky DL.
            $add($pdo->query("SELECT faktura_id AS doc_id, mnozstvi, cena_bez_dph, sazba_dph FROM faktura_polozky
                              WHERE faktura_id IN ($ids) ORDER BY faktura_id, poradi, id")->fetchAll(PDO::FETCH_ASSOC));
            $zDl = [];
            foreach ($hlavicky as $id => $h) { if (empty($h['rucni']) && empty($pol[(int) $id])) $zDl[] = (int) $id; }
            if ($zDl) {
                $add($pdo->query("SELECT fdl.faktura_id AS doc_id, dlp.mnozstvi, dlp.cena_bez_dph, dlp.sazba_dph
                                  FROM faktury_dodaci_listy fdl
                                  JOIN dodaci_listy dl ON dl.id = fdl.dodaci_list_id
                                  JOIN dodaci_list_polozky dlp ON dlp.dodaci_list_id = dl.id
                                  WHERE fdl.faktura_id IN (" . implode(',', $zDl) . ")")->fetchAll(PDO::FETCH_ASSOC));
                $bez = array_values(array_filter($zDl, fn($i) => empty($pol[$i])));
                if ($bez) {
                    $add($pdo->query("SELECT fdl.faktura_id AS doc_id, p.mnozstvi, p.cena_bez_dph, p.sazba_dph
                                      FROM faktury_dodaci_listy fdl
                                      JOIN dodaci_listy dl ON dl.id = fdl.dodaci_list_id
                                      JOIN objednavky o ON o.id = dl.objednavka_id
                                      JOIN objednavky_polozky p ON p.objednavka_id = o.id
                                      JOIN vyrobky v ON v.id = p.vyrobek_id
                                      WHERE fdl.faktura_id IN (" . implode(',', $bez) . ")")->fetchAll(PDO::FETCH_ASSOC));
                }
            }
        }
        return $pol;
    }

    // ══════════════════════════════════════════════════════════════════════════════════
    //  🆕 v3.0.643 — KURZY (tabulka mena_kurzy, ČNB denně + líně, nebo vlastní)
    // ══════════════════════════════════════════════════════════════════════════════════

    /** Rozparsuje denní kurzovní lístek ČNB → ['datum' => 'Y-m-d', 'kurzy' => [KOD => Kč za 1]] nebo null. */
    function mena_cnb_parse(string $txt): ?array {
        $lines = preg_split('/\r?\n/', trim($txt));
        if (count($lines) < 3) return null;
        $d = explode(' ', trim($lines[0]))[0] ?? '';
        $dt = DateTime::createFromFormat('!d.m.Y', $d);
        if (!$dt) return null;
        $kurzy = [];
        foreach ($lines as $ln) {
            $p = explode('|', $ln);
            if (count($p) !== 5) continue;
            $kod = strtoupper(trim($p[3]));
            $mn = max(1, (int) $p[2]);
            $k = (float) str_replace(',', '.', trim($p[4]));
            if ($k > 0 && preg_match('/^[A-Z]{3}$/', $kod)) $kurzy[$kod] = round($k / $mn, 6);
        }
        return $kurzy ? ['datum' => $dt->format('Y-m-d'), 'kurzy' => $kurzy] : null;
    }

    /**
     * Stáhne lístek ČNB platný k datu ($datum Y-m-d; null = dnes). Testy podstrčí
     * $GLOBALS['MENA_CNB_FETCH'] = fn(string $datumYmd): ?string (surový text lístku).
     */
    function mena_cnb_stahni(?string $datum = null, int $timeout = 6): ?array {
        $datum = $datum ?: date('Y-m-d');
        if (isset($GLOBALS['MENA_CNB_FETCH']) && is_callable($GLOBALS['MENA_CNB_FETCH'])) {
            $txt = ($GLOBALS['MENA_CNB_FETCH'])($datum);
        } else {
            $url = MENA_CNB_URL . '?date=' . date('d.m.Y', strtotime($datum));
            $ctx = stream_context_create(['http' => ['timeout' => $timeout, 'header' => "User-Agent: Appek-B2B/1.0\r\n"]]);
            $txt = @file_get_contents($url, false, $ctx);
        }
        if (!is_string($txt) || strlen($txt) < 50) return null;
        return mena_cnb_parse($txt);
    }

    /** Uloží lístek do mena_kurzy (jen měny z MENA_PODPOROVANE). Vrátí počet uložených kurzů. */
    function mena_kurzy_uloz(PDO $pdo, array $listek, string $zdroj = 'cnb'): int {
        $n = 0;
        $st = $pdo->prepare("REPLACE INTO mena_kurzy (mena, datum, kurz, zdroj, nacteno_at) VALUES (?, ?, ?, ?, ?)");
        foreach ($listek['kurzy'] as $kod => $k) {
            if ($kod === 'CZK' || !isset(MENA_PODPOROVANE[$kod])) continue;
            $st->execute([$kod, $listek['datum'], $k, $zdroj, date('Y-m-d H:i:s')]);
            $n++;
        }
        return $n;
    }

    /** Stáhne a uloží kurz ČNB k datu (cron / tlačítko). → ['datum', 'kurzy'] nebo null při výpadku. */
    function mena_kurzy_aktualizuj(PDO $pdo, ?string $datum = null, int $timeout = 15): ?array {
        $l = mena_cnb_stahni($datum, $timeout);
        mena_pokus_zapis($pdo, $datum ?: date('Y-m-d'));
        if (!$l) return null;
        mena_kurzy_uloz($pdo, $l);
        return $l;
    }

    /** Throttle líného stahování: poslední pokus pro datum (nastaveni.mena_cnb_pokus = "Y-m-d|unix"). */
    function mena_pokus_zapis(PDO $pdo, string $datum): void {
        $v = $datum . '|' . time();
        try {
            $u = $pdo->prepare("UPDATE nastaveni SET hodnota = ? WHERE klic = 'mena_cnb_pokus'");
            $u->execute([$v]);
            if ($u->rowCount() === 0) {
                $e = $pdo->query("SELECT COUNT(*) FROM nastaveni WHERE klic = 'mena_cnb_pokus'")->fetchColumn();
                if (!$e) $pdo->prepare("INSERT INTO nastaveni (klic, hodnota) VALUES ('mena_cnb_pokus', ?)")->execute([$v]);
            }
        } catch (Throwable $e) { /* best-effort */ }
    }
    function mena_pokus_nedavno(PDO $pdo, string $datum, int $sekund = 1800): bool {
        try {
            $st = $pdo->prepare("SELECT hodnota FROM nastaveni WHERE klic = 'mena_cnb_pokus'");
            $st->execute();
            [$d, $ts] = array_pad(explode('|', (string) $st->fetchColumn()), 2, 0);
            return $d === $datum && (time() - (int) $ts) < $sekund;
        } catch (Throwable $e) { return false; }
    }

    /** Kurz měny k datu. Viz smlouva v hlavičce. */
    function mena_kurz_dne(PDO $pdo, string $mena, ?string $datum = null, bool $stahnout = true): array {
        return mena_kurz_dne_cfg($pdo, mena_config_load($pdo), $mena, $datum, $stahnout);
    }

    function mena_kurz_dne_cfg(PDO $pdo, array $cfg, string $mena, ?string $datum = null, bool $stahnout = true): array {
        $mena = strtoupper($mena);
        $D = $datum && preg_match('/^\d{4}-\d{2}-\d{2}$/', $datum) ? $datum : date('Y-m-d');
        if ($mena === 'CZK') return ['kurz' => 1.0, 'datum' => $D, 'zdroj' => 'pevny', 'stari_dni' => 0];
        if (!isset(MENA_PODPOROVANE[$mena])) throw new MenaException('mena_nepovolena', "Nepodporovaná měna $mena");
        $p = $cfg['platba'];
        if ($p['kurz_zdroj'] === 'rucni') {
            $k = (float) ($p['kurzy_rucni'][$mena] ?? 0);
            if ($k <= 0) throw new MenaException('kurz_chybi', "Není zadaný vlastní kurz pro $mena (Nastavení → Přístupy, ceny & měna).", 503);
            return ['kurz' => $k, 'datum' => $D, 'zdroj' => 'rucni', 'stari_dni' => 0];
        }
        $nacti = function () use ($pdo, $mena, $D) {
            $st = $pdo->prepare("SELECT kurz, datum FROM mena_kurzy WHERE mena = ? AND zdroj = 'cnb' AND datum <= ? ORDER BY datum DESC LIMIT 1");
            $st->execute([$mena, $D]);
            return $st->fetch(PDO::FETCH_ASSOC) ?: null;
        };
        $row = $nacti();
        // ČNB vyhlašuje v pracovní dny ~14:30 → nejnovější možný lístek = dnes po 14:40, jinak poslední pracovní den.
        //   Máme-li ho, nestahovat (dřív o víkendu/ráno synchronní pokus v pokladně/B2B každých 30 min).
        $ocek = $D;
        if ($D === date('Y-m-d') && (int) date('N') <= 5 && date('H:i') < '14:40') $ocek = date('Y-m-d', strtotime($D . ' -1 day'));
        while ((int) date('N', strtotime($ocek)) >= 6) $ocek = date('Y-m-d', strtotime($ocek . ' -1 day'));
        if ($stahnout && (!$row || $row['datum'] < $ocek) && !mena_pokus_nedavno($pdo, $D)) {
            try { mena_kurzy_aktualizuj($pdo, $D, 6); } catch (Throwable $e) { error_log('mena_kurz_dne ČNB: ' . $e->getMessage()); }
            $row = $nacti();
        }
        if (!$row) throw new MenaException('kurz_chybi', "Kurz ČNB pro $mena není k dispozici (ČNB nedostupná). Zkus to později nebo nastav vlastní kurz.", 503);
        $stari = (int) round((strtotime($D) - strtotime((string) $row['datum'])) / 86400);
        return ['kurz' => (float) $row['kurz'], 'datum' => (string) $row['datum'], 'zdroj' => 'cnb', 'stari_dni' => max(0, $stari)];
    }

    // ══════════════════════════════════════════════════════════════════════════════════
    //  🆕 v3.0.643 — VOLBA MĚNY PLATBY
    // ══════════════════════════════════════════════════════════════════════════════════

    function mena_volba_platby(PDO $pdo, ?string $volba, string $kanal): array {
        return mena_volba_platby_cfg($pdo, mena_config_load($pdo), $volba, $kanal, true);
    }

    function mena_volba_platby_cfg(PDO $pdo, array $cfg, ?string $volba, string $kanal, bool $stahnout = true): array {
        $volba = $volba !== null && trim($volba) !== '' ? strtoupper(trim($volba)) : null;
        if ($kanal !== 'admin' && !in_array($kanal, MENA_KANALY, true)) throw new MenaException('kanal_neznamy', "Neznámý prodejní kanál $kanal");
        $p = $cfg['platba'];

        if (!$p['aktivni']) {
            if (($cfg['zakladni'] ?? 'CZK') !== 'CZK' && $volba !== null && $volba !== $cfg['zakladni']) {
                throw new MenaException('cenik_prepocten', 'Ceník je trvale přepočtený do ' . $cfg['zakladni'] . ' — platba v jiné měně není možná.');
            }
            $leg = ($cfg['zobrazeni'] === 'mena' && $cfg['kod'] !== 'CZK')
                ? ['mena' => $cfg['kod'], 'mena_kurz' => (float) $cfg['kurz'], 'mena_zaklad' => $cfg['zakladni']]
                : ['mena' => 'CZK', 'mena_kurz' => 1.0, 'mena_zaklad' => 'CZK'];
            if ($volba !== null && $volba !== $leg['mena']) {
                throw new MenaException('platba_vypnuta', 'Platba v jiné měně není zapnutá (Nastavení → Přístupy, ceny & měna).');
            }
            return $leg + ['mena_kurz_prodej' => null, 'kurz_datum' => null, 'kurz_zdroj' => null, 'kurz_stari_dni' => null,
                           'prirazka_pct' => 0.0, 'symbol' => MENA_PODPOROVANE[$leg['mena']]];
        }

        $kanalOk = $kanal === 'admin' || in_array($kanal, $p['kanaly'], true);
        $mena = $volba ?? ($kanalOk ? $p['vychozi'] : 'CZK');
        if ($mena !== 'CZK' && !$kanalOk) throw new MenaException('kanal_nepovolen', 'V tomto kanálu je platba jen v Kč.');
        if (!in_array($mena, $p['prijimane'], true)) throw new MenaException('mena_nepovolena', "Platba v $mena není povolená.");
        if ($mena === 'CZK') {
            return ['mena' => 'CZK', 'mena_kurz' => 1.0, 'mena_kurz_prodej' => 1.0, 'mena_zaklad' => 'CZK',
                    'kurz_datum' => date('Y-m-d'), 'kurz_zdroj' => 'pevny', 'kurz_stari_dni' => 0, 'prirazka_pct' => 0.0, 'symbol' => 'Kč'];
        }
        $czk = ['mena' => 'CZK', 'mena_kurz' => 1.0, 'mena_kurz_prodej' => 1.0, 'mena_zaklad' => 'CZK',
                'kurz_datum' => date('Y-m-d'), 'kurz_zdroj' => 'pevny', 'kurz_stari_dni' => 0, 'prirazka_pct' => 0.0, 'symbol' => 'Kč'];
        try {
            $k = mena_kurz_dne_cfg($pdo, $cfg, $mena, null, $stahnout);
        } catch (MenaException $e) {
            if ($volba === null) return $czk;   // nikdo cizí měnu nevolil (jen výchozí) → bez kurzu se prodá v Kč, ne chyba
            throw $e;
        }
        if ($k['stari_dni'] > $p['max_stari_kurzu_dni']) {
            if ($volba === null) return $czk;   // starý kurz (svátky) nesmí zablokovat prodej bez volby měny
            throw new MenaException('kurz_stary', "Kurz $mena je starý {$k['stari_dni']} dní (poslední z " . date('j. n. Y', strtotime($k['datum'])) . ') — platba v ' . $mena . ' je dočasně vypnutá.', 503);
        }
        $pp = (float) ($p['prirazka_pct'][$mena] ?? 0);
        return ['mena' => $mena, 'mena_kurz' => round($k['kurz'], 6),
                'mena_kurz_prodej' => round($k['kurz'] / (1 + $pp / 100), 6), 'mena_zaklad' => 'CZK',
                'kurz_datum' => $k['datum'], 'kurz_zdroj' => $k['zdroj'], 'kurz_stari_dni' => $k['stari_dni'],
                'prirazka_pct' => $pp, 'symbol' => MENA_PODPOROVANE[$mena]];
    }

    /** Info pro front-endy (POS, B2B, pay/, objednat/). Nikdy nehází. */
    function mena_platebni_info(PDO $pdo, string $kanal): array {
        $cfg = mena_config_load($pdo);
        $p = $cfg['platba'];
        $kanalOk = $kanal === 'admin' || in_array($kanal, $p['kanaly'], true);
        $aktivni = $p['aktivni'] && $kanalOk;
        $meny = ['CZK' => ['symbol' => 'Kč', 'kurz' => 1.0, 'kurz_prodej' => 1.0, 'datum' => date('Y-m-d'), 'stari_dni' => 0, 'ok' => true, 'chyba' => null]];
        if ($aktivni) {
            foreach ($p['meny'] as $m) {
                try {
                    $v = mena_volba_platby_cfg($pdo, $cfg, $m, $kanal);
                    $meny[$m] = ['symbol' => $v['symbol'], 'kurz' => $v['mena_kurz'], 'kurz_prodej' => $v['mena_kurz_prodej'],
                                 'datum' => $v['kurz_datum'], 'stari_dni' => $v['kurz_stari_dni'], 'ok' => true, 'chyba' => null];
                } catch (MenaException $e) {
                    $meny[$m] = ['symbol' => MENA_PODPOROVANE[$m], 'kurz' => null, 'kurz_prodej' => null, 'datum' => null,
                                 'stari_dni' => null, 'ok' => false, 'chyba' => $e->getMessage()];
                }
            }
        }
        return ['aktivni' => $aktivni, 'vychozi' => $aktivni ? $p['vychozi'] : 'CZK',
                'prijimane' => $aktivni ? $p['prijimane'] : ['CZK'], 'terminal_meny' => $p['terminal_meny'],
                'vraceni' => $p['vraceni'], 'meny' => $meny];
    }

    /** EUR IBAN pro QR platbu převodem ('' = QR v EUR negenerovat). */
    function mena_iban_eur(PDO $pdo): string {
        $p = mena_config_load($pdo)['platba'];
        if ($p['iban_eur'] !== '') return $p['iban_eur'];
        try {
            $st = $pdo->prepare("SELECT hodnota FROM nastaveni WHERE klic = 'payment_methods_config_json'");
            $st->execute();
            $c = json_decode((string) $st->fetchColumn(), true);
            $i = strtoupper(preg_replace('/\s+/', '', (string) ($c['prevod']['ucet_iban_eur'] ?? '')));
            return $i;
        } catch (Throwable $e) { return ''; }
    }

    // ══════════════════════════════════════════════════════════════════════════════════
    //  🆕 v3.0.643 — ČÁSTKA DOKLADU, LEDGER, FORMÁT
    // ══════════════════════════════════════════════════════════════════════════════════

    /** Položky účtu stolu pro výpočet v měně: [[mn, cena bez DPH (4 dp), sazba]]. */
    function mena_ucet_polozky(PDO $pdo, int $ucetId): array {
        $saz = mena_ma_sloupec($pdo, 'restaurant_pos_polozky', 'sazba_dph') ? 'COALESCE(sazba_dph, 21)' : '21';
        $st = $pdo->prepare("SELECT mnozstvi, jednotkova_cena, $saz AS sazba FROM restaurant_pos_polozky WHERE ucet_id = ? AND stav <> 'storno' ORDER BY id");
        $st->execute([$ucetId]);
        $out = [];
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $s = (float) $r['sazba'];
            // na 2 místa = přesně jak ji uloží prodejní záznam (objednavky_polozky.cena_bez_dph DECIMAL(10,2)) → EUR účtu == EUR dokladu
            $out[] = [(float) $r['mnozstvi'], round((float) $r['jednotkova_cena'] / (1 + $s / 100), 2), $s];
        }
        return $out;
    }

    function mena_doc_castka(PDO $pdo, string $typ, int $id, ?array $m = null): array {
        $fin = function (string $mena, float $cel, float $kurz, ?float $prodej, float $czk) {
            return ['mena' => $mena, 'celkem' => round($cel, 2), 'celkem_minor' => (int) round($cel * 100),
                    'kurz' => $kurz, 'kurz_prodej' => $prodej ?: $kurz, 'celkem_czk' => round($czk, 2),
                    'symbol' => MENA_PODPOROVANE[$mena] ?? $mena];
        };
        if ($typ === 'restaurant_pos_ucty') {
            $st = $pdo->prepare("SELECT * FROM restaurant_pos_ucty WHERE id = ?");
            $st->execute([$id]);
            $u = $st->fetch(PDO::FETCH_ASSOC);
            if (!$u) throw new MenaException('doklad_nenalezen', 'Účet nenalezen', 404);
            $pol = mena_ucet_polozky($pdo, $id);
            $czk = 0.0;
            $st = $pdo->prepare("SELECT mnozstvi, jednotkova_cena FROM restaurant_pos_polozky WHERE ucet_id = ? AND stav <> 'storno'");
            $st->execute([$id]);
            foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) $czk += (float) $r['mnozstvi'] * (float) $r['jednotkova_cena'];
            $czk = round($czk, 2);
            $src = $m ?? (!empty($u['mena']) ? $u : ['mena' => 'CZK']);   // účet bez snapshotu = Kč (nikdy globál)
            $mm = mena_doklad_ro($pdo, ['mena' => $src['mena'] ?? 'CZK', 'mena_kurz' => $src['mena_kurz'] ?? 1,
                                        'mena_kurz_prodej' => $src['mena_kurz_prodej'] ?? null, 'mena_zaklad' => 'CZK']);
            if ($mm['mena'] === 'CZK') return $fin('CZK', $czk, 1.0, 1.0, $czk);
            return $fin($mm['mena'], mena_doklad_polozky($pol, $mm)['celkem'], (float) $mm['mena_kurz'], $mm['mena_kurz_prodej'], $czk);
        }
        if (!in_array($typ, ['objednavky', 'faktury', 'dodaci_listy'], true)) throw new InvalidArgumentException("mena_doc_castka: neznámý typ $typ");
        if ($m === null) {
            $rows = [['id' => $id]];
            mena_doc_pole($pdo, $typ, $rows);
            $r = $rows[0];
            if (!isset($r['doc_mena'])) throw new MenaException('doklad_nenalezen', 'Doklad nenalezen', 404);
            $st = $pdo->prepare("SELECT castka_celkem FROM `$typ` WHERE id = ?");
            $st->execute([$id]);
            return $fin($r['doc_mena'], (float) $r['doc_celkem'], (float) $r['doc_kurz'], (float) $r['doc_kurz_prodej'], (float) $st->fetchColumn());
        }
        // Náhled v jiné měně (quote před snapshotem)
        $st = $pdo->prepare("SELECT * FROM `$typ` WHERE id = ?");
        $st->execute([$id]);
        $h = $st->fetch(PDO::FETCH_ASSOC);
        if (!$h) throw new MenaException('doklad_nenalezen', 'Doklad nenalezen', 404);
        $czk = round((float) $h['castka_celkem'], 2);
        $mm = mena_doklad_ro($pdo, ['mena' => $m['mena'] ?? 'CZK', 'mena_kurz' => $m['mena_kurz'] ?? 1,
                                    'mena_kurz_prodej' => $m['mena_kurz_prodej'] ?? null, 'mena_zaklad' => 'CZK']);
        if ($mm['mena'] === 'CZK') return $fin('CZK', $czk, 1.0, 1.0, $czk);
        $pol = mena_doc_polozky_davka($pdo, $typ, [$id => $h])[$id] ?? [];
        return $fin($mm['mena'], mena_doklad_polozky($pol, $mm)['celkem'], (float) $mm['mena_kurz'], $mm['mena_kurz_prodej'], $czk);
    }

    function mena_ulozit_castku(PDO $pdo, string $typ, int $id): float {
        if (!in_array($typ, ['objednavky', 'faktury', 'dodaci_listy'], true)) throw new InvalidArgumentException("mena_ulozit_castku: neznámý typ $typ");
        $c = mena_doc_castka($pdo, $typ, $id);
        $pdo->prepare("UPDATE `$typ` SET castka_mena = ? WHERE id = ?")->execute([$c['celkem'], $id]);
        return $c['celkem'];
    }

    function mena_platba_zapis(PDO $pdo, array $r): int {
        $zdroje = ['quick', 'stul', 'pickup', 'vratka', 'vymena', 'qr', 'online', 'faktura'];
        $metody = ['hotovost', 'karta', 'qr', 'online', 'poukaz', 'prevod'];
        if (!in_array($r['zdroj'] ?? '', $zdroje, true)) throw new InvalidArgumentException('mena_platba_zapis: neplatný zdroj');
        if (!in_array($r['metoda'] ?? '', $metody, true)) throw new InvalidArgumentException('mena_platba_zapis: neplatná metoda');
        $mena = strtoupper((string) ($r['mena'] ?? ''));
        if (!isset(MENA_PODPOROVANE[$mena])) throw new InvalidArgumentException('mena_platba_zapis: neplatná měna');
        if (!isset($r['castka_mena']) || !is_numeric($r['castka_mena'])) throw new InvalidArgumentException('mena_platba_zapis: chybí castka_mena');
        $kurz = $mena === 'CZK' ? 1.0 : (float) ($r['kurz'] ?? 0);
        if ($kurz <= 0) throw new InvalidArgumentException('mena_platba_zapis: chybí kurz');
        $cm = round((float) $r['castka_mena'], 2);
        $czk = isset($r['castka_czk']) && is_numeric($r['castka_czk']) ? round((float) $r['castka_czk'], 2) : round($cm * $kurz, 2);
        $idem = isset($r['idem']) && $r['idem'] !== '' ? substr((string) $r['idem'], 0, 64) : null;
        $najdi = function () use ($pdo, $idem) {
            $s = $pdo->prepare("SELECT id FROM platby_doklad WHERE idem = ?");
            $s->execute([$idem]);
            return (int) $s->fetchColumn();
        };
        if ($idem !== null && ($ex = $najdi())) return $ex;
        $num = fn($k) => isset($r[$k]) && is_numeric($r[$k]) ? round((float) $r[$k], 2) : null;
        try {
            $pdo->prepare("INSERT INTO platby_doklad (objednavka_id, ucet_id, zdroj, metoda, mena, castka_mena, kurz, castka_czk,
                                                      prijato_mena, vraceno_mena, pokladna, uzivatel, idem, created_at)
                           VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)")
                ->execute([!empty($r['objednavka_id']) ? (int) $r['objednavka_id'] : null, !empty($r['ucet_id']) ? (int) $r['ucet_id'] : null,
                           $r['zdroj'], $r['metoda'], $mena, $cm, $kurz, $czk, $num('prijato_mena'), $num('vraceno_mena'),
                           isset($r['pokladna']) ? mb_substr((string) $r['pokladna'], 0, 40) : null,
                           isset($r['uzivatel']) ? mb_substr((string) $r['uzivatel'], 0, 120) : null, $idem, date('Y-m-d H:i:s')]);
        } catch (PDOException $e) {
            if ($idem !== null && ($ex = $najdi())) return $ex;   // souběh se stejným idem
            throw $e;
        }
        return (int) $pdo->lastInsertId();
    }

    function mena_fmt(float $n, string $mena): string {
        $mena = strtoupper($mena);
        return number_format($n, 2, ',', ' ') . ' ' . (MENA_PODPOROVANE[$mena] ?? $mena);
    }

    // ══════════════════════════════════════════════════════════════════════════════════
    //  🆕 v3.0.643 — MIGRACE balíku 0 (volá apply_full_schema; MIMO transakci)
    // ══════════════════════════════════════════════════════════════════════════════════
    if (!defined('MENA_SCHEMA_VER')) define('MENA_SCHEMA_VER', '643.1');

    function mena_ensure_schema(PDO $pdo, bool $force = false): void {
        static $hotovo = false;
        if ($hotovo && !$force) return;
        $hotovo = true;
        if ($pdo->inTransaction()) { error_log('mena_ensure_schema: volání v transakci — přeskočeno'); $hotovo = false; return; }
        // Marker = verze + počet existujících cílových tabulek → když později vznikne restaurant_pos_*
        //   (zakládá je admin_pos.php), migrace se sama doběhne i bez $force.
        $tabulky = ['objednavky', 'faktury', 'dodaci_listy', 'odberatele', 'restaurant_pos_ucty', 'restaurant_pos_polozky', 'restaurant_pos_platby'];
        try {
            $pocet = (int) $pdo->query("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE()
                                        AND TABLE_NAME IN ('" . implode("','", $tabulky) . "')")->fetchColumn();
        } catch (Throwable $e) { $pocet = 0; }
        $marker = MENA_SCHEMA_VER . '|' . $pocet;
        if (!$force) {
            try {
                $st = $pdo->prepare("SELECT hodnota FROM nastaveni WHERE klic = 'mena_schema_ver'");
                $st->execute();
                if ($st->fetchColumn() === $marker) return;
            } catch (Throwable $e) { return; /* nastaveni ještě není (instalace) */ }
        }
        $vse = true;
        $ex = function (string $sql) use ($pdo, &$vse) {
            try { $pdo->exec($sql); } catch (Throwable $e) { $vse = false; error_log('mena_ensure_schema: ' . $e->getMessage()); }
        };
        $ex("CREATE TABLE IF NOT EXISTS mena_kurzy (
                mena CHAR(3) NOT NULL, datum DATE NOT NULL, kurz DECIMAL(12,6) NOT NULL,
                zdroj ENUM('cnb','rucni') NOT NULL DEFAULT 'cnb', nacteno_at DATETIME NULL,
                PRIMARY KEY (mena, datum)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $ex("CREATE TABLE IF NOT EXISTS platby_doklad (
                id INT AUTO_INCREMENT PRIMARY KEY,
                objednavka_id INT NULL, ucet_id INT NULL,
                zdroj ENUM('quick','stul','pickup','vratka','vymena','qr','online','faktura') NOT NULL,
                metoda VARCHAR(20) NOT NULL,
                mena CHAR(3) NOT NULL, castka_mena DECIMAL(12,2) NOT NULL, kurz DECIMAL(12,6) NOT NULL,
                castka_czk DECIMAL(12,2) NOT NULL, prijato_mena DECIMAL(12,2) NULL, vraceno_mena DECIMAL(12,2) NULL,
                pokladna VARCHAR(40) NULL, uzivatel VARCHAR(120) NULL, idem VARCHAR(64) NULL,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                KEY idx_pd_created (created_at), KEY idx_pd_obj (objednavka_id), KEY idx_pd_ucet (ucet_id),
                UNIQUE KEY uq_pd_idem (idem)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        $sloupce = [
            'objednavky'   => ['mena_kurz_prodej' => 'DECIMAL(12,6) NULL', 'castka_mena' => 'DECIMAL(12,2) NULL',
                               'pay_mena' => 'CHAR(3) NULL', 'pay_castka' => 'DECIMAL(12,2) NULL', 'pay_prijato' => 'DECIMAL(12,2) NULL'],
            'faktury'      => ['mena_kurz_prodej' => 'DECIMAL(12,6) NULL', 'castka_mena' => 'DECIMAL(12,2) NULL'],
            'dodaci_listy' => ['mena_kurz_prodej' => 'DECIMAL(12,6) NULL', 'castka_mena' => 'DECIMAL(12,2) NULL'],
            'restaurant_pos_ucty' => ['mena' => 'CHAR(3) NULL', 'mena_kurz' => 'DECIMAL(12,6) NULL', 'mena_kurz_prodej' => 'DECIMAL(12,6) NULL',
                                      'pay_mena' => 'CHAR(3) NULL', 'pay_castka' => 'DECIMAL(12,2) NULL', 'pay_suma_kc' => 'DECIMAL(10,2) NULL'],
            'restaurant_pos_polozky' => ['sazba_dph' => 'DECIMAL(5,2) NULL'],
            'restaurant_pos_platby'  => ['mena' => "CHAR(3) NOT NULL DEFAULT 'CZK'", 'castka_mena' => 'DECIMAL(12,2) NULL', 'kurz' => 'DECIMAL(12,6) NULL'],
            'odberatele'   => ['mena_platby' => 'CHAR(3) NULL'],
            'recurring_orders' => ['mena' => 'CHAR(3) NULL'],   // měna opakované objednávky (kurz = den generování)
        ];
        $pridanoCastka = [];
        foreach ($sloupce as $tab => $cols) {
            try { $exist = $pdo->query("SHOW COLUMNS FROM `$tab`")->fetchAll(PDO::FETCH_COLUMN); }
            catch (Throwable $e) { continue; /* tabulka zatím neexistuje (restaurant_pos_* zakládá admin_pos) → marker s počtem tabulek */ }
            $add = [];
            foreach ($cols as $c => $ddl) if (!in_array($c, $exist, true)) $add[] = "ADD COLUMN `$c` $ddl";
            if ($add) {
                $ex("ALTER TABLE `$tab` " . implode(', ', $add));
                if (isset($cols['castka_mena']) && !in_array('castka_mena', $exist, true) && in_array($tab, ['objednavky', 'faktury', 'dodaci_listy'], true)) $pridanoCastka[] = $tab;
            }
        }
        // restaurant_pos_ucty.pay_status: balík A/B potřebuje stav 'failed' (nesouhlasí částka/měna u brány)
        try {
            $t = $pdo->query("SHOW COLUMNS FROM restaurant_pos_ucty LIKE 'pay_status'")->fetch(PDO::FETCH_ASSOC);
            if ($t && stripos((string) $t['Type'], "'failed'") === false && stripos((string) $t['Type'], 'enum(') === 0) {
                $vals = substr((string) $t['Type'], 5, -1) . ",'failed'";
                $ex("ALTER TABLE restaurant_pos_ucty MODIFY pay_status ENUM($vals) NOT NULL DEFAULT 'none'");
            }
        } catch (Throwable $e) { /* tabulka/sloupec zatím není */ }

        // Backfill castka_mena (jen při přidání sloupce): Kč = castka_celkem, cizí měna = jako PDF
        //   updated_at se NESMÍ pohnout (ON UPDATE CURRENT_TIMESTAMP) — čte ho sync/export „změněno od".
        foreach ($pridanoCastka as $tab) {
            try {
                $ua = in_array('updated_at', $pdo->query("SHOW COLUMNS FROM `$tab`")->fetchAll(PDO::FETCH_COLUMN), true);
                $keep = $ua ? ($tab === 'dodaci_listy' ? ', dl.updated_at = dl.updated_at' : ', updated_at = updated_at') : '';
                if ($tab === 'dodaci_listy') {
                    $ex("UPDATE dodaci_listy dl LEFT JOIN objednavky o ON o.id = dl.objednavka_id
                         SET dl.castka_mena = dl.castka_celkem$keep
                         WHERE COALESCE(IF(dl.objednavka_id IS NULL, dl.mena, o.mena), 'CZK') = 'CZK'");
                    $ids = $pdo->query("SELECT dl.id FROM dodaci_listy dl LEFT JOIN objednavky o ON o.id = dl.objednavka_id
                                        WHERE IF(dl.objednavka_id IS NULL, dl.mena, o.mena) <> 'CZK'")->fetchAll(PDO::FETCH_COLUMN);
                } else {
                    $ex("UPDATE `$tab` SET castka_mena = castka_celkem$keep WHERE COALESCE(mena, 'CZK') = 'CZK'");
                    $ids = $pdo->query("SELECT id FROM `$tab` WHERE mena IS NOT NULL AND mena <> 'CZK'")->fetchAll(PDO::FETCH_COLUMN);
                }
                foreach (array_chunk(array_map('intval', $ids), 200) as $chunk) {
                    $rows = array_map(fn($i) => ['id' => $i], $chunk);
                    mena_doc_pole($pdo, $tab, $rows);
                    $u = $pdo->prepare("UPDATE `$tab` SET castka_mena = ?" . ($ua ? ', updated_at = updated_at' : '') . " WHERE id = ?");
                    foreach ($rows as $r) if (isset($r['doc_celkem'])) $u->execute([$r['doc_celkem'], $r['id']]);
                }
            } catch (Throwable $e) { $vse = false; error_log("mena_ensure_schema backfill $tab: " . $e->getMessage()); }
        }
        if ($vse) {
            try {
                $u = $pdo->prepare("UPDATE nastaveni SET hodnota = ? WHERE klic = 'mena_schema_ver'");
                $u->execute([$marker]);
                if ($u->rowCount() === 0 && !$pdo->query("SELECT COUNT(*) FROM nastaveni WHERE klic = 'mena_schema_ver'")->fetchColumn()) {
                    $pdo->prepare("INSERT INTO nastaveni (klic, hodnota) VALUES ('mena_schema_ver', ?)")->execute([$marker]);
                }
            } catch (Throwable $e) { /* best-effort */ }
        }
    }
}
