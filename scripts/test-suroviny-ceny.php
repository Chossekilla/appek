<?php
/**
 * Test cen surovin (api/_suroviny_ceny_lib.php) — bez DB.
 * Spuštění: php scripts/test-suroviny-ceny.php   (exit 0 = vše OK)
 */
require_once __DIR__ . '/../api/_bom_lib.php';
require_once __DIR__ . '/../api/_suroviny_ceny_lib.php';

$ok = 0; $fail = 0;
function t(string $nazev, bool $podminka, string $info = ''): void {
    global $ok, $fail;
    if ($podminka) { $ok++; } else { $fail++; echo "❌ $nazev" . ($info !== '' ? " — $info" : '') . "\n"; }
}
$hit = fn(string $n) => suroviny_najdi_cenu($n);
$lbl = fn(string $n) => ($h = suroviny_najdi_cenu($n)) ? $h['label'] : null;

// ── rozpoznání (dřívější falešné shody)
t('Arašídy solené ≠ lněná semínka', $hit('Arašídy solené') === null, (string) $lbl('Arašídy solené'));
t('Olej slunečnicový = olej (l)', ($h = $hit('Olej slunečnicový')) && $h['label'] === 'Rostlinný olej' && $h['jed'] === 'l', (string) $lbl('Olej slunečnicový'));
t('Olej řepkový = l', ($h = $hit('Olej řepkový')) && $h['jed'] === 'l');
t('Mouka rýžová bez návrhu', $hit('Mouka rýžová') === null, (string) $lbl('Mouka rýžová'));
t('Mouka mandlová bez návrhu', $hit('Mouka mandlová') === null, (string) $lbl('Mouka mandlová'));
t('Mouka kukuřičná bez návrhu', $hit('Mouka kukuřičná') === null);
t('Mouka chlebová T1050 = pšeničná chlebová', $lbl('Mouka chlebová T1050') === 'Mouka pšeničná chlebová', (string) $lbl('Mouka chlebová T1050'));
t('Mouka žitná chlebová T960 = žitná', str_starts_with((string) $lbl('Mouka žitná chlebová T960'), 'Mouka žitná'), (string) $lbl('Mouka žitná chlebová T960'));
t('Mouka špaldová = 35', ($h = $hit('Mouka špaldová')) && $h['cena'] == 35.0);
t('Kvasnice ≠ kvas', $lbl('Kvasnice') !== 'Kvas');
t('Minerální voda bez návrhu', $hit('Minerální voda') === null, (string) $lbl('Minerální voda'));
t('Pikantní salám bez návrhu', $hit('Pikantní salám') === null, (string) $lbl('Pikantní salám'));
t('Mléko bez laktózy ≠ laktóza', $lbl('Mléko bez laktózy') !== 'Laktóza');
t('Máslo selské 82% = 270', ($h = $hit('Máslo selské 82%')) && $h['cena'] == 270.0, (string) $lbl('Máslo selské 82%'));
t('Vaječná směs ≠ pekařská směs', $lbl('Vaječná směs') !== 'Pekařská směs');
t('Vejce = 6/ks', ($h = $hit('Vejce')) && $h['cena'] == 6.0 && $h['jed'] === 'ks');
t('Mouka Pšeničná Hladká (title case) = T530', $lbl('Mouka Pšeničná Hladká') === 'Mouka pšeničná hladká T530');

// ── převod do jednotky suroviny
$mouka = $hit('Mouka pšeničná hladká');
$n = suroviny_cena_navrh(['jednotka' => 'g', 'cena_baleni' => null, 'obsah_baleni' => null], $mouka);
t('g bez ceny → 1000 g za 14 Kč', $n['stav'] === 'doplnit' && $n['obsah_baleni'] == 1000 && $n['cena_baleni'] == 14.0, json_encode($n));
$n = suroviny_cena_navrh(['jednotka' => 'g', 'cena_baleni' => 0, 'obsah_baleni' => 25000], $mouka);
t('g, balení 25 kg → 350 Kč / 25000 g', $n['obsah_baleni'] == 25000 && $n['cena_baleni'] == 350.0, json_encode($n));
$n = suroviny_cena_navrh(['jednotka' => 'kg', 'cena_baleni' => null, 'obsah_baleni' => null], $mouka);
t('kg → 1 kg za 14 Kč', $n['obsah_baleni'] == 1 && $n['cena_baleni'] == 14.0);
$n = suroviny_cena_navrh(['jednotka' => 'ml', 'cena_baleni' => null, 'obsah_baleni' => null], $hit('Olej slunečnicový'));
t('ml olej → 1000 ml', $n['obsah_baleni'] == 1000 && $n['cena_baleni'] == 55.0);
$n = suroviny_cena_navrh(['jednotka' => 'lžíce', 'cena_baleni' => null, 'obsah_baleni' => null], $mouka);
t('lžíce → nekompatibilni', $n['stav'] === 'nekompatibilni');
$n = suroviny_cena_navrh(['jednotka' => 'g', 'cena_baleni' => null, 'obsah_baleni' => null], $hit('Vejce'));
t('Vejce v g → nekompatibilni', $n['stav'] === 'nekompatibilni');
$n = suroviny_cena_navrh(['jednotka' => 'g', 'cena_baleni' => 0, 'obsah_baleni' => 1], $mouka);
t('g, balení 1 g → fallback 1000 g', $n['obsah_baleni'] == 1000 && $n['cena_baleni'] == 14.0, json_encode($n));
$n = suroviny_cena_navrh(['jednotka' => 'g', 'cena_baleni' => 14, 'obsah_baleni' => 1000], $mouka);
t('stejná cena → beze_zmeny', $n['stav'] === 'beze_zmeny', json_encode($n));
$n = suroviny_cena_navrh(['jednotka' => 'g', 'cena_baleni' => 400, 'obsah_baleni' => 25000], $mouka);
t('aktuální i návrh za kg (16 vs 14)', $n['stav'] === 'ma_cenu' && abs($n['cena_za_jed_akt'] - 16.0) < 1e-6 && $n['cena_za_jed_navrh'] == 14.0, json_encode($n));

// ── jednotky + názvy + seed
t('strict g→kg = 0.001', abs(bom_unit_factor_strict('g', 'kg') - 0.001) < 1e-12);
t('strict g→l = null', bom_unit_factor_strict('g', 'l') === null);
t('bom_unit_factor g→l zůstává 1.0', bom_unit_factor('g', 'l') === 1.0);
t('bom_unit_factor prázdná = 1.0', bom_unit_factor('', 'kg') === 1.0);
t('normalizace názvu', suroviny_normalizuj_nazev('Mouka Pšeničná  Hladká') === suroviny_normalizuj_nazev('mouka psenicna hladka'));
$seed = suroviny_vychozi_seznam();
$norm = array_map(fn($r) => suroviny_normalizuj_nazev($r[0]), $seed);
t('seed: 70 položek', count($seed) === 70, (string) count($seed));
t('seed: unikátní názvy', count(array_unique($norm)) === count($norm));
t('seed: jednotky g/ml/ks', !array_filter($seed, fn($r) => !in_array($r[1], ['g', 'ml', 'ks'], true)));

echo "suroviny_ceny: $ok OK, $fail chyb\n";
exit($fail ? 1 : 0);
