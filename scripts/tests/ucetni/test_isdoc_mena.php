<?php
/**
 * 🧪 ISDOC export/import dokladu v cizí měně (v3.0.642).
 *
 *   UCETNI_TEST_DB=appek_int_test [ISDOC_XSD_DIR=/cesta/k/isdoc-6.0.1-xsd] php scripts/tests/ucetni/test_isdoc_mena.php
 *
 * Ověřuje:
 *   - doklad v Kč: žádné *Curr elementy, CurrRate = RefCurrRate = 1, PayableAmount = hlavička faktury
 *   - doklad v EUR (faktura + dobropis): ForeignCurrencyCode, CurrRate = kurz, *Curr částky = PDF
 *     (nezávislý přepočet: cena/ks na centy, řádek = mn × cena, DPH po řádcích), Kč = EUR sazby × kurz,
 *     Σ řádků = rekapitulace, pravidla ISDOC schematronu pro cizí měnu, pořadí elementů dle XSD
 *   - import (parsuj_isdoc): snapshot měny z ForeignCurrencyCode/CurrRate, Kč částky zůstanou Kč,
 *     doklad s tuzemskou měnou ≠ CZK a nepodporovaná měna se odmítnou
 *   - volitelně xmllint --schema proti oficiálnímu XSD (ISDOC_XSD_DIR s isdoc-invoice-6.0.1.xsd)
 * Zakládá jen testovací doklady FA-TEST-ISDOC-EUR / DOB-TEST-ISDOC-EUR (idempotentně, jako _fixtures.php).
 */
require __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/_fixtures.php';
require_once $ROOT . '/api/_mena_lib.php';
if (!defined('ISDOC_INCLUDE')) define('ISDOC_INCLUDE', true);
if (!defined('ISDOC_IMPORT_INCLUDE')) define('ISDOC_IMPORT_INCLUDE', true);
require_once $ROOT . '/api/_admin_auth.php';
require_once $ROOT . '/api/admin_export_isdoc.php';
require_once $ROOT . '/api/admin_import_isdoc.php';

$pdo = db();
$xsdDir = getenv('ISDOC_XSD_DIR') ?: '';
$xsd = $xsdDir !== '' ? rtrim($xsdDir, '/') . '/isdoc-invoice-6.0.1.xsd' : '';
const NS = 'http://isdoc.cz/namespace/2013';
const KURZ = 25.25;

function isdoc_pro(PDO $pdo, int $id): string {
    $f = isdoc_nacti_fakturu($pdo, $id);
    if (!$f) throw new RuntimeException("faktura $id nenalezena");
    return generuj_isdoc($pdo, $f);
}
function dom_xp(string $xml): DOMXPath {
    $d = new DOMDocument();
    if (!$d->loadXML($xml, LIBXML_NONET)) throw new RuntimeException('neplatné XML');
    $xp = new DOMXPath($d);
    $xp->registerNamespace('i', NS);
    return $xp;
}
function num(DOMXPath $xp, string $q, ?DOMNode $ctx = null): float { return (float) $xp->evaluate("number($q)", $ctx); }
function eq(float $a, float $b): bool { return abs($a - $b) < 0.0001; }
function validuj_xsd(string $xml, string $xsd): array {
    $f = test_out_dir() . '/isdoc-' . md5($xml) . '.isdoc';
    file_put_contents($f, $xml);
    exec('xmllint --noout --nonet --schema ' . escapeshellarg($xsd) . ' ' . escapeshellarg($f) . ' 2>&1', $out, $rc);
    return [$rc === 0, implode("\n", $out)];
}
/** Pravidla schematronu ISDOC 6.0.1 (sch/isdoc-6.0.1.sch) pro měnu. */
const CURR_PARY = ['LineExtensionAmount', 'LineExtensionAmountTaxInclusive', 'TaxAmount', 'TaxableAmount', 'TaxInclusiveAmount',
    'AlreadyClaimedTaxableAmount', 'AlreadyClaimedTaxAmount', 'AlreadyClaimedTaxInclusiveAmount', 'DifferenceTaxableAmount',
    'DifferenceTaxAmount', 'DifferenceTaxInclusiveAmount', 'TaxExclusiveAmount', 'AlreadyClaimedTaxExclusiveAmount',
    'DifferenceTaxExclusiveAmount', 'PayableRoundingAmount', 'PaidDepositsAmount', 'PayableAmount'];
function schematron_mena(DOMXPath $xp): array {
    $chyby = [];
    $cizi = $xp->evaluate('count(/i:Invoice/i:ForeignCurrencyCode)') > 0;
    if ($cizi) {
        foreach (CURR_PARY as $el) {
            foreach ($xp->query("//*[i:$el]") as $parent) {
                if ($xp->query("i:{$el}Curr", $parent)->length === 0) $chyby[] = "{$parent->localName}/$el bez {$el}Curr";
            }
        }
        if ($xp->evaluate('string(/i:Invoice/i:ForeignCurrencyCode)') === $xp->evaluate('string(/i:Invoice/i:LocalCurrencyCode)')) $chyby[] = 'Foreign = Local';
    } else {
        if (num($xp, '/i:Invoice/i:CurrRate') != 1 || num($xp, '/i:Invoice/i:RefCurrRate') != 1) $chyby[] = 'kurz ≠ 1 u dokladu v Kč';
        if ($xp->query("//*[substring(local-name(), string-length(local-name()) - 3) = 'Curr']")->length) $chyby[] = '*Curr element u dokladu v Kč';
    }
    return $chyby;
}
/** Nezávislý přepočet „nativního" dokladu v cizí měně (jako PDF faktury) z řádků faktura_polozky. */
function reference_eur(PDO $pdo, int $id, float $kurz): array {
    $rows = $pdo->query("SELECT mnozstvi, cena_bez_dph, sazba_dph FROM faktura_polozky WHERE faktura_id = $id ORDER BY poradi, id")->fetchAll(PDO::FETCH_ASSOC);
    $sazby = []; $radky = [];
    foreach ($rows as $r) {
        $cena = round($r['cena_bez_dph'] / $kurz, 2);
        $z = round($r['mnozstvi'] * $cena, 2);
        $d = round($z * $r['sazba_dph'] / 100, 2);
        $radky[] = [$z, $d];
        $s = (string) (0 + (float) $r['sazba_dph']);
        $sazby[$s]['bez'] = round(($sazby[$s]['bez'] ?? 0) + $z, 2);
        $sazby[$s]['dph'] = round(($sazby[$s]['dph'] ?? 0) + $d, 2);
    }
    $bez = round(array_sum(array_column($sazby, 'bez')), 2);
    $dph = round(array_sum(array_column($sazby, 'dph')), 2);
    $czk = [];
    foreach ($sazby as $s => $x) $czk[$s] = ['bez' => round($x['bez'] * $kurz, 2), 'dph' => round($x['dph'] * $kurz, 2)];
    return ['radky' => $radky, 'sazby' => $sazby, 'czk' => $czk, 'bez' => $bez, 'dph' => $dph, 'celkem' => round($bez + $dph, 2),
            'czk_celkem' => round(array_sum(array_column($czk, 'bez')) + array_sum(array_column($czk, 'dph')), 2)];
}

// ── fixtures
$fx = fixture_testovaci_doklady($pdo);
$pdo->prepare("UPDATE faktury SET mena = 'CZK', mena_kurz = 1, mena_zaklad = 'CZK' WHERE id IN (?, ?) AND mena IS NULL")
    ->execute([$fx['faktura_id'], $fx['dobropis_id']]);
$eurPol = [
    ['Chléb kváskový', 10, 'ks', 45.50, 12],
    ['Dort čokoládový „Sacher" & <speciál>', 2.5, 'kg', 389.00, 12],
    ['Doprava', 1, 'ks', 250.00, 21],
    ['Záloha přepravka', 3, 'ks', 33.33, 0],
];
$faEur  = fixture_faktura($pdo, 'FA-TEST-ISDOC-EUR', $fx['odberatel_id'], false, null, $eurPol, 'APPEK TEST ISDOC EUR');
$dobEur = fixture_faktura($pdo, 'DOB-TEST-ISDOC-EUR', $fx['odberatel_id'], true, $faEur,
    array_map(fn($p) => [$p[0], $p[1], $p[2], -$p[3], $p[4]], $eurPol), 'APPEK TEST ISDOC EUR dobropis');
$pdo->prepare("UPDATE faktury SET mena = 'EUR', mena_kurz = ?, mena_zaklad = 'CZK' WHERE id IN (?, ?)")->execute([KURZ, $faEur, $dobEur]);

// ── 1) doklad v Kč
echo "── ISDOC doklad v Kč\n";
foreach ([$fx['faktura_id'] => 'faktura', $fx['dobropis_id'] => 'dobropis'] as $id => $co) {
    $xml = isdoc_pro($pdo, $id);
    $xp = dom_xp($xml);
    $h = $pdo->query("SELECT castka_bez_dph, castka_dph, castka_celkem FROM faktury WHERE id = $id")->fetch(PDO::FETCH_ASSOC);
    ok(!str_contains($xml, 'ForeignCurrencyCode') && !str_contains($xml, 'Curr>'), "$co v Kč: bez ForeignCurrencyCode a *Curr");
    ok(str_contains($xml, "<CurrRate>1</CurrRate>\n  <RefCurrRate>1</RefCurrRate>"), "$co v Kč: CurrRate = RefCurrRate = 1");
    ok(eq(num($xp, '/i:Invoice/i:LegalMonetaryTotal/i:PayableAmount'), (float) $h['castka_celkem']), "$co v Kč: PayableAmount = hlavička ({$h['castka_celkem']})");
    $e = schematron_mena($xp);
    ok(!$e, "$co v Kč: pravidla schematronu pro měnu" . ($e ? ' — ' . implode('; ', $e) : ''));
    if ($xsd !== '') { [$v, $m] = validuj_xsd($xml, $xsd); ok($v, "$co v Kč: validní proti XSD" . ($v ? '' : "\n$m")); }
}

// ── 2) doklad v EUR
echo "── ISDOC doklad v EUR\n";
$xmls = [];
foreach ([$faEur => 'faktura', $dobEur => 'dobropis'] as $id => $co) {
    $xml = $xmls[$id] = isdoc_pro($pdo, $id);
    $xp = dom_xp($xml);
    $ref = reference_eur($pdo, $id, KURZ);
    $L = '/i:Invoice/i:LegalMonetaryTotal/i:';
    ok($xp->evaluate('string(/i:Invoice/i:DocumentType)') === ($co === 'dobropis' ? '2' : '1'), "$co: DocumentType " . ($co === 'dobropis' ? '2' : '1'));
    ok($xp->evaluate('string(/i:Invoice/i:LocalCurrencyCode)') === 'CZK' && $xp->evaluate('string(/i:Invoice/i:ForeignCurrencyCode)') === 'EUR',
       "$co: LocalCurrencyCode CZK + ForeignCurrencyCode EUR");
    ok(eq(num($xp, '/i:Invoice/i:CurrRate'), KURZ) && eq(num($xp, '/i:Invoice/i:RefCurrRate'), 1), "$co: CurrRate 25.25, RefCurrRate 1");
    ok(str_contains($xml, "<LocalCurrencyCode>CZK</LocalCurrencyCode>\n  <ForeignCurrencyCode>EUR</ForeignCurrencyCode>\n  <CurrRate>25.25</CurrRate>\n  <RefCurrRate>1</RefCurrRate>"),
       "$co: pořadí hlavičky Local → Foreign → CurrRate → RefCurrRate");
    ok(eq(num($xp, $L . 'PayableAmountCurr'), $ref['celkem']), sprintf('%s: PayableAmountCurr %.2f = PDF „K úhradě" %.2f EUR', $co, num($xp, $L . 'PayableAmountCurr'), $ref['celkem']));
    ok(eq(num($xp, $L . 'TaxExclusiveAmountCurr'), $ref['bez']) && eq(num($xp, '/i:Invoice/i:TaxTotal/i:TaxAmountCurr'), $ref['dph']), "$co: základ a DPH v EUR = PDF");
    ok(eq(num($xp, $L . 'PayableAmount'), $ref['czk_celkem']), sprintf('%s: PayableAmount %.2f Kč = Σ sazeb v EUR × kurz', $co, num($xp, $L . 'PayableAmount')));
    ok(eq(num($xp, '/i:Invoice/i:PaymentMeans/i:Payment/i:PaidAmount'), $ref['czk_celkem']) || $xp->query('/i:Invoice/i:PaymentMeans')->length === 0,
       "$co: PaidAmount (tuzemská měna) = PayableAmount");
    // rekapitulace po sazbách: Curr = PDF, Kč = Curr × kurz (= „Rekapitulace DPH v Kč" na PDF)
    $rekOk = true; $n = 0;
    foreach ($xp->query('/i:Invoice/i:TaxTotal/i:TaxSubTotal') as $ts) {
        $s = (string) (0 + num($xp, 'i:TaxCategory/i:Percent', $ts));
        $n++;
        $r = $ref['sazby'][$s] ?? null; $c = $ref['czk'][$s] ?? null;
        if (!$r || !eq(num($xp, 'i:TaxableAmountCurr', $ts), $r['bez']) || !eq(num($xp, 'i:TaxAmountCurr', $ts), $r['dph'])
            || !eq(num($xp, 'i:TaxableAmount', $ts), $c['bez']) || !eq(num($xp, 'i:TaxAmount', $ts), $c['dph'])) $rekOk = false;
    }
    ok($rekOk && $n === count($ref['sazby']), "$co: rekapitulace DPH ($n sazby) v EUR = PDF, v Kč = EUR × kurz");
    // řádky: EUR = PDF po řádcích, Σ Kč řádků po sazbě = rekapitulace v Kč
    $radkyOk = true; $sumKc = [];
    foreach ($xp->query('/i:Invoice/i:InvoiceLines/i:InvoiceLine') as $ix => $ln) {
        [$z, $d] = $ref['radky'][$ix] ?? [null, null];
        if ($z === null || !eq(num($xp, 'i:LineExtensionAmountCurr', $ln), $z) || !eq(num($xp, 'i:LineExtensionAmountTaxInclusiveCurr', $ln), round($z + $d, 2))) $radkyOk = false;
        $s = (string) (0 + num($xp, 'i:ClassifiedTaxCategory/i:Percent', $ln));
        $sumKc[$s]['bez'] = ($sumKc[$s]['bez'] ?? 0) + num($xp, 'i:LineExtensionAmount', $ln);
        $sumKc[$s]['dph'] = ($sumKc[$s]['dph'] ?? 0) + num($xp, 'i:LineExtensionTaxAmount', $ln);
        if (!eq(num($xp, 'i:LineExtensionAmountTaxInclusive', $ln), round(num($xp, 'i:LineExtensionAmount', $ln) + num($xp, 'i:LineExtensionTaxAmount', $ln), 2))) $radkyOk = false;
        // pořadí v InvoiceLineType: …Curr PŘED tuzemskou částkou
        $names = []; foreach ($ln->childNodes as $ch) if ($ch instanceof DOMElement) $names[] = $ch->localName;
        if (array_search('LineExtensionAmountCurr', $names) !== array_search('LineExtensionAmount', $names) - 1) $radkyOk = false;
    }
    ok($radkyOk && count($ref['radky']) === $xp->query('//i:InvoiceLine')->length, "$co: řádky v EUR = PDF (cena/ks na centy, DPH po řádcích)");
    $sumOk = true;
    foreach ($ref['czk'] as $s => $c) if (!eq(round($sumKc[$s]['bez'] ?? 0, 2), $c['bez']) || !eq(round($sumKc[$s]['dph'] ?? 0, 2), $c['dph'])) $sumOk = false;
    ok($sumOk, "$co: Σ řádků v Kč po sazbách = rekapitulace v Kč");
    // pořadí v LegalMonetaryTotal: tuzemská částka, hned za ní …Curr
    $names = []; foreach ($xp->query('/i:Invoice/i:LegalMonetaryTotal/*') as $ch) $names[] = $ch->localName;
    ok(array_slice($names, 0, 2) === ['TaxExclusiveAmount', 'TaxExclusiveAmountCurr'] && end($names) === 'PayableAmountCurr', "$co: pořadí LegalMonetaryTotal dle XSD");
    $e = schematron_mena($xp);
    ok(!$e, "$co: pravidla schematronu (všechny částky i v cizí měně)" . ($e ? ' — ' . implode('; ', $e) : ''));
    if ($xsd !== '') { [$v, $m] = validuj_xsd($xml, $xsd); ok($v, "$co v EUR: validní proti XSD" . ($v ? '' : "\n$m")); }
}
$a = dom_xp($xmls[$faEur]); $b = dom_xp($xmls[$dobEur]);
ok(eq(num($a, '//i:PayableAmountCurr'), -num($b, '//i:PayableAmountCurr')) && eq(num($a, '//i:PayableAmount'), -num($b, '//i:PayableAmount')),
   'dobropis v EUR i Kč = −faktura');
// shoda s exportem do účetnictví (POHODA/FlexiBee) v cizí měně
$inv = ucetni_nacti_fakturu($pdo, $faEur);
ok(eq(num($a, '//i:PayableAmountCurr'), $inv['cizi']['celkem']) && eq(num($a, '//i:LegalMonetaryTotal/i:TaxExclusiveAmount'), $inv['celkem_bez']),
   'EUR celkem i Kč základ = export do účetnictví (ucetni_nacti_fakturu)');

// ── 3) import ISDOC
echo "── ISDOC import\n";
$p = parsuj_isdoc($xmls[$faEur]);
ok(empty($p['error']) && $p['mena']['mena'] === 'EUR' && eq($p['mena']['mena_kurz'], KURZ) && $p['mena']['mena_zaklad'] === 'CZK',
   'import EUR dokladu: snapshot EUR / 25.25 / částky v Kč');
ok(eq($p['castka_celkem'], num($a, '//i:LegalMonetaryTotal/i:PayableAmount')) && eq((float) $p['castka_celkem_mena'], num($a, '//i:PayableAmountCurr')),
   "import: castka_celkem = Kč ({$p['castka_celkem']}), v EUR {$p['castka_celkem_mena']} — Kč se neberou jako EUR");
// zpětný přepočet importovaného dokladu do EUR (jak ho ukáže PDF) = původní doklad
$m = mena_doklad($pdo, 'faktury', ['mena' => 'EUR', 'mena_kurz' => KURZ, 'mena_zaklad' => 'CZK']);
$calc = mena_doklad_polozky(array_map(fn($x) => [$x['mnozstvi'], $x['cena_bez_dph'], $x['sazba_dph']], $p['polozky']), $m);
ok(eq($calc['celkem'], num($a, '//i:PayableAmountCurr')), "import → PDF v EUR ({$calc['celkem']}) = původní doklad");
$p = parsuj_isdoc(isdoc_pro($pdo, $fx['faktura_id']));
ok(empty($p['error']) && $p['mena']['mena'] === 'CZK' && $p['castka_celkem_mena'] === null, 'import dokladu v Kč: snapshot CZK');
$p = parsuj_isdoc(str_replace('<LocalCurrencyCode>CZK</LocalCurrencyCode>', '<LocalCurrencyCode>EUR</LocalCurrencyCode>', isdoc_pro($pdo, $fx['faktura_id'])));
ok(!empty($p['error']) && str_contains($p['error'], 'EUR'), 'import: tuzemská měna ≠ CZK se odmítne (žádné EUR jako Kč)');
$p = parsuj_isdoc(str_replace('<ForeignCurrencyCode>EUR</ForeignCurrencyCode>', '<ForeignCurrencyCode>JPY</ForeignCurrencyCode>', $xmls[$faEur]));
ok(!empty($p['error']) && str_contains($p['error'], 'JPY'), 'import: nepodporovaná cizí měna se odmítne');
$p = parsuj_isdoc(str_replace('<CurrRate>25.25</CurrRate>', '<CurrRate>0</CurrRate>', $xmls[$faEur]));
ok(!empty($p['error']), 'import: cizí měna bez platného kurzu se odmítne');
$p = parsuj_isdoc(str_replace(['<CurrRate>25.25</CurrRate>', '<RefCurrRate>1</RefCurrRate>'], ['<CurrRate>2525</CurrRate>', '<RefCurrRate>100</RefCurrRate>'], $xmls[$faEur]));
ok(empty($p['error']) && eq($p['mena']['mena_kurz'], KURZ), 'import: kurz = CurrRate / RefCurrRate');

if ($xsd === '') echo "  (XSD validace přeskočena — nastav ISDOC_XSD_DIR)\n";
konec();
