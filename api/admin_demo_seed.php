<?php
/**
 * 🎬 DEMO DATA SEEDER — One-click "Naplnit ukázkovými daty".
 *
 * POST /api/admin_demo_seed.php?action=preview   → vrátí counts co se vytvoří
 * POST /api/admin_demo_seed.php?action=apply     → naplní DB (jen pokud je prázdná)
 * POST /api/admin_demo_seed.php?action=clear     → SMAŽE všechna demo data (jen super admin, opatrně!)
 *
 * Bezpečnostní pojistka: nikdy nepřepíše existující záznamy
 * (insertne jen pokud daný počet < threshold).
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/_admin_auth.php';
cors_headers();
require_super_admin();
header('Content-Type: application/json; charset=UTF-8');

$pdo = db();
$action = $_GET['action'] ?? '';

// ════════════════════════════════════════════════════════════
// DEMO DATA — sample sets
// ════════════════════════════════════════════════════════════

function demo_categories(): array {
    return [
        ['nazev' => 'Pečivo',           'ikona' => '🥖', 'poradi' => 1],
        ['nazev' => 'Chleby',           'ikona' => '🍞', 'poradi' => 2],
        ['nazev' => 'Sladké pečivo',    'ikona' => '🥐', 'poradi' => 3],
        ['nazev' => 'Sendviče & svačiny','ikona' => '🥪', 'poradi' => 4],
        ['nazev' => 'Nápoje',           'ikona' => '☕', 'poradi' => 5],
    ];
}

function demo_products(array $kategorieIds): array {
    return [
        ['nazev' => 'Rohlík klasik',           'cislo' => 'RK01', 'cena_bez_dph' => 2.50, 'hmotnost_g' => 50,  'kategorie' => 'Pečivo',           'dph' => 12],
        ['nazev' => 'Veka 500 g',              'cislo' => 'VK01', 'cena_bez_dph' => 22.00,'hmotnost_g' => 500, 'kategorie' => 'Pečivo',           'dph' => 12],
        ['nazev' => 'Bageta sezamová',         'cislo' => 'BS01', 'cena_bez_dph' => 35.00,'hmotnost_g' => 250, 'kategorie' => 'Pečivo',           'dph' => 12],
        ['nazev' => 'Chléb konzumní 1 kg',     'cislo' => 'CH01', 'cena_bez_dph' => 45.00,'hmotnost_g' => 1000,'kategorie' => 'Chleby',           'dph' => 12],
        ['nazev' => 'Žitný chléb 1 kg',        'cislo' => 'CH02', 'cena_bez_dph' => 55.00,'hmotnost_g' => 1000,'kategorie' => 'Chleby',           'dph' => 12],
        ['nazev' => 'Croissant máslový',       'cislo' => 'CR01', 'cena_bez_dph' => 28.00,'hmotnost_g' => 70,  'kategorie' => 'Sladké pečivo',    'dph' => 12],
        ['nazev' => 'Šáteček s tvarohem',      'cislo' => 'SP01', 'cena_bez_dph' => 18.00,'hmotnost_g' => 80,  'kategorie' => 'Sladké pečivo',    'dph' => 12],
        ['nazev' => 'Záviny ořechové',         'cislo' => 'SP02', 'cena_bez_dph' => 32.00,'hmotnost_g' => 90,  'kategorie' => 'Sladké pečivo',    'dph' => 12],
        ['nazev' => 'Sendvič šunka-sýr',       'cislo' => 'SE01', 'cena_bez_dph' => 65.00,'hmotnost_g' => 180, 'kategorie' => 'Sendviče & svačiny','dph' => 12],
        ['nazev' => 'Káva americano',          'cislo' => 'NA01', 'cena_bez_dph' => 35.00,'hmotnost_g' => null,'kategorie' => 'Nápoje',           'dph' => 12],
    ];
}

function demo_customers(): array {
    // 🆕 v2.0.77 — John Doe jako prvotřídní demo zákazník (univerzální placeholder).
    // Plné údaje včetně login_email/heslo pro B2B portal testing.
    return [
        [
            'cislo' => 'O0001', 'nazev' => 'John Doe s.r.o.',
            'ico' => '11111111', 'dic' => 'CZ11111111',
            'ulice' => 'Demo 1', 'mesto' => 'Praha', 'psc' => '11000',
            'email' => 'john.doe@example.com', 'telefon' => '+420 777 111 111',
            'typ' => 'bistro',
            'login_email' => 'odberatel@demo.cz', 'heslo' => 'demo1234',
            'is_primary_demo' => true,  // marker pro auto-create order/DL/faktury
        ],
        ['cislo' => 'O0002', 'nazev' => 'John Doe Bakery & Café',  'ico' => '22222222', 'mesto' => 'Brno',       'email' => 'bakery@johndoe.cz', 'typ' => 'kavarna'],
        ['cislo' => 'O0003', 'nazev' => 'John Doe Hotel',          'ico' => '33333333', 'mesto' => 'Olomouc',    'email' => 'hotel@johndoe.cz',  'typ' => 'hotel'],
        ['cislo' => 'O0004', 'nazev' => 'John Doe Bistro',         'ico' => '44444444', 'mesto' => 'Plzeň',      'email' => 'bistro@johndoe.cz', 'typ' => 'bistro'],
        ['cislo' => 'O0005', 'nazev' => 'John Doe Catering',       'ico' => '55555555', 'mesto' => 'České Budějovice', 'email' => 'catering@johndoe.cz', 'typ' => 'jidelna'],
    ];
}

function demo_suroviny(): array {
    return [
        ['nazev' => 'Mouka pšeničná hladká', 'jednotka' => 'kg', 'cena_baleni' => 350, 'obsah_baleni' => 25, 'alergen' => 'lepek'],
        ['nazev' => 'Mouka žitná',           'jednotka' => 'kg', 'cena_baleni' => 320, 'obsah_baleni' => 25, 'alergen' => 'lepek'],
        ['nazev' => 'Cukr krystal',          'jednotka' => 'kg', 'cena_baleni' => 380, 'obsah_baleni' => 25],
        ['nazev' => 'Sůl',                   'jednotka' => 'kg', 'cena_baleni' => 65,  'obsah_baleni' => 25],
        ['nazev' => 'Droždí čerstvé',        'jednotka' => 'kg', 'cena_baleni' => 95,  'obsah_baleni' => 1],
        ['nazev' => 'Vejce slepičí',         'jednotka' => 'ks', 'cena_baleni' => 240, 'obsah_baleni' => 60, 'alergen' => 'vejce'],
        ['nazev' => 'Mléko polotučné',       'jednotka' => 'l',  'cena_baleni' => 28,  'obsah_baleni' => 1,  'alergen' => 'mléko'],
        ['nazev' => 'Máslo',                 'jednotka' => 'kg', 'cena_baleni' => 220, 'obsah_baleni' => 1,  'alergen' => 'mléko'],
        ['nazev' => 'Olej slunečnicový',     'jednotka' => 'l',  'cena_baleni' => 250, 'obsah_baleni' => 10],
        ['nazev' => 'Mák modrý',             'jednotka' => 'kg', 'cena_baleni' => 480, 'obsah_baleni' => 5,  'alergen' => 'sezamová zrna'],
    ];
}

// ════════════════════════════════════════════════════════════
// PREVIEW — co bude vytvořeno
// ════════════════════════════════════════════════════════════
if ($action === 'preview') {
    $stats = [];
    try {
        $stats['kategorie'] = (int) $pdo->query("SELECT COUNT(*) FROM kategorie_vyrobku")->fetchColumn();
        $stats['vyrobky']   = (int) $pdo->query("SELECT COUNT(*) FROM vyrobky")->fetchColumn();
        $stats['odberatele']= (int) $pdo->query("SELECT COUNT(*) FROM odberatele")->fetchColumn();
        $stats['suroviny']  = (int) $pdo->query("SELECT COUNT(*) FROM suroviny")->fetchColumn();
    } catch (Throwable $e) { $stats = ['error' => $e->getMessage()]; }

    json_response([
        'current'  => $stats,
        'will_add' => [
            'kategorie'    => count(demo_categories()),
            'vyrobky'      => count(demo_products([])),
            'odberatele'   => count(demo_customers()),
            'suroviny'     => count(demo_suroviny()),
            // 🆕 v2.0.77 — John Doe ekosystém (auto-create)
            'objednavky'   => 1,
            'dodaci_listy' => 1,
            'faktury'      => 1,
            '_john_doe'    => 'John Doe s.r.o. + objednávka + DL + faktura (komplet test data flow)',
        ],
    ]);
}

// ════════════════════════════════════════════════════════════
// APPLY — naplň DB demo daty
// ════════════════════════════════════════════════════════════
if ($action === 'apply') {
    $stats = ['kategorie' => 0, 'vyrobky' => 0, 'odberatele' => 0, 'suroviny' => 0, 'errors' => []];

    $pdo->beginTransaction();
    try {
        // 1. Kategorie
        $katIds = [];
        foreach (demo_categories() as $k) {
            try {
                $cnt = $pdo->prepare("SELECT id FROM kategorie_vyrobku WHERE nazev = :n");
                $cnt->execute(['n' => $k['nazev']]);
                $existId = $cnt->fetchColumn();
                if ($existId) {
                    $katIds[$k['nazev']] = (int) $existId;
                    continue;
                }
                $cols = $pdo->query("SHOW COLUMNS FROM kategorie_vyrobku")->fetchAll(PDO::FETCH_COLUMN);
                $hasIkona = in_array('ikona', $cols, true);
                $hasPoradi = in_array('poradi', $cols, true);
                $sql = "INSERT INTO kategorie_vyrobku (nazev" . ($hasIkona ? ", ikona" : "") . ($hasPoradi ? ", poradi" : "") . ") VALUES (:n" . ($hasIkona ? ", :i" : "") . ($hasPoradi ? ", :p" : "") . ")";
                $params = ['n' => $k['nazev']];
                if ($hasIkona) $params['i'] = $k['ikona'];
                if ($hasPoradi) $params['p'] = $k['poradi'];
                $pdo->prepare($sql)->execute($params);
                $katIds[$k['nazev']] = (int) $pdo->lastInsertId();
                $stats['kategorie']++;
            } catch (Throwable $e) { $stats['errors'][] = "Kategorie {$k['nazev']}: " . $e->getMessage(); }
        }

        // 2. Jednotky default (kus) a sazba DPH 12% — pro nové výrobky
        $jId = (int) $pdo->query("SELECT id FROM jednotky LIMIT 1")->fetchColumn();
        if (!$jId) {
            try {
                $pdo->exec("INSERT INTO jednotky (kod, nazev) VALUES ('ks', 'kus')");
                $jId = (int) $pdo->lastInsertId();
            } catch (Throwable $e) { /* tabulka jednotky možná nemá tyto sloupce */ }
        }
        $sId = (int) $pdo->query("SELECT id FROM sazby_dph WHERE sazba = 12 LIMIT 1")->fetchColumn();
        if (!$sId) {
            try {
                $pdo->exec("INSERT INTO sazby_dph (sazba, nazev) VALUES (12, '12 %')");
                $sId = (int) $pdo->lastInsertId();
            } catch (Throwable $e) { /* ignore */ }
        }
        if (!$sId) $sId = (int) $pdo->query("SELECT id FROM sazby_dph LIMIT 1")->fetchColumn();

        // 3. Výrobky
        foreach (demo_products($katIds) as $p) {
            try {
                $cnt = $pdo->prepare("SELECT 1 FROM vyrobky WHERE cislo = :c OR nazev = :n");
                $cnt->execute(['c' => $p['cislo'], 'n' => $p['nazev']]);
                if ($cnt->fetchColumn()) continue;
                $katId = $katIds[$p['kategorie']] ?? null;
                $pdo->prepare("
                    INSERT INTO vyrobky (cislo, nazev, kategorie_id, cena_bez_dph, hmotnost_g, jednotka_id, sazba_dph_id, aktivni)
                    VALUES (:c, :n, :k, :cn, :h, :j, :s, 1)
                ")->execute([
                    'c' => $p['cislo'], 'n' => $p['nazev'], 'k' => $katId,
                    'cn' => $p['cena_bez_dph'], 'h' => $p['hmotnost_g'],
                    'j' => $jId, 's' => $sId,
                ]);
                $stats['vyrobky']++;
            } catch (Throwable $e) { $stats['errors'][] = "Výrobek {$p['nazev']}: " . $e->getMessage(); }
        }

        // 4. Odběratelé (s plnými údaji + login pro primárního John Doe)
        $johnDoeId = null;
        foreach (demo_customers() as $o) {
            try {
                $cnt = $pdo->prepare("SELECT id FROM odberatele WHERE cislo = :c OR nazev = :n");
                $cnt->execute(['c' => $o['cislo'], 'n' => $o['nazev']]);
                $existId = $cnt->fetchColumn();
                if ($existId) {
                    if (!empty($o['is_primary_demo'])) $johnDoeId = (int) $existId;
                    continue;
                }
                // Detekuj které sloupce tabulka má (defenzivně)
                $cols = $pdo->query("SHOW COLUMNS FROM odberatele")->fetchAll(PDO::FETCH_COLUMN);
                $colSet = array_flip($cols);

                $fields = ['cislo', 'nazev', 'ico', 'mesto', 'email', 'typ'];
                $values = [
                    'cislo' => $o['cislo'], 'nazev' => $o['nazev'], 'ico' => $o['ico'],
                    'mesto' => $o['mesto'], 'email' => $o['email'], 'typ' => $o['typ'],
                ];

                // Volitelně přidej další sloupce pokud existují v tabulce
                $optional = [
                    'dic' => $o['dic'] ?? null,
                    'ulice' => $o['ulice'] ?? null,
                    'psc' => $o['psc'] ?? null,
                    'telefon' => $o['telefon'] ?? null,
                    'login_email' => $o['login_email'] ?? null,
                    'splatnost_dni' => 14,
                ];
                foreach ($optional as $col => $val) {
                    if (isset($colSet[$col]) && $val !== null) {
                        $fields[] = $col;
                        $values[$col] = $val;
                    }
                }
                // Heslo (bcrypt) — pokud má login_email
                if (!empty($o['heslo']) && isset($colSet['heslo_hash']) && isset($colSet['login_email'])) {
                    $fields[] = 'heslo_hash';
                    $values['heslo_hash'] = password_hash($o['heslo'], PASSWORD_BCRYPT);
                }

                $cols_sql = implode(', ', $fields);
                $params_sql = ':' . implode(', :', $fields);
                $pdo->prepare("INSERT INTO odberatele ({$cols_sql}) VALUES ({$params_sql})")
                    ->execute($values);
                $newId = (int) $pdo->lastInsertId();
                if (!empty($o['is_primary_demo'])) $johnDoeId = $newId;
                $stats['odberatele']++;
            } catch (Throwable $e) { $stats['errors'][] = "Odběratel {$o['nazev']}: " . $e->getMessage(); }
        }

        // 🆕 v2.0.77 — Auto-create John Doe ekosystém: objednávka → DL → faktura
        $stats['objednavky'] = 0;
        $stats['dodaci_listy'] = 0;
        $stats['faktury'] = 0;
        if ($johnDoeId) {
            try {
                // Vyber 4 výrobky z DB pro John Doe objednávku
                $vyrobkyForOrder = $pdo->query("
                    SELECT v.id, v.nazev, v.cislo, v.cena_bez_dph, v.jednotka_id, v.sazba_dph_id,
                           j.kod AS jednotka_kod, s.sazba AS dph_sazba
                    FROM vyrobky v
                    LEFT JOIN jednotky j ON j.id = v.jednotka_id
                    LEFT JOIN sazby_dph s ON s.id = v.sazba_dph_id
                    WHERE v.aktivni = 1
                    ORDER BY v.id LIMIT 4
                ")->fetchAll();

                if (!empty($vyrobkyForOrder)) {
                    $dnes = date('Y-m-d');
                    $zitra = date('Y-m-d', strtotime('+1 day'));
                    $rok = date('Y');

                    // ─── 1. Objednávka ────────────────────────────
                    // 🐛 fix v2.9.182 — dalsi_cislo() místo hardcoded 'OBJ-2026-0001'.
                    $cisloObj = dalsi_cislo($pdo, 'OBJ', (int) $rok);
                    $cnt = $pdo->prepare("SELECT 1 FROM objednavky WHERE cislo = :c");
                    $cnt->execute(['c' => $cisloObj]);
                    if (!$cnt->fetchColumn()) {
                        // Mnozstvi randomly 2-12 per item
                        $polozkyData = [];
                        $bezDph = 0; $dphSum = 0;
                        foreach ($vyrobkyForOrder as $idx => $v) {
                            $mn = [3, 5, 7, 10][$idx % 4];
                            $cena = (float) $v['cena_bez_dph'];
                            $dph = (float) ($v['dph_sazba'] ?? 12);
                            $polozkyData[] = [
                                'vyrobek_id' => $v['id'], 'nazev' => $v['nazev'],
                                'mnozstvi' => $mn, 'jednotka' => $v['jednotka_kod'] ?? 'ks',
                                'cena_bez_dph' => $cena, 'sazba_dph' => $dph,
                            ];
                            $bezDph += $cena * $mn;
                            $dphSum += $cena * $mn * ($dph / 100);
                        }
                        $celkem = $bezDph + $dphSum;

                        $pdo->prepare("
                            INSERT INTO objednavky (cislo, typ, odberatel_id, datum_objednani, datum_dodani, stav, castka_bez_dph, castka_dph, castka_celkem, poznamka)
                            VALUES (:c, 'standard', :oid, :do, :dd, 'nova', :bdz, :dph, :ce, :pz)
                        ")->execute([
                            'c' => $cisloObj, 'oid' => $johnDoeId,
                            'do' => $dnes, 'dd' => $zitra,
                            'bdz' => round($bezDph, 2), 'dph' => round($dphSum, 2), 'ce' => round($celkem, 2),
                            'pz' => 'Ukázková objednávka pro John Doe (demo seed).',
                        ]);
                        $objId = (int) $pdo->lastInsertId();
                        foreach ($polozkyData as $p) {
                            $pdo->prepare("
                                INSERT INTO objednavky_polozky (objednavka_id, vyrobek_id, vyrobek_nazev, mnozstvi, jednotka, cena_bez_dph, sazba_dph)
                                VALUES (:oid, :vid, :vn, :mn, :j, :cb, :sd)
                            ")->execute([
                                'oid' => $objId, 'vid' => $p['vyrobek_id'], 'vn' => $p['nazev'],
                                'mn' => $p['mnozstvi'], 'j' => $p['jednotka'],
                                'cb' => $p['cena_bez_dph'], 'sd' => $p['sazba_dph'],
                            ]);
                        }
                        $stats['objednavky']++;

                        // ─── 2. Dodací list (z objednávky) ────────
                        // 🐛 fix v2.9.182 — použít dalsi_cislo() místo hardcoded
                        // 'DL-2026-0001'. Předtím seed kolidovalo s nově generovanými
                        // čísly (cislovani.posledni se zvyšuje při generaci, takže by
                        // dalsi_cislo() vrátil 'DL-2026-0002' a hardcoded by konflikt).
                        $cisloDl = dalsi_cislo($pdo, 'DL', (int) $rok);
                        $pdo->prepare("
                            INSERT INTO dodaci_listy (cislo, objednavka_id, odberatel_id, datum_vystaveni, datum_dodani, castka_celkem, poznamka)
                            VALUES (:c, :oid, :odb, :dv, :dd, :ce, :pz)
                        ")->execute([
                            'c' => $cisloDl, 'oid' => $objId, 'odb' => $johnDoeId,
                            'dv' => $dnes, 'dd' => $zitra, 'ce' => round($celkem, 2),
                            'pz' => 'Auto-vygenerováno z objednávky ' . $cisloObj . ' (demo seed).',
                        ]);
                        $dlId = (int) $pdo->lastInsertId();
                        foreach ($polozkyData as $p) {
                            $pdo->prepare("
                                INSERT INTO dodaci_list_polozky (dodaci_list_id, vyrobek_id, vyrobek_nazev, mnozstvi, jednotka, cena_bez_dph, sazba_dph)
                                VALUES (:dl, :vid, :vn, :mn, :j, :cb, :sd)
                            ")->execute([
                                'dl' => $dlId, 'vid' => $p['vyrobek_id'], 'vn' => $p['nazev'],
                                'mn' => $p['mnozstvi'], 'j' => $p['jednotka'],
                                'cb' => $p['cena_bez_dph'], 'sd' => $p['sazba_dph'],
                            ]);
                        }
                        $stats['dodaci_listy']++;

                        // ─── 3. Faktura (z DL) ────────────────────
                        // 🐛 fix v2.9.182 — dalsi_cislo() místo hardcoded 'FA-2026-0001'.
                        $cisloFa = dalsi_cislo($pdo, 'FA', (int) $rok);
                        $datumSplat = date('Y-m-d', strtotime('+14 days'));
                        $varSym = preg_replace('/\D/', '', $cisloFa); // VS = jen číslice z čísla
                        $pdo->prepare("
                            INSERT INTO faktury (cislo, odberatel_id, datum_vystaveni, datum_splatnosti, datum_dph, castka_bez_dph, castka_dph, castka_celkem, variabilni_symbol, poznamka)
                            VALUES (:c, :odb, :dv, :ds, :ddph, :bdz, :dph, :ce, :vs, :pz)
                        ")->execute([
                            'c' => $cisloFa, 'odb' => $johnDoeId,
                            'dv' => $dnes, 'ds' => $datumSplat, 'ddph' => $dnes,
                            'bdz' => round($bezDph, 2), 'dph' => round($dphSum, 2), 'ce' => round($celkem, 2),
                            'vs' => $varSym,
                            'pz' => 'Ukázková faktura pro John Doe — vystavena z dodacího listu ' . $cisloDl . ' (demo seed).',
                        ]);
                        $faId = (int) $pdo->lastInsertId();
                        foreach ($polozkyData as $idx => $p) {
                            $pdo->prepare("
                                INSERT INTO faktura_polozky (faktura_id, vyrobek_id, vyrobek_nazev, mnozstvi, jednotka, cena_bez_dph, sazba_dph, poradi)
                                VALUES (:fa, :vid, :vn, :mn, :j, :cb, :sd, :po)
                            ")->execute([
                                'fa' => $faId, 'vid' => $p['vyrobek_id'], 'vn' => $p['nazev'],
                                'mn' => $p['mnozstvi'], 'j' => $p['jednotka'],
                                'cb' => $p['cena_bez_dph'], 'sd' => $p['sazba_dph'], 'po' => $idx + 1,
                            ]);
                        }
                        // Link DL → Faktura
                        try {
                            $pdo->prepare("INSERT INTO faktury_dodaci_listy (faktura_id, dodaci_list_id) VALUES (:f, :d)")
                                ->execute(['f' => $faId, 'd' => $dlId]);
                            $pdo->prepare("UPDATE dodaci_listy SET fakturovano = 1 WHERE id = :id")
                                ->execute(['id' => $dlId]);
                        } catch (Throwable $e) { /* link table optional */ }
                        $stats['faktury']++;
                    }
                }
            } catch (Throwable $e) {
                $stats['errors'][] = 'John Doe ekosystém: ' . $e->getMessage();
            }
        }

        // 5. Suroviny
        foreach (demo_suroviny() as $s) {
            try {
                $cnt = $pdo->prepare("SELECT 1 FROM suroviny WHERE nazev = :n");
                $cnt->execute(['n' => $s['nazev']]);
                if ($cnt->fetchColumn()) continue;
                $pdo->prepare("
                    INSERT INTO suroviny (nazev, jednotka, cena_baleni, obsah_baleni, alergen, aktivni)
                    VALUES (:n, :j, :c, :o, :a, 1)
                ")->execute([
                    'n' => $s['nazev'], 'j' => $s['jednotka'],
                    'c' => $s['cena_baleni'] ?? null, 'o' => $s['obsah_baleni'] ?? null,
                    'a' => $s['alergen'] ?? null,
                ]);
                $stats['suroviny']++;
            } catch (Throwable $e) { $stats['errors'][] = "Surovina {$s['nazev']}: " . $e->getMessage(); }
        }

        $pdo->commit();
        json_response($stats);
    } catch (Throwable $e) {
        $pdo->rollBack();
        json_error('Seed selhal: ' . $e->getMessage(), 500);
    }
}

if ($action === 'clear') {
    // POZOR: smaže VŠECHNA data (ne jen demo) — používat opatrně.
    // Vyžaduje explicitní confirm parametr.
    $d = json_input();
    if (($d['confirm'] ?? '') !== 'SMAZAT VSE') {
        json_error('Vyžadováno confirm="SMAZAT VSE"', 400);
    }
    try {
        $pdo->exec("SET FOREIGN_KEY_CHECKS=0");
        foreach (['objednavky_polozky', 'objednavky', 'dodaci_list_polozky', 'dodaci_listy',
                  'faktura_polozky', 'faktury', 'vyrobni_list_polozky', 'vyrobni_listy',
                  'mista_dodani', 'cenove_skupiny_slevy', 'vyrobek_suroviny',
                  'vyrobky', 'odberatele', 'suroviny', 'sklad_pohyby', 'kategorie_vyrobku'] as $t) {
            try { $pdo->exec("DELETE FROM `$t`"); } catch (Throwable $e) { /* ignore */ }
        }
        $pdo->exec("SET FOREIGN_KEY_CHECKS=1");
        json_response(['ok' => true, 'cleared' => true]);
    } catch (Throwable $e) {
        json_error('Clear selhal: ' . $e->getMessage(), 500);
    }
}

json_error('Neznámá akce (preview|apply|clear)', 404);
