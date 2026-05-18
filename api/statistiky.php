<?php
require_once __DIR__ . '/config.php';
cors_headers();
$odberatel_id = require_odberatel();

$stmt = db()->prepare("
    SELECT COUNT(*) AS objednavek,
           COALESCE(SUM(castka_celkem), 0) AS utrata,
           COALESCE(AVG(castka_celkem), 0) AS prumer
    FROM objednavky
    WHERE odberatel_id = :odb
      AND datum_objednani >= DATE_FORMAT(CURDATE(), '%Y-%m-01')
");
$stmt->execute(['odb' => $odberatel_id]);
$souhrn = $stmt->fetch();

$stmt = db()->prepare("
    SELECT v.nazev, SUM(p.mnozstvi) AS mnozstvi
    FROM objednavky_polozky p
    JOIN vyrobky v ON v.id = p.vyrobek_id
    JOIN objednavky o ON o.id = p.objednavka_id
    WHERE o.odberatel_id = :odb
      AND o.datum_objednani >= DATE_FORMAT(CURDATE(), '%Y-%m-01')
    GROUP BY v.id, v.nazev
    ORDER BY mnozstvi DESC LIMIT 10
");
$stmt->execute(['odb' => $odberatel_id]);
$top = $stmt->fetchAll();

$total = array_sum(array_column($top, 'mnozstvi'));
foreach ($top as &$t) {
    $t['pct'] = $total > 0 ? round($t['mnozstvi'] / $total * 100) : 0;
}

json_response(['souhrn' => $souhrn, 'top_vyrobky' => $top]);
