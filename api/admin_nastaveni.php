<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/_pwa_lib.php';
require_once __DIR__ . '/_admin_auth.php';
cors_headers();
require_admin();

$method = $_SERVER['REQUEST_METHOD'];
$pdo = db();
$action = $_GET['action'] ?? '';
appek_ensure_pwa_icons();  // 🆕 v3.0.364 — naseeduj PWA install ikony (default APPEK), pokud chybí

// =============================================================
// 🖼️ UPLOAD LOGA + AUTO-FAVICON — POST ?action=upload_logo (multipart)
// 1) Uloží originál do /uploads/logo/logo.{ext}
// 2) Vygeneruje favicon.png (32×32) ve stejné složce
// 3) Aktualizuje nastaveni: firma_logo_url + firma_favicon_url
// =============================================================
if ($method === 'POST' && $action === 'upload_logo') {
    if (empty($_FILES['logo'])) json_error('Chybí soubor');
    $file = $_FILES['logo'];
    if ($file['error'] !== UPLOAD_ERR_OK) json_error('Chyba uploadu (kód ' . $file['error'] . ')');

    $allowed = ['image/jpeg', 'image/png', 'image/webp'];
    $mime = mime_content_type($file['tmp_name']);
    if (!in_array($mime, $allowed)) json_error('Povolen je jen JPG, PNG nebo WEBP');
    if ($file['size'] > 5 * 1024 * 1024) json_error('Soubor je větší než 5 MB');

    // Načti přes GD
    $img = match($mime) {
        'image/jpeg' => @imagecreatefromjpeg($file['tmp_name']),
        'image/png'  => @imagecreatefrompng($file['tmp_name']),
        'image/webp' => @imagecreatefromwebp($file['tmp_name']),
    };
    if (!$img) json_error('Nepodařilo se zpracovat obrázek (potřebuje GD extension)');

    $upload_dir = __DIR__ . '/../uploads/logo';
    if (!is_dir($upload_dir)) mkdir($upload_dir, 0755, true);
    // .htaccess — zakaž PHP
    $ht = $upload_dir . '/.htaccess';
    if (!file_exists($ht)) {
        file_put_contents($ht, "<FilesMatch \"\\.(php|php3|php4|php5|phtml|phar)$\">\n    Require all denied\n</FilesMatch>\n");
    }

    $w = imagesx($img);
    $h = imagesy($img);

    // === 1) Uložit logo — zachovat průhlednost, max 400px delší strana ===
    $logoMax = 400;
    if ($w > $logoMax || $h > $logoMax) {
        if ($w >= $h) { $nw = $logoMax; $nh = (int)($h * $logoMax / $w); }
        else          { $nh = $logoMax; $nw = (int)($w * $logoMax / $h); }
        $resized = imagecreatetruecolor($nw, $nh);
        imagealphablending($resized, false);
        imagesavealpha($resized, true);
        $transparent = imagecolorallocatealpha($resized, 0, 0, 0, 127);
        imagefilledrectangle($resized, 0, 0, $nw, $nh, $transparent);
        imagecopyresampled($resized, $img, 0, 0, 0, 0, $nw, $nh, $w, $h);
        $logoImg = $resized;
    } else {
        $logoImg = $img;
    }

    // Vyčistit staré soubory (zachovat audit přes timestamp v URL)
    foreach (glob($upload_dir . '/logo.*') as $old) @unlink($old);
    foreach (glob($upload_dir . '/favicon.*') as $old) @unlink($old);

    $logoExt = ($mime === 'image/jpeg') ? 'jpg' : 'png';
    $logoPath = $upload_dir . '/logo.' . $logoExt;
    if ($logoExt === 'jpg') imagejpeg($logoImg, $logoPath, 90);
    else imagepng($logoImg, $logoPath, 6);

    // === 2) Vygeneruj favicon 32×32 (vždy PNG, s průhledností) ===
    $favSize = 32;
    $fav = imagecreatetruecolor($favSize, $favSize);
    imagealphablending($fav, false);
    imagesavealpha($fav, true);
    $transparent = imagecolorallocatealpha($fav, 0, 0, 0, 127);
    imagefilledrectangle($fav, 0, 0, $favSize, $favSize, $transparent);
    // Vystředit logo (čtvercově)
    $srcW = imagesx($logoImg);
    $srcH = imagesy($logoImg);
    $ratio = min($favSize / $srcW, $favSize / $srcH);
    $dstW = (int) ($srcW * $ratio);
    $dstH = (int) ($srcH * $ratio);
    $dstX = (int) (($favSize - $dstW) / 2);
    $dstY = (int) (($favSize - $dstH) / 2);
    imagecopyresampled($fav, $logoImg, $dstX, $dstY, 0, 0, $dstW, $dstH, $srcW, $srcH);

    $favPath = $upload_dir . '/favicon.png';
    imagepng($fav, $favPath, 6);

    // 🆕 v3.0.364 — PWA install ikony (192/512/maskable/apple) ze zákazníkova loga (white-label, bílé pozadí)
    appek_gen_pwa_icons_from_gd($logoImg);

    imagedestroy($logoImg);
    imagedestroy($fav);
    if ($logoImg !== $img) imagedestroy($img);

    // === 3) Uložit URL do nastaveni (s cache-bust timestampem) ===
    $ts = time();
    $logoUrl = app_url_path('uploads/logo/logo.') . $logoExt . '?v=' . $ts; // v3.0.639 podsložka
    $favUrl  = app_url_path('uploads/logo/favicon.png') . '?v=' . $ts;

    $st = $pdo->prepare("INSERT INTO nastaveni (klic, hodnota) VALUES (:k, :v) ON DUPLICATE KEY UPDATE hodnota = :v2");
    $st->execute(['k' => 'firma_logo_url',    'v' => $logoUrl, 'v2' => $logoUrl]);
    $st->execute(['k' => 'firma_favicon_url', 'v' => $favUrl,  'v2' => $favUrl]);

    json_response([
        'ok' => true,
        'logo_url'    => $logoUrl,
        'favicon_url' => $favUrl,
    ]);
}

// 🗑️ DELETE LOGO — POST ?action=remove_logo
if ($method === 'POST' && $action === 'remove_logo') {
    $upload_dir = __DIR__ . '/../uploads/logo';
    if (is_dir($upload_dir)) {
        foreach (glob($upload_dir . '/logo.*') as $f) @unlink($f);
        foreach (glob($upload_dir . '/favicon.*') as $f) @unlink($f);
    }
    appek_reset_pwa_icons();  // 🆕 v3.0.364 — smaž custom PWA ikony → zpět APPEK default
    $pdo->prepare("DELETE FROM nastaveni WHERE klic IN ('firma_logo_url','firma_favicon_url')")->execute();
    json_response(['ok' => true]);
}

// =============================================================
// 📝 EMAIL TEMPLATES — správa šablon e-mailů
// GET    ?action=email_templates[&lang=cs|en|es]  → seznam šablon (s defaulty) v jazyce
// PUT    ?action=email_template { klic, predmet, telo, aktivni?, lang? }  → uložit
// POST   ?action=email_template_reset { klic, lang? }  → reset na default
// POST   ?action=email_template_preview { klic, vars?, lang? }  → vrátí vyrenderovaný text
// 🌍 v3.0.643 — jazykové varianty zákaznických šablon (en/es) se ukládají do STEJNÉ tabulky
//   pod klíčem 'klic@en' / 'klic@es' (beze změny schématu; česká 'klic' zůstává, jak byla).
//   Interní 'admin_nova_objednavka' varianty nemá.
// =============================================================
/** Jazyk editoru šablon z požadavku (cs/en/es), jinak cs. */
function email_tpl_req_lang($v): string {
    $l = strtolower(trim((string) $v));
    if ($l === '') return 'cs';                                   // parametr nevyplněn = čeština (zpětná kompatibilita)
    if (!in_array($l, EMAIL_LANGS, true)) json_error('Nepodporovaný jazyk šablony (cs/en/es)', 400);   // dřív tiše přepsal českou
    return $l;
}
/** Vzorová data pro náhled šablony v daném jazyce (částky v Kč jako vzor). */
function email_tpl_sample(PDO $pdo, string $lang): array {
    email_i18n_boot();
    $m = fn($n) => _fmt_money_loc($n, $lang, 'CZK');
    [$dec, $thou] = _num_seps($lang);
    $ks = _unit_loc('ks', $lang);
    return [
        'firma'          => nastaveni_get($pdo, 'firma_nazev', 'APPEK B2B'),
        'cislo'          => 'OBJ-2026-148',
        'datum'          => _fmt_date_loc('2026-05-14', $lang),
        'misto'          => '',
        'odberatel'      => 'Hotel Beránek s.r.o.',
        'stav'           => email_stav_label('expedovana', $lang),
        'polozky_text'   => "  • Chleba selský — 12 $ks × " . $m(28) . ' = ' . $m(336) . "\n  • Bageta — 8 $ks × " . $m(18) . ' = ' . $m(144),
        'castka_bez_dph' => $m(420),
        'castka_dph'     => $m(60),
        'castka_celkem'  => $m(480),
        'poznamka'       => ['cs' => 'Dovézt prosím před 8:30.', 'en' => 'Please deliver before 8:30.', 'es' => 'Entregar antes de las 8:30, por favor.'][$lang] ?? '',
    ];
}

if ($action === 'email_templates' && $method === 'GET') {
    ensure_email_templates_table($pdo);
    $lang = email_tpl_req_lang($_GET['lang'] ?? 'cs');
    $defaults = email_template_defaults();
    $defaultsLang = $lang === 'cs' ? [] : email_template_defaults_lang($lang);
    // Načti uložené z DB
    $ulozene = [];
    try {
        $rows = $pdo->query("SELECT klic, predmet, telo, format, popis, aktivni FROM email_templates")->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as $r) $ulozene[$r['klic']] = $r;
    } catch (Throwable $e) { /* tabulka neexistuje, vrátíme defaulty */ }

    // Merge: každá šablona z defaulta + případně přepsání z DB
    $result = [];
    foreach ($defaults as $klic => $def) {
        $maVariantu = $lang === 'cs' || isset($defaultsLang[$klic]);
        if (!$maVariantu) continue;   // interní šablona (jen česky) — v en/es seznamu není
        $dbKlic = $lang === 'cs' ? $klic : $klic . '@' . $lang;
        $defX = $lang === 'cs' ? $def : $defaultsLang[$klic];
        $u = $ulozene[$dbKlic] ?? null;
        $result[] = [
            'klic'    => $klic,
            'lang'    => $lang,
            'popis'   => $lang === 'cs' ? ($u['popis'] ?? $def['popis'] ?? '') : ($def['popis'] ?? ''),
            'predmet' => $u['predmet'] ?? $defX['predmet'] ?? '',
            'telo'    => $u['telo']    ?? $defX['telo']    ?? '',
            'format'  => $u['format']  ?? 'text',
            'aktivni' => $u ? (int) $u['aktivni'] : 1,
            'default' => $defX,        // pro reset/zobrazení defaultu
            'upraveno' => $u ? true : false,
            'jen_cesky' => !isset(email_template_defaults_lang('en')[$klic]),
        ];
    }
    $sample = email_tpl_sample($pdo, $lang);
    json_response(['templates' => $result, 'lang' => $lang, 'jazyky' => EMAIL_LANGS,
        'sample' => $sample,
        'poznamka_label' => $lang === 'cs' ? 'Poznámka' : _t_lang($lang, 'Poznámka'),
        'promenne' => [
        'firma' => 'Název firmy z nastavení',
        'cislo' => 'Číslo objednávky (např. OBJ-2026-148)',
        'datum' => 'Datum dodání (např. 14. 5. 2026)',
        'misto' => 'Místo dodání (pobočka)',
        'odberatel' => 'Název odběratele',
        'stav' => 'Aktuální stav (přijata, expedována...)',
        'polozky_text' => 'Seznam položek (více řádků)',
        'castka_bez_dph' => 'Částka bez DPH',
        'castka_dph' => 'Hodnota DPH',
        'castka_celkem' => 'Celková částka s DPH',
        'poznamka' => 'Poznámka k objednávce',
        'poznamka_block' => 'Speciální: zobrazí se "Poznámka: ..." jen pokud je vyplněná',
    ]]);
}

if ($action === 'email_template' && $method === 'PUT') {
    ensure_email_templates_table($pdo);
    $d = json_input();
    $klic = trim($d['klic'] ?? '');
    if (!$klic) json_error('Chybí klic');
    $defaults = email_template_defaults();
    if (!isset($defaults[$klic])) json_error('Neznámá šablona');
    $lang = email_tpl_req_lang($d['lang'] ?? 'cs');
    if ($lang !== 'cs' && !isset(email_template_defaults_lang($lang)[$klic])) json_error('Tato šablona je jen česká (interní)');
    $dbKlic = $lang === 'cs' ? $klic : $klic . '@' . $lang;
    $popis = $defaults[$klic]['popis'] ?? null;
    if ($lang !== 'cs' && $popis !== null) $popis .= ' [' . strtoupper($lang) . ']';
    $format = ($d['format'] ?? 'text') === 'html' ? 'html' : 'text';

    $pdo->prepare("
        INSERT INTO email_templates (klic, predmet, telo, format, popis, aktivni)
        VALUES (:k, :p, :t, :f, :pp, :a)
        ON DUPLICATE KEY UPDATE predmet = :p2, telo = :t2, format = :f2, aktivni = :a2
    ")->execute([
        'k'  => $dbKlic,
        'p'  => trim($d['predmet'] ?? ''),
        't'  => $d['telo'] ?? '',
        'f'  => $format,
        'pp' => $popis,
        'a'  => isset($d['aktivni']) ? (int)(bool)$d['aktivni'] : 1,
        'p2' => trim($d['predmet'] ?? ''),
        't2' => $d['telo'] ?? '',
        'f2' => $format,
        'a2' => isset($d['aktivni']) ? (int)(bool)$d['aktivni'] : 1,
    ]);
    json_response(['ok' => true, 'klic' => $dbKlic]);
}

// 🎲 Generuj náhodnou HTML šablonu daného designu
if ($action === 'email_template_generate_html' && $method === 'GET') {
    require_once __DIR__ . '/_email_html_designs.php';
    $klic = $_GET['klic'] ?? 'objednavka_expedovana';
    $style = $_GET['style'] ?? 'modern';
    if ($style === 'random') {
        $styles = array_keys(email_html_designs_list());
        $style = $styles[array_rand($styles)];
    }
    $designs = email_html_designs_list();
    if (!isset($designs[$style])) json_error('Neznámý design');
    json_response([
        'style'    => $style,
        'popis'    => $designs[$style],
        'designs'  => $designs,   // pro vykreslení seznamu na frontend
        'telo'     => email_html_design($style, $klic),
    ]);
}

if ($action === 'email_template_reset' && $method === 'POST') {
    ensure_email_templates_table($pdo);
    $d = json_input();
    $klic = trim($d['klic'] ?? '');
    if (!$klic) json_error('Chybí klic');
    $lang = email_tpl_req_lang($d['lang'] ?? 'cs');
    // 🌍 v3.0.643 — reset varianty v jazyce smaže jen 'klic@lang' (česká šablona zůstane)
    $pdo->prepare("DELETE FROM email_templates WHERE klic = :k")->execute(['k' => $lang === 'cs' ? $klic : $klic . '@' . $lang]);
    json_response(['ok' => true]);
}

if ($action === 'email_template_preview' && $method === 'POST') {
    $d = json_input();
    $klic = trim($d['klic'] ?? '');
    $lang = email_tpl_req_lang($d['lang'] ?? 'cs');
    $tpl = email_template_load($pdo, $klic, $lang);
    $tLang = $tpl['jazyk'] ?? 'cs';
    // Vzorové proměnné (v jazyce šablony) — pokud uživatel pošle vlastní, mergne se
    $sample = email_tpl_sample($pdo, $tLang);
    if (isset($d['vars']) && is_array($d['vars'])) $sample = array_merge($sample, $d['vars']);
    json_response([
        'predmet' => email_template_render($tpl['predmet'], $sample, $tLang),
        'telo'    => email_template_render($tpl['telo'], $sample, $tLang, $tpl['format'] ?? 'text'),
        'format'  => $tpl['format'] ?? 'text',  // 🔧 frontend potřebuje vědět, jestli renderovat jako HTML
        'jazyk'   => $tLang,
    ]);
}

// =============================================================
// TEST EMAIL — POST ?action=test_email { email: "..." }
// =============================================================
if ($method === 'POST' && $action === 'test_email') {
    $d = json_input();
    $komu = trim($d['email'] ?? '');
    if (!filter_var($komu, FILTER_VALIDATE_EMAIL)) {
        json_error('Neplatný formát emailu');
    }

    $firma = nastaveni_get($pdo, 'firma_nazev', 'Provoz');
    $from  = nastaveni_get($pdo, 'firma_email', '');

    $predmet = "Testovací email z $firma";
    $telo  = "Toto je testovací zpráva z administrace.\n\n";
    $telo .= "Pokud tento email čtete, znamená to, že odesílání e-mailů funguje.\n\n";
    $telo .= "Odesílatel (firma_email v Nastavení): " . ($from ?: '— prázdné —') . "\n";
    $telo .= "Server: " . ($_SERVER['HTTP_HOST'] ?? 'localhost') . "\n";
    $telo .= "Čas odeslání: " . date('j. n. Y H:i:s') . "\n\n";
    $telo .= "S pozdravem,\n$firma";

    $ok = poslat_email([$komu], $predmet, $telo);

    if ($ok) {
        json_response([
            'ok' => true,
            'zprava' => 'Email odeslán. Zkontrolujte schránku (i složku spam).',
        ]);
    } else {
        json_error('PHP mail() vrátilo chybu. Zkontrolujte log v Hostinger panelu nebo nastavte firma_email na adresu, kterou Hostinger autorizuje.', 500);
    }
}

// =============================================================
// 🆕 v3.0.212 — PRODEJNÍ KANÁLY (objednavky.puvod)
//   GET  ?action=kanaly  → konfigurace (defaulty + override z nastaveni)
//   POST ?action=kanaly  {kanaly:{klic:{label,barva,pokladni,zapnuto}}}
//   Sada kanálů je pevná; panel mění jen label/barvu/pokladni/zapnuto.
// =============================================================
if ($action === 'kanaly' && $method === 'GET') {
    $cfg = kanaly_config($pdo);
    $out = [];
    foreach (kanaly_defaults() as $k => $_d) {
        $out[] = ['klic' => $k] + $cfg[$k];
    }
    json_response(['kanaly' => $out]);
}

if ($action === 'kanaly' && $method === 'POST') {
    $d = json_input();
    $in = $d['kanaly'] ?? null;
    if (!is_array($in)) json_error('Neplatná data', 400);
    $defaults = kanaly_defaults();
    $override = [];
    foreach ($in as $k => $v) {
        if (!isset($defaults[$k]) || !is_array($v)) continue; // jen známé kanály
        $row = [];
        if (array_key_exists('label', $v)) {
            $lbl = trim((string) $v['label']);
            if ($lbl !== '') $row['label'] = mb_substr($lbl, 0, 40);
        }
        if (array_key_exists('barva', $v) && preg_match('/^#[0-9a-fA-F]{6}$/', trim((string) $v['barva']))) {
            $row['barva'] = trim((string) $v['barva']);
        }
        if (array_key_exists('pokladni', $v)) $row['pokladni'] = (bool) $v['pokladni'];
        if (array_key_exists('zapnuto', $v))  $row['zapnuto']  = (bool) $v['zapnuto'];
        if ($row) $override[$k] = $row;
    }
    $json = json_encode($override, JSON_UNESCAPED_UNICODE);
    $pdo->prepare("INSERT INTO nastaveni (klic, hodnota, popis) VALUES ('kanaly_config', :v, 'Prodejní kanály (puvod objednávky)') ON DUPLICATE KEY UPDATE hodnota = :v2")
        ->execute(['v' => $json, 'v2' => $json]);
    json_response(['ok' => true]);
}

if ($method === 'GET') {
    $stmt = $pdo->query("SELECT klic, hodnota, popis FROM nastaveni ORDER BY klic");
    $vsechna = $stmt->fetchAll();

    $data = [];
    foreach ($vsechna as $n) {
        $data[$n['klic']] = $n['hodnota'];
    }
    // 📧 v3.0.289 — SMTP heslo NIKDY plain do frontendu; jen indikace že je nastavené
    if (isset($data['smtp_pass'])) {
        $data['smtp_pass'] = $data['smtp_pass'] !== '' ? '••••••••' : '';
    }

    json_response($data);
}

if ($method === 'PUT') {
    $d = json_input();
    if (empty($d)) json_error('Žádná data');

    $povolene = [
        'firma_nazev', 'firma_ico', 'firma_dic',
        'firma_ulice', 'firma_mesto', 'firma_psc',
        'firma_banka', 'firma_email', 'firma_telefon', 'firma_web',
        // Logo + favicon (URL relativní k webroot, např. /uploads/logo/logo.png)
        'firma_logo_url', 'firma_favicon_url', 'firma_logo_na_dokladech',
        // Patička dokladů (faktura, dodací list)
        'firma_paticka_dokladu',
        // 🌍 v3.0.640 — výchozí jazyk dokladů (cs/en/es; odběratel může mít vlastní)
        'firma_jazyk_dokladu',
        // 🆕 v3.0.358 — zamknout mazání vydaných dokladů (faktura/DL) kvůli souvislosti číselné řady: '1'|'0'
        'faktura_zamknout_mazani',
        // 🌍 Lokalizace — jazyk UI, měna, formát data, země pro doklady
        'firma_jazyk',        // 'cs' | 'en' | 'es' — UI jazyk
        'firma_locale',       // 'cs-CZ' | 'en-US' | 'en-GB' | 'es-ES' | 'es-MX' — Intl locale
        'firma_mena',         // 'CZK' | 'EUR' | 'USD' | 'GBP' | 'MXN' — měna
        'firma_zeme',         // 'CZ' | 'US' | 'GB' | 'ES' | 'MX' — ISO země
        'firma_format_data',  // 'dd.MM.yyyy' | 'MM/dd/yyyy' | 'dd/MM/yyyy'
        // Email + objednávky
        'admin_email_pro_objednavky',
        'uzaverka_hodina', 'uzaverka_dni_predem',
        // 🆕 v3.0.317 — sazba DPH příplatků doprava/poplatek (default 21; pro neplátce/jiné sazby)
        'dph_sazba_priplatky',
        // 🆕 v3.0.326 — skener čárových kódů (JSON: enabled/hw_enabled/hw_prefix/hw_suffix/hw_min_len/camera_enabled/default_action/beep)
        'scanner_config',
        // Notifikace pro odběratele
        'notif_nova_objednavka', 'notif_zmena_stavu', 'notif_stavy_pro_email',
        // Fixní náklady pro kalkulaci výrobků
        'naklady_polozky',
        // Email účetní pro automatický export ISDOC
        'export_isdoc_email',
        // HACCP defaultní hodnoty (JSON)
        'haccp_defaults',
        // 🆕 v2.9.270 — Custom kategorie surovin (JSON array)
        'suroviny_kategorie',
        // 🔎 v3.0.446/454 — HACCP sledovatelnost šarží: master přepínač + úroveň kontroly ve výrobě
        //   (dřív chybělo ve whitelistu → toggly se neukládaly). enforce: 'off'|'warn'|'block'
        'sklad_sledovatelnost', 'sklad_sled_enforce',
        // 🔔 v3.0.447 — vypnutí dashboard panelu „Akce vyžadující pozornost": '1'|'0'
        'dashboard_alerts_off',
        // 🆕 v3.0.218 — styl stránkování dlouhých seznamů: 'load_more' | 'stranky' | 'infinite'
        'pagination_styl',
        // 🆕 v3.0.247 — počet řádků na stránku (25/50/100/200)
        'pagination_pocet',
        // ⚡ v3.0.252 — odlehčený režim (výkon): '1' | '0'
        'vykon_lite',
        // 🖥️ v3.0.522 — skrýt dekorativní ikony/emoji (pro starší monitory, kde se renderují jako □): '1' | '0'
        'skryt_ikony',
        // 🖼️ obrázky/ikony v seznamu surovin: '1' | '0'
        'seznam_obrazky',
        // 🛒 v3.0.495 — sticky spodní košík na B2B eshopu: '1' | '0'
        'b2b_sticky_cart',
        // 🛒 v3.0.501 — režim košíku na B2B: side | bottom | both
        'b2b_cart_mode',
        // 📊 v3.0.284/286 — Google Analytics measurement ID — B2B portál + POS pokladna zvlášť
        // 📊 v3.0.310 — + admin core (hlavní aplikace)
        'ga_measurement_id',
        'ga_measurement_id_pos',
        'ga_measurement_id_core',
        // 🍪 v3.0.401 — vlastní sledovací kód (HTML/JS, např. Meta Pixel/Sklik) pro B2B portál;
        //   vkládá se návštěvníkům AŽ po souhlasu s analytickými cookies (viz b2b/app.js consent)
        'tracking_custom_code',
        // 📧 v3.0.289 — SMTP odesílání
        'smtp_enabled', 'smtp_host', 'smtp_port', 'smtp_user', 'smtp_pass',
        'smtp_secure', 'smtp_from', 'smtp_from_name',
    ];

    $applied = [];
    $ignored = [];

    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare("
            INSERT INTO nastaveni (klic, hodnota) VALUES (:k, :v)
            ON DUPLICATE KEY UPDATE hodnota = :v2
        ");
        foreach ($d as $klic => $val) {
            if (!in_array($klic, $povolene, true)) {
                $ignored[] = $klic;
                continue;
            }
            $hodnota = is_string($val) ? trim($val) : (string) $val;
            // 📧 v3.0.289 — SMTP heslo: prázdné / maska = NEPŘEPISUJ uložené (UI ho maskuje)
            if ($klic === 'smtp_pass' && ($hodnota === '' || $hodnota === '••••••••')) { continue; }
            $stmt->execute(['k' => $klic, 'v' => $hodnota, 'v2' => $hodnota]);
            $applied[] = $klic;
        }
        $pdo->commit();
        json_response(['ok' => true, 'applied' => $applied, 'ignored' => $ignored]);
    } catch (Exception $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        error_log('admin_nastaveni PUT: ' . $e->getMessage());
        json_error('Nepodařilo se uložit nastavení', 500);
    }
}

json_error('Neplatná metoda', 405);
