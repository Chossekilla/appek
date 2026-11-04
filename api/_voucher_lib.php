<?php
/**
 * 🎟️ VOUCHERY — sdílená logika (v3.0.631): schéma, kanály + chování (v3.0.630) a VOUCHER NA OBJEDNÁVCE.
 *
 * Voucher na objednávce = řádky objednavky_polozky s voucher_id (vyrobek_id NULL, záporná cena):
 *   chování „platba" → 1 řádek „Uhrazeno voucherem KÓD" se sazbou 0 % (cena i DPH zboží beze změny),
 *   chování „sleva"  → záporné řádky po sazbách DPH v poměru zboží (sníží i základ DPH).
 * Řádky se kopírují do DL a faktury jako ostatní volné řádky.
 *
 * Kolik objednávka z voucheru čerpá, určuje KNIHA voucher_pohyby (objednavka_id), ne řádky —
 *   B2B úprava řádky smaže a vloží znovu. voucher_objednavka_sync() spočítá cíl podle aktuálního
 *   zboží a zaúčtuje jen rozdíl (dočerpání / vrácení), pak řádky přepíše. Storno = cíl 0 → vše zpět.
 * Jeden voucher na objednávku. Funkce s DB zápisem očekávají, že volající drží transakci.
 */

/** Místa, kde lze voucher uplatnit. Klíč = zdroj uplatnění (posílá ho volající). */
const VOUCHER_KANALY = [
    'pos'   => 'Pokladna',
    'admin' => 'Objednávky v adminu',
    'b2b'   => 'B2B portál',
    'web'   => 'Online objednávky',
];

function voucher_ensure_schema(PDO $pdo): void {
    static $done = false;
    if ($done) return;
    // DDL (CREATE/ALTER) v MySQL/MariaDB IMPLICITNĚ COMMITNE rozběhlou transakci → volajícímu pak commit() hodí
    // „There is no active transaction" a půl změn už je uložená. Uvnitř transakce proto schéma nesahat
    // (volající má ensure zavolat předem; tady jen předpokládáme, že schéma existuje).
    if ($pdo->inTransaction()) return;
    $done = true;
    try {
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS vouchery (
                id INT AUTO_INCREMENT PRIMARY KEY,
                kod VARCHAR(40) NOT NULL UNIQUE,
                typ VARCHAR(20) NOT NULL DEFAULT 'voucher',
                hodnota DECIMAL(10,2) NOT NULL DEFAULT 0,
                zustatek DECIMAL(10,2) NOT NULL DEFAULT 0,
                platnost_do DATE NULL,
                stav VARCHAR(20) NOT NULL DEFAULT 'aktivni',
                poznamka VARCHAR(255) NULL,
                vytvoreno DATETIME DEFAULT CURRENT_TIMESTAMP,
                posledni_pouziti DATETIME NULL,
                INDEX idx_kod (kod), INDEX idx_stav (stav)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS voucher_pohyby (
                id INT AUTO_INCREMENT PRIMARY KEY,
                voucher_id INT NOT NULL,
                typ VARCHAR(20) NOT NULL,         -- 'uplatneni' | 'vraceni' | 'dobiti' | 'vytvoreni'
                castka DECIMAL(10,2) NOT NULL,    -- + dobití / vytvoření / vrácení, − uplatnění
                zustatek_po DECIMAL(10,2) NOT NULL,
                doklad VARCHAR(40) NULL,
                kdo VARCHAR(120) NULL,
                kdy DATETIME DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_v (voucher_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
        foreach ([
            // v3.0.282 — voucher pro konkrétního odběratele + procentuální sleva (typ='sleva')
            "ALTER TABLE vouchery ADD COLUMN IF NOT EXISTS odberatel_id INT NULL",
            "ALTER TABLE vouchery ADD COLUMN IF NOT EXISTS odberatel_nazev VARCHAR(255) NULL",
            "ALTER TABLE vouchery ADD COLUMN IF NOT EXISTS odberatel_email VARCHAR(150) NULL",
            "ALTER TABLE vouchery ADD COLUMN IF NOT EXISTS sleva_pct DECIMAL(5,2) NULL",
            "ALTER TABLE vouchery ADD COLUMN IF NOT EXISTS sleva_max_kc DECIMAL(10,2) NULL",
            "ALTER TABLE vouchery ADD COLUMN IF NOT EXISTS odeslano_email TINYINT NOT NULL DEFAULT 0",
            "ALTER TABLE vouchery ADD COLUMN IF NOT EXISTS odeslano_kdy DATETIME NULL",
            // v3.0.630 — kde lze uplatnit (CSV kanálů, NULL = všude) + jak se chová (platba | sleva)
            "ALTER TABLE vouchery ADD COLUMN IF NOT EXISTS kanaly VARCHAR(60) NULL",
            "ALTER TABLE vouchery ADD COLUMN IF NOT EXISTS chovani VARCHAR(10) NULL",
            // v3.0.631 — voucher na objednávce: vazba pohybu na objednávku + řádku na voucher
            "ALTER TABLE voucher_pohyby ADD COLUMN IF NOT EXISTS objednavka_id INT NULL",
            "ALTER TABLE voucher_pohyby ADD INDEX IF NOT EXISTS idx_obj (objednavka_id)",
            "ALTER TABLE objednavky_polozky ADD COLUMN IF NOT EXISTS voucher_id INT NULL",
        ] as $alter) { try { $pdo->exec($alter); } catch (Throwable $e) {} }
    } catch (Throwable $e) { error_log('voucher_ensure_schema: ' . $e->getMessage()); }
}

/** Povolené kanály voucheru (NULL/prázdné = všude — i vouchery z doby před v3.0.630). */
function voucher_kanaly(array $v): array {
    $k = array_values(array_intersect(explode(',', (string) ($v['kanaly'] ?? '')), array_keys(VOUCHER_KANALY)));
    return $k ?: array_keys(VOUCHER_KANALY);
}

/** Jak se voucher chová: 'sleva' = snižuje cenu (a základ DPH po sazbách),
 *  'platba' = úhrada předem, cenu ani DPH nemění. % sleva je vždy sleva, dárková karta vždy platba,
 *  Kč voucher dle volby při vytvoření (default platba = dosavadní chování). */
function voucher_chovani(array $v): string {
    if (($v['typ'] ?? '') === 'sleva') return 'sleva';
    if (($v['typ'] ?? '') === 'darkova_karta') return 'platba';
    return ($v['chovani'] ?? '') === 'sleva' ? 'sleva' : 'platba';
}

/** Voucher, ze kterého objednávka podle knihy pohybů čerpá (null = žádný). */
function voucher_objednavka_id(PDO $pdo, int $oid): ?int {
    voucher_ensure_schema($pdo);
    $st = $pdo->prepare("SELECT voucher_id FROM voucher_pohyby WHERE objednavka_id = :o
                         GROUP BY voucher_id HAVING SUM(castka) < -0.004 ORDER BY MAX(id) DESC LIMIT 1");
    $st->execute(['o' => $oid]);
    $vid = $st->fetchColumn();
    return $vid ? (int) $vid : null;
}

/**
 * Srovná čerpání voucheru s aktuálním zbožím objednávky (dočerpá / vrátí rozdíl) a přepíše
 * řádky voucheru + hlavičku objednávky. $zrusit = storno/odebrání → vše zpět na voucher.
 * Vrací částku, kterou objednávka z voucheru nově čerpá (s DPH).
 */
/**
 * Kolik voucher z dané částky zboží (s DPH) odečte. $aplikovano = co už z něj tato objednávka čerpá
 * (Kč voucher má k dispozici zůstatek + to, co už drží). Čistá funkce — sdílí ji sync i odhad v košíku.
 */
function voucher_cil(array $v, float $zbozi, float $aplikovano = 0.0): float {
    if ($zbozi <= 0) return 0.0;
    if ($v['typ'] === 'sleva') {
        $cil = $zbozi * (float) $v['sleva_pct'] / 100;
        if (!empty($v['sleva_max_kc'])) $cil = min($cil, (float) $v['sleva_max_kc']);
    } else {
        $cil = min($zbozi, (float) $v['zustatek'] + $aplikovano);
    }
    return round(max(0.0, min($cil, $zbozi)), 2);
}

/** Kontrola, že voucher jde použít v kanálu (a pro odběratele). Vyhodí RuntimeException s textem pro uživatele. */
function voucher_validuj(array $v, string $kanal, ?int $odberatelId): void {
    if ($v['stav'] === 'zruseny') throw new RuntimeException('Voucher je zrušený');
    if (!empty($v['platnost_do']) && $v['platnost_do'] < date('Y-m-d')) throw new RuntimeException('Voucher expiroval ' . date('d.m.Y', strtotime($v['platnost_do'])));
    if (!in_array($kanal, voucher_kanaly($v), true)) {
        $kde = implode(', ', array_map(fn($k) => VOUCHER_KANALY[$k], voucher_kanaly($v)));
        throw new RuntimeException('Tento voucher nelze uplatnit zde (' . (VOUCHER_KANALY[$kanal] ?? $kanal) . '). Platí jen: ' . $kde);
    }
    if (!empty($v['odberatel_id']) && (int) $v['odberatel_id'] !== (int) $odberatelId) {
        throw new RuntimeException($kanal === 'web' ? 'Tento voucher je vystavený na jméno odběratele — online ho použít nelze'
                                                    : 'Voucher je vystavený pro jiného odběratele (' . ($v['odberatel_nazev'] ?: '#' . $v['odberatel_id']) . ')');
    }
    if ($v['typ'] === 'sleva' && $v['stav'] === 'vycerpany') throw new RuntimeException('Slevový voucher už byl použit');
    if ($v['typ'] !== 'sleva' && (float) $v['zustatek'] <= 0.004) throw new RuntimeException('Voucher je již vyčerpaný');
}

/** Odhad pro košík (bez zápisu): ['kod','chovani','odecet','popis']. Vyhodí RuntimeException. */
function voucher_odhad(PDO $pdo, string $kod, string $kanal, ?int $odberatelId, float $zbozi): array {
    voucher_ensure_schema($pdo);
    $kod = strtoupper(trim($kod));
    if ($kod === '') throw new RuntimeException('Zadejte kód');
    $vs = $pdo->prepare("SELECT * FROM vouchery WHERE kod = :k LIMIT 1");
    $vs->execute(['k' => $kod]);
    $v = $vs->fetch(PDO::FETCH_ASSOC);
    if (!$v) throw new RuntimeException('Kód „' . $kod . '" neplatí');
    voucher_validuj($v, $kanal, $odberatelId);
    $odecet = voucher_cil($v, $zbozi);
    $popis = $v['typ'] === 'sleva'
        ? 'Sleva ' . rtrim(rtrim(number_format((float) $v['sleva_pct'], 2, ',', ''), '0'), ',') . ' %' . (!empty($v['sleva_max_kc']) ? ' (max. ' . number_format((float) $v['sleva_max_kc'], 0, ',', ' ') . ' Kč)' : '')
        : ($v['typ'] === 'darkova_karta' ? 'Dárková karta' : 'Voucher') . ' — zůstatek ' . number_format((float) $v['zustatek'], 2, ',', ' ') . ' Kč';
    return ['kod' => $kod, 'chovani' => voucher_chovani($v), 'odecet' => $odecet, 'popis' => $popis];
}

function voucher_objednavka_sync(PDO $pdo, int $oid, string $kdo, bool $zrusit = false, ?int $vid = null): float {
    voucher_ensure_schema($pdo);
    $vid = $vid ?: voucher_objednavka_id($pdo, $oid);
    if (!$vid) return 0.0;
    $st = $pdo->prepare("SELECT * FROM vouchery WHERE id = :id FOR UPDATE");
    $st->execute(['id' => $vid]);
    $v = $st->fetch(PDO::FETCH_ASSOC);
    if (!$v) return 0.0;

    $ap = $pdo->prepare("SELECT COALESCE(-SUM(castka), 0) FROM voucher_pohyby WHERE voucher_id = :v AND objednavka_id = :o");
    $ap->execute(['v' => $vid, 'o' => $oid]);
    $aplikovano = round((float) $ap->fetchColumn(), 2);

    $zb = $pdo->prepare("SELECT sazba_dph, SUM(mnozstvi * cena_bez_dph * (1 + sazba_dph / 100)) AS s
                         FROM objednavky_polozky WHERE objednavka_id = :o AND voucher_id IS NULL GROUP BY sazba_dph");
    $zb->execute(['o' => $oid]);
    $poSazbach = [];
    foreach ($zb->fetchAll(PDO::FETCH_ASSOC) as $r) {
        if ((float) $r['s'] > 0.004) $poSazbach[(string) (float) $r['sazba_dph']] = (float) $r['s'];
    }
    $zbozi = round(array_sum($poSazbach), 2);

    $cil = $zrusit ? 0.0 : voucher_cil($v, $zbozi, $aplikovano);
    $delta = round($cil - $aplikovano, 2);

    $ob = $pdo->prepare("SELECT cislo FROM objednavky WHERE id = :o");
    $ob->execute(['o' => $oid]);
    $cislo = (string) ($ob->fetchColumn() ?: '');

    if (abs($delta) >= 0.005) {
        $pevny = in_array($v['stav'], ['zruseny', 'expirovany'], true); // vrácení neoživí zrušený voucher
        if ($v['typ'] === 'sleva') {
            $zust = 0.0;
            $stav = $pevny ? $v['stav'] : ($cil > 0 ? 'vycerpany' : 'aktivni');
            $pdo->prepare("UPDATE vouchery SET stav = :s, posledni_pouziti = NOW() WHERE id = :id")
                ->execute(['s' => $stav, 'id' => $vid]);
        } else {
            $zust = round((float) $v['zustatek'] - $delta, 2);
            $stav = $pevny ? $v['stav'] : ($zust <= 0.001 ? 'vycerpany' : 'aktivni');
            $pdo->prepare("UPDATE vouchery SET zustatek = :z, stav = :s, posledni_pouziti = NOW() WHERE id = :id")
                ->execute(['z' => $zust, 's' => $stav, 'id' => $vid]);
        }
        $pdo->prepare("INSERT INTO voucher_pohyby (voucher_id, typ, castka, zustatek_po, doklad, kdo, objednavka_id)
                       VALUES (:v, :t, :c, :z, :d, :k, :o)")
            ->execute(['v' => $vid, 't' => $delta > 0 ? 'uplatneni' : 'vraceni', 'c' => -$delta, 'z' => $zust,
                       'd' => mb_substr($cislo, 0, 40), 'k' => mb_substr($kdo, 0, 120), 'o' => $oid]);
    }

    // Řádky voucheru: smaž a vlož podle cíle
    $pdo->prepare("DELETE FROM objednavky_polozky WHERE objednavka_id = :o AND voucher_id IS NOT NULL")->execute(['o' => $oid]);
    if ($cil > 0) {
        $ins = $pdo->prepare("INSERT INTO objednavky_polozky (objednavka_id, vyrobek_id, vyrobek_nazev, jednotka, mnozstvi, cena_bez_dph, sazba_dph, voucher_id)
                              VALUES (:o, NULL, :n, 'ks', 1, :c, :s, :v)");
        if (voucher_chovani($v) === 'platba') {
            $ins->execute(['o' => $oid, 'n' => 'Uhrazeno voucherem ' . $v['kod'], 'c' => -$cil, 's' => 0, 'v' => $vid]);
        } else {
            $pct = $v['typ'] === 'sleva' ? ' ' . rtrim(rtrim(number_format((float) $v['sleva_pct'], 2, ',', ''), '0'), ',') . ' %' : '';
            $zbyva = $cil;
            $sazby = array_keys($poSazbach);
            foreach ($sazby as $i => $s) {
                $dil = $i === count($sazby) - 1 ? $zbyva : round($cil * $poSazbach[$s] / $zbozi, 2);
                $zbyva = round($zbyva - $dil, 2);
                $ins->execute([
                    'o' => $oid, 'n' => 'Sleva' . $pct . ' — voucher ' . $v['kod'] . (count($sazby) > 1 ? " (DPH $s %)" : ''),
                    'c' => -round($dil / (1 + (float) $s / 100), 2), 's' => (float) $s, 'v' => $vid,
                ]);
            }
        }
    }

    // Hlavička objednávky (stejný výpočet jako prepocitat_objednavku v admin_objednavky.php)
    $sum = $pdo->prepare("SELECT COALESCE(SUM(cena_bez_dph * mnozstvi), 0) AS b, COALESCE(SUM(cena_bez_dph * mnozstvi * sazba_dph / 100), 0) AS d
                          FROM objednavky_polozky WHERE objednavka_id = :o");
    $sum->execute(['o' => $oid]);
    $s = $sum->fetch(PDO::FETCH_ASSOC);
    $pdo->prepare("UPDATE objednavky SET castka_bez_dph = :b, castka_dph = :d, castka_celkem = :c WHERE id = :o")
        ->execute(['b' => round((float) $s['b'], 2), 'd' => round((float) $s['d'], 2), 'c' => round((float) $s['b'] + (float) $s['d'], 2), 'o' => $oid]);
    return $cil;
}

/**
 * Uplatní voucher (kód) na objednávku. Kontroly: objednávka (ne storno, ne z pokladny, bez DL,
 * bez jiného voucheru), voucher (existuje, aktivní, platný, povolený kanál, správný odběratel,
 * má co čerpat). Při chybě vyhodí RuntimeException se zprávou pro uživatele.
 */
function voucher_objednavka_uplatnit(PDO $pdo, int $oid, string $kod, string $kanal, string $kdo): array {
    voucher_ensure_schema($pdo);
    $kod = strtoupper(trim($kod));
    if ($kod === '') throw new RuntimeException('Chybí kód voucheru');

    $os = $pdo->prepare("SELECT id, cislo, stav, puvod, odberatel_id FROM objednavky WHERE id = :o FOR UPDATE");
    $os->execute(['o' => $oid]);
    $o = $os->fetch(PDO::FETCH_ASSOC);
    if (!$o) throw new RuntimeException('Objednávka nenalezena');
    if ($o['stav'] === 'zrusena') throw new RuntimeException('Objednávka je stornovaná');
    $kan = kanaly_config($pdo)[$o['puvod'] ?? ''] ?? null;
    // pokladní objednávky řeší voucher při placení na pokladně — výjimka: zákazník ho zadá sám ve vlastním kanálu (online objednávka → 'web')
    if ($kan && !empty($kan['pokladni']) && ($o['puvod'] ?? '') !== $kanal) throw new RuntimeException('Objednávka z pokladny / platformy — voucher se uplatňuje při placení na pokladně');
    $dl = $pdo->prepare("SELECT COUNT(*) FROM dodaci_listy WHERE objednavka_id = :o");
    $dl->execute(['o' => $oid]);
    if ((int) $dl->fetchColumn() > 0) throw new RuntimeException('Objednávka už má dodací list — voucher uplatněte před jeho vystavením');

    $vs = $pdo->prepare("SELECT * FROM vouchery WHERE kod = :k LIMIT 1 FOR UPDATE");
    $vs->execute(['k' => $kod]);
    $v = $vs->fetch(PDO::FETCH_ASSOC);
    if (!$v) throw new RuntimeException('Voucher „' . $kod . '" neexistuje');
    $stary = voucher_objednavka_id($pdo, $oid);
    if ($stary === (int) $v['id']) throw new RuntimeException('Tento voucher už je na objednávce uplatněný');
    if ($stary) throw new RuntimeException('Na objednávce už je jiný voucher — nejdřív ho odeberte');
    voucher_validuj($v, $kanal, (int) $o['odberatel_id']);

    $uplatneno = voucher_objednavka_sync($pdo, $oid, $kdo, false, (int) $v['id']);
    if ($uplatneno <= 0) throw new RuntimeException('Objednávka nemá žádné zboží, ze kterého by šlo slevu odečíst');
    return ['kod' => $kod, 'uplatneno' => $uplatneno, 'chovani' => voucher_chovani($v)];
}
