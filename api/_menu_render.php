<?php
/**
 * 🍽️ RENDERER TÝDENNÍHO MENU — 5 rozložení × barevné palety (v3.0.413).
 *
 * Jeden zdroj HTML pro veřejnou stránku (menu_public.php) i tisk A4
 * (admin_tydenni_menu.php action=tisk). Vrací ['css'=>…, 'body'=>…].
 *
 * $res  = resolved týden z tm_resolve (tyden_od/tyden_do/poznamka/custom_text/
 *         styl/layout, dny={po:[{nazev,cena_s_dph,pozn,alergeny,existuje}]…}).
 * $mode = 'public' (obrazovka/sociální) | 'print' (A4 k tisku).
 */

require_once __DIR__ . '/_menu_styly.php';

const MR_DNY = ['po' => 'Pondělí', 'ut' => 'Úterý', 'st' => 'Středa', 'ct' => 'Čtvrtek', 'pa' => 'Pátek', 'so' => 'Sobota', 'ne' => 'Neděle'];

/** Cena → "145,-" (nebo prázdné). */
function mr_cena($c): string {
    if ($c === null || $c === '') return '';
    return number_format((float) $c, 0, ',', ' ') . ',-';
}

/** Neprázdné dny s existujícími položkami: [ [key,label,datum,items[]], … ]. */
function mr_dny_seznam(array $res): array {
    $out = [];
    $i = 0;
    foreach (MR_DNY as $k => $label) {
        $items = array_values(array_filter((array) ($res['dny'][$k] ?? []), fn($it) => !empty($it['existuje'])));
        if ($items) {
            $out[] = ['key' => $k, 'label' => $label, 'datum' => date('j. n.', strtotime($res['tyden_od'] . " +{$i} days")), 'items' => $items];
        }
        $i++;
    }
    return $out;
}

/** Alergeny "1,7" → bublinky. */
function mr_alergeny_html(string $al, array $st): string {
    $al = trim($al);
    if ($al === '') return '';
    $parts = array_filter(array_map('trim', preg_split('/[,;\s]+/', $al)));
    if (!$parts) return '';
    $out = ' ';
    foreach ($parts as $p) {
        $out .= '<span class="al">' . htmlspecialchars($p) . '</span>';
    }
    return $out;
}

function mr_e($s): string { return htmlspecialchars((string) $s); }

/**
 * Hlavní vstup. Vrací ['css'=>…, 'body'=>…].
 */
function menu_render(array $res, array $firma, string $mode = 'public'): array {
    $st = menu_styl($res['styl'] ?? 'restaurace');
    $layout = menu_layout_key($res['layout'] ?? 'karta');
    $fN = mr_e($firma['firma_nazev'] ?? 'Restaurace');
    $odS = date('j. n.', strtotime($res['tyden_od']));
    $doS = date('j. n. Y', strtotime($res['tyden_do']));
    $dny = mr_dny_seznam($res);
    $ctext = trim((string) ($res['custom_text'] ?? ''));
    $pozn = trim((string) ($res['poznamka'] ?? ''));
    $print = $mode === 'print';

    $fnName = 'mr_layout_' . $layout;
    if (!function_exists($fnName)) $fnName = 'mr_layout_karta';
    return $fnName(compact('st', 'fN', 'odS', 'doS', 'dny', 'ctext', 'pozn', 'firma', 'print', 'res'));
}

/** Patička s kontakty (sdílená). */
function mr_footer_txt(array $firma): string {
    $out = mr_e($firma['firma_nazev'] ?? '');
    if (($firma['firma_telefon'] ?? '') !== '') $out .= ' · 📞 ' . mr_e($firma['firma_telefon']);
    if (($firma['firma_ulice'] ?? '') !== '')   $out .= ' · 📍 ' . mr_e($firma['firma_ulice'] . ', ' . ($firma['firma_mesto'] ?? ''));
    return $out;
}

// ════════════════════════════════════════════════════════════════
// LAYOUT 1 — KARTA (mobilní karta s gradientem)
// ════════════════════════════════════════════════════════════════
function mr_layout_karta(array $c): array {
    extract($c); /** @var array $st */
    $css = "
      body { font-family: {$st['font']}; background: {$st['bg']}; color: {$st['text']}; margin: 0; padding: 20px 14px 40px; }
      .card { max-width: 560px; margin: 0 auto; background: {$st['card']}; border-radius: 18px; box-shadow: 0 8px 30px rgba(0,0,0,.08); overflow: hidden; }
      .hd { background: linear-gradient(135deg, {$st['grad_a']}, {$st['grad_b']}); color: #fff; padding: 22px 22px 18px; }
      .hd h1 { margin: 0; font-size: 21px; } .hd .sub { opacity: .9; font-size: 13px; margin-top: 3px; }
      .ct { padding: 12px 22px; font-size: 14px; color: {$st['accent']}; font-weight: 600; text-align: center; }
      .note { padding: 8px 22px; font-size: 12.5px; color: {$st['muted']}; font-style: italic; }
      .day { padding: 14px 22px 4px; }
      .day h2 { font-size: 13px; text-transform: uppercase; letter-spacing: .08em; color: {$st['accent']}; margin: 0 0 6px; border-bottom: 2px solid {$st['accent_soft']}; padding-bottom: 4px; }
      .it { display: flex; justify-content: space-between; gap: 12px; padding: 7px 0; border-bottom: 1px dashed #eee; font-size: 14.5px; }
      .it small { color: {$st['muted']}; font-size: 11px; } .al { display:inline-block; background:{$st['accent_soft']}; color:{$st['accent']}; border-radius:8px; padding:0 5px; font-size:10px; margin-left:2px; }
      .c { white-space: nowrap; font-weight: 700; }
      .ft { padding: 16px 22px 20px; font-size: 12px; color: {$st['muted']}; border-top: 1px solid #f0f0f0; }
    ";
    $b = "<div class=\"card\"><div class=\"hd\"><h1>{$st['nadpis']} Týdenní menu {$odS} – {$doS}</h1><div class=\"sub\">{$fN}</div></div>";
    if ($ctext !== '') $b .= '<div class="ct">' . nl2br(mr_e($ctext)) . '</div>';
    if ($pozn !== '')  $b .= '<div class="note">📝 ' . mr_e($pozn) . '</div>';
    foreach ($dny as $d) {
        $b .= '<div class="day"><h2>' . mr_e($d['label']) . ' ' . $d['datum'] . '</h2>';
        foreach ($d['items'] as $it) {
            $b .= '<div class="it"><div>' . mr_e($it['nazev'])
                . ($it['pozn'] !== '' ? ' <small>(' . mr_e($it['pozn']) . ')</small>' : '')
                . mr_alergeny_html($it['alergeny'], $st)
                . '</div><div class="c">' . mr_cena($it['cena_s_dph']) . '</div></div>';
        }
        $b .= '</div>';
    }
    $b .= '<div class="ft">' . mr_footer_txt($firma) . '</div></div>';
    return ['css' => $css, 'body' => $b];
}

// ════════════════════════════════════════════════════════════════
// LAYOUT 2 — KLASIK BISTRO (denní menu, dny pod sebou)
// ════════════════════════════════════════════════════════════════
function mr_layout_klasik(array $c): array {
    extract($c);
    $css = "
      body { font-family: {$st['font']}; background: {$st['bg']}; color: {$st['text']}; margin: 0; padding: 24px 16px; }
      .wrap { max-width: 720px; margin: 0 auto; background: {$st['card']}; padding: 30px 34px; border-radius: 10px; box-shadow: 0 4px 24px rgba(0,0,0,.06); }
      .hd { text-align: center; margin-bottom: 6px; }
      .hd h1 { font-size: 27px; margin: 0; letter-spacing: -.01em; }
      .hd .sub { color: {$st['accent']}; font-weight: 700; font-size: 15px; }
      .ct { text-align:center; color:{$st['accent']}; font-weight:600; font-size:14px; margin:10px 0; }
      .note { text-align:center; font-style:italic; color:{$st['muted']}; font-size:13px; margin-bottom:6px; }
      hr { border:0; border-top:2px solid {$st['accent_soft']}; margin:14px 0; }
      .day { margin: 16px 0; }
      .dlabel { color:{$st['accent']}; font-weight:800; font-size:15px; text-transform:uppercase; letter-spacing:.03em; border-bottom:2px solid {$st['accent_soft']}; padding-bottom:3px; margin-bottom:8px; }
      .row { display:flex; gap:12px; padding:5px 0; align-items:baseline; }
      .num { color:{$st['muted']}; font-weight:700; min-width:20px; }
      .name { flex:1; } .name b { font-weight:700; } .name small { color:{$st['muted']}; display:block; font-size:12.5px; }
      .al { display:inline-block; background:{$st['accent_soft']}; color:{$st['accent']}; border-radius:9px; padding:0 6px; font-size:11px; margin-left:3px; font-weight:700; }
      .price { font-weight:800; white-space:nowrap; font-size:15px; }
      .ft { margin-top:22px; border-top:1px solid #eee; padding-top:12px; text-align:center; color:{$st['muted']}; font-size:12px; }
    ";
    $b = '<div class="wrap"><div class="hd"><h1>' . $st['nadpis'] . ' Týdenní menu</h1><div class="sub">' . $odS . ' – ' . $doS . ' · ' . $fN . '</div></div>';
    if ($ctext !== '') $b .= '<div class="ct">' . nl2br(mr_e($ctext)) . '</div>';
    if ($pozn !== '')  $b .= '<div class="note">📝 ' . mr_e($pozn) . '</div>';
    $b .= '<hr>';
    foreach ($dny as $d) {
        $b .= '<div class="day"><div class="dlabel">' . mr_e($d['label']) . ' ' . $d['datum'] . '</div>';
        $n = 1;
        foreach ($d['items'] as $it) {
            $b .= '<div class="row"><div class="num">' . $n++ . '.</div><div class="name"><b>' . mr_e($it['nazev']) . '</b>'
                . mr_alergeny_html($it['alergeny'], $st)
                . ($it['pozn'] !== '' ? '<small>' . mr_e($it['pozn']) . '</small>' : '')
                . '</div><div class="price">' . mr_cena($it['cena_s_dph']) . '</div></div>';
        }
        $b .= '</div>';
    }
    $b .= '<div class="ft">' . mr_footer_txt($firma) . '</div></div>';
    return ['css' => $css, 'body' => $b];
}

// ════════════════════════════════════════════════════════════════
// LAYOUT 3 — ELEGANT (fine dining, serif, tečkované vodítko)
// ════════════════════════════════════════════════════════════════
function mr_layout_elegant(array $c): array {
    extract($c);
    $css = "
      body { font-family: Georgia, 'Times New Roman', serif; background: {$st['bg']}; color: {$st['text']}; margin: 0; padding: 40px 20px; }
      .wrap { max-width: 620px; margin: 0 auto; text-align: center; }
      .eyebrow { letter-spacing:.32em; text-transform:uppercase; font-size:11px; color:{$st['accent']}; }
      h1 { font-size: 32px; font-weight: 400; margin: 6px 0 2px; letter-spacing:.02em; }
      .sub { color:{$st['muted']}; font-size:14px; font-style:italic; margin-bottom:18px; }
      .ct { color:{$st['accent']}; font-size:15px; font-style:italic; margin:14px auto; max-width:460px; }
      .note { color:{$st['muted']}; font-size:13px; font-style:italic; }
      .rule { width:56px; height:1px; background:{$st['accent']}; margin:22px auto; }
      .day { margin: 26px auto; max-width:480px; text-align:left; }
      .dlabel { text-align:center; letter-spacing:.22em; text-transform:uppercase; font-size:12px; color:{$st['accent']}; margin-bottom:12px; }
      .row { display:flex; align-items:baseline; gap:6px; margin:10px 0; }
      .name { font-size:16px; } .name em { color:{$st['muted']}; font-size:13px; }
      .lead { flex:1; border-bottom:1px dotted {$st['muted']}; margin:0 2px 4px; }
      .price { font-size:15px; }
      .al { color:{$st['muted']}; font-size:11px; font-style:italic; }
      .ft { margin-top:30px; color:{$st['muted']}; font-size:12px; }
    ";
    $b = '<div class="wrap"><div class="eyebrow">' . $fN . '</div><h1>Týdenní menu</h1><div class="sub">' . $odS . ' – ' . $doS . '</div>';
    if ($ctext !== '') $b .= '<div class="ct">' . nl2br(mr_e($ctext)) . '</div>';
    if ($pozn !== '')  $b .= '<div class="note">' . mr_e($pozn) . '</div>';
    $b .= '<div class="rule"></div>';
    foreach ($dny as $d) {
        $b .= '<div class="day"><div class="dlabel">' . mr_e($d['label']) . ' · ' . $d['datum'] . '</div>';
        foreach ($d['items'] as $it) {
            $al = trim($it['alergeny']) !== '' ? ' <span class="al">al. ' . mr_e($it['alergeny']) . '</span>' : '';
            $b .= '<div class="row"><span class="name">' . mr_e($it['nazev'])
                . ($it['pozn'] !== '' ? ' <em>' . mr_e($it['pozn']) . '</em>' : '') . $al . '</span>'
                . '<span class="lead"></span><span class="price">' . mr_cena($it['cena_s_dph']) . '</span></div>';
        }
        $b .= '</div>';
    }
    $b .= '<div class="ft">' . mr_footer_txt($firma) . '</div></div>';
    return ['css' => $css, 'body' => $b];
}

// ════════════════════════════════════════════════════════════════
// LAYOUT 4 — EDITORIAL (specialita týdne + dvousloupcové sekce)
// ════════════════════════════════════════════════════════════════
function mr_layout_sloupce(array $c): array {
    extract($c);
    // "specialita" = první položka prvního dne; zbytek do sloupců
    $feat = null; $flat = $dny;
    if ($flat && $flat[0]['items']) {
        $feat = $flat[0]['items'][0];
        $flat[0]['items'] = array_slice($flat[0]['items'], 1);
        $flat = array_values(array_filter($flat, fn($d) => count($d['items'])));
    }
    $css = "
      body { font-family: {$st['font']}; background: {$st['bg']}; color: {$st['text']}; margin: 0; padding: 30px 22px 50px; }
      .wrap { max-width: 820px; margin: 0 auto; }
      .top { color:{$st['muted']}; letter-spacing:.14em; font-size:13px; font-weight:700; text-transform:uppercase; }
      h1 { font-size: 46px; line-height:.98; margin: 4px 0 14px; font-weight: 900; letter-spacing:-.02em; }
      .ct { color:{$st['accent']}; font-weight:700; font-size:15px; margin-bottom:10px; }
      .feat { border-bottom:3px solid {$st['text']}; padding-bottom:16px; margin-bottom:18px; overflow:hidden; }
      .feat .fn { font-size:20px; font-weight:800; text-transform:uppercase; } .feat .fp { float:right; font-weight:800; font-size:18px; }
      .feat .fd { color:{$st['muted']}; font-size:15px; margin-top:4px; }
      .cols { column-count:2; column-gap:34px; }
      @media (max-width:620px){ .cols{column-count:1;} h1{font-size:34px;} }
      .sec { break-inside:avoid; margin-bottom:20px; }
      .sec.box { background:rgba(0,0,0,.035); border-radius:12px; padding:14px 16px; }
      .sech { font-weight:800; text-transform:uppercase; letter-spacing:.04em; font-size:15px; color:{$st['accent']}; margin-bottom:8px; text-align:center; }
      .item { margin:9px 0; overflow:hidden; } .item .nm { font-weight:700; font-size:14.5px; } .item .pr { float:right; font-weight:700; }
      .item .ds { color:{$st['muted']}; font-size:12.5px; }
      .al { display:inline-block; background:{$st['accent_soft']}; color:{$st['accent']}; border-radius:9px; padding:0 6px; font-size:11px; margin-left:3px; font-weight:700; }
      .note { color:{$st['muted']}; font-style:italic; font-size:13px; margin-bottom:12px; }
      .ft { margin-top:26px; border-top:1px solid rgba(0,0,0,.1); padding-top:12px; color:{$st['muted']}; font-size:12px; }
    ";
    $b = '<div class="wrap"><div class="top">' . $odS . ' – ' . $doS . ' &nbsp; ' . $fN . '</div><h1>SPECIALITY<br>TÝDNE</h1>';
    if ($ctext !== '') $b .= '<div class="ct">' . nl2br(mr_e($ctext)) . '</div>';
    if ($feat) {
        $b .= '<div class="feat"><div><span class="fn">' . mr_e($feat['nazev']) . '</span>' . mr_alergeny_html($feat['alergeny'], $st)
            . '<span class="fp">' . mr_cena($feat['cena_s_dph']) . '</span></div>'
            . ($feat['pozn'] !== '' ? '<div class="fd">' . mr_e($feat['pozn']) . '</div>' : '') . '</div>';
    }
    if ($pozn !== '') $b .= '<div class="note">📝 ' . mr_e($pozn) . '</div>';
    $b .= '<div class="cols">';
    foreach ($flat as $idx => $d) {
        $box = $idx % 2 === 1 ? ' box' : '';
        $b .= '<div class="sec' . $box . '"><div class="sech">' . mr_e($d['label']) . ' ' . $d['datum'] . '</div>';
        foreach ($d['items'] as $it) {
            $b .= '<div class="item"><span class="nm">' . mr_e($it['nazev']) . '</span>' . mr_alergeny_html($it['alergeny'], $st)
                . '<span class="pr">' . mr_cena($it['cena_s_dph']) . '</span>'
                . ($it['pozn'] !== '' ? '<div class="ds">' . mr_e($it['pozn']) . '</div>' : '') . '</div>';
        }
        $b .= '</div>';
    }
    $b .= '</div><div class="ft">' . mr_footer_txt($firma) . '</div></div>';
    return ['css' => $css, 'body' => $b];
}

// ════════════════════════════════════════════════════════════════
// LAYOUT 5 — TABULE (tmavá křídová tabule)
// ════════════════════════════════════════════════════════════════
function mr_layout_tabule(array $c): array {
    extract($c);
    $gold = $st['grad_a'];
    $css = "
      body { font-family: {$st['font']}; background: #1A1613; color: #ECE6DC; margin: 0; padding: 30px 18px 50px;
             background-image: radial-gradient(rgba(255,255,255,.03) 1px, transparent 1px); background-size: 4px 4px; }
      .wrap { max-width: 640px; margin: 0 auto; border: 2px solid rgba(255,255,255,.12); border-radius: 12px; padding: 28px 26px; }
      .hd { text-align:center; border-bottom:2px solid {$gold}; padding-bottom:12px; margin-bottom:8px; }
      .hd h1 { margin:0; font-size:28px; color:#fff; letter-spacing:.02em; }
      .hd .sub { color:{$gold}; font-size:14px; margin-top:3px; font-weight:600; }
      .ct { text-align:center; color:{$gold}; font-size:15px; margin:12px 0; font-style:italic; }
      .note { text-align:center; color:#B9B0A2; font-size:13px; font-style:italic; }
      .day { margin:18px 0; }
      .dlabel { color:{$gold}; font-weight:800; font-size:15px; text-transform:uppercase; letter-spacing:.1em; margin-bottom:8px; }
      .row { display:flex; justify-content:space-between; gap:12px; padding:6px 0; border-bottom:1px dashed rgba(255,255,255,.12); }
      .row small { color:#9C9384; display:block; font-size:12px; }
      .al { display:inline-block; border:1px solid {$gold}; color:{$gold}; border-radius:9px; padding:0 6px; font-size:10.5px; margin-left:3px; }
      .price { color:#fff; font-weight:800; white-space:nowrap; }
      .ft { margin-top:24px; border-top:1px solid rgba(255,255,255,.12); padding-top:12px; text-align:center; color:#9C9384; font-size:12px; }
    ";
    $b = '<div class="wrap"><div class="hd"><h1>' . $st['nadpis'] . ' Týdenní menu</h1><div class="sub">' . $odS . ' – ' . $doS . ' · ' . $fN . '</div></div>';
    if ($ctext !== '') $b .= '<div class="ct">' . nl2br(mr_e($ctext)) . '</div>';
    if ($pozn !== '')  $b .= '<div class="note">📝 ' . mr_e($pozn) . '</div>';
    foreach ($dny as $d) {
        $b .= '<div class="day"><div class="dlabel">' . mr_e($d['label']) . ' ' . $d['datum'] . '</div>';
        foreach ($d['items'] as $it) {
            $al = trim($it['alergeny']) !== '' ? ' <span class="al">' . mr_e($it['alergeny']) . '</span>' : '';
            $b .= '<div class="row"><div>' . mr_e($it['nazev']) . $al
                . ($it['pozn'] !== '' ? '<small>' . mr_e($it['pozn']) . '</small>' : '')
                . '</div><div class="price">' . mr_cena($it['cena_s_dph']) . '</div></div>';
        }
        $b .= '</div>';
    }
    $b .= '<div class="ft">' . mr_footer_txt($firma) . '</div></div>';
    return ['css' => $css, 'body' => $b];
}
