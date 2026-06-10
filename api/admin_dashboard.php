<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/_admin_auth.php';
cors_headers();
require_admin();

$pdo = db();

// 🐛 v3.0.235 — jednorázový backfill puvod + datum_objednani (b2b objednávky měly 0000-00-00
// → mizely z přehledu). Guarded flagem, takže po 1. běhu jen levný nastaveni_get.
if (function_exists('kanaly_backfill_once')) kanaly_backfill_once($pdo);

// =============================================================
// Vstup: obdobi (dnes|tyden|mesic|rok|vlastni) + datum_od / datum_do
// =============================================================
$obdobi = $_GET['obdobi'] ?? 'mesic';
$datum_od = $_GET['datum_od'] ?? null;
$datum_do = $_GET['datum_do'] ?? null;

// Resolve datum range
[$od, $do] = (function () use ($obdobi, $datum_od, $datum_do) {
    $today = date('Y-m-d');
    if ($obdobi === 'dnes') {
        return [$today, $today];
    }
    if ($obdobi === 'tyden') {
        $monday = date('Y-m-d', strtotime('monday this week'));
        $sunday = date('Y-m-d', strtotime('sunday this week'));
        return [$monday, $sunday];
    }
    if ($obdobi === 'mesic') {
        return [date('Y-m-01'), date('Y-m-t')];
    }
    if ($obdobi === 'rok') {
        return [date('Y-01-01'), date('Y-12-31')];
    }
    if ($obdobi === 'vlastni' && $datum_od && $datum_do) {
        return [$datum_od, $datum_do];
    }
    return [date('Y-m-01'), date('Y-m-t')];
})();

// Hlavní statistika za zvolené období
// 🐛 v3.0.230 — tržby NEPOČÍTAJÍ zrušené objednávky (dřív SUM přes všechny stavy
// → dashboard "milion" vs POS kasa 0; zrušené nafukovaly tržby, graf i top odběratele).
// Počty objednávek zrušené dál zahrnují — breakdown je ukazuje explicitně (zrusenych).
$stmt = $pdo->prepare("
    SELECT COUNT(*) AS objednavek,
           COALESCE(SUM(CASE WHEN stav <> 'zrusena' THEN castka_celkem ELSE 0 END), 0) AS trzby,
           SUM(CASE WHEN stav = 'nova'      THEN 1 ELSE 0 END) AS novych,
           SUM(CASE WHEN stav = 'dorucena'  THEN 1 ELSE 0 END) AS dorucenych,
           SUM(CASE WHEN stav = 'zrusena'   THEN 1 ELSE 0 END) AS zrusenych,
           SUM(CASE WHEN stav IN ('potvrzena','ve_vyrobe','pripravena','expedovana') THEN 1 ELSE 0 END) AS rozpracovanych
    FROM objednavky
    WHERE DATE(datum_objednani) BETWEEN :od AND :do
");
$stmt->execute(['od' => $od, 'do' => $do]);
$obdobi_stats = $stmt->fetch();

$dny_v_obdobi = max(1, (strtotime($do) - strtotime($od)) / 86400 + 1);
$obdobi_stats['prumerne_denne'] = round($obdobi_stats['trzby'] / $dny_v_obdobi, 2);

// 🆕 v3.0.230 — tržby podle kanálu (puvod) za období: dashboard ukáže, Z ČEHO se
// celková tržba skládá (POS pokladna vs B2B vs interní…) — provázané s kanálovým
// registrem (kanal_meta). Odpovídá na "proč dashboard X a POS kasa Y".
$stmt = $pdo->prepare("
    SELECT COALESCE(puvod,'interni') AS puvod,
           COUNT(*) AS objednavek,
           COALESCE(SUM(CASE WHEN stav <> 'zrusena' THEN castka_celkem ELSE 0 END), 0) AS trzby
    FROM objednavky
    WHERE DATE(datum_objednani) BETWEEN :od AND :do
    GROUP BY COALESCE(puvod,'interni')
    ORDER BY trzby DESC
");
$stmt->execute(['od' => $od, 'do' => $do]);
$trzby_kanaly = [];
foreach ($stmt->fetchAll() as $rk) {
    $meta = function_exists('kanal_meta') ? kanal_meta($pdo, $rk['puvod']) : null;
    $trzby_kanaly[] = [
        'puvod'      => $rk['puvod'],
        'label'      => $meta['label'] ?? $rk['puvod'],
        'ikona'      => $meta['ikona'] ?? '•',
        'barva'      => $meta['barva'] ?? '#6B7280',
        'pokladni'   => (int) ($meta['pokladni'] ?? 0),
        'objednavek' => (int) $rk['objednavek'],
        'trzby'      => (float) $rk['trzby'],
    ];
}

// Vždy: dnes + po splatnosti
$dnes = $pdo->query("
    SELECT COUNT(*) AS objednavek,
           COALESCE(SUM(CASE WHEN stav <> 'zrusena' THEN castka_celkem ELSE 0 END), 0) AS trzby
    FROM objednavky
    WHERE DATE(datum_objednani) = CURDATE()
")->fetch();

$po_splatnosti = $pdo->query("
    SELECT COUNT(*) AS pocet, COALESCE(SUM(castka_celkem - castka_uhrazeno), 0) AS castka
    FROM faktury
    WHERE castka_uhrazeno < castka_celkem AND datum_splatnosti < CURDATE()
")->fetch();

// Časový graf
$stmt = $pdo->prepare("
    SELECT DATE(datum_objednani) AS den,
           COUNT(*) AS objednavek,
           COALESCE(SUM(CASE WHEN stav <> 'zrusena' THEN castka_celkem ELSE 0 END), 0) AS trzby
    FROM objednavky
    WHERE DATE(datum_objednani) BETWEEN :od AND :do
    GROUP BY DATE(datum_objednani)
    ORDER BY den
");
$stmt->execute(['od' => $od, 'do' => $do]);
$casovy_graf = $stmt->fetchAll();

// Top odběratelé
$stmt = $pdo->prepare("
    SELECT od.id, od.nazev,
           COUNT(*) AS objednavek,
           COALESCE(SUM(o.castka_celkem), 0) AS trzba
    FROM objednavky o
    JOIN odberatele od ON od.id = o.odberatel_id
    WHERE DATE(o.datum_objednani) BETWEEN :od AND :do
      AND o.stav <> 'zrusena'
    GROUP BY od.id, od.nazev
    ORDER BY trzba DESC
    LIMIT 5
");
$stmt->execute(['od' => $od, 'do' => $do]);
$top_odberatele = $stmt->fetchAll();

// Top výrobky
$stmt = $pdo->prepare("
    SELECT v.id, v.nazev, v.obrazek_url,
           SUM(p.mnozstvi) AS mnozstvi,
           SUM(p.mnozstvi * p.cena_bez_dph) AS trzba
    FROM objednavky_polozky p
    JOIN objednavky o ON o.id = p.objednavka_id
    JOIN vyrobky v ON v.id = p.vyrobek_id
    WHERE DATE(o.datum_objednani) BETWEEN :od AND :do
      AND o.stav <> 'zrusena'
    GROUP BY v.id, v.nazev, v.obrazek_url
    ORDER BY mnozstvi DESC
    LIMIT 5
");
$stmt->execute(['od' => $od, 'do' => $do]);
$top_vyrobky = $stmt->fetchAll();

// Výroba na zítra
$vyroba_zitra = $pdo->query("
    SELECT v.id, v.nazev, v.obrazek_url, SUM(p.mnozstvi) AS celkem
    FROM objednavky_polozky p
    JOIN objednavky o ON o.id = p.objednavka_id
    JOIN vyrobky v ON v.id = p.vyrobek_id
    WHERE o.datum_dodani = CURDATE() + INTERVAL 1 DAY
      AND o.stav NOT IN ('zrusena','dorucena')
    GROUP BY v.id, v.nazev, v.obrazek_url
    ORDER BY celkem DESC
")->fetchAll();

// Nedávné objednávky (s info o DL/FA + místě dodání)
$nedavne = $pdo->query("
    SELECT o.id, o.cislo, o.stav, o.datum_objednani, o.datum_dodani, o.castka_celkem,
           od.nazev AS odberatel,
           md.nazev AS misto_nazev,
           md.ulice AS misto_ulice,
           md.mesto AS misto_mesto,
           (SELECT COUNT(*) FROM dodaci_listy dl WHERE dl.objednavka_id = o.id) AS pocet_dl,
           (SELECT COUNT(DISTINCT fdl.faktura_id)
              FROM faktury_dodaci_listy fdl
              JOIN dodaci_listy dl2 ON dl2.id = fdl.dodaci_list_id
             WHERE dl2.objednavka_id = o.id) AS pocet_faktur,
           (SELECT MIN(fdl.faktura_id)
              FROM faktury_dodaci_listy fdl
              JOIN dodaci_listy dl3 ON dl3.id = fdl.dodaci_list_id
             WHERE dl3.objednavka_id = o.id) AS prvni_faktura_id
    FROM objednavky o
    JOIN odberatele od ON od.id = o.odberatel_id
    LEFT JOIN mista_dodani md ON md.id = o.misto_dodani_id
    ORDER BY o.datum_objednani DESC
    LIMIT 5
")->fetchAll();

// 🆕 v3.0.131 — Položky pro nedávné objednávky (první 3 zobrazené + total count)
//   User: "pod adresou položky z obj — poslední 3 vypsaný a pak +N podle objednávky".
//   Jeden batch dotaz pro všech 5 objednávek (IN clause), pak groupnuto v PHP.
$nedavneIds = array_column($nedavne, 'id');
if ($nedavneIds) {
    $inObj = implode(',', array_map('intval', $nedavneIds));
    $polozkyRows = $pdo->query("
        SELECT op.objednavka_id,
               op.mnozstvi,
               COALESCE(NULLIF(op.vyrobek_nazev, ''), v.nazev) AS nazev
        FROM objednavky_polozky op
        LEFT JOIN vyrobky v ON v.id = op.vyrobek_id
        WHERE op.objednavka_id IN ($inObj)
        ORDER BY op.objednavka_id, op.id
    ")->fetchAll();
    $polozkyByObj = [];
    foreach ($polozkyRows as $pr) {
        $polozkyByObj[$pr['objednavka_id']][] = [
            'nazev'    => $pr['nazev'],
            'mnozstvi' => $pr['mnozstvi'],
        ];
    }
    foreach ($nedavne as &$o) {
        $items = $polozkyByObj[$o['id']] ?? [];
        $o['pocet_polozek'] = count($items);
        $o['polozky']       = array_slice($items, 0, 3);  // jen první 3 do payloadu
    }
    unset($o);
}

// Nedávné dodací listy (s místem dodání + prvni_faktura_id pro FA badge)
$nedavne_dl = $pdo->query("
    SELECT dl.id, dl.cislo, dl.objednavka_id, dl.datum_vystaveni, dl.datum_dodani,
           dl.castka_celkem, dl.fakturovano, dl.rucni,
           od.nazev AS odberatel,
           md.nazev AS misto_nazev,
           md.ulice AS misto_ulice,
           md.mesto AS misto_mesto,
           o.cislo AS objednavka_cislo,
           (SELECT MIN(fdl.faktura_id) FROM faktury_dodaci_listy fdl WHERE fdl.dodaci_list_id = dl.id) AS prvni_faktura_id
    FROM dodaci_listy dl
    JOIN odberatele od ON od.id = dl.odberatel_id
    LEFT JOIN mista_dodani md ON md.id = dl.misto_dodani_id
    LEFT JOIN objednavky o ON o.id = dl.objednavka_id
    ORDER BY dl.datum_vystaveni DESC, dl.id DESC
    LIMIT 5
")->fetchAll();

// 🆕 v3.0.132 — Položky pro nedávné DL (stejně jako objednávky: první 3 + total)
$nedavneDlIds = array_column($nedavne_dl, 'id');
if ($nedavneDlIds) {
    $inDl = implode(',', array_map('intval', $nedavneDlIds));
    $dlPolRows = $pdo->query("
        SELECT dlp.dodaci_list_id AS pid,
               dlp.mnozstvi,
               COALESCE(NULLIF(dlp.vyrobek_nazev, ''), v.nazev) AS nazev
        FROM dodaci_list_polozky dlp
        LEFT JOIN vyrobky v ON v.id = dlp.vyrobek_id
        WHERE dlp.dodaci_list_id IN ($inDl)
        ORDER BY dlp.dodaci_list_id, dlp.id
    ")->fetchAll();
    $dlPolBy = [];
    foreach ($dlPolRows as $pr) {
        $dlPolBy[$pr['pid']][] = ['nazev' => $pr['nazev'], 'mnozstvi' => $pr['mnozstvi']];
    }
    foreach ($nedavne_dl as &$dl) {
        $items = $dlPolBy[$dl['id']] ?? [];
        $dl['pocet_polozek'] = count($items);
        $dl['polozky']       = array_slice($items, 0, 3);
    }
    unset($dl);
}

// Nedávné faktury (místo dodání odvozeno z prvního DL, plus DL badge info)
$nedavne_fa = $pdo->query("
    SELECT f.id, f.cislo, f.datum_vystaveni, f.datum_splatnosti,
           f.castka_celkem, f.castka_uhrazeno, f.rucni,
           od.nazev AS odberatel,
           CASE
               WHEN f.castka_uhrazeno >= f.castka_celkem THEN 'uhrazena'
               WHEN f.datum_splatnosti < CURDATE() THEN 'po_splatnosti'
               ELSE 'cekajici'
           END AS stav_uhrady,
           (SELECT COUNT(DISTINCT fdl.dodaci_list_id) FROM faktury_dodaci_listy fdl WHERE fdl.faktura_id = f.id) AS pocet_dl,
           (SELECT MIN(dl.objednavka_id)
              FROM faktury_dodaci_listy fdl
              JOIN dodaci_listy dl ON dl.id = fdl.dodaci_list_id
             WHERE fdl.faktura_id = f.id) AS prvni_dl_objednavka_id,
           (SELECT MIN(fdl.dodaci_list_id) FROM faktury_dodaci_listy fdl WHERE fdl.faktura_id = f.id) AS prvni_dl_id,
           (SELECT md.nazev
              FROM faktury_dodaci_listy fdl
              JOIN dodaci_listy dl ON dl.id = fdl.dodaci_list_id
              LEFT JOIN mista_dodani md ON md.id = dl.misto_dodani_id
             WHERE fdl.faktura_id = f.id LIMIT 1) AS misto_nazev
    FROM faktury f
    JOIN odberatele od ON od.id = f.odberatel_id
    ORDER BY f.datum_vystaveni DESC, f.id DESC
    LIMIT 5
")->fetchAll();

// 🆕 v3.0.132 — Položky pro nedávné faktury (z faktura_polozky)
$nedavneFaIds = array_column($nedavne_fa, 'id');
if ($nedavneFaIds) {
    $inFa = implode(',', array_map('intval', $nedavneFaIds));
    $faPolRows = $pdo->query("
        SELECT fp.faktura_id AS pid,
               fp.mnozstvi,
               COALESCE(NULLIF(fp.vyrobek_nazev, ''), v.nazev) AS nazev
        FROM faktura_polozky fp
        LEFT JOIN vyrobky v ON v.id = fp.vyrobek_id
        WHERE fp.faktura_id IN ($inFa)
        ORDER BY fp.faktura_id, fp.poradi, fp.id
    ")->fetchAll();
    $faPolBy = [];
    foreach ($faPolRows as $pr) {
        $faPolBy[$pr['pid']][] = ['nazev' => $pr['nazev'], 'mnozstvi' => $pr['mnozstvi']];
    }
    foreach ($nedavne_fa as &$f) {
        $items = $faPolBy[$f['id']] ?? [];
        $f['pocet_polozek'] = count($items);
        $f['polozky']       = array_slice($items, 0, 3);
    }
    unset($f);
}

// 🆕 v2.9.242 — Alerts widget pro Dashboard (rychlé COUNTs, indexed columns)
$alerts = [
    'obj_bez_dl' => 0,    // objednávky doručené ale bez DL (>3 dny)
    'dl_bez_fa'  => 0,    // DL nefakturované >7 dní
    'sklad_pod_min' => 0, // suroviny pod minimální zásobou (legacy stock_*)
];
try {
    // Objednávky doručené, starší 3 dny, bez DL
    $a = $pdo->query("
        SELECT COUNT(*) FROM objednavky o
        WHERE o.stav = 'dorucena'
          AND o.datum_dodani < CURDATE() - INTERVAL 3 DAY
          AND NOT EXISTS (SELECT 1 FROM dodaci_listy dl WHERE dl.objednavka_id = o.id)
    ")->fetchColumn();
    $alerts['obj_bez_dl'] = (int) $a;
} catch (Throwable $e) { /* table missing — skip */ }

try {
    // DL nefakturované, starší 7 dní
    $b = $pdo->query("
        SELECT COUNT(*) FROM dodaci_listy dl
        WHERE COALESCE(dl.fakturovano, 0) = 0
          AND dl.datum_vystaveni < CURDATE() - INTERVAL 7 DAY
    ")->fetchColumn();
    $alerts['dl_bez_fa'] = (int) $b;
} catch (Throwable $e) { /* skip */ }

try {
    // Suroviny pod minimální zásobou (legacy `suroviny.stock_aktualni` + `stock_minimalni`)
    $c = $pdo->query("
        SELECT COUNT(*) FROM suroviny
        WHERE aktivni = 1
          AND stock_minimalni IS NOT NULL
          AND stock_minimalni > 0
          AND COALESCE(stock_aktualni, 0) <= stock_minimalni
    ")->fetchColumn();
    $alerts['sklad_pod_min'] = (int) $c;
} catch (Throwable $e) { /* skip */ }

json_response([
    'obdobi'        => $obdobi,
    'datum_od'      => $od,
    'datum_do'      => $do,
    'dny_v_obdobi'  => (int) $dny_v_obdobi,

    'obdobi_stats'  => $obdobi_stats,
    'trzby_kanaly'  => $trzby_kanaly,
    'dnes'          => $dnes,
    'po_splatnosti' => $po_splatnosti,
    'alerts'        => $alerts,

    'casovy_graf'   => $casovy_graf,
    'top_odberatele'=> $top_odberatele,
    'top_vyrobky'   => $top_vyrobky,

    'vyroba_zitra'  => $vyroba_zitra,
    'nedavne'       => $nedavne,
    'nedavne_dl'    => $nedavne_dl,
    'nedavne_fa'    => $nedavne_fa,
]);
