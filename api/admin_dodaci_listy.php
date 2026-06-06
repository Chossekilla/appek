<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/_admin_auth.php';
cors_headers();
require_admin();

$method = $_SERVER['REQUEST_METHOD'];
$pdo = db();

// 🔄 v2.9.175 — DDL přesunut do _schema_lib.php (sdílený helper, idempotentní).
require_once __DIR__ . '/_schema_lib.php';
ensure_dodaci_list_polozky_schema($pdo);
ensure_dodaci_listy_schema($pdo);

try {
    if ($method === 'GET' && isset($_GET['id'])) {
        // Detail jednoho DL — včetně položek a navázaných faktur
        $id = (int) $_GET['id'];

        $stmt = $pdo->prepare("
            SELECT dl.*,
                   o.cislo AS objednavka_cislo,
                   o.id AS o_id,
                   o.datum_dodani AS obj_datum_dodani,
                   od.nazev AS odberatel_nazev,
                   od.ico AS odberatel_ico,
                   od.dic AS odberatel_dic,
                   od.ulice AS odberatel_ulice,
                   od.mesto AS odberatel_mesto,
                   od.psc AS odberatel_psc,
                   md.nazev AS misto_nazev,
                   md.ulice AS misto_ulice,
                   md.mesto AS misto_mesto,
                   md.psc AS misto_psc
            FROM dodaci_listy dl
            LEFT JOIN objednavky o ON o.id = dl.objednavka_id
            JOIN odberatele od ON od.id = dl.odberatel_id
            LEFT JOIN mista_dodani md ON md.id = dl.misto_dodani_id
            WHERE dl.id = :id
        ");
        $stmt->execute(['id' => $id]);
        $dl = $stmt->fetch();
        if (!$dl) {
            json_error('Dodací list nenalezen', 404);
        }

        // Položky
        $stmt = $pdo->prepare("
            SELECT * FROM dodaci_list_polozky
            WHERE dodaci_list_id = :id
            ORDER BY id
        ");
        $stmt->execute(['id' => $id]);
        $dl['polozky'] = $stmt->fetchAll();

        // Navázané faktury
        $stmt = $pdo->prepare("
            SELECT f.id, f.cislo, f.datum_vystaveni, f.datum_splatnosti, f.castka_celkem
            FROM faktury_dodaci_listy fdl
            JOIN faktury f ON f.id = fdl.faktura_id
            WHERE fdl.dodaci_list_id = :id
            ORDER BY f.datum_vystaveni DESC
        ");
        $stmt->execute(['id' => $id]);
        $dl['faktury'] = $stmt->fetchAll();

        json_response($dl);
    }

    if ($method === 'GET') {
        // Seznam DL s filtry: q, datum_od, datum_do, fakturovano, odberatel_id
        $sql = "
            SELECT dl.id, dl.cislo, dl.objednavka_id,
                   dl.datum_vystaveni, dl.datum_dodani,
                   dl.castka_celkem, dl.fakturovano, dl.obsah_upraveno,
                   o.cislo AS objednavka_cislo,
                   od.id AS odberatel_id,
                   od.nazev AS odberatel_nazev,
                   md.nazev AS misto_nazev,
                   COALESCE(ag_pol.pocet, 0) AS pocet_polozek,
                   ag_fa.faktura_cisla,
                   ag_fa.prvni_faktura_id
            FROM dodaci_listy dl
            LEFT JOIN objednavky o ON o.id = dl.objednavka_id
            -- 🐛 v3.0.64 fix: INNER JOIN → LEFT JOIN aby orphan DL (s neexistujícím
            -- odberatel_id) zůstaly viditelné. Dashboard alert je počítá ale list je
            -- skrýval → user reportoval '8 nefakturovaných v alertu ale prázdný list'.
            LEFT JOIN odberatele od ON od.id = dl.odberatel_id
            LEFT JOIN mista_dodani md ON md.id = dl.misto_dodani_id
            -- 🚀 N+1 → 2 agregované JOINy
            LEFT JOIN (
                SELECT dodaci_list_id, COUNT(*) AS pocet
                FROM dodaci_list_polozky
                GROUP BY dodaci_list_id
            ) ag_pol ON ag_pol.dodaci_list_id = dl.id
            LEFT JOIN (
                SELECT fdl.dodaci_list_id,
                       GROUP_CONCAT(f.cislo SEPARATOR ', ') AS faktura_cisla,
                       MIN(fdl.faktura_id)                   AS prvni_faktura_id
                FROM faktury_dodaci_listy fdl
                JOIN faktury f ON f.id = fdl.faktura_id
                GROUP BY fdl.dodaci_list_id
            ) ag_fa ON ag_fa.dodaci_list_id = dl.id
            WHERE 1=1
        ";
        $params = [];

        if (!empty($_GET['q'])) {
            $sql .= " AND (dl.cislo LIKE :q OR od.nazev LIKE :q OR (o.cislo IS NOT NULL AND o.cislo LIKE :q))";
            $params['q'] = '%' . $_GET['q'] . '%';
        }
        if (!empty($_GET['datum_od'])) {
            $sql .= " AND dl.datum_vystaveni >= :datum_od";
            $params['datum_od'] = $_GET['datum_od'];
        }
        if (!empty($_GET['datum_do'])) {
            $sql .= " AND dl.datum_vystaveni <= :datum_do";
            $params['datum_do'] = $_GET['datum_do'];
        }
        if (isset($_GET['fakturovano']) && $_GET['fakturovano'] !== '') {
            $sql .= " AND dl.fakturovano = :fakt";
            $params['fakt'] = (int) $_GET['fakturovano'];
        }
        if (!empty($_GET['odberatel_id'])) {
            $sql .= " AND dl.odberatel_id = :oid";
            $params['oid'] = (int) $_GET['odberatel_id'];
        }

        $sql .= " ORDER BY dl.datum_vystaveni DESC, dl.id DESC";

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $list = $stmt->fetchAll();

        // Sumy pro stat boxy
        $celkem = 0;
        $nefakt = 0;
        foreach ($list as $r) {
            $celkem += (float) $r['castka_celkem'];
            if (!$r['fakturovano']) {
                $nefakt += (float) $r['castka_celkem'];
            }
        }

        json_response([
            'dodaci_listy'        => $list,
            'pocet'               => count($list),
            'castka_celkem'       => $celkem,
            'castka_nefakturovana'=> $nefakt,
        ]);
    }

    if ($method === 'POST') {
        // Vytvoření ručního dodacího listu (bez objednávky)
        $d = json_decode(file_get_contents('php://input'), true);
        if (!is_array($d)) json_error('Chybný JSON', 400);

        // === EDITACE EXISTUJÍCÍHO DL ===
        if (($d['action'] ?? '') === 'upravit' && !empty($d['id'])) {
            $dl_id = (int) $d['id'];
            $odb_id   = (int) ($d['odberatel_id'] ?? 0);
            $misto_id = !empty($d['misto_dodani_id']) ? (int) $d['misto_dodani_id'] : null;
            $polozky  = $d['polozky'] ?? [];
            $datum_vystaveni = $d['datum_vystaveni'] ?? date('Y-m-d');
            $datum_dodani    = $d['datum_dodani']    ?? $datum_vystaveni;
            $poznamka        = $d['poznamka']        ?? null;

            if (!$odb_id) json_error('Chybí odběratel');
            if (!is_array($polozky) || count($polozky) === 0) json_error('DL musí mít alespoň jednu položku');

            // Načti ceník
            $cenik = [];
            foreach (cenik_pro_odberatele($pdo, $odb_id) as $v) {
                $cenik[(int) $v['id']] = $v;
            }

            $celkem = 0;
            $polozky_clean = [];
            foreach ($polozky as $i => $p) {
                $vid = isset($p['vyrobek_id']) ? (int) $p['vyrobek_id'] : null;
                $mn = isset($p['mnozstvi']) ? (float) $p['mnozstvi'] : 0;
                if ($mn <= 0) json_error("Řádek " . ($i + 1) . ": neplatné množství");

                $row = [
                    'vyrobek_id'    => $vid,
                    'vyrobek_cislo' => $p['vyrobek_cislo'] ?? null,
                    'vyrobek_nazev' => trim($p['vyrobek_nazev'] ?? ''),
                    'jednotka'      => $p['jednotka'] ?? 'ks',
                    'mnozstvi'      => $mn,
                    'cena_bez_dph'  => isset($p['cena_bez_dph']) ? (float) $p['cena_bez_dph'] : null,
                    'sazba_dph'     => isset($p['sazba_dph']) ? (float) $p['sazba_dph'] : 12.0,
                    'poznamka'      => $p['poznamka'] ?? null,
                ];

                if ($vid && isset($cenik[$vid])) {
                    $c = $cenik[$vid];
                    if ($row['vyrobek_nazev'] === '') $row['vyrobek_nazev'] = $c['nazev'];
                    if (!$row['vyrobek_cislo'])       $row['vyrobek_cislo'] = $c['cislo'];
                    if ($row['cena_bez_dph'] === null) $row['cena_bez_dph'] = (float) $c['cena_bez_dph'];
                }

                // Volný řádek (bez vyrobek_id) — vyžadujeme alespoň název a cenu
                if (!$vid) {
                    if ($row['vyrobek_nazev'] === '') json_error("Řádek " . ($i + 1) . ": chybí název");
                    if ($row['cena_bez_dph'] === null) json_error("Řádek " . ($i + 1) . ": chybí cena");
                    if ($row['sazba_dph'] === null) $row['sazba_dph'] = 12.0;
                }

                $celkem += $mn * $row['cena_bez_dph'] * (1 + $row['sazba_dph'] / 100);
                $polozky_clean[] = $row;
            }

            $pdo->beginTransaction();
            try {
                // Aktualizuj hlavičku
                $pdo->prepare("
                    UPDATE dodaci_listy SET
                        odberatel_id = :o, misto_dodani_id = :m,
                        datum_vystaveni = :dv, datum_dodani = :dd,
                        castka_celkem = :ce, poznamka = :pz,
                        obsah_upraveno = NOW()
                    WHERE id = :id
                ")->execute([
                    'o' => $odb_id, 'm' => $misto_id,
                    'dv' => $datum_vystaveni, 'dd' => $datum_dodani,
                    'ce' => round($celkem, 2), 'pz' => $poznamka,
                    'id' => $dl_id,
                ]);

                // Smaž staré položky
                $pdo->prepare("DELETE FROM dodaci_list_polozky WHERE dodaci_list_id = :dl")->execute(['dl' => $dl_id]);

                // Vlož nové položky
                $stmt = $pdo->prepare("
                    INSERT INTO dodaci_list_polozky
                        (dodaci_list_id, vyrobek_id, vyrobek_cislo, vyrobek_nazev,
                         jednotka, mnozstvi, cena_bez_dph, sazba_dph, poznamka)
                    VALUES (:dl, :v, :c, :n, :j, :m, :ce, :s, :pz)
                ");
                foreach ($polozky_clean as $p) {
                    $stmt->execute([
                        'dl' => $dl_id,
                        'v'  => $p['vyrobek_id'],
                        'c'  => $p['vyrobek_cislo'],
                        'n'  => $p['vyrobek_nazev'],
                        'j'  => $p['jednotka'],
                        'm'  => $p['mnozstvi'],
                        'ce' => $p['cena_bez_dph'],
                        's'  => $p['sazba_dph'],
                        'pz' => $p['poznamka'],
                    ]);
                }

                $pdo->commit();
                json_response(['ok' => true, 'id' => $dl_id]);
            } catch (Throwable $e) {
                $pdo->rollBack();
                throw $e;
            }
        }

        $odb_id   = (int) ($d['odberatel_id'] ?? 0);
        $misto_id = !empty($d['misto_dodani_id']) ? (int) $d['misto_dodani_id'] : null;
        $polozky  = $d['polozky'] ?? [];
        $datum_vystaveni = $d['datum_vystaveni'] ?? date('Y-m-d');
        $datum_dodani    = $d['datum_dodani']    ?? $datum_vystaveni;
        $poznamka        = $d['poznamka']        ?? null;

        if (!$odb_id) json_error('Chybí odběratel');
        if (!is_array($polozky) || count($polozky) === 0) {
            json_error('DL musí mít alespoň jednu položku');
        }
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $datum_vystaveni)) json_error('Neplatné datum vystavení');
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $datum_dodani))    json_error('Neplatné datum dodání');

        // Načti odběratele pro snapshot adresy
        $stmt = $pdo->prepare("SELECT * FROM odberatele WHERE id = :id");
        $stmt->execute(['id' => $odb_id]);
        $odb = $stmt->fetch();
        if (!$odb) json_error('Odběratel nenalezen', 404);

        // Pokud je zadané místo dodání, ověř, že patří odběrateli
        if ($misto_id) {
            $stmt = $pdo->prepare("SELECT 1 FROM mista_dodani WHERE id = :m AND odberatel_id = :o");
            $stmt->execute(['m' => $misto_id, 'o' => $odb_id]);
            if (!$stmt->fetchColumn()) json_error('Místo dodání nepatří k tomuto odběrateli');
        }

        // Načti ceník pro fallback
        $cenik = [];
        foreach (cenik_pro_odberatele($pdo, $odb_id) as $v) {
            $cenik[(int) $v['id']] = $v;
        }

        // Validace + výpočet částky
        $celkem = 0;
        $polozky_clean = [];
        foreach ($polozky as $i => $p) {
            $vid = isset($p['vyrobek_id']) ? (int) $p['vyrobek_id'] : null;
            $mn = isset($p['mnozstvi']) ? (float) $p['mnozstvi'] : 0;
            if ($mn <= 0) json_error("Řádek " . ($i + 1) . ": neplatné množství");

            $row = [
                'vyrobek_id'    => $vid,
                'vyrobek_cislo' => $p['vyrobek_cislo'] ?? null,
                'vyrobek_nazev' => trim($p['vyrobek_nazev'] ?? ''),
                'jednotka'      => $p['jednotka'] ?? null,
                'mnozstvi'      => $mn,
                'cena_bez_dph'  => isset($p['cena_bez_dph']) ? (float) $p['cena_bez_dph'] : null,
                'sazba_dph'     => isset($p['sazba_dph']) ? (float) $p['sazba_dph'] : null,
                'poznamka'      => $p['poznamka'] ?? null,
            ];

            if ($vid && isset($cenik[$vid])) {
                $c = $cenik[$vid];
                if ($row['vyrobek_nazev'] === '') $row['vyrobek_nazev'] = $c['nazev'];
                if (!$row['vyrobek_cislo'])       $row['vyrobek_cislo'] = $c['cislo'];
                if (!$row['jednotka'])            $row['jednotka']      = $c['jednotka'];
                if ($row['cena_bez_dph'] === null) $row['cena_bez_dph'] = (float) $c['cena_bez_dph'];
                if ($row['sazba_dph'] === null)   $row['sazba_dph']    = (float) $c['dph'];
            }

            // Pro volný řádek musí být vše vyplněno
            if (!$vid) {
                if ($row['vyrobek_nazev'] === '') json_error("Řádek " . ($i + 1) . ": chybí název");
                if ($row['cena_bez_dph'] === null) json_error("Řádek " . ($i + 1) . ": chybí cena");
                if ($row['sazba_dph'] === null)   $row['sazba_dph']    = 12.0;
                if (!$row['vyrobek_id'])           $row['vyrobek_id']   = null;
            }

            $celkem += $mn * $row['cena_bez_dph'] * (1 + $row['sazba_dph'] / 100);
            $polozky_clean[] = $row;
        }

        $pdo->beginTransaction();
        try {
            // Vygeneruj číslo DL
            $cislo = dalsi_cislo($pdo, 'DL', (int) date('Y', strtotime($datum_vystaveni)));

            // Vlož DL hlavičku
            $pdo->prepare("
                INSERT INTO dodaci_listy (
                    cislo, objednavka_id, odberatel_id, misto_dodani_id,
                    datum_vystaveni, datum_dodani, castka_celkem,
                    fakturovano, rucni, poznamka,
                    odb_nazev_snapshot, odb_ico_snapshot, odb_dic_snapshot,
                    odb_ulice_snapshot, odb_mesto_snapshot, odb_psc_snapshot
                ) VALUES (
                    :c, NULL, :o, :m,
                    :dv, :dd, :ce,
                    0, 1, :pz,
                    :n, :ico, :dic, :ul, :me, :psc
                )
            ")->execute([
                'c'  => $cislo, 'o' => $odb_id, 'm' => $misto_id,
                'dv' => $datum_vystaveni, 'dd' => $datum_dodani,
                'ce' => round($celkem, 2),
                'pz' => $poznamka,
                'n'   => $odb['nazev'],
                'ico' => $odb['ico'],
                'dic' => $odb['dic'],
                'ul'  => $odb['ulice'],
                'me'  => $odb['mesto'],
                'psc' => $odb['psc'],
            ]);
            $dl_id = (int) $pdo->lastInsertId();

            // Vlož položky
            $stmt = $pdo->prepare("
                INSERT INTO dodaci_list_polozky
                    (dodaci_list_id, vyrobek_id, vyrobek_cislo, vyrobek_nazev,
                     jednotka, mnozstvi, cena_bez_dph, sazba_dph, poznamka)
                VALUES (:dl, :v, :c, :n, :j, :m, :ce, :s, :pz)
            ");
            foreach ($polozky_clean as $p) {
                $stmt->execute([
                    'dl' => $dl_id,
                    'v'  => $p['vyrobek_id'],
                    'c'  => $p['vyrobek_cislo'],
                    'n'  => $p['vyrobek_nazev'],
                    'j'  => $p['jednotka'],
                    'm'  => $p['mnozstvi'],
                    'ce' => $p['cena_bez_dph'],
                    's'  => $p['sazba_dph'],
                    'pz' => $p['poznamka'],
                ]);
            }

            $pdo->commit();

            json_response([
                'ok'    => true,
                'id'    => $dl_id,
                'cislo' => $cislo,
            ]);
        } catch (Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
    }

    if ($method === 'DELETE') {
        require_super_admin(); // jen super admin smí mazat dodací listy
        $id = (int) ($_GET['id'] ?? 0);
        if (!$id) json_error('Chybí ID');

        // Zkontrolovat, jestli DL existuje
        $dl = $pdo->prepare("SELECT id, cislo, objednavka_id FROM dodaci_listy WHERE id = :id LIMIT 1");
        $dl->execute(['id' => $id]);
        $dlRow = $dl->fetch(PDO::FETCH_ASSOC);
        if (!$dlRow) json_error('Dodací list nenalezen', 404);

        // Pokud je DL fakturován, blokovat — uživatel musí nejprve smazat fakturu
        $fakStmt = $pdo->prepare("
            SELECT f.cislo FROM faktury_dodaci_listy fdl
            JOIN faktury f ON f.id = fdl.faktura_id
            WHERE fdl.dodaci_list_id = :id
        ");
        $fakStmt->execute(['id' => $id]);
        $linkedFaktury = $fakStmt->fetchAll(PDO::FETCH_COLUMN);
        if (!empty($linkedFaktury)) {
            json_error(
                'Tento dodací list je navázán na fakturu (' . implode(', ', $linkedFaktury) . '). Nejprve smažte fakturu.',
                409
            );
        }

        // === AUTO-SNAPSHOT před destruktivní akcí ===
        require_once __DIR__ . '/_zaloha_helper.php';
        zaloha_snapshot($pdo, 'Před smazáním dodacího listu ' . $dlRow['cislo']);

        $pdo->beginTransaction();
        try {
            $pdo->prepare("DELETE FROM dodaci_list_polozky WHERE dodaci_list_id = :id")
                ->execute(['id' => $id]);
            $pdo->prepare("DELETE FROM dodaci_listy WHERE id = :id")
                ->execute(['id' => $id]);
            $pdo->commit();
            json_response(['ok' => true]);
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            error_log('admin_dodaci_listy DELETE: ' . $e->getMessage());
            json_error_safe('Nepodařilo se smazat dodací list', $e, 500);
        }
    }

    json_error('Method not allowed', 405);

} catch (Throwable $e) {
    json_error_safe('Server error', $e, 500);
}
