<?php
require_once __DIR__ . '/config.php';
cors_headers();

$kategorie_id = isset($_GET['kategorie']) ? (int) $_GET['kategorie'] : null;
$hledat       = isset($_GET['q']) ? trim($_GET['q']) : '';

$pdo = db();

// 🍰 v3.0.331 — aktivní sezóny dnes (vlastní i výchozí, vč. přechodu přes Nový rok).
//   Výrobek se sezónou se zobrazí jen v aktivním okně; sezónní cenu řeší cenik/PHP níže.
require_once __DIR__ . '/_seasonal_lib.php';
$seasonActiveSet = array_flip(seasonal_active_keys($pdo));

// Pokud je přihlášený odběratel, použij ceník s aplikovanými slevami
session_secure_start();
$odb_id = $_SESSION['odberatel_id'] ?? null;

if ($odb_id) {
    // Plný ceník se slevami z cenové skupiny
    $vyrobky_full = cenik_pro_odberatele($pdo, (int) $odb_id);

    // Filtrování podle kategorie / hledání / aktivní sezóny
    $vyrobky = array_values(array_filter($vyrobky_full, function ($v) use ($kategorie_id, $hledat, $seasonActiveSet) {
        if ($kategorie_id && (int) $v['kategorie_id'] !== $kategorie_id) return false;
        if ($hledat !== '' && stripos($v['nazev'], $hledat) === false) return false;
        $sez = $v['sezona'] ?? '';
        if ($sez !== '' && $sez !== null && !isset($seasonActiveSet[$sez])) return false; // sezónní výrobek mimo okno
        return true;
    }));

    // Doplň pole, která dosud používal frontend (popis, obrazek_url, oblibeny, ...)
    $ids = array_column($vyrobky, 'id');
    if ($ids) {
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        // Načti i statusové štítky — defenzivně přes COALESCE, aby SELECT
        // neuselhal na instalacích bez auto-migrace (sloupce mohou chybět).
        try {
            $colsExist = $pdo->query("
                SELECT COLUMN_NAME FROM information_schema.COLUMNS
                WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'vyrobky'
            ")->fetchAll(PDO::FETCH_COLUMN);
        } catch (Throwable $e) { $colsExist = []; }

        $jeAk = in_array('je_akce', $colsExist, true) ? 'v.je_akce' : '0 AS je_akce';
        $jeNo = in_array('je_novinka', $colsExist, true) ? 'v.je_novinka' : '0 AS je_novinka';
        $jeDo = in_array('je_doprodej', $colsExist, true) ? 'v.je_doprodej' : '0 AS je_doprodej';
        $jeVy = in_array('je_vyprodano', $colsExist, true) ? 'v.je_vyprodano' : '0 AS je_vyprodano';

        $stmt = $pdo->prepare("
            SELECT v.id, v.popis, v.obrazek_url, v.objednat_do_hod, v.alergeny, v.oblibeny,
                   $jeAk, $jeNo, $jeDo, $jeVy
            FROM vyrobky v WHERE v.id IN ($placeholders)
        ");
        $stmt->execute($ids);
        $extras = [];
        while ($r = $stmt->fetch()) {
            $extras[(int) $r['id']] = $r;
        }
        foreach ($vyrobky as &$v) {
            $vid = (int) $v['id'];
            if (isset($extras[$vid])) {
                $v['popis']           = $extras[$vid]['popis'];
                $v['obrazek_url']     = $extras[$vid]['obrazek_url'];
                $v['objednat_do_hod'] = $extras[$vid]['objednat_do_hod'];
                $v['alergeny']        = $extras[$vid]['alergeny'];
                $v['oblibeny']        = $extras[$vid]['oblibeny'];
                $v['je_akce']         = (int) $extras[$vid]['je_akce'];
                $v['je_novinka']      = (int) $extras[$vid]['je_novinka'];
                $v['je_doprodej']     = (int) $extras[$vid]['je_doprodej'];
                $v['je_vyprodano']    = (int) $extras[$vid]['je_vyprodano'];
            }
        }
    }
} else {
    // Anonymní/admin pohled - bez slev
    // Defenzivní detekce status sloupců (mohou chybět na starších instalacích)
    try {
        $colsExist = $pdo->query("
            SELECT COLUMN_NAME FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'vyrobky'
        ")->fetchAll(PDO::FETCH_COLUMN);
    } catch (Throwable $e) { $colsExist = []; }
    $jeAk = in_array('je_akce', $colsExist, true) ? 'v.je_akce' : '0 AS je_akce';
    $jeNo = in_array('je_novinka', $colsExist, true) ? 'v.je_novinka' : '0 AS je_novinka';
    $jeDo = in_array('je_doprodej', $colsExist, true) ? 'v.je_doprodej' : '0 AS je_doprodej';
    $jeVy = in_array('je_vyprodano', $colsExist, true) ? 'v.je_vyprodano' : '0 AS je_vyprodano';

    $sql = "
        SELECT v.id, v.cislo, v.nazev, v.popis,
               v.cena_bez_dph AS cena_zakladni,
               v.cena_bez_dph,
               v.hmotnost_g,
               v.obrazek_url, v.objednat_do_hod, v.alergeny, v.oblibeny,
               $jeAk, $jeNo, $jeDo, $jeVy,
               v.min_objednavka, v.sezona,
               k.nazev AS kategorie, k.id AS kategorie_id, k.ikona AS kategorie_ikona,
               j.kod AS jednotka,
               s.sazba AS dph,
               NULL AS sleva_pct,
               NULL AS pevna_cena
        FROM vyrobky v
        LEFT JOIN kategorie_vyrobku k ON v.kategorie_id = k.id
        LEFT JOIN jednotky j ON v.jednotka_id = j.id
        LEFT JOIN sazby_dph s ON v.sazba_dph_id = s.id
        WHERE v.aktivni = 1
    ";
    // 🍰 v3.0.331 — Seasonal filter dynamicky (vlastní i výchozí sezóny, wrap přes Nový rok).
    //   Aktivní klíče spočítané v PHP ($seasonActiveSet) — viditelnost i pro custom sezóny.
    $params = [];
    $activeKeys = array_keys($seasonActiveSet);
    if ($activeKeys) {
        $ph = [];
        foreach ($activeKeys as $i => $k) { $ph[] = ":sez$i"; $params["sez$i"] = $k; }
        $sql .= " AND (v.sezona IS NULL OR v.sezona = '' OR v.sezona IN (" . implode(',', $ph) . "))";
    } else {
        $sql .= " AND (v.sezona IS NULL OR v.sezona = '')";
    }
    if ($kategorie_id) {
        $sql .= " AND v.kategorie_id = :kat";
        $params['kat'] = $kategorie_id;
    }
    if ($hledat !== '') {
        $hl = str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $hledat);
        $sql .= " AND v.nazev LIKE :hl";
        $params['hl'] = '%' . $hl . '%';
    }
    $sql .= " ORDER BY k.poradi, v.poradi, v.nazev";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $vyrobky = $stmt->fetchAll();

    // 🍰 v3.0.331 — sezónní cena: cena_bez_dph dle aktivní sezóny, cena_zakladni zůstává původní (web ukáže „bylo → teď").
    foreach ($vyrobky as &$v) {
        $adj = seasonal_adjust_price($pdo, (float) $v['cena_bez_dph'], $v['sezona'] ?? null);
        $v['cena_bez_dph']     = $adj['cena'];
        $v['sezona_sleva_pct'] = $adj['pct'];
    }
    unset($v);
}

$kategorie = $pdo->query("
    SELECT id, nazev, ikona, obrazek_url, poradi, parent_id FROM kategorie_vyrobku
    WHERE aktivni = 1 ORDER BY poradi
")->fetchAll();

json_response(['vyrobky' => $vyrobky, 'kategorie' => $kategorie]);
