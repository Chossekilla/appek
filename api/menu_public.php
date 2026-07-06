<?php
/**
 * 🗓️ VEŘEJNÉ TÝDENNÍ MENU — sdílecí stránka (v3.0.409, Restaurace balíček).
 *
 * GET ?t=TOKEN — BEZ přihlášení (token = kapabilita). Renderuje týdenní menu
 * s og: metadaty pro hezký náhled na sociálních sítích (FB/X/WhatsApp).
 * Žádná citlivá data — jen jídla a ceny, jako vývěska před restaurací.
 */

require_once __DIR__ . '/config.php';

$tok = preg_replace('/[^a-f0-9]/', '', strtolower((string) ($_GET['t'] ?? '')));
header('Content-Type: text/html; charset=UTF-8');

$w = null;
if (strlen($tok) === 32) {
    try {
        $st = db()->prepare("SELECT * FROM tydenni_menu WHERE public_token = :t");
        $st->execute(['t' => $tok]);
        $w = $st->fetch(PDO::FETCH_ASSOC);
    } catch (Throwable $e) { $w = null; }
}
if (!$w) {
    http_response_code(404);
    echo '<!DOCTYPE html><html lang="cs"><head><meta charset="UTF-8"><title>Menu nenalezeno</title></head><body style="font-family:sans-serif;text-align:center;padding:60px"><h1>🍽️</h1><p>Toto menu už není dostupné.</p></body></html>';
    exit;
}

$pdo = db();
$dny = json_decode($w['dny'] ?? '[]', true) ?: [];
$DNY = ['po' => 'Pondělí', 'ut' => 'Úterý', 'st' => 'Středa', 'ct' => 'Čtvrtek', 'pa' => 'Pátek', 'so' => 'Sobota', 'ne' => 'Neděle'];

$ids = [];
foreach ($DNY as $k => $_) foreach ((array) ($dny[$k] ?? []) as $r) $ids[] = (int) ($r['vyrobek_id'] ?? 0);
$ids = array_values(array_unique(array_filter($ids)));
$map = [];
if ($ids) {
    $ph = implode(',', array_fill(0, count($ids), '?'));
    $st = $pdo->prepare("
        SELECT v.id, v.nazev, ROUND(v.cena_bez_dph * (1 + COALESCE(sd.sazba, 12) / 100), 0) AS cena,
               COALESCE(v.alergeny, '') AS alergeny
        FROM vyrobky v LEFT JOIN sazby_dph sd ON sd.id = v.sazba_dph_id WHERE v.id IN ($ph)
    ");
    $st->execute($ids);
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) $map[(int) $r['id']] = $r;
}

$firma = [];
try { foreach ($pdo->query("SELECT klic, hodnota FROM nastaveni WHERE klic LIKE 'firma_%'") as $r) $firma[$r['klic']] = $r['hodnota']; } catch (Throwable $e) {}
$fN = htmlspecialchars($firma['firma_nazev'] ?? 'Restaurace');

$odS = date('j. n.', strtotime($w['tyden_od']));
$doS = date('j. n. Y', strtotime($w['tyden_od'] . ' +6 days'));
$titulek = "Týdenní menu {$odS} – {$doS}";
// og:description = první ~3 jídla
$prvni = [];
foreach ($DNY as $k => $_) {
    foreach ((array) ($dny[$k] ?? []) as $r) {
        $v = $map[(int) ($r['vyrobek_id'] ?? 0)] ?? null;
        if ($v) $prvni[] = $v['nazev'];
        if (count($prvni) >= 3) break 2;
    }
}
$ogDesc = htmlspecialchars(implode(' · ', $prvni) . (count($prvni) >= 3 ? ' …' : ''));

// 🆕 v3.0.413 — sdílený renderer (5 rozložení × palety). Sestav $res do tvaru tm_resolve.
require_once __DIR__ . '/_menu_render.php';
$resDny = [];
foreach (['po', 'ut', 'st', 'ct', 'pa', 'so', 'ne'] as $k) {
    $resDny[$k] = [];
    foreach ((array) ($dny[$k] ?? []) as $r) {
        $v = $map[(int) ($r['vyrobek_id'] ?? 0)] ?? null;
        $resDny[$k][] = [
            'nazev' => $v['nazev'] ?? '', 'cena_s_dph' => $v ? (float) $v['cena'] : null,
            'pozn' => $r['pozn'] ?? '', 'alergeny' => $v['alergeny'] ?? '', 'existuje' => (bool) $v,
        ];
    }
}
$res = [
    'tyden_od' => $w['tyden_od'], 'tyden_do' => date('Y-m-d', strtotime($w['tyden_od'] . ' +6 days')),
    'poznamka' => $w['poznamka'] ?? '', 'custom_text' => $w['custom_text'] ?? '',
    'styl' => $w['styl'] ?? 'restaurace', 'layout' => $w['layout'] ?? 'karta', 'dny' => $resDny,
];
$rend = menu_render($res, $firma, 'public');
?>
<!DOCTYPE html>
<html lang="cs">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= htmlspecialchars($titulek) ?> · <?= $fN ?></title>
<meta property="og:title" content="🗓️ <?= htmlspecialchars($titulek) ?>">
<meta property="og:description" content="<?= $ogDesc ?>">
<meta property="og:type" content="website">
<meta property="og:site_name" content="<?= $fN ?>">
<meta name="robots" content="noindex">
<style>* { box-sizing: border-box; } <?= $rend['css'] ?></style>
</head>
<body>
<?= $rend['body'] ?>
</body>
</html>
