<?php
/**
 * 🔒 _gdpr_lib.php (v3.0.425) — sdílené helpery GDPR modulu.
 * Používá admin_gdpr.php (správa) i gdpr_verejne.php (veřejné čtení).
 */

if (!function_exists('gdpr_firma')) {
    /** Načte firemní údaje (firma_*) pro předvyplnění šablony. */
    function gdpr_firma(PDO $pdo): array {
        $out = [];
        try {
            $rows = $pdo->query("SELECT klic, hodnota FROM nastaveni WHERE klic LIKE 'firma_%'")
                        ->fetchAll(PDO::FETCH_KEY_PAIR);
            foreach ($rows as $k => $v) $out[$k] = trim((string) $v);
        } catch (Throwable $e) { /* ignore */ }
        return $out;
    }
}

if (!function_exists('gdpr_default_template')) {
    /** Vygeneruje obecnou šablonu zásad zpracování osobních údajů (HTML), předvyplněnou firmou. */
    function gdpr_default_template(array $f): string {
        $nazev = ($f['firma_nazev']   ?? '') !== '' ? $f['firma_nazev']   : '[NÁZEV FIRMY]';
        $ico   = ($f['firma_ico']     ?? '') !== '' ? $f['firma_ico']     : '[IČO]';
        $dic   = $f['firma_dic']      ?? '';
        $ul    = $f['firma_ulice']    ?? '';
        $mesto = $f['firma_mesto']    ?? '';
        $psc   = $f['firma_psc']      ?? '';
        $email = ($f['firma_email']   ?? '') !== '' ? $f['firma_email']   : '[E-MAIL]';
        $tel   = $f['firma_telefon']  ?? '';
        $adresa = trim($ul . ', ' . trim($psc . ' ' . $mesto), ', ');
        if ($adresa === '' || $adresa === ',') $adresa = '[ADRESA SÍDLA]';

        $e = fn($s) => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
        $dicRadek = $dic !== '' ? "<li><strong>DIČ:</strong> " . $e($dic) . "</li>" : '';
        $telRadek = $tel !== '' ? "<li><strong>Telefon:</strong> " . $e($tel) . "</li>" : '';

        return <<<HTML
<h2>Zásady zpracování osobních údajů</h2>
<p>Tyto zásady popisují, jak správce {$e($nazev)} zpracovává osobní údaje v souladu s Nařízením
Evropského parlamentu a Rady (EU) 2016/679 (GDPR) a zákonem č. 110/2019 Sb., o zpracování osobních údajů.</p>

<h3>1. Správce osobních údajů</h3>
<ul>
  <li><strong>Firma:</strong> {$e($nazev)}</li>
  <li><strong>IČO:</strong> {$e($ico)}</li>
  {$dicRadek}
  <li><strong>Sídlo:</strong> {$e($adresa)}</li>
  <li><strong>E-mail:</strong> {$e($email)}</li>
  {$telRadek}
</ul>

<h3>2. Jaké údaje zpracováváme</h3>
<ul>
  <li>Identifikační a kontaktní údaje (jméno / název, IČO, DIČ, adresa, e-mail, telefon, kontaktní osoba).</li>
  <li>Fakturační a objednávkové údaje (obsah objednávek, dodací a fakturační adresy, historie plateb).</li>
  <li>Přihlašovací údaje k zákaznickému (B2B) portálu, pokud jej využíváte.</li>
</ul>

<h3>3. Účel a právní základ zpracování</h3>
<ul>
  <li><strong>Plnění smlouvy</strong> (čl. 6 odst. 1 písm. b) GDPR) — vyřízení objednávek, dodání, fakturace.</li>
  <li><strong>Plnění právní povinnosti</strong> (písm. c) — účetní a daňové předpisy.</li>
  <li><strong>Oprávněný zájem</strong> (písm. f) — ochrana práv správce, vedení evidence, zabezpečení.</li>
  <li><strong>Souhlas</strong> (písm. a) — tam, kde je vyžadován (např. zasílání obchodních sdělení, analytické cookies).</li>
</ul>

<h3>4. Doba uchování</h3>
<p>Účetní a daňové doklady uchováváme po dobu stanovenou zákonem (zpravidla 10 let dle zákona o DPH a o účetnictví).
Ostatní osobní údaje zpracováváme po dobu trvání smluvního vztahu a přiměřenou dobu poté.</p>

<h3>5. Příjemci a zpracovatelé</h3>
<p>Osobní údaje mohou být předány zpracovatelům, kteří pro správce zajišťují služby: přepravci, poskytovatelé
platebních bran, účetní kancelář, poskytovatel hostingu a IT podpory. Všichni jsou vázáni povinností mlčenlivosti
a zpracovávají údaje pouze podle pokynů správce.</p>

<h3>6. Vaše práva</h3>
<p>Máte právo na přístup k údajům, jejich opravu, výmaz („právo být zapomenut"), omezení zpracování,
přenositelnost, vznést námitku a kdykoli odvolat udělený souhlas. Rovněž máte právo podat stížnost
u dozorového úřadu — <strong>Úřad pro ochranu osobních údajů</strong> (www.uoou.cz).</p>

<h3>7. Cookies</h3>
<p>Zásady používání cookies jsou dostupné samostatně; analytické cookies vkládáme až po vašem souhlasu.</p>

<h3>8. Uplatnění práv a kontakt</h3>
<p>Pro uplatnění svých práv nebo s jakýmkoli dotazem ke zpracování osobních údajů nás kontaktujte
na e-mailu <strong>{$e($email)}</strong>.</p>

<p><em>Tento dokument je obecná šablona — před zveřejněním jej zkontrolujte a upravte podle skutečného
rozsahu zpracování ve vaší firmě. V případě pochybností se poraďte s právníkem.</em></p>
HTML;
    }
}
