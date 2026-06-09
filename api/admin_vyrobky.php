<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/_admin_auth.php';
cors_headers();
require_admin();

$method = $_SERVER['REQUEST_METHOD'];
$pdo = db();

// Auto-migrace: sloupec ean (pro tisk čárových kódů na cenovkách) + obsah balení
(function() use ($pdo) {
    try {
        $cols = $pdo->query("
            SELECT COLUMN_NAME FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'vyrobky'
        ")->fetchAll(PDO::FETCH_COLUMN);
        if (!in_array('ean', $cols, true)) {
            $pdo->exec("ALTER TABLE vyrobky ADD COLUMN ean VARCHAR(13) DEFAULT NULL AFTER cislo");
        }
        if (!in_array('obsah', $cols, true)) {
            $pdo->exec("ALTER TABLE vyrobky ADD COLUMN obsah DECIMAL(10,3) DEFAULT NULL AFTER hmotnost_g");
        }
        if (!in_array('obsah_jednotka', $cols, true)) {
            $pdo->exec("ALTER TABLE vyrobky ADD COLUMN obsah_jednotka VARCHAR(5) DEFAULT NULL AFTER obsah");
        }
        if (!in_array('nutricni_hodnoty', $cols, true)) {
            $pdo->exec("ALTER TABLE vyrobky ADD COLUMN nutricni_hodnoty TEXT DEFAULT NULL AFTER alergeny");
        }
        if (!in_array('vyrobni_cena', $cols, true)) {
            $pdo->exec("ALTER TABLE vyrobky ADD COLUMN vyrobni_cena DECIMAL(10,4) DEFAULT NULL AFTER cena_bez_dph");
        }
        if (!in_array('kalkulace_data', $cols, true)) {
            $pdo->exec("ALTER TABLE vyrobky ADD COLUMN kalkulace_data TEXT DEFAULT NULL AFTER vyrobni_cena");
        }
        if (!in_array('haccp_data', $cols, true)) {
            $pdo->exec("ALTER TABLE vyrobky ADD COLUMN haccp_data TEXT DEFAULT NULL AFTER kalkulace_data");
        }
        if (!in_array('haccp_graf_id', $cols, true)) {
            $pdo->exec("ALTER TABLE vyrobky ADD COLUMN haccp_graf_id INT NULL AFTER haccp_data, ADD INDEX idx_haccp_graf (haccp_graf_id)");
        }
        // Statusové štítky pro katalog — nezávislé na slevě
        if (!in_array('je_akce', $cols, true)) {
            $pdo->exec("ALTER TABLE vyrobky ADD COLUMN je_akce TINYINT(1) DEFAULT 0 AFTER oblibeny");
        }
        if (!in_array('je_novinka', $cols, true)) {
            $pdo->exec("ALTER TABLE vyrobky ADD COLUMN je_novinka TINYINT(1) DEFAULT 0 AFTER je_akce");
        }
        if (!in_array('je_doprodej', $cols, true)) {
            $pdo->exec("ALTER TABLE vyrobky ADD COLUMN je_doprodej TINYINT(1) DEFAULT 0 AFTER je_novinka");
        }
        if (!in_array('je_vyprodano', $cols, true)) {
            $pdo->exec("ALTER TABLE vyrobky ADD COLUMN je_vyprodano TINYINT(1) DEFAULT 0 AFTER je_doprodej");
        }
        // 🆕 v3.0.213 — viditelnost výrobku na POS (KASA). Default 1 = zobrazit (nenarušuje stávající).
        if (!in_array('zobrazit_na_pos', $cols, true)) {
            $pdo->exec("ALTER TABLE vyrobky ADD COLUMN zobrazit_na_pos TINYINT(1) DEFAULT 1 AFTER je_vyprodano");
        }
    } catch (Throwable $e) {
        error_log('admin_vyrobky auto-migrace: ' . $e->getMessage());
    }
})();

// =============================================================
// UPLOAD obrázku - re-enkóduje přes GD a tím odstraní případný
// payload schovaný v EXIFu/metadatech.
// =============================================================
if ($method === 'POST' && ($_GET['action'] ?? '') === 'upload') {
    if (empty($_FILES['obrazek'])) json_error('Chybí soubor');

    $file = $_FILES['obrazek'];
    if ($file['error'] !== UPLOAD_ERR_OK) {
        json_error('Chyba uploadu (kód ' . $file['error'] . ')');
    }

    $allowed = ['image/jpeg', 'image/png', 'image/webp'];
    $mime = mime_content_type($file['tmp_name']);
    if (!in_array($mime, $allowed)) {
        json_error('Povolen je jen JPG, PNG nebo WEBP');
    }
    if ($file['size'] > 5 * 1024 * 1024) {
        json_error('Soubor je větší než 5 MB');
    }

    // Re-enkódování přes GD - smaže EXIF, profily, případné payloady
    $img = match($mime) {
        'image/jpeg' => @imagecreatefromjpeg($file['tmp_name']),
        'image/png'  => @imagecreatefrompng($file['tmp_name']),
        'image/webp' => @imagecreatefromwebp($file['tmp_name']),
    };
    if (!$img) json_error('Nepodařilo se zpracovat obrázek');

    // Zachovej průhlednost u PNG/WEBP
    $w = imagesx($img);
    $h = imagesy($img);

    // Volitelné zmenšení - max 1200px na delší stranu
    $max = 1200;
    if ($w > $max || $h > $max) {
        if ($w >= $h) { $nw = $max; $nh = (int) ($h * $max / $w); }
        else          { $nh = $max; $nw = (int) ($w * $max / $h); }
        $resized = imagecreatetruecolor($nw, $nh);
        imagealphablending($resized, false);
        imagesavealpha($resized, true);
        imagecopyresampled($resized, $img, 0, 0, 0, 0, $nw, $nh, $w, $h);
        imagedestroy($img);
        $img = $resized;
    }

    // Vytvoř složku, pokud neexistuje
    $upload_dir = __DIR__ . '/../uploads/vyrobky';
    if (!is_dir($upload_dir)) {
        mkdir($upload_dir, 0755, true);
    }
    // .htaccess - zákaz spouštění PHP v uploads
    $htaccess = $upload_dir . '/.htaccess';
    if (!file_exists($htaccess)) {
        file_put_contents($htaccess,
            "<FilesMatch \"\\.(php|php3|php4|php5|phtml|phar)$\">\n"
          . "    Require all denied\n"
          . "</FilesMatch>\n");
    }

    // Generuj unikátní jméno - vždy uložíme jako JPG nebo PNG
    $ext = ($mime === 'image/png') ? 'png' : 'jpg';
    $filename = 'vyrobek_' . time() . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
    $target = $upload_dir . '/' . $filename;

    $ok = ($ext === 'png')
        ? imagepng($img, $target, 6)
        : imagejpeg($img, $target, 85);
    imagedestroy($img);

    if (!$ok) json_error('Nepodařilo se uložit soubor');

    json_response([
        'url' => '/uploads/vyrobky/' . $filename,
        'filename' => $filename,
    ]);
}

// =============================================================
// POST action=update_poradi - hromadná aktualizace pořadí výrobků
// Body: { poradi: [ {id: 5, poradi: 0}, {id: 7, poradi: 1}, ... ] }
// =============================================================
// Vrátí další volné číslo (pro pre-fill při novém výrobku)
if ($method === 'GET' && ($_GET['action'] ?? '') === 'next_cislo') {
    // Najdi nejvyšší numerické cislo (i s prefixem typu CH001, BG002 — vezme jen čísla)
    $rows = $pdo->query("SELECT cislo FROM vyrobky WHERE cislo IS NOT NULL AND cislo <> ''")->fetchAll(PDO::FETCH_COLUMN);
    $max = 0;
    foreach ($rows as $c) {
        // Vyextrahuj poslední skupinu číslic
        if (preg_match('/(\d+)\s*$/', $c, $m)) {
            $n = (int) $m[1];
            if ($n > $max) $max = $n;
        }
    }
    json_response(['next' => $max + 1]);
}

// Přečíslování výrobků — ?action=renumber
if ($method === 'POST' && ($_GET['action'] ?? '') === 'renumber') {
    // === AUTO-SNAPSHOT před destruktivní akcí ===
    require_once __DIR__ . '/_zaloha_helper.php';
    zaloha_snapshot($pdo, 'Před přečíslováním výrobků');

    $d = json_input();
    $start = max(1, (int) ($d['start'] ?? 1));
    $prefix = isset($d['prefix']) ? trim((string) $d['prefix']) : '';
    $pad = max(0, min(6, (int) ($d['pad'] ?? 0))); // padding zleva nulami (0 = bez)
    $jenAktivni = !empty($d['jen_aktivni']);
    $podleKategorie = !empty($d['podle_kategorie']);
    $propagovat = !isset($d['propagovat']) || !empty($d['propagovat']); // default ano
    // Map { kategorie_id: start_number } — kategorie s vlastním startem (např. chleby 2001, sladké 3001)
    $katStart = is_array($d['kategorie_start'] ?? null) ? $d['kategorie_start'] : [];
    $perKategorii = !empty($katStart) || !empty($d['per_kategorii']);

    if ($perKategorii) {
        // Načti výrobky se všemi info — řadíme podle kategorií, pro každou vlastní pořadí
        $sql = "SELECT v.id, v.kategorie_id, k.nazev AS kat_nazev, k.poradi AS kat_poradi
                FROM vyrobky v LEFT JOIN kategorie_vyrobku k ON v.kategorie_id = k.id";
        if ($jenAktivni) $sql .= " WHERE v.aktivni = 1";
        $sql .= " ORDER BY k.poradi, k.nazev, v.poradi, v.nazev";
        $rows = $pdo->query($sql)->fetchAll();
        if (empty($rows)) json_error('Žádné výrobky k přečíslování');

        // Sgrupuj per kategorii
        $byKat = [];
        foreach ($rows as $r) {
            $kid = (int) ($r['kategorie_id'] ?? 0);
            $byKat[$kid][] = (int) $r['id'];
        }
    } else {
        $sql = "SELECT v.id FROM vyrobky v LEFT JOIN kategorie_vyrobku k ON v.kategorie_id = k.id";
        if ($jenAktivni) $sql .= " WHERE v.aktivni = 1";
        if ($podleKategorie) $sql .= " ORDER BY k.poradi, v.poradi, v.nazev";
        else                 $sql .= " ORDER BY v.poradi, v.nazev";
        $ids = $pdo->query($sql)->fetchAll(PDO::FETCH_COLUMN);
        if (empty($ids)) json_error('Žádné výrobky k přečíslování');
    }

    $pdo->beginTransaction();
    try {
        $upd = $pdo->prepare("UPDATE vyrobky SET cislo = :c WHERE id = :id");

        // Příprava propagace do snapshot polí (DL + FA položky)
        // Některé tabulky/sloupce nemusí v DB existovat — připravíme pouze pokud je
        $propUpd = [];
        if ($propagovat) {
            $tables = [
                ['t' => 'dodaci_list_polozky', 'col' => 'vyrobek_cislo', 'fk' => 'vyrobek_id'],
                ['t' => 'faktura_polozky',     'col' => 'vyrobek_cislo', 'fk' => 'vyrobek_id'],
            ];
            foreach ($tables as $tbl) {
                try {
                    $check = $pdo->prepare("
                        SELECT COUNT(*) FROM information_schema.COLUMNS
                        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?
                    ");
                    $check->execute([$tbl['t'], $tbl['col']]);
                    if ((int) $check->fetchColumn() > 0) {
                        $propUpd[$tbl['t']] = $pdo->prepare(
                            "UPDATE `{$tbl['t']}` SET `{$tbl['col']}` = :c WHERE `{$tbl['fk']}` = :id"
                        );
                    }
                } catch (Throwable $e) { /* tabulka nemusí existovat */ }
            }
        }

        $report = [];
        $propocet = 0;

        $applyOne = function($id, $cislo) use ($upd, $propUpd, &$report, &$propocet) {
            $upd->execute(['c' => $cislo, 'id' => (int) $id]);
            foreach ($propUpd as $stmt) {
                $stmt->execute(['c' => $cislo, 'id' => (int) $id]);
                $propocet += $stmt->rowCount();
            }
            $report[] = ['id' => (int) $id, 'cislo' => $cislo];
        };

        if (!empty($perKategorii) && !empty($byKat)) {
            // Per kategorii — každá má svůj start
            foreach ($byKat as $kid => $ids_kat) {
                $kStart = isset($katStart[$kid]) ? max(1, (int) $katStart[$kid]) : $start;
                $i = $kStart;
                foreach ($ids_kat as $id) {
                    $cislo = $pad > 0 ? str_pad((string) $i, $pad, '0', STR_PAD_LEFT) : (string) $i;
                    if ($prefix !== '') $cislo = $prefix . $cislo;
                    $applyOne($id, $cislo);
                    $i++;
                }
            }
        } else {
            $i = $start;
            foreach ($ids as $id) {
                $cislo = $pad > 0 ? str_pad((string) $i, $pad, '0', STR_PAD_LEFT) : (string) $i;
                if ($prefix !== '') $cislo = $prefix . $cislo;
                $applyOne($id, $cislo);
                $i++;
            }
        }
        $pdo->commit();
        json_response([
            'ok' => true,
            'pocet' => count($report),
            'propagovano_zaznamu' => $propocet, // počet aktualizovaných záznamů v DL/FA
            'preklady' => array_slice($report, 0, 5), // ukázka prvních 5
        ]);
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        json_error_safe('Chyba', $e, 500);
    }
}

if ($method === 'POST' && ($_GET['action'] ?? '') === 'update_poradi') {
    $d = json_input();
    if (empty($d['poradi']) || !is_array($d['poradi'])) {
        json_error('Chybí seznam pořadí');
    }

    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare("UPDATE vyrobky SET poradi = :p WHERE id = :id");
        foreach ($d['poradi'] as $row) {
            $id  = (int) ($row['id'] ?? 0);
            $por = (int) ($row['poradi'] ?? 0);
            if ($id > 0) {
                $stmt->execute(['p' => $por, 'id' => $id]);
            }
        }
        $pdo->commit();
        json_response(['ok' => true, 'updated' => count($d['poradi'])]);
    } catch (Throwable $e) {
        $pdo->rollBack();
        json_error_safe('Chyba ukládání', $e, 500);
    }
}

// =============================================================
// GET - seznam, detail
// =============================================================
if ($method === 'GET') {
    // 🆕 v3.0.44 — EAN lookup endpoint (barcode scanner → najdi výrobek)
    //   GET ?ean=XXX → vrátí { vyrobky: [...matches] } (lightweight, jen základní pole)
    if (isset($_GET['ean'])) {
        $ean = trim((string) $_GET['ean']);
        if ($ean === '') json_response(['vyrobky' => []]);
        try {
            $st = $pdo->prepare("SELECT id, cislo, ean, nazev, cena, jednotka, kategorie_id, obrazek_url
                                 FROM vyrobky WHERE ean = :e LIMIT 20");
            $st->execute(['e' => $ean]);
            json_response(['vyrobky' => $st->fetchAll(PDO::FETCH_ASSOC)]);
        } catch (Throwable $e) { json_response(['vyrobky' => []]); }
    }
    if (isset($_GET['id'])) {
        $vid = (int) $_GET['id'];
        $stmt = $pdo->prepare("SELECT * FROM vyrobky WHERE id = :id");
        $stmt->execute(['id' => $vid]);
        $v = $stmt->fetch();
        if (!$v) json_error('Výrobek nenalezen', 404);

        // Slozeni text → přesun pod jiný klíč, abychom v 'slozeni' mohli vrátit pivot
        $v['slozeni_text'] = $v['slozeni'] ?? null;

        // Složení (pivot s cenami pro kalkulaci nákladů)
        try {
            $stmt = $pdo->prepare("
                SELECT vs.id, vs.surovina_id, vs.mnozstvi, vs.jednotka, vs.poradi, vs.poznamka,
                       s.nazev AS surovina_nazev, s.alergen AS surovina_alergen,
                       s.jednotka AS surovina_jednotka,
                       s.cena_baleni, s.obsah_baleni
                FROM vyrobek_suroviny vs
                JOIN suroviny s ON s.id = vs.surovina_id
                WHERE vs.vyrobek_id = :v
                ORDER BY vs.poradi, s.nazev
            ");
            $stmt->execute(['v' => $vid]);
            $v['slozeni'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            // Tabulky ještě nejsou - nevadí
            $v['slozeni'] = [];
        }
        json_response($v);
    }

    // 🚀 PERFORMANCE: 4 N+1 subqueries nahrazeny dvěma agregovanými LEFT JOINs.
    // Při 500 výrobcích to ušetří ~2000 dotazů → 4 dotazy, viditelně rychlejší.
    // API kontrakt zachován — stejná pole (pocet_dl, pocet_fa, posledni_dl, posledni_fa).
    // 🆕 v2.9.271 — přidáno ma_recept (počet surovin v receptuře) + ma_kalkulaci
    //               (count uložených kalkulací) pro frontend badge "🧮 Má recept"
    // Detekce tabulek — vyrobek_suroviny / kalkulace_historie mohou chybět ve staré DB
    $hasVS = false; $hasKH = false;
    try {
        $hasVS = !!$pdo->query("SHOW TABLES LIKE 'vyrobek_suroviny'")->fetchColumn();
        $hasKH = !!$pdo->query("SHOW TABLES LIKE 'kalkulace_historie'")->fetchColumn();
    } catch (Throwable $e) {}
    $vsJoin = $hasVS ? "LEFT JOIN (
            SELECT vyrobek_id, COUNT(*) AS pocet_surovin_receptu
            FROM vyrobek_suroviny GROUP BY vyrobek_id
        ) vs ON vs.vyrobek_id = v.id" : "";
    $vsSelect = $hasVS ? "COALESCE(vs.pocet_surovin_receptu, 0) AS pocet_surovin_receptu" : "0 AS pocet_surovin_receptu";
    $khJoin = $hasKH ? "LEFT JOIN (
            SELECT vyrobek_id, COUNT(*) AS pocet_kalkulaci, MAX(vytvoreno) AS posledni_kalkulace
            FROM kalkulace_historie WHERE vyrobek_id IS NOT NULL GROUP BY vyrobek_id
        ) kh ON kh.vyrobek_id = v.id" : "";
    $khSelect = $hasKH
        ? "COALESCE(kh.pocet_kalkulaci, 0) AS pocet_kalkulaci, kh.posledni_kalkulace"
        : "0 AS pocet_kalkulaci, NULL AS posledni_kalkulace";

    $vyrobky = $pdo->query("
        SELECT v.*, k.nazev AS kategorie_nazev, k.ikona AS kategorie_ikona,
               j.kod AS jednotka_kod, s.sazba AS dph,
               COALESCE(sdl.pocet_dl, 0)  AS pocet_dl,
               COALESCE(sfa.pocet_fa, 0)  AS pocet_fa,
               sdl.posledni_dl,
               sfa.posledni_fa,
               {$vsSelect},
               {$khSelect}
        FROM vyrobky v
        LEFT JOIN kategorie_vyrobku k ON v.kategorie_id = k.id
        LEFT JOIN jednotky j ON v.jednotka_id = j.id
        LEFT JOIN sazby_dph s ON v.sazba_dph_id = s.id
        LEFT JOIN (
            SELECT dlp.vyrobek_id,
                   COUNT(DISTINCT dlp.dodaci_list_id) AS pocet_dl,
                   MAX(dl.datum_vystaveni)            AS posledni_dl
            FROM dodaci_list_polozky dlp
            JOIN dodaci_listy dl ON dl.id = dlp.dodaci_list_id
            GROUP BY dlp.vyrobek_id
        ) sdl ON sdl.vyrobek_id = v.id
        LEFT JOIN (
            SELECT fp.vyrobek_id,
                   COUNT(DISTINCT fp.faktura_id) AS pocet_fa,
                   MAX(f.datum_vystaveni)        AS posledni_fa
            FROM faktura_polozky fp
            JOIN faktury f ON f.id = fp.faktura_id
            GROUP BY fp.vyrobek_id
        ) sfa ON sfa.vyrobek_id = v.id
        {$vsJoin}
        {$khJoin}
        ORDER BY v.aktivni DESC, k.poradi, v.poradi, v.nazev
    ")->fetchAll();

    json_response([
        'vyrobky' => $vyrobky,
        'kategorie' => $pdo->query("SELECT * FROM kategorie_vyrobku ORDER BY poradi")->fetchAll(),
        'jednotky' => $pdo->query("SELECT * FROM jednotky ORDER BY id")->fetchAll(),
        'sazby' => $pdo->query("SELECT * FROM sazby_dph ORDER BY id")->fetchAll(),
    ]);
}

// =============================================================
// Helper - synchronizuje pivot vyrobek_suroviny (replace-all)
// =============================================================
function sync_slozeni(PDO $pdo, int $vyrobek_id, array $polozky): void {
    try {
        $pdo->prepare("DELETE FROM vyrobek_suroviny WHERE vyrobek_id = :v")
            ->execute(['v' => $vyrobek_id]);
        if (empty($polozky)) return;

        $ins = $pdo->prepare("
            INSERT INTO vyrobek_suroviny
                (vyrobek_id, surovina_id, mnozstvi, jednotka, poradi, poznamka)
            VALUES (:v, :s, :m, :j, :p, :pozn)
            ON DUPLICATE KEY UPDATE mnozstvi = VALUES(mnozstvi),
                                    jednotka = VALUES(jednotka),
                                    poradi   = VALUES(poradi),
                                    poznamka = VALUES(poznamka)
        ");
        foreach ($polozky as $i => $p) {
            $sid = (int) ($p['surovina_id'] ?? 0);
            if ($sid <= 0) continue;
            $ins->execute([
                'v'    => $vyrobek_id,
                's'    => $sid,
                'm'    => (float) ($p['mnozstvi'] ?? 0),
                'j'    => trim($p['jednotka'] ?? 'g') ?: 'g',
                'p'    => (int) ($p['poradi'] ?? $i),
                'pozn' => isset($p['poznamka']) && trim($p['poznamka']) !== '' ? trim($p['poznamka']) : null,
            ]);
        }
    } catch (PDOException $e) {
        // Pokud tabulky ještě neexistují, ignoruj - frontend zatím neposílá
        if (str_contains($e->getMessage(), "doesn't exist") || str_contains($e->getMessage(), 'Base table')) return;
        throw $e;
    }
}

// =============================================================
// POST - vytvořit
// =============================================================
if ($method === 'POST') {
    $d = json_input();
    if (empty($d['nazev'])) json_error('Chybí název');

    $stmt = $pdo->prepare("
        INSERT INTO vyrobky (cislo, ean, nazev, popis, slozeni, alergeny, nutricni_hodnoty,
                             kategorie_id, jednotka_id, cena_bez_dph, sazba_dph_id,
                             hmotnost_g, obsah, obsah_jednotka, obrazek_url, min_objednavka,
                             aktivni, oblibeny, je_akce, je_novinka, je_doprodej, je_vyprodano, zobrazit_na_pos, poradi,
                             priprava_min, kitchen_station_id)
        VALUES (:cislo,:ean,:nazev,:popis,:sloz,:aler,:nutr,:kat,:jed,:cena,:sazba,:hm,:ob,:obj,:obr,:min,:akt,:obl,:jak,:jno,:jdo,:jvy,:zpos,:por,:prip,:station)
    ");
    $stmt->execute([
        'cislo' => $d['cislo'] ?? null,
        'ean' => $d['ean'] ?? null,
        'nazev' => trim($d['nazev']),
        'popis' => $d['popis'] ?? null,
        'sloz' => $d['slozeni_text'] ?? ($d['slozeni'] ?? null),
        'aler' => $d['alergeny'] ?? null,
        'nutr' => is_array($d['nutricni_hodnoty'] ?? null)
                  ? json_encode($d['nutricni_hodnoty'], JSON_UNESCAPED_UNICODE)
                  : ($d['nutricni_hodnoty'] ?? null),
        'kat' => $d['kategorie_id'] ?? null,
        'jed' => $d['jednotka_id'] ?? 1,
        'cena' => (float) ($d['cena_bez_dph'] ?? 0),
        'sazba' => $d['sazba_dph_id'] ?? 1,
        'hm' => $d['hmotnost_g'] ?? null,
        'ob' => isset($d['obsah']) && $d['obsah'] !== '' ? (float) $d['obsah'] : null,
        'obj' => $d['obsah_jednotka'] ?? null,
        'obr' => $d['obrazek_url'] ?? null,
        'min' => $d['min_objednavka'] ?? 1,
        'akt' => isset($d['aktivni']) ? (int) $d['aktivni'] : 1,
        'obl' => isset($d['oblibeny']) ? (int) $d['oblibeny'] : 0,
        'jak' => isset($d['je_akce']) ? (int) $d['je_akce'] : 0,
        'jno' => isset($d['je_novinka']) ? (int) $d['je_novinka'] : 0,
        'jdo' => isset($d['je_doprodej']) ? (int) $d['je_doprodej'] : 0,
        'jvy' => isset($d['je_vyprodano']) ? (int) $d['je_vyprodano'] : 0,
        'zpos' => isset($d['zobrazit_na_pos']) ? (int) $d['zobrazit_na_pos'] : 1, // 🆕 v3.0.213 — default zobrazit
        'por' => $d['poradi'] ?? 0,
        // 🆕 v3.0.156 — doba přípravy (min) + kuchyňská stanice (propojení do KDS)
        'prip' => max(0, (int) ($d['priprava_min'] ?? 10)),
        'station' => empty($d['kitchen_station_id']) ? null : (int) $d['kitchen_station_id'],
    ]);
    $new_id = (int) $pdo->lastInsertId();

    // Volitelně - položky složení
    if (isset($d['slozeni_polozky']) && is_array($d['slozeni_polozky'])) {
        sync_slozeni($pdo, $new_id, $d['slozeni_polozky']);
    }

    json_response(['id' => $new_id], 201);
}

// =============================================================
// PUT - PARTIAL UPDATE (pouze pole, která přišla v requestu)
// =============================================================
if ($method === 'PUT') {
    $d = json_input();
    if (empty($d['id'])) json_error('Chybí ID');

    // Mapování JSON klíč => DB sloupec
    $mapa = [
        'cislo' => 'cislo', 'ean' => 'ean', 'nazev' => 'nazev', 'popis' => 'popis',
        'slozeni' => 'slozeni', 'alergeny' => 'alergeny',
        'nutricni_hodnoty' => 'nutricni_hodnoty',
        'kategorie_id' => 'kategorie_id', 'jednotka_id' => 'jednotka_id',
        'cena_bez_dph' => 'cena_bez_dph', 'sazba_dph_id' => 'sazba_dph_id',
        'vyrobni_cena' => 'vyrobni_cena', 'kalkulace_data' => 'kalkulace_data',
        'haccp_data' => 'haccp_data',
        'haccp_graf_id' => 'haccp_graf_id',
        'hmotnost_g' => 'hmotnost_g',
        'obsah' => 'obsah', 'obsah_jednotka' => 'obsah_jednotka',
        'obrazek_url' => 'obrazek_url',
        'min_objednavka' => 'min_objednavka',
        'aktivni' => 'aktivni', 'oblibeny' => 'oblibeny', 'poradi' => 'poradi',
        'je_akce' => 'je_akce', 'je_novinka' => 'je_novinka',
        'je_doprodej' => 'je_doprodej', 'je_vyprodano' => 'je_vyprodano',
        'zobrazit_na_pos' => 'zobrazit_na_pos', // 🆕 v3.0.213 — viditelnost na POS (KASA)
        // 🆕 v3.0.156 — doba přípravy + kuchyňská stanice (KDS)
        'priprava_min' => 'priprava_min', 'kitchen_station_id' => 'kitchen_station_id',
    ];

    $sets = []; $params = ['id' => (int) $d['id']];
    foreach ($mapa as $jk => $dbk) {
        if (array_key_exists($jk, $d)) {
            $sets[] = "$dbk = :$dbk";
            // Cast pro číselné sloupce
            if (in_array($jk, ['cena_bez_dph'])) {
                $params[$dbk] = (float) $d[$jk];
            } elseif (in_array($jk, ['aktivni','oblibeny','je_akce','je_novinka','je_doprodej','je_vyprodano','zobrazit_na_pos'])) {
                $params[$dbk] = (int) $d[$jk];
            } elseif ($jk === 'nutricni_hodnoty' || $jk === 'kalkulace_data' || $jk === 'haccp_data') {
                // Přijmi pole nebo JSON string
                $params[$dbk] = is_array($d[$jk])
                    ? json_encode($d[$jk], JSON_UNESCAPED_UNICODE)
                    : $d[$jk];
            } elseif ($jk === 'vyrobni_cena') {
                $params[$dbk] = $d[$jk] === null || $d[$jk] === '' ? null : (float) $d[$jk];
            } elseif ($jk === 'haccp_graf_id') {
                $params[$dbk] = ($d[$jk] === null || $d[$jk] === '' || (int) $d[$jk] === 0) ? null : (int) $d[$jk];
            } elseif ($jk === 'priprava_min') {
                $params[$dbk] = max(0, (int) $d[$jk]);
            } elseif ($jk === 'kitchen_station_id') {
                $params[$dbk] = ($d[$jk] === null || $d[$jk] === '' || (int) $d[$jk] === 0) ? null : (int) $d[$jk];
            } else {
                $params[$dbk] = $d[$jk];
            }
        }
    }
    if (empty($sets)) json_error('Žádné změny');

    $sql = "UPDATE vyrobky SET " . implode(', ', $sets) . " WHERE id = :id";
    $pdo->prepare($sql)->execute($params);

    // Volitelně - položky složení
    if (isset($d['slozeni_polozky']) && is_array($d['slozeni_polozky'])) {
        sync_slozeni($pdo, (int) $d['id'], $d['slozeni_polozky']);
    }
    json_response(['ok' => true]);
}

// =============================================================
// DELETE
// =============================================================
if ($method === 'DELETE') {
    require_super_admin(); // jen super admin smí mazat / deaktivovat výrobky
    $id = (int) ($_GET['id'] ?? 0);
    if (!$id) json_error('Chybí ID');

    // Pokud je výrobek použitý kdekoli (objednávka/DL/FA), jen ho skryj (soft-delete)
    // 🐛 fix v2.9.169 — native PDO neumožňuje reuse :id 3x; distinct placeholdery.
    $cnt = $pdo->prepare("
        SELECT
            (SELECT COUNT(*) FROM objednavky_polozky    WHERE vyrobek_id = :id1) +
            (SELECT COUNT(*) FROM dodaci_list_polozky   WHERE vyrobek_id = :id2) +
            (SELECT COUNT(*) FROM faktura_polozky       WHERE vyrobek_id = :id3)
    ");
    $cnt->execute(['id1' => $id, 'id2' => $id, 'id3' => $id]);
    if ($cnt->fetchColumn() > 0) {
        $pdo->prepare("UPDATE vyrobky SET aktivni = 0 WHERE id = :id")->execute(['id' => $id]);
        json_response(['ok' => true, 'deactivated' => true]);
    }

    // === AUTO-SNAPSHOT před TRVALÝM smazáním výrobku ===
    require_once __DIR__ . '/_zaloha_helper.php';
    zaloha_snapshot($pdo, 'Před smazáním výrobku ID ' . $id);

    $pdo->prepare("DELETE FROM vyrobky WHERE id = :id")->execute(['id' => $id]);
    json_response(['ok' => true, 'deleted' => true]);
}

json_error('Neplatná metoda', 405);
