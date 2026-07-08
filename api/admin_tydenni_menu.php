<?php
/**
 * 🗓️ TÝDENNÍ MENU — Restaurace balíček (v3.0.409).
 *
 * Menu se skládá Z VÝROBKŮ (cena vždy živě z výrobku), per den Po–Ne.
 * Šablony (pojmenované) + historie týdnů (duplikace). Rozesílka e-mailem
 * odběratelům (HTML), veřejná sdílecí stránka pro social networks
 * (api/menu_public.php?t=TOKEN s og: metadaty).
 *
 * GET  ?action=list                  → historie týdnů + šablony + katalog výrobků
 * POST ?action=save                  → {tyden_od, dny, poznamka} upsert dle týdne
 * POST ?action=delete                → {id}
 * POST ?action=duplicate             → {from_id, tyden_od} zkopíruj týden
 * POST ?action=save_sablona          → {nazev, dny}
 * POST ?action=delete_sablona        → {id}
 * POST ?action=rozeslat              → {id} e-mail všem aktivním odběratelům s e-mailem
 * POST ?action=share                 → {id} zajistí public_token → vrátí veřejnou URL
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/_admin_auth.php';
require_once __DIR__ . '/_packages_lib.php';

cors_headers();
require_admin();
header('Content-Type: application/json; charset=UTF-8');

if (!package_enabled('restaurace')) {
    http_response_code(402);
    json_response(['error' => 'Vyžaduje balíček 🍕 Restaurace']);
}

$pdo = db();

// ── Schéma (idempotentní) ────────────────────────────────────
$pdo->exec("
    CREATE TABLE IF NOT EXISTS tydenni_menu (
        id INT AUTO_INCREMENT PRIMARY KEY,
        tyden_od DATE NOT NULL UNIQUE,
        dny TEXT NOT NULL,
        poznamka VARCHAR(300) NULL,
        public_token VARCHAR(32) NULL,
        rozeslano_at DATETIME NULL,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
");
// 🆕 v3.0.412 — vzhledový styl + vlastní text (idempotentní migrace)
require_once __DIR__ . '/_menu_styly.php';
try {
    $tmCols = $pdo->query("SHOW COLUMNS FROM tydenni_menu")->fetchAll(PDO::FETCH_COLUMN);
    if (!in_array('styl', $tmCols, true))        $pdo->exec("ALTER TABLE tydenni_menu ADD COLUMN styl VARCHAR(20) NOT NULL DEFAULT 'restaurace'");
    if (!in_array('custom_text', $tmCols, true)) $pdo->exec("ALTER TABLE tydenni_menu ADD COLUMN custom_text VARCHAR(500) NULL");
    if (!in_array('layout', $tmCols, true))      $pdo->exec("ALTER TABLE tydenni_menu ADD COLUMN layout VARCHAR(20) NOT NULL DEFAULT 'karta'"); // 🆕 v3.0.413
} catch (Throwable $e) {}

const TM_DNY = ['po', 'ut', 'st', 'ct', 'pa', 'so', 'ne'];

/** Pondělí týdne pro dané datum. */
function tm_monday(string $date): string {
    $t = strtotime($date) ?: time();
    return date('Y-m-d', strtotime('monday this week', $t));
}

/** Sanitizace dnů: {po:[{vyrobek_id, pozn?}], …} */
function tm_clean_dny($dny): array {
    $out = [];
    foreach (TM_DNY as $d) {
        $out[$d] = [];
        foreach ((array) ($dny[$d] ?? []) as $r) {
            $vid = (int) ($r['vyrobek_id'] ?? 0);
            if ($vid <= 0) continue;
            $row = ['vyrobek_id' => $vid];
            $pozn = trim(mb_substr((string) ($r['pozn'] ?? ''), 0, 120));
            if ($pozn !== '') $row['pozn'] = $pozn;
            $out[$d][] = $row;
        }
    }
    return $out;
}

/** Resolved výrobky pro množinu id (cena s DPH živě z výrobku). */
function tm_vyrobky_map(PDO $pdo, array $ids): array {
    if (!$ids) return [];
    $ph = implode(',', array_fill(0, count($ids), '?'));
    $st = $pdo->prepare("
        SELECT v.id, v.nazev, ROUND(v.cena_bez_dph, 2) AS cena_bez_dph,
               ROUND(v.cena_bez_dph * (1 + COALESCE(sd.sazba, 12) / 100), 2) AS cena_s_dph,
               COALESCE(v.alergeny, '') AS alergeny
        FROM vyrobky v LEFT JOIN sazby_dph sd ON sd.id = v.sazba_dph_id
        WHERE v.id IN ($ph)
    ");
    $st->execute(array_values($ids));
    $m = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) $m[(int) $r['id']] = $r;
    return $m;
}

/** Týden + resolved položky. */
function tm_resolve(PDO $pdo, array $row): array {
    $dny = json_decode($row['dny'] ?? '[]', true) ?: [];
    $ids = [];
    foreach (TM_DNY as $d) foreach ((array) ($dny[$d] ?? []) as $r) $ids[] = (int) ($r['vyrobek_id'] ?? 0);
    $map = tm_vyrobky_map($pdo, array_values(array_unique(array_filter($ids))));
    $out = [];
    foreach (TM_DNY as $d) {
        $out[$d] = [];
        foreach ((array) ($dny[$d] ?? []) as $r) {
            $v = $map[(int) $r['vyrobek_id']] ?? null;
            $out[$d][] = [
                'vyrobek_id' => (int) $r['vyrobek_id'],
                'nazev'      => $v['nazev'] ?? ('#' . $r['vyrobek_id'] . ' (smazaný výrobek)'),
                'cena_s_dph' => $v ? (float) $v['cena_s_dph'] : null,
                'alergeny'   => $v['alergeny'] ?? '',
                'pozn'       => $r['pozn'] ?? '',
                'existuje'   => (bool) $v,
            ];
        }
    }
    return [
        'id' => (int) $row['id'], 'tyden_od' => $row['tyden_od'],
        'tyden_do' => date('Y-m-d', strtotime($row['tyden_od'] . ' +6 days')),
        'poznamka' => $row['poznamka'], 'dny' => $out,
        'public_token' => $row['public_token'], 'rozeslano_at' => $row['rozeslano_at'],
        'styl' => $row['styl'] ?? 'restaurace', 'custom_text' => $row['custom_text'] ?? null,
        'layout' => $row['layout'] ?? 'karta',
    ];
}

function tm_sablony_load(PDO $pdo): array {
    try {
        $raw = $pdo->query("SELECT hodnota FROM nastaveni WHERE klic = 'tydenni_menu_sablony'")->fetchColumn();
        $j = $raw ? json_decode($raw, true) : [];
        return is_array($j) ? $j : [];
    } catch (Throwable $e) { return []; }
}
function tm_sablony_save(PDO $pdo, array $s): void {
    $v = json_encode(array_values($s), JSON_UNESCAPED_UNICODE);
    $pdo->prepare("INSERT INTO nastaveni (klic, hodnota) VALUES ('tydenni_menu_sablony', :v)
                   ON DUPLICATE KEY UPDATE hodnota = :v2")->execute(['v' => $v, 'v2' => $v]);
}

$action = $_GET['action'] ?? 'list';
$method = $_SERVER['REQUEST_METHOD'];

if ($action === 'list' && $method === 'GET') {
    $weeks = $pdo->query("SELECT * FROM tydenni_menu ORDER BY tyden_od DESC LIMIT 26")->fetchAll(PDO::FETCH_ASSOC);
    $vyrobky = $pdo->query("
        SELECT v.id, v.nazev, ROUND(v.cena_bez_dph * (1 + COALESCE(sd.sazba, 12) / 100), 2) AS cena_s_dph,
               COALESCE(k.nazev, '') AS kategorie
        FROM vyrobky v
        LEFT JOIN sazby_dph sd ON sd.id = v.sazba_dph_id
        LEFT JOIN kategorie_vyrobku k ON k.id = v.kategorie_id
        WHERE v.aktivni = 1 ORDER BY k.nazev, v.nazev LIMIT 500
    ")->fetchAll(PDO::FETCH_ASSOC);
    // 🆕 v3.0.412 — dostupné vzhledové styly pro picker
    $styly = [];
    foreach (menu_styly_defs() as $k => $s) $styly[] = ['key' => $k, 'label' => $s['label'], 'popis' => $s['popis'], 'grad_a' => $s['grad_a'], 'grad_b' => $s['grad_b']];
    // 🆕 v3.0.413 — dostupná rozložení
    $layouty = [];
    foreach (menu_layouty_defs() as $k => $s) $layouty[] = ['key' => $k, 'label' => $s['label'], 'popis' => $s['popis']];
    json_response([
        'weeks'   => array_map(fn($w) => tm_resolve($pdo, $w), $weeks),
        'sablony' => tm_sablony_load($pdo),
        'vyrobky' => $vyrobky,
        'styly'   => $styly,
        'layouty' => $layouty,
        'tento_tyden' => tm_monday(date('Y-m-d')),
    ]);
}

if ($action === 'save' && $method === 'POST') {
    $d = json_input();
    $od = tm_monday((string) ($d['tyden_od'] ?? date('Y-m-d')));
    $dny = tm_clean_dny((array) ($d['dny'] ?? []));
    $pozn = trim(mb_substr((string) ($d['poznamka'] ?? ''), 0, 300));
    $styl = menu_styl_key($d['styl'] ?? 'restaurace');                     // 🆕 v3.0.412
    $ctext = trim(mb_substr((string) ($d['custom_text'] ?? ''), 0, 500));  // 🆕 v3.0.412
    $layout = menu_layout_key($d['layout'] ?? 'karta');                    // 🆕 v3.0.413
    $j = json_encode($dny, JSON_UNESCAPED_UNICODE);
    $pdo->prepare("INSERT INTO tydenni_menu (tyden_od, dny, poznamka, styl, custom_text, layout) VALUES (:o, :d, :p, :s, :ct, :ly)
                   ON DUPLICATE KEY UPDATE dny = :d2, poznamka = :p2, styl = :s2, custom_text = :ct2, layout = :ly2")
        ->execute(['o' => $od, 'd' => $j, 'p' => $pozn, 's' => $styl, 'ct' => $ctext ?: null, 'ly' => $layout,
                   'd2' => $j, 'p2' => $pozn, 's2' => $styl, 'ct2' => $ctext ?: null, 'ly2' => $layout]);
    $row = $pdo->prepare("SELECT * FROM tydenni_menu WHERE tyden_od = :o");
    $row->execute(['o' => $od]);
    json_response(['ok' => true, 'week' => tm_resolve($pdo, $row->fetch(PDO::FETCH_ASSOC))]);
}

if ($action === 'delete' && $method === 'POST') {
    $id = (int) (json_input()['id'] ?? 0);
    $pdo->prepare("DELETE FROM tydenni_menu WHERE id = :id")->execute(['id' => $id]);
    json_response(['ok' => true]);
}

if ($action === 'duplicate' && $method === 'POST') {
    $d = json_input();
    $from = (int) ($d['from_id'] ?? 0);
    $od = tm_monday((string) ($d['tyden_od'] ?? date('Y-m-d')));
    $src = $pdo->prepare("SELECT dny, poznamka FROM tydenni_menu WHERE id = :id");
    $src->execute(['id' => $from]);
    $s = $src->fetch(PDO::FETCH_ASSOC);
    if (!$s) json_error('Zdrojový týden nenalezen', 404);
    $pdo->prepare("INSERT INTO tydenni_menu (tyden_od, dny, poznamka) VALUES (:o, :d, :p)
                   ON DUPLICATE KEY UPDATE dny = :d2, poznamka = :p2")
        ->execute(['o' => $od, 'd' => $s['dny'], 'p' => $s['poznamka'], 'd2' => $s['dny'], 'p2' => $s['poznamka']]);
    json_response(['ok' => true, 'tyden_od' => $od]);
}

if ($action === 'save_sablona' && $method === 'POST') {
    $d = json_input();
    $nazev = trim(mb_substr((string) ($d['nazev'] ?? ''), 0, 80));
    $dny = tm_clean_dny((array) ($d['dny'] ?? []));
    $poloz = array_sum(array_map('count', $dny));
    if ($nazev === '' || $poloz === 0) json_error('Vyplň název a alespoň jednu položku', 400);
    $sab = tm_sablony_load($pdo);
    $maxId = 0; foreach ($sab as $s) $maxId = max($maxId, (int) ($s['id'] ?? 0));
    $found = false;
    foreach ($sab as &$s) {
        if (mb_strtolower($s['nazev'] ?? '') === mb_strtolower($nazev)) { $s['dny'] = $dny; $found = true; break; }
    }
    unset($s);
    if (!$found) $sab[] = ['id' => $maxId + 1, 'nazev' => $nazev, 'dny' => $dny];
    tm_sablony_save($pdo, $sab);
    json_response(['ok' => true, 'prepsano' => $found, 'sablony' => $sab]);
}

if ($action === 'delete_sablona' && $method === 'POST') {
    $id = (int) (json_input()['id'] ?? 0);
    $sab = array_values(array_filter(tm_sablony_load($pdo), fn($s) => (int) ($s['id'] ?? 0) !== $id));
    tm_sablony_save($pdo, $sab);
    json_response(['ok' => true, 'sablony' => $sab]);
}

if ($action === 'share' && $method === 'POST') {
    $id = (int) (json_input()['id'] ?? 0);
    $row = $pdo->prepare("SELECT * FROM tydenni_menu WHERE id = :id");
    $row->execute(['id' => $id]);
    $w = $row->fetch(PDO::FETCH_ASSOC);
    if (!$w) json_error('Týden nenalezen', 404);
    if (empty($w['public_token'])) {
        $tok = bin2hex(random_bytes(16));
        $pdo->prepare("UPDATE tydenni_menu SET public_token = :t WHERE id = :id")->execute(['t' => $tok, 'id' => $id]);
        $w['public_token'] = $tok;
    }
    $base = defined('APP_URL') && APP_URL ? rtrim(APP_URL, '/') : ((($_SERVER['HTTPS'] ?? '') === 'on' ? 'https' : 'http') . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost'));
    json_response(['ok' => true, 'url' => $base . '/api/menu_public.php?t=' . $w['public_token']]);
}

if ($action === 'rozeslat' && $method === 'POST') {
    require_once __DIR__ . '/_smtp_lib.php';
    $id = (int) (json_input()['id'] ?? 0);
    $row = $pdo->prepare("SELECT * FROM tydenni_menu WHERE id = :id");
    $row->execute(['id' => $id]);
    $w = $row->fetch(PDO::FETCH_ASSOC);
    if (!$w) json_error('Týden nenalezen', 404);
    $res = tm_resolve($pdo, $w);

    $firma = [];
    foreach ($pdo->query("SELECT klic, hodnota FROM nastaveni WHERE klic LIKE 'firma_%'") as $r) $firma[$r['klic']] = $r['hodnota'];
    $fN = $firma['firma_nazev'] ?? 'APPEK B2B';

    $labels = ['po' => 'Pondělí', 'ut' => 'Úterý', 'st' => 'Středa', 'ct' => 'Čtvrtek', 'pa' => 'Pátek', 'so' => 'Sobota', 'ne' => 'Neděle'];
    $odS = date('j. n.', strtotime($res['tyden_od']));
    $doS = date('j. n. Y', strtotime($res['tyden_do']));
    $st = menu_styl($res['styl']); // 🆕 v3.0.412 — paleta dle stylu
    $html = '<div style="font-family:' . $st['font'] . ';max-width:640px;margin:0 auto;color:' . $st['text'] . '">'
          . '<h1 style="font-size:20px;border-bottom:3px solid ' . $st['grad_a'] . ';padding-bottom:8px">' . $st['nadpis'] . ' Týdenní menu ' . $odS . ' – ' . $doS . '</h1>'
          . ($res['custom_text'] ? '<p style="color:' . $st['accent'] . ';font-size:14px;font-weight:600">' . nl2br(htmlspecialchars($res['custom_text'])) . '</p>' : '')
          . '<p style="color:' . $st['muted'] . ';font-size:13px">' . htmlspecialchars($fN) . ($res['poznamka'] ? ' · ' . htmlspecialchars($res['poznamka']) : '') . '</p>';
    foreach (TM_DNY as $d) {
        $items = $res['dny'][$d] ?? [];
        if (!$items) continue;
        $den = date('j. n.', strtotime($res['tyden_od'] . ' +' . array_search($d, TM_DNY) . ' days'));
        $html .= '<h3 style="font-size:14px;color:' . $st['accent'] . ';margin:16px 0 4px">' . $labels[$d] . ' ' . $den . '</h3><table style="width:100%;border-collapse:collapse;font-size:13px">';
        foreach ($items as $it) {
            $html .= '<tr><td style="padding:4px 0;border-bottom:1px solid #eee">' . htmlspecialchars($it['nazev'])
                   . ($it['pozn'] ? ' <span style="color:#888;font-size:11px">(' . htmlspecialchars($it['pozn']) . ')</span>' : '')
                   . ($it['alergeny'] ? ' <span style="color:#aaa;font-size:10px">al. ' . htmlspecialchars($it['alergeny']) . '</span>' : '')
                   . '</td><td style="padding:4px 0;border-bottom:1px solid #eee;text-align:right;white-space:nowrap;font-weight:600">'
                   . ($it['cena_s_dph'] !== null ? number_format($it['cena_s_dph'], 0, ',', ' ') . ' Kč' : '—') . '</td></tr>';
        }
        $html .= '</table>';
    }
    $html .= '<p style="margin-top:20px;font-size:11px;color:#999">' . htmlspecialchars($fN)
           . (($firma['firma_telefon'] ?? '') !== '' ? ' · 📞 ' . htmlspecialchars($firma['firma_telefon']) : '')
           . (($firma['firma_email'] ?? '') !== '' ? ' · ✉️ ' . htmlspecialchars($firma['firma_email']) : '') . '</p></div>';

    $prijemci = $pdo->query("SELECT DISTINCT email FROM odberatele WHERE COALESCE(blokovan, 0) = 0 AND email IS NOT NULL AND email != '' AND email LIKE '%@%'")->fetchAll(PDO::FETCH_COLUMN);
    if (!$prijemci) json_error('Žádný odběratel nemá vyplněný e-mail', 400);

    $predmet = '🗓️ Týdenní menu ' . $odS . ' – ' . $doS . ' · ' . $fN;
    $predmetEnc = '=?UTF-8?B?' . base64_encode($predmet) . '?=';
    $from = ($firma['firma_email'] ?? '') !== '' ? $firma['firma_email'] : 'noreply@' . ($_SERVER['HTTP_HOST'] ?? 'localhost');
    $headers = implode("\r\n", [
        'MIME-Version: 1.0',
        'Content-Type: text/html; charset=UTF-8',
        'Content-Transfer-Encoding: 8bit',
        'From: =?UTF-8?B?' . base64_encode($fN) . '?= <' . $from . '>',
    ]);
    $ok = 0; $fail = 0;
    foreach ($prijemci as $to) {
        if (appek_mail_raw($to, $predmetEnc, $html, $headers)) $ok++; else $fail++;
    }
    $pdo->prepare("UPDATE tydenni_menu SET rozeslano_at = NOW() WHERE id = :id")->execute(['id' => $id]);
    json_response(['ok' => true, 'odeslano' => $ok, 'selhalo' => $fail, 'celkem' => count($prijemci)]);
}

// 🆕 v3.0.410 — 🖨️ TISK týdenního menu (A4 k vyvěšení / uložení do PDF)
if ($action === 'tisk' && $method === 'GET') {
    $id = (int) ($_GET['id'] ?? 0);
    $row = $pdo->prepare("SELECT * FROM tydenni_menu WHERE id = :id");
    $row->execute(['id' => $id]);
    $w = $row->fetch(PDO::FETCH_ASSOC);
    if (!$w) json_error('Týden nenalezen', 404);
    $res = tm_resolve($pdo, $w);

    $firma = [];
    try { foreach ($pdo->query("SELECT klic, hodnota FROM nastaveni WHERE klic LIKE 'firma_%'") as $r) $firma[$r['klic']] = $r['hodnota']; } catch (Throwable $e) {}
    $fN = htmlspecialchars($firma['firma_nazev'] ?? 'Restaurace');
    $odS = date('j. n.', strtotime($res['tyden_od']));
    $doS = date('j. n. Y', strtotime($res['tyden_do']));

    // 🆕 v3.0.413 — sdílený renderer (5 rozložení × palety), tisk = přidá @page + auto-print
    require_once __DIR__ . '/_menu_render.php';
    $r = menu_render($res, $firma, 'print');
    header('Content-Type: text/html; charset=UTF-8');
    // 🆕 v3.0.422 — @page{margin:0} skryje hlavičku/patičku prohlížeče (název/URL/„1 z 1"/datum);
    //   odsazení stránky přesunuto do padding body, aby obsah přesto měl okraje.
    echo "<!DOCTYPE html><html lang=\"cs\"><head><meta charset=\"UTF-8\"><title>Týdenní menu {$odS} – {$doS} · {$fN}</title><style>@page{size:A4;margin:0;}@media print{body{padding:14mm!important;}}"
       . $r['css'] . "</style></head><body>" . $r['body'] . "<script>window.print();</script></body></html>";
    exit;
}

json_error('Neznámá akce', 404);
