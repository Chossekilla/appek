<?php
/**
 * Sales report — agregovaná statistika za zvolené období.
 *
 * GET ?od=YYYY-MM-DD&do=YYYY-MM-DD
 *   → JSON: { celkem_obj, celkem_kc, prum_obj, top_vyrobky, top_odberatele, denni_trzby }
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/_admin_auth.php';
cors_headers();
require_admin();

$pdo = db();
$od = $_GET['od'] ?? date('Y-m-01');
$do = $_GET['do'] ?? date('Y-m-t');
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $od)) json_error('Neplatný od');
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $do)) json_error('Neplatný do');

// Hlavní stats — z objednávek
$stats = $pdo->prepare("
    SELECT
        COUNT(DISTINCT o.id)                                     AS celkem_obj,
        COUNT(DISTINCT o.odberatel_id)                           AS pocet_odberatelu,
        COALESCE(SUM(o.castka_celkem), 0)                        AS celkem_kc,
        COALESCE(SUM(o.castka_bez_dph), 0)                       AS celkem_bez_dph,
        COALESCE(AVG(o.castka_celkem), 0)                        AS prum_obj
    FROM objednavky o
    WHERE o.datum_dodani BETWEEN :od AND :do
      AND o.stav NOT IN ('zrusena')
");
$stats->execute(['od' => $od, 'do' => $do]);
$summary = $stats->fetch(PDO::FETCH_ASSOC);

// Top 10 výrobků
$topVyr = $pdo->prepare("
    SELECT v.id, v.cislo, v.nazev, k.ikona AS kat_ikona, k.nazev AS kat_nazev,
           SUM(op.mnozstvi) AS celkem_ks,
           SUM(op.mnozstvi * op.cena_bez_dph * (1 + op.sazba_dph/100)) AS celkem_kc,
           COUNT(DISTINCT op.objednavka_id) AS pocet_obj
    FROM objednavky o
    JOIN objednavky_polozky op ON op.objednavka_id = o.id
    LEFT JOIN vyrobky v ON v.id = op.vyrobek_id
    LEFT JOIN kategorie_vyrobku k ON k.id = v.kategorie_id
    WHERE o.datum_dodani BETWEEN :od AND :do
      AND o.stav NOT IN ('zrusena')
    GROUP BY v.id, v.cislo, v.nazev, k.ikona, k.nazev
    ORDER BY celkem_kc DESC
    LIMIT 10
");
$topVyr->execute(['od' => $od, 'do' => $do]);
$top_vyrobky = $topVyr->fetchAll(PDO::FETCH_ASSOC);

// Top 10 odběratelů
$topOdb = $pdo->prepare("
    SELECT od.id, od.nazev,
           COUNT(o.id)                  AS pocet_obj,
           SUM(o.castka_celkem)         AS celkem_kc,
           AVG(o.castka_celkem)         AS prum_obj
    FROM objednavky o
    JOIN odberatele od ON od.id = o.odberatel_id
    WHERE o.datum_dodani BETWEEN :od AND :do
      AND o.stav NOT IN ('zrusena')
    GROUP BY od.id, od.nazev
    ORDER BY celkem_kc DESC
    LIMIT 10
");
$topOdb->execute(['od' => $od, 'do' => $do]);
$top_odberatele = $topOdb->fetchAll(PDO::FETCH_ASSOC);

// Denní tržby (pro graf)
$denni = $pdo->prepare("
    SELECT datum_dodani AS den,
           COUNT(*) AS pocet,
           SUM(castka_celkem) AS kc
    FROM objednavky
    WHERE datum_dodani BETWEEN :od AND :do
      AND stav NOT IN ('zrusena')
    GROUP BY datum_dodani
    ORDER BY datum_dodani
");
$denni->execute(['od' => $od, 'do' => $do]);
$denni_trzby = $denni->fetchAll(PDO::FETCH_ASSOC);

// Top kategorie — 🆕 v3.0.335 tržby subkategorií se rollup-ují pod HLAVNÍ kategorii
$topKat = $pdo->prepare("
    SELECT COALESCE(k.parent_id, k.id) AS id,
           COALESCE(pk.nazev, k.nazev) AS nazev,
           COALESCE(pk.ikona, k.ikona) AS ikona,
           SUM(op.mnozstvi * op.cena_bez_dph * (1 + op.sazba_dph/100)) AS celkem_kc,
           SUM(op.mnozstvi) AS celkem_ks
    FROM objednavky o
    JOIN objednavky_polozky op ON op.objednavka_id = o.id
    LEFT JOIN vyrobky v ON v.id = op.vyrobek_id
    LEFT JOIN kategorie_vyrobku k ON k.id = v.kategorie_id
    LEFT JOIN kategorie_vyrobku pk ON pk.id = k.parent_id
    WHERE o.datum_dodani BETWEEN :od AND :do
      AND o.stav NOT IN ('zrusena')
      AND k.id IS NOT NULL
    GROUP BY COALESCE(k.parent_id, k.id), COALESCE(pk.nazev, k.nazev), COALESCE(pk.ikona, k.ikona)
    ORDER BY celkem_kc DESC
");
$topKat->execute(['od' => $od, 'do' => $do]);
$top_kategorie = $topKat->fetchAll(PDO::FETCH_ASSOC);

json_response([
    'od' => $od,
    'do' => $do,
    'summary' => $summary,
    'top_vyrobky' => $top_vyrobky,
    'top_odberatele' => $top_odberatele,
    'top_kategorie' => $top_kategorie,
    'denni_trzby' => $denni_trzby,
]);
