<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/_admin_auth.php';
session_secure_start(); // 🔒 v3.0.349 — start session PŘED B2B gate (jinak $_SESSION prázdné → i vlastník faktury 401)

// 🆕 v2.9.164 — public access přes signed token z e-mailu (alternativa k admin login).
// Token je vázaný na konkrétní fakturu, takže leak nepustí útočníka jinam.
$_email_token = $_GET['token'] ?? '';
$_token_auth = false;
if ($_email_token !== '') {
    require_once __DIR__ . '/_email_token.php';
    $_tok_row = verify_email_token(db(), $_email_token, 'fa');
    if ($_tok_row) {
        $_GET['id'] = (int) $_tok_row['doklad_id'];
        $_token_auth = true;
    }
}
if (!$_token_auth) {
    // 🆕 v3.0.323 — přihlášený B2B odběratel smí otevřít VLASTNÍ fakturu z portálu
    //   (dřív tlačítka 💰/📄 v b2b/ padala na require_admin → 401). Scoped na vlastní doklad.
    $_odb_id = (int) ($_SESSION['odberatel_id'] ?? 0);
    $_odb_ok = false;
    if ($_odb_id) {
        $_fa_id = (int) ($_GET['id'] ?? 0);
        if ($_fa_id) {
            $chk = db()->prepare("SELECT 1 FROM faktury WHERE id = :f AND odberatel_id = :o LIMIT 1");
            $chk->execute(['f' => $_fa_id, 'o' => $_odb_id]);
            $_odb_ok = (bool) $chk->fetchColumn();
        }
    }
    if (!$_odb_ok) require_admin();
}

$pdo = db();

// =============================================================
// VYTVOŘENÍ FAKTURY (POST only - chrání proti CSRF)
// =============================================================
if (($_GET['action'] ?? '') === 'vytvor') {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['error' => 'Vytvoření faktury vyžaduje POST']);
        exit;
    }

    header('Content-Type: application/json; charset=utf-8');

    // ID objednávky lze poslat v query nebo v JSON body
    $body   = json_input();
    $obj_id = (int) ($_GET['objednavka_id'] ?? $body['objednavka_id'] ?? 0);
    $dl_in  = (int) ($_GET['dodaci_list_id'] ?? $body['dodaci_list_id'] ?? 0);

    // 🆕 v3.0.233 — fakturace PŘÍMO z dodacího listu (ruční DL bez objednávky).
    // Když DL má objednávku, použij objednávkovou cestu níž (snapshot položek je tam).
    if ($dl_in && !$obj_id) {
        $pdo->beginTransaction();
        try {
            $st = $pdo->prepare("SELECT * FROM dodaci_listy WHERE id = :id");
            $st->execute(['id' => $dl_in]);
            $dl = $st->fetch();
            if (!$dl) throw new Exception('Dodací list nenalezen');
            if (!empty($dl['objednavka_id'])) { $obj_id = (int) $dl['objednavka_id']; } // → standardní cesta
            else {
                // Už fakturováno?
                $st = $pdo->prepare("SELECT faktura_id FROM faktury_dodaci_listy WHERE dodaci_list_id = :d LIMIT 1");
                $st->execute(['d' => $dl_in]);
                if ($ex = $st->fetchColumn()) { $pdo->commit(); echo json_encode(['faktura_id' => (int) $ex, 'existing' => true]); exit; }

                $spl = (int) ($pdo->query("SELECT splatnost_dni FROM odberatele WHERE id = " . (int) $dl['odberatel_id'])->fetchColumn() ?: 14);
                // DPH rozpad z položek DL
                $sums = $pdo->prepare("SELECT
                        COALESCE(SUM(mnozstvi * cena_bez_dph), 0) AS bez,
                        COALESCE(SUM(mnozstvi * cena_bez_dph * COALESCE(sazba_dph,0)/100), 0) AS dph
                    FROM dodaci_list_polozky WHERE dodaci_list_id = :d");
                $sums->execute(['d' => $dl_in]);
                $s = $sums->fetch() ?: ['bez' => 0, 'dph' => 0];
                $bez = round((float) $s['bez'], 2);
                $dph = round((float) $s['dph'], 2);
                $cel = ($bez + $dph) > 0 ? round($bez + $dph, 2) : (float) $dl['castka_celkem'];
                if ($bez == 0 && $cel > 0) { $bez = $cel; } // fallback když položky nemají rozpad

                $cislo_fa = dalsi_cislo($pdo, 'FA', (int) date('Y'));
                $vs = preg_replace('/\D/', '', $cislo_fa);
                $pdo->prepare("
                    INSERT INTO faktury (cislo, odberatel_id, datum_vystaveni, datum_dph, datum_splatnosti,
                                         castka_bez_dph, castka_dph, castka_celkem, variabilni_symbol)
                    VALUES (:c,:o,CURDATE(),CURDATE(),DATE_ADD(CURDATE(), INTERVAL :sp DAY),:bez,:dph,:cel,:vs)
                ")->execute(['c' => $cislo_fa, 'o' => $dl['odberatel_id'], 'sp' => $spl, 'bez' => $bez, 'dph' => $dph, 'cel' => $cel, 'vs' => $vs]);
                $fa_id = (int) $pdo->lastInsertId();
                $pdo->prepare("INSERT INTO faktury_dodaci_listy (faktura_id, dodaci_list_id) VALUES (:f,:d)")->execute(['f' => $fa_id, 'd' => $dl_in]);
                $pdo->prepare("UPDATE dodaci_listy SET fakturovano = 1 WHERE id = :id")->execute(['id' => $dl_in]);
                $pdo->commit();
                echo json_encode(['faktura_id' => $fa_id, 'cislo' => $cislo_fa]);
                exit;
            }
        } catch (Exception $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            http_response_code(500); echo json_encode(['error' => $e->getMessage()]); exit;
        }
    }

    if (!$obj_id) { http_response_code(400); echo json_encode(['error' => 'Chybí ID']); exit; }

    $pdo->beginTransaction();
    try {
        // 1) Objednávka
        $stmt = $pdo->prepare("SELECT * FROM objednavky WHERE id = :id");
        $stmt->execute(['id' => $obj_id]);
        $obj = $stmt->fetch();
        if (!$obj) throw new Exception('Objednávka nenalezena');

        // 2) Existuje už faktura pro tuto objednávku? Pokud ano, vrátíme ji.
        $stmt = $pdo->prepare("
            SELECT f.id FROM faktury f
            JOIN faktury_dodaci_listy fdl ON fdl.faktura_id = f.id
            JOIN dodaci_listy dl ON dl.id = fdl.dodaci_list_id
            WHERE dl.objednavka_id = :o LIMIT 1
        ");
        $stmt->execute(['o' => $obj_id]);
        $exist = $stmt->fetchColumn();
        if ($exist) {
            $pdo->commit();
            echo json_encode(['faktura_id' => (int) $exist, 'existing' => true]);
            exit;
        }

        // 3) Splatnost odběratele
        $stmt = $pdo->prepare("SELECT splatnost_dni FROM odberatele WHERE id = :id");
        $stmt->execute(['id' => $obj['odberatel_id']]);
        $spl = (int) ($stmt->fetchColumn() ?: 14);

        // 4) Najdeme nebo vytvoříme dodací list
        $stmt = $pdo->prepare("SELECT id FROM dodaci_listy WHERE objednavka_id = :id");
        $stmt->execute(['id' => $obj_id]);
        $dl_id = $stmt->fetchColumn();

        if (!$dl_id) {
            $cislo_dl = dalsi_cislo($pdo, 'DL', (int) date('Y', strtotime($obj['datum_dodani'])));
            $pdo->prepare("
                INSERT INTO dodaci_listy (cislo, objednavka_id, odberatel_id, misto_dodani_id,
                                          datum_vystaveni, datum_dodani, castka_celkem, fakturovano)
                VALUES (:c,:o,:odb,:m,CURDATE(),:d,:cel,1)
            ")->execute([
                'c' => $cislo_dl, 'o' => $obj_id, 'odb' => $obj['odberatel_id'],
                'm' => $obj['misto_dodani_id'], 'd' => $obj['datum_dodani'],
                'cel' => $obj['castka_celkem'],
            ]);
            $dl_id = (int) $pdo->lastInsertId();

            // FIX #3: SNAPSHOT položek do dodaciho listu
            // Po této chvíli je objednávka uzamčena pro úpravy a faktura
            // se už nezmění, ani když někdo objednávku upraví.
            $pdo->prepare("
                INSERT INTO dodaci_list_polozky
                    (dodaci_list_id, vyrobek_id, vyrobek_cislo, vyrobek_nazev, jednotka,
                     mnozstvi, cena_bez_dph, sazba_dph, poznamka)
                SELECT :dl, p.vyrobek_id, v.cislo,
                       COALESCE(v.nazev, p.vyrobek_nazev),
                       COALESCE(j.kod, p.jednotka),
                       p.mnozstvi, p.cena_bez_dph, p.sazba_dph, p.poznamka
                FROM objednavky_polozky p
                LEFT JOIN vyrobky v ON v.id = p.vyrobek_id
                LEFT JOIN jednotky j ON j.id = v.jednotka_id
                WHERE p.objednavka_id = :o
            ")->execute(['dl' => $dl_id, 'o' => $obj_id]);
        } else {
            $pdo->prepare("UPDATE dodaci_listy SET fakturovano = 1 WHERE id = :id")
                ->execute(['id' => $dl_id]);
        }

        // 5) Vytvoříme fakturu - atomické číslo
        $cislo_fa = dalsi_cislo($pdo, 'FA', (int) date('Y'));
        $vs       = preg_replace('/\D/', '', $cislo_fa);

        $pdo->prepare("
            INSERT INTO faktury (cislo, odberatel_id, datum_vystaveni, datum_dph, datum_splatnosti,
                                 castka_bez_dph, castka_dph, castka_celkem, variabilni_symbol)
            VALUES (:c,:o,CURDATE(),CURDATE(),DATE_ADD(CURDATE(), INTERVAL :sp DAY),:bez,:dph,:cel,:vs)
        ")->execute([
            'c'   => $cislo_fa, 'o' => $obj['odberatel_id'], 'sp' => $spl,
            'bez' => $obj['castka_bez_dph'], 'dph' => $obj['castka_dph'],
            'cel' => $obj['castka_celkem'], 'vs' => $vs,
        ]);
        $fa_id = (int) $pdo->lastInsertId();

        $pdo->prepare("INSERT INTO faktury_dodaci_listy (faktura_id, dodaci_list_id) VALUES (:f,:d)")
            ->execute(['f' => $fa_id, 'd' => $dl_id]);

        $pdo->commit();
        echo json_encode(['faktura_id' => $fa_id, 'cislo' => $cislo_fa]);
        exit;
    } catch (Exception $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        error_log('faktura.php vytvor: ' . $e->getMessage());
        http_response_code(500);
        // Generická zpráva - detaily v logu
        echo json_encode(['error' => 'Nepodařilo se vystavit fakturu']);
        exit;
    }
}

// =============================================================
// ZOBRAZENÍ FAKTURY - HTML pro tisk
// Podporuje single ?id=X i bulk ?ids=1,2,3 (tisk více faktur za sebou)
// =============================================================
function nacti_fakturu(PDO $pdo, int $id): ?array {
    $stmt = $pdo->prepare("
        SELECT f.*, od.nazev AS odb_nazev_aktualni, od.ico AS odb_ico_aktualni,
               od.dic AS odb_dic_aktualni, od.ulice AS odb_ulice_aktualni,
               od.mesto AS odb_mesto_aktualni, od.psc AS odb_psc_aktualni
        FROM faktury f JOIN odberatele od ON od.id = f.odberatel_id WHERE f.id = :id
    ");
    $stmt->execute(['id' => $id]);
    $f = $stmt->fetch();
    if (!$f) return null;

    // Pro ruční fakturu použij snapshot adresy uložené v okamžiku vystavení.
    // Pro automatickou fakturu (z objednávky) použij aktuální adresu odběratele.
    if (!empty($f['rucni'])) {
        $f['odb_nazev'] = $f['odb_nazev_snapshot'] ?: $f['odb_nazev_aktualni'];
        $f['ico']       = $f['odb_ico_snapshot']   ?: $f['odb_ico_aktualni'];
        $f['dic']       = $f['odb_dic_snapshot']   ?: $f['odb_dic_aktualni'];
        $f['ulice']     = $f['odb_ulice_snapshot'] ?: $f['odb_ulice_aktualni'];
        $f['mesto']     = $f['odb_mesto_snapshot'] ?: $f['odb_mesto_aktualni'];
        $f['psc']       = $f['odb_psc_snapshot']   ?: $f['odb_psc_aktualni'];
    } else {
        $f['odb_nazev'] = $f['odb_nazev_aktualni'];
        $f['ico']       = $f['odb_ico_aktualni'];
        $f['dic']       = $f['odb_dic_aktualni'];
        $f['ulice']     = $f['odb_ulice_aktualni'];
        $f['mesto']     = $f['odb_mesto_aktualni'];
        $f['psc']       = $f['odb_psc_aktualni'];
    }

    // Položky - rozdílné zdroje pro ruční vs automatickou fakturu
    if (!empty($f['rucni'])) {
        $stmt = $pdo->prepare("
            SELECT vyrobek_cislo, vyrobek_nazev, jednotka, mnozstvi,
                   cena_bez_dph, sazba_dph, poznamka,
                   NULL AS dl_cislo, NULL AS objednavka_cislo, NULL AS datum_dodani
            FROM faktura_polozky
            WHERE faktura_id = :id
            ORDER BY poradi, id
        ");
        $stmt->execute(['id' => $id]);
        $polozky = $stmt->fetchAll();
    } else {
        $stmt = $pdo->prepare("
            SELECT dlp.*,
                   dl.cislo AS dl_cislo,
                   o.cislo AS objednavka_cislo,
                   o.datum_dodani
            FROM faktury_dodaci_listy fdl
            JOIN dodaci_listy dl ON dl.id = fdl.dodaci_list_id
            LEFT JOIN objednavky o ON o.id = dl.objednavka_id
            JOIN dodaci_list_polozky dlp ON dlp.dodaci_list_id = dl.id
            WHERE fdl.faktura_id = :id
            ORDER BY o.datum_dodani, dlp.vyrobek_nazev
        ");
        $stmt->execute(['id' => $id]);
        $polozky = $stmt->fetchAll();

        if (empty($polozky)) {
            error_log("faktura.php: DL nemá snapshot, fallback pro fakturu $id");
            $stmt = $pdo->prepare("
                SELECT p.cena_bez_dph, p.mnozstvi, p.sazba_dph, p.poznamka,
                       v.cislo AS vyrobek_cislo, v.nazev AS vyrobek_nazev,
                       j.kod AS jednotka,
                       o.cislo AS objednavka_cislo, o.datum_dodani
                FROM faktury_dodaci_listy fdl
                JOIN dodaci_listy dl ON dl.id = fdl.dodaci_list_id
                JOIN objednavky o ON o.id = dl.objednavka_id
                JOIN objednavky_polozky p ON p.objednavka_id = o.id
                JOIN vyrobky v ON v.id = p.vyrobek_id
                LEFT JOIN jednotky j ON j.id = v.jednotka_id
                WHERE fdl.faktura_id = :id ORDER BY o.datum_dodani, v.nazev
            ");
            $stmt->execute(['id' => $id]);
            $polozky = $stmt->fetchAll();
        }
    }

    $dph_rozpis = [];
    foreach ($polozky as $p) {
        $bez = $p['cena_bez_dph'] * $p['mnozstvi'];
        $sazba = (float) $p['sazba_dph'];
        $dph = $bez * $sazba / 100;
        if (!isset($dph_rozpis[$sazba])) $dph_rozpis[$sazba] = ['bez' => 0, 'dph' => 0];
        $dph_rozpis[$sazba]['bez'] += $bez;
        $dph_rozpis[$sazba]['dph'] += $dph;
    }
    // 🆕 v3.0.356 — zaokrouhli rozpis per sazba a haléřový zbytek vlož do sazby s největším
    // základem → Σ rozpisu sedí PŘESNĚ na hlavičkové castka_* (rozpis == „K úhradě") i u
    // vícesazbových faktur. Bez toho mohl Σ(zaokrouhlených sazeb) ≠ uložená castka_* o haléř
    // = vnitřně nekonzistentní daňový doklad.
    if ($dph_rozpis) {
        foreach ($dph_rozpis as $s => &$r) { $r['bez'] = round($r['bez'], 2); $r['dph'] = round($r['dph'], 2); }
        unset($r);
        $maxKey = null; $maxBez = -INF;
        foreach ($dph_rozpis as $s => $r) { if (abs($r['bez']) > $maxBez) { $maxBez = abs($r['bez']); $maxKey = $s; } }
        if ($maxKey !== null) {
            $resBez = round((float) $f['castka_bez_dph'], 2) - array_sum(array_column($dph_rozpis, 'bez'));
            $resDph = round((float) $f['castka_dph'], 2)    - array_sum(array_column($dph_rozpis, 'dph'));
            $dph_rozpis[$maxKey]['bez'] = round($dph_rozpis[$maxKey]['bez'] + $resBez, 2);
            $dph_rozpis[$maxKey]['dph'] = round($dph_rozpis[$maxKey]['dph'] + $resDph, 2);
        }
    }
    return ['f' => $f, 'polozky' => $polozky, 'dph_rozpis' => $dph_rozpis];
}

// Sběr ID — single nebo bulk
$ids = [];
if (!empty($_GET['ids'])) {
    $ids = array_filter(array_map('intval', explode(',', (string) $_GET['ids'])));
} elseif (!empty($_GET['id'])) {
    $ids = [(int) $_GET['id']];
}
if (empty($ids)) { http_response_code(400); die('Chybí ID'); }

// 🔒 v3.0.349 — IDOR fix: bulk ?ids= dřív obešlo single-id scoping z v323.
// B2B odběratel i e-mail token smí jen VLASTNÍ faktury; admin (prošel require_admin) má plný přístup.
if (!empty($_token_auth)) {
    $ids = array_values(array_intersect($ids, [(int) ($_GET['id'] ?? 0)])); // token = jen jeho doklad
} elseif (!empty($_odb_id) && !empty($_odb_ok)) {
    $ph = implode(',', array_fill(0, count($ids), '?'));
    $q = $pdo->prepare("SELECT id FROM faktury WHERE id IN ($ph) AND odberatel_id = ?");
    $q->execute([...array_map('intval', $ids), (int) $_odb_id]);
    $ids = array_map('intval', $q->fetchAll(PDO::FETCH_COLUMN));
}
if (empty($ids)) { http_response_code(403); die('Přístup k faktuře zamítnut'); }

$faktury_render = [];
foreach ($ids as $i) {
    $data = nacti_fakturu($pdo, $i);
    if ($data) $faktury_render[] = $data;
}
if (empty($faktury_render)) { http_response_code(404); die('Žádná z faktur nenalezena'); }

// Pro single fakturu zachovej původní proměnné (pro kompatibilitu s případnými přídavky)
$f = $faktury_render[0]['f'];
$polozky = $faktury_render[0]['polozky'];
$dph_rozpis = $faktury_render[0]['dph_rozpis'];

$bulk_count = count($faktury_render);
$autoprint = !empty($_GET['autoprint']);

// 💸 v3.0.287 — QR Platba (SPD — český standard „QR Platba", spec SPAYD 1.0).
//   IBAN: 1) platby config (payment_methods_config_json → libovolný ucet_iban),
//         2) fallback z firma_banka (IBAN přímo nebo CZ účet [předčíslí-]číslo/kód → IBAN).
//   QR se renderuje client-side (qrcode.min.js) — faktura je vždy v prohlížeči (tisk/e-mail link).
if (!function_exists('faktura_cz_ucet_to_iban')) {
    function faktura_cz_ucet_to_iban(string $ucet): string {
        $compact = strtoupper(preg_replace('/\s+/', '', $ucet));
        if (preg_match('/^CZ\d{22}$/', $compact)) return $compact;           // už IBAN
        if (!preg_match('#^(?:(\d{1,6})-)?(\d{2,10})/(\d{4})$#', $compact, $m)) return '';
        $prefix = str_pad($m[1] ?? '', 6, '0', STR_PAD_LEFT);
        $number = str_pad($m[2], 10, '0', STR_PAD_LEFT);
        $bban   = $m[3] . $prefix . $number;                                  // 20 číslic: banka+předčíslí+číslo
        // IBAN kontrolní číslice: BBAN + "CZ00" (C=12,Z=35) → mod 97 → 98-mod
        $check = $bban . '123500';
        $mod = 0;
        for ($i = 0, $n = strlen($check); $i < $n; $i++) $mod = ($mod * 10 + (int) $check[$i]) % 97;
        return 'CZ' . str_pad((string) (98 - $mod), 2, '0', STR_PAD_LEFT) . $bban;
    }
    /** Sestaví SPD řetězec; vrátí '' pokud chybí IBAN nebo nekladná částka / dobropis. */
    function faktura_spd_string(array $f, PDO $pdo): string {
        if (!empty($f['je_dobropis'])) return '';                             // dobropis = vratka, ne platba
        $castka = round((float) ($f['castka_celkem'] ?? 0), 2);
        if ($castka <= 0) return '';
        // 1) IBAN z platby configu
        $iban = '';
        try {
            $raw = nastaveni_get($pdo, 'payment_methods_config_json', '');
            $cfg = $raw ? json_decode($raw, true) : null;
            if (is_array($cfg)) foreach ($cfg as $m) {
                if (!empty($m['ucet_iban'])) { $iban = faktura_cz_ucet_to_iban((string) $m['ucet_iban']); if ($iban) break; }
            }
        } catch (Throwable $e) { /* ignore */ }
        if ($iban === '') $iban = faktura_cz_ucet_to_iban(firma('banka', ''));  // 2) fallback z bankovního spojení
        if ($iban === '') return '';
        $vs  = preg_replace('/\D/', '', (string) ($f['variabilni_symbol'] ?? ''));
        $am  = number_format($castka, 2, '.', '');
        // MSG: bez diakritiky a hvězdiček, max 60 znaků (SPAYD limit)
        $msg = 'Faktura ' . ($f['cislo'] ?? '');
        $msg = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $msg) ?: $msg;
        $msg = mb_substr(str_replace('*', ' ', $msg), 0, 60);
        $spd = 'SPD*1.0*ACC:' . $iban . '*AM:' . $am . '*CC:CZK';
        if ($vs !== '') $spd .= '*X-VS:' . $vs;
        $spd .= '*MSG:' . $msg;
        return $spd;
    }
}
?>
<!DOCTYPE html>
<html lang="cs">
<head>
<meta charset="UTF-8">
<title><?= $bulk_count > 1 ? 'Tisk ' . $bulk_count . ' faktur' : esc($f['cislo']) . ' - faktura' ?></title>
<style>
  *{box-sizing:border-box;margin:0;padding:0}
  body{font-family:'Helvetica Neue',Arial,sans-serif;color:#2C2C2A;background:#f5f5f0;padding:20px}
  .toolbar{max-width:210mm;margin:0 auto 16px;padding:12px 16px;background:#fff;border-radius:8px;display:flex;gap:8px;justify-content:flex-end;box-shadow:0 1px 3px rgba(0,0,0,.08)}
  .toolbar .btn{padding:8px 16px;border:1px solid rgba(0,0,0,.16);background:#fff;border-radius:6px;cursor:pointer;font-size:14px;font-family:inherit}
  .toolbar .btn-primary{background:#BA7517;color:#fff;border-color:#BA7517}
  .info{color:#888;font-size:13px;margin-right:auto;align-self:center}
  .page{max-width:210mm;margin:0 auto;padding:18mm 20mm;background:#fff;min-height:297mm;box-shadow:0 2px 8px rgba(0,0,0,.1);font-size:11pt}
  .header{display:flex;justify-content:space-between;margin-bottom:12mm}
  h1{font-size:28pt;color:#BA7517;font-weight:600}
  .cislo{font-size:14pt;margin-top:4mm;color:#555}
  .firma{text-align:right;line-height:1.6}
  .firma .nazev{font-weight:600;font-size:13pt;margin-bottom:4px}
  .parties{display:grid;grid-template-columns:1fr 1fr;gap:12mm;margin-bottom:8mm}
  .parties h3{font-size:9pt;color:#888;margin-bottom:4mm;text-transform:uppercase;letter-spacing:1px;font-weight:500}
  .parties .name{font-size:13pt;font-weight:600;margin-bottom:4px}
  .parties .row{line-height:1.7;font-size:11pt}
  .info-box{background:#FAEEDA;padding:5mm 8mm;border-radius:6px;margin-bottom:8mm;display:grid;grid-template-columns:repeat(3,1fr);gap:6mm 8mm}
  .info-box .lbl{color:#855;font-size:9pt;text-transform:uppercase;margin-bottom:2px}
  .info-box .val{font-weight:600;font-size:12pt;color:#854F0B}
  table.items{width:100%;border-collapse:collapse;margin-bottom:4mm}
  table.items th{background:#F1EFE8;padding:3.5mm 4mm;text-align:left;font-size:9pt;color:#888;text-transform:uppercase;border-bottom:1px solid #aaa;font-weight:500;letter-spacing:.5px}
  table.items td{padding:3.5mm 4mm;border-bottom:1px solid #E5E3DD;font-size:10pt}
  table.items td.num,table.items th.num{text-align:right}
  table.items .sub{font-size:9pt;color:#888}
  .bottom{display:grid;grid-template-columns:1fr 1fr;gap:8mm;margin-top:6mm}
  .dph-table{width:100%;border-collapse:collapse}
  .dph-table th{background:#F1EFE8;padding:2mm 3mm;font-size:9pt;color:#888;text-transform:uppercase;text-align:left}
  .dph-table th.num,.dph-table td.num{text-align:right}
  .dph-table td{padding:2.5mm 3mm;font-size:10pt;border-bottom:1px solid #E5E3DD}
  .summary-final{background:#FAEEDA;padding:5mm 7mm;border-radius:6px;text-align:center}
  .summary-final .lbl{color:#855;font-size:10pt;text-transform:uppercase;margin-bottom:4px}
  .summary-final .val{font-size:22pt;font-weight:700;color:#854F0B}
  .foot{margin-top:16mm;padding-top:4mm;border-top:1px solid #ddd;font-size:9pt;color:#666}
  .foot-row{display:flex;justify-content:space-between;gap:12mm;margin-bottom:3mm}
  .foot-cell{flex:1}
  .foot-cell .lbl{font-size:8pt;color:#aaa;text-transform:uppercase;letter-spacing:0.4pt;margin-bottom:1mm}
  .foot-cell .val{color:#333;font-weight:500}
  .foot-custom{margin-top:3mm;text-align:center;font-size:8.5pt;color:#888;line-height:1.45;white-space:pre-line}
  .foot-meta{margin-top:3mm;text-align:center;font-size:8pt;color:#bbb;border-top:1px dotted #eee;padding-top:2mm}
  @media print {
    body { background: #fff; padding: 0; margin: 0; }
    .toolbar { display: none; }
    .page {
      box-shadow: none;
      padding: 8mm 12mm 6mm 12mm;
      max-width: none;
      margin: 0;
      min-height: 0;
      font-size: 9.5pt;
    }
    /* Vypnout záhlaví/zápatí prohlížeče (URL, datum, číslo stránky) */
    @page {
      size: A4;
      margin: 0;
    }
    /* Zhuštění pro print, aby se vlezlo na 1 list */
    h1 { font-size: 22pt; }
    .cislo { font-size: 12pt; margin-top: 2mm; }
    .firma .nazev { font-size: 11pt; }
    .header { margin-bottom: 6mm; }
    .parties { margin-bottom: 5mm; gap: 8mm; }
    .parties .name { font-size: 11pt; }
    .parties .row { line-height: 1.45; font-size: 9.5pt; }
    .info-box { padding: 3mm 5mm; margin-bottom: 5mm; gap: 3mm 5mm; }
    .info-box .val { font-size: 10.5pt; }
    table.items { margin-bottom: 3mm; }
    table.items th { padding: 2.2mm 3mm; font-size: 8pt; }
    table.items td { padding: 2mm 3mm; font-size: 9pt; }
    table.items .sub { font-size: 8pt; }
    .bottom { margin-top: 4mm; gap: 6mm; }
    .dph-table th { padding: 1.5mm 2.5mm; font-size: 8pt; }
    .dph-table td { padding: 2mm 2.5mm; font-size: 9pt; }
    .summary-final { padding: 3mm 5mm; }
    .summary-final .lbl { font-size: 9pt; }
    .summary-final .val { font-size: 18pt; }
    .foot { margin-top: 8mm; padding-top: 3mm; font-size: 8pt; }
    .foot-row { margin-bottom: 2mm; gap: 8mm; }
    .foot-custom { margin-top: 2mm; font-size: 8pt; }
    .foot-meta { margin-top: 2mm; font-size: 7.5pt; padding-top: 1.5mm; }
    /* Zabránit zalomení uvnitř */
    table.items, .summary-final, .foot, .bottom { page-break-inside: avoid; }
    table.items tr { page-break-inside: avoid; }
    /* Bulk tisk — každá faktura na vlastní stránce */
    .page + .page { page-break-before: always; }
  }
  /* I v náhledu zviditelnit oddělení mezi fakturami */
  .page + .page { margin-top: 20px; }
</style>
</head>
<body>
<div class="toolbar">
  <span class="info">
    <?php if ($bulk_count > 1): ?>
      📦 <strong>Hromadný tisk <?= $bulk_count ?> faktur</strong> · v dialogu „Uložit jako PDF" pro 1 PDF s více stránkami
    <?php else: ?>
      💡 Pro PDF klikněte „Tisk" → v dialogu „Uložit jako PDF"
    <?php endif; ?>
  </span>
  <button class="btn" onclick="window.history.back()">← Zpět</button>
  <button class="btn btn-primary" onclick="window.print()">🖨 Tisk / Uložit PDF</button>
</div>
<?php foreach ($faktury_render as $idx => $fr):
    $f = $fr['f'];
    $polozky = $fr['polozky'];
    $dph_rozpis = $fr['dph_rozpis'];
?>
<div class="page">
  <div class="header">
    <div>
      <?php $zobrazitLogo = nastaveni_get($pdo, 'firma_logo_na_dokladech', '1') === '1';
            $logoUrl = $zobrazitLogo ? nastaveni_get($pdo, 'firma_logo_url', '') : '';
            if ($logoUrl): ?>
        <img src="<?= esc($logoUrl) ?>" style="max-height:18mm;max-width:60mm;margin-bottom:6mm;object-fit:contain" alt="Logo">
      <?php endif; ?>
      <?php // 🆕 v3.0.268 — dobropis = opravný daňový doklad (vlastní nadpis + vazba na původní FA)
            $jeDobropis = !empty($f['je_dobropis']); ?>
      <h1><?= $jeDobropis ? 'Opravný daňový doklad (dobropis)' : 'Faktura' ?></h1>
      <div class="cislo">č. <strong><?= esc($f['cislo']) ?></strong></div>
      <?php if ($jeDobropis && !empty($f['puvodni_faktura_id'])):
          $puvC = $pdo->query("SELECT cislo FROM faktury WHERE id = " . (int) $f['puvodni_faktura_id'])->fetchColumn();
          if ($puvC): ?><div style="font-size:9pt;color:#666;margin-top:1mm">k faktuře <strong><?= esc($puvC) ?></strong></div><?php endif;
      endif; ?>
    </div>
    <div class="firma">
      <div class="nazev"><?= esc(firma('nazev', 'APPEK B2B')) ?></div>
      <?php if (firma('ulice')): ?><div><?= esc(firma('ulice')) ?></div><?php endif; ?>
      <?php if (firma('mesto') || firma('psc')): ?><div><?= esc(trim(firma('mesto') . ' ' . firma('psc'))) ?></div><?php endif; ?>
      <?php if (firma('ico')): ?><div>IČO: <?= esc(firma('ico')) ?></div><?php endif; ?>
      <?php if (firma('dic')): ?><div>DIČ: <?= esc(firma('dic')) ?></div><?php endif; ?>
    </div>
  </div>
  <div class="parties">
    <div>
      <h3>Dodavatel</h3>
      <div class="name"><?= esc(firma('nazev', 'APPEK B2B')) ?></div>
      <div class="row">
        <?php if (firma('ulice')): ?><?= esc(firma('ulice')) ?><br><?php endif; ?>
        <?php if (firma('mesto') || firma('psc')): ?><?= esc(trim(firma('mesto') . ' ' . firma('psc'))) ?><br><?php endif; ?>
        <?php if (firma('ico')): ?>IČO: <?= esc(firma('ico')) ?><br><?php endif; ?>
        <?php if (firma('dic')): ?>DIČ: <?= esc(firma('dic')) ?><?php endif; ?>
      </div>
    </div>
    <div>
      <h3>Odběratel</h3>
      <div class="name"><?= esc($f['odb_nazev']) ?></div>
      <div class="row">
        <?php if ($f['ulice']): ?><?= esc($f['ulice']) ?><br><?php endif; ?>
        <?php if ($f['mesto'] || $f['psc']): ?><?= esc(trim($f['mesto'] . ' ' . $f['psc'])) ?><br><?php endif; ?>
        <?php if ($f['ico']): ?>IČO: <?= esc($f['ico']) ?><br><?php endif; ?>
        <?php if ($f['dic']): ?>DIČ: <?= esc($f['dic']) ?><?php endif; ?>
      </div>
    </div>
  </div>
  <div class="info-box">
    <div><div class="lbl">Datum vystavení</div><div class="val"><?= fmt_date($f['datum_vystaveni']) ?></div></div>
    <div><div class="lbl">Datum splatnosti</div><div class="val"><?= fmt_date($f['datum_splatnosti']) ?></div></div>
    <div><div class="lbl">Datum zd. plnění</div><div class="val"><?= fmt_date($f['datum_dph']) ?></div></div>
    <div><div class="lbl">Bankovní spojení</div><div class="val"><?= esc(firma('banka', '—')) ?></div></div>
    <div><div class="lbl">Variabilní symbol</div><div class="val"><?= esc($f['variabilni_symbol']) ?></div></div>
    <div><div class="lbl">Forma úhrady</div><div class="val">Bankovní převod</div></div>
  </div>
  <table class="items">
    <thead><tr><th style="width:18mm">Kód</th><th>Položka</th><th class="num" style="width:22mm">Množství</th><th class="num" style="width:22mm">Cena</th><th class="num" style="width:14mm">DPH</th><th class="num" style="width:28mm">Celkem s DPH</th></tr></thead>
    <tbody>
      <?php foreach ($polozky as $p):
        $bez = $p['cena_bez_dph'] * $p['mnozstvi'];
        $sazba = (float) $p['sazba_dph'];
        $dph = $bez * $sazba / 100;
      ?>
        <tr>
          <td><?= esc($p['vyrobek_cislo']) ?></td>
          <td>
            <strong><?= esc($p['vyrobek_nazev']) ?></strong>
            <?php if (!empty($p['datum_dodani'])): ?>
              <div class="sub">DL z <?= fmt_date($p['datum_dodani']) ?></div>
            <?php endif; ?>
          </td>
          <td class="num"><?= fmt_ks($p['mnozstvi']) ?> <?= esc($p['jednotka'] ?? 'ks') ?></td>
          <td class="num"><?= fmt_kc($p['cena_bez_dph']) ?></td>
          <td class="num"><?= number_format($sazba, 0) ?>%</td>
          <td class="num"><strong><?= fmt_kc($bez + $dph) ?></strong></td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
  <div class="bottom">
    <div>
      <table class="dph-table">
        <thead><tr><th>Sazba</th><th class="num">Bez DPH</th><th class="num">DPH</th><th class="num">Celkem</th></tr></thead>
        <tbody>
          <?php foreach ($dph_rozpis as $sazba => $r): ?>
            <tr>
              <td><?= number_format($sazba, 0) ?>%</td>
              <td class="num"><?= fmt_kc($r['bez']) ?></td>
              <td class="num"><?= fmt_kc($r['dph']) ?></td>
              <td class="num"><strong><?= fmt_kc($r['bez'] + $r['dph']) ?></strong></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <div class="summary-final">
      <div class="lbl">K úhradě</div>
      <div class="val"><?= fmt_kc($f['castka_celkem']) ?></div>
      <?php
        // 💱 v3.0.283 — informativní přepočet na cílovou měnu (mena_config_json, dual_doklady)
        try {
            $mcRaw = nastaveni_get($pdo, 'mena_config_json', '');
            $mc = $mcRaw ? json_decode($mcRaw, true) : null;
            if (is_array($mc) && !empty($mc['dual_doklady']) && ($mc['kod'] ?? 'CZK') !== 'CZK' && (float) ($mc['kurz'] ?? 0) > 0) {
                $prep = round((float) $f['castka_celkem'] / (float) $mc['kurz'], 2);
                echo '<div style="font-size:10pt;color:#855;margin-top:3px">≈ ' . number_format($prep, 2, ',', ' ') . ' ' . htmlspecialchars($mc['kod'])
                   . ' <span style="font-size:8pt">(kurz ' . number_format((float) $mc['kurz'], 3, ',', ' ') . ' Kč/' . htmlspecialchars($mc['kod']) . ')</span></div>';
            }
        } catch (Throwable $e) { /* bez přepočtu */ }
        // 💸 v3.0.287 — QR Platba (SPD) — naskenuj v bankovní aplikaci, předvyplní platbu
        $spd = faktura_spd_string($f, $pdo);
        if ($spd !== ''):
      ?>
        <div class="qr-platba" style="margin-top:8px;display:flex;flex-direction:column;align-items:center">
          <div class="qr-img" data-spd="<?= htmlspecialchars($spd, ENT_QUOTES) ?>" style="width:108px;height:108px;background:#fff;padding:4px;border-radius:6px"></div>
          <div style="font-size:8pt;color:#855;margin-top:3px;font-weight:600">📱 QR Platba</div>
        </div>
      <?php endif; ?>
    </div>
  </div>
  <div class="foot">
    <?php
      $tel  = firma('telefon', '');
      $em   = firma('email', '');
      $web  = firma('web', '');
      $pati = firma('paticka_dokladu', '');
    ?>
    <?php if ($tel || $em || $web): ?>
      <div class="foot-row">
        <?php if ($tel): ?>
          <div class="foot-cell"><div class="lbl">Telefon</div><div class="val"><?= esc($tel) ?></div></div>
        <?php endif; ?>
        <?php if ($em): ?>
          <div class="foot-cell"><div class="lbl">E-mail</div><div class="val"><?= esc($em) ?></div></div>
        <?php endif; ?>
        <?php if ($web): ?>
          <div class="foot-cell"><div class="lbl">Web</div><div class="val"><?= esc($web) ?></div></div>
        <?php endif; ?>
      </div>
    <?php endif; ?>
    <?php if ($pati): ?>
      <div class="foot-custom"><?= esc($pati) ?></div>
    <?php endif; ?>
    <div class="foot-meta">Vystaveno <?= date('j. n. Y H:i') ?> · <?= esc(firma('nazev', 'APPEK B2B')) ?></div>
  </div>
</div>
<?php endforeach; ?>
<!-- 💸 v3.0.287 — QR Platba render (lokální lib, offline; žádný externí dotaz při tisku) -->
<script src="../admin/lib/qrcode.min.js"></script>
<script>
  (function () {
    function drawQr() {
      if (typeof QRCode === 'undefined') return;
      document.querySelectorAll('.qr-img[data-spd]').forEach(function (el) {
        if (el.dataset.done) return;
        el.dataset.done = '1';
        try { new QRCode(el, { text: el.getAttribute('data-spd'), width: 100, height: 100, correctLevel: QRCode.CorrectLevel.M }); }
        catch (e) { el.textContent = ''; }
      });
    }
    if (document.readyState !== 'loading') drawQr();
    else document.addEventListener('DOMContentLoaded', drawQr);
  })();
</script>
<?php if ($autoprint): ?>
<script>
  // Auto-trigger print dialog po načtení (QR už vykreslen na DOMContentLoaded)
  window.addEventListener('load', function() { setTimeout(function() { window.print(); }, 350); });
</script>
<?php endif; ?>
</body>
</html>
