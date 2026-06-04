<?php
require_once __DIR__ . '/config.php';
cors_headers();
$odberatel_id = require_odberatel();

// 🆕 v3.0.157 — filtr období (default tento měsíc). Demo/noví odběratelé mívají data
//   z minulého měsíce → „tento měsíc" pak ukáže 0; proto volitelný period.
$period = $_GET['period'] ?? 'mesic';
switch ($period) {
    case 'minuly': // minulý kalendářní měsíc
        $dateCond = "datum_objednani >= DATE_FORMAT(CURDATE() - INTERVAL 1 MONTH, '%Y-%m-01')
                     AND datum_objednani < DATE_FORMAT(CURDATE(), '%Y-%m-01')";
        break;
    case '90': // posledních 90 dní
        $dateCond = "datum_objednani >= CURDATE() - INTERVAL 90 DAY";
        break;
    case 'vse': // celá historie
        $dateCond = "1=1";
        break;
    case 'mesic':
    default:
        $period = 'mesic';
        $dateCond = "datum_objednani >= DATE_FORMAT(CURDATE(), '%Y-%m-01')";
        break;
}

$stmt = db()->prepare("
    SELECT COUNT(*) AS objednavek,
           COALESCE(SUM(castka_celkem), 0) AS utrata,
           COALESCE(AVG(castka_celkem), 0) AS prumer
    FROM objednavky
    WHERE odberatel_id = :odb AND $dateCond
");
$stmt->execute(['odb' => $odberatel_id]);
$souhrn = $stmt->fetch();

// stejná podmínka, ale s aliasem o. (druhý dotaz joinuje objednavky o)
$dateCondO = str_replace('datum_objednani', 'o.datum_objednani', $dateCond);
$stmt = db()->prepare("
    SELECT v.nazev, SUM(p.mnozstvi) AS mnozstvi
    FROM objednavky_polozky p
    JOIN vyrobky v ON v.id = p.vyrobek_id
    JOIN objednavky o ON o.id = p.objednavka_id
    WHERE o.odberatel_id = :odb AND $dateCondO
    GROUP BY v.id, v.nazev
    ORDER BY mnozstvi DESC LIMIT 10
");
$stmt->execute(['odb' => $odberatel_id]);
$top = $stmt->fetchAll();

$total = array_sum(array_column($top, 'mnozstvi'));
foreach ($top as &$t) {
    $t['pct'] = $total > 0 ? round($t['mnozstvi'] / $total * 100) : 0;
}

json_response(['souhrn' => $souhrn, 'top_vyrobky' => $top, 'period' => $period]);
