<?php
/**
 * 🌍💱 v3.0.643 — automatické e-maily zákazníkům v jazyce odběratele (cs/en/es) a v měně objednávky.
 * Integrační test nad lokální DB (api/config.local.php). NIC NEODESÍLÁ a nic trvale nemění
 * (zápisy šablon jen v transakci s ROLLBACK).
 *
 * Spuštění: php scripts/tests/emaily/test_emaily_jazyk.php   (exit 0 = vše OK)
 * Volitelně: EMAIL_TEST_EUR_ID=85738 (EUR objednávka, kurz 25.25)
 */
require_once __DIR__ . '/../../../api/config.php';
email_i18n_boot();

$pdo = db();
$ok = 0; $fail = 0;
function t(string $nazev, bool $podminka, string $detail = ''): void {
    global $ok, $fail;
    if ($podminka) { $ok++; } else { $fail++; echo "❌ $nazev" . ($detail !== '' ? "\n   $detail" : '') . "\n"; }
}

// ── LEGACY: přesná kopie výpočtu před v3.0.643 (fmt_kc, fmt_date, česká výchozí šablona) ──
function legacy_render(string $text, array $vars): string {
    if (strpos($text, '{poznamka_block}') !== false) {
        $pozn = trim($vars['poznamka'] ?? '');
        $text = str_replace('{poznamka_block}', $pozn !== '' ? "\nPoznámka: $pozn\n" : '', $text);
    }
    foreach ($vars as $k => $v) $text = str_replace('{' . $k . '}', (string) $v, $text);
    return $text;
}
function legacy_obj(PDO $pdo, int $id) {
    $st = $pdo->prepare("SELECT o.*, od.nazev AS odb_nazev, md.nazev AS misto_nazev FROM objednavky o
        JOIN odberatele od ON od.id = o.odberatel_id LEFT JOIN mista_dodani md ON md.id = o.misto_dodani_id WHERE o.id = :id");
    $st->execute(['id' => $id]);
    return $st->fetch(PDO::FETCH_ASSOC);
}
function legacy_nova(PDO $pdo, int $id): array {
    $o = legacy_obj($pdo, $id);
    $st = $pdo->prepare("SELECT op.mnozstvi, op.cena_bez_dph, op.sazba_dph, COALESCE(v.nazev, op.vyrobek_nazev) AS nazev, v.cislo,
        COALESCE(j.kod, op.jednotka) AS jednotka FROM objednavky_polozky op LEFT JOIN vyrobky v ON v.id = op.vyrobek_id
        LEFT JOIN jednotky j ON j.id = v.jednotka_id WHERE op.objednavka_id = :id ORDER BY nazev");
    $st->execute(['id' => $id]);
    $pt = '';
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $p) {
        $mn = rtrim(rtrim(number_format((float) $p['mnozstvi'], 2, ',', ' '), '0'), ',');
        $cena = (float) $p['cena_bez_dph'];
        $celkem = $cena * (float) $p['mnozstvi'] * (1 + (float) $p['sazba_dph'] / 100);
        $pt .= sprintf("  • %s — %s %s × %s = %s\n", $p['nazev'], $mn, $p['jednotka'] ?? 'ks', fmt_kc($cena), fmt_kc($celkem));
    }
    $vars = ['firma' => nastaveni_get($pdo, 'firma_nazev', 'Provoz'), 'cislo' => $o['cislo'], 'datum' => fmt_date($o['datum_dodani']),
        'misto' => $o['misto_nazev'] ?: '—', 'odberatel' => $o['odb_nazev'], 'polozky_text' => $pt,
        'castka_bez_dph' => fmt_kc($o['castka_bez_dph']), 'castka_dph' => fmt_kc($o['castka_dph']),
        'castka_celkem' => fmt_kc($o['castka_celkem']), 'poznamka' => $o['poznamka'] ?? ''];
    $tpl = email_template_defaults()['objednavka_nova'];
    return [legacy_render($tpl['predmet'], $vars), legacy_render($tpl['telo'], $vars)];
}
function legacy_stav(PDO $pdo, int $id, string $novy): array {
    $o = legacy_obj($pdo, $id);
    $labels = ['potvrzena' => 'potvrzena', 've_vyrobe' => 've výrobě', 'pripravena' => 'připravena k expedici',
               'expedovana' => 'EXPEDOVÁNA', 'dorucena' => 'DORUČENA', 'zrusena' => 'ZRUŠENA'];
    $vars = ['firma' => nastaveni_get($pdo, 'firma_nazev', 'Provoz'), 'cislo' => $o['cislo'], 'datum' => fmt_date($o['datum_dodani']),
        'misto' => $o['misto_nazev'] ?: '—', 'odberatel' => $o['odb_nazev'] ?? '', 'stav' => $labels[$novy],
        'castka_celkem' => fmt_kc($o['castka_celkem']), 'castka_bez_dph' => fmt_kc($o['castka_bez_dph']),
        'castka_dph' => fmt_kc($o['castka_dph']), 'poznamka' => $o['poznamka'] ?? ''];
    $tpl = email_template_defaults()['objednavka_' . $novy];
    return [legacy_render($tpl['predmet'], $vars), legacy_render($tpl['telo'], $vars)];
}
function placeholders(string $s): array { preg_match_all('/\{[a-z_]+\}/', $s, $m); $u = array_values(array_unique($m[0])); sort($u); return $u; }

$STAVY = ['potvrzena', 've_vyrobe', 'pripravena', 'expedovana', 'dorucena', 'zrusena'];
$firma = nastaveni_get($pdo, 'firma_nazev', 'Provoz');

// Upravené šablony v DB by změnily „výchozí" výstup → porovnání s legacy jen nad výchozími texty
email_template_load($pdo, 'objednavka_nova');   // ensure_email_templates_table (DDL) MIMO transakci
$vlastni = (int) $pdo->query("SELECT COUNT(*) FROM email_templates WHERE klic LIKE 'objednavka\\_%'")->fetchColumn();

// ── 1) Výchozí en/es šablony: stejné klíče a proměnné jako česká ─────────────────────────
$cs = email_template_defaults();
foreach (['en', 'es'] as $L) {
    $d = email_template_defaults_lang($L);
    t("$L: klíče = česká bez interní admin šablony", array_keys($d) === array_values(array_diff(array_keys($cs), ['admin_nova_objednavka'])));
    foreach ($d as $k => $tpl) {
        t("$L/$k: stejné proměnné v předmětu", placeholders($tpl['predmet']) === placeholders($cs[$k]['predmet']));
        t("$L/$k: stejné proměnné v těle", placeholders($tpl['telo']) === placeholders($cs[$k]['telo']),
          json_encode([placeholders($tpl['telo']), placeholders($cs[$k]['telo'])]));
        t("$L/$k: bez české diakritiky", !preg_match('/[ěščřžůĚŠČŘŽŮ]/u', $tpl['predmet'] . $tpl['telo']));
    }
}

// ── 2) Česká objednávka v Kč = IDENTICKÝ text jako před v3.0.643 ─────────────────────────
if ($vlastni === 0) {
    $ids = $pdo->query("SELECT id FROM objednavky WHERE (mena = 'CZK' OR mena IS NULL) AND odberatel_id IN (SELECT id FROM odberatele)
                        ORDER BY (poznamka IS NOT NULL AND poznamka <> '') DESC, id DESC LIMIT 15")->fetchAll(PDO::FETCH_COLUMN);
    t('máme české Kč objednávky', count($ids) > 0);
    foreach ($ids as $id) {
        $id = (int) $id;
        $r = notif_render_nova($pdo, $id, 'cs');
        [$p, $b] = legacy_nova($pdo, $id);
        t("CZK #$id nová: předmět identický", $r['predmet'] === $p, $r['predmet'] . ' ≠ ' . $p);
        t("CZK #$id nová: tělo identické", $r['telo'] === $b);
        $o = legacy_obj($pdo, $id);
        t("CZK #$id push identický", $r['push']['title'] === '✓ Objednávka přijata'
            && $r['push']['body'] === "Vaše obj. {$o['cislo']} byla přijata. Dodání: " . fmt_date($o['datum_dodani']));
        foreach ($STAVY as $st) {
            $r = notif_render_stav($pdo, $id, $st, 'cs');
            [$p, $b] = legacy_stav($pdo, $id, $st);
            t("CZK #$id $st: identické", $r['predmet'] === $p && $r['telo'] === $b);
        }
        $diff = ['pridane' => [['nazev' => 'Rohlík', 'mnozstvi' => 3]], 'zmenene' => [['nazev' => 'Chléb', 'puvodne' => 2, 'nove' => 2.5]], 'odebrane' => [['nazev' => 'Bageta', 'mnozstvi' => 1]]];
        [$p, $b] = notif_zmena_objednavky_text($pdo, $id, $o, ['castka' => (float) $o['castka_celkem'] + 10, 'diff' => $diff, 'kdo' => 'Jan (administrace)'], 'cs');
        $exp = "Vážení,\n\nv objednávce #{$o['cislo']} (datum dodání " . fmt_date($o['datum_dodani']) . ") byly provedeny tyto změny:\nZměnu provedl: Jan (administrace)\n"
             . str_repeat('-', 50) . "\n\nPŘIDÁNO:\n  + Rohlík: 3 ks\n\nZMĚNĚNO:\n  ~ Chléb: 2 ks → 2,5 ks\n\nODEBRÁNO:\n  - Bageta: 1 ks\n\n"
             . str_repeat('-', 50) . "\nPůvodní částka: " . fmt_kc($o['castka_celkem']) . "\nNová částka:    " . fmt_kc((float) $o['castka_celkem'] + 10)
             . "\n\nS pozdravem\n" . $firma;
        t("CZK #$id úprava: identická", $p === "Objednávka #{$o['cislo']} byla UPRAVENA" && $b === $exp);
        [$p, $b] = notif_zmena_objednavky_text($pdo, $id, $o, ['zruseno' => true, 'kdo' => 'Odběratel X'], 'cs');
        t("CZK #$id zrušení: identické", $p === "Objednávka #{$o['cislo']} byla ZRUŠENA"
            && $b === "Vážení,\n\nobjednávka #{$o['cislo']} (datum dodání " . fmt_date($o['datum_dodani']) . ") byla zrušena.\nZměnu provedl: Odběratel X\n\nPokud k tomu došlo omylem, vytvořte prosím novou objednávku.\n\nS pozdravem\n" . $firma);
    }
} else {
    echo "ℹ️  V DB jsou upravené šablony objednávek ($vlastni) — porovnání s výchozím textem přeskočeno.\n";
}

// ── 3) EUR objednávka: částky = doc_celkem z mena_doc_pole, jazyk cs/en/es ──────────────
$eurId = (int) (getenv('EMAIL_TEST_EUR_ID') ?: 85738);
$eur = legacy_obj($pdo, $eurId);
$doc = null;
if ($eur && strtoupper((string) $eur['mena']) === 'EUR') {
    $rows = [['id' => $eurId]];
    mena_doc_pole($pdo, 'objednavky', $rows);
    $doc = $rows[0];
    t('EUR: mena_doc_pole = EUR', $doc['doc_mena'] === 'EUR');
    $st = $pdo->prepare("SELECT mnozstvi, cena_bez_dph, sazba_dph FROM objednavky_polozky WHERE objednavka_id = ? ORDER BY id");
    $st->execute([$eurId]);
    $calc = mena_doklad_polozky(array_map(fn($p) => [(float) $p['mnozstvi'], (float) $p['cena_bez_dph'], (float) $p['sazba_dph']], $st->fetchAll(PDO::FETCH_ASSOC)), mena_doklad_ro($pdo, $eur));
    $sumaRadku = round(array_sum(array_map(fn($x) => $x['zaklad'] + $x['dph'], $calc['polozky'])), 2);
    t('EUR: Σ řádků (mena_doklad_polozky) = doc_celkem', abs($sumaRadku - (float) $doc['doc_celkem']) < 0.001, "$sumaRadku vs {$doc['doc_celkem']}");
    t('EUR: doc_celkem ≠ uložená Kč částka', abs((float) $doc['doc_celkem'] - (float) $eur['castka_celkem']) > 1);

    $ocek = [
        'cs' => ['predmet' => 'Potvrzení objednávky #',      'celkem' => 'Celkem s DPH:',    'pol' => 'POLOŽKY:'],
        'en' => ['predmet' => 'Order confirmation #',        'celkem' => 'Total incl. VAT:', 'pol' => 'ITEMS:'],
        'es' => ['predmet' => 'Confirmación del pedido n.º', 'celkem' => 'Total con IVA:',   'pol' => 'ARTÍCULOS:'],
    ];
    foreach ($ocek as $L => $e) {
        $r = notif_render_nova($pdo, $eurId, $L);
        $castka = _fmt_money_loc($doc['doc_celkem'], $L, 'EUR');
        t("EUR $L: jazyk šablony", $r['jazyk'] === $L);
        t("EUR $L: předmět", str_starts_with($r['predmet'], $e['predmet']), $r['predmet']);
        t("EUR $L: {castka_celkem} = doc_celkem ($castka)", $r['vars']['castka_celkem'] === $castka
            && preg_match('/' . preg_quote($e['celkem'], '/') . ' +' . preg_quote($castka, '/') . '\n/u', $r['telo']), $r['telo']);
        t("EUR $L: bez/DPH = doc_bez/doc_dph", $r['vars']['castka_bez_dph'] === _fmt_money_loc($doc['doc_bez'], $L, 'EUR')
            && $r['vars']['castka_dph'] === _fmt_money_loc($doc['doc_dph'], $L, 'EUR'));
        t("EUR $L: žádné Kč/CZK v těle", !preg_match('/Kč|CZK/u', $r['telo']), $r['telo']);
        t("EUR $L: popisek položek", str_contains($r['telo'], $e['pol']));
        preg_match_all('/= ([0-9., ]+) (?:€|EUR)\n/u', $r['vars']['polozky_text'], $mm);
        [$dec, $thou] = _num_seps($L);
        $suma = array_sum(array_map(fn($x) => (float) str_replace([$thou, $dec], ['', '.'], trim($x)), $mm[1]));
        t("EUR $L: Σ řádků v e-mailu = doc_celkem", count($mm[1]) > 0 && abs(round($suma, 2) - (float) $doc['doc_celkem']) < 0.001, $r['vars']['polozky_text']);
        $poz = ['cs' => 'Poznámka: ', 'en' => 'Note: ', 'es' => 'Nota: '][$L];
        t("EUR $L: poznámka přeložená", trim((string) $eur['poznamka']) === '' || str_contains($r['telo'], $poz . trim($eur['poznamka'])));
        foreach ($STAVY as $stv) {
            $rs = notif_render_stav($pdo, $eurId, $stv, $L);
            t("EUR $L $stv: jazyk + bez Kč", $rs['jazyk'] === $L && !preg_match('/Kč|CZK/u', $rs['telo']));
            if ($stv === 'expedovana') t("EUR $L expedovaná: částka", str_contains($rs['telo'], $castka), $rs['telo']);
        }
        $rs = notif_render_stav($pdo, $eurId, 'expedovana', $L);
        t("EUR $L push stav", $rs['push']['body'] === _t_lang($L, 'Stav: {stav} · Dodání: {datum}', ['stav' => email_stav_label('expedovana', $L), 'datum' => _fmt_date_loc($eur['datum_dodani'], $L)]));
        [$p, $b] = notif_zmena_objednavky_text($pdo, $eurId, $eur, ['castka' => (float) $eur['castka_celkem'], 'diff' => [], 'kdo' => 'Jan (administrace)'], $L);
        t("EUR $L úprava: obě částky = doc_celkem v EUR", substr_count($b, $castka) === 2 && !preg_match('/Kč|CZK/u', $b), $b);
        if ($L !== 'cs') t("EUR $L úprava: přeložená", !preg_match('/[ěščřžůĚŠČŘŽŮ]/u', $p . $b), $p . "\n" . $b);
    }
    $en = notif_render_nova($pdo, $eurId, 'en');
    t('EUR en push', $en['push']['title'] === '✓ Order received' && str_starts_with($en['push']['body'], 'Your order ' . $eur['cislo']));
} else {
    echo "⚠️  EUR objednávka #$eurId nenalezena — EUR část přeskočena.\n";
    $fail++;
}

// ── 4) Kč objednávka anglicky/španělsky: CZK, formáty jazyka ────────────────────────────
$czkId = (int) $pdo->query("SELECT id FROM objednavky WHERE mena = 'CZK' AND odberatel_id IN (SELECT id FROM odberatele) ORDER BY id DESC LIMIT 1")->fetchColumn();
if ($czkId) {
    $o = legacy_obj($pdo, $czkId);
    $r = notif_render_nova($pdo, $czkId, 'en');
    t('CZK en: částka "1,234.50 CZK"', $r['vars']['castka_celkem'] === number_format((float) $o['castka_celkem'], 2, '.', ',') . ' CZK');
    t('CZK en: datum "j M Y"', $r['vars']['datum'] === date('j M Y', strtotime($o['datum_dodani'])));
    t('CZK en: bez „Kč"', !str_contains($r['telo'], 'Kč'));
    $r = notif_render_nova($pdo, $czkId, 'es');
    t('CZK es: datum d/m/Y', $r['vars']['datum'] === date('d/m/Y', strtotime($o['datum_dodani'])));
}

// ── 5) Načítání: varianta z DB → výchozí v jazyce → čeština (v transakci, ROLLBACK) ──────
$pdo->beginTransaction();
try {
    $pdo->prepare("INSERT INTO email_templates (klic, predmet, telo, format, popis, aktivni) VALUES ('objednavka_nova@en', 'CUSTOM EN {cislo}', 'Body {castka_celkem}', 'text', 't', 1)
                   ON DUPLICATE KEY UPDATE predmet = VALUES(predmet), telo = VALUES(telo), aktivni = 1")->execute();
    $pdo->prepare("INSERT INTO email_templates (klic, predmet, telo, format, popis, aktivni) VALUES ('objednavka_dorucena@en', 'INACTIVE', 'x', 'text', 't', 0)
                   ON DUPLICATE KEY UPDATE predmet = VALUES(predmet), aktivni = 0")->execute();
    $tp = email_template_load($pdo, 'objednavka_nova', 'en');
    t('load en: vlastní varianta z DB', $tp['predmet'] === 'CUSTOM EN {cislo}' && $tp['jazyk'] === 'en');
    $tp = email_template_load($pdo, 'objednavka_nova', 'es');
    t('load es: výchozí španělská (bez řádku v DB)', $tp['predmet'] === email_template_defaults_lang('es')['objednavka_nova']['predmet'] || $vlastni > 0);
    $tp = email_template_load($pdo, 'objednavka_dorucena', 'en');
    t('load en: neaktivní varianta → výchozí anglická', $tp['predmet'] === email_template_defaults_lang('en')['objednavka_dorucena']['predmet']);
    $tp = email_template_load($pdo, 'objednavka_nova', 'cs');
    t('load cs: česká beze změny', $tp['jazyk'] === 'cs' && ($vlastni > 0 || $tp['predmet'] === email_template_defaults()['objednavka_nova']['predmet']));
    $tp = email_template_load($pdo, 'admin_nova_objednavka', 'en');
    t('load en: interní šablona zůstává česká', $tp['jazyk'] === 'cs');
    $tp = email_template_load($pdo, 'objednavka_nova', 'xx');
    t('load neznámý jazyk → čeština', $tp['jazyk'] === 'cs');
    if ($doc) {
        $r = notif_render_nova($pdo, $eurId, 'en');
        t('render en s vlastní variantou z DB', $r['predmet'] === 'CUSTOM EN ' . $eur['cislo'] && $r['telo'] === 'Body ' . _fmt_money_loc($doc['doc_celkem'], 'en', 'EUR'));
    }
} finally {
    $pdo->rollBack();
}
t('rollback: testovací varianty v DB nezůstaly', (int) $pdo->query("SELECT COUNT(*) FROM email_templates WHERE predmet IN ('CUSTOM EN {cislo}','INACTIVE')")->fetchColumn() === 0);

// ── 6) Jazyk odběratele = jazyk dokladů (bez ?lang z URL) ───────────────────────────────
$_GET['lang'] = 'es';
$odb = (int) $pdo->query("SELECT id FROM odberatele WHERE jazyk_dokladu IS NULL LIMIT 1")->fetchColumn();
t('email_lang_odberatele ignoruje ?lang z URL', email_lang_odberatele($pdo, $odb ?: null) === doc_lang_firma($pdo));
unset($_GET['lang']);
t('bez odběratele = výchozí jazyk firmy', email_lang_odberatele($pdo, null) === doc_lang_firma($pdo));
t('stav en/es', email_stav_label('expedovana', 'en') === 'DISPATCHED' && email_stav_label('ve_vyrobe', 'es') === 'en producción');
t('stav cs beze změny', email_stav_label('pripravena', 'cs') === 'připravena k expedici');
t('poznámka v render en', email_template_render('{poznamka_block}', ['poznamka' => 'X'], 'en') === "\nNote: X\n");
t('poznámka v render cs', email_template_render('{poznamka_block}', ['poznamka' => 'X']) === "\nPoznámka: X\n");

// ── 7) Voucher: cs beze změny, en/es přeložené ─────────────────────────────────────────
if (!function_exists('voucher_hodnota_text')) {
    // admin_vouchers.php je endpoint (require_admin) — funkci vytáhneme bez spuštění endpointu
    $src = file_get_contents(__DIR__ . '/../../../api/admin_vouchers.php');
    preg_match('/function voucher_hodnota_text\(.*?\n}\n/s', $src, $mm);
    eval($mm[0]);
}
t('voucher cs Kč', voucher_hodnota_text(['typ' => 'voucher', 'hodnota' => 1234.5]) === '1 234,50 Kč');
t('voucher cs %', voucher_hodnota_text(['typ' => 'sleva', 'sleva_pct' => 12.5, 'sleva_max_kc' => 1500]) === '12,5 % (max 1 500 Kč)');
t('voucher en', voucher_hodnota_text(['typ' => 'voucher', 'hodnota' => 1234.5], 'en', 'CZK') === '1,234.50 CZK');
t('voucher es %', voucher_hodnota_text(['typ' => 'sleva', 'sleva_pct' => 12.5, 'sleva_max_kc' => 1500], 'es', 'CZK') === '12,5 % (max 1.500 CZK)');
t('voucher en EUR', voucher_hodnota_text(['typ' => 'voucher', 'hodnota' => 20], 'en', 'EUR') === '20.00 EUR');

echo ($fail === 0 ? "✅" : "❌") . " test_emaily_jazyk: $ok OK, $fail chyb\n";
exit($fail === 0 ? 0 : 1);
