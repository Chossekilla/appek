<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/_admin_auth.php';
cors_headers();
require_admin();

$pdo = db();

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
$stmt = $pdo->prepare("
    SELECT COUNT(*) AS objednavek,
           COALESCE(SUM(castka_celkem), 0) AS trzby,
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

// Vždy: dnes + po splatnosti
$dnes = $pdo->query("
    SELECT COUNT(*) AS objednavek,
           COALESCE(SUM(castka_celkem), 0) AS trzby
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
           COALESCE(SUM(castka_celkem), 0) AS trzby
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

json_response([
    'obdobi'        => $obdobi,
    'datum_od'      => $od,
    'datum_do'      => $do,
    'dny_v_obdobi'  => (int) $dny_v_obdobi,

    'obdobi_stats'  => $obdobi_stats,
    'dnes'          => $dnes,
    'po_splatnosti' => $po_splatnosti,

    'casovy_graf'   => $casovy_graf,
    'top_odberatele'=> $top_odberatele,
    'top_vyrobky'   => $top_vyrobky,

    'vyroba_zitra'  => $vyroba_zitra,
    'nedavne'       => $nedavne,
    'nedavne_dl'    => $nedavne_dl,
    'nedavne_fa'    => $nedavne_fa,
]);
