<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/_admin_auth.php';
cors_headers();
require_admin();

$method = $_SERVER['REQUEST_METHOD'];
$pdo = db();

// Auto-migrace: notif_emaily flag + typ sloupec (kategorie odběratele)
(function() use ($pdo) {
    $cols = $pdo->query("
        SELECT COLUMN_NAME FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'odberatele'
    ")->fetchAll(PDO::FETCH_COLUMN);
    if (!in_array('notif_emaily', $cols, true)) {
        $pdo->exec("ALTER TABLE odberatele ADD COLUMN notif_emaily TINYINT(1) NOT NULL DEFAULT 1");
    }
    if (!in_array('typ', $cols, true)) {
        $pdo->exec("ALTER TABLE odberatele ADD COLUMN typ VARCHAR(50) DEFAULT NULL");
        $pdo->exec("ALTER TABLE odberatele ADD INDEX idx_odb_typ (typ)");
    }
})();

// 📇 IMPORT VCARD — POST ?action=import_vcard
// Body: { vcard: "BEGIN:VCARD..." } nebo { vcards: ["...", "..."] } (více najednou)
if (($_GET['action'] ?? '') === 'import_vcard' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $d = json_input();
    $cards = isset($d['vcards']) && is_array($d['vcards']) ? $d['vcards'] : [$d['vcard'] ?? ''];
    $imported = 0; $errors = [];
    foreach ($cards as $vcard) {
        if (!trim($vcard)) continue;
        try {
            $parsed = parse_vcard($vcard);
            if (!$parsed['nazev']) { $errors[] = 'Vizitka bez názvu — přeskočeno'; continue; }
            // Anti-duplicate (podle IČO nebo emailu)
            if ($parsed['ico']) {
                $exists = $pdo->prepare("SELECT id FROM odberatele WHERE ico = :i LIMIT 1");
                $exists->execute(['i' => $parsed['ico']]);
                if ($exists->fetchColumn()) { $errors[] = "Duplikát IČO {$parsed['ico']}: {$parsed['nazev']}"; continue; }
            }
            $stmt = $pdo->prepare("
                INSERT INTO odberatele (nazev, ico, dic, ulice, mesto, psc, email, telefon, web, poznamka, typ, splatnost_dni)
                VALUES (:n, :i, :d, :u, :m, :p, :e, :t, :w, :pz, :tp, 14)
            ");
            $stmt->execute([
                'n' => $parsed['nazev'],
                'i' => $parsed['ico'] ?: null,
                'd' => $parsed['dic'] ?: null,
                'u' => $parsed['ulice'] ?: null,
                'm' => $parsed['mesto'] ?: null,
                'p' => $parsed['psc'] ?: null,
                'e' => $parsed['email'] ?: null,
                't' => $parsed['telefon'] ?: null,
                'w' => $parsed['web'] ?: null,
                'pz'=> $parsed['poznamka'] ?: null,
                'tp'=> $d['typ'] ?? null,    // pro hromadný import nastavit typ pro všechny
            ]);
            $imported++;
        } catch (Throwable $e) {
            $errors[] = 'Chyba: ' . $e->getMessage();
        }
    }
    json_response(['ok' => true, 'imported' => $imported, 'errors' => $errors]);
}

/**
 * Parse vCard 2.1/3.0/4.0 do struktury.
 */
function parse_vcard(string $vcard): array {
    $out = ['nazev'=>'','ico'=>'','dic'=>'','ulice'=>'','mesto'=>'','psc'=>'','email'=>'','telefon'=>'','web'=>'','poznamka'=>''];
    $lines = preg_split('/\r?\n/', $vcard);
    // Slož pokračovací řádky (vCard 3.0 — line wrap)
    $joined = [];
    foreach ($lines as $line) {
        if (preg_match('/^[\s\t]/', $line) && !empty($joined)) {
            $joined[count($joined) - 1] .= ltrim($line);
        } else {
            $joined[] = $line;
        }
    }
    foreach ($joined as $line) {
        if (!str_contains($line, ':')) continue;
        [$key, $val] = explode(':', $line, 2);
        $val = trim($val);
        // Pro ORG, FN escaping
        $val = str_replace(['\,', '\;', '\\\\', '\n'], [',', ';', '\\', "\n"], $val);
        $keyU = strtoupper(strtok($key, ';'));
        if ($keyU === 'FN' && !$out['nazev']) $out['nazev'] = $val;
        if ($keyU === 'ORG') {
            // ORG někdy "název firmy;oddělení" — vezmeme jen první
            $parts = explode(';', $val);
            if (!$out['nazev'] || $out['nazev'] === ($joined[0] ?? '')) $out['nazev'] = trim($parts[0]);
        }
        if ($keyU === 'TEL' && !$out['telefon']) $out['telefon'] = $val;
        if ($keyU === 'EMAIL' && !$out['email']) $out['email'] = $val;
        if ($keyU === 'URL' && !$out['web']) $out['web'] = $val;
        if ($keyU === 'ADR') {
            // ADR: PO box;Extended;Street;City;Region;PostalCode;Country
            $parts = explode(';', $val);
            $out['ulice'] = trim($parts[2] ?? '');
            $out['mesto'] = trim($parts[3] ?? '');
            $out['psc']   = trim($parts[5] ?? '');
        }
        if ($keyU === 'NOTE') $out['poznamka'] = $val;
        if ($keyU === 'X-EVOLUTION-FILE-AS' && !$out['nazev']) $out['nazev'] = $val;
        // Custom: IČO/DIČ z poznámky (heuristika)
        if (preg_match('/IČO[:\s]*(\d{6,8})/u', $val, $m)) $out['ico'] = $m[1];
        if (preg_match('/DIČ[:\s]*(CZ?\d{6,12})/u', $val, $m)) $out['dic'] = $m[1];
    }
    return $out;
}

if ($method === 'GET') {
    if (isset($_GET['id'])) {
        $stmt = $pdo->prepare("SELECT * FROM odberatele WHERE id = :id");
        $stmt->execute(['id' => (int) $_GET['id']]);
        $o = $stmt->fetch();
        if (!$o) json_error('Odběratel nenalezen', 404);

        $mista = $pdo->prepare("
            SELECT * FROM mista_dodani
            WHERE odberatel_id = :id
            ORDER BY vychozi DESC, poradi, nazev
        ");
        $mista->execute(['id' => $o['id']]);
        $o['mista_dodani'] = $mista->fetchAll();

        $stat = $pdo->prepare("
            SELECT COUNT(*) AS pocet, COALESCE(SUM(castka_celkem), 0) AS celkem
            FROM objednavky WHERE odberatel_id = :id
        ");
        $stat->execute(['id' => $o['id']]);
        $o['statistika'] = $stat->fetch();

        // Heslo neposíláme
        unset($o['heslo_hash']);

        json_response($o);
    }

    $hledat = trim($_GET['q'] ?? '');
    $filtrTyp = trim($_GET['typ'] ?? '');

    // Speciální endpoint: agregace typů (pro pillsy filtru)
    if (isset($_GET['action']) && $_GET['action'] === 'typy_stats') {
        $st = $pdo->query("
            SELECT COALESCE(NULLIF(typ,''),'_nezarazeno') AS typ, COUNT(*) AS pocet
            FROM odberatele
            GROUP BY COALESCE(NULLIF(typ,''),'_nezarazeno')
            ORDER BY pocet DESC
        ");
        json_response($st->fetchAll());
    }

    // FIX #15: Místo SUM(DISTINCT ...) používáme subquery, jinak by se
    // ztrácely objednávky s duplicitní castka_celkem
    $sql = "
        SELECT o.id, o.cislo, o.nazev, o.ico, o.email, o.telefon,
               o.ulice, o.mesto, o.psc, o.typ,
               o.login_email, o.blokovan, o.splatnost_dni,
               COALESCE(obj.pocet, 0) AS pocet_objednavek,
               COALESCE(obj.trzba, 0) AS trzba_celkem,
               COALESCE(md.pocet, 0)  AS pocet_pobocek,
               COALESCE(dl.pocet, 0)  AS pocet_dl,
               COALESCE(fa.pocet, 0)  AS pocet_faktur
        FROM odberatele o
        LEFT JOIN (
            SELECT odberatel_id, COUNT(*) AS pocet, SUM(castka_celkem) AS trzba
            FROM objednavky GROUP BY odberatel_id
        ) obj ON obj.odberatel_id = o.id
        LEFT JOIN (
            SELECT odberatel_id, COUNT(*) AS pocet
            FROM mista_dodani WHERE aktivni = 1 GROUP BY odberatel_id
        ) md ON md.odberatel_id = o.id
        -- 🚀 N+1 → JOINs: pocet_dl/faktur jednou agregované, ne per-row subquery
        LEFT JOIN (
            SELECT odberatel_id, COUNT(*) AS pocet FROM dodaci_listy GROUP BY odberatel_id
        ) dl ON dl.odberatel_id = o.id
        LEFT JOIN (
            SELECT odberatel_id, COUNT(*) AS pocet FROM faktury GROUP BY odberatel_id
        ) fa ON fa.odberatel_id = o.id
        WHERE 1=1
    ";
    $params = [];
    if ($hledat !== '') {
        $hl = str_replace(['\\','%','_'], ['\\\\','\\%','\\_'], $hledat);
        $sql .= " AND (o.nazev LIKE :q OR o.email LIKE :q OR o.ico LIKE :q)";
        $params['q'] = '%' . $hl . '%';
    }
    if ($filtrTyp !== '') {
        if ($filtrTyp === '_nezarazeno') {
            $sql .= " AND (o.typ IS NULL OR o.typ = '')";
        } else {
            $sql .= " AND o.typ = :tp";
            $params['tp'] = $filtrTyp;
        }
    }
    $sql .= " ORDER BY o.nazev";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    json_response($stmt->fetchAll());
}

if ($method === 'POST') {
    $d = json_input();
    if (empty($d['nazev'])) json_error('Chybí název');

    $heslo_hash = !empty($d['heslo']) ? password_hash($d['heslo'], PASSWORD_DEFAULT) : null;

    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare("
            INSERT INTO odberatele (cislo, nazev, ico, dic, ulice, mesto, psc,
                                    email, telefon, kontaktni_osoba,
                                    login_email, heslo_hash,
                                    splatnost_dni, sleva_pct, blokovan, notif_emaily, poznamka,
                                    cenova_skupina_id, typ)
            VALUES (:c,:n,:ico,:dic,:ul,:me,:psc,:em,:tel,:ko,:log,:hash,:sp,:sl,:bl,:nf,:po,:cs,:tp)
        ");
        $stmt->execute([
            'c' => $d['cislo'] ?? null, 'n' => trim($d['nazev']),
            'ico' => $d['ico'] ?? null, 'dic' => $d['dic'] ?? null,
            'ul' => $d['ulice'] ?? null, 'me' => $d['mesto'] ?? null,
            'psc' => $d['psc'] ?? null, 'em' => $d['email'] ?? null,
            'tel' => $d['telefon'] ?? null, 'ko' => $d['kontaktni_osoba'] ?? null,
            'log' => $d['login_email'] ?? null, 'hash' => $heslo_hash,
            'sp' => $d['splatnost_dni'] ?? 14, 'sl' => $d['sleva_pct'] ?? 0,
            'bl' => isset($d['blokovan']) ? (int) $d['blokovan'] : 0,
            'nf' => isset($d['notif_emaily']) ? (int) $d['notif_emaily'] : 1,
            'po' => $d['poznamka'] ?? null,
            'cs' => !empty($d['cenova_skupina_id']) ? (int) $d['cenova_skupina_id'] : null,
            'tp' => !empty($d['typ']) ? trim((string)$d['typ']) : null,
        ]);
        $odb_id = (int) $pdo->lastInsertId();

        // Hlavní pobočka z údajů odběratele (jako výchozí)
        if (!empty($d['vytvorit_hlavni_pobocku']) && !empty($d['ulice'])) {
            $stmt = $pdo->prepare("
                INSERT INTO mista_dodani (odberatel_id, nazev, ulice, mesto, psc,
                                          kontaktni_osoba, telefon, vychozi, aktivni)
                VALUES (:o, 'Hlavní provozovna', :ul, :me, :psc, :ko, :tel, 1, 1)
            ");
            $stmt->execute([
                'o' => $odb_id,
                'ul' => $d['ulice'], 'me' => $d['mesto'] ?? null, 'psc' => $d['psc'] ?? null,
                'ko' => $d['kontaktni_osoba'] ?? null, 'tel' => $d['telefon'] ?? null,
            ]);
        }

        $pdo->commit();
        json_response(['id' => $odb_id], 201);
    } catch (PDOException $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        error_log('admin_odberatele POST: ' . $e->getMessage());
        // Speciálka pro duplicitní login_email
        if ($e->getCode() === '23000') {
            json_error('Email je už použitý jiným odběratelem', 409);
        }
        json_error('Nepodařilo se uložit odběratele', 500);
    }
}

if ($method === 'PUT') {
    $d = json_input();
    if (empty($d['id'])) json_error('Chybí ID');

    $sql = "
        UPDATE odberatele SET
            cislo = :c, nazev = :n, ico = :ico, dic = :dic,
            ulice = :ul, mesto = :me, psc = :psc,
            email = :em, telefon = :tel, kontaktni_osoba = :ko,
            login_email = :log, splatnost_dni = :sp,
            sleva_pct = :sl, blokovan = :bl, notif_emaily = :nf, poznamka = :po,
            cenova_skupina_id = :cs, typ = :tp
    ";
    $params = [
        'id' => (int) $d['id'],
        'c' => $d['cislo'] ?? null, 'n' => trim($d['nazev']),
        'ico' => $d['ico'] ?? null, 'dic' => $d['dic'] ?? null,
        'ul' => $d['ulice'] ?? null, 'me' => $d['mesto'] ?? null,
        'psc' => $d['psc'] ?? null, 'em' => $d['email'] ?? null,
        'tel' => $d['telefon'] ?? null, 'ko' => $d['kontaktni_osoba'] ?? null,
        'log' => $d['login_email'] ?? null,
        'sp' => $d['splatnost_dni'] ?? 14, 'sl' => $d['sleva_pct'] ?? 0,
        'bl' => isset($d['blokovan']) ? (int) $d['blokovan'] : 0,
        'nf' => isset($d['notif_emaily']) ? (int) $d['notif_emaily'] : 1,
        'po' => $d['poznamka'] ?? null,
        'cs' => !empty($d['cenova_skupina_id']) ? (int) $d['cenova_skupina_id'] : null,
        'tp' => !empty($d['typ']) ? trim((string)$d['typ']) : null,
    ];
    if (!empty($d['heslo'])) {
        $sql .= ", heslo_hash = :hash";
        $params['hash'] = password_hash($d['heslo'], PASSWORD_DEFAULT);
    }
    $sql .= " WHERE id = :id";
    try {
        $pdo->prepare($sql)->execute($params);
        json_response(['ok' => true]);
    } catch (PDOException $e) {
        if ($e->getCode() === '23000') {
            json_error('Email je už použitý jiným odběratelem', 409);
        }
        throw $e;
    }
}

if ($method === 'DELETE') {
    require_super_admin(); // jen super admin smí mazat / blokovat odběratele
    $id = (int) ($_GET['id'] ?? 0);
    if (!$id) json_error('Chybí ID');

    // Pokud má odběratel objednávky, jen ho zablokuj (soft-delete)
    $cnt = $pdo->prepare("SELECT COUNT(*) FROM objednavky WHERE odberatel_id = :id");
    $cnt->execute(['id' => $id]);
    if ($cnt->fetchColumn() > 0) {
        $pdo->prepare("UPDATE odberatele SET blokovan = 1 WHERE id = :id")->execute(['id' => $id]);
        json_response(['ok' => true, 'blocked' => true]);
    }

    // === AUTO-SNAPSHOT před TRVALÝM smazáním odběratele ===
    require_once __DIR__ . '/_zaloha_helper.php';
    zaloha_snapshot($pdo, 'Před smazáním odběratele ID ' . $id);

    $pdo->prepare("DELETE FROM odberatele WHERE id = :id")->execute(['id' => $id]);
    json_response(['ok' => true, 'deleted' => true]);
}

json_error('Neplatná metoda', 405);
