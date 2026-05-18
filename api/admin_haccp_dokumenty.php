<?php
/**
 * Firemní HACCP dokumentace — strukturované dokumenty firmy
 * (Plán HACCP, Sanitační řád, Vstupní instruktáž, Postupy CCP, Formuláře, Školení)
 *
 *   GET    ?kategorie=...    → seznam dokumentů v kategorii (nebo vše)
 *   GET    ?id=X             → detail dokumentu
 *   POST                     → vytvořit
 *   PUT    ?id=X             → upravit
 *   DELETE ?id=X             → smazat
 *   POST   ?action=import_default → naimportuje výchozí sadu z firemní dokumentace
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/_admin_auth.php';
cors_headers();
require_admin();

$pdo = db();
$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? '';

// =============================================================
// AUTO-MIGRACE
// =============================================================
$pdo->exec("
    CREATE TABLE IF NOT EXISTS haccp_dokumenty (
        id INT AUTO_INCREMENT PRIMARY KEY,
        kategorie VARCHAR(40) NOT NULL,
        nazev VARCHAR(200) NOT NULL,
        poradi INT DEFAULT 0,
        obsah LONGTEXT,
        aktivni TINYINT(1) DEFAULT 1,
        vytvoreno DATETIME DEFAULT CURRENT_TIMESTAMP,
        upraveno DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        INDEX idx_kategorie (kategorie, poradi)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
");

// Tabulka pro evidenci interních auditů (každý rok jeden záznam)
$pdo->exec("
    CREATE TABLE IF NOT EXISTS haccp_audity (
        id INT AUTO_INCREMENT PRIMARY KEY,
        rok SMALLINT NOT NULL,
        datum DATE,
        auditor VARCHAR(200),
        vysledek VARCHAR(40) DEFAULT 'v_poradku',
        napravna_opatreni TEXT,
        poznamka TEXT,
        vytvoreno DATETIME DEFAULT CURRENT_TIMESTAMP,
        vytvoril VARCHAR(100),
        UNIQUE KEY uniq_rok (rok),
        INDEX idx_rok (rok)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
");
// Audity — žádný seed, uživatel si přidá vlastní záznamy ručně podle skutečného provedení auditu.

// =============================================================
// AUDITY — pomocná funkce která vyrenderuje aktuální tabulku z DB
// a vloží ji do obsahu dokumentu místo statické tabulky.
// =============================================================
function render_audity_tabulka(PDO $pdo): string {
    $rows = $pdo->query("
        SELECT rok, datum, auditor, vysledek, napravna_opatreni
        FROM haccp_audity
        ORDER BY rok DESC
    ")->fetchAll();

    $vysledkyMap = [
        'v_poradku'  => '✓ V pořádku',
        's_pripominkami' => '⚠ S připomínkami',
        'nevyhovuje' => '✗ Nevyhovuje',
    ];

    $html  = '<table border="1" cellpadding="6" style="border-collapse:collapse;width:100%">';
    $html .= '<tr style="background:#f5f5f0"><th>Rok</th><th>Datum auditu</th><th>Auditor</th><th>Výsledek</th><th>Nápravná opatření</th></tr>';
    if (empty($rows)) {
        $html .= '<tr><td colspan="5" style="text-align:center;color:#888">Zatím nebyl proveden žádný audit — přidej první v sekci „Audity" v administraci.</td></tr>';
    } else {
        foreach ($rows as $r) {
            $datum = $r['datum'] ? date('j. n. Y', strtotime($r['datum'])) : '—';
            $vysl  = $vysledkyMap[$r['vysledek']] ?? htmlspecialchars((string) $r['vysledek']);
            $html .= '<tr>';
            $html .= '<td><strong>' . (int) $r['rok'] . '</strong></td>';
            $html .= '<td>' . htmlspecialchars($datum) . '</td>';
            $html .= '<td>' . htmlspecialchars((string) ($r['auditor'] ?? '')) . '</td>';
            $html .= '<td>' . $vysl . '</td>';
            $html .= '<td>' . htmlspecialchars((string) ($r['napravna_opatreni'] ?? '')) . '</td>';
            $html .= '</tr>';
        }
    }
    $html .= '</table>';
    return $html;
}

/**
 * Najde v obsahu dokumentu sekci "Záznamy o auditech" + tabulku
 * a nahradí ji aktuálními daty z DB. Pokud sekce není, vrátí původní obsah.
 */
function render_audity_v_dokumentu(PDO $pdo, string $obsah): string {
    $novaTabulka = render_audity_tabulka($pdo);
    // Match: <h4>Záznamy o auditech</h4>...<table>...</table>
    $pattern = '#(<h4>\s*Záznamy o auditech\s*</h4>)\s*<table[^>]*>.*?</table>#is';
    if (preg_match($pattern, $obsah)) {
        return preg_replace($pattern, '$1' . "\n" . $novaTabulka, $obsah);
    }
    // Fallback — pokud nadpis chybí, jen přidá tabulku na konec
    return $obsah . "\n<h4>Záznamy o auditech</h4>\n" . $novaTabulka;
}

// =============================================================
// KATEGORIE — seznam (pro frontend)
// =============================================================
function kategorie_list(): array {
    return [
        ['key' => 'plan_haccp',         'label' => '📋 Plán systému kritických bodů', 'ikona' => '📋'],
        ['key' => 'sanitacni_rad',      'label' => '🧹 Sanitační řád',                'ikona' => '🧹'],
        ['key' => 'instruktaz_hygieny', 'label' => '👤 Vstupní instruktáž — osobní hygiena', 'ikona' => '👤'],
        ['key' => 'postupy_ccp',        'label' => '⚠️ Postupy CCP',                   'ikona' => '⚠️'],
        ['key' => 'formulare',          'label' => '📝 Formuláře',                     'ikona' => '📝'],
        ['key' => 'skoleni',            'label' => '🎓 Záznamy o školení',             'ikona' => '🎓'],
    ];
}

// =============================================================
// VÝCHOZÍ OBSAH — aktualizovaná verze 2025
// dle: 178/2002 (obecné principy potr. práva), 852/2004 (hygiena),
//      2073/2005 (mikrobiologie), 1169/2011 (informace o potravinách),
//      zák. 110/1997 Sb. o potravinách, Vyhl. 18/2024 Sb. (hygiena potravin)
// =============================================================
function default_dokumenty(): array {
    $firma_hlavicka = "[Vaše firma s.r.o.]\n\n\n";

    return [
        // ============== PLÁN HACCP ==============
        [
            'kategorie' => 'plan_haccp',
            'nazev' => '1. Vymezení činnosti a definice',
            'poradi' => 1,
            'obsah' => "<h2>Plán systému kritických bodů (HACCP)</h2>\n\n<h3>1.1 Vymezení činnosti</h3>\n<table border=\"1\" cellpadding=\"6\" style=\"border-collapse:collapse;width:100%\">\n<tr><td style=\"width:35%;background:#f5f5f0\"><strong>Provozovatel</strong></td><td>[Vaše firma s.r.o.]</td></tr>\n<tr><td style=\"background:#f5f5f0\"><strong>Adresa provozovny</strong></td><td></td></tr>\n<tr><td style=\"background:#f5f5f0\"><strong>Charakter provozu</strong></td><td>Pekárna menšího typu — výroba pekařských a cukrářských výrobků</td></tr>\n<tr><td style=\"background:#f5f5f0\"><strong>Sortiment</strong></td><td>Více než 20 druhů výrobků (chleby, běžné pečivo, jemné pečivo, listové, lité, trvanlivé, rozpékané)</td></tr>\n<tr><td style=\"background:#f5f5f0\"><strong>Distribuce</strong></td><td>Vlastní prodejna + smluvní odběratelé (B2B)</td></tr>\n</table>\n\n<h3>1.2 Legislativní rámec</h3>\n<p>Plán je vypracován v souladu s těmito předpisy:</p>\n<ul>\n<li><strong>Nařízení EP a Rady (ES) č. 178/2002</strong> — obecné zásady a požadavky potravinového práva, založení Evropského úřadu pro bezpečnost potravin</li>\n<li><strong>Nařízení EP a Rady (ES) č. 852/2004</strong> — o hygieně potravin (povinnost zavedení postupů založených na zásadách HACCP)</li>\n<li><strong>Nařízení Komise (ES) č. 2073/2005</strong> — o mikrobiologických kritériích pro potraviny</li>\n<li><strong>Nařízení EP a Rady (EU) č. 1169/2011</strong> — o poskytování informací o potravinách spotřebitelům (alergeny)</li>\n<li><strong>Zákon č. 110/1997 Sb.</strong>, o potravinách a tabákových výrobcích, ve znění pozdějších předpisů</li>\n<li><strong>Vyhláška č. 18/2024 Sb.</strong>, o hygienických požadavcích na potraviny (ruší vyhl. 137/2004 Sb.)</li>\n<li><strong>Codex Alimentarius CAC/RCP 1-1969 Rev. 4-2003</strong> — General Principles of Food Hygiene (doporučení 7 zásad HACCP)</li>\n</ul>\n\n<h3>1.3 Výklad pojmů</h3>\n<dl>\n<dt><strong>HACCP (Hazard Analysis and Critical Control Points)</strong></dt>\n<dd>Systém analýzy nebezpečí a stanovení kritických kontrolních bodů — preventivní systém zajišťující bezpečnost potravin identifikací a kontrolou významných nebezpečí.</dd>\n\n<dt><strong>Plán systému kritických bodů</strong></dt>\n<dd>Dokument zajišťující ovládání nebezpečí významných pro porušení zdravotní nezávadnosti potravin v daném úseku potravinového řetězce (čl. 5 nařízení 852/2004).</dd>\n\n<dt><strong>Kritický kontrolní bod (CCP — Critical Control Point)</strong></dt>\n<dd>Krok, v němž lze uplatnit ovládání a který je nezbytný pro prevenci, vyloučení nebo zmenšení nebezpečí ohrožujícího zdravotní nezávadnost potraviny na přípustnou úroveň.</dd>\n\n<dt><strong>Kontrolní bod (CP — Control Point)</strong></dt>\n<dd>Úsek technologie nebo operace, ve kterém se provádí pozorování nebo měření, a který přispívá k bezpečnosti potraviny, ale nesplňuje kritéria CCP.</dd>\n\n<dt><strong>Nebezpečí (Hazard)</strong></dt>\n<dd>Biologický (B), chemický (CH), fyzikální (F) nebo <strong>alergenní (A)</strong> činitel v potravině či její zhotovené podmínce, který může porušit zdravotní nezávadnost potraviny.</dd>\n\n<dt><strong>Riziko</strong></dt>\n<dd>Pravděpodobnost výskytu škodlivého účinku ve spojení se závažností tohoto účinku. Klasifikace: <strong>N</strong> = nízké, <strong>M</strong> = střední, <strong>S</strong> = vyšší střední (signifikantní), <strong>V</strong> = vysoké.</dd>\n\n<dt><strong>Kritická mez</strong></dt>\n<dd>Hodnota oddělující přípustný stav od nepřípustného v kritickém bodě. Měřitelné veličiny: teplota, čas, pH, vlhkost, koncentrace…</dd>\n\n<dt><strong>Validace</strong></dt>\n<dd>Získání důkazu, že prvky plánu HACCP jsou účinné při kontrole nebezpečí.</dd>\n\n<dt><strong>Verifikace</strong></dt>\n<dd>Použití metod, postupů a zkoušek (jiných než průběžné sledování) k posouzení, zda systém HACCP funguje dle plánu.</dd>\n</dl>\n\n<h3>1.4 Postup zavedení (7 zásad HACCP dle Codex Alimentarius)</h3>\n<ol>\n<li>Provedení analýzy nebezpečí</li>\n<li>Stanovení kritických kontrolních bodů (CCP)</li>\n<li>Stanovení kritických mezí v každém CCP</li>\n<li>Stanovení postupu sledování (monitoringu)</li>\n<li>Stanovení nápravných opatření při překročení kritické meze</li>\n<li>Stanovení postupu ověřování (verifikace)</li>\n<li>Vedení dokumentace a záznamů</li>\n</ol>",
        ],
        [
            'kategorie' => 'plan_haccp',
            'nazev' => '2. Tým HACCP',
            'poradi' => 2,
            'obsah' => "<h3>2.1 Sestavení týmu HACCP</h3>\n<p>V souladu s čl. 5 odst. 1 nařízení (ES) č. 852/2004 jmenoval jednatel společnosti <strong>[Jednatel firmy]</strong> tým pro zavedení a provozování systému kritických bodů. Tým je zodpovědný za vypracování, zavedení, údržbu a aktualizaci plánu HACCP.</p>\n\n<h3>2.2 Kompetence a vzdělání týmu</h3>\n<p>Členové týmu mají dostatečné odborné znalosti v oblastech:</p>\n<ul>\n<li>Technologie potravinářské výroby</li>\n<li>Hygiena potravin a sanitační postupy</li>\n<li>Mikrobiologie potravin (Salmonella, Listeria, E. coli, Bacillus cereus, plísně)</li>\n<li>Legislativa potravinářského práva (178/2002, 852/2004, 110/1997 Sb.)</li>\n<li>Alergeny dle nařízení 1169/2011 (14 sledovaných alergenů)</li>\n</ul>\n\n<h3>2.3 Členové týmu HACCP</h3>\n<table border=\"1\" cellpadding=\"6\" style=\"border-collapse:collapse;width:100%\">\n<tr style=\"background:#f5f5f0\">\n<th>Funkce</th><th>Jméno</th><th>Odpovědnost</th><th>Datum jmenování</th><th>Podpis</th>\n</tr>\n<tr><td>Vedoucí týmu</td><td>[Jednatel firmy]</td><td>Celková odpovědnost, audit, validace</td><td></td><td></td></tr>\n<tr><td>Koordinátor týmu</td><td></td><td>Koordinace dokumentace, školení personálu</td><td></td><td></td></tr>\n<tr><td>Vedoucí výroby</td><td></td><td>Sledování CCP, přejímka surovin</td><td></td><td></td></tr>\n<tr><td>Mistr (technolog)</td><td></td><td>Technologické postupy, výrobní receptury</td><td></td><td></td></tr>\n</table>\n\n<h3>2.4 Periodické přezkoušení a aktualizace</h3>\n<ul>\n<li>Tým se schází <strong>min. 1× ročně</strong> k revizi plánu HACCP</li>\n<li>Mimořádně při: změně technologie, sortimentu, suroviny, legislativy, výskytu incidentu</li>\n<li>O setkání týmu se vede zápis (datum, účastníci, body programu, závěry)</li>\n<li>Audit provádí jednou ročně tým složený z osob bez přímé zodpovědnosti za provozování systému (může zahrnovat externí poradce)</li>\n</ul>\n\n<p style=\"margin-top:14px;font-size:11pt;color:#666\"><em>Tým může být v případě potřeby rozšířen o další interní i externí pracovníky (mikrobiologa, technologa, právníka, externího auditora).</em></p>",
        ],
        [
            'kategorie' => 'plan_haccp',
            'nazev' => '3. Diagram výrobního procesu',
            'poradi' => 3,
            'obsah' => "<h3>Sestavení a potvrzení diagramu výrobního procesu</h3>\n<p>Diagram výrobního procesu je sestaven jednotlivě pro každý druh výrobku a je k dispozici v sekci <strong>📈 Grafy (šablony postupu)</strong> v aplikaci.</p>\n\n<p>Diagram výrobního procesu byl ověřován za provozu a na základě zjištěných rozdílů byl upraven a doplněn tak, aby odpovídal skutečnosti.</p>\n\n<h4>Potvrzení diagramu výrobního procesu</h4>\n<table border=\"1\" cellpadding=\"6\" style=\"border-collapse:collapse;width:100%\">\n<tr style=\"background:#f5f5f0\">\n<th>Funkce</th><th>Datum</th><th>Podpis</th>\n</tr>\n<tr><td>Vedoucí týmu</td><td></td><td></td></tr>\n<tr><td>Koordinátor týmu</td><td></td><td></td></tr>\n<tr><td>Vedoucí výroby</td><td></td><td></td></tr>\n<tr><td>Mistr (technolog)</td><td></td><td></td></tr>\n</table>",
        ],
        [
            'kategorie' => 'plan_haccp',
            'nazev' => '4. Analýza nebezpečí',
            'poradi' => 4,
            'obsah' => "<h3>4.1 Analýza nebezpečí — sumární přehled</h3>\n<p>Analýza nebezpečí provedena podle Codex Alimentarius (zásada 1) a čl. 5 odst. 2 nařízení (ES) č. 852/2004. Hodnocena všechna potenciální nebezpečí ve všech krocích výrobního procesu. Detailní analýza per výrobek je v jednotlivých <strong>HACCP kartách výrobků</strong>.</p>\n\n<h3>4.2 Druhy nebezpečí</h3>\n<table border=\"1\" cellpadding=\"6\" style=\"border-collapse:collapse;width:100%;font-size:10.5pt\">\n<tr style=\"background:#f5f5f0\"><th>Typ</th><th>Příklady v potravinářské výrobě</th></tr>\n<tr><td><strong>Biologické (B)</strong></td><td>Salmonella spp., Listeria monocytogenes, E. coli (vč. STEC/EHEC), Staphylococcus aureus, Bacillus cereus, Clostridium perfringens, plísně produkující mykotoxiny (Aspergillus, Penicillium), kvasinky, hmyz, hlodavci</td></tr>\n<tr><td><strong>Chemické (CH)</strong></td><td>Mykotoxiny (aflatoxin, ochratoxin), rezidua pesticidů, těžké kovy (Pb, Cd, Hg), zbytky čisticích prostředků, alergenní křížová kontaminace, obalové migrace (BPA), akrylamid (při pečení)</td></tr>\n<tr><td><strong>Fyzikální (F)</strong></td><td>Sklo, kov, kámen, plast, dřevo, šperky pracovníků, vlasy, kostí, skořápky vajec, lepidlo z obalů</td></tr>\n<tr><td><strong>Alergenní (A)</strong> dle 1169/2011</td><td>14 EU alergenů: lepek, korýši, vejce, ryby, arašídy, sója, mléko, ořechy, celer, hořčice, sezam, oxid siřičitý, vlčí bob, měkkýši</td></tr>\n</table>\n\n<h3>4.3 Klasifikace významnosti rizik</h3>\n<table border=\"1\" cellpadding=\"6\" style=\"border-collapse:collapse;width:100%;font-size:10.5pt\">\n<tr style=\"background:#f5f5f0\"><th>Kód</th><th>Stupeň</th><th>Popis</th></tr>\n<tr><td><strong>N</strong></td><td>Nízké</td><td>Pravděpodobnost výskytu i závažnost nízká, nepřispívá k zdravotním rizikům</td></tr>\n<tr><td><strong>M</strong></td><td>Střední</td><td>Možný výskyt, mírný účinek — nutné běžné kontrolní opatření</td></tr>\n<tr><td><strong>S</strong></td><td>Vyšší střední</td><td>Reálná pravděpodobnost a možnost vážnějšího dopadu — striktní kontrolní opatření</td></tr>\n<tr><td><strong>V</strong></td><td>Vysoké</td><td>Vysoká pravděpodobnost výskytu nebo vážný zdravotní dopad — nutný CCP</td></tr>\n</table>\n\n<h3>4.4 Sumární analýza výrobních operací</h3>\n<table border=\"1\" cellpadding=\"6\" style=\"border-collapse:collapse;width:100%;font-size:10pt\">\n<thead style=\"background:#f5f5f0\">\n<tr><th>Výrobní operace</th><th>Druh</th><th>Riziko</th><th>Zdůvodnění</th><th>CCP/CP</th></tr>\n</thead>\n<tbody>\n<tr><td>Příjem surovin</td><td>B, CH, F, A</td><td>S</td><td>Možnost dodávky kontaminovaných surovin (Salmonella ve vejcích, mykotoxiny v mouce, alergeny v křížové kontaminaci u dodavatele) — povinná vstupní kontrola, atesty</td><td><strong>CP</strong></td></tr>\n<tr><td>Sklad surovin</td><td>B, CH, F</td><td>S</td><td>Pomnožení MO při nesprávné teplotě/vlhkosti, tvorba mykotoxinů, kontaminace škůdci — kontrola teploty, vlhkosti, FIFO, deratizace</td><td><strong>CP</strong></td></tr>\n<tr><td>Výtluk vajec</td><td>B, F</td><td>S</td><td>Salmonella enteritidis — striktní separace prostor, dezinfekce, max. 2 hodiny do zpracování, teplota melanže max. 8 °C</td><td><strong>CP</strong></td></tr>\n<tr><td>Dávkování / mísení</td><td>B, F, A</td><td>M</td><td>Křížová kontaminace alergenů — sanit. postupy. Kovová detekce u rizikových surovin</td><td>—</td></tr>\n<tr><td>Tvarování / dělení</td><td>B</td><td>M</td><td>Kontaminace MO z prostředí, nářadí, obsluhy — hygiena pracoviště</td><td>—</td></tr>\n<tr><td>Kynutí</td><td>B</td><td>M</td><td>Správná teplota a vlhkost zabraňují tvorbě plísní</td><td>—</td></tr>\n<tr><td><strong>Pečení</strong></td><td>B</td><td><strong>V</strong></td><td><strong>Klíčový krok pro zajištění zdravotní nezávadnosti — likvidace vegetativních forem patogenů (Salmonella, E. coli, Listeria, Bacillus cereus). Vnitřní teplota produktu min. 90 °C zaručuje pasterizační účinek.</strong></td><td><strong>CCP</strong></td></tr>\n<tr><td>Chladnutí</td><td>B</td><td>M</td><td>Sekundární kontaminace, kondenzace vlhkosti — chlazení v čistém prostředí, oddělení od surového těsta</td><td><strong>CP</strong></td></tr>\n<tr><td>Plnění (jemné peč.)</td><td>B</td><td>S</td><td>Tvarohové, ovocné a tukové náplně — riziko kontaminace, oddělené nářadí</td><td><strong>CP</strong></td></tr>\n<tr><td>Skladování hotových</td><td>B</td><td>M</td><td>Pomnožení plísní při nedodržení T/RH — kontrola</td><td><strong>CP</strong></td></tr>\n<tr><td>Mytí přepravek</td><td>B, CH</td><td>M</td><td>Zbytky čisticích prostředků, nedostatečně omyté přepravky — záznam mytí, dezinfekce</td><td><strong>CP</strong></td></tr>\n<tr><td>Expedice</td><td>F</td><td>N</td><td>Mechanická kontaminace při manipulaci — čisté přepravky, hygiena</td><td><strong>CP</strong></td></tr>\n</tbody>\n</table>\n\n<p style=\"margin-top:14px\"><strong>Legenda:</strong> B = biologické · CH = chemické · F = fyzikální · A = alergenní · CCP = kritický kontrolní bod · CP = kontrolní bod</p>\n\n<h3>4.5 Rozhodovací strom dle Codex Alimentarius</h3>\n<p>Pro rozhodnutí, zda je daný krok CCP, byl použit standardní rozhodovací strom (Decision Tree) z Codex Alimentarius:</p>\n<ol>\n<li><strong>Q1:</strong> Existuje pro identifikované nebezpečí preventivní opatření? Ano → Q2 / Ne → není CCP</li>\n<li><strong>Q2:</strong> Je tento krok speciálně určen k tomu, aby eliminoval nebo snížil riziko na přijatelnou úroveň? Ano → CCP / Ne → Q3</li>\n<li><strong>Q3:</strong> Mohou tato nebezpečí kontaminovat produkt na nepřípustnou úroveň, nebo se mohou v něm zvýšit? Ano → Q4 / Ne → není CCP</li>\n<li><strong>Q4:</strong> Eliminuje následující krok zjištěné nebezpečí? Ano → není CCP / Ne → CCP</li>\n</ol>\n<p>Aplikací tohoto rozhodovacího stromu byl jako jediný <strong>kritický kontrolní bod (CCP) určen krok PEČENÍ</strong>, neboť představuje jedinou teplotní bariéru pro likvidaci vegetativních forem patogenních mikroorganismů.</p>",
        ],
        [
            'kategorie' => 'plan_haccp',
            'nazev' => '5. Kritické meze a nápravná opatření',
            'poradi' => 5,
            'obsah' => "<h3>5.1 Stanovení kritických mezí, postupů sledování a nápravných opatření</h3>\n<p>Kritické meze stanoveny v souladu s vědeckými poznatky, technologickým know-how a požadavky <strong>nařízení (ES) č. 2073/2005</strong> o mikrobiologických kritériích pro potraviny.</p>\n\n<h3>5.2 Mikrobiologická kritéria (dle 2073/2005)</h3>\n<table border=\"1\" cellpadding=\"6\" style=\"border-collapse:collapse;width:100%;font-size:10pt\">\n<thead style=\"background:#f5f5f0\">\n<tr><th>Mikroorganismus</th><th>Limit</th><th>Vzorek</th><th>Použití</th></tr>\n</thead>\n<tbody>\n<tr><td>Salmonella spp.</td><td>nedetekováno</td><td>v 25 g</td><td>Výrobky s vejcem (jemné pečivo)</td></tr>\n<tr><td>Listeria monocytogenes</td><td>nedetekováno</td><td>v 25 g</td><td>Trvanlivější výrobky určené k přímé konzumaci</td></tr>\n<tr><td>E. coli</td><td>&lt; 10 KTJ/g</td><td>na 1 g</td><td>Hygiena výrobních postupů</td></tr>\n<tr><td>Staphylococcus aureus</td><td>&lt; 100 KTJ/g</td><td>na 1 g</td><td>Indikátor osobní hygieny</td></tr>\n<tr><td>Plísně a kvasinky</td><td>&lt; 10³ KTJ/g</td><td>na 1 g</td><td>Po 24 h skladování při 25 °C</td></tr>\n<tr><td>Bacillus cereus</td><td>&lt; 10⁴ KTJ/g</td><td>na 1 g</td><td>U výrobků s plněním</td></tr>\n</tbody>\n</table>\n\n<h3>5.3 CCP a CP — kritické meze a postupy</h3>\n<table border=\"1\" cellpadding=\"6\" style=\"border-collapse:collapse;width:100%;font-size:10pt\">\n<thead style=\"background:#f5f5f0\">\n<tr><th>Operace</th><th>Typ</th><th>Sledovaný znak</th><th>Kritická mez</th><th>Postup sledování</th><th>Frekvence</th><th>Nápravné opatření</th></tr>\n</thead>\n<tbody>\n<tr style=\"background:#FEF3C7\"><td><strong>Pečení (CCP)</strong></td><td><strong>CCP</strong></td><td>Teplota pece, doba pečení, vnitřní teplota produktu, smyslové hodnocení</td><td><strong>Pečivo:</strong> 220–240 °C, 12–22 min · <strong>Chléb:</strong> 240–260 °C, 30–45 min · <strong>Jemné pečivo:</strong> 180–210 °C, 12–18 min · <strong>Vnitřní teplota produktu MIN. 90 °C</strong> (jemné min. 88 °C)</td><td>Měření teploty pece, kontrola času. Namátkové měření vnitřní teploty teploměrem. Záznam do provozního deníku</td><td>U každé vsádky / výsadky</td><td>Úprava teplotního režimu pece, dopečení; při neopravitelné odchylce — výrobek určit k jinému účelu nebo likvidovat</td></tr>\n<tr><td>Příjem surovin</td><td>CP</td><td>Neporušenost obalu, DMT, organoleptika, teplota chlazených surovin</td><td>Vyhovují všem sledovaným znakům. Chlazené suroviny T ≤ 8 °C. Atesty u rizikových surovin (mouka, vejce, tuky)</td><td>Vizuální kontrola, namátkově teploměr</td><td>Při každé dodávce</td><td>Nepřevzetí dodávky, vrácení dodavateli, písemný záznam reklamace</td></tr>\n<tr><td>Sklad surovin (suchý)</td><td>CP</td><td>Teplota, vlhkost</td><td>T ≤ 25 °C, RH &lt; 70 %, FIFO</td><td>Teploměr, vlhkoměr — záznam denně</td><td>Denně (ráno)</td><td>Větrání, klimatizace, snížení vlhkosti, přesun do jiného prostoru</td></tr>\n<tr><td>Sklad chlazený</td><td>CP</td><td>Teplota</td><td>T 2–8 °C (mléčné, vejce, droždí, tuky)</td><td>Teploměr v boxe — záznam denně</td><td>Denně (ráno + odpoledne)</td><td>Servis chlazení, přesun zboží do jiného boxu, posouzení vhodnosti</td></tr>\n<tr><td>Výtluk vajec</td><td>CP</td><td>Teplota melanže, doba do zpracování, čistota prostředí</td><td>T melanže ≤ 8 °C · Doba od vytlučení do zpracování max. 2 hod. · Pouze potřebný počet vajec · Na vyhrazeném místě</td><td>Záznam: čas vytlučení, počet vajec, teplota, jméno obsluhy</td><td>Po každém vytlučení</td><td>Likvidace melanže (uplynutí 2 h, T &gt; 8 °C), vytřídění vajec, dezinfekce prostoru</td></tr>\n<tr><td>Chladnutí výrobků</td><td>CP</td><td>Doba od upečení po balení/expedici, teplota výrobku</td><td><strong>Chléb:</strong> min. 6 hod. (vnitřní T &lt; 30 °C) · <strong>Vícezrnné:</strong> min. 3 hod. · <strong>Toastový:</strong> min. 3 hod. · <strong>Vánočky/mazance:</strong> min. 2 hod. · <strong>Drobné plněné:</strong> min. 1 hod.</td><td>Vizuální + smyslová kontrola, namátkově teploměrem</td><td>U každé vyrobené dávky</td><td>Prodloužení doby chladnutí, ventilace, oddělení od horkého zboží</td></tr>\n<tr><td>Plnění (jemné pečivo)</td><td>CP</td><td>Teplota náplně, separace nářadí, hygiena</td><td>Tvarohová náplň T ≤ 8 °C, doba zpracování max. 2 hod. Oddělené nářadí pro různé náplně</td><td>Smyslová kontrola náplní, teploměr</td><td>Před každým plněním</td><td>Likvidace náplně, dezinfekce nářadí</td></tr>\n<tr><td>Skladování hotových výrobků</td><td>CP</td><td>Teplota, vlhkost expediční místnosti</td><td>T ≤ 25 °C, RH &lt; 70 %, FIFO, max. 4 hod. do expedice</td><td>Vizuální + teploměr</td><td>Denně</td><td>Úprava T/RH, urychlení expedice</td></tr>\n<tr><td>Strouhanka — staré pečivo</td><td>CP</td><td>Vizuální stav (plísně, posyp, znečištění)</td><td>Bez plísní, bez posypu, čisté, neporušené</td><td>Vizuální kontrola každého kusu</td><td>Před každým mletím</td><td>Vyřazení napadených kusů, likvidace</td></tr>\n<tr><td>Strouhanka — sušení</td><td>CP</td><td>Teplota, doba sušení, výsledná vlhkost</td><td>T 80–150 °C, doba 4–5 hod. Smyslové hodnocení: suchá, nevlhká</td><td>Časoměr, kontrola na konci procesu</td><td>Při každém sušení</td><td>Dosušení, případně likvidace nedostatečně vysušené šarže</td></tr>\n<tr><td>Mytí přepravek</td><td>CP</td><td>Čistota, počet umytých přepravek</td><td>Vyhovuje vizuálně, bez zbytků potravin a čisticích prostředků</td><td>Vizuální kontrola, evidence (počet, datum, podpis)</td><td>U všech vrácených přepravek</td><td>Opakované umytí, vytřídění poškozených</td></tr>\n<tr><td>Expedice</td><td>CP</td><td>Čistota vozidel, kontrola DMT, hygiena řidičů</td><td>Čisté přepravky, vozidla, platná DMT, řidič v pracovním oděvu</td><td>Vizuální před nakládkou</td><td>Před každou nakládkou</td><td>Reorganizace, výměna vozidla, dezinfekce</td></tr>\n</tbody>\n</table>\n\n<h3>5.4 Záznamy</h3>\n<p>Veškeré sledované znaky se zaznamenávají do provozního deníku CCP/CP. Záznamy jsou archivovány <strong>min. 2 roky</strong> dle požadavků kontrolních orgánů (SZPI, KHS).</p>\n\n<p style=\"margin-top:14px;font-size:11pt;color:#666\"><em>Počet stanovených kritických bodů není konečný a bude se upravovat v souladu s navazujícím ověřováním (verifikací, validací) při provozování systému.</em></p>",
        ],
        [
            'kategorie' => 'plan_haccp',
            'nazev' => '6. Verifikace, validace, vnitřní audit',
            'poradi' => 6,
            'obsah' => "<h3>Ověřování systému (verifikace, validace, vnitřní audit)</h3>\n\n<h4>Verifikace</h4>\n<p>Způsob a četnost provádění <strong>verifikace</strong> — ověřování kontrolních metod v kritických bodech — je určena vnitřním předpisem společnosti Appek s.r.o.</p>\n\n<h4>Validace</h4>\n<p><strong>Validace</strong> — ověřování funkce systému (jednotlivých plánů) se provádí analýzou finálních výrobků. Četnost analýz je stanovena aktuálním plánem systému kritických bodů výrobku a souvisejícími vnitřními předpisy společnosti Appek s.r.o.</p>\n\n<h4>Vnitřní audit</h4>\n<p><strong>Vnitřní audit</strong> provádí jednou za rok tým společnosti složený z osob, které nenesou přímou zodpovědnost za provozování systému. Složení týmu jmenuje jednatel společnosti, tým může zahrnovat externí poradce.</p>\n<p>Audit spočívá v:</p>\n<ul>\n<li>Kontrole podkladů o verifikaci a validaci plánů</li>\n<li>Kontrole záznamů z měření v kritických bodech</li>\n<li>Kontrole záznamů o školeních</li>\n<li>Prohlídce technologie</li>\n<li>Přezkoušení obsluhy</li>\n</ul>\n<p>O provedeném auditu se vede zápis. Audit může být proveden kdykoliv rozhodnutím jednatele společnosti.</p>\n\n<h4>Záznamy o auditech</h4>\n<table border=\"1\" cellpadding=\"6\" style=\"border-collapse:collapse;width:100%\">\n<tr style=\"background:#f5f5f0\"><th>Datum auditu</th><th>Auditor</th><th>Výsledek</th><th>Nápravná opatření</th><th>Podpis</th></tr>\n<tr><td></td><td></td><td></td><td></td><td></td></tr>\n<tr><td></td><td></td><td></td><td></td><td></td></tr>\n<tr><td></td><td></td><td></td><td></td><td></td></tr>\n</table>",
        ],

        // ============== SANITAČNÍ ŘÁD ==============
        [
            'kategorie' => 'sanitacni_rad',
            'nazev' => '1. Pracovníci',
            'poradi' => 1,
            'obsah' => "<h2>Sanitační řád</h2>\n<p style=\"font-size:11pt;color:#666;margin-bottom:14px\"><em>Vypracován dle: Nařízení (ES) č. 852/2004 (hygiena potravin), Vyhlášky č. 18/2024 Sb. (hygienické požadavky na potraviny), zákona č. 110/1997 Sb. o potravinách, zákona č. 258/2000 Sb. o ochraně veřejného zdraví.</em></p>\n\n<h3>1.1 Požadavky na pracovníky</h3>\n<p>Pracovník přijatý do potravinářského provozu musí:</p>\n<ul>\n<li>Vlastnit <strong>platný zdravotní průkaz</strong> dle § 19 zák. 258/2000 Sb. (lékařská prohlídka před nástupem a periodicky 1× za 2 roky, u rizikových pracovníků kratší interval)</li>\n<li>Absolvovat školení v <strong>hygienickém minimu</strong> dle § 20 zák. 258/2000 Sb. (provede vedoucí, periodicita 1× ročně)</li>\n<li>Absolvovat školení v zásadách HACCP a sanitačním řádu</li>\n<li>Organizace zajistí proškolení o specifických rizicích pracoviště (záznam: obsah, datum, jméno školitele, podpisy)</li>\n</ul>\n\n<h3>1.2 Pracovní pomůcky</h3>\n<p>Při nástupu obdrží pracovník <strong>pracovní potřeby</strong>:</p>\n<ul>\n<li>Bílý pracovní oděv (alespoň 2 sady pro pravidelnou výměnu)</li>\n<li>Pokrývku hlavy (čepice, šátek)</li>\n<li>Pracovní obuv (uzavřená, omyvatelná)</li>\n<li>Zástěru pro práci s tekutými surovinami</li>\n<li>Jednorázové rukavice (pro práci s hotovými výrobky)</li>\n</ul>\n<p>Nárok na opakované vybavení má zaměstnanec <strong>1× za 2 roky</strong> nebo dle míry opotřebení.</p>\n\n<h3>1.3 Povinnosti zaměstnance</h3>\n<ul>\n<li>Dodržovat všechny hygienické předpisy a sanitační řád</li>\n<li>Udržovat osobní hygienu a čistotu pracoviště</li>\n<li>Hlásit jakékoliv onemocnění, které by mohlo ovlivnit zdravotní nezávadnost potravin</li>\n<li>Účastnit se pravidelných školení a hygienických prohlídek</li>\n</ul>\n\n<p><em>Za zajištění výše uvedených požadavků zodpovídá vždy vedoucí provozu / jednatel společnosti.</em></p>",
        ],
        [
            'kategorie' => 'sanitacni_rad',
            'nazev' => '2. Osobní hygiena pracovníků',
            'poradi' => 2,
            'obsah' => "<h3>2. Osobní hygiena — pracovníci ve výrobě</h3>\n<ul>\n<li>Ve výrobě je <strong>přísně zakázáno kouřit, jíst, pít</strong> mimo prostory k tomuto účelu určené</li>\n<li>Při výrobě je zakázáno nosit prstýnky, hodinky, přívěsky, řetízky, sponky ve vlasech, mít nalakované nehty</li>\n<li>Nutno nosit čisté oblečení včetně pokrývek hlavy</li>\n<li>Při znečištění oděvu je nutné tento vyměnit (u prací silně znečišťujících používat zástěry)</li>\n<li>Před použitím WC nutno odložit plášť mimo prostor (nutné zajistit vybavení), po použití nutno umýt ruce mýdlem a osušit ručníkem (nejlépe papírovým nebo vzduchem)</li>\n<li>Po každé práci, kde dochází ke znečištění rukou, je nutné si je umýt</li>\n<li>Pracovník je povinen nahlásit před nástupem do práce každé <strong>hnisavé nebo průjmové onemocnění</strong>, pobyt v zemích s rizikem těchto onemocnění, popř. možnou kapénkovou infekci (záznam: kdo, kdy, kam přeložen, podpis)</li>\n<li>Je zakázáno bezdůvodně přecházet ze špinavých prostor do čistých a naopak</li>\n</ul>\n\n<h4>Ostatní pracovníci</h4>\n<p>Při vstupu do výrobních prostor jsou povinni nosit <strong>bílý plášť, pokrývku hlavy</strong>. Totéž se vztahuje na kontroly, odběratele, případné exkurze (u těch nutno posoudit zdravotní průkazy!).</p>\n<p><strong>Je přísně zakázáno vodění soukromých návštěv do výroby!</strong></p>",
        ],
        [
            'kategorie' => 'sanitacni_rad',
            'nazev' => '3. Sociální zázemí (šatny, WC, chodby)',
            'poradi' => 3,
            'obsah' => "<h3>3. Sociální zázemí</h3>\n\n<h4>Šatny</h4>\n<p>Každý provoz nutno vybavit dostatečným počtem šaten, oddělených pro muže a ženy — rozhoduje počet. Tyto nesmí být spojeny s umývárnami, musí tvořit tzv. <strong>hygienickou smyčku</strong>: pracovník nesmí vstoupit do provozu v civilním oděvu, naopak nesmí v pracovním oděvu opustit provoz.</p>\n<ul>\n<li>Každý pracovník musí mít přidělenou skříňku s oddělením pro civilní a pracovní oděv</li>\n<li>Do provozních místností nesmí být nošeny tašky, civilní oděv, obuv atd.</li>\n<li>Úklid — denně při použití dezinfekčních prostředků (např. SAVO)</li>\n</ul>\n\n<h4>WC</h4>\n<ul>\n<li>V potřebném počtu podle zaměstnanců, oddělené pro muže a ženy</li>\n<li>Nesmí mít vchod bezprostředně z provozních místností</li>\n<li>Vybaveno zařízením na mytí rukou, na odkládání pracovního oděvu</li>\n<li>Na dámském WC hygienický koš (uzavřený, denně čištěn a dezinfikován)</li>\n<li>Dostatečné množství mýdla a prostředky k osušení rukou</li>\n<li>Úklid denně při použití dezinfekčních prostředků (působení v záchodové míse přibližně 30 min.)</li>\n<li><strong>Dezinfekční prostředky obměňovat v 2-měsíčních intervalech</strong> (kvůli vznikající rezistenci mikrobů)</li>\n</ul>\n\n<h4>Chodby</h4>\n<p>Úklid denně při použití dezinfekčních prostředků (SAVO).</p>\n\n<p><em>Za dodržování úklidu zodpovídá vždy vedoucí provozu.</em></p>",
        ],
        [
            'kategorie' => 'sanitacni_rad',
            'nazev' => '4. Příjem surovin a skladování',
            'poradi' => 4,
            'obsah' => "<h3>4.1 Příjem surovin</h3>\n<p>Suroviny musí splňovat požadavky <strong>nařízení (ES) č. 178/2002</strong> (vysledovatelnost), <strong>zákona č. 110/1997 Sb.</strong> o potravinách a tabákových výrobcích a <strong>nařízení (EU) č. 1169/2011</strong> (značení).</p>\n<p>Při příjmu suroviny musí být:</p>\n<ul>\n<li>V <strong>neporušeném obalu</strong>, čisté, bez známek znečištění nebo poškození</li>\n<li>S platnou dobou minimální trvanlivosti (DMT) nebo dobou použitelnosti (DP)</li>\n<li>Správně označeny dle § 6 zák. 110/1997 Sb. a nařízení 1169/2011 (název, šarže, výrobce, alergeny, DMT/DP, podmínky skladování)</li>\n<li>Splňovat požadavky na vysledovatelnost dle čl. 18 nařízení 178/2002 — dodací list s údaji o dodavateli</li>\n<li>U <strong>chlazených surovin</strong> (vejce, mléko, tuky, droždí, sýry, masné suroviny): namátková kontrola teploty teploměrem — <strong>max. 8 °C</strong>, záznam!</li>\n<li>U surovin s vysokým rizikem (mouka, vejce, mléko, oleje): vyžadovat <strong>atesty</strong> o zdravotní nezávadnosti — archivace alespoň 2 roky</li>\n</ul>\n<p>Nevyhovující surovinu <strong>nepřevzít</strong>, sepsat reklamační záznam, vrátit dodavateli.</p>\n\n<h3>4.2 Sklad surovin (suchý)</h3>\n<ul>\n<li>Místnost suchá, dobře větratelná, <strong>teplota max. 25 °C</strong> (oproti dříve uváděným 35 °C nyní přísnější dle 18/2024 Sb.)</li>\n<li>Relativní vlhkost <strong>&lt; 70 %</strong></li>\n<li>Zabezpečená proti vnikání hmyzu, hlodavců, ptáků — sítě v oknech, oplechování dveří, otrávené nástrahy</li>\n<li>Sklad vybaven <strong>teploměrem a vlhkoměrem</strong> — denní záznam</li>\n<li>Bílení 1× ročně, v případě potřeby častěji</li>\n<li>Běžný úklid denně, sanitární úklid 1× za 3 měsíce s dezinfekčními prostředky (např. SAVO, Domestos, profesionální deziprostř.)</li>\n<li><strong>Deratizace 2× ročně</strong> (firma ČIHÁK), záznamy archivovány</li>\n<li><strong>Suroviny POUZE na paletách / roštech</strong> — nikdy přímo na podlaze (min. 15 cm od stěny, 10 cm od podlahy)</li>\n<li>FIFO (first-in, first-out) — kontrola DMT a DP, prošlé okamžitě vyřadit</li>\n</ul>\n\n<h3>4.3 Chladící boxy a lednice</h3>\n<ul>\n<li>Skladování chlazených surovin (droždí, tuky, mléčné výrobky, vejce, masné výrobky) při <strong>2–8 °C</strong></li>\n<li><strong>Dezinfekce 1× týdně</strong>, zajištění úklidu provádí směna</li>\n<li>Měření teploty <strong>2× denně</strong> (ráno, odpoledne) — záznam do deníku</li>\n<li>Při poruše chlazení: okamžitě posoudit zdravotní nezávadnost zboží, příp. vyřadit / přesunout do jiného boxu</li>\n</ul>\n\n<h3>4.4 Mrazící boxy</h3>\n<ul>\n<li>Hluboko zmrazené suroviny při <strong>−18 °C nebo nižší</strong></li>\n<li>Pravidelná kontrola teploty, odmrazování dle potřeby</li>\n<li>Rozmrazování pouze v lednici (2–8 °C), nikdy při pokojové teplotě</li>\n</ul>\n\n<p><em>Za provoz skladu zodpovídá vždy vedoucí provozu.</em></p>",
        ],
        [
            'kategorie' => 'sanitacni_rad',
            'nazev' => '5. Výrobní prostory a úklid',
            'poradi' => 5,
            'obsah' => "<h3>5. Výrobní prostory</h3>\n<ul>\n<li>Musí být čisté, dobře větratelné, zabezpečené proti vnikání hmyzu a hlodavců</li>\n<li>Okna musí být zabezpečena <strong>sítěmi</strong>, dveře dole proti hlodavcům oplechováním</li>\n<li>Deratizace a dezinsekce proti škůdcům prováděna v <strong>půlročních intervalech</strong> (záznamy nebo alespoň faktury)</li>\n<li>Bílení 1× ročně, popř. dle potřeby častěji</li>\n<li>Mytí oken dle charakteru provozu, v prašném prostředí 4× ročně, 2× zvenku — parapety při denním úklidu</li>\n<li>Mytí topných těles, čištění moučných cest, prosévaček — záznamy</li>\n</ul>\n\n<h3>Úklid provozovny</h3>\n<dl>\n<dt><strong>Denní</strong></dt>\n<dd>Běžný úklid pracoviště — suché očistění ploch, strojů od hrubého znečištění, poté mytí vodou, popř. s přísadou dezinfekčního prostředku, poté důkladný oplach</dd>\n<dt><strong>Týdenní</strong></dt>\n<dd>Totéž, doba expozice delší, přibližně 20 min.</dd>\n<dt><strong>Sanitární den</strong> — min. 1× za 3 měsíce</dt>\n<dd>Generální úklid veškerého zařízení — obkladů, stěn, oken, dezinfekce kynáren, strojního zařízení, mrazících boxů, dveří atd.</dd>\n</dl>\n<p><strong>Pro jakýkoliv úklid a mytí musí být dostatek teplé vody!</strong></p>\n\n<h4>Pracovní nádobí</h4>\n<p>Kotle, metly atd. uloženy v místnosti k tomu určené. Nesmí být uloženy na podlaze.</p>\n<p>Mytí — po každém znečištění (hrubé nečistoty, pak voda s dezinfekčním prostředkem, nechat působit 30 min., oplach čistou vodou).</p>\n<p>Sáčky na plnění nutno denně vyvarovat, pozor na koncové otvory a kroužky.</p>\n\n<h4>Údržbářské práce</h4>\n<p>Zejména práce s ředidly, bílení — musí být vykonávány <strong>mimo pracovní dobu</strong>, aby nedocházelo k případnému ovlivnění smyslových, chemických nebo fyzikálních vlastností výrobků. Ve skladech za nepřítomnosti surovin a výrobků.</p>",
        ],
        [
            'kategorie' => 'sanitacni_rad',
            'nazev' => '6. Odpady, expedice, voda, havárie',
            'poradi' => 6,
            'obsah' => "<h3>6. Odpady</h3>\n<p>Veškerý odpad z výroby (papíry, plechovky, sklo, zbytky z výroby včetně zbytků surovin, náplní, nevyhovujících výrobků) musí být skladován <strong>odděleně v určené místnosti mimo výrobní prostory</strong>, v nádobách k tomu určených, uzavřených.</p>\n<ul>\n<li>Skořápky od vajec, popř. obaly od melanže jsou uloženy odděleně ve sklepních prostorech</li>\n<li>Odvoz odpadů zajištěn firmou <strong>AVE — četnost 2× týdně</strong></li>\n<li>Doklady o odvozu odpadů uloženy u jednatele společnosti</li>\n</ul>\n\n<h3>Chladnutí výrobků</h3>\n<p>Prováděno v prostorách provozovny na vozících nebo v přepravkách (chléb, rohlíky), kromě cukrářských — nutné ihned po dohotovení uložit do chladícího zařízení.</p>\n<p>Nesmí docházet ke znečištění ptactvem, psy, hmyzem, hlodavci, zaprášení.</p>\n\n<h3>Expedice výrobků</h3>\n<ul>\n<li>Výrobky uloženy v <strong>čistých přepravkách</strong> v prostorách expedice</li>\n<li>U cukr. výr. vyložených papírem, používaných pouze k tomuto účelu</li>\n<li>Přepravky s výrobky <strong>nesmí být uloženy na zemi</strong></li>\n<li>Při předávání k rozvozu — krytá rampa, zamezení zaprášení, ptactva, prodlev (zejména u cukr. výrobků)</li>\n</ul>\n\n<h3>Mytí přepravek</h3>\n<p>Musí být prováděno u všech přepravek dle potřeby. Mytí ručně nebo myčkou. <strong>Evidence o mytí</strong>: kolik, kdy, podpis.</p>\n\n<h3>Uložení čistících prostředků</h3>\n<p>Čistící prostředky jsou uloženy v kuchyňské lince, zvlášť, čitelně popsané.</p>\n\n<h3>Uložení obalů</h3>\n<p>Obaly jsou uloženy v bezprašném prostředí, mimo dosah ptactva a domácího zvířectva, tak aby nedocházelo k jejich znečištění.</p>\n\n<h3>Kontrola vody</h3>\n<p>Atesty od OÚ, kontrola výtoku vody na provozovně — kontrolovat postupně u všech kohoutků, čistění. Atesty <strong>1× za 5 let</strong>, zajišťuje hygienická stanice K. Vary.</p>\n<p><em>Zodpovídá vždy vedoucí provozu.</em></p>\n\n<h3>Havárie</h3>\n<p>Při výpadku el. energie, nebo při jiných závažných havarijních situacích se těsto již dále nezpracovává. Výrobky poškozené a ovlivněné havárií se likvidují.</p>\n<p><em>Za likvidaci poškozených výrobků zodpovídá vždy vedoucí provozu.</em></p>",
        ],

        // ============== VSTUPNÍ INSTRUKTÁŽ HYGIENA ==============
        [
            'kategorie' => 'instruktaz_hygieny',
            'nazev' => '1. Vstupní instruktáž osobní hygieny',
            'poradi' => 1,
            'obsah' => "<h2>Vstupní instruktáž — Osobní hygiena</h2>\n<p style=\"color:#666;font-style:italic\">Opakované školení: perioda 24 měsíců</p>\n\n<h3>Konkrétní zásady osobní hygieny při práci s potravinami</h3>\n\n<h4>1. Hygiena rukou</h4>\n<p>Každý pracovník při nástupu do zaměstnání si <strong>před zahájením a během pracovní doby</strong> umyje ruce teplou vodou a mýdlem a použije k usušení papírové utěrky umístěné v zásobníku nad umyvadlem.</p>\n\n<h4>2. Pracovní oděv</h4>\n<p>Každý pracovník se před vstupem do výrobny převlékne v šatně do <strong>čistého pracovního oděvu</strong>. Pracovní oděv vymění dle potřeby a znečištění, nejdéle však jednou za dvě pracovní směny.</p>\n<p>Pracovní oděv musí být z materiálu, který se dá vyvařit / vyprat. Zaměstnanec dostává pracovní oděv každé <strong>dva roky</strong> nebo dle potřeby. Jsou poučeni, jak doma prát odděleně pracovní oděv.</p>\n\n<h4>3. Vlasy, ruce a nehty</h4>\n<p>Každý pracovník bude mít po dobu pracovní doby <strong>vlasy upravené a svázané</strong>, aby zamezil padání vlasu do potravin. Nehty bude mít každý pracovník upravené, čisté, nenalakované, a ruce vždy čisté a <strong>bez hodinek a šperků</strong>.</p>\n\n<h4>4. Poranění rukou</h4>\n<p>Pokud si pracovník poraní ruce, použije náplast a bude vždy při práci s potravinami používat <strong>pracovní rukavice</strong>.</p>\n\n<h4>5. Nemoci</h4>\n<p>Pokud má pracovník <strong>podezření na infekční nemoci</strong>, nahlásí to ihned zaměstnavateli a navštíví praktického lékaře.</p>\n\n<h4>6. Obecné</h4>\n<p>Každý pracovník je povinen dbát na dodržování osobní a pracovní hygieny. Pracovní oděv bude umístěn v šatně provozovny vždy <strong>odděleně od oděvů civilních</strong>.</p>",
        ],

        // ============== POSTUPY CCP ==============
        [
            'kategorie' => 'postupy_ccp',
            'nazev' => 'Postup prodejce pro příjem a prodej pečiva',
            'poradi' => 1,
            'obsah' => "<h2>Postup prodejce pro příjem a prodej pečiva (CCP)</h2>\n\n<h3>1. Příjem pečiva od pekárny</h3>\n<ul>\n<li>Kontrola <strong>čistoty přepravek</strong> — bez plísní, posypu, mechanického znečištění</li>\n<li>Kontrola <strong>množství</strong> dle dodacího listu</li>\n<li>Kontrola <strong>vzhledu</strong> — propečenost, žádné popraskaní/spáleniny, žádné zaplísnění</li>\n<li>Záznam příjmu do provozního deníku (datum, čas, počet kusů, podpis)</li>\n</ul>\n\n<h3>2. Ukládání zboží na prodejnu</h3>\n<ul>\n<li>Pečivo se ukládá do <strong>čistých regálů a košů</strong> oddělených od jiných potravin</li>\n<li>Dodržovat zásadu <strong>FIFO</strong> (first-in, first-out) — starší zboží do popředí</li>\n<li>Teplota prodejny <strong>do 25 °C</strong>, vlhkost &lt; 70 %</li>\n<li>Zboží nesmí být uloženo na zemi ani v dosahu zákazníka přímou rukou (zabalené nebo přes podávací nářadí)</li>\n</ul>\n\n<h3>3. Prodej</h3>\n<ul>\n<li>Prodejce má <strong>zdravotní průkaz</strong>, čisté oblečení, pokrývku hlavy</li>\n<li>Při manipulaci s pečivem nutné použít <strong>kleště, papír nebo rukavice</strong></li>\n<li>Pří dotazu zákazníka — sdělit alergeny (informace na obalu / cenovce)</li>\n<li>Prošlé / nevyhovující zboží <strong>okamžitě stáhnout z prodeje</strong></li>\n</ul>\n\n<h3>4. Likvidace</h3>\n<p>Neprodané pečivo na konci dne — likvidace dle interních pravidel (odepsat do evidence, oddělit do určeného koše).</p>\n\n<h3>Záznam o školení</h3>\n<table border=\"1\" cellpadding=\"6\" style=\"border-collapse:collapse;width:100%\">\n<tr style=\"background:#f5f5f0\"><th>Jméno</th><th>Profese</th><th>Datum</th><th>Podpis</th><th>Školil</th><th>Podpis školitele</th></tr>\n<tr><td></td><td></td><td></td><td></td><td></td><td></td></tr>\n<tr><td></td><td></td><td></td><td></td><td></td><td></td></tr>\n<tr><td></td><td></td><td></td><td></td><td></td><td></td></tr>\n</table>",
        ],

        // ============== FORMULÁŘE ==============
        [
            'kategorie' => 'formulare',
            'nazev' => 'Odpovědnost za potraviny — provozovatelé potr. podniků',
            'poradi' => 1,
            'obsah' => "<h2>Odpovědnost za potraviny: provozovatelé potravinářských podniků</h2>\n\n<ol>\n<li><p>Jestliže se provozovatel potravinářského podniku domnívá nebo má důvod se domnívat, že potravina, kterou dovezl, vyprodukoval, zpracoval, vyrobil nebo distribuoval, <strong>není v souladu s požadavky na bezpečnost potravin</strong>, neprodleně přistoupí ke <strong>stažení dotyčné potraviny z trhu</strong>, pokud tato potravina již není pod bezprostřední kontrolou tohoto původního provozovatele potravinářského podniku, a uvědomí o tom příslušné orgány.</p>\n<p>Jestliže se již produkt mohl dostat ke spotřebiteli, provozovatel účinně a přesně <strong>informuje spotřebitele</strong> o důvodu jeho stažení, a je-li to nezbytné, převezme zpět od spotřebitelů již dodané produkty, nestačí-li k dosažení vysoké úrovně ochrany zdraví jiná opatření.</p></li>\n\n<li><p>Provozovatel potravinářského podniku odpovědný za maloobchodní nebo distribuční činnost, která nemá vliv na balení, označování, bezpečnost nebo neporušenost potraviny, zahájí v mezích své činnosti postupy, jimiž se z trhu stahují výrobky nesplňující požadavky na bezpečnost potravin, a přispívá k bezpečnosti potraviny tím, že předá významné informace nezbytné ke <strong>sledování potraviny</strong>, přičemž spolupracuje na opatřeních producentů, zpracovatelů, výrobců nebo příslušných orgánů.</p></li>\n\n<li><p>Provozovatel potravinářského podniku <strong>neprodleně uvědomí příslušné orgány</strong>, pokud se domnívá nebo má důvod se domnívat, že potravina, kterou uvedl na trh, může být škodlivá pro lidské zdraví. Provozovatel uvědomí příslušné orgány o opatřeních, která přijal s cílem předejít riziku pro konečného spotřebitele, a nebrání žádné osobě ani žádnou osobu neodrazuje od toho, aby v souladu s vnitrostátními právními předpisy a právní praxí <strong>spolupracovala s příslušnými orgány</strong>, lze-li tím předejít riziku spojenému s potravinou nebo toto riziko zmenšit či vyloučit.</p></li>\n</ol>",
        ],
        [
            'kategorie' => 'formulare',
            'nazev' => 'Rozbor vody',
            'poradi' => 2,
            'obsah' => "<h2>Rozbor vody</h2>\n\n<p>Atesty rozboru vody jsou pravidelně zajištěny:</p>\n<ul>\n<li><strong>Periodicita</strong>: 1× za 5 let</li>\n<li><strong>Zajišťuje</strong>: místně příslušná hygienická stanice</li>\n<li><strong>Kontrolováno</strong>: postupně u všech kohoutků v provozovně</li>\n</ul>\n\n<h3>Záznamy o rozborech vody</h3>\n<table border=\"1\" cellpadding=\"6\" style=\"border-collapse:collapse;width:100%\">\n<tr style=\"background:#f5f5f0\">\n<th>Datum rozboru</th><th>Místo odběru</th><th>Provedl</th><th>Výsledek</th><th>Příští rozbor</th><th>Podpis</th>\n</tr>\n<tr><td></td><td></td><td></td><td></td><td></td><td></td></tr>\n<tr><td></td><td></td><td></td><td></td><td></td><td></td></tr>\n<tr><td></td><td></td><td></td><td></td><td></td><td></td></tr>\n</table>\n\n<p style=\"margin-top:20px;font-size:11pt;color:#666\"><em>Atesty od OÚ uloženy u jednatele společnosti.</em></p>",
        ],
        [
            'kategorie' => 'formulare',
            'nazev' => 'Záznam o příjmu surovin (vysledovatelnost)',
            'poradi' => 3,
            'obsah' => "<h2>Záznam o příjmu surovin a obalů</h2>\n<p style=\"font-size:11pt;color:#666;margin-bottom:14px\"><em>Vedeno dle: čl. 18 nařízení (ES) č. 178/2002 (vysledovatelnost) · čl. 5 nař. 852/2004 · § 3 zák. 110/1997 Sb.</em></p>\n\n<h3>Povinné údaje při příjmu</h3>\n<ul>\n<li>Datum a čas převzetí</li>\n<li>Identifikace dodavatele (název, IČO)</li>\n<li>Druh suroviny + šarže/lot</li>\n<li>Množství + jednotka</li>\n<li>DMT / DP (datum minimální trvanlivosti / doba použitelnosti)</li>\n<li>Teplota chlazených surovin (povinné, ≤ 8 °C)</li>\n<li>Stav obalu, organoleptické hodnocení (vyhovuje / nevyhovuje)</li>\n<li>Číslo dodacího listu / faktury</li>\n<li>Podpis přebírajícího</li>\n</ul>\n\n<h3>Měsíc / rok: ______________</h3>\n<table border=\"1\" cellpadding=\"5\" style=\"border-collapse:collapse;width:100%;font-size:10pt\">\n<thead style=\"background:#f5f5f0\">\n<tr>\n<th>Datum</th><th>Dodavatel + IČO</th><th>Surovina</th><th>Šarže</th><th>Množství</th><th>DMT/DP</th><th>T °C</th><th>Stav</th><th>DL/FA č.</th><th>Podpis</th>\n</tr>\n</thead>\n<tbody>\n" . str_repeat("<tr><td>&nbsp;</td><td></td><td></td><td></td><td></td><td></td><td></td><td></td><td></td><td></td></tr>\n", 20) . "</tbody>\n</table>\n\n<p style=\"margin-top:14px;font-size:11pt;color:#666\"><em>Záznamy uchovávat <strong>min. 2 roky</strong> pro kontrolu SZPI / KHS. Při zjištění závady — neprodleně reklamace u dodavatele.</em></p>",
        ],
        [
            'kategorie' => 'formulare',
            'nazev' => 'Záznam teplot chladicích a mrazicích zařízení',
            'poradi' => 4,
            'obsah' => "<h2>Záznam teplot chladicích a mrazicích zařízení</h2>\n<p style=\"font-size:11pt;color:#666;margin-bottom:14px\"><em>Vedeno dle: nař. (ES) č. 852/2004 příl. II kap. IX · vyhl. 18/2024 Sb. · zákon 110/1997 Sb.</em></p>\n\n<h3>Kritické meze (CCP/CP)</h3>\n<ul>\n<li><strong>Chladící zařízení:</strong> +2 až +8 °C (mléčné, vejce, droždí, tuky, masné výrobky)</li>\n<li><strong>Mrazící zařízení:</strong> −18 °C nebo méně</li>\n<li><strong>Frekvence měření:</strong> 2× denně (ráno + odpoledne)</li>\n<li><strong>Při překročení limitu:</strong> posoudit zdravotní nezávadnost, případně přesun do jiného zařízení nebo likvidace + záznam nápravného opatření</li>\n</ul>\n\n<h3>Měsíc / rok: ______________</h3>\n<table border=\"1\" cellpadding=\"5\" style=\"border-collapse:collapse;width:100%;font-size:10pt\">\n<thead style=\"background:#f5f5f0\">\n<tr>\n<th rowspan=\"2\">Den</th>\n<th colspan=\"2\">Lednice 1 (2–8 °C)</th>\n<th colspan=\"2\">Lednice 2 (2–8 °C)</th>\n<th colspan=\"2\">Mraznička (≤ −18 °C)</th>\n<th rowspan=\"2\">Nápravné opatření / podpis</th>\n</tr>\n<tr>\n<th>Ráno</th><th>Odp.</th><th>Ráno</th><th>Odp.</th><th>Ráno</th><th>Odp.</th>\n</tr>\n</thead>\n<tbody>\n" . implode("\n", array_map(fn($d) => "<tr><td><strong>$d</strong></td><td></td><td></td><td></td><td></td><td></td><td></td><td></td></tr>", range(1, 31))) . "\n</tbody>\n</table>\n\n<p style=\"margin-top:14px;font-size:11pt;color:#666\"><em>Pokud teplota mimo limit — okamžitě zaznamenat do <strong>Záznamu nápravných opatření</strong> + foto. Servis chlazení dokumentovat.</em></p>",
        ],
        [
            'kategorie' => 'formulare',
            'nazev' => 'Záznam sanitace (denní/týdenní/sanitární den)',
            'poradi' => 5,
            'obsah' => "<h2>Záznam o provedené sanitaci</h2>\n<p style=\"font-size:11pt;color:#666;margin-bottom:14px\"><em>Vedeno dle: nař. (ES) č. 852/2004 příl. II kap. I-II · vyhl. 18/2024 Sb. · sanitační řád</em></p>\n\n<h3>Plán sanitace</h3>\n<table border=\"1\" cellpadding=\"6\" style=\"border-collapse:collapse;width:100%;font-size:10.5pt\">\n<thead style=\"background:#f5f5f0\">\n<tr><th>Frekvence</th><th>Co</th><th>Postup / prostředek</th></tr>\n</thead>\n<tbody>\n<tr><td><strong>Denně</strong></td><td>Pracovní plochy, stoly, vozíky, podlahy</td><td>Mýdlový roztok → oplach → dezinfekce (např. SAVO 1:50) → oplach pitnou vodou</td></tr>\n<tr><td>Denně</td><td>WC, umyvadla, šatny</td><td>Dezinfekce, expozice 30 min., oplach</td></tr>\n<tr><td><strong>Týdně</strong></td><td>Pec, kynárna, dveře, parapety, regály</td><td>Studená dezinfekce, expozice 20 min., oplach</td></tr>\n<tr><td><strong>Měsíčně</strong></td><td>Stěny do 1,8 m, mrazící boxy (rozmraz), ventilace</td><td>Generální čištění + dezinfekce</td></tr>\n<tr><td><strong>1× za 3 měsíce</strong></td><td>Sanitární den — kompletní generální úklid</td><td>Veškeré obklady, stěny, okna, strojní zařízení, kynárny, dveře</td></tr>\n<tr><td><strong>1× ročně</strong></td><td>Bílení provozu, mytí oken (zvenku)</td><td>Mimo pracovní dobu, suroviny chráněny</td></tr>\n<tr><td><strong>2× ročně</strong></td><td>Deratizace / dezinsekce (DDD)</td><td>Externí firma — záznam!</td></tr>\n</tbody>\n</table>\n\n<h3>Evidence provedené sanitace — měsíc / rok: ______________</h3>\n<table border=\"1\" cellpadding=\"5\" style=\"border-collapse:collapse;width:100%;font-size:10pt\">\n<thead style=\"background:#f5f5f0\">\n<tr><th>Datum</th><th>Co bylo provedeno</th><th>Použitý prostředek + koncentrace</th><th>Provedl (jméno)</th><th>Kontrola provedl</th><th>Podpis</th></tr>\n</thead>\n<tbody>\n" . str_repeat("<tr><td>&nbsp;</td><td></td><td></td><td></td><td></td><td></td></tr>\n", 18) . "</tbody>\n</table>\n\n<p style=\"margin-top:14px;font-size:11pt;color:#666\"><em>Dezinfekční prostředky obměňovat v <strong>2-měsíčních intervalech</strong> (rezistence). Bezpečnostní listy (MSDS) musí být uloženy u jednatele.</em></p>",
        ],
        [
            'kategorie' => 'formulare',
            'nazev' => 'Evidence DDD (Deratizace, Dezinsekce, Dezinfekce)',
            'poradi' => 6,
            'obsah' => "<h2>Evidence DDD — Deratizace, Dezinsekce, Dezinfekce</h2>\n<p style=\"font-size:11pt;color:#666;margin-bottom:14px\"><em>Vedeno dle: zák. 258/2000 Sb. § 57 · nař. (ES) č. 852/2004 příl. II kap. IX odst. 4 · vyhl. 18/2024 Sb.</em></p>\n\n<h3>Povinnosti provozovatele</h3>\n<ul>\n<li>Provádět ochranné DDD pravidelně, <strong>min. 2× ročně</strong> nebo dle potřeby</li>\n<li>Provádí <strong>oprávněná osoba</strong> (kurz DDD dle zák. 258/2000 §58) nebo certifikovaná firma</li>\n<li>Při provádění zaznamenat: datum, použitý přípravek (název, číslo registrace), množství, lokalitu, podpis</li>\n<li>Uchovávat <strong>bezpečnostní listy (MSDS)</strong> všech použitých přípravků</li>\n<li>Záznamy uchovávat <strong>min. 2 roky</strong></li>\n</ul>\n\n<h3>Evidence provedených DDD zásahů — rok: ______________</h3>\n<table border=\"1\" cellpadding=\"6\" style=\"border-collapse:collapse;width:100%;font-size:10pt\">\n<thead style=\"background:#f5f5f0\">\n<tr><th>Datum</th><th>Typ (DD/DI/DZ)</th><th>Cílový škůdce</th><th>Přípravek + č. reg.</th><th>Množství</th><th>Místo aplikace</th><th>Provedl (firma + IČO)</th><th>Příští zásah</th><th>Podpis</th></tr>\n</thead>\n<tbody>\n" . str_repeat("<tr><td>&nbsp;</td><td></td><td></td><td></td><td></td><td></td><td></td><td></td><td></td></tr>\n", 8) . "</tbody>\n</table>\n\n<p style=\"margin-top:12px;font-size:11pt\"><strong>Legenda:</strong> DD = deratizace (hlodavci) · DI = dezinsekce (hmyz) · DZ = dezinfekce (mikroorganismy)</p>\n\n<h3>Plán rozmístění nástrah (deratizace)</h3>\n<table border=\"1\" cellpadding=\"6\" style=\"border-collapse:collapse;width:100%;font-size:10pt\">\n<thead style=\"background:#f5f5f0\">\n<tr><th>Číslo nástrahy</th><th>Lokalita</th><th>Použitý přípravek</th><th>Stav kontroly (datum + výsledek)</th></tr>\n</thead>\n<tbody>\n" . str_repeat("<tr><td>&nbsp;</td><td></td><td></td><td></td></tr>\n", 6) . "</tbody>\n</table>\n\n<p style=\"margin-top:12px;font-size:11pt;color:#666\"><em>Smlouvy s DDD firmou, certifikáty oprávněnosti a MSDS uloženy u jednatele společnosti.</em></p>",
        ],
        [
            'kategorie' => 'formulare',
            'nazev' => 'Evidence zdravotních průkazů zaměstnanců',
            'poradi' => 7,
            'obsah' => "<h2>Evidence zdravotních průkazů zaměstnanců</h2>\n<p style=\"font-size:11pt;color:#666;margin-bottom:14px\"><em>Vedeno dle: § 19 zák. č. 258/2000 Sb. o ochraně veřejného zdraví · vyhláška č. 79/2013 Sb. (pracovnělékařské služby)</em></p>\n\n<h3>Povinnosti</h3>\n<ul>\n<li>Každý pracovník vykonávající <strong>činnost epidemiologicky závažnou</strong> (potraviny, pokrmy, kosmetické služby, péče o dítě) musí mít <strong>platný zdravotní průkaz</strong></li>\n<li>Lékařská prohlídka <strong>před nástupem</strong> a periodicky <strong>1× za 2 roky</strong> (u rizikových pracovníků dle vyhl. 79/2013 kratší interval)</li>\n<li>Zaměstnavatel vede evidenci, kontroluje platnost</li>\n<li>Při prošlém průkazu — pracovník <strong>nesmí pokračovat v práci</strong>, dokud nebude obnoven</li>\n</ul>\n\n<h3>Evidence — rok: ______________</h3>\n<table border=\"1\" cellpadding=\"6\" style=\"border-collapse:collapse;width:100%;font-size:10pt\">\n<thead style=\"background:#f5f5f0\">\n<tr><th>Jméno a příjmení</th><th>Funkce</th><th>Datum nástupu</th><th>Datum prohlídky</th><th>Platnost do</th><th>Lékař / poskytovatel</th><th>Číslo průkazu</th><th>Podpis zaměstnance</th></tr>\n</thead>\n<tbody>\n" . str_repeat("<tr><td>&nbsp;</td><td></td><td></td><td></td><td></td><td></td><td></td><td></td></tr>\n", 14) . "</tbody>\n</table>\n\n<p style=\"margin-top:14px;font-size:11pt;color:#666\"><em>Originální zdravotní průkazy uchovávají zaměstnanci. Provozovatel vede pouze tuto evidenci pro kontrolu KHS. Při kontrole hygieny musí být průkazy k dispozici.</em></p>",
        ],
        [
            'kategorie' => 'formulare',
            'nazev' => 'Záznam nápravných opatření (CCP / kontroly)',
            'poradi' => 8,
            'obsah' => "<h2>Záznam o nápravných opatřeních</h2>\n<p style=\"font-size:11pt;color:#666;margin-bottom:14px\"><em>Vedeno dle: čl. 5 odst. 2 písm. d) nař. (ES) č. 852/2004 (HACCP — zásada 5)</em></p>\n\n<h3>Postup při zjištění odchylky od kritické meze</h3>\n<ol>\n<li>Okamžitě <strong>zastavit / izolovat</strong> nevyhovující výrobek</li>\n<li>Identifikovat <strong>příčinu</strong> odchylky (lidská chyba, technika, surovina, prostředí)</li>\n<li>Provést <strong>okamžité nápravné opatření</strong> (dopečení, likvidace, přesun)</li>\n<li>Stanovit <strong>preventivní opatření</strong>, aby se neopakovalo</li>\n<li>Zaznamenat do tohoto formuláře</li>\n<li>Posoudit dopad na další výrobu / zdravotní nezávadnost</li>\n<li>Při riziku pro spotřebitele — postupovat dle <strong>Recall záznamu</strong> (čl. 19 nař. 178/2002)</li>\n</ol>\n\n<h3>Záznamy — rok: ______________</h3>\n<table border=\"1\" cellpadding=\"6\" style=\"border-collapse:collapse;width:100%;font-size:10pt\">\n<thead style=\"background:#f5f5f0\">\n<tr><th>Datum</th><th>Místo / CCP</th><th>Odchylka (co bylo špatně)</th><th>Příčina</th><th>Okamžité opatření</th><th>Preventivní opatření</th><th>Dopad / posouzení</th><th>Provedl + podpis</th><th>Kontrolovala</th></tr>\n</thead>\n<tbody>\n" . str_repeat("<tr style=\"height:38px\"><td></td><td></td><td></td><td></td><td></td><td></td><td></td><td></td><td></td></tr>\n", 8) . "</tbody>\n</table>\n\n<p style=\"margin-top:14px;font-size:11pt;color:#666\"><em>Záznam je povinná součást HACCP systému. Při kontrole SZPI / KHS musí být k dispozici.</em></p>",
        ],
        [
            'kategorie' => 'formulare',
            'nazev' => 'Recall — stažení výrobku z trhu',
            'poradi' => 9,
            'obsah' => "<h2>Záznam o stažení výrobku z trhu (Recall)</h2>\n<p style=\"font-size:11pt;color:#666;margin-bottom:14px\"><em>Vedeno dle: čl. 19 nař. (ES) č. 178/2002 (povinnost stažení nebezpečné potraviny)</em></p>\n\n<h3>Kdy provést stažení (Recall)</h3>\n<p>Provozovatel <strong>neprodleně</strong> přistoupí ke stažení potraviny z trhu, jestliže se domnívá nebo má důvod se domnívat, že potravina:</p>\n<ul>\n<li>Není v souladu s <strong>požadavky na bezpečnost potravin</strong> (čl. 14 nař. 178/2002)</li>\n<li>Je nebo může být <strong>škodlivá pro lidské zdraví</strong></li>\n<li>Je <strong>nevhodná k lidské spotřebě</strong></li>\n</ul>\n\n<h3>Povinné kroky</h3>\n<ol>\n<li>Identifikovat dotčené šarže (vysledovatelnost dle čl. 18 nař. 178/2002)</li>\n<li>Uvědomit <strong>příslušné orgány</strong> (SZPI / KVS / KHS) — <strong>do 24 hodin</strong></li>\n<li>Stáhnout výrobek od distributorů a maloodběratelů</li>\n<li>Pokud se výrobek mohl dostat ke spotřebiteli — <strong>informovat veřejnost</strong> (web, tisk, sociální sítě)</li>\n<li>Přijmout výrobek zpět od spotřebitelů</li>\n<li>Likvidovat výrobek odbornou cestou</li>\n<li>Analyzovat příčinu + preventivní opatření</li>\n</ol>\n\n<h3>Záznam o stažení</h3>\n<table border=\"1\" cellpadding=\"6\" style=\"border-collapse:collapse;width:100%;font-size:11pt\">\n<tr style=\"background:#f5f5f0\"><td style=\"width:35%\"><strong>Datum a čas zjištění problému</strong></td><td></td></tr>\n<tr><td style=\"background:#f5f5f0\"><strong>Kdo zjistil (jméno, funkce)</strong></td><td></td></tr>\n<tr><td style=\"background:#f5f5f0\"><strong>Identifikace výrobku</strong></td><td></td></tr>\n<tr><td style=\"background:#f5f5f0\"><strong>Šarže (lot, DMT/DP)</strong></td><td></td></tr>\n<tr><td style=\"background:#f5f5f0\"><strong>Vyrobeno (datum, dávka)</strong></td><td></td></tr>\n<tr><td style=\"background:#f5f5f0\"><strong>Množství vyrobené / expedované</strong></td><td></td></tr>\n<tr><td style=\"background:#f5f5f0\"><strong>Příčina závady (B/CH/F/A)</strong></td><td></td></tr>\n<tr><td style=\"background:#f5f5f0\"><strong>Riziko pro zdraví</strong></td><td></td></tr>\n<tr><td style=\"background:#f5f5f0\"><strong>Distribuční místa (odběratelé)</strong></td><td></td></tr>\n<tr><td style=\"background:#f5f5f0\"><strong>Uvědomené orgány (datum + kontakt)</strong></td><td></td></tr>\n<tr><td style=\"background:#f5f5f0\"><strong>Informace pro spotřebitele (kde + jak)</strong></td><td></td></tr>\n<tr><td style=\"background:#f5f5f0\"><strong>Stažené množství</strong></td><td></td></tr>\n<tr><td style=\"background:#f5f5f0\"><strong>Likvidace (firma + datum)</strong></td><td></td></tr>\n<tr><td style=\"background:#f5f5f0\"><strong>Preventivní opatření</strong></td><td></td></tr>\n<tr><td style=\"background:#f5f5f0\"><strong>Datum + podpis jednatele</strong></td><td></td></tr>\n</table>\n\n<h3>Kontakty kontrolních orgánů</h3>\n<ul>\n<li><strong>SZPI</strong> (Státní zemědělská a potravinářská inspekce) — <a href=\"https://www.szpi.gov.cz\">www.szpi.gov.cz</a> · linka 542 426 633</li>\n<li><strong>KVS</strong> (Krajská veterinární správa) — místně příslušná dle adresy provozu</li>\n<li><strong>KHS</strong> (Krajská hygienická stanice) — místně příslušná</li>\n<li><strong>RASFF</strong> (Rapid Alert System for Food and Feed) — pro EU varování</li>\n</ul>\n\n<p style=\"margin-top:14px;font-size:11pt;color:#666\"><em>Provozovatel nesmí <strong>žádné osobě bránit</strong> ve spolupráci s příslušnými orgány. Záznam o stažení uchovávat trvale.</em></p>",
        ],
        [
            'kategorie' => 'formulare',
            'nazev' => 'Evidence alergenů (značení dle 1169/2011)',
            'poradi' => 10,
            'obsah' => "<h2>Evidence alergenů ve výrobcích</h2>\n<p style=\"font-size:11pt;color:#666;margin-bottom:14px\"><em>Vedeno dle: nař. (EU) č. 1169/2011 (Food Information to Consumers) · příloha II · § 7 zák. 110/1997 Sb.</em></p>\n\n<h3>14 EU alergenů (povinné značení)</h3>\n<table border=\"1\" cellpadding=\"6\" style=\"border-collapse:collapse;width:100%;font-size:11pt\">\n<thead style=\"background:#f5f5f0\"><tr><th>Č.</th><th>Alergen</th><th>Příklady</th></tr></thead>\n<tbody>\n<tr><td>1</td><td><strong>Obiloviny obsahující lepek</strong></td><td>Pšenice, žito, ječmen, oves, špalda, kamut</td></tr>\n<tr><td>2</td><td>Korýši</td><td>Krevety, langusty, krabi</td></tr>\n<tr><td>3</td><td><strong>Vejce</strong></td><td>Slepičí, křepelčí + produkty (majonéza, vaječné krémy)</td></tr>\n<tr><td>4</td><td>Ryby</td><td>Včetně rybích produktů</td></tr>\n<tr><td>5</td><td>Jádra podzemnice olejné (arašídy)</td><td>Plnění, sypky, suvenýry</td></tr>\n<tr><td>6</td><td><strong>Sója</strong></td><td>Sójové mléko, sójová mouka, lecitin</td></tr>\n<tr><td>7</td><td><strong>Mléko</strong> a mléčné výrobky (vč. laktózy)</td><td>Mléko, smetana, sýry, máslo</td></tr>\n<tr><td>8</td><td>Skořápkové plody</td><td>Mandle, lískové, vlašské, kešu, pekan, brazilské, pistácie, makadamie</td></tr>\n<tr><td>9</td><td>Celer</td><td>Včetně sušeného, celerového kořene</td></tr>\n<tr><td>10</td><td>Hořčice</td><td>Včetně hořčičného oleje</td></tr>\n<tr><td>11</td><td><strong>Sezamová semínka</strong></td><td>Sezam, sezamový olej, tahini</td></tr>\n<tr><td>12</td><td>Oxid siřičitý / siřičitany</td><td>&gt; 10 mg/kg — sušené ovoce, víno, ocet</td></tr>\n<tr><td>13</td><td>Lupina (vlčí bob)</td><td>Lupinová mouka</td></tr>\n<tr><td>14</td><td>Měkkýši</td><td>Slávky, ústřice, šneci</td></tr>\n</tbody>\n</table>\n\n<h3>Pravidla značení (1169/2011)</h3>\n<ul>\n<li>Alergeny v <strong>seznamu složek</strong> musí být <strong>zvýrazněny</strong> (tučně, podtržení, jiné písmo)</li>\n<li>U nebalených potravin (přímý prodej, prodejna) — informace na <strong>cenovce nebo viditelně</strong> v provozu</li>\n<li>U možné <strong>křížové kontaminace</strong> uvést „Může obsahovat stopy…\"</li>\n<li>Personál musí být <strong>schopen sdělit alergeny</strong> kterého výrobku</li>\n</ul>\n\n<h3>Evidence alergenů per výrobek</h3>\n<p style=\"font-size:11pt;color:#666\"><em>Tip: tato evidence se vede v aplikaci automaticky — sekce <strong>📦 Výrobky → Alergeny</strong>. Tento formulář je pro tištěnou archivaci.</em></p>\n\n<table border=\"1\" cellpadding=\"5\" style=\"border-collapse:collapse;width:100%;font-size:9pt\">\n<thead style=\"background:#f5f5f0\">\n<tr><th>Výrobek</th><th>Lepek</th><th>Korýši</th><th>Vejce</th><th>Ryby</th><th>Arašídy</th><th>Sója</th><th>Mléko</th><th>Ořechy</th><th>Celer</th><th>Hořčice</th><th>Sezam</th><th>SO₂</th><th>Lupina</th><th>Měkkýši</th><th>Stopy</th></tr>\n</thead>\n<tbody>\n" . str_repeat("<tr><td>&nbsp;</td>" . str_repeat("<td></td>", 15) . "</tr>\n", 12) . "</tbody>\n</table>\n\n<p style=\"margin-top:14px;font-size:11pt;color:#666\"><em>Při přidání / změně receptury vždy <strong>aktualizovat evidenci</strong> a značení na cenovkách / obalech.</em></p>",
        ],
        [
            'kategorie' => 'formulare',
            'nazev' => 'Záznam o reklamacích a stížnostech zákazníků',
            'poradi' => 11,
            'obsah' => "<h2>Záznam o reklamacích a stížnostech</h2>\n<p style=\"font-size:11pt;color:#666;margin-bottom:14px\"><em>Vedeno dle: čl. 14 nař. (ES) č. 178/2002 · § 13 zák. 634/1992 Sb. (ochrana spotřebitele) · zák. 89/2012 Sb. § 2161-2174 (Občanský zákoník — vady věci)</em></p>\n\n<h3>Postup při reklamaci</h3>\n<ol>\n<li>Přijmout reklamaci (písemně nebo ústně se zápisem)</li>\n<li>Vyžádat <strong>identifikaci výrobku</strong> (název, šarže/datum výroby, místo nákupu)</li>\n<li>Vyzvat zákazníka k <strong>předání vzorku</strong> (pokud možno) — pro analýzu</li>\n<li>Posoudit závažnost — riziko pro zdraví? (pokud ano → <strong>Recall</strong>!)</li>\n<li>Vystavit <strong>písemné potvrzení o přijetí</strong> reklamace (§ 19 zák. 634/1992)</li>\n<li>Vyřídit <strong>do 30 dnů</strong> (§ 19 odst. 3 zák. 634/1992)</li>\n<li>Informovat zákazníka o výsledku</li>\n<li>Analyzovat příčinu + preventivní opatření</li>\n</ol>\n\n<h3>Evidence reklamací — rok: ______________</h3>\n<table border=\"1\" cellpadding=\"5\" style=\"border-collapse:collapse;width:100%;font-size:10pt\">\n<thead style=\"background:#f5f5f0\">\n<tr><th>Č.</th><th>Datum</th><th>Zákazník (jméno + kontakt)</th><th>Výrobek + šarže</th><th>Důvod</th><th>Posouzení</th><th>Výsledek (uznáno/zamítnuto)</th><th>Datum vyřízení</th><th>Podpis</th></tr>\n</thead>\n<tbody>\n" . implode("\n", array_map(fn($i) => "<tr><td>$i</td><td></td><td></td><td></td><td></td><td></td><td></td><td></td><td></td></tr>", range(1, 12))) . "\n</tbody>\n</table>\n\n<p style=\"margin-top:14px;font-size:11pt;color:#666\"><em>Při systematickém opakování stížností na stejný výrobek — <strong>okamžitě</strong> revize výroby + případně Recall. Záznamy archivovat <strong>min. 3 roky</strong>.</em></p>",
        ],
        [
            'kategorie' => 'formulare',
            'nazev' => '📌 Kontakty kontrolních a hygienických orgánů',
            'poradi' => 12,
            'obsah' => "<h2>Kontakty kontrolních orgánů a státních institucí</h2>\n<p style=\"font-size:11pt;color:#666;margin-bottom:14px\"><em>Aktuální k roku 2026 — vždy ověřte na oficiálních webech.</em></p>\n\n<h3>🏛️ Státní zemědělská a potravinářská inspekce (SZPI)</h3>\n<p>Hlavní kontrolní orgán nad výrobou a uváděním potravin na trh (mimo živočišné produkty).</p>\n<ul>\n<li><strong>Web:</strong> <a href=\"https://www.szpi.gov.cz\" target=\"_blank\">www.szpi.gov.cz</a></li>\n<li><strong>Ústředí:</strong> Květná 15, 603 00 Brno · tel. 542 426 633</li>\n<li><strong>E-mail:</strong> info@szpi.gov.cz · datová schránka: <strong>avraiqg</strong></li>\n<li><strong>Hlášení podezření / podnět:</strong> <a href=\"https://www.szpi.gov.cz/clanek/podnety-spotrebitelu.aspx\" target=\"_blank\">online formulář</a></li>\n</ul>\n\n<h3>🐄 Státní veterinární správa (SVS) / Krajská veterinární správa (KVS)</h3>\n<p>Kontrola živočišných produktů (maso, mléko, vejce, ryby) a krmiv.</p>\n<ul>\n<li><strong>Web:</strong> <a href=\"https://www.svscr.cz\" target=\"_blank\">www.svscr.cz</a></li>\n<li><strong>Krajské pobočky:</strong> dle adresy provozu — kontakty na webu SVS</li>\n</ul>\n\n<h3>🏥 Krajská hygienická stanice (KHS)</h3>\n<p>Kontrola hygienických podmínek, zdravotních průkazů, školení, odběr vody.</p>\n<ul>\n<li><strong>Web:</strong> <a href=\"https://www.szu.cz\" target=\"_blank\">www.szu.cz</a> (SZÚ — Státní zdravotní ústav)</li>\n<li><strong>Krajské stanice:</strong> dle místa provozu — kontakty na webech jednotlivých KHS</li>\n</ul>\n\n<h3>🛒 Česká obchodní inspekce (ČOI)</h3>\n<p>Kontrola ochrany spotřebitele, značení, klamavé praktiky.</p>\n<ul>\n<li><strong>Web:</strong> <a href=\"https://www.coi.cz\" target=\"_blank\">www.coi.cz</a></li>\n<li><strong>Ústřední inspektorát:</strong> Štěpánská 567/15, 120 00 Praha 2 · tel. 296 366 360</li>\n</ul>\n\n<h3>🚨 RASFF — EU Rapid Alert System for Food and Feed</h3>\n<p>Systém rychlého varování pro potraviny a krmiva v EU.</p>\n<ul>\n<li><strong>Web:</strong> <a href=\"https://webgate.ec.europa.eu/rasff-window\" target=\"_blank\">webgate.ec.europa.eu/rasff-window</a></li>\n<li><strong>Národní kontaktní bod:</strong> SZPI (info@szpi.gov.cz)</li>\n</ul>\n\n<h3>📞 Důležité telefonní linky</h3>\n<table border=\"1\" cellpadding=\"6\" style=\"border-collapse:collapse;width:100%;font-size:11pt\">\n<tr style=\"background:#f5f5f0\"><th style=\"width:60%\">Instituce / účel</th><th>Telefon</th></tr>\n<tr><td>Tísňová linka (Evropa)</td><td><strong>112</strong></td></tr>\n<tr><td>Záchranná služba</td><td>155</td></tr>\n<tr><td>Hasiči (havárie, požár)</td><td>150</td></tr>\n<tr><td>Otravy + hlášení toxinů (TIS)</td><td>224 919 293 · 224 915 402</td></tr>\n<tr><td>SZPI ústředí</td><td>542 426 633</td></tr>\n<tr><td>SÚKL (Státní ústav kontroly léčiv)</td><td>272 185 111</td></tr>\n</table>\n\n<h3>🌐 Užitečné odkazy</h3>\n<ul>\n<li><a href=\"https://eur-lex.europa.eu\" target=\"_blank\">EUR-Lex</a> — EU legislativa (178/2002, 852/2004, 1169/2011…)</li>\n<li><a href=\"https://www.zakonyprolidi.cz\" target=\"_blank\">zákonyprolidi.cz</a> — Sbírka zákonů ČR (vyhl. 18/2024, zák. 110/1997, 258/2000)</li>\n<li><a href=\"https://www.efsa.europa.eu\" target=\"_blank\">EFSA</a> — Evropský úřad pro bezpečnost potravin</li>\n<li><a href=\"https://www.codexalimentarius.org\" target=\"_blank\">Codex Alimentarius</a> — mezinárodní standardy</li>\n</ul>",
        ],

        // ============== ZÁZNAMY ŠKOLENÍ ==============
        [
            'kategorie' => 'skoleni',
            'nazev' => 'Záznam o školení sanitačního řádu',
            'poradi' => 1,
            'obsah' => "<h2>Záznam o provedeném školení sanitačního řádu</h2>\n<p><strong>Školení provedl:</strong> </p>\n<p><em>Potvrzuji svým podpisem, že jsem se celého školení zúčastnil a byl jsem seznámen se sanitačním řádem.</em></p>\n\n<table border=\"1\" cellpadding=\"6\" style=\"border-collapse:collapse;width:100%\">\n<tr style=\"background:#f5f5f0\">\n<th>Jméno</th><th>Profese</th><th>Datum</th><th>Podpis</th><th>Školitel</th><th>Podpis školitele</th>\n</tr>\n<tr><td></td><td>pekař</td><td></td><td></td><td></td><td></td></tr>\n<tr><td></td><td>pekař</td><td></td><td></td><td></td><td></td></tr>\n<tr><td></td><td>pekař</td><td></td><td></td><td></td><td></td></tr>\n<tr><td></td><td>pekař</td><td></td><td></td><td></td><td></td></tr>\n<tr><td></td><td>pekař</td><td></td><td></td><td></td><td></td></tr>\n<tr><td></td><td>pekař</td><td></td><td></td><td></td><td></td></tr>\n<tr><td></td><td></td><td></td><td></td><td></td><td></td></tr>\n<tr><td></td><td></td><td></td><td></td><td></td><td></td></tr>\n<tr><td></td><td></td><td></td><td></td><td></td><td></td></tr>\n<tr><td></td><td></td><td></td><td></td><td></td><td></td></tr>\n<tr><td></td><td></td><td></td><td></td><td></td><td></td></tr>\n<tr><td></td><td></td><td></td><td></td><td></td><td></td></tr>\n</table>",
        ],
        [
            'kategorie' => 'skoleni',
            'nazev' => 'Záznam o školení HACCP',
            'poradi' => 2,
            'obsah' => "<h2>Školení zaměstnanců [Vaše firma s.r.o.] — HACCP</h2>\n<p><strong>Byl jsem proškolen na HACCP dne:</strong> _______________________</p>\n\n<table border=\"1\" cellpadding=\"6\" style=\"border-collapse:collapse;width:100%\">\n<tr style=\"background:#f5f5f0\">\n<th>Jméno</th><th>Datum</th><th>Podpis</th><th>Školil</th>\n</tr>\n<tr><td></td><td></td><td></td><td></td></tr>\n<tr><td></td><td></td><td></td><td></td></tr>\n<tr><td></td><td></td><td></td><td></td></tr>\n<tr><td></td><td></td><td></td><td></td></tr>\n<tr><td></td><td></td><td></td><td></td></tr>\n<tr><td></td><td></td><td></td><td></td></tr>\n<tr><td></td><td></td><td></td><td></td></tr>\n<tr><td></td><td></td><td></td><td></td></tr>\n<tr><td></td><td></td><td></td><td></td></tr>\n<tr><td></td><td></td><td></td><td></td></tr>\n</table>\n\n<p style=\"margin-top:20px;font-size:11pt;color:#666\"><em>Pravidelná školení navazují na ověřování systému kritických bodů a jsou koordinována se školeními k hygienickému minimu, které provádí  každý rok.</em></p>",
        ],
    ];
}

// =============================================================
// AUDITY — CRUD pro evidenci ročních interních auditů
// =============================================================
if ($action === 'audity_list') {
    $rows = $pdo->query("
        SELECT id, rok, datum, auditor, vysledek, napravna_opatreni, poznamka, vytvoreno, vytvoril
        FROM haccp_audity
        ORDER BY rok DESC
    ")->fetchAll();
    json_response(['audity' => $rows]);
}

if ($method === 'POST' && $action === 'audity_add') {
    $d = json_input();
    $rok = (int) ($d['rok'] ?? date('Y'));
    if ($rok < 2000 || $rok > 2100) json_error('Neplatný rok');

    // Pokud rok už existuje, vrátíme chybu (jeden záznam na rok díky UNIQUE)
    $exists = $pdo->prepare("SELECT id FROM haccp_audity WHERE rok = :r");
    $exists->execute(['r' => $rok]);
    if ($exists->fetchColumn()) json_error('Pro rok ' . $rok . ' už audit existuje. Klikni na něj pro úpravu.', 409);

    $stmt = $pdo->prepare("
        INSERT INTO haccp_audity (rok, datum, auditor, vysledek, napravna_opatreni, poznamka, vytvoril)
        VALUES (:r, :d, :a, :v, :n, :p, :vt)
    ");
    $stmt->execute([
        'r'  => $rok,
        'd'  => $d['datum'] ?? null,
        'a'  => trim((string) ($d['auditor'] ?? '')),
        'v'  => $d['vysledek'] ?? 'v_poradku',
        'n'  => $d['napravna_opatreni'] ?? '',
        'p'  => $d['poznamka'] ?? '',
        'vt' => $_SESSION['admin_jmeno'] ?? 'admin',
    ]);
    json_response(['id' => (int) $pdo->lastInsertId(), 'rok' => $rok]);
}

if ($method === 'PUT' && $action === 'audity_update') {
    $id = (int) ($_GET['id'] ?? 0);
    if (!$id) json_error('Chybí ID');
    $d = json_input();
    $stmt = $pdo->prepare("
        UPDATE haccp_audity SET
            datum = :d, auditor = :a, vysledek = :v,
            napravna_opatreni = :n, poznamka = :p
        WHERE id = :id
    ");
    $stmt->execute([
        'd'  => $d['datum'] ?? null,
        'a'  => trim((string) ($d['auditor'] ?? '')),
        'v'  => $d['vysledek'] ?? 'v_poradku',
        'n'  => $d['napravna_opatreni'] ?? '',
        'p'  => $d['poznamka'] ?? '',
        'id' => $id,
    ]);
    json_response(['ok' => true]);
}

if ($method === 'DELETE' && $action === 'audity_delete') {
    $id = (int) ($_GET['id'] ?? 0);
    if (!$id) json_error('Chybí ID');
    $pdo->prepare("DELETE FROM haccp_audity WHERE id = :id")->execute(['id' => $id]);
    json_response(['ok' => true]);
}

// =============================================================
// 🏢 PERSONALIZACE — nahradí v textu výchozí "APPEK pekařství..."
// údaji z nastavení firmy (firma_nazev, firma_ulice, firma_mesto, firma_psc,
// firma_ico, firma_dic, firma_email, firma_telefon, firma_jednatel).
// Použito po import_default i přes endpoint ?action=personalize.
// =============================================================
function nacti_firma_pro_haccp(PDO $pdo): array {
    $klice = ['firma_nazev','firma_ulice','firma_mesto','firma_psc','firma_ico','firma_dic',
              'firma_email','firma_telefon','firma_jednatel','firma_pravni_forma','firma_web'];
    $out = [];
    foreach ($klice as $k) $out[$k] = trim((string) nastaveni_get($pdo, $k, ''));
    // Sestav adresu
    $adresa = trim($out['firma_ulice'] . ', ' . trim($out['firma_psc'] . ' ' . $out['firma_mesto']), ', ');
    $out['_adresa'] = $adresa;
    return $out;
}

function personalize_haccp_obsah(string $obsah, array $f): string {
    if (empty($f['firma_nazev'])) return $obsah; // bez nastavené firmy nech default
    // Nahraď známé hardcoded řetězce z výchozích dokumentů Appek
    $mapy = [
        '[Vaše firma s.r.o.]' => $f['firma_nazev'],
        'APPEK pekařství a cukrářství'               => $f['firma_nazev'],
        'Appek s.r.o.'                                => $f['firma_nazev'],
        'APPEK B2B'                             => $f['firma_nazev'],
        ''      => $f['_adresa'] ?: '',
        ''     => $f['_adresa'] ?: '',
    ];
    // Telefon nahraď, jen pokud je vyplněn
    if ($f['firma_telefon']) {
        $mapy['353 585 277'] = $f['firma_telefon'];
        $mapy[''] = 'Tel: ' . $f['firma_telefon'];
    }
    // Jméno jednatele (jen pokud je v nastavení)
    if ($f['firma_jednatel']) {
        $mapy['[Jednatel firmy]'] = $f['firma_jednatel'];
        $mapy['Bedřich Tauš']      = $f['firma_jednatel'];
    }
    return strtr($obsah, $mapy);
}

// Endpoint: ?action=personalize → projde všechny dokumenty a personalizuje je
if ($method === 'POST' && $action === 'personalize') {
    $f = nacti_firma_pro_haccp($pdo);
    if (empty($f['firma_nazev'])) {
        json_error('Nejprve vyplň název firmy v Nastavení → Firma a doklady', 400);
    }
    $rows = $pdo->query("SELECT id, obsah FROM haccp_dokumenty")->fetchAll();
    $upd = $pdo->prepare("UPDATE haccp_dokumenty SET obsah = :o WHERE id = :id");
    $changed = 0;
    foreach ($rows as $r) {
        $nove = personalize_haccp_obsah($r['obsah'] ?? '', $f);
        if ($nove !== $r['obsah']) {
            $upd->execute(['o' => $nove, 'id' => $r['id']]);
            $changed++;
        }
    }
    json_response(['ok' => true, 'aktualizovano' => $changed, 'firma' => $f['firma_nazev']]);
}

// =============================================================
// UPGRADE — přepsání obsahu existujících dokumentů aktualizovanou verzí
// (matchne podle kategorie + nazev; ID, datum, aktivní zachová)
// =============================================================
if ($method === 'POST' && $action === 'upgrade_obsahy') {
    $defaults = default_dokumenty();
    $existujici = $pdo->query("SELECT id, kategorie, nazev FROM haccp_dokumenty")->fetchAll();
    $upd = $pdo->prepare("UPDATE haccp_dokumenty SET obsah = :o WHERE id = :id");
    $ins = $pdo->prepare("INSERT INTO haccp_dokumenty (kategorie, nazev, poradi, obsah, aktivni) VALUES (:k, :n, :p, :o, 1)");

    $updated = 0;
    $created = 0;
    $skipped = 0;
    $matched = []; // klíče (kat+nazev), které jsou už v DB

    foreach ($existujici as $e) {
        $key = $e['kategorie'] . '||' . mb_strtolower(trim($e['nazev']));
        $matched[$key] = $e['id'];
    }

    foreach ($defaults as $d) {
        $key = $d['kategorie'] . '||' . mb_strtolower(trim($d['nazev']));
        if (isset($matched[$key])) {
            // Existuje — přepsat obsah
            $upd->execute([
                'id' => $matched[$key],
                'o' => $d['obsah'],
            ]);
            $updated++;
        } else {
            // Neexistuje — vytvořit
            $ins->execute([
                'k' => $d['kategorie'],
                'n' => $d['nazev'],
                'p' => $d['poradi'],
                'o' => $d['obsah'],
            ]);
            $created++;
        }
    }

    json_response([
        'ok' => true,
        'updated' => $updated,
        'created' => $created,
    ]);
}

// =============================================================
// IMPORT VÝCHOZÍ SADY
// =============================================================
if ($method === 'POST' && $action === 'import_default') {
    $existing = (int) $pdo->query("SELECT COUNT(*) FROM haccp_dokumenty")->fetchColumn();
    $force = !empty($_GET['force']);
    if ($existing > 0 && !$force) {
        json_response([
            'ok' => false,
            'message' => "Už existuje $existing dokumentů. Pro přepsání pošli ?force=1.",
            'existing' => $existing,
        ]);
    }
    // Pokud je vyplněna firma v nastavení, automaticky personalizovat obsah dokumentů
    $firma = nacti_firma_pro_haccp($pdo);
    $created = 0;
    $stmt = $pdo->prepare("INSERT INTO haccp_dokumenty (kategorie, nazev, poradi, obsah, aktivni) VALUES (:k, :n, :p, :o, 1)");
    foreach (default_dokumenty() as $d) {
        $obsah = personalize_haccp_obsah($d['obsah'], $firma);
        $stmt->execute([
            'k' => $d['kategorie'],
            'n' => $d['nazev'],
            'p' => $d['poradi'],
            'o' => $obsah,
        ]);
        $created++;
    }
    json_response([
        'ok' => true,
        'created' => $created,
        'personalizovano' => !empty($firma['firma_nazev']),
        'firma' => $firma['firma_nazev'] ?: null,
    ]);
}

// =============================================================
// GET — seznam nebo detail
// =============================================================
if ($method === 'GET') {
    $id = (int) ($_GET['id'] ?? 0);
    if ($id) {
        $stmt = $pdo->prepare("SELECT * FROM haccp_dokumenty WHERE id = ?");
        $stmt->execute([$id]);
        $r = $stmt->fetch();
        if (!$r) json_error('Nenalezeno', 404);

        // Pokud je to dokument "Vnitřní audit", vykresli aktuální tabulku audit-záznamů z DB
        if (stripos($r['nazev'] ?? '', 'audit') !== false && stripos($r['nazev'] ?? '', 'verifikace') !== false) {
            $r['obsah'] = render_audity_v_dokumentu($pdo, $r['obsah']);
        }
        json_response($r);
    }

    $kategorie = $_GET['kategorie'] ?? '';
    $sql = "SELECT id, kategorie, nazev, poradi, aktivni, vytvoreno, upraveno FROM haccp_dokumenty";
    $params = [];
    if ($kategorie) {
        $sql .= " WHERE kategorie = ?";
        $params[] = $kategorie;
    }
    $sql .= " ORDER BY kategorie, poradi, nazev";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll();

    json_response([
        'kategorie' => kategorie_list(),
        'dokumenty' => $rows,
    ]);
}

// =============================================================
// POST — vytvořit
// =============================================================
if ($method === 'POST') {
    $d = json_input();
    $kat = trim((string) ($d['kategorie'] ?? ''));
    $nazev = trim((string) ($d['nazev'] ?? ''));
    if ($kat === '' || $nazev === '') json_error('Chybí kategorie nebo název');

    $stmt = $pdo->prepare("INSERT INTO haccp_dokumenty (kategorie, nazev, poradi, obsah, aktivni) VALUES (:k, :n, :p, :o, :a)");
    $stmt->execute([
        'k' => $kat,
        'n' => $nazev,
        'p' => (int) ($d['poradi'] ?? 99),
        'o' => (string) ($d['obsah'] ?? ''),
        'a' => !empty($d['aktivni']) ? 1 : 0,
    ]);
    json_response(['ok' => true, 'id' => (int) $pdo->lastInsertId()]);
}

// =============================================================
// PUT — upravit
// =============================================================
if ($method === 'PUT') {
    $id = (int) ($_GET['id'] ?? 0);
    if (!$id) json_error('Chybí id');
    $d = json_input();
    $stmt = $pdo->prepare("
        UPDATE haccp_dokumenty
        SET kategorie = :k, nazev = :n, poradi = :p, obsah = :o, aktivni = :a
        WHERE id = :id
    ");
    $stmt->execute([
        'id' => $id,
        'k' => trim((string) ($d['kategorie'] ?? '')),
        'n' => trim((string) ($d['nazev'] ?? '')),
        'p' => (int) ($d['poradi'] ?? 99),
        'o' => (string) ($d['obsah'] ?? ''),
        'a' => !empty($d['aktivni']) ? 1 : 0,
    ]);
    json_response(['ok' => true]);
}

// =============================================================
// DELETE
// =============================================================
if ($method === 'DELETE') {
    $id = (int) ($_GET['id'] ?? 0);
    if (!$id) json_error('Chybí id');
    $pdo->prepare("DELETE FROM haccp_dokumenty WHERE id = ?")->execute([$id]);
    json_response(['ok' => true]);
}

json_error('Neznámá metoda', 405);
